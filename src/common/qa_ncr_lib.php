<?php
/**
 * qa_ncr_lib.php — 不合格品管制記錄表（2-QA-01-03）唯一實作
 * 建立：2026-09-18
 *
 * 【這張表是什麼】
 * 不是一張要人從頭打字的表單，而是一本**登錄簿**：把「所有不合格品事件」按時間列成一份可追溯的清單。
 * 程序書 2-QA-01 第 5 節把不合格品的來源列得很清楚（5.1 進料檢驗／5.2 製程·重工／
 * 5.3 委外加工檢驗／5.4 最終產品檢驗／5.5 客退），這些事件系統裡本來就有紀錄，
 * 所以本庫**把它們彙整出來自動填好**，人只要補「原因／責任單位／處理方式／報廢單號／結案」這幾格。
 *
 * 【四個來源，逐來源可開關（鐵律4：要開哪些來源存 DB，不寫死）】
 *   qa   品質異常處理單 qa_abnormal_order —— 程序書 6.1.2 指定由品管課開立的那一張
 *   car  異常矯正處理單 car_order        —— 走矯正與預防措施管理程序的那一張
 *   ir   客戶退貨     ir_track          —— 程序書 5.5／6.6.2 客退不合格品
 *   qc   QC 檢驗判定不良／特採 qc_check  —— 5.1~5.4 的檢驗當下（QC_check<>'ok'）
 *
 * 【為什麼補充欄位要另存一張表，不寫回來源單】
 * 來源單各有自己的流程與簽核（CAR 有四段簽核、品質異常有總經理裁決），
 * 在這本登錄簿上改一個字就回頭動它們的欄位，等於從側門繞過那些流程。
 * 所以補充內容存在 `qa_ncr_log`，以 (source, source_key) 對回來源列；
 * 來源本身有的欄位一律**即時讀來源**（單子後來結案了，這裡要跟著變成已結案）。
 */
if (!function_exists('ncr_ensure_schema')) {

define('NCR_PARAM_GROUP', 'QA_NCR');
define('NCR_ASDOC_MODULE', 'qa_ncr_log');   // AS 文件綁定（走 asdoc_lib，ai-rules/16 第一之三節）

/** 來源代碼 → 顯示名稱（唯一登記表；新增來源只改這裡與 ncr_rows() 的對應查詢） */
function ncr_sources(): array {
    return [
        'qa'  => '品質異常處理單',
        'car' => '異常矯正處理單',
        'ir'  => '客戶退貨',
        'qc'  => 'QC檢驗不良／特採',
    ];
}
/** 處理方式選項（程序書 6.2~6.6：特採／選別使用／重工／報廢／退貨換貨） */
function ncr_dispositions(): array {
    return ['特採', '選別使用', '重工', '報廢', '退貨/換貨', '其他'];
}

function ncr_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS qa_ncr_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source VARCHAR(10) NOT NULL COMMENT 'qa/car/ir/qc；manual=紙本補登、系統裡沒有來源單',
        source_key VARCHAR(40) NOT NULL COMMENT '來源列的主鍵（manual 時為自動產生的流水碼）',
        insp_date DATE NULL COMMENT '檢驗日期。有來源時即時讀來源，manual 才用這一欄',
        client_name VARCHAR(100) NULL,
        part_name VARCHAR(200) NULL COMMENT '工件名稱',
        drawing_no VARCHAR(100) NULL COMMENT '圖號/件號',
        qty DECIMAL(14,3) NULL,
        order_no VARCHAR(60) NULL COMMENT '異常單編號（manual 用；有來源時讀來源單號）',
        cause VARCHAR(500) NULL COMMENT '原因',
        resp_unit VARCHAR(100) NULL COMMENT '責任單位',
        disposition VARCHAR(50) NULL COMMENT '處理方式（ncr_dispositions()）',
        disposition_note VARCHAR(300) NULL COMMENT '處理方式補充；報廢時填報廢單號（程序書 6.5.1）',
        is_aero TINYINT(1) NOT NULL DEFAULT 0 COMMENT '航太類（程序書 6.5.3：要記錄於本表並貼紅色吊卡）',
        is_closed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '結案（人工勾；來源單本身已結案時畫面上一律顯示已結案）',
        closed_date DATE NULL,
        remark VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        updated_at DATETIME NULL, updated_by INT NULL, updated_by_name VARCHAR(50) NULL,
        UNIQUE KEY uk_ncr (source, source_key),
        KEY idx_ncr_date (insp_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='不合格品管制記錄表(2-QA-01-03) 補充欄位與紙本補登'");
}

/* ── 設定 ───────────────────────────────────────────── */
function ncr_param_get(PDO $db, string $key, $default) {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([NCR_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}
function ncr_param_save(PDO $db, string $key, $val, string $by = ''): void {
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    try {
        $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([NCR_PARAM_GROUP, $key]);
        $rid = $st->fetchColumn();
        if ($rid) $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $by, $rid]);
        else $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by) VALUES (?,?,?,?,?)")
                ->execute([NCR_PARAM_GROUP, $key, $json, '不合格品管制記錄表：'.$key, $by]);
    } catch (Throwable $e) {}
}
/** 要彙整哪些來源（預設全開；QC 檢驗量最大但 2026 只有 55 筆，不必預設關） */
function ncr_enabled_sources(PDO $db): array {
    $v = ncr_param_get($db, 'sources', array_keys(ncr_sources()));
    $ok = array_keys(ncr_sources());
    $v  = array_values(array_intersect(is_array($v) ? $v : $ok, $ok));
    return $v ?: $ok;
}

