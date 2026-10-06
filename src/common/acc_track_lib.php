<?php
/**
 * acc_track_lib.php — 對帳進度追蹤（新模組，與既有「對帳作業」reconcile.php 完全分開的
 * 流程狀態看板）唯一實作。
 * ---------------------------------------------------------------------------
 * 2026-10-06 新增（使用者交辦）。
 *
 * ── 這個模組要解決什麼 ──────────────────────────────────────────────────────
 * reconcile.php／recon_overview.php 管的是「單據怎麼對、對出來的金額是多少」（acc_recon_sheet
 * 的 draft/confirmed/reopened）；本模組管的是「這個月這家客戶/廠商的對帳作業，流程走到哪一關、
 * 卡在誰手上」（處理中→已對帳→已送會計→會計已接收→會計已處理），**刻意不碰 acc_recon_sheet
 * 一個欄位**——那是已經在生產環境運作的對帳底稿，本模組只是疊加一層流程看板。
 *
 * ── 資料來源（刻意沿用既有頁面的口徑，不重新發明）────────────────────────────
 *  AR（客戶／應收）：直接呼叫 acc_lib.php 的 acc_ar_summary()，帳款月份口徑＝acc_bm_for()
 *                   （客戶結帳日 + customer_settlement_exceptions 臨時結帳調整），
 *                   與 Shipping_Quick.php／reconcile.php 的應收完全一致。
 *  AP（廠商／應付）：以 bom_ing_transfer_log.bill_ym 分組——**不是** acc_ap_summary() 的
 *                   invoice_ym 口徑（那是另一套、給發票/採購對帳用的），bill_ym 是
 *                   billing_month_lib.php／views/pm/Transfer_Log_Analysis.php 既有使用的
 *                   「廠商結帳日 + J- 憑單號回推」口徑，使用者指定要跟那一頁一致。
 *
 * ── 狀態機與權限（使用者拍板：角色與矩陣都是全新、可調整的）───────────────────
 *  5 個狀態：processing(處理中)/reconciled(已對帳)/sent_to_acc(已送會計)/
 *            acc_received(會計已接收)/acc_done(會計已處理)。
 *  5 個全新角色（module='acc_recon_track'，見 ACT_ROLES）：
 *    art_admin    本頁管理員（可修改）：任意設定/回復任何狀態、改結帳日、改權限矩陣
 *    art_view_all 本頁管理員（唯讀）：看得到全部 AR/AP 與統計，不能按任何按鈕、不能改設定
 *                 ——使用者原話「本頁管理員應該要分有更改權限跟無更改權限的」，兩者分開指派
 *    art_pm       生管
 *    art_sales    業務
 *    art_acc      會計
 *  「哪個角色可以把狀態設成哪個值」是**可設定的矩陣**（acc_recon_track_role_matrix），
 *  不是寫死「AR 只能業務推、AP 只能生管推」——使用者原話「也可以設定生管、業務都可點選」，
 *  矩陣允許同一顆按鈕同時勾給生管與業務。art_admin 永遠不受矩陣限制（act_can_set_status）。
 *
 * ── 結帳日（工作天數統計的錨點之一）───────────────────────────────────────────
 *  AR：act_cutoff_date_ar()＝acc_cutoff_for()＋acc_settle_ex_of()（跟 acc_bm_for 同一套輸入）。
 *  AP：act_cutoff_date_ap()＝billing_month_lib.php 的 eg_bm_settlement_for()（廠商自己的
 *      settlement_day/mode，沒設才退回全站 vendor_default_settlement_*）。
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/acc_lib.php';
require_once __DIR__ . '/billing_month_lib.php';
require_once __DIR__ . '/kpi_as_lib.php';
require_once __DIR__ . '/order_analysis_lib.php';   // oa_period_buckets()/oa_period_pick() 等：純日期計算，通用

if (!defined('ACT_MODULE'))      define('ACT_MODULE', 'acc_recon_track');
if (!defined('ACT_PARAM_GROUP')) define('ACT_PARAM_GROUP', 'ACC_RECON_TRACK');

/* ══════════════════════════════════════════════════════════════════
 * 資料表（可重複執行）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_ensure_schema')) {
function act_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS acc_recon_track (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            side            ENUM('ar','ap') NOT NULL COMMENT 'ar=應收(客戶) ap=應付(廠商)',
            party_key       VARCHAR(30) NOT NULL COMMENT 'ar: customer_id，查無主檔時 UNM:簡稱；ap: maker_id_no',
            party_name      VARCHAR(100) NULL COMMENT '顯示用名稱快取，每次列表時會跟著主檔更新',
            billing_month   CHAR(7) NOT NULL COMMENT '帳款月份 YYYY-MM',
            cutoff_date     DATE NULL COMMENT '該對象該月份的結帳日（工作天數統計錨點）',
            status          VARCHAR(20) NOT NULL DEFAULT 'processing',
            created_at      DATETIME NULL,
            created_by      INT NULL,
            created_by_name VARCHAR(50) NULL,
            modified_at     DATETIME NULL,
            modified_by     INT NULL,
            modified_by_name VARCHAR(50) NULL,
            UNIQUE KEY uk_side_party_bm (side, party_key, billing_month),
            INDEX idx_bm (billing_month),
            INDEX idx_status (side, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='對帳進度追蹤：每側(AR/AP)每個對象每個結帳月份一列，列表時自動補建（lazy create）'");

        $db->exec("CREATE TABLE IF NOT EXISTS acc_recon_track_status_log (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            track_id        INT NOT NULL,
            from_status     VARCHAR(20) NULL,
            to_status       VARCHAR(20) NOT NULL,
            changed_by      INT NULL,
            changed_by_name VARCHAR(50) NULL,
            changed_at      DATETIME NOT NULL,
            note            VARCHAR(200) NULL,
            INDEX idx_track (track_id, to_status),
            INDEX idx_changed_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='狀態變更歷程：供工作天數統計取「第一次抵達某狀態的時間」'");

        $db->exec("CREATE TABLE IF NOT EXISTS acc_recon_track_change_log (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            target_type     ENUM('customer','maker') NOT NULL,
            target_id       VARCHAR(30) NOT NULL,
            field           VARCHAR(40) NOT NULL,
            old_value       VARCHAR(100) NULL,
            new_value       VARCHAR(100) NULL,
            changed_by      INT NULL,
            changed_by_name VARCHAR(50) NULL,
            changed_at      DATETIME NOT NULL,
            reverted        TINYINT(1) NOT NULL DEFAULT 0,
            revert_of_id    INT NULL,
            INDEX idx_target (target_type, target_id),
            INDEX idx_revert_of (revert_of_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='本頁快速修改客戶/廠商結帳日等欄位的異動紀錄，支援快速復原'");

        $db->exec("CREATE TABLE IF NOT EXISTS acc_recon_track_role_matrix (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            side       ENUM('ar','ap') NOT NULL,
            status     VARCHAR(20) NOT NULL COMMENT '要設成這個狀態需要下列角色之一（本頁管理員不受此限）',
            role_code  VARCHAR(30) NOT NULL,
            UNIQUE KEY uk (side, status, role_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='狀態按鈕權限矩陣：管理員可調整哪些角色可以按哪個狀態'");

        // 預設矩陣：只在整張表還是空的（第一次建立）時種入，管理員調整過後不會被蓋回來
        $cnt = (int)$db->query("SELECT COUNT(*) FROM acc_recon_track_role_matrix")->fetchColumn();
        if ($cnt === 0) {
            $ins = $db->prepare("INSERT IGNORE INTO acc_recon_track_role_matrix (side,status,role_code) VALUES (?,?,?)");
            foreach ([
                ['ar','reconciled','art_sales'], ['ar','sent_to_acc','art_sales'],
                ['ap','reconciled','art_pm'],    ['ap','sent_to_acc','art_pm'],
                ['ar','acc_received','art_acc'], ['ar','acc_done','art_acc'],
                ['ap','acc_received','art_acc'], ['ap','acc_done','art_acc'],
            ] as $r) $ins->execute($r);
        }
    } catch (Throwable $e) {}

    // 應收歸戶需要的兩個欄位（是否需要對帳單／提供方式）已由 master_data_management.php 建立，
    // 這裡只是保險：萬一先跑到這支而那邊還沒跑過，一樣要能用。
    try { $db->exec("ALTER TABLE customer_list ADD COLUMN need_recon_stmt  TINYINT(1)  NULL DEFAULT 0   COMMENT '是否需要對帳單'"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE customer_list ADD COLUMN recon_provide_by VARCHAR(10) NULL DEFAULT NULL COMMENT '對帳單提供方式 customer/company'"); } catch (Throwable $e) {}

    act_ensure_roles($db);
}}

/* 本模組的角色（唯一定義處；role_code 全站唯一，不要跟別的模組撞名） */
if (!defined('ACT_ROLES')) {
    define('ACT_ROLES', [
        'art_admin'    => '對帳追蹤-本頁管理員（可修改）',
        'art_view_all' => '對帳追蹤-管理檢閱（唯讀，可看全部不可修改）',
        'art_pm'       => '對帳追蹤-生管',
        'art_sales'    => '對帳追蹤-業務',
        'art_acc'      => '對帳追蹤-會計',
    ]);
}

