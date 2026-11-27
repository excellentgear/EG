<?php
/**
 * 收款延伸 — 唯一實作：請款日期／應入帳日期、入帳類型必填資料、扣%／匯費。
 *
 * 不動 acc_receipt 既有欄位語意，只新增欄位（舊收款單 settle_extra=0，沖帳上限仍＝收款金額）：
 *   remit_account 匯入帳號｜deduct_pct 扣%｜deduct_amt 扣款金額｜due_amount 應收金額｜settle_extra
 *   settle_extra=1（本延伸建立）：沖帳上限＝實收金額＋扣款金額＋匯費，
 *   因為客戶扣 3% 或匯款扣匯費之後，發票仍視為收齊，不應留下零頭未收。
 *
 * 應入帳日期（依客戶主檔的結帳／付款設定推算）：
 *   依天數（net_unit=days 或未設）：發票日期（沒有則請款日期）＋ net_days 天
 *   月結／次月結：帳款月份「月底」＋ net_days 天（次月結的 net_days 在主檔已含 +30）
 *   客戶沒填天數時無法推算（顯示「未設定」，不亂猜）
 * 請款日期＝該發票明細所屬傳票的傳票日期（還沒開傳票則空白）。
 */
require_once __DIR__ . '/acc_lib.php';
require_once __DIR__ . '/acc_voucher_lib.php';

const ACC_RCPT_METHODS = ['匯款', '支票', '現金', '信用狀', '其他'];

function acc_rcpt_ext_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    acc_ensure_schema($db);
    $have = [];
    foreach ($db->query("SHOW COLUMNS FROM acc_receipt")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['Field']] = 1;
    $add = [
        'remit_account' => "VARCHAR(40) NULL COMMENT '匯入帳號（匯款時）'",
        'deduct_pct'    => "DECIMAL(6,3) NULL COMMENT '客戶扣%（現金／票據）'",
        'deduct_amt'    => "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '客戶扣款金額'",
        'due_amount'    => "DECIMAL(14,2) NULL COMMENT '應收金額（扣款前）'",
        'settle_extra'  => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=沖帳上限含扣款與匯費'",
    ];
    foreach ($add as $col => $def)
        if (!isset($have[$col])) $db->exec("ALTER TABLE acc_receipt ADD COLUMN $col $def");
}

/** 收款方式選項：客戶主檔可維護的收款方式（cust_option pay_method）＋固定幾種，去重 */
function acc_rcpt_methods(PDO $db): array
{
    $out = ACC_RCPT_METHODS;
    try {
        require_once __DIR__ . '/cust_option_lib.php';
        foreach (eg_cust_opt_names($db, 'pay_method') as $n) if ($n !== '附回郵' && !in_array($n, $out, true)) array_splice($out, count($out) - 1, 0, [$n]);
    } catch (Throwable $e) {}
    return $out;
}

