<?php
/**
 * 稽核建議修改：新增「綁定 AS 文件」與「綁定內部條文」兩個欄位，並把原本的自由文字
 * doc_no 改名為 location_note（補充位置說明，如章節/段落，與正式綁定分開）。
 * 使用者 2026-10-05 回報：新增跳窗要能綁定 AS 文件編號與內部條文編號、點擊開啟文件。
 *
 *  - as_doc_id   綁定 as_document.id（選填，存 id 不存文字，改名不失聯）
 *  - clause_id   綁定 ia_as_clause.clause_id（選填，AS9100 標準條文題庫，內部稽核模組已在用的同一份）
 *  - location_note  自由文字補充位置（如「§6.2 第2段」），與上面兩個正式綁定互補、非必填
 *
 * 可重複執行。
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\ADM\migrations\2026-10-05_audit_rec_doc_clause.php
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

// ① doc_no → location_note（改名＋放寬長度；表內目前 0 筆資料，直接改名不必搬資料）
if (colExists($db, 'as_audit_recommend', 'doc_no') && !colExists($db, 'as_audit_recommend', 'location_note')) {
    $db->exec("ALTER TABLE as_audit_recommend CHANGE doc_no location_note VARCHAR(160) DEFAULT NULL COMMENT '補充位置說明（如章節/段落），與下面的正式綁定欄位互補、非必填'");
    echo "已改名：doc_no → location_note\n";
} else {
    echo "略過（location_note 已存在或 doc_no 已不存在）\n";
}

// ② 新增兩個綁定欄位
$adds = [
    ['as_doc_id', "ALTER TABLE as_audit_recommend ADD COLUMN as_doc_id INT DEFAULT NULL COMMENT '綁定 AS 文件(as_document.id)，選填' AFTER location_note"],
    ['clause_id', "ALTER TABLE as_audit_recommend ADD COLUMN clause_id INT DEFAULT NULL COMMENT '綁定內部條文(ia_as_clause.clause_id)，選填' AFTER as_doc_id"],
];
foreach ($adds as [$col, $sql]) {
    if (colExists($db, 'as_audit_recommend', $col)) { echo "略過（已存在）：as_audit_recommend.$col\n"; continue; }
    $db->exec($sql);
    echo "已新增：as_audit_recommend.$col\n";
}

// ③ 索引
$idxes = [
    ['idx_as_doc', "ALTER TABLE as_audit_recommend ADD INDEX idx_as_doc (as_doc_id)"],
    ['idx_clause', "ALTER TABLE as_audit_recommend ADD INDEX idx_clause (clause_id)"],
];
foreach ($idxes as [$idx, $sql]) {
    if (idxExists($db, 'as_audit_recommend', $idx)) { echo "略過（已存在）：as_audit_recommend.$idx\n"; continue; }
    $db->exec($sql);
    echo "已新增索引：as_audit_recommend.$idx\n";
}

echo "完成。\n";
