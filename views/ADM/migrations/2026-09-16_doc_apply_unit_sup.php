<?php
/**
 * 文件制、修申請單：把已核准單據上的「單位主管」依新規則重算並回寫
 * （2026-09-16 使用者回報：品管組組長開的單，單位主管欄蓋的是他自己的章）
 *
 * 規則一律呼叫 unit_supervisor_lib.php 的 eg_unit_supervisor()（ai-rules/24 審核層級規範，唯一實作）：
 *   本單位最高主管 → 本人就是最高主管時往上一層單位 → **到「課」級為止**。
 *
 * 兩種模式（都只動 `status='approved'` 的單，而且**只在新規則真的算得出人時才寫入**）：
 *   ①預設：`sign_sup_id = applicant_id`（單位主管蓋到申請人自己）→ 改成往上一層單位的主管。
 *     課級單位的最高主管本人開的單（課長／經理自己開單）依規則本來就該蓋自己的章，一律不動。
 *   ②`--fill-blank`：`sign_sup_id IS NULL`（單位主管整格空白，當年解析不到人）→ 補上該解析到的那一位。
 *     依規則本來就該留白的（申請人就是課級最高主管、或整條路徑當時真的沒有主管）維持空白。
 *
 * 圖章日期（sign_sup_date）＝該單業務日期，不因為換人／補人而改變；空白單補人時一併把日期補成 apply_date
 * （沒有日期的圖章印不出來）。人員一律依該單 apply_date 回推當時職務（ai-rules/22），
 * 所以補歷史單不會蓋成現任者——例：2025-10 那幾張品管組的單，當時品管課還沒有課長，就維持留白。
 *
 * 用法（可重複執行）：
 *   php 2026-09-16_doc_apply_unit_sup.php                      ← 試算（蓋到自己的那些）
 *   php 2026-09-16_doc_apply_unit_sup.php --run                ← 實際寫入
 *   php 2026-09-16_doc_apply_unit_sup.php --fill-blank         ← 試算（整格空白的那些）
 *   php 2026-09-16_doc_apply_unit_sup.php --fill-blank --run   ← 實際寫入
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/unit_supervisor_lib.php';

$run   = in_array('--run', $argv, true);
$blank = in_array('--fill-blank', $argv, true);
$db    = (new DBConnection())->getPDO();

$cond = $blank ? "sign_sup_id IS NULL" : "sign_sup_id IS NOT NULL AND sign_sup_id = applicant_id";
$rows = $db->query("SELECT apply_id, apply_no, apply_date, applicant_id, applicant_name, dept_id, dept_name,
                           sign_sup_id, sign_sup_name, sign_sup_date
                      FROM doc_apply
                     WHERE status='approved' AND COALESCE(is_deleted,0)=0 AND $cond
                     ORDER BY apply_date, apply_id")->fetchAll(PDO::FETCH_ASSOC);

echo ($blank ? "【模式：補上空白的單位主管】" : "【模式：單位主管蓋到申請人自己】")
   . ($run ? "【實際寫入】\n" : "【試算，加上 --run 才會真的寫入】\n");
echo str_repeat('=', 100) . "\n";
printf("%-16s %-12s %-10s %-12s %s\n", '單號', '申請日期', '申請人', '申請單位', '單位主管：目前 → 應為');

$changed = $keep = 0;
$upd = $db->prepare("UPDATE doc_apply SET sign_sup_id=?, sign_sup_name=?, sign_sup_date=COALESCE(sign_sup_date, ?),
                            updated_at=NOW() WHERE apply_id=?");
foreach ($rows as $r) {
    $sup = eg_unit_supervisor($db, (int)$r['applicant_id'],
                              $r['dept_id'] !== null ? (int)$r['dept_id'] : null,
                              (string)$r['apply_date']);
    $newId   = (int)($sup['id'] ?? 0);
    $newName = (string)($sup['name'] ?? '');
    $now     = $blank ? '（空白）' : (string)$r['sign_sup_name'];
    if (!$newId || $newId === (int)$r['sign_sup_id']) {
        $keep++;
        printf("%-16s %-12s %-10s %-12s %s（依規則維持原樣：%s）\n",
            $r['apply_no'], $r['apply_date'], $r['applicant_name'], $r['dept_name'], $now,
            $newId ? '解析結果與目前相同' : $sup['reason']);
        continue;
    }
    $changed++;
    printf("%-16s %-12s %-10s %-12s %s → %s（%s）\n",
        $r['apply_no'], $r['apply_date'], $r['applicant_name'], $r['dept_name'],
        $now, $newName, $sup['reason']);
    if ($run) $upd->execute([$newId, $newName, (string)$r['apply_date'], (int)$r['apply_id']]);
}

echo str_repeat('=', 100) . "\n";
echo "掃描 " . count($rows) . " 張；需要改 {$changed} 張；依規則維持原樣 {$keep} 張。"
   . ($run ? "（已寫入）\n" : "（尚未寫入，加 --run）\n");
