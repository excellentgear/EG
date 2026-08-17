<?php
/**
 * 量測儀器校驗管理 —— 共用函式庫
 * 對應 KPI 2-GM-04-01 第18項「量測儀器按時校驗率」= 當月準時完成校驗數 / 當月應校驗數
 *
 * 資料模型（排程+紀錄合一，可追溯、可由 KPI 決定性重算）：
 *   - 儀器主檔沿用既有 qc_tool（量具），僅「加欄」不破壞既有：
 *       calib_cycle_months  校驗週期(月)
 *       calib_managed       是否納入校驗管理(KPI計算)
 *       calib_method        內校/外校（預設值，可於每次登錄覆寫）
 *       calibration_due     下次應校驗日（既有欄位，當作「目前待完成的到期日」）
 *       purchase_spec_id    對應採購料號規格（purchase_spec.spec_id）；全公司只留採購料號與產品料號兩種
 *   - qc_tool_calibration：每列＝某支量具「一次校驗週期」的完成紀錄
 *       due_date  該次應校驗到期日（排程當下的 calibration_due，準時判定基準）
 *       calib_date 實際完成日；next_due 完成後推算的下次到期(= calib_date + 週期)
 *
 *   - qc_tool_list（量具類別；新增/更名/刪除在「線上檢驗－量具設定」，本模組只加旗標欄）：
 *       calib_required 需校驗（否＝僅檢驗方式如「目視」，其量具不列入本頁與 KPI）
 *       has_tool_no    可設定底下量具編號（否＝無實體量具）
 *       calib_tab      在校驗管理頁以分頁顯示（需 calib_required=1）
 *       calib_tab_group 併入的自訂分頁 id（qc_tool_calib_tab；NULL＝自成一頁、分頁名用類別名）
 *   - qc_tool_calib_tab：自訂合併分頁（tab_name 自訂，可把數個類別歸到同一分頁）
 *
 * 人員列表一律走 src/common/people_lib.php（人員列表鐵則：只列未離職、長期請假標記、依職稱排序）。
 *
 * 到期判定：主檔週期自動推算——登錄完成時把 calibration_due 前滾為 next_due。
 * KPI 準時率：den = 當月到期(已完成紀錄的 due_date + 尚待完成的 calibration_due)；
 *            num = 其中 calib_date ≤ due_date(+寬限天數) 者。
 */

require_once __DIR__ . '/people_lib.php';   // 人員列表鐵則（只列未離職、長假標記、職稱排序）
require_once __DIR__ . '/org_role_lib.php'; // 組織角色綁定（最高核准人員、部門主管）
require_once __DIR__ . '/delegate_lib.php'; // 核准鏈「自動抓上一階主管」方法
require_once __DIR__ . '/approval_lib.php'; // 跨模組共用簽核紀錄（approval_record）
require_once __DIR__ . '/equip_list_lib.php'; // 檢驗設備一覽表：保管人員歷程/履歴表/年度整份送簽（equip_type='qc_tool'，與機台設備一覽表共用邏輯）

/** 核准鏈可用方法（比照 ai-rules/19 第五節；唯一參考實作 review_form_lib.php 的 RVF_APPROVER_METHODS） */
const TOOL_CALIB_APPROVER_METHODS = ['dept_or_user', 'auto_supervisor', 'top_approver'];

/* ============================================================
 * Schema（CREATE TABLE IF NOT EXISTS + qc_tool 加欄 + roles seed）
 * ============================================================ */