if (!function_exists('act_ensure_roles')) {
/** 預設角色（module='acc_recon_track'），可重複執行；只在角色不存在時建立，管理員事後改名/改勾選都不會被還原 */
function act_ensure_roles(PDO $db): void
{
    try {
        foreach (ACT_ROLES as $code => $name) {
            $st = $db->prepare("SELECT role_id FROM roles WHERE role_code=? LIMIT 1");
            $st->execute([$code]);
            $rid = $st->fetchColumn();
            if (!$rid) {
                $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?,?)")
                   ->execute([$code, $name, ACT_MODULE]);
                $rid = (int)$db->lastInsertId();
                $db->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?,?)")->execute([$rid, $code]);
            }
        }
    } catch (Throwable $e) {}
}}

/* ══════════════════════════════════════════════════════════════════
 * 權限
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_perms')) {
function act_perms(PDO $db, int $uid): array
{
    $feat = function_exists('rf_load_user_features_all') ? rf_load_user_features_all($db, $uid) : [];
    $isAll     = in_array('all', $feat, true);
    $canAdmin  = $isAll || in_array('art_admin', $feat, true);
    $viewAll   = $canAdmin || in_array('art_view_all', $feat, true);
    $isPm      = in_array('art_pm', $feat, true);
    $isSales   = in_array('art_sales', $feat, true);
    $isAcc     = in_array('art_acc', $feat, true);
    $canView   = $viewAll || $isPm || $isSales || $isAcc;
    return [
        'uid' => $uid, 'isAll' => $isAll, 'canAdmin' => $canAdmin, 'viewAll' => $viewAll,
        'isPm' => $isPm, 'isSales' => $isSales, 'isAcc' => $isAcc, 'canView' => $canView,
    ];
}}

/* ══════════════════════════════════════════════════════════════════
 * 狀態機 / 錨點定義
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_statuses')) {
function act_statuses(): array
{
    return [
        'processing'   => '處理中',
        'reconciled'   => '已對帳',
        'sent_to_acc'  => '已送會計',
        'acc_received' => '會計已接收',
        'acc_done'     => '會計已處理',
    ];
}}
if (!function_exists('act_anchors')) {
function act_anchors(): array
{
    return array_merge(['cutoff_date' => '結帳日'], act_statuses());
}}

if (!function_exists('act_role_matrix')) {
/** [side][status] => [role_code,...] */
function act_role_matrix(PDO $db): array
{
    $out = [];
    try {
        foreach ($db->query("SELECT side,status,role_code FROM acc_recon_track_role_matrix")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['side']][$r['status']][] = $r['role_code'];
        }
    } catch (Throwable $e) {}
    return $out;
}}

