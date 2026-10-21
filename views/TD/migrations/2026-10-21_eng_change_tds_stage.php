<?php
/**
 * 工程變更：新增「技術課人員」關卡（先人員簽、再技術課主管簽）——把目前還卡在「技術課主管」關、
 * 且主管尚未簽的單據退回到「技術課人員」那一關（刪掉還沒有決定的舊待簽紀錄，重發通知給技術課人員）。
 * 已結案的舊單據不動（它們沒有人員那一格，列印時那格留白）；「單一製程」欄位語意＝客供料，不必轉換資料。
 * 可重複執行。用法：php 2026-10-21_eng_change_tds_stage.php [--run]
 */
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'C:/MAMP/htdocs';
include 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
include 'C:/MAMP/htdocs/EGsystem/src/common/eng_change_lib.php';
$run = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
ec_ensure_schema($db);
$rows = $db->query("SELECT ec_id, doc_no FROM eng_change WHERE status='TD' AND sign_td_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo ($run ? '處理 ' : '（試算）') . $r['doc_no'] . "\n";
    if (!$run) continue;
    $id = (int)$r['ec_id'];
    $db->prepare("DELETE FROM approval_record WHERE module=? AND entity_id=? AND level='TD' AND status='pending'")->execute([EC_APPROVAL_MODULE, $id]);
    $db->prepare("UPDATE eng_change SET status='TDS' WHERE ec_id=?")->execute([$id]);
    ec_route_to_stage($db, ec_row($db, $id), 'TDS', 0, '系統');
}
echo count($rows) . " 筆\n";
