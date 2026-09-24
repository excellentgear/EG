<?php
/**
 * qa_abnormal_lib.php — 品質異常處理單（2-QA-01-01）唯一實作
 * 建立：2026-09-18
 *
 * 【這一版在做什麼】
 * 舊版異常單是「開單跳窗 + 通知回覆」，現場反映不好用：
 *   ① 沒有一張跟紙本 2-QA-01-01 對得起來的表（列印出來不是那張表）
 *   ② 回覆部門要在開單當下一次勾完，實際上是「先問甲、看甲怎麼回、再決定問乙還是直接決策」
 *   ③ 異常原因分類寫死在 enum（人/機器/材料/方法/工具/環/其他），改一個字要改程式，
 *      也沒辦法做「方法 → 程式」這種下一層，後面的異常分析根本分不出來
 *   ④ 紙本左下角整塊「扣款確認」系統裡完全沒有
 * 這一版把①~④做完，並且**所有選項一律存 id 不存文字**（鐵律4：改名不會斷掉連動）。
 *
 * 【三張代碼表都可由管理員維護】
 *   qa_cause_cat   異常原因分類，自關聯最多三層（人 → 方法 → 程式）。刻意沒有「其他」，
 *                  因為這一欄要延伸到異常分析與報告，一個「其他」會把分析吃掉一大塊。
 *   qa_option      異常處置方式(kind=disp) 與 總經理裁示(kind=gm) 共用一張；
 *                  「是不是報廢」「是不是轉總經理」用 is_scrap / is_escalate 旗標判定，
 *                  **不可以比對名稱文字**——管理員把「報廢」改成「報廢(不可用)」就會全部失效。
 *   qa_decider_cfg 決策者的可選範圍（部門＋職稱），kind=decider 是主管決策者
 *                  （業務主管／品管主管），kind=top 是最高決策者（總經理）。
 *
 * 【最終處置怎麼認定】（2026-09-18 使用者定調）
 *   總經理裁示優先於主管的處置方式；有總經理裁示就以總經理的為最終決策。
 *   最終決策含「報廢」者，**結案當下**才配發報廢單號（F+民國年3碼+MMDD+流水3碼）並寫入 DB，
 *   因為後續還有別的單據要靠這個號碼追蹤，不能只是畫面上算出來的字串。
 *
 * 關聯資料表：qa_abnormal_order（主表，本檔案再擴充欄位）／qa_abnormal_cause／qa_abnormal_opt／
 *             qa_abnormal_resp／qa_abnormal_measure／qa_abnormal_deduct／qa_abnormal_order_flow（擴充）／
 *             qa_cause_cat／qa_option／qa_decider_cfg／qa_scrap_seq
 */
