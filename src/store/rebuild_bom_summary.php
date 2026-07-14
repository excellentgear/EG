<?php
// 檔案位置: c:\MAMP\htdocs\EGsystem\src\store\rebuild_bom_summary.php

// 設定執行時間限制，避免大量資料處理時超時 (5分鐘)
set_time_limit(300); 

// 載入資料庫連線
include_once '../common/DBConnection.php';
include_once '../common/_config.php';

// 確保只在 CLI 或有權限的情況下執行 (簡單防護)
// if (php_sapi_name() !== 'cli' && !isset($_SESSION['userName'])) {
//     die('Access Denied');
// }

$db = new DBConnection();
$pdo = $db->getPDO();

echo "<pre>"; // 方便瀏覽器查看輸出
echo "開始重建 bom_summary...\n";
$start_time = microtime(true);

// 1. 獲取所有活躍的 BOM (未結案或有製程的)
// 這裡選擇 processing_state IS NULL (未結案) 的 BOM
$sql_boms = "SELECT bom FROM bom WHERE processing_state IS NULL OR processing_state != '1'";
$stmt_boms = $pdo->query($sql_boms);
$boms = $stmt_boms->fetchAll(PDO::FETCH_COLUMN);

$total_boms = count($boms);
echo "共找到 " . $total_boms . " 筆 BOM 需要更新。\n";

$count = 0;
$batch_size = 50; // 每處理 50 筆輸出一次進度

foreach ($boms as $bom) {
    updateBomSummary($bom, $pdo);
    $count++;
    
    if ($count % $batch_size == 0) {
        echo "已處理 $count / $total_boms 筆...\n";
        if (php_sapi_name() !== 'cli') {
            flush();
            ob_flush();
        }
    }
}

$end_time = microtime(true);
$duration = round($end_time - $start_time, 2);
echo "完成！共更新 $count 筆資料，耗時 $duration 秒。\n";
echo "</pre>";

/**
 * 更新單一 BOM 的摘要資訊
 */
