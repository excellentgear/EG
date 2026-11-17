<?php
/**
 * acc_allowance_lib.php — 折讓單（views/ACC/allowance.php）唯一實作
 * 建立：2026-11-17（使用者交辦）
 *
 * 一張折讓單＝一個客戶、一組「出貨單明細／退貨單明細」的折讓（可混合綁定、多筆）。
 *  - 折讓數量／單價預設帶來源單據，可修改（部分折讓）；金額＝數量×單價、營業稅逐筆四捨五入後加總。
 *  - 證明單號碼（電子發票平台給的）由會計手填；格式範本管理員可改，只影響「之後」建立的建議編號，
 *    已存的單號是定稿字串、不隨設定改變。
 *  - 帳款月份＝折讓日期依該客戶結帳日算出（acc_bm_for），對帳進度追蹤頁用它判斷「這個月要對這筆折讓」。
 *  - 與舊的 acc_invoice(doc_type='ALLOWANCE')（發票已開後的退貨折讓）是兩套獨立資料，互不影響。
 * 權限沿用 acc_perms()（canView 看、canEdit 建立／填號／作廢、canAdmin 改編號格式）。
 */
require_once __DIR__ . '/acc_lib.php';
require_once __DIR__ . '/attach_lib.php';
require_once __DIR__ . '/role_features_helper.php';

if (!defined('ACA_MODULE')) define('ACA_MODULE', 'acc_allowance');
/* 折讓單自己的角色（roles.module='acc_allowance'；role_code 全站唯一）。全站管理員固定擁有全部權限，不需指派 */
if (!defined('ACA_ROLES')) {
    define('ACA_ROLES', [
        'aal_admin' => '折讓單-本頁管理員（可修改設定）',
        'aal_acc'   => '折讓單-會計（可開立、填號、附件、作廢；不可更動設定）',
        'aal_view'  => '折讓單-唯讀',
    ]);
}

/** 可重複執行；只在角色不存在時建立，事後改名或改勾選不會被還原 */
function aca_ensure_roles(PDO $db): void
{
    try {
        foreach (ACA_ROLES as $code => $name) {
            $st = $db->prepare("SELECT role_id FROM roles WHERE role_code=? LIMIT 1");
            $st->execute([$code]);
            if (!$st->fetchColumn()) {
                $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?,?)")->execute([$code, $name, ACA_MODULE]);
                $db->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?,?)")->execute([(int)$db->lastInsertId(), $code]);
            }
        }
    } catch (Throwable $e) {}
}

/** 折讓單權限：全站管理員／本頁管理員(可改設定)／會計(可開立、不可改設定)／唯讀。鍵名與 acc_perms() 相容 */
function aca_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canEdit' => false, 'canView' => false];
    $uid = (int)$u['id'];
    $feat = function_exists('rf_load_user_features_all') ? rf_load_user_features_all($db, $uid) : [];
    $isAdmin = acc_perms($db, $u)['isAdmin'] || in_array('all', $feat, true);
    $canAdmin = $isAdmin || in_array('aal_admin', $feat, true);
    $canEdit  = $canAdmin || in_array('aal_acc', $feat, true);
    $canView  = $canEdit  || in_array('aal_view', $feat, true);
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canEdit' => $canEdit, 'canView' => $canView];
}

if (!defined('ACA_PARAM_GROUP')) define('ACA_PARAM_GROUP', 'ACC_ALLOWANCE');
if (!defined('ACA_DEFAULT_FMT')) define('ACA_DEFAULT_FMT', '{CID}-{YYYY}{MM}-{CM_SEQ}');

