<?php
// =============================================================================
// 一次性 migration：外來文件清單「只留最新版」的人工覆寫表
//   external_doc_version_override：以「料號(ds_pk) × 外來文件類別(cat_id)」為一組，
//     kind='pin'      → 指定該組的現行版是哪一筆附件（不看發行日期）
//     kind='keep_all' → 該組不做版本判定，全部保留（同標籤下本來就是不同文件時用）
//   沒有覆寫的組別＝自動判定：發行日期最新者為現行版，同日的全部保留（多頁掃描不會被吃掉）。
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\Sales\migrations\2026-08-20_external_doc_version.php
// 可重複執行。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
CREATE TABLE IF NOT EXISTS external_doc_version_override (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ds_pk      INT          NOT NULL COMMENT 'd_setting.d_id',
  cat_id     INT          NOT NULL COMMENT 'quotation_file_categories.id（外來文件類別）',
  kind       VARCHAR(10)  NOT NULL COMMENT 'pin=釘選現行版 / keep_all=該組不做版本判定全部保留',
  source     VARCHAR(10)  NULL COMMENT 'pin 專用：part=料號附件 / quote=報價附件',
  attach_id  INT          NULL COMMENT 'pin 專用：附件 id',
  part_no    VARCHAR(100) NULL COMMENT '設定當下的料號字串（備查）',
  created_by VARCHAR(50)  NULL,
  created_at DATETIME     NULL,
  UNIQUE KEY uq_grp (ds_pk, cat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外來文件清單版本判定的人工覆寫（釘選現行版／不判定版本）'");
echo "external_doc_version_override OK\n";
echo "done\n";
