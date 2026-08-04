<?php
/**
 * 會議紀錄管理（2-GM-05-01 會議記錄／2-GM-05-03 會議通知單）
 * 權限：meeting_perms()（roles module='meeting'；admin⊃edit⊃view，比照 training 模組）
 * 簽核：借用共用 approval_record（module='meeting'，level='chair'→'gm'），流程函式見 approval_lib.php
 * 代理：主席／總經理缺席時的簽核人解析一律走 delegate_lib（ai-rules/11），不自己猜
 */
include_once __DIR__ . '/approval_lib.php';
include_once __DIR__ . '/org_role_lib.php';
include_once __DIR__ . '/role_features_helper.php';

const MEETING_FEATURES = [
    ['code'=>'meeting_view',      'group'=>'view', 'label'=>'檢閱會議記錄列表（沒勾也看得到自己的草稿、有簽核/出席到的會議）'],
    ['code'=>'meeting_view_all',  'group'=>'view', 'label'=>'檢視全部人員建立的會議記錄（不含他人尚未送出的草稿）'],
    ['code'=>'meeting_edit',      'group'=>'op',   'label'=>'新增/編輯/送出會議記錄'],
    ['code'=>'meeting_print',     'group'=>'op',   'label'=>'列印（會議記錄／空白簽到表）'],
    ['code'=>'meeting_admin',     'group'=>'op',   'label'=>'模組設定、刪除會議記錄、修改他人已送出的記錄'],
];
const MEETING_DEFAULT_ROLE_FEATURES = [
    'meeting_view'  => ['meeting_view'],
    'meeting_edit'  => ['meeting_edit', 'meeting_view', 'meeting_print'],
    'meeting_admin' => ['meeting_admin', 'meeting_edit', 'meeting_view', 'meeting_view_all', 'meeting_print'],
];

/* ============================================================
 * Schema（CREATE TABLE IF NOT EXISTS，比照 training_ensure_schema 慣例，每次 API 啟動時跑一次）
 * ============================================================ */
