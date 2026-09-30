<?php
/**
 * 2026-09-30_gsop_fix_slots.php
 * 把「跨齒厚({}齒)」那一列的**齒數**補回去。
 *
 * 起因（自己造成的，記下來免得再犯）：
 * `ss_items_replace()` 會用 `ctrl_pat` ＋ `ctrl_slots` **重新組一次** ctrl_point，
 * 所以呼叫端只送組好的 ctrl_point、沒送 ctrl_slots 時，會被重組成空格版
 * ——「跨齒厚(10齒)」變成「跨齒厚(齒)」，而且完全不報錯。
 * 往後凡是要寫入「帶 {} 樣板」的檢驗項目，**一定要連 ctrl_slots／q_slots 一起送**。
 *
 * 齒數的來源＝原始 xlsx（唯一真實來源），這裡照文件對應表補回去。
 *
 * 用法：php 2026-09-30_gsop_fix_slots.php        試算
 *       php 2026-09-30_gsop_fix_slots.php --run  實際寫入
 */
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';

$RUN = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);

/* 來源＝原始 xlsx 解析出來的管理重點（用匯入程式的 --detail 逐份核對過）。
   鍵是 ss_doc.src_tag 的「檔名#工作表」，值是紙本上寫的齒數（沒寫就留空）。 */
$TOOTH = [
    '40301017-01.xlsx#臥式'       => '4',
    'KKYC58207901.xlsx#臥式'      => '10',
    'NM4401-51.xlsx#臥式'         => '2',
    'SP-11210-UE2-0001.xlsx#臥式' => '10',
    'DRW_AA96598_001D.xlsx#臥式'  => '',    // 紙本上就沒寫齒數
];

$rows = $db->query("SELECT i.item_id, i.ver_id, i.ctrl_point, i.ctrl_pat, d.doc_id, d.title, d.src_tag
                    FROM ss_item i
                    JOIN ss_ver v ON v.ver_id = i.ver_id
                    JOIN ss_doc d ON d.doc_id = v.doc_id
                    WHERE d.layout='gsop' AND d.is_deleted=0
                      AND i.ctrl_pat LIKE '%{%' AND i.ctrl_point LIKE '%(齒)%'
                    ORDER BY d.doc_id")->fetchAll(PDO::FETCH_ASSOC);

$plan = [];
foreach ($rows as $r) {
    $tag = (string)$r['src_tag'];
    $key = '';
    foreach (array_keys($TOOTH) as $k) { if (strpos($tag, $k) !== false) { $key = $k; break; } }
    if ($key === '') { echo "略過 doc{$r['doc_id']} {$r['title']}：不是 Excel 匯入的（src_tag={$tag}）\n"; continue; }
    $n = $TOOTH[$key];
    if ($n === '') { echo "略過 doc{$r['doc_id']} {$r['title']}：紙本上本來就沒寫齒數\n"; continue; }
    $newCtrl = ss_slot_compose((string)$r['ctrl_pat'], [$n]);
    $plan[] = ['item_id' => (int)$r['item_id'], 'doc' => (int)$r['doc_id'], 'title' => (string)$r['title'],
               'from' => (string)$r['ctrl_point'], 'to' => $newCtrl, 'slots' => [$n]];
}

echo "\n=== 要補回齒數的列：" . count($plan) . " 筆 ===\n";
foreach ($plan as $p) echo "  doc{$p['doc']} {$p['title']}：{$p['from']} → {$p['to']}\n";
if (!$plan) { echo "（沒有要處理的）\n"; exit; }
if (!$RUN) { echo "\n（試算，加 --run 才寫入）\n"; exit; }

$db->beginTransaction();
try {
    foreach ($plan as $p) {
        $db->prepare("UPDATE ss_item SET ctrl_point=? WHERE item_id=?")->execute([$p['to'], $p['item_id']]);
    }
    $db->commit();
    echo "\n[OK] 已補回 " . count($plan) . " 筆。\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "\n!! 失敗，已全部回復：" . $e->getMessage() . "\n";
    exit(1);
}
