<?php
/**
 * 2026-10-02 料號標籤：把「軸件›總長」與「片狀›總厚」合併成單一數字標籤「工件總長」
 *
 * 使用者交辦：主檔管理（views/pages/master_data_management.php）新增/編輯料號的標籤區，
 * 「軸件」與「片狀」合併成一個，「總長」與「總厚」改叫「工件總長」，既有資料一併合併；
 * 並拍板**直接做成一個數字標籤、不要子標籤**（結構由兩層降為一層）。
 *
 * 動工前查證（都是用真實資料數出來的，決定了下面的做法）：
 *   ⑴ 軸件(label 1) 73 個料號、片狀(label 2) 186 個，**沒有任何一個料號同時掛兩個**
 *      → label_id 2→1 不會撞到 is_repeatable=0 的唯一性，不必處理衝突；
 *   ⑵ 這兩個父標籤的 item_label_map 列**一個值都沒有**（input_type 本來是 none），
 *      值全部在子標籤（總長 67 筆、總厚 185 筆，且只用到 input_value，
 *      draw_dim/value_min/tol/qty 全空）→ 子標籤的值可以直接搬進父列的 input_value；
 *   ⑶ 子標籤底下沒有任何孫標籤選項（dict_label_sub_option 0 筆）、沒有孤兒子列、
 *      也沒有同一列掛兩個子標籤的情形。
 *
 * 連帶處理（不處理的話會留下「看起來還在、其實永遠跑不到」的設定）：
 *   ⑷ kpi_weight_calc_rule 有兩條規則，一條靠「軸件」命中、一條靠「片狀」命中，
 *      長度分別取 總長／總厚。合併後兩條的條件會變成同一個，依使用者拍板**合併成一條**：
 *      保留 rule 1（直徑來源順序＝齒輪外徑 da → 最大外徑 → 外徑），長度改取合併後的標籤本身，
 *      rule 2 停用。兩條原本只差在直徑來源的先後順序。
 *   ⑸ weight_calc_binding 的 length/thickness 兩列也指著舊的 label/sub，一併收斂
 *      （**全站沒有任何程式讀這張表**，純粹是不要留下指向停用子標籤的死設定）。
 *
 * 用法：
 *   php views/pages/migrations/2026-10-02_merge_workpiece_length.php          ← 只試算，不寫入
 *   php views/pages/migrations/2026-10-02_merge_workpiece_length.php --run    ← 實際執行
 *   php views/pages/migrations/2026-10-02_merge_workpiece_length.php --verify ← 只檢查結果
 * 可重複執行（已經合併過會自己跳過）。
 */

require_once __DIR__ . '/../../../src/common/DBConnection.php';

const KEEP_LABEL  = 1;    // 軸件 → 改名成「工件總長」，資料都歸到這個 id
const DROP_LABEL  = 2;    // 片狀 → 停用
const SUB_LEN     = 8;    // 軸件›總長
const SUB_THK     = 19;   // 片狀›總厚
const NEW_NAME    = '工件總長';

$run    = in_array('--run', $argv, true);
$verify = in_array('--verify', $argv, true);
$pdo    = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function say(string $s = ''): void { echo $s . "\n"; }