function tool_calib_ensure_schema(PDO $db): void {
    equip_list_ensure_schema($db); // 檢驗設備一覽表共用表(equip_assignee_history/equip_service_log/equip_list_plan_lock)
    // 儀器主檔加欄（既有 qc_tool，僅新增可空欄，不動既有資料）
    foreach ([
        "ALTER TABLE qc_tool ADD COLUMN calib_cycle_months INT NULL COMMENT '校驗週期(月)'",
        "ALTER TABLE qc_tool ADD COLUMN calib_managed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '納入校驗管理(KPI計算)'",
        "ALTER TABLE qc_tool ADD COLUMN calib_method VARCHAR(10) NULL COMMENT '校驗方式 內校/外校'",
        // 量具規格一律掛到「採購料號」(purchase_item→purchase_spec)，不另建第三套料號主檔
        // （2026-07-30 使用者定案）；實體仍以 Tool_No 為主，這裡只是往上指向規格。
        "ALTER TABLE qc_tool ADD COLUMN purchase_spec_id INT NULL COMMENT '對應採購料號規格 purchase_spec.spec_id'",
        "ALTER TABLE qc_tool ADD INDEX idx_purchase_spec (purchase_spec_id)",
        // 檢驗設備一覽表（2026-08-17新增）用欄位：純文字描述，不強迫綁 purchase_spec_id（很多舊量具沒設過採購規格）
        "ALTER TABLE qc_tool ADD COLUMN manufacturer VARCHAR(100) NULL COMMENT '製造商'",
        "ALTER TABLE qc_tool ADD COLUMN spec_desc VARCHAR(255) NULL COMMENT '規格(檢驗設備一覽表顯示用，純文字，與purchase_spec關聯規格用途不同不可混用)'",
        "ALTER TABLE qc_tool ADD COLUMN purchase_date DATE NULL COMMENT '購買日期'",
        "ALTER TABLE qc_tool ADD COLUMN note VARCHAR(200) NULL COMMENT '備註(檢驗設備一覽表用)'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    // 量具類別主檔 qc_tool_list 加欄（類別的新增/更名/刪除仍只在「線上檢驗－量具設定」，本模組不重複提供）
    //   calib_required 此類別是否需校驗（否＝僅檢驗方式，例如「目視」，不列入本頁與 KPI）
    //   has_tool_no    此類別是否可設定底下量具編號（否＝沒有實體量具，不可建編號）
    //   calib_tab      是否在校驗管理頁以分頁顯示（僅 calib_required=1 者可設）
    foreach ([
        "ALTER TABLE qc_tool_list ADD COLUMN calib_required TINYINT(1) NOT NULL DEFAULT 1 COMMENT '需校驗(列入量測儀器校驗管理)'",
        "ALTER TABLE qc_tool_list ADD COLUMN has_tool_no TINYINT(1) NOT NULL DEFAULT 1 COMMENT '可設定底下量具編號(否=僅檢驗方式)'",
        "ALTER TABLE qc_tool_list ADD COLUMN calib_tab TINYINT(1) NOT NULL DEFAULT 0 COMMENT '校驗管理頁以分頁顯示(需 calib_required=1)'",
        "ALTER TABLE qc_tool_list ADD COLUMN calib_tab_group INT NULL COMMENT '併入的自訂分頁 id(qc_tool_calib_tab)；NULL=自成一頁用類別名'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    // 自訂合併分頁：可把數個類別歸到同一個自訂名稱的分頁（例：盤式/跨珠/針狀/外徑/珠徑 → 「分厘卡」）
    $db->exec("CREATE TABLE IF NOT EXISTS qc_tool_calib_tab (
        tab_id INT AUTO_INCREMENT PRIMARY KEY,
        tab_name VARCHAR(30) NOT NULL COMMENT '自訂分頁名稱',
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY uk_tab_name (tab_name)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='量測儀器校驗管理－自訂合併分頁'");

    $db->exec("CREATE TABLE IF NOT EXISTS qc_tool_calibration (
        calib_id INT AUTO_INCREMENT PRIMARY KEY,
        Tool_id INT NOT NULL COMMENT '對應 qc_tool.Tool_id',
        due_date DATE NULL COMMENT '本次應校驗到期日(準時判定基準)',
        calib_date DATE NOT NULL COMMENT '實際校驗完成日',
        result VARCHAR(12) NOT NULL DEFAULT 'pass' COMMENT 'pass=合格 fail=不合格 pass_adjust=校正後合格',
        method VARCHAR(10) NULL COMMENT '內校/外校',
        operator VARCHAR(50) NULL COMMENT '校驗人員/單位',
        cert_no VARCHAR(50) NULL COMMENT '校驗憑證/報告編號',
        next_due DATE NULL COMMENT '完成後推算的下次到期(= calib_date + 週期)',
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        KEY idx_tool (Tool_id),
        KEY idx_due (due_date),
        KEY idx_calib (calib_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='量測儀器校驗紀錄(排程+完成合一)'");

    // 批次校驗（一次校驗多支量具，常見於外校/廠內批量校驗）
    foreach ([
        "ALTER TABLE qc_tool_calibration ADD COLUMN batch_id INT NULL COMMENT '批次校驗單 id(qc_tool_calib_batch)；NULL=單筆登錄'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS qc_tool_calib_batch (
        batch_id INT AUTO_INCREMENT PRIMARY KEY,
        calib_date DATE NOT NULL COMMENT '本批校驗完成日',
        method VARCHAR(10) NULL COMMENT '內校/外校',
        operator VARCHAR(50) NULL COMMENT '校驗人員/單位(外校廠商)',
        cert_no VARCHAR(50) NULL COMMENT '校驗憑證/報告編號',
        note VARCHAR(200) NULL,
        tool_count INT NOT NULL DEFAULT 0 COMMENT '本批量具數(快取，僅供列表顯示)',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        KEY idx_date (calib_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='量測儀器批次校驗單'");

    // 校驗附件（鐵律5：DB 只存檔名，完整路徑讀取當下用設定值現場組；temp/active 暫存機制）
    $db->exec("CREATE TABLE IF NOT EXISTS qc_tool_calib_attach (
        attach_id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL DEFAULT 0 COMMENT '所屬批次；0=尚未存檔的暫存',
        category_id INT NULL COMMENT '對應量具類別(選填，方便依種類上傳報告)',
        doc_type VARCHAR(20) NULL COMMENT '文件類別(校驗報告/證書/原始記錄…，可於附件設定維護)',
        file_name VARCHAR(120) NOT NULL COMMENT '實際存檔檔名(不含路徑!)',
        original_name VARCHAR(200) NULL,
        file_size INT NULL,
        note VARCHAR(200) NULL,
        user_id INT NULL COMMENT '上傳者(temp 列以此判定擁有者)',
        status VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT 'temp=未存檔暫存 / active=正式',
        expire_at DATETIME NULL COMMENT 'temp 自動清除時間',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_batch (batch_id),
        KEY idx_status (status)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='量測儀器校驗附件(校驗報告等)'");

    // 附件↔量具 一對多對應（一份外校報告可涵蓋多支量具）
    $db->exec("CREATE TABLE IF NOT EXISTS qc_tool_calib_attach_map (
        attach_id INT NOT NULL,
        Tool_id INT NOT NULL,
        PRIMARY KEY (attach_id, Tool_id),
        KEY idx_tool (Tool_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='校驗附件對應的量具編號(一對多)'");

    // 校驗人員（內校）：品管部門底下具校驗人員資格者，由管理員在本頁「校驗人員資格」設定
    $db->exec("CREATE TABLE IF NOT EXISTS qc_tool_calib_staff (
        user_id INT NOT NULL PRIMARY KEY COMMENT 'user.id；具內校校驗人員資格',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL
    ) DEFAULT CHARSET=utf8mb4 COMMENT='量測儀器內校校驗人員資格'");

    // 校驗人員／外校廠商的可追溯欄位（顯示仍用 operator 字串，這裡多存來源 id）
    foreach ([
        "ALTER TABLE qc_tool_calibration ADD COLUMN operator_user_id INT NULL COMMENT '內校人員 user.id',
         ADD COLUMN vendor_id VARCHAR(11) NULL COMMENT '外校廠商 maker_list.maker_id_no'",
        "ALTER TABLE qc_tool_calib_batch ADD COLUMN operator_user_id INT NULL COMMENT '內校人員 user.id',
         ADD COLUMN vendor_id VARCHAR(11) NULL COMMENT '外校廠商 maker_list.maker_id_no'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    // 內校覆驗者簽章＋主管核准（使用者 2026-08-12 明確要求）：覆驗人與校驗人員一樣，登錄時挑選（僅內校適用）；
    // 是否需要主管核准由管理員開關(tool_calib_approval_cfg)決定，核准狀態存在「登錄事件」這一層(batch，單筆登錄也會建一筆
    // tool_count=1 的批次，見 create_batch)，不落在 qc_tool_calibration 逐列——一次登錄涵蓋的量具共用同一次覆驗/核准。
    // approval_status 是 approval_record（module='tool_calib_batch'）目前狀態的快取，供列表不必逐列 join 判斷。
    foreach ([
        "ALTER TABLE qc_tool_calib_batch ADD COLUMN reviewer_user_id INT NULL COMMENT '內校覆驗者 user.id(登錄時挑選，比照校驗人員資格池)'",
        "ALTER TABLE qc_tool_calib_batch ADD COLUMN reviewer_name VARCHAR(50) NULL COMMENT '內校覆驗者姓名'",
        "ALTER TABLE qc_tool_calib_batch ADD COLUMN approval_status VARCHAR(10) NOT NULL DEFAULT 'none' COMMENT 'none=免核准/pending/approved/rejected'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    // 角色 seed（module='tool_calib'，供 user_permissions.php 指派）
    foreach ([['tool_calib_view','校驗唯讀'],['tool_calib_edit','校驗登錄'],['tool_calib_admin','校驗管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='tool_calib' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'tool_calib')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ============================================================
 * 使用者 / 權限（roles module='tool_calib'；admin⊃edit⊃view，fail-closed）
 * ============================================================ */
function tool_calib_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function tool_calib_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='tool_calib' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='tool_calib' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

/** 回傳 ['isAdmin','canAdmin','canEdit','canView'] 能力階層 */
function tool_calib_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || tool_calib_has_role($db, $uid, ['tool_calib_admin']);
    $canEdit  = $canAdmin || tool_calib_has_role($db, $uid, ['tool_calib_edit']);
    $canView  = $canEdit  || tool_calib_has_role($db, $uid, ['tool_calib_view']);
    // 內校主管核准的核准人多半不屬於品管部門、沒有本模組任何角色，但收到待核准通知後要能打開本頁處理，
    // 否則通知形同虛設（比照 ai-rules/19：核准人只看得到自己被通知的那筆，這裡只放行「檢閱」，決行時
    // batch_decide/plan_decide 仍會用送出者本人的職級重新解析一次合格池，不因此多出可核准的權限）。
    if (!$canView) {
        $cfg = tool_calib_approval_cfg($db);
        if ($cfg['approver_user_id'] && (int)$cfg['approver_user_id'] === $uid) $canView = true;
        elseif ($cfg['approver_dept_id'] && in_array($uid, array_column(tool_calib_dept_managers($db, $cfg['approver_dept_id']), 'id'), true)) $canView = true;
        else { $top = eg_org_user($db, 'top_approver'); if ($top && (int)$top['id'] === $uid) $canView = true; }
    }
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/* ============================================================
 * 工具函式
 * ============================================================ */
/**
 * 到期一律以「月」為單位（使用者 2026-07-30 定調：同一月份內安排校驗即可）
 *   - calibration_due / due_date / next_due 一律存該月 **1 日**，語意＝「該月到期」
 *   - 準時判定＝實際校驗日 ≤ 該月**最後一天**(+寬限)
 * 任意日期字串（含 'YYYY-MM'）正規化成該月 1 日
 */
function tool_calib_month_start(?string $date): ?string {
    if (!$date) return null;
    if (preg_match('/^(\d{4})-(\d{2})$/', trim($date), $m)) return sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]);
    $t = strtotime(substr(trim($date), 0, 10));
    return $t === false ? null : date('Y-m-01', $t);
}

/** 該到期月的最後一天（準時判定基準） */
function tool_calib_month_end(?string $date): ?string {
    $s = tool_calib_month_start($date);
    return $s === null ? null : date('Y-m-t', strtotime($s));
}

/** 校驗月 + 週期 → 下次應校驗月（回該月 1 日）；週期未設回 null */
function tool_calib_next_due_month(string $calibDate, int $months): ?string {
    if ($months <= 0) return null;
    $s = tool_calib_month_start($calibDate);
    return $s === null ? null : tool_calib_shift_months($s, $months);
}

/** 安全加月份（避免 1/31 +1月 溢位到 3 月，超出當月時 clamp 到月底） */
function tool_calib_add_months(string $date, int $months): ?string {
    $t = strtotime(substr($date, 0, 10));
    if ($t === false || $months <= 0) return null;
    $y = (int)date('Y', $t); $m = (int)date('n', $t); $d = (int)date('j', $t);
    $m += $months;
    $y += intdiv($m - 1, 12);
    $m = (($m - 1) % 12) + 1;
    $last = (int)date('t', mktime(0,0,0,$m,1,$y));
    if ($d > $last) $d = $last;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * 量具類別清單（含校驗屬性旗標與量具數）
 * 旗標一律 COALESCE 預設值，欄位剛加或舊資料為 NULL 時行為與加欄前相同（需校驗、可設編號、無分頁）
 */
function tool_calib_categories(PDO $db): array {
    $rows = $db->query("SELECT l.QC_Tool_List_id, l.QC_Tool, l.sort_order,
                               COALESCE(l.calib_required,1) AS calib_required,
                               COALESCE(l.has_tool_no,1)    AS has_tool_no,
                               COALESCE(l.calib_tab,0)      AS calib_tab,
                               l.calib_tab_group,
                               (SELECT COUNT(*) FROM qc_tool t WHERE t.QC_Tool_List_id=l.QC_Tool_List_id) AS tool_cnt,
                               (SELECT COUNT(*) FROM qc_tool t WHERE t.QC_Tool_List_id=l.QC_Tool_List_id AND t.calib_managed=1) AS managed_cnt
                        FROM qc_tool_list l
                        ORDER BY l.sort_order, l.QC_Tool_List_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        foreach (['QC_Tool_List_id','calib_required','has_tool_no','calib_tab','tool_cnt','managed_cnt'] as $k) {
            $r[$k] = (int)$r[$k];
        }
        $r['calib_tab_group'] = ($r['calib_tab_group'] === null || $r['calib_tab_group'] === '')
                              ? null : (int)$r['calib_tab_group'];
    }
    return $rows;
}

/** 自訂合併分頁清單（含成員類別數） */
function tool_calib_tabs(PDO $db): array {
    try {
        $rows = $db->query("SELECT t.tab_id, t.tab_name, t.sort_order,
                                   (SELECT COUNT(*) FROM qc_tool_list l WHERE l.calib_tab_group=t.tab_id) AS cat_cnt
                            FROM qc_tool_calib_tab t
                            ORDER BY t.sort_order, t.tab_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }   // 表尚未建立
    foreach ($rows as &$r) {
        $r['tab_id'] = (int)$r['tab_id']; $r['sort_order'] = (int)$r['sort_order']; $r['cat_cnt'] = (int)$r['cat_cnt'];
    }
    return $rows;
}

/**
 * 儀器目前狀態（給前端上色/篩選）——到期以「月」為單位
 *   overdue＝到期月已整月過完仍未校驗；soon＝本月或下個月到期；ok＝更晚
 */
function tool_calib_status(array $tool, int $warnMonths = 1): string {
    if ((int)$tool['calib_managed'] !== 1) return 'unmanaged';
    $due = $tool['calibration_due'] ?? null;
    if (!$due) return 'nobaseline';
    $dueM  = substr((string)tool_calib_month_start($due), 0, 7);
    $thisM = date('Y-m');
    if ($dueM < $thisM) return 'overdue';                                    // 到期月已過
    $warnM = date('Y-m', strtotime(date('Y-m-01') . " +{$warnMonths} month"));
    if ($dueM <= $warnM) return 'soon';                                      // 本月或警示月內
    return 'ok';
}

/* ============================================================
 * 校驗人員（內校）：品管部門沿用「異常單處置決策設定」已設好的部門，不另設一份
 * ============================================================ */
/** 品管部門 id（qa_system_settings.qa_qc_dept_ids）＋其所有子部門 */
function tool_calib_qc_dept_ids(PDO $db): array {
    // 2026-08-03 起品管部門改由全站「組織角色綁定設定」的 qc_dept 決定（含子部門）；
    // 舊 qa_qc_dept_ids 只當統一設定未綁定時的回退值。
    require_once __DIR__ . '/org_role_lib.php';
    $u = eg_org_dept_ids($db, 'qc_dept');
    if ($u) return $u;
    $ids = [];
    try {
        $st = $db->prepare("SELECT setting_value FROM qa_system_settings WHERE setting_key='qa_qc_dept_ids'");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) $ids = array_values(array_filter(array_map('intval', $j)));
    } catch (Throwable $e) { return []; }
    if (!$ids) return [];
    // 展開子部門（最多 5 層，避免資料異常造成無限迴圈）
    $all = $ids;
    for ($lv = 0; $lv < 5; $lv++) {
        $in = implode(',', array_map('intval', $ids));
        $kids = $db->query("SELECT id FROM department WHERE parent_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        $kids = array_values(array_diff(array_map('intval', $kids), $all));
        if (!$kids) break;
        $all = array_merge($all, $kids);
        $ids = $kids;
    }
    return $all;
}

/**
 * 品管部門人員清單（含是否具校驗資格）；供設定畫面勾選
 * 一律走 people_lib（人員列表鐵則：只列未離職、標記長期請假、依職稱排序、含職稱/部門）
 */
function tool_calib_staff_candidates(PDO $db): array {
    $depts = tool_calib_qc_dept_ids($db);
    if (!$depts) return [];
    $rows = eg_people_list($db, ['dept_ids'=>$depts]);
    $q = [];
    try {
        foreach ($db->query("SELECT user_id FROM qc_tool_calib_staff")->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $q[(int)$v] = 1;
        }
    } catch (Throwable $e) { /* 表尚未建立 */ }
    foreach ($rows as &$r) { $r['qualified'] = isset($q[$r['id']]) ? 1 : 0; }
    return $rows;
}

/** 具校驗人員資格者（內校人員下拉用）；離職者自動不列，長期請假者會帶 leave_note 標記 */
function tool_calib_qualified_staff(PDO $db): array {
    try {
        $ids = $db->query("SELECT user_id FROM qc_tool_calib_staff")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    return eg_people_list($db, ['user_ids'=>$ids]);
}

/* ============================================================
 * 內校覆驗者／主管核准（使用者 2026-08-12 明確要求）：
 *   覆驗者＝登錄時挑選，池同校驗人員資格(qc_tool_calib_staff)，且不可與校驗人員(operator)同一人
 *   主管核准＝管理員可開關；需要時核准人一律走「核准鏈」(ai-rules/19 第五節手法，比照 review_form_lib)
 *   圖章樣式＝「逐列簽章」「製表/核准」各自可綁定一個 stamp_template（不設定則 EGStamp 用預設樣式）
 * 設定值一律存 system_settings（本模組沒有「模板」概念，全站只有一份設定，不比照 review_form 存在資料表列上）
 * ============================================================ */
const TOOL_CALIB_APPROVAL_KEYS = ['tool_calib_need_approval', 'tool_calib_approver_dept_id',
    'tool_calib_approver_user_id', 'tool_calib_approver_chain',
    'tool_calib_list_stamp_tpl_id', 'tool_calib_footer_stamp_tpl_id'];

function tool_calib_approval_cfg(PDO $db): array {
    $out = ['need_approval'=>0, 'approver_dept_id'=>null, 'approver_user_id'=>null,
            'approver_chain'=>['top_approver'], 'list_stamp_tpl_id'=>null, 'footer_stamp_tpl_id'=>null];
    try {
        $in = implode(',', array_fill(0, count(TOOL_CALIB_APPROVAL_KEYS), '?'));
        $st = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(TOOL_CALIB_APPROVAL_KEYS);
        $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { return $out; }
    if (isset($rows['tool_calib_need_approval'])) $out['need_approval'] = (int)$rows['tool_calib_need_approval'] === 1 ? 1 : 0;
    if (!empty($rows['tool_calib_approver_dept_id'])) $out['approver_dept_id'] = (int)$rows['tool_calib_approver_dept_id'];
    if (!empty($rows['tool_calib_approver_user_id'])) $out['approver_user_id'] = (int)$rows['tool_calib_approver_user_id'];
    if (!empty($rows['tool_calib_approver_chain'])) {
        $c = json_decode((string)$rows['tool_calib_approver_chain'], true);
        if (is_array($c) && $c) $out['approver_chain'] = array_values(array_intersect($c, TOOL_CALIB_APPROVER_METHODS)) ?: ['top_approver'];
    }
    if (!empty($rows['tool_calib_list_stamp_tpl_id']))   $out['list_stamp_tpl_id']   = (int)$rows['tool_calib_list_stamp_tpl_id'];
    if (!empty($rows['tool_calib_footer_stamp_tpl_id'])) $out['footer_stamp_tpl_id'] = (int)$rows['tool_calib_footer_stamp_tpl_id'];
    return $out;
}

function tool_calib_approval_cfg_save(PDO $db, array $d): void {
    $chain = array_values(array_intersect($d['approver_chain'] ?? ['top_approver'], TOOL_CALIB_APPROVER_METHODS)) ?: ['top_approver'];
    $pairs = [
        'tool_calib_need_approval'       => !empty($d['need_approval']) ? '1' : '0',
        'tool_calib_approver_dept_id'    => !empty($d['approver_dept_id']) ? (string)(int)$d['approver_dept_id'] : '',
        'tool_calib_approver_user_id'    => !empty($d['approver_user_id']) ? (string)(int)$d['approver_user_id'] : '',
        'tool_calib_approver_chain'      => json_encode($chain),
        'tool_calib_list_stamp_tpl_id'   => !empty($d['list_stamp_tpl_id']) ? (string)(int)$d['list_stamp_tpl_id'] : '',
        'tool_calib_footer_stamp_tpl_id' => !empty($d['footer_stamp_tpl_id']) ? (string)(int)$d['footer_stamp_tpl_id'] : '',
    ];
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($pairs as $k => $v) $st->execute([$k, $v]);
}

/** 部門(含子部門)內具主管職級者；$maxLevel 有給值時只留職級不高於它的(數字越小職級越高)。回傳 [['id','user_cname'],...] */
function tool_calib_dept_managers(PDO $db, ?int $deptId, ?int $maxLevel = null): array {
    if (!$deptId) return [];
    $pool = eg_org_dept_managers($db, eg_dept_subtree_ids($db, $deptId));
    $out = [];
    foreach ($pool as $p) {
        if ($maxLevel !== null && ($p['level'] === null || (int)$p['level'] > $maxLevel)) continue;
        $out[] = ['id'=>(int)$p['id'], 'user_cname'=>$p['user_cname']];
    }
    return $out;
}

function tool_calib_approver_pool_dept_or_user(PDO $db, int $submitterUid): array {
    $cfg = tool_calib_approval_cfg($db);
    if ($cfg['approver_user_id']) {
        try {
            $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
            $st->execute([$cfg['approver_user_id']]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            return $u ? [$u] : [];
        } catch (Throwable $e) { return []; }
    }
    if (!$cfg['approver_dept_id']) return [];
    $identity = eg_user_main_identity($db, $submitterUid);
    return tool_calib_dept_managers($db, $cfg['approver_dept_id'], $identity['level'] ?? null);
}

function tool_calib_approver_pool_auto_supervisor(PDO $db, int $submitterUid): array {
    $sup = eg_resolve_supervisor($db, $submitterUid);
    if (!$sup || (int)$sup === $submitterUid) return [];
    try {
        $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
        $st->execute([$sup]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ? [$u] : [];
    } catch (Throwable $e) { return []; }
}

function tool_calib_approver_pool_top_approver(PDO $db): array {
    $u = eg_org_user($db, 'top_approver');
    return $u ? [['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname']]] : [];
}

/** 核准人員池：依設定的核准鏈依序嘗試，取第一個有結果的方法；強制 SoD（送出者本人一律剔除）。 */
function tool_calib_approver_pool(PDO $db, int $submitterUid): array {
    $cfg = tool_calib_approval_cfg($db);
    foreach ($cfg['approver_chain'] as $method) {
        $pool = match ($method) {
            'dept_or_user'    => tool_calib_approver_pool_dept_or_user($db, $submitterUid),
            'auto_supervisor' => tool_calib_approver_pool_auto_supervisor($db, $submitterUid),
            'top_approver'    => tool_calib_approver_pool_top_approver($db),
            default => [],
        };
        $pool = array_values(array_filter($pool, fn($p) => (int)$p['id'] !== $submitterUid));
        if ($pool) return $pool;
    }
    return [];
}

/** 圖章模板（stamp_template）；id=0 或查無資料回 null，呼叫端沒拿到就用 EGStamp 預設樣式。 */
function tool_calib_stamp_tpl_get(PDO $db, int $tplId): ?array {
    if (!$tplId) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$tplId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}

/** 圖章模板下拉選單用清單（供設定頁挑選）。 */
function tool_calib_stamp_tpl_options(PDO $db): array {
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name
                           FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 通知（比照 review_form 的 rvf_notify：live_event + live_event_target + Web Push）。 */
function tool_calib_notify(PDO $db, int $refId, array $toUids, string $title, string $content, int $fromUid, string $refType, string $mode = 'sign'): int {
    $toUids = array_values(array_unique(array_map('intval', $toUids)));
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '量測儀器校驗', 1, ?, ?)")
           ->execute([$title, $content, $fromUid, $refType, $refId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
        foreach ($toUids as $tuid) $ins->execute([$eid, $tuid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 一批 batch_id 對應的「核准」關卡最新 approval_record（module='tool_calib_batch'）；回傳 [batch_id => record|null] */
function tool_calib_batch_approvals(PDO $db, array $batchIds): array {
    $batchIds = array_values(array_unique(array_filter(array_map('intval', $batchIds))));
    $out = [];
    foreach ($batchIds as $id) $out[$id] = null;
    if (!$batchIds) return $out;
    $in = implode(',', array_fill(0, count($batchIds), '?'));
    try {
        $st = $db->prepare("SELECT * FROM approval_record WHERE module='tool_calib_batch' AND level='approval'
                            AND entity_id IN ($in) ORDER BY id DESC");
        $st->execute($batchIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eid = (int)$r['entity_id'];
            if ($out[$eid] === null) $out[$eid] = $r;   // 每個 batch 只留最新一筆
        }
    } catch (Throwable $e) {}
    return $out;
}

/* ============================================================
 * 年度校驗紀錄 / 年度校驗計畫表
 * ============================================================ */
/** 日期位移月份（可負；月底 clamp） */
function tool_calib_shift_months(string $date, int $months): ?string {
    $t = strtotime(substr($date, 0, 10));
    if ($t === false) return null;
    $y = (int)date('Y', $t); $m = (int)date('n', $t); $d = (int)date('j', $t);
    $tm = ($y * 12 + ($m - 1)) + $months;
    $y = intdiv($tm, 12); $m = ($tm % 12) + 1;
    if ($m < 1) { $m += 12; $y -= 1; }
    $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    if ($d > $last) $d = $last;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/** 年度校驗紀錄（該年度完成的每一筆校驗；供查閱與列印） */
function tool_calib_year_records(PDO $db, int $year): array {
    $st = $db->prepare("SELECT c.calib_id, c.Tool_id, t.Tool_No, l.QC_Tool AS category_name,
                               c.due_date, c.calib_date, c.result, c.method, c.operator, c.cert_no,
                               c.next_due, c.note, c.batch_id, c.created_by_name,
                               b.reviewer_name, b.approval_status,
                               (SELECT COUNT(*) FROM qc_tool_calib_attach a
                                 JOIN qc_tool_calib_attach_map mp ON mp.attach_id=a.attach_id
                                WHERE a.status='active' AND a.batch_id=c.batch_id AND mp.Tool_id=c.Tool_id) AS attach_count,
                               (SELECT JSON_ARRAYAGG(JSON_OBJECT('doc_type', COALESCE(a.doc_type,'附件'), 'name', a.original_name))
                                  FROM qc_tool_calib_attach a JOIN qc_tool_calib_attach_map mp ON mp.attach_id=a.attach_id
                                 WHERE a.status='active' AND a.batch_id=c.batch_id AND mp.Tool_id=c.Tool_id) AS attach_json
                        FROM qc_tool_calibration c
                        JOIN qc_tool t ON t.Tool_id=c.Tool_id
                        LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                        LEFT JOIN qc_tool_calib_batch b ON b.batch_id=c.batch_id
                        WHERE YEAR(c.calib_date)=? AND COALESCE(l.calib_required,1)=1
                        ORDER BY c.calib_date, t.Tool_No");
    $st->execute([$year]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $approvals = tool_calib_batch_approvals($db, array_column($rows, 'batch_id'));
    foreach ($rows as &$r) {
        $r['attach_list'] = $r['attach_json'] ? (json_decode((string)$r['attach_json'], true) ?: []) : [];
        unset($r['attach_json']);
        $ap = $approvals[(int)($r['batch_id'] ?? 0)] ?? null;
        $r['approver_name'] = ($ap && $ap['status'] === 'approved') ? $ap['approver_name'] : null;
        $r['approved_at']   = ($ap && $ap['status'] === 'approved') ? $ap['decided_at'] : null;
    }
    unset($r);
    return $rows;
}

/**
 * 年度校驗計畫表：每支納管量具在該年度各月的「應校驗(計畫)」與「實際完成」
 * 計畫月份來源：①已完成/已排定紀錄的 due_date 落在該年度者 ②主檔 calibration_due 依週期往前後推算落在該年度者
 * 回傳 [['Tool_No','category_name','cycle','months'=>[1..12 => ['plan'=>bool,'done'=>date|null,'result'=>..]]], ...]
 */
function tool_calib_year_plan(PDO $db, int $year): array {
    $tools = $db->query("SELECT t.Tool_id, t.Tool_No, t.calibration_due, t.calib_cycle_months, t.calib_method,
                                l.QC_Tool AS category_name, l.sort_order
                         FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                         WHERE t.calib_managed=1 AND COALESCE(l.calib_required,1)=1
                         ORDER BY l.sort_order, t.Tool_No")->fetchAll(PDO::FETCH_ASSOC);
    if (!$tools) return [];

    // 該年度相關紀錄（依 due_date 或 calib_date 落在該年度者）
    $recs = [];
    $st = $db->prepare("SELECT Tool_id, due_date, calib_date, result FROM qc_tool_calibration
                        WHERE YEAR(due_date)=? OR YEAR(calib_date)=?");
    $st->execute([$year, $year]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $recs[(int)$r['Tool_id']][] = $r; }

    $out = [];
    foreach ($tools as $t) {
        $tid = (int)$t['Tool_id'];
        $cycle = $t['calib_cycle_months'] !== null ? (int)$t['calib_cycle_months'] : 0;
        $months = [];
        for ($m = 1; $m <= 12; $m++) $months[$m] = ['plan'=>false, 'done'=>null, 'result'=>null, 'late'=>false];

        // ① 紀錄：due_date 落在該年度 → 該月為計畫月，並帶入實際完成
        foreach ($recs[$tid] ?? [] as $r) {
            if (!empty($r['due_date']) && (int)substr($r['due_date'], 0, 4) === $year) {
                $m = (int)substr($r['due_date'], 5, 2);
                $months[$m]['plan'] = true;
                if (!empty($r['calib_date'])) {
                    $months[$m]['done'] = substr($r['calib_date'], 0, 10);
                    $months[$m]['result'] = $r['result'];
                    // 逾期＝超過到期月月底才完成（到期以月為單位）
                    $months[$m]['late'] = substr($r['calib_date'], 0, 10) > (string)tool_calib_month_end($r['due_date']);
                }
            } elseif (!empty($r['calib_date']) && (int)substr($r['calib_date'], 0, 4) === $year) {
                // 到期日不在本年度但完成日在本年度（提前/逾期補做）→ 完成標在完成月
                $m = (int)substr($r['calib_date'], 5, 2);
                if ($months[$m]['done'] === null) {
                    $months[$m]['done'] = substr($r['calib_date'], 0, 10);
                    $months[$m]['result'] = $r['result'];
                }
            }
        }
        // ② 主檔到期日依週期推算落在該年度者（尚未完成的計畫）
        $anchor = $t['calibration_due'] ? substr($t['calibration_due'], 0, 10) : null;
        if ($anchor) {
            if ($cycle > 0) {
                $d = $anchor; $guard = 0;
                while ((int)substr($d, 0, 4) > $year && $guard++ < 200) $d = tool_calib_shift_months($d, -$cycle);
                $guard = 0;
                while ((int)substr($d, 0, 4) < $year && $guard++ < 200) $d = tool_calib_shift_months($d, $cycle);
                $guard = 0;
                while ((int)substr($d, 0, 4) === $year && $guard++ < 60) {
                    $months[(int)substr($d, 5, 2)]['plan'] = true;
                    $d = tool_calib_shift_months($d, $cycle);
                }
            } elseif ((int)substr($anchor, 0, 4) === $year) {
                $months[(int)substr($anchor, 5, 2)]['plan'] = true;
            }
        }
        $out[] = ['Tool_id'=>$tid, 'Tool_No'=>$t['Tool_No'], 'category_name'=>$t['category_name'],
                  'cycle'=>$cycle ?: null, 'method'=>$t['calib_method'], 'months'=>$months];
    }
    return $out;
}

/* ============================================================
 * 校驗附件（鐵律5：DB 只存檔名；根路徑存 system_settings，完整路徑讀取當下現場組）
 * ============================================================ */
/** 附件設定：dir(以/結尾)、ext(允許副檔名陣列)、maxmb、types(文件類別陣列) */
function tool_calib_attach_cfg(PDO $db): array {
    $dir   = 'Z:/BOM/ERP/量測儀器校驗/';
    $ext   = 'pdf,jpg,jpeg,png,gif,webp,xlsx,xls,doc,docx,zip';
    $maxmb = 20;
    $types = '校驗報告,校驗證書,原始記錄,其他';
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM system_settings
                            WHERE setting_key IN ('tool_calib_attach_dir','tool_calib_attach_ext',
                                                  'tool_calib_attach_maxmb','tool_calib_attach_types')")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($rows['tool_calib_attach_dir']))   $dir   = trim($rows['tool_calib_attach_dir']);
        if (!empty($rows['tool_calib_attach_ext']))   $ext   = trim($rows['tool_calib_attach_ext']);
        if (isset($rows['tool_calib_attach_maxmb']) && (int)$rows['tool_calib_attach_maxmb'] > 0)
            $maxmb = (int)$rows['tool_calib_attach_maxmb'];
        if (!empty($rows['tool_calib_attach_types'])) $types = trim($rows['tool_calib_attach_types']);
    } catch (Throwable $e) { /* 表不存在 → 用預設 */ }
    if (!preg_match('#[/\\\\]$#', $dir)) $dir .= '/';
    $split = function ($s) {
        return array_values(array_filter(array_map('trim', preg_split('/[,、\s]+/u', $s)), function ($v) { return $v !== ''; }));
    };
    return ['dir'=>$dir, 'ext'=>array_map('strtolower', $split($ext)), 'ext_raw'=>$ext,
            'maxmb'=>$maxmb, 'types'=>$split($types), 'types_raw'=>$types];
}

/** 單一附件的完整實體路徑（唯一組路徑的地方，其他處一律呼叫本函式） */
function tool_calib_attach_file(PDO $db, string $fileName): string {
    $cfg = tool_calib_attach_cfg($db);
    return $cfg['dir'] . $fileName;
}

/** 懶惰清除：刪掉已過期的暫存附件（實體檔＋DB 列＋對應），list/meta 順路呼叫 */
function tool_calib_purge_temp_attach(PDO $db): void {
    try {
        $rows = $db->query("SELECT attach_id, file_name FROM qc_tool_calib_attach
                            WHERE status='temp' AND expire_at IS NOT NULL AND expire_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        $cfg = tool_calib_attach_cfg($db);
        foreach ($rows as $r) {
            $fp = $cfg['dir'] . $r['file_name'];
            if (is_file($fp)) @unlink($fp);
        }
        $in = implode(',', array_map(function ($r) { return (int)$r['attach_id']; }, $rows));
        $db->exec("DELETE FROM qc_tool_calib_attach_map WHERE attach_id IN ({$in})");
        $db->exec("DELETE FROM qc_tool_calib_attach WHERE attach_id IN ({$in})");
    } catch (Throwable $e) { /* 忽略清除失敗，不影響主流程 */ }
}

/** 取某批/某量具的附件（只取 active）；$toolId>0 時只回對應到該量具者 */
function tool_calib_attach_list(PDO $db, int $batchId, int $toolId = 0): array {
    if ($batchId <= 0 && $toolId <= 0) return [];
    $sql = "SELECT a.attach_id, a.batch_id, a.category_id, a.doc_type, a.file_name, a.original_name,
                   a.file_size, a.note, a.created_at, l.QC_Tool AS category_name,
                   (SELECT GROUP_CONCAT(t.Tool_No ORDER BY t.Tool_No SEPARATOR ', ')
                      FROM qc_tool_calib_attach_map m JOIN qc_tool t ON t.Tool_id=m.Tool_id
                     WHERE m.attach_id=a.attach_id) AS tool_nos
            FROM qc_tool_calib_attach a
            LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=a.category_id
            WHERE a.status='active'";
    $p = [];
    if ($batchId > 0) { $sql .= " AND a.batch_id=?"; $p[] = $batchId; }
    if ($toolId > 0)  { $sql .= " AND EXISTS(SELECT 1 FROM qc_tool_calib_attach_map m2 WHERE m2.attach_id=a.attach_id AND m2.Tool_id=?)"; $p[] = $toolId; }
    $sql .= " ORDER BY a.attach_id";
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ============================================================
 * 量具料號對應（qc_tool.purchase_spec_id → purchase_spec）
 * 使用者 2026-07-30 定案：不另建量具規格主檔，規格一律掛到「採購料號」
 *   purchase_item（品項＝量具類別，如「盤式分厘卡」）→ purchase_spec（規格變體＝採購料號）
 * 舊資料的規格是人工塞在 Tool_No 字串裡（例 `A-200-Q 電子(0-25mm)`），
 * 所以先「解析成草稿」給使用者確認、可改，確認後才寫入（不可直接寫）。
 * ============================================================ */
/**
 * 從量具編號解析出規格草稿
 *   spec  規格文字＝括號內容（半形/全形混用都處理，例 `B-007-Q (0-300mm）`）
 *   type  型式＝編號出現「電子」→ 電子，否則 機械
 *   model 型號／描述＝去掉編號本體與「電子(式)」後剩下的字（例 三點式、千分）；與類別同名時視為贅字捨去
 *   brand 廠牌＝編號看不出來，一律留空待使用者補
 */
function tool_calib_parse_tool_no(string $toolNo, string $catName = ''): array {
    $raw  = trim($toolNo);
    $spec = '';
    $rest = $raw;
    if (preg_match_all('/[（(]([^（()）]*)[)）]/u', $raw, $ms, PREG_SET_ORDER)) {
        $last = end($ms);                                   // 取最後一組括號＝規格
        $spec = trim($last[1]);
        $rest = trim(str_replace($last[0], ' ', $raw));
    }
    $type  = (mb_strpos($raw, '電子') !== false) ? '電子' : '機械';
    $model = (string)preg_replace('/^[A-Za-z0-9\-~_.\/\s]+/u', '', $rest);   // 去掉編號本體
    $model = trim(str_replace(['電子式', '電子'], '', $model));               // 型式已另存，避免重複
    if ($catName !== '' && $model === $catName) $model = '';
    return ['spec' => $spec, 'type' => $type, 'model' => $model, 'brand' => ''];
}

/**
 * 由草稿欄位組出 purchase_spec.spec_text（規格 型號 型式；空欄自動略過，上限 150 字）
 * **品牌不併進來**——品牌是獨立欄位 purchase_spec.brand（使用者 2026-07-30：品牌 ≠ 購買廠商，
 * 清單由採購維護），併進規格文字會讓同規格不同品牌無法分辨、也沒辦法依品牌查。
 */
function tool_calib_compose_spec_text(array $d): string {
    $parts = [];
    foreach (['spec', 'model'] as $k) {
        $v = trim((string)($d[$k] ?? ''));
        if ($v !== '') $parts[] = $v;
    }
    if (trim((string)($d['type'] ?? '')) === '電子') $parts[] = '電子';
    $s = trim((string)preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    return mb_substr($s, 0, 150);
}

/**
 * 全部量具的料號對應草稿（含已綁定者的現況，供畫面標示「已對應」）
 * 回傳每列：Tool_id / Tool_No / category_id / category_name / item_name(預設＝類別名)
 *          spec / type / model / brand / spec_text_preview / bound(0/1) / bound_code / bound_text / parsed(0=編號看不出規格)
 */
function tool_calib_spec_draft(PDO $db): array {
    $hasSpecTbl = false;
    try { $hasSpecTbl = (bool)$db->query("SHOW TABLES LIKE 'purchase_spec'")->fetchColumn(); } catch (Throwable $e) {}
    $hasBrandCol = false;
    if ($hasSpecTbl) {
        try { $hasBrandCol = (bool)$db->query("SHOW COLUMNS FROM purchase_spec LIKE 'brand'")->fetchColumn(); } catch (Throwable $e) {}
    }
    $sql = "SELECT t.Tool_id, t.Tool_No, t.QC_Tool_List_id, t.purchase_spec_id,
                   l.QC_Tool AS category_name" .
           ($hasSpecTbl ? ", ps.spec_code AS bound_code, ps.spec_text AS bound_text, pi.item_name AS bound_item" : "") .
           ($hasBrandCol ? ", ps.brand AS bound_brand" : "") . "
            FROM qc_tool t
            LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id = t.QC_Tool_List_id" .
           ($hasSpecTbl ? " LEFT JOIN purchase_spec ps ON ps.spec_id = t.purchase_spec_id
                            LEFT JOIN purchase_item pi ON pi.item_id = ps.item_id" : "") . "
            ORDER BY l.sort_order, t.Tool_No";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $cat = (string)($r['category_name'] ?? '');
        $p   = tool_calib_parse_tool_no((string)$r['Tool_No'], $cat);
        $d   = [
            'Tool_id'       => (int)$r['Tool_id'],
            'Tool_No'       => (string)$r['Tool_No'],
            'category_id'   => (string)$r['QC_Tool_List_id'],
            'category_name' => $cat,
            'item_name'     => $cat !== '' ? $cat : (string)$r['Tool_No'],
            'spec'          => $p['spec'],
            'type'          => $p['type'],
            'model'         => $p['model'],
            // 已綁定者沿用規格上目前的品牌，重跑草稿不會把使用者補過的品牌洗掉
            'brand'         => (string)($r['bound_brand'] ?? '') !== '' ? (string)$r['bound_brand'] : $p['brand'],
            'bound'         => $r['purchase_spec_id'] ? 1 : 0,
            'bound_code'    => (string)($r['bound_code'] ?? ''),
            'bound_text'    => trim(((string)($r['bound_item'] ?? '')) . ' '
                                  . ((string)($r['bound_brand'] ?? '')) . ' ' . ((string)($r['bound_text'] ?? ''))),
            'parsed'        => $p['spec'] !== '' ? 1 : 0,
        ];
        $d['spec_text_preview'] = tool_calib_compose_spec_text($d);
        $out[] = $d;
    }
    return $out;
}

/** 採購品類別清單（purchase_item.category_id 用；預設挑「品管 檢/量具」那一類） */
function tool_calib_purchase_categories(PDO $db): array {
    try {
        $rows = $db->query("SELECT category_id, category_code, category_name
                            FROM stock_item_categories ORDER BY category_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) { $r['category_id'] = (int)$r['category_id']; }
    return $rows;
}

/**
 * 是否看得到「採購料號代碼」(spec_code)——**只有採購看得懂料號 ID，其他人用中文品名／規格查就好**
 * （使用者 2026-07-30 指示）。判定沿用採購模組的口徑：系統管理者，或具 purchase_buy／purchase_admin
 * 功能碼（role_features 優先、舊 role_code 後援），不重寫一套權限規則。
 */
function tool_calib_can_see_spec_code(PDO $db, ?array $u, array $perms): bool {
    if (!$u) return false;
    if (!empty($perms['isAdmin'])) return true;
    $uid = (int)$u['id'];
    try {
        require_once __DIR__ . '/role_features_helper.php';
        $feat = rf_load_user_features_all($db, $uid);
        foreach (['purchase_admin', 'purchase_buy'] as $c) { if (rf_has_feature($feat, $c)) return true; }
    } catch (Throwable $e) { /* helper 不存在 → 只看舊 role_code */ }
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='purchase' AND r.role_code IN ('purchase_admin','purchase_buy')
                            UNION
                            SELECT 1 FROM user_department_position_map m
                            JOIN position_roles pr ON pr.position_id=m.position_id
                            JOIN roles r ON r.role_id=pr.role_id
                            WHERE m.user_id=? AND r.module='purchase' AND r.role_code IN ('purchase_admin','purchase_buy')
                            LIMIT 1");
        $st->execute([$uid, $uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** 單位清單（purchase_item.default_unit_id／purchase_spec.unit_id 用） */
function tool_calib_units(PDO $db): array {
    try {
        $rows = $db->query("SELECT unit_id, unit_name, unit_symbol FROM stock_units
                            WHERE is_active=1 ORDER BY sort_order, unit_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) { $r['unit_id'] = (int)$r['unit_id']; }
    return $rows;
}

/** 量具的預設單位＝**PCS**（使用者 2026-07-30 指定）；查不到 PCS 才回 0 由使用者選 */
function tool_calib_default_unit_id(PDO $db): int {
    try {
        $st = $db->prepare("SELECT unit_id FROM stock_units
                            WHERE is_active=1 AND (UPPER(unit_name)='PCS' OR UPPER(unit_symbol)='PCS')
                            ORDER BY unit_id LIMIT 1");
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** 預設採購品類別 id（優先 category_code='QC'，其次名稱含「量具」，都沒有回 0 由使用者選） */
function tool_calib_default_purchase_category(PDO $db): int {
    foreach (tool_calib_purchase_categories($db) as $c) {
        if (strtoupper(trim((string)$c['category_code'])) === 'QC') return (int)$c['category_id'];
    }
    foreach (tool_calib_purchase_categories($db) as $c) {
        if (mb_strpos((string)$c['category_name'], '量具') !== false) return (int)$c['category_id'];
    }
    return 0;
}

/* ============================================================
 * 清除測試資料（僅超級管理員；只清本模組的交易性資料，設定類一律保留）
 * ============================================================ */
/** 本模組可被清除的資料筆數（畫面先顯示，讓使用者知道要刪掉什麼） */
function tool_calib_clean_counts(PDO $db): array {
    $one = function (string $sql) use ($db): int {
        try { return (int)$db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    return [
        'calibration' => $one("SELECT COUNT(*) FROM qc_tool_calibration"),
        'batch'       => $one("SELECT COUNT(*) FROM qc_tool_calib_batch"),
        'attach'      => $one("SELECT COUNT(*) FROM qc_tool_calib_attach"),
        'attach_map'  => $one("SELECT COUNT(*) FROM qc_tool_calib_attach_map"),
        'tool_reset'  => $one("SELECT COUNT(*) FROM qc_tool
                               WHERE calib_cycle_months IS NOT NULL OR calibration_due IS NOT NULL
                                  OR calib_managed = 1 OR calib_method IS NOT NULL"),
        // 保留不動者（畫面同時列出，避免使用者誤以為會一起被清）
        'keep_category' => $one("SELECT COUNT(*) FROM qc_tool_list"),
        'keep_tab'      => $one("SELECT COUNT(*) FROM qc_tool_calib_tab"),
        'keep_staff'    => $one("SELECT COUNT(*) FROM qc_tool_calib_staff"),
        'keep_tool'     => $one("SELECT COUNT(*) FROM qc_tool"),
    ];
}

/**
 * 二次確認用：必須輸入 **員工 id=1 本人的密碼**。
 * 比對方式沿用專案既有慣例（user.user_password 明碼欄位 + hash_equals 定時比較），
 * 與 eg_leave_verify_superadmin_password() / 共用帳號代簽一致，不另發明一套。
 * 空密碼一律不放行（fail-closed）。
 */
function tool_calib_verify_superadmin_password(PDO $db, string $password): array {
    if ($password === '') return ['ok' => false, 'msg' => '請輸入最高權限帳號的密碼'];
    try {
        $st = $db->prepare("SELECT user_password FROM `user` WHERE id = 1 LIMIT 1");
        $st->execute();
        $real = $st->fetchColumn();
        if ($real === false) return ['ok' => false, 'msg' => '查無最高權限帳號'];
        if (!hash_equals((string)$real, $password)) return ['ok' => false, 'msg' => '密碼錯誤，未執行清除'];
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => '密碼驗證失敗，未執行清除'];
    }
    return ['ok' => true, 'msg' => ''];
}

/** page_change_log 一列（清除測試資料前後各記一次，確保破壞性操作可追溯） */
function tool_calib_log_change(PDO $db, string $summary, string $detail, string $who): void {
    try {
        $db->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                      VALUES ('views/QC/tool_calibration.php', ?, ?, NOW(), ?)")
           ->execute([mb_substr($summary, 0, 255), $detail, mb_substr($who, 0, 100)]);
    } catch (Throwable $e) { /* 記錄失敗不影響主流程 */ }
}

/* ============================================================
 * KPI 第18項計算：量測儀器按時校驗率（供 kpi_as_lib compute 呼叫）
 * 回傳 ['num'=>準時完成數, 'den'=>應校驗數, 'value'=>百分比或 null]
 * ============================================================ */
function tool_calib_kpi_compute(PDO $db, int $year, int $month, array $params): ?array {
    // 表尚未建立時（首次尚未進過校驗頁）→ 無資料
    try {
        $chk = $db->query("SHOW TABLES LIKE 'qc_tool_calibration'")->fetchColumn();
        if (!$chk) return ['num'=>0, 'den'=>0, 'value'=>null];
    } catch (Throwable $e) { return ['num'=>0, 'den'=>0, 'value'=>null]; }

    // grace_days 兼容兩種格式：矩陣路徑 {grace_days:{v,fe}}、試算路徑 {grace_days:number}
    $g = $params['grace_days'] ?? 0;
    if (is_array($g)) $g = $g['v'] ?? 0;
    $grace = is_numeric($g) ? max(0, (int)$g) : 0;

    $ms = sprintf('%04d-%02d-01', $year, $month);
    $me = date('Y-m-t', strtotime($ms));

    // 類別旗標「需校驗」：欄位存在才加條件（尚未升級 schema 時行為與加欄前相同）
    $catJoin = ''; $catCond = '';
    try {
        if ($db->query("SHOW COLUMNS FROM qc_tool_list LIKE 'calib_required'")->fetchColumn()) {
            $catJoin = " LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id ";
            $catCond = " AND COALESCE(l.calib_required,1)=1 ";
        }
    } catch (Throwable $e) { /* 無此表/欄位 → 不加條件 */ }

    // (1) 已完成紀錄：本次到期日落在當月者 → 計入 den；準時(calib_date ≤ due+寬限)者計入 num
    $st = $db->prepare("SELECT c.due_date, c.calib_date
                        FROM qc_tool_calibration c
                        JOIN qc_tool t ON t.Tool_id=c.Tool_id
                        $catJoin
                        WHERE t.calib_managed=1 AND c.due_date IS NOT NULL
                          $catCond
                          AND c.due_date BETWEEN ? AND ?");
    $st->execute([$ms, $me]);
    $den = 0; $num = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $den++;
        // 到期以「月」為單位 → 準時＝該月月底前完成（再加寬限天數）
        $limit = tool_calib_month_end($r['due_date']);
        if ($grace > 0) $limit = date('Y-m-d', strtotime($limit . " +$grace days"));
        if (!empty($r['calib_date']) && substr($r['calib_date'], 0, 10) <= $limit) $num++;
    }

    // (2) 尚待完成的到期（主檔 calibration_due 落在當月且尚無對應完成紀錄）→ 計入 den，未準時
    $st = $db->prepare("SELECT COUNT(*) FROM qc_tool t
                        $catJoin
                        WHERE t.calib_managed=1 AND t.calibration_due IS NOT NULL
                          $catCond
                          AND t.calibration_due BETWEEN ? AND ?
                          AND NOT EXISTS (SELECT 1 FROM qc_tool_calibration c
                                          WHERE c.Tool_id=t.Tool_id AND c.due_date=t.calibration_due)");
    $st->execute([$ms, $me]);
    $den += (int)$st->fetchColumn();

    return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
}