if (!function_exists('act_role_matrix_save')) {
/** 整批覆寫；$rows = [['side'=>'ar','status'=>'reconciled','roles'=>['art_sales','art_pm']], ...] */
function act_role_matrix_save(PDO $db, array $rows): void
{
    $validRoles = ['art_pm', 'art_sales', 'art_acc'];
    $validStatus = array_keys(act_statuses());
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM acc_recon_track_role_matrix");
        $ins = $db->prepare("INSERT IGNORE INTO acc_recon_track_role_matrix (side,status,role_code) VALUES (?,?,?)");
        foreach ($rows as $r) {
            $side = ($r['side'] ?? '') === 'ap' ? 'ap' : 'ar';
            $status = (string)($r['status'] ?? '');
            if (!in_array($status, $validStatus, true)) continue;
            foreach ((array)($r['roles'] ?? []) as $rc) {
                if (in_array($rc, $validRoles, true)) $ins->execute([$side, $status, $rc]);
            }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}}

if (!function_exists('act_can_set_status')) {
/** 這個人能不能把這一側的狀態設成 $toStatus（本頁管理員永遠可以） */
function act_can_set_status(PDO $db, array $perms, string $side, string $toStatus): bool
{
    if (!empty($perms['canAdmin'])) return true;
    $matrix = act_role_matrix($db);
    $allowed = $matrix[$side][$toStatus] ?? [];
    if (!empty($perms['isPm'])    && in_array('art_pm', $allowed, true))    return true;
    if (!empty($perms['isSales']) && in_array('art_sales', $allowed, true)) return true;
    if (!empty($perms['isAcc'])   && in_array('art_acc', $allowed, true))   return true;
    return false;
}}

/* ══════════════════════════════════════════════════════════════════
 * 結帳日（工作天數統計的「結帳日」錨點）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_month_cutoff_day')) {
/** 把「結帳日天數（0=月底）+ 帳款月份 YYYY-MM」換算成實際日期 */
function act_month_cutoff_day(string $billingMonth, int $cutoffDay): string
{
    $y = (int)substr($billingMonth, 0, 4);
    $m = (int)substr($billingMonth, 5, 2);
    $base = sprintf('%04d-%02d-01', $y, $m);
    if ($cutoffDay <= 0) return date('Y-m-t', strtotime($base));   // EOM
    $lastDay = (int)date('t', strtotime($base));
    return sprintf('%04d-%02d-%02d', $y, $m, min($cutoffDay, $lastDay));
}}

