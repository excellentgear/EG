<?php
// Roles_API.php — 全域角色權限管理 API
// 供所有頁面共用，負責角色 CRUD、功能設定、使用者指派角色
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
header('Content-Type: application/json');

include '../common/DBConnection.php';
$db  = new DBConnection();
$pdo = $db->getPDO();

if (!isset($_SESSION['id'])) {
    echo json_encode(['success'=>false,'message'=>'尚未登入']);
    exit;
}
$user_id = (int)$_SESSION['id'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success'=>false,'message'=>'未知的 action'];

// ── 初始化 RBAC 資料表（若不存在）────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        role_id    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        role_code  VARCHAR(30) NOT NULL UNIQUE,
        role_name  VARCHAR(50) NOT NULL,
        is_system  TINYINT NOT NULL DEFAULT 0,
        note       VARCHAR(200),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_features (
        role_id      INT NOT NULL,
        feature_code VARCHAR(60) NOT NULL,
        PRIMARY KEY (role_id, feature_code),
        INDEX idx_rf_role (role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
        user_id INT NOT NULL,
        role_id INT NOT NULL,
        PRIMARY KEY (user_id, role_id),
        INDEX idx_ur_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS position_roles (
        position_id INT NOT NULL,
        role_id INT NOT NULL,
        PRIMARY KEY (position_id, role_id),
        INDEX idx_pr_role (role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 植入管理員角色（若不存在）
    $pdo->exec("INSERT IGNORE INTO roles (role_code,role_name,is_system) VALUES ('admin','管理員',1)");
    $_aid = $pdo->query("SELECT role_id FROM roles WHERE role_code='admin' LIMIT 1")->fetchColumn();
    if ($_aid) $pdo->prepare("INSERT IGNORE INTO role_features (role_id,feature_code) VALUES (?,?)")->execute([$_aid,'all']);
} catch(Exception $_e) {}

// ── 管理員身份驗證（用於寫入操作）───────────────────────────────────────
function isAdmin(PDO $pdo, int $uid): bool {
    try {
        // 與 rbac.php 一致：已被指派角色者，看其角色是否含 'all'；
        // 未指派任何角色者，才套用 bootstrap（系統尚無管理員則暫時可操作）。
        $chk = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id = ? LIMIT 1");
        $chk->execute([$uid]);
        if ($chk->fetchColumn()) {
            $stmt = $pdo->prepare("
                SELECT 1 FROM user_roles ur
                JOIN role_features rf ON rf.role_id = ur.role_id
                WHERE ur.user_id = ? AND rf.feature_code = 'all' LIMIT 1");
            $stmt->execute([$uid]);
            return (bool)$stmt->fetchColumn();
        }
        // 未指派角色 → bootstrap
        $anyAdmin = (int)$pdo->query("
            SELECT COUNT(*) FROM user_roles ur
            JOIN role_features rf ON rf.role_id = ur.role_id
            WHERE rf.feature_code = 'all'")->fetchColumn();
        return ($anyAdmin === 0);
    } catch(Exception $_e) { return false; }
}

// ── 權限異動稽核：寫入 audit_log（靜默，失敗不影響操作本身）──────────────
function rbacAudit(PDO $pdo, int $uid, string $act, string $ttype, $tid, ?string $tname, $changes = null): void {
    try {
        $op = '';
        try {
            $s = $pdo->prepare("SELECT user_cname, user_uname FROM `user` WHERE id=? LIMIT 1");
            $s->execute([$uid]);
            if ($r = $s->fetch(PDO::FETCH_ASSOC)) $op = trim((string)$r['user_cname']) !== '' ? trim((string)$r['user_cname']) : trim((string)$r['user_uname']);
        } catch (Exception $_e) {}
        $pdo->prepare("INSERT INTO audit_log (action_type,target_type,target_id,target_name,changes,user_id,operator)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$act, $ttype, (string)$tid, $tname,
                       is_array($changes) ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                       $uid, $op]);
    } catch (Exception $_e) {}
}
function rbacRoleName(PDO $pdo, int $rid): string {
    try { $s = $pdo->prepare("SELECT role_name FROM roles WHERE role_id=?"); $s->execute([$rid]); $n = $s->fetchColumn(); return $n !== false ? (string)$n : ('#'.$rid); } catch (Exception $_e) { return '#'.$rid; }
}
function rbacUserName(PDO $pdo, int $uid): string {
    try { $s = $pdo->prepare("SELECT user_cname, user_uname FROM `user` WHERE id=? LIMIT 1"); $s->execute([$uid]);
          if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $n = trim((string)$r['user_cname']); return $n !== '' ? $n : trim((string)$r['user_uname']); }
    } catch (Exception $_e) {}
    return '#'.$uid;
}
function rbacPositionName(PDO $pdo, int $pid): string {
    try { $s = $pdo->prepare("SELECT name FROM position WHERE id=?"); $s->execute([$pid]); $n = $s->fetchColumn(); return $n !== false ? (string)$n : ('#'.$pid); } catch (Exception $_e) { return '#'.$pid; }
}

switch ($action) {

    // ──────────────────────────────────────────────────────────────────────
    // 取得所有角色清單
    // GET  ?action=get_roles
    // 回傳 { success, data: [{role_id, role_code, role_name, is_system, note}] }
    // ──────────────────────────────────────────────────────────────────────
    case 'get_roles': {
        try {
            $module = $_GET['module'] ?? $_POST['module'] ?? '';
            if ($module !== '') {
                // 指定模組：只回該模組角色 + 系統角色(admin，全頁共用)
                $stmt = $pdo->prepare("
                    SELECT role_id, role_code, role_name, is_system, note, module
                    FROM roles
                    WHERE module = ? OR is_system = 1
                    ORDER BY is_system DESC, role_id ASC");
                $stmt->execute([$module]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = $pdo->query("
                    SELECT role_id, role_code, role_name, is_system, note, module
                    FROM roles
                    ORDER BY is_system DESC, role_id ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
            $response = ['success'=>true, 'data'=>$rows];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 新增或更新角色名稱
    // POST action=save_role  role_name=xxx  [role_id=N（更新時帶入）]
    // 回傳 { success, role_id }
    // ──────────────────────────────────────────────────────────────────────
    case 'save_role': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $rname  = trim($_POST['role_name'] ?? '');
        $rid    = intval($_POST['role_id'] ?? 0);
        $module = trim($_POST['module'] ?? '');
        if (!$rname) { $response = ['success'=>false,'message'=>'請輸入角色名稱']; break; }
        try {
            if ($rid) {
                // 改名（不更動所屬模組）
                $oldName = rbacRoleName($pdo, $rid);
                $pdo->prepare("UPDATE roles SET role_name=? WHERE role_id=? AND is_system=0")->execute([$rname, $rid]);
                if ($oldName !== $rname) {
                    rbacAudit($pdo, $user_id, 'update', 'rbac_role', $rid, $rname,
                              [['field'=>'role_name','old'=>$oldName,'new'=>$rname]]);
                }
                $response = ['success'=>true, 'role_id'=>$rid];
            } else {
                $rcode = 'role_' . time() . '_' . rand(100,999);
                $pdo->prepare("INSERT INTO roles (role_code,role_name,module) VALUES (?,?,?)")
                    ->execute([$rcode, $rname, ($module !== '' ? $module : null)]);
                $newId = (int)$pdo->lastInsertId();
                rbacAudit($pdo, $user_id, 'create', 'rbac_role', $newId, $rname,
                          ($module !== '' ? [['field'=>'module','old'=>null,'new'=>$module]] : null));
                $response = ['success'=>true, 'role_id'=>$newId];
            }
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 刪除角色（系統角色不可刪）
    // POST action=delete_role  role_id=N
    // ──────────────────────────────────────────────────────────────────────
    case 'delete_role': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $rid = intval($_POST['role_id'] ?? 0);
        if (!$rid) { $response = ['success'=>false,'message'=>'缺少 role_id']; break; }
        try {
            $chk = $pdo->prepare("SELECT is_system FROM roles WHERE role_id=? LIMIT 1");
            $chk->execute([$rid]);
            if ((int)$chk->fetchColumn() === 1) { $response = ['success'=>false,'message'=>'系統角色不可刪除']; break; }
            $delName = rbacRoleName($pdo, $rid);
            $pdo->prepare("DELETE FROM role_features WHERE role_id=?")->execute([$rid]);
            $pdo->prepare("DELETE FROM user_roles    WHERE role_id=?")->execute([$rid]);
            $pdo->prepare("DELETE FROM roles         WHERE role_id=? AND is_system=0")->execute([$rid]);
            rbacAudit($pdo, $user_id, 'delete', 'rbac_role', $rid, $delName);
            $response = ['success'=>true];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 取得角色擁有的功能代碼
    // GET  ?action=get_role_features&role_id=N
    // 回傳 { success, data: ['feature_code', ...] }
    // ──────────────────────────────────────────────────────────────────────
    case 'get_role_features': {
        $rid = intval($_GET['role_id'] ?? 0);
        if (!$rid) { $response = ['success'=>false,'message'=>'缺少 role_id']; break; }
        try {
            $stmt = $pdo->prepare("SELECT feature_code FROM role_features WHERE role_id=?");
            $stmt->execute([$rid]);
            $response = ['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_COLUMN)];
        } catch(Exception $_e) { $response = ['success'=>true, 'data'=>[]]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 儲存角色的功能代碼（覆蓋，系統角色不可改）
    // POST action=save_role_features  role_id=N  features=["code1","code2",...]
    // ──────────────────────────────────────────────────────────────────────
    case 'save_role_features': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $rid  = intval($_POST['role_id'] ?? 0);
        $feat = json_decode($_POST['features'] ?? '[]', true);
        if (!$rid)            { $response = ['success'=>false,'message'=>'缺少 role_id']; break; }
        if (!is_array($feat)) { $response = ['success'=>false,'message'=>'features 格式錯誤']; break; }
        try {
            $chk = $pdo->prepare("SELECT is_system FROM roles WHERE role_id=? LIMIT 1");
            $chk->execute([$rid]);
            if ((int)$chk->fetchColumn() === 1) { $response = ['success'=>false,'message'=>'系統角色不可修改']; break; }
            $oldFeat = [];
            try { $of = $pdo->prepare("SELECT feature_code FROM role_features WHERE role_id=? ORDER BY feature_code");
                  $of->execute([$rid]); $oldFeat = $of->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $_e) {}
            $pdo->prepare("DELETE FROM role_features WHERE role_id=?")->execute([$rid]);
            $ins = $pdo->prepare("INSERT IGNORE INTO role_features (role_id,feature_code) VALUES (?,?)");
            $newFeat = [];
            foreach ($feat as $fc) {
                $fc = preg_replace('/[^a-z0-9_]/', '', strval($fc));
                if ($fc) { $ins->execute([$rid, $fc]); $newFeat[] = $fc; }
            }
            sort($newFeat);
            if ($oldFeat !== $newFeat) {
                rbacAudit($pdo, $user_id, 'update', 'rbac_role', $rid, rbacRoleName($pdo, $rid),
                          [['field'=>'features','old'=>implode(',', $oldFeat),'new'=>implode(',', $newFeat)]]);
            }
            $response = ['success'=>true];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 取得所有使用者（含已指派的角色）
    // GET  ?action=get_users
    // 回傳 { success, data: [{id, user_cname, user_uname, roles:[{role_id,role_name}]}] }
    // ──────────────────────────────────────────────────────────────────────
    case 'get_users': {
        try {
            $users = $pdo->query("
                SELECT id, user_cname, user_uname
                FROM user
                WHERE state != 0
                ORDER BY user_cname ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 一次抓所有使用者的角色（可依模組過濾；系統角色 admin 全頁皆顯示）
            $module = $_GET['module'] ?? $_POST['module'] ?? '';
            if ($module !== '') {
                $urStmt = $pdo->prepare("
                    SELECT ur.user_id, r.role_id, r.role_name
                    FROM user_roles ur
                    JOIN roles r ON r.role_id = ur.role_id
                    WHERE r.module = ? OR r.is_system = 1");
                $urStmt->execute([$module]);
                $urRows = $urStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $urRows = $pdo->query("
                    SELECT ur.user_id, r.role_id, r.role_name
                    FROM user_roles ur
                    JOIN roles r ON r.role_id = ur.role_id
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
            $urMap = [];
            foreach ($urRows as $row) {
                $urMap[$row['user_id']][] = ['role_id'=>$row['role_id'],'role_name'=>$row['role_name']];
            }
            foreach ($users as &$u) $u['roles'] = $urMap[$u['id']] ?? [];
            unset($u);

            $response = ['success'=>true, 'data'=>$users];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 指派角色給使用者（可重複指派多個角色）
    // POST action=assign_user_role  user_id=N  role_id=N
    // ──────────────────────────────────────────────────────────────────────
    case 'assign_user_role': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $uid = intval($_POST['user_id'] ?? 0);
        $rid = intval($_POST['role_id'] ?? 0);
        if (!$uid || !$rid) { $response = ['success'=>false,'message'=>'缺少參數']; break; }
        try {
            $st = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id,role_id) VALUES (?,?)");
            $st->execute([$uid,$rid]);
            if ($st->rowCount() > 0) {
                rbacAudit($pdo, $user_id, 'assign', 'rbac_user', $uid, rbacUserName($pdo, $uid),
                          [['field'=>'role','old'=>null,'new'=>rbacRoleName($pdo, $rid)]]);
            }
            $response = ['success'=>true];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 移除使用者的某個角色
    // POST action=remove_user_role  user_id=N  role_id=N
    // ──────────────────────────────────────────────────────────────────────
    case 'remove_user_role': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $uid = intval($_POST['user_id'] ?? 0);
        $rid = intval($_POST['role_id'] ?? 0);
        if (!$uid || !$rid) { $response = ['success'=>false,'message'=>'缺少參數']; break; }
        try {
            $st = $pdo->prepare("DELETE FROM user_roles WHERE user_id=? AND role_id=?");
            $st->execute([$uid,$rid]);
            if ($st->rowCount() > 0) {
                rbacAudit($pdo, $user_id, 'remove', 'rbac_user', $uid, rbacUserName($pdo, $uid),
                          [['field'=>'role','old'=>rbacRoleName($pdo, $rid),'new'=>null]]);
            }
            $response = ['success'=>true];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 取得某使用者擁有的所有 feature codes（供頁面判斷權限）
    // GET  ?action=get_user_features&user_id=N
    // 回傳 { success, data: ['feature_code', ...] }
    // ──────────────────────────────────────────────────────────────────────
    case 'get_user_features': {
        $uid = intval($_GET['user_id'] ?? $user_id);
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT rf.feature_code
                FROM user_roles ur
                JOIN role_features rf ON rf.role_id = ur.role_id
                WHERE ur.user_id = ?
            ");
            $stmt->execute([$uid]);
            $response = ['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_COLUMN)];
        } catch(Exception $_e) { $response = ['success'=>true,'data'=>[]]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 取得所有職稱（含已指派的角色；可依模組過濾）
    // GET ?action=get_positions[&module=xxx]
    // 回傳 { success, data: [{id, name, departments, roles:[{role_id,role_name}]}] }
    // ──────────────────────────────────────────────────────────────────────
    case 'get_positions': {
        try {
            $positions = $pdo->query("
                SELECT p.id, p.name, p.sort_order,
                       GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order SEPARATOR '、') AS departments
                FROM position p
                LEFT JOIN department_position dp ON dp.position_id = p.id
                LEFT JOIN department d ON d.id = dp.department_id
                GROUP BY p.id, p.name, p.sort_order
                ORDER BY p.sort_order ASC, p.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $module = $_GET['module'] ?? $_POST['module'] ?? '';
            if ($module !== '') {
                $prStmt = $pdo->prepare("
                    SELECT pr.position_id, r.role_id, r.role_name
                    FROM position_roles pr
                    JOIN roles r ON r.role_id = pr.role_id
                    WHERE r.module = ?");
                $prStmt->execute([$module]);
                $prRows = $prStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $prRows = $pdo->query("
                    SELECT pr.position_id, r.role_id, r.role_name
                    FROM position_roles pr
                    JOIN roles r ON r.role_id = pr.role_id
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
            $prMap = [];
            foreach ($prRows as $row) {
                $prMap[$row['position_id']][] = ['role_id'=>$row['role_id'],'role_name'=>$row['role_name']];
            }
            foreach ($positions as &$p) $p['roles'] = $prMap[$p['id']] ?? [];
            unset($p);

            $response = ['success'=>true, 'data'=>$positions];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 指派角色給職稱（該職稱所有在職人員自動獲得此角色的功能）
    // POST action=assign_position_role  position_id=N  role_id=N
    // ──────────────────────────────────────────────────────────────────────
    case 'assign_position_role': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $pid = intval($_POST['position_id'] ?? 0);
        $rid = intval($_POST['role_id'] ?? 0);
        if (!$pid || !$rid) { $response = ['success'=>false,'message'=>'缺少參數']; break; }
        try {
            // 系統角色(admin)不可指派給職稱，避免整個職稱全變全域管理員
            $chk = $pdo->prepare("SELECT is_system FROM roles WHERE role_id=? LIMIT 1");
            $chk->execute([$rid]);
            if ((int)$chk->fetchColumn() === 1) { $response = ['success'=>false,'message'=>'系統角色（管理員）不可指派給職稱，請個別指派給使用者']; break; }
            $st = $pdo->prepare("INSERT IGNORE INTO position_roles (position_id,role_id) VALUES (?,?)");
            $st->execute([$pid,$rid]);
            if ($st->rowCount() > 0) {
                rbacAudit($pdo, $user_id, 'assign', 'rbac_position', $pid, rbacPositionName($pdo, $pid),
                          [['field'=>'role','old'=>null,'new'=>rbacRoleName($pdo, $rid)]]);
            }
            $response = ['success'=>true];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 移除職稱的某個角色
    // POST action=remove_position_role  position_id=N  role_id=N
    // ──────────────────────────────────────────────────────────────────────
    case 'remove_position_role': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $pid = intval($_POST['position_id'] ?? 0);
        $rid = intval($_POST['role_id'] ?? 0);
        if (!$pid || !$rid) { $response = ['success'=>false,'message'=>'缺少參數']; break; }
        try {
            $st = $pdo->prepare("DELETE FROM position_roles WHERE position_id=? AND role_id=?");
            $st->execute([$pid,$rid]);
            if ($st->rowCount() > 0) {
                rbacAudit($pdo, $user_id, 'remove', 'rbac_position', $pid, rbacPositionName($pdo, $pid),
                          [['field'=>'role','old'=>rbacRoleName($pdo, $rid),'new'=>null]]);
            }
            $response = ['success'=>true];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 複製某員工的權限設定給另一位員工（所有模組的角色指派＋選單群組/頁面權限）
    // POST action=copy_user_permissions  source_user_id=N  target_user_id=N  mode=merge|overwrite
    // 刻意不複製 page_operator_acl（各頁獨立、名額有限的超級管理員白名單，不該靠複製角色帶過去）
    // 與舊版 user_permissions（無任何頁面在讀寫，屬已停用的舊資料）
    // ──────────────────────────────────────────────────────────────────────
    case 'copy_user_permissions': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $srcId = intval($_POST['source_user_id'] ?? 0);
        $tgtId = intval($_POST['target_user_id'] ?? 0);
        $mode  = ($_POST['mode'] ?? 'merge') === 'overwrite' ? 'overwrite' : 'merge';
        if (!$srcId || !$tgtId) { $response = ['success'=>false,'message'=>'請選擇來源與目標員工']; break; }
        if ($srcId === $tgtId) { $response = ['success'=>false,'message'=>'來源與目標不可為同一人']; break; }
        try {
            $pdo->beginTransaction();

            // 複製前先記下目標員工原本的設定，寫入稽核紀錄備查
            $stmt = $pdo->prepare("SELECT r.role_id, r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=?");
            $stmt->execute([$tgtId]);
            $beforeRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("SELECT module_code, permission, scope FROM user_module_permissions WHERE user_id=?");
            $stmt->execute([$tgtId]);
            $beforeModPerm = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($mode === 'overwrite') {
                $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$tgtId]);
                $pdo->prepare("DELETE FROM user_module_permissions WHERE user_id=?")->execute([$tgtId]);
            }

            // 角色（跨所有模組，user_roles 本來就沒有分模組存放，一次複製即涵蓋全部）
            $roleCount = 0;
            $stmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id=?");
            $stmt->execute([$srcId]);
            $ins = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $ins->execute([$tgtId, $rid]);
                if ($ins->rowCount() > 0) $roleCount++;
            }

            // 選單群組/頁面權限
            $permCount = 0;
            $stmt = $pdo->prepare("SELECT module_code, permission, scope FROM user_module_permissions WHERE user_id=?");
            $stmt->execute([$srcId]);
            $chk  = $pdo->prepare("SELECT COUNT(*) FROM user_module_permissions WHERE user_id=? AND module_code=? AND scope=?");
            $ins2 = $pdo->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope) VALUES (?,?,?,?)");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $chk->execute([$tgtId, $p['module_code'], $p['scope']]);
                if ($chk->fetchColumn() > 0) continue; // 目標已有此模組/頁面設定，合併模式不覆蓋（覆蓋模式上面已先清空，不會走到這裡）
                $ins2->execute([$tgtId, $p['module_code'], $p['permission'], $p['scope']]);
                $permCount++;
            }

            $srcName = rbacUserName($pdo, $srcId);
            $tgtName = rbacUserName($pdo, $tgtId);
            rbacAudit($pdo, $user_id, 'PERM_COPY', 'rbac_user', $tgtId, $tgtName, [
                'source_user_id' => $srcId, 'source_name' => $srcName, 'mode' => $mode,
                'roles_copied' => $roleCount, 'module_perms_copied' => $permCount,
                'before_roles' => $beforeRoles, 'before_module_permissions' => $beforeModPerm,
            ]);

            $pdo->commit();
            $response = ['success' => true,
                'message' => "已從「{$srcName}」複製 {$roleCount} 個角色、{$permCount} 筆模組權限給「{$tgtName}」"
                    . ($mode === 'overwrite' ? '（已先清空目標原設定）' : '（保留目標原有設定，僅補上缺少的）')];
        } catch(Exception $_e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $response = ['success'=>false,'message'=>$_e->getMessage()];
        }
        break;
    }

    default:
        $response = ['success'=>false,'message'=>"未知的 action: {$action}"];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
