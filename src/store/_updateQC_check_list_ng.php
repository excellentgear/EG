<?php
if (!isset($_SESSION)){
    session_start();
}
include("../common/_config.php");
include_once '../common/DBConnection.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'An error occurred.'];

if (!isset($_GET['bi']) || !isset($_GET['id'])) {
    $response['message'] = 'Missing GET parameters.';
    echo json_encode($response);
    exit;
}

$bom_ing_fid = $_GET['bi']; // Changed variable name
$user_id = $_GET['id'];
$qc_message_content = isset($_POST['QCmessage']) ? trim($_POST['QCmessage']) : null;
$clear_remark_only = isset($_POST['clear_remark_only']) && $_POST['clear_remark_only'] == '1';

try {
    if ($clear_remark_only) {
        // 只清除備註，不更新 QC_check 和 QC_check_date
        $sql = "UPDATE `bom_ing` SET `Modified_By`=:user_id,
                `Modified_At`=now(),
                QC_ps2=NULL
                WHERE `bom_ing_fid`=:bom_ing_fid"; // Changed WHERE clause
    } else {
        // 更新 QC_check, QC_check_date 和備註 (原始邏輯)
        if ($qc_message_content === null || $qc_message_content === '') {
            $sql = "UPDATE `bom_ing` SET `Modified_By`=:user_id,
                    `Modified_At`=now(), `QC_check`='ng', QC_check_date=now(),
                    QC_ps2=NULL
                    WHERE `bom_ing_fid`=:bom_ing_fid"; // Changed WHERE clause
        } else {
            $sql = "UPDATE `bom_ing` SET `Modified_By`=:user_id,
                    `Modified_At`=now(), `QC_check`='ng', QC_check_date=now(),
                    QC_ps2=:qc_ps_content
                    WHERE `bom_ing_fid`=:bom_ing_fid"; // Changed WHERE clause
        }
    }

    $cmd = $db->prepare($sql);
    if (!$clear_remark_only && !($qc_message_content === null || $qc_message_content === '')) {
        $cmd->bindParam(':qc_ps_content', $qc_message_content, PDO::PARAM_STR);
    }
    $cmd->bindParam(':user_id', $user_id, PDO::PARAM_STR);
    $cmd->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR); // Changed bindParam
    
    if ($cmd->execute()) {
        // 獲取所有相關欄位以回傳給前端
        $stmt_fetch_remarks = $db->prepare("SELECT QC_check, DATE_FORMAT(QC_check_date, '%m/%d') as QC_check_date_formatted, QC_ps, QC_ps2, QC_ps_aod FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid_fetch"); // Changed WHERE clause
        $stmt_fetch_remarks->bindParam(':bom_ing_fid_fetch', $bom_ing_fid, PDO::PARAM_STR); // Changed bindParam
        $stmt_fetch_remarks->execute();
        $current_remarks_row = $stmt_fetch_remarks->fetch(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['message'] = $clear_remark_only ? '驗退備註已清除' : '驗退紀錄已更新';

        if ($current_remarks_row) {
            $response['qc_check'] = $current_remarks_row['QC_check'];
            $response['qc_check_date'] = $current_remarks_row['QC_check_date_formatted'];
            $response['qc_ps'] = $current_remarks_row['QC_ps'];
            $response['qc_ps2'] = $current_remarks_row['QC_ps2'];
            $response['qc_ps_aod'] = $current_remarks_row['QC_ps_aod'];
            // Fetch main total qty from bom_ing (it's not in current_remarks_row yet)
            $stmt_fetch_sqty = $db->prepare("SELECT sqty FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid_sqty");
            $stmt_fetch_sqty->bindParam(':bom_ing_fid_sqty', $bom_ing_fid, PDO::PARAM_STR);
            $stmt_fetch_sqty->execute();
            $sqty_row = $stmt_fetch_sqty->fetch(PDO::FETCH_ASSOC);
            $response['main_total_qty'] = ($sqty_row && $sqty_row['sqty'] !== null) ? (float)$sqty_row['sqty'] : 0;
        } else {
            $response['qc_check_date'] = $clear_remark_only ? null : date('m/d');
            $response['qc_ps'] = null;
            $response['qc_ps2'] = $clear_remark_only ? null : $qc_message_content;
            $response['qc_ps_aod'] = null;
            $response['main_total_qty'] = 0;
        }

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

        // Fetch all QQ and OK qc_check entries for this bom_ing_fid to return to frontend
        $sql_fetch_all_remarks_ng = "SELECT qc_check_id, bom_ing_fid_ref, QC_check, QC_QQ_sqty, QC_ok_sqty, QC_ps, QC_ps_ok,
                                         DATE_FORMAT(QC_check_date, '%m/%d') AS QC_check_date_formatted
                                  FROM qc_check
                                  WHERE bom_ing_fid_ref = :bom_ing_fid AND QC_check IN ('QQ', 'ok')
                                  ORDER BY QC_check_date DESC, qc_check_id DESC";
        $stmt_fetch_all_remarks_ng = $db->prepare($sql_fetch_all_remarks_ng);
        $stmt_fetch_all_remarks_ng->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
        $stmt_fetch_all_remarks_ng->execute();
        $response['individual_qc_entries'] = $stmt_fetch_all_remarks_ng->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $errorInfo = $cmd->errorInfo();
        $response['message'] = 'Database update failed: ' . $errorInfo[2];
    }

} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
