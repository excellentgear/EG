<?php
/**
 * 機台設備一覽表 API
 * 權限：equip_list_lib.php equip_list_perms($db,'equip_machine',$u)（roles module='equip_machine'；admin⊃edit⊃view），fail-closed
 * 主檔沿用既有 machine_list（與 views/pm/kpi_main.php「機台資產設定」共用同一張表，兩邊即時同步；
 * 本頁只多加 manufacturer 欄位＋保養人歷程＋履歴表＋年度整份送簽，不動 kpi_main.php 既有的折舊設定）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/equip_list_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    equip_list_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = equip_list_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = equip_list_perms($db, 'equip_machine', $u);
if (!$perms['canView']) jerr('無機台設備一覽表檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
const EQ = 'machine'; // equip_type 固定

function eml_company_name(PDO $db): string {
    try { return eg_company_full_name($db); } catch (Throwable $e) { return ''; }
}

switch ($action) {

case 'meta': {
    $types = $db->query("SELECT process_type_id, process_type FROM process_type WHERE COALESCE(is_active,1)=1 ORDER BY sort_order, process_type")->fetchAll(PDO::FETCH_ASSOC);
    $signSet = equip_list_plan_sign_setting($db, EQ);
    $chain = equip_list_plan_approver_chain($db, EQ);
    jout([
        'perms' => $perms,
        'company_name' => eml_company_name($db),
        'today' => date('Y-m-d'),
        'cur_year' => (int)date('Y'),
        'machine_types' => $types,
        'sign_setting' => $signSet,
        'approver_chain' => $chain,
        'approver_methods' => EQUIP_LIST_APPROVER_METHODS,
        'list_as_doc' => equip_list_bound_asdoc($db, EQ, 'list'),
        'service_as_doc' => equip_list_bound_asdoc($db, EQ, 'service'),
        'as_doc_list' => eg_asdoc_list($db),
    ]);
}

case 'list': {
    $kw = trim((string)($_GET['keyword'] ?? ''));
    $typeId = (int)($_GET['machine_type_id'] ?? 0);
    $showDisabled = !empty($_GET['show_disabled']);
    $sql = "SELECT ml.machine_id, ml.machine, ml.machine_type_id, ml.machine_model, ml.asset_no, ml.field_no,
                   ml.spec, ml.note, ml.manufacturer, ml.state, ml.disabled_date, ml.position,
                   pt.process_type AS machine_type
            FROM machine_list ml
            LEFT JOIN process_type pt ON pt.process_type_id = ml.machine_type_id
            WHERE 1=1";
    $params = [];
    if (!$showDisabled) $sql .= " AND (ml.state IS NULL OR ml.state<>1)";
    if ($typeId) { $sql .= " AND ml.machine_type_id=?"; $params[] = $typeId; }
    if ($kw !== '') {
        $sql .= " AND (ml.machine LIKE ? OR ml.asset_no LIKE ? OR ml.field_no LIKE ? OR ml.machine_model LIKE ? OR ml.manufacturer LIKE ?)";
        for ($i=0;$i<5;$i++) $params[] = '%'.$kw.'%';
    }
    $sql .= " ORDER BY pt.process_type, ml.machine";
    $st = $db->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $assignMap = equip_list_resigned_map($db, EQ, array_column($rows, 'machine_id'));
    foreach ($rows as &$r) {
        $r['machine_id'] = (int)$r['machine_id'];
        $r['assignee'] = $assignMap[$r['machine_id']] ?? null;
    }
    jout(['rows' => $rows]);
}

case 'get': {
    $mid = (int)($_GET['machine_id'] ?? 0);
    if (!$mid) jerr('缺少機台');
    $st = $db->prepare("SELECT ml.*, pt.process_type AS machine_type FROM machine_list ml
                        LEFT JOIN process_type pt ON pt.process_type_id=ml.machine_type_id WHERE ml.machine_id=?");
    $st->execute([$mid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jerr('找不到機台', 404);
    jout(['row' => $row, 'assignee' => equip_list_current_assignee($db, EQ, $mid)]);
}

case 'save': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $mid = (int)($_POST['machine_id'] ?? 0);
    $name = trim((string)($_POST['machine'] ?? ''));
    $typeId = (int)($_POST['machine_type_id'] ?? 0);
    if ($name === '') jerr('機台名稱不可為空');
    if (!$typeId) jerr('請選擇機台類型');
    $model = trim((string)($_POST['machine_model'] ?? ''));
    $assetNo = trim((string)($_POST['asset_no'] ?? ''));
    $fieldNo = trim((string)($_POST['field_no'] ?? ''));
    $spec = trim((string)($_POST['spec'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $manufacturer = trim((string)($_POST['manufacturer'] ?? ''));
    $position = trim((string)($_POST['position'] ?? ''));
    $needSetup = (int)($_POST['need_setup'] ?? 0);
    $disabled = !empty($_POST['disabled']);
    $state = $disabled ? 1 : null;
    $disabledDate = $disabled ? (trim((string)($_POST['disabled_date'] ?? '')) ?: date('Y-m-d')) : null;
    if ($mid) {
        $db->prepare("UPDATE machine_list SET machine=?, machine_type_id=?, need_setup=?, position=?, machine_model=?, asset_no=?, field_no=?, spec=?, note=?, manufacturer=?, state=?, disabled_date=? WHERE machine_id=?")
           ->execute([$name,$typeId,$needSetup,$position,$model,$assetNo,$fieldNo,$spec,$note,$manufacturer,$state,$disabledDate,$mid]);
    } else {
        $db->prepare("INSERT INTO machine_list (machine, machine_type_id, need_setup, position, machine_model, asset_no, field_no, spec, note, manufacturer, state, disabled_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$name,$typeId,$needSetup,$position,$model,$assetNo,$fieldNo,$spec,$note,$manufacturer,$state,$disabledDate]);
        $mid = (int)$db->lastInsertId();
    }
    jout(['machine_id' => $mid]);
}

case 'delete': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可停用機台', 403);
    $mid = (int)($_POST['machine_id'] ?? 0);
    if (!$mid) jerr('缺少機台');
    $db->prepare("UPDATE machine_list SET state=1, disabled_date=COALESCE(disabled_date, CURDATE()) WHERE machine_id=?")->execute([$mid]);
    jout([]);
}

/* ---- 保養人指派歷程 ---- */
case 'assignee_candidates': {
    $kw = trim((string)($_GET['keyword'] ?? ''));
    $rows = eg_people_list($db, ['keyword' => $kw]);
    jout(['rows' => $rows]);
}
case 'assignee_history': {
    $mid = (int)($_GET['machine_id'] ?? 0);
    if (!$mid) jerr('缺少機台');
    jout(['rows' => equip_list_assignee_history($db, EQ, $mid), 'current' => equip_list_current_assignee($db, EQ, $mid)]);
}
case 'assignee_assign': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $mid = (int)($_POST['machine_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    $startDate = trim((string)($_POST['start_date'] ?? '')) ?: date('Y-m-d');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!$mid || !$userId) jerr('請選擇機台與人員');
    try {
        $cur = equip_list_assign_new($db, EQ, $mid, $userId, $startDate, $note ?: null, $uid, $uname);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['current' => $cur]);
}
case 'assignee_save': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可校正歷史紀錄', 403);
    $row = $_POST;
    $row['equip_type'] = EQ;
    try { $saved = equip_list_history_save($db, $row, $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['row' => $saved]);
}
case 'assignee_delete': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可刪除歷史紀錄', 403);
    $histId = (int)($_POST['hist_id'] ?? 0);
    if (!$histId) jerr('缺少紀錄');
    equip_list_history_delete($db, $histId);
    jout([]);
}

