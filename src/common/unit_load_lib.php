<?php
/**
 * unit_load_lib.php — 各單位負荷分析（新模組第一階段：基礎設施＋設計課＋業務課＋生管）唯一實作
 *
 * 本檔只建立共用函式，不含頁面／API（頁面與 API 留給下一階段）。
 * 函式一律 `ul_` 前綴，直接吃 PDO，不自建類別（比照 order_analysis_lib.php 的寫法）。
 *
 * ── 設定儲存 ──
 * 沿用 order_analysis_lib.php 的做法：存進 system_parameters（param_group='UNIT_LOAD'），
 * 不另建資料表——這批設定很小，而且在交易中跑 DDL 會隱式 commit（鐵律）。
 *
 * ── 資料來源，皆直接重用既有唯一實作，不重寫一份 ──
 *   期間切法／新料號判定：order_analysis_lib.php（oa_period_buckets 等／oa_first_seen）
 *   人員列表／含子部門展開：people_lib.php（eg_people_list）／org_role_lib.php（eg_dept_subtree_ids）
 *   工作日判定：leave_lib.php（eg_leave_is_workday）
 *   設計備註開放問題數：eng_log_lib.php（el_order_open_exists_sql／el_order_open_item_counts）
 *   待對帳家數/筆數（生管）：acc_track_lib.php（act_ap_rows，status='processing' 視為尚未對帳完成）
 */

require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/leave_lib.php';
require_once __DIR__ . '/eng_log_lib.php';
require_once __DIR__ . '/order_analysis_lib.php';
require_once __DIR__ . '/acc_track_lib.php';

if (!defined('UL_PARAM_GROUP')) define('UL_PARAM_GROUP', 'UNIT_LOAD');

/* ══════════════════════════════════════════════════════════════════
 * A. 基礎設施
 * ══════════════════════════════════════════════════════════════════ */

/** 設定值讀取（system_parameters，與 order_analysis_lib.php 的 oa_param_get 同一種寫法） */
function ul_param_get(PDO $db, string $key, $default)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([UL_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}
function ul_param_save(PDO $db, string $key, $val, string $by = ''): void
{
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
    $st->execute([UL_PARAM_GROUP, $key]);
    $rid = $st->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$json, $by, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at)
                      VALUES (?,?,?,?,?,NOW())")
           ->execute([UL_PARAM_GROUP, $key, $json, '各單位負荷分析：' . $key, $by]);
    }
}

/** 本模組目前已知的單位鍵（prod/qc 這次只佔位，計算函式留待後續階段） */
function ul_unit_keys(): array
{
    return ['design', 'sales', 'pm', 'prod', 'qc'];
}

/**
 * 讀出設定：dept_cfg（每個單位對應的部門清單，可勾「含子部門」）＋ thresholds（之後各單位的門檻值）。
 * @return array ['dept_cfg'=>['design'=>[['dept_id'=>int,'include_sub'=>bool],...], 'sales'=>[...], ...],
 *                'thresholds'=>array]
 */
function ul_settings(PDO $db): array
{
    $deptCfgRaw = ul_param_get($db, 'dept_cfg', []);
    if (!is_array($deptCfgRaw)) $deptCfgRaw = [];
    $thresholds = ul_param_get($db, 'thresholds', []);
    if (!is_array($thresholds)) $thresholds = [];

    $deptCfg = [];
    foreach (ul_unit_keys() as $k) {
        $list = isset($deptCfgRaw[$k]) && is_array($deptCfgRaw[$k]) ? $deptCfgRaw[$k] : [];
        $clean = [];
        foreach ($list as $r) {
            if (!is_array($r)) continue;
            $did = (int)($r['dept_id'] ?? 0);
            if ($did <= 0) continue;
            $clean[] = ['dept_id' => $did, 'include_sub' => !empty($r['include_sub'])];
        }
        $deptCfg[$k] = $clean;
    }
    return ['dept_cfg' => $deptCfg, 'thresholds' => $thresholds];
}

/**
 * 遞迴清洗門檔設定：只留數字型葉節點，非數字的整支直接略過（不報錯、不採信，鐵律8）。
 */
