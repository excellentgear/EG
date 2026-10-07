<?php
/**
 * UnitLoad_API.php — 各單位負荷分析（views/ADM/unit_load.php）唯一資料端點
 * 建立：2026-10-07（新模組開發第三階段：API 端點）
 *
 * 骨架完全比照 OrderAnalysis_API.php（session/CSRF/權限判斷方式一致，只換模組名稱與功能碼）。
 * 權限：unit_load_view（檢視）／unit_load_admin（設定：各單位部門勾選、過重門檻），
 * 兩者的判定一律呼叫 unit_load_lib.php 的 ul_can_view()／ul_can_admin()，不在這裡另寫一次。
 *
 * 人員 id 參數的格式提醒（讀 unit_load_lib.php 的 ul_ids_norm() 得到的結論）：
 *   本檔所有 ul_design_*／ul_sales_*／ul_pm_*／ul_prod_*／ul_qc_* 函式，凡是吃「人員 id 陣列」
 *   的參數，一律是「純整數陣列」——函式內部用 ul_ids_norm() 對它逐一 intval()，傳整列
 *   eg_people_list() 回傳的關聯陣列進去，intval(非空陣列) 會被 PHP 當成 1，等於全部人都變成
 *   同一個假 id。故本檔一律先呼叫 ul_dept_user_ids() 取得完整人員列（供畫面顯示姓名/部門/
 *   職稱），再用 array_column(...,'id') 轉成純整數陣列才交給下面這些計算函式。
 *
 * 一律即時算、不做快照：跟訂單分析同一個理由，資料隨時在變，存起來的數字只會讓人以為
 * 現在就是這樣。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/unit_load_lib.php';
include_once $document_root . '/EGsystem/src/common/order_analysis_lib.php';
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';

// 未捕捉的例外一律轉成 JSON——不然畫面上只會是「按了完全沒反應」的空白 500
set_exception_handler(function ($e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

function ulOut(array $a = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE);
    exit;
}
function ulErr(string $msg, int $code = 400, array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/** 這個單位鍵（design/sales/pm/prod/qc）在設定裡勾選的部門展開出來的人員，只取 id（純整數陣列） */
function ulUnitIds(PDO $db, array $settings, string $unitKey): array {
    $rows = ul_dept_user_ids($db, $settings, $unitKey);
    return array_values(array_unique(array_map(fn($r) => (int)$r['id'], $rows)));
}

/**
 * 解析期間參數（比照 order_analysis_lib.php 的 oa_period_buckets/oa_period_pick/oa_compare_period，
 * 這五個 data_* action 與 overview 共用同一套解析，不要各自重複解析一次）。
 * 口徑參數一律過白名單——不吃前端隨便送的字串（會被拼進別處的查詢/判斷）。
 * @return array ['year','gran','idx','cmp','from','to','label','cmp_from','cmp_to','cmp_label']
 */
function ulPeriodParse(array $req): array {
    $year = (int)($req['year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) ulErr('不合法的年度');
    $gran = (string)($req['gran'] ?? 'month');
    if (!isset(oa_grans()[$gran])) ulErr('不合法的期間粒度');
    $idx = (int)($req['period_idx'] ?? $req['idx'] ?? 1);
    if ($idx < 1) $idx = 1;
    $cmp = (string)($req['cmp'] ?? 'prev');
    if (!isset(oa_compares()[$cmp])) ulErr('不合法的比較基準');

    $cur = oa_period_pick($year, $gran, $idx);
    $cmpP = oa_compare_period($year, $gran, $idx, $cmp);

    return [
        'year' => $year, 'gran' => $gran, 'idx' => (int)$cur['idx'], 'cmp' => $cmp,
        'label' => $cur['label'], 'from' => $cur['start'], 'to' => $cur['end'],
        'cmp_label' => $cmpP['label'], 'cmp_from' => $cmpP['start'], 'cmp_to' => $cmpP['end'],
    ];
}

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid = (int)($_SESSION['id'] ?? 0);
if (!$uid) ulErr('未登入或連線已逾時，請重新登入', 401);

$feat     = rf_load_user_features_all($db, $uid);
$isAdmin  = in_array('all', $feat, true);
$canView  = ul_can_view($db, $uid, $feat);
$canAdmin = ul_can_admin($db, $uid, $feat);
if (!$canView) ulErr('您沒有各單位負荷分析的檢視權限，請洽管理員開通 unit_load_view', 403);

if (empty($_SESSION['ul_csrf'])) $_SESSION['ul_csrf'] = bin2hex(random_bytes(16));
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 寫入類先驗登入（上面已驗）再驗 CSRF；順序不可顛倒——session 被 GC 掃掉時 token 會在同一個
// 請求裡重新產生、比對必定不過，那是「已被登出」不是 CSRF 攻擊，訊息要分開講清楚。
$WRITE = ['settings_save'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['ul_csrf'], $tok)) {
        ulErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403, ['code' => 'CSRF']);
    }
    if (!$canAdmin) ulErr('您沒有各單位負荷分析的設定權限（unit_load_admin）', 403);
}