function meeting_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS meeting_record (
        meeting_id INT AUTO_INCREMENT PRIMARY KEY,
        subject VARCHAR(100) NOT NULL COMMENT '主題',
        meeting_date DATE NOT NULL,
        start_time VARCHAR(5) NULL,
        end_time VARCHAR(5) NULL,
        location VARCHAR(100) NULL,
        chair_user_id INT NULL COMMENT '主席 user.id(來自簽到勾選)',
        chair_name VARCHAR(50) NULL,
        recorder_user_id INT NOT NULL COMMENT '記錄(建立者) user.id',
        recorder_name VARCHAR(50) NULL,
        status VARCHAR(15) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/chair_done/done/rejected',
        kpi_snapshot_json LONGTEXT NULL COMMENT '插入的出貨目標達成率快照(JSON，凍結當下數字不隨後續資料再變動)',
        kpi_snapshot_asof DATE NULL COMMENT '快照資料基準日',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        updated_at DATETIME NULL,
        KEY idx_status (status),
        KEY idx_date (meeting_date),
        KEY idx_recorder (recorder_user_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議記錄表頭(2-GM-05-01)'");

    $db->exec("CREATE TABLE IF NOT EXISTS meeting_attendee (
        att_id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_id INT NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(50) NULL,
        dept_name VARCHAR(50) NULL,
        position_name VARCHAR(50) NULL,
        is_chair TINYINT(1) NOT NULL DEFAULT 0,
        signed TINYINT(1) NOT NULL DEFAULT 0,
        signed_at DATETIME NULL,
        UNIQUE KEY uq_ma (meeting_id, user_id),
        KEY idx_meeting (meeting_id),
        KEY idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議出席人員名單(含簽到)'");

    $db->exec("CREATE TABLE IF NOT EXISTS meeting_item (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_id INT NOT NULL,
        kind VARCHAR(15) NOT NULL DEFAULT 'general' COMMENT 'directive=上級指示要項 general=會議要項',
        sort_order INT NOT NULL DEFAULT 0,
        content TEXT NOT NULL COMMENT '報告要點及決議事項',
        due_date DATE NULL COMMENT '應完成日期',
        owner_depts VARCHAR(200) NULL COMMENT '負責部門 department.id 逗號分隔(可多選)',
        owner_dept_names VARCHAR(200) NULL COMMENT '負責部門名稱(冗餘顯示用)',
        remark VARCHAR(200) NULL,
        confirm_user_id INT NULL COMMENT '確認簽名者(負責部門任一與會者簽名即完成)',
        confirm_user_name VARCHAR(50) NULL,
        confirm_at DATETIME NULL,
        gm_comment TEXT NULL COMMENT '總經理逐筆回覆意見(選填)',
        KEY idx_meeting (meeting_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議記錄項目(上級指示要項/會議要項)'");

    // 內建 3 個角色只在模組第一次啟用時種一次（比照 training_roles_seeded 的做法：種過之後管理員刪除就是真的刪除）
    try {
        $seeded = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='meeting_roles_seeded'")->fetchColumn();
        if (!$seeded) {
            foreach ([['meeting_view','會議記錄檢閱'],['meeting_edit','會議記錄登錄'],['meeting_admin','會議記錄管理員']] as $r) {
                $st = $db->prepare("SELECT role_id FROM roles WHERE role_code=? AND module='meeting' LIMIT 1");
                $st->execute([$r[0]]);
                $rid = (int)$st->fetchColumn();
                if (!$rid) {
                    $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'meeting')")->execute([$r[0], $r[1]]);
                    $rid = (int)$db->lastInsertId();
                }
                $cnt = $db->prepare("SELECT COUNT(*) FROM role_features WHERE role_id=?");
                $cnt->execute([$rid]);
                if ((int)$cnt->fetchColumn() === 0) {
                    $ins = $db->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?,?)");
                    foreach (MEETING_DEFAULT_ROLE_FEATURES[$r[0]] ?? [$r[0]] as $fc) $ins->execute([$rid, $fc]);
                }
            }
            $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('meeting_roles_seeded','1')
                          ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
        }
    } catch (Throwable $e) {}
}

/* ============================================================
 * 使用者／權限
 * ============================================================ */
function meeting_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function meeting_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false,'canViewAll'=>false,'canPrint'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $feat = rf_load_user_features_all($db, $uid);
    $codes = array_values(array_intersect($feat, array_column(MEETING_FEATURES, 'code')));
    $has = function(string $code) use ($isAdmin, $codes) { return $isAdmin || in_array('all', $codes, true) || in_array($code, $codes, true); };
    return [
        'isAdmin'    => $isAdmin,
        'canAdmin'   => $has('meeting_admin'),
        'canEdit'    => $has('meeting_edit') || $has('meeting_admin'),
        'canView'    => $has('meeting_view') || $has('meeting_edit') || $has('meeting_admin') || true,   // 每個登入者至少看得到自己的草稿/相關會議
        'canViewAll' => $has('meeting_view_all') || $has('meeting_admin'),
        'canPrint'   => $has('meeting_print') || $has('meeting_admin'),
    ];
}

/**
 * 單筆會議記錄的檢視授權：草稿只有本人／管理員看得到；送出後才適用「出席者/主席/總經理/canViewAll」等額外規則。
 * 起因：與會者一律用密碼自行簽章、不透過角色勾選，所以簽章者至少要有唯讀權限，不可被角色設定擋在外面。
 */
function meeting_can_view(PDO $db, int $uid, array $perms, array $m): bool {
    if ((int)$m['recorder_user_id'] === $uid) return true;
    if (!empty($perms['canAdmin'])) return true;
    if (($m['status'] ?? '') === 'draft') return false;
    if (!empty($perms['canViewAll'])) return true;
    if ((int)($m['chair_user_id'] ?? 0) === $uid) return true;
    try {
        $st = $db->prepare("SELECT 1 FROM meeting_attendee WHERE meeting_id=? AND user_id=? LIMIT 1");
        $st->execute([(int)$m['meeting_id'], $uid]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {}
    $gm = eg_org_user($db, 'top_approver');
    if ($gm && (int)$gm['id'] === $uid) return true;
    return false;
}

/* ============================================================
 * 密碼簽章（共用裝置輪流簽）：身分一律用「選人」建立，密碼只用來驗證是本人，
 * 不用密碼反查身分——避免使用者提到的「密碼重複無法辨識」問題。
 * 授權範圍＝該會議的與會者名單（不是共用帳號成員），任何人只要在名單內都可以自己輸入密碼簽到。
 * ============================================================ */
function meeting_verify_own_password(PDO $db, int $forUid, string $password): array {
    if ($forUid <= 0) return ['ok'=>false, 'msg'=>'請先選擇人員'];
    if ($password === '') return ['ok'=>false, 'msg'=>'請輸入密碼'];
    try {
        $st = $db->prepare("SELECT user_password FROM `user` WHERE id=?");
        $st->execute([$forUid]);
        $real = $st->fetchColumn();
        if ($real === false) return ['ok'=>false, 'msg'=>'查無此人員'];
        if (!hash_equals((string)$real, $password)) return ['ok'=>false, 'msg'=>'密碼錯誤，請由本人輸入自己的密碼'];
        return ['ok'=>true, 'msg'=>''];
    } catch (Throwable $e) { return ['ok'=>false, 'msg'=>'驗證失敗']; }
}

/* ============================================================
 * 送審／簽核通知（比照 ai-rules/17：內容要看得到摘要，點進去可直接核准/退回）
 * 借用共用 approval_record（module='meeting'）；主席／總經理缺席時的簽核人一律走 delegate_lib 解析。
 * ============================================================ */

/** 主席簽核人（含代理解析）：主席本人今日有行程忙碌時，改由代理人簽 */
function meeting_chair_signer_effective(PDO $db, int $chairUid, string $chairName): array {
    if ($chairUid <= 0) return ['id'=>0, 'name'=>'', 'is_delegated'=>false];
    try {
        require_once __DIR__ . '/delegate_lib.php';
        $r = eg_resolve_signer($db, $chairUid, ['flow_key'=>'meeting_chair']);
        $sid = (int)($r['signer_id'] ?? $chairUid);
        if ($sid === $chairUid) return ['id'=>$chairUid, 'name'=>$chairName, 'is_delegated'=>false];
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$sid]);
        return ['id'=>$sid, 'name'=>(string)($st->fetchColumn() ?: $chairName), 'is_delegated'=>true];
    } catch (Throwable $e) { return ['id'=>$chairUid, 'name'=>$chairName, 'is_delegated'=>false]; }
}

/** 總經理（最高核准人員，org_role_lib 'top_approver'）簽核人，含代理解析 */
function meeting_gm_signer_effective(PDO $db): ?array {
    $gm = eg_org_user($db, 'top_approver');
    if (!$gm) return null;
    try {
        require_once __DIR__ . '/delegate_lib.php';
        $r = eg_resolve_signer($db, (int)$gm['id'], ['flow_key'=>'meeting_gm']);
        $sid = (int)($r['signer_id'] ?? $gm['id']);
        if ($sid === (int)$gm['id']) return ['id'=>(int)$gm['id'], 'name'=>$gm['user_cname'], 'is_delegated'=>false];
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$sid]);
        return ['id'=>$sid, 'name'=>(string)($st->fetchColumn() ?: $gm['user_cname']), 'is_delegated'=>true];
    } catch (Throwable $e) { return ['id'=>(int)$gm['id'], 'name'=>$gm['user_cname'], 'is_delegated'=>false]; }
}

