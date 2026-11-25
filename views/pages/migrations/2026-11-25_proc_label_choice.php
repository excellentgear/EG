<?php
// 一次性：加工限制標籤新增「固定選項」欄位，並建立 銑床的「軸數」(3/3.5/4.5/5 或不填)、「銑床機台總數」(整數)、「可自行收送貨」。
// 可重複執行（同名標籤已存在就不再建）。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'C:/MAMP/htdocs';
require_once __DIR__ . '/../../../src/common/DBConnection.php';
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$has = false;
foreach ($pdo->query("SHOW COLUMNS FROM dict_maker_proc_label")->fetchAll(PDO::FETCH_ASSOC) as $c) if ($c['Field'] === 'choice_values') $has = true;
if (!$has) { $pdo->exec("ALTER TABLE dict_maker_proc_label ADD COLUMN choice_values VARCHAR(200) NULL DEFAULT NULL COMMENT '文字型標籤的固定選項（逗號分隔）；#int＝限填整數'"); echo "已新增 choice_values 欄位\n"; }
$milling = (int)$pdo->query("SELECT proc_label_id FROM dict_maker_proc_label WHERE label_name='銑床' AND is_active=1 LIMIT 1")->fetchColumn();
if (!$milling) { echo "找不到「銑床」標籤，中止\n"; exit(1); }
$defs = [
    ['軸數',         'text', '3,3.5,4.5,5', $milling],
    ['銑床機台總數', 'text', '#int',        null],
    ['可自行收送貨', 'none', null,          null],
];
foreach ($defs as [$name, $it, $cv, $parent]) {
    $q = $pdo->prepare("SELECT proc_label_id FROM dict_maker_proc_label WHERE label_name=? AND is_active=1");
    $q->execute([$name]);
    if ($q->fetchColumn()) { echo "已存在：{$name}\n"; continue; }
    $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM dict_maker_proc_label")->fetchColumn();
    $pdo->prepare("INSERT INTO dict_maker_proc_label (label_name,input_type,numeric_type,unit,sort_order,parent_label_id,is_repeatable,choice_values) VALUES (?,?,?,?,?,?,0,?)")
        ->execute([$name, $it, 'range', 'mm', $max, $parent, $cv]);
    $id = (int)$pdo->lastInsertId();
    if ($parent) $pdo->prepare("INSERT IGNORE INTO dict_maker_proc_label_parent_map (sub_label_id,parent_label_id) VALUES (?,?)")->execute([$id, $parent]);
    echo "已建立：{$name}（id {$id}）\n";
}
