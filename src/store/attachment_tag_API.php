<?php
// =============================================================================
// src/store/attachment_tag_API.php — 附件標籤系統 API
// 標籤分兩組 scope：announcement（公告）/ abnormal（異常單），互不混用。
// 只有主管角色可管理標籤：
//   - announcement：管理者(all) 或 具 notice_tag_manage 功能
//   - abnormal    ：管理者(all) 或 具 qc_supervisor 功能
// 一般使用者僅能 list（供上傳時選擇）。
// 所有開關/名稱/預設變更皆寫 tag_change_logs（誰、何時、舊值→新值）。
// =============================================================================
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
if (!isset($_SESSION['userName'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

include_once '../common/DBConnection.php';
include_once '../common/_config.php';
require_once '../common/rbac.php';
require_once '../common/attachment_lib.php';

$conn   = new DBConnection();
$db     = $conn->getPDO();
$userId = (int)($_SESSION['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$scope  = $_POST['scope'] ?? $_GET['scope'] ?? '';

function att_tag_scope_ok(string $s): bool { return in_array($s, ['announcement', 'abnormal'], true); }

/** 是否具該 scope 的標籤管理權（主管） */
function att_tag_can_manage(PDO $db, int $userId, string $scope): bool {
    try {
        $f = rbac_user_features($db, $userId);
        if (rbac_has($f, 'all')) return true;
        if ($scope === 'announcement') return rbac_has($f, 'notice_tag_manage');
        if ($scope === 'abnormal')     return rbac_has($f, 'qc_supervisor');
    } catch (Throwable $e) { error_log('[att_tag] rbac check failed: ' . $e->getMessage()); }
    return false;
}

try {
    eg_att_ensure_schema($db);

    switch ($action) {

        // ─── 標籤清單（一般使用者：僅啟用中；manage=1 時回全部＋管理權標記）───
        case 'list':
            if (!att_tag_scope_ok($scope)) { echo json_encode(['success'=>false,'message'=>'scope 錯誤']); break; }
            $manage = !empty($_POST['manage']) || !empty($_GET['manage']);
            $canManage = att_tag_can_manage($db, $userId, $scope);
            $tags = eg_att_tags($db, $scope, !($manage && $canManage));
            echo json_encode([
                'success'    => true,
                'data'       => $tags,
                'can_manage' => $canManage,
                'default_id' => eg_att_default_tag_id($db, $scope),
            ]);
            break;

        // ─── 新增標籤（主管）────────────────────────────────────
        case 'add':
            if (!att_tag_scope_ok($scope)) { echo json_encode(['success'=>false,'message'=>'scope 錯誤']); break; }
            if (!att_tag_can_manage($db, $userId, $scope)) { echo json_encode(['success'=>false,'message'=>'僅主管可管理標籤']); break; }
            $name = trim($_POST['name'] ?? '');
            if ($name === '' || mb_strlen($name, 'UTF-8') > 50) { echo json_encode(['success'=>false,'message'=>'標籤名稱需為 1–50 字']); break; }
            $aw = !empty($_POST['allow_webpush']) ? 1 : 0;
            $at = !empty($_POST['allow_telegram']) ? 1 : 0;
            $rw = isset($_POST['require_watermark']) ? (!empty($_POST['require_watermark']) ? 1 : 0) : 1;
            $db->prepare("INSERT INTO attachment_tags (scope, name, allow_webpush, allow_telegram, require_watermark, is_default, is_active, created_by)
                          VALUES (?,?,?,?,?,0,1,?)")->execute([$scope, $name, $aw, $at, $rw, $userId]);
            $newId = (int)$db->lastInsertId();
            eg_att_log($db, $userId, 'tag', $newId, 'create', null, "$name (4G=$aw,TG=$at,浮水印=$rw)");
            echo json_encode(['success'=>true, 'id'=>$newId]);
            break;

        // ─── 修改標籤（名稱 / 三個開關 / 啟用停用；主管）────────
        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $tag = $id ? eg_att_tag_row($db, $id) : null;
            if (!$tag) { echo json_encode(['success'=>false,'message'=>'標籤不存在']); break; }
            if (!att_tag_can_manage($db, $userId, $tag['scope'])) { echo json_encode(['success'=>false,'message'=>'僅主管可管理標籤']); break; }

            $fields = [];
            if (isset($_POST['name'])) {
                $name = trim($_POST['name']);
                if ($name === '' || mb_strlen($name, 'UTF-8') > 50) { echo json_encode(['success'=>false,'message'=>'標籤名稱需為 1–50 字']); break; }
                $fields['name'] = $name;
            }
            foreach (['allow_webpush', 'allow_telegram', 'require_watermark', 'is_active'] as $k) {
                if (isset($_POST[$k])) $fields[$k] = !empty($_POST[$k]) ? 1 : 0;
            }
            // 預設標籤保護：require_watermark 必須保持開啟；不可停用
            if ((int)$tag['is_default'] === 1) {
                if (isset($fields['require_watermark']) && $fields['require_watermark'] === 0) {
                    echo json_encode(['success'=>false,'message'=>'預設標籤的浮水印開關必須保持開啟']); break;
                }
                if (isset($fields['is_active']) && $fields['is_active'] === 0) {
                    echo json_encode(['success'=>false,'message'=>'預設標籤不可停用，請先將預設改指定給其他標籤']); break;
                }
            }
            if (!$fields) { echo json_encode(['success'=>true]); break; }

            $set = []; $vals = [];
            foreach ($fields as $k => $v) { $set[] = "`$k`=?"; $vals[] = $v; }
            $vals[] = $id;
            $db->prepare("UPDATE attachment_tags SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
            foreach ($fields as $k => $v) {
                if ((string)$tag[$k] !== (string)$v) eg_att_log($db, $userId, 'tag', $id, $k, $tag[$k], $v);
            }
            echo json_encode(['success'=>true]);
            break;

        // ─── 指定預設標籤（主管；每 scope 僅一個；預設標籤浮水印強制開）─
        case 'set_default':
            $id = (int)($_POST['id'] ?? 0);
            $tag = $id ? eg_att_tag_row($db, $id) : null;
            if (!$tag) { echo json_encode(['success'=>false,'message'=>'標籤不存在']); break; }
            if (!att_tag_can_manage($db, $userId, $tag['scope'])) { echo json_encode(['success'=>false,'message'=>'僅主管可管理標籤']); break; }
            if ((int)$tag['is_active'] !== 1) { echo json_encode(['success'=>false,'message'=>'停用中的標籤不可設為預設']); break; }
            $oldDefault = eg_att_default_tag_id($db, $tag['scope']);
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE attachment_tags SET is_default=0 WHERE scope=?")->execute([$tag['scope']]);
                // 預設標籤的 require_watermark 必須開啟（規格 6-6）
                $db->prepare("UPDATE attachment_tags SET is_default=1, require_watermark=1 WHERE id=?")->execute([$id]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            eg_att_log($db, $userId, 'tag', $id, 'is_default', $oldDefault, $id);
            echo json_encode(['success'=>true]);
            break;

        // ─── 標籤異動紀錄（主管）────────────────────────────────
        case 'logs':
            if (!att_tag_scope_ok($scope)) { echo json_encode(['success'=>false,'message'=>'scope 錯誤']); break; }
            if (!att_tag_can_manage($db, $userId, $scope)) { echo json_encode(['success'=>false,'message'=>'僅主管可查看']); break; }
            $st = $db->prepare("SELECT l.*, u.user_cname AS actor_name, t.name AS tag_name
                                FROM tag_change_logs l
                                LEFT JOIN `user` u ON u.id = l.actor_id
                                LEFT JOIN attachment_tags t ON (l.target_type='tag' AND t.id = l.target_id)
                                WHERE (l.target_type='tag' AND l.target_id IN (SELECT id FROM attachment_tags WHERE scope=?))
                                   OR (l.target_type = ?)
                                ORDER BY l.id DESC LIMIT 200");
            $st->execute([$scope, $scope === 'announcement' ? 'event_file' : 'qa_att']);
            echo json_encode(['success'=>true, 'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            echo json_encode(['success'=>false, 'message'=>'未知 action']);
    }
} catch (Throwable $e) {
    error_log('[att_tag_API] ' . $e->getMessage());
    echo json_encode(['success'=>false, 'message'=>'系統錯誤，請查看伺服器記錄']);
}