if (!function_exists('qab_ensure_schema')) {

define('QAB_PARAM_GROUP', 'QA_ABN');
define('QAB_ASDOC_MODULE', 'qa_abnormal');   // AS 綁定（2-QA-01-01），版次依業務日期回推

/* ─────────────────────────────────────────────────────────────
   Schema
   ───────────────────────────────────────────────────────────── */
function qab_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $db->exec("CREATE TABLE IF NOT EXISTS qa_cause_cat (
        cat_id INT AUTO_INCREMENT PRIMARY KEY,
        parent_id INT NULL COMMENT '上層分類；NULL=第一層',
        lv TINYINT NOT NULL DEFAULT 1 COMMENT '1/2/3，由 parent 推導後存起來方便查',
        name VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        KEY idx_parent (parent_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常原因分類（最多三層，管理員可維護）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_option (
        opt_id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(10) NOT NULL COMMENT 'disp=異常處置方式 / gm=總經理裁示',
        name VARCHAR(40) NOT NULL,
        is_scrap TINYINT(1) NOT NULL DEFAULT 0 COMMENT '此選項代表報廢（報廢單號依旗標判定，不比對文字）',
        is_escalate TINYINT(1) NOT NULL DEFAULT 0 COMMENT '此選項代表轉總經理裁示',
        need_capa TINYINT(1) NOT NULL DEFAULT 0 COMMENT '此選項代表需開矯正單',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_kind (kind, is_active)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常處置方式／總經理裁示 選項（管理員可維護）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_decider_cfg (
        cfg_id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(10) NOT NULL COMMENT 'decider=主管決策者 / top=最高決策者',
        label VARCHAR(40) NULL COMMENT '顯示名稱（例：業務主管）；留空用「部門 職稱」',
        dept_id INT NOT NULL,
        position_id INT NULL COMMENT 'NULL=該部門不限職稱',
        include_sub TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=含下轄部門',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_kind (kind, is_active)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常單決策者可選範圍（管理員設定部門＋職稱）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_abnormal_cause (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        cat_id INT NOT NULL COMMENT '選到的那一層（最深的節點），上層由 qa_cause_cat 推導',
        UNIQUE KEY uk_oc (order_id, cat_id),
        KEY idx_o (order_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常單↔異常原因分類（可複選，存 id）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_abnormal_opt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        kind VARCHAR(10) NOT NULL COMMENT 'disp / gm',
        opt_id INT NOT NULL,
        UNIQUE KEY uk_ook (order_id, kind, opt_id),
        KEY idx_o (order_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常單↔處置方式／總經理裁示（存 id）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_abnormal_resp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        dept_id INT NOT NULL,
        user_id INT NULL COMMENT 'NULL=只指到部門',
        UNIQUE KEY uk_odu (order_id, dept_id, user_id),
        KEY idx_o (order_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='責任單位為廠內時的部門／人員（可複選、非必填）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_abnormal_measure (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        seq TINYINT NOT NULL DEFAULT 1 COMMENT '第幾列（紙本三列）',
        dim_name VARCHAR(60) NULL COMMENT '量測尺寸',
        vals TEXT NULL COMMENT 'JSON 陣列，最多 12 個實測值',
        UNIQUE KEY uk_os (order_id, seq)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常單 量測尺寸與實測值（比照紙本 3 列 × 12 值）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_abnormal_deduct (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'process' COMMENT 'process=製程（自動帶入）/ other=其他（生管或業務自行填）',
        transfer_id INT NULL COMMENT '來源 bom_ing_transfer_log.transfer_id',
        bom_no VARCHAR(30) NULL COMMENT '這一列來自哪一張製令（一張單可綁多張）',
        bom_sn INT NULL,
        process_name VARCHAR(60) NULL,
        vendor_name VARCHAR(60) NULL,
        transfer_no VARCHAR(30) NULL,
        qty DECIMAL(14,3) NULL,
        amount_auto DECIMAL(14,2) NULL COMMENT '自動帶入的金額（保留原值，手動改價後仍看得到原本帶入多少）',
        amount DECIMAL(14,2) NULL COMMENT '實際採用金額（可手動修改）',
        note VARCHAR(255) NULL,
        included TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=不計入合計（仍列出，方便看到為什麼不算）',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        KEY idx_o (order_id, kind)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常單 扣款確認明細'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_ask_dept_cfg (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dept_id INT NOT NULL,
        position_id INT NOT NULL,
        paper_slot VARCHAR(12) NULL COMMENT '對應紙本「相關單位意見」的哪一格（qab_ask_slots()）；沒對到就不會印在紙本上',
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY uk_dp (dept_id, position_id),
        KEY idx_d (dept_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='相關單位意見：各部門的預設回覆職稱（可多選，勾部門時自動帶入）'");
    $acols = $db->query("SHOW COLUMNS FROM qa_ask_dept_cfg")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('paper_slot', $acols, true)) {
        $db->exec("ALTER TABLE qa_ask_dept_cfg ADD COLUMN paper_slot VARCHAR(12) NULL COMMENT '對應紙本相關單位意見的哪一格' AFTER position_id");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS qa_abnormal_bom (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        bom_no VARCHAR(30) NOT NULL,
        is_main TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=主製令（客戶、料號、責任製程以它為準）',
        part_no VARCHAR(60) NULL COMMENT '建立當下的料號，只供顯示',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ob (order_id, bom_no),
        KEY idx_o (order_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常單↔製令（退貨的是組合件時會有好幾張，扣款金額一起加總）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_abnormal_del_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        abnormal_order_no VARCHAR(20) NULL,
        act VARCHAR(10) NOT NULL DEFAULT 'delete' COMMENT 'delete=刪除 / restore=還原',
        reason VARCHAR(255) NULL,
        snapshot TEXT NULL COMMENT '刪除當下的主要欄位，單被還原或事後查核時對得起來',
        acted_by INT NULL,
        acted_name VARCHAR(40) NULL,
        acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_o (order_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常單刪除／還原紀錄（軟刪除，資料留著可查）'");

    $db->exec("CREATE TABLE IF NOT EXISTS qa_scrap_seq (
        seq_date DATE NOT NULL PRIMARY KEY,
        last_no INT NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4 COMMENT='報廢單號流水（F+民國年3碼+MMDD+3碼）'");

    // ── 主表擴充欄位 ────────────────────────────────────────
    $cols = $db->query("SHOW COLUMNS FROM qa_abnormal_order")->fetchAll(PDO::FETCH_COLUMN);
    $add = [];
    $need = [
        'fill_date'        => "ADD COLUMN fill_date DATE NULL COMMENT '填寫日期（紙本表頭；業務日期，版次依它回推）'",
        'ir_no'            => "ADD COLUMN ir_no VARCHAR(30) NULL COMMENT '客退單號 ir_track.IR_no'",
        'ir_id'            => "ADD COLUMN ir_id INT NULL COMMENT 'ir_track.IR_id'",
        'client_name'      => "ADD COLUMN client_name VARCHAR(60) NULL COMMENT '客戶（開單時由來源帶入，可改）'",
        'part_no'          => "ADD COLUMN part_no VARCHAR(60) NULL COMMENT '料號'",
        'batch_qty'        => "ADD COLUMN batch_qty INT NULL COMMENT '批量'",
        'insp_qty'         => "ADD COLUMN insp_qty INT NULL COMMENT '檢驗數'",
        'ng_qty'           => "ADD COLUMN ng_qty INT NULL COMMENT '不良數'",
        'resp_process_no'  => "ADD COLUMN resp_process_no INT NULL COMMENT '責任單位－製程 process_no.ProcessNo'",
        'resp_is_internal' => "ADD COLUMN resp_is_internal TINYINT(1) NOT NULL DEFAULT 0 COMMENT '責任廠商是否為廠內加工廠商(maker_list.internal=1)'",
        'decider_cfg_id'   => "ADD COLUMN decider_cfg_id INT NULL COMMENT '填表人選定的決策者設定列 qa_decider_cfg.cfg_id'",
        'decider_user_id'  => "ADD COLUMN decider_user_id INT NULL COMMENT '指定的決策者本人（可留空＝該範圍任一人皆可）'",
        'disp_decided_by'  => "ADD COLUMN disp_decided_by INT NULL COMMENT '實際做出處置判定的人'",
        'disp_decided_at'  => "ADD COLUMN disp_decided_at DATETIME NULL",
        'gm_deduct'        => "ADD COLUMN gm_deduct TINYINT(1) NOT NULL DEFAULT 0 COMMENT '總經理裁示：是否扣款（勾了就可填左下角扣款確認，與報廢與否無關）'",
        'surcharge_rate'   => "ADD COLUMN surcharge_rate DECIMAL(6,3) NULL COMMENT '製程金額加成，1.1＝×110%',",
        'deduct_qty'       => "ADD COLUMN deduct_qty DECIMAL(14,3) NULL COMMENT '扣款數量 PCS'",
        'deduct_unit_amt'  => "ADD COLUMN deduct_unit_amt DECIMAL(14,2) NULL COMMENT '核准扣款金額 元/PCS'",
        'deduct_exec'      => "ADD COLUMN deduct_exec VARCHAR(60) NULL COMMENT '執行'",
        'deduct_notify_no' => "ADD COLUMN deduct_notify_no VARCHAR(120) NULL COMMENT '通知扣款（移轉單號，可加註備註）'",
        'deduct_pm_by'     => "ADD COLUMN deduct_pm_by INT NULL COMMENT '(生管)簽章',",
        'deduct_pm_at'     => "ADD COLUMN deduct_pm_at DATETIME NULL",
        'deduct_qc_by'     => "ADD COLUMN deduct_qc_by INT NULL COMMENT '(品管)簽章'",
        'deduct_qc_at'     => "ADD COLUMN deduct_qc_at DATETIME NULL",
        'deduct_appr_by'   => "ADD COLUMN deduct_appr_by INT NULL COMMENT '核准（管理課 會計/主管）'",
        'deduct_appr_at'   => "ADD COLUMN deduct_appr_at DATETIME NULL",
        'scrap_no'         => "ADD COLUMN scrap_no VARCHAR(20) NULL COMMENT '報廢單號（結案當下配發，F+民國年3碼+MMDD+3碼）'",
        'scrap_no_at'      => "ADD COLUMN scrap_no_at DATETIME NULL",
        'owner_sign_by'    => "ADD COLUMN owner_sign_by INT NULL COMMENT '(業務/品管)承辦',",
        'owner_sign_at'    => "ADD COLUMN owner_sign_at DATETIME NULL",
        'closed_by'        => "ADD COLUMN closed_by INT NULL",
        'client_id'        => "ADD COLUMN client_id CHAR(11) NULL COMMENT '客戶主檔 customer_list.customer_id；綁了製令或客退單就由來源自動帶，不給手打'",
        'gm_by_deputy'     => "ADD COLUMN gm_by_deputy TINYINT(1) NOT NULL DEFAULT 0 COMMENT '總經理裁示是由代理人簽的（列印時圖章右下角加「代」字）'",
        'part_d_id'        => "ADD COLUMN part_d_id INT NULL COMMENT '料號主檔 d_setting.d_id；綁了製令或客退單就由來源自動帶（同一個料號文字常分屬多家客戶，只存文字會歪）'",
        'deleted_at'       => "ADD COLUMN deleted_at DATETIME NULL COMMENT '軟刪除：清單不再出現，資料留著可查可還原（刪除紀錄見 qa_abnormal_del_log）'",
        'deleted_by'       => "ADD COLUMN deleted_by INT NULL",
        'resp_vendor_manual'  => "ADD COLUMN resp_vendor_manual TINYINT(1) NOT NULL DEFAULT 0 COMMENT '責任廠商是人工改的（不是由製令製程自動帶），畫面與列印要標示'",
        'resp_process_manual' => "ADD COLUMN resp_process_manual TINYINT(1) NOT NULL DEFAULT 0 COMMENT '責任製程是人工改的（不是從製令製程挑的）'",
        'auto_opened'      => "ADD COLUMN auto_opened TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=系統自動開立（報工NG觸發），非人工手動建立'",
        'auto_open_note'   => "ADD COLUMN auto_open_note VARCHAR(255) NULL COMMENT '自動開立的現場主管解析軌跡（誰不在職、最後選到誰），供追溯'",
        'pm_report_id'     => "ADD COLUMN pm_report_id INT NULL COMMENT '來源報工紀錄 pm_process_daily_report.report_id（報廢扣減下游數量要靠它回推是哪一站發現的）'",
    ];
    foreach ($need as $c => $sql) if (!in_array($c, $cols, true)) $add[] = rtrim($sql, ',');
    if ($add) $db->exec("ALTER TABLE qa_abnormal_order " . implode(', ', $add));
    // 開單來源多一種：BOM＝製程中直接綁製令開立（不是從某一張檢驗單來的）。
    // 原本只有 IR/QC，不放寬的話「製程中開立」只能假冒成 QC，之後分不出是誰開的。
    try {
        $t = $db->query("SHOW COLUMNS FROM qa_abnormal_order LIKE 'source_type'")->fetch(PDO::FETCH_ASSOC);
        if ($t && stripos((string)$t['Type'], "'BOM'") === false) {
            $db->exec("ALTER TABLE qa_abnormal_order MODIFY COLUMN source_type ENUM('IR','QC','BOM') NOT NULL
                       COMMENT 'IR=客退來源 QC=檢驗單來源 BOM=製程中直接開立'");
        }
    } catch (Throwable $e) {}
    if (!in_array('scrap_no', $cols, true)) {
        try { $db->exec("ALTER TABLE qa_abnormal_order ADD UNIQUE KEY uk_scrap_no (scrap_no)"); } catch (Throwable $e) {}
    }

    // 扣款明細要記得「這一列是哪一張製令來的」（一張單可綁多張製令）
    $dcols = $db->query("SHOW COLUMNS FROM qa_abnormal_deduct")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bom_no', $dcols, true)) {
        $db->exec("ALTER TABLE qa_abnormal_deduct ADD COLUMN bom_no VARCHAR(30) NULL COMMENT '這一列來自哪一張製令' AFTER transfer_id");
    }

    // ── 相關單位意見（逐輪徵詢）：沿用既有 flow 表，補上輪次與指定職稱 ──
    $fcols = $db->query("SHOW COLUMNS FROM qa_abnormal_order_flow")->fetchAll(PDO::FETCH_COLUMN);
    $fadd = [];
    $fneed = [
        'round_no'    => "ADD COLUMN round_no INT NOT NULL DEFAULT 1 COMMENT '第幾輪徵詢（一次送一個，收到回覆才決定下一個）'",
        'position_id' => "ADD COLUMN position_id INT NULL COMMENT '指定職稱（未指定特定人員時用）'",
        'asked_by'    => "ADD COLUMN asked_by INT NULL COMMENT '誰送出這一輪'",
        'asked_at'    => "ADD COLUMN asked_at DATETIME NULL",
        'replied_by'  => "ADD COLUMN replied_by INT NULL COMMENT '實際回覆的人'",
        'event_id'    => "ADD COLUMN event_id INT NULL COMMENT '這一輪的通知 live_event.id'",
        'position_ids'=> "ADD COLUMN position_ids VARCHAR(120) NULL COMMENT '指定的職稱可以有好幾個（管理員為該部門設的預設回覆職稱），逗號分隔；多人只要有一人回覆即可'",
    ];
    foreach ($fneed as $c => $sql) if (!in_array($c, $fcols, true)) $fadd[] = $sql;
    if ($fadd) $db->exec("ALTER TABLE qa_abnormal_order_flow " . implode(', ', $fadd));

    // 報工NG自動開單：一筆報工最多對到一張異常單，靠這欄防重複建立
    try {
        $pcols = $db->query("SHOW COLUMNS FROM pm_process_daily_report")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('abnormal_order_id', $pcols, true)) {
            $db->exec("ALTER TABLE pm_process_daily_report ADD COLUMN abnormal_order_id INT NULL COMMENT '報工NG自動/補開的品質異常單 qa_abnormal_order.id；NULL=尚未開單'");
        }
    } catch (Throwable $e) {}

    qab_seed_defaults($db);
}

/** 第一次建置時塞入預設代碼（只在表是空的時候做，之後管理員怎麼改都不會被蓋回去） */
function qab_seed_defaults(PDO $db): void
{
    if ((int)$db->query("SELECT COUNT(*) FROM qa_cause_cat")->fetchColumn() === 0) {
        // 第一層沿用現場既有的 4M1E 講法，刻意不放「其他」（見檔頭說明）
        $ins = $db->prepare("INSERT INTO qa_cause_cat (parent_id, lv, name, sort_order) VALUES (NULL,1,?,?)");
        foreach (['人', '機器', '材料', '方法', '工具', '環境'] as $i => $n) $ins->execute([$n, ($i + 1) * 10]);
    }
    if ((int)$db->query("SELECT COUNT(*) FROM qa_option")->fetchColumn() === 0) {
        $ins = $db->prepare("INSERT INTO qa_option (kind,name,is_scrap,is_escalate,need_capa,sort_order) VALUES (?,?,?,?,?,?)");
        // 紙本「異常處置方式」四個勾選框
        $ins->execute(['disp', '特採', 0, 0, 0, 10]);
        $ins->execute(['disp', '報廢', 1, 0, 0, 20]);
        $ins->execute(['disp', '重工', 0, 0, 0, 30]);
        $ins->execute(['disp', '轉總經理裁示', 0, 1, 0, 40]);
        // 紙本「總經理裁示」四個勾選框
        $ins->execute(['gm', '特採', 0, 0, 0, 10]);
        $ins->execute(['gm', '報廢', 1, 0, 0, 20]);
        $ins->execute(['gm', '重工', 0, 0, 0, 30]);
        $ins->execute(['gm', '需矯正', 0, 0, 1, 40]);
    }
}

/* ─────────────────────────────────────────────────────────────
   設定值（system_parameters）
   ───────────────────────────────────────────────────────────── */
function qab_setting_get(PDO $db, string $key, $default = null)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([QAB_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}

function qab_setting_set(PDO $db, string $key, $val): void
{
    $st = $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value)
                        VALUES (?,?,?) ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)");
    $st->execute([QAB_PARAM_GROUP, $key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
}

/** 製程金額加成的預設值（管理員設定；1＝不加成） */
function qab_default_rate(PDO $db): float
{
    $v = (float)qab_setting_get($db, 'surcharge_rate', 1);
    return $v > 0 ? $v : 1.0;
}

/** 幾天以前的單算「補資料」（使用者定調：今日往前 10 天以前；做成可設定，預設 10） */
function qab_backfill_days(PDO $db): int
{
    $v = (int)qab_setting_get($db, 'backfill_days', 10);
    return ($v >= 0 && $v <= 3650) ? $v : 10;
}

/**
 * 這張單是不是「補資料」。
 * 判定用**表單自己的業務日期（填寫日期）**，不是建檔時間——補登的人本來就是今天才建檔。
 */
function qab_is_backfill(PDO $db, array $o): bool
{
    $biz = substr(trim((string)($o['fill_date'] ?: $o['occurrence_date'] ?: ($o['created_at'] ?? ''))), 0, 10);
    if ($biz === '') return false;
    $cut = date('Y-m-d', strtotime('-' . qab_backfill_days($db) . ' day'));   // 這一天（含）之後算「當期」
    return $biz < $cut;
}

/**
 * 來源（製令／客退單）帶出來的內容＝客戶＋料號，唯一實作。
 * 使用者要求：綁定製令或退貨單就要自動綁客戶，料號也要綁定後自動鎖定。
 * @return array ['client'=>['id','name','src'], 'part_no'=>?string, 'part_d_id'=>?int, 'batch'=>?int, 'src'=>'ir'|'bom'|'']
 */
function qab_resolve_source(PDO $db, ?string $bomNo, ?int $irId): array
{
    $out = ['client' => ['id' => null, 'name' => null, 'src' => ''], 'part_no' => null, 'part_d_id' => null,
            'batch' => null, 'src' => ''];
    if ($irId) {
        $st = $db->prepare("SELECT i.Client_name, i.d_id, i.d_setting_id, i.Qty FROM ir_track i WHERE i.IR_id=?");
        $st->execute([$irId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['src'] = 'ir';
            $out['part_no']   = trim((string)$r['d_id']) !== '' ? mb_substr((string)$r['d_id'], 0, 60) : null;
            $out['part_d_id'] = $r['d_setting_id'] ? (int)$r['d_setting_id'] : null;
            $out['batch']     = $r['Qty'] !== null ? (int)$r['Qty'] : null;
        }
    }
    // 客退單查不到料號時退回製令（手建的客退單常常只有單號與數量）
    if ($out['part_no'] === null && $bomNo !== null && trim($bomNo) !== '') {
        $st = $db->prepare("SELECT b.d_id, b.d_setting_id, b.sqty FROM bom b WHERE b.bom=? LIMIT 1");
        $st->execute([trim($bomNo)]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['src'] = 'bom';
            $out['part_no']   = trim((string)$r['d_id']) !== '' ? mb_substr((string)$r['d_id'], 0, 60) : null;
            $out['part_d_id'] = $r['d_setting_id'] ? (int)$r['d_setting_id'] : null;
            $out['batch']     = $r['sqty'] !== null ? (int)$r['sqty'] : null;
        }
    }
    $out['client'] = qab_resolve_client($db, $bomNo, $irId);
    return $out;
}

/**
 * 客戶一律由來源決定（使用者要求：綁定製令或退貨單都應該自動綁定客戶）。
 * 判定順序與 bom_client_lib 同一條：**先看料號主檔綁定的客戶**（同一個料號文字在 d_setting
 * 常有好幾筆、分屬不同客戶，用文字去猜一定會撿錯家），主檔查不到才退回來源單上的客戶文字。
 * @return array ['id'=>?string, 'name'=>?string, 'src'=>'ir'|'bom'|'']
 */
function qab_resolve_client(PDO $db, ?string $bomNo, ?int $irId): array
{
    $none = ['id' => null, 'name' => null, 'src' => ''];
    /* 來源單只留客戶文字時的歸戶：走會計模組的 acc_customer_by_name()（**含別名**）。
       ERP 寫「義高工業」「高鋒工業」而主檔是「義高」「高鋒」，只比對完全相同的字串會有一成多對不到，
       而別名對照表是全站唯一一份，這裡不要再刻第二套比對規則。 */
    $byText = function (?string $nm) use ($db) {
        $nm = trim((string)$nm);
        if ($nm === '') return [null, null];
        try {
            require_once __DIR__ . '/acc_lib.php';
            $map = acc_customer_by_name($db);
            if (isset($map[$nm])) return [(string)$map[$nm]['customer_id'], (string)$map[$nm]['customer']];
        } catch (Throwable $e) { /* 會計模組不在時退回下面的字串比對 */ }
        $st = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE customer=? ORDER BY is_inactive, customer_id LIMIT 1");
        $st->execute([$nm]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? [(string)$r['customer_id'], (string)$r['customer']] : [null, $nm];
    };

    if ($irId) {
        $st = $db->prepare("SELECT i.Client_name, cl.customer_id, cl.customer
                            FROM ir_track i
                            LEFT JOIN d_setting ds ON ds.d_id = i.d_setting_id
                            LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                            WHERE i.IR_id=?");
        $st->execute([$irId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if (trim((string)$r['customer']) !== '') return ['id' => (string)$r['customer_id'], 'name' => (string)$r['customer'], 'src' => 'ir'];
            [$id, $nm] = $byText($r['Client_name']);
            // 客退單自己查不到客戶時（沒綁料號主檔、Client_name 也是空的）**不要就此回報「沒有客戶」**，
            // 底下還有一段製令可以問——同一張單常常兩個都綁，只認客退單會讓畫面整欄空白又看不出原因。
            if (trim((string)$nm) !== '') return ['id' => $id, 'name' => $nm, 'src' => 'ir'];
        }
    }
    if ($bomNo !== null && trim($bomNo) !== '') {
        $st = $db->prepare("SELECT b.Client_Name, cl.customer_id, cl.customer
                            FROM bom b
                            LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
                            LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                            WHERE b.bom=? LIMIT 1");
        $st->execute([trim($bomNo)]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if (trim((string)$r['customer']) !== '') return ['id' => (string)$r['customer_id'], 'name' => (string)$r['customer'], 'src' => 'bom'];
            // 製令沒綁料號主檔時走 bom_client_lib 的完整判定（訂單 → 料號文字唯一對應）
            require_once __DIR__ . '/bom_client_lib.php';
            $m = eg_bom_client_resolve($db, [trim($bomNo)]);
            $nm = $m[trim($bomNo)] ?? $r['Client_Name'];
            [$id, $nm2] = $byText($nm);
            return ['id' => $id, 'name' => $nm2, 'src' => 'bom'];
        }
    }
    return $none;
}

/**
 * 最終決策者（總經理裁示）＝**全站統一的組織角色綁定「最高核准人員」**（`org_role_lib` 的 top_approver，
 * 設定入口 views/admin/org_role_setting.php）。使用者定調：這一格不在本模組另外設定，
 * 改人只在那一頁改一次，全站表單一起跟著變——本模組刻意不留第二份設定，否則兩邊遲早對不起來。
 * 再過一次 delegate_lib 的代理解析（ai-rules/11）：本人請假時由代理人簽，並標記成代簽。
 *
 * @param array $ctx 傳 ['log'=>true] 才寫代理事件紀錄；純顯示用一律不要寫（每次開畫面都寫會洗版）
 * @return array ['id','name','base_id','base_name','is_delegated','bound'] bound=false 表示那一頁還沒設定
 */
function qab_gm_person(PDO $db, array $ctx = []): array
{
    require_once __DIR__ . '/org_role_lib.php';
    $out = ['id' => 0, 'name' => '', 'base_id' => 0, 'base_name' => '', 'is_delegated' => false, 'bound' => false];
    $u = eg_org_user($db, 'top_approver');
    if (!$u) return $out;
    $out['bound'] = true;
    $out['base_id'] = (int)$u['id'];
    $out['base_name'] = (string)($u['user_cname'] ?: $u['user_uname']);
    $out['id'] = $out['base_id'];
    $out['name'] = $out['base_name'];
    try {
        require_once __DIR__ . '/delegate_lib.php';
        $r = eg_resolve_signer($db, $out['base_id'], array_merge(['flow_key' => 'qa_abnormal_gm', 'log' => false], $ctx));
        $sid = (int)($r['signer_id'] ?? 0);
        if ($sid > 0 && $sid !== $out['base_id']) {
            $st = $db->prepare("SELECT user_cname, user_uname FROM `user` WHERE id=?");
            $st->execute([$sid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            $out['id'] = $sid;
            $out['name'] = $p ? (string)($p['user_cname'] ?: $p['user_uname']) : '';
            $out['is_delegated'] = !empty($r['is_delegated']);
        }
    } catch (Throwable $e) { /* 代理模組不在就用本人 */ }
    return $out;
}

/**
 * 簽章格登記表（唯一來源）：補登時要能逐格指定人員與日期，畫面、API、列印都讀這一份。
 * perm＝平常誰能簽；補資料模式下一律由「異常單管理員」代為補登。
 */
function qab_sign_slots(): array
{
    return [
        'owner' => ['label' => '(業務/品管) 承辦', 'by' => 'owner_sign_by',  'at' => 'owner_sign_at',  'perm' => 'canCreate',        'ord' => 1],
        'disp'  => ['label' => '(業務/品管) 主管', 'by' => 'disp_decided_by', 'at' => 'disp_decided_at', 'perm' => 'canDecide',       'ord' => 2],
        'gm'    => ['label' => '總經理 裁示',      'by' => 'gm_decided_by',   'at' => 'gm_decided_at',   'perm' => 'canGm',           'ord' => 3],
        'pm'    => ['label' => '(生管) 簽章',      'by' => 'deduct_pm_by',    'at' => 'deduct_pm_at',    'perm' => 'canDeductFill',   'ord' => 4],
        'qc'    => ['label' => '(品管) 簽章',      'by' => 'deduct_qc_by',    'at' => 'deduct_qc_at',    'perm' => 'canQcSign',       'ord' => 5],
        'appr'  => ['label' => '核准 (管理課 會計/主管)', 'by' => 'deduct_appr_by', 'at' => 'deduct_appr_at', 'perm' => 'canDeductApprove', 'ord' => 6],
    ];
}

/**
 * 補登簽章要用的時間戳：日期由補登者指定，時間則接在「同一天已經有的簽章之後」隨機錯開，
 * 不跨日（ai-rules/21 第3條：時間要串接、不可各自獨立亂數，也不可因偏移跨天）。
 */
function qab_backfill_time(PDO $db, array $order, string $date): string
{
    $base = strtotime($date . ' 09:00:00');
    foreach (qab_sign_slots() as $s) {
        $at = trim((string)($order[$s['at']] ?? ''));
        if ($at === '' || substr($at, 0, 10) !== $date) continue;
        $t = strtotime($at);
        if ($t > $base) $base = $t;
    }
    $ts = $base + random_int(5 * 60, 180 * 60);
    $end = strtotime($date . ' 23:59:00');
    return date('Y-m-d H:i:s', min($ts, $end));
}

/**
 * 這個人在「那一天」是不是在職（補登簽章的守門）。
 * 走 eg_people_list_asof()：帶 asof 時**當時在職、現在已離職的人也會在名單裡**，
 * 補歷史單據才挑得到當時的人（ai-rules/22 第5坑）；用現況清單會整批挑不到又不報錯。
 */
function qab_user_asof_ok(PDO $db, int $uid, string $date): bool
{
    if ($uid <= 0 || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) return false;
    require_once __DIR__ . '/people_lib.php';
    static $cache = [];
    if (!isset($cache[$date])) {
        $ids = [];
        foreach (eg_people_list_asof($db, [], $date) as $r) $ids[(int)$r['id']] = 1;
        $cache[$date] = $ids;
    }
    return isset($cache[$date][$uid]);
}

/**
 * 每個簽章格「該由哪個部門的人簽」＝全站組織角色綁定（org_role_lib，禁止在這裡寫死部門 id）。
 * 回傳的是 role_key 清單，空陣列＝不限部門（總經理裁示走最高核准人員，不是某個部門的人）。
 */
function qab_slot_dept_keys(string $slot): array
{
    switch ($slot) {
        case 'owner': return ['sales_dept', 'qc_dept'];        // (業務/品管) 承辦
        case 'disp':  return ['sales_dept', 'qc_dept'];        // (業務/品管) 主管
        case 'gm':    return [];                               // 總經理裁示
        case 'pm':    return ['pm_dept'];                      // (生管) 簽章
        case 'qc':    return ['qc_dept'];                      // (品管) 簽章
        case 'appr':  return ['acc_dept', 'hr_dept'];          // 核准（管理課 會計/主管）
    }
    return [];
}

/**
 * 補登簽章的候選人：**那一天在職**（ai-rules/22，當時在職現已離職的人也要挑得到）
 * ＋**屬於這一格該簽的部門**（使用者回報：(生管)簽章卻列出全公司的人）
 * ＋**那一天沒有請整天假、也沒有整天外出**（章不可能蓋在人不在的那一天）。
 *
 * @param bool $all true＝不做部門篩選（補舊單偶爾會有例外，畫面上要留一個「顯示全部」的退路）
 * @return array [['id','name','dept_id','dept_name','position_name'], ...] 一人一列
 */
function qab_sign_candidates(PDO $db, string $slot, string $date, bool $all = false, ?int $deptId = null): array
{
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) $date = date('Y-m-d');
    require_once __DIR__ . '/people_lib.php';

    $deptIds = [];
    if ($deptId > 0) {
        /* 指定部門（相關單位意見的補登：選了生管組，右邊就只能出現生管組的人）。
           含下轄，因為組織是樹狀的，挑到課別時底下的組也要算。 */
        require_once __DIR__ . '/org_role_lib.php';
        $deptIds[(int)$deptId] = 1;
        try {
            foreach (eg_dept_subtree_ids($db, (int)$deptId) as $d) $deptIds[(int)$d] = 1;
        } catch (Throwable $e) { /* 沒有這支共用庫時就只取本部門 */ }
    } elseif (!$all) {
        require_once __DIR__ . '/org_role_lib.php';
        foreach (qab_slot_dept_keys($slot) as $k) {
            foreach (eg_org_dept_ids($db, $k) as $d) $deptIds[(int)$d] = 1;   // 含下轄（品管部→品管組）
        }
    }

    $rows = eg_people_list_asof($db, ['all_posts' => true], $date);
    $pick = [];
    foreach ($rows as $r) {
        $uid = (int)$r['id'];
        if ($deptIds && !isset($deptIds[(int)$r['dept_id']])) continue;
        if (isset($pick[$uid])) continue;                       // 兼任者只留命中範圍的第一筆
        $pick[$uid] = ['id' => $uid, 'name' => (string)$r['user_cname'],
                       'dept_id' => (int)$r['dept_id'], 'dept_name' => (string)$r['dept_name'],
                       'position_name' => (string)$r['position_name']];
    }
    if (!$pick) return [];

    /* 那一天請整天假或整天外出的人不可以蓋章（使用者明確要求）。
       走全站共用的 person_schedule_lib，不在這裡自己查請假單與公出單。 */
    try {
        require_once __DIR__ . '/person_schedule_lib.php';
        $sch = eg_psched_for_users($db, array_keys($pick), $date);
        foreach ($sch as $uid => $items) {
            foreach ($items as $it) {
                if (empty($it['allday'])) continue;
                if (in_array((string)($it['source'] ?? ''), ['leave', 'trip'], true)) { unset($pick[(int)$uid]); break; }
            }
        }
    } catch (Throwable $e) { /* 行程模組不在時就只做部門與在職判定 */ }

    $out = array_values($pick);
    usort($out, function ($a, $b) {
        return [$a['dept_name'], $a['position_name'], $a['name']] <=> [$b['dept_name'], $b['position_name'], $b['name']];
    });
    return $out;
}

/**
 * 補登模式下，「這一格是誰簽的、蓋哪一天」要由補登者指定（使用者要求：補登簽章區直接補結果與內容）。
 * 回傳 [signer_id, 'Y-m-d H:i:s']；不是補登模式或沒指定就回 [0, '']，呼叫端照原本的「現在、我」處理。
 */
function qab_backfill_sign_args(PDO $db, array $order, array $perms, array $post): array
{
    if (empty($order['is_backfill']) || empty($perms['canBackfill'])) return [0, ''];
    $date = trim((string)($post['sign_date'] ?? ''));
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) return [0, ''];
    if ($date > date('Y-m-d')) throw new RuntimeException('印章日期不可以是未來');
    $by = (int)($post['sign_by'] ?? 0);
    if ($by <= 0) return [0, ''];
    if (!qab_user_asof_ok($db, $by, $date)) throw new RuntimeException('選擇的人員在該日期並不在職，請改選當時在職的人');
    return [$by, qab_backfill_time($db, $order, $date)];
}

/**
 * 列印圖章要用的模板（管理員在清單頁「設定 → 其他設定」選；沒選就用系統預設回墨印）。
 * 注意 ai-rules/18 第11條：有模板時前端一定要連 eg_stamp_tpl.js 一起載，只載 eg_stamp.js 會靜默退回預設章。
 */
function qab_stamp_tpl(PDO $db, string $use = ''): ?array
{
    /* $use='ask' ＝相關單位意見那五格（紙本格子矮，通常會另外指定長方章）；
       沒有另外指定時退回一般簽章的模板。 */
    $key = $use === 'ask' ? 'stamp_tpl_ask_id' : 'stamp_tpl_id';
    $id = (int)qab_setting_get($db, $key, 0);
    if ($id <= 0 && $use !== '') $id = (int)qab_setting_get($db, 'stamp_tpl_id', 0);
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id' => (int)$r['id'], 'tpl_name' => (string)$r['tpl_name'],
                'schema' => json_decode((string)$r['schema_json'], true)];
    } catch (Throwable $e) { return null; }
}

/**
 * 某個人在某一天的部門與職稱（圖章模板的 {部門}{職稱} token 要用「當時」的，ai-rules/22）。
 * @return array ['dept'=>string,'position'=>string]
 */
function qab_person_asof(PDO $db, int $uid, string $date): array
{
    if ($uid <= 0) return ['dept' => '', 'position' => ''];
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) $date = date('Y-m-d');
    require_once __DIR__ . '/people_lib.php';
    static $cache = [];
    if (!isset($cache[$date])) {
        $m = [];
        foreach (eg_people_list_asof($db, [], $date) as $r) {
            $m[(int)$r['id']] = ['dept' => (string)$r['dept_name'], 'position' => (string)$r['position_name']];
        }
        $cache[$date] = $m;
    }
    return $cache[$date][$uid] ?? ['dept' => '', 'position' => ''];
}

/**
 * 新增一張品質異常單——唯一寫入路徑（2026-09-24 從 QaAbnormal_API.php 的 case 'create' 抽出）。
 * 人工開單（`QaAbnormal_API.php`）與「報工NG自動開單／管理員批次補開」（`qab_auto_open_from_pm_ng()`）
 * 共用這一支，不要再各刻一份——`created_by` 一律由呼叫端明確傳入（不可在這裡讀 $_SESSION），
 * 因為自動開單時「開單人」是解析出來的現場主管，不是操作當下按存檔的那個人。
 *
 * $data 可用鍵：kind('ir'|'bom')、fill_date、ir_id、bom_no、client_name、part_no、batch_qty、
 *              insp_qty、ng_qty、abnormal_phenomenon、created_by（必填）、
 *              resp_process_no（責任製程，選填；自動開單會直接帶，人工開單留給之後在「責任單位」段填）、
 *              auto_opened、auto_open_note、pm_report_id（報工NG自動/補開才會有）。
 * 回傳 ['id'=>int, 'no'=>string]，失敗一律丟例外（呼叫端自行決定要不要 catch）。
 */
function qab_create_order(PDO $db, array $data): array
{
    $uid = (int)($data['created_by'] ?? 0);
    if ($uid <= 0) throw new Exception('qab_create_order 缺少 created_by');
    $kind = ($data['kind'] ?? '') === 'ir' ? 'ir' : 'bom';
    $fillDate = trim((string)($data['fill_date'] ?? '')) ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fillDate)) throw new Exception('填寫日期格式不正確');

    $strOrNull = function ($v, $max) {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    };
    $intOrNull = function ($v) {
        if ($v === null || $v === '') return null;
        return (int)$v;
    };

    $irId = null; $irNo = null;
    $bomNo = $strOrNull($data['bom_no'] ?? null, 30);
    $client = $strOrNull($data['client_name'] ?? null, 60);
    $partNo = $strOrNull($data['part_no'] ?? null, 60);
    $batch = $intOrNull($data['batch_qty'] ?? null);

    if ($kind === 'ir') {
        $irId = (int)($data['ir_id'] ?? 0);
        if ($irId <= 0) throw new Exception('客退來源請先選擇客退單（IR）');
        $st = $db->prepare("SELECT IR_id, IR_no, Client_name, d_id, Qty FROM ir_track WHERE IR_id=?");
        $st->execute([$irId]);
        $ir = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ir) throw new Exception('找不到這張客退單');
        $irNo = (string)$ir['IR_no'];
        if ($partNo === null) $partNo = $ir['d_id'] !== '' ? mb_substr((string)$ir['d_id'], 0, 60) : null;
        if ($batch === null)  $batch  = $ir['Qty'] !== null ? (int)$ir['Qty'] : null;
    } else {
        if ($bomNo === null) throw new Exception('製程來源請先選擇製令編號');
        $st = $db->prepare("SELECT bom, d_id, Client_Name, sqty FROM bom WHERE bom=?");
        $st->execute([$bomNo]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new Exception('找不到這張製令');
        if ($partNo === null) $partNo = $b['d_id'] !== '' ? mb_substr((string)$b['d_id'], 0, 60) : null;
        if ($batch === null)  $batch  = $b['sqty'] !== null ? (int)$b['sqty'] : null;
    }
    if ($kind === 'ir' && $bomNo !== null) {
        $chk = $db->prepare("SELECT 1 FROM bom WHERE bom=?");
        $chk->execute([$bomNo]);
        if (!$chk->fetchColumn()) throw new Exception('要綁定的製令編號不存在');
    }

    // 客戶與料號一律由來源綁定，不採信呼叫端傳來的文字（與人工開單同一規則）
    $srcInfo = qab_resolve_source($db, $bomNo, $irId);
    $cli = $srcInfo['client'];
    $partDid = null;
    if ($srcInfo['src'] !== '') {
        $client  = $cli['name'];
        $partNo  = $srcInfo['part_no'];
        $partDid = $srcInfo['part_d_id'];
        if ($batch === null) $batch = $srcInfo['batch'];
    }

    $ngQty = $intOrNull($data['ng_qty'] ?? null);
    $inspQty = $intOrNull($data['insp_qty'] ?? null);
    if ($inspQty === null && $batch && function_exists('qc_suggest_sample_qty')) {
        $inspQty = qc_suggest_sample_qty($db, (int)$batch);
    }
    $respProcessNo = $intOrNull($data['resp_process_no'] ?? null);
    $autoOpened = !empty($data['auto_opened']) ? 1 : 0;
    $autoNote = $strOrNull($data['auto_open_note'] ?? null, 255);
    $pmReportId = $intOrNull($data['pm_report_id'] ?? null);
    $phenomenon = $strOrNull($data['abnormal_phenomenon'] ?? null, 2000);

    $db->beginTransaction();
    try {
        $no = qab_next_order_no($db, $fillDate);
        $db->prepare("INSERT INTO qa_abnormal_order
            (abnormal_order_no, source_type, source_id, occurrence_date, fill_date, found_unit,
             ir_id, ir_no, bom_no, client_id, client_name, part_no, part_d_id, batch_qty, insp_qty, ng_qty,
             abnormal_phenomenon, created_by, created_at, surcharge_rate,
             resp_process_no, auto_opened, auto_open_note, pm_report_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?)")
           ->execute([$no, ($kind === 'ir' ? 'IR' : 'BOM'), (int)($irId ?: 0), $fillDate, $fillDate,
                      ($kind === 'ir' ? '客退' : '廠內'), $irId, $irNo, $bomNo, $cli['id'], $client, $partNo, $partDid, $batch,
                      $inspQty, $ngQty, $phenomenon, $uid, qab_default_rate($db),
                      $respProcessNo, $autoOpened, $autoNote, $pmReportId]);
        $id = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    qab_sync_ir_flag($db, $irId);
    return ['id' => $id, 'no' => $no];
}

/**
 * 客退單上的「已開立異常單」旗標（`ir_track.has_ncr`）。
 * 退貨單追蹤頁是靠它決定要顯示「開立」還是單號，本模組開單後沒同步就會一直顯示「開立」（使用者回報）。
 * **清成 0 之前要確認舊模組（qa_ir_ncr）也沒有紀錄**——那張表也會把同一個旗標設成 1，
 * 直接歸零會把舊資料的狀態一起洗掉。
 */
function qab_sync_ir_flag(PDO $db, ?int $irId): void
{
    $irId = (int)$irId;
    if ($irId <= 0) return;
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM qa_abnormal_order
                            WHERE deleted_at IS NULL AND (ir_id=? OR (source_type='IR' AND source_id=?))");
        $st->execute([$irId, $irId]);
        $n = (int)$st->fetchColumn();
        if ($n === 0) {
            $st = $db->prepare("SELECT COUNT(*) FROM qa_ir_ncr WHERE IR_id=?");
            $st->execute([$irId]);
            $n = (int)$st->fetchColumn();
        }
        $db->prepare("UPDATE ir_track SET has_ncr=? WHERE IR_id=?")->execute([$n > 0 ? 1 : 0, $irId]);
    } catch (Throwable $e) { /* 舊模組的表不在時就只看本模組 */ }
}

/**
 * 由一筆「報工紀錄」自動（或管理員補開）建立一張品質異常單——2026-09-24 使用者交辦。
 * 唯一呼叫時機：①`process_schedule.php`/`process_schedule_NOW.php` 存檔報工當下 NG>0 時自動觸發，
 * ②`process_report_query.php` 管理員對舊報工「補開」或「批次補開」時手動觸發（傳入的是該筆報工的
 * `report_date`，不是今天——現場主管要依當時日期回推是誰、是不是請假，見 ai-rules/22 的精神）。
 *
 * 開單人＝現場主管：報工人員（`production_user_id`，缺值退回 `Created_By`）所屬部門的單位主管，
 * 若當天不在職/請假則逐層往上找、最後保底找到全站最高決策者（`eg_unit_supervisor_available()`）。
 * 這支**只負責找人與建單，不做任何權限檢查**——它本來就是系統代表「現場主管」這個角色自動執行的
 * 動作，不是某個登入者在操作。
 *
 * @return array|null 成功回傳 ['id'=>,'no'=>,'opener'=>...]；
 *   已經開過單（`abnormal_order_id` 有值）回傳 ['skipped'=>true,'id'=>既有id,'no'=>既有單號]；
 *   這筆報工本身沒有NG（不該被呼叫，防呆）回傳 null。
 * 呼叫端（尤其是報工存檔的路徑）務必自己包 try/catch——**這支失敗絕不可以讓報工存不進去**。
 */
function qab_auto_open_from_pm_ng(PDO $db, int $reportId): ?array
{
    require_once __DIR__ . '/unit_supervisor_lib.php';
    qab_ensure_schema($db);

    $st = $db->prepare("SELECT pdr.report_id, pdr.bom_ing_fid, pdr.report_date, pdr.produced_qty,
                                pdr.production_user_id, pdr.Created_By, pdr.abnormal_order_id,
                                bi.bom, bi.process_no
                         FROM pm_process_daily_report pdr
                         JOIN bom_ing bi ON bi.bom_ing_fid = pdr.bom_ing_fid
                         WHERE pdr.report_id = ?");
    $st->execute([$reportId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if (!empty($r['abnormal_order_id'])) {
        $st2 = $db->prepare("SELECT abnormal_order_no FROM qa_abnormal_order WHERE id=?");
        $st2->execute([(int)$r['abnormal_order_id']]);
        return ['skipped' => true, 'id' => (int)$r['abnormal_order_id'], 'no' => (string)$st2->fetchColumn()];
    }

    $stNg = $db->prepare("SELECT COALESCE(SUM(ng_qty),0) FROM pm_process_daily_ng WHERE report_id=?");
    $stNg->execute([$reportId]);
    $ngQty = (int)$stNg->fetchColumn();
    if ($ngQty <= 0) return null; // 沒有 NG，這支不該被呼叫（呼叫端應自行先判斷）

    $reportDate = (string)$r['report_date'];
    $prodUserId = (int)($r['production_user_id'] ?: $r['Created_By']);
    $opener = eg_unit_supervisor_available($db, $prodUserId, null, $reportDate);
    if (empty($opener['id'])) {
        // 連最高決策者都沒綁定——這是組織設定缺口，不是這支的錯，留下明確原因讓管理員去 process_report_query.php 補開
        throw new Exception('找不到可以自動開單的現場主管（含最高決策者皆未綁定），請至 org_role_setting 設定「最高核准人員」後再補開');
    }

    $noteParts = [];
    if ($opener['source'] === 'top_approver') $noteParts[] = '逐層往上皆無可用主管，改由全站最高決策者開立';
    if (!empty($opener['trail'])) $noteParts[] = '略過：' . implode('、', $opener['trail']);
    $noteParts[] = '報工人員：' . $prodUserId . '；製程：' . (string)$r['process_no'];
    $autoNote = mb_substr(implode('；', $noteParts), 0, 255);

    $produced = (int)($r['produced_qty'] ?? 0);
    $data = [
        'kind' => 'bom',
        'bom_no' => (string)$r['bom'],
        'fill_date' => $reportDate,
        'batch_qty' => $produced + $ngQty,
        'insp_qty' => $produced + $ngQty,   // 報工是逐件自檢，不是抽樣，檢驗數＝全數
        'ng_qty' => $ngQty,
        'abnormal_phenomenon' => '報工發現不良，自動開立（原始數量請於原因分類補充說明）',
        'created_by' => (int)$opener['id'],
        'resp_process_no' => (int)$r['process_no'],
        'auto_opened' => 1,
        'auto_open_note' => $autoNote,
        'pm_report_id' => $reportId,
    ];
    $created = qab_create_order($db, $data);
    $db->prepare("UPDATE pm_process_daily_report SET abnormal_order_id=? WHERE report_id=?")
       ->execute([$created['id'], $reportId]);
    return ['id' => $created['id'], 'no' => $created['no'], 'opener' => $opener['name'], 'skipped' => false];
}

/** 清單的年度下拉：只列「真的有資料」的年度（使用者要求，免得列出一堆空年度） */
function qab_years(PDO $db): array
{
    $sql = "SELECT DISTINCT YEAR(COALESCE(fill_date,occurrence_date,DATE(created_at))) y
            FROM qa_abnormal_order WHERE deleted_at IS NULL ORDER BY y DESC";
    $ys = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $y) if ((int)$y > 0) $ys[] = (int)$y;
    return $ys;
}

/* ─────────────────────────────────────────────────────────────
   製令（可綁多張）、製令內的製程與廠商
   ───────────────────────────────────────────────────────────── */

/** 這張異常單綁了哪幾張製令（主製令排最前） */
function qab_boms(PDO $db, int $orderId): array
{
    $st = $db->prepare("SELECT b.bom_no, b.is_main, b.part_no, bm.Client_Name, bm.sqty, bm.processing_state
                        FROM qa_abnormal_bom b
                        LEFT JOIN bom bm ON bm.bom = b.bom_no
                        WHERE b.order_id=? ORDER BY b.is_main DESC, b.sort_order, b.id");
    $st->execute([$orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['is_main'] = (int)$r['is_main'];
    return $rows;
}

/**
 * 建議可以綁的製令：同料號的，**外加組合件底下子件的**（使用者回報：退貨單退的是組合件料號，
 * 底下好幾張製令，只比同料號一張都找不到）。子件關係走既有的 `d_setting_bom`（parent→child）。
 *
 * @return array [['bom','d_id','Client_Name','sqty','processing_state','rel'=>'self|child|kw','rel_note'], ...]
 */
function qab_bom_candidates(PDO $db, ?int $partDId, ?string $partNo, string $kw = '', int $limit = 60): array
{
    $ids = [];  $texts = [];  $relOfId = [];  $relOfText = [];
    $partNo = trim((string)$partNo);
    if ($partDId > 0) { $ids[$partDId] = 1; $relOfId[$partDId] = ['self', '']; }
    if ($partNo !== '') { $texts[$partNo] = 1; $relOfText[$partNo] = ['self', '']; }

    if ($partDId > 0) {   // 組合件 → 子件（可能有好幾個子件，各自有自己的製令）
        $st = $db->prepare("SELECT c.d_id, c.D_Setting_Id FROM d_setting_bom sb
                            JOIN d_setting c ON c.d_id = sb.child_d_id
                            WHERE sb.parent_d_id=?");
        $st->execute([$partDId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cid = (int)$c['d_id'];  $ctx = trim((string)$c['D_Setting_Id']);
            if (!isset($ids[$cid]))            { $ids[$cid] = 1;   $relOfId[$cid] = ['child', $ctx]; }
            if ($ctx !== '' && !isset($texts[$ctx])) { $texts[$ctx] = 1; $relOfText[$ctx] = ['child', $ctx]; }
        }
    }

    $w = []; $p = [];
    if ($ids)   { $w[] = "b.d_setting_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";   foreach (array_keys($ids) as $v) $p[] = $v; }
    if ($texts) { $w[] = "b.d_id IN ("         . implode(',', array_fill(0, count($texts), '?')) . ")"; foreach (array_keys($texts) as $v) $p[] = $v; }
    $kw = trim($kw);
    if ($kw !== '') { $w[] = "(b.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ?)"; array_push($p, "%$kw%", "%$kw%", "%$kw%"); }
    if (!$w) return [];

    $sql = "SELECT b.bom, b.d_id, b.d_setting_id, b.Client_Name, b.sqty, b.processing_state
            FROM bom b WHERE (" . implode(' OR ', $w) . ") ORDER BY b.Created_At DESC, b.bom DESC LIMIT " . (int)$limit;
    $st = $db->prepare($sql);
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rel = 'kw'; $note = '';
        $dsid = (int)$r['d_setting_id'];
        if ($dsid && isset($relOfId[$dsid]))            [$rel, $note] = $relOfId[$dsid];
        elseif (isset($relOfText[trim((string)$r['d_id'])])) [$rel, $note] = $relOfText[trim((string)$r['d_id'])];
        $r['rel'] = $rel;
        $r['rel_note'] = $rel === 'child' ? '組合件子件' : ($rel === 'self' ? '同料號' : '關鍵字');
        $out[] = $r;
    }
    // 同料號的排前面、子件次之、關鍵字最後
    usort($out, function ($a, $b) {
        $o = ['self' => 0, 'child' => 1, 'kw' => 2];
        return [$o[$a['rel']], $a['bom']] <=> [$o[$b['rel']], $b['bom']];
    });
    return $out;
}

/**
 * 這幾張製令裡有哪些製程（責任單位的製程下拉要從這裡挑），順便把**該製程的廠商**帶出來。
 * 製程先後一律用 bom_sn（processing_sequence 多數是 NULL，拿來排序會亂跳）。
 */
function qab_bom_processes(PDO $db, array $bomNos): array
{
    $bomNos = array_values(array_filter(array_map('trim', $bomNos)));
    if (!$bomNos) return [];
    $in = implode(',', array_fill(0, count($bomNos), '?'));
    $st = $db->prepare("SELECT i.bom, i.bom_sn, i.process_no, i.maker_id_no, i.processing_state,
                               pn.ProcessName, ml.maker_id AS vendor_name, ml.internal AS vendor_internal
                        FROM bom_ing i
                        LEFT JOIN process_no pn ON pn.ProcessNo = i.process_no
                        LEFT JOIN maker_list ml ON ml.maker_id_no = i.maker_id_no
                        WHERE i.bom IN ($in) AND (i.is_consumed IS NULL OR i.is_consumed=0)
                        ORDER BY i.bom, i.bom_sn");
    $st->execute($bomNos);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['bom' => $r['bom'], 'bom_sn' => (int)$r['bom_sn'],
                  'process_no' => $r['process_no'] === null ? null : (int)$r['process_no'],
                  'process_name' => (string)$r['ProcessName'],
                  'vendor_id' => $r['maker_id_no'] === null ? null : (int)$r['maker_id_no'],
                  'vendor_name' => (string)$r['vendor_name'],
                  'vendor_internal' => (int)$r['vendor_internal'],
                  'state' => (string)$r['processing_state']];
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────
   相關單位意見：各部門的預設回覆職稱
   ───────────────────────────────────────────────────────────── */

/**
 * 紙本 2-QA-01-01 的「相關單位意見」是**固定五格**（發生單位／技術／生管·採購／品保／業務），
 * 不是想印幾個部門就印幾個——所以線上勾的部門要對應到這幾格才印得出來（使用者回報：印出董事長室是錯的）。
 * org＝沒有另外設定時，用全站組織角色綁定自動對應的部門。
 */
function qab_ask_slots(): array
{
    return [
        'occur'    => ['label' => '發生單位(僅廠內需填)', 'org' => ''],
        'tech'     => ['label' => '技術',   'org' => 'rd_dept'],
        'pm'       => ['label' => '生管',   'org' => 'pm_dept'],
        'purchase' => ['label' => '採購',   'org' => 'purchase_dept'],
        'qa'       => ['label' => '品保',   'org' => 'qc_dept'],
        'sales'    => ['label' => '業務',   'org' => 'sales_dept'],
    ];
}

/** dept_id => 紙本欄位代碼；先看管理員設定，沒設定才用組織角色綁定（含下轄）自動對應 */
function qab_dept_slot_map(PDO $db): array
{
    $map = [];
    require_once __DIR__ . '/org_role_lib.php';
    foreach (qab_ask_slots() as $k => $sl) {
        if ($sl['org'] === '') continue;
        foreach (eg_org_dept_ids($db, $sl['org']) as $d) if (!isset($map[(int)$d])) $map[(int)$d] = $k;
    }
    try {
        $rows = $db->query("SELECT DISTINCT dept_id, paper_slot FROM qa_ask_dept_cfg WHERE paper_slot IS NOT NULL AND paper_slot<>''")
                   ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $map[(int)$r['dept_id']] = (string)$r['paper_slot'];   // 管理員設定優先
    } catch (Throwable $e) {}
    return $map;
}

/** dept_id => [['position_id','position_name'], ...]（管理員設定，勾部門時自動帶入） */
function qab_ask_cfg(PDO $db): array
{
    $rows = $db->query("SELECT c.dept_id, c.position_id, c.paper_slot, p.name AS position_name, d.name AS dept_name
                        FROM qa_ask_dept_cfg c
                        LEFT JOIN position p ON p.id=c.position_id
                        LEFT JOIN department d ON d.id=c.dept_id
                        ORDER BY d.sort_order, c.dept_id, c.sort_order, p.sort_order")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['dept_id']][] = ['position_id' => (int)$r['position_id'],
                                      'position_name' => (string)$r['position_name'],
                                      'paper_slot' => (string)$r['paper_slot'],
                                      'dept_name' => (string)$r['dept_name']];
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────
   代碼表
   ───────────────────────────────────────────────────────────── */
/** 全部分類（含停用）：cat_id => [cat_id,parent_id,lv,name,sort_order,is_active,path] */
function qab_cause_map(PDO $db): array
{
    $rows = $db->query("SELECT cat_id,parent_id,lv,name,sort_order,is_active FROM qa_cause_cat")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $r['cat_id'] = (int)$r['cat_id'];
        $r['parent_id'] = $r['parent_id'] === null ? null : (int)$r['parent_id'];
        $r['lv'] = (int)$r['lv'];
        $r['is_active'] = (int)$r['is_active'];
        $map[$r['cat_id']] = $r;
    }
    foreach ($map as $id => $r) $map[$id]['path'] = qab_cause_path($map, $id);
    return $map;
}

/** 由 map 組出「人 → 方法 → 程式」的完整路徑字串 */
function qab_cause_path(array $map, int $catId): string
{
    $names = [];
    $cur = $catId;
    $guard = 0;
    while (isset($map[$cur]) && $guard++ < 10) {
        array_unshift($names, $map[$cur]['name']);
        $cur = $map[$cur]['parent_id'];
        if ($cur === null) break;
    }
    return implode(' → ', $names);
}

/** 樹狀（給設定頁與勾選介面用） */
function qab_cause_tree(PDO $db, bool $activeOnly = true): array
{
    $map = qab_cause_map($db);
    $byParent = [];
    foreach ($map as $r) {
        if ($activeOnly && !$r['is_active']) continue;
        $byParent[$r['parent_id'] === null ? 0 : $r['parent_id']][] = $r;
    }
    foreach ($byParent as &$list) {
        usort($list, function ($a, $b) {
            if ($a['sort_order'] === $b['sort_order']) return strcmp($a['name'], $b['name']);
            return $a['sort_order'] <=> $b['sort_order'];
        });
    }
    unset($list);
    $build = function ($pid) use (&$build, $byParent) {
        $out = [];
        foreach ($byParent[$pid] ?? [] as $n) {
            $n['children'] = $build($n['cat_id']);
            $out[] = $n;
        }
        return $out;
    };
    return $build(0);
}

/* ═══════════════════════════════════════════════════════════════════════════
   異常原因分類：使用中偵測與移轉（**全站唯一實作**）
   ---------------------------------------------------------------------------
   2026-09-23 使用者要求：設定頁改成逐層挑選之後，「修改／刪除都要偵測是否有已選定
   此項之異常單、矯正單」，刪除時還要能「詢問是否轉到別的選項，設定完直接自動全部移轉」。

   這份代碼表同時被三個地方選用，偵測與移轉一定要在同一支函式裡做完：
     ① qa_abnormal_cause      品質異常處理單（可複選）
     ② car_order_cause        異常矯正處理單（可複選，2026-09-23 之前是單選）
     ③ car_order.cause_cat_id 矯正單的「主要分類」快取欄位（舊資料可能只有這一欄）
   少算任何一個，畫面就會說「沒有人在用」，然後把別張單的分類刪成空白。

   刻意不 require car_lib.php：那支會反過來 require 本檔，而且這裡只要對兩張表下最
   單純的 SQL；矯正單模組還沒建過表時（乾淨安裝）一律當成 0 筆，不可以讓異常單的
   設定頁因此整個開不起來。
   ═══════════════════════════════════════════════════════════════════════════ */

/** 這張表在不在（矯正單模組可能還沒初始化過）；一個 request 只查一次 */
function qab_tbl_exists(PDO $db, string $tbl): bool
{
    static $cache = [];
    if (isset($cache[$tbl])) return $cache[$tbl];
    try {
        $st = $db->prepare("SHOW TABLES LIKE ?");
        $st->execute([$tbl]);
        return $cache[$tbl] = (bool)$st->fetchColumn();
    } catch (Throwable $e) { return $cache[$tbl] = false; }
}

/** 這個分類自己＋底下所有子孫的 cat_id（偵測與移轉都要含子孫，不然數字會少算） */
function qab_cause_subtree_ids(PDO $db, int $catId): array
{
    $map = qab_cause_map($db);
    $out = [];
    $walk = function ($id) use (&$walk, $map, &$out) {
        $out[] = (int)$id;
        foreach ($map as $r) if ((int)$r['parent_id'] === (int)$id) $walk((int)$r['cat_id']);
    };
    if (isset($map[$catId])) $walk($catId); else $out[] = $catId;
    return array_values(array_unique($out));
}

/**
 * 這個分類被哪些單據選用了（$withSub=true 連子孫一起算）。
 * 回傳 ['ab'=>['cnt'=>n,'docs'=>[單號…]], 'car'=>[...], 'total'=>n, 'ids'=>[算進去的cat_id]]
 * docs 最多列 20 筆（只是給人確認「是哪幾張」，不是報表）。
 */
function qab_cause_usage(PDO $db, int $catId, bool $withSub = true): array
{
    $ids = $withSub ? qab_cause_subtree_ids($db, $catId) : [$catId];
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $out = ['ab' => ['cnt' => 0, 'docs' => []], 'car' => ['cnt' => 0, 'docs' => []], 'total' => 0, 'ids' => $ids];

    try {
        $st = $db->prepare("SELECT o.abnormal_order_no AS no
                              FROM qa_abnormal_cause c
                              JOIN qa_abnormal_order o ON o.id = c.order_id
                             WHERE c.cat_id IN ($in)
                             GROUP BY o.id ORDER BY o.id DESC");
        $st->execute($ids);
        $rows = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
        $out['ab'] = ['cnt' => count($rows), 'docs' => array_slice($rows, 0, 20)];
    } catch (Throwable $e) {}

    if (qab_tbl_exists($db, 'car_order')) {
        try {
            /* 矯正單兩個來源要一起看：新的多選子表，以及舊資料只寫了主要分類快取欄位的。
               UNION 之後再取單號，同一張單不會被算成兩筆。 */
            $sub = "SELECT id FROM car_order WHERE cause_cat_id IN ($in)";
            $par = $ids;
            if (qab_tbl_exists($db, 'car_order_cause')) {
                $sub .= " UNION SELECT car_id FROM car_order_cause WHERE cat_id IN ($in)";
                $par = array_merge($ids, $ids);
            }
            $st = $db->prepare("SELECT c.car_no FROM car_order c WHERE c.id IN ($sub) ORDER BY c.id DESC");
            $st->execute($par);
            $rows = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
            $out['car'] = ['cnt' => count($rows), 'docs' => array_slice($rows, 0, 20)];
        } catch (Throwable $e) {}
    }

    $out['total'] = $out['ab']['cnt'] + $out['car']['cnt'];
    return $out;
}

/**
 * 把「選用 $fromIds 這幾個分類」的單據全部改成選用 $toId。
 * **呼叫端要自己包 transaction**（刪除分類時「移轉＋刪除」要一起成立或一起不成立）。
 *
 * 異常單那張是 UNIQUE(order_id,cat_id) 的複選表，直接 UPDATE 會在「同一張單原本就
 * 同時選了來源與目標」時撞鍵（1062）——所以一律先插目標再刪來源，不用 UPDATE。
 */
function qab_cause_transfer(PDO $db, array $fromIds, int $toId): array
{
    $fromIds = array_values(array_unique(array_map('intval', $fromIds)));
    $fromIds = array_values(array_filter($fromIds, function ($v) use ($toId) { return $v > 0 && $v !== $toId; }));
    $res = ['ab' => 0, 'car' => 0, 'car_cache' => 0];
    if (!$fromIds || $toId <= 0) return $res;
    $in = implode(',', array_fill(0, count($fromIds), '?'));

    // ① 異常單（複選表）
    $st = $db->prepare("INSERT IGNORE INTO qa_abnormal_cause (order_id, cat_id)
                        SELECT DISTINCT order_id, ? FROM qa_abnormal_cause WHERE cat_id IN ($in)");
    $st->execute(array_merge([$toId], $fromIds));
    $st = $db->prepare("DELETE FROM qa_abnormal_cause WHERE cat_id IN ($in)");
    $st->execute($fromIds);
    $res['ab'] = $st->rowCount();

    // ② 矯正單（複選子表，可能還沒建）
    if (qab_tbl_exists($db, 'car_order_cause')) {
        $st = $db->prepare("INSERT IGNORE INTO car_order_cause (car_id, cat_id)
                            SELECT DISTINCT car_id, ? FROM car_order_cause WHERE cat_id IN ($in)");
        $st->execute(array_merge([$toId], $fromIds));
        $st = $db->prepare("DELETE FROM car_order_cause WHERE cat_id IN ($in)");
        $st->execute($fromIds);
        $res['car'] = $st->rowCount();
    }

    // ③ 矯正單的主要分類快取欄位（舊資料只有這一欄，不改的話畫面照樣印著被刪掉的分類）
    if (qab_tbl_exists($db, 'car_order')) {
        $st = $db->prepare("UPDATE car_order SET cause_cat_id=? WHERE cause_cat_id IN ($in)");
        $st->execute(array_merge([$toId], $fromIds));
        $res['car_cache'] = $st->rowCount();
    }
    return $res;
}

/** 處置方式／總經理裁示 選項 */
function qab_options(PDO $db, string $kind, bool $activeOnly = true): array
{
    $sql = "SELECT opt_id,kind,name,is_scrap,is_escalate,need_capa,sort_order,is_active FROM qa_option WHERE kind=?";
    if ($activeOnly) $sql .= " AND is_active=1";
    $sql .= " ORDER BY sort_order, opt_id";
    $st = $db->prepare($sql);
    $st->execute([$kind]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['opt_id'] = (int)$r['opt_id'];
        foreach (['is_scrap', 'is_escalate', 'need_capa', 'sort_order', 'is_active'] as $k) $r[$k] = (int)$r[$k];
    }
    return $rows;
}

/** opt_id => 該列（兩種 kind 一起） */
function qab_option_map(PDO $db): array
{
    $rows = $db->query("SELECT opt_id,kind,name,is_scrap,is_escalate,need_capa,is_active FROM qa_option")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $r['opt_id'] = (int)$r['opt_id'];
        foreach (['is_scrap', 'is_escalate', 'need_capa', 'is_active'] as $k) $r[$k] = (int)$r[$k];
        $map[$r['opt_id']] = $r;
    }
    return $map;
}

/* ─────────────────────────────────────────────────────────────
   決策者設定
   ───────────────────────────────────────────────────────────── */
function qab_decider_cfgs(PDO $db, string $kind = '', bool $activeOnly = true): array
{
    $sql = "SELECT c.*, d.name AS dept_name, p.name AS position_name
            FROM qa_decider_cfg c
            LEFT JOIN department d ON d.id = c.dept_id
            LEFT JOIN position p ON p.id = c.position_id
            WHERE 1=1";
    $par = [];
    if ($kind !== '') { $sql .= " AND c.kind=?"; $par[] = $kind; }
    if ($activeOnly) $sql .= " AND c.is_active=1";
    $sql .= " ORDER BY c.kind, c.sort_order, c.cfg_id";
    $st = $db->prepare($sql);
    $st->execute($par);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['cfg_id'] = (int)$r['cfg_id'];
        $r['dept_id'] = (int)$r['dept_id'];
        $r['position_id'] = $r['position_id'] === null ? null : (int)$r['position_id'];
        $r['include_sub'] = (int)$r['include_sub'];
        $r['is_active'] = (int)$r['is_active'];
        /* 顯示名稱**一律即時由「部門＋職稱」組出來**，不存一份文字（鐵律4）：
           存下來的那份會在部門或職稱改名之後繼續顯示舊名稱，而且完全不報錯。 */
        $r['show_name'] = trim(((string)$r['dept_name']) . ' ' . ((string)($r['position_name'] ?: '不限職稱')));
    }
    return $rows;
}

/** 某一筆決策者設定實際涵蓋哪些在職人員（部門＋職稱；include_sub 時含下轄部門） */
function qab_decider_people(PDO $db, array $cfg): array
{
    require_once __DIR__ . '/people_lib.php';
    require_once __DIR__ . '/org_role_lib.php';
    $deptIds = $cfg['include_sub'] ? eg_dept_subtree_ids($db, (int)$cfg['dept_id']) : [(int)$cfg['dept_id']];
    $rows = eg_people_list($db, ['dept_ids' => $deptIds, 'all_posts' => true]);
    $out = [];
    foreach ($rows as $r) {
        if (!in_array((int)$r['dept_id'], $deptIds, true)) continue;
        if ($cfg['position_id'] !== null && (int)$r['position_id'] !== (int)$cfg['position_id']) continue;
        $out[] = $r;
    }
    return $out;
}

/** 這個人是否落在某一類決策者範圍內（decider / top） */
function qab_user_in_decider(PDO $db, int $uid, string $kind): bool
{
    if ($uid <= 0) return false;
    foreach (qab_decider_cfgs($db, $kind) as $cfg) {
        foreach (qab_decider_people($db, $cfg) as $p) if ((int)$p['id'] === $uid) return true;
    }
    return false;
}

/* ─────────────────────────────────────────────────────────────
   權限
   使用者拍板（2026-09-18）：扣款的填寫與核准＝「部門綁定＋角色並用」。
   部門綁定走 org_role_lib（生管部門／業務部門／會計部門），角色走本模組 role_code。
   ───────────────────────────────────────────────────────────── */
function qab_user_role_codes(PDO $db, int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $codes = [];
    try {
        $st = $db->prepare("SELECT DISTINCT r.role_code FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=?");
        $st->execute([$uid]);
        $codes = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {}
    try {
        $st = $db->prepare("SELECT DISTINCT rf.feature_code FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id WHERE ur.user_id=?");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $f) $codes[] = (string)$f;
    } catch (Throwable $e) {}
    return $cache[$uid] = array_values(array_unique($codes));
}

function qab_user_dept_ids(PDO $db, int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $out = [];
    try {
        $st = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=?");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d !== null) $out[] = (int)$d;
    } catch (Throwable $e) {}
    return $cache[$uid] = array_values(array_unique($out));
}

/** 這個人是不是屬於某個組織角色綁定的部門（含下轄） */
function qab_in_org_dept(PDO $db, int $uid, string $key): bool
{
    require_once __DIR__ . '/org_role_lib.php';
    $ids = eg_org_dept_ids($db, $key);
    if (!$ids) return false;
    foreach (qab_user_dept_ids($db, $uid) as $d) if (in_array($d, $ids, true)) return true;
    return false;
}

function qab_perms(PDO $db, int $uid): array
{
    $none = ['uid' => 0, 'name' => '', 'isAdmin' => false, 'canView' => false, 'canCreate' => false,
             'canAdmin' => false, 'canDecide' => false, 'canGm' => false,
             'canDeductFill' => false, 'canDeductApprove' => false, 'canQcSign' => false];
    if ($uid <= 0) return $none;
    $st = $db->prepare("SELECT id, user_cname, user_uname, state, user_status FROM `user` WHERE id=?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return $none;
    if ((int)$u['state'] === 0 || (int)$u['user_status'] === 90) return $none;   // 離職／特殊帳號 fail-closed

    $codes = qab_user_role_codes($db, $uid);
    $has = function (array $c) use ($codes) { return (bool)array_intersect($c, $codes); };
    $isAdmin = $uid === 1 || $has(['admin', 'superadmin']);

    $canAdmin = $isAdmin || $has(['qab_admin']);
    // 品管的「管理檢驗設定」本來就是這張單的主辦，不必再多指派一個角色
    $canCreate = $canAdmin || $has(['qab_fill', 'qc_manage_settings', 'qc_fill_inspection'])
                 || qab_in_org_dept($db, $uid, 'qc_dept') || qab_in_org_dept($db, $uid, 'sales_dept');
    $canDecide = $canAdmin || $has(['qab_decide', 'qa_disposition_reply']) || qab_user_in_decider($db, $uid, 'decider');
    // 最高決策者：組織角色的最高核准人員，或管理員在設定裡登記的部門職稱
    // 最終決策者一律吃全站統一綁定（org_role_setting.php 的「最高核准人員」）＋其代理人；
    // qab_gm 角色只是給特殊情況補授權用，本模組**不再**自己設定一份最高決策者名單
    // 刻意**不含** canAdmin：異常單管理員不等於總經理，讓模組管理員也能蓋最終裁示，
    // 等於把「最高決策者由全站統一綁定」這件事整個架空。管理員要補的是歷史單，那條路走 canBackfill。
    $gm = qab_gm_person($db);
    $canGm = $isAdmin || $has(['qab_gm'])
             || ($gm['base_id'] > 0 && $gm['base_id'] === $uid)
             || ($gm['id'] > 0 && $gm['id'] === $uid);
    $canDeductFill = $canAdmin || $has(['qab_deduct_fill'])
                     || qab_in_org_dept($db, $uid, 'pm_dept') || qab_in_org_dept($db, $uid, 'sales_dept');
    $canDeductApprove = $canAdmin || $has(['qab_deduct_approve']) || qab_in_org_dept($db, $uid, 'acc_dept');
    $canQcSign = $canAdmin || $has(['qab_fill', 'qc_manage_settings', 'qc_fill_inspection']) || qab_in_org_dept($db, $uid, 'qc_dept');
    $canView = $canCreate || $canDecide || $canGm || $canDeductFill || $canDeductApprove
               || $has(['qab_view', 'qc_view_readonly', 'ncr_view', 'ncr_admin']);

    return ['uid' => $uid, 'name' => (string)($u['user_cname'] ?: $u['user_uname']),
            'isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canView' => $canView, 'canCreate' => $canCreate,
            'canDecide' => $canDecide, 'canGm' => $canGm,
            'canDeductFill' => $canDeductFill, 'canDeductApprove' => $canDeductApprove, 'canQcSign' => $canQcSign,
            // 補資料（指定補章人員與印章日期）刻意只給「異常單管理員」——使用者定調：這個功能只有異常單有
            'canBackfill' => $canAdmin];
}

/**
 * 這個人看不看得到「這一張」單。
 * canView 是模組層的一般檢視權，但**被徵詢意見的人多半是完全沒有本模組角色的一般同仁**——
 * 只看 canView 的話，通知點進來會被擋在門外、整個徵詢流程就斷了。
 * 所以再放行「跟這張單有關的人」：開單人／共同編輯者／被徵詢的本人或該部門的人／追蹤人。
 */
function qab_can_view_order(PDO $db, array $perms, int $orderId): bool
{
    if (!empty($perms['canView'])) return true;
    $uid = (int)($perms['uid'] ?? 0);
    if ($uid <= 0 || $orderId <= 0) return false;

    $st = $db->prepare("SELECT created_by FROM qa_abnormal_order WHERE id=?");
    $st->execute([$orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ((int)$row['created_by'] === $uid) return true;

    if (function_exists('eg_qa_user_is_order_editor') && eg_qa_user_is_order_editor($db, $orderId, $uid)) return true;

    $myDepts = qab_user_dept_ids($db, $uid);
    $st = $db->prepare("SELECT dept_id, user_id FROM qa_abnormal_order_flow WHERE abnormal_order_id=?");
    $st->execute([$orderId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if ((int)$f['user_id'] === $uid) return true;
        if ((int)$f['user_id'] === 0 && in_array((int)$f['dept_id'], $myDepts, true)) return true;
    }
    try {
        $st = $db->prepare("SELECT 1 FROM qa_abnormal_follower WHERE abnormal_order_id=? AND user_id=? LIMIT 1");
        $st->execute([$orderId, $uid]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {}
    return false;
}

/** 這個人能不能改這張單的「填寫區」（表頭、現象、原因分類、量測值…） */
function qab_can_edit_form(PDO $db, array $perms, array $order): bool
{
    if (!empty($order['is_closed'])) return $perms['isAdmin'] || $perms['canAdmin'];
    if ($perms['canAdmin']) return true;
    if ((int)($order['created_by'] ?? 0) === (int)$perms['uid']) return true;
    if (function_exists('eg_qa_user_is_order_editor') && eg_qa_user_is_order_editor($db, (int)$order['id'], (int)$perms['uid'])) return true;
    return $perms['canCreate'] && $perms['canDecide'];   // 有決策權的主管也可以補正內容
}

/* ─────────────────────────────────────────────────────────────
   編號
   ───────────────────────────────────────────────────────────── */
function qab_next_order_no(PDO $db, ?string $ymd = null): string
{
    $ts = $ymd ? strtotime($ymd) : time();
    $roc = (int)date('Y', $ts) - 1911;
    $prefix = 'Q' . str_pad((string)$roc, 3, '0', STR_PAD_LEFT) . date('md', $ts);
    $st = $db->prepare("SELECT abnormal_order_no FROM qa_abnormal_order WHERE abnormal_order_no LIKE ? ORDER BY abnormal_order_no DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = $st->fetchColumn();
    $seq = $last ? ((int)substr((string)$last, -3) + 1) : 1;
    return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/**
 * 配發報廢單號：F + 民國年3碼 + MMDD + 流水3碼（例 F1150918001）。
 * 與 car_alloc_numbers 同一套做法：INSERT IGNORE 建當日列 + SELECT … FOR UPDATE 鎖住配號，杜絕並發撞號。
 */
function qab_scrap_alloc(PDO $db, ?string $ymd = null): string
{
    $ts = $ymd ? strtotime($ymd) : time();
    $dateSql = date('Y-m-d', $ts);
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $db->prepare("INSERT IGNORE INTO qa_scrap_seq (seq_date,last_no) VALUES (?,0)")->execute([$dateSql]);
        $sel = $db->prepare("SELECT last_no FROM qa_scrap_seq WHERE seq_date=? FOR UPDATE");
        $sel->execute([$dateSql]);
        $next = (int)$sel->fetchColumn() + 1;
        $db->prepare("UPDATE qa_scrap_seq SET last_no=? WHERE seq_date=?")->execute([$next, $dateSql]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    $roc = (int)date('Y', $ts) - 1911;
    return 'F' . str_pad((string)$roc, 3, '0', STR_PAD_LEFT) . date('md', $ts) . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * 這張 BOM 已經「結案配發報廐單號」的確認報廐數量——待包裝／BOM總覽／檢驗表的「良品數」、
 * 快速出貨的可出量，一律呼叫這支，不要各自寫一份加總（鐵律4）。
 *
 * 只算 is_closed=1 AND scrap_no IS NOT NULL（=使用者拍板「異常單結案配號時才正式扣」，
 * 沒有另外存一個「已確認」狀態，這兩個條件本身就是唯一真相）；同一張單的 ng_qty 整筆都算報廐
 * （本模組的處置決策是整張單一次裁決，沒有做「這張單一部分報廐一部分特採」的切分，與 qab_final() 同一個粒度）。
 *
 * $uptoBomSn：
 *   null（預設）＝整張 BOM 的總確認報廐量，不分站——給包裝、BOM 總覽這種「整批」畫面用。
 *   帶入數字＝只算「發現時所在站別 bom_sn ≤ 這個值」的報廐量——給某一站自己的檢驗表算「這一站的良品數」用
 *   （使用者拍板：報廐只影響「該站之後（含該站）」，該站之前已經發生的事實不動）。
 *
 * 找「哪一站發現的」：優先用 pm_report_id（報工NG自動/補開一定會填，直接回推 bom_ing_fid 最準）；
 * 沒有就退回 resp_process_no（人工開單填的責任製程，同一 bom 同 process_no 取最早一站，較保守）；
 * 兩者都沒有（人工開單、還沒填責任製程）就視為第 0 站＝不管 $uptoBomSn 是多少都照算，
 * 寧可讓還沒查清楚來源的報廐多扣一點，也不要在攔阻出貨的數字上漏算。
 */
function qab_bom_scrap_qty(PDO $db, string $bom, ?int $uptoBomSn = null): int
{
    $bom = trim($bom);
    if ($bom === '') return 0;
    $st = $db->prepare("SELECT o.ng_qty, o.pm_report_id, o.resp_process_no,
                                pr.bom_ing_fid AS pm_bom_ing_fid,
                                (SELECT MIN(bi2.bom_sn) FROM bom_ing bi2
                                  WHERE bi2.bom=o.bom_no AND bi2.process_no=o.resp_process_no) AS resp_bom_sn,
                                bi_pm.bom_sn AS pm_bom_sn
                         FROM qa_abnormal_order o
                         LEFT JOIN pm_process_daily_report pr ON pr.report_id=o.pm_report_id
                         LEFT JOIN bom_ing bi_pm ON bi_pm.bom_ing_fid=pr.bom_ing_fid
                         WHERE o.bom_no=? AND o.is_closed=1 AND o.scrap_no IS NOT NULL AND o.deleted_at IS NULL");
    $st->execute([$bom]);
    $sum = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($uptoBomSn !== null) {
            $originSn = null;
            if ($r['pm_report_id']) $originSn = $r['pm_bom_sn'] !== null ? (int)$r['pm_bom_sn'] : null;
            if ($originSn === null && $r['resp_process_no']) $originSn = $r['resp_bom_sn'] !== null ? (int)$r['resp_bom_sn'] : null;
            if ($originSn !== null && $originSn > $uptoBomSn) continue; // 發現於這一站之後，還不影響這一站
        }
        $sum += (int)($r['ng_qty'] ?? 0);
    }
    return $sum;
}

/* ─────────────────────────────────────────────────────────────
   扣款：製程金額自動帶入
   來源＝製程移轉一覽表（views/pm/Transfer_Log_Analysis.php）讀的同一張 bom_ing_transfer_log。
   金額優先取 process_amount，為 0 才用 數量×單價 回推（與那一頁的防呆同一條規則）。
   ───────────────────────────────────────────────────────────── */
function qab_deduct_autofill(PDO $db, $bomNos): array
{
    $list = is_array($bomNos) ? $bomNos : [$bomNos];
    $list = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $list)))));
    if (!$list) return [];
    $in  = implode(',', array_fill(0, count($list), '?'));
    $sql = "SELECT t.bom, t.transfer_id, t.transfer_no, t.bom_sn, t.transfer_qty, t.price, t.process_amount,
                   t.maker_from, m.maker_id AS vendor_name, pn.ProcessName
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list m ON m.maker_id_no = t.maker_from
            LEFT JOIN bom_ing bi ON bi.bom = t.bom AND bi.bom_sn = t.bom_sn
            LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
            WHERE t.bom IN ($in)
            ORDER BY t.bom, t.bom_sn, t.transfer_id";
    $st = $db->prepare($sql);
    $st->execute($list);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['transfer_qty'];
        $amt = (float)$r['process_amount'];
        if ($amt == 0 && $qty > 0 && (float)$r['price'] > 0) $amt = $qty * (float)$r['price'];
        $out[] = [
            'bom_no'       => (string)$r['bom'],
            'transfer_id'  => (int)$r['transfer_id'],
            'transfer_no'  => (string)$r['transfer_no'],
            'bom_sn'       => (int)$r['bom_sn'],
            'process_name' => (string)($r['ProcessName'] ?? ''),
            'vendor_name'  => (string)($r['vendor_name'] ?? $r['maker_from']),
            'qty'          => $qty,
            'amount'       => round($amt, 2),
        ];
    }
    return $out;
}

/** 扣款合計：製程小計×加成 ＋ 其他小計 */
function qab_deduct_totals(array $rows, float $rate): array
{
    $proc = 0.0; $other = 0.0;
    foreach ($rows as $r) {
        if (!(int)($r['included'] ?? 1)) continue;
        $a = (float)($r['amount'] ?? 0);
        if (($r['kind'] ?? 'process') === 'other') $other += $a; else $proc += $a;
    }
    if ($rate <= 0) $rate = 1.0;
    $procRated = round($proc * $rate, 2);
    return ['process' => round($proc, 2), 'process_rated' => $procRated,
            'other' => round($other, 2), 'total' => round($procRated + $other, 2), 'rate' => $rate];
}

/**
 * 製程欄的說明文字：全部帶進來就寫「全部製程」，只挑了幾站就把站名列出來。
 * （使用者要求：說明自動顯示所有製程或是包含哪些製程）
 */
function qab_deduct_process_desc(array $rows): string
{
    $all = []; $inc = [];
    foreach ($rows as $r) {
        if (($r['kind'] ?? 'process') !== 'process') continue;
        $nm = trim((string)($r['process_name'] ?? '')) ?: ('第' . (int)($r['bom_sn'] ?? 0) . '站');
        $all[] = $nm;
        if ((int)($r['included'] ?? 1)) $inc[] = $nm;
    }
    if (!$all) return '';
    if (!$inc) return '（未計入任何製程）';
    if (count($inc) === count($all)) return '全部製程（' . implode('、', array_unique($inc)) . '）';
    return '含：' . implode('、', array_unique($inc));
}

/* ─────────────────────────────────────────────────────────────
   讀一張完整的單
   ───────────────────────────────────────────────────────────── */
function qab_order(PDO $db, int $id): ?array
{
    $st = $db->prepare("SELECT o.*, t.type_name,
                               cu.user_cname AS created_name, du.user_cname AS decided_name,
                               gu.user_cname AS gm_name, au.user_cname AS deduct_appr_name,
                               pu.user_cname AS deduct_pm_name, qu.user_cname AS deduct_qc_name,
                               ou.user_cname AS owner_sign_name,
                               pn.ProcessName AS resp_process_name,
                               ml.maker_id AS resp_vendor_name, ml.internal AS resp_vendor_internal
                        FROM qa_abnormal_order o
                        LEFT JOIN qa_abnormal_type t ON t.type_id = o.abnormal_type_id
                        LEFT JOIN `user` cu ON cu.id = o.created_by
                        LEFT JOIN `user` du ON du.id = o.disp_decided_by
                        LEFT JOIN `user` gu ON gu.id = o.gm_decided_by
                        LEFT JOIN `user` au ON au.id = o.deduct_appr_by
                        LEFT JOIN `user` pu ON pu.id = o.deduct_pm_by
                        LEFT JOIN `user` qu ON qu.id = o.deduct_qc_by
                        LEFT JOIN `user` ou ON ou.id = o.owner_sign_by
                        LEFT JOIN process_no pn ON pn.ProcessNo = o.resp_process_no
                        LEFT JOIN maker_list ml ON ml.maker_id_no = o.responsible_vendor_id
                        WHERE o.id=?");
    $st->execute([$id]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;

    $causeMap = qab_cause_map($db);
    $optMap   = qab_option_map($db);

    $st = $db->prepare("SELECT cat_id FROM qa_abnormal_cause WHERE order_id=?");
    $st->execute([$id]);
    $o['cause_ids'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $o['cause_paths'] = [];
    foreach ($o['cause_ids'] as $cid) $o['cause_paths'][] = ['cat_id' => $cid, 'path' => $causeMap[$cid]['path'] ?? '(已刪除)'];

    $st = $db->prepare("SELECT kind,opt_id FROM qa_abnormal_opt WHERE order_id=?");
    $st->execute([$id]);
    $o['disp_ids'] = []; $o['gm_ids'] = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['kind'] === 'gm') $o['gm_ids'][] = (int)$r['opt_id'];
        else $o['disp_ids'][] = (int)$r['opt_id'];
    }
    $nameOf = function (array $ids) use ($optMap) {
        $o = [];
        foreach ($ids as $i) if (isset($optMap[$i])) $o[] = $optMap[$i]['name'];
        return $o;
    };
    $o['disp_names'] = $nameOf($o['disp_ids']);
    $o['gm_names']   = $nameOf($o['gm_ids']);

    $st = $db->prepare("SELECT r.dept_id, r.user_id, d.name AS department_name, u.user_cname
                        FROM qa_abnormal_resp r
                        LEFT JOIN department d ON d.id=r.dept_id
                        LEFT JOIN `user` u ON u.id=r.user_id
                        WHERE r.order_id=? ORDER BY r.id");
    $st->execute([$id]);
    $o['resp_people'] = $st->fetchAll(PDO::FETCH_ASSOC);

    /* 綁定的製令（可多張）：主製令就是表頭那一欄，其餘是相關製令。
       扣款與責任製程都吃 bom_list，所以這裡一定要把主製令補進去（舊單只有 bom_no、沒有明細列）。 */
    $o['boms'] = qab_boms($db, $id);
    $o['bom_list'] = [];
    if (trim((string)$o['bom_no']) !== '') $o['bom_list'][] = trim((string)$o['bom_no']);
    foreach ($o['boms'] as $b) {
        $bn = trim((string)$b['bom_no']);
        if ($bn !== '' && !in_array($bn, $o['bom_list'], true)) $o['bom_list'][] = $bn;
    }
    $o['bom_processes'] = qab_bom_processes($db, $o['bom_list']);

    $st = $db->prepare("SELECT seq, dim_name, vals FROM qa_abnormal_measure WHERE order_id=? ORDER BY seq");
    $st->execute([$id]);
    $o['measures'] = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $o['measures'][] = ['seq' => (int)$m['seq'], 'dim_name' => (string)$m['dim_name'],
                            'vals' => json_decode((string)$m['vals'], true) ?: []];
    }

    $st = $db->prepare("SELECT d.*, u.user_cname AS created_name FROM qa_abnormal_deduct d
                        LEFT JOIN `user` u ON u.id=d.created_by
                        WHERE d.order_id=? ORDER BY d.kind DESC, d.sort_order, d.id");
    $st->execute([$id]);
    $o['deducts'] = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($o['deducts'] as &$d) {
        $d['id'] = (int)$d['id'];
        $d['included'] = (int)$d['included'];
        $d['amount'] = $d['amount'] === null ? null : (float)$d['amount'];
        $d['amount_auto'] = $d['amount_auto'] === null ? null : (float)$d['amount_auto'];
        $d['qty'] = $d['qty'] === null ? null : (float)$d['qty'];
    }
    unset($d);
    $o['deduct_totals'] = qab_deduct_totals($o['deducts'], (float)($o['surcharge_rate'] ?: qab_default_rate($db)));
    $o['deduct_desc']   = qab_deduct_process_desc($o['deducts']);

    $st = $db->prepare("SELECT f.*, d.name AS department_name, u.user_cname, p.name AS position_name, ru.user_cname AS replied_name,
                               ab.user_cname AS asked_name
                        FROM qa_abnormal_order_flow f
                        LEFT JOIN department d ON d.id=f.dept_id
                        LEFT JOIN `user` u ON u.id=f.user_id
                        LEFT JOIN `user` ru ON ru.id=f.replied_by
                        LEFT JOIN `user` ab ON ab.id=f.asked_by
                        LEFT JOIN position p ON p.id=f.position_id
                        WHERE f.abnormal_order_id=? ORDER BY f.round_no, f.flow_id");
    $st->execute([$id]);
    $o['rounds'] = $st->fetchAll(PDO::FETCH_ASSOC);
    // 指定職稱可以有好幾個（多人只要一人回覆），把名稱一起組好給畫面與列印用
    $posNameMap = [];
    foreach ($o['rounds'] as $r) {
        foreach (array_filter(array_map('intval', explode(',', (string)$r['position_ids']))) as $pid) $posNameMap[$pid] = '';
    }
    if ($posNameMap) {
        $in2 = implode(',', array_fill(0, count($posNameMap), '?'));
        $st2 = $db->prepare("SELECT id, name FROM position WHERE id IN ($in2)");
        $st2->execute(array_keys($posNameMap));
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $pr) $posNameMap[(int)$pr['id']] = (string)$pr['name'];
    }
    foreach ($o['rounds'] as &$r) {
        $pids = array_values(array_filter(array_map('intval', explode(',', (string)$r['position_ids']))));
        $r['position_id_list'] = $pids;
        $names = [];
        foreach ($pids as $pid) if (!empty($posNameMap[$pid])) $names[] = $posNameMap[$pid];
        if (!$names && trim((string)$r['position_name']) !== '') $names[] = (string)$r['position_name'];
        $r['position_names'] = $names;
    }
    unset($r);

    $o['final']   = qab_final($db, $o, $optMap);
    $o['need_gm'] = qab_need_gm($db, $o, $optMap);
    $o['status']  = qab_status($o);

    // 最終決策者（畫面與通知都讀這一份；來源是全站統一綁定）
    $o['gm_person'] = qab_gm_person($db);
    /* 客戶／料號是不是「真的由來源帶得出來」——畫面要據此決定鎖不鎖。
       只看「有沒有綁來源」是不夠的：手建的客退單常常沒綁料號主檔、Client_name 也是空的，
       那時鎖起來就變成「欄位空白又不給填」，使用者只看得到一片空白（實際踩過）。 */
    $srcNow = qab_resolve_source($db, $o['bom_no'], $o['ir_id'] ? (int)$o['ir_id'] : null);
    /* 自動補回來源帶得出來、但欄位還空著的值——舊單是在解析規則修好之前建立的，
       不補的話清單那一欄會一直空白（使用者回報「綁定退貨單建立的都沒有自動帶出客戶」）。
       只補空的，已經有值的一律不動。 */
    $fix = [];
    if (trim((string)$o['client_name']) === '' && trim((string)($srcNow['client']['name'] ?? '')) !== '') {
        $o['client_name'] = $srcNow['client']['name'];
        $o['client_id']   = $srcNow['client']['id'];
        $fix['client_name'] = $o['client_name'];
        $fix['client_id']   = $o['client_id'];
    }
    if (trim((string)$o['part_no']) === '' && trim((string)($srcNow['part_no'] ?? '')) !== '') {
        $o['part_no'] = $srcNow['part_no'];
        $fix['part_no'] = $o['part_no'];
    }
    if (!$o['part_d_id'] && !empty($srcNow['part_d_id'])) {
        $o['part_d_id'] = (int)$srcNow['part_d_id'];
        $fix['part_d_id'] = $o['part_d_id'];
    }
    if ($fix) {
        $set = implode(',', array_map(fn($c) => "$c=?", array_keys($fix)));
        $st2 = $db->prepare("UPDATE qa_abnormal_order SET $set WHERE id=?");
        $st2->execute(array_merge(array_values($fix), [$id]));
    }
    $o['client_bound'] = trim((string)($srcNow['client']['name'] ?? '')) !== '' ? 1 : 0;
    $o['part_bound']   = trim((string)($srcNow['part_no'] ?? '')) !== '' ? 1 : 0;
    $o['src_client_from'] = (string)($srcNow['client']['src'] ?? '');
    /* 綁到的客退單已經不在了（ERP 重新匯入會換一組 IR_id）——畫面要講出來，
       不然只會看到客戶與料號突然帶不出來，完全看不出原因。 */
    $o['ir_missing'] = 0;
    if ((int)$o['ir_id'] > 0) {
        $c = $db->prepare("SELECT 1 FROM ir_track WHERE IR_id=?");
        $c->execute([(int)$o['ir_id']]);
        if (!$c->fetchColumn()) $o['ir_missing'] = 1;
    }
    $o['src_part_from']   = (string)($srcNow['src'] ?? '');
    // 補資料模式（今日往前 N 天以前的業務日期）
    $o['is_backfill']    = qab_is_backfill($db, $o) ? 1 : 0;
    $o['backfill_days']  = qab_backfill_days($db);
    // 各簽章格目前是誰、哪一天（補登介面與列印共用同一份登記表）
    $o['signs'] = [];
    foreach (qab_sign_slots() as $k => $sl) {
        $o['signs'][$k] = ['label' => $sl['label'], 'perm' => $sl['perm'],
                           'user_id' => $o[$sl['by']] === null ? null : (int)$o[$sl['by']],
                           'at' => (string)($o[$sl['at']] ?? ''),
                           'name' => ''];
    }
    $ids = array_values(array_filter(array_column($o['signs'], 'user_id')));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id, user_cname FROM `user` WHERE id IN ($in)");
        $st->execute($ids);
        $nm = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $nm[(int)$r['id']] = (string)$r['user_cname'];
        foreach ($o['signs'] as $k => $v) if ($v['user_id']) $o['signs'][$k]['name'] = $nm[$v['user_id']] ?? '';
    }
    // 圖章模板可能有 {部門}{職稱}，要用「簽章當天」的職務（ai-rules/22）
    foreach ($o['signs'] as $k => $v) {
        $d = trim((string)$v['at']) !== '' ? substr((string)$v['at'], 0, 10) : (string)$o['fill_date'];
        $pi = $v['user_id'] ? qab_person_asof($db, (int)$v['user_id'], (string)$d) : ['dept' => '', 'position' => ''];
        $o['signs'][$k]['dept'] = $pi['dept'];
        $o['signs'][$k]['position'] = $pi['position'];
    }
    return $o;
}

/**
 * 最終處置：總經理裁示優先（使用者定調）；沒有裁示才看主管的處置方式。
 * 回傳 ['from'=>'gm|disp|none','ids'=>[],'names'=>[],'is_scrap'=>bool,'need_capa'=>bool]
 */
function qab_final(PDO $db, array $o, ?array $optMap = null): array
{
    if ($optMap === null) $optMap = qab_option_map($db);
    $ids = []; $from = 'none';
    if (!empty($o['gm_ids'])) { $ids = $o['gm_ids']; $from = 'gm'; }
    elseif (!empty($o['disp_ids'])) { $ids = $o['disp_ids']; $from = 'disp'; }
    $names = []; $scrap = false; $capa = false;
    foreach ($ids as $i) {
        if (!isset($optMap[$i])) continue;
        $names[] = $optMap[$i]['name'];
        if ($optMap[$i]['is_scrap']) $scrap = true;
        if ($optMap[$i]['need_capa']) $capa = true;
    }
    return ['from' => $from, 'ids' => $ids, 'names' => $names, 'is_scrap' => $scrap, 'need_capa' => $capa];
}

/** 這張單現在卡在哪一關（由資料推導，不另存狀態欄，避免兩份狀態對不起來） */
function qab_status(array $o): array
{
    if (!empty($o['is_closed'])) return ['code' => 'closed', 'label' => '已結案'];
    foreach ($o['rounds'] ?? [] as $r) {
        if (($r['status'] ?? '') !== 'Returned') {
            $who = trim((string)($r['user_cname'] ?: $r['department_name']));
            return ['code' => 'reply', 'label' => '等待回覆' . ($who !== '' ? '（' . $who . '）' : '')];
        }
    }
    if (empty($o['disp_ids']) && empty($o['gm_ids'])) return ['code' => 'decide', 'label' => '待決策'];
    if (!empty($o['need_gm']) && empty($o['gm_ids'])) return ['code' => 'gm', 'label' => '待總經理裁示'];
    if (!empty($o['gm_deduct']) || (!empty($o['final']['is_scrap']))) {
        if (empty($o['deduct_appr_at'])) return ['code' => 'deduct', 'label' => '扣款確認中'];
    }
    return ['code' => 'ready', 'label' => '可結案'];
}

/** 這張單是不是還在等總經理裁示（處置方式勾了「轉總經理裁示」但還沒裁示） */
function qab_need_gm(PDO $db, array $o, ?array $optMap = null): bool
{
    if ($optMap === null) $optMap = qab_option_map($db);
    foreach ($o['disp_ids'] ?? [] as $i) if (!empty($optMap[$i]['is_escalate'])) return empty($o['gm_ids']);
    return false;
}

/* ─────────────────────────────────────────────────────────────
   清單
   ───────────────────────────────────────────────────────────── */
function qab_list(PDO $db, array $f = []): array
{
    // 軟刪除的單一律不出現在清單（要看已刪除的請用 deleted=1，只有異常單管理員叫得動）
    $w = [empty($f['deleted']) ? 'o.deleted_at IS NULL' : 'o.deleted_at IS NOT NULL'];
    $p = [];
    if (!empty($f['year']))   { $w[] = "YEAR(COALESCE(o.fill_date,o.occurrence_date,DATE(o.created_at)))=?"; $p[] = (int)$f['year']; }
    if (!empty($f['month']))  { $w[] = "MONTH(COALESCE(o.fill_date,o.occurrence_date,DATE(o.created_at)))=?"; $p[] = (int)$f['month']; }
    if (isset($f['closed']) && $f['closed'] !== '') { $w[] = "o.is_closed=?"; $p[] = (int)$f['closed']; }
    if (!empty($f['source'])) { $w[] = "o.source_type=?"; $p[] = $f['source']; }
    if (!empty($f['kw'])) {
        $kw = '%' . $f['kw'] . '%';
        $w[] = "(o.abnormal_order_no LIKE ? OR o.bom_no LIKE ? OR o.ir_no LIKE ? OR o.part_no LIKE ?
                 OR o.client_name LIKE ? OR o.abnormal_phenomenon LIKE ? OR o.responsible_unit LIKE ? OR o.scrap_no LIKE ?)";
        array_push($p, $kw, $kw, $kw, $kw, $kw, $kw, $kw, $kw);
    }
    $sql = "SELECT o.id, o.abnormal_order_no, o.source_type, o.fill_date, o.occurrence_date, o.client_name,
                   o.part_no, o.bom_no, o.ir_no, o.responsible_unit, o.ng_qty, o.sqty, o.is_closed, o.closed_at,
                   o.scrap_no, o.gm_deduct, o.abnormal_phenomenon, o.created_by, cu.user_cname AS created_name,
                   o.deleted_at, o.deleted_by, dl.user_cname AS deleted_name,
                   pn.ProcessName AS resp_process_name, ml.maker_id AS resp_vendor_name
            FROM qa_abnormal_order o
            LEFT JOIN `user` cu ON cu.id=o.created_by
            LEFT JOIN `user` dl ON dl.id=o.deleted_by
            LEFT JOIN process_no pn ON pn.ProcessNo=o.resp_process_no
            LEFT JOIN maker_list ml ON ml.maker_id_no=o.responsible_vendor_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY COALESCE(o.fill_date,o.occurrence_date,DATE(o.created_at)) DESC, o.id DESC";
    $st = $db->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    // 狀態要看「有沒有還沒回覆的輪次／有沒有處置／有沒有裁示」，一次撈完再組（避免逐筆查）
    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $pend = [];
    $st = $db->prepare("SELECT abnormal_order_id, COUNT(*) c FROM qa_abnormal_order_flow
                        WHERE abnormal_order_id IN ($in) AND status<>'Returned' GROUP BY abnormal_order_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pend[(int)$r['abnormal_order_id']] = (int)$r['c'];
    $opts = [];
    $st = $db->prepare("SELECT order_id, kind, opt_id FROM qa_abnormal_opt WHERE order_id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $opts[(int)$r['order_id']][$r['kind']][] = (int)$r['opt_id'];
    $optMap = qab_option_map($db);

    foreach ($rows as &$r) {
        $id = (int)$r['id'];
        $r['disp_ids'] = $opts[$id]['disp'] ?? [];
        $r['gm_ids']   = $opts[$id]['gm'] ?? [];
        $r['rounds']   = [];
        $r['pending']  = $pend[$id] ?? 0;
        $r['final']    = qab_final($db, $r, $optMap);
        $r['need_gm']  = qab_need_gm($db, $r, $optMap);
        if (!empty($r['is_closed']))      $r['status'] = ['code' => 'closed', 'label' => '已結案'];
        elseif ($r['pending'] > 0)        $r['status'] = ['code' => 'reply', 'label' => '等待單位回覆'];
        elseif (!$r['disp_ids'] && !$r['gm_ids']) $r['status'] = ['code' => 'decide', 'label' => '待決策'];
        elseif ($r['need_gm'])            $r['status'] = ['code' => 'gm', 'label' => '待總經理裁示'];
        elseif (($r['gm_deduct'] || $r['final']['is_scrap'])) $r['status'] = ['code' => 'deduct', 'label' => '扣款確認中'];
        else                              $r['status'] = ['code' => 'ready', 'label' => '可結案'];
        $r['final_label'] = implode('、', $r['final']['names']);
    }
    unset($r);
    return $rows;
}

/** 給不合格品管制記錄表用：一次取回多張單的狀態與最終處置（唯讀顯示） */
function qab_status_map(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_map('intval', array_filter($orderIds))));
    if (!$orderIds) return [];
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $out = [];
    $st = $db->prepare("SELECT id, is_closed, gm_deduct, scrap_no, capa_order_no FROM qa_abnormal_order WHERE id IN ($in)");
    $st->execute($orderIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = ['is_closed' => (int)$r['is_closed'], 'gm_deduct' => (int)$r['gm_deduct'],
                               'scrap_no' => (string)($r['scrap_no'] ?? ''), 'capa_order_no' => (string)($r['capa_order_no'] ?? ''),
                               'disp_ids' => [], 'gm_ids' => [], 'pending' => 0];
    }
    $st = $db->prepare("SELECT order_id, kind, opt_id FROM qa_abnormal_opt WHERE order_id IN ($in)");
    $st->execute($orderIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $r['kind'] === 'gm' ? 'gm_ids' : 'disp_ids';
        if (isset($out[(int)$r['order_id']])) $out[(int)$r['order_id']][$k][] = (int)$r['opt_id'];
    }
    $st = $db->prepare("SELECT abnormal_order_id, COUNT(*) c FROM qa_abnormal_order_flow
                        WHERE abnormal_order_id IN ($in) AND status<>'Returned' GROUP BY abnormal_order_id");
    $st->execute($orderIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($out[(int)$r['abnormal_order_id']])) $out[(int)$r['abnormal_order_id']]['pending'] = (int)$r['c'];

    $optMap = qab_option_map($db);
    $causeMap = qab_cause_map($db);
    $st = $db->prepare("SELECT order_id, cat_id FROM qa_abnormal_cause WHERE order_id IN ($in)");
    $st->execute($orderIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($out[(int)$r['order_id']])) $out[(int)$r['order_id']]['causes'][] = $causeMap[(int)$r['cat_id']]['path'] ?? '';
    }
    foreach ($out as $id => &$v) {
        $v['final'] = qab_final($db, $v, $optMap);
        $v['need_gm'] = qab_need_gm($db, $v, $optMap);
        if ($v['is_closed'])            $v['status'] = '已結案';
        elseif ($v['pending'] > 0)      $v['status'] = '等待單位回覆';
        elseif (!$v['disp_ids'] && !$v['gm_ids']) $v['status'] = '待決策';
        elseif ($v['need_gm'])          $v['status'] = '待總經理裁示';
        elseif ($v['gm_deduct'] || $v['final']['is_scrap']) $v['status'] = '扣款確認中';
        else                            $v['status'] = '可結案';
        $v['final_label'] = implode('、', $v['final']['names']);
        $v['cause_label'] = implode('；', array_filter($v['causes'] ?? []));
    }
    unset($v);
    return $out;
}

}
