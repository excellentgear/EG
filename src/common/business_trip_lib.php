<?php
/**
 * 公出單（2-MM-01-06）共用庫
 * ------------------------------------------------------------------
 * 使用者決策（2026-08-18 以 AskUserQuestion 拍板）：
 *   ①外訓場次「確認開課」時，為每位參加人員各自動產生一張草稿（可關閉）
 *   ②多天公出＝一張涵蓋整個期間（起訖日期＋每日時段明細）
 *   ③簽核只有「單位主管」一關；超級管理員可設免簽核（送出即核准）
 *   ④全新獨立頁面，**全體在職員工都能開自己的單**（不限教育訓練），管理員可代開/查全部
 * 紙本附註（已實作）：主管本人公出時，核准人自動改為最高核准人員（總經理）。
 * 代理：核准人一律走 delegate_lib 的 eg_resolve_signer()（ai-rules/11），不自己猜代理人。
 * 自動核准：依 ai-rules/21——業務日期(submit_date/approved_date)與精確時間戳分離、
 *          自動簽核時間隨機錯開送出時間 5~30 分鐘且不跨天。
 */

require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/people_lib.php';

const BT_ASDOC_MODULE = 'business_trip';          // AS 文件綁定模組代碼（asdoc_lib）
const BT_SETTING_KEYS = ['bt_need_approval', 'bt_auto_from_training', 'bt_stamp_tpl_id',
                         'bt_sign_acc', 'bt_sign_section', 'bt_sign_group', 'bt_commute_min'];
/** 列印簽章格的來源選項（兩格：會計固定留白／單位主管）；值一律存設定，不在別處寫死對照表（鐵律4） */
const BT_SIGN_SOURCES = [
    ''          => '（留白，紙本手蓋）',
    'approver'  => '實際核准的單位主管',
    'acc_dept'  => '會計部門主管（組織角色綁定）',
    'sup'       => '申請人的上一級主管',
    'top'       => '最高核准人員（組織角色綁定）',
];

