<?php
/**
 * PFMEA 潛在失效模式及效應分析（AS 3-TD-01-02）API
 * 資料/權限說明見 src/common/pfmea_lib.php
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';
include_once $document_root . '/EGsystem/src/common/pfmea_lib.php';
include_once $document_root . '/EGsystem/src/common/pfmea_suggest_lib.php';
include_once $document_root . '/EGsystem/src/common/pfmea_reference_lib.php';

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']); exit;
}

$db = (new DBConnection())->getPDO();
pfmea_ensure_schema($db);
$me    = pfmea_current_user($db);
$perms = pfmea_perms($db, $me);
$uid   = $me ? (int)$me['id'] : 0;
$uname = $me ? (string)$me['user_cname'] : '';

function jout($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function needView(array $perms) { if (!$perms['canView']) jout(['success'=>false,'message'=>'無檢閱權限']); }
function needEdit(array $perms) { if (!$perms['canEdit']) jout(['success'=>false,'message'=>'無登錄權限']); }
function needAdmin(array $perms) { if (!$perms['canAdmin']) jout(['success'=>false,'message'=>'無管理權限']); }

/** 組出單筆項目列（RPN 一律當下重算，不採信前端送來的值——鐵律：推導欄位系統算不給手填） */
function buildItemView(array $it): array {
    $s = $it['severity'] !== null ? (int)$it['severity'] : null;
    $o = $it['occurrence'] !== null ? (int)$it['occurrence'] : null;
    $d = $it['detection'] !== null ? (int)$it['detection'] : null;
    $rpn = ($s !== null && $o !== null && $d !== null) ? $s * $o * $d : null;
    $ns = $it['new_severity'] !== null ? (int)$it['new_severity'] : null;
    $no = $it['new_occurrence'] !== null ? (int)$it['new_occurrence'] : null;
    $nd = $it['new_detection'] !== null ? (int)$it['new_detection'] : null;
    $newRpn = ($ns !== null && $no !== null && $nd !== null) ? $ns * $no * $nd : null;
    return [
        'id' => (int)$it['id'], 'seq' => (int)$it['seq'], 'process_code' => $it['process_code'] ?? null,
        'process_desc' => $it['process_desc'], 'function_desc' => $it['function_desc'], 'requirement' => $it['requirement'],
        'failure_mode' => $it['failure_mode'], 'failure_effect' => $it['failure_effect'],
        'severity' => $s, 'classification' => $it['classification'], 'failure_cause' => $it['failure_cause'],
        'occurrence' => $o, 'detection' => $d, 'rpn' => $rpn,
        'recommended_actions' => $it['recommended_actions'], 'responsibility' => $it['responsibility'], 'target_date' => $it['target_date'],
        'action_taken' => $it['action_taken'], 'action_date' => $it['action_date'],
        'new_severity' => $ns, 'new_occurrence' => $no, 'new_detection' => $nd, 'new_rpn' => $newRpn,
        'prevention_controls' => $it['prevention_controls'], 'detection_controls' => $it['detection_controls'],
    ];
}

/** 官方紙本表單(F-11210-UE2-0001)固定的「相關部門」勾選清單，非逐份填寫可自訂的內容 */
const PFMEA_DEPT_LIST = ['管理課','技術課','業務組','品保組','倉管組','採購組','生管組','生產課'];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'perms':
    jout(['success'=>true,'perms'=>$perms,'user_name'=>$uname]);

