<?php
/**
 * 2026-08-24 (二) 使用者要求：
 *  ① 「圖面+車床」不再記錄車床尺寸 ⇒ 該形態改為單一數值（圖面尺寸搬到 input_value）
 *  ② 熱處理子標籤的「範圍」旗標開回來（硬度範圍值目前存著卻顯示不出來）
 *
 * 重要：滾齒預留(label 17) 是「計算差異」標籤（粗滾 − 齒研，含公差修正），
 *       只是借用 draw_dim/lathe_dim 當儲存欄位，欄位名稱不是圖面/車床，
 *       清掉會毀掉 88 筆預留量 ⇒ 刻意排除，只處理 has_draw_lathe=1 的標籤。
 *
 * 用法： php migrate_drop_lathe_dim.php [--dry]   （可重複執行）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
$DRY = in_array('--dry', $argv, true);
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306","EG-TS2024","excell30367593",
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET NAMES utf8mb4");
function num($v){
    if ($v === null || $v === '') return null;
    $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}
$say = fn($s) => print(($DRY ? '[DRY] ' : '[RUN] ') . $s . PHP_EOL);

$db->beginTransaction();
try {
// ── ① 圖面+車床 → 單一數值 ─────────────────────────────────────
$ids = $db->query("SELECT label_id, label_name FROM dict_label WHERE has_draw_lathe=1 AND is_calc_diff=0")->fetchAll();
if (!$ids) { $say('① 沒有 has_draw_lathe=1 的標籤（可能已執行過）'); }
foreach ($ids as $L) {
    $lid = (int)$L['label_id'];
    $rows = $db->query("SELECT map_id, input_value, draw_dim, lathe_dim FROM item_label_map
                        WHERE label_id={$lid} AND (draw_dim IS NOT NULL OR lathe_dim IS NOT NULL)")->fetchAll();
    $say("① {$L['label_name']}(label {$lid}) 轉換 ".count($rows)." 筆：圖面→數值、清除車床");
    if (!$DRY) {
        $up = $db->prepare("UPDATE item_label_map SET input_value=?, draw_dim=NULL, lathe_dim=NULL WHERE map_id=?");
        foreach ($rows as $r) {
            $iv = ($r['input_value'] !== null && $r['input_value'] !== '') ? $r['input_value'] : num($r['draw_dim']);
            $up->execute([$iv, $r['map_id']]);
        }
        $db->exec("UPDATE dict_label SET has_draw_lathe=0, lathe_optional=0, input_type='number' WHERE label_id={$lid}");
    }
}
if (!$DRY) $db->exec("UPDATE dict_label_sub SET has_draw_lathe=0 WHERE has_draw_lathe=1");

// ── ② 熱處理「範圍」旗標開回來（只開有資料的那幾個）────────────
// 2026-08-24 追加：使用者實測後決定熱處理「不需要輸入範圍，只要能篩選到子標籤就好」，
// 已自行關閉範圍旗標並改回純標籤。這一步保留紀錄但停用，避免重跑時蓋掉使用者的決定。
$hs = [];
$__superseded = $db->query("SELECT s.sub_id, s.sub_name, COUNT(m.sub_map_id) n
                  FROM dict_label_sub s JOIN item_sub_label_map m ON m.sub_id=s.sub_id
                  WHERE s.label_id=13 AND s.is_range=0 AND m.value_min IS NOT NULL
                  GROUP BY s.sub_id")->fetchAll();
foreach ($hs as $r) {
    $say("② 熱處理「{$r['sub_name']}」開回範圍旗標（{$r['n']} 筆硬度範圍會重新顯示）");
    if (!$DRY) $db->exec("UPDATE dict_label_sub SET is_range=1, input_type='number' WHERE sub_id=".(int)$r['sub_id']);
}
if (!$hs) $say('② 熱處理沒有待開啟的範圍子標籤（可能已執行過）');

if ($DRY) { $db->rollBack(); echo "\n（--dry 模式，未寫入）\n"; }
else      { $db->commit();  echo "\n完成。\n"; }
} catch (Throwable $e) { $db->rollBack(); fwrite(STDERR,"FAILED: ".$e->getMessage()."\n"); exit(1); }
