<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// c:\MAMP\htdocs\EGsystem\views\Sales\image_editor.php
// 批圖編輯器（小畫家 + Figma 混合式）：獨立跳窗頁，由 NewOrder_Track222.php「批圖」按鈕開啟。
// 純前端 Canvas 編輯（Fabric.js 本地載入），不寫入資料庫；權限採 imgedit 角色模組（見下方說明）。

ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}
// 版本號＝本檔最後修改時間（自動產生，免手動維護）；並禁止瀏覽器快取本頁，避免改版後使用者還在跑舊版 JS
// 先設台灣時區，否則 PHP 預設 UTC 會讓版本時間比實際少 8 小時
date_default_timezone_set('Asia/Taipei');
$EDITOR_VER = 'v' . date('Y.m.d-H:i', filemtime(__FILE__));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

include '../../src/common/DBConnection.php';

$uid      = (int)($_SESSION['id'] ?? 0);
$userName = $_SESSION['userName'] ?? '';

// 保險：一律以登入帳號回查 user 表確認 id/status。
// 原因：session 可能缺 id，或帳號曾重建導致 session 存舊 id——兩者都會讓
// 部門查詢（user_department_position_map 以 user_id 對應）、管理者判定、印章白名單落空。
if ($userName !== '') {
    try {
        $dbc0 = new DBConnection();
        $st0 = $dbc0->getPDO()->prepare("SELECT id, user_status FROM user WHERE user_uname = ? AND state != 0 LIMIT 1");
        $st0->execute([$userName]);
        if ($r0 = $st0->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$r0['id'] !== $uid) {
                $uid = (int)$r0['id'];
                $_SESSION['id'] = $uid;   // 修正 session 舊 id
            }
            if (!isset($_SESSION['status'])) $_SESSION['status'] = (int)$r0['user_status'];
        }
    } catch (Exception $e) { /* 回查失敗維持 session 原值 */ }
}
$isAdmin  = in_array((int)($_SESSION['status'] ?? 0), [9, 90], true);

// ── RBAC（imgedit 模組）─────────────────────────────────────────────────────
// 規則：管理者（status 9/90 或系統 admin 角色）固定可用。
// 其他人：若已有人被指派 imgedit 角色 → 只有被指派者可用；
//         若整個系統尚無任何 imgedit 角色指派 → 暫時開放全部登入者（沿用本系統
//         「資料庫未設定頁面權限時暫時允許訪問」的既有慣例）。
$canUse    = $isAdmin;
$roleLabel = $isAdmin ? '管理者' : '一般使用者';
try {
    $dbc = new DBConnection();
    $pdo = $dbc->getPDO();

    // 植入 imgedit 預設角色（供 user_permissions.php 角色指派區塊使用）
    $pdo->exec("INSERT IGNORE INTO roles (role_code, role_name, module, is_system, note)
                VALUES ('imgedit_user', '批圖使用者', 'imgedit', 0, '可使用批圖編輯器（views/Sales/image_editor.php）')");
    try {
        $rid = $pdo->query("SELECT role_id FROM roles WHERE role_code='imgedit_user' LIMIT 1")->fetchColumn();
        if ($rid) {
            $pdo->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?, 'imgedit.use')")->execute([$rid]);
        }
    } catch (Exception $e) { /* 表不存在時靜默跳過 */ }

    if (!$canUse && $uid) {
        // 系統 admin 角色也視為管理者
        $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                             WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1");
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() > 0) {
            $canUse = true;
            $roleLabel = '管理者';
        }
    }
    if (!$canUse && $uid) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                             WHERE r.module = 'imgedit'");
        $st->execute();
        $assignedTotal = (int)$st->fetchColumn();
        if ($assignedTotal === 0) {
            $canUse = true;
            $roleLabel = '一般使用者（暫時開放）';
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                                 WHERE ur.user_id = ? AND r.module = 'imgedit'");
            $st->execute([$uid]);
            if ((int)$st->fetchColumn() > 0) {
                $canUse = true;
                $roleLabel = '批圖使用者';
            }
        }
    }
} catch (Exception $e) {
    error_log('image_editor RBAC check error: ' . $e->getMessage());
    if (!$canUse) { $canUse = true; $roleLabel = '一般使用者（權限查詢失敗，暫時開放）'; }
}

if (!$canUse) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '無批圖編輯器使用權限']);
        exit;
    }
    echo '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>批圖編輯器</title></head><body>';
    echo '<script>alert("您尚未被指派「批圖使用者」角色，無法使用批圖編輯器。\n請洽管理者至「使用者權限管理」指派角色。");window.close();</script>';
    echo '<p style="font-family:sans-serif;padding:30px;">您尚未被指派「批圖使用者」角色，無法使用批圖編輯器。請洽管理者。</p></body></html>';
    exit;
}

// ── 管理者判定（status 9/90 或系統 admin 角色，前面已計算進 $roleLabel）──
$isMgr = ($isAdmin || $roleLabel === '管理者');

// ── 標籤實體檔 NAS 儲存 ─────────────────────────────────────────────────
// 路徑可於 使用者權限管理頁 設定（system_settings: imgedit_label_nas_dir）。
// 子資料夾前綴避免使用者ID與部門ID衝突：U<使用者ID>＝私人、D<部門ID>＝部門、company＝公司共用。
$labelNasBase = '\\\\excellentnas\\生產課\\BOM\\ERP\\共用資料\\標籤';
try {
    if (isset($pdo) && $pdo) {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'imgedit_label_nas_dir'")->fetchColumn();
        if ($v) $labelNasBase = rtrim(trim($v), '\\/');
    }
} catch (Exception $e) {}
function imgedit_label_sub(string $scope, int $uid, int $deptId): string {
    return $scope === 'private' ? ('U' . $uid) : ($scope === 'dept' ? ('D' . $deptId) : 'company');
}
function imgedit_label_dir(string $base, string $sub): string {
    $dir = $base . DIRECTORY_SEPARATOR . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}
// 內嵌 base64 圖片 → 抽離成實體檔並改為引用網址（$urlPrefix 接檔名；控制檔案與資料庫大小）
function imgedit_extract_images(&$node, string $dir, string $urlPrefix, &$count) {
    if (is_array($node)) {
        foreach ($node as &$v) imgedit_extract_images($v, $dir, $urlPrefix, $count);
        unset($v);
    } elseif (is_string($node) && strpos($node, 'data:image/') === 0 && strlen($node) > 512) {
        if (preg_match('#^data:image/(png|jpe?g|gif|webp|bmp);base64,#', $node, $m)) {
            $ext = ($m[1] === 'jpeg') ? 'jpg' : $m[1];
            $bin = base64_decode(substr($node, strpos($node, ',') + 1));
            if ($bin !== false && is_dir($dir)) {
                $fn = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@file_put_contents($dir . DIRECTORY_SEPARATOR . $fn, $bin) !== false) {
                    $node = $urlPrefix . $fn;
                    $count++;
                }
            }
        }
    }
}
// 標籤搬移/複製到其他範圍時，引用的實體檔一併複製到目標資料夾並改寫網址
function imgedit_relocate_files(string $specJson, string $base, string $targetSub): string {
    return preg_replace_callback('#image_editor\.php\?action=label_file&f=([^"\\\\]+)#u', function ($m) use ($base, $targetSub) {
        $rel = $m[1];
        $fn = (strrpos($rel, '/') === false) ? $rel : substr($rel, strrpos($rel, '/') + 1);
        $src = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $dst = imgedit_label_dir($base, $targetSub) . DIRECTORY_SEPARATOR . $fn;
        if (is_file($src) && $src !== $dst) @copy($src, $dst);
        return 'image_editor.php?action=label_file&f=' . $targetSub . '/' . $fn;
    }, $specJson);
}

// ── 料號附件儲存路徑（沿用附件系統 part_attach_nas_dir）────────────────
function imgedit_part_base(PDO $pdo): string {
    try {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'part_attach_nas_dir'")->fetchColumn();
        return $v ? rtrim(trim($v), '\\/') : '';
    } catch (Exception $e) { return ''; }
}

// ── GET：料號附件目錄檔案服務（工作檔內引用的底圖經此輸出）──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'part_file') {
    $dId = (int)($_GET['d'] ?? 0);
    $f = basename((string)($_GET['f'] ?? ''));
    if ($dId <= 0 || $f === '' || strpos($f, '..') !== false) { http_response_code(400); exit('bad path'); }
    $pb = (isset($pdo) && $pdo) ? imgedit_part_base($pdo) : '';
    $path = $pb ? ($pb . DIRECTORY_SEPARATOR . $dId . DIRECTORY_SEPARATOR . $f) : '';
    if ($path === '' || !is_file($path)) { http_response_code(404); exit('not found'); }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

// ── GET：標籤實體檔服務端點（NAS 檔案經此輸出給瀏覽器）─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'label_file') {
    $f = (string)($_GET['f'] ?? '');
    if ($f === '' || strpos($f, '..') !== false || strpos($f, ':') !== false) { http_response_code(400); exit('bad path'); }
    $path = $labelNasBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f);
    if (!is_file($path)) { http_response_code(404); exit('not found'); }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

// ── 使用者所屬部門（標籤庫「部門」範圍用）──────────────────────────────
$myDepts = [];
$myDeptIds = [];
$myMainDeptId = 0;
try {
    if (isset($pdo) && $pdo && $uid) {
        // 注意：DISTINCT 不能配未選取的 ORDER BY 欄位（MySQL 3065），改用 GROUP BY
        $st = $pdo->prepare("SELECT d.id, d.name
                             FROM user_department_position_map m JOIN department d ON d.id = m.department_id
                             WHERE m.user_id = ?
                             GROUP BY d.id, d.name, d.sort_order
                             ORDER BY d.sort_order, d.id");
        $st->execute([$uid]);
        $myDepts = $st->fetchAll(PDO::FETCH_ASSOC);
        $myDeptIds = array_map(fn($r) => (int)$r['id'], $myDepts);
        // 主要職務部門（is_main=1）：工作檔分享範圍選「部門共用」時預設用這個
        $mst = $pdo->prepare("SELECT department_id FROM user_department_position_map WHERE user_id = ? AND is_main = 1 LIMIT 1");
        $mst->execute([$uid]);
        $myMainDeptId = (int)($mst->fetchColumn() ?: 0);
        if (!$myMainDeptId && $myDeptIds) $myMainDeptId = $myDeptIds[0];
    }
} catch (Exception $e) { /* 無部門資料則視為空 */ }

// ── 使用者個人畫圖偏好（顏色/粗細/印章大小…用完自動記住，下次開啟沿用）──────
$userPrefs = [];
try {
    if (isset($pdo) && $pdo && $uid) {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute(['imgedit_user_prefs_' . $uid]);
        $v = $st->fetchColumn();
        if ($v) { $d = json_decode($v, true); if (is_array($d)) $userPrefs = $d; }
    }
} catch (Exception $e) {}

// ── 使用者個人常用「圖面像素縮放」尺寸（最多3組，可命名，其中一組可設為預設）────
$resizePresets = [];
$resizeDefaultIdx = 0;
try {
    if (isset($pdo) && $pdo && $uid) {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute(['imgedit_resize_presets_' . $uid]);
        $v = $st->fetchColumn();
        if ($v) {
            $d = json_decode($v, true);
            if (is_array($d)) {
                $resizePresets = array_slice(is_array($d['presets'] ?? null) ? $d['presets'] : [], 0, 3);
                $resizeDefaultIdx = (int)($d['default_index'] ?? 0);
            }
        }
    }
} catch (Exception $e) {}

// ── 部門印章（技術課章/發行章）使用權：管理者恆可；其他人須在指定人員名單內 ──
$stampUserIds = [];
try {
    if (isset($pdo) && $pdo) {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'imgedit_stamp_user_ids'")->fetchColumn();
        if ($v) $stampUserIds = array_map('intval', json_decode($v, true) ?: []);
    }
} catch (Exception $e) {}
$canDeptStamp = $isMgr || in_array($uid, $stampUserIds, true);

// 部門印章（技術課章/發行章）目前顏色：管理者可在「用章人員」跳窗設定，藍/紅二選一，全體共用
$deptStampColor = 'blue';
try {
    if (isset($pdo) && $pdo) {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'imgedit_dept_stamp_color'")->fetchColumn();
        if ($v === 'red') $deptStampColor = 'red';
    }
} catch (Exception $e) {}

// ── 工作檔刪除權限（imgedit 模組新角色）：未指派前一律只有管理者能刪，不套用「批圖使用者」那種
//    「無人指派時暫時全體開放」的例外 ──
$canDeleteWorkfile = $isMgr;
try {
    if (isset($pdo) && $pdo) {
        $pdo->exec("INSERT IGNORE INTO roles (role_code, role_name, module, is_system, note)
                    VALUES ('imgedit_wf_delete', '工作檔刪除', 'imgedit', 0, '可刪除批圖工作檔（views/Sales/image_editor.php 料號附件）')");
        if (!$canDeleteWorkfile && $uid) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                                 WHERE ur.user_id = ? AND r.role_code = 'imgedit_wf_delete'");
            $st->execute([$uid]);
            if ((int)$st->fetchColumn() > 0) $canDeleteWorkfile = true;
        }
    }
} catch (Exception $e) {}

// 同一料號最多保留幾份批圖工作檔（管理者可於使用者權限管理頁調整）；存檔時超過會自動砍掉最舊的
$workfileMaxCount = 3;
try {
    if (isset($pdo) && $pdo) {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'imgedit_workfile_max_count'")->fetchColumn();
        if ($v !== false && (int)$v > 0) $workfileMaxCount = (int)$v;
    }
} catch (Exception $e) {}

// ── AJAX：標籤庫（公司共用/部門/私人三層）＋ 印章人員設定 ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    // 儲存料號附件時會匯出大張 PNG＋整份 JSON 工作檔，預設 128M/60s 對大圖可能不夠；
    // 這裡只針對這支 AJAX 端點放寬，不動全域 php.ini
    @ini_set('memory_limit', '512M');
    @ini_set('max_execution_time', 180);
    // 若仍然爆掉（記憶體不足/逾時等 Fatal Error），display_errors 關閉下前端只會拿到空白內容
    // 造成 fetch().json() 丟出「Unexpected end of JSON input」；這裡攔截 Fatal Error 改回傳有意義的 JSON 錯誤
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode(['success' => false, 'message' => '伺服器處理失敗，可能是圖檔過大或處理逾時：' . $err['message']]);
        }
    });
    try {
        if (!isset($pdo) || !$pdo) throw new Exception('資料庫連線失敗');
        $pdo->exec("CREATE TABLE IF NOT EXISTS imgedit_labels (
            label_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            label_name VARCHAR(100) NOT NULL,
            category VARCHAR(50) DEFAULT NULL,
            owner_type VARCHAR(10) NOT NULL DEFAULT 'company',
            owner_user_id INT NULL,
            owner_dept_id INT NULL,
            spec_json MEDIUMTEXT NOT NULL,
            created_by VARCHAR(50),
            created_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE imgedit_labels ADD COLUMN category VARCHAR(50) DEFAULT NULL AFTER label_name"); } catch (Exception $e) { /* 已存在 */ }
        try { $pdo->exec("ALTER TABLE imgedit_labels ADD COLUMN owner_type VARCHAR(10) NOT NULL DEFAULT 'company' AFTER category"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE imgedit_labels ADD COLUMN owner_user_id INT NULL AFTER owner_type"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE imgedit_labels ADD COLUMN owner_dept_id INT NULL AFTER owner_user_id"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE imgedit_labels ADD COLUMN hide_name TINYINT NOT NULL DEFAULT 0 AFTER owner_dept_id"); } catch (Exception $e) {}

        // 批圖工作檔的分享範圍（私人／部門共用／指定人員），比照標籤庫三層概念但沒有「全公司共用」；
        // 沒有這兩張表的紀錄（舊資料）一律視為公司共用（沿用改版前的既有行為，不讓舊工作檔忽然看不到）
        $pdo->exec("CREATE TABLE IF NOT EXISTS imgedit_workfile_meta (
            attachment_id INT NOT NULL PRIMARY KEY,
            owner_type VARCHAR(10) NOT NULL DEFAULT 'dept',
            owner_dept_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS imgedit_workfile_share (
            attachment_id INT NOT NULL,
            user_id INT NOT NULL,
            PRIMARY KEY (attachment_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $act = $_POST['action'];
        if ($act === 'list_labels') {
            // 可見範圍：公司共用 + 自己部門的部門標籤 + 自己的私人標籤
            $params = [':uid' => $uid];
            $deptCond = '0';
            if ($myDeptIds) {
                $in = [];
                foreach ($myDeptIds as $i => $d) { $in[] = ':d' . $i; $params[':d' . $i] = $d; }
                $deptCond = 'owner_dept_id IN (' . implode(',', $in) . ')';
            }
            $st = $pdo->prepare("SELECT l.label_id, l.label_name, l.category, l.tags, l.owner_type, l.owner_user_id, l.owner_dept_id,
                                        l.hide_name, l.spec_json, l.created_by, d.name AS dept_name
                                 FROM imgedit_labels l LEFT JOIN department d ON d.id = l.owner_dept_id
                                 WHERE l.owner_type = 'company'
                                    OR (l.owner_type = 'dept' AND $deptCond)
                                    OR (l.owner_type = 'private' AND l.owner_user_id = :uid)
                                 ORDER BY l.category ASC, l.label_id DESC");
            $st->execute($params);
            $allDepts = [];
            if ($isMgr) {
                try { $allDepts = $pdo->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
            }
            echo json_encode(['success' => true, 'labels' => $st->fetchAll(PDO::FETCH_ASSOC),
                              'my_depts' => $myDepts, 'all_depts' => $allDepts, 'is_mgr' => $isMgr, 'uid' => $uid]);
        } elseif ($act === 'save_label') {
            $name  = trim($_POST['name'] ?? '');
            $cat   = trim($_POST['category'] ?? '');
            // #標示：空白/逗號分隔多個，#可省略；統一存成「無#、空格分隔」
            $tags  = implode(' ', array_values(array_unique(array_filter(preg_split('/[\s,，#]+/u', trim($_POST['tags'] ?? ''))))));
            if (mb_strlen($tags) > 100) $tags = mb_substr($tags, 0, 100);
            $scope = $_POST['scope'] ?? 'private';
            $deptId = (int)($_POST['dept_id'] ?? 0);
            $spec  = $_POST['spec'] ?? '';
            if ($name === '' || $spec === '') throw new Exception('缺少標籤名稱或內容');
            if (json_decode($spec) === null) throw new Exception('標籤內容格式錯誤');
            if (!in_array($scope, ['private', 'dept', 'company'], true)) throw new Exception('範圍參數錯誤');
            if ($scope === 'company' && !$isMgr) throw new Exception('只有管理者可存公司共用標籤');
            if ($scope === 'dept') {
                if ($deptId <= 0) throw new Exception('請選擇部門');
                if (!$isMgr && !in_array($deptId, $myDeptIds, true)) throw new Exception('只能存到自己所屬的部門');
            }
            if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);
            if (mb_strlen($cat) > 50) $cat = mb_substr($cat, 0, 50);
            // 內嵌圖片抽離到 NAS（U<uid>/D<dept>/company 資料夾）；NAS 不可用時保留 base64 照常運作
            $specArr = json_decode($spec, true);
            if (is_array($specArr)) {
                $sub = imgedit_label_sub($scope, $uid, $deptId);
                $dir = imgedit_label_dir($labelNasBase, $sub);
                $n = 0;
                if (is_dir($dir)) imgedit_extract_images($specArr, $dir, 'image_editor.php?action=label_file&f=' . $sub . '/', $n);
                $spec = json_encode($specArr, JSON_UNESCAPED_UNICODE);
            }
            $pdo->beginTransaction();
            $st = $pdo->prepare("INSERT INTO imgedit_labels (label_name, category, tags, owner_type, owner_user_id, owner_dept_id, spec_json, created_by, created_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $st->execute([$name, ($cat !== '' ? $cat : null), ($tags !== '' ? $tags : null), $scope, $uid, ($scope === 'dept' ? $deptId : null), $spec, $userName]);
            $newId = $pdo->lastInsertId();
            $pdo->commit();
            echo json_encode(['success' => true, 'label_id' => $newId]);
        } elseif ($act === 'delete_label') {
            $lid = (int)($_POST['label_id'] ?? 0);
            if ($lid <= 0) throw new Exception('缺少標籤編號');
            $row = $pdo->prepare("SELECT owner_type, owner_user_id, owner_dept_id FROM imgedit_labels WHERE label_id = ?");
            $row->execute([$lid]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('標籤不存在');
            $ok = $isMgr
                || ((int)$r['owner_user_id'] === $uid)                                            // 建立者本人
                || ($r['owner_type'] === 'dept' && in_array((int)$r['owner_dept_id'], $myDeptIds, true)); // 同部門
            if (!$ok) throw new Exception('公司共用標籤僅管理者可刪除');
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM imgedit_labels WHERE label_id = ?")->execute([$lid]);
            $pdo->commit();
            echo json_encode(['success' => true]);
        } elseif ($act === 'move_labels') {
            // 多選複製/搬移標籤到其他範圍（預設私人 → 部門）
            $ids  = json_decode($_POST['label_ids'] ?? '[]', true);
            $mode = $_POST['mode'] ?? 'copy';                 // copy | move
            $scope = $_POST['scope'] ?? 'dept';               // dept | company
            $deptId = (int)($_POST['dept_id'] ?? 0);
            if (!is_array($ids) || !$ids) throw new Exception('未選擇標籤');
            if (!in_array($mode, ['copy', 'move'], true)) throw new Exception('模式錯誤');
            if (!in_array($scope, ['private', 'dept', 'company'], true)) throw new Exception('目標範圍錯誤');
            if ($scope === 'company' && !$isMgr) throw new Exception('只有管理者可放到公司共用');
            // scope=private：收進自己的私人標籤庫（owner 檢查同下，僅能動自己的標籤；管理者不受限）
            // scope=dept 支援多部門（dept_ids JSON 陣列）：move=第一個部門為搬移、其餘為複製；copy=每個部門各複製一份
            $deptIds = json_decode($_POST['dept_ids'] ?? '[]', true);
            if (!is_array($deptIds)) $deptIds = [];
            $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds), fn($v) => $v > 0)));
            if (!$deptIds && $deptId > 0) $deptIds = [$deptId];
            if ($scope === 'dept') {
                if (!$deptIds) throw new Exception('請至少選擇一個目標部門');
                foreach ($deptIds as $d) {
                    if (!$isMgr && !in_array($d, $myDeptIds, true)) throw new Exception('只能放到自己所屬的部門');
                }
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $pdo->beginTransaction();
            $sel = $pdo->prepare("SELECT * FROM imgedit_labels WHERE label_id = ?");
            $ins = $pdo->prepare("INSERT INTO imgedit_labels (label_name, category, tags, owner_type, owner_user_id, owner_dept_id, spec_json, created_by, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $upd = $pdo->prepare("UPDATE imgedit_labels SET owner_type = ?, owner_user_id = ?, owner_dept_id = ?, spec_json = ? WHERE label_id = ?");
            $done = 0;
            foreach ($ids as $lid) {
                $sel->execute([$lid]);
                $r = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$r) continue;
                if (!$isMgr && (int)$r['owner_user_id'] !== $uid) continue;   // 只能動自己的標籤
                if ($scope === 'dept') {
                    $first = true;
                    foreach ($deptIds as $d) {
                        $sub = 'D' . $d;
                        $newSpec = imgedit_relocate_files($r['spec_json'], $labelNasBase, $sub);
                        if ($mode === 'move' && $first) $upd->execute(['dept', (int)$r['owner_user_id'] ?: $uid, $d, $newSpec, $lid]);
                        else $ins->execute([$r['label_name'], $r['category'], $r['tags'], 'dept', $uid, $d, $newSpec, $userName]);
                        $first = false;
                    }
                } else {
                    $sub = imgedit_label_sub($scope, $uid, 0);
                    $newSpec = imgedit_relocate_files($r['spec_json'], $labelNasBase, $sub);
                    if ($mode === 'copy') $ins->execute([$r['label_name'], $r['category'], $r['tags'], $scope, $uid, null, $newSpec, $userName]);
                    else $upd->execute([$scope, ($scope === 'private' ? $uid : ((int)$r['owner_user_id'] ?: $uid)), null, $newSpec, $lid]);
                }
                $done++;
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'count' => $done]);
        } elseif ($act === 'set_label_flag') {
            // 批次設定「不顯示標籤名稱」（標籤內容與名稱幾乎相同時使用）
            $ids = json_decode($_POST['label_ids'] ?? '[]', true);
            $hide = (int)($_POST['hide_name'] ?? 0) ? 1 : 0;
            if (!is_array($ids) || !$ids) throw new Exception('未選擇標籤');
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $pdo->beginTransaction();
            $sel = $pdo->prepare("SELECT owner_user_id FROM imgedit_labels WHERE label_id = ?");
            $upd = $pdo->prepare("UPDATE imgedit_labels SET hide_name = ? WHERE label_id = ?");
            $done = 0;
            foreach ($ids as $lid) {
                $sel->execute([$lid]);
                $r = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$r) continue;
                if (!$isMgr && (int)$r['owner_user_id'] !== $uid) continue;   // 只能改自己的標籤
                $upd->execute([$hide, $lid]);
                $done++;
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'count' => $done]);
        } elseif ($act === 'part_search') {
            // 料號搜尋（料號/圖號）
            $q = trim($_POST['q'] ?? '');
            if ($q === '') throw new Exception('請輸入料號或圖號關鍵字');
            $st = $pdo->prepare("SELECT d_id, D_Setting_Id, Drawing_No FROM d_setting
                                 WHERE D_Setting_Id LIKE ? OR Drawing_No LIKE ?
                                 ORDER BY d_id DESC LIMIT 20");
            $like = '%' . $q . '%';
            $st->execute([$like, $like]);
            echo json_encode(['success' => true, 'parts' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } elseif ($act === 'save_workfile') {
            // 存成料號附件：壓平 PNG（附件系統可見）＋ .egwork.json 工作檔（批圖編輯器可重開繼續編輯）
            $dId = (int)($_POST['d_id'] ?? 0);
            $name = trim($_POST['name'] ?? '') ?: ('批圖_' . date('Ymd_Hi'));
            $png = $_POST['png'] ?? '';
            $work = $_POST['work'] ?? '';
            // 分享範圍：私人／部門共用（預設）／指定人員；沒有「全公司共用」，避免任何人都能改到
            $scope = in_array($_POST['scope'] ?? 'dept', ['private', 'dept', 'custom'], true) ? $_POST['scope'] : 'dept';
            $shareIds = json_decode($_POST['share_user_ids'] ?? '[]', true);
            if (!is_array($shareIds)) $shareIds = [];
            $shareIds = array_values(array_unique(array_map('intval', $shareIds)));
            $deptId = (int)($_POST['dept_id'] ?? 0);
            if ($scope === 'dept' && (!$deptId || !in_array($deptId, $myDeptIds, true))) $deptId = $myDeptIds[0] ?? 0;
            if ($dId <= 0) throw new Exception('請先選擇料號');
            if (strpos($png, 'data:image/png;base64,') !== 0) throw new Exception('圖檔資料異常');
            if (json_decode($work) === null) throw new Exception('工作檔資料異常');
            $pb = imgedit_part_base($pdo);
            if ($pb === '') throw new Exception('尚未設定料號附件儲存路徑（附件系統設定）');
            $dir = $pb . DIRECTORY_SEPARATOR . $dId;
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) throw new Exception('無法建立料號附件目錄（NAS 權限？）');
            $stamp = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
            $pngFile = 'egdraw_' . $stamp . '.png';
            $bin = base64_decode(substr($png, strpos($png, ',') + 1));
            if ($bin === false || @file_put_contents($dir . DIRECTORY_SEPARATOR . $pngFile, $bin) === false) throw new Exception('圖檔寫入失敗');
            // 工作檔：抽離內嵌底圖 → 同目錄實體檔，JSON 只留引用
            $workArr = json_decode($work, true);
            $n = 0;
            imgedit_extract_images($workArr, $dir, 'image_editor.php?action=part_file&d=' . $dId . '&f=', $n);
            $workFile = 'egdraw_' . $stamp . '.egwork.json';
            if (@file_put_contents($dir . DIRECTORY_SEPARATOR . $workFile, json_encode($workArr, JSON_UNESCAPED_UNICODE)) === false) throw new Exception('工作檔寫入失敗');
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO part_attachments (d_id, filename, original_name, note, uploaded_by, uploaded_by_id, uploaded_at)
                                  VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([$dId, $pngFile, $name . '.png', '批圖編輯器輸出圖', $userName, $uid]);
            $pngId = $pdo->lastInsertId();
            $ins->execute([$dId, $workFile, $name . '.egwork.json', '批圖工作檔（可用批圖編輯器重新開啟，標籤仍可編輯）', $userName, $uid]);
            $workId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO imgedit_workfile_meta (attachment_id, owner_type, owner_dept_id) VALUES (?, ?, ?)")
                ->execute([$workId, $scope, ($scope === 'dept' && $deptId) ? $deptId : null]);
            if ($scope === 'custom' && $shareIds) {
                $shareIns = $pdo->prepare("INSERT IGNORE INTO imgedit_workfile_share (attachment_id, user_id) VALUES (?, ?)");
                foreach ($shareIds as $sid) { if ($sid > 0) $shareIns->execute([$workId, $sid]); }
            }
            // 保留上限：同一料號工作檔數量超過上限時，砍掉最舊的（絕不會刪到剛存好的這份）
            $allIds = $pdo->prepare("SELECT id FROM part_attachments
                                     WHERE d_id = ? AND deleted_at IS NULL AND filename LIKE '%.egwork.json'
                                     ORDER BY id DESC");
            $allIds->execute([$dId]);
            $existing = $allIds->fetchAll(PDO::FETCH_COLUMN);
            $removed = 0;
            if (count($existing) > $workfileMaxCount) {
                $delSt = $pdo->prepare("UPDATE part_attachments SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
                foreach (array_slice($existing, $workfileMaxCount) as $rid) {
                    $delSt->execute([$userName . '（系統自動：超過保留上限 ' . $workfileMaxCount . ' 份）', $rid]);
                    $removed++;
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'png_id' => $pngId, 'work_id' => $workId, 'extracted' => $n, 'auto_removed' => $removed]);
        } elseif ($act === 'list_workfiles') {
            $dId = (int)($_POST['d_id'] ?? 0);
            if ($dId <= 0) throw new Exception('缺少料號');
            $st = $pdo->prepare("SELECT pa.id, pa.original_name, pa.uploaded_by, pa.uploaded_by_id, pa.uploaded_at,
                                        m.owner_type, m.owner_dept_id
                                 FROM part_attachments pa
                                 LEFT JOIN imgedit_workfile_meta m ON m.attachment_id = pa.id
                                 WHERE pa.d_id = ? AND pa.deleted_at IS NULL AND pa.filename LIKE '%.egwork.json'
                                 ORDER BY pa.id DESC LIMIT 30");
            $st->execute([$dId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $shareChk = $pdo->prepare("SELECT COUNT(*) FROM imgedit_workfile_share WHERE attachment_id = ? AND user_id = ?");
            $out = [];
            foreach ($rows as $i => $r) {
                // 舊資料沒有 meta 紀錄（改版前存的）→ 視為公司共用，維持改版前「大家都看得到」的行為
                $ownerType = $r['owner_type'] ?: 'company';
                $visible = $isMgr || (int)$r['uploaded_by_id'] === $uid || $ownerType === 'company';
                if (!$visible && $ownerType === 'dept') $visible = in_array((int)$r['owner_dept_id'], $myDeptIds, true);
                if (!$visible && $ownerType === 'custom') {
                    $shareChk->execute([$r['id'], $uid]);
                    $visible = (int)$shareChk->fetchColumn() > 0;
                }
                if (!$visible) continue;
                $out[] = ['id' => $r['id'], 'original_name' => $r['original_name'], 'uploaded_by' => $r['uploaded_by'],
                          'uploaded_at' => $r['uploaded_at'], 'scope' => $ownerType,
                          'is_latest' => ($i === 0), 'can_delete' => ($canDeleteWorkfile && $i !== 0)];
            }
            echo json_encode(['success' => true, 'works' => $out]);
        } elseif ($act === 'load_workfile') {
            $wid = (int)($_POST['id'] ?? 0);
            if ($wid <= 0) throw new Exception('缺少工作檔編號');
            $st = $pdo->prepare("SELECT pa.d_id, pa.filename, pa.original_name, pa.uploaded_by_id, m.owner_type, m.owner_dept_id
                                 FROM part_attachments pa LEFT JOIN imgedit_workfile_meta m ON m.attachment_id = pa.id
                                 WHERE pa.id = ? AND pa.deleted_at IS NULL");
            $st->execute([$wid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('找不到工作檔');
            $ownerType = $r['owner_type'] ?: 'company';
            $canSee = $isMgr || (int)$r['uploaded_by_id'] === $uid || $ownerType === 'company';
            if (!$canSee && $ownerType === 'dept') $canSee = in_array((int)$r['owner_dept_id'], $myDeptIds, true);
            if (!$canSee && $ownerType === 'custom') {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM imgedit_workfile_share WHERE attachment_id = ? AND user_id = ?");
                $chk->execute([$wid, $uid]);
                $canSee = (int)$chk->fetchColumn() > 0;
            }
            if (!$canSee) throw new Exception('沒有這份工作檔的存取權限');
            $pb = imgedit_part_base($pdo);
            $path = $pb . DIRECTORY_SEPARATOR . $r['d_id'] . DIRECTORY_SEPARATOR . basename($r['filename']);
            if (!is_file($path)) throw new Exception('工作檔實體檔不存在（' . $r['filename'] . '）');
            $content = @file_get_contents($path);
            if ($content === false || json_decode($content) === null) throw new Exception('工作檔讀取失敗或格式異常');
            echo json_encode(['success' => true, 'work' => $content, 'name' => $r['original_name']]);
        } elseif ($act === 'delete_workfile') {
            if (!$canDeleteWorkfile) throw new Exception('沒有工作檔刪除權限');
            $wid = (int)($_POST['id'] ?? 0);
            if ($wid <= 0) throw new Exception('缺少工作檔編號');
            $st = $pdo->prepare("SELECT id, d_id FROM part_attachments WHERE id = ? AND deleted_at IS NULL AND filename LIKE '%.egwork.json'");
            $st->execute([$wid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('找不到工作檔');
            $latest = $pdo->prepare("SELECT id FROM part_attachments
                                     WHERE d_id = ? AND deleted_at IS NULL AND filename LIKE '%.egwork.json'
                                     ORDER BY id DESC LIMIT 1");
            $latest->execute([$r['d_id']]);
            if ((int)$latest->fetchColumn() === $wid) throw new Exception('這是目前最新的工作檔，不能刪除');
            $pdo->prepare("UPDATE part_attachments SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")->execute([$userName, $wid]);
            echo json_encode(['success' => true]);
        } elseif ($act === 'list_users_for_share') {
            // 只列出「目前有批圖編輯器使用權」的人：管理者（status 9/90 或系統 admin 角色）、
            // 或已被指派 imgedit 模組角色者；若全系統尚無人被指派 imgedit 角色（頁面暫時開放全部登入者的狀態），
            // 則不篩選，比照頁面本身現在的開放狀態
            $assignedTotal = (int)$pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                                               WHERE r.module = 'imgedit'")->fetchColumn();
            $where = "u.state != 0";
            if ($assignedTotal > 0) {
                $where .= " AND (u.user_status IN (9,90)
                    OR EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                               WHERE ur.user_id = u.id AND r.role_code = 'admin' AND r.is_system = 1)
                    OR EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                               WHERE ur.user_id = u.id AND r.module = 'imgedit'))";
            }
            $rows = $pdo->query("SELECT u.id, u.user_cname, d.name AS dept_name, p.name AS pos_name
                                 FROM user u
                                 LEFT JOIN user_department_position_map dp ON dp.user_id = u.id AND dp.is_main = 1
                                 LEFT JOIN department d ON d.id = dp.department_id
                                 LEFT JOIN position p ON p.id = dp.position_id
                                 WHERE $where
                                 ORDER BY u.user_cname")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'users' => $rows]);
        } elseif ($act === 'save_user_prefs') {
            // 個人畫圖偏好（顏色/粗細/印章大小…），存到 system_settings，key 依使用者 id 區分
            $prefs = json_decode($_POST['prefs'] ?? '{}', true);
            if (!is_array($prefs)) $prefs = [];
            // 白名單欄位，避免被塞進奇怪的東西
            $allowed = ['stroke', 'width', 'lineEnds', 'fill', 'fillOn', 'textColor', 'fontSize', 'bold',
                        'textBg', 'textBgOn', 'balloonSize', 'dcShape', 'dcSize', 'stampSize', 'maskColor', 'cropTransparent',
                        'connectKind', 'dimStyle'];
            $clean = [];
            foreach ($allowed as $k) if (array_key_exists($k, $prefs)) $clean[$k] = $prefs[$k];
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                               updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)")
                ->execute(['imgedit_user_prefs_' . $uid, json_encode($clean, JSON_UNESCAPED_UNICODE), $uid, $userName]);
            echo json_encode(['success' => true]);
        } elseif ($act === 'save_resize_presets') {
            // 個人常用「圖面像素縮放」尺寸，最多3組
            $presets = json_decode($_POST['presets'] ?? '[]', true);
            if (!is_array($presets)) $presets = [];
            $clean = [];
            foreach (array_slice($presets, 0, 3) as $p) {
                if (!is_array($p)) continue;
                $name = trim((string)($p['name'] ?? ''));
                $w = (int)($p['w'] ?? 0);
                $h = (int)($p['h'] ?? 0);
                if ($name === '' || $w < 10 || $h < 10) continue;
                $clean[] = ['name' => mb_substr($name, 0, 30), 'w' => $w, 'h' => $h];
            }
            $defIdx = (int)($_POST['default_index'] ?? 0);
            if ($defIdx < 0 || $defIdx >= count($clean)) $defIdx = 0;
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                               updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)")
                ->execute(['imgedit_resize_presets_' . $uid,
                           json_encode(['presets' => $clean, 'default_index' => $defIdx], JSON_UNESCAPED_UNICODE),
                           $uid, $userName]);
            echo json_encode(['success' => true]);
        } elseif ($act === 'set_label_category') {
            // 批次設定分類（分類名稱由使用者自訂，空字串＝未分類）
            $ids = json_decode($_POST['label_ids'] ?? '[]', true);
            $cat = trim($_POST['category'] ?? '');
            if (!is_array($ids) || !$ids) throw new Exception('未選擇標籤');
            if (mb_strlen($cat) > 50) $cat = mb_substr($cat, 0, 50);
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $pdo->beginTransaction();
            $sel = $pdo->prepare("SELECT owner_user_id FROM imgedit_labels WHERE label_id = ?");
            $upd = $pdo->prepare("UPDATE imgedit_labels SET category = ? WHERE label_id = ?");
            $done = 0;
            foreach ($ids as $lid) {
                $sel->execute([$lid]);
                $r = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$r) continue;
                if (!$isMgr && (int)$r['owner_user_id'] !== $uid) continue;   // 只能改自己的標籤
                $upd->execute([($cat !== '' ? $cat : null), $lid]);
                $done++;
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'count' => $done]);
        } elseif ($act === 'set_label_tags') {
            // 批次設定 #標示（空白/逗號分隔多個，#可省略；空字串＝清除）
            $ids = json_decode($_POST['label_ids'] ?? '[]', true);
            $tags = implode(' ', array_values(array_unique(array_filter(preg_split('/[\s,，#]+/u', trim($_POST['tags'] ?? ''))))));
            if (!is_array($ids) || !$ids) throw new Exception('未選擇標籤');
            if (mb_strlen($tags) > 100) $tags = mb_substr($tags, 0, 100);
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $pdo->beginTransaction();
            $sel = $pdo->prepare("SELECT owner_user_id FROM imgedit_labels WHERE label_id = ?");
            $upd = $pdo->prepare("UPDATE imgedit_labels SET tags = ? WHERE label_id = ?");
            $done = 0;
            foreach ($ids as $lid) {
                $sel->execute([$lid]);
                $r = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$r) continue;
                if (!$isMgr && (int)$r['owner_user_id'] !== $uid) continue;   // 只能改自己的標籤
                $upd->execute([($tags !== '' ? $tags : null), $lid]);
                $done++;
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'count' => $done]);
        } elseif ($act === 'get_stamp_users') {
            if (!$isMgr) throw new Exception('只有管理者可設定印章使用人員');
            $depts = $pdo->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
            $users = $pdo->query("SELECT DISTINCT m.department_id, u.id, u.user_cname
                                  FROM user_department_position_map m JOIN user u ON u.id = m.user_id
                                  ORDER BY m.department_id, u.id")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'depts' => $depts, 'users' => $users, 'selected' => $stampUserIds, 'color' => $deptStampColor]);
        } elseif ($act === 'save_stamp_users') {
            if (!$isMgr) throw new Exception('只有管理者可設定印章使用人員');
            $ids = json_decode($_POST['user_ids'] ?? '[]', true);
            if (!is_array($ids)) throw new Exception('人員名單格式錯誤');
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $color = (($_POST['color'] ?? 'blue') === 'red') ? 'red' : 'blue';
            $pdo->beginTransaction();
            $st = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                                 VALUES ('imgedit_stamp_user_ids', ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)");
            $st->execute([json_encode($ids), $uid, $userName]);
            $st2 = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                                 VALUES ('imgedit_dept_stamp_color', ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)");
            $st2->execute([$color, $uid, $userName]);
            $pdo->commit();
            echo json_encode(['success' => true, 'count' => count($ids), 'color' => $color]);
        } else {
            throw new Exception('未知的動作');
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// ── 印章資料：公司全名（customer_list.is_own_company=1）與使用者中文名 ──
$ownCompany = '';
$userCname  = $userName;
try {
    if (isset($pdo) && $pdo) {
        $r = $pdo->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) $ownCompany = ($r['customer_full'] ?: $r['customer']) ?: '';
        $st = $pdo->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $cn = $st->fetchColumn();
        if ($cn) $userCname = $cn;
    }
} catch (Exception $e) { /* 取不到就用帳號名 */ }

$safeUser  = htmlspecialchars($userCname ?: $userName, ENT_QUOTES, 'UTF-8');   // 右上角顯示中文姓名
$safeRole  = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>批圖編輯器 - EGsystem</title>
<link href="../../resource/css/font-awesome.min.css" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; overflow: hidden; }
    body { font-family: "Microsoft JhengHei", "PingFang TC", sans-serif; font-size: 13px; background: #2c2f33; color: #e8e8e8; display: flex; flex-direction: column; }

    /* ── 頂列 ── */
    #topbar { height: 44px; background: #22252a; border-bottom: 1px solid #111; display: flex; align-items: center; gap: 6px; padding: 0 10px; flex-shrink: 0; }
    #topbar .brand { font-weight: 700; font-size: 14px; color: #6fc3ff; margin-right: 6px; white-space: nowrap; }
    .tb-btn { background: #34383f; color: #e8e8e8; border: 1px solid #45494f; border-radius: 4px; padding: 5px 9px; font-size: 12px; cursor: pointer; white-space: nowrap; }
    .tb-btn:hover { background: #43484f; }
    .tb-btn.primary { background: #2779bd; border-color: #3a8ed1; }
    .tb-btn.primary:hover { background: #3189d0; }
    .tb-sep { width: 1px; height: 24px; background: #45494f; margin: 0 3px; flex-shrink: 0; }
    #user-info { margin-left: auto; display: flex; align-items: center; gap: 8px; font-size: 12px; color: #aaa; white-space: nowrap; }
    #help-icon { width: 20px; height: 20px; border-radius: 50%; background: #45494f; color: #fff; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; }
    #help-icon:hover { background: #2779bd; }

    /* ── 主區 ── */
    #main { flex: 1; display: flex; min-height: 0; }

    /* 左側工具列 */
    #toolbar { width: 52px; background: #22252a; border-right: 1px solid #111; display: flex; flex-direction: column; align-items: center; padding: 6px 0; gap: 3px; flex-shrink: 0; overflow-y: auto; }
    .tool-btn { width: 40px; height: 38px; border: none; border-radius: 6px; background: transparent; color: #cfcfcf; font-size: 15px; cursor: pointer; position: relative; }
    .tool-btn:hover { background: #34383f; }
    .tool-btn.active { background: #2779bd; color: #fff; }
    .tool-btn .kbd { position: absolute; bottom: 1px; right: 3px; font-size: 8px; color: #8fa4b3; }
    .tool-group-sep { width: 30px; height: 1px; background: #3c4046; margin: 4px 0; }

    /* 屬性列 */
    /* 固定保留兩列的高度：控制項多寡改變時（選取/取消選取）畫布位置才不會上上下下跳動；
       適合視窗/適合內容用的是畫布容器當下尺寸，容器高度固定後自然一起正確 */
    /* 覆蓋式屬性列：版面固定只佔一列高（畫布不因選取物件多長一列而上下跳動），
       欄位超過頁寬自動換列全部顯示，第二列起「浮」在畫布上方（absolute 覆蓋，不推擠版面）、不出現捲軸 */
    #propbar { position: absolute; top: 0; left: 0; right: 0; z-index: 650; min-height: 36px; background: #292c31; border-bottom: 1px solid #17191c; box-shadow: 0 2px 6px rgba(0,0,0,.35); display: flex; flex-wrap: wrap; align-content: flex-start; align-items: center; gap: 6px 10px; padding: 6px 12px; }
    #propbar label { font-size: 11.5px; color: #9aa4ad; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
    #propbar input[type=color] { width: 26px; height: 22px; border: 1px solid #45494f; border-radius: 3px; background: transparent; padding: 0 1px; cursor: pointer; }
    #propbar input[type=range] { width: 90px; }
    .ni { width: 54px; background: #1d2024; border: 1px solid #45494f; color: #eee; border-radius: 3px; padding: 3px 5px; font-size: 12px; text-align: center; }
    .ni::-webkit-outer-spin-button, .ni::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .ni[type=number] { -moz-appearance: textfield; }
    .prop-sec { display: none; align-items: center; gap: 10px; }
    .prop-sec.show { display: inline-flex; }
    .pb-btn { background: #34383f; color: #ddd; border: 1px solid #45494f; border-radius: 3px; padding: 3px 7px; font-size: 11.5px; cursor: pointer; white-space: nowrap; }
    .pb-btn:hover { background: #43484f; }

    /* 畫布 */
    #canvas-col { flex: 1; display: flex; flex-direction: column; min-width: 0; position: relative; }
    #canvas-wrap { flex: 1; position: relative; overflow: hidden; background: #3b3f45; margin-top: 36px; }   /* 固定保留屬性列一列高，多出的列以覆蓋方式出現 */
    #canvas-wrap canvas { outline: none; }

    /* 底部狀態列 */
    #statusbar { height: 26px; background: #22252a; border-top: 1px solid #111; display: flex; align-items: center; gap: 16px; padding: 0 12px; font-size: 11.5px; color: #8b949e; flex-shrink: 0; white-space: nowrap; overflow: hidden; }
    #statusbar b { color: #c7d1da; font-weight: 600; }

    /* 跳窗 */
    .modal-mask { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 900; }
    .modal-mask.show { display: flex; align-items: center; justify-content: center; }
    .modal-box { background: #2c2f33; border: 1px solid #45494f; border-radius: 8px; min-width: 360px; max-width: 560px; max-height: 85vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,.6); }
    .modal-box h3 { font-size: 14px; padding: 12px 16px; border-bottom: 1px solid #3c4046; color: #6fc3ff; }
    .modal-body { padding: 14px 16px; font-size: 12.5px; line-height: 1.7; }
    .modal-foot { padding: 10px 16px; border-top: 1px solid #3c4046; text-align: right; display: flex; gap: 8px; justify-content: flex-end; }
    .modal-body table { width: 100%; border-collapse: collapse; }
    .modal-body td, .modal-body th { border: 1px solid #45494f; padding: 4px 8px; font-size: 12px; }
    .modal-body .frm-row { display: flex; align-items: center; gap: 8px; margin: 8px 0; }
    .modal-body .frm-row label { min-width: 84px; color: #9aa4ad; }
    .modal-body select, .modal-body input[type=text] { background: #1d2024; border: 1px solid #45494f; color: #eee; border-radius: 3px; padding: 4px 6px; font-size: 12px; }

    /* 標籤庫面板（左緣可拖曳調寬；夠寬時標籤自動排兩列以上） */
    #label-lib { display: none; position: absolute; top: 0; right: 0; bottom: 0; width: 250px; min-width: 220px; max-width: 70vw; background: #26292e; border-left: 1px solid #111; z-index: 600; flex-direction: column; }
    #label-lib.show { display: flex; }
    #lib-resizer { position: absolute; left: -3px; top: 0; bottom: 0; width: 7px; cursor: ew-resize; z-index: 610; }
    #lib-resizer:hover, #lib-resizer.active { background: rgba(111,195,255,.35); }
    #lib-presets, #lib-customs { display: flex; flex-wrap: wrap; gap: 6px; align-content: flex-start; }
    #lib-presets .lib-sec, #lib-customs .lib-sec { width: 100%; margin-bottom: 0; }
    #lib-presets .lib-item, #lib-customs .lib-item { flex: 1 1 180px; min-width: 180px; max-width: 100%; margin-bottom: 0; }
    #label-lib .lib-head { padding: 9px 12px; border-bottom: 1px solid #3c4046; display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: #6fc3ff; font-weight: 700; }
    #label-lib .lib-body { flex: 1; overflow-y: auto; padding: 8px; }
    #label-lib .lib-sec { font-size: 11px; color: #8b949e; margin: 8px 4px 4px; }
    .lib-item { background: #fff; border: 1px solid #45494f; border-radius: 6px; margin-bottom: 8px; cursor: pointer; position: relative; padding: 8px; text-align: center; }
    .lib-item:hover { border-color: #6fc3ff; box-shadow: 0 0 0 2px rgba(111,195,255,.25); }
    .lib-item img { max-width: 100%; max-height: 86px; }
    .lib-item .lib-name { display: block; font-size: 11px; color: #555; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lib-item .lib-del { position: absolute; top: 3px; right: 5px; color: #c0392b; font-size: 13px; padding: 2px 5px; display: none; }
    .lib-item:hover .lib-del { display: block; }
    /* #標示：固定在縮圖左上角的有底色小徽章（標籤庫面板與管理跳窗共用） */
    .lib-tags { position: absolute; top: 3px; left: 4px; display: flex; gap: 3px; max-width: 72%; overflow: hidden; pointer-events: none; z-index: 2; }
    .lib-tags .lib-tag { font-size: 9.5px; line-height: 15px; font-weight: 700; background: #2779bd; color: #fff; border-radius: 3px; padding: 0 4px; white-space: nowrap; }
    #label-lib .lib-foot { padding: 8px; border-top: 1px solid #3c4046; }

    /* 標籤管理跳窗（框選/Ctrl多選/拖曳搬移） */
    #libmgr-body { display: flex; gap: 10px; height: 62vh; min-width: 0; }
    .lm-col { flex: 1; min-width: 170px; background: #1d2024; border: 1px solid #3c4046; border-radius: 6px; display: flex; flex-direction: column; min-height: 0; }
    .lm-col-head { padding: 6px 10px; font-size: 12.5px; font-weight: 700; border-bottom: 1px solid #3c4046; display: flex; align-items: center; gap: 6px; }
    .lm-grid { flex: 1; overflow-y: auto; padding: 8px; display: flex; flex-wrap: wrap; gap: 6px; align-content: flex-start; }
    .lm-item { width: 104px; background: #fff; border: 2px solid transparent; border-radius: 5px; padding: 4px; cursor: grab; user-select: none; position: relative; }
    .lm-item img { max-width: 100%; max-height: 52px; display: block; margin: 0 auto; pointer-events: none; }
    .lm-item .lm-name { font-size: 10px; color: #444; display: block; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lm-item.sel { border-color: #2779bd; box-shadow: 0 0 0 3px rgba(111,195,255,.85); background: #cfe6fb; }
    .lm-item.sel::after { content: '✓'; position: absolute; top: -7px; left: -7px; width: 18px; height: 18px; border-radius: 50%; background: #2779bd; color: #fff; font-size: 12px; line-height: 18px; text-align: center; font-weight: 700; box-shadow: 0 1px 3px rgba(0,0,0,.5); }
    .lm-item.lock { opacity: .5; cursor: not-allowed; }
    .lm-col.dragover { border-color: #6fc3ff; box-shadow: inset 0 0 0 2px rgba(111,195,255,.45); }
    .lm-chip { font-size: 11px; padding: 2px 8px; border-radius: 10px; border: 1px solid #45494f; color: #8b949e; cursor: pointer; user-select: none; white-space: nowrap; }
    .lm-chip.on { background: #1abb9c; border-color: #1abb9c; color: #fff; }
    .lm-dept-badge { position: absolute; top: 2px; right: 4px; font-size: 9px; color: #1abb9c; background: rgba(26,187,156,.12); border-radius: 3px; padding: 0 3px; }
    /* 管理跳窗：欄內分類標題列（點擊＝整組選取） */
    .lm-cat-head { width: 100%; font-size: 11px; font-weight: 700; color: #d29922; padding: 5px 2px 2px; border-bottom: 1px dashed #4a4f56; cursor: pointer; user-select: none; }
    .lm-cat-head:hover { color: #f0c04a; }
    #lm-rubber { position: fixed; border: 1px dashed #6fc3ff; background: rgba(39,121,189,.15); z-index: 1200; display: none; pointer-events: none; }

    /* 浮動快捷列（半透明暖色）：編輯文字時＝符號鍵浮在輸入框上方；選取物件時＝旋轉角度鍵浮在物件上方 */
    .obj-float { display: none; position: fixed; z-index: 950; background: rgba(255,241,203,.88); border: 1px solid #d9b96a; border-radius: 6px; padding: 4px; box-shadow: 0 3px 10px rgba(0,0,0,.35); gap: 3px; flex-wrap: wrap; max-width: 250px; }
    .obj-float button { background: rgba(255,255,255,.75); color: #5a4a20; border: 1px solid #cbb377; border-radius: 4px; min-width: 30px; padding: 3px 6px; font-size: 13px; cursor: pointer; white-space: nowrap; }
    .obj-float button:hover { background: #fff; }

    /* 拖放提示 */
    #drop-hint { display: none; position: absolute; inset: 14px; border: 3px dashed #6fc3ff; border-radius: 12px; background: rgba(39,121,189,.12); z-index: 500; pointer-events: none; align-items: center; justify-content: center; font-size: 20px; color: #9fd4ff; }
    #drop-hint.show { display: flex; }

    /* toast */
    #toast { position: fixed; left: 50%; bottom: 44px; transform: translateX(-50%); background: rgba(20,22,25,.95); color: #fff; border: 1px solid #45494f; padding: 8px 18px; border-radius: 20px; font-size: 12.5px; display: none; z-index: 999; max-width: 80vw; }
</style>
</head>
<body>

<div id="topbar">
    <span class="brand"><i class="fa fa-paint-brush"></i> 批圖編輯器 <span style="font-size:10px;color:#8b949e;font-weight:400;" title="版本＝程式檔最後更新時間；跟最新修改時間不符表示瀏覽器載到舊版，請按 Ctrl+F5"><?= $EDITOR_VER ?></span></span>
    <button class="tb-btn" onclick="openImageFiles()" title="開啟圖片檔（可多選，也可直接拖檔案進來）"><i class="fa fa-folder-open-o"></i> 開啟圖檔</button>
    <button class="tb-btn" onclick="pasteFromButton()" title="貼上剪貼簿圖片（小畫家複製後按此，或直接 Ctrl+V）"><i class="fa fa-clipboard"></i> 貼上</button>
    <span class="tb-sep"></span>
    <button class="tb-btn" onclick="undo()" title="復原 (Ctrl+Z)"><i class="fa fa-undo"></i></button>
    <button class="tb-btn" onclick="redo()" title="重做 (Ctrl+Y)"><i class="fa fa-repeat"></i></button>
    <span class="tb-sep"></span>
    <button class="tb-btn" onclick="zoomFit()" title="縮放至整個畫布 (Ctrl+0)"><i class="fa fa-arrows-alt"></i> 適合視窗</button>
    <button class="tb-btn" onclick="zoomToSelection()" title="放大檢視目前選取的物件">縮放至選取</button>
    <span id="zoom-label" style="font-size:12px;color:#9aa4ad;min-width:44px;text-align:center;">100%</span>
    <span class="tb-sep"></span>
    <button class="tb-btn" onclick="openCanvasModal()" title="畫布尺寸與背景設定"><i class="fa fa-crop"></i> 畫布</button>
    <button class="tb-btn" onclick="fitArtboardToContent()" title="畫布自動調整為剛好包住所有內容">適合內容</button>
    <span class="tb-sep"></span>
    <button class="tb-btn" onclick="openSecondWindow()" title="再開一個批圖視窗（可移到另一個螢幕；兩窗之間可用「複製選取」互貼）"><i class="fa fa-clone"></i> 開新視窗</button>
    <button class="tb-btn" onclick="copySelectionCrossWindow()" title="把目前選取的內容複製成圖（可到另一個批圖視窗按 Ctrl+V 貼上）"><i class="fa fa-share-square-o"></i> 複製選取→他窗</button>
    <span class="tb-sep"></span>
    <button class="tb-btn" id="btn-label-lib" onclick="toggleLabelLib()" title="標籤庫：內建常用標籤＋自訂標籤，點一下放到圖上"><i class="fa fa-tags"></i> 標籤庫</button>
    <button class="tb-btn" onclick="openWmModal()" title="浮水印：自訂文字/角度/單一或填滿/濃淡"><i class="fa fa-shield"></i> 浮水印</button>
    <button class="tb-btn" onclick="openPartModal()" title="存成料號附件（壓平圖＋可再編輯的工作檔），或開啟既有工作檔繼續編輯"><i class="fa fa-archive"></i> 料號附件</button>
    <button class="tb-btn" onclick="saveDraft(true)" title="把目前畫布暫存在這台電腦的瀏覽器裡（依使用者區分，只保留一份）：下次開啟批圖編輯器會詢問是否接續編輯；選「不開啟」的暫存檔會在該次關閉後自動移除。內容有變動時每 60 秒也會自動暫存。要長期保存或跨電腦請用「料號附件」存工作檔"><i class="fa fa-clock-o"></i> 暫存</button>
    <button class="tb-btn primary" onclick="openExportModal()" title="列印或另存圖片"><i class="fa fa-download"></i> 匯出 / 列印</button>
    <div id="user-info">
        <span><i class="fa fa-user"></i> <?= $safeUser ?>（<?= $safeRole ?>）</span>
        <span id="help-icon" onclick="showModal('help-modal')" title="權限與操作說明">?</span>
    </div>
</div>

<div id="main">
    <div id="toolbar">
        <button class="tool-btn active" id="tool-select" onclick="setTool('select')" title="選取 / 移動 / 縮放（Figma 式物件選取）"><i class="fa fa-mouse-pointer"></i><span class="kbd">V</span></button>
        <button class="tool-btn" id="tool-pan" onclick="setTool('pan')" title="平移畫面（或按住空白鍵拖曳）"><i class="fa fa-hand-paper-o"></i><span class="kbd">H</span></button>
        <div class="tool-group-sep"></div>
        <button class="tool-btn" id="tool-draw" onclick="setTool('draw')" title="畫筆（自由手繪）"><i class="fa fa-pencil"></i><span class="kbd">B</span></button>
        <button class="tool-btn" id="tool-line" onclick="setTool('line')" title="直線（可用「端點」下拉選無/單箭頭/雙箭頭）"><i class="fa fa-minus" style="transform:rotate(-45deg)"></i><span class="kbd">L</span></button>
        <button class="tool-btn" id="tool-connect" onclick="setTool('connect')" title="兩點連線：點第一點→點第二點自動相連，可連續一直連（屬性列選直線/曲線與端點箭頭，選擇會記住）。連好後切回選取(V)雙擊該線＝編輯端點、拖節點調曲度。Esc 取消第一點" style="font-size:15px;font-weight:700;">⤳</button>
        <button class="tool-btn" id="tool-rect" onclick="setTool('rect')" title="矩形"><i class="fa fa-square-o"></i><span class="kbd">R</span></button>
        <button class="tool-btn" id="tool-ellipse" onclick="setTool('ellipse')" title="橢圓"><i class="fa fa-circle-o"></i><span class="kbd">O</span></button>
        <div class="tool-group-sep"></div>
        <button class="tool-btn" id="tool-dimdist" onclick="setTool('dimdist')" title="快速標註：距離（拖曳兩點畫出標註線，中間自動出現輸入框，可輸入實際量測數值）" style="font-size:15px;font-weight:700;">↔</button>
        <button class="tool-btn" id="tool-dimcircle" onclick="setTool('dimcircle')" title="快速標註：直徑（拖曳兩點畫出標註線，中間自動出現帶「⌀」符號的輸入框；不會另外畫圓，適合標在既有的圓/孔上）" style="font-size:15px;font-weight:700;">⌀</button>
        <button class="tool-btn" id="tool-dimangle" onclick="setTool('dimangle')" title="快速標註：角度（點一下產生角度標示＋兩條虛擬輔助線；拖曳虛線頭尾的圓點各自對齊要量的兩條邊，兩條線可分開放；虛線平常自動隱藏，點選角度標示才會出現；刪除標示時輔助線自動一併刪除）" style="font-size:15px;font-weight:700;">∠</button>
        <div class="tool-group-sep"></div>
        <button class="tool-btn" id="tool-text" onclick="setTool('text')" title="文字（點畫布加入，隨時可再點選拖移、雙擊改字、拉角縮放）"><i class="fa fa-font"></i><span class="kbd">T</span></button>
        <button class="tool-btn" id="tool-label" onclick="setTool('label')" title="標籤（固定有邊框的文字框，像標籤機印出來的標籤；雙擊改字，外框自動貼合新字長）"><i class="fa fa-tag"></i></button>
        <div class="tool-group-sep"></div>
        <button class="tool-btn" id="tool-balloon" onclick="setTool('balloon')" title="球標：連續點圖面即依 A、B、C… 自動編號（放上後仍可移動；右下角自動產生「Ⓐ~Ⓕ」範圍文字）" style="font-size:13px;font-weight:700;">Ⓐ</button>
        <button class="tool-btn" id="tool-dc" onclick="setTool('dc')" title="設變標示：點圖面任意位置放標示（菱形/三角形可選），同時在圖面左上角自動產生設變列表（標示＋今日日期＋可輸入文字，越新越上面）" style="font-size:15px;font-weight:700;">◇</button>
        <button class="tool-btn" id="tool-stamp" onclick="setTool('stamp')" title="蓋章：本人簽章（紅）/ 技術課章（<?= $deptStampColor === 'red' ? '紅' : '藍' ?>）/ 發行章（<?= $deptStampColor === 'red' ? '紅' : '藍' ?>）。透明背景直接蓋在圖上，日期自動帶今天" style="font-size:14px;">㊞</button>
        <div class="tool-group-sep"></div>
        <button class="tool-btn" id="tool-maskrect" onclick="setTool('maskrect')" title="遮蓋刪除－長方形（拖出範圍蓋掉客戶資料；顏色可改，放上後仍可移動調整）"><i class="fa fa-eraser"></i><span class="kbd">M</span></button>
        <button class="tool-btn" id="tool-masklasso" onclick="setTool('masklasso')" title="遮蓋刪除－不規則形（按住拖曳圈出範圍）"><i class="fa fa-scissors"></i></button>
        <div class="tool-group-sep"></div>
        <button class="tool-btn" id="tool-cropcopy" onclick="setTool('cropcopy')" title="框選複製：拖出一個範圍，把該範圍的合成影像複製成新圖塊（可貼到別窗、可縮放）"><i class="fa fa-crop"></i><span class="kbd">C</span></button>
        <button class="tool-btn" id="tool-cropmove" onclick="setTool('cropmove')" title="框選搬移（小畫家式）：拖出範圍後切下該區域，原處補白，直接拖到新位置"><i class="fa fa-arrows"></i><span class="kbd">X</span></button>
        <button class="tool-btn" id="tool-cropmovelasso" onclick="setTool('cropmovelasso')" title="框選搬移（不規則，小畫家套索式）：按住拖曳圈出任意形狀後放開，切下該範圍直接拖到新位置"><i class="fa fa-object-ungroup"></i></button>
    </div>

    <div id="canvas-col">
        <div id="propbar">
            <!-- 通用（畫筆/形狀） -->
            <span class="prop-sec show" id="sec-stroke">
                <label>顏色 <input type="color" id="p-stroke" value="#e53935"></label>
                <label>粗細 <input type="range" id="p-width" min="1" max="40" value="3"> <span id="p-width-v" style="color:#ccc;">3</span></label>
                <label id="wrap-line-ends">端點
                    <select id="p-line-ends" title="直線/畫筆工具的頭端形式" style="background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:3px 5px;font-size:12px;">
                        <option value="none">─ 無</option>
                        <option value="end">→ 單箭頭</option>
                        <option value="both">↔ 雙箭頭</option>
                    </select>
                </label>
                <label>線型
                    <select id="p-line-style" title="線段/箭頭/矩形/橢圓/圓的邊框樣式" style="background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:3px 5px;font-size:12px;">
                        <option value="solid">── 實線</option>
                        <option value="dashed">╌╌ 虛線</option>
                        <option value="dashdot">─‧─ 中心線</option>
                    </select>
                </label>
                <label>填色
                    <input type="color" id="p-fill" value="#ffffff">
                    <input type="checkbox" id="p-fill-on" title="形狀是否填色">
                </label>
            </span>
            <!-- 兩點連線 -->
            <span class="prop-sec" id="sec-connect">
                <label>連線方式
                    <select id="p-connect-kind" style="background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:3px 5px;font-size:12px;">
                        <option value="line" selected>直線</option>
                        <option value="curve">曲線</option>
                    </select>
                </label>
                <span style="color:#8b949e;font-size:11px;">點第一點→點第二點自動相連，可連續；切回選取(V)雙擊該線＝編輯端點調曲度；Esc 取消第一點</span>
            </span>
            <!-- 直徑標註樣式 -->
            <span class="prop-sec" id="sec-dimstyle">
                <label>標註樣式
                    <select id="p-dim-style" style="background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:3px 5px;font-size:12px;">
                        <option value="center" selected>置中（文字在線中間）</option>
                        <option value="extend">延伸（文字在線外側）</option>
                    </select>
                </label>
                <span style="color:#8b949e;font-size:11px;">延伸式：線越過拖曳結束端往外延伸，尺寸文字沿斜線顯示在外側（如 (Ø61.12)）</span>
            </span>
            <!-- 文字 -->
            <span class="prop-sec" id="sec-text">
                <label>文字色 <input type="color" id="p-textcolor" value="#d32f2f"></label>
                <label>字級 <input type="number" class="ni" id="p-fontsize" value="28" min="6" max="400"></label>
                <label><input type="checkbox" id="p-bold" checked> 粗體</label>
                <label>底線
                    <select id="p-underline" style="background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:3px 5px;font-size:12px;">
                        <option value="none">無</option>
                        <option value="single">單底線</option>
                        <option value="double">雙底線</option>
                    </select>
                </label>
                <label>底色
                    <input type="color" id="p-textbg" value="#fff59d">
                    <input type="checkbox" id="p-textbg-on" title="文字是否加底色">
                </label>
                <button class="pb-btn" id="sym-btn" onmousedown="event.preventDefault()" onclick="toggleSymPad()"
                    title="插入工程符號（Ø ° ± ▽ ↧ ⌴ ⌵ □ ⌒ Ra ×）：正在編輯文字時插入游標處；只選取文字物件時附加到最後。另外可直接輸入 A^B 自動變上下公差小字（例如 25 -0^-0.18）">Ø± 符號</button>
            </span>
            <!-- 球標 -->
            <span class="prop-sec" id="sec-balloon">
                <label>下一個球標 <input type="text" class="ni" id="p-balloon-next" value="A" maxlength="3" style="width:44px;text-transform:uppercase;" title="若原圖上已印有球標（例如已有A~C），把這裡改成 D 接著編"></label>
                <label>大小 <input type="number" class="ni" id="p-balloon-size" value="40" min="12" max="300"></label>
                <span style="color:#8b949e;font-size:11px;">連續點圖面自動接續編號；Esc 結束。右下角範圍文字會自動更新（舊的自動刪除重建）</span>
            </span>
            <!-- 設變標示 -->
            <span class="prop-sec" id="sec-dc">
                <label>樣式
                    <select id="p-dc-shape" style="background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:3px 5px;font-size:12px;">
                        <option value="diamond">◇ 菱形＋號碼</option>
                        <option value="triangle">△ 三角形＋號碼</option>
                    </select>
                </label>
                <label>號碼 <input type="number" class="ni" id="p-dc-num" value="1" min="1" max="999" title="同一次設變多處都點同一號；圖面已有其他設變標示時，自行改成接續號碼"></label>
                <label>大小 <input type="number" class="ni" id="p-dc-size" value="40" min="12" max="300"></label>
                <span style="color:#8b949e;font-size:11px;">同號碼可連續點多處；該號第一次放置時左上角自動加一列（標示＋今日日期＋雙擊輸入文字，越新越上面）</span>
            </span>
            <!-- 蓋章 -->
            <span class="prop-sec" id="sec-stamp">
                <label>印章
                    <select id="p-stamp-type" style="background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:3px 5px;font-size:12px;">
                        <option value="self">本人簽章（紅）</option>
                        <option value="tech" id="opt-stamp-tech">技術課章（<?= $deptStampColor === 'red' ? '紅' : '藍' ?>）</option>
                        <option value="issue" id="opt-stamp-issue">發行章（<?= $deptStampColor === 'red' ? '紅' : '藍' ?>）</option>
                    </select>
                </label>
                <label>大小 <input type="number" class="ni" id="p-stamp-size" value="110" min="40" max="600"></label>
                <button class="pb-btn" id="btn-stamp-perm" style="display:none;" onclick="openStampPermModal()" title="設定哪些人員可使用技術課章/發行章（管理者限定）"><i class="fa fa-cog"></i> 用章人員</button>
                <span style="color:#8b949e;font-size:11px;">點圖面蓋章（透明背景、日期自動帶今天）；Esc 結束</span>
            </span>
            <!-- 遮蓋 -->
            <span class="prop-sec" id="sec-mask">
                <label>遮蓋色 <input type="color" id="p-maskcolor" value="#ffffff"></label>
                <span style="color:#8b949e;font-size:11px;">（白色 = 刪除效果；遮蓋後仍是可移動的物件，匯出時才壓平）</span>
            </span>
            <!-- 框選複製 / 框選搬移 -->
            <span class="prop-sec" id="sec-crop">
                <label><input type="checkbox" id="p-crop-transparent" checked> 透明選擇</label>
                <span style="color:#8b949e;font-size:11px;">（勾選：白底視為透明，拖到新位置不會蓋住下面的東西，類似小畫家的透明選取；取消勾選＝白底也一起蓋上去）</span>
            </span>
            <!-- 選取到物件時 -->
            <span class="prop-sec" id="sec-object">
                <label>縮放% <input type="number" class="ni" id="p-scale" value="100" min="1" max="3000"></label>
                <label>角度 <input type="number" class="ni" id="p-angle" value="0" min="-360" max="360" title="輸入角度直接旋轉（以物件中心）"></label>
                <label>透明度 <input type="range" id="p-opacity" min="10" max="100" value="100"></label>
                <button class="pb-btn" onclick="layerCmd('front')" title="移到最上層"><i class="fa fa-angle-double-up"></i> 置頂</button>
                <button class="pb-btn" onclick="layerCmd('forward')" title="上移一層"><i class="fa fa-angle-up"></i></button>
                <button class="pb-btn" onclick="layerCmd('backward')" title="下移一層"><i class="fa fa-angle-down"></i></button>
                <button class="pb-btn" onclick="layerCmd('back')" title="移到最下層"><i class="fa fa-angle-double-down"></i> 置底</button>
                <button class="pb-btn" onclick="flipSelected('h')" title="水平翻轉（左右鏡射，像小畫家）"><i class="fa fa-arrows-h"></i> 水平翻轉</button>
                <button class="pb-btn" onclick="flipSelected('v')" title="垂直翻轉（上下鏡射，像小畫家）"><i class="fa fa-arrows-v"></i> 垂直翻轉</button>
                <button class="pb-btn" id="btn-edit-points" style="display:none;" onclick="togglePointEdit()" title="像 Excel 編輯端點：拖曳節點改形狀、點線段中間的「＋」新增節點（直線會轉成折線、矩形會轉成四角多邊形）">編輯端點</button>
                <button class="pb-btn" id="btn-poly-close" style="display:none;" onclick="togglePolyClosed()" title="把折線頭尾接起來變封閉圖形（再按一次打開）">封閉</button>
                <button class="pb-btn" id="btn-poly-smooth" style="display:none;" onclick="togglePolySmooth()" title="節點之間改用圓滑曲線連接（再按一次改回直線）">圓滑</button>
                <button class="pb-btn" onclick="groupCmd()" id="btn-group">群組</button>
                <button class="pb-btn" onclick="mergeSelection()" title="把多個線條/圖形合併成單一物件：縮放移動不走位、雙擊不會拆開（Alt+雙擊才拆）">合併</button>
                <button class="pb-btn" id="btn-label-bg" style="display:none;" onclick="toggleLabelBg()" title="切換這個標籤的底色（白底 ⇄ 透明）">底色</button>
                <button class="pb-btn" onclick="lockSelection()" title="鎖定選取物件：不再被點選（適合底圖）。用右方「解鎖全部」解開"><i class="fa fa-lock"></i> 鎖定</button>
                <button class="pb-btn" onclick="duplicateSelection()" title="複製一份 (Ctrl+D)；Alt+拖曳也可複製"><i class="fa fa-copy"></i> 複製</button>
                <button class="pb-btn" style="color:#ff8a80;" onclick="deleteSelection()" title="刪除選取 (Delete)"><i class="fa fa-trash"></i> 刪除</button>
            </span>
            <!-- 鎖定資訊（有鎖定物件時恆顯示） -->
            <span id="lock-info" style="display:none;align-items:center;gap:6px;margin-left:auto;white-space:nowrap;">
                <span style="font-size:11.5px;color:#e6b800;"><i class="fa fa-lock"></i> 已鎖定 <b id="lock-count">0</b> 個</span>
                <button class="pb-btn" onclick="unlockAll()" title="解除所有鎖定物件"><i class="fa fa-unlock"></i> 解鎖全部</button>
            </span>
        </div>
        <div id="canvas-wrap">
            <canvas id="c"></canvas>
            <div id="drop-hint"><i class="fa fa-picture-o" style="margin-right:10px;"></i>放開以加入圖片</div>
            <div id="label-lib">
                <div id="lib-resizer" title="拖曳調整標籤庫寬度"></div>
                <div class="lib-head"><span><i class="fa fa-tags"></i> 標籤庫</span>
                    <span>
                        <button class="pb-btn" id="lib-manage-btn" onclick="openLibMgr()" title="開啟標籤管理跳窗：框選/Ctrl多選，拖曳搬移（Ctrl+拖曳=複製）">管理</button>
                        <span style="cursor:pointer;color:#8b949e;margin-left:6px;" onclick="toggleLabelLib()" title="關閉"><i class="fa fa-times"></i></span>
                    </span>
                </div>
                <div style="padding:8px 8px 0;">
                    <select id="lib-cat-filter" onchange="renderLibrary()"
                        style="width:100%;background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:4px 6px;font-size:12px;">
                        <option value="">— 全部分類 —</option>
                    </select>
                    <input type="text" id="lib-search" oninput="renderLibrary()" onfocus="this.select()" ondblclick="this.value='';renderLibrary()"
                        placeholder="🔍 搜尋名稱 / #標示 / 分類（模糊）"
                        title="輸入即時篩選（名稱、#標示、分類都比對）；「#關鍵字」只找#標示；空格分隔多個關鍵字＝全部都要符合；雙擊清空"
                        style="width:100%;margin-top:6px;background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:4px 6px;font-size:12px;">
                    <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;color:#9aa4ad;cursor:pointer;"
                        title="勾選後插入的標籤為透明背景（不遮住圖線）；放上後也可用屬性列「底色」按鈕切換">
                        <input type="checkbox" id="lib-transparent"> 以透明背景插入
                    </label>
                </div>
                <div class="lib-body">
                    <div class="lib-sec" style="color:#6fc3ff;">內建標籤（點一下放到圖上，雙擊圖上標籤可改字）</div>
                    <div id="lib-presets"></div>
                    <div class="lib-sec" style="color:#6fc3ff;margin-top:14px;">自訂標籤（全體共用）</div>
                    <div id="lib-customs"><div style="color:#666;font-size:11px;padding:6px;">載入中…</div></div>
                </div>
                <div class="lib-foot" style="display:flex;gap:6px;">
                    <button class="tb-btn" style="flex:1;" onclick="openNewLabelModal()" title="直接輸入文字建立可改字標籤（外框/純文字，之後雙擊可改內容）">
                        <i class="fa fa-magic"></i> 建立文字標籤
                    </button>
                    <button class="tb-btn" style="flex:1;" onclick="saveSelectionAsLabel()" title="把畫布上目前選取的物件存進標籤庫（預設私人）">
                        <i class="fa fa-plus"></i> 把選取存為標籤
                    </button>
                </div>
            </div>
        </div>
        <div id="statusbar">
            <span>畫布 <b id="st-canvas">1600×1200</b></span>
            <button class="tb-btn" onclick="openResizeModal()" title="圖面像素縮放設定：輸入目標寬或高，等比例縮放整張圖面；也可以在這裡設定常用尺寸"><i class="fa fa-arrows-alt"></i></button>
            <button class="tb-btn" onclick="quickResize()" title="一鍵套用預設常用尺寸，整張圖面等比例縮放"><i class="fa fa-bolt"></i> 快速縮放</button>
            <span id="st-sel">未選取</span>
            <span id="st-pos"></span>
            <span style="margin-left:auto;">Ctrl+V 貼圖｜拖檔案進來開圖｜滾輪縮放｜空白鍵拖曳平移｜Delete 刪除｜Ctrl+Z 復原</span>
        </div>
    </div>
</div>

<!-- 匯出 / 列印 -->
<div class="modal-mask" id="export-modal">
    <div class="modal-box">
        <h3><i class="fa fa-download"></i> 匯出 / 列印</h3>
        <div class="modal-body">
            <div class="frm-row"><label>範圍</label>
                <select id="ex-range">
                    <option value="artboard">整個畫布</option>
                    <option value="selection">目前選取的物件</option>
                </select>
            </div>
            <div class="frm-row"><label>格式</label>
                <select id="ex-format">
                    <option value="png">PNG（無損，適合線圖）</option>
                    <option value="jpeg">JPG（檔案較小）</option>
                </select>
            </div>
            <div class="frm-row"><label>解析度倍率</label>
                <select id="ex-mult">
                    <option value="1">1×（原尺寸）</option>
                    <option value="2" selected>2×（建議，列印較清晰）</option>
                    <option value="3">3×</option>
                    <option value="0.5">0.5×</option>
                </select>
            </div>
            <div class="frm-row"><label>檔名</label>
                <input type="text" id="ex-name" style="flex:1;" value="">
            </div>
            <div id="ex-fs-hint" style="font-size:11.5px;color:#8b949e;margin-top:6px;"></div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('export-modal')">取消</button>
            <button class="tb-btn" onclick="doPrint()"><i class="fa fa-print"></i> 列印</button>
            <button class="tb-btn primary" onclick="doSave()"><i class="fa fa-save"></i> 另存圖片…</button>
        </div>
    </div>
</div>

<!-- 畫布設定 -->
<div class="modal-mask" id="canvas-modal">
    <div class="modal-box">
        <h3><i class="fa fa-crop"></i> 畫布設定</h3>
        <div class="modal-body">
            <div class="frm-row"><label>寬 (px)</label><input type="number" class="ni" id="cv-w" style="width:90px;"></div>
            <div class="frm-row"><label>高 (px)</label><input type="number" class="ni" id="cv-h" style="width:90px;"></div>
            <div class="frm-row"><label>背景色</label><input type="color" id="cv-bg" value="#ffffff"></div>
            <div style="font-size:11.5px;color:#8b949e;">提示：開圖時若圖片比畫布大，會自動把畫布撐大。「適合內容」可一鍵讓畫布剛好包住所有物件。</div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('canvas-modal')">取消</button>
            <button class="tb-btn primary" onclick="applyCanvasModal()">套用</button>
        </div>
    </div>
</div>

<!-- 浮水印 -->
<div class="modal-mask" id="wm-modal">
    <div class="modal-box">
        <h3><i class="fa fa-shield"></i> 浮水印</h3>
        <div class="modal-body">
            <div class="frm-row"><label>文字</label><input type="text" id="wm-text" style="flex:1;" placeholder="例如：超正齒輪 樣本 禁止外流"></div>
            <div class="frm-row"><label>角度</label>
                <select id="wm-angle">
                    <option value="-30" selected>-30°（建議：由左下往右上，最不擋線條）</option>
                    <option value="-45">-45°（對角線）</option>
                    <option value="0">0°（水平）</option>
                    <option value="30">30°</option>
                    <option value="45">45°</option>
                </select>
            </div>
            <div class="frm-row"><label>重複</label>
                <select id="wm-mode">
                    <option value="single">單一（畫布中央一個大字）</option>
                    <option value="fill" selected>填滿（自動間距鋪滿整張）</option>
                </select>
            </div>
            <div class="frm-row"><label>濃淡</label>
                <input type="range" id="wm-opacity" min="5" max="60" value="15" style="flex:1;" oninput="document.getElementById('wm-op-v').textContent=this.value+'%'">
                <span id="wm-op-v" style="min-width:38px;color:#ccc;">15%</span>
            </div>
            <div class="frm-row"><label>字級(填滿)</label><input type="number" class="ni" id="wm-size" value="60" min="12" max="400" title="填滿模式的單字大小；單一模式字級自動撐滿畫布約七成寬"></div>
            <div class="frm-row"><label>顏色</label><input type="color" id="wm-color" value="#888888"></div>
            <div style="font-size:11.5px;color:#8b949e;">濃淡預設 15%，不影響閱讀圖面；浮水印會自動鎖定（不會被誤點），要調整先按「解鎖全部」。重新套用會取代舊浮水印。</div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="removeWatermark();hideModal('wm-modal')"><i class="fa fa-trash"></i> 移除浮水印</button>
            <button class="tb-btn" onclick="hideModal('wm-modal')">取消</button>
            <button class="tb-btn primary" onclick="applyWatermark()"><i class="fa fa-check"></i> 套用</button>
        </div>
    </div>
</div>

<!-- 圖面像素縮放 -->
<div class="modal-mask" id="resize-modal">
    <div class="modal-box">
        <h3><i class="fa fa-arrows-alt"></i> 圖面像素縮放</h3>
        <div class="modal-body">
            <div style="font-size:11.5px;color:#8b949e;margin-bottom:8px;">目前尺寸：<b id="rs-current">—</b> px。輸入寬或高，另一邊會自動依比例帶出；套用後畫面上所有物件會一起等比例縮放（可 Ctrl+Z 復原）。</div>
            <div class="frm-row"><label>寬 (px)</label><input type="number" class="ni" id="rs-w" min="10" style="flex:1;"></div>
            <div class="frm-row"><label>高 (px)</label><input type="number" class="ni" id="rs-h" min="10" style="flex:1;"></div>
            <div class="frm-row"><button class="tb-btn primary" style="flex:1;" onclick="applyResize()"><i class="fa fa-check"></i> 套用縮放</button></div>
            <hr style="border-color:#3c4046;margin:12px 0;">
            <div style="font-weight:700;color:#6fc3ff;font-size:12.5px;margin-bottom:6px;"><i class="fa fa-star"></i> 常用尺寸（最多 3 組，可命名；勾選「預設」＝按「快速縮放」套用的那組）</div>
            <div id="rs-presets"></div>
            <div class="frm-row" style="margin-top:6px;">
                <input type="text" id="rs-preset-name" placeholder="名稱（例如 A4、SOP）" style="flex:1;">
                <input type="number" class="ni" id="rs-preset-w" placeholder="寬" style="width:70px;">
                <input type="number" class="ni" id="rs-preset-h" placeholder="高" style="width:70px;">
                <button class="tb-btn" onclick="addResizePreset()"><i class="fa fa-plus"></i> 新增</button>
            </div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('resize-modal')">關閉</button>
        </div>
    </div>
</div>

<!-- 存為標籤 -->
<div class="modal-mask" id="savelabel-modal">
    <div class="modal-box">
        <h3><i class="fa fa-tag"></i> 把選取存為標籤</h3>
        <div class="modal-body">
            <div class="frm-row"><label>標籤名稱</label><input type="text" id="sl-name" style="flex:1;" placeholder="例如：熱處理HRC50"></div>
            <div class="frm-row"><label>分類</label><input type="text" id="sl-cat" list="lib-cat-datalist" style="flex:1;" placeholder="可留空（未分類）；輸入新名稱即新增分類"></div>
            <div class="frm-row"><label>#標示</label><input type="text" id="sl-tags" list="lib-tag-datalist" style="flex:1;" placeholder="選填；空格分隔多個，例如：出貨 急件（#可省略）"></div>
            <datalist id="lib-cat-datalist"></datalist>
            <datalist id="lib-tag-datalist"></datalist>
            <div class="frm-row"><label>範圍</label>
                <select id="sl-scope" onchange="document.getElementById('sl-dept').style.display=(this.value==='dept')?'':'none'">
                    <option value="private" selected>私人（只有自己看得到）</option>
                    <option value="dept">部門（同部門共用）</option>
                </select>
                <select id="sl-dept" style="display:none;"></select>
            </div>
            <div style="font-size:11.5px;color:#8b949e;">預設存為私人標籤；之後可在標籤庫用「管理」多選複製/搬移到部門。標籤放到圖上後仍可雙擊改字、縮放。</div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('savelabel-modal')">取消</button>
            <button class="tb-btn primary" onclick="confirmSaveLabel()"><i class="fa fa-save"></i> 儲存</button>
        </div>
    </div>
</div>

<!-- 標籤管理跳窗 -->
<div class="modal-mask" id="libmgr-modal">
    <div class="modal-box" style="min-width:82vw;max-width:94vw;">
        <h3><i class="fa fa-tags"></i> 標籤管理
            <span style="font-size:11.5px;color:#8b949e;font-weight:400;margin-left:10px;">
                框選、Ctrl+點選、<b>Shift+點選（範圍）</b>多選 → 拖曳到目標欄＝搬移，<b>按住 Ctrl 拖曳＝複製</b>；部門欄先點亮要發佈的部門（可複選），拖入時同時放到所有亮起的部門；欄內已依分類分組，<b>點分類標題＝整組選取</b>，選好按「設定分類」即可整批改分類
            </span>
        </h3>
        <div class="modal-body" style="max-width:none;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <span style="font-size:12px;color:#9aa4ad;">已選 <b id="lm-sel-count" style="color:#6fc3ff;">0</b> 個</span>
                <span style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="pb-btn" onclick="lmMakeGroupLabel()" title="把選取的多個標籤組成一個「群組標籤」存進庫：之後點一下整組插入圖面，不用每次自己拉再群組"><i class="fa fa-object-group"></i> 組成群組標籤</button>
                    <button class="pb-btn" onclick="lmOpenSetCat()" title="批次設定選取標籤的分類（分類名稱自訂，輸入新名稱即新增分類）"><i class="fa fa-folder-o"></i> 設定分類</button>
                    <button class="pb-btn" onclick="lmOpenSetTags()" title="批次設定選取標籤的 #標示：以有底色小徽章固定顯示在縮圖左上角，標籤庫搜尋框可用「#關鍵字」快速找到"><i class="fa fa-hashtag"></i> 設定#標示</button>
                    <button class="pb-btn" onclick="lmSetHideName(1)" title="選取的標籤在標籤庫不顯示名稱（標籤內容與名稱幾乎相同時用；滑鼠停留仍會提示）"><i class="fa fa-eye-slash"></i> 隱藏名稱</button>
                    <button class="pb-btn" onclick="lmSetHideName(0)" title="恢復顯示標籤名稱"><i class="fa fa-eye"></i> 顯示名稱</button>
                    <button class="pb-btn" style="color:#ff8a80;" onclick="lmDeleteSelected()"><i class="fa fa-trash"></i> 刪除選取</button>
                </span>
            </div>
            <div id="libmgr-body"></div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn primary" onclick="hideModal('libmgr-modal')">完成</button>
        </div>
    </div>
</div>
<div id="lm-rubber"></div>
<!-- 工程符號浮動面板（屬性列「Ø± 符號」按鈕點開；mousedown preventDefault＝插入時不中斷文字編輯） -->
<div id="sym-pad" onmousedown="event.preventDefault()"
    style="display:none;position:fixed;z-index:900;background:#26292e;border:1px solid #45494f;border-radius:6px;padding:6px;box-shadow:0 4px 14px rgba(0,0,0,.5);gap:4px;flex-wrap:wrap;width:212px;"></div>
<!-- 浮動快捷列：編輯文字＝符號鍵（mousedown preventDefault＝插入不中斷編輯）；選取物件＝旋轉角度鍵 -->
<div id="float-syms" class="obj-float" onmousedown="event.preventDefault()"></div>
<div id="float-rot" class="obj-float"></div>

<!-- 料號附件：儲存 / 開啟工作檔 -->
<div class="modal-mask" id="partfile-modal">
    <div class="modal-box" style="min-width:480px;">
        <h3><i class="fa fa-archive"></i> 料號附件</h3>
        <div class="modal-body">
            <div class="frm-row"><label>料號/圖號</label>
                <input type="text" id="pf-q" style="flex:1;" placeholder="輸入料號或圖號關鍵字後按搜尋">
                <button class="tb-btn" onclick="pfSearch()"><i class="fa fa-search"></i> 搜尋</button>
            </div>
            <div class="frm-row"><label>選擇料號</label>
                <select id="pf-part" style="flex:1;" onchange="pfLoadWorkfiles()"><option value="">— 請先搜尋 —</option></select>
            </div>
            <hr style="border-color:#3c4046;margin:10px 0;">
            <div style="font-weight:700;color:#6fc3ff;font-size:12.5px;margin-bottom:6px;"><i class="fa fa-save"></i> 儲存目前畫布到此料號</div>
            <div class="frm-row"><label>檔名</label>
                <input type="text" id="pf-name" style="flex:1;">
                <button class="tb-btn primary" onclick="pfSave()"><i class="fa fa-save"></i> 儲存</button>
            </div>
            <div class="frm-row"><label>分享範圍</label>
                <select id="pf-scope" style="flex:1;" onchange="pfOnScopeChange()">
                    <option value="private">私人（只有自己看得到）</option>
                    <option value="dept" selected>部門共用（同部門看得到）</option>
                    <option value="custom">指定人員（自選要分享給誰）</option>
                </select>
                <select id="pf-dept" style="display:none;"></select>
            </div>
            <div id="pf-share-box" style="display:none;margin:6px 0 4px;">
                <input type="text" id="pf-share-q" placeholder="搜尋姓名篩選" style="width:100%;margin-bottom:4px;" oninput="pfRenderShareUsers()">
                <div id="pf-share-list" style="max-height:130px;overflow-y:auto;border:1px solid #45494f;border-radius:4px;padding:6px;">載入中…</div>
            </div>
            <div style="font-size:11.5px;color:#8b949e;margin-bottom:10px;">
                會存兩個附件：<b>壓平 PNG</b>（附件系統直接看/印）＋<b>工作檔 .egwork.json</b>（用下方「開啟」重新載入後，標籤/文字/球標全部仍可編輯）。工作檔沒有「全公司共用」，避免所有人都能改到；同一料號最多保留 <?= (int)$workfileMaxCount ?> 份，超過會自動刪掉最舊的一份（不影響剛存好的這份）。
            </div>
            <hr style="border-color:#3c4046;margin:10px 0;">
            <div style="font-weight:700;color:#6fc3ff;font-size:12.5px;margin-bottom:6px;"><i class="fa fa-folder-open-o"></i> 開啟此料號的批圖工作檔</div>
            <div id="pf-works-list" style="max-height:180px;overflow-y:auto;border:1px solid #45494f;border-radius:4px;padding:4px;font-size:12px;">選料號後自動列出</div>
            <div style="font-size:11.5px;color:#8b949e;margin-top:6px;">開啟會<b>取代目前畫布內容</b>（會先確認）；改完再按上方「儲存」會存成新版本，不覆蓋舊檔。最新一份不能刪除。</div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('partfile-modal')">關閉</button>
        </div>
    </div>
</div>

<!-- 建立文字標籤（可改字規格標籤建立器） -->
<div class="modal-mask" id="newlabel-modal">
    <div class="modal-box" style="min-width:440px;">
        <h3><i class="fa fa-magic"></i> 建立文字標籤</h3>
        <div class="modal-body">
            <div class="frm-row"><label>標籤文字</label>
                <textarea id="nl-text" rows="3" style="flex:1;background:#1d2024;border:1px solid #45494f;color:#eee;border-radius:3px;padding:4px 6px;font-size:12px;" placeholder="可多行（換行＝標籤內換行）"></textarea>
            </div>
            <div class="frm-row"><label>符號</label>
                <span id="nl-sym-strip" style="display:inline-flex;gap:2px;align-items:center;flex-wrap:wrap;" title="點一下插入到標籤文字的游標處"></span>
            </div>
            <div class="frm-row"><label>樣式</label>
                <select id="nl-kind">
                    <option value="box" selected>█ 外框標籤（粗黑框＋粗體字）</option>
                    <option value="plain">─ 純文字（無外框）</option>
                </select>
                <label style="min-width:0;"><input type="checkbox" id="nl-transparent"> 透明底</label>
            </div>
            <div class="frm-row"><label>字級</label>
                <input type="number" class="ni" id="nl-size" value="44" min="10" max="200">
                <label style="min-width:0;">對齊</label>
                <select id="nl-align">
                    <option value="center" selected>置中</option>
                    <option value="left">靠左</option>
                </select>
            </div>
            <div class="frm-row"><label>名稱</label><input type="text" id="nl-name" style="flex:1;" placeholder="留空＝以標籤文字第一行當名稱"></div>
            <div class="frm-row"><label>分類</label><input type="text" id="nl-cat" list="lib-cat-datalist" style="flex:1;" placeholder="可留空"></div>
            <div class="frm-row"><label>#標示</label><input type="text" id="nl-tags" list="lib-tag-datalist" style="flex:1;" placeholder="選填；空格分隔多個（#可省略）"></div>
            <div class="frm-row"><label>範圍</label>
                <select id="nl-scope" onchange="document.getElementById('nl-dept').style.display=(this.value==='dept')?'':'none'">
                    <option value="private" selected>私人</option>
                    <option value="dept">部門</option>
                </select>
                <select id="nl-dept" style="display:none;"></select>
            </div>
            <div style="font-size:11.5px;color:#8b949e;">建立後放到圖面仍可雙擊改字、外框自動貼合、屬性列可改文字色與底色。</div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('newlabel-modal')">取消</button>
            <button class="tb-btn primary" onclick="confirmNewLabel()"><i class="fa fa-save"></i> 建立</button>
        </div>
    </div>
</div>

<!-- 批次設定分類 -->
<div class="modal-mask" id="setcat-modal">
    <div class="modal-box">
        <h3><i class="fa fa-folder-o"></i> 設定分類</h3>
        <div class="modal-body">
            <div class="frm-row"><label>分類名稱</label>
                <input type="text" id="sc-cat" list="lib-cat-datalist" style="flex:1;" placeholder="輸入新名稱即新增分類；留空＝未分類"
                    onkeydown="if(event.key==='Enter'){event.preventDefault();confirmSetCat();}">
            </div>
            <div style="font-size:11.5px;color:#8b949e;">套用到目前選取的標籤（只能改自己的標籤，管理者不限）。要「改分類名稱」：篩選該分類→全選→在此輸入新名稱。</div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('setcat-modal')">取消</button>
            <button class="tb-btn primary" onclick="confirmSetCat()"><i class="fa fa-check"></i> 套用</button>
        </div>
    </div>
</div>

<!-- 批次設定 #標示 -->
<div class="modal-mask" id="settags-modal">
    <div class="modal-box">
        <h3><i class="fa fa-hashtag"></i> 設定#標示</h3>
        <div class="modal-body">
            <div class="frm-row"><label>#標示</label>
                <input type="text" id="st-tags" list="lib-tag-datalist" style="flex:1;" placeholder="空格分隔多個（#可省略）；留空＝清除標示"
                    onkeydown="if(event.key==='Enter'){event.preventDefault();confirmSetTags();}">
            </div>
            <div style="font-size:11.5px;color:#8b949e;">套用到目前選取的標籤（只能改自己的標籤，管理者不限）。#標示會以藍底小徽章固定顯示在標籤縮圖左上角，標籤庫搜尋框輸入「#關鍵字」可只搜尋標示。</div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('settags-modal')">取消</button>
            <button class="tb-btn primary" onclick="confirmSetTags()"><i class="fa fa-check"></i> 套用</button>
        </div>
    </div>
</div>

<!-- 用章人員設定（管理者限定） -->
<div class="modal-mask" id="stampperm-modal">
    <div class="modal-box" style="min-width:420px;">
        <h3><i class="fa fa-cog"></i> 部門印章使用人員（技術課章／發行章）</h3>
        <div class="modal-body">
            <div class="frm-row"><label>部門</label>
                <select id="sp-dept" onchange="renderStampUsers()" style="flex:1;"></select>
            </div>
            <div id="sp-users" style="max-height:260px;overflow-y:auto;border:1px solid #45494f;border-radius:4px;padding:8px;margin-top:6px;">載入中…</div>
            <div style="font-size:11.5px;color:#8b949e;margin-top:8px;">
                勾選的人員可使用「技術課章」與「發行章」（跨部門勾選會一併保留）。管理者不受此限制固定可用。本人簽章人人可用。
            </div>
            <hr style="border-color:#3c4046;margin:12px 0;">
            <div class="frm-row"><label>印章顏色</label>
                <select id="sp-color" style="flex:1;">
                    <option value="blue">藍色</option>
                    <option value="red">紅色</option>
                </select>
            </div>
            <div style="font-size:11.5px;color:#8b949e;margin-top:4px;">
                套用到「技術課章」與「發行章」（全體共用同一個顏色）；本人簽章固定紅色不受此設定影響。
            </div>
        </div>
        <div class="modal-foot">
            <button class="tb-btn" onclick="hideModal('stampperm-modal')">取消</button>
            <button class="tb-btn primary" onclick="saveStampUsers()"><i class="fa fa-save"></i> 儲存</button>
        </div>
    </div>
</div>

<!-- 權限 / 操作說明 -->
<div class="modal-mask" id="help-modal">
    <div class="modal-box">
        <h3><i class="fa fa-question-circle"></i> 批圖編輯器：權限與操作說明</h3>
        <div class="modal-body">
            <b style="color:#6fc3ff;">各角色權限</b>
            <table style="margin:6px 0 12px;">
                <tr><th style="width:110px;">角色</th><th>權限</th></tr>
                <tr><td>管理者</td><td>固定擁有全部功能（系統規則）</td></tr>
                <tr><td>批圖使用者</td><td>可使用批圖編輯器全部功能</td></tr>
                <tr><td>未指派者</td><td>系統尚未指派任何「批圖使用者」前暫時開放；一旦有人被指派，未指派者即無法開啟本頁</td></tr>
            </table>
            <div style="font-size:11.5px;color:#8b949e;margin-bottom:12px;">角色指派：使用者權限管理頁 →「批圖編輯器」區塊。</div>
            <b style="color:#6fc3ff;">① 開圖與圖片</b>
            <ul style="padding-left:18px;margin:4px 0 10px;">
                <li>小畫家複製 → 本視窗按 <b>Ctrl+V</b> 貼入；圖檔直接<b>拖進視窗</b>開啟（可多張，排版後匯出＝合併）</li>
                <li>圖比畫布大會自動撐大畫布；「適合內容」讓畫布剛好包住所有東西</li>
                <li>底圖調好後按屬性列「<b>鎖定</b>」→ 點擊穿透不誤選；屬性列右側「解鎖全部」解開</li>
            </ul>
            <b style="color:#6fc3ff;">② 編修與遮蓋</b>
            <ul style="padding-left:18px;margin:4px 0 10px;">
                <li>畫筆(B)/直線(L)/矩形(R)/橢圓(O)；所有東西都是物件，隨時可移動、縮放、刪除；直線與畫筆都可在屬性列選<b>線型（實線/虛線/中心線）</b>與<b>端點（無/單箭頭/雙箭頭）</b></li>
                <li><b>兩點連線（⤳）</b>：點第一點→點第二點自動相連、可連續一直連，屬性列選<b>直線或曲線</b>（直線也可帶箭頭端點；選擇會記住）。曲線＝沿真圓弧生成的圓潤勾線；連好後切回選取(V)<b>雙擊該線＝編輯端點</b>，拖節點調曲度/改位置、「＋」加節點；Esc 取消已點的第一點</li>
                <li>遮蓋刪除客戶資料：矩形(M)或不規則套索圈選，遮蓋色可改，匯出時才壓平</li>
                <li>框選複製(C)：框一個範圍變成新圖塊；<b>框選搬移(X)</b>＝小畫家式切下搬走，所見即所得——底圖挖空、<b>完整落在框內的物件（圓/矩形/線條/標籤/文字…）一起烙進切塊搬走</b>；只壓到框線一部分的物件不動。旁邊的<b>套索工具</b>是不規則形狀版，按住拖曳圈任意形狀後放開即可切下。兩者都可連續使用，Esc 或切別的工具才離開。跨視窗貼上用 <b>Ctrl+Shift+V</b>（Ctrl+V 優先貼系統剪貼簿）</li>
                <li>遮蓋/形狀/直線等工具<b>畫完保持啟用可連續畫</b>，Esc 或 V 回選取；<b>Ctrl+A</b> 全選畫布物件；<b>方向鍵微調</b>選取物（Shift＝10px）；屬性列可輸入<b>角度</b>（直線 0 度＝水平線）；多選一次改粗細/顏色；「合併」把多線條變單一物件（Alt+雙擊才拆）</li>
            </ul>
            <b style="color:#6fc3ff;">③ 文字與標籤庫</b>
            <ul style="padding-left:18px;margin:4px 0 10px;">
                <li>文字(T)/標籤放上後<b>永遠可再拖移、雙擊改字、拉角縮放</b>（不像小畫家會固定）</li>
                <li>標籤庫：內建＋自訂（全體共用、可分類篩選）。點一下放到圖上；<b>雙擊改字</b>，外框自動貼合字長；「底色」按鈕或插入前勾「透明背景」可切換白底/透明</li>
                <li>製程表格標籤（如 (  )齒研）：<b>雙擊空白格可填數值</b>（如公差 29.91 -0.056），Enter 可換行打上下公差，格子自動加寬加高；雙擊標題可把括號填入製程序號</li>
                <li>自己組好的標籤（矩形＋文字框選）按「把選取存為標籤」入庫；填分類方便日後查找</li>
                <li><b>Alt＋拖曳</b>＝原地留一份拖走一份；Ctrl+D 原地複製</li>
                <li>群組：框選按 <b>Ctrl+G</b>；<b>雙擊群組＝進入</b>拆成多選可調個別位置，調完 Ctrl+G 組回（標籤群組雙擊是改字，用 <b>Alt＋雙擊</b>進入）</li>
            </ul>
            <b style="color:#6fc3ff;">④ 球標與設變標示</b>
            <ul style="padding-left:18px;margin:4px 0 10px;">
                <li>球標 Ⓐ：連續點圖面自動 A、B、C…編號；右下角自動產生「Ⓐ～Ⓕ」範圍（圓圈樣式與圖面球標一致，變動自動刪舊重建）。原圖已有球標 → 把「下一個球標」改成接續字母</li>
                <li>設變標示 ◇/△：<b>同一次設變多處都點同一個號碼</b>（號碼欄可自訂起始，圖面已有舊設變時接續）；該號第一次放置時左上角自動加一列「標示＋今日日期」，<b>雙擊該列輸入說明文字</b>，越新越上面。下一次設變記得把號碼欄+1</li>
                <li>蓋章 ㊞：本人簽章（紅，人人可用）／技術課章與發行章（<?= $deptStampColor === 'red' ? '紅' : '藍' ?>，<b>限管理者在「用章人員」勾選的人員</b>，顏色也在同一個跳窗設定）；透明背景直接蓋在圖上（自動去背），日期自動帶今天，可移動縮放</li>
                <li>標籤庫分三層：公司共用（管理者管理）／部門標籤（同部門共用）／私人標籤（只有自己看得到）。新標籤<b>預設存私人</b>；面板右上「管理」開跳窗：<b>框選或 Ctrl+點選多選 → 拖曳到目標欄＝搬移、Ctrl+拖曳＝複製</b>，也可批次刪除</li>
            </ul>
            <b style="color:#6fc3ff;">⑤ 檢視、匯出與跨視窗</b>
            <ul style="padding-left:18px;margin:4px 0 10px;">
                <li>滾輪縮放；<b>按住滾輪中鍵拖移</b>或空白鍵＋拖曳平移；「縮放至選取」放大局部細修</li>
                <li>「開新視窗」再開一個編輯器（可拖到另一個螢幕）；「複製選取→他窗」＋在另一窗 Ctrl+V 互貼</li>
                <li>匯出/列印：整個畫布或只匯出選取；PNG/JPG、解析度倍率（列印建議2×）</li>
                <li>浮水印：頂列「浮水印」→ 自訂文字/角度（建議-30°）/單一或填滿（自動間距）/濃淡（預設15%不影響閱讀）；套用後自動鎖定，重新套用會取代舊的</li>
                <li>料號附件：頂列「料號附件」→ 搜尋料號 → 儲存＝壓平PNG＋<b>可再編輯的工作檔</b>；之後從同跳窗開啟工作檔，標籤/文字/球標全部還能改，改完儲存成新版本</li>
                <li>標籤庫「建立文字標籤」＝直接打字生成可改字標籤；管理跳窗「組成群組標籤」＝多選標籤打包，之後點一下整組插入（雙擊進入可調個別位置）；「設定分類」批次改分類（名稱自訂）；管理跳窗欄內依分類分組，<b>點分類標題＝整組選取</b></li>
                <li>標籤搜尋與#標示：標籤庫面板上方搜尋框可模糊搜尋名稱/#標示/分類（「#關鍵字」只找標示、空格分隔＝全部要符合、雙擊清空）；「設定#標示」把選取標籤加上左上角藍底小徽章，方便分群找尋</li>
                <li>工程符號與公差：屬性列「文字」區有符號鈕（Ø ° ± ▽ ↧ ⌴ ⌵ □ ⌒ Ra ×），編輯文字時點一下插到游標處（研磨＝連按▽）；文字輸入 <b>A^B</b>（如 25 -0^-0.18）結束編輯自動變成上下公差小字，雙擊可還原 ^ 字串重編</li>
                <li>研磨/粗糙度記號：標籤庫內建「<b>加工符號</b>」分類有「研磨記號 G＋▽▽▽」與「粗糙度記號 0.8＋G」，點一下放到圖上（預設透明底、可移動縮放旋轉），<b>雙擊 G 或 0.8 即可改字</b></li>
            </ul>
            <b style="color:#6fc3ff;">⑥ 快捷鍵</b>
            <table style="margin:6px 0 4px;">
                <tr><td style="width:130px;">V / H</td><td>選取 / 平移</td><td style="width:130px;">B / L / R / O</td><td>畫筆/直線/矩形/橢圓</td></tr>
                <tr><td>T / M / C</td><td>文字 / 遮蓋矩形 / 框選複製</td><td>Delete</td><td>刪除選取</td></tr>
                <tr><td>Ctrl+Z / Ctrl+Y</td><td>復原 / 重做</td><td>Ctrl+C / Ctrl+V</td><td>複製 / 貼上（含跨視窗、小畫家）</td></tr>
                <tr><td>Ctrl+D</td><td>原地複製</td><td>Alt＋拖曳</td><td>拖曳複製</td></tr>
                <tr><td>Ctrl+G</td><td>群組 / 進入群組</td><td>Ctrl+0 / Esc</td><td>適合視窗 / 回選取工具</td></tr>
            </table>
        </div>
        <div class="modal-foot"><button class="tb-btn primary" onclick="hideModal('help-modal')">知道了</button></div>
    </div>
</div>

<input type="file" id="file-input" accept="image/*" multiple style="display:none;">
<div id="toast"></div>

<script src="../../resource/js/fabric.min.js?v=<?= filemtime(__DIR__ . '/../../resource/js/fabric.min.js') ?>"></script><!-- 帶檔案時間當版本參數：修過的 fabric 才不會被瀏覽器快取的舊檔蓋掉 -->
<script>
'use strict';
/* Fabric 5.3.0 已知 bug 修補（本地 fabric.min.js 已修字，這裡是多層保險）：
   1) textBaseline 預設值誤植 'alphabetical'（非法值）→ 瀏覽器每幀、每個文字物件都印一條主控台警告，
      警告洪流就是「操作卡頓/當機30秒」的元兇。光改 prototype 預設值不夠——舊工作檔/舊標籤/剪貼簿
      序列化時把錯值存成了物件「自己的」屬性，載入後蓋過預設值——所以在渲染入口把每顆實例就地矯正。
   2) IText 游標動畫在物件已被移出畫布後仍可能再跑一拍 → this.canvas undefined → getRetinaScaling
      例外打斷渲染迴圈（殘影/卡頓來源之一），入口加防呆直接略過。 */
if (window.fabric && fabric.Text) {
    if (fabric.Text.prototype.textBaseline === 'alphabetical') fabric.Text.prototype.textBaseline = 'alphabetic';
    const __setTextStyles = fabric.Text.prototype._setTextStyles;
    fabric.Text.prototype._setTextStyles = function (ctx, charStyle, forMeasuring) {
        if (this.textBaseline === 'alphabetical') this.textBaseline = 'alphabetic';
        return __setTextStyles.call(this, ctx, charStyle, forMeasuring);
    };
    const __renderCursor = fabric.IText.prototype.renderCursorOrSelection;
    fabric.IText.prototype.renderCursorOrSelection = function () {
        if (!this.canvas || !this.canvas.contextTop) return;
        return __renderCursor.call(this);
    };
    /* 3) 已被移出畫布卻仍殘留為「作用中選取」的物件：每一幀畫控制點都因 this.canvas=undefined 拋例外
       （drawControls → getRetinaScaling），渲染迴圈整個死掉＝殘影/凍結。物件層防呆略過，
       畫布層順手把殘留選取清掉，讓畫面自然恢復。 */
    const __objDrawControls = fabric.Object.prototype.drawControls;
    fabric.Object.prototype.drawControls = function (ctx, styleOverride) {
        if (!this.canvas) return this;
        return __objDrawControls.call(this, ctx, styleOverride);
    };
    // 多選(activeSelection)裡的子物件畫框線走 drawBordersInGroup（不經過 drawControls），
    // 子物件被移出畫布後 this.canvas.getZoom 一樣會拋，這裡同樣略過
    const __objDrawBIG = fabric.Object.prototype.drawBordersInGroup;
    if (__objDrawBIG) {
        fabric.Object.prototype.drawBordersInGroup = function () {
            if (!this.canvas) return this;
            return __objDrawBIG.apply(this, arguments);
        };
    }
    if (fabric.Canvas.prototype.drawControls) {
        const __cvsDrawControls = fabric.Canvas.prototype.drawControls;
        fabric.Canvas.prototype.drawControls = function (ctx) {
            const ao = this._activeObject;
            // 殘留選取：本體被移出畫布，或多選裡任一子物件已被移出（undo 重建後常見）→ 清掉不畫
            if (ao && (!ao.canvas || (ao.type === 'activeSelection' && ao.getObjects && ao.getObjects().some(o => !o.canvas)))) {
                this._activeObject = null;
                return;
            }
            return __cvsDrawControls.call(this, ctx);
        };
    }
}
/* ════════════════════════════════════════════════════════════════════
   批圖編輯器主程式（Fabric.js 5.3）
   物件模型：所有東西（圖片/文字/形狀/遮蓋）都是可再編輯的物件（Figma 式），
   匯出時才壓平成點陣圖（小畫家式結果）。
   ════════════════════════════════════════════════════════════════════ */
const USER_ID = <?= (int)$uid ?>;
const USER_CNAME = <?= json_encode($userCname, JSON_UNESCAPED_UNICODE) ?>;
const OWN_COMPANY = <?= json_encode($ownCompany, JSON_UNESCAPED_UNICODE) ?>;
const IS_MGR = <?= $isMgr ? 'true' : 'false' ?>;
const CAN_DEPT_STAMP = <?= $canDeptStamp ? 'true' : 'false' ?>;
let deptStampColorHex = <?= json_encode($deptStampColor === 'red' ? '#cf3a2b' : '#2b4a9b') ?>;
const CAN_DELETE_WORKFILE = <?= $canDeleteWorkfile ? 'true' : 'false' ?>;
const WORKFILE_MAX_COUNT = <?= (int)$workfileMaxCount ?>;
const USER_PREFS = <?= json_encode($userPrefs, JSON_UNESCAPED_UNICODE) ?>;
const RESIZE_PRESETS = <?= json_encode($resizePresets, JSON_UNESCAPED_UNICODE) ?>;
const RESIZE_DEFAULT_IDX = <?= (int)$resizeDefaultIdx ?>;
const MY_DEPTS = <?= json_encode($myDepts, JSON_UNESCAPED_UNICODE) ?>;
const MY_MAIN_DEPT_ID = <?= (int)$myMainDeptId ?>;
const CLIP_KEY = 'eg_imgedit_clip';           // 跨視窗剪貼簿（localStorage，同網域共用）
const DIRDB = 'eg_imgedit_fs';                // IndexedDB：預設儲存資料夾 handle

let artW = 1600, artH = 1200;                 // 畫布（工作區）尺寸
let currentTool = 'select';
let spaceDown = false;

const canvas = new fabric.Canvas('c', {
    backgroundColor: null,
    preserveObjectStacking: true,
    selection: true,
    stopContextMenu: true,
    fireRightClick: true,
    fireMiddleClick: true,
    uniformScaling: true
});
// 擋掉瀏覽器中鍵自動捲動，讓「按住滾輪中鍵拖移畫面」可用
const wrapForMiddle = document.getElementById('canvas-wrap');
wrapForMiddle.addEventListener('mousedown', function (e) { if (e.button === 1) e.preventDefault(); });
wrapForMiddle.addEventListener('auxclick', function (e) { if (e.button === 1) e.preventDefault(); });
fabric.Object.prototype.transparentCorners = false;
fabric.Object.prototype.cornerColor = '#2779bd';
fabric.Object.prototype.cornerStyle = 'circle';
fabric.Object.prototype.cornerSize = 9;
fabric.Object.prototype.borderColor = '#4da3e8';

/* ── 畫布（工作區）＝一個白色底 Rect，匯出時以它的範圍裁切 ── */
let artboard = new fabric.Rect({
    left: 0, top: 0, width: artW, height: artH,
    fill: '#ffffff', selectable: false, evented: false,
    id: '__artboard', shadow: new fabric.Shadow({ color: 'rgba(0,0,0,.45)', blur: 18, offsetX: 0, offsetY: 4 })
});
canvas.add(artboard);

function findArtboard() {
    const o = canvas.getObjects().find(o => o.id === '__artboard');
    if (o) { artboard = o; artboard.selectable = false; artboard.evented = false; }
    return artboard;
}
function setArtboardSize(w, h, bg) {
    artW = Math.max(50, Math.round(w)); artH = Math.max(50, Math.round(h));
    artboard.set({ width: artW, height: artH, scaleX: 1, scaleY: 1 });
    if (bg) artboard.set('fill', bg);
    canvas.sendToBack(artboard);
    document.getElementById('st-canvas').textContent = artW + '×' + artH;
    canvas.requestRenderAll();
}

/* ── 圖面像素縮放：輸入目標寬/高，等比例縮放整張圖面（所有物件的位置與大小一起等比例調整）── */
function openResizeModal() {
    document.getElementById('rs-current').textContent = Math.round(artW) + '×' + Math.round(artH);
    document.getElementById('rs-w').value = Math.round(artW);
    document.getElementById('rs-h').value = Math.round(artH);
    renderResizePresets();
    showModal('resize-modal');
}
document.getElementById('rs-w').addEventListener('input', function () {
    const w = parseFloat(this.value) || 0;
    if (w > 0 && artW > 0) document.getElementById('rs-h').value = Math.round(w * (artH / artW));
});
document.getElementById('rs-h').addEventListener('input', function () {
    const h = parseFloat(this.value) || 0;
    if (h > 0 && artH > 0) document.getElementById('rs-w').value = Math.round(h * (artW / artH));
});
function applyResizeTo(newW, newH) {
    newW = Math.max(10, Math.round(newW)); newH = Math.max(10, Math.round(newH));
    const factor = newW / artW;
    canvas.discardActiveObject();
    canvas.getObjects().forEach(o => {
        if (o === artboard) return;
        o.set({
            left: (o.left || 0) * factor,
            top: (o.top || 0) * factor,
            scaleX: (o.scaleX || 1) * factor,
            scaleY: (o.scaleY || 1) * factor
        });
        o.dirty = true;
        o.setCoords();
    });
    setArtboardSize(newW, newH);
    canvas.requestRenderAll();
    zoomFit();
    pushState();
    toast('圖面已等比例縮放為 ' + newW + '×' + newH);
}
function applyResize() {
    const w = parseFloat(document.getElementById('rs-w').value) || 0;
    const h = parseFloat(document.getElementById('rs-h').value) || 0;
    if (w < 10 || h < 10) { toast('尺寸太小'); return; }
    if (!confirm('確定把整張圖面等比例縮放為 ' + Math.round(w) + '×' + Math.round(h) + '？（可 Ctrl+Z 復原）')) return;
    applyResizeTo(w, h);
    hideModal('resize-modal');
}
/* 常用尺寸：最多 3 組，可命名，其中一組可設為「快速縮放」的預設 */
let resizePresets = JSON.parse(JSON.stringify(RESIZE_PRESETS || []));
let resizeDefaultIdx = RESIZE_DEFAULT_IDX || 0;
function renderResizePresets() {
    const box = document.getElementById('rs-presets');
    if (!resizePresets.length) { box.innerHTML = '<span style="color:#8b949e;font-size:12px;">尚未設定常用尺寸</span>'; return; }
    box.innerHTML = resizePresets.map((p, i) =>
        '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:12.5px;">' +
        '<label style="display:flex;align-items:center;gap:3px;white-space:nowrap;"><input type="radio" name="rs-default" ' + (i === resizeDefaultIdx ? 'checked' : '') + ' onchange="setResizeDefault(' + i + ')"> 預設</label>' +
        '<span style="flex:1;">' + escHtml(p.name) + '（' + p.w + '×' + p.h + '）</span>' +
        '<button class="tb-btn" onclick="applyResizeTo(' + p.w + ',' + p.h + ')" title="套用這組尺寸"><i class="fa fa-check"></i></button>' +
        '<button class="tb-btn" style="color:#ff8a80;" onclick="deleteResizePreset(' + i + ')" title="刪除這組"><i class="fa fa-trash"></i></button>' +
        '</div>').join('');
}
function addResizePreset() {
    const name = document.getElementById('rs-preset-name').value.trim();
    const w = parseFloat(document.getElementById('rs-preset-w').value) || 0;
    const h = parseFloat(document.getElementById('rs-preset-h').value) || 0;
    if (!name) { toast('請輸入名稱'); return; }
    if (w < 10 || h < 10) { toast('請輸入寬高'); return; }
    if (resizePresets.length >= 3) { toast('最多只能設定 3 組常用尺寸，請先刪除一組再新增'); return; }
    resizePresets.push({ name, w: Math.round(w), h: Math.round(h) });
    document.getElementById('rs-preset-name').value = '';
    document.getElementById('rs-preset-w').value = '';
    document.getElementById('rs-preset-h').value = '';
    saveResizePresets();
    renderResizePresets();
}
function deleteResizePreset(i) {
    resizePresets.splice(i, 1);
    if (resizeDefaultIdx >= resizePresets.length) resizeDefaultIdx = 0;
    saveResizePresets();
    renderResizePresets();
}
function setResizeDefault(i) {
    resizeDefaultIdx = i;
    saveResizePresets();
}
async function saveResizePresets() {
    try {
        const fd = new FormData();
        fd.append('action', 'save_resize_presets');
        fd.append('presets', JSON.stringify(resizePresets));
        fd.append('default_index', resizeDefaultIdx);
        await fetch('image_editor.php', { method: 'POST', body: fd });
    } catch (e) {}
}
function quickResize() {
    if (!resizePresets.length) { toast('尚未設定常用尺寸，請先設定'); openResizeModal(); return; }
    const p = resizePresets[resizeDefaultIdx] || resizePresets[0];
    if (!confirm('確定套用「' + p.name + '」（' + p.w + '×' + p.h + '）等比例縮放整張圖面？')) return;
    applyResizeTo(p.w, p.h);
}

/* ── 視窗尺寸/縮放/平移 ── */
const wrap = document.getElementById('canvas-wrap');
function resizeViewport() {
    canvas.setDimensions({ width: wrap.clientWidth, height: wrap.clientHeight });
    canvas.requestRenderAll();
}
window.addEventListener('resize', resizeViewport);
/* 換螢幕(不同DPI)/喚醒/切回分頁後，canvas 偶爾會繪圖異常（殘影、只剩選取控制點）；
   回來時強制整張畫布連同每個物件的快取都重畫一次 */
function forceFullRepaint() {
    resizeViewport();
    canvas.getObjects().forEach(o => { o.dirty = true; if (o.getObjects) o.getObjects().forEach(c => { c.dirty = true; }); });
    canvas.requestRenderAll();
}
window.addEventListener('focus', forceFullRepaint);
document.addEventListener('visibilitychange', function () { if (!document.hidden) forceFullRepaint(); });

function setZoomLabel() {
    document.getElementById('zoom-label').textContent = Math.round(canvas.getZoom() * 100) + '%';
}
function zoomFit() {
    const m = 40;
    const z = Math.min((wrap.clientWidth - m) / artW, (wrap.clientHeight - m) / artH, 4);
    const zz = Math.max(0.02, z);
    canvas.setViewportTransform([zz, 0, 0, zz,
        (wrap.clientWidth - artW * zz) / 2 - artboard.left * zz,
        (wrap.clientHeight - artH * zz) / 2 - artboard.top * zz]);
    setZoomLabel(); canvas.requestRenderAll();
}
function zoomToSelection() {
    const obj = canvas.getActiveObject();
    if (!obj) { toast('請先選取物件'); return; }
    const b = obj.getBoundingRect(true, true); // absolute (scene) coords
    const m = 60;
    const z = Math.min((wrap.clientWidth - m) / b.width, (wrap.clientHeight - m) / b.height, 8);
    canvas.setViewportTransform([z, 0, 0, z,
        (wrap.clientWidth - b.width * z) / 2 - b.left * z,
        (wrap.clientHeight - b.height * z) / 2 - b.top * z]);
    setZoomLabel(); canvas.requestRenderAll();
}
canvas.on('mouse:wheel', function (opt) {
    const e = opt.e;
    let z = canvas.getZoom() * Math.pow(0.999, e.deltaY);
    z = Math.min(12, Math.max(0.02, z));
    canvas.zoomToPoint({ x: e.offsetX, y: e.offsetY }, z);
    setZoomLabel();
    e.preventDefault(); e.stopPropagation();
});

/* ── 工具切換 ── */
function setTool(t) {
    if (t !== 'connect') clearConnectDraft();   // 切走工具時清掉兩點連線的第一點/預覽
    currentTool = t;
    document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('tool-' + t);
    if (btn) btn.classList.add('active');

    canvas.isDrawingMode = (t === 'draw');
    if (t === 'draw') {
        canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
        canvas.freeDrawingBrush.color = document.getElementById('p-stroke').value;
        const bw = parseInt(document.getElementById('p-width').value, 10) || 3;
        canvas.freeDrawingBrush.width = bw;
        canvas.freeDrawingBrush.strokeDashArray = dashArrayFor(document.getElementById('p-line-style').value, bw);
    }
    const isSelect = (t === 'select');
    const isCropTool = (t === 'cropmove' || t === 'cropcopy' || t === 'cropmovelasso');
    canvas.selection = isSelect;
    canvas.skipTargetFind = !isSelect && !isCropTool;   // 框選複製/搬移也允許點到既有物件（例如剛切下那塊）直接拖曳，不必先切回選取
    canvas.defaultCursor = (t === 'pan') ? 'grab' : (isSelect ? 'default' : 'crosshair');
    if (!isSelect && !isCropTool) canvas.discardActiveObject();

    // 屬性列切換
    document.getElementById('sec-stroke').classList.toggle('show', ['draw','line','connect','rect','ellipse','select','dimdist','dimcircle','dimangle'].includes(t));
    // 「端點」只有直線／畫筆工具在畫新物件時真的有作用（矩形/橢圓/標註工具沒有端點可設，選取既有物件也不會回填生效，故只依工具顯示）
    document.getElementById('wrap-line-ends').style.display = ['line', 'draw', 'connect'].includes(t) ? '' : 'none';
    document.getElementById('sec-connect').classList.toggle('show', t === 'connect');
    document.getElementById('sec-dimstyle').classList.toggle('show', t === 'dimcircle');
    document.getElementById('sec-crop').classList.toggle('show', isCropTool);
    document.getElementById('sec-text').classList.toggle('show', ['text','label'].includes(t));
    document.getElementById('sec-mask').classList.toggle('show', ['maskrect','masklasso'].includes(t));
    document.getElementById('sec-balloon').classList.toggle('show', t === 'balloon');
    document.getElementById('sec-dc').classList.toggle('show', t === 'dc');
    document.getElementById('sec-stamp').classList.toggle('show', t === 'stamp');
    if (t === 'balloon') document.getElementById('p-balloon-next').value = nextBalloonLetter();
    if (t === 'dc') document.getElementById('p-dc-num').value = nextDcNumber();
    canvas.requestRenderAll();
}

/* ── 滑鼠操作：平移 / 形狀繪製 / 遮蓋 / 框選複製 / 文字 ── */
let isPanning = false, lastPan = null;
let drawing = null;   // 進行中的形狀 {type, obj, startX, startY, points}

function scenePoint(opt) { return canvas.getPointer(opt.e, false); } // scene coords

canvas.on('mouse:down', function (opt) {
    const e = opt.e;
    if (currentTool === 'pan' || spaceDown || e.button === 1) {
        isPanning = true; lastPan = { x: e.clientX, y: e.clientY };
        canvas.defaultCursor = 'grabbing';
        return;
    }
    if (canvas.isDrawingMode) return;
    const p = scenePoint(opt);

    if (currentTool === 'text' || currentTool === 'label') {
        addText(p.x, p.y, currentTool === 'label');
        return;
    }
    if (currentTool === 'balloon') { placeBalloon(p.x, p.y); return; }   // 工具保持啟用，連續點連續編
    if (currentTool === 'dc') { placeDcMark(p.x, p.y); return; }
    if (currentTool === 'stamp') { placeStamp(p.x, p.y); return; }
    if (currentTool === 'dimangle') { startDimAngle(p.x, p.y); return; }
    if (currentTool === 'connect') { handleConnectClick(p); return; }
    if (['rect','ellipse','line','maskrect','cropcopy','cropmove','dimdist','dimcircle'].includes(currentTool)) {
        // 框選複製/搬移：點在既有物件上（例如剛切下、還沒拖到定位的那塊）就交給 Fabric 正常拖曳，不要開新框
        if ((currentTool === 'cropcopy' || currentTool === 'cropmove') && opt.target) return;
        drawing = { type: currentTool, startX: p.x, startY: p.y, obj: null };
        return;
    }
    if (currentTool === 'masklasso') {
        drawing = { type: 'masklasso', points: [{ x: p.x, y: p.y }], obj: null };
        return;
    }
    if (currentTool === 'cropmovelasso') {
        if (opt.target) return;   // 點在既有物件上（例如剛切下那塊）交給 Fabric 正常拖曳
        drawing = { type: 'cropmovelasso', points: [{ x: p.x, y: p.y }], obj: null };
        return;
    }
});

canvas.on('mouse:move', function (opt) {
    const e = opt.e;
    if (isPanning && lastPan) {
        const vpt = canvas.viewportTransform;
        vpt[4] += e.clientX - lastPan.x;
        vpt[5] += e.clientY - lastPan.y;
        lastPan = { x: e.clientX, y: e.clientY };
        canvas.setViewportTransform(vpt);
        return;
    }
    const p = scenePoint(opt);
    document.getElementById('st-pos').textContent = Math.round(p.x) + ', ' + Math.round(p.y);
    if (currentTool === 'connect' && connectFirst) { updateConnectPreview(p); return; }
    if (!drawing) return;

    const stroke = document.getElementById('p-stroke').value;
    const sw = parseInt(document.getElementById('p-width').value, 10) || 3;
    const fillOn = document.getElementById('p-fill-on').checked;
    const fill = fillOn ? document.getElementById('p-fill').value : 'transparent';
    const maskColor = document.getElementById('p-maskcolor').value;

    const x = Math.min(drawing.startX, p.x), y = Math.min(drawing.startY, p.y);
    const w = Math.abs(p.x - drawing.startX), h = Math.abs(p.y - drawing.startY);

    if (drawing.type === 'masklasso' || drawing.type === 'cropmovelasso') {
        // 套索預覽：就地更新同一個 Polyline 的 points（每個 mousemove 都重建新物件會 O(n²)，長套索明顯掉幀）
        drawing.points.push({ x: p.x, y: p.y });
        if (!drawing.obj) {
            const style = (drawing.type === 'masklasso')
                ? { stroke: '#e53935', fill: 'rgba(229,57,53,.15)', strokeDashArray: [4, 3] }
                : { stroke: '#6fc3ff', fill: 'rgba(39,121,189,.15)', strokeDashArray: [5, 4] };
            drawing.obj = new fabric.Polyline(drawing.points, Object.assign({
                strokeWidth: 1 / canvas.getZoom(), selectable: false, evented: false, objectCaching: false
            }, style));
            canvas.add(drawing.obj);
        } else {
            drawing.obj._setPositionDimensions({});
            drawing.obj.dirty = true;
        }
        canvas.requestRenderAll();
        return;
    }

    if (drawing.obj) canvas.remove(drawing.obj);
    let o = null;
    const lineDash = dashArrayFor(document.getElementById('p-line-style').value, sw);
    if (drawing.type === 'rect') {
        o = new fabric.Rect({ left: x, top: y, width: w, height: h, stroke, strokeWidth: sw, fill, strokeUniform: true, strokeDashArray: lineDash });
    } else if (drawing.type === 'ellipse') {
        o = new fabric.Ellipse({ left: x, top: y, rx: w / 2, ry: h / 2, stroke, strokeWidth: sw, fill, strokeUniform: true, strokeDashArray: lineDash });
    } else if (drawing.type === 'line' || drawing.type === 'dimdist' || drawing.type === 'dimcircle') {
        o = new fabric.Line([drawing.startX, drawing.startY, p.x, p.y], { stroke, strokeWidth: sw, strokeUniform: true, strokeDashArray: lineDash });
    } else if (drawing.type === 'maskrect') {
        o = new fabric.Rect({ left: x, top: y, width: w, height: h, fill: maskColor, stroke: null });
    } else if (drawing.type === 'cropcopy' || drawing.type === 'cropmove') {
        o = new fabric.Rect({ left: x, top: y, width: w, height: h, fill: 'rgba(39,121,189,.15)', stroke: '#6fc3ff', strokeWidth: 1 / canvas.getZoom(), strokeDashArray: [5, 4] });
    }
    if (o) {
        o.set({ selectable: false, evented: false, objectCaching: false });
        drawing.obj = o;
        canvas.add(o); canvas.requestRenderAll();
    }
});

canvas.on('mouse:up', function (opt) {
    if (isPanning) { isPanning = false; lastPan = null; canvas.defaultCursor = (currentTool === 'pan') ? 'grab' : 'default'; return; }
    if (!drawing) return;
    const d = drawing; drawing = null;
    const p = scenePoint(opt);

    if (d.type === 'masklasso') {
        if (d.obj) canvas.remove(d.obj);
        if (d.points.length > 2) {
            const poly = new fabric.Polygon(d.points, {
                fill: document.getElementById('p-maskcolor').value, stroke: null, objectCaching: false
            });
            canvas.add(poly); finishNewObject(poly);
        }
        return;
    }
    if (d.type === 'cropmovelasso') {
        if (d.obj) canvas.remove(d.obj);
        if (d.points.length > 2) doCropMoveLasso(d.points);
        else canvas.requestRenderAll();
        return;   // 停留在此工具，可連續框選（同框選搬移）
    }
    if (!d.obj) return;
    canvas.remove(d.obj);
    const w = Math.abs(p.x - d.startX), h = Math.abs(p.y - d.startY);
    if (w < 3 && h < 3) { canvas.requestRenderAll(); return; } // 誤點不建物件

    if (d.type === 'cropcopy') { doCropCopy(Math.min(d.startX, p.x), Math.min(d.startY, p.y), w, h); return; }
    if (d.type === 'cropmove') { doCropMove(Math.min(d.startX, p.x), Math.min(d.startY, p.y), w, h); return; }

    const color = document.getElementById('p-stroke').value;
    const width = parseInt(document.getElementById('p-width').value, 10) || 3;
    const dash = dashArrayFor(document.getElementById('p-line-style').value, width);

    if (d.type === 'dimdist' || d.type === 'dimcircle') {
        const isDia = (d.type === 'dimcircle');
        const extendOut = isDia && document.getElementById('p-dim-style').value === 'extend';
        const shape = makeDimDistanceShape(d.startX, d.startY, p.x, p.y, color, width, dash, !isDia, isDia ? '⌀' : '', extendOut);
        shape.dimKind = isDia ? 'diameter' : 'distance';
        canvas.add(shape);
        finishNewObject(shape);
        const txtChild = shape.getObjects().find(o => o.type === 'i-text');
        if (txtChild) startGroupTextEdit(shape, txtChild, true);   // 畫完立刻進入輸入，游標停在最後（⌀後面）
        return;
    }

    let o = null;
    const ends = document.getElementById('p-line-ends').value;
    if (d.type === 'line' && ends !== 'none') {
        o = makeArrow(d.startX, d.startY, p.x, p.y, color, width, ends, dash);
    } else {
        o = d.obj; // 直接把預覽物件轉正式
        o.set({ objectCaching: true });
    }
    o.set({ selectable: true, evented: true });
    canvas.add(o);
    if (o === d.obj) { /* 已在畫布上，避免重複加 */ canvas.remove(o); canvas.add(o); }
    finishNewObject(o);
});

/* 連續工具：畫完不切回選取，可一直畫（同球標邏輯）；Esc 或 V 回選取工具 */
const CONTINUOUS_TOOLS = ['maskrect', 'masklasso', 'rect', 'ellipse', 'line', 'dimdist', 'dimcircle'];
function finishNewObject(o) {
    if (CONTINUOUS_TOOLS.includes(currentTool)) {
        canvas.requestRenderAll();
        pushState();
        return;
    }
    canvas.setActiveObject(o);
    setTool('select');
    canvas.requestRenderAll();
    pushState();
}

/* ── 兩點連線：點第一點→點第二點，自動用直線或曲線相連 ──
   直線＝跟直線工具同款（含端點箭頭選項），之後雙擊可編輯端點；
   曲線＝三節點圓滑折線（curved=Catmull-Rom），先天帶一點弧度並自動進入
   「編輯端點」模式，拖中間圓點即調曲度（頭尾圓點＝改連接位置）。 */
let connectFirst = null, connectMarker = null, connectPreview = null;
function clearConnectDraft() {
    if (connectMarker) { canvas.remove(connectMarker); connectMarker = null; }
    if (connectPreview) { canvas.remove(connectPreview); connectPreview = null; }
    connectFirst = null;
}
function updateConnectPreview(p) {
    if (connectPreview) canvas.remove(connectPreview);
    connectPreview = new fabric.Line([connectFirst.x, connectFirst.y, p.x, p.y], {
        stroke: document.getElementById('p-stroke').value,
        strokeWidth: Math.max(1, 1 / canvas.getZoom()), strokeDashArray: [5, 4],
        selectable: false, evented: false, objectCaching: false
    });
    canvas.add(connectPreview);
    canvas.requestRenderAll();
}
/* 兩點間圓弧取樣：bulge＝弧高/弦長，n 段（回傳 n+1 個落在同一圓上的點） */
function connectArcPoints(a, b, bulge, n) {
    const dx = b.x - a.x, dy = b.y - a.y;
    const d = Math.hypot(dx, dy);
    const h = d * bulge;
    const ux = -dy / d, uy = dx / d;                       // 垂直單位向量（凸向側）
    const R = h / 2 + d * d / (8 * h);
    const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
    const cx = mx + ux * (h - R), cy = my + uy * (h - R);  // 圓心
    const sx = mx + ux * h, sy = my + uy * h;              // 弧頂
    const a0 = Math.atan2(a.y - cy, a.x - cx);
    let sweep = Math.atan2(b.y - cy, b.x - cx) - a0;
    sweep = ((sweep % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI);
    // 兩個掃掠方向，取「會經過弧頂」的那一個
    const pm = { x: cx + R * Math.cos(a0 + sweep / 2), y: cy + R * Math.sin(a0 + sweep / 2) };
    if (Math.hypot(pm.x - sx, pm.y - sy) > R * 0.2) sweep -= 2 * Math.PI;
    const pts = [];
    for (let i = 0; i <= n; i++) {
        const t = a0 + sweep * i / n;
        pts.push({ x: cx + R * Math.cos(t), y: cy + R * Math.sin(t) });
    }
    return pts;
}
function handleConnectClick(p) {
    if (!connectFirst) {   // 第一點：放個小紅點提示
        connectFirst = { x: p.x, y: p.y };
        connectMarker = new fabric.Circle({
            left: p.x, top: p.y, radius: 4 / canvas.getZoom(), fill: '#e53935',
            originX: 'center', originY: 'center', selectable: false, evented: false, objectCaching: false
        });
        canvas.add(connectMarker);
        canvas.requestRenderAll();
        return;
    }
    const a = connectFirst, b = { x: p.x, y: p.y };
    if (Math.hypot(b.x - a.x, b.y - a.y) < 3) { toast('兩點太近，請點遠一點的位置'); return; }
    clearConnectDraft();
    const stroke = document.getElementById('p-stroke').value;
    const sw = parseInt(document.getElementById('p-width').value, 10) || 3;
    const dash = dashArrayFor(document.getElementById('p-line-style').value, sw);
    const kind = document.getElementById('p-connect-kind').value;
    if (kind === 'curve') {
        // 沿真正的圓弧取 5 個節點（弧高＝弦長 50%≈半圓），圓滑曲線通過圓上的點＝視覺圓潤；
        // 弧凸向取決於兩點點擊順序；工具保持啟用可連續連線，之後雙擊曲線＝編輯端點拖節點調曲度
        const poly = new fabric.Polyline(connectArcPoints(a, b, 0.5, 4), {
            stroke, strokeWidth: sw, fill: 'transparent', strokeUniform: true,
            strokeDashArray: dash, strokeLineCap: 'round', strokeLineJoin: 'round', objectCaching: false
        });
        poly.curved = true;
        canvas.add(poly);
        canvas.requestRenderAll();
        pushState();
        return;
    }
    const ends = document.getElementById('p-line-ends').value;
    const o = (ends !== 'none')
        ? makeArrow(a.x, a.y, b.x, b.y, stroke, sw, ends, dash)
        : new fabric.Line([a.x, a.y, b.x, b.y], { stroke, strokeWidth: sw, strokeUniform: true, strokeDashArray: dash });
    canvas.add(o);
    canvas.requestRenderAll();
    pushState();   // 直線：工具保持啟用，可連續點下一組兩點（Esc 或 V 回選取）
}

/* 線型（實線/虛線/中心線）：dashArrayFor 依粗細等比縮放，styleFromDashArray 是反查（供屬性列同步顯示） */
function dashArrayFor(style, sw) {
    if (style === 'dashed') return [Math.max(6, sw * 3), Math.max(4, sw * 2)];
    if (style === 'dashdot') return [Math.max(8, sw * 4), Math.max(3, sw * 1.5), Math.max(2, sw * 0.8), Math.max(3, sw * 1.5)];
    return null;
}
function styleFromDashArray(arr) {
    if (!arr || !arr.length) return 'solid';
    return arr.length >= 4 ? 'dashdot' : 'dashed';
}

/* 直線/箭頭的「角度」以水平線為 0 度基準（畫出來當下的斜線本身 x1..y2 方向 + 目前旋轉量），而非物件自身方向 */
function normDeg(a) { return ((a + 180) % 360 + 360) % 360 - 180; }
function isLineLike(o) { return !!o && (o.type === 'line' || (o.type === 'group' && o.isArrowGroup)); }
function trueLineAngle(o) {
    const line = (o.type === 'line') ? o : o.getObjects().find(c => c.type === 'line');
    if (!line) return o.angle || 0;
    const dx = line.x2 - line.x1, dy = line.y2 - line.y1;
    const base = Math.atan2(dy, dx) * 180 / Math.PI;
    return normDeg(base + (o.angle || 0));
}

/* 箭頭 = 線 + 三角形頭端組成群組；ends: none / end(單) / both(雙) */
function arrowHeadLen(width) { return Math.max(18, width * 5); }   // 箭頭大小公式；粗細調整時要用同一套（見 p-width 監聽）
/* 箭頭三角形要「尖端剛好在線段端點」：Triangle 以中心定位，所以中心要沿線方向往內縮 headLen/2 */
function arrowHeadTri(px, py, dirDeg, headLen, color) {
    const rad = dirDeg * Math.PI / 180;
    return new fabric.Triangle({
        left: px - Math.cos(rad) * headLen / 2, top: py - Math.sin(rad) * headLen / 2,
        originX: 'center', originY: 'center',
        width: headLen, height: headLen, angle: dirDeg + 90, fill: color
    });
}
function makeArrow(x1, y1, x2, y2, color, width, ends, dash) {
    const angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    const headLen = arrowHeadLen(width);
    const rad = angle * Math.PI / 180, ux = Math.cos(rad), uy = Math.sin(rad);
    const hasEnd = (ends === 'end' || ends === 'both');
    // 線只畫到「箭頭底部」不畫到尖端：粗線畫到尖端會從細細的箭頭尖旁邊露出來
    const len = Math.hypot(x2 - x1, y2 - y1);
    const s = (len > headLen * (ends === 'both' ? 2 : 1) + 4) ? headLen : 0;
    const lx1 = (ends === 'both') ? x1 + ux * s : x1, ly1 = (ends === 'both') ? y1 + uy * s : y1;
    const lx2 = hasEnd ? x2 - ux * s : x2, ly2 = hasEnd ? y2 - uy * s : y2;
    const items = [new fabric.Line([lx1, ly1, lx2, ly2], { stroke: color, strokeWidth: width, strokeUniform: true, strokeDashArray: dash || null })];
    if (hasEnd) items.push(arrowHeadTri(x2, y2, angle, headLen, color));
    if (ends === 'both') items.push(arrowHeadTri(x1, y1, angle + 180, headLen, color));
    const g = new fabric.Group(items, {});
    g.isArrowGroup = true;
    g.merged = true;   // 箭頭視為單一物件，雙擊不拆
    return g;
}
/* 直線端點的絕對座標（含物件自身/所屬群組的位移旋轉縮放） */
function lineAbsEndpoints(line) {
    const m = line.calcTransformMatrix();
    const lp = line.calcLinePoints();   // 相對線中心的區域座標
    return [fabric.util.transformPoint({ x: lp.x1, y: lp.y1 }, m),
            fabric.util.transformPoint({ x: lp.x2, y: lp.y2 }, m)];
}
/* 箭頭群組的「真實」頭尾＝箭頭尖端（群組裡的線段有被縮短，不能拿線段端點當頭尾）。
   三角形尖端＝其區域座標 (0, -高/2) 經自身+群組矩陣轉換。makeArrow 的順序固定：先 end(x2) 再 start(x1)。 */
function trueArrowEndpoints(obj) {
    if (obj.type === 'line') return lineAbsEndpoints(obj);
    const line = obj.getObjects().find(c => c.type === 'line');
    const tris = obj.getObjects().filter(c => c.type === 'triangle');
    const pts = lineAbsEndpoints(line);
    const apex = t => fabric.util.transformPoint({ x: 0, y: -t.height / 2 }, t.calcTransformMatrix());
    if (tris.length >= 2) return [apex(tris[1]), apex(tris[0])];
    if (tris.length === 1) return [pts[0], apex(tris[0])];   // 單箭頭：箭頭固定在第二端
    return pts;
}
/* 就地重建箭頭群組（改粗細/端點模式時用整支重畫，避免只放大三角形造成尖端跑位、跟線沒對齊） */
function rebuildArrowGroup(g, opts) {
    opts = opts || {};
    const line = g.getObjects().find(c => c.type === 'line');
    const tris = g.getObjects().filter(c => c.type === 'triangle');
    if (!line) return g;
    const pts = trueArrowEndpoints(g);
    const no = makeArrow(pts[0].x, pts[0].y, pts[1].x, pts[1].y,
        opts.color != null ? opts.color : line.stroke,
        opts.width != null ? opts.width : (line.strokeWidth || 3),
        opts.ends != null ? opts.ends : (tris.length >= 2 ? 'both' : 'end'),
        opts.dash !== undefined ? opts.dash : (line.strokeDashArray || null));
    const idx = canvas.getObjects().indexOf(g);
    canvas.remove(g);
    canvas.add(no);
    if (idx >= 0) no.moveTo(idx);
    return no;
}
/* Excel 式端點拖曳：直線只顯示頭尾兩個圓形控制點，拖曳＝直接改該端點座標（改方向/長度）。
   矩形/橢圓維持原生四角控制點拖曳調整大小。actionHandler 把絕對座標寫回 x1..y2 並歸零
   angle/scale（fabric.Line 設定座標時會自動重算 left/top/width/height）。 */
(function installLineEndpointControls() {
    function mkControl(idx) {
        return new fabric.Control({
            positionHandler: function (dim, finalMatrix, obj) {
                const lp = obj.calcLinePoints();
                const pt = (idx === 1) ? { x: lp.x1, y: lp.y1 } : { x: lp.x2, y: lp.y2 };
                return fabric.util.transformPoint(pt,
                    fabric.util.multiplyTransformMatrices(obj.canvas.viewportTransform, obj.calcTransformMatrix()));
            },
            actionHandler: function (eventData, transform, x, y) {
                const line = transform.target;
                const pts = lineAbsEndpoints(line);
                const p1 = (idx === 1) ? { x, y } : pts[0];
                const p2 = (idx === 2) ? { x, y } : pts[1];
                line.set({ angle: 0, scaleX: 1, scaleY: 1, flipX: false, flipY: false });
                line.set({ x1: p1.x, y1: p1.y, x2: p2.x, y2: p2.y });
                line.setCoords();
                return true;
            },
            cursorStyle: 'crosshair',
            actionName: 'modifyLineEnd',
            render: function (ctx, left, top) {
                ctx.save();
                ctx.fillStyle = '#ffffff'; ctx.strokeStyle = '#2779bd'; ctx.lineWidth = 1.5;
                ctx.beginPath(); ctx.arc(left, top, 6, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
                ctx.restore();
            }
        });
    }
    fabric.Line.prototype.controls = { p1: mkControl(1), p2: mkControl(2) };
})();
/* 圓滑曲線渲染：curved=true 的折線/多邊形，用 Catmull-Rom 曲線通過所有節點取代直線段。
   覆寫 Polyline.commonRender（Polygon 也共用），節點資料不變，編輯端點照常可用。 */
(function installCurvedPolyRender() {
    const orig = fabric.Polyline.prototype.commonRender;
    fabric.Polyline.prototype.commonRender = function (ctx) {
        if (!this.curved || !this.points || this.points.length < 3) return orig.call(this, ctx);
        const pts = this.points, n = pts.length, ox = this.pathOffset.x, oy = this.pathOffset.y;
        if (!n || isNaN(pts[n - 1].y)) return false;
        const closed = (this.type === 'polygon');
        const P = i => closed ? pts[((i % n) + n) % n] : pts[Math.max(0, Math.min(n - 1, i))];
        ctx.beginPath();
        ctx.moveTo(P(0).x - ox, P(0).y - oy);
        const segs = closed ? n : n - 1;
        for (let i = 0; i < segs; i++) {
            const p0 = P(i - 1), p1 = P(i), p2 = P(i + 1), p3 = P(i + 2);
            ctx.bezierCurveTo(
                p1.x + (p2.x - p0.x) / 6 - ox, p1.y + (p2.y - p0.y) / 6 - oy,
                p2.x - (p3.x - p1.x) / 6 - ox, p2.y - (p3.y - p1.y) / 6 - oy,
                p2.x - ox, p2.y - oy);
        }
        return true;
    };
})();

/* ── Excel「編輯端點」：直線/折線/矩形/不規則遮蓋 進入節點編輯模式 ──────────
   拖曳實心圓點＝移動該節點；點各線段中間的「＋」＝在該處插入新節點（直線第一次編輯會先轉成折線）。
   節點座標數學沿用 fabric 官方 custom-controls-polygon 範例（pathOffset / _setPositionDimensions / 錨定點）。 */
function polyEditSizeWithStroke(o) {
    const sx = o.strokeUniform ? 1 / o.scaleX : 1, sy = o.strokeUniform ? 1 / o.scaleY : 1;
    return { x: o.width + sx * (o.strokeWidth || 0), y: o.height + sy * (o.strokeWidth || 0) };
}
function polyPointPositionHandler(i) {
    return function (dim, finalMatrix, poly) {
        const p = poly.points[i];
        // 節點數變動後的殘留控制點、或物件已被移出畫布（undo 重建後的殘留選取）：移到畫面外，不讓它把渲染搞掛
        if (!p || !poly.canvas) return new fabric.Point(-99999, -99999);
        const pt = { x: p.x - poly.pathOffset.x, y: p.y - poly.pathOffset.y };
        return fabric.util.transformPoint(pt,
            fabric.util.multiplyTransformMatrices(poly.canvas.viewportTransform, poly.calcTransformMatrix()));
    };
}
function polyMidPositionHandler(i) {
    return function (dim, finalMatrix, poly) {
        const a = poly.points[i], b = poly.points[(i + 1) % poly.points.length];
        if (!a || !b || !poly.canvas) return new fabric.Point(-99999, -99999);
        const pt = { x: (a.x + b.x) / 2 - poly.pathOffset.x, y: (a.y + b.y) / 2 - poly.pathOffset.y };
        return fabric.util.transformPoint(pt,
            fabric.util.multiplyTransformMatrices(poly.canvas.viewportTransform, poly.calcTransformMatrix()));
    };
}
function polyPointActionHandler(i) {
    return function (eventData, transform, x, y) {
        const poly = transform.target;
        const local = poly.toLocalPoint(new fabric.Point(x, y), 'center', 'center');
        const base = polyEditSizeWithStroke(poly);
        const size = poly._getTransformedDimensions(0, 0);
        // 節點全部垂直/水平共線時寬或高=0，除以0會把座標變成Infinity「毒化」整個物件
        // （看不見、選得到、每幀渲染出錯畫面不清除→拖曳殘影），一律防呆
        const sx = size.x || 1, sy = size.y || 1;
        const nx = local.x * base.x / sx + poly.pathOffset.x;
        const ny = local.y * base.y / sy + poly.pathOffset.y;
        if (!isFinite(nx) || !isFinite(ny)) return false;
        poly.points[i] = { x: nx, y: ny };
        return true;
    };
}
/* 改動節點後物件的寬高/中心會變，用「另一個節點」當錨點把物件釘在原地（官方範例作法） */
function polyAnchorWrapper(anchorIndex, fn) {
    return function (eventData, transform, x, y) {
        const poly = transform.target;
        const anchor = poly.points[anchorIndex];
        if (!anchor) return false;
        const anchorAbs = fabric.util.transformPoint(
            { x: anchor.x - poly.pathOffset.x, y: anchor.y - poly.pathOffset.y },
            poly.calcTransformMatrix());
        const done = fn(eventData, transform, x, y);
        poly._setPositionDimensions({});
        const base = polyEditSizeWithStroke(poly);
        const nx = (poly.points[anchorIndex].x - poly.pathOffset.x) / (base.x || 1);
        const ny = (poly.points[anchorIndex].y - poly.pathOffset.y) / (base.y || 1);
        if (isFinite(nx) && isFinite(ny) && isFinite(anchorAbs.x) && isFinite(anchorAbs.y)) {
            poly.setPositionByOrigin(anchorAbs, nx + 0.5, ny + 0.5);
        }
        return done;
    };
}
function polyInsertPointHandler(i) {
    return function (eventData, transform, x, y) {
        const poly = transform.target;
        // 防連點：畫面卡頓時排隊的連續 click 會在同一個「＋」上狂插一堆節點
        const now = Date.now();
        if (poly.__lastNodeInsert && now - poly.__lastNodeInsert < 350) return;
        poly.__lastNodeInsert = now;
        const a = poly.points[i], b = poly.points[(i + 1) % poly.points.length];
        poly.points.splice(i + 1, 0, { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
        poly.controls = buildPolyEditControls(poly);
        poly.dirty = true;
        canvas.requestRenderAll();
        pushState();
    };
}
function buildPolyEditControls(poly) {
    const controls = {};
    poly.points.forEach((pt, i) => {
        controls['p' + i] = new fabric.Control({
            positionHandler: polyPointPositionHandler(i),
            actionHandler: polyAnchorWrapper(i > 0 ? i - 1 : poly.points.length - 1, polyPointActionHandler(i)),
            actionName: 'modifyPoly',
            cursorStyle: 'crosshair',
            render: function (ctx, left, top) {
                ctx.save();
                ctx.fillStyle = '#2779bd'; ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 1.5;
                ctx.beginPath(); ctx.arc(left, top, 6, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
                ctx.restore();
            }
        });
    });
    // 節點多時不放「＋」控制點（控制點數量加倍會拖慢渲染；要加節點的情境都是少節點的折線）
    const segCount = (poly.points.length > 40) ? 0 : ((poly.type === 'polygon') ? poly.points.length : poly.points.length - 1);
    for (let i = 0; i < segCount; i++) {
        controls['m' + i] = new fabric.Control({
            positionHandler: polyMidPositionHandler(i),
            mouseDownHandler: polyInsertPointHandler(i),
            actionHandler: function () { return false; },   // ＋只負責插入節點，插完由使用者拖新出現的實心點
            cursorStyle: 'copy',
            render: function (ctx, left, top) {
                ctx.save();
                ctx.fillStyle = '#ffffff'; ctx.strokeStyle = '#2779bd'; ctx.lineWidth = 1.2;
                ctx.beginPath(); ctx.arc(left, top, 5, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
                ctx.strokeStyle = '#2779bd'; ctx.lineWidth = 1.4;
                ctx.beginPath();
                ctx.moveTo(left - 3, top); ctx.lineTo(left + 3, top);
                ctx.moveTo(left, top - 3); ctx.lineTo(left, top + 3);
                ctx.stroke();
                ctx.restore();
            }
        });
    }
    return controls;
}
/* ── 直線選取＝直接出現頭尾兩個端點圓點，拖曳即改線段長短/方向（不再是一般物件的整體縮放）──
   拖端點時把線攤平回 angle=0/scale=1 再用絕對座標重設 x1..y2（fabric.Line 的 _set 會自動重算外框），
   不改物件型別，端點箭頭下拉/角度欄等 isLineLike 相關功能全部不受影響。 */
function lineEndPositionHandler(which) {
    return function (dim, finalMatrix, o) {
        if (!o.canvas) return new fabric.Point(-99999, -99999);   // 已被移出畫布的殘留選取
        const p = lineAbsEndpoints(o)[which];
        return fabric.util.transformPoint(new fabric.Point(p.x, p.y), o.canvas.viewportTransform);
    };
}
function lineEndActionHandler(which) {
    return function (eventData, transform, x, y) {
        const o = transform.target;
        const other = lineAbsEndpoints(o)[which === 0 ? 1 : 0];
        const a = (which === 0) ? { x, y } : other;
        const b = (which === 0) ? other : { x, y };
        if (!isFinite(a.x) || !isFinite(a.y) || !isFinite(b.x) || !isFinite(b.y)) return false;   // 防 NaN 毒化
        o.set({ angle: 0, scaleX: 1, scaleY: 1, flipX: false, flipY: false });
        o.set({ x1: a.x, y1: a.y, x2: b.x, y2: b.y });
        o.setCoords();
        return true;
    };
}
function buildLineEndControls() {
    const controls = {};
    [0, 1].forEach(i => {
        controls['e' + i] = new fabric.Control({
            positionHandler: lineEndPositionHandler(i),
            actionHandler: lineEndActionHandler(i),
            actionName: 'modifyLine',
            cursorStyle: 'crosshair',
            render: function (ctx, left, top) {
                ctx.save();
                ctx.fillStyle = '#2779bd'; ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 1.5;
                ctx.beginPath(); ctx.arc(left, top, 6, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
                ctx.restore();
            }
        });
    });
    return controls;
}
function refreshLineEndControls(e) {
    ((e && e.deselected) || []).forEach(o => { if (o.__lineEndCtrls) { delete o.controls; delete o.__lineEndCtrls; } });
    const obj = canvas.getActiveObject();
    if (obj && obj.type === 'line' && !obj.isDimGuide && !obj.__pointEditing && !obj.locked && !obj.__lineEndCtrls) {
        obj.controls = buildLineEndControls();
        obj.__lineEndCtrls = true;
    }
}
canvas.on('selection:created', refreshLineEndControls);
canvas.on('selection:updated', refreshLineEndControls);
canvas.on('selection:cleared', function (e) {
    ((e && e.deselected) || []).forEach(o => { if (o.__lineEndCtrls) { delete o.controls; delete o.__lineEndCtrls; } });
});
function toEditablePolyline(obj) {
    if (obj.type === 'polyline' || obj.type === 'polygon') return obj;
    if (obj.type === 'rect') {
        // 矩形 → 四角多邊形（封閉），之後就能拖角、插入節點拉成任意形狀
        obj.setCoords();
        const c = obj.aCoords;
        const poly = new fabric.Polygon([
            { x: c.tl.x, y: c.tl.y }, { x: c.tr.x, y: c.tr.y }, { x: c.br.x, y: c.br.y }, { x: c.bl.x, y: c.bl.y }
        ], {
            stroke: obj.stroke, strokeWidth: obj.strokeWidth, strokeDashArray: obj.strokeDashArray || null,
            fill: obj.fill || 'transparent', strokeUniform: true, strokeLineJoin: 'miter'
        });
        canvas.remove(obj);
        canvas.add(poly);
        return poly;
    }
    if (obj.type !== 'line') return null;
    const pts = lineAbsEndpoints(obj);
    const poly = new fabric.Polyline([{ x: pts[0].x, y: pts[0].y }, { x: pts[1].x, y: pts[1].y }], {
        stroke: obj.stroke, strokeWidth: obj.strokeWidth, strokeDashArray: obj.strokeDashArray || null,
        fill: 'transparent', strokeUniform: true, strokeLineCap: 'round', strokeLineJoin: 'round'
    });
    canvas.remove(obj);
    canvas.add(poly);
    return poly;
}
/* 離開編輯端點：還原預設控制點（供切換鈕、Esc、點空白/點其他物件共用） */
function exitPointEdit(obj) {
    if (!obj || !obj.__pointEditing) return;
    delete obj.__pointEditing;
    delete obj.controls;          // 還原成 prototype 預設控制點
    obj.hasBorders = true;
    obj.objectCaching = !obj.curved;   // 圓滑曲線可能超出節點外框，關快取避免被裁掉
    obj.setCoords();
}
/* 點空白處或點選其他物件＝自動離開編輯端點（否則物件卡在節點模式，回頭點選還是節點控制點） */
canvas.on('selection:cleared', function (e) {
    (e && e.deselected || []).forEach(o => { if (o.__pointEditing) { exitPointEdit(o); canvas.requestRenderAll(); } });
});
canvas.on('selection:updated', function (e) {
    (e && e.deselected || []).forEach(o => { if (o.__pointEditing) { exitPointEdit(o); canvas.requestRenderAll(); } });
});
function togglePointEdit() {
    const obj = canvas.getActiveObject();
    if (!obj) return;
    if (obj.__pointEditing) {
        exitPointEdit(obj);
        canvas.requestRenderAll();
        refreshPropbar();
        pushState();
        return;
    }
    const poly = toEditablePolyline(obj);
    if (!poly) { toast('只有直線、折線、矩形、不規則遮蓋可以編輯端點'); return; }
    poly.__pointEditing = true;
    poly.objectCaching = false;       // 編輯中即時重繪，節點拖曳才不會殘影
    poly.hasBorders = false;
    poly.controls = buildPolyEditControls(poly);
    canvas.setActiveObject(poly);
    canvas.requestRenderAll();
    refreshPropbar();
    pushState();
    toast('拖曳藍色圓點調整形狀；點線段中間的「＋」新增節點；屬性列可「封閉/打開」頭尾、「圓滑/取直」曲線；再按一次「編輯端點」完成');
}
/* 編輯端點模式的兩個附加功能：封閉/打開（折線↔多邊形）、圓滑/取直（直線段↔通過節點的曲線） */
function togglePolyClosed() {
    const obj = canvas.getActiveObject();
    if (!obj || !obj.__pointEditing) return;
    const Cls = (obj.type === 'polygon') ? fabric.Polyline : fabric.Polygon;
    const np = new Cls(obj.points.map(p => ({ x: p.x, y: p.y })), {
        left: obj.left, top: obj.top, angle: obj.angle, scaleX: obj.scaleX, scaleY: obj.scaleY,
        flipX: obj.flipX, flipY: obj.flipY,
        stroke: obj.stroke, strokeWidth: obj.strokeWidth, strokeDashArray: obj.strokeDashArray || null,
        fill: obj.fill, strokeUniform: obj.strokeUniform,
        strokeLineCap: obj.strokeLineCap, strokeLineJoin: obj.strokeLineJoin
    });
    np.curved = obj.curved;
    const idx = canvas.getObjects().indexOf(obj);
    canvas.remove(obj);
    canvas.add(np);
    if (idx >= 0) np.moveTo(idx);
    np.__pointEditing = true;
    np.objectCaching = false;
    np.hasBorders = false;
    np.controls = buildPolyEditControls(np);
    canvas.setActiveObject(np);
    canvas.requestRenderAll();
    refreshPropbar();
    pushState();
}
function togglePolySmooth() {
    const obj = canvas.getActiveObject();
    if (!obj || !obj.__pointEditing) return;
    if (!obj.curved && obj.points.length < 3) { toast('至少要 3 個節點才能圓滑（先用「＋」加節點）'); return; }
    obj.curved = !obj.curved;
    obj.dirty = true;
    canvas.requestRenderAll();
    refreshPropbar();
    pushState();
}

/* 雙底線：fabric 原生只有單底線(underline)。doubleUnderline=true 時沿用 fabric 自己的底線繪製流程，
   把 offset 往下移一段再畫第二條（同粗細同顏色），跟原生底線完全同款式。 */
(function installDoubleUnderline() {
    const origRender = fabric.Text.prototype._render;
    fabric.Text.prototype._render = function (ctx) {
        origRender.call(this, ctx);
        if (this.doubleUnderline && this.underline) {
            this.offsets = { underline: 0.24, linethrough: this.offsets.linethrough, overline: this.offsets.overline };
            this._renderTextDecoration(ctx, 'underline');
            delete this.offsets;   // 還原回 prototype 上的預設值（0.10）
        }
    };
})();

/* ── 文字 / 標籤（Figma 式：隨時可移動、雙擊編輯、拉角縮放） ──
   文字＝單一 IText，底色可選；標籤＝固定有邊框的文字框（像標籤機印出來的標籤），是「邊框 Rect + IText」
   的小群組，雙擊比照標籤庫的群組內文字編輯機制（startGroupTextEdit／finishGroupTextEdit），改完字邊框
   自動貼合新字長（finishGroupTextEdit 內 isQuickLabel 分支處理，不影響標籤庫既有的規格標籤重建邏輯）。 */
function addText(x, y, isLabel) {
    if (isLabel) { addLabelBox(x, y); return; }
    const size = parseInt(document.getElementById('p-fontsize').value, 10) || 28;
    const color = document.getElementById('p-textcolor').value;
    const bold = document.getElementById('p-bold').checked;
    const bgOn = document.getElementById('p-textbg-on').checked;
    const bg = document.getElementById('p-textbg').value;
    const ul = document.getElementById('p-underline').value;
    const t = new fabric.IText('輸入文字', {
        left: x, top: y, fontSize: size, fill: color,
        fontFamily: '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        fontWeight: bold ? 'bold' : 'normal',
        backgroundColor: bgOn ? bg : '',
        underline: ul !== 'none'
    });
    t.doubleUnderline = (ul === 'double');
    canvas.add(t);
    canvas.setActiveObject(t);
    setTool('select');
    t.enterEditing(); t.selectAll();
    canvas.requestRenderAll();
    pushState();
}
function labelBoxPadding(fontSize) { return Math.max(6, fontSize * 0.28); }
function addLabelBox(x, y) {
    const size = parseInt(document.getElementById('p-fontsize').value, 10) || 28;
    const color = document.getElementById('p-textcolor').value;
    const bg = document.getElementById('p-textbg-on').checked ? document.getElementById('p-textbg').value : '#fff59d';
    const text = new fabric.IText('標籤文字', {
        fontSize: size, fill: color, fontWeight: 'bold',
        fontFamily: '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        originX: 'center', originY: 'center', left: 0, top: 0
    });
    const pad = labelBoxPadding(size);
    const box = new fabric.Rect({
        width: text.width + pad * 2, height: text.height + pad * 2, left: 0, top: 0,
        originX: 'center', originY: 'center', fill: bg, stroke: color, strokeWidth: 1.5, rx: 4, ry: 4
    });
    const g = new fabric.Group([box, text], { left: x, top: y });
    g.labelSpec = { kind: 'fabric' };
    g.labelKind = 'fabric';
    g.isQuickLabel = true;
    canvas.add(g);
    canvas.setActiveObject(g);
    setTool('select');
    canvas.requestRenderAll();
    pushState();
    startGroupTextEdit(g, text);   // 放上後直接進入編輯，跟文字工具手感一致
}

/* ── 工程符號快速插入 ＋ ^ 上下公差堆疊文字（Inventor 式） ─────────────
   符號：屬性列「文字」區與「建立文字標籤」跳窗各有一條符號鈕，點一下插到游標處。
   公差：一般文字輸入 A^B（如 25 -0^-0.18），結束編輯自動變成「-0 疊在 -0.18 上」
   的小字（0.55 倍），雙擊可還原成含 ^ 的原始字串整串重編。 */
const EG_SYMBOLS = [
    ['Ø', '直徑'], ['°', '度'], ['±', '正負公差'], ['▽', '加工符號（研磨＝連按多個）'],
    ['↧', '深度'], ['⌴', '沉頭孔／柱坑'], ['⌵', '錐坑'], ['□', '正方形'],
    ['⌒', '圓弧'], ['Ra', '表面粗糙度'], ['×', '乘號']
];
(function initSymStrips() {
    const mk = fn => EG_SYMBOLS.map(s =>
        '<button class="pb-btn" style="min-width:30px;padding:4px 6px;font-size:13px;" onclick="' + fn + '(\'' + s[0] + '\')" title="' + s[1] + '">' + s[0] + '</button>').join('');
    document.getElementById('sym-pad').innerHTML = mk('insertSym');
    document.getElementById('nl-sym-strip').innerHTML = mk('nlInsertSym');
    // 浮動快捷列：符號（編輯文字時浮在輸入框上方）＋ 旋轉角度（選取物件時浮在物件上方）
    document.getElementById('float-syms').innerHTML = EG_SYMBOLS.map(s =>
        '<button onclick="insertSym(\'' + s[0] + '\')" title="' + s[1] + '">' + s[0] + '</button>').join('');
    document.getElementById('float-rot').innerHTML = [45, -90, 90, 180].map(a =>
        '<button onclick="rotateQuickBy(' + a + ')" title="以目前角度為基準，再旋轉 ' + a + ' 度（物件中心）">' + (a > 0 ? '+' : '') + a + '°</button>').join('')
        + '<button onclick="flipSelected(\'h\')" title="水平翻轉（左右鏡射）">⇋</button>'
        + '<button onclick="flipSelected(\'v\')" title="垂直翻轉（上下鏡射）">⇵</button>'
        + '<input type="number" id="float-rot-v" min="-360" max="360" step="1" placeholder="±°"'
        + ' title="手動輸入相對角度（-360～360，以目前角度為 0 點），Enter 或離開欄位即套用"'
        + ' style="width:56px;background:rgba(255,255,255,.75);border:1px solid #cbb377;border-radius:4px;color:#5a4a20;font-size:12px;padding:3px 4px;"'
        + ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();applyFloatRotInput();}" onchange="applyFloatRotInput()">';
})();
/* 旋轉快捷鍵：以「目前角度」為 0 點的相對旋轉（圖塊/圖片看不出原始 0 度方向，相對轉比較直覺；
   屬性列「角度」欄位維持絕對角度，會同步更新） */
function rotateQuickBy(delta) {
    const obj = canvas.getActiveObject(); if (!obj) return;
    obj.rotate(normDeg((obj.angle || 0) + delta));   // normDeg 正規化到 ±180，避免角度無限累積
    obj.setCoords();
    if (obj.isDimGuide && obj.dimAngleId) rebuildDimAngleArc(obj.dimAngleId);
    refreshPropbar();
    canvas.requestRenderAll();
    pushState();
}
/* 水平/垂直翻轉（小畫家式鏡射）：群組/多選整體翻 */
function flipSelected(axis) {
    const obj = canvas.getActiveObject(); if (!obj) return;
    const k = (axis === 'h') ? 'flipX' : 'flipY';
    obj.set(k, !obj[k]);
    obj.setCoords();
    canvas.requestRenderAll();
    pushState();
}
function applyFloatRotInput() {
    const inp = document.getElementById('float-rot-v');
    let v = parseFloat(inp.value);
    if (isNaN(v) || v === 0) { inp.value = ''; return; }
    v = Math.max(-360, Math.min(360, v));   // 限制 ±360 度，避免計算錯誤
    inp.value = '';
    rotateQuickBy(v);
}
/* 浮動快捷列定位：跟著選取物/編輯框走（每次畫布重繪時更新，移動/縮放/捲動都會跟上） */
function positionFloatBars() {
    const symEl = document.getElementById('float-syms');
    const rotEl = document.getElementById('float-rot');
    const obj = canvas.getActiveObject();
    const editing = !!(obj && obj.isEditing);
    // 框選搬移/複製切下的圖塊也要能就地旋轉，所以這幾個工具選取中也顯示旋轉快捷列
    const showRot = !!(obj && !editing && !obj.__pointEditing
        && ['select', 'cropmove', 'cropcopy', 'cropmovelasso'].includes(currentTool));
    symEl.style.display = editing ? 'flex' : 'none';
    rotEl.style.display = showRot ? 'flex' : 'none';
    const el = editing ? symEl : (showRot ? rotEl : null);
    if (!el || !obj) return;
    const br = obj.getBoundingRect();   // 含視圖縮放/平移＝畫布像素座標
    const cr = canvas.upperCanvasEl.getBoundingClientRect();
    const w = el.offsetWidth, h = el.offsetHeight;
    let left = cr.left + br.left + br.width / 2 - w / 2;
    let top = cr.top + br.top - h - 10;
    left = Math.max(4, Math.min(left, window.innerWidth - w - 4));
    if (top < cr.top + 4) top = cr.top + br.top + br.height + 10;   // 上方放不下改物件下方
    el.style.left = left + 'px';
    el.style.top = top + 'px';
}
canvas.on('after:render', positionFloatBars);
/* 符號浮動面板：開在按鈕正下方；點面板/按鈕以外的地方自動收起 */
function toggleSymPad() {
    const pad = document.getElementById('sym-pad');
    if (pad.style.display === 'flex') { pad.style.display = 'none'; return; }
    const r = document.getElementById('sym-btn').getBoundingClientRect();
    pad.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 220)) + 'px';
    pad.style.top = (r.bottom + 4) + 'px';
    pad.style.display = 'flex';
}
document.addEventListener('mousedown', function (e) {
    const pad = document.getElementById('sym-pad');
    if (pad.style.display === 'flex' && !e.target.closest('#sym-pad, #sym-btn')) pad.style.display = 'none';
});
function insertSym(s) {
    const obj = canvas.getActiveObject();
    if (obj && obj.isEditing) {   // IText 編輯中（含標籤/群組文字的暫時編輯框）：插入游標處
        const st = obj.selectionStart || 0, en = obj.selectionEnd || 0;
        obj.insertChars(s, null, st, en);
        obj.selectionStart = obj.selectionEnd = st + s.length;
        if (obj.hiddenTextarea) {   // 同步隱藏 textarea，接著打字才不會吃掉剛插入的符號
            obj.hiddenTextarea.value = obj.text;
            obj.hiddenTextarea.selectionStart = obj.hiddenTextarea.selectionEnd = obj.selectionStart;
        }
        obj.dirty = true;
        canvas.requestRenderAll();
        return;
    }
    if (obj && (obj.type === 'i-text' || obj.type === 'text') && !obj.merged) {   // 只選取未進編輯：附加到最後
        obj.set('text', obj.text + s);
        obj.dirty = true; obj.setCoords();
        canvas.requestRenderAll(); pushState();
        return;
    }
    toast('請先雙擊要編輯的文字（或用文字工具點畫布）再按符號');
}
function nlInsertSym(s) {
    const ta = document.getElementById('nl-text');
    const st = ta.selectionStart || 0, en = ta.selectionEnd || 0;
    ta.value = ta.value.slice(0, st) + s + ta.value.slice(en);
    ta.selectionStart = ta.selectionEnd = st + s.length;
    ta.focus();
}
/* ^ 公差：兩側限「數字/字母/±.,°」的短字串，避免把 25-0^-0.18 的基準值 25 一起吃進堆疊 */
const TOL_INPUT_RE = /([+\-±]?[\w.,°]+)\^([+\-±]?[\w.,°]+)/;
function makeTolGroup(raw, style) {
    const fs = style.fontSize || 28;
    const small = Math.max(8, Math.round(fs * 0.55));
    const base = {
        fontFamily: style.fontFamily || '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        fontWeight: style.fontWeight || 'normal', fill: style.fill || '#d32f2f',
        backgroundColor: style.backgroundColor || ''
    };
    const items = [];
    const lineH = fs * 1.16;
    String(raw).split('\n').forEach((line, li) => {
        const cy = li * lineH;   // 該行的垂直中線
        let x = 0, last = 0, m;
        const re = new RegExp(TOL_INPUT_RE.source, 'g');
        const put = str => {   // 一般（大字）片段
            if (!str) return;
            const t = new fabric.Text(str, Object.assign({}, base, { fontSize: fs, left: x, top: cy, originY: 'center' }));
            items.push(t); x += t.width;
        };
        while ((m = re.exec(line))) {
            put(line.slice(last, m.index));
            // 上排底貼中線、下排頂貼中線＝整疊高度約等於基準字高
            const up = new fabric.Text(m[1], Object.assign({}, base, { fontSize: small, left: x, top: cy - fs * 0.02, originY: 'bottom' }));
            const dn = new fabric.Text(m[2], Object.assign({}, base, { fontSize: small, left: x, top: cy + fs * 0.02, originY: 'top' }));
            items.push(up, dn);
            x += Math.max(up.width, dn.width);
            last = m.index + m[0].length;
        }
        put(line.slice(last));
    });
    if (!items.length) return null;
    const g = new fabric.Group(items, {});
    g.labelSpec = { kind: 'tol', text: String(raw), fontSize: fs, fill: base.fill,
                    fontWeight: base.fontWeight, fontFamily: base.fontFamily, backgroundColor: base.backgroundColor };
    g.labelKind = 'tol';
    return g;
}
function convertToTolGroup(t) {
    let g = null;
    try {
        g = makeTolGroup(t.text, { fontSize: t.fontSize, fill: t.fill, fontWeight: t.fontWeight,
                                   fontFamily: t.fontFamily, backgroundColor: t.backgroundColor });
    } catch (e) { console.warn('[EGdraw] 公差文字建立例外：', e); }
    if (!g || !isFinite(g.width) || !isFinite(g.height)) return;   // 建失敗就保留原文字，不毒化畫布
    const c = t.getCenterPoint();
    g.set({ angle: t.angle, scaleX: t.scaleX, scaleY: t.scaleY, originX: 'center', originY: 'center' });
    g.setPositionByOrigin(c, 'center', 'center');
    g.setCoords();
    canvas.remove(t);
    canvas.add(g);
    canvas.setActiveObject(g);
    canvas.requestRenderAll();
    pushState();
}
/* 雙擊 ^ 公差群組：還原成含 ^ 的原始字串整串重編（比照 startGroupTextEdit 的暫時編輯框機制） */
function startTolEdit(group) {
    const spec = group.labelSpec;
    const c = group.getCenterPoint();
    const tmp = new fabric.IText(spec.text, {
        left: c.x, top: c.y, originX: 'center', originY: 'center',
        angle: group.angle, scaleX: group.scaleX, scaleY: group.scaleY,
        fontSize: spec.fontSize || 28,
        fontFamily: spec.fontFamily || '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        fontWeight: spec.fontWeight || 'normal', fill: spec.fill || '#d32f2f',
        backgroundColor: '#fff8d6'
    });
    tmp.__groupEditFor = group;   // 讓「刪除」知道使用者要刪的是整組
    group.visible = false;
    canvas.add(tmp);
    canvas.setActiveObject(tmp);
    tmp.enterEditing();
    tmp.selectAll();
    tmp.on('editing:exited', function () {
        const val = tmp.text;
        try { tmp.abortCursorAnimation(); } catch (e) { /* 游標動畫沒在跑就算了 */ }
        canvas.remove(tmp);
        if (tmp.__deleteGroup) {
            canvas.remove(group);
            canvas.discardActiveObject();
            canvas.requestRenderAll();
            pushState();
            return;
        }
        if (restoring || canvas.getObjects().indexOf(group) === -1) { canvas.requestRenderAll(); return; }
        group.visible = true;
        const style = { fontSize: tmp.fontSize, fill: tmp.fill, fontWeight: tmp.fontWeight, fontFamily: tmp.fontFamily,
                        backgroundColor: (tmp.backgroundColor !== '#fff8d6') ? tmp.backgroundColor : (spec.backgroundColor || '') };
        const center = group.getCenterPoint();
        const { scaleX, scaleY, angle } = group;
        let ng = null;
        if (TOL_INPUT_RE.test(val)) {
            try { ng = makeTolGroup(val, style); } catch (e) { console.warn('[EGdraw] 公差文字重建例外：', e); }
        } else {   // 改到沒有 ^ 了：變回一般文字
            ng = new fabric.IText(val, { fontSize: style.fontSize, fill: style.fill, fontWeight: style.fontWeight,
                                         fontFamily: style.fontFamily, backgroundColor: style.backgroundColor });
        }
        if (!ng || !isFinite(ng.width) || !isFinite(ng.height)) {
            toast('公差文字重建失敗，已保留原內容（此次修改未套用）');
            canvas.requestRenderAll();
            return;
        }
        canvas.remove(group);
        ng.set({ scaleX, scaleY, angle, originX: 'center', originY: 'center' });
        ng.setPositionByOrigin(center, 'center', 'center');
        ng.setCoords();
        canvas.add(ng);
        canvas.setActiveObject(ng);
        canvas.requestRenderAll();
        pushState();
    });
}

/* ── 快速標註（CAD 風格）：距離 / 直徑 / 角度 ──────────────────────────
   標註線＋文字數值包成同一個群組（labelSpec kind='fabric'），移動時一起動；雙擊沿用標籤庫既有的
   「群組內文字編輯」機制（startGroupTextEdit）。距離/直徑無法從像素推算真實尺寸，文字預設留空
   （直徑帶 ⌀ 前綴）由使用者自行輸入實測值；角度是純幾何夾角、不受比例尺影響，自動帶入算好的度數。 */
/* 標註文字的角度與擺放位置：沿線段方向、放在線的上側；角度正規化到 ±90 內，字不會上下顛倒 */
function dimTextPose(x1, y1, x2, y2, extraGap) {
    let a = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    if (a > 90) a -= 180;
    if (a < -90) a += 180;
    const fs = parseInt(document.getElementById('p-fontsize').value, 10) || 28;
    const k = fs * 0.75 + (extraGap || 0);
    const rad = a * Math.PI / 180;
    return { x: (x1 + x2) / 2 + Math.sin(rad) * k, y: (y1 + y2) / 2 - Math.cos(rad) * k, angle: a };
}
function makeDimText(x, y, str, angleDeg) {
    const t = new fabric.IText(str || '', {
        left: x, top: y, originX: 'center', originY: 'center',
        fontSize: parseInt(document.getElementById('p-fontsize').value, 10) || 28,
        fill: document.getElementById('p-textcolor').value, fontWeight: 'bold',
        fontFamily: '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        backgroundColor: '#ffffff', angle: angleDeg || 0
    });
    t.dimKind = 'label';
    return t;
}
/* withTicks：距離標註兩端有垂直小刻度線（CAD 尺寸界線收尾），直徑標註不要（會看起來像多出兩條線） */
function makeDimDistanceShape(x1, y1, x2, y2, color, width, dash, withTicks, textPrefix, extendOut) {
    const angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    const headLen = arrowHeadLen(width);
    const rad0 = angle * Math.PI / 180, ux = Math.cos(rad0), uy = Math.sin(rad0);
    // 線只畫到「箭頭底部」不畫到尖端，粗線才不會從箭頭尖旁邊露出來
    const len = Math.hypot(x2 - x1, y2 - y1);
    const s = (len > headLen * 2 + 4) ? headLen : 0;
    const items = [
        new fabric.Line([x1 + ux * s, y1 + uy * s, x2 - ux * s, y2 - uy * s], { stroke: color, strokeWidth: width, strokeUniform: true, strokeDashArray: dash || null }),
        arrowHeadTri(x2, y2, angle, headLen, color),
        arrowHeadTri(x1, y1, angle + 180, headLen, color)
    ];
    if (withTicks) {
        const nx = -Math.sin(angle * Math.PI / 180), ny = Math.cos(angle * Math.PI / 180);
        const tick = 8 + width;
        items.push(new fabric.Line([x1 - nx * tick, y1 - ny * tick, x1 + nx * tick, y1 + ny * tick], { stroke: color, strokeWidth: Math.max(1, width * 0.6) }));
        items.push(new fabric.Line([x2 - nx * tick, y2 - ny * tick, x2 + nx * tick, y2 + ny * tick], { stroke: color, strokeWidth: Math.max(1, width * 0.6) }));
    }
    if (extendOut) {
        // 延伸式（直徑標註第二種樣式）：線越過第二點往外延伸，文字沿斜線放在延伸段上方（拖曳結束端＝文字端）
        const fs = parseInt(document.getElementById('p-fontsize').value, 10) || 28;
        const extLen = fs * 2.2 + headLen;
        items.push(new fabric.Line([x2, y2, x2 + ux * extLen, y2 + uy * extLen],
            { stroke: color, strokeWidth: width, strokeUniform: true, strokeDashArray: dash || null }));
        let a2 = angle;                            // 角度正規化到 ±90，字不會上下顛倒
        if (a2 > 90) a2 -= 180;
        if (a2 < -90) a2 += 180;
        const mx = x2 + ux * extLen * 0.55, my = y2 + uy * extLen * 0.55;
        const k = fs * 0.75 + width;
        const rad2 = a2 * Math.PI / 180;
        items.push(makeDimText(mx + Math.sin(rad2) * k, my - Math.cos(rad2) * k, textPrefix || '', a2));
    } else {
        const pose = dimTextPose(x1, y1, x2, y2, width);
        items.push(makeDimText(pose.x, pose.y, textPrefix || '', pose.angle));
    }
    const g = new fabric.Group(items, {});
    g.labelSpec = { kind: 'fabric' };   // 讓雙擊走「群組內文字編輯」而不是拆群組
    g.dimKind = 'distance';
    return g;
}
/* 角度標註：點一下產生「角度標示」（雙箭頭弧線＋度數）＋兩條各自獨立的虛擬輔助線。
   - 兩條輔助線可以分開放：拖曳頭尾圓形控制點各自對齊圖面上要量的兩條邊（不用相交在同一點）
   - 角度＝兩線方向的夾角；弧線畫在兩線延伸的交點上（平行時畫在兩線中間）
   - 輔助線平常自動隱藏，點選角度標示（或輔助線本身）才顯示（見 updateDimGuideVisibility）
   - 標示與輔助線用 dimAngleId 綁定：一起存檔還原、刪掉任一個時整組一併刪除（見 deleteSelection） */
function guidesOfAngle(id) { return canvas.getObjects().filter(o => o.isDimGuide && o.dimAngleId === id); }
function arcOfAngle(id) { return canvas.getObjects().find(o => o.dimKind === 'angle' && o.dimAngleId === id); }
function lineLineIntersection(a, b) {
    const [p1, p2] = a, [p3, p4] = b;
    const d = (p2.x - p1.x) * (p4.y - p3.y) - (p2.y - p1.y) * (p4.x - p3.x);
    if (Math.abs(d) < 1e-6) return null;   // 平行
    const t = ((p3.x - p1.x) * (p4.y - p3.y) - (p3.y - p1.y) * (p4.x - p3.x)) / d;
    return { x: p1.x + t * (p2.x - p1.x), y: p1.y + t * (p2.y - p1.y) };
}
function startDimAngle(vx, vy) {
    const id = 'da' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const R = 90;
    const mkGuide = angDeg => {
        const rad = angDeg * Math.PI / 180;
        const dx = Math.cos(rad) * R, dy = Math.sin(rad) * R;
        const line = new fabric.Line([vx - dx, vy - dy, vx + dx, vy + dy], {
            stroke: '#2b8fd6', strokeWidth: 3, strokeDashArray: [7, 5], opacity: 0.9,
            hasBorders: false, padding: 8
        });
        line.isDimGuide = true;      // 頭尾圓形控制點沿用直線的全域端點控制，拖端點即改方向/位置
        line.dimAngleId = id;
        return line;
    };
    canvas.add(mkGuide(0)); canvas.add(mkGuide(-60));
    rebuildDimAngleArc(id);
    const arc = arcOfAngle(id);
    if (arc) canvas.setActiveObject(arc);
    setTool('select');
    updateDimGuideVisibility();
    canvas.requestRenderAll();
    pushState();
    toast('拖曳藍色虛線頭尾的圓點，把兩條線各自對齊要量的邊（可分開放）；點空白處虛線自動隱藏，點角度標示可再叫出來調整');
}
function rebuildDimAngleArc(id) {
    const gs = guidesOfAngle(id);
    if (gs.length < 2) return;
    const old = arcOfAngle(id);
    // 使用者改過度數文字的樣式（底色/顏色/字級…）時，重建後要沿用，不能每次調整輔助線就重設
    const oldText = (old && old.getObjects) ? old.getObjects().find(o => o.type === 'i-text') : null;
    if (old) canvas.remove(old);
    const [g1, g2] = gs;
    const e1 = lineAbsEndpoints(g1), e2 = lineAbsEndpoints(g2);
    let c = lineLineIntersection(e1, e2);
    if (!c) c = { x: (e1[0].x + e1[1].x + e2[0].x + e2[1].x) / 4, y: (e1[0].y + e1[1].y + e2[0].y + e2[1].y) / 4 };
    const a1 = trueLineAngle(g1), a2 = trueLineAngle(g2);
    let diff = ((a2 - a1) % 360 + 360) % 360;
    let signedHalf = diff > 180 ? (diff - 360) / 2 : diff / 2;
    if (diff > 180) diff = 360 - diff;
    const R = 40;
    const sweep = (((a2 - a1) % 360 + 360) % 360) <= 180 ? 1 : 0;
    const rad = deg => deg * Math.PI / 180;
    const sx = c.x + R * Math.cos(rad(a1)), sy = c.y + R * Math.sin(rad(a1));
    const ex = c.x + R * Math.cos(rad(a2)), ey = c.y + R * Math.sin(rad(a2));
    const color = document.getElementById('p-stroke').value;
    const width = parseInt(document.getElementById('p-width').value, 10) || 3;
    const arcW = Math.max(2, width * 0.7);
    const arc = new fabric.Path('M ' + sx + ' ' + sy + ' A ' + R + ' ' + R + ' 0 0 ' + sweep + ' ' + ex + ' ' + ey,
        { stroke: color, strokeWidth: arcW, fill: 'transparent' });
    // 弧線兩端加箭頭（沿弧線切線方向、尖端貼齊弧線端點），像真正的 CAD 角度標註
    const headLen = Math.max(14, Math.min(22, arcW * 6));
    const dirEnd = a2 + (sweep ? 90 : -90);
    const dirStart = a1 + (sweep ? -90 : 90);
    const midAngle = a1 + signedHalf;
    const tx = c.x + (R + 26) * Math.cos(rad(midAngle)), ty = c.y + (R + 26) * Math.sin(rad(midAngle));
    const text = makeDimText(tx, ty, diff.toFixed(1) + '°', 0);
    if (oldText) {
        text.set({ fill: oldText.fill, fontSize: oldText.fontSize, fontWeight: oldText.fontWeight, backgroundColor: oldText.backgroundColor, underline: !!oldText.underline });
        text.doubleUnderline = !!oldText.doubleUnderline;
    }
    const arcGroup = new fabric.Group([
        arc,
        arrowHeadTri(sx, sy, dirStart, headLen, color),
        arrowHeadTri(ex, ey, dirEnd, headLen, color),
        text
    ], {});
    arcGroup.labelSpec = { kind: 'fabric' };   // 雙擊可改度數文字（群組內文字編輯）
    arcGroup.dimKind = 'angle';
    arcGroup.dimAngleId = id;
    canvas.add(arcGroup);
    canvas.requestRenderAll();
}
/* 輔助線顯示規則：只有選到同一組的角度標示（或輔助線本身）才顯示，平常自動隱藏。
   隱藏時必須同時設為「不可選取」——不然 Ctrl+A/框選會把看不見的線掃進選取，
   變成一個拖得動、裡面卻空無一物的藍色選取框（幽靈選取）。 */
function updateDimGuideVisibility() {
    const ao = canvas.getActiveObject();
    const ids = new Set();
    if (ao) {
        if (ao.dimAngleId) ids.add(ao.dimAngleId);
        if (ao.type === 'activeSelection' && ao.getObjects) ao.getObjects().forEach(o => { if (o.dimAngleId) ids.add(o.dimAngleId); });
    }
    let changed = false;
    canvas.getObjects().forEach(o => {
        if (!o.isDimGuide) return;
        const want = ids.has(o.dimAngleId);
        if (o.visible !== want || o.selectable !== want) {
            o.visible = want; o.selectable = want; o.evented = want;
            o.dirty = true; changed = true;
        }
    });
    if (changed) canvas.requestRenderAll();
}
canvas.on('selection:created', updateDimGuideVisibility);
canvas.on('selection:updated', updateDimGuideVisibility);
canvas.on('selection:cleared', updateDimGuideVisibility);

/* ── 蓋章：回墨印（版式沿用 CAR 簽章：公司名兩列/日期/下段文字） ────────
   fabric 原生物件繪製 → 天生透明背景，蓋在圖上自動去背。 */
const STAMP_KAI = "DFKai-SB,BiauKai,KaiTi,'標楷體',serif";
function makeStamp(bottomText, color, size, dateStr) {
    const items = [];
    items.push(new fabric.Circle({ left: 50, top: 50, radius: 47, fill: 'transparent', stroke: color, strokeWidth: 2.6, originX: 'center', originY: 'center' }));
    const chord = y => { const r = 45.7, dy = y - 50, dx = Math.sqrt(Math.max(0, r * r - dy * dy)); return [50 - dx, 50 + dx]; };
    const c1 = chord(27.5), c2 = chord(68.5);
    items.push(new fabric.Line([c1[0], 27.5, c1[1], 27.5], { stroke: color, strokeWidth: 1.4 }));
    items.push(new fabric.Line([c2[0], 68.5, c2[1], 68.5], { stroke: color, strokeWidth: 1.4 }));
    const company = OWN_COMPANY || '';
    const l1 = company.substring(0, 4), l2 = company.substring(4);
    // 對齊 CAR 印章的 SVG baseline：fabric 用中心定位，中心 y ≈ baseline − 字級×0.35
    const fit = (txt, baseY, fs, maxW, font) => {
        if (!txt) return null;
        const t = new fabric.Text(txt, {
            left: 50, top: baseY - fs * 0.35, originX: 'center', originY: 'center',
            fontSize: fs, fill: color, fontWeight: 'bold', fontFamily: font || STAMP_KAI
        });
        if (t.width > maxW) t.set('scaleX', maxW / t.width);   // 字多自動壓縮（同 textLength）
        return t;
    };
    const bfs = bottomText.length > 3 ? 15 : 19;
    [fit(l1, 15, 11, 58), fit(l2, 26, 11.5, 76),
     fit(dateStr, 54.5, 14.5, 72, "'Times New Roman','Courier New',serif"),
     fit(bottomText, 84.5, bfs, 56)].forEach(t => { if (t) items.push(t); });
    const g = new fabric.Group(items, { originX: 'center', originY: 'center', opacity: 0.92 });
    const sc = size / Math.max(g.width, g.height);
    g.set({ scaleX: sc, scaleY: sc });
    return g;
}
function placeStamp(x, y) {
    const type = document.getElementById('p-stamp-type').value;
    const size = Math.max(40, parseInt(document.getElementById('p-stamp-size').value, 10) || 110);
    const conf = {
        self:  { text: USER_CNAME || '簽章', color: '#cf3a2b' },        // 本人＝紅（同 CAR，固定）
        tech:  { text: '技術課',             color: deptStampColorHex }, // 管理者可在「用章人員」設定藍/紅
        issue: { text: '發行章',             color: deptStampColorHex }
    }[type] || { text: USER_CNAME, color: '#cf3a2b' };
    const g = makeStamp(conf.text, conf.color, size, todayStr());
    g.set({ left: x, top: y });
    g.setCoords();
    canvas.add(g);
    canvas.requestRenderAll();
    pushState();
}

/* 部門印章權限初始化：無權者隱藏技術課章/發行章；管理者顯示設定按鈕 */
(function initStampPerm() {
    if (!CAN_DEPT_STAMP) {
        const sel = document.getElementById('p-stamp-type');
        Array.from(sel.options).slice().forEach(o => { if (o.value !== 'self') sel.removeChild(o); });
    }
    if (IS_MGR) document.getElementById('btn-stamp-perm').style.display = '';
})();

/* 用章人員設定（管理者限定） */
let spData = null;
async function openStampPermModal() {
    showModal('stampperm-modal');
    document.getElementById('sp-users').innerHTML = '載入中…';
    try {
        const fd = new FormData(); fd.append('action', 'get_stamp_users');
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        spData = { depts: res.depts, users: res.users, selected: new Set((res.selected || []).map(Number)) };
        document.getElementById('sp-dept').innerHTML = res.depts.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
        document.getElementById('sp-color').value = (res.color === 'red') ? 'red' : 'blue';
        renderStampUsers();
    } catch (e) {
        document.getElementById('sp-users').innerHTML = '<span style="color:#ff8a80;">載入失敗：' + escHtml(e.message || '') + '</span>';
    }
}
function renderStampUsers() {
    if (!spData) return;
    const dept = parseInt(document.getElementById('sp-dept').value, 10);
    const list = spData.users.filter(u => u.department_id == dept);
    const box = document.getElementById('sp-users');
    if (!list.length) { box.innerHTML = '<span style="color:#8b949e;font-size:12px;">此部門沒有人員</span>'; return; }
    box.innerHTML = list.map(u =>
        '<label style="display:inline-flex;align-items:center;gap:5px;width:48%;margin:3px 0;font-size:12.5px;cursor:pointer;">' +
        '<input type="checkbox" data-uid="' + u.id + '"' + (spData.selected.has(Number(u.id)) ? ' checked' : '') +
        ' onchange="this.checked ? spData.selected.add(' + u.id + ') : spData.selected.delete(' + u.id + ')">' +
        escHtml(u.user_cname || ('#' + u.id)) + '</label>').join('');
}
async function saveStampUsers() {
    if (!spData) return;
    try {
        const fd = new FormData();
        fd.append('action', 'save_stamp_users');
        fd.append('user_ids', JSON.stringify(Array.from(spData.selected)));
        fd.append('color', document.getElementById('sp-color').value);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        deptStampColorHex = (res.color === 'red') ? '#cf3a2b' : '#2b4a9b';
        const colorWord = (res.color === 'red') ? '紅' : '藍';
        document.getElementById('opt-stamp-tech').textContent = '技術課章（' + colorWord + '）';
        document.getElementById('opt-stamp-issue').textContent = '發行章（' + colorWord + '）';
        hideModal('stampperm-modal');
        toast('已儲存用章人員名單（共 ' + res.count + ' 人）與印章顏色；被移除者重新開啟編輯器後生效');
    } catch (e) { toast('儲存失敗：' + (e.message || '')); }
}

/* ── 快速標籤①：球標（圓圈＋英文字母，自動接續編號） ─────────────────── */
function lettersToNum(s) {                       // A=1, B=2 ... Z=26, AA=27
    let n = 0;
    for (const ch of String(s).toUpperCase()) {
        const c = ch.charCodeAt(0);
        if (c < 65 || c > 90) return 0;
        n = n * 26 + (c - 64);
    }
    return n;
}
function numToLetters(n) {
    let s = '';
    while (n > 0) { n--; s = String.fromCharCode(65 + (n % 26)) + s; n = Math.floor(n / 26); }
    return s || 'A';
}
function balloonObjects() {
    return canvas.getObjects().filter(o => o.balloonLetter);
}
function nextBalloonLetter() {
    const used = balloonObjects().map(o => lettersToNum(o.balloonLetter)).filter(n => n > 0);
    if (used.length) return numToLetters(Math.max(...used) + 1);   // 接續畫布上既有球標
    const m = lettersToNum((document.getElementById('p-balloon-next') || {}).value);
    return m ? numToLetters(m) : 'A';                              // 尊重使用者手動起始字母
}
function makeBalloonGlyphItems(letter, size, cx, cy) {
    return [
        new fabric.Circle({ left: cx, top: cy, radius: size / 2, fill: '#ffffff', stroke: '#000000', strokeWidth: Math.max(1.5, size / 20), originX: 'center', originY: 'center' }),
        new fabric.Text(letter, { left: cx, top: cy, fontSize: size * 0.62, fontFamily: 'Arial', fontWeight: 'bold', fill: '#000000', originX: 'center', originY: 'center' })
    ];
}
function placeBalloon(x, y) {
    const letter = (document.getElementById('p-balloon-next').value || 'A').toUpperCase().replace(/[^A-Z]/g, '') || nextBalloonLetter();
    const size = Math.max(12, parseInt(document.getElementById('p-balloon-size').value, 10) || 40);
    const g = new fabric.Group(makeBalloonGlyphItems(letter, size, 0, 0), { left: x, top: y, originX: 'center', originY: 'center' });
    g.balloonLetter = letter;
    canvas.add(g);
    canvas.requestRenderAll();
    // 下一顆自動遞增（維持球標工具，連續點連續編）
    document.getElementById('p-balloon-next').value = numToLetters(lettersToNum(letter) + 1);
    updateBalloonSummary();
    pushState();
}
/* 右下角球標範圍：「Ⓐ～Ⓕ」——字母用真實圓圈球標樣式呈現（不帶「球標」字樣）。
   每次變動自動刪舊建新；使用者移動過的位置會沿用。 */
function updateBalloonSummary() {
    const old = canvas.getObjects().find(o => o.id === '__balloonSummary');
    const nums = balloonObjects().map(o => lettersToNum(o.balloonLetter)).filter(n => n > 0).sort((a, b) => a - b);
    if (!nums.length) { if (old) canvas.remove(old); canvas.requestRenderAll(); return; }
    const lo = numToLetters(nums[0]), hi = numToLetters(nums[nums.length - 1]);
    const s = 34, fs = 26;
    const items = []; let x = 0;
    const addTxt = str => {
        const t = new fabric.Text(str, { left: x, top: 0, originY: 'center', fontSize: fs, fontFamily: LABEL_FONT, fontWeight: 'bold', fill: '#000000', backgroundColor: '#ffffff' });
        items.push(t); x += t.width + 5;
    };
    const addGlyph = L => { items.push(...makeBalloonGlyphItems(L, s, x + s / 2, 0)); x += s + 5; };
    addGlyph(lo);
    if (hi !== lo) { addTxt('～'); addGlyph(hi); }
    let left, top;
    if (old) { left = old.left; top = old.top; canvas.remove(old); } // 已有 → 自動刪除重建，位置沿用
    else {
        left = artboard.left + artW - 14;   // 預設貼齊圖面右下角，可再拖移
        top = artboard.top + artH - 14;
    }
    const g = new fabric.Group(items, { left, top, originX: 'right', originY: 'bottom' });
    g.id = '__balloonSummary';
    canvas.add(g);
    canvas.requestRenderAll();
}

/* ── 快速標籤②：設變標示（菱形/三角形＋號碼；左上角自動設變列表） ────── */
function dcMarkObjects() {
    return canvas.getObjects().filter(o => o.dcNumber && o.dcRole === 'mark');
}
function makeDcSymbol(num, shape, size) {
    let s;
    if (shape === 'triangle') {
        s = new fabric.Triangle({ width: size * 1.12, height: size, fill: '#ffffff', stroke: '#000000', strokeWidth: Math.max(1.5, size / 20), originX: 'center', originY: 'center' });
    } else {
        s = new fabric.Rect({ width: size * 0.74, height: size * 0.74, angle: 45, fill: '#ffffff', stroke: '#000000', strokeWidth: Math.max(1.5, size / 20), originX: 'center', originY: 'center' });
    }
    const txt = new fabric.Text(String(num), {
        fontSize: size * 0.46, fontFamily: 'Arial', fontWeight: 'bold', fill: '#000000',
        originX: 'center', originY: 'center', top: shape === 'triangle' ? size * 0.14 : 0
    });
    return new fabric.Group([s, txt], { originX: 'center', originY: 'center' });
}
function todayStr() {
    const d = new Date(), p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '.' + p(d.getMonth() + 1) + '.' + p(d.getDate());
}
function nextDcNumber() {
    const used = canvas.getObjects().filter(o => o.dcNumber).map(o => o.dcNumber);
    return used.length ? Math.max(...used) + 1 : 1;
}
function placeDcMark(x, y) {
    const shape = document.getElementById('p-dc-shape').value;
    const size = Math.max(12, parseInt(document.getElementById('p-dc-size').value, 10) || 40);
    // 同一次設變多處 → 每次點擊都用「號碼」欄的同一個數字；使用者可自行改起始號
    let num = parseInt(document.getElementById('p-dc-num').value, 10);
    if (!num || num < 1) { num = nextDcNumber(); document.getElementById('p-dc-num').value = num; }

    // 圖面上的標示（工具保持啟用，同號可連續點多處）
    const mark = makeDcSymbol(num, shape, size);
    mark.set({ left: x, top: y });
    mark.dcNumber = num; mark.dcShape = shape; mark.dcRole = 'mark';
    canvas.add(mark);

    // 左上角設變列表：該號碼第一次放置時才建立（標示＋今日日期＋雙擊可輸入文字）
    const hasLegend = canvas.getObjects().some(o => o.dcRole === 'legend' && o.dcNumber === num);
    if (!hasLegend) {
        const legendMark = makeDcSymbol(num, shape, size * 0.9);
        legendMark.dcNumber = num; legendMark.dcShape = shape; legendMark.dcRole = 'legend';
        const legendText = new fabric.IText(todayStr() + '  ', {
            fontSize: size * 0.5, fontFamily: '"Microsoft JhengHei", Arial, sans-serif',
            fill: '#000000', backgroundColor: '#ffffff', originY: 'center'
        });
        legendText.dcNumber = num; legendText.dcRole = 'legendText';
        canvas.add(legendMark); canvas.add(legendText);
        reflowDcLegend();
        toast('設變 ' + num + ' 已加入左上角列表（雙擊該列文字輸入說明）；同號可繼續點圖面加標示，下一次設變請改「號碼」欄');
    }
    canvas.requestRenderAll();
    pushState();
}
/* 設變列表排版：錨定圖面左上角，號碼越大（越新）越上面；新增時自動重新判定位置 */
function reflowDcLegend() {
    const marks = canvas.getObjects().filter(o => o.dcRole === 'legend').sort((a, b) => b.dcNumber - a.dcNumber);
    const texts = {};
    canvas.getObjects().filter(o => o.dcRole === 'legendText').forEach(o => texts[o.dcNumber] = o);
    const x0 = artboard.left + 16, y0 = artboard.top + 16;
    let y = y0;
    marks.forEach(m => {
        const h = m.getBoundingRect(true, true).height;
        m.set({ left: x0 + m.getBoundingRect(true, true).width / 2, top: y + h / 2, originX: 'center', originY: 'center' });
        m.setCoords();
        const t = texts[m.dcNumber];
        if (t) {
            t.set({ left: x0 + m.getBoundingRect(true, true).width + 10, top: y + h / 2, originY: 'center' });
            t.setCoords();
        }
        y += h + 8;
    });
}

/* ── 標籤庫：規格化標籤（雙擊改字、外框自動貼合、可存庫共用） ─────────── */
const LABEL_FONT = '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif';
/* 內建標籤（依你提供的樣式重建成可編輯向量標籤；分類為暫定，可再調整） */
const PRESET_LABELS = [
    { name: '齒研附P40報告',   cat: '齒研',     spec: { kind: 'box',   text: '齒研附 P40 報告' } },
    { name: '齒底徑說明',       cat: '齒研',     spec: { kind: 'box',   text: '齒底徑 Ø\n(齒底確定有磨到此深度即可)', align: 'left' } },
    { name: '注意隆齒設定',     cat: '注意事項', spec: { kind: 'box',   text: '注意隆齒設定' } },
    { name: '注意結合要壓到底', cat: '注意事項', spec: { kind: 'box',   text: '注意結合要壓到底' } },
    { name: '粗滾圖面',         cat: '滾齒',     spec: { kind: 'box',   text: '粗滾圖面' } },
    { name: '鎖螺絲',           cat: '組裝',     spec: { kind: 'box',   text: '鎖螺絲' } },
    { name: '攻牙用一般絲攻',   cat: '攻牙',     spec: { kind: 'inline', segs: [{ t: '攻牙用', box: false }, { t: '一般', box: true }, { t: '絲攻', box: false }] } },
    { name: '研磨記號 G＋▽▽▽', cat: '加工符號', spec: { kind: 'grind3', text: 'G', bg: 'transparent' } },
    { name: '粗糙度記號 0.8＋G', cat: '加工符號', spec: { kind: 'rough', text: 'G', val: '0.8', bg: 'transparent' } },
    { name: '±0.02',            cat: '公差',     spec: { kind: 'plain', text: '±0.02' } },
    { name: 'JIS 2',            cat: '公差',     spec: { kind: 'plain', text: 'JIS 2' } },
    { name: '(  )齒研 滾/磨',   cat: '製程表格', spec: { kind: 'table', title: '(  )齒研', rows: ['滾', '磨'] } },
    { name: '(  )滾齒 滾',      cat: '製程表格', spec: { kind: 'table', title: '(  )滾齒', rows: ['滾'] } }
];

let __labelInk = '#000000';   // makeLabelFromSpec 執行期間的文字色（spec.color）
function mkLabelText(str, fs, extra) {
    return new fabric.Text(str, Object.assign({
        fontSize: fs, fontFamily: LABEL_FONT, fontWeight: 'bold', fill: __labelInk
    }, extra || {}));
}
/* 數值格文字：多行時第 2 行起自動縮小（公差慣例：基準值大字、上下公差小字）。
   用 fabric 的 per-char styles 實作，仍是同一個文字物件，雙擊編輯/重建流程不受影響 */
function mkValText(str, fs, extra) {
    const t = mkLabelText(String(str), fs, extra);
    const lines = String(str).split('\n');
    if (lines.length > 1) {
        const small = Math.max(10, Math.round(fs * 0.75));
        const styles = {};
        for (let li = 1; li < lines.length; li++) {
            styles[li] = {};
            for (let ci = 0; ci < lines[li].length; ci++) styles[li][ci] = { fontSize: small };
        }
        t.styles = styles;
        t.initDimensions();
    }
    return t;
}
function makeLabelFromSpec(spec) {
    spec = JSON.parse(JSON.stringify(spec || {}));   // 表格空白格會就地補 vals/cellVals/body 預設值，先複製一份避免動到標籤庫共用的 spec
    __labelInk = spec.color || '#000000';
    const fs = spec.fontSize || 44;
    const bw = spec.strokeW || 4;
    const bgFill = (spec.bg === 'transparent') ? 'transparent' : '#ffffff';   // 白底或透明可選
    const items = [];
    if (spec.kind === 'plain') {
        items.push(mkLabelText(spec.text, fs, { textAlign: spec.align || 'center', backgroundColor: (spec.bg === 'transparent') ? '' : '#ffffff', specPath: 'text' }));
    } else if (spec.kind === 'box') {
        const t = mkLabelText(spec.text, fs, { textAlign: spec.align || 'center', originX: 'center', originY: 'center', left: 0, top: 0, specPath: 'text' });
        const pad = spec.pad != null ? spec.pad : fs * 0.32;
        items.push(new fabric.Rect({
            left: 0, top: 0, originX: 'center', originY: 'center',
            width: t.width + pad * 2, height: t.height + pad * 1.1,
            fill: bgFill, stroke: '#000000', strokeWidth: bw
        }));
        items.push(t);
    } else if (spec.kind === 'inline') {
        const pad = fs * 0.32, gap = fs * 0.35;
        const chunks = []; let x = 0;
        (spec.segs || []).forEach((s, i) => {
            const t = mkLabelText(s.t, fs, { left: x, top: 0, originY: 'center', specPath: 'segs.' + i });
            if (s.box) {
                chunks.push(new fabric.Rect({
                    left: x - fs * 0.14, top: -fs * 0.66, width: t.width + fs * 0.28, height: fs * 1.32,
                    fill: 'transparent', stroke: '#000000', strokeWidth: Math.max(2, bw - 1)
                }));
            }
            chunks.push(t);
            x += t.width + gap;
        });
        const total = x - gap;
        items.push(new fabric.Rect({
            left: -pad, top: -fs * 0.66 - pad * 0.55, width: total + pad * 2, height: fs * 1.32 + pad * 1.1,
            fill: bgFill, stroke: '#000000', strokeWidth: bw
        }));
        items.push(...chunks);
    } else if (spec.kind === 'grind3') {
        // 研磨記號：n 個倒三角形並排＋上方文字（預設 G）；文字雙擊可改
        const t = fs * 1.15, th = t * 0.866, n = Math.max(1, spec.count || 3);
        items.push(mkLabelText(spec.text != null ? spec.text : 'G', fs, {
            originX: 'center', originY: 'bottom', left: n * t / 2, top: -fs * 0.12,
            backgroundColor: (spec.bg === 'transparent') ? '' : '#ffffff', specPath: 'text'
        }));
        for (let i = 0; i < n; i++) {
            items.push(new fabric.Polygon(
                [{ x: i * t, y: 0 }, { x: (i + 1) * t, y: 0 }, { x: i * t + t / 2, y: th }],
                { fill: bgFill, stroke: __labelInk, strokeWidth: Math.max(2, bw * 0.75), strokeUniform: true, strokeLineJoin: 'round' }
            ));
        }
    } else if (spec.kind === 'rough') {
        // 表面粗糙度記號：倒三角＋斜線＋水平尾線；左側數值（0.8）與尾線上文字（G）雙擊都可改
        const t = fs * 1.15, th = t * 0.866, sw2 = Math.max(2, bw * 0.75);
        const B = { x: t, y: 0 }, C = { x: t / 2, y: th };
        const k = 2.1;   // 斜線＝三角形右邊沿 C→B 方向延伸
        const E = { x: C.x + (B.x - C.x) * k, y: C.y + (B.y - C.y) * k };
        const tail = t * 1.35;
        items.push(new fabric.Polygon([{ x: 0, y: 0 }, B, C],
            { fill: bgFill, stroke: __labelInk, strokeWidth: sw2, strokeUniform: true, strokeLineJoin: 'round' }));
        items.push(new fabric.Polyline([B, E, { x: E.x + tail, y: E.y }],
            { fill: 'transparent', stroke: __labelInk, strokeWidth: sw2, strokeUniform: true, strokeLineJoin: 'round' }));
        items.push(mkLabelText(spec.val != null ? spec.val : '0.8', fs * 0.85, {
            originX: 'right', originY: 'center', left: t * 0.95, top: -th * 0.5,
            backgroundColor: (spec.bg === 'transparent') ? '' : '#ffffff', specPath: 'val'
        }));
        items.push(mkLabelText(spec.text != null ? spec.text : 'G', fs, {
            originX: 'center', originY: 'bottom', left: E.x + tail * 0.5, top: E.y - fs * 0.08,
            backgroundColor: (spec.bg === 'transparent') ? '' : '#ffffff', specPath: 'text'
        }));
    } else if (spec.kind === 'table') {
        const rows = spec.rows || [];
        const cols = spec.cols || null;   // 雙欄式：title + 欄標題列 + 空白格（如 熱處理前置：防碳/鎖螺絲）
        const pad = fs * 0.4;
        // 空白格內建可填數值的文字（預設空字串）：雙擊空白格即可像公差標籤那樣填入數字，格子隨內容自動加寬加高
        if (cols && cols.length) spec.cellVals = cols.map((c, i) => (spec.cellVals || [])[i] || '');
        else if (rows.length) spec.vals = rows.map((r, i) => (spec.vals || [])[i] || '');
        else if (spec.body == null) spec.body = '';
        const titleT = mkLabelText(spec.title || '', fs, { specPath: 'title' });
        const th = fs * 1.7, rh = spec.rowH || fs * 2.8;
        if (cols && cols.length) {
            const valTs = cols.map((c, i) => mkValText(spec.cellVals[i], fs, { originX: 'center', originY: 'center', textAlign: 'center', specPath: 'cellVals.' + i }));
            const colW = Math.max(fs * 4, ...cols.map(c => mkLabelText(c, fs).width + pad * 2), ...valTs.map(t => t.width + pad * 2));
            const W = Math.max(titleT.width + pad * 2, colW * cols.length);
            const cw = W / cols.length;
            const bodyH = Math.max(spec.bodyH || fs * 2.6, ...valTs.map(t => t.height + pad));
            items.push(new fabric.Rect({ left: 0, top: 0, width: W, height: th, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
            titleT.set({ left: W / 2, top: th / 2, originX: 'center', originY: 'center' });
            items.push(titleT);
            cols.forEach((c, i) => {
                items.push(new fabric.Rect({ left: i * cw, top: th, width: cw, height: rh * 0.7, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
                items.push(mkLabelText(c, fs, { left: i * cw + cw / 2, top: th + rh * 0.35, originX: 'center', originY: 'center', specPath: 'cols.' + i }));
                items.push(new fabric.Rect({ left: i * cw, top: th + rh * 0.7, width: cw, height: bodyH, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
                valTs[i].set({ left: i * cw + cw / 2, top: th + rh * 0.7 + bodyH / 2 });
                items.push(valTs[i]);
            });
        } else if (!rows.length) {
            // 標題＋空白大格（如 (  )粗滾、(  )精滾、(  )插齒）；大格雙擊可填數值
            const bodyT = mkValText(spec.body, fs, { originX: 'center', originY: 'center', textAlign: 'center', specPath: 'body' });
            const W = Math.max(titleT.width + pad * 2, fs * 6, bodyT.width + pad * 2);
            const bodyH = Math.max(spec.bodyH || fs * 3.4, bodyT.height + pad);
            items.push(new fabric.Rect({ left: 0, top: 0, width: W, height: th, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
            titleT.set({ left: W / 2, top: th / 2, originX: 'center', originY: 'center' });
            items.push(titleT);
            items.push(new fabric.Rect({ left: 0, top: th, width: W, height: bodyH, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
            bodyT.set({ left: W / 2, top: th + bodyH / 2 });
            items.push(bodyT);
        } else {
            const valTs = rows.map((r, i) => mkValText(spec.vals[i], fs, { originX: 'center', originY: 'center', textAlign: 'center', specPath: 'vals.' + i }));
            let col0 = Math.max(fs, ...rows.map(r => mkLabelText(r, fs).width)) + pad * 2;
            const col1Min = Math.max(spec.cellW || fs * 5.5, ...valTs.map(t => t.width + pad * 2));
            const rowH = Math.max(rh, ...valTs.map(t => t.height + pad));   // 多行數值（如上下公差）整列自動加高
            const W = Math.max(titleT.width + pad * 2, col0 + col1Min);
            const col1 = W - col0;
            items.push(new fabric.Rect({ left: 0, top: 0, width: W, height: th, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
            titleT.set({ left: W / 2, top: th / 2, originX: 'center', originY: 'center' });
            items.push(titleT);
            rows.forEach((r, i) => {
                const y = th + i * rowH;
                items.push(new fabric.Rect({ left: 0, top: y, width: col0, height: rowH, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
                items.push(new fabric.Rect({ left: col0, top: y, width: col1, height: rowH, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
                items.push(mkLabelText(r, fs, { left: col0 / 2, top: y + rowH / 2, originX: 'center', originY: 'center', specPath: 'rows.' + i }));
                valTs[i].set({ left: col0 + col1 / 2, top: y + rowH / 2 });
                items.push(valTs[i]);
            });
        }
    }
    const g = new fabric.Group(items, {});
    g.labelSpec = JSON.parse(JSON.stringify(spec));
    g.labelKind = spec.kind;
    return g;
}
function setSpecByPath(spec, path, val) {
    const parts = String(path).split('.');
    let cur = spec;
    for (let i = 0; i < parts.length - 1; i++) cur = cur[parts[i]];
    const last = parts[parts.length - 1];
    if (cur[last] !== null && typeof cur[last] === 'object' && 't' in cur[last]) cur[last].t = val;
    else cur[last] = val;
}
function viewCenter() {
    const vpt = canvas.viewportTransform;
    return { x: (wrap.clientWidth / 2 - vpt[4]) / vpt[0], y: (wrap.clientHeight / 2 - vpt[5]) / vpt[3] };
}
function placeLabelObject(o) {
    // 尺寸算出 NaN 的物件一旦進畫布會毒化整個渲染迴圈（殘影、卡死），寧可不插入
    if (!o || !isFinite(o.width) || !isFinite(o.height)) { toast('標籤建立失敗（尺寸異常），未插入'); return; }
    const c = viewCenter();
    o.set({ originX: 'center', originY: 'center', left: c.x, top: c.y });
    o.setCoords();
    canvas.add(o);
    canvas.setActiveObject(o);
    setTool('select');
    canvas.requestRenderAll();
    pushState();
}
/* 由 spec 建立標籤物件（統一入口；multi＝群組標籤：多個標籤直排組成一組） */
function buildLabelObject(spec, done) {
    if (spec.kind === 'image') {
        fabric.Image.fromURL(encodeURI(spec.url), function (img) {
            if (!img || !img.width) { done(null); return; }
            img.labelSpec = spec; img.labelKind = 'image';
            done(img);
        }, { crossOrigin: 'anonymous' });
        return;
    }
    if (spec.kind === 'fabric') {
        const j = JSON.parse(JSON.stringify(spec.json));
        if (j.type === 'activeSelection') j.type = 'group';
        fabric.util.enlivenObjects([j], function (objs) {
            if (!objs || !objs[0]) { done(null); return; }
            objs[0].labelSpec = spec; objs[0].labelKind = 'fabric';
            done(objs[0]);
        });
        return;
    }
    if (spec.kind === 'multi') {
        const parts = (spec.specs || []).slice();
        const objs = [];
        const next = i => {
            if (i >= parts.length) {
                if (!objs.length) { done(null); return; }
                let y = 0;
                objs.forEach(o => {
                    o.set({ originX: 'left', originY: 'top', left: 0, top: y });
                    o.setCoords();
                    y += o.getScaledHeight() + 12;
                });
                const g = new fabric.Group(objs, {});
                g.labelSpec = spec; g.labelKind = 'multi';
                done(g);
                return;
            }
            buildLabelObject(parts[i], o => { if (o) objs.push(o); next(i + 1); });
        };
        next(0);
        return;
    }
    done(makeLabelFromSpec(spec));
}
function insertLabel(spec) {
    const s = JSON.parse(JSON.stringify(spec));
    const cb = document.getElementById('lib-transparent');
    if (cb && cb.checked && !['image', 'fabric', 'multi'].includes(s.kind)) s.bg = 'transparent';
    buildLabelObject(s, o => {
        if (!o) { toast('標籤載入失敗'); return; }
        placeLabelObject(o);
    });
}
/* 選取中的規格標籤：白底 ⇄ 透明 切換 */
function toggleLabelBg() {
    const g = canvas.getActiveObject();
    if (!g || !g.labelSpec || g.labelSpec.kind === 'fabric') { toast('請先選取一個標籤（內建/規格標籤才能切換底色）'); return; }
    const spec = JSON.parse(JSON.stringify(g.labelSpec));
    spec.bg = (spec.bg === 'transparent') ? 'white' : 'transparent';
    const center = g.getCenterPoint();
    const { scaleX, scaleY, angle } = g;
    canvas.remove(g);
    const ng = makeLabelFromSpec(spec);
    ng.set({ scaleX, scaleY, angle, originX: 'center', originY: 'center' });
    ng.setPositionByOrigin(center, 'center', 'center');
    ng.setCoords();
    canvas.add(ng);
    canvas.setActiveObject(ng);
    canvas.requestRenderAll();
    pushState();
    toast('標籤底色：' + (spec.bg === 'transparent' ? '透明' : '白底'));
}

/* 標籤庫面板（分類顯示＋篩選） */
let libLoaded = false, customLabels = [];
function toggleLabelLib() {
    const el = document.getElementById('label-lib');
    el.classList.toggle('show');
    if (el.classList.contains('show') && !libLoaded) { loadLabelLibrary(); libLoaded = true; }
}
/* 側欄寬度：拖曳左緣調整，記住每台電腦的偏好 */
(function initLibResizer() {
    const panel = document.getElementById('label-lib');
    const saved = parseInt(localStorage.getItem('eg_imgedit_lib_w') || '0', 10);
    if (saved >= 220) panel.style.width = Math.min(saved, window.innerWidth * 0.7) + 'px';
    const rz = document.getElementById('lib-resizer');
    rz.addEventListener('mousedown', function (e) {
        e.preventDefault();
        rz.classList.add('active');
        const move = ev => {
            let w = wrap.getBoundingClientRect().right - ev.clientX;   // 面板右緣固定貼齊畫布右緣
            w = Math.max(220, Math.min(window.innerWidth * 0.7, w));
            panel.style.width = w + 'px';
        };
        const up = () => {
            rz.classList.remove('active');
            localStorage.setItem('eg_imgedit_lib_w', String(Math.round(panel.getBoundingClientRect().width)));
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
        };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    });
})();
function labelThumbHTML(dataURL, name, delId) {
    return '<img src="' + (dataURL || '') + '" alt=""><span class="lib-name">' + escHtml(name) + '</span>' +
        (delId ? '<span class="lib-del" title="刪除這個自訂標籤" onclick="event.stopPropagation();deleteCustomLabel(' + delId + ')"><i class="fa fa-trash"></i></span>' : '');
}
function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
/* #標示：縮圖左上角有底色小徽章（面板與管理跳窗共用） */
function tagChipsHTML(tags) {
    const arr = String(tags || '').trim().split(/\s+/).filter(Boolean);
    if (!arr.length) return '';
    return '<span class="lib-tags">' + arr.map(t => '<span class="lib-tag">#' + escHtml(t) + '</span>').join('') + '</span>';
}
function allTags() {
    const set = new Set();
    customLabels.forEach(r => String(r.tags || '').trim().split(/\s+/).filter(Boolean).forEach(t => set.add(t)));
    return Array.from(set);
}
function allCategories() {
    const cats = [];
    PRESET_LABELS.forEach(p => { if (p.cat && !cats.includes(p.cat)) cats.push(p.cat); });
    customLabels.forEach(r => { const c = r.category || '未分類'; if (!cats.includes(c)) cats.push(c); });
    return cats;
}
function refreshCatControls() {
    const sel = document.getElementById('lib-cat-filter');
    const keep = sel.value;
    sel.innerHTML = '<option value="">— 全部分類 —</option>' +
        allCategories().map(c => '<option value="' + escHtml(c) + '">' + escHtml(c) + '</option>').join('');
    if (Array.from(sel.options).some(o => o.value === keep)) sel.value = keep;
    document.getElementById('lib-cat-datalist').innerHTML =
        allCategories().map(c => '<option value="' + escHtml(c) + '">').join('');
    document.getElementById('lib-tag-datalist').innerHTML =
        allTags().map(t => '<option value="' + escHtml(t) + '">').join('');
}
/* ── 標籤管理跳窗：框選 / Ctrl 多選 / 拖曳搬移（Ctrl+拖曳＝複製） ── */
let LIB_UID = 0, ALL_DEPTS = [];
const lmSel = new Set();
async function openLibMgr() {
    lmSel.clear();
    lmAnchor = null; lmAnchorGrid = null;
    showModal('libmgr-modal');
    document.getElementById('libmgr-body').innerHTML = '<div style="color:#8b949e;padding:20px;">載入中…</div>';
    await loadLabelLibrary();   // 取最新資料（含部門/權限資訊）
    lmInitChips();
    renderLibMgr();
    if (!MY_DEPTS.length && !IS_MGR) toast('提醒：你的帳號（' + (USER_CNAME || '') + '，ID ' + LIB_UID + '）在人員部門對應表查無部門，故沒有部門欄可放；請把此訊息回報管理者');
}
function lmCanTouch(row) { return IS_MGR || Number(row.owner_user_id) === Number(LIB_UID); }
/* 部門晶片：單一部門欄，頂部列出使用者的部門按鈕（可複選）。
   拖進部門欄＝同時發佈到所有點亮的部門；顯示內容也跟著點亮的部門過濾。 */
let lmDeptChips = [];
let lmAnchor = null, lmAnchorGrid = null;   // Shift 範圍選取的錨點
function lmInitChips() {
    lmDeptChips = MY_DEPTS.map(d => ({ id: Number(d.id), name: d.name, on: true }));
    // 管理者：把「已有部門標籤」的其他部門也列為晶片（預設不點亮）
    if (IS_MGR) {
        ALL_DEPTS.forEach(d => {
            if (!lmDeptChips.some(c => c.id === Number(d.id)) &&
                customLabels.some(r => r.owner_type === 'dept' && Number(r.owner_dept_id) === Number(d.id))) {
                lmDeptChips.push({ id: Number(d.id), name: d.name, on: false });
            }
        });
    }
}
function lmActiveDepts() { return lmDeptChips.filter(c => c.on).map(c => c.id); }
function lmToggleChip(id) {
    const c = lmDeptChips.find(c => c.id === id);
    if (c) c.on = !c.on;
    renderLibMgr();
}
function lmAddDeptChip(sel) {
    const id = parseInt(sel.value, 10);
    if (!id) return;
    if (!lmDeptChips.some(c => c.id === id)) {
        const d = ALL_DEPTS.find(d => Number(d.id) === id);
        if (d) lmDeptChips.push({ id, name: d.name, on: true });
    } else lmDeptChips.find(c => c.id === id).on = true;
    renderLibMgr();
}
function lmCols() {
    return [
        { scope: 'private', title: '🔒 私人標籤', color: '#b39ddb' },
        { scope: 'dept',    title: '👥 部門標籤', color: '#1abb9c' },
        { scope: 'company', title: '🏢 公司共用' + (IS_MGR ? '' : '（唯讀）'), color: '#e67e22' }
    ];
}
function lmUpdateCount() { document.getElementById('lm-sel-count').textContent = lmSel.size; }
function renderLibMgr() {
    const body = document.getElementById('libmgr-body');
    // 記住各欄捲動位置：設定分類/#標示/隱藏名稱等操作後重繪，不要跳回最上面
    const scrollPos = {};
    body.querySelectorAll('.lm-col').forEach(col => {
        const g = col.querySelector('.lm-grid');
        if (g) scrollPos[col.dataset.scope] = g.scrollTop;
    });
    body.innerHTML = '';
    lmCols().forEach(col => {
        const el = document.createElement('div');
        el.className = 'lm-col';
        el.dataset.scope = col.scope;
        let head = '<span style="color:' + col.color + ';">' + escHtml(col.title) + '</span>';
        if (col.scope === 'dept') {
            // 部門切換鈕（可複選）：拖進此欄＝同時發佈到所有點亮的部門
            head += '<span style="display:flex;flex-wrap:wrap;gap:4px;margin-left:4px;">'
                + lmDeptChips.map(c => '<span class="lm-chip' + (c.on ? ' on' : '') + '" onclick="lmToggleChip(' + c.id + ')" title="點擊切換；拖進此欄會同時放到所有亮起的部門">' + escHtml(c.name) + '</span>').join('')
                + '</span>';
            if (IS_MGR && ALL_DEPTS.length) {
                head += '<select onchange="lmAddDeptChip(this)" onclick="event.stopPropagation()" style="background:#14161a;border:1px solid #45494f;color:#8b949e;border-radius:3px;font-size:11px;padding:1px;max-width:70px;">'
                    + '<option value="">＋部門</option>'
                    + ALL_DEPTS.filter(d => !lmDeptChips.some(c => c.id === Number(d.id))).map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('')
                    + '</select>';
            }
        }
        el.innerHTML = '<div class="lm-col-head" style="flex-wrap:wrap;">' + head + '</div>';
        const grid = document.createElement('div');
        grid.className = 'lm-grid';
        // 欄內容
        let rows = [];
        if (col.scope === 'private') rows = customLabels.filter(r => r.owner_type === 'private');
        else if (col.scope === 'company') rows = customLabels.filter(r => r.owner_type === 'company');
        else if (col.scope === 'dept') {
            const act = lmActiveDepts();
            rows = customLabels.filter(r => r.owner_type === 'dept' && act.includes(Number(r.owner_dept_id)));
        }
        rows.sort((a, b) => String(a.category || '').localeCompare(String(b.category || ''))
            || String(a.label_name || '').localeCompare(String(b.label_name || '')));
        let lastCat = null;
        rows.forEach(row => {
            // 分類標題列：一眼看出哪些標籤屬於哪一類；點標題＝整組選取（Ctrl+點＝保留原選取加減這一組）
            const catName = row.category || '未分類';
            if (catName !== lastCat) {
                lastCat = catName;
                const groupIds = rows.filter(r => (r.category || '未分類') === catName).map(r => r.label_id);
                const ch = document.createElement('div');
                ch.className = 'lm-cat-head';
                ch.textContent = '▸ ' + catName + '（' + groupIds.length + '）';
                ch.title = '點擊＝選取這一分類的全部標籤（已全選時再點＝取消）；Ctrl+點擊＝保留原選取再加減這一組';
                ch.addEventListener('click', e => {
                    e.stopPropagation();
                    const allIn = groupIds.every(id => lmSel.has(id));
                    if (!(e.ctrlKey || e.metaKey)) lmSel.clear();
                    if (allIn) groupIds.forEach(id => lmSel.delete(id));
                    else groupIds.forEach(id => lmSel.add(id));
                    lmAnchor = null; lmAnchorGrid = null;
                    syncLmSelClass(); lmUpdateCount();
                });
                grid.appendChild(ch);
            }
            const it = document.createElement('div');
            it.className = 'lm-item' + (lmSel.has(row.label_id) ? ' sel' : '') + (lmCanTouch(row) ? '' : ' lock');
            it.style.position = 'relative';
            it.dataset.id = row.label_id;
            it.title = row.label_name + (row.category ? '（' + row.category + '）' : '')
                + (row.tags ? '｜#' + String(row.tags).trim().split(/\s+/).join(' #') : '')
                + (lmCanTouch(row) ? '' : '｜他人建立，無法移動');
            it.innerHTML = '<img alt=""><span class="lm-name"' + (Number(row.hide_name) ? ' style="color:#bbb;font-style:italic;"' : '') + '>'
                + escHtml(row.label_name) + (Number(row.hide_name) ? ' <i class="fa fa-eye-slash"></i>' : '') + '</span>'
                + (col.scope === 'dept' && row.dept_name ? '<span class="lm-dept-badge">' + escHtml(row.dept_name) + '</span>' : '')
                + tagChipsHTML(row.tags);
            makeSpecThumb(row.spec, url => { const img = it.querySelector('img'); if (img && url) img.src = url; });
            it.draggable = lmCanTouch(row);
            it.addEventListener('click', e => {
                e.stopPropagation();
                const id = row.label_id;
                if (e.shiftKey && lmAnchor != null && lmAnchorGrid === grid) {
                    // Shift＝從錨點到此的範圍選取（同一欄內）；加 Ctrl 則保留原選取
                    const items = Array.from(grid.querySelectorAll('.lm-item'));
                    const i1 = items.findIndex(x => parseInt(x.dataset.id, 10) === lmAnchor);
                    const i2 = items.findIndex(x => parseInt(x.dataset.id, 10) === id);
                    if (i1 >= 0 && i2 >= 0) {
                        if (!(e.ctrlKey || e.metaKey)) lmSel.clear();
                        const a = Math.min(i1, i2), b = Math.max(i1, i2);
                        for (let k = a; k <= b; k++) lmSel.add(parseInt(items[k].dataset.id, 10));
                    }
                } else if (e.ctrlKey || e.metaKey) {
                    lmSel.has(id) ? lmSel.delete(id) : lmSel.add(id);
                    lmAnchor = id; lmAnchorGrid = grid;
                } else {
                    lmSel.clear(); lmSel.add(id);
                    lmAnchor = id; lmAnchorGrid = grid;
                }
                syncLmSelClass(); lmUpdateCount();
            });
            it.addEventListener('dragstart', e => {
                if (!lmSel.has(row.label_id)) { lmSel.clear(); lmSel.add(row.label_id); syncLmSelClass(); lmUpdateCount(); }
                e.dataTransfer.setData('text/plain', 'eg-labels');
                e.dataTransfer.effectAllowed = 'copyMove';
            });
            grid.appendChild(it);
        });
        if (!rows.length) grid.innerHTML = '<div style="color:#555;font-size:11px;padding:6px;">（空）拖曳標籤到這裡</div>';
        // 放置目標
        el.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = e.ctrlKey ? 'copy' : 'move';
            el.classList.add('dragover');
        });
        el.addEventListener('dragleave', () => el.classList.remove('dragover'));
        el.addEventListener('drop', e => {
            e.preventDefault();
            el.classList.remove('dragover');
            const scope = col.scope;
            let deptIds = [];
            if (scope === 'dept') {
                deptIds = lmActiveDepts();
                if (!deptIds.length) { toast('請先點亮至少一個部門按鈕（部門欄頂部）'); return; }
            }
            if (scope === 'company' && !IS_MGR) { toast('只有管理者可放到公司共用'); return; }
            lmMove(scope, deptIds, e.ctrlKey ? 'copy' : 'move');
        });
        // 框選（滑鼠在欄內空白處拖出選取框）
        grid.addEventListener('mousedown', e => {
            if (e.target.closest('.lm-item, .lm-cat-head') || e.button !== 0) return;
            const keep = e.ctrlKey || e.metaKey;
            const rub = document.getElementById('lm-rubber');
            const sx = e.clientX, sy = e.clientY;
            rub.style.display = 'block';
            const move = ev => {
                const x = Math.min(sx, ev.clientX), y = Math.min(sy, ev.clientY);
                const w = Math.abs(ev.clientX - sx), h = Math.abs(ev.clientY - sy);
                Object.assign(rub.style, { left: x + 'px', top: y + 'px', width: w + 'px', height: h + 'px' });
                if (!keep) lmSel.clear();
                grid.querySelectorAll('.lm-item').forEach(it => {
                    const r = it.getBoundingClientRect();
                    const hit = !(r.right < x || r.left > x + w || r.bottom < y || r.top > y + h);
                    if (hit) lmSel.add(parseInt(it.dataset.id, 10));
                });
                syncLmSelClass(); lmUpdateCount();
            };
            const up = () => {
                rub.style.display = 'none'; rub.style.width = '0'; rub.style.height = '0';
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            };
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
            e.preventDefault();
        });
        el.appendChild(grid);
        body.appendChild(el);
    });
    // 還原各欄捲動位置
    body.querySelectorAll('.lm-col').forEach(col => {
        const g = col.querySelector('.lm-grid');
        if (g && scrollPos[col.dataset.scope]) g.scrollTop = scrollPos[col.dataset.scope];
    });
    syncLmSelClass(); lmUpdateCount();
}
function syncLmSelClass() {
    document.querySelectorAll('#libmgr-body .lm-item').forEach(it =>
        it.classList.toggle('sel', lmSel.has(parseInt(it.dataset.id, 10))));
}
async function lmMove(scope, deptIds, mode) {
    if (!lmSel.size) { toast('請先選取標籤'); return; }
    try {
        const fd = new FormData();
        fd.append('action', 'move_labels');
        fd.append('label_ids', JSON.stringify(Array.from(lmSel)));
        fd.append('mode', mode);
        fd.append('scope', scope);
        fd.append('dept_ids', JSON.stringify(deptIds || []));
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        toast((mode === 'copy' ? '已複製 ' : '已搬移 ') + res.count + ' 個標籤');
        lmSel.clear();
        await loadLabelLibrary();
        renderLibMgr();
    } catch (e) { toast('執行失敗：' + (e.message || '')); }
}
/* 組成群組標籤：選取的標籤 spec 打包成 multi，存回標籤庫（點一下整組插入） */
function lmMakeGroupLabel() {
    if (lmSel.size < 2) { toast('請先選取兩個以上的標籤'); return; }
    const rows = customLabels.filter(r => lmSel.has(r.label_id));   // 依顯示順序
    if (!rows.length) return;
    pendingLabelSpec = { kind: 'multi', specs: rows.map(r => JSON.parse(JSON.stringify(r.spec))) };
    document.getElementById('sl-name').value = '';
    document.getElementById('sl-cat').value = '群組';
    document.getElementById('sl-tags').value = '';
    document.getElementById('sl-scope').value = 'private';
    const sd = document.getElementById('sl-dept');
    sd.innerHTML = MY_DEPTS.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
    sd.style.display = 'none';
    showModal('savelabel-modal');
    document.getElementById('sl-name').focus();
}
/* 批次設定分類 */
function lmOpenSetCat() {
    if (!lmSel.size) { toast('請先選取標籤'); return; }
    document.getElementById('sc-cat').value = '';
    showModal('setcat-modal');
    document.getElementById('sc-cat').focus();
}
async function confirmSetCat() {
    const cat = document.getElementById('sc-cat').value.trim();
    try {
        const fd = new FormData();
        fd.append('action', 'set_label_category');
        fd.append('label_ids', JSON.stringify(Array.from(lmSel)));
        fd.append('category', cat);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        hideModal('setcat-modal');
        toast('已把 ' + res.count + ' 個標籤設為分類「' + (cat || '未分類') + '」');
        await loadLabelLibrary();
        renderLibMgr();
    } catch (e) { toast('設定失敗：' + (e.message || '')); }
}
/* 批次設定 #標示 */
function lmOpenSetTags() {
    if (!lmSel.size) { toast('請先選取標籤'); return; }
    let pre = '';
    if (lmSel.size === 1) {   // 只選一個時帶入現值方便修改
        const r = customLabels.find(r => lmSel.has(r.label_id));
        pre = (r && r.tags) ? r.tags : '';
    }
    document.getElementById('st-tags').value = pre;
    showModal('settags-modal');
    document.getElementById('st-tags').focus();
}
async function confirmSetTags() {
    const tags = document.getElementById('st-tags').value.trim();
    try {
        const fd = new FormData();
        fd.append('action', 'set_label_tags');
        fd.append('label_ids', JSON.stringify(Array.from(lmSel)));
        fd.append('tags', tags);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        hideModal('settags-modal');
        toast(tags ? ('已把 ' + res.count + ' 個標籤設定#標示「' + tags + '」') : ('已清除 ' + res.count + ' 個標籤的#標示'));
        await loadLabelLibrary();
        renderLibMgr();
    } catch (e) { toast('設定失敗：' + (e.message || '')); }
}
async function lmSetHideName(hide) {
    if (!lmSel.size) { toast('請先選取標籤'); return; }
    try {
        const fd = new FormData();
        fd.append('action', 'set_label_flag');
        fd.append('label_ids', JSON.stringify(Array.from(lmSel)));
        fd.append('hide_name', hide);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        toast((hide ? '已設定 ' : '已恢復 ') + res.count + ' 個標籤' + (hide ? '不顯示名稱' : '顯示名稱'));
        await loadLabelLibrary();
        renderLibMgr();
    } catch (e) { toast('設定失敗：' + (e.message || '')); }
}
async function lmDeleteSelected() {
    if (!lmSel.size) { toast('請先選取要刪除的標籤'); return; }
    if (!confirm('確定刪除選取的 ' + lmSel.size + ' 個標籤？')) return;
    let ok = 0, fail = 0;
    for (const id of Array.from(lmSel)) {
        try {
            const fd = new FormData();
            fd.append('action', 'delete_label'); fd.append('label_id', id);
            const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
            res.success ? ok++ : fail++;
        } catch (e) { fail++; }
    }
    toast('已刪除 ' + ok + ' 個' + (fail ? '；' + fail + ' 個無權限或失敗' : ''));
    lmSel.clear();
    await loadLabelLibrary();
    renderLibMgr();
}

function renderLibrary() {
    const filter = document.getElementById('lib-cat-filter').value;
    // 模糊搜尋：名稱/#標示/分類都比對；「#xx」只比對#標示；空格分隔多關鍵字＝全部都要符合
    const q = (document.getElementById('lib-search').value || '').trim().toLowerCase();
    const words = q ? q.split(/\s+/) : [];
    const hitQ = hay => !words.length || words.every(w => hay.includes(w));
    const tagHay = tags => String(tags || '').trim().split(/\s+/).filter(Boolean).map(t => '#' + t).join(' ');
    // 內建：依分類分組
    const pbox = document.getElementById('lib-presets');
    pbox.innerHTML = '';
    const groups = {};
    PRESET_LABELS.forEach(p => {
        if (filter && p.cat !== filter) return;
        if (!hitQ((p.name + ' ' + (p.cat || '')).toLowerCase())) return;
        (groups[p.cat || '未分類'] = groups[p.cat || '未分類'] || []).push(p);
    });
    Object.keys(groups).forEach(cat => {
        const h = document.createElement('div'); h.className = 'lib-sec'; h.textContent = '▸ ' + cat;
        pbox.appendChild(h);
        groups[cat].forEach(p => {
            const g = makeLabelFromSpec(p.spec);
            const url = g.toDataURL({ format: 'png', multiplier: Math.min(1, 210 / g.width, 100 / g.height) });
            const div = document.createElement('div');
            div.className = 'lib-item';
            div.innerHTML = labelThumbHTML(url, p.name, 0);
            div.onclick = () => insertLabel(p.spec);
            pbox.appendChild(div);
        });
    });
    if (!pbox.children.length) pbox.innerHTML = '<div style="color:#666;font-size:11px;padding:6px;">' + (q ? '沒有符合搜尋的內建標籤' : '此分類沒有內建標籤') + '</div>';

    // 自訂：先分範圍（公司共用/部門/私人），範圍內再依分類分組
    const cbox = document.getElementById('lib-customs');
    cbox.innerHTML = '';
    const SCOPES = [
        { key: 'company', title: '🏢 公司共用', color: '#e67e22' },
        { key: 'dept',    title: '👥 部門標籤', color: '#1abb9c' },
        { key: 'private', title: '🔒 私人標籤', color: '#b39ddb' }
    ];
    SCOPES.forEach(sc => {
        const rows = customLabels.filter(r => (r.owner_type || 'company') === sc.key
            && (!filter || (r.category || '未分類') === filter)
            && hitQ((r.label_name + ' ' + (r.category || '') + ' ' + tagHay(r.tags)).toLowerCase()));
        if (!rows.length) return;
        const sh = document.createElement('div');
        sh.className = 'lib-sec';
        sh.style.cssText = 'font-weight:700;color:' + sc.color + ';margin-top:10px;';
        sh.textContent = sc.title;
        cbox.appendChild(sh);
        const cgroups = {};
        rows.forEach(r => { const c = r.category || '未分類'; (cgroups[c] = cgroups[c] || []).push(r); });
        Object.keys(cgroups).forEach(cat => {
            const h = document.createElement('div'); h.className = 'lib-sec'; h.textContent = '▸ ' + cat;
            cbox.appendChild(h);
            cgroups[cat].forEach(row => {
                const div = document.createElement('div');
                div.className = 'lib-item';
                const suffix = (sc.key === 'dept' && row.dept_name) ? '【' + row.dept_name + '】' : '';
                const fullName = row.label_name + suffix + (row.created_by ? '（' + row.created_by + '）' : '');
                // hide_name＝縮圖即內容，名稱不重複顯示（滑鼠停留仍看得到）
                div.innerHTML = (Number(row.hide_name) ? '<img alt="">' : labelThumbHTML('', fullName, row.label_id)) + tagChipsHTML(row.tags);
                div.title = fullName + (row.tags ? '｜#' + String(row.tags).trim().split(/\s+/).join(' #') : '');
                div.onclick = () => insertLabel(row.spec);
                cbox.appendChild(div);
                makeSpecThumb(row.spec, url => { const img = div.querySelector('img'); if (img && url) img.src = url; });
            });
        });
    });
    if (!cbox.children.length)
        cbox.innerHTML = '<div style="color:#666;font-size:11px;padding:6px;">' + ((filter || q) ? '沒有符合篩選/搜尋的自訂標籤' : '尚無自訂標籤。選取畫布上的物件後按下方「把選取存為標籤」。') + '</div>';
}
async function loadLabelLibrary() {
    try {
        const fd = new FormData(); fd.append('action', 'list_labels');
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        customLabels = [];
        res.labels.forEach(row => {
            try { row.spec = JSON.parse(row.spec_json); row.label_id = Number(row.label_id); customLabels.push(row); } catch (e) { /* 略過壞資料 */ }
        });
        LIB_UID = Number(res.uid || 0);
        ALL_DEPTS = res.all_depts || [];
        if (Array.isArray(res.my_depts)) {   // 以後端最新回傳為準（修正頁面載入時的舊資料）
            MY_DEPTS.length = 0;
            res.my_depts.forEach(d => MY_DEPTS.push(d));
        }
        refreshCatControls();
        renderLibrary();
    } catch (e) {
        refreshCatControls();
        renderLibrary();
        document.getElementById('lib-customs').innerHTML =
            '<div style="color:#c0392b;font-size:11px;padding:6px;">標籤庫載入失敗：' + escHtml(e.message || '') + '</div>';
    }
}
function makeSpecThumb(spec, cb) {
    if (spec.kind === 'image') { cb(encodeURI(spec.url)); return; }   // 縮圖直接用原圖
    buildLabelObject(JSON.parse(JSON.stringify(spec)), g => {
        if (!g) { cb(null); return; }
        try { cb(g.toDataURL({ format: 'png', multiplier: Math.min(1, 210 / g.width, 100 / g.height) })); } catch (e) { cb(null); }
    });
}
let pendingLabelSpec = null;
function saveSelectionAsLabel() {
    const obj = canvas.getActiveObject();
    if (!obj) { toast('請先在畫布上選取要存成標籤的物件（可框選多個）'); return; }
    if (obj.labelSpec && obj.labelSpec.kind !== 'fabric') pendingLabelSpec = obj.labelSpec;
    else {
        const j = obj.toObject(SNAP_PROPS);
        if (j.type === 'activeSelection') j.type = 'group';
        pendingLabelSpec = { kind: 'fabric', json: j };
    }
    document.getElementById('sl-name').value = '';
    document.getElementById('sl-cat').value = document.getElementById('lib-cat-filter').value || '';
    document.getElementById('sl-tags').value = '';
    // 範圍：預設私人；部門下拉帶自己所屬部門
    document.getElementById('sl-scope').value = 'private';
    const sd = document.getElementById('sl-dept');
    sd.innerHTML = MY_DEPTS.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
    sd.style.display = 'none';
    showModal('savelabel-modal');
    document.getElementById('sl-name').focus();
}
async function confirmSaveLabel() {
    const name = document.getElementById('sl-name').value.trim();
    const cat = document.getElementById('sl-cat').value.trim();
    if (!name) { toast('請輸入標籤名稱'); return; }
    if (!pendingLabelSpec) { hideModal('savelabel-modal'); return; }
    try {
        const fd = new FormData();
        fd.append('action', 'save_label');
        fd.append('name', name);
        fd.append('category', cat);
        fd.append('tags', document.getElementById('sl-tags').value.trim());
        fd.append('scope', document.getElementById('sl-scope').value);
        fd.append('dept_id', document.getElementById('sl-dept').value || '0');
        fd.append('spec', JSON.stringify(pendingLabelSpec));
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        pendingLabelSpec = null;
        hideModal('savelabel-modal');
        toast('已存入標籤庫：' + name + (cat ? '（' + cat + '）' : ''));
        await loadLabelLibrary();
        if (document.getElementById('libmgr-modal').classList.contains('show')) renderLibMgr();
    } catch (e) { toast('儲存失敗：' + (e.message || '')); }
}
async function deleteCustomLabel(id) {
    if (!confirm('確定刪除這個自訂標籤？（標籤庫是全體共用的）')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete_label'); fd.append('label_id', id);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        loadLabelLibrary();
    } catch (e) { toast('刪除失敗：' + (e.message || '')); }
}

/* 建立文字標籤（可改字規格標籤建立器） */
function openNewLabelModal() {
    document.getElementById('nl-text').value = '';
    document.getElementById('nl-name').value = '';
    document.getElementById('nl-cat').value = document.getElementById('lib-cat-filter').value || '';
    document.getElementById('nl-tags').value = '';
    document.getElementById('nl-scope').value = 'private';
    const nd = document.getElementById('nl-dept');
    nd.innerHTML = MY_DEPTS.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
    nd.style.display = 'none';
    showModal('newlabel-modal');
    document.getElementById('nl-text').focus();
}
async function confirmNewLabel() {
    const text = document.getElementById('nl-text').value.replace(/\s+$/, '');
    if (!text.trim()) { toast('請輸入標籤文字'); return; }
    const spec = {
        kind: document.getElementById('nl-kind').value,
        text: text,
        fontSize: Math.max(10, parseInt(document.getElementById('nl-size').value, 10) || 44),
        align: document.getElementById('nl-align').value
    };
    if (document.getElementById('nl-transparent').checked) spec.bg = 'transparent';
    const name = document.getElementById('nl-name').value.trim() || text.split('\n')[0].substring(0, 30);
    try {
        const fd = new FormData();
        fd.append('action', 'save_label');
        fd.append('name', name);
        fd.append('category', document.getElementById('nl-cat').value.trim());
        fd.append('tags', document.getElementById('nl-tags').value.trim());
        fd.append('scope', document.getElementById('nl-scope').value);
        fd.append('dept_id', document.getElementById('nl-dept').value || '0');
        fd.append('spec', JSON.stringify(spec));
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        hideModal('newlabel-modal');
        toast('已建立標籤：' + name);
        await loadLabelLibrary();
        if (document.getElementById('libmgr-modal').classList.contains('show')) renderLibMgr();
    } catch (e) { toast('建立失敗：' + (e.message || '')); }
}

/* 雙擊群組：
   - 一般群組（或 Alt+雙擊任何群組）→ 進入群組：拆成多選，可個別移動，調整完 Ctrl+G 重新群組
   - 標籤群組 → 就地編輯其中的文字（規格標籤改完外框自動貼合字長） */
function enterGroup(g) {
    // 重組時要還原的自訂屬性：漏掉會讓標註/箭頭/快速標籤在「進入群組→重組」一趟之後失去識別，
    // 連動刪除、角度重算、雙擊改字、外框貼字等行為全部失效
    const props = { labelSpec: g.labelSpec, labelKind: g.labelKind, dimKind: g.dimKind, dimAngleId: g.dimAngleId,
                    isQuickLabel: g.isQuickLabel, merged: g.merged, isArrowGroup: g.isArrowGroup, isFreehandEnds: g.isFreehandEnds };
    g.toActiveSelection();
    const sel = canvas.getActiveObject();
    if (sel) sel._regroupProps = props;   // Ctrl+G 重組時還原標籤屬性
    canvas.requestRenderAll();
    pushState();
    toast('已進入群組（拆成多選）：點空白處取消選取後可個別移動；調整完框選物件按 Ctrl+G 重新群組');
}
/* 雙擊直線/折線/矩形/不規則遮蓋＝直接進入「編輯端點」模式（跟屬性列按鈕同功能，較好找） */
canvas.on('mouse:dblclick', function (opt) {
    const t = opt.target;
    if (!t || currentTool !== 'select' || t.__pointEditing) return;
    if (['line', 'polyline', 'polygon', 'rect'].includes(t.type) && !t.isDimGuide) {
        canvas.setActiveObject(t);
        togglePointEdit();
    }
});
canvas.on('mouse:dblclick', function (opt) {
    const g = opt.target;
    if (!g || g.type !== 'group' || currentTool !== 'select') return;
    if (opt.e.altKey) { enterGroup(g); return; }
    if (g.merged) return;                                        // 合併物件＝單一物件，雙擊不拆
    if (g.labelSpec && g.labelSpec.kind === 'tol') { startTolEdit(g); return; }   // ^公差堆疊：整串重編
    if (!g.labelSpec || g.labelSpec.kind === 'multi') { enterGroup(g); return; }
    const p = canvas.getPointer(opt.e);
    const texts = [];
    g.forEachObject(o => { if (o.type === 'text' || o.type === 'i-text') texts.push(o); });
    if (!texts.length) return;
    let best = null, bd = Infinity;
    texts.forEach(t => {
        const d = fabric.util.qrDecompose(t.calcTransformMatrix());
        const dist = (d.translateX - p.x) ** 2 + (d.translateY - p.y) ** 2;
        if (dist < bd) { bd = dist; best = t; }
    });
    startGroupTextEdit(g, best);
});
/* 一般文字（文字工具）：結束編輯時若含 A^B（如 -0^-0.18）自動轉成上下公差堆疊群組。
   注意：此事件在 fabric exitEditing() 執行到一半時同步觸發，當場移除物件會讓後續
   this.canvas.fire('object:modified') 讀到 undefined 噴例外，所以延後一個 tick 再轉換 */
canvas.on('text:editing:exited', function (opt) {
    const t = opt.target;
    if (!t || t.type !== 'i-text' || t.__groupEditFor || t.dcRole || t.dimKind) return;   // 暫時編輯框/設變列表/標註文字不轉
    if (!TOL_INPUT_RE.test(t.text)) return;
    setTimeout(function () {
        if (restoring || canvas.getObjects().indexOf(t) === -1) return;   // 已被其他流程移除（如編輯中刪除/復原）
        convertToTolGroup(t);
    }, 0);
});
function startGroupTextEdit(group, child, cursorToEnd) {
    const dec = fabric.util.qrDecompose(child.calcTransformMatrix());
    child.visible = false; group.dirty = true;
    canvas.requestRenderAll();
    const tmp = new fabric.IText(child.text, {
        left: dec.translateX, top: dec.translateY, originX: 'center', originY: 'center',
        angle: dec.angle, scaleX: dec.scaleX, scaleY: dec.scaleY,
        fontSize: child.fontSize, fontFamily: child.fontFamily, fontWeight: child.fontWeight,
        fill: child.fill, textAlign: child.textAlign || 'center', backgroundColor: '#fff8d6',
        underline: !!child.underline
    });
    tmp.doubleUnderline = !!child.doubleUnderline;
    tmp.__groupEditFor = group;   // 讓「刪除」知道使用者要刪的是整組，不是這個暫時編輯框
    canvas.add(tmp);
    canvas.setActiveObject(tmp);
    tmp.enterEditing();
    if (cursorToEnd) { tmp.setSelectionStart(tmp.text.length); tmp.setSelectionEnd(tmp.text.length); }
    else tmp.selectAll();
    tmp.on('editing:exited', function () {
        const val = tmp.text;
        try { tmp.abortCursorAnimation(); } catch (e) { /* 游標動畫沒在跑就算了 */ }
        canvas.remove(tmp);
        if (tmp.__deleteGroup) {   // 編輯中按了刪除：連同整組一起刪，不要再把群組加回來
            canvas.remove(group);
            // 角度標註整組連動：刪弧線群組時把同 id 的隱藏輔助線一併刪掉，不留孤兒
            if (group.dimAngleId) canvas.getObjects().slice().forEach(o => { if (o.dimAngleId === group.dimAngleId) canvas.remove(o); });
            canvas.discardActiveObject();
            canvas.requestRenderAll();
            pushState();
            return;
        }
        // 復原/重做把畫布整個換掉了，或群組已被其他流程移除：不能再把舊群組加回去（會生出與歷史不符的幽靈物件）
        if (restoring || canvas.getObjects().indexOf(group) === -1) { canvas.requestRenderAll(); return; }
        // 編輯期間若有改文字樣式（底色/顏色/粗體/字級/底線），要同步回真正的文字，不然編輯結束就跳回舊樣式
        child.set({ fill: tmp.fill, fontWeight: tmp.fontWeight, fontSize: tmp.fontSize, underline: tmp.underline });
        child.doubleUnderline = tmp.doubleUnderline;
        if (tmp.backgroundColor !== '#fff8d6') child.set('backgroundColor', tmp.backgroundColor);   // #fff8d6=編輯中的提示底色，沒被使用者改過就不帶回去
        child.dirty = true;
        child.visible = true;
        finishGroupTextEdit(group, child, val);
    });
}
function finishGroupTextEdit(group, child, val) {
    const center = group.getCenterPoint();
    if (group.labelSpec && group.labelSpec.kind !== 'fabric' && child.specPath) {
        // 規格標籤：改 spec 後整顆重建（外框自動貼合新字長），保留使用者縮放/旋轉
        const spec = JSON.parse(JSON.stringify(group.labelSpec));
        setSpecByPath(spec, child.specPath, val);
        // 先建好並驗證尺寸再替換：重建若出例外或算出 NaN（毒化物件＝殘影/卡死來源），保留原標籤不動
        let ng = null;
        try { ng = makeLabelFromSpec(spec); } catch (e) { console.warn('[EGdraw] 標籤重建例外：', e); }
        if (!ng || !isFinite(ng.width) || !isFinite(ng.height)) {
            toast('標籤重建失敗，已保留原內容（此次修改未套用）');
            canvas.requestRenderAll();
            return;
        }
        const { scaleX, scaleY, angle } = group;
        canvas.remove(group);
        ng.set({ scaleX, scaleY, angle, originX: 'center', originY: 'center' });
        ng.setPositionByOrigin(center, 'center', 'center');
        ng.setCoords();
        canvas.add(ng);
        canvas.setActiveObject(ng);
    } else {
        // 自由組合（fabric）標籤／快速標註群組：改字後重組群組以重算邊界
        child.set('text', val); child.dirty = true;
        const props = { labelSpec: group.labelSpec, labelKind: group.labelKind, isQuickLabel: group.isQuickLabel, dimKind: group.dimKind, dimAngleId: group.dimAngleId };
        const kids = group.getObjects().slice();
        const wasQuickLabel = group.isQuickLabel;
        group.destroy();               // 還原子物件為絕對座標（含群組縮放）
        if (wasQuickLabel) {
            // 快速標籤（文字工具的「標籤」）：邊框自動貼合新字長，中心點不變
            const box = kids.find(k => k.type === 'rect');
            if (box) {
                const pad = labelBoxPadding(child.fontSize);
                box.set({ width: child.width + pad * 2, height: child.height + pad * 2 });
                box.setCoords();
            }
        }
        canvas.remove(group);
        const ng = new fabric.Group(kids);
        Object.assign(ng, props);
        canvas.add(ng);
        canvas.setActiveObject(ng);
    }
    canvas.requestRenderAll();
    pushState();
}

/* 複製出來的物件若帶著角度標註的 dimAngleId，要換發新 id：
   兩份標示共用同一 id 會互相干擾（重算只刪到一份、刪一份會連動刪掉另一份） */
function reissueDimIds(root) {
    const map = {};
    const visit = o => {
        if (o.dimAngleId) {
            map[o.dimAngleId] = map[o.dimAngleId] || ('da' + Date.now() + '_' + Math.floor(Math.random() * 100000));
            o.dimAngleId = map[o.dimAngleId];
        }
        if (o.getObjects) o.getObjects().forEach(visit);
    };
    visit(root);
}
/* Figma 式快速複製：Alt+拖曳 = 原地留一份、拖走一份（Ctrl+D 亦可）。
   一定要「真的開始拖曳」才複製——Alt+單擊/雙擊（進入群組手勢）若立即複製，
   會原地疊出看不見的重疊複本，就是「刪了又還有一個」的殘留來源。 */
let altClonePending = null;   // { src, left, top }
canvas.on('mouse:down', function (opt) {
    if (currentTool !== 'select' || !opt.e.altKey || !opt.target || opt.target === artboard) return;
    altClonePending = { src: opt.target, left: opt.target.left, top: opt.target.top };
});
canvas.on('object:moving', function (e) {
    if (!altClonePending || !e || e.target !== altClonePending.src) return;
    const { src, left, top } = altClonePending;
    altClonePending = null;
    src.clone(function (cl) {
        cl.set({ left, top });   // 複製品留在原位（src 已經被拖走了）
        reissueDimIds(cl);
        canvas.add(cl);
        cl.moveTo(canvas.getObjects().indexOf(src));  // 複製品墊在原件下方，使用者拖走的是原件
        pushState();
    }, SNAP_PROPS);
});
canvas.on('mouse:up', function () { altClonePending = null; });

/* ── 圖片載入：檔案 / 拖放 / 剪貼簿 ── */
function openImageFiles() { document.getElementById('file-input').click(); }
document.getElementById('file-input').addEventListener('change', function () {
    loadFiles(Array.from(this.files)); this.value = '';
});

let addOffset = 0;
function loadFiles(files) {
    const imgs = files.filter(f => f.type.startsWith('image/'));
    if (!imgs.length) { toast('沒有可用的圖片檔'); return; }
    imgs.forEach((f, i) => {
        const r = new FileReader();
        r.onload = e => addImageFromURL(e.target.result, i);
        r.readAsDataURL(f);
    });
}
function addImageFromURL(url, cascade) {
    fabric.Image.fromURL(url, function (img) {
        // 圖比畫布大很多 → 自動撐大畫布（解決小畫家貼圖被裁掉的問題）
        if (img.width > artW || img.height > artH) {
            setArtboardSize(Math.max(artW, img.width + 40), Math.max(artH, img.height + 40));
            zoomFit();
        }
        const off = (cascade || 0) * 30 + (addOffset % 5) * 24;
        addOffset++;
        img.set({
            left: artboard.left + (artW - img.width * (img.scaleX || 1)) / 2 + off,
            top: artboard.top + (artH - img.height * (img.scaleY || 1)) / 2 + off
        });
        canvas.add(img);
        canvas.setActiveObject(img);
        setTool('select');
        canvas.requestRenderAll();
        pushState();
        toast('已加入圖片（' + img.width + '×' + img.height + '），可拖角縮放對齊');
    }, { crossOrigin: 'anonymous' });
}

/* 拖放開圖 */
['dragenter', 'dragover'].forEach(ev => document.addEventListener(ev, e => {
    e.preventDefault();
    if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files'))
        document.getElementById('drop-hint').classList.add('show');
}));
['dragleave', 'drop'].forEach(ev => document.addEventListener(ev, e => {
    e.preventDefault();
    if (ev === 'drop' || e.target === document.documentElement || !e.relatedTarget)
        document.getElementById('drop-hint').classList.remove('show');
}));
document.addEventListener('drop', e => {
    e.preventDefault();
    if (e.dataTransfer && e.dataTransfer.files.length) loadFiles(Array.from(e.dataTransfer.files));
});

/* 剪貼簿貼上：優先系統圖片（小畫家）→ 內部物件複製 → 跨視窗剪貼簿 */
let internalClip = null, internalClipTs = 0;
document.addEventListener('paste', function (e) {
    // 焦點在輸入欄位（例如匯出檔名）時，貼上交給瀏覽器，不動畫布
    const tag = (document.activeElement || {}).tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    const items = (e.clipboardData || {}).items || [];
    for (const it of items) {
        if (it.type && it.type.startsWith('image/')) {
            const blob = it.getAsFile();
            const r = new FileReader();
            r.onload = ev => addImageFromURL(ev.target.result, 0);
            r.readAsDataURL(blob);
            e.preventDefault();
            return;
        }
    }
    if (isTextEditing()) return; // 文字編輯中的純文字貼上交給 IText
    pasteInternalOrCross();
});
async function pasteFromButton() {
    // 按鈕觸發：嘗試 async clipboard（需 HTTPS/localhost），失敗則提示用 Ctrl+V
    if (navigator.clipboard && navigator.clipboard.read) {
        try {
            const items = await navigator.clipboard.read();
            for (const item of items) {
                const t = item.types.find(t => t.startsWith('image/'));
                if (t) {
                    const blob = await item.getType(t);
                    const r = new FileReader();
                    r.onload = ev => addImageFromURL(ev.target.result, 0);
                    r.readAsDataURL(blob);
                    return;
                }
            }
        } catch (err) { /* fallthrough */ }
    }
    if (!pasteInternalOrCross()) toast('請直接按 Ctrl+V 貼上（瀏覽器限制，按鈕無法讀取系統剪貼簿）');
}
const CLIP_TTL_MS = 10 * 60 * 1000;   // 跨視窗剪貼簿只保留 10 分鐘：過期自動失效，避免很久以前複製的東西一直被 Ctrl+V 貼回來
function pasteInternalOrCross() {
    let cross = null;
    try { cross = JSON.parse(localStorage.getItem(CLIP_KEY) || 'null'); } catch (e) {}
    if (cross && (!cross.ts || Date.now() - cross.ts > CLIP_TTL_MS)) {
        cross = null;
        try { localStorage.removeItem(CLIP_KEY); } catch (e) {}
    }
    const crossIsNewer = cross && (!internalClip || cross.ts > internalClipTs);
    if (crossIsNewer && cross.objs && cross.objs.length) {
        // 跨視窗貼上：優先還原成可編輯的向量物件（保留顏色/線型/文字內容等），不是扁平化圖片
        fabric.util.enlivenObjects(cross.objs, function (objs) {
            objs.forEach(o => { o.set({ left: (o.left || 0) + 20, top: (o.top || 0) + 20 }); reissueDimIds(o); canvas.add(o); o.setCoords(); });
            if (objs.length > 1) canvas.setActiveObject(new fabric.ActiveSelection(objs, { canvas }));
            else if (objs[0]) canvas.setActiveObject(objs[0]);
            canvas.requestRenderAll();
            pushState();
        });
        return true;
    }
    if (crossIsNewer && cross.dataURL) {
        addImageFromURL(cross.dataURL, 0);
        return true;
    }
    if (internalClip) {
        internalClip.clone(function (cl) {
            cl.set({ left: cl.left + 20, top: cl.top + 20 });
            reissueDimIds(cl);
            if (cl.type === 'activeSelection') {
                cl.canvas = canvas;
                cl.forEachObject(o => canvas.add(o));
                cl.setCoords();
            } else canvas.add(cl);
            canvas.setActiveObject(cl);
            canvas.requestRenderAll();
            pushState();
        }, SNAP_PROPS);
        return true;
    }
    return false;
}

/* Ctrl+C：內部複製（同視窗貼上用，保留完整 Fabric 物件）＋ 寫入跨視窗剪貼簿（把選取內容序列化成向量
   JSON，讓另一個視窗貼上時能重建成可編輯物件，不是扁平化圖片；序列化太大寫不進 localStorage 才退回
   扁平化 JPEG 預覽圖，兩者都失敗就明講「太大複製不過去」，不要默默失敗讓使用者以為有複製到） */
function serializeSelectionForClip(obj, cb) {
    obj.clone(function (cl) {
        // 單一物件：clone 本身就是絕對座標，直接序列化，不必經過畫布
        if (cl.type !== 'activeSelection') { cb([cl.toObject(SNAP_PROPS)]); return; }
        // 多選：子物件座標是相對於選取框的，得暫時放回畫布還原成絕對座標再序列化；
        // 用 try/finally 保證一定移除，途中出錯才不會留下一份「殘影」在畫布上
        const added = [];
        try {
            cl.canvas = canvas;
            cl.forEachObject(o => { canvas.add(o); added.push(o); });
            cl.setCoords();
            cb(added.map(o => o.toObject(SNAP_PROPS)));
        } finally {
            added.forEach(o => canvas.remove(o));
            canvas.requestRenderAll();
        }
    }, SNAP_PROPS);
}
function copySelection() {
    const obj = canvas.getActiveObject();
    if (!obj) return false;
    obj.clone(function (cl) { internalClip = cl; internalClipTs = Date.now(); }, SNAP_PROPS);
    const ts = Date.now();
    serializeSelectionForClip(obj, function (arr) {
        try {
            localStorage.setItem(CLIP_KEY, JSON.stringify({ ts, objs: arr }));
            return;
        } catch (e) { /* 向量 JSON 太大，往下退回扁平化圖片 */ }
        try {
            const url = exportSelectionDataURL(obj, 'jpeg', 1);
            localStorage.setItem(CLIP_KEY, JSON.stringify({ ts, dataURL: url }));
        } catch (e2) {
            toast('已複製（本視窗內可貼上），但內容過大無法同步到其他批圖視窗');
        }
    });
    return true;
}
function copySelectionCrossWindow() {
    if (!canvas.getActiveObject()) { toast('請先選取要複製的物件'); return; }
    // 注意：系統剪貼簿（小畫家複製的東西）優先權在 Ctrl+V；跨窗內容請在另一視窗用 Ctrl+Shift+V 或「貼上」按鈕
    if (copySelection()) toast('已複製。到另一個批圖視窗按 Ctrl+Shift+V（或頂列「貼上」按鈕）貼上');
}
function duplicateSelection() {
    const obj = canvas.getActiveObject();
    if (!obj) return;
    obj.clone(function (cl) {
        cl.set({ left: cl.left + 20, top: cl.top + 20 });
        reissueDimIds(cl);
        if (cl.type === 'activeSelection') {
            cl.canvas = canvas;
            cl.forEachObject(o => canvas.add(o));
            cl.setCoords();
        } else canvas.add(cl);
        canvas.setActiveObject(cl);
        canvas.requestRenderAll();
        pushState();
    }, SNAP_PROPS);
}

/* ── 框選複製（把區域合成影像變新圖塊；白底視為透明，貼上時不蓋住下面的東西） ── */
function doCropCopy(x, y, w, h) {
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    const el = exportRegionCanvasEl(x, y, w, h, 1);
    if (document.getElementById('p-crop-transparent').checked) whiteToTransparent(el);
    const url = el.toDataURL('image/png');
    // 不再順手寫入跨視窗剪貼簿：同步寫大字串會卡 UI、常撞 5MB 配額，要跨窗請用「複製選取→他窗」
    fabric.Image.fromURL(url, function (img) {
        img.set({ left: x + 24, top: y + 24 });
        canvas.add(img);
        canvas.setActiveObject(img);
        setTool('select');
        canvas.requestRenderAll();
        pushState();
        toast('已複製框選範圍成新圖塊（要複製到另一個批圖視窗：選取後用「複製選取→他窗」）');
    });
}

/* ── 框選搬移（小畫家式，所見即所得）：跟框選複製一樣把框內看得到的東西（底圖＋畫上去的物件）
   一起烙進切塊圖搬走——底圖從範圍真正挖空、完整落在框內的物件烙進切塊後從原處移除；
   只壓到框線一部分的物件無法切一半，保持原樣不動（也不會烙進切塊）。
   切下來的內容白底視為透明，拖到新位置不會蓋住下面的東西。
   用完停留在此工具可連續框選，按 Esc 或切換其他工具才離開 ── */
function doCropMove(x, y, w, h) {
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    const bgObjs = backgroundImagesInRect(x, y, w, h);
    const moved = contentObjectsInRect(x, y, w, h);   // 完整落在框內：烙進切塊並從原處移除
    const others = canvas.getObjects().filter(o => o.id !== '__artboard' && bgObjs.indexOf(o) === -1 && moved.indexOf(o) === -1);
    const prevVis = others.map(o => o.visible !== false);
    others.forEach(o => { o.visible = false; });   // 只藏「部分壓框」與框外的物件；框內物件留著＝跟複製一樣烙進圖
    const el = exportRegionCanvasEl(x, y, w, h, 1);
    others.forEach((o, i) => { o.visible = prevVis[i]; });
    moved.forEach(o => canvas.remove(o));   // 已烙進切塊，原件移除（Ctrl+Z 可整批復原）
    if (document.getElementById('p-crop-transparent').checked) whiteToTransparent(el);
    const url = el.toDataURL('image/png');
    const fillColor = toHex(artboard.fill) || '#ffffff';
    let skipped = 0;
    bgObjs.forEach(o => {
        if (punchHoleInImage(o, x, y, w, h, fillColor)) return;
        skipped++;
        // 無法真正挖空（旋轉/已裁切）的底圖，退回原本「疊一層遮板」做法，只蓋住那張圖被選到的範圍
        const br = o.getBoundingRect(true, true);
        const ix = Math.max(x, br.left), iy = Math.max(y, br.top);
        const ix2 = Math.min(x + w, br.left + br.width), iy2 = Math.min(y + h, br.top + br.height);
        if (ix2 > ix && iy2 > iy) canvas.add(new fabric.Rect({ left: ix, top: iy, width: ix2 - ix, height: iy2 - iy, fill: fillColor }));
    });
    canvas.requestRenderAll();
    fabric.Image.fromURL(url, function (img) {
        img.set({ left: x, top: y });
        canvas.add(img);
        canvas.setActiveObject(img);
        canvas.requestRenderAll();
        pushState();
        toast((skipped
            ? '已切下框選範圍；部分底圖因旋轉/已裁切無法真正挖空，改用底色覆蓋，直接拖到新位置'
            : '已切下框選範圍（原底圖已真正挖空），直接拖到新位置；不滿意可 Ctrl+Z 復原')
            + (moved.length ? '；框內 ' + moved.length + ' 個物件已一起切進圖塊' : ''));
    });
}

/* ── 匯出核心：以 identity viewport 座標裁切，確保所見即所得 ── */
function exportRegionDataURL(x, y, w, h, format, mult) {
    const vpt = canvas.viewportTransform.slice();
    const active = canvas.getActiveObject();
    canvas.discardActiveObject();
    canvas.setViewportTransform([1, 0, 0, 1, 0, 0]);
    const prevShadow = artboard.shadow;
    artboard.shadow = null;
    canvas.requestRenderAll();
    const url = canvas.toDataURL({
        format: format || 'png',
        quality: 0.92,
        left: x, top: y, width: w, height: h,
        multiplier: mult || 1,
        enableRetinaScaling: false
    });
    artboard.shadow = prevShadow;
    canvas.setViewportTransform(vpt);
    if (active) canvas.setActiveObject(active);
    canvas.requestRenderAll();
    return url;
}
function exportSelectionDataURL(obj, format, mult) {
    const b = obj.getBoundingRect(true, true);
    return exportRegionDataURL(b.left, b.top, b.width, b.height, format, mult);
}
/* 同 exportRegionDataURL，但回傳實際 <canvas> 供進一步像素處理（白底轉透明用） */
function exportRegionCanvasEl(x, y, w, h, mult) {
    const vpt = canvas.viewportTransform.slice();
    const active = canvas.getActiveObject();
    canvas.discardActiveObject();
    canvas.setViewportTransform([1, 0, 0, 1, 0, 0]);
    const prevShadow = artboard.shadow;
    artboard.shadow = null;
    canvas.requestRenderAll();
    const el = canvas.toCanvasElement(mult || 1, { left: x, top: y, width: w, height: h });
    artboard.shadow = prevShadow;
    canvas.setViewportTransform(vpt);
    if (active) canvas.setActiveObject(active);
    canvas.requestRenderAll();
    return el;
}
/* 選取後透明選擇：白色（含接近白）像素視為透明，搬移/貼上時白底不會蓋住下面的東西 */
function whiteToTransparent(canvasEl, threshold) {
    threshold = threshold || 245;
    const ctx = canvasEl.getContext('2d');
    const id = ctx.getImageData(0, 0, canvasEl.width, canvasEl.height);
    const d = id.data;
    for (let i = 0; i < d.length; i += 4) {
        if (d[i] >= threshold && d[i + 1] >= threshold && d[i + 2] >= threshold) d[i + 3] = 0;
    }
    ctx.putImageData(id, 0, 0);
}
/* 完整落在範圍內的「畫上去的物件」（標籤/文字/球標/形狀…）：框選搬移時跟著切塊一起選取移動。
   排除底圖（走挖空）、鎖定/隱藏物件與標註輔助線 */
function contentObjectsInRect(x, y, w, h) {
    return canvas.getObjects().filter(o => {
        if (o.id === '__artboard' || o.locked || o.visible === false || o.isDimGuide) return false;
        if (o.type === 'image' && !o.labelSpec && !o.labelKind) return false;   // 底圖另外走挖空流程
        const br = o.getBoundingRect(true, true);
        return br.left >= x - 2 && br.top >= y - 2 && br.left + br.width <= x + w + 2 && br.top + br.height <= y + h + 2;
    });
}
function pointInPoly(px, py, pts) {
    let inside = false;
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const xi = pts[i].x, yi = pts[i].y, xj = pts[j].x, yj = pts[j].y;
        if ((yi > py) !== (yj > py) && px < (xj - xi) * (py - yi) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
}
/* 框選範圍內、屬於「底圖」的 fabric.Image（排除標籤/球標等其他物件與畫布本身） */
function backgroundImagesInRect(x, y, w, h) {
    return canvas.getObjects().filter(o => {
        if (o.type !== 'image' || o.id === '__artboard' || o.labelSpec || o.labelKind) return false;
        const br = o.getBoundingRect(true, true);
        return !(br.left + br.width <= x || br.left >= x + w || br.top + br.height <= y || br.top >= y + h);
    });
}
/* 把底圖在選取範圍內的部分真正挖空（燒進圖片像素填色），而不是疊一層遮板；
   有旋轉或已裁切(cropX/cropY)的底圖座標換算太複雜且容易算錯，跳過改用底色覆蓋 */
function punchHoleInImage(obj, x, y, w, h, fillColor, polyPoints) {
    const ang = ((obj.angle || 0) % 360 + 360) % 360;
    if (ang > 0.01 && ang < 359.99) return false;
    if (obj.cropX || obj.cropY) return false;
    const br = obj.getBoundingRect(true, true);
    const ix = Math.max(x, br.left), iy = Math.max(y, br.top);
    const ix2 = Math.min(x + w, br.left + br.width), iy2 = Math.min(y + h, br.top + br.height);
    if (ix2 <= ix || iy2 <= iy) return false;
    const natW = obj.width, natH = obj.height;
    if (!natW || !natH || !br.width || !br.height) return false;
    const sx = natW / br.width, sy = natH / br.height;
    const off = document.createElement('canvas');
    off.width = natW; off.height = natH;
    const ctx = off.getContext('2d');
    ctx.drawImage(obj._element, 0, 0, natW, natH);
    ctx.fillStyle = fillColor;
    if (polyPoints && polyPoints.length > 2) {
        // 不規則挖空：把場景座標的套索點換算成這張圖自己的像素座標，直接照形狀填色（canvas fill 本來就能畫任意多邊形）
        ctx.beginPath();
        polyPoints.forEach((pt, i) => {
            const lx = (pt.x - br.left) * sx, ly = (pt.y - br.top) * sy;
            if (i === 0) ctx.moveTo(lx, ly); else ctx.lineTo(lx, ly);
        });
        ctx.closePath();
        ctx.fill();
    } else {
        const lx = (ix - br.left) * sx, ly = (iy - br.top) * sy;
        const lw = (ix2 - ix) * sx, lh = (iy2 - iy) * sy;
        ctx.fillRect(lx, ly, lw, lh);
    }
    obj.setElement(off);
    obj.dirty = true;
    // 重要：element 若一直是 canvas 元素，之後「每一次」undo 快照的 getSrc() 都會對整張底圖重做
    // toDataURL PNG 編碼（數千像素圖一次數百ms～數秒），是「用過框選搬移後開始狂卡」的主因。
    // 這裡一次性編碼成 dataURL 換回 <img>，之後快照直接取 src 字串，零成本。
    const punchedUrl = off.toDataURL('image/png');
    const imgEl = new Image();
    imgEl.onload = function () {
        if (canvas.getObjects().indexOf(obj) === -1) return;   // 換圖途中被刪掉就算了
        obj.setElement(imgEl);
        obj.dirty = true;
        canvas.requestRenderAll();
    };
    imgEl.src = punchedUrl;
    return true;
}
/* 框選搬移（不規則套索版）：座套索式，只把底圖依套索形狀真正挖空，其他物件不受影響，可連續使用 */
function polyBBox(points) {
    const xs = points.map(p => p.x), ys = points.map(p => p.y);
    const x = Math.min(...xs), y = Math.min(...ys);
    return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y };
}
function clipCanvasToPolygon(canvasEl, points, offsetX, offsetY) {
    const ctx = canvasEl.getContext('2d');
    ctx.save();
    ctx.globalCompositeOperation = 'destination-in';
    ctx.beginPath();
    points.forEach((pt, i) => {
        const lx = pt.x - offsetX, ly = pt.y - offsetY;
        if (i === 0) ctx.moveTo(lx, ly); else ctx.lineTo(lx, ly);
    });
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}
function doCropMoveLasso(points) {
    const b = polyBBox(points);
    if (b.w < 3 || b.h < 3) return;
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    const bgObjs = backgroundImagesInRect(b.x, b.y, b.w, b.h);
    // 四個角都在套索形狀內＝完整落在框內的物件：烙進切塊並從原處移除（跟複製一樣所見即所得）
    const moved = contentObjectsInRect(b.x, b.y, b.w, b.h).filter(o => {
        const br = o.getBoundingRect(true, true);
        return pointInPoly(br.left, br.top, points) && pointInPoly(br.left + br.width, br.top, points)
            && pointInPoly(br.left, br.top + br.height, points) && pointInPoly(br.left + br.width, br.top + br.height, points);
    });
    const others = canvas.getObjects().filter(o => o.id !== '__artboard' && bgObjs.indexOf(o) === -1 && moved.indexOf(o) === -1);
    const prevVis = others.map(o => o.visible !== false);
    others.forEach(o => { o.visible = false; });
    const el = exportRegionCanvasEl(b.x, b.y, b.w, b.h, 1);
    others.forEach((o, i) => { o.visible = prevVis[i]; });
    moved.forEach(o => canvas.remove(o));   // 已烙進切塊，原件移除（Ctrl+Z 可整批復原）
    clipCanvasToPolygon(el, points, b.x, b.y);   // 只留套索範圍內的內容，範圍外變透明
    if (document.getElementById('p-crop-transparent').checked) whiteToTransparent(el);
    const url = el.toDataURL('image/png');
    const fillColor = toHex(artboard.fill) || '#ffffff';
    let anySkipped = false;
    bgObjs.forEach(o => { if (!punchHoleInImage(o, b.x, b.y, b.w, b.h, fillColor, points)) anySkipped = true; });
    if (anySkipped) canvas.add(new fabric.Polygon(points.slice(), { fill: fillColor }));   // 跟矩形版遮板一樣保持可選取，才能被使用者移動/刪除
    canvas.requestRenderAll();
    fabric.Image.fromURL(url, function (img) {
        img.set({ left: b.x, top: b.y });
        canvas.add(img);
        canvas.setActiveObject(img);
        canvas.requestRenderAll();
        pushState();
        toast((anySkipped
            ? '已切下不規則框選範圍；部分底圖因旋轉/已裁切無法真正挖空，改用色塊覆蓋，直接拖到新位置'
            : '已切下不規則框選範圍（原底圖已真正挖空），直接拖到新位置；不滿意可 Ctrl+Z 復原')
            + (moved.length ? '；框內 ' + moved.length + ' 個物件已一起切進圖塊' : ''));
    });
}

/* ── 匯出 / 列印 / 另存 ── */
function defaultFileName() {
    const d = new Date(), p = n => String(n).padStart(2, '0');
    return '批圖_' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '_' + p(d.getHours()) + p(d.getMinutes());
}
let lastExportName = '';   // 視窗未關前記住上次輸入的匯出檔名，第二次匯出不再跳回預設
function openExportModal() {
    document.getElementById('ex-name').value = lastExportName || defaultFileName();
    const hint = document.getElementById('ex-fs-hint');
    if (window.showSaveFilePicker) {
        hint.innerHTML = '「另存圖片」會開啟儲存位置選擇視窗（會記住上次資料夾）。';
    } else {
        hint.innerHTML = '目前以 HTTP 連線，瀏覽器只允許存到「下載」資料夾。<br>若要每次選擇資料夾：瀏覽器設定 → 下載 → 開啟「<b>每次下載前先詢問儲存位置</b>」，即可存到各自習慣的資料夾。';
    }
    showModal('export-modal');
}
function buildExportURL() {
    const range = document.getElementById('ex-range').value;
    const format = document.getElementById('ex-format').value;
    const mult = parseFloat(document.getElementById('ex-mult').value) || 1;
    if (range === 'selection') {
        const obj = canvas.getActiveObject();
        if (!obj) { toast('沒有選取物件，改匯出整個畫布'); }
        else return exportSelectionDataURL(obj, format, mult);
    }
    return exportRegionDataURL(artboard.left, artboard.top, artW, artH, format, mult);
}
function dataURLtoBlob(u) {
    const [head, body] = u.split(',');
    const mime = head.match(/:(.*?);/)[1];
    const bin = atob(body);
    const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return new Blob([arr], { type: mime });
}
async function doSave() {
    const format = document.getElementById('ex-format').value;
    const ext = format === 'jpeg' ? '.jpg' : '.png';
    lastExportName = document.getElementById('ex-name').value.trim();
    const name = (lastExportName || defaultFileName()) + ext;
    const url = buildExportURL();
    const blob = dataURLtoBlob(url);

    if (window.showSaveFilePicker) {
        try {
            const opts = {
                suggestedName: name,
                types: [{ description: format.toUpperCase(), accept: { [blob.type]: [ext] } }]
            };
            const dir = await loadDirHandle();
            if (dir) opts.startIn = dir;
            const fh = await window.showSaveFilePicker(opts);
            const w = await fh.createWritable();
            await w.write(blob); await w.close();
            hideModal('export-modal');
            toast('已儲存：' + fh.name);
            return;
        } catch (e) {
            if (e && e.name === 'AbortError') return; // 使用者取消
            /* 失敗改走下載 fallback */
        }
    }
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    hideModal('export-modal');
    toast('已送出下載：' + name);
}
function doPrint() {
    const url = buildExportURL();
    const w = window.open('', '_blank');
    if (!w) { toast('列印視窗被瀏覽器攔截，請允許彈出視窗'); return; }
    w.document.write('<!DOCTYPE html><html><head><title>列印 - 批圖</title>' +
        '<style>html,body{margin:0;padding:0;}img{max-width:100%;}@media print{img{width:100%;}}</style>' +
        '</head><body><img src="' + url + '" onload="setTimeout(function(){window.focus();window.print();},150)"></body></html>');
    w.document.close();
    hideModal('export-modal');
}

/* ── 料號附件：儲存（壓平PNG＋工作檔）與開啟工作檔 ── */
function openPartModal() {
    document.getElementById('pf-name').value = defaultFileName();
    document.getElementById('pf-scope').value = 'dept';
    pfShareSelected = new Set();
    document.getElementById('pf-share-q').value = '';
    const pd = document.getElementById('pf-dept');
    pd.innerHTML = MY_DEPTS.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
    if (MY_MAIN_DEPT_ID) pd.value = String(MY_MAIN_DEPT_ID);   // 預設主要職務部門
    pfOnScopeChange();
    showModal('partfile-modal');
    document.getElementById('pf-q').focus();
}
/* 範圍切換：部門→顯示部門下拉；指定人員→顯示搜尋+勾選名單（延遲載入使用者清單） */
let pfAllUsers = null, pfShareSelected = new Set();
function pfOnScopeChange() {
    const scope = document.getElementById('pf-scope').value;
    document.getElementById('pf-dept').style.display = (scope === 'dept') ? '' : 'none';
    document.getElementById('pf-share-box').style.display = (scope === 'custom') ? '' : 'none';
    if (scope === 'custom' && !pfAllUsers) loadPfShareUsers();
}
async function loadPfShareUsers() {
    document.getElementById('pf-share-list').innerHTML = '載入中…';
    try {
        const fd = new FormData(); fd.append('action', 'list_users_for_share');
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        pfAllUsers = res.users;
        pfRenderShareUsers();
    } catch (e) { document.getElementById('pf-share-list').innerHTML = '<span style="color:#ff8a80;">載入失敗：' + escHtml(e.message || '') + '</span>'; }
}
function pfRenderShareUsers() {
    if (!pfAllUsers) return;
    const q = document.getElementById('pf-share-q').value.trim().toLowerCase();
    const list = q ? pfAllUsers.filter(u => (u.user_cname || '').toLowerCase().includes(q)) : pfAllUsers;
    const box = document.getElementById('pf-share-list');
    box.innerHTML = list.length ? list.map(u => {
        const dp = u.dept_name ? ('<span style="color:#8b949e;">（' + escHtml(u.dept_name) + (u.pos_name ? ' ' + escHtml(u.pos_name) : '') + '）</span>') : '';
        return '<label style="display:flex;align-items:center;gap:5px;width:100%;margin:3px 0;font-size:12.5px;cursor:pointer;">' +
            '<input type="checkbox" data-uid="' + u.id + '"' + (pfShareSelected.has(Number(u.id)) ? ' checked' : '') +
            ' onchange="this.checked ? pfShareSelected.add(' + u.id + ') : pfShareSelected.delete(' + u.id + ')">' +
            escHtml(u.user_cname || ('#' + u.id)) + dp + '</label>';
    }).join('')
        : '<span style="color:#8b949e;font-size:12px;">查無符合的人員（可能是對方沒有批圖編輯器使用權）</span>';
}
async function pfSearch() {
    const q = document.getElementById('pf-q').value.trim();
    if (!q) { toast('請輸入料號或圖號關鍵字'); return; }
    try {
        const fd = new FormData();
        fd.append('action', 'part_search'); fd.append('q', q);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        const sel = document.getElementById('pf-part');
        sel.innerHTML = res.parts.length
            ? res.parts.map(p => '<option value="' + p.d_id + '">' + escHtml(p.D_Setting_Id + (p.Drawing_No ? '｜' + p.Drawing_No : '')) + '</option>').join('')
            : '<option value="">查無符合料號</option>';
        pfLoadWorkfiles();
    } catch (e) { toast('搜尋失敗：' + (e.message || '')); }
}
const PF_SCOPE_LABEL = { private: '私人', dept: '部門共用', custom: '指定人員', company: '公司共用（舊資料）' };
async function pfLoadWorkfiles() {
    const d = document.getElementById('pf-part').value;
    const box = document.getElementById('pf-works-list');
    if (!d) { box.innerHTML = '選料號後自動列出'; return; }
    box.innerHTML = '載入中…';
    try {
        const fd = new FormData();
        fd.append('action', 'list_workfiles'); fd.append('d_id', d);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        box.innerHTML = res.works.length ? res.works.map(w =>
            '<div style="display:flex;align-items:center;gap:6px;padding:4px 2px;border-bottom:1px solid #333;">' +
            '<span style="flex:1;">' + escHtml((w.original_name || '').replace('.egwork.json', '')) + '｜' + escHtml(w.uploaded_at || '') + '｜' + escHtml(w.uploaded_by || '') +
            '　<b style="color:#6fc3ff;">' + (PF_SCOPE_LABEL[w.scope] || w.scope) + '</b>' + (w.is_latest ? ' <b style="color:#7ed957;">（最新）</b>' : '') + '</span>' +
            '<button class="tb-btn" onclick="pfOpenWork(' + w.id + ')" title="開啟"><i class="fa fa-folder-open"></i></button>' +
            (w.can_delete ? '<button class="tb-btn" style="color:#ff8a80;" onclick="pfDeleteWork(' + w.id + ')" title="刪除"><i class="fa fa-trash"></i></button>' : '') +
            '</div>').join('')
            : '<span style="color:#8b949e;">此料號尚無你看得到的批圖工作檔</span>';
    } catch (e) { box.innerHTML = '載入失敗'; toast('工作檔列表載入失敗：' + (e.message || '')); }
}
async function pfSave() {
    const d = document.getElementById('pf-part').value;
    if (!d) { toast('請先搜尋並選擇料號'); return; }
    const name = document.getElementById('pf-name').value.trim() || defaultFileName();
    const scope = document.getElementById('pf-scope').value;
    if (scope === 'custom' && !pfShareSelected.size) { toast('請至少勾選一位要分享的人員，或改選別的範圍'); return; }
    try {
        toast('儲存中…');
        const png = exportRegionDataURL(artboard.left, artboard.top, artW, artH, 'png', 2);
        const work = JSON.stringify(canvas.toJSON(SNAP_PROPS));
        const fd = new FormData();
        fd.append('action', 'save_workfile');
        fd.append('d_id', d);
        fd.append('name', name);
        fd.append('png', png);
        fd.append('work', work);
        fd.append('scope', scope);
        fd.append('dept_id', document.getElementById('pf-dept').value || '0');
        fd.append('share_user_ids', JSON.stringify(Array.from(pfShareSelected)));
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        toast('已存入料號附件：壓平圖＋工作檔（底圖抽離 ' + (res.extracted || 0) + ' 張）' +
            (res.auto_removed ? '，並自動清掉 ' + res.auto_removed + ' 份超過保留上限的舊工作檔' : ''));
        pfLoadWorkfiles();
    } catch (e) { toast('儲存失敗：' + (e.message || '')); }
}
async function pfOpenWork(wid) {
    if (!confirm('開啟工作檔會取代目前畫布內容（未儲存的變更會消失），確定？')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'load_workfile'); fd.append('id', wid);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        restoreState(res.work);
        undoStack = [res.work];
        redoStack = [];
        hideModal('partfile-modal');
        setTimeout(zoomFit, 200);
        toast('已開啟工作檔：' + (res.name || '') + '（所有標籤/文字仍可編輯）');
    } catch (e) { toast('開啟失敗：' + (e.message || '')); }
}
async function pfDeleteWork(wid) {
    if (!confirm('確定刪除這份批圖工作檔？刪除後無法復原。')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete_workfile'); fd.append('id', wid);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        toast('已刪除工作檔');
        pfLoadWorkfiles();
    } catch (e) { toast('刪除失敗：' + (e.message || '')); }
}

/* 預設資料夾 handle（IndexedDB，僅 HTTPS/localhost 可用；HTTP 下自動停用） */
function idb() {
    return new Promise((res, rej) => {
        const rq = indexedDB.open(DIRDB, 1);
        rq.onupgradeneeded = () => rq.result.createObjectStore('handles');
        rq.onsuccess = () => res(rq.result);
        rq.onerror = () => rej(rq.error);
    });
}
async function loadDirHandle() {
    if (!window.showDirectoryPicker) return null;
    try {
        const db = await idb();
        return await new Promise((res) => {
            const tx = db.transaction('handles', 'readonly').objectStore('handles').get('dir_' + USER_ID);
            tx.onsuccess = () => res(tx.result || null);
            tx.onerror = () => res(null);
        });
    } catch (e) { return null; }
}

/* ── 物件屬性列 ── */
function isTextEditing() {
    const o = canvas.getActiveObject();
    return !!(o && o.isEditing);
}
/* 顏色/粗細列只對「真的有邊框/填色可調」的物件（含群組、多選內含的子物件）有意義；圖片/文字/群組整體本身不算 */
function isStrokeable(o) {
    if (!o) return false;
    if ((o.type === 'group' || o.type === 'activeSelection') && o.getObjects) return o.getObjects().some(isStrokeable);
    return ['rect', 'ellipse', 'line', 'path', 'polygon', 'polyline'].includes(o.type);
}
function refreshPropbar() {
    const obj = canvas.getActiveObject();
    const sec = document.getElementById('sec-object');
    sec.classList.toggle('show', !!obj);
    const st = document.getElementById('st-sel');
    // 選取時上方顏色/粗細列改依「選到的物件類型」決定要不要出現，不是只看目前工具
    if (currentTool === 'select') {
        document.getElementById('sec-stroke').classList.toggle('show', isStrokeable(obj));
        // 選到直線/箭頭時顯示「端點」下拉並回填目前模式，改選＝就地互換（見 p-line-ends change 監聽）
        const canEnds = !!obj && isLineLike(obj) && !obj.dimKind && !obj.isDimGuide;
        document.getElementById('wrap-line-ends').style.display = canEnds ? '' : 'none';
        if (canEnds) {
            document.getElementById('p-line-ends').value = (obj.type === 'line') ? 'none'
                : (obj.getObjects().filter(c => c.type === 'triangle').length >= 2 ? 'both' : 'end');
        }
    }
    if (!obj) { st.textContent = '未選取'; return; }
    const b = obj.getBoundingRect(true, true);
    st.innerHTML = '選取 <b>' + (obj.type === 'activeSelection' ? obj.getObjects().length + ' 個物件' : objTypeName(obj)) +
        '</b>（' + Math.round(b.width) + '×' + Math.round(b.height) + '）';
    document.getElementById('p-scale').value = Math.round((obj.scaleX || 1) * 100);
    document.getElementById('p-angle').value = Math.round(isLineLike(obj) ? trueLineAngle(obj) : (obj.angle || 0));
    document.getElementById('p-opacity').value = Math.round((obj.opacity ?? 1) * 100);
    if (isStrokeable(obj)) {
        const strokedChild = (obj.type === 'group' || obj.type === 'activeSelection') && obj.getObjects
            ? (obj.getObjects().find(isStrokeable) || obj) : obj;
        document.getElementById('p-line-style').value = styleFromDashArray(strokedChild.strokeDashArray);
    }
    document.getElementById('btn-group').textContent = (obj.type === 'group') ? '解散群組' : '群組';
    const epBtn = document.getElementById('btn-edit-points');
    epBtn.style.display = (['line', 'polyline', 'polygon', 'rect'].includes(obj.type) && !obj.isDimGuide) ? '' : 'none';
    epBtn.textContent = obj.__pointEditing ? '完成編輯' : '編輯端點';
    const inPtEdit = !!obj.__pointEditing;
    document.getElementById('btn-poly-close').style.display = inPtEdit ? '' : 'none';
    document.getElementById('btn-poly-smooth').style.display = inPtEdit ? '' : 'none';
    if (inPtEdit) {
        document.getElementById('btn-poly-close').textContent = (obj.type === 'polygon') ? '打開' : '封閉';
        document.getElementById('btn-poly-smooth').textContent = obj.curved ? '取直' : '圓滑';
    }
    document.getElementById('btn-label-bg').style.display = (obj.labelSpec && obj.labelSpec.kind !== 'fabric') ? '' : 'none';
    // 選到文字（含標籤/標註等群組裡的文字）時同步並顯示文字屬性區——底線/粗體/字級/底色對已建立的文字隨時可改
    const txt = firstTextIn(obj);
    if (currentTool === 'select') document.getElementById('sec-text').classList.toggle('show', !!txt);
    if (txt) {
        document.getElementById('p-textcolor').value = toHex(txt.fill) || '#d32f2f';
        document.getElementById('p-fontsize').value = Math.round(txt.fontSize * (txt.scaleX || 1));
        document.getElementById('p-bold').checked = (txt.fontWeight === 'bold');
        document.getElementById('p-underline').value = txt.underline ? (txt.doubleUnderline ? 'double' : 'single') : 'none';
        document.getElementById('p-textbg-on').checked = !!txt.backgroundColor;
        if (txt.backgroundColor) document.getElementById('p-textbg').value = toHex(txt.backgroundColor) || '#fff59d';
    }
}
/* 選取物件（含群組/多選遞迴）裡的第一個文字物件 */
function firstTextIn(o) {
    if (!o) return null;
    if (o.type === 'i-text' || o.type === 'textbox' || o.type === 'text') return o;
    if ((o.type === 'group' || o.type === 'activeSelection') && o.getObjects) {
        for (const c of o.getObjects()) { const t = firstTextIn(c); if (t) return t; }
    }
    return null;
}
function objTypeName(o) {
    return ({ image: '圖片', 'i-text': '文字', textbox: '文字', rect: '矩形', ellipse: '橢圓', line: '直線',
              group: '群組', path: '手繪線', polygon: '遮蓋(不規則)', polyline: '折線' })[o.type] || o.type;
}
function toHex(c) {
    if (!c || typeof c !== 'string') return null;
    if (c[0] === '#') return c.length === 7 ? c : null;
    const m = c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!m) return null;
    return '#' + [m[1], m[2], m[3]].map(n => (+n).toString(16).padStart(2, '0')).join('');
}
canvas.on('selection:created', refreshPropbar);
canvas.on('selection:updated', refreshPropbar);
canvas.on('selection:cleared', refreshPropbar);
canvas.on('object:modified', (e) => {
    // 縮放結束後強制重繪快取，避免 Fabric 預設的 noScaleCache 造成放大後文字/標籤模糊
    const t = e && e.target;
    if (t) {
        t.dirty = true;
        if (t.getObjects) t.getObjects().forEach(o => { o.dirty = true; });
    }
    if (t && t.isDimGuide && t.dimAngleId) rebuildDimAngleArc(t.dimAngleId);
    canvas.requestRenderAll();
    refreshPropbar(); pushState();
});

/* 屬性 → 套用到選取物件 */
document.getElementById('p-scale').addEventListener('change', function () {
    const obj = canvas.getActiveObject(); if (!obj) return;
    const s = Math.max(1, parseFloat(this.value) || 100) / 100;
    obj.set({ scaleX: s, scaleY: s }); obj.setCoords();
    canvas.requestRenderAll(); pushState();
});
document.getElementById('p-angle').addEventListener('change', function () {
    const obj = canvas.getActiveObject(); if (!obj) return;
    let v = parseFloat(this.value) || 0;
    if (isLineLike(obj)) {
        // 直線/箭頭：0 度＝水平線，輸入值是「相對水平的目標角度」，換算成要疊加的旋轉量
        const delta = v - trueLineAngle(obj);
        obj.rotate((obj.angle || 0) + delta);
    } else {
        obj.rotate(v);   // 以物件中心旋轉
    }
    obj.setCoords();
    if (obj.isDimGuide && obj.dimAngleId) rebuildDimAngleArc(obj.dimAngleId);
    canvas.requestRenderAll(); pushState();
});
document.getElementById('p-opacity').addEventListener('input', function () {
    const obj = canvas.getActiveObject(); if (!obj) return;
    obj.set('opacity', (parseInt(this.value, 10) || 100) / 100);
    canvas.requestRenderAll();
});
/* 套用工具：把設定套到選取物（含多選 activeSelection 與群組內的子物件） */
function eachInSelection(obj, fn) {
    if (!obj) return 0;
    let n = 0;
    const visit = o => {
        fn(o) && n++;
        if ((o.type === 'group' || o.type === 'activeSelection') && o.getObjects) o.getObjects().forEach(visit);
    };
    visit(obj);
    return n;
}
document.getElementById('p-width').addEventListener('input', function () {
    document.getElementById('p-width-v').textContent = this.value;
    const v = parseInt(this.value, 10) || 3;
    if (canvas.isDrawingMode) canvas.freeDrawingBrush.width = v;
    const obj = canvas.getActiveObject();
    if (obj && obj.type === 'group' && obj.isArrowGroup) return;   // 箭頭群組要整支重建，很重，放開滑桿（change）才做
    const headLen = arrowHeadLen(v);
    const n = eachInSelection(obj, o => {
        if (o.stroke && (o.type === 'line' || o.type === 'path' || o.type === 'rect' || o.type === 'ellipse' || o.type === 'circle' || o.type === 'polygon' || o.type === 'polyline')) {
            o.set('strokeWidth', v); o.dirty = true; return true;
        }
        if (o.type === 'triangle') {   // 箭頭頭端：大小要跟著粗細一起變，不然線變粗頭還是原本那麼小
            o.set({ width: headLen, height: headLen }); o.setCoords(); o.dirty = true; return true;
        }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); }
});
document.getElementById('p-width').addEventListener('change', function () {
    // 放開滑桿才對箭頭群組整支重建（拖動中每格都 remove+重建 會拖慢畫面）
    const v = parseInt(this.value, 10) || 3;
    const obj = canvas.getActiveObject();
    if (obj && obj.type === 'group' && obj.isArrowGroup) {
        const no = rebuildArrowGroup(obj, { width: v });
        canvas.setActiveObject(no);
        canvas.requestRenderAll();
        pushState();
    }
});
document.getElementById('p-line-style').addEventListener('change', function () {
    const v = this.value;
    if (canvas.isDrawingMode) canvas.freeDrawingBrush.strokeDashArray = dashArrayFor(v, canvas.freeDrawingBrush.width || 3);
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.stroke && (o.type === 'line' || o.type === 'path' || o.type === 'rect' || o.type === 'ellipse' || o.type === 'circle' || o.type === 'polygon' || o.type === 'polyline')) {
            o.set('strokeDashArray', dashArrayFor(v, o.strokeWidth || 3)); o.dirty = true; return true;
        }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
});
/* 端點下拉：選取既有直線/箭頭時改選＝就地互換（線↔單箭頭↔雙箭頭，保留顏色/粗細/線型與位置） */
document.getElementById('p-line-ends').addEventListener('change', function () {
    const obj = canvas.getActiveObject();
    if (!obj || !isLineLike(obj) || obj.dimKind || obj.isDimGuide) return;
    const line = (obj.type === 'line') ? obj : obj.getObjects().find(c => c.type === 'line');
    if (!line) return;
    const pts = trueArrowEndpoints(obj);   // 箭頭群組的子線段有被縮短，要用箭頭尖端當真實頭尾
    const stroke = line.stroke || document.getElementById('p-stroke').value;
    const sw = line.strokeWidth || 3;
    const dashArr = line.strokeDashArray || null;
    const ends = this.value;
    canvas.remove(obj);
    let no;
    if (ends === 'none') {
        no = new fabric.Line([pts[0].x, pts[0].y, pts[1].x, pts[1].y], { stroke, strokeWidth: sw, strokeUniform: true, strokeDashArray: dashArr });
    } else {
        no = makeArrow(pts[0].x, pts[0].y, pts[1].x, pts[1].y, stroke, sw, ends, dashArr);
    }
    canvas.add(no);
    canvas.setActiveObject(no);
    canvas.requestRenderAll();
    pushState();
});
document.getElementById('p-stroke').addEventListener('input', function () {
    if (canvas.isDrawingMode) canvas.freeDrawingBrush.color = this.value;
    const v = this.value;
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.stroke || o.type === 'path') { o.set('stroke', v); o.dirty = true; return true; }
        if (o.type === 'triangle') { o.set('fill', v); o.dirty = true; return true; }   // 箭頭頭端
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); }
});
document.getElementById('p-textcolor').addEventListener('input', function () {
    const v = this.value;
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox' || o.type === 'text') { o.set('fill', v); o.dirty = true; return true; }
        return false;
    });
    // 規格標籤把顏色記進 spec，之後雙擊改字重建不掉色
    if (obj && obj.labelSpec && !['fabric', 'image', 'multi'].includes(obj.labelSpec.kind)) obj.labelSpec.color = v;
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); }
});
document.getElementById('p-fontsize').addEventListener('change', function () {
    const obj = canvas.getActiveObject();
    const v = Math.max(6, parseInt(this.value, 10) || 28);
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox') { o.set({ fontSize: v, scaleX: 1, scaleY: 1 }); o.setCoords(); o.dirty = true; return true; }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
});
document.getElementById('p-bold').addEventListener('change', function () {
    const obj = canvas.getActiveObject();
    const bold = this.checked;
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox') { o.set('fontWeight', bold ? 'bold' : 'normal'); o.dirty = true; return true; }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
});
function applyTextBg() {
    const obj = canvas.getActiveObject();
    const on = document.getElementById('p-textbg-on').checked;
    let bg = document.getElementById('p-textbg').value;
    // 勾選加底色時色票若是白色且文字原本沒底色，改用預設黃色：白色色票多半是先前
    // 點選過標註文字（內建白底）被同步留下的，白底疊在白圖紙上看不出來，使用者會以為勾了沒效
    if (on && bg.toLowerCase() === '#ffffff') {
        const t = firstTextIn(obj);
        if (t && !t.backgroundColor) {
            bg = '#fff59d';
            document.getElementById('p-textbg').value = bg;
        }
    }
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox' || o.type === 'text') { o.set('backgroundColor', on ? bg : ''); o.dirty = true; return true; }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
}
document.getElementById('p-underline').addEventListener('change', function () {
    const v = this.value;
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox' || o.type === 'text') {
            o.set('underline', v !== 'none'); o.doubleUnderline = (v === 'double'); o.dirty = true; return true;
        }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
});
document.getElementById('p-textbg').addEventListener('input', applyTextBg);
document.getElementById('p-textbg-on').addEventListener('change', applyTextBg);
document.getElementById('p-maskcolor').addEventListener('input', function () {
    const obj = canvas.getActiveObject();
    if (obj && obj.type === 'polygon') { obj.set('fill', this.value); canvas.requestRenderAll(); }
});

/* ── 鎖定（Figma 式）：鎖住底圖等物件，點擊會穿透不再誤選 ── */
function lockSelection() {
    const obj = canvas.getActiveObject();
    if (!obj) { toast('請先選取要鎖定的物件（例如底圖）'); return; }
    const targets = (obj.type === 'activeSelection') ? obj.getObjects().slice() : [obj];
    canvas.discardActiveObject();
    targets.forEach(o => { o.locked = true; o.selectable = false; o.evented = false; });
    canvas.requestRenderAll();
    updateLockUI();
    pushState();
    toast('已鎖定 ' + targets.length + ' 個物件（點擊會穿透）。要解開請按屬性列右側「解鎖全部」');
}
/* 座標被算壞（NaN/Infinity）的物件：看不見、選得到、每幀渲染出錯造成拖曳殘影——直接移除 */
function purgePoisonedObjects() {
    let n = 0;
    canvas.getObjects().slice().forEach(o => {
        if (o === artboard) return;
        if (!isFinite(o.left) || !isFinite(o.top) || !isFinite(o.width) || !isFinite(o.height) || !isFinite(o.scaleX) || !isFinite(o.scaleY)) {
            canvas.remove(o); n++;
        }
    });
    return n;
}
function unlockAll() {
    // 一鍵自救：解鎖正常鎖定物、救回「點不到又刪不掉」的殘留物、清掉座標壞掉的毒化物件
    const purged = purgePoisonedObjects();
    const locked = canvas.getObjects().filter(o => o !== artboard && (o.locked || o.selectable === false || o.evented === false) && !o.isDimGuide);
    locked.forEach(o => { o.locked = false; o.selectable = true; o.evented = true; });
    // 順手救回卡在隱形狀態的一般物件（框選搬移等流程暫時隱藏後若中途出錯沒還原，物件會看不見但佔位）
    let unhidden = 0;
    canvas.getObjects().forEach(o => {
        if (o !== artboard && !o.isDimGuide && o.visible === false) { o.visible = true; o.dirty = true; unhidden++; }
    });
    if (!purged && !locked.length && !unhidden) return;
    canvas.requestRenderAll();
    updateLockUI();
    pushState();
    toast('已解鎖 ' + locked.length + ' 個物件' + (purged ? '，並清除 ' + purged + ' 個損壞殘留物' : '') + (unhidden ? '，救回 ' + unhidden + ' 個隱形物件' : ''));
}
function updateLockUI() {
    const n = canvas.getObjects().filter(o => o.locked).length;
    document.getElementById('lock-info').style.display = n ? 'inline-flex' : 'none';
    document.getElementById('lock-count').textContent = n;
}

/* ── 浮水印：單一或自動間距填滿，預設鎖定避免誤點 ── */
function openWmModal() { showModal('wm-modal'); document.getElementById('wm-text').focus(); }
function removeWatermark() {
    const olds = canvas.getObjects().filter(o => o.wmRole);
    olds.forEach(o => canvas.remove(o));
    if (olds.length) { updateLockUI(); canvas.requestRenderAll(); pushState(); }
}
function applyWatermark() {
    const text = document.getElementById('wm-text').value.trim();
    if (!text) { toast('請輸入浮水印文字'); return; }
    const angle = parseInt(document.getElementById('wm-angle').value, 10) || 0;
    const mode = document.getElementById('wm-mode').value;
    const opacity = (parseInt(document.getElementById('wm-opacity').value, 10) || 15) / 100;
    const color = document.getElementById('wm-color').value;
    removeWatermark();

    const mk = (fs, x, y) => new fabric.Text(text, {
        left: x, top: y, originX: 'center', originY: 'center',
        fontSize: fs, fontFamily: LABEL_FONT, fontWeight: 'bold',
        fill: color, angle: angle, selectable: false, evented: false
    });
    const cx = artboard.left + artW / 2, cy = artboard.top + artH / 2;
    let wm;
    if (mode === 'single') {
        // 字級自動：寬度約佔畫布七成
        const probe = mk(100, 0, 0);
        const fs = Math.max(20, Math.min(100 * (artW * 0.7) / probe.width, artH * 0.6));
        wm = new fabric.Group([mk(fs, cx, cy)], {});
    } else {
        // 填滿：依字寬自動抓間距，奇偶列錯開半格
        const fs = Math.max(12, parseInt(document.getElementById('wm-size').value, 10) || 60);
        const probe = mk(fs, 0, 0);
        const dx = probe.width + fs * 1.6;
        const dy = probe.height + fs * 2.2;
        const items = [];
        let row = 0;
        for (let y = artboard.top - dy / 2; y < artboard.top + artH + dy; y += dy, row++) {
            const off = (row % 2) ? dx / 2 : 0;
            for (let x = artboard.left - dx / 2 + off; x < artboard.left + artW + dx; x += dx) {
                items.push(mk(fs, x, y));
            }
        }
        wm = new fabric.Group(items, {});
        // 裁掉超出畫布的部分，列印才乾淨
        wm.clipPath = new fabric.Rect({
            left: artboard.left, top: artboard.top, width: artW, height: artH, absolutePositioned: true
        });
    }
    wm.set({ opacity: opacity });
    wm.wmRole = 'wm';
    wm.locked = true; wm.selectable = false; wm.evented = false;   // 預設鎖定
    canvas.add(wm);
    updateLockUI();
    canvas.requestRenderAll();
    pushState();
    hideModal('wm-modal');
    toast('浮水印已套用（已自動鎖定；要調整請按屬性列右側「解鎖全部」）');
}

/* 圖層 / 群組 / 刪除 */
function layerCmd(cmd) {
    const obj = canvas.getActiveObject(); if (!obj) return;
    if (cmd === 'front') canvas.bringToFront(obj);
    if (cmd === 'forward') canvas.bringForward(obj);
    if (cmd === 'backward') canvas.sendBackwards(obj);
    if (cmd === 'back') canvas.sendToBack(obj);
    canvas.sendToBack(artboard); // 畫布永遠最底
    canvas.requestRenderAll(); pushState();
}
/* 合併：多物件 → 單一物件（雙擊不拆，Alt+雙擊才拆），縮放比例位置固定 */
function mergeSelection() {
    const obj = canvas.getActiveObject();
    if (!obj || obj.type !== 'activeSelection') { toast('請先框選或 Shift 點選要合併的多個物件'); return; }
    const g = obj.toGroup();
    g.merged = true;
    canvas.requestRenderAll();
    refreshPropbar();
    pushState();
    toast('已合併為單一物件（要拆開：Alt+雙擊）');
}
function groupCmd() {
    const obj = canvas.getActiveObject(); if (!obj) return;
    if (obj.type === 'group') {
        enterGroup(obj);
        return;
    } else if (obj.type === 'activeSelection') {
        const props = obj._regroupProps;
        const g = obj.toGroup();
        if (props && g) Object.assign(g, props);   // 由「進入群組」拆出的，重組時還原標籤屬性
    } else { toast('請以框選或 Shift 點選多個物件再群組'); return; }
    canvas.requestRenderAll(); refreshPropbar(); pushState();
}
/* Ctrl+A：全選畫布上的物件（含畫布外，排除底板/鎖定/浮水印鎖定物） */
function selectAllObjects() {
    setTool('select');
    // visible !== false：隱藏物件（例如角度標註的輔助線）不可被全選掃進來，會變成空的幽靈選取框
    const objs = canvas.getObjects().filter(o => o !== artboard && o.selectable !== false && !o.locked && o.visible !== false);
    if (!objs.length) { toast('畫布上沒有可選取的物件'); return; }
    canvas.discardActiveObject();
    const sel = new fabric.ActiveSelection(objs, { canvas: canvas });
    canvas.setActiveObject(sel);
    canvas.requestRenderAll();
    refreshPropbar();
}
function deleteSelection() {
    const obj = canvas.getActiveObject(); if (!obj) return;
    if (obj.__groupEditFor) {
        // 正在編輯標籤/標註的文字：按刪除＝把整組刪掉（不然只會刪到暫時編輯框，群組又被加回來，看起來永遠刪不掉）
        obj.__deleteGroup = true;
        obj.exitEditing();
        return;
    }
    const hadBalloon = (obj.balloonLetter || (obj.type === 'activeSelection' && obj.getObjects().some(o => o.balloonLetter)));
    const removed = (obj.type === 'activeSelection') ? obj.getObjects().slice() : [obj];
    removed.forEach(o => canvas.remove(o));
    canvas.discardActiveObject();
    // 角度標註整組連動刪除：刪標示→隱藏中的輔助線一併刪；刪輔助線→標示與另一條也一併刪
    const daIds = new Set(removed.map(o => o.dimAngleId).filter(Boolean));
    if (daIds.size) canvas.getObjects().slice().forEach(o => { if (o.dimAngleId && daIds.has(o.dimAngleId)) canvas.remove(o); });
    if (hadBalloon) updateBalloonSummary();  // 球標增減 → 右下角範圍文字自動重建
    canvas.requestRenderAll(); pushState();
}

/* ── Undo / Redo（JSON 快照） ──
   快照＝整張畫布序列化。debounce 150ms 合併連續動作；undo/redo 前先 flush 未寫入的快照。
   穩定性關鍵：貼上的底圖是數 MB 的 base64 dataURL，若每份快照都內含一份，
   每個動作都要同步 stringify 幾 MB 字串（畫面凍結），30 份快照更會撐爆分頁記憶體（整頁當掉）。
   → dataURL 只存一份進 IMG_SRC_POOL 共用池，快照裡只放「__imgpool:索引」占位，
     快照從數 MB 縮到幾 KB；還原時再換回原字串（同一個字串參照，不另占記憶體）。 */
let undoStack = [], redoStack = [], restoring = false, pushTimer = null;
const IMG_SRC_POOL = [];   // 本次開啟期間用過的大圖 dataURL，各存一份；快照/舊快照都可能引用，不做淘汰
function snapWalk(node, fn) {
    if (!node || typeof node !== 'object') return;
    fn(node);
    if (Array.isArray(node.objects)) node.objects.forEach(o => snapWalk(o, fn));
    if (node.backgroundImage) snapWalk(node.backgroundImage, fn);
    if (node.overlayImage) snapWalk(node.overlayImage, fn);
}
function snapPoolify(json) {   // 大 dataURL → 池索引占位（就地修改）
    snapWalk(json, n => {
        if (typeof n.src === 'string' && n.src.length > 2000 && n.src.slice(0, 5) === 'data:') {
            let idx = IMG_SRC_POOL.indexOf(n.src);
            if (idx === -1) { IMG_SRC_POOL.push(n.src); idx = IMG_SRC_POOL.length - 1; }
            n.src = '__imgpool:' + idx;
        }
    });
}
function snapUnpoolify(json) {   // 池索引占位 → 原 dataURL（沒有占位的舊快照/工作檔原樣通過）
    snapWalk(json, n => {
        if (typeof n.src === 'string' && n.src.slice(0, 10) === '__imgpool:') {
            const s = IMG_SRC_POOL[parseInt(n.src.slice(10), 10)];
            if (s) n.src = s;
        }
    });
}
const SNAP_PROPS = ['id', 'selectable', 'evented', 'locked', 'merged', 'balloonLetter', 'dcNumber', 'dcShape', 'dcRole', 'labelSpec', 'labelKind', 'specPath', 'wmRole', 'isArrowGroup', 'dimKind', 'isFreehandEnds', 'isQuickLabel', 'doubleUnderline', 'isDimGuide', 'dimAngleId', 'curved'];
/* 卡頓/當機診斷：主要耗時點超過門檻就在主控台留紀錄（回報問題時請開 F12 把紅字/黃字截圖）；
   未攔截的程式例外第一次發生時跳 toast 提醒——渲染迴圈被例外打斷正是「殘影＋卡死」的典型來源 */
let __egErrToasted = false;
window.addEventListener('error', function (ev) {
    console.warn('[EGdraw] 未攔截例外：', ev.message, ev.filename, ev.lineno);
    if (!__egErrToasted) {
        __egErrToasted = true;
        toast('偵測到程式例外（畫面可能出現殘影或卡頓）：' + (ev.message || '不明錯誤') + '——建議儲存後重新整理，並回報這則訊息');
    }
});
function __egSlow(op, t0) {
    const ms = Math.round(performance.now() - t0);
    if (ms > 300) console.warn('[EGdraw] ' + op + ' 耗時 ' + ms + 'ms');
}
function doPushState() {
    pushTimer = null;
    if (restoring) return;
    const __t0 = performance.now();
    try {
        const j = canvas.toJSON(SNAP_PROPS);
        snapPoolify(j);   // 大圖 dataURL 抽進共用池，快照只剩幾 KB，stringify 不再凍結畫面
        const snap = JSON.stringify(j);
        if (undoStack[undoStack.length - 1] === snap) return;   // 內容沒變就不疊快照
        undoStack.push(snap);
        redoStack = [];
        draftDirty = true;   // 內容有變＝下一輪自動暫存要重存
        // 上限用「總位元組」控管，不只份數（保險：載入工作檔的第一份快照仍是未池化的原始字串）
        let total = 0;
        for (let i = 0; i < undoStack.length; i++) total += undoStack[i].length;
        while ((undoStack.length > 30 || total > 120 * 1024 * 1024) && undoStack.length > 3) {
            total -= undoStack[0].length;
            undoStack.shift();
        }
    } catch (e) { /* 圖太大時快照失敗不影響操作 */ }
    __egSlow('undo快照', __t0);
}

/* ── 暫存檔（IndexedDB，這台電腦、依使用者區分）──
   暫存＝最新 undo 快照＋圖片池（快照本來就池化，存檔零額外編碼成本）。
   手動「暫存」鈕＋內容有變每 60 秒自動暫存＋關窗前盡力補存；
   下次開啟詢問是否接續編輯，選「不開啟」的暫存檔在該次關閉視窗後自動移除。 */
let draftDirty = false, draftDeclined = false, draftSavedThisSession = false;
function draftDb() {
    return new Promise((res, rej) => {
        const rq = indexedDB.open('egdraw_drafts', 1);
        rq.onupgradeneeded = () => rq.result.createObjectStore('draft');
        rq.onsuccess = () => res(rq.result);
        rq.onerror = () => rej(rq.error);
    });
}
function draftPut(val) {
    return draftDb().then(db => new Promise((res, rej) => {
        const tx = db.transaction('draft', 'readwrite');
        tx.objectStore('draft').put(val, 'u' + USER_ID);
        tx.oncomplete = res; tx.onerror = () => rej(tx.error);
    }));
}
function draftGet() {
    return draftDb().then(db => new Promise((res, rej) => {
        const rq = db.transaction('draft').objectStore('draft').get('u' + USER_ID);
        rq.onsuccess = () => res(rq.result || null); rq.onerror = () => rej(rq.error);
    }));
}
function draftDelete() {
    return draftDb().then(db => new Promise((res, rej) => {
        const tx = db.transaction('draft', 'readwrite');
        tx.objectStore('draft').delete('u' + USER_ID);
        tx.oncomplete = res; tx.onerror = () => rej(tx.error);
    })).catch(() => {});
}
function saveDraft(manual) {
    if (restoring) { if (manual) toast('還原進行中，請稍後再按一次暫存'); return; }
    if (canvas.getObjects().filter(o => o.id !== '__artboard').length === 0) { if (manual) toast('畫布是空的，沒有東西可暫存'); return; }
    flushPendingState();   // 確保最新內容已進快照
    const snap = undoStack[undoStack.length - 1];
    if (!snap) { if (manual) toast('暫存失敗：目前沒有可用的內容快照'); return; }
    draftPut({ snap: snap, pool: IMG_SRC_POOL.slice(), ts: Date.now() }).then(() => {
        draftDirty = false; draftSavedThisSession = true;
        if (manual) toast('已暫存到這台電腦（下次開啟批圖編輯器會詢問是否接續編輯）');
    }).catch(e => { if (manual) toast('暫存失敗：' + ((e && e.message) || '瀏覽器儲存空間不足')); });
}
setInterval(() => { if (draftDirty) saveDraft(false); }, 60000);
window.addEventListener('pagehide', function () {
    if (draftDirty) saveDraft(false);                                // 關窗前盡力補存（存不完就靠 60 秒自動暫存的那份）
    if (draftDeclined && !draftSavedThisSession) draftDelete();      // 「不開啟」的暫存＝本次關閉後自動移除
});
function offerDraftRestore() {
    draftGet().then(d => {
        if (!d || !d.snap) return;
        const dt = new Date(d.ts || Date.now()), p = n => String(n).padStart(2, '0');
        const when = dt.getFullYear() + '/' + p(dt.getMonth() + 1) + '/' + p(dt.getDate()) + ' ' + p(dt.getHours()) + ':' + p(dt.getMinutes());
        if (confirm('偵測到暫存檔（' + when + ' 暫存）。\n\n要開啟接續編輯嗎？\n選「取消」＝不開啟，此暫存檔會在本次關閉視窗後自動移除。')) {
            // 圖片池必須在快照還原前先接回（快照裡是 __imgpool:索引 占位）；此時是頁面初始，池必為空
            if (IMG_SRC_POOL.length === 0 && Array.isArray(d.pool)) d.pool.forEach(s => IMG_SRC_POOL.push(s));
            restoreState(d.snap);
            const wait = setInterval(() => {
                if (restoring) return;
                clearInterval(wait);
                zoomFit();
                pushState();
                toast('已還原暫存檔，繼續編輯；要長期保存請用「料號附件」存工作檔');
            }, 150);
            draftSavedThisSession = true;   // 暫存內容已被採用，關閉時不清掉
        } else {
            draftDeclined = true;
        }
    }).catch(() => {});
}
function pushState() {
    if (restoring) return;
    clearTimeout(pushTimer);
    pushTimer = setTimeout(doPushState, 150);
}
function flushPendingState() {
    if (pushTimer) { clearTimeout(pushTimer); doPushState(); }
}
function restoreState(json) {
    restoring = true;
    const __t0 = performance.now();
    try {
        // 先清掉目前選取再重建：等 loadFromJSON 換掉全部物件後才清，舊多選裡的物件 canvas 已是
        // undefined，destroy→setCoords→控制點 positionHandler 會連環拋例外（殘影/卡頓來源）
        try { canvas.discardActiveObject(); } catch (e) { /* 選取已壞掉就算了，下面照樣重建 */ }
        const j = (typeof json === 'string') ? JSON.parse(json) : json;
        snapUnpoolify(j);   // 快照裡的池索引換回真正的 dataURL；未池化的舊格式原樣通過
        canvas.loadFromJSON(j, function () { restoreDone(); __egSlow('undo還原', __t0); });
    } catch (e) {
        restoring = false;   // JSON 壞掉時 restoring 卡在 true 會讓之後所有快照永久靜默失效
        toast('還原失敗：資料格式有誤');
    }
}
function restoreDone() {
    try {
        canvas.discardActiveObject();   // 舊選取框指向已被重建取代的物件，留著會變成拖得動卻刪不掉的幽靈框
        findArtboard();
        canvas.sendToBack(artboard);
        purgePoisonedObjects();         // 清掉座標已變成 NaN/Infinity 的毒化物件（看不見選得到、渲染出錯留殘影）
        canvas.getObjects().forEach(o => { if (o.curved) o.objectCaching = false; });   // 圓滑曲線可能超出快取框，關快取避免被裁掉
        artW = Math.round(artboard.width * (artboard.scaleX || 1));
        artH = Math.round(artboard.height * (artboard.scaleY || 1));
        document.getElementById('st-canvas').textContent = artW + '×' + artH;
        canvas.requestRenderAll();
        refreshPropbar();
        updateLockUI();
    } finally {
        restoring = false;   // 中途出錯也一定要解除，否則之後所有快照永久靜默失效
    }
}
function undo() {
    if (restoring) return;   // 還原進行中不疊加：連按 Ctrl+Z 排隊多發全畫布重建會凍結數秒
    flushPendingState();
    if (undoStack.length < 2) { toast('沒有可復原的步驟'); return; }
    redoStack.push(undoStack.pop());
    restoreState(undoStack[undoStack.length - 1]);
}
function redo() {
    if (restoring) return;
    flushPendingState();
    if (!redoStack.length) { toast('沒有可重做的步驟'); return; }
    const s = redoStack.pop();
    undoStack.push(s);
    restoreState(s);
}
canvas.on('path:created', function (opt) {
    try { applyFreehandEnds(opt.path); } catch (e) { /* 端點加不上去也不影響手繪線本身，靜默略過 */ }
    setTimeout(pushState, 30);
});
/* 手繪線的端點（無/單箭頭/雙箭頭）：從 fabric.Path 內部座標換算成畫布絕對座標算出頭尾切線方向，
   換算方式跟既有群組內文字定位（fabric.util.transformPoint + calcTransformMatrix）同一套原理。 */
function applyFreehandEnds(path) {
    const ends = document.getElementById('p-line-ends').value;
    if (ends === 'none' || !path || !path.path || path.path.length < 2) return;
    const toPt = cmd => ({ x: cmd[cmd.length - 2], y: cmd[cmd.length - 1] });
    const off = path.pathOffset || { x: 0, y: 0 };
    const m = path.calcTransformMatrix();
    const abs = path.path.map(cmd => { const pt = toPt(cmd); return fabric.util.transformPoint({ x: pt.x - off.x, y: pt.y - off.y }, m); });
    const first = abs[0], second = abs[1] || abs[0];
    const last = abs[abs.length - 1], prev = abs[abs.length - 2] || last;
    const width = path.strokeWidth || 3;
    const color = path.stroke || '#000000';
    const headLen = arrowHeadLen(width);
    const angEnd = Math.atan2(last.y - prev.y, last.x - prev.x) * 180 / Math.PI;
    const angStart = Math.atan2(first.y - second.y, first.x - second.x) * 180 / Math.PI;
    const items = [path];
    // 手繪路徑無法像直線那樣縮短，箭頭改成「底部貼在筆畫末端、尖端往外延伸」，筆畫就不會超過尖端
    const radE = angEnd * Math.PI / 180, radS = angStart * Math.PI / 180;
    if (ends === 'end' || ends === 'both') items.push(arrowHeadTri(last.x + Math.cos(radE) * headLen, last.y + Math.sin(radE) * headLen, angEnd, headLen, color));
    if (ends === 'both') items.push(arrowHeadTri(first.x + Math.cos(radS) * headLen, first.y + Math.sin(radS) * headLen, angStart, headLen, color));
    if (items.length > 1) {
        canvas.remove(path);
        const g = new fabric.Group(items, {});
        g.merged = true; g.isFreehandEnds = true;
        canvas.add(g);
    }
}
canvas.on('object:removed', function () { if (!restoring && !drawing) { /* deleteSelection 已 pushState，避免重複 */ } });

/* ── 鍵盤 ── */
document.addEventListener('keydown', function (e) {
    if (e.code === 'Space' && !isTextEditing()) { spaceDown = true; canvas.defaultCursor = 'grab'; }
    const tag = (document.activeElement || {}).tagName;
    const inInput = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || isTextEditing();

    if (e.ctrlKey && !e.shiftKey && e.key.toLowerCase() === 'z') { if (!inInput) { e.preventDefault(); undo(); } return; }
    if (e.ctrlKey && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))) { if (!inInput) { e.preventDefault(); redo(); } return; }
    if (e.ctrlKey && e.key.toLowerCase() === 'c') { if (!inInput) { if (copySelection()) e.preventDefault(); } return; }
    if (e.ctrlKey && e.key.toLowerCase() === 'd') { if (!inInput) { e.preventDefault(); duplicateSelection(); } return; }
    if (e.ctrlKey && e.key.toLowerCase() === 'g') {
        if (!inInput) { e.preventDefault(); groupCmd(); } return;
    }
    if (e.ctrlKey && e.key === '0') { e.preventDefault(); zoomFit(); return; }
    if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'v') {
        if (!inInput) { e.preventDefault(); if (!pasteInternalOrCross()) toast('沒有可貼上的跨窗/內部內容'); }
        return;
    }
    if (e.ctrlKey && e.key.toLowerCase() === 'a') {
        if (!inInput) { e.preventDefault(); selectAllObjects(); }
        return;
    }
    if (inInput) return;

    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); deleteSelection(); return; }
    // 方向鍵微調選取物件：1px，Shift＝10px
    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        const obj = canvas.getActiveObject();
        if (obj) {
            e.preventDefault();
            const step = e.shiftKey ? 10 : 1;
            if (e.key === 'ArrowUp') obj.set('top', obj.top - step);
            if (e.key === 'ArrowDown') obj.set('top', obj.top + step);
            if (e.key === 'ArrowLeft') obj.set('left', obj.left - step);
            if (e.key === 'ArrowRight') obj.set('left', obj.left + step);
            obj.setCoords();
            canvas.requestRenderAll();
            clearTimeout(window.__nudgeTimer);
            window.__nudgeTimer = setTimeout(pushState, 400);   // 連按只記一次復原點
        }
        return;
    }
    if (e.key === 'Escape') {
        const ao = canvas.getActiveObject();
        if (ao && ao.__pointEditing) { togglePointEdit(); return; }   // 第一下 Esc 先離開編輯端點模式，第二下才取消選取
        setTool('select'); canvas.discardActiveObject(); canvas.requestRenderAll(); return;
    }
    const keyTool = { v: 'select', h: 'pan', b: 'draw', l: 'line', r: 'rect', o: 'ellipse', t: 'text', m: 'maskrect', c: 'cropcopy', x: 'cropmove' }[e.key.toLowerCase()];
    if (keyTool && !e.ctrlKey && !e.altKey) setTool(keyTool);
});
document.addEventListener('keyup', function (e) {
    if (e.code === 'Space') { spaceDown = false; canvas.defaultCursor = (currentTool === 'pan') ? 'grab' : 'default'; }
});

/* ── 跳窗 / 畫布設定 / 其他 ── */
function showModal(id) { document.getElementById(id).classList.add('show'); }
function hideModal(id) { document.getElementById(id).classList.remove('show'); }
function openCanvasModal() {
    document.getElementById('cv-w').value = artW;
    document.getElementById('cv-h').value = artH;
    document.getElementById('cv-bg').value = toHex(artboard.fill) || '#ffffff';
    showModal('canvas-modal');
}
function applyCanvasModal() {
    setArtboardSize(parseInt(document.getElementById('cv-w').value, 10) || artW,
                    parseInt(document.getElementById('cv-h').value, 10) || artH,
                    document.getElementById('cv-bg').value);
    hideModal('canvas-modal');
    zoomFit(); pushState();
}
function fitArtboardToContent() {
    const objs = canvas.getObjects().filter(o => o !== artboard);
    if (!objs.length) { toast('畫布上沒有內容'); return; }
    let l = Infinity, t = Infinity, r = -Infinity, b = -Infinity;
    objs.forEach(o => {
        const bb = o.getBoundingRect(true, true);
        l = Math.min(l, bb.left); t = Math.min(t, bb.top);
        r = Math.max(r, bb.left + bb.width); b = Math.max(b, bb.top + bb.height);
    });
    const pad = 10;
    artboard.set({ left: l - pad, top: t - pad });
    setArtboardSize(r - l + pad * 2, b - t + pad * 2);
    zoomFit(); pushState();
    toast('畫布已調整為剛好包住所有內容');
}
function openSecondWindow() {
    window.open(location.href, 'egImgEditor_' + Date.now(),
        'width=1280,height=860,menubar=no,toolbar=no,location=no,status=no,resizable=yes');
}
let toastTimer = null;
function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.style.display = 'none', 3200);
}

/* 數字輸入框 UI 規則：聚焦全選、雙擊清空、Enter 跳下一欄 */
document.querySelectorAll('input[type=number], .ni').forEach(inp => {
    inp.addEventListener('focus', function () { this.select(); });
    inp.addEventListener('dblclick', function () { this.value = ''; });
    inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const all = Array.from(document.querySelectorAll('input, select'));
            const i = all.indexOf(this);
            for (let j = i + 1; j < all.length; j++) {
                if (all[j].offsetParent !== null) { all[j].focus(); break; }
            }
            this.dispatchEvent(new Event('change'));
        }
    });
});

/* ── 個人畫圖偏好：顏色/粗細/印章大小…改過就記住，下次開啟沿用（存在 system_settings，依使用者區分）── */
const PREF_FIELDS = [
    ['p-stroke', 'stroke'], ['p-width', 'width'], ['p-line-ends', 'lineEnds'], ['p-line-style', 'lineStyle'],
    ['p-fill', 'fill'], ['p-fill-on', 'fillOn', true],
    ['p-textcolor', 'textColor'], ['p-fontsize', 'fontSize'], ['p-bold', 'bold', true], ['p-underline', 'underline'],
    ['p-textbg', 'textBg'], ['p-textbg-on', 'textBgOn', true],
    ['p-balloon-size', 'balloonSize'], ['p-dc-shape', 'dcShape'], ['p-dc-size', 'dcSize'],
    ['p-stamp-size', 'stampSize'], ['p-maskcolor', 'maskColor'], ['p-crop-transparent', 'cropTransparent', true],
    ['p-connect-kind', 'connectKind'], ['p-dim-style', 'dimStyle']
];
function applyUserPrefs() {
    PREF_FIELDS.forEach(([id, key, isCheckbox]) => {
        const el = document.getElementById(id);
        const v = USER_PREFS[key];
        if (!el || v === undefined || v === null) return;
        if (isCheckbox) el.checked = !!v; else el.value = v;
    });
    document.getElementById('p-width-v').textContent = document.getElementById('p-width').value;
}
let prefsSaveTimer = null;
function saveUserPrefsDebounced() {
    clearTimeout(prefsSaveTimer);
    prefsSaveTimer = setTimeout(() => {
        const prefs = {};
        PREF_FIELDS.forEach(([id, key, isCheckbox]) => {
            const el = document.getElementById(id);
            if (!el) return;
            prefs[key] = isCheckbox ? el.checked : el.value;
        });
        const fd = new FormData();
        fd.append('action', 'save_user_prefs');
        fd.append('prefs', JSON.stringify(prefs));
        fetch('image_editor.php', { method: 'POST', body: fd }).catch(() => {});
    }, 600);
}
PREF_FIELDS.forEach(([id]) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', saveUserPrefsDebounced);
});
applyUserPrefs();

/* ── 初始化 ── */
resizeViewport();
setArtboardSize(artW, artH);
zoomFit();
pushState();  // 初始狀態
offerDraftRestore();   // 有暫存檔時詢問是否接續編輯（選「不開啟」＝本次關閉後自動移除）
setTool('select');
</script>
</body>
</html>
