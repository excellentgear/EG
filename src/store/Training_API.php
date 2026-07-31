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
    training_purge_temp_attachments($db);      // 懶惰清除過期暫存附件
    jout(['perms'=>$perms, 'departments'=>$depts, 'years'=>$years, 'locations'=>tr_locations($db),
          'shifts'=>training_shifts($db), 'settings'=>training_settings($db), 'event_categories'=>$cats,
          'cat_internal_eff'=>training_category_id($db, 'internal'), 'cat_external_eff'=>training_category_id($db, 'external'),
          'att_cats'=>TRAINING_ATT_CATS, 'eval_methods'=>TRAINING_EVAL_METHODS,
          'dept_groups'=>training_dept_groups($db), 'units'=>training_units($db),
          'attach_nas_dir'=>$perms['canAdmin'] ? training_attach_dir($db) : null,
          'attach_root'=>$perms['canAdmin'] ? eg_attach_root($db) : null,
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

/* ---------- 場次附件（簽到表掃描/教材/試卷）：DB 只存檔名，路徑即時組（鐵律5） ---------- */
case 'list_attach': {
    training_purge_temp_attachments($db);
    $sid = (int)($_GET['session_id'] ?? 0);
    jout(['attachments'=>training_attachments($db, $sid)]);
}

case 'upload_attach': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid > 0) {
        $st = $db->prepare("SELECT 1 FROM training_session WHERE session_id=?");
        $st->execute([$sid]);
        if (!$st->fetchColumn()) jerr('找不到場次');
    }
    // 類別可複選（同一份掃描 PDF 可能同時是簽到表＋試卷），存逗號分隔
    $cats = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['cat'] ?? ''))),
                                      fn($c) => isset(TRAINING_ATT_CATS[$c])));
    $cat = $cats ? implode(',', array_unique($cats)) : 'other';
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['file']['error'] ?? -1;
        jerr($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
            ? '檔案超過伺服器允許的上傳大小（upload_max_filesize）' : '上傳失敗（錯誤碼 '.$err.'）');
    }
    $orig = basename((string)$_FILES['file']['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allow = ['pdf','jpg','jpeg','png','gif','webp','bmp','tif','tiff','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip'];
    if (!in_array($ext, $allow, true)) jerr('不支援的檔案類型「'.$ext.'」（可上傳：'.implode('、', $allow).'）');
    if ((int)$_FILES['file']['size'] > 50 * 1024 * 1024) jerr('單檔上限 50MB');
    $nasDir = training_attach_dir($db);
    if (!eg_attach_ensure_dir($nasDir)) jerr('無法建立附件目錄，請確認「模組設定」的附件路徑（含網路磁碟權限）：'.$nasDir, 500);
    $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $nasDir . $fname)) jerr('檔案寫入失敗（路徑：'.$nasDir.'）', 500);
    try {
        if ($sid > 0) {
            $db->prepare("INSERT INTO training_attachment (session_id, cat, file_name, original_name, file_size, user_id, user_name, status)
                          VALUES (?,?,?,?,?,?,?,'active')")
               ->execute([$sid, $cat, $fname, $orig, (int)$_FILES['file']['size'], $uid, $uname]);
        } else {   // 場次尚未存檔＝暫存 2 天，存檔時由 save_execution 帶 temp_att_ids 轉正
            $db->prepare("INSERT INTO training_attachment (session_id, cat, file_name, original_name, file_size, user_id, user_name, status, expire_at)
                          VALUES (0,?,?,?,?,?,?,'temp', DATE_ADD(NOW(), INTERVAL 2 DAY))")
               ->execute([$cat, $fname, $orig, (int)$_FILES['file']['size'], $uid, $uname]);
        }
    } catch (Throwable $e) { @unlink($nasDir . $fname); jerr('附件登錄失敗：'.$e->getMessage(), 500); }
    jout(['att_id'=>(int)$db->lastInsertId(), 'file_name'=>$fname, 'original_name'=>$orig,
          'cat'=>$cat, 'file_size'=>(int)$_FILES['file']['size']]);
}

case 'del_attach': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $aid = (int)($_POST['att_id'] ?? 0);
    $st = $db->prepare("SELECT file_name, status, user_id FROM training_attachment WHERE att_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    if ($a['status'] === 'temp' && (int)$a['user_id'] !== $uid) jerr('暫存附件僅上傳者本人可刪', 403);
    $db->prepare("DELETE FROM training_attachment WHERE att_id=?")->execute([$aid]);
    $nasDir = training_attach_dir($db);
    $fp = $nasDir . $a['file_name'];
    if (is_file($fp)) @unlink($fp);
    jout([]);
}

/* 下載/預覽：一律經此守門（檢閱權即可），路徑現場組 */
case 'download_attach': {
    $aid = (int)($_GET['att_id'] ?? 0);
    $st = $db->prepare("SELECT file_name, original_name FROM training_attachment WHERE att_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    $nasDir = training_attach_dir($db);
    $fp = $nasDir . $a['file_name'];
    if (!is_file($fp)) jerr('檔案不存在（可能已被移動或附件路徑設定已變更）：'.$a['file_name'], 404);
    $ext = strtolower(pathinfo($a['file_name'], PATHINFO_EXTENSION));
    $mime = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
             'gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','txt'=>'text/plain; charset=utf-8'][$ext] ?? 'application/octet-stream';
    $inline = (bool)preg_match('#^(image/|application/pdf|text/)#', $mime);
    $dl = $a['original_name'] ?: $a['file_name'];
    header('Content-Type: '.$mime);                                   // 覆蓋前面的 application/json
    header('Content-Length: '.filesize($fp));
    header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode($dl));
    readfile($fp);
    exit;
}

