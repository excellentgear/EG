<?php
/**
 * ToolInq_API.php — 刀具詢價（詢價單頁的「刀具詢價」分頁）資料介面
 * 共用邏輯一律在 src/common/tool_inq_lib.php；本檔只負責登入／權限／CSRF 守門與參數整理。
 * CSRF 與詢價單頁共用同一把 $_SESSION['inq_csrf']。
 */
session_start();
require_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/tool_inq_lib.php';
require_once __DIR__ . '/../common/asdoc_lib.php';
require_once __DIR__ . '/../common/print_log_lib.php';

function jout($ok, $data = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['success' => $ok], is_array($data) ? $data : ['message' => $data]), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = '', $http = 200) { if ($http !== 200) http_response_code($http); jout(false, ['message' => $msg, 'code' => $code]); }

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) jerr('尚未登入或登入已逾時，請重新整理頁面後再試', 'LOGIN', 401);

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
set_exception_handler(function ($e) { jerr('系統發生例外：' . $e->getMessage(), '', 500); });

inq_ensure_schema($db);
ti_ensure_schema($db);
$perm = inq_perms($db, $uid);
if (!$perm['canView']) jerr('沒有詢價單的使用權限', '', 403);
$uname = $perm['name'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (isset($_POST['action'])) {
    $tok = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['inq_csrf']) || !hash_equals((string)$_SESSION['inq_csrf'], $tok)) jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 'CSRF');
}
function tiJson($k) { $v = json_decode((string)($_POST[$k] ?? ''), true); return is_array($v) ? $v : []; }
function tiReqAdmin($perm) { if (empty($perm['canToolAdmin'])) jerr('只有刀具詢價管理員（或詢價單管理員）可以設定', '', 403); }
/** 附件權限：詢價單還在時＝能編輯該單的人；暫存中＝上傳者或管理員 */
function tiAttCanEdit(PDO $db, array $a, array $perm, int $uid): bool {
    if ((int)$a['inq_id'] > 0) {
        $st = $db->prepare("SELECT * FROM tool_inq WHERE id=?"); $st->execute([(int)$a['inq_id']]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        return $i && ti_can_edit($perm, $i);
    }
    return (int)$a['uploaded_by'] === $uid || !empty($perm['canToolAdmin']);
}

switch ($action) {

case 'init': {   // 表單所需的全部下拉資料一次給
    jout(true, ['opts' => ti_opts($db, false, true), 'settings' => ti_settings($db), 'profiles' => ti_profiles(),
                'att_cats' => ti_att_cats(), 'currencies' => ti_currencies(), 'years' => ti_years($db), 'perm' => $perm, 'locs' => ti_loc_rows($db, ti_stock_ids($db))]);
}

case 'list': {
    $res = ti_list($db, ['year' => $_GET['year'] ?? '', 'status' => $_GET['status'] ?? '', 'tool_type' => $_GET['tool_type'] ?? '',
                         'customer' => $_GET['customer'] ?? '', 'part' => $_GET['part'] ?? '', 'vendor' => $_GET['vendor'] ?? '', 'kw' => $_GET['kw'] ?? '', 'page' => $_GET['page'] ?? 1, 'psize' => $_GET['psize'] ?? 10]);
    jout(true, $res);
}

case 'get': {
    $i = ti_get($db, (int)($_GET['id'] ?? 0));
    if (!$i) jerr('查無此詢價單');
    $i['can_edit'] = ti_can_edit($perm, $i);
    jout(true, ['inq' => $i]);
}

/* ---- 列印：一家廠商一頁。vids＝指定印哪幾家（逗號分隔 vid）；沒給＝全部（不採用的廠商除外） ---- */
case 'print_data': {
    $i = ti_get($db, (int)($_GET['id'] ?? 0));
    if (!$i) jerr('找不到這張詢價單');
    $vids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['vids'] ?? '')))));
    $vs = [];
    foreach ($i['vendors'] as $v) {
        if ($vids ? !in_array($v['vid'], $vids, true) : (int)$v['is_void']) continue;
        $live = inq_vendor_snapshot($db, $v['vendor_id_no']) ?: [];   // 電話／傳真／聯絡人以廠商主檔現況為準，主檔沒有才退回建單當時的快照
        $v['tel'] = (string)($live['contact_phone'] ?? $v['contact_phone']);
        $v['fax'] = (string)($live['contact_fax'] ?? '');
        $v['contact'] = (string)($live['contact_person'] ?? $v['contact_person']);
        $vs[] = $v;
    }
    if (!$vs && !$vids) $vs = $i['vendors'];   // 全部不採用時仍讓使用者印得出來
    jout(true, ['inq' => $i, 'vendors' => $vs, 'company' => inq_own_company($db), 'as_no' => eg_asdoc_no_asof($db, 'inquiry', $i['inq_date'])]);
}
case 'print_log': {   // ai-rules/23：每家廠商各留一筆列印紀錄
    $i = ti_get($db, (int)($_POST['id'] ?? 0));
    if ($i) {
        $vids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['vids'] ?? '')))));
        $targets = array_values(array_filter($i['vendors'], function ($v) use ($vids) { return !$vids || in_array($v['vid'], $vids, true); }));
        if (!$targets) $targets = [['vid' => 0, 'vendor_name' => '']];
        foreach ($targets as $v) {
            eg_print_log_add($db, ['source' => 'inquiry', 'doc_kind' => 'form', 'ref_table' => 'tool_inq_vendor', 'ref_id' => (int)$v['vid'],
                'doc_name' => '刀具詢價單 ' . $i['inq_no'] . ($v['vendor_name'] !== '' ? '　' . $v['vendor_name'] : ''), 'part_no' => (string)$i['part_no'], 'user_id' => $uid]);
        }
    }
    jout(true);
}

