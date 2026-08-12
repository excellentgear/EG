<?php
/**
 * 型態識別文件管制表 API（本頁AS文件編號動態綁定，不寫死）
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
        'process_tag' => $it['process_tag'] ?? null,
        'need_process_hint' => !empty($it['need_process_hint']),
        'is_linked' => $linked !== null,
        'is_excluded' => !empty($it['is_excluded']),
        'ref_source' => $it['ref_source'],
        'ref_attach_id' => $it['ref_attach_id'] ? (int)$it['ref_attach_id'] : null,
        'ref_ds_pk' => $it['ref_ds_pk'] ? (int)$it['ref_ds_pk'] : null,
        'ref_broken' => ($it['ref_source'] && $it['ref_attach_id'] && $linked === null), // 曾連結但來源已消失
        'effective_date' => $linked ? $linked['doc_date'] : $it['manual_effective_date'],
        'doc_no_text' => $linked ? $linked['doc_name'] : $it['manual_doc_no'],
        // 列印版：連結列若沒有真正版次、退回顯示檔名時，檔名不算真正的「版別／文件編號」，
        // 列印不印（畫面上仍用 doc_no_text 顯示檔名以利辨識；手動輸入列一律視為真實文件編號）
        'print_doc_no' => ($linked && !empty($linked['doc_no_is_filename'])) ? '' : ($linked ? $linked['doc_name'] : $it['manual_doc_no']),
        'file_url' => $linked ? $linked['file_url'] : null,
    ];
}

const REVIEW_LABELS = ['pending'=>'待確認','confirmed'=>'已確認','needs_recheck'=>'需重新確認'];

/** 文件日期(建立日期)＝最早的有效項目日期；簽章日期＝最新的有效項目日期(排除列/無日期列不計) */
function computeDocDates(array $items): array {
    $dates = [];
    foreach ($items as $it) {
        if ($it['is_excluded'] || empty($it['effective_date'])) continue;
        $dates[] = $it['effective_date'];
    }
    if (!$dates) return ['earliest'=>null, 'latest'=>null];
    sort($dates);
    return ['earliest'=>$dates[0], 'latest'=>$dates[count($dates)-1]];
}

/**
 * 製程摘要（2026-08-12 使用者要求恢復顯示，比照架構改版前表頭的「製程」欄）：架構改版後製程改記在
 * 每一列項目上（可能來自多張報價單匯入、各自不同製程），這裡改成唯讀彙總——取所有未排除項目的
 * 「所屬製程」，去重後合併顯示；共用文件(process_tag留空)不計入。process_tag 本身可能已是
 * GROUP_CONCAT 出的「滾齒+研磨」組合，先拆開再去重，避免同一製程因來源不同重複列出。
 */
function computeProcessSummary(array $items): string {
    $procs = [];
    foreach ($items as $it) {
        if (!empty($it['is_excluded'])) continue;
        $tag = trim((string)($it['process_tag'] ?? ''));
        if ($tag === '') continue;
        foreach (explode('+', $tag) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && !in_array($piece, $procs, true)) $procs[] = $piece;
        }
    }
    return implode('+', $procs);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'perms':
    jout(['success'=>true,'perms'=>$perms,'user_name'=>$uname]);

