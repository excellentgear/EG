<?php
/**
 * PFMEA 潛在失效模式及效應分析（AS 3-TD-01-02）API
 * 資料/權限說明見 src/common/pfmea_lib.php
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';
include_once $document_root . '/EGsystem/src/common/pfmea_lib.php';
include_once $document_root . '/EGsystem/src/common/pfmea_suggest_lib.php';
include_once $document_root . '/EGsystem/src/common/pfmea_reference_lib.php';
include_once $document_root . '/EGsystem/src/common/gear_spec_lib.php';
// type_id_ctrl_process_candidates()：此料號的訂單/報價製程紀錄，跟型態識別文件管制表共用同一套來源，
// 不重寫一份（2026-08-13 使用者要求跳窗右側即時顯示此料號所有訂單製程）
include_once $document_root . '/EGsystem/src/common/type_id_ctrl_lib.php';
// td_dev_eval_default_product_name_get()：產品名稱沿用產品開發評估表同一個全域預設值設定，不另建一份
include_once $document_root . '/EGsystem/src/common/td_dev_eval_lib.php';
// td_dev_eval_suggest_part_reference()：業務日期建議(BOM/報工/訂單最早日期)沿用建議建立清單同一支查詢，不重寫
include_once $document_root . '/EGsystem/src/common/td_dev_eval_suggest_lib.php';

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
        'recommended_actions' => $it['recommended_actions'], 'target_date' => $it['target_date'],
        'action_date' => $it['action_date'],
        'new_severity' => $ns, 'new_occurrence' => $no, 'new_detection' => $nd, 'new_rpn' => $newRpn,
        'prevention_controls' => $it['prevention_controls'], 'detection_controls' => $it['detection_controls'],
    ];
}

/** 官方紙本表單(F-11210-UE2-0001)固定的「相關部門」勾選清單，非逐份填寫可自訂的內容 */
// PFMEA_DEPT_LIST_LIB 定義於 pfmea_lib.php（單一來源，pfmea_dept_defaults_save 也要用）
const PFMEA_DEPT_LIST = PFMEA_DEPT_LIST_LIB;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'perms':
    jout(['success'=>true,'perms'=>$perms,'user_name'=>$uname]);