function ul_settings_clean_thresholds(array $v): array
{
    $out = [];
    foreach ($v as $k => $x) {
        if (is_array($x)) { $out[$k] = ul_settings_clean_thresholds($x); continue; }
        if (is_numeric($x)) $out[$k] = $x + 0;
    }
    return $out;
}

/**
 * 存檔：驗證 dept_cfg 裡的 dept_id 都是正整數、include_sub 正規化成 0/1；
 * thresholds 裡的葉節點值都要是數字，非數字的整支略過。
 * @return array 存檔後的完整設定（即 ul_settings() 的回傳格式）
 */
function ul_settings_save(PDO $db, array $in, int $by): array
{
    $deptCfgIn = isset($in['dept_cfg']) && is_array($in['dept_cfg']) ? $in['dept_cfg'] : [];
    $deptCfgOut = [];
    foreach (ul_unit_keys() as $k) {
        $list = isset($deptCfgIn[$k]) && is_array($deptCfgIn[$k]) ? $deptCfgIn[$k] : [];
        $clean = [];
        foreach ($list as $r) {
            if (!is_array($r)) continue;
            $did = (int)($r['dept_id'] ?? 0);
            if ($did <= 0) continue;   // 不是合法的部門 id，整列略過
            $clean[] = ['dept_id' => $did, 'include_sub' => !empty($r['include_sub']) ? 1 : 0];
        }
        $deptCfgOut[$k] = $clean;
    }

    $thrIn = isset($in['thresholds']) && is_array($in['thresholds']) ? $in['thresholds'] : [];
    $thrOut = ul_settings_clean_thresholds($thrIn);

    $byStr = (string)$by;
    ul_param_save($db, 'dept_cfg', $deptCfgOut, $byStr);
    ul_param_save($db, 'thresholds', $thrOut, $byStr);

    return ul_settings($db);
}

/**
 * 依 ul_settings() 回傳的 dept_cfg，展開某個單位鍵對應的人員清單。
 * 含子部門時用 eg_dept_subtree_ids() 展開，彙總全部部門 id（去重）後交給 eg_people_list()
 * （all_posts=true：兼任者每個職務各一列，這裡只是給呼叫端挑人用，不是要「代表身分」）。
 * @return array eg_people_list() 的回傳格式（每列含 id/user_cname/dept_name/position_name 等）
 */
function ul_dept_user_ids(PDO $db, array $settings, string $unitKey): array
{
    $cfgList = $settings['dept_cfg'][$unitKey] ?? [];
    if (!is_array($cfgList) || !$cfgList) return [];

    $deptIds = [];
    foreach ($cfgList as $cfg) {
        $did = (int)($cfg['dept_id'] ?? 0);
        if ($did <= 0) continue;
        if (!empty($cfg['include_sub'])) {
            foreach (eg_dept_subtree_ids($db, $did) as $sub) $deptIds[] = (int)$sub;
        } else {
            $deptIds[] = $did;
        }
    }
    $deptIds = array_values(array_unique(array_filter($deptIds, fn($v) => $v > 0)));
    if (!$deptIds) return [];

    return eg_people_list($db, ['dept_ids' => $deptIds, 'all_posts' => true]);
}

/**
 * 兩個日期（含頭尾）之間的工作天數，逐日呼叫 eg_leave_is_workday()（含假日/補班日判定）。
 * 仿照 kpi_as_lib.php 的 kpi_as_workdays_inclusive() 同一種逐日累加寫法，但改走
 * eg_leave_is_workday()（人員列表鐵則同一套假日判定，不要再另外接 car_holiday_sets 那一份）。
 * @return int|null $from/$to 任一個為空或格式不合法、或 $to 早於 $from 時回 null
 */
function ul_workdays_between(PDO $db, ?string $from, ?string $to): ?int
{
    if ($from === null || $to === null || $from === '' || $to === '') return null;
    $f = substr($from, 0, 10);
    $t = substr($to, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) return null;
    $fTs = strtotime($f);
    $tTs = strtotime($t);
    if ($fTs === false || $tTs === false || $tTs < $fTs) return null;

    $count = 0; $cur = $fTs; $guard = 0;
    while ($cur <= $tTs && $guard++ < 4000) {
        if (eg_leave_is_workday($db, date('Y-m-d', $cur))) $count++;
        $cur = strtotime('+1 day', $cur);
    }
    return $count;
}

