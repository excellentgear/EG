<?php
// =============================================================================
// 一次性 migration：外來文件清單「待補檔案」項目表（PFMEA 缺件偵測用）
//   external_doc_pending：PFMEA 已建立、但該料號在外來文件清單一列都沒有者，
//   由「PFMEA 缺件偵測」建立成待補項目，補上傳附件後轉為 done（附件即成為正式清單一列）。
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\Sales\migrations\2026-08-19_external_doc_pending.php
// 可重複執行。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
CREATE TABLE IF NOT EXISTS external_doc_pending (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ds_pk        INT          NOT NULL COMMENT 'd_setting.d_id',
  part_no      VARCHAR(100) NULL COMMENT '建立當下的料號字串（備查，顯示一律即時查 d_setting）',
  customer_id  VARCHAR(50)  NULL COMMENT '建立當下的客戶（備查）',
  source_kind  VARCHAR(20)  NOT NULL DEFAULT 'pfmea' COMMENT '偵測來源：pfmea=PFMEA 已建立但外來文件缺件',
  ref_no       VARCHAR(30)  NULL COMMENT '來源文件編號（PFMEA 表 doc_no）',
  status       VARCHAR(10)  NOT NULL DEFAULT 'pending' COMMENT 'pending=待補 / done=已補檔 / ignored=不列入',
  filled_attach_id INT      NULL COMMENT '補檔後產生的 part_attachments.id',
  filled_at    DATETIME     NULL,
  filled_by    VARCHAR(50)  NULL,
  created_by   VARCHAR(50)  NULL,
  created_at   DATETIME     NULL,
  UNIQUE KEY uq_item (ds_pk, source_kind),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外來文件清單待補檔案項目（PFMEA 缺件偵測）'");
echo "external_doc_pending OK\n";
echo "done\n";
