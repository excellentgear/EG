<?php
/**
 * 量測儀器校驗管理 API
 * 權限：tool_calib_lib.php tool_calib_perms()（roles module='tool_calib'；admin⊃edit⊃view），fail-closed
 * 讀：GET；寫：POST。所有寫入用 transaction。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/tool_calib_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    tool_calib_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = tool_calib_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = tool_calib_perms($db, $u);
if (!$perms['canView']) jerr('無量測儀器校驗檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 取單支量具（含類別名稱） */
function tc_get_tool(PDO $db, int $tid): ?array {
    $st = $db->prepare("SELECT t.*, l.QC_Tool AS category_name
                        FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                        WHERE t.Tool_id=?");
    $st->execute([$tid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 依現有紀錄重算某支量具的下次應校驗日（刪除紀錄後修復用） */
function tc_recompute_due(PDO $db, int $tid): void {
    $st = $db->prepare("SELECT next_due, due_date FROM qc_tool_calibration
                        WHERE Tool_id=? ORDER BY calib_date DESC, calib_id DESC LIMIT 1");
    $st->execute([$tid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    // 有紀錄→用最近一次的 next_due；無紀錄→保留原到期(不動)
    if ($r && !empty($r['next_due'])) {
        $db->prepare("UPDATE qc_tool SET calibration_due=? WHERE Tool_id=?")->execute([$r['next_due'], $tid]);
    }
}

switch ($action) {

/* ---------- 基本資訊 ---------- */
case 'meta': {
    $cats = $db->query("SELECT QC_Tool_List_id, QC_Tool FROM qc_tool_list ORDER BY sort_order, QC_Tool_List_id")
               ->fetchAll(PDO::FETCH_ASSOC);
    jout(['perms'=>$perms, 'categories'=>$cats,
          'cur_ym'=>date('Y-m'), 'today'=>date('Y-m-d')]);
}

/* ---------- 儀器清單 + 當月統計 ---------- */
case 'list': {
    $ym = $_GET['ym'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    [$y, $m] = array_map('intval', explode('-', $ym));

    $st = $db->query("SELECT t.Tool_id, t.Tool_No, t.QC_Tool_List_id, t.calibration_due,
                             t.calib_cycle_months, t.calib_managed, t.calib_method,
                             l.QC_Tool AS category_name
                      FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                      ORDER BY t.calib_managed DESC, t.calibration_due IS NULL, t.calibration_due ASC, t.Tool_No ASC");
    $tools = $st->fetchAll(PDO::FETCH_ASSOC);

    // 每支最近一次校驗
    $last = [];
    foreach ($db->query("SELECT c.Tool_id, c.calib_date, c.result, c.method, c.cert_no, c.operator
                         FROM qc_tool_calibration c
                         JOIN (SELECT Tool_id, MAX(calib_date) md FROM qc_tool_calibration GROUP BY Tool_id) x
                              ON x.Tool_id=c.Tool_id AND x.md=c.calib_date")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $last[(int)$r['Tool_id']] = $r;
    }

    $rows = [];
    foreach ($tools as $t) {
        $t['calib_managed'] = (int)$t['calib_managed'];
        $t['status'] = tool_calib_status($t);
        $t['last'] = $last[(int)$t['Tool_id']] ?? null;
        $rows[] = $t;
    }

    $stat = tool_calib_kpi_compute($db, $y, $m, []);
    jout(['rows'=>$rows, 'ym'=>$ym, 'stat'=>$stat, 'perms'=>$perms]);
}

/* ---------- 新增儀器（管理員） ---------- */
case 'create_tool': {
    if (!$perms['canAdmin']) jerr('無新增儀器權限', 403);
    $no  = trim((string)($_POST['tool_no'] ?? ''));
    $cat = trim((string)($_POST['category_id'] ?? ''));
    if ($no === '' || $cat === '') jerr('請填量具編號與類別');
    $st = $db->prepare("SELECT 1 FROM qc_tool WHERE Tool_No=? LIMIT 1");
    $st->execute([$no]);
    if ($st->fetchColumn()) jerr('量具編號已存在：'.$no);
    $cycle = ($_POST['cycle'] ?? '') === '' ? null : max(0, (int)$_POST['cycle']);
    $managed = (int)($_POST['managed'] ?? 0) === 1 ? 1 : 0;
    $method = trim((string)($_POST['method'] ?? '')) ?: null;
    $baseDue = trim((string)($_POST['baseline_due'] ?? '')) ?: null;
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO qc_tool (Tool_No, QC_Tool_List_id, Created_at, calib_cycle_months, calib_managed, calib_method, calibration_due)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$no, $cat, date('Y-m-d H:i:s'), $cycle, $managed, $method, $baseDue]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('新增失敗：'.$e->getMessage(), 500); }
    jout(['tool_id'=>(int)$db->lastInsertId()]);
}

/* ---------- 設定儀器（管理員）：校驗屬性 + 可編輯編號/類別 ---------- */
case 'save_tool': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $tid = (int)($_POST['tool_id'] ?? 0);
    $t = tc_get_tool($db, $tid);
    if (!$t) jerr('找不到量具');
    $cycle = ($_POST['cycle'] ?? '') === '' ? null : max(0, (int)$_POST['cycle']);
    $managed = (int)($_POST['managed'] ?? 0) === 1 ? 1 : 0;
    $method = trim((string)($_POST['method'] ?? '')) ?: null;
    // baseline_due：允許管理員設定/修正下次應校驗日（尚無紀錄或需校正時）
    $setBase = array_key_exists('baseline_due', $_POST);
    $baseDue = $setBase ? (trim((string)$_POST['baseline_due']) ?: null) : $t['calibration_due'];
    // 可編輯基本資料：量具編號 / 類別（有帶才更新）
    $newNo = array_key_exists('tool_no', $_POST) ? trim((string)$_POST['tool_no']) : $t['Tool_No'];
    $newCat = array_key_exists('category_id', $_POST) && $_POST['category_id'] !== '' ? trim((string)$_POST['category_id']) : $t['QC_Tool_List_id'];
    if ($newNo === '') jerr('量具編號不可空白');
    if ($newNo !== $t['Tool_No']) {
        $c = $db->prepare("SELECT 1 FROM qc_tool WHERE Tool_No=? AND Tool_id<>? LIMIT 1");
        $c->execute([$newNo, $tid]);
        if ($c->fetchColumn()) jerr('量具編號已存在：'.$newNo);
    }
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE qc_tool SET Tool_No=?, QC_Tool_List_id=?, calib_cycle_months=?, calib_managed=?, calib_method=?, calibration_due=? WHERE Tool_id=?")
           ->execute([$newNo, $newCat, $cycle, $managed, $method, $baseDue, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout([]);
}

/* ---------- 登錄一次校驗完成（登錄權） ---------- */
case 'record_calib': {
    if (!$perms['canEdit']) jerr('無校驗登錄權限', 403);
    $tid = (int)($_POST['tool_id'] ?? 0);
    $t = tc_get_tool($db, $tid);
    if (!$t) jerr('找不到量具');
    $calibDate = trim((string)($_POST['calib_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calibDate)) jerr('請選擇校驗完成日');
    $result = in_array($_POST['result'] ?? '', ['pass','fail','pass_adjust'], true) ? $_POST['result'] : 'pass';
    $method = trim((string)($_POST['method'] ?? '')) ?: ($t['calib_method'] ?: null);
    $operator = trim((string)($_POST['operator'] ?? '')) ?: null;
    $certNo = trim((string)($_POST['cert_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;

    $dueDate = $t['calibration_due'] ?: null;               // 本次滿足的到期日
    $cycle = $t['calib_cycle_months'] !== null ? (int)$t['calib_cycle_months'] : 0;
    $nextDue = $cycle > 0 ? tool_calib_add_months($calibDate, $cycle) : null;

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO qc_tool_calibration
            (Tool_id, due_date, calib_date, result, method, operator, cert_no, next_due, note, created_by, created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$tid, $dueDate, $calibDate, $result, $method, $operator, $certNo, $nextDue, $note, $uid, $uname]);
        // 前滾主檔到期日；並把預設校驗方式更新為本次方式
        $db->prepare("UPDATE qc_tool SET calibration_due=?, calib_method=COALESCE(?, calib_method) WHERE Tool_id=?")
           ->execute([$nextDue, $method, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('登錄失敗：'.$e->getMessage(), 500); }
    jout(['next_due'=>$nextDue]);
}

/* ---------- 編輯校驗紀錄（登錄權；修正誤登） ---------- */
case 'edit_calib': {
    if (!$perms['canEdit']) jerr('無編輯權限', 403);
    $cid = (int)($_POST['calib_id'] ?? 0);
    $st = $db->prepare("SELECT c.*, t.calib_cycle_months FROM qc_tool_calibration c
                        JOIN qc_tool t ON t.Tool_id=c.Tool_id WHERE c.calib_id=?");
    $st->execute([$cid]);
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) jerr('找不到紀錄');
    $tid = (int)$rec['Tool_id'];
    $calibDate = trim((string)($_POST['calib_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calibDate)) jerr('請選擇校驗完成日');
    $result = in_array($_POST['result'] ?? '', ['pass','fail','pass_adjust'], true) ? $_POST['result'] : 'pass';
    $method = trim((string)($_POST['method'] ?? '')) ?: null;
    $operator = trim((string)($_POST['operator'] ?? '')) ?: null;
    $certNo = trim((string)($_POST['cert_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    // 若改到期日基準（管理員）也允許
    $dueDate = array_key_exists('due_date', $_POST)
        ? (trim((string)$_POST['due_date']) ?: null) : $rec['due_date'];
    $cycle = $rec['calib_cycle_months'] !== null ? (int)$rec['calib_cycle_months'] : 0;
    $nextDue = $cycle > 0 ? tool_calib_add_months($calibDate, $cycle) : null;
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE qc_tool_calibration SET due_date=?, calib_date=?, result=?, method=?, operator=?, cert_no=?, next_due=?, note=? WHERE calib_id=?")
           ->execute([$dueDate, $calibDate, $result, $method, $operator, $certNo, $nextDue, $note, $cid]);
        // 若此為該量具最近一次校驗，前滾主檔到期日
        $latest = $db->prepare("SELECT calib_id FROM qc_tool_calibration WHERE Tool_id=? ORDER BY calib_date DESC, calib_id DESC LIMIT 1");
        $latest->execute([$tid]);
        if ((int)$latest->fetchColumn() === $cid)
            $db->prepare("UPDATE qc_tool SET calibration_due=? WHERE Tool_id=?")->execute([$nextDue, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['next_due'=>$nextDue]);
}

/* ---------- 校驗歷史 ---------- */
case 'history': {
    $tid = (int)($_GET['tool_id'] ?? 0);
    $t = tc_get_tool($db, $tid);
    if (!$t) jerr('找不到量具');
    $st = $db->prepare("SELECT calib_id, due_date, calib_date, result, method, operator, cert_no, next_due, note,
                               created_by_name, created_at
                        FROM qc_tool_calibration WHERE Tool_id=? ORDER BY calib_date DESC, calib_id DESC");
    $st->execute([$tid]);
    jout(['tool'=>['Tool_No'=>$t['Tool_No'],'category_name'=>$t['category_name']],
          'list'=>$st->fetchAll(PDO::FETCH_ASSOC), 'can_delete'=>$perms['canAdmin']]);
}

/* ---------- 刪除校驗紀錄（管理員；修正誤登） ---------- */
case 'delete_calib': {
    if (!$perms['canAdmin']) jerr('無刪除權限', 403);
    $cid = (int)($_POST['calib_id'] ?? 0);
    $st = $db->prepare("SELECT Tool_id, due_date FROM qc_tool_calibration WHERE calib_id=?");
    $st->execute([$cid]);
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) jerr('找不到紀錄');
    $tid = (int)$rec['Tool_id'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM qc_tool_calibration WHERE calib_id=?")->execute([$cid]);
        // 修復到期日：若刪掉的是最近一次，回復為其所滿足的到期日；否則依剩餘紀錄重算
        $chk = $db->prepare("SELECT COUNT(*) FROM qc_tool_calibration WHERE Tool_id=?");
        $chk->execute([$tid]);
        if ((int)$chk->fetchColumn() === 0) {
            if (!empty($rec['due_date']))
                $db->prepare("UPDATE qc_tool SET calibration_due=? WHERE Tool_id=?")->execute([$rec['due_date'], $tid]);
        } else {
            tc_recompute_due($db, $tid);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    jout([]);
}

default:
    jerr('未知動作：'.$action);
}
