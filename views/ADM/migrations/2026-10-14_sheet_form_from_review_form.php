<?php
/**
 * 一次性：把舊「審核表單」(review_form) 已核准的單據，複製一份進新「表格模板」(sheet_form)。
 * 用法：php 2026-10-14_sheet_form_from_review_form.php        （預設試算，不寫入）
 *       php 2026-10-14_sheet_form_from_review_form.php --run  （真的寫入）
 *
 * 前提：新模板已建好，且名稱與舊模板完全相同（本次建立：仿冒零件防制審核表／產品安全審核表／防護審核表／環境審查表）。
 * 欄位對應：舊 schema 的 label ↔ 新模板待填欄位的 label；項目＝子項內容；負責單位／負責人＝依 id 轉成名稱以「、」串接。
 * 舊資料、舊模板、舊簽核紀錄完全不動（只讀）；新單據一律標「已完成」，標題尾端帶「自審核表單匯入 #舊單id」當重複匯入的判斷依據。
 * 可重複執行（已匯入過的會略過）。簽核歷程（誰何時簽）不搬——新模組第一版尚無簽核。
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
chdir(__DIR__ . '/../../../src/common');
require_once 'DBConnection.php';
require_once 'sheet_form_lib.php';
$db = (new DBConnection())->getPDO();
sf_ensure_schema($db);
$run = in_array('--run', $argv, true);

$names = function (string $table, string $col, string $nameCol, string $csv) use ($db): string {
    $ids = array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));
    if (!$ids) return '';
    $out = [];
    $st = $db->prepare("SELECT $nameCol FROM $table WHERE $col=?");
    foreach ($ids as $id) { $st->execute([$id]); $n = $st->fetchColumn(); if ($n !== false && $n !== null) $out[] = $n; }
    return implode('、', $out);
};

$new = [];   // 舊模板 id => 新模板
foreach ($db->query("SELECT id, name FROM rf_template WHERE id IN (1,2,4,5)")->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $st = $db->prepare("SELECT * FROM sf_template WHERE name=? AND status='active' ORDER BY id DESC LIMIT 1");
    $st->execute([$o['name']]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) { echo "找不到新模板：{$o['name']}\n"; continue; }
    $fields = json_decode((string)$t['fields_json'], true) ?: [];
    $byLabel = [];
    foreach ($fields as $f) $byLabel[$f['label']] = $f;
    $new[(int)$o['id']] = ['tpl' => $t, 'by' => $byLabel, 'fields' => $fields];
}

$total = 0; $skipped = 0; $dropped = 0;
foreach ($db->query("SELECT * FROM rf_instance WHERE template_id IN (1,2,4,5) AND status='approved' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $i) {
    $m = $new[(int)$i['template_id']] ?? null;
    if (!$m) continue;
    $tag = '自審核表單匯入 #' . $i['id'];
    $ex = $db->prepare("SELECT id FROM sf_instance WHERE title LIKE ? AND deleted_at IS NULL");
    $ex->execute(['%' . $tag . '%']);
    if ($ex->fetchColumn()) { $skipped++; echo "略過（已匯入）：舊單 #{$i['id']}\n"; continue; }

    $oldSchema = json_decode((string)$db->query("SELECT current_schema_json FROM rf_template WHERE id=" . (int)$i['template_id'])->fetchColumn(), true);
    $keyLabel = [];
    foreach ($oldSchema['fields'] ?? [] as $of) $keyLabel[$of['key']] = $of['label'];

    $rows = [];
    $its = $db->prepare("SELECT * FROM rf_instance_item WHERE instance_id=? ORDER BY sort_order, id");
    $its->execute([$i['id']]);
    foreach ($its->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $ss = $db->prepare("SELECT * FROM rf_instance_subitem WHERE item_id=? ORDER BY sort_order, id");
        $ss->execute([$it['id']]);
        foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $row = ['項目' => (string)$s['content'], '負責單位' => $names('department', 'id', 'name', (string)$s['owner_depts']),
                    '負責人' => $names('user', 'id', 'user_cname', (string)$s['owner_users'])];
            foreach ((json_decode((string)$s['data_json'], true) ?: []) as $k => $v) { if (isset($keyLabel[$k])) $row[$keyLabel[$k]] = is_scalar($v) ? (string)$v : ''; }
            $rows[] = $row;
        }
    }
    if (!$rows) { echo "舊單 #{$i['id']} 沒有項目，略過\n"; continue; }

    $year = trim((string)$i['year_heading']) !== '' ? trim((string)$i['year_heading']) : substr((string)$i['business_date'], 0, 4);
    $values = [];
    foreach ($m['fields'] as $f) {
        if ($f['g'] !== '') { $values[$f['id']] = []; }
        elseif ($f['label'] === '年度') { [$nv] = sf_norm_value($f, $year); $values[$f['id']] = $nv; }
    }
    foreach ($rows as $row) {
        foreach ($m['fields'] as $f) {
            if ($f['g'] === '') continue;
            [$nv, $er] = sf_norm_value($f, $row[$f['label']] ?? '');
            if ($er) { $dropped++; echo "  ※ 舊單 #{$i['id']} 「{$f['label']}」值「" . ($row[$f['label']] ?? '') . "」$er，已略過該值\n"; $nv = ''; }
            $values[$f['id']][] = $nv;
        }
    }
    $uid = (int)($db->query("SELECT id FROM user WHERE user_cname=" . $db->quote((string)$i['created_by_name']) . " LIMIT 1")->fetchColumn() ?: 1);
    $title = $year . '年度 ' . $m['tpl']['name'] . '（' . $tag . '）';
    echo "舊單 #{$i['id']} → {$title}：{$m['tpl']['name']}，" . count($rows) . " 列，建立人 uid=$uid\n";
    if ($run) {
        $db->prepare("INSERT INTO sf_instance (template_id, tpl_version, title, biz_date, status, tpl_book_json, tpl_fields_json, values_json, extra_rows,
                      created_by, created_at, modified_by, modified_at, submitted_at) VALUES (?,?,?,?, 'done', ?,?,?,?, ?,?,?,NOW(),?)")
           ->execute([$m['tpl']['id'], $m['tpl']['version'], $title, $i['business_date'], $m['tpl']['book_json'], $m['tpl']['fields_json'],
                      json_encode($values, JSON_UNESCAPED_UNICODE), count($rows) - 1, $uid, $i['created_at'], $uid, $i['submitted_at'] ?: ($i['submit_date'] . ' 00:00:00')]);
    }
    $total++;
}
echo ($run ? '已匯入 ' : '試算（未寫入）將匯入 ') . "$total 張，略過 $skipped 張，捨棄不合法值 $dropped 個\n";
