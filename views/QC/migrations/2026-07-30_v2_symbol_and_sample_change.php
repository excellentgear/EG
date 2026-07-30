<?php
// =============================================================================
// 一次性 migration：品管檢驗表2.0（views/QC/inspection_entry_v2.php）新增兩張表
//   1) qc_symbol            工程符號主檔（Ø ° ± ▽ …），供檢驗項目名稱/標準值插入；僅管理員可維護
//   2) qc_sample_change_log 抽驗數變更紀錄（誰、從幾件改成幾件、理由），掛在該張檢驗表單下
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-07-30_v2_symbol_and_sample_change.php
// 可重複執行（IF NOT EXISTS + 種子資料只在空表時寫入）
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
CREATE TABLE IF NOT EXISTS qc_symbol (
  sym_id     INT AUTO_INCREMENT PRIMARY KEY,
  symbol     VARCHAR(8)  NOT NULL COMMENT '符號本身，如 Ø ± ▽',
  label      VARCHAR(50) NULL     COMMENT '說明，如 直徑',
  sort_order INT         DEFAULT 0,
  is_active  TINYINT(1)  DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='線上檢驗：工程符號主檔（僅管理員維護）'");
echo "qc_symbol OK\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS qc_sample_change_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  qc_form_id INT          NOT NULL COMMENT '對應 qc_check_form.qc_form_id',
  old_qty    INT          NULL     COMMENT '原抽驗數',
  new_qty    INT          NOT NULL COMMENT '改為抽驗數',
  reason     VARCHAR(255) NOT NULL COMMENT '變更理由（必填）',
  changed_by CHAR(11)     NULL,
  changed_at DATETIME     NULL,
  KEY idx_form (qc_form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='線上檢驗：抽驗數變更紀錄'");
echo "qc_sample_change_log OK\n";

// 種子符號（沿用 views/Sales/image_editor.php 的工程符號組，另加常用幾何公差符號）
if ((int)$pdo->query("SELECT COUNT(*) FROM qc_symbol")->fetchColumn() === 0) {
    $seed = [
        ['Ø','直徑'], ['⌀','直徑（另一種寫法）'], ['°','度'], ['±','正負公差'],
        ['▽','加工符號（研磨＝連按多個）'], ['↧','深度'], ['⌴','沉頭孔／柱坑'], ['⌵','錐坑'],
        ['□','正方形'], ['⌒','圓弧'], ['Ra','表面粗糙度'], ['×','乘號'],
        ['R','半徑'], ['C','倒角'], ['∥','平行度'], ['⊥','垂直度'],
        ['○','真圓度'], ['◎','同心度'], ['⌭','圓柱度'], ['⌖','位置度'],
        ['µ','微米'], ['²','平方'], ['³','立方'],
    ];
    $st = $pdo->prepare("INSERT INTO qc_symbol (symbol,label,sort_order,is_active) VALUES (?,?,?,1)");
    foreach ($seed as $i => $s) { $st->execute([$s[0], $s[1], ($i+1)*10]); }
    echo "seeded " . count($seed) . " symbols\n";
} else {
    echo "qc_symbol already has data, skip seed\n";
}
echo "DONE\n";
