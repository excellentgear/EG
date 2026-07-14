<?php
header('Content-Type: application/json');
require_once '../common/_config.php';
if (!isset($db)) {
    include_once '../common/DBConnection.php';
    $db = (new DBConnection())->getDB();
}
$sql = "SELECT *
FROM qc_check
WHERE (created_at >= NOW() - INTERVAL 1 HOUR OR updated_at >= NOW() - INTERVAL 1 HOUR)
ORDER BY qc_check_id DESC;
";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);
