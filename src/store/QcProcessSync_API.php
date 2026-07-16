<?php
// QcProcessSync_API.php — QC/生管製程同步提醒 後端 API
// 比對「QC最新確認做到的製程序號」跟「生管系統裡仍停在加工中/QC待驗、序號較舊的落後製程」，
// 列出不同步清單，並提供「快速更新目前製程」(移轉+回廠+視qc_completed跳待移轉) 的一鍵動作。
// 權限沿用既有 oready(BOM總覽) 模組角色，不另開新 RBAC 模組。
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/role_features_helper.php';

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => '尚未登入']);
    exit;
}
$user_id = $_SESSION['id'];

$conn = new DBConnection();
$db = $conn->getPDO();

if (!rf_has_module_role($db, (int)$user_id, 'oready')) {
    echo json_encode(['success' => false, 'message' => '請先申請權限', 'no_access' => true]);
    exit;
}
$_qsync_features = rf_load_user_features($db, (int)$user_id);
$can_write = rf_has_feature($_qsync_features, 'oready_update')
          || rf_has_feature($_qsync_features, 'oready_transfer')
          || rf_has_feature($_qsync_features, 'oready_mark_returned');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '未知的 action: ' . $action];

function qsync_paginate_params() {
    $page = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
    $pageSize = (int)($_POST['pageSize'] ?? $_GET['pageSize'] ?? 10);
    if (!in_array($pageSize, [5, 10, 20, 50], true)) $pageSize = 10;
    return [$page, $pageSize];
}

if ($action === 'get_mismatch_list') {
    list($page, $pageSize) = qsync_paginate_params();
    $offset = ($page - 1) * $pageSize;
    $kw = trim($_POST['keyword'] ?? $_GET['keyword'] ?? '');

    $kwSql = '';
    $kwParams = [];
    if ($kw !== '') {
        $kwSql = " AND (b.bom LIKE :kw OR b.Client_Name LIKE :kw2) ";
        $kwParams[':kw'] = '%' . $kw . '%';
        $kwParams[':kw2'] = '%' . $kw . '%';
    }

    $baseSql = "
        FROM bom b
        JOIN bom_ing stuck ON stuck.bom = b.bom AND stuck.processing_state IN ('ing','Q')
             AND stuck.is_consumed = 0 AND stuck.is_schedule_split = 0
        JOIN (
            SELECT bi.bom, MAX(bi.bom_sn) AS max_qc_sn, MAX(qc.QC_check_date) AS qc_date
            FROM qc_check qc
            JOIN bom_ing bi ON bi.bom_ing_fid = qc.bom_ing_fid_ref
            GROUP BY bi.bom
        ) qc_max ON qc_max.bom = b.bom
        WHERE b.processing_state IS NULL
          AND stuck.bom_sn < qc_max.max_qc_sn
          $kwSql
    ";

    try {
        $cntStmt = $db->prepare("SELECT COUNT(*) $baseSql");
        $cntStmt->execute($kwParams);
        $total = (int)$cntStmt->fetchColumn();

        $sql = "
            SELECT
                b.bom, b.Client_Name, DATE_FORMAT(b.Delivery_date, '%Y/%m/%d') AS delivery_date,
                stuck.bom_ing_fid AS stuck_fid, stuck.bom_sn AS stuck_sn, stuck.processing_state AS stuck_state,
                DATE_FORMAT(stuck.outsource_date, '%Y/%m/%d') AS stuck_outsource_date,
                stuck.qc_completed AS stuck_qc_completed,
                COALESCE(spn.ProcessName, CONCAT('製程', stuck.process_no)) AS stuck_process_name,
                qc_max.max_qc_sn, DATE_FORMAT(qc_max.qc_date, '%Y/%m/%d %H:%i') AS qc_date,
                COALESCE(qpn.ProcessName, CONCAT('製程', qc_row.process_no)) AS qc_process_name
            $baseSql
            LEFT JOIN process_no spn ON spn.ProcessNo = stuck.process_no
            LEFT JOIN bom_ing qc_row ON qc_row.bom = b.bom AND qc_row.bom_sn = qc_max.max_qc_sn AND qc_row.is_consumed = 0
            LEFT JOIN process_no qpn ON qpn.ProcessNo = qc_row.process_no
            ORDER BY stuck.outsource_date ASC
            LIMIT $pageSize OFFSET $offset
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($kwParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = ['success' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'can_write' => $can_write];
    } catch (PDOException $e) {
        $response = ['success' => false, 'message' => '資料庫查詢錯誤：' . $e->getMessage()];
    }
}

else if ($action === 'search_maker') {
    // 廠商搜尋：直接沿用 OreadyReply_ForPm_BaseOfTime2_ajax.php 既有的 search_maker 邏輯（同一份 maker_list 查詢）
    $term = trim($_POST['term'] ?? '');
    $response = ['success' => true, 'data' => []];
    if ($term !== '') {
        try {
            $sql = "SELECT maker_id_no, maker_id, m_category, m_process_items
                    FROM maker_list
                    WHERE (maker_id_no LIKE :term OR maker_id LIKE :term2)
                      AND (status IS NULL OR status != 'X')
                    ORDER BY CASE WHEN maker_id_no LIKE :term3 THEN 0 ELSE 1 END, maker_id_no ASC
                    LIMIT 20";
            $stmt = $db->prepare($sql);
            $wildcard = '%' . $term . '%';
            $stmt->execute([':term' => $wildcard, ':term2' => $wildcard, ':term3' => $wildcard]);
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => '資料庫查詢錯誤：' . $e->getMessage()];
        }
    }
}

