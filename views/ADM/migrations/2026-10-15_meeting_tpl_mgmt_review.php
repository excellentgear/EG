<?php
// =============================================================================
// 管理審查會議範本（依 2-GM-05 管理審查管理程序 6.1.4 審查輸入／6.1.5 審查輸出）
//  - 6.1.4 各項 → 會議記錄「一般項目」；6.1.5 各項 → 「決議項目」(directive)
//  - 需要記錄人填入的地方用【待填：提示】標記，送出前系統會擋下沒填的
//  - 可由系統算的資料掛 data_src（套用時依會議日期即時算出）
//  - 冪等：同名範本已存在就略過。執行：php views\ADM\migrations\2026-10-15_meeting_tpl_mgmt_review.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
include_once __DIR__ . '/../../../src/common/meeting_tpl_lib.php';

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
meeting_tpl_ensure($db);

$name = '管理審查會議';
$st = $db->prepare("SELECT tpl_id FROM meeting_tpl WHERE name=? AND is_active=1");
$st->execute([$name]);
if ($st->fetchColumn()) { echo "範本「{$name}」已存在，略過\n"; exit; }

$notice = "【審查輸入（6.1.4）】\n"
 . "1. 內部或外部品質稽核結果報告\n"
 . "2. 客戶回饋資料：客戶抱怨統計、客戶滿意度統計\n"
 . "3. 供應商之績效表現\n"
 . "4. 績效及產品符合性：不良品成本的檢討、各部門各項品質目標達成狀況（尤其是製造過程的能力）\n"
 . "5. 預防和矯正措施的狀況\n"
 . "6. 品質政策、目標執行狀況（含年度目標執行狀況）\n"
 . "7. 其他相關品質系統運作狀況（含可能影響品質管理系統之計劃變動）：「環境審查表」檢視、「防護審核表」檢視\n"
 . "8. 前次管理審查之追蹤行動及情形\n"
 . "9. 客戶／市場的要求變化對本廠的風險及其影響（如品質、安全、環境等）\n"
 . "【審查輸出（6.1.5）】品質管理系統及其過程有效性的改善／顧客要求關於產品服務的改善／資源的需求／風險識別／次年度目標制定／修訂";

// [kind, 內容, data_src]
$items = [
 ['general', "6.1.4.1 內部或外部品質稽核結果報告。\n報告：【待填：本年度內部稽核及外部稽核（客戶、驗證機構）結果摘要】", 'ia'],
 ['general', "6.1.4.2.1 客戶抱怨統計。\n【待填：本年度客戶抱怨件數、主要原因與處理情形】", null],
 ['general', "6.1.4.2.2 客戶滿意度統計。\n【待填：客戶滿意度調查結果與分析】", null],
 ['general', "6.1.4.3 供應商之績效表現。\n【待填：供應商評核結果、不合格供應商處理情形】", null],
 ['general', "6.1.4.4.1 績效及產品符合性－不良品成本的檢討。\n【待填：本年度不良品成本統計與檢討】", 'qa'],
 ['general', "6.1.4.4.2 績效及產品符合性－各部門各項品質目標達成狀況，尤其是製造過程的能力。\n製造過程能力：【待填：製程能力分析（如製程不良率、準時交貨率）檢討】", null],
 ['general', "6.1.4.5 預防和矯正措施的狀況。", 'car'],
 ['general', "6.1.4.6 品質政策、目標執行狀況（含年度目標執行狀況）。\n檢討：【待填：未達標項目原因與改善方向】", 'kpi'],
 ['general', "6.1.4.7 其他相關品質系統運作狀況（含可能影響品質管理系統之計劃變動）。\n6.1.4.7.1「環境審查表」檢視：【待填：檢視結果】\n6.1.4.7.2「防護審核表」檢視：【待填：檢視結果】", null],
 ['general', "6.1.4.8 前次管理審查之追蹤行動及情形。\n【待填：前次決議事項執行與結案情形】", null],
 ['general', "6.1.4.9 客戶／市場的要求變化對本廠的風險及其影響（如品質、安全、環境等）。\n【待填：變化內容與評估】", null],
 ['directive', "6.1.5.1 品質管理系統及其過程有效性的改善。\n決議：【待填：改善決議內容】", null],
 ['directive', "6.1.5.2 顧客要求關於產品服務的改善。\n決議：【待填：改善決議內容】", null],
 ['directive', "6.1.5.3 資源的需求（人員、設施、工作環境、方法、原材料…）。\n決議：【待填：資源需求與核定內容】", null],
 ['directive', "6.1.5.4 風險識別。\n決議：【待填：風險與機會的識別及因應】", null],
 ['directive', "6.1.5.5 次年度目標制定／修訂。\n決議：【待填：次年度目標】", null],
];

$db->beginTransaction();
try {
    $n = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM meeting_tpl")->fetchColumn();
    $db->prepare("INSERT INTO meeting_tpl (name, subject, location, start_time, end_time, notice_items, notice_depts, sort_order, created_at)
                  VALUES (?,?,?,?,?,?,?,?,NOW())")
       ->execute([$name, '管理審查會議', null, null, null, $notice, null, $n]);
    $id = (int)$db->lastInsertId();
    $ins = $db->prepare("INSERT INTO meeting_tpl_item (tpl_id, kind, sort_order, content, owner_depts, remark, data_src) VALUES (?,?,?,?,NULL,NULL,?)");
    foreach ($items as $i => $r) $ins->execute([$id, $r[0], $i, $r[1], $r[2]]);
    $db->commit();
    echo "已建立範本 tpl_id={$id}，共 " . count($items) . " 項\n";
} catch (Throwable $e) { $db->rollBack(); echo '失敗：' . $e->getMessage() . "\n"; exit(1); }