if (!function_exists('act_cutoff_date_ar')) {
function act_cutoff_date_ar(PDO $db, ?array $custRow, string $billingMonth): ?string
{
    $ex = acc_settle_ex_of($db, $custRow);
    if (isset($ex[$billingMonth])) return $ex[$billingMonth];
    $global = acc_global_cutoff($db);
    return act_month_cutoff_day($billingMonth, acc_cutoff_for($custRow, $global));
}}

if (!function_exists('act_cutoff_date_ap')) {
function act_cutoff_date_ap(PDO $db, string $makerIdNo, string $billingMonth): ?string
{
    if (!function_exists('eg_bm_settlement_for')) return null;
    $s = eg_bm_settlement_for($db, $makerIdNo ?: null);
    $mode = strtoupper(trim((string)($s['mode'] ?? 'FIXED')));
    if ($mode === 'EOM') return act_month_cutoff_day($billingMonth, 0);
    return act_month_cutoff_day($billingMonth, (int)($s['day'] ?? 20));
}}

/* ══════════════════════════════════════════════════════════════════
 * 追蹤列（lazy create）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_track_get_or_create')) {
function act_track_get_or_create(PDO $db, string $side, string $partyKey, string $partyName, string $billingMonth, ?string $cutoffDate): array
{
    act_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM acc_recon_track WHERE side=? AND party_key=? AND billing_month=? LIMIT 1");
    $st->execute([$side, $partyKey, $billingMonth]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $changed = false;
        if ($partyName !== '' && $row['party_name'] !== $partyName) { $row['party_name'] = $partyName; $changed = true; }
        if ($cutoffDate && $row['cutoff_date'] !== $cutoffDate) { $row['cutoff_date'] = $cutoffDate; $changed = true; }
        if ($changed) {
            $db->prepare("UPDATE acc_recon_track SET party_name=?, cutoff_date=? WHERE id=?")
               ->execute([$row['party_name'], $row['cutoff_date'], $row['id']]);
        }
        return $row;
    }
    $now = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO acc_recon_track (side,party_key,party_name,billing_month,cutoff_date,status,created_at)
                  VALUES (?,?,?,?,?, 'processing', ?)")
       ->execute([$side, $partyKey, $partyName, $billingMonth, $cutoffDate, $now]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO acc_recon_track_status_log (track_id,from_status,to_status,changed_by,changed_by_name,changed_at,note)
                  VALUES (?,NULL,'processing',NULL,'系統',?,'自動建立（首次列出）')")
       ->execute([$id, $now]);
    $st2 = $db->prepare("SELECT * FROM acc_recon_track WHERE id=?");
    $st2->execute([$id]);
    return $st2->fetch(PDO::FETCH_ASSOC);
}}

if (!function_exists('act_set_status')) {
function act_set_status(PDO $db, int $trackId, string $toStatus, array $perms, ?string $note = null): array
{
    if (!array_key_exists($toStatus, act_statuses())) return ['success' => false, 'message' => '不合法的狀態'];
    $st = $db->prepare("SELECT * FROM acc_recon_track WHERE id=?");
    $st->execute([$trackId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['success' => false, 'message' => '查無此筆追蹤資料，請重新整理'];
    if (!act_can_set_status($db, $perms, $row['side'], $toStatus)) return ['success' => false, 'message' => '沒有設定此狀態的權限'];
    if ($row['status'] === $toStatus) return ['success' => true, 'message' => '狀態未變動'];
    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE acc_recon_track SET status=?, modified_at=?, modified_by=?, modified_by_name=? WHERE id=?")
           ->execute([$toStatus, $now, $perms['uid'] ?? null, $perms['uname'] ?? '', $trackId]);
        $db->prepare("INSERT INTO acc_recon_track_status_log (track_id,from_status,to_status,changed_by,changed_by_name,changed_at,note)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$trackId, $row['status'], $toStatus, $perms['uid'] ?? null, $perms['uname'] ?? '', $now, $note]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); return ['success' => false, 'message' => $e->getMessage()]; }
    return ['success' => true, 'message' => '已更新', 'from' => $row['status'], 'to' => $toStatus];
}}

/* ══════════════════════════════════════════════════════════════════
 * AR / AP 清單（供列表頁用；每列已掛好追蹤狀態）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_ar_rows')) {
function act_ar_rows(PDO $db, string $billingMonth): array
{
    act_ensure_schema($db);
    $sum  = acc_ar_summary($db, ['bm_from' => $billingMonth, 'bm_to' => $billingMonth, 'per_page' => 0]);
    $cust = acc_customer_by_name($db);   // 簡稱(含別名) => 完整客戶主檔列（含 settlement_*/need_recon_stmt/recon_provide_by）
    $out = [];
    foreach ($sum['rows'] as $r) {
        $c         = $cust[$r['customer']] ?? null;
        $partyKey  = $r['customer_id'] ? (string)$r['customer_id'] : ('UNM:' . $r['customer']);
        $partyName = $r['customer_full'] ?: $r['customer'];
        $cutoff    = act_cutoff_date_ar($db, $c, $billingMonth);
        $track     = act_track_get_or_create($db, 'ar', $partyKey, $partyName, $billingMonth, $cutoff);
        $out[] = [
            'track_id'        => (int)$track['id'],
            'side'            => 'ar',
            'party_key'       => $partyKey,
            'party_id'        => $r['customer_id'],
            'party_short'     => $r['customer'],
            'party_name'      => $partyName,
            'in_master'       => (bool)$r['in_master'],
            'billing_month'   => $billingMonth,
            'cutoff_date'     => $track['cutoff_date'],
            'status'          => $track['status'],
            'ship_amt'        => (float)$r['ship_amt'],
            'ret_amt'         => (float)$r['ret_amt'],
            'net_amt'         => (float)$r['net_amt'],
            'tax_amt'         => (float)$r['tax_amt'],
            'total_amt'       => (float)$r['total_amt'],
            'need_recon_stmt' => $c['need_recon_stmt'] ?? null,
            'recon_provide_by'=> $c['recon_provide_by'] ?? null,
            'settlement_mode' => $c['settlement_mode'] ?? null,
            'settlement_day'  => $c['settlement_day'] ?? null,
            'payment_method'  => $r['payment_method'] ?? null,
            'net_days'        => $r['net_days'] ?? null,
        ];
    }
    return $out;
}}

