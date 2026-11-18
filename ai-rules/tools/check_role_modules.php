<?php
/**
 * 角色模組登記健檢 —— 新增任何「roles.module」（新模組角色）收尾必跑
 * 用法：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_role_modules.php
 * 通過條件：「未登記」為（無）。
 *
 * 漏登記在 src/common/role_modules_lib.php 的症狀：
 *   ① user_permissions.php「人員為主」畫面直接顯示 inquiry 這種英文代碼、歸在「其他」
 *   ② 指派角色後不會自動補頁面 R，側欄沒有該頁選項
 */
require __DIR__ . '/../../src/common/role_modules_lib.php';
require __DIR__ . '/../../src/common/role_page_r_lib.php';
require __DIR__ . '/../../src/common/DBConnection.php';
$pdo = (new DBConnection())->getPDO();
$reg = eg_role_modules();
$mods = $pdo->query("SELECT DISTINCT module FROM roles WHERE is_system=0 AND module IS NOT NULL AND module<>''")->fetchAll(PDO::FETCH_COLUMN);
$miss = array_values(array_filter($mods, function ($m) use ($reg) { return !isset($reg[$m]); }));
echo "未登記：" . ($miss ? implode('、', $miss) : '（無）') . "\n";
$auto = eg_role_page_r_all();
$no = [];
foreach ($reg as $m => $d) if (($d['page'] ?? '') !== '' && !isset($auto[$m])) $no[] = $m;
echo "有頁面但不會自動補頁面R（頁面自己讀 R/C/U/D 字母，需在人員權限手動勾）：" . implode('、', $no) . "\n";
