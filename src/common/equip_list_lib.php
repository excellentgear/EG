<?php
/**
 * 設備一覽表 共用邏輯（2026-08-14 建立，使用者要求新增「機台設備一覽表」「檢驗設備一覽表」兩份 AS9100 文件）
 *
 * 機台設備一覽表：沿用既有 machine_list 主檔（另加 manufacturer 欄位）。
 * 檢驗設備一覽表：沿用既有 qc_tool 主檔（views/QC/tool_calibration.php 既有模組），本庫不碰它的欄位/權限，
 *   qc_tool 需要的新增欄位（manufacturer/spec/purchase_date/note）與角色沿用 tool_calib_lib.php 既有 schema/perms，
 *   本庫只提供「保管人員歷程」「履歴表(故障維修紀錄)」「年度整份送簽」三種兩邊都要用的共用邏輯，用 $equipType
 *   ('machine' 或 'qc_tool') 參數化，避免同一套邏輯寫兩份。
 *
 * 核准鏈／SoD 迴避比照 ai-rules/19 第五節，唯一參考實作 vendor_audit_lib.php 的 vendor_audit_plan_approver_pool()。
 */

require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/asdoc_lib.php';

const EQUIP_LIST_APPROVER_METHODS = ['dept_or_user', 'auto_supervisor', 'top_approver'];

/** 四個固定 AS 文件綁定模組代碼（equip_type => ['list'=>清單模組代碼, 'service'=>履歴表模組代碼]）
 *  qc_tool 這組刻意跟 src/store/ToolCalib_API.php 的 TOOL_CALIB_ASDOC_MODULES 用同一組字串——
 *  該頁的 asdoc_list/save_asdoc 是既有共用 action，檢驗設備一覽表整合進該頁沿用同一套，
 *  綁定值存放的 module 代碼兩邊必須完全一致，否則存進去卻讀不出來（已在CLI測試中踩過這個坑）。 */
const EQUIP_ASDOC_MODULES = [
    'machine' => ['list' => 'equip_machine_list', 'service' => 'equip_machine_service_log'],
    'qc_tool' => ['list' => 'tool_calib_equip_list', 'service' => 'tool_calib_equip_service'],
];

/* ============================================================
 * Schema
 * ============================================================ */
