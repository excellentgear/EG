<?php
/**
 * 教育訓練管理 —— 共用函式庫
 * 對應 KPI 2-GM-04-01 第19項「人員教育訓練達成率」= 當月實際完成場次 / 當月計畫場次
 *
 * 彈性設計（管理員後端維護各部門/年度訓練計畫）：
 *   - training_session：每列＝一場訓練課程（計畫+執行合一）
 *       year, plan_month  計畫歸屬年月（KPI 依此歸月）
 *       dept_id           指定部門(department.id；NULL=全公司/跨部門)
 *       status            planned=計畫中 / done=已完成 / cancelled=取消
 *   - KPI 達成率(場次口徑)：den=當月計畫場次(排除取消)；num=當月已完成場次
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
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

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
