<?php
/**
 * 審核表單（review_form）：新增「橫式標題下方的可填入列」所需的欄位 — 2026-09-09
 *
 * 背景：模板可以選擇在欄位標題那一列的下方多加一列可填欄（紙本 2-GM-01-01 組織處境分析表就長這樣），
 * 這一列的值是「整張表單一欄一個」而不是逐列的，所以不能塞進 rf_instance_subitem，
 * 改在 rf_instance 上加一個 JSON 欄位存 {欄位key: 值}。
 *
 * 「直式標題右側的可填入欄」不需要 migration —— 那個是逐列的值，直接存在該列第一個小項的
 * data_json 保留鍵 __rowside 裡（見 review_form_lib.php RVF_ROWSIDE_KEY）。
 *
 * 用法（可重複執行，已經有欄位就跳過）：
 *   php views/ADM/migrations/2026-09-09_rvf_head_row.php          （試算，不寫入）
 *   php views/ADM/migrations/2026-09-09_rvf_head_row.php --run    （實際執行）
 */
require_once __DIR__ . '/../../../src/common/DBConnection.php';

$run = in_array('--run', $argv, true);
$db = (new DBConnection())->getPDO();

$st = $db->query("SHOW COLUMNS FROM rf_instance LIKE 'head_data_json'");
$exists = (bool)$st->fetch(PDO::FETCH_ASSOC);

if ($exists) {
    echo "rf_instance.head_data_json 已存在，不需要處理。\n";
    exit(0);
}
echo "rf_instance 缺少 head_data_json 欄位" . ($run ? "，開始新增…\n" : "（加上 --run 才會實際新增）\n");
if (!$run) exit(0);

$db->exec("ALTER TABLE rf_instance ADD COLUMN head_data_json TEXT NULL COMMENT '橫式標題下方那一列的值（{欄位key:值}），模板未啟用時為 NULL' AFTER year_heading");
echo "完成：已新增 rf_instance.head_data_json。\n";
