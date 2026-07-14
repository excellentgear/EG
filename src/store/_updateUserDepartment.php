<?php
session_start();

// 確保載入資料庫連線與設定
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/_config.php';

// 設定回應為 JSON 格式
header('Content-Type: application/json');
$response = ['success' => false, 'message' => '發生未知錯誤。'];

// 檢查表單是否已提交且使用者已登入
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['userid']) && isset($_SESSION['id'])) {

    $userid_to_update = $_POST['userid'];
    $status1 = !empty($_POST['user_status']) ? $_POST['user_status'] : null;
    $status2 = !empty($_POST['user_status2']) ? $_POST['user_status2'] : null;
    $status3 = !empty($_POST['user_status3']) ? $_POST['user_status3'] : null;
    $current_admin_id = $_SESSION['id'];

    try {
        // 準備 SQL UPDATE 陳述式
        $sql = "
            UPDATE user 
            SET 
                user_status = :status1, 
                user_status2 = :status2, 
                user_status3 = :status3,
                modified_by = :admin_id,
                modified_date = NOW()
            WHERE id = :userid
        ";

        $stmt = $db->prepare($sql);

        // 綁定參數
        $stmt->bindValue(':status1', $status1, $status1 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':status2', $status2, $status2 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':status3', $status3, $status3 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':admin_id', $current_admin_id, PDO::PARAM_INT);
        $stmt->bindValue(':userid', $userid_to_update, PDO::PARAM_INT);

        // 執行更新
        if ($stmt->execute()) {
            // 更新成功後，獲取新的顯示文字
            $sql_fetch = "
                SELECT 
                    u.user_status,
                    u.user_status2,
                    u.user_status3,
                    sr1.status_id AS status_id_1,
                    CONCAT(sr1.department_name, '-', sr1.position_title) AS status_title_1,
                    CONCAT(sr2.department_name, '-', sr2.position_title, ' (兼任)') AS status_title_2,
                    CONCAT(sr3.department_name, '-', sr3.position_title, ' (兼任)') AS status_title_3
                FROM `user` u
                LEFT JOIN `status_roles` sr1 ON CAST(u.user_status AS UNSIGNED) = sr1.status_id
                LEFT JOIN `status_roles` sr2 ON CAST(u.user_status2 AS UNSIGNED) = sr2.status_id
                LEFT JOIN `status_roles` sr3 ON CAST(u.user_status3 AS UNSIGNED) = sr3.status_id
                WHERE u.id = :userid
            ";
            $stmt_fetch = $db->prepare($sql_fetch);
            $stmt_fetch->bindValue(':userid', $userid_to_update, PDO::PARAM_INT);
            $stmt_fetch->execute();
            $new_data = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

            // 建立用於表格儲存格的 HTML
            $statuses = [];
            if (!empty($new_data['status_title_1'])) {
                $statuses[] = htmlspecialchars("{$new_data['status_id_1']} - {$new_data['status_title_1']}");
            }
            if (!empty($new_data['status_title_2'])) {
                $statuses[] = htmlspecialchars($new_data['status_title_2']);
            }
            if (!empty($new_data['status_title_3'])) {
                $statuses[] = htmlspecialchars($new_data['status_title_3']);
            }

            $response['success'] = true;
            $response['message'] = '部門職務更新成功。';
            $response['newDepartmentHtml'] = implode('<br>', $statuses);
            $response['new_status_1'] = $new_data['user_status'];
            $response['new_status_2'] = $new_data['user_status2'];
            $response['new_status_3'] = $new_data['user_status3'];
        } else {
            $response['message'] = '資料庫更新失敗。';
        }
    } catch (PDOException $e) {
        $response['message'] = '資料庫操作錯誤：' . $e->getMessage();
        error_log("Department update failed: " . $e->getMessage());
    }
} else {
    $response['message'] = '無效的請求或缺少參數。';
}

echo json_encode($response);
exit();