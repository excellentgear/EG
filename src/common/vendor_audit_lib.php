<?php
/**
 * 供應商稽核管理 —— 共用函式庫（稽核批次模型）
 * 對應 KPI 2-GM-04-01 第6項「廠商稽核按時執行率」= 該期實際稽核家數 / 該期應稽核家數
 * 頻率半年（6/12月結算：6月=上半年、12月=下半年）
 *
 * 模型（每期挑一批對象）：
 *   - 每期 = (年, 上/下半年) 一列 vendor_audit_round
 *   - 該期稽核對象 = vendor_audit_target（可手動多選/隨機抽取加入；audit_date=NULL 未稽核）
 *   - 廠商是否需稽核 = maker_list.audit_managed（有些廠商不需稽核=0）
 *   - 大類=maker_list.main_category_id、加工項目(小類)=maker_sub_category_mapping（比照 master_data 廠商分頁）
 *   - master_data 設「停用」(maker_list.status='停用')者：頁面灰底、不可加入、隨機排除、不列入 KPI
 *   - 稽核週期(月)=全域共用設定(system_settings vendor_audit_cycle_months,預設6)，僅作參考/提醒
 *
 * KPI：den=該期對象數(排除停用)；num=其中已稽核(audit_date 非空)者。
 */

// maker_list.status='X' 代表停用（比照 master_data 廠商分頁：讀取一律 status<>'X'）
const VENDOR_AUDIT_DISABLED = 'X';

/* ============================================================
 * Schema
 * ============================================================ */