case 'part_search': jout(true, ['rows' => ti_part_search($db, (string)($_GET['kw'] ?? ''))]);
case 'part_gear': {
    $pid = (int)($_GET['part_id'] ?? 0);
    jout(true, ['customer' => ti_part_customer($db, $pid), 'gears' => ti_part_gears($db, $pid), 'tool' => ti_part_tool($db, $pid)]);
}
case 'vendor_search': jout(true, ['rows' => ti_vendor_search($db, (string)($_GET['kw'] ?? ''))]);

case 'save': {
    [$ok, $msg, $id] = ti_save($db, ['id' => $_POST['id'] ?? 0, 'part_id' => $_POST['part_id'] ?? 0, 'part_no_text' => $_POST['part_no_text'] ?? '',
        'customer_text' => $_POST['customer_text'] ?? '', 'qty' => $_POST['qty'] ?? '', 'gear_io' => $_POST['gear_io'] ?? '', 'spec' => tiJson('spec'),
        'tool_type' => $_POST['tool_type'] ?? '', 'tool' => tiJson('tool'), 'special' => tiJson('special'), 'remark' => $_POST['remark'] ?? '',
        'vendors' => tiJson('vendors'), 'temp_key' => $_POST['temp_key'] ?? ''], $perm, $uid, $uname);
    if (!$ok) jerr($msg);
    $i = ti_get($db, $id);
    jout(true, ['id' => $id, 'inq_no' => $i['inq_no']]);
}

case 'calc_due': {   // 下訂日＋交期天數（上班日／日曆日）→ 交期
    $o = (string)($_GET['order'] ?? ''); $d = (string)($_GET['days'] ?? '');
    if (!ti_date_ok($o) || !preg_match('/^\d{1,4}$/', $d)) jerr('請先填下訂日與交期天數');
    jout(true, ['due' => ti_add_days($db, $o, (int)$d, ($_GET['unit'] ?? 'work') === 'cal' ? 'cal' : 'work')]);
}

case 'vendor_save': {
    [$ok, $msg] = ti_vendor_save($db, (int)($_POST['vid'] ?? 0), $_POST, $perm);
    if (!$ok) jerr($msg);
    jout(true);
}

case 'tool_part': {   // 下訂後填刀號：check／create／suffix／bind／unbind
    $mode = (string)($_POST['mode'] ?? 'create');
    if (!in_array($mode, ['check', 'create', 'suffix', 'bind', 'unbind'], true)) jerr('操作不正確');
    [$ok, $msg, $x] = ti_tool_part_apply($db, (int)($_POST['vid'] ?? 0), (string)($_POST['tool_no'] ?? ''), $mode, (int)($_POST['d_id'] ?? 0), $uid, $perm);
    if (!$ok && empty($x['conflict'])) jerr($msg);
    jout($ok, array_merge(['message' => $msg], $x));
}