function bt_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS business_trip (
            trip_id       INT AUTO_INCREMENT PRIMARY KEY,
            trip_no       VARCHAR(30) NULL COMMENT '單號（BT-西元年月-流水）',
            apply_date    DATE NOT NULL COMMENT '單據日期（列印右上角 年月日）＝業務日期',
            user_id       INT NOT NULL COMMENT '公出人',
            user_name     VARCHAR(60) NULL,
            dept_id       INT NULL,
            dept_name     VARCHAR(100) NULL,
            position_name VARCHAR(100) NULL COMMENT '級職（快照，可手改）',
            date_from     DATE NOT NULL,
            date_to       DATE NOT NULL,
            time_from     VARCHAR(5) NULL,
            time_to       VARCHAR(5) NULL,
            location      VARCHAR(200) NULL COMMENT '公出地點',
            reason        TEXT NULL COMMENT '事由',
            status        VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/approved/rejected',
            source        VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual/training',
            ref_type      VARCHAR(30) NULL,
            ref_id        INT NULL,
            approver_id   INT NULL,
            approver_name VARCHAR(60) NULL,
            is_delegated  TINYINT NOT NULL DEFAULT 0,
            is_auto       TINYINT NOT NULL DEFAULT 0 COMMENT '1=系統自動核准（免簽核或查無主管）',
            auto_note     VARCHAR(200) NULL,
            submit_date   DATE NULL COMMENT '送出業務日期',
            submitted_at  DATETIME NULL,
            approved_date DATE NULL COMMENT '核准業務日期',
            approved_at   DATETIME NULL,
            decide_note   VARCHAR(500) NULL COMMENT '核准意見／退回原因',
            created_by    INT NULL,
            created_at    DATETIME NULL,
            updated_at    DATETIME NULL,
            is_deleted    TINYINT NOT NULL DEFAULT 0,
            KEY idx_user (user_id, apply_date),
            KEY idx_status (status),
            KEY idx_ref (ref_type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公出單 2-MM-01-06'");
        $db->exec("CREATE TABLE IF NOT EXISTS business_trip_day (
            day_id     INT AUTO_INCREMENT PRIMARY KEY,
            trip_id    INT NOT NULL,
            day_no     INT NOT NULL,
            day_date   DATE NOT NULL,
            start_time VARCHAR(5) NULL,
            end_time   VARCHAR(5) NULL,
            KEY idx_trip (trip_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公出單每日時段（多天且各天時段不同時使用）'");
        // 公出單專屬角色（module='business_trip'）。一般員工不需角色就能開自己的單，這兩個是加值角色。
        foreach ([['business_trip_view_all', '公出單檢閱'], ['business_trip_admin', '公出單管理員']] as $r) {
            $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='business_trip' LIMIT 1");
            $st->execute([$r[0]]);
            if (!$st->fetchColumn()) {
                $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'business_trip')")
                   ->execute([$r[0], $r[1]]);
            }
        }
    } catch (Throwable $e) {}
}

/* ============================ 使用者與權限 ============================ */

function bt_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bt_has_role(PDO $db, int $uid, array $codes): bool
{
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='business_trip' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * 權限：
 *   isAdmin    系統管理者（固定全權）
 *   canAdmin   公出單管理員：查全部、代開、刪除、模組設定、AS 文件綁定
 *   canViewAll 可查全部（唯讀）
 *   canApply   可開自己的單＝全體在職員工（離職/特殊帳號除外）
 */
function bt_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canViewAll'=>false,'canApply'=>false,'uid'=>0];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)($u['user_status'] ?? 0), [9, 90], true);
    if (!$isAdmin) {
        try {
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
            $st->execute([$uid]);
            $isAdmin = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    $canAdmin   = $isAdmin || bt_has_role($db, $uid, ['business_trip_admin']);
    $canViewAll = $canAdmin || bt_has_role($db, $uid, ['business_trip_view_all']);
    $canApply   = !in_array((int)($u['state'] ?? 1), [0, 90], true);   // 在職就能開自己的單
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canViewAll'=>$canViewAll, 'canApply'=>$canApply, 'uid'=>$uid];
}

/* ============================ 設定 ============================ */

function bt_settings(PDO $db): array
{
    $out = ['bt_need_approval'=>1, 'bt_auto_from_training'=>1, 'bt_stamp_tpl_id'=>null,
            'bt_sign_acc'=>'', 'bt_sign_section'=>'', 'bt_sign_group'=>'approver',
            'bt_commute_min'=>30];      // 外訓帶入時公出時間前後各加的通勤時間（分鐘）
    try {
        $in = implode(',', array_fill(0, count(BT_SETTING_KEYS), '?'));
        $st = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(BT_SETTING_KEYS);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['setting_key']; $v = $r['setting_value'];
            if (in_array($k, ['bt_need_approval', 'bt_auto_from_training', 'bt_commute_min'], true)) $out[$k] = (int)$v;
            elseif ($k === 'bt_stamp_tpl_id')  $out[$k] = ($v === '' || $v === null) ? null : (int)$v;
            else                               $out[$k] = (string)$v;
        }
    } catch (Throwable $e) {}
    return $out;
}

function bt_save_setting(PDO $db, string $key, $val): void
{
    if (!in_array($key, BT_SETTING_KEYS, true)) return;
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $st->execute([$key, (string)$val]);
}


/** 核准圖章要套用的模板（system_settings key bt_stamp_tpl_id）；未設定或停用回 null（消費端退回預設圓形印章） */
function bt_stamp_template(PDO $db): ?array
{
    $id = (int)(bt_settings($db)['bt_stamp_tpl_id'] ?? 0);
    if (!$id) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)];
    } catch (Throwable $e) { return null; }
}

/* ============================ 單據讀取 ============================ */

