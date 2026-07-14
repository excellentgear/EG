<?php
if (!isset($_SESSION)){
    session_start();
}
include("../common/_config.php");
include_once '../common/DBConnection.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'An error occurred.'];

if (!isset($_GET['bi']) || !isset($_GET['id'])) {
    $response['message'] = 'Missing parameters.';
    echo json_encode($response);
    exit;
}

$bom_ing_fid = $_GET['bi']; // Changed variable name
$user_id = $_GET['id'];

try {
    // Update QC_check and QC_check_date, but DO NOT clear QC_ps and QC_ps2
    $sql_update = "UPDATE `bom_ing` SET 
                    `Modified_By`=:user_id,
                    `Modified_At`=now(),
                    `QC_check`=NULL,      -- Set to NULL for '待驗'
                    `QC_check_date`=NULL
                   WHERE `bom_ing_fid`=:bom_ing_fid"; // Changed WHERE clause
    $cmd_update = $db->prepare($sql_update);
    $cmd_update->bindParam(':user_id', $user_id, PDO::PARAM_STR);
    $cmd_update->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR); // Changed bindParam

    if ($cmd_update->execute()) {
        // Fetch the current (and now preserved) QC_ps, QC_ps2, and QC_ps_aod values
        $sql_fetch = "SELECT QC_ps, QC_ps2, QC_ps_aod FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid_fetch"; // Changed WHERE clause
        $cmd_fetch = $db->prepare($sql_fetch);
        $cmd_fetch->bindParam(':bom_ing_fid_fetch', $bom_ing_fid, PDO::PARAM_STR); // Changed bindParam
        $cmd_fetch->execute();
        $remarks = $cmd_fetch->fetch(PDO::FETCH_ASSOC);


        $response['success'] = true;
        $response['message'] = '更新為「待驗」狀態成功，備註已保留。';
        $response['qc_ps'] = ($remarks && isset($remarks['QC_ps'])) ? $remarks['QC_ps'] : null;
        $response['qc_ps2'] = ($remarks && isset($remarks['QC_ps2'])) ? $remarks['QC_ps2'] : null;
        $response['qc_ps_aod'] = ($remarks && isset($remarks['QC_ps_aod'])) ? $remarks['QC_ps_aod'] : null;
        
        // Add fetching for quantities
        // Fetch main total qty from bom_ing
        $stmt_fetch_sqty = $db->prepare("SELECT sqty FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid_sqty");
        $stmt_fetch_sqty->bindParam(':bom_ing_fid_sqty', $bom_ing_fid, PDO::PARAM_STR);
        $stmt_fetch_sqty->execute();
        $sqty_row = $stmt_fetch_sqty->fetch(PDO::FETCH_ASSOC);
        $response['main_total_qty'] = ($sqty_row && $sqty_row['sqty'] !== null) ? (float)$sqty_row['sqty'] : 0;

        // Fetch total QQ quantity from qc_check
        $sql_sum_qq = "SELECT SUM(QC_QQ_sqty) as total_qq_qty FROM qc_check WHERE bom_ing_fid_ref = :bom_ing_fid AND QC_check = 'QQ'";
        $stmt_sum_qq = $db->prepare($sql_sum_qq);
        $stmt_sum_qq->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
        $stmt_sum_qq->execute();
        $qq_sum_result = $stmt_sum_qq->fetch(PDO::FETCH_ASSOC);
        $response['total_qq_qty'] = ($qq_sum_result && $qq_sum_result['total_qq_qty'] !== null) ? (float)$qq_sum_result['total_qq_qty'] : 0;

        // Fetch total OK quantity from qc_check
        $sql_sum_ok = "SELECT SUM(QC_ok_sqty) as total_ok_qty FROM qc_check WHERE bom_ing_fid_ref = :bom_ing_fid AND QC_check = 'ok'";
        $stmt_sum_ok = $db->prepare($sql_sum_ok);
        $stmt_sum_ok->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
        $stmt_sum_ok->execute();
        $ok_sum_result = $stmt_sum_ok->fetch(PDO::FETCH_ASSOC);
        $response['total_ok_qty'] = ($ok_sum_result && $ok_sum_result['total_ok_qty'] !== null) ? (float)$ok_sum_result['total_ok_qty'] : 0;
        
        // For 'wait', qc_check and qc_check_date are NULL
        $response['qc_check'] = null;
        $response['qc_check_date'] = null;
    } else {
        $errorInfo = $cmd_update->errorInfo();
        $response['message'] = '資料庫更新失敗: ' . $errorInfo[2];
    }
} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
