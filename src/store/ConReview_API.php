<?php
/**
 * 合約訂單審查表（AS 2-SM-01-06）API
 * 資料/權限/簽核人解析說明見 src/common/con_review_lib.php
 * 讀：GET；寫：POST，一律 CSRF（cnrv_need_csrf()）。
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/con_review_lib.php';

function jout($a) { echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400) { http_response_code($code); echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

if (!isset($_SESSION['userName'])) jerr('未登入', 401);

$db = (new DBConnection())->getPDO();
cnrv_ensure_schema($db);
$me    = cnrv_current_user($db);
$perms = cnrv_perms($db, $me);
$uid   = $me ? (int)$me['id'] : 0;
$uname = $me ? (string)$me['user_cname'] : '';
if (!$perms['canView']) jerr('沒有權限', 403);

$action = (string)($_REQUEST['action'] ?? '');

try {
switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    jout(['perms'=>$perms, 'uid'=>$uid, 'uname'=>$uname, 'csrf'=>cnrv_csrf_token(), 'today'=>date('Y-m-d'),
          'departments'=>$depts, 'decisions'=>CNRV_DECISIONS,
          'as_doc'=>eg_asdoc_get($db, CNRV_AS_MODULE),
          'stamp_tpl'=>cnrv_stamp_tpl($db, cnrv_stamp_tpl_id($db))]);
}

/* ---- 範本項目（僅管理員） ---- */
case 'tpl_list': {
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    jout(['items'=>cnrv_tpl_items_get($db)]);
}
case 'tpl_save': {
    cnrv_need_csrf();
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    $data = [
        'group_label'=>trim((string)($_POST['group_label'] ?? '')),
        'item_text'=>trim((string)($_POST['item_text'] ?? '')),
        'dept_id'=>(int)($_POST['dept_id'] ?? 0) ?: null,
        'options'=>array_filter(array_map('trim', explode(',', (string)($_POST['options'] ?? '')))),
        'default_value'=>trim((string)($_POST['default_value'] ?? '')),
        'default_extra'=>trim((string)($_POST['default_extra'] ?? '')),
        'is_active'=>!empty($_POST['is_active']),
    ];
    try { $id = cnrv_tpl_item_save($db, (int)($_POST['id'] ?? 0), $data, $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['id'=>$id, 'items'=>cnrv_tpl_items_get($db)]);
}
case 'tpl_reorder': {
    cnrv_need_csrf();
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
    if (!$ids) jerr('缺少排序清單');
    cnrv_tpl_reorder($db, $ids);
    jout(['items'=>cnrv_tpl_items_get($db)]);
}
case 'tpl_delete': {
    cnrv_need_csrf();
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    cnrv_tpl_item_delete($db, (int)($_POST['id'] ?? 0));
    jout(['items'=>cnrv_tpl_items_get($db)]);
}
case 'tpl_seed_from_dev_eval': {
    cnrv_need_csrf();
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    try { $n = cnrv_tpl_seed_from_dev_eval($db, $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['added'=>$n, 'items'=>cnrv_tpl_items_get($db)]);
}

/* ---- 訂單挑選（建立審查用） ---- */
case 'order_search': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    if (mb_strlen($kw) < 2) jout(['orders'=>[]]);
    $like = '%'.$kw.'%';
    $st = $db->prepare("SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Qty, ot.Order_date, ot.Delivery_date,
                               ot.Client_name, cl.customer AS client_name_txt, t.proc_name AS tag_proc_name, ot.as_tag_scope
                        FROM order_track ot
                        LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                        JOIN ot_as_proc_tag t ON t.tag_id = ot.as_tag_id AND t.kind='process'
                        WHERE (ot.Order_status IS NULL OR ot.Order_status<>6)
                          AND (ot.Order_oo LIKE ? OR ot.d_id LIKE ? OR ot.Client_name LIKE ? OR cl.customer LIKE ?)
                        ORDER BY ot.Order_id DESC LIMIT 30");
    $st->execute([$like, $like, $like, $like]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $map = $rows ? cnrv_doc_status_map($db, array_column($rows, 'Order_id')) : [];
    foreach ($rows as &$r) {
        $r['doc_id'] = $map[(int)$r['Order_id']]['id'] ?? 0;
        $r['tag_label'] = $r['tag_proc_name'] ? (($r['as_tag_scope']==='full' ? '全製含' : '單製') . $r['tag_proc_name']) : '';
    }
    jout(['orders'=>$rows]);
}
case 'order_get': {
    $o = cnrv_order_get($db, (int)($_GET['order_id'] ?? 0));
    if (!$o) jerr('找不到此訂單或該訂單已取消', 404);
    $o['doc_id'] = cnrv_doc_by_order($db, (int)$o['Order_id']);
    $o['need_review'] = cnrv_need_review($o);
    jout(['order'=>$o]);
}

/* ---- 建議建立清單（批次一鍵建立） ---- */
case 'suggest_list': {
    if (!$perms['canCreate']) jerr('沒有權限', 403);
    jout(cnrv_suggest_list($db, (int)($_GET['days'] ?? 30), (int)($_GET['limit'] ?? 300)));
}
case 'batch_create': {
    cnrv_need_csrf();
    if (!$perms['canCreate']) jerr('沒有建立權限', 403);
    $ids = array_filter(array_map('intval', explode(',', (string)($_POST['order_ids'] ?? ''))));
    if (!$ids) jerr('沒有勾選任何訂單');
    $r = cnrv_batch_create($db, $ids, $uid, $uname);
    jout(['created_count'=>count($r['created']), 'failed'=>$r['failed']]);
}

/* ---- 表單建立／讀取／清單 ---- */
case 'create': {
    cnrv_need_csrf();
    if (!$perms['canCreate']) jerr('沒有建立權限', 403);
    try { $id = cnrv_create($db, (int)($_POST['order_id'] ?? 0), $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['id'=>$id]);
}
case 'get': {
    $id = (int)($_GET['id'] ?? 0);
    $doc = cnrv_get($db, $id);
    if (!$doc) jerr('找不到此表單', 404);
    $items = cnrv_items_get($db, $id);
    $deptIds = cnrv_doc_dept_ids($db, $id);
    $deptSign = cnrv_dept_sign_map($db, $id);
    $deptNameSt = $deptIds ? $db->prepare("SELECT id,name FROM department WHERE id IN (" . implode(',', array_fill(0, count($deptIds), '?')) . ")") : null;
    $deptNames = [];
    if ($deptNameSt) { $deptNameSt->execute($deptIds); foreach ($deptNameSt->fetchAll(PDO::FETCH_ASSOC) as $d) $deptNames[(int)$d['id']] = $d['name']; }
    $depts = [];
    foreach ($deptIds as $d) {
        $depts[] = [
            'dept_id'=>$d, 'dept_name'=>$deptNames[$d] ?? ('#'.$d),
            'signed'=>!empty($deptSign[$d]['signed_by']),
            'signed_by_name'=>$deptSign[$d]['signed_by_name'] ?? null,
            'signed_at'=>$deptSign[$d]['signed_at'] ?? null,
            'note'=>$deptSign[$d]['note'] ?? null,
            'can_fill'=>cnrv_can_fill_dept($db, $uid, $d, $perms['isAdmin']),
            // is_auto_sign／is_backfill 只給管理員在畫面上看（2026-10-05 使用者要求「把自動審核紀錄
            // 留在LOG中只提供管理員查看，其他前端一律是正常簽核」），一般使用者看到的 signed_by_name
            // 本來就已經是真人姓名，不必再隱藏這兩個旗標本身。
            'is_auto_sign'=>(bool)($deptSign[$d]['is_auto_sign'] ?? false),
            'is_backfill'=>(bool)($deptSign[$d]['is_backfill'] ?? false),
        ];
    }
    jout([
        'doc'=>$doc, 'items'=>$items, 'depts'=>$depts,
        'all_depts_signed'=>cnrv_all_depts_signed($db, $id),
        'can_decide'=>cnrv_can_decide($db, $uid, $perms['isAdmin']),
        'can_edit_header'=>($doc['status']==='draft' && ((int)$doc['created_by']===$uid || $perms['canAdmin'])),
        'gm_signer'=>cnrv_gm_signer($db, $id),
        'is_admin'=>$perms['isAdmin'],
    ]);
}
case 'list': {
    $r = cnrv_list($db, [
        'status'=>(string)($_GET['status'] ?? ''), 'keyword'=>(string)($_GET['keyword'] ?? ''),
        'limit'=>(int)($_GET['limit'] ?? 50), 'offset'=>(int)($_GET['offset'] ?? 0),
    ]);
    // has_admin_sign（這張單有沒有管理員自動/代為簽核）只提供管理員查看（2026-10-05 使用者要求），
    // 非管理員的清單一律拿掉這個欄位，前端「顯示管理員代簽標記」開關也只對管理員輸出。
    if (!$perms['isAdmin']) { foreach ($r['rows'] as &$row) unset($row['has_admin_sign']); unset($row); }
    jout($r);
}

/* ---- 填寫／簽核／決行／核准 ---- */
case 'item_save': {
    cnrv_need_csrf();
    try {
        cnrv_item_save($db, (int)($_POST['doc_id'] ?? 0), (int)($_POST['item_id'] ?? 0), $uid, $perms['isAdmin'],
            ($_POST['value'] ?? '') === '' ? null : (string)$_POST['value'],
            ($_POST['note'] ?? '') === '' ? null : (string)$_POST['note'], $uname);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout([]);
}
case 'submit': {
    cnrv_need_csrf();
    $doc = cnrv_get($db, (int)($_POST['doc_id'] ?? 0));
    if (!$doc) jerr('找不到此表單', 404);
    if ((int)$doc['created_by'] !== $uid && !$perms['canAdmin']) jerr('只有建立者本人或管理員可以送出', 403);
    try { cnrv_submit($db, (int)$doc['id'], $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout([]);
}
case 'dept_sign': {
    cnrv_need_csrf();
    try {
        cnrv_dept_sign($db, (int)($_POST['doc_id'] ?? 0), (int)($_POST['dept_id'] ?? 0), $uid, $uname,
            ($_POST['note'] ?? '') === '' ? null : (string)$_POST['note'], $perms['isAdmin']);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout([]);
}
case 'decide': {
    cnrv_need_csrf();
    try {
        cnrv_decide($db, (int)($_POST['doc_id'] ?? 0), $uid, $uname, (string)($_POST['decision'] ?? ''),
            ($_POST['note'] ?? '') === '' ? null : (string)$_POST['note'], $perms['isAdmin']);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout([]);
}
case 'approve': {
    cnrv_need_csrf();
    try { cnrv_approve($db, (int)($_POST['doc_id'] ?? 0), $uid, $uname, $perms['isAdmin']); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout([]);
}
case 'admin_auto_fill_sign': {
    cnrv_need_csrf();
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    try {
        $r = cnrv_admin_auto_fill_sign($db, (int)($_POST['doc_id'] ?? 0), (string)($_POST['sign_date'] ?? ''), $uid, $uname);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout($r);
}
case 'delete': {
    cnrv_need_csrf();
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    try { cnrv_delete($db, (int)($_POST['doc_id'] ?? 0), $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout([]);
}

/* ---- 列印（ai-rules/16：公司全名、表頭取綁定AS文件表單名稱、版次依業務日期回推、頁尾右下AS編號；
         ai-rules/18：簽章一律走 eg_stamp.js 圖章＋日期，不印純文字姓名） ---- */
case 'print_get': {
    $id = (int)($_GET['id'] ?? 0);
    $doc = cnrv_get($db, $id);
    if (!$doc) jerr('找不到此表單', 404);
    $items = cnrv_items_get($db, $id);
    $deptIds = cnrv_doc_dept_ids($db, $id);
    $deptSign = cnrv_dept_sign_map($db, $id);
    $deptNameSt = $deptIds ? $db->prepare("SELECT id,name FROM department WHERE id IN (" . implode(',', array_fill(0, count($deptIds), '?')) . ")") : null;
    $deptNames = [];
    if ($deptNameSt) { $deptNameSt->execute($deptIds); foreach ($deptNameSt->fetchAll(PDO::FETCH_ASSOC) as $d) $deptNames[(int)$d['id']] = $d['name']; }
    $depts = [];
    foreach ($deptIds as $d) {
        $depts[] = [
            'dept_id'=>$d, 'dept_name'=>$deptNames[$d] ?? ('#'.$d),
            'signed'=>!empty($deptSign[$d]['signed_by']),
            'signed_by_name'=>$deptSign[$d]['signed_by_name'] ?? null,
            'signed_at'=>$deptSign[$d]['signed_at'] ?? null,
            'note'=>$deptSign[$d]['note'] ?? null,
        ];
    }
    $asDoc = eg_asdoc_get($db, CNRV_AS_MODULE);
    jout([
        'doc'=>$doc, 'items'=>$items, 'depts'=>$depts,
        'company_name'=>eg_company_full_name($db),
        'as_doc_name'=>$asDoc['doc_name'] ?? '合約訂單審查表',
        'as_doc_no'=>eg_asdoc_no_asof($db, CNRV_AS_MODULE, (string)$doc['business_date']),
        'stamp_tpl'=>cnrv_stamp_tpl($db, cnrv_stamp_tpl_id($db)),
    ]);
}

/* ---- 列印設定（AS 文件編號綁定＋簽章圖章模板）：僅管理員，比照 stock.php 領料需求單既有做法 ---- */
case 'print_setting_get': {
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    jout([
        'as_docs'=>eg_asdoc_list($db),
        'as_doc_id'=>eg_asdoc_id($db, CNRV_AS_MODULE),
        'as_doc'=>eg_asdoc_get($db, CNRV_AS_MODULE),
        'stamp_tpls'=>cnrv_stamp_tpl_options($db),
        'stamp_tpl_id'=>cnrv_stamp_tpl_id($db),
    ]);
}
case 'print_setting_save': {
    cnrv_need_csrf();
    if (!$perms['canAdmin']) jerr('沒有權限', 403);
    try {
        eg_asdoc_save($db, CNRV_AS_MODULE, (int)($_POST['as_doc_id'] ?? 0), $uname);
        cnrv_stamp_tpl_save($db, (int)($_POST['stamp_tpl_id'] ?? 0), $uname);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout([]);
}

default: jerr('無效的操作');
}
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'系統錯誤'], JSON_UNESCAPED_UNICODE);
}
