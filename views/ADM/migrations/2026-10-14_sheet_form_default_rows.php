<?php
/**
 * 一次性：把舊審核表單每個模板「最近一張已核准單據」的項目（項目／負責單位／負責人），
 * 設成新「表格模板」的預設明細內容，建立新單據時自動帶入（等於舊流程「沿用上次的標準項目」）。
 * 用法：php 2026-10-14_sheet_form_default_rows.php        （試算）
 *       php 2026-10-14_sheet_form_default_rows.php --run  （寫入）
 * 只動 sf_template.defaults_json；舊資料只讀。已有預設內容的模板略過（不覆蓋人工設定）。
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
chdir(__DIR__ . '/../../../src/common');
require_once 'DBConnection.php';
require_once 'sheet_form_lib.php';
$db = (new DBConnection())->getPDO();
sf_ensure_schema($db);
$run = in_array('--run', $argv, true);

$names = function (string $table, string $col, string $nameCol, string $csv) use ($db): string {
    $out = [];
    $st = $db->prepare("SELECT $nameCol FROM $table WHERE $col=?");
    foreach (array_filter(array_map('trim', explode(',', $csv)), 'strlen') as $id) { $st->execute([$id]); $n = $st->fetchColumn(); if ($n) $out[] = $n; }
    return implode('、', $out);
};

foreach ($db->query("SELECT id, name FROM rf_template WHERE id IN (1,2,4,5) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $st = $db->prepare("SELECT * FROM sf_template WHERE name=? AND status='active' ORDER BY id DESC LIMIT 1");
    $st->execute([$o['name']]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) { echo "找不到新模板：{$o['name']}\n"; continue; }
    if (!empty($t['defaults_json'])) { echo "略過（已有預設內容）：{$o['name']}\n"; continue; }
    $fields = json_decode((string)$t['fields_json'], true) ?: [];
    $byLabel = [];
    foreach ($fields as $f) $byLabel[$f['label']] = $f;
    $inst = $db->prepare("SELECT id FROM rf_instance WHERE template_id=? AND status='approved' ORDER BY business_date DESC, id DESC LIMIT 1");
    $inst->execute([$o['id']]);
    $iid = (int)$inst->fetchColumn();
    if (!$iid) { echo "舊模板沒有已核准單據：{$o['name']}\n"; continue; }
    $rows = [];
    $its = $db->prepare("SELECT id FROM rf_instance_item WHERE instance_id=? ORDER BY sort_order, id");
    $its->execute([$iid]);
    foreach ($its->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
        $ss = $db->prepare("SELECT * FROM rf_instance_subitem WHERE item_id=? ORDER BY sort_order, id");
        $ss->execute([$itemId]);
        foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $rows[] = ['項目' => (string)$s['content'], '負責單位' => $names('department', 'id', 'name', (string)$s['owner_depts']), '負責人' => $names('user', 'id', 'user_cname', (string)$s['owner_users'])];
        }
    }
    $values = [];
    foreach ($fields as $f) {
        if ($f['g'] === '') continue;
        $values[$f['id']] = array_map(fn($r) => $r[$f['label']] ?? '', $rows);
    }
    $def = sf_defaults_normalize($fields, ['rows' => count($rows), 'values' => $values]);
    echo "{$o['name']}（新模板 #{$t['id']}）← 舊單 #{$iid}，" . count($rows) . " 個標準項目\n";
    if ($run && $def) $db->prepare("UPDATE sf_template SET defaults_json=? WHERE id=?")->execute([json_encode($def, JSON_UNESCAPED_UNICODE), $t['id']]);
}
echo $run ? "已寫入\n" : "試算完成（未寫入），加 --run 才會寫入\n";
