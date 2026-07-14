<?php
session_start();
if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include '../common/DBConnection.php';
$conn = new DBConnection();
$pdo = $conn->getPDO();

// 確保欄位存在（首次執行時自動建立）
try { $pdo->query("SELECT exclude_when_nonzero FROM is_sale_type LIMIT 1"); } catch (Exception $_ce) {
    try { $pdo->exec("ALTER TABLE is_sale_type ADD COLUMN exclude_when_nonzero tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否金額>0時視為異常'"); } catch (Exception $_ae) {}
}
try { $pdo->query("SELECT exclude_top10 FROM is_sale_type LIMIT 1"); } catch (Exception $_ce) {
    try { $pdo->exec("ALTER TABLE is_sale_type ADD COLUMN exclude_top10 tinyint(1) NOT NULL DEFAULT 0 COMMENT '排除十大熱銷產品統計'"); } catch (Exception $_ae) {}
}

$action = $_POST['action'] ?? 'get';

if ($action === 'get') {
    $stmt = $pdo->query("SELECT * FROM is_sale_type ORDER BY sort_order ASC, sale_type_id ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($action === 'save') {
    $id = $_POST['sale_type_id'] ?? '';
    $name = $_POST['sale_type_name'] ?? '';
    $is_count = isset($_POST['is_count']) ? 1 : 0;
    $exclude_anomaly = isset($_POST['exclude_anomaly']) ? 1 : 0;
    $exclude_when_nonzero = isset($_POST['exclude_when_nonzero']) ? 1 : 0;
    $exclude_top10 = isset($_POST['exclude_top10']) ? 1 : 0;
    $desc = $_POST['description'] ?? '';
    $sort = $_POST['sort_order'] ?? 0;
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => '名稱為必填']);
        exit;
    }

    try {
        if ($id !== '') {
            $sql = "UPDATE is_sale_type SET sale_type_name=?, is_count=?, exclude_anomaly=?, exclude_when_nonzero=?, exclude_top10=?, description=?, sort_order=?, is_active=? WHERE sale_type_id=?";
            $pdo->prepare($sql)->execute([$name, $is_count, $exclude_anomaly, $exclude_when_nonzero, $exclude_top10, $desc, $sort, $active, $id]);
        } else {
            $stmt = $pdo->query("SELECT MAX(sale_type_id) FROM is_sale_type");
            $maxId = $stmt->fetchColumn();
            $newId = ($maxId !== false) ? $maxId + 1 : 1;

            $sql = "INSERT INTO is_sale_type (sale_type_id, sale_type_name, is_count, exclude_anomaly, exclude_when_nonzero, exclude_top10, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$newId, $name, $is_count, $exclude_anomaly, $exclude_when_nonzero, $exclude_top10, $desc, $sort, $active]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} elseif ($action === 'delete') {
    $id = $_POST['sale_type_id'] ?? '';
    if ($id !== '') {
        try {
            $stmt = $pdo->prepare("DELETE FROM is_sale_type WHERE sale_type_id=?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            // 若有外鍵約束可能會失敗
            echo json_encode(['success' => false, 'message' => '刪除失敗，該性質可能已被使用於出貨資料中']);
        }
    }
}
?>