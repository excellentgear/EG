<?php
/**
 * 2026-09-22_ss_step_renumber.php
 * 把 ss_step.step_text 既有的項次編號統一成系統格式「1.內容」（使用者 2026-09-22 指定：
 * 原本已經打好的也要格式相同，而且編號一律由系統代入）。
 *
 * 規則完全走 ss_renumber_lines()（唯一實作），所以與存檔時編出來的一模一樣。
 * 預設只試算不寫入；要真的寫請加 --run。可重複執行（第二次會是 0 列要改）。
 *
 *   php views/QA/migrations/2026-09-22_ss_step_renumber.php
 *   php views/QA/migrations/2026-09-22_ss_step_renumber.php --run
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/common/_config.php';
require_once $root . '/src/common/DBConnection.php';
require_once $root . '/src/common/sopsip_lib.php';

$run = in_array('--run', $argv ?? [], true);
$db  = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $db->query("SELECT s.step_id, s.ver_id, s.seq, s.step_text, d.title
                    FROM ss_step s JOIN ss_ver v ON v.ver_id = s.ver_id
                    JOIN ss_doc d ON d.doc_id = v.doc_id
                    WHERE s.step_text IS NOT NULL AND s.step_text <> ''
                    ORDER BY s.ver_id, s.seq")->fetchAll(PDO::FETCH_ASSOC);

$chg = [];
foreach ($rows as $r) {
    $new = ss_renumber_lines((string)$r['step_text']);
    if ($new !== (string)$r['step_text']) $chg[] = $r + ['new' => $new];
}

echo '掃描 ' . count($rows) . " 列步驟，要重編的 " . count($chg) . " 列\n\n";
foreach ($chg as $r) {
    echo "ver {$r['ver_id']} 第 {$r['seq']} 項　{$r['title']}\n";
    echo '  舊：' . str_replace("\n", ' ⏎ ', (string)$r['step_text']) . "\n";
    echo '  新：' . str_replace("\n", ' ⏎ ', (string)$r['new']) . "\n";
}
if (!$chg) { echo "沒有要改的。\n"; exit; }

if (!$run) { echo "\n（試算，未寫入。要真的寫請加 --run）\n"; exit; }

$db->beginTransaction();
try {
    $st = $db->prepare("UPDATE ss_step SET step_text=? WHERE step_id=?");
    foreach ($chg as $r) $st->execute([$r['new'], (int)$r['step_id']]);
    $db->commit();
    echo "\n已寫入 " . count($chg) . " 列。\n";
} catch (Throwable $e) { $db->rollBack(); echo "\n失敗，已全部回滾：" . $e->getMessage() . "\n"; }
