<?php
/**
 * 料號別名遷移工具（2026-07-31 一次性）
 *
 * 做兩件事：
 *   1. d_setting_old_part_links（舊料號，最多3筆、單向）→ d_setting_alias（type=old_part，帶 linked_d_id）
 *      舊表保留不刪，供回溯。
 *   2. 用 Drawing_No 互相指向對方料號的那幾筆（等同料號硬塞在圖面代號欄）→ 轉成別名，
 *      並把 Drawing_No 清空（清空前先備份進 d_setting_drawingno_bak_20260731）。
 *
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe ai-rules\tools\migrate_part_alias.php [--dry]
 */
require __DIR__ . '/../../src/common/DBConnection.php';
require __DIR__ . '/../../src/common/part_alias_lib.php';

$dry = in_array('--dry', $argv, true);
$conn = new DBConnection();
$pdo  = $conn->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

eg_part_alias_ensure_table($pdo);
$log = [];

$ins = $pdo->prepare("INSERT IGNORE INTO d_setting_alias
    (d_id, alias_code, alias_type, customer_id, linked_d_id, note, sort_order, created_by, created_at)
    VALUES (?,?,?,?,?,?,?,NULL,NOW())");

// ── 1. 舊料號連結 ────────────────────────────────────────────────────────────
$rows = $pdo->query("SELECT o.d_id, o.old_d_id, o.sort_order,
                            od.D_Setting_Id AS old_part, od.Customer_Id AS old_cust
                     FROM d_setting_old_part_links o
                     JOIN d_setting od ON od.d_id = o.old_d_id
                     JOIN d_setting d  ON d.d_id  = o.d_id
                     ORDER BY o.d_id, o.sort_order")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $log[] = "舊料號 → 別名：料號 d_id={$r['d_id']} 加入代號「{$r['old_part']}」(客戶 {$r['old_cust']}，連結 d_id={$r['old_d_id']})";
    if (!$dry) $ins->execute([(int)$r['d_id'], $r['old_part'], 'old_part', $r['old_cust'] ?: null,
                              (int)$r['old_d_id'], '由舊料號欄位轉入', (int)$r['sort_order']]);
}

// ── 2. 用 Drawing_No 互指的料號 ──────────────────────────────────────────────
$pairs = $pdo->query("SELECT a.d_id, a.D_Setting_Id AS part, a.Drawing_No AS dw,
                             b.d_id AS other_id, b.Customer_Id AS other_cust, b.D_Setting_Id AS other_part
                      FROM d_setting a
                      JOIN d_setting b ON b.D_Setting_Id = a.Drawing_No AND b.d_id <> a.d_id
                      WHERE a.Drawing_No IS NOT NULL AND a.Drawing_No <> '' AND a.Drawing_No <> a.D_Setting_Id")
             ->fetchAll(PDO::FETCH_ASSOC);
$clearIds = [];
foreach ($pairs as $p) {
    $log[] = "圖面代號互指 → 別名：料號 d_id={$p['d_id']}（{$p['part']}）加入代號「{$p['other_part']}」(客戶 {$p['other_cust']}，連結 d_id={$p['other_id']})，並清空其圖面代號";
    if (!$dry) $ins->execute([(int)$p['d_id'], $p['other_part'], 'old_part', $p['other_cust'] ?: null,
                              (int)$p['other_id'], '由圖面代號互指轉入', 90]);
    $clearIds[(int)$p['d_id']] = true;
}
if (!$dry && $clearIds) {
    $ids = implode(',', array_keys($clearIds));
    // 清空前備份（沿用 07-31 建立的備份表，欄位相同）
    $pdo->exec("INSERT INTO d_setting_drawingno_bak_20260731 (d_id, D_Setting_Id, Drawing_No)
                SELECT d_id, D_Setting_Id, Drawing_No FROM d_setting WHERE d_id IN ($ids)");
    $pdo->exec("UPDATE d_setting SET Drawing_No=NULL WHERE d_id IN ($ids)");
}

echo ($dry ? "[試跑，未寫入]\n" : "[已寫入]\n");
foreach ($log as $l) echo "  - $l\n";
echo "合計 " . count($log) . " 筆\n";