/* ── 來源彙整 ───────────────────────────────────────── */

/**
 * 某期間的不合格品事件（依檢驗日期新→舊）。
 * 來源欄位一律即時讀，只有「補充欄位」才取自 qa_ncr_log——
 * 存快照的話，來源單事後結案／改了處理方式，這本登錄簿會永遠停在存檔那天而且看不出來。
 *
 * @return array 每列：source/source_key/insp_date/client_name/part_name/drawing_no/qty/order_no
 *               /cause/resp_unit/disposition/is_closed/... ＋ src_* 表示「來源本身給的值」
 */
function ncr_rows(PDO $db, string $from, string $to, array $opt = []): array {
    $srcs = $opt['sources'] ?? ncr_enabled_sources($db);
    $rows = [];

    // ① 品質異常處理單
    if (in_array('qa', $srcs, true)) {
        try {
            // 2026-09-18 改版後這張單的欄位都在 qa_abnormal_lib 那一套（原因分類存 id、最終處置要看總經理裁示、
            // 報廢單號在結案時配發），所以狀態與處置一律由 qab_status_map() 解析，**不要在這裡再判一次**。
            require_once __DIR__ . '/qa_abnormal_lib.php';
            qab_ensure_schema($db);
            // deleted_at IS NULL：已刪除的異常單不可以再出現在這本登錄簿（2026-09-24 使用者回報，
            // 刪除的單一度連同尚未結案的舊測試資料一起被算了進來）。
            // NOT EXISTS(...)：同一批NG被拆分成好幾張子單時，子單才是真正承擔處置的那幾張，
            // 母單自己已無決策（qab_save_decision 拆分時會清空母單的處置），故母單不再列出——
            // 子單有列入，母單不需要再重複列一次（使用者 2026-09-24 交辦）。
            $st = $db->prepare("SELECT a.id, a.abnormal_order_no, a.occurrence_date, a.fill_date, a.sqty, a.ng_qty,
                                       a.abnormal_phenomenon, a.defect_detail, a.responsible_unit,
                                       a.is_closed, a.closed_at, a.bom_no, a.ir_no, a.capa_order_no,
                                       a.client_name, a.part_no,
                                       b.d_id, b.Client_Name, b.specification
                                FROM qa_abnormal_order a
                                LEFT JOIN bom b ON b.bom = a.bom_no
                                WHERE a.deleted_at IS NULL
                                  AND DATE(COALESCE(a.fill_date, a.occurrence_date)) BETWEEN ? AND ?
                                  AND NOT EXISTS (SELECT 1 FROM qa_abnormal_order c
                                                  WHERE c.parent_order_id = a.id AND c.deleted_at IS NULL)");
            $st->execute([$from, $to]);
            $qaRows = $st->fetchAll(PDO::FETCH_ASSOC);
            $qaStat = qab_status_map($db, array_column($qaRows, 'id'));
            foreach ($qaRows as $r) {
                $s = $qaStat[(int)$r['id']] ?? [];
                $extra = [];
                // 已結案時「結案」欄本身就講得清楚了，這裡不再重複印「狀態：已結案」（使用者 2026-09-24 回報）
                if (!empty($s['status']) && $s['status'] !== '已結案') $extra[] = '狀態：' . $s['status'];
                if (!empty($r['capa_order_no'])) $extra[] = '矯正單 ' . $r['capa_order_no'];
                if (!empty($s['gm_deduct'])) $extra[] = '需扣款';
                $rows[] = [
                    'source' => 'qa', 'source_key' => (string)$r['id'],
                    'src_date'   => substr((string)($r['fill_date'] ?: $r['occurrence_date']), 0, 10),
                    'src_client' => (string)($r['client_name'] ?: ($r['Client_Name'] ?? '')),
                    'src_part'   => (string)($r['part_no'] ?: ($r['d_id'] ?? '')),
                    'src_draw'   => (string)($r['specification'] ?? ''),
                    'src_qty'    => ($r['ng_qty'] !== null && $r['ng_qty'] !== '') ? $r['ng_qty'] : $r['sqty'],
                    'src_no'     => (string)$r['abnormal_order_no'],
                    // 原因：優先用勾選好的異常原因分類（那才是之後要做分析的欄位），沒勾才退回文字敘述
                    'src_cause'  => trim((string)($s['cause_label'] ?? '')) !== ''
                                    ? (string)$s['cause_label']
                                    : trim((string)($r['abnormal_phenomenon'] ?: $r['defect_detail'])),
                    'src_resp'   => (string)($r['responsible_unit'] ?? ''),
                    'src_disp'   => (string)($s['final_label'] ?? ''),
                    // 報廢單號獨立一格傳出去，讓畫面把它印在「處理方式」欄下方、加底色外框標出來
                    // （比照 qa_abnormal_list.php 的顯示方式），不要再混在來源徽章下的小字說明裡。
                    'src_scrap_no' => (string)($s['scrap_no'] ?? ''),
                    'src_closed' => (int)($r['is_closed'] ?? 0),
                    'src_closed_date' => substr((string)($r['closed_at'] ?? ''), 0, 10),
                    'src_link'   => '../QA/qa_abnormal_form.php',
                    'src_extra'  => implode('　', $extra),
                    // 這張單自己就管好了原因分類／責任單位／處置／結案，登錄簿一律唯讀（使用者 2026-09-21 指定）
                    'src_readonly' => 1,
                ];
            }
        } catch (Throwable $e) { error_log('ncr qa: ' . $e->getMessage()); }
    }

    // ② 異常矯正處理單（fill_date 為表單日期；只收已核准開立的，草稿不算不合格品事件）
    if (in_array('car', $srcs, true)) {
        try {
            // 2026-09-22：異常矯正單的處置方式改存 id（沿用異常單的 qa_option），舊資料還在 enum
            // 欄位裡；顯示文字一律走 car_disp_label()（唯一實作），不要在這裡自己判一次。
            // 順帶修掉既有問題：原本直接印 enum 代碼（'rework'），現在印中文。
            require_once __DIR__ . '/car_lib.php';
            car_ensure_cause_cols($db);
            // c.d_id 存的是 d_setting 主檔的整數 PK（不是料號文字，跟 ir_track/bom 的 d_id 意義不同！
            // 直接印會顯示成「5993」這種數字，要 JOIN d_setting 換成 D_Setting_Id 才是真正的料號）
            $st = $db->prepare("SELECT c.id, c.car_no, c.fill_date, c.found_date, c.qty, c.d_id, c.drawing_no,
                                       c.abnormal_desc, c.cause_detail, c.resp_display,
                                       c.disposition, c.disposition_other, c.disposition_opt_id,
                                       c.close_date, c.status, c.counterparty_type, c.customer_id, c.maker_id_no,
                                       cl.customer AS cname, m.maker_id AS mname, ds.D_Setting_Id AS part_text
                                FROM car_order c
                                LEFT JOIN customer_list cl ON cl.customer_id = c.customer_id
                                LEFT JOIN maker_list m ON m.maker_id_no = c.maker_id_no
                                LEFT JOIN d_setting ds ON ds.d_id = c.d_id
                                WHERE DATE(COALESCE(c.found_date, c.fill_date)) BETWEEN ? AND ?");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $who = $r['counterparty_type'] === 'maker' ? (string)($r['mname'] ?? '') : (string)($r['cname'] ?? '');
                $rows[] = [
                    'source' => 'car', 'source_key' => (string)$r['id'],
                    'src_date'   => substr((string)($r['found_date'] ?: $r['fill_date']), 0, 10),
                    'src_client' => $who,
                    'src_part'   => (string)($r['part_text'] ?? ''),
                    'src_draw'   => (string)($r['drawing_no'] ?? ''),
                    'src_qty'    => $r['qty'],
                    'src_no'     => (string)$r['car_no'],
                    'src_cause'  => trim((string)($r['abnormal_desc'] ?: $r['cause_detail'])),
                    'src_resp'   => (string)($r['resp_display'] ?? ''),
                    'src_disp'   => car_disp_label($db, $r),
                    'src_closed' => ($r['close_date'] || $r['status'] === 'closed') ? 1 : 0,
                    'src_closed_date' => substr((string)($r['close_date'] ?? ''), 0, 10),
                    'src_link'   => '../QA/correction_order.php',
                    'src_extra'  => '',
                ];
            }
        } catch (Throwable $e) { error_log('ncr car: ' . $e->getMessage()); }
    }

    // ③ 客戶退貨（程序書 5.5／6.6.2）
    if (in_array('ir', $srcs, true)) {
        try {
            $st = $db->prepare("SELECT r.IR_id, r.IR_no, r.IR_date, r.Client_name, r.d_id, r.Specification,
                                       r.Qty, r.IR_ps, r.IR_status, r.has_ncr, t.type_name
                                FROM ir_track r
                                LEFT JOIN ir_return_type t ON t.type_id = r.return_type_id
                                WHERE DATE(r.IR_date) BETWEEN ? AND ?");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'source' => 'ir', 'source_key' => (string)$r['IR_id'],
                    'src_date'   => substr((string)$r['IR_date'], 0, 10),
                    'src_client' => (string)($r['Client_name'] ?? ''),
                    'src_part'   => (string)($r['d_id'] ?? ''),
                    'src_draw'   => (string)($r['Specification'] ?? ''),
                    'src_qty'    => $r['Qty'],
                    'src_no'     => (string)$r['IR_no'],
                    'src_cause'  => trim((string)($r['type_name'] ? $r['type_name'] . '：' : '') . (string)($r['IR_ps'] ?? '')),
                    'src_resp'   => '',
                    'src_disp'   => '',
                    'src_closed' => ((string)($r['IR_status'] ?? '') === '結案') ? 1 : 0,
                    'src_closed_date' => '',
                    'src_link'   => '../Sales/IR_Track.php',
                    'src_extra'  => '',
                ];
            }
        } catch (Throwable $e) { error_log('ncr ir: ' . $e->getMessage()); }
    }

    // ④ QC 檢驗判定不良／特採（5.1~5.4 的檢驗當下）
    //    廠商從 bom_ing.maker_id_no 對 maker_list；客戶與料號從該製令的 bom
    if (in_array('qc', $srcs, true)) {
        try {
            $st = $db->prepare("SELECT q.qc_check_id, q.QC_check, q.QC_check_date, q.QC_QQ_sqty, q.QC_ng_sqty,
                                       q.QC_aod_sqty, q.QC_ps, q.QC_ps_aod,
                                       bi.bom, bi.bom_sn, bi.maker_id_no, bi.process_no,
                                       b.d_id, b.Client_Name, b.specification,
                                       m.maker_id AS mname, p.ProcessName AS process_name
                                FROM qc_check q
                                LEFT JOIN bom_ing bi ON bi.bom_ing_fid = q.bom_ing_fid_ref
                                LEFT JOIN bom b ON b.bom = bi.bom
                                LEFT JOIN maker_list m ON m.maker_id_no = bi.maker_id_no
                                LEFT JOIN process_no p ON p.ProcessNo = bi.process_no
                                WHERE q.QC_check <> 'ok' AND DATE(q.QC_check_date) BETWEEN ? AND ?");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // 數量：優先取該批實際記錄的不良／特採顆數（見記憶 vendor_eval_scoring：QC_ng_sqty 全庫皆 0）
                $q = (float)($r['QC_QQ_sqty'] ?: 0) ?: (float)($r['QC_ng_sqty'] ?: 0) ?: (float)($r['QC_aod_sqty'] ?: 0);
                $rows[] = [
                    'source' => 'qc', 'source_key' => (string)$r['qc_check_id'],
                    'src_date'   => substr((string)$r['QC_check_date'], 0, 10),
                    'src_client' => (string)($r['Client_Name'] ?? ''),
                    'src_part'   => (string)($r['d_id'] ?? ''),
                    'src_draw'   => (string)($r['specification'] ?? ''),
                    'src_qty'    => $q ?: null,
                    'src_no'     => (string)($r['bom'] ?? ''),   // QC 檢驗本身沒有異常單號，登錄製令號供追溯
                    'src_cause'  => trim((string)($r['QC_ps'] ?: $r['QC_ps_aod'])),
                    // 責任單位：這一站發包給誰就是誰（廠內加工沒有廠商，留白讓人填）
                    'src_resp'   => (string)($r['mname'] ?? ''),
                    'src_disp'   => ((string)$r['QC_check'] === 'QQ') ? '特採' : '',
                    'src_closed' => 0,
                    'src_closed_date' => '',
                    'src_link'   => '../QC/QC_check_list.php',
                    'src_extra'  => trim((string)($r['process_name'] ?? '')) . (($r['bom_sn'] ?? '') !== '' ? ' 第' . $r['bom_sn'] . '站' : ''),
                ];
            }
        } catch (Throwable $e) { error_log('ncr qc: ' . $e->getMessage()); }
    }

    // 補充欄位（人工填的那幾格）
    $sup = [];
    try {
        foreach ($db->query("SELECT * FROM qa_ncr_log") as $r) {
            $sup[$r['source'] . '|' . $r['source_key']] = $r;
        }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $r) {
        $k = $r['source'] . '|' . $r['source_key'];
        $s = $sup[$k] ?? null;
        unset($sup[$k]);
        $out[] = ncr_merge($r, $s);
    }
    // 紙本補登（manual）以及「來源已不在期間內、但補充列還在」的——後者不列出來，
    // 否則改一次期間就會冒出一堆對不上來源的孤兒列
    foreach ($sup as $k => $s) {
        if ((string)$s['source'] !== 'manual') continue;
        $d = substr((string)($s['insp_date'] ?? ''), 0, 10);
        if ($d === '' || $d < $from || $d > $to) continue;
        $out[] = ncr_merge(['source'=>'manual', 'source_key'=>(string)$s['source_key'],
            'src_date'=>$d, 'src_client'=>'', 'src_part'=>'', 'src_draw'=>'', 'src_qty'=>null,
            'src_no'=>'', 'src_cause'=>'', 'src_resp'=>'', 'src_disp'=>'', 'src_closed'=>0,
            'src_closed_date'=>'', 'src_link'=>'', 'src_extra'=>'', 'src_readonly'=>0], $s);
    }
    // 檢驗日期新→舊；沒有日期的排最後（不是排最前，否則沒日期的會霸佔第一頁）
    usort($out, function ($a, $b) {
        $da = (string)$a['insp_date']; $db_ = (string)$b['insp_date'];
        if (($da === '') !== ($db_ === '')) return $da === '' ? 1 : -1;
        if ($da !== $db_) return strcmp($db_, $da);
        return strcmp($a['source'] . $a['source_key'], $b['source'] . $b['source_key']);
    });
    return $out;
}

