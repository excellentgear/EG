<?php
/**
 * 教育訓練管理 —— 共用函式庫
 * 對應 KPI 2-GM-04-01 第19項「人員教育訓練達成率」= 當月實際完成場次 / 當月計畫場次
 *
 * 彈性設計（管理員後端維護各部門/年度訓練計畫）：
 *   - training_session：每列＝一場訓練課程（計畫+執行合一）
 *       year, plan_month  計畫歸屬年月（KPI 依此歸月）
 *       dept_id           指定部門(department.id；NULL=全公司/跨部門)
 *       status            planned=計畫中 / scheduled=已排定(確認開課,可印簽到表) / done=已完成 / cancelled=取消
 *   - KPI 達成率(場次口徑)：den=當月計畫場次(排除取消；已排定仍算分母)；num=當月已完成(done)場次
 *     （取消是否計入分母可由參數 include_cancelled 控制）
 */

/* ============================================================
 * Schema
 * ============================================================ */
function training_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS training_session (
        session_id INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL COMMENT '計畫年度',
        plan_month TINYINT NOT NULL COMMENT '計畫月份 1-12(KPI歸屬)',
        dept_id INT NULL COMMENT '指定部門 department.id；NULL=全公司/跨部門',
        course_name VARCHAR(100) NOT NULL COMMENT '課程/訓練名稱',
        trainer VARCHAR(50) NULL COMMENT '講師/主辦',
        hours DECIMAL(5,1) NULL COMMENT '時數',
        target_headcount INT NULL COMMENT '應到人數',
        actual_headcount INT NULL COMMENT '實到人數',
        status VARCHAR(10) NOT NULL DEFAULT 'planned' COMMENT 'planned/done/cancelled',
        done_date DATE NULL COMMENT '實際完成日',
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        KEY idx_ym (year, plan_month),
        KEY idx_dept (dept_id),
        KEY idx_status (status)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練場次(計畫+執行)'");

    // 實際開課資訊 + 講師人員id（既有 done_date=實際開課日；僅加欄不破壞）
    foreach ([
        "ALTER TABLE training_session ADD COLUMN start_time VARCHAR(5) NULL COMMENT '開始時間 HH:MM'",
        "ALTER TABLE training_session ADD COLUMN end_time VARCHAR(5) NULL COMMENT '結束時間 HH:MM'",
        "ALTER TABLE training_session ADD COLUMN location VARCHAR(100) NULL COMMENT '上課地點'",
        "ALTER TABLE training_session ADD COLUMN trainer_id INT NULL COMMENT '講師 user.id(外部講師留空,用trainer文字)'",
        "ALTER TABLE training_session ADD COLUMN train_type VARCHAR(10) NOT NULL DEFAULT 'internal' COMMENT 'internal=內訓 external=外訓'",
        "ALTER TABLE training_session ADD COLUMN org_unit VARCHAR(100) NULL COMMENT '外訓開課/主辦單位'",
        "ALTER TABLE training_session ADD COLUMN actual_hours DECIMAL(5,1) NULL COMMENT '實際上課時數(可與計畫 hours 不同；多天=各天合計)'",
        "ALTER TABLE training_session ADD COLUMN plan_days INT NULL COMMENT '計畫上課天數(多天課程；NULL/1=單天)'",
        "ALTER TABLE training_session ADD COLUMN shift_type_id INT NULL COMMENT '套用的固定班別 shift_type.shift_type_id(上下班時間僅參考、休息分鐘用來扣時數)'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // 多天課程：每天一列上課日期與時段（單天課程也存 1 列）
    // 主表 done_date/start_time/end_time 保留＝第 1 天的值（KPI、清單、既有程式相容）
    $db->exec("CREATE TABLE IF NOT EXISTS training_session_day (
        day_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        day_no INT NOT NULL DEFAULT 1 COMMENT '第幾天(1起)',
        day_date DATE NOT NULL COMMENT '上課日期',
        start_time VARCHAR(5) NULL COMMENT '開始 HH:MM',
        end_time VARCHAR(5) NULL COMMENT '結束 HH:MM',
        hours DECIMAL(5,1) NULL COMMENT '當日時數(可手改,扣休息)',
        KEY idx_session (session_id),
        KEY idx_date (day_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練場次上課日期(多天課程)'");

    foreach ([
        "ALTER TABLE training_session_day ADD COLUMN break_minutes INT NOT NULL DEFAULT 0 COMMENT '當日休息分鐘(自班別帶入,可手改;時數已扣除)'",
        "ALTER TABLE training_session_day ADD COLUMN evenement_id INT NULL COMMENT '同步到行事曆的 evenement.id(一天一筆)'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // 上課地點主檔（可設定/儲存後選擇；停用不刪，舊紀錄仍存文字）
    $db->exec("CREATE TABLE IF NOT EXISTS training_location (
        loc_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        UNIQUE KEY uq_name (name)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練上課地點主檔'");

    // 參加人員名單（應參加＋實到＋簽名）
    $db->exec("CREATE TABLE IF NOT EXISTS training_attendee (
        att_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(50) NULL,
        dept_name VARCHAR(50) NULL,
        attended TINYINT(1) NOT NULL DEFAULT 0 COMMENT '實到',
        signed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '已簽名',
        signed_at DATETIME NULL,
        sign_method VARCHAR(10) NULL COMMENT 'online=線上密碼 paper=紙本掃描',
        note VARCHAR(100) NULL,
        UNIQUE KEY uq_sa (session_id, user_id),
        KEY idx_session (session_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='教育訓練參加人員名單'");

    try { $db->exec("ALTER TABLE training_attendee ADD COLUMN position_name VARCHAR(50) NULL COMMENT '職稱(冗餘保存,列印簽到表用)'"); }
    catch (Throwable $e) {}

    foreach ([['training_view','訓練檢閱'],['training_edit','訓練登錄'],['training_admin','訓練管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='training' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'training')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ============================================================
 * 模組設定（system_settings）：預設班別、行事曆類別綁定
 *   一律存「id」不存名稱——類別/班別日後改名，綁定仍然有效（使用者明確要求）。
 * ============================================================ */
const TRAINING_SETTING_KEYS = ['training_default_shift_id', 'training_cat_internal', 'training_cat_external'];
/* 休息時段（HH:MM 字串，不是 id）：上課時間與此時段重疊幾分鐘就扣幾分鐘。
   兩欄都留空＝完全不扣休息。預設 12:00~13:00（＝日班的午休）。 */
const TRAINING_SETTING_STR_KEYS = ['training_break_start', 'training_break_end'];
const TRAINING_BREAK_DEFAULT = ['training_break_start'=>'12:00', 'training_break_end'=>'13:00'];

function training_settings(PDO $db): array {
    $out = ['training_default_shift_id'=>null, 'training_cat_internal'=>null, 'training_cat_external'=>null];
    $out += TRAINING_BREAK_DEFAULT;      // 沒設定過才用預設；設定成空字串＝管理員刻意關閉，不可再被預設蓋回去
    try {
        $keys = array_merge(TRAINING_SETTING_KEYS, TRAINING_SETTING_STR_KEYS);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute($keys);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (in_array($r['setting_key'], TRAINING_SETTING_STR_KEYS, true)) {
                $out[$r['setting_key']] = (string)($r['setting_value'] ?? '');
            } else {
                $out[$r['setting_key']] = ($r['setting_value'] === '' || $r['setting_value'] === null) ? null : (int)$r['setting_value'];
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

function training_setting_save(PDO $db, string $key, $val, int $uid, string $uname): void {
    if (!in_array($key, TRAINING_SETTING_KEYS, true) && !in_array($key, TRAINING_SETTING_STR_KEYS, true)) return;
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                  VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                      updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)")
       ->execute([$key, $val === null ? '' : (string)$val, $uid, $uname]);
}

/** HH:MM → 分鐘；不合法回 null */
function training_min(?string $t): ?int {
    $t = trim((string)$t);
    if ($t === '' || !preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $t, $m)) return null;
    $h = (int)$m[1]; $i = (int)$m[2];
    if ($h > 23 || $i > 59) return null;
    return $h * 60 + $i;
}

/**
 * 當日應扣的休息分鐘 ＝「上課時間」與「休息時段」的重疊分鐘數。
 * 使用者明確要求：休息時間不給手動改，由系統依實際上課時間自動算——
 * 例如 11:00~12:00 的課根本沒跨到午休，就不該被扣掉 60 分鐘（舊版會扣，導致時數算錯甚至變負數）。
 */
function training_break_minutes(PDO $db, ?string $start, ?string $end, ?array $set = null): int {
    $set = $set ?? training_settings($db);
    $bs = training_min($set['training_break_start'] ?? '');
    $be = training_min($set['training_break_end'] ?? '');
    $s  = training_min($start);
    $e  = training_min($end);
    if ($bs === null || $be === null || $be <= $bs) return 0;   // 未設定休息時段＝不扣
    if ($s === null || $e === null || $e <= $s) return 0;
    $ov = min($e, $be) - max($s, $bs);
    return $ov > 0 ? $ov : 0;
}

/** 行事曆類別 id：優先用設定綁定的 id；沒設定才以名稱回退（找不到回 null＝不寫行事曆） */
function training_category_id(PDO $db, string $trainType): ?int {
    $set = training_settings($db);
    $key = $trainType === 'external' ? 'training_cat_external' : 'training_cat_internal';
    if ($set[$key]) {
        $st = $db->prepare("SELECT id FROM event_category WHERE id=?");
        $st->execute([$set[$key]]);
        $v = $st->fetchColumn();
        if ($v) return (int)$v;
    }
    $fallback = $trainType === 'external' ? '課程(外訓)' : '課程(內訓)';
    try {
        $st = $db->prepare("SELECT id FROM event_category WHERE category_name=? LIMIT 1");
        $st->execute([$fallback]);
        $v = $st->fetchColumn();
        return $v ? (int)$v : null;
    } catch (Throwable $e) { return null; }
}

/** 固定班別（與輪值排班共用 shift_type：上下班時間僅供帶入參考，break_minutes 用於扣時數） */
function training_shifts(PDO $db): array {
    try {
        return $db->query("SELECT shift_type_id, shift_name, shift_code,
                                  LEFT(start_time,5) AS start_time, LEFT(end_time,5) AS end_time,
                                  break_minutes, is_overnight
                           FROM shift_type WHERE is_active=1 ORDER BY sort_order, shift_type_id")
                  ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================================================
 * 行事曆同步（evenement）：一個上課日一筆事件
 *   寫法比照 src/store/_events_setting.php 與 leave_lib.php 的行事曆段落。
 *   同步時機：確認實行/登錄完成(save_execution) → 建立或更新；
 *             退回計畫中/取消/刪除場次 → 移除。失敗一律不擋主流程。
 * ============================================================ */
function training_event_set_targets(PDO $db, int $evId): void {
    try {
        $db->prepare("DELETE FROM evenement_target WHERE event_id=?")->execute([$evId]);
        $db->prepare("DELETE FROM evenement_recipient_cache WHERE event_id=?")->execute([$evId]);
        $db->prepare("INSERT INTO evenement_target (event_id, target_type, created_at) VALUES (?, 'all', NOW())")
           ->execute([$evId]);   // 教育訓練屬公司事務 → 全體可見
        $ids = $db->query("SELECT id FROM user WHERE state NOT IN (0, 90) AND state IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $cache = $db->prepare("INSERT INTO evenement_recipient_cache (event_id, user_id, created_at) VALUES (?, ?, NOW())");
        foreach ($ids as $u) $cache->execute([$evId, (int)$u]);
    } catch (Throwable $e) {}
}

/** 移除某場次已同步的行事曆事件（只刪 training_session_day.evenement_id 指到的那些，不條件反查） */
function training_event_remove(PDO $db, int $sid): void {
    try {
        $st = $db->prepare("SELECT day_id, evenement_id FROM training_session_day WHERE session_id=? AND evenement_id IS NOT NULL");
        $st->execute([$sid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ev = (int)$r['evenement_id'];
            $db->prepare("DELETE FROM evenement_recipient_cache WHERE event_id=?")->execute([$ev]);
            $db->prepare("DELETE FROM evenement_target WHERE event_id=?")->execute([$ev]);
            $db->prepare("DELETE FROM evenement_actor WHERE event_id=?")->execute([$ev]);
            $db->prepare("DELETE FROM evenement WHERE id=?")->execute([$ev]);
            $db->prepare("UPDATE training_session_day SET evenement_id=NULL WHERE day_id=?")->execute([$r['day_id']]);
        }
    } catch (Throwable $e) {}
}

/**
 * 同步某場次到行事曆：每個上課日一筆 evenement（先移除舊的再重建，避免改期後殘留）。
 * 回傳實際寫入的事件數；沒有綁定類別（且名稱也找不到）時回 0 並不寫入。
 */
function training_event_sync(PDO $db, int $sid): int {
    try {
        $st = $db->prepare("SELECT s.*, d.name AS dept_name FROM training_session s
                            LEFT JOIN department d ON d.id=s.dept_id WHERE s.session_id=?");
        $st->execute([$sid]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return 0;
        training_event_remove($db, $sid);
        if (!in_array($s['status'], ['scheduled', 'done'], true)) return 0;   // 只有確定開課後才進行事曆
        $catId = training_category_id($db, (string)$s['train_type']);
        if (!$catId) return 0;
        $dq = $db->prepare("SELECT day_id, day_no, day_date, start_time, end_time, hours FROM training_session_day
                            WHERE session_id=? ORDER BY day_no, day_date");
        $dq->execute([$sid]);
        $days = $dq->fetchAll(PDO::FETCH_ASSOC);
        $ext = $s['train_type'] === 'external';
        $n = count($days); $done = 0;
        foreach ($days as $d) {
            $title = '教育訓練：' . $s['course_name'] . ($n > 1 ? '（第' . $d['day_no'] . '/' . $n . '天）' : '');
            $startT = $d['start_time'] ?: '00:00';
            $endT   = $d['end_time'] ?: '23:59';
            $allday = ($d['start_time'] && $d['end_time']) ? 0 : 1;
            $remark = ($ext ? '外訓' : '內訓')
                . '／' . ($ext ? ('開課單位：' . ($s['org_unit'] ?: '—')) : ('講師：' . ($s['trainer'] ?: '—')))
                . '　對象：' . ($s['dept_id'] === null ? '全公司' : ($s['dept_name'] ?: ''))
                . ($s['location'] ? '　地點：' . $s['location'] : '')
                . ($d['hours'] !== null ? '　時數：' . rtrim(rtrim(number_format((float)$d['hours'], 1), '0'), '.') : '')
                . '（教育訓練管理 #' . $sid . '）';
            $db->prepare("INSERT INTO evenement (title, category_id, start, end, allday, remark) VALUES (?,?,?,?,?,?)")
               ->execute([$title, $catId, $d['day_date'] . ' ' . $startT . ':00', $d['day_date'] . ' ' . $endT . ':00', $allday, $remark]);
            $evId = (int)$db->lastInsertId();
            if (!$ext && $s['trainer_id']) {
                try { $db->prepare("INSERT INTO evenement_actor (event_id, user_id, created_at) VALUES (?,?,NOW())")
                         ->execute([$evId, (int)$s['trainer_id']]); } catch (Throwable $e) {}
            }
            training_event_set_targets($db, $evId);
            $db->prepare("UPDATE training_session_day SET evenement_id=? WHERE day_id=?")->execute([$evId, $d['day_id']]);
            $done++;
        }
        return $done;
    } catch (Throwable $e) { return 0; }
}

/* ============================================================
 * 使用者 / 權限（roles module='training'；admin⊃edit⊃view）
 * ============================================================ */
function training_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function training_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='training' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='training' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function training_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || training_has_role($db, $uid, ['training_admin']);
    $canEdit  = $canAdmin || training_has_role($db, $uid, ['training_edit']);
    $canView  = $canEdit  || training_has_role($db, $uid, ['training_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/* ============================================================
 * KPI 第19項計算：人員教育訓練達成率（供 kpi_as_lib compute 呼叫）
 * den=當月計畫場次(排除取消，除非 include_cancelled=1)；num=已完成場次
 * ============================================================ */
function training_kpi_compute(PDO $db, int $year, int $month, array $params): ?array {
    try {
        if (!$db->query("SHOW TABLES LIKE 'training_session'")->fetchColumn())
            return ['num'=>0, 'den'=>0, 'value'=>null];
    } catch (Throwable $e) { return ['num'=>0, 'den'=>0, 'value'=>null]; }

    // include_cancelled 兼容 {v,fe} 與扁平
    $inc = $params['include_cancelled'] ?? 0;
    if (is_array($inc)) $inc = $inc['v'] ?? 0;
    $inc = (int)$inc === 1;

    $denSql = "SELECT COUNT(*) FROM training_session WHERE year=? AND plan_month=?"
            . ($inc ? "" : " AND status<>'cancelled'");
    $st = $db->prepare($denSql);
    $st->execute([$year, $month]);
    $den = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM training_session WHERE year=? AND plan_month=? AND status='done'");
    $st->execute([$year, $month]);
    $num = (int)$st->fetchColumn();

    return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
}
