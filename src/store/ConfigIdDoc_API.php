<?php
/**
 * 型態識別文件管制表 API（本頁AS文件編號動態綁定，不寫死）
 * 資料/權限說明見 src/common/type_id_ctrl_lib.php
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/type_id_ctrl_lib.php';
require_once $document_root . '/EGsystem/src/common/print_log_lib.php';   // 列印紀錄（ai-rules/23）

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']); exit;
}

$db = (new DBConnection())->getPDO();
type_id_ctrl_ensure_schema($db);
eg_print_log_ensure_schema($db);   // list 動作直接查 print_log（最後列印時間/列印狀態篩選），要先確保表存在
$me    = type_id_ctrl_current_user($db);
$perms = type_id_ctrl_perms($db, $me);
$uid   = $me ? (int)$me['id'] : 0;
$uname = $me ? (string)$me['user_cname'] : '';

function jout($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function needView(array $perms) { if (!$perms['canView']) jout(['success'=>false,'message'=>'無檢閱權限']); }
function needEdit(array $perms) { if (!$perms['canEdit']) jout(['success'=>false,'message'=>'無登錄權限']); }
function needAdmin(array $perms) { if (!$perms['canAdmin']) jout(['success'=>false,'message'=>'無管理權限']); }
function needBatchUpdate(array $perms) { if (empty($perms['canBatchUpdate'])) jout(['success'=>false,'message'=>'無批次更新權限，請洽管理者指派「批次更新權限」角色']); }

/* 型態類別與連結來源的顯示標籤：唯一登記處已移到 src/common/type_id_ctrl_lib.php
   （2026-09-22 內部稽核的「產品型態稽核表」也要照同一份標籤把管制表的項目列帶進查檢表，
   兩邊各留一份遲早走鐘＝鐵律4）。這裡只保留原本的常數名稱，讓本檔既有的呼叫端一行都不必改。 */
const TYPE_LABELS   = TIC_TYPE_LABELS;
const SOURCE_LABELS = TIC_SOURCE_LABELS;

/** 組出單筆項目列的顯示資料（即時解析連結，不快照）——實作在共用庫，這裡只是薄包裝 */
function buildItemView(PDO $db, array $it): array { return type_id_ctrl_item_view($db, $it); }

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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'perms':
    jout(['success'=>true,'perms'=>$perms,'user_name'=>$uname]);

