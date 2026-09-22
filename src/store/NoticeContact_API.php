<?php
/**
 * 聯絡單（AS 2-DC-02-01）API —— 2026-09-22 建立
 *
 * 服務 views/liveEvent/createEvent.php 的「聯絡單」列印、列印設定、補簽與補資料。
 * 全部判定一律走 src/common/notice_contact_lib.php（唯一實作），本檔只負責守門與參數轉換。
 * 前端擋一次、後端用同一支函式再擋一次（鐵律8），不留只擋 UI 的漏洞。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/notice_contact_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/notice_event_lib.php';

header('Content-Type: application/json; charset=utf-8');

function ncOut(array $a = []) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function ncErr(string $msg, int $code = 400, array $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
// 未捕捉的例外一律轉成 JSON：否則整支 API 回空白 500，畫面上就是「按了完全沒反應也沒有錯誤訊息」
set_exception_handler(function ($e) {
    error_log('[NoticeContact_API] ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '系統錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { ncErr('DB連線失敗', 500); }

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) ncErr('尚未登入', 401);

nc_ensure_schema($db);
if (empty($_SESSION['nc_csrf'])) $_SESSION['nc_csrf'] = bin2hex(random_bytes(16));
$P = nc_perms($db, $uid);
if (!$P['canPrint']) ncErr('無公告 / 通知檢視權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* 寫入類動作先驗登入再驗 CSRF（順序不可顛倒，理由見 _config.php 的 session GC 說明） */
$WRITE = ['cfg_save', 'sign_fill', 'sign_undo', 'sign_real', 'settings_save', 'asdoc_save', 'backfill_create'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['nc_csrf'], $tok)) {
        ncErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code' => 'CSRF']);
    }
}
function ncReq(bool $ok, string $what) { if (!$ok) ncErr('您沒有「' . $what . '」的權限', 403); }

