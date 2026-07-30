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
 * 資料表建立（比照 vendor_audit_ensure_schema，頁面載入時確保存在）
 *
 * 設計原則：發票／折讓／收款是「會計層」，來源憑證（is_list 出貨、ir_track 退貨）
 * 一律只用 src_type+src_id 參照，不複製金額以外的資料，也不回寫來源表。
 * 客戶統編與抬頭在開立當下做快照，避免事後客戶改名導致舊發票內容變動。
 * ============================================================ */
function acc_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        // 發票主檔（含銷貨折讓證明單，用 doc_type 區分）
        $db->exec("CREATE TABLE IF NOT EXISTS acc_invoice (
            invoice_id     INT NOT NULL AUTO_INCREMENT,
            doc_type       VARCHAR(10)  NOT NULL DEFAULT 'INVOICE' COMMENT 'INVOICE=發票 ALLOWANCE=銷貨折讓證明單',
            invoice_no     VARCHAR(20)  DEFAULT NULL COMMENT '實際開立的發票號碼（外部平台開立後回填）',
            invoice_date   DATE         DEFAULT NULL COMMENT '實際開立日期（回填）',
            customer_id    VARCHAR(11)  DEFAULT NULL COMMENT '對應 customer_list.customer_id',
            customer_name  VARCHAR(60)  NOT NULL COMMENT '客戶簡稱（開立當下快照）',
            customer_full  VARCHAR(100) DEFAULT NULL COMMENT '發票抬頭（開立當下快照）',
            tax_id         VARCHAR(20)  DEFAULT NULL COMMENT '買方統一編號（開立當下快照）',
            billing_month  VARCHAR(7)   NOT NULL COMMENT '帳款月份 YYYY-MM',
            tax_type       TINYINT      NOT NULL DEFAULT 1 COMMENT '1=應稅 2=零稅率 3=免稅',
            tax_rate       DECIMAL(6,4) NOT NULL DEFAULT 0.0500,
            sales_amount   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '未稅金額',
            tax_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_amount   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '含稅金額',
            status         VARCHAR(10)  NOT NULL DEFAULT 'draft' COMMENT 'draft/exported/issued/void',
            export_batch   VARCHAR(30)  DEFAULT NULL,
            exported_at    DATETIME     DEFAULT NULL,
            ref_invoice_id INT          DEFAULT NULL COMMENT '折讓單所對應的原發票 invoice_id',
            note           VARCHAR(200) DEFAULT NULL,
            void_reason    VARCHAR(200) DEFAULT NULL,
            Created_By     VARCHAR(11)  DEFAULT NULL,
            Created_At     DATETIME     DEFAULT CURRENT_TIMESTAMP,
            Modified_By    VARCHAR(11)  DEFAULT NULL,
            Modified_At    DATETIME     DEFAULT NULL,
            PRIMARY KEY (invoice_id),
            UNIQUE KEY uq_inv_no (invoice_no),
            KEY idx_inv_cust_bm (customer_id, billing_month),
            KEY idx_inv_status  (status),
            KEY idx_inv_bm      (billing_month),
            KEY idx_inv_ref     (ref_invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-發票/折讓單主檔'");

        // 發票明細：src_type+src_id 唯一，防同一張出貨被開兩次發票
        $db->exec("CREATE TABLE IF NOT EXISTS acc_invoice_item (
            item_id    INT NOT NULL AUTO_INCREMENT,
            invoice_id INT NOT NULL,
            src_type   VARCHAR(4)   NOT NULL COMMENT 'IS=出貨(is_list) IR=退貨(ir_track)',
            src_id     INT          NOT NULL COMMENT 'IS_id 或 IR_id',
            src_no     VARCHAR(20)  DEFAULT NULL COMMENT '來源單號（顯示用快照）',
            src_date   DATE         DEFAULT NULL,
            product_id VARCHAR(30)  DEFAULT NULL,
            spec       VARCHAR(120) DEFAULT NULL,
            qty        INT          NOT NULL DEFAULT 0,
            unit_price DECIMAL(12,4) NOT NULL DEFAULT 0,
            amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (item_id),
            UNIQUE KEY uq_item_src (src_type, src_id),
            KEY idx_item_inv (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-發票明細（參照出貨/退貨憑證）'");

        // 收款單
        $db->exec("CREATE TABLE IF NOT EXISTS acc_receipt (
            receipt_id    INT NOT NULL AUTO_INCREMENT,
            receipt_no    VARCHAR(20)  NOT NULL,
            customer_id   VARCHAR(11)  DEFAULT NULL,
            customer_name VARCHAR(60)  NOT NULL,
            receipt_date  DATE         NOT NULL COMMENT '入帳日',
            method        VARCHAR(20)  DEFAULT '匯款' COMMENT '匯款/支票/現金/其他',
            amount        DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '實收金額',
            fee           DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '匯費/手續費（由我方負擔的扣款）',
            bank          VARCHAR(50)  DEFAULT NULL,
            check_no      VARCHAR(30)  DEFAULT NULL COMMENT '支票號碼',
            check_due     DATE         DEFAULT NULL COMMENT '票期',
            note          VARCHAR(200) DEFAULT NULL,
            Created_By    VARCHAR(11)  DEFAULT NULL,
            Created_At    DATETIME     DEFAULT CURRENT_TIMESTAMP,
            Modified_By   VARCHAR(11)  DEFAULT NULL,
            Modified_At   DATETIME     DEFAULT NULL,
            PRIMARY KEY (receipt_id),
            UNIQUE KEY uq_rcpt_no (receipt_no),
            KEY idx_rcpt_cust (customer_id),
            KEY idx_rcpt_date (receipt_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-收款單'");

        // 沖帳明細：多對多，一筆收款可沖多張發票、一張發票可被多筆收款分次沖
        $db->exec("CREATE TABLE IF NOT EXISTS acc_receipt_alloc (
            alloc_id   INT NOT NULL AUTO_INCREMENT,
            receipt_id INT NOT NULL,
            invoice_id INT NOT NULL,
            amount     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '本次沖銷金額',
            Created_By VARCHAR(11) DEFAULT NULL,
            Created_At DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (alloc_id),
            KEY idx_alloc_rcpt (receipt_id),
            KEY idx_alloc_inv  (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-收款沖帳明細（支援部分沖帳與一筆對多張）'");

        $done = true;
    } catch (Throwable $e) {
        error_log('acc_ensure_schema 失敗: ' . $e->getMessage());
    }
}

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

/* ============================================================
 * 發票開立（本系統不連電子發票，只產生「待開立清單」供外部平台開立，
 * 再把實際開出的發票號碼與日期回填。）
 * ============================================================ */

/**
 * 已被「未作廢的發票或折讓單」佔用的來源憑證：'IS-123' => 佔用資訊。
 * 必須同時涵蓋 INVOICE 與 ALLOWANCE——折讓單一樣會消耗退貨憑證，
 * 只算 INVOICE 會讓同一張退貨可被重複折讓，最後才被資料庫唯一鍵擋下，
 * 使用者看到的是原始 SQL 錯誤而不是看得懂的訊息。
 */
function acc_invoiced_src_map(PDO $db): array
{
    $st = $db->query("SELECT ii.src_type, ii.src_id, ii.invoice_id, i.status, i.invoice_no, i.doc_type
                      FROM acc_invoice_item ii
                      JOIN acc_invoice i ON i.invoice_id = ii.invoice_id
                      WHERE i.status <> 'void'");
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m[$r['src_type'] . '-' . (int)$r['src_id']] = [
            'invoice_id' => (int)$r['invoice_id'],
            'status'     => $r['status'],
            'invoice_no' => $r['invoice_no'],
            'doc_type'   => $r['doc_type'],
        ];
    }
    return $m;
}

/**
 * 取某帳款月份「尚未開發票」的憑證，依客戶分組（＝待開立清單）。
 * 作廢的發票會把其憑證釋放回來，可重新開立。
 *
 * @return array ['groups'=>[...], 'summary'=>[...]]
 */
function acc_invoice_candidates(PDO $db, string $bm, array $f = []): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $bm)) return ['groups' => [], 'summary' => []];
    [$scanFrom, $scanTo] = acc_scan_range($bm, $bm);
    $global = acc_global_cutoff($db);
    $rate   = acc_tax_rate($db);
    $used   = acc_invoiced_src_map($db);

    $cust = [];
    foreach ($db->query("SELECT customer_id, customer, customer_full, tax_id, invoice_email,
                                settlement_mode, settlement_day
                         FROM customer_list")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (trim((string)$c['customer']) !== '') $cust[trim($c['customer'])] = $c;
    }

    $groups = [];
    $touch = function (string $name) use (&$groups, $cust, $bm) {
        if (isset($groups[$name])) return;
        $c = $cust[$name] ?? null;
        $groups[$name] = [
            'customer'      => $name,
            'customer_id'   => $c['customer_id']   ?? null,
            'customer_full' => $c['customer_full'] ?? null,
            'tax_id'        => $c['tax_id']        ?? null,
            'invoice_email' => $c['invoice_email'] ?? null,
            'in_master'     => (bool)$c,
            'billing_month' => $bm,
            'items'         => [],
            'net_amt'       => 0.0,
        ];
        $groups[$name]['inv_ready'] = $c ? acc_invoice_ready($c) : 'no_tax';
    };

    // 出貨
    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS d,
               il.Client_name, il.Product_id, il.Specification, il.Qty, il.Unit_price,
               il.billing_month_override
        FROM is_list il
        WHERE il.Order_date BETWEEN ? AND ?
        ORDER BY il.Order_date, il.IS_number, il.IS_id");
    $st->execute([$scanFrom, $scanTo]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $name = trim((string)$r['Client_name']);
        if ($name === '') continue;
        $b = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $b)) {
            $b = acc_billing_month($r['d'], acc_cutoff_for($cust[$name] ?? null, $global));
        }
        if ($b !== $bm) continue;
        if (isset($used['IS-' . (int)$r['IS_id']])) continue;      // 已開過發票

        $touch($name);
        $groups[$name]['items'][] = [
            'src_type' => 'IS', 'src_id' => (int)$r['IS_id'], 'src_no' => $r['IS_number'],
            'src_date' => $r['d'], 'product_id' => $r['Product_id'],
            'spec' => $r['Specification'], 'qty' => (int)$r['Qty'],
            'unit_price' => (float)$r['Unit_price'], 'amount' => $amt,
        ];
        $groups[$name]['net_amt'] += $amt;
    }

    // 退貨（同期退貨直接在發票中扣除，就不需要另開折讓單）
    $st2 = $db->prepare("
        SELECT it.IR_id, it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d, it.Client_name,
               it.d_id, it.Specification, it.Qty, it.Unit_price, it.billing_month_override,
               COALESCE(rt.is_note,0) AS is_note
        FROM ir_track it
        LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
        WHERE it.IR_date BETWEEN ? AND ?
        ORDER BY it.IR_date, it.IR_no");
    $st2->execute([$scanFrom, $scanTo]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['is_note'] === 1) continue;
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $name = trim((string)$r['Client_name']);
        if ($name === '') continue;
        $b = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $b)) {
            $b = acc_billing_month($r['d'], acc_cutoff_for($cust[$name] ?? null, $global));
        }
        if ($b !== $bm) continue;
        if (isset($used['IR-' . (int)$r['IR_id']])) continue;

        $touch($name);
        $groups[$name]['items'][] = [
            'src_type' => 'IR', 'src_id' => (int)$r['IR_id'], 'src_no' => $r['IR_no'],
            'src_date' => $r['d'], 'product_id' => $r['d_id'],
            'spec' => $r['Specification'], 'qty' => -(int)$r['Qty'],
            'unit_price' => (float)$r['Unit_price'], 'amount' => -$amt,
        ];
        $groups[$name]['net_amt'] -= $amt;
    }

    // 稅與可開立判定
    $out = [];
    foreach ($groups as $g) {
        if (!$g['items']) continue;
        $g['tax_amt']   = round($g['net_amt'] * $rate);
        $g['total_amt'] = $g['net_amt'] + $g['tax_amt'];
        $g['item_cnt']  = count($g['items']);
        $g['can_issue'] = ($g['inv_ready'] === 'ok' && $g['net_amt'] > 0);
        $out[] = $g;
    }
    usort($out, fn($a, $b) => $b['net_amt'] <=> $a['net_amt']);

    $summary = ['groups' => count($out), 'can_issue' => 0, 'blocked' => 0,
                'net_amt' => 0.0, 'tax_amt' => 0.0, 'total_amt' => 0.0];
    foreach ($out as $g) {
        $summary['net_amt']   += $g['net_amt'];
        $summary['tax_amt']   += $g['tax_amt'];
        $summary['total_amt'] += $g['total_amt'];
        if ($g['can_issue']) $summary['can_issue']++; else $summary['blocked']++;
    }
    return ['groups' => $out, 'summary' => $summary, 'tax_rate' => $rate, 'billing_month' => $bm];
}

