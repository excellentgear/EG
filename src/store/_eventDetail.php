<?php
// 被通知者跳窗用：取得公告詳情、附件、我的通知方式與狀態、(可互看時)他人狀態
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate'); // 避免手機 PWA 快取造成回覆後狀態不更新
include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/notice_files.php';
require_once __DIR__ . '/../common/rbac.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];
$isAdmin = in_array('all', rbac_user_features($db, $uid), true); // 最高管理者（系統預設）
$eid = (int)($_GET['eventid'] ?? 0);
if ($eid <= 0) { echo json_encode(['ok' => false, 'msg' => '參數錯誤']); exit(); }

try {
    $ev = $db->prepare("SELECT le.*, u.user_cname AS creator_name FROM live_event le LEFT JOIN user u ON u.id = le.created_by WHERE le.id = ?");
    $ev->execute([$eid]);
    $event = $ev->fetch(PDO::FETCH_ASSOC);
    if (!$event) { echo json_encode(['ok' => false, 'msg' => '找不到公告']); exit(); }

    // 本人身分/部門
    $myStatus = [-1];
    foreach (['status', 'status2', 'status3'] as $sk) if (!empty($_SESSION[$sk])) $myStatus[] = (int)$_SESSION[$sk];
    $myDept = [-1];
    $ds = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id = ?");
    $ds->execute([$uid]);
    foreach ($ds->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d !== null) $myDept[] = (int)$d;

    // 本人對此公告的通知方式（符合的對象中，取最高義務 reply>sign>read）
    $ts = $db->prepare("SELECT target_type, target_id, mode FROM live_event_target WHERE live_event_id = ?");
    $ts->execute([$eid]);
    $rank = ['read' => 1, 'sign' => 2, 'reply' => 3];
    $myModeRank = 0;
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $match = ($t['target_type'] === 'all')
            || ($t['target_type'] === 'status' && in_array((int)$t['target_id'], $myStatus, true))
            || ($t['target_type'] === 'dept'   && in_array((int)$t['target_id'], $myDept, true))
            || ($t['target_type'] === 'user'   && (int)$t['target_id'] === $uid);
        if ($match) { $r = $rank[$t['mode']] ?? 1; if ($r > $myModeRank) $myModeRank = $r; }
    }
    $myMode = $myModeRank >= 3 ? 'reply' : ($myModeRank === 2 ? 'sign' : 'read');

    // 公告附件（含標籤名稱與備注；附件標籤系統 2026-07-07 新增）
    try {
        $af = $db->prepare("SELECT f.id, f.file_name, f.description, t.name AS tag_name
                            FROM live_event_file f LEFT JOIN attachment_tags t ON t.id = f.tag_id
                            WHERE f.live_event_id = ? ORDER BY f.id");
        $af->execute([$eid]);
        $afRows = $af->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { // 欄位/表尚未建立時退回舊查詢
        $af = $db->prepare("SELECT id, file_name FROM live_event_file WHERE live_event_id = ? ORDER BY id");
        $af->execute([$eid]);
        $afRows = $af->fetchAll(PDO::FETCH_ASSOC);
    }
    $attachments = [];
    foreach ($afRows as $f) {
        $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
        $attachments[] = [
            'id' => (int)$f['id'], 'name' => $f['file_name'], 'ext' => $ext,
            'previewable' => eg_notice_is_previewable($ext),
            'tag' => $f['tag_name'] ?? '', 'description' => $f['description'] ?? '',
        ];
    }

    // 我的回覆/回簽/已閱狀態
    $rs = $db->prepare("SELECT * FROM live_event_response WHERE live_event_id = ? AND user_id = ?");
    $rs->execute([$eid, $uid]);
    $mine = $rs->fetch(PDO::FETCH_ASSOC);
    $myFiles = [];
    if ($mine) {
        $mf = $db->prepare("SELECT id, file_name FROM live_event_resp_file WHERE response_id = ? ORDER BY id");
        $mf->execute([$mine['id']]);
        foreach ($mf->fetchAll(PDO::FETCH_ASSOC) as $f) $myFiles[] = ['id' => (int)$f['id'], 'name' => $f['file_name']];
    }

    $deadlinePassed = !empty($event['reply_deadline']) && $event['reply_deadline'] < date('Y-m-d');
    // 通知已結束(2026-08-26新增)：各模組在「事情已經辦完/已撤回」時會把 enddate 設成昨天把通知關掉
    // （會議記錄項目任一人回簽完成、簽核已核准、送審已撤回…全站 14 個模組都是同一種寫法）。
    // 置頂鈴鐺本來就會濾掉這種通知，但從「我的通知」進來的人仍看得到完整回覆表單、
    // 以為系統還在等他回覆——所以這裡把狀態一併回給前端，讓畫面直接標示「已結束、不需再回覆」。
    $closed = !empty($event['enddate']) && $event['enddate'] < date('Y-m-d');

    // 他人狀態（僅在 show_status_to_others 開啟時）
    $others = [];
    if (!empty($event['show_status_to_others'])) {
        $os = $db->prepare("SELECT r.user_id, r.read_at, r.signed_at, r.reply_content, r.replied_at, u.user_cname
                            FROM live_event_response r LEFT JOIN user u ON u.id = r.user_id
                            WHERE r.live_event_id = ? AND r.user_id <> ? ORDER BY r.replied_at DESC, r.signed_at DESC, r.read_at DESC");
        $os->execute([$eid, $uid]);
        foreach ($os->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $of = $db->prepare("SELECT rf.id, rf.file_name FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id = ? AND r.user_id = ?");
            $of->execute([$eid, $o['user_id']]);
            $others[] = [
                'name' => $o['user_cname'] ?: ('#' . $o['user_id']),
                'read_at' => $o['read_at'], 'signed_at' => $o['signed_at'],
                'reply_content' => $o['reply_content'], 'replied_at' => $o['replied_at'],
                'files' => $of->fetchAll(PDO::FETCH_ASSOC),
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'event' => [
            'id' => (int)$event['id'], 'event_no' => $event['event_no'], 'title' => $event['title'],
            'content' => $event['content'], 'source' => $event['source'], 'creator' => $event['creator_name'],
            'eventdate' => $event['eventdate'], 'enddate' => $event['enddate'], 'reply_deadline' => $event['reply_deadline'],
            'ref_type' => $event['ref_type'] ?? '', 'ref_id' => isset($event['ref_id']) ? (int)$event['ref_id'] : 0,
        ],
        'attachments' => $attachments,
        'my_mode' => $myMode,
        'my_status' => $mine ? [
            'read_at' => $mine['read_at'], 'signed_at' => $mine['signed_at'],
            'reply_content' => $mine['reply_content'], 'replied_at' => $mine['replied_at'], 'files' => $myFiles,
        ] : null,
        'deadline_passed' => $deadlinePassed,
        'closed' => $closed,
        'show_status' => !empty($event['show_status_to_others']),
        'others' => $others,
        // 回覆附件刪除權限（2026-07-07）：本人附件（期限內）可刪；管理者可刪任何人的且不受期限限制
        'is_admin' => $isAdmin,
        'can_del_my' => $isAdmin || !$deadlinePassed,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