/** 這則公告存不存在（並回傳基本欄位） */
function ncEvent(PDO $db, int $eid): array {
    $st = $db->prepare("SELECT id, eventdate, title, created_by, source FROM live_event WHERE id=?");
    $st->execute([$eid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) ncErr('找不到這則公告 / 通知', 404);
    return $r;
}

switch ($action) {

    /* 頁面載入用：權限、設定、可選清單 */
    case 'meta': {
        $s = nc_settings($db);
        ncOut([
            'csrf'      => $_SESSION['nc_csrf'],
            'perms'     => $P,
            'settings'  => $s,
            'stamps'    => $P['canSetting'] ? nc_stamp_template_list($db) : [],
            'as_docs'   => $P['canSetting'] ? eg_asdoc_list($db) : [],
            'as_doc'    => eg_asdoc_get($db, NC_ASDOC_MODULE),
            'stamp_tpl' => nc_stamp_template($db),
        ]);
    }

    /* 一張聯絡單的全部列印資料。alloc=1 才配聯絡單號（＝真的要印，沒印過的公告不佔號） */
    case 'print_data': {
        $eid = (int)($_GET['eventid'] ?? $_POST['eventid'] ?? 0);
        ncEvent($db, $eid);
        $alloc = !empty($_GET['alloc']) || !empty($_POST['alloc']);
        $d = nc_print_data($db, $eid, $alloc);
        if (empty($d['ok'])) ncErr($d['msg'] ?? '讀取失敗');
        ncOut(['data' => $d, 'perms' => $P, 'csrf' => $_SESSION['nc_csrf']]);
    }

    /* 逐則列印設定（受文者 / 製表 / 核准 / 要印誰 / 要不要印回覆內容） */
    case 'cfg_save': {
        ncReq($P['canSign'], '聯絡單列印補簽 / 指定簽章人員');
        $eid = (int)($_POST['eventid'] ?? 0);
        ncEvent($db, $eid);
        $in = [];
        foreach (['from_text', 'to_text', 'maker_user_id', 'maker_date', 'approver_user_id', 'approver_date', 'show_reply'] as $k) {
            if (array_key_exists($k, $_POST)) $in[$k] = $_POST[$k];
        }
        if (array_key_exists('include_uids', $_POST)) {
            $v = (string)$_POST['include_uids'];
            $in['include_uids'] = ($v === '') ? null : (json_decode($v, true) ?: []);
        }
        // 日期一樣要擋（前端擋一次、這裡同規則再擋一次）
        $ev = ncEvent($db, $eid);
        foreach (['maker_date', 'approver_date'] as $k) {
            if (!empty($in[$k])) {
                [$ok, $msg] = nc_sign_date_check((string)$in[$k], (string)$ev['eventdate']);
                if (!$ok) ncErr(($k === 'maker_date' ? '製表' : '核准') . '日期：' . $msg);
            }
        }
        nc_cfg_save($db, $eid, $in, $uid);
        ncOut(['data' => nc_print_data($db, $eid, false)]);
    }

    /* 列印用補簽（一般公告）：只影響紙上的章，不動回簽紀錄 */
    case 'sign_fill': {
        ncReq($P['canSign'], '聯絡單列印補簽');
        $eid  = (int)($_POST['eventid'] ?? 0);
        $date = trim((string)($_POST['sign_date'] ?? ''));
        $list = json_decode((string)($_POST['user_ids'] ?? '[]'), true);
        if (!is_array($list) || !$list) ncErr('請選擇要補簽的人員');
        ncEvent($db, $eid);
        $cfg  = nc_cfg($db, $eid);
        $back = (int)$cfg['is_backfill'] === 1;

        $done = []; $fail = [];
        foreach (array_unique(array_map('intval', $list)) as $u) {
            // 補資料模式的單，補簽就是真的寫回簽紀錄（使用者明確定調）
            $r = $back ? nc_sign_real($db, $eid, $u, $date, $uid) : nc_sign_fill($db, $eid, $u, $date, $uid);
            if ($r[0]) $done[] = $u; else $fail[] = ['user_id' => $u, 'msg' => $r[1]];
        }
        ncOut(['done' => $done, 'fail' => $fail, 'real' => $back, 'data' => nc_print_data($db, $eid, false)]);
    }

    /* 取消列印用補簽（補資料模式寫進去的是真實回簽，不從這裡撤，避免誤刪原始資料） */
    case 'sign_undo': {
        ncReq($P['canSign'], '聯絡單列印補簽');
        $eid = (int)($_POST['eventid'] ?? 0);
        $u   = (int)($_POST['user_id'] ?? 0);
        ncEvent($db, $eid);
        nc_sign_fill_del($db, $eid, $u, $uid);
        ncOut(['data' => nc_print_data($db, $eid, false)]);
    }

    /* 模組設定（圖章型式、預設製表 / 核准來源） */
    case 'settings_save': {
        ncReq($P['canSetting'], '聯絡單設定');
        $in = [];
        foreach (array_keys(nc_setting_defaults()) as $k) if (array_key_exists($k, $_POST)) $in[$k] = $_POST[$k];
        // 指定人員時一定要真的有這個人，否則設定看起來存好了、列印卻永遠印不出章
        foreach ([['maker_src', 'maker_user_id', '製表人'], ['approver_src', 'approver_user_id', '核准']] as $c) {
            if (($in[$c[0]] ?? '') === 'user') {
                $x = (int)($in[$c[1]] ?? 0);
                if ($x <= 0) ncErr('請指定' . $c[2] . '人員');
                $st = $db->prepare("SELECT COUNT(*) FROM user WHERE id=?");
                $st->execute([$x]);
                if (!(int)$st->fetchColumn()) ncErr($c[2] . '人員不存在');
            }
        }
        if (!empty($in['stamp_tpl_id'])) {
            $st = $db->prepare("SELECT COUNT(*) FROM stamp_template WHERE id=? AND is_active=1");
            $st->execute([(int)$in['stamp_tpl_id']]);
            if (!(int)$st->fetchColumn()) ncErr('選擇的圖章型式不存在或已停用');
        }
        nc_settings_save($db, $in, (string)($_SESSION['user_cname'] ?? $uid));
        ncOut(['settings' => nc_settings($db), 'stamp_tpl' => nc_stamp_template($db)]);
    }

    /* AS 文件編號綁定（走全站唯一實作 asdoc_lib） */
    case 'asdoc_save': {
        ncReq($P['canSetting'], '聯絡單設定');
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($docId > 0) {
            $st = $db->prepare("SELECT COUNT(*) FROM as_document WHERE id=? AND is_deleted=0");
            $st->execute([$docId]);
            if (!(int)$st->fetchColumn()) ncErr('選擇的 AS 文件不存在');
        }
        eg_asdoc_save($db, NC_ASDOC_MODULE, $docId, (string)($_SESSION['user_cname'] ?? $uid));
        ncOut(['as_doc' => eg_asdoc_get($db, NC_ASDOC_MODULE)]);
    }

    /* 補資料：建立一張完全不發通知的聯絡單 */
    case 'backfill_create': {
        ncReq($P['canBackfill'], '聯絡單補資料');
        $targets = json_decode((string)($_POST['targets'] ?? '[]'), true);
        [$ok, $msg, $eid] = nc_backfill_create($db, [
            'eventdate'    => $_POST['eventdate'] ?? '',
            'title'        => $_POST['title'] ?? '',
            'content'      => $_POST['content'] ?? '',
            'from_user_id' => $_POST['from_user_id'] ?? 0,
            'source'       => $_POST['source'] ?? '',
            'mode'         => $_POST['mode'] ?? 'sign',
            'targets'      => is_array($targets) ? $targets : [],
        ], $uid);
        if (!$ok) ncErr($msg);
        ncOut(['event_id' => $eid, 'data' => nc_print_data($db, $eid, false)]);
    }

    /* 挑人用清單（依聯絡單日期回推當時在職者與當時職稱＝ai-rules/22 第5坑） */
    case 'people': {
        $date = trim((string)($_GET['date'] ?? ''));
        $rows = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)
              ? eg_people_list_asof($db, [], $date) : eg_people_list($db, []);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r['id'], 'name' => (string)($r['user_cname'] ?: $r['user_uname']),
                      'dept' => (string)($r['dept_name'] ?? ''), 'position' => (string)($r['position_name'] ?? '')];
        }
        ncOut(['people' => $out]);
    }

    /* 受文者可選對象（全體 / 部門 / 身分 / 人員）——補資料建立聯絡單用 */
    case 'targets': {
        $dept = $db->query("SELECT id, name FROM department ORDER BY COALESCE(sort_order,999), id")->fetchAll(PDO::FETCH_ASSOC);
        $sts  = $db->query("SELECT id, title FROM user_status ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $ppl  = [];
        foreach (eg_people_list($db, []) as $r) {
            $ppl[] = ['id' => (int)$r['id'], 'name' => (string)($r['user_cname'] ?: $r['user_uname']),
                      'dept' => (string)($r['dept_name'] ?? ''), 'position' => (string)($r['position_name'] ?? '')];
        }
        ncOut(['depts' => $dept, 'statuses' => $sts, 'people' => $ppl]);
    }

    default:
        ncErr('無效的操作');
}
