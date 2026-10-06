<?php
// =============================================================================
// views/QC/migrations/2026-10-06_check_form_container.php
// 線上檢驗「批次與檢驗歷程」要能顯示每一輪當時用的容器（使用者回報：只在 bom_ing.QC_ps/QC_ps2
// 看得到現在這一站「最新」的容器，換過幾次之後舊的那幾輪就再也查不到）。
//  - container_1／container_2：存檔格式沿用 bom_ing.QC_ps/QC_ps2 同一套「箱數+代碼」
//    （例 "3P"），唯一解析/組字實作是 src/common/qc_container_lib.php，這裡只加欄位。
//  - 只在「正常存檔路徑」(save_inspection) 寫入；修改既有紀錄、出貨檢驗、臨時檢驗單
//    都不寫（那幾種本來就不會觸發「允收(OK)自動彙總」，容器欄位對它們沒有意義）。
//  - 冪等：可重複執行（欄位已存在會略過）。
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-10-06_check_form_container.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function colExistsCf($pdo, $table, $col) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

$cols = [
    'container_1' => "ALTER TABLE qc_check_form ADD COLUMN container_1 VARCHAR(100) NULL COMMENT '本輪容器1（格式同bom_ing.QC_ps，例3P）' AFTER main_remark",
    'container_2' => "ALTER TABLE qc_check_form ADD COLUMN container_2 VARCHAR(100) NULL COMMENT '本輪容器2' AFTER container_1",
];
foreach ($cols as $col => $sql) {
    if (!colExistsCf($pdo, 'qc_check_form', $col)) {
        $pdo->exec($sql);
        echo "已新增 qc_check_form.$col\n";
    } else {
        echo "qc_check_form.$col 已存在，略過\n";
    }
}

echo "完成。\n";