function updateBomSummary($bom, $pdo) {
    // 1. 基礎資訊 (d_id)
    $stmt = $pdo->prepare("SELECT d_id FROM bom WHERE bom = ?");
    $stmt->execute([$bom]);
    $basic = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$basic) return; // BOM 不存在於 bom 表，跳過
    $d_id = $basic['d_id'];

    // 2. 取得該 BOM 所有製程的 SN (用於計算總數與進度位置)
    // 為了符合 JS 邏輯：先排序，再找位置
    $stmt = $pdo->prepare("SELECT DISTINCT bom_sn FROM bom_ing WHERE bom = ? ORDER BY bom_sn ASC");
    $stmt->execute([$bom]);
    $all_sns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $process_count = count($all_sns);

    // 3. 決定「目前」是哪一關 (Latest Process)
    // 邏輯：取 outsource_date 或 QC_check_date 較新者為準，若日期相同取 bom_sn 較大者
    $stmt = $pdo->prepare("
        SELECT 
            bi.bom_sn,
            bi.process_no,
            pn.ProcessName,
            ml.maker_id,
            bi.processing_state,
            GREATEST(COALESCE(bi.outsource_date, '0000-00-00'), COALESCE(bi.QC_check_date, '0000-00-00')) as event_date
        FROM bom_ing bi
        LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
        LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
        WHERE bi.bom = ?
        ORDER BY event_date DESC, bi.bom_sn DESC
        LIMIT 1
    ");
    $stmt->execute([$bom]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    $current_bom_sn = (int)($latest['bom_sn'] ?? 0);
    $latest_process_name = $latest['ProcessName'] ?? null;
    $latest_maker = $latest['maker_id'] ?? null;
    $latest_processing_state = $latest['processing_state'] ?? null;
    $latest_event_date = ($latest['event_date'] && $latest['event_date'] != '0000-00-00') ? $latest['event_date'] : null;

    // 4. 計算進度百分比 (Progress %)
    // 邏輯：找出 current_bom_sn 在 all_sns 中的索引位置 (1-based)
    $progress_pct = 0.00;
    if ($process_count > 0 && $current_bom_sn > 0) {
        $index = array_search($current_bom_sn, $all_sns);
        if ($index !== false) {
            $current_step = $index + 1;
            $progress_pct = round(($current_step / $process_count) * 100, 2);
        }
    }

    // 5. QC 狀態統計
    // 累計數量
    $stmt = $pdo->prepare("
        SELECT 
            SUM(IFNULL(qc.QC_QQ_sqty, 0) + IFNULL(qc.QC_ng_sqty, 0) + IFNULL(qc.QC_aod_sqty, 0) + IFNULL(qc.QC_ok_sqty, 0)) as total_qc_qty
        FROM bom_ing bi
        JOIN QC_check qc ON bi.bom_ing_fid = qc.bom_ing_fid_ref
        WHERE bi.bom = ?
    ");
    $stmt->execute([$bom]);
    $qc_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_qc_qty = (int)($qc_stats['total_qc_qty'] ?? 0);

    // 最新 QC 結果
    $stmt = $pdo->prepare("
        SELECT 
            qc.QC_check as latest_qc_result,
            qc.QC_ps as latest_qc_remark,
            qc.QC_check_date as latest_qc_date
        FROM bom_ing bi
        JOIN QC_check qc ON bi.bom_ing_fid = qc.bom_ing_fid_ref
        WHERE bi.bom = ?
        ORDER BY qc.QC_check_date DESC, qc.QC_check_id DESC
        LIMIT 1
    ");
    $stmt->execute([$bom]);
    $qc_latest = $stmt->fetch(PDO::FETCH_ASSOC);

    $latest_qc_result = $qc_latest['latest_qc_result'] ?? null;
    $latest_qc_remark = $qc_latest['latest_qc_remark'] ?? null;
    $latest_qc_date = $qc_latest['latest_qc_date'] ?? null;

    // 6. 寫入 bom_summary (Insert or Update)
    $sql = "INSERT INTO bom_summary (
        bom, d_id, 
        process_count, current_bom_sn, progress_pct,
        latest_process_name, latest_maker, latest_event_date, latest_processing_state,
        total_qc_qty, latest_qc_result, latest_qc_remark, latest_qc_date,
        updated_at
    ) VALUES (
        :bom, :d_id, 
        :process_count, :current_bom_sn, :progress_pct,
        :latest_process_name, :latest_maker, :latest_event_date, :latest_processing_state,
        :total_qc_qty, :latest_qc_result, :latest_qc_remark, :latest_qc_date,
        NOW()
    ) ON DUPLICATE KEY UPDATE
        d_id = VALUES(d_id),
        process_count = VALUES(process_count),
        current_bom_sn = VALUES(current_bom_sn),
        progress_pct = VALUES(progress_pct),
        latest_process_name = VALUES(latest_process_name),
        latest_maker = VALUES(latest_maker),
        latest_event_date = VALUES(latest_event_date),
        latest_processing_state = VALUES(latest_processing_state),
        total_qc_qty = VALUES(total_qc_qty),
        latest_qc_result = VALUES(latest_qc_result),
        latest_qc_remark = VALUES(latest_qc_remark),
        latest_qc_date = VALUES(latest_qc_date),
        updated_at = NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':bom' => $bom,
        ':d_id' => $d_id,
        ':process_count' => $process_count,
        ':current_bom_sn' => $current_bom_sn,
        ':progress_pct' => $progress_pct,
        ':latest_process_name' => $latest_process_name,
        ':latest_maker' => $latest_maker,
        ':latest_event_date' => $latest_event_date,
        ':latest_processing_state' => $latest_processing_state,
        ':total_qc_qty' => $total_qc_qty,
        ':latest_qc_result' => $latest_qc_result,
        ':latest_qc_remark' => $latest_qc_remark,
        ':latest_qc_date' => $latest_qc_date
    ]);
}
?>
