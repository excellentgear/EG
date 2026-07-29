<?php
/**
 * src/common/acc_lib.php — 會計模組共用函式庫
 *
 * 第一階段：客戶發票資料維護（統編／發票全名／發票地址／發票 email）。
 * 背景：925 家有效客戶只有 15 家有統編、近一年有出貨的 175 家只有 5 家有，
 *       沒有買方統編就開不出電子發票，這是應收/發票模組的資料前置條件。
 *
 * 後續階段會在此檔擴充：帳款月份計算、應收對帳、發票主檔、收款沖帳、應付。
 * 權限 roles module='accounting'。
 */

if (!defined('ACC_MODULE')) define('ACC_MODULE', 'accounting');

/* ============================================================
 * 權限（RBAC，比照 shipping_lib.php / vendor_audit_lib.php）
 * ============================================================ */
function acc_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname = ?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function acc_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                        WHERE ur.user_id = ? AND r.module = '" . ACC_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id = m.position_id
                        JOIN roles r ON r.role_id = pr.role_id
                        WHERE m.user_id = ? AND r.module = '" . ACC_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function acc_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canEdit' => false, 'canView' => false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin  || acc_has_role($db, $uid, ['acc_admin']);
    $canEdit  = $canAdmin || acc_has_role($db, $uid, ['acc_edit']);
    $canView  = $canEdit  || acc_has_role($db, $uid, ['acc_view']);
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canEdit' => $canEdit, 'canView' => $canView];
}

/* ============================================================
 * 統一編號驗證（財政部檢查碼規則）
 * ============================================================ */

/**
 * 台灣統一編號檢查：8 位數字 + 加權檢查碼。
 * 權重 1,2,1,2,1,2,4,1；各位數乘權重後「各自拆成十位+個位相加」再總和，
 * 總和能被 5 整除即有效；第 7 位為 7 時，總和 +1 亦視為有效（特例）。
 */
function acc_valid_tax_id(?string $t): bool
{
    $t = preg_replace('/\D/', '', (string)$t);
    if (strlen($t) !== 8) return false;
    if ($t === '00000000') return false;      // 全零雖能被 5 整除，但不是有效統編

    $w   = [1, 2, 1, 2, 1, 2, 4, 1];
    $sum = 0;
    for ($i = 0; $i < 8; $i++) {
        $p = ((int)$t[$i]) * $w[$i];
        $sum += intdiv($p, 10) + ($p % 10);
    }
    if ($sum % 5 === 0) return true;
    return ($t[6] === '7' && ($sum + 1) % 5 === 0);
}

/** 回傳客戶發票資料的完備狀態：ok / no_tax / bad_tax / no_full */
function acc_invoice_ready(array $c): string
{
    $tax  = trim((string)($c['tax_id'] ?? ''));
    $full = trim((string)($c['customer_full'] ?? ''));
    if ($tax === '')                  return 'no_tax';
    if (!acc_valid_tax_id($tax))      return 'bad_tax';
    if ($full === '')                 return 'no_full';
    return 'ok';
}

/* ============================================================
 * 客戶發票資料清單
 * ============================================================ */

/**
 * 客戶清單 + 近一年出貨統計（用來決定先補哪些客戶的統編）。
 * 出貨歸戶：is_list 近期 Client_id 幾乎為 NULL，只能用簡稱 Client_name 對 customer_list.customer。
 *
 * @param array $f kw, status(all|no_tax|bad_tax|no_full|ok|shipped), sort, dir, page, per_page, months
 * @return array ['rows'=>, 'total'=>, 'summary'=>]
 */
