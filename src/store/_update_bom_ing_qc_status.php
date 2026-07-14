<?php
// c:\MAMP\htdocs\EGsystem\src\store\_update_bom_ing_qc_status.php

/**
 * 檢查 QC 總數是否達到訂單數量，並在達標時更新 bom_ing.QC_check 狀態。
 *
 * - 如果 (允收 + 異常) >= 訂單總數：
 *   - 且 允收 > 異常，則狀態更新為 'ok'。
 *   - 否則 (允收 <= 異常)，則狀態更新為 'ng'。
 * - 如果未達標，則不進行任何操作。
 *
 * @param PDO $db The database connection object.
 * @param int $bom_ing_fid The bom_ing_fid to check and update.
 * @return void
 */
function updateBomIngQcStatus(PDO $db, int $bom_ing_fid): void
{
    try {
        // 1. 從 bom_ing 取得訂單總數 (sqty)
        $stmt_sqty = $db->prepare("SELECT sqty FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid");
        $stmt_sqty->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_INT);
        $stmt_sqty->execute();
        $bom_ing_data = $stmt_sqty->fetch(PDO::FETCH_ASSOC);

        // 如果沒有 sqty 或 sqty 為 0，則沒有比較基準，直接返回
        if (!$bom_ing_data || (float)$bom_ing_data['sqty'] <= 0) {
            return;
        }
        $total_order_qty = (float)$bom_ing_data['sqty'];

        // 2. 從 qc_check 取得 'ok' 和 'QQ' 的數量總和
        $stmt_sums = $db->prepare("
            SELECT 
                SUM(CASE WHEN QC_check = 'ok' THEN QC_ok_sqty ELSE 0 END) as total_ok,
                SUM(CASE WHEN QC_check = 'QQ' THEN QC_QQ_sqty ELSE 0 END) as total_qq
            FROM qc_check 
            WHERE bom_ing_fid_ref = :bom_ing_fid
        ");
        $stmt_sums->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_INT);
        $stmt_sums->execute();
        $qc_sums = $stmt_sums->fetch(PDO::FETCH_ASSOC);

        $total_ok_qty = (float)($qc_sums['total_ok'] ?? 0);
        $total_qq_qty = (float)($qc_sums['total_qq'] ?? 0);
        $total_checked_qty = $total_ok_qty + $total_qq_qty;

        // 3. 執行新的判斷邏輯（三段式）
        if ($total_checked_qty >= $total_order_qty) {
            // 全數檢驗完成：寫入最終狀態，並推進 processing_state → 'P'
            $new_status = ($total_ok_qty > $total_qq_qty) ? 'ok' : 'ng';
            $new_processing_state = 'P';

            $stmt_update_bom_ing = $db->prepare(
                "UPDATE bom_ing
                 SET QC_check = :new_status,
                     processing_state = :processing_state,
                     QC_check_date = NOW(),
                     Modified_At = NOW()
                 WHERE bom_ing_fid = :bom_ing_fid"
            );
            $stmt_update_bom_ing->bindParam(':new_status', $new_status, PDO::PARAM_STR);
            $stmt_update_bom_ing->bindParam(':processing_state', $new_processing_state, PDO::PARAM_STR);
            $stmt_update_bom_ing->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_INT);
            $stmt_update_bom_ing->execute();
        } elseif ($total_checked_qty > 0) {
            // 部分檢驗：寫入目前最佳狀態與日期，不異動 processing_state
            // 有任何允收 → ok；只有異常 → QQ
            $partial_status = ($total_ok_qty > 0) ? 'ok' : 'QQ';

            $stmt_update_bom_ing = $db->prepare(
                "UPDATE bom_ing
                 SET QC_check = :new_status,
                     QC_check_date = NOW(),
                     Modified_At = NOW()
                 WHERE bom_ing_fid = :bom_ing_fid"
            );
            $stmt_update_bom_ing->bindParam(':new_status', $partial_status, PDO::PARAM_STR);
            $stmt_update_bom_ing->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_INT);
            $stmt_update_bom_ing->execute();
        } else {
            // 無任何 QC 紀錄：若 processing_state 為 'P' 則退回 'Q'（處理刪除 QC 後數量歸零的情況）
            $stmt_update_bom_ing = $db->prepare(
                "UPDATE bom_ing SET processing_state = 'Q', Modified_At = NOW() WHERE bom_ing_fid = :bom_ing_fid AND processing_state = 'P'"
            );
            $stmt_update_bom_ing->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_INT);
            $stmt_update_bom_ing->execute();
        }

    } catch (PDOException $e) {
        // 記錄錯誤，但不中斷主腳本的執行
        error_log("Error in updateBomIngQcStatus for bom_ing_fid {$bom_ing_fid}: " . $e->getMessage());
    }
}