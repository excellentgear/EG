<?php
/**
 * 管制計畫 CP（Control Plan）建表 migration
 * 用法：
 *   試算：& C:\MAMP\bin\php\php8.3.1\php.exe views\QA\migrations\2026-10-02_control_plan.php
 *   執行：& C:\MAMP\bin\php\php8.3.1\php.exe views\QA\migrations\2026-10-02_control_plan.php --run
 * 可重複執行（CREATE TABLE IF NOT EXISTS；預設資料只在空表時塞，不覆蓋管理員改過的設定）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
$run = in_array('--run', $argv, true);

$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
    "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ddl = [];

$ddl['cp_stage'] = "CREATE TABLE IF NOT EXISTS cp_stage (
  stage_id INT AUTO_INCREMENT PRIMARY KEY,
  stage_code VARCHAR(20) NOT NULL COMMENT 'prototype/prelaunch/production',
  stage_name VARCHAR(50) NOT NULL COMMENT '畫面與列印顯示的名稱',
  default_freq VARCHAR(100) DEFAULT NULL COMMENT '該階段的預設樣本大小/頻率',
  sort_order INT DEFAULT 0,
  is_active TINYINT DEFAULT 1 COMMENT '0=停用，本公司不使用這一段',
  note VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY uk_code (stage_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管制計畫階段(AIAG三段，管理員可停用)'";

$ddl['cp_special_class'] = "CREATE TABLE IF NOT EXISTS cp_special_class (
  class_id INT AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(10) NOT NULL COMMENT '列印用符號',
  class_name VARCHAR(50) NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  sort_order INT DEFAULT 0,
  is_active TINYINT DEFAULT 1,
  sev_min TINYINT DEFAULT NULL COMMENT '嚴重度下限(1~10)，NULL=不限制；依AS文件3-TD-01門檻判定用',
  sev_max TINYINT DEFAULT NULL COMMENT '嚴重度上限',
  occ_min TINYINT DEFAULT NULL COMMENT '發生率下限(1~10)，NULL=不限制',
  occ_max TINYINT DEFAULT NULL COMMENT '發生率上限'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='特殊特性分類(依AS文件3-TD-01：關鍵特性CC/重要特性SC，依PFMEA嚴重度/發生率數值判定)'";

$ddl['cp_reaction_opt'] = "CREATE TABLE IF NOT EXISTS cp_reaction_opt (
  opt_id INT AUTO_INCREMENT PRIMARY KEY,
  opt_text VARCHAR(255) NOT NULL,
  sort_order INT DEFAULT 0,
  is_active TINYINT DEFAULT 1,
  is_default TINYINT DEFAULT 0 COMMENT '1=自動帶入時用這一列當預設（反應計畫是稽核必看欄位，不可整欄空白）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='反應計畫常用語(管制計畫最後一欄)'";

$ddl['cp_doc'] = "CREATE TABLE IF NOT EXISTS cp_doc (
  cp_id INT AUTO_INCREMENT PRIMARY KEY,
  cp_no VARCHAR(40) NOT NULL COMMENT 'CP-YYYYMMDD-NNN 依表單日期產生',
  stage_id INT NOT NULL,
  scope ENUM('part','family') DEFAULT 'part' COMMENT 'part=單一料號 family=產品族',
  part_d_id INT DEFAULT NULL COMMENT 'd_setting.d_id (scope=part)',
  part_no_text VARCHAR(100) DEFAULT NULL,
  product_name VARCHAR(200) DEFAULT NULL,
  family_name VARCHAR(100) DEFAULT NULL COMMENT 'scope=family 的產品族名稱',
  customer_id VARCHAR(11) DEFAULT NULL,
  customer_name VARCHAR(100) DEFAULT NULL,
  ver_no VARCHAR(10) DEFAULT NULL COMMENT 'CP自己的版次 A/B/C',
  part_rev VARCHAR(30) DEFAULT NULL COMMENT '圖面版次(d_setting.Revision)',
  form_date DATE DEFAULT NULL COMMENT '表單日期=業務日期，決定編號與AS版次回推',
  status ENUM('draft','submitted','approved') DEFAULT 'draft',
  src_order_id INT DEFAULT NULL COMMENT '來源訂單(被標為AS認證的那張) order_track.Order_id',
  src_order_oo VARCHAR(50) DEFAULT NULL COMMENT '來源訂單編號(顯示用快取)',
  src_bom VARCHAR(30) DEFAULT NULL COMMENT '製程列取自哪張製令',
  src_bom_date DATE DEFAULT NULL COMMENT '該製令開立日(由編號回推)',
  pfmea_doc_id INT DEFAULT NULL COMMENT '對應的PFMEA pfmea_doc.id',
  org_code VARCHAR(100) DEFAULT NULL COMMENT '組織/工廠代碼',
  key_contact VARCHAR(150) DEFAULT NULL COMMENT '主要聯絡人/電話',
  core_team VARCHAR(500) DEFAULT NULL COMMENT '核心小組成員',
  customer_eng_appr VARCHAR(100) DEFAULT NULL COMMENT '客戶工程核准/日期',
  customer_qa_appr VARCHAR(100) DEFAULT NULL COMMENT '客戶品保核准/日期',
  other_appr VARCHAR(100) DEFAULT NULL,
  note TEXT DEFAULT NULL,
  submitted_at DATETIME DEFAULT NULL, submitted_by INT DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL, approved_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL,
  created_by_name VARCHAR(50) DEFAULT NULL,
  modified_at DATETIME DEFAULT NULL, modified_by INT DEFAULT NULL,
  is_deleted TINYINT DEFAULT 0,
  UNIQUE KEY uk_no (cp_no),
  KEY idx_part (part_d_id), KEY idx_stage (stage_id),
  KEY idx_order (src_order_id), KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管制計畫主檔(一張CP=一個階段)'";

$ddl['cp_family_part'] = "CREATE TABLE IF NOT EXISTS cp_family_part (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cp_id INT NOT NULL,
  part_d_id INT NOT NULL,
  part_no_text VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uk_cp_part (cp_id, part_d_id),
  KEY idx_cp (cp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='產品族CP涵蓋的料號(scope=family)'";

$ddl['cp_process'] = "CREATE TABLE IF NOT EXISTS cp_process (
  cp_proc_id INT AUTO_INCREMENT PRIMARY KEY,
  cp_id INT NOT NULL,
  seq INT DEFAULT 0,
  process_no INT DEFAULT NULL COMMENT 'process_no.ProcessNo',
  process_name VARCHAR(100) DEFAULT NULL,
  op_desc VARCHAR(500) DEFAULT NULL COMMENT '作業說明',
  machine VARCHAR(200) DEFAULT NULL COMMENT '機器/裝置',
  jig_tool VARCHAR(200) DEFAULT NULL COMMENT '治具/工具',
  maker_id_no VARCHAR(20) DEFAULT NULL COMMENT '委外廠商 maker_list.Maker_Id_No',
  maker_name VARCHAR(100) DEFAULT NULL,
  is_outsource TINYINT DEFAULT 0,
  bom_sn INT DEFAULT NULL COMMENT '來源製令的製程序(排序依據)',
  src VARCHAR(20) DEFAULT 'manual' COMMENT 'bom/pfmea/manual',
  note VARCHAR(255) DEFAULT NULL,
  KEY idx_cp (cp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管制計畫的製程列(以BOM製程為主)'";

$ddl['cp_item'] = "CREATE TABLE IF NOT EXISTS cp_item (
  cp_item_id INT AUTO_INCREMENT PRIMARY KEY,
  cp_proc_id INT NOT NULL,
  seq INT DEFAULT 0,
  char_no VARCHAR(20) DEFAULT NULL COMMENT '特性編號',
  char_product VARCHAR(500) DEFAULT NULL COMMENT '產品特性',
  char_process VARCHAR(500) DEFAULT NULL COMMENT '製程特性',
  special_class_id INT DEFAULT NULL,
  special_class_text VARCHAR(50) DEFAULT NULL COMMENT '特殊特性文字(來源PFMEA時備援)',
  spec_text VARCHAR(500) DEFAULT NULL COMMENT '規格/公差(屬性值寫這裡)',
  up_limit VARCHAR(50) DEFAULT NULL COMMENT '公差上限(計量值)',
  lo_limit VARCHAR(50) DEFAULT NULL,
  eval_method VARCHAR(255) DEFAULT NULL COMMENT '評估/量測技術',
  tool_id INT DEFAULT NULL COMMENT 'qc_tool.Tool_id',
  tool_no VARCHAR(50) DEFAULT NULL COMMENT '檢具編號(文字，可為N/A)',
  sample_size VARCHAR(50) DEFAULT NULL,
  sample_freq VARCHAR(100) DEFAULT NULL,
  control_method VARCHAR(500) DEFAULT NULL COMMENT '管制方法',
  reaction_plan VARCHAR(500) DEFAULT NULL COMMENT '反應計畫',
  src VARCHAR(20) DEFAULT 'manual' COMMENT 'sip/tpl/pfmea/manual',
  src_ref VARCHAR(50) DEFAULT NULL COMMENT '來源列id(追溯用)',
  note VARCHAR(255) DEFAULT NULL,
  KEY idx_proc (cp_proc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管制計畫特性列(CP主體)'";

$ddl['cp_revision'] = "CREATE TABLE IF NOT EXISTS cp_revision (
  rev_id INT AUTO_INCREMENT PRIMARY KEY,
  cp_id INT NOT NULL,
  ver_no VARCHAR(10) DEFAULT NULL,
  form_date DATE DEFAULT NULL,
  rev_note VARCHAR(500) DEFAULT NULL,
  changed_by INT DEFAULT NULL, changed_by_name VARCHAR(50) DEFAULT NULL,
  changed_at DATETIME DEFAULT NULL,
  KEY idx_cp (cp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管制計畫版次履歷'";

$ddl['cp_suggest_ignore'] = "CREATE TABLE IF NOT EXISTS cp_suggest_ignore (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_d_id INT NOT NULL,
  stage_id INT NOT NULL DEFAULT 0 COMMENT '0=所有階段都不建議',
  reason VARCHAR(255) DEFAULT NULL,
  created_by INT DEFAULT NULL, created_at DATETIME DEFAULT NULL,
  UNIQUE KEY uk_part_stage (part_d_id, stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='建議建立清單的忽略名單'";

echo $run ? "=== 執行模式 ===\n" : "=== 試算模式（加 --run 才真的建立）===\n";
foreach ($ddl as $t => $sql) {
    $exists = $db->query("SHOW TABLES LIKE ".$db->quote($t))->fetch();
    if ($exists) { echo "  [已存在] $t\n"; continue; }
    if (!$run) { echo "  [將建立] $t\n"; continue; }
    $db->exec($sql);
    echo "  [已建立] $t\n";
}

// ---- 預設資料（只在空表時塞）----
$seed = function($table, $rows, $insSql) use ($db, $run) {
    $ex = $db->query("SHOW TABLES LIKE ".$db->quote($table))->fetch();
    if (!$ex) { echo "  [略過預設] $table 尚未建立\n"; return; }
    $c = (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    if ($c > 0) { echo "  [已有資料] " . $table . "（" . $c . " 筆），不動\n"; return; }
    if (!$run) { echo "  [將塞預設] $table ".count($rows)." 筆\n"; return; }
    $st = $db->prepare($insSql);
    foreach ($rows as $r) { $st->execute($r); }
    echo "  [已塞預設] $table ".count($rows)." 筆\n";
};

echo "--- 預設資料 ---\n";
$seed('cp_stage', [
    ['prototype',  '試作 / 首件', '100% 全尺寸（首件）', 1, 1, '訂單首件階段。本公司首件通過後即轉正式生產。'],
    ['prelaunch',  '試產',        '加嚴抽驗',            2, 1, '量產導入期。本公司為接單加工、無量產導入期時可停用這一段。'],
    ['production', '量產 / 生產', '',                    3, 1, '正式生產階段。頻率取自 SIP 檢驗項目或抽樣規則。'],
], "INSERT INTO cp_stage (stage_code,stage_name,default_freq,sort_order,is_active,note) VALUES (?,?,?,?,?,?)");

// 依 AS 文件 3-TD-01「失效模式及效應分析應用辦法」第5.14節：
// 關鍵特性CC＝嚴重度9~10(需於PFMEA標註)／重要特性SC＝嚴重度5~8或發生率4~10(製程上需特別管制)。
// 一般特性無符號、不建一筆（維持「沒有分類=一般特性」的既有語意，3-TD-01的表格上它的符號欄本來就是「無」）。
// 判定一律依PFMEA的severity/occurrence數值算，不比對classification文字——
// PFMEA自己的自動判定(pfmea_classify_rule_get)目前是二分法、沒有9~10那一段，
// 只比文字永遠配不到「關鍵特性」，見 cp_special_class_match() 的說明。
$seed('cp_special_class', [
    ['CC', '關鍵特性', '依 AS 文件 3-TD-01：嚴重度9~10，需於PFMEA標註。', 1, 1, 9, 10, null, null],
    ['SC', '重要特性', '依 AS 文件 3-TD-01：嚴重度5~8或發生率4~10，製程上需特別管制。', 2, 1, 5, 8, 4, 10],
    ['☆', '客戶指定', '非 AS 文件 3-TD-01 定義的官方分類；如客戶合約另有指定特殊特性要求可手動啟用，不依數值自動判定。', 3, 0, null, null, null, null],
], "INSERT INTO cp_special_class (symbol,class_name,note,sort_order,is_active,sev_min,sev_max,occ_min,occ_max) VALUES (?,?,?,?,?,?,?,?,?)");

// is_default＝自動帶入時用哪一列當預設。預設挑「隔離標示，通知品管判定」——
// 它最通用、而且不預設任何處置結論（退修／報廢／特採是業務判斷，不可由系統先填）。
$seed('cp_reaction_opt', [
    ['隔離標示，通知品管判定', 1, 1, 1],
    ['停機檢查，追溯上一批', 2, 1, 0],
    ['開立品質異常處理單（2-QA-01-01）', 3, 1, 0],
    ['退修', 4, 1, 0],
    ['報廢', 5, 1, 0],
    ['特採（需總經理裁示）', 6, 1, 0],
    ['通知生管改派製程', 7, 1, 0],
    ['100% 全檢後放行', 8, 1, 0],
], "INSERT INTO cp_reaction_opt (opt_text,sort_order,is_active,is_default) VALUES (?,?,?,?)");

echo $run ? "\n完成。\n" : "\n（試算結束，未寫入）\n";
