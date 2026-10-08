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
 * 同 ulUnitIds()，但多一層「排除特定職位」過濾（2026-10-07 新增，見 unit_load_lib.php 的
 * ul_dept_user_ids_filtered() 函式註解）——只給「逐人負荷明細」這一類輸出用，彙總統計
 * 一律還是用上面那支不排除的 ulUnitIds()。
 */
function ulUnitIdsFiltered(PDO $db, array $settings, string $unitKey): array {
    $rows = ul_dept_user_ids_filtered($db, $settings, $unitKey);
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

    /* ── 設定讀取（部門勾選＋過重門檻＋製程大類白名單＋排除職位）──────── */
    case 'settings_get': {
        $s = ul_settings($db);
        // process_type 全表很小（十幾筆），整張撈起來給設定頁的「要列入哪些製程大類」勾選清單用。
        $processTypes = $db->query(
            "SELECT process_type_id, process_type FROM process_type ORDER BY process_type_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        ulOut([
            'dept_cfg'          => $s['dept_cfg'],
            'thresholds'        => $s['thresholds'],
            'threshold_defaults'=> ul_threshold_defaults(),
            'prod_process_types'=> $s['prod_process_types'],
            'exclude_positions' => $s['exclude_positions'],
            'process_types'     => $processTypes,
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

        $in = ['dept_cfg' => $deptCfg, 'thresholds' => $thr];

        // prod_process_types／exclude_positions／no_threshold：本頁設定跳窗一次送出全部
        // 欄位，但 ul_settings_save() 仍是用 array_key_exists 判「有沒有送這個鍵」（鐵律8：
        // 沒送＝不要動既有設定，送了才整批覆蓋），本頁一律整批送所以這裡只要「有送就轉交」。
        if (array_key_exists('prod_process_types', $_POST)) {
            $pptRaw = $_POST['prod_process_types'];
            $ppt = is_string($pptRaw) ? json_decode($pptRaw, true) : $pptRaw;
            $in['prod_process_types'] = is_array($ppt) ? $ppt : [];
        }
        if (array_key_exists('exclude_positions', $_POST)) {
            $exRaw = $_POST['exclude_positions'];
            $ex = is_string($exRaw) ? json_decode($exRaw, true) : $exRaw;
            $in['exclude_positions'] = is_array($ex) ? $ex : [];
        }
        if (array_key_exists('no_threshold', $_POST)) {
            $ntRaw = $_POST['no_threshold'];
            $nt = is_string($ntRaw) ? json_decode($ntRaw, true) : $ntRaw;
            $in['no_threshold'] = is_array($nt) ? $nt : [];
        }

        $saved = ul_settings_save($db, $in, $uid);
        ulOut([
            'dept_cfg'          => $saved['dept_cfg'],
            'thresholds'        => $saved['thresholds'],
            'threshold_defaults'=> ul_threshold_defaults(),
            'prod_process_types'=> $saved['prod_process_types'],
            'exclude_positions' => $saved['exclude_positions'],
        ]);
    }

    /* ── 設定頁「排除職位」勾選清單：給某個單位目前工作中的部門勾選（尚未存檔）即時算出
       候選職位（2026-10-07 新增）。吃的是前端送來的 dept_cfg（該單位目前勾選狀態，未必
       已存檔），不是已存檔的設定——這樣使用者在設定跳窗裡邊勾部門邊看得到候選職位會
       跟著變，不必先存檔才看得到。─────────────────────────────── */
    case 'positions_for_unit': {
        $unitKey = (string)($_REQUEST['unit'] ?? '');
        if (!in_array($unitKey, ul_unit_keys(), true)) ulErr('不合法的單位代碼');
        $cfgRaw = $_REQUEST['dept_cfg'] ?? '[]';
        $cfg = is_string($cfgRaw) ? json_decode($cfgRaw, true) : $cfgRaw;
        if (!is_array($cfg)) $cfg = [];
        $clean = [];
        foreach ($cfg as $r) {
            if (!is_array($r)) continue;
            $did = (int)($r['dept_id'] ?? 0);
            if ($did <= 0) continue;
            $clean[] = ['dept_id' => $did, 'include_sub' => !empty($r['include_sub'])];
        }
        $deptIds = ul_unit_dept_ids($db, ['dept_cfg' => [$unitKey => $clean]], $unitKey);
        $positions = ul_positions_in_depts($db, $deptIds);
        ulOut(['positions' => $positions, 'dept_ids' => $deptIds]);
    }

    /* ── 總覽：五張 KPI 卡的核心數字＋整頁自動分析 ─────────────── */
    case 'overview': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];

        $designIds  = ulUnitIds($db, $settings, 'design');
        $salesIds   = ulUnitIds($db, $settings, 'sales');
        $pmIds      = ulUnitIds($db, $settings, 'pm');
        $prodIds    = ulUnitIds($db, $settings, 'prod');
        $qcIds      = ulUnitIds($db, $settings, 'qc');
        $packingIds = ulUnitIds($db, $settings, 'packing');

        $designCur = ul_design_summary($db, $p['from'], $p['to'], $designIds);
        $designCmp = ul_design_summary($db, $p['cmp_from'], $p['cmp_to'], $designIds);

        $salesCur = ul_sales_summary($db, $p['from'], $p['to'], $salesIds);
        $salesCmp = ul_sales_summary($db, $p['cmp_from'], $p['cmp_to'], $salesIds);

        $pmCur = ul_pm_summary($db, $p['from'], $p['to'], $pmIds);
        $pmCmp = ul_pm_summary($db, $p['cmp_from'], $p['cmp_to'], $pmIds);

        // 2026-10-07 新增：生產課「製程大類負荷」要列入哪些製程大類的管理員白名單
        // （settings['prod_process_types']，空陣列＝沿用舊行為顯示全部）。總覽的 KPI 卡／
        // 部門負荷總表跟「生產課」詳細分頁（data_prod）要是同一份口徑，否則兩處數字會對不起來。
        $allowedTypes = $settings['prod_process_types'] ?? [];
        $prodByType    = ul_prod_by_process_type($db, $p['from'], $p['to'], $prodIds, $allowedTypes);
        $prodUntracked = ul_prod_untracked_reports($db, $p['from'], $p['to'], $prodIds, $allowedTypes);
        $prodSetup     = ul_prod_setup_stats($db, $p['from'], $p['to'], $prodIds);

        $qcWait     = ul_qc_wait_time($db, $p['from'], $p['to'], $qcIds);
        $qcAbnormal = ul_qc_abnormal_stats($db, $p['from'], $p['to'], $qcIds);
        $qcAdhoc    = ul_qc_adhoc($db, $p['from'], $p['to']);
        // 2026-10-08 新增：使用者交辦總覽要看「待驗」與「檢驗」——待驗＝目前待驗佇列現況
        // 快照（跟單位詳細分頁的「目前待驗佇列」同一份資料，全公司共用同一條佇列，不受
        // $qcIds 篩選，見 ul_qc_pending_queue() 函式註解）；檢驗＝本期內這批品管人員實際
        // 完成的檢驗項目數（ul_qc_daily_items() 逐日逐製程的加總）。
        $qcPending  = ul_qc_pending_queue($db, $p['from'], $p['to']);
        $qcDaily    = ul_qc_daily_items($db, $p['from'], $p['to'], $qcIds);
        $qcItemsTotal = array_sum(array_column($qcDaily, 'count'));

        // 包裝：輕量彙總（待包裝筆數為現況快照、平均處理工作天為本期統計，整體統計
        // 沿用既有 ul_prod_packing_stats()，不受 $packingIds 篩選——跟製程大類現況
        // 同一種道理，見該函式註解）
        $packingStats = ul_prod_packing_stats($db, $p['from'], $p['to']);

        // 2026-10-07 新增：unit_overload（部門負荷總表）與 insights（自動分析）共用同一份
        // 已經組好的 $allData，不重新查資料庫，見 unit_load_lib.php 的 ul_unit_overload_check()／
        // ul_insights() 函式註解。
        $allData = [
            'design' => ['cur' => $designCur, 'cmp' => $designCmp, 'cmp_label' => $p['cmp_label']],
            'sales'  => ['cur' => $salesCur,  'cmp' => $salesCmp,  'cmp_label' => $p['cmp_label']],
            'pm'     => ['cur' => $pmCur,     'cmp' => $pmCmp,     'cmp_label' => $p['cmp_label']],
            'prod'   => ['by_process_type' => $prodByType, 'untracked' => $prodUntracked, 'setup' => $prodSetup],
            'qc'     => ['wait' => $qcWait, 'abnormal' => $qcAbnormal, 'adhoc' => $qcAdhoc, 'pending' => $qcPending],
            'packing'=> ['pending' => $packingStats['pending'], 'avg_workdays' => $packingStats['avg_workdays']],
        ];

        $unitOverload = ul_unit_overload_check($allData, $thresholds);

        // 趨勢型訊息（連續上升/下滑）只在期間粒度是月/季時才算得出有意義的趨勢線——
        // 半年/整年的「上一期」間距太粗（ul_trend_series 本身也只支援 month/quarter），
        // 其他粒度就不算，insights 其餘結論不受影響。
        $trend = in_array($p['gran'], ['month', 'quarter'], true)
            ? ul_trend_series($db, $settings, $p['gran'], 6)
            : null;

        $insights = ul_insights($allData, $thresholds, $trend);

        ulOut([
            'period'   => $p,
            'design'   => ['cur' => $designCur, 'cmp' => $designCmp, 'people_count' => count($designIds)],
            'sales'    => ['cur' => $salesCur,  'cmp' => $salesCmp,  'people_count' => count($salesIds)],
            'pm'       => ['cur' => $pmCur,     'cmp' => $pmCmp,     'people_count' => count($pmIds)],
            'prod'     => [
                'unassigned_total' => array_sum(array_column($prodByType, 'unassigned')),
                'assigned_total'   => array_sum(array_column($prodByType, 'assigned')),
                // 2026-10-08 新增：使用者交辦總覽要有「待生產數」。定義＝目前每張現役製令
                // 「正卡著的那一關」全部加總（未指派機台＋已指派機台，見 ul_prod_by_process_type()
                // 函式註解；二者原本就各自是正確數字，這裡純粹加總不是另一套新邏輯，故可以
                // 直接確認計算正確——不含已結案/已移轉(processing_state='E')的站）。
                'pending_production_total' => array_sum(array_column($prodByType, 'unassigned'))
                                             + array_sum(array_column($prodByType, 'assigned')),
                'untracked_total'  => $prodUntracked['total'],
                'avg_setup_minutes'=> $prodSetup['avg_minutes'],
                'people_count'     => count($prodIds),
            ],
            'qc'       => [
                'avg_wait_workdays' => $qcWait['avg_workdays'],
                'avg_ng_rate'       => $qcAbnormal['avg_ng_rate'],
                'adhoc_total'       => $qcAdhoc['total'],
                'pending_total'     => $qcPending['total'],
                'items_total'       => $qcItemsTotal,
                'people_count'      => count($qcIds),
            ],
            'packing'  => [
                'pending'      => $packingStats['pending'],
                'avg_workdays' => $packingStats['avg_workdays'],
                'people_count' => count($packingIds),
            ],
            'unit_overload' => $unitOverload,
            'insights' => $insights,
            'canAdmin' => $canAdmin ? 1 : 0,
        ]);
    }

    /* ── 月／季趨勢分析：六個單位代表指標的走勢（獨立於 overview 的期間篩選，
       自己的粒度與期數）─────────────────────────────────────── */
    case 'trend': {
        $gran = (string)($_REQUEST['gran'] ?? 'month');
        if (!in_array($gran, ['month', 'quarter'], true)) ulErr('不合法的期間粒度（趨勢分析僅支援 month/quarter）');
        $buckets = (int)($_REQUEST['buckets'] ?? 6);
        if ($buckets < 1) $buckets = 1;
        if ($buckets > 12) $buckets = 12;

        $settings = ul_settings($db);
        $trend = ul_trend_series($db, $settings, $gran, $buckets);
        ulOut($trend);
    }

    /* ── 設計課逐項明細 ───────────────────────────────────────── */
    case 'data_design': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];
        $people = ul_dept_user_ids($db, $settings, 'design');
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $people)));
        $deptIds = ul_unit_dept_ids($db, $settings, 'design');
        // 逐人負荷明細才套「排除職位」過濾（2026-10-07 新增），彙總統計（$summary／
        // $summaryCmp）仍用未過濾的 $ids——這些人即使被排除在明細表之外，工作量還是要
        // 算進整個設計課的彙總數字。
        $idsFiltered = ulUnitIdsFiltered($db, $settings, 'design');

        $summary    = ul_design_summary($db, $p['from'], $p['to'], $ids);
        $summaryCmp = ul_design_summary($db, $p['cmp_from'], $p['cmp_to'], $ids);
        $byPerson   = ul_design_by_person($db, $p['from'], $p['to'], $idsFiltered, $deptIds);
        // 2026-10-07 修正：以下三支原本吃未過濾的 $ids——但前端渲染時都是拿「已排除職位的
        // by_person」去查姓名（renderDesignDailyChart()/renderDesignNoteStats() 的 nameOf()
        // 共用邏輯），被排除的人（如職位課長的陳俊宏）仍會出現在這幾份明細裡、卻查不到姓名，
        // 畫面上就印出「#105030101」這種異常。改用 $idsFiltered 讓「被排除職位的人不列入
        // 逐人相關明細」這件事全站一致——不是只有主表格排除、其他地方還是漏網之魚。
        $reviewer   = ul_design_reviewer_counts($db, $p['from'], $p['to'], $idsFiltered);
        $dailyPmget = ul_design_daily_pmget($db, $p['from'], $p['to'], $idsFiltered);
        $tags       = ul_design_tags($db, $p['from'], $p['to'], $ids);
        $tagsByPerson = ul_design_tags_by_person($db, $p['from'], $p['to'], $idsFiltered);
        $noteStats  = ul_design_note_stats($db, $p['from'], $p['to'], $idsFiltered);
        // 2026-10-08 新增：逐人每日工作量明細（批圖中新進／審圖／已轉生管＋其中新料號數），
        // 使用者交辦——與上面逐人彙總表（$byPerson）用同一份已排除職位的人員範圍。
        $dailyDetail = ul_design_daily_detail($db, $p['from'], $p['to'], $idsFiltered);
        // 2026-10-08 新增：累積待批圖數量（存量，依統計日重建），跟 $dailyDetail 的
        // batch_in（流量，當天新進幾筆）互補，不要混為一談——見 ul_design_wip_daily() 註解。
        $wipDaily = ul_design_wip_daily($db, $p['from'], $p['to'], $idsFiltered);
        // 2026-10-08 新增：當天狀態（會議／外出／請假），使用者交辦。超過 31 天自動回空
        // 陣列（見 ul_person_day_status() 註解），前端沒有資料時不顯示「狀態」欄。
        $dayStatus = ul_person_day_status($db, $idsFiltered, $p['from'], $p['to']);

        // 2026-10-08 修正：issue_orders 的過重判定改比「近90天下單仍未回覆」
        // （issue_orders_recent），不是全歷史總量——道理與 ul_unit_overload_check() 的
        // design 區塊同一處修正完全一致，本頁（單位詳細分頁）跟總覽頁的紅綠燈不可以各算
        // 一套、對不起來。
        $overload = [
            'drawing_wip'       => ul_is_overload((float)$summary['drawing_wip'], 'design.batch_pending', $thresholds),
            'avg_draw_workdays' => $summary['avg_draw_workdays'] !== null
                                   && ul_is_overload((float)$summary['avg_draw_workdays'], 'design.avg_draw_workdays', $thresholds),
            'issue_orders'      => ul_is_overload((float)$summary['issue_orders_recent'], 'design.issue_orders', $thresholds),
        ];

        ulOut([
            'period'       => $p,
            'people'       => $people,
            'summary'      => $summary,
            'summary_cmp'  => $summaryCmp,
            'overload'     => $overload,
            'by_person'    => $byPerson,
            'reviewer'     => $reviewer,
            'daily_pmget'  => $dailyPmget,
            'daily_detail' => $dailyDetail,
            'wip_daily'    => $wipDaily,
            'day_status'   => $dayStatus,
            'tags'         => $tags,
            'tags_by_person' => $tagsByPerson,
            'note_stats'   => $noteStats,
            'note'         => $ids ? '' : '尚未在設定頁為設計課勾選任何部門，以下數字皆為 0',
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
        $idsFiltered = ulUnitIdsFiltered($db, $settings, 'sales');

        $summary    = ul_sales_summary($db, $p['from'], $p['to'], $ids);
        $summaryCmp = ul_sales_summary($db, $p['cmp_from'], $p['cmp_to'], $ids);
        $byPerson   = ul_sales_by_person($db, $p['from'], $p['to'], $idsFiltered, $deptIds);

        // 2026-10-08 修正：quote_count 改比「日均報價單數」（跟總覽頁 ul_unit_overload_check()
        // 的 sales 區塊、2026-10-07 已經定案的規則同一套，本頁先前還是比本期累積總數，本期
        // 未走完時會失真——沒跟上游的規則一起更新）；open_issue_count 改比近90天下單仍未
        // 回覆的 open_issue_count_recent，不是全歷史總量，道理同設計課 issue_orders。
        $overload = [
            'quote_count'      => ul_is_overload(ul_sales_quote_daily_rate($summary), 'sales.quote_backlog', $thresholds),
            'open_issue_count' => ul_is_overload((float)$summary['open_issue_count_recent'], 'sales.open_issue_count', $thresholds),
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

        $allowedTypes = $settings['prod_process_types'] ?? [];
        $byType     = ul_prod_by_process_type($db, $p['from'], $p['to'], $ids, $allowedTypes);
        $untracked  = ul_prod_untracked_reports($db, $p['from'], $p['to'], $ids, $allowedTypes);
        $dailyOut   = ul_prod_daily_output($db, $p['from'], $p['to'], $ids);
        $setup      = ul_prod_setup_stats($db, $p['from'], $p['to'], $ids);
        $production = ul_prod_production_stats($db, $p['from'], $p['to'], $ids);
        $perCapita  = ul_prod_per_capita($db, $p['from'], $p['to'], $ids);
        // 包裝負荷 2026-10-07 已改為獨立單位（見下方 data_packing），本分頁不再重複計算。

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
            'overload'       => $overload,
            'note'           => $ids ? '' : '尚未在設定頁為生產課勾選任何部門——製程大類現況與系統缺口統計不受此影響'
                                           . '（照常顯示全公司數字），但每日產出/架機與生產時間/人均負荷需要設定人員後才有數字',
        ]);
    }

    /* ── 包裝逐項明細（獨立單位，2026-10-07 新增）─────────────── */
    case 'data_packing': {
        $p = ulPeriodParse($_REQUEST);
        $settings = ul_settings($db);
        $thresholds = $settings['thresholds'];
        // 本分頁的人員清單只用在逐人明細（ul_packing_by_person），全部直接走過濾後的清單
        // （2026-10-07 新增「排除職位」）——不像設計/業務/品管同時還要供彙總統計用未過濾的
        // 清單，包裝沒有獨立於逐人明細之外的「依人彙總」用途。
        $people = ul_dept_user_ids_filtered($db, $settings, 'packing');
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $people)));
        $deptIds = ul_unit_dept_ids($db, $settings, 'packing');

        // 待包裝筆數／每日完成數／平均處理工作天：全公司現況統計，沿用既有
        // ul_prod_packing_stats()（不受 $ids 篩選，見該函式與下方逐人明細的區別）。
        $stats    = ul_prod_packing_stats($db, $p['from'], $p['to']);
        $byPerson = ul_packing_by_person($db, $p['from'], $p['to'], $ids, $deptIds);

        $overload = [
            'pending' => ul_is_overload((float)$stats['pending'], 'packing.pending', $thresholds),
        ];

        $note = '';
        if (!$ids) {
            $note = '尚未在設定頁為「包裝」勾選任何部門——待包裝筆數／每日完成數／平均處理工作天仍照常顯示'
                  . '全公司現況（不受此影響），但逐人明細需要設定人員後才有數字';
        } elseif (!$byPerson) {
            $note = '已設定的人員名下目前查不到任何包裝報工紀錄——包裝報工（pm_process_daily_report）多半'
                  . '記在實際操作人員頭上，可能跟目前設定的人員範圍對不起來，逐人明細暫時沒有數字可顯示；'
                  . '整體統計（待包裝筆數／每日完成數／平均處理工作天）不受此影響，照常顯示全公司現況。';
        }

        ulOut([
            'period'    => $p,
            'people'    => $people,
            'packing'   => $stats,
            'by_person' => $byPerson,
            'overload'  => $overload,
            'note'      => $note,
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

        $idsFiltered = ulUnitIdsFiltered($db, $settings, 'qc');

        $dailyItems = ul_qc_daily_items($db, $p['from'], $p['to'], $ids);
        $wait       = ul_qc_wait_time($db, $p['from'], $p['to'], $ids);
        $abnormal   = ul_qc_abnormal_stats($db, $p['from'], $p['to'], $ids);
        $byPerson   = ul_qc_by_person($db, $p['from'], $p['to'], $idsFiltered, $deptIds);
        $adhoc      = ul_qc_adhoc($db, $p['from'], $p['to']);
        // 目前待驗佇列：筆數是現況快照，不吃 $from/$to、不受 $ids 篩選（全公司共用同一條
        // 佇列）；平均檢驗工作天數才吃本期 $from/$to（見 ul_qc_pending_queue() 函式註解）。
        $pendingQueue = ul_qc_pending_queue($db, $p['from'], $p['to']);

        // 待驗逐筆明細（rows）只用於畫面上的「最長/最短前5筆」，完整逐筆清單不回傳避免payload過大
        $waitOut = $wait;
        unset($waitOut['rows']);

        // 2026-10-08 新增：逐人每日工作量（檢驗筆數／NG筆數）＋當天狀態（會議／外出／
        // 請假），使用者交辦，跟設計課同一種做法——與逐人彙總表（$byPerson）用同一份
        // 已排除職位的人員範圍。
        $qcDailyDetail = ul_qc_daily_detail($db, $p['from'], $p['to'], $idsFiltered);
        $qcDayStatus   = ul_person_day_status($db, $idsFiltered, $p['from'], $p['to']);

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
            'daily_detail'  => $qcDailyDetail,
            'day_status'    => $qcDayStatus,
            'overload'      => $overload,
            'note'          => $ids ? '' : '尚未在設定頁為品管勾選任何部門——待驗等待工作天/異常比例/脫離流程補檢驗/目前待驗佇列'
                                        . '不受此影響（照常顯示全公司現況），但每日檢驗項目數/逐人明細需要設定人員後才有數字',
        ]);
    }

    default:
        ulErr('無效的操作');
}
