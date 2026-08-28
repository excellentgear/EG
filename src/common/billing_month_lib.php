<?php
/**
 * billing_month_lib.php — 製程移轉「帳款月份」的唯一實作
 * ---------------------------------------------------------------------------
 * 2026-08-27 新增（使用者交辦）。所有算帳款月份的地方一律呼叫這裡，禁止各頁自刻。
 *
 * ── 帳款月份怎麼來（使用者拍板）────────────────────────────────────────────
 *  1) 基準日期：**一律以 J- 單號解析**（J-1150819055 → 民國115/08/19 → 115+1911＝2026.08.19）。
 *     單號不是 J-＋10 碼數字時才退回 transfer_date（全表只有 20 筆非 J- 單號）。
 *     ※ 刻意不用 transfer_date 優先：實測有 4,123 筆（4.3%）兩者不一致，使用者指定以憑單號為準。
 *  2) 結帳日：**該廠商主檔自己設的優先**（maker_list.settlement_day，目前 5 家有設），
 *     沒設才用全域「廠商預設結帳日」（system_settings.vendor_default_settlement_day，目前 20）。
 *     廠商＝移出單位 maker_from（對 maker_list.maker_id_no）。
 *  3) 區間：結帳日 D ⇒（上月 D+1）～（本月 D）算「本月帳」。
 *     例：D=20，7/21～8/20 都是 8 月帳；8/21 起就變成 9 月帳。**12 月會自動跨到隔年 1 月。**
 *     D 大於當月天數時（例如 D=31 遇到 2 月）自動視為該月最後一天，不會整個月被推到下個月。
 *     結帳模式 EOM（月底結帳）＝該自然月就是該月帳。
 *
 * ── 手動修改 ───────────────────────────────────────────────────────────────
 *  bill_ym_manual=1 的列代表人工指定過，**自動重算一律不動它**（重新匯入 ERP 也不會被蓋掉）。
 *  要讓它回到自動值，走「還原為自動」（eg_bm_reset_auto）。
 *
 * ── 權限（module='proc_transfer'）───────────────────────────────────────────
 *  ptl_bill_edit 帳款月份維護：批次修改／還原為自動
 *  ptl_admin     製程移轉管理員：以上＋重算（含尚未產生帳款月份的整批回填）
 *  檢視不需要角色（維持本頁原本「側邊選單進得來就看得到」的行為，不因本次改動變嚴）。
 */

if (!function_exists('eg_bm_ensure_schema')) {
/** 建立/補齊欄位（可重複執行）。 */
function eg_bm_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([
        "ALTER TABLE bom_ing_transfer_log ADD COLUMN bill_ym CHAR(6) NULL COMMENT '帳款月份 YYYYMM'",
        "ALTER TABLE bom_ing_transfer_log ADD COLUMN bill_ym_manual TINYINT(1) NOT NULL DEFAULT 0 COMMENT '帳款月份是否人工指定過'",
        "ALTER TABLE bom_ing_transfer_log ADD COLUMN bill_ym_by INT NULL COMMENT '最後修改帳款月份的 user.id'",
        "ALTER TABLE bom_ing_transfer_log ADD COLUMN bill_ym_at DATETIME NULL COMMENT '最後修改帳款月份的時間'",
        "ALTER TABLE bom_ing_transfer_log ADD INDEX idx_bill_ym (bill_ym)",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }
    eg_bm_ensure_roles($db);
}}

if (!function_exists('eg_bm_ensure_roles')) {
/** 角色（module='proc_transfer'），可重複執行。 */
function eg_bm_ensure_roles(PDO $db): void
{
    try {
        foreach ([
            ['ptl_admin',     '製程移轉管理員'],
            ['ptl_bill_edit', '帳款月份維護'],
        ] as $r) {
            $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='proc_transfer' LIMIT 1");
            $st->execute([$r[0]]);
            if (!$st->fetchColumn()) {
                $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'proc_transfer')")
                   ->execute([$r[0], $r[1]]);
            }
        }
    } catch (Throwable $e) {}
}}

