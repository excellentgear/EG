<?php
/**
 * 一次性回填：產品開發評估表——已用「系統管理員快速設定→全部自動簽核」補完的舊資料，
 * 把表頭「填表日期(fill_date)」改成該筆的簽核業務日期（＝列印時各欄圖章上印的那個日期）。
 * 2026-08-18 使用者明確要求：新的自動簽核已改成填表日期自動＝簽核業務日期，既有資料也要一併補齊。
 *
 * 認定條件（避免動到人工簽核或「補登簽核」逐格指定日期的單）：該筆 8 個簽核欄全部都有簽核人、
 * 全部都是補登/自動簽核(is_backfill=1)、且 8 欄的簽核日期是同一天（＝自動簽核的特徵，補登簽核可逐格
 * 指定不同日期）。填表日期取 MAX(DATE(signed_at))＝圖章上印的日期，這樣列印出來填表日期與簽章日期一致。
 *
 * 同時回填新欄位 is_auto_sign=1（2026-08-18 新增）：標記這些欄位是自動簽核寫進來的，管理員日期選錯
 * 重跑「全部自動簽核」時才有辦法連圖章日期一起改；人工簽核與補登簽核的欄位維持 0，永遠不會被覆蓋。
 *
 * 用法：php migrate_td_dev_eval_filldate.php          （試算，不寫入）
 *       php migrate_td_dev_eval_filldate.php --apply  （實際寫入）
 */
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: 'C:/MAMP/htdocs';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/td_dev_eval_lib.php';

$apply = in_array('--apply', $argv, true);
$db = (new DBConnection())->getPDO();
td_dev_eval_ensure_schema($db);

$slotTotal = count(TD_DEV_EVAL_SLOTS);
$sql = "SELECT h.id, h.doc_no, h.fill_date, DATE(h.closed_at) AS closed_date,
               MAX(DATE(s.signed_at)) AS sign_date, COUNT(*) AS signed_cnt,
               SUM(s.is_backfill) AS backfill_cnt, COUNT(DISTINCT DATE(s.signed_at)) AS date_kinds
          FROM td_dev_eval h
          JOIN td_dev_eval_signoff s ON s.doc_id = h.id AND s.signed_by IS NOT NULL
         WHERE h.is_deleted = 0
      GROUP BY h.id
        HAVING signed_cnt >= $slotTotal AND backfill_cnt >= $slotTotal AND date_kinds = 1
      ORDER BY h.id";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$updDoc  = $db->prepare("UPDATE td_dev_eval SET fill_date=? WHERE id=?");
$updSign = $db->prepare("UPDATE td_dev_eval_signoff SET is_auto_sign=1 WHERE doc_id=?");
$changed = 0; $same = 0; $warn = 0;
foreach ($rows as $r) {
    if (!$r['sign_date']) continue;
    if ($apply) $updSign->execute([$r['id']]);   // 標記為自動簽核寫入，之後重跑才改得動圖章日期
    // 曾用不同日期重跑過自動簽核：舊版重跑時「已簽好的欄不會重蓋圖章日期」，只有 closed_at 被改掉，
    // 於是「最後一次指定的業務日期」與「圖章上印的日期」會是兩個不同的日子，無法判斷紙本上到底是哪一天，
    // 這種筆數一律不自動改，列出來請使用者在畫面上用正確日期重跑一次「全部自動簽核」
    //（新版會連圖章日期一起改，填表日期也會同步）。
    if ($r['closed_date'] && $r['closed_date'] !== $r['sign_date']) {
        printf("#%-4d %-14s [請人工確認] 最後指定業務日期 %s、圖章日期 %s 不一致，本次不動；請用正確日期重跑一次「全部自動簽核」\n",
               $r['id'], $r['doc_no'], $r['closed_date'], $r['sign_date']);
        $warn++;
        continue;
    }
    if ((string)$r['fill_date'] === (string)$r['sign_date']) { $same++; continue; }
    printf("#%-4d %-14s 填表日期 %s → %s%s\n", $r['id'], $r['doc_no'], $r['fill_date'] ?: '(空)',
           $r['sign_date'], $apply ? '' : '（試算）');
    if ($apply) $updDoc->execute([$r['sign_date'], $r['id']]);
    $changed++;
}
echo "\n符合「全部自動簽核」的單據 " . count($rows) . " 筆；需調整填表日期 $changed 筆、已一致 $same 筆、待人工確認(日期不一致，本次未動) $warn 筆。"
   . ($apply ? "已寫入。\n" : "本次為試算，加 --apply 才會實際寫入。\n");
