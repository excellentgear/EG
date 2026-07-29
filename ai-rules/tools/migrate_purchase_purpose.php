<?php
// 申請採購「用途歸屬」欄位：schema 遷移（可重跑，先查 information_schema 防重）
// 用法：php migrate_purchase_purpose.php
// 目的：把申請單的「申請事由」純文字，升級為可綁 ID 的用途歸屬（訂單/BOM/料號/常備/設備/其他），
//       讓後續成本分析能正確歸戶——訂單一律綁 order_track.Order_id、料號一律綁 d_setting.d_id
//       （料號字串有 159 個重複、一個訂單號最多對到 25 列，存字串會歸錯成本）。
// 自建連線（不 include _config.php，避免觸發 telegram/personal_task 順路輪詢）
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function idxExists(PDO $db, string $table, string $idx): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $st->execute([$table, $idx]);
    return (bool)$st->fetchColumn();
}
function addCol(PDO $db, array &$done, string $table, string $col, string $ddl): void {
    if (colExists($db, $table, $col)) return;
    $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    $done[] = "$table.$col";
}
function addIdx(PDO $db, array &$done, string $table, string $idx, string $cols): void {
    if (idxExists($db, $table, $idx)) return;
    $db->exec("ALTER TABLE `$table` ADD KEY `$idx` ($cols)");
    $done[] = "$table:$idx";
}

$done = [];

/* ── 單頭：用途歸屬（整張單的主用途）＋急件旗標 ───────────────── */
addCol($db, $done, 'purchase_request', 'purpose_type',
    "VARCHAR(10) NULL COMMENT '用途類別 ORDER/BOM/PART/STOCK/EQUIP/OTHER' AFTER `reason`");
addCol($db, $done, 'purchase_request', 'purpose_order_id',
    "INT NULL COMMENT '訂單列 order_track.Order_id（禁存訂單號字串）' AFTER `purpose_type`");
addCol($db, $done, 'purchase_request', 'purpose_bom',
    "VARCHAR(30) NULL COMMENT '製令 bom.bom（該表主鍵本身就是字串）' AFTER `purpose_order_id`");
addCol($db, $done, 'purchase_request', 'purpose_d_id',
    "INT NULL COMMENT '料號 d_setting.d_id（禁存料號字串）' AFTER `purpose_bom`");
addCol($db, $done, 'purchase_request', 'purpose_note',
    "VARCHAR(200) NULL COMMENT '其他用途的自由說明' AFTER `purpose_d_id`");
addCol($db, $done, 'purchase_request', 'purpose_label',
    "VARCHAR(150) NULL COMMENT '選定當下的顯示快照，列表免 join' AFTER `purpose_note`");
addCol($db, $done, 'purchase_request', 'is_urgent',
    "TINYINT NOT NULL DEFAULT 0 COMMENT '單頭急件（申請人只勾一次，存檔時同步寫進各品項）' AFTER `purpose_label`");
addIdx($db, $done, 'purchase_request', 'idx_pr_purpose_order', '`purpose_order_id`');
addIdx($db, $done, 'purchase_request', 'idx_pr_purpose_bom',   '`purpose_bom`');
addIdx($db, $done, 'purchase_request', 'idx_pr_purpose_d',     '`purpose_d_id`');

/* ── 品項：逐列覆寫（NULL = 沿用單頭用途） ───────────────────── */
addCol($db, $done, 'purchase_request_item', 'purpose_type',
    "VARCHAR(10) NULL COMMENT '逐列覆寫用途；NULL=沿用單頭' AFTER `remark`");
addCol($db, $done, 'purchase_request_item', 'purpose_order_id', "INT NULL AFTER `purpose_type`");
addCol($db, $done, 'purchase_request_item', 'purpose_bom',      "VARCHAR(30) NULL AFTER `purpose_order_id`");
addCol($db, $done, 'purchase_request_item', 'purpose_d_id',     "INT NULL AFTER `purpose_bom`");
addCol($db, $done, 'purchase_request_item', 'purpose_note',     "VARCHAR(200) NULL AFTER `purpose_d_id`");
addCol($db, $done, 'purchase_request_item', 'purpose_label',    "VARCHAR(150) NULL AFTER `purpose_note`");
addIdx($db, $done, 'purchase_request_item', 'idx_pri_purpose_order', '`purpose_order_id`');
addIdx($db, $done, 'purchase_request_item', 'idx_pri_purpose_bom',   '`purpose_bom`');
addIdx($db, $done, 'purchase_request_item', 'idx_pri_purpose_d',     '`purpose_d_id`');

echo $done ? ("已新增：\n  " . implode("\n  ", $done) . "\n") : "無異動（欄位皆已存在）\n";
