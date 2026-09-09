<?php
/**
 * bom_progress_lib.php — 單一 BOM 的逐關製程進度（唯讀）
 *
 * 唯一實作（鐵律4）：原本只寫在 src/store/PersonalTask_API.php 的 pt_bom_progress()，
 * 2026-09-09 工程處理紀錄也要顯示同一份 BOM 製程條，故抽成共用庫；
 * PersonalTask_API 的 pt_bom_progress() 已改為呼叫這裡，兩邊永遠同一套口徑。
 *
 * 口徑比照 bom_tracking 的 get_matched_boms：一個 bom_sn 一個節點、排除 skip、
 * 目前關卡取「發包日與檢驗日孰晚」最新的那一關。
 */
if (!function_exists('eg_bom_progress')) {
// 單一 BOM 的逐關製程進度（唯讀，比照 bom_tracking get_matched_boms 口徑）。查無回 null。
function eg_bom_progress(PDO $db, string $bom): ?array {
    $st = $db->prepare("SELECT bom, d_id, Client_Name, sqty, processing_state, Delivery_date FROM bom WHERE bom = ?");
    $st->execute([$bom]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) return null;
    // 逐關節點（一個 bom_sn 一節點，排除 skip；含外包廠商 maker）
    $st = $db->prepare("SELECT bi.bom_sn, MAX(pn.ProcessName) AS name, MAX(bi.maker_id) AS maker,
            MAX(bi.outsource_date) AS outsource_date, MAX(bi.return_date) AS return_date
        FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        WHERE bi.bom = ? AND bi.processing_state != 'skip'
        GROUP BY bi.bom_sn ORDER BY bi.bom_sn");
    $st->execute([$bom]);
    $nodeRows = $st->fetchAll(PDO::FETCH_ASSOC);
    $processCount = count($nodeRows);
    $st = $db->prepare("SELECT COUNT(DISTINCT bi.bom_sn) FROM bom_ing bi
        WHERE bi.bom = ? AND bi.processing_state != 'skip' AND bi.bom_sn <= (
            SELECT bi2.bom_sn FROM bom_ing bi2 WHERE bi2.bom = ? AND bi2.processing_state != 'skip'
            ORDER BY GREATEST(COALESCE(bi2.outsource_date,'0000-00-00'), COALESCE(bi2.QC_check_date,'0000-00-00')) DESC, bi2.bom_sn DESC LIMIT 1
        )");
    $st->execute([$bom, $bom]);
    $currentStep = (int)$st->fetchColumn();
    $isClosed = ((string)$m['processing_state'] === '1');
    $progressPct = $isClosed ? 100 : ($processCount > 0 ? round($currentStep / $processCount * 100, 1) : null);
    $nodes = [];
    foreach ($nodeRows as $i => $n) {
        $rank = $i + 1;
        $nodes[] = [
            'bom_sn' => $n['bom_sn'], 'name' => $n['name'] ?: ('關卡' . $rank), 'maker' => $n['maker'],
            'outsource_date' => $n['outsource_date'], 'return_date' => $n['return_date'],
            'reached' => ($isClosed || $rank <= $currentStep) ? 1 : 0,
            'current' => (!$isClosed && $rank === $currentStep) ? 1 : 0,
        ];
    }
    $latestName = ($currentStep > 0 && isset($nodeRows[$currentStep - 1])) ? $nodeRows[$currentStep - 1]['name'] : null;
    return [
        'bom' => $bom, 'd_id' => $m['d_id'], 'Client_Name' => $m['Client_Name'], 'sqty' => $m['sqty'],
        'processing_state' => $m['processing_state'], 'is_closed' => $isClosed ? 1 : 0,
        'process_count' => $processCount, 'current_step' => $currentStep,
        'progress_pct' => $progressPct, 'latest_process_name' => $isClosed ? '結案' : $latestName,
        'delivery_date' => $m['Delivery_date'], 'nodes' => $nodes,
    ];
}

}