if (!function_exists('aca_ensure_schema')) {
function aca_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    aca_ensure_roles($db);
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS acc_allowance (
            allow_id      INT AUTO_INCREMENT PRIMARY KEY,
            allow_no      VARCHAR(60) NULL COMMENT '折讓證明單號碼（電子發票平台提供，會計填入）',
            allow_date    DATE NOT NULL COMMENT '折讓日期（業務日期）',
            billing_month CHAR(7) NOT NULL COMMENT '依客戶結帳日算出的帳款月份，對帳進度追蹤用',
            customer_id   VARCHAR(20) NOT NULL,
            customer_name VARCHAR(100) NULL,
            tax_rate      DECIMAL(6,4) NOT NULL DEFAULT 0.05,
            sales_amount  DECIMAL(16,2) NOT NULL DEFAULT 0,
            tax_amount    DECIMAL(16,2) NOT NULL DEFAULT 0,
            total_amount  DECIMAL(16,2) NOT NULL DEFAULT 0,
            seq_cm        INT NOT NULL DEFAULT 0 COMMENT '該客戶該月流水號',
            seq_m         INT NOT NULL DEFAULT 0 COMMENT '該月全部客戶流水號',
            seq_y         INT NOT NULL DEFAULT 0 COMMENT '該年度全部客戶流水號',
            status        ENUM('active','void') NOT NULL DEFAULT 'active',
            note          VARCHAR(500) NULL,
            void_reason   VARCHAR(200) NULL,
            voided_by_name VARCHAR(50) NULL,
            voided_at     DATETIME NULL,
            created_by    INT NULL,
            created_by_name VARCHAR(50) NULL,
            created_at    DATETIME NULL,
            modified_by_name VARCHAR(50) NULL,
            modified_at   DATETIME NULL,
            KEY idx_cust_bm (customer_id, billing_month),
            KEY idx_bm (billing_month),
            KEY idx_no (allow_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='折讓單表頭'");
        $db->exec("CREATE TABLE IF NOT EXISTS acc_allowance_item (
            item_id     INT AUTO_INCREMENT PRIMARY KEY,
            allow_id    INT NOT NULL,
            src_type    ENUM('IS','IR') NOT NULL COMMENT 'IS=出貨單明細 IR=退貨單明細',
            src_id      INT NOT NULL,
            src_no      VARCHAR(40) NULL,
            src_date    DATE NULL,
            part_no     VARCHAR(80) NULL,
            spec        VARCHAR(255) NULL,
            src_qty     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '來源單據原數量',
            src_price   DECIMAL(16,4) NOT NULL DEFAULT 0 COMMENT '來源單據原單價',
            qty         DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '本次折讓數量',
            unit_price  DECIMAL(16,4) NOT NULL DEFAULT 0 COMMENT '本次折讓單價',
            amount      DECIMAL(16,2) NOT NULL DEFAULT 0,
            tax_amount  DECIMAL(16,2) NOT NULL DEFAULT 0,
            KEY idx_allow (allow_id),
            KEY idx_src (src_type, src_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='折讓單明細（綁定出貨／退貨單）'");
        $db->exec("CREATE TABLE IF NOT EXISTS acc_allowance_file (
            file_id     INT AUTO_INCREMENT PRIMARY KEY,
            allow_id    INT NOT NULL,
            file_name   VARCHAR(200) NOT NULL COMMENT '實際存放的檔名（相對於附件資料夾）',
            orig_name   VARCHAR(255) NOT NULL,
            file_size   INT NOT NULL DEFAULT 0,
            uploaded_by_name VARCHAR(50) NULL,
            uploaded_at DATETIME NULL,
            KEY idx_allow (allow_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='折讓單附件（只存檔名，路徑即時組）'");
    } catch (Throwable $e) { error_log('aca_ensure_schema: ' . $e->getMessage()); }
}}

/* ── 證明單號碼格式 ───────────────────────────────────────────── */
function aca_param_get(PDO $db, string $key, $default)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([ACA_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}
function aca_format_get(PDO $db): string { return aca_param_get($db, 'no_format', ACA_DEFAULT_FMT); }

/** 可用代碼（供設定畫面顯示）；SEQ 類可加「:位數」固定補零，例 {CM_SEQ:2}＝01 */
function aca_format_tokens(): array
{
    return [
        ['{CID}',    '客戶ID（客戶編號）'],
        ['{YYYY}',   '折讓日期的西元年'],
        ['{MM}',     '折讓日期的月份（2 碼）'],
        ['{DD}',     '折讓日期的日（2 碼）'],
        ['{CM_SEQ}', '此客戶本月的流水號（不訂位數＝1、2、3…；{CM_SEQ:2}＝01、02…）'],
        ['{M_SEQ}',  '不限客戶的本月流水號（{M_SEQ:3}＝001…）'],
        ['{Y_SEQ}',  '不限客戶的年度流水號（{Y_SEQ:4}＝0001…）'],
    ];
}

/** 驗證格式字串：只能含已知代碼，且至少要有一個代碼 */
function aca_format_check(string $fmt): ?string
{
    $fmt = trim($fmt);
    if ($fmt === '') return '格式不可空白';
    if (mb_strlen($fmt) > 60) return '格式過長（上限 60 字）';
    if (!preg_match_all('/\{([A-Z_]+)(?::(\d{1,2}))?\}/', $fmt, $m)) return '格式至少要有一個代碼，例如 {CID}';
    $ok = ['CID', 'YYYY', 'MM', 'DD', 'CM_SEQ', 'M_SEQ', 'Y_SEQ'];
    foreach ($m[1] as $i => $t) {
        if (!in_array($t, $ok, true)) return "不認得的代碼 {{$t}}";
        if ($m[2][$i] !== '' && !in_array($t, ['CM_SEQ', 'M_SEQ', 'Y_SEQ'], true)) return "{{$t}} 不能指定位數";
    }
    $rest = preg_replace('/\{[A-Z_]+(?::\d{1,2})?\}/', '', $fmt);
    if (strpos($rest, '{') !== false || strpos($rest, '}') !== false) return '大括號沒有成對或代碼寫錯';
    return null;
}

function aca_format_save(PDO $db, string $fmt, string $by): array
{
    $fmt = trim($fmt);
    if (($e = aca_format_check($fmt)) !== null) return ['success' => false, 'message' => $e];
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key='no_format' LIMIT 1");
    $st->execute([ACA_PARAM_GROUP]);
    if ($rid = $st->fetchColumn()) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$fmt, $by, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at)
                      VALUES (?,?,?,?,?,NOW())")->execute([ACA_PARAM_GROUP, 'no_format', $fmt, '折讓單證明單號碼建議格式', $by]);
    }
    return ['success' => true, 'format' => $fmt];
}

function aca_format_render(string $fmt, string $cid, string $date, int $cm, int $m, int $y): string
{
    $t = strtotime($date) ?: time();
    $seq = fn(int $n, string $w) => $w !== '' ? str_pad((string)$n, (int)$w, '0', STR_PAD_LEFT) : (string)$n;
    return preg_replace_callback('/\{([A-Z_]+)(?::(\d{1,2}))?\}/', function ($x) use ($cid, $t, $cm, $m, $y, $seq) {
        switch ($x[1]) {
            case 'CID':    return $cid;
            case 'YYYY':   return date('Y', $t);
            case 'MM':     return date('m', $t);
            case 'DD':     return date('d', $t);
            case 'CM_SEQ': return $seq($cm, $x[2] ?? '');
            case 'M_SEQ':  return $seq($m,  $x[2] ?? '');
            case 'Y_SEQ':  return $seq($y,  $x[2] ?? '');
        }
        return $x[0];
    }, $fmt);
}

/** 下一組流水號（以折讓日期的年月、客戶為桶；作廢的也算，編號不回收） */
function aca_next_seqs(PDO $db, string $cid, string $date, bool $lock = false): array
{
    $ym = substr($date, 0, 7); $y = substr($date, 0, 4);
    $suffix = $lock ? ' FOR UPDATE' : '';
    $q = function (string $sql, array $p) use ($db, $suffix) {
        $st = $db->prepare($sql . $suffix); $st->execute($p); return (int)$st->fetchColumn();
    };
    return [
        'cm' => $q("SELECT COALESCE(MAX(seq_cm),0)+1 FROM acc_allowance WHERE customer_id=? AND DATE_FORMAT(allow_date,'%Y-%m')=?", [$cid, $ym]),
        'm'  => $q("SELECT COALESCE(MAX(seq_m),0)+1  FROM acc_allowance WHERE DATE_FORMAT(allow_date,'%Y-%m')=?", [$ym]),
        'y'  => $q("SELECT COALESCE(MAX(seq_y),0)+1  FROM acc_allowance WHERE YEAR(allow_date)=?", [(int)$y]),
    ];
}

function aca_suggest_no(PDO $db, string $cid, string $date): string
{
    $s = aca_next_seqs($db, $cid, $date);
    return aca_format_render(aca_format_get($db), $cid, $date, $s['cm'], $s['m'], $s['y']);
}

/* ── 搜尋可折讓的出貨／退貨明細 ───────────────────────────────── */
/**
 * 篩選：customer（客戶名稱或客戶ID 關鍵字）、part（料號／規格關鍵字）、date_from/date_to、src（all|IS|IR）。
 * 回傳每列的「已折讓數量／尚可折讓數量」，已折讓完的仍列出但標 full=1（不可選）。
 */
function aca_search(PDO $db, array $f): array
{
    aca_ensure_schema($db);
    $custKw = trim((string)($f['customer'] ?? ''));
    $partKw = trim((string)($f['part'] ?? ''));
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['date_from'] ?? '')) ? $f['date_from'] : '';
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['date_to'] ?? '')) ? $f['date_to'] : '';
    $src  = in_array(($f['src'] ?? 'all'), ['IS', 'IR'], true) ? $f['src'] : 'all';
    $lim  = 300;

    $cmap = acc_customer_by_name($db);   // 簡稱(含別名) => 客戶列
    $names = null;
    if ($custKw !== '') {
        $names = [];
        foreach ($cmap as $nm => $c) {
            if (mb_stripos((string)$nm, $custKw) !== false || mb_stripos((string)$c['customer_id'], $custKw) !== false
                || mb_stripos((string)($c['customer_full'] ?? ''), $custKw) !== false) $names[] = (string)$nm;
        }
        if (!$names) return ['rows' => [], 'truncated' => false];
    }
    $words = $partKw === '' ? [] : preg_split('/\s+/u', $partKw);

    $rows = [];
    $build = function (string $table, string $type) use ($db, $names, $words, $from, $to, $lim, &$rows) {
        if ($type === 'IS') {
            $sql = "SELECT IS_id id, IS_number no, DATE_FORMAT(Order_date,'%Y-%m-%d') d, Client_name cn,
                           Product_id part, Specification spec, Qty, Unit_price FROM is_list WHERE 1=1";
            $dateCol = 'Order_date'; $partCols = ['Product_id', 'Specification', 'IS_number'];
        } else {
            $sql = "SELECT it.IR_id id, it.IR_no no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') d, it.Client_name cn,
                           it.d_id part, it.Specification spec, it.Qty, it.Unit_price
                    FROM ir_track it LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
                    WHERE COALESCE(rt.is_note,0)=0";
            $dateCol = 'it.IR_date'; $partCols = ['it.d_id', 'it.Specification', 'it.IR_no'];
        }
        $p = [];
        $cc = $type === 'IS' ? 'Client_name' : 'it.Client_name';
        if ($names !== null) { $sql .= " AND $cc IN (" . implode(',', array_fill(0, count($names), '?')) . ")"; $p = array_merge($p, $names); }
        foreach ($words as $w) {
            $sql .= ' AND (' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $partCols)) . ')';
            foreach ($partCols as $_) $p[] = '%' . $w . '%';
        }
        if ($from) { $sql .= " AND $dateCol >= ?"; $p[] = $from; }
        if ($to)   { $sql .= " AND $dateCol <= ?"; $p[] = $to; }
        $sql .= " ORDER BY $dateCol DESC LIMIT " . ($lim + 1);
        $st = $db->prepare($sql); $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['type'] = $type; $rows[] = $r; }
    };
    if ($src !== 'IR') $build('is_list', 'IS');
    if ($src !== 'IS') $build('ir_track', 'IR');

    $truncated = false;
    $isCnt = count(array_filter($rows, fn($r) => $r['type'] === 'IS'));
    $irCnt = count($rows) - $isCnt;
    if ($isCnt > $lim || $irCnt > $lim) $truncated = true;

    // 已折讓數量（未作廢）
    $used = [];
    $keys = array_map(fn($r) => $r['type'] . ':' . $r['id'], $rows);
    if ($rows) {
        $q = $db->query("SELECT i.src_type, i.src_id, SUM(i.qty) q, MAX(a.allow_no) no, COUNT(*) c
                         FROM acc_allowance_item i JOIN acc_allowance a ON a.allow_id=i.allow_id
                         WHERE a.status='active' GROUP BY i.src_type, i.src_id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) $used[$u['src_type'] . ':' . $u['src_id']] = $u;
    }
    $out = [];
    foreach ($rows as $r) {
        $nm = trim((string)$r['cn']);
        $c = $cmap[$nm] ?? null;
        $qty = (float)$r['Qty']; $price = (float)$r['Unit_price'];
        $k = $r['type'] . ':' . $r['id'];
        $u = (float)($used[$k]['q'] ?? 0);
        $out[] = [
            'type' => $r['type'], 'src_id' => (int)$r['id'], 'no' => $r['no'], 'date' => $r['d'],
            'client_name' => $nm, 'customer_id' => $c['customer_id'] ?? null, 'customer' => $c['customer'] ?? $nm,
            'in_master' => (bool)$c, 'part' => $r['part'], 'spec' => $r['spec'],
            'qty' => $qty, 'unit_price' => $price, 'amount' => round($qty * $price, 2),
            'used_qty' => $u, 'remain_qty' => max(0, $qty - $u), 'full' => ($qty - $u) <= 0.0001,
        ];
    }
    return ['rows' => $out, 'truncated' => $truncated];
}