/**
 * 來源值與人工補充值合併。
 * 規則：人工有填就用人工的（人看過資料後的判斷優先），沒填就用來源的。
 * **結案例外**：來源單已經結案時一律顯示已結案——來源都結了，登錄簿還掛著未結案是錯的。
 */
function ncr_merge(array $r, $s): array {
    $pick = function ($manual, $src) {
        $m = is_string($manual) ? trim($manual) : $manual;
        return ($m === null || $m === '') ? $src : $m;
    };
    $closed = (int)$r['src_closed'] === 1 ? 1 : (int)($s['is_closed'] ?? 0);
    return [
        'source'      => $r['source'],
        'source_name' => ncr_sources()[$r['source']] ?? ($r['source'] === 'manual' ? '紙本補登' : $r['source']),
        'source_key'  => $r['source_key'],
        'has_sup'     => $s ? 1 : 0,
        'insp_date'   => $pick($s['insp_date'] ?? null, $r['src_date']),
        'client_name' => $pick($s['client_name'] ?? null, $r['src_client']),
        'part_name'   => $pick($s['part_name'] ?? null, $r['src_part']),
        'drawing_no'  => $pick($s['drawing_no'] ?? null, $r['src_draw']),
        'qty'         => ($s && $s['qty'] !== null && $s['qty'] !== '') ? $s['qty'] : $r['src_qty'],
        'order_no'    => $pick($s['order_no'] ?? null, $r['src_no']),
        'cause'       => $pick($s['cause'] ?? null, $r['src_cause']),
        'resp_unit'   => $pick($s['resp_unit'] ?? null, $r['src_resp']),
        'disposition' => $pick($s['disposition'] ?? null, $r['src_disp']),
        'disposition_note' => (string)($s['disposition_note'] ?? ''),
        'scrap_no'    => (string)($r['src_scrap_no'] ?? ''),
        'is_aero'     => (int)($s['is_aero'] ?? 0),
        'is_closed'   => $closed,
        'closed_date' => $pick($s['closed_date'] ?? null, $r['src_closed_date']),
        'remark'      => (string)($s['remark'] ?? ''),
        'src_extra'   => (string)$r['src_extra'],
        'src_link'    => (string)$r['src_link'],
        'src_readonly'=> (int)($r['src_readonly'] ?? 0),
        // 來源本身的值也回傳：畫面上要能看出「這一格是系統帶的還是人改過的」
        'src_cause'   => (string)$r['src_cause'],
        'src_resp'    => (string)$r['src_resp'],
        'src_disp'    => (string)$r['src_disp'],
        'src_closed'  => (int)$r['src_closed'],
    ];
}

