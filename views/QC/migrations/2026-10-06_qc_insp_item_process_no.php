<?php
// =============================================================================
// views/QC/migrations/2026-10-06_qc_insp_item_process_no.php
// 新增 qc_inspection_item.process_no 並做一次性回填。
//
// 背景：管制計畫 CP 查無 SIP 時要退回「線上檢驗」(inspection_entry_v2.php) 自己的
// 檢驗標準庫(qc_inspection_item)當內容來源——使用者明確要求「不是比對製程文字，
// 應該比對製程ID」，因為這張表原本只有自由文字 process_name，同一製程有「車床」
// 「車 床」兩種寫法，文字比對不可靠（見 qc_inspection_lib.php 的
// qc_v2_ensure_process_no_col()／qc_v2_items_by_process()，唯一實作）。
//
// 回填規則：process_name 去除全部空白後，與 process_no.ProcessName 去除全部空白
// 完全相同，才回填（嚴格比對，不猜近似值）；對不上的只列出來，process_no 留 NULL，
// 要靠管理員之後到「線上檢驗→標準管理」逐筆改。
//
// 用法：
//   試算（不寫入）：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-10-06_qc_insp_item_process_no.php
//   實際執行：      & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-10-06_qc_insp_item_process_no.php --run
// 冪等：只回填 process_no IS NULL 的項目，重跑不會動已經填過的。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
include_once __DIR__ . '/../../../src/common/qc_inspection_lib.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$doRun = in_array('--run', $argv, true);

echo $doRun ? "=== 執行模式 ===\n" : "=== 試算模式（加 --run 才真的寫入）===\n";

$hadColBefore = (bool)$pdo->query("SHOW COLUMNS FROM qc_inspection_item LIKE 'process_no'")->fetch();
$hasCol = $hadColBefore;
if (!$hadColBefore) {
    if ($doRun) {
        qc_v2_ensure_process_no_col($pdo);
        $hasCol = true;   // 本次新增後，接下來的回填步驟要能馬上寫，不可以還卡在「新增前」的判斷
        echo "[已新增] qc_inspection_item.process_no\n";
    } else {
        echo "[將新增] qc_inspection_item.process_no\n";
    }
} else {
    echo "[已存在] qc_inspection_item.process_no\n";
}

// 製程主檔：去空白後的名稱 => ProcessNo（重複名稱的不收，避免回填到錯的那一個）
$nameMap = []; $dupNames = [];
foreach ($pdo->query("SELECT ProcessNo, ProcessName FROM process_no WHERE ProcessName IS NOT NULL AND ProcessName<>''") as $r) {
    $key = preg_replace('/\s+/u', '', (string)$r['ProcessName']);
    if ($key === '') continue;
    if (isset($nameMap[$key])) { $dupNames[$key] = true; continue; }
    $nameMap[$key] = (int)$r['ProcessNo'];
}
foreach (array_keys($dupNames) as $k) unset($nameMap[$k]);

$rows = $pdo->query(
    ($hadColBefore
        ? "SELECT item_id, process_name FROM qc_inspection_item WHERE process_no IS NULL AND process_name IS NOT NULL AND process_name<>''"
        : "SELECT item_id, process_name FROM qc_inspection_item WHERE process_name IS NOT NULL AND process_name<>''")
)->fetchAll(PDO::FETCH_ASSOC);

$matched = 0; $unmatched = [];
$upd = $pdo->prepare("UPDATE qc_inspection_item SET process_no=? WHERE item_id=?");
foreach ($rows as $r) {
    $key = preg_replace('/\s+/u', '', (string)$r['process_name']);
    if (isset($nameMap[$key])) {
        $matched++;
        if ($doRun && $hasCol) $upd->execute([$nameMap[$key], (int)$r['item_id']]);
    } else {
        $unmatched[(string)$r['process_name']] = ($unmatched[(string)$r['process_name']] ?? 0) + 1;
    }
}

echo "候選項目：" . count($rows) . " 筆，可比對到製程代號：$matched 筆" . ($doRun ? "（已寫入）" : "（試算，未寫入）") . "\n";
if ($unmatched) {
    echo "比對不到的 process_name（需人工到「線上檢驗→標準管理」逐筆指定）：\n";
    foreach ($unmatched as $nm => $n) echo "  - 「{$nm}」 x{$n}\n";
}
if (!$doRun) echo "加 --run 才會真的新增欄位與寫入。\n";
