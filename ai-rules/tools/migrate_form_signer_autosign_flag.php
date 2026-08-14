<?php
// 表單簽核設計器：fsd_case_response 新增 is_auto 旗標（使用者2026-08-14明確要求：
// 「系統自動簽核」字樣只開放管理員看到，唯讀/一般使用者絕對不可看到；不可用字串比對reply_text猜測，
// 要有明確欄位讓API層依權限決定是否隱藏該筆回覆文字/自動簽核註記）。可重複執行。
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
$done = [];
if (!colExists($db, 'fsd_case_response', 'is_auto')) {
    $db->exec("ALTER TABLE fsd_case_response ADD COLUMN is_auto TINYINT(1) NOT NULL DEFAULT 0
               COMMENT '系統自動簽核(ai-rules/21)；此筆的「自動簽核」字樣只有管理員可見,一般/唯讀使用者不可見' AFTER decision");
    $done[] = 'fsd_case_response.is_auto';
    // 既有資料回填：reply_text 含「系統自動簽核」字樣的視為自動簽核（僅一次性回填舊資料，之後一律用欄位判斷不再猜文字）
    $db->exec("UPDATE fsd_case_response SET is_auto=1 WHERE reply_text LIKE '%系統自動簽核%'");
}
echo $done ? ("已異動：\n  " . implode("\n  ", $done) . "\n") : "無異動（皆已存在）\n";