case 'list':
    needView($perms);
    $kw = trim((string)($_GET['kw'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $customerKw = trim((string)($_GET['customer'] ?? ''));
    $partKw = trim((string)($_GET['part_no'] ?? ''));
    // PFMEA 是否已為此料號建檔（2026-08-19 使用者要求：清單要看得到、也要能篩選）
    $hasPf   = type_id_ctrl_pfmea_table_exists($db);
    $pfWhere = "SELECT 1 FROM pfmea_doc pf WHERE pf.is_deleted=0 AND pf.part_d_id=h.part_d_id";
    $pfSel   = $hasPf ? "(SELECT COUNT(*) FROM pfmea_doc pf WHERE pf.is_deleted=0 AND pf.part_d_id=h.part_d_id)" : "0";
    // 內容項次筆數（2026-08-19 使用者要求）：只算「有資料」的項目列＝未排除，且已連結來源文件或手動填了文件編號/生效日期；
    // 另回傳項目列總數供提示用（只有名稱、還沒帶到文件的空列不計入 filled）
    $itemBase   = "FROM type_id_ctrl_item ti WHERE ti.doc_id=h.id AND ti.is_deleted=0";
    $itemFilled = "(SELECT COUNT(*) $itemBase AND ti.is_excluded=0
                      AND ( (ti.ref_source IS NOT NULL AND ti.ref_source<>'' AND COALESCE(ti.ref_attach_id,0)>0)
                            OR (ti.ref_source='bomfile' AND ti.ref_file_name IS NOT NULL AND ti.ref_file_name<>'')
                            OR (ti.manual_doc_no IS NOT NULL AND ti.manual_doc_no<>'')
                            OR ti.manual_effective_date IS NOT NULL ))";
    $itemTotal  = "(SELECT COUNT(*) $itemBase)";
    // 最後列印時間（ai-rules/23，2026-09-24 使用者要求）：直接讀共用 print_log，不另存一份；
    // ref_table/ref_id 已補了索引（print_log_lib.php），MAX() 對單一 ref 是索引查找不是整表掃。
    $printedSel = "(SELECT MAX(printed_at) FROM print_log WHERE source='type_id_ctrl' AND ref_table='type_id_ctrl_doc' AND ref_id=h.id)";
    $sql = "SELECT h.id, h.doc_no, h.customer_id, COALESCE(cl.customer,'') AS customer_name,
                   h.part_d_id, COALESCE(ds.D_Setting_Id,'') AS part_no,
                   h.review_status, h.confirmed_by_name, h.confirmed_at,
                   h.created_by_name, h.created_at, $pfSel AS pfmea_count,
                   $itemFilled AS item_filled_count, $itemTotal AS item_total_count,
                   $printedSel AS last_printed_at
            FROM type_id_ctrl_doc h
            LEFT JOIN customer_list cl ON cl.customer_id = h.customer_id
            LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
            WHERE h.is_deleted=0";
    $args = [];
    if ($kw !== '') {
        $sql .= " AND (h.doc_no LIKE ? OR ds.D_Setting_Id LIKE ? OR cl.customer LIKE ?)";
        $like = '%'.$kw.'%'; $args[] = $like; $args[] = $like; $args[] = $like;
    }
    if ($customerKw !== '') {
        $sql .= " AND (cl.customer_id LIKE ? OR cl.customer LIKE ?)";
        $like = '%'.$customerKw.'%'; $args[] = $like; $args[] = $like;
    }
    if ($partKw !== '') {
        $sql .= " AND ds.D_Setting_Id LIKE ?";
        $args[] = '%'.$partKw.'%';
    }
    if (isset(REVIEW_LABELS[$status])) { $sql .= " AND h.review_status=?"; $args[] = $status; }
    $pfFilter = trim((string)($_GET['pfmea'] ?? ''));
    if ($hasPf && $pfFilter === 'yes')     $sql .= " AND EXISTS ($pfWhere)";
    else if ($hasPf && $pfFilter === 'no') $sql .= " AND NOT EXISTS ($pfWhere)";
    $printedFilter = trim((string)($_GET['printed'] ?? ''));
    if ($printedFilter === 'no')       $sql .= " AND NOT EXISTS (SELECT 1 FROM print_log pl WHERE pl.source='type_id_ctrl' AND pl.ref_table='type_id_ctrl_doc' AND pl.ref_id=h.id)";
    else if ($printedFilter === 'yes') $sql .= " AND EXISTS (SELECT 1 FROM print_log pl WHERE pl.source='type_id_ctrl' AND pl.ref_table='type_id_ctrl_doc' AND pl.ref_id=h.id)";
    $sql .= " ORDER BY h.created_at DESC";
    $st = $db->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // 需要更新（新檔案／內容變更，2026-09-24 使用者要求，五種來源皆涵蓋）：只對「已確認」的文件檢查——
    // 待確認／需重新確認本來就還沒審過，疊加這個提示沒有意義。已確認的文件數量天生就遠小於全部文件
    // （要人工按過確認才會進到這個集合），所以逐筆呼叫 type_id_ctrl_source_diff() 也不會是效能問題；
    // 千萬不要對全部文件都這樣掃一輪——那正是 CLAUDE.md 記過的「全料號跑一次子查詢」效能坑。
    $needsUpdateFilter = trim((string)($_GET['needs_update'] ?? ''));
    foreach ($rows as &$r) {
        $r['review_status_label'] = REVIEW_LABELS[$r['review_status']] ?? $r['review_status'];
        $r['pfmea_count'] = (int)$r['pfmea_count'];
        $r['has_pfmea']   = $r['pfmea_count'] > 0;
        $r['item_filled_count'] = (int)$r['item_filled_count'];
        $r['item_total_count']  = (int)$r['item_total_count'];
        $r['has_new'] = false; $r['has_changed'] = false; $r['new_count'] = 0; $r['changed_count'] = 0;
        if ($r['review_status'] === 'confirmed' && $r['part_d_id']) {
            $diff = type_id_ctrl_source_diff($db, (int)$r['id'], (int)$r['part_d_id']);
            $r['new_count'] = count($diff['new']); $r['changed_count'] = count($diff['changed']);
            $r['has_new'] = $r['new_count'] > 0; $r['has_changed'] = $r['changed_count'] > 0;
        }
    }
    unset($r);
    if ($needsUpdateFilter === 'yes') $rows = array_values(array_filter($rows, fn($r) => $r['has_new'] || $r['has_changed']));
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
    // 點開編輯畫面時自動加入新檔案／偵測內容變更（2026-09-24 使用者要求）：只有「已確認」的文件才做，
    // 且只有登錄以上權限才會觸發寫入（純檢視權限的人打開來看不應該連帶改到資料）。加入後一律改回
    // 「需重新確認」——不直接視為已確認，要由人親自按「重新確認」，見 type_id_ctrl_apply_diff()。
    $autoAdded = 0; $autoChanged = 0;
    if ($perms['canEdit'] && $doc['review_status'] === 'confirmed' && $doc['part_d_id']) {
        $diff = type_id_ctrl_source_diff($db, $id, (int)$doc['part_d_id']);
        if ($diff['new'] || $diff['changed']) {
            $r = type_id_ctrl_apply_diff($db, $id, $diff, false, $uid, $uname);
            $autoAdded = $r['added_count']; $autoChanged = $r['changed_count'];
            if ($autoAdded > 0 || $autoChanged > 0) {
                $st = $db->prepare("SELECT h.*, COALESCE(cl.customer,'') AS customer_name,
                                            COALESCE(ds.D_Setting_Id,'') AS part_no
                                     FROM type_id_ctrl_doc h
                                     LEFT JOIN customer_list cl ON cl.customer_id = h.customer_id
                                     LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
                                     WHERE h.id=?");
                $st->execute([$id]);
                $doc = $st->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
    $doc['review_status_label'] = REVIEW_LABELS[$doc['review_status']] ?? $doc['review_status'];
    $st = $db->prepare("SELECT * FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 ORDER BY seq");
    $st->execute([$id]);
    $items = array_map(function($it) use ($db) { return buildItemView($db, $it); }, $st->fetchAll(PDO::FETCH_ASSOC));
    $dates = computeDocDates($items);
    jout(['success'=>true,'doc'=>$doc,'items'=>$items,'doc_date_earliest'=>$dates['earliest'],'sign_date_latest'=>$dates['latest'],
          'process_summary'=>type_id_ctrl_process_header_summary($db,(int)$doc['part_d_id']),
          'auto_added_count'=>$autoAdded, 'auto_changed_count'=>$autoChanged]);

case 'delete_header':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    $db->prepare("UPDATE type_id_ctrl_doc SET is_deleted=1 WHERE id=?")->execute([$id]);
    jout(['success'=>true]);

// 批次確認清單（僅型態文件管理員／管理員；確認者一律記為目前登入者，比照單筆「確認清單」同一套邏輯，
// 不提供指定他人的介面——簽章日期仍是各文件自己項目列的最新日期即時算出，跟這裡的confirmed_at無關）
case 'batch_confirm':
    needAdmin($perms);
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) jout(['success'=>false,'message'=>'請先勾選要確認的項目']);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $db->beginTransaction();
    try {
        $st = $db->prepare("UPDATE type_id_ctrl_doc SET review_status='confirmed', confirmed_by=?, confirmed_by_name=?, confirmed_at=NOW()
                             WHERE id IN ($in) AND is_deleted=0");
        $st->execute(array_merge([$uid, $uname], $ids));
        $n = $st->rowCount();
        // 確認當下把每一筆已連結項目的「版別／文件編號」存成快照，供之後偵測內容是否變更（2026-09-24）
        foreach ($ids as $confirmedId) { type_id_ctrl_snapshot_confirm($db, $confirmedId); }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'批次確認失敗：'.$e->getMessage()]); }
    jout(['success'=>true,'confirmed_count'=>$n]);

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

    // 每個料號只能有一份型態識別文件管制表（本模組 2026-08-12 拍板改版的初衷：原本一個料號×一種製程
    // 各開一份，造成同一張共用圖面在多份管制表重複出現）。sync_part（自動建立）本來就有這道檢查，
    // 但這裡（畫面「新增」手動存檔）漏了同一道，新增或把既有一筆的料號改成別筆已經在用的都會擋下
    // （2026-09-24 使用者實測抓到：同一料號建出兩份 20260924001／20260924002）。
    $dupSt = $db->prepare("SELECT id, doc_no FROM type_id_ctrl_doc WHERE part_d_id=? AND is_deleted=0"
                          .($id ? " AND id<>?" : "")." LIMIT 1");
    $dupSt->execute($id ? [$partDId, $id] : [$partDId]);
    if ($dup = $dupSt->fetch(PDO::FETCH_ASSOC)) {
        jout(['success'=>false, 'message'=>'此料號已經有一份型態識別文件管制表（文件編號 '.$dup['doc_no'].'），一個料號只能有一份，請直接開啟編輯該份，不要另外建立。',
              'dup_id'=>(int)$dup['id'], 'dup_doc_no'=>$dup['doc_no']]);
    }

    // 已確認後按「儲存」（非確認清單/重新確認）要先取消已確認狀態並提醒（2026-09-24 使用者要求）：
    // 已確認代表有人審過目前內容，既然又動手改存檔，那份審核已經不成立了，不可以讓它安靜地繼續掛著
    // 「已確認」的樣子。降回「待確認」——這是使用者主動編輯，不是被動偵測到來源變了，跟 sync_part／
    // type_id_ctrl_apply_diff() 用「需重新確認」是不同情境，故意用不同的狀態值分開兩種原因。
    $reviewReset = false;
    if ($id && !$confirm) {
        $st = $db->prepare("SELECT review_status FROM type_id_ctrl_doc WHERE id=? AND is_deleted=0");
        $st->execute([$id]);
        $reviewReset = ($st->fetchColumn() === 'confirmed');
    }

    $db->beginTransaction();
    try {
        if ($id) {
            $st = $db->prepare("SELECT 1 FROM type_id_ctrl_doc WHERE id=? AND is_deleted=0");
            $st->execute([$id]);
            if (!$st->fetchColumn()) throw new Exception('找不到該筆或已刪除');
            $st = $db->prepare("UPDATE type_id_ctrl_doc SET customer_id=?, part_d_id=?,
                                 updated_at=NOW(), updated_by=?, updated_by_name=? " . ($reviewReset ? ", review_status='pending'" : "") . "
                                 WHERE id=?");
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
            $refFileName = trim((string)($it['ref_file_name'] ?? ''));
            $refBomTag = trim((string)($it['ref_bom_tag'] ?? ''));
            // bomfile（ERP/資材報告）沒有 attach_id，識別鍵是檔名
            // 2026-09-24 補上 sopsip（SOP／SIP，ref_attach_id 存 ss_doc.doc_id）——漏掉的話用
            // 「選外來文件」手動連結 SOP/SIP 後存檔，這裡會判成沒連結，把剛選好的連結整個洗空
            $isLinked = (in_array($refSource, ['part','quote','dev_eval','pfmea','sopsip'], true) && $refAttachId)
                        || ($refSource === 'bomfile' && $refFileName !== '');
            $isExcluded = $isLinked && !empty($it['is_excluded']) ? 1 : 0;
            $manualDate = trim((string)($it['manual_effective_date'] ?? ''));
            $manualDocNo = trim((string)($it['manual_doc_no'] ?? ''));

            $rowId = (int)($it['id'] ?? 0);
            if ($rowId && isset($existing[$rowId])) {
                $st = $db->prepare("UPDATE type_id_ctrl_item SET seq=?, item_name=?, item_type=?, process_tag=?, need_process_hint=?,
                                     ref_source=?, ref_attach_id=?, ref_ds_pk=?, ref_file_name=?, ref_bom_tag=?, is_excluded=?,
                                     manual_effective_date=?, manual_doc_no=?, updated_at=NOW()
                                     WHERE id=?");
                $st->execute([
                    $seq, $itemName, $itemType, ($processTag !== '' ? $processTag : null), $needProcessHint,
                    $isLinked ? $refSource : null, $isLinked ? $refAttachId : null, $isLinked ? $refDsPk : null,
                    $isLinked && $refFileName !== '' ? $refFileName : null, $isLinked && $refBomTag !== '' ? $refBomTag : null, $isExcluded,
                    $isLinked ? null : ($manualDate ?: null), $isLinked ? null : ($manualDocNo ?: null),
                    $rowId,
                ]);
                unset($existing[$rowId]);
            } else {
                $st = $db->prepare("INSERT INTO type_id_ctrl_item
                    (doc_id, seq, item_name, item_type, process_tag, need_process_hint, ref_source, ref_attach_id, ref_ds_pk, ref_file_name, ref_bom_tag, is_excluded, manual_effective_date, manual_doc_no)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([
                    $id, $seq, $itemName, $itemType, ($processTag !== '' ? $processTag : null), $needProcessHint,
                    $isLinked ? $refSource : null, $isLinked ? $refAttachId : null, $isLinked ? $refDsPk : null,
                    $isLinked && $refFileName !== '' ? $refFileName : null, $isLinked && $refBomTag !== '' ? $refBomTag : null, $isExcluded,
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
            // 確認當下把每一筆已連結項目的「版別／文件編號」存成快照，供之後偵測內容是否變更（2026-09-24）
            type_id_ctrl_snapshot_confirm($db, $id);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'儲存失敗：'.$e->getMessage()]); }
    jout(['success'=>true,'id'=>$id,'review_reset'=>$reviewReset]);

// ── 連結外來文件清單（即時查詢，不落地快照；含 is_external_doc + 廠內自家出圖已勾選類別）──
case 'search_ext_doc':
    needView($perms);
    $dsPk = (int)($_POST['ds_pk'] ?? $_GET['ds_pk'] ?? 0);
    if (!$dsPk) jout(['success'=>true,'rows'=>[]]);
    $extRows = type_id_ctrl_fetch_ext_docs_for_part($db, $dsPk);
    // 手動連結候選額外併入「完全通用」的 SOP/SIP（使用者：「若無綁訂此料號之SOP/SIP 則可指定
    // 通用的SOP/SIP列入」）——那種不會被自動同步收進去（見 type_id_ctrl_fetch_sopsip_for_part 說明），
    // 只在這個手動挑選的候選清單裡補上；已經因「專用／通用限定客戶」出現過的不重複列出。
    $seenSopsip = [];
    foreach ($extRows as $er) { if (($er['source'] ?? '') === 'sopsip') $seenSopsip[(int)$er['attach_id']] = true; }
    foreach (type_id_ctrl_fetch_sopsip_generic($db, $dsPk) as $g) {
        if (isset($seenSopsip[(int)$g['attach_id']])) continue;
        $extRows[] = $g;
    }
    jout(['success'=>true,'rows'=>$extRows]);

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
    if (!$dsPk) jout(['success'=>true,'rows'=>[],'doc_date_earliest'=>null,'process_summary'=>'']);
    $ext = type_id_ctrl_fetch_ext_docs_for_part($db, $dsPk);
    $out = array_map(function($er){
        return [
            'id'=>0, 'seq'=>0,
            'item_name'=> !empty($er['categories']) ? $er['categories'][0] : $er['doc_name'],
            'item_type'=> !empty($er['force_type']) ? $er['force_type'] : type_id_ctrl_guess_type($er['categories'] ?? []),
            'process_tag'=> $er['origin_process'] ?? null,
            'need_process_hint'=> !empty($er['need_process']),
            'is_linked'=>true, 'is_excluded'=>false,
            'ref_source'=>$er['source'], 'ref_attach_id'=>(int)$er['attach_id'], 'ref_ds_pk'=>(int)$er['ds_pk'],
            'ref_source_label'=>SOURCE_LABELS[$er['source']] ?? '自動帶入',
            'ref_file_name'=>$er['file_name'] ?? null, 'ref_bom_tag'=>$er['bom_tag'] ?? null,
            'ref_broken'=>false, 'effective_date'=>$er['doc_date'], 'doc_no_text'=>$er['doc_name'], 'file_url'=>null,
        ];
    }, $ext);
    // 新增流程(尚未存檔)選定料號後，畫面上的「建立日期(最早外來文件日期)」與「製程」原本要存檔後
    // 重新載入才看得到；這兩者只跟「這個料號」有關，選料號當下就算得出來，一併回傳讓前端即時顯示
    // （2026-09-24 使用者回報「開啟全表填寫模式後才顯示」——其實是這裡沒有一起算、一起回）。
    $dates = computeDocDates($out);
    jout(['success'=>true,'rows'=>$out,
          'doc_date_earliest'=>$dates['earliest'],
          'process_summary'=>type_id_ctrl_process_header_summary($db, $dsPk)]);

// ── 依料號自動產生/同步型態識別文件管制表(每料號一份，項目自標所屬製程)────
case 'sync_part':
    needEdit($perms);
    $dsPk = (int)($_POST['part_d_id'] ?? 0);
    if (!$dsPk) jout(['success'=>false,'message'=>'請先選擇料號']);
    $r = type_id_ctrl_sync_part($db, $dsPk);
    if (!$r['doc_id']) jout(['success'=>false,'message'=>'找不到此料號']);
    jout(['success'=>true,'doc_id'=>$r['doc_id'],'is_new'=>$r['is_new'],'added_count'=>$r['added_count']]);

// ── 批次更新（2026-09-24 使用者要求，獨立授權：需要「批次更新權限」角色或管理員）──────
// 對勾選的多筆「已確認」文件，一次套用目前偵測到的新檔案／內容變更差異。
// confirm_after：使用者的選擇——1＝更新後直接視為已確認（重新寫入確認快照，不必再人工確認一次）；
//                0（預設）＝更新後改為「需重新確認」，仍要人工逐份按「重新確認」（較保守）。
// 沒有 part_d_id、或本來就不是「已確認」、或掃出來根本沒有差異的一律跳過，不視為失敗。
case 'batch_update':
    needBatchUpdate($perms);
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) jout(['success'=>false,'message'=>'請先勾選要更新的項目']);
    $confirmAfter = !empty($_POST['confirm_after']);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, part_d_id, review_status FROM type_id_ctrl_doc WHERE id IN ($in) AND is_deleted=0");
    $st->execute($ids);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
    $updatedCount = 0; $skippedCount = 0; $addedTotal = 0; $changedTotal = 0;
    foreach ($docs as $d) {
        $docId = (int)$d['id'];
        if ($d['review_status'] !== 'confirmed' || !$d['part_d_id']) { $skippedCount++; continue; }
        $diff = type_id_ctrl_source_diff($db, $docId, (int)$d['part_d_id']);
        if (!$diff['new'] && !$diff['changed']) { $skippedCount++; continue; }
        $db->beginTransaction();
        try {
            $r = type_id_ctrl_apply_diff($db, $docId, $diff, $confirmAfter, $uid, $uname);
            $db->commit();
            $updatedCount++; $addedTotal += $r['added_count']; $changedTotal += $r['changed_count'];
        } catch (Throwable $e) { $db->rollBack(); $skippedCount++; }
    }
    jout(['success'=>true, 'updated_count'=>$updatedCount, 'skipped_count'=>$skippedCount,
          'added_total'=>$addedTotal, 'changed_total'=>$changedTotal, 'confirm_after'=>$confirmAfter]);

// ── 上傳後把附件的建立日期改成使用者填的「文件日期」（2026-08-19 使用者要求）─────
// 料號附件沒有獨立的文件日期欄位，本模組的「型態生效日期」＝發行章日期，沒填才退回上傳日
// (type_id_ctrl_resolve_ref)。所以「以上傳日認定日期」的文件要能補正日期，只能改 uploaded_at；
// 只改日期、保留原本的時分秒，同一天內多筆的先後順序才不會被洗掉。
case 'set_attach_doc_date':
    needEdit($perms);
    $aid  = (int)($_POST['attach_id'] ?? 0);
    $ddate = trim((string)($_POST['doc_date'] ?? ''));
    if (!$aid) jout(['success'=>false,'message'=>'缺少附件編號']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ddate)) jout(['success'=>false,'message'=>'文件日期格式錯誤（需 YYYY-MM-DD）']);
    $st = $db->prepare("UPDATE part_attachments SET uploaded_at=CONCAT(?,' ',TIME(uploaded_at)) WHERE id=? AND deleted_at IS NULL");
    $st->execute([$ddate, $aid]);
    jout(['success'=>true,'updated'=>$st->rowCount()]);

// ── 本頁「上傳檔案」可選的附件類別（只給會被本模組同步進項目列的類別）──────
case 'upload_categories':
    needEdit($perms);
    jout(['success'=>true,'rows'=>type_id_ctrl_upload_categories($db),
          'dwg_change_url'=>'../QC/drawing_change_log.php']);

// ── 掃描「應該要有、但還沒建立型態識別文件管制表」的料號（外來文件附件／PFMEA 兩種來源）──
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

// ── ERP/資材報告 檔名標籤設定（2026-08-20 使用者要求）───────────────────────
// 標籤本身在「料號圖面查閱(part_viewer.php)」設定，這裡只設定「要不要列入本模組／列入後的
// 型態項目名稱與型態類別」，逐標籤分開設定。
case 'bom_tag_setting_get':
    needAdmin($perms);
    $map = type_id_ctrl_bom_tag_map_get($db);
    $rows = [];
    foreach (type_id_ctrl_bom_tags_all($db) as $t) {
        $cfg = $map[$t['suffix']] ?? null;
        $rows[] = [
            'suffix'    => $t['suffix'],
            'label'     => $t['label'],
            'color'     => $t['color'],
            'included'  => $cfg ? 1 : 0,
            'item_name' => $cfg ? $cfg['item_name'] : $t['label'],
            'item_type' => $cfg ? $cfg['item_type'] : 'report',
        ];
    }
    jout(['success'=>true,'rows'=>$rows,'dir'=>type_id_ctrl_bom_file_dir($db),
          'type_options'=>TYPE_LABELS, 'part_viewer_url'=>'../pm/part_viewer.php']);

case 'bom_tag_setting_save':
    needAdmin($perms);
    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($rows)) $rows = [];
    $dir = trim((string)($_POST['dir'] ?? ''));
    $map = [];
    foreach ($rows as $r) {
        if (empty($r['included'])) continue;                       // 沒勾列入的不存
        $suffix = trim((string)($r['suffix'] ?? ''));
        if ($suffix === '') continue;
        $type = (string)($r['item_type'] ?? 'other');
        if (!isset(TYPE_LABELS[$type])) jout(['success'=>false,'message'=>'型態類別不合法：'.$type]);
        $map[$suffix] = ['item_name'=>trim((string)($r['item_name'] ?? '')), 'item_type'=>$type];
    }
    try {
        type_id_ctrl_bom_tag_map_save($db, $map, $dir, $uname);
    } catch (Throwable $e) { jout(['success'=>false,'message'=>'儲存失敗：'.$e->getMessage()]); }
    jout(['success'=>true,'saved_count'=>count($map)]);

// NAS 上的 ERP/資材報告檔案下載（走 API 守門＋路徑現場組，不給瀏覽器直連 UNC＝鐵律5）
case 'download_bom_file':
    needView($perms);
    $name = (string)($_GET['name'] ?? '');
    $full = type_id_ctrl_bom_file_path($db, $name);
    if ($full === null || !is_file($full)) { http_response_code(404); echo '檔案不存在'; exit; }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
             'gif'=>'image/gif','xls'=>'application/vnd.ms-excel',
             'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name));
    readfile($full);
    exit;

// 把「廠內圖面標籤設定」目前的顯示名稱／需要顯示製程，套用回已同步進本模組的既有項目列
// （2026-08-12 使用者要求：沒有批次刪除重轉，改名要能直接更新舊資料）
case 'refresh_item_names_by_category':
    needAdmin($perms);
    $r = type_id_ctrl_refresh_synced_item_names($db);
    jout(['success'=>true,'updated_count'=>$r['updated_count'],'affected_docs'=>$r['affected_docs']]);

// ── AS 文件編號綁定＋製表人圖章模板（本頁自身列印設定）────────────────
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

// 圖章模板下拉清單＋目前設定值（讀取不卡管理員，ai-rules/18 鐵則9：卡了一般人列印永遠拿不到模板）
case 'stamp_tpl_options':
    needView($perms);
    jout(['success'=>true,'tpls'=>type_id_ctrl_stamp_tpl_options($db),'tpl_id'=>type_id_ctrl_stamp_tpl_id($db)]);

case 'stamp_tpl_save':
    needAdmin($perms);
    $tplId = (int)($_POST['tpl_id'] ?? 0);
    if ($tplId) {
        $chk = $db->prepare("SELECT id FROM stamp_template WHERE id=? AND is_active=1");
        $chk->execute([$tplId]);
        if (!$chk->fetchColumn()) jout(['success'=>false,'message'=>'選擇的圖章模板不存在或已停用']);
    }
    type_id_ctrl_stamp_tpl_save($db, $tplId, $uname);
    jout(['success'=>true,'tpl_id'=>type_id_ctrl_stamp_tpl_id($db)]);

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
    // 待確認/需重新確認者一律不可列印（2026-09-24 使用者要求）：尚未經人確認過的內容不該當成正式文件印出。
    // 已確認但有新檔案/內容變更（needs_update）者同樣擋下——確認當下的清單與目前來源已經不同，
    // 印出來的內容跟「已確認」的狀態對不起來，一律要先重新確認過才能列印。
    if ($doc['review_status'] !== 'confirmed') {
        jout(['success'=>false,'message'=>'此文件尚未確認（目前狀態：'.(REVIEW_LABELS[$doc['review_status']] ?? $doc['review_status']).'），請先完成確認後再列印。']);
    }
    if ($doc['part_d_id']) {
        $diffChk = type_id_ctrl_source_diff($db, (int)$doc['id'], (int)$doc['part_d_id']);
        if (count($diffChk['new']) > 0 || count($diffChk['changed']) > 0) {
            jout(['success'=>false,'message'=>'此文件已確認，但偵測到新檔案或內容變更尚待更新，請先「更新狀態」重新確認後再列印。']);
        }
    }
    $st = $db->prepare("SELECT * FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 ORDER BY seq");
    $st->execute([$id]);
    $items = array_map(function($it) use ($db) { return buildItemView($db, $it); }, $st->fetchAll(PDO::FETCH_ASSOC));
    $dates = computeDocDates($items);
    $asDoc = eg_asdoc_get($db, 'type_id_ctrl');
    // 版次依業務日期回推：業務日期優先用「建立日期(最早外來文件日期)」，沒有則退回DB建立時間（ai-rules/16第三之四節）
    $bizDate = $dates['earliest'] ?: substr((string)$doc['created_at'], 0, 10);
    // 列印紀錄（ai-rules/23）：記的是「按下列印」這個動作，不是「印出來了」；「列印全部搜尋結果」
    // 逐筆各自呼叫這個動作，本來就一份文件各記一筆，不會有一次列印被記成好幾筆的問題。
    eg_print_log_add($db, [
        'source' => 'type_id_ctrl', 'doc_kind' => 'form',
        'ref_table' => 'type_id_ctrl_doc', 'ref_id' => (string)$id,
        'doc_name' => '型態識別文件管制表 ' . (string)$doc['doc_no'],
        'part_no' => (string)($doc['part_no'] ?? ''),
    ]);
    jout([
        'success'=>true, 'doc'=>$doc, 'items'=>$items,
        'doc_date_earliest'=>$dates['earliest'], 'sign_date_latest'=>$dates['latest'],
        'process_summary'=>type_id_ctrl_process_header_summary($db,(int)$doc['part_d_id']),
        'company_name'=>type_id_ctrl_company_name($db),
        'as_doc_no'=>eg_asdoc_no_asof($db, 'type_id_ctrl', $bizDate),
        'as_doc_name'=>$asDoc['doc_name'] ?? '型態識別文件管制表',
        'stamp_tpl'=>type_id_ctrl_stamp_tpl($db, type_id_ctrl_stamp_tpl_id($db)),
    ]);

default:
    jout(['success'=>false,'message'=>'未知動作']);
}
