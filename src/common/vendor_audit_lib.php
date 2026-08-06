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

require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/org_role_lib.php';

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
        "ALTER TABLE maker_list ADD COLUMN in_roster TINYINT(1) NOT NULL DEFAULT 0 COMMENT '手動列入合格供應商清冊(非納管也可列冊)'",
        "ALTER TABLE maker_list ADD COLUMN roster_grade VARCHAR(6) NULL COMMENT '合格清冊-手動指定評核等級(覆寫定期評核建議值)'",
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

    // 查核表題庫(可設定化)：類別/項次/單項滿分皆可調整；已評分的稽核紀錄改用凍結快照,不受後續調整影響
    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit_checklist_cat (
        cat_id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) NULL,
        name VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核查核表-類別(可設定化)'");
    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit_checklist_item (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        cat_id INT NOT NULL,
        item_no VARCHAR(10) NOT NULL COMMENT '顯示用項次編號(管理員可自訂,非資料庫鍵)',
        question VARCHAR(300) NOT NULL,
        item_max DECIMAL(5,1) NOT NULL DEFAULT 7,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_cat (cat_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核查核表-項次(可設定化,含單項滿分)'");
    // 稽核紀錄：完成流程/簽核狀態 + 題庫快照(凍結當時查核表內容,不受後續調整影響)
    foreach ([
        "ALTER TABLE vendor_audit_target ADD COLUMN status VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft/completed/pending/approved/rejected'",
        "ALTER TABLE vendor_audit_target ADD COLUMN checklist_snapshot MEDIUMTEXT NULL COMMENT '首次登錄分數時凍結的查核表內容(類別/項次/滿分/權重/合格率) JSON'",
        "ALTER TABLE vendor_audit_target ADD COLUMN signed_by_name VARCHAR(50) NULL COMMENT '主管簽核人姓名(核准/自動核可時寫入)'",
        "ALTER TABLE vendor_audit_target ADD COLUMN signed_at DATETIME NULL COMMENT '主管簽核時間'",
        "ALTER TABLE vendor_audit_target ADD COLUMN signed_is_deputy TINYINT NULL COMMENT '是否代理人代簽'",
        "ALTER TABLE vendor_audit_target ADD COLUMN completed_at DATETIME NULL COMMENT '按下完成的時間'",
        "ALTER TABLE vendor_audit_target ADD COLUMN completed_by INT NULL COMMENT '按下完成的使用者id'",
        "ALTER TABLE vendor_audit_target ADD COLUMN completed_by_name VARCHAR(50) NULL",
        "ALTER TABLE vendor_audit_target ADD COLUMN review_type VARCHAR(10) NULL COMMENT 'site=人員實地審查 self=供應商主自評核 abnormal=異常檢核'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    // 供應商稽核計劃(2-PH-01-06,年度版)：送出後鎖定當年度不可再增列稽核對象
    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit_plan_lock (
        year INT PRIMARY KEY,
        status VARCHAR(12) NOT NULL DEFAULT 'approved' COMMENT 'pending=待核准 approved=已核准(含免簽核直接生效) rejected=已退回(視同解鎖)',
        submit_date DATE NOT NULL COMMENT '送出計畫日期(使用者可選,非一定是今天)',
        submitted_at DATETIME NOT NULL,
        submitted_by INT NULL,
        submitted_by_name VARCHAR(50) NULL,
        approved_by_name VARCHAR(50) NULL,
        approved_at DATETIME NULL
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核計劃-年度送出鎖定'");

    foreach ([['vendor_audit_view','稽核檢閱'],['vendor_audit_edit','稽核登錄'],['vendor_audit_admin','稽核管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='vendor_audit' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'vendor_audit')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ---- 本公司名稱（列印標頭統一來源：customer_list.is_own_company=1 的 customer_full 客戶全名發票用）---- */
function vendor_audit_company_name(PDO $db): string {
    try {
        $st = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) { $n = trim((string)($r['customer_full'] ?: $r['customer'])); if ($n !== '') return $n; }
    } catch (Throwable $e) {}
    return '超正齒輪科技有限公司';
}

/* ---- 綁定的 AS 表單（列印表單名稱/編號與 AS 文件管理連動）---- */
/** 綁定的 AS 文件；僅四階文件（表單/記錄表）doc_no 附加版次供直接列印用（見 ai-rules/16 第三節，二階以上不附加、無版次不附加） */
function vendor_audit_bound_asdoc(PDO $db, string $key = 'vendor_audit_as_doc_id'): ?array {
    $id = (int)vendor_eval_setting($db, $key, 0);
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, doc_no, doc_name, current_version, doc_level FROM as_document WHERE id=? AND (is_deleted IS NULL OR is_deleted=0) LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if (($r['doc_level'] ?? '') === '四階') {
            $r['doc_no'] = $r['doc_no'] . (string)($r['current_version'] ?? '');
        }
        return $r;
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

/** 由 scores_json（{item_id:{self,audit}}）+ 查核表設定($cfg，見 vendor_audit_resolve_cfg)計算各類與總合格率、判定。分數留空以0計。 */
function vendor_audit_compute_rates(array $scores, array $cfg): array {
    $cats = [];
    $tSelf = 0; $tAudit = 0; $tMax = 0;
    $selfW = (float)($cfg['self_w'] ?? VENDOR_AUDIT_SELF_W);
    $auditW = (float)($cfg['audit_w'] ?? VENDOR_AUDIT_AUDIT_W);
    $passRate = (float)($cfg['pass_rate'] ?? VENDOR_AUDIT_PASS_RATE);
    foreach (($cfg['items'] ?? []) as $cat) {
        [$code, $name, $items] = $cat;
        $cSelf = 0; $cAudit = 0; $cMax = 0;
        foreach ($items as $it) {
            $iid = (string)$it[0];
            $iMax = (float)($it[3] ?? VENDOR_AUDIT_ITEM_MAX);
            $cMax += $iMax;
            $s = $scores[$iid] ?? null;
            $sv = (is_array($s) && isset($s['self'])  && is_numeric($s['self']))  ? max(0, min($iMax, (float)$s['self']))  : 0;
            $av = (is_array($s) && isset($s['audit']) && is_numeric($s['audit'])) ? max(0, min($iMax, (float)$s['audit'])) : 0;
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
    $overall   = round($selfRate*$selfW + $auditRate*$auditW, 1);
    return [
        'categories'=>$cats,
        'total'=>['max'=>$tMax, 'self_sum'=>$tSelf, 'audit_sum'=>$tAudit,
                  'self_rate'=>$selfRate, 'audit_rate'=>$auditRate,
                  'overall_rate'=>$overall, 'judge'=>$overall >= $passRate ? 'pass' : 'fail'],
    ];
}

/* ============================================================
 * 查核表題庫(可設定化)：類別/項次/單項滿分/權重/合格率皆可由管理員調整。
 * 總分滿分＝所有生效項次滿分加總,系統自動算(不可手填)。
 * 已進行過評分的稽核紀錄一律凍結當時的查核表內容(vendor_audit_target.checklist_snapshot)，
 * 之後管理員再調整查核表/權重都不會回頭影響舊紀錄——見 vendor_audit_resolve_cfg()。
 * ============================================================ */
function vendor_audit_checklist_ensure_seed(PDO $db): void {
    $n = (int)$db->query("SELECT COUNT(*) FROM vendor_audit_checklist_item")->fetchColumn();
    if ($n > 0) return;
    $sort = 0;
    foreach (vendor_audit_items() as $cat) {
        [$code, $name, $items] = $cat;
        $sort += 10;
        $db->prepare("INSERT INTO vendor_audit_checklist_cat (code, name, sort_order, is_active) VALUES (?,?,?,1)")
           ->execute([$code, $name, $sort]);
        $catId = (int)$db->lastInsertId();
        $isort = 0;
        foreach ($items as $it) {
            $isort += 10;
            $db->prepare("INSERT INTO vendor_audit_checklist_item (cat_id, item_no, question, item_max, sort_order, is_active) VALUES (?,?,?,?,?,1)")
               ->execute([$catId, (string)$it[0], $it[1], VENDOR_AUDIT_ITEM_MAX, $isort]);
        }
    }
}

/** 自評/稽核權重與合格率門檻(可由管理員調整；預設沿用原本常數) */
function vendor_audit_weights(PDO $db): array {
    return [
        'self_w'    => (float)vendor_eval_setting($db, 'vendor_audit_self_w', VENDOR_AUDIT_SELF_W),
        'audit_w'   => (float)vendor_eval_setting($db, 'vendor_audit_audit_w', VENDOR_AUDIT_AUDIT_W),
        'pass_rate' => (float)vendor_eval_setting($db, 'vendor_audit_pass_rate', VENDOR_AUDIT_PASS_RATE),
    ];
}
function vendor_audit_save_weights(PDO $db, float $selfW, float $auditW, float $passRate): void {
    $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $up->execute(['vendor_audit_self_w', (string)$selfW]);
    $up->execute(['vendor_audit_audit_w', (string)$auditW]);
    $up->execute(['vendor_audit_pass_rate', (string)$passRate]);
}

/** 目前生效中的查核表：[[code,name,[[item_id,item_no,question,item_max],...]],...] */
function vendor_audit_checklist_live(PDO $db): array {
    vendor_audit_checklist_ensure_seed($db);
    $cats = $db->query("SELECT cat_id, code, name FROM vendor_audit_checklist_cat WHERE is_active=1 ORDER BY sort_order, cat_id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    $ist = $db->prepare("SELECT item_id, item_no, question, item_max FROM vendor_audit_checklist_item WHERE cat_id=? AND is_active=1 ORDER BY sort_order, item_id");
    foreach ($cats as $c) {
        $ist->execute([$c['cat_id']]);
        $items = [];
        foreach ($ist->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $items[] = [(string)$it['item_id'], (string)$it['item_no'], $it['question'], (float)$it['item_max']];
        }
        if ($items) $out[] = [$c['code'], $c['name'], $items];
    }
    return $out;
}

/** 目前生效中的完整查核表設定(給新草稿使用；已凍結快照的目標請用 vendor_audit_resolve_cfg) */
function vendor_audit_checklist_config(PDO $db): array {
    $items = vendor_audit_checklist_live($db);
    $w = vendor_audit_weights($db);
    $total = 0;
    foreach ($items as $cat) foreach ($cat[2] as $it) $total += $it[3];
    return ['items'=>$items, 'total_max'=>$total, 'self_w'=>$w['self_w'], 'audit_w'=>$w['audit_w'], 'pass_rate'=>$w['pass_rate']];
}

/** 解析某稽核目標「當時應採用」的查核表設定：已有快照就回放凍結內容，否則採用目前生效版本 */
function vendor_audit_resolve_cfg(PDO $db, ?string $snapshotJson): array {
    if ($snapshotJson) {
        $d = json_decode($snapshotJson, true);
        if (is_array($d) && !empty($d['items'])) return $d;
    }
    return vendor_audit_checklist_config($db);
}

/** 管理員儲存查核表(類別/項次/單項滿分)：整批覆蓋現行生效版本；不影響已凍結快照的舊紀錄 */
function vendor_audit_checklist_save(PDO $db, array $cats): void {
    vendor_audit_checklist_ensure_seed($db);
    $db->exec("UPDATE vendor_audit_checklist_cat SET is_active=0");
    $db->exec("UPDATE vendor_audit_checklist_item SET is_active=0");
    $sort = 0;
    foreach ($cats as $cat) {
        $code = trim((string)($cat['code'] ?? ''));
        $name = trim((string)($cat['name'] ?? ''));
        if ($name === '') continue;
        $sort += 10;
        $catId = (int)($cat['cat_id'] ?? 0);
        $hit = false;
        if ($catId > 0) {
            $up = $db->prepare("UPDATE vendor_audit_checklist_cat SET code=?, name=?, sort_order=?, is_active=1 WHERE cat_id=?");
            $up->execute([$code, $name, $sort, $catId]);
            $hit = $up->rowCount() > 0;
        }
        if (!$hit) {
            $db->prepare("INSERT INTO vendor_audit_checklist_cat (code, name, sort_order, is_active) VALUES (?,?,?,1)")
               ->execute([$code, $name, $sort]);
            $catId = (int)$db->lastInsertId();
        }
        $isort = 0;
        foreach (($cat['items'] ?? []) as $it) {
            $q = trim((string)($it['question'] ?? ''));
            if ($q === '') continue;
            $max = max(1, (float)($it['item_max'] ?? VENDOR_AUDIT_ITEM_MAX));
            $isort += 10;
            $itemNo = trim((string)($it['item_no'] ?? '')) ?: (string)$isort;
            $itemId = (int)($it['item_id'] ?? 0);
            $ihit = false;
            if ($itemId > 0) {
                $up = $db->prepare("UPDATE vendor_audit_checklist_item SET item_no=?, question=?, item_max=?, sort_order=?, is_active=1 WHERE item_id=? AND cat_id=?");
                $up->execute([$itemNo, $q, $max, $isort, $itemId, $catId]);
                $ihit = $up->rowCount() > 0;
            }
            if (!$ihit) {
                $db->prepare("INSERT INTO vendor_audit_checklist_item (cat_id, item_no, question, item_max, sort_order, is_active) VALUES (?,?,?,?,?,1)")
                   ->execute([$catId, $itemNo, $q, $max, $isort]);
            }
        }
    }
}

/** 逐項驗證「完成」前的完整性：稽核員/建議結論必填,所有生效項次自評/稽核分皆需在 0~單項滿分內的整數 */
function vendor_audit_validate_complete(array $post, array $cfg): array {
    $errs = [];
    if (trim((string)($post['auditor'] ?? '')) === '') $errs[] = '請填寫稽核員';
    if (trim((string)($post['conclusion'] ?? '')) === '') $errs[] = '請選擇建議評鑑結果';
    $reviewType = $post['review_type'] ?? '';
    if (!in_array($reviewType, ['site','self','abnormal'], true)) $errs[] = '請選擇審查類別（人員實地審查／供應商自主評核／異常檢核）';
    $scores = is_array($post['scores'] ?? null) ? $post['scores'] : [];
    $badSelf = 0; $badAudit = 0;
    foreach (($cfg['items'] ?? []) as $cat) {
        foreach ($cat[2] as $it) {
            $iid = (string)$it[0];
            $iMax = (float)($it[3] ?? VENDOR_AUDIT_ITEM_MAX);
            $s = $scores[$iid] ?? null;
            $sv = is_array($s) ? ($s['self'] ?? null) : null;
            $av = is_array($s) ? ($s['audit'] ?? null) : null;
            $ok = function($v) use ($iMax) {
                return $v !== null && $v !== '' && is_numeric($v) && (float)$v >= 0 && (float)$v <= $iMax && (float)$v == (int)$v;
            };
            if ($reviewType !== 'abnormal' && !$ok($sv)) $badSelf++;
            if (!$ok($av)) $badAudit++;
        }
    }
    if ($badSelf)  $errs[] = "尚有 {$badSelf} 項自評分未填寫或超出範圍";
    if ($badAudit) $errs[] = "尚有 {$badAudit} 項稽核分未填寫或超出範圍";
    return $errs;
}

/** 查核表「生產類別」自動勾選：依供應商主檔大類名稱比對原料/委外加工件/包材，比對不到回 null(不自動勾) */
function vendor_audit_prod_type(?string $mainCatName): ?string {
    $n = (string)$mainCatName;
    if ($n === '') return null;
    if (mb_strpos($n, '委外') !== false || mb_strpos($n, '加工') !== false) return 'outsource';
    if (mb_strpos($n, '包材') !== false) return 'packaging';
    if (mb_strpos($n, '原料') !== false) return 'raw';
    return null;
}

/* ============================================================
 * 稽核紀錄簽核（完成後自動核可 或 送審核給生管部門往上主管；OR-gate 單層，見 approval_lib.php）
 * ============================================================ */
/** 簽核設定：自動簽核開關 + 簽核部門(管理員從「生管組或往上部門」擇一) */
function vendor_audit_sign_setting(PDO $db): array {
    $raw = json_decode((string)vendor_eval_setting($db, 'VENDOR_AUDIT_SIGN', ''), true);
    $auto = (is_array($raw) && !empty($raw['auto'])) ? 1 : 0;
    $deptId = (is_array($raw) && !empty($raw['dept_id'])) ? (int)$raw['dept_id'] : null;
    $deptName = null;
    if ($deptId) {
        try { $st = $db->prepare("SELECT name FROM department WHERE id=?"); $st->execute([$deptId]); $deptName = $st->fetchColumn() ?: null; } catch (Throwable $e) {}
    }
    return ['auto'=>$auto, 'dept_id'=>$deptId, 'dept_name'=>$deptName];
}
function vendor_audit_sign_save_setting(PDO $db, int $auto, ?int $deptId): void {
    $val = json_encode(['auto'=>$auto?1:0, 'dept_id'=>$deptId], JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('VENDOR_AUDIT_SIGN', ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $st->execute([$val]);
}
/** 簽核部門下拉選項：從「生管部門」(org_role pm_dept)出發沿 department.parent_id 往上收集(含自己)，上限8層防呆 */
function vendor_audit_sign_dept_options(PDO $db): array {
    $startId = eg_org_dept($db, 'pm_dept');
    if (!$startId) return [];
    $out = []; $cur = $startId;
    for ($hop = 0; $hop < 8 && $cur; $hop++) {
        try {
            $st = $db->prepare("SELECT id, name, parent_id FROM department WHERE id=?");
            $st->execute([$cur]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $r = false; }
        if (!$r) break;
        $out[] = ['id'=>(int)$r['id'], 'name'=>$r['name']];
        $cur = $r['parent_id'] ? (int)$r['parent_id'] : null;
    }
    return $out;
}
/** 解析目前設定下實際該簽核的人（含代理/迴避解析）；找不到部門或主管回 null */
/**
 * 解析目前設定下實際該簽核的人（含代理/迴避解析）。
 * 若設定部門「無主管」或解析出的簽核人剛好就是製表人(申請人)本人，自動往上一個部門找主管，
 * 一路往上找到不同於申請人的人就用；若找到最上層仍是同一人(或整段都無主管)，
 * 允許回退成同一人(使用者已明確要求「若無上方部門時可允許同一人」)；完全找不到任何主管才回 null。
 * 若目前「自動簽核」開關(set.auto)為開，帶給 eg_resolve_signer() 的行程閘門是 auto_sign 模式
 * （只看主管今天是否請假，忽略開會等一般行程），因為自動簽核是系統當下直接數位蓋章，不需要主管人在場。
 */
function vendor_audit_resolve_signer(PDO $db, int $applicantUserId = 0): ?array {
    $set = vendor_audit_sign_setting($db);
    if (!$set['dept_id']) return null;
    $deptId = $set['dept_id'];
    $fallback = null;
    for ($hop = 0; $hop < 8 && $deptId; $hop++) {
        $mgr = eg_org_dept_manager($db, $deptId);
        if ($mgr) {
            $res = eg_resolve_signer($db, (int)$mgr['id'], ['applicant_id'=>$applicantUserId, 'flow_key'=>'vendor_audit_sign', 'log'=>true, 'auto_sign'=>!empty($set['auto'])]);
            $sid = (int)$res['signer_id'];
            $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
            $st->execute([$sid]);
            $cand = ['id'=>$sid, 'name'=>(string)($st->fetchColumn() ?: ''), 'is_deputy'=>!empty($res['is_delegated'])];
            if ($sid !== $applicantUserId) return $cand;
            $fallback = $cand;
        }
        try {
            $pst = $db->prepare("SELECT parent_id FROM department WHERE id=?");
            $pst->execute([$deptId]);
            $deptId = (int)($pst->fetchColumn() ?: 0) ?: null;
        } catch (Throwable $e) { $deptId = null; }
    }
    return $fallback;
}

if (!function_exists('vendor_audit_notify_sign')) {
/** 建立簽核通知（送給解析出的簽核人，mode=sign 動作完成前不消失）。回傳 live_event id（失敗回 0）。 */
function vendor_audit_notify_sign(PDO $db, int $targetId, int $signerId, string $makerName, ?int $submittedByUid, string $submittedByName): int {
    try {
        $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='VENDOR_AUDIT_SIGN' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())")
           ->execute([$targetId]);
        $title = '供應商稽核待簽核：' . $makerName;
        $content = $submittedByName . ' 完成供應商 ' . $makerName . ' 的稽核評鑑，請簽核。';
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '供應商稽核簽核', 1, 'VENDOR_AUDIT_SIGN', ?)")
           ->execute([$title, $content, $submittedByUid, $targetId]);
        $eventId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
           ->execute([$eventId, $signerId]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            $recipients = eg_push_event_recipients($db, $eventId);
            eg_push_send_to_users($db, $recipients, ['title'=>$title, 'body'=>mb_substr($content,0,480)]);
        } catch (Throwable $e) {}
        return $eventId;
    } catch (Throwable $e) { error_log('[vendor_audit] notify_sign failed: ' . $e->getMessage()); return 0; }
}}

if (!function_exists('vendor_audit_close_sign_notice')) {
/** 簽核人決行後結束此筆待簽核通知 */
function vendor_audit_close_sign_notice(PDO $db, int $targetId, int $deciderUid): void {
    try {
        $st = $db->prepare("SELECT id FROM live_event WHERE ref_type='VENDOR_AUDIT_SIGN' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())");
        $st->execute([$targetId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $eid = (int)$eid;
            $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=?")->execute([$eid]);
            $rs = $db->prepare("SELECT id FROM live_event_response WHERE live_event_id=? AND user_id=?");
            $rs->execute([$eid, $deciderUid]);
            if ($rid = $rs->fetchColumn()) {
                $db->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()), signed_at=COALESCE(signed_at,NOW()) WHERE id=?")->execute([$rid]);
            } else {
                $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at) VALUES (?,?,NOW(),NOW())")->execute([$eid, $deciderUid]);
            }
        }
    } catch (Throwable $e) { error_log('[vendor_audit] close_sign_notice failed: ' . $e->getMessage()); }
}}

if (!function_exists('vendor_audit_notify_sign_result')) {
/** 核准/退回結果通知原完成該筆的人（mode=read） */
function vendor_audit_notify_sign_result(PDO $db, int $targetId, string $makerName, ?int $submittedByUid, string $deciderName, string $decision, ?string $note): void {
    if (!$submittedByUid) return;
    try {
        if ($decision === 'approved') {
            $title = '供應商稽核已核准：' . $makerName;
            $content = $deciderName . ' 已核准供應商 ' . $makerName . ' 的稽核評鑑' . ($note ? '（意見：' . $note . '）' : '');
        } else {
            $title = '供應商稽核被退回：' . $makerName;
            $content = $deciderName . ' 退回了供應商 ' . $makerName . ' 的稽核評鑑，原因：' . ($note ?: '（未填寫原因）') . '，請修改後重新完成送審。';
        }
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, NULL, '供應商稽核簽核', 1, 'VENDOR_AUDIT_SIGN_RESULT', ?)")
           ->execute([$title, $content, $targetId]);
        $eventId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
           ->execute([$eventId, $submittedByUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            $recipients = eg_push_event_recipients($db, $eventId);
            eg_push_send_to_users($db, $recipients, ['title'=>$title, 'body'=>mb_substr($content,0,480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) { error_log('[vendor_audit] notify_sign_result failed: ' . $e->getMessage()); }
}}

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
        'grades'       => vendor_eval_grades($db),                                          // 評核等級門檻
    ];
}
function vendor_eval_save_settings(PDO $db, array $vals): void {
    $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach (['vendor_eval_ng_max','vendor_eval_special_max','vendor_eval_late_max','vendor_eval_default_days'] as $k) {
        if (array_key_exists($k, $vals)) $up->execute([$k, (string)$vals[$k]]);
    }
}

/* ---- 評核等級（分數→等級；管理員可設門檻）---- */
function vendor_eval_grades(PDO $db): array {
    $g = json_decode((string)vendor_eval_setting($db, 'vendor_eval_grades', ''), true);
    if (!is_array($g) || !$g) $g = [['min'=>90,'label'=>'A'],['min'=>80,'label'=>'B'],['min'=>70,'label'=>'C'],['min'=>0,'label'=>'D']];
    usort($g, function($a,$b){ return (float)($b['min']??0) <=> (float)($a['min']??0); });
    return $g;
}
function vendor_eval_save_grades(PDO $db, array $grades): void {
    $clean = [];
    foreach ($grades as $g) {
        $label = trim((string)($g['label'] ?? '')); if ($label==='') continue;
        $clean[] = ['min'=>max(0,(float)($g['min'] ?? 0)), 'label'=>$label];
    }
    if (!$clean) return;
    $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('vendor_eval_grades', ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $up->execute([json_encode($clean, JSON_UNESCAPED_UNICODE)]);
}
function vendor_eval_grade_of($score, array $grades): ?string {
    if ($score === null) return null;
    foreach ($grades as $g) if ($score >= (float)($g['min'] ?? 0)) return (string)($g['label'] ?? '');
    return null;
}
/** 彙總一段期間：回傳率/判定/分數(品質分+交期分,各50滿分,四捨五入)/等級 */
function vendor_eval_summ(int $qc, int $ng, int $sp, int $di, int $lt, array $set, array $grades): array {
    $ngR = $qc ? round($ng/$qc*100,1) : null;
    $spR = $qc ? round($sp/$qc*100,1) : null;
    $ltR = $di ? round($lt/$di*100,1) : null;
    $judge = null; $qScore = null; $dScore = null; $score = null;
    if ($qc>0 || $di>0) {
        $ok = true;
        if ($ngR!==null && $ngR>$set['ng_max']) $ok=false;
        if ($ltR!==null && $ltR>$set['late_max']) $ok=false;
        if ($set['special_max']<100 && $spR!==null && $spR>$set['special_max']) $ok=false;
        $judge = $ok ? 'pass' : 'fail';
        $qScore = $qc>0 ? (int)round((1-$ng/$qc)*50) : 50;   // 品質分=(1-不良率)×50
        $dScore = $di>0 ? (int)round((1-$lt/$di)*50) : 50;   // 交期分=(1-遲交率)×50
        $score = $qScore + $dScore;                          // 總分(0~100)
    }
    return ['qc_in'=>$qc,'ng'=>$ng,'special'=>$sp,'del_in'=>$di,'late'=>$lt,
            'ng_rate'=>$ngR,'special_rate'=>$spR,'late_rate'=>$ltR,'judge'=>$judge,
            'q_score'=>$qScore,'d_score'=>$dScore,'score'=>$score,'grade'=>vendor_eval_grade_of($score,$grades)];
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
    // 半年彙總 + 全年彙總（率/判定/分數/等級）
    $grades = vendor_eval_grades($db);
    $halves = [];
    $fqc=0;$fng=0;$fsp=0;$fdi=0;$flt=0;
    foreach ([1=>[1,6], 2=>[7,12]] as $h => $rg) {
        $qc=0;$ng=0;$sp=0;$di=0;$lt=0;
        for ($m=$rg[0]; $m<=$rg[1]; $m++){ $qc+=$mon[$m]['qc_in']; $ng+=$mon[$m]['ng']; $sp+=$mon[$m]['special']; $di+=$mon[$m]['del_in']; $lt+=$mon[$m]['late']; }
        $halves[$h] = vendor_eval_summ($qc,$ng,$sp,$di,$lt,$set,$grades);
        $fqc+=$qc;$fng+=$ng;$fsp+=$sp;$fdi+=$di;$flt+=$lt;
    }
    $full = vendor_eval_summ($fqc,$fng,$fsp,$fdi,$flt,$set,$grades);
    return ['months'=>$rows, 'halves'=>$halves, 'full'=>$full];
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

/* ============================================================
 * 供應商稽核計劃（2-PH-01-06，年度版：廠商×1~12月 V 標記，只顯示計畫不顯示結果）
 * 送出計畫＝鎖定該年度不可再增列稽核對象；可設定是否需要核准(最高核准人員 org_role top_approver)簽核。
 * ============================================================ */
/** 是否需要核准簽核(不需=送出即視同已核准) */
function vendor_audit_plan_sign_setting(PDO $db): array {
    $raw = json_decode((string)vendor_eval_setting($db, 'VENDOR_AUDIT_PLAN_SIGN', ''), true);
    return ['need' => (is_array($raw) && !empty($raw['need'])) ? 1 : 0];
}
function vendor_audit_plan_sign_save_setting(PDO $db, int $need): void {
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('VENDOR_AUDIT_PLAN_SIGN', ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $st->execute([json_encode(['need'=>$need?1:0])]);
}
/** 是否為超級管理員(全站固定 id=1 帳號e，且 state=99 特殊帳號狀態)；比照 meeting_lib.php 既有同款寫法。
 *  用於「取消送出/已核准的年度計畫」這類需要最高權限才能覆寫既定簽核結果的操作。 */
function vendor_audit_is_superadmin(PDO $db, int $uid): bool {
    if ($uid !== 1) return false;
    try {
        $st = $db->prepare("SELECT state FROM user WHERE id=1 LIMIT 1");
        $st->execute();
        return (int)$st->fetchColumn() === 99;
    } catch (Throwable $e) { return false; }
}
function vendor_audit_verify_superadmin_password(PDO $db, string $password): array {
    if ($password === '') return ['ok'=>false, 'msg'=>'請輸入超級管理員密碼'];
    try {
        $st = $db->prepare("SELECT user_password FROM `user` WHERE id=1 LIMIT 1");
        $st->execute();
        $real = $st->fetchColumn();
        if ($real === false) return ['ok'=>false, 'msg'=>'查無超級管理員帳號'];
        if (!hash_equals((string)$real, $password)) return ['ok'=>false, 'msg'=>'密碼錯誤'];
    } catch (Throwable $e) { return ['ok'=>false, 'msg'=>'密碼驗證失敗']; }
    return ['ok'=>true, 'msg'=>''];
}
function vendor_audit_plan_lock_get(PDO $db, int $year): ?array {
    $st = $db->prepare("SELECT * FROM vendor_audit_plan_lock WHERE year=?");
    $st->execute([$year]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** 該年度目前是否鎖定(不可再增列稽核對象)：pending/approved 都算鎖定，rejected 視同解鎖可修改重送 */
function vendor_audit_plan_locked(PDO $db, int $year): bool {
    $lock = vendor_audit_plan_lock_get($db, $year);
    return $lock !== null && in_array($lock['status'], ['pending','approved'], true);
}
/** 送出年度計畫：一律立即鎖定；不需簽核者直接視為已核准，需簽核者狀態=待核准並回傳待通知的簽核人 */
function vendor_audit_plan_submit(PDO $db, int $year, string $submitDate, int $byUid, string $byName): array {
    $need = vendor_audit_plan_sign_setting($db)['need'];
    $status = $need ? 'pending' : 'approved';
    $approvedName = $need ? null : $byName;
    $approvedAt   = $need ? null : date('Y-m-d H:i:s');
    $st = $db->prepare("INSERT INTO vendor_audit_plan_lock (year, status, submit_date, submitted_at, submitted_by, submitted_by_name, approved_by_name, approved_at)
                        VALUES (?,?,?,NOW(),?,?,?,?)
                        ON DUPLICATE KEY UPDATE status=VALUES(status), submit_date=VALUES(submit_date), submitted_at=NOW(),
                            submitted_by=VALUES(submitted_by), submitted_by_name=VALUES(submitted_by_name),
                            approved_by_name=VALUES(approved_by_name), approved_at=VALUES(approved_at)");
    $st->execute([$year, $status, $submitDate, $byUid, $byName, $approvedName, $approvedAt]);
    return vendor_audit_plan_lock_get($db, $year);
}
/** 年度計畫資料(給列印用)：彙總該年度(不分上下半年)所有目標的預定月份標記 + 廠商小類 */
function vendor_audit_plan_data(PDO $db, int $year): array {
    $st = $db->prepare("SELECT t.maker_id_no, m.maker_id, sc.sub_cat_names, t.plan_month
                        FROM vendor_audit_target t
                        JOIN vendor_audit_round r ON r.round_id=t.round_id AND r.year=?
                        JOIN maker_list m ON m.maker_id_no=t.maker_id_no
                        " . vendor_audit_subcat_join() . "
                        WHERE t.plan_month IS NOT NULL
                        ORDER BY m.maker_id");
    $st->execute([$year]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mid = $r['maker_id_no'];
        if (!isset($rows[$mid])) $rows[$mid] = ['maker_id_no'=>$mid, 'maker_id'=>$r['maker_id'], 'sub_cat_names'=>$r['sub_cat_names'], 'months'=>[]];
        $rows[$mid]['months'][(int)$r['plan_month']] = true;
    }
    $rows = array_values($rows);
    // 依稽核月份(取最早的預定月份)由小到大排序；相同月份內依加工項目排在一起
    usort($rows, function($a, $b) {
        $ma = min(array_keys($a['months'])); $mb = min(array_keys($b['months']));
        if ($ma !== $mb) return $ma <=> $mb;
        return strcmp((string)$a['sub_cat_names'], (string)$b['sub_cat_names']);
    });
    return $rows;
}
/** 廠商小類(加工項目)彙總 JOIN 片段：多個小類以「、」串接。大類本身不對外顯示(小類即代表加工項目)。別名固定用 m 代表 maker_list。 */
function vendor_audit_subcat_join(): string {
    return "LEFT JOIN (SELECT mp.maker_id_no, GROUP_CONCAT(s.sub_cat_name ORDER BY s.sub_cat_id SEPARATOR '、') AS sub_cat_names
                        FROM maker_sub_category_mapping mp
                        JOIN dict_maker_sub_category s ON s.sub_cat_id=mp.sub_cat_id AND s.is_active=1
                        GROUP BY mp.maker_id_no) sc ON sc.maker_id_no = m.maker_id_no";
}

if (!function_exists('vendor_audit_notify_plan_sign')) {
function vendor_audit_notify_plan_sign(PDO $db, int $year, int $signerId, ?int $submittedByUid, string $submittedByName): int {
    try {
        $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='VENDOR_AUDIT_PLAN' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())")
           ->execute([$year]);
        $title = $year . ' 年供應商稽核計劃待核准';
        $content = $submittedByName . ' 送出 ' . $year . ' 年供應商稽核計劃，請核准。';
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '供應商稽核計劃', 1, 'VENDOR_AUDIT_PLAN', ?)")
           ->execute([$title, $content, $submittedByUid, $year]);
        $eventId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
           ->execute([$eventId, $signerId]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            $recipients = eg_push_event_recipients($db, $eventId);
            eg_push_send_to_users($db, $recipients, ['title'=>$title, 'body'=>mb_substr($content,0,480)]);
        } catch (Throwable $e) {}
        return $eventId;
    } catch (Throwable $e) { error_log('[vendor_audit] notify_plan_sign failed: ' . $e->getMessage()); return 0; }
}}

if (!function_exists('vendor_audit_close_plan_notice')) {
function vendor_audit_close_plan_notice(PDO $db, int $year, int $deciderUid): void {
    try {
        $st = $db->prepare("SELECT id FROM live_event WHERE ref_type='VENDOR_AUDIT_PLAN' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())");
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $eid = (int)$eid;
            $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=?")->execute([$eid]);
            $rs = $db->prepare("SELECT id FROM live_event_response WHERE live_event_id=? AND user_id=?");
            $rs->execute([$eid, $deciderUid]);
            if ($rid = $rs->fetchColumn()) {
                $db->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()), signed_at=COALESCE(signed_at,NOW()) WHERE id=?")->execute([$rid]);
            } else {
                $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at) VALUES (?,?,NOW(),NOW())")->execute([$eid, $deciderUid]);
            }
        }
    } catch (Throwable $e) { error_log('[vendor_audit] close_plan_notice failed: ' . $e->getMessage()); }
}}

if (!function_exists('vendor_audit_notify_plan_result')) {
function vendor_audit_notify_plan_result(PDO $db, int $year, ?int $submittedByUid, string $deciderName, string $decision, ?string $note): void {
    if (!$submittedByUid) return;
    try {
        if ($decision === 'approved') {
            $title = $year . ' 年供應商稽核計劃已核准';
            $content = $deciderName . ' 已核准 ' . $year . ' 年供應商稽核計劃' . ($note ? '（意見：' . $note . '）' : '');
        } else {
            $title = $year . ' 年供應商稽核計劃被退回';
            $content = $deciderName . ' 退回了 ' . $year . ' 年供應商稽核計劃，原因：' . ($note ?: '（未填寫原因）') . '，請修改後重新送出。';
        }
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, NULL, '供應商稽核計劃', 1, 'VENDOR_AUDIT_PLAN_RESULT', ?)")
           ->execute([$title, $content, $year]);
        $eventId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
           ->execute([$eventId, $submittedByUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            $recipients = eg_push_event_recipients($db, $eventId);
            eg_push_send_to_users($db, $recipients, ['title'=>$title, 'body'=>mb_substr($content,0,480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) { error_log('[vendor_audit] notify_plan_result failed: ' . $e->getMessage()); }
}}
