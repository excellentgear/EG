<?php
// Leave_API.php — 請假系統後端 API
// 商業邏輯集中在 src/common/leave_lib.php（代理解析一律走 delegate_lib eg_resolve_signer，
// 禁用 leave_agent_setting、禁自寫 user_delegate SQL —— ai-rules/11、12 鐵律）。
// 前端：views/ADM/leave_request.php
//
// 權限模型：
//   - 登入即可：送審自己的單、查自己的單、撤回/銷假自己的單、上傳自己單的附件、額度查詢
//   - 簽核：不看角色，看「是否為該層主管本人或簽核當下解析出的代理」（eg_leave_can_sign）
//   - 檢視範圍（2026-07-28 定案）：自己；主管看部門(含下轄)；管理員/人事(leave_view_all)看全部
// 寫入動作一律 CSRF 驗證（fail-closed）。
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/role_features_helper.php';
require_once __DIR__ . '/../common/leave_lib.php';
require_once __DIR__ . '/../common/leave_stats_lib.php';
require_once __DIR__ . '/../common/people_lib.php';

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => '尚未登入']);
    exit;
}
$user_id = (int)$_SESSION['id'];

$conn = new DBConnection();
$db = $conn->getPDO();

// ---- 權限 ----
$features = rf_load_user_features_override($db, $user_id, 'leave');
$IS_ADMIN = rf_has_feature($features, 'all');
$VIEW_ALL = $IS_ADMIN || rf_has_feature($features, 'leave_view_all');

