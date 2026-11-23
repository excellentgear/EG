<?php
/**
 * 簽呈（2-MM-01-15）共用庫
 * ------------------------------------------------------------------
 * 與公告/通知（createEvent.php）完全分開：簽呈是「一張單、逐關批示」的電子簽核表單，
 * 公告是「發給一群人看」。兩者只共用底層通知表 live_event（站內通知＋Web Push）。
 *
 * 流程（比照紙本 2-MM-01-15 欄位順序）：
 *   提簽人送出 → 部門主管批示 → 總經理批示 → 會辦單位批示（最多 5 個，可同時進行）→ 結案
 *   設定 memo_cosign_before_gm=1 時改成：部門主管 → 會辦單位 → 總經理。
 *   每一關都可「核准／退回」並寫批示意見；退回必須填原因，退回後提簽人可修改重送（新一輪）。
 *   簽核人解析：部門主管走 ai-rules/24（eg_unit_supervisor，到課為止）、總經理走組織角色綁定 top_approver、
 *   會辦單位＝提簽人指定的人，沒指定人就取該部門主管；一律再過 delegate_lib 的 eg_resolve_signer()（ai-rules/11）。
 *   提簽人本人就是該關簽核人（例如課級最高主管、最高決策者）→ 該關自動略過並註記，不會自己簽自己。
 * 角色（module='memo_request'）：全體在職員工不需角色即可開自己的單；
 *   memo_view_all＝檢閱全部（唯讀，不含機密／極機密）；memo_admin＝管理員（看全部含機密、代開、改編號、刪除、設定）。
 * 機密等級：「機密／極機密」只有提簽人、簽核關卡的人、管理員看得到，檢閱角色也看不到。
 */

require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/unit_supervisor_lib.php';

const MEMO_ASDOC_MODULE = 'memo_request';
const MEMO_SETTING_KEYS = ['memo_need_gm', 'memo_cosign_before_gm', 'memo_stamp_tpl_id', 'memo_view_hr_levels', 'memo_view_sup_levels'];
const MEMO_COSIGN_MAX   = 5;
const MEMO_SECRECY      = ['normal' => '一般', 'secret' => '機密', 'top_secret' => '極機密'];
/** 時限：天數（紙本注意事項：普通三天內、速件兩天內、最速件當天完成） */
const MEMO_URGENCY      = ['normal' => ['普通', 3], 'urgent' => ['速件', 2], 'rush' => ['最速件', 0]];

