<?php
// 一次性：廠商既有的工廠地址／帳單地址補進「其他地址」同名類別（沒有才補，可重複執行）。預設只試算，--run 才寫入。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'C:/MAMP/htdocs';
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/party_name_lib.php';
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$f = (int)$pdo->query("SELECT COUNT(*) FROM maker_list WHERE factory_address IS NOT NULL AND TRIM(factory_address)<>''")->fetchColumn();
$b = (int)$pdo->query("SELECT COUNT(*) FROM maker_list WHERE billing_address IS NOT NULL AND TRIM(billing_address)<>''")->fetchColumn();
echo "有工廠地址的廠商：{$f}　有帳單地址的廠商：{$b}\n";
if (in_array('--run', $argv ?? [], true)) echo '已補進其他地址：' . eg_maker_addr_migrate($pdo) . " 筆\n";
