<?php
/**
 * 出貨單「已綁定確認」記號：建立欄位／差異表，並把目前已有任何綁定的出貨單全部標上記號
 * （之後 ERP 出貨單匯入就不會刪除或覆蓋它們，見 src/common/is_bind_lock_lib.php）。
 * 用法：php 本檔 [--run]   （預設只試算；可重複執行，已有記號的不重複標）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/is_bind_lock_lib.php';
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$run = in_array('--run', $argv, true);
isbl_ensure_schema($pdo);
$ids = $pdo->query("SELECT IS_id FROM is_list WHERE Order_id IS NOT NULL
    UNION SELECT IS_id FROM shipment_order_map UNION SELECT IS_id FROM is_order_map UNION SELECT IS_id FROM is_bom_map
    UNION SELECT IS_id FROM return_order_map WHERE IS_id IS NOT NULL UNION SELECT IS_id FROM ir_reship_map
    UNION SELECT is_id FROM project_shipment UNION SELECT src_id FROM acc_recon_line WHERE src_type='IS'
    UNION SELECT src_id FROM acc_invoice_item WHERE src_type='IS'")->fetchAll(PDO::FETCH_COLUMN);
$ids = array_values(array_filter(array_map('intval', $ids)));
// 只算 is_list 裡還存在的（孤兒參照不標）
$exist = $ids ? $pdo->query("SELECT IS_id FROM is_list WHERE IS_id IN (" . implode(',', $ids) . ") AND bind_locked=0")->fetchAll(PDO::FETCH_COLUMN) : [];
echo ($run ? '執行' : '試算') . '：有綁定的出貨單 ' . count($ids) . ' 筆，尚未標記 ' . count($exist) . " 筆\n";
if ($run && $exist) echo '已標記 ' . isbl_mark($pdo, $exist, 0) . " 筆\n";