/* ══════════════════════ 基準日期 ══════════════════════ */

if (!function_exists('eg_bm_date_from_no')) {
/**
 * J- 單號 → 'Y-m-d'（民國年+1911）。解析不出來回 null。
 * 格式：J-1150819055＝J- + 民國年3碼 + 月2碼 + 日2碼 + 流水3碼。
 */
function eg_bm_date_from_no(?string $no): ?string
{
    if ($no === null) return null;
    $no = trim($no);
    if (!preg_match('/^J-(\d{3})(\d{2})(\d{2})\d{3}$/', $no, $m)) return null;
    $y = (int)$m[1] + 1911;
    $mo = (int)$m[2]; $d = (int)$m[3];
    if (!checkdate($mo, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}}

if (!function_exists('eg_bm_base_date')) {
/** 這一列要用哪個日期算帳款月份：J- 單號優先，解析不出來才用 transfer_date。 */
function eg_bm_base_date(?string $transfer_no, ?string $transfer_date): ?string
{
    $d = eg_bm_date_from_no($transfer_no);
    if ($d !== null) return $d;
    if ($transfer_date === null || $transfer_date === '' || strpos((string)$transfer_date, '0000') === 0) return null;
    $ts = strtotime((string)$transfer_date);
    return $ts ? date('Y-m-d', $ts) : null;
}}

/* ══════════════════════ 結帳日 ══════════════════════ */

if (!function_exists('eg_bm_default_settlement')) {
/** 全域「廠商預設付款條件」的結帳模式與結帳日（主檔管理 → 類別字典設定 → 基本設定）。 */
function eg_bm_default_settlement(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $mode = 'FIXED'; $day = 20;
    try {
        $st = $db->query("SELECT setting_key, setting_value FROM system_settings
                          WHERE setting_key IN ('vendor_default_settlement_mode','vendor_default_settlement_day')");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['setting_key'] === 'vendor_default_settlement_mode' && $r['setting_value'] !== '') $mode = $r['setting_value'];
            if ($r['setting_key'] === 'vendor_default_settlement_day'  && $r['setting_value'] !== '') $day  = (int)$r['setting_value'];
        }
    } catch (Throwable $e) {}
    if ($day < 1 || $day > 31) $day = 20;
    return $cache = ['mode' => $mode, 'day' => $day];
}}

if (!function_exists('eg_bm_settlement_map')) {
/** maker_id_no => ['mode'=>..,'day'=>..]（只含主檔有自訂結帳日/模式的廠商）。 */
function eg_bm_settlement_map(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $map = [];
    try {
        $st = $db->query("SELECT maker_id_no, settlement_mode, settlement_day FROM maker_list
                          WHERE maker_id_no IS NOT NULL AND maker_id_no <> ''");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $day  = ($r['settlement_day'] === null || $r['settlement_day'] === '') ? null : (int)$r['settlement_day'];
            $mode = $r['settlement_mode'] ?: null;
            if ($day === null && $mode === null) continue;
            $map[(string)$r['maker_id_no']] = ['mode' => $mode, 'day' => $day];
        }
    } catch (Throwable $e) {}
    return $cache = $map;
}}

if (!function_exists('eg_bm_settlement_for')) {
/** 某廠商實際適用的結帳模式與結帳日：廠商自設優先，沒設用全域廠商預設。 */
function eg_bm_settlement_for(PDO $db, ?string $maker_id_no): array
{
    $def = eg_bm_default_settlement($db);
    if ($maker_id_no === null || trim((string)$maker_id_no) === '') {
        return ['mode' => $def['mode'], 'day' => $def['day'], 'source' => 'default'];
    }
    $map = eg_bm_settlement_map($db);
    $own = $map[trim((string)$maker_id_no)] ?? null;
    if (!$own) return ['mode' => $def['mode'], 'day' => $def['day'], 'source' => 'default'];
    $day  = $own['day']  !== null ? $own['day']  : $def['day'];
    $mode = $own['mode'] !== null ? $own['mode'] : $def['mode'];
    return ['mode' => $mode, 'day' => $day, 'source' => ($own['day'] !== null ? 'vendor' : 'default')];
}}

/* ══════════════════════ 計算 ══════════════════════ */

if (!function_exists('eg_bm_calc')) {
/**
 * 日期 + 結帳日 → 帳款月份 'YYYYMM'。
 *  FIXED：日 <= D → 當月帳；日 > D → 下個月帳（12 月自動跨到隔年 1 月）。
 *         D 超過當月天數時視同該月最後一天（例：D=31 的 2 月，2/28 仍算 2 月帳）。
 *  EOM  ：月底結帳＝該自然月就是該月帳。
 */
function eg_bm_calc(?string $date, int $day = 20, string $mode = 'FIXED'): ?string
{
    if ($date === null || $date === '') return null;
    $ts = strtotime($date);
    if (!$ts) return null;
    $y = (int)date('Y', $ts); $m = (int)date('n', $ts); $d = (int)date('j', $ts);

    if (strtoupper($mode) === 'EOM') return sprintf('%04d%02d', $y, $m);

    if ($day < 1)  $day = 1;
    if ($day > 31) $day = 31;
    $lastDay = (int)date('t', $ts);
    $cut = min($day, $lastDay);          // 結帳日超過當月天數 → 該月最後一天

    if ($d > $cut) {                     // 超過結帳日 → 下個月帳
        $m++;
        if ($m > 12) { $m = 1; $y++; }   // 12 月 → 隔年 1 月
    }
    return sprintf('%04d%02d', $y, $m);
}}

if (!function_exists('eg_bm_calc_row')) {
/** 一列 transfer_log（需含 transfer_no/transfer_date/maker_from）→ 帳款月份。 */
function eg_bm_calc_row(PDO $db, array $row): ?string
{
    $base = eg_bm_base_date($row['transfer_no'] ?? null, $row['transfer_date'] ?? null);
    if ($base === null) return null;
    $s = eg_bm_settlement_for($db, $row['maker_from'] ?? null);
    return eg_bm_calc($base, (int)$s['day'], (string)$s['mode']);
}}

if (!function_exists('eg_bm_ym_label')) {
/** 'YYYYMM' → 顯示用 'YYYY.MM'（比照 ai-rules/20 的日期顯示格式，只是到月）。 */
function eg_bm_ym_label(?string $ym): string
{
    if ($ym === null || !preg_match('/^\d{6}$/', (string)$ym)) return '';
    return substr($ym, 0, 4) . '.' . substr($ym, 4, 2);
}}

if (!function_exists('eg_bm_ym_shift')) {
/** 'YYYYMM' 平移 n 個月（可負），自動跨年。 */
function eg_bm_ym_shift(string $ym, int $n): ?string
{
    if (!preg_match('/^(\d{4})(\d{2})$/', $ym, $m)) return null;
    $y = (int)$m[1]; $mo = (int)$m[2] + $n;
    $y += intdiv($mo - 1, 12);
    $mo = ($mo - 1) % 12 + 1;
    if ($mo <= 0) { $mo += 12; $y--; }
    if ($y < 1990 || $y > 2200) return null;
    return sprintf('%04d%02d', $y, $mo);
}}

if (!function_exists('eg_bm_ym_valid')) {
function eg_bm_ym_valid($ym): bool
{
    return is_string($ym) && preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $ym)
        && (int)substr($ym, 0, 4) >= 1990 && (int)substr($ym, 0, 4) <= 2200;
}}

/* ══════════════════════ 批次寫入 ══════════════════════ */

if (!function_exists('eg_bm_fill')) {
/**
 * 自動計算並寫回 bill_ym。**手動指定過的列一律跳過**。
 * @param array $opt  transfer_ids=只算這幾列／transfer_nos=只算這些單號／only_empty=true 只補 bill_ym 空的
 * @return array ['scanned'=>,'updated'=>,'skipped_manual'=>,'no_date'=>]
 */
function eg_bm_fill(PDO $db, array $opt = []): array
{
    eg_bm_ensure_schema($db);
    $where = []; $args = [];
    if (isset($opt['transfer_ids'])) {
        $ids = array_values(array_filter(array_map('intval', (array)$opt['transfer_ids'])));
        if (!$ids) return ['scanned'=>0,'updated'=>0,'skipped_manual'=>0,'no_date'=>0];
        $where[] = 'transfer_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_merge($args, $ids);
    }
    if (isset($opt['transfer_nos'])) {
        $nos = array_values(array_unique(array_filter((array)$opt['transfer_nos'], 'strlen')));
        if (!$nos) return ['scanned'=>0,'updated'=>0,'skipped_manual'=>0,'no_date'=>0];
        $where[] = 'transfer_no IN (' . implode(',', array_fill(0, count($nos), '?')) . ')';
        $args = array_merge($args, $nos);
    }
    if (!empty($opt['only_empty'])) $where[] = "(bill_ym IS NULL OR bill_ym = '')";
    $where[] = 'bill_ym_manual = 0';                    // 手動指定過的不動
    $sql = "SELECT transfer_id, transfer_no, transfer_date, maker_from, bill_ym
            FROM bom_ing_transfer_log WHERE " . implode(' AND ', $where);

    $st = $db->prepare($sql);
    $st->execute($args);
    $upd = $db->prepare("UPDATE bom_ing_transfer_log SET bill_ym = ? WHERE transfer_id = ? AND bill_ym_manual = 0");

    $stat = ['scanned'=>0,'updated'=>0,'skipped_manual'=>0,'no_date'=>0];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $stat['scanned']++;
        $ym = eg_bm_calc_row($db, $r);
        if ($ym === null) { $stat['no_date']++; continue; }
        if ((string)$r['bill_ym'] === $ym) continue;
        $upd->execute([$ym, $r['transfer_id']]);
        $stat['updated']++;
    }
    if (!isset($opt['transfer_ids']) && !isset($opt['transfer_nos'])) {
        try { $stat['skipped_manual'] = (int)$db->query("SELECT COUNT(*) FROM bom_ing_transfer_log WHERE bill_ym_manual=1")->fetchColumn(); } catch (Throwable $e) {}
    }
    return $stat;
}}

