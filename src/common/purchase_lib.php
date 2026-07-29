<?php
/**
 * 申請採購 —— 共用函式庫
 *
 * 模型（2026-07-29 定案，使用者拍板）：
 *   採購品主檔三層：類別(stock_item_categories) → 品項(purchase_item) → 規格變體(purchase_spec)
 *     ‧ 規格變體才是真正的「採購料號」(spec_code)，庫存以它計數
 *     ‧ 同一個「鑽頭」只建一次品項，多個尺寸＝多個規格變體，不必重複建品項
 *     ‧ 規格屬性(purchase_attr)掛在「類別」上（如刀具＝直徑/長度/材質/刃數），
 *       新增規格時是填欄位而非自由打字 → 命名一致、可依屬性篩選
 *     ‧ 標籤(purchase_tag)跨類別自由標記，可篩選
 *   流程：申請(金額可留白) → 採購詢價填實際金額 → 依實際總額判定簽核層級 → 核准 → 下單
 *         → 到貨(每筆三選一：入庫待領/直接交付請購人/不列管) → 記帳(發票、付款) → 結案
 *   簽核門檻：system_settings purchase_appr_l1(預設5000) / purchase_appr_l2(預設30000)
 *         總額 ≤ L1 免簽；L1<x≤L2 部門主管一層；>L2 主管＋高階核准兩層
 *   簽核紀錄沿用共用 approval_record（module='purchase'，level='L1'/'L2'）
 *   代理人一律走 delegate_lib（禁自寫代理 SQL，見 ai-rules/11）
 *   庫存共用既有 stock_items / stock_transactions（不另建一套），
 *     以 stock_items.purchase_spec_id 區分採購品，d_setting_id 區分客戶產品
 *   附件只存檔名，路徑現場組（鐵律5 / ai-rules/07），temp/active 暫存狀態機
 */