function acc_customer_invoice_list(PDO $db, array $f): array
{
    $months = (int)($f['months'] ?? 12);
    if ($months <= 0 || $months > 60) $months = 12;
    $since = date('Y-m-d', strtotime("-{$months} months"));

    $where  = ['(c.is_inactive = 0 OR c.is_inactive IS NULL)'];
    $params = [':since' => $since];

    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(c.customer LIKE :kw OR c.customer_full LIKE :kw
                     OR c.customer_id LIKE :kw OR c.tax_id LIKE :kw)";
        $params[':kw'] = '%' . $kw . '%';
    }

    $sql = "
        SELECT c.customer_id, c.customer, c.customer_full, c.tax_id, c.invoice_email,
               c.customer_address, c.billing_contact,
               COALESCE(s.cnt, 0) AS ship_cnt,
               COALESCE(s.amt, 0) AS ship_amt,
               s.last_date
        FROM customer_list c
        LEFT JOIN (
            SELECT il.Client_name,
                   COUNT(*)                       AS cnt,
                   SUM(il.Qty * il.Unit_price)    AS amt,
                   DATE_FORMAT(MAX(il.Order_date), '%Y-%m-%d') AS last_date
            FROM is_list il
            WHERE il.Order_date >= :since
            GROUP BY il.Client_name
        ) s ON s.Client_name = c.customer
        WHERE " . implode(' AND ', $where);

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // 完備狀態
    foreach ($rows as &$r) {
        $r['ship_cnt'] = (int)$r['ship_cnt'];
        $r['ship_amt'] = (float)$r['ship_amt'];
        $r['status']   = acc_invoice_ready($r);
        $r['tax_ok']   = ($r['tax_id'] !== null && trim($r['tax_id']) !== '')
                         ? acc_valid_tax_id($r['tax_id']) : null;
    }
    unset($r);

    // 全部符合條件先算合計（不可只用當頁）
    $summary = ['total' => count($rows), 'ok' => 0, 'no_tax' => 0, 'bad_tax' => 0,
                'no_full' => 0, 'shipped' => 0, 'shipped_no_tax' => 0];
    foreach ($rows as $r) {
        $summary[$r['status']]++;
        if ($r['ship_cnt'] > 0) {
            $summary['shipped']++;
            if ($r['status'] !== 'ok') $summary['shipped_no_tax']++;
        }
    }

    // 狀態篩選
    $status = $f['status'] ?? 'all';
    if ($status === 'shipped') {
        $rows = array_values(array_filter($rows, fn($r) => $r['ship_cnt'] > 0));
    } elseif ($status === 'shipped_gap') {
        $rows = array_values(array_filter($rows, fn($r) => $r['ship_cnt'] > 0 && $r['status'] !== 'ok'));
    } elseif ($status !== 'all') {
        $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $status));
    }

    // 排序：預設「近一年出貨金額」由大到小 —— 先補最重要的客戶
    $sort = $f['sort'] ?? 'ship_amt';
    $dir  = (($f['dir'] ?? 'desc') === 'asc') ? 1 : -1;
    usort($rows, function ($a, $b) use ($sort, $dir) {
        switch ($sort) {
            case 'customer':    return $dir * strnatcasecmp($a['customer'] ?? '', $b['customer'] ?? '');
            case 'customer_id': return $dir * strnatcasecmp($a['customer_id'], $b['customer_id']);
            case 'ship_cnt':    return $dir * ($a['ship_cnt'] <=> $b['ship_cnt']);
            case 'last_date':   return $dir * strcmp($a['last_date'] ?? '', $b['last_date'] ?? '');
            case 'status':      return $dir * strcmp($a['status'], $b['status']);
            default:            return $dir * ($a['ship_amt'] <=> $b['ship_amt']);
        }
    });

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) {
        return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
    }
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));

    return [
        'rows'     => array_slice($rows, ($page - 1) * $perPage, $perPage),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'summary'  => $summary,
    ];
}

/* ============================================================
 * 更新／匯入
 * ============================================================ */

/** 可由本頁維護的欄位白名單（不開放改 customer_id / customer 本身） */
function acc_invoice_fields(): array
{
    return ['customer_full', 'tax_id', 'invoice_email', 'customer_address', 'billing_contact'];
}

/** 單筆就地編輯 */
function acc_update_customer(PDO $db, string $customerId, array $data, string $userId): array
{
    $allow = acc_invoice_fields();
    $set   = [];
    $vals  = [];
    foreach ($allow as $col) {
        if (!array_key_exists($col, $data)) continue;
        $v = trim((string)$data[$col]);
        if ($col === 'tax_id' && $v !== '') {
            $v = preg_replace('/\D/', '', $v);
            if (!acc_valid_tax_id($v)) {
                return ['success' => false, 'message' => "統一編號 {$v} 檢查碼不正確，請確認"];
            }
        }
        $set[]  = "$col = ?";
        $vals[] = ($v === '') ? null : $v;
    }
    if (!$set) return ['success' => false, 'message' => '沒有要更新的欄位'];

    $set[]  = "Modified_By = ?"; $vals[] = $userId;
    $set[]  = "Modified_At = NOW()";
    $vals[] = $customerId;

    try {
        $db->beginTransaction();
        $st = $db->prepare("UPDATE customer_list SET " . implode(', ', $set) . " WHERE customer_id = ?");
        $st->execute($vals);
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'updated' => $n,
                'message' => $n > 0 ? '已更新' : '資料未變更'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '更新失敗：' . $e->getMessage()];
    }
}