function vendor_audit_ensure_schema(PDO $db): void {
    // maker_list：納入稽核管理旗標（cycle/next_due 舊欄位保留但改由批次模型，不再使用）
    foreach ([
        "ALTER TABLE maker_list ADD COLUMN audit_managed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '納入稽核管理(需被稽核)'",
        "ALTER TABLE maker_list ADD COLUMN audit_cycle_months INT NULL COMMENT '(保留,改用全域週期)'",
        "ALTER TABLE maker_list ADD COLUMN audit_next_due DATE NULL COMMENT '(保留,改用批次模型)'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit_round (
        round_id INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        half TINYINT NOT NULL COMMENT '1=上半年 2=下半年',
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        UNIQUE KEY uq_period (year, half)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核期(每半年一期)'");

    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit_target (
        target_id INT AUTO_INCREMENT PRIMARY KEY,
        round_id INT NOT NULL,
        maker_id_no VARCHAR(11) NOT NULL,
        audit_date DATE NULL COMMENT '實際稽核日(NULL=未稽核)',
        result VARCHAR(12) NULL COMMENT 'pass=合格 conditional=限期改善 fail=不合格',
        score INT NULL,
        auditor VARCHAR(50) NULL,
        report_no VARCHAR(50) NULL,
        note VARCHAR(200) NULL,
        added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        added_by INT NULL,
        added_by_name VARCHAR(50) NULL,
        UNIQUE KEY uq_rt (round_id, maker_id_no),
        KEY idx_round (round_id),
        KEY idx_maker (maker_id_no)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核期對象+執行紀錄'");

    // 稽核評鑑表單(簡版15項 2-PH-01-02/03)：每對象存整份評分表
    foreach ([
        "ALTER TABLE vendor_audit_target ADD COLUMN plan_month TINYINT NULL COMMENT '預定稽核月份1-12(月內完成即準時)'",
        "ALTER TABLE vendor_audit_target ADD COLUMN scores_json TEXT NULL COMMENT '各項自評/稽核分 JSON'",
        "ALTER TABLE vendor_audit_target ADD COLUMN self_rate DECIMAL(5,1) NULL COMMENT '自評合格率%'",
        "ALTER TABLE vendor_audit_target ADD COLUMN audit_rate DECIMAL(5,1) NULL COMMENT '稽核合格率%'",
        "ALTER TABLE vendor_audit_target ADD COLUMN overall_rate DECIMAL(5,1) NULL COMMENT '綜合合格率%(自評x0.3+稽核x0.7)'",
        "ALTER TABLE vendor_audit_target ADD COLUMN judge VARCHAR(12) NULL COMMENT 'pass=合格 fail=不合格(依75%)'",
        "ALTER TABLE vendor_audit_target ADD COLUMN audit_mode VARCHAR(10) NULL COMMENT 'first=首次 again=次稽核 self=自我評量'",
        "ALTER TABLE vendor_audit_target ADD COLUMN self_evaluator VARCHAR(50) NULL COMMENT '自評人員'",
        "ALTER TABLE vendor_audit_target ADD COLUMN supplier_rep VARCHAR(50) NULL COMMENT '供應商代表'",
        "ALTER TABLE vendor_audit_target ADD COLUMN conclusion VARCHAR(30) NULL COMMENT '建議評鑑結果'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    // 稽核員資格（管理員設定：管理供應商的部門×人員，scope 區分外包加工/採購/通用）
    $db->exec("CREATE TABLE IF NOT EXISTS vendor_auditor (
        auditor_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_name VARCHAR(50) NULL,
        dept_id INT NULL COMMENT '管理供應商的部門 department.id',
        dept_name VARCHAR(50) NULL,
        scope VARCHAR(10) NOT NULL DEFAULT 'all' COMMENT 'outsource=外包加工 purchase=採購 all=通用',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        UNIQUE KEY uq_us (user_id, scope), KEY idx_scope (scope)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核員資格'");

    // 稽核佐證附件（供應商自評表等；DB只存檔名，路徑即時組）
    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit_attach (
        attach_id INT AUTO_INCREMENT PRIMARY KEY,
        target_id INT NOT NULL COMMENT '對應 vendor_audit_target',
        year INT NULL, file_name VARCHAR(120) NOT NULL COMMENT '實體檔名(亂數)',
        original_name VARCHAR(200) NULL, note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        KEY idx_target (target_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核佐證附件(供應商自評等)'");

    foreach ([['vendor_audit_view','稽核檢閱'],['vendor_audit_edit','稽核登錄'],['vendor_audit_admin','稽核管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='vendor_audit' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'vendor_audit')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ---- 綁定的 AS 表單（列印表單名稱/編號與 AS 文件管理連動）---- */
function vendor_audit_bound_asdoc(PDO $db, string $key = 'vendor_audit_as_doc_id'): ?array {
    $id = (int)vendor_eval_setting($db, $key, 0);
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, doc_no, doc_name FROM as_document WHERE id=? AND (is_deleted IS NULL OR is_deleted=0) LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* ---- 供應商 scope 判定：加工廠(main_category_id=1)=外包加工，其餘=採購 ---- */
function vendor_audit_scope_of(?int $mainCatId): string {
    return ((int)$mainCatId === 1) ? 'outsource' : 'purchase';
}
function vendor_audit_scope_label(string $s): string {
    return ['outsource'=>'外包加工','purchase'=>'採購','all'=>'通用'][$s] ?? $s;
}
/** 有效稽核員清單（依 scope 篩該 scope+all；**自動排除離職員工 user.state=0**） */
function vendor_audit_auditors(PDO $db, ?string $scope = null): array {
    $base = "SELECT a.auditor_id, a.user_id, a.user_name, a.dept_id, a.dept_name, a.scope
             FROM vendor_auditor a JOIN user u ON u.id=a.user_id
             WHERE a.is_active=1 AND (u.state IS NULL OR u.state<>0)";
    if ($scope === 'outsource' || $scope === 'purchase') {
        $st = $db->prepare($base . " AND a.scope IN (?, 'all') ORDER BY a.dept_name, a.user_name");
        $st->execute([$scope]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $db->query($base . " ORDER BY a.scope, a.dept_name, a.user_name")->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- 附件路徑（即時組：system_settings vendor_audit_attach_base + /年度/ 檔名） ---- */
function vendor_audit_attach_base(PDO $db): string {
    $v = trim((string)vendor_eval_setting($db, 'vendor_audit_attach_base', ''));
    if ($v !== '') return rtrim($v, '\\/');
    return realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'vendor_audit_attach';
}
function vendor_audit_attach_path(PDO $db, array $att): ?string {
    $fn = basename((string)($att['file_name'] ?? ''));
    if ($fn === '') return null;
    $p = vendor_audit_attach_base($db) . DIRECTORY_SEPARATOR . (int)($att['year'] ?: date('Y')) . DIRECTORY_SEPARATOR . $fn;
    return is_file($p) ? $p : null;
}

/* ============================================================
 * 稽核評鑑表單題庫（簡版15項 2-PH-01-02 供應商評鑑稽核查表）
 * 每項自評/稽核各評 0~7 分；分類滿分 A28/B28/C21/D14/E14＝總分105
 * ============================================================ */
const VENDOR_AUDIT_ITEM_MAX  = 7;
const VENDOR_AUDIT_TOTAL_MAX = 105;
const VENDOR_AUDIT_PASS_RATE = 75.0;   // 綜合合格率 ≥75% 判合格
const VENDOR_AUDIT_SELF_W    = 0.3;
const VENDOR_AUDIT_AUDIT_W   = 0.7;

function vendor_audit_items(): array {
    return [
        ['A', 'A.管理', [
            [1, '對客戶之訂單內容是否審核回簽？'],
            [2, '廠房是否保持整潔，乾燥，及良好照明？'],
            [3, '是否落實產品追溯系統？'],
            [4, '針對不良品或可疑品，是否有標示區別、隔離及處理？'],
        ]],
        ['B', 'B.品質', [
            [5, '是否落實首件檢查？'],
            [6, '是否在加工前校正量具？'],
            [7, '不合格品是否主動告知？'],
            [8, '重工後的產品，是否按照生產計劃予以再檢驗與測試？'],
        ]],
        ['C', 'C.交期', [
            [9,  '針對急單產生，處理配合度佳？'],
            [10, '是否有足夠機台及加工技術可配合製作？'],
            [11, '是否可配合出車收送貨？'],
        ]],
        ['D', 'D.出貨', [
            [12, '是否按照適合的包裝標準來執行？'],
            [13, '出貨是否有執行標籤管制作業？'],
        ]],
        ['E', 'E.矯正及預防', [
            [14, '針對異常事件產生，處理配合度佳？'],
            [15, '是否落實建議改善？'],
        ]],
    ];
}

/** 由 scores_json（{item_id:{self,audit}}）計算各類與總合格率、判定。分數留空以0計。 */
function vendor_audit_compute_rates(array $scores): array {
    $cats = [];
    $tSelf = 0; $tAudit = 0; $tMax = 0;
    foreach (vendor_audit_items() as $cat) {
        [$code, $name, $items] = $cat;
        $cSelf = 0; $cAudit = 0; $cMax = 0;
        foreach ($items as $it) {
            $iid = (string)$it[0];
            $cMax += VENDOR_AUDIT_ITEM_MAX;
            $s = $scores[$iid] ?? null;
            $sv = (is_array($s) && isset($s['self'])  && is_numeric($s['self']))  ? max(0, min(VENDOR_AUDIT_ITEM_MAX, (float)$s['self']))  : 0;
            $av = (is_array($s) && isset($s['audit']) && is_numeric($s['audit'])) ? max(0, min(VENDOR_AUDIT_ITEM_MAX, (float)$s['audit'])) : 0;
            $cSelf += $sv; $cAudit += $av;
        }
        $cats[] = [
            'code'=>$code, 'name'=>$name, 'max'=>$cMax,
            'self_sum'=>$cSelf, 'audit_sum'=>$cAudit,
            'self_rate'=>$cMax ? round($cSelf/$cMax*100, 1) : 0,
            'audit_rate'=>$cMax ? round($cAudit/$cMax*100, 1) : 0,
        ];
        $tSelf += $cSelf; $tAudit += $cAudit; $tMax += $cMax;
    }
    $selfRate  = $tMax ? round($tSelf/$tMax*100, 1) : 0;
    $auditRate = $tMax ? round($tAudit/$tMax*100, 1) : 0;
    $overall   = round($selfRate*VENDOR_AUDIT_SELF_W + $auditRate*VENDOR_AUDIT_AUDIT_W, 1);
    return [
        'categories'=>$cats,
        'total'=>['max'=>$tMax, 'self_sum'=>$tSelf, 'audit_sum'=>$tAudit,
                  'self_rate'=>$selfRate, 'audit_rate'=>$auditRate,
                  'overall_rate'=>$overall, 'judge'=>$overall >= VENDOR_AUDIT_PASS_RATE ? 'pass' : 'fail'],
    ];
}

/* ============================================================
 * 使用者 / 權限（roles module='vendor_audit'；admin⊃edit⊃view）
 * ============================================================ */
function vendor_audit_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function vendor_audit_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='vendor_audit' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='vendor_audit' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function vendor_audit_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || vendor_audit_has_role($db, $uid, ['vendor_audit_admin']);
    $canEdit  = $canAdmin || vendor_audit_has_role($db, $uid, ['vendor_audit_edit']);
    $canView  = $canEdit  || vendor_audit_has_role($db, $uid, ['vendor_audit_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/* ============================================================
 * 全域設定（system_settings）
 * ============================================================ */
function vendor_audit_cycle_months(PDO $db): int {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='vendor_audit_cycle_months' LIMIT 1");
        $st->execute();
        $v = (int)$st->fetchColumn();
        return $v > 0 ? $v : 6;
    } catch (Throwable $e) { return 6; }
}
function vendor_audit_set_cycle(PDO $db, int $months): void {
    $months = $months > 0 ? $months : 6;
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('vendor_audit_cycle_months', ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $st->execute([(string)$months]);
}

/* ---- 定期評核門檻設定（system_settings） ---- */
function vendor_eval_setting(PDO $db, string $key, $default) {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
function vendor_eval_settings(PDO $db): array {
    return [
        'ng_max'       => (float)vendor_eval_setting($db, 'vendor_eval_ng_max', 5),        // 不良率上限%
        'special_max'  => (float)vendor_eval_setting($db, 'vendor_eval_special_max', 100), // 特採率上限%(100=不判定)
        'late_max'     => (float)vendor_eval_setting($db, 'vendor_eval_late_max', 30),     // 遲交率上限%
        'default_days' => (int)vendor_eval_setting($db, 'vendor_eval_default_days', 7),    // 約定工作天(算應交日)
    ];
}
function vendor_eval_save_settings(PDO $db, array $vals): void {
    $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach (['vendor_eval_ng_max','vendor_eval_special_max','vendor_eval_late_max','vendor_eval_default_days'] as $k) {
        if (array_key_exists($k, $vals)) $up->execute([$k, (string)$vals[$k]]);
    }
}

/* ============================================================
 * 定期評核（2-PH-01-05）：單一廠商×年度 月不良率/特採率/遲交率（ERP bom_ing 自動算）
 *  品質：QC_check_date 歸月；進貨數=有檢驗筆數、不良=ng、特採=QQ
 *  交期：應交日=outsource_date+約定工作天(沿用#7)；歸應交月；遲交=未回廠或回廠>應交
 * ============================================================ */
function vendor_periodic_eval(PDO $db, string $mid, int $year, array $set): array {
    require_once __DIR__ . '/kpi_as_lib.php';
    $mon = [];
    for ($m = 1; $m <= 12; $m++) $mon[$m] = ['qc_in'=>0,'ng'=>0,'special'=>0,'del_in'=>0,'late'=>0];

    // 品質：依 QC_check_date 月份
    $st = $db->prepare("SELECT MONTH(QC_check_date) m, QC_check, COUNT(*) c FROM bom_ing
                        WHERE maker_id_no=? AND QC_check_date>=? AND QC_check_date<? AND QC_check IS NOT NULL AND QC_check<>''
                        GROUP BY MONTH(QC_check_date), QC_check");
    $st->execute([$mid, sprintf('%04d-01-01',$year), sprintf('%04d-01-01',$year+1)]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int)$r['m']; if ($m<1||$m>12) continue;
        $mon[$m]['qc_in'] += (int)$r['c'];
        if ($r['QC_check']==='ng') $mon[$m]['ng'] += (int)$r['c'];
        elseif ($r['QC_check']==='QQ') $mon[$m]['special'] += (int)$r['c'];
    }

    // 交期：進貨數=實際回廠(有 return_date)筆數，依回廠日歸月；遲交=回廠日晚於應交日(發包+約定工作天)
    $days = max(0, (int)$set['default_days']);
    $st = $db->prepare("SELECT outsource_date, return_date FROM bom_ing
                        WHERE maker_id_no=? AND return_date IS NOT NULL AND return_date>=? AND return_date<?");
    $st->execute([$mid, sprintf('%04d-01-01',$year), sprintf('%04d-01-01',$year+1)]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ret = substr((string)$r['return_date'],0,10);
        $m = (int)substr($ret,5,2); if ($m<1||$m>12) continue;
        $mon[$m]['del_in']++;
        if (!empty($r['outsource_date'])) {
            $due = kpi_as_add_workdays($db, (string)$r['outsource_date'], $days);
            if ($ret > $due) $mon[$m]['late']++;
        }
    }

    // 各月率
    $rows = [];
    foreach ($mon as $m => $d) {
        $rows[$m] = $d + [
            'ng_rate'      => $d['qc_in']  ? round($d['ng']/$d['qc_in']*100,1) : null,
            'special_rate' => $d['qc_in']  ? round($d['special']/$d['qc_in']*100,1) : null,
            'late_rate'    => $d['del_in'] ? round($d['late']/$d['del_in']*100,1) : null,
        ];
    }
    // 半年彙總(以加總筆數算率) + 判定
    $halves = [];
    foreach ([1=>[1,6], 2=>[7,12]] as $h => $rg) {
        $qc=0;$ng=0;$sp=0;$di=0;$lt=0;
        for ($m=$rg[0]; $m<=$rg[1]; $m++){ $qc+=$mon[$m]['qc_in']; $ng+=$mon[$m]['ng']; $sp+=$mon[$m]['special']; $di+=$mon[$m]['del_in']; $lt+=$mon[$m]['late']; }
        $ngR = $qc?round($ng/$qc*100,1):null; $spR=$qc?round($sp/$qc*100,1):null; $ltR=$di?round($lt/$di*100,1):null;
        $judge = null;
        if ($qc>0 || $di>0) {
            $ok = true;
            if ($ngR !== null && $ngR > $set['ng_max']) $ok = false;
            if ($ltR !== null && $ltR > $set['late_max']) $ok = false;
            if ($set['special_max'] < 100 && $spR !== null && $spR > $set['special_max']) $ok = false;
            $judge = $ok ? 'pass' : 'fail';
        }
        $halves[$h] = ['qc_in'=>$qc,'ng'=>$ng,'special'=>$sp,'del_in'=>$di,'late'=>$lt,
                       'ng_rate'=>$ngR,'special_rate'=>$spR,'late_rate'=>$ltR,'judge'=>$judge];
    }
    return ['months'=>$rows, 'halves'=>$halves];
}

/* ============================================================
 * 期別輔助
 * ============================================================ */
function vendor_audit_round_id(PDO $db, int $year, int $half, bool $create = false, ?array $u = null): ?int {
    $st = $db->prepare("SELECT round_id FROM vendor_audit_round WHERE year=? AND half=? LIMIT 1");
    $st->execute([$year, $half]);
    $rid = $st->fetchColumn();
    if ($rid !== false) return (int)$rid;
    if (!$create) return null;
    $ins = $db->prepare("INSERT INTO vendor_audit_round (year, half, created_by, created_by_name) VALUES (?,?,?,?)");
    $ins->execute([$year, $half, $u ? (int)$u['id'] : null, $u ? (string)$u['user_cname'] : null]);
    return (int)$db->lastInsertId();
}

/* ============================================================
 * KPI 第6項計算：廠商稽核按時執行率（半年批次，供 kpi_as_lib compute 呼叫）
 * month≤6→上半年、否則下半年；den=該期對象(排除停用)，num=已稽核
 * ============================================================ */
function vendor_audit_kpi_compute(PDO $db, int $year, int $month, array $params): ?array {
    try {
        if (!$db->query("SHOW TABLES LIKE 'vendor_audit_target'")->fetchColumn())
            return ['num'=>0, 'den'=>0, 'value'=>null];
    } catch (Throwable $e) { return ['num'=>0, 'den'=>0, 'value'=>null]; }

    $half = $month <= 6 ? 1 : 2;
    $rid = vendor_audit_round_id($db, $year, $half, false);
    if ($rid === null) return ['num'=>0, 'den'=>0, 'value'=>null];

    $st = $db->prepare("SELECT COUNT(*) den, SUM(t.audit_date IS NOT NULL) num
                        FROM vendor_audit_target t
                        JOIN maker_list mk ON mk.maker_id_no=t.maker_id_no
                        WHERE t.round_id=? AND (mk.status IS NULL OR mk.status<>?)");
    $st->execute([$rid, VENDOR_AUDIT_DISABLED]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $den = (int)($r['den'] ?? 0);
    $num = (int)($r['num'] ?? 0);
    return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
}
