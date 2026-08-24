<?php
/**
 * 2026-08-24 使用者要求（建議 B）：製程類 5 個標籤合併成 1 個「製程」
 *
 * 合併前：單一製程(19,136筆)／精滾(9,15)／插齒(10,2)／線割(22,7)／製程(44,5)
 *   —「線割」同時是 單一製程 的子標籤又是獨立標籤，使用者不知道該勾哪個。
 * 合併後：製程(44)，子標籤＝粗滾+齒研／精滾／齒研／線割／插齒／倒圓角／槍鑽
 *
 * 每個料號合併成「一列製程 + 多個子標籤」，重複的子標籤自動去重。
 * 用法： php migrate_merge_process_labels.php [--dry]   （可重複執行）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
$DRY = in_array('--dry', $argv, true);
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306","EG-TS2024","excell30367593",
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET NAMES utf8mb4");
$say = fn($s) => print(($DRY ? '[DRY] ' : '[RUN] ') . $s . PHP_EOL);

const TARGET = 44;                    // 製程
const SRC    = [19, 9, 10, 22];       // 單一製程 / 精滾 / 插齒 / 線割
// 沒有子標籤的獨立標籤 → 合併後要變成哪個子標籤名稱
const AS_SUB = [9=>'精滾', 10=>'插齒', 22=>'線割'];

$db->beginTransaction();
try {
    // ── 1. 子標籤搬到「製程」底下 ────────────────────────────────
    $moved = $db->query("SELECT COUNT(*) c FROM dict_label_sub WHERE label_id=19")->fetch()['c'];
    $say("① 把 單一製程 的 {$moved} 個子標籤移到「製程」底下");
    if (!$DRY) $db->exec("UPDATE dict_label_sub SET label_id=".TARGET." WHERE label_id=19");

    // ── 2. 補齊獨立標籤要變成的子標籤 ───────────────────────────
    $subId = [];
    foreach (AS_SUB as $lid => $name) {
        // 也找 label 19（步驟①在 --dry 模式不會真的搬，這樣試算才忠實）
        $st = $db->prepare("SELECT sub_id FROM dict_label_sub WHERE label_id IN (?,19) AND sub_name=? LIMIT 1");
        $st->execute([TARGET, $name]);
        $sid = $st->fetchColumn();
        if (!$sid) {
            $say("② 新增子標籤「{$name}」到「製程」");
            if (!$DRY) {
                $mx = $db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM dict_label_sub WHERE label_id=".TARGET)->fetchColumn();
                $db->prepare("INSERT INTO dict_label_sub (label_id, sub_name, input_type, is_repeatable, sort_order, is_active, no_depth)
                              VALUES (?,?,'none',0,?,1,1)")->execute([TARGET, $name, $mx]);
                $sid = $db->lastInsertId();
            } else { $sid = 'NEW'; }
        }
        $subId[$lid] = $sid;
    }

    // ── 3. 每個料號合併成一列 ───────────────────────────────────
    $inSrc = implode(',', SRC);
    $parts = $db->query("SELECT DISTINCT d_id FROM item_label_map WHERE label_id IN ({$inSrc},".TARGET.")")->fetchAll();
    $mergedParts = 0; $dedup = 0; $newSubRows = 0;
    foreach ($parts as $P) {
        $did  = (int)$P['d_id'];
        $rows = $db->query("SELECT map_id, label_id FROM item_label_map
                            WHERE d_id={$did} AND label_id IN ({$inSrc},".TARGET.") ORDER BY (label_id=".TARGET.") DESC, map_id")->fetchAll();
        if (!$rows) continue;
        $keep = (int)$rows[0]['map_id'];      // 已是「製程」的優先留下，否則留第一列
        $mergedParts++;
        // 這一列目前已有哪些子標籤（去重用）
        $have = [];
        foreach ($db->query("SELECT sub_id FROM item_sub_label_map WHERE parent_map_id={$keep}")->fetchAll() as $h) $have[(int)$h['sub_id']] = true;

        foreach ($rows as $i => $R) {
            $mid = (int)$R['map_id']; $lid = (int)$R['label_id'];
            if ($mid !== $keep) {
                foreach ($db->query("SELECT sub_map_id, sub_id FROM item_sub_label_map WHERE parent_map_id={$mid}")->fetchAll() as $S) {
                    if (isset($have[(int)$S['sub_id']])) {          // 重複的子標籤 → 丟掉
                        $dedup++;
                        if (!$DRY) $db->prepare("DELETE FROM item_sub_label_map WHERE sub_map_id=?")->execute([$S['sub_map_id']]);
                    } else {
                        $have[(int)$S['sub_id']] = true;
                        if (!$DRY) $db->prepare("UPDATE item_sub_label_map SET parent_map_id=? WHERE sub_map_id=?")->execute([$keep, $S['sub_map_id']]);
                    }
                }
            }
            // 沒有子標籤的獨立標籤（精滾/插齒/線割）→ 補一筆子標籤
            if (isset(AS_SUB[$lid])) {
                $sid = $subId[$lid];
                if ($sid !== 'NEW' && !isset($have[(int)$sid])) {
                    $have[(int)$sid] = true; $newSubRows++;
                    if (!$DRY) $db->prepare("INSERT INTO item_sub_label_map (parent_map_id, sub_id, created_at) VALUES (?,?,NOW())")->execute([$keep, $sid]);
                } elseif ($sid !== 'NEW') { $dedup++; }
                else { $newSubRows++; }
            }
            if ($mid !== $keep && !$DRY) $db->prepare("DELETE FROM item_label_map WHERE map_id=?")->execute([$mid]);
        }
        if (!$DRY) $db->prepare("UPDATE item_label_map SET label_id=".TARGET." WHERE map_id=?")->execute([$keep]);
    }
    $say("③ 合併 {$mergedParts} 個料號：補子標籤 {$newSubRows} 筆、重複去除 {$dedup} 筆");

    // ── 4. 收尾 ─────────────────────────────────────────────────
    if (!$DRY) {
        $db->exec("UPDATE dict_label SET is_active=0 WHERE label_id IN ({$inSrc})");
        $db->exec("UPDATE dict_label SET type_code='G,J,N', label_name='製程' WHERE label_id=".TARGET);
    }
    $say("④ 停用 單一製程／精滾／插齒／線割，「製程」適用種類設為 G,J,N");

    if ($DRY) { $db->rollBack(); echo "\n（--dry 模式，未寫入）\n"; }
    else      { $db->commit();  echo "\n完成。\n"; }
} catch (Throwable $e) { $db->rollBack(); fwrite(STDERR,"FAILED: ".$e->getMessage()."\n"); exit(1); }