/* ── 建立 ─────────────────────────────────────────────────────── */
/**
 * $d: customer_id, allow_date, tax_rate(選填), allow_no(選填), note, items[]{type,src_id,qty,unit_price}
 */
function aca_create(PDO $db, array $d, ?array $user): array
{
    aca_ensure_schema($db);
    $cid  = trim((string)($d['customer_id'] ?? ''));
    $date = (string)($d['allow_date'] ?? date('Y-m-d'));
    $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 500);
    $no   = trim((string)($d['allow_no'] ?? ''));
    if ($cid === '') return ['success' => false, 'message' => '請先選定客戶（請從搜尋結果勾選單據）'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['success' => false, 'message' => '折讓日期格式不正確'];
    if ($date > date('Y-m-d')) return ['success' => false, 'message' => '折讓日期不可晚於今天'];
    if (mb_strlen($no) > 60) return ['success' => false, 'message' => '證明單號碼過長'];
    $items = $d['items'] ?? [];
    if (!is_array($items) || !$items) return ['success' => false, 'message' => '請至少選一筆出貨單或退貨單明細'];
    if (count($items) > 100) return ['success' => false, 'message' => '一張折讓單最多 100 筆明細'];

    $rate = (isset($d['tax_rate']) && $d['tax_rate'] !== '') ? (float)$d['tax_rate'] : acc_tax_rate($db);
    if ($rate < 0 || $rate > 1) return ['success' => false, 'message' => '營業稅率必須介於 0～1（例如 0.05）'];

    $cmap = acc_customer_by_name($db);
    $cust = null;
    foreach ($cmap as $c) { if (trim((string)$c['customer_id']) === $cid) { $cust = $c; break; } }
    if (!$cust) return ['success' => false, 'message' => '客戶主檔查無此客戶編號（未建主檔的客戶請先到客戶主檔建立或對應）'];

    $seen = []; $lines = [];
    foreach ($items as $i => $it) {
        $type = ($it['type'] ?? '') === 'IR' ? 'IR' : (($it['type'] ?? '') === 'IS' ? 'IS' : '');
        $sid = (int)($it['src_id'] ?? 0);
        $n = $i + 1;
        if (!$type || $sid <= 0) return ['success' => false, 'message' => "第 {$n} 筆來源單據不正確"];
        if (isset($seen["$type$sid"])) return ['success' => false, 'message' => "第 {$n} 筆與前面重複選了同一筆單據"];
        $seen["$type$sid"] = 1;
        $qty = (float)($it['qty'] ?? 0); $price = (float)($it['unit_price'] ?? 0);
        if ($qty <= 0) return ['success' => false, 'message' => "第 {$n} 筆折讓數量必須大於 0"];
        if ($price < 0) return ['success' => false, 'message' => "第 {$n} 筆折讓單價不可為負數"];
        if ($type === 'IS') {
            $st = $db->prepare("SELECT IS_id id, IS_number no, DATE_FORMAT(Order_date,'%Y-%m-%d') d, Client_name cn,
                                       Product_id part, Specification spec, Qty, Unit_price FROM is_list WHERE IS_id=?");
        } else {
            $st = $db->prepare("SELECT IR_id id, IR_no no, DATE_FORMAT(IR_date,'%Y-%m-%d') d, Client_name cn,
                                       d_id part, Specification spec, Qty, Unit_price FROM ir_track WHERE IR_id=?");
        }
        $st->execute([$sid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['success' => false, 'message' => "第 {$n} 筆來源單據已不存在"];
        $c = $cmap[trim((string)$r['cn'])] ?? null;
        if (!$c || trim((string)$c['customer_id']) !== $cid) {
            return ['success' => false, 'message' => "第 {$n} 筆（{$r['no']}）屬於「{$r['cn']}」，與折讓單客戶不同；一張折讓單只能開給同一個客戶"];
        }
        $lines[] = ['type' => $type, 'r' => $r, 'qty' => $qty, 'price' => $price];
    }

    $net = 0.0; $taxSum = 0.0;
    try {
        $db->beginTransaction();
        // 逐筆鎖定來源已折讓量，避免兩個人同時對同一筆超折
        foreach ($lines as $k => &$ln) {
            $st = $db->prepare("SELECT COALESCE(SUM(i.qty),0) FROM acc_allowance_item i
                                JOIN acc_allowance a ON a.allow_id=i.allow_id AND a.status='active'
                                WHERE i.src_type=? AND i.src_id=? FOR UPDATE");
            $st->execute([$ln['type'], (int)$ln['r']['id']]);
            $used = (float)$st->fetchColumn();
            $remain = (float)$ln['r']['Qty'] - $used;
            if ($ln['qty'] - $remain > 0.0001) {
                $db->rollBack();
                return ['success' => false, 'message' => "{$ln['r']['no']} 尚可折讓數量只剩 " . rtrim(rtrim(number_format($remain, 2, '.', ''), '0'), '.')
                    . "（原數量 " . (float)$ln['r']['Qty'] . "、已折讓 " . $used . "），無法再折讓 " . $ln['qty']];
            }
            $ln['amount'] = round($ln['qty'] * $ln['price'], 2);
            $ln['tax'] = round($ln['amount'] * $rate, 0);
            $net += $ln['amount']; $taxSum += $ln['tax'];
        }
        unset($ln);

        if ($no !== '') {
            $st = $db->prepare("SELECT allow_id FROM acc_allowance WHERE allow_no=? AND status='active' LIMIT 1");
            $st->execute([$no]);
            if ($st->fetchColumn()) { $db->rollBack(); return ['success' => false, 'message' => "證明單號碼「{$no}」已被另一張折讓單使用"]; }
        }
        $sq = aca_next_seqs($db, $cid, $date, true);
        $global = acc_global_cutoff($db);
        $bm = acc_bm_for($db, $date, $cust, $global);
        $db->prepare("INSERT INTO acc_allowance
            (allow_no, allow_date, billing_month, customer_id, customer_name, tax_rate, sales_amount, tax_amount, total_amount,
             seq_cm, seq_m, seq_y, status, note, created_by, created_by_name, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'active', ?,?,?,NOW())")
           ->execute([$no === '' ? null : $no, $date, $bm, $cid, $cust['customer'], $rate, $net, $taxSum, $net + $taxSum,
                      $sq['cm'], $sq['m'], $sq['y'], $note, $user ? (int)$user['id'] : null, $user ? $user['user_cname'] : null]);
        $aid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO acc_allowance_item
            (allow_id, src_type, src_id, src_no, src_date, part_no, spec, src_qty, src_price, qty, unit_price, amount, tax_amount)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($lines as $ln) {
            $r = $ln['r'];
            $ins->execute([$aid, $ln['type'], (int)$r['id'], $r['no'], $r['d'], $r['part'], $r['spec'],
                           (float)$r['Qty'], (float)$r['Unit_price'], $ln['qty'], $ln['price'], $ln['amount'], $ln['tax']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立折讓單失敗：' . $e->getMessage()];
    }
    acc_audit($db, 'ACC_ALLOWANCE', 'acc_allowance', $aid, $cust['customer'],
              ['action' => 'create', 'items' => count($lines), 'total' => $net + $taxSum, 'billing_month' => $bm], null, $user);
    return ['success' => true, 'allow_id' => $aid, 'billing_month' => $bm, 'net' => $net, 'tax' => $taxSum, 'total' => $net + $taxSum,
            'message' => '已開立折讓單，含稅合計 ' . number_format($net + $taxSum) . '；將列入 ' . $bm . ' 帳款月份對帳'];
}

/** 補填／修改證明單號碼 */
function aca_set_no(PDO $db, int $id, string $no, ?array $user): array
{
    $no = trim($no);
    if ($no === '') return ['success' => false, 'message' => '證明單號碼不可空白'];
    if (mb_strlen($no) > 60) return ['success' => false, 'message' => '證明單號碼過長（上限 60 字）'];
    $st = $db->prepare("SELECT allow_id, allow_no, status, customer_name FROM acc_allowance WHERE allow_id=?");
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return ['success' => false, 'message' => '找不到折讓單'];
    if ($a['status'] === 'void') return ['success' => false, 'message' => '此折讓單已作廢，不可修改'];
    $st = $db->prepare("SELECT allow_id FROM acc_allowance WHERE allow_no=? AND status='active' AND allow_id<>? LIMIT 1");
    $st->execute([$no, $id]);
    if ($st->fetchColumn()) return ['success' => false, 'message' => "證明單號碼「{$no}」已被另一張折讓單使用"];
    $db->prepare("UPDATE acc_allowance SET allow_no=?, modified_by_name=?, modified_at=NOW() WHERE allow_id=?")
       ->execute([$no, $user ? $user['user_cname'] : null, $id]);
    acc_audit($db, 'ACC_ALLOWANCE', 'acc_allowance', $id, $a['customer_name'],
              ['allow_no' => [$a['allow_no'], $no]], null, $user);
    return ['success' => true, 'message' => '已儲存證明單號碼'];
}

function aca_void(PDO $db, int $id, string $reason, ?array $user): array
{
    $reason = trim($reason);
    if ($reason === '') return ['success' => false, 'message' => '請填寫作廢原因'];
    $st = $db->prepare("SELECT allow_id, status, customer_name FROM acc_allowance WHERE allow_id=?");
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return ['success' => false, 'message' => '找不到折讓單'];
    if ($a['status'] === 'void') return ['success' => false, 'message' => '此折讓單已經作廢，請重新整理'];
    $db->prepare("UPDATE acc_allowance SET status='void', void_reason=?, voided_by_name=?, voided_at=NOW() WHERE allow_id=?")
       ->execute([mb_substr($reason, 0, 200), $user ? $user['user_cname'] : null, $id]);
    acc_audit($db, 'ACC_ALLOWANCE', 'acc_allowance', $id, $a['customer_name'], ['action' => 'void'], $reason, $user);
    return ['success' => true, 'message' => '已作廢，來源單據的折讓數量已釋出'];
}

/* ── 清單／明細 ───────────────────────────────────────────────── */
function aca_list(PDO $db, array $f): array
{
    aca_ensure_schema($db);
    $w = ['1=1']; $p = [];
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $w[] = "(a.allow_no LIKE ? OR a.customer_name LIKE ? OR a.customer_id LIKE ? OR a.note LIKE ?
                 OR EXISTS (SELECT 1 FROM acc_allowance_item i WHERE i.allow_id=a.allow_id AND (i.src_no LIKE ? OR i.part_no LIKE ? OR i.spec LIKE ?)))";
        for ($i = 0; $i < 7; $i++) $p[] = "%$kw%";
    }
    if (preg_match('/^\d{4}-\d{2}$/', (string)($f['bm'] ?? ''))) { $w[] = 'a.billing_month=?'; $p[] = $f['bm']; }
    $s = (string)($f['status'] ?? '');
    if ($s === 'void') $w[] = "a.status='void'";
    elseif ($s === 'nonumber') $w[] = "a.status='active' AND (a.allow_no IS NULL OR a.allow_no='')";
    elseif ($s === 'active') $w[] = "a.status='active'";
    $where = implode(' AND ', $w);
    $per = max(5, min(100, (int)($f['per_page'] ?? 20)));
    $page = max(1, (int)($f['page'] ?? 1));
    $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN a.status='active' THEN a.total_amount END),0) FROM acc_allowance a WHERE $where");
    $st->execute($p);
    [$total, $sum] = $st->fetch(PDO::FETCH_NUM);
    $st = $db->prepare("SELECT a.*, (SELECT COUNT(*) FROM acc_allowance_item i WHERE i.allow_id=a.allow_id) item_cnt,
                               (SELECT COUNT(*) FROM acc_allowance_file x WHERE x.allow_id=a.allow_id) file_cnt
                        FROM acc_allowance a WHERE $where ORDER BY a.allow_date DESC, a.allow_id DESC
                        LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($p);
    return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$total, 'sum_total' => (float)$sum, 'page' => $page, 'per_page' => $per];
}

function aca_get(PDO $db, int $id): ?array
{
    aca_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM acc_allowance WHERE allow_id=?");
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return null;
    $st = $db->prepare("SELECT * FROM acc_allowance_item WHERE allow_id=? ORDER BY item_id");
    $st->execute([$id]);
    $a['items'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT file_id, orig_name, file_size, uploaded_by_name, uploaded_at FROM acc_allowance_file WHERE allow_id=? ORDER BY file_id");
    $st->execute([$id]);
    $a['files'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $a;
}

/* ── 附件（只存檔名；路徑即時組，ai-rules/07）──────────────────── */
function aca_attach_dir(PDO $db): string
{
    $dir = eg_attach_dir($db, 'acc_allowance_attach_dir', '折讓單');
    eg_attach_ensure_dir($dir);
    return $dir;
}

/** $f 來自 $_FILES 單一檔案陣列 */
function aca_attach_add(PDO $db, int $allowId, array $f, ?array $user): array
{
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        return ['success' => false, 'message' => '檔案上傳失敗'];
    }
    if ((int)$f['size'] > 20 * 1024 * 1024) return ['success' => false, 'message' => '單檔上限 20MB'];
    $orig = basename((string)$f['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (in_array($ext, ['php', 'phtml', 'exe', 'bat', 'cmd', 'js', 'vbs', 'ps1', 'sh', 'com', 'scr', 'msi'], true)) {
        return ['success' => false, 'message' => '不接受此類型的檔案'];
    }
    $dir = aca_attach_dir($db);
    if (!is_dir($dir)) return ['success' => false, 'message' => '附件資料夾無法使用，請洽管理員'];
    $stored = 'AL' . $allowId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . ($ext !== '' ? '.' . $ext : '');
    if (!@move_uploaded_file($f['tmp_name'], $dir . $stored)) return ['success' => false, 'message' => '附件寫入資料夾失敗'];
    try {
        $db->prepare("INSERT INTO acc_allowance_file (allow_id,file_name,orig_name,file_size,uploaded_by_name,uploaded_at) VALUES (?,?,?,?,?,NOW())")
           ->execute([$allowId, $stored, mb_substr($orig, 0, 255), (int)$f['size'], $user ? $user['user_cname'] : null]);
    } catch (Throwable $e) {
        @unlink($dir . $stored);
        return ['success' => false, 'message' => '附件登記失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'file_id' => (int)$db->lastInsertId()];
}

function aca_attach_path(PDO $db, int $fileId): ?array
{
    $st = $db->prepare("SELECT file_name, orig_name FROM acc_allowance_file WHERE file_id=?");
    $st->execute([$fileId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['file_name'] !== basename($r['file_name'])) return null;
    $p = aca_attach_dir($db) . $r['file_name'];
    return is_file($p) ? ['path' => $p, 'name' => $r['orig_name']] : null;
}

function aca_attach_delete(PDO $db, int $fileId): array
{
    $info = aca_attach_path($db, $fileId);
    $db->prepare("DELETE FROM acc_allowance_file WHERE file_id=?")->execute([$fileId]);
    if ($info) @unlink($info['path']);
    return ['success' => true, 'message' => '已刪除附件'];
}

/* ── 對帳進度追蹤整合（recon_track.php）──────────────────────────── */
/** 某帳款月份「有折讓」的客戶：customer_id => ['cnt'=>筆數,'total'=>含稅合計] */
function aca_recon_customer_map(PDO $db, string $bm): array
{
    aca_ensure_schema($db);
    $st = $db->prepare("SELECT customer_id, COUNT(*) cnt, SUM(total_amount) total FROM acc_allowance
                        WHERE billing_month=? AND status='active' GROUP BY customer_id");
    $st->execute([$bm]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['customer_id']] = ['cnt' => (int)$r['cnt'], 'total' => (float)$r['total']];
    return $out;
}

/** 某客戶某帳款月份的折讓單＋明細（對帳明細置頂顯示、折讓資料跳窗共用） */
function aca_recon_rows(PDO $db, string $customerId, string $bm): array
{
    aca_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM acc_allowance WHERE customer_id=? AND billing_month=? AND status='active' ORDER BY allow_date, allow_id");
    $st->execute([$customerId, $bm]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $q = $db->prepare("SELECT * FROM acc_allowance_item WHERE allow_id=? ORDER BY item_id");
        $q->execute([$a['allow_id']]);
        $a['items'] = $q->fetchAll(PDO::FETCH_ASSOC);
        $out[] = $a;
    }
    return $out;
}

/** 以客戶簡稱／別名／客戶ID 找出客戶編號（對帳頁雙擊用的 key 是簡稱） */
function aca_customer_id_of(PDO $db, string $key): ?string
{
    $key = trim($key);
    if ($key === '') return null;
    $c = acc_customer_of_name($db, $key);
    if ($c) return trim((string)$c['customer_id']);
    $st = $db->prepare("SELECT customer_id FROM customer_list WHERE customer_id=? LIMIT 1");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
