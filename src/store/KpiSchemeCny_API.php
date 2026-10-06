<?php
/**
 * KpiSchemeCny_API.php — KPI 新方案頁面（views/news/KPI_new.php）的寫入端點
 *
 * 2026-10-05 擴充：原本只有春節目標調整，現在加上「設定」分頁要用的指標 CRUD。
 * 這支檔案碰的全部是 kpi_scheme_* 開頭的新表（kpi_scheme_cny_adjust／
 * kpi_scheme_indicator／kpi_scheme_indicator_year），**完全不會寫到**正式 KPI 系統
 * 的 kpi_as_indicator／kpi_as_indicator_year／kpi_as_monthly_value——那三張表只有
 * kpi_scheme_lib.php 的 kps_from_snapshot() 會讀（唯讀 SELECT），確保
 * `views/news/KPI.php` 不受本頁任何操作影響，這是使用者明確要求的設計界線。
 *
 *   春節調整：list / save（見 kpi_scheme_lib.php「五、春節目標調整」）
 *   指標設定：list_scheme（讀某年度全部指標）／save_indicator（主檔）／
 *             save_indicator_year（年度設定：目標/擔當者/來源/參數）／
 *             preview_compute（存檔前即時試算，不寫入）
 *
 * 權限沿用既有 KPI 模組角色（kpi_as_current_user/kpi_as_perms），不另開角色——
 * 這是 KPI_new.php 頁面自己的輔助設定，不是獨立功能，沒有理由另外管一組權限。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/kpi_as_lib.php';
include_once $document_root . '/EGsystem/src/common/kpi_scheme_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = kpi_as_current_user($db);
if (!$u) jerr('未登入', 401);
$perms = kpi_as_perms($db, $u);
if (!$perms['canView']) jerr('無 KPI 檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$curY = (int)date('Y');
$curM = (int)date('n');

/** 某年某月是否已整月結束（未來月份不可填寫/覆寫，比照正式系統 kpi_month_ended） */
function kps_month_ended(int $y, int $m): bool {
    return strtotime(date('Y-m-t', mktime(0, 0, 0, $m, 1, $y)) . ' 23:59:59') < time();
}
/** 讀單一指標的主檔＋年度設定（本頁寫入動作共用的查詢，找不到回 null） */
function kps_get_iy_row(PDO $db, int $iid, int $year): ?array {
    $st = $db->prepare("SELECT i.indicator_id, i.item_no, i.name, i.freq, i.value_type,
                               y.owner_user_id, y.source_mode, y.calculator_key, y.params_json,
                               y.target_direction, y.target_value
                        FROM kpi_scheme_indicator i
                        JOIN kpi_scheme_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                        WHERE i.indicator_id=? AND i.is_active=1 AND y.is_active=1");
    $st->execute([$year, $iid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
/**
 * 誰可以改這個指標的這一格——本方案沒有正式系統那套「年度鎖定」與「請假代理」，
 * 單純：系統管理者／KPI 管理員永遠可以；指標指定的擔當者本人可以改自己名下的指標。
 */
function kps_can_edit(array $perms, ?int $ownerUserId, int $uid): bool {
    if (!empty($perms['isAdmin']) || !empty($perms['canAdmin'])) return true;
    return !empty($perms['canFill']) && $ownerUserId !== null && $ownerUserId === $uid;
}

switch ($action) {

case 'list': {
    $year = (int)($_GET['year'] ?? $curY);
    if ($year < 2020 || $year > $curY + 2) $year = $curY;

    $lost = kps_cny_lost_days_by_month($db, $year);
    $rows = [];
    for ($m = 1; $m <= 12; $m++) {
        $r = kps_cny_ratio($db, $year, $m);
        // 以字串送出比例數字：這台環境的 serialize_precision 會把 round() 的結果印成一長串
        // 浮點誤差尾巴（例：0.83330000000000004...），前端 parseFloat 吃字串結果完全相同、但乾淨得多。
        $rows[] = ['month'=>$m, 'lost_days'=>$lost[$m], 'auto_ratio'=>number_format((float)$r['auto'], 4, '.', ''),
                   'extra_pct'=>$r['extra_pct'], 'final_ratio'=>number_format((float)$r['final'], 4, '.', ''),
                   'note'=>$r['note']];
    }
    jout(['year'=>$year, 'rows'=>$rows, 'can_edit'=>$perms['canAdmin']]);
}

case 'save': {
    if (!$perms['canAdmin']) jerr('僅 KPI 管理者可設定', 403);
    $year  = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if ($year < 2020 || $year > $curY + 2) jerr('年度不合法');
    if ($month < 1 || $month > 12) jerr('月份不合法');
    $extraPct = $_POST['extra_pct'] ?? '0';
    if (!is_numeric($extraPct)) jerr('額外調整率必須是數字');
    $note = (string)($_POST['note'] ?? '');
    kps_cny_override_save($db, $year, $month, (float)$extraPct, $note, (string)($u['user_cname'] ?? ''));
    $r = kps_cny_ratio($db, $year, $month);
    jout(['saved'=>true, 'ratio'=>[
        'auto'=>number_format((float)$r['auto'], 4, '.', ''), 'extra_pct'=>$r['extra_pct'],
        'final'=>number_format((float)$r['final'], 4, '.', ''), 'lost_days'=>$r['lost_days'], 'note'=>$r['note'],
    ]]);
}

case 'list_scheme': {
    $year = (int)($_GET['year'] ?? $curY);
    if ($year < 2020 || $year > $curY + 2) $year = $curY;
    $rows = kpi_scheme_list_year($db, $year);

    $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $deptMembers = [];
    $st = $db->query("SELECT m.department_id, m.user_id, u.user_cname, m.is_main, m.position_id,
                             p.name AS position_name, p.sort_order AS pos_sort
                      FROM user_department_position_map m
                      JOIN user u ON u.id=m.user_id
                      LEFT JOIN position p ON p.id=m.position_id
                      WHERE u.user_cname IS NOT NULL AND u.user_cname<>''
                      ORDER BY m.department_id, (p.sort_order IS NULL), p.sort_order, m.user_id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $deptMembers[(int)$r['department_id']][] = [
            'user_id'=>(int)$r['user_id'], 'cname'=>$r['user_cname'],
            'position_id'=>$r['position_id']===null ? null : (int)$r['position_id'],
            'position_name'=>$r['position_name'], 'is_main'=>(int)$r['is_main']];
    }
    $ptypes = []; $officialItems = [];
    try { $ptypes = $db->query("SELECT process_type_id, process_type FROM process_type ORDER BY sort_order, process_type_id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try {
        // 連同該年度正式指標目前的 calculator_key／params_json 一起回傳，讓前端的
        // existing／existing_cny 選擇器能順便顯示「這個官方項次目前用什麼算法、
        // 月目標金額設多少」——這些資料仍然只唯讀 SELECT，不會寫回正式表。
        $st = $db->prepare("SELECT i.item_no, i.name, y.calculator_key, y.params_json
                            FROM kpi_as_indicator i
                            LEFT JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                            ORDER BY i.item_no");
        $st->execute([$year]);
        $officialItems = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    jout([
        'year'=>$year, 'rows'=>$rows, 'registry'=>kpi_scheme_registry(), 'blocks'=>kpi_scheme_blocks(),
        'can_edit'=>$perms['canAdmin'],
        'dicts'=>['departments'=>$depts, 'dept_members'=>$deptMembers,
                  'process_types'=>$ptypes, 'official_items'=>$officialItems],
    ]);
}

case 'save_indicator': {
    if (!$perms['canAdmin']) jerr('僅 KPI 管理者可設定', 403);
    $year = (int)($_POST['year'] ?? $curY);
    $wasNew = (int)($_POST['indicator_id'] ?? 0) <= 0;
    try { $iid = kpi_scheme_ind_save($db, $_POST, $year, (string)($u['user_cname'] ?? '')); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    kps_log($db, $iid, $year, null, 'setting', 'indicator', null, (string)($_POST['name'] ?? ''),
            $wasNew ? '新增指標' : '修改指標基本資料', $u);
    jout(['indicator_id'=>$iid]);
}

case 'save_indicator_year': {
    if (!$perms['canAdmin']) jerr('僅 KPI 管理者可設定', 403);
    $iid  = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? $curY);
    if ($iid <= 0) jerr('缺少指標');
    $params = [];
    if (!empty($_POST['params_json'])) {
        $params = json_decode((string)$_POST['params_json'], true);
        if (!is_array($params)) jerr('參數格式不合法');
    }
    try { kpi_scheme_iy_save($db, $iid, $year, $_POST, $params, (string)($u['user_cname'] ?? '')); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    kps_log($db, $iid, $year, null, 'setting', 'indicator_year', null,
            (string)($_POST['source_mode'] ?? '') . '/' . (string)($_POST['calculator_key'] ?? ''),
            '修改年度設定（目標/擔當者/來源/參數）', $u);
    jout([]);
}

case 'preview_compute': {
    // 僅唯讀試算（不寫入任何資料），canView 即可——設定分頁的「試算目前設定」與總覽的
    // 「前端即時試算」（toggleSim）共用這支，後者刻意開放給所有看得到這頁的人，不限管理員。
    $calcKey = trim((string)($_POST['calculator_key'] ?? ''));
    $year = (int)($_POST['year'] ?? $curY);
    $month = (int)($_POST['month'] ?? (int)date('n'));
    if (!isset(kpi_scheme_registry()[$calcKey])) jerr('計算方式不合法');
    $params = [];
    if (!empty($_POST['params_json'])) {
        $params = json_decode((string)$_POST['params_json'], true);
        if (!is_array($params)) jerr('參數格式不合法');
    }
    try { $r = kpi_scheme_compute_by_key($db, $calcKey, $year, $month, $params); }
    catch (Throwable $e) { jerr('試算失敗：'.$e->getMessage()); }
    // 同一個浮點序列化坑：v 改送字串，num/den 維持原樣(金額/件數精確值前端有用途，不強制轉字串)
    if ($r && $r['v'] !== null) $r['v'] = number_format((float)$r['v'], 4, '.', '');
    jout(['result'=>$r]);
}

/* ---------- 套用試算：把「前端即時試算」目前調整的開放參數寫回本年度設定（僅管理者） ----------
   只允許套用「本來就被標記為開放前端試算」的參數（params_json 裡該值是 {v,fe:1} 包起來的），
   不是隨便一個參數鍵都能被這支端點改掉——避免繞過「設定」分頁的正常編輯流程。 */
case 'apply_params': {
    if (!$perms['canAdmin']) jerr('僅 KPI 管理者可套用修改', 403);
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2020 || $year > $curY + 2) jerr('年度不合法');
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if ($iy['source_mode'] !== 'auto') jerr('人工填寫的指標沒有參數可套用');
    $params = kpi_as_params($iy['params_json']);
    $ov = json_decode((string)($_POST['params'] ?? '{}'), true);
    if (!is_array($ov)) jerr('參數格式錯誤');
    $changed = [];
    foreach ($ov as $k => $v) {
        $cur = $params[$k] ?? null;
        $fe = is_array($cur) && !empty($cur['fe']);
        if (!$fe) continue;   // 只允許套用「開放前端試算」的參數
        $oldV = is_array($cur) && array_key_exists('v', $cur) ? $cur['v'] : null;
        $params[$k] = ['v'=>$v, 'fe'=>1];
        if (json_encode($oldV) !== json_encode($v)) $changed[$k] = ['old'=>$oldV, 'new'=>$v];
    }
    if (!$changed) jout(['changed'=>0]);
    $db->prepare("UPDATE kpi_scheme_indicator_year SET params_json=?, Modified_By=?, Modified_At=NOW()
                 WHERE indicator_id=? AND year=?")
       ->execute([json_encode($params, JSON_UNESCAPED_UNICODE), (string)$u['user_cname'], $iid, $year]);
    kps_log($db, $iid, $year, null, 'setting', 'apply_params', null,
            json_encode($changed, JSON_UNESCAPED_UNICODE), '前端即時試算套用', $u);
    jout(['changed'=>count($changed)]);
}

/* ============================================================
 * 2026-10-05（續）：總覽頁單一欄位修改／逐筆排除／排除規則／數值明細，比照 KPI.php
 * 的 KpiAs_API.php，但一律只碰 kpi_scheme_*（kpi_scheme_monthly_value／_adjust／
 * _excl_rule），與正式系統的 kpi_as_monthly_value／_adjust／_excl_rule 完全分離。
 * ============================================================ */

/* ---------- 數值明細（「查看單一月份統計資料」） ---------- */
case 'detail_rows': {
    $iid   = (int)($_GET['indicator_id'] ?? 0);
    $year  = (int)($_GET['year'] ?? $curY);
    $month = max(1, min(12, (int)($_GET['month'] ?? 1)));
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    if ($iy['source_mode'] !== 'auto') {
        jout(['supported'=>0, 'readonly'=>0, 'rows'=>[], 'cols'=>[], 'msg'=>'人工填寫的指標沒有來源明細。']);
    }
    // existing／existing_cny 不受 kps_detail_supported() 這道白名單限制——是否有東西可以看，
    // 交給 kps_detail() 自己去查正式項次背後的計算模組決定（唯讀代理），不在這裡先擋掉。
    $params = kpi_as_params($iy['params_json']);
    $rules  = kps_excl_rules($db, $iid, $year);
    $d = kps_detail($db, $calc, $year, $month, $params, $rules);
    $readonly = !empty($d['readonly']);
    if (empty($d['supported'])) {
        jout(['supported'=>0, 'readonly'=>$readonly ? 1 : 0, 'rows'=>[], 'cols'=>[], 'msg'=>$d['note'] ?? '這個計算方式還沒有做數值明細。']);
    }
    $adj = [];
    if (!$readonly) foreach (kps_adjust_rows($db, $iid, $year, $month) as $a) $adj[(string)$a['row_key']] = $a;
    $rows = [];
    $cap = 500;
    $ordered = [];
    foreach (['bad', 'warn', 'info'] as $kk) {
        foreach ($d['rows'] as $r) if ((string)($r['kind'] ?? 'bad') === $kk) $ordered[] = $r;
    }
    foreach ($ordered as $r) {
        if (count($rows) >= $cap) break;
        $k = (string)$r['key'];
        $r['excluded']  = isset($adj[$k]) ? 1 : 0;
        $r['ex_reason'] = isset($adj[$k]) ? (string)$adj[$k]['reason'] : '';
        $r['ex_by']     = isset($adj[$k]) ? (string)$adj[$k]['created_by_name'] : '';
        $r['ex_at']     = isset($adj[$k]) ? (string)$adj[$k]['created_at'] : '';
        $rows[] = $r;
    }
    $canAdjust = $readonly ? 0 : (kps_can_edit($perms, $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null,
                                                (int)$u['id']) ? 1 : 0);

    // 直接修改來源資料（使用者要求比照 KPI.php 補齊）：existing/existing_cny 一律 na，
    // 其餘依 kps_edit_mode()（管理員設定優先於系統建議）。
    $em = kps_edit_mode($db, $iid, $calc);
    $editFields = []; $canEdit = 0;
    $spec = $readonly ? [] : kps_detail_edit_spec($calc);
    if (!$readonly && $em['mode'] === 'allow' && $spec) {
        $canEdit = $canAdjust;  // 同一群人（擔當者本人或 KPI 管理員）才能直接改來源
        foreach ($spec['fields'] as $f) {
            $opts = [];
            foreach (($f['opts'] ?? []) as $k => $t) $opts[] = ['v'=>(string)$k, 't'=>$t];
            $editFields[] = ['k'=>$f['k'], 't'=>$f['t'], 'type'=>$f['type'], 'opts'=>$opts, 'hint'=>$f['hint'] ?? ''];
        }
        if ($rows) {
            $keys = array_map(fn($r) => $r['key'], $rows);
            $cols = array_map(fn($f) => '`' . $f['k'] . '`', $spec['fields']);
            $in = implode(',', array_fill(0, count($keys), '?'));
            try {
                $q = $db->prepare("SELECT `" . $spec['pk'] . "` AS __k, " . implode(',', $cols)
                                  . " FROM `" . $spec['table'] . "` WHERE `" . $spec['pk'] . "` IN ($in)");
                $q->execute($keys);
                $cur = [];
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                    $k = (string)$cr['__k']; unset($cr['__k']);
                    foreach ($cr as $ck => $cv) {
                        $cur[$k][$ck] = ($cv === null) ? '' : substr((string)$cv, 0, 10);
                        foreach ($spec['fields'] as $f) {
                            if ($f['k'] === $ck && ($f['type'] ?? '') !== 'date') $cur[$k][$ck] = (string)$cv;
                        }
                    }
                }
                foreach ($rows as &$rr) { $rr['edit'] = $cur[(string)$rr['key']] ?? new stdClass(); }
                unset($rr);
            } catch (Throwable $e) { /* 取不到就不給編輯欄位，不影響清單本身 */ }
        }
    }

    jout(['supported'=>1, 'readonly'=>$readonly ? 1 : 0, 'warn'=>$d['warn'] ?? 0, 'cols'=>$d['cols'], 'rows'=>$rows,
          'total'=>$d['total'], 'listed'=>count($d['rows']), 'rule_ex'=>$d['rule_ex'] ?? 0,
          'truncated'=>count($d['rows']) > $cap ? 1 : 0, 'note'=>$d['note'],
          'dims'=>$readonly ? [] : ($d['dims'] ?? []), 'dim_labels'=>kpi_as_dim_labels(),
          'rules'=>$readonly ? [] : kps_excl_rule_rows($db, $iid, $year),
          'can_adjust'=>$canAdjust,
          'mode'=>$em['mode'], 'setting'=>$em['setting'], 'suggest'=>$em['suggest'], 'edit_why'=>$em['why'],
          'edit_fields'=>$editFields, 'can_edit'=>$canEdit,
          'can_set_mode'=>(!empty($perms['canAdmin']) || !empty($perms['isAdmin'])) && !$readonly ? 1 : 0,
          'target'=>['dir'=>$iy['target_direction'], 'value'=>$iy['target_value']]]);
}

/* ---------- 逐筆排除／取消排除（「增減個別調整排除」之一：單一來源列） ---------- */
case 'adjust_add': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = max(1, min(12, (int)($_POST['month'] ?? 0)));
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    if ($iy['source_mode'] !== 'auto' || !kps_detail_supported($calc)) jerr('這個指標不支援排除');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有調整這個指標的權限（限擔當者本人或 KPI 管理員）', 403);
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
    $keys = json_decode((string)($_POST['keys'] ?? '[]'), true);
    $keys = is_array($keys) ? array_values(array_unique(array_filter(array_map('strval', $keys), 'strlen'))) : [];
    if (!$keys) jerr('請選擇要排除的項目');

    $params = kpi_as_params($iy['params_json']);
    $d = kps_detail($db, $calc, $year, $month, $params, kps_excl_rules($db, $iid, $year));
    $valid = [];
    foreach ($d['rows'] as $r) {
        if (!empty($r['rule_ex'])) continue;
        if ((string)($r['kind'] ?? 'bad') !== 'bad') continue;
        $valid[(string)$r['key']] = $r;
    }
    $ins = $db->prepare("INSERT INTO kpi_scheme_adjust
        (indicator_id,year,month,calculator_key,row_key,row_label,row_json,reason,created_by,created_by_name)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE reason=VALUES(reason), created_by=VALUES(created_by),
                                created_by_name=VALUES(created_by_name), created_at=NOW()");
    $done = 0; $skip = 0;
    $db->beginTransaction();
    try {
        foreach ($keys as $k) {
            if (!isset($valid[$k])) { $skip++; continue; }
            $r = $valid[$k];
            $ins->execute([$iid, $year, $month, $calc, $k, mb_substr(implode(' ｜ ', $r['vals']), 0, 250),
                           json_encode($r, JSON_UNESCAPED_UNICODE), $reason,
                           (int)$u['id'], (string)$u['user_cname']]);
            $done++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('寫入失敗：'.$e->getMessage(), 500); }
    if (!$done) jerr('沒有可排除的項目（選到的資料已經不在這個月的清單內，請重新整理）');
    kps_log($db, $iid, $year, $month, 'adjust_add', 'exclude', null, implode(',', $keys),
            '排除 ' . $done . ' 筆' . ($reason !== '' ? ('（原因：' . $reason . '）') : ''), $u);
    jout(['added'=>$done, 'skipped'=>$skip]);
}

case 'adjust_del': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = max(1, min(12, (int)($_POST['month'] ?? 0)));
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有調整這個指標的權限（限擔當者本人或 KPI 管理員）', 403);
    $keys = json_decode((string)($_POST['keys'] ?? '[]'), true);
    $keys = is_array($keys) ? array_values(array_filter(array_map('strval', $keys), 'strlen')) : [];
    if (!$keys) jerr('請選擇要取消排除的項目');
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $db->prepare("DELETE FROM kpi_scheme_adjust WHERE indicator_id=? AND year=? AND month=? AND row_key IN ($in)");
    $st->execute(array_merge([$iid, $year, $month], $keys));
    $n = $st->rowCount();
    if (!$n) jerr('這幾筆本來就沒有被排除（請重新整理）');
    kps_log($db, $iid, $year, $month, 'adjust_del', 'exclude', implode(',', $keys), null, '取消排除 ' . $n . ' 筆', $u);
    jout(['removed'=>$n]);
}

/* ---------- 直接修改來源資料（使用者要求比照 KPI.php 補齊） ----------
   安全邊界：表名／主鍵／欄位／可選值一律取自程式碼裡的白名單（kps_detail_edit_spec），
   請求端只送 row_key 與欄位代號；一定要先確認那一筆真的出現在這一格的不符合標準清單裡
   （鐵律8）。kpi_scheme 沒有快照／settle 概念，改完直接是下次讀取就反映，不必另外觸發
   別的月份重算——這點刻意比官方系統簡單。 */
case 'src_edit': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = max(1, min(12, (int)($_POST['month'] ?? 0)));
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    if ($iy['source_mode'] !== 'auto' || !kps_detail_supported($calc)) jerr('這個指標不支援明細修改');

    $em = kps_edit_mode($db, $iid, $calc);
    if ($em['mode'] !== 'allow') jerr('這個指標的來源資料不開放直接修改（' . $em['why'] . '），請改用「排除」', 403);
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有修改這個指標來源資料的權限（限擔當者本人或 KPI 管理員）', 403);
    $spec = kps_detail_edit_spec($calc);
    if (!$spec) jerr('這個指標沒有可修改的欄位');

    $rowKey = trim((string)($_POST['row_key'] ?? ''));
    $fieldK = trim((string)($_POST['field'] ?? ''));
    $value  = (string)($_POST['value'] ?? '');
    if ($rowKey === '') jerr('缺少資料列');
    $fd = null;
    foreach ($spec['fields'] as $f) { if ($f['k'] === $fieldK) { $fd = $f; break; } }
    if (!$fd) jerr('這個欄位不開放修改');

    $params = kpi_as_params($iy['params_json']);
    $d = kps_detail($db, $calc, $year, $month, $params, kps_excl_rules($db, $iid, $year));
    $hit = null;
    foreach ($d['rows'] as $r) { if ((string)$r['key'] === $rowKey) { $hit = $r; break; } }
    if (!$hit) jerr('這一筆已經不在本月的清單內（可能別人剛改過），請重新整理後再試');

    $val = $value;
    if (($fd['type'] ?? '') === 'select') {
        if (!isset($fd['opts'][$val])) jerr('選項不正確');
    } elseif (($fd['type'] ?? '') === 'date') {
        if ($val === '') {
            if (empty($fd['nullable'])) jerr('這個欄位不可留空');
            $val = null;
        } elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val, $mm) || !checkdate((int)$mm[2], (int)$mm[3], (int)$mm[1])) {
            jerr('日期格式不正確');
        }
    } else {
        jerr('欄位型態不支援');
    }

    $table = $spec['table']; $pk = $spec['pk'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $pk)
        || !preg_match('/^[A-Za-z0-9_]+$/', $fieldK)) jerr('設定不正確');

    $st = $db->prepare("SELECT `$fieldK` FROM `$table` WHERE `$pk`=?");
    $st->execute([$rowKey]);
    if ($st->rowCount() === 0) jerr('來源資料不存在');
    $oldVal = $st->fetchColumn();
    $oldStr = $oldVal === null ? '' : (string)$oldVal;
    $newStr = $val === null ? '' : (string)$val;
    if (substr($oldStr, 0, 10) === substr($newStr, 0, 10) && strlen($oldStr) && strlen($newStr)) {
        if ($oldStr === $newStr || (($fd['type'] ?? '') === 'date')) jerr('值沒有變更');
    }

    $sets = ["`$fieldK`=?"]; $bind = [$val];
    if (!empty($spec['stamp']['by'])) { $sets[] = "`" . $spec['stamp']['by'] . "`=?"; $bind[] = (string)$u['user_cname']; }
    if (!empty($spec['stamp']['at'])) { $sets[] = "`" . $spec['stamp']['at'] . "`=NOW()"; }
    $bind[] = $rowKey;
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE `$table` SET " . implode(',', $sets) . " WHERE `$pk`=?")->execute($bind);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('寫入失敗：' . $e->getMessage(), 500); }

    // 全站稽核紀錄（改的是別的模組的資料，kpi_scheme 沒有自己的 change_log，寫這裡才查得到）
    try {
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES ('kpi_scheme_src_edit', ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$table, (string)$rowKey, mb_substr(implode(' ｜ ', $hit['vals']), 0, 100),
                      json_encode(['field'=>$fieldK, 'old'=>$oldStr, 'new'=>$newStr,
                                   'indicator_id'=>$iid, 'year'=>$year, 'month'=>$month],
                                  JSON_UNESCAPED_UNICODE),
                      (int)$u['id'], (string)$u['user_cname']]);
    } catch (Throwable $e) {}
    kps_log($db, $iid, $year, $month, 'src_edit', $table . '.' . $fieldK, $oldStr, $newStr,
            '由數值明細修改來源資料（' . $spec['pk'] . '=' . $rowKey . '）', $u);

    // 重新即時算這一格（不必另外觸發別的月份重算，kpi_scheme 本來就沒有快照）；
    // 若改的是「決定算在哪個月」的欄位，順便告訴使用者這一筆以後會改算到哪個月（純提示，不代為處理）。
    $also = null;
    if (!empty($fd['remonth'])) {
        $t = kps_edit_target_ym($calc, $fieldK, $newStr);
        if ($t && !($t[0] === $year && $t[1] === $month)) $also = $t[0] . '年' . $t[1] . '月';
    }
    $exclRows = kps_adjust_keys($db, $iid, $year, $month);
    $rules = kps_excl_rules($db, $iid, $year);
    $res = kpi_scheme_compute_by_key($db, $calc, $year, $month, $params, $exclRows, $rules);
    // 浮點數直接 json_encode 在這台環境的 serialize_precision 下會印出一長串誤差尾巴，
    // 一律送「乾淨的字串」給前端（前端拿去顯示或 parseFloat 都一樣），不要送裸 float。
    jout(['old'=>$oldStr, 'new'=>$newStr, 'also_month'=>$also,
          'value'=>($res && $res['v'] !== null) ? number_format((float)$res['v'], 2, '.', '') : null,
          'num'=>$res['num'] ?? null, 'den'=>$res['den'] ?? null]);
}

/* ---------- 管理員設定：這個指標的來源資料可不可以直接改 ---------- */
case 'edit_mode_save': {
    if (empty($perms['canAdmin']) && empty($perms['isAdmin'])) jerr('僅KPI管理者可設定', 403);
    $iid  = (int)($_POST['indicator_id'] ?? 0);
    $mode = (string)($_POST['mode'] ?? '');
    if (!in_array($mode, ['suggest', 'allow', 'deny'], true)) jerr('設定值不正確');
    $st = $db->prepare("SELECT 1 FROM kpi_scheme_indicator WHERE indicator_id=?");
    $st->execute([$iid]);
    if (!$st->fetchColumn()) jerr('找不到指標');
    kps_edit_mode_save($db, $iid, $mode, (string)$u['user_cname']);
    kps_log($db, $iid, null, null, 'edit_mode', 'src_edit_mode', null, $mode, '來源資料可否直接修改', $u);
    jout(['mode'=>$mode]);
}

/* ---------- 排除規則的候選查詢（整年度依維度排除；只有重用官方引擎的 calc 才有 dims） ---------- */
case 'excl_dim_search': {
    $iid  = (int)($_GET['indicator_id'] ?? 0);
    $year = (int)($_GET['year'] ?? $curY);
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    $dim  = trim((string)($_GET['dim'] ?? ''));
    if (!in_array($dim, kps_calc_dims($calc), true)) jerr('這個指標沒有這種排除維度');
    $asMap = kps_as_delegate_map();
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        // 年度候選清單借用官方的「這年度真的有的值」查詢（以轉呼叫的官方 calc key 查）
        jout(['dim'=>$dim, 'q'=>'', 'src'=>'本年度',
              'rows'=>kpi_as_dim_year_values($db, $asMap[$calc] ?? null, $dim, $year, 300)]);
    }
    jout(['dim'=>$dim, 'q'=>$q, 'src'=>'主檔', 'rows'=>kpi_as_dim_lookup($db, $dim, $q, 50)]);
}

case 'excl_rule_add': {
    $iid  = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    if ($iy['source_mode'] !== 'auto' || !kps_detail_supported($calc)) jerr('這個指標不支援排除');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有調整這個指標的權限（限擔當者本人或 KPI 管理員）', 403);
    $scope = ((string)($_POST['scope'] ?? 'year') === 'all') ? 'all' : 'year';
    $rowYear = ($scope === 'all') ? 0 : $year;
    $dim = trim((string)($_POST['dim'] ?? ''));
    if (!in_array($dim, kps_calc_dims($calc), true)) jerr('這個指標沒有這種排除維度');
    $vals = json_decode((string)($_POST['vals'] ?? '[]'), true);
    $vals = is_array($vals) ? array_values(array_unique(array_filter(array_map(function ($v) {
        return mb_substr(trim((string)$v), 0, 190);
    }, $vals), 'strlen'))) : [];
    if (!$vals) jerr('請選擇要排除的項目');
    if (count($vals) > 200) jerr('一次最多 200 項');
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);

    $ins = $db->prepare("INSERT INTO kpi_scheme_excl_rule (indicator_id,year,scope,dim,val,reason,created_by,created_by_name)
                         VALUES (?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE scope=VALUES(scope), reason=VALUES(reason),
                                                 created_by=VALUES(created_by),
                                                 created_by_name=VALUES(created_by_name), created_at=NOW()");
    $delNarrow = $db->prepare("DELETE FROM kpi_scheme_excl_rule WHERE indicator_id=? AND dim=? AND val=? AND scope='year'");
    $db->beginTransaction();
    try {
        foreach ($vals as $v) {
            if ($scope === 'all') $delNarrow->execute([$iid, $dim, $v]);
            $ins->execute([$iid, $rowYear, $scope, $dim, $v, ($reason !== '' ? $reason : null),
                           (int)$u['id'], (string)$u['user_cname']]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('寫入失敗：'.$e->getMessage(), 500); }
    kps_log($db, $iid, ($scope === 'all' ? null : $year), null, 'excl_rule_add', 'excl_' . $dim, null,
            implode(',', $vals), '新增排除規則 ' . count($vals) . ' 項（適用：' . ($scope === 'all' ? '所有年度' : ($year . ' 年度')) . '）', $u);
    jout(['added'=>count($vals), 'scope'=>$scope, 'rules'=>kps_excl_rule_rows($db, $iid, $year)]);
}

case 'excl_rule_del': {
    $iid  = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有調整這個指標的權限（限擔當者本人或 KPI 管理員）', 403);
    $ids = json_decode((string)($_POST['rule_ids'] ?? '[]'), true);
    $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    if (!$ids) jerr('請選擇要取消的規則');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("DELETE FROM kpi_scheme_excl_rule WHERE indicator_id=? AND rule_id IN ($in)");
    $st->execute(array_merge([$iid], $ids));
    $n = $st->rowCount();
    if (!$n) jerr('這幾條規則本來就不存在（請重新整理）');
    kps_log($db, $iid, $year, null, 'excl_rule_del', 'excl_rule', implode(',', $ids), null, '取消排除規則 ' . $n . ' 條', $u);
    jout(['removed'=>$n, 'rules'=>kps_excl_rule_rows($db, $iid, $year)]);
}

/* ---------- 單一欄位修改：人工填寫（manual）／手動覆寫（auto） ---------- */
case 'fill': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if ($iy['source_mode'] !== 'manual') jerr('此指標為自動計算，如需修正請用手動覆寫功能');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有填寫這個指標的權限（限擔當者本人或 KPI 管理員）', 403);
    if (!in_array($month, kpi_as_valid_months($iy), true)) jerr('該指標此月份不適用（頻率：'.$iy['freq'].'）');
    if ($year === $curY && $month > $curM) jerr('不可填寫未來月份');
    $raw = trim((string)($_POST['value'] ?? ''));
    if ($raw === '') jerr('請輸入數值');
    $val = kpi_as_parse_input((string)$iy['value_type'], $raw);
    if ($val === null) jerr('「'.$raw.'」無法辨識，'.kpi_as_input_hint((string)$iy['value_type']));
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
    kps_fill_save($db, $iid, $year, $month, $val, $note, (int)$u['id'], (string)$u['user_cname']);
    kps_log($db, $iid, $year, $month, 'fill', 'manual_value', null, (string)$val, $note ?: null, $u);
    jout(['value'=>$val]);
}

case 'clear_fill': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有清除這個指標填寫內容的權限', 403);
    kps_fill_clear($db, $iid, $year, $month);
    kps_log($db, $iid, $year, $month, 'fill', 'manual_value', null, null, '清除填寫', $u);
    jout([]);
}

case 'override': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if ($iy['source_mode'] !== 'auto') jerr('人工填寫的指標請用「填寫/修改」');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有覆寫這個指標的權限（限擔當者本人或 KPI 管理員）', 403);
    if (!in_array($month, kpi_as_valid_months($iy), true)) jerr('該指標此月份不適用');
    $raw = trim((string)($_POST['value'] ?? ''));
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
    if ($raw === '') jerr('請輸入覆寫值');
    if ($reason === '') jerr('覆寫原因必填（AS9100 可追溯要求）');
    $val = kpi_as_parse_input((string)$iy['value_type'], $raw);
    if ($val === null) jerr('「'.$raw.'」無法辨識，'.kpi_as_input_hint((string)$iy['value_type']));
    kps_override_save($db, $iid, $year, $month, $val, $reason, (int)$u['id'], (string)$u['user_cname']);
    kps_log($db, $iid, $year, $month, 'override', 'override_value', null, (string)$val, $reason, $u);
    jout(['value'=>$val]);
}

case 'clear_override': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('您沒有清除這個指標覆寫值的權限', 403);
    kps_override_clear($db, $iid, $year, $month);
    kps_log($db, $iid, $year, $month, 'override', 'override_value', null, null, '清除覆寫', $u);
    jout([]);
}

/* ---------- 補登模式：整張表直接填寫（管理員補資料用，比照 KPI.php 的 bulk_override） ----------
   僅系統管理員；只能補「已結束的月份」；不論該指標是 auto 還是 manual，一律寫成 override_value
   （顯示優先序最高），不要求逐格填覆寫原因（整批共用同一句說明，誰在什麼時候補的仍留在
   override_by／override_at）；空字串＝清掉這一格的覆寫。 */
case 'bulk_override': {
    if (empty($perms['isAdmin']) && empty($perms['canAdmin'])) jerr('僅 KPI 管理員／系統管理員可使用補登模式', 403);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2020 || $year > $curY + 2) jerr('年度不合法');
    $cells = json_decode((string)($_POST['cells'] ?? '[]'), true);
    if (!is_array($cells) || !$cells) jerr('沒有要寫入的資料');
    if (count($cells) > 500) jerr('一次最多 500 格');
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
    if ($note === '') $note = '補登舊年度資料（補登模式整批填寫）';

    $rows = [];
    foreach (kpi_scheme_list_year($db, $year) as $r) {
        if ((int)$r['ind_active'] !== 1 || (int)$r['year_active'] !== 1) continue;
        $rows[(int)$r['indicator_id']] = $r;
    }

    $set = $db->prepare("INSERT INTO kpi_scheme_monthly_value
            (indicator_id,year,month,override_value,override_by,override_by_name,override_at,override_reason)
            VALUES (?,?,?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE override_value=VALUES(override_value), override_by=VALUES(override_by),
                    override_by_name=VALUES(override_by_name), override_at=NOW(), override_reason=VALUES(override_reason)");
    $clr = $db->prepare("UPDATE kpi_scheme_monthly_value
            SET override_value=NULL, override_by=NULL, override_by_name=NULL, override_at=NULL, override_reason=NULL
            WHERE indicator_id=? AND year=? AND month=?");
    $saved = 0; $cleared = 0; $skipped = [];
    $db->beginTransaction();
    try {
        foreach ($cells as $c) {
            $iid = (int)($c['i'] ?? 0);
            $m   = (int)($c['m'] ?? 0);
            $raw = trim((string)($c['v'] ?? ''));
            $row = $rows[$iid] ?? null;
            if (!$row) { $skipped[] = "指標 $iid 不存在"; continue; }
            if (!in_array($m, kpi_as_valid_months($row), true)) { $skipped[] = $row['name'] . " {$m}月 不適用"; continue; }
            if (!kps_month_ended($year, $m)) { $skipped[] = $row['name'] . " {$m}月 尚未結束"; continue; }
            if ($raw === '') {
                $clr->execute([$iid, $year, $m]);
                if ($clr->rowCount()) { $cleared++; kps_log($db, $iid, $year, $m, 'bulk_override', 'override_value', null, null, '補登模式清除', $u); }
                continue;
            }
            $val = kpi_as_parse_input((string)$row['value_type'], $raw);
            if ($val === null) {
                $skipped[] = $row['name'] . " {$m}月「{$raw}」無法辨識（" . kpi_as_input_hint((string)$row['value_type']) . '）';
                continue;
            }
            $set->execute([$iid, $year, $m, $val, (int)$u['id'], (string)$u['user_cname'], $note]);
            kps_log($db, $iid, $year, $m, 'bulk_override', 'override_value', null, (string)$val, $note, $u);
            $saved++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('寫入失敗：' . $e->getMessage(), 500); }

    jout(['saved'=>$saved, 'cleared'=>$cleared, 'skipped'=>$skipped]);
}

/* ---------- 佐證附件 ---------- */
case 'attach_list': {
    $iid = (int)($_GET['indicator_id'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);
    $month = (int)($_GET['month'] ?? 0);
    $st = $db->prepare("SELECT attach_id, file_name, original_name, file_size, note, uploaded_by, uploaded_by_name, created_at
                        FROM kpi_scheme_attachment WHERE indicator_id=? AND year=? AND month=? ORDER BY attach_id");
    $st->execute([$iid, $year, $month]);
    $list = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['can_delete'] = !empty($perms['canAdmin']) || (int)$r['uploaded_by'] === (int)$u['id'];
        $r['exists'] = kps_attach_path($db, array_merge($r, ['year'=>$year])) !== null;
        unset($r['file_name']);
        $list[] = $r;
    }
    jout(['list'=>$list, 'max'=>kps_attach_max($db)]);
}

case 'attach_upload': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if ($year < 2020 || $year > $curY + 2) jerr('年度不合法');
    $iy = kps_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if (!in_array($month, kpi_as_valid_months($iy), true)) jerr('該指標此月份不適用');
    $ownerId = $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null;
    if (!kps_can_edit($perms, $ownerId, (int)$u['id'])) jerr('僅該指標擔當者本人或 KPI 管理員可上傳佐證', 403);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('檔案上傳失敗');
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) jerr('檔案超過 20MB');
    $allowed = ['jpg','jpeg','png','gif','webp','bmp','pdf','xls','xlsx','xlsm','xlsb','doc','docx','docm',
                'ppt','pptx','csv','txt','zip','7z','rar','odt','ods'];
    $orig = basename((string)$_FILES['file']['name']);
    if (!mb_check_encoding($orig, 'UTF-8')) {
        $conv = @mb_convert_encoding($orig, 'UTF-8', 'BIG-5');
        $orig = ($conv !== false && mb_check_encoding($conv, 'UTF-8')) ? $conv : ('附件_' . date('Ymd_His'));
    }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) jerr('不支援的檔案格式：' . $ext);
    $max = kps_attach_max($db);
    $st = $db->prepare("SELECT COUNT(*) FROM kpi_scheme_attachment WHERE indicator_id=? AND year=? AND month=?");
    $st->execute([$iid, $year, $month]);
    if ((int)$st->fetchColumn() >= $max) jerr("此月份佐證已達上限 {$max} 件");
    $base = kps_attach_dir($db);
    $dir = rtrim($base, '\\/') . DIRECTORY_SEPARATOR . $year;
    if (!eg_attach_ensure_dir($dir)) jerr('無法建立附件目錄，請確認NAS路徑設定：' . $dir);
    $fname = 'kps' . $iid . '_' . sprintf('%02d', $month) . '_' . date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $dir . DIRECTORY_SEPARATOR . $fname;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) jerr('檔案寫入失敗');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!mb_check_encoding($note, 'UTF-8')) {
        $conv = @mb_convert_encoding($note, 'UTF-8', 'BIG-5');
        $note = ($conv !== false && mb_check_encoding($conv, 'UTF-8')) ? $conv : '';
    }
    $note = mb_substr($note, 0, 200);
    // DB 寫不進去就要把剛落地的實體檔 unlink 掉，不然 NAS 上會留一個沒人認得的孤兒檔
    // （本專案 ia_attach 已踩過同一個坑，見記憶）。
    try {
        $st = $db->prepare("INSERT INTO kpi_scheme_attachment (indicator_id,year,month,file_name,original_name,file_size,note,uploaded_by,uploaded_by_name)
                            VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([$iid, $year, $month, $fname, $orig, (int)$_FILES['file']['size'], $note, (int)$u['id'], (string)$u['user_cname']]);
    } catch (Throwable $e) {
        @unlink($destPath);
        jerr('寫入失敗：' . $e->getMessage(), 500);
    }
    kps_log($db, $iid, $year, $month, 'attach', 'upload', null, $orig, $note ?: null, $u);
    jout(['attach_id'=>(int)$db->lastInsertId()]);
}

case 'attach_delete': {
    $aid = (int)($_POST['attach_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM kpi_scheme_attachment WHERE attach_id=?");
    $st->execute([$aid]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) jerr('找不到附件');
    if (!(!empty($perms['canAdmin']) || (int)$att['uploaded_by'] === (int)$u['id'])) jerr('僅上傳者或管理者可刪除', 403);
    $p = kps_attach_path($db, $att);
    if ($p) @unlink($p);
    $db->prepare("DELETE FROM kpi_scheme_attachment WHERE attach_id=?")->execute([$aid]);
    kps_log($db, (int)$att['indicator_id'], (int)$att['year'], (int)$att['month'], 'attach', 'delete',
            (string)$att['original_name'], null, null, $u);
    jout([]);
}

case 'attach_open': {
    $aid = (int)($_GET['attach_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM kpi_scheme_attachment WHERE attach_id=?");
    $st->execute([$aid]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) jerr('找不到附件', 404);
    $p = kps_attach_path($db, $att);
    if (!$p) jerr('檔案不存在（可能NAS路徑已變更或檔案被移除）', 404);
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    $inline = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
               'gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','txt'=>'text/plain; charset=utf-8'];
    header_remove('Content-Type');
    if (isset($inline[$ext])) header('Content-Type: ' . $inline[$ext]);
    else header('Content-Type: application/octet-stream');
    eg_attach_send_disposition((string)($att['original_name'] ?: basename($p)));
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

/* ---------- 變更歷史（使用者要求：KPI.php 連畫面都沒有，本方案額外補的查看功能） ---------- */
case 'change_log': {
    $iid = (int)($_GET['indicator_id'] ?? 0);
    if ($iid <= 0) jerr('缺少指標');
    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;
    $rows = kps_log_rows($db, $iid, $year, 300);
    jout(['rows'=>$rows]);
}

default:
    jerr('不支援的操作：'.$action, 400);
}
