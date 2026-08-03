<?php
// =============================================================================
// 一次性 migration：品管檢驗表2.0（views/QC/inspection_entry_v2.php）新增標準公差/客戶公差表
//   1) qc_tolerance_table 公差表表頭（名稱＋可選對應客戶；customer_id NULL＝通用標準）
//   2) qc_tolerance_band  公差表明細（依「標準值」區間對應上下公差）
// 用途：檢驗填寫頁「自動套用公差」——依項目標準值落在哪個區間，帶入上/下公差，
//       只套用在上下限都還沒填的欄位；不建立任何預設資料，區間由管理員自行設定。
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-08-03_tolerance_table.php
// 可重複執行（IF NOT EXISTS）
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
CREATE TABLE IF NOT EXISTS qc_tolerance_table (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL COMMENT '公差表名稱，如：公司標準公差、A客戶公差',
  customer_id CHAR(11)     NULL     COMMENT '對應 customer_list.customer_id；NULL=通用標準(非特定客戶)',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  CHAR(11)     NULL,
  created_at  DATETIME     NULL,
  updated_by  CHAR(11)     NULL,
  updated_at  DATETIME     NULL,
  KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='線上檢驗：標準公差/客戶公差表(表頭)'");
echo "qc_tolerance_table OK\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS qc_tolerance_band (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  tolerance_table_id INT           NOT NULL COMMENT '對應 qc_tolerance_table.id',
  min_value          DECIMAL(12,4) NOT NULL COMMENT '標準值下限(含)',
  max_value          DECIMAL(12,4) NOT NULL COMMENT '標準值上限(含)',
  plus_tolerance     DECIMAL(12,4) NOT NULL COMMENT '上公差(正值)',
  minus_tolerance    DECIMAL(12,4) NOT NULL COMMENT '下公差(存正值，套用時視為負向)',
  sort_order         INT DEFAULT 0,
  KEY idx_table (tolerance_table_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='線上檢驗：公差表明細(依標準值區間對應上下公差)'");
echo "qc_tolerance_band OK\n";

echo "DONE\n";
