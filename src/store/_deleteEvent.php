<?php
 if (!isset($_SESSION)){
    session_start();
    }

include('../../src/common/DBConnection.php');

$conn = new DBConnection();

// ajax=1：由列表頁非同步呼叫（刪除後只重載列表、不整頁跳轉，畫面才不會捲回最上面）
$__ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1';
/** 統一出口：ajax 回 JSON，一般模式維持原本的 header 轉址 */
function eg_del_exit($ajax, $ok, $msg, $location)
{
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        header("location:" . $location);
    }
    exit();
}

// 角色權限把關：需有 notice_delete 才能刪除
require_once __DIR__ . '/../common/rbac.php';
$__uid = (int)($_SESSION['id'] ?? 0);
$__nf = rbac_user_features($conn->getPDO(), $__uid);
if (!rbac_has($__nf, 'notice_delete')) {
    eg_del_exit($__ajax, false, '您沒有刪除公告／通知的權限', '../../views/liveEvent/createEvent.php');
}

$eventId = (int)$_GET['eventid'];
$pdo = $conn->getPDO();

// 只有系統角色（管理員）可刪除任何公告；其他人僅能刪除本人建立的公告
if (!rbac_has($__nf, 'all')) {
    $__cb = $pdo->prepare("SELECT created_by FROM live_event WHERE id = ?");
    $__cb->execute([$eventId]);
    if ((int)$__cb->fetchColumn() !== $__uid) {
        eg_del_exit($__ajax, false, '您不是此公告的公告者，僅系統管理員可刪除他人公告。', '../../views/liveEvent/createEvent.php?denied=1');
    }
}

// 鎖定：來源『訂單變更』的通知禁止在此刪除（單一真相來源為 order_change_log，請至訂單頁作廢變更單連動移除）
$lk = $pdo->prepare("SELECT source FROM live_event WHERE id = ?");
$lk->execute([$eventId]);
if ($lk->fetchColumn() === '訂單變更') {
    eg_del_exit($__ajax, false, '此通知由「訂單變更」產生已鎖定，不可在此刪除；如需移除請至訂單追蹤頁作廢該變更單（會連動移除通知）。', '../../views/liveEvent/createEvent.php?locked=1');
}

// 刪除實體附件檔（公告附件 + 回覆附件）
require_once __DIR__ . '/../common/notice_files.php';
$fq = $pdo->prepare("SELECT file_path FROM live_event_file WHERE live_event_id = ?");
$fq->execute([$eventId]);
$paths = $fq->fetchAll(PDO::FETCH_COLUMN);
$rq = $pdo->prepare("SELECT rf.file_path FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id = ?");
$rq->execute([$eventId]);
$paths = array_merge($paths, $rq->fetchAll(PDO::FETCH_COLUMN));
foreach ($paths as $p) { $abs = eg_notice_abs_path($p); if ($abs && is_file($abs)) @unlink($abs); }

// 收回先前發出的 Telegram 通知訊息（2026-07-07 恢復啟用；改寫為「已刪除」提示、移除按鈕；失敗不影響刪除流程）
try {
    require_once __DIR__ . '/../../telegram/notify_event.php';
    eg_telegram_retract_event($pdo, $eventId, true);
} catch (Throwable $e) {
    error_log('[telegram] retract on delete failed: ' . $e->getMessage());
}

// 刪除關聯資料
$pdo->prepare("DELETE rf FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id = ?")->execute([$eventId]);
$conn->execute("DELETE FROM live_event_response WHERE live_event_id=" . $eventId);
$conn->execute("DELETE FROM live_event_file WHERE live_event_id=" . $eventId);
$conn->execute("DELETE FROM live_event_target WHERE live_event_id=" . $eventId);
try { $conn->execute("DELETE FROM live_event_editor WHERE live_event_id=" . $eventId); } catch (Throwable $e) { /* 表不存在時忽略 */ }
$subjects = $conn->execute("DELETE FROM live_event WHERE id=" . $eventId);

unset($_SESSION['eventid']);
unset($_SESSION['eventdate']);
unset($_SESSION['enddate']);
unset($_SESSION['title']);
unset($_SESSION['content']);
unset($_SESSION['eventstatus']);

eg_del_exit($__ajax, true, '', "../../views/liveEvent/createEvent.php?ev=" . (int)$eventId);