/** 本頁管理員（canAdmin）：$feat 含 'all'（全站超級管理員）或本模組的 'unit_load_admin' */
function ul_can_admin(PDO $db, int $uid, array $feat): bool
{
    return in_array('all', $feat, true) || in_array('unit_load_admin', $feat, true);
}
/** 本頁檢視權（canView）：canAdmin 或持有 'unit_load_view' */
function ul_can_view(PDO $db, int $uid, array $feat): bool
{
    return ul_can_admin($db, $uid, $feat) || in_array('unit_load_view', $feat, true);
}

/** 把 YYYY-MM-DD ~ YYYY-MM-DD 展開成跨越到的每一個「帳款月份」YYYY-MM（給 act_ap_rows 逐月查用） */
function ul_billing_months_between(string $from, string $to): array
{
    $f = substr($from, 0, 7);
    $t = substr($to, 0, 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $f) || !preg_match('/^\d{4}-\d{2}$/', $t)) return [];
    if ($t < $f) return [];
    $out = []; $cur = $f; $guard = 0;
    while ($cur <= $t && $guard++ < 240) {
        $out[] = $cur;
        $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * B. 設計課
 *
 * 資料來源：order_track（ate=設計者 user.id／ateGet=業務轉設計日／in_review=審圖中／
 *          pmGet=轉生管日／Order_status：6=暫停或取消一律排除，9=已結案照算）、
 *          eng_log/eng_log_bind/eng_log_item（設計備註問題，走 eng_log_lib.php）、
 *          ot_as_proc_tag（order_track.as_tag_id 指向的標籤定義表）。
 * ══════════════════════════════════════════════════════════════════ */

/** 共用：把呼叫端傳進來的設計師/業務 id 陣列正規化成去重過的正整數陣列 */
function ul_ids_norm(array $ids): array
{
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
}

/**
 * 這張訂單是不是「系統裡第一次出現這個料號」——薄包裝呼叫 order_analysis_lib.php 的
 * oa_first_seen()（出貨/訂單/製令/退貨四來源逐料號取最早日期的唯一實作），不要在這裡
 * 另外寫一份判定規則。
 * @param int $partDId 料號主檔 id（order_track.d_id_ID）
 * @param string $orderDate 這張訂單的下單日（order_track.Order_date）
 */
function ul_order_is_new_case(PDO $db, int $partDId, string $orderDate): bool
{
    if ($partDId <= 0) return false;
    $map = oa_first_seen($db);
    if (!isset($map[$partDId])) return false;
    $d = substr($orderDate, 0, 10);
    if ($d === '') return false;
    // 系統裡這個料號最早出現的日期不早於這張單的下單日＝這張單就是（或同一天內）首次出現
    return $map[$partDId]['d'] >= $d;
}

/**
 * 設計課本期彙總卡片。
 * @return array drawing_wip（批圖中：未轉生管且該訂單目前有開放中設計備註問題）
 *               in_review（本期按下審圖）／pm_get（本期轉生管）／new_case（本期 ateGet 的訂單裡
 *               屬系統首次出現料號的張數）／issue_orders（現況：有開放中設計備註問題的訂單數）
 *               avg_draw_workdays（ateGet→pmGet 的平均工作天，只取 pmGet 落在本期內的）
 */
function ul_design_summary(PDO $db, string $from, string $to, array $designerIds): array
{
    $out = ['drawing_wip' => 0, 'in_review' => 0, 'pm_get' => 0, 'new_case' => 0,
            'issue_orders' => 0, 'avg_draw_workdays' => null];
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return $out;
    $in = implode(',', $ids);

    // 批圖中：這是「目前狀態」的即時快照，刻意不受 $from/$to 限制
    $existsSql = el_order_open_exists_sql('ot', 'order_note');
    $out['drawing_wip'] = (int)$db->query(
        "SELECT COUNT(*) FROM order_track ot
         WHERE ot.ate IN ({$in}) AND ot.pmGet IS NULL AND ot.Order_status<>6 AND {$existsSql}"
    )->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM order_track
        WHERE ate IN ({$in}) AND in_review IS NOT NULL AND DATE(in_review) BETWEEN ? AND ? AND Order_status<>6");
    $st->execute([$from, $to]);
    $out['in_review'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM order_track
        WHERE ate IN ({$in}) AND pmGet IS NOT NULL AND DATE(pmGet) BETWEEN ? AND ? AND Order_status<>6");
    $st->execute([$from, $to]);
    $out['pm_get'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT Order_id, d_id_ID, Order_date FROM order_track
        WHERE ate IN ({$in}) AND ateGet IS NOT NULL AND DATE(ateGet) BETWEEN ? AND ? AND Order_status<>6");
    $st->execute([$from, $to]);
    $newCnt = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (ul_order_is_new_case($db, (int)$r['d_id_ID'], (string)$r['Order_date'])) $newCnt++;
    }
    $out['new_case'] = $newCnt;

    // 問題訂單數：現況快照，不受 $from/$to 限制
    $orderIds = array_map('intval', $db->query(
        "SELECT Order_id FROM order_track WHERE ate IN ({$in}) AND Order_status<>6"
    )->fetchAll(PDO::FETCH_COLUMN));
    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        $out['issue_orders'] = count(array_filter($openMap, fn($c) => $c > 0));
    }

    $st = $db->prepare("SELECT ateGet, pmGet FROM order_track
        WHERE ate IN ({$in}) AND ateGet IS NOT NULL AND pmGet IS NOT NULL
          AND DATE(pmGet) BETWEEN ? AND ? AND Order_status<>6");
    $st->execute([$from, $to]);
    $sum = 0.0; $cnt = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = ul_workdays_between($db, substr((string)$r['ateGet'], 0, 10), substr((string)$r['pmGet'], 0, 10));
        if ($d === null) continue;
        $sum += $d; $cnt++;
    }
    $out['avg_draw_workdays'] = $cnt > 0 ? round($sum / $cnt, 2) : null;

    return $out;
}

/** ul_design_summary() 的逐人版本，同一批指標各自依設計師（order_track.ate）分組 */
function ul_design_by_person(PDO $db, string $from, string $to, array $designerIds): array
{
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return [];
    $in = implode(',', $ids);

    $people = eg_people_list($db, ['user_ids' => $ids]);
    $byId = [];
    foreach ($people as $p) $byId[(int)$p['id']] = $p;

    $out = [];
    foreach ($ids as $uid) {
        $p = $byId[$uid] ?? null;
        $out[$uid] = [
            'user_id' => $uid,
            'name' => $p['user_cname'] ?? ('#' . $uid),
            'dept_name' => $p['dept_name'] ?? '',
            'position_name' => $p['position_name'] ?? '',
            'drawing_wip' => 0, 'in_review' => 0, 'pm_get' => 0,
            'issue_orders' => 0, 'avg_draw_workdays' => null,
        ];
    }

    $existsSql = el_order_open_exists_sql('ot', 'order_note');
    foreach ($db->query(
        "SELECT ot.ate k, COUNT(*) c FROM order_track ot
         WHERE ot.ate IN ({$in}) AND ot.pmGet IS NULL AND ot.Order_status<>6 AND {$existsSql}
         GROUP BY ot.ate"
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['drawing_wip'] = (int)$r['c'];
    }

    $st = $db->prepare("SELECT ate k, COUNT(*) c FROM order_track
        WHERE ate IN ({$in}) AND in_review IS NOT NULL AND DATE(in_review) BETWEEN ? AND ? AND Order_status<>6
        GROUP BY ate");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['in_review'] = (int)$r['c'];
    }

    $st = $db->prepare("SELECT ate k, COUNT(*) c FROM order_track
        WHERE ate IN ({$in}) AND pmGet IS NOT NULL AND DATE(pmGet) BETWEEN ? AND ? AND Order_status<>6
        GROUP BY ate");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['pm_get'] = (int)$r['c'];
    }

    $orderRows = $db->query("SELECT Order_id, ate FROM order_track WHERE ate IN ({$in}) AND Order_status<>6")
                    ->fetchAll(PDO::FETCH_ASSOC);
    $orderIds = []; $ateByOrder = [];
    foreach ($orderRows as $r) { $oid = (int)$r['Order_id']; $orderIds[] = $oid; $ateByOrder[$oid] = (int)$r['ate']; }
    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        foreach ($openMap as $oid => $cnt) {
            if ($cnt <= 0) continue;
            $uid = $ateByOrder[$oid] ?? 0;
            if (isset($out[$uid])) $out[$uid]['issue_orders']++;
        }
    }

    $st = $db->prepare("SELECT ate k, ateGet, pmGet FROM order_track
        WHERE ate IN ({$in}) AND ateGet IS NOT NULL AND pmGet IS NOT NULL
          AND DATE(pmGet) BETWEEN ? AND ? AND Order_status<>6");
    $st->execute([$from, $to]);
    $sums = []; $cnts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k'];
        $d = ul_workdays_between($db, substr((string)$r['ateGet'], 0, 10), substr((string)$r['pmGet'], 0, 10));
        if ($d === null) continue;
        $sums[$uid] = ($sums[$uid] ?? 0) + $d;
        $cnts[$uid] = ($cnts[$uid] ?? 0) + 1;
    }
    foreach ($cnts as $uid => $c) {
        if (isset($out[$uid]) && $c > 0) $out[$uid]['avg_draw_workdays'] = round($sums[$uid] / $c, 2);
    }

    return array_values($out);
}

/**
 * 審圖人判定：只有設計課「恰好 2 人」時才能判定——按下審圖鈕的權限只限 ate 本人，
 * 公司制度上按審圖視為「對方審圖」（唯一能回推是誰審的依據）。超過或少於 2 人時
 * 無法判定是哪一位在審，回 supported=false 並附理由，不可以硬湊一個答案。
 */
function ul_design_reviewer_counts(PDO $db, string $from, string $to, array $designerIds): array
{
    $ids = ul_ids_norm($designerIds);
    if (count($ids) !== 2) {
        return [
            'supported' => false,
            'by_user' => [],
            'note' => '審圖人判定僅在設計課恰好 2 人時才有意義（制度上按審圖視為「對方審圖」），'
                    . '目前設定的設計課人數為 ' . count($ids) . '，本項不提供數字。',
        ];
    }
    $in = implode(',', $ids);
    $st = $db->prepare("SELECT ate k, COUNT(*) c FROM order_track
        WHERE in_review IS NOT NULL AND DATE(in_review) BETWEEN ? AND ? AND ate IN ({$in})
        GROUP BY ate");
    $st->execute([$from, $to]);
    $byUser = [];
    foreach ($ids as $uid) $byUser[$uid] = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byUser[(int)$r['k']] = (int)$r['c'];
    return ['supported' => true, 'by_user' => $byUser, 'note' => ''];
}

/** 每日各設計師完成數（已轉生管），給畫面畫趨勢線用 */
function ul_design_daily_pmget(PDO $db, string $from, string $to, array $designerIds): array
{
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return [];
    $in = implode(',', $ids);
    $st = $db->prepare("SELECT DATE(pmGet) d, ate, COUNT(*) c FROM order_track
        WHERE pmGet IS NOT NULL AND DATE(pmGet) BETWEEN ? AND ? AND ate IN ({$in})
        GROUP BY d, ate ORDER BY d");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 訂單標籤分布（AS 認定的稽核製程標籤，order_track.as_tag_id → ot_as_proc_tag 唯一定義表）。
 * 依下單日歸屬期間——標籤是訂單的屬性，用下單日判斷「這張單算不算這一期」最直觀；
 * 若要改成依「貼標籤當下」(as_tag_at) 篩選，留給下一階段依使用者意見再調。
 */
function ul_design_tags(PDO $db, string $from, string $to, array $designerIds): array
{
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return [];
    $in = implode(',', $ids);
    $st = $db->prepare("SELECT t.tag_id, COALESCE(t.proc_name,'（未命名標籤）') AS tag_name, COUNT(*) c
        FROM order_track ot
        LEFT JOIN ot_as_proc_tag t ON t.tag_id = ot.as_tag_id
        WHERE ot.ate IN ({$in}) AND ot.as_tag_id IS NOT NULL
          AND ot.Order_date BETWEEN ? AND ? AND ot.Order_status<>6
        GROUP BY t.tag_id, tag_name
        ORDER BY c DESC");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 設計備註問題數與回覆工作日數。
 * open_count：這批設計師名下目前（現況快照）全部訂單的開放中設計備註問題總數。
 * avg_reply_workdays：本期內被標記 resolved/dropped 的問題項，從提出(asked_at，沒填退回
 * created_at)到解決(updated_at) 的平均工作天數。
 */
function ul_design_note_stats(PDO $db, string $from, string $to, array $designerIds): array
{
    $out = ['open_count' => 0, 'avg_reply_workdays' => null, 'by_designer' => []];
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return $out;
    $in = implode(',', $ids);
    foreach ($ids as $uid) $out['by_designer'][$uid] = ['open' => 0, 'avg_days' => null];

    $orderRows = $db->query("SELECT Order_id, ate FROM order_track WHERE ate IN ({$in})")->fetchAll(PDO::FETCH_ASSOC);
    $orderIds = []; $ateByOrder = [];
    foreach ($orderRows as $r) { $oid = (int)$r['Order_id']; $orderIds[] = $oid; $ateByOrder[$oid] = (int)$r['ate']; }

    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        foreach ($openMap as $oid => $cnt) {
            $out['open_count'] += (int)$cnt;
            $uid = $ateByOrder[$oid] ?? 0;
            if (isset($out['by_designer'][$uid])) $out['by_designer'][$uid]['open'] += (int)$cnt;
        }
    }

    $st = $db->prepare("SELECT i.created_at, i.asked_at, i.updated_at, b.bind_id AS order_id
        FROM eng_log_item i
        JOIN eng_log_bind b ON b.log_id = i.log_id
        JOIN eng_log g ON g.id = i.log_id
        JOIN order_track ot ON ot.Order_id = b.bind_id
        WHERE b.bind_type='order' AND g.log_type='order_note'
          AND i.status IN ('resolved','dropped') AND i.updated_at IS NOT NULL
          AND DATE(i.updated_at) BETWEEN ? AND ?
          AND ot.ate IN ({$in})");
    $st->execute([$from, $to]);

    $sum = 0.0; $cnt = 0; $perUser = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $start = $r['asked_at'] ?: substr((string)$r['created_at'], 0, 10);
        $end = substr((string)$r['updated_at'], 0, 10);
        $days = ul_workdays_between($db, $start, $end);
        if ($days === null) continue;
        $sum += $days; $cnt++;
        $oid = (int)$r['order_id'];
        $uid = $ateByOrder[$oid] ?? 0;
        if (!isset($perUser[$uid])) $perUser[$uid] = ['sum' => 0.0, 'cnt' => 0];
        $perUser[$uid]['sum'] += $days;
        $perUser[$uid]['cnt']++;
    }
    $out['avg_reply_workdays'] = $cnt > 0 ? round($sum / $cnt, 2) : null;
    foreach ($perUser as $uid => $pd) {
        if (!isset($out['by_designer'][$uid])) $out['by_designer'][$uid] = ['open' => 0, 'avg_days' => null];
        $out['by_designer'][$uid]['avg_days'] = $pd['cnt'] > 0 ? round($pd['sum'] / $pd['cnt'], 2) : null;
    }

    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * C. 業務課
 *
 * 資料來源：quotation_list（created_by 存 user.id 字串）、order_track（Created_By=打單人員）、
 *          eng_log（透過訂單反查開放中設計備註問題）。
 * ══════════════════════════════════════════════════════════════════ */

/** 本期彙總：報價單數量／報價單明細總筆數／訂單追蹤筆數／待回覆問題筆數（現況快照） */
function ul_sales_summary(PDO $db, string $from, string $to, array $salesIds): array
{
    $out = ['quote_count' => 0, 'quote_item_count' => 0, 'order_count' => 0, 'open_issue_count' => 0];
    $ids = ul_ids_norm($salesIds);
    if (!$ids) return $out;
    $inQ = implode(',', array_map(fn($v) => "'" . $v . "'", $ids));

    $st = $db->prepare("SELECT COUNT(*) FROM quotation_list
        WHERE is_draft=0 AND DATE(quote_date) BETWEEN ? AND ? AND created_by IN ({$inQ})");
    $st->execute([$from, $to]);
    $out['quote_count'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM quotation_item qi
        JOIN quotation_list ql ON ql.quote_id = qi.quote_id
        WHERE ql.is_draft=0 AND DATE(ql.quote_date) BETWEEN ? AND ? AND ql.created_by IN ({$inQ})");
    $st->execute([$from, $to]);
    $out['quote_item_count'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM order_track
        WHERE Order_status<>6 AND DATE(Created_At) BETWEEN ? AND ? AND Created_By IN ({$inQ})");
    $st->execute([$from, $to]);
    $out['order_count'] = (int)$st->fetchColumn();

    $orderIds = array_map('intval', $db->query(
        "SELECT Order_id FROM order_track WHERE Order_status<>6 AND Created_By IN ({$inQ})"
    )->fetchAll(PDO::FETCH_COLUMN));
    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        $out['open_issue_count'] = array_sum($openMap);
    }

    return $out;
}

/** ul_sales_summary() 的逐人版本，依 order_track.Created_By／quotation_list.created_by 分組 */
function ul_sales_by_person(PDO $db, string $from, string $to, array $salesIds): array
{
    $ids = ul_ids_norm($salesIds);
    if (!$ids) return [];
    $inQ = implode(',', array_map(fn($v) => "'" . $v . "'", $ids));

    $people = eg_people_list($db, ['user_ids' => $ids]);
    $byId = [];
    foreach ($people as $p) $byId[(int)$p['id']] = $p;

    $out = [];
    foreach ($ids as $uid) {
        $p = $byId[$uid] ?? null;
        $out[$uid] = [
            'user_id' => $uid,
            'name' => $p['user_cname'] ?? ('#' . $uid),
            'dept_name' => $p['dept_name'] ?? '',
            'position_name' => $p['position_name'] ?? '',
            'quote_count' => 0, 'quote_item_count' => 0, 'order_count' => 0, 'open_issue_count' => 0,
        ];
    }

    $st = $db->prepare("SELECT created_by k, COUNT(*) c FROM quotation_list
        WHERE is_draft=0 AND DATE(quote_date) BETWEEN ? AND ? AND created_by IN ({$inQ})
        GROUP BY created_by");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['quote_count'] = (int)$r['c'];
    }

    $st = $db->prepare("SELECT ql.created_by k, COUNT(*) c FROM quotation_item qi
        JOIN quotation_list ql ON ql.quote_id = qi.quote_id
        WHERE ql.is_draft=0 AND DATE(ql.quote_date) BETWEEN ? AND ? AND ql.created_by IN ({$inQ})
        GROUP BY ql.created_by");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['quote_item_count'] = (int)$r['c'];
    }

    $st = $db->prepare("SELECT Created_By k, COUNT(*) c FROM order_track
        WHERE Order_status<>6 AND DATE(Created_At) BETWEEN ? AND ? AND Created_By IN ({$inQ})
        GROUP BY Created_By");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['order_count'] = (int)$r['c'];
    }

    $orderRows = $db->query(
        "SELECT Order_id, Created_By FROM order_track WHERE Order_status<>6 AND Created_By IN ({$inQ})"
    )->fetchAll(PDO::FETCH_ASSOC);
    $orderIds = []; $createdByOf = [];
    foreach ($orderRows as $r) { $oid = (int)$r['Order_id']; $orderIds[] = $oid; $createdByOf[$oid] = (int)$r['Created_By']; }
    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        foreach ($openMap as $oid => $cnt) {
            if ($cnt <= 0) continue;
            $uid = $createdByOf[$oid] ?? 0;
            if (isset($out[$uid])) $out[$uid]['open_issue_count'] += $cnt;
        }
    }

    return array_values($out);
}

/* ══════════════════════════════════════════════════════════════════
 * D. 生管
 *
 * 資料來源：bom_ing（processing_state：N/P/Q/ing/E）、maker_list（internal=1＝廠內加工）、
 *          acc_track_lib.php 的 act_ap_rows()（對帳進度追蹤，status='processing'＝尚未對帳完成）。
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 生管本期彙總。
 * outsource_wip/internal_wip/to_qc/pending_transfer/transferred 這五項是「目前狀態」的
 * 即時快照（processing_state 沒有時間戳可以切期間），$from/$to 刻意不用在這五項上——
 * 跟 ul_design_summary() 的「批圖中」、「問題訂單數」是同一種道理。
 * pending_recon_* 才是真正有日期意義的指標：依 $from/$to 跨到的每個帳款月份，
 * 呼叫既有的 act_ap_rows() 取「尚未對帳完成」的廠商家數與筆數——**要看 card_status
 * 不是原始的 status**：act_track_get_or_create() 刻意把從未操作過的狀態存成空字串
 * （使用者明確要求「不要自動顯示處理中」，見 acc_track_lib.php 同一支函式的註解），
 * card_status 才會依結帳日把它分成 need_recon（結帳日已到、還沒人動）／
 * not_due（還沒到結帳日，本來就不該算待辦）；pending＝card_status 落在
 * need_recon 或 processing 這兩種。
 * $pmIds 目前未使用——bom_ing 沒有任何「這張製令由哪個生管負責」的欄位
 * （Created_By/Modified_By 只是操作當下的帳號，不是逐人負責歸屬），見 ul_pm_by_person()。
 */
function ul_pm_summary(PDO $db, string $from, string $to, array $pmIds): array
{
    $out = ['outsource_wip' => 0, 'internal_wip' => 0, 'to_qc' => 0, 'pending_transfer' => 0,
            'transferred' => 0, 'pending_recon_parties' => 0, 'pending_recon_lines' => 0];

    $rows = $db->query("SELECT bi.processing_state AS st, m.internal AS internal
                        FROM bom_ing bi
                        LEFT JOIN maker_list m ON m.maker_id_no = bi.maker_id_no")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $st = (string)$r['st'];
        $isInternal = (int)($r['internal'] ?? 0) === 1;
        if ($st === 'ing') { if ($isInternal) $out['internal_wip']++; else $out['outsource_wip']++; }
        elseif ($st === 'Q') $out['to_qc']++;
        elseif ($st === 'P') $out['pending_transfer']++;
        elseif ($st === 'E') $out['transferred']++;
    }

    $months = ul_billing_months_between($from, $to);
    $parties = [];
    $lines = 0;
    foreach ($months as $m) {
        try {
            foreach (act_ap_rows($db, $m) as $r) {
                $cs = (string)($r['card_status'] ?? '');
                if (!in_array($cs, ['need_recon', 'processing'], true)) continue;
                $parties[$r['party_key']] = true;
                $lines += (int)($r['cnt'] ?? 0);
            }
        } catch (Throwable $e) { /* 該月查無資料或對帳進度追蹤表尚未建立時不中斷整支函式 */ }
    }
    $out['pending_recon_parties'] = count($parties);
    $out['pending_recon_lines'] = $lines;

    return $out;
}

/**
 * 生管逐人明細——目前刻意回傳空陣列：bom_ing 沒有任何欄位記著「這張製令由哪個生管負責」，
 * Created_By/Modified_By 存的是該筆紀錄被建立/修改當下操作者的帳號（多半是 ERP 匯入或
 * 任何人按下某個動作時留下的），不是逐人負責歸屬，勉強拿來分組只會算出一堆誤導的數字。
 * 等現場真的有「哪個生管負責哪張製令」這個資料來源時再補。
 */
function ul_pm_by_person(PDO $db, array $pmIds): array
{
    return [];
}
