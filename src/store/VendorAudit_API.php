<?php
/**
 * 供應商稽核管理 API
 * 權限：vendor_audit_lib.php vendor_audit_perms()（roles module='vendor_audit'；admin⊃edit⊃view），fail-closed
 * 讀：GET；寫：POST，transaction。廠商主檔沿用 maker_list（本頁只維護稽核屬性，不新增/刪除廠商）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/vendor_audit_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    vendor_audit_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = vendor_audit_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = vendor_audit_perms($db, $u);
if (!$perms['canView']) jerr('無供應商稽核檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function va_get_maker(PDO $db, string $mid): ?array {
    $st = $db->prepare("SELECT maker_id_no, maker_id, audit_cycle_months, audit_managed, audit_next_due
                        FROM maker_list WHERE maker_id_no=?");
    $st->execute([$mid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function va_recompute_due(PDO $db, string $mid): void {
    $st = $db->prepare("SELECT next_due FROM vendor_audit WHERE maker_id_no=? ORDER BY audit_date DESC, audit_id DESC LIMIT 1");
    $st->execute([$mid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && !empty($r['next_due']))
        $db->prepare("UPDATE maker_list SET audit_next_due=? WHERE maker_id_no=?")->execute([$r['next_due'], $mid]);
}

switch ($action) {

case 'meta': {
    jout(['perms'=>$perms, 'cur_year'=>(int)date('Y'),
          'cur_half'=>((int)date('n') <= 6 ? 1 : 2), 'today'=>date('Y-m-d')]);
}

case 'list': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $half = (int)($_GET['half'] ?? ((int)date('n') <= 6 ? 1 : 2));
    if ($half !== 1 && $half !== 2) $half = 1;

    $makers = $db->query("SELECT maker_id_no, maker_id, audit_cycle_months, audit_managed, audit_next_due, is_qualified
                          FROM maker_list
                          ORDER BY audit_managed DESC, audit_next_due IS NULL, audit_next_due ASC, maker_id ASC")
                 ->fetchAll(PDO::FETCH_ASSOC);

    $last = [];
    foreach ($db->query("SELECT a.maker_id_no, a.audit_date, a.result, a.score, a.auditor
                         FROM vendor_audit a
                         JOIN (SELECT maker_id_no, MAX(audit_date) md FROM vendor_audit GROUP BY maker_id_no) x
                              ON x.maker_id_no=a.maker_id_no AND x.md=a.audit_date")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $last[$r['maker_id_no']] = $r;
    }

    $rows = [];
    foreach ($makers as $mk) {
        $mk['audit_managed'] = (int)$mk['audit_managed'];
        $mk['status'] = vendor_audit_status($mk);
        $mk['last'] = $last[$mk['maker_id_no']] ?? null;
        $rows[] = $mk;
    }

    $stat = vendor_audit_kpi_compute($db, $year, $half === 1 ? 6 : 12, []);
    jout(['rows'=>$rows, 'year'=>$year, 'half'=>$half, 'stat'=>$stat, 'perms'=>$perms]);
}

/* 設定稽核屬性（管理員） */
case 'save_vendor': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $mid = trim((string)($_POST['maker_id_no'] ?? ''));
    $mk = va_get_maker($db, $mid);
    if (!$mk) jerr('找不到廠商');
    $cycle = ($_POST['cycle'] ?? '') === '' ? null : max(0, (int)$_POST['cycle']);
    $managed = (int)($_POST['managed'] ?? 0) === 1 ? 1 : 0;
    $setBase = array_key_exists('baseline_due', $_POST);
    $baseDue = $setBase ? (trim((string)$_POST['baseline_due']) ?: null) : $mk['audit_next_due'];
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE maker_list SET audit_cycle_months=?, audit_managed=?, audit_next_due=? WHERE maker_id_no=?")
           ->execute([$cycle, $managed, $baseDue, $mid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout([]);
}

/* 登錄一次稽核完成（登錄權） */
case 'record_audit': {
    if (!$perms['canEdit']) jerr('無稽核登錄權限', 403);
    $mid = trim((string)($_POST['maker_id_no'] ?? ''));
    $mk = va_get_maker($db, $mid);
    if (!$mk) jerr('找不到廠商');
    $auditDate = trim((string)($_POST['audit_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDate)) jerr('請選擇稽核日');
    $result = in_array($_POST['result'] ?? '', ['pass','conditional','fail'], true) ? $_POST['result'] : 'pass';
    $score = ($_POST['score'] ?? '') === '' ? null : (int)$_POST['score'];
    $auditor = trim((string)($_POST['auditor'] ?? '')) ?: null;
    $reportNo = trim((string)($_POST['report_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;

    $dueDate = $mk['audit_next_due'] ?: null;
    $cycle = $mk['audit_cycle_months'] !== null ? (int)$mk['audit_cycle_months'] : 0;
    $nextDue = $cycle > 0 ? vendor_audit_add_months($auditDate, $cycle) : null;

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO vendor_audit
            (maker_id_no, due_date, audit_date, result, score, auditor, report_no, next_due, note, created_by, created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$mid, $dueDate, $auditDate, $result, $score, $auditor, $reportNo, $nextDue, $note, $uid, $uname]);
        $db->prepare("UPDATE maker_list SET audit_next_due=? WHERE maker_id_no=?")->execute([$nextDue, $mid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('登錄失敗：'.$e->getMessage(), 500); }
    jout(['next_due'=>$nextDue]);
}

case 'history': {
    $mid = trim((string)($_GET['maker_id_no'] ?? ''));
    $mk = va_get_maker($db, $mid);
    if (!$mk) jerr('找不到廠商');
    $st = $db->prepare("SELECT audit_id, due_date, audit_date, result, score, auditor, report_no, next_due, note,
                               created_by_name, created_at
                        FROM vendor_audit WHERE maker_id_no=? ORDER BY audit_date DESC, audit_id DESC");
    $st->execute([$mid]);
    jout(['maker'=>['maker_id_no'=>$mk['maker_id_no'],'maker_id'=>$mk['maker_id']],
          'list'=>$st->fetchAll(PDO::FETCH_ASSOC), 'can_delete'=>$perms['canAdmin']]);
}

case 'delete_audit': {
    if (!$perms['canAdmin']) jerr('無刪除權限', 403);
    $aid = (int)($_POST['audit_id'] ?? 0);
    $st = $db->prepare("SELECT maker_id_no, due_date FROM vendor_audit WHERE audit_id=?");
    $st->execute([$aid]);
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) jerr('找不到紀錄');
    $mid = $rec['maker_id_no'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM vendor_audit WHERE audit_id=?")->execute([$aid]);
        $chk = $db->prepare("SELECT COUNT(*) FROM vendor_audit WHERE maker_id_no=?");
        $chk->execute([$mid]);
        if ((int)$chk->fetchColumn() === 0) {
            if (!empty($rec['due_date']))
                $db->prepare("UPDATE maker_list SET audit_next_due=? WHERE maker_id_no=?")->execute([$rec['due_date'], $mid]);
        } else {
            va_recompute_due($db, $mid);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    jout([]);
}

default:
    jerr('未知動作：'.$action);
}
