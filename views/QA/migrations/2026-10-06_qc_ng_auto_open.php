<?php
// =============================================================================
// views/QA/migrations/2026-10-06_qc_ng_auto_open.php
// 線上檢驗(inspection_entry_v2.php) NG 判定後「直接自動開立品質異常單草稿」——
// 使用者要求比照報工NG自動開立同一套「草稿＋品管確認」模式，差別只在開單人就是
// 當下操作的品管人員本人（不必像報工那條路徑去解析現場主管）。
//
// 本模組（qa_abnormal_lib.php）的 schema 演進一律走 qab_ensure_schema()（在
// QaAbnormal_API.php 每次請求自動呼叫的既有機制），不是獨立 migration 檔各自維護
// 一份 ALTER——新增的三個欄位（qa_abnormal_cat.is_qc_auto／qa_abnormal_order.
// src_qc_form_id／src_qc_item_sel）定義都已經寫進該函式的 $needCat/$need4 陣列。
// 這支檔案只是手動觸發一次該函式，讓欄位立刻就緒，不必等下一次真的呼叫 API。
//  - 冪等：可重複執行（qab_ensure_schema 內部本來就是逐欄檢查存在才新增）。
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QA\migrations\2026-10-06_qc_ng_auto_open.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
include_once __DIR__ . '/../../../src/common/qa_abnormal_lib.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

qab_ensure_schema($pdo);

function colExistsQcAuto($pdo, $table, $col) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}
foreach ([
    ['qa_abnormal_cat', 'is_qc_auto'],
    ['qa_abnormal_order', 'src_qc_form_id'],
    ['qa_abnormal_order', 'src_qc_item_sel'],
] as [$t, $c]) {
    echo "{$t}.{$c}: " . (colExistsQcAuto($pdo, $t, $c) ? "OK" : "**仍缺失，請檢查 qab_ensure_schema()**") . "\n";
}
echo "完成。\n";
