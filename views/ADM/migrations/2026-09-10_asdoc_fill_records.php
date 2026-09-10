<?php
/**
 * AS 文件填寫紀錄連動 × 表單簽核「綁定但不列印編號」（可重複執行）
 * 2026-09-10 使用者交辦。
 *
 *  - fsd_template.as_doc_hide_print  樣板：綁定照樣成立（填寫紀錄會連動），但列印右下角不印 AS 編號
 *  - fsd_case.as_doc_hide_print      補案件：同上，逐案各自決定（補案件的 AS 編號本來就是逐案挑的）
 *
 * 為什麼需要：紙本掃描檔上本來就已經印好表單編號了，系統再印一次會變成同一頁出現兩組編號；
 * 但綁定不能拿掉——拿掉的話 AS 文件管理的「填寫紀錄」就找不到這些已簽核完成的文件。
 *
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\ADM\migrations\2026-09-10_asdoc_fill_records.php
 */
$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

$db = (new DBConnection())->getPDO();

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}
function idxExists(PDO $db, string $table, string $idx): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $st->execute([$table, $idx]);
    return (int)$st->fetchColumn() > 0;
}

$adds = [
    ['fsd_template', 'as_doc_hide_print',
     "ALTER TABLE fsd_template ADD COLUMN as_doc_hide_print TINYINT(1) NOT NULL DEFAULT 0 COMMENT '綁定照舊成立(填寫紀錄連動)，但列印右下角不印AS編號'"],
    ['fsd_case', 'as_doc_hide_print',
     "ALTER TABLE fsd_case ADD COLUMN as_doc_hide_print TINYINT(1) NOT NULL DEFAULT 0 COMMENT '補案件：綁定照舊成立(填寫紀錄連動)，但列印右下角不印AS編號'"],
];
foreach ($adds as [$table, $col, $sql]) {
    if (colExists($db, $table, $col)) { echo "略過（已存在）：$table.$col\n"; continue; }
    $db->exec($sql);
    echo "已新增：$table.$col\n";
}

// 填寫紀錄的反查用索引（由 AS 文件往回找案件／表單）
$idxes = [
    ['fsd_case',    'idx_fsd_case_asdoc',   "ALTER TABLE fsd_case ADD INDEX idx_fsd_case_asdoc (as_doc_id)"],
    ['fsd_case',    'idx_fsd_case_tpl_st',  "ALTER TABLE fsd_case ADD INDEX idx_fsd_case_tpl_st (template_id, status)"],
    ['rf_instance', 'idx_rf_inst_tpl_st',   "ALTER TABLE rf_instance ADD INDEX idx_rf_inst_tpl_st (template_id, status)"],
];
foreach ($idxes as [$table, $idx, $sql]) {
    if (idxExists($db, $table, $idx)) { echo "略過（已存在）：$table.$idx\n"; continue; }
    $db->exec($sql);
    echo "已新增索引：$table.$idx\n";
}

echo "完成。\n";