/** 送審/決行通知（待簽核，通知帶「立即前往」連結；mode='sign'＝點進去可核准/退回） */
function meeting_notify(PDO $db, int $meetingId, int $toUid, string $title, string $content, int $fromUid, string $mode='sign'): int {
    if (!$toUid) return 0;
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='MEETING_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$meetingId]);
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '會議記錄簽核', 1, 'MEETING_APPROVAL', ?)")
           ->execute([$title, $content, $fromUid, $meetingId]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)")
           ->execute([$eid, $toUid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 結束此會議記錄目前待簽核的通知（換下一階段或已有結果時關閉舊通知） */
function meeting_close_notice(PDO $db, int $meetingId): void {
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='MEETING_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$meetingId]);
    } catch (Throwable $e) {}
}

/** 部門指派項目待確認通知：發給該（多）部門所有本次與會者，任一人簽名即完成 */
function meeting_notify_item_owners(PDO $db, int $meetingId, array $toUids, string $title, string $content, int $fromUid): int {
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '會議記錄項目確認', 1, 'MEETING_ITEM_CONFIRM', ?)")
           ->execute([$title, $content, $fromUid, $meetingId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')");
        foreach (array_unique(array_map('intval', $toUids)) as $uid) $ins->execute([$eid, $uid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 結果通知（核准/退回/項目已確認 都要回報，退回一定帶原因） */
function meeting_notify_result(PDO $db, int $meetingId, int $toUid, string $title, string $content, int $fromUid): void {
    if (!$toUid) return;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '會議記錄簽核', 1, 'MEETING_RESULT', ?)")
           ->execute([$title, $content, $fromUid, $meetingId]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
           ->execute([$eid, $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

/* ============================================================
 * 會議記錄簽核目前狀態（借用 approval_record，module='meeting'，level='chair'→'gm'）
 * ============================================================ */
function meeting_approval_status(PDO $db, int $meetingId): array {
    $chair = eg_approval_latest($db, 'meeting', $meetingId, 'chair');
    $gm    = eg_approval_latest($db, 'meeting', $meetingId, 'gm');
    $status = 'draft';
    if ($chair) $status = $chair['status'] === 'pending' ? 'submitted'
                        : ($chair['status'] === 'rejected' ? 'rejected' : 'chair_done');
    if ($gm) $status = $gm['status'] === 'pending' ? 'chair_done'
                     : ($gm['status'] === 'rejected' ? 'rejected' : 'done');
    return ['status'=>$status, 'chair'=>$chair, 'gm'=>$gm];
}

/* ============================================================
 * 出貨目標達成率快照：資料新鮮度檢查（前一個工作天，比照 leave_lib eg_leave_is_workday 的工作日判定）
 * ============================================================ */
function meeting_prev_workday(PDO $db, string $date): string {
    require_once __DIR__ . '/leave_lib.php';
    $d = $date;
    for ($i = 0; $i < 14; $i++) {
        $d = date('Y-m-d', strtotime($d . ' -1 day'));
        if (eg_leave_is_workday($db, $d)) return $d;
    }
    return $d;
}

/** 出貨資料是否已更新至「前一個工作天」；未達標回傳最新日期供前端提示還差幾天 */
function meeting_kpi_freshness(PDO $db, string $today): array {
    $need = meeting_prev_workday($db, $today);
    $latest = null;
    try { $latest = $db->query("SELECT MAX(Order_date) FROM is_list")->fetchColumn(); } catch (Throwable $e) {}
    $ok = $latest && $latest >= $need;
    return ['ok'=>$ok, 'need_asof'=>$need, 'latest'=>$latest ?: null];
}

/**
 * 出貨目標達成率（帳款月整月彙總，一行摘要，供產銷會議紀錄插入用）。
 * 計算邏輯與 views/Sales/Shipping_Analysis_new.php 的 get_kpi_data（4週明細）完全比照
 * （帳款月起訖判定、target 金額來源、revenue=出貨-退貨），只是不拆成 4 週、彙總整個帳款月一行，
 * 避免另刻一套算法造成兩頁數字兜不起來。
 */
function meeting_kpi_month_summary(PDO $db, int $year, int $month): array {
    $gCutoff = 0;
    try { $gCutoff = (int)$db->query("SELECT setting_value FROM system_settings WHERE setting_key='billing_cutoff_day' LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}
    $targetAmount = 0.0;
    try {
        $t = $db->query("SELECT param_value FROM system_parameters WHERE param_group='SHIPPING_ANALYSIS' AND param_key='KPI_TARGET' LIMIT 1")->fetchColumn();
        if ($t !== false) $targetAmount = (float)$t;
    } catch (Throwable $e) {}
    $startDay = $gCutoff > 0 ? $gCutoff + 1 : 1;
    try {
        $st = $db->prepare("SELECT start_day FROM kpi_monthly_targets WHERE year=? AND month=?");
        $st->execute([$year, $month]);
        $sv = $st->fetch(PDO::FETCH_ASSOC);
        if ($sv) $startDay = (int)$sv['start_day'];
    } catch (Throwable $e) {}
    if ($startDay > 28) $startDay = 1;
    $cutoff = $startDay > 1 ? $startDay - 1 : 31;
    $prevMonth = $month - 1; $prevYear = $year;
    if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
    $bdStart = new DateTime(sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, $startDay));
    $endDay = min($cutoff, (int)(new DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t'));
    $bdEnd = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $endDay));
    $ws = $bdStart->format('Y-m-d'); $we = $bdEnd->format('Y-m-d');

    $s1 = $db->prepare("SELECT COALESCE(SUM(Qty*Unit_price),0) FROM is_list WHERE Order_date BETWEEN ? AND ? AND Unit_price > 0");
    $s1->execute([$ws, $we]); $shipAmount = (float)$s1->fetchColumn();
    $s2 = $db->prepare("SELECT COALESCE(SUM(Qty*unit_price),0) FROM order_track WHERE Delivery_date BETWEEN ? AND ? AND (Order_status IS NULL OR Order_status!=9) AND unit_price>0");
    $s2->execute([$ws, $we]); $orderAmount = (float)$s2->fetchColumn();
    $s3 = $db->prepare("SELECT COALESCE(SUM(Qty*Unit_price),0) FROM ir_track WHERE IR_date BETWEEN ? AND ? AND Unit_price>0");
    $s3->execute([$ws, $we]); $returnAmount = (float)$s3->fetchColumn();
    $revenue = $shipAmount - $returnAmount;

    return [
        'billing_month_start'=>$ws, 'billing_month_end'=>$we,
        'target_amount'=>round($targetAmount), 'order_amount'=>round($orderAmount),
        'ship_amount'=>round($shipAmount), 'return_amount'=>round($returnAmount), 'revenue'=>round($revenue),
        'achieve_rate'=>$targetAmount > 0 ? round($revenue / $targetAmount * 100, 2) : 0,
    ];
}