if (!function_exists('act_maker_map')) {
function act_maker_map(PDO $db): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        foreach ($db->query("SELECT maker_id_no, maker_id, maker_id_all, settlement_mode, settlement_day, payment_method, net_days, status
                             FROM maker_list")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m[trim((string)$r['maker_id_no'])] = $r;
        }
    } catch (Throwable $e) {}
    return $m;
}}

if (!function_exists('act_ap_rows')) {
function act_ap_rows(PDO $db, string $billingMonth): array
{
    act_ensure_schema($db);
    // 把這個廠商這個結帳月的 bill_ym 補齊（只補空值，已有人工覆寫或已算過的不動）
    try { eg_bm_fill($db, ['only_empty' => true]); } catch (Throwable $e) {}

    $ym6 = str_replace('-', '', $billingMonth);
    $st = $db->prepare("
        SELECT t.maker_from,
               COUNT(*)                            AS cnt,
               SUM(COALESCE(t.process_amount,0))    AS amt,
               SUM(COALESCE(t.tax_amount,0))        AS tax
        FROM bom_ing_transfer_log t
        WHERE t.bill_ym = ? AND COALESCE(t.process_amount,0) <> 0
        GROUP BY t.maker_from");
    $st->execute([$ym6]);
    $mk = act_maker_map($db);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = trim((string)$r['maker_from']);
        $m  = $mk[$id] ?? null;
        $partyKey  = $id !== '' ? $id : 'UNM:unknown';
        $partyName = $m['maker_id_all'] ?? ($m['maker_id'] ?? ($id !== '' ? $id : '（未指定廠商）'));
        $cutoff    = $id !== '' ? act_cutoff_date_ap($db, $id, $billingMonth) : null;
        $track     = act_track_get_or_create($db, 'ap', $partyKey, $partyName, $billingMonth, $cutoff);
        $amt = (float)$r['amt']; $tax = (float)$r['tax'];
        $out[] = [
            'track_id'       => (int)$track['id'],
            'side'           => 'ap',
            'party_key'      => $partyKey,
            'party_id'       => $id !== '' ? $id : null,
            'party_short'    => $m['maker_id'] ?? $id,
            'party_name'     => $partyName,
            'in_master'      => (bool)$m,
            'billing_month'  => $billingMonth,
            'cutoff_date'    => $track['cutoff_date'],
            'status'         => $track['status'],
            'cnt'            => (int)$r['cnt'],
            'amount'         => $amt,
            'tax_amount'     => $tax,
            'total_amt'      => $amt + $tax,
            'settlement_mode'=> $m['settlement_mode'] ?? null,
            'settlement_day' => $m['settlement_day'] ?? null,
            'payment_method' => $m['payment_method'] ?? null,
            'net_days'       => $m['net_days'] ?? null,
        ];
    }
    return $out;
}}

