<?php
if (!isset($_SESSION)){
    session_start();
}
include("../common/_config.php");
include_once '../common/DBConnection.php';
include_once '_update_bom_ing_qc_status.php'; // ⭐ 1. 引入新的共用邏輯檔案


// New: Handle fetch request for OK details
if (isset($_GET['action']) && $_GET['action'] === 'fetch_ok_details' && isset($_GET['bi'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'data' => [], 'message' => ''];
    $bom_ing_fid_to_fetch = $_GET['bi'];

    if (empty($bom_ing_fid_to_fetch)) {
        $response['message'] = 'BOM Ing FID is required.';
        echo json_encode($response);
        exit;
    }

    try {
        // Ensure $db is available (it should be from _config.php)
        if (!isset($db) || !($db instanceof PDO)) {
             // Fallback or error if $db is not initialized
            if (class_exists('DBConnection')) { // Check if DBConnection class exists
                $connInstance = new DBConnection();
                $db = $connInstance->getDB(); // Assuming getDB() method returns PDO
            }
            if (!isset($db) || !($db instanceof PDO)) { // Check again
                throw new Exception('Database connection not available.');
            }
        }

        $sql_fetch_ok = "SELECT qc_check_id, QC_ok_sqty, QC_ps_ok,
                                DATE_FORMAT(QC_check_date, '%m/%d') AS QC_check_date_formatted
                         FROM qc_check
                         WHERE bom_ing_fid_ref = :bom_ing_fid AND QC_check = 'ok' 
                         ORDER BY qc_check_id ASC";
        $stmt_fetch_ok = $db->prepare($sql_fetch_ok);
        $stmt_fetch_ok->bindParam(':bom_ing_fid', $bom_ing_fid_to_fetch, PDO::PARAM_STR);
        $stmt_fetch_ok->execute();
        $ok_records = $stmt_fetch_ok->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['data'] = $ok_records;
        echo json_encode($response);
        exit;

    } catch (Exception $e) { // Catch PDOException and general Exception
        $response['message'] = 'Error fetching OK details: ' . $e->getMessage();
        error_log($response['message']);
        echo json_encode($response);
        exit;
    }
}

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'An error occurred.'];

if (!isset($_GET['bi']) || !isset($_GET['id'])) {
    $response['message'] = 'Missing parameters.';
    echo json_encode($response);
    exit;
}

$bom_ing_fid = $_GET['bi']; // Changed variable name
$user_id = $_GET['id'];
$clear_remark_only = isset($_POST['clear_remark_only']) && $_POST['clear_remark_only'] == '1';

// Expect arrays for multi-row input
$ok_quantities_post = $_POST['ok_total_qty'] ?? [];
$qc_messages_post = $_POST['QCmessage'] ?? [];
$qc_check_ids_post = $_POST['qc_check_id'] ?? [];

if (empty($bom_ing_fid)) {
    error_log("[QC_OK] Error: Received empty bom_ing_fid. User_id: '" . $user_id . "'");
    $response['message'] = '錯誤：bom_ing_fid 為空或缺失。'; // Translated message
    echo json_encode($response);
    exit;
}

error_log("[QC_OK] Processing bom_ing_fid: '" . $bom_ing_fid . "', user_id: '" . $user_id . "'");

