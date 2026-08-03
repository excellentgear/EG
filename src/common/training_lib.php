<?php
/**
 * 教育訓練管理 —— 共用函式庫
 * 對應 KPI 2-GM-04-01 第19項「人員教育訓練達成率」= 當月實際完成場次 / 當月計畫場次
 *
 * 彈性設計（管理員後端維護各部門/年度訓練計畫）：
 *   - training_session：每列＝一場訓練課程（計畫+執行合一）
 *       year, plan_month  計畫歸屬年月（KPI 依此歸月）
 *       dept_id           指定部門(department.id；NULL=全公司/跨部門)
 *       status            planned=計畫中 / scheduled=已排定(確認開課,可印簽到表) / done=已完成 / cancelled=取消
 *   - KPI 達成率(場次口徑)：den=當月計畫場次(排除取消；已排定仍算分母)；num=當月已完成(done)場次
 *     （取消是否計入分母可由參數 include_cancelled 控制）
 *   - 對象部門為**複選**（training_session_dept），每個被列入的部門都認定有做這場訓練；
 *     達標統計以「顯示單位」為準（部門合併群組 training_dept_group，未分組部門自成一單位）。
 */
include_once __DIR__ . '/attach_lib.php';     // 附件根路徑（鐵律5：只存檔名、路徑即時組）
include_once __DIR__ . '/org_role_lib.php';   // 人事部門/最高核准人員等全站組織綁定（禁止各頁寫死）
include_once __DIR__ . '/approval_lib.php';   // 簽核紀錄 approval_record

/* ============================================================
 * Schema
 * ============================================================ */
