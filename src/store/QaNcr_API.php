<?php
/**
 * 不合格品管制記錄表（2-QA-01-03）API —— 2026-09-18 建立
 *
 * 權限（qa_ncr_lib.php ncr_perms()）：
 *   ncr_admin（或品管的 qc_manage_settings）＝補填原因/責任單位/處理方式/結案、紙本補登、改設定
 *   ncr_view（或品管既有的檢閱類角色）      ＝唯讀（含列印）
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 *
 * 來源欄位一律即時讀（qa_ncr_lib.php），不做快照——來源單事後結案或改了處理方式，
 * 這本登錄簿要跟著變；存快照會永遠停在存檔那天而且看不出來是舊的。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/qa_ncr_lib.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';
include_once $document_root . '/EGsystem/src/common/position_history_lib.php';

function jout($a = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ncr_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['ncr_csrf'])) $_SESSION['ncr_csrf'] = bin2hex(random_bytes(16));

$P = ncr_perms($db, ncr_current_user($db));
$uid = (int)$P['uid']; $uname = (string)$P['name'];
if (!$uid)          jerr('未登入或帳號非在職狀態', 401);
if (!$P['canView']) jerr('您沒有不合格品管制記錄表的檢閱權限，請洽管理員於「權限設定」開通', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$WRITE = ['row_save', 'row_delete', 'manual_add', 'setting_save', 'asdoc_save', 'print_log'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['ncr_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code'=>'CSRF']);
    if (!$P['canAdmin']) jerr('需要「管制記錄管理員」或品管「管理檢驗設定」權限才能修改', 403);
}

/** 期間：預設本年度。年度或自訂區間二選一。 */
function ncrRange(): array {
    $f = trim((string)($_GET['from'] ?? $_POST['from'] ?? ''));
    $t = trim((string)($_GET['to']   ?? $_POST['to']   ?? ''));
    $ok = '/^\d{4}-\d{2}-\d{2}$/';
    if (preg_match($ok, $f) && preg_match($ok, $t) && $f <= $t) return [$f, $t];
    $y = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
    if ($y < 2000 || $y > 2100) $y = (int)date('Y');
    return [sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)];
}
function ncrDate($v): ?string {
    $d = substr(trim((string)$v), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
}

switch ($action) {

case 'csrf':
    jout(['csrf' => $_SESSION['ncr_csrf']]);

case 'list': {
    list($from, $to) = ncrRange();
    $srcSel = $_GET['sources'] ?? '';
    $srcs = null;
    if ($srcSel !== '') {
        $want = array_filter(array_map('trim', explode(',', (string)$srcSel)));
        $srcs = array_values(array_intersect($want, array_keys(ncr_sources())));   // 白名單
    }
    $rows = ncr_rows($db, $from, $to, $srcs !== null && $srcs ? ['sources'=>$srcs] : []);
    // 關鍵字：掃過畫面上看得到的所有欄位，多個關鍵字＝每個都要命中（可分散在不同欄位）
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        $words = preg_split('/\s+/u', $kw);
        $rows = array_values(array_filter($rows, function ($r) use ($words) {
            $hay = mb_strtolower(implode(' ', [$r['insp_date'], $r['source_name'], $r['client_name'],
                $r['part_name'], $r['drawing_no'], $r['order_no'], $r['cause'], $r['resp_unit'],
                $r['disposition'], $r['disposition_note'], $r['remark'], $r['src_extra']]));
            foreach ($words as $w) { if ($w !== '' && mb_strpos($hay, mb_strtolower($w)) === false) return false; }
            return true;
        }));
    }
    // 結案篩選
    $cl = (string)($_GET['closed'] ?? 'all');
    if ($cl === 'open')   $rows = array_values(array_filter($rows, function ($r) { return !$r['is_closed']; }));
    if ($cl === 'closed') $rows = array_values(array_filter($rows, function ($r) { return  $r['is_closed']; }));
    if ((string)($_GET['aero'] ?? '') === '1') $rows = array_values(array_filter($rows, function ($r) { return $r['is_aero']; }));

    $stat = ['total'=>count($rows), 'closed'=>0, 'aero'=>0, 'no_disp'=>0];
    foreach ($rows as $r) {
        if ($r['is_closed']) $stat['closed']++;
        if ($r['is_aero'])   $stat['aero']++;
        if (trim((string)$r['disposition']) === '') $stat['no_disp']++;
    }
    jout(['rows'=>$rows, 'from'=>$from, 'to'=>$to, 'stat'=>$stat,
          'sources'=>ncr_sources(), 'enabled'=>ncr_enabled_sources($db),
          'dispositions'=>ncr_dispositions(), 'perm'=>['admin'=>$P['canAdmin']]]);
}

/** 補填一列（來源列的補充欄位，或紙本補登列） */
case 'row_save': {
    $src = trim((string)($_POST['source'] ?? ''));
    $key = trim((string)($_POST['source_key'] ?? ''));
    if ($src === '' || $key === '') jerr('缺少來源');
    if (!isset(ncr_sources()[$src]) && $src !== 'manual') jerr('不支援的來源：' . $src);   // 白名單（鐵律8）
    // 品質異常處理單自己就管好原因分類／責任單位／處置／結案，這本登錄簿一律唯讀（前端已擋，後端同規則再擋一次）
    if ($src === 'qa') jerr('品質異常處理單的內容請到該單修改，這一頁只顯示不修改', 403);
    $disp = trim((string)($_POST['disposition'] ?? ''));
    if ($disp !== '' && !in_array($disp, ncr_dispositions(), true)) jerr('不支援的處理方式：' . $disp);

    /* 只更新「有送過來」的欄位（array_key_exists，本專案既有慣例）：
       沒送＝呼叫端不打算動它 → 保留原值；送空字串＝真的要清空。
       不這樣分的話，只想改一格的呼叫會把其餘欄位連帶清掉。 */
    $cur = [];
    try {
        $st = $db->prepare("SELECT * FROM qa_ncr_log WHERE source=? AND source_key=? LIMIT 1");
        $st->execute([$src, $key]);
        $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $TXT = ['client_name'=>100, 'part_name'=>200, 'drawing_no'=>100, 'order_no'=>60,
            'cause'=>500, 'resp_unit'=>100, 'disposition_note'=>300, 'remark'=>500];
    $v = [];
    foreach ($TXT as $f => $len) {
        $v[$f] = array_key_exists($f, $_POST)
            ? mb_substr(trim((string)$_POST[$f]), 0, $len)
            : (string)($cur[$f] ?? '');
        if ($v[$f] === '') $v[$f] = null;
    }
    $v['disposition'] = array_key_exists('disposition', $_POST) ? ($disp ?: null) : ($cur['disposition'] ?? null);
    $v['insp_date']   = array_key_exists('insp_date', $_POST)   ? ncrDate($_POST['insp_date'])   : ($cur['insp_date'] ?? null);
    $v['closed_date'] = array_key_exists('closed_date', $_POST) ? ncrDate($_POST['closed_date']) : ($cur['closed_date'] ?? null);
    $v['qty']         = array_key_exists('qty', $_POST)
        ? (is_numeric($_POST['qty']) ? (float)$_POST['qty'] : null)
        : ($cur['qty'] ?? null);
    $v['is_aero']   = array_key_exists('is_aero', $_POST)   ? ((int)$_POST['is_aero']   ? 1 : 0) : (int)($cur['is_aero'] ?? 0);
    $v['is_closed'] = array_key_exists('is_closed', $_POST) ? ((int)$_POST['is_closed'] ? 1 : 0) : (int)($cur['is_closed'] ?? 0);
    // 勾了結案卻沒有結案日期＝自動補今天（表上留白會變成「結案了但不知道哪天」）
    if ($v['is_closed'] && !$v['closed_date']) $v['closed_date'] = date('Y-m-d');

    try {
        if (!empty($cur['id'])) {
            $db->prepare("UPDATE qa_ncr_log SET insp_date=?, client_name=?, part_name=?, drawing_no=?, qty=?,
                          order_no=?, cause=?, resp_unit=?, disposition=?, disposition_note=?, is_aero=?,
                          is_closed=?, closed_date=?, remark=?, updated_at=NOW(), updated_by=?, updated_by_name=?
                          WHERE id=?")
               ->execute([$v['insp_date'], $v['client_name'], $v['part_name'], $v['drawing_no'], $v['qty'],
                          $v['order_no'], $v['cause'], $v['resp_unit'], $v['disposition'], $v['disposition_note'],
                          $v['is_aero'], $v['is_closed'], $v['closed_date'], $v['remark'], $uid, $uname, (int)$cur['id']]);
        } else {
            $db->prepare("INSERT INTO qa_ncr_log (source, source_key, insp_date, client_name, part_name, drawing_no,
                          qty, order_no, cause, resp_unit, disposition, disposition_note, is_aero, is_closed,
                          closed_date, remark, created_by, created_by_name)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$src, $key, $v['insp_date'], $v['client_name'], $v['part_name'], $v['drawing_no'],
                          $v['qty'], $v['order_no'], $v['cause'], $v['resp_unit'], $v['disposition'],
                          $v['disposition_note'], $v['is_aero'], $v['is_closed'], $v['closed_date'],
                          $v['remark'], $uid, $uname]);
        }
    } catch (Throwable $e) { jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout();
}

/** 紙本補登（系統裡沒有來源單的那幾筆） */
case 'manual_add': {
    $d = ncrDate($_POST['insp_date'] ?? '');
    if (!$d) jerr('請填檢驗日期');
    $key = 'M' . date('YmdHis') . substr((string)mt_rand(100, 999), 0, 3);
    try {
        $db->prepare("INSERT INTO qa_ncr_log (source, source_key, insp_date, client_name, part_name, drawing_no,
                      qty, order_no, cause, resp_unit, disposition, created_by, created_by_name)
                      VALUES ('manual',?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$key, $d,
               mb_substr(trim((string)($_POST['client_name'] ?? '')), 0, 100) ?: null,
               mb_substr(trim((string)($_POST['part_name'] ?? '')), 0, 200) ?: null,
               mb_substr(trim((string)($_POST['drawing_no'] ?? '')), 0, 100) ?: null,
               is_numeric($_POST['qty'] ?? '') ? (float)$_POST['qty'] : null,
               mb_substr(trim((string)($_POST['order_no'] ?? '')), 0, 60) ?: null,
               mb_substr(trim((string)($_POST['cause'] ?? '')), 0, 500) ?: null,
               mb_substr(trim((string)($_POST['resp_unit'] ?? '')), 0, 100) ?: null,
               in_array((string)($_POST['disposition'] ?? ''), ncr_dispositions(), true) ? (string)$_POST['disposition'] : null,
               $uid, $uname]);
    } catch (Throwable $e) { jerr('新增失敗：' . $e->getMessage(), 500); }
    jout(['source_key' => $key]);
}

/** 刪除：只准刪「紙本補登」那種，來源列的補充只能清空不能刪掉來源事件 */
case 'row_delete': {
    $src = trim((string)($_POST['source'] ?? ''));
    $key = trim((string)($_POST['source_key'] ?? ''));
    if ($src !== 'manual') jerr('只有「紙本補登」的列可以刪除。來源自品質異常單／矯正單／退貨／QC檢驗的事件不可以從這裡刪掉——那會讓不合格品紀錄憑空消失，要處理請到來源模組。');
    try { $db->prepare("DELETE FROM qa_ncr_log WHERE source='manual' AND source_key=?")->execute([$key]); }
    catch (Throwable $e) { jerr('刪除失敗：' . $e->getMessage(), 500); }
    jout();
}

/* ── 列印（ai-rules/16）──────────────────────────────── */
case 'print_meta': {
    list($from, $to) = ncrRange();
    $docId = eg_asdoc_id($db, NCR_ASDOC_MODULE);
    $doc   = eg_asdoc_get($db, NCR_ASDOC_MODULE);
    // 業務日期＝期間最後一天（這是一份區間登錄簿，沒有單一單據日期）
    $biz = $to;
    $dept = ''; $pos = '';
    try {
        $snap = eg_position_snapshot_at($db, $uid, $biz);
        if ($snap) {
            $r = null;
            foreach ($snap as $x) { if (!empty($x['is_main'])) { $r = $x; break; } }
            if (!$r) $r = $snap[0];
            $dept = (string)($r['department_name'] ?? ''); $pos = (string)($r['position_name'] ?? '');
        }
    } catch (Throwable $e) {}
    jout(['company'=>ncr_company_name($db),
          'doc'=>$doc ? ['id'=>(int)$doc['id'], 'doc_no'=>$doc['doc_no'], 'doc_name'=>$doc['doc_name']] : null,
          'doc_no_print'=>eg_asdoc_no_asof_id($db, $docId, $biz),
          'biz_date'=>$biz, 'stamp_tpl'=>ncr_stamp_tpl($db),
          'maker_name'=>$uname, 'maker'=>['dept'=>$dept, 'position'=>$pos]]);
}

case 'print_log': {
    $name = mb_substr(trim((string)($_POST['doc_name'] ?? '')), 0, 200);
    if ($name !== '') { try { eg_print_log_add($db, ['source'=>'qa_ncr_log', 'doc_name'=>$name, 'doc_kind'=>'form']); } catch (Throwable $e) {} }
    jout();
}

/* ── 設定（限管理員）────────────────────────────────── */
case 'setting_get': {
    if (!$P['canAdmin']) jerr('需要管理員權限', 403);
    jout(['as_docs'=>eg_asdoc_list($db), 'doc_id'=>eg_asdoc_id($db, NCR_ASDOC_MODULE),
          'doc'=>eg_asdoc_get($db, NCR_ASDOC_MODULE),
          'sources'=>ncr_sources(), 'enabled'=>ncr_enabled_sources($db),
          'stamp_tpls'=>ncr_stamp_tpl_options($db), 'stamp_tpl_id'=>ncr_stamp_tpl_id($db)]);
}

case 'setting_save': {
    if (array_key_exists('sources', $_POST)) {
        $want = json_decode((string)$_POST['sources'], true);
        if (!is_array($want)) jerr('資料格式錯誤');
        $srcs = array_values(array_intersect($want, array_keys(ncr_sources())));
        if (!$srcs) jerr('至少要保留一個來源，否則這張表會永遠是空的');
        ncr_param_save($db, 'sources', $srcs, $uname);
    }
    if (array_key_exists('stamp_tpl_id', $_POST)) {
        $t = (int)$_POST['stamp_tpl_id'];
        if ($t) {
            $c = $db->prepare("SELECT id FROM stamp_template WHERE id=? AND is_active=1"); $c->execute([$t]);
            if (!$c->fetchColumn()) jerr('選擇的圖章模板不存在或已停用');
        }
        ncr_param_save($db, 'stamp_tpl_id', $t, $uname);
    }
    jout();
}

case 'asdoc_save': {
    $id = (int)($_POST['doc_id'] ?? 0);
    if ($id) {
        $c = $db->prepare("SELECT id FROM as_document WHERE id=? AND is_deleted=0"); $c->execute([$id]);
        if (!$c->fetchColumn()) jerr('選擇的 AS 文件不存在或已刪除');
    }
    eg_asdoc_save($db, NCR_ASDOC_MODULE, $id, $uname);
    jout();
}

default:
    jerr('未知的 action：' . $action, 404);
}
