<?php
/**
 * 產品開發評估表（AS 2-TD-02-01）API
 * 資料/權限/簽核人解析說明見 src/common/td_dev_eval_lib.php
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/delegate_lib.php';
include_once $document_root . '/EGsystem/src/common/confirm_password_lib.php';
include_once $document_root . '/EGsystem/src/common/td_dev_eval_lib.php';

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']); exit;
}

$db = (new DBConnection())->getPDO();
eg_org_ensure_schema($db);
td_dev_eval_ensure_schema($db);
$me    = td_dev_eval_current_user($db);
$perms = td_dev_eval_perms($db, $me);
$uid   = $me ? (int)$me['id'] : 0;
$uname = $me ? (string)$me['user_cname'] : '';

function jout($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function needView(array $perms) { if (!$perms['canView']) jout(['success'=>false,'message'=>'無檢閱權限']); }
function needEdit(array $perms) { if (!$perms['canEdit']) jout(['success'=>false,'message'=>'無登錄權限']); }
function needAdmin(array $perms) { if (!$perms['canAdmin']) jout(['success'=>false,'message'=>'無管理權限']); }
/** 32項快速設定／全部自動簽核：僅限系統超級管理員(isAdmin)，比一般模組管理員(td_dev_eval_admin)更高，僅補舊資料用 */
function needSuperAdmin(array $perms) { if (!$perms['isAdmin']) jout(['success'=>false,'message'=>'僅系統管理員可使用此功能']); }

const RESULT_LABELS = ['yes'=>'是', 'no'=>'否', 'na'=>'N/A'];

/**
 * 組出單一簽核欄位的顯示資料（含目前可簽核人員池、本人是否可簽、卡關原因）。
 * 卡關規則：doc.status 必須是 submitted；六部門欄不限順序；生產課決行需六部門全簽完（決行結果跟簽核
 * 同一次一起送出存檔，不在畫面上點選當下就存，見前端 signSlot()）；總經理決行需生產課決行已簽。
 */
