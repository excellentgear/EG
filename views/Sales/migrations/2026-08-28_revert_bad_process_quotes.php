<?php
/**
 * 2026-08-28_revert_bad_process_quotes.php
 *
 * 把「快速轉移頁設定過製程、但 process_notes 沒寫入」的報價單全部退回未轉正，並清掉那些
 * 對不起來的製程資料，強迫在快速轉移頁重新設定一次（修好之後設定的才會存對）。
 *
 * 為什麼要連製程資料一起清：轉正閘門與卡片徽章是看 quotation_item_process_map 有沒有列，
 * 只把 pending_review 翻回 1 而留著那些列的話，畫面會顯示「製程已設定」、閘門也會放行，
 * 等於原封不動再轉一次同樣的壞資料。
 *
 * 為什麼不自動補回 process_notes：process_no → 子標籤是多對多（例 202 同時屬於 8 個子標籤），
 * 當初點的是哪一個從來沒被存下來，猜錯就是把錯的製程寫進正式報價單。使用者指示一律退回重設。
 *
 * 刪掉的列會先備份進 quotation_process_revert_backup，--rollback 可完整還原。
 *
 * 用法（可重複執行）：
 *   php 2026-08-28_revert_bad_process_quotes.php            試算，不改資料
 *   php 2026-08-28_revert_bad_process_quotes.php --run      實際執行
 *   php 2026-08-28_revert_bad_process_quotes.php --verify   檢查結果
 *   php 2026-08-28_revert_bad_process_quotes.php --rollback 還原上一次執行
 */

require_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo  = (new DBConnection())->getPDO();
$args = array_slice($argv, 1);
$mode = in_array('--run', $args, true) ? 'run'
      : (in_array('--verify', $args, true) ? 'verify'
      : (in_array('--rollback', $args, true) ? 'rollback' : 'dry'));

function out(string $m): void { echo $m . PHP_EOL; }