/* ══════════════════════════════════════════════════════════════════
 * 結帳日快速修改＋修改紀錄＋快速復原
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_settle_quick_edit')) {
/**
 * 在本頁直接改客戶/廠商的結帳模式或固定結帳日，會連動寫回 customer_list/maker_list
 * （與主檔管理共用同一組欄位，不另存一份），並留下修改紀錄供「快速復原」。
 */
function act_settle_quick_edit(PDO $db, string $targetType, string $targetId, array $fields, array $perms): array
{
    if (!in_array($targetType, ['customer', 'maker'], true)) return ['success' => false, 'message' => '不合法的對象類型'];
    $table = $targetType === 'customer' ? 'customer_list' : 'maker_list';
    $idCol = $targetType === 'customer' ? 'customer_id' : 'maker_id_no';
    $allow = ['settlement_mode' => true, 'settlement_day' => true];

    $st = $db->prepare("SELECT settlement_mode, settlement_day FROM {$table} WHERE {$idCol}=?");
    $st->execute([$targetId]);
    $old = $st->fetch(PDO::FETCH_ASSOC);
    if (!$old) return ['success' => false, 'message' => '找不到此對象'];

    $sets = []; $vals = []; $logs = [];
    foreach ($fields as $f => $v) {
        if (!isset($allow[$f])) continue;
        $newVal = ($f === 'settlement_day') ? (($v === '' || $v === null) ? null : (int)$v) : trim((string)$v);
        $oldVal = $old[$f];
        if ((string)$oldVal === (string)$newVal) continue;
        $sets[] = "{$f}=?"; $vals[] = $newVal;
        $logs[] = ['field' => $f, 'old' => $oldVal, 'new' => $newVal];
    }
    if (!$sets) return ['success' => true, 'message' => '未變動'];

    $db->beginTransaction();
    try {
        $vals[] = $targetId;
        $db->prepare("UPDATE {$table} SET " . implode(',', $sets) . ", Modified_By=?, Modified_At=NOW() WHERE {$idCol}=?")
           ->execute(array_merge(array_slice($vals, 0, -1), [$perms['uid'] ?? null, $targetId]));
        $ins = $db->prepare("INSERT INTO acc_recon_track_change_log
                              (target_type,target_id,field,old_value,new_value,changed_by,changed_by_name,changed_at)
                              VALUES (?,?,?,?,?,?,?,NOW())");
        foreach ($logs as $l) {
            $ins->execute([$targetType, $targetId, $l['field'], $l['old'], $l['new'], $perms['uid'] ?? null, $perms['uname'] ?? '']);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); return ['success' => false, 'message' => $e->getMessage()]; }
    return ['success' => true, 'message' => '已更新', 'changed' => $logs];
}}