case 'alloc_stock': {   // 到貨後入庫（選儲位與數量）
    [$ok, $msg, $x] = ti_alloc_stock($db, (int)($_POST['vid'] ?? 0), (int)($_POST['loc_id'] ?? 0), (int)($_POST['qty'] ?? 0), (string)($_POST['date'] ?? ''), $uid, $perm);
    if (!$ok) jerr($msg);
    jout(true, array_merge(['message' => $msg], $x));
}
case 'ship_orders': jout(true, ['rows' => ti_ship_orders($db, (int)($_GET['vid'] ?? 0), (string)($_GET['kw'] ?? ''))]);
case 'alloc_ship': {    // 到貨後出貨（快速出貨，綁訂單）
    [$ok, $msg, $x] = ti_alloc_ship($db, (int)($_POST['vid'] ?? 0), (int)($_POST['order_id'] ?? 0), (int)($_POST['qty'] ?? 0), (string)($_POST['date'] ?? ''), $uid, $perm);
    if (!$ok) jerr($msg);
    jout(true, array_merge(['message' => $msg], $x));
}

case 'set_closed': {
    $id = (int)($_POST['id'] ?? 0);
    $st = $db->prepare("SELECT * FROM tool_inq WHERE id=?"); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) jerr('查無此詢價單');
    if (!ti_can_edit($perm, $r)) jerr('您沒有編輯這張詢價單的權限', '', 403);
    $db->prepare("UPDATE tool_inq SET is_closed=? WHERE id=?")->execute([!empty($_POST['closed']) ? 1 : 0, $id]);
    jout(true);
}

case 'delete': {
    [$ok, $msg] = ti_delete($db, (int)($_POST['id'] ?? 0), $perm);
    if (!$ok) jerr($msg);
    jout(true);
}

