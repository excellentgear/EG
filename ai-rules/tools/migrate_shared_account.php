<?php
// 共用帳號綁定/通知轉送：schema 遷移（可重跑，先查 information_schema 防重）
// 用法：php migrate_shared_account.php
// 自建連線（不 include _config.php，避免觸發 telegram/personal_task 順路輪詢）
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function tblExists(PDO $db, string $table): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

$done = [];

if (!colExists($db, 'user', 'is_shared_account')) {
    $db->exec("ALTER TABLE `user` ADD COLUMN `is_shared_account` TINYINT NOT NULL DEFAULT 0 COMMENT '1=共用帳號(現場多人共用登入)'");
    $done[] = 'user.is_shared_account';
}
if (!colExists($db, 'user', 'lock_password')) {
    $db->exec("ALTER TABLE `user` ADD COLUMN `lock_password` TINYINT NOT NULL DEFAULT 0 COMMENT '1=禁止改密碼(共用帳號防呆)'");
    $done[] = 'user.lock_password';
}

if (!tblExists($db, 'shared_account_member')) {
    $db->exec("CREATE TABLE `shared_account_member` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `shared_uid` INT NOT NULL COMMENT '共用帳號 user.id',
        `member_uid` INT NOT NULL COMMENT '員工 user.id',
        `mode` VARCHAR(10) NOT NULL DEFAULT 'attach' COMMENT 'attach=綁定依附(只送共用) / notify=開通(雙送)',
        `active` TINYINT NOT NULL DEFAULT 1,
        `created_by` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_pair` (`shared_uid`, `member_uid`),
        INDEX `idx_member` (`member_uid`),
        INDEX `idx_shared` (`shared_uid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='共用帳號成員綁定'");
    $done[] = 'table shared_account_member';
}

// 共用帳號代簽留痕：記錄「這筆已閱/回簽是在哪個共用帳號上、由本人輸密碼完成的」
if (!colExists($db, 'live_event_response', 'signed_via')) {
    $db->exec("ALTER TABLE `live_event_response` ADD COLUMN `signed_via` INT NULL COMMENT '經由哪個共用帳號代為操作(NULL=本人帳號直接操作)'");
    $done[] = 'live_event_response.signed_via';
}
if (!colExists($db, 'live_event_for_user', 'signed_via')) {
    $db->exec("ALTER TABLE `live_event_for_user` ADD COLUMN `signed_via` INT NULL COMMENT '經由哪個共用帳號代為標記已閱(NULL=本人)'");
    $done[] = 'live_event_for_user.signed_via';
}

echo $done ? ("已完成：\n - " . implode("\n - ", $done) . "\n") : "無需變更（皆已存在）\n";