case 'list':
    needView($perms);
    $kw = trim((string)($_GET['kw'] ?? ''));
    $sql = "SELECT h.id, h.doc_no, h.part_d_id, COALESCE(ds.D_Setting_Id, h.part_no_text,'') AS part_no,
                   COALESCE(cl.customer,'') AS customer_name, h.created_by_name, h.created_at, h.biz_date, h.spec_desc,
                   (SELECT COUNT(*) FROM pfmea_print_log pl WHERE pl.doc_id=h.id) AS print_count,
                   (SELECT MAX(pl.printed_at) FROM pfmea_print_log pl WHERE pl.doc_id=h.id) AS last_printed_at,
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
        /* 規格描述（2026-08-18 使用者回報：跳窗看得到、清單卻顯示未建立）：
           pfmea_doc.spec_desc 只有「存過檔」才有值，批次建立的單據是空的；但跳窗開起來時是即時
           從料號主檔偵測齒輪規格帶出來的。清單若只讀本表就會誤判成未建立，故本表沒有時回退查
           主檔，並標記 spec_from_master 讓畫面說明它還沒存進這張表。 */
        if ((trim((string)($r['spec_desc'] ?? '')) === '') && !empty($r['part_d_id'])) {
            $ms = eg_gear_spec_for_part($db, (int)$r['part_d_id']);
            if ($ms !== null && trim($ms) !== '') { $r['spec_desc'] = $ms; $r['spec_from_master'] = 1; }
        }
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
    jout(['success'=>true,'doc'=>$doc,'items'=>$items,'revisions'=>pfmea_revision_list($db, $id)]);

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
    $bizDate = trim((string)($_POST['biz_date'] ?? ''));
    $itemsRaw = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($itemsRaw)) $itemsRaw = [];
    $newRevision = !empty($_POST['new_revision']); // 既有文件修改存檔時，使用者確認要記為新版本才會傳true

    $db->beginTransaction();
    try {
        $isNew = !$id;
        if ($id) {
            $st = $db->prepare("SELECT 1 FROM pfmea_doc WHERE id=? AND is_deleted=0");
            $st->execute([$id]);
            if (!$st->fetchColumn()) throw new Exception('找不到該筆或已刪除');
            $st = $db->prepare("UPDATE pfmea_doc SET part_d_id=?, part_no_text=?, item_type=?, spec_desc=?, product_name=?, related_depts=?, biz_date=?,
                                 updated_at=NOW(), updated_by=?, updated_by_name=? WHERE id=?");
            $st->execute([$partDId ?: null, $partNoText ?: null, $itemType, $specDesc ?: null, $productName ?: null, $relatedDepts ?: null, $bizDate ?: null, $uid, $uname, $id]);
            // 業務日期改了，表單編號跟著重編（使用者 2026-08-20 拍板）
            pfmea_sync_doc_no($db, $id, $bizDate);
        } else {
            $docNo = pfmea_next_doc_no($db, $bizDate); // 編號依業務日期(2026-08-20)
            $st = $db->prepare("INSERT INTO pfmea_doc (doc_no, part_d_id, part_no_text, item_type, spec_desc, product_name, related_depts, biz_date, created_by, created_by_name)
                                 VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$docNo, $partDId ?: null, $partNoText ?: null, $itemType, $specDesc ?: null, $productName ?: null, $relatedDepts ?: null, $bizDate ?: null, $uid, $uname]);
            $id = (int)$db->lastInsertId();
        }
        // 修訂履歷：第一次存檔一律記「新增文件」；既有文件只有使用者確認要記為新版本才加「修改文件」
        // 一列，避免每次小幅調整都讓版次一直往上跳（2026-08-13 使用者明確要求）
        if ($isNew) { pfmea_revision_add($db, $id, '新增文件', $uname, $bizDate); }
        else {
            // 業務日期可能被改過，第一列「新增文件」要跟著走；修改文件各列不動
            pfmea_revision_sync_first_date($db, $id, $bizDate);
            if ($newRevision) pfmea_revision_add($db, $id, '修改文件', $uname);
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
                trim((string)($it['recommended_actions'] ?? '')) ?: null,
                trim((string)($it['target_date'] ?? '')) ?: null,
                trim((string)($it['action_date'] ?? '')) ?: null,
                pfmea_clamp_rating($it['new_severity'] ?? null), pfmea_clamp_rating($it['new_occurrence'] ?? null), pfmea_clamp_rating($it['new_detection'] ?? null),
                trim((string)($it['prevention_controls'] ?? '')) ?: null, trim((string)($it['detection_controls'] ?? '')) ?: null,
            ];

            $rowId = (int)($it['id'] ?? 0);
            if ($rowId && isset($existing[$rowId])) {
                $st = $db->prepare("UPDATE pfmea_item SET seq=?, process_code=?, process_desc=?, function_desc=?, requirement=?,
                    failure_mode=?, failure_effect=?, severity=?, classification=?, failure_cause=?, occurrence=?,
                    detection=?, recommended_actions=?, target_date=?,
                    action_date=?, new_severity=?, new_occurrence=?, new_detection=?,
                    prevention_controls=?, detection_controls=?, updated_at=NOW()
                    WHERE id=?");
                $st->execute(array_merge([$seq], $vals, [$rowId]));
                unset($existing[$rowId]);
            } else {
                $st = $db->prepare("INSERT INTO pfmea_item
                    (doc_id, seq, process_code, process_desc, function_desc, requirement, failure_mode, failure_effect, severity,
                     classification, failure_cause, occurrence, detection, recommended_actions,
                     target_date, action_date, new_severity, new_occurrence, new_detection,
                     prevention_controls, detection_controls)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
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
    // 來源可複選（dev_eval＝產品開發評估表／project＝有專案但未建 PFMEA）；沒帶就維持原本的行為
    $src = trim((string)($_GET['sources'] ?? $_POST['sources'] ?? ''));
    $srcArr = $src === '' ? ['dev_eval'] : array_map('trim', explode(',', $src));
    jout(['success'=>true,'rows'=>pfmea_suggest_candidates_multi($db, $srcArr)]);

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

// 製程主檔同步/開放使用設定（2026-08-14使用者要求）：製程代號改從process_no/process_type同步，
// 管理員逐一或整個大項分類批次決定哪些開放給PFMEA使用
case 'process_sync_master':
    needAdmin($perms);
    jout(['success'=>true] + pfmea_process_sync_from_master($db, $uid, $uname));

case 'process_list_all':
    needAdmin($perms);
    jout(['success'=>true, 'rows'=>pfmea_process_list_all($db)]);

case 'process_enable_configured':
    needAdmin($perms);
    jout(['success'=>true, 'enabled'=>pfmea_process_enable_configured($db)]);

case 'process_set_enabled':
    needAdmin($perms);
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids)) $ids = [];
    pfmea_process_set_enabled($db, array_map('intval', $ids), !empty($_POST['enabled']));
    jout(['success'=>true]);

case 'process_set_enabled_by_type':
    needAdmin($perms);
    $typeId = (int)($_POST['master_type_id'] ?? 0);
    if (!$typeId) jout(['success'=>false,'message'=>'缺少大項分類id']);
    pfmea_process_set_enabled_by_type($db, $typeId, !empty($_POST['enabled']));
    jout(['success'=>true]);

case 'process_rename':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['process_name'] ?? ''));
    if (!$id || $name === '') jout(['success'=>false,'message'=>'缺少id或名稱']);
    pfmea_process_rename($db, $id, $name);
    jout(['success'=>true]);

