<?php
/**
 * 一次性：訂單的「下料區域(drop_zone)／容器(Containers)／業務備註(Order_ps)」內容只有 X（大小寫皆同、前後空白不計）
 * 的一律改成空值（NULL＝系統存檔時空白欄位本來就存 NULL），避免畫面上一堆沒有意義的 X（2026-11-11 使用者要求）。
 *
 * - 只動「整格就是一個 X」的欄位，含其他文字者不動
 * - 不動 Modified_At／Modified_By（訂單變更比對的基準），last_activity_at 明確保留原值（該欄 ON UPDATE 會自動跳）
 * - 改之前把原值備份到 order_track_x_cleanup_bak（可依此還原）
 *
 * 用法：php 2026-11-11_order_track_clear_x.php          試算
 *       php 2026-11-11_order_track_clear_x.php --run    執行
 */
require_once __DIR__ . '/../../../src/common/DBConnection.php';   // 帳密不寫在腳本裡
$conn = new DBConnection();
$db = $conn->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$run = in_array('--run', $argv, true);
$isX = "UPPER(TRIM(%s)) = 'X'";
$where = sprintf("$isX OR $isX OR $isX", 'drop_zone', 'Containers', 'Order_ps');
$rows = $db->query("SELECT Order_id, drop_zone, Containers, Order_ps FROM order_track WHERE $where")->fetchAll(PDO::FETCH_ASSOC);
$cnt = ['drop_zone' => 0, 'Containers' => 0, 'Order_ps' => 0];
foreach ($rows as $r) foreach ($cnt as $k => $_) if (strtoupper(trim((string)$r[$k])) === 'X') $cnt[$k]++;
echo '符合的訂單：', count($rows), ' 張；下料區域 ', $cnt['drop_zone'], '、容器 ', $cnt['Containers'], '、業務備註 ', $cnt['Order_ps'], "\n";
if (!$run) { echo "（試算，未寫入；加 --run 執行）\n"; exit; }

$db->exec("CREATE TABLE IF NOT EXISTS order_track_x_cleanup_bak (
    Order_id INT NOT NULL PRIMARY KEY, drop_zone VARCHAR(30) NULL, Containers VARCHAR(30) NULL, Order_ps VARCHAR(150) NULL,
    backed_up_at DATETIME DEFAULT CURRENT_TIMESTAMP) COMMENT='2026-11-11 清除訂單X值前的原值備份'");
$db->beginTransaction();
$bk = $db->prepare("INSERT IGNORE INTO order_track_x_cleanup_bak (Order_id, drop_zone, Containers, Order_ps) VALUES (?,?,?,?)");
foreach ($rows as $r) $bk->execute([$r['Order_id'], $r['drop_zone'], $r['Containers'], $r['Order_ps']]);
$n = 0;
foreach (['drop_zone', 'Containers', 'Order_ps'] as $c) {
    $n += $db->exec("UPDATE order_track SET $c = NULL, last_activity_at = last_activity_at WHERE UPPER(TRIM($c)) = 'X'");
}
$db->commit();
echo "已清除 $n 個欄位；原值備份在 order_track_x_cleanup_bak\n";
