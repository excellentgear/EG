<?php
// src/store/_update_qc_completion.php
// QC 完工：封存最終狀態（同時補齊 QC_check，防止 ok/QQ 存檔時中途失敗留下不一致）

session_start();
if (!isset($_SESSION['userName'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

include_once '../common/DBConnection.php';
include_once '../common/_config.php';

$conn   = new DBConnection();
$db     = $conn->getPDO();
$userId = (int)($_SESSION['id'] ?? 0);

$bomIngFid = (int)($_POST['bom_ing_fid'] ?? 0);

if (!$bomIngFid) {
    echo json_encode(['success' => false, 'message' => '缺少 bom_ing_fid']);
    exit;
}

try {
    $db->beginTransaction();

    // 1. 確認此筆存在且尚未完工，同時取出現有 QC_check
    $check = $db->prepare("SELECT bom_ing_fid, qc_completed, QC_check FROM bom_ing WHERE bom_ing_fid = ?");
    $check->execute([$bomIngFid]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => '找不到此製程紀錄']);
        exit;
    }
    if ($row['qc_completed'] == 1) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => '此筆已完工，請勿重複操作']);
        exit;
    }

    // 2. 從 qc_check 計算累計數量，補齊 bom_ing.QC_check（若尚未寫入）
    if ($row['QC_check'] === null) {
        $stmtSums = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN QC_check='ok' THEN QC_ok_sqty ELSE 0 END), 0) AS total_ok,
                COALESCE(SUM(CASE WHEN QC_check='QQ' THEN QC_QQ_sqty ELSE 0 END), 0) AS total_qq
            FROM qc_check
            WHERE bom_ing_fid_ref = ?
        ");
        $stmtSums->execute([$bomIngFid]);
        $sums     = $stmtSums->fetch(PDO::FETCH_ASSOC);
        $totalOk  = (float)$sums['total_ok'];
        $totalQq  = (float)$sums['total_qq'];

        if ($totalOk > 0 || $totalQq > 0) {
            $calculatedQcCheck = ($totalOk >= $totalQq) ? 'ok' : 'ng';
            $stmtFixQc = $db->prepare("
                UPDATE bom_ing
                SET QC_check = :qc_check, QC_check_date = NOW()
                WHERE bom_ing_fid = :fid AND QC_check IS NULL
            ");
            $stmtFixQc->execute([':qc_check' => $calculatedQcCheck, ':fid' => $bomIngFid]);
        }
    }

    // 3. 標記完工並推進 processing_state → 'P'
    $stmtUpdate = $db->prepare("
        UPDATE bom_ing
        SET qc_completed     = 1,
            qc_completed_by  = :uid,
            qc_completed_at  = NOW(),
            processing_state = 'P',
            Modified_By      = :uid2,
            Modified_At      = NOW()
        WHERE bom_ing_fid    = :fid
    ");
    $stmtUpdate->execute([
        ':uid'  => $userId,
        ':uid2' => $userId,
        ':fid'  => $bomIngFid,
    ]);

    if ($stmtUpdate->rowCount() === 0) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => '完工更新失敗，請重新整理後再試']);
        exit;
    }

    $db->commit();

    echo json_encode(['success' => true, 'message' => 'QC 完工已記錄']);

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[_update_qc_completion] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
}