// 潛在失效模式：2026-08-13使用者要求改階層式，優先套用功能層級專屬清單，該層級還沒人填過才逐層
// 退回項目層級、製程層級(舊148筆通用清單)——item_option_id/function_option_id為0時等同純製程層級查詢
case 'ref_failure_mode_list':
    needView($perms);
    $pid = (int)($_GET['process_id'] ?? 0);
    $itemOptId = (int)($_GET['item_option_id'] ?? 0);
    $funcOptId = (int)($_GET['function_option_id'] ?? 0);
    if (!$pid) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>pfmea_ref_failure_mode_list($db, $pid, $itemOptId, $funcOptId)]);

case 'ref_failure_mode_add':
    needEdit($perms);
    $pid = (int)($_POST['process_id'] ?? 0);
    $itemOptId = (int)($_POST['item_option_id'] ?? 0);
    $funcOptId = (int)($_POST['function_option_id'] ?? 0);
    $text = trim((string)($_POST['failure_mode'] ?? ''));
    if (!$pid || $text === '') jout(['success'=>false,'message'=>'缺少製程或失效模式文字']);
    $id = pfmea_ref_failure_mode_add($db, $pid, $text, $uid, $uname, $itemOptId, $funcOptId);
    jout(['success'=>true,'id'=>$id]);

case 'ref_failure_mode_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_ref_failure_mode_delete($db, $id);
    jout(['success'=>true]);

// 參考資料設定畫面(2026-08-14使用者要求)專用：只回傳確切層級的資料，不做退回帶入
case 'ref_failure_mode_list_exact':
    needAdmin($perms);
    jout(['success'=>true,'rows'=>pfmea_ref_failure_mode_list_exact($db, (int)($_GET['process_id']??0), (int)($_GET['item_option_id']??0), (int)($_GET['function_option_id']??0))]);

case 'ref_requirement_list_exact':
    needAdmin($perms);
    jout(['success'=>true,'rows'=>pfmea_ref_requirement_list_exact($db, (int)($_GET['function_option_id']??0), (int)($_GET['process_id']??0), (int)($_GET['item_option_id']??0))]);

// ── 料號綁定的製程／項目／功能組合（2026-08-18 使用者拍板：料號只在「要求」分岔）──────────
// 依 BOM 帶入失效模式分析（2026-08-18 使用者要求）
case 'bom_process_candidates':
    needEdit($perms);
    jout(['success'=>true,'rows'=>pfmea_part_bom_processes($db, (int)($_GET['part_d_id'] ?? 0),
          (string)($_GET['part_no'] ?? ''), (string)($_GET['customer_name'] ?? ''))]);

case 'templates_of_processes':
    needEdit($perms);
    $ids = array_filter(array_map('intval', explode(',', (string)($_GET['process_ids'] ?? ''))));
    jout(['success'=>true,'rows'=>pfmea_templates_of_processes($db, $ids)]);

