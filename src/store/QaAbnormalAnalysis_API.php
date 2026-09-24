<?php
/**
 * QaAbnormalAnalysis_API.php — 品質異常分析（views/QA/qa_abnormal_analysis.php）唯一資料端點
 * 建立：2026-09-24
 *
 * 權限：qa_abnormal 模組角色 qab_analysis_view（檢視）／qab_analysis_admin（設定）；
 * 異常單管理員(qab_admin)與系統管理員自動涵蓋兩者。前端擋一次、後端同規則再擋一次（鐵律8）。
 * 一律即時算、不存快照——異常單隨時在填寫/裁示，存起來的數字只會停在算那天。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/qa_abnormal_analysis_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';

set_exception_handler(function ($e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

function qaaOut(array $a = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE);
    exit;
}
function qaaErr(string $msg, int $code = 400, array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
qaa_ensure($db);

$uid = (int)($_SESSION['id'] ?? 0);
if (!$uid) qaaErr('未登入或連線已逾時，請重新登入', 401);

$perms = qaa_perms($db, $uid);
if (!$perms['canAView']) qaaErr('您沒有品質異常分析的檢視權限，請洽管理員於「品質異常處理單」角色設定開通', 403);

if (empty($_SESSION['qaa_csrf'])) $_SESSION['qaa_csrf'] = bin2hex(random_bytes(16));
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$WRITE = ['settings_save'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['qaa_csrf'], $tok)) {
        qaaErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403, ['code' => 'CSRF']);
    }
}

/** 依前端傳來的 year/gran/idx/cmp 算出目前期別與比較期別（口徑一律過白名單） */
function qaaResolvePeriod(): array
{
    $gran = (string)($_REQUEST['gran'] ?? 'month');
    if (!isset(oa_grans()[$gran])) qaaErr('不合法的期間粒度');
    $cmp = (string)($_REQUEST['cmp'] ?? 'yoy');
    if ($cmp !== 'none' && !isset(oa_compares()[$cmp])) qaaErr('不合法的比較基準');
    $year = (int)($_REQUEST['year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) qaaErr('不合法的年度');
    $idx = (int)($_REQUEST['idx'] ?? 1);
    $period = oa_period_pick($year, $gran, $idx);
    $cmpPeriod = $cmp === 'none' ? null : oa_compare_period($year, $gran, $idx, $cmp);
    return [$period, $cmpPeriod, $gran, $cmp, $year, $idx];
}

switch ($action) {

    /* ── 分析主體：一次回傳整份儀表板需要的資料 ─────────────────────────── */
    case 'analyze': {
        [$period, $cmpPeriod, $gran, $cmp, $year, $idx] = qaaResolvePeriod();
        $filters = [];
        if (!empty($_REQUEST['source']) && in_array($_REQUEST['source'], ['IR', 'QC', 'BOM'], true)) $filters['source'] = $_REQUEST['source'];
        if (!empty($_REQUEST['kw'])) $filters['kw'] = trim((string)$_REQUEST['kw']);

        $settings = qaa_settings_get($db);
        $rows = qaa_load_rows($db, $period, $filters);
        $kCur = qaa_kpi_one($rows);
        $kCmp = null;
        if ($cmpPeriod) {
            $cmpRows = qaa_load_rows($db, $cmpPeriod, $filters);
            $kCmp = qaa_kpi_one($cmpRows);
        }

        $trend       = qaa_trend($db, $year, $gran, $filters);
        $paretoPart  = qaa_pareto_part($rows);
        $paretoCause = qaa_pareto_cause($db, $rows);
        $paretoSrc   = qaa_pareto_source($rows);
        $disposition = qaa_disposition_dist($rows);
        $copqDisp    = qaa_copq_by_disposition($rows);
        $srcType     = qaa_source_type_dist($rows);
        $agingAll    = qaa_aging_list($db, $settings);
        $agingOver   = array_values(array_filter($agingAll, function ($a) { return !empty($a['over']); }));
        $recurrence  = qaa_recurrence($db, $settings);

        $cmpLabel = $cmpPeriod ? ($cmp === 'yoy' ? '去年同期' : '上一期') : '';
        $ins = qaa_insights([
            'kpi'  => ['cur' => $kCur, 'cmp' => $kCmp],
            'cmp_label' => $cmpLabel,
            'trend' => $trend,
            'pareto_part' => $paretoPart,
            'pareto_source' => $paretoSrc,
            'aging_over_count' => count($agingOver),
            'recurrence_count' => count($recurrence),
            'recurrence_months' => $settings['recurrence_months'],
        ]);

        qaaOut([
            'meta' => [
                'period' => $period, 'cmp_period' => $cmpPeriod, 'cmp_label' => $cmpLabel,
                'gran' => $gran, 'gran_label' => oa_grans()[$gran], 'cmp' => $cmp,
                'company' => eg_company_full_name($db),
                'print_time' => date('Y-m-d H:i:s'),
                'settings' => $settings,
            ],
            'kpi' => ['cur' => $kCur, 'cmp' => $kCmp],
            'trend' => $trend,
            'pareto_part' => $paretoPart,
            'pareto_cause' => $paretoCause,
            'pareto_source' => $paretoSrc,
            'disposition' => $disposition,
            'copq_by_disposition' => $copqDisp,
            'source_type' => $srcType,
            'aging' => $agingAll,
            'aging_over_count' => count($agingOver),
            'recurrence' => $recurrence,
            'insights' => $ins,
            'perm' => ['canAAdmin' => $perms['canAAdmin'] ? 1 : 0],
        ]);
    }

    /* ── 設定 ─────────────────────────────────────────────────────────── */
    case 'settings_get': {
        qaaOut(['settings' => qaa_settings_get($db), 'defaults' => qaa_settings_default(),
                'canAAdmin' => $perms['canAAdmin'] ? 1 : 0, 'csrf' => $_SESSION['qaa_csrf']]);
    }

    case 'settings_save': {
        if (!$perms['canAAdmin']) qaaErr('您沒有品質異常分析設定的權限（qab_analysis_admin）', 403);
        $s = json_decode((string)($_POST['settings'] ?? 'null'), true);
        if (!is_array($s)) qaaErr('設定格式不正確');
        $uname = (string)($_SESSION['userName'] ?? $uid);
        $sv = qaa_settings_save($db, $s, $uname);
        qaaOut(['settings' => $sv['settings']]);
    }

    default:
        qaaErr('無效的操作');
}
