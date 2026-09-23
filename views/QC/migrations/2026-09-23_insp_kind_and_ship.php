<?php
// =============================================================================
// views/QC/migrations/2026-09-23_insp_kind_and_ship.php
// 新增：①首件/末件標記 ②出貨檢驗（獨立於單一製程，綁在整個 BOM 底下）
//  - qc_check_form.insp_kind：NORMAL一般／FIRST首件／LAST末件／SHIP出貨檢驗
//    首件/末件仍走原本的 bom_ing_fid（掛在該製程底下，可能同一製程有好幾張首件重做）；
//    出貨檢驗不屬於任何單一製程，bom_ing_fid=0（比照既有「臨時檢驗單」的作法），
//    改用新欄位 ship_bom 記住是哪一張 BOM 的出貨檢驗。
//  - qc_check_form.ship_bom：出貨檢驗(SHIP)專用，存 BOM 號碼（例 B-1150910015）。
//  - 冪等：可重複執行（欄位已存在會略過）。
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-09-23_insp_kind_and_ship.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function colExists($pdo, $table, $col) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

if (!colExists($pdo, 'qc_check_form', 'insp_kind')) {
    $pdo->exec("ALTER TABLE qc_check_form ADD COLUMN insp_kind VARCHAR(10) NOT NULL DEFAULT 'NORMAL'
                COMMENT '檢驗性質：NORMAL一般／FIRST首件／LAST末件／SHIP出貨檢驗' AFTER form_type_id");
    echo "已新增 qc_check_form.insp_kind\n";
} else {
    echo "qc_check_form.insp_kind 已存在，略過\n";
}

if (!colExists($pdo, 'qc_check_form', 'ship_bom')) {
    $pdo->exec("ALTER TABLE qc_check_form ADD COLUMN ship_bom VARCHAR(20) NULL
                COMMENT '出貨檢驗(insp_kind=SHIP)所屬BOM號碼；一般製程檢驗以bom_ing_fid關聯製程，出貨檢驗不屬於單一製程改用此欄位' AFTER insp_kind");
    $pdo->exec("ALTER TABLE qc_check_form ADD KEY idx_ship_bom (ship_bom)");
    echo "已新增 qc_check_form.ship_bom\n";
} else {
    echo "qc_check_form.ship_bom 已存在，略過\n";
}

echo "完成。\n";