// 主管身分：主職職稱有階級（position_level.level 非 NULL）→ 可看本部門（含下轄部門）
function leave_dept_scope(PDO $db, int $uid): array {
    // 回傳可視部門 id 清單；空陣列=非主管
    try {
        $main = eg_user_main_identity($db, $uid);
        if (!$main || $main['level'] === null) return [];
        $depts = [$main['department_id']];
        // 下轄部門（沿 parent_id 往下收，最多 3 層防迴圈）
        $frontier = $depts;
        for ($hop = 0; $hop < 3 && $frontier; $hop++) {
            $in = implode(',', array_map('intval', $frontier));
            $rows = $db->query("SELECT id FROM department WHERE parent_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            $frontier = array_diff(array_map('intval', $rows), $depts);
            $depts = array_merge($depts, $frontier);
        }
        return $depts;
    } catch (Throwable $e) { return []; }
}

function leave_csrf_token(): string {
    if (empty($_SESSION['leave_csrf'])) $_SESSION['leave_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['leave_csrf'];
}
function leave_csrf_ok(?string $t): bool {
    return !empty($_SESSION['leave_csrf']) && is_string($t) && hash_equals($_SESSION['leave_csrf'], $t);
}
function out(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function bad(string $m): void { out(['success' => false, 'message' => $m]); }
function need_csrf(): void {
    if (!leave_csrf_ok($_POST['csrf'] ?? '')) bad('CSRF 驗證失敗，請重新整理頁面');
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

// ════════════════ 初始化（頁面載入一次拿齊） ════════════════
case 'bootstrap': {
    // 假別清單
    $types = $db->query("SELECT id, leave_name, agent, need_approval, max_approval_level,
                                unit_type, require_attachment, attach_min_days, allow_attach_later,
                                rule_kind, rule_max_value, rule_max_unit, rule_deadline_days,
                                rule_child_age_years, rule_min_days, rule_note
                         FROM leave_type ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    // 我的候選代理人（唯讀，來自 delegate_lib）
    $cands = eg_person_delegate_candidates($db, $user_id);
    // 特休摘要 ＋ 本年度各假別已核准累積（只列請過的假別）
    $annual = eg_leave_annual_summary($db, $user_id);
    $yearUsage = eg_leave_year_usage($db, $user_id);
    $myYears = eg_leave_years_of($db, $user_id);   // 申請頁年度下拉（我有請假資料的年度＋今年）
    // 檢視範圍
    $deptScope = $VIEW_ALL ? [] : leave_dept_scope($db, $user_id);
    $cfg = eg_leave_settings($db);
    $st = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
    $st->execute([$user_id]);
    out(['success' => true,
         'csrf' => leave_csrf_token(),
         'me' => ['id' => $user_id, 'name' => (string)$st->fetchColumn()],
         'perm' => ['admin' => $IS_ADMIN, 'view_all' => $VIEW_ALL, 'view_dept' => !empty($deptScope)],
         'leave_types' => $types,
         'agent_candidates' => $cands,
         'annual' => $annual,
         'year_usage' => $yearUsage,
         'my_years' => $myYears,
         'cur_year' => (int)date('Y'),
         // 喪假親等（申請頁下拉用；只給啟用中的）
         'grades' => eg_leave_rule_grades($db, true),
         'settings' => [
             'backdate_limit_days' => (int)$cfg['leave_backdate_limit_days'],
             'hours_per_day' => (float)$cfg['leave_hours_per_day'],
             'halfday_hours' => (float)$cfg['leave_halfday_hours'],
             'attach_ready' => trim((string)$cfg['leave_attach_base']) !== '',
             'print_header' => (string)$cfg['leave_print_header'],
             'print_footer' => (string)$cfg['leave_print_footer'],
         ]]);
}

// ════════════════ 列印表頭/表尾設定（僅管理員） ════════════════
case 'save_print_setting': {
    need_csrf();
    if (!$IS_ADMIN) bad('僅管理員可修改列印表頭表尾');
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                        VALUES (?,?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                                updated_by_id = VALUES(updated_by_id),
                                                updated_by = VALUES(updated_by), updated_at = NOW()");
    $nameSt = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
    $nameSt->execute([$user_id]);
    $by = (string)$nameSt->fetchColumn();
    $st->execute(['leave_print_header', trim((string)($_POST['header'] ?? '')), $user_id, $by]);
    $st->execute(['leave_print_footer', trim((string)($_POST['footer'] ?? '')), $user_id, $by]);
    out(['success' => true, 'message' => '已儲存列印表頭表尾']);
}

// ════════════════ 申請前預覽 ════════════════
case 'preview': {
    // 「將由誰簽核」＋時數試算（不寫任何資料）
    $tid = (int)($_GET['leave_type_id'] ?? 0);
    $start = trim((string)($_GET['start'] ?? ''));
    $end = trim((string)($_GET['end'] ?? ''));
    $st = $db->prepare("SELECT * FROM leave_type WHERE id = ? LIMIT 1");
    $st->execute([$tid]);
    $type = $st->fetch(PDO::FETCH_ASSOC);
    if (!$type) bad('假別不存在');
    $ret = ['success' => true];
    if ($start && $end) {
        $amt = eg_leave_calc_amount($db, (string)$type['unit_type'], $start, $end);
        $ret['amount'] = $amt;
        $ret['need_attach'] = eg_leave_attach_needed($type, (float)$amt['days']);
        if ($type['leave_name'] === '特休') {
            $ret['annual'] = eg_leave_annual_summary($db, $user_id, (int)substr($start, 0, 4));
        }
    }
    if ((int)$type['need_approval'] === 1) {
        $ret['signers'] = eg_leave_preview_signers($db, $user_id, max(1, (int)$type['max_approval_level']));
    } else {
        $ret['signers'] = [];
    }
    // 代理人預覽（系統自動依順位解析，申請人不需挑選）
    if ($start && $end && (int)$type['agent'] === 1) {
        $ret['agents'] = eg_leave_resolve_agents($db, $user_id, $start, $end);
    } else {
        $ret['agents'] = [];
    }
    $ret['agent_required'] = ((int)$type['agent'] === 1);

    // ── 假別特殊規則（喪假／育嬰類）：即時回檢查結果與剩餘額度，前端當下就顯示原因 ──
    // 這裡跟送審走的是同一支 eg_leave_rule_check()，所以畫面說可以送、後端就一定收得下；
    // 反過來說前端要是被人繞過，送審那關仍會用同一套規則擋下。
    $ret['rule_kind'] = (string)$type['rule_kind'];
    $ret['rule_note'] = (string)$type['rule_note'];
    if ($type['rule_kind'] !== '') {
        $extra = eg_leave_rule_extra_in($_GET);
        $ret['rule_extra'] = $extra;
        $editId = (int)($_GET['edit_id'] ?? 0);   // 修改既有單時要排除自己
        $q = eg_leave_rule_quota($db, $user_id, $type, $extra, $editId ?: null);
        $ret['rule_quota'] = $q;
        if ($start && $end && isset($ret['amount'])) {
            $chk = eg_leave_rule_check($db, $user_id, $type, $start, $end, $ret['amount'], $extra, $editId ?: null);
            $ret['rule_ok'] = $chk['ok'];
            $ret['rule_msg'] = $chk['msg'];
            $ret['rule_warns'] = $chk['warns'];
        }
        // 喪假：親等決定天數上限，畫面要能顯示「這個關係可請幾天」
        if ($type['rule_kind'] === 'bereavement') $ret['grades'] = eg_leave_rule_grades($db, true);
    }
    out($ret);
}

// ════════════════ 依排班帶出請假時間 ════════════════
case 'roster_shift': {
    // 申請頁選好「請假起日／迄日」後呼叫：回傳該員工當日固定班別的上下班時間，
    // 供前端自動帶出「整天請假」的起訖（跨夜班的結束時間會落到隔天）。
    $s = trim((string)($_GET['start_date'] ?? ''));
    $e = trim((string)($_GET['end_date'] ?? ''));
    if ($s === '') bad('缺少請假起日');
    $r = eg_leave_roster_range($db, $user_id, $s, $e !== '' ? $e : null);
    out(['success' => true] + $r);
}

// ════════════════ 送審 ════════════════
case 'submit': {
    need_csrf();
    $token = trim((string)($_POST['upload_token'] ?? ''));
    $r = eg_leave_submit($db, [
        'employee_id'    => $user_id,   // 只能替自己送
        'leave_type_id'  => (int)($_POST['leave_type_id'] ?? 0),
        'start_datetime' => trim((string)($_POST['start_datetime'] ?? '')),
        'end_datetime'   => trim((string)($_POST['end_datetime'] ?? '')),
        'reason'         => trim((string)($_POST['reason'] ?? '')),
        // 代理人不再由前端傳入：系統依人事設定的順位自動解析（2026-07-30 定案）
        'upload_token'   => $token,
        // 假別特殊規則欄位；是否採用由後端依該假別的 rule_kind 決定（前端亂送也不會亂存）
        'rel_grade_id'   => $_POST['rel_grade_id'] ?? '',
        'deceased_date'  => $_POST['deceased_date'] ?? '',
        'child_birthday' => $_POST['child_birthday'] ?? '',
    ]);
    // 送審成功：把 temp 目錄的實體檔搬到正式目錄 req_<id>（DB 轉正已在 lib 的 transaction 內完成）
    if ($r['ok'] && $token !== '') {
        try {
            $st = $db->prepare("SELECT stored_name FROM leave_attachment WHERE leave_request_id = ? AND status = 'active'");
            $st->execute([(int)$r['id']]);
            $files = $st->fetchAll(PDO::FETCH_COLUMN);
            if ($files) {
                $src = eg_leave_attach_dir($db, 'temp_' . $token);
                $dst = eg_leave_attach_dir($db, 'req_' . (int)$r['id']);
                if ($src && $dst) {
                    if (!is_dir($dst)) @mkdir($dst, 0777, true);
                    foreach ($files as $fn) {
                        if (is_file($src . DIRECTORY_SEPARATOR . $fn)) {
                            @rename($src . DIRECTORY_SEPARATOR . $fn, $dst . DIRECTORY_SEPARATOR . $fn);
                        }
                    }
                    @rmdir($src);   // 搬空後移除暫存資料夾（非空會失敗，無妨）
                }
            }
        } catch (Throwable $e) { /* 搬檔失敗不影響單據；下載時會提示檔案不存在再人工處理 */ }
    }
    out(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? null,
         'need_attach_later' => $r['need_attach_later'] ?? false,
         'warns' => $r['warns'] ?? []]);
}

// ════════════════ 修改（審核前） ════════════════
case 'update': {
    need_csrf();
    $reqId = (int)($_POST['id'] ?? 0);
    if (!$reqId) bad('缺少參數');
    $r = eg_leave_update($db, $reqId, $user_id, [
        'leave_type_id'  => (int)($_POST['leave_type_id'] ?? 0),
        'start_datetime' => trim((string)($_POST['start_datetime'] ?? '')),
        'end_datetime'   => trim((string)($_POST['end_datetime'] ?? '')),
        'reason'         => trim((string)($_POST['reason'] ?? '')),
        // 代理人不再由前端傳入：系統依人事設定的順位自動解析（2026-07-30 定案）
        'rel_grade_id'   => $_POST['rel_grade_id'] ?? '',
        'deceased_date'  => $_POST['deceased_date'] ?? '',
        'child_birthday' => $_POST['child_birthday'] ?? '',
    ], $IS_ADMIN);
    out(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? null,
         'warns' => $r['warns'] ?? []]);
}

// ════════════════ 簽核 ════════════════
case 'sign': {
    need_csrf();
    $reqId = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['decision'] ?? '');
    $act = $act === 'approve' ? 'approved' : ($act === 'reject' ? 'rejected' : '');
    if (!$reqId || !$act) bad('缺少參數');
    if ($act === 'rejected' && trim((string)($_POST['remark'] ?? '')) === '') bad('退回必須填寫意見');
    // kind=cancel → 簽核「撤回申請」（請假期間內撤回需主管簽核）；否則為請假本身的簽核
    $kind = (($_POST['kind'] ?? '') === 'cancel') ? 'cancel' : 'leave';
    $r = ($kind === 'cancel')
        ? eg_leave_sign_cancel($db, $reqId, $user_id, $act, trim((string)($_POST['remark'] ?? '')))
        : eg_leave_sign($db, $reqId, $user_id, $act, trim((string)($_POST['remark'] ?? '')));
    out(['success' => $r['ok'], 'message' => $r['msg'], 'final' => $r['final'] ?? false]);
}

// ════════════════ 撤回 / 銷假 ════════════════
case 'cancel': {
    need_csrf();
    $reqId = (int)($_POST['id'] ?? 0);
    if (!$reqId) bad('缺少參數');
    $r = eg_leave_cancel($db, $reqId, $user_id, trim((string)($_POST['reason'] ?? '')), $IS_ADMIN);
    out(['success' => $r['ok'], 'message' => $r['msg']]);
}

// ════════════════ 徹底刪除（僅管理者／測試用） ════════════════
case 'delete': {
    need_csrf();
    // 僅「員工 id=1 且在職狀態=99（最高權限）」可用（2026-07-30 使用者要求，比一般管理者更嚴）
    if (!eg_leave_is_superadmin($db, $user_id)) bad('此功能僅限最高權限帳號（員工編號 1）使用');
    $reqId = (int)($_POST['id'] ?? 0);
    if (!$reqId) bad('缺少參數');
    // 二次確認：前端須把單號原樣送回，避免誤觸就把整張單連通知簽核紀錄一起刪掉
    if ((string)($_POST['confirm_id'] ?? '') !== (string)$reqId) bad('確認碼不符，未執行刪除');
    // 第三道：必須輸入 id=1 本人的密碼（2026-07-30 使用者要求；不可回復的操作要有本人確認）
    $pw = eg_leave_verify_superadmin_password($db, (string)($_POST['password'] ?? ''));
    if (!$pw['ok']) bad($pw['msg']);
    $r = eg_leave_delete($db, $reqId, $user_id);
    out(['success' => $r['ok'], 'message' => $r['msg']]);
}

// ════════════════ 待我簽核清單 ════════════════
case 'pending_for_me': {
    $rows = eg_leave_pending_for($db, $user_id);
    out(['success' => true, 'rows' => $rows, 'count' => count($rows)]);
}

// ════════════════ 請假單列表（分頁＋範圍） ════════════════
case 'list': {
    $scope = (string)($_GET['scope'] ?? 'mine');   // mine / dept / all
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = (int)($_GET['per'] ?? 10);
    if (!in_array($per, [5, 10, 20, 50], true)) $per = 10;
    $status = trim((string)($_GET['status'] ?? ''));
    // 年度：預設今年，'all'＝不限年度。年度歸屬以請假「起日」年份計，
    // 與特休額度／年度累積同一口徑（跨年度的假算在起日那年）。
    $yearRaw = trim((string)($_GET['year'] ?? date('Y')));
    // 範圍條件與「狀態／年度」條件分開放：年度下拉的選項只能套範圍條件算，
    // 否則切到 2025 之後下拉就只剩 2025 一個選項，切不回來。
    $scopeWhere = []; $scopeArgs = [];

    if ($scope === 'all') {
        if (!$VIEW_ALL) bad('您沒有檢視全部請假單的權限');
    } elseif ($scope === 'dept') {
        $depts = leave_dept_scope($db, $user_id);
        if (!$depts && !$VIEW_ALL) bad('您不是主管，無法檢視部門請假單');
        if ($depts) {
            $in = implode(',', array_map('intval', $depts));
            $scopeWhere[] = "lr.employee_id IN (SELECT DISTINCT m.user_id FROM user_department_position_map m WHERE m.department_id IN ($in))";
        }
    } else {
        $scopeWhere[] = 'lr.employee_id = ?'; $scopeArgs[] = $user_id;
    }
    $where = $scopeWhere; $args = $scopeArgs;
    if ($status !== '' && in_array($status, ['pending', 'cancel_pending', 'approved', 'rejected', 'canceled'], true)) {
        $where[] = 'lr.status = ?'; $args[] = $status;
    }
    if ($yearRaw !== 'all' && ctype_digit($yearRaw)) {
        $where[] = 'YEAR(lr.start_datetime) = ?'; $args[] = (int)$yearRaw;
    }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // 有資料的年度（供前端下拉；只套範圍條件）
    $sw = $scopeWhere ? ('WHERE ' . implode(' AND ', $scopeWhere)) : '';
    $st = $db->prepare("SELECT DISTINCT YEAR(lr.start_datetime) AS y FROM leave_request lr $sw ORDER BY y DESC");
    $st->execute($scopeArgs);
    $years = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));

    $st = $db->prepare("SELECT COUNT(*) FROM leave_request lr $w");
    $st->execute($args);
    $total = (int)$st->fetchColumn();

    $off = ($page - 1) * $per;
    $st = $db->prepare(
        "SELECT lr.*, lt.leave_name, lt.unit_type, u.user_cname AS applicant_name,
                ag.user_cname AS agent_name
         FROM leave_request lr
         JOIN leave_type lt ON lt.id = lr.leave_type_id
         JOIN user u ON u.id = lr.employee_id
         LEFT JOIN user ag ON ag.id = lr.agent_user_id
         $w ORDER BY lr.submit_time DESC LIMIT $per OFFSET $off");
    $st->execute($args);
    out(['success' => true, 'total' => $total, 'page' => $page, 'per' => $per,
         'year' => $yearRaw, 'years' => $years,
         'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

// ════════════════ 請假統計（月／年／趨勢／部門・人員） ════════════════
// 檢視範圍與請假單列表同一套：人事/管理員看全公司，主管看自己部門(含下轄)，其他人看不到本分頁。
// 統計一律後端全量計算（eg_leave_stats），前端只負責畫圖，不可自己加總已載入的那一頁。
case 'stats': {
    $depts = $VIEW_ALL ? [] : leave_dept_scope($db, $user_id);
    if (!$VIEW_ALL && !$depts) bad('您沒有檢視請假統計的權限');
    // 主管只看得到自己部門(含下轄)的人；人事/管理員不設限（null）
    $scopeIds = null;
    if (!$VIEW_ALL) {
        $in = implode(',', array_map('intval', $depts));
        $scopeIds = array_map('intval', $db->query(
            "SELECT DISTINCT user_id FROM user_department_position_map WHERE department_id IN ($in)")
            ->fetchAll(PDO::FETCH_COLUMN));
        if (!$scopeIds) $scopeIds = [0];   // 空白名單也要是「什麼都看不到」，不能退化成看全部
    }
    $yearRaw = trim((string)($_GET['year'] ?? date('Y')));
    $year = ($yearRaw === 'all') ? 'all'
          : ((ctype_digit($yearRaw) && (int)$yearRaw >= 1990 && (int)$yearRaw <= 9999) ? (int)$yearRaw : (int)date('Y'));
    $typeIds = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['type_ids'] ?? '')))));
    // 預設只算「已核准」；人事要看含審核中的預估時才切 with_pending
    $statuses = ((string)($_GET['with_pending'] ?? '') === '1') ? ['approved', 'pending', 'cancel_pending'] : ['approved'];

    // 主管切「某部門」時仍受可視範圍限制：dept_id 只能是自己看得到的部門
    $deptId = (int)($_GET['dept_id'] ?? 0);
    if ($deptId > 0 && !$VIEW_ALL && !in_array($deptId, array_map('intval', $depts), true)) $deptId = 0;

    out(['success' => true, 'scope' => $VIEW_ALL ? 'all' : 'dept',
         'with_pending' => ($statuses !== ['approved']),
         'data' => eg_leave_stats($db, [
             'scope_user_ids' => $scopeIds,
             'year'           => $year,
             'dept_id'        => $deptId,
             'user_id'        => (int)($_GET['user_id'] ?? 0),
             'type_ids'       => $typeIds,
             'statuses'       => $statuses,
         ])]);
}

