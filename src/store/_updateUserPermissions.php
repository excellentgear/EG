<?php
session_start();
// 確保載入資料庫連線與設定
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/_config.php';

// 設定回應為 JSON 格式，以便 AJAX 正確處理
header('Content-Type: application/json');
$response = ['success' => false, 'message' => '發生未知錯誤。'];

// 檢查表單是否已提交
if (isset($_POST['updatePermissions']) && isset($_POST['userid'])) {

    $userid_to_update = $_POST['userid'];
    $permissions_from_post = $_POST['permissions'] ?? [];
    $current_admin_id = $_SESSION['id'] ?? 'system'; // 執行更新的管理員ID

    try {
        $dbConn = new DBConnection();
        $pdo = $dbConn->getPDO();

        // 開始交易
        $pdo->beginTransaction();

        // 1. 刪除該使用者現有的所有模組權限
        $deleteSql = "DELETE FROM user_module_permissions WHERE user_id = :user_id";
        $deleteStmt = $pdo->prepare($deleteSql);
        $deleteStmt->bindValue(':user_id', $userid_to_update, PDO::PARAM_INT);
        $deleteStmt->execute();

        // 2. 插入新的權限
        // 增加 scope, created_at, updated_at 欄位
        $insertSql = "INSERT INTO user_module_permissions (user_id, module_code, permission, scope, created_at, updated_at) VALUES (:user_id, :module_code, :permission, :scope, NOW(), NOW())";
        $insertStmt = $pdo->prepare($insertSql);

        // 定義權限字元排序順序，確保儲存格式一致 (如 "ACRUD")
        $sortOrder = ['A' => 1, 'C' => 2, 'R' => 3, 'U' => 4, 'D' => 5];

        // 處理 Group (模組) 權限
        if (isset($permissions_from_post['group']) && is_array($permissions_from_post['group'])) {
            foreach ($permissions_from_post['group'] as $module_code => $perms) {
                if (is_array($perms) && !empty($perms)) {
                    usort($perms, function($a, $b) use ($sortOrder) {
                        return ($sortOrder[$a] ?? 99) - ($sortOrder[$b] ?? 99);
                    });
                    $permString = implode('', $perms);

                    $insertStmt->bindValue(':user_id', $userid_to_update, PDO::PARAM_INT);
                    $insertStmt->bindValue(':module_code', $module_code, PDO::PARAM_STR);
                    $insertStmt->bindValue(':permission', $permString, PDO::PARAM_STR);
                    $insertStmt->bindValue(':scope', 'group', PDO::PARAM_STR);
                    $insertStmt->execute();
                }
            }
        }

        // 處理 Page (子頁面) 權限
        if (isset($permissions_from_post['page']) && is_array($permissions_from_post['page'])) {
            foreach ($permissions_from_post['page'] as $page_id => $perms) {
                if (is_array($perms) && !empty($perms)) {
                    usort($perms, function($a, $b) use ($sortOrder) {
                        return ($sortOrder[$a] ?? 99) - ($sortOrder[$b] ?? 99);
                    });
                    $permString = implode('', $perms);

                    $insertStmt->bindValue(':user_id', $userid_to_update, PDO::PARAM_INT);
                    // 當 scope=page 時，module_code 欄位存放 page_id
                    $insertStmt->bindValue(':module_code', $page_id, PDO::PARAM_STR);
                    $insertStmt->bindValue(':permission', $permString, PDO::PARAM_STR);
                    $insertStmt->bindValue(':scope', 'page', PDO::PARAM_STR);
                    $insertStmt->execute();
                }
            }
        }

        // 提交交易
        $pdo->commit();

        $response['success'] = true;
        $response['message'] = "權限更新成功。";

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['message'] = "資料庫操作錯誤：" . $e->getMessage();
        // 建議在伺服器日誌中記錄詳細錯誤，方便排查問題
        error_log("權限更新失敗: " . $e->getMessage());
    }

    // 將結果以 JSON 格式回傳給前端
    echo json_encode($response);
    exit();
} else {
    $response['message'] = '無效的請求或缺少參數。';
    echo json_encode($response);
    exit();
}
?>