/**
 * 建立發票（狀態 draft）。預設一客戶一帳款月份一張；
 * 若 $splitBySrc 為 true 則每張來源出貨單各開一張（使用者可自選）。
 *
 * @param array $sel [{customer, billing_month, src:[{src_type,src_id}...]（可省略＝全取）}]
 */
function acc_create_invoices(PDO $db, array $sel, string $userId, bool $splitBySrc = false): array
{
    acc_ensure_schema($db);
    $made = []; $errors = [];

    try {
        $db->beginTransaction();
        $insInv = $db->prepare(
            "INSERT INTO acc_invoice
             (doc_type, customer_id, customer_name, customer_full, tax_id, billing_month,
              tax_type, tax_rate, sales_amount, tax_amount, total_amount, status, Created_By)
             VALUES ('INVOICE',?,?,?,?,?,1,?,?,?,?,'draft',?)");
        $insItem = $db->prepare(
            "INSERT INTO acc_invoice_item
             (invoice_id, src_type, src_id, src_no, src_date, product_id, spec, qty, unit_price, amount)
             VALUES (?,?,?,?,?,?,?,?,?,?)");

        foreach ($sel as $s) {
            $bm   = trim((string)($s['billing_month'] ?? ''));
            $name = trim((string)($s['customer'] ?? ''));
            if ($name === '' || !preg_match('/^\d{4}-\d{2}$/', $bm)) { $errors[] = "資料不完整，略過"; continue; }

            // 一律以資料庫當下狀態重算可開立項目，不信前端傳來的金額
            $cand = acc_invoice_candidates($db, $bm);
            $grp  = null;
            foreach ($cand['groups'] as $g) if ($g['customer'] === $name) { $grp = $g; break; }
            if (!$grp) { $errors[] = "{$name} {$bm}：已無可開立的憑證"; continue; }

            // 若前端指定了憑證子集，就只取那些
            if (!empty($s['src']) && is_array($s['src'])) {
                $want = [];
                foreach ($s['src'] as $x) $want[$x['src_type'] . '-' . (int)$x['src_id']] = 1;
                $grp['items'] = array_values(array_filter($grp['items'],
                    fn($it) => isset($want[$it['src_type'] . '-' . $it['src_id']])));
            }
            if (!$grp['items']) { $errors[] = "{$name} {$bm}：沒有選到憑證"; continue; }
            if ($grp['inv_ready'] !== 'ok') {
                $errors[] = "{$name}：發票資料不完整（" . $grp['inv_ready'] . "），無法開立";
                continue;
            }

            $batches = $splitBySrc ? array_map(fn($it) => [$it], $grp['items']) : [$grp['items']];
            foreach ($batches as $items) {
                $net = 0.0;
                foreach ($items as $it) $net += $it['amount'];
                if ($net <= 0) { $errors[] = "{$name} {$bm}：金額為 " . round($net) . "，不開立發票（請用折讓單處理）"; continue; }
                $tax = round($net * $cand['tax_rate']);

                $insInv->execute([$grp['customer_id'], $name, $grp['customer_full'], $grp['tax_id'],
                                  $bm, $cand['tax_rate'], $net, $tax, $net + $tax, $userId]);
                $invId = (int)$db->lastInsertId();
                foreach ($items as $it) {
                    $insItem->execute([$invId, $it['src_type'], $it['src_id'], $it['src_no'],
                                       $it['src_date'], $it['product_id'], $it['spec'],
                                       $it['qty'], $it['unit_price'], $it['amount']]);
                }
                $made[] = ['invoice_id' => $invId, 'customer' => $name, 'billing_month' => $bm,
                           'net' => $net, 'tax' => $tax, 'total' => $net + $tax,
                           'item_cnt' => count($items)];
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立發票失敗：' . $e->getMessage()];
    }

    $t = 0.0;
    foreach ($made as $m) $t += $m['total'];
    return ['success' => true, 'created' => count($made), 'invoices' => $made, 'errors' => $errors,
            'message' => '已建立 ' . count($made) . ' 張發票，含稅合計 ' . number_format($t)];
}

/** 發票清單 */
function acc_invoice_list(PDO $db, array $f): array
{
    acc_ensure_schema($db);
    $where = []; $params = [];

    $bmFrom = trim((string)($f['bm_from'] ?? ''));
    $bmTo   = trim((string)($f['bm_to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}$/', $bmFrom)) { $where[] = "i.billing_month >= ?"; $params[] = $bmFrom; }
    if (preg_match('/^\d{4}-\d{2}$/', $bmTo))   { $where[] = "i.billing_month <= ?"; $params[] = $bmTo; }

    $status = trim((string)($f['status'] ?? ''));
    if ($status !== '' && $status !== 'all') { $where[] = "i.status = ?"; $params[] = $status; }
    $docType = trim((string)($f['doc_type'] ?? ''));
    if ($docType !== '' && $docType !== 'all') { $where[] = "i.doc_type = ?"; $params[] = $docType; }

    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(i.customer_name LIKE ? OR i.customer_full LIKE ? OR i.invoice_no LIKE ?
                     OR i.tax_id LIKE ? OR i.customer_id LIKE ?)";
        for ($k = 0; $k < 5; $k++) $params[] = '%' . $kw . '%';
    }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT i.*,
                   (SELECT COUNT(*) FROM acc_invoice_item x WHERE x.invoice_id = i.invoice_id) AS item_cnt,
                   COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                             WHERE a.invoice_id = i.invoice_id), 0) AS paid_amt
            FROM acc_invoice i
            $ws
            ORDER BY i.billing_month DESC, i.customer_name, i.invoice_id DESC";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $today = new DateTime(date('Y-m-d'));
    foreach ($rows as &$r) {
        $r['sales_amount'] = (float)$r['sales_amount'];
        $r['tax_amount']   = (float)$r['tax_amount'];
        $r['total_amount'] = (float)$r['total_amount'];
        $r['paid_amt']     = (float)$r['paid_amt'];
        $r['open_amt']     = round($r['total_amount'] - $r['paid_amt'], 2);
        $r['item_cnt']     = (int)$r['item_cnt'];
        // 帳齡：以發票日起算；未開立者不算帳齡
        $r['age_days'] = null;
        if (!empty($r['invoice_date']) && $r['status'] === 'issued' && $r['open_amt'] > 0.005) {
            $r['age_days'] = (int)$today->diff(new DateTime($r['invoice_date']))->days;
        }
        $r['age_bucket'] = ($r['age_days'] === null) ? ''
            : ($r['age_days'] <= 30 ? '0-30' : ($r['age_days'] <= 60 ? '31-60'
              : ($r['age_days'] <= 90 ? '61-90' : '90+')));
    }
    unset($r);

    if (!empty($f['only_open'])) {
        $rows = array_values(array_filter($rows, fn($r) => $r['open_amt'] > 0.005 && $r['status'] === 'issued'));
    }

    $summary = ['count' => count($rows), 'total_amt' => 0.0, 'paid_amt' => 0.0, 'open_amt' => 0.0,
                'draft' => 0, 'exported' => 0, 'issued' => 0, 'void' => 0,
                'age' => ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0]];
    foreach ($rows as $r) {
        if ($r['status'] === 'void') { $summary['void']++; continue; }
        $sign = ($r['doc_type'] === 'ALLOWANCE') ? -1 : 1;
        $summary['total_amt'] += $r['total_amount'] * $sign;
        $summary['paid_amt']  += $r['paid_amt'];
        $summary['open_amt']  += $r['open_amt'] * $sign;
        if (isset($summary[$r['status']])) $summary[$r['status']]++;
        if ($r['age_bucket'] !== '') $summary['age'][$r['age_bucket']] += $r['open_amt'];
    }

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));

    return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'summary' => $summary];
}

