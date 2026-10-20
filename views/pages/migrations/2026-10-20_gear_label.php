<?php
/** 2026-10-20 d_setting_gear 新增 gear_label（齒型名稱 A/B/C…，選填、同一料號不可重複）。可重複執行。 */
require_once __DIR__ . '/../../../src/common/DBConnection.php';
$pdo = (new DBConnection())->getPDO();
if ($pdo->query("SHOW COLUMNS FROM d_setting_gear LIKE 'gear_label'")->fetch()) { echo "已存在\n"; exit; }
$pdo->exec("ALTER TABLE d_setting_gear ADD COLUMN gear_label VARCHAR(10) NULL COMMENT '齒型名稱 A/B/C（選填，同料號不重複）'");
echo "已新增 gear_label\n";