/* ---- 附件（鐵律5：DB 只存檔名，路徑讀取當下現場組；類別必選，後端同規則再擋） ---- */
case 'att_list': {
    $id = (int)($_GET['inq_id'] ?? 0);
    jout(true, ['rows' => ti_attaches($db, $id, mb_substr((string)($_GET['temp_key'] ?? ''), 0, 40))]);
}
case 'att_upload': {
    $inqId = (int)($_POST['inq_id'] ?? 0); $vid = (int)($_POST['vid'] ?? 0);
    $tk = mb_substr(trim((string)($_POST['temp_key'] ?? '')), 0, 40);
    $cat = (string)($_POST['category'] ?? '');
    if (!isset(ti_att_cats()[$cat])) jerr('請先選擇附件類別（詢價附件／廠商報價單／刀圖／其他）');
    if ($vid > 0) {   // 廠商層級：報價單／刀圖／其他，歸屬由廠商列推出詢價單
        $v = $db->prepare("SELECT inq_id FROM tool_inq_vendor WHERE vid=?"); $v->execute([$vid]);
        $inqId = (int)$v->fetchColumn();
        if ($inqId <= 0) jerr('查無此廠商列');
        if ($cat === 'inquiry') jerr('廠商回覆的附件請選「廠商報價單」「刀圖」或「其他」');
    } elseif ($cat !== 'inquiry' && $cat !== 'other' && $cat !== 'drawing') {
        jerr('詢價單層級的附件請選「詢價附件」「刀圖」或「其他」');
    }
    if ($inqId > 0) {
        $st = $db->prepare("SELECT * FROM tool_inq WHERE id=?"); $st->execute([$inqId]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i) jerr('查無此詢價單');
        if (!ti_can_edit($perm, $i)) jerr('您沒有編輯這張詢價單的權限', '', 403);
    } elseif ($tk === '') jerr('缺少暫存識別碼');
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 9) !== UPLOAD_ERR_OK) jerr('沒有收到檔案（或檔案過大）');
    $orig = (string)$_FILES['file']['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' || !preg_match('/^[a-z0-9]{1,8}$/', $ext)) jerr('檔案類型不正確');
    if (in_array($ext, ['php', 'phtml', 'exe', 'bat', 'cmd', 'com', 'scr', 'js', 'vbs', 'msi', 'dll'], true)) jerr('不允許上傳此類型檔案');
    if ((int)$_FILES['file']['size'] > 50 * 1024 * 1024) jerr('單一檔案請勿超過 50MB');
    $dir = ti_attach_dir($db);
    if (!eg_attach_ensure_dir($dir)) jerr('附件資料夾無法建立，請洽管理員確認 NAS 設定', '', 500);
    $fn = 'ti_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fn)) jerr('檔案寫入失敗（NAS 可能未連線）', '', 500);
    $now = ti_now($db);
    $db->prepare("INSERT INTO tool_inq_attach (inq_id, vid, temp_key, category, file_name, orig_name, file_size, mime, note, status, uploaded_by, uploaded_by_name, uploaded_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$inqId ?: null, $vid ?: null, $inqId ? null : $tk, $cat, $fn, mb_substr($orig, 0, 200), (int)$_FILES['file']['size'],
                  mb_substr((string)($_FILES['file']['type'] ?? ''), 0, 100), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200),
                  $inqId ? 'active' : 'temp', $uid, $uname, $now['n']]);
    jout(true, ['att_id' => (int)$db->lastInsertId()]);
}
case 'att_delete': {
    $st = $db->prepare("SELECT * FROM tool_inq_attach WHERE att_id=?"); $st->execute([(int)($_POST['att_id'] ?? 0)]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('查無此附件');
    if (!tiAttCanEdit($db, $a, $perm, $uid)) jerr('無權刪除此附件', '', 403);
    $db->prepare("DELETE FROM tool_inq_attach WHERE att_id=?")->execute([(int)$a['att_id']]);
    @unlink(ti_attach_dir($db) . basename((string)$a['file_name']));
    jout(true);
}
case 'att_download': {
    $st = $db->prepare("SELECT * FROM tool_inq_attach WHERE att_id=?"); $st->execute([(int)($_GET['att_id'] ?? 0)]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('查無此附件');
    if ((int)$a['inq_id'] <= 0 && (int)$a['uploaded_by'] !== $uid && empty($perm['canToolAdmin'])) jerr('無權下載此附件', '', 403);
    $path = ti_attach_dir($db) . basename((string)$a['file_name']);   // 鐵律5：路徑現場組
    if (!is_file($path)) jerr('檔案不存在（可能已被移除或 NAS 未連線）', '', 404);
    header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    eg_attach_send_disposition((string)($a['orig_name'] ?: $a['file_name']));
    readfile($path);
    exit;
}

/* ---- 管理員設定：選項（精度／材質／鍍層）與牙口數、槽數上限 ---- */
case 'settings_get': {
    tiReqAdmin($perm);
    jout(true, ['opts' => ti_opts($db, true), 'settings' => ti_settings($db), 'kinds' => ti_opt_kinds(), 'locations' => ti_loc_rows($db)]);
}
case 'setting_save': {
    tiReqAdmin($perm);
    $in = [];
    foreach (['starts_max', 'slots_max', 'print_review', 'spec_hob', 'spec_shaper', 'dup_mode', 'stock_locs'] as $k) if (array_key_exists($k, $_POST)) $in[$k] = $_POST[$k];
    [$ok, $msg] = ti_settings_save($db, $in);
    if (!$ok) jerr($msg);
    jout(true);
}
case 'opt_save': {
    tiReqAdmin($perm);
    [$ok, $msg, $oid] = ti_opt_save($db, (int)($_POST['opt_id'] ?? 0), (string)($_POST['kind'] ?? ''), (string)($_POST['name'] ?? ''), !empty($_POST['is_active']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0));
    if (!$ok) jerr($msg);
    jout(true, ['opt_id' => $oid]);
}
case 'opt_delete': {
    tiReqAdmin($perm);
    [$ok, $msg] = ti_opt_delete($db, (int)($_POST['opt_id'] ?? 0));
    if (!$ok) jerr($msg);
    jout(true);
}

default: jerr('無效的操作');
}
