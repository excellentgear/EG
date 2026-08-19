<?php
/**
 * AS 文件管理：品質記錄一覽表（2-DC-01-03）明細表（可重複執行）。
 * 一覽表本身綁哪份 AS 文件走 asdoc_lib（system_parameters AS_DOC_BIND，模組代碼 as_doc_quality_record_list）；
 * 預設保存年限與製表日期放 system_settings；這裡只建「要列進一覽表的表單」明細。
 * 用法： php ai-rules/tools/migrate_asdoc_quality_record.php
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/_config.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$db->exec("CREATE TABLE IF NOT EXISTS as_quality_record_item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_id INT NOT NULL COMMENT 'as_document.id（要列入品質記錄一覽表的表單）',
    retention_years INT NULL COMMENT '保存年限(年)；NULL=套用設定的預設值',
    keeper_dept_id INT NULL COMMENT '保管單位；NULL=用該文件所屬部門',
    note VARCHAR(255) NULL COMMENT '備註',
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uk_qr_doc (doc_id),
    KEY idx_qr_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品質記錄一覽表明細'");
echo "  as_quality_record_item 已就緒\n";

// 預設保存年限（沒設過才寫入，不覆蓋既有設定）
$st = $db->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key=?");
$st->execute(['as_doc_qr_default_years']);
if (!(int)$st->fetchColumn()) {
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)")
       ->execute(['as_doc_qr_default_years', '3']);
    echo "  已寫入預設保存年限 as_doc_qr_default_years=3\n";
} else {
    echo "  as_doc_qr_default_years 已存在，未覆蓋\n";
}
echo "完成。\n";
