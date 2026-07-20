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

/** 判斷使用者是否為某記錄的合格「指派者」（責任部門主管；廠商責任→生管主管） */
function car_can_assign_order(PDO $pdo, array $o, int $uid): bool {
    if (($o['status'] ?? '') !== 'open') return false;
    $rtype = $o['resp_type'] ?? '';
    if ($rtype === 'dept' && !empty($o['resp_dept_id'])) {
        foreach (car_dept_supervisors($pdo, (int)$o['resp_dept_id']) as $s) if ((int)$s['id'] === $uid) return true;
    } elseif ($rtype === 'maker') {
        foreach (car_pm_supervisors($pdo) as $s) if ((int)$s['id'] === $uid) return true;
    }
    return false;
}

/** 是否為某單的「首要決策者」候選：責任部門主管；廠商責任→生管主管 */
function car_is_primary_candidate(PDO $pdo, array $o, int $uid): bool {
    $rtype = $o['resp_type'] ?? '';
    if ($rtype === 'maker') {
        foreach (car_pm_supervisors($pdo) as $s) if ((int)$s['id'] === $uid) return true;
        return false;
    }
    if (!empty($o['resp_dept_id'])) {
        foreach (car_dept_supervisors($pdo, (int)$o['resp_dept_id']) as $s) if ((int)$s['id'] === $uid) return true;
    }
    return false;
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

} // end function_exists guard
