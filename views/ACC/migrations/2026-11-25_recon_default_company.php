<?php
// 一次性：尚未勾選「是否需要對帳單」的客戶，全部改為需要＋本公司提供（使用者交辦 2026-11-25）。
// 已勾選者（含提供方式＝客戶提供）完全不動。預設只試算，加 --run 才寫入。可重複執行。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'C:/MAMP/htdocs';
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/cust_option_lib.php';
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
eg_cust_opt_ensure($pdo);
$n = (int)$pdo->query("SELECT COUNT(*) FROM customer_list WHERE need_recon_stmt IS NULL OR need_recon_stmt=0")->fetchColumn();
echo "待更新客戶：{$n} 筆\n";
if (in_array('--run', $argv ?? [], true)) {
    $c = $pdo->exec("UPDATE customer_list SET need_recon_stmt=1, recon_provide_by='company' WHERE need_recon_stmt IS NULL OR need_recon_stmt=0");
    echo "已更新：{$c} 筆\n";
}
