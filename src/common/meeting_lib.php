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
include_once __DIR__ . '/attach_lib.php';
include_once __DIR__ . '/asdoc_lib.php';
include_once __DIR__ . '/kpi_lib.php';
include_once __DIR__ . '/people_lib.php';

const MEETING_FEATURES = [
    ['code'=>'meeting_view',      'group'=>'view', 'label'=>'檢閱會議記錄列表（沒勾也看得到自己的草稿、有簽核/出席到的會議）'],
    ['code'=>'meeting_view_all',  'group'=>'view', 'label'=>'檢視全部人員建立的會議記錄（不含他人尚未送出的草稿）'],
    ['code'=>'meeting_edit',      'group'=>'op',   'label'=>'新增/編輯/送出會議記錄'],
    ['code'=>'meeting_print',     'group'=>'op',   'label'=>'列印（會議記錄／空白簽到表）'],
    ['code'=>'meeting_kpi_insert','group'=>'op',   'label'=>'可將本月出貨目標達成率插入會議記錄'],
    ['code'=>'meeting_admin',     'group'=>'op',   'label'=>'模組設定、刪除會議記錄、修改他人已送出的記錄'],
];
const MEETING_DEFAULT_ROLE_FEATURES = [
    'meeting_view'  => ['meeting_view'],
    'meeting_edit'  => ['meeting_edit', 'meeting_view', 'meeting_print'],
    'meeting_admin' => ['meeting_admin', 'meeting_edit', 'meeting_view', 'meeting_view_all', 'meeting_print', 'meeting_kpi_insert'],
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

    // 附件（手動輸入類型/說明的自由文字，非固定分類）；status: temp=草稿暫存(meeting_id=0)、active=已隨會議存檔
    $db->exec("CREATE TABLE IF NOT EXISTS meeting_attach (
        attach_id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_id INT NOT NULL DEFAULT 0,
        file_name VARCHAR(120) NOT NULL COMMENT '實體檔名(亂數,只存檔名不存路徑)',
        original_name VARCHAR(200) NULL,
        attach_type VARCHAR(100) NULL COMMENT '附件類型/說明(使用者手動輸入)',
        file_size INT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        expire_at DATETIME NULL COMMENT 'temp暫存到期時間(轉正時清空)',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        KEY idx_meeting (meeting_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議記錄附件'");

    // 常用設定：主題綁地點綁時間，套用後仍可自行修改（管理員維護）
    $db->exec("CREATE TABLE IF NOT EXISTS meeting_preset (
        preset_id INT AUTO_INCREMENT PRIMARY KEY,
        subject VARCHAR(100) NOT NULL,
        location VARCHAR(100) NULL,
        start_time VARCHAR(5) NULL,
        end_time VARCHAR(5) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at DATETIME NULL
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議常用設定(主題/地點/時間組合)，套用後仍可自行修改'");

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
        'canKpiInsert' => $has('meeting_kpi_insert') || $has('meeting_admin'),
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
/** 主席簽核人：一律直送主席本人，不走代理判定(使用者明確要求的特例，2026-08-05)。
 *  理由：會議記錄的主席＝該場會議行事曆上實際出席主持的人，delegate_lib 的「今日行程忙碌」判定
 *  對這裡完全不適用(忙碌判定本身可能就是因為他正在開這場會，反而被誤判成沒空)；曾發生代理人被誤轉去某位
 *  當天請假、根本不知情的員工，主席本人反而完全沒收到待簽通知。往後若要恢復代理判定需使用者另外確認。 */
function meeting_chair_signer_effective(PDO $db, int $chairUid, string $chairName): array {
    if ($chairUid <= 0) return ['id'=>0, 'name'=>'', 'is_delegated'=>false];
    return ['id'=>$chairUid, 'name'=>$chairName, 'is_delegated'=>false];
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

/** 撤回時一併關閉本次送出所發出的「項目待確認回簽」通知(MEETING_ITEM_CONFIRM，ref_id=item_id) */
function meeting_close_item_notices(PDO $db, int $meetingId): void {
    try {
        $ids = $db->prepare("SELECT item_id FROM meeting_item WHERE meeting_id=?");
        $ids->execute([$meetingId]);
        $itemIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if (!$itemIds) return;
        $in = implode(',', $itemIds);
        $db->exec("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                   WHERE ref_type='MEETING_ITEM_CONFIRM' AND ref_id IN ($in) AND (enddate IS NULL OR enddate>=CURDATE())");
    } catch (Throwable $e) {}
}

/**
 * 部門指派項目待確認通知：發給該（多）負責部門「本次未出席」的成員，走一般通知系統回簽（ref_type='MEETING_ITEM_CONFIRM'，ref_id=項目id）。
 * 有出席的部門成員一律在會議記錄現場用本人密碼確認（item_confirm，見 Meeting_API.php），不走這條通知路線，
 * 避免「按鈕點一下不知道是誰簽」的問題；未出席者無法現場輸入密碼，才改用通知系統的回簽功能。
 */
function meeting_notify_item_owners(PDO $db, int $itemId, array $toUids, string $title, string $content, int $fromUid): int {
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '會議記錄項目確認', 1, 'MEETING_ITEM_CONFIRM', ?)")
           ->execute([$title, $content, $fromUid, $itemId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')");
        foreach (array_unique(array_map('intval', $toUids)) as $tuid) $ins->execute([$eid, $tuid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 負責部門(含子項目)中「本次未出席」的成員 id 清單，用來決定要發通知給誰回簽。
 *  部門成員一律以「會議當天」的部門歸屬為準(ai-rules/14，走 position_history_lib.php 的
 *  eg_position_snapshot_at_bulk())，不可用查詢當下(送出時)的現況——否則像 8/3 才兼任業務職務的人，
 *  會被誤通知去確認 7/28 那場會議的業務部門項目(2026-08-05 使用者實際回報的案例)。 */
function meeting_dept_nonattendee_targets(PDO $db, int $meetingId, array $ownerDeptIds): array {
    require_once __DIR__ . '/position_history_lib.php';
    if (!$ownerDeptIds) return [];
    $mst = $db->prepare("SELECT meeting_date FROM meeting_record WHERE meeting_id=?");
    $mst->execute([$meetingId]);
    $meetingDate = (string)($mst->fetchColumn() ?: date('Y-m-d'));

    $snapAll = eg_position_snapshot_at_bulk($db, $meetingDate);
    $deptSet = array_flip($ownerDeptIds);
    $memberIds = [];
    foreach ($snapAll as $memberUid => $snap) {
        foreach ($snap as $row) {
            if (isset($deptSet[(int)$row['department_id']])) { $memberIds[] = (int)$memberUid; break; }
        }
    }
    if (!$memberIds) return [];
    // 排除離職／特殊帳號（歷史快照不含在職狀態，仍須另外過濾，不可通知已離職的人）
    $in = implode(',', $memberIds);
    $active = $db->query("SELECT id FROM `user` WHERE id IN ($in) AND state NOT IN (0,90)")->fetchAll(PDO::FETCH_COLUMN);
    $memberIds = array_map('intval', $active);
    if (!$memberIds) return [];

    $st = $db->prepare("SELECT user_id FROM meeting_attendee WHERE meeting_id=?");
    $st->execute([$meetingId]);
    $attendeeIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_diff($memberIds, $attendeeIds));
}

/** 未出席部門成員透過通知系統回簽（_eventRespond.php 的 MEETING_ITEM_CONFIRM 掛勾呼叫）：任一人簽名即完成，比照現場確認同一套 OR-gate */
function meeting_item_confirm_via_notify(PDO $db, int $itemId, int $uid, string $uname): void {
    $st = $db->prepare("SELECT owner_depts, confirm_user_id FROM meeting_item WHERE item_id=?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item || $item['confirm_user_id']) return;
    $ownerIds = array_values(array_filter(array_map('intval', explode(',', (string)$item['owner_depts']))));
    if (!$ownerIds) return;
    $in = implode(',', array_fill(0, count($ownerIds), '?'));
    $chk = $db->prepare("SELECT 1 FROM user_department_position_map WHERE user_id=? AND department_id IN ($in)");
    $chk->execute(array_merge([$uid], $ownerIds));
    if (!$chk->fetchColumn()) return;
    $db->prepare("UPDATE meeting_item SET confirm_user_id=?, confirm_user_name=?, confirm_at=NOW() WHERE item_id=? AND confirm_user_id IS NULL")
       ->execute([$uid, $uname, $itemId]);
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
 * 出貨目標達成率快照，供產銷會議紀錄插入用：直接呼叫共用 kpi_weekly_report()（與
 * views/Sales/Shipping_Analysis_new.php 的 KPI 週報同一套函式），4 週明細＋合計＋大額前三名
 * 全部一併存進快照，畫面/列印顯示的內容才會跟該頁「月份出貨KPI週報」完全一致。
 */
function meeting_kpi_snapshot(PDO $db, int $year, int $month): array {
    return kpi_weekly_report($db, $year, $month);
}

/* ============================================================
 * 附件路徑（比照 ai-rules/07，DB只存檔名，路徑即時組）
 * ============================================================ */
function meeting_attach_dir(PDO $db): string {
    return eg_attach_dir($db, 'meeting_nas_dir', '會議紀錄');
}
function meeting_setting_get(PDO $db, string $key, string $default = ''): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}
function meeting_setting_save(PDO $db, string $key, string $value): void {
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$key, $value]);
}

/* ============================================================
 * 合併列印「製表人」解析：出席者中屬於業務部門(含子部門)、且在職務職稱設定裡職稱有設職級者，
 * 取職級最低(最基層主管)的那一位；同職級多人時全部回傳讓使用者選。
 * ============================================================ */
function meeting_preparer_candidates(PDO $db, int $meetingId): array {
    $deptIds = eg_org_dept_ids($db, 'sales_dept');
    if (!$deptIds) return [];
    $in = implode(',', array_fill(0, count($deptIds), '?'));
    $st = $db->prepare("SELECT a.user_id, a.user_name, MIN(pl.level) AS best_level
                        FROM meeting_attendee a
                        JOIN user_department_position_map m ON m.user_id=a.user_id AND m.department_id IN ($in)
                        JOIN position_level pl ON pl.position_id=m.position_id AND pl.level IS NOT NULL
                        WHERE a.meeting_id=?
                        GROUP BY a.user_id, a.user_name");
    $st->execute(array_merge($deptIds, [$meetingId]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $maxLevel = max(array_map(fn($r) => (int)$r['best_level'], $rows)); // level 數字越大＝職級越基層
    $out = [];
    foreach ($rows as $r) if ((int)$r['best_level'] === $maxLevel) $out[] = ['id'=>(int)$r['user_id'], 'name'=>$r['user_name']];
    return $out;
}
