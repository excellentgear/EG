<?php
/**
 * 文件制、修申請單：把已核准單據上「單位主管＝申請人自己」的那幾張，依新規則重算並回寫
 * （2026-09-16 使用者回報：品管組組長開的單，單位主管欄蓋的是他自己的章）
 *
 * 規則一律呼叫 unit_supervisor_lib.php 的 eg_unit_supervisor()（ai-rules/24 審核層級規範，唯一實作）：
 *   本單位最高主管 → 本人就是最高主管時往上一層單位 → **到「課」級為止**。
 *
 * 只動 `status='approved'` 且 `sign_sup_id = applicant_id` 的單，而且**只在新規則算出不同的人時才改**——
 * 課級單位的最高主管本人開的單（課長／經理自己開單）依規則本來就該蓋自己的章，一律不動。
 * 圖章日期（sign_sup_date）不動，那是該單的業務日期，不因為換人而改變。
 * 人員一律依該單 apply_date 回推當時職務（ai-rules/22），所以補的歷史單不會蓋成現任者。
 *
 * 用法（可重複執行）：
 *   php 2026-09-16_doc_apply_unit_sup.php          ← 只試算，不寫入
 *   php 2026-09-16_doc_apply_unit_sup.php --run    ← 實際寫入
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/unit_supervisor_lib.php';

$run = in_array('--run', $argv, true);
$db  = (new DBConnection())->getPDO();

$rows = $db->query("SELECT apply_id, apply_no, apply_date, applicant_id, applicant_name, dept_id, dept_name,
                           sign_sup_id, sign_sup_name
                      FROM doc_apply
                     WHERE status='approved' AND COALESCE(is_deleted,0)=0
                       AND sign_sup_id IS NOT NULL AND sign_sup_id = applicant_id
                     ORDER BY apply_date, apply_id")->fetchAll(PDO::FETCH_ASSOC);

echo $run ? "【實際寫入】\n" : "【試算模式】加上 --run 才會真的寫入\n";
echo str_repeat('=', 96) . "\n";
printf("%-16s %-12s %-10s %-12s %s\n", '單號', '申請日期', '申請人', '申請單位', '單位主管：目前 → 應為');

$changed = $keep = 0;
$upd = $db->prepare("UPDATE doc_apply SET sign_sup_id=?, sign_sup_name=?, updated_at=NOW() WHERE apply_id=?");
foreach ($rows as $r) {
    $sup = eg_unit_supervisor($db, (int)$r['applicant_id'],
                              $r['dept_id'] !== null ? (int)$r['dept_id'] : null,
                              (string)$r['apply_date']);
    $newId   = (int)($sup['id'] ?? 0);
    $newName = (string)($sup['name'] ?? '');
    if (!$newId || $newId === (int)$r['applicant_id']) {
        $keep++;
        printf("%-16s %-12s %-10s %-12s %s（依規則從缺，維持申請人自己簽）\n",
            $r['apply_no'], $r['apply_date'], $r['applicant_name'], $r['dept_name'], $r['sign_sup_name']);
        continue;
    }
    $changed++;
    printf("%-16s %-12s %-10s %-12s %s → %s（%s）\n",
        $r['apply_no'], $r['apply_date'], $r['applicant_name'], $r['dept_name'],
        $r['sign_sup_name'], $newName, $sup['reason']);
    if ($run) $upd->execute([$newId, $newName, (int)$r['apply_id']]);
}

echo str_repeat('=', 96) . "\n";
echo "掃描 " . count($rows) . " 張；需要改 {$changed} 張；依規則維持原樣 {$keep} 張。"
   . ($run ? "（已寫入）\n" : "（尚未寫入，加 --run）\n");
