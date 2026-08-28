<?php
session_start();
if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include '../common/DBConnection.php';
$conn = new DBConnection();
$pdo = $conn->getPDO();

$is_id = $_POST['is_id'] ?? null;
if (!$is_id) {
    echo json_encode(['success' => false, 'message' => 'Missing ID']);
    exit;
}

// 允許更新的欄位
$fields = [
    'Order_date', 'IS_number', 'Client_name', 'Client_id', 'Product_id',
    'Specification', 'Content', 'Qty', 'Unit_price', 'Order_id',
    'Warehouse', 'Note', 'sale_type', 'billing_month_override'
];

// ── 已綁定的料號／客戶不可由此端點修改（前端已反灰，後端同規則再擋一次）──
// 綁定的唯一入口是出貨分析頁的「綁定料號 & 客戶」跳窗（action=save_bind_record）。
// 只有「值真的被改動」才擋下，送回原值（例如舊版頁面整份 serialize）一律放行。
try {
    $cs = $pdo->prepare('SELECT d_setting_id, Client_id, Product_id, Client_name FROM is_list WHERE IS_id = ? LIMIT 1');
    $cs->execute([$is_id]);
    $cur = $cs->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
if (!$cur) {
    echo json_encode(['success' => false, 'message' => '找不到這筆出貨記錄']);
    exit;
}

$same = function ($posted, $current) {
    return trim((string)$posted) === trim((string)$current);
};

if (!empty($cur['d_setting_id'])
    && isset($_POST['Product_id']) && !$same($_POST['Product_id'], $cur['Product_id'])) {
    echo json_encode(['success' => false, 'message' => '此筆已綁定料號主檔，料號不可直接修改；請改用「綁定料號 & 客戶」功能。']);
    exit;
}
if (trim((string)$cur['Client_id']) !== '') {
    if (isset($_POST['Client_name']) && !$same($_POST['Client_name'], $cur['Client_name'])) {
        echo json_encode(['success' => false, 'message' => '此筆已綁定客戶，客戶名稱不可直接修改；請改用「綁定料號 & 客戶」功能。']);
        exit;
    }
    if (isset($_POST['Client_id']) && !$same($_POST['Client_id'], $cur['Client_id'])) {
        echo json_encode(['success' => false, 'message' => '此筆已綁定客戶，客戶不可直接修改；請改用「綁定料號 & 客戶」功能。']);
        exit;
    }
}

$set_clause = [];
$params = [':id' => $is_id];

foreach ($fields as $field) {
    if (isset($_POST[$field])) {
        $val = $_POST[$field];
        // Client_id 只在有指定客戶時才寫入，避免空字串洗掉既有綁定
        if ($field === 'Client_id' && trim((string)$val) === '') {
            continue;
        }
        $set_clause[] = "$field = :$field";
        // 處理 sale_type 的 NULL 值
        if ($field === 'sale_type' && ($val === '' || $val === 'NULL')) {
            $val = null;
        }
        if ($field === 'billing_month_override' && $val === '') {
            $val = null;
        }
        $params[":$field"] = $val;
    }
}

if (empty($set_clause)) {
    echo json_encode(['success' => false, 'message' => 'No fields to update']);
    exit;
}

$sql = "UPDATE is_list SET " . implode(', ', $set_clause) . " WHERE IS_id = :id";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
