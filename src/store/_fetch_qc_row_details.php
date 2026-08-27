<?php
if (!isset($_SESSION)) {
    session_start();
}
include_once '../common/DBConnection.php';
include_once '../common/_config.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

if (!isset($_GET['bi'])) {
    $response['message'] = 'Missing bom_ing_fid parameter (bi).';
    echo json_encode($response);
    exit;
}

$bom_ing_fid_to_fetch = $_GET['bi'];

if (empty($bom_ing_fid_to_fetch)) {
    $response['message'] = 'BOM Ing FID (bi) cannot be empty.';
    echo json_encode($response);
    exit;
}

try {
    if (!isset($db) || !($db instanceof PDO)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
        if (!isset($db) || !($db instanceof PDO)) {
            throw new Exception('Database connection not available.');
        }
    }

    $fetched_data = [];

    // 1. bom_ing 主資料（YEAR() > 0 過濾無效 TIMESTAMP）
    $stmt_bom_ing = $db->prepare("
        SELECT
            QC_check,
            IF(YEAR(QC_check_date) > 0, DATE_FORMAT(QC_check_date,'%m/%d'), NULL) AS QC_check_date_formatted,
            processing_state, ps,
            QC_ps  AS BIQC_ps,
            QC_ps2 AS BIQC_ps2,
            pm_ps  AS BIPM_ps,
            pm_ps2 AS BIPM_ps2,
            QC_ps_aod,
            sqty
        FROM bom_ing
        WHERE bom_ing_fid = ?
    ");
    $stmt_bom_ing->execute([$bom_ing_fid_to_fetch]);
    $fetched_data['bom_ing_details'] = $stmt_bom_ing->fetch(PDO::FETCH_ASSOC) ?: [
        'QC_check' => null, 'QC_check_date_formatted' => null,
        'processing_state' => null, 'ps' => null,
        'QC_ps' => null, 'QC_ps2' => null, 'QC_ps_aod' => null, 'sqty' => 0
    ];

    // 2. individual QQ/OK entries
    $stmt_individual_qc = $db->prepare("
        SELECT
            qc_check_id, bom_ing_fid_ref, QC_check,
            QC_QQ_sqty, QC_ok_sqty, QC_ps, QC_ps_ok,
            IF(YEAR(QC_check_date) > 0, DATE_FORMAT(QC_check_date,'%m/%d'), NULL) AS QC_check_date_formatted,
            IF(YEAR(QC_check_date) > 0, DATE_FORMAT(QC_check_date,'%m/%d %H:%i'), NULL) AS QC_check_date_formatted_with_time
        FROM qc_check
        WHERE bom_ing_fid_ref = ? AND QC_check IN ('QQ','ok')
        ORDER BY QC_check_date DESC, qc_check_id DESC
    ");
    $stmt_individual_qc->execute([$bom_ing_fid_to_fetch]);
    $fetched_data['individual_qc_entries'] = $stmt_individual_qc->fetchAll(PDO::FETCH_ASSOC);

    // 3. 合計 + 最新日期（一次查完）
    $stmt_agg = $db->prepare("
        SELECT
            SUM(CASE WHEN QC_check='QQ' THEN QC_QQ_sqty ELSE 0 END) AS total_qq_qty,
            SUM(CASE WHEN QC_check='ok' THEN QC_ok_sqty  ELSE 0 END) AS total_ok_qty,
            DATE_FORMAT(MAX(CASE WHEN QC_check='QQ' AND YEAR(QC_check_date) > 0
                THEN QC_check_date END),'%m/%d') AS latest_QQ_date_formatted,
            DATE_FORMAT(MAX(CASE WHEN QC_check='ok' AND YEAR(QC_check_date) > 0
                THEN QC_check_date END),'%m/%d') AS latest_ok_date_formatted
        FROM qc_check
        WHERE bom_ing_fid_ref = ?
    ");
    $stmt_agg->execute([$bom_ing_fid_to_fetch]);
    $agg = $stmt_agg->fetch(PDO::FETCH_ASSOC);

    $fetched_data['total_qq_qty']             = (float)($agg['total_qq_qty'] ?? 0);
    $fetched_data['total_ok_qty']             = (float)($agg['total_ok_qty'] ?? 0);
    $fetched_data['latest_QQ_date_formatted'] = $agg['latest_QQ_date_formatted'] ?: null;
    $fetched_data['latest_ok_date_formatted'] = $agg['latest_ok_date_formatted'] ?: null;

    $response['success'] = true;
    $response['data']    = $fetched_data;
    $response['message'] = 'Row details fetched successfully.';

} catch (Exception $e) {
    $response['message'] = 'Error fetching row details: ' . $e->getMessage();
    error_log($response['message'] . " for bom_ing_fid: " . ($bom_ing_fid_to_fetch ?? 'N/A'));
}

echo json_encode($response);
exit;