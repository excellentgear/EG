<?php
/**
 * 一次性：把舊「審核表單」模板的簽核設定（審核／核准／維護部門／圖章樣式）搬成新「表格模板」的簽核流程，
 * 並把已匯入的 4 張舊單據的簽核紀錄複製過來（讓列印出來有當時的簽章，AS9100 紀錄才完整）。
 * 用法：php 2026-10-14_sheet_form_flow_from_review_form.php        （試算）
 *       php 2026-10-14_sheet_form_flow_from_review_form.php --run  （寫入）
 * 舊資料只讀；可重複執行（模板已有流程的略過、單據已有簽核快照的略過）。
 *
 * 對應：舊 need_review＋review_dept_id(＋auto_review) → 第1關「審核」(dept)；
 *       舊 need_approval：approver_chain 第一個有設定的方式 → 第2關「核准」（dept_or_user→指定人員／部門；auto_supervisor→supervisor；top_approver→top）。
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
chdir(__DIR__ . '/../../../src/common');
require_once 'DBConnection.php';
require_once 'sheet_form_lib.php';
$db = (new DBConnection())->getPDO();
sf_ensure_schema($db);
$run = in_array('--run', $argv, true);

$map = [];   // 舊模板 id => 新模板 row
foreach ($db->query("SELECT * FROM rf_template WHERE id IN (1,2,4,5) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $st = $db->prepare("SELECT * FROM sf_template WHERE name=? AND status='active' ORDER BY id DESC LIMIT 1");
    $st->execute([$o['name']]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) { echo "找不到新模板：{$o['name']}\n"; continue; }

    $flow = [];
    if (!empty($o['need_review']) && !empty($o['review_dept_id']))
        $flow[] = ['label' => '審核', 'mode' => 'dept', 'dept_id' => (int)$o['review_dept_id'], 'auto' => !empty($o['auto_review']) ? 1 : 0];
    if (!empty($o['need_approval'])) {
        $chain = json_decode((string)$o['approver_chain_json'], true) ?: ['top_approver'];
        $step = ['label' => '核准', 'mode' => 'top', 'auto' => !empty($o['auto_approval']) ? 1 : 0];
        foreach ($chain as $m) {
            if ($m === 'dept_or_user' && !empty($o['approver_user_id'])) { $step['mode'] = 'user'; $step['user_id'] = (int)$o['approver_user_id']; break; }
            if ($m === 'dept_or_user' && !empty($o['approver_dept_id'])) { $step['mode'] = 'dept'; $step['dept_id'] = (int)$o['approver_dept_id']; break; }
            if ($m === 'auto_supervisor') { $step['mode'] = 'supervisor'; break; }
            if ($m === 'top_approver') { $step['mode'] = 'top'; break; }
        }
        $flow[] = $step;
    }
    $flow = sf_flow_normalize($db, json_encode($flow));
    $map[(int)$o['id']] = ['old' => $o, 'new' => $t, 'flow' => $flow];
    echo "{$o['name']}（新 #{$t['id']}）：" . ($flow ? implode(' → ', array_map(fn($s) => $s['label'] . '[' . $s['mode'] . ($s['auto'] ? ',自動' : '') . ']', $flow)) : '無簽核')
        . '；維護部門=' . ($o['maintain_dept_id'] ?: '無') . '；圖章樣式=' . ($o['footer_stamp_tpl_id'] ?: '預設') . "\n";
    if ($run && empty($t['flow_json'])) {
        $db->prepare("UPDATE sf_template SET flow_json=?, maintain_dept_id=?, stamp_tpl_id=? WHERE id=?")
           ->execute([$flow ? json_encode($flow, JSON_UNESCAPED_UNICODE) : null, $o['maintain_dept_id'] ?: null, $o['footer_stamp_tpl_id'] ?: null, $t['id']]);
    }
}

/* 已匯入單據：複製簽核紀錄與流程快照 */
$n = 0;
foreach ($db->query("SELECT id, title, template_id FROM sf_instance WHERE title LIKE '%自審核表單匯入 #%' AND deleted_at IS NULL AND (tpl_flow_json IS NULL OR tpl_flow_json='')")->fetchAll(PDO::FETCH_ASSOC) as $i) {
    if (!preg_match('/自審核表單匯入 #(\d+)/u', $i['title'], $m)) continue;
    $oldId = (int)$m[1];
    $oi = $db->query("SELECT * FROM rf_instance WHERE id=" . $oldId)->fetch(PDO::FETCH_ASSOC);
    if (!$oi || !isset($map[(int)$oi['template_id']])) continue;
    $flow = $map[(int)$oi['template_id']]['flow'];
    if (!$flow) continue;
    $recs = $db->query("SELECT * FROM approval_record WHERE module='review_form' AND entity_id=$oldId ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $lv = ['review' => 1, 'approval' => (count($flow) >= 2 ? 2 : 1)];
    // 舊紀錄一個 level 可能有多筆（退回重送），只取每個 level 最後一筆已核准的
    $pick = [];
    foreach ($recs as $r) { if ($r['status'] === 'approved') $pick[$lv[$r['level']] ?? 0] = $r; }
    echo "單據 #{$i['id']}（舊 #{$oldId}）：複製 " . count($pick) . " 筆簽核紀錄\n";
    if (!$run) { $n++; continue; }
    $meta = ['round_from' => 0];
    foreach ($pick as $no => $r) {
        if ($no < 1) continue;
        $db->prepare("INSERT INTO approval_record (module, entity_id, level, status, submitted_by, submitted_by_name, submitted_at, approver_id, approver_name, note, decided_at, created_at)
                      VALUES ('sheet_form', ?, ?, 'approved', ?, ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$i['id'], sf_level($no), $r['submitted_by'], $r['submitted_by_name'], $r['submitted_at'], $r['approver_id'], $r['approver_name'], $r['note'], $r['decided_at']]);
        $aid = (int)$db->lastInsertId();
        if (strpos((string)$r['note'], '自動簽核') !== false) $meta['a' . $aid] = ['auto' => 1];
    }
    $db->prepare("UPDATE sf_instance SET tpl_flow_json=?, sign_meta_json=?, submit_date=?, cur_step=?, status='done' WHERE id=?")
       ->execute([json_encode($flow, JSON_UNESCAPED_UNICODE), json_encode($meta), $oi['submit_date'] ?: $oi['business_date'], count($flow), $i['id']]);
    $n++;
}
echo ($run ? '已處理 ' : '試算（未寫入）將處理 ') . "$n 張單據\n";
