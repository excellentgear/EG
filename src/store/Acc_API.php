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

/* 未攔截的例外一律轉成 JSON 錯誤，不要回空白 500——
   前端只會看到「載入失敗」而查不出原因。實測觸發點：非 UTF-8 的中文參數
   打到 utf8mb3 欄位會噴 SQLSTATE 3854（見記憶 db_charset_constraints）。 */
set_exception_handler(function (Throwable $e) {
    error_log('Acc_API 未攔截例外: ' . $e->getMessage());
    acc_err('伺服器錯誤：' . $e->getMessage(), 500);
});

/** 把輸入正規化成 UTF-8（Big5/CP950 來源會讓 SQL 直接炸掉） */
function acc_u8(string $s): string {
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
    $c = @mb_convert_encoding($s, 'UTF-8', 'BIG-5,CP950,UTF-8');
    return ($c === false) ? '' : $c;
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
        'kw'       => acc_u8(trim((string)($s['kw'] ?? ''))),
        'status'   => trim((string)($s['status'] ?? 'all')),
        'months'   => (int)($s['months'] ?? 12),
        'sort'     => trim((string)($s['sort'] ?? 'ship_amt')),
        'dir'      => (($s['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc',
        'page'     => max(1, (int)($s['page'] ?? 1)),
        'per_page' => (int)($s['per_page'] ?? 20),
    ];
}

/* 應收對帳篩選參數（必須定義在 switch 之外：PHP 不會 hoist 控制結構內的函式宣告，
   放進 switch 會因為直接跳到 case 而永遠沒被宣告到） */
function acc_ar_filter(): array {
    $s = $_POST ?: $_GET;
    return [
        'bm_from'     => trim((string)($s['bm_from'] ?? '')),
        'bm_to'       => trim((string)($s['bm_to'] ?? '')),
        'kw'          => acc_u8(trim((string)($s['kw'] ?? ''))),
        'customer_id' => trim((string)($s['customer_id'] ?? '')),
        'only_gap'    => !empty($s['only_gap']),
        'sort'        => trim((string)($s['sort'] ?? 'net_amt')),
        'dir'         => (($s['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc',
        'page'        => max(1, (int)($s['page'] ?? 1)),
        'per_page'    => (int)($s['per_page'] ?? 20),
    ];
}

/** 對帳單總覽的篩選參數（sheet_list 與 sheet_list_export 共用；
    與 acc_ar_filter 同理，必須定義在 switch 之外才會被宣告到）。 */
function acc_sheet_list_filter(): array {
    $s = $_POST ?: $_GET;
    return [
        'side'     => trim((string)($s['side'] ?? 'all')),
        'status'   => trim((string)($s['status'] ?? 'all')),
        'bm_from'  => trim((string)($s['bm_from'] ?? '')),
        'bm_to'    => trim((string)($s['bm_to'] ?? '')),
        'kw'       => acc_u8(trim((string)($s['kw'] ?? ''))),
        'only_diff'=> !empty($s['only_diff']) && $s['only_diff'] !== '0',
        'only_open'=> !empty($s['only_open']) && $s['only_open'] !== '0',
        'sort'     => trim((string)($s['sort'] ?? 'billing_month')),
        'dir'      => (($s['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc',
        'page'     => max(1, (int)($s['page'] ?? 1)),
        'per_page' => (int)($s['per_page'] ?? 20),
    ];
}

/** 依單據類型判斷該用哪一側的對帳權限（業務只能碰應收、生管只能碰應付）。
    與 acc_ar_filter 同理，必須定義在 switch 之外才會被宣告到。 */
function acc_recon_guard(array $perms, string $srcType): void {
    if (strtoupper(trim($srcType)) === 'TLOG') {
        if (empty($perms['canReconAp'])) acc_err('無應付對帳權限（需「應付對帳(生管)」或會計登錄角色）', 403);
    } else {
        if (empty($perms['canReconAr'])) acc_err('無應收對帳權限（需「應收對帳(業務)」或會計登錄角色）', 403);
    }
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

/* ══ 應收對帳 ═════════════════════════════════════════════════════════ */

case 'ar_summary':
    acc_out(acc_ar_summary($db, acc_ar_filter()));

case 'ar_detail': {
    $s  = $_POST ?: $_GET;
    $cn = acc_u8(trim((string)($s['customer'] ?? '')));
    $bm = trim((string)($s['billing_month'] ?? ''));
    if ($cn === '' || !preg_match('/^\d{4}-\d{2}$/', $bm)) acc_err('缺少客戶或帳款月份');
    acc_out(acc_ar_detail($db, $cn, $bm));
}

/* 應收彙總匯出（全部符合條件） */
case 'ar_export': {
    $f = acc_ar_filter();
    $f['per_page'] = 0;
    $r = acc_ar_summary($db, $f);

    $lbl = ['ok' => '可開發票', 'no_tax' => '缺統編', 'bad_tax' => '統編錯誤', 'no_full' => '缺發票全名'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="應收對帳彙總_' . $r['bm_from'] . '_' . $r['bm_to'] . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['帳款月份', '客戶編號', '客戶簡稱', '客戶全名(發票用)', '統一編號',
                 '出貨筆數', '出貨金額', '退貨筆數', '退貨金額',
                 '應收未稅', '稅額', '應收含稅', '發票資料狀態', '在客戶主檔']);
    foreach ($r['rows'] as $x) {
        fputcsv($o, [
            $x['billing_month'], $x['customer_id'], $x['customer'], $x['customer_full'],
            ($x['tax_id'] !== null && $x['tax_id'] !== '') ? "\t" . $x['tax_id'] : '',
            $x['ship_cnt'], round($x['ship_amt']), $x['ret_cnt'], round($x['ret_amt']),
            round($x['net_amt']), round($x['tax_amt']), round($x['total_amt']),
            $lbl[$x['inv_ready']] ?? $x['inv_ready'], $x['in_master'] ? '是' : '否（簡稱對不到）',
        ]);
    }
    $s = $r['summary'];
    fputcsv($o, []);
    fputcsv($o, ['合計', '', '', '', '', '', round($s['ship_amt']), '', round($s['ret_amt']),
                 round($s['net_amt']), round($s['tax_amt']), round($s['total_amt']), '', '']);
    fclose($o);
    exit;
}

/* 單一客戶對帳單明細匯出 */
case 'ar_detail_export': {
    $s  = $_GET ?: $_POST;
    $cn = acc_u8(trim((string)($s['customer'] ?? '')));
    $bm = trim((string)($s['billing_month'] ?? ''));
    if ($cn === '' || !preg_match('/^\d{4}-\d{2}$/', $bm)) acc_err('缺少客戶或帳款月份');
    $d = acc_ar_detail($db, $cn, $bm);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="對帳單_' . $cn . '_' . $bm . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['對帳單', $cn, $bm . ' 帳款月份']);
    fputcsv($o, ['統一編號', $d['head']['tax_id'] ? "\t" . $d['head']['tax_id'] : '（未建）',
                 '發票抬頭', $d['head']['customer_full'] ?: '（未建）']);
    fputcsv($o, []);
    fputcsv($o, ['類型', '單號', '日期', '訂單號', '料號', '品名規格', '數量', '單價', '金額', '備註']);
    foreach ($d['items'] as $i) {
        fputcsv($o, [$i['kind'] === 'ship' ? '出貨' : '退貨', $i['no'], $i['date'], $i['order_oo'],
                     $i['product_id'], $i['spec'], $i['qty'], $i['unit_price'],
                     round($i['amount']), $i['note']]);
    }
    fputcsv($o, []);
    fputcsv($o, ['', '', '', '', '', '', '', '應收未稅', round($d['net_amt'])]);
    fputcsv($o, ['', '', '', '', '', '', '', '稅額(' . round($d['tax_rate'] * 100) . '%)', round($d['tax_amt'])]);
    fputcsv($o, ['', '', '', '', '', '', '', '應收含稅', round($d['total_amt'])]);
    fclose($o);
    exit;
}

/* ══ 發票開立／轉出／回填／折讓 ═══════════════════════════════════════ */

/* 待開立清單（某帳款月份尚未開發票的憑證，依客戶分組） */
case 'inv_candidates': {
    $s  = $_POST ?: $_GET;
    $bm = trim((string)($s['bm'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $bm)) acc_err('請指定帳款月份');
    acc_out(acc_invoice_candidates($db, $bm));
}

/* 建立發票（draft） */
case 'inv_create': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');

    $sel = json_decode($_POST['sel'] ?? '[]', true);
    if (!is_array($sel) || !$sel) acc_err('沒有選取要開立的客戶');
    foreach ($sel as &$x) if (isset($x['customer'])) $x['customer'] = acc_u8((string)$x['customer']);
    unset($x);

    $r = acc_create_invoices($db, $sel, (string)$uid, !empty($_POST['split_by_src']));
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 發票清單 */
case 'inv_list': {
    $s = $_POST ?: $_GET;
    acc_out(acc_invoice_list($db, [
        'bm_from'  => trim((string)($s['bm_from'] ?? '')),
        'bm_to'    => trim((string)($s['bm_to'] ?? '')),
        'status'   => trim((string)($s['status'] ?? 'all')),
        'doc_type' => trim((string)($s['doc_type'] ?? 'all')),
        'kw'       => acc_u8(trim((string)($s['kw'] ?? ''))),
        'only_open' => !empty($s['only_open']),
        'page'     => max(1, (int)($s['page'] ?? 1)),
        'per_page' => (int)($s['per_page'] ?? 20),
    ]));
}

/* 單張發票明細 */
case 'inv_items': {
    $s = $_POST ?: $_GET;
    $id = (int)($s['invoice_id'] ?? 0);
    if ($id <= 0) acc_err('缺少發票');
    $st = $db->prepare("SELECT * FROM acc_invoice WHERE invoice_id=? LIMIT 1");
    $st->execute([$id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) acc_err('找不到發票');
    acc_out(['invoice' => $inv, 'items' => acc_invoice_items($db, $id)]);
}

/* 標記為已轉出 */
case 'inv_export_mark': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    if (!is_array($ids) || !$ids) acc_err('沒有選取發票');
    $r = acc_invoice_mark_exported($db, $ids, (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 回填發票號碼（手動逐張或 CSV 批次共用） */
case 'inv_backfill': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $pairs = json_decode($_POST['pairs'] ?? '[]', true);
    if (!is_array($pairs) || !$pairs) acc_err('沒有要回填的資料');
    $r = acc_invoice_backfill($db, $pairs, (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 回填用 CSV：讀表頭並比對到系統中的發票 */
case 'inv_backfill_csv': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        acc_err('請選擇平台匯出的「已開立清單」CSV');
    }
    [$head, $data] = acc_parse_csv(file_get_contents($_FILES['file']['tmp_name']));
    if (!$head) acc_err('CSV 沒有表頭列');

    // 自動找出「發票號碼／發票日期／買方統編／金額」欄位位置
    $ix = ['no' => -1, 'date' => -1, 'tax' => -1, 'amt' => -1];
    foreach ($head as $i => $h) {
        $h2 = str_replace([' ', '　', '(', ')', '（', '）'], '', (string)$h);
        if ($ix['no']   < 0 && preg_match('/發票號碼|發票字號|invoice_?no|number/i', $h2)) $ix['no']   = $i;
        elseif ($ix['date'] < 0 && preg_match('/發票日期|開立日期|invoice_?date|date/i', $h2)) $ix['date'] = $i;
        elseif ($ix['tax']  < 0 && preg_match('/買方統一?編號|買方統編|統一?編號|統編|tax_?id|ban/i', $h2)) $ix['tax'] = $i;
        elseif ($ix['amt']  < 0 && preg_match('/總計|含稅|總金額|total/i', $h2)) $ix['amt'] = $i;
    }
    if ($ix['no'] < 0) acc_err('CSV 中找不到「發票號碼」欄位，請確認匯出檔含此欄');

    // 待回填的發票（draft / exported）
    $pend = $db->query("SELECT invoice_id, customer_name, customer_full, tax_id, billing_month,
                               total_amount, status
                        FROM acc_invoice
                        WHERE doc_type='INVOICE' AND status IN ('draft','exported')
                        ORDER BY invoice_id")->fetchAll(PDO::FETCH_ASSOC);

    $byTax = [];
    foreach ($pend as $p) {
        $t = preg_replace('/\D/', '', (string)$p['tax_id']);
        if ($t !== '') $byTax[$t][] = $p;
    }

    $matched = []; $unmatched = [];
    foreach ($data as $n => $line) {
        $no = strtoupper(trim((string)($line[$ix['no']] ?? '')));
        if ($no === '') continue;
        $dt  = $ix['date'] >= 0 ? trim((string)($line[$ix['date']] ?? '')) : '';
        $tax = $ix['tax']  >= 0 ? preg_replace('/\D/', '', (string)($line[$ix['tax']] ?? '')) : '';
        $amt = $ix['amt']  >= 0 ? (float)preg_replace('/[^\d.\-]/', '', (string)($line[$ix['amt']] ?? '')) : 0.0;

        // 日期正規化：2026/5/5、20260505、2026-05-05 都收
        $d2 = '';
        if (preg_match('/^(\d{4})\D?(\d{1,2})\D?(\d{1,2})$/', $dt, $m)) {
            $d2 = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        $hit = null;
        if ($tax !== '' && !empty($byTax[$tax])) {
            // 同統編有多張時，優先取含稅金額最接近的
            $best = null; $bestDiff = null;
            foreach ($byTax[$tax] as $k => $p) {
                $diff = ($amt > 0) ? abs((float)$p['total_amount'] - $amt) : 0;
                if ($bestDiff === null || $diff < $bestDiff) { $bestDiff = $diff; $best = $k; }
            }
            if ($best !== null && ($amt <= 0 || $bestDiff < 1)) {
                $hit = $byTax[$tax][$best];
                unset($byTax[$tax][$best]);
                $byTax[$tax] = array_values($byTax[$tax]);
            }
        }
        if ($hit) {
            $matched[] = ['row' => $n + 2, 'invoice_id' => (int)$hit['invoice_id'],
                          'customer' => $hit['customer_name'], 'billing_month' => $hit['billing_month'],
                          'total_amount' => (float)$hit['total_amount'],
                          'invoice_no' => $no, 'invoice_date' => $d2, 'csv_amount' => $amt,
                          'no_ok' => (bool)preg_match('/^[A-Z]{2}\d{8}$/', $no),
                          'date_ok' => ($d2 !== '')];
        } else {
            $unmatched[] = ['row' => $n + 2, 'invoice_no' => $no, 'invoice_date' => $dt,
                            'tax_id' => $tax, 'amount' => $amt];
        }
    }

    acc_out(['head' => $head, 'col_index' => $ix, 'matched' => $matched, 'unmatched' => $unmatched,
             'pending' => count($pend),
             'summary' => ['csv_rows' => count($data), 'matched' => count($matched),
                           'unmatched' => count($unmatched)]]);
}

/* 作廢發票 */
case 'inv_void': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canAdmin']) acc_err('僅會計管理員可作廢發票', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $r = acc_invoice_void($db, (int)($_POST['invoice_id'] ?? 0),
                          acc_u8(trim((string)($_POST['reason'] ?? ''))), (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 折讓單：可選的原發票與可折讓退貨 */
case 'allow_options': {
    $s  = $_POST ?: $_GET;
    $cn = acc_u8(trim((string)($s['customer'] ?? '')));
    if ($cn === '') acc_err('缺少客戶');
    acc_out(['invoices' => acc_allowance_targets($db, $cn),
             'returns'  => acc_uninvoiced_returns($db, $cn)]);
}

/* 折讓單：建立 */
case 'allow_create': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $ids = json_decode($_POST['ir_ids'] ?? '[]', true);
    $r = acc_create_allowance($db, (int)($_POST['ref_invoice_id'] ?? 0),
                              is_array($ids) ? $ids : [], (string)$uid);
    if (!$r['success']) acc_err($r['message'] . (empty($r['errors']) ? '' : '（' . implode('；', $r['errors']) . '）'));
    acc_out($r);
}

/* 發票轉出 CSV：通用格式，供拿去電子發票平台開立 */
case 'inv_export_csv': {
    $s   = $_GET ?: $_POST;
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($s['ids'] ?? '')))));
    if (!$ids) acc_err('沒有選取發票');
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $st = $db->prepare("SELECT * FROM acc_invoice WHERE invoice_id IN ($ph) ORDER BY customer_name, invoice_id");
    $st->execute($ids);
    $invs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$invs) acc_err('找不到發票');

    $sti = $db->prepare("SELECT * FROM acc_invoice_item WHERE invoice_id IN ($ph) ORDER BY invoice_id, src_date, item_id");
    $sti->execute($ids);
    $itemsBy = [];
    foreach ($sti->fetchAll(PDO::FETCH_ASSOC) as $it) $itemsBy[(int)$it['invoice_id']][] = $it;

    $byItem = !empty($s['by_item']);   // 逐品項列出 or 單張彙總一列
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="發票開立清單_' . date('Ymd_Hi') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");

    if ($byItem) {
        fputcsv($o, ['系統發票序號', '買方統一編號', '買方名稱', '帳款月份', '稅別',
                     '品名', '規格', '數量', '單價', '金額', '來源單號', '來源日期']);
        foreach ($invs as $v) {
            foreach ($itemsBy[(int)$v['invoice_id']] ?? [] as $it) {
                fputcsv($o, [
                    $v['invoice_id'], "\t" . $v['tax_id'], $v['customer_full'] ?: $v['customer_name'],
                    $v['billing_month'], '應稅',
                    $it['product_id'], $it['spec'], $it['qty'],
                    rtrim(rtrim(number_format((float)$it['unit_price'], 4, '.', ''), '0'), '.'),
                    round((float)$it['amount']), $it['src_no'], $it['src_date'],
                ]);
            }
        }
    } else {
        fputcsv($o, ['系統發票序號', '買方統一編號', '買方名稱', '帳款月份', '稅別',
                     '品名', '數量', '單價', '銷售額(未稅)', '稅額', '總計(含稅)', '明細筆數', '備註']);
        foreach ($invs as $v) {
            $cnt = count($itemsBy[(int)$v['invoice_id']] ?? []);
            fputcsv($o, [
                $v['invoice_id'], "\t" . $v['tax_id'], $v['customer_full'] ?: $v['customer_name'],
                $v['billing_month'], '應稅',
                $v['billing_month'] . ' 加工費', 1, round((float)$v['sales_amount']),
                round((float)$v['sales_amount']), round((float)$v['tax_amount']),
                round((float)$v['total_amount']), $cnt, $v['note'],
            ]);
        }
    }
    fclose($o);
    exit;
}

/* ══ 收款與沖帳 ═══════════════════════════════════════════════════════ */

case 'rcpt_list': {
    $s = $_POST ?: $_GET;
    acc_out(acc_receipt_list($db, [
        'date_from'     => trim((string)($s['date_from'] ?? '')),
        'date_to'       => trim((string)($s['date_to'] ?? '')),
        'kw'            => acc_u8(trim((string)($s['kw'] ?? ''))),
        'only_unalloc'  => !empty($s['only_unalloc']),
        'page'          => max(1, (int)($s['page'] ?? 1)),
        'per_page'      => (int)($s['per_page'] ?? 20),
    ]));
}

case 'rcpt_save': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $d = json_decode($_POST['data'] ?? '{}', true);
    if (!is_array($d)) acc_err('資料格式錯誤');
    foreach (['customer_name', 'bank', 'note', 'method'] as $k)
        if (isset($d[$k])) $d[$k] = acc_u8((string)$d[$k]);
    $r = acc_receipt_save($db, $d, (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

case 'rcpt_delete': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canAdmin']) acc_err('僅會計管理員可刪除收款單', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $r = acc_receipt_delete($db, (int)($_POST['receipt_id'] ?? 0), (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 某收款單的沖帳明細 + 該客戶目前可沖的發票 */
case 'rcpt_alloc_options': {
    $s   = $_POST ?: $_GET;
    $rid = (int)($s['receipt_id'] ?? 0);
    if ($rid <= 0) acc_err('缺少收款單');
    $st = $db->prepare("SELECT * FROM acc_receipt WHERE receipt_id=? LIMIT 1");
    $st->execute([$rid]);
    $rc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rc) acc_err('找不到收款單');
    acc_out(['receipt'  => $rc,
             'allocs'   => acc_receipt_allocs($db, $rid),
             'invoices' => acc_open_invoices($db, (string)$rc['customer_name'], $rid)]);
}

case 'rcpt_alloc_save': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $allocs = json_decode($_POST['allocs'] ?? '[]', true);
    $r = acc_alloc_save($db, (int)($_POST['receipt_id'] ?? 0), is_array($allocs) ? $allocs : [], (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 客戶下拉（有已開立發票或有收款紀錄的） */
case 'rcpt_customers': {
    $rows = $db->query("SELECT customer_name, MAX(customer_id) AS customer_id, COUNT(*) AS cnt
                        FROM (
                          SELECT customer_name, customer_id FROM acc_invoice WHERE status='issued'
                          UNION ALL
                          SELECT customer_name, customer_id FROM acc_receipt
                        ) t
                        GROUP BY customer_name ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
    acc_out(['customers' => $rows]);
}

/* 收款單匯出 */
case 'rcpt_export': {
    $s = $_GET ?: $_POST;
    $r = acc_receipt_list($db, [
        'date_from' => trim((string)($s['date_from'] ?? '')),
        'date_to'   => trim((string)($s['date_to'] ?? '')),
        'kw'        => acc_u8(trim((string)($s['kw'] ?? ''))),
        'only_unalloc' => !empty($s['only_unalloc']),
        'per_page'  => 0,
    ]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="收款明細_' . date('Ymd_Hi') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['收款單號', '入帳日', '客戶', '方式', '收款金額', '手續費', '已沖帳',
                 '未分配', '沖帳張數', '銀行', '支票號碼', '票期', '備註']);
    foreach ($r['rows'] as $x) {
        fputcsv($o, [$x['receipt_no'], $x['receipt_date'], $x['customer_name'], $x['method'],
                     round($x['amount']), round($x['fee']), round($x['allocated']),
                     round($x['unallocated']), $x['alloc_cnt'], $x['bank'],
                     $x['check_no'], $x['check_due'], $x['note']]);
    }
    $s2 = $r['summary'];
    fputcsv($o, []);
    fputcsv($o, ['合計', '', '', '', round($s2['amount']), round($s2['fee']),
                 round($s2['allocated']), round($s2['unallocated']), '', '', '', '', '']);
    fclose($o);
    exit;
}

/* ══ 應付對帳（廠商加工費）═══════════════════════════════════════════ */

case 'ap_summary': {
    $s = $_POST ?: $_GET;
    acc_out(acc_ap_summary($db, [
        'ym_from'  => trim((string)($s['ym_from'] ?? '')),
        'ym_to'    => trim((string)($s['ym_to'] ?? '')),
        'kw'       => acc_u8(trim((string)($s['kw'] ?? ''))),
        'only_gap' => !empty($s['only_gap']),
        'sort'     => trim((string)($s['sort'] ?? 'total_amount')),
        'dir'      => (($s['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc',
        'page'     => max(1, (int)($s['page'] ?? 1)),
        'per_page' => (int)($s['per_page'] ?? 20),
    ]));
}

case 'ap_detail': {
    $s  = $_POST ?: $_GET;
    $mk = acc_u8(trim((string)($s['maker_id_no'] ?? '')));
    $ym = trim((string)($s['invoice_ym'] ?? ''));
    if ($mk === '' || !preg_match('/^\d{4}-\d{2}$/', $ym)) acc_err('缺少廠商或發票年月');
    acc_out(acc_ap_detail($db, $mk, $ym));
}

case 'ap_export': {
    $s = $_GET ?: $_POST;
    $r = acc_ap_summary($db, [
        'ym_from'  => trim((string)($s['ym_from'] ?? '')),
        'ym_to'    => trim((string)($s['ym_to'] ?? '')),
        'kw'       => acc_u8(trim((string)($s['kw'] ?? ''))),
        'only_gap' => !empty($s['only_gap']),
        'per_page' => 0,
    ]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="應付對帳彙總_' . $r['ym_from'] . '_' . $r['ym_to'] . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['發票年月', '廠商編號', '廠商簡稱', '廠商全稱', '統一編號', '付款方式', '月結天數',
                 '加工筆數', '加工數量', '加工費(未稅)', '稅額', '應付含稅', '缺發票日期筆數', '加工日期範圍']);
    foreach ($r['rows'] as $x) {
        fputcsv($o, [$x['invoice_ym'], $x['maker_id_no'], $x['maker_name'], $x['maker_full'],
                     ($x['tax_id'] ? "\t" . $x['tax_id'] : ''), $x['payment_method'], $x['net_days'],
                     $x['cnt'], $x['qty'], round($x['amount']), round($x['tax_amount']),
                     round($x['total_amount']), $x['no_inv_date'], $x['date_range']]);
    }
    $s2 = $r['summary'];
    fputcsv($o, []);
    fputcsv($o, ['合計', '', '', '', '', '', '', $s2['cnt'], '', round($s2['amount']),
                 round($s2['tax_amount']), round($s2['total_amount']), $s2['no_inv_date'], '']);
    fclose($o);
    exit;
}

case 'ap_detail_export': {
    $s  = $_GET ?: $_POST;
    $mk = acc_u8(trim((string)($s['maker_id_no'] ?? '')));
    $ym = trim((string)($s['invoice_ym'] ?? ''));
    if ($mk === '' || !preg_match('/^\d{4}-\d{2}$/', $ym)) acc_err('缺少廠商或發票年月');
    $d = acc_ap_detail($db, $mk, $ym);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="應付對帳單_' . $d['head']['maker_name'] . '_' . $ym . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['應付對帳單', $d['head']['maker_full'] ?: $d['head']['maker_name'], $ym . ' 發票年月']);
    fputcsv($o, ['統一編號', $d['head']['tax_id'] ? "\t" . $d['head']['tax_id'] : '（未建）',
                 '付款方式', $d['head']['payment_method'], '月結天數', $d['head']['net_days']]);
    fputcsv($o, []);
    fputcsv($o, ['加工日', '移轉單號', '製令', '料號', '製程', '加工數量', '損耗',
                 '單價', '加工費', '稅額', '發票日期', '訂單號', '備註']);
    foreach ($d['items'] as $i) {
        fputcsv($o, [$i['d'], $i['transfer_no'], $i['bom'], $i['product_id'], $i['process_name'],
                     $i['transfer_qty'], $i['loss_qty'],
                     rtrim(rtrim(number_format((float)$i['unit_price'], 4, '.', ''), '0'), '.'),
                     round($i['process_amount']), round($i['tax_amount']),
                     $i['inv_date'], $i['order_no'], $i['note']]);
    }
    fputcsv($o, []);
    fputcsv($o, ['', '', '', '', '', '', '', '加工費(未稅)', round($d['amount'])]);
    fputcsv($o, ['', '', '', '', '', '', '', '稅額', round($d['tax_amount'])]);
    fputcsv($o, ['', '', '', '', '', '', '', '應付含稅', round($d['total_amount'])]);
    fclose($o);
    exit;
}

/* ══ 單據快搜（對紙本用）═════════════════════════════════════════════ */
case 'doc_lookup': {
    $s  = $_POST ?: $_GET;
    $kw = acc_u8(trim((string)($s['kw'] ?? '')));
    if ($kw === '') acc_err('請輸入要查的單號、金額、料號或客戶／廠商');
    acc_out(acc_doc_lookup($db, $kw, ['limit' => min(200, max(20, (int)($s['limit'] ?? 60)))]));
}

/* ══ 帳款月份調整 ═════════════════════════════════════════════════════ */
case 'billing_search': {
    $s = $_POST ?: $_GET;
    acc_out(acc_billing_search($db, [
        'side'          => trim((string)($s['side'] ?? 'ar')),
        'kw'            => acc_u8(trim((string)($s['kw'] ?? ''))),
        'date_from'     => trim((string)($s['date_from'] ?? '')),
        'date_to'       => trim((string)($s['date_to'] ?? '')),
        'billing_month' => trim((string)($s['billing_month'] ?? '')),
        'only_override' => !empty($s['only_override']),
    ]));
}

case 'billing_set': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $r = acc_set_billing_month($db, (string)($_POST['src_type'] ?? ''),
                               (int)($_POST['id'] ?? 0),
                               trim((string)($_POST['ym'] ?? '')), (string)$uid);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

case 'billing_set_bulk': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canEdit']) acc_err('無會計登錄權限', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || !$items) acc_err('沒有選取單據');
    $r = acc_set_billing_month_bulk($db, $items, trim((string)($_POST['ym'] ?? '')), (string)$uid);
    acc_out($r);
}

/* 單據快搜結果匯出（對帳時可印出來對照紙本）*/
case 'doc_lookup_export': {
    $s  = $_GET ?: $_POST;
    $kw = acc_u8(trim((string)($s['kw'] ?? '')));
    if ($kw === '') acc_err('缺少關鍵字');
    $r = acc_doc_lookup($db, $kw, ['limit' => 200]);

    $label = ['ship' => '出貨單', 'return' => '退貨單', 'invoice' => '發票',
              'allowance' => '折讓單', 'receipt' => '收款單', 'process' => '加工移轉單'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="單據查詢_' . preg_replace('/[^\w\-]/u', '', $kw) . '_' . date('Ymd_Hi') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['查詢關鍵字', $kw, '共 ' . $r['summary']['total'] . ' 筆']);
    fputcsv($o, []);
    fputcsv($o, ['單據類型', '單號', '日期', '對象', '對象別', '料號', '數量', '金額',
                 '帳款月份', '是否人工指定', '已開發票', '備註']);
    foreach ($r['groups'] as $g) {
        foreach ($g as $x) {
            fputcsv($o, [$label[$x['kind']] ?? $x['kind'], $x['no'], $x['date'] ?? '',
                         $x['party'] ?? '', $x['party_kind'] ?? '', $x['product_id'] ?? '',
                         $x['qty'] ?? '', round((float)($x['amount'] ?? 0)),
                         $x['billing_month'] ?? '', !empty($x['overridden']) ? '是' : '',
                         $x['invoiced'] ?? '', $x['note'] ?? '']);
        }
    }
    fclose($o);
    exit;
}

/* ══ 對帳：線上修改單據 / 對帳狀態 / 稽核紀錄 ═════════════════════════ */

/* 線上修改單據欄位（數量／單價／金額／備註），必填修改原因，寫入稽核 */
case 'doc_edit': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $srcType = (string)($_POST['src_type'] ?? '');
    acc_recon_guard($perms, $srcType);

    $fields = json_decode($_POST['fields'] ?? '{}', true);
    if (!is_array($fields) || !$fields) acc_err('沒有要修改的欄位');
    foreach ($fields as $k => $v) if (is_string($v)) $fields[$k] = acc_u8($v);

    $r = acc_edit_doc($db, $srcType, (int)($_POST['id'] ?? 0), $fields,
                      acc_u8((string)($_POST['reason'] ?? '')), $u);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 對帳狀態（未對／已對完／有異常）＋對帳註記 */
case 'recon_set': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $srcType = (string)($_POST['src_type'] ?? '');
    acc_recon_guard($perms, $srcType);

    $r = acc_recon_set($db, $srcType, (int)($_POST['id'] ?? 0),
                       trim((string)($_POST['status'] ?? '')),
                       acc_u8((string)($_POST['note'] ?? '')), $u);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 批次標對帳狀態 */
case 'recon_set_bulk': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $items  = json_decode($_POST['items'] ?? '[]', true);
    $status = trim((string)($_POST['status'] ?? ''));
    $note   = acc_u8((string)($_POST['note'] ?? ''));
    if (!is_array($items) || !$items) acc_err('沒有選取單據');

    $ok = 0; $fail = 0; $errors = [];
    foreach ($items as $it) {
        $st = (string)($it['src_type'] ?? '');
        // 逐筆檢查權限，避免夾帶另一側的單據繞過
        $isAp = (strtoupper(trim($st)) === 'TLOG');
        if (($isAp && empty($perms['canReconAp'])) || (!$isAp && empty($perms['canReconAr']))) {
            $fail++; if (count($errors) < 10) $errors[] = ($it['no'] ?? '?') . '：無此側對帳權限';
            continue;
        }
        $r = acc_recon_set($db, $st, (int)($it['id'] ?? 0), $status, $note, $u);
        if ($r['success']) $ok++;
        else { $fail++; if (count($errors) < 10) $errors[] = ($it['no'] ?? '?') . '：' . $r['message']; }
    }
    acc_out(['applied' => $ok, 'failed' => $fail, 'errors' => $errors,
             'message' => "已標記 {$ok} 筆" . ($fail ? "，{$fail} 筆失敗" : '')]);
}

/* 對帳明細（含目前對帳狀態與可改欄位定義），供明細跳窗就地編輯 */
case 'recon_detail': {
    $s  = $_POST ?: $_GET;
    $side = (($s['side'] ?? 'ar') === 'ap') ? 'ap' : 'ar';

    if ($side === 'ar') {
        $cn = acc_u8(trim((string)($s['customer'] ?? '')));
        $bm = trim((string)($s['billing_month'] ?? ''));
        if ($cn === '' || !preg_match('/^\d{4}-\d{2}$/', $bm)) acc_err('缺少客戶或帳款月份');
        $d = acc_ar_detail($db, $cn, $bm);
        // 明細本身沒帶 src_id，用單據快搜的邏輯補不上，改為直接查一次對帳狀態
        $isIds = []; $irIds = [];
        foreach ($d['items'] as $it) {
            if (($it['src_type'] ?? '') === 'IS') $isIds[] = (int)($it['src_id'] ?? 0);
            else                                  $irIds[] = (int)($it['src_id'] ?? 0);
        }
        $d['recon'] = array_merge(acc_recon_map($db, 'IS', $isIds), acc_recon_map($db, 'IR', $irIds));
        $d['editable'] = ['IS' => acc_editable_fields('IS'), 'IR' => acc_editable_fields('IR')];
        $d['can_edit'] = (bool)$perms['canReconAr'];
        acc_out($d);
    } else {
        $mk = acc_u8(trim((string)($s['maker_id_no'] ?? '')));
        $ym = trim((string)($s['invoice_ym'] ?? ''));
        if ($mk === '' || !preg_match('/^\d{4}-\d{2}$/', $ym)) acc_err('缺少廠商或發票年月');
        $d = acc_ap_detail($db, $mk, $ym);
        $d['recon']    = acc_recon_map($db, 'TLOG', array_column($d['items'], 'transfer_id'));
        $d['editable'] = ['TLOG' => acc_editable_fields('TLOG')];
        $d['can_edit'] = (bool)$perms['canReconAp'];
        acc_out($d);
    }
}

/* 稽核紀錄查詢 */
case 'audit_search': {
    $s = $_POST ?: $_GET;
    acc_out(acc_audit_search($db, [
        'action'    => trim((string)($s['action'] ?? 'all')),
        'kw'        => acc_u8(trim((string)($s['kw'] ?? ''))),
        'date_from' => trim((string)($s['date_from'] ?? '')),
        'date_to'   => trim((string)($s['date_to'] ?? '')),
        'page'      => max(1, (int)($s['page'] ?? 1)),
        'per_page'  => (int)($s['per_page'] ?? 20),
    ]));
}

/* 稽核紀錄匯出（帳款有爭議時要能整份交出去） */
case 'audit_export': {
    $s = $_GET ?: $_POST;
    $r = acc_audit_search($db, [
        'action'    => trim((string)($s['action'] ?? 'all')),
        'kw'        => acc_u8(trim((string)($s['kw'] ?? ''))),
        'date_from' => trim((string)($s['date_from'] ?? '')),
        'date_to'   => trim((string)($s['date_to'] ?? '')),
        'per_page'  => 0,
    ]);
    $al = ['ACC_EDIT' => '修改單據', 'ACC_MONTH' => '改帳款月份', 'ACC_RECON' => '對帳狀態'];
    $tl = ['is_list' => '出貨單', 'ir_track' => '退貨單', 'bom_ing_transfer_log' => '加工移轉單'];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="會計稽核紀錄_' . date('Ymd_Hi') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['紀錄編號', '時間', '操作人', '動作', '單據類型', '單據ID', '單號',
                 '變更欄位', '原值', '新值', '修改原因']);
    foreach ($r['rows'] as $x) {
        $ch = $x['parsed'] ?: [];
        if (!$ch) {
            fputcsv($o, [$x['id'], $x['created_at'], $x['operator'], $al[$x['action_type']] ?? $x['action_type'],
                         $tl[$x['target_type']] ?? $x['target_type'], $x['target_id'], $x['target_name'],
                         '', '', '', $x['reason']]);
            continue;
        }
        foreach ($ch as $col => $v) {
            fputcsv($o, [$x['id'], $x['created_at'], $x['operator'], $al[$x['action_type']] ?? $x['action_type'],
                         $tl[$x['target_type']] ?? $x['target_type'], $x['target_id'], $x['target_name'],
                         $col,
                         is_array($v) ? (string)($v['old'] ?? '') : '',
                         is_array($v) ? (string)($v['new'] ?? '') : (string)$v,
                         $x['reason']]);
        }
    }
    fclose($o);
    exit;
}

/* ══ 對帳工作底稿 ═════════════════════════════════════════════════════ */

/* 某帳款月份有帳的對象清單（供下拉選擇，取代萬用關鍵字搜尋） */
case 'recon_parties': {
    $s    = $_POST ?: $_GET;
    $side = (($s['side'] ?? 'ar') === 'ap') ? 'ap' : 'ar';
    $bm   = trim((string)($s['bm'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $bm)) acc_err('請指定帳款月份');

    $out = [];
    if ($side === 'ap') {
        foreach (acc_ap_summary($db, ['ym_from' => $bm, 'ym_to' => $bm, 'per_page' => 0])['rows'] as $r) {
            $out[] = ['party_id' => $r['maker_id_no'], 'party_name' => $r['maker_name'],
                      'party_full' => $r['maker_full'], 'tax_id' => $r['tax_id'],
                      'cnt' => $r['cnt'], 'amount' => $r['amount'], 'total' => $r['total_amount']];
        }
    } else {
        foreach (acc_ar_summary($db, ['bm_from' => $bm, 'bm_to' => $bm, 'per_page' => 0])['rows'] as $r) {
            // 應收以客戶簡稱歸戶（is_list.Client_id 近期多為空）
            $out[] = ['party_id' => $r['customer'], 'party_name' => $r['customer'],
                      'party_full' => $r['customer_full'], 'tax_id' => $r['tax_id'],
                      'cnt' => $r['ship_cnt'] + $r['ret_cnt'], 'amount' => $r['net_amt'],
                      'total' => $r['total_amt'], 'customer_id' => $r['customer_id']];
        }
    }
    // 已有底稿的標出來，讓使用者知道哪些對過了
    $sh = acc_sheet_list($db, ['side' => $side, 'bm_from' => $bm, 'bm_to' => $bm]);
    $map = [];
    foreach ($sh['rows'] as $x) $map[$x['party_id']] = ['status' => $x['status'],
                                                        'checked_cnt' => $x['checked_cnt'],
                                                        'line_cnt' => $x['line_cnt'],
                                                        'sheet_id' => (int)$x['sheet_id']];
    foreach ($out as &$o) $o['sheet'] = $map[$o['party_id']] ?? null;
    unset($o);

    acc_out(['side' => $side, 'billing_month' => $bm, 'parties' => $out,
             'summary' => ['count' => count($out), 'with_sheet' => count($map)]]);
}

/* 載入底稿：有暫存就回暫存，否則從來源憑證即時組出（尚未寫入） */
case 'sheet_load': {
    $s    = $_POST ?: $_GET;
    $side = (($s['side'] ?? 'ar') === 'ap') ? 'ap' : 'ar';
    $pid  = acc_u8(trim((string)($s['party_id'] ?? '')));
    $bm   = trim((string)($s['billing_month'] ?? ''));
    if ($pid === '' || !preg_match('/^\d{4}-\d{2}$/', $bm)) acc_err('缺少對象或帳款月份');

    $canEdit = ($side === 'ap') ? (bool)$perms['canReconAp'] : (bool)$perms['canReconAr'];
    $sheet = acc_sheet_get($db, $side, $pid, $bm);

    if ($sheet) {
        acc_out(['from' => 'draft', 'sheet' => $sheet, 'can_edit' => $canEdit,
                 'can_reopen' => (bool)$perms['canAdmin'], 'tax_rate' => acc_tax_rate($db)]);
    }
    $built = acc_sheet_build($db, $side, $pid, $bm);
    $t = acc_sheet_totals($db, $built['lines']);
    acc_out(['from' => 'source',
             'sheet' => ['sheet_id' => null, 'side' => $side, 'party_id' => $pid,
                         'party_name' => $built['party_name'], 'billing_month' => $bm,
                         'status' => 'new', 'their_total' => null, 'memo' => null,
                         'our_total' => $t['our_total'], 'tax_amount' => $t['tax_amount'],
                         'total_amount' => $t['total_amount'], 'lines' => $built['lines']],
             'head' => $built['head'], 'can_edit' => $canEdit,
             'can_reopen' => (bool)$perms['canAdmin'], 'tax_rate' => acc_tax_rate($db)]);
}

/* 暫存底稿 */
case 'sheet_save': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $sheet = json_decode($_POST['sheet'] ?? '{}', true);
    $lines = json_decode($_POST['lines'] ?? '[]', true);
    if (!is_array($sheet) || !is_array($lines)) acc_err('資料格式錯誤');
    if (!$lines) acc_err('沒有明細可暫存');
    acc_recon_guard($perms, (($sheet['side'] ?? 'ar') === 'ap') ? 'TLOG' : 'IS');

    foreach (['party_name', 'memo'] as $k) if (isset($sheet[$k])) $sheet[$k] = acc_u8((string)$sheet[$k]);
    foreach ($lines as &$l) if (isset($l['memo'])) $l['memo'] = acc_u8((string)$l['memo']);
    unset($l);

    $r = acc_sheet_save($db, $sheet, $lines, $u);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 確認正確 → 鎖帳 */
case 'sheet_confirm': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $sid = (int)($_POST['sheet_id'] ?? 0);
    if ($sid <= 0) acc_err('請先暫存後再確認');
    $st = $db->prepare("SELECT side FROM acc_recon_sheet WHERE sheet_id=? LIMIT 1");
    $st->execute([$sid]);
    $side = (string)$st->fetchColumn();
    acc_recon_guard($perms, ($side === 'ap') ? 'TLOG' : 'IS');

    $r = acc_sheet_confirm($db, $sid, $u);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 退回重對（僅會計管理員） */
case 'sheet_reopen': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_err('必須用 POST', 405);
    if (!$perms['canAdmin']) acc_err('僅會計管理員可退回已鎖帳的對帳單', 403);
    if (!acc_csrf_ok($_POST['csrf'] ?? '')) acc_err('CSRF 驗證失敗，請重新整理頁面');
    $r = acc_sheet_reopen($db, (int)($_POST['sheet_id'] ?? 0),
                          acc_u8((string)($_POST['reason'] ?? '')), $u);
    if (!$r['success']) acc_err($r['message']);
    acc_out($r);
}

/* 底稿清單（會計／稽核看誰對完、誰還卡著、哪些有差額） */
case 'sheet_list': {
    acc_out(acc_sheet_list($db, acc_sheet_list_filter()));
}

/* 對帳單總覽匯出（總覽層級：一列一份底稿，含差額與經手人） */
case 'sheet_list_export': {
    $f = acc_sheet_list_filter();
    $f['per_page'] = 0;                       // 匯出一律匯全部符合條件的資料，不受分頁影響
    $r = acc_sheet_list($db, $f);

    $sideLbl = ['ar' => '應收', 'ap' => '應付'];
    $stLbl   = ['draft' => '暫存中', 'confirmed' => '已確認鎖帳', 'reopened' => '已退回重對'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="對帳單總覽_' . date('Ymd_His') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['對帳單總覽', '匯出時間', date('Y-m-d H:i:s'),
                 '月份區間', ($f['bm_from'] ?: '不限') . ' ~ ' . ($f['bm_to'] ?: '不限'),
                 '側別', $sideLbl[$f['side']] ?? '全部',
                 '狀態', $stLbl[$f['status']] ?? '全部',
                 '關鍵字', $f['kw'],
                 '附加篩選', trim(($f['only_diff'] ? '只看有差額 ' : '') . ($f['only_open'] ? '只看還沒對完' : '')) ?: '無']);
    $sm = $r['summary'];
    fputcsv($o, ['總份數', $sm['count'], '暫存中', $sm['draft'], '已確認', $sm['confirmed'],
                 '已退回', $sm['reopened'], '有差額', $sm['diff_cnt']]);
    fputcsv($o, []);
    fputcsv($o, ['側別', '對象', '對象編號', '帳款月份', '狀態', '總列數', '已勾選', '調整列數',
                 '我方未稅', '對方紙本', '差額', '稅額', '含稅合計',
                 '暫存人', '暫存時間', '確認人', '確認時間', '退回人', '退回時間', '退回原因', '備註']);
    foreach ($r['rows'] as $x) {
        fputcsv($o, [
            $sideLbl[$x['side']] ?? $x['side'], $x['party_name'], $x['party_id'], $x['billing_month'],
            $stLbl[$x['status']] ?? $x['status'],
            $x['line_cnt'], $x['checked_cnt'], $x['adj_cnt'],
            round((float)$x['our_total']),
            $x['their_total'] === null ? '' : round((float)$x['their_total']),
            $x['diff'] === null ? '' : round((float)$x['diff']),
            round((float)$x['tax_amount']), round((float)$x['total_amount']),
            $x['saved_by_name'], $x['saved_at'],
            $x['confirmed_by_name'], $x['confirmed_at'],
            $x['reopen_by_name'], $x['reopen_at'], $x['reopen_reason'], $x['memo'],
        ]);
    }
    fclose($o);
    exit;
}

/* 底稿匯出（含原始值與調整後對照，供會計／稽核核對） */
case 'sheet_export': {
    $s    = $_GET ?: $_POST;
    $side = (($s['side'] ?? 'ar') === 'ap') ? 'ap' : 'ar';
    $pid  = acc_u8(trim((string)($s['party_id'] ?? '')));
    $bm   = trim((string)($s['billing_month'] ?? ''));
    if ($pid === '' || !preg_match('/^\d{4}-\d{2}$/', $bm)) acc_err('缺少對象或帳款月份');
    $sheet = acc_sheet_get($db, $side, $pid, $bm);
    if (!$sheet) acc_err('此對象與月份尚無對帳底稿');

    $stLbl = ['draft' => '暫存中', 'confirmed' => '已確認鎖帳', 'reopened' => '已退回重對'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="對帳底稿_' . $sheet['party_name'] . '_' . $bm . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['對帳底稿', $sheet['party_name'], $bm, $side === 'ap' ? '應付' : '應收',
                 $stLbl[$sheet['status']] ?? $sheet['status']]);
    fputcsv($o, ['我方合計(未稅)', round((float)$sheet['our_total']),
                 '對方紙本合計', $sheet['their_total'] === null ? '' : round((float)$sheet['their_total']),
                 '差額', $sheet['their_total'] === null ? '' : round((float)$sheet['their_total'] - (float)$sheet['our_total'])]);
    fputcsv($o, ['稅額', round((float)$sheet['tax_amount']), '含稅合計', round((float)$sheet['total_amount'])]);
    if ($sheet['status'] === 'confirmed')
        fputcsv($o, ['確認人', $sheet['confirmed_by_name'], '確認時間', $sheet['confirmed_at']]);
    if (!empty($sheet['reopen_at']))
        fputcsv($o, ['退回人', $sheet['reopen_by_name'], '退回時間', $sheet['reopen_at'], '退回原因', $sheet['reopen_reason']]);
    fputcsv($o, ['備註', $sheet['memo']]);
    fputcsv($o, []);
    fputcsv($o, ['順序', '已對到', '單號', '日期', '製令', '料號', '說明',
                 '原始數量', '原始單價', '原始金額',
                 '調整數量', '調整單價', '調整金額', '指定月份',
                 '加總組', '拆分自', '拆分序', '計入合計', '備註']);
    foreach ($sheet['lines'] as $l) {
        fputcsv($o, [
            $l['sort_order'], $l['checked'] ? '✓' : '', $l['doc_no'], $l['doc_date'],
            $l['bom'], $l['product_id'], $l['spec'],
            $l['orig_qty'], $l['orig_price'], $l['orig_amount'],
            $l['adj_qty'], $l['adj_price'], $l['adj_amount'], $l['adj_month'],
            $l['group_no'], $l['split_parent'], $l['split_seq'],
            $l['counts_in_total'] ? '是' : '否(拆分父列)', $l['memo'],
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
