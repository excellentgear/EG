<?php
/**
 * 2026-09-18_qa_abnormal_v2.php
 * 品質異常處理單 改版（2-QA-01-01 vC）建置：
 *   ① 建立新資料表與主表擴充欄位（qab_ensure_schema，可重複執行）
 *   ② 依使用者指示刪除 2026-07 那 5 筆測試異常單（含通知、流程、附件、編輯記錄）
 *   ③ 把既有的 defect_category 文字分類對應到新的 qa_cause_cat（只對還留著的單；②刪光後通常沒有）
 *
 * 用法（預設只試算，不寫入）：
 *   php views/QA/migrations/2026-09-18_qa_abnormal_v2.php
 *   php views/QA/migrations/2026-09-18_qa_abnormal_v2.php --run
 *   php views/QA/migrations/2026-09-18_qa_abnormal_v2.php --run --keep-orders   （只建 schema，不刪測試單）
 */
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/qa_abnormal_lib.php';

$run  = in_array('--run', $argv, true);
$keep = in_array('--keep-orders', $argv, true);
$db   = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo ($run ? "【實際執行】\n" : "【試算，不寫入】加 --run 才會真的執行\n");

/* ── ① schema ─────────────────────────────────────────── */
if ($run) {
    qab_ensure_schema($db);
    echo "① schema：已建立／補齊\n";
} else {
    echo "① schema：將建立 qa_cause_cat / qa_option / qa_decider_cfg / qa_abnormal_cause / qa_abnormal_opt /\n";
    echo "            qa_abnormal_resp / qa_abnormal_measure / qa_abnormal_deduct / qa_scrap_seq，\n";
    echo "            並為 qa_abnormal_order 補 30 個欄位、qa_abnormal_order_flow 補 6 個欄位\n";
}

/* ── ② 刪除測試單 ─────────────────────────────────────── */
$st = $db->query("SELECT id, abnormal_order_no, notify_event_id, created_at FROM qa_abnormal_order ORDER BY id");
$orders = $st->fetchAll(PDO::FETCH_ASSOC);
echo "② 既有異常單 " . count($orders) . " 筆：\n";
foreach ($orders as $o) echo "   #{$o['id']} {$o['abnormal_order_no']}  建立 {$o['created_at']}  通知 event=" . ($o['notify_event_id'] ?: '-') . "\n";

