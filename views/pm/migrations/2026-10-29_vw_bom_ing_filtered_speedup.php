<?php
// =============================================================================
// vw_bom_ing_filtered 提速：把 all_null 子查詢（判斷「這張製令是否所有製程都沒發包日」）
// 限縮在「未結案的製令」範圍內計算。
//  - 原本對整張 bom_ing（約 8.5 萬列、1.25 萬張製令）分組，vw_bom_ing 又引用該視圖 3 次，
//    待加工排程 NOW/V2 載入約 0.5 秒都花在這裡。
//  - 此視圖輸出本來就只含未結案製令（WHERE bom.processing_state <> 1），
//    所以只限縮 all_null 的計算範圍，輸出列與欄位完全不變。
//  - 安全機制：改之前把「舊定義」存成同資料夾的 .backup.sql；改完比對 vw_bom_ing 與
//    vw_bom_ing_filtered 全部列欄的雜湊，不一致就自動還原舊定義。
//  - 冪等：已改過會略過。還原：加參數 --rollback。
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\pm\migrations\2026-10-29_vw_bom_ing_filtered_speedup.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$backup = __DIR__ . '/2026-10-29_vw_bom_ing_filtered.backup.sql';

function fp(PDO $pdo) {
    $out = [];
    foreach (['vw_bom_ing_filtered', 'vw_bom_ing'] as $v) {
        $rows = $pdo->query("SELECT * FROM $v ORDER BY bom_ing_fid")->fetchAll(PDO::FETCH_ASSOC);
        $out[$v] = [count($rows), md5(json_encode($rows))];
    }
    return $out;
}
function viewDef(PDO $pdo) { return $pdo->query("SHOW CREATE VIEW vw_bom_ing_filtered")->fetch(PDO::FETCH_ASSOC)['Create View']; }

if (in_array('--rollback', $argv ?? [], true)) {
    if (!is_file($backup)) { exit("找不到備份檔 $backup\n"); }
    $pdo->exec(file_get_contents($backup));
    exit("已還原舊定義\n");
}

$def = viewDef($pdo);
$old = "from `bom_ing` group by `bom_ing`.`bom`) `all_null`";
$new = "from `bom_ing` where `bom_ing`.`bom` in (select `bom`.`bom` from `bom` where ((`bom`.`processing_state` is null) or (`bom`.`processing_state` <> '1'))) group by `bom_ing`.`bom`) `all_null`";
if (strpos($def, $new) !== false) { exit("已經是限縮版，略過\n"); }
if (substr_count($def, $old) !== 1) { exit("視圖定義與預期不符（找不到唯一的 all_null 片段），未修改\n"); }

$before = fp($pdo);
file_put_contents($backup, preg_replace('/^CREATE /', 'CREATE OR REPLACE ', $def));
$pdo->exec(preg_replace('/^CREATE /', 'CREATE OR REPLACE ', str_replace($old, $new, $def)));
$after = fp($pdo);

if ($before !== $after) {
    $pdo->exec(file_get_contents($backup));
    echo "輸出不一致，已自動還原舊定義\n";
    echo "before=" . json_encode($before) . "\nafter=" . json_encode($after) . "\n";
    exit(1);
}
echo "已更新 vw_bom_ing_filtered；輸出比對一致：" . json_encode($after) . "\n";
