<?php
/**
 * 一次性：風險／機會評分表重新制定為 4 級 × 4 級（可重複執行；只有「發生率還是舊的 3 級」時才會動資料）。
 * 原因：舊的 3×3 表中，嚴重度最高（3）的風險即使把發生率降到最低（1）仍有 3 分＝高度危險，
 *       採取措施後永遠降不到「不需要因應措施」。新表：S4×O1=4 ＝中度危險（不需因應）。
 * 做法：①把新的預設設定寫進資料庫（風險準則表A、嚴重度表B、發生率表C）②既有資料換算成新級距
 *       嚴重度(S)／預期效益(B)：1→1、2→2、3→3（只在最上面新增第 4 級，舊的三級門檻不變）
 *       發生率(O)／可行性(F)：1→1、2→3、3→4（舊的 0-40/41-60/61-100% 對應新的 0-25/51-75/76-100%）
 *       ③措施後的發生率(O)／可行性(F)依新規則重新帶入。
 * 用法：php 本檔 [--run]（不加 --run 只試算）
 */
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'C:/MAMP/htdocs';
include_once 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
include_once 'C:/MAMP/htdocs/EGsystem/src/common/risk_mgmt_rules_lib.php';
$run = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
rm_ensure_schema($db);
$cur = rm_settings($db);
if (count($cur['rm_likelihood']) >= 4) { echo "已經是新的評分表，不需要再執行\n"; exit; }
$def = rm_settings_default();
$map = [1 => 1, 2 => 3, 3 => 4];
$cnt = (int)$db->query("SELECT COUNT(*) FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id WHERE d.kind='risk' AND i.severity IS NOT NULL")->fetchColumn();
echo "要換算的評估列：$cnt 列\n";
if (!$run) { echo "（試算，加 --run 才寫入）\n"; exit; }
$db->beginTransaction();
try {
    foreach (['rm_impact', 'rm_likelihood', 'rm_levels'] as $k) rm_setting_write($db, $k, $def[$k], 'migration');
    $rows = $db->query("SELECT i.item_id, i.frequency, i.frequency_after FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id WHERE d.kind='risk' AND i.frequency IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $up = $db->prepare("UPDATE rm_item SET frequency=?, frequency_after=NULL WHERE item_id=?");
    foreach ($rows as $r) $up->execute([$map[(int)$r['frequency']] ?? (int)$r['frequency'], $r['item_id']]);
    $db->commit();
} catch (Throwable $e) { $db->rollBack(); echo '失敗：' . $e->getMessage() . "\n"; exit(1); }
// 措施後依新規則重新帶入
$set = rm_settings($db);
$rows = $db->query("SELECT i.item_id, i.severity, i.frequency, i.ro_kind FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id WHERE d.kind='risk' AND i.severity IS NOT NULL AND i.frequency IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$n = 0;
foreach ($rows as $r) {
    $a = rm_after_suggest($set, $r['severity'], $r['frequency'], (string)$r['ro_kind']);
    if ($a !== null) { $db->prepare("UPDATE rm_item SET frequency_after=? WHERE item_id=?")->execute([$a, $r['item_id']]); $n++; }
}
echo "完成：設定已更新、發生率／可行性已換算、措施後已重新帶入 $n 列\n";
