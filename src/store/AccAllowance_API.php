<?php
/**
 * AccAllowance_API.php — 折讓單（views/ACC/allowance.php）端點
 * 計算與規則一律在 src/common/acc_allowance_lib.php。權限沿用 acc_perms()：
 * canView 看／搜尋、canEdit 建立·填證明單號·作廢·附件、canAdmin 改證明單號碼格式。
 * 寫入：POST + CSRF。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/acc_allowance_lib.php';

function aal_out(array $a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function aal_err(string $m, int $c = 400) { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }
function aal_u8(string $s): string {
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
    $c = @mb_convert_encoding($s, 'UTF-8', 'BIG-5,CP950,UTF-8');
    return ($c === false) ? '' : $c;
}
set_exception_handler(function (Throwable $e) {
    error_log('AccAllowance_API: ' . $e->getMessage());
    aal_err('伺服器錯誤：' . $e->getMessage(), 500);
});

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$u = acc_current_user($db);
if (!$u) aal_err('未登入或連線已逾時，請重新登入', 401);
$perms = aca_perms($db, $u);
if (!$perms['canView']) aal_err('您沒有折讓單的使用權限，請洽管理員指派角色', 403);
aca_ensure_schema($db);

if (empty($_SESSION['aca_csrf'])) $_SESSION['aca_csrf'] = bin2hex(random_bytes(16));
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$WRITE = ['create', 'set_no', 'void', 'format_save', 'attach_add', 'attach_del'];
if (in_array($action, $WRITE, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') aal_err('必須用 POST', 405);
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals($_SESSION['aca_csrf'], $tok)) aal_err('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403);
    if ($action === 'format_save') { if (empty($perms['canAdmin'])) aal_err('只有全站管理員或折讓單本頁管理員可以修改證明單號碼格式', 403); }
    elseif (empty($perms['canEdit'])) aal_err('無折讓單編輯權限（需「折讓單-會計」或本頁管理員角色）', 403);
}

switch ($action) {

case 'meta':
    aal_out([
        'csrf' => $_SESSION['aca_csrf'], 'perms' => $perms, 'user' => (string)$u['user_cname'],
        'format' => aca_format_get($db), 'default_format' => ACA_DEFAULT_FMT, 'tokens' => aca_format_tokens(),
        'tax_rate' => acc_tax_rate($db), 'today' => date('Y-m-d'),
    ]);

case 'search':
    $s = $_POST ?: $_GET;
    aal_out(aca_search($db, [
        'customer' => aal_u8(trim((string)($s['customer'] ?? ''))), 'part' => aal_u8(trim((string)($s['part'] ?? ''))),
        'date_from' => $s['date_from'] ?? '', 'date_to' => $s['date_to'] ?? '', 'src' => $s['src'] ?? 'all',
    ]));

case 'suggest_no': {
    $cid = trim((string)($_GET['customer_id'] ?? '')); $d = (string)($_GET['allow_date'] ?? date('Y-m-d'));
    if ($cid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) aal_err('缺少客戶或日期');
    aal_out(['no' => aca_suggest_no($db, $cid, $d)]);
}

case 'create': {
    $d = json_decode((string)($_POST['payload'] ?? ''), true);
    if (!is_array($d)) aal_err('資料格式錯誤');
    $r = aca_create($db, $d, $u);
    if (empty($r['success'])) aal_err($r['message']);
    // 附件隨建立一起送（新增當下就能上傳）；單一附件失敗不回滾折讓單，回報哪幾個沒傳成
    $fail = [];
    if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
        foreach ($_FILES['files']['name'] as $i => $nm) {
            if ($_FILES['files']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $one = ['name' => $nm, 'tmp_name' => $_FILES['files']['tmp_name'][$i], 'size' => $_FILES['files']['size'][$i], 'error' => $_FILES['files']['error'][$i]];
            $ar = aca_attach_add($db, (int)$r['allow_id'], $one, $u);
            if (empty($ar['success'])) $fail[] = $nm . '：' . $ar['message'];
        }
    }
    $r['attach_fail'] = $fail;
    aal_out($r);
}

case 'list': {
    $s = $_POST ?: $_GET;
    aal_out(aca_list($db, [
        'kw' => aal_u8(trim((string)($s['kw'] ?? ''))), 'bm' => $s['bm'] ?? '', 'status' => $s['status'] ?? '',
        'page' => $s['page'] ?? 1, 'per_page' => $s['per_page'] ?? 20,
    ]));
}

case 'get': {
    $a = aca_get($db, (int)($_GET['id'] ?? 0));
    if (!$a) aal_err('找不到折讓單', 404);
    aal_out(['allowance' => $a]);
}

case 'set_no': {
    $r = aca_set_no($db, (int)($_POST['id'] ?? 0), aal_u8((string)($_POST['allow_no'] ?? '')), $u);
    if (empty($r['success'])) aal_err($r['message']);
    aal_out($r);
}

case 'void': {
    $r = aca_void($db, (int)($_POST['id'] ?? 0), aal_u8((string)($_POST['reason'] ?? '')), $u);
    if (empty($r['success'])) aal_err($r['message']);
    aal_out($r);
}

case 'format_save': {
    $r = aca_format_save($db, aal_u8((string)($_POST['format'] ?? '')), (string)$u['user_cname']);
    if (empty($r['success'])) aal_err($r['message']);
    aal_out($r);
}

case 'attach_add': {
    $id = (int)($_POST['id'] ?? 0);
    $st = $db->prepare("SELECT status FROM acc_allowance WHERE allow_id=?"); $st->execute([$id]);
    $sv = $st->fetchColumn();
    if ($sv === false) aal_err('找不到折讓單', 404);
    if ($sv === 'void') aal_err('已作廢的折讓單不可再傳附件');
    if (empty($_FILES['file'])) aal_err('請選擇檔案');
    $r = aca_attach_add($db, $id, $_FILES['file'], $u);
    if (empty($r['success'])) aal_err($r['message']);
    aal_out($r);
}

case 'attach_del': {
    $fid = (int)($_POST['file_id'] ?? 0);
    aal_out(aca_attach_delete($db, $fid));
}

case 'attach_download': {
    $info = aca_attach_path($db, (int)($_GET['file_id'] ?? 0));
    if (!$info) { http_response_code(404); echo '找不到檔案'; exit; }
    require_once __DIR__ . '/../common/attach_lib.php';
    $mime = function_exists('mime_content_type') ? (@mime_content_type($info['path']) ?: 'application/octet-stream') : 'application/octet-stream';
    // 只有 PDF／點陣圖才在瀏覽器內顯示；其他（含 html、svg）一律下載，避免上傳檔被當頁面執行
    $inlineOk = in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
    if (!$inlineOk) $mime = 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode($info['name']));
    header('X-Content-Type-Options: nosniff');
    readfile($info['path']);
    exit;
}

default:
    aal_err('無效的操作', 404);
}