if (!function_exists('act_settle_revert')) {
function act_settle_revert(PDO $db, int $logId, array $perms): array
{
    $st = $db->prepare("SELECT * FROM acc_recon_track_change_log WHERE id=?");
    $st->execute([$logId]);
    $log = $st->fetch(PDO::FETCH_ASSOC);
    if (!$log) return ['success' => false, 'message' => '查無此筆紀錄'];
    if ((int)$log['reverted'] === 1) return ['success' => false, 'message' => '這筆已經復原過了'];
    $table = $log['target_type'] === 'customer' ? 'customer_list' : 'maker_list';
    $idCol = $log['target_type'] === 'customer' ? 'customer_id' : 'maker_id_no';

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE {$table} SET {$log['field']}=?, Modified_By=?, Modified_At=NOW() WHERE {$idCol}=?")
           ->execute([$log['old_value'], $perms['uid'] ?? null, $log['target_id']]);
        $db->prepare("UPDATE acc_recon_track_change_log SET reverted=1 WHERE id=?")->execute([$logId]);
        $db->prepare("INSERT INTO acc_recon_track_change_log
                      (target_type,target_id,field,old_value,new_value,changed_by,changed_by_name,changed_at,revert_of_id)
                      VALUES (?,?,?,?,?,?,?,NOW(),?)")
           ->execute([$log['target_type'], $log['target_id'], $log['field'], $log['new_value'], $log['old_value'],
                      $perms['uid'] ?? null, $perms['uname'] ?? '', $logId]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); return ['success' => false, 'message' => $e->getMessage()]; }
    return ['success' => true, 'message' => '已復原'];
}}

