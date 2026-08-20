<?php
// =============================================================================
// 一次性 migration：表單編號改依「表單上的日期」重編（2026-08-20 使用者明確要求）
//   td_dev_eval.doc_no  ← 依 fill_date（填表日期）
//   pfmea_doc.doc_no    ← 依 biz_date （業務日期）
//   格式維持 YYYYMMDD + 3 位流水號；同一天內依 id 由小到大重新編 001、002…
//   沒有日期的（極少數舊資料）退回用建檔日 DATE(created_at)。
//   已刪除(is_deleted=1)的單據也一起重編——doc_no 是 UNIQUE，不重編會跟新號碼相撞。
//
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\TD\migrations\2026-08-20_docno_by_date.php
//   加 --dry 只列出將如何變更、不寫入。可重複執行（結果穩定，第二次跑會顯示 0 筆變更）。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$dry = in_array('--dry', $argv ?? [], true);
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * 依日期欄位重編一張表的 doc_no。
 * 兩階段寫入：先把要變更的列改成暫時值(~id)，再寫入正式編號，
 * 否則中途會撞到 UNIQUE KEY（新舊編號在同一個號碼空間裡交換）。
 */
function renumber(PDO $pdo, string $table, string $dateCol, bool $dry): array {
    $rows = $pdo->query("SELECT id, doc_no, COALESCE($dateCol, DATE(created_at)) AS d
                           FROM $table ORDER BY d, id")->fetchAll(PDO::FETCH_ASSOC);
    $seqByDate = []; $plan = [];
    foreach ($rows as $r) {
        $ymd = str_replace('-', '', substr((string)$r['d'], 0, 10));
        if (!preg_match('/^\d{8}$/', $ymd)) continue;   // 連建檔日都沒有的資料不動
        $seq = ($seqByDate[$ymd] = ($seqByDate[$ymd] ?? 0) + 1);
        $newNo = $ymd . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        if ($newNo !== (string)$r['doc_no']) $plan[] = ['id'=>(int)$r['id'], 'old'=>$r['doc_no'], 'new'=>$newNo, 'date'=>$r['d']];
    }
    if (!$dry && $plan) {
        $pdo->beginTransaction();
        try {
            $tmp = $pdo->prepare("UPDATE $table SET doc_no=? WHERE id=?");
            foreach ($plan as $p) $tmp->execute(['~' . $p['id'], $p['id']]);
            $fin = $pdo->prepare("UPDATE $table SET doc_no=? WHERE id=?");
            foreach ($plan as $p) $fin->execute([$p['new'], $p['id']]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
    return $plan;
}

foreach ([['td_dev_eval','fill_date','產品開發評估表'], ['pfmea_doc','biz_date','PFMEA']] as [$table, $dateCol, $label]) {
    $plan = renumber($pdo, $table, $dateCol, $dry);
    echo "== {$label}（{$table}，依 {$dateCol}）：" . count($plan) . " 筆" . ($dry ? '將' : '已') . "重編 ==\n";
    foreach ($plan as $p) echo "  #{$p['id']}  {$p['old']} → {$p['new']}  ({$p['date']})\n";
}
echo $dry ? "\n(--dry 模式，未寫入任何資料)\n" : "\n完成。\n";