/** 客戶收帳設定 */
function acc_rcpt_cust_terms(PDO $db, string $customerName): array
{
    $m = acc_customer_by_name($db);
    $cid = trim((string)($m[$customerName]['customer_id'] ?? ''));
    $out = ['customer_id' => $cid, 'net_days' => null, 'net_unit' => null, 'allow_deduct' => 0,
            'payment_method' => null, 'payment_methods' => null, 'settlement_mode' => null];
    if ($cid === '') return $out;
    try {
        $st = $db->prepare("SELECT net_days, net_unit, allow_deduct, deduct_pct, payment_method, payment_methods, settlement_mode
                            FROM customer_list WHERE customer_id=? LIMIT 1");
        $st->execute([$cid]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) $out = array_merge($out, $r);
    } catch (Throwable $e) {}
    return $out;
}

/** 應入帳日期；推算不出來回 null */
function acc_rcpt_due_date(array $terms, ?string $billingMonth, ?string $invoiceDate, ?string $voucherDate): ?string
{
    if ($terms['net_days'] === null || $terms['net_days'] === '' ) return null;
    $n = (int)$terms['net_days'];
    $unit = (string)($terms['net_unit'] ?? '');
    if ($unit === 'month' || $unit === 'next' || $unit === 'months') {
        if (!preg_match('/^\d{4}-\d{2}$/', (string)$billingMonth)) return null;
        $base = date('Y-m-t', strtotime($billingMonth . '-01'));
    } else {
        $base = $invoiceDate ?: $voucherDate;
        if (!$base) return null;
    }
    return date('Y-m-d', strtotime($base . ' +' . $n . ' days'));
}

/** 待收款發票清單：請款日期、應入帳日期、逾期天數 */
function acc_rcpt_due_list(PDO $db, array $f): array
{
    acc_rcpt_ext_ensure($db);
    $where = ["i.status='issued'", "i.doc_type='INVOICE'"]; $p = [];
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(i.customer_name LIKE ? OR i.customer_full LIKE ? OR i.invoice_no LIKE ? OR i.customer_id LIKE ?)";
        for ($k = 0; $k < 4; $k++) $p[] = '%' . $kw . '%';
    }
    $st = $db->prepare("SELECT i.invoice_id, i.invoice_no, i.invoice_date, i.customer_name, i.customer_id, i.billing_month,
                               i.total_amount,
                               COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a WHERE a.invoice_id=i.invoice_id),0) AS paid
                        FROM acc_invoice i WHERE " . implode(' AND ', $where) . "
                        ORDER BY i.customer_name, i.invoice_date");
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // 傳票日期（請款日期）：發票明細所屬傳票，同一張發票取最早的
    $vd = [];
    $ids = array_map(fn($r) => (int)$r['invoice_id'], $rows);
    if ($ids) {
        for ($o = 0; $o < count($ids); $o += 500) {
            $part = array_slice($ids, $o, 500);
            $ph = implode(',', array_fill(0, count($part), '?'));
            $q = $db->prepare("SELECT ii.invoice_id, MIN(v.voucher_date) d, GROUP_CONCAT(DISTINCT v.voucher_no) nos
                               FROM acc_invoice_item ii
                               JOIN acc_voucher_item vi ON vi.src_type=ii.src_type AND vi.src_id=ii.src_id
                               JOIN acc_voucher v ON v.voucher_id=vi.voucher_id AND v.status<>'void'
                               WHERE ii.invoice_id IN ($ph) GROUP BY ii.invoice_id");
            $q->execute($part);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $vd[(int)$r['invoice_id']] = $r;
        }
    }
    $termsCache = [];
    $today = date('Y-m-d');
    $out = [];
    foreach ($rows as $r) {
        $open = round((float)$r['total_amount'] - (float)$r['paid'], 2);
        if ($open <= 0.005) continue;
        $cn = $r['customer_name'];
        if (!isset($termsCache[$cn])) $termsCache[$cn] = acc_rcpt_cust_terms($db, $cn);
        $t = $termsCache[$cn];
        $v = $vd[(int)$r['invoice_id']] ?? null;
        $due = acc_rcpt_due_date($t, $r['billing_month'], $r['invoice_date'], $v['d'] ?? null);
        $out[] = [
            'invoice_id' => (int)$r['invoice_id'], 'invoice_no' => $r['invoice_no'], 'invoice_date' => $r['invoice_date'],
            'customer_name' => $cn, 'billing_month' => $r['billing_month'],
            'total_amount' => (float)$r['total_amount'], 'paid' => (float)$r['paid'], 'open' => $open,
            'request_date' => $v['d'] ?? null, 'voucher_no' => $v['nos'] ?? null,
            'due_date' => $due,
            'overdue_days' => ($due && $due < $today) ? (int)((strtotime($today) - strtotime($due)) / 86400) : 0,
            'terms_text' => acc_rcpt_terms_text($t), 'allow_deduct' => (int)$t['allow_deduct'],
        ];
    }
    if (($f['only_overdue'] ?? '') === '1') $out = array_values(array_filter($out, fn($x) => $x['overdue_days'] > 0));
    usort($out, fn($a, $b) => [$a['due_date'] ?? '9999', $a['customer_name']] <=> [$b['due_date'] ?? '9999', $b['customer_name']]);
    return $out;
}

