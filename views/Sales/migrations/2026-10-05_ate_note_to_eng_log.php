<?php
/**
 * 2026-10-05_ate_note_to_eng_log.php — 把訂單追蹤「設計備註」舊資料搬進 eng_log 問題項
 *
 * 背景：工程處理紀錄(eng_log)併入設計備註欄位(NewOrder_Track.php)之後，畫面改成讀
 * eng_log_item 而不是 order_track.ateNote；舊的自由文字與歷史「已處理」快照
 * (order_ate_note_log) 若不搬過去，會從畫面上消失、也查不回來。
 *
 * 做的事（完全不刪除、不清空任何既有欄位，只新增）：
 *  1. ateNote 目前有內容的訂單 → 每張訂單對應案件底下新增一筆「純備註」問題項
 *     (target_type=NULL)，狀態=waiting（待確認），內容＝目前的 ateNote 原文。
 *  2. order_ate_note_log 的每一筆歷史「已處理」快照 → 同一張訂單對應案件底下新增一筆
 *     「純備註」問題項，狀態=resolved，created_at 保留原始時間（不是搬移當下的時間）。
 *  3. order_track.ateNote 與 order_ate_note_log 兩個來源表完全不動（唯讀搬移，零風險）。
 *
 * 一張訂單只會有一個 eng_log 案件（find-or-create，見 el_order_case_get_or_create()），
 * 新舊問題項都掛在同一個案件底下。
 *
 * 用法：
 *   php 2026-10-05_ate_note_to_eng_log.php --dry   （預設，只印出會怎麼搬，不寫入）
 *   php 2026-10-05_ate_note_to_eng_log.php --run    （實際寫入）
 *
 * 可重複執行：每次都先查「這個案件底下是否已有一筆內容完全相同、來源相同的問題項」才新增，
 * 不會因為重跑而重複搬移。
 *
 * ★ 每一筆各自一個小交易（不是整批一個大交易）：這套站台是正式上線、多人同時在用的系統，
 * 1403+76 筆若包成一個大交易，鎖的時間太長、也會讓單一筆的號碼碰撞（eng_log.log_no 由
 * el_next_log_no() 讀目前最大值+1 產生，不是嚴格原子操作）一撞就整批回滾、全部要重來。
 * 改成逐筆各自 commit，遇到號碼碰撞（MySQL 1062）就該筆重試幾次，不會波及其他已搬移成功的筆。
 */

chdir(__DIR__);
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/eng_log_lib.php';

$RUN = in_array('--run', $argv, true);

$conn = new DBConnection();
$db = $conn->getPDO();
el_ensure_schema($db);

$actor = ['uid' => 1, 'dept_id' => null];   // 系統搬移，掛在超級管理員名下（與其他唯讀搬移腳本同一慣例）

function el_mig_item_exists(PDO $db, int $logId, string $question, string $status): bool
{
    $st = $db->prepare("SELECT 1 FROM eng_log_item WHERE log_id=? AND target_type IS NULL AND question=? AND status=? LIMIT 1");
    $st->execute([$logId, $question, $status]);
    return (bool)$st->fetchColumn();
}

function el_mig_find_case(PDO $db, int $orderId): int
{
    $st = $db->prepare("SELECT el.id FROM eng_log_bind b JOIN eng_log el ON el.id=b.log_id
                        WHERE b.bind_type='order' AND b.bind_id=? ORDER BY el.id LIMIT 1");
    $st->execute([(string)$orderId]);
    return (int)$st->fetchColumn();
}

/**
 * 搬移一筆（新增案件/問題項），自己的小交易，撞號碼就重試。成功回 log_id，失敗丟例外。
 */
function el_mig_insert_one(PDO $db, array $actor, int $orderId, string $question, string $status,
                            string $askedAt, string $createdAt, int $remindSent): int
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $db->beginTransaction();
        try {
            // 案件若需要新建，log_no 一律用「今天」編（即使問題項補的是歷史日期），
            // 跟 eng_log.php 手動建案同一套編號邏輯。
            $logId = el_order_case_get_or_create($db, $orderId, $actor, $createdAt, date('Y-m-d'));
            $db->prepare("INSERT INTO eng_log_item (log_id, seq, question, status, asked_at, remind_sent, created_at, updated_at)
                          SELECT ?, COALESCE(MAX(seq),0)+1, ?, ?, ?, ?, ?, ? FROM eng_log_item WHERE log_id=?")
               ->execute([$logId, $question, $status, $askedAt, $remindSent, $createdAt, $createdAt, $logId]);
            if ($status !== 'waiting') el_order_case_sync_status($db, $logId, $createdAt);
            $db->commit();
            return $logId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            // 1062＝log_no 撞號（罕見的並發新建案件），重試即可；其餘例外直接往外丟
            if (strpos($e->getMessage(), '1062') !== false && $attempt < 4) { usleep(50000); continue; }
            throw $e;
        }
    }
    throw new RuntimeException('重試仍然撞號，請稍後重跑');
}

