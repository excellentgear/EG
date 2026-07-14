<?php
// c:\MAMP\htdocs\EGsystem\src\store\save_event_category.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

include ("../common/DBConnection.php");
include_once dirname(__DIR__) . '/common/_config.php';

$response = ['success' => false, 'message' => '無效的請求。'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = (new DBConnection())->getPDO();

        // 從 POST 資料中獲取值，並進行基本的清理
        $id = !empty($_POST['id']) ? $_POST['id'] : null;
        $category_name = trim($_POST['category_name'] ?? '');
        $color = $_POST['color'] ?? '#3a87ad';
        $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
        $day_type = !empty($_POST['day_type']) ? $_POST['day_type'] : null;

        if (empty($category_name)) {
            throw new Exception("類別名稱為必填欄位。");
        }

        if ($id) {
            // 更新操作
            $sql = "UPDATE event_category SET category_name = :category_name, color = :color, description = :description, day_type = :day_type WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        } else {
            // 新增操作
            $sql = "INSERT INTO event_category (category_name, color, description, created_at, day_type) VALUES (:category_name, :color, :description, NOW(), :day_type)";
            $stmt = $db->prepare($sql);
        }

        // 綁定共用參數
        $stmt->bindParam(':category_name', $category_name, PDO::PARAM_STR);
        $stmt->bindParam(':color', $color, PDO::PARAM_STR);
        if ($description === null) {
            $stmt->bindParam(':description', $description, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        }
        
        $stmt->bindParam(':day_type', $day_type, $day_type === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => '類別已成功儲存。'];
        } else {
            $response['message'] = '儲存失敗，請檢查資料庫操作。';
        }

    } catch (Exception $e) {
        http_response_code(500);
        $response['message'] = '操作失敗: ' . $e->getMessage();
    }
}

echo json_encode($response);
?>