function buildSlotView(PDO $db, string $slotKey, array $row, int $docId, int $curUid, array $doc): array {
    [$label,,$isSingle] = TD_DEV_EVAL_SLOTS[$slotKey];
    $pool = td_dev_eval_slot_pool($db, $slotKey, $docId);
    $blockedReason = '';
    if ($doc['status'] !== 'submitted') {
        $blockedReason = $doc['status'] === 'draft' ? '尚未送出' : '已結案';
    } elseif ($slotKey === 'prod_decision') {
        if (!td_dev_eval_dept_slots_done($db, $docId)) $blockedReason = '需六部門全部簽認完成';
    } elseif ($slotKey === 'gm') {
        if (!td_dev_eval_slot_signed($db, $docId, 'prod_decision')) $blockedReason = '需生產課決行完成';
    }
    $canSign = !$row['signed_by'] && !$blockedReason && in_array($curUid, array_column($pool, 'id'), true);
    return [
        'slot_key' => $slotKey, 'label' => $label,
        'note' => $row['note'], 'signed_by' => $row['signed_by'] ? (int)$row['signed_by'] : null,
        'decision_value' => $row['decision_value'] ?? null, // 該欄簽核當下的決行結果(僅生產課決行/總經理決行)
        'signed_by_name' => $row['signed_by_name'], 'signed_at' => $row['signed_at'],
        'is_deputy' => !empty($row['is_deputy']), 'is_backfill' => !empty($row['is_backfill']),
        'backfill_by_name' => $row['backfill_by_name'],
        'pool' => $pool, 'can_sign' => $canSign, 'blocked_reason' => $blockedReason,
        'item_nos' => in_array($slotKey, TD_DEV_EVAL_DEPT_SLOTS, true) ? td_dev_eval_slot_item_nos($slotKey) : [],
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'perms':
    jout(['success'=>true,'perms'=>$perms,'user_name'=>$uname]);

case 'get_template':
    needView($perms);
    jout(['success'=>true,'template'=>TD_DEV_EVAL_TEMPLATE,'slots'=>TD_DEV_EVAL_SLOTS,'decisions'=>TD_DEV_EVAL_DECISIONS]);

// ── 預估需求量自動試算（僅供帶入預設值，欄位仍可手動改） ──
case 'estimate_qty':
    needView($perms);
    $partDId = (int)($_GET['part_d_id'] ?? 0);
    $fillDate = trim((string)($_GET['fill_date'] ?? ''));
    jout(['success'=>true, 'est_qty'=>td_dev_eval_estimate_qty($db, $partDId, $fillDate)]);

case 'list':
    needView($perms);
    $kw = trim((string)($_GET['kw'] ?? ''));
    $sql = "SELECT h.id, h.doc_no, h.customer_name, h.part_d_id, COALESCE(ds.D_Setting_Id, h.part_no_text,'') AS part_no,
                   h.product_name, h.fill_date, h.decision, h.status, h.created_by_name, h.created_at,
                   (SELECT COUNT(*) FROM td_dev_eval_signoff s WHERE s.doc_id=h.id AND s.signed_by IS NOT NULL) AS signed_count,
                   (SELECT MAX(s2.signed_at) FROM td_dev_eval_signoff s2 WHERE s2.doc_id=h.id AND s2.signed_by IS NOT NULL) AS last_signed_at,
                   (SELECT MAX(pl.printed_at) FROM td_dev_eval_print_log pl WHERE pl.doc_id=h.id) AS last_printed_at,
                   (SELECT COUNT(*) FROM td_dev_eval_print_log pl2 WHERE pl2.doc_id=h.id) AS print_count
            FROM td_dev_eval h
            LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
            LEFT JOIN customer_list clP ON clP.customer_id = ds.Customer_Id
            LEFT JOIN customer_list clN ON clN.customer = h.customer_name
            WHERE h.is_deleted=0";
    $args = [];
    if ($kw !== '') {
        // 搜尋欄位涵蓋客戶ID（透過料號綁定的客戶、或客戶名稱文字比對兩種來源，使用者明確要求可用客戶ID篩選）
        $sql .= " AND (h.doc_no LIKE ? OR h.product_name LIKE ? OR h.customer_name LIKE ? OR ds.D_Setting_Id LIKE ?
                       OR clP.customer_id LIKE ? OR clN.customer_id LIKE ?)";
        $like = '%'.$kw.'%'; $args = [$like,$like,$like,$like,$like,$like];
    }
    $sql .= " ORDER BY h.created_at DESC";
    $st = $db->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $statusLabels = ['draft'=>'草稿', 'submitted'=>'簽核中', 'closed'=>'已結案'];
    foreach ($rows as &$r) {
        $r['decision_label'] = TD_DEV_EVAL_DECISIONS[$r['decision']] ?? '';
        $r['is_complete'] = ((int)$r['signed_count'] >= count(TD_DEV_EVAL_SLOTS));
        $r['status_label'] = $statusLabels[$r['status']] ?? $r['status'];
    }
    unset($r);
    jout(['success'=>true,'rows'=>$rows]);

case 'get':
    needView($perms);
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT h.*, COALESCE(ds.D_Setting_Id, h.part_no_text,'') AS part_no
                         FROM td_dev_eval h LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
                         WHERE h.id=? AND h.is_deleted=0");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['success'=>false,'message'=>'找不到該筆']);

    $st = $db->prepare("SELECT item_no, result FROM td_dev_eval_answer WHERE doc_id=?");
    $st->execute([$id]);
    $answers = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $answers[(int)$a['item_no']] = $a['result'];

    $st = $db->prepare("SELECT * FROM td_dev_eval_signoff WHERE doc_id=?");
    $st->execute([$id]);
    $signRows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) $signRows[$s['slot_key']] = $s;
    $slots = [];
    foreach (array_keys(TD_DEV_EVAL_SLOTS) as $slotKey) {
        $row = $signRows[$slotKey] ?? ['note'=>null,'decision_value'=>null,'signed_by'=>null,'signed_by_name'=>null,'signed_at'=>null,'is_deputy'=>0,'is_backfill'=>0,'backfill_by_name'=>null];
        $slots[$slotKey] = buildSlotView($db, $slotKey, $row, $id, $uid, $doc);
    }
    jout(['success'=>true,'doc'=>$doc,'answers'=>$answers,'slots'=>$slots]);