case 'part_binding_list':
    needView($perms);
    jout(['success'=>true,'rows'=>pfmea_part_binding_list($db, (int)($_GET['part_d_id']??0), (string)($_GET['part_no_text']??''))]);

case 'part_binding_candidates':
    needAdmin($perms);
    jout(['success'=>true,'rows'=>pfmea_part_binding_candidates($db, (int)($_GET['process_id']??0), (int)($_GET['part_d_id']??0), (string)($_GET['part_no_text']??''))]);

case 'part_binding_add':
    needAdmin($perms);
    $partDId = (int)($_POST['part_d_id'] ?? 0);
    $partText = trim((string)($_POST['part_no_text'] ?? ''));
    $procId = (int)($_POST['process_id'] ?? 0);
    if ((!$partDId && $partText === '') || !$procId) jout(['success'=>false,'message'=>'缺少料號或製程']);
    // paths：一次可勾多組路徑快速建立，格式 [{item_option_id, function_option_id}, ...]
    $paths = json_decode((string)($_POST['paths'] ?? '[]'), true);
    if (!is_array($paths) || !$paths) $paths = [['item_option_id'=>0, 'function_option_id'=>0]];
    $ids = [];
    $db->beginTransaction();
    try {
        foreach ($paths as $pt) {
            $bid = pfmea_part_binding_add($db, $partDId, $partText, $procId, (int)($pt['item_option_id']??0), (int)($pt['function_option_id']??0), $uid, $uname);
            if ($bid) $ids[] = $bid;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'綁定失敗：'.$e->getMessage()]); }
    jout(['success'=>true,'ids'=>$ids,'count'=>count($ids)]);

case 'part_binding_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_part_binding_delete($db, $id);
    jout(['success'=>true]);

case 'part_binding_requirement_add':
    needAdmin($perms);
    $bindId = (int)($_POST['bind_id'] ?? 0);
    $text = trim((string)($_POST['requirement_text'] ?? ''));
    if (!$bindId || $text === '') jout(['success'=>false,'message'=>'缺少綁定組合或要求文字']);
    $id = pfmea_part_binding_requirement_add($db, $bindId, $text, $uid, $uname);
    if (!$id) jout(['success'=>false,'message'=>'找不到該綁定組合']);
    jout(['success'=>true,'id'=>$id]);

// ── 料號-製程-項目-功能-要求 階層式連動（2026-08-13使用者要求）──────────────────
case 'ref_item_options_list':
    needView($perms);
    $pid = (int)($_GET['process_id'] ?? 0);
    if (!$pid) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>pfmea_ref_item_options($db, $pid)]);

case 'ref_item_option_add':
    needEdit($perms);
    $pid = (int)($_POST['process_id'] ?? 0);
    $name = trim((string)($_POST['item_name'] ?? ''));
    if (!$pid || $name === '') jout(['success'=>false,'message'=>'缺少製程或項目名稱']);
    $id = pfmea_ref_item_option_get_or_add($db, $pid, $name, $uid, $uname);
    jout(['success'=>true,'id'=>$id]);

case 'ref_item_option_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_ref_item_option_delete($db, $id);
    jout(['success'=>true]);

case 'ref_function_options_list':
    needView($perms);
    $itemOptId = (int)($_GET['item_option_id'] ?? 0);
    if (!$itemOptId) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>pfmea_ref_function_options($db, $itemOptId)]);

case 'ref_function_option_add':
    needEdit($perms);
    $itemOptId = (int)($_POST['item_option_id'] ?? 0);
    $desc = trim((string)($_POST['function_desc'] ?? ''));
    if (!$itemOptId || $desc === '') jout(['success'=>false,'message'=>'缺少項目或功能文字']);
    $id = pfmea_ref_function_option_get_or_add($db, $itemOptId, $desc, $uid, $uname);
    jout(['success'=>true,'id'=>$id]);

case 'ref_function_option_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_ref_function_option_delete($db, $id);
    jout(['success'=>true]);