/**
 * CSV 匯入試算：把上傳的每一列比對到現有客戶，回傳可套用的變更（不寫入）。
 * 比對優先序：客戶編號 > 統編 > 客戶簡稱（完全相同）> 客戶全名（完全相同）。
 *
 * @param array $rows 已解析的 CSV 資料列（關聯陣列，鍵為對應後的欄位名）
 */
function acc_import_preview(PDO $db, array $rows): array
{
    $cust = $db->query("SELECT customer_id, customer, customer_full, tax_id, invoice_email,
                               customer_address, billing_contact
                        FROM customer_list")->fetchAll(PDO::FETCH_ASSOC);

    $byId = $byName = $byFull = $byTax = [];
    foreach ($cust as $c) {
        $byId[strtoupper(trim($c['customer_id']))] = $c;
        if (trim((string)$c['customer'])      !== '') $byName[trim($c['customer'])]      = $c;
        if (trim((string)$c['customer_full']) !== '') $byFull[trim($c['customer_full'])] = $c;
        $t = preg_replace('/\D/', '', (string)$c['tax_id']);
        if ($t !== '') $byTax[$t] = $c;
    }

    $out = ['matched' => [], 'unmatched' => [], 'summary' => [
        'total' => count($rows), 'matched' => 0, 'unmatched' => 0,
        'changed' => 0, 'same' => 0, 'bad_tax' => 0]];

    foreach ($rows as $i => $r) {
        $cid  = strtoupper(trim((string)($r['customer_id']    ?? '')));
        $name = trim((string)($r['customer']      ?? ''));
        $full = trim((string)($r['customer_full'] ?? ''));
        $tax  = preg_replace('/\D/', '', (string)($r['tax_id'] ?? ''));

        $hit = null; $how = '';
        if     ($cid  !== '' && isset($byId[$cid]))    { $hit = $byId[$cid];    $how = '客戶編號'; }
        elseif ($tax  !== '' && isset($byTax[$tax]))   { $hit = $byTax[$tax];   $how = '統一編號'; }
        elseif ($name !== '' && isset($byName[$name])) { $hit = $byName[$name]; $how = '客戶簡稱'; }
        elseif ($full !== '' && isset($byFull[$full])) { $hit = $byFull[$full]; $how = '客戶全名'; }

        $taxBad = ($tax !== '' && !acc_valid_tax_id($tax));
        if ($taxBad) $out['summary']['bad_tax']++;

        if (!$hit) {
            $out['summary']['unmatched']++;
            $out['unmatched'][] = ['row' => $i + 1, 'customer_id' => $cid, 'customer' => $name,
                                   'customer_full' => $full, 'tax_id' => $tax, 'tax_bad' => $taxBad];
            continue;
        }

        // 逐欄比對出「真正會變動」的欄位（空值不覆蓋既有值）
        $chg = [];
        foreach (acc_invoice_fields() as $col) {
            $nv = trim((string)($r[$col] ?? ''));
            if ($col === 'tax_id') $nv = preg_replace('/\D/', '', $nv);
            if ($nv === '') continue;                            // 匯入檔留空 = 不動既有資料
            $ov = trim((string)($hit[$col] ?? ''));
            if ($col === 'tax_id') $ov = preg_replace('/\D/', '', $ov);
            if ($nv !== $ov) $chg[$col] = ['old' => $ov, 'new' => $nv];
        }

        $out['summary']['matched']++;
        if ($chg) $out['summary']['changed']++; else $out['summary']['same']++;

        $out['matched'][] = [
            'row'         => $i + 1,
            'customer_id' => $hit['customer_id'],
            'customer'    => $hit['customer'],
            'match_by'    => $how,
            'changes'     => $chg,
            'tax_bad'     => $taxBad,
        ];
    }
    return $out;
}

/** 套用匯入（只寫入前端勾選的 customer_id；統編檢查碼不合格者一律擋下） */
function acc_import_apply(PDO $db, array $items, string $userId): array
{
    $applied = 0; $skipped = 0; $errors = [];
    try {
        $db->beginTransaction();
        foreach ($items as $it) {
            $cid = trim((string)($it['customer_id'] ?? ''));
            $chg = $it['changes'] ?? [];
            if ($cid === '' || !$chg) { $skipped++; continue; }

            $set = []; $vals = [];
            foreach ($chg as $col => $v) {
                if (!in_array($col, acc_invoice_fields(), true)) continue;
                $nv = trim((string)(is_array($v) ? ($v['new'] ?? '') : $v));
                if ($col === 'tax_id') {
                    $nv = preg_replace('/\D/', '', $nv);
                    if ($nv !== '' && !acc_valid_tax_id($nv)) {
                        $errors[] = "{$cid}：統編 {$nv} 檢查碼不正確，已略過";
                        continue 2;
                    }
                }
                $set[]  = "$col = ?";
                $vals[] = ($nv === '') ? null : $nv;
            }
            if (!$set) { $skipped++; continue; }

            $set[]  = "Modified_By = ?"; $vals[] = $userId;
            $set[]  = "Modified_At = NOW()";
            $vals[] = $cid;
            $st = $db->prepare("UPDATE customer_list SET " . implode(', ', $set) . " WHERE customer_id = ?");
            $st->execute($vals);
            if ($st->rowCount() > 0) $applied++; else $skipped++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '匯入失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'applied' => $applied, 'skipped' => $skipped, 'errors' => $errors,
            'message' => "已更新 {$applied} 家客戶" . ($skipped ? "，略過 {$skipped} 家" : '')];
}

/* ============================================================
 * 帳款月份（結帳日切月）
 *
 * 口徑沿用 Shipping_Analysis_new.php 的 compute_billing_month_global，
 * 但額外支援客戶自己的結帳日（customer_list.settlement_mode / settlement_day）：
 *   FIXED    → 用 settlement_day（實測 908 家 = 25、5 家 = 20）
 *   EOM      → 月底結帳，等於不跨月（實測 11 家）
 *   VARIABLE → 無固定規則，退回用全域截止日（實測 1 家）
 * is_list / ir_track 的 billing_month_override 一律優先於上述計算。
 * ============================================================ */

/** 全域帳款月份截止日（system_settings.billing_cutoff_day，實測 = 25） */
function acc_global_cutoff(PDO $db): int
{
    static $c = null;
    if ($c !== null) return $c;
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='billing_cutoff_day' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        $c = ($v !== false) ? (int)$v : 0;
    } catch (Throwable $e) { $c = 0; }
    return $c;
}

/** 營業稅率（system_settings.acc_tax_rate，預設 5%） */
function acc_tax_rate(PDO $db): float
{
    static $r = null;
    if ($r !== null) return $r;
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='acc_tax_rate' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        $r = ($v !== false && $v !== '' && (float)$v > 0) ? (float)$v : 0.05;
    } catch (Throwable $e) { $r = 0.05; }
    return $r;
}

