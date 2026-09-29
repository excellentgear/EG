<?php
/**
 * return_order_map.Order_id 的外鍵指錯資料表（2026-09-29）
 * ------------------------------------------------------------------
 * 症狀：快速出貨的「追溯對照」可以把退貨單拖去綁訂單，但這張表**從來沒有成功寫進一列**
 *       （全庫 0 筆、AUTO_INCREMENT=2＝只成功過一次又被刪掉）。
 *
 * 根因：`fk_rom_order` 指向 **order_list**（ERP 匯入的「未交清單」快照表），
 *       而 trace_chain_lib 的 `tc_node('order', …)` 讀的是 **order_track**（自建訂單追蹤）。
 *       兩張表各自有獨立的 auto_increment Order_id，所以把 order_track 的 id 寫進來
 *       幾乎一定被外鍵擋下（PDOException），使用者只看到「按了沒反應」。
 *       更根本的是：order_list 只裝「還完全沒出貨」的訂單快照（每列 Qty=Open_Qty，
 *       最後一次匯入 2026-03-12），而退貨的前提是貨已經出去了——那些訂單**本質上不會
 *       出現在未交清單裡**，所以這個外鍵在業務邏輯上也不可能成立。
 *
 * 另一個證據：同一套追溯鏈的 `shipment_order_map.Order_id` 外鍵指的就是 order_track
 *       （見 CLAUDE.md 2026-09-21 記錄的「FK 其實是指向 order_track，資料表註解寫錯」），
 *       全站是以 order_track 為訂單的權威來源，return_order_map 是唯一的例外。
 *
 * 做法：DROP fk_rom_order → 改指 order_track(Order_id)，並修正欄位註解。
 *       這張表目前是空的，所以不會有既有資料被擋下或需要轉換。
 *
 * 用法（預設只試算，不會改任何東西）：
 *   php views/Sales/migrations/2026-09-29_return_order_map_fk.php
 *   php views/Sales/migrations/2026-09-29_return_order_map_fk.php --run
 *   php views/Sales/migrations/2026-09-29_return_order_map_fk.php --verify
 * 可重複執行：已經改好了會直接說「無須處理」。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');

$run    = in_array('--run', $argv, true);
$verify = in_array('--verify', $argv, true);

$db = new PDO('mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4',
              'EG-TS2024', 'excell30367593',
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/** 目前 Order_id 的外鍵指到哪張表 */
function cur_ref(PDO $db): ?array {
    $st = $db->prepare("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
                        FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='return_order_map'
                          AND COLUMN_NAME='Order_id' AND REFERENCED_TABLE_NAME IS NOT NULL
                        LIMIT 1");
    $st->execute();
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

$rows = (int)$db->query("SELECT COUNT(*) FROM return_order_map")->fetchColumn();
$fk   = cur_ref($db);

echo "目前狀態：return_order_map 共 {$rows} 列；Order_id 外鍵＝"
   . ($fk ? ($fk['CONSTRAINT_NAME'] . ' → ' . $fk['REFERENCED_TABLE_NAME']) : '（沒有外鍵）') . "\n";

if ($verify) {
    // 綁定得進去嗎：拿一筆真實的 ir_track 與 order_track 試寫再立刻刪掉（同一個交易，一定還原）
    $ir = (int)$db->query("SELECT IR_id FROM ir_track ORDER BY IR_id DESC LIMIT 1")->fetchColumn();
    $ot = (int)$db->query("SELECT Order_id FROM order_track ORDER BY Order_id DESC LIMIT 1")->fetchColumn();
    if (!$ir || !$ot) { echo "驗證跳過：找不到可用的退貨單或訂單\n"; exit(0); }
    echo "驗證：試寫 IR_id={$ir} ↔ order_track.Order_id={$ot}（寫完立刻回滾，不留資料）… ";
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO return_order_map (IR_id, Order_id, return_qty) VALUES (?,?,1)")->execute([$ir, $ot]);
        $db->rollBack();
        echo "成功（外鍵已正確指向 order_track）\n";
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "失敗：" . $e->getMessage() . "\n";
    }
    exit(0);
}

if ($fk && $fk['REFERENCED_TABLE_NAME'] === 'order_track') {
    echo "無須處理：外鍵已經指向 order_track。\n";
    exit(0);
}
if ($rows > 0) {
    // 保險：真的有資料時先確認每一列在 order_track 裡都找得到，否則換外鍵會失敗
    $bad = (int)$db->query("SELECT COUNT(*) FROM return_order_map m
                            LEFT JOIN order_track ot ON ot.Order_id=m.Order_id
                            WHERE ot.Order_id IS NULL")->fetchColumn();
    echo "現有 {$rows} 列，其中 {$bad} 列在 order_track 找不到對應訂單。\n";
    if ($bad > 0) { echo "中止：請先處理這 {$bad} 列，否則換外鍵會被擋下。\n"; exit(1); }
}

if (!$run) {
    echo "\n[試算] 將執行：\n"
       . "  ① ALTER TABLE return_order_map DROP FOREIGN KEY " . ($fk['CONSTRAINT_NAME'] ?? 'fk_rom_order') . "\n"
       . "  ② ALTER TABLE return_order_map ADD CONSTRAINT fk_rom_order_track\n"
       . "       FOREIGN KEY (Order_id) REFERENCES order_track(Order_id) ON DELETE RESTRICT ON UPDATE CASCADE\n"
       . "  ③ 修正 Order_id 欄位註解（改成 order_track.Order_id）\n"
       . "要實際執行請加 --run\n";
    exit(0);
}

try {
    if ($fk) {
        $db->exec("ALTER TABLE return_order_map DROP FOREIGN KEY `" . $fk['CONSTRAINT_NAME'] . "`");
        echo "① 已移除舊外鍵 {$fk['CONSTRAINT_NAME']}\n";
    }
    $db->exec("ALTER TABLE return_order_map
               ADD CONSTRAINT `fk_rom_order_track` FOREIGN KEY (`Order_id`)
               REFERENCES `order_track` (`Order_id`) ON DELETE RESTRICT ON UPDATE CASCADE");
    echo "② 已加上新外鍵 fk_rom_order_track → order_track(Order_id)\n";
    $db->exec("ALTER TABLE return_order_map
               MODIFY `Order_id` int NOT NULL COMMENT '對應 order_track.Order_id（訂單追蹤主鍵；2026-09-29 由 order_list 改正）'");
    echo "③ 已修正欄位註解\n";
    $now = cur_ref($db);
    echo "完成：Order_id 外鍵現在是 " . $now['CONSTRAINT_NAME'] . ' → ' . $now['REFERENCED_TABLE_NAME'] . "\n";
} catch (Throwable $e) {
    echo "失敗：" . $e->getMessage() . "\n";
    exit(1);
}
