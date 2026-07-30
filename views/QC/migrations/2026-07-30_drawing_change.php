<?php
// =============================================================================
// 一次性 migration：圖面變更紀錄（AS 表單 2-PD-01-07 圖面變更簽收單）
//   1) qc_drawing_change          變更主檔（含影響起始製程、自動建立的檢驗標準新版本）
//   2) qc_drawing_change_ack      簽收紀錄（通知相關人員 → 回簽）
//   3) qc_drawing_change_confirm  檢驗人員確認「已依新版次更新檢驗項目」（逐製程）
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-07-30_drawing_change.php
// 可重複執行。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
CREATE TABLE IF NOT EXISTS qc_drawing_change (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  change_no       VARCHAR(30)  NOT NULL COMMENT '變更單號 DWG-YYYYMM-nnn',
  as_doc_no       VARCHAR(40)  NOT NULL DEFAULT '2-PD-01-07' COMMENT '綁定的 AS 表單編號',
  d_id            INT          NOT NULL COMMENT 'd_setting.d_id（料號）',
  old_revision    VARCHAR(30)  NULL COMMENT '變更前圖面版次',
  new_revision    VARCHAR(30)  NULL COMMENT '變更後圖面版次',
  change_date     DATE         NULL COMMENT '客戶/內部發出變更的日期',
  source          VARCHAR(20)  NULL COMMENT '變更來源：客戶／內部',
  customer_doc_no VARCHAR(80)  NULL COMMENT '客戶通知文件編號',
  from_process_no INT          NULL COMMENT '從哪個製程開始受影響(process_no.ProcessNo)；NULL=全部製程',
  summary         VARCHAR(255) NOT NULL COMMENT '變更摘要（必填）',
  detail          TEXT         NULL COMMENT '變更內容明細',
  old_version_id  INT          NULL COMMENT '變更前的檢驗標準版本',
  new_version_id  INT          NULL COMMENT '自動建立的檢驗標準新版本',
  status          VARCHAR(10)  NOT NULL DEFAULT 'OPEN' COMMENT 'OPEN=進行中 / CLOSED=已結案',
  created_by      CHAR(11)     NULL,
  created_at      DATETIME     NULL,
  KEY idx_did (d_id), KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='圖面變更紀錄（AS 2-PD-01-07 圖面變更簽收單）'");
echo "qc_drawing_change OK\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS qc_drawing_change_ack (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  change_id  INT      NOT NULL,
  user_id    INT      NOT NULL COMMENT '需簽收的人員',
  acked_at   DATETIME NULL     COMMENT '回簽時間；NULL=尚未簽收',
  note       VARCHAR(255) NULL,
  KEY idx_change (change_id), KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='圖面變更簽收紀錄'");
echo "qc_drawing_change_ack OK\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS qc_drawing_change_confirm (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  change_id    INT          NOT NULL,
  process_name VARCHAR(100) NULL COMMENT '哪一個製程的檢驗項目已更新',
  version_id   INT          NULL COMMENT '確認採用的檢驗標準版本',
  note         VARCHAR(255) NULL,
  confirmed_by CHAR(11)     NULL,
  confirmed_at DATETIME     NULL,
  KEY idx_change (change_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='圖面變更後，檢驗人員確認已更新檢驗項目（逐製程）'");
echo "qc_drawing_change_confirm OK\n";
echo "DONE\n";
