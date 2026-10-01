<?php
/**
 * 2026-10-01_gsop_df_na.php
 * 既有的標準作業流程 SOP 補兩件使用者 2026-10-01 指定的預設值：
 *   ① 檢驗項目「齒根徑」那一列：品質特性＝無要求、備註＝不磨齒底
 *   ② 軟體步驟「齒型/導程修整」：有參數名稱卻沒填值的格子一律填 NA
 *
 * **只補空白、不覆蓋任何已經填好的值**——現場真的量了齒根徑、或備註寫了別的字，
 * 那是比預設值更正確的資料，不可以被這支蓋掉。
 * 已核准／已送簽的版次一律跳過（那是已經蓋過章的紙本內容，不可事後更動）。
 *
 * 用法：php 2026-10-01_gsop_df_na.php        試算
 *       php 2026-10-01_gsop_df_na.php --run  實際寫入
 */
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';

$RUN = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);

/* ── ① 檢驗項目：齒根徑 ── */
$items = $db->query("SELECT i.item_id, i.q_char, i.note, d.doc_id, d.title, v.status
                       FROM ss_item i
                       JOIN ss_ver v ON v.ver_id = i.ver_id
                       JOIN ss_doc d ON d.doc_id = v.doc_id
                      WHERE d.layout='gsop' AND d.is_deleted=0
                        AND i.ctrl_point LIKE '%齒根徑%'
                      ORDER BY d.doc_id")->fetchAll(PDO::FETCH_ASSOC);
$itemPlan = [];
foreach ($items as $r) {
    if ((string)$r['status'] !== 'draft') continue;          // 已送簽／已核准的不動
    $q = trim((string)$r['q_char']); $n = trim((string)$r['note']);
    $setQ = ($q === '') ? '無要求'   : null;
    $setN = ($n === '') ? '不磨齒底' : null;
    if ($setQ === null && $setN === null) continue;
    $itemPlan[] = ['id' => (int)$r['item_id'], 'doc' => (int)$r['doc_id'], 'title' => (string)$r['title'],
                   'q' => $setQ, 'n' => $setN];
}
echo "=== 檢驗項目「齒根徑」要補預設值：" . count($itemPlan) . " 列 ===\n";
foreach ($itemPlan as $p) {
    echo "  doc{$p['doc']} {$p['title']}　"
       . ($p['q'] !== null ? '品質特性→無要求 ' : '') . ($p['n'] !== null ? '備註→不磨齒底' : '') . "\n";
}

/* ── ② 軟體步驟：齒型/導程修整 ── */
$steps = $db->query("SELECT s.step_id, s.step_name, s.kv_json, d.doc_id, d.title, v.status, d.machine_model
                       FROM ss_step s
                       JOIN ss_ver v ON v.ver_id = s.ver_id
                       JOIN ss_doc d ON d.doc_id = v.doc_id
                      WHERE d.layout='gsop' AND d.is_deleted=0 AND s.sect='soft'
                      ORDER BY d.doc_id")->fetchAll(PDO::FETCH_ASSOC);
$stepPlan = [];
foreach ($steps as $r) {
    if ((string)$r['status'] !== 'draft') continue;
    if (!ss_is_na_step((string)$r['step_name'])) continue;
    $kv  = ss_kv_decode($r['kv_json'] ?? '');
    if (!$kv) continue;
    /* 只補「機種範本帶進來的參數」——有文件把匯入 Excel 時的註記（±一個模數）存成了
       參數名稱，那種格子補 NA 會印成「±一個模數 NA」，意思整個跑掉。 */
    $naKeys = ss_na_keys($db, array_filter(array_map('trim', explode(',', (string)$r['machine_model']))));
    if (!$naKeys) continue;
    $new = ss_kv_fill_na($kv, $naKeys);
    if (json_encode($new, JSON_UNESCAPED_UNICODE) === json_encode($kv, JSON_UNESCAPED_UNICODE)) continue;
    $names = [];
    foreach ($new as $ri => $row) foreach ($row as $ci => $p) {
        $o = $kv[$ri][$ci] ?? [];
        if (trim((string)($o['v'] ?? '')) === '' && (string)($p['v'] ?? '') === 'NA') $names[] = (string)$p['k'];
    }
    $stepPlan[] = ['id' => (int)$r['step_id'], 'doc' => (int)$r['doc_id'], 'title' => (string)$r['title'],
                   'kv' => $new, 'names' => $names];
}
echo "\n=== 軟體步驟「齒型/導程修整」要補 NA：" . count($stepPlan) . " 個步驟 ===\n";
foreach ($stepPlan as $p) echo "  doc{$p['doc']} {$p['title']}　" . implode('、', $p['names']) . " → NA\n";

if (!$itemPlan && !$stepPlan) { echo "\n（沒有要補的項目）\n"; exit; }
if (!$RUN) { echo "\n（試算，加 --run 才寫入）\n"; exit; }

$db->beginTransaction();
try {
    foreach ($itemPlan as $p) {
        $set = []; $arg = [];
        if ($p['q'] !== null) { $set[] = 'q_char=?'; $arg[] = $p['q']; }
        if ($p['n'] !== null) { $set[] = 'note=?';   $arg[] = $p['n']; }
        $arg[] = $p['id'];
        $db->prepare("UPDATE ss_item SET " . implode(',', $set) . " WHERE item_id=?")->execute($arg);
    }
    foreach ($stepPlan as $p) {
        $db->prepare("UPDATE ss_step SET kv_json=? WHERE step_id=?")
           ->execute([json_encode($p['kv'], JSON_UNESCAPED_UNICODE), $p['id']]);
    }
    $db->commit();
    echo "\n[OK] 檢驗項目 " . count($itemPlan) . " 列、軟體步驟 " . count($stepPlan) . " 個已補上預設值。\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "\n!! 失敗，已全部回復：" . $e->getMessage() . "\n";
    exit(1);
}
