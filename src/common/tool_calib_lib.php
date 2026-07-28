<?php
/**
 * 量測儀器校驗管理 —— 共用函式庫
 * 對應 KPI 2-GM-04-01 第18項「量測儀器按時校驗率」= 當月準時完成校驗數 / 當月應校驗數
 *
 * 資料模型（排程+紀錄合一，可追溯、可由 KPI 決定性重算）：
 *   - 儀器主檔沿用既有 qc_tool（量具），僅「加欄」不破壞既有：
 *       calib_cycle_months  校驗週期(月)
 *       calib_managed       是否納入校驗管理(KPI計算)
 *       calib_method        內校/外校（預設值，可於每次登錄覆寫）
 *       calibration_due     下次應校驗日（既有欄位，當作「目前待完成的到期日」）
 *   - qc_tool_calibration：每列＝某支量具「一次校驗週期」的完成紀錄
 *       due_date  該次應校驗到期日（排程當下的 calibration_due，準時判定基準）
 *       calib_date 實際完成日；next_due 完成後推算的下次到期(= calib_date + 週期)
 *
 * 到期判定：主檔週期自動推算——登錄完成時把 calibration_due 前滾為 next_due。
 * KPI 準時率：den = 當月到期(已完成紀錄的 due_date + 尚待完成的 calibration_due)；
 *            num = 其中 calib_date ≤ due_date(+寬限天數) 者。
 */

/* ============================================================
 * Schema（CREATE TABLE IF NOT EXISTS + qc_tool 加欄 + roles seed）
 * ============================================================ */
function tool_calib_ensure_schema(PDO $db): void {
    // 儀器主檔加欄（既有 qc_tool，僅新增可空欄，不動既有資料）
    foreach ([
        "ALTER TABLE qc_tool ADD COLUMN calib_cycle_months INT NULL COMMENT '校驗週期(月)'",
        "ALTER TABLE qc_tool ADD COLUMN calib_managed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '納入校驗管理(KPI計算)'",
        "ALTER TABLE qc_tool ADD COLUMN calib_method VARCHAR(10) NULL COMMENT '校驗方式 內校/外校'",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* 欄位已存在 */ }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS qc_tool_calibration (
        calib_id INT AUTO_INCREMENT PRIMARY KEY,
        Tool_id INT NOT NULL COMMENT '對應 qc_tool.Tool_id',
        due_date DATE NULL COMMENT '本次應校驗到期日(準時判定基準)',
        calib_date DATE NOT NULL COMMENT '實際校驗完成日',
        result VARCHAR(12) NOT NULL DEFAULT 'pass' COMMENT 'pass=合格 fail=不合格 pass_adjust=校正後合格',
        method VARCHAR(10) NULL COMMENT '內校/外校',
        operator VARCHAR(50) NULL COMMENT '校驗人員/單位',
        cert_no VARCHAR(50) NULL COMMENT '校驗憑證/報告編號',
        next_due DATE NULL COMMENT '完成後推算的下次到期(= calib_date + 週期)',
        note VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        KEY idx_tool (Tool_id),
        KEY idx_due (due_date),
        KEY idx_calib (calib_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='量測儀器校驗紀錄(排程+完成合一)'");

    // 角色 seed（module='tool_calib'，供 user_permissions.php 指派）
    foreach ([['tool_calib_view','校驗唯讀'],['tool_calib_edit','校驗登錄'],['tool_calib_admin','校驗管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='tool_calib' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'tool_calib')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ============================================================
 * 使用者 / 權限（roles module='tool_calib'；admin⊃edit⊃view，fail-closed）
 * ============================================================ */
function tool_calib_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function tool_calib_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='tool_calib' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='tool_calib' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

/** 回傳 ['isAdmin','canAdmin','canEdit','canView'] 能力階層 */
function tool_calib_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || tool_calib_has_role($db, $uid, ['tool_calib_admin']);
    $canEdit  = $canAdmin || tool_calib_has_role($db, $uid, ['tool_calib_edit']);
    $canView  = $canEdit  || tool_calib_has_role($db, $uid, ['tool_calib_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/* ============================================================
 * 工具函式
 * ============================================================ */
/** 安全加月份（避免 1/31 +1月 溢位到 3 月，超出當月時 clamp 到月底） */
function tool_calib_add_months(string $date, int $months): ?string {
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

/** 儀器目前狀態（給前端上色/篩選）：warn_days 內視為即將到期 */
function tool_calib_status(array $tool, int $warnDays = 30): string {
    if ((int)$tool['calib_managed'] !== 1) return 'unmanaged';
    $due = $tool['calibration_due'] ?? null;
    if (!$due) return 'nobaseline';
    $today = strtotime(date('Y-m-d'));
    $dt = strtotime($due);
    if ($dt < $today) return 'overdue';
    if ($dt <= $today + $warnDays * 86400) return 'soon';
    return 'ok';
}

/* ============================================================
 * KPI 第18項計算：量測儀器按時校驗率（供 kpi_as_lib compute 呼叫）
 * 回傳 ['num'=>準時完成數, 'den'=>應校驗數, 'value'=>百分比或 null]
 * ============================================================ */
function tool_calib_kpi_compute(PDO $db, int $year, int $month, array $params): ?array {
    // 表尚未建立時（首次尚未進過校驗頁）→ 無資料
    try {
        $chk = $db->query("SHOW TABLES LIKE 'qc_tool_calibration'")->fetchColumn();
        if (!$chk) return ['num'=>0, 'den'=>0, 'value'=>null];
    } catch (Throwable $e) { return ['num'=>0, 'den'=>0, 'value'=>null]; }

    // grace_days 兼容兩種格式：矩陣路徑 {grace_days:{v,fe}}、試算路徑 {grace_days:number}
    $g = $params['grace_days'] ?? 0;
    if (is_array($g)) $g = $g['v'] ?? 0;
    $grace = is_numeric($g) ? max(0, (int)$g) : 0;

    $ms = sprintf('%04d-%02d-01', $year, $month);
    $me = date('Y-m-t', strtotime($ms));

    // (1) 已完成紀錄：本次到期日落在當月者 → 計入 den；準時(calib_date ≤ due+寬限)者計入 num
    $st = $db->prepare("SELECT c.due_date, c.calib_date
                        FROM qc_tool_calibration c
                        JOIN qc_tool t ON t.Tool_id=c.Tool_id
                        WHERE t.calib_managed=1 AND c.due_date IS NOT NULL
                          AND c.due_date BETWEEN ? AND ?");
    $st->execute([$ms, $me]);
    $den = 0; $num = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $den++;
        $limit = $grace > 0 ? date('Y-m-d', strtotime($r['due_date'] . " +$grace days")) : $r['due_date'];
        if (!empty($r['calib_date']) && substr($r['calib_date'], 0, 10) <= $limit) $num++;
    }

    // (2) 尚待完成的到期（主檔 calibration_due 落在當月且尚無對應完成紀錄）→ 計入 den，未準時
    $st = $db->prepare("SELECT COUNT(*) FROM qc_tool t
                        WHERE t.calib_managed=1 AND t.calibration_due IS NOT NULL
                          AND t.calibration_due BETWEEN ? AND ?
                          AND NOT EXISTS (SELECT 1 FROM qc_tool_calibration c
                                          WHERE c.Tool_id=t.Tool_id AND c.due_date=t.calibration_due)");
    $st->execute([$ms, $me]);
    $den += (int)$st->fetchColumn();

    return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
}