// ─────────────────────────────────────────────────────────────
function verifyState(PDO $pdo): void {
    $q = fn(string $sql) => $pdo->query($sql)->fetchColumn();
    say('── 目前狀態 ──────────────────────────────');
    foreach ($pdo->query("SELECT label_id,label_name,input_type,is_active FROM dict_label WHERE label_id IN (" . KEEP_LABEL . "," . DROP_LABEL . ")") as $r) {
        say(sprintf('  dict_label %d：%s（input_type=%s, is_active=%s）', $r['label_id'], $r['label_name'], $r['input_type'], $r['is_active']));
    }
    foreach ($pdo->query("SELECT sub_id,label_id,sub_name,is_active FROM dict_label_sub WHERE sub_id IN (" . SUB_LEN . "," . SUB_THK . ")") as $r) {
        say(sprintf('  dict_label_sub %d：%s（label_id=%d, is_active=%s）', $r['sub_id'], $r['sub_name'], $r['label_id'], $r['is_active']));
    }
    say(sprintf('  料號掛「保留標籤 %d」：%s 筆，其中有填數值 %s 筆',
        KEEP_LABEL,
        $q("SELECT COUNT(*) FROM item_label_map WHERE label_id=" . KEEP_LABEL),
        $q("SELECT COUNT(*) FROM item_label_map WHERE label_id=" . KEEP_LABEL . " AND input_value IS NOT NULL AND input_value<>''")));
    say(sprintf('  料號仍掛「停用標籤 %d」：%s 筆（應為 0）', DROP_LABEL, $q("SELECT COUNT(*) FROM item_label_map WHERE label_id=" . DROP_LABEL)));
    say(sprintf('  子標籤殘留（總長/總厚）：%s 筆（應為 0）', $q("SELECT COUNT(*) FROM item_sub_label_map WHERE sub_id IN (" . SUB_LEN . "," . SUB_THK . ")")));
    foreach ($pdo->query("SELECT rule_id,rule_name,cond_label_ids,l_label_id,l_sub_id,is_active FROM kpi_weight_calc_rule ORDER BY sort_order,rule_id") as $r) {
        say(sprintf('  重量規則 %d：%s cond=%s 長度=(label %s, sub %s) active=%s',
            $r['rule_id'], $r['rule_name'], $r['cond_label_ids'], $r['l_label_id'] ?? 'null', $r['l_sub_id'] ?? 'null', $r['is_active']));
    }
}

if ($verify) { verifyState($pdo); exit(0); }

// ── 事前盤點 ─────────────────────────────────────────────────
$already = $pdo->query("SELECT label_name FROM dict_label WHERE label_id=" . KEEP_LABEL)->fetchColumn() === NEW_NAME;
if ($already) {
    say('⚠ 看起來已經合併過了（dict_label ' . KEEP_LABEL . ' 已叫「' . NEW_NAME . '」），以下只做殘留檢查。');
}

$rows = $pdo->query(
    "SELECT m.map_id, m.d_id, m.label_id, m.input_value AS parent_val,
            s.sub_map_id, s.sub_id, s.input_value AS sub_val
       FROM item_label_map m
       LEFT JOIN item_sub_label_map s ON s.parent_map_id = m.map_id AND s.sub_id IN (" . SUB_LEN . "," . SUB_THK . ")
      WHERE m.label_id IN (" . KEEP_LABEL . "," . DROP_LABEL . ")
      ORDER BY m.d_id"
)->fetchAll(PDO::FETCH_ASSOC);

$both = $pdo->query(
    "SELECT COUNT(*) FROM (SELECT d_id FROM item_label_map WHERE label_id IN (" . KEEP_LABEL . "," . DROP_LABEL . ")
      GROUP BY d_id HAVING COUNT(DISTINCT label_id) > 1) t"
)->fetchColumn();

$nMove = 0; $nRelabel = 0; $nNoVal = 0; $nParentHadVal = 0;
foreach ($rows as $r) {
    if ($r['label_id'] == DROP_LABEL) $nRelabel++;
    if ($r['sub_map_id'] !== null && trim((string)$r['sub_val']) !== '') $nMove++;
    else $nNoVal++;
    if (trim((string)$r['parent_val']) !== '') $nParentHadVal++;
}

say('── 試算 ──────────────────────────────────');
say("  受影響的料號標籤列：" . count($rows) . ' 筆');
say("  其中要把 label_id {$rows[0]['label_id']}… 由 " . DROP_LABEL . ' 改成 ' . KEEP_LABEL . " 的：{$nRelabel} 筆");
say("  有數值要從子標籤搬進標籤本身的：{$nMove} 筆");
say("  子標籤沒有數值（標籤勾了但沒填）：{$nNoVal} 筆");
say("  父列原本就有值（理應為 0，不是 0 要先查清楚）：{$nParentHadVal} 筆");
say("  同時掛著兩個標籤的料號（會撞唯一性，必須為 0）：{$both} 筆");

