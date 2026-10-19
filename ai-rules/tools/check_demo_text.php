<?php
/**
 * AI／自動產生資料（草稿、【假資料】）的內容健檢 —— 規則見 ai-rules/27-AI假資料與草稿產生規範.md
 * 執行：php ai-rules/tools/check_demo_text.php        （有違規時結束碼 1；每項都要是「（無）」才算過）
 * 檢查對象＝溝通記錄表（comm_record／item）裡 is_draft=1 或備註【假資料】開頭的資料。
 */
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4", "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = $db->query("SELECT r.rec_id, r.rec_no, r.comm_date, r.party_kind, r.party_name, r.ch_meeting, r.ch_face, r.ch_phone, r.ch_email, r.ch_other, i.item_id, i.question, i.reply, i.track_id,
                           t.owner_name, t.due_date
                    FROM comm_record r JOIN comm_record_item i ON i.rec_id=r.rec_id LEFT JOIN comm_track t ON t.track_id=i.track_id
                    WHERE r.is_deleted=0 AND (r.is_draft=1 OR r.remark LIKE '【假資料】%') ORDER BY r.rec_id, i.seq")->fetchAll(PDO::FETCH_ASSOC);
$bad = ['dup' => [], 'claim' => [], 'channel' => [], 'party' => [], 'track' => [], 'future' => []];
$today = date('Y-m-d');
$seen = [];
foreach ($rows as $r) {
    $q = trim((string)$r['question']); $a = trim((string)$r['reply']); $k = $r['rec_no'] . '#' . $r['item_id'];
    // ① 溝通問題與回覆內容不可相同或互相包含（回覆要寫決議，不是把問題抄一遍）
    if ($q !== '' && $a !== '' && ($q === $a || (mb_strlen($q) >= 12 && mb_strpos($a, $q) !== false) || (mb_strlen($a) >= 12 && mb_strpos($q, $a) !== false))) $bad['dup'][] = $k;
    // 同一張單的溝通問題不可逐項完全相同
    if (isset($seen[$r['rec_id'] . '|' . $q])) $bad['dup'][] = $k . '（同單問題重複）'; $seen[$r['rec_id'] . '|' . $q] = 1;
    // ② 回覆不可寫成對方已經回應／同意／確認（系統無從得知，除非標明待補記）
    if (preg_match('/(客戶|對方|廠商|供應商)[^，。；]{0,6}(表示|預計|同意|確認|滿意|回覆說|答應)/u', $a) && mb_strpos($a, '待') === false) $bad['claim'][] = $k . '：' . mb_substr($a, 0, 30);
    // ③ 管道：勾「會議」要有會議紀錄佐證，只有人數眾多（4 個部門以上）才可；客戶定期溝通以面談或電話為主
    if ((int)$r['ch_meeting'] === 1) {
        $depts = substr_count((string)$r['party_name'], '、') + 1;
        if ($r['party_kind'] === 'customer' || $depts < 4) $bad['channel'][] = $k . '：' . $r['party_name'];
    }
    if (!(int)$r['ch_meeting'] + (int)$r['ch_face'] + (int)$r['ch_phone'] + (int)$r['ch_email'] + (int)$r['ch_other'] === 0) { }
    // ④ 對象：員工類要有部門（＋人員），不可寫成「議題名稱　因應措施追蹤」這類標題
    if ($r['party_kind'] === 'employee' && (!preg_match('/(課|組|室|部)/u', (string)$r['party_name']) || mb_strpos((string)$r['party_name'], '因應措施追蹤') !== false)) $bad['party'][] = $k . '：' . $r['party_name'];
    // ⑤ 有轉追蹤的項目一定要有負責人與預計完成日
    if ((int)$r['track_id'] > 0 && ($r['owner_name'] === null || $r['owner_name'] === '' || empty($r['due_date']))) $bad['track'][] = $k;
    // ⑥ 溝通日期不可晚於今天
    if ($r['comm_date'] > $today) $bad['future'][] = $k . '：' . $r['comm_date'];
}
$names = ['dup' => '溝通問題與回覆內容重複', 'claim' => '回覆寫成對方已回應／同意', 'channel' => '管道誤勾會議（客戶或不足 4 個部門）', 'party' => '員工類對象沒寫部門＋人員', 'track' => '追蹤缺負責人或完成日', 'future' => '溝通日期晚於今天'];
$fail = 0;
foreach ($names as $k => $t) {
    echo "[$t]\n";
    if (!$bad[$k]) echo "  （無）\n"; else { $fail++; foreach (array_slice($bad[$k], 0, 15) as $x) echo "  $x\n"; if (count($bad[$k]) > 15) echo "  …共 " . count($bad[$k]) . " 筆\n"; }
}
echo "\n檢查 " . count($rows) . " 列，" . ($fail ? "有 $fail 類違規" : '全部通過') . "\n";
exit($fail ? 1 : 0);