/** 取某客戶適用的結帳日；回傳 0 = 不跨月（當月即帳款月） */
function acc_cutoff_for(?array $cust, int $global): int
{
    if (!$cust) return $global;
    $mode = strtoupper(trim((string)($cust['settlement_mode'] ?? '')));
    if ($mode === 'EOM') return 0;                       // 月底結帳＝不跨月
    if ($mode === 'FIXED') {
        $d = (int)($cust['settlement_day'] ?? 0);
        if ($d >= 1 && $d <= 31) return $d;
    }
    return $global;                                      // VARIABLE 或未設定
}

/** 依出貨/退貨日期與結帳日算出帳款月份 YYYY-MM */
function acc_billing_month(string $date, int $cutoff): string
{
    $ts = strtotime($date);
    if ($ts === false) return '';
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    $d = (int)date('j', $ts);
    if ($cutoff > 0 && $d > $cutoff) {
        return ($m === 12) ? sprintf('%04d-01', $y + 1) : sprintf('%04d-%02d', $y, $m + 1);
    }
    return sprintf('%04d-%02d', $y, $m);
}

/** 帳款月份對應要掃描的出貨日期區間（放寬一個月，實際歸屬再逐列精算） */
function acc_scan_range(string $bmFrom, string $bmTo): array
{
    $from = date('Y-m-d', strtotime($bmFrom . '-01 -1 month'));
    $to   = date('Y-m-t', strtotime($bmTo . '-01'));
    return [$from, $to];
}

/* ============================================================
 * 應收對帳（客戶 × 帳款月份）
 * ============================================================ */

