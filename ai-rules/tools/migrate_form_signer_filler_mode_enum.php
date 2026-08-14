<?php
// 表單簽核設計器：fsd_stage_signer.mode ENUM 補上 'filler'(填表人)選項，配合新增的簽核人來源。可重複執行。
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");
$st = $db->query("SHOW COLUMNS FROM fsd_stage_signer LIKE 'mode'");
$col = $st->fetch(PDO::FETCH_ASSOC);
if (strpos($col['Type'], "'filler'") === false) {
    $db->exec("ALTER TABLE fsd_stage_signer MODIFY COLUMN mode ENUM('user','dept_auto_manager','submitter_supervisor','top_approver','filler') NOT NULL");
    echo "已異動：fsd_stage_signer.mode ENUM 新增 'filler'\n";
} else {
    echo "無異動（已存在）\n";
}
