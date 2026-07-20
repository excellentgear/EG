<?php
// 回傳某筆公告/通知的「已讀 / 回簽 / 回覆」人員清單與時間（供列表「已讀」點擊查看）
// 合併兩個來源：
//   live_event_for_user  → 純「已閱」模式的閱讀紀錄 (oready_read / read_at)
//   live_event_response  → 回簽 / 回覆+回簽 模式的回應紀錄 (read_at / signed_at / reply_content / replied_at)
header('Content-Type: application/json; charset=utf-8');

include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';

if (!isset($_SESSION['id'])) {
    echo json_encode(['ok' => false, 'msg' => '尚未登入']);
    exit();
}
$sessUid = (int)$_SESSION['id'];
$isAdmin = in_array('all', rbac_user_features($db, $sessUid), true); // 最高管理者（系統預設）

$event_id = isset($_GET['eventid']) ? (int)$_GET['eventid'] : 0;
if ($event_id <= 0) {
    echo json_encode(['ok' => false, 'msg' => '參數錯誤']);
    exit();
}

try {
    // user_id => 該人員的合併狀態
    $byUser = [];

    $ensure = function (&$byUser, $uid) {
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'user_id'       => $uid,
                'name'          => null,
                'read_at'       => null,
                'signed_at'     => null,
                'reply_content' => null,
                'replied_at'    => null,
                'files'         => [],
            ];
        }
        return $byUser;
    };

    // 1) 回應紀錄（回簽 / 回覆）— 含回覆附件
    $rs = $db->prepare(
        "SELECT r.id AS response_id, r.user_id, r.read_at, r.signed_at, r.reply_content, r.replied_at,
                u.user_cname, u.user_uname
         FROM live_event_response r
         LEFT JOIN user u ON u.id = r.user_id
         WHERE r.live_event_id = :eid"
    );
    $rs->bindValue(':eid', $event_id, PDO::PARAM_INT);
    $rs->execute();
    $respRows = $rs->fetchAll(PDO::FETCH_ASSOC);

    $respIds = [];
    foreach ($respRows as $r) {
        $uid = (int)$r['user_id'];
        $byUser = $ensure($byUser, $uid);
        $byUser[$uid]['name']          = $r['user_cname'] ?: ($r['user_uname'] ?: '（未知人員）');
        $byUser[$uid]['read_at']       = $r['read_at'];
        $byUser[$uid]['signed_at']     = $r['signed_at'];
        $byUser[$uid]['reply_content'] = $r['reply_content'];
        $byUser[$uid]['replied_at']    = $r['replied_at'];
        $byUser[$uid]['_response_id']  = (int)$r['response_id'];
        if (!empty($r['response_id'])) $respIds[(int)$r['response_id']] = $uid;
    }

    // 回覆附件（一次撈齊，掛回各人員）
    if (!empty($respIds)) {
        $in = implode(',', array_map('intval', array_keys($respIds)));
        foreach ($db->query("SELECT id, response_id, file_name FROM live_event_resp_file WHERE response_id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $uid = $respIds[(int)$f['response_id']] ?? null;
            if ($uid !== null) $byUser[$uid]['files'][] = [
                'id' => (int)$f['id'], 'name' => $f['file_name'],
                // 只有上傳者本人與最高管理者可刪（本人另受回覆期限限制，由後端 _respFileDelete.php 把關）
                'can_del' => ($isAdmin || $uid === $sessUid),
            ];
        }
    }

    // 2) 純「已閱」模式的閱讀紀錄（live_event_for_user）
    $stmt = $db->prepare(
        "SELECT lr.user_id, lr.read_at, u.user_cname, u.user_uname
         FROM live_event_for_user lr
         LEFT JOIN user u ON u.id = lr.user_id
         WHERE lr.live_event_id = :eid AND lr.oready_read = 1"
    );
    $stmt->bindValue(':eid', $event_id, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['user_id'];
        $byUser = $ensure($byUser, $uid);
        if ($byUser[$uid]['name'] === null) $byUser[$uid]['name'] = $r['user_cname'] ?: ($r['user_uname'] ?: '（未知人員）');
        // 若回應表沒有已閱時間，補上 live_event_for_user 的時間
        if (empty($byUser[$uid]['read_at'])) $byUser[$uid]['read_at'] = $r['read_at'];
    }

    // 整理輸出：判斷每人狀態（回覆 > 回簽 > 已閱）
    $readers = [];
    foreach ($byUser as $u) {
        if (!empty($u['replied_at']) || (isset($u['reply_content']) && trim((string)$u['reply_content']) !== '')) {
            $status = 'reply';
        } elseif (!empty($u['signed_at'])) {
            $status = 'sign';
        } else {
            $status = 'read';
        }
        $readers[] = [
            'user_id'       => (int)$u['user_id'],
            'name'          => $u['name'],
            'status'        => $status,
            'read_at'       => $u['read_at'],
            'signed_at'     => $u['signed_at'],
            'replied_at'    => $u['replied_at'],
            'reply_content' => $u['reply_content'],
            'files'         => $u['files'],
            // 排序用時間（取最近一次動作）
            '_ts'           => $u['replied_at'] ?: ($u['signed_at'] ?: $u['read_at']),
        ];
    }

    // 依最近動作時間新到舊排序（無時間者置後）
    usort($readers, function ($a, $b) {
        if (empty($a['_ts']) && empty($b['_ts'])) return strcmp((string)$a['name'], (string)$b['name']);
        if (empty($a['_ts'])) return 1;
        if (empty($b['_ts'])) return -1;
        return strcmp($b['_ts'], $a['_ts']);
    });
    foreach ($readers as &$r) unset($r['_ts']);
    unset($r);

    echo json_encode(['ok' => true, 'data' => $readers], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