if (!function_exists('eg_bm_set_manual')) {
/**
 * 批次「人工指定」帳款月份。$mode: 'set'＝指定成 $ym；'shift'＝在各自現值上平移 $shift 個月。
 * 一律標記 bill_ym_manual=1 並記錄修改人與時間。
 * @return array ['updated'=>,'skipped'=>]
 */
function eg_bm_set_manual(PDO $db, array $ids, string $mode, ?string $ym, int $shift, int $uid): array
{
    eg_bm_ensure_schema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return ['updated'=>0,'skipped'=>0];
    if ($mode === 'set' && !eg_bm_ym_valid($ym)) throw new InvalidArgumentException('帳款月份格式不正確（要 YYYYMM）');
    if ($mode === 'shift' && ($shift === 0 || $shift < -60 || $shift > 60)) throw new InvalidArgumentException('平移月數要在 -60 ~ 60 之間且不可為 0');

    $now = $db->query("SELECT NOW()")->fetchColumn();   // 時間戳一律取 DB 時間（PHP date() 是 UTC）
    $sel = $db->prepare("SELECT transfer_id, transfer_no, transfer_date, maker_from, bill_ym
                         FROM bom_ing_transfer_log WHERE transfer_id IN ("
                         . implode(',', array_fill(0, count($ids), '?')) . ")");
    $sel->execute($ids);
    $upd = $db->prepare("UPDATE bom_ing_transfer_log
                         SET bill_ym = ?, bill_ym_manual = 1, bill_ym_by = ?, bill_ym_at = ?
                         WHERE transfer_id = ?");
    $updated = 0; $skipped = 0;
    while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
        if ($mode === 'set') {
            $new = $ym;
        } else {
            $cur = ($r['bill_ym'] !== null && $r['bill_ym'] !== '') ? $r['bill_ym'] : eg_bm_calc_row($db, $r);
            if ($cur === null) { $skipped++; continue; }     // 連自動值都算不出來（沒日期）就不平移
            $new = eg_bm_ym_shift($cur, $shift);
            if ($new === null) { $skipped++; continue; }
        }
        $upd->execute([$new, $uid, $now, $r['transfer_id']]);
        $updated++;
    }
    return ['updated'=>$updated, 'skipped'=>$skipped];
}}

if (!function_exists('eg_bm_reset_auto')) {
/** 還原為自動：清掉手動註記並重算。 */
function eg_bm_reset_auto(PDO $db, array $ids): array
{
    eg_bm_ensure_schema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return ['updated'=>0,'no_date'=>0];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE bom_ing_transfer_log SET bill_ym_manual = 0, bill_ym_by = NULL, bill_ym_at = NULL
                  WHERE transfer_id IN ($ph)")->execute($ids);
    $stat = eg_bm_fill($db, ['transfer_ids' => $ids]);
    return ['updated' => count($ids), 'no_date' => $stat['no_date']];
}}

/* ══════════════════════ 權限 ══════════════════════ */

if (!function_exists('eg_bm_current_user')) {
function eg_bm_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    try {
        $st = $db->prepare("SELECT id, user_cname, user_status, state FROM `user` WHERE user_uname=?");
        $st->execute([$uname]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}}

if (!function_exists('eg_bm_perms')) {
/**
 * isAdmin  系統管理者（固定全權）
 * canAdmin 製程移轉管理員：批次修改＋還原＋重算
 * canEdit  帳款月份維護：批次修改＋還原
 * 檢視不需要角色（維持本頁原本行為）。
 */
function eg_bm_perms(PDO $db, ?array $u): array
{
    $none = ['isAdmin'=>false, 'canAdmin'=>false, 'canEdit'=>false, 'uid'=>0, 'name'=>''];
    if (!$u) return $none;
    $uid   = (int)$u['id'];
    $state = (int)($u['state'] ?? 0);
    $ustat = (int)($u['user_status'] ?? 0);
    if ($state === 0 || $ustat === 90) return $none;      // 離職／特殊帳號一律擋（fail-closed）

    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;         // 超級管理員固定 id=1

    $has = function (array $codes) use ($db, $uid) {
        $in = implode(',', array_fill(0, count($codes), '?'));
        try {
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                WHERE ur.user_id=? AND r.module='proc_transfer' AND r.role_code IN ($in) LIMIT 1");
            $st->execute(array_merge([$uid], $codes));
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    };
    $canAdmin = $isAdmin || $has(['ptl_admin']);
    $canEdit  = $canAdmin || $has(['ptl_bill_edit']);
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canEdit'=>$canEdit,
            'uid'=>$uid, 'name'=>(string)($u['user_cname'] ?? '')];
}}

/* ══════════════════════ CSRF ══════════════════════ */

if (!function_exists('eg_bm_csrf_token')) {
function eg_bm_csrf_token(): string
{
    if (empty($_SESSION['bm_csrf'])) $_SESSION['bm_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['bm_csrf'];
}}
if (!function_exists('eg_bm_csrf_ok')) {
function eg_bm_csrf_ok(?string $t): bool
{
    return $t !== null && hash_equals((string)($_SESSION['bm_csrf'] ?? ''), (string)$t);
}}
