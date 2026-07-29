<?php
// 手機頁用：回傳「與我相關（我是通知對象）」的公告清單與我的處理狀態。
// 判定對象方式與側邊欄鈴鐺一致（all / status / dept / user）。
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate'); // 避免手機 PWA 快取造成狀態不更新
require_once __DIR__ . '/../common/_config.php'; // session_start + $db

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];

$page = max(1, (int)($_GET['page'] ?? 1));
$size = (int)($_GET['size'] ?? 20);
if ($size < 1 || $size > 100) $size = 20;
$off = ($page - 1) * $size;

try {
    // 我的身分（含兼任 status2/status3）
    $statusIds = [-1];
    foreach (['status', 'status2', 'status3'] as $k) {
        if (isset($_SESSION[$k]) && $_SESSION[$k] !== '' && $_SESSION[$k] !== null) $statusIds[] = (int)$_SESSION[$k];
    }
    $statusIn = implode(',', array_unique($statusIds));

    // 我的部門（含兼任）
    $deptIds = [-1];
    foreach ($db->query("SELECT department_id FROM user_department_position_map WHERE user_id = " . $uid)->fetchAll(PDO::FETCH_COLUMN) as $d) {
        if ($d !== null && $d !== '') $deptIds[] = (int)$d;
    }
    $deptIn = implode(',', array_unique($deptIds));

    // 共用帳號（ai-rules/13）：登入的若是共用帳號，其成員被「指名」的通知也要列出並標「給 ○○○」。
    // 一般帳號 = 只有自己，行為與改造前完全相同。
    require_once __DIR__ . '/../common/shared_account_lib.php';
    $viewUids = eg_shared_view_uids($db, $uid);
    $viewIn   = implode(',', array_map('intval', $viewUids));
    $memberNames = eg_shared_member_names($db, $uid);   // member_uid => 姓名（非共用帳號時為空陣列）

    // 對象符合我的條件
    $match = "( t.target_type='all'
             OR (t.target_type='status' AND t.target_id IN ($statusIn))
             OR (t.target_type='dept'   AND t.target_id IN ($deptIn))
             OR (t.target_type='user'   AND t.target_id IN ($viewIn)) )";

    // 總筆數（去重）
    $total = (int)$db->query("SELECT COUNT(*) FROM (SELECT le.id FROM live_event le
                              JOIN live_event_target t ON t.live_event_id = le.id
                              WHERE $match GROUP BY le.id) x")->fetchColumn();

    // 來源參照欄位（品質異常單通知用；欄位由 qa_notify 模組建立，尚未建立時略過）
    $hasRef = false;
    try { $hasRef = (bool)$db->query("SHOW COLUMNS FROM live_event LIKE 'ref_type'")->rowCount(); } catch (Exception $e) {}
    $refCols = $hasRef ? ", le.ref_type, le.ref_id" : "";

    // 本頁事件
    $rows = $db->query("SELECT le.id, le.title, le.content, le.source, le.eventdate, le.enddate, le.reply_deadline,
                               u.user_cname AS creator $refCols
                        FROM live_event le
                        JOIN live_event_target t ON t.live_event_id = le.id
                        LEFT JOIN `user` u ON u.id = le.created_by
                        WHERE $match
                        GROUP BY le.id
                        ORDER BY le.eventdate DESC, le.id DESC
                        LIMIT $size OFFSET $off")->fetchAll(PDO::FETCH_ASSOC);

    $ids = array_column($rows, 'id');
    $modeRank = ['read' => 1, 'sign' => 2, 'reply' => 3];
    $myMode = [];   // event_id => 'read'|'sign'|'reply'
    $resp = [];     // event_id => [read_at, signed_at, replied_at]
    $readSet = [];  // event_id => true (live_event_for_user 已閱)
    $forOf = [];    // event_id => member_uid（共用帳號代收成員的通知時）
    $respAll = [];  // event_id => user_id => 回應列
    $readAll = [];  // event_id => user_id => true

    if (!empty($ids)) {
        $in = implode(',', array_map('intval', $ids));

        // 我在各事件的通知方式（取最高義務）
        $tr = $db->query("SELECT live_event_id, target_type, target_id, mode FROM live_event_target WHERE live_event_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tr as $t) {
            $tid = (int)$t['target_id'];
            $matched = ($t['target_type'] === 'all')
                || ($t['target_type'] === 'status' && in_array($tid, $statusIds, true))
                || ($t['target_type'] === 'dept'   && in_array($tid, $deptIds, true))
                || ($t['target_type'] === 'user'   && in_array($tid, $viewUids, true));
            if (!$matched) continue;
            $r = $modeRank[$t['mode']] ?? 1;
            $eid = (int)$t['live_event_id'];
            if (!isset($myMode[$eid]) || $r > $modeRank[$myMode[$eid]]) $myMode[$eid] = $t['mode'];
            // 這則是「代收成員的」→ 記下給誰的（顯示標籤、完成狀態要看該成員）
            if ($t['target_type'] === 'user' && $tid !== $uid && isset($memberNames[$tid])) $forOf[$eid] = $tid;
        }

        // 回應（回簽/回覆）：含代收成員的，取「該則負責人」的狀態
        $rs = $db->query("SELECT live_event_id, user_id, read_at, signed_at, replied_at FROM live_event_response
                          WHERE user_id IN ($viewIn) AND live_event_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs as $r) $respAll[(int)$r['live_event_id']][(int)$r['user_id']] = $r;

        // 純已閱：同上
        $fr = $db->query("SELECT live_event_id, user_id FROM live_event_for_user
                          WHERE user_id IN ($viewIn) AND oready_read=1 AND live_event_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fr as $r) $readAll[(int)$r['live_event_id']][(int)$r['user_id']] = true;

        foreach ($ids as $eid) {
            $owner = $forOf[(int)$eid] ?? $uid;    // 這則的負責人（代收＝成員本人）
            if (isset($respAll[(int)$eid][$owner])) $resp[(int)$eid] = $respAll[(int)$eid][$owner];
            if (isset($readAll[(int)$eid][$owner])) $readSet[(int)$eid] = true;
        }
    }

    $today = date('Y-m-d');
    $data = [];
    foreach ($rows as $r) {
        $eid = (int)$r['id'];
        $mode = $myMode[$eid] ?? 'read';
        $rp = $resp[$eid] ?? null;
        $hasRead = !empty($readSet[$eid]) || ($rp && !empty($rp['read_at']));
        $done = ($mode === 'read' && $hasRead)
             || ($mode === 'sign' && $rp && !empty($rp['signed_at']))
             || ($mode === 'reply' && $rp && !empty($rp['replied_at']));
        $expired = !empty($r['reply_deadline']) && $r['reply_deadline'] < $today;
        $data[] = [
            'id'        => $eid,
            'title'     => $r['title'],
            'snippet'   => mb_substr((string)$r['content'], 0, 60),
            'source'    => $r['source'] ?: '',
            'creator'   => $r['creator'] ?: '',
            'eventdate' => $r['eventdate'],
            'enddate'   => $r['enddate'],
            'reply_deadline' => $r['reply_deadline'],
            'mode'      => $mode,     // read / sign / reply
            'done'      => $done,     // 我是否已完成該義務
            'read'      => $hasRead,  // 是否已閱
            'expired'   => $expired,  // 回覆/回簽期限已過
            'ref_type'  => $r['ref_type'] ?? '',   // 'QA'=品質異常單通知 → 前端導向異常單頁
            'ref_id'    => isset($r['ref_id']) ? (int)$r['ref_id'] : 0,
            // 共用帳號代收：這則是給哪位成員的（0＝給自己）。前端顯示「給 ○○○」，回簽需輸本人密碼
            'for_uid'   => (int)($forOf[$eid] ?? 0),
            'for_name'  => isset($forOf[$eid]) ? ($memberNames[$forOf[$eid]] ?? '') : '',
        ];
    }

    echo json_encode([
        'ok' => true, 'total' => $total, 'page' => $page, 'size' => $size,
        'pages' => max(1, (int)ceil($total / $size)), 'rows' => $data,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