if (!function_exists('act_change_log_list')) {
function act_change_log_list(PDO $db, string $targetType, string $targetId, int $limit = 20): array
{
    $limit = ($limit > 0 && $limit <= 200) ? $limit : 20;
    $st = $db->prepare("SELECT l.*, u.user_cname AS changed_by_cname
                        FROM acc_recon_track_change_log l LEFT JOIN user u ON u.id=l.changed_by
                        WHERE l.target_type=? AND l.target_id=? ORDER BY l.changed_at DESC LIMIT {$limit}");
    $st->execute([$targetType, $targetId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}}

/* ══════════════════════════════════════════════════════════════════
 * 工作天數統計設定（system_parameters，比照 order_analysis_lib 的做法，不建表）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_param_get')) {
function act_param_get(PDO $db, string $key, $default)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([ACT_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}}
if (!function_exists('act_param_save')) {
function act_param_save(PDO $db, string $key, $val, string $by = ''): void
{
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
    $st->execute([ACT_PARAM_GROUP, $key]);
    $rid = $st->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$json, $by, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at)
                      VALUES (?,?,?,?,?,NOW())")->execute([ACT_PARAM_GROUP, $key, $json, '對帳進度追蹤設定', $by]);
    }
}}

if (!function_exists('act_workday_groups')) {
function act_workday_groups(PDO $db): array
{
    $def = [
        ['label' => '結帳日→已送會計', 'from' => 'cutoff_date', 'to' => 'sent_to_acc'],
        ['label' => '已送會計→會計已處理', 'from' => 'sent_to_acc', 'to' => 'acc_done'],
    ];
    return act_param_get($db, 'workday_groups', $def);
}}
if (!function_exists('act_workday_groups_save')) {
function act_workday_groups_save(PDO $db, array $groups, string $by): array
{
    $anchors = array_keys(act_anchors());
    $clean = [];
    foreach ($groups as $g) {
        $label = trim((string)($g['label'] ?? ''));
        $from = (string)($g['from'] ?? ''); $to = (string)($g['to'] ?? '');
        if ($label === '' || !in_array($from, $anchors, true) || !in_array($to, $anchors, true)) continue;
        $clean[] = ['label' => $label, 'from' => $from, 'to' => $to];
    }
    act_param_save($db, 'workday_groups', $clean, $by);
    return $clean;
}}

/* ══════════════════════════════════════════════════════════════════
 * 工作天數統計計算
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('act_anchor_date')) {
function act_anchor_date(array $row, array $statusFirstTime, string $anchor): ?string
{
    if ($anchor === 'cutoff_date') return $row['cutoff_date'] ?: null;
    $t = $statusFirstTime[$anchor] ?? null;
    return $t ? substr($t, 0, 10) : null;
}}

if (!function_exists('act_workday_stats')) {
/** $period = ['start'=>Y-m-d,'end'=>Y-m-d]（依 billing_month 落在這段期間內篩選） */
function act_workday_stats(PDO $db, string $side, array $period, array $groups): array
{
    $bmFrom = substr($period['start'], 0, 7);
    $bmTo   = substr($period['end'], 0, 7);
    $st = $db->prepare("SELECT * FROM acc_recon_track WHERE side=? AND billing_month BETWEEN ? AND ?");
    $st->execute([$side, $bmFrom, $bmTo]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['groups' => array_map(fn($g) => array_merge($g, ['count' => 0, 'avg' => null, 'min' => null, 'max' => null]), $groups), 'total_track' => 0];

    $ids = array_column($rows, 'id');
    $logMap = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st2 = $db->prepare("SELECT track_id, to_status, MIN(changed_at) AS t
                             FROM acc_recon_track_status_log WHERE track_id IN ($in) GROUP BY track_id, to_status");
        $st2->execute($ids);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) $logMap[$r['track_id']][$r['to_status']] = $r['t'];
    }

    $out = [];
    foreach ($groups as $g) {
        $vals = [];
        foreach ($rows as $row) {
            $fromD = act_anchor_date($row, $logMap[$row['id']] ?? [], $g['from']);
            $toD   = act_anchor_date($row, $logMap[$row['id']] ?? [], $g['to']);
            if (!$fromD || !$toD || $toD < $fromD) continue;
            $vals[] = kpi_as_workdays_inclusive($db, $fromD, $toD);
        }
        $out[] = array_merge($g, [
            'count' => count($vals),
            'avg'   => $vals ? round(array_sum($vals) / count($vals), 1) : null,
            'min'   => $vals ? min($vals) : null,
            'max'   => $vals ? max($vals) : null,
        ]);
    }
    return ['groups' => $out, 'total_track' => count($rows)];
}}

if (!function_exists('act_period_status_counts')) {
/** 這段期間（依 billing_month）各狀態的筆數，給 KPI 卡/圓餅用 */
function act_period_status_counts(PDO $db, string $side, array $period): array
{
    $bmFrom = substr($period['start'], 0, 7);
    $bmTo   = substr($period['end'], 0, 7);
    $out = array_fill_keys(array_keys(act_statuses()), 0);
    try {
        $st = $db->prepare("SELECT status, COUNT(*) c FROM acc_recon_track WHERE side=? AND billing_month BETWEEN ? AND ? GROUP BY status");
        $st->execute([$side, $bmFrom, $bmTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['status']] = (int)$r['c'];
    } catch (Throwable $e) {}
    return $out;
}}

if (!function_exists('act_insights')) {
/** 自動分析：本期 vs 上一期的工作天數變化、卡關提醒 */
function act_insights(PDO $db, string $side, array $period, array $prevPeriod, array $groups): array
{
    $out = [];
    $cur  = act_workday_stats($db, $side, $period, $groups);
    $prev = act_workday_stats($db, $side, $prevPeriod, $groups);
    foreach ($cur['groups'] as $i => $g) {
        $p = $prev['groups'][$i] ?? null;
        if ($g['avg'] === null) continue;
        if ($p && $p['avg'] !== null) {
            $diff = round($g['avg'] - $p['avg'], 1);
            if (abs($diff) >= 0.5) {
                $out[] = [
                    'level'  => ($diff > 0) ? 'warn' : 'good',
                    'title'  => $g['label'] . '：平均工作天數' . ($diff > 0 ? '上升' : '下降'),
                    'detail' => "本期平均 {$g['avg']} 天，上一期 {$p['avg']} 天，差 " . ($diff > 0 ? '+' : '') . $diff . ' 天（本期 ' . $g['count'] . ' 筆、上期 ' . $p['count'] . ' 筆）',
                ];
            }
        } elseif ($g['count'] > 0) {
            $out[] = ['level' => 'info', 'title' => $g['label'] . '：本期平均 ' . $g['avg'] . ' 天', 'detail' => '上一期沒有可比較的資料（' . $g['count'] . ' 筆）'];
        }
    }
    $counts = act_period_status_counts($db, $side, $period);
    $stuckAtProcessing = $counts['processing'] ?? 0;
    if ($stuckAtProcessing > 0) {
        $out[] = ['level' => 'warn', 'title' => '尚有 ' . $stuckAtProcessing . ' 筆還在「處理中」', 'detail' => '這段期間內還沒有人按下「已對帳」'];
    }
    return $out;
}}