case 'list':
    needView($perms);
    $kw = trim((string)($_GET['kw'] ?? ''));
    $sql = "SELECT h.id, h.doc_no, h.part_d_id, COALESCE(ds.D_Setting_Id, h.part_no_text,'') AS part_no,
                   COALESCE(cl.customer,'') AS customer_name, h.created_by_name, h.created_at,
                   (SELECT COUNT(*) FROM pfmea_item i WHERE i.doc_id=h.id AND i.is_deleted=0) AS item_count,
                   (SELECT MAX(i.rpn) FROM pfmea_item i WHERE i.doc_id=h.id AND i.is_deleted=0
                     AND i.severity IS NOT NULL AND i.occurrence IS NOT NULL AND i.detection IS NOT NULL) AS max_rpn
            FROM pfmea_doc h
            LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
            LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
            WHERE h.is_deleted=0";
    $args = [];
    if ($kw !== '') {
        $sql .= " AND (h.doc_no LIKE ? OR h.part_no_text LIKE ? OR ds.D_Setting_Id LIKE ?)";
        $like = '%'.$kw.'%'; $args = [$like,$like,$like];
    }
    $sql .= " ORDER BY h.created_at DESC";
    $st = $db->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // MAX(rpn) 需即時依 S*O*D 重算，SQL 端存的 rpn 欄位（若有）不採信；改在 PHP 端重算 max
    foreach ($rows as &$r) {
        $st2 = $db->prepare("SELECT severity, occurrence, detection FROM pfmea_item WHERE doc_id=? AND is_deleted=0");
        $st2->execute([$r['id']]);
        $max = null;
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $it) {
            if ($it['severity'] === null || $it['occurrence'] === null || $it['detection'] === null) continue;
            $v = (int)$it['severity'] * (int)$it['occurrence'] * (int)$it['detection'];
            if ($max === null || $v > $max) $max = $v;
        }
        $r['max_rpn'] = $max;
    }
    unset($r);
    jout(['success'=>true,'rows'=>$rows]);

case 'get':
    needView($perms);
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT h.*, COALESCE(ds.D_Setting_Id, h.part_no_text,'') AS part_no,
                                COALESCE(cl.customer,'') AS customer_name
                         FROM pfmea_doc h LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
                         LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                         WHERE h.id=? AND h.is_deleted=0");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['success'=>false,'message'=>'找不到該筆']);
    $st = $db->prepare("SELECT * FROM pfmea_item WHERE doc_id=? AND is_deleted=0 ORDER BY seq");
    $st->execute([$id]);
    $items = array_map('buildItemView', $st->fetchAll(PDO::FETCH_ASSOC));
    jout(['success'=>true,'doc'=>$doc,'items'=>$items]);

