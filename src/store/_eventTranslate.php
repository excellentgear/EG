<?php
// 公告翻譯：讀取／儲存（action=get|save）。已簽核發布的公告也可補翻譯；權限見 ni_can_translate()
header('Content-Type: application/json; charset=utf-8');
include("../../src/common/_config.php");
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/notice_i18n_lib.php';
require_once __DIR__ . '/../common/notice_doc_lib.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$action = $in['action'] ?? 'get';
$eid = (int)($in['eventid'] ?? 0);
$lang = $in['lang'] ?? 'vi';
if ($eid <= 0 || !isset(ni_langs()[$lang])) { echo json_encode(['ok' => false, 'msg' => '參數錯誤']); exit(); }

try {
    $st = $db->prepare("SELECT id, title, content, content_html, created_by FROM live_event WHERE id = ?");
    $st->execute([$eid]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ev) { echo json_encode(['ok' => false, 'msg' => '找不到公告']); exit(); }
    $features = rbac_user_features($db, $uid);
    if (!ni_can_translate($db, $uid, $ev, $features)) {
        echo json_encode(['ok' => false, 'msg' => '僅系統管理員、公告者或共同編輯者可設定翻譯版本']); exit();
    }
    ni_ensure_schema($db);

    if ($action === 'save') {
        if (empty($_SESSION['ndoc_csrf']) || !hash_equals((string)$_SESSION['ndoc_csrf'], (string)($in['csrf'] ?? ''))) {
            echo json_encode(['ok' => false, 'msg' => '連線憑證失效，請重新整理頁面']); exit();
        }
        [$okS, $msgS] = ni_save($db, $eid, $lang, (string)($in['title'] ?? ''), (string)($in['content_html'] ?? ''), $uid);
        echo json_encode(['ok' => $okS, 'msg' => $msgS]); exit();
    }

    $all = ni_get_all($db, $eid);
    echo json_encode(['ok' => true, 'orig_title' => $ev['title'], 'orig_content' => $ev['content'],
        'orig_html' => trim((string)$ev['content_html']) !== '' ? ndoc_hydrate((string)$ev['content_html']) : '',
        'title' => $all[$lang]['title'] ?? '', 'content' => $all[$lang]['content'] ?? '',
        'html' => ($all[$lang]['content_html'] ?? '') !== '' ? ndoc_hydrate($all[$lang]['content_html']) : ''], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[eventTranslate] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => '處理失敗']);
}
