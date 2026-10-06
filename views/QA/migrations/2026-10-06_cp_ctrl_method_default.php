<?php
// =============================================================================
// views/QA/migrations/2026-10-06_cp_ctrl_method_default.php
// 新增 cp_ctrl_method_default：管制方法快選庫（管理員維護「特性名稱關鍵字 → 預設管制
// 方法」，自動帶入時依特性名稱比對套用，不是依製程套用——跟 2026-10-06 修掉的那個
// bug（外觀被套到齒輪咬合的管制方法）刻意走不同的比對維度，這次比對的是特性"自己"
// 的名稱，不會錯套到不相干的特性上）。
//
// 用法：
//   試算（不寫入）：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QA\migrations\2026-10-06_cp_ctrl_method_default.php
//   實際執行：      & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QA\migrations\2026-10-06_cp_ctrl_method_default.php --run
// 冪等：CREATE TABLE IF NOT EXISTS，可重複執行。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$doRun = in_array('--run', $argv, true);

$sql = "CREATE TABLE IF NOT EXISTS cp_ctrl_method_default (
  id INT AUTO_INCREMENT PRIMARY KEY,
  match_text VARCHAR(100) NOT NULL COMMENT '比對特性名稱(ss_item.ctrl_point)用的關鍵字，CONTAINS比對',
  control_method TEXT NOT NULL COMMENT '預設管制方法內容',
  sort_order INT DEFAULT 0,
  is_active TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管制計畫：管制方法快選庫(特性名稱關鍵字->預設管制方法)'";

echo $doRun ? "=== 執行模式 ===\n" : "=== 試算模式（加 --run 才真的建立）===\n";
$exists = $pdo->query("SHOW TABLES LIKE 'cp_ctrl_method_default'")->fetch();
if ($exists) {
    echo "[已存在] cp_ctrl_method_default\n";
} elseif ($doRun) {
    $pdo->exec($sql);
    echo "[已建立] cp_ctrl_method_default\n";
} else {
    echo "[將建立] cp_ctrl_method_default\n";
}