if ($both > 0) { say('✗ 有料號同時掛著兩個標籤，停止。請先人工處理再執行。'); exit(1); }
if ($nParentHadVal > 0) { say('✗ 父標籤列本來就有值，停止（會被覆蓋）。請先確認那幾筆要保留哪一個值。'); exit(1); }

if (!$run) { say(''); say('（這是試算，沒有寫入任何資料。要實際執行請加 --run）'); verifyState($pdo); exit(0); }

// ── 實際執行 ─────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    // ① 值：子標籤 → 標籤本身
    $upd = $pdo->prepare("UPDATE item_label_map SET input_value = ? WHERE map_id = ?");
    $del = $pdo->prepare("DELETE FROM item_sub_label_map WHERE sub_map_id = ?");
    $moved = 0;
    foreach ($rows as $r) {
        if ($r['sub_map_id'] === null) continue;
        $v = trim((string)$r['sub_val']);
        if ($v !== '') { $upd->execute([$v, $r['map_id']]); $moved++; }
        $del->execute([$r['sub_map_id']]);
    }
    say("① 搬了 {$moved} 筆數值到標籤本身，並清掉對應的子標籤列");

    // ② 料號上的「片狀」改掛合併後的標籤
    $st = $pdo->prepare("UPDATE item_label_map SET label_id = ? WHERE label_id = ?");
    $st->execute([KEEP_LABEL, DROP_LABEL]);
    say("② 把 {$st->rowCount()} 筆料號的標籤由「片狀」改掛合併後的標籤");

    // ③ 字典：保留的那個改名成數字標籤、另一個停用、兩個子標籤停用
    $pdo->prepare("UPDATE dict_label SET label_name = ?, input_type = 'number', is_repeatable = 0,
                          has_draw_lathe = 0, is_range = 0, has_tolerance = 0, is_dimension = 0,
                          is_qty_dim = 0, is_triple_dim = 0, has_draw_lathe_depth = 0
                    WHERE label_id = ?")->execute([NEW_NAME, KEEP_LABEL]);
    $pdo->prepare("UPDATE dict_label SET is_active = 0 WHERE label_id = ?")->execute([DROP_LABEL]);
    $pdo->prepare("UPDATE dict_label_sub SET is_active = 0 WHERE sub_id IN (?, ?)")->execute([SUB_LEN, SUB_THK]);
    say("③ 字典：label " . KEEP_LABEL . " 改名「" . NEW_NAME . "」且改成數字輸入；label " . DROP_LABEL . " 與兩個子標籤停用");

    // ④ 重量計算：兩條規則合併成一條（使用者拍板）
    $pdo->prepare("UPDATE kpi_weight_calc_rule SET rule_name = ?, l_label_id = ?, l_sub_id = NULL WHERE rule_id = 1")
        ->execute([NEW_NAME, KEEP_LABEL]);
    $pdo->prepare("UPDATE kpi_weight_calc_rule SET is_active = 0 WHERE rule_id = 2")->execute();
    say("④ 重量計算：規則 1 的長度改取合併後的標籤本身（不再有子標籤），規則 2 停用");

    // ⑤ weight_calc_binding（全站沒有程式讀它，只是不要留下指向停用子標籤的死設定）
    $pdo->prepare("UPDATE weight_calc_binding SET label_id = ?, sub_id = NULL WHERE binding_role = 'length'")
        ->execute([KEEP_LABEL]);
    $pdo->prepare("DELETE FROM weight_calc_binding WHERE binding_role = 'thickness'")->execute();
    say("⑤ weight_calc_binding：length 改指合併後的標籤、thickness 移除");

    $pdo->commit();
    say('');
    say('✓ 完成');
} catch (Throwable $e) {
    $pdo->rollBack();
    say('✗ 失敗並已全部回滾：' . $e->getMessage());
    exit(1);
}

verifyState($pdo);