case 'save':
    needEdit($perms);
    $id = (int)($_POST['id'] ?? 0);
    $partDId = (int)($_POST['part_d_id'] ?? 0);
    $partNoText = trim((string)($_POST['part_no_text'] ?? ''));
    $itemType = ((string)($_POST['item_type'] ?? 'part')) === 'assembly' ? 'assembly' : 'part';
    $specDesc = trim((string)($_POST['spec_desc'] ?? ''));
    $productName = trim((string)($_POST['product_name'] ?? ''));
    $relatedDeptsRaw = json_decode((string)($_POST['related_depts'] ?? '[]'), true);
    if (!is_array($relatedDeptsRaw)) $relatedDeptsRaw = [];
    $relatedDepts = implode(',', array_values(array_intersect(PFMEA_DEPT_LIST, $relatedDeptsRaw)));
    $itemsRaw = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($itemsRaw)) $itemsRaw = [];

    $db->beginTransaction();
    try {
        if ($id) {
            $st = $db->prepare("SELECT 1 FROM pfmea_doc WHERE id=? AND is_deleted=0");
            $st->execute([$id]);
            if (!$st->fetchColumn()) throw new Exception('找不到該筆或已刪除');
            $st = $db->prepare("UPDATE pfmea_doc SET part_d_id=?, part_no_text=?, item_type=?, spec_desc=?, product_name=?, related_depts=?,
                                 updated_at=NOW(), updated_by=?, updated_by_name=? WHERE id=?");
            $st->execute([$partDId ?: null, $partNoText ?: null, $itemType, $specDesc ?: null, $productName ?: null, $relatedDepts ?: null, $uid, $uname, $id]);
        } else {
            $docNo = pfmea_next_doc_no($db);
            $st = $db->prepare("INSERT INTO pfmea_doc (doc_no, part_d_id, part_no_text, item_type, spec_desc, product_name, related_depts, created_by, created_by_name)
                                 VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute([$docNo, $partDId ?: null, $partNoText ?: null, $itemType, $specDesc ?: null, $productName ?: null, $relatedDepts ?: null, $uid, $uname]);
            $id = (int)$db->lastInsertId();
        }

        $st = $db->prepare("SELECT id FROM pfmea_item WHERE doc_id=? AND is_deleted=0");
        $st->execute([$id]);
        $existing = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

        $seq = 0;
        foreach ($itemsRaw as $it) {
            // 空白列不存（比照可增列表格鐵則）：失效模式與製程說明都空白視為未填
            $failureMode = trim((string)($it['failure_mode'] ?? ''));
            $processDesc = trim((string)($it['process_desc'] ?? ''));
            if ($failureMode === '' && $processDesc === '') continue;
            $seq++;

            $vals = [
                trim((string)($it['process_code'] ?? '')) ?: null,
                $processDesc ?: null, trim((string)($it['function_desc'] ?? '')) ?: null, trim((string)($it['requirement'] ?? '')) ?: null,
                $failureMode ?: null, trim((string)($it['failure_effect'] ?? '')) ?: null,
                pfmea_clamp_rating($it['severity'] ?? null), trim((string)($it['classification'] ?? '')) ?: null,
                trim((string)($it['failure_cause'] ?? '')) ?: null, pfmea_clamp_rating($it['occurrence'] ?? null),
                pfmea_clamp_rating($it['detection'] ?? null),
                trim((string)($it['recommended_actions'] ?? '')) ?: null, trim((string)($it['responsibility'] ?? '')) ?: null,
                trim((string)($it['target_date'] ?? '')) ?: null,
                trim((string)($it['action_taken'] ?? '')) ?: null, trim((string)($it['action_date'] ?? '')) ?: null,
                pfmea_clamp_rating($it['new_severity'] ?? null), pfmea_clamp_rating($it['new_occurrence'] ?? null), pfmea_clamp_rating($it['new_detection'] ?? null),
                trim((string)($it['prevention_controls'] ?? '')) ?: null, trim((string)($it['detection_controls'] ?? '')) ?: null,
            ];

            $rowId = (int)($it['id'] ?? 0);
            if ($rowId && isset($existing[$rowId])) {
                $st = $db->prepare("UPDATE pfmea_item SET seq=?, process_code=?, process_desc=?, function_desc=?, requirement=?,
                    failure_mode=?, failure_effect=?, severity=?, classification=?, failure_cause=?, occurrence=?,
                    detection=?, recommended_actions=?, responsibility=?, target_date=?,
                    action_taken=?, action_date=?, new_severity=?, new_occurrence=?, new_detection=?,
                    prevention_controls=?, detection_controls=?, updated_at=NOW()
                    WHERE id=?");
                $st->execute(array_merge([$seq], $vals, [$rowId]));
                unset($existing[$rowId]);
            } else {
                $st = $db->prepare("INSERT INTO pfmea_item
                    (doc_id, seq, process_code, process_desc, function_desc, requirement, failure_mode, failure_effect, severity,
                     classification, failure_cause, occurrence, detection, recommended_actions,
                     responsibility, target_date, action_taken, action_date, new_severity, new_occurrence, new_detection,
                     prevention_controls, detection_controls)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute(array_merge([$id, $seq], $vals));
            }
        }
        if ($existing) {
            $delIds = array_keys($existing);
            $in = implode(',', array_fill(0, count($delIds), '?'));
            $db->prepare("UPDATE pfmea_item SET is_deleted=1 WHERE id IN ($in)")->execute($delIds);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'儲存失敗：'.$e->getMessage()]); }
    jout(['success'=>true,'id'=>$id]);

// ── 建議建立清單（來源：已有 td_dev_eval 紀錄、還沒有 PFMEA 紀錄的料號）──────────
case 'suggest_list':
    needEdit($perms);
    jout(['success'=>true,'rows'=>pfmea_suggest_candidates($db)]);

case 'suggest_bulk_create':
    needEdit($perms);
    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($rows) || !$rows) jout(['success'=>false,'message'=>'沒有可建立的料號']);
    $r = pfmea_suggest_bulk_create($db, $rows, $uid, $uname);
    jout(['success'=>true,'created'=>$r['created'],'errors'=>$r['errors']]);

// ── 參考資料庫（製程代號／潛在失效模式／控制預防控制偵測選項／製程整組樣板）───────
// 可填表人(canEdit)可新增/自行輸入新值；僅管理員(canAdmin)可刪除。
case 'ref_process_list':
    needView($perms);
    jout(['success'=>true,'rows'=>pfmea_ref_process_list($db)]);

case 'ref_process_add':
    needEdit($perms);
    $code = trim((string)($_POST['process_code'] ?? ''));
    $name = trim((string)($_POST['process_name'] ?? ''));
    if ($code === '') jout(['success'=>false,'message'=>'缺少製程代號']);
    $id = pfmea_ref_process_get_or_add($db, $code, $name, $uid, $uname);
    jout(['success'=>true,'id'=>$id]);

case 'ref_process_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_ref_process_delete($db, $id);
    jout(['success'=>true]);

case 'ref_failure_mode_list':
    needView($perms);
    $pid = (int)($_GET['process_id'] ?? 0);
    if (!$pid) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>pfmea_ref_failure_mode_list($db, $pid)]);