function equip_list_ensure_schema(PDO $db): void {
    try { $db->exec("ALTER TABLE machine_list ADD COLUMN manufacturer VARCHAR(100) NULL COMMENT '製造商'"); } catch (Throwable $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS equip_assignee_history (
        hist_id INT AUTO_INCREMENT PRIMARY KEY,
        equip_type VARCHAR(10) NOT NULL COMMENT 'machine / qc_tool',
        equip_ref_id INT NOT NULL COMMENT '對應 machine_list.machine_id 或 qc_tool.Tool_id',
        user_id INT NOT NULL COMMENT '保養人/保管人員 user.id',
        start_date DATE NOT NULL,
        end_date DATE NULL COMMENT 'NULL=現任',
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        updated_at TIMESTAMP NULL, updated_by INT NULL, updated_by_name VARCHAR(50) NULL,
        KEY idx_equip (equip_type, equip_ref_id, start_date),
        KEY idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='機台保養人/檢驗設備保管人員 指派歷程(日期區間)'");

    $db->exec("CREATE TABLE IF NOT EXISTS equip_service_log (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        equip_type VARCHAR(10) NOT NULL,
        equip_ref_id INT NOT NULL,
        service_date DATE NOT NULL COMMENT '日期',
        vendor_name VARCHAR(100) NULL COMMENT '廠商',
        problem_desc VARCHAR(500) NULL COMMENT '問題',
        solution_desc VARCHAR(500) NULL COMMENT '解決方式',
        executor_name VARCHAR(50) NULL COMMENT '執行者',
        executor_user_id INT NULL,
        approved_by_name VARCHAR(50) NULL, approved_by_uid INT NULL,
        approved_at DATETIME NULL, approved_date DATE NULL, approved_is_deputy TINYINT NULL,
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        updated_at TIMESTAMP NULL, updated_by INT NULL, updated_by_name VARCHAR(50) NULL,
        KEY idx_equip (equip_type, equip_ref_id, service_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='機台/檢驗設備履歴表(故障維修紀錄)'");

    // 2026-08-26 追加：廠商／執行者改為可從主檔挑選（廠商＝maker_list、廠內人員＝user），
    // 但顯示用的名稱仍存在原本的 vendor_name/executor_name——歷史維修紀錄要留下「當時寫的是哪一家」，
    // 主檔事後改名或停用都不該讓舊紀錄變空白；id 欄位只是額外的可追溯線索，不是顯示來源。
    foreach ([
        "ALTER TABLE equip_service_log ADD COLUMN vendor_id VARCHAR(11) NULL COMMENT '對應 maker_list.maker_id_no，自由輸入時為 NULL'",
        "ALTER TABLE equip_service_log ADD COLUMN executor_kind VARCHAR(10) NULL COMMENT 'user=廠內人員 / vendor=廠商 / NULL=自由輸入'",
        "ALTER TABLE equip_service_log ADD COLUMN executor_vendor_id VARCHAR(11) NULL COMMENT '執行者是廠商時對應 maker_list.maker_id_no'",
    ] as $ddl) { try { $db->exec($ddl); } catch (Throwable $e) {} }

    // 預存片語：問題／解決方式在現場其實高度重複（「主軸異音」「更換皮帶」…），每次重打既慢又寫法不一致，
    // 統計時同一件事會被當成好幾種。故做成可新增/修改/刪除的常用語庫，填表時直接帶出。
    $db->exec("CREATE TABLE IF NOT EXISTS equip_service_phrase (
        phrase_id INT AUTO_INCREMENT PRIMARY KEY,
        equip_type VARCHAR(10) NOT NULL COMMENT 'machine / qc_tool',
        kind VARCHAR(10) NOT NULL COMMENT 'problem=問題 / solution=解決方式',
        content VARCHAR(500) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        updated_at TIMESTAMP NULL, updated_by INT NULL, updated_by_name VARCHAR(50) NULL,
        UNIQUE KEY uk_phrase (equip_type, kind, content(190)),
        KEY idx_kind (equip_type, kind, is_active, sort_order)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='機台/檢驗設備履歴表 問題與解決方式預存片語'");

    $db->exec("CREATE TABLE IF NOT EXISTS equip_list_plan_lock (
        equip_type VARCHAR(10) NOT NULL,
        year INT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'approved' COMMENT 'pending/approved/rejected',
        snapshot_json MEDIUMTEXT NULL COMMENT '送出當下完整清單快照，供列印與事後稽核回放',
        submit_date DATE NOT NULL, submitted_at DATETIME NOT NULL,
        submitted_by INT NULL, submitted_by_name VARCHAR(50) NULL,
        approved_by_name VARCHAR(50) NULL, approved_at DATETIME NULL, approved_date DATE NULL,
        PRIMARY KEY (equip_type, year)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='機台/檢驗設備一覽表-年度整份送簽'");

    // 機台設備一覽表專屬角色(module='equip_machine')；檢驗設備一覽表沿用既有 tool_calib_perms()，不另建角色。
    foreach ([['equip_machine_view','設備唯讀'],['equip_machine_edit','設備登錄'],['equip_machine_admin','設備管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='equip_machine' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'equip_machine')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ============================================================
 * 權限（machine 頁專用；比照 vendor_audit_perms/tool_calib_perms 同一套寫法）
 * ============================================================ */
function equip_list_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function equip_list_has_role(PDO $db, int $uid, string $module, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module=? AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid, $module], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module=? AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid, $module], $codes));
    return (bool)$st->fetchColumn();
}

/** module='equip_machine' 固定；回傳 ['isAdmin','canAdmin','canEdit','canView'] */
function equip_list_perms(PDO $db, string $module, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || equip_list_has_role($db, $uid, $module, [$module.'_admin']);
    $canEdit  = $canAdmin || equip_list_has_role($db, $uid, $module, [$module.'_edit']);
    $canView  = $canEdit  || equip_list_has_role($db, $uid, $module, [$module.'_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/* ============================================================
 * 保養人/保管人員 指派歷程
 * ============================================================ */

/** 現任者（含在職狀態偵測；查無現任回 null，呼叫端要顯示「尚未指派」） */
function equip_list_current_assignee(PDO $db, string $equipType, int $refId): ?array {
    $st = $db->prepare("SELECT h.*, u.user_cname, u.state
                        FROM equip_assignee_history h JOIN `user` u ON u.id=h.user_id
                        WHERE h.equip_type=? AND h.equip_ref_id=?
                          AND h.start_date<=CURDATE() AND (h.end_date IS NULL OR h.end_date>=CURDATE())
                        ORDER BY h.start_date DESC, h.hist_id DESC LIMIT 1");
    $st->execute([$equipType, $refId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['state'] = (int)$r['state'];
    $r['is_resigned'] = ($r['state'] === 0);
    $r['on_leave'] = in_array($r['state'], [2, 3], true);
    $r['state_label'] = eg_people_state_label($r['state']);
    return $r;
}

/** 批次版現任者查詢（給一覽表列表用，避免逐列各查一次） */
function equip_list_resigned_map(PDO $db, string $equipType, array $refIds): array {
    $refIds = array_values(array_unique(array_filter(array_map('intval', $refIds))));
    if (!$refIds) return [];
    $in = implode(',', array_fill(0, count($refIds), '?'));
    $st = $db->prepare("SELECT h.equip_ref_id, h.user_id, u.user_cname, u.state, h.start_date, h.hist_id
                        FROM equip_assignee_history h JOIN `user` u ON u.id=h.user_id
                        WHERE h.equip_type=? AND h.equip_ref_id IN ($in)
                          AND h.start_date<=CURDATE() AND (h.end_date IS NULL OR h.end_date>=CURDATE())
                        ORDER BY h.start_date DESC, h.hist_id DESC");
    $st->execute(array_merge([$equipType], $refIds));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rid = (int)$r['equip_ref_id'];
        if (isset($out[$rid])) continue; // 每設備只取最新一筆
        $state = (int)$r['state'];
        $out[$rid] = [
            'user_id' => (int)$r['user_id'], 'user_cname' => $r['user_cname'], 'state' => $state,
            'is_resigned' => ($state === 0), 'on_leave' => in_array($state, [2, 3], true),
            'state_label' => eg_people_state_label($state),
        ];
    }
    return $out;
}

/** 完整歷程（含每筆的在職狀態，供補印「(已離職)」標記） */
function equip_list_assignee_history(PDO $db, string $equipType, int $refId): array {
    $st = $db->prepare("SELECT h.*, u.user_cname, u.state
                        FROM equip_assignee_history h JOIN `user` u ON u.id=h.user_id
                        WHERE h.equip_type=? AND h.equip_ref_id=?
                        ORDER BY h.start_date DESC, h.hist_id DESC");
    $st->execute([$equipType, $refId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['state'] = (int)$r['state'];
        $r['state_label'] = eg_people_state_label($r['state']);
    }
    return $rows;
}

/** 指派新保養人/保管人員：交易內把現任那筆結束、插入新一筆接續 */
function equip_list_assign_new(PDO $db, string $equipType, int $refId, int $userId, string $startDate, ?string $note, int $byUid, string $byName): array {
    // 後端防呆二次檢查（前端下拉本來就用 eg_people_list() 排除離職者，這裡防繞過前端直打API）
    $st = $db->prepare("SELECT state FROM `user` WHERE id=?");
    $st->execute([$userId]);
    $state = $st->fetchColumn();
    if ($state === false) throw new RuntimeException('找不到該人員');
    if (in_array((int)$state, array_map('intval', explode(',', EG_PEOPLE_EXCLUDE_STATES)), true)) {
        throw new RuntimeException('該人員目前狀態不可指派為保養人/保管人員');
    }
    $db->beginTransaction();
    try {
        $cur = $db->prepare("SELECT hist_id FROM equip_assignee_history
                             WHERE equip_type=? AND equip_ref_id=? AND end_date IS NULL
                               AND start_date<=CURDATE() ORDER BY start_date DESC, hist_id DESC LIMIT 1");
        $cur->execute([$equipType, $refId]);
        $curId = $cur->fetchColumn();
        if ($curId) {
            $db->prepare("UPDATE equip_assignee_history SET end_date=DATE_SUB(?, INTERVAL 1 DAY), updated_at=NOW(), updated_by=?, updated_by_name=? WHERE hist_id=?")
               ->execute([$startDate, $byUid, $byName, $curId]);
        }
        $db->prepare("INSERT INTO equip_assignee_history (equip_type, equip_ref_id, user_id, start_date, end_date, note, created_by, created_by_name)
                      VALUES (?,?,?,?,NULL,?,?,?)")
           ->execute([$equipType, $refId, $userId, $startDate, $note, $byUid, $byName]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return equip_list_current_assignee($db, $equipType, $refId);
}

/** 管理員補登/校正歷史區間；存檔前檢查跟同設備既有其他區間是否重疊 */
function equip_list_history_save(PDO $db, array $row, int $byUid, string $byName): array {
    $equipType = (string)$row['equip_type'];
    $refId = (int)$row['equip_ref_id'];
    $userId = (int)$row['user_id'];
    $startDate = (string)$row['start_date'];
    $endDate = trim((string)($row['end_date'] ?? '')) ?: null;
    $histId = (int)($row['hist_id'] ?? 0);
    if (!$refId || !$userId || !$startDate) throw new RuntimeException('資料不齊全');
    if ($endDate !== null && $endDate < $startDate) throw new RuntimeException('結束日不可早於起始日');

    $others = $db->prepare("SELECT hist_id, start_date, end_date FROM equip_assignee_history
                            WHERE equip_type=? AND equip_ref_id=?" . ($histId ? " AND hist_id<>?" : ""));
    $params = [$equipType, $refId];
    if ($histId) $params[] = $histId;
    $others->execute($params);
    foreach ($others->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $oEnd = $o['end_date'] ?: '9999-12-31';
        $newEnd = $endDate ?: '9999-12-31';
        if ($startDate <= $oEnd && $o['start_date'] <= $newEnd) {
            throw new RuntimeException('日期區間與同一設備既有紀錄重疊（'.$o['start_date'].' ~ '.($o['end_date']?:'現任').'）');
        }
    }
    $note = trim((string)($row['note'] ?? ''));
    if ($histId) {
        $db->prepare("UPDATE equip_assignee_history SET user_id=?, start_date=?, end_date=?, note=?, updated_at=NOW(), updated_by=?, updated_by_name=? WHERE hist_id=?")
           ->execute([$userId, $startDate, $endDate, $note, $byUid, $byName, $histId]);
    } else {
        $db->prepare("INSERT INTO equip_assignee_history (equip_type, equip_ref_id, user_id, start_date, end_date, note, created_by, created_by_name)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$equipType, $refId, $userId, $startDate, $endDate, $note, $byUid, $byName]);
        $histId = (int)$db->lastInsertId();
    }
    $st = $db->prepare("SELECT * FROM equip_assignee_history WHERE hist_id=?");
    $st->execute([$histId]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function equip_list_history_delete(PDO $db, int $histId): void {
    $db->prepare("DELETE FROM equip_assignee_history WHERE hist_id=?")->execute([$histId]);
}

/* ============================================================
 * 履歴表（故障維修紀錄）
 * ============================================================ */
function equip_service_log_list(PDO $db, string $equipType, int $refId): array {
    $st = $db->prepare("SELECT * FROM equip_service_log WHERE equip_type=? AND equip_ref_id=? ORDER BY service_date DESC, log_id DESC");
    $st->execute([$equipType, $refId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function equip_service_log_save(PDO $db, array $row, int $byUid, string $byName): array {
    $equipType = (string)$row['equip_type'];
    $refId = (int)$row['equip_ref_id'];
    $serviceDate = trim((string)($row['service_date'] ?? ''));
    if (!$refId || !$serviceDate) throw new RuntimeException('設備與日期為必填');
    $logId = (int)($row['log_id'] ?? 0);
    $vendor = trim((string)($row['vendor_name'] ?? ''));
    $vendorId = trim((string)($row['vendor_id'] ?? ''));
    $problem = trim((string)($row['problem_desc'] ?? ''));
    $solution = trim((string)($row['solution_desc'] ?? ''));
    $note = trim((string)($row['note'] ?? ''));
    // 執行者三種來源：廠內人員(user)／廠商(vendor)／自由輸入。後端一律依 kind 自行重算顯示名稱與 id，
    // 不採信前端送來的組合（鐵律8：前端擋一次、後端同規則再擋一次），否則會留下「掛著某人的 user_id、
    // 名字卻是別人」這種事後查不出來的紀錄。
    $execKind = trim((string)($row['executor_kind'] ?? ''));
    if (!in_array($execKind, ['user', 'vendor'], true)) $execKind = '';
    $executor = trim((string)($row['executor_name'] ?? ''));
    $executorUid = null;
    $executorVendorId = null;
    if ($execKind === 'user') {
        $execUid = (int)($row['executor_user_id'] ?? 0);
        if (!$execUid) throw new RuntimeException('執行者選了「廠內人員」，請從名單挑選人員');
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=? LIMIT 1");
        $st->execute([$execUid]);
        $nm = $st->fetchColumn();
        if ($nm === false) throw new RuntimeException('找不到該人員，請重新挑選');
        $executorUid = $execUid;
        $executor = (string)$nm;
    } elseif ($execKind === 'vendor') {
        $execVid = trim((string)($row['executor_vendor_id'] ?? ''));
        if ($execVid !== '') {
            $st = $db->prepare("SELECT maker_id_no FROM maker_list WHERE maker_id_no=? LIMIT 1");
            $st->execute([$execVid]);
            if ($st->fetchColumn() === false) throw new RuntimeException('找不到該廠商，請重新挑選');
            $executorVendorId = $execVid;
        }
        if ($executor === '') throw new RuntimeException('執行者選了「廠商」，請填廠商名稱');
    }
    // 廠商代號同理：對不上主檔就只留文字，不留一個查不到的假 id
    if ($vendorId !== '') {
        $st = $db->prepare("SELECT maker_id_no FROM maker_list WHERE maker_id_no=? LIMIT 1");
        $st->execute([$vendorId]);
        if ($st->fetchColumn() === false) $vendorId = '';
    }
    if ($vendor === '') $vendorId = '';
    if ($logId) {
        $db->prepare("UPDATE equip_service_log SET service_date=?, vendor_name=?, vendor_id=?, problem_desc=?, solution_desc=?,
                        executor_name=?, executor_user_id=?, executor_kind=?, executor_vendor_id=?, note=?,
                        updated_at=NOW(), updated_by=?, updated_by_name=?
                      WHERE log_id=?")
           ->execute([$serviceDate, $vendor, $vendorId ?: null, $problem, $solution, $executor, $executorUid,
                      $execKind ?: null, $executorVendorId, $note, $byUid, $byName, $logId]);
    } else {
        $db->prepare("INSERT INTO equip_service_log (equip_type, equip_ref_id, service_date, vendor_name, vendor_id, problem_desc, solution_desc,
                        executor_name, executor_user_id, executor_kind, executor_vendor_id, note, created_by, created_by_name)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$equipType, $refId, $serviceDate, $vendor, $vendorId ?: null, $problem, $solution, $executor,
                      $executorUid, $execKind ?: null, $executorVendorId, $note, $byUid, $byName]);
        $logId = (int)$db->lastInsertId();
    }
    $st = $db->prepare("SELECT * FROM equip_service_log WHERE log_id=?");
    $st->execute([$logId]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function equip_service_log_delete(PDO $db, int $logId): void {
    $db->prepare("DELETE FROM equip_service_log WHERE log_id=?")->execute([$logId]);
}

/** 單筆履歴表記錄核准（簽章欄，非整份送簽通知機制） */
function equip_service_log_approve(PDO $db, int $logId, int $uid, string $name, string $approvedDate, bool $isDeputy): void {
    $db->prepare("UPDATE equip_service_log SET approved_by_name=?, approved_by_uid=?, approved_at=NOW(), approved_date=?, approved_is_deputy=? WHERE log_id=?")
       ->execute([$name, $uid, $approvedDate, $isDeputy ? 1 : 0, $logId]);
}

/* ============================================================
 * 履歴表「問題／解決方式」預存片語（常用語庫）
 * ============================================================ */
/** 兩種片語類別；要加類別請改這裡一處，禁止在別處再寫一份對照表（鐵律4） */
function equip_service_phrase_kinds(): array {
    return ['problem' => '問題', 'solution' => '解決方式'];
}

function equip_service_phrase_list(PDO $db, string $equipType, ?string $kind = null, bool $includeInactive = false): array {
    $sql = "SELECT * FROM equip_service_phrase WHERE equip_type=?";
    $params = [$equipType];
    if ($kind !== null && $kind !== '') {
        if (!array_key_exists($kind, equip_service_phrase_kinds())) throw new RuntimeException('片語類別不正確');
        $sql .= " AND kind=?"; $params[] = $kind;
    }
    if (!$includeInactive) $sql .= " AND is_active=1";
    $sql .= " ORDER BY kind, sort_order, phrase_id";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['phrase_id'] = (int)$r['phrase_id'];
        $r['is_active'] = (int)$r['is_active'];
        $r['sort_order'] = (int)$r['sort_order'];
    }
    unset($r);
    return $rows;
}

function equip_service_phrase_save(PDO $db, array $row, int $byUid, string $byName): array {
    $equipType = (string)($row['equip_type'] ?? '');
    $kind = (string)($row['kind'] ?? '');
    if (!array_key_exists($kind, equip_service_phrase_kinds())) throw new RuntimeException('片語類別不正確');
    $content = trim((string)($row['content'] ?? ''));
    if ($content === '') throw new RuntimeException('內容不可空白');
    if (mb_strlen($content) > 500) throw new RuntimeException('內容不可超過 500 字（目前 ' . mb_strlen($content) . ' 字）');
    $sort = (int)($row['sort_order'] ?? 0);
    $active = array_key_exists('is_active', $row) ? (!empty($row['is_active']) ? 1 : 0) : 1;
    $phraseId = (int)($row['phrase_id'] ?? 0);

    // 同一類別內不可重複：重複的常用語會讓下拉出現兩筆一模一樣的選項，使用者只會覺得系統壞了
    $st = $db->prepare("SELECT phrase_id FROM equip_service_phrase WHERE equip_type=? AND kind=? AND content=? LIMIT 1");
    $st->execute([$equipType, $kind, $content]);
    $dup = $st->fetchColumn();
    if ($dup !== false && (int)$dup !== $phraseId) throw new RuntimeException('這句常用語已經存在，不必重複新增');

    if ($phraseId) {
        $db->prepare("UPDATE equip_service_phrase SET kind=?, content=?, sort_order=?, is_active=?,
                        updated_at=NOW(), updated_by=?, updated_by_name=? WHERE phrase_id=? AND equip_type=?")
           ->execute([$kind, $content, $sort, $active, $byUid, $byName, $phraseId, $equipType]);
    } else {
        $db->prepare("INSERT INTO equip_service_phrase (equip_type, kind, content, sort_order, is_active, created_by, created_by_name)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$equipType, $kind, $content, $sort, $active, $byUid, $byName]);
        $phraseId = (int)$db->lastInsertId();
    }
    $st = $db->prepare("SELECT * FROM equip_service_phrase WHERE phrase_id=?");
    $st->execute([$phraseId]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function equip_service_phrase_delete(PDO $db, string $equipType, int $phraseId): void {
    $db->prepare("DELETE FROM equip_service_phrase WHERE phrase_id=? AND equip_type=?")->execute([$phraseId, $equipType]);
}

/* ============================================================
 * 年度整份送簽（比照 vendor_audit_plan_lock，不鎖主檔只存證+凍結快照）
 * ============================================================ */
function equip_list_plan_sign_setting(PDO $db, string $equipType): array {
    $raw = json_decode((string)_equip_setting_get($db, 'EQUIP_LIST_SIGN_'.strtoupper($equipType), ''), true);
    return ['need' => (is_array($raw) && !empty($raw['need'])) ? 1 : 0];
}
function equip_list_plan_sign_save_setting(PDO $db, string $equipType, int $need): void {
    _equip_setting_set($db, 'EQUIP_LIST_SIGN_'.strtoupper($equipType), json_encode(['need'=>$need?1:0]));
}
function equip_list_plan_approver_chain(PDO $db, string $equipType): array {
    $raw = json_decode((string)_equip_setting_get($db, 'EQUIP_LIST_APPROVER_CHAIN_'.strtoupper($equipType), ''), true);
    $chain = is_array($raw) ? array_values(array_filter($raw, fn($m) => in_array($m, EQUIP_LIST_APPROVER_METHODS, true))) : [];
    return $chain ?: EQUIP_LIST_APPROVER_METHODS;
}
function equip_list_plan_approver_chain_save(PDO $db, string $equipType, array $chain): void {
    $chain = array_values(array_unique(array_filter($chain, fn($m) => in_array($m, EQUIP_LIST_APPROVER_METHODS, true))));
    _equip_setting_set($db, 'EQUIP_LIST_APPROVER_CHAIN_'.strtoupper($equipType), json_encode($chain));
}
function _equip_setting_get(PDO $db, string $key, string $default): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}
function _equip_setting_set(PDO $db, string $key, string $value): void {
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $st->execute([$key, $value]);
}

/** org_role_lib 綁定 role_key：machine=equip_machine_list_approver / qc_tool=equip_qc_list_approver */
function equip_list_approver_role_key(string $equipType): string {
    return $equipType === 'qc_tool' ? 'equip_qc_list_approver' : 'equip_machine_list_approver';
}

function equip_list_plan_approver_pool_dept_or_user(PDO $db, string $equipType, int $submitterUid): array {
    $roleKey = equip_list_approver_role_key($equipType);
    $bind = eg_org_bindings($db)[$roleKey] ?? null;
    $deptId = !empty($bind['dept_id']) ? (int)$bind['dept_id'] : null;
    $userId = !empty($bind['user_id']) ? (int)$bind['user_id'] : null;
    if ($userId) {
        try {
            $st = $db->prepare("SELECT id, user_cname FROM `user` WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
            $st->execute([$userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            return $u ? [$u] : [];
        } catch (Throwable $e) { return []; }
    }
    if (!$deptId) {
        $doc = equip_list_bound_asdoc($db, $equipType, 'list');
        if ($doc && !empty($doc['department_id'])) $deptId = (int)$doc['department_id'];
    }
    if (!$deptId) return [];
    $identity = eg_user_main_identity($db, $submitterUid);
    $submitterLevel = $identity['level'] ?? null;
    $deptIds = eg_dept_subtree_ids($db, $deptId);
    if (!$deptIds) return [];
    try {
        $in = implode(',', array_fill(0, count($deptIds), '?'));
        $st = $db->prepare("SELECT u.id, u.user_cname, MIN(pl.level) AS lvl
                            FROM user_department_position_map m
                            JOIN `user` u ON u.id=m.user_id
                            JOIN position_level pl ON pl.position_id=m.position_id AND pl.level IS NOT NULL
                            WHERE m.department_id IN ($in) AND COALESCE(u.state,1) NOT IN (0,90)
                            GROUP BY u.id, u.user_cname");
        $st->execute($deptIds);
        $pool = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($submitterLevel === null || (int)$r['lvl'] <= (int)$submitterLevel) {
                $pool[] = ['id'=>(int)$r['id'], 'user_cname'=>$r['user_cname']];
            }
        }
        return $pool;
    } catch (Throwable $e) { return []; }
}
function equip_list_plan_approver_pool_auto_supervisor(PDO $db, int $submitterUid): array {
    $supId = eg_resolve_supervisor($db, $submitterUid);
    if (!$supId || (int)$supId === $submitterUid) return [];
    try {
        $st = $db->prepare("SELECT id, user_cname FROM `user` WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
        $st->execute([$supId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ? [$u] : [];
    } catch (Throwable $e) { return []; }
}
function equip_list_plan_approver_pool_top_approver(PDO $db): array {
    $u = eg_org_user($db, 'top_approver');
    return $u ? [['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname']]] : [];
}

/** 依優先序鏈依序嘗試，取第一個有結果的方法；強制濾除送出者本人(SoD) */
function equip_list_plan_approver_pool(PDO $db, string $equipType, int $submitterUid): array {
    foreach (equip_list_plan_approver_chain($db, $equipType) as $method) {
        $pool = match ($method) {
            'dept_or_user'    => equip_list_plan_approver_pool_dept_or_user($db, $equipType, $submitterUid),
            'auto_supervisor' => equip_list_plan_approver_pool_auto_supervisor($db, $submitterUid),
            'top_approver'    => equip_list_plan_approver_pool_top_approver($db),
            default => [],
        };
        $pool = array_values(array_filter($pool, fn($p) => (int)$p['id'] !== $submitterUid));
        if ($pool) return $pool;
    }
    return [];
}

function equip_list_plan_lock_get(PDO $db, string $equipType, int $year): ?array {
    $st = $db->prepare("SELECT * FROM equip_list_plan_lock WHERE equip_type=? AND year=?");
    $st->execute([$equipType, $year]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 送出年度整份清單：免簽核直接視為已核准(核准人印送出者上一階主管，避免球員兼裁判)；需簽核則待核准 */
function equip_list_plan_submit(PDO $db, string $equipType, int $year, string $submitDate, array $snapshotRows, int $byUid, string $byName): array {
    $need = equip_list_plan_sign_setting($db, $equipType)['need'];
    $status = $need ? 'pending' : 'approved';
    $approvedName = null; $approvedAt = null; $approvedDate = null;
    if (!$need) {
        $supId = eg_resolve_supervisor($db, $byUid);
        $approvedName = $byName;
        if ($supId && (int)$supId !== $byUid) {
            $st0 = $db->prepare("SELECT user_cname FROM `user` WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
            $st0->execute([$supId]);
            $approvedName = $st0->fetchColumn() ?: $byName;
        }
        $approvedAt = date('Y-m-d H:i:s');
        $approvedDate = $submitDate;
    }
    $snapshotJson = json_encode(array_values($snapshotRows), JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("INSERT INTO equip_list_plan_lock (equip_type, year, status, snapshot_json, submit_date, submitted_at, submitted_by, submitted_by_name, approved_by_name, approved_at, approved_date)
                        VALUES (?,?,?,?,?,NOW(),?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE status=VALUES(status), snapshot_json=VALUES(snapshot_json), submit_date=VALUES(submit_date), submitted_at=NOW(),
                            submitted_by=VALUES(submitted_by), submitted_by_name=VALUES(submitted_by_name),
                            approved_by_name=VALUES(approved_by_name), approved_at=VALUES(approved_at), approved_date=VALUES(approved_date)");
    $st->execute([$equipType, $year, $status, $snapshotJson, $submitDate, $byUid, $byName, $approvedName, $approvedAt, $approvedDate]);
    return equip_list_plan_lock_get($db, $equipType, $year);
}

/** AS 文件綁定（$kind='list'|'service'） */
function equip_list_bound_asdoc(PDO $db, string $equipType, string $kind, ?string $bizDate = null): ?array {
    $module = EQUIP_ASDOC_MODULES[$equipType][$kind] ?? null;
    if (!$module) return null;
    $doc = eg_asdoc_get($db, $module);
    if (!$doc) return null;
    try {
        $st = $db->prepare("SELECT department_id FROM as_document WHERE id=?");
        $st->execute([$doc['id']]);
        $doc['department_id'] = $st->fetchColumn() ?: null;
    } catch (Throwable $e) {}
    if (($doc['doc_level'] ?? '') === '四階' && $bizDate !== null) {
        $v = eg_asdoc_version_asof($db, (int)$doc['id'], $bizDate);
        if ($v !== null) $doc['current_version'] = $v;
    }
    return $doc;
}

/* ============================================================
 * 年度送簽通知（比照 vendor_audit_notify_plan_sign）
 * ============================================================ */
function equip_list_notify_sign(PDO $db, string $equipType, int $year, array $signerIds, ?int $submittedByUid, string $submittedByName): int {
    $signerIds = array_values(array_unique(array_filter(array_map('intval', $signerIds))));
    if (!$signerIds) return 0;
    $refType = $equipType === 'qc_tool' ? 'EQUIP_QC_LIST_SIGN' : 'EQUIP_MACHINE_LIST_SIGN';
    $label = $equipType === 'qc_tool' ? '檢驗設備一覽表' : '機台設備一覽表';
    try {
        $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type=? AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())")
           ->execute([$refType, $year]);
        $title = $year . ' 年 ' . $label . ' 待核准';
        $content = $submittedByName . ' 送出 ' . $year . ' 年 ' . $label . '，請核准。';
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, ?, 1, ?, ?)")
           ->execute([$title, $content, $submittedByUid, $label, $refType, $year]);
        $eventId = (int)$db->lastInsertId();
        $insTarget = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')");
        foreach ($signerIds as $sid) $insTarget->execute([$eventId, $sid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            $recipients = eg_push_event_recipients($db, $eventId);
            eg_push_send_to_users($db, $recipients, ['title'=>$title, 'body'=>mb_substr($content,0,480)]);
        } catch (Throwable $e) {}
        return $eventId;
    } catch (Throwable $e) { error_log('[equip_list] notify_sign failed: ' . $e->getMessage()); return 0; }
}

function equip_list_notify_sign_result(PDO $db, string $equipType, int $year, ?int $submittedByUid, string $deciderName, string $decision, ?string $note): void {
    if (!$submittedByUid) return;
    $refType = $equipType === 'qc_tool' ? 'EQUIP_QC_LIST_SIGN' : 'EQUIP_MACHINE_LIST_SIGN';
    $label = $equipType === 'qc_tool' ? '檢驗設備一覽表' : '機台設備一覽表';
    try {
        $title = $year . ' 年 ' . $label . '：' . ($decision === 'approved' ? '已核准' : '已退回');
        $content = $deciderName . '將 ' . $year . ' 年 ' . $label . ($decision === 'approved' ? '核准' : ('退回：' . ($note ?: '')));
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, NULL, ?, 1, ?, ?)")
           ->execute([$title, $content, $label, $refType.'_RESULT', $year]);
        $eventId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'notice')")
           ->execute([$eventId, $submittedByUid]);
    } catch (Throwable $e) {}
}
