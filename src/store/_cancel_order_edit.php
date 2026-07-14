<?php
session_start();
$_SESSION['Order_id'] = "";
header('Content-Type: application/json');
echo json_encode(array("success" => true));
exit;
?>
