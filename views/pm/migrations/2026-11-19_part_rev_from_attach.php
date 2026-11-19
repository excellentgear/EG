<?php
// =============================================================================
// views/pm/migrations/2026-11-19_part_rev_from_attach.php
// 舊資料回填：附件版次比料號主檔版次新者，把主檔版次更新成附件版次。
//  - 比對規則與日後上傳時相同（dwg_rev_newer，只升不降、結構不同不動、作廢附件不算）。
//  - 異動日期＝該附件的發行章日期，沒有就用上傳日（寫進版次異動紀錄的 changed_at）；
//    不動 d_setting.Modified_At（避免影響依修改時間判斷的功能）。
//  - 預設試算；加 --run 才寫入；可重複執行（已更新過的不會再被選到）。
//  - 加 --skip-blank 則不處理「主檔版次原本是空的」那一類。
//  - 執行：php views\pm\migrations\2026-11-19_part_rev_from_attach.php [--run] [--skip-blank]
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/dwg_change_lib.php';
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$run = in_array('--run', $argv ?? [], true);
$skipBlank = in_array('--skip-blank', $argv ?? [], true);
dwg_ensure_schema($pdo);
$obs = dwg_obsolete_cat_ids($pdo);

$rows = $pdo->query("SELECT a.id, a.d_id, a.revision, a.category_ids, a.issue_stamp_date, a.uploaded_at, s.Revision AS cur
    FROM part_attachments a JOIN d_setting s ON s.d_id = a.d_id
   WHERE a.revision IS NOT NULL AND TRIM(a.revision) <> ''
   ORDER BY a.d_id, COALESCE(a.issue_stamp_date, DATE(a.uploaded_at)), a.id")->fetchAll(PDO::FETCH_ASSOC);

$by = [];
foreach ($rows as $r) {
    $cats = array_filter(array_map('intval', explode(',', (string)$r['category_ids'])));
    if ($obs && array_intersect($cats, $obs)) continue;
    $by[$r['d_id']][] = $r;
}
$plan = []; $blank = 0; $up = 0;
foreach ($by as $dId => $list) {
    $cur = trim((string)$list[0]['cur']); $orig = $cur; $pick = null;
    foreach ($list as $r) {
        $rev = trim($r['revision']);
        if ($cur === '' || dwg_rev_newer($rev, $cur) === true) { $cur = $rev; $pick = $r; }
    }
    if (!$pick) continue;
    if ($orig === '' && $skipBlank) continue;
    $date = $pick['issue_stamp_date'] ?: substr((string)$pick['uploaded_at'], 0, 10);
    $plan[] = ['d_id'=>$dId,'old'=>$orig,'new'=>$cur,'date'=>$date,'att'=>$pick['id'],'src'=>$pick['issue_stamp_date'] ? '發行日' : '上傳日'];
    if ($orig === '') $blank++; else $up++;
}
echo "待更新 ".count($plan)." 筆（主檔原本有版次且較舊：{$up}；主檔原本空白：{$blank}）\n";
foreach (array_slice($plan, 0, 40) as $p) echo "d_id={$p['d_id']}  [{$p['old']}] -> [{$p['new']}]  {$p['date']}({$p['src']})  att#{$p['att']}\n";
if (!$run) { echo "\n（試算，未寫入；加 --run 才寫入）\n"; exit; }
$n = 0;
foreach ($plan as $p) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE d_setting SET Revision=? WHERE d_id=?")->execute([$p['new'], $p['d_id']]);
        $pdo->prepare("INSERT INTO d_setting_revision_log (d_id,old_revision,new_revision,source,changed_by_name,changed_at,note)
                       VALUES (?,?,?,'attach','系統回填',?,?)")
            ->execute([$p['d_id'], $p['old'] === '' ? null : $p['old'], $p['new'], $p['date'].' 00:00:00',
                       '舊資料回填：附件版次較新（日期取附件'.$p['src'].'，附件#'.$p['att'].'）']);
        $pdo->commit(); $n++;
    } catch (Throwable $e) { $pdo->rollBack(); echo "d_id={$p['d_id']} 失敗：".$e->getMessage()."\n"; }
}
echo "已更新 $n 筆\n";
