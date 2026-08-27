<?php
// Roles_API.php — 全域角色權限管理 API
// 供所有頁面共用，負責角色 CRUD、功能設定、使用者指派角色
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
header('Content-Type: application/json');

require_once __DIR__ . '/../common/DBConnection.php';   // 2026-08-24 改 require_once＋__DIR__：api_guard 已先載入過，用 include 會二次宣告 class 直接 500
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
    // department_id：0＝該職稱所有部門通用（本表原始語意）／>0＝僅該部門的該職稱
    // 職稱跨部門共用（「組員」橫跨 7 個部門），不帶部門就是跨部門越權，見 role_features_helper.php
    $pdo->exec("CREATE TABLE IF NOT EXISTS position_roles (
        department_id INT NOT NULL DEFAULT 0,
        position_id INT NOT NULL,
        role_id INT NOT NULL,
        PRIMARY KEY (department_id, position_id, role_id),
        INDEX idx_pr_role (role_id),
        INDEX idx_pr_pos (position_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 既有安裝補欄（與 views/user/migrations/2026-08-27_dept_position_roles.php 同一件事，可重複執行）
    try {
        if (!$pdo->query("SHOW COLUMNS FROM position_roles LIKE 'department_id'")->fetch()) {
            $pdo->exec("ALTER TABLE position_roles ADD COLUMN department_id INT NOT NULL DEFAULT 0 FIRST");
            $pdo->exec("ALTER TABLE position_roles DROP PRIMARY KEY, ADD PRIMARY KEY (department_id, position_id, role_id)");
            $pdo->exec("ALTER TABLE position_roles ADD KEY idx_pr_pos (position_id)");
        }
    } catch(Exception $_e2) {}
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
// 稽核紀錄用的顯示名稱：帶部門的寫成「品管組 組長」，department_id=0 寫成「（全部門）組長」
function rbacDeptPosName(PDO $pdo, int $did, int $pid): string {
    $pos = rbacPositionName($pdo, $pid);
    if ($did <= 0) return '（全部門）' . $pos;
    try {
        $s = $pdo->prepare("SELECT name FROM department WHERE id=?"); $s->execute([$did]);
        $d = $s->fetchColumn();
        return ($d !== false ? (string)$d : ('#'.$did)) . ' ' . $pos;
    } catch (Exception $_e) { return '#'.$did.' '.$pos; }
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
                    SELECT pr.department_id, pr.position_id, r.role_id, r.role_name
                    FROM position_roles pr
                    JOIN roles r ON r.role_id = pr.role_id
                    WHERE r.module = ?");
                $prStmt->execute([$module]);
                $prRows = $prStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $prRows = $pdo->query("
                    SELECT pr.department_id, pr.position_id, r.role_id, r.role_name
                    FROM position_roles pr
                    JOIN roles r ON r.role_id = pr.role_id
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
            $prMap = [];
            foreach ($prRows as $row) {
                $prMap[(int)$row['department_id'] . '_' . $row['position_id']][] =
                    ['role_id'=>$row['role_id'],'role_name'=>$row['role_name']];
            }
            // roles＝該職稱「全部門通用(0)」的角色；相容舊呼叫端（本欄位語意未變）
            foreach ($positions as &$p) $p['roles'] = $prMap['0_' . $p['id']] ?? [];
            unset($p);

            // dept_positions：實際有人在的「部門×職稱」編制，各自帶已指派的角色與在職人數
            $dpRows = $pdo->query("
                SELECT m.department_id, d.name AS department_name, m.position_id, p.name AS position_name,
                       COUNT(DISTINCT m.user_id) AS people
                FROM user_department_position_map m
                JOIN department d ON d.id = m.department_id
                JOIN position  p ON p.id = m.position_id
                JOIN `user` u ON u.id = m.user_id AND u.state = 1
                GROUP BY m.department_id, d.name, m.position_id, p.name, d.sort_order, p.sort_order
                ORDER BY d.sort_order, d.id, p.sort_order, p.id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dpRows as &$dp) {
                $dp['roles']        = $prMap[(int)$dp['department_id'] . '_' . $dp['position_id']] ?? [];
                $dp['roles_anydept'] = $prMap['0_' . $dp['position_id']] ?? [];   // 全部門通用那一層
            }
            unset($dp);

            $response = ['success'=>true, 'data'=>$positions, 'dept_positions'=>$dpRows];
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
        $did = intval($_POST['department_id'] ?? 0);   // 0＝該職稱所有部門通用
        if (!$pid || !$rid) { $response = ['success'=>false,'message'=>'缺少參數']; break; }
        try {
            // 系統角色(admin)不可指派給職稱，避免整個職稱全變全域管理員
            $chk = $pdo->prepare("SELECT is_system FROM roles WHERE role_id=? LIMIT 1");
            $chk->execute([$rid]);
            if ((int)$chk->fetchColumn() === 1) { $response = ['success'=>false,'message'=>'系統角色（管理員）不可指派給職稱，請個別指派給使用者']; break; }
            if ($did > 0) {   // 指定部門時，該部門必須真的存在（擋掉前端亂送）
                $cd = $pdo->prepare("SELECT 1 FROM department WHERE id=? LIMIT 1");
                $cd->execute([$did]);
                if (!$cd->fetchColumn()) { $response = ['success'=>false,'message'=>'部門不存在']; break; }
            }
            $st = $pdo->prepare("INSERT IGNORE INTO position_roles (department_id,position_id,role_id) VALUES (?,?,?)");
            $st->execute([$did,$pid,$rid]);
            if ($st->rowCount() > 0) {
                rbacAudit($pdo, $user_id, 'assign', 'rbac_position', $pid,
                          rbacDeptPosName($pdo, $did, $pid),
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
        $did = intval($_POST['department_id'] ?? 0);   // 0＝全部門通用那一層
        if (!$pid || !$rid) { $response = ['success'=>false,'message'=>'缺少參數']; break; }
        try {
            $st = $pdo->prepare("DELETE FROM position_roles WHERE department_id=? AND position_id=? AND role_id=?");
            $st->execute([$did,$pid,$rid]);
            if ($st->rowCount() > 0) {
                rbacAudit($pdo, $user_id, 'remove', 'rbac_position', $pid,
                          rbacDeptPosName($pdo, $did, $pid),
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

    // ──────────────────────────────────────────────────────────────────────
    // 單一人員的權限總覽（人員導向檢視）
    // GET ?action=get_user_profile&user_id=N
    // 回傳這個人的：①每個「部門＋職稱」身分各自帶到的角色 ②個人指派的角色
    //              ③逐模組的最終生效結果與來源 ④代理狀態（他代理誰／誰代理他）
    // 為什麼要一次帶這些：一個人可能同時有主要職務與兼任職務，而代理是掛在「某個職稱身分」上的，
    // 只看「這個人有哪些角色」看不出權限是哪來的、也看不出承接中的代理。
    // ──────────────────────────────────────────────────────────────────────
    case 'get_user_profile': {
        $uid = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
        if (!$uid) { $response = ['success'=>false,'message'=>'缺少 user_id']; break; }
        try {
            require_once __DIR__ . '/../common/role_features_helper.php';

            $st = $pdo->prepare("SELECT id, user_cname, user_uname, state FROM `user` WHERE id=?");
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) { $response = ['success'=>false,'message'=>'找不到此人員']; break; }
            $u['active'] = eg_user_is_active($pdo, $uid) ? 1 : 0;

            // ① 身分（部門＋職稱，含兼任）與各身分帶到的角色
            $st = $pdo->prepare("
                SELECT m.department_id, d.name AS department_name, m.position_id, p.name AS position_name, m.is_main
                FROM user_department_position_map m
                JOIN department d ON d.id = m.department_id
                JOIN position  p ON p.id = m.position_id
                WHERE m.user_id = ?
                ORDER BY m.is_main DESC, d.sort_order, p.sort_order");
            $st->execute([$uid]);
            $identities = $st->fetchAll(PDO::FETCH_ASSOC);

            $prq = $pdo->prepare("
                SELECT r.role_id, r.role_name, r.module, pr.department_id
                FROM position_roles pr
                JOIN roles r ON r.role_id = pr.role_id
                WHERE pr.position_id = ? AND (pr.department_id = 0 OR pr.department_id = ?)
                ORDER BY r.module, r.role_id");
            foreach ($identities as &$_id) {
                $prq->execute([$_id['position_id'], $_id['department_id']]);
                $_id['roles'] = array_map(function($r) {
                    $r['scope'] = ((int)$r['department_id'] === 0) ? '全部門通用' : '此部門';
                    unset($r['department_id']);
                    return $r;
                }, $prq->fetchAll(PDO::FETCH_ASSOC));
            }
            unset($_id);

            // ② 個人指派
            $st = $pdo->prepare("
                SELECT r.role_id, r.role_name, r.module, r.is_system
                FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                WHERE ur.user_id = ? ORDER BY r.is_system DESC, r.module, r.role_id");
            $st->execute([$uid]);
            $personal = $st->fetchAll(PDO::FETCH_ASSOC);

            // ③ 逐模組最終生效（個人優先，逐模組判斷）
            $byModule = [];
            foreach ($personal as $r) {
                if ((int)$r['is_system'] === 1 || $r['module'] === null || $r['module'] === '') continue;
                $byModule[$r['module']]['personal'][] = $r['role_name'];
            }
            foreach ($identities as $_id) {
                foreach ($_id['roles'] as $r) {
                    $label = $r['role_name'] . '（' . $_id['department_name'] . ' ' . $_id['position_name'] . '）';
                    $byModule[$r['module']]['position'][] = $label;
                }
            }
            $effective = [];
            foreach ($byModule as $m => $srcs) {
                $use = !empty($srcs['personal']) ? 'personal' : (!empty($srcs['position']) ? 'position' : null);
                if (!$use) continue;
                $effective[] = [
                    'module'   => $m,
                    'source'   => $use,
                    'roles'    => array_values(array_unique($srcs[$use])),
                    'shadowed' => ($use === 'personal' && !empty($srcs['position']))
                                  ? array_values(array_unique($srcs['position'])) : [],   // 被個人指派蓋掉的部門職稱角色
                ];
            }
            usort($effective, function($a, $b) { return strcmp($a['module'], $b['module']); });

            // ④ 代理：他目前代理誰（承接中）／誰代理他
            $st = $pdo->prepare("
                SELECT lr.id, eu.user_cname AS employee_name, ra.scope_label,
                       d.name AS scope_department, p.name AS scope_position,
                       lt.leave_name AS type_name, lt.full_inherit_permission,
                       lr.start_datetime, lr.end_datetime
                FROM leave_request_agent ra
                JOIN leave_request lr ON lr.id = ra.leave_request_id
                JOIN leave_type lt ON lt.id = lr.leave_type_id
                LEFT JOIN `user` eu ON eu.id = lr.employee_id
                LEFT JOIN department d ON d.id = ra.scope_department_id
                LEFT JOIN `position` p ON p.id = ra.scope_position_id
                WHERE ra.agent_user_id = ? AND lr.status='approved'
                  AND NOW() BETWEEN lr.start_datetime AND lr.end_datetime");
            $st->execute([$uid]);
            $delegateIn = $st->fetchAll(PDO::FETCH_ASSOC);

            $st = $pdo->prepare("
                SELECT lr.id, au.user_cname AS agent_name, ra.scope_label,
                       d.name AS scope_department, p.name AS scope_position,
                       lt.leave_name AS type_name, lt.full_inherit_permission,
                       lr.start_datetime, lr.end_datetime
                FROM leave_request_agent ra
                JOIN leave_request lr ON lr.id = ra.leave_request_id
                JOIN leave_type lt ON lt.id = lr.leave_type_id
                LEFT JOIN `user` au ON au.id = ra.agent_user_id
                LEFT JOIN department d ON d.id = ra.scope_department_id
                LEFT JOIN `position` p ON p.id = ra.scope_position_id
                WHERE lr.employee_id = ? AND lr.status='approved'
                  AND NOW() BETWEEN lr.start_datetime AND lr.end_datetime");
            $st->execute([$uid]);
            $delegateOut = $st->fetchAll(PDO::FETCH_ASSOC);

            $response = ['success'=>true, 'user'=>$u, 'identities'=>$identities, 'personal'=>$personal,
                         'effective'=>$effective, 'delegate_in'=>$delegateIn, 'delegate_out'=>$delegateOut,
                         'features'=>rf_load_user_features_all($pdo, $uid)];
        } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
        break;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 部門×職稱角色：批次操作（一次套用到多組編制）
    // POST action=bulk_position_roles
    //   targets = JSON 陣列，元素為 "部門id_職稱id"（部門id=0 代表該職稱全部門通用）
    //   op      = assign（批次指派單一角色）/ remove（批次移除單一角色）/ copy（從來源整組複製）
    //   assign|remove： role_id
    //   copy：           source_type=user|position
    //                    source_user_id           （source_type=user）
    //                    source_department_id, source_position_id（source_type=position）
    //                    mode=merge|overwrite（overwrite 會先清空目標編制原有的角色）
    // 為什麼要有這個：同一組角色常常要套到好幾個編制（三個生產廠的組長權限一樣），
    // 一組一組點下去既慢又容易漏掉其中一組。
    // ──────────────────────────────────────────────────────────────────────
    case 'bulk_position_roles': {
        if (!isAdmin($pdo, $user_id)) { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
        $op      = $_POST['op'] ?? '';
        $rawTgts = json_decode($_POST['targets'] ?? '[]', true);
        if (!is_array($rawTgts) || !$rawTgts) { $response = ['success'=>false,'message'=>'請先勾選要套用的部門×職稱']; break; }
        if (!in_array($op, ['assign','remove','copy'], true)) { $response = ['success'=>false,'message'=>'無效的操作']; break; }

        try {
            // 目標解析與驗證：畫面上只列出「有在職人員的職稱」，後端用同一條規則再擋一次（鐵律8），
            // 這同時也擋掉超級管理員那種沒有在職人員的職稱，不會被直接打 API 改到。
            $posOk = $pdo->prepare("SELECT COUNT(*) FROM user_department_position_map m
                                    JOIN `user` u ON u.id=m.user_id AND u.state=1
                                    WHERE m.position_id=?");
            $dpOk  = $pdo->prepare("SELECT COUNT(*) FROM user_department_position_map m
                                    JOIN `user` u ON u.id=m.user_id AND u.state=1
                                    WHERE m.department_id=? AND m.position_id=?");
            $targets = [];
            foreach ($rawTgts as $t) {
                $parts = explode('_', (string)$t);
                if (count($parts) !== 2) { $response = ['success'=>false,'message'=>'目標格式錯誤']; break 2; }
                $did = intval($parts[0]); $pid = intval($parts[1]);
                if ($pid <= 0) { $response = ['success'=>false,'message'=>'目標職稱錯誤']; break 2; }
                if ($did === 0) { $posOk->execute([$pid]); $ok = (int)$posOk->fetchColumn() > 0; }
                else            { $dpOk->execute([$did, $pid]); $ok = (int)$dpOk->fetchColumn() > 0; }
                if (!$ok) { $response = ['success'=>false,'message'=>'目標編制不存在或沒有在職人員，不可設定']; break 2; }
                $targets[] = [$did, $pid];
            }

            // 要寫入的角色清單
            $roleIds = [];
            $srcLabel = '';
            if ($op === 'assign' || $op === 'remove') {
                $rid = intval($_POST['role_id'] ?? 0);
                if (!$rid) { $response = ['success'=>false,'message'=>'請選擇角色']; break; }
                $chk = $pdo->prepare("SELECT is_system, role_name FROM roles WHERE role_id=? LIMIT 1");
                $chk->execute([$rid]);
                $r = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$r) { $response = ['success'=>false,'message'=>'角色不存在']; break; }
                if ((int)$r['is_system'] === 1) { $response = ['success'=>false,'message'=>'系統角色（管理員）不可指派給職稱，請個別指派給使用者']; break; }
                $roleIds = [$rid];
                $srcLabel = $r['role_name'];
            } else { // copy
                $srcType = $_POST['source_type'] ?? '';
                if ($srcType === 'user') {
                    $suid = intval($_POST['source_user_id'] ?? 0);
                    if (!$suid) { $response = ['success'=>false,'message'=>'請選擇來源人員']; break; }
                    // 只複製「有歸模組的非系統角色」：系統角色（管理員）不可下放到職稱
                    $st = $pdo->prepare("SELECT r.role_id FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                         WHERE ur.user_id=? AND r.is_system=0 AND r.module IS NOT NULL AND r.module<>''");
                    $st->execute([$suid]);
                    $roleIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                    $srcLabel = '人員「' . rbacUserName($pdo, $suid) . '」的個人指派角色';
                } elseif ($srcType === 'position') {
                    $sdid = intval($_POST['source_department_id'] ?? 0);
                    $spid = intval($_POST['source_position_id'] ?? 0);
                    if (!$spid) { $response = ['success'=>false,'message'=>'請選擇來源部門×職稱']; break; }
                    // 來源要含「該部門專屬」與「全部門通用」兩層，跟實際生效的內容一致
                    $st = $pdo->prepare("SELECT pr.role_id FROM position_roles pr JOIN roles r ON r.role_id=pr.role_id
                                         WHERE pr.position_id=? AND (pr.department_id=0 OR pr.department_id=?)
                                           AND r.is_system=0");
                    $st->execute([$spid, $sdid]);
                    $roleIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                    $srcLabel = '編制「' . rbacDeptPosName($pdo, $sdid, $spid) . '」的角色';
                } else {
                    $response = ['success'=>false,'message'=>'請選擇複製來源']; break;
                }
                if (!$roleIds) { $response = ['success'=>false,'message'=>'來源沒有任何可複製的角色（系統角色不列入）']; break; }
            }

            $mode = (($_POST['mode'] ?? 'merge') === 'overwrite') ? 'overwrite' : 'merge';
            $pdo->beginTransaction();

            // 動作前先記錄各目標原本的內容，寫進稽核紀錄備查
            $beforeQ = $pdo->prepare("SELECT role_id FROM position_roles WHERE department_id=? AND position_id=?");
            $before  = [];
            foreach ($targets as [$did, $pid]) {
                $beforeQ->execute([$did, $pid]);
                $before[$did . '_' . $pid] = array_map('intval', $beforeQ->fetchAll(PDO::FETCH_COLUMN));
            }

            $ins = $pdo->prepare("INSERT IGNORE INTO position_roles (department_id,position_id,role_id) VALUES (?,?,?)");
            $del = $pdo->prepare("DELETE FROM position_roles WHERE department_id=? AND position_id=? AND role_id=?");
            $clr = $pdo->prepare("DELETE FROM position_roles WHERE department_id=? AND position_id=?");
            $added = 0; $removed = 0;
            foreach ($targets as [$did, $pid]) {
                if ($op === 'remove') {
                    $del->execute([$did, $pid, $roleIds[0]]);
                    $removed += $del->rowCount();
                    continue;
                }
                if ($op === 'copy' && $mode === 'overwrite') {
                    $clr->execute([$did, $pid]);
                    $removed += $clr->rowCount();
                }
                foreach ($roleIds as $rid) {
                    $ins->execute([$did, $pid, $rid]);
                    $added += $ins->rowCount();
                }
            }

            rbacAudit($pdo, $user_id, 'BULK_POSITION_ROLE', 'rbac_position', 0,
                      count($targets) . ' 組部門×職稱', [
                'op' => $op, 'mode' => $mode, 'source' => $srcLabel,
                'targets' => array_map(function($t) use ($pdo) { return rbacDeptPosName($pdo, $t[0], $t[1]); }, $targets),
                'role_ids' => $roleIds, 'added' => $added, 'removed' => $removed,
                'before' => $before,
            ]);
            $pdo->commit();

            $msg = ($op === 'remove')
                ? "已從 " . count($targets) . " 組編制移除「{$srcLabel}」（實際移除 {$removed} 筆）"
                : (($op === 'assign')
                    ? "已指派「{$srcLabel}」給 " . count($targets) . " 組編制（新增 {$added} 筆，原本已有的不重複）"
                    : "已把{$srcLabel}複製到 " . count($targets) . " 組編制（新增 {$added} 筆"
                      . ($mode === 'overwrite' ? "、清除原有 {$removed} 筆" : "，保留原有設定") . "）");
            $response = ['success'=>true, 'message'=>$msg, 'added'=>$added, 'removed'=>$removed];
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
