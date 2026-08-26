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
        confirm_user_id INT NULL COMMENT '(舊,已停用)確認簽名者：改用 meeting_item_confirm 一人一部門一列',
        confirm_user_name VARCHAR(50) NULL COMMENT '(舊,已停用)',
        confirm_at DATETIME NULL COMMENT '(舊,已停用)',
        gm_comment TEXT NULL COMMENT '總經理逐筆回覆意見(選填)',
        KEY idx_meeting (meeting_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議記錄項目(上級指示要項/會議要項)'");

    // 項目確認簽名(2026-08-05改版，使用者明確要求)：負責部門每部門各一位簽名(該部門有出席的主管優先，沒有主管出席才由任一出席人員代表)，
    // 一部門一列，(item_id,user_id) 唯一；取代舊版 meeting_item.confirm_user_id 的「任一人簽即整項完成」單欄位設計。
    $db->exec("CREATE TABLE IF NOT EXISTS meeting_item_confirm (
        confirm_id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(50) NULL,
        dept_name VARCHAR(50) NULL,
        confirmed_at DATETIME NOT NULL,
        UNIQUE KEY uq_ic (item_id, user_id),
        KEY idx_item (item_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議記錄項目確認簽名(依負責部門各一位,manager優先)'");
    // 負責人「指定人員」模式(2026-08-05使用者明確要求)：有值時完全取代 owner_depts 的部門自動判定，
    // 直接指定的人只要本次有出席就是必簽者(不套用主管優先的判定，因為是特別指名的)。
    // 既有表加欄位一律先查 information_schema 再 ALTER(MySQL 無 ADD COLUMN IF NOT EXISTS)，比照 homepage.php 慣例。
    try {
        $c = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meeting_item' AND COLUMN_NAME='owner_users'")->fetchColumn();
        if ($c === 0) {
            $db->exec("ALTER TABLE meeting_item ADD COLUMN owner_users VARCHAR(200) NULL
                       COMMENT '直接指定負責人員 user.id 逗號分隔(與owner_depts二擇一,有值時完全取代部門判定)' AFTER owner_dept_names");
        }
    } catch (Throwable $e) {}
    // 2026-08-06使用者明確要求：送出時不再因負責部門/指定人員未現場簽名而擋下，改為擴大通知相關人員回簽，
    // 任一人透過通知系統回覆即視同完成——但回覆的人不一定是當初系統挑出的那位「現場必簽代表」，
    // 需要靠 dept_id 而非 user_id 來比對「這格部門的簽名槽是否已由『任何一位代表這個部門的人』簽過」；
    // reply_content 存回覆內容供畫面在項目下方顯示(取代舊版只顯示已讀/已回簽狀態，看不到內容)。
    try {
        $c = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meeting_item_confirm' AND COLUMN_NAME='dept_id'")->fetchColumn();
        if ($c === 0) {
            $db->exec("ALTER TABLE meeting_item_confirm ADD COLUMN dept_id INT NULL
                       COMMENT '此簽名對應的負責部門id(部門模式；指定人員模式為NULL)，用來判定同部門任一人回覆都算完成該格' AFTER dept_name");
        }
        $c2 = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meeting_item_confirm' AND COLUMN_NAME='reply_content'")->fetchColumn();
        if ($c2 === 0) {
            $db->exec("ALTER TABLE meeting_item_confirm ADD COLUMN reply_content TEXT NULL
                       COMMENT '透過通知系統回覆時的回覆內容(現場密碼簽名無此內容)' AFTER confirmed_at");
        }
    } catch (Throwable $e) {}
    // dept_id 回填(2026-08-11修正)：新增 dept_id 欄位之前就存在的舊確認簽名列全部是 NULL，get_detail
    // 的簽名槽比對(部門模式)改用 dept_id 判定後，這些舊資料變成永遠對不到、畫面上顯示不出蓋章，但
    // item_confirm 的「已簽過」檢查是用 item_id+user_id 比對不受影響，造成使用者點了被擋「已簽過」
    // 卻完全看不到簽名的矛盾情況(2026-08-11使用者實測回報)。用當初存的 dept_name 文字比對 department
    // 表回填一次；沒有符合部門名稱的(如指定人員模式，dept_name本來就是空的)不受影響，維持NULL。
    try {
        $needBackfill = (int)$db->query("SELECT COUNT(*) FROM meeting_item_confirm WHERE dept_id IS NULL AND dept_name IS NOT NULL AND dept_name<>''")->fetchColumn();
        if ($needBackfill > 0) {
            $db->exec("UPDATE meeting_item_confirm mic JOIN department d ON d.name=mic.dept_name
                        SET mic.dept_id=d.id WHERE mic.dept_id IS NULL");
        }
    } catch (Throwable $e) {}
    // 舊資料一次性搬移(只搬一次)：把舊單欄位 confirm_user_id 的既有簽名保留下來，避免改版後歷史紀錄的簽名憑空消失
    try {
        $migrated = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='meeting_item_confirm_migrated'")->fetchColumn();
        if (!$migrated) {
            $db->exec("INSERT IGNORE INTO meeting_item_confirm (item_id, user_id, user_name, dept_name, confirmed_at)
                       SELECT item_id, confirm_user_id, confirm_user_name, NULL, COALESCE(confirm_at, NOW())
                       FROM meeting_item WHERE confirm_user_id IS NOT NULL");
            $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('meeting_item_confirm_migrated','1')
                          ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
        }
    } catch (Throwable $e) {}

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
    // 草稿是否可看：canViewAll(一般查閱者角色)僅適用已送出的會議紀錄，草稿仍需與此筆有關才看得到，
    // 「有關」＝出席人員／主席／總經理／項目負責部門或指定人員(需簽名的人)，2026-08-14使用者明確要求擴大。
    $isDraft = (($m['status'] ?? '') === 'draft');
    if (!$isDraft && !empty($perms['canViewAll'])) return true;
    if ((int)($m['chair_user_id'] ?? 0) === $uid) return true;
    try {
        $st = $db->prepare("SELECT 1 FROM meeting_attendee WHERE meeting_id=? AND user_id=? LIMIT 1");
        $st->execute([(int)$m['meeting_id'], $uid]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {}
    $gm = eg_org_user($db, 'top_approver');
    if ($gm && (int)$gm['id'] === $uid) return true;
    if (meeting_is_item_signer($db, (int)$m['meeting_id'], $uid)) return true;
    return false;
}

/** 是否為此會議任一項目的負責部門(含兼任)或指定負責人＝「需簽名的人」，草稿階段也要能看到，判斷才不會晚到送出後。 */
function meeting_is_item_signer(PDO $db, int $meetingId, int $uid): bool {
    $st = $db->prepare("SELECT owner_depts, owner_users FROM meeting_item WHERE meeting_id=?");
    $st->execute([$meetingId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return false;
    $userDeptIds = null;
    foreach ($rows as $r) {
        $ownerUsers = array_filter(array_map('intval', explode(',', (string)($r['owner_users'] ?? ''))));
        if ($ownerUsers && in_array($uid, $ownerUsers, true)) return true;
        $ownerDepts = array_filter(array_map('intval', explode(',', (string)($r['owner_depts'] ?? ''))));
        if ($ownerDepts) {
            if ($userDeptIds === null) {
                $ust = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id=?");
                $ust->execute([$uid]);
                $userDeptIds = array_map('intval', $ust->fetchAll(PDO::FETCH_COLUMN));
            }
            if (array_intersect($ownerDepts, $userDeptIds)) return true;
        }
    }
    return false;
}

/** 是否可列印此筆會議紀錄：只有建立人／管理員／已完成(status=done)可印，避免他人在草稿/簽核中階段印出未定案內容。 */
function meeting_can_print(int $uid, array $perms, array $m): bool {
    if (!empty($perms['canAdmin'])) return true;
    if ((int)$m['recorder_user_id'] === $uid) return true;
    return ($m['status'] ?? '') === 'done';
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
 * 這筆會議記錄目前是否還有「項目待確認回覆」的通知還在生效中(2026-08-10使用者明確要求新增)：
 * 「存檔並通知」送出後，在對方回覆確認完成、或記錄人主動撤回之前，畫面應鎖定不可編輯、狀態顯示「回簽中」，
 * 避免記錄人在別人還在看/回覆這份內容時又把內容改掉，讓對方回覆的是舊版內容。
 */
function meeting_has_active_item_notices(PDO $db, int $meetingId): bool {
    meeting_sync_item_notices($db, $meetingId); // 已完成卻漏關的先補關掉，否則會永遠卡在「回簽中」
    try {
        $st = $db->prepare("SELECT 1 FROM live_event le JOIN meeting_item mi ON mi.item_id=le.ref_id
                             WHERE le.ref_type='MEETING_ITEM_CONFIRM' AND mi.meeting_id=?
                             AND (le.enddate IS NULL OR le.enddate>=CURDATE()) LIMIT 1");
        $st->execute([$meetingId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * 給畫面顯示用的狀態(2026-08-10使用者明確要求)：在真正的簽核狀態(submitted/chair_done/done/rejected)之外，
 * draft/rejected 階段再細分出兩種一眼可辨的子狀態，避免「全部確認完成、可以送簽核了」跟「什麼都還沒做的
 * 新草稿」在畫面上長得一模一樣(都只顯示「草稿」)：
 *  - notifying(回簽中)：還有生效中的項目回覆通知。
 *  - ready(待送簽核)：出席已全部簽到、負責部門/指定人員也全部確認完成、且已指定主席，只差按下送簽核。
 * $m 需含 meeting_id、status、chair_user_id。
 */
function meeting_display_status(PDO $db, array $m): string {
    $raw = (string)$m['status'];
    if (!in_array($raw, ['draft', 'rejected'], true)) return $raw;
    $meetingId = (int)$m['meeting_id'];
    if (meeting_has_active_item_notices($db, $meetingId)) return 'notifying';
    if (!$m['chair_user_id']) return $raw;
    $ac = $db->prepare("SELECT COUNT(*) FROM meeting_attendee WHERE meeting_id=?");
    $ac->execute([$meetingId]);
    if ((int)$ac->fetchColumn() === 0) return $raw;
    $unsigned = $db->prepare("SELECT COUNT(*) FROM meeting_attendee WHERE meeting_id=? AND signed=0");
    $unsigned->execute([$meetingId]);
    if ((int)$unsigned->fetchColumn() > 0) return $raw;
    $itq = $db->prepare("SELECT * FROM meeting_item WHERE meeting_id=?");
    $itq->execute([$meetingId]);
    $items = $itq->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return $raw;
    foreach ($items as $it) {
        if (!meeting_item_is_confirmed($db, $it)) return $raw;
    }
    return 'ready';
}

/**
 * 項目待確認通知(2026-08-06改版，使用者明確要求)：送出時若負責部門/指定人員尚未現場簽名完成，
 * 一律改發通知請對方回覆確認，不再擋下送出；對象範圍見 meeting_item_pending_notify_targets()
 * （未出席者本身／部門主管／已出席但尚未簽的部門成員或指定人員，任一人回覆即完成該項目）。
 * mode 用 'reply'（不是單純的 sign）：讓對方在通知系統內留下一段回覆文字，畫面上會顯示在項目下方，
 * 而不只是「已回簽」三個字看不到內容；回覆同時視同簽名完成，見 _eventRespond.php 的掛勾。
 */
function meeting_notify_item_owners(PDO $db, int $itemId, array $toUids, string $title, string $content, int $fromUid): int {
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '會議記錄項目確認', 1, 'MEETING_ITEM_CONFIRM', ?)")
           ->execute([$title, $content, $fromUid, $itemId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'reply')");
        foreach (array_unique(array_map('intval', $toUids)) as $tuid) $ins->execute([$eid, $tuid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/**
 * 送出時「尚未簽名完成」要發通知的對象(2026-08-06改版，使用者明確要求，取代原本擋下送出不給送的做法)：
 *  ・指定人員模式：所有尚未回簽的指定人員本人(不論本次是否出席)。
 *  ・部門模式(可多個部門，各自獨立判斷後合併)：該部門「本次所有出席人員」(不限系統挑出的那位現場代表)
 *    ＋該部門主管(不論是否出席，職稱有設職級者皆算，含兼任)；若該部門本次完全沒人出席，就只剩主管，
 *    等同「通知部門主管」。任一人透過通知系統回覆都視同這個部門/這位指定人員已確認，不必是原本被
 *    系統選中代簽的那一位（畫面上用 dept_id 而非 user_id 比對簽名槽，見 meeting_item_confirm_via_notify）。
 *  已經回簽(meeting_item_confirm)的人／離職或特殊帳號一律排除。
 */
function meeting_item_pending_notify_targets(PDO $db, int $meetingId, array $item): array {
    $cst = $db->prepare("SELECT user_id FROM meeting_item_confirm WHERE item_id=?");
    $cst->execute([(int)$item['item_id']]);
    $confirmed = array_map('intval', $cst->fetchAll(PDO::FETCH_COLUMN));

    $ownerUserIds = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_users'] ?? '')))));
    if ($ownerUserIds) return array_values(array_diff($ownerUserIds, $confirmed));

    $ownerDeptIds = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_depts'] ?? '')))));
    if (!$ownerDeptIds) return [];

    $targets = [];
    foreach ($ownerDeptIds as $deptId) {
        $ast = $db->prepare("SELECT DISTINCT a.user_id FROM meeting_attendee a
                              JOIN user_department_position_map m ON m.user_id=a.user_id AND m.department_id=?
                              WHERE a.meeting_id=?");
        $ast->execute([$deptId, $meetingId]);
        foreach ($ast->fetchAll(PDO::FETCH_COLUMN) as $u) $targets[] = (int)$u;

        $mst = $db->prepare("SELECT DISTINCT m.user_id FROM user_department_position_map m
                              JOIN position_level pl ON pl.position_id=m.position_id
                              WHERE m.department_id=? AND pl.level IS NOT NULL");
        $mst->execute([$deptId]);
        $deptManagers = $mst->fetchAll(PDO::FETCH_COLUMN);
        // 該部門沒有任何職稱有登記在 position_level 的人(該表只涵蓋經理/副理/課長/副課長/組長/副組長，
        // 董事長室/總經理室/文管中心/採購組等部門的主要職稱不在其中)——退而求其次通知該部門所有「主要角色」
        // 成員，確保至少有人收到通知，不會因為「查無主管」就整個部門都沒人被通知(2026-08-07實測回報的案例：
        // 董事長室指派給未出席，因查無position_level主管而完全沒發出通知)。
        if (!$deptManagers) {
            $fst = $db->prepare("SELECT DISTINCT user_id FROM user_department_position_map WHERE department_id=? AND is_main=1");
            $fst->execute([$deptId]);
            $deptManagers = $fst->fetchAll(PDO::FETCH_COLUMN);
        }
        foreach ($deptManagers as $u) $targets[] = (int)$u;
    }
    $targets = array_values(array_unique($targets));
    if (!$targets) return [];
    $in = implode(',', $targets);
    $active = $db->query("SELECT id FROM `user` WHERE id IN ($in) AND state NOT IN (0,90)")->fetchAll(PDO::FETCH_COLUMN);
    $targets = array_map('intval', $active);
    return array_values(array_diff($targets, $confirmed));
}

/**
 * 依負責部門(可多個)算出「每個部門各一位必須現場簽名的人」(2026-08-05改版，使用者再次明確要求優先序)：
 * ①該部門(主要角色 is_main=1)本次有出席的主管優先(position_level有設level，取level最小＝職級最高)
 * ②該部門完全沒人以「主要角色」出席、或出席的主要角色member都不是主管，才輪到「兼任」(is_main=0)該部門的主管代簽
 * ③連兼任主管都沒有，才依職稱 position.sort_order 由高到低，取該部門(主要優先)出席人員中職稱排序最高者代簽
 * 回傳的 dept_name 一律是「要求簽章的部門」本身名稱(不是簽署人自己的主要部門)，is_main=false 代表這是用兼任身分代簽，
 * 前端需標示清楚(如「生管組(兼)」)避免跟簽署人實際所屬部門搞混。
 * 只回傳「該部門本次確實有人出席(含兼任)」的部門；完全沒人出席的部門不會出現在結果中，
 * 那種情況改走通知系統回覆(meeting_item_pending_notify_targets/meeting_item_confirm_via_notify)。
 * 回傳 [dept_id => ['user_id','user_name','dept_name','is_manager','is_main','dept_id']]。
 */
function meeting_item_required_signers(PDO $db, int $meetingId, array $ownerDeptIds): array {
    $out = [];
    foreach (array_unique(array_map('intval', $ownerDeptIds)) as $deptId) {
        if ($deptId <= 0) continue;
        $st = $db->prepare("SELECT a.user_id, a.user_name, d.name AS dept_name, p.name AS position_name, m.is_main, pl.level
                             FROM meeting_attendee a
                             JOIN user_department_position_map m ON m.user_id=a.user_id AND m.department_id=?
                             JOIN department d ON d.id=m.department_id
                             LEFT JOIN position p ON p.id=m.position_id
                             LEFT JOIN position_level pl ON pl.position_id=m.position_id
                             WHERE a.meeting_id=?
                             ORDER BY
                               CASE WHEN m.is_main=1 AND pl.level IS NOT NULL THEN 0
                                    WHEN m.is_main=0 AND pl.level IS NOT NULL THEN 1
                                    WHEN m.is_main=1 THEN 2
                                    ELSE 3 END ASC,
                               COALESCE(pl.level, 999) ASC,
                               COALESCE(p.sort_order, 999) ASC,
                               a.user_id ASC
                             LIMIT 1");
        $st->execute([$deptId, $meetingId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $out[$deptId] = ['user_id'=>(int)$r['user_id'], 'user_name'=>(string)$r['user_name'],
                                  'dept_name'=>(string)($r['dept_name'] ?: ''), 'position_name'=>(string)($r['position_name'] ?: ''),
                                  'is_manager'=>$r['level'] !== null,
                                  'is_main'=>(int)$r['is_main'] === 1, 'dept_id'=>$deptId];
    }
    return $out;
}

/**
 * 「指定人員」模式(2026-08-05使用者明確要求，與 owner_depts 部門模式二擇一，完全取代)：
 * 直接列出的人只要本次有出席就是必簽者，不套用主管優先判定(特別指名的，信任記錄人的判斷)。
 * 只回傳「本次有出席」的人；沒出席的人不會出現在結果中，改走通知系統回簽。
 * 回傳 [user_id => ['user_id','user_name','dept_name','is_manager'=true,'is_main'=true]]（is_manager/is_main
 * 固定回true讓前端不顯示「(代)/(兼)」標記，因為是指名而非自動判定出來的代理）。
 */
function meeting_item_required_signers_by_users(PDO $db, int $meetingId, array $userIds): array {
    $out = [];
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return $out;
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $st = $db->prepare("SELECT user_id, user_name, dept_name, position_name FROM meeting_attendee WHERE meeting_id=? AND user_id IN ($in)");
    $st->execute(array_merge([$meetingId], $userIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['user_id']] = ['user_id'=>(int)$r['user_id'], 'user_name'=>(string)$r['user_name'],
                                     'dept_name'=>(string)($r['dept_name'] ?: ''), 'position_name'=>(string)($r['position_name'] ?: ''),
                                     'is_manager'=>true, 'is_main'=>true, 'dept_id'=>null];
    }
    return $out;
}

/** 統一入口：依項目的 owner_users(指定人員模式，優先/完全取代) 或 owner_depts(部門自動判定模式) 算出必簽名單，兩者擇一。
 *  呼叫端一律用這支，不要自己 if/else 判斷 owner_users 是否有值，避免各處判斷邏輯漏改不一致。$item 需含 owner_users/owner_depts 欄位。 */
function meeting_item_required_signers_for(PDO $db, int $meetingId, array $item): array {
    $ownerUserIds = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_users'] ?? '')))));
    if ($ownerUserIds) return meeting_item_required_signers_by_users($db, $meetingId, $ownerUserIds);
    $ownerDeptIds = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_depts'] ?? '')))));
    if ($ownerDeptIds) return meeting_item_required_signers($db, $meetingId, $ownerDeptIds);
    return [];
}

/** 透過通知系統回簽/回覆（_eventRespond.php 的 MEETING_ITEM_CONFIRM 掛勾呼叫）：屬補充性質的異步回應留痕，
 *  跟「現場部門代表簽名」(meeting_item_required_signers，只認本次出席者)彼此獨立互不影響，任一人回覆都直接記錄一列。
 *  2026-08-06改版(使用者明確要求)：送出時的通知對象已擴大到「部門所有出席人員＋部門主管」，回覆的人不一定是
 *  當初系統挑出的那位現場必簽代表，因此改存 dept_id(而非只有 dept_name 文字)，讓畫面能用 dept_id 判定「這個
 *  部門的簽名槽是否已經由任何一位代表回覆」；$replyContent 有值時(reply動作)存回覆內容，供項目下方顯示。 */
function meeting_item_confirm_via_notify(PDO $db, int $itemId, int $uid, string $uname, ?string $replyContent = null): void {
    $st = $db->prepare("SELECT meeting_id, owner_depts, owner_users FROM meeting_item WHERE item_id=?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) return;
    $ownerUserIds = array_values(array_filter(array_map('intval', explode(',', (string)$item['owner_users']))));
    if ($ownerUserIds) {
        if (!in_array($uid, $ownerUserIds, true)) return; // 指定人員模式：不是被指名的人，不接受回簽
        $deptId = null; $deptName = null; // 指名模式不特別標部門，蓋章不帶部門
    } else {
        $ownerIds = array_values(array_filter(array_map('intval', explode(',', (string)$item['owner_depts']))));
        if (!$ownerIds) return;
        $in = implode(',', array_fill(0, count($ownerIds), '?'));
        $chk = $db->prepare("SELECT d.id, d.name FROM user_department_position_map m JOIN department d ON d.id=m.department_id
                              WHERE m.user_id=? AND m.department_id IN ($in) LIMIT 1");
        $chk->execute(array_merge([$uid], $ownerIds));
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) return; // 不屬於此項目的負責部門，不接受回簽
        $deptId = (int)$row['id']; $deptName = (string)$row['name'];
    }
    $db->prepare("INSERT INTO meeting_item_confirm (item_id, user_id, user_name, dept_name, dept_id, confirmed_at, reply_content)
                  VALUES (?,?,?,?,?,NOW(),?)
                  ON DUPLICATE KEY UPDATE reply_content=VALUES(reply_content)")
       ->execute([$itemId, $uid, $uname, $deptName, $deptId, $replyContent]);

    // 2026-08-10使用者實測回報：一部門/一指定人員只要任一人回覆就完成，但其餘被通知的人開啟同一則通知時
    // 還是看得到完整的回覆表單，容易讓人誤會「這樣回覆會不會蓋掉別人」。此項目所有負責部門(或所有指定人員)
    // 都已有人確認時，關閉這則通知，其餘人之後開啟只會看到已讀/已處理，不再能送出回覆。
    meeting_close_item_notice_if_done($db, $itemId);
    // 這一筆回簽可能就是最後一項——全部到齊就直接送主席簽核，不必記錄人再手動按一次（2026-08-26使用者要求）
    meeting_try_auto_submit($db, (int)$item['meeting_id']);
}

/** 這個項目的「所有負責部門/所有指定人員」是否都已確認(不論現場密碼簽名或通知回覆皆算)。
 *  沒指派負責人時視同已確認(不擋)。用於：①送出主席簽核前的把關(2026-08-10使用者明確要求恢復：負責人未確認不可
 *  送主席簽核) ②通知全部完成時關閉該則通知。多負責部門的項目要「全部部門」都有人確認才算完成。 */
function meeting_item_is_confirmed(PDO $db, array $item): bool {
    $ownerUserIds = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_users'] ?? '')))));
    $ownerDeptIds = $ownerUserIds ? [] : array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_depts'] ?? '')))));
    if (!$ownerUserIds && !$ownerDeptIds) return true;
    $st = $db->prepare("SELECT user_id, dept_id FROM meeting_item_confirm WHERE item_id=?");
    $st->execute([(int)($item['item_id'] ?? 0)]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($ownerUserIds) {
        $confirmedUserIds = array_map('intval', array_column($rows, 'user_id'));
        return !array_diff($ownerUserIds, $confirmedUserIds);
    }
    $confirmedDeptIds = array_values(array_unique(array_filter(array_map(fn($r) => $r['dept_id'] !== null ? (int)$r['dept_id'] : null, $rows))));
    return !array_diff($ownerDeptIds, $confirmedDeptIds);
}

/** 單一項目的「待確認」通知已全部完成，關閉這則通知(其餘被通知的人之後開啟只會看到唯讀狀態，無法再送出回覆)。 */
function meeting_close_single_item_notice(PDO $db, int $itemId): void {
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='MEETING_ITEM_CONFIRM' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$itemId]);
    } catch (Throwable $e) {}
}

/** 這個項目只要已經確認完成，就把它還在生效中的「待確認回簽」通知關掉（2026-08-26 使用者實測回報修正）。
 *  原本只有「透過通知回覆」那條路徑會關通知，**現場密碼簽名(item_confirm)與超管補齊(admin_backfill)都只寫了
 *  meeting_item_confirm 卻沒關通知**，造成兩個症狀：①同部門其他被通知的人一直收到「需要回簽」的通知，開進去
 *  還能再回覆一次（明明畫面上已經蓋好章）②meeting_has_active_item_notices() 永遠為真，整筆會議記錄卡在「回簽中」
 *  不能編輯也送不出簽核。**凡是新增 meeting_item_confirm 的路徑一律呼叫這支**，不要各自再寫一次判定。 */
function meeting_close_item_notice_if_done(PDO $db, int $itemId): void {
    try {
        $st = $db->prepare("SELECT item_id, owner_depts, owner_users FROM meeting_item WHERE item_id=?");
        $st->execute([$itemId]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if ($item && meeting_item_is_confirmed($db, $item)) meeting_close_single_item_notice($db, $itemId);
    } catch (Throwable $e) {}
}

/** 補關漏關的通知：把這筆會議記錄「已經確認完成、通知卻還開著」的項目一次關掉。
 *  用意是讓修正前留下來的舊資料（以及日後任何漏呼叫的新路徑）不必人工處理就會自動解除「回簽中」鎖定。
 *  沒有生效中的通知時只花一句查詢，成本可忽略。 */
function meeting_sync_item_notices(PDO $db, int $meetingId): void {
    try {
        $st = $db->prepare("SELECT DISTINCT mi.item_id, mi.owner_depts, mi.owner_users
                             FROM live_event le JOIN meeting_item mi ON mi.item_id=le.ref_id
                             WHERE le.ref_type='MEETING_ITEM_CONFIRM' AND mi.meeting_id=?
                               AND (le.enddate IS NULL OR le.enddate>=CURDATE())");
        $st->execute([$meetingId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
            if (meeting_item_is_confirmed($db, $it)) meeting_close_single_item_notice($db, (int)$it['item_id']);
        }
    } catch (Throwable $e) {}
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
 * 送出主席簽核（手動按鈕與「回簽完成自動送出」共用同一份實作）
 * 2026-08-26 使用者拍板三項：①模組設定可開關、預設開啟 ②三個條件（全部回簽完成／出席全部簽到／
 * 已指定主席）都滿足的那一刻就自動送 ③簽核紀錄上的送出人一律記「記錄人」，跟手動按送出完全一致。
 * ============================================================ */

/** 自動送簽核開關（模組設定；未設定＝開啟）。 */
function meeting_auto_submit_enabled(PDO $db): bool {
    return meeting_setting_get($db, 'meeting_auto_submit', '1') === '1';
}

/** 可不可以送主席簽核：回傳擋下的原因（空字串＝三個條件都到齊、可以送）。
 *  手動送出用它產生錯誤訊息，自動送出用它判斷時機，兩邊規則保證一致（不要各寫一份）。 */
function meeting_submit_blocker(PDO $db, array $m): string {
    $id = (int)$m['meeting_id'];
    if (!in_array((string)$m['status'], ['draft', 'rejected'], true)) return '此會議記錄已送出過';
    if (!$m['chair_user_id']) return '請先指定本次會議主席';
    $ac = $db->prepare("SELECT COUNT(*) FROM meeting_attendee WHERE meeting_id=?"); $ac->execute([$id]);
    if ((int)$ac->fetchColumn() === 0) return '請先加入出席人員名單';
    $itq = $db->prepare("SELECT * FROM meeting_item WHERE meeting_id=? ORDER BY kind, sort_order, item_id");
    $itq->execute([$id]);
    $items = $itq->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return '請至少建立一項會議要項或上級指示要項';
    $un = $db->prepare("SELECT COUNT(*) FROM meeting_attendee WHERE meeting_id=? AND signed=0"); $un->execute([$id]);
    if ((int)$un->fetchColumn() > 0) return '尚有出席人員未完成現場簽到，請先完成全部出席人員簽到再送出';
    foreach ($items as $it) {
        if (!meeting_item_is_confirmed($db, $it)) {
            return '項目「' . mb_substr((string)$it['content'], 0, 20) . '…」尚有負責部門/指定人員未確認回簽，請先「存檔並通知」，待對方回覆確認後再送出';
        }
    }
    return '';
}

/** 真正執行送出：建立主席簽核紀錄＋發通知＋狀態改 submitted。呼叫前請先自行跑過 meeting_submit_blocker()。
 *  $byUid/$byName＝送出人（自動送出時一律傳記錄人，讓主席收到的通知與簽核紀錄跟手動送出長得一樣）。
 *  回傳空字串＝成功，否則為錯誤訊息。 */
function meeting_submit_to_chair(PDO $db, array $m, int $byUid, string $byName): string {
    $id = (int)$m['meeting_id'];
    $chair = meeting_chair_signer_effective($db, (int)$m['chair_user_id'], (string)$m['chair_name']);
    if (!$chair['id']) return '找不到主席簽核人';
    $apId = eg_approval_submit($db, 'meeting', $id, 'chair', $byUid, $byName);
    $ev = meeting_notify($db, $id, $chair['id'],
        '「' . $m['subject'] . '」會議記錄待主席確認簽章',
        $byName . ' 送出「' . $m['subject'] . '」（' . $m['meeting_date'] . '）會議記錄，請確認內容並簽章（點入可看完整會議要項，並直接確認或退回）。'
        . ($chair['is_delegated'] ? '（原主席今日行程忙碌，已轉由代理人處理）' : ''), $byUid);
    if ($ev) eg_approval_set_live_event($db, $apId, $ev);
    $db->prepare("UPDATE meeting_record SET status='submitted', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
    return '';
}

/** 三個條件都到齊時自動送出主席簽核，並回報記錄人一聲（使用者要求：不必再手動按一次送簽核）。
 *  凡是「可能讓最後一個條件成立」的動作做完後都呼叫這支：項目確認（現場密碼簽名／通知回覆）、出席簽到、存檔（指定主席）。
 *  刻意不在超管「補齊簽章日期」時呼叫——那是補歷史紙本用的救濟工具，scope=all 本來就會自己把整條簽核鏈補完，
 *  在那裡自動送出只會多發一則主席通知。回傳 true＝這次真的送出去了。 */
function meeting_try_auto_submit(PDO $db, int $meetingId): bool {
    try {
        if (!meeting_auto_submit_enabled($db)) return false;
        $st = $db->prepare("SELECT * FROM meeting_record WHERE meeting_id=?");
        $st->execute([$meetingId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) return false;
        if (meeting_submit_blocker($db, $m) !== '') return false;
        $byUid = (int)$m['recorder_user_id'];
        $byName = (string)$m['recorder_name'];
        if (!$byUid) return false;
        if (meeting_submit_to_chair($db, $m, $byUid, $byName) !== '') return false;
        // 記錄人不一定是觸發的人（多半是別人回簽補上最後一項），一定要讓他知道已經送出去了
        meeting_notify_result($db, $meetingId, $byUid,
            '「' . $m['subject'] . '」會議記錄已自動送出主席簽核',
            '「' . $m['subject'] . '」（' . $m['meeting_date'] . '）的出席簽到與負責部門回簽都已全部完成，系統已自動送交主席確認簽章，不需要再手動送出。',
            $byUid);
        return true;
    } catch (Throwable $e) { return false; }
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
 * 超級管理員：補齊/修改簽章日期（僅員工id=1且state=99；2026-08-05使用者明確要求，比照
 * eg_leave_verify_superadmin_password 慣例不另發明一套）。用途：出席簽到/項目確認簽名/主席
 * 總經理簽核忘記簽或日期需要更正時的救濟，密碼每次呼叫都驗證(fail-closed)，前端只需畫面上輸入一次重複帶入即可。
 * ============================================================ */
function meeting_is_superadmin(PDO $db, int $uid): bool {
    if ($uid !== 1) return false;
    try {
        $st = $db->prepare("SELECT state FROM user WHERE id=1 LIMIT 1");
        $st->execute();
        return (int)$st->fetchColumn() === 99;
    } catch (Throwable $e) { return false; }
}
function meeting_verify_superadmin_password(PDO $db, string $password): array {
    if ($password === '') return ['ok'=>false, 'msg'=>'請輸入超級管理員密碼'];
    try {
        $st = $db->prepare("SELECT user_password FROM `user` WHERE id=1 LIMIT 1");
        $st->execute();
        $real = $st->fetchColumn();
        if ($real === false) return ['ok'=>false, 'msg'=>'查無超級管理員帳號'];
        if (!hash_equals((string)$real, $password)) return ['ok'=>false, 'msg'=>'密碼錯誤'];
    } catch (Throwable $e) { return ['ok'=>false, 'msg'=>'密碼驗證失敗']; }
    return ['ok'=>true, 'msg'=>''];
}

/* ============================================================
 * 出席人員候選過濾(2026-08-06使用者明確要求)：不應該選得到「會議當天時段有請假(含留職停薪等)」的人員；
 * 超級管理員(state=99)本身已透過 eg_people_list 的 states 參數在呼叫端排除，這裡只處理請假時段重疊判斷。
 * 沒有會議時間(start/end)時視為整天，用 00:00~23:59 判斷；重用 leave_lib 既有的 eg_leave_user_busy_in_range()
 * （pending/cancel_pending/approved 皆算，跟代理系統判斷忙碌的邏輯一致，不另外發明一套）。
 * ============================================================ */
function meeting_filter_available_people(PDO $db, array $rows, string $meetingDate, ?string $startTime, ?string $endTime): array {
    if (!$rows || $meetingDate === '') return $rows;
    require_once __DIR__ . '/leave_lib.php';
    $start = $meetingDate . ' ' . ($startTime ?: '00:00') . ':00';
    $end   = $meetingDate . ' ' . ($endTime   ?: '23:59') . ':59';
    return array_values(array_filter($rows, function($r) use ($db, $start, $end) {
        $uid = (int)($r['id'] ?? 0);
        return $uid > 0 && !eg_leave_user_busy_in_range($db, $uid, $start, $end);
    }));
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
