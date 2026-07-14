<?php
// c:\MAMP\htdocs\EGsystem\src\store\_delete_bom_ing.php
if (!isset($_SESSION)) {
    session_start();
}

include_once '../common/DBConnection.php'; // Assuming DBConnection class is here for $db if not using _config.php's $db
include '../common/_config.php'; // For $db PDO object

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bom_ing_fid']) && !empty($_POST['bom_ing_fid'])) {
        $bom_ing_fid = $_POST['bom_ing_fid']; // Assuming bom_ing_fid is a string like other FIDs. Adjust PDO::PARAM_ if it's an int.

        // Fetch BOM ID to re-sequence later and to check count
        $bom_ing_info_stmt = $db->prepare("SELECT bom FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid_info");
        $bom_ing_info_stmt->bindParam(':bom_ing_fid_info', $bom_ing_fid, PDO::PARAM_STR);
        $bom_ing_info_stmt->execute();
        $bom_ing_info = $bom_ing_info_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bom_ing_info) {
            $response['message'] = 'Process step (BOM Ing FID) not found.';
            echo json_encode($response);
            exit;
        }
        $current_bom_id = $bom_ing_info['bom'];

        // Check if it's the last process step for this BOM
        $count_stmt = $db->prepare("SELECT COUNT(*) as count FROM bom_ing WHERE bom = :bom");
        $count_stmt->bindParam(':bom', $current_bom_id, PDO::PARAM_STR);
        $count_stmt->execute();
        $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);

        if ($count_result && $count_result['count'] <= 1) {
            $response['message'] = 'Cannot delete the last process step of a BOM.';
            echo json_encode($response);
            exit;
        }

        try {
            $db->beginTransaction();

            $deleteStmt = $db->prepare("DELETE FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid");
            $deleteStmt->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_STR);
            
            if ($deleteStmt->execute()) {
                if ($deleteStmt->rowCount() > 0) {
                    // No re-sequencing of bom_sn
                    $db->commit();
                    $response['success'] = true;
                    $response['message'] = 'Process step deleted successfully.';
                } else {
                    $db->rollBack(); // Rollback if no rows were deleted (e.g., FID didn't exist)
                    $response['message'] = 'No process step found with the given FID, or it was already deleted.';
                }
            } else {
                $db->rollBack();
                $errorInfo = $deleteStmt->errorInfo();
                $response['message'] = 'Failed to delete process step: ' . htmlspecialchars($errorInfo[2]);
            }
        } catch (PDOException $e) {
            $db->rollBack();
            $response['message'] = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    } else {
        $response['message'] = 'bom_ing_fid is required.';
    }
} else {
    $response['message'] = 'Invalid request method.';
}

echo json_encode($response);
exit;
?>