// ════════════════ 統計頁的篩選下拉（部門／人員） ════════════════
// 人員下拉一律走 people_lib（CLAUDE.md 人員列表鐵則：只列未離職、標長期請假、依職稱排序、跨部門顯示部門）
case 'stats_options': {
    $depts = $VIEW_ALL ? [] : leave_dept_scope($db, $user_id);
    if (!$VIEW_ALL && !$depts) bad('您沒有檢視請假統計的權限');
    $deptIds = $VIEW_ALL ? [] : array_map('intval', $depts);
    $people = eg_people_list($db, $deptIds ? ['dept_ids' => $deptIds] : []);
    $showDept = eg_people_multi_dept($people);
    $rows = [];
    foreach ($people as $p) {
        $rows[] = ['id' => $p['id'],
                   'label' => $p['display'] . ($showDept && $p['dept_name'] !== '' ? '／' . $p['dept_name'] : ''),
                   'dept_id' => $p['dept_id'] ?? 0];
    }
    // 部門清單：有可視範圍就只給那些，否則全部
    $dq = $deptIds ? ("WHERE id IN (" . implode(',', $deptIds) . ")") : '';
    $dl = $db->query("SELECT id, name FROM department $dq ORDER BY COALESCE(sort_order,999), id")
             ->fetchAll(PDO::FETCH_ASSOC);
    out(['success' => true, 'depts' => $dl, 'people' => $rows, 'show_dept' => $showDept]);
}

