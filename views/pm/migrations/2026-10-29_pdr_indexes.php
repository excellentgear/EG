<?php
// =============================================================================
// pm_process_daily_report（製程每日報工）補索引
//  - 原本只有 PRIMARY：依製令(bom_ing_fid)、機台+日期、日期 查詢全是全表掃描。
//    待加工排程 NOW/V2 的「查詢已報工工單」每筆工單 4 條查詢各掃全表（100 筆約 0.9 秒），
//    process_report_query、機台 24H 時程表、報工人員統計也都靠這幾個欄位查。
//  - 純新增索引，不改資料、可隨時 DROP INDEX 還原。
//  - 冪等：可重複執行。MySQL 沒有 ADD INDEX IF NOT EXISTS，先 SHOW INDEX 再 ALTER。
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\pm\migrations\2026-10-29_pdr_indexes.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$want = [
    'idx_pdr_fid'          => '(bom_ing_fid, report_source)',
    'idx_pdr_machine_date' => '(machine_id, report_date)',
    'idx_pdr_date'         => '(report_date)',
];
$have = [];
foreach ($pdo->query("SHOW INDEX FROM pm_process_daily_report")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['Key_name']] = 1;

foreach ($want as $name => $cols) {
    if (isset($have[$name])) { echo "$name 已存在，略過\n"; continue; }
    $pdo->exec("ALTER TABLE pm_process_daily_report ADD INDEX $name $cols");
    echo "已新增索引 $name $cols\n";
}
