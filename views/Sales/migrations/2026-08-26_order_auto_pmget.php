<?php
// =============================================================================
// 一次性 migration：訂單追蹤「指定特定設計(技術)自動轉生管」
//   order_track.pmGet_auto TINYINT(1)：這筆轉生管日是不是系統依設定自動蓋上的
//     1 = 系統自動蓋的（改掉指派設計時會自動退回、清空）
//     0 = 人工按「轉生管」鈕蓋的（任何自動規則都不會動它）
//   設定值本身放 system_parameters(DESIGNER_SETTING/auto_pmget_ates)，不需要建表。
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\Sales\migrations\2026-08-26_order_auto_pmget.php
// 可重複執行。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$has = $pdo->query("SHOW COLUMNS FROM order_track LIKE 'pmGet_auto'")->fetch();
if ($has) {
    echo "order_track.pmGet_auto 已存在，略過\n";
} else {
    $pdo->exec("ALTER TABLE order_track
                ADD COLUMN pmGet_auto TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '轉生管日是否為系統依「指定特定設計自動轉生管」設定自動蓋上（1=自動、0=人工按鈕）'
                AFTER pmGet");
    echo "order_track.pmGet_auto 已新增\n";
}
echo "done\n";
