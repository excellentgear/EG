<?php
// 表單簽核設計器：案件改成「自己上傳要簽核的文件」，樣板改為建立案件時旁邊的欄位提示/白名單而已
// （使用者2026-08-14明確要求）。新增 draft 狀態、案件自己的檔案欄位、案件自己的頁面尺寸/框選表、
// 刪除紀錄表(超級管理員硬刪不留紀錄／一般管理員+操作密碼軟刪可復原)。可重複執行。
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function addCol(PDO $db, array &$done, string $table, string $col, string $ddl): void {
    if (colExists($db, $table, $col)) return;
    $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    $done[] = "$table.$col";
}

$done = [];
addCol($db, $done, 'fsd_case', 'file_type', "ENUM('image','pdf') NULL COMMENT '案件自己上傳要簽核的文件類型(不再沿用樣板的檔案)' AFTER template_version");
addCol($db, $done, 'fsd_case', 'file_name', "VARCHAR(255) NULL COMMENT '案件自己上傳的檔名(不存路徑,鐵律5)' AFTER file_type");

// status ENUM 新增 'draft'（建立案件後先上傳文件+拖放框選，存草稿或送出二擇一才真正開始跑關卡）
$col = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fsd_case' AND COLUMN_NAME='status'")->fetchColumn();
if ($col && strpos($col, "'draft'") === false) {
    $db->exec("ALTER TABLE fsd_case MODIFY COLUMN status ENUM('draft','in_progress','approved','rejected','void') NOT NULL DEFAULT 'draft'");
    $done[] = 'fsd_case.status(+draft)';
}

$tables = [];
$tables['fsd_case_page'] = "CREATE TABLE IF NOT EXISTS fsd_case_page (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    page_no INT NOT NULL,
    width_pt DECIMAL(10,2) NOT NULL,
    height_pt DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uq_case_page (case_id, page_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='案件自己上傳文件的頁面尺寸'";

$tables['fsd_case_field'] = "CREATE TABLE IF NOT EXISTS fsd_case_field (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    slot_key VARCHAR(40) NOT NULL COMMENT '對應樣板schema快照的槽位key,只能是樣板本身有框選過的槽位+框型(白名單)',
    box_type ENUM('stamp','reply') NOT NULL,
    page_no INT NOT NULL,
    x DECIMAL(8,6) NOT NULL,
    y DECIMAL(8,6) NOT NULL,
    w DECIMAL(8,6) NOT NULL,
    h DECIMAL(8,6) NOT NULL,
    UNIQUE KEY uq_case_slot_box (case_id, slot_key, box_type),
    KEY idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='案件自己框選的圖章/回覆區塊位置(在案件自己上傳的文件上)'";

$tables['fsd_case_delete_log'] = "CREATE TABLE IF NOT EXISTS fsd_case_delete_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    prior_status ENUM('draft','in_progress','approved','rejected','void') NOT NULL,
    deleted_by INT NOT NULL,
    deleted_by_name VARCHAR(60) NULL,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_by INT NULL,
    restored_by_name VARCHAR(60) NULL,
    restored_at DATETIME NULL,
    KEY idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='案件軟刪除紀錄(超級管理員id=1硬刪不寫此表；一般管理員+操作密碼軟刪寫此表可復原)'";

foreach ($tables as $table => $sql) {
    $exists = (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetchColumn();
    $db->exec($sql);
    if (!$exists) $done[] = $table;
}

echo $done ? ("已異動：\n  " . implode("\n  ", $done) . "\n") : "無異動（皆已存在）\n";
