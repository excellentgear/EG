<?php
/**
 * KpiSchemeCny_API.php — KPI 新方案（草案）春節目標調整，唯一的寫入端點
 *
 * 只做兩件事：
 *   list : 任何有 KPI 檢閱權的人都能看（列出全年 12 個月的自動比例＋管理員額外調整率）
 *   save : 僅 KPI 管理者可寫（kps_cny_override_save()，見 kpi_scheme_lib.php「五、春節目標調整」）
 *
 * 權限沿用既有 KPI 模組角色（kpi_as_current_user/kpi_as_perms），不另開角色——
 * 這是草案頁的輔助設定，不是獨立功能，沒有理由另外管一組權限。
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

default:
    jerr('不支援的操作：'.$action, 400);
}