/**
 * 應收金額口徑（重要）：
 *  - 不使用 is_sale_type.is_count——那是「本業業績統計」用的旗標。
 *    機台買賣/刀具/砂輪/非本業 的 is_count=0，但那些都是真的要開發票收錢的。
 *  - 計入所有金額不為 0 的出貨列；退貨(ir_track)金額則從應收扣除。
 *  - is_sale_type.exclude_when_nonzero=1（備註、歸還NG）本來就該是 0 元，
 *    若出現金額不視為正常，計入但另外標記為待確認，不靜默吞掉。
 *  - ir_return_type.is_note=1（備註性質退貨）不計金額。
 *
 * @param array $f bm_from, bm_to (YYYY-MM), customer_id, kw, only_gap, sort, dir, page, per_page
 */
function acc_ar_summary(PDO $db, array $f): array
{
    $bmFrom = preg_match('/^\d{4}-\d{2}$/', (string)($f['bm_from'] ?? '')) ? $f['bm_from'] : date('Y-m');
    $bmTo   = preg_match('/^\d{4}-\d{2}$/', (string)($f['bm_to'] ?? ''))   ? $f['bm_to']   : $bmFrom;
    if ($bmTo < $bmFrom) [$bmFrom, $bmTo] = [$bmTo, $bmFrom];
    [$scanFrom, $scanTo] = acc_scan_range($bmFrom, $bmTo);

    $global = acc_global_cutoff($db);
    $rate   = acc_tax_rate($db);

    // 客戶主檔（用簡稱歸戶：近期 is_list.Client_id 幾乎為 NULL）
    $cust = [];
    foreach ($db->query("SELECT customer_id, customer, customer_full, tax_id, invoice_email,
                                settlement_mode, settlement_day, payment_method, net_days
                         FROM customer_list")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (trim((string)$c['customer']) !== '') $cust[trim($c['customer'])] = $c;
    }

    $bucket = [];   // key = customer_key|billing_month
    $anomaly = [];

    $addRow = function (string $name, string $bm, array $add) use (&$bucket, $cust) {
        $c   = $cust[$name] ?? null;
        $key = ($c ? $c['customer_id'] : '?' . $name) . '|' . $bm;
        if (!isset($bucket[$key])) {
            $bucket[$key] = [
                'customer_id'    => $c['customer_id']   ?? null,
                'customer'       => $name,
                'customer_full'  => $c['customer_full'] ?? null,
                'tax_id'         => $c['tax_id']        ?? null,
                'invoice_email'  => $c['invoice_email'] ?? null,
                'payment_method' => $c['payment_method'] ?? null,
                'net_days'       => $c['net_days']      ?? null,
                'in_master'      => (bool)$c,
                'billing_month'  => $bm,
                'ship_cnt' => 0, 'ship_amt' => 0.0,
                'ret_cnt'  => 0, 'ret_amt'  => 0.0,
                'anomaly'  => 0,
            ];
        }
        foreach ($add as $k => $v) $bucket[$key][$k] += $v;
    };

    // ── 出貨 ────────────────────────────────────────────────────────────
    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, il.Client_name, il.Order_date, il.Qty, il.Unit_price,
               il.sale_type, il.billing_month_override,
               COALESCE(ist.exclude_when_nonzero, 0) AS excl_nonzero
        FROM is_list il
        LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
        WHERE il.Order_date BETWEEN ? AND ?");
    $st->execute([$scanFrom, $scanTo]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;                       // 0 元列不構成應收
        $name = trim((string)$r['Client_name']);
        if ($name === '') continue;

        $bm = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) {
            $bm = acc_billing_month($r['Order_date'], acc_cutoff_for($cust[$name] ?? null, $global));
        }
        if ($bm < $bmFrom || $bm > $bmTo) continue;

        $bad = ((int)$r['excl_nonzero'] === 1);
        $addRow($name, $bm, ['ship_cnt' => 1, 'ship_amt' => $amt, 'anomaly' => $bad ? 1 : 0]);
        if ($bad) {
            $anomaly[] = ['is_number' => $r['IS_number'], 'client' => $name, 'date' => $r['Order_date'],
                          'amount' => $amt, 'reason' => '此出貨性質應為 0 元卻有金額，請確認'];
        }
    }

    // ── 退貨（從應收扣除；備註性質不計金額）─────────────────────────────
    $st2 = $db->prepare("
        SELECT it.IR_id, it.IR_no, it.Client_name, it.IR_date, it.Qty, it.Unit_price,
               it.billing_month_override, COALESCE(rt.is_note, 0) AS is_note
        FROM ir_track it
        LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
        WHERE it.IR_date BETWEEN ? AND ?");
    $st2->execute([$scanFrom, $scanTo]);

    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['is_note'] === 1) continue;
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $name = trim((string)$r['Client_name']);
        if ($name === '') continue;

        $bm = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) {
            $bm = acc_billing_month($r['IR_date'], acc_cutoff_for($cust[$name] ?? null, $global));
        }
        if ($bm < $bmFrom || $bm > $bmTo) continue;

        $addRow($name, $bm, ['ret_cnt' => 1, 'ret_amt' => $amt]);
    }

    // ── 收斂成清單 ──────────────────────────────────────────────────────
    $rows = [];
    foreach ($bucket as $b) {
        $net = $b['ship_amt'] - $b['ret_amt'];
        $b['net_amt']   = $net;
        $b['tax_amt']   = round($net * $rate);
        $b['total_amt'] = $net + $b['tax_amt'];
        $b['inv_ready'] = acc_invoice_ready($b);            // 能不能開發票
        $rows[] = $b;
    }

    // 篩選
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $rows = array_values(array_filter($rows, fn($r) =>
            mb_stripos((string)$r['customer'], $kw) !== false
            || mb_stripos((string)$r['customer_full'], $kw) !== false
            || mb_stripos((string)$r['customer_id'], $kw) !== false));
    }
    if (!empty($f['customer_id'])) {
        $rows = array_values(array_filter($rows, fn($r) => $r['customer_id'] === $f['customer_id']));
    }
    if (!empty($f['only_gap'])) {                            // 只看不能開發票的
        $rows = array_values(array_filter($rows, fn($r) => $r['inv_ready'] !== 'ok'));
    }

    // 全部符合條件才算合計
    $summary = ['groups' => count($rows), 'ship_amt' => 0.0, 'ret_amt' => 0.0,
                'net_amt' => 0.0, 'tax_amt' => 0.0, 'total_amt' => 0.0,
                'not_ready' => 0, 'not_in_master' => 0, 'anomaly' => count($anomaly)];
    foreach ($rows as $r) {
        $summary['ship_amt']  += $r['ship_amt'];
        $summary['ret_amt']   += $r['ret_amt'];
        $summary['net_amt']   += $r['net_amt'];
        $summary['tax_amt']   += $r['tax_amt'];
        $summary['total_amt'] += $r['total_amt'];
        if ($r['inv_ready'] !== 'ok') $summary['not_ready']++;
        if (!$r['in_master'])         $summary['not_in_master']++;
    }

    // 排序：預設應收金額由大到小
    $sort = $f['sort'] ?? 'net_amt';
    $dir  = (($f['dir'] ?? 'desc') === 'asc') ? 1 : -1;
    usort($rows, function ($a, $b) use ($sort, $dir) {
        switch ($sort) {
            case 'customer':      return $dir * strnatcasecmp($a['customer'], $b['customer']);
            case 'billing_month': return $dir * strcmp($a['billing_month'], $b['billing_month']);
            case 'ship_amt':      return $dir * ($a['ship_amt'] <=> $b['ship_amt']);
            case 'total_amt':     return $dir * ($a['total_amt'] <=> $b['total_amt']);
            default:              return $dir * ($a['net_amt'] <=> $b['net_amt']);
        }
    });

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) {
        return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0,
                'summary' => $summary, 'anomaly' => $anomaly, 'tax_rate' => $rate,
                'bm_from' => $bmFrom, 'bm_to' => $bmTo];
    }
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));

    return [
        'rows'     => array_slice($rows, ($page - 1) * $perPage, $perPage),
        'total'    => $total, 'page' => $page, 'per_page' => $perPage,
        'summary'  => $summary, 'anomaly' => $anomaly, 'tax_rate' => $rate,
        'bm_from'  => $bmFrom, 'bm_to' => $bmTo,
    ];
}

