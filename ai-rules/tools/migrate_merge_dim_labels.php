<?php
/**
 * 2026-08-24 使用者要求（建議 C）：尺寸類標籤收斂
 *   ① 厚度(6) → 片狀›總厚(sub 19)：實測 10 筆的數值與總厚「完全相同」＝純重複標籤
 *      數值相同才刪；萬一有不同的，改成寫進總厚（不覆蓋已有值），絕不默默丟資料。
 *   ② 外徑(>齒部外徑)(12) → 最大外徑(21)：兩者都是「最大的那個外徑」，2 筆且無衝突
 *
 * 用法： php migrate_merge_dim_labels.php [--dry]   （可重複執行）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
$DRY = in_array('--dry', $argv, true);
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306","EG-TS2024","excell30367593",
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET NAMES utf8mb4");
$say = fn($s) => print(($DRY ? '[DRY] ' : '[RUN] ') . $s . PHP_EOL);
$n = fn($v) => ($v===null||$v==='') ? null : rtrim(rtrim(number_format((float)$v,4,'.',''),'0'),'.');

$db->beginTransaction();
try {
// ── ① 厚度 → 片狀›總厚 ──────────────────────────────────────
$same=0; $moved=0; $kept=0;
foreach ($db->query("SELECT map_id, d_id, input_value FROM item_label_map WHERE label_id=6")->fetchAll() as $r) {
    $did=(int)$r['d_id'];
    $t = $db->query("SELECT s.sub_map_id, s.input_value FROM item_label_map m
                     JOIN item_sub_label_map s ON s.parent_map_id=m.map_id AND s.sub_id=19
                     WHERE m.d_id={$did} AND m.label_id=2 LIMIT 1")->fetch();
    if ($t && $n($t['input_value']) === $n($r['input_value'])) {          // 數值一樣＝純重複，刪掉厚度那筆
        $same++;
        if (!$DRY) $db->prepare("DELETE FROM item_label_map WHERE map_id=?")->execute([$r['map_id']]);
    } elseif ($t && ($t['input_value']===null || $t['input_value']==='')) { // 總厚是空的 → 把厚度寫進去
        $moved++;
        if (!$DRY) {
            $db->prepare("UPDATE item_sub_label_map SET input_value=? WHERE sub_map_id=?")->execute([$n($r['input_value']), $t['sub_map_id']]);
            $db->prepare("DELETE FROM item_label_map WHERE map_id=?")->execute([$r['map_id']]);
        }
    } else {                                                              // 兩邊都有值且不同 → 保留不動，人工判斷
        $kept++;
        $say("   ⚠ 料號 d_id={$did} 厚度={$r['input_value']} 與 總厚={$t['input_value']} 不一致，保留不動");
    }
}
$say("① 厚度：{$same} 筆與總厚相同直接刪除、{$moved} 筆寫入總厚、{$kept} 筆不一致保留");

// ── ② 外徑(>齒部外徑) → 最大外徑 ────────────────────────────
$mv=0; $dup=0;
foreach ($db->query("SELECT map_id, d_id, input_value FROM item_label_map WHERE label_id=12")->fetchAll() as $r) {
    $did=(int)$r['d_id'];
    $has = $db->query("SELECT map_id, input_value FROM item_label_map WHERE d_id={$did} AND label_id=21 LIMIT 1")->fetch();
    if ($has) { $dup++; if(!$DRY) $db->prepare("DELETE FROM item_label_map WHERE map_id=?")->execute([$r['map_id']]); }
    else      { $mv++;  if(!$DRY) $db->prepare("UPDATE item_label_map SET label_id=21 WHERE map_id=?")->execute([$r['map_id']]); }
}
$say("② 外徑(>齒部外徑)：{$mv} 筆改掛最大外徑、{$dup} 筆因已有最大外徑而移除");

// ── ③ 停用被併掉的標籤（沒有殘留資料才停用）────────────────
foreach ([6=>'厚度', 12=>'外徑(>齒部外徑)'] as $lid=>$nm) {
    $left = (int)$db->query("SELECT COUNT(*) c FROM item_label_map WHERE label_id={$lid}")->fetch()['c'];
    if ($left === 0) { $say("③ 停用「{$nm}」"); if(!$DRY) $db->exec("UPDATE dict_label SET is_active=0 WHERE label_id={$lid}"); }
    else             { $say("③ 「{$nm}」尚有 {$left} 筆未處理，暫不停用"); }
}

if ($DRY) { $db->rollBack(); echo "\n（--dry 模式，未寫入）\n"; }
else      { $db->commit();  echo "\n完成。\n"; }
} catch (Throwable $e) { $db->rollBack(); fwrite(STDERR,"FAILED: ".$e->getMessage()."\n"); exit(1); }
