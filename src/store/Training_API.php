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
function tr_locations(PDO $db): array {
    return $db->query("SELECT loc_id, name FROM training_location WHERE is_active=1 ORDER BY sort_order, loc_id")
              ->fetchAll(PDO::FETCH_ASSOC);
}
/* HH:MM 合法性（0-23 / 0-59，擋 25:00 這種） */
function tr_valid_time(?string $t): bool {
    if ($t === null || $t === '') return true;
    if (!preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $t, $m)) return false;
    return (int)$m[1] <= 23 && (int)$m[2] <= 59;
}
function tr_norm_time(?string $t): ?string {
    $t = trim((string)$t);
    if ($t === '') return null;
    if (!preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $t, $m)) return $t;   // 交給 tr_valid_time 擋
    return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
}

switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $years = $db->query("SELECT DISTINCT year FROM training_session ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $cy = (int)date('Y');
    $years = array_values(array_unique(array_merge([$cy], array_map('intval', $years))));
    rsort($years);
    $cats = [];
    try { $cats = $db->query("SELECT id, category_name, color FROM event_category ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {}
    jout(['perms'=>$perms, 'departments'=>$depts, 'years'=>$years, 'locations'=>tr_locations($db),
          'shifts'=>training_shifts($db), 'settings'=>training_settings($db), 'event_categories'=>$cats,
          'cat_internal_eff'=>training_category_id($db, 'internal'), 'cat_external_eff'=>training_category_id($db, 'external'),
          'cur_year'=>$cy, 'cur_month'=>(int)date('n'), 'today'=>date('Y-m-d')]);
}

/* 模組設定（限訓練管理員）：預設班別、行事曆類別綁定（存 id 不存名稱） */
case 'save_settings': {
    if (!$perms['canAdmin']) jerr('無管理權限（設定限訓練管理員）', 403);
    $map = ['default_shift_id'=>'training_default_shift_id',
            'cat_internal'=>'training_cat_internal', 'cat_external'=>'training_cat_external'];
    // 休息時段（HH:MM 字串）：兩欄都空＝不扣休息；只填一欄視為未設定
    $bs = tr_norm_time($_POST['break_start'] ?? null);
    $be = tr_norm_time($_POST['break_end'] ?? null);
    if (array_key_exists('break_start', $_POST) || array_key_exists('break_end', $_POST)) {
        if (!tr_valid_time($bs)) jerr("休息開始時間不是合法時刻（{$bs}）");
        if (!tr_valid_time($be)) jerr("休息結束時間不是合法時刻（{$be}）");
        if (($bs === null) !== ($be === null)) jerr('休息時段請「起、迄」兩個都填，或兩個都留空（＝不扣休息）');
        if ($bs !== null && $be <= $bs) jerr("休息結束（{$be}）不可早於或等於休息開始（{$bs}）");
    }
    try {
        $db->beginTransaction();
        foreach ($map as $post => $key) {
            if (!array_key_exists($post, $_POST)) continue;
            $v = trim((string)$_POST[$post]);
            training_setting_save($db, $key, $v === '' ? null : (int)$v, $uid, $uname);
        }
        if (array_key_exists('break_start', $_POST) || array_key_exists('break_end', $_POST)) {
            training_setting_save($db, 'training_break_start', $bs === null ? '' : $bs, $uid, $uname);
            training_setting_save($db, 'training_break_end',   $be === null ? '' : $be, $uid, $uname);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('設定儲存失敗：'.$e->getMessage(), 500); }
    jout(['settings'=>training_settings($db),
          'cat_internal_eff'=>training_category_id($db, 'internal'), 'cat_external_eff'=>training_category_id($db, 'external')]);
}

/* ---------- 上課地點主檔（設定後可下拉選擇） ---------- */
case 'save_location': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請輸入地點名稱');
    if (mb_strlen($name) > 100) jerr('地點名稱過長（上限 100 字）');
    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT loc_id, is_active FROM training_location WHERE name=?");
        $st->execute([$name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ((int)$row['is_active'] !== 1)
                $db->prepare("UPDATE training_location SET is_active=1 WHERE loc_id=?")->execute([$row['loc_id']]);
        } else {
            $db->prepare("INSERT INTO training_location (name, sort_order, created_by)
                          VALUES (?, (SELECT COALESCE(MAX(t.sort_order),0)+1 FROM training_location t), ?)")
               ->execute([$name, $uid]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('地點儲存失敗：'.$e->getMessage(), 500); }
    jout(['locations'=>tr_locations($db), 'name'=>$name]);
}

case 'del_location': {
    if (!$perms['canAdmin']) jerr('無管理權限（停用地點限訓練管理員）', 403);
    $locId = (int)($_POST['loc_id'] ?? 0);
    try {
        $db->prepare("UPDATE training_location SET is_active=0 WHERE loc_id=?")->execute([$locId]);
    } catch (Throwable $e) { jerr('停用失敗：'.$e->getMessage(), 500); }
    jout(['locations'=>tr_locations($db)]);
}

case 'list': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $deptMap = tr_dept_map($db);
    $st = $db->prepare("SELECT * FROM training_session WHERE year=? ORDER BY plan_month, session_id");
    $st->execute([$year]);
    // 各場次上課日期（多天課程）
    $dq = $db->prepare("SELECT d.session_id, d.day_no, d.day_date, d.start_time, d.end_time, d.hours,
                               d.break_minutes, d.evenement_id
                        FROM training_session_day d JOIN training_session s ON s.session_id=d.session_id
                        WHERE s.year=? ORDER BY d.session_id, d.day_no, d.day_date");
    $dq->execute([$year]);
    $dayMap = [];
    foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $d) $dayMap[(int)$d['session_id']][] = $d;
    $rows = [];
    $summary = [];
    for ($m = 1; $m <= 12; $m++) $summary[$m] = ['den'=>0, 'num'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['dept_name'] = $r['dept_id'] !== null ? ($deptMap[(int)$r['dept_id']] ?? '') : '全公司';
        $r['days'] = $dayMap[(int)$r['session_id']] ?? [];
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

/* 新增/修改「訓練計畫」（登錄權）
   只寫計畫欄位：年月/部門/課程/類型/講師或開課單位/時數/備註。
   實行欄位（狀態、實際開課日、時段、地點、名單）一律由 save_execution 維護，此處不動。 */
case 'save_session': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['plan_month'] ?? 0);
    $course = trim((string)($_POST['course_name'] ?? ''));
    if ($year < 2000 || $month < 1 || $month > 12) jerr('請選擇有效年月');
    if ($course === '') jerr('請填課程名稱');
    $deptId = ($_POST['dept_id'] ?? '') === '' ? null : (int)$_POST['dept_id'];
    $trainType = ($_POST['train_type'] ?? '') === 'external' ? 'external' : 'internal';
    $trainer = trim((string)($_POST['trainer'] ?? '')) ?: null;      // 內訓講師姓名
    $trainerId = ($_POST['trainer_id'] ?? '') === '' ? null : (int)$_POST['trainer_id'];
    $orgUnit = trim((string)($_POST['org_unit'] ?? '')) ?: null;     // 外訓開課單位
    if ($trainType === 'external') { $trainer = null; $trainerId = null; }
    else { $orgUnit = null; }
    if ($trainType === 'external' && $orgUnit === null) jerr('外訓請填開課單位');
    $hours = ($_POST['hours'] ?? '') === '' ? null : (float)$_POST['hours'];
    $planDays = ($_POST['plan_days'] ?? '') === '' ? null : max(1, (int)$_POST['plan_days']);
    if ($planDays !== null && $planDays > 60) jerr('計畫天數請勿超過 60 天');
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    try {
        $db->beginTransaction();
        if ($sid > 0) {
            $db->prepare("UPDATE training_session SET year=?, plan_month=?, dept_id=?, course_name=?, train_type=?, trainer=?, trainer_id=?, org_unit=?,
                          hours=?, plan_days=?, note=? WHERE session_id=?")
               ->execute([$year,$month,$deptId,$course,$trainType,$trainer,$trainerId,$orgUnit,$hours,$planDays,$note,$sid]);
        } else {
            $db->prepare("INSERT INTO training_session
                (year,plan_month,dept_id,course_name,train_type,trainer,trainer_id,org_unit,hours,plan_days,status,note,created_by,created_by_name)
                VALUES (?,?,?,?,?,?,?,?,?,?, 'planned', ?,?,?)")
               ->execute([$year,$month,$deptId,$course,$trainType,$trainer,$trainerId,$orgUnit,$hours,$planDays,$note,$uid,$uname]);
            $sid = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['session_id'=>$sid]);
}

/* 確認實行（登錄權）：登錄上課日期/時段/地點（支援多天課程，days JSON 每天一列）。
   狀態：mark_done=1 → done(已完成，計入 KPI 分子)；否則至少推進到 scheduled(已排定，可印簽到表)，
   已經是 done 的場次維持 done（單純修改實行紀錄）。計畫欄位一律不動；名單另由 save_attendees 寫入。
   主表 done_date/start_time/end_time = 第 1 天的值、actual_hours = 各天合計（相容既有程式與 KPI）。 */
case 'save_execution': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT status FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    $cur = $st->fetchColumn();
    if ($cur === false) jerr('找不到場次');
    if ($cur === 'cancelled') jerr('此計畫已取消，請先恢復為計畫中再確認實行');
    $location = trim((string)($_POST['location'] ?? '')) ?: null;

    $shiftId = ($_POST['shift_type_id'] ?? '') === '' ? null : (int)$_POST['shift_type_id'];

    // days：[{day_date, start_time, end_time, break_minutes, hours}, ...]；沒帶就退回單天（相容舊呼叫）
    $days = json_decode((string)($_POST['days'] ?? '[]'), true);
    if (!is_array($days) || !count($days)) {
        $days = [['day_date'=>trim((string)($_POST['done_date'] ?? '')),
                  'start_time'=>$_POST['start_time'] ?? '', 'end_time'=>$_POST['end_time'] ?? '',
                  'hours'=>$_POST['actual_hours'] ?? '']];
    }
    if (count($days) > 60) jerr('上課天數請勿超過 60 天');
    $trSet = training_settings($db);          // 休息時段（重算休息分鐘用），迴圈外讀一次
    $clean = []; $seen = [];
    foreach ($days as $i => $d) {
        $n = $i + 1;
        $date = trim((string)($d['day_date'] ?? ''));
        if ($date === '') jerr("第 {$n} 天：請填上課日期");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jerr("第 {$n} 天：日期格式不正確（應為 YYYY-MM-DD）");
        [$yy,$mm,$dd] = array_map('intval', explode('-', $date));
        if (!checkdate($mm, $dd, $yy)) jerr("第 {$n} 天：日期不存在（{$date}）");
        if (isset($seen[$date])) jerr("第 {$n} 天：日期重複（{$date}）");
        $seen[$date] = true;
        $s = tr_norm_time($d['start_time'] ?? '');
        $e = tr_norm_time($d['end_time'] ?? '');
        if (!tr_valid_time($s)) jerr("第 {$n} 天：開始時間不是合法時刻（{$s}），時須 0-23、分須 0-59");
        if (!tr_valid_time($e)) jerr("第 {$n} 天：結束時間不是合法時刻（{$e}），時須 0-23、分須 0-59");
        if ($s && $e && $e <= $s) jerr("第 {$n} 天：結束時間（{$e}）不可早於或等於開始時間（{$s}）");
        // 休息一律由系統依「上課時間 ∩ 休息時段」重算（前端不給改，後端也不採信送上來的值）
        $brk = training_break_minutes($db, $s, $e, $trSet);
        // 時數＝(結束−開始)−休息；使用者可手填覆蓋（例：中間穿插其他行程）
        $span = ($s && $e) ? (int)round((strtotime("1970-01-01 $e UTC") - strtotime("1970-01-01 $s UTC")) / 60) : null;
        if ($span !== null && $brk >= $span)
            jerr("第 {$n} 天：上課時間 {$s}~{$e} 全部落在休息時段（{$trSet['training_break_start']}~{$trSet['training_break_end']}），扣除休息後沒有時數；請調整上課時間，或到「模組設定」改休息時段");
        $h = ($d['hours'] ?? '') === '' ? null : (float)$d['hours'];
        if ($h === null && $span !== null) $h = round(($span - $brk) / 60, 1);
        if ($h !== null && ($h < 0 || $h > 24)) jerr("第 {$n} 天：時數 {$h} 不合理（0~24）");
        $clean[] = ['date'=>$date, 'start'=>$s, 'end'=>$e, 'break'=>$brk, 'hours'=>$h];
    }
    usort($clean, fn($a, $b) => strcmp($a['date'], $b['date']));
    $totalH = 0; $hasH = false;
    foreach ($clean as $c) if ($c['hours'] !== null) { $totalH += $c['hours']; $hasH = true; }

    $markDone = (int)($_POST['mark_done'] ?? 0) === 1;
    $newStatus = $markDone ? 'done' : ($cur === 'done' ? 'done' : 'scheduled');
    try {
        $db->beginTransaction();
        training_event_remove($db, $sid);      // 舊事件先清（日期/時間可能已變動）
        $db->prepare("UPDATE training_session SET status=?, done_date=?, start_time=?, end_time=?, location=?, actual_hours=?, plan_days=?, shift_type_id=?
                      WHERE session_id=?")
           ->execute([$newStatus, $clean[0]['date'], $clean[0]['start'], $clean[0]['end'], $location,
                      $hasH ? round($totalH, 1) : null, count($clean), $shiftId, $sid]);
        $db->prepare("DELETE FROM training_session_day WHERE session_id=?")->execute([$sid]);
        $ins = $db->prepare("INSERT INTO training_session_day (session_id, day_no, day_date, start_time, end_time, break_minutes, hours)
                             VALUES (?,?,?,?,?,?,?)");
        foreach ($clean as $i => $c) $ins->execute([$sid, $i + 1, $c['date'], $c['start'], $c['end'], $c['break'], $c['hours']]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    // 確定開課後自動寫入行事曆（一天一筆；失敗不擋存檔）
    $evCount = training_event_sync($db, $sid);
    jout(['session_id'=>$sid, 'status'=>$newStatus, 'days'=>count($clean), 'events'=>$evCount,
          'total_hours'=>$hasH ? round($totalH, 1) : null]);
}

/* 狀態切換（登錄權）：退回計畫中 / 取消計畫 / 恢復計畫。
   退回或取消時清空開課日（KPI 只認 done），時段/地點/實際時數保留供改期後沿用。 */
case 'set_status': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    if (!in_array($status, ['planned','cancelled'], true)) jerr('狀態只能設為計畫中或取消');
    $st = $db->prepare("SELECT 1 FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    if (!$st->fetchColumn()) jerr('找不到場次');
    try {
        $db->beginTransaction();
        training_event_remove($db, $sid);      // 退回計畫中/取消 → 一併撤掉行事曆事件
        $db->prepare("UPDATE training_session SET status=?, done_date=NULL WHERE session_id=?")->execute([$status, $sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('狀態變更失敗：'.$e->getMessage(), 500); }
    jout(['session_id'=>$sid, 'status'=>$status]);
}

/* 部門人員（講師/參加人員選擇用）
   排除：離職(state=0)、共用帳號(is_shared_account=1)、系統/公用身分(user_status 9/90/9999)。
   一併回傳該部門的職稱（同一人多職稱時取主要職務 is_main 優先）。 */
case 'people': {
    $deptId = (int)($_GET['dept_id'] ?? 0);
    if ($deptId <= 0) jout(['people'=>[]]);
    $st = $db->prepare("SELECT u.id, u.user_cname,
                               SUBSTRING_INDEX(GROUP_CONCAT(p.name ORDER BY m.is_main DESC, p.sort_order, p.id SEPARATOR '|'), '|', 1) AS position_name
                        FROM user_department_position_map m
                        JOIN user u ON u.id=m.user_id
                        LEFT JOIN position p ON p.id=m.position_id
                        WHERE m.department_id=? AND u.user_cname IS NOT NULL AND u.user_cname<>''
                          AND COALESCE(u.state,1) <> 0
                          AND COALESCE(u.is_shared_account,0) <> 1
                          AND COALESCE(u.user_status,0) NOT IN (9,90,9999)
                        GROUP BY u.id, u.user_cname
                        ORDER BY u.user_cname");
    $st->execute([$deptId]);
    jout(['people'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* 場次參加人員名單 */
case 'get_attendees': {
    $sid = (int)($_GET['session_id'] ?? 0);
    $st = $db->prepare("SELECT att_id, user_id, user_name, dept_name, position_name, attended, signed, signed_at, sign_method
                        FROM training_attendee WHERE session_id=? ORDER BY dept_name, user_name");
    $st->execute([$sid]);
    jout(['attendees'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* 儲存參加人員名單（整批取代；同步應到/實到人數） */
case 'save_attendees': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT 1 FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    if (!$st->fetchColumn()) jerr('找不到場次');
    $list = json_decode((string)($_POST['attendees'] ?? '[]'), true);
    if (!is_array($list)) $list = [];
    try {
        $db->beginTransaction();
        // 保留既有簽名狀態：先讀舊資料
        $old = [];
        $oq = $db->prepare("SELECT user_id, attended, signed, signed_at, sign_method FROM training_attendee WHERE session_id=?");
        $oq->execute([$sid]);
        foreach ($oq->fetchAll(PDO::FETCH_ASSOC) as $o) $old[(int)$o['user_id']] = $o;
        $db->prepare("DELETE FROM training_attendee WHERE session_id=?")->execute([$sid]);
        $ins = $db->prepare("INSERT INTO training_attendee (session_id,user_id,user_name,dept_name,position_name,attended,signed,signed_at,sign_method)
                             VALUES (?,?,?,?,?,?,?,?,?)");
        $total = 0; $att = 0;
        foreach ($list as $p) {
            $uidP = (int)($p['user_id'] ?? 0);
            if ($uidP <= 0) continue;
            $attended = (int)($p['attended'] ?? 0) === 1 ? 1 : 0;
            $o = $old[$uidP] ?? null;
            $ins->execute([$sid, $uidP, trim((string)($p['user_name'] ?? '')) ?: null,
                trim((string)($p['dept_name'] ?? '')) ?: null,
                trim((string)($p['position_name'] ?? '')) ?: null, $attended,
                $o ? (int)$o['signed'] : 0, $o ? $o['signed_at'] : null, $o ? $o['sign_method'] : null]);
            $total++; if ($attended) $att++;
        }
        // 同步人數（有名單即以名單為準）
        $db->prepare("UPDATE training_session SET target_headcount=?, actual_headcount=? WHERE session_id=?")
           ->execute([$total, $att, $sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存名單失敗：'.$e->getMessage(), 500); }
    jout(['total'=>$total]);
}

/* 複製場次（內容複製、不帶參加名單；狀態回計畫中） */
case 'copy_session': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) jerr('找不到場次');
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO training_session
            (year,plan_month,dept_id,course_name,train_type,trainer,trainer_id,org_unit,hours,plan_days,status,note,created_by,created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?, 'planned', ?, ?, ?)")
           ->execute([$s['year'],$s['plan_month'],$s['dept_id'],$s['course_name'],$s['train_type'],$s['trainer'],
                      $s['trainer_id'],$s['org_unit'],$s['hours'],$s['plan_days'],$s['note'],$uid,$uname]);
        $newId = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('複製失敗：'.$e->getMessage(), 500); }
    jout(['session_id'=>$newId]);
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
        training_event_remove($db, $sid);      // 連帶撤掉行事曆事件
        $db->prepare("DELETE FROM training_attendee WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_session_day WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_session WHERE session_id=?")->execute([$sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    jout([]);
}

default:
    jerr('未知動作：'.$action);
}
