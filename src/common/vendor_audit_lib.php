<?php
/**
 * 供應商稽核管理 —— 共用函式庫
 * 對應 KPI 2-GM-04-01 第6項「廠商稽核按時執行率」= 該半年實際稽核廠商數 / 應稽核廠商數
 * 頻率半年（6/12月結算：6月=上半年、12月=下半年）
 *
 * 資料模型（排程+紀錄合一，主檔週期自動推算，比照量測儀器校驗）：
 *   - 廠商主檔沿用 maker_list，僅加欄：
 *       audit_cycle_months  稽核週期(月)
 *       audit_managed       納入稽核管理(KPI計算)
 *       audit_next_due      下次應稽核日
 *   - vendor_audit：每列＝某廠商一次稽核週期的完成紀錄
 *       due_date 該次應稽核到期日(按時判定基準)；audit_date 實際稽核日；
 *       next_due 完成後推算的下次到期(= audit_date + 週期)
 */

/* ============================================================
 * Schema
 * ============================================================ */
function vendor_audit_ensure_schema(PDO $db): void {
    foreach ([
        "ALTER TABLE maker_list ADD COLUMN audit_cycle_months INT NULL COMMENT '稽核週期(月)'",
        "ALTER TABLE maker_list ADD COLUMN audit_managed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '納入稽核管理(KPI計算)'",
        "ALTER TABLE maker_list ADD COLUMN audit_next_due DATE NULL COMMENT '下次應稽核日'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS vendor_audit (
        audit_id INT AUTO_INCREMENT PRIMARY KEY,
        maker_id_no VARCHAR(11) NOT NULL COMMENT '對應 maker_list.maker_id_no',
        due_date DATE NULL COMMENT '本次應稽核到期日(按時判定基準)',
        audit_date DATE NOT NULL COMMENT '實際稽核日',
        result VARCHAR(12) NOT NULL DEFAULT 'pass' COMMENT 'pass=合格 conditional=限期改善 fail=不合格',
        score INT NULL COMMENT '稽核分數',
        auditor VARCHAR(50) NULL COMMENT '稽核人員',
        report_no VARCHAR(50) NULL COMMENT '稽核報告編號',
        next_due DATE NULL COMMENT '完成後推算的下次到期(= audit_date + 週期)',
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        KEY idx_maker (maker_id_no),
        KEY idx_due (due_date),
        KEY idx_audit (audit_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='供應商稽核紀錄(排程+完成合一)'");

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
 * 工具
 * ============================================================ */
function vendor_audit_add_months(string $date, int $months): ?string {
    $t = strtotime(substr($date, 0, 10));
    if ($t === false || $months <= 0) return null;
    $y = (int)date('Y', $t); $m = (int)date('n', $t); $d = (int)date('j', $t);
    $m += $months;
    $y += intdiv($m - 1, 12);
    $m = (($m - 1) % 12) + 1;
    $last = (int)date('t', mktime(0,0,0,$m,1,$y));
    if ($d > $last) $d = $last;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function vendor_audit_status(array $v, int $warnDays = 60): string {
    if ((int)$v['audit_managed'] !== 1) return 'unmanaged';
    $due = $v['audit_next_due'] ?? null;
    if (!$due) return 'nobaseline';
    $today = strtotime(date('Y-m-d'));
    $dt = strtotime($due);
    if ($dt < $today) return 'overdue';
    if ($dt <= $today + $warnDays * 86400) return 'soon';
    return 'ok';
}

/* ============================================================
 * KPI 第6項計算：廠商稽核按時執行率（半年結算，供 kpi_as_lib compute 呼叫）
 * month≤6 → 上半年窗 [Y-01-01,Y-06-30]；否則下半年窗 [Y-07-01,Y-12-31]
 * ============================================================ */
function vendor_audit_kpi_compute(PDO $db, int $year, int $month, array $params): ?array {
    try {
        if (!$db->query("SHOW TABLES LIKE 'vendor_audit'")->fetchColumn())
            return ['num'=>0, 'den'=>0, 'value'=>null];
    } catch (Throwable $e) { return ['num'=>0, 'den'=>0, 'value'=>null]; }

    $g = $params['grace_days'] ?? 0;
    if (is_array($g)) $g = $g['v'] ?? 0;
    $grace = is_numeric($g) ? max(0, (int)$g) : 0;

    if ($month <= 6) { $ws = sprintf('%04d-01-01', $year); $we = sprintf('%04d-06-30', $year); }
    else             { $ws = sprintf('%04d-07-01', $year); $we = sprintf('%04d-12-31', $year); }

    // (1) 已完成紀錄：到期日落在半年窗者
    $st = $db->prepare("SELECT a.due_date, a.audit_date
                        FROM vendor_audit a
                        JOIN maker_list mk ON mk.maker_id_no=a.maker_id_no
                        WHERE mk.audit_managed=1 AND a.due_date IS NOT NULL
                          AND a.due_date BETWEEN ? AND ?");
    $st->execute([$ws, $we]);
    $den = 0; $num = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $den++;
        $limit = $grace > 0 ? date('Y-m-d', strtotime($r['due_date'] . " +$grace days")) : $r['due_date'];
        if (!empty($r['audit_date']) && substr($r['audit_date'], 0, 10) <= $limit) $num++;
    }

    // (2) 尚待完成的到期（主檔 audit_next_due 落在半年窗且尚無對應完成紀錄）
    $st = $db->prepare("SELECT COUNT(*) FROM maker_list mk
                        WHERE mk.audit_managed=1 AND mk.audit_next_due IS NOT NULL
                          AND mk.audit_next_due BETWEEN ? AND ?
                          AND NOT EXISTS (SELECT 1 FROM vendor_audit a
                                          WHERE a.maker_id_no=mk.maker_id_no AND a.due_date=mk.audit_next_due)");
    $st->execute([$ws, $we]);
    $den += (int)$st->fetchColumn();

    return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
}