case 'save':
    needEdit($perms);
    $id = (int)($_POST['id'] ?? 0);
    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $partDId = (int)($_POST['part_d_id'] ?? 0);
    $partNoText = trim((string)($_POST['part_no_text'] ?? ''));
    $productName = trim((string)($_POST['product_name'] ?? ''));
    $estQty = trim((string)($_POST['est_qty'] ?? ''));
    $fillDate = trim((string)($_POST['fill_date'] ?? ''));
    $sampleTime = trim((string)($_POST['sample_time'] ?? ''));
    $answersRaw = json_decode((string)($_POST['answers'] ?? '{}'), true);
    if (!is_array($answersRaw)) $answersRaw = [];

    $db->beginTransaction();
    try {
        if ($id) {
            $st = $db->prepare("SELECT status FROM td_dev_eval WHERE id=? AND is_deleted=0");
            $st->execute([$id]);
            $curStatus = $st->fetchColumn();
            if ($curStatus === false) throw new Exception('找不到該筆或已刪除');
            if ($curStatus !== 'draft' && !$perms['isAdmin']) throw new Exception('已送出後表頭與確認項目改為各部門於簽核關卡自行填寫，僅系統管理員可整批修改');
            $st = $db->prepare("UPDATE td_dev_eval SET customer_name=?, part_d_id=?, part_no_text=?, product_name=?,
                                 est_qty=?, fill_date=?, sample_time=?, updated_at=NOW(), updated_by=?, updated_by_name=? WHERE id=?");
            $st->execute([$customerName ?: null, $partDId ?: null, $partNoText ?: null, $productName ?: null,
                          $estQty !== '' ? (int)$estQty : null, $fillDate ?: null, $sampleTime ?: null, $uid, $uname, $id]);
            // 填表日期改了，表單編號跟著重編（使用者 2026-08-20 拍板：編號前八碼永遠＝填表日期）
            td_dev_eval_sync_doc_no($db, $id, $fillDate);
        } else {
            $docNo = td_dev_eval_next_doc_no($db, $fillDate); // 編號依填表日期(2026-08-20)
            // 建立當下若前端沒帶預估需求量，後端自動試算補上（使用者明確要求「建立評估表時就要自動計算並填入」，
            // 不只是前端互動觸發才算，伺服端在建立這個動作本身就做一次保底）
            $estQtyToSave = $estQty !== '' ? (int)$estQty : null;
            if ($estQtyToSave === null && $partDId && $fillDate) {
                $estQtyToSave = td_dev_eval_estimate_qty($db, $partDId, $fillDate);
            }
            $st = $db->prepare("INSERT INTO td_dev_eval (doc_no, customer_name, part_d_id, part_no_text, product_name,
                                 est_qty, fill_date, sample_time, created_by, created_by_name)
                                 VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$docNo, $customerName ?: null, $partDId ?: null, $partNoText ?: null, $productName ?: null,
                          $estQtyToSave, $fillDate ?: null, $sampleTime ?: null, $uid, $uname]);
            $id = (int)$db->lastInsertId();
        }

        // 確認項目及結果只能透過「送出後在自己部門的簽核關卡」(sign動作) 或系統管理員的全表填寫模式來填，
        // 不論表單處於什麼狀態，一般使用者一律不可經由 save 動作寫入(即使是草稿階段)——各使用者只能點選/回覆
        // 自己能簽核的範圍，跟評估表登錄/管理員這種頁面操作角色無關，此處後端同步前端 itemEditable() 的收斂
        if ($perms['isAdmin']) foreach (TD_DEV_EVAL_TEMPLATE as $itemNo => $tpl) {
            $result = $answersRaw[$itemNo] ?? $answersRaw[(string)$itemNo] ?? null;
            if (!in_array($result, ['yes','no','na'], true)) $result = null;
            $st = $db->prepare("INSERT INTO td_dev_eval_answer (doc_id, item_no, result) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE result=VALUES(result)");
            $st->execute([$id, $itemNo, $result]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'儲存失敗：'.$e->getMessage()]); }
    jout(['success'=>true,'id'=>$id]);

