<?php
/**
 * 會計模組 API — 第一階段：客戶發票資料維護（統編／發票全名／發票地址／發票 email）
 *
 * 權限：acc_lib.php acc_perms()（roles module='accounting'；admin ⊃ edit ⊃ view），fail-closed。
 * 寫：POST + CSRF + transaction（transaction 在 lib 內）。
 * 合計／匯出一律後端對「全部符合條件」的資料計算。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/acc_lib.php';

function acc_out(array $a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function acc_err(string $m, int $c = 400) { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }

function acc_csrf_token(): string {
    if (empty($_SESSION['acc_csrf'])) $_SESSION['acc_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['acc_csrf'];
}
function acc_csrf_ok(?string $t): bool {
    return !empty($_SESSION['acc_csrf']) && is_string($t) && hash_equals($_SESSION['acc_csrf'], $t);
}

try {
    $db = (new DBConnection())->getPDO();
} catch (Throwable $e) { acc_err('DB連線失敗：' . $e->getMessage(), 500); }

$u = acc_current_user($db);
if (!$u) acc_err('未登入', 401);
$uid   = (int)$u['id'];
$perms = acc_perms($db, $u);
if (!$perms['canView']) acc_err('無會計模組檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function acc_filter(): array {
    $s = $_POST ?: $_GET;
    return [
        'kw'       => trim((string)($s['kw'] ?? '')),
        'status'   => trim((string)($s['status'] ?? 'all')),
        'months'   => (int)($s['months'] ?? 12),
        'sort'     => trim((string)($s['sort'] ?? 'ship_amt')),
        'dir'      => (($s['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc',
        'page'     => max(1, (int)($s['page'] ?? 1)),
        'per_page' => (int)($s['per_page'] ?? 20),
    ];
}

switch ($action) {

case 'meta':
    acc_out([
        'perms'  => $perms,
        'csrf'   => acc_csrf_token(),
        'user'   => ['id' => $uid, 'name' => (string)$u['user_cname']],
        'fields' => acc_invoice_fields(),
    ]);

/* ── 客戶發票資料清單 ─────────────────────────────────────────────────── */
case 'customers':
    acc_out(acc_customer_invoice_list($db, acc_filter()));

/* ── 單筆就地編輯 ─────────────────────────────────────────────────────── */
case 'update_customer': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計資料編輯權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');

    $cid = trim((string)($_POST['customer_id'] ?? ''));
    if ($cid === '') acc_err('缺少客戶編號');
    $data = json_decode($_POST['data'] ?? '{}', true);
    if (!is_array($data)) acc_err('資料格式錯誤');

    $r = acc_update_customer($db, $cid, $data, (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* ── CSV 匯入：試算（不寫入）──────────────────────────────────────────── */
case 'import_preview': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計資料編輯權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');

    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        acc_err('請選擇要匯入的 CSV 檔');
    }
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    if ($raw === false || $raw === '') acc_err('檔案讀取失敗或內容為空');

    [$head, $data] = acc_parse_csv($raw);
    if (!$head) acc_err('CSV 沒有表頭列');

    // 欄位對應：前端送 map = {csv欄位index: 目標欄位名}
    $map = json_decode($_POST['map'] ?? '{}', true);
    if (!is_array($map) || !$map) acc_err('請先完成欄位對應');

    $rows = [];
    foreach ($data as $line) {
        $r = [];
        foreach ($map as $idx => $col) {
            if ($col === '' || $col === null) continue;
            $r[$col] = $line[(int)$idx] ?? '';
        }
        if (array_filter($r, fn($v) => trim((string)$v) !== '')) $rows[] = $r;
    }
    if (!$rows) acc_err('對應後沒有可用的資料列');

    $res = acc_import_preview($db, $rows);
    $res['head'] = $head;
    acc_out($res);
}

/* ── CSV 匯入：只讀表頭，供前端做欄位對應 ─────────────────────────────── */
case 'import_head': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計資料編輯權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        acc_err('請選擇要匯入的 CSV 檔');
    }
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    [$head, $data] = acc_parse_csv($raw);
    if (!$head) acc_err('CSV 沒有表頭列');

    // 依表頭文字猜測對應欄位，減少人工設定
    $guess = [];
    foreach ($head as $i => $h) {
        $h2 = str_replace([' ', '　', '(', ')', '（', '）'], '', (string)$h);
        if (preg_match('/客戶編號|客編|代號|編號|customer_?id/i', $h2))          $guess[$i] = 'customer_id';
        elseif (preg_match('/統一?編號|統編|tax_?id|ban/i', $h2))                $guess[$i] = 'tax_id';
        elseif (preg_match('/全名|全稱|發票抬頭|抬頭|公司名稱|full/i', $h2))     $guess[$i] = 'customer_full';
        elseif (preg_match('/簡稱|客戶名稱|客戶$|customer$/i', $h2))             $guess[$i] = 'customer';
        elseif (preg_match('/e-?mail|信箱|電子郵件/i', $h2))                     $guess[$i] = 'invoice_email';
        elseif (preg_match('/地址|address/i', $h2))                              $guess[$i] = 'customer_address';
        elseif (preg_match('/聯絡人|帳務聯絡|contact/i', $h2))                   $guess[$i] = 'billing_contact';
    }

    acc_out(['head' => $head, 'guess' => $guess, 'row_count' => count($data),
             'sample' => array_slice($data, 0, 3)]);
}

/* ── CSV 匯入：套用 ───────────────────────────────────────────────────── */
case 'import_apply': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計資料編輯權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');

    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || !$items) acc_err('沒有要套用的資料');

    $r = acc_import_apply($db, $items, (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* ── 匯出（全部符合條件，供整理後再匯入，也可當缺漏清單）──────────────── */
case 'export': {
    $f = acc_filter();
    $f['per_page'] = 0;
    $rows = acc_customer_invoice_list($db, $f)['rows'];

    $lbl = ['ok' => '完整', 'no_tax' => '缺統編', 'bad_tax' => '統編檢查碼錯', 'no_full' => '缺發票全名'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="客戶發票資料_' . date('Ymd_Hi') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['客戶編號', '客戶簡稱', '客戶全名(發票用)', '統一編號', '發票email',
                 '發票地址', '帳務聯絡人', '狀態', '近期出貨筆數', '近期出貨金額', '最後出貨日']);
    foreach ($rows as $r) {
        fputcsv($o, [
            $r['customer_id'], $r['customer'], $r['customer_full'],
            // 前面加 tab 讓 Excel 不要把統編當數字吃掉前導零
            ($r['tax_id'] !== null && $r['tax_id'] !== '') ? "\t" . $r['tax_id'] : '',
            $r['invoice_email'], $r['customer_address'], $r['billing_contact'],
            $lbl[$r['status']] ?? $r['status'],
            $r['ship_cnt'], round($r['ship_amt']), $r['last_date'],
        ]);
    }
    fclose($o);
    exit;
}

/* ── 匯入範本下載（告訴使用者 ERP 要匯出成什麼格式）─────────────────── */
case 'template': {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="客戶發票資料_匯入範本.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['客戶編號', '客戶簡稱', '客戶全名(發票用)', '統一編號', '發票email', '發票地址', '帳務聯絡人']);
    fputcsv($o, ['1RT001', '寶嘉誠', '寶嘉誠工業股份有限公司', "\t89215742",
                 'ap@example.com.tw', '台中市…', '陳小姐']);
    fputcsv($o, ['', '（客戶編號留空時，會用統編→簡稱→全名依序比對）', '', '', '', '', '']);
    fclose($o);
    exit;
}

default:
    acc_err('未知操作：' . $action, 404);
}