switch ($action) {

    /* ── 部門樹（設定頁勾選部門用）─────────────────────────────── */
    case 'dept_tree': {
        $rows = $db->query("SELECT id,name,parent_id,level,sort_order FROM department ORDER BY sort_order,id")
                   ->fetchAll(PDO::FETCH_ASSOC);
        $hasChild = [];
        foreach ($rows as $r) {
            if ($r['parent_id'] !== null) $hasChild[(int)$r['parent_id']] = true;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'          => (int)$r['id'],
                'name'        => (string)$r['name'],
                'parent_id'   => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                'level'       => (int)$r['level'],
                'sort_order'  => (int)$r['sort_order'],
                'has_children'=> !empty($hasChild[(int)$r['id']]),
            ];
        }
        ulOut(['depts' => $out]);
    }

    /* ── 設定讀取（部門勾選＋過重門檻）─────────────────────────── */
    case 'settings_get': {
        $s = ul_settings($db);
        ulOut([
            'dept_cfg'          => $s['dept_cfg'],
            'thresholds'        => $s['thresholds'],
            'threshold_defaults'=> ul_threshold_defaults(),
            'unit_keys'         => ul_unit_keys(),
            'canAdmin'          => $canAdmin ? 1 : 0,
            'csrf'              => $_SESSION['ul_csrf'],
        ]);
    }

    /* ── 設定存檔（僅 unit_load_admin，CSRF 已於上方驗過）────────── */
    case 'settings_save': {
        $deptCfgRaw = $_POST['dept_cfg'] ?? '[]';
        $thrRaw     = $_POST['thresholds'] ?? '[]';
        $deptCfg = is_string($deptCfgRaw) ? json_decode($deptCfgRaw, true) : $deptCfgRaw;
        $thr     = is_string($thrRaw) ? json_decode($thrRaw, true) : $thrRaw;
        if (!is_array($deptCfg)) ulErr('部門設定格式不正確');
        if (!is_array($thr))     ulErr('門檻設定格式不正確');

        $saved = ul_settings_save($db, ['dept_cfg' => $deptCfg, 'thresholds' => $thr], $uid);
        ulOut([
            'dept_cfg'          => $saved['dept_cfg'],
            'thresholds'        => $saved['thresholds'],
            'threshold_defaults'=> ul_threshold_defaults(),
        ]);
    }

    /* ── 總覽：五張 KPI 卡的核心數字＋整頁自動分析 ─────────────── */
    case 'overview': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];

        $designIds = ulUnitIds($db, $settings, 'design');
        $salesIds  = ulUnitIds($db, $settings, 'sales');
        $pmIds     = ulUnitIds($db, $settings, 'pm');
        $prodIds   = ulUnitIds($db, $settings, 'prod');
        $qcIds     = ulUnitIds($db, $settings, 'qc');

        $designCur = ul_design_summary($db, $p['from'], $p['to'], $designIds);
        $designCmp = ul_design_summary($db, $p['cmp_from'], $p['cmp_to'], $designIds);

        $salesCur = ul_sales_summary($db, $p['from'], $p['to'], $salesIds);
        $salesCmp = ul_sales_summary($db, $p['cmp_from'], $p['cmp_to'], $salesIds);

        $pmCur = ul_pm_summary($db, $p['from'], $p['to'], $pmIds);
        $pmCmp = ul_pm_summary($db, $p['cmp_from'], $p['cmp_to'], $pmIds);

        $prodByType    = ul_prod_by_process_type($db, $p['from'], $p['to'], $prodIds);
        $prodUntracked = ul_prod_untracked_reports($db, $p['from'], $p['to'], $prodIds);
        $prodSetup     = ul_prod_setup_stats($db, $p['from'], $p['to'], $prodIds);

        $qcWait     = ul_qc_wait_time($db, $p['from'], $p['to'], $qcIds);
        $qcAbnormal = ul_qc_abnormal_stats($db, $p['from'], $p['to'], $qcIds);
        $qcAdhoc    = ul_qc_adhoc($db, $p['from'], $p['to']);

        $insights = ul_insights([
            'design' => ['cur' => $designCur, 'cmp' => $designCmp, 'cmp_label' => $p['cmp_label']],
            'sales'  => ['cur' => $salesCur,  'cmp' => $salesCmp,  'cmp_label' => $p['cmp_label']],
            'pm'     => ['cur' => $pmCur,     'cmp' => $pmCmp,     'cmp_label' => $p['cmp_label']],
            'prod'   => ['by_process_type' => $prodByType, 'untracked' => $prodUntracked, 'setup' => $prodSetup],
            'qc'     => ['wait' => $qcWait, 'abnormal' => $qcAbnormal, 'adhoc' => $qcAdhoc],
        ], $thresholds);

        ulOut([
            'period'   => $p,
            'design'   => ['cur' => $designCur, 'cmp' => $designCmp, 'people_count' => count($designIds)],
            'sales'    => ['cur' => $salesCur,  'cmp' => $salesCmp,  'people_count' => count($salesIds)],
            'pm'       => ['cur' => $pmCur,     'cmp' => $pmCmp,     'people_count' => count($pmIds)],
            'prod'     => [
                'unassigned_total' => array_sum(array_column($prodByType, 'unassigned')),
                'assigned_total'   => array_sum(array_column($prodByType, 'assigned')),
                'untracked_total'  => $prodUntracked['total'],
                'avg_setup_minutes'=> $prodSetup['avg_minutes'],
                'people_count'     => count($prodIds),
            ],
            'qc'       => [
                'avg_wait_workdays' => $qcWait['avg_workdays'],
                'avg_ng_rate'       => $qcAbnormal['avg_ng_rate'],
                'adhoc_total'       => $qcAdhoc['total'],
                'people_count'      => count($qcIds),
            ],
            'insights' => $insights,
            'canAdmin' => $canAdmin ? 1 : 0,
        ]);
    }

    /* ── 設計課逐項明細 ───────────────────────────────────────── */
    case 'data_design': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];
        $people = ul_dept_user_ids($db, $settings, 'design');
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $people)));
        $deptIds = ul_unit_dept_ids($db, $settings, 'design');

        $summary    = ul_design_summary($db, $p['from'], $p['to'], $ids);
        $summaryCmp = ul_design_summary($db, $p['cmp_from'], $p['cmp_to'], $ids);
        $byPerson   = ul_design_by_person($db, $p['from'], $p['to'], $ids, $deptIds);
        $reviewer   = ul_design_reviewer_counts($db, $p['from'], $p['to'], $ids);
        $dailyPmget = ul_design_daily_pmget($db, $p['from'], $p['to'], $ids);
        $tags       = ul_design_tags($db, $p['from'], $p['to'], $ids);
        $noteStats  = ul_design_note_stats($db, $p['from'], $p['to'], $ids);

        $overload = [
            'drawing_wip'       => ul_is_overload((float)$summary['drawing_wip'], 'design.batch_pending', $thresholds),
            'avg_draw_workdays' => $summary['avg_draw_workdays'] !== null
                                   && ul_is_overload((float)$summary['avg_draw_workdays'], 'design.avg_draw_workdays', $thresholds),
            'issue_orders'      => ul_is_overload((float)$summary['issue_orders'], 'design.issue_orders', $thresholds),
        ];

        ulOut([
            'period'      => $p,
            'people'      => $people,
            'summary'     => $summary,
            'summary_cmp' => $summaryCmp,
            'overload'    => $overload,
            'by_person'   => $byPerson,
            'reviewer'    => $reviewer,
            'daily_pmget' => $dailyPmget,
            'tags'        => $tags,
            'note_stats'  => $noteStats,
            'note'        => $ids ? '' : '尚未在設定頁為設計課勾選任何部門，以下數字皆為 0',
        ]);
    }

    /* ── 業務課逐項明細 ───────────────────────────────────────── */
    case 'data_sales': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];
        $people = ul_dept_user_ids($db, $settings, 'sales');
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $people)));
        $deptIds = ul_unit_dept_ids($db, $settings, 'sales');

        $summary    = ul_sales_summary($db, $p['from'], $p['to'], $ids);
        $summaryCmp = ul_sales_summary($db, $p['cmp_from'], $p['cmp_to'], $ids);
        $byPerson   = ul_sales_by_person($db, $p['from'], $p['to'], $ids, $deptIds);

        $overload = [
            'quote_count'      => ul_is_overload((float)$summary['quote_count'], 'sales.quote_backlog', $thresholds),
            'open_issue_count' => ul_is_overload((float)$summary['open_issue_count'], 'sales.open_issue_count', $thresholds),
        ];

        ulOut([
            'period'      => $p,
            'people'      => $people,
            'summary'     => $summary,
            'summary_cmp' => $summaryCmp,
            'overload'    => $overload,
            'by_person'   => $byPerson,
            'note'        => $ids ? '' : '尚未在設定頁為業務課勾選任何部門，以下數字皆為 0',
        ]);
    }

    /* ── 生管逐項明細 ─────────────────────────────────────────── */
    case 'data_pm': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];
        $people = ul_dept_user_ids($db, $settings, 'pm');
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $people)));

        $summary    = ul_pm_summary($db, $p['from'], $p['to'], $ids);
        $summaryCmp = ul_pm_summary($db, $p['cmp_from'], $p['cmp_to'], $ids);
        $byPerson   = ul_pm_by_person($db, $ids); // 目前恆為 []，見 unit_load_lib.php 的函式註解

        $overload = [
            'outsource_wip'       => ul_is_overload((float)$summary['outsource_wip'], 'pm.outsource_wip', $thresholds),
            'pending_recon_lines' => ul_is_overload((float)$summary['pending_recon_lines'], 'pm.pending_recon_lines', $thresholds),
        ];

        ulOut([
            'period'      => $p,
            'people'      => $people,
            'summary'     => $summary,
            'summary_cmp' => $summaryCmp,
            'overload'    => $overload,
            'by_person'   => $byPerson,
            'note'        => '生管目前的現況快照（委外/廠內加工中、待QC、待移轉、已移轉、待對帳）'
                            . '是全公司製令統計，bom_ing 沒有「這張製令由哪個生管負責」的欄位，'
                            . '不受設定頁勾選的部門/人員影響；逐人明細因此恆為空。',
        ]);
    }

    /* ── 生產課逐項明細 ───────────────────────────────────────── */
    case 'data_prod': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];
        $people = ul_dept_user_ids($db, $settings, 'prod');
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $people)));

        $byType     = ul_prod_by_process_type($db, $p['from'], $p['to'], $ids);
        $untracked  = ul_prod_untracked_reports($db, $p['from'], $p['to'], $ids);
        $dailyOut   = ul_prod_daily_output($db, $p['from'], $p['to'], $ids);
        $setup      = ul_prod_setup_stats($db, $p['from'], $p['to'], $ids);
        $production = ul_prod_production_stats($db, $p['from'], $p['to'], $ids);
        $perCapita  = ul_prod_per_capita($db, $p['from'], $p['to'], $ids);
        // 包裝負荷：待包裝筆數是全公司現況快照（跟 by_process_type 一樣不受 $ids 篩選），
        // 不屬於任一品管/生產課特定人員，見 unit_load_lib.php 的函式註解。
        $packing = ul_prod_packing_stats($db, $p['from'], $p['to']);

        $totalUnassigned = array_sum(array_column($byType, 'unassigned'));
        $overload = [
            'unassigned_count' => ul_is_overload((float)$totalUnassigned, 'prod.unassigned_count', $thresholds),
            'avg_setup_minutes'=> $setup['avg_minutes'] !== null
                                  && ul_is_overload((float)$setup['avg_minutes'], 'prod.avg_setup_minutes', $thresholds),
            'untracked_count'  => ul_is_overload((float)$untracked['total'], 'prod.untracked_count', $thresholds),
        ];

        ulOut([
            'period'         => $p,
            'people'         => $people,
            'by_process_type'=> $byType,
            'untracked'      => $untracked,
            'daily_output'   => $dailyOut,
            'setup'          => $setup,
            'production'     => $production,
            'per_capita'     => $perCapita,
            'packing'        => $packing,
            'overload'       => $overload,
            'note'           => $ids ? '' : '尚未在設定頁為生產課勾選任何部門——製程大類現況與系統缺口統計、包裝負荷不受此影響'
                                           . '（照常顯示全公司數字），但每日產出/架機與生產時間/人均負荷需要設定人員後才有數字',
        ]);
    }

    /* ── 品管逐項明細 ─────────────────────────────────────────── */
    case 'data_qc': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];
        $people = ul_dept_user_ids($db, $settings, 'qc');
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $people)));
        $deptIds = ul_unit_dept_ids($db, $settings, 'qc');

        $dailyItems = ul_qc_daily_items($db, $p['from'], $p['to'], $ids);
        $wait       = ul_qc_wait_time($db, $p['from'], $p['to'], $ids);
        $abnormal   = ul_qc_abnormal_stats($db, $p['from'], $p['to'], $ids);
        $byPerson   = ul_qc_by_person($db, $p['from'], $p['to'], $ids, $deptIds);
        $adhoc      = ul_qc_adhoc($db, $p['from'], $p['to']);
        // 目前待驗佇列：筆數是現況快照，不吃 $from/$to、不受 $ids 篩選（全公司共用同一條
        // 佇列）；平均檢驗工作天數才吃本期 $from/$to（見 ul_qc_pending_queue() 函式註解）。
        $pendingQueue = ul_qc_pending_queue($db, $p['from'], $p['to']);

        // 待驗逐筆明細（rows）只用於畫面上的「最長/最短前5筆」，完整逐筆清單不回傳避免payload過大
        $waitOut = $wait;
        unset($waitOut['rows']);

        $overload = [
            'ng_rate'       => $abnormal['avg_ng_rate'] !== null
                                && ul_is_overload((float)$abnormal['avg_ng_rate'], 'qc.ng_rate', $thresholds),
            'wait_days_avg' => $wait['avg_workdays'] !== null
                                && ul_is_overload((float)$wait['avg_workdays'], 'qc.wait_days_avg', $thresholds),
            'adhoc_count'   => ul_is_overload((float)$adhoc['total'], 'qc.adhoc_count', $thresholds),
        ];

        ulOut([
            'period'        => $p,
            'people'        => $people,
            'daily_items'   => $dailyItems,
            'wait'          => $waitOut,
            'abnormal'      => $abnormal,
            'by_person'     => $byPerson,
            'adhoc'         => $adhoc,
            'pending_queue' => $pendingQueue,
            'overload'      => $overload,
            'note'          => $ids ? '' : '尚未在設定頁為品管勾選任何部門——待驗等待工作天/異常比例/脫離流程補檢驗/目前待驗佇列'
                                        . '不受此影響（照常顯示全公司現況），但每日檢驗項目數/逐人明細需要設定人員後才有數字',
        ]);
    }

    default:
        ulErr('無效的操作');
}