case 'list':
    needView($perms);
    $kw = trim((string)($_GET['kw'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $sql = "SELECT h.id, h.doc_no, h.customer_id, COALESCE(cl.customer,'') AS customer_name,
                   h.part_d_id, COALESCE(ds.D_Setting_Id,'') AS part_no,
                   h.review_status, h.confirmed_by_name, h.confirmed_at,
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
    if (isset(REVIEW_LABELS[$status])) { $sql .= " AND h.review_status=?"; $args[] = $status; }
    $sql .= " ORDER BY h.created_at DESC";
    $st = $db->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['review_status_label'] = REVIEW_LABELS[$r['review_status']] ?? $r['review_status']; }
    unset($r);
    jout(['success'=>true,'rows'=>$rows]);

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
    $doc['review_status_label'] = REVIEW_LABELS[$doc['review_status']] ?? $doc['review_status'];
    $st = $db->prepare("SELECT * FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 ORDER BY seq");
    $st->execute([$id]);
    $items = array_map(function($it) use ($db) { return buildItemView($db, $it); }, $st->fetchAll(PDO::FETCH_ASSOC));
    $dates = computeDocDates($items);
    jout(['success'=>true,'doc'=>$doc,'items'=>$items,'doc_date_earliest'=>$dates['earliest'],'sign_date_latest'=>$dates['latest'],'process_summary'=>computeProcessSummary($items)]);

case 'delete_header':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    $db->prepare("UPDATE type_id_ctrl_doc SET is_deleted=1 WHERE id=?")->execute([$id]);
    jout(['success'=>true]);

// ── 整張表頭+項目列一次儲存（交易內完成，避免部分寫入）─────────────
// confirm=1：同時完成「確認清單」動作(review_status=>confirmed, confirmed_by/at 記錄目前使用者)
case 'save_all':
    needEdit($perms);
    $id = (int)($_POST['id'] ?? 0);
    $customerId = trim((string)($_POST['customer_id'] ?? ''));
    $partDId = (int)($_POST['part_d_id'] ?? 0);
    $confirm = !empty($_POST['confirm']);
    if (!$partDId) jout(['success'=>false,'message'=>'請先選擇產品編號(料號)']);
    $itemsRaw = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($itemsRaw)) $itemsRaw = [];

    $db->beginTransaction();
    try {
        if ($id) {
            $st = $db->prepare("SELECT 1 FROM type_id_ctrl_doc WHERE id=? AND is_deleted=0");
            $st->execute([$id]);
            if (!$st->fetchColumn()) throw new Exception('找不到該筆或已刪除');
            $st = $db->prepare("UPDATE type_id_ctrl_doc SET customer_id=?, part_d_id=?,
                                 updated_at=NOW(), updated_by=?, updated_by_name=? WHERE id=?");
            $st->execute([$customerId ?: null, $partDId, $uid, $uname, $id]);
        } else {
            $docNo = type_id_ctrl_next_doc_no($db);
            $st = $db->prepare("INSERT INTO type_id_ctrl_doc (doc_no, customer_id, part_d_id, created_by, created_by_name)
                                 VALUES (?,?,?,?,?)");
            $st->execute([$docNo, $customerId ?: null, $partDId, $uid, $uname]);
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

            $processTag = trim((string)($it['process_tag'] ?? ''));
            $needProcessHint = !empty($it['need_process_hint']) ? 1 : 0;
            $refSource = trim((string)($it['ref_source'] ?? ''));
            $refAttachId = (int)($it['ref_attach_id'] ?? 0);
            $refDsPk = (int)($it['ref_ds_pk'] ?? 0);
            $isLinked = in_array($refSource, ['part','quote'], true) && $refAttachId;
            $isExcluded = $isLinked && !empty($it['is_excluded']) ? 1 : 0;
            $manualDate = trim((string)($it['manual_effective_date'] ?? ''));
            $manualDocNo = trim((string)($it['manual_doc_no'] ?? ''));

            $rowId = (int)($it['id'] ?? 0);
            if ($rowId && isset($existing[$rowId])) {
                $st = $db->prepare("UPDATE type_id_ctrl_item SET seq=?, item_name=?, item_type=?, process_tag=?, need_process_hint=?,
                                     ref_source=?, ref_attach_id=?, ref_ds_pk=?, is_excluded=?,
                                     manual_effective_date=?, manual_doc_no=?, updated_at=NOW()
                                     WHERE id=?");
                $st->execute([
                    $seq, $itemName, $itemType, ($processTag !== '' ? $processTag : null), $needProcessHint,
                    $isLinked ? $refSource : null, $isLinked ? $refAttachId : null, $isLinked ? $refDsPk : null, $isExcluded,
                    $isLinked ? null : ($manualDate ?: null), $isLinked ? null : ($manualDocNo ?: null),
                    $rowId,
                ]);
                unset($existing[$rowId]);
            } else {
                $st = $db->prepare("INSERT INTO type_id_ctrl_item
                    (doc_id, seq, item_name, item_type, process_tag, need_process_hint, ref_source, ref_attach_id, ref_ds_pk, is_excluded, manual_effective_date, manual_doc_no)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([
                    $id, $seq, $itemName, $itemType, ($processTag !== '' ? $processTag : null), $needProcessHint,
                    $isLinked ? $refSource : null, $isLinked ? $refAttachId : null, $isLinked ? $refDsPk : null, $isExcluded,
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
        if ($confirm) {
            $db->prepare("UPDATE type_id_ctrl_doc SET review_status='confirmed', confirmed_by=?, confirmed_by_name=?, confirmed_at=NOW() WHERE id=?")
               ->execute([$uid, $uname, $id]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'儲存失敗：'.$e->getMessage()]); }
    jout(['success'=>true,'id'=>$id]);

// ── 連結外來文件清單（即時查詢，不落地快照；含 is_external_doc + 廠內自家出圖已勾選類別）──
case 'search_ext_doc':
    needView($perms);
    $dsPk = (int)($_POST['ds_pk'] ?? $_GET['ds_pk'] ?? 0);
    if (!$dsPk) jout(['success'=>true,'rows'=>[]]);
    jout(['success'=>true,'rows'=>type_id_ctrl_fetch_ext_docs_for_part($db, $dsPk)]);

// ── 從此料號的訂單+報價單帶入製程（type_id_ctrl_process_candidates，2026-08-12 加入報價單來源）──
case 'get_order_process':
    needView($perms);
    $partDId = (int)($_POST['part_d_id'] ?? 0);
    if (!$partDId) jout(['success'=>false,'message'=>'缺少料號']);
    $rows = array_map(function($p){
        return ['process'=>$p['process'], 'order_oo'=>($p['ref_kind'].' '.$p['ref_no']), 'order_date'=>$p['ref_date']];
    }, type_id_ctrl_process_candidates($db, $partDId));
    jout(['success'=>true,'rows'=>array_slice($rows, 0, 10)]);

// ── 選定料號後自動列出此料號目前所有外來文件清單附件（供「新增」跳窗預先帶入項目列）──
case 'fetch_ext_for_part':
    needView($perms);
    $dsPk = (int)($_POST['part_d_id'] ?? $_GET['part_d_id'] ?? 0);
    if (!$dsPk) jout(['success'=>true,'rows'=>[]]);
    $ext = type_id_ctrl_fetch_ext_docs_for_part($db, $dsPk);
    $out = array_map(function($er){
        return [
            'id'=>0, 'seq'=>0,
            'item_name'=> !empty($er['categories']) ? $er['categories'][0] : $er['doc_name'],
            'item_type'=> type_id_ctrl_guess_type($er['categories'] ?? []),
            'process_tag'=> $er['origin_process'] ?? null,
            'need_process_hint'=> !empty($er['need_process']),
            'is_linked'=>true, 'is_excluded'=>false,
            'ref_source'=>$er['source'], 'ref_attach_id'=>(int)$er['attach_id'], 'ref_ds_pk'=>(int)$er['ds_pk'],
            'ref_broken'=>false, 'effective_date'=>$er['doc_date'], 'doc_no_text'=>$er['doc_name'], 'file_url'=>null,
        ];
    }, $ext);
    jout(['success'=>true,'rows'=>$out]);

// ── 依料號自動產生/同步型態識別文件管制表(每料號一份，項目自標所屬製程)────
case 'sync_part':
    needEdit($perms);
    $dsPk = (int)($_POST['part_d_id'] ?? 0);
    if (!$dsPk) jout(['success'=>false,'message'=>'請先選擇料號']);
    $r = type_id_ctrl_sync_part($db, $dsPk);
    if (!$r['doc_id']) jout(['success'=>false,'message'=>'找不到此料號']);
    jout(['success'=>true,'doc_id'=>$r['doc_id'],'is_new'=>$r['is_new'],'added_count'=>$r['added_count']]);

// ── 掃描「外來文件清單有附件、但還沒建立型態識別文件管制表」的料號 ─────────
case 'find_missing_parts':
    needView($perms);
    jout(['success'=>true,'rows'=>type_id_ctrl_find_missing_parts($db)]);

// ── 一鍵批次建立：把掃描出的每個料號都跑一次自動產生/同步 ─────────────
case 'sync_all_missing':
    needEdit($perms);
    $ids = json_decode((string)($_POST['part_ids'] ?? '[]'), true);
    if (!is_array($ids) || !$ids) jout(['success'=>false,'message'=>'沒有可建立的料號']);
    $partCount = 0; $itemCount = 0;
    foreach ($ids as $dsPk) {
        $dsPk = (int)$dsPk;
        if (!$dsPk) continue;
        $r = type_id_ctrl_sync_part($db, $dsPk);
        if ($r['doc_id']) { $partCount++; $itemCount += $r['added_count']; }
    }
    jout(['success'=>true,'part_count'=>$partCount,'item_count'=>$itemCount]);

// ── 廠內「自家出的圖」標籤設定：從 is_own_drawing=1 的類別挑選要納入本模組的 ──────
case 'get_own_drawing_categories':
    needAdmin($perms);
    $rows = $db->query("SELECT id, category_name, type_id_ctrl_include,
                                COALESCE(external_doc_name,'') AS external_doc_name,
                                COALESCE(type_id_ctrl_need_process,0) AS type_id_ctrl_need_process
                         FROM quotation_file_categories WHERE is_own_drawing=1 AND is_active=1
                         ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    jout(['success'=>true,'rows'=>$rows]);

// rows: [{id, included, name, need_process}] — name 沿用既有 external_doc_name 欄位（與外來文件清單
// 共用同一顯示名稱設定，不另開欄位）；need_process 僅供項目列「所屬製程」留空時的視覺提示，不做強制驗證。
case 'save_own_drawing_categories':
    needAdmin($perms);
    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($rows)) $rows = [];
    $db->beginTransaction();
    try {
        $db->exec("UPDATE quotation_file_categories SET type_id_ctrl_include=0 WHERE is_own_drawing=1");
        $st = $db->prepare("UPDATE quotation_file_categories
                             SET type_id_ctrl_include=?, external_doc_name=?, type_id_ctrl_need_process=?
                             WHERE id=? AND is_own_drawing=1");
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if (!$id) continue;
            $name = trim((string)($r['name'] ?? ''));
            $st->execute([
                !empty($r['included']) ? 1 : 0,
                $name !== '' ? $name : null,
                !empty($r['need_process']) ? 1 : 0,
                $id,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'儲存失敗：'.$e->getMessage()]); }
    jout(['success'=>true]);

// 把「廠內圖面標籤設定」目前的顯示名稱／需要顯示製程，套用回已同步進本模組的既有項目列
// （2026-08-12 使用者要求：沒有批次刪除重轉，改名要能直接更新舊資料）
case 'refresh_item_names_by_category':
    needAdmin($perms);
    $r = type_id_ctrl_refresh_synced_item_names($db);
    jout(['success'=>true,'updated_count'=>$r['updated_count'],'affected_docs'=>$r['affected_docs']]);

// ── AS 文件編號綁定（本頁自身模板）────────────────────────────────
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
    $dates = computeDocDates($items);
    $asDoc = eg_asdoc_get($db, 'type_id_ctrl');
    // 版次依業務日期回推：業務日期優先用「建立日期(最早外來文件日期)」，沒有則退回DB建立時間（ai-rules/16第三之四節）
    $bizDate = $dates['earliest'] ?: substr((string)$doc['created_at'], 0, 10);
    jout([
        'success'=>true, 'doc'=>$doc, 'items'=>$items,
        'doc_date_earliest'=>$dates['earliest'], 'sign_date_latest'=>$dates['latest'],
        'process_summary'=>computeProcessSummary($items),
        'company_name'=>type_id_ctrl_company_name($db),
        'as_doc_no'=>eg_asdoc_no_asof($db, 'type_id_ctrl', $bizDate),
        'as_doc_name'=>$asDoc['doc_name'] ?? '型態識別文件管制表',
    ]);

default:
    jout(['success'=>false,'message'=>'未知動作']);
}