/** 公司全名（列印大標題，唯一來源 customer_list.is_own_company=1，禁寫死） */
function ncr_company_name(PDO $db): string {
    try {
        $r = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) return trim((string)($r['customer_full'] ?: $r['customer']));
    } catch (Throwable $e) {}
    return '';
}

/* ── 圖章（ai-rules/18）：製表與主管審核兩格 ── */
function ncr_stamp_tpl_id(PDO $db): int { return (int)ncr_param_get($db, 'stamp_tpl_id', 0); }
function ncr_stamp_tpl(PDO $db): ?array {
    $id = ncr_stamp_tpl_id($db);
    if (!$id) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}
function ncr_stamp_tpl_options(PDO $db): array {
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name FROM stamp_template p
                           LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ── 權限（roles module='qa_ncr'）───────────────────────
 *   ncr_admin 管制記錄管理員：補填原因/責任單位/處理方式/結案、紙本補登、改設定
 *   ncr_view  檢閱：唯讀（含列印）
 *   品管相關角色（qc_manage_settings）視同管理員——這本登錄簿本來就是品管在維護的
 */
function ncr_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_uname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function ncr_perms(PDO $db, ?array $u): array {
    $none = ['isAdmin'=>false, 'canAdmin'=>false, 'canView'=>false, 'uid'=>0, 'name'=>''];
    if (!$u) return $none;
    $uid = (int)$u['id'];
    if ((int)($u['state'] ?? 0) === 0 || (int)($u['user_status'] ?? 0) === 90) return $none;   // 離職/特殊帳號 fail-closed
    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;
    $has = function (array $codes) use ($db, $uid) {
        $in = implode(',', array_fill(0, count($codes), '?'));
        try {
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                WHERE ur.user_id=? AND r.role_code IN ($in) LIMIT 1");
            $st->execute(array_merge([$uid], $codes));
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    };
    // 品管的「管理檢驗設定」本來就是這本登錄簿的維護者，不要再叫他們多指派一個角色
    $canAdmin = $isAdmin || $has(['ncr_admin', 'qc_manage_settings']);
    $canView  = $canAdmin || $has(['ncr_view', 'qc_view_readonly', 'qc_fill_inspection', 'qc_edit_history']);
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canView'=>$canView,
            'uid'=>$uid, 'name'=>(string)($u['user_cname'] ?: $u['user_uname'])];
}

}
