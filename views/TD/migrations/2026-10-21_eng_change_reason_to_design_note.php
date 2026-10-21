<?php
/**
 * 工程變更：設變事由說明只有「其他變更」才填。變更方式不是「其他」卻在事由說明留了字的舊單據
 * （多半是圖面變更自動轉入的摘要），把內容搬到「設計分析補充」（design_note 原本是空的才搬）。可重複執行。
 */
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'C:/MAMP/htdocs';
include 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
include 'C:/MAMP/htdocs/EGsystem/src/common/eng_change_lib.php';
$db = (new DBConnection())->getPDO();
ec_ensure_schema($db);
$n = $db->exec("UPDATE eng_change SET design_note=change_reason, change_reason=''
                 WHERE change_type<>'other' AND TRIM(COALESCE(change_reason,''))<>'' AND TRIM(COALESCE(design_note,''))=''");
echo "搬移 $n 筆\n";
