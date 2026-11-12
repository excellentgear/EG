<?php
/**
 * 分類鈴鐺設定 API（限系統管理者）——只管理 notice_bell_cat 的顯示設定，不碰任何通知資料。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/notice_bell_lib.php';

function nbout($a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function nberr($m, $c = 400) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }

try { $db = (new DBConnection())->getPDO(); eg_nbell_ensure_schema($db); }
catch (Throwable $e) { nberr('DB連線失敗：' . $e->getMessage(), 500); }

$uname = $_SESSION['userName'] ?? '';
if ($uname === '') nberr('未登入', 401);
$st = $db->prepare("SELECT id, user_status FROM user WHERE user_uname=?");
$st->execute([$uname]);
$me = $st->fetch(PDO::FETCH_ASSOC);
if (!$me) nberr('未登入', 401);
$isAdmin = in_array((int)$me['user_status'], [9, 90], true);
if (!$isAdmin) {
    $q = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
    $q->execute([(int)$me['id']]);
    $isAdmin = (bool)$q->fetchColumn();
}
if (!$isAdmin) nberr('僅系統管理者可設定', 403);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action !== 'list') {   // 寫入動作一律驗 CSRF
    if (empty($_SESSION['nbell_csrf']) || !hash_equals((string)$_SESSION['nbell_csrf'], (string)($_POST['csrf'] ?? ''))) {
        nberr('連線憑證失效，請重新整理頁面後再試');
    }
}
const NB_MAX = 8;   // 分類鈴鐺上限（置頂列放不下太多）

$rowOut = function (array $r) {
    $src = json_decode((string)$r['src_json'], true);
    return ['id' => (int)$r['cat_id'], 'name' => (string)$r['name'], 'icon' => (string)$r['icon'], 'kind' => (string)$r['kind'],
            'src' => eg_nbell_clean_src(is_array($src) ? $src : []), 'active' => (int)$r['is_active'], 'sort' => (int)$r['sort_order']];
};

try {
switch ($action) {

case 'list': {
    $rows = $db->query("SELECT * FROM notice_bell_cat ORDER BY sort_order, cat_id")->fetchAll(PDO::FETCH_ASSOC);
    // 近 90 天各 ref_type 的通知數（讓管理員知道哪個來源有量）
    $cnt = [];
    foreach ($db->query("SELECT COALESCE(ref_type,'') rt, COUNT(*) c FROM live_event WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY ref_type")->fetchAll(PDO::FETCH_ASSOC) as $r) $cnt[$r['rt']] = (int)$r['c'];
    $groups = [];
    $covered = [];
    foreach (eg_nbell_source_groups() as $pat => $label) {
        $n = 0;
        foreach ($cnt as $rt => $c) {
            $hit = ($pat === '(none)') ? ($rt === '') : (substr($pat, -1) === '*' ? strpos($rt, substr($pat, 0, -1)) === 0 : $rt === $pat);
            if ($hit) { $n += $c; $covered[$rt] = 1; }
        }
        $groups[] = ['key' => $pat, 'label' => $label, 'count90' => $n];
    }
    $raw = [];
    foreach ($db->query("SELECT DISTINCT ref_type FROM live_event WHERE ref_type IS NOT NULL AND ref_type<>'' ORDER BY ref_type")->fetchAll(PDO::FETCH_COLUMN) as $rt) {
        $raw[] = ['key' => (string)$rt, 'count90' => $cnt[$rt] ?? 0, 'grouped' => isset($covered[$rt]) ? 1 : 0];
    }
    if (empty($_SESSION['nbell_csrf'])) $_SESSION['nbell_csrf'] = bin2hex(random_bytes(16));
    nbout(['cats' => array_map($rowOut, $rows), 'groups' => $groups, 'raw' => $raw, 'icons' => eg_nbell_icons(),
           'csrf' => $_SESSION['nbell_csrf'], 'max' => NB_MAX]);
}

case 'save': {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') nberr('請輸入分類名稱');
    if (mb_strlen($name, 'UTF-8') > 12) nberr('分類名稱最多 12 個字');
    $icon = (string)($_POST['icon'] ?? '');
    if (!in_array($icon, eg_nbell_icons(), true)) nberr('請從清單中選一個圖示');
    $kind = (string)($_POST['kind'] ?? 'all');
    if (!in_array($kind, ['all', 'todo', 'info'], true)) nberr('類型不正確');
    $src = eg_nbell_clean_src(json_decode((string)($_POST['src'] ?? '[]'), true));
    if (!$src && $kind === 'all') nberr('請至少勾選一個來源，或把類型改成「只看待辦」或「只看通知」（否則和主鈴鐺一模一樣）');
    $dup = $db->prepare("SELECT cat_id FROM notice_bell_cat WHERE name=? AND cat_id<>?");
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) nberr('已經有同名的分類');
    $active = !empty($_POST['active']) ? 1 : 0;
    if ($id > 0) {
        $chk = $db->prepare("SELECT 1 FROM notice_bell_cat WHERE cat_id=?"); $chk->execute([$id]);
        if (!$chk->fetchColumn()) nberr('找不到這個分類，可能已被刪除，請重新整理');
        $db->prepare("UPDATE notice_bell_cat SET name=?, icon=?, kind=?, src_json=?, is_active=?, updated_at=NOW() WHERE cat_id=?")
           ->execute([$name, $icon, $kind, json_encode($src, JSON_UNESCAPED_UNICODE), $active, $id]);
    } else {
        if ((int)$db->query("SELECT COUNT(*) FROM notice_bell_cat")->fetchColumn() >= NB_MAX) nberr('分類鈴鐺最多 ' . NB_MAX . ' 個，請先刪除不用的');
        $next = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM notice_bell_cat")->fetchColumn();
        $db->prepare("INSERT INTO notice_bell_cat (name, icon, kind, src_json, sort_order, is_active, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
           ->execute([$name, $icon, $kind, json_encode($src, JSON_UNESCAPED_UNICODE), $next, $active, (int)$me['id']]);
        $id = (int)$db->lastInsertId();
    }
    nbout(['id' => $id]);
}

case 'delete': {
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("DELETE FROM notice_bell_cat WHERE cat_id=?")->execute([$id]);
    nbout([]);
}

case 'toggle': {
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("UPDATE notice_bell_cat SET is_active=?, updated_at=NOW() WHERE cat_id=?")->execute([!empty($_POST['active']) ? 1 : 0, $id]);
    nbout([]);
}

case 'move': {
    $id = (int)($_POST['id'] ?? 0);
    $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
    $ids = array_map('intval', $db->query("SELECT cat_id FROM notice_bell_cat ORDER BY sort_order, cat_id")->fetchAll(PDO::FETCH_COLUMN));
    $i = array_search($id, $ids, true);
    if ($i === false) nberr('找不到這個分類，請重新整理');
    $j = $i + $dir;
    if ($j >= 0 && $j < count($ids)) { $t = $ids[$i]; $ids[$i] = $ids[$j]; $ids[$j] = $t; }
    $u = $db->prepare("UPDATE notice_bell_cat SET sort_order=? WHERE cat_id=?");
    foreach ($ids as $n => $cid) $u->execute([$n + 1, $cid]);
    nbout([]);
}

default: nberr('未知的動作');
}
} catch (Throwable $e) { error_log('NoticeBell_API: ' . $e->getMessage()); nberr('系統錯誤：' . $e->getMessage(), 500); }
