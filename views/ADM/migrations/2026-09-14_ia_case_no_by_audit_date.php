<?php
/**
 * 內部稽核：把「稽核件號」統一重編成現行規則（2026-09-14 使用者拍板）
 *
 * 規則＝西元年後兩碼 + MMDD + 3 位流水，**依稽核日期（稽核起）**，沒填才退回通知日期。
 * 起因：畫面上同時看得到兩種格式——
 *   ・251103001（西元＋通知日，線上新建的舊寫法）
 *   ・1141208001（民國年＋建檔日，更早以前的寫法）
 * 2024 兩張紙本轉入的 241115001／241216001 就是用稽核日期編的，以它為準。
 *
 * **只重編「還是草稿且未執行」的**——已發出／執行中／已結案的紙本上印著舊號，改了會對不起來。
 * 判定與重編一律呼叫 internal_audit_lib.php 的 ia_case_sync_no()（唯一實作，鐵律4）。
 *
 * 用法（可重複執行）：
 *   php 2026-09-14_ia_case_no_by_audit_date.php          ← 只試算，不寫入
 *   php 2026-09-14_ia_case_no_by_audit_date.php --run    ← 實際寫入
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/internal_audit_lib.php';

$run = in_array('--run', $argv, true);
$db  = (new DBConnection())->getPDO();
ia_ensure_schema($db);

$rows = $db->query("SELECT case_id, case_no, year, seq_no, notify_date, audit_from, status, executed
                      FROM ia_case WHERE COALESCE(is_deleted,0)=0 ORDER BY case_id")->fetchAll(PDO::FETCH_ASSOC);

echo $run ? "【實際寫入】\n" : "【試算模式】加上 --run 才會真的寫入\n";
echo str_repeat('=', 78) . "\n";
printf("%-8s %-6s %-12s %-12s %-12s %s\n", 'case_id', '次別', '目前件號', '稽核起', '通知日', '結果');

$changed = $skip = 0;
foreach ($rows as $c) {
    $base   = ia_case_no_base($c);
    $expect = $base ? date('ymd', strtotime($base)) : '';
    $locked = ((string)$c['status'] !== 'draft' || (int)$c['executed'] === 1);
    $fitted = ($expect !== '' && strncmp((string)$c['case_no'], $expect, 6) === 0 && strlen((string)$c['case_no']) === 9);

    if ($locked)      { $note = '不動（' . $c['status'] . ($c['executed'] ? '／已執行' : '') . '）'; $skip++; }
    elseif ($fitted)  { $note = '已符合規則，不動'; $skip++; }
    else {
        if ($run) {
            $r = ia_case_sync_no($db, (int)$c['case_id']);
            $note = $r['changed'] ? ('重編 → ' . $r['new']) : '未變動';
            if ($r['changed']) $changed++;
        } else {
            $note = '將重編 → ' . $expect . 'xxx';
            $changed++;
        }
    }
    printf("%-8s %-6s %-12s %-12s %-12s %s\n", $c['case_id'], '第' . $c['seq_no'] . '次',
           $c['case_no'], (string)$c['audit_from'], (string)$c['notify_date'], $note);
}
echo str_repeat('=', 78) . "\n";
echo ($run ? '已重編 ' : '待重編 ') . $changed . " 張，維持原樣 $skip 張。\n";
