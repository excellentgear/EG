<?php
/**
 * 一次性：對帳進度追蹤（recon_track.php）應收＋應付，帳款月份 2026-09（含）以前一律設為「已對帳」。
 * 使用者交辦 2026-11-17。
 *   - 帳款月份一律走 act_ar_rows()/act_ap_rows()（內含結帳日與臨時結帳日判定），
 *     所以「結帳日問題」（9 月底前才出的貨若依結帳日歸到 10 月）不會被誤算進 9 月。
 *   - 只動「狀態空白」且「沒有被略過」的列；已經有狀態的（處理中、已送會計…）一律不動。
 *   - 狀態歷程時間取該列結帳日（沒有結帳日用帳款月份月底），工作天數統計不會因批次補登而失真；
 *     note 註明來源，之後可查。
 * 用法：預設只試算；加 --run 才寫入。可重複執行（已設過的不會再動）。
 * 執行：C:\MAMP\bin\php\php8.3.1\php.exe views\ACC\migrations\2026-11-17_recon_baseline_to_2026_09.php [--run]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'C:/MAMP/htdocs';
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/acc_track_lib.php';

$run = in_array('--run', $argv, true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
act_ensure_schema($db);

$LAST = '2026-09';
$first = $db->query("SELECT DATE_FORMAT(LEAST(
            COALESCE((SELECT MIN(Order_date) FROM is_list WHERE Order_date > '2000-01-01'),'2099-01-01'),
            COALESCE((SELECT MIN(IR_date) FROM ir_track WHERE IR_date > '2000-01-01'),'2099-01-01')), '%Y-%m')")->fetchColumn();
if (!$first || $first > $LAST) $first = '2018-07';
$first = max($first, '2018-01');

$perms = ['uid' => null, 'uname' => '系統批次'];
$total = ['ar' => 0, 'ap' => 0]; $touched = ['ar' => 0, 'ap' => 0]; $now = date('Y-m-d H:i:s');
for ($t = strtotime($first . '-01'); date('Y-m', $t) <= $LAST; $t = strtotime('+1 month', $t)) {
    $bm = date('Y-m', $t);
    foreach (['ar', 'ap'] as $side) {
        $rows = ($side === 'ar') ? act_ar_rows($db, $bm) : act_ap_rows($db, $bm, false);
        foreach ($rows as $r) {
            $total[$side]++;
            if ((string)$r['status'] !== '') continue;
            $tid = (int)$r['track_id'];
            $sk = $db->prepare("SELECT skipped_at FROM acc_recon_track WHERE id=?"); $sk->execute([$tid]);
            if ($sk->fetchColumn()) continue;
            $touched[$side]++;
            if (!$run) continue;
            $at = ($r['cutoff_date'] ? $r['cutoff_date'] : date('Y-m-t', $t)) . ' 12:00:00';
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE acc_recon_track SET status='reconciled', modified_at=?, modified_by=NULL, modified_by_name='系統批次' WHERE id=? AND status=''")->execute([$now, $tid]);
                foreach ([['processing', null], ['reconciled', 'processing']] as [$to, $from]) {
                    $db->prepare("INSERT INTO acc_recon_track_status_log (track_id,from_status,to_status,changed_by,changed_by_name,changed_at,note)
                                  VALUES (?,?,?,NULL,'系統批次',?,?)")->execute([$tid, $from, $to, $at, '2026-11-17 批次：' . $LAST . '（含）以前視為已對帳']);
                }
                $db->commit();
            } catch (Throwable $e) { $db->rollBack(); echo "失敗 $side $bm $tid: " . $e->getMessage() . "\n"; }
        }
    }
    echo "$bm ok\n";
}
echo ($run ? '【已寫入】' : '【試算，未寫入】') . " 範圍 {$first} ~ {$LAST}；列數 AR {$total['ar']}／AP {$total['ap']}；需設為已對帳 AR {$touched['ar']}／AP {$touched['ap']}\n";
