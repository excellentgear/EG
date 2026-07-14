<?php
// =============================================================================
// src/store/notice_attachment_API.php — 公告附件 AJAX API（配合 views/liveEvent/createEvent.php）
// 流程：
//   att_upload  → 檔案驗證後存入 {公告附件基礎路徑}/att_pending/{upload_id}/（尚未入庫）
//                 圖片/PDF：回 pending（表單送出時由 _setting.php 綁定入庫）
//                 Excel/Word：回 pending + need_convert（含工作表清單）
//   att_convert → LibreOffice 轉 PDF（Excel 可選工作表）
//   att_preview → 串流轉好的 PDF 供上傳者確認
//   att_commit  → 上傳者確認轉檔結果（只標記 confirmed；正式入庫在表單送出）
//   att_discard → 取消（刪除暫存）
//   att_set_meta→ 修改「既有」公告附件（live_event_file）的標籤/說明（寫異動 log、重建檢視快取版）
// =============================================================================
session_start();
if (!isset($_SESSION['userName'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}
if (ob_get_level()) ob_end_clean();

include_once '../common/DBConnection.php';
include_once '../common/_config.php';
require_once '../common/rbac.php';
require_once '../common/notice_files.php';
require_once '../common/attachment_lib.php';

$conn   = new DBConnection();
$db     = $conn->getPDO();
$userId = (int)($_SESSION['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$features = rbac_user_features($db, $userId);
$canUpload = rbac_has($features, 'all') || rbac_has($features, 'notice_create') || rbac_has($features, 'notice_edit');

$tempRoot = eg_notice_base($db); // pending 暫存放在附件基礎路徑下的 att_pending/

function nja_json($arr) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }

try {
    eg_att_ensure_schema($db);
    if (in_array($action, ['att_upload'], true)) eg_att_pending_sweep($tempRoot); // 順手清 24h 前的暫存

    switch ($action) {

        case 'att_upload':
            if (!$canUpload) nja_json(['success'=>false,'message'=>'您沒有公告附件上傳權限']);
            $v = eg_att_validate_upload($_FILES['file'] ?? []);
            if (!$v['ok']) nja_json(['success'=>false,'message'=>$v['msg']]);
            $p = eg_att_pending_create($tempRoot, $_FILES['file'], $v['ext']);
            if (!$p) nja_json(['success'=>false,'message'=>'暫存失敗，請確認附件儲存路徑可寫入']);
            nja_json(['success'=>true, 'pending'=>$p]);
            break;

        case 'att_convert':
            if (!$canUpload) nja_json(['success'=>false,'message'=>'無權限']);
            $uid = trim($_POST['upload_id'] ?? '');
            $sheets = json_decode($_POST['sheets'] ?? '', true);
            $pdf = eg_att_pending_convert($tempRoot, $uid, is_array($sheets) && $sheets ? $sheets : null);
            if (!$pdf) nja_json(['success'=>false,'message'=>'轉換失敗，該附件視同未完成上傳（詳見伺服器記錄）']);
            nja_json(['success'=>true]);
            break;

        case 'att_preview': // GET；串流轉好的 PDF 給預覽 iframe
            $uid = trim($_GET['upload_id'] ?? '');
            $meta = eg_att_pending_get($tempRoot, $uid);
            if (!$meta || empty($meta['pdf']) || !is_file($meta['pdf'])) { http_response_code(404); exit('not found'); }
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="preview.pdf"');
            header('Cache-Control: no-store');
            readfile($meta['pdf']);
            exit;

        case 'att_commit': // 上傳者確認轉檔結果（office 專用；圖片/PDF 不需確認）
            $uid = trim($_POST['upload_id'] ?? '');
            $meta = eg_att_pending_get($tempRoot, $uid);
            if (!$meta || empty($meta['pdf']) || !is_file($meta['pdf'])) nja_json(['success'=>false,'message'=>'找不到轉換結果，請重新上傳']);
            $meta['confirmed'] = 1;
            @file_put_contents(eg_att_pending_dir($tempRoot, $uid) . DIRECTORY_SEPARATOR . 'meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
            nja_json(['success'=>true]);
            break;

        case 'att_discard':
            $uid = trim($_POST['upload_id'] ?? '');
            eg_att_pending_discard($tempRoot, $uid);
            nja_json(['success'=>true]);
            break;

        case 'att_set_meta': // 修改既有公告附件的標籤/說明
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->prepare("SELECT f.*, e.created_by AS event_creator FROM live_event_file f JOIN live_event e ON e.id = f.live_event_id WHERE f.id=?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) nja_json(['success'=>false,'message'=>'附件不存在']);
            // 權限：管理者 / (本人建立且有 notice_edit) / 共同編輯者
            $allowed = rbac_has($features, 'all')
                || ((int)$row['event_creator'] === $userId && rbac_has($features, 'notice_edit'))
                || eg_user_is_event_editor2($db, (int)$row['live_event_id'], $userId);
            if (!$allowed) nja_json(['success'=>false,'message'=>'您沒有修改此公告附件的權限']);

            $updates = [];
            if (array_key_exists('tag_id', $_POST)) {
                $newTag = ($_POST['tag_id'] !== '' ? (int)$_POST['tag_id'] : null);
                if ($newTag !== null) {
                    $t = eg_att_tag_row($db, $newTag);
                    if (!$t || $t['scope'] !== 'announcement' || !(int)$t['is_active']) nja_json(['success'=>false,'message'=>'標籤無效']);
                }
                $updates['tag_id'] = $newTag;
            }
            if (array_key_exists('description', $_POST)) {
                $updates['description'] = mb_substr(trim((string)$_POST['description']), 0, 255, 'UTF-8');
            }
            if ($updates) {
                $set = []; $vals = [];
                foreach ($updates as $k=>$v) { $set[]="`$k`=?"; $vals[]=$v; }
                $vals[] = $id;
                $db->prepare("UPDATE live_event_file SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
                foreach ($updates as $k=>$v) {
                    if ((string)($row[$k] ?? '') !== (string)($v ?? '')) eg_att_log($db, $userId, 'event_file', $id, $k, $row[$k] ?? null, $v);
                }
                eg_att_refresh_preview($db, 'announcement', $id); // 標籤/備注變更 → 重建角落標註快取版
            }
            nja_json(['success'=>true]);
            break;

        default:
            nja_json(['success'=>false, 'message'=>'未知 action']);
    }
} catch (Throwable $e) {
    error_log('[notice_att_API] ' . $e->getMessage());
    nja_json(['success'=>false, 'message'=>'系統錯誤，請查看伺服器記錄']);
}

/** 共同編輯者檢查（同 _setting.php 的 eg_user_is_event_editor，避免跨檔相依） */
function eg_user_is_event_editor2(PDO $db, int $eventId, int $uid): bool {
    try {
        $deptIds = array_map('intval', $db->query("SELECT department_id FROM user_department_position_map WHERE user_id = " . (int)$uid)->fetchAll(PDO::FETCH_COLUMN));
        $deptIn = $deptIds ? implode(',', $deptIds) : '-1';
        $st = $db->prepare("SELECT 1 FROM live_event_editor WHERE live_event_id = ? AND ((editor_type='user' AND editor_id = ?) OR (editor_type='dept' AND editor_id IN ($deptIn))) LIMIT 1");
        $st->execute([$eventId, $uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