case 'ref_failure_mode_add':
    needEdit($perms);
    $pid = (int)($_POST['process_id'] ?? 0);
    $text = trim((string)($_POST['failure_mode'] ?? ''));
    if (!$pid || $text === '') jout(['success'=>false,'message'=>'缺少製程或失效模式文字']);
    $id = pfmea_ref_failure_mode_add($db, $pid, $text, $uid, $uname);
    jout(['success'=>true,'id'=>$id]);

case 'ref_failure_mode_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_ref_failure_mode_delete($db, $id);
    jout(['success'=>true]);

case 'ref_control_options':
    needView($perms);
    jout(['success'=>true,'options'=>pfmea_ref_control_options($db)]);

case 'ref_control_option_add':
    needEdit($perms);
    $type = (string)($_POST['option_type'] ?? '');
    $text = trim((string)($_POST['option_text'] ?? ''));
    if ($text === '') jout(['success'=>false,'message'=>'缺少選項文字']);
    $id = pfmea_ref_control_option_add($db, $type, $text, $uid, $uname);
    jout(['success'=>true,'id'=>$id]);

case 'ref_control_option_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_ref_control_option_delete($db, $id);
    jout(['success'=>true]);

case 'ref_item_templates':
    needView($perms);
    $pid = (int)($_GET['process_id'] ?? 0);
    if (!$pid) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>pfmea_ref_item_templates($db, $pid)]);

case 'ref_item_template_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_ref_item_template_delete($db, $id);
    jout(['success'=>true]);

case 'delete_header':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    $db->prepare("UPDATE pfmea_doc SET is_deleted=1 WHERE id=?")->execute([$id]);
    jout(['success'=>true]);

// ── AS 文件編號綁定（本頁自身模板）────────────────────────────────
case 'asdoc_list':
    needView($perms);
    jout(['success'=>true,'docs'=>eg_asdoc_list($db)]);

case 'asdoc_get':
    needView($perms);
    jout(['success'=>true,'as_doc'=>eg_asdoc_get($db,'pfmea')]);

case 'as_doc_save':
    needAdmin($perms);
    $docId = (int)($_POST['doc_id'] ?? 0);
    eg_asdoc_save($db, 'pfmea', $docId, $uname);
    jout(['success'=>true,'as_doc'=>eg_asdoc_get($db,'pfmea')]);

case 'print_get':
    needView($perms);
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT h.*, COALESCE(ds.D_Setting_Id, h.part_no_text,'') AS part_no,
                                COALESCE(cl.customer,'') AS customer_name
                         FROM pfmea_doc h LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
                         LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                         WHERE h.id=? AND h.is_deleted=0");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['success'=>false,'message'=>'找不到該筆']);
    $st = $db->prepare("SELECT * FROM pfmea_item WHERE doc_id=? AND is_deleted=0 ORDER BY seq");
    $st->execute([$id]);
    $items = array_map('buildItemView', $st->fetchAll(PDO::FETCH_ASSOC));
    $asDoc = eg_asdoc_get($db, 'pfmea');
    $bizDate = substr((string)$doc['created_at'], 0, 10);
    jout([
        'success'=>true, 'doc'=>$doc, 'items'=>$items,
        'company_name'=>eg_company_full_name($db),
        'as_doc_no'=>eg_asdoc_no_asof($db, 'pfmea', $bizDate),
        'as_doc_name'=>$asDoc['doc_name'] ?? 'PFMEA潛在失效模式及效應分析',
    ]);

default:
    jout(['success'=>false,'message'=>'未知動作']);
}
