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
    try { $iid = kpi_scheme_ind_save($db, $_POST, $year, (string)($u['user_cname'] ?? '')); }
    catch (Throwable $e) { jerr($e->getMessage()); }
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
    jout([]);
}

case 'preview_compute': {
    if (!$perms['canAdmin']) jerr('僅 KPI 管理者可設定', 403);
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
    jout(['result'=>$r]);
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
    if ($iy['source_mode'] !== 'auto' || !kps_detail_supported($calc)) {
        jout(['supported'=>0, 'rows'=>[], 'cols'=>[],
              'msg'=>$iy['source_mode'] !== 'auto' ? '人工填寫的指標沒有來源明細。'
                                                   : '這個計算方式還沒有做數值明細。']);
    }
    $params = kpi_as_params($iy['params_json']);
    $rules  = kps_excl_rules($db, $iid, $year);
    $d = kps_detail($db, $calc, $year, $month, $params, $rules);
    $adj = [];
    foreach (kps_adjust_rows($db, $iid, $year, $month) as $a) $adj[(string)$a['row_key']] = $a;
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
    jout(['supported'=>1, 'warn'=>$d['warn'] ?? 0, 'cols'=>$d['cols'], 'rows'=>$rows,
          'total'=>$d['total'], 'listed'=>count($d['rows']), 'rule_ex'=>$d['rule_ex'] ?? 0,
          'truncated'=>count($d['rows']) > $cap ? 1 : 0, 'note'=>$d['note'],
          'dims'=>$d['dims'] ?? [], 'dim_labels'=>kpi_as_dim_labels(),
          'rules'=>kps_excl_rule_rows($db, $iid, $year),
          'can_adjust'=>kps_can_edit($perms, $iy['owner_user_id'] !== null ? (int)$iy['owner_user_id'] : null,
                                     (int)$u['id']) ? 1 : 0,
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
    jout(['removed'=>$n]);
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
    jout([]);
}

default:
    jerr('不支援的操作：'.$action, 400);
}