function training_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS training_session (
        session_id INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL COMMENT '計畫年度',
        plan_month TINYINT NOT NULL COMMENT '計畫月份 1-12(KPI歸屬)',
        dept_id INT NULL COMMENT '指定部門 department.id；NULL=全公司/跨部門',
        course_name VARCHAR(100) NOT NULL COMMENT '課程/訓練名稱',
        trainer VARCHAR(50) NULL COMMENT '講師/主辦',
        hours DECIMAL(5,1) NULL COMMENT '時數',
        target_headcount INT NULL COMMENT '應到人數',
        actual_headcount INT NULL COMMENT '實到人數',
        status VARCHAR(10) NOT NULL DEFAULT 'planned' COMMENT 'planned/done/cancelled',
        done_date DATE NULL COMMENT '實際完成日',
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        KEY idx_ym (year, plan_month),
        KEY idx_dept (dept_id),
        KEY idx_status (status)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練場次(計畫+執行)'");

    // 實際開課資訊 + 講師人員id（既有 done_date=實際開課日；僅加欄不破壞）
    foreach ([
        "ALTER TABLE training_session ADD COLUMN start_time VARCHAR(5) NULL COMMENT '開始時間 HH:MM'",
        "ALTER TABLE training_session ADD COLUMN end_time VARCHAR(5) NULL COMMENT '結束時間 HH:MM'",
        "ALTER TABLE training_session ADD COLUMN location VARCHAR(100) NULL COMMENT '上課地點'",
        "ALTER TABLE training_session ADD COLUMN trainer_id INT NULL COMMENT '講師 user.id(外部講師留空,用trainer文字)'",
        "ALTER TABLE training_session ADD COLUMN train_type VARCHAR(10) NOT NULL DEFAULT 'internal' COMMENT 'internal=內訓 external=外訓'",
        "ALTER TABLE training_session ADD COLUMN org_unit VARCHAR(100) NULL COMMENT '外訓開課/主辦單位'",
        "ALTER TABLE training_session ADD COLUMN actual_hours DECIMAL(5,1) NULL COMMENT '實際上課時數(可與計畫 hours 不同；多天=各天合計)'",
        "ALTER TABLE training_session ADD COLUMN plan_days INT NULL COMMENT '計畫上課天數(多天課程；NULL/1=單天)'",
        "ALTER TABLE training_session ADD COLUMN shift_type_id INT NULL COMMENT '套用的固定班別 shift_type.shift_type_id(上下班時間僅參考、休息分鐘用來扣時數)'",
        "ALTER TABLE training_session ADD COLUMN outline TEXT NULL COMMENT '課程大綱(確認開課時填,列印簽到表會帶出)'",
        "ALTER TABLE training_session ADD COLUMN eval_method VARCHAR(20) NULL COMMENT '評鑑方式 exam=試券/report=心得/practice=實作/oral=口試/notice=宣導(免評鑑)'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // 多天課程：每天一列上課日期與時段（單天課程也存 1 列）
    // 主表 done_date/start_time/end_time 保留＝第 1 天的值（KPI、清單、既有程式相容）
    $db->exec("CREATE TABLE IF NOT EXISTS training_session_day (
        day_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        day_no INT NOT NULL DEFAULT 1 COMMENT '第幾天(1起)',
        day_date DATE NOT NULL COMMENT '上課日期',
        start_time VARCHAR(5) NULL COMMENT '開始 HH:MM',
        end_time VARCHAR(5) NULL COMMENT '結束 HH:MM',
        hours DECIMAL(5,1) NULL COMMENT '當日時數(可手改,扣休息)',
        KEY idx_session (session_id),
        KEY idx_date (day_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練場次上課日期(多天課程)'");

    foreach ([
        "ALTER TABLE training_session_day ADD COLUMN break_minutes INT NOT NULL DEFAULT 0 COMMENT '當日休息分鐘(自班別帶入,可手改;時數已扣除)'",
        "ALTER TABLE training_session_day ADD COLUMN evenement_id INT NULL COMMENT '同步到行事曆的 evenement.id(一天一筆)'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // 上課地點主檔（可設定/儲存後選擇；停用不刪，舊紀錄仍存文字）
    $db->exec("CREATE TABLE IF NOT EXISTS training_location (
        loc_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        UNIQUE KEY uq_name (name)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練上課地點主檔'");

    // 參加人員名單（應參加＋實到＋簽名）
    $db->exec("CREATE TABLE IF NOT EXISTS training_attendee (
        att_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(50) NULL,
        dept_name VARCHAR(50) NULL,
        attended TINYINT(1) NOT NULL DEFAULT 0 COMMENT '實到',
        signed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '已簽名',
        signed_at DATETIME NULL,
        sign_method VARCHAR(10) NULL COMMENT 'online=線上密碼 paper=紙本掃描',
        note VARCHAR(100) NULL,
        UNIQUE KEY uq_sa (session_id, user_id),
        KEY idx_session (session_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練參加人員名單'");

    // 參加人員：一律綁 user.id（user_name 等只是當下的冗餘快照，供列印/歷史保存；
    // 日後「某人受過哪些訓練」一律用 user_id 查，故補一支單欄索引）
    foreach ([
        "ALTER TABLE training_attendee ADD COLUMN position_name VARCHAR(50) NULL COMMENT '職稱(冗餘保存,列印簽到表用)'",
        "ALTER TABLE training_attendee ADD COLUMN eval_result VARCHAR(10) NULL COMMENT '評鑑結果 pass=合格 fail=不合格 exempt=免評鑑(宣導)；NULL=未評'",
        "ALTER TABLE training_attendee ADD COLUMN eval_score DECIMAL(5,1) NULL COMMENT '評分(選填 0~100)'",
        "ALTER TABLE training_attendee ADD COLUMN eval_note VARCHAR(100) NULL COMMENT '評鑑備註(不合格原因等)'",
        "ALTER TABLE training_attendee ADD KEY idx_user (user_id)",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // 場次附件（簽到表掃描、教材、試卷…）：DB 只存檔名，完整路徑一律讀取當下組出（鐵律5／ai-rules/07）
    $db->exec("CREATE TABLE IF NOT EXISTS training_attachment (
        att_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL DEFAULT 0 COMMENT '0=新增中的暫存(status=temp)',
        cat VARCHAR(16) NOT NULL DEFAULT 'other' COMMENT 'sign=簽到表 material=教材 exam=試卷/測驗 photo=照片 other=其他',
        file_name VARCHAR(150) NOT NULL COMMENT '實際存檔檔名(只存檔名,不存路徑)',
        original_name VARCHAR(200) NULL,
        file_size INT NULL,
        user_id INT NULL COMMENT '上傳者(temp 列以此判定擁有者)',
        user_name VARCHAR(50) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT 'temp=未存檔暫存 active=正式',
        expire_at DATETIME NULL COMMENT 'temp 到期時間(懶惰清除)',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id),
        KEY idx_status (status)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練場次附件(簽到表/教材/試卷)'");

    // 類別可複選（逗號分隔，如 'sign,exam'）→ 原 VARCHAR(16) 不夠長
    try { $db->exec("ALTER TABLE training_attachment MODIFY COLUMN cat VARCHAR(80) NOT NULL DEFAULT 'other'
                     COMMENT '類別可複選,逗號分隔 sign/material/exam/photo/other'"); } catch (Throwable $e) {}

    // 對象部門（複選）：一場訓練可同時屬於多個部門，每個部門都認定「有做這場教育訓練」。
    // training_session.dept_id 保留＝主部門(第一個)，供既有清單/KPI 相容；判定達標一律用本表。
    $db->exec("CREATE TABLE IF NOT EXISTS training_session_dept (
        session_id INT NOT NULL,
        dept_id INT NOT NULL,
        PRIMARY KEY (session_id, dept_id),
        KEY idx_dept (dept_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練場次對象部門(複選)'");
    // 舊資料一次性回填（用旗標擋住，否則使用者事後把部門拿掉會被再塞回來）
    try {
        $done = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='training_dept_backfilled'")->fetchColumn();
        if (!$done) {
            $db->exec("INSERT IGNORE INTO training_session_dept (session_id, dept_id)
                       SELECT session_id, dept_id FROM training_session WHERE dept_id IS NOT NULL");
            $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('training_dept_backfilled','1')
                          ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
        }
    } catch (Throwable $e) {}

    // 部門合併顯示群組（子部門併入母部門一起算達標）；一個部門只能屬於一個群組(PK 保證不重複計算)
    $db->exec("CREATE TABLE IF NOT EXISTS training_dept_group (
        group_id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(50) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練達標統計的部門合併群組'");
    $db->exec("CREATE TABLE IF NOT EXISTS training_dept_group_member (
        dept_id INT NOT NULL PRIMARY KEY,
        group_id INT NOT NULL,
        KEY idx_group (group_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='部門合併群組成員(一個部門只能屬一個群組)'");

    // 訓練次數目標：unit_key = 'ALL'(全公司統一預設) / 'D<dept_id>' / 'G<group_id>'
    $db->exec("CREATE TABLE IF NOT EXISTS training_target (
        target_id INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        unit_key VARCHAR(20) NOT NULL COMMENT 'ALL=全公司統一 / D部門id / G群組id',
        internal_period VARCHAR(5) NOT NULL DEFAULT 'year' COMMENT 'month=每月 year=每年',
        internal_times INT NOT NULL DEFAULT 0 COMMENT '內訓次數目標',
        external_period VARCHAR(5) NOT NULL DEFAULT 'year',
        external_times INT NOT NULL DEFAULT 0 COMMENT '外訓次數目標',
        updated_at DATETIME NULL,
        updated_by VARCHAR(50) NULL,
        UNIQUE KEY uq_year_unit (year, unit_key)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練次數目標(內訓/外訓,每月或每年)'");

    foreach ([['training_view','訓練檢閱'],['training_edit','訓練登錄'],['training_admin','訓練管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='training' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'training')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ============================================================
 * 模組設定（system_settings）：預設班別、行事曆類別綁定
 *   一律存「id」不存名稱——類別/班別日後改名，綁定仍然有效（使用者明確要求）。
 * ============================================================ */
const TRAINING_SETTING_KEYS = ['training_default_shift_id', 'training_cat_internal', 'training_cat_external',
    // 各分頁/報表綁定的 AS 文件（存 as_document.id，列印時才解出 doc_no；未設定＝該表不印文件編號）
    'training_as_doc_plan', 'training_as_doc_result', 'training_as_doc_target',
    'training_need_approval'];   // 1=訓練計劃表需要送審（審核→核准）；0=不送審，列印直接顯示簽章
/* 休息時段（HH:MM 字串，不是 id）：上課時間與此時段重疊幾分鐘就扣幾分鐘。
   兩欄都留空＝完全不扣休息。預設 12:00~13:00（＝日班的午休）。 */
const TRAINING_SETTING_STR_KEYS = ['training_break_start', 'training_break_end',
    'training_exclude_depts',    // 不列入教育訓練達標統計的部門（csv dept_id）
    'training_plan_sign_date'];  // 免送審時計劃表的簽章日期（YYYY-MM-DD；留空＝自動取該年度最後異動日）
const TRAINING_BREAK_DEFAULT = ['training_break_start'=>'12:00', 'training_break_end'=>'13:00'];

function training_settings(PDO $db): array {
    $out = ['training_default_shift_id'=>null, 'training_cat_internal'=>null, 'training_cat_external'=>null,
            'training_as_doc_plan'=>null, 'training_as_doc_result'=>null, 'training_as_doc_target'=>null,
            'training_need_approval'=>null, 'training_exclude_depts'=>'', 'training_plan_sign_date'=>''];
    $out += TRAINING_BREAK_DEFAULT;      // 沒設定過才用預設；設定成空字串＝管理員刻意關閉，不可再被預設蓋回去
    try {
        $keys = array_merge(TRAINING_SETTING_KEYS, TRAINING_SETTING_STR_KEYS);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute($keys);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (in_array($r['setting_key'], TRAINING_SETTING_STR_KEYS, true)) {
                $out[$r['setting_key']] = (string)($r['setting_value'] ?? '');
            } else {
                $out[$r['setting_key']] = ($r['setting_value'] === '' || $r['setting_value'] === null) ? null : (int)$r['setting_value'];
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

function training_setting_save(PDO $db, string $key, $val, int $uid, string $uname): void {
    if (!in_array($key, TRAINING_SETTING_KEYS, true) && !in_array($key, TRAINING_SETTING_STR_KEYS, true)) return;
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                  VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                      updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)")
       ->execute([$key, $val === null ? '' : (string)$val, $uid, $uname]);
}

/** HH:MM → 分鐘；不合法回 null */
function training_min(?string $t): ?int {
    $t = trim((string)$t);
    if ($t === '' || !preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $t, $m)) return null;
    $h = (int)$m[1]; $i = (int)$m[2];
    if ($h > 23 || $i > 59) return null;
    return $h * 60 + $i;
}

/**
 * 當日應扣的休息分鐘 ＝「上課時間」與「休息時段」的重疊分鐘數。
 * 使用者明確要求：休息時間不給手動改，由系統依實際上課時間自動算——
 * 例如 11:00~12:00 的課根本沒跨到午休，就不該被扣掉 60 分鐘（舊版會扣，導致時數算錯甚至變負數）。
 */
function training_break_minutes(PDO $db, ?string $start, ?string $end, ?array $set = null): int {
    $set = $set ?? training_settings($db);
    $bs = training_min($set['training_break_start'] ?? '');
    $be = training_min($set['training_break_end'] ?? '');
    $s  = training_min($start);
    $e  = training_min($end);
    if ($bs === null || $be === null || $be <= $bs) return 0;   // 未設定休息時段＝不扣
    if ($s === null || $e === null || $e <= $s) return 0;
    $ov = min($e, $be) - max($s, $bs);
    return $ov > 0 ? $ov : 0;
}

/** 行事曆類別 id：優先用設定綁定的 id；沒設定才以名稱回退（找不到回 null＝不寫行事曆） */
function training_category_id(PDO $db, string $trainType): ?int {
    $set = training_settings($db);
    $key = $trainType === 'external' ? 'training_cat_external' : 'training_cat_internal';
    if ($set[$key]) {
        $st = $db->prepare("SELECT id FROM event_category WHERE id=?");
        $st->execute([$set[$key]]);
        $v = $st->fetchColumn();
        if ($v) return (int)$v;
    }
    $fallback = $trainType === 'external' ? '課程(外訓)' : '課程(內訓)';
    try {
        $st = $db->prepare("SELECT id FROM event_category WHERE category_name=? LIMIT 1");
        $st->execute([$fallback]);
        $v = $st->fetchColumn();
        return $v ? (int)$v : null;
    } catch (Throwable $e) { return null; }
}

/** 固定班別（與輪值排班共用 shift_type：上下班時間僅供帶入參考，break_minutes 用於扣時數） */
function training_shifts(PDO $db): array {
    try {
        return $db->query("SELECT shift_type_id, shift_name, shift_code,
                                  LEFT(start_time,5) AS start_time, LEFT(end_time,5) AS end_time,
                                  break_minutes, is_overnight
                           FROM shift_type WHERE is_active=1 ORDER BY sort_order, shift_type_id")
                  ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================================================
 * 附件（鐵律5／ai-rules/07）：DB 只存檔名，完整路徑一律在讀取當下用「目前設定值」現場組出。
 *   換 NAS 磁碟或搬資料夾時只要改設定值，舊附件立刻讀得到，不必動 DB。
 * ============================================================ */
const TRAINING_ATT_CATS = ['sign'=>'簽到表', 'material'=>'教材/講義', 'exam'=>'試卷/測驗', 'photo'=>'上課照片', 'other'=>'其他'];
/* 評鑑方式（確認開課時選定）；notice=宣導＝免評鑑，選了它參加人員一律記 exempt */
const TRAINING_EVAL_METHODS = ['exam'=>'試券', 'report'=>'心得', 'practice'=>'實作', 'oral'=>'口試', 'notice'=>'宣導（免評鑑）'];

/** 附件目錄（寫檔／讀檔皆用這支；預設＝全站附件根資料夾＼教育訓練＼，見 attach_lib.php） */
function training_attach_dir(PDO $db): string {
    return eg_attach_dir($db, 'training_nas_dir', '教育訓練');
}
/** 相容舊呼叫：回傳 [實體目錄, URL前綴]（附件一律走 API download_attach，URL 前綴已不使用） */
function training_attach_dirs(PDO $db): array {
    return [training_attach_dir($db), ''];
}

/** 某場次的正式附件（只回 active） */
function training_attachments(PDO $db, int $sid): array {
    try {
        $st = $db->prepare("SELECT att_id, session_id, cat, file_name, original_name, file_size, user_name, created_at
                            FROM training_attachment WHERE session_id=? AND status='active'
                            ORDER BY cat, att_id");
        $st->execute([$sid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 懶惰清除：刪掉過期的暫存附件（實體檔＋DB 列），由 list 動作順路呼叫 */
function training_purge_temp_attachments(PDO $db): void {
    try {
        $rows = $db->query("SELECT att_id, file_name FROM training_attachment
                            WHERE status='temp' AND expire_at IS NOT NULL AND expire_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        $nas = training_attach_dir($db);
        foreach ($rows as $r) { $fp = $nas . $r['file_name']; if (is_file($fp)) @unlink($fp); }
        $in = implode(',', array_map(fn($r) => (int)$r['att_id'], $rows));
        $db->exec("DELETE FROM training_attachment WHERE att_id IN ({$in})");
    } catch (Throwable $e) {}
}

/* ============================================================
 * 對象部門（複選）／部門合併群組／訓練次數目標／達標統計
 * ============================================================ */
/** 某些場次的對象部門 map：session_id => [dept_id,...] */
function training_session_depts(PDO $db, array $sids): array {
    $sids = array_values(array_filter(array_map('intval', $sids)));
    if (!$sids) return [];
    $in = implode(',', $sids);
    $map = [];
    try {
        foreach ($db->query("SELECT session_id, dept_id FROM training_session_dept WHERE session_id IN ({$in})")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['session_id']][] = (int)$r['dept_id'];
    } catch (Throwable $e) {}
    return $map;
}

/** 整批覆寫某場次的對象部門（主表 dept_id 同步成第一個，維持既有相容） */
function training_save_session_depts(PDO $db, int $sid, array $deptIds): void {
    $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds))));
    $db->prepare("DELETE FROM training_session_dept WHERE session_id=?")->execute([$sid]);
    if ($deptIds) {
        $ins = $db->prepare("INSERT IGNORE INTO training_session_dept (session_id, dept_id) VALUES (?,?)");
        foreach ($deptIds as $d) $ins->execute([$sid, $d]);
    }
    $db->prepare("UPDATE training_session SET dept_id=? WHERE session_id=?")
       ->execute([$deptIds ? $deptIds[0] : null, $sid]);
}

/** 部門合併群組（含成員） */
function training_dept_groups(PDO $db): array {
    try {
        $gs = $db->query("SELECT group_id, group_name, sort_order FROM training_dept_group
                          WHERE is_active=1 ORDER BY sort_order, group_id")->fetchAll(PDO::FETCH_ASSOC);
        $mem = [];
        foreach ($db->query("SELECT group_id, dept_id FROM training_dept_group_member")->fetchAll(PDO::FETCH_ASSOC) as $m)
            $mem[(int)$m['group_id']][] = (int)$m['dept_id'];
        foreach ($gs as &$g) $g['dept_ids'] = $mem[(int)$g['group_id']] ?? [];
        return $gs;
    } catch (Throwable $e) { return []; }
}

/**
 * 達標統計的「顯示單位」：合併群組各算一個單位，沒被歸入群組的部門各自一個單位。
 * 回傳 [['key'=>'G1'|'D5', 'name'=>..., 'dept_ids'=>[...]], ...]
 */
function training_units(PDO $db): array {
    $units = [];
    $inGroup = [];
    $ex = training_excluded_depts($db);          // 不列入教育訓練的部門
    foreach (training_dept_groups($db) as $g) {
        $ids = array_values(array_diff($g['dept_ids'], $ex));
        foreach ($g['dept_ids'] as $d) $inGroup[$d] = true;
        if (!$ids) continue;                      // 整個群組都被排除就不顯示
        $units[] = ['key'=>'G'.$g['group_id'], 'name'=>$g['group_name'], 'dept_ids'=>$ids, 'is_group'=>1];
    }
    try {
        foreach ($db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $id = (int)$d['id'];
            if (isset($inGroup[$id]) || in_array($id, $ex, true)) continue;
            $units[] = ['key'=>'D'.$id, 'name'=>$d['name'], 'dept_ids'=>[$id], 'is_group'=>0];
        }
    } catch (Throwable $e) {}
    return $units;
}

/** 不列入教育訓練達標統計的部門 id 陣列 */
function training_excluded_depts(PDO $db): array {
    $s = (string)(training_settings($db)['training_exclude_depts'] ?? '');
    return array_values(array_filter(array_map('intval', explode(',', $s))));
}

/** 某年度計畫的「最後異動日」（免送審時當簽章日期用）；查無回 '' */
function training_plan_last_modified(PDO $db, int $year): string {
    try {
        $st = $db->prepare("SELECT GREATEST(
                                COALESCE(MAX(s.created_at),'1970-01-01'),
                                COALESCE((SELECT MAX(a.created_at) FROM training_attachment a
                                          JOIN training_session s2 ON s2.session_id=a.session_id WHERE s2.year=?),'1970-01-01')
                            ) FROM training_session s WHERE s.year=?");
        $st->execute([$year, $year]);
        $v = (string)$st->fetchColumn();
        return ($v && substr($v,0,4) !== '1970') ? substr($v, 0, 10) : '';
    } catch (Throwable $e) { return ''; }
}

/** 綁定的 AS 文件編號（$which = plan|result|target）；未綁定或查無回 '' */
function training_as_doc_no(PDO $db, string $which): string {
    $id = (int)(training_settings($db)['training_as_doc_'.$which] ?? 0);
    if (!$id) return '';
    try {
        $st = $db->prepare("SELECT doc_no FROM as_document WHERE id=? AND COALESCE(is_deleted,0)=0");
        $st->execute([$id]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}

/* ============================================================
 * 年度訓練計劃表送審（module='training_plan'、entity_id=年度）
 *   審核＝人事表單審核者（未指定則自動取人事部門主管）；核准＝最高核准人員；人事章＝人事簽章人員。
 *   通知規則見 ai-rules/17-審核通知標準.md（通知要看得到內容、有核准/退回、退回填原因）。
 * ============================================================ */
function training_plan_signers(PDO $db): array {
    $hrDept  = eg_org_dept($db, 'hr_dept');
    $review  = eg_org_user($db, 'hr_reviewer') ?: eg_org_dept_manager($db, $hrDept);
    return [
        'reviewer'  => $review ? ['id'=>(int)$review['id'], 'name'=>$review['user_cname']] : null,
        'approver'  => (function($u){ return $u ? ['id'=>(int)$u['id'], 'name'=>$u['user_cname']] : null; })(eg_org_user($db, 'top_approver')),
        'hr_signer' => (function($u){ return $u ? ['id'=>(int)$u['id'], 'name'=>$u['user_cname']] : null; })(eg_org_user($db, 'hr_signer')),
        'hr_dept_id'=> $hrDept,
    ];
}

/** 某年度訓練計劃表的簽核現況（最新一筆 review / approve） */
function training_plan_approval(PDO $db, int $year): array {
    $rev = eg_approval_latest($db, 'training_plan', $year, 'review');
    $app = eg_approval_latest($db, 'training_plan', $year, 'approve');
    $status = 'none';
    if ($rev) $status = $rev['status'] === 'pending' ? 'review_pending'
                      : ($rev['status'] === 'rejected' ? 'rejected' : 'reviewed');
    if ($app) $status = $app['status'] === 'pending' ? 'approve_pending'
                      : ($app['status'] === 'rejected' ? 'rejected' : 'approved');
    return ['status'=>$status, 'review'=>$rev, 'approve'=>$app];
}

/** 送審/決行通知（依 ai-rules/17：通知帶內容摘要，點進去是可核准/退回的檢視頁） */
function training_plan_notify(PDO $db, int $year, string $stage, int $toUid, string $title, string $content, int $fromUid): int {
    if (!$toUid) return 0;
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='TRAINING_PLAN_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$year]);
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '教育訓練計畫簽核', 1, 'TRAINING_PLAN_APPROVAL', ?)")
           ->execute([$title, $content, $fromUid, $year]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
           ->execute([$eid, $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 結束某年度計畫的待簽核通知 */
function training_plan_close_notice(PDO $db, int $year): void {
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='TRAINING_PLAN_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$year]);
    } catch (Throwable $e) {}
}

/** 結果通知（核准/退回都要回報送審者，退回一定帶原因） */
function training_plan_notify_result(PDO $db, int $year, int $toUid, string $title, string $content, int $fromUid): void {
    if (!$toUid) return;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '教育訓練計畫簽核', 1, 'TRAINING_PLAN_RESULT', ?)")
           ->execute([$title, $content, $fromUid, $year]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
           ->execute([$eid, $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

/** 某年度的目標設定（unit_key => 設定列）；'ALL' 為未個別設定時的預設 */
function training_targets(PDO $db, int $year): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT * FROM training_target WHERE year=?");
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['unit_key']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 達標統計：每個顯示單位 × 每個月 × 內訓/外訓 的完成數、排定數、目標與達標與否。
 * 計入規則：場次的對象部門屬於該單位就計入；**未指定部門(全公司課程)計入所有單位**。
 * 月份歸屬：已完成用實際開課日、其餘用計畫月份（看得出「哪個月已有計畫」）。
 */
function training_target_stats(PDO $db, int $year): array {
    $units   = training_units($db);
    $targets = training_targets($db, $year);
    $def     = $targets['ALL'] ?? ['internal_period'=>'year','internal_times'=>0,'external_period'=>'year','external_times'=>0];

    $rows = [];
    try {
        $st = $db->prepare("SELECT session_id, plan_month, course_name, train_type, status, done_date, dept_id
                            FROM training_session WHERE year=? AND status<>'cancelled'");
        $st->execute([$year]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $deptMap = training_session_depts($db, array_column($rows, 'session_id'));

    $out = [];
    foreach ($units as $u) {
        $mine = ['internal'=>[], 'external'=>[]];      // type => month => ['done'=>n,'plan'=>n,'items'=>[]]
        for ($m = 1; $m <= 12; $m++) { $mine['internal'][$m] = ['done'=>0,'plan'=>0,'items'=>[]];
                                       $mine['external'][$m] = ['done'=>0,'plan'=>0,'items'=>[]]; }
        foreach ($rows as $r) {
            $sid  = (int)$r['session_id'];
            $ds   = $deptMap[$sid] ?? ($r['dept_id'] !== null ? [(int)$r['dept_id']] : []);
            $hit  = !$ds;                                       // 沒指定部門＝全公司課程，每個單位都算
            if (!$hit) foreach ($ds as $d) if (in_array($d, $u['dept_ids'], true)) { $hit = true; break; }
            if (!$hit) continue;
            $type = $r['train_type'] === 'external' ? 'external' : 'internal';
            $mon  = (int)$r['plan_month'];
            if ($r['status'] === 'done' && $r['done_date']) $mon = (int)substr($r['done_date'], 5, 2);
            if ($mon < 1 || $mon > 12) $mon = (int)$r['plan_month'];
            if ($r['status'] === 'done') $mine[$type][$mon]['done']++; else $mine[$type][$mon]['plan']++;
            $mine[$type][$mon]['items'][] = ['session_id'=>$sid, 'course_name'=>$r['course_name'], 'status'=>$r['status'],
                                             'company_wide'=>$ds ? 0 : 1];
        }
        $t = $targets[$u['key']] ?? $def;
        $sum = ['internal'=>0, 'external'=>0];
        foreach (['internal','external'] as $ty) for ($m = 1; $m <= 12; $m++) $sum[$ty] += $mine[$ty][$m]['done'];
        $out[] = ['unit'=>$u, 'months'=>$mine, 'year_done'=>$sum,
                  'target'=>['internal_period'=>$t['internal_period'], 'internal_times'=>(int)$t['internal_times'],
                             'external_period'=>$t['external_period'], 'external_times'=>(int)$t['external_times'],
                             'is_default'=>isset($targets[$u['key']]) ? 0 : 1]];
    }
    return $out;
}

/* ============================================================
 * 某位員工的受訓紀錄（供其他頁面查詢用，例如員工資料/履歷/稽核佐證）
 *   一律以 user.id 綁定（training_attendee.user_id），不要用姓名比對。
 * ============================================================ */
function training_user_history(PDO $db, int $userId, ?int $year = null): array {
    try {
        $sql = "SELECT s.session_id, s.year, s.plan_month, s.course_name, s.train_type, s.trainer, s.org_unit,
                       s.status, s.done_date, s.actual_hours, s.hours, s.location, s.eval_method,
                       a.attended, a.signed, a.eval_result, a.eval_score, a.eval_note
                FROM training_attendee a
                JOIN training_session s ON s.session_id = a.session_id
                WHERE a.user_id = ?" . ($year ? " AND s.year = ?" : "") . "
                ORDER BY s.done_date DESC, s.session_id DESC";
        $st = $db->prepare($sql);
        $st->execute($year ? [$userId, $year] : [$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================================================
 * 行事曆同步（evenement）：一個上課日一筆事件
 *   寫法比照 src/store/_events_setting.php 與 leave_lib.php 的行事曆段落。
 *   同步時機：確認實行/登錄完成(save_execution) → 建立或更新；
 *             退回計畫中/取消/刪除場次 → 移除。失敗一律不擋主流程。
 * ============================================================ */
function training_event_set_targets(PDO $db, int $evId): void {
    try {
        $db->prepare("DELETE FROM evenement_target WHERE event_id=?")->execute([$evId]);
        $db->prepare("DELETE FROM evenement_recipient_cache WHERE event_id=?")->execute([$evId]);
        $db->prepare("INSERT INTO evenement_target (event_id, target_type, created_at) VALUES (?, 'all', NOW())")
           ->execute([$evId]);   // 教育訓練屬公司事務 → 全體可見
        $ids = $db->query("SELECT id FROM user WHERE state NOT IN (0, 90) AND state IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $cache = $db->prepare("INSERT INTO evenement_recipient_cache (event_id, user_id, created_at) VALUES (?, ?, NOW())");
        foreach ($ids as $u) $cache->execute([$evId, (int)$u]);
    } catch (Throwable $e) {}
}

/** 移除某場次已同步的行事曆事件（只刪 training_session_day.evenement_id 指到的那些，不條件反查） */
function training_event_remove(PDO $db, int $sid): void {
    try {
        $st = $db->prepare("SELECT day_id, evenement_id FROM training_session_day WHERE session_id=? AND evenement_id IS NOT NULL");
        $st->execute([$sid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ev = (int)$r['evenement_id'];
            $db->prepare("DELETE FROM evenement_recipient_cache WHERE event_id=?")->execute([$ev]);
            $db->prepare("DELETE FROM evenement_target WHERE event_id=?")->execute([$ev]);
            $db->prepare("DELETE FROM evenement_actor WHERE event_id=?")->execute([$ev]);
            $db->prepare("DELETE FROM evenement WHERE id=?")->execute([$ev]);
            $db->prepare("UPDATE training_session_day SET evenement_id=NULL WHERE day_id=?")->execute([$r['day_id']]);
        }
    } catch (Throwable $e) {}
}

/**
 * 同步某場次到行事曆：每個上課日一筆 evenement（先移除舊的再重建，避免改期後殘留）。
 * 回傳實際寫入的事件數；沒有綁定類別（且名稱也找不到）時回 0 並不寫入。
 */
function training_event_sync(PDO $db, int $sid): int {
    try {
        $st = $db->prepare("SELECT s.*, d.name AS dept_name FROM training_session s
                            LEFT JOIN department d ON d.id=s.dept_id WHERE s.session_id=?");
        $st->execute([$sid]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return 0;
        training_event_remove($db, $sid);
        if (!in_array($s['status'], ['scheduled', 'done'], true)) return 0;   // 只有確定開課後才進行事曆
        $catId = training_category_id($db, (string)$s['train_type']);
        if (!$catId) return 0;
        $dq = $db->prepare("SELECT day_id, day_no, day_date, start_time, end_time, hours FROM training_session_day
                            WHERE session_id=? ORDER BY day_no, day_date");
        $dq->execute([$sid]);
        $days = $dq->fetchAll(PDO::FETCH_ASSOC);
        $ext = $s['train_type'] === 'external';
        $n = count($days); $done = 0;
        foreach ($days as $d) {
            $title = '教育訓練：' . $s['course_name'] . ($n > 1 ? '（第' . $d['day_no'] . '/' . $n . '天）' : '');
            $startT = $d['start_time'] ?: '00:00';
            $endT   = $d['end_time'] ?: '23:59';
            $allday = ($d['start_time'] && $d['end_time']) ? 0 : 1;
            $remark = ($ext ? '外訓' : '內訓')
                . '／' . ($ext ? ('開課單位：' . ($s['org_unit'] ?: '—')) : ('講師：' . ($s['trainer'] ?: '—')))
                . '　對象：' . ($s['dept_id'] === null ? '全公司' : ($s['dept_name'] ?: ''))
                . ($s['location'] ? '　地點：' . $s['location'] : '')
                . ($d['hours'] !== null ? '　時數：' . rtrim(rtrim(number_format((float)$d['hours'], 1), '0'), '.') : '')
                . '（教育訓練管理 #' . $sid . '）';
            $db->prepare("INSERT INTO evenement (title, category_id, start, end, allday, remark) VALUES (?,?,?,?,?,?)")
               ->execute([$title, $catId, $d['day_date'] . ' ' . $startT . ':00', $d['day_date'] . ' ' . $endT . ':00', $allday, $remark]);
            $evId = (int)$db->lastInsertId();
            // 事件發生者：內訓＝講師＋參加人員；外訓＝只有參加人員（外部講師不是本公司員工）
            try {
                $actors = [];
                if (!$ext && $s['trainer_id']) $actors[] = (int)$s['trainer_id'];
                $aq = $db->prepare("SELECT user_id FROM training_attendee WHERE session_id=? AND user_id>0");
                $aq->execute([$sid]);
                foreach ($aq->fetchAll(PDO::FETCH_COLUMN) as $au) $actors[] = (int)$au;
                $ins = $db->prepare("INSERT INTO evenement_actor (event_id, user_id, created_at) VALUES (?,?,NOW())");
                foreach (array_values(array_unique(array_filter($actors))) as $au) $ins->execute([$evId, $au]);
            } catch (Throwable $e) {}
            training_event_set_targets($db, $evId);
            $db->prepare("UPDATE training_session_day SET evenement_id=? WHERE day_id=?")->execute([$evId, $d['day_id']]);
            $done++;
        }
        return $done;
    } catch (Throwable $e) { return 0; }
}

/* ============================================================
 * 使用者 / 權限（roles module='training'；admin⊃edit⊃view）
 * ============================================================ */
function training_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function training_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='training' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='training' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function training_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || training_has_role($db, $uid, ['training_admin']);
    $canEdit  = $canAdmin || training_has_role($db, $uid, ['training_edit']);
    $canView  = $canEdit  || training_has_role($db, $uid, ['training_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/* ============================================================
 * KPI 第19項計算：人員教育訓練達成率（供 kpi_as_lib compute 呼叫）
 * den=當月計畫場次(排除取消，除非 include_cancelled=1)；num=已完成場次
 * ============================================================ */
function training_kpi_compute(PDO $db, int $year, int $month, array $params): ?array {
    try {
        if (!$db->query("SHOW TABLES LIKE 'training_session'")->fetchColumn())
            return ['num'=>0, 'den'=>0, 'value'=>null];
    } catch (Throwable $e) { return ['num'=>0, 'den'=>0, 'value'=>null]; }

    // include_cancelled 兼容 {v,fe} 與扁平
    $inc = $params['include_cancelled'] ?? 0;
    if (is_array($inc)) $inc = $inc['v'] ?? 0;
    $inc = (int)$inc === 1;

    $denSql = "SELECT COUNT(*) FROM training_session WHERE year=? AND plan_month=?"
            . ($inc ? "" : " AND status<>'cancelled'");
    $st = $db->prepare($denSql);
    $st->execute([$year, $month]);
    $den = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM training_session WHERE year=? AND plan_month=? AND status='done'");
    $st->execute([$year, $month]);
    $num = (int)$st->fetchColumn();

    return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
}
