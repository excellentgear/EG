<?php
// =============================================================================
// views/QC/migrations/2026-10-05_qc_insp_no.php
// 新增「檢驗單號」insp_no：{管理員可設字首，預設 QR} + YYYYMMDD + 流水號3碼（例 QR20261005001）。
//  - qc_check_form 新增 insp_no 欄位＋索引
//  - 新表 qc_insp_no_seq：逐日流水號（與 qa_scrap_seq 同一套設計）
//  - 既有「正式送出」(status<>DRAFT) 但還沒有編號的舊紀錄，依 check_date 由舊到新補配一次
//    （草稿不補——草稿本來就不該有編號）
//  - 冪等：可重複執行（欄位/表已存在會略過、已有編號的紀錄不會重配）
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-10-05_qc_insp_no.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
include_once __DIR__ . '/../../../src/common/qc_inspection_lib.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---- ① qc_check_form.insp_no ----
$col = $pdo->query("SHOW COLUMNS FROM qc_check_form LIKE 'insp_no'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    $pdo->exec("ALTER TABLE qc_check_form ADD COLUMN insp_no VARCHAR(20) NULL COMMENT '檢驗單號（字首+YYYYMMDD+流水3碼，管理員可設字首，改字首不溯及既往）' AFTER qc_form_id");
    echo "已新增 qc_check_form.insp_no\n";
} else {
    echo "qc_check_form.insp_no 已存在，略過新增欄位\n";
}
$idx = $pdo->query("SHOW INDEX FROM qc_check_form WHERE Key_name='idx_insp_no'")->fetch(PDO::FETCH_ASSOC);
if (!$idx) {
    $pdo->exec("ALTER TABLE qc_check_form ADD INDEX idx_insp_no (insp_no)");
    echo "已新增索引 idx_insp_no\n";
} else {
    echo "索引 idx_insp_no 已存在，略過\n";
}

// ---- ② qc_insp_no_seq（逐日流水號） ----
$existedSeq = (bool)$pdo->query("SHOW TABLES LIKE 'qc_insp_no_seq'")->fetchColumn();
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_insp_no_seq (
    seq_date DATE NOT NULL,
    last_no INT NOT NULL DEFAULT 0,
    PRIMARY KEY (seq_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='檢驗單號流水（字首+YYYYMMDD+3碼）'");
echo ($existedSeq ? "qc_insp_no_seq 已存在，略過建表\n" : "已建立 qc_insp_no_seq\n");

// ---- ③ 預設字首（沒設定過才寫入預設值 QR，不覆蓋管理員已設定的值） ----
$hasPrefix = (bool)$pdo->query("SELECT 1 FROM system_settings WHERE setting_key='qc_insp_no_prefix' LIMIT 1")->fetchColumn();
if (!$hasPrefix) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id) VALUES ('qc_insp_no_prefix','QR',NULL)")->execute();
    echo "已寫入預設字首 QR\n";
} else {
    echo "qc_insp_no_prefix 設定已存在，略過\n";
}

// ---- ④ 回填：已正式送出但還沒有編號的舊紀錄，依 check_date→qc_form_id 由舊到新逐一補配 ----
// （草稿 status='DRAFT' 不補；已有編號的不重配）
$rows = $pdo->query("SELECT qc_form_id, COALESCE(check_date, DATE(created_at)) AS biz_date
                      FROM qc_check_form
                      WHERE status <> 'DRAFT' AND (insp_no IS NULL OR insp_no='')
                      ORDER BY COALESCE(check_date, DATE(created_at)) ASC, qc_form_id ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
$upd = $pdo->prepare("UPDATE qc_check_form SET insp_no=? WHERE qc_form_id=?");
$n = 0;
foreach ($rows as $r) {
    $no = qc_insp_no_alloc($pdo, (string)$r['biz_date']);
    $upd->execute([$no, (int)$r['qc_form_id']]);
    $n++;
}
printf("回填完成：補配 %d 筆舊紀錄的檢驗單號\n", $n);