function bt_trip_row(PDO $db, int $tripId): ?array
{
    $st = $db->prepare("SELECT * FROM business_trip WHERE trip_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$tripId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $d = $db->prepare("SELECT day_no, day_date, start_time, end_time FROM business_trip_day WHERE trip_id=? ORDER BY day_no");
    $d->execute([$tripId]);
    $r['days'] = $d->fetchAll(PDO::FETCH_ASSOC);
    return $r;
}

/** 單號：BT-YYYYMM-nnn（同月流水，缺號不補；只是給人看的，不當唯一鍵） */
function bt_next_no(PDO $db, string $applyDate): string
{
    $ym = str_replace('-', '', substr($applyDate, 0, 7));
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM business_trip WHERE DATE_FORMAT(apply_date,'%Y%m')=?");
        $st->execute([$ym]);
        $n = (int)$st->fetchColumn() + 1;
    } catch (Throwable $e) { $n = 1; }
    return 'BT-' . $ym . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/** 公出人的部門/級職預設值（主要職務身分；查不到回空字串） */
function bt_user_identity(PDO $db, int $uid): array
{
    $out = ['dept_id'=>null, 'dept_name'=>'', 'position_name'=>'', 'user_name'=>''];
    try {
        $st = $db->prepare("SELECT u.user_cname, m.department_id, d.name AS dept_name, p.name AS position_name
                            FROM `user` u
                            LEFT JOIN user_department_position_map m ON m.user_id=u.id AND m.is_main=1
                            LEFT JOIN department d ON d.id=m.department_id
                            LEFT JOIN position p ON p.id=m.position_id
                            WHERE u.id=? LIMIT 1");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $out['user_name']     = (string)$r['user_cname'];
            $out['dept_id']       = $r['department_id'] !== null ? (int)$r['department_id'] : null;
            $out['dept_name']     = (string)($r['dept_name'] ?? '');
            $out['position_name'] = (string)($r['position_name'] ?? '');
        }
    } catch (Throwable $e) {}
    return $out;
}

/* ============================ 核准人解析 ============================ */

/**
 * 核准人＝公出人所屬單位的部門主管；本人就是該主管（主管公出）→ 改用最高核准人員（總經理），
 * 比照紙本附註「主管公出請總經理代理」。最後再走 delegate_lib 做代理/請假轉派（ai-rules/11）。
 * 回傳 ['id','name','base_id','is_delegated','reason'] 或 null（查無人可簽 → 呼叫端自動核准）。
 */
function bt_resolve_approver(PDO $db, ?int $deptId, int $tripUserId, bool $autoSign = false): ?array
{
    $base = null; $why = ''; $selfOk = false;
    $mgr = $deptId ? eg_org_dept_manager($db, $deptId) : null;
    if ($mgr && (int)$mgr['id'] !== $tripUserId) {
        $base = (int)$mgr['id'];
        $why  = '單位主管';
    } else {
        // 主管本人公出 → 往上找全站最高決策者。**最高決策者本人公出時就由他自己簽**（使用者定案）：
        // 他已經是全站最高一層，再往上沒有人，硬套 SoD 迴避只會變成「查無核准人」而讓單子沒人簽、簽章欄空白。
        $top = eg_org_user($db, 'top_approver');
        if ($top) {
            $base = (int)$top['id'];
            if ($base === $tripUserId) {
                $selfOk = true;
                $why    = '最高決策者本人公出，由本人核准（全站已無更上層可簽）';
            } else {
                $why = $mgr ? '主管本人公出，改由最高核准人員核准' : '查無單位主管，改由最高核准人員核准';
            }
        }
    }
    if (!$base) return null;
    $signerId = $base; $delegated = false; $reason = $why;
    try {
        $rs = eg_resolve_signer($db, $base, ['applicant_id'=>$tripUserId, 'scope_department_id'=>$deptId,
                                             'flow_key'=>'business_trip', 'auto_sign'=>$autoSign]);
        if (!empty($rs['signer_id'])) {
            $signerId  = (int)$rs['signer_id'];
            $delegated = !empty($rs['is_delegated']) || !empty($rs['is_sod_escalated']);
            if ($delegated) $reason = $why . '；' . ($rs['reason'] ?? '轉由代理人簽核');
        }
    } catch (Throwable $e) {}
    // 迴避：解析結果又繞回公出人本人（唯獨「最高決策者本人公出」例外，見上方）
    if ($signerId === $tripUserId && !$selfOk) return null;
    $nm = '';
    try {
        $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
        $st->execute([$signerId]);
        $nm = (string)$st->fetchColumn();
    } catch (Throwable $e) {}
    return ['id'=>$signerId, 'name'=>$nm, 'base_id'=>$base, 'is_delegated'=>$delegated,
            'is_self'=>($signerId === $tripUserId), 'reason'=>$reason];
}

/** 自動核准的時間戳：業務日期＝送出日，精確時間隨機錯開 5~30 分鐘且不跨日（ai-rules/21） */
function bt_auto_sign_time(string $submittedAt): string
{
    $base = strtotime($submittedAt);
    $ts   = $base + random_int(5, 30) * 60;
    if (date('Y-m-d', $ts) !== date('Y-m-d', $base)) {
        $ts = strtotime(date('Y-m-d', $base) . ' 23:59:00');
    }
    return date('Y-m-d H:i:s', $ts);
}

/* ============================ 通知（ai-rules/17） ============================ */

/** 公出期間的顯示字串（顯示格式一律 YYYY.MM.DD，見 ai-rules/20） */
function bt_period_text(array $trip): string
{
    $f = str_replace('-', '.', (string)$trip['date_from']);
    $t = str_replace('-', '.', (string)$trip['date_to']);
    $tm = (string)($trip['time_from'] ?? '') . (($trip['time_to'] ?? '') ? '~' . $trip['time_to'] : '');
    return ($f === $t ? $f : $f . '～' . $t) . ($tm ? ' ' . $tm : '');
}

function bt_notify_approver(PDO $db, array $trip, int $toUid, int $fromUid): int
{
    if (!$toUid) return 0;
    $title   = '公出單待核准：' . ($trip['user_name'] ?: '') . '　' . bt_period_text($trip);
    $content = '公出人：' . $trip['user_name'] . '（' . $trip['dept_name'] . '　' . $trip['position_name'] . '）' . "\n"
             . '公出時間：' . bt_period_text($trip) . "\n"
             . '公出地點：' . ($trip['location'] ?: '（未填）') . "\n"
             . '事　　由：' . ($trip['reason'] ?: '（未填）') . "\n"
             . '單號：' . ($trip['trip_no'] ?: ('#' . $trip['trip_id'])) . '　點此開啟公出單，可直接核准或退回（退回須填原因）。';
    try {
        bt_close_notice($db, (int)$trip['trip_id']);
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '公出單', 1, 'BUSINESS_TRIP_APPROVAL', ?)")
           ->execute([$title, $content, $fromUid, (int)$trip['trip_id']]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
           ->execute([$eid, $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

function bt_close_notice(PDO $db, int $tripId): void
{
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='BUSINESS_TRIP_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$tripId]);
    } catch (Throwable $e) {}
}

function bt_notify_result(PDO $db, array $trip, int $toUid, string $decision, string $note, int $fromUid, string $byName): void
{
    if (!$toUid) return;
    $ok      = ($decision === 'approved');
    $title   = '公出單' . ($ok ? '已核准' : '已退回') . '：' . bt_period_text($trip);
    $content = $byName . ($ok ? ' 已核准您的公出單。' . ($note ? '意見：' . $note : '')
                              : ' 已退回您的公出單。退回原因：' . $note);
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '公出單', 1, 'BUSINESS_TRIP_RESULT', ?)")
           ->execute([$title, $content, $fromUid, (int)$trip['trip_id']]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
           ->execute([$eid, $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

/* ============================ 列印簽章三格 ============================ */

/**
 * 列印用的三格簽章人（會計／課長／組長），來源由模組設定決定（BT_SIGN_SOURCES），不寫死人名。
 * 未核准的單一律由呼叫端不蓋章，這裡只負責「誰該蓋」。
 */
function bt_print_signers(PDO $db, array $trip): array
{
    $set  = bt_settings($db);
    $pick = function (string $src) use ($db, $trip) {
        switch ($src) {
            case 'approver':
                return (string)($trip['approver_name'] ?? '');
            case 'acc_dept':
                $ids = eg_org_dept_ids($db, 'acc_dept');
                $m   = $ids ? eg_org_dept_manager($db, $ids) : null;
                return $m ? (string)$m['user_cname'] : '';
            case 'sup':
                $sup = eg_resolve_supervisor($db, (int)$trip['user_id'], $trip['dept_id'] !== null ? (int)$trip['dept_id'] : null);
                if (!$sup) return '';
                $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
                $st->execute([$sup]);
                return (string)$st->fetchColumn();
            case 'top':
                $t = eg_org_user($db, 'top_approver');
                return $t ? (string)$t['user_cname'] : '';
        }
        return '';
    };
    // 簽章欄只有兩格：會計固定留白（只有需要請款時才由會計手蓋）、單位主管蓋實際核准人
    return ['acc'     => '',
            'section' => '',
            'group'   => $pick((string)$set['bt_sign_group'])];
}

/* ============================ 教育訓練自動產生 ============================ */

/**
 * 外訓場次「確認開課」後自動產生公出單草稿（每位參加人員各一張，一張涵蓋整個期間）。
 * 已存在（同場次同人、未刪除）就不重複產生；仍是草稿才更新日期/時段/地點/事由，已送出/已核准的不覆蓋。
 * 回傳 ['created'=>n,'updated'=>n,'skipped'=>n]；任何錯誤都吞掉不影響訓練存檔（呼叫端在 commit 之後呼叫）。
 */
function bt_create_from_training(PDO $db, int $sessionId): array
{
    $out = ['created'=>0, 'updated'=>0, 'skipped'=>0];
    try {
        bt_ensure_schema($db);
        $set = bt_settings($db);
        if (!(int)$set['bt_auto_from_training']) return $out;
        $st = $db->prepare("SELECT * FROM training_session WHERE session_id=?");
        $st->execute([$sessionId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s || (string)$s['train_type'] !== 'external') return $out;      // 只有外訓要開公出單
        $days = bt_session_days($db, $s);          // 已依 day_date 排序，第一筆即最早那天
        if (!$days) return $out;
        $days     = bt_days_with_commute($days, (int)($set['bt_commute_min'] ?? 30));   // 公出時間前後各留通勤時間
        $dateFrom = $days[0]['day_date'];
        $dateTo   = $days[count($days) - 1]['day_date'];
        $timeFrom = $days[0]['start_time'];
        $timeTo    = $days[count($days) - 1]['end_time'];
        $location = (string)($s['location'] ?? '');
        $reason   = bt_training_reason($s);
        $aq = $db->prepare("SELECT user_id, user_name, dept_name, position_name FROM training_attendee WHERE session_id=?");
        $aq->execute([$sessionId]);
        // 單據日期＝該場外訓最早一天（使用者要求：從外訓帶入的單據日期＝外訓最早日期；補舊資料才不會全印成今天）
        $applyDate = $dateFrom;
        foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $uidA = (int)$a['user_id'];
            if (!$uidA) continue;
            $ex = $db->prepare("SELECT trip_id, status FROM business_trip
                                WHERE ref_type='training_session' AND ref_id=? AND user_id=? AND COALESCE(is_deleted,0)=0
                                ORDER BY trip_id LIMIT 1");
            $ex->execute([$sessionId, $uidA]);
            $old = $ex->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ($old['status'] !== 'draft') { $out['skipped']++; continue; }
                $db->prepare("UPDATE business_trip SET apply_date=?, date_from=?, date_to=?, time_from=?, time_to=?, location=?, reason=?, updated_at=NOW()
                              WHERE trip_id=?")
                   ->execute([$applyDate, $dateFrom, $dateTo, $timeFrom, $timeTo, $location, $reason, (int)$old["trip_id"]]);
                bt_replace_days($db, (int)$old['trip_id'], $days);
                $out['updated']++;
                continue;
            }
            $who = bt_resolve_user_dept($db, $uidA, (string)($a['dept_name'] ?? ''), (string)($a['position_name'] ?? ''));
            $db->prepare("INSERT INTO business_trip
                          (trip_no, apply_date, user_id, user_name, dept_id, dept_name, position_name,
                           date_from, date_to, time_from, time_to, location, reason,
                           status, source, ref_type, ref_id, created_by, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'draft','training','training_session',?,?,NOW(),NOW())")
               ->execute([bt_next_no($db, $applyDate), $applyDate, $uidA,
                          $a['user_name'] ?: $who['user_name'],
                          $who['dept_id'], $who['dept_name'], $who['position_name'],
                          $dateFrom, $dateTo, $timeFrom, $timeTo, $location, $reason, $sessionId, $uidA]);
            bt_replace_days($db, (int)$db->lastInsertId(), $days);
            $out['created']++;
        }
    } catch (Throwable $e) {}
    return $out;
}

function bt_replace_days(PDO $db, int $tripId, array $days): void
{
    try {
        $db->prepare("DELETE FROM business_trip_day WHERE trip_id=?")->execute([$tripId]);
        if (count($days) <= 1) return;         // 單日不必存明細，主檔的起訖就夠了
        $ins = $db->prepare("INSERT INTO business_trip_day (trip_id, day_no, day_date, start_time, end_time) VALUES (?,?,?,?,?)");
        foreach ($days as $i => $d) {
            $ins->execute([$tripId, $i + 1, $d['day_date'], ($d['start_time'] ?: null), ($d['end_time'] ?: null)]);
        }
    } catch (Throwable $e) {}
}

/** 時刻正規化（0900/900/9 → 09:00）；空字串回空字串，格式不對回 null 由呼叫端擋 */
function bt_norm_time($v): ?string
{
    $s = trim((string)$v);
    if ($s === '') return '';
    if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $s, $m))      { $h = (int)$m[1]; $i = (int)$m[2]; }
    elseif (preg_match('/^(\d{3,4})$/', $s, $m))            { $h = (int)substr($m[1], 0, -2); $i = (int)substr($m[1], -2); }
    elseif (preg_match('/^(\d{1,2})$/', $s, $m))            { $h = (int)$m[1]; $i = 0; }
    else return null;
    if ($h < 0 || $h > 23 || $i < 0 || $i > 59) return null;
    return sprintf('%02d:%02d', $h, $i);
}

/**
 * 兼任人員的身分解析：一個人可能同時掛技術部工程師（主要）與生管組組長（兼任），
 * 外訓當時登記的是哪個身分（training_attendee 的 dept_name/position_name 快照），公出單就要跟著印那個身分，
 * 不能一律套主要職務。做法：先在這個人自己的部門職務對應中找同名部門，找到就連同該部門的職稱一起回傳；
 * 找不到才退回全域部門表查 id（職稱沿用快照文字），再不行才用主要職務。
 */
function bt_resolve_user_dept(PDO $db, int $uid, string $deptName, string $posName = ''): array
{
    $ident = bt_user_identity($db, $uid);
    $out = ['dept_id'=>$ident['dept_id'], 'dept_name'=>$ident['dept_name'],
            'position_name'=>($posName !== '' ? $posName : $ident['position_name']),
            'user_name'=>$ident['user_name']];
    $deptName = trim($deptName);
    if ($deptName === '') return $out;
    try {
        $st = $db->prepare("SELECT m.department_id, d.name AS dept_name, p.name AS position_name
                            FROM user_department_position_map m
                            LEFT JOIN department d ON d.id=m.department_id
                            LEFT JOIN position p ON p.id=m.position_id
                            WHERE m.user_id=? ORDER BY m.is_main DESC");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (trim((string)$r['dept_name']) === $deptName) {      // 這個人確實掛在這個部門（含兼任）
                return ['dept_id'=>(int)$r['department_id'], 'dept_name'=>(string)$r['dept_name'],
                        'position_name'=>($posName !== '' ? $posName : (string)$r['position_name']),
                        'user_name'=>$ident['user_name']];
            }
        }
        $d = $db->prepare("SELECT id, name FROM department WHERE name=? LIMIT 1");   // 已調離等情況：至少把部門對上
        $d->execute([$deptName]);
        if ($r = $d->fetch(PDO::FETCH_ASSOC)) {
            $out['dept_id']   = (int)$r['id'];
            $out['dept_name'] = (string)$r['name'];
            return $out;
        }
        $out['dept_name'] = $deptName;      // 部門表也查不到（舊名稱），至少印當時登記的文字
        $out['dept_id']   = null;
    } catch (Throwable $e) {}
    return $out;
}

/** 這張公出單綁的外訓場次資訊（課程名稱＋原始上課日期時間），供編輯時對照用；沒綁外訓回 null */
function bt_trip_training_ref(PDO $db, array $trip): ?array
{
    if ((string)($trip['ref_type'] ?? '') !== 'training_session' || !(int)($trip['ref_id'] ?? 0)) return null;
    try {
        $st = $db->prepare("SELECT * FROM training_session WHERE session_id=?");
        $st->execute([(int)$trip['ref_id']]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return null;
        return [
            'session_id'  => (int)$s['session_id'],
            'course_name' => (string)$s['course_name'],
            'org_unit'    => (string)($s['org_unit'] ?? ''),
            'location'    => (string)($s['location'] ?? ''),
            'class_days'  => bt_session_days($db, $s),
            'commute_min' => (int)(bt_settings($db)['bt_commute_min'] ?? 30),
        ];
    } catch (Throwable $e) { return null; }
}

/**
 * 時刻前後位移（通勤時間用）。跨越 00:00／24:00 一律夾在當日範圍內——公出單是以「日」為單位的單據，
 * 讓時間跑到前一天或隔天只會讓單子看起來像跨日，寧可貼齊 00:00／23:59。
 */
function bt_shift_time(?string $hhmm, int $deltaMin): string
{
    $t = bt_norm_time($hhmm);
    if ($t === null || $t === '') return '';
    [$h, $i] = array_map('intval', explode(':', $t));
    $m = $h * 60 + $i + $deltaMin;
    if ($m < 0)    $m = 0;
    if ($m > 1439) $m = 1439;
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** 外訓的逐日上課時段 → 公出用時段（前後各加通勤時間）；原始上課時間另外保留給畫面對照 */
function bt_days_with_commute(array $days, int $commuteMin): array
{
    $out = [];
    foreach ($days as $d) {
        $out[] = [
            'day_date'     => $d['day_date'],
            'start_time'   => bt_shift_time($d['start_time'] ?? '', -$commuteMin),
            'end_time'     => bt_shift_time($d['end_time'] ?? '', +$commuteMin),
            'class_start'  => (string)($d['start_time'] ?? ''),      // 原始上課時間（對照用，不寫進 DB）
            'class_end'    => (string)($d['end_time'] ?? ''),
        ];
    }
    return $out;
}

/* ============================ 從教育訓練外訓帶入（供使用者自行帶入用） ============================ */

/**
 * 一個外訓場次的逐日時段（沒建逐日資料時退回 done_date 當單日）。
 * 一律依 day_date 由小到大排序——「最早日期」要靠這個排序決定，不採信 day_no。
 */
function bt_session_days(PDO $db, array $s): array
{
    $days = [];
    try {
        $dq = $db->prepare("SELECT day_date, start_time, end_time FROM training_session_day
                            WHERE session_id=? ORDER BY day_date, day_no");
        $dq->execute([(int)$s['session_id']]);
        $days = $dq->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    if (!$days && !empty($s['done_date'])) {
        $days = [['day_date'=>$s['done_date'], 'start_time'=>$s['start_time'] ?? null, 'end_time'=>$s['end_time'] ?? null]];
    }
    usort($days, fn($a, $b) => strcmp((string)$a['day_date'], (string)$b['day_date']));
    return $days;
}

/** 外訓帶入公出單的事由文字（課程名稱＋外訓單位），與自動產生的寫法一致 */
function bt_training_reason(array $s): string
{
    return '參加外訓：' . (string)$s['course_name']
         . ((string)($s['org_unit'] ?? '') !== '' ? '（' . $s['org_unit'] . '）' : '');
}

/**
 * 指定人員可帶入的外訓場次清單（本人有列在參加人員名單、且已「確認實行」＝已排定或已完成的外訓）。
 * $uid=0 代表不限人員（管理員撈全部，方便補資料）。
 */
function bt_user_training_sessions(PDO $db, int $uid, int $year): array
{
    $sql = "SELECT s.session_id, s.course_name, s.org_unit, s.location, s.done_date, s.status,
                   s.start_time, s.end_time,
                   (SELECT MIN(d.day_date) FROM training_session_day d WHERE d.session_id=s.session_id) AS d_min,
                   (SELECT MAX(d.day_date) FROM training_session_day d WHERE d.session_id=s.session_id) AS d_max,
                   (SELECT COUNT(*) FROM training_session_day d WHERE d.session_id=s.session_id) AS day_cnt";
    $par = [];
    if ($uid) {
        $sql .= ", a.user_id, a.user_name,
                  (SELECT t.status FROM business_trip t
                    WHERE t.ref_type='training_session' AND t.ref_id=s.session_id AND t.user_id=a.user_id
                      AND COALESCE(t.is_deleted,0)=0 ORDER BY t.trip_id LIMIT 1) AS trip_status
                  FROM training_session s
                  JOIN training_attendee a ON a.session_id=s.session_id AND a.user_id=?";
        $par[] = $uid;
    } else {
        $sql .= ", 0 AS user_id, '' AS user_name, NULL AS trip_status,
                  (SELECT COUNT(*) FROM training_attendee a WHERE a.session_id=s.session_id) AS att_cnt
                  FROM training_session s";
    }
    $sql .= " WHERE s.train_type='external' AND s.status IN ('scheduled','done')";
    if ($year) { $sql .= " AND s.year=?"; $par[] = $year; }
    $sql .= " ORDER BY COALESCE((SELECT MIN(d.day_date) FROM training_session_day d WHERE d.session_id=s.session_id),
                                s.done_date) DESC, s.session_id DESC";
    try {
        $st = $db->prepare($sql);
        $st->execute($par);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $r['date_from'] = $r['d_min'] ?: $r['done_date'];
        $r['date_to']   = $r['d_max'] ?: $r['done_date'];
    }
    return $rows;
}

/**
 * 取一個外訓場次要帶進公出單的內容。
 * 單據日期（apply_date）＝該場外訓的**最早一天**（使用者明確要求；補舊資料時才不會全部印成今天）。
 * $uid 有給就檢查此人確實是該場次的參加人員（後端守門，避免直打 API 撈別人的訓練）。
 */
function bt_training_fill(PDO $db, int $sessionId, int $uid): ?array
{
    $st = $db->prepare("SELECT * FROM training_session WHERE session_id=?");
    $st->execute([$sessionId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s || (string)$s['train_type'] !== 'external') return null;
    if (!in_array((string)$s['status'], ['scheduled', 'done'], true)) return null;   // 未「確認實行」的不給帶
    $att = null;
    if ($uid) {
        $aq = $db->prepare("SELECT user_id, user_name, dept_name, position_name FROM training_attendee
                            WHERE session_id=? AND user_id=? LIMIT 1");
        $aq->execute([$sessionId, $uid]);
        $att = $aq->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$att) return null;
    }
    $days = bt_session_days($db, $s);
    if (!$days) return null;
    $commute = (int)(bt_settings($db)['bt_commute_min'] ?? 30);
    $trip_days = bt_days_with_commute($days, $commute);       // 公出時段＝上課時間前後各加通勤時間
    $first = $trip_days[0];
    $last  = $trip_days[count($trip_days) - 1];
    $trip = null;
    if ($uid) {
        $tq = $db->prepare("SELECT trip_id, trip_no, status FROM business_trip
                            WHERE ref_type='training_session' AND ref_id=? AND user_id=? AND COALESCE(is_deleted,0)=0
                            ORDER BY trip_id LIMIT 1");
        $tq->execute([$sessionId, $uid]);
        $trip = $tq->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $who = $uid ? bt_resolve_user_dept($db, $uid, (string)($att['dept_name'] ?? ''), (string)($att['position_name'] ?? '')) : null;
    return [
        'session_id'  => (int)$sessionId,
        'course_name' => (string)$s['course_name'],
        'dept_id'     => $who['dept_id'] ?? null,             // 兼任者：用外訓當時登記的部門/職稱，不套主要職務
        'dept_name'   => $who['dept_name'] ?? '',
        'position_name' => $who['position_name'] ?? '',
        'org_unit'    => (string)($s['org_unit'] ?? ''),
        'apply_date'  => (string)$first['day_date'],        // ← 單據日期＝外訓最早日期
        'date_from'   => (string)$first['day_date'],
        'date_to'     => (string)$last['day_date'],
        'time_from'   => (string)($first['start_time'] ?? ''),
        'time_to'     => (string)($last['end_time'] ?? ''),
        'location'    => (string)($s['location'] ?? ''),
        'reason'      => bt_training_reason($s),
        'days'        => $trip_days,
        'class_days'  => $days,                                   // 原始上課日期時間（畫面對照用）
        'class_time_from' => (string)($days[0]['start_time'] ?? ''),
        'class_time_to'   => (string)($days[count($days) - 1]['end_time'] ?? ''),
        'commute_min' => $commute,
        'attendee'    => $att,
        'exist_trip'  => $trip,
    ];
}