/** 單一客戶單一帳款月份的明細（對帳單內容） */
function acc_ar_detail(PDO $db, string $clientName, string $billingMonth): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $billingMonth)) return ['items' => [], 'head' => null];
    [$scanFrom, $scanTo] = acc_scan_range($billingMonth, $billingMonth);
    $global = acc_global_cutoff($db);
    $rate   = acc_tax_rate($db);

    $stc = $db->prepare("SELECT customer_id, customer, customer_full, tax_id, invoice_email,
                                customer_address, billing_contact, settlement_mode, settlement_day,
                                payment_method, net_days
                         FROM customer_list WHERE customer = ? LIMIT 1");
    $stc->execute([$clientName]);
    $c = $stc->fetch(PDO::FETCH_ASSOC) ?: null;
    $cut = acc_cutoff_for($c, $global);

    $items = [];

    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS d,
               il.Product_id, il.Specification, il.Qty, il.Unit_price, il.Note,
               il.billing_month_override, ot.Order_oo,
               COALESCE(ist.sale_type_name,'') AS sale_type_name
        FROM is_list il
        LEFT JOIN order_track  ot  ON ot.Order_id = il.Order_id
        LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
        WHERE il.Client_name = ? AND il.Order_date BETWEEN ? AND ?
        ORDER BY il.Order_date, il.IS_number, il.IS_id");
    $st->execute([$clientName, $scanFrom, $scanTo]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $bm = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) $bm = acc_billing_month($r['d'], $cut);
        if ($bm !== $billingMonth) continue;
        $items[] = ['kind' => 'ship', 'no' => $r['IS_number'], 'date' => $r['d'],
                    'order_oo' => $r['Order_oo'], 'product_id' => $r['Product_id'],
                    'spec' => $r['Specification'], 'qty' => (int)$r['Qty'],
                    'unit_price' => (float)$r['Unit_price'], 'amount' => $amt,
                    'note' => $r['Note'], 'sale_type' => $r['sale_type_name']];
    }

    $st2 = $db->prepare("
        SELECT it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d, it.d_id, it.Specification,
               it.Qty, it.Unit_price, it.IR_ps, it.billing_month_override,
               COALESCE(rt.is_note,0) AS is_note
        FROM ir_track it
        LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
        WHERE it.Client_name = ? AND it.IR_date BETWEEN ? AND ?
        ORDER BY it.IR_date, it.IR_no");
    $st2->execute([$clientName, $scanFrom, $scanTo]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['is_note'] === 1) continue;
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $bm = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) $bm = acc_billing_month($r['d'], $cut);
        if ($bm !== $billingMonth) continue;
        $items[] = ['kind' => 'return', 'no' => $r['IR_no'], 'date' => $r['d'],
                    'order_oo' => null, 'product_id' => $r['d_id'], 'spec' => $r['Specification'],
                    'qty' => -(int)$r['Qty'], 'unit_price' => (float)$r['Unit_price'],
                    'amount' => -$amt, 'note' => $r['IR_ps'], 'sale_type' => '退貨'];
    }

    usort($items, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp((string)$a['no'], (string)$b['no']));

    $net = 0.0;
    foreach ($items as $i) $net += $i['amount'];
    $tax = round($net * $rate);

    return [
        'head' => [
            'customer'       => $clientName,
            'customer_id'    => $c['customer_id']   ?? null,
            'customer_full'  => $c['customer_full'] ?? null,
            'tax_id'         => $c['tax_id']        ?? null,
            'address'        => $c['customer_address'] ?? null,
            'contact'        => $c['billing_contact']  ?? null,
            'payment_method' => $c['payment_method']   ?? null,
            'net_days'       => $c['net_days']         ?? null,
            'cutoff'         => $cut,
            'billing_month'  => $billingMonth,
            'in_master'      => (bool)$c,
            'inv_ready'      => $c ? acc_invoice_ready($c) : 'no_tax',
        ],
        'items'     => $items,
        'net_amt'   => $net,
        'tax_amt'   => $tax,
        'total_amt' => $net + $tax,
        'tax_rate'  => $rate,
    ];
}

/** 解析上傳的 CSV（支援 UTF-8 BOM 與 Big5），回傳 [表頭, 資料列] */
function acc_parse_csv(string $raw): array
{
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $conv = @mb_convert_encoding($raw, 'UTF-8', 'BIG-5,CP950,UTF-8');
        if ($conv !== false) $raw = $conv;
    }
    $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
    if (!$lines) return [[], []];

    $head = str_getcsv(array_shift($lines));
    $head = array_map(fn($h) => trim($h, " \t\"'"), $head);

    $data = [];
    foreach ($lines as $l) $data[] = str_getcsv($l);
    return [$head, $data];
}
