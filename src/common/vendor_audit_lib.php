<?php
/**
 * 供應商稽核管理 —— 共用函式庫（稽核批次模型）
 * 對應 KPI 2-GM-04-01 第6項「廠商稽核按時執行率」= 該期實際稽核家數 / 該期應稽核家數
 * 頻率半年（6/12月結算：6月=上半年、12月=下半年）
 *
 * 模型（每期挑一批對象）：
 *   - 每期 = (年, 上/下半年) 一列 vendor_audit_round
 *   - 該期稽核對象 = vendor_audit_target（可手動多選/隨機抽取加入；audit_date=NULL 未稽核）
 *   - 廠商是否需稽核 = maker_list.audit_managed（有些廠商不需稽核=0）
 *   - 大類=maker_list.main_category_id、加工項目(小類)=maker_sub_category_mapping（比照 master_data 廠商分頁）
 *   - master_data 設「停用」(maker_list.status='停用')者：頁面灰底、不可加入、隨機排除、不列入 KPI
 *   - 稽核週期(月)=全域共用設定(system_settings vendor_audit_cycle_months,預設6)，僅作參考/提醒
 *
 * KPI：den=該期對象數(排除停用)；num=其中已稽核(audit_date 非空)者。
 */

// maker_list.status='X' 代表停用（比照 master_data 廠商分頁：讀取一律 status<>'X'）
const VENDOR_AUDIT_DISABLED = 'X';

/* ============================================================
 * Schema
 * ============================================================ */
function vendor_audit_ensure_schema(PDO $db): void {
    // maker_list：納入稽核管理旗標（cycle/next_due 舊欄位保留但改由批次模型，不再使用）
    foreach ([
        "ALTER TABLE maker_list ADD COLUMN audit_managed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '納入稽核管理(需被稽核)'",
        "ALTER TABLE maker_list ADD COLUMN audit_cycle_months INT NULL COMMENT '(保留,改用全域週期)'",
        "ALTER TABLE maker_list ADD COLUMN audit_next_due DATE NULL COMMENT '(保留,改用批次模型)'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit_round (
        round_id INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        half TINYINT NOT NULL COMMENT '1=上半年 2=下半年',
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        UNIQUE KEY uq_period (year, half)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核期(每半年一期)'");

    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit_target (
        target_id INT AUTO_INCREMENT PRIMARY KEY,
        round_id INT NOT NULL,
        maker_id_no VARCHAR(11) NOT NULL,
        audit_date DATE NULL COMMENT '實際稽核日(NULL=未稽核)',
        result VARCHAR(12) NULL COMMENT 'pass=合格 conditional=限期改善 fail=不合格',
        score INT NULL,
        auditor VARCHAR(50) NULL,
        report_no VARCHAR(50) NULL,
        note VARCHAR(200) NULL,
        added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        added_by INT NULL,
        added_by_name VARCHAR(50) NULL,
        UNIQUE KEY uq_rt (round_id, maker_id_no),
        KEY idx_round (round_id),
        KEY idx_maker (maker_id_no)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核期對象+執行紀錄'");

    foreach ([['vendor_audit_view','稽核檢閱'],['vendor_audit_edit','稽核登錄'],['vendor_audit_admin','稽核管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='vendor_audit' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'vendor_audit')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ============================================================
 * 使用者 / 權限（roles module='vendor_audit'；admin⊃edit⊃view）
 * ============================================================ */
function vendor_audit_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function vendor_audit_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='vendor_audit' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='vendor_audit' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function vendor_audit_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || vendor_audit_has_role($db, $uid, ['vendor_audit_admin']);
    $canEdit  = $canAdmin || vendor_audit_has_role($db, $uid, ['vendor_audit_edit']);
    $canView  = $canEdit  || vendor_audit_has_role($db, $uid, ['vendor_audit_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/* ============================================================
 * 全域設定（system_settings）
 * ============================================================ */
function vendor_audit_cycle_months(PDO $db): int {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='vendor_audit_cycle_months' LIMIT 1");
        $st->execute();
        $v = (int)$st->fetchColumn();
        return $v > 0 ? $v : 6;
    } catch (Throwable $e) { return 6; }
}
function vendor_audit_set_cycle(PDO $db, int $months): void {
    $months = $months > 0 ? $months : 6;
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('vendor_audit_cycle_months', ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $st->execute([(string)$months]);
}

/* ============================================================
 * 期別輔助
 * ============================================================ */
function vendor_audit_round_id(PDO $db, int $year, int $half, bool $create = false, ?array $u = null): ?int {
    $st = $db->prepare("SELECT round_id FROM vendor_audit_round WHERE year=? AND half=? LIMIT 1");
    $st->execute([$year, $half]);
    $rid = $st->fetchColumn();
    if ($rid !== false) return (int)$rid;
    if (!$create) return null;
    $ins = $db->prepare("INSERT INTO vendor_audit_round (year, half, created_by, created_by_name) VALUES (?,?,?,?)");
    $ins->execute([$year, $half, $u ? (int)$u['id'] : null, $u ? (string)$u['user_cname'] : null]);
    return (int)$db->lastInsertId();
}

/* ============================================================
 * KPI 第6項計算：廠商稽核按時執行率（半年批次，供 kpi_as_lib compute 呼叫）
 * month≤6→上半年、否則下半年；den=該期對象(排除停用)，num=已稽核
 * ============================================================ */
function vendor_audit_kpi_compute(PDO $db, int $year, int $month, array $params): ?array {
    try {
        if (!$db->query("SHOW TABLES LIKE 'vendor_audit_target'")->fetchColumn())
            return ['num'=>0, 'den'=>0, 'value'=>null];
    } catch (Throwable $e) { return ['num'=>0, 'den'=>0, 'value'=>null]; }

    $half = $month <= 6 ? 1 : 2;
    $rid = vendor_audit_round_id($db, $year, $half, false);
    if ($rid === null) return ['num'=>0, 'den'=>0, 'value'=>null];

    $st = $db->prepare("SELECT COUNT(*) den, SUM(t.audit_date IS NOT NULL) num
                        FROM vendor_audit_target t
                        JOIN maker_list mk ON mk.maker_id_no=t.maker_id_no
                        WHERE t.round_id=? AND (mk.status IS NULL OR mk.status<>?)");
    $st->execute([$rid, VENDOR_AUDIT_DISABLED]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $den = (int)($r['den'] ?? 0);
    $num = (int)($r['num'] ?? 0);
    return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
}