/** 發票明細 */
function acc_invoice_items(PDO $db, int $invoiceId): array
{
    $st = $db->prepare("SELECT * FROM acc_invoice_item WHERE invoice_id = ? ORDER BY src_date, item_id");
    $st->execute([$invoiceId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 標記為已轉出（給外部平台開立），回傳批次編號 */
function acc_invoice_mark_exported(PDO $db, array $ids, string $userId): array
{
    $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
    if (!$ids) return ['success' => false, 'message' => '沒有選取發票'];
    $batch = 'EXP' . date('YmdHis');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $db->beginTransaction();
        $st = $db->prepare("UPDATE acc_invoice
                            SET status='exported', export_batch=?, exported_at=NOW(),
                                Modified_By=?, Modified_At=NOW()
                            WHERE invoice_id IN ($ph) AND status='draft'");
        $st->execute(array_merge([$batch, $userId], $ids));
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'batch' => $batch, 'updated' => $n,
                'message' => "已標記 {$n} 張為待開立（批次 {$batch}）"];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '標記失敗：' . $e->getMessage()];
    }
}

/**
 * 回填實際開立的發票號碼與日期（手動逐張或 CSV 批次共用同一支）。
 * @param array $pairs [{invoice_id 或 match_key, invoice_no, invoice_date}]
 */
function acc_invoice_backfill(PDO $db, array $pairs, string $userId): array
{
    $ok = 0; $skip = 0; $errors = [];
    try {
        $db->beginTransaction();
        $up = $db->prepare("UPDATE acc_invoice
                            SET invoice_no=?, invoice_date=?, status='issued',
                                Modified_By=?, Modified_At=NOW()
                            WHERE invoice_id=? AND status IN ('draft','exported')");
        foreach ($pairs as $p) {
            $id = (int)($p['invoice_id'] ?? 0);
            $no = strtoupper(trim((string)($p['invoice_no'] ?? '')));
            $dt = trim((string)($p['invoice_date'] ?? ''));
            if ($id <= 0 || $no === '') { $skip++; continue; }
            // 台灣電子發票號碼格式：2 英文字母 + 8 數字
            if (!preg_match('/^[A-Z]{2}\d{8}$/', $no)) {
                $errors[] = "發票號碼 {$no} 格式不正確（應為 2 個英文字母＋8 位數字），已略過";
                $skip++; continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                $errors[] = "發票 {$no} 的開立日期格式不正確，已略過";
                $skip++; continue;
            }
            try {
                $up->execute([$no, $dt, $userId, $id]);
                if ($up->rowCount() > 0) $ok++; else $skip++;
            } catch (PDOException $e) {
                // uq_inv_no 撞號
                $errors[] = "發票號碼 {$no} 已存在於系統，已略過";
                $skip++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '回填失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'applied' => $ok, 'skipped' => $skip, 'errors' => $errors,
            'message' => "已回填 {$ok} 張發票號碼" . ($skip ? "，略過 {$skip} 張" : '')];
}

/** 作廢發票（憑證會釋放回待開立清單；已沖帳者不可作廢） */
function acc_invoice_void(PDO $db, int $invoiceId, string $reason, string $userId): array
{
    if ($invoiceId <= 0) return ['success' => false, 'message' => '缺少發票'];
    $paid = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM acc_receipt_alloc
                               WHERE invoice_id = " . (int)$invoiceId)->fetchColumn();
    if ($paid > 0.005) {
        return ['success' => false, 'message' => '此發票已有收款沖帳紀錄（' . number_format($paid)
                . ' 元），請先取消沖帳再作廢'];
    }
    try {
        $db->beginTransaction();
        $st = $db->prepare("UPDATE acc_invoice SET status='void', void_reason=?,
                            Modified_By=?, Modified_At=NOW()
                            WHERE invoice_id=? AND status<>'void'");
        $st->execute([mb_substr($reason, 0, 200), $userId, $invoiceId]);
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'updated' => $n,
                'message' => $n ? '已作廢，該發票的出貨憑證已釋放回待開立清單' : '此發票已是作廢狀態'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '作廢失敗：' . $e->getMessage()];
    }
}

/**
 * 建立銷貨折讓證明單：處理「發票已開立之後才發生的退貨」。
 * 同期退貨會直接在發票中扣除，不需要折讓單；只有跨期（發票已開）才需要。
 *
 * @param int   $refInvoiceId 原發票
 * @param array $irIds        要折讓的 ir_track.IR_id
 */
function acc_create_allowance(PDO $db, int $refInvoiceId, array $irIds, string $userId): array
{
    acc_ensure_schema($db);
    $irIds = array_values(array_unique(array_map('intval', array_filter($irIds))));
    if ($refInvoiceId <= 0 || !$irIds) return ['success' => false, 'message' => '缺少原發票或退貨明細'];

    $st = $db->prepare("SELECT * FROM acc_invoice WHERE invoice_id=? AND doc_type='INVOICE' LIMIT 1");
    $st->execute([$refInvoiceId]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv)                       return ['success' => false, 'message' => '找不到原發票'];
    if ($inv['status'] === 'void')   return ['success' => false, 'message' => '原發票已作廢，不可開折讓單'];
    if ($inv['status'] !== 'issued') return ['success' => false, 'message' => '原發票尚未開立（狀態 ' . $inv['status'] . '），同期退貨請直接在發票中扣除'];

    $used = acc_invoiced_src_map($db);
    $ph   = implode(',', array_fill(0, count($irIds), '?'));
    $sti  = $db->prepare("SELECT it.IR_id, it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d,
                                 it.Client_name, it.d_id, it.Specification, it.Qty, it.Unit_price
                          FROM ir_track it WHERE it.IR_id IN ($ph)");
    $sti->execute($irIds);
    $irs = $sti->fetchAll(PDO::FETCH_ASSOC);
    if (!$irs) return ['success' => false, 'message' => '找不到退貨資料'];

    $items = []; $net = 0.0; $errors = [];
    foreach ($irs as $r) {
        if (trim((string)$r['Client_name']) !== trim((string)$inv['customer_name'])) {
            $errors[] = "退貨單 {$r['IR_no']} 的客戶與原發票不符，已略過"; continue;
        }
        if (isset($used['IR-' . (int)$r['IR_id']])) {
            $u = $used['IR-' . (int)$r['IR_id']];
            $what = ($u['doc_type'] === 'ALLOWANCE') ? '折讓單' : '發票';
            $errors[] = "退貨單 {$r['IR_no']} 已折讓或已入帳（{$what} "
                      . ($u['invoice_no'] ?: '#' . $u['invoice_id']) . "），已略過";
            continue;
        }
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) { $errors[] = "退貨單 {$r['IR_no']} 金額為 0，已略過"; continue; }
        $items[] = ['src_id' => (int)$r['IR_id'], 'src_no' => $r['IR_no'], 'src_date' => $r['d'],
                    'product_id' => $r['d_id'], 'spec' => $r['Specification'],
                    'qty' => -(int)$r['Qty'], 'unit_price' => (float)$r['Unit_price'], 'amount' => $amt];
        $net += $amt;
    }
    if (!$items) return ['success' => false, 'message' => '沒有可折讓的退貨明細', 'errors' => $errors];

    $rate = (float)$inv['tax_rate'];
    $tax  = round($net * $rate);
    try {
        $db->beginTransaction();
        $ins = $db->prepare(
            "INSERT INTO acc_invoice
             (doc_type, customer_id, customer_name, customer_full, tax_id, billing_month,
              tax_type, tax_rate, sales_amount, tax_amount, total_amount, status,
              ref_invoice_id, note, Created_By)
             VALUES ('ALLOWANCE',?,?,?,?,?,?,?,?,?,?,'draft',?,?,?)");
        $ins->execute([$inv['customer_id'], $inv['customer_name'], $inv['customer_full'], $inv['tax_id'],
                       date('Y-m'), (int)$inv['tax_type'], $rate, $net, $tax, $net + $tax,
                       $refInvoiceId, '對應原發票 ' . ($inv['invoice_no'] ?: $refInvoiceId), $userId]);
        $aid = (int)$db->lastInsertId();
        $insItem = $db->prepare(
            "INSERT INTO acc_invoice_item
             (invoice_id, src_type, src_id, src_no, src_date, product_id, spec, qty, unit_price, amount)
             VALUES (?,'IR',?,?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $insItem->execute([$aid, $it['src_id'], $it['src_no'], $it['src_date'],
                               $it['product_id'], $it['spec'], $it['qty'], $it['unit_price'], $it['amount']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立折讓單失敗：' . $e->getMessage()];
    }

    return ['success' => true, 'allowance_id' => $aid, 'net' => $net, 'tax' => $tax,
            'total' => $net + $tax, 'item_cnt' => count($items), 'errors' => $errors,
            'message' => '已建立折讓單，折讓未稅 ' . number_format($net) . '、含稅 ' . number_format($net + $tax)];
}

/** 某客戶已開立、仍可開折讓的發票（供折讓單選原發票用） */
function acc_allowance_targets(PDO $db, string $clientName): array
{
    acc_ensure_schema($db);
    $st = $db->prepare("SELECT invoice_id, invoice_no, invoice_date, billing_month,
                               sales_amount, tax_amount, total_amount
                        FROM acc_invoice
                        WHERE doc_type='INVOICE' AND status='issued' AND customer_name=?
                        ORDER BY invoice_date DESC, invoice_id DESC LIMIT 100");
    $st->execute([$clientName]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 某客戶尚未被任何發票／折讓單使用的退貨（供折讓單選明細用） */
function acc_uninvoiced_returns(PDO $db, string $clientName): array
{
    $used = acc_invoiced_src_map($db);
    // 折讓單本身也會佔用 IR，需一併排除
    $st0 = $db->query("SELECT ii.src_id FROM acc_invoice_item ii
                       JOIN acc_invoice i ON i.invoice_id = ii.invoice_id
                       WHERE ii.src_type='IR' AND i.status <> 'void'");
    foreach ($st0->fetchAll(PDO::FETCH_COLUMN) as $sid) $used['IR-' . (int)$sid] = ['x' => 1];

    $st = $db->prepare("SELECT it.IR_id, it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d,
                               it.d_id, it.Specification, it.Qty, it.Unit_price, it.IR_ps,
                               COALESCE(rt.is_note,0) AS is_note
                        FROM ir_track it
                        LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
                        WHERE it.Client_name=? ORDER BY it.IR_date DESC LIMIT 300");
    $st->execute([$clientName]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['is_note'] === 1) continue;
        if (isset($used['IR-' . (int)$r['IR_id']])) continue;
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $r['amount'] = $amt;
        $out[] = $r;
    }
    return $out;
}

/* ============================================================
 * 收款與沖帳
 *
 * 彈性設計（使用者指定）：一筆收款可沖多張發票、一張發票可被多筆收款分次沖，
 * 支援部分收款與尾款。折讓單以負數參與未收餘額計算（等於減少應收）。
 * ============================================================ */

/** 收款單號：RC + 民國年(3) + MMDD + 序號(3) */
function acc_receipt_next_no(PDO $db, string $date): string
{
    $y      = (int)substr($date, 0, 4) - 1911;
    $prefix = 'RC' . str_pad((string)$y, 3, '0', STR_PAD_LEFT) . substr($date, 5, 2) . substr($date, 8, 2);
    $st = $db->prepare("SELECT MAX(CAST(SUBSTRING(receipt_no, 10) AS UNSIGNED)) FROM acc_receipt WHERE receipt_no LIKE ?");
    $st->execute([$prefix . '%']);
    return $prefix . str_pad((string)((int)$st->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
}

/** 某張發票目前的未收餘額（扣掉所有收款單已沖金額） */
function acc_invoice_open(PDO $db, int $invoiceId): array
{
    $st = $db->prepare("SELECT i.invoice_id, i.invoice_no, i.doc_type, i.status, i.customer_name,
                               i.billing_month, i.total_amount,
                               COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                                         WHERE a.invoice_id = i.invoice_id), 0) AS paid
                        FROM acc_invoice i WHERE i.invoice_id = ? LIMIT 1");
    $st->execute([$invoiceId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return [];
    $r['total_amount'] = (float)$r['total_amount'];
    $r['paid']         = (float)$r['paid'];
    $r['open']         = round($r['total_amount'] - $r['paid'], 2);
    return $r;
}

/** 某客戶尚未收完的已開立發票（供沖帳挑選；折讓單一併列出，金額為負） */
function acc_open_invoices(PDO $db, string $clientName, ?int $includeReceiptId = null): array
{
    acc_ensure_schema($db);
    $st = $db->prepare("
        SELECT i.invoice_id, i.doc_type, i.invoice_no, i.invoice_date, i.billing_month,
               i.total_amount, i.customer_name,
               COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                         WHERE a.invoice_id = i.invoice_id), 0) AS paid,
               COALESCE((SELECT SUM(a2.amount) FROM acc_receipt_alloc a2
                         WHERE a2.invoice_id = i.invoice_id AND a2.receipt_id = ?), 0) AS this_paid
        FROM acc_invoice i
        WHERE i.customer_name = ? AND i.status = 'issued'
        ORDER BY i.invoice_date, i.invoice_id");
    $st->execute([$includeReceiptId ?? 0, $clientName]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sign  = ($r['doc_type'] === 'ALLOWANCE') ? -1 : 1;
        $total = (float)$r['total_amount'] * $sign;
        $paid  = (float)$r['paid'];
        $open  = round($total - $paid, 2);
        // 編輯中的收款單：把它自己已沖的金額算回可沖額度，否則會沖不回去
        $r['total_amount'] = $total;
        $r['paid']         = $paid;
        $r['this_paid']    = (float)$r['this_paid'];
        $r['open']         = $open;
        $r['available']    = round($open + $r['this_paid'], 2);
        if (abs($r['available']) < 0.005) continue;
        $out[] = $r;
    }
    return $out;
}

/** 建立或更新收款單（不含沖帳明細，沖帳走 acc_alloc_save） */
function acc_receipt_save(PDO $db, array $d, string $userId): array
{
    acc_ensure_schema($db);
    $id   = (int)($d['receipt_id'] ?? 0);
    $name = trim((string)($d['customer_name'] ?? ''));
    $date = trim((string)($d['receipt_date'] ?? ''));
    $amt  = (float)($d['amount'] ?? 0);

    if ($name === '')                                  return ['success' => false, 'message' => '請填客戶'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))   return ['success' => false, 'message' => '入帳日格式錯誤'];
    if ($amt <= 0)                                     return ['success' => false, 'message' => '收款金額必須大於 0'];

    $cid = trim((string)($d['customer_id'] ?? ''));
    if ($cid === '') {
        $stc = $db->prepare("SELECT customer_id FROM customer_list WHERE customer = ? LIMIT 1");
        $stc->execute([$name]);
        $cid = (string)($stc->fetchColumn() ?: '');
    }

    $method  = trim((string)($d['method'] ?? '匯款'));
    $fee     = (float)($d['fee'] ?? 0);
    $bank    = trim((string)($d['bank'] ?? ''));
    $ckNo    = trim((string)($d['check_no'] ?? ''));
    $ckDue   = trim((string)($d['check_due'] ?? ''));
    $note    = trim((string)($d['note'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ckDue)) $ckDue = null;

    try {
        $db->beginTransaction();
        if ($id > 0) {
            // 縮小金額時不可小於已沖總額
            $alloc = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM acc_receipt_alloc
                                        WHERE receipt_id = " . (int)$id)->fetchColumn();
            if ($amt < $alloc - 0.005) {
                $db->rollBack();
                return ['success' => false, 'message' => '收款金額 ' . number_format($amt)
                        . ' 小於已沖帳金額 ' . number_format($alloc) . '，請先調整沖帳明細'];
            }
            $st = $db->prepare("UPDATE acc_receipt SET customer_id=?, customer_name=?, receipt_date=?,
                                method=?, amount=?, fee=?, bank=?, check_no=?, check_due=?, note=?,
                                Modified_By=?, Modified_At=NOW() WHERE receipt_id=?");
            $st->execute([$cid ?: null, $name, $date, $method, $amt, $fee, $bank ?: null,
                          $ckNo ?: null, $ckDue, $note ?: null, $userId, $id]);
            $db->commit();
            return ['success' => true, 'receipt_id' => $id, 'message' => '已更新收款單'];
        }

        $no = acc_receipt_next_no($db, $date);
        $st = $db->prepare("INSERT INTO acc_receipt
                            (receipt_no, customer_id, customer_name, receipt_date, method, amount,
                             fee, bank, check_no, check_due, note, Created_By)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$no, $cid ?: null, $name, $date, $method, $amt, $fee, $bank ?: null,
                      $ckNo ?: null, $ckDue, $note ?: null, $userId]);
        $newId = (int)$db->lastInsertId();
        $db->commit();
        return ['success' => true, 'receipt_id' => $newId, 'receipt_no' => $no,
                'message' => '已建立收款單 ' . $no];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '存檔失敗：' . $e->getMessage()];
    }
}

/**
 * 寫入沖帳明細（整批取代該收款單原有的沖帳）。
 * 三道檢查：沖帳總額 ≤ 收款金額、每張發票不可超沖、發票客戶需與收款單一致。
 */
function acc_alloc_save(PDO $db, int $receiptId, array $allocs, string $userId): array
{
    acc_ensure_schema($db);
    if ($receiptId <= 0) return ['success' => false, 'message' => '缺少收款單'];

    $st = $db->prepare("SELECT * FROM acc_receipt WHERE receipt_id=? LIMIT 1");
    $st->execute([$receiptId]);
    $rc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rc) return ['success' => false, 'message' => '找不到收款單'];

    // 整理輸入
    $clean = [];
    $sum   = 0.0;
    foreach ($allocs as $a) {
        $iid = (int)($a['invoice_id'] ?? 0);
        $amt = round((float)($a['amount'] ?? 0), 2);
        if ($iid <= 0 || abs($amt) < 0.005) continue;
        if (isset($clean[$iid])) $clean[$iid] += $amt; else $clean[$iid] = $amt;
    }
    foreach ($clean as $amt) $sum += $amt;

    if ($sum > (float)$rc['amount'] + 0.005) {
        return ['success' => false, 'message' => '沖帳總額 ' . number_format($sum)
                . ' 超過收款金額 ' . number_format((float)$rc['amount'])];
    }

    try {
        $db->beginTransaction();
        // 先刪掉本收款單原有沖帳，再重寫（額度計算才不會把自己算進去）
        $db->prepare("DELETE FROM acc_receipt_alloc WHERE receipt_id=?")->execute([$receiptId]);

        $ins = $db->prepare("INSERT INTO acc_receipt_alloc (receipt_id, invoice_id, amount, Created_By)
                             VALUES (?,?,?,?)");
        foreach ($clean as $iid => $amt) {
            $inv = acc_invoice_open($db, $iid);
            if (!$inv) { $db->rollBack(); return ['success' => false, 'message' => "發票 #{$iid} 不存在"]; }
            if ($inv['status'] !== 'issued') {
                $db->rollBack();
                return ['success' => false, 'message' => '發票 ' . ($inv['invoice_no'] ?: '#' . $iid)
                        . ' 尚未開立或已作廢，不可沖帳'];
            }
            if (trim((string)$inv['customer_name']) !== trim((string)$rc['customer_name'])) {
                $db->rollBack();
                return ['success' => false, 'message' => '發票 ' . ($inv['invoice_no'] ?: '#' . $iid)
                        . ' 的客戶（' . $inv['customer_name'] . '）與收款單（' . $rc['customer_name'] . '）不符'];
            }
            $sign = ($inv['doc_type'] === 'ALLOWANCE') ? -1 : 1;
            $open = round((float)$inv['total_amount'] * $sign - (float)$inv['paid'], 2);
            if ($sign > 0 && $amt > $open + 0.005) {
                $db->rollBack();
                return ['success' => false, 'message' => '發票 ' . ($inv['invoice_no'] ?: '#' . $iid)
                        . ' 未收餘額只有 ' . number_format($open) . '，不可沖 ' . number_format($amt)];
            }
            if ($sign < 0 && $amt < $open - 0.005) {
                $db->rollBack();
                return ['success' => false, 'message' => '折讓單 ' . ($inv['invoice_no'] ?: '#' . $iid)
                        . ' 可沖額度只有 ' . number_format($open)];
            }
            $ins->execute([$receiptId, $iid, $amt, $userId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '沖帳失敗：' . $e->getMessage()];
    }

    $left = round((float)$rc['amount'] - $sum, 2);
    return ['success' => true, 'allocated' => $sum, 'unallocated' => $left,
            'count' => count($clean),
            'message' => '已沖帳 ' . count($clean) . ' 張發票、合計 ' . number_format($sum)
                       . ($left > 0.005 ? '，尚有 ' . number_format($left) . ' 元未分配（暫收款）' : '')];
}

/** 收款單清單（含已沖／未分配金額） */
function acc_receipt_list(PDO $db, array $f): array
{
    acc_ensure_schema($db);
    $where = []; $params = [];
    if (!empty($f['date_from'])) { $where[] = "r.receipt_date >= ?"; $params[] = $f['date_from']; }
    if (!empty($f['date_to']))   { $where[] = "r.receipt_date <= ?"; $params[] = $f['date_to']; }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(r.receipt_no LIKE ? OR r.customer_name LIKE ? OR r.bank LIKE ? OR r.check_no LIKE ?)";
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $kw . '%';
    }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $db->prepare("SELECT r.*,
                          COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                                    WHERE a.receipt_id = r.receipt_id), 0) AS allocated,
                          (SELECT COUNT(*) FROM acc_receipt_alloc a2
                           WHERE a2.receipt_id = r.receipt_id) AS alloc_cnt
                        FROM acc_receipt r
                        $ws
                        ORDER BY r.receipt_date DESC, r.receipt_id DESC");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['amount']      = (float)$r['amount'];
        $r['fee']         = (float)$r['fee'];
        $r['allocated']   = (float)$r['allocated'];
        $r['alloc_cnt']   = (int)$r['alloc_cnt'];
        $r['unallocated'] = round($r['amount'] - $r['allocated'], 2);
    }
    unset($r);

    if (!empty($f['only_unalloc'])) {
        $rows = array_values(array_filter($rows, fn($r) => $r['unallocated'] > 0.005));
    }

    $summary = ['count' => count($rows), 'amount' => 0.0, 'allocated' => 0.0, 'unallocated' => 0.0, 'fee' => 0.0];
    foreach ($rows as $r) {
        $summary['amount']      += $r['amount'];
        $summary['allocated']   += $r['allocated'];
        $summary['unallocated'] += $r['unallocated'];
        $summary['fee']         += $r['fee'];
    }

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));
    return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'summary' => $summary];
}

/** 某收款單的沖帳明細 */
function acc_receipt_allocs(PDO $db, int $receiptId): array
{
    $st = $db->prepare("SELECT a.alloc_id, a.invoice_id, a.amount,
                               i.invoice_no, i.invoice_date, i.doc_type, i.billing_month, i.total_amount
                        FROM acc_receipt_alloc a
                        JOIN acc_invoice i ON i.invoice_id = a.invoice_id
                        WHERE a.receipt_id = ?
                        ORDER BY i.invoice_date, a.alloc_id");
    $st->execute([$receiptId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 刪除收款單（連同沖帳明細） */
function acc_receipt_delete(PDO $db, int $receiptId, string $userId): array
{
    if ($receiptId <= 0) return ['success' => false, 'message' => '缺少收款單'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM acc_receipt_alloc WHERE receipt_id=?")->execute([$receiptId]);
        $st = $db->prepare("DELETE FROM acc_receipt WHERE receipt_id=?");
        $st->execute([$receiptId]);
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'deleted' => $n,
                'message' => $n ? '已刪除收款單與其沖帳明細' : '找不到該收款單'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '刪除失敗：' . $e->getMessage()];
    }
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
