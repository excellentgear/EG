<?php
// =============================================================================
// views/QC/migrations/2026-09-23_backfill_fields.php
// 管理員補資料：可設定檢驗日期／檢驗人員／主管審核人員與日期。
//  - inspector_by：實際檢驗人員（補資料用；沒設定時沿用 created_by，既有資料完全不受影響）。
//  - approved_by／approved_at：主管審核人員與日期（補資料用；沒設定時仍走既有的
//    system_settings『主管自動核可』全站設定，計算方式完全不變）。
//  - check_date 欄位本來就存在，本次只是開放管理員可以事後改它，不需要新欄位。
//  - 冪等：可重複執行（欄位已存在會略過）。
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-09-23_backfill_fields.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function colExists2($pdo, $table, $col) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

$cols = [
    'inspector_by' => "ALTER TABLE qc_check_form ADD COLUMN inspector_by CHAR(11) NULL COMMENT '實際檢驗人員(補資料用；未設定時沿用created_by)' AFTER created_by",
    'approved_by'  => "ALTER TABLE qc_check_form ADD COLUMN approved_by CHAR(11) NULL COMMENT '主管審核人員(補資料用；未設定時採全站自動核可設定)' AFTER inspector_by",
    'approved_at'  => "ALTER TABLE qc_check_form ADD COLUMN approved_at DATE NULL COMMENT '主管審核日期(補資料用)' AFTER approved_by",
];
foreach ($cols as $col => $sql) {
    if (!colExists2($pdo, 'qc_check_form', $col)) {
        $pdo->exec($sql);
        echo "已新增 qc_check_form.$col\n";
    } else {
        echo "qc_check_form.$col 已存在，略過\n";
    }
}

// qc_inspection_edit_log.action 原本是 ENUM('UNLOCK','EDIT','RELOCK')，補資料要留一筆稽核紀錄
// 但不算「修改」（不需要填修改原因），故另立一個 BACKFILL 動作，不要混進 EDIT 裡讓歷程看不出差異。
$actEnum = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qc_inspection_edit_log' AND COLUMN_NAME='action'")->fetchColumn();
if ($actEnum && strpos($actEnum, "'BACKFILL'") === false) {
    $pdo->exec("ALTER TABLE qc_inspection_edit_log MODIFY COLUMN action ENUM('UNLOCK','EDIT','RELOCK','BACKFILL') NOT NULL");
    echo "已擴充 qc_inspection_edit_log.action 加入 BACKFILL\n";
} else {
    echo "qc_inspection_edit_log.action 已含 BACKFILL，略過\n";
}

echo "完成。\n";
