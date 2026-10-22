<?php
/**
 * 公告／通知／聯絡單：內文圖片與草稿 API（2026-10-22）
 * 判定與存取一律走 src/common/notice_doc_lib.php（唯一實作）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/notice_doc_lib.php';
include_once $document_root . '/EGsystem/src/common/rbac.php';

function ndOut(array $a = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function ndErr(string $m, int $c = 400, array $x = []) { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok' => false, 'error' => $m], $x), JSON_UNESCAPED_UNICODE); exit; }
set_exception_handler(function ($e) { error_log('[NoticeDoc_API] ' . $e->getMessage()); ndErr('系統錯誤：' . $e->getMessage(), 500); });

try { $db = (new DBConnection())->getPDO(); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
catch (Throwable $e) { ndErr('DB連線失敗', 500); }

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) ndErr('尚未登入', 401);
ndoc_ensure_schema($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* 圖片：所有登入者都看得到（公告本來就是給全公司的；列表層級的對象過濾與圖片無關） */
if ($action === 'asset') {
    $a = ndoc_asset_get($db, (int)($_GET['id'] ?? 0));
    if (!$a || !is_file($a['path'])) { http_response_code(404); exit; }
    header('Content-Type: ' . $a['mime']);
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($a['path']));
    readfile($a['path']);
    exit;
}

/* 待核准清單與送審內容預覽（唯讀） */
if ($action === 'pending_list' || $action === 'pending_view') {
    $fx = rbac_user_features($db, $uid);
    $adm = rbac_has($fx, 'all');
    if ($action === 'pending_list') ndOut(ndoc_pending_lists($db, $uid, $adm));
    $st = $db->prepare("SELECT * FROM live_event_draft WHERE id=?");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d || !($adm || (int)$d['user_id'] === $uid || (int)$d['approver_id'] === $uid)) ndErr('找不到這份單', 404);
    $data = json_decode((string)$d['payload'], true) ?: [];
    ndOut(['title' => $d['title'], 'kind' => ndoc_kind_label_of($d['kind']), 'state' => $d['state'],
           'html' => ndoc_hydrate((string)($data['content_html'] ?? '')),
           'eventdate' => $data['eventdate'] ?? '', 'post_until' => $data['post_until'] ?? '',
           'submitter' => ndoc_user_name($db, (int)$d['user_id']), 'reject_reason' => $d['reject_reason']]);
}

/* 以下皆為寫入：先驗登入（上面）再驗 CSRF */
if (empty($_SESSION['ndoc_csrf'])) $_SESSION['ndoc_csrf'] = bin2hex(random_bytes(16));
$tok = $_POST['csrf'] ?? '';
if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['ndoc_csrf'], $tok)) {
    ndErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code' => 'CSRF']);
}
$f = rbac_user_features($db, $uid);
$isAdmin = rbac_has($f, 'all');
$canCreate = $isAdmin || rbac_has($f, 'notice_create') || rbac_has($f, 'notice_edit');

switch ($action) {
    /* 核准設定：只有超級管理員（三種表單的核准方式＋共用核准人員） */
    case 'appr_settings_save': {
        if (!$isAdmin) ndErr('只有超級管理員可以設定核准方式', 403);
        $err = ndoc_appr_save($db, $_POST, (string)$uid);
        if ($err !== '') ndErr($err);
        ndOut(['settings' => ndoc_appr_settings($db), 'approver' => ndoc_appr_approver($db)]);
    }
    /* 核准 / 退回：核准人本人或超管 */
    case 'decide': {
        $dec = ($_POST['decision'] ?? '') === 'reject' ? 'reject' : 'approve';
        $r = ndoc_decide($db, (int)($_POST['draft_id'] ?? 0), $uid, $isAdmin, $dec, (string)($_POST['note'] ?? ''), (string)($_POST['date'] ?? ''));
        if (isset($r['error'])) ndErr($r['error']);
        ndOut($r);
    }
}
if (!$canCreate) ndErr('您沒有新增公告 / 通知的權限', 403);

switch ($action) {
    case 'withdraw': {
        $e = ndoc_withdraw($db, $uid, (int)($_POST['draft_id'] ?? 0));
        if ($e !== '') ndErr($e);
        ndOut();
    }
    case 'asset_upload': {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) ndErr('沒有收到圖片');
        $r = ndoc_asset_store($db, $uid, (string)file_get_contents($_FILES['file']['tmp_name']), (string)($_FILES['file']['name'] ?? ''));
        if (isset($r['error'])) ndErr($r['error']);
        ndoc_asset_gc($db);
        ndOut(['id' => $r['id']]);
    }
    case 'draft_save': {
        $r = ndoc_draft_save($db, $uid, (int)($_POST['draft_id'] ?? 0), $_POST);
        if (isset($r['error'])) ndErr($r['error']);
        ndOut(['id' => $r['id']]);
    }
    case 'draft_delete': {
        ndoc_draft_delete($db, $uid, (int)($_POST['draft_id'] ?? 0));
        ndOut();
    }
    default: ndErr('無效的操作');
}
