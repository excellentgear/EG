<?php
// =============================================================================
// 管理審查會議範本 —— 項目條文取自「AS 文件線上版」2-GM-05 管理審查管理程序（不是 Word 檔）
//  - 6.1.4 審查輸入 → 會議記錄「一般項目」；6.1.5 審查輸出 → 「決議項目」(directive)
//  - 每個項目掛 對應 AS 表單（可多選）與系統資料；有登記系統資料的表單會自動帶出統計
//  - 需要記錄人填入的地方用【待填：提示】標記，送出前系統會擋下沒填的
//  - 之後線上版更動：會議紀錄的「範本管理」會提醒，可一鍵依線上版更新（meeting_tpl_sync_apply）
//  - 冪等：範本已存在且項目都已有 clause 就略過；舊版（無 clause）會整批重建。
//  執行：php views\ADM\migrations\2026-10-15_meeting_tpl_mgmt_review.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
include_once __DIR__ . '/../../../src/common/meeting_tpl_lib.php';

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
meeting_tpl_ensure($db);

$name = '管理審查會議';
$docId = (int)$db->query("SELECT id FROM as_document WHERE doc_no='2-GM-05' ORDER BY id LIMIT 1")->fetchColumn();
if (!$docId) { echo "找不到 AS 文件 2-GM-05\n"; exit(1); }
$spec = '6.1.4:general,6.1.5:directive';
$doc = meeting_tpl_doc_clauses($db, $docId, $spec);
if (!$doc) { echo "2-GM-05 沒有線上版內容\n"; exit(1); }

$docIdOf = function (string $no) use ($db) {
    $st = $db->prepare("SELECT id FROM as_document WHERE doc_no=? ORDER BY id LIMIT 1"); $st->execute([$no]);
    return (int)$st->fetchColumn();
};
// 條文 → [待填提示, 對應 AS 表單編號[], 系統資料[{k,ids}]]
$cfg = [
 '6.1.4.1'   => ['報告：【待填：本年度內部稽核及外部稽核（客戶、驗證機構）結果摘要】', ['2-GM-06-08'], []],
 '6.1.4.2.1' => ['說明：【待填：主要客訴原因與處理情形】', [], [['k'=>'ir'], ['k'=>'kpi', 'ids'=>[1]]]],
 '6.1.4.2.2' => ['說明：【待填：客戶滿意度調查結果與分析】', ['2-SM-02-03'], [['k'=>'kpi', 'ids'=>[5]]]],
 '6.1.4.3'   => ['說明：【待填：不合格供應商的處理情形】', ['2-PH-01-05'], []],
 '6.1.4.4.1' => ['製造過程能力檢討：【待填：製程能力分析與改善方向】', [], [['k'=>'mfg'], ['k'=>'kpi']]],
 '6.1.4.5'   => ['說明：【待填：預防和矯正措施執行情形】', ['2-QA-01-04', '2-QA-01-01'], []],
 '6.1.4.6'   => ['檢討：【待填：品質政策執行情形；年度目標未達標項目的原因與改善方向（達成數據見 6.1.4.4.1）】', [], []],
 '6.1.4.7.1' => ['檢視結果：【待填：檢視結果】', ['2-MM-03-01'], []],
 '6.1.4.7.2' => ['檢視結果：【待填：檢視結果】', ['2-MM-05-01'], []],
 '6.1.4.8'   => ['說明：【待填：前次決議事項執行與結案情形】', [], []],
 '6.1.4.9'   => ['說明：【待填：變化內容與評估】', [], []],
];

$ex = $db->prepare("SELECT tpl_id FROM meeting_tpl WHERE name=? AND is_active=1"); $ex->execute([$name]);
$tplId = (int)$ex->fetchColumn();
if ($tplId) {
    $n = (int)$db->query("SELECT COUNT(*) FROM meeting_tpl_item WHERE tpl_id=$tplId AND (clause IS NULL OR clause='')")->fetchColumn();
    if ($n === 0) { echo "範本「{$name}」已是線上版格式，略過\n"; exit; }
}

$notice = "【審查輸入（6.1.4）】\n";
foreach ($doc['clauses'] as $c) if ($c['kind'] === 'general') $notice .= $c['clause'] . ' ' . $c['text'] . "\n";
$notice .= "【審查輸出（6.1.5）】\n";
foreach ($doc['clauses'] as $c) if ($c['kind'] === 'directive') $notice .= $c['clause'] . ' ' . $c['text'] . "\n";
$notice = rtrim($notice);

$db->beginTransaction();
try {
    if ($tplId) {
        $db->prepare("DELETE FROM meeting_tpl_item WHERE tpl_id=?")->execute([$tplId]);
        $db->prepare("UPDATE meeting_tpl SET notice_items=?, src_doc_id=?, src_prefixes=?, src_ver_id=?, src_synced_at=NOW(), updated_at=NOW() WHERE tpl_id=?")
           ->execute([$notice, $docId, $spec, $doc['ver']['version_id'], $tplId]);
    } else {
        $n = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM meeting_tpl")->fetchColumn();
        $db->prepare("INSERT INTO meeting_tpl (name, subject, notice_items, sort_order, src_doc_id, src_prefixes, src_ver_id, src_synced_at, created_at)
                      VALUES (?,?,?,?,?,?,?,NOW(),NOW())")
           ->execute([$name, '管理審查會議', $notice, $n, $docId, $spec, $doc['ver']['version_id']]);
        $tplId = (int)$db->lastInsertId();
    }
    $ins = $db->prepare("INSERT INTO meeting_tpl_item (tpl_id, kind, sort_order, content, clause, title_text, as_docs, src_json)
                         VALUES (?,?,?,?,?,?,?,?)");
    foreach ($doc['clauses'] as $i => $c) {
        $k = $cfg[$c['clause']] ?? null;
        $hint = $k ? $k[0] : ($c['kind'] === 'directive' ? '決議：【待填：決議內容】' : '');
        if ($c['kind'] === 'directive' && $k === null) $hint = '決議：【待填：決議內容】';
        $ids = [];
        foreach (($k[1] ?? []) as $no) { $d = $docIdOf($no); if ($d) $ids[] = $d; }
        $ins->execute([$tplId, $c['kind'], $i, $c['clause'] . ' ' . $c['text'] . ($hint !== '' ? "\n" . $hint : ''),
                       $c['clause'], $c['text'], $ids ? implode(',', $ids) : null,
                       ($k && $k[2]) ? json_encode($k[2], JSON_UNESCAPED_UNICODE) : null]);
    }
    $db->commit();
    echo "已建立／重建範本 tpl_id={$tplId}，共 " . count($doc['clauses']) . " 項（線上版 {$doc['ver']['version']}）\n";
} catch (Throwable $e) { $db->rollBack(); echo '失敗：' . $e->getMessage() . "\n"; exit(1); }