// 要求：依綁定的功能+料號查，優先給該料號在功能層級的專屬要求，逐層退回功能通用/製程層級(較粗，
// 沒有功能細分資料時用，如製作表單.xlsm匯入的舊資料)
case 'ref_requirement_options_list':
    needView($perms);
    $funcOptId = (int)($_GET['function_option_id'] ?? 0);
    $procId = (int)($_GET['process_id'] ?? 0);
    $partDId = (int)($_GET['part_d_id'] ?? 0);
    $partText = trim((string)($_GET['part_no_text'] ?? ''));
    $itemOptId = (int)($_GET['item_option_id'] ?? 0);
    if (!$funcOptId && !$procId && !$itemOptId) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>pfmea_ref_requirement_options($db, $funcOptId, $partDId, $partText, $procId, $itemOptId)]);

case 'ref_requirement_option_add':
    needEdit($perms);
    $funcOptId = (int)($_POST['function_option_id'] ?? 0);
    $procId = (int)($_POST['process_id'] ?? 0);
    $partDId = (int)($_POST['part_d_id'] ?? 0);
    $partText = trim((string)($_POST['part_no_text'] ?? ''));
    $text = trim((string)($_POST['requirement_text'] ?? ''));
    $itemOptId = (int)($_POST['item_option_id'] ?? 0);
    if ((!$funcOptId && !$procId && !$itemOptId) || $text === '') jout(['success'=>false,'message'=>'缺少功能/項目/製程或要求文字']);
    $id = pfmea_ref_requirement_option_add($db, $funcOptId, $partDId, $partText, $text, $uid, $uname, $procId, $itemOptId);
    jout(['success'=>true,'id'=>$id]);

case 'ref_requirement_option_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_ref_requirement_option_delete($db, $id);
    jout(['success'=>true]);

// ── 平面表格編輯器（2026-08-18 使用者回饋鑽取式難用、Excel 比較好用）────────────────
case 'flat_list':
    needAdmin($perms);
    jout(['success'=>true, 'rows'=>pfmea_flat_list($db), 'tree'=>pfmea_flat_tree($db)]);

case 'flat_save':
    needAdmin($perms);
    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($rows)) jout(['success'=>false,'message'=>'資料格式錯誤']);
    if (!$rows) jout(['success'=>false,'message'=>'沒有要儲存的變更']);
    $r = pfmea_flat_save($db, $rows, $uid, $uname);
    if (empty($r['ok'])) jout(['success'=>false,'message'=>'儲存失敗：'.implode('；', $r['errors'])]);
    jout(['success'=>true] + $r);

case 'flat_delete_row':
    needAdmin($perms);
    $fmId = (int)($_POST['fm_id'] ?? 0);
    if (!$fmId) jout(['success'=>false,'message'=>'缺少列 id']);
    pfmea_flat_delete_row($db, $fmId);
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

// 整組樣板新增/編輯（2026-08-14使用者要求：參考資料設定畫面要能新增+編輯，不只查看/刪除）
case 'ref_item_template_save':
    needAdmin($perms);
    $tplId = (int)($_POST['id'] ?? 0);
    $pid = (int)($_POST['process_id'] ?? 0);
    if (!$pid) jout(['success'=>false,'message'=>'缺少製程']);
    $data = json_decode((string)($_POST['data'] ?? '{}'), true);
    if (!is_array($data)) $data = [];
    $newId = pfmea_ref_item_template_save($db, $tplId, $pid, $data, $uid, $uname);
    jout(['success'=>true,'id'=>$newId]);

// 欄位個別設定對應（2026-08-14使用者要求）：潛在失效模式->失效模式潛在後果/分類/失效潛在原因、
// 產品名稱->規格描述等，任一欄位值都能設定對應到另一欄位的建議值
case 'field_link_list':
    needView($perms);
    jout(['success'=>true,'rows'=>pfmea_field_link_list($db, (string)($_GET['source_field']??''), (string)($_GET['source_value']??''), (string)($_GET['target_field']??''), (int)($_GET['scope_fm_id']??0))]);

// 設定畫面用：只看剛好掛在這一筆潛在失效模式底下的對應，不退回舊的全域文字對應
case 'field_link_list_exact':
    needAdmin($perms);
    $fmId = (int)($_GET['scope_fm_id'] ?? 0);
    if (!$fmId) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>pfmea_field_link_list_exact($db, $fmId, (string)($_GET['target_field']??''))]);

