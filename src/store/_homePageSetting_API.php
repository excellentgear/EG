<?php
// _homePageSetting_API.php — 首頁設定的讀取/儲存 API
// action:
//   get        → 回傳 {options, departments[], users[]}
//   save_dept  → 參數 department_id, home_page（空字串=清除）
//   save_user  → 參數 user_id, home_page（空字串=清除）
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/homepage.php';
require_once __DIR__ . '/../common/rbac.php';

function out($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit(); }

if (!isset($_SESSION['id'])) {
    out(['success' => false, 'message' => '未登入']);
}

$pdo = (new DBConnection())->getPDO();
$uid = (int)$_SESSION['id'];
$features = rbac_user_features($pdo, $uid);

$action = $_REQUEST['action'] ?? '';

// 寫入類動作需要編輯權限
function require_edit($features) {
    if (!rbac_has($features, 'homepage_edit')) {
        out(['success' => false, 'message' => '無權限：需要「首頁設定 編輯」權限']);
    }
}

// 驗證 home_page 值（允許空字串代表清除）
function clean_home_page($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (!hp_is_valid($raw)) {
        out(['success' => false, 'message' => '無效的首頁路徑']);
    }
    return $raw;
}

hp_ensure_columns($pdo);

switch ($action) {

    case 'get':
        if (!rbac_has($features, 'homepage_view')) {
            out(['success' => false, 'message' => '無檢視權限']);
        }
        // 部門（含階層資訊，供顯示路徑）
        $departments = $pdo->query(
            "SELECT id, name, parent_id, level, home_page
             FROM department ORDER BY level ASC, sort_order ASC, name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // 使用者（含主要部門名稱與個人首頁設定），排除離職
        $users = $pdo->query(
            "SELECT u.id, u.user_cname, u.home_page,
                    d.name AS department_name
             FROM `user` u
             LEFT JOIN user_department_position_map m ON u.id = m.user_id AND m.is_main = 1
             LEFT JOIN department d ON m.department_id = d.id
             WHERE u.state NOT IN (0, 90)
             ORDER BY u.user_cname ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        out([
            'success'     => true,
            'options'     => hp_options(),
            'departments' => $departments,
            'users'       => $users,
            'default'     => hp_get_default($pdo),
        ]);
        break;

    case 'save_default':
        require_edit($features);
        $hp = clean_home_page($_POST['home_page'] ?? '');
        hp_set_default($pdo, $hp, $uid, ($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? null));
        out(['success' => true]);
        break;

    case 'save_dept':
        require_edit($features);
        $deptId = (int)($_POST['department_id'] ?? 0);
        if ($deptId <= 0) out(['success' => false, 'message' => '缺少部門 ID']);
        $hp = clean_home_page($_POST['home_page'] ?? '');
        $st = $pdo->prepare("UPDATE department SET home_page = ? WHERE id = ?");
        $st->execute([$hp, $deptId]);
        out(['success' => true]);
        break;

    case 'save_user':
        require_edit($features);
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) out(['success' => false, 'message' => '缺少使用者 ID']);
        $hp = clean_home_page($_POST['home_page'] ?? '');
        $st = $pdo->prepare("UPDATE `user` SET home_page = ? WHERE id = ?");
        $st->execute([$hp, $userId]);
        out(['success' => true]);
        break;

    default:
        out(['success' => false, 'message' => '未知的 action']);
}