if ($keep) {
    echo "   （--keep-orders：不刪除）\n";
} elseif (!$orders) {
    echo "   （沒有資料可刪）\n";
} else {
    $ids = array_column($orders, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $evIds = array_values(array_filter(array_map('intval', array_column($orders, 'notify_event_id'))));

    // 先算出會刪掉哪些附屬資料（試算也要看得到）
    $cnt = function (string $sql, array $p) use ($db) {
        try { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); } catch (Throwable $e) { return -1; }
    };
    echo "   連帶刪除：flow " . $cnt("SELECT COUNT(*) FROM qa_abnormal_order_flow WHERE abnormal_order_id IN ($in)", $ids)
       . "、附件 " . $cnt("SELECT COUNT(*) FROM qa_abnormal_attachments WHERE abnormal_order_id IN ($in)", $ids)
       . "、追蹤 " . $cnt("SELECT COUNT(*) FROM qa_abnormal_follower WHERE abnormal_order_id IN ($in)", $ids)
       . "、共編 " . $cnt("SELECT COUNT(*) FROM qa_abnormal_editor WHERE abnormal_order_id IN ($in)", $ids)
       . "、編輯記錄 " . $cnt("SELECT COUNT(*) FROM qa_abnormal_edit_log WHERE abnormal_order_id IN ($in)", $ids)
       . "、修改請求 " . $cnt("SELECT COUNT(*) FROM qa_abnormal_edit_request WHERE abnormal_order_id IN ($in)", $ids) . "\n";
    if ($evIds) {
        $ein = implode(',', array_fill(0, count($evIds), '?'));
        echo "   連帶刪除通知：live_event " . $cnt("SELECT COUNT(*) FROM live_event WHERE id IN ($ein)", $evIds)
           . "、對象 " . $cnt("SELECT COUNT(*) FROM live_event_target WHERE live_event_id IN ($ein)", $evIds) . "\n";
    }
    // ref_type='QA' 指向這些單的其他通知（例如修改請求通知）也一併清掉，否則鈴鐺點進去會找不到單
    $orphan = $cnt("SELECT COUNT(*) FROM live_event WHERE ref_type='QA' AND ref_id IN ($in)", $ids);
    echo "   連帶刪除 ref_type='QA' 指向這些單的通知：{$orphan}\n";

    if ($run) {
        $db->beginTransaction();
        try {
            foreach (['qa_abnormal_attachments', 'qa_abnormal_follower', 'qa_abnormal_editor',
                      'qa_abnormal_edit_log', 'qa_abnormal_edit_request', 'qa_abnormal_order_flow'] as $t) {
                try { $db->prepare("DELETE FROM $t WHERE abnormal_order_id IN ($in)")->execute($ids); } catch (Throwable $e) {}
            }
            // 新表（若先前試跑過）
            foreach (['qa_abnormal_cause', 'qa_abnormal_opt', 'qa_abnormal_resp', 'qa_abnormal_measure', 'qa_abnormal_deduct'] as $t) {
                try { $db->prepare("DELETE FROM $t WHERE order_id IN ($in)")->execute($ids); } catch (Throwable $e) {}
            }
            $allEv = $db->prepare("SELECT id FROM live_event WHERE ref_type='QA' AND ref_id IN ($in)");
            $allEv->execute($ids);
            $evAll = array_unique(array_merge($evIds, array_map('intval', $allEv->fetchAll(PDO::FETCH_COLUMN))));
            if ($evAll) {
                $ein = implode(',', array_fill(0, count($evAll), '?'));
                foreach (['live_event_target', 'live_event_reply', 'live_event_history'] as $t) {
                    try { $db->prepare("DELETE FROM $t WHERE live_event_id IN ($ein)")->execute(array_values($evAll)); } catch (Throwable $e) {}
                }
                $db->prepare("DELETE FROM live_event WHERE id IN ($ein)")->execute(array_values($evAll));
            }
            $db->prepare("DELETE FROM qa_abnormal_order WHERE id IN ($in)")->execute($ids);
            $db->commit();
            echo "   已刪除 " . count($ids) . " 筆測試異常單與其附屬資料\n";
        } catch (Throwable $e) {
            $db->rollBack();
            echo "   刪除失敗（已還原）：" . $e->getMessage() . "\n";
        }
    }
}

/* ── ③ 舊分類文字 → 新代碼表 ──────────────────────────── */
if ($run) {
    $left = $db->query("SELECT id, defect_category FROM qa_abnormal_order WHERE defect_category IS NOT NULL AND defect_category<>''")->fetchAll(PDO::FETCH_ASSOC);
    if ($left) {
        $map = [];
        foreach ($db->query("SELECT cat_id,name FROM qa_cause_cat WHERE parent_id IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $c) $map[$c['name']] = (int)$c['cat_id'];
        $ins = $db->prepare("INSERT IGNORE INTO qa_abnormal_cause (order_id,cat_id) VALUES (?,?)");
        $n = 0;
        foreach ($left as $o) {
            $cid = $map[$o['defect_category']] ?? 0;   // 舊 enum 的「其他」刻意沒有對應，留空由人重選
            if ($cid) { $ins->execute([(int)$o['id'], $cid]); $n++; }
        }
        echo "③ 舊分類對應：{$n} 筆\n";
    } else {
        echo "③ 舊分類對應：沒有需要對應的資料\n";
    }
}

echo "完成。\n";
