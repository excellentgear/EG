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
                                unit_type, require_attachment, attach_min_days, allow_attach_later
                         FROM leave_type ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    // 我的候選代理人（唯讀，來自 delegate_lib）
    $cands = eg_person_delegate_candidates($db, $user_id);
    // 特休摘要
    $annual = eg_leave_annual_summary($db, $user_id);
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
         'settings' => [
             'backdate_limit_days' => (int)$cfg['leave_backdate_limit_days'],
             'hours_per_day' => (float)$cfg['leave_hours_per_day'],
             'halfday_hours' => (float)$cfg['leave_halfday_hours'],
             'attach_ready' => trim((string)$cfg['leave_attach_base']) !== '',
         ]]);
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
    out($ret);
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
        'agent_user_id'  => (int)($_POST['agent_user_id'] ?? 0),
        'upload_token'   => $token,
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
         'need_attach_later' => $r['need_attach_later'] ?? false]);
}

// ════════════════ 簽核 ════════════════
case 'sign': {
    need_csrf();
    $reqId = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['decision'] ?? '');
    $act = $act === 'approve' ? 'approved' : ($act === 'reject' ? 'rejected' : '');
    if (!$reqId || !$act) bad('缺少參數');
    if ($act === 'rejected' && trim((string)($_POST['remark'] ?? '')) === '') bad('退回必須填寫意見');
    $r = eg_leave_sign($db, $reqId, $user_id, $act, trim((string)($_POST['remark'] ?? '')));
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
    $where = []; $args = [];

    if ($scope === 'all') {
        if (!$VIEW_ALL) bad('您沒有檢視全部請假單的權限');
    } elseif ($scope === 'dept') {
        $depts = leave_dept_scope($db, $user_id);
        if (!$depts && !$VIEW_ALL) bad('您不是主管，無法檢視部門請假單');
        if ($depts) {
            $in = implode(',', array_map('intval', $depts));
            $where[] = "lr.employee_id IN (SELECT DISTINCT m.user_id FROM user_department_position_map m WHERE m.department_id IN ($in))";
        }
    } else {
        $where[] = 'lr.employee_id = ?'; $args[] = $user_id;
    }
    if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'canceled'], true)) {
        $where[] = 'lr.status = ?'; $args[] = $status;
    }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

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
         'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

// ════════════════ 單一請假單詳情（含簽核歷程） ════════════════
case 'detail': {
    $reqId = (int)($_GET['id'] ?? 0);
    if (!$reqId) bad('缺少參數');
    $st = $db->prepare(
        "SELECT lr.*, lt.leave_name, lt.unit_type, lt.require_attachment, u.user_cname AS applicant_name,
                ag.user_cname AS agent_name
         FROM leave_request lr
         JOIN leave_type lt ON lt.id = lr.leave_type_id
         JOIN user u ON u.id = lr.employee_id
         LEFT JOIN user ag ON ag.id = lr.agent_user_id
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

    out(['success' => true, 'request' => $req, 'approvals' => $approvals,
         'sign_records' => $signs, 'attachments' => $attaches,
         'can_cancel' => ((int)$req['employee_id'] === $user_id || $IS_ADMIN)
                          && in_array($req['status'], ['pending', 'approved'], true)]);
}

// ════════════════ 特休額度 ════════════════
case 'annual_summary': {
    $year = (int)($_GET['year'] ?? date('Y'));
    out(['success' => true, 'annual' => eg_leave_annual_summary($db, $user_id, $year)]);
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

    if ($reqId > 0) {
        // 補件：限本人（或管理員），單須存在且需要證明
        $st = $db->prepare("SELECT * FROM leave_request WHERE id = ? LIMIT 1");
        $st->execute([$reqId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) bad('請假單不存在');
        if ((int)$req['employee_id'] !== $user_id && !$IS_ADMIN) bad('僅申請人本人可補件');
        if (in_array($req['status'], ['rejected', 'canceled'], true)) bad('此單已' . ($req['status'] === 'rejected' ? '退回' : '取消') . '，不可補件');
        $sub = 'req_' . $reqId;
        $statusVal = 'active';
    } else {
        // 新增單據中：temp 暫存（鐵律5：新增當下就能上傳）
        if ($token === '' || !preg_match('/^[a-f0-9]{16,64}$/', $token)) bad('缺少有效的上傳批次識別');
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
