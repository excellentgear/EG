<?php
// 匯入會計科目（111.xlsx）與科目分類（222.xlsx）。資料在同目錄 acc_account_seed.json。可重複執行。
// 已存在的科目（例如傳票預設用的 6 個）名稱不動，只補類別／摘要／所屬科目／備註。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'C:/MAMP/htdocs';
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/acc_voucher_lib.php';
$seed = json_decode(file_get_contents(__DIR__ . '/acc_account_seed.json'), true);
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
acc_voucher_ensure($pdo);
$pdo->beginTransaction();
$cs = $pdo->prepare("INSERT INTO acc_account_category (code,name,note,sort_order) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),note=VALUES(note),sort_order=VALUES(sort_order)");
foreach ($seed['categories'] as $i => $c) $cs->execute([$c['code'], $c['name'], $c['note'] ?: null, $i + 1]);
$as = $pdo->prepare("INSERT INTO acc_account (code,name,active,category,summary,parent_code,note) VALUES (?,?,1,?,?,?,?)
  ON DUPLICATE KEY UPDATE category=VALUES(category),summary=VALUES(summary),parent_code=VALUES(parent_code),note=VALUES(note)");
foreach ($seed['accounts'] as $a)
    $as->execute([$a['code'], $a['name'], $a['category'] ?: null, $a['summary'] ?: null, $a['parent'] ?: null, $a['note'] ?: null]);
// 不在 111 裡的既有科目（傳票預設科目）依前兩碼補類別
$pdo->exec("UPDATE acc_account a JOIN acc_account_category c ON c.code=LEFT(a.code,2) SET a.category=c.code WHERE a.category IS NULL");
$pdo->commit();
echo count($seed['categories']) . " 個分類、" . count($seed['accounts']) . " 個科目已匯入\n";
