<?php
/**
 * 表單簽核設計器 × AS文件管理：案件連結 AS 文件編號（可重複執行）
 *
 * 用途：同一份文件不必上傳兩次。案件在建立時可連結一個 AS 文件編號，之後在 AS 文件管理
 *       上傳「相同編號」的版本附件時，可以直接選「由表單簽核案件導入」拿那份已簽核完成的 PDF。
 *
 *  - fsd_template.allow_case_as_link  樣板開關：用這個樣板建立案件時，才會出現「連結 AS 文件編號」
 *  - fsd_case.link_as_doc_id          案件連結的 AS 文件（**不是**列印用的 AS 編號綁定，兩者無關）
 *  - as_document_version.src_fsd_case_id  這個版本的檔案是從哪一件表單簽核案件導入的
 *      → 有被導入的案件不可刪除，要先到 AS 文件管理刪掉那個版本
 *
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\migrate_form_signer_asdoc_link.php
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

$adds = [
    ['fsd_template', 'allow_case_as_link',
     "ALTER TABLE fsd_template ADD COLUMN allow_case_as_link TINYINT(1) NOT NULL DEFAULT 0 COMMENT '建立案件時可選擇連結AS文件編號(供AS文件管理導入同一份檔案)'"],
    ['fsd_case', 'link_as_doc_id',
     "ALTER TABLE fsd_case ADD COLUMN link_as_doc_id INT NULL COMMENT '案件連結的AS文件(供AS文件管理導入用；與列印綁定的AS編號無關)'"],
    ['as_document_version', 'src_fsd_case_id',
     "ALTER TABLE as_document_version ADD COLUMN src_fsd_case_id INT NULL COMMENT '此版本檔案由表單簽核案件導入的來源案件id(有值者該案件不可刪除)'"],
];
foreach ($adds as [$table, $col, $sql]) {
    if (colExists($db, $table, $col)) { echo "略過（已存在）：$table.$col\n"; continue; }
    $db->exec($sql);
    echo "已新增：$table.$col\n";
}

// 查詢用索引（刪除案件時要反查有沒有被 AS 文件採用）
$idx = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='as_document_version' AND INDEX_NAME='idx_src_fsd_case'");
$idx->execute();
if (!(int)$idx->fetchColumn()) {
    $db->exec("ALTER TABLE as_document_version ADD INDEX idx_src_fsd_case (src_fsd_case_id)");
    echo "已新增索引：as_document_version.idx_src_fsd_case\n";
} else {
    echo "略過（已存在）：as_document_version.idx_src_fsd_case\n";
}

echo "完成。\n";
