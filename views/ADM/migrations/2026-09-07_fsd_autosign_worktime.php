<?php
/**
 * 表單簽核設計器：把既有的「自動簽核時間」重排進上班時間窗口 09:30~19:00
 * ------------------------------------------------------------------------------
 * 起因（使用者 2026-09-07 回報）：舊寫法把自動簽核時間 LEAST 到「業務日期 23:59:59」，
 * 於是補歷史案件時每個人都被壓成 23:59:59——既超出含加班的上班時間（08:00~19:00），
 * 同一件案子裡兩個人的時間又完全一樣。程式本身已改用共用的 eg_auto_sign_next_ts()，
 * 這支工具負責把「已經寫進去的舊資料」一併重排。
 *
 * 規則（使用者拍板）：
 *   ・日期完全不動（＝圖章上印的日期不變），只重排「時間」。
 *   ・同一件案子依審核順序（stage_seq → 寫入順序）時間由早到晚，兩個人不會相同。
 *   ・只動 is_auto=1 的自動簽核紀錄；真人簽的時間是真的，一律不碰
 *     （真人紀錄仍當作排序的基準點，自動簽核不會排到它前面）。
 *   ・approval_record 內對應的自動簽核紀錄（note 含「系統自動簽核」）跟著同步，
 *     送出時間往前挪一點，避免出現「決行早於送出」。
 *
 * 用法（可重複執行）：
 *   php views/ADM/migrations/2026-09-07_fsd_autosign_worktime.php          ← 只試算，不寫入
 *   php views/ADM/migrations/2026-09-07_fsd_autosign_worktime.php --run    ← 實際寫入
 *   php views/ADM/migrations/2026-09-07_fsd_autosign_worktime.php --verify ← 檢查現況是否全部合規
 */

require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/auto_sign_time_lib.php';

$args   = array_slice($argv, 1);
$run    = in_array('--run', $args, true);
$verify = in_array('--verify', $args, true);
$db     = (new DBConnection())->getPDO();

$WIN_S = EG_AUTOSIGN_START_MIN * 60;      // 09:30
$WIN_E = EG_AUTOSIGN_END_MIN * 60 + 59;   // 19:00:59
$secOf = function (string $ts): int {
    return (int)substr($ts, 11, 2) * 3600 + (int)substr($ts, 14, 2) * 60 + (int)substr($ts, 17, 2);
};