case 'delete_header':
    needAdmin($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺少id']);
    $db->prepare("UPDATE td_dev_eval SET is_deleted=1 WHERE id=?")->execute([$id]);
    jout(['success'=>true]);

// ── 送出：草稿鎖定表頭，通知六部門開始簽核 ─────────────────────────
case 'submit':
    needEdit($perms);
    $id = (int)($_POST['id'] ?? 0);
    $r = td_dev_eval_submit($db, $id, $uid, $uname);
    if (!$r['ok']) jout(['success'=>false,'message'=>$r['msg']]);
    jout(['success'=>true]);

// ── 本人即時簽核：需在該欄目前可簽核人員池內、表單狀態需為簽核中、且符合分階段卡關 ──
// 部門類欄位(tech/sales/mgmt/prod/qa/material)簽核時一併送出本部門負責的確認項目結果。
case 'sign':
    needEdit($perms);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $slotKey = (string)($_POST['slot_key'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $answersRaw = json_decode((string)($_POST['answers'] ?? '{}'), true);
    if (!is_array($answersRaw)) $answersRaw = [];
    if (!isset(TD_DEV_EVAL_SLOTS[$slotKey])) jout(['success'=>false,'message'=>'不合法的簽核欄位']);

    $st = $db->prepare("SELECT * FROM td_dev_eval WHERE id=? AND is_deleted=0");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['success'=>false,'message'=>'找不到該筆']);
    if ($doc['status'] !== 'submitted') jout(['success'=>false,'message'=>$doc['status']==='draft' ? '此表單尚未送出，無法簽核' : '此表單已結案']);
    // 決行結果一般使用者不會在點選當下就存檔(避免只是想測試畫面卻真的存進DB)，改成跟「我要簽核」同一次一起送出存檔；
    // 若管理員已經透過快速設定面板先存好(仍支援)，這裡沒收到就退回沿用DB既有值
    $decision = trim((string)($_POST['decision'] ?? '')) ?: (string)($doc['decision'] ?? '');
    if ($slotKey === 'prod_decision') {
        if (!td_dev_eval_dept_slots_done($db, $docId)) jout(['success'=>false,'message'=>'需六部門全部簽認完成才能決行']);
        if (!isset(TD_DEV_EVAL_DECISIONS[$decision])) jout(['success'=>false,'message'=>'請先選擇決行結果']);
    }
    if ($slotKey === 'gm') {
        if (!td_dev_eval_slot_signed($db, $docId, 'prod_decision')) jout(['success'=>false,'message'=>'需生產課決行完成才能總經理決行']);
        // 總經理決行為最終決策，可沿用或覆蓋生產課的結果，一樣要求明確選一個（使用者明確要求給跟生產課一樣的選項）
        if (!isset(TD_DEV_EVAL_DECISIONS[$decision])) jout(['success'=>false,'message'=>'請先選擇決行結果']);
    }

    $pool = td_dev_eval_slot_pool($db, $slotKey, $docId);
    $mine = null;
    foreach ($pool as $p) if ((int)$p['id'] === $uid) { $mine = $p; break; }
    if (!$mine) jout(['success'=>false,'message'=>'您不在此欄目前的可簽核人員名單內']);
    $st = $db->prepare("SELECT signed_by FROM td_dev_eval_signoff WHERE doc_id=? AND slot_key=?");
    $st->execute([$docId, $slotKey]);
    if ($st->fetchColumn()) jout(['success'=>false,'message'=>'此欄已經有人簽核，請重新整理後確認']); // 點開即刷新鐵則

    // 部門負責的項次要全部有結果才能簽核（使用者明確要求）：合併「本次一併送出的」與「DB既有的」結果一起檢查
    if (in_array($slotKey, TD_DEV_EVAL_DEPT_SLOTS, true)) {
        $itemNos = td_dev_eval_slot_item_nos($slotKey);
        $st = $db->prepare("SELECT item_no, result FROM td_dev_eval_answer WHERE doc_id=?");
        $st->execute([$docId]);
        $existing = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $existing[(int)$a['item_no']] = $a['result'];
        foreach ($itemNos as $itemNo) {
            $result = $answersRaw[$itemNo] ?? $answersRaw[(string)$itemNo] ?? $existing[$itemNo] ?? null;
            if (!in_array($result, ['yes','no','na'], true)) jout(['success'=>false,'message'=>'請先填完本部門負責的所有項次，才能填意見與簽核']);
        }
    }

    $db->beginTransaction();
    try {
        if (in_array($slotKey, TD_DEV_EVAL_DEPT_SLOTS, true)) {
            foreach (td_dev_eval_slot_item_nos($slotKey) as $itemNo) {
                $result = $answersRaw[$itemNo] ?? $answersRaw[(string)$itemNo] ?? null;
                if (!in_array($result, ['yes','no','na'], true)) continue; // 已在上方驗證過DB已有值，這裡只是不覆蓋成空值
                $st = $db->prepare("INSERT INTO td_dev_eval_answer (doc_id, item_no, result) VALUES (?,?,?)
                                     ON DUPLICATE KEY UPDATE result=VALUES(result)");
                $st->execute([$docId, $itemNo, $result]);
            }
        }
        if ($slotKey === 'prod_decision' || $slotKey === 'gm') {
            // 總經理決行是最終決策：即使跟生產課選的一樣也重寫一次，若不同則以總經理這次選的為準(覆蓋)
            $db->prepare("UPDATE td_dev_eval SET decision=?, updated_at=NOW(), updated_by=?, updated_by_name=? WHERE id=?")
               ->execute([$decision, $uid, $uname, $docId]);
        }
        // 決行欄位另存「這一格自己選的決行結果」：表頭 decision 是最終決策(總經理會覆蓋生產課)，只留表頭的話
        // 列印時總經理那格印不出自己的結果，且生產課被覆蓋前選了什麼也查不到
        $slotDecision = ($slotKey === 'prod_decision' || $slotKey === 'gm') ? $decision : null;
        $st = $db->prepare("INSERT INTO td_dev_eval_signoff (doc_id, slot_key, note, decision_value, signed_by, signed_by_name, signed_at, is_deputy)
                             VALUES (?,?,?,?,?,?,NOW(),?)
                             ON DUPLICATE KEY UPDATE note=VALUES(note), decision_value=VALUES(decision_value), signed_by=VALUES(signed_by),
                                 signed_by_name=VALUES(signed_by_name), signed_at=NOW(), is_deputy=VALUES(is_deputy)");
        $st->execute([$docId, $slotKey, $note ?: null, $slotDecision, $uid, $uname, !empty($mine['is_deputy']) ? 1 : 0]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'簽核失敗：'.$e->getMessage()]); }
    td_dev_eval_advance_after_sign($db, $docId, $slotKey, $uid, $uname);
    jout(['success'=>true]);

// ── 超級管理員：32項快速設定 + 全部自動簽核(指定日期)，補舊資料用，不受送出/簽核狀態限制 ──
case 'admin_auto_sign_all':
    needSuperAdmin($perms);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $bizDate = trim((string)($_POST['biz_date'] ?? ''));
    $applyDefaults = !empty($_POST['apply_defaults']);
    $decision = trim((string)($_POST['decision'] ?? ''));
    if (!$docId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) jout(['success'=>false,'message'=>'請指定業務日期']);
    $r = td_dev_eval_admin_auto_sign_all($db, $docId, $bizDate, $uid, $uname, $applyDefaults, $decision ?: null);
    if (!$r['ok']) jout(['success'=>false,'message'=>$r['msg']]);
    jout(['success'=>true]);

// ── 確認項目及結果預設值：超級管理員設定，供「全部自動簽核」可選套用 ──
// ── 開啟全表填寫模式：僅系統管理員(isAdmin)，輸入操作確認密碼後前端才放行不受部門/簽核順序限制編輯32項 ──
// 只驗證密碼，不寫入任何資料；真正的資料寫入仍走 save/sign/backfill_sign_all/admin_auto_sign_all 既有動作與各自的規則。
case 'admin_full_edit_check':
    needSuperAdmin($perms);
    $password = (string)($_POST['password'] ?? '');
    $chk = eg_confirm_password_verify($db, $uid, $password);
    if (!$chk['ok']) jout(['success'=>false,'message'=>$chk['msg']]);
    jout(['success'=>true]);

case 'answer_defaults_get':
    needView($perms);
    jout(['success'=>true, 'defaults'=>td_dev_eval_answer_defaults_get($db)]);

case 'answer_defaults_save':
    needSuperAdmin($perms);
    $map = json_decode((string)($_POST['defaults'] ?? '{}'), true);
    if (!is_array($map)) $map = [];
    td_dev_eval_answer_defaults_save($db, $map, $uid, $uname);
    jout(['success'=>true, 'defaults'=>td_dev_eval_answer_defaults_get($db)]);

// ── 產品名稱預設值：全部產品通用的單一值(不是特定料號)，新建立表單時自動帶入，仍可手動改 ──
case 'default_product_name_get':
    needView($perms);
    jout(['success'=>true, 'product_name'=>td_dev_eval_default_product_name_get($db)]);

case 'default_product_name_save':
    needAdmin($perms);
    $name = trim((string)($_POST['product_name'] ?? ''));
    td_dev_eval_default_product_name_save($db, $name, $uid, $uname);
    jout(['success'=>true]);

// ── 部門簽核人指定覆蓋：部門組織圖主管實務上不回覆這張表單時(如技術課由總經理兼任但不回覆此表單)，
// 評估表管理員可指定特定人員取代該部門的自動解析主管，只影響本模組，不動全站組織角色綁定 ──
case 'slot_overrides_get':
    needView($perms);
    jout(['success'=>true, 'overrides'=>td_dev_eval_slot_overrides_get($db), 'people'=>eg_people_list($db)]);

case 'slot_overrides_save':
    needAdmin($perms);
    $map = json_decode((string)($_POST['overrides'] ?? '{}'), true);
    if (!is_array($map)) $map = [];
    td_dev_eval_slot_overrides_save($db, $map, $uid, $uname);
    jout(['success'=>true, 'overrides'=>td_dev_eval_slot_overrides_get($db)]);

case 'unsign':
    needAdmin($perms);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $slotKey = (string)($_POST['slot_key'] ?? '');
    $db->prepare("DELETE FROM td_dev_eval_signoff WHERE doc_id=? AND slot_key=?")->execute([$docId, $slotKey]);
    if ($slotKey === 'gm') { // 取消總經理決行簽核時，若已結案要跟著解除結案狀態，避免結案時間跟實際簽核狀態脫勾
        $db->prepare("UPDATE td_dev_eval SET status='submitted', closed_at=NULL WHERE id=? AND status='closed'")->execute([$docId]);
    }
    jout(['success'=>true]);

// ── 補資料用：具操作確認密碼資格者，輸入一次密碼，逐格指定原簽核人一次補齊 ──────
case 'backfill_sign_all':
    needEdit($perms);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $assignments = json_decode((string)($_POST['assignments'] ?? '[]'), true);
    if (!is_array($assignments) || !$assignments) jout(['success'=>false,'message'=>'沒有要補登的欄位']);
    $chk = eg_confirm_password_verify($db, $uid, $password);
    if (!$chk['ok']) jout(['success'=>false,'message'=>$chk['msg']]);
    $st = $db->prepare("SELECT status, decision FROM td_dev_eval WHERE id=? AND is_deleted=0");
    $st->execute([$docId]);
    $curRow = $st->fetch(PDO::FETCH_ASSOC);
    $curStatus = $curRow === false ? false : $curRow['status'];
    $curDecision = $curRow === false ? null : ($curRow['decision'] ?: null); // 補登沒有逐欄選決行結果的欄位，沿用表頭目前的值
    if ($curStatus === false) jout(['success'=>false,'message'=>'找不到該筆']);
    if ($curStatus === 'draft') jout(['success'=>false,'message'=>'此表單尚未送出，請先送出，或改用系統管理員的「全部自動簽核」功能']);

    $signedSlots = [];
    $db->beginTransaction();
    try {
        foreach ($assignments as $a) {
            $slotKey = (string)($a['slot_key'] ?? '');
            $signerUid = (int)($a['signer_user_id'] ?? 0);
            $note = trim((string)($a['note'] ?? ''));
            $signedAt = trim((string)($a['signed_at'] ?? ''));
            if (!isset(TD_DEV_EVAL_SLOTS[$slotKey]) || !$signerUid) continue;
            $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
            $st->execute([$signerUid]);
            $signerName = $st->fetchColumn();
            if (!$signerName) continue;
            $slotDecision = in_array($slotKey, ['prod_decision','gm'], true) ? $curDecision : null;
            $st = $db->prepare("INSERT INTO td_dev_eval_signoff
                (doc_id, slot_key, note, decision_value, signed_by, signed_by_name, signed_at, is_backfill, backfill_by_name)
                VALUES (?,?,?,?,?,?,?,1,?)
                ON DUPLICATE KEY UPDATE note=VALUES(note), decision_value=VALUES(decision_value), signed_by=VALUES(signed_by), signed_by_name=VALUES(signed_by_name),
                    signed_at=VALUES(signed_at), is_backfill=1, backfill_by_name=VALUES(backfill_by_name)");
            $st->execute([$docId, $slotKey, $note ?: null, $slotDecision, $signerUid, $signerName, $signedAt ?: date('Y-m-d H:i:s'), $uname]);
            $signedSlots[] = $slotKey;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jout(['success'=>false,'message'=>'補登失敗：'.$e->getMessage()]); }
    foreach ($signedSlots as $sk) td_dev_eval_advance_after_sign($db, $docId, $sk, $uid, $uname);
    jout(['success'=>true]);

// ── AS 文件編號綁定（本頁自身模板）────────────────────────────────
case 'asdoc_list':
    needView($perms);
    jout(['success'=>true,'docs'=>eg_asdoc_list($db)]);

case 'asdoc_get':
    needView($perms);
    jout(['success'=>true,'as_doc'=>eg_asdoc_get($db,'td_dev_eval')]);

case 'as_doc_save':
    needAdmin($perms);
    $docId = (int)($_POST['doc_id'] ?? 0);
    eg_asdoc_save($db, 'td_dev_eval', $docId, $uname);
    jout(['success'=>true,'as_doc'=>eg_asdoc_get($db,'td_dev_eval')]);

case 'print_get':
    needView($perms);
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT h.*, COALESCE(ds.D_Setting_Id, h.part_no_text,'') AS part_no
                         FROM td_dev_eval h LEFT JOIN d_setting ds ON ds.d_id = h.part_d_id
                         WHERE h.id=? AND h.is_deleted=0");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['success'=>false,'message'=>'找不到該筆']);

    // 列印紀錄：本動作只有列印會呼叫（含批次列印逐筆），清單「列印日期」讀這裡的最後一筆
    td_dev_eval_log_print($db, $id, $uid, $uname);

    $st = $db->prepare("SELECT item_no, result FROM td_dev_eval_answer WHERE doc_id=?");
    $st->execute([$id]);
    $answers = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $answers[(int)$a['item_no']] = $a['result'];

    $st = $db->prepare("SELECT * FROM td_dev_eval_signoff WHERE doc_id=?");
    $st->execute([$id]);
    $signRows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) $signRows[$s['slot_key']] = $s;

    $asDoc = eg_asdoc_get($db, 'td_dev_eval');
    $bizDate = $doc['fill_date'] ?: substr((string)$doc['created_at'], 0, 10);
    jout([
        'success'=>true, 'doc'=>$doc, 'answers'=>$answers, 'signoffs'=>$signRows,
        'company_name'=>eg_company_full_name($db),
        'as_doc_no'=>eg_asdoc_no_asof($db, 'td_dev_eval', $bizDate),
        'as_doc_name'=>$asDoc['doc_name'] ?? '產品開發評估表',
    ]);

default:
    jout(['success'=>false,'message'=>'未知動作']);
}