$pdo->exec("
    CREATE TABLE IF NOT EXISTS quotation_process_revert_backup (
        bk_id INT AUTO_INCREMENT PRIMARY KEY,
        batch_tag VARCHAR(30) NOT NULL,
        quote_id INT NOT NULL,
        item_id INT NOT NULL,
        process_no INT NULL,
        process_group_type VARCHAR(30) NULL,
        was_pending_review TINYINT(1) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX (batch_tag), INDEX (item_id), INDEX (quote_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    COMMENT='2026-08-28 退回製程對不起來的報價單前的備份，可 --rollback 還原'
");

// ── 受影響範圍：有製程對應列、但 process_notes 沒寫（＝快速轉移頁舊版寫進去的）──
$SQL_ITEMS = "
    SELECT qi.item_id, qi.quote_id, qi.process_group_type, ql.quote_no, ql.pending_review
    FROM quotation_item qi
    JOIN quotation_list ql ON ql.quote_id = qi.quote_id
    WHERE (qi.process_notes IS NULL OR qi.process_notes = '')
      AND EXISTS (SELECT 1 FROM quotation_item_process_map m WHERE m.quotation_item_id = qi.item_id)
    ORDER BY ql.quote_no, qi.item_id
";

if ($mode === 'rollback') {
    $tag = $pdo->query("SELECT batch_tag FROM quotation_process_revert_backup ORDER BY bk_id DESC LIMIT 1")->fetchColumn();
    if (!$tag) { out('備份表裡沒有任何批次，無可還原。'); exit(0); }
    $rows = $pdo->prepare("SELECT * FROM quotation_process_revert_backup WHERE batch_tag=? ORDER BY bk_id");
    $rows->execute([$tag]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    out("還原批次 {$tag}（{$rows[0]['created_at']}），共 " . count($rows) . ' 列備份。');

    $pdo->beginTransaction();
    try {
        $insMap  = $pdo->prepare("INSERT IGNORE INTO quotation_item_process_map (quotation_item_id, process_no) VALUES (?,?)");
        $updItem = $pdo->prepare("UPDATE quotation_item SET process_group_type=? WHERE item_id=?");
        $updQ    = $pdo->prepare("UPDATE quotation_list SET pending_review=? WHERE quote_id=?");
        $doneQ = [];
        foreach ($rows as $r) {
            if ($r['process_no'] !== null) $insMap->execute([$r['item_id'], $r['process_no']]);
            if ($r['process_group_type'] !== null) $updItem->execute([$r['process_group_type'], $r['item_id']]);
            if (!isset($doneQ[$r['quote_id']])) { $updQ->execute([$r['was_pending_review'], $r['quote_id']]); $doneQ[$r['quote_id']] = 1; }
        }
        $pdo->prepare("DELETE FROM quotation_process_revert_backup WHERE batch_tag=?")->execute([$tag]);
        $pdo->commit();
        out('已還原：報價單 ' . count($doneQ) . ' 張、製程對應列 ' . count(array_filter($rows, fn($r) => $r['process_no'] !== null)) . ' 列。');
    } catch (Exception $e) { $pdo->rollBack(); out('還原失敗：' . $e->getMessage()); exit(1); }
    exit(0);
}

if ($mode === 'verify') {
    $left = $pdo->query("SELECT COUNT(*) FROM ($SQL_ITEMS) t")->fetchColumn();
    $bk   = $pdo->query("SELECT COUNT(*) FROM quotation_process_revert_backup")->fetchColumn();
    $badFormal = $pdo->query("SELECT COUNT(DISTINCT quote_id) FROM ($SQL_ITEMS) t WHERE pending_review=0")->fetchColumn();
    out("尚未處理的項目：{$left} 筆（其中屬於已轉正報價單的：{$badFormal} 張）");
    out("備份表列數：{$bk}");
    out($left == 0 ? '結果：已全部處理完畢。' : '結果：仍有未處理項目，請執行 --run。');
    exit(0);
}

$items = $pdo->query($SQL_ITEMS)->fetchAll(PDO::FETCH_ASSOC);
if (!$items) { out('沒有需要處理的項目。'); exit(0); }

$quotes = [];
foreach ($items as $it) {
    $quotes[$it['quote_id']] = ['quote_no' => $it['quote_no'], 'pending' => (int)$it['pending_review']];
}
$toRevert = array_filter($quotes, fn($q) => $q['pending'] === 0);

$mapCount = (int)$pdo->query("
    SELECT COUNT(*) FROM quotation_item_process_map m
    WHERE m.quotation_item_id IN (SELECT item_id FROM ($SQL_ITEMS) t)
")->fetchColumn();

out('── 受影響範圍 ──');
out('報價單：' . count($quotes) . ' 張（其中 ' . count($toRevert) . ' 張目前是已轉正，會被退回未轉正）');
out('項目：' . count($items) . ' 筆');
out('要清掉並備份的製程對應列：' . $mapCount . ' 列');
out('');

if ($mode === 'dry') {
    $i = 0;
    out('前 15 張報價單：');
    foreach ($quotes as $qid => $q) {
        out(sprintf('  %s  quote_id=%d  %s', $q['quote_no'], $qid, $q['pending'] ? '（本來就未轉正，只清製程）' : '（已轉正 → 退回）'));
        if (++$i >= 15) { out('  …其餘 ' . (count($quotes) - 15) . ' 張'); break; }
    }
    out('');
    out('這是試算，沒有改動任何資料。要實際執行請加 --run');
    exit(0);
}

// ── 實際執行 ──
$tag = 'B' . date('YmdHis');
$pdo->beginTransaction();
try {
    $bkIns = $pdo->prepare("
        INSERT INTO quotation_process_revert_backup
            (batch_tag, quote_id, item_id, process_no, process_group_type, was_pending_review, created_at)
        VALUES (?,?,?,?,?,?,NOW())
    ");
    $mapQ  = $pdo->prepare("SELECT process_no FROM quotation_item_process_map WHERE quotation_item_id=?");
    $delMap = $pdo->prepare("DELETE FROM quotation_item_process_map WHERE quotation_item_id=?");

    foreach ($items as $it) {
        $mapQ->execute([$it['item_id']]);
        $nos = $mapQ->fetchAll(PDO::FETCH_COLUMN);
        if (!$nos) $nos = [null];   // 至少留一列，才記得住 group_type 與原本的 pending_review
        foreach ($nos as $no) {
            $bkIns->execute([$tag, $it['quote_id'], $it['item_id'], $no, $it['process_group_type'], (int)$it['pending_review']]);
        }
        $delMap->execute([$it['item_id']]);
    }

    // 退回未轉正（只動本來是已轉正的那些）
    $ids = array_keys($toRevert);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE quotation_list SET pending_review=1, updated_at=NOW() WHERE quote_id IN ($ph)")->execute($ids);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    out('執行失敗，已全部回滾：' . $e->getMessage());
    exit(1);
}

out('── 執行完成 ──');
out('批次代號：' . $tag . '（--rollback 會還原這一批）');
out('退回未轉正的報價單：' . count($toRevert) . ' 張');
out('清掉並備份的製程對應列：' . $mapCount . ' 列，涉及 ' . count($items) . ' 筆項目');
out('');
out('接下來：請到「報價單快速轉移」頁重新設定這些單的製程（現在存得對了），料號ID綁定不受影響。');
