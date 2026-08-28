<?php
// 2026-08-28_quote_kw_rule.php — 報價單快速轉移頁「關鍵字自動偵測製程」用的結構補齊工具
//   1) quotation_kw_rule            關鍵字規則表（新建）
//   2) quotation_item.note_only     這一筆以備註代替製程（新欄）
// 可重複執行；預設試算，加 --run 才真的改。
//   & C:\MAMP\bin\php\php8.3.1\php.exe views/Sales/migrations/2026-08-28_quote_kw_rule.php --run
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/quote_kw_rule_lib.php';

$run = in_array('--run', $argv, true);
$pdo = (new DBConnection())->getPDO();

$has = function (string $tbl, string $col) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$tbl, $col]);
    return (int)$st->fetchColumn() > 0;
};
$tblExists = function (string $tbl) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$tbl]);
    return (int)$st->fetchColumn() > 0;
};

echo 'quotation_kw_rule 表：' . ($tblExists('quotation_kw_rule') ? '已存在' : '不存在（要建立）') . PHP_EOL;
echo 'quotation_item.note_only 欄：' . ($has('quotation_item', 'note_only') ? '已存在' : '不存在（要新增）') . PHP_EOL;

if (!$run) { echo PHP_EOL . '（試算模式，未改動任何東西；加 --run 才會執行）' . PHP_EOL; exit; }

qkw_ensure_schema($pdo);

echo PHP_EOL . '執行完成：' . PHP_EOL;
echo '  quotation_kw_rule 表：' . ($tblExists('quotation_kw_rule') ? 'OK' : '失敗') . PHP_EOL;
echo '  quotation_item.note_only 欄：' . ($has('quotation_item', 'note_only') ? 'OK' : '失敗') . PHP_EOL;
echo '  目前規則筆數：' . (int)$pdo->query("SELECT COUNT(*) FROM quotation_kw_rule")->fetchColumn() . PHP_EOL;
