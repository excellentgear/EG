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
    try { $officialItems = $db->query("SELECT item_no, name FROM kpi_as_indicator ORDER BY item_no")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

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

default:
    jerr('不支援的操作：'.$action, 400);
}
