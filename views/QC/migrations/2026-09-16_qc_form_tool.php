<?php
// =============================================================================
// views/QC/migrations/2026-09-16_qc_form_tool.php
// 量具改為「整張檢驗單綁一次」：新增 qc_form_tool（檢驗單 ↔ 量具，多對多）
//  - 原本量具是綁在每一個檢驗項目的每一筆讀值上（qc_measurement.tool_id），
//    使用者定案：追溯只需要到「這張檢驗單用了哪幾支量具」，不需要到「哪一項」。
//  - 舊資料回填：把 qc_measurement 既有的 tool_id 依檢驗單去重，補成表單層級的量具，
//    這樣舊紀錄在新畫面／列印／量具使用紀錄上仍看得到當時用的量具。
//  - 冪等：可重複執行（表已存在會略過、回填用 INSERT IGNORE）。
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-09-16_qc_form_tool.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$existed = (bool)$pdo->query("SHOW TABLES LIKE 'qc_form_tool'")->fetchColumn();

$pdo->exec("CREATE TABLE IF NOT EXISTS qc_form_tool (
    qc_form_id INT NOT NULL COMMENT '檢驗單 qc_check_form.qc_form_id',
    tool_id INT NOT NULL COMMENT '量具實例 qc_tool.Tool_id',
    sort_order INT DEFAULT 0 COMMENT '顯示順序（畫面挑選順序）',
    created_by CHAR(11) NULL COMMENT '建立者',
    created_at DATETIME NULL COMMENT '建立時間',
    PRIMARY KEY (qc_form_id, tool_id),
    KEY idx_tool (tool_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='檢驗單使用的量具（整張單綁一次，不綁到個別檢驗項目）'");

echo ($existed ? "qc_form_tool 已存在，略過建表\n" : "已建立 qc_form_tool\n");

// ---- 回填：舊資料的量具記在 qc_measurement.tool_id，依檢驗單去重補進來 ----
$before = (int)$pdo->query("SELECT COUNT(*) FROM qc_form_tool")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO qc_form_tool (qc_form_id, tool_id, sort_order, created_by, created_at)
            SELECT m.qc_form_id, m.tool_id, 0, NULL, NOW()
              FROM qc_measurement m
              JOIN qc_check_form f ON f.qc_form_id = m.qc_form_id
              JOIN qc_tool t ON t.Tool_id = m.tool_id
             WHERE m.tool_id IS NOT NULL
             GROUP BY m.qc_form_id, m.tool_id");
$after = (int)$pdo->query("SELECT COUNT(*) FROM qc_form_tool")->fetchColumn();

printf("回填完成：新增 %d 列（總計 %d 列，涵蓋 %d 張檢驗單）\n",
    $after - $before, $after,
    (int)$pdo->query("SELECT COUNT(DISTINCT qc_form_id) FROM qc_form_tool")->fetchColumn());
