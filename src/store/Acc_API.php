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