function acc_rcpt_terms_text(array $t): string
{
    if ($t['net_days'] === null || $t['net_days'] === '') return '未設定收帳天數';
    $u = (string)($t['net_unit'] ?? '');
    $n = (int)$t['net_days'];
    if ($u === 'next') return '次月結（' . $n . ' 天）';
    if ($u === 'month' || $u === 'months') return '月結 ' . $n . ' 天';
    return '發票日後 ' . $n . ' 天';
}

/**
 * 收款單延伸欄位檢核＋計算（存核心收款單之前呼叫）。
 * @return array ['ok'=>bool,'message'=>..., 'method'=>..., 'amount'=>實收, 'ext'=>[...], 'warn'=>[...]]
 */
function acc_rcpt_ext_check(PDO $db, array $d): array
{
    $method = trim((string)($d['method'] ?? ''));
    if (!in_array($method, acc_rcpt_methods($db), true)) return ['ok' => false, 'message' => '請選擇收款方式'];
    $due  = ($d['due_amount'] ?? '') === '' ? null : round((float)$d['due_amount'], 2);
    $pct  = ($d['deduct_pct'] ?? '') === '' ? null : round((float)$d['deduct_pct'], 3);
    $ded  = round((float)($d['deduct_amt'] ?? 0), 2);
    $fee  = round((float)($d['fee'] ?? 0), 2);
    $amt  = round((float)($d['amount'] ?? 0), 2);
    $acct = trim((string)($d['remit_account'] ?? ''));
    $ckNo = trim((string)($d['check_no'] ?? ''));
    $ckDue = trim((string)($d['check_due'] ?? ''));

    if ($pct !== null && ($pct < 0 || $pct > 100)) return ['ok' => false, 'message' => '扣% 須介於 0~100'];
    if ($ded < 0 || $fee < 0) return ['ok' => false, 'message' => '扣款與手續費不可為負數'];
    if ($method === '匯款' && $acct === '') return ['ok' => false, 'message' => '匯款需填「匯入帳號」'];
    if ($method === '支票') {
        if ($ckNo === '') return ['ok' => false, 'message' => '支票需填「支票號碼」'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ckDue)) return ['ok' => false, 'message' => '支票需填「票期」（到期日）'];
    }
    if ($due !== null && abs(($due - $ded - $fee) - $amt) > 0.5)
        return ['ok' => false, 'message' => '金額不符：應收 ' . number_format($due) . ' － 扣款 ' . number_format($ded)
                . ' － 匯費 ' . number_format($fee) . ' ＝ ' . number_format($due - $ded - $fee) . '，與實收 ' . number_format($amt) . ' 不同'];
    $warn = [];
    if ($ded > 0) {
        $t = acc_rcpt_cust_terms($db, trim((string)($d['customer_name'] ?? '')));
        if (!(int)$t['allow_deduct']) $warn[] = '此客戶在主檔未勾選「接受扣%」，請確認扣款是否已與客戶約定';
    }
    return ['ok' => true, 'method' => $method, 'amount' => $amt, 'warn' => $warn,
            'ext' => ['remit_account' => $acct !== '' ? $acct : null, 'deduct_pct' => $pct, 'deduct_amt' => $ded,
                      'due_amount' => $due, 'settle_extra' => 1]];
}

function acc_rcpt_ext_save(PDO $db, int $receiptId, array $ext): void
{
    $db->prepare("UPDATE acc_receipt SET remit_account=?, deduct_pct=?, deduct_amt=?, due_amount=?, settle_extra=? WHERE receipt_id=?")
       ->execute([$ext['remit_account'], $ext['deduct_pct'], $ext['deduct_amt'], $ext['due_amount'], $ext['settle_extra'], $receiptId]);
}
