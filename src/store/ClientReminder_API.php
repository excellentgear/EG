<?php
// ClientReminder_API.php — 訂單追蹤「客戶提醒」專用 API（2026-09-23 使用者要求）
// 唯一實作在 src/common/order_client_reminder_lib.php，本檔只負責權限守門與參數收發。
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action !== 'download') {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/order_client_reminder_lib.php';
$db  = new DBConnection();
$pdo = $db->getPDO();
ocr_ensure_schema($pdo);

$uid   = (int)($_SESSION['id'] ?? 0);
$uname = (string)($_SESSION['userName'] ?? '');
// 顯示用姓名優先取中文名（比照本頁其他既有寫法）
try {
    $us = $pdo->prepare("SELECT user_cname FROM user WHERE id=?");
    $us->execute([$uid]);
    $cname = (string)($us->fetchColumn() ?: '');
    if ($cname !== '') $uname = $cname;
} catch (Exception $e) {}

function ocrReply(array $d) { echo json_encode($d); exit; }
function ocrDeny(string $msg = '沒有權限執行這項操作') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

switch ($action) {

    // ── 管理面板：某客戶的提醒清單（含停用），看得到本頁的人都能讀 ──────────
    case 'manage_list': {
        $cid = trim((string)($_POST['customer_id'] ?? $_GET['customer_id'] ?? ''));
        if ($cid === '') ocrReply(['success' => true, 'rows' => []]);
        $rows = ocr_list_for_customer($pdo, $cid, false);
        ocrReply(['success' => true, 'rows' => $rows, 'can_manage' => ocr_can_manage($pdo, $uid)]);
    }

    // ── 新增／編輯一則提醒（需 ot_client_reminder_manage）──────────────────
    case 'save': {
        if (!ocr_can_manage($pdo, $uid)) ocrDeny('您沒有設定客戶提醒的權限（需角色勾選「設定客戶提醒」）');
        try {
            $id = intval($_POST['id'] ?? 0);
            $cid = trim((string)($_POST['customer_id'] ?? ''));
            $cname = trim((string)($_POST['customer_name'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            $newId = ocr_save($pdo, $id, $cid, $cname, $content, $uid, $uname);
            ocrReply(['success' => true, 'id' => $newId]);
        } catch (Exception $e) {
            ocrReply(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ── 停用／恢復一則提醒（需 ot_client_reminder_manage）───────────────────
    case 'set_active': {
        if (!ocr_can_manage($pdo, $uid)) ocrDeny('您沒有設定客戶提醒的權限（需角色勾選「設定客戶提醒」）');
        $id = intval($_POST['id'] ?? 0);
        $active = intval($_POST['active'] ?? 1) ? true : false;
        $ok = ocr_set_active($pdo, $id, $active, $uid, $uname);
        ocrReply(['success' => $ok, 'message' => $ok ? '' : '查無這筆提醒']);
    }

    // ── 附件：清單（開放讀取）───────────────────────────────────────────
    case 'attach_list': {
        $rid = intval($_POST['reminder_id'] ?? $_GET['reminder_id'] ?? 0);
        ocrReply(['success' => true, 'rows' => ocr_attach_rows($pdo, $rid)]);
    }

    // ── 附件：上傳（需 ot_client_reminder_manage）───────────────────────
    case 'attach_upload': {
        if (!ocr_can_manage($pdo, $uid)) ocrDeny('您沒有設定客戶提醒的權限（需角色勾選「設定客戶提醒」）');
        try {
            $rid = intval($_POST['reminder_id'] ?? 0);
            if (empty($_FILES['file'])) throw new Exception('未選擇檔案');
            $attId = ocr_attach_add($pdo, $rid, $_FILES['file'], $uid, $uname);
            ocrReply(['success' => true, 'att_id' => $attId]);
        } catch (Exception $e) {
            ocrReply(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ── 附件：刪除（需 ot_client_reminder_manage）───────────────────────
    case 'attach_delete': {
        if (!ocr_can_manage($pdo, $uid)) ocrDeny('您沒有設定客戶提醒的權限（需角色勾選「設定客戶提醒」）');
        $attId = intval($_POST['att_id'] ?? 0);
        $ok = ocr_attach_del($pdo, $attId);
        ocrReply(['success' => $ok, 'message' => $ok ? '' : '查無這個檔案']);
    }

    // ── 附件：下載（開放讀取，路徑一律即時組＝鐵律5）──────────────────────
    case 'download': {
        $attId = intval($_GET['id'] ?? 0);
        if (!$attId) { http_response_code(404); echo '參數錯誤'; exit; }
        $row = ocr_attach_one($pdo, $attId);
        if (!$row || !is_file($row['fs_path'])) { http_response_code(404); echo '檔案不存在'; exit; }
        while (ob_get_level()) ob_end_clean();
        $ext  = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf'         => 'application/pdf',
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'xlsx', 'xls' => 'application/vnd.ms-excel',
            'docx', 'doc' => 'application/msword',
            default       => 'application/octet-stream',
        };
        $dispName = $row['orig_name'] ?: $row['file_name'];
        header('Content-Type: ' . $mime);
        require_once __DIR__ . '/../common/attach_lib.php';
        eg_attach_send_disposition($dispName);
        header('Content-Length: ' . filesize($row['fs_path']));
        readfile($row['fs_path']);
        exit;
    }

    // ── 選定/變更客戶當下：這張訂單還沒看過的有效提醒 ─────────────────────
    // order_id<=0＝新增中的訂單（尚未存檔），回傳該客戶全部有效提醒；
    // order_id>0＝既有訂單，只回傳還沒有 ack 紀錄的那些，並順手記一筆「已跳出」。
    case 'unacked': {
        $cid = trim((string)($_POST['customer_id'] ?? ''));
        $oid = intval($_POST['order_id'] ?? 0);
        if ($cid === '') ocrReply(['success' => true, 'rows' => []]);
        $rows = ocr_unacked_for_order($pdo, $cid, $oid);
        foreach ($rows as $r) {
            if ($oid > 0) ocr_ack_mark_shown($pdo, (int)$r['id'], $oid);
        }
        ocrReply(['success' => true, 'rows' => $rows]);
    }

    // ── 確認 / 設為待處理（既有訂單，order_id 必須是真的訂單）──────────────
    case 'ack': {
        $rid = intval($_POST['reminder_id'] ?? 0);
        $oid = intval($_POST['order_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        try {
            if ($status === 'confirmed') {
                ocr_ack_confirm($pdo, $rid, $oid, $uid, $uname);
                ocrReply(['success' => true, 'status' => 'confirmed']);
            } elseif ($status === 'pending') {
                $r = ocr_ack_pending($pdo, $rid, $oid, $uid, $uname);
                ocrReply(['success' => true, 'status' => 'pending', 'order_ps' => $r['order_ps'], 'inserted' => $r['inserted'], 'reason' => $r['reason']]);
            } else {
                ocrReply(['success' => false, 'message' => '不合法的狀態']);
            }
        } catch (Exception $e) {
            ocrReply(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ── 新增訂單存檔成功後，一次補寫先前在跳窗上做的決定 ───────────────────
    case 'commit_new': {
        $oid = intval($_POST['order_id'] ?? 0);
        if ($oid <= 0) ocrReply(['success' => false, 'message' => '未指定訂單']);
        $decisions = json_decode((string)($_POST['decisions'] ?? '[]'), true);
        if (!is_array($decisions)) $decisions = [];
        $out = [];
        foreach ($decisions as $d) {
            $rid = intval($d['reminder_id'] ?? 0);
            $status = (string)($d['status'] ?? '');
            if ($rid <= 0) continue;
            try {
                ocr_ack_mark_shown($pdo, $rid, $oid);
                if ($status === 'pending') {
                    $r = ocr_ack_pending($pdo, $rid, $oid, $uid, $uname);
                    $out[] = ['reminder_id' => $rid, 'ok' => true, 'inserted' => $r['inserted'], 'reason' => $r['reason']];
                } elseif ($status === 'confirmed') {
                    ocr_ack_confirm($pdo, $rid, $oid, $uid, $uname);
                    $out[] = ['reminder_id' => $rid, 'ok' => true];
                }
            } catch (Exception $e) {
                $out[] = ['reminder_id' => $rid, 'ok' => false, 'message' => $e->getMessage()];
            }
        }
        ocrReply(['success' => true, 'results' => $out]);
    }

    // ── 標記已確認完成（誰都可以，跟編輯訂單同一群人）─────────────────────
    case 'ack_resolve': {
        $ackId = intval($_POST['ack_id'] ?? 0);
        try {
            ocr_ack_resolve($pdo, $ackId, $uid, $uname);
            ocrReply(['success' => true]);
        } catch (Exception $e) {
            ocrReply(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ── 這張訂單目前待處理中的提醒（完成按鈕/挑選用）──────────────────────
    case 'pending_for_order': {
        $oid = intval($_POST['order_id'] ?? 0);
        ocrReply(['success' => true, 'rows' => ocr_pending_for_order($pdo, $oid)]);
    }

    default:
        http_response_code(400);
        ocrReply(['success' => false, 'message' => '不合法的操作']);
}
