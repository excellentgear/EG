<?php
/**
 * 2026-09-30_gsop_items_tpl.php
 * 種入「標準作業流程 SOP」**專用**的檢驗項目預設值（製程 12 齒研）。
 *
 * 使用者 2026-09-30：
 *   ①「檢驗項目請幫我自動設定為特定料號的製程代號12 齒研 的預設項目」
 *   ②「綁定機台的特定料號SOP 的檢驗項目預設跟 SIP 的檢驗項目不同，請注意要分開」
 *   ③「擔當者固定顯示為生產」「不需要檢驗頻率欄位」
 *
 * 所以這一套存在 tpl_kind='gproc'（SIP 那一套是 'proc'，兩邊互不影響）。
 * **內容取自紙本 as-sop 那批 xlsx 左下角實際的檢驗表**（跨齒厚／齒根徑／齒型·導程·節距精度／
 * 外觀三列），不是抄 SIP 那一套——SOP 上的檢驗是操作員自主檢查，SIP 是品管的檢驗指導書，
 * 兩者本來就不一樣（這正是使用者要求分開的原因）。
 *
 * 用法：php 2026-09-30_gsop_items_tpl.php        試算
 *       php 2026-09-30_gsop_items_tpl.php --run  實際寫入
 */
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';

$RUN = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);

const PROC_NO = 12;                       // 齒研
$fam = ss_tpl_family('gsop');             // gproc / gstd
$own = ss_gsop_owner_dept($db);           // 生產（由 ss_owner_depts 的顯示名稱找，不寫死 id）
if ($own <= 0) exit("找不到顯示名稱為「生產」的擔當者部門，請先到設定→擔當者與檢驗方法設定。\n");

/* 管理重點／品質特性裡的 {} ＝ 現場要填的空格（與 SIP 那一套同一種填空語法）。
   鎖定欄位（lock_ctrl/lock_q）帶進文件後不可改，只有 {} 那幾格可以填。 */
$ROWS = [
    ['ctrl_point' => '跨齒厚({}齒)', 'q_char' => '', 'lock_ctrl' => 1, 'lock_q' => 0,
     'method' => '盤式分厘卡', 'freq' => '', 'note' => ''],
    ['ctrl_point' => '齒根徑', 'q_char' => '', 'lock_ctrl' => 1, 'lock_q' => 0,
     'method' => '尖頭分厘卡', 'freq' => '', 'note' => ''],
    ['ctrl_point' => '齒型精度', 'q_char' => '依圖面', 'lock_ctrl' => 1, 'lock_q' => 0,
     'input_kind' => 'gear_grade_opt',          // 可按「挑等級」也可自己打（使用者 2026-10-01）
     'method' => '齒輪量測儀', 'freq' => '', 'note' => ''],
    ['ctrl_point' => '導程精度', 'q_char' => '依圖面', 'lock_ctrl' => 1, 'lock_q' => 0,
     'input_kind' => 'gear_grade_opt',          // 可按「挑等級」也可自己打（使用者 2026-10-01）
     'method' => '齒輪量測儀', 'freq' => '', 'note' => ''],
    ['ctrl_point' => '節距精度', 'q_char' => '依圖面', 'lock_ctrl' => 1, 'lock_q' => 0,
     'input_kind' => 'gear_grade_opt',          // 可按「挑等級」也可自己打（使用者 2026-10-01）
     'method' => '齒輪量測儀', 'freq' => '', 'note' => ''],
    ['ctrl_point' => '外觀', 'q_char' => '不可碰傷', 'lock_ctrl' => 1, 'lock_q' => 1,
     'method' => '目視', 'freq' => '', 'note' => ''],
    ['ctrl_point' => '外觀', 'q_char' => '不可生鏽', 'lock_ctrl' => 1, 'lock_q' => 1,
     'method' => '目視', 'freq' => '', 'note' => ''],
    ['ctrl_point' => '外觀', 'q_char' => '不可有震刀紋', 'lock_ctrl' => 1, 'lock_q' => 1,
     'method' => '目視', 'freq' => '', 'note' => ''],
];
foreach ($ROWS as &$r) { $r['owner_dept_id'] = $own; $r['owner'] = '生產'; }
unset($r);

$cur = ss_tpl_rows($db, $fam['proc'], PROC_NO);
echo "=== 標準作業流程 SOP 專用的檢驗項目預設值（tpl_kind=" . $fam['proc'] . "、製程 " . PROC_NO . " 齒研）===\n";
echo "目前已有 " . count($cur) . " 列" . ($cur ? "（會被整組取代）" : "") . "，要寫入 " . count($ROWS) . " 列：\n";
foreach ($ROWS as $i => $r) {
    printf("  %d. %-14s %-14s 擔當=%s 方法=%s\n", $i + 1, $r['ctrl_point'], $r['q_char'] ?: '（上下限）',
           $r['owner'], $r['method']);
}
echo "\nSIP 那一套（tpl_kind=" . ss_tpl_family('sip')['proc'] . "）目前有 "
   . count(ss_tpl_rows($db, ss_tpl_family('sip')['proc'], PROC_NO)) . " 列，**本次完全不會動到**。\n";

if (!$RUN) { echo "\n（試算，加 --run 才寫入）\n"; exit; }

$db->beginTransaction();
try {
    ss_tpl_replace($db, $fam['proc'], PROC_NO, $ROWS, 0);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    exit("\n!! 寫入失敗，已回復：" . $e->getMessage() . "\n");
}
$after = ss_tpl_rows($db, $fam['proc'], PROC_NO);
echo "\n[OK] 已寫入 " . count($after) . " 列。\n";
echo "  SIP 那一套仍是 " . count(ss_tpl_rows($db, ss_tpl_family('sip')['proc'], PROC_NO)) . " 列（未變動）。\n";
echo "  新建立的標準作業流程SOP 會自動帶這一套；既有文件要帶請在文件裡按「整段換成製程預設項目」。\n";