/* ---- 履歴表（故障維修紀錄） ---- */
case 'service_list': {
    $mid = (int)($_GET['machine_id'] ?? 0);
    if (!$mid) jerr('缺少機台');
    jout(['rows' => equip_service_log_list($db, EQ, $mid)]);
}
case 'service_save': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $row = $_POST; $row['equip_type'] = EQ;
    try { $saved = equip_service_log_save($db, $row, $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['row' => $saved]);
}
case 'service_delete': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可刪除履歴紀錄', 403);
    $logId = (int)($_POST['log_id'] ?? 0);
    if (!$logId) jerr('缺少紀錄');
    equip_service_log_delete($db, $logId);
    jout([]);
}
case 'service_approve': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $logId = (int)($_POST['log_id'] ?? 0);
    $approvedDate = trim((string)($_POST['approved_date'] ?? '')) ?: date('Y-m-d');
    $isDeputy = !empty($_POST['is_deputy']);
    if (!$logId) jerr('缺少紀錄');
    equip_service_log_approve($db, $logId, $uid, $uname, $approvedDate, $isDeputy);
    jout([]);
}
case 'service_print_list': {
    // 履歴表批次列印用：依目前篩選出的機台清單各自帶出履歴表資料
    $ids = array_filter(array_map('intval', explode(',', (string)($_GET['machine_ids'] ?? ''))));
    if (!$ids) jerr('缺少機台清單');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT machine_id, machine, asset_no, field_no FROM machine_list WHERE machine_id IN ($in)");
    $st->execute($ids);
    jout(['machines' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---- 年度整份送簽 ---- */
case 'plan_data': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $signSet = equip_list_plan_sign_setting($db, EQ);
    $lock = equip_list_plan_lock_get($db, EQ, $year);
    $decidePool = [];
    $canDecide = false;
    if ($lock && $lock['status'] === 'pending') {
        $submittedBy = (int)($lock['submitted_by'] ?? 0);
        $decidePool = equip_list_plan_approver_pool($db, EQ, $submittedBy);
        if ($uid === $submittedBy) {
            $canDecide = !$decidePool && $perms['canAdmin'];
        } else {
            $canDecide = $perms['canAdmin'] || in_array($uid, array_column($decidePool, 'id'), true);
        }
    }
    $bizDate = $lock['submit_date'] ?? null;
    jout([
        'year' => $year, 'lock' => $lock, 'sign_setting' => $signSet,
        'can_decide' => $canDecide,
        'list_as_doc' => equip_list_bound_asdoc($db, EQ, 'list', $bizDate),
        'company_name' => eml_company_name($db),
    ]);
}
case 'plan_submit': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000) jerr('年度不正確');
    $submitDate = trim((string)($_POST['submit_date'] ?? '')) ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $submitDate)) jerr('日期格式不正確');
    $lock = equip_list_plan_lock_get($db, EQ, $year);
    if ($lock && in_array($lock['status'], ['pending','approved'], true)) jerr('此年度清單已送出，請重新整理確認狀態');
    $snapshotRaw = $_POST['snapshot'] ?? '[]';
    $snapshot = json_decode((string)$snapshotRaw, true);
    if (!is_array($snapshot)) $snapshot = [];
    $need = equip_list_plan_sign_setting($db, EQ)['need'];
    $pool = [];
    if ($need) {
        $pool = equip_list_plan_approver_pool($db, EQ, $uid);
        if (!$pool) jerr('尚未設定合格的核准人員，請先至「組織角色綁定設定」指定「機台設備一覽表年度核准」');
    }
    $lock = equip_list_plan_submit($db, EQ, $year, $submitDate, $snapshot, $uid, $uname);
    if ($need && $pool) equip_list_notify_sign($db, EQ, $year, array_column($pool, 'id'), $uid, $uname);
    jout(['lock' => $lock]);
}
case 'plan_decide': {
    $year = (int)($_POST['year'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $noteIn = trim((string)($_POST['note'] ?? ''));
    $approvedDate = trim((string)($_POST['approved_date'] ?? '')) ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $approvedDate)) jerr('核准日期格式不正確');
    if (!in_array($decision, ['approved','rejected'], true)) jerr('無效的決定');
    if ($decision === 'rejected' && $noteIn === '') jerr('退回必須填寫原因');
    $lock = equip_list_plan_lock_get($db, EQ, $year);
    if (!$lock || $lock['status'] !== 'pending') jerr('此年度清單目前無待核准紀錄');
    $submittedBy = (int)($lock['submitted_by'] ?? 0);
    $pool = equip_list_plan_approver_pool($db, EQ, $submittedBy);
    $isSubmitter = ($uid === $submittedBy);
    $inPool = in_array($uid, array_column($pool, 'id'), true);
    if (!$perms['canAdmin']) {
        if ($isSubmitter) { if ($pool) jerr('您是送出人，請由核准人員決行', 403); }
        elseif (!$inPool) jerr('您不是本清單的核准人員', 403);
    }
    if ($decision === 'approved') {
        $db->prepare("UPDATE equip_list_plan_lock SET status='approved', approved_by_name=?, approved_at=NOW(), approved_date=? WHERE equip_type=? AND year=?")
           ->execute([$uname, $approvedDate, EQ, $year]);
    } else {
        $db->prepare("UPDATE equip_list_plan_lock SET status='rejected' WHERE equip_type=? AND year=?")->execute([EQ, $year]);
    }
    equip_list_notify_sign_result($db, EQ, $year, $lock['submitted_by'] ? (int)$lock['submitted_by'] : null, $uname, $decision, $noteIn ?: null);
    jout([]);
}

/* ---- 送簽設定（管理員） ---- */
case 'sign_setting_save': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可設定', 403);
    equip_list_plan_sign_save_setting($db, EQ, !empty($_POST['need']) ? 1 : 0);
    if (isset($_POST['chain'])) {
        $chain = json_decode((string)$_POST['chain'], true);
        if (is_array($chain)) equip_list_plan_approver_chain_save($db, EQ, $chain);
    }
    jout([]);
}

/* ---- AS 文件綁定 ---- */
case 'asdoc_save': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可設定AS文件綁定', 403);
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(EQUIP_ASDOC_MODULES[EQ][$kind])) jerr('無效的文件類型');
    $docId = (int)($_POST['doc_id'] ?? 0);
    eg_asdoc_save($db, EQUIP_ASDOC_MODULES[EQ][$kind], $docId, $uname);
    jout([]);
}

default: jerr('未知的操作：'.$action);
}