case 'field_link_distinct_sources':
    needAdmin($perms);
    jout(['success'=>true,'rows'=>pfmea_field_link_distinct_sources($db, (string)($_GET['source_field']??''), (string)($_GET['target_field']??''))]);

case 'field_link_all_failure_modes':
    needAdmin($perms);
    jout(['success'=>true,'rows'=>pfmea_field_link_all_failure_modes($db, (string)($_GET['target_field']??'failure_effect'))]);

case 'field_link_backfill':
    needAdmin($perms);
    jout(['success'=>true] + pfmea_field_link_backfill_from_templates($db, $uid, $uname));

case 'qc_special_characteristics_list':
    needAdmin($perms);
    jout(['success'=>true,'rows'=>pfmea_qc_special_characteristics($db)]);

// 要求資料總覽/重新綁定料號（2026-08-14使用者要求）
case 'requirement_option_list_all':
    needAdmin($perms);
    jout(['success'=>true,'rows'=>pfmea_requirement_option_list_all($db)]);

case 'requirement_option_rebind':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_requirement_option_rebind($db, $id, (int)($_POST['part_d_id']??0), (string)($_POST['part_no_text']??''));
    jout(['success'=>true]);

case 'requirement_option_update_text':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    $text = trim((string)($_POST['requirement_text'] ?? ''));
    if (!$id || $text === '') jout(['success'=>false,'message'=>'缺少id或要求文字']);
    pfmea_requirement_option_update_text($db, $id, $text);
    jout(['success'=>true]);

case 'field_link_add':
    needEdit($perms);
    $sf = (string)($_POST['source_field'] ?? ''); $sv = (string)($_POST['source_value'] ?? '');
    $tf = (string)($_POST['target_field'] ?? ''); $tv = (string)($_POST['target_value'] ?? '');
    $scopeFmId = (int)($_POST['scope_fm_id'] ?? 0);
    // 掛層級時 source_value 可省（由失效模式那筆推導），只有純全域文字對應才一定要 source_value
    if ($tf==='' || $tv==='' || ($sf==='' && !$scopeFmId) || ($sv==='' && !$scopeFmId)) jout(['success'=>false,'message'=>'缺少必要參數']);
    $id = pfmea_field_link_add($db, $sf, $sv, $tf, $tv, $uid, $uname, $scopeFmId);
    jout(['success'=>true,'id'=>$id]);

case 'field_link_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_field_link_delete($db, $id);
    jout(['success'=>true]);

// 評價S/O/D建議規則（2026-08-14使用者要求第7段）：依製程+項目+功能+潛在失效模式+失效模式潛在
// 效果+嚴重度+失效潛在原因完整組合查/存建議評價值，只給新增列自動帶入用
case 'rating_rule_lookup':
    needView($perms);
    $pid = (int)($_GET['process_id'] ?? 0);
    if (!$pid) jout(['success'=>true,'rule'=>null]);
    $rule = pfmea_rating_rule_lookup($db, $pid, (int)($_GET['item_option_id']??0), (int)($_GET['function_option_id']??0),
        (string)($_GET['failure_mode']??''), (string)($_GET['failure_effect']??''), (int)($_GET['severity']??0), (string)($_GET['failure_cause']??''));
    jout(['success'=>true,'rule'=>$rule]);

case 'rating_rule_add':
    needEdit($perms);
    $pid = (int)($_POST['process_id'] ?? 0);
    if (!$pid) jout(['success'=>false,'message'=>'缺少製程']);
    $id = pfmea_rating_rule_add($db, $pid, (int)($_POST['item_option_id']??0), (int)($_POST['function_option_id']??0),
        (string)($_POST['failure_mode']??''), (string)($_POST['failure_effect']??''), (int)($_POST['severity']??0), (string)($_POST['failure_cause']??''),
        (int)($_POST['new_severity']??0), (int)($_POST['new_occurrence']??0), (int)($_POST['new_detection']??0), $uid, $uname);
    jout(['success'=>true,'id'=>$id]);

case 'rating_rule_delete':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    pfmea_rating_rule_delete($db, $id);
    jout(['success'=>true]);