try {
    $db->beginTransaction();

    if ($clear_remark_only) {
        // Action: Clear existing OK records from qc_check
        $sql_delete_qc_checks = "DELETE FROM qc_check WHERE bom_ing_fid_ref = :bom_ing_fid AND QC_check = 'ok'";
        $cmd_delete_qc_checks = $db->prepare($sql_delete_qc_checks);
        $cmd_delete_qc_checks->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
        if (!$cmd_delete_qc_checks->execute()) {
            $db->rollBack();
            $response['message'] = '清除允收檢驗記錄失敗: ' . $cmd_delete_qc_checks->errorInfo()[2];
            echo json_encode($response);
            exit;
        }

        // 新增：同時將 bom_ing 的檢驗狀態與日期設為 NULL
        $sql_update_bom_ing = "UPDATE bom_ing 
                               SET QC_check = NULL, QC_check_date = NULL 
                               WHERE bom_ing_fid = :bom_ing_fid";
        $cmd_update_bom_ing = $db->prepare($sql_update_bom_ing);
        $cmd_update_bom_ing->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
        if (!$cmd_update_bom_ing->execute()) {
            $db->rollBack();
            $response['message'] = '重設 bom_ing 狀態失敗: ' . $cmd_update_bom_ing->errorInfo()[2];
            echo json_encode($response);
            exit;
        }

        $response['message'] = '允收紀錄已清除。';

    } else {
        // Action: Add/Update OK records in qc_check and update bom_ing
        if (!is_array($ok_quantities_post) || !is_array($qc_messages_post) || !is_array($qc_check_ids_post) ||
            count($ok_quantities_post) !== count($qc_messages_post) ||
            count($ok_quantities_post) !== count($qc_check_ids_post)) {
            $db->rollBack();
            $response['message'] = '提交的允收數據格式不正確。';
            echo json_encode($response);
            exit;
        }

        $processed_qc_check_ids = []; // To keep track of qc_check_ids that are submitted
        $first_valid_message_for_bom_ing = null;

        for ($i = 0; $i < count($ok_quantities_post); $i++) {
            $qty_str = isset($ok_quantities_post[$i]) ? trim($ok_quantities_post[$i]) : '';
            $msg_str = isset($qc_messages_post[$i]) ? trim($qc_messages_post[$i]) : '';
            $current_qc_check_id = isset($qc_check_ids_post[$i]) ? trim($qc_check_ids_post[$i]) : null;

            if ($qty_str === '' && $msg_str === '') { // Skip completely empty rows
                // If there's a qc_check_id, it means an existing row was cleared by user, will be handled by orphan deletion
                if (is_numeric($current_qc_check_id) && (int)$current_qc_check_id > 0) {
                    // This ID will not be in $processed_qc_check_ids, so it will be deleted by the orphan deletion logic later.
                }
                continue;
            }
             if ($qty_str === '') { // Quantity is mandatory if row is not entirely empty
                $db->rollBack();
                $response['message'] = '允收數量為必填。';
                echo json_encode($response);
                exit;
            }

            $qty_num = filter_var($qty_str, FILTER_VALIDATE_FLOAT);
            if ($qty_num === false || $qty_num < 0) { // Allow 0 quantity if remark is present
                $db->rollBack();
                $response['message'] = '允收數量必須是非負數字。';
                echo json_encode($response);
                exit;
            }

            if ($first_valid_message_for_bom_ing === null && $msg_str !== '') {
                $first_valid_message_for_bom_ing = $msg_str;
            }

            if (is_numeric($current_qc_check_id) && (int)$current_qc_check_id > 0) {
                // UPDATE existing qc_check record
                $sql_fetch_existing = "SELECT QC_ok_sqty, QC_ps_ok FROM qc_check WHERE qc_check_id = :qc_check_id AND bom_ing_fid_ref = :bom_ing_fid_ref";
                $stmt_fetch_existing = $db->prepare($sql_fetch_existing);
                $stmt_fetch_existing->bindParam(':qc_check_id', $current_qc_check_id, PDO::PARAM_INT);
                $stmt_fetch_existing->bindParam(':bom_ing_fid_ref', $bom_ing_fid, PDO::PARAM_STR);
                $stmt_fetch_existing->execute();
                $existing_record = $stmt_fetch_existing->fetch(PDO::FETCH_ASSOC);

                $perform_db_update = false;

                if ($existing_record) {
                    $db_qty = isset($existing_record['QC_ok_sqty']) ? (float)$existing_record['QC_ok_sqty'] : null;
                    $db_ps_ok_normalized = ($existing_record['QC_ps_ok'] === null) ? '' : $existing_record['QC_ps_ok'];
                    $msg_str_normalized = ($msg_str === null) ? '' : $msg_str;

                    $qty_is_different = (is_null($db_qty) && $qty_num !== null) ||
                                        (!is_null($db_qty) && $qty_num === null) ||
                                        (!is_null($db_qty) && $qty_num !== null && abs($db_qty - $qty_num) > 1e-9);

                    $ps_is_different = ($db_ps_ok_normalized !== $msg_str_normalized);

                    if ($qty_is_different || $ps_is_different) {
                        $perform_db_update = true;
                    }
                } else {
                    error_log("[QC_OK_UPDATE_WARN] User: $user_id, QC_ID: $current_qc_check_id not found for bom_ing_fid: $bom_ing_fid. This ID might be for a new or deleted record.");
                }

                $isPrivilegedUser = (isset($_SESSION['status']) && ($_SESSION['status'] == 9 || $_SESSION['status'] == 50 || $_SESSION['status'] == 51));

                if ($perform_db_update) {
                    if ($isPrivilegedUser) {
                        // Privileged user: update quantity and message
                        $sql_update_qc_check = "UPDATE qc_check SET QC_ps_ok = :qc_ps_ok, QC_ok_sqty = :qc_ok_sqty, QC_check_date = NOW(), updated_by = :user_id, updated_at = NOW() WHERE qc_check_id = :qc_check_id AND bom_ing_fid_ref = :bom_ing_fid_param";
                        $cmd_qc_action = $db->prepare($sql_update_qc_check);
                        $cmd_qc_action->bindParam(':qc_ps_ok', $msg_str, ($msg_str === '') ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        $cmd_qc_action->bindParam(':qc_ok_sqty', $qty_num, PDO::PARAM_STR); // qty_num is float or null
                    } else {
                        // Non-privileged user: data changed (anomaly). Update only timestamps.
                        $sql_update_qc_check = "UPDATE qc_check SET QC_check_date = NOW(), updated_by = :user_id, updated_at = NOW() WHERE qc_check_id = :qc_check_id AND bom_ing_fid_ref = :bom_ing_fid_param";
                        $cmd_qc_action = $db->prepare($sql_update_qc_check);
                    }
                    $cmd_qc_action->bindParam(':qc_check_id', $current_qc_check_id, PDO::PARAM_INT);
                    $cmd_qc_action->bindParam(':bom_ing_fid_param', $bom_ing_fid, PDO::PARAM_STR);
                    $cmd_qc_action->bindParam(':user_id', $user_id, PDO::PARAM_INT);

                    if (!$cmd_qc_action->execute()) {
                        $db->rollBack();
                        $response['message'] = '更新允收記錄 (ID: ' . $current_qc_check_id . ') 失敗: ' . $cmd_qc_action->errorInfo()[2];
                        error_log("[QC_OK_UPDATE_FAIL] User: $user_id, QC_ID: $current_qc_check_id, Error: " . $cmd_qc_action->errorInfo()[2]);
                        echo json_encode($response);
                        exit;
                    }
                } else {
                    // Data exists but is unchanged, or record not found for ID: skip DB update for this row.
                }
                $processed_qc_check_ids[] = (int)$current_qc_check_id;
            } else {
                // INSERT new qc_check record
                // For new records, always save the submitted quantity and message, regardless of user privilege,
                // as these fields are editable for new rows on the frontend.

                $sql_insert_qc_check = "INSERT INTO qc_check (
                                        bom_ing_fid_ref, QC_check, QC_check_date, QC_ps_ok, QC_ok_sqty,
                                        created_by, created_at, updated_by, updated_at
                                    ) VALUES (
                                        :bom_ing_fid_param, 'ok', NOW(), :qc_ps_ok, :qc_ok_sqty,
                                        :user_id, NOW(), :user_id, NOW()
                                    )";
                $cmd_qc_action = $db->prepare($sql_insert_qc_check);
                $cmd_qc_action->bindParam(':bom_ing_fid_param', $bom_ing_fid, PDO::PARAM_STR);
                $cmd_qc_action->bindParam(':qc_ps_ok', $msg_str, ($msg_str === '') ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $cmd_qc_action->bindParam(':qc_ok_sqty', $qty_num, PDO::PARAM_STR);
                $cmd_qc_action->bindParam(':user_id', $user_id, PDO::PARAM_INT);

                if (!$cmd_qc_action->execute()) {
                    $db->rollBack();
                    $response['message'] = '新增允收記錄至 qc_check 失敗: ' . $cmd_qc_action->errorInfo()[2];
                    error_log("[QC_OK_INSERT_FAIL] User: $user_id, BOM_ING_FID: $bom_ing_fid, Error: " . $cmd_qc_action->errorInfo()[2]);
                    echo json_encode($response);
                    exit;
                }
                $processed_qc_check_ids[] = (int)$db->lastInsertId();
            }
        }

        // Delete orphaned qc_check 'ok' records
        if (!empty($processed_qc_check_ids)) {
            $placeholders = rtrim(str_repeat('?,', count($processed_qc_check_ids)), ',');
            $sql_delete_orphans = "DELETE FROM qc_check 
                                   WHERE bom_ing_fid_ref = ? AND QC_check = 'ok' AND qc_check_id NOT IN ($placeholders)";
            $cmd_delete_orphans = $db->prepare($sql_delete_orphans);
            $delete_params = array_merge([$bom_ing_fid], $processed_qc_check_ids);
        } else { // If all rows were cleared by user, delete all 'ok' for this bom_ing_fid
            $sql_delete_orphans = "DELETE FROM qc_check 
                                   WHERE bom_ing_fid_ref = ? AND QC_check = 'ok'";
            $cmd_delete_orphans = $db->prepare($sql_delete_orphans);
            $delete_params = [$bom_ing_fid];
        }
        if (!$cmd_delete_orphans->execute($delete_params)) {
            $db->rollBack();
            $response['message'] = '刪除舊允收記錄失敗: ' . $cmd_delete_orphans->errorInfo()[2];
            echo json_encode($response);
            exit;
        }

        $response['message'] = '允收記錄更新成功。';

        // ====== 這裡加 PATCH ======
$containerList = $_POST['container'] ?? [];
$quantityList = $_POST['quantity'] ?? [];
$qc_ps = "";
$qc_ps2 = "";
$dataPairs = [];
for ($i = 0; $i < count($containerList); $i++) {
    $container = trim($containerList[$i]);
    $quantity = trim($quantityList[$i]);
    if ($container !== "") {
        if ($i === 1 && $quantity === "") {
            continue; // 第二筆若未填箱數則略過
        }
        $dataPairs[] = ($quantity === "" ? "0" : $quantity) . $container;
    }
}
if (isset($dataPairs[0])) {
    $qc_ps = $dataPairs[0];
}
if (isset($dataPairs[1])) {
    $qc_ps2 = $dataPairs[1];
}

$sql_update_qcps = "UPDATE bom_ing SET QC_ps = :qc_ps, QC_ps2 = :qc_ps2 WHERE bom_ing_fid = :bom_ing_fid LIMIT 1";
$stmt_update_qcps = $db->prepare($sql_update_qcps);
$stmt_update_qcps->bindParam(':qc_ps', $qc_ps, PDO::PARAM_STR);
$stmt_update_qcps->bindParam(':qc_ps2', $qc_ps2, PDO::PARAM_STR);
$stmt_update_qcps->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
$stmt_update_qcps->execute();
// ====== PATCH結束 ======
    }

    // ⭐ 2. 統一呼叫狀態更新函式
    updateBomIngQcStatus($db, (int)$bom_ing_fid);

    $db->commit();
    $response['success'] = true;

    // Fetch all QQ and OK qc_check entries for this bom_ing_fid to return to frontend
    $sql_fetch_all_remarks = "SELECT qc_check_id, bom_ing_fid_ref, QC_check, QC_QQ_sqty, QC_ok_sqty, QC_ps, QC_ps_ok,
                                     DATE_FORMAT(QC_check_date, '%m/%d') AS QC_check_date_formatted
                              FROM qc_check
                              WHERE bom_ing_fid_ref = :bom_ing_fid AND QC_check IN ('QQ', 'ok')
                              ORDER BY QC_check_date DESC, qc_check_id DESC";
    $stmt_fetch_all_remarks = $db->prepare($sql_fetch_all_remarks);
    $stmt_fetch_all_remarks->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
    $stmt_fetch_all_remarks->execute();
    $response['individual_qc_entries'] = $stmt_fetch_all_remarks->fetchAll(PDO::FETCH_ASSOC);

    // Fetch the final state from bom_ing for the response
    $stmt_fetch_bom_ing = $db->prepare("SELECT QC_check, DATE_FORMAT(QC_check_date, '%m/%d') as QC_check_date_formatted, processing_state, QC_ps AS BIQC_ps, QC_ps2 AS BIQC_ps2, QC_ps_aod, sqty FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid_fetch");
    $stmt_fetch_bom_ing->bindParam(':bom_ing_fid_fetch', $bom_ing_fid, PDO::PARAM_STR);
    $stmt_fetch_bom_ing->execute();
    $final_bom_ing_state = $stmt_fetch_bom_ing->fetch(PDO::FETCH_ASSOC);

    // Fetch total OK quantity from qc_check
    $sql_sum_ok = "SELECT SUM(QC_ok_sqty) as total_ok_qty FROM qc_check WHERE bom_ing_fid_ref = :bom_ing_fid AND QC_check = 'ok'";
    $stmt_sum_ok = $db->prepare($sql_sum_ok);
    $stmt_sum_ok->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
    $stmt_sum_ok->execute();
    $ok_sum_result = $stmt_sum_ok->fetch(PDO::FETCH_ASSOC);
    $response['total_ok_qty'] = ($ok_sum_result && $ok_sum_result['total_ok_qty'] !== null) ? (float)$ok_sum_result['total_ok_qty'] : 0;

    // Fetch total QQ quantity from qc_check
    $sql_sum_qq = "SELECT SUM(QC_QQ_sqty) as total_qq_qty FROM qc_check WHERE bom_ing_fid_ref = :bom_ing_fid AND QC_check = 'QQ'";
    $stmt_sum_qq = $db->prepare($sql_sum_qq);
    $stmt_sum_qq->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
    $stmt_sum_qq->execute();
    $qq_sum_result = $stmt_sum_qq->fetch(PDO::FETCH_ASSOC);
    $response['total_qq_qty'] = ($qq_sum_result && $qq_sum_result['total_qq_qty'] !== null) ? (float)$qq_sum_result['total_qq_qty'] : 0;


    if ($final_bom_ing_state) {
        $response['BIQC_ps'] = $final_bom_ing_state['BIQC_ps'];
        $response['BIQC_ps2'] = $final_bom_ing_state['BIQC_ps2'];
        $response['qc_check'] = $final_bom_ing_state['QC_check'];
        $response['qc_check_date'] = $final_bom_ing_state['QC_check_date_formatted'];
        $response['processing_state'] = $final_bom_ing_state['processing_state'];
        $response['qc_ps'] = $final_bom_ing_state['QC_ps']; // This is general QQ remark from bom_ing
        $response['qc_ps2'] = $final_bom_ing_state['QC_ps2']; // This is NG remark from bom_ing
        $response['qc_ps_aod'] = $final_bom_ing_state['QC_ps_aod']; // This is AOD remark from bom_ing
        $response['main_total_qty'] = ($final_bom_ing_state['sqty'] !== null) ? (float)$final_bom_ing_state['sqty'] : 0;
    } else {
        $response['main_total_qty'] = 0;
    }

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $response['message'] = 'Database error: ' . $e->getMessage();
}



echo json_encode($response);
