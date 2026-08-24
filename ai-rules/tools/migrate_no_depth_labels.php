<?php
/**
 * 2026-08-24 料號標籤「一律不可設定深度」資料遷移（使用者要求）
 *
 * 1. 沉頭孔（sub 26）清除「沉頭孔徑」value_min，只留 數量＋小孔規格(tol_upper)
 * 2. 鍵（label 3）／中心牙孔（label 4）：使用者已把「長×寬」旗標關掉，
 *    但值還留在 value_min/value_max，純數字標籤是讀 input_value ⇒ 畫面顯示不出來。
 *    改成 input_value = value_min（鍵寬／牙孔規格），清掉 value_min/value_max（深度）。
 * 3. 內孔(預留)（label 25）→ 內孔（label 43）：把「圖面孔徑」搬成內孔的值，
 *    車床孔徑與深度不留；同一料號同一數值不重複建立。搬完把 label 25 停用。
 * 4. 旗標收斂：帶深度的設定改成不帶深度的等價設定。
 *
 * 用法： php migrate_no_depth_labels.php [--dry]
 * 可重複執行（每步都先檢查是否已完成）。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
$DRY = in_array('--dry', $argv, true);
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306","EG-TS2024","excell30367593",
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET NAMES utf8mb4");

function num($v){ // 3.5000 → 3.5 ； 75.0000 → 75
    if ($v === null || $v === '') return null;
    $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}
$say = function($s) use ($DRY) { echo ($DRY ? '[DRY] ' : '[RUN] ') . $s . PHP_EOL; };

$db->beginTransaction();
try {

// ── 1. 沉頭孔徑清除 ───────────────────────────────────────────────
$n = (int)$db->query("SELECT COUNT(*) FROM item_sub_label_map WHERE sub_id=26 AND value_min IS NOT NULL")->fetchColumn();
$say("① 沉頭孔徑(sub 26 value_min) 待清除 {$n} 筆");
if ($n && !$DRY) $db->exec("UPDATE item_sub_label_map SET value_min=NULL WHERE sub_id=26 AND value_min IS NOT NULL");

// ── 2. 鍵 / 中心牙孔：value_min → input_value，清掉深度 ────────────
foreach ([3=>'鍵', 4=>'中心牙孔'] as $lid=>$lname) {
    $rows = $db->query("SELECT map_id, value_min, value_max, input_value FROM item_label_map
                        WHERE label_id={$lid} AND (value_min IS NOT NULL OR value_max IS NOT NULL)")->fetchAll();
    $say("② {$lname}(label {$lid}) 待轉換 ".count($rows)." 筆（value_min→input_value、清除深度）");
    if (!$DRY) {
        $up = $db->prepare("UPDATE item_label_map SET input_value=?, value_min=NULL, value_max=NULL WHERE map_id=?");
        foreach ($rows as $r) {
            $iv = ($r['input_value'] !== null && $r['input_value'] !== '') ? $r['input_value'] : num($r['value_min']);
            $up->execute([$iv, $r['map_id']]);
        }
    }
}

// ── 3. 內孔(預留) → 內孔 ─────────────────────────────────────────
$src = $db->query("SELECT map_id, d_id, draw_dim FROM item_label_map WHERE label_id=25 ORDER BY map_id")->fetchAll();
// 既有內孔值（用來去重）
$exist = [];
foreach ($db->query("SELECT d_id, input_value FROM item_label_map WHERE label_id=43")->fetchAll() as $e) {
    $exist[$e['d_id']][num($e['input_value'])] = true;
}
$ins = 0; $skip = 0;
$insSt = $DRY ? null : $db->prepare("INSERT INTO item_label_map (d_id, label_id, input_value, created_at) VALUES (?, 43, ?, NOW())");
foreach ($src as $r) {
    $v = num($r['draw_dim']);
    if ($v === null) { $skip++; continue; }
    if (isset($exist[$r['d_id']][$v])) { $skip++; continue; }   // 同料號同數值不重複建立
    $exist[$r['d_id']][$v] = true;
    if (!$DRY) $insSt->execute([$r['d_id'], $v]);
    $ins++;
}
$say("③ 內孔(預留) 共 ".count($src)." 筆 → 建立內孔 {$ins} 筆、去重略過 {$skip} 筆");
if (!$DRY) {
    $db->exec("DELETE FROM item_label_map WHERE label_id=25");
    $db->exec("UPDATE dict_label SET is_active=0 WHERE label_id=25");
}
$say("③ 內孔(預留) 資料已刪除、標籤已停用");

// ── 4. 旗標收斂：一律不可設定深度 ────────────────────────────────
$flagFix = [
    // 子標籤：長×寬 → 數量＋規格（值仍在 value_min，不需搬資料）
    "UPDATE dict_label_sub SET is_dimension=0, is_qty_dim=1, no_depth=1 WHERE sub_id=32",
    // 子標籤：數量+三維 → 數量＋規格（0 筆資料）
    "UPDATE dict_label_sub SET is_qty_triple_dim=0, is_qty_dim=1, no_depth=1 WHERE sub_id=46",
    // 全部子標籤一律不帶深度
    "UPDATE dict_label_sub SET no_depth=1 WHERE no_depth=0",
    // 主標籤：清掉帶深度的形態（皆為停用且 0 筆）
    "UPDATE dict_label SET is_triple_dim=0 WHERE label_id=5",
    "UPDATE dict_label SET is_dimension=0 WHERE label_id=26",
    "UPDATE dict_label SET is_dimension=0, is_qty_dim=0, has_draw_lathe_depth=0, is_triple_dim=0 WHERE is_dimension=1 OR is_qty_dim=1 OR has_draw_lathe_depth=1 OR is_triple_dim=1",
];
foreach ($flagFix as $q) { if (!$DRY) $db->exec($q); }
$say("④ 旗標已收斂（子標籤全部 no_depth=1、主標籤不再有長×寬/三維/圖面車床×深度）");

if ($DRY) { $db->rollBack(); echo "\n（--dry 模式，未寫入）\n"; }
else      { $db->commit();  echo "\n完成。\n"; }

} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "FAILED: ".$e->getMessage()."\n");
    exit(1);
}
