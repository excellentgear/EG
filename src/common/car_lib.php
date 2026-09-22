<?php
/**
 * car_lib.php — 異常矯正處理單 (CAR / Corrective Action Report) 共用函式庫
 *
 * 被 views/QA/correction_order.php 與 src/store/store_CAR_API.php 共用。
 * 僅提供函式，不啟動 session、不輸出內容。呼叫端需自備 $pdo (PDO)。
 *
 * 關聯資料表：car_order / car_signature / car_attachment / car_activity_log / car_seq
 * 設定存放：qa_system_settings（key 以 car_ 開頭）
 */

require_once __DIR__ . '/user_active_lib.php';   // 回覆人是否已離職／留停（重新指派判定）
require_once __DIR__ . '/rbac.php';             // 補資料權限判定（rbac_has）

if (!function_exists('car_labels')) {

/** 中文標籤對照（DB 一律存 ASCII code，顯示時轉中文） */
function car_labels(): array {
    return [
        'source_type' => [
            'QA'    => '品質異常處理單',
            'IR'    => '客戶退貨單',
            'OTHER' => '其他',
        ],
        'counterparty_type' => [
            'customer' => '客戶',
            'maker'    => '廠商',
        ],
        'resp_type' => [
            'dept'          => '本公司部門',
            'maker'         => '廠商',
            'own_customer'  => '本公司',
        ],
        'cause' => [
            'person'   => '人員',
            'material' => '物料',
            'machine'  => '機器',
            'method'   => '方法',
            'tool'     => '工具',
            'other'    => '其他',
        ],
        'disposition' => [
            'special_accept' => '特採',
            'rework'         => '重工',
            'scrap'          => '報廢',
            'return'         => '退料',
            'other'          => '其他',
        ],
        'status' => [
            'draft'           => '已撤回',
            'applying'       => '申請中',
            'app_rejected'   => '申請退回',
            'open'            => '待指派',
            'assigned'       => '待回覆',
            'replying'       => '填寫中',
            'pending_primary'=> '待主管簽核',
            'pending_final'  => '待總經理裁決',
            'closed'         => '已結案',
            'rejected'       => '不可結案',
        ],
    ];
}

/** 取單一 CAR 設定值 */
function car_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM qa_system_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

/** 寫入 / 更新一個 CAR 設定值 */
function car_setting_set(PDO $pdo, string $key, string $value, ?int $uid = null): void {
    $st = $pdo->prepare(
        "INSERT INTO qa_system_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
    );
    $st->execute([$key, $value, $uid]);
}

/** 取全部 car_ 設定成關聯陣列 */
function car_settings_all(PDO $pdo): array {
    $out = [];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM qa_system_settings WHERE setting_key LIKE 'car\\_%'")
                    ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) { $out[$r['setting_key']] = $r['setting_value']; }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 原子性配發 N 個當日流水號。
 * 格式：YYYYMMDD + 至少兩位流水號（>99 自動變三位）。
 * 用 car_seq 資料表 + SELECT ... FOR UPDATE 序列化，杜絕並發撞號。
 * 可帶入既有交易（呼叫端已 beginTransaction 則沿用，不自行 commit）。
 * @return string[] 例如 ['2026070801','2026070802','2026070803']
 */
function car_alloc_numbers(PDO $pdo, int $n = 1, ?string $ymd = null): array {
    if ($n < 1) $n = 1;
    if ($ymd === null)  $ymd  = date('Ymd');
    $dateSql = date('Y-m-d', strtotime($ymd));

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // 確保當日列存在（INSERT IGNORE 原子，多執行緒僅一筆成功）
        $pdo->prepare("INSERT IGNORE INTO car_seq (seq_date, last_no) VALUES (?, 0)")->execute([$dateSql]);
        // 鎖住當日列，序列化配號
        $sel = $pdo->prepare("SELECT last_no FROM car_seq WHERE seq_date = ? FOR UPDATE");
        $sel->execute([$dateSql]);
        $last = (int)$sel->fetchColumn();
        $start = $last + 1;
        $pdo->prepare("UPDATE car_seq SET last_no = last_no + ? WHERE seq_date = ?")->execute([$n, $dateSql]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $ymd . str_pad((string)($start + $i), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

/** 產生退件（不可結案）之 R 編號：母號 + R + 兩位版次，例如 2026070801R01 */
function car_reissue_no(string $baseNo, int $seq): string {
    return $baseNo . 'R' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

/** 由 session 取得目前使用者 [id, name]。name 一律取中文姓名(user_cname)，而非登入帳號。 */
function car_current_user(PDO $pdo): array {
    $uid = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
    $name = '';
    if ($uid) {
        try {
            $st = $pdo->prepare("SELECT user_cname FROM user WHERE id = ?");
            $st->execute([$uid]);
            $name = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }
    if ($name === '') $name = (string)($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? '');
    return ['id' => $uid, 'name' => $name];
}

/** 取某使用者主要「部門/職稱」字串（簽章顯示用），例如「業務部/會計」 */
function car_user_title(PDO $pdo, ?int $uid): string {
    if (!$uid) return '';
    try {
        $st = $pdo->prepare("SELECT d.name AS dn, p.name AS pn
                             FROM user_department_position_map m
                             JOIN department d ON d.id = m.department_id
                             JOIN position p ON p.id = m.position_id
                             WHERE m.user_id = ? ORDER BY m.is_main DESC LIMIT 1");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ($r['dn'] . '/' . $r['pn']) : '';
    } catch (Throwable $e) { return ''; }
}

/** 今日簽章日期戳，格式 2026.07.08 */
function car_sign_date_label(): string {
    return date('Y.m.d');
}

/** 寫一筆活動軌跡（前端時間軸用） */
function car_log(PDO $pdo, int $carId, string $action, ?int $actorId, ?string $actorName, ?string $note = null): void {
    $st = $pdo->prepare(
        "INSERT INTO car_activity_log (car_id, action, actor_id, actor_name, note) VALUES (?, ?, ?, ?, ?)"
    );
    $st->execute([$carId, $action, $actorId, $actorName, $note]);
}

/**
 * 取某部門「主管」名單。
 * 主管定義：職稱層級 pl.level 於門檻(含)之上。level 1=最高階，數字越小階級越高，
 * 故「門檻以上(含)」= pl.level <= $minLevel。
 */
function car_dept_supervisors(PDO $pdo, int $deptId, ?int $minLevel = null): array {
    if ($minLevel === null) $minLevel = (int)(car_setting($pdo, 'car_supervisor_min_level', '2') ?: 2);
    $sql = "SELECT DISTINCT u.id, u.user_cname, p.name AS position_name, pl.level
            FROM user_department_position_map m
            JOIN user u ON u.id = m.user_id
            JOIN position p ON p.id = m.position_id
            JOIN position_level pl ON pl.position_id = p.id
            WHERE m.department_id = ?
              AND pl.level IS NOT NULL AND pl.level <= ?
              AND u.state IN (1, 99)
            ORDER BY pl.level ASC, u.user_cname ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$deptId, $minLevel]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 取某使用者所有(部門,職務)身分，含層級與是否主管職 */
function car_user_positions(PDO $pdo, int $uid): array {
    $min = (int)(car_setting($pdo, 'car_supervisor_min_level', '2') ?: 2);
    $sql = "SELECT m.department_id AS dept_id, d.name AS dept_name,
                   m.position_id, p.name AS position_name, pl.level, m.is_main
            FROM user_department_position_map m
            JOIN department d ON d.id = m.department_id
            JOIN position p ON p.id = m.position_id
            LEFT JOIN position_level pl ON pl.position_id = p.id
            WHERE m.user_id = ?
            ORDER BY m.is_main DESC, pl.level ASC";
    $st = $pdo->prepare($sql); $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $lv = $r['level'];
        $r['is_supervisor'] = ($lv !== null && (int)$lv <= $min);
    }
    unset($r);
    return $rows;
}

/** 某職務(position)是否屬主管職（層級門檻含以上） */
function car_position_is_supervisor(PDO $pdo, int $positionId, ?int $minLevel = null): bool {
    if ($minLevel === null) $minLevel = (int)(car_setting($pdo, 'car_supervisor_min_level', '2') ?: 2);
    $st = $pdo->prepare("SELECT level FROM position_level WHERE position_id = ? LIMIT 1");
    $st->execute([$positionId]);
    $lv = $st->fetchColumn();
    return ($lv !== false && $lv !== null && (int)$lv <= $minLevel);
}

/** 取生管部門(可多個)之主管名單（責任單位為廠商時的首要決策者） */
function car_pm_supervisors(PDO $pdo): array {
    $ids = json_decode(car_setting($pdo, 'car_pm_dept_ids', '[]'), true);
    if (!is_array($ids) || !$ids) return [];
    $out = [];
    foreach ($ids as $d) {
        foreach (car_dept_supervisors($pdo, (int)$d) as $r) { $out[$r['id']] = $r; }
    }
    return array_values($out);
}

/** 取最終決策者(總經理)使用者名單：依設定的職位名稱比對 */
function car_final_deciders(PDO $pdo): array {
    $pos = trim(car_setting($pdo, 'car_final_decider_position', ''));
    if ($pos === '') return [];
    $sql = "SELECT DISTINCT u.id, u.user_cname
            FROM user_department_position_map m
            JOIN user u ON u.id = m.user_id
            JOIN position p ON p.id = m.position_id
            WHERE p.name = ? AND u.state IN (1, 99)";
    $st = $pdo->prepare($sql);
    $st->execute([$pos]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 回覆內容(原因分析/矯正/預防)的簽章顯示名稱。
 * 責任單位為廠商時：由生管代填，但簽章壓「廠商名稱」；否則壓填寫者本人。
 */
function car_reply_signer_name(PDO $pdo, array $o, string $fallback): string {
    if (($o['resp_type'] ?? '') === 'maker') {
        if (!empty($o['resp_maker_id'])) {
            $st = $pdo->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no = ?");
            $st->execute([$o['resp_maker_id']]);
            $n = $st->fetchColumn();
            if ($n) return $n;
        }
        if (!empty($o['resp_display'])) return preg_replace('/^廠商：/', '', $o['resp_display']);
    }
    return $fallback;
}

/**
 * 現任回覆人是否已無法作業（離職／留職停薪／育嬰留停／預定離職日已過）。
 * 判定沿用全站唯一實作 eg_user_blocked_state()（＝登入被擋的同一套規則），不自己比 state。
 * @return array|null 被擋回 ['state'=>0,'label'=>'離職','name'=>'王小明']；仍可作業或無回覆人回 null
 */
function car_assignee_blocked(PDO $pdo, array $o): ?array {
    $uid = (int)($o['assigned_to'] ?? 0);
    if ($uid <= 0) return null;
    $b = eg_user_blocked_state($pdo, $uid);
    return $b ?: null;
}

/** 此單目前是否可由主管「重新指派」（＝已指派出去、但現任回覆人已離職／留停） */
function car_can_reassign_order(PDO $pdo, array $o): bool {
    if (!in_array(($o['status'] ?? ''), ['assigned', 'replying'], true)) return false;
    return car_assignee_blocked($pdo, $o) !== null;
}

/**
 * 首要決策者候選名單（責任部門主管；廠商責任→生管主管）。
 * 全站唯一實作——car_notify.php 的 car_primary_recipients() 轉呼叫本函式，不要再寫第二份。
 * @return int[] user id
 */
function car_primary_pool_ids(PDO $pdo, array $o): array {
    $out = [];
    if (($o['resp_type'] ?? '') === 'maker') {
        foreach (car_pm_supervisors($pdo) as $s) $out[] = (int)$s['id'];
    } elseif (!empty($o['resp_dept_id'])) {
        foreach (car_dept_supervisors($pdo, (int)$o['resp_dept_id']) as $s) $out[] = (int)$s['id'];
    }
    return array_values(array_unique(array_filter($out)));
}

/**
 * 扣除迴避者後的首要決策者名單。
 * 主管把單指派給自己親自回覆時，不可以自己簽核自己填的內容（SoD）：
 * 由同單位其他主管簽；一個都沒有時回空陣列，呼叫端（submit_reply）直接跳總經理裁決。
 * @return int[]
 */
function car_primary_pool_excluding(PDO $pdo, array $o, int $excludeUid): array {
    return array_values(array_diff(car_primary_pool_ids($pdo, $o), [$excludeUid]));
}

/** 判斷使用者是否為某記錄的合格「指派者」（責任部門主管；廠商責任→生管主管） */
function car_can_assign_order(PDO $pdo, array $o, int $uid): bool {
    // 待指派＝正常指派；已指派但回覆人離職／留停＝開放重新指派（使用者 2026-09-17 定調）
    if (($o['status'] ?? '') !== 'open' && !car_can_reassign_order($pdo, $o)) return false;
    $rtype = $o['resp_type'] ?? '';
    if ($rtype === 'dept' && !empty($o['resp_dept_id'])) {
        foreach (car_dept_supervisors($pdo, (int)$o['resp_dept_id']) as $s) if ((int)$s['id'] === $uid) return true;
    } elseif ($rtype === 'maker') {
        foreach (car_pm_supervisors($pdo) as $s) if ((int)$s['id'] === $uid) return true;
    }
    return false;
}

/** 是否為某單的「首要決策者」候選：責任部門主管；廠商責任→生管主管（回覆人本人迴避） */
function car_is_primary_candidate(PDO $pdo, array $o, int $uid): bool {
    $pool = car_primary_pool_ids($pdo, $o);
    if (!in_array($uid, $pool, true)) return false;
    // SoD：主管自己填的單不可自己簽核——但同單位再無其他主管時不擋死
    // （那種單在送出時就已直接跳總經理裁決，這裡只是不讓舊資料卡住）
    if ((int)($o['assigned_to'] ?? 0) === $uid && car_primary_pool_excluding($pdo, $o, $uid)) return false;
    return true;
}

/**
 * 是否為「管理課扣款判定」人員。
 * 有指定判定人員（car_admin_user_ids，上限 2 人）時：僅指定者可判定；
 * 未指定時：管理課課室（car_admin_dept_ids）成員皆可。
 */
function car_is_admin_deduct(PDO $pdo, int $uid): bool {
    $uids = json_decode(car_setting($pdo, 'car_admin_user_ids', '[]'), true);
    if (is_array($uids) && $uids) {
        return in_array($uid, array_map('intval', $uids), true);
    }
    $depts = json_decode(car_setting($pdo, 'car_admin_dept_ids', '[]'), true);
    if (is_array($depts) && $depts) {
        $in = implode(',', array_map('intval', $depts));
        $st = $pdo->prepare("SELECT 1 FROM user_department_position_map WHERE user_id = ? AND department_id IN ($in) LIMIT 1");
        $st->execute([$uid]);
        if ($st->fetchColumn()) return true;
    }
    return false;
}

/**
 * 是否為某單的「當事人」：填表人/被指派回覆人/責任部門主管/生管主管(廠商責任)/
 * 開單部門主管(申請核准)/最終決策者/管理課判定人員。
 * 當事人即使沒有 car_view 權限，也可透過通知連結開啟並處理自己的單。
 */
function car_is_stakeholder(PDO $pdo, array $o, int $uid): bool {
    if ((int)($o['created_by'] ?? 0) === $uid) return true;
    if ((int)($o['assigned_to'] ?? 0) === $uid) return true;
    if ((int)($o['resp_person_id'] ?? 0) === $uid) return true;
    if (car_is_primary_candidate($pdo, $o, $uid)) return true;
    if (!empty($o['opener_dept_id'])) {
        foreach (car_dept_supervisors($pdo, (int)$o['opener_dept_id']) as $s) if ((int)$s['id'] === $uid) return true;
    }
    if (car_is_final_decider($pdo, $uid)) return true;
    if (car_is_admin_deduct($pdo, $uid)) return true;
    return false;
}

/** 是否為「最終決策者」（總經理）：職位名稱符合 car_final_decider_position 設定 */
function car_is_final_decider(PDO $pdo, int $uid): bool {
    foreach (car_final_deciders($pdo) as $d) if ((int)$d['id'] === $uid) return true;
    return false;
}

/** 由母號取下一個退件 R 號（母號本身可能已是 R 單，一律以去 R 後的基底計序） */
function car_next_reissue_no(PDO $pdo, string $carNo): array {
    $base = preg_replace('/R\d+$/', '', $carNo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM car_order WHERE car_no LIKE ?");
    $st->execute([$base . 'R%']);
    $seq = (int)$st->fetchColumn() + 1;
    return [car_reissue_no($base, $seq), $seq];
}

/** 取某單各區段目前是否已簽（同區段取最後一筆，未作廢才算已簽） */
function car_signed_map(PDO $pdo, int $carId): array {
    $s = $pdo->prepare("SELECT section, revoked FROM car_signature WHERE car_id = ? ORDER BY id");
    $s->execute([$carId]);
    $m = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $m[$r['section']] = ((int)$r['revoked'] === 0);
    return $m;
}

/** 區段中文名（軌跡/訊息用） */
function car_section_name(string $sec): string {
    return ['desc' => '異常說明', 'cause' => '異常原因分析', 'correction' => '矯正措施',
            'prevention' => '預防措施', 'primary' => '主管簽核', 'final' => '總經理裁決'][$sec] ?? $sec;
}

/** 本公司全名（發票用）：customer_list.is_own_company=1 之 customer_full（印章上弧顯示用） */
function car_own_company_full(PDO $pdo): string {
    try {
        $v = $pdo->query("SELECT COALESCE(customer_full, customer) FROM customer_list
                          WHERE is_own_company = 1 AND (is_inactive IS NULL OR is_inactive = 0) LIMIT 1")->fetchColumn();
        return (string)($v ?: '');
    } catch (Throwable $e) { return ''; }
}

/** 依 counterparty_type + id 取顯示名稱（含 [客]/[廠] 標示） */
function car_counterparty_display(PDO $pdo, ?string $type, ?string $id): string {
    if (!$type || !$id) return '';
    if ($type === 'customer') {
        $st = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id = ?");
        $st->execute([$id]);
        $n = $st->fetchColumn();
        return $n ? "[客] $n" : "[客] $id";
    }
    if ($type === 'maker') {
        $st = $pdo->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no = ?");
        $st->execute([$id]);
        $n = $st->fetchColumn();
        return $n ? "[廠] $n" : "[廠] $id";
    }
    return '';
}

/**
 * 取行事曆「休假日(s)／補班日(m)」日期集合（靜態快取，整批載入一次）。
 * 資料來源同 views/pages/calendar.php：evenement JOIN event_category.day_type。
 * @return array ['holidays' => [Y-m-d=>true...], 'makeups' => [Y-m-d=>true...]]
 */
function car_holiday_sets(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $holidays = []; $makeups = [];
    try {
        $rows = $pdo->query(
            "SELECT DATE(e.start) AS d1, DATE(COALESCE(e.end, e.start)) AS d2, ec.day_type
             FROM evenement e JOIN event_category ec ON e.category_id = ec.id
             WHERE ec.day_type IN ('s','m')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $d = strtotime((string)$r['d1']); $end = strtotime((string)$r['d2']);
            if ($d === false || $end === false || $end < $d) continue;
            $guard = 0;   // 防呆：單一事件跨距上限約一年
            while ($d <= $end && $guard++ < 400) {
                $key = date('Y-m-d', $d);
                if ($r['day_type'] === 's') $holidays[$key] = true; else $makeups[$key] = true;
                $d = strtotime('+1 day', $d);
            }
        }
    } catch (Throwable $e) {}
    $cache = ['holidays' => $holidays, 'makeups' => $makeups];
    return $cache;
}

/**
 * 兩日期之間的「工作天數」（不含起算日、含迄日）。
 * 規則同行事曆：週六、週日與休假日(s)不算；補班日(m)算（補班優先於週末）。
 * @param string      $fromDate 進入狀態日（Y-m-d，可含時間，只取日期）
 * @param string|null $toDate   迄日，預設今日
 * @return int toDate <= fromDate 時回傳 0
 */
function car_working_days_between(PDO $pdo, string $fromDate, ?string $toDate = null): int {
    $from = strtotime(substr($fromDate, 0, 10));
    $to   = strtotime(substr(($toDate ?: date('Y-m-d')), 0, 10));
    if ($from === false || $to === false || $to <= $from) return 0;
    $sets = car_holiday_sets($pdo);
    $count = 0; $cur = strtotime('+1 day', $from); $guard = 0;
    while ($cur <= $to && $guard++ < 4000) {
        $key = date('Y-m-d', $cur);
        $dow = (int)date('w', $cur);   // 0=週日, 6=週六
        $isWeekend = ($dow === 0 || $dow === 6);
        if (isset($sets['makeups'][$key]) || (!$isWeekend && !isset($sets['holidays'][$key]))) $count++;
        $cur = strtotime('+1 day', $cur);
    }
    return $count;
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 補資料（代填單據／代簽圖章／調整簽章日期）—— 全站唯一實作
 * ──────────────────────────────────────────────────────────────────────────────
 * 2026-09-22 使用者要求：「要可以另外開超級管理員權限，可以代填單據與代簽圖章(可設定
 * 人員)與修改/調整簽核日期，需要輸入管理員操作密碼後可執行，方便補資料。」
 *
 * 四個刻意這樣做的地方：
 *  1. **兩道門而不是一道**：①角色功能碼 `car_backfill`（或系統管理員 all）②每一張單各自
 *     輸入一次**操作確認密碼**（`confirm_password_lib`，action_key=CAR_BF_ACTION，錯三次
 *     鎖七天）。只靠角色＝任何被指派到那個角色的人都能無聲改掉已結案單據的簽章。
 *  2. **解鎖逐張單、逐人、有有效期**（比照 order_track_perm_lib 的客戶解鎖）：否則解鎖
 *     一次之後這個人當天改任何一張單的簽章都不必再驗密碼。
 *  3. **`car_can_backfill()` 不 fail-open**：`rbac_user_features()` 對「系統尚無管理員」
 *     的情況會回全權，那是開站用的 bootstrap；補資料是繞過整條流程的動作，寧可擋下。
 *  4. **代簽不是代理簽核**：章面壓的是**當年紙本上那個人**的姓名與日期，所以
 *     **不加 ai-rules/18 的「代」字**（那是代理人代簽本人職務時才加的）。誰在什麼時候
 *     補的，記在 `car_activity_log` 與 `audit_log`，不寫在章面上。
 * ════════════════════════════════════════════════════════════════════════════ */

if (!defined('CAR_BF_UNLOCK_TTL')) define('CAR_BF_UNLOCK_TTL', 1800);       // 解鎖有效秒數（30 分鐘）
if (!defined('CAR_BF_ACTION'))     define('CAR_BF_ACTION', 'car_backfill'); // 操作確認密碼的用途代碼（錯誤次數／鎖定按用途分開計）

/**
 * 可補簽的「章格」登記表（唯一登記處；新增章格只改這裡）。
 *  kind=sig → 寫 `car_signature`（section 就是 key，**必須是該欄 ENUM 既有的值**）
 *  kind=col → 寫 `car_order` 上的欄位（扣款判定的章是讀 deduct_by_name/deduct_at 畫出來的，
 *             不在 car_signature 裡，硬塞會違反 ENUM）
 *  ord     → 章面時間的先後順序（見 car_bf_time()），也是畫面上的排列順序
 */
function car_bf_slots(): array {
    return [
        'desc'       => ['label' => '異常說明（填表人）',   'kind' => 'sig', 'ord' => 1],
        'cause'      => ['label' => '異常原因分析',         'kind' => 'sig', 'ord' => 2],
        'correction' => ['label' => '矯正措施',             'kind' => 'sig', 'ord' => 3],
        'prevention' => ['label' => '預防措施',             'kind' => 'sig', 'ord' => 4],
        'primary'    => ['label' => '主管簽核',             'kind' => 'sig', 'ord' => 5,
                         'by' => 'primary_by', 'at' => 'primary_at'],
        'final'      => ['label' => '總經理核准',           'kind' => 'sig', 'ord' => 6,
                         'by' => 'final_by',   'at' => 'final_at'],
        'deduct'     => ['label' => '扣款判定（管理課）',   'kind' => 'col', 'ord' => 7,
                         'by' => 'deduct_by', 'name' => 'deduct_by_name', 'at' => 'deduct_at'],
    ];
}

/**
 * 一張「已結案」的單，紙本上這幾格一定要有章（扣款判定除外——那是結案之後、而且不一定要扣款）。
 * @return string[] 缺章的章格代碼
 */
function car_bf_missing_signs(PDO $pdo, array $o): array {
    $need = ['desc', 'cause', 'correction', 'prevention', 'primary', 'final'];
    $map  = car_signed_map($pdo, (int)$o['id']);      // section => 是否已簽（未作廢）
    $miss = [];
    foreach ($need as $k) if (empty($map[$k])) $miss[] = $k;
    return $miss;
}

/** 缺章清單轉成看得懂的一句話（沒有缺就回空字串） */
function car_bf_missing_text(array $miss): string {
    if (!$miss) return '';
    $sl = car_bf_slots();
    $names = array_map(fn($k) => $sl[$k]['label'] ?? $k, $miss);
    return '這張單已設為結案，但還有 ' . count($miss) . ' 格沒有簽章：' . implode('、', $names);
}

/** 是否具備「補資料」功能碼（系統管理員 all 亦可）。刻意不 fail-open，見本區塊註解第3點。 */
function car_can_backfill(array $features): bool {
    return rbac_has($features, CAR_BF_ACTION);
}

/** 補資料解鎖狀態（逐人、逐張單、逾時自動失效） */
function car_bf_unlock_mark(int $uid, int $carId): void {
    if (!isset($_SESSION['car_bf_unlock']) || !is_array($_SESSION['car_bf_unlock'])) $_SESSION['car_bf_unlock'] = [];
    $_SESSION['car_bf_unlock'][$uid . ':' . $carId] = time();
}
function car_bf_unlock_valid(int $uid, int $carId): bool {
    $k = $uid . ':' . $carId;
    $t = (int)($_SESSION['car_bf_unlock'][$k] ?? 0);
    if (!$t) return false;
    if (time() - $t > CAR_BF_UNLOCK_TTL) { unset($_SESSION['car_bf_unlock'][$k]); return false; }
    return true;
}
function car_bf_unlock_left(int $uid, int $carId): int {
    $t = (int)($_SESSION['car_bf_unlock'][$uid . ':' . $carId] ?? 0);
    if (!$t) return 0;
    $left = $t + CAR_BF_UNLOCK_TTL - time();
    return $left > 0 ? $left : 0;
}
function car_bf_unlock_clear(int $uid, int $carId): void {
    unset($_SESSION['car_bf_unlock'][$uid . ':' . $carId]);
}

/** Y-m-d 格式檢查（補資料的日期一律走這支，不要各處自己 preg） */
function car_bf_is_date(string $d): bool {
    return (bool)preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $d);
}

/**
 * 今天（以 **DB 的本地時間** 為準）。
 * **不可以用 PHP 的 date('Y-m-d')**：本站 PHP 跑 UTC、MySQL 跑本地（實測差 8 小時），
 * 所以本地時間 00:00~07:59 之間 PHP 還停在前一天——「日期不可以是未來」的檢查會把
 * 今天的日期整批擋下來，而且看起來像使用者選錯日期。同一個坑 2026-08-18 公出單踩過。
 */
function car_db_today(PDO $pdo): string {
    static $d = null;
    if ($d === null) {
        try { $d = (string)$pdo->query("SELECT CURDATE()")->fetchColumn(); }
        catch (Throwable $e) { $d = date('Y-m-d'); }
    }
    return $d;
}

/**
 * 這個人在「那一天」是不是在職（補簽的守門）。
 * 走 eg_people_list_asof()：帶 asof 時**當時在職、現在已離職的人也會在名單裡**，補歷史
 * 紙本才挑得到當年的人（ai-rules/22 第5坑）；用現況清單會整批挑不到又完全不報錯。
 */
function car_bf_user_asof_ok(PDO $pdo, int $uid, string $date): bool {
    if ($uid <= 0 || !car_bf_is_date($date)) return false;
    require_once __DIR__ . '/people_lib.php';
    static $cache = [];
    if (!isset($cache[$date])) {
        $ids = [];
        foreach (eg_people_list_asof($pdo, [], $date) as $r) $ids[(int)$r['id']] = 1;
        $cache[$date] = $ids;
    }
    return isset($cache[$date][$uid]);
}

/** 某人在「那一天」的部門/職稱（章面旁的說明文字用；回推不到才退回現況） */
function car_user_title_asof(PDO $pdo, ?int $uid, string $date): string {
    if (!$uid) return '';
    if (!car_bf_is_date($date)) return car_user_title($pdo, (int)$uid);
    try {
        require_once __DIR__ . '/position_history_lib.php';
        $snap = eg_position_snapshot_at($pdo, (int)$uid, $date);
        if ($snap) {
            $best = $snap[0];
            foreach ($snap as $s) if (!empty($s['is_main'])) { $best = $s; break; }
            /* 名稱一律取「目前設定值」，只有 id 真的被刪掉才退回快照裡凍結的舊名——
               改名不是改組織（部門早就由「部」改成「課」），同一條規則見 eg_people_list_asof()。 */
            $dn = (string)($best['department_name'] ?? '');
            $pn = (string)($best['position_name'] ?? '');
            $q = $pdo->prepare("SELECT name FROM department WHERE id = ?");
            $q->execute([(int)$best['department_id']]);
            $cur = $q->fetchColumn(); if ($cur !== false && $cur !== null) $dn = (string)$cur;
            $q = $pdo->prepare("SELECT name FROM position WHERE id = ?");
            $q->execute([(int)$best['position_id']]);
            $cur = $q->fetchColumn(); if ($cur !== false && $cur !== null) $pn = (string)$cur;
            if ($dn !== '' || $pn !== '') return $dn . '/' . $pn;
        }
    } catch (Throwable $e) {}
    return car_user_title($pdo, (int)$uid);
}

/**
 * 補簽要用的時間戳：**日期由補登者指定**，時間則排在同一天其他章格之間（ai-rules/21 第3條）。
 * 下界＝同一天「表單順序在我前面」那些章的最晚時間（沒有就當天 09:00）；
 * 上界＝同一天「順序在我後面」那些章的最早時間（沒有就當天 23:59）。
 * 這樣不管管理員是照順序補還是跳著補，印出來都不會出現「總經理核准早於填表人」。
 */
function car_bf_time(PDO $pdo, int $carId, array $o, string $slot, string $date): string {
    $slots = car_bf_slots();
    $myOrd = (int)($slots[$slot]['ord'] ?? 99);
    $lower = strtotime($date . ' 09:00:00');
    $upper = strtotime($date . ' 23:59:00');

    $have = [];
    try {
        $st = $pdo->prepare("SELECT section, signed_at FROM car_signature
                             WHERE car_id = ? AND revoked = 0 AND signed_at IS NOT NULL");
        $st->execute([$carId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (substr((string)$r['signed_at'], 0, 10) !== $date) continue;
            $ord = $slots[$r['section']]['ord'] ?? null;
            if ($ord === null || (int)$ord === $myOrd) continue;
            $have[] = [(int)$ord, (int)strtotime((string)$r['signed_at'])];
        }
    } catch (Throwable $e) {}
    $dAt = trim((string)($o['deduct_at'] ?? ''));
    if ($dAt !== '' && substr($dAt, 0, 10) === $date && $myOrd !== (int)$slots['deduct']['ord']) {
        $have[] = [(int)$slots['deduct']['ord'], (int)strtotime($dAt)];
    }
    foreach ($have as $h) {
        if ($h[0] < $myOrd) { if ($h[1] > $lower) $lower = $h[1]; }
        else                { if ($h[1] < $upper) $upper = $h[1]; }
    }

    if ($upper <= $lower) {                       // 同一天章格已經排滿（時間擠在一起）→ 貼著下界放，不跨日
        return date('Y-m-d H:i:s', min($lower + 60, strtotime($date . ' 23:59:00')));
    }
    $ts = $lower + random_int(5, 180) * 60;       // 5 分～3 小時隨機錯開（ai-rules/21）
    if ($ts >= $upper) $ts = $lower + (int)(($upper - $lower) / 2);
    if ($ts <= $lower) $ts = $lower + 60;
    return date('Y-m-d H:i:s', min($ts, $upper));
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 異常原因分類／處置方式 —— 一律沿用品質異常處理單的那兩張代碼表
 * ──────────────────────────────────────────────────────────────────────────────
 * 2026-09-22 使用者要求：「原因調查只能勾選一個、不可複選；內容要跟異常單一樣可以多層
 * 選擇（選單要同異常單內的異常原因分類）；處置方式也要跟異常單的選單相同。」
 *
 * 所以 CAR **不自己建一份分類表**，直接讀 `qa_cause_cat`（三層，管理員在異常單設定頁維護）
 * 與 `qa_option`（kind=disp）；讀取走 qa_abnormal_lib 的唯一實作（`qab_cause_map()`／
 * `qab_cause_tree()`／`qab_options()`／`qab_option_map()`），不在這裡再寫一份查詢
 * ——否則管理員改名／加一層之後，兩張單會顯示不一樣的東西（鐵律4）。
 *
 * 舊資料相容：`car_order.cause_investigation`(SET) 與 `disposition`(ENUM) 保留不動，
 * 只在新欄位是 NULL 時當退路顯示；新存檔一律寫 `cause_cat_id`／`disposition_opt_id`
 * 並把同區段的舊欄位清掉（不然畫面上會同時有兩個「目前選的」，分不出哪個才算）。
 * ════════════════════════════════════════════════════════════════════════════ */

/** 確保 car_order 上的兩個新欄位存在（一個 request 只檢查一次；**不可在交易中呼叫**，DDL 會隱式 commit） */
function car_ensure_cause_cols(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM car_order")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['Field']] = 1;
        if (!isset($cols['cause_cat_id'])) {
            $pdo->exec("ALTER TABLE car_order ADD COLUMN cause_cat_id INT NULL
                        COMMENT '異常原因分類(qa_cause_cat.cat_id)，單選；NULL 且有 cause_investigation=舊資料'
                        AFTER cause_investigation");
        }
        if (!isset($cols['disposition_opt_id'])) {
            $pdo->exec("ALTER TABLE car_order ADD COLUMN disposition_opt_id INT NULL
                        COMMENT '處置方式(qa_option.opt_id, kind=disp)，單選；NULL 且有 disposition=舊資料'
                        AFTER disposition");
        }
    } catch (Throwable $e) {}
}

/** 載入異常單共用庫（只在真的要用代碼表時才載，避免每支 API 都多吃一個 1600 行的檔案） */
function car_qab_lib(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    require_once __DIR__ . '/qa_abnormal_lib.php';
}

/** 異常原因分類：三層樹（給勾選介面用；只列啟用中的） */
function car_cause_tree(PDO $pdo): array {
    car_qab_lib();
    try { return qab_cause_tree($pdo, true); } catch (Throwable $e) { return []; }
}

/** 處置方式選項（qa_option kind=disp；只列啟用中的） */
function car_disp_options(PDO $pdo): array {
    car_qab_lib();
    try { return qab_options($pdo, 'disp', true); } catch (Throwable $e) { return []; }
}

/** 這個 cat_id 存不存在（存檔守門；停用的也放行——舊單重存時不該因為管理員停用了分類就存不回去） */
function car_cause_exists(PDO $pdo, int $catId): bool {
    car_qab_lib();
    try { $m = qab_cause_map($pdo); } catch (Throwable $e) { return false; }
    return isset($m[$catId]);
}

/** 這個 opt_id 存不存在、而且是「處置方式」那一種 */
function car_disp_exists(PDO $pdo, int $optId): bool {
    car_qab_lib();
    try { $m = qab_option_map($pdo); } catch (Throwable $e) { return false; }
    return isset($m[$optId]) && ($m[$optId]['kind'] ?? '') === 'disp';
}

/**
 * 顯示用：這張單的「異常原因分類」文字（新欄位優先，舊資料退回原本的勾選項）。
 * 畫面與列印共用同一支，不要在 JS 再組一次（兩邊遲早長出不同說法）。
 */
function car_cause_label(PDO $pdo, array $o): string {
    $cat = (int)($o['cause_cat_id'] ?? 0);
    if ($cat > 0) {
        car_qab_lib();
        try {
            $m = qab_cause_map($pdo);
            if (isset($m[$cat])) return (string)$m[$cat]['path'];   // 例「人 → 操作疏失 → 未依SOP標準作業」
        } catch (Throwable $e) {}
        return '#' . $cat;
    }
    $legacy = trim((string)($o['cause_investigation'] ?? ''));
    if ($legacy === '') return '';
    $L = car_labels()['cause'];
    $names = [];
    foreach (explode(',', $legacy) as $k) { $k = trim($k); if ($k !== '') $names[] = ($L[$k] ?? $k); }
    $txt = implode('、', $names);
    $other = trim((string)($o['cause_other'] ?? ''));
    return $txt . ($other !== '' ? ('、' . $other) : '');
}

/** 顯示用：這張單的「處置方式」文字（新欄位優先，舊資料退回原本的 enum） */
function car_disp_label(PDO $pdo, array $o): string {
    $opt = (int)($o['disposition_opt_id'] ?? 0);
    if ($opt > 0) {
        car_qab_lib();
        try {
            $m = qab_option_map($pdo);
            if (isset($m[$opt])) return (string)$m[$opt]['name'];
        } catch (Throwable $e) {}
        return '#' . $opt;
    }
    $legacy = trim((string)($o['disposition'] ?? ''));
    if ($legacy === '') return '';
    $txt = car_labels()['disposition'][$legacy] ?? $legacy;
    $other = trim((string)($o['disposition_other'] ?? ''));
    return $txt . ($other !== '' ? ('、' . $other) : '');
}

/* ── 處理軌跡：連續的「微幅更動」合併成一筆 ────────────────────────────────
 * 補資料改成「改到哪存到哪」之後，打一段字就會寫好幾十列 bf_edit，處理軌跡整片被洗版
 * （使用者回報）。做法分兩層：
 *   ① 寫入當下就合併（car_log_merge）——同一個人、同一種動作、短時間內的連續紀錄，
 *      直接更新上一列，不再長出新的一列；欄位異動的內容會逐欄合併成「最早的舊值 → 最新的新值」。
 *   ② 顯示時再合併一次（car_acts_merge）——把改版之前已經寫進去的舊紀錄也收乾淨。
 * **簽章、指派、核准、退回一律不合併**（使用者明確要求），那些是一件一件的事實。
 * ------------------------------------------------------------------------ */

/** 可合併的動作（只有「編輯內容」這一類；簽章等一律不在內） */
function car_log_mergeable(string $action): bool {
    return in_array($action, ['bf_edit', 'edit'], true);
}

/**
 * 把兩筆「欄位異動」說明合併成一筆：同一個欄位取「最早的舊值 → 最新的新值」，
 * 不同欄位則接在後面。格式＝「前綴（欄位：舊 → 新；欄位：舊 → 新）」。
 * 兩筆的前綴不同（＝根本不是同一種動作）時回 null＝不要合併。
 */
function car_log_merge_note(?string $old, ?string $new): ?string {
    $old = (string)$old; $new = (string)$new;
    if ($old === $new) return $old;                       // 一模一樣（例：三段內容存檔）＝直接沿用
    $split = function (string $t): ?array {
        $pos = mb_strpos($t, '（');
        if ($pos === false || mb_substr($t, -1) !== '）') return null;
        $prefix = mb_substr($t, 0, $pos);
        $body   = mb_substr($t, $pos + 1, mb_strlen($t) - $pos - 2);
        $map = [];
        foreach (explode('；', $body) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $p = mb_strpos($part, '：');
            if ($p === false) return null;
            $map[mb_substr($part, 0, $p)] = mb_substr($part, $p + 1);
        }
        return [$prefix, $map];
    };
    $a = $split($old); $b = $split($new);
    if (!$a || !$b || $a[0] !== $b[0]) return null;
    $merged = $a[1];
    foreach ($b[1] as $k => $v) {
        if (isset($merged[$k])) {
            // 舊值取最早那一次的、新值取最新那一次的
            $from = explode(' → ', $merged[$k]); $to = explode(' → ', $v);
            $merged[$k] = $from[0] . ' → ' . end($to);
        } else $merged[$k] = $v;
    }
    // 改回原值的欄位（舊值＝新值）就不必再列了
    $parts = [];
    foreach ($merged as $k => $v) {
        $kv = explode(' → ', $v);
        if (count($kv) === 2 && $kv[0] === $kv[1]) continue;
        $parts[] = $k . '：' . $v;
    }
    if (!$parts) return $a[0] . '（內容改回原樣）';
    return $a[0] . '（' . implode('；', $parts) . '）';
}

/** 寫處理軌跡，但同一個人短時間內的連續「編輯」合併成同一列（見上方說明） */
function car_log_merge(PDO $pdo, int $carId, string $action, ?int $actorId, ?string $actorName,
                       ?string $note, int $windowSec = 600): void {
    if (car_log_mergeable($action)) {
        try {
            $st = $pdo->prepare("SELECT id, action, actor_id, note,
                                        TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
                                 FROM car_activity_log WHERE car_id = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$carId]);
            $last = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $last = null; }
        if ($last && (string)$last['action'] === $action && (int)$last['actor_id'] === (int)$actorId
            && $last['age'] !== null && (int)$last['age'] >= 0 && (int)$last['age'] <= $windowSec) {
            $m = car_log_merge_note($last['note'], $note);
            if ($m !== null) {
                try {
                    $pdo->prepare("UPDATE car_activity_log SET note = ?, created_at = NOW() WHERE id = ?")
                        ->execute([$m, (int)$last['id']]);
                    return;
                } catch (Throwable $e) {}
            }
        }
    }
    car_log($pdo, $carId, $action, $actorId, $actorName, $note);
}

/**
 * 顯示用合併：把已經寫進去的連續微幅更動收成一筆（改版前留下來的紀錄也適用）。
 * 合併後多回兩個欄位：merged_count（合併了幾筆）、merged_from（最早那一筆的時間）。
 */
function car_acts_merge(array $acts, int $windowSec = 600): array {
    $out = [];
    foreach ($acts as $a) {
        $n = count($out);
        if ($n > 0 && car_log_mergeable((string)$a['action'])) {
            $prev = &$out[$n - 1];
            $same = ((string)$prev['action'] === (string)$a['action'])
                 && ((int)($prev['actor_id'] ?? 0) === (int)($a['actor_id'] ?? 0));
            $gap  = strtotime((string)$a['created_at']) - strtotime((string)$prev['created_at']);
            if ($same && $gap >= 0 && $gap <= $windowSec) {
                $m = car_log_merge_note($prev['note'] ?? '', $a['note'] ?? '');
                if ($m !== null) {
                    $prev['note']         = $m;
                    $prev['merged_from']  = $prev['merged_from'] ?? $prev['created_at'];
                    $prev['created_at']   = $a['created_at'];
                    $prev['merged_count'] = (int)($prev['merged_count'] ?? 1) + 1;
                    unset($prev);
                    continue;
                }
            }
            unset($prev);
        }
        $a['merged_count'] = 1;
        $out[] = $a;
    }
    return $out;
}

/**
 * 補資料的章格「預設帶誰」（使用者要求）。
 * - primary 主管簽核＝**責任單位裡職級最高的那位主管**（責任單位是廠商時＝生管主管，
 *   與正式流程 car_primary_pool_ids() 用的是同一份名單，不另外發明規則）。
 * - final 總經理核准＝**全站設定的最高核准人員**（org_role_setting 的 top_approver），
 *   設定沒綁人才退回本模組的「最終決策者職位」設定。禁止寫死人名。
 * 只是「預設選起來」，超管仍可自己改；解析不到就回 0（留白）。
 * @return array{primary:int,final:int}
 */
function car_bf_default_signers(PDO $pdo, array $o): array {
    $primary = 0;
    $pool = car_primary_pool_ids($pdo, $o);         // 已依職級由高到低排序
    if ($pool) $primary = (int)$pool[0];

    $final = 0;
    require_once __DIR__ . '/org_role_lib.php';
    $top = eg_org_user($pdo, 'top_approver');
    if ($top && !empty($top['id'])) $final = (int)$top['id'];
    if (!$final) { $d = car_final_deciders($pdo); if ($d) $final = (int)$d[0]['id']; }

    return ['primary' => $primary, 'final' => $final];
}

/** 補資料一律留 audit_log（全站共用表；寫入失敗不影響主要作業） */
function car_bf_audit(PDO $pdo, int $carId, string $carNo, int $uid, string $uname, string $what, string $detail = ''): void {
    try {
        $pdo->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                       VALUES ('backfill', 'car_order', ?, ?, ?, ?, ?, NOW())")
            ->execute([(string)$carId, ($carNo !== '' ? $carNo : ('#' . $carId)),
                       trim($what . ($detail !== '' ? ('：' . $detail) : '')), $uid, $uname]);
    } catch (Throwable $e) {}
}

} // end function_exists guard