if ($verify) {
    $bad = $db->query("SELECT case_id, id, responded_at FROM fsd_case_response
                       WHERE is_auto=1 AND (TIME_TO_SEC(TIME(responded_at)) < $WIN_S OR TIME_TO_SEC(TIME(responded_at)) > $WIN_E)
                       ORDER BY case_id, id")->fetchAll(PDO::FETCH_ASSOC);
    $dup = $db->query("SELECT case_id, responded_at, COUNT(*) c FROM fsd_case_response
                       WHERE is_auto=1 GROUP BY case_id, responded_at HAVING c > 1")->fetchAll(PDO::FETCH_ASSOC);
    $ord = $db->query("SELECT a.case_id, a.id, a.responded_at, b.id id2, b.responded_at t2
                       FROM fsd_case_response a JOIN fsd_case_response b
                         ON b.case_id=a.case_id AND (b.stage_seq > a.stage_seq OR (b.stage_seq=a.stage_seq AND b.id > a.id))
                       WHERE a.is_auto=1 AND b.is_auto=1 AND DATE(a.responded_at)=DATE(b.responded_at)
                         AND b.responded_at <= a.responded_at")->fetchAll(PDO::FETCH_ASSOC);
    $badA = $db->query("SELECT id, entity_id, decided_at FROM approval_record
                        WHERE module='form_signer' AND note LIKE '%自動簽核%'
                          AND (TIME_TO_SEC(TIME(decided_at)) < $WIN_S OR TIME_TO_SEC(TIME(decided_at)) > $WIN_E)
                        ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    printf("超出 09:30~19:00 的：%d 筆\n同一案件時間重複的：%d 組\n審核順序與時間先後不符的：%d 組\n簽核紀錄(approval_record)超窗的：%d 筆\n",
        count($bad), count($dup), count($ord), count($badA));
    foreach (array_slice($bad, 0, 10) as $b) echo "  超窗 case {$b['case_id']} #{$b['id']} {$b['responded_at']}\n";
    foreach (array_slice($dup, 0, 10) as $d) echo "  重複 case {$d['case_id']} {$d['responded_at']} x{$d['c']}\n";
    foreach (array_slice($badA, 0, 10) as $b) echo "  超窗 approval #{$b['id']} case {$b['entity_id']} {$b['decided_at']}\n";
    exit((count($bad) + count($dup) + count($ord) + count($badA)) ? 1 : 0);
}

// 有自動簽核紀錄的案件
$caseIds = $db->query("SELECT DISTINCT case_id FROM fsd_case_response WHERE is_auto=1 ORDER BY case_id")
              ->fetchAll(PDO::FETCH_COLUMN);
$stRows = $db->prepare("SELECT id, case_id, stage_seq, slot_key, resolved_user_id, resolved_user_name, is_auto, responded_at
                        FROM fsd_case_response WHERE case_id=? AND responded_at IS NOT NULL
                        ORDER BY stage_seq, id");
$updResp = $db->prepare("UPDATE fsd_case_response SET responded_at=? WHERE id=?");
// 同一個人在同一關卡可能有多筆自動簽核紀錄（例：整關重跑過），所以要「一筆對一筆」逐一取用，
// 不可以每次都 LIMIT 1 取到同一筆——那會讓第二筆以後的 approval_record 被漏掉、時間留在 23:59:59。
$findAppr = $db->prepare("SELECT id, submitted_at, decided_at FROM approval_record
                          WHERE module='form_signer' AND entity_id=? AND level=? AND approver_id=?
                            AND note LIKE '%自動簽核%' AND DATE(decided_at)=? ORDER BY id");
$updAppr = $db->prepare("UPDATE approval_record SET submitted_at=?, decided_at=? WHERE id=?");

$totResp = 0; $totAppr = 0; $touchedCases = 0; $preview = [];
if ($run) $db->beginTransaction();
try {
    foreach ($caseIds as $cid) {
        $stRows->execute([(int)$cid]);
        $rows = $stRows->fetchAll(PDO::FETCH_ASSOC);
        $lastTs = null;              // 這件案子上一筆（含真人簽核）的時間，自動簽核一律排在它之後
        $changed = false;
        $apprUsed = [];              // 已配對掉的 approval_record id（同人同關卡多筆時要逐一取用）
        foreach ($rows as $r) {
            $date = substr((string)$r['responded_at'], 0, 10);
            if (!(int)$r['is_auto']) { $lastTs = (string)$r['responded_at']; continue; }   // 真人簽的不動
            $prev = ($lastTs && substr($lastTs, 0, 10) === $date) ? $lastTs : null;
            $cur  = (string)$r['responded_at'];
            // 已經在窗口內、又確實排在上一筆之後的就原樣保留（重複執行才不會每跑一次就重新亂數一批時間）
            $okNow = ($secOf($cur) >= $WIN_S && $secOf($cur) <= $WIN_E) && (!$prev || $cur > $prev);
            $new   = $okNow ? $cur : eg_auto_sign_next_ts($db, $date, $prev);
            $lastTs = $new;
            if ($new !== $cur) {
                $changed = true; $totResp++;
                if (count($preview) < 12) $preview[] = "case {$r['case_id']} 第{$r['stage_seq']}關 {$r['resolved_user_name']}：{$cur} → $new";
            }
            if ($run && $new !== $cur) $updResp->execute([$new, (int)$r['id']]);
            $findAppr->execute([(int)$r['case_id'], 'stage_' . (int)$r['stage_seq'], (int)$r['resolved_user_id'], $date]);
            foreach ($findAppr->fetchAll(PDO::FETCH_ASSOC) as $a) {
                if (isset($apprUsed[(int)$a['id']])) continue;        // 這一筆已經配對給前一個回應紀錄了
                $apprUsed[(int)$a['id']] = true;
                if ((string)$a['decided_at'] !== $new) {
                    $totAppr++;
                    if (count($preview) < 16) $preview[] = "  └ 簽核紀錄 #{$a['id']}：{$a['decided_at']} → $new";
                    if ($run) $updAppr->execute([eg_auto_sign_before_ts($new), $new, (int)$a['id']]);
                }
                break;
            }
        }
        if ($changed) $touchedCases++;
    }
    if ($run) $db->commit();
} catch (Throwable $e) {
    if ($run && $db->inTransaction()) $db->rollBack();
    echo "失敗：" . $e->getMessage() . "\n"; exit(2);
}

echo ($run ? "【已寫入】" : "【試算，未寫入】") . "\n";
echo "案件數：{$touchedCases}　回應紀錄：{$totResp} 筆　簽核紀錄(approval_record)：{$totAppr} 筆\n";
foreach ($preview as $p) echo "  $p\n";
if (!$run) echo "\n要實際寫入請加 --run；寫入後可用 --verify 檢查。\n";