$stats = ['ate_note_scanned' => 0, 'ate_note_migrated' => 0, 'ate_note_skipped' => 0, 'ate_note_failed' => 0,
          'log_scanned' => 0, 'log_migrated' => 0, 'log_skipped' => 0, 'log_failed' => 0];

echo ($RUN ? "=== 實際寫入模式 ===\n" : "=== 預覽模式（不會寫入，加 --run 才會實際執行）===\n");

$nowStr = (string)$db->query("SELECT NOW()")->fetchColumn();
$today = substr($nowStr, 0, 10);

/* ① ateNote 目前有內容的訂單 */
$rows = $db->query("SELECT Order_id, Order_oo, d_id, ateNote FROM order_track
                    WHERE ateNote IS NOT NULL AND TRIM(ateNote) <> ''")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $stats['ate_note_scanned']++;
    $oid = (int)$r['Order_id'];
    $text = trim((string)$r['ateNote']);

    $existingLogId = el_mig_find_case($db, $oid);
    if ($existingLogId > 0 && el_mig_item_exists($db, $existingLogId, $text, 'waiting')) {
        $stats['ate_note_skipped']++;
        continue;
    }

    echo sprintf("[ateNote] 訂單 #%d（%s／%s）→ 純備註問題項（待確認）：%s\n",
        $oid, $r['Order_oo'], $r['d_id'], mb_substr($text, 0, 40));

    if ($RUN) {
        try {
            el_mig_insert_one($db, $actor, $oid, $text, 'waiting', $today, $nowStr, 0);
            $stats['ate_note_migrated']++;
        } catch (Throwable $e) {
            $stats['ate_note_failed']++;
            echo "   !! 失敗：" . $e->getMessage() . "\n";
        }
    } else {
        $stats['ate_note_migrated']++;
    }
}

/* ② 歷史「已處理」快照 order_ate_note_log */
$logs = [];
try {
    $logs = $db->query("SELECT log_id, Order_id, note_text, created_by_name, created_at
                        FROM order_ate_note_log ORDER BY log_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { echo "（order_ate_note_log 表不存在或讀取失敗，略過這一段：" . $e->getMessage() . "）\n"; }

foreach ($logs as $lg) {
    $stats['log_scanned']++;
    $oid = (int)$lg['Order_id'];
    $text = trim((string)$lg['note_text']);
    if ($text === '') { $stats['log_skipped']++; continue; }

    $existingLogId = el_mig_find_case($db, $oid);
    if ($existingLogId > 0 && el_mig_item_exists($db, $existingLogId, $text, 'resolved')) {
        $stats['log_skipped']++;
        continue;
    }

    $createdAt = (string)($lg['created_at'] ?: $nowStr);
    $askedAt = substr($createdAt, 0, 10);
    echo sprintf("[歷史已處理] 訂單 #%d（原紀錄 #%d，%s，%s）→ 純備註問題項（已處理）：%s\n",
        $oid, (int)$lg['log_id'], $createdAt, $lg['created_by_name'] ?: '（無記名）', mb_substr($text, 0, 40));

    if ($RUN) {
        try {
            el_mig_insert_one($db, $actor, $oid, $text, 'resolved', $askedAt, $createdAt, 1);
            $stats['log_migrated']++;
        } catch (Throwable $e) {
            $stats['log_failed']++;
            echo "   !! 失敗：" . $e->getMessage() . "\n";
        }
    } else {
        $stats['log_migrated']++;
    }
}

echo "\n" . ($RUN ? "執行完畢。" : "（預覽模式，未寫入任何資料。確認上面內容無誤後加 --run 執行。）") . "\n";
echo "\n=== 統計 ===\n";
echo "ateNote 掃到有內容的訂單：{$stats['ate_note_scanned']}，搬移：{$stats['ate_note_migrated']}，已存在略過：{$stats['ate_note_skipped']}，失敗：{$stats['ate_note_failed']}\n";
echo "歷史已處理快照掃到：{$stats['log_scanned']}，搬移：{$stats['log_migrated']}，已存在略過：{$stats['log_skipped']}，失敗：{$stats['log_failed']}\n";
echo "order_track.ateNote 與 order_ate_note_log 兩個來源表完全未被修改。\n";
if ($RUN && ($stats['ate_note_failed'] > 0 || $stats['log_failed'] > 0)) {
    echo "有失敗的筆數，重跑一次本腳本即可（已成功的筆會被跳過，只補失敗的那些）。\n";
}