if (!defined('PURCHASE_LIB_LOADED')) {
define('PURCHASE_LIB_LOADED', 1);

require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';

/** 稅別 */
define('PURCHASE_TAX_TYPES', ['taxable' => '應稅5%', 'free' => '免稅', 'zero' => '零稅率']);
/** 到貨處理方式 */
define('PURCHASE_RECEIVE_MODES', ['stock' => '入庫待領', 'direct' => '直接交付請購人', 'expense' => '不列管(費用)']);
/** 單據狀態 */
define('PURCHASE_STATUS', [
    'submitted' => '待詢價',
    'quoted'    => '待簽核',
    'approved'  => '待下單',
    'ordered'   => '待到貨',
    'partial'   => '部分到貨',
    'received'  => '已到貨',
    'closed'    => '已結案',
    'rejected'  => '已駁回',
    'canceled'  => '已取消',
]);

/* ============================================================
 * Schema
 * ============================================================ */
function purchase_ensure_schema(PDO $db): void
{
    // ── 採購品主檔：標籤 ──
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_tag (
        tag_id INT AUTO_INCREMENT PRIMARY KEY,
        tag_name VARCHAR(40) NOT NULL,
        color VARCHAR(10) NULL COMMENT '暖色系色碼',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        UNIQUE KEY uq_tag (tag_name)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購品標籤(跨類別，可篩選)'");

    // ── 採購品主檔：規格屬性定義（掛在類別上）──
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_attr (
        attr_id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL COMMENT '對應 stock_item_categories.category_id',
        attr_name VARCHAR(40) NOT NULL COMMENT '屬性名稱，如 直徑/長度/材質',
        attr_type VARCHAR(10) NOT NULL DEFAULT 'text' COMMENT 'text=文字 number=數值 select=下拉',
        attr_options VARCHAR(500) NULL COMMENT 'select 用，逗號分隔選項',
        attr_unit VARCHAR(20) NULL COMMENT '單位提示，如 mm',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        KEY idx_cat (category_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購品規格屬性定義(依類別)'");

    // ── 採購品主檔：品項 ──
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_item (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        item_code VARCHAR(30) NOT NULL COMMENT '品項編碼(類別碼-流水)，自動產生',
        category_id INT NOT NULL COMMENT 'stock_item_categories.category_id',
        item_name VARCHAR(100) NOT NULL COMMENT '品項名稱，如 鑽頭',
        default_unit_id INT NULL COMMENT 'stock_units.unit_id',
        default_vendor_id VARCHAR(11) NULL COMMENT 'maker_list.maker_id_no',
        default_vendor_name VARCHAR(120) NULL,
        note VARCHAR(300) NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        Created_By INT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Modified_By INT NULL, Modified_At TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code (item_code),
        KEY idx_cat (category_id),
        KEY idx_name (item_name)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購品品項(鑽頭、手套…)'");

    $db->exec("CREATE TABLE IF NOT EXISTS purchase_item_tag (
        item_id INT NOT NULL, tag_id INT NOT NULL,
        PRIMARY KEY (item_id, tag_id), KEY idx_tag (tag_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購品項↔標籤'");

    // ── 採購品主檔：規格變體（＝實際採購料號）──
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_spec (
        spec_id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        spec_code VARCHAR(40) NOT NULL COMMENT '採購料號(品項碼-兩位流水)',
        spec_text VARCHAR(150) NOT NULL DEFAULT '' COMMENT '規格顯示字串(由屬性值組出，可手改)',
        attr_json TEXT NULL COMMENT '屬性值 {attr_id:值}',
        unit_id INT NULL,
        location_id INT NULL COMMENT '預設儲位 stock_locations.location_id',
        safety_qty DECIMAL(15,4) NULL COMMENT '安全存量(低於此值提醒)',
        last_price DECIMAL(12,4) NULL COMMENT '最近採購單價',
        last_vendor_id VARCHAR(11) NULL, last_vendor_name VARCHAR(120) NULL,
        last_buy_date DATE NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        Created_By INT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Modified_By INT NULL, Modified_At TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_spec_code (spec_code),
        KEY idx_item (item_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購品規格變體(=採購料號，庫存以此計數)'");

    // ── 申請單 ──
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_request (
        req_id INT AUTO_INCREMENT PRIMARY KEY,
        req_no VARCHAR(30) NOT NULL COMMENT '單號 PR-YYYYMMDD-###(建立後不可改，附件子資料夾依此)',
        title VARCHAR(120) NULL,
        dept_id INT NULL, dept_name VARCHAR(60) NULL,
        requester_id INT NULL, requester_name VARCHAR(60) NULL,
        need_date DATE NULL COMMENT '希望到貨日',
        reason VARCHAR(500) NULL COMMENT '申請事由',
        status VARCHAR(20) NOT NULL DEFAULT 'submitted',
        vendor_id VARCHAR(11) NULL, vendor_name VARCHAR(120) NULL,
        tax_type VARCHAR(10) NOT NULL DEFAULT 'taxable',
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '未稅小計',
        tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        grand_total DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '含稅總額(簽核判定依據)',
        need_levels TINYINT NOT NULL DEFAULT 0 COMMENT '需簽核層數 0/1/2',
        level_done TINYINT NOT NULL DEFAULT 0 COMMENT '已完成層數',
        buyer_id INT NULL, buyer_name VARCHAR(60) NULL, quoted_at DATETIME NULL,
        approved_at DATETIME NULL, ordered_at DATETIME NULL, expected_date DATE NULL,
        invoice_no VARCHAR(40) NULL, invoice_date DATE NULL,
        pay_status VARCHAR(10) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid/paid',
        pay_date DATE NULL, pay_method VARCHAR(40) NULL,
        reject_reason VARCHAR(300) NULL,
        closed_at DATETIME NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        deleted_by INT NULL, deleted_at DATETIME NULL, delete_reason VARCHAR(300) NULL,
        Created_By INT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Modified_By INT NULL, Modified_At TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_req_no (req_no),
        KEY idx_status (status), KEY idx_requester (requester_id), KEY idx_created (Created_At)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購申請單(單頭：帳與簽核)'");

    $db->exec("CREATE TABLE IF NOT EXISTS purchase_request_item (
        pr_item_id INT AUTO_INCREMENT PRIMARY KEY,
        req_id INT NOT NULL,
        spec_id INT NULL COMMENT 'purchase_spec.spec_id；NULL=自由輸入未建檔',
        item_name VARCHAR(120) NOT NULL DEFAULT '' COMMENT '品名快照',
        spec_text VARCHAR(150) NULL COMMENT '規格快照',
        category_id INT NULL, unit_id INT NULL,
        qty_requested DECIMAL(15,4) NOT NULL DEFAULT 1,
        qty_received DECIMAL(15,4) NOT NULL DEFAULT 0,
        est_price DECIMAL(12,4) NULL COMMENT '申請人預估單價(選填，可留白)',
        unit_price DECIMAL(12,4) NULL COMMENT '採購詢價後實際單價',
        amount DECIMAL(14,2) NULL COMMENT '未稅小計 = 數量×單價',
        receive_mode VARCHAR(10) NOT NULL DEFAULT 'stock',
        location_id INT NULL COMMENT '入庫儲位',
        stock_item_id INT NULL COMMENT '實際入庫的 stock_items',
        remark VARCHAR(300) NULL,
        is_urgent TINYINT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_req (req_id), KEY idx_spec (spec_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購申請單明細'");

    $db->exec("CREATE TABLE IF NOT EXISTS purchase_receipt (
        rcpt_id INT AUTO_INCREMENT PRIMARY KEY,
        req_id INT NOT NULL, pr_item_id INT NOT NULL,
        rcpt_date DATE NOT NULL,
        qty DECIMAL(15,4) NOT NULL DEFAULT 0,
        receive_mode VARCHAR(10) NOT NULL DEFAULT 'stock',
        location_id INT NULL, stock_item_id INT NULL,
        txn_in_id INT NULL COMMENT 'stock_transactions 入庫列',
        txn_out_id INT NULL COMMENT '直接交付時的出庫列',
        receiver_id INT NULL, receiver_name VARCHAR(60) NULL COMMENT '直接交付的領用人',
        remark VARCHAR(300) NULL,
        Created_By INT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_req (req_id), KEY idx_item (pr_item_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購到貨紀錄(一筆明細可多次到貨)'");

    // ── 附件（只存檔名；temp/active 暫存狀態機）──
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_attachment (
        att_id INT AUTO_INCREMENT PRIMARY KEY,
        req_id INT NOT NULL DEFAULT 0,
        user_id INT NULL COMMENT '上傳者(temp 列以此判定擁有者)',
        att_type VARCHAR(12) NOT NULL DEFAULT 'other' COMMENT 'quote=估價單 invoice=發票 receipt=收據 other',
        file_name VARCHAR(190) NOT NULL COMMENT '實際存檔檔名(不存路徑)',
        original_name VARCHAR(190) NULL,
        file_size INT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT 'temp=未存檔暫存 active=正式',
        expire_at DATETIME NULL,
        Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_req (req_id), KEY idx_status (status)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='採購單附件(估價單/發票/收據)'");

    // ── 庫存主表加欄位：區分採購品（與 d_setting_id 二擇一）──
    foreach ([
        "ALTER TABLE stock_items ADD COLUMN purchase_spec_id INT NULL COMMENT '採購品規格ID(purchase_spec.spec_id)；與 d_setting_id 二擇一'",
        "ALTER TABLE stock_items ADD INDEX idx_purchase_spec (purchase_spec_id)",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 已存在 */ }
    }

    // ── 角色 ──
    foreach ([
        ['purchase_apply',       '申請採購'],
        ['purchase_buy',         '採購作業'],
        ['purchase_receive',     '到貨入庫'],
        ['purchase_view',        '採購檢閱'],
        ['purchase_approve_top', '高階核准'],
        ['purchase_admin',       '採購管理員'],
    ] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='purchase' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?,'purchase')")->execute([$r[0], $r[1]]);
        }
    }
}

/* ============================================================
 * 使用者 / 權限（roles module='purchase'）
 * ============================================================ */
function purchase_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($u) {
        $d = $db->prepare("SELECT d.id, d.name FROM department d
                           JOIN user_department_position_map m ON m.department_id=d.id
                           WHERE m.user_id=? ORDER BY m.is_main DESC, d.id ASC LIMIT 1");
        $d->execute([(int)$u['id']]);
        $dep = $d->fetch(PDO::FETCH_ASSOC);
        $u['dept_id']   = $dep ? (int)$dep['id'] : null;
        $u['dept_name'] = $dep ? (string)$dep['name'] : '';
    }
    return $u;
}

function purchase_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='purchase' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='purchase' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

/** 依角色代碼取得所有具該角色的 user id（含職務綁定） */
function purchase_role_users(PDO $db, string $code): array
{
    $out = [];
    try {
        $st = $db->prepare("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE r.module='purchase' AND r.role_code=?");
        $st->execute([$code]);
        $out = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $st = $db->prepare("SELECT DISTINCT m.user_id FROM user_department_position_map m
                            JOIN position_roles pr ON pr.position_id=m.position_id
                            JOIN roles r ON r.role_id=pr.role_id
                            WHERE r.module='purchase' AND r.role_code=?");
        $st->execute([$code]);
        $out = array_merge($out, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {}
    return array_values(array_unique(array_filter($out)));
}

function purchase_perms(PDO $db, ?array $u): array
{
    $none = ['isAdmin'=>false,'canAdmin'=>false,'canBuy'=>false,'canReceive'=>false,
             'canView'=>false,'canApply'=>false,'canApproveTop'=>false];
    if (!$u) return $none;
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin    = $isAdmin  || purchase_has_role($db, $uid, ['purchase_admin']);
    $canBuy      = $canAdmin || purchase_has_role($db, $uid, ['purchase_buy']);
    $canReceive  = $canBuy   || purchase_has_role($db, $uid, ['purchase_receive']);
    $canApply    = $canReceive || purchase_has_role($db, $uid, ['purchase_apply']);
    $canView     = $canApply || purchase_has_role($db, $uid, ['purchase_view']);
    $canApproveTop = $isAdmin || purchase_has_role($db, $uid, ['purchase_approve_top']);
    return compact('isAdmin') + [
        'canAdmin'=>$canAdmin, 'canBuy'=>$canBuy, 'canReceive'=>$canReceive,
        'canView'=>$canView, 'canApply'=>$canApply, 'canApproveTop'=>$canApproveTop,
    ];
}

function purchase_role_label(array $p): string
{
    if ($p['isAdmin'])     return '管理者';
    if ($p['canAdmin'])    return '採購管理員';
    if ($p['canBuy'])      return '採購作業';
    if ($p['canReceive'])  return '到貨入庫';
    if ($p['canApply'])    return '申請採購';
    if ($p['canView'])     return '採購檢閱';
    return '無權限';
}

/* ============================================================
 * 設定（system_settings）
 * ============================================================ */
function purchase_setting(PDO $db, string $key, string $default = ''): string
{
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

function purchase_set_setting(PDO $db, string $key, string $val): void
{
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $st->execute([$key, $val]);
}

/** 簽核金額門檻 [L1, L2] */
function purchase_thresholds(PDO $db): array
{
    $l1 = (float)purchase_setting($db, 'purchase_appr_l1', '5000');
    $l2 = (float)purchase_setting($db, 'purchase_appr_l2', '30000');
    if ($l2 < $l1) $l2 = $l1;
    return [$l1, $l2];
}

/** 依含稅總額算出需要幾層簽核（0=免簽） */
function purchase_need_levels(float $grandTotal, float $l1, float $l2): int
{
    if ($grandTotal <= $l1) return 0;
    if ($grandTotal <= $l2) return 1;
    return 2;
}

/* ============================================================
 * 編號
 * ============================================================ */
function purchase_next_req_no(PDO $db): string
{
    $today = date('Ymd');
    $st = $db->prepare("SELECT MAX(req_no) FROM purchase_request WHERE req_no LIKE ?");
    $st->execute(["PR-$today-%"]);
    $last = $st->fetchColumn();
    $seq  = $last ? ((int)substr((string)$last, -3) + 1) : 1;
    return sprintf('PR-%s-%03d', $today, $seq);
}

/** 品項編碼：類別碼(無則PU)-4位流水 */
function purchase_next_item_code(PDO $db, int $categoryId): string
{
    $st = $db->prepare("SELECT category_code FROM stock_item_categories WHERE category_id=?");
    $st->execute([$categoryId]);
    $code = strtoupper(trim((string)($st->fetchColumn() ?: '')));
    if ($code === '') $code = 'PU';
    $st = $db->prepare("SELECT item_code FROM purchase_item WHERE item_code LIKE ? ORDER BY item_code DESC LIMIT 1");
    $st->execute([$code . '-%']);
    $last = (string)($st->fetchColumn() ?: '');
    $seq  = $last !== '' ? ((int)substr($last, strlen($code) + 1) + 1) : 1;
    return sprintf('%s-%04d', $code, $seq);
}

/** 規格編碼：品項碼-2位流水 */
function purchase_next_spec_code(PDO $db, int $itemId): string
{
    $st = $db->prepare("SELECT item_code FROM purchase_item WHERE item_id=?");
    $st->execute([$itemId]);
    $base = (string)($st->fetchColumn() ?: 'PU-0000');
    $st = $db->prepare("SELECT spec_code FROM purchase_spec WHERE item_id=? ORDER BY spec_code DESC LIMIT 1");
    $st->execute([$itemId]);
    $last = (string)($st->fetchColumn() ?: '');
    $seq  = $last !== '' ? ((int)substr($last, strlen($base) + 1) + 1) : 1;
    return sprintf('%s-%02d', $base, $seq);
}

/** 由屬性值組出規格顯示字串（如「直徑5mm 長度100mm 材質HSS」） */
function purchase_build_spec_text(PDO $db, int $categoryId, array $attrVals): string
{
    if (!$attrVals) return '';
    $st = $db->prepare("SELECT attr_id, attr_name, attr_unit FROM purchase_attr
                        WHERE category_id=? AND is_active=1 ORDER BY sort_order, attr_id");
    $st->execute([$categoryId]);
    $parts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $v = trim((string)($attrVals[$a['attr_id']] ?? ''));
        if ($v === '') continue;
        $parts[] = $a['attr_name'] . $v . (string)$a['attr_unit'];
    }
    return implode(' ', $parts);
}

/* ============================================================
 * 金額計算（一律後端算，前端只顯示）
 * ============================================================ */
function purchase_calc_totals(PDO $db, int $reqId, string $taxType): array
{
    $st = $db->prepare("SELECT COALESCE(SUM(ROUND(qty_requested * COALESCE(unit_price,0), 2)),0)
                        FROM purchase_request_item WHERE req_id=?");
    $st->execute([$reqId]);
    $subtotal = round((float)$st->fetchColumn(), 2);
    $tax = ($taxType === 'taxable') ? round($subtotal * 0.05, 2) : 0.0;
    return ['subtotal' => $subtotal, 'tax_amount' => $tax, 'grand_total' => round($subtotal + $tax, 2)];
}

/* ============================================================
 * 簽核人解析（一律經 delegate_lib，含代理與權責分離）
 * ============================================================ */
function purchase_level_signers(PDO $db, array $req, int $level): array
{
    $requesterId = (int)($req['requester_id'] ?? 0);
    $deptId      = $req['dept_id'] !== null ? (int)$req['dept_id'] : null;
    $signers     = [];

    if ($level === 1) {
        $sup = null;
        try { $sup = eg_resolve_supervisor($db, $requesterId, $deptId); } catch (Throwable $e) {}
        if ($sup && (int)$sup !== $requesterId) {
            try {
                $r = eg_resolve_signer($db, (int)$sup, [
                    'applicant_id' => $requesterId,
                    'scope_department_id' => $deptId,
                    'flow_key' => 'purchase_L1',
                    'doc_id' => (int)($req['req_id'] ?? 0),
                ]);
                $signers[] = (int)$r['signer_id'];
            } catch (Throwable $e) { $signers[] = (int)$sup; }
        }
        // 主管解析不出來（或主管就是申請人）→ 退回高階核准者，再退回採購管理員
        if (!$signers) $signers = purchase_role_users($db, 'purchase_approve_top');
        if (!$signers) $signers = purchase_role_users($db, 'purchase_admin');
    } else {
        foreach (purchase_role_users($db, 'purchase_approve_top') as $uid) {
            if ($uid === $requesterId) continue;
            try {
                $r = eg_resolve_signer($db, $uid, [
                    'applicant_id' => $requesterId,
                    'flow_key' => 'purchase_L2',
                    'doc_id' => (int)($req['req_id'] ?? 0),
                ]);
                $signers[] = (int)$r['signer_id'];
            } catch (Throwable $e) { $signers[] = $uid; }
        }
        if (!$signers) $signers = purchase_role_users($db, 'purchase_admin');
    }
    return array_values(array_unique(array_filter($signers)));
}

/** 某使用者對某單是否為當層簽核人 */
function purchase_can_sign(PDO $db, array $req, int $uid, array $perms): bool
{
    if (($req['status'] ?? '') !== 'quoted') return false;
    $level = (int)$req['level_done'] + 1;
    if ($level > (int)$req['need_levels']) return false;
    if ($perms['isAdmin']) return true;
    return in_array($uid, purchase_level_signers($db, $req, $level), true);
}

/* ============================================================
 * 通知（沿用 live_event；推播失敗不影響流程）
 * ============================================================ */
function purchase_notify(PDO $db, array $userIds, string $refType, int $refId,
                         string $title, string $content, string $mode = 'read', ?int $createdBy = null): int
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return 0;
    // 測試紀律：標題含 __ 的單據視為測試單，不建立公告也不推播。
    // 一定要回頭查「單據本身」的標題——只檢查訊息字串會漏掉（訊息不一定帶得到單據標題），
    // 2026-07-29 就是這樣讓 6 則測試通知外流到真實使用者。
    if (str_contains($title, '__') || str_contains($content, '__')) return 0;
    if ($refId > 0) {
        try {
            $st = $db->prepare("SELECT CONCAT(COALESCE(title,''),'|',COALESCE(reason,'')) FROM purchase_request WHERE req_id=?");
            $st->execute([$refId]);
            if (str_contains((string)$st->fetchColumn(), '__')) return 0;
        } catch (Throwable $e) {}
    }
    try {
        if ($mode === 'sign') {
            $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                          WHERE ref_type=? AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())")
               ->execute([$refType, $refId]);
        }
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '申請採購', 1, ?, ?)")
           ->execute([$title, $content, $createdBy, $refType, $refId]);
        $eventId = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
        foreach ($userIds as $uid) $ins->execute([$eventId, $uid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            $recipients = eg_push_event_recipients($db, $eventId);
            eg_push_send_to_users($db, $recipients, ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
        } catch (Throwable $e) { /* 推播失敗不影響流程 */ }
        return $eventId;
    } catch (Throwable $e) {
        error_log('[purchase] notify failed: ' . $e->getMessage());
        return 0;
    }
}

/** 結束某單的待簽核通知（比照報價單） */
function purchase_close_sign_notice(PDO $db, int $reqId, int $deciderUid): void
{
    try {
        $st = $db->prepare("SELECT id FROM live_event WHERE ref_type='PURCHASE_APPROVAL' AND ref_id=?
                            AND (enddate IS NULL OR enddate >= CURDATE())");
        $st->execute([$reqId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $eid = (int)$eid;
            $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=?")->execute([$eid]);
            $rs = $db->prepare("SELECT id FROM live_event_response WHERE live_event_id=? AND user_id=?");
            $rs->execute([$eid, $deciderUid]);
            if ($rid = $rs->fetchColumn()) {
                $db->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()), signed_at=COALESCE(signed_at,NOW()) WHERE id=?")->execute([$rid]);
            } else {
                $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at) VALUES (?,?,NOW(),NOW())")->execute([$eid, $deciderUid]);
            }
        }
    } catch (Throwable $e) { error_log('[purchase] close_sign_notice failed: ' . $e->getMessage()); }
}

/* ============================================================
 * 入庫（共用既有 stock_items / stock_transactions）
 * ============================================================ */

/** 找出或建立採購品對應的 stock_items 列（同規格＋同儲位＝同一列） */
function purchase_find_or_create_stock_item(PDO $db, array $item, array $req, ?int $locationId, int $userId): int
{
    $specId = (int)($item['spec_id'] ?? 0);
    if ($specId <= 0) throw new Exception('未建檔的採購品無法入庫，請先在「採購品主檔」建立品項與規格');

    $st = $db->prepare("SELECT s.spec_id, s.spec_code, s.spec_text, s.unit_id, s.location_id, i.item_name, i.category_id
                        FROM purchase_spec s JOIN purchase_item i ON i.item_id=s.item_id WHERE s.spec_id=?");
    $st->execute([$specId]);
    $spec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$spec) throw new Exception('找不到採購品規格');

    $locId = $locationId ?: ($spec['location_id'] !== null ? (int)$spec['location_id'] : 0);
    if ($locId <= 0) throw new Exception('請指定入庫儲位');

    $st = $db->prepare("SELECT stock_item_id FROM stock_items
                        WHERE purchase_spec_id=? AND location_id=? AND is_active=1 LIMIT 1");
    $st->execute([$specId, $locId]);
    $sid = $st->fetchColumn();
    if ($sid) return (int)$sid;

    $locName = '';
    try {
        $st = $db->prepare("SELECT location_code FROM stock_locations WHERE location_id=?");
        $st->execute([$locId]);
        $locName = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    $db->prepare("INSERT INTO stock_items
        (d_id, d_setting_id, purchase_spec_id, item_type, storage_location, location_id, qty, unit_id,
         vendor_id, vendor_name, remark1, stock_date, is_active, Created_By)
        VALUES (?, NULL, ?, ?, ?, ?, 0, ?, ?, ?, ?, CURDATE(), 1, ?)")
       ->execute([
           $spec['spec_code'], $specId, (int)$spec['category_id'], $locName, $locId,
           $item['unit_id'] !== null ? (int)$item['unit_id'] : ($spec['unit_id'] !== null ? (int)$spec['unit_id'] : null),
           $req['vendor_id'] ?: null, $req['vendor_name'] ?: null,
           trim($spec['item_name'] . ' ' . (string)$spec['spec_text']), $userId,
       ]);
    return (int)$db->lastInsertId();
}

/** 寫一筆庫存異動並同步 stock_items.qty，回傳 txn_id */
function purchase_write_txn(PDO $db, int $stockItemId, string $type, float $qty,
                            ?int $locId, array $opt, int $userId): int
{
    $st = $db->prepare("SELECT d_id, qty FROM stock_items WHERE stock_item_id=? FOR UPDATE");
    $st->execute([$stockItemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('找不到庫存品項');
    $before = (float)$row['qty'];
    $delta  = ($type === 'out') ? -abs($qty) : abs($qty);
    $after  = $before + $delta;

    $db->prepare("INSERT INTO stock_transactions
        (stock_item_id, d_id, txn_type, txn_qty, qty_before, qty_after, location_to, location_to_id,
         location_from_id, unit_cost_snap, txn_date, remark, out_dept_id, out_user_id, Created_By)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
           $stockItemId, $row['d_id'], $type, $delta, $before, $after,
           $opt['location_name'] ?? null,
           ($type === 'in') ? $locId : null,
           ($type === 'out') ? $locId : null,
           $opt['unit_cost'] ?? null,
           $opt['txn_date'] ?? date('Y-m-d'),
           $opt['remark'] ?? null,
           $opt['out_dept_id'] ?? null,
           $opt['out_user_id'] ?? null,
           $userId,
       ]);
    $txnId = (int)$db->lastInsertId();
    $db->prepare("UPDATE stock_items SET qty=?, Modified_By=? WHERE stock_item_id=?")
       ->execute([$after, $userId, $stockItemId]);
    return $txnId;
}

/* ============================================================
 * 附件（只存檔名，路徑現場組；鐵律5 / ai-rules/07）
 * ============================================================ */
function purchase_attach_dirs(PDO $db): array
{
    $nas = purchase_setting($db, 'purchase_attach_nas_dir', 'Z:/BOM/ERP/採購/');
    $url = purchase_setting($db, 'purchase_attach_url_dir', '/nas/ERP/採購/');
    if (!preg_match('#[/\\\\]$#', $nas)) $nas .= '/';
    return [$nas, rtrim($url, '/') . '/'];
}

/**
 * 單一路徑解析點：所有下載/預覽/刪除都呼叫這裡。
 * 子資料夾＝單號（req_no 建立後不可改），temp 附件放 _temp 子資料夾。
 */
function purchase_att_path(PDO $db, array $att, ?string $reqNo = null): string
{
    [$nas] = purchase_attach_dirs($db);
    if ($reqNo === null) {
        $reqNo = '';
        if ((int)($att['req_id'] ?? 0) > 0) {
            $st = $db->prepare("SELECT req_no FROM purchase_request WHERE req_id=?");
            $st->execute([(int)$att['req_id']]);
            $reqNo = (string)($st->fetchColumn() ?: '');
        }
    }
    $sub = (($att['status'] ?? 'active') === 'temp' || $reqNo === '') ? '_temp' : $reqNo;
    return $nas . $sub . DIRECTORY_SEPARATOR . $att['file_name'];
}

function purchase_att_url(PDO $db, array $att, ?string $reqNo = null): string
{
    [, $url] = purchase_attach_dirs($db);
    if ($reqNo === null) $reqNo = '';
    $sub = (($att['status'] ?? 'active') === 'temp' || $reqNo === '') ? '_temp' : $reqNo;
    return $url . rawurlencode($sub) . '/' . rawurlencode($att['file_name']);
}

/** 懶惰清除：刪掉過期的 temp 附件（實體檔＋DB 列）。列表 action 順路呼叫。 */
function purchase_purge_expired_temp(PDO $db): void
{
    try {
        $rows = $db->query("SELECT att_id, req_id, file_name, status FROM purchase_attachment
                            WHERE status='temp' AND expire_at IS NOT NULL AND expire_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        foreach ($rows as $r) {
            $fp = purchase_att_path($db, $r, '');
            if (is_file($fp)) @unlink($fp);
        }
        $in = implode(',', array_map(fn($r) => (int)$r['att_id'], $rows));
        $db->exec("DELETE FROM purchase_attachment WHERE att_id IN ($in)");
    } catch (Throwable $e) {}
}

/** temp 轉正：搬檔到單號資料夾並改 status（在主單存檔的同一筆交易內呼叫） */
function purchase_commit_temp_atts(PDO $db, array $attIds, int $reqId, string $reqNo, int $userId): void
{
    $attIds = array_values(array_filter(array_map('intval', $attIds)));
    if (!$attIds) return;
    [$nas] = purchase_attach_dirs($db);
    $destDir = $nas . $reqNo;
    if (!is_dir($destDir) && !@mkdir($destDir, 0777, true)) throw new Exception('無法建立附件目錄：' . $destDir);
    $in = implode(',', array_fill(0, count($attIds), '?'));
    $st = $db->prepare("SELECT att_id, file_name FROM purchase_attachment
                        WHERE att_id IN ($in) AND user_id=? AND status='temp'");
    $st->execute(array_merge($attIds, [$userId]));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $src = $nas . '_temp' . DIRECTORY_SEPARATOR . $a['file_name'];
        $dst = $destDir . DIRECTORY_SEPARATOR . $a['file_name'];
        if (is_file($src)) @rename($src, $dst);
        $db->prepare("UPDATE purchase_attachment SET req_id=?, status='active', expire_at=NULL WHERE att_id=?")
           ->execute([$reqId, (int)$a['att_id']]);
    }
}

} // PURCHASE_LIB_LOADED
