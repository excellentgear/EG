<?php
/**
 * 補開「發料不良」漏開的品質異常單（2026-11-11）：外包批次 badmat_qty>0、但沒有任何未刪除、
 * src_outsource_batch_id=該批 的異常單者，一律補開並通知品管（走 qab_outsource_batch_auto_open()，
 * 與回廠存檔同一條路徑）。開單人＝該批最後一筆回廠紀錄的建立者，查不到用 1。
 * 用法：php 本檔 [--run]   （預設只試算；可重複執行，已開過的不會再開）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/qa_abnormal_lib.php';
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$run = in_array('--run', $argv, true);
$rows = $pdo->query("SELECT b.batch_id, b.badmat_qty, b.scrap_qty, bi.bom,
                            (SELECT r.created_by FROM bom_ing_outsource_return r WHERE r.batch_id=b.batch_id ORDER BY r.return_id DESC LIMIT 1) AS uid
                       FROM bom_ing_outsource_batch b
                       LEFT JOIN bom_ing bi ON bi.bom_ing_fid=b.bom_ing_fid
                      WHERE b.badmat_qty > 0
                        AND NOT EXISTS (SELECT 1 FROM qa_abnormal_order o WHERE o.src_outsource_batch_id=b.batch_id AND o.deleted_at IS NULL)
                      ORDER BY b.batch_id")->fetchAll(PDO::FETCH_ASSOC);
echo ($run ? '執行' : '試算') . '：待補開 ' . count($rows) . " 批\n";
foreach ($rows as $r) {
    echo '批次#' . $r['batch_id'] . ' 製令 ' . $r['bom'] . ' 發料不良 ' . (float)$r['badmat_qty'] . ' 報廢 ' . (float)$r['scrap_qty'];
    if ($run) {
        $res = qab_outsource_batch_auto_open($pdo, (int)$r['batch_id'], (int)($r['uid'] ?: 1));
        echo $res['err'] !== '' ? ' → 失敗：' . $res['err'] : ' → ' . ($res['ab']['no'] ?? '（未開）');
    }
    echo "\n";
}
