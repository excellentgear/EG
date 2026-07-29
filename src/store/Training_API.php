<?php
/**
 * 教育訓練管理 API
 * 權限：training_lib.php training_perms()（roles module='training'；admin⊃edit⊃view），fail-closed
 * 讀：GET；寫：POST，transaction。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/training_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    training_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = training_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = training_perms($db, $u);
if (!$perms['canView']) jerr('無教育訓練檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function tr_dept_map(PDO $db): array {
    $m = [];
    foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $m[(int)$d['id']] = $d['name'];
    return $m;
}

switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $years = $db->query("SELECT DISTINCT year FROM training_session ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $cy = (int)date('Y');
    $years = array_values(array_unique(array_merge([$cy], array_map('intval', $years))));
    rsort($years);
    jout(['perms'=>$perms, 'departments'=>$depts, 'years'=>$years,
          'cur_year'=>$cy, 'cur_month'=>(int)date('n')]);
}

case 'list': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $deptMap = tr_dept_map($db);
    $st = $db->prepare("SELECT * FROM training_session WHERE year=? ORDER BY plan_month, session_id");
    $st->execute([$year]);
    $rows = [];
    $summary = [];
    for ($m = 1; $m <= 12; $m++) $summary[$m] = ['den'=>0, 'num'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['dept_name'] = $r['dept_id'] !== null ? ($deptMap[(int)$r['dept_id']] ?? '') : '全公司';
        $rows[] = $r;
        $m = (int)$r['plan_month'];
        if ($r['status'] !== 'cancelled') $summary[$m]['den']++;
        if ($r['status'] === 'done') $summary[$m]['num']++;
    }
    // 年度合計達成率
    $yd = 0; $yn = 0;
    foreach ($summary as $s) { $yd += $s['den']; $yn += $s['num']; }
    jout(['rows'=>$rows, 'year'=>$year, 'summary'=>$summary,
          'year_rate'=>$yd > 0 ? round($yn / $yd * 100, 1) : null, 'year_den'=>$yd, 'year_num'=>$yn,
          'perms'=>$perms]);
}

/* 新增/修改場次（登錄權；計畫與完成登錄合一） */
case 'save_session': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['plan_month'] ?? 0);
    $course = trim((string)($_POST['course_name'] ?? ''));
    if ($year < 2000 || $month < 1 || $month > 12) jerr('請選擇有效年月');
    if ($course === '') jerr('請填課程名稱');
    $deptId = ($_POST['dept_id'] ?? '') === '' ? null : (int)$_POST['dept_id'];
    $trainer = trim((string)($_POST['trainer'] ?? '')) ?: null;
    $hours = ($_POST['hours'] ?? '') === '' ? null : (float)$_POST['hours'];
    $target = ($_POST['target_headcount'] ?? '') === '' ? null : (int)$_POST['target_headcount'];
    $actual = ($_POST['actual_headcount'] ?? '') === '' ? null : (int)$_POST['actual_headcount'];
    $status = in_array($_POST['status'] ?? '', ['planned','done','cancelled'], true) ? $_POST['status'] : 'planned';
    $doneDate = trim((string)($_POST['done_date'] ?? '')) ?: null;
    if ($status === 'done' && !$doneDate) $doneDate = date('Y-m-d');
    if ($status !== 'done') $doneDate = null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    try {
        $db->beginTransaction();
        if ($sid > 0) {
            $db->prepare("UPDATE training_session SET year=?, plan_month=?, dept_id=?, course_name=?, trainer=?,
                          hours=?, target_headcount=?, actual_headcount=?, status=?, done_date=?, note=? WHERE session_id=?")
               ->execute([$year,$month,$deptId,$course,$trainer,$hours,$target,$actual,$status,$doneDate,$note,$sid]);
        } else {
            $db->prepare("INSERT INTO training_session
                (year,plan_month,dept_id,course_name,trainer,hours,target_headcount,actual_headcount,status,done_date,note,created_by,created_by_name)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$year,$month,$deptId,$course,$trainer,$hours,$target,$actual,$status,$doneDate,$note,$uid,$uname]);
            $sid = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['session_id'=>$sid]);
}

/* 刪除場次（管理員） */
case 'delete_session': {
    if (!$perms['canAdmin']) jerr('無刪除權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT 1 FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    if (!$st->fetchColumn()) jerr('找不到場次');
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM training_session WHERE session_id=?")->execute([$sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    jout([]);
}

default:
    jerr('未知動作：'.$action);
}