/* 附件儲存路徑（限訓練管理員；只存設定值，不存完整路徑到附件列） */
case 'save_attach_path': {
    if (!$perms['canAdmin']) jerr('無管理權限（附件路徑限訓練管理員）', 403);
    $nasDir = trim((string)($_POST['nas_dir'] ?? ''));
    if ($nasDir === '') jerr('附件儲存路徑不可為空（要恢復預設請填全站根路徑＋\\教育訓練）');
    try {
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                          updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)")
           ->execute(['training_nas_dir', $nasDir, $uid, $uname]);
    } catch (Throwable $e) { jerr('路徑儲存失敗：'.$e->getMessage(), 500); }
    jout(['attach_nas_dir'=>training_attach_dir($db)]);
}

/* ---------- 部門合併群組（達標統計的顯示單位）：限訓練管理員 ---------- */
case 'save_dept_groups': {
    if (!$perms['canAdmin']) jerr('無管理權限（部門合併設定限訓練管理員）', 403);
    $groups = json_decode((string)($_POST['groups'] ?? '[]'), true);
    if (!is_array($groups)) jerr('資料格式不正確');
    try {
        $db->beginTransaction();
        $db->exec("DELETE FROM training_dept_group_member");
        $db->exec("UPDATE training_dept_group SET is_active=0");
        $ins  = $db->prepare("INSERT INTO training_dept_group (group_id, group_name, sort_order, is_active) VALUES (?,?,?,1)
                              ON DUPLICATE KEY UPDATE group_name=VALUES(group_name), sort_order=VALUES(sort_order), is_active=1");
        $ins2 = $db->prepare("INSERT INTO training_dept_group (group_name, sort_order, is_active) VALUES (?,?,1)");
        $mem  = $db->prepare("INSERT IGNORE INTO training_dept_group_member (dept_id, group_id) VALUES (?,?)");
        $i = 0;
        foreach ($groups as $g) {
            $name = trim((string)($g['group_name'] ?? ''));
            $ids  = array_values(array_unique(array_filter(array_map('intval', $g['dept_ids'] ?? []))));
            if ($name === '' || !$ids) continue;              // 沒名字或沒成員的群組直接不留
            $gid = (int)($g['group_id'] ?? 0);
            if ($gid > 0) { $ins->execute([$gid, $name, $i]); }
            else { $ins2->execute([$name, $i]); $gid = (int)$db->lastInsertId(); }
            foreach ($ids as $d) $mem->execute([$d, $gid]);
            $i++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('合併設定儲存失敗：'.$e->getMessage(), 500); }
    jout(['dept_groups'=>training_dept_groups($db), 'units'=>training_units($db)]);
}

/* ---------- 訓練次數目標（每月/每年 × 內訓/外訓，可全公司統一或逐單位）：限訓練管理員 ---------- */
case 'save_targets': {
    if (!$perms['canAdmin']) jerr('無管理權限（目標設定限訓練管理員）', 403);
    $year = (int)($_POST['year'] ?? date('Y'));
    $list = json_decode((string)($_POST['targets'] ?? '[]'), true);
    if (!is_array($list)) jerr('資料格式不正確');
    try {
        $db->beginTransaction();
        $up = $db->prepare("INSERT INTO training_target (year, unit_key, internal_period, internal_times, external_period, external_times, updated_at, updated_by)
                            VALUES (?,?,?,?,?,?,NOW(),?)
                            ON DUPLICATE KEY UPDATE internal_period=VALUES(internal_period), internal_times=VALUES(internal_times),
                                external_period=VALUES(external_period), external_times=VALUES(external_times),
                                updated_at=NOW(), updated_by=VALUES(updated_by)");
        $del = $db->prepare("DELETE FROM training_target WHERE year=? AND unit_key=?");
        foreach ($list as $t) {
            $key = trim((string)($t['unit_key'] ?? ''));
            if ($key === '' || !preg_match('/^(ALL|[DG]\d+)$/', $key)) continue;
            if (!empty($t['use_default']) && $key !== 'ALL') { $del->execute([$year, $key]); continue; }  // 改回套用統一預設
            $ip = ($t['internal_period'] ?? 'year') === 'month' ? 'month' : 'year';
            $ep = ($t['external_period'] ?? 'year') === 'month' ? 'month' : 'year';
            $it = max(0, (int)($t['internal_times'] ?? 0));
            $et = max(0, (int)($t['external_times'] ?? 0));
            if ($it > 999 || $et > 999) jerr('次數上限 999');
            $up->execute([$year, $key, $ip, $it, $ep, $et, $uname]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('目標儲存失敗：'.$e->getMessage(), 500); }
    jout(['targets'=>training_targets($db, $year)]);
}

/* 達標狀況（依顯示單位 × 月份 × 內外訓） */
case 'target_stats': {
    $year = (int)($_GET['year'] ?? date('Y'));
    jout(['year'=>$year, 'stats'=>training_target_stats($db, $year), 'targets'=>training_targets($db, $year),
          'units'=>training_units($db)]);
}

/* 單一場次完整內容（清單展開／檢視畫面用）：場次＋上課日＋參加人員(含評鑑)＋附件 */
case 'session_detail': {
    $sid = (int)($_GET['session_id'] ?? 0);
    $st = $db->prepare("SELECT s.*, d.name AS dept_name FROM training_session s
                        LEFT JOIN department d ON d.id=s.dept_id WHERE s.session_id=?");
    $st->execute([$sid]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) jerr('找不到場次');
    $dq = $db->prepare("SELECT day_no, day_date, start_time, end_time, break_minutes, hours FROM training_session_day
                        WHERE session_id=? ORDER BY day_no, day_date");
    $dq->execute([$sid]);
    $aq = $db->prepare("SELECT user_id, user_name, dept_name, position_name, attended, signed, eval_result, eval_score, eval_note
                        FROM training_attendee WHERE session_id=? ORDER BY dept_name, user_name");
    $aq->execute([$sid]);
    $deptIds = training_session_depts($db, [$sid])[$sid] ?? [];
    $dnames = [];
    if ($deptIds) {
        $in = implode(',', $deptIds);
        $dnames = $db->query("SELECT name FROM department WHERE id IN ({$in}) ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN);
    }
    jout(['session'=>$s, 'dept_ids'=>$deptIds, 'dept_names'=>$dnames,
          'days'=>$dq->fetchAll(PDO::FETCH_ASSOC), 'attendees'=>$aq->fetchAll(PDO::FETCH_ASSOC),
          'attachments'=>training_attachments($db, $sid)]);
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
    // 各場次附件數（讓清單看得出簽到表/教材有沒有上傳）
    $attMap = [];
    try {
        $aq = $db->prepare("SELECT a.session_id, COUNT(*) c FROM training_attachment a
                            JOIN training_session s ON s.session_id=a.session_id
                            WHERE s.year=? AND a.status='active' GROUP BY a.session_id");
        $aq->execute([$year]);
        foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $a) $attMap[(int)$a['session_id']] = (int)$a['c'];
    } catch (Throwable $e) {}
    // 各場次評鑑統計（清單顯示合格狀態）
    $evMap = [];
    try {
        $eq = $db->prepare("SELECT a.session_id,
                                   SUM(a.eval_result='pass') p, SUM(a.eval_result='fail') f,
                                   SUM(a.eval_result='exempt') x, SUM(a.eval_result IS NULL OR a.eval_result='') n
                            FROM training_attendee a JOIN training_session s ON s.session_id=a.session_id
                            WHERE s.year=? GROUP BY a.session_id");
        $eq->execute([$year]);
        foreach ($eq->fetchAll(PDO::FETCH_ASSOC) as $e)
            $evMap[(int)$e['session_id']] = ['pass'=>(int)$e['p'], 'fail'=>(int)$e['f'], 'exempt'=>(int)$e['x'], 'none'=>(int)$e['n']];
    } catch (Throwable $e) {}
    // 對象部門（複選）
    $sq = $db->prepare("SELECT sd.session_id, sd.dept_id FROM training_session_dept sd
                        JOIN training_session s ON s.session_id=sd.session_id WHERE s.year=?");
    $sq->execute([$year]);
    $sdMap = [];
    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $d) $sdMap[(int)$d['session_id']][] = (int)$d['dept_id'];

    $rows = [];
    $summary = [];
    for ($m = 1; $m <= 12; $m++) $summary[$m] = ['den'=>0, 'num'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (int)$r['session_id'];
        $r['dept_ids'] = $sdMap[$sid] ?? ($r['dept_id'] !== null ? [(int)$r['dept_id']] : []);
        $r['dept_name'] = $r['dept_ids']
            ? implode('、', array_map(fn($d) => $deptMap[$d] ?? '', $r['dept_ids'])) : '全公司';
        $r['days'] = $dayMap[$sid] ?? [];
        $r['attach_count'] = $attMap[$sid] ?? 0;
        $r['eval'] = $evMap[$sid] ?? ['pass'=>0,'fail'=>0,'exempt'=>0,'none'=>0];
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
    // 已排定/已完成的場次＝計畫已定案，只有訓練管理員能再改計畫內容（避免事後動到已成立的紀錄）
    if ($sid > 0) {
        $st = $db->prepare("SELECT status FROM training_session WHERE session_id=?");
        $st->execute([$sid]);
        $curSt = $st->fetchColumn();
        if ($curSt === false) jerr('找不到場次');
        if (in_array($curSt, ['scheduled','done'], true) && !$perms['canAdmin'])
            jerr('此場次已「'.($curSt === 'done' ? '完成' : '排定開課').'」，計畫內容僅訓練管理員可修改；如需改期請用「實行資料」或先退回計畫中', 403);
    }
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['plan_month'] ?? 0);
    $course = trim((string)($_POST['course_name'] ?? ''));
    if ($year < 2000 || $month < 1 || $month > 12) jerr('請選擇有效年月');
    if ($course === '') jerr('請填課程名稱');
    // 對象部門複選（dept_ids）；相容舊呼叫的單一 dept_id
    $deptIds = array_values(array_unique(array_filter(array_map('intval',
        explode(',', (string)($_POST['dept_ids'] ?? ($_POST['dept_id'] ?? '')))))));
    $deptId = $deptIds ? $deptIds[0] : null;
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
        training_save_session_depts($db, $sid, $deptIds);      // 對象部門複選（同步主表 dept_id）
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
    // 課程大綱／評鑑方式（確認開課時填）
    $outline = trim((string)($_POST['outline'] ?? ''));
    if (mb_strlen($outline) > 5000) jerr('課程大綱過長（上限 5000 字）');
    $evalMethod = trim((string)($_POST['eval_method'] ?? ''));
    if ($evalMethod !== '' && !isset(TRAINING_EVAL_METHODS[$evalMethod])) jerr('評鑑方式不正確');

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
        $db->prepare("UPDATE training_session SET status=?, done_date=?, start_time=?, end_time=?, location=?, actual_hours=?, plan_days=?, shift_type_id=?,
                             outline=?, eval_method=?
                      WHERE session_id=?")
           ->execute([$newStatus, $clean[0]['date'], $clean[0]['start'], $clean[0]['end'], $location,
                      $hasH ? round($totalH, 1) : null, count($clean), $shiftId,
                      $outline === '' ? null : $outline, $evalMethod === '' ? null : $evalMethod, $sid]);
        // 暫存附件轉正（與主單同一筆交易內；限本人上傳的 temp）
        $tempIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['temp_att_ids'] ?? '')))));
        if ($tempIds) {
            $in = implode(',', $tempIds);
            $db->prepare("UPDATE training_attachment SET session_id=?, status='active', expire_at=NULL
                          WHERE att_id IN ({$in}) AND user_id=? AND status='temp'")->execute([$sid, $uid]);
        }
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
    $st = $db->prepare("SELECT att_id, user_id, user_name, dept_name, position_name, attended, signed, signed_at, sign_method,
                               eval_result, eval_score, eval_note
                        FROM training_attendee WHERE session_id=? ORDER BY dept_name, user_name");
    $st->execute([$sid]);
    jout(['attendees'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* 儲存參加人員名單（整批取代；同步應到/實到人數） */
case 'save_attendees': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT eval_method FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    $sRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sRow) jerr('找不到場次');
    $isNotice = ($sRow['eval_method'] ?? '') === 'notice';    // 宣導＝免評鑑
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
        $ins = $db->prepare("INSERT INTO training_attendee (session_id,user_id,user_name,dept_name,position_name,attended,signed,signed_at,sign_method,
                                                            eval_result,eval_score,eval_note)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $total = 0; $att = 0;
        foreach ($list as $p) {
            $uidP = (int)($p['user_id'] ?? 0);
            if ($uidP <= 0) continue;                      // 一律綁 user.id，沒有 id 的不寫入
            $attended = (int)($p['attended'] ?? 0) === 1 ? 1 : 0;
            $o = $old[$uidP] ?? null;
            $ev = (string)($p['eval_result'] ?? '');
            if ($isNotice) $ev = 'exempt';                  // 宣導課程一律免評鑑
            elseif (!in_array($ev, ['pass','fail','exempt'], true)) $ev = '';
            $sc = ($p['eval_score'] ?? '') === '' || $p['eval_score'] === null ? null : (float)$p['eval_score'];
            if ($sc !== null && ($sc < 0 || $sc > 100)) jerr('評分需在 0~100 之間（'.trim((string)($p['user_name'] ?? '')).'）');
            $ins->execute([$sid, $uidP, trim((string)($p['user_name'] ?? '')) ?: null,
                trim((string)($p['dept_name'] ?? '')) ?: null,
                trim((string)($p['position_name'] ?? '')) ?: null, $attended,
                $o ? (int)$o['signed'] : 0, $o ? $o['signed_at'] : null, $o ? $o['sign_method'] : null,
                $ev === '' ? null : $ev, $sc, trim((string)($p['eval_note'] ?? '')) ?: null]);
            $total++; if ($attended) $att++;
        }
        // 同步人數（有名單即以名單為準）
        $db->prepare("UPDATE training_session SET target_headcount=?, actual_headcount=? WHERE session_id=?")
           ->execute([$total, $att, $sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存名單失敗：'.$e->getMessage(), 500); }
    // 行事曆事件的「發生者」＝內訓：講師＋參加人員／外訓：參加人員 → 名單改了要重寫一次
    $ev = training_event_sync($db, $sid);
    jout(['total'=>$total, 'events'=>$ev]);
}

/* 某位員工的受訓紀錄（供其他頁面查詢：員工資料、稽核佐證…）
   一律以 user.id 查（training_attendee.user_id），本人可查自己，其餘需檢閱權。 */
case 'user_history': {
    $target = (int)($_GET['user_id'] ?? 0);
    if ($target <= 0) jerr('請指定 user_id');
    if ($target !== $uid && !$perms['canView']) jerr('無查詢權限', 403);
    $year = ($_GET['year'] ?? '') === '' ? null : (int)$_GET['year'];
    jout(['user_id'=>$target, 'records'=>training_user_history($db, $target, $year)]);
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
            (year,plan_month,dept_id,course_name,train_type,trainer,trainer_id,org_unit,hours,plan_days,status,note,outline,eval_method,created_by,created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?, 'planned', ?, ?, ?, ?, ?)")
           ->execute([$s['year'],$s['plan_month'],$s['dept_id'],$s['course_name'],$s['train_type'],$s['trainer'],
                      $s['trainer_id'],$s['org_unit'],$s['hours'],$s['plan_days'],$s['note'],
                      $s['outline'] ?? null, $s['eval_method'] ?? null, $uid,$uname]);
        $newId = (int)$db->lastInsertId();
        training_save_session_depts($db, $newId, training_session_depts($db, [$sid])[$sid] ?? []);   // 對象部門一併複製
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
    // 附件實體檔先收集，DB 刪成功後才刪檔（避免交易失敗卻已刪檔）
    $attFiles = array_map(fn($a) => $a['file_name'], training_attachments($db, $sid));
    try {
        $db->beginTransaction();
        training_event_remove($db, $sid);      // 連帶撤掉行事曆事件
        $db->prepare("DELETE FROM training_attachment WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_attendee WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_session_day WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_session_dept WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_session WHERE session_id=?")->execute([$sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    if ($attFiles) {
        $nasDir = training_attach_dir($db);
        foreach ($attFiles as $fn) { $fp = $nasDir . $fn; if (is_file($fp)) @unlink($fp); }
    }
    jout([]);
}

default:
    jerr('未知動作：'.$action);
}