else if ($action === 'quick_sync_transfer') {
    if (!$can_write) {
        $response = ['success' => false, 'message' => '您沒有執行此操作的權限'];
        echo json_encode($response);
        exit;
    }
    $fid = (int)($_POST['bom_ing_fid'] ?? 0);
    $transfer_date = trim($_POST['transfer_date'] ?? '');
    $maker_no = trim($_POST['maker_no'] ?? '');
    $maker_name = trim($_POST['maker_name'] ?? '');

    if (!$fid || $transfer_date === '' || $maker_no === '' || $maker_name === '') {
        $response = ['success' => false, 'message' => '請填寫移轉日期與廠商'];
        echo json_encode($response);
        exit;
    }

    try {
        $chk = $db->prepare("
            SELECT bi.bom_ing_fid, bi.processing_state, bi.qc_completed, bi.sqty, pn.is_exclude_qc
            FROM bom_ing bi
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            WHERE bi.bom_ing_fid = ? LIMIT 1
        ");
        $chk->execute([$fid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $response = ['success' => false, 'message' => '找不到對應的製程記錄'];
            echo json_encode($response);
            exit;
        }
        if (!in_array($row['processing_state'], ['ing', 'Q'], true)) {
            $response = ['success' => false, 'message' => '此製程目前狀態已非加工中/QC待驗，可能已被其他人處理，請重新整理清單'];
            echo json_encode($response);
            exit;
        }

        // qc_completed 優先於 is_exclude_qc：QC 若已對這筆完工，即使原本走一般驗程也直接視為待移轉
        $final_state = !empty($row['qc_completed']) ? 'P' : (!empty($row['is_exclude_qc']) ? 'P' : 'Q');

        $db->beginTransaction();

        $upd = $db->prepare("
            UPDATE bom_ing
            SET outsource_date = :od, maker_id_no = :mno, maker_id = :mname,
                return_date = NOW(), processing_state = :st, Modified_At = NOW(), Modified_By = :uid
            WHERE bom_ing_fid = :fid
        ");
        $upd->execute([
            ':od' => $transfer_date . ' 00:00:00',
            ':mno' => $maker_no,
            ':mname' => $maker_name,
            ':st' => $final_state,
            ':uid' => $user_id,
            ':fid' => $fid,
        ]);

        $note = 'QC已回報後續製程，快速補同步：移轉日=' . $transfer_date . '，廠商=' . $maker_name . '，回廠日=今天，最終狀態=' . $final_state;
        $ins = $db->prepare("
            INSERT INTO bom_ing_event (bom_ing_fid, event_type, affected_qty, target_maker_id, event_note, Created_By)
            VALUES (:fid, 'quick_sync_transfer', :qty, :maker, :note, :uid)
        ");
        $ins->execute([
            ':fid' => $fid,
            ':qty' => $row['sqty'],
            ':maker' => $maker_no,
            ':note' => $note,
            ':uid' => $user_id,
        ]);

        $db->commit();
        $response = ['success' => true, 'message' => '已快速更新，狀態 → ' . $final_state, 'new_state' => $final_state];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $response = ['success' => false, 'message' => '資料庫操作錯誤：' . $e->getMessage()];
    }
}

echo json_encode($response);
