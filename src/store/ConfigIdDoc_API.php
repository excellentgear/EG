<?php
/**
 * 型態識別文件管制表（AS 文件 RTD630EC0A00）API
 * 資料/權限說明見 src/common/type_id_ctrl_lib.php
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/type_id_ctrl_lib.php';

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']); exit;
}

$db = (new DBConnection())->getPDO();
type_id_ctrl_ensure_schema($db);
$me    = type_id_ctrl_current_user($db);
$perms = type_id_ctrl_perms($db, $me);
$uid   = $me ? (int)$me['id'] : 0;
$uname = $me ? (string)$me['user_cname'] : '';

function jout($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function needView(array $perms) { if (!$perms['canView']) jout(['success'=>false,'message'=>'無檢閱權限']); }
function needEdit(array $perms) { if (!$perms['canEdit']) jout(['success'=>false,'message'=>'無登錄權限']); }
function needAdmin(array $perms) { if (!$perms['canAdmin']) jout(['success'=>false,'message'=>'無管理權限']); }

const TYPE_LABELS = ['drawing'=>'圖面','jig'=>'治夾具','report'=>'報告','other'=>'其他文件'];

/** 組出單筆項目列的顯示資料（即時解析連結，不快照） */
function buildItemView(PDO $db, array $it): array {
    $linked = null;
    if ($it['ref_source'] && $it['ref_attach_id']) {
        $linked = type_id_ctrl_resolve_ref($db, $it['ref_source'], (int)$it['ref_attach_id'], (int)$it['ref_ds_pk']);
    }
    return [
        'id' => (int)$it['id'],
        'seq' => (int)$it['seq'],
        'item_name' => $it['item_name'],
        'item_type' => $it['item_type'],
        'item_type_label' => TYPE_LABELS[$it['item_type']] ?? '其他文件',
        'is_linked' => $linked !== null,
        'ref_source' => $it['ref_source'],
        'ref_attach_id' => $it['ref_attach_id'] ? (int)$it['ref_attach_id'] : null,
        'ref_ds_pk' => $it['ref_ds_pk'] ? (int)$it['ref_ds_pk'] : null,
        'ref_broken' => ($it['ref_source'] && $it['ref_attach_id'] && $linked === null), // 曾連結但來源已消失
        'effective_date' => $linked ? $linked['doc_date'] : $it['manual_effective_date'],
        'doc_no_text' => $linked ? $linked['doc_name'] : $it['manual_doc_no'],
        'file_url' => $linked ? $linked['file_url'] : null,
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'perms':
    jout(['success'=>true,'perms'=>$perms,'user_name'=>$uname]);

case 'list':
    needView($perms);
    $kw = trim((string)($_GET['kw'] ?? ''));
    $sql = "SELECT h.id, h.doc_no, h.customer_id, COALESCE(cl.customer,'') AS customer_name,
                   h.part_d_id, COALESCE(ds.D_Setting_Id,'') AS part_no, h.process_desc,
                   h.created_by_name, h.created_at
            FROM type_id_ctrl_doc h
            LEFT JOIN customer_list cl ON cl.customer_id = h.customer_id
            LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
            WHERE h.is_deleted=0";
    $args = [];
    if ($kw !== '') {
        $sql .= " AND (h.doc_no LIKE ? OR ds.D_Setting_Id LIKE ? OR cl.customer LIKE ?)";
        $like = '%'.$kw.'%'; $args = [$like,$like,$like];
    }
    $sql .= " ORDER BY h.created_at DESC";
    $st = $db->prepare($sql); $st->execute($args);
    jout(['success'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);

case 'get':
    needView($perms);
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT h.*, COALESCE(cl.customer,'') AS customer_name,
                                COALESCE(ds.D_Setting_Id,'') AS part_no
                         FROM type_id_ctrl_doc h
                         LEFT JOIN customer_list cl ON cl.customer_id = h.customer_id
                         LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
                         WHERE h.id=? AND h.is_deleted=0");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['success'=>false,'message'=>'找不到該筆']);
    $st = $db->prepare("SELECT * FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 ORDER BY seq");
    $st->execute([$id]);
    $items = array_map(function($it) use ($db) { return buildItemView($db, $it); }, $st->fetchAll(PDO::FETCH_ASSOC));
    jout(['success'=>true,'doc'=>$doc,'items'=>$items]);

case 'delete_header':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    $db->prepare("UPDATE type_id_ctrl_doc SET is_deleted=1 WHERE id=?")->execute([$id]);
    jout(['success'=>true]);

// ── 整張表頭+項目列一次儲存（交易內完成，避免部分寫入）─────────────
case 'save_all':
    needEdit($perms);
    $id = (int)($_POST['id'] ?? 0);
    $customerId = trim((string)($_POST['customer_id'] ?? ''));
    $partDId = (int)($_POST['part_d_id'] ?? 0);
    $process = trim((string)($_POST['process_desc'] ?? ''));
    if (!$partDId) jout(['success'=>false,'message'=>'請先選擇產品編號(料號)']);
    $itemsRaw = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($itemsRaw)) $itemsRaw = [];

    $db->beginTransaction();
    try {
        if ($id) {
            $st = $db->prepare("SELECT 1 FROM type_id_ctrl_doc WHERE id=? AND is_deleted=0");
            $st->execute([$id]);
            if (!$st->fetchColumn()) throw new Exception('找不到該筆或已刪除');
            $st = $db->prepare("UPDATE type_id_ctrl_doc SET customer_id=?, part_d_id=?, process_desc=?,
                                 updated_at=NOW(), updated_by=?, updated_by_name=? WHERE id=?");
            $st->execute([$customerId ?: null, $partDId, $process ?: null, $uid, $uname, $id]);
        } else {
            $docNo = type_id_ctrl_next_doc_no($db);
            $st = $db->prepare("INSERT INTO type_id_ctrl_doc (doc_no, customer_id, part_d_id, process_desc, created_by, created_by_name)
                                 VALUES (?,?,?,?,?,?)");
            $st->execute([$docNo, $customerId ?: null, $partDId, $process ?: null, $uid, $uname]);
            $id = (int)$db->lastInsertId();
        }

        $st = $db->prepare("SELECT id FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0");
        $st->execute([$id]);
        $existing = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

        $seq = 0;
        foreach ($itemsRaw as $it) {
            $seq++;
            $itemName = trim((string)($it['item_name'] ?? ''));
            $itemType = (string)($it['item_type'] ?? 'other');
            if (!isset(TYPE_LABELS[$itemType])) $itemType = 'other';
            if ($itemName === '') continue; // 空白列不存（比照可增列表格鐵則：沒填東西的列不算）

            $refSource = trim((string)($it['ref_source'] ?? ''));
            $refAttachId = (int)($it['ref_attach_id'] ?? 0);
            $refDsPk = (int)($it['ref_ds_pk'] ?? 0);
            $isLinked = in_array($refSource, ['part','quote'], true) && $refAttachId;
            $manualDate = trim((string)($it['manual_effective_date'] ?? ''));
            $manualDocNo = trim((string)($it['manual_doc_no'] ?? ''));

            $rowId = (int)($it['id'] ?? 0);
            if ($rowId && isset($existing[$rowId])) {
                $st = $db->prepare("UPDATE type_id_ctrl_item SET seq=?, item_name=?, item_type=?,
                                     ref_source=?, ref_attach_id=?, ref_ds_pk=?,
                                     manual_effective_date=?, manual_doc_no=?, updated_at=NOW()
                                     WHERE id=?");
                $st->execute([
                    $seq, $itemName, $itemType,
                    $isLinked ? $refSource : null, $isLinked ? $refAttachId : null, $isLinked ? $refDsPk : null,
                    $isLinked ? null : ($manualDate ?: null), $isLinked ? null : ($manualDocNo ?: null),
                    $rowId,
                ]);
                unset($existing[$rowId]);
            } else {
                $st = $db->prepare("INSERT INTO type_id_ctrl_item
                    (doc_id, seq, item_name, item_type, ref_source, ref_attach_id, ref_ds_pk, manual_effective_date, manual_doc_no)
                    VALUES (?,?,?,?,?,?,?,?,?)");
                $st->execute([
                    $id, $seq, $itemName, $itemType,
                    $isLinked ? $refSource : null, $isLinked ? $refAttachId : null, $isLinked ? $refDsPk : null,
                    $isLinked ? null : ($manualDate ?: null), $isLinked ? null : ($manualDocNo ?: null),
                ]);
            }
        }
        // 前端已移除的列：軟刪除
        if ($existing) {
            $delIds = array_keys($existing);
            $in = implode(',', array_fill(0, count($delIds), '?'));
            $db->prepare("UPDATE type_id_ctrl_item SET is_deleted=1 WHERE id IN ($in)")->execute($delIds);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'儲存失敗：'.$e->getMessage()]); }
    jout(['success'=>true,'id'=>$id]);

// ── 連結外來文件清單（即時查詢，不落地快照；只列已勾 is_external_doc 標籤的附件）──
case 'search_ext_doc':
    needView($perms);
    $dsPk = (int)($_POST['ds_pk'] ?? $_GET['ds_pk'] ?? 0);
    if (!$dsPk) jout(['success'=>true,'rows'=>[]]);
    $cats = $db->query("SELECT id FROM quotation_file_categories WHERE is_external_doc=1")->fetchAll(PDO::FETCH_COLUMN);
    if (!$cats) jout(['success'=>true,'rows'=>[]]);
    $catCond = function(string $col, string $singleCol = '') use ($cats): string {
        $parts = [];
        foreach ($cats as $cid) $parts[] = "FIND_IN_SET($cid, REPLACE(COALESCE($col,''),' ',''))";
        if ($singleCol !== '') $parts[] = "$singleCol IN (" . implode(',', $cats) . ")";
        return '(' . implode(' OR ', $parts) . ')';
    };
    $rows = [];
    // ① 料號附件
    $sql = "SELECT pa.id AS attach_id, pa.d_id AS ds_pk,
                   COALESCE(NULLIF(pa.original_name,''), pa.filename) AS doc_name,
                   DATE(pa.uploaded_at) AS doc_date
            FROM part_attachments pa
            WHERE pa.d_id=? AND pa.deleted_at IS NULL AND " . $catCond('pa.category_ids');
    $st = $db->prepare($sql); $st->execute([$dsPk]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['source']='part'; $rows[]=$r; }
    // ② 報價附件：整張報價單料號皆適用 或 linked_parts 指定此料號字串
    $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
    $st->execute([$dsPk]);
    $partNo = (string)$st->fetchColumn();
    if ($partNo !== '') {
        $sql = "SELECT DISTINCT a.id AS attach_id, ? AS ds_pk,
                       COALESCE(NULLIF(a.original_name,''), a.filename) AS doc_name,
                       DATE(a.uploaded_at) AS doc_date
                FROM quotation_attachments a
                JOIN quotation_item qi ON qi.quote_id = (SELECT quote_id FROM quotation_list WHERE quote_no=a.quote_no)
                WHERE a.status='active' AND " . $catCond('a.category_ids', 'a.category_id') . "
                  AND ((a.linked_parts IS NULL AND qi.d_setting_d_id = ?)
                       OR (a.linked_parts IS NOT NULL AND JSON_CONTAINS(a.linked_parts, JSON_QUOTE(?))))";
        $st = $db->prepare($sql); $st->execute([$dsPk, $dsPk, $partNo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['source']='quote'; $rows[]=$r; }
    }
    jout(['success'=>true,'rows'=>$rows]);

// ── AS 文件編號綁定（本頁自身模板 RTD630EC0A00）──────────────────────
case 'asdoc_list':
    needView($perms);
    jout(['success'=>true,'docs'=>eg_asdoc_list($db)]);

case 'asdoc_get':
    needView($perms);
    jout(['success'=>true,'as_doc'=>eg_asdoc_get($db,'type_id_ctrl')]);

case 'as_doc_save':
    needAdmin($perms);
    $docId = (int)($_POST['doc_id'] ?? 0);
    eg_asdoc_save($db, 'type_id_ctrl', $docId, $uname);
    jout(['success'=>true,'as_doc'=>eg_asdoc_get($db,'type_id_ctrl')]);

case 'print_get':
    needView($perms);
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT h.*, COALESCE(cl.customer,'') AS customer_name,
                                COALESCE(ds.D_Setting_Id,'') AS part_no
                         FROM type_id_ctrl_doc h
                         LEFT JOIN customer_list cl ON cl.customer_id = h.customer_id
                         LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
                         WHERE h.id=? AND h.is_deleted=0");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['success'=>false,'message'=>'找不到該筆']);
    $st = $db->prepare("SELECT * FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 ORDER BY seq");
    $st->execute([$id]);
    $items = array_map(function($it) use ($db) { return buildItemView($db, $it); }, $st->fetchAll(PDO::FETCH_ASSOC));
    $asDoc = eg_asdoc_get($db, 'type_id_ctrl');
    jout([
        'success'=>true, 'doc'=>$doc, 'items'=>$items,
        'company_name'=>type_id_ctrl_company_name($db),
        'as_doc_no'=>eg_asdoc_no_asof($db, 'type_id_ctrl', $doc['created_at'] ? substr($doc['created_at'],0,10) : null),
        'as_doc_name'=>$asDoc['doc_name'] ?? '型態識別文件管制表',
    ]);

default:
    jout(['success'=>false,'message'=>'未知動作']);
}