// ── 各種自動帶入（2026-08-13 使用者要求）──────────────────────────────
// 規格描述：沿用 NewOrder_Track.php 料號下方顯示的齒輪規格邏輯，查無資料回傳空字串(前端不覆蓋)
case 'gear_spec_get':
    needView($perms);
    $partDId = (int)($_POST['part_d_id'] ?? $_GET['part_d_id'] ?? 0);
    jout(['success'=>true,'spec'=>eg_gear_spec_for_part($db, $partDId) ?? '']);

// 產品名稱：沿用產品開發評估表(td_dev_eval)同一個「產品名稱預設值」設定——這是全部產品通用的單一
// 預設值(非特定料號)，2026-08-13使用者已在td_dev_eval.php更正過這個機制，PFMEA直接共用同一組設定
case 'product_name_get':
    needView($perms);
    jout(['success'=>true,'product_name'=>td_dev_eval_default_product_name_get($db) ?? '']);

// 相關部門預設勾選值（管理員設定，新增文件時自動帶入）
case 'classify_rule_get':
    needView($perms);
    jout(['success'=>true,'rule'=>pfmea_classify_rule_get($db)]);

case 'classify_rule_save':
    needAdmin($perms);
    pfmea_classify_rule_save($db, $_POST, $uid);
    jout(['success'=>true,'rule'=>pfmea_classify_rule_get($db)]);

case 'dept_defaults_get':
    needView($perms);
    jout(['success'=>true,'depts'=>pfmea_dept_defaults_get($db)]);

case 'dept_defaults_save':
    needAdmin($perms);
    $depts = json_decode((string)($_POST['depts'] ?? '[]'), true);
    if (!is_array($depts)) $depts = [];
    pfmea_dept_defaults_save($db, $depts, $uid);
    jout(['success'=>true]);

// 業務日期建議：手動建立的紀錄綁定料號後，比照 td_dev_eval_suggest.php 既有的「建議建立日期」機制
// (套用BOM日期／套用最早報工日期／套用最早訂單日期)，直接共用同一支查詢函式，不重寫一份
case 'biz_date_suggest':
    needView($perms);
    $partDId = (int)($_POST['part_d_id'] ?? $_GET['part_d_id'] ?? 0);
    $partText = trim((string)($_POST['part_no_text'] ?? $_GET['part_no_text'] ?? ''));
    $custName = trim((string)($_POST['customer_name'] ?? $_GET['customer_name'] ?? ''));
    jout(['success'=>true,'ref'=>td_dev_eval_suggest_part_reference($db, $partDId ?: null, $partText, $custName)]);

// 此料號所有訂單/報價製程紀錄，跳窗右側即時顯示方便對照填寫（跟型態識別文件管制表共用同一套來源）
case 'order_process_list':
    needView($perms);
    $partDId = (int)($_POST['part_d_id'] ?? $_GET['part_d_id'] ?? 0);
    if (!$partDId) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>type_id_ctrl_process_candidates($db, $partDId)]);

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

case 'print_log_add':
    needView($perms);
    pfmea_print_log_add($db, (int)($_POST['id'] ?? 0), (string)($_POST['kind'] ?? 'single'), $uid, $uname);
    jout(['success'=>true]);

case 'print_log_list':
    needView($perms);
    jout(['success'=>true,'rows'=>pfmea_print_log_list($db, (int)($_GET['id'] ?? 0))]);

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
    // 版次依業務日期回推(ai-rules/16第三之四節)：優先用biz_date，沒有才退回created_at(舊資料/尚未填業務日期)
    $bizDate = substr((string)($doc['biz_date'] ?: $doc['created_at']), 0, 10);
    jout([
        'success'=>true, 'doc'=>$doc, 'items'=>$items,
        'revisions'=>pfmea_revision_list($db, $id),
        'company_name'=>eg_company_full_name($db),
        'as_doc_no'=>eg_asdoc_no_asof($db, 'pfmea', $bizDate),
        'as_doc_name'=>$asDoc['doc_name'] ?? 'PFMEA潛在失效模式及效應分析',
    ]);

default:
    jout(['success'=>false,'message'=>'未知動作']);
}
