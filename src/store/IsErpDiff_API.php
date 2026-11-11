<?php
/**
 * 更新資料頁「ERP 差異確認」分頁（2026-11-11）：已綁定的出貨單在 ERP 匯入時內容不同／找不到，
 * 由系統管理員逐筆決定套用哪些欄位或保留原資料。只 UPDATE 勾選的欄位，IS_id 一律不變。
 * 權限＝與「補資料」分頁相同的系統管理員（role feature 'all'）。唯一實作 src/common/is_bind_lock_lib.php
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once __DIR__ . '/../common/DBConnection.php';
include_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/role_features_helper.php';
require_once __DIR__ . '/../common/is_bind_lock_lib.php';

function iedOut(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$uid = (int)($_SESSION['id'] ?? 0);
if (!$uid) iedOut(['ok' => false, 'error' => '未登入或連線已逾時，請重新整理頁面'], 401);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!rf_has_feature(rf_load_user_features($db, $uid), 'all')) iedOut(['ok' => false, 'error' => '只有系統管理員可以處理 ERP 差異'], 403);

if (empty($_SESSION['ied_csrf'])) $_SESSION['ied_csrf'] = bin2hex(random_bytes(16));
$action = (string)($_REQUEST['action'] ?? '');
if (in_array($action, ['apply', 'keep'], true)) {
    $tok = (string)($_POST['csrf'] ?? '');
    if ($tok === '' || !hash_equals((string)$_SESSION['ied_csrf'], $tok)) iedOut(['ok' => false, 'error' => '連線憑證失效，請重新整理頁面後再試 (CSRF)', 'code' => 'CSRF'], 403);
}

try {
    if ($action === 'list') {
        iedOut(['ok' => true, 'rows' => isbl_pending($db), 'labels' => isbl_field_labels(), 'csrf' => $_SESSION['ied_csrf']]);
    }
    if ($action === 'apply') {
        $fields = json_decode((string)($_POST['fields'] ?? '[]'), true);
        $r = isbl_apply($db, (int)($_POST['id'] ?? 0), is_array($fields) ? array_map('strval', $fields) : [], $uid);
        iedOut(['ok' => true, 'message' => '已套用 ' . count($r['fields']) . ' 個欄位（出貨單 ID 不變，綁定照舊）']);
    }
    if ($action === 'keep') {
        isbl_keep($db, (int)($_POST['id'] ?? 0), $uid);
        iedOut(['ok' => true, 'message' => '已保留原資料']);
    }
    iedOut(['ok' => false, 'error' => '不支援的動作'], 400);
} catch (Throwable $e) {
    iedOut(['ok' => false, 'error' => $e->getMessage()], 400);
}
