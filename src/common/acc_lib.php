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
