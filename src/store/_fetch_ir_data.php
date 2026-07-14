<?php
session_start();

include_once '../common/DBConnection.php';
include_once '../common/_config.php';

header('Content-Type: application/json');

if (!isset($db)) {
    echo json_encode(['status' => 'error', 'message' => '資料庫連線失敗']);
    exit;
}

$response = [
    'status' => 'error',
    'message' => '',
    'data' => [],
    'totalRecords' => 0,
    'totalPages' => 0,
    'currentPage' => 1
];

try {
    // --- 分頁參數 ---
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $recordsPerPage = isset($_GET['recordsPerPage']) ? (int)$_GET['recordsPerPage'] : 10;
    $offset = ($page - 1) * $recordsPerPage;

    // --- 篩選參數 ---
    $filters = isset($_GET['filters']) ? $_GET['filters'] : [];
    $selectedYear = isset($filters['year']) ? (int)$filters['year'] : date('Y');

    // --- SQL 查詢建構 ---
    $baseSql = "FROM IR_track
                LEFT JOIN user ON user.id = IR_track.QC_Assignee
                WHERE YEAR(IR_track.IR_date) = :year";
    
    $countSql = "SELECT COUNT(*) " . $baseSql;
    $dataSql = "SELECT
                    IR_track.*,
                    CONCAT(DATE_FORMAT(IR_track.IR_date, '%y'), 'y/', DATE_FORMAT(IR_track.IR_date, '%c/%e')) AS IR_date_T,
                    DATE_FORMAT(IR_track.QCGet, '%c/%e') AS QCGet_T,
                    DATE_FORMAT(IR_track.pmGet, '%c/%e') AS pmGet_T,
                    DATE_FORMAT(IR_track.ateGet, '%c/%e') AS ateGet_T,
                    DATE_FORMAT(IR_track.bossGet, '%c/%e') AS bossGet_T,
                    DATE_FORMAT(IR_track.Closed_At, '%c/%e') AS Closed_T,
                    DATE_FORMAT(IR_track.Created_At, '%c/%e') AS Created_At_T,
                    DATE_FORMAT(IR_track.in_review, '%c/%e') AS in_review_T,
                    user.user_cname
                " . $baseSql;

    $whereClauses = [];
    $params = [':year' => $selectedYear];

    // 全域搜尋
    if (!empty($filters['globalSearch'])) {
        $searchTerm = '%' . $filters['globalSearch'] . '%';
        $whereClauses[] = "(IR_track.Client_name LIKE :globalSearch OR IR_track.d_id LIKE :globalSearch OR IR_track.Processing_items LIKE :globalSearch OR IR_track.IR_no LIKE :globalSearch OR IR_track.C_IR LIKE :globalSearch)";
        $params[':globalSearch'] = $searchTerm;
    }

    // 客戶篩選
    if (!empty($filters['customer'])) {
        $whereClauses[] = "IR_track.Client_name LIKE :customer";
        $params[':customer'] = '%' . $filters['customer'] . '%';
    }

    // 料號/製程篩選
    if (!empty($filters['bom'])) {
        $whereClauses[] = "(IR_track.d_id LIKE :bom OR IR_track.Processing_items LIKE :bom)";
        $params[':bom'] = '%' . $filters['bom'] . '%';
    }

    // 數量篩選
    if (!empty($filters['ir'])) {
        preg_match('/(>|<|=)?\s*(\d+)/', $filters['ir'], $matches);
        $operator = $matches[1] ?: '=';
        $value = $matches[2];
        $whereClauses[] = "IR_track.Qty $operator :qty";
        $params[':qty'] = $value;
    }

    // 退單日期篩選
    if (!empty($filters['date'])) {
        preg_match('/(>|<|=)?\s*([\d\/-]+)/', $filters['date'], $matches);
        $operator = $matches[1] ?: '=';
        $dateValue = date('Y-m-d', strtotime(str_replace('/', '-', $matches[2])));
        $whereClauses[] = "DATE(IR_track.IR_date) $operator :ir_date";
        $params[':ir_date'] = $dateValue;
    }

    // 狀態篩選
    if (!empty($filters['status'])) {
        switch ($filters['status']) {
            case 'in_progress':
                $whereClauses[] = "IR_track.pmGet IS NULL";
                break;
            case 'transferred_to_qc':
                $whereClauses[] = "IR_track.QCGet IS NOT NULL AND IR_track.ateGet IS NULL";
                break;
            case 'transferred_to_ate':
                $whereClauses[] = "IR_track.ateGet IS NOT NULL AND IR_track.pmGet IS NULL";
                break;
            case 'transferred_to_pm':
                 $whereClauses[] = "IR_track.pmGet IS NOT NULL AND IR_track.bossGet IS NULL";
                break;
            case 'transferred_to_boss':
                $whereClauses[] = "IR_track.bossGet IS NOT NULL AND IR_track.Closed_At IS NULL";
                break;
            case 'completed':
                $whereClauses[] = "IR_track.Closed_At IS NOT NULL";
                break;
        }
    }

    if (!empty($whereClauses)) {
        $clauseString = " AND " . implode(' AND ', $whereClauses);
        $countSql .= $clauseString;
        $dataSql .= $clauseString;
    }

    // --- 執行計數查詢 ---
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    // --- 執行資料查詢 ---
    $dataSql .= " ORDER BY COALESCE(IR_track.Modified_At, IR_track.Created_At) DESC LIMIT :limit OFFSET :offset";
    $dataStmt = $db->prepare($dataSql);

    // 綁定分頁參數
    foreach ($params as $key => &$val) {
        $dataStmt->bindParam($key, $val);
    }
    unset($val);
    $dataStmt->bindParam(':limit', $recordsPerPage, PDO::PARAM_INT);
    $dataStmt->bindParam(':offset', $offset, PDO::PARAM_INT);

    $dataStmt->execute();
    $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- 準備回應 ---
    $response['status'] = 'success';
    $response['data'] = $data;
    $response['totalRecords'] = $totalRecords;
    $response['totalPages'] = ceil($totalRecords / $recordsPerPage);
    $response['currentPage'] = $page;

} catch (PDOException $e) {
    $response['message'] = '資料庫查詢錯誤: ' . $e->getMessage();
    error_log($response['message']);
} catch (Exception $e) {
    $response['message'] = '系統錯誤: ' . $e->getMessage();
    error_log($response['message']);
}

echo json_encode($response);

?>