function memo_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS memo_request (
            memo_id       INT AUTO_INCREMENT PRIMARY KEY,
            memo_no       VARCHAR(30) NULL COMMENT '編號（紙本由管理課填寫，這裡自動產生、管理員可改）',
            apply_date    DATE NOT NULL COMMENT '簽呈日期＝業務日期',
            user_id       INT NOT NULL COMMENT '提簽人',
            user_name     VARCHAR(60) NULL,
            dept_id       INT NULL,
            dept_name     VARCHAR(100) NULL COMMENT '所屬部門（快照，可改）',
            position_name VARCHAR(100) NULL,
            secrecy       VARCHAR(12) NOT NULL DEFAULT 'normal' COMMENT 'normal/secret/top_secret',
            urgency       VARCHAR(8)  NOT NULL DEFAULT 'normal' COMMENT 'normal/urgent/rush',
            subject       VARCHAR(300) NOT NULL DEFAULT '' COMMENT '主旨',
            content       MEDIUMTEXT NULL COMMENT '說明',
            cosign_json   TEXT NULL COMMENT '會辦單位草稿 [{dept_id,user_id}]，送出時展開成 memo_step',
            status        VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/approved/rejected',
            round_no      INT NOT NULL DEFAULT 1 COMMENT '第幾輪送簽（退回重送加 1）',
            submit_date   DATE NULL,
            submitted_at  DATETIME NULL,
            due_date      DATE NULL COMMENT '依時限推算的應完成日',
            closed_date   DATE NULL,
            closed_at     DATETIME NULL,
            reject_note   VARCHAR(500) NULL,
            created_by    INT NULL,
            created_at    DATETIME NULL,
            updated_at    DATETIME NULL,
            is_deleted    TINYINT NOT NULL DEFAULT 0,
            KEY idx_user (user_id, apply_date),
            KEY idx_status (status),
            KEY idx_no (memo_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='簽呈 2-MM-01-15'");
        $db->exec("CREATE TABLE IF NOT EXISTS memo_step (
            step_id        INT AUTO_INCREMENT PRIMARY KEY,
            memo_id        INT NOT NULL,
            round_no       INT NOT NULL DEFAULT 1,
            seq            INT NOT NULL,
            step_type      VARCHAR(8) NOT NULL COMMENT 'dept 部門主管／gm 總經理／cosign 會辦單位',
            cosign_dept_id INT NULL,
            cosign_dept_name VARCHAR(100) NULL,
            assignee_id    INT NULL,
            assignee_name  VARCHAR(60) NULL,
            is_delegated   TINYINT NOT NULL DEFAULT 0,
            status         VARCHAR(10) NOT NULL DEFAULT 'waiting' COMMENT 'waiting/pending/approved/rejected/skipped',
            note           VARCHAR(1000) NULL COMMENT '批示意見／略過原因',
            decided_by     INT NULL,
            decided_by_name VARCHAR(60) NULL,
            decided_date   DATE NULL COMMENT '批示業務日期',
            decided_at     DATETIME NULL,
            event_id       INT NULL COMMENT '待簽通知 live_event.id',
            KEY idx_memo (memo_id, round_no),
            KEY idx_assignee (assignee_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='簽呈簽核關卡'");
        $db->exec("CREATE TABLE IF NOT EXISTS memo_comment (
            comment_id INT AUTO_INCREMENT PRIMARY KEY,
            memo_id    INT NOT NULL,
            user_id    INT NOT NULL,
            user_name  VARCHAR(60) NULL,
            content    VARCHAR(1000) NOT NULL,
            created_at DATETIME NULL,
            KEY idx_memo (memo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='簽呈回覆／討論'");
        $db->exec("CREATE TABLE IF NOT EXISTS memo_route (
            route_id INT AUTO_INCREMENT PRIMARY KEY, route_name VARCHAR(60) NOT NULL,
            need_dept TINYINT NOT NULL DEFAULT 1 COMMENT '需部門主管批示', need_gm TINYINT NOT NULL DEFAULT 1 COMMENT '需總經理批示',
            allow_cosign TINYINT NOT NULL DEFAULT 1 COMMENT '可加會辦單位', is_default TINYINT NOT NULL DEFAULT 0,
            is_active TINYINT NOT NULL DEFAULT 1, sort_no INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='簽呈固定送簽模式'");
        if (!(int)$db->query('SELECT COUNT(*) FROM memo_route')->fetchColumn()) {
            $db->exec("INSERT INTO memo_route (route_name, need_dept, need_gm, allow_cosign, is_default, sort_no) VALUES
                ('一般簽呈（部門主管→總經理）',1,1,1,1,1), ('老闆直接指示（直送總經理）',0,1,1,0,2), ('部門內簽呈（僅部門主管）',1,0,1,0,3)");
        }
        $has = $db->query("SHOW COLUMNS FROM memo_request LIKE 'route_id'")->fetch();
        if (!$has) $db->exec("ALTER TABLE memo_request ADD COLUMN route_id INT NULL COMMENT '送簽模式'");
        $has = $db->query("SHOW COLUMNS FROM memo_request LIKE 'src_memo_id'")->fetch();
        if (!$has) $db->exec("ALTER TABLE memo_request ADD COLUMN src_memo_id INT NULL COMMENT '調查結果簽呈：原啟動調查簽呈 memo_id'");
        foreach ([['memo_view_all', '簽呈檢閱'], ['memo_admin', '簽呈管理員']] as $r) {
            $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='memo_request' LIMIT 1");
            $st->execute([$r[0]]);
            if (!$st->fetchColumn()) {
                $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'memo_request')")->execute([$r[0], $r[1]]);
            }
        }
    } catch (Throwable $e) {}
}

/* ============================ 使用者與權限 ============================ */

function memo_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function memo_has_role(PDO $db, int $uid, array $codes): bool
{
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='memo_request' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** isAdmin＝系統管理者／canAdmin＝簽呈管理員（含系統管理者）／canViewAll＝檢閱全部（不含機密）／canApply＝在職員工 */
function memo_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canViewAll'=>false,'canApply'=>false,'uid'=>0,'hrLevels'=>[],'supLevels'=>[],'headDepts'=>[],'viewAny'=>false];
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
    $canAdmin   = $isAdmin || memo_has_role($db, $uid, ['memo_admin']);
    $canViewAll = $canAdmin || memo_has_role($db, $uid, ['memo_view_all']);
    $canApply   = !in_array((int)($u['state'] ?? 1), [0, 90], true);
    // 角色可看的機密等級（管理員設定）：人事部門成員看全公司、部門主管看自己主管的部門（含下轄）
    $set = memo_settings($db);
    $hrLv = $supLv = []; $headDepts = [];
    if (!$canAdmin) {
        if ($set['memo_view_hr_levels'] && memo_is_hr($db, $uid)) $hrLv = $set['memo_view_hr_levels'];
        if ($set['memo_view_sup_levels']) { $headDepts = memo_head_dept_ids($db, $uid); if ($headDepts) $supLv = $set['memo_view_sup_levels']; }
    }
    $viewAny = $canViewAll || $hrLv || $supLv;
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canViewAll'=>$canViewAll, 'canApply'=>$canApply, 'uid'=>$uid,
            'hrLevels'=>$hrLv, 'supLevels'=>$supLv, 'headDepts'=>$headDepts, 'viewAny'=>(bool)$viewAny];
}

/* ============================ 設定 ============================ */

function memo_settings(PDO $db): array
{
    $out = ['memo_need_gm'=>1, 'memo_cosign_before_gm'=>0, 'memo_stamp_tpl_id'=>null, 'memo_view_hr_levels'=>[], 'memo_view_sup_levels'=>[]];
    try {
        $in = implode(',', array_fill(0, count(MEMO_SETTING_KEYS), '?'));
        $st = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(MEMO_SETTING_KEYS);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['setting_key']; $v = $r['setting_value'];
            if ($k === 'memo_stamp_tpl_id') $out[$k] = ($v === '' || $v === null) ? null : (int)$v;
            elseif ($k === 'memo_view_hr_levels' || $k === 'memo_view_sup_levels') $out[$k] = memo_levels_clean(explode(',', (string)$v));
            else $out[$k] = (int)$v;
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 機密等級清單過濾成合法值 */
function memo_levels_clean(array $a): array
{
    return array_values(array_unique(array_filter(array_map('trim', $a), fn($x) => isset(MEMO_SECRECY[$x]))));
}

/** 是否為人事部門（組織角色綁定 hr_dept，含下轄）成員 */
function memo_is_hr(PDO $db, int $uid): bool
{
    $ids = eg_org_dept_ids($db, 'hr_dept');
    if (!$ids) return false;
    foreach (eg_unit_posts($db) as $p) if ((int)$p['id'] === $uid && in_array((int)($p['dept_id'] ?? 0), $ids, true)) return true;
    return false;
}

/** 這個人擔任最高主管的部門（含下轄部門）id 清單 */
function memo_head_dept_ids(PDO $db, int $uid): array
{
    $out = [];
    try {
        foreach ($db->query('SELECT id FROM department')->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $h = eg_unit_dept_head($db, (int)$d);
            if ($h && (int)$h['id'] === $uid) $out = array_merge($out, eg_dept_subtree_ids($db, (int)$d));
        }
    } catch (Throwable $e) {}
    return array_values(array_unique(array_map('intval', $out)));
}

/* ===== 固定送簽模式（管理員設定）：決定要不要部門主管／總經理、可不可以會辦 ===== */
function memo_routes(PDO $db, bool $onlyActive = false): array
{
    try {
        $r = $db->query('SELECT * FROM memo_route' . ($onlyActive ? ' WHERE is_active=1' : '') . ' ORDER BY sort_no, route_id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    foreach ($r as &$x) foreach (['route_id','need_dept','need_gm','allow_cosign','is_default','is_active','sort_no'] as $k) $x[$k] = (int)$x[$k];
    return $r;
}

/** 這張簽呈適用的送簽模式；沒指定或已刪除就用預設，連預設都沒有回 null（沿用全域設定） */
function memo_route_for(PDO $db, array $memo): ?array
{
    $all = memo_routes($db);
    foreach ($all as $r) if ((int)$r['route_id'] === (int)($memo['route_id'] ?? 0)) return $r;
    foreach ($all as $r) if ($r['is_default'] && $r['is_active']) return $r;
    return null;
}

function memo_save_setting(PDO $db, string $key, $val): void
{
    if (!in_array($key, MEMO_SETTING_KEYS, true)) return;
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$key, (string)$val]);
}

function memo_stamp_template(PDO $db): ?array
{
    $id = (int)(memo_settings($db)['memo_stamp_tpl_id'] ?? 0);
    if (!$id) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}

/* ============================ 單據讀取與可見性 ============================ */

function memo_steps(PDO $db, int $memoId, ?int $round = null): array
{
    $sql = "SELECT * FROM memo_step WHERE memo_id=?" . ($round ? " AND round_no=?" : "") . " ORDER BY round_no, seq, step_id";
    $st = $db->prepare($sql);
    $st->execute($round ? [$memoId, $round] : [$memoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function memo_row(PDO $db, int $memoId): ?array
{
    $st = $db->prepare("SELECT * FROM memo_request WHERE memo_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$memoId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['cosign'] = json_decode((string)($r['cosign_json'] ?? ''), true) ?: [];
    $r['steps']  = memo_steps($db, $memoId);
    return $r;
}

/** 這個人是不是這張單簽核關卡上的人（任何一輪） */
function memo_is_signer(array $memo, int $uid): bool
{
    foreach ($memo['steps'] ?? [] as $s) if ((int)$s['assignee_id'] === $uid) return true;
    return false;
}

/**
 * 我看不看得到：提簽人／建立者／簽核關卡上的人／管理員一律可看；
 * 檢閱角色只看「一般」等級，機密與極機密只限關係人與管理員。
 */
function memo_can_see(array $memo, array $perms, int $uid): bool
{
    if ($perms['canAdmin']) return true;
    if ((int)$memo['user_id'] === $uid || (int)($memo['created_by'] ?? 0) === $uid) return true;
    if (memo_is_signer($memo, $uid)) return true;
    $sec = $memo['secrecy'] ?? 'normal';
    if ($perms['canViewAll'] && $sec === 'normal') return true;
    if (in_array($sec, $perms['hrLevels'] ?? [], true)) return true;
    return in_array($sec, $perms['supLevels'] ?? [], true) && in_array((int)($memo['dept_id'] ?? 0), $perms['headDepts'] ?? [], true);
}

/** 「全部」清單的可見範圍 SQL（與 memo_can_see 同規則；管理員不限） */
function memo_visible_sql(array $perms, int $uid): array
{
    if ($perms['canAdmin']) return ['1=1', []];
    $o = ['m.user_id=?', 'm.created_by=?', 'EXISTS (SELECT 1 FROM memo_step s WHERE s.memo_id=m.memo_id AND s.assignee_id=?)']; $p = [$uid, $uid, $uid];
    if ($perms['canViewAll']) $o[] = "m.secrecy='normal'";
    if ($perms['hrLevels']) { $o[] = 'm.secrecy IN (' . implode(',', array_fill(0, count($perms['hrLevels']), '?')) . ')'; $p = array_merge($p, $perms['hrLevels']); }
    if ($perms['supLevels'] && $perms['headDepts']) {
        $o[] = 'm.secrecy IN (' . implode(',', array_fill(0, count($perms['supLevels']), '?')) . ') AND m.dept_id IN (' . implode(',', array_map('intval', $perms['headDepts'])) . ')';
        $p = array_merge($p, $perms['supLevels']);
    }
    return ['(' . implode(' OR ', array_map(fn($x) => "($x)", $o)) . ')', $p];
}

function memo_next_no(PDO $db, string $applyDate): string
{
    $ym = str_replace('-', '', substr($applyDate, 0, 7));
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM memo_request WHERE DATE_FORMAT(apply_date,'%Y%m')=?");
        $st->execute([$ym]);
        $n = (int)$st->fetchColumn() + 1;
    } catch (Throwable $e) { $n = 1; }
    return 'QP-' . $ym . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/** 提簽人的部門／職稱預設值（主要職務） */
function memo_user_identity(PDO $db, int $uid): array
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
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out = ['user_name'=>(string)$r['user_cname'],
                    'dept_id'=>$r['department_id'] !== null ? (int)$r['department_id'] : null,
                    'dept_name'=>(string)($r['dept_name'] ?? ''), 'position_name'=>(string)($r['position_name'] ?? '')];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 時限推算應完成日（日曆天；普通3／速件2／最速件0） */
function memo_due_date(string $baseDate, string $urgency): string
{
    $days = MEMO_URGENCY[$urgency][1] ?? 3;
    return date('Y-m-d', strtotime($baseDate . " +{$days} day"));
}

/* ============================ 簽核人解析 ============================ */

/** 把一個人過代理解析（請假／代理設定），回傳 ['id','name','delegated'] */
function memo_resolve_signer(PDO $db, int $baseId, int $applicantId, ?int $scopeDept = null): array
{
    $signer = $baseId; $delegated = false;
    try {
        $bid = $scopeDept ? ['department_id'=>$scopeDept, 'position_id'=>0] : (eg_user_main_identity($db, $baseId) ?: ['department_id'=>0,'position_id'=>0]);
        $rs = eg_resolve_signer($db, $baseId, ['applicant_id'=>$applicantId,
                'scope_department_id'=>($bid['department_id'] ?: null), 'scope_position_id'=>($bid['position_id'] ?: null),
                'scope_is_signer'=>true, 'flow_key'=>'memo_request', 'auto_sign'=>false]);
        if (!empty($rs['signer_id'])) {
            $signer = (int)$rs['signer_id'];
            $delegated = !empty($rs['is_delegated']) || !empty($rs['is_sod_escalated']);
        }
    } catch (Throwable $e) {}
    $nm = '';
    try { $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $st->execute([$signer]); $nm = (string)$st->fetchColumn(); }
    catch (Throwable $e) {}
    return ['id'=>$signer, 'name'=>$nm, 'delegated'=>$delegated];
}

/** 部門主管批示關：回傳 [簽核人或null, 略過原因] */
function memo_resolve_dept(PDO $db, array $memo): array
{
    $uid = (int)$memo['user_id'];
    $sup = eg_unit_supervisor($db, $uid, $memo['dept_id'] ? (int)$memo['dept_id'] : null, (string)$memo['apply_date']);
    if (empty($sup['id']) || (int)$sup['id'] === $uid) {
        return [null, '提簽人即本單位最高主管（或查無單位主管），略過部門主管批示'];
    }
    return [memo_resolve_signer($db, (int)$sup['id'], $uid, !empty($sup['dept_id']) ? (int)$sup['dept_id'] : null), ''];
}

function memo_resolve_gm(PDO $db, array $memo): array
{
    $uid = (int)$memo['user_id'];
    $top = eg_org_user($db, 'top_approver');
    if (!$top) return [null, '尚未設定最高核准人員（組織角色綁定），略過總經理批示'];
    if ((int)$top['id'] === $uid) return [null, '提簽人即最高核准人員，略過總經理批示'];
    return [memo_resolve_signer($db, (int)$top['id'], $uid), ''];
}

/** 會辦單位：有指定人就用該人；沒指定就取該部門主管 */
function memo_resolve_cosign(PDO $db, array $memo, array $c): array
{
    $uid = (int)$memo['user_id'];
    $deptId = (int)($c['dept_id'] ?? 0);
    $dn = '';
    if ($deptId) { try { $st = $db->prepare("SELECT name FROM department WHERE id=?"); $st->execute([$deptId]); $dn = (string)$st->fetchColumn(); } catch (Throwable $e) {} }
    $base = (int)($c['user_id'] ?? 0);
    if (!$base && $deptId) {
        $h = eg_unit_dept_head($db, $deptId, (string)$memo['apply_date']) ?: eg_org_dept_manager($db, $deptId);
        $base = (int)($h['id'] ?? 0);
    }
    if (!$base) return [null, $dn, '會辦單位「' . $dn . '」查無主管，略過'];
    if ($base === $uid) return [null, $dn, '會辦人即提簽人本人，略過'];
    return [memo_resolve_signer($db, $base, $uid, $deptId ?: null), $dn, ''];
}

/* ============================ 流程引擎 ============================ */

/** 送出：建立本輪全部關卡（略過的也留一列說明原因），再啟動第一批 */
function memo_build_steps(PDO $db, array $memo): void
{
    $round = (int)$memo['round_no'];
    $set   = memo_settings($db);
    $plan  = [];     // [type, signer|null, skipNote, extra]
    [$s, $why] = memo_resolve_dept($db, $memo);
    $route = memo_route_for($db, $memo);
    if ($route && !$route['need_dept']) { $s = null; $why = '依送簽模式「' . $route['route_name'] . '」免部門主管批示'; }
    $plan[] = ['dept', $s, $why, []];
    $gm = [];
    if ($route ? (int)$route['need_gm'] === 1 : (int)$set['memo_need_gm'] === 1) { [$s, $why] = memo_resolve_gm($db, $memo); $gm[] = ['gm', $s, $why, []]; }
    $co = [];
    foreach (($route && !$route['allow_cosign']) ? [] : array_slice($memo['cosign'] ?? [], 0, MEMO_COSIGN_MAX) as $c) {
        [$s, $dn, $why] = memo_resolve_cosign($db, $memo, $c);
        $co[] = ['cosign', $s, $why, ['dept_id'=>(int)($c['dept_id'] ?? 0), 'dept_name'=>$dn]];
    }
    $plan = array_merge($plan, (int)$set['memo_cosign_before_gm'] === 1 ? array_merge($co, $gm) : array_merge($gm, $co));
    $ins = $db->prepare("INSERT INTO memo_step (memo_id, round_no, seq, step_type, cosign_dept_id, cosign_dept_name,
                         assignee_id, assignee_name, is_delegated, status, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $seq = 0;
    foreach ($plan as $p) {
        $seq++;
        $ins->execute([$memo['memo_id'], $round, $seq, $p[0], $p[3]['dept_id'] ?? null, $p[3]['dept_name'] ?? null,
            $p[1]['id'] ?? null, $p[1]['name'] ?? null, !empty($p[1]['delegated']) ? 1 : 0,
            $p[1] ? 'waiting' : 'skipped', $p[1] ? null : $p[2]]);
    }
}

/**
 * 推進：本輪沒有待簽的關卡時，啟動下一關（會辦單位整批同時啟動）；全部完成就結案核准。
 * 回傳 'pending'（已啟動新關卡）／'approved'（全部完成）。
 */
function memo_advance(PDO $db, int $memoId, int $fromUid): string
{
    $memo = memo_row($db, $memoId);
    $round = (int)$memo['round_no'];
    $cur = array_values(array_filter($memo['steps'], fn($s) => (int)$s['round_no'] === $round));
    foreach ($cur as $s) if ($s['status'] === 'pending') return 'pending';
    $next = null;
    foreach ($cur as $s) if ($s['status'] === 'waiting') { $next = $s; break; }
    if (!$next) {
        $now = $db->query("SELECT NOW() n, CURDATE() d")->fetch(PDO::FETCH_ASSOC);
        $db->prepare("UPDATE memo_request SET status='approved', closed_date=?, closed_at=?, reject_note=NULL, updated_at=NOW() WHERE memo_id=?")
           ->execute([$now['d'], $now['n'], $memoId]);
        $memo['status'] = 'approved';
        memo_notify_result($db, $memo, 'approved', '', $fromUid, '');
        return 'approved';
    }
    $batch = ($next['step_type'] === 'cosign')
        ? array_filter($cur, fn($s) => $s['status'] === 'waiting' && $s['step_type'] === 'cosign') : [$next];
    foreach ($batch as $s) {
        $db->prepare("UPDATE memo_step SET status='pending' WHERE step_id=?")->execute([$s['step_id']]);
        $ev = memo_notify_signer($db, $memo, $s, $fromUid);
        if ($ev) $db->prepare("UPDATE memo_step SET event_id=? WHERE step_id=?")->execute([$ev, $s['step_id']]);
    }
    return 'pending';
}

/** 退回或撤銷：關閉本輪所有待簽通知、尚未簽的關卡標為略過 */
function memo_close_open_steps(PDO $db, int $memoId, int $round, string $why): void
{
    $st = $db->prepare("SELECT step_id, event_id FROM memo_step WHERE memo_id=? AND round_no=? AND status IN ('pending','waiting')");
    $st->execute([$memoId, $round]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        memo_close_event($db, (int)$s['event_id']);
        $db->prepare("UPDATE memo_step SET status='skipped', note=? WHERE step_id=?")->execute([$why, $s['step_id']]);
    }
}

function memo_close_event(PDO $db, int $eventId): void
{
    if (!$eventId) return;
    try { $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=? AND (enddate IS NULL OR enddate>=CURDATE())")->execute([$eventId]); }
    catch (Throwable $e) {}
}

/* ============================ 通知（ai-rules/17） ============================ */

const MEMO_STEP_LABEL = ['dept'=>'部門主管批示', 'gm'=>'總經理批示', 'cosign'=>'會辦單位批示'];

function memo_send_event(PDO $db, string $title, string $content, int $fromUid, string $refType, int $memoId, int $toUid, string $mode): int
{
    if (!$toUid) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '簽呈', 1, ?, ?)")->execute([$title, $content, $fromUid, $refType, $memoId]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)")->execute([$eid, $toUid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

function memo_notify_signer(PDO $db, array $memo, array $step, int $fromUid): int
{
    $secret = ($memo['secrecy'] ?? 'normal') !== 'normal';
    $title = '簽呈待' . (MEMO_STEP_LABEL[$step['step_type']] ?? '批示') . '：' . $memo['subject'];
    $content = '提簽人：' . $memo['user_name'] . '（' . $memo['dept_name'] . '）' . "\n"
             . '機密等級：' . (MEMO_SECRECY[$memo['secrecy']] ?? '') . '　時限：' . (MEMO_URGENCY[$memo['urgency']][0] ?? '') . "\n"
             . ($secret ? '' : '主　　旨：' . $memo['subject'] . "\n")
             . '編號：' . ($memo['memo_no'] ?: '#' . $memo['memo_id']) . '　點此開啟簽呈，可直接批示核准或退回（退回須填原因）。';
    return memo_send_event($db, $title, $content, $fromUid, 'MEMO_APPROVAL', (int)$memo['memo_id'], (int)$step['assignee_id'], 'sign');
}

function memo_notify_result(PDO $db, array $memo, string $decision, string $note, int $fromUid, string $byName): void
{
    $ok = ($decision === 'approved');
    $title = '簽呈' . ($ok ? '已核准' : '已退回') . '：' . $memo['subject'];
    $content = $ok ? '您的簽呈所有關卡皆已批示完成。' : (($byName ? $byName . ' ' : '') . '已退回您的簽呈。退回原因：' . $note);
    memo_send_event($db, $title, $content, $fromUid, 'MEMO_RESULT', (int)$memo['memo_id'], (int)$memo['user_id'], 'read');
}

/** 回覆通知：提簽人＋本張單所有關卡簽核人（除了寫回覆的人自己） */
function memo_notify_comment(PDO $db, array $memo, int $fromUid, string $byName, string $text): void
{
    $to = [(int)$memo['user_id']];
    foreach ($memo['steps'] as $s) if ((int)$s['assignee_id']) $to[] = (int)$s['assignee_id'];
    $title = '簽呈有新回覆：' . $memo['subject'];
    $content = $byName . '：' . mb_substr($text, 0, 200);
    foreach (array_unique($to) as $t) {
        if ($t && $t !== $fromUid) memo_send_event($db, $title, $content, $fromUid, 'MEMO_RESULT', (int)$memo['memo_id'], $t, 'read');
    }
}
