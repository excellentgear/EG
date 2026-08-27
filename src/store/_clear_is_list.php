<?php
session_start();
header('Content-Type: application/json');

// Basic security check
if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => '使用者未登入。']);
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';
include '../../src/common/role_features_helper.php';
include '../../src/common/confirm_password_lib.php';

$uid = (int)$_SESSION['id'];
$response = ['success' => false, 'message' => '', 'deleted_rows' => 0];

// 權限：僅系統管理員或「資料急救台」管理員(data_console_edit)可清除出貨單資料（使用者明確要求 2026-08-27）
$features = rf_load_user_features($db, $uid);
if (!rf_has_feature($features, 'all') && !rf_has_feature($features, 'data_console_edit')) {
    echo json_encode(['success' => false, 'message' => '您沒有清除出貨單資料的權限（需具備資料急救台管理員資格）。']);
    exit;
}

/**
 * 依 mode/year/month 算出刪除範圍。回傳 [startDate, endDate(可為null=不設上限), errorMsg(可為null)]。
 * mode=month：該年月起之後全部清除；mode=year：該年度起之後全部清除（無上限）；
 * mode=year_only：只清該單一年度區間（有上限，不影響其他年度）。
 */
function is_list_clear_range(array $src): array {
    $mode = $src['mode'] ?? 'month';
    $year = filter_var($src['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
    if ($year === false || $year === null) return [null, null, '無效的年度參數。'];

    if ($mode === 'year') {
        return [sprintf('%04d-01-01', $year), null, null];
    }
    if ($mode === 'year_only') {
        return [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), null];
    }
    if ($mode === 'month') {
        $month = filter_var($src['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
        if ($month === false || $month === null) return [null, null, '無效的月份參數。'];
        return [sprintf('%04d-%02d-01', $year, $month), null, null];
    }
    return [null, null, '無效的清除方式。'];
}

// 預覽模式（GET，不刪除也不用密碼）：讓使用者選範圍時先看到目前符合條件的筆數，避免誤會/誤刪
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'preview') {
    [$startDate, $endDate, $err] = is_list_clear_range($_GET);
    if ($err !== null) { echo json_encode(['success' => false, 'message' => $err]); exit; }
    $where = $endDate !== null ? "Order_date BETWEEN :start_date AND :end_date" : "Order_date >= :start_date";
    $st = $db->prepare("SELECT COUNT(*) FROM is_list WHERE $where");
    $st->bindParam(':start_date', $startDate, PDO::PARAM_STR);
    if ($endDate !== null) $st->bindParam(':end_date', $endDate, PDO::PARAM_STR);
    $st->execute();
    echo json_encode(['success' => true, 'count' => (int)$st->fetchColumn()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '無效的請求方法。']);
    exit;
}

[$startDate, $endDate, $err] = is_list_clear_range($_POST);
if ($err !== null) {
    echo json_encode(['success' => false, 'message' => $err]);
    exit;
}

// 操作確認密碼（按用途分開計數，錯3次鎖定此功能7天；全站共用機制見 confirm_password_lib.php）
$pwChk = eg_confirm_password_verify_scoped($db, $uid, (string)($_POST['confirm_password'] ?? ''), 'upload_list_clear_is_list');
if (!$pwChk['ok']) {
    echo json_encode(['success' => false, 'message' => $pwChk['msg']]);
    exit;
}

try {
    $db->beginTransaction();

    // 出貨單刪除範圍：預設 Order_date >= 起始日期（不設上限，該日期起之後全部清除）；
    // year_only 模式有 $endDate，只清該單一年度區間。
    // is_bom_map（出貨-BOM對應）無 FK 約束需手動清；shipment_order_map 由 FK CASCADE 自動連鎖刪除，
    // return_order_map.IS_id 由 FK SET NULL 自動處理，兩者都不用在這裡另外寫。
    $where  = $endDate !== null ? "Order_date BETWEEN :start_date AND :end_date" : "Order_date >= :start_date";
    $bindFn = function(PDOStatement $st) use ($startDate, $endDate) {
        $st->bindParam(':start_date', $startDate, PDO::PARAM_STR);
        if ($endDate !== null) $st->bindParam(':end_date', $endDate, PDO::PARAM_STR);
    };

    $delMap = $db->prepare("DELETE FROM is_bom_map WHERE IS_id IN (SELECT IS_id FROM is_list WHERE $where)");
    $bindFn($delMap);
    $delMap->execute();

    $delList = $db->prepare("DELETE FROM is_list WHERE $where");
    $bindFn($delList);
    $delList->execute();
    $deletedRows = $delList->rowCount();

    $db->commit();

    $response['success'] = true;
    $response['message'] = "成功清除 {$deletedRows} 筆記錄。";
    $response['deleted_rows'] = $deletedRows;
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    $response['message'] = '資料庫錯誤：' . $e->getMessage();
}

echo json_encode($response);
