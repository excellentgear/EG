<?php
// =============================================================================
// views/QC/migrations/2026-09-24_fix_orphaned_measurement_items.php
// 修補「歷史檢驗紀錄讀不到任何檢驗項目」——qc_measurement.item_id 指向已被刪除的
// qc_inspection_item 的一次性資料修復（根因已在 save_inspection / save_items 的
// update_std 路徑改成 UPSERT，見同批程式修正；本檔只處理修正之前留下的舊傷）。
//
// 根因：save_inspection（update_std=1，「存檔時同步更新此料號的檢驗標準」，
//       這個勾選框預設打勾）與 inspection_standard_setting.php 的 save_items，
//       原本都是「先 DELETE 整批 qc_inspection_item 再全部重新 INSERT」，
//       同名項目也會拿到全新的 item_id，讓所有指向舊 item_id 的 qc_measurement
//       從此在「修改」／「同料號歷次檢驗」查不到任何項目（success:true 但 items:[]，
//       不報錯，使用者 2026-09-24 回報「原本的檢驗結果好像都沒有帶進去」才發現）。
//
// 修法：只處理「可以唯一確定對應到哪一個現行項目」的孤兒——
//   同一張 qc_check_form 記錄的 (version_id, form_type_id, process_name) 範圍內，
//   現在剛好只有一個 is_active=1 的項目時，孤兒 item_id 一律改指向它
//   （這正是它原本被刪除重建前唯一可能的樣子：同一個範圍只會有這一個項目）。
//   範圍內現有 2 個以上候選、無法唯一判定的一律跳過不動，交由人工個案確認。
//
// 用法：
//   試算（不寫入）：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-09-24_fix_orphaned_measurement_items.php
//   實際執行：      & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-09-24_fix_orphaned_measurement_items.php --run
// 冪等：可重複執行——修過的孤兒已經不孤兒了，重跑只會再抓到「當下還孤兒」的那些。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$doRun = in_array('--run', $argv, true);

$sql = "SELECT m.item_id AS orphan_item_id, f.version_id, f.form_type_id, f.process_name,
               GROUP_CONCAT(DISTINCT m.qc_form_id) AS qc_form_ids,
               (SELECT GROUP_CONCAT(ci.item_id) FROM qc_inspection_item ci
                 WHERE ci.version_id=f.version_id AND ci.form_type_id=f.form_type_id
                   AND (ci.process_name <=> f.process_name) AND ci.is_active=1) AS active_candidate_ids
        FROM qc_measurement m
        JOIN qc_check_form f ON f.qc_form_id = m.qc_form_id
        LEFT JOIN qc_inspection_item i ON m.item_id = i.item_id
        WHERE i.item_id IS NULL
        GROUP BY m.item_id, f.version_id, f.form_type_id, f.process_name";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "沒有孤兒 item_id，不需修補。\n"; exit; }

$fixCount = 0; $skipCount = 0;
foreach ($rows as $r) {
    $orphanId = (int)$r['orphan_item_id'];
    $candidates = $r['active_candidate_ids'] ? explode(',', $r['active_candidate_ids']) : [];
    $forms = $r['qc_form_ids'];
    if (count($candidates) !== 1) {
        $skipCount++;
        printf("略過 item_id=%d（version=%s type=%s process=%s，影響 qc_form_id=%s）：現行候選 %d 個，無法唯一判定：%s\n",
            $orphanId, $r['version_id'], $r['form_type_id'], $r['process_name'], $forms,
            count($candidates), $r['active_candidate_ids'] ?: '（無）');
        continue;
    }
    $target = (int)$candidates[0];
    printf("%s item_id=%d → %d（version=%s type=%s process=%s，影響 qc_form_id=%s）\n",
        $doRun ? '修補' : '[試算]', $orphanId, $target, $r['version_id'], $r['form_type_id'], $r['process_name'], $forms);
    if ($doRun) {
        $n = $pdo->prepare("UPDATE qc_measurement SET item_id=? WHERE item_id=?")->execute([$target, $orphanId]);
        $fixCount++;
    }
}

printf("\n%s：可唯一判定 %d 組，無法判定（維持不動） %d 組。\n",
    $doRun ? '已修補' : '試算完成（尚未寫入，加 --run 才會真的修改）', $fixCount ?: count($rows) - $skipCount, $skipCount);