// ════════════════ 單一請假單詳情（含簽核歷程） ════════════════
case 'detail': {
    $reqId = (int)($_GET['id'] ?? 0);
    if (!$reqId) bad('缺少參數');
    $st = $db->prepare(
        "SELECT lr.*, lt.leave_name, lt.unit_type, lt.require_attachment, lt.rule_kind,
                u.user_cname AS applicant_name, ag.user_cname AS agent_name,
                bg.grade_name AS rel_grade_name, bg.max_days AS rel_grade_days,
                eb.user_cname AS early_end_by_name
         FROM leave_request lr
         JOIN leave_type lt ON lt.id = lr.leave_type_id
         JOIN user u ON u.id = lr.employee_id
         LEFT JOIN user ag ON ag.id = lr.agent_user_id
         LEFT JOIN leave_bereavement_grade bg ON bg.id = lr.rel_grade_id
         LEFT JOIN user eb ON eb.id = lr.early_end_by
         WHERE lr.id = ? LIMIT 1");
    $st->execute([$reqId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) bad('請假單不存在');

    // 檢視授權：本人 / 全部檢視者 / 部門主管(含下轄) / 該單簽核鏈成員
    $allowed = ((int)$req['employee_id'] === $user_id) || $VIEW_ALL;
    if (!$allowed) {
        $depts = leave_dept_scope($db, $user_id);
        if ($depts) {
            $st = $db->prepare("SELECT 1 FROM user_department_position_map WHERE user_id = ? AND department_id IN ("
                               . implode(',', array_map('intval', $depts)) . ") LIMIT 1");
            $st->execute([(int)$req['employee_id']]);
            $allowed = (bool)$st->fetchColumn();
        }
    }
    if (!$allowed) {
        $st = $db->prepare("SELECT 1 FROM leave_approval WHERE leave_request_id = ? AND approver_id = ? LIMIT 1");
        $st->execute([$reqId, $user_id]);
        $allowed = (bool)$st->fetchColumn();
        if (!$allowed) {
            // 簽核當下解析出的代理也可看目前輪到的層
            foreach (eg_leave_pending_for($db, $user_id) as $p) {
                if ((int)$p['leave_request_id'] === $reqId) { $allowed = true; break; }
            }
        }
    }
    if (!$allowed) bad('您沒有檢視此請假單的權限');

    // 流程狀態（leave_approval）與簽章軌跡（leave_sign_record）
    $st = $db->prepare("SELECT la.*, u.user_cname AS approver_name, d.user_cname AS delegate_name
                        FROM leave_approval la
                        JOIN user u ON u.id = la.approver_id
                        LEFT JOIN user d ON d.id = la.delegate_id
                        WHERE la.leave_request_id = ? ORDER BY la.approval_level ASC");
    $st->execute([$reqId]);
    $approvals = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT sr.*, u.user_cname AS signer_name
                        FROM leave_sign_record sr JOIN user u ON u.id = sr.signer_id
                        WHERE sr.leave_request_id = ? ORDER BY sr.signed_at ASC, sr.id ASC");
    $st->execute([$reqId]);
    $signs = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT id, file_name, ext, file_size, uploaded_at FROM leave_attachment
                        WHERE leave_request_id = ? AND status = 'active' ORDER BY id");
    $st->execute([$reqId]);
    $attaches = $st->fetchAll(PDO::FETCH_ASSOC);

    $edit = eg_leave_can_edit($db, $req, $user_id, $IS_ADMIN);
    out(['success' => true, 'request' => $req, 'approvals' => $approvals,
         'sign_records' => $signs, 'attachments' => $attaches,
         'agents' => eg_leave_get_agents($db, $reqId),   // 每個職務身分的代理人與解析原因
         'can_cancel' => ((int)$req['employee_id'] === $user_id || $IS_ADMIN)
                          && in_array($req['status'], ['pending', 'approved'], true),
         // 撤回會走哪條路：direct=直接撤 / approval=需主管簽核 / blocked=請假已結束不開放
         'cancel_mode' => eg_leave_cancel_mode($req, $IS_ADMIN),
         'can_edit' => $edit['ok'], 'edit_reason' => $edit['reason'],
         // 已核准者提供「申請修改」＝銷假後重新申請（帶回原內容），流程上等同變更
         'can_request_change' => ((int)$req['employee_id'] === $user_id || $IS_ADMIN) && $req['status'] === 'approved']);
}

// ════════════════ 特休額度 ════════════════
case 'annual_summary': {
    $year = (int)($_GET['year'] ?? date('Y'));
    if ($year < 1990 || $year > 9999) $year = (int)date('Y');   // 年度下拉被亂帶時退回今年
    out(['success' => true, 'year' => $year,
         'annual' => eg_leave_annual_summary($db, $user_id, $year),
         'year_usage' => eg_leave_year_usage($db, $user_id, $year)]);
}

// ════════════════ 附件：上傳（temp 或補件） ════════════════
case 'attach_upload': {
    need_csrf();
    $cfg = eg_leave_settings($db);
    if (trim((string)$cfg['leave_attach_base']) === '') bad('附件根目錄尚未設定，請洽管理員（人事設定→請假系統設定）');
    if (empty($_FILES['file'])) bad('未收到檔案');
    $f = $_FILES['file'];
    if (($f['error'] ?? -1) !== UPLOAD_ERR_OK) bad('上傳失敗（錯誤碼 ' . $f['error'] . '）');
    if ($f['size'] > 20 * 1024 * 1024) bad('檔案不可超過 20MB');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) bad('僅接受 jpg/png/pdf');

    $reqId = (int)($_POST['leave_request_id'] ?? 0);
    $token = trim((string)($_POST['upload_token'] ?? ''));

    // 假別若未勾「需附證明文件」，一律不接受上傳（前端也不會顯示上傳區塊；
    // 此處為後端把關，避免繞過畫面直接打 API 塞檔案）
    $assertNeedAttach = function (int $leaveTypeId) use ($db) {
        $st = $db->prepare("SELECT leave_name, require_attachment FROM leave_type WHERE id = ? LIMIT 1");
        $st->execute([$leaveTypeId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) bad('假別不存在');
        if ((int)$t['require_attachment'] !== 1) {
            bad('「' . $t['leave_name'] . '」未設定需附證明文件，不提供上傳；如需開啟請洽管理員於「人事設定→假別設定」勾選。');
        }
    };

    if ($reqId > 0) {
        // 補件：限本人（或管理員），單須存在且該假別需要證明
        $st = $db->prepare("SELECT * FROM leave_request WHERE id = ? LIMIT 1");
        $st->execute([$reqId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) bad('請假單不存在');
        if ((int)$req['employee_id'] !== $user_id && !$IS_ADMIN) bad('僅申請人本人可補件');
        if (in_array($req['status'], ['rejected', 'canceled'], true)) bad('此單已' . ($req['status'] === 'rejected' ? '退回' : '取消') . '，不可補件');
        $assertNeedAttach((int)$req['leave_type_id']);
        $sub = 'req_' . $reqId;
        $statusVal = 'active';
    } else {
        // 新增單據中：temp 暫存（鐵律5：新增當下就能上傳）
        if ($token === '' || !preg_match('/^[a-f0-9]{16,64}$/', $token)) bad('缺少有效的上傳批次識別');
        $assertNeedAttach((int)($_POST['leave_type_id'] ?? 0));
        $sub = 'temp_' . $token;
        $statusVal = 'temp';
    }

    $dir = eg_leave_attach_dir($db, $sub);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) bad('無法建立附件目錄，請確認 NAS 連線與設定');
    $stored = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . DIRECTORY_SEPARATOR . $stored)) bad('檔案寫入失敗');

    $db->prepare("INSERT INTO leave_attachment
                    (leave_request_id, upload_token, file_name, stored_name, ext, file_size, status, uploaded_by)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$reqId > 0 ? $reqId : null, $reqId > 0 ? '' : $token,
                  (string)$f['name'], $stored, $ext, (int)$f['size'], $statusVal, $user_id]);
    $attId = (int)$db->lastInsertId();

    // 補件：若此單原為待補證明 → 轉 done 並通知目前簽核者/已核准單的簽核者
    if ($reqId > 0 && ($req['attach_status'] ?? '') === 'pending') {
        $db->prepare("UPDATE leave_request SET attach_status = 'done', last_update = NOW() WHERE id = ?")->execute([$reqId]);
        $targets = [];
        try {
            if ($req['status'] === 'pending') {
                $st = $db->prepare("SELECT approver_id FROM leave_approval WHERE leave_request_id = ? AND status = 'pending'
                                    ORDER BY approval_level ASC LIMIT 1");
                $st->execute([$reqId]);
                if ($ap = $st->fetchColumn()) {
                    $r = eg_resolve_signer($db, (int)$ap, ['applicant_id' => (int)$req['employee_id'], 'flow_key' => 'leave', 'log' => false]);
                    $targets = [$r['signer_id']];
                }
            } else {
                $st = $db->prepare("SELECT DISTINCT signer_id FROM leave_sign_record WHERE leave_request_id = ? AND action = 'approved'");
                $st->execute([$reqId]);
                $targets = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
        } catch (Throwable $e) {}
        eg_leave_notify($db, $reqId, "📎 請假單 #{$reqId} 已補上證明文件", '申請人已補上傳證明文件，請知悉。',
                        $targets, $user_id, (string)$req['reason']);
    }
    out(['success' => true, 'id' => $attId, 'file_name' => (string)$f['name']]);
}

// ════════════════ 附件：temp 清單 / 刪除 / 下載 ════════════════
case 'attach_temp_list': {
    $token = trim((string)($_GET['upload_token'] ?? ''));
    if ($token === '') out(['success' => true, 'rows' => []]);
    $st = $db->prepare("SELECT id, file_name, ext, file_size FROM leave_attachment
                        WHERE upload_token = ? AND status = 'temp' AND uploaded_by = ? ORDER BY id");
    $st->execute([$token, $user_id]);
    out(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'attach_delete': {
    need_csrf();
    $attId = (int)($_POST['id'] ?? 0);
    $st = $db->prepare("SELECT la.*, lr.employee_id, lr.status AS req_status FROM leave_attachment la
                        LEFT JOIN leave_request lr ON lr.id = la.leave_request_id WHERE la.id = ? LIMIT 1");
    $st->execute([$attId]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) bad('附件不存在');
    $isOwner = ((int)$att['uploaded_by'] === $user_id) || ($att['employee_id'] !== null && (int)$att['employee_id'] === $user_id);
    if (!$isOwner && !$IS_ADMIN) bad('僅上傳者本人可刪除');
    if ($att['status'] === 'active' && in_array((string)$att['req_status'], ['approved'], true) && !$IS_ADMIN) {
        bad('已核准單據的附件不可刪除（如需更正請洽管理員）');
    }
    // 實體檔即時組路徑刪除；DB 標記 trash（不硬刪，留軌跡）
    $sub = $att['leave_request_id'] ? ('req_' . (int)$att['leave_request_id']) : ('temp_' . $att['upload_token']);
    $dir = eg_leave_attach_dir($db, $sub);
    if ($dir && is_file($dir . DIRECTORY_SEPARATOR . $att['stored_name'])) @unlink($dir . DIRECTORY_SEPARATOR . $att['stored_name']);
    $db->prepare("UPDATE leave_attachment SET status = 'trash' WHERE id = ?")->execute([$attId]);
    out(['success' => true]);
}

case 'attach_download': {
    $attId = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT la.*, lr.employee_id FROM leave_attachment la
                        LEFT JOIN leave_request lr ON lr.id = la.leave_request_id
                        WHERE la.id = ? AND la.status IN ('temp','active') LIMIT 1");
    $st->execute([$attId]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) { http_response_code(404); exit('附件不存在'); }
    // 守門：本人 / 全部檢視 / 部門主管 / 簽核鏈成員（比照 detail）
    $ok = ((int)($att['employee_id'] ?? 0) === $user_id) || ((int)$att['uploaded_by'] === $user_id) || $VIEW_ALL;
    if (!$ok && $att['leave_request_id']) {
        $st = $db->prepare("SELECT 1 FROM leave_approval WHERE leave_request_id = ? AND approver_id = ? LIMIT 1");
        $st->execute([(int)$att['leave_request_id'], $user_id]);
        $ok = (bool)$st->fetchColumn();
        if (!$ok) {
            foreach (eg_leave_pending_for($db, $user_id) as $p) {
                if ((int)$p['leave_request_id'] === (int)$att['leave_request_id']) { $ok = true; break; }
            }
        }
        if (!$ok) {
            $depts = leave_dept_scope($db, $user_id);
            if ($depts) {
                $st = $db->prepare("SELECT 1 FROM user_department_position_map WHERE user_id = ? AND department_id IN ("
                                   . implode(',', array_map('intval', $depts)) . ") LIMIT 1");
                $st->execute([(int)$att['employee_id']]);
                $ok = (bool)$st->fetchColumn();
            }
        }
    }
    if (!$ok) { http_response_code(403); exit('沒有權限'); }
    $sub = $att['leave_request_id'] ? ('req_' . (int)$att['leave_request_id']) : ('temp_' . $att['upload_token']);
    $dir = eg_leave_attach_dir($db, $sub);
    $path = $dir ? $dir . DIRECTORY_SEPARATOR . $att['stored_name'] : '';
    if (!$path || !is_file($path)) { http_response_code(404); exit('檔案不存在（可能 NAS 未連線或路徑設定已變更）'); }
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$att['ext']] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($att['file_name']));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

default:
    bad('無效的操作');
}
