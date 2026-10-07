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
/**
 * 這個單位鍵在設定裡勾選的部門，展開（含「含子部門」）後的部門 id 扁平清單。
 * 從 ul_dept_user_ids() 抽出來單獨一支（唯一實作），讓「這個單位的部門範圍是什麼」
 * 這件事只算一次、只存在一個地方——逐人明細（ul_design_by_person／ul_sales_by_person／
 * ul_qc_by_person）要用同一份部門範圍去挑「這個人在範圍內的哪一筆職務」，不可以各自
 * 重新解析設定，否則範圍解析規則（含子部門展開）遲早會跟 ul_dept_user_ids() 走鐘。
 */
function ul_unit_dept_ids(PDO $db, array $settings, string $unitKey): array
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
    return array_values(array_unique(array_filter($deptIds, fn($v) => $v > 0)));
}

function ul_dept_user_ids(PDO $db, array $settings, string $unitKey): array
{
    $deptIds = ul_unit_dept_ids($db, $settings, $unitKey);
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

/**
 * 「這張 bom_ing 對應的 BOM（製令）還沒結案」條件，供任何「現況快照型」的
 * bom_ing.processing_state 計數共用拼進 WHERE（AND 接上即可）——已結案的 BOM
 * 底下常有殘留的 bom_ing 製程列（歷史原因沒有同步清掉 processing_state），
 * 不排除的話會被誤算成「還在加工中／還沒指派」，把現況負荷灌水。
 *
 * 判斷「這張 BOM 是否已結案」一律用 bom.processing_state='1'，不是 bom.closed_at
 * ——closed_at 只有 2026-05-22 之後手動結案才會填，92% 已結案的舊資料這欄是 NULL，
 * 用它判斷會漏掉大量已結案 BOM（見本機記憶 bom_closed_at_gap.md）。
 * 實測全庫 bom.processing_state 只有 '1'（已結案）或 NULL（未結案）兩種值。
 *
 * @param string $bomIngAlias bom_ing 在該查詢裡的別名（預設 'bi'）
 * @return string 可直接用 "AND " . ul_bom_active_cond('bi') 接進 WHERE 子句的條件字串
 */
function ul_bom_active_cond(string $bomIngAlias = 'bi'): string
{
    return "NOT EXISTS (SELECT 1 FROM bom _bc WHERE _bc.bom = {$bomIngAlias}.bom AND COALESCE(_bc.processing_state,'')='1')";
}

/**
 * 「現在正卡在等這一關」的 bom_ing 列——每張現役 BOM 只取「最早那個還沒走完的站」
 * （bom_sn 最小、該站還沒全部移轉完成），不是把整條製程路線裡還排在後面、根本
 * 還沒輪到的站（processing_state='N'）全部當成「現在卡著」。
 *
 * 2026-10-07 使用者對照「已完工BOM查詢列印」頁（OreadyReply_ForPm_BaseOfTime.php，
 * 工具列顯示「現役 596 張製令」，查證其口徑＝`bom.d_id<>'' AND bom.processing_state
 * IS NULL`，與 ul_bom_active_cond() 排除已結案 BOM 之後的集合逐欄核對完全一致）
 * 回報：生產課「未指派」機台數加總遠超過這個量級。查證發現根因不是已結案的 BOM
 * 混進來（ul_bom_active_cond() 早已排除、且 596 這個數字本身就驗證過一致），而是
 * 舊版「進行中」定義把每張 BOM 整條製程路線裡還沒輪到的站全部算進來——N 站佔全部
 * 候選列的 68%（實測 2301/3370），一張 BOM 走完常要經過 5、6 關，只有第一個還沒
 * 結束的那一關才是「現在」真的在等指派機台，後面那幾關連發包日都還沒填
 * （bomp_derive_state()：兩個日期都空才是 N，輪到了才會被填發包日離開 N），
 * 算進去只會把「現況負荷」灌成「整條路線還剩幾關」。
 *
 * 判定「這一站算不算已經走完」：同一個 (bom,bom_sn) 底下，先排除 is_consumed=1
 * （已被取代的歷史列——OreadyReply_ForPm_BaseOfTime.php 第684/740行就是靠這個旗標
 * 判定「活躍批次」／「已消耗批次不列入」，同一套規則搬過來用），剩下的列若全部是
 * processing_state='E' 才算這一關已經結束；is_schedule_split=1（process_schedule_NOW.php
 * 的「拆分製程」另開給別台機台同時加工的那一份，沿用同一個 bom_sn）刻意不排除——
 * 那不是重複列，是這一關被拆成兩份同時進行，各自仍是要指派機台的真實工作項目
 * （實測確實有 bom_sn 同時掛著「原列已驗 Q、machine_id=12」與「拆分列 P、
 * machine_id=NULL」兩筆，都是當下真實存在的工作）。
 *
 * 實測：596 張現役 BOM 下取出 605 張「還有未完成站」的 BOM、共 608 筆現在站
 * （少數 BOM 因拆分同時有 2 筆現在站），未指派 601／已指派 7——量級終於貼近
 * 596 這個基準，不再是 3000 多筆。
 *
 * @return array 每列 ['bom_ing_fid','bom','process_no','machine_id','processing_state']
 */
function ul_prod_current_step_rows(PDO $db): array
{
    $sql = "
        WITH step_done AS (
            SELECT bom, bom_sn,
                   (SUM(CASE WHEN processing_state<>'E' OR processing_state IS NULL THEN 1 ELSE 0 END)=0) AS all_done
            FROM bom_ing
            WHERE is_consumed = 0
            GROUP BY bom, bom_sn
        ),
        cur_sn AS (
            SELECT bom, MIN(bom_sn) AS cur_sn FROM step_done WHERE all_done = 0 GROUP BY bom
        )
        SELECT bi.bom_ing_fid, bi.bom, bi.process_no, bi.machine_id, bi.processing_state
        FROM bom_ing bi
        JOIN cur_sn c ON c.bom = bi.bom AND c.cur_sn = bi.bom_sn
        WHERE bi.is_consumed = 0
          AND " . ul_bom_active_cond('bi') . "
    ";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
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
function ul_design_by_person(PDO $db, string $from, string $to, array $designerIds, array $deptIds = []): array
{
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return [];
    $in = implode(',', $ids);

    // $deptIds（本單位設定範圍展開後的部門 id，見 ul_unit_dept_ids()）要一起傳進去——
    // eg_people_list() 不給 dept_ids 時，一人多職務會挑「職級最高」那一筆顯示（全站通用
    // 預設），兼任者因此會被顯示成他在別的部門的高階兼職（例：主職技術課工程師、兼任
    // 生管組組長，會被印成「生管組／組長」），而不是「讓他出現在這份設計課名單裡的那一
    // 筆職務」。帶上 dept_ids 後 eg_people_list() 的揀選順序會優先取「部門落在此範圍內」
    // 的那一筆，才會正確顯示「技術課／工程師」。
    $people = $deptIds ? eg_people_list($db, ['user_ids' => $ids, 'dept_ids' => $deptIds])
                       : eg_people_list($db, ['user_ids' => $ids]);
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
function ul_sales_by_person(PDO $db, string $from, string $to, array $salesIds, array $deptIds = []): array
{
    $ids = ul_ids_norm($salesIds);
    if (!$ids) return [];
    $inQ = implode(',', array_map(fn($v) => "'" . $v . "'", $ids));

    // $deptIds：見 ul_design_by_person() 同一段註解，兼任者要顯示「讓他出現在業務課這份
    // 名單裡的那一筆職務」，不是他職級最高的那一筆（可能是別的部門）。
    $people = $deptIds ? eg_people_list($db, ['user_ids' => $ids, 'dept_ids' => $deptIds])
                       : eg_people_list($db, ['user_ids' => $ids]);
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
                        LEFT JOIN maker_list m ON m.maker_id_no = bi.maker_id_no
                        WHERE " . ul_bom_active_cond('bi'))->fetchAll(PDO::FETCH_ASSOC);
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

/* ══════════════════════════════════════════════════════════════════
 * E. 生產課
 *
 * 資料來源：bom_ing（processing_state：N未發包／ing加工中／Q待QC驗／P生管待移轉／
 *          E已移轉，排除 E 才算「進行中」——規則同 bom_process_date_lib.php 的狀態推導
 *          註解）JOIN process_no／process_type（製程大類）、pm_process_daily_report
 *          （報工紀錄：setup_user_id＝架機人員、production_user_id＝生產人員，兩者可能
 *          是不同人）。
 *
 * $prodUserIds 的用法並不統一：①由製程大類彙總現況（ul_prod_by_process_type）與抓
 * 系統缺口（ul_prod_untracked_reports）兩種是「這批工作目前的狀態／系統流程哪裡斷掉」，
 * 跟哪個人報的無關，刻意不按人員篩選（$prodUserIds 保留只是讓六支函式介面一致，
 * 同 ul_pm_summary() 的 $pmIds 先例）②真正算工作量（每日產出／架機與生產時間／人均）
 * 的三支一定要篩，否則算出來的是全公司的量不是這個單位的量。
 * ══════════════════════════════════════════════════════════════════ */

if (!defined('UL_PROD_SETUP_MAX_MINUTES')) define('UL_PROD_SETUP_MAX_MINUTES', 1440);
if (!defined('UL_PROD_PRODUCTION_MAX_MINUTES')) define('UL_PROD_PRODUCTION_MAX_MINUTES', 1440);

/**
 * 製程編號→（製程名稱／製程大類 id／製程大類名稱）對照，全站這兩張表都很小，
 * 整張撈起來在記憶體裡查，不逐筆各查一次。
 */
function ul_process_type_map(PDO $db): array
{
    $out = [];
    foreach ($db->query(
        "SELECT pn.ProcessNo pno, pn.ProcessName pname, pt.process_type_id ptid, pt.process_type ptname
         FROM process_no pn LEFT JOIN process_type pt ON pt.process_type_id = pn.process_type_id"
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['pno']] = [
            'process_name' => (string)$r['pname'],
            'process_type_id' => $r['ptid'] !== null ? (int)$r['ptid'] : 0,
            'process_type_name' => $r['ptname'] !== null && $r['ptname'] !== '' ? (string)$r['ptname'] : '（未設定製程大類）',
        ];
    }
    return $out;
}

/**
 * 依「製程大類」分組的現況快照（不受 $from/$to 限制，跟 ul_pm_summary() 的「目前狀態」
 * 五項同一種道理——processing_state 沒有時間戳可以切期間）：未指派(machine_id IS NULL)／
 * 已指派(machine_id IS NOT NULL) 的「目前這一關」（每張現役 BOM 只取最早那個還沒走完的
 * 站，見 ul_prod_current_step_rows() 註解）筆數——不是整條製程路線裡還沒輪到的所有站。
 * @return array 每列 ['process_type_id','process_type_name','unassigned','assigned','total']，依 total 由大到小排序
 */
function ul_prod_by_process_type(PDO $db, string $from, string $to, array $prodUserIds = []): array
{
    $typeMap = ul_process_type_map($db);
    $rows = ul_prod_current_step_rows($db);

    $buckets = [];
    foreach ($rows as $r) {
        $pno = $r['process_no'] !== null ? (int)$r['process_no'] : 0;
        $info = $typeMap[$pno] ?? ['process_type_id' => 0, 'process_type_name' => '（未設定製程大類）'];
        $ptid = $info['process_type_id'];
        if (!isset($buckets[$ptid])) $buckets[$ptid] = ['name' => $info['process_type_name'], 'unassigned' => 0, 'assigned' => 0];
        if ($r['machine_id'] === null) $buckets[$ptid]['unassigned']++;
        else $buckets[$ptid]['assigned']++;
    }

    $out = [];
    foreach ($buckets as $ptid => $b) {
        $out[] = [
            'process_type_id' => $ptid, 'process_type_name' => $b['name'],
            'unassigned' => $b['unassigned'], 'assigned' => $b['assigned'],
            'total' => $b['unassigned'] + $b['assigned'],
        ];
    }
    usort($out, fn($x, $y) => $y['total'] <=> $x['total']);
    return $out;
}

/**
 * 生管沒有正式指派/移轉、但現場已經直接報工的筆數：報工紀錄（pm_process_daily_report）
 * 存在，但對應的 bom_ing 當時查無此筆、或完全沒指派機台、或仍停在最初狀態（N／NULL）。
 * 分組用「報工自己登記的 process_no」而不是 bom_ing.process_no——報工跟 bom_ing 對不起來
 * 正是本函式要抓的情況，拿對不起來的那一邊分組沒有意義。
 * 這是「系統流程哪裡斷掉」的缺口偵測，不是某個人的工作量，刻意不按 $prodUserIds 篩選。
 * @return array ['by_process_type'=>[每類筆數，依數量由大到小], 'examples'=>最多20筆範例, 'total'=>int]
 */
function ul_prod_untracked_reports(PDO $db, string $from, string $to, array $prodUserIds = []): array
{
    $typeMap = ul_process_type_map($db);
    $st = $db->prepare(
        "SELECT r.process_no, r.report_date, bi.bom AS bom_no
         FROM pm_process_daily_report r
         LEFT JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
         WHERE r.report_date BETWEEN ? AND ?
           AND (bi.bom_ing_fid IS NULL OR bi.machine_id IS NULL
                OR bi.processing_state IS NULL OR bi.processing_state = 'N')
           AND (bi.bom_ing_fid IS NULL OR " . ul_bom_active_cond('bi') . ")"
    );
    $st->execute([$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $buckets = [];
    $examples = [];
    foreach ($rows as $r) {
        $pno = (int)$r['process_no'];
        $info = $typeMap[$pno] ?? ['process_type_id' => 0, 'process_type_name' => '（未設定製程大類）', 'process_name' => '#' . $pno];
        $ptid = $info['process_type_id'];
        if (!isset($buckets[$ptid])) $buckets[$ptid] = ['name' => $info['process_type_name'], 'count' => 0];
        $buckets[$ptid]['count']++;
        if (count($examples) < 20) {
            $examples[] = [
                'bom' => $r['bom_no'] ?: '（找不到對應製令）',
                'process_name' => $info['process_name'] ?? ('#' . $pno),
                'report_date' => $r['report_date'],
            ];
        }
    }

    $out = [];
    foreach ($buckets as $ptid => $b) $out[] = ['process_type_id' => $ptid, 'process_type_name' => $b['name'], 'count' => $b['count']];
    usort($out, fn($x, $y) => $y['count'] <=> $x['count']);

    return ['by_process_type' => $out, 'examples' => $examples, 'total' => count($rows)];
}

/**
 * 每日報工加工數量，依製程大類分組；只算這批 $prodUserIds（生產課人員）裡「實際生產」
 * 的那個人報的工（production_user_id——跟負責架機的 setup_user_id 是不同角色，架機時間
 * 另見 ul_prod_setup_stats()）。$prodUserIds 為空直接回傳空結構（跟其他「沒有人員就沒有
 * 數字」的既有函式同規則，例如 ul_design_summary()）。
 * @return array ['rows'=>[每日×製程大類：report_date/process_type_id/process_type_name/qty/cnt],
 *                'total_qty'=>int,'total_cnt'=>int]
 */
function ul_prod_daily_output(PDO $db, string $from, string $to, array $prodUserIds): array
{
    $empty = ['rows' => [], 'total_qty' => 0, 'total_cnt' => 0];
    $ids = ul_ids_norm($prodUserIds);
    if (!$ids) return $empty;
    $in = implode(',', $ids);
    $typeMap = ul_process_type_map($db);

    $st = $db->prepare(
        "SELECT report_date, process_no, SUM(produced_qty) qty, COUNT(*) cnt
         FROM pm_process_daily_report
         WHERE report_date BETWEEN ? AND ? AND production_user_id IN ({$in})
         GROUP BY report_date, process_no ORDER BY report_date"
    );
    $st->execute([$from, $to]);

    $rows = []; $totalQty = 0; $totalCnt = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pno = (int)$r['process_no'];
        $info = $typeMap[$pno] ?? ['process_type_id' => 0, 'process_type_name' => '（未設定製程大類）'];
        $qty = (int)$r['qty']; $cnt = (int)$r['cnt'];
        $rows[] = [
            'report_date' => $r['report_date'],
            'process_type_id' => $info['process_type_id'], 'process_type_name' => $info['process_type_name'],
            'qty' => $qty, 'cnt' => $cnt,
        ];
        $totalQty += $qty; $totalCnt += $cnt;
    }
    return ['rows' => $rows, 'total_qty' => $totalQty, 'total_cnt' => $totalCnt];
}

/**
 * 每日架機數與平均架機時間（setup_start_time~setup_end_time），篩 setup_user_id（架機
 * 人員，跟實際生產的 production_user_id 是不同角色）。平均只算頭尾都有填、且落在
 * 0~UL_PROD_SETUP_MAX_MINUTES 分鐘（預設 1440＝24小時，常數方便之後調整）之間的紀錄，
 * 排除資料異常的離群值（例如忘了填結束時間被系統預設成隔天的髒資料）。
 * @return array ['daily'=>[每日：report_date/count], 'avg_minutes'=>float|null,
 *                'total_minutes'=>float,'valid_count'=>int]
 */
function ul_prod_setup_stats(PDO $db, string $from, string $to, array $prodUserIds): array
{
    $empty = ['daily' => [], 'avg_minutes' => null, 'total_minutes' => 0.0, 'valid_count' => 0];
    $ids = ul_ids_norm($prodUserIds);
    if (!$ids) return $empty;
    $in = implode(',', $ids);

    $st = $db->prepare(
        "SELECT report_date, setup_start_time, setup_end_time
         FROM pm_process_daily_report
         WHERE report_date BETWEEN ? AND ? AND setup_user_id IN ({$in})
           AND setup_start_time IS NOT NULL AND setup_end_time IS NOT NULL"
    );
    $st->execute([$from, $to]);

    $dailyCount = [];
    $sumMin = 0.0; $validCnt = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = (string)$r['report_date'];
        $dailyCount[$d] = ($dailyCount[$d] ?? 0) + 1;
        $mins = (strtotime((string)$r['setup_end_time']) - strtotime((string)$r['setup_start_time'])) / 60;
        if ($mins >= 0 && $mins <= UL_PROD_SETUP_MAX_MINUTES) { $sumMin += $mins; $validCnt++; }
    }
    $daily = [];
    foreach ($dailyCount as $d => $c) $daily[] = ['report_date' => $d, 'count' => $c];
    usort($daily, fn($x, $y) => strcmp($x['report_date'], $y['report_date']));

    return [
        'daily' => $daily,
        'avg_minutes' => $validCnt > 0 ? round($sumMin / $validCnt, 1) : null,
        'total_minutes' => round($sumMin, 1),
        'valid_count' => $validCnt,
    ];
}

/**
 * 平均生產時間（production_start_time~production_end_time），邏輯與 ul_prod_setup_stats()
 * 完全對稱，只是角色換成實際生產的人（production_user_id）、常數換成
 * UL_PROD_PRODUCTION_MAX_MINUTES（預設一樣 1440 分鐘，獨立開一個常數方便之後分開調整）。
 * @return array ['daily'=>[每日：report_date/count], 'avg_minutes'=>float|null,
 *                'total_minutes'=>float,'valid_count'=>int]
 */
function ul_prod_production_stats(PDO $db, string $from, string $to, array $prodUserIds): array
{
    $empty = ['daily' => [], 'avg_minutes' => null, 'total_minutes' => 0.0, 'valid_count' => 0];
    $ids = ul_ids_norm($prodUserIds);
    if (!$ids) return $empty;
    $in = implode(',', $ids);

    $st = $db->prepare(
        "SELECT report_date, production_start_time, production_end_time
         FROM pm_process_daily_report
         WHERE report_date BETWEEN ? AND ? AND production_user_id IN ({$in})
           AND production_start_time IS NOT NULL AND production_end_time IS NOT NULL"
    );
    $st->execute([$from, $to]);

    $dailyCount = [];
    $sumMin = 0.0; $validCnt = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = (string)$r['report_date'];
        $dailyCount[$d] = ($dailyCount[$d] ?? 0) + 1;
        $mins = (strtotime((string)$r['production_end_time']) - strtotime((string)$r['production_start_time'])) / 60;
        if ($mins >= 0 && $mins <= UL_PROD_PRODUCTION_MAX_MINUTES) { $sumMin += $mins; $validCnt++; }
    }
    $daily = [];
    foreach ($dailyCount as $d => $c) $daily[] = ['report_date' => $d, 'count' => $c];
    usort($daily, fn($x, $y) => strcmp($x['report_date'], $y['report_date']));

    return [
        'daily' => $daily,
        'avg_minutes' => $validCnt > 0 ? round($sumMin / $validCnt, 1) : null,
        'total_minutes' => round($sumMin, 1),
        'valid_count' => $validCnt,
    ];
}

/**
 * 人均負荷：分母＝期間內這批 $prodUserIds 裡「真的有報工」的人數（COUNT DISTINCT
 * production_user_id，忽略 NULL，限定在 $prodUserIds 之內——不限定的話會把全公司任何人
 * 都算進分母，跟本函式「這個單位人均負荷多少」的語意不合）；分子直接重用
 * ul_prod_daily_output()／ul_prod_setup_stats()／ul_prod_production_stats() 已經算好的
 * 加總值，不重新寫一份查詢（單一職責，三支各自的規則改了這裡自動跟上）。
 * @return array ['worker_count'=>int,'avg_output_per_person'=>float|null,
 *                'avg_setup_minutes_per_person'=>float|null,'avg_production_minutes_per_person'=>float|null]
 */
function ul_prod_per_capita(PDO $db, string $from, string $to, array $prodUserIds): array
{
    $out = ['worker_count' => 0, 'avg_output_per_person' => null,
            'avg_setup_minutes_per_person' => null, 'avg_production_minutes_per_person' => null];
    $ids = ul_ids_norm($prodUserIds);
    if (!$ids) return $out;
    $in = implode(',', $ids);

    $st = $db->prepare(
        "SELECT COUNT(DISTINCT production_user_id) FROM pm_process_daily_report
         WHERE report_date BETWEEN ? AND ? AND production_user_id IN ({$in})"
    );
    $st->execute([$from, $to]);
    $workerCount = (int)$st->fetchColumn();
    $out['worker_count'] = $workerCount;
    if ($workerCount <= 0) return $out;

    $output = ul_prod_daily_output($db, $from, $to, $ids);
    $setup = ul_prod_setup_stats($db, $from, $to, $ids);
    $prod = ul_prod_production_stats($db, $from, $to, $ids);

    $out['avg_output_per_person'] = round($output['total_qty'] / $workerCount, 1);
    $out['avg_setup_minutes_per_person'] = round($setup['total_minutes'] / $workerCount, 1);
    $out['avg_production_minutes_per_person'] = round($prod['total_minutes'] / $workerCount, 1);
    return $out;
}

/**
 * 包裝負荷：判定「包裝製程」一律用 process_no.ProcessName='包裝'（實測全庫對應
 * ProcessNo 168／169，皆屬 process_type_id=16「雷刻與包裝」；該大類底下的 ProcessNo=16
 * 「雷刻」不是包裝，不可用 process_type_id 當判準，否則會把雷刻一起算進去）——優先用
 * 名稱比對不寫死製程代號，現場往後加新的包裝代號只要掛進同一個 ProcessName 就自動算進來。
 *
 *  ①待包裝筆數：現況快照（不受 $from/$to 限制，跟 ul_pm_summary() 的「目前狀態」同一種
 *    道理），bom_ing 的包裝這一關還沒有任何 is_finished=1 完工報工、且排除對應 BOM 已結案
 *    （ul_bom_active_cond()）。
 *  ②每日包裝完成筆數與平均處理工作天數：對期間內（report_date 落在區間）包裝完工
 *    （is_finished=1）的報工逐筆回推「進入待包裝」到「完成包裝」的工作天數——
 *    完成時間＝production_end_time（缺值退 report_date）；進入時間＝同一張 BOM（bom_ing.bom）
 *    裡 bom_sn 小於這一關、且最接近（最大）的前一關，取該關最新一筆 is_finished=1 的
 *    production_end_time（缺值退 report_date）；包裝是這張 BOM 第一關（找不到更小 bom_sn
 *    的已完工前一關）時退回 bom_ing.Created_At，查無該值才再退回 bom.Created_At（這張
 *    製令本身的建立時間，比完全算不出來合理）。這一段是歷史資料統計不是現況快照，
 *    刻意不加 ul_bom_active_cond()（已結案的 BOM 一樣有真實發生過的包裝歷程要算）。
 * @return array ['pending'=>int,
 *                'daily'=>[每日：report_date/count/avg_workdays，依日期由舊到新],
 *                'avg_workdays'=>float|null,
 *                'longest'=>最多5筆[bom/bom_ing_fid/report_date/enter_at/finish_at/workdays]，
 *                'shortest'=>同結構最多5筆]
 */
function ul_prod_packing_stats(PDO $db, string $from, string $to): array
{
    $out = ['pending' => 0, 'daily' => [], 'avg_workdays' => null, 'longest' => [], 'shortest' => []];

    $stPending = $db->query(
        "SELECT COUNT(*) FROM bom_ing bi
         JOIN process_no pn ON pn.ProcessNo = bi.process_no
         WHERE pn.ProcessName='包裝'
           AND NOT EXISTS (
               SELECT 1 FROM pm_process_daily_report pdr
               WHERE pdr.bom_ing_fid = bi.bom_ing_fid AND pdr.process_no = bi.process_no AND pdr.is_finished = 1
           )
           AND " . ul_bom_active_cond('bi')
    );
    $out['pending'] = (int)$stPending->fetchColumn();

    $st = $db->prepare(
        "SELECT r.bom_ing_fid, r.report_date, r.production_end_time,
                bi.bom AS bom_no, bi.bom_sn AS bom_sn, bi.Created_At AS bi_created_at
         FROM pm_process_daily_report r
         JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
         JOIN process_no pn ON pn.ProcessNo = r.process_no
         WHERE pn.ProcessName='包裝' AND r.is_finished = 1
           AND r.report_date BETWEEN ? AND ?
         ORDER BY r.report_date"
    );
    $st->execute([$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return $out;

    $prevStmt = $db->prepare(
        "SELECT bi2.bom_ing_fid
         FROM bom_ing bi2
         WHERE bi2.bom = ? AND bi2.bom_sn < ? AND bi2.bom_sn IS NOT NULL
           AND EXISTS (SELECT 1 FROM pm_process_daily_report pdr2
                       WHERE pdr2.bom_ing_fid = bi2.bom_ing_fid AND pdr2.is_finished = 1)
         ORDER BY bi2.bom_sn DESC LIMIT 1"
    );
    $finishStmt = $db->prepare(
        "SELECT production_end_time, report_date FROM pm_process_daily_report
         WHERE bom_ing_fid = ? AND is_finished = 1
         ORDER BY COALESCE(production_end_time, report_date) DESC LIMIT 1"
    );
    $bomCreatedStmt = $db->prepare("SELECT Created_At FROM bom WHERE bom = ? LIMIT 1");

    $dailyAgg = [];
    $wdRows = [];
    foreach ($rows as $r) {
        $finishAt = $r['production_end_time'] ?: $r['report_date'];
        $bomSn = $r['bom_sn'] !== null ? (int)$r['bom_sn'] : null;

        $enterAt = null;
        if ($bomSn !== null) {
            $prevStmt->execute([$r['bom_no'], $bomSn]);
            $prevFid = $prevStmt->fetchColumn();
            if ($prevFid) {
                $finishStmt->execute([$prevFid]);
                $pf = $finishStmt->fetch(PDO::FETCH_ASSOC);
                if ($pf) $enterAt = $pf['production_end_time'] ?: $pf['report_date'];
            }
        }
        if ($enterAt === null) {
            $enterAt = $r['bi_created_at'] ?: null;
            if (!$enterAt) {
                $bomCreatedStmt->execute([$r['bom_no']]);
                $enterAt = $bomCreatedStmt->fetchColumn() ?: null;
            }
        }
        if (!$enterAt) continue; // 連製令建立時間都查不到，不勉強算成 0 天，直接不計入

        $days = ul_workdays_between($db, substr((string)$enterAt, 0, 10), substr((string)$finishAt, 0, 10));
        if ($days === null) continue;

        $d = (string)$r['report_date'];
        if (!isset($dailyAgg[$d])) $dailyAgg[$d] = ['count' => 0, 'sum' => 0];
        $dailyAgg[$d]['count']++;
        $dailyAgg[$d]['sum'] += $days;

        $wdRows[] = [
            'bom' => $r['bom_no'], 'bom_ing_fid' => (int)$r['bom_ing_fid'], 'report_date' => $d,
            'enter_at' => $enterAt, 'finish_at' => $finishAt, 'workdays' => $days,
        ];
    }

    if (!$wdRows) return $out;

    $dailyOut = [];
    foreach ($dailyAgg as $d => $v) {
        $dailyOut[] = ['report_date' => $d, 'count' => $v['count'], 'avg_workdays' => round($v['sum'] / $v['count'], 2)];
    }
    usort($dailyOut, fn($x, $y) => strcmp($x['report_date'], $y['report_date']));
    $out['daily'] = $dailyOut;

    $sum = array_sum(array_column($wdRows, 'workdays'));
    $cnt = count($wdRows);
    $out['avg_workdays'] = $cnt > 0 ? round($sum / $cnt, 2) : null;

    $byDesc = $wdRows; usort($byDesc, fn($x, $y) => $y['workdays'] <=> $x['workdays']);
    $out['longest'] = array_slice($byDesc, 0, 5);
    $byAsc = $wdRows; usort($byAsc, fn($x, $y) => $x['workdays'] <=> $y['workdays']);
    $out['shortest'] = array_slice($byAsc, 0, 5);

    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * F. 品管
 *
 * 資料來源：qc_check_form（檢驗單，inspector_by／approved_by 是 char(11) 存 user.id
 *          字串，比照 bom_ing.Created_By 同一種存法）、bom_ing（qc_completed／
 *          qc_completed_at／qc_completed_by）、qa_abnormal_order（異常單，deleted_at
 *          IS NOT NULL 一律排除軟刪除）。
 *
 * $qcUserIds 的用法同樣不統一：待驗等待工作天（ul_qc_wait_time）跟異常單／NG比例
 * （ul_qc_abnormal_stats）、脫離流程的補檢驗（ul_qc_adhoc）都是「這批工作項目本身的
 * 狀態」，不是某個品管的工作量，刻意不按人篩選（ul_qc_wait_time 的 $qcUserIds 只用在
 * 算人均檢驗天數的分母）；真正要看「這個人今天做了多少」的 ul_qc_daily_items／
 * ul_qc_by_person 才會篩。
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 目前待驗佇列筆數（現況快照，不受 $from/$to 限制，跟 ul_pm_summary() 的「目前狀態」
 * 五項同一種道理——processing_state 沒有時間戳可以切期間）。
 *
 * 2026-10-07 使用者回報本頁數字（286 筆）與 `views/QC/QC_check_list_test.php`
 * （實際畫面上「QC 待驗清單」用的那一支，端點 `src/store/_fetch_qc_data_test.php`）
 * 顯示的「共 99 筆」對不起來。查證後兩邊從一開始就是兩套獨立判定：舊版只簡單篩
 * `processing_state='Q'`，但官方那一支還同時篩了 ⑴`processing_state IN ('Q','P')`
 * （'P'＝生管待移轉，但只要 `qc_completed=0` 就代表品管只做了部分動作、整批還沒
 * 驗完，一樣要留在待驗佇列上）⑵`qc_completed=0`（已按完成的不再算待驗）⑶
 * `is_consumed=0`（排除被取代的歷史列）⑷排除「同一個 bom_sn 底下已經有更新一批
 * outsource_date 的 ing/E/P 批次」（這一批已經被後面新的一批蓋過去，不該再列）
 * ⑸排除標著「(拆分工單)」的拆分列 ⑹排除被設定為「包裝製程」的站別（包裝有自己
 * 獨立的檢驗流程）⑺只取「目前製程」那個 bom_sn（同 bom 裡還沒輪到的站不算）。
 * 這支改成逐字沿用同一套條件（實測改完後筆數對齊），**兩邊沒有共用函式可以直接
 * 呼叫**（判定寫在 `_fetch_qc_data_test.php` 內嵌 SQL 裡，本次修改範圍不含那支
 * 檔案）——往後任一邊改了判定規則都要回來同步，不要讓兩套條件各自演進。
 *
 * 每個製程再附一個「平均檢驗工作天數」——這是歷史統計（本期已完成檢驗、從進入待驗到
 * 驗完的平均工作天），跟上面「目前筆數」是兩個不同語意：筆數不受期間限制、是即時
 * 快照；平均工作天數才受 $from/$to 限制。直接重用 ul_qc_wait_time() 已經算好的逐筆
 * 明細依 process_no 分組平均，不重新寫一次 SQL（鐵律4）。$from/$to 任一個沒傳（或空）
 * 時，整支函式的「目前筆數」行為完全不變，只是每個製程的 avg_wait_workdays 回 null
 * （沒有期間就不硬湊一個平均值）。
 * @return array ['by_process'=>[每製程：process_no/process_name/count/avg_wait_workdays，
 *                依 count 由大到小], 'total'=>int]
 */
function ul_qc_pending_queue(PDO $db, ?string $from = null, ?string $to = null): array
{
    require_once __DIR__ . '/packing_process_lib.php';
    $packingNos = pk_packing_process_nos($db);
    $packingExclSql = $packingNos ? (' AND bi.process_no NOT IN (' . implode(',', array_map('intval', $packingNos)) . ')') : '';

    $st = $db->query(
        "SELECT bi.process_no, COUNT(*) c
         FROM bom_ing bi
         JOIN (
             SELECT bom, COALESCE(bom_sn, -1) AS sn, COALESCE(batch_label, '') AS bl, MAX(outsource_date) AS max_date
             FROM bom_ing
             WHERE processing_state IN ('Q','P') AND is_consumed = 0
             GROUP BY bom, COALESCE(bom_sn, -1), COALESCE(batch_label, '')
         ) latest ON bi.bom = latest.bom
                 AND COALESCE(bi.bom_sn, -1) = latest.sn
                 AND bi.outsource_date = latest.max_date
                 AND COALESCE(bi.batch_label, '') = latest.bl
         LEFT JOIN bom_ing newer ON
             newer.bom = bi.bom
             AND COALESCE(newer.bom_sn, -1) = COALESCE(bi.bom_sn, -1)
             AND newer.outsource_date > bi.outsource_date
             AND newer.processing_state IN ('ing','E','P')
             AND COALESCE(newer.batch_label, '') = COALESCE(bi.batch_label, '')
             AND newer.is_consumed = 0
         JOIN bom b ON bi.bom = b.bom
         WHERE b.processing_state IS NULL
           AND bi.processing_state IN ('Q','P')
           AND bi.qc_completed = 0
           AND bi.is_consumed = 0
           AND newer.bom_ing_fid IS NULL
           AND (bi.ps IS NULL OR bi.ps NOT LIKE '%(拆分工單)%')
           {$packingExclSql}
           AND COALESCE(bi.bom_sn, -1) = (
               SELECT COALESCE(cur.bom_sn, -1) FROM bom_ing cur
               WHERE cur.bom = bi.bom AND cur.processing_state IN ('Q','P','ing','E')
                 AND cur.outsource_date IS NOT NULL AND cur.is_schedule_split = 0 AND cur.is_consumed = 0
               ORDER BY cur.outsource_date DESC, COALESCE(cur.bom_sn, -1) DESC LIMIT 1
           )
         GROUP BY bi.process_no"
    );
    $typeMap = ul_process_type_map($db);
    $byProcess = [];
    $total = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pno = $r['process_no'] !== null ? (int)$r['process_no'] : 0;
        $cnt = (int)$r['c'];
        $info = $typeMap[$pno] ?? ['process_name' => '（未設定製程）'];
        $byProcess[] = [
            'process_no' => $pno,
            'process_name' => $info['process_name'] ?? ('#' . $pno),
            'count' => $cnt,
            'avg_wait_workdays' => null,
        ];
        $total += $cnt;
    }

    if ($byProcess && $from !== null && $to !== null && $from !== '' && $to !== '') {
        $wait = ul_qc_wait_time($db, $from, $to, []);
        $sums = []; $cnts = [];
        foreach ($wait['rows'] as $r) {
            $pno = (int)$r['process_no'];
            $sums[$pno] = ($sums[$pno] ?? 0) + $r['workdays'];
            $cnts[$pno] = ($cnts[$pno] ?? 0) + 1;
        }
        foreach ($byProcess as &$bp) {
            $pno = $bp['process_no'];
            if (($cnts[$pno] ?? 0) > 0) $bp['avg_wait_workdays'] = round($sums[$pno] / $cnts[$pno], 2);
        }
        unset($bp);
    }

    usort($byProcess, fn($x, $y) => $y['count'] <=> $x['count']);
    return ['by_process' => $byProcess, 'total' => $total];
}

/**
 * 每日檢驗項目數與製程名稱，只算這批 $qcUserIds（品管人員，inspector_by 或 approved_by
 * 任一是其中一人）自己經手的（排除 status='DRAFT'——草稿還沒定案，不算正式完成的檢驗
 * 項目；要統計「待驗中」的另開一支，不要混進這支）。日期一律用 check_date，缺值退回
 * created_at 當天（補登資料常常沒填 check_date）。$qcUserIds 為空直接回傳空陣列。
 *
 * 2026-10-07：同 ul_qc_by_person() 查證到的同一個缺口——現場天天在用的 `qc_check`
 * 表完全沒被算進每日趨勢圖，這批品管人員全年在 `qc_check_form` 只有個位數紀錄。
 * 已補上第二段查詢（JOIN bom_ing/process_no 取得製程名稱），兩段結果直接合併回傳；
 * 前端 renderQcDailyChart() 本來就是依 (check_date,process_name) 累加，重複的鍵
 * 會自動加總，這裡不必先在 PHP 端合併。
 * @return array 每列 ['check_date','process_name','count']
 */
function ul_qc_daily_items(PDO $db, string $from, string $to, array $qcUserIds): array
{
    $ids = ul_ids_norm($qcUserIds);
    if (!$ids) return [];
    $inQ = implode(',', array_map(fn($v) => "'" . $v . "'", $ids));
    $inN = implode(',', $ids);

    $st = $db->prepare(
        "SELECT COALESCE(check_date, DATE(created_at)) AS d, process_name, COUNT(*) c
         FROM qc_check_form
         WHERE status<>'DRAFT'
           AND COALESCE(check_date, DATE(created_at)) BETWEEN ? AND ?
           AND (inspector_by IN ({$inQ}) OR approved_by IN ({$inQ}))
         GROUP BY d, process_name ORDER BY d"
    );
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['check_date' => $r['d'], 'process_name' => (string)$r['process_name'], 'count' => (int)$r['c']];
    }

    $st2 = $db->prepare(
        "SELECT COALESCE(DATE(c.QC_check_date), DATE(c.created_at)) AS d,
                COALESCE(pn.ProcessName, '（未設定製程）') AS process_name, COUNT(*) cnt
         FROM qc_check c
         LEFT JOIN bom_ing bi ON bi.bom_ing_fid = c.bom_ing_fid_ref
         LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
         WHERE COALESCE(DATE(c.QC_check_date), DATE(c.created_at)) BETWEEN ? AND ?
           AND COALESCE(c.created_by, c.updated_by) IN ({$inN})
         GROUP BY d, process_name ORDER BY d"
    );
    $st2->execute([$from, $to]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['check_date' => $r['d'], 'process_name' => (string)$r['process_name'], 'count' => (int)$r['cnt']];
    }

    return $out;
}

/**
 * 待驗等待工作天（重點函式）：對期間內「驗完」的每一筆 bom_ing，算「進入待驗」到
 * 「驗完」之間的工作天數。
 *   驗完時間：優先 bom_ing.qc_completed_at；缺值時退回該 bom_ing_fid 名下
 *             qc_check_form 狀態=LOCKED 的最新 approved_at（補舊資料、或沒有走
 *             qc_completed 流程留下紀錄的單據）。
 *   進入待驗時間：外包（maker_list.internal<>1）用 bom_ing.return_date（回廠日）；
 *                 廠內（internal=1，或查無對照廠商資訊時保守當廠內）用該
 *                 bom_ing_fid+process_no 名下最後一筆 is_finished=1 報工的
 *                 production_end_time（缺值退 Created_At）。
 * 這是「這一筆製令在品管手上排了多久」的指標，跟是哪一位品管驗的沒有關係——公司目前
 * 只有一個共用的待驗佇列，所以刻意不按 $qcUserIds 篩掉任何一列；$qcUserIds 只用來算
 * 「人均檢驗天數」這一項分母（供 ul_qc_by_person() 重用逐筆明細再依人分組）。
 * $internalMakerFlagJoin：可選的 [maker_id_no=>bool internal] 覆寫對照表，呼叫端已經
 * 查過一次 maker_list 時可以直接傳進來省一次查詢；留空（預設）時自己查。
 * @return array ['rows'=>逐筆明細（含 person_id，供 ul_qc_by_person() 分組重用）,
 *                'avg_workdays'=>float|null,'longest'=>最長前5筆,'shortest'=>最短前5筆,
 *                'per_capita_workdays'=>float|null]
 */
function ul_qc_wait_time(PDO $db, string $from, string $to, array $qcUserIds = [], ?array $internalMakerFlagJoin = null): array
{
    $out = ['rows' => [], 'avg_workdays' => null, 'longest' => [], 'shortest' => [], 'per_capita_workdays' => null];

    $st = $db->prepare(
        "SELECT bom_ing_fid, bom, process_no, maker_id_no, return_date, qc_completed_at, qc_completed_by
         FROM bom_ing
         WHERE qc_completed=1 AND qc_completed_at IS NOT NULL
           AND DATE(qc_completed_at) BETWEEN ? AND ?"
    );
    $st->execute([$from, $to]);
    $candidates = [];
    $seenFid = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fid = (int)$r['bom_ing_fid'];
        $seenFid[$fid] = true;
        $candidates[$fid] = [
            'fid' => $fid, 'bom' => $r['bom'], 'process_no' => (int)$r['process_no'],
            'maker_id_no' => $r['maker_id_no'], 'return_date' => $r['return_date'],
            'finish_at' => $r['qc_completed_at'],
            'person_id' => $r['qc_completed_by'] !== null ? (int)$r['qc_completed_by'] : null,
        ];
    }

    // 補：qc_completed_at 缺值的，退回同一張 bom_ing 名下 qc_check_form LOCKED 最新的 approved_at
    $st2 = $db->query(
        "SELECT qf.bom_ing_fid, qf.approved_at, qf.approved_by, qf.inspector_by
         FROM qc_check_form qf
         JOIN bom_ing bi ON bi.bom_ing_fid = qf.bom_ing_fid
         WHERE qf.status='LOCKED' AND qf.approved_at IS NOT NULL AND qf.bom_ing_fid>0
           AND bi.qc_completed_at IS NULL"
    );
    $fallbackBest = [];
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fid = (int)$r['bom_ing_fid'];
        if (isset($seenFid[$fid])) continue;
        if (!isset($fallbackBest[$fid]) || (string)$r['approved_at'] > (string)$fallbackBest[$fid]['approved_at']) {
            $fallbackBest[$fid] = $r;
        }
    }
    $fallbackFids = [];
    foreach ($fallbackBest as $fid => $r) {
        $d = substr((string)$r['approved_at'], 0, 10);
        if ($d >= $from && $d <= $to) $fallbackFids[] = $fid;
    }
    if ($fallbackFids) {
        $in2 = implode(',', $fallbackFids);
        foreach ($db->query(
            "SELECT bom_ing_fid, bom, process_no, maker_id_no, return_date FROM bom_ing WHERE bom_ing_fid IN ({$in2})"
        )->fetchAll(PDO::FETCH_ASSOC) as $bi) {
            $fid = (int)$bi['bom_ing_fid'];
            $fb = $fallbackBest[$fid];
            $personRaw = ($fb['approved_by'] !== null && $fb['approved_by'] !== '') ? $fb['approved_by'] : $fb['inspector_by'];
            $candidates[$fid] = [
                'fid' => $fid, 'bom' => $bi['bom'], 'process_no' => (int)$bi['process_no'],
                'maker_id_no' => $bi['maker_id_no'], 'return_date' => $bi['return_date'],
                'finish_at' => $fb['approved_at'],
                'person_id' => ($personRaw !== null && $personRaw !== '' && ctype_digit((string)$personRaw)) ? (int)$personRaw : null,
            ];
        }
    }

    if (!$candidates) return $out;

    $makerIds = array_values(array_unique(array_filter(array_column($candidates, 'maker_id_no'))));
    $internalMap = $internalMakerFlagJoin;
    if ($internalMap === null) {
        $internalMap = [];
        if ($makerIds) {
            $inM = implode(',', array_map(fn($v) => $db->quote($v), $makerIds));
            foreach ($db->query("SELECT maker_id_no, internal FROM maker_list WHERE maker_id_no IN ({$inM})")->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $internalMap[$m['maker_id_no']] = ((int)($m['internal'] ?? 0)) === 1;
            }
        }
    }

    $typeMap = ul_process_type_map($db);
    $rowsOut = [];
    foreach ($candidates as $c) {
        $hasMaker = !empty($c['maker_id_no']);
        if (!$hasMaker) {
            $isInternal = true; // 查無廠商資訊，保守當廠內製程，不猜成外包
        } elseif (array_key_exists($c['maker_id_no'], $internalMap)) {
            $isInternal = (bool)$internalMap[$c['maker_id_no']];
        } else {
            $isInternal = true; // 有廠商編號但查不到對照資料，同樣保守當廠內
        }

        $enterAt = null;
        if (!$isInternal) {
            $enterAt = $c['return_date'];
        } else {
            $stp = $db->prepare(
                "SELECT production_end_time, Created_At FROM pm_process_daily_report
                 WHERE bom_ing_fid=? AND process_no=? AND is_finished=1
                 ORDER BY COALESCE(production_end_time, Created_At) DESC LIMIT 1"
            );
            $stp->execute([$c['fid'], $c['process_no']]);
            $pr = $stp->fetch(PDO::FETCH_ASSOC);
            if ($pr) $enterAt = $pr['production_end_time'] ?: $pr['Created_At'];
        }
        if (!$enterAt) continue; // 查不到進入待驗時間，不勉強算成 0 天，直接不計入

        $days = ul_workdays_between($db, substr((string)$enterAt, 0, 10), substr((string)$c['finish_at'], 0, 10));
        if ($days === null) continue;

        $info = $typeMap[$c['process_no']] ?? ['process_name' => '#' . $c['process_no']];
        $rowsOut[] = [
            'bom_ing_fid' => $c['fid'], 'bom' => $c['bom'],
            'process_no' => $c['process_no'], 'process_name' => $info['process_name'] ?? ('#' . $c['process_no']),
            'enter_at' => $enterAt, 'finish_at' => $c['finish_at'],
            'workdays' => $days, 'person_id' => $c['person_id'],
        ];
    }

    if (!$rowsOut) return $out;
    $out['rows'] = $rowsOut;

    $sum = array_sum(array_column($rowsOut, 'workdays'));
    $cnt = count($rowsOut);
    $out['avg_workdays'] = $cnt > 0 ? round($sum / $cnt, 2) : null;

    $byDesc = $rowsOut; usort($byDesc, fn($x, $y) => $y['workdays'] <=> $x['workdays']);
    $out['longest'] = array_slice($byDesc, 0, 5);
    $byAsc = $rowsOut; usort($byAsc, fn($x, $y) => $x['workdays'] <=> $y['workdays']);
    $out['shortest'] = array_slice($byAsc, 0, 5);

    $qcIds = ul_ids_norm($qcUserIds);
    $out['per_capita_workdays'] = count($qcIds) > 0 ? round($sum / count($qcIds), 2) : null;

    return $out;
}

/**
 * 每日/每週異常單數量與異常比例。異常單數依 occurrence_date（缺值退 created_at 當天）
 * 分組，排除軟刪除（deleted_at IS NOT NULL）；異常比例＝當日 NG 檢驗筆數
 * （qc_check_form.check_result='NG'，排除 DRAFT）÷ 當日總檢驗筆數，依日分組。
 * avg_abnormal_per_day 用「期間內的日曆天數」當分母（不只是有異常單的那幾天——沒異常單
 * 的日子本來就該算進分母，否則平均值會被墊高）；avg_ng_rate 是「有檢驗資料的那些天」的
 * 簡單平均（不是用總筆數加權），比較貼近「平常日子異常比例大概多少」的語感。
 * 異常單沒有欄位記著「是哪個品管驗出來的」（responsible_person_id 是缺失的責任歸屬，
 * 不是品管本人），這兩項是公司整體的品質狀況，刻意不按 $qcUserIds 篩選，保留參數只是
 * 讓本節函式介面一致。
 * @return array ['daily_abnormal'=>[每日：date/count],'daily_ng_rate'=>[每日：date/rate(0~1)/ng_count/total_count],
 *                'avg_abnormal_per_day'=>float,'avg_ng_rate'=>float|null]
 */
function ul_qc_abnormal_stats(PDO $db, string $from, string $to, array $qcUserIds = []): array
{
    $st = $db->prepare(
        "SELECT COALESCE(occurrence_date, DATE(created_at)) AS d, COUNT(*) c
         FROM qa_abnormal_order
         WHERE deleted_at IS NULL
           AND COALESCE(occurrence_date, DATE(created_at)) BETWEEN ? AND ?
         GROUP BY d ORDER BY d"
    );
    $st->execute([$from, $to]);
    $dailyAbnormal = [];
    $totalAbnormal = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dailyAbnormal[] = ['date' => $r['d'], 'count' => (int)$r['c']];
        $totalAbnormal += (int)$r['c'];
    }

    $st2 = $db->prepare(
        "SELECT COALESCE(check_date, DATE(created_at)) AS d,
                SUM(CASE WHEN check_result='NG' THEN 1 ELSE 0 END) AS ng_c, COUNT(*) AS cnt
         FROM qc_check_form
         WHERE status<>'DRAFT'
           AND COALESCE(check_date, DATE(created_at)) BETWEEN ? AND ?
         GROUP BY d ORDER BY d"
    );
    $st2->execute([$from, $to]);
    $dailyNgRate = [];
    $rateSum = 0.0; $rateCnt = 0;
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cnt = (int)$r['cnt'];
        if ($cnt <= 0) continue;
        $rate = (int)$r['ng_c'] / $cnt;
        $dailyNgRate[] = ['date' => $r['d'], 'rate' => round($rate, 4), 'ng_count' => (int)$r['ng_c'], 'total_count' => $cnt];
        $rateSum += $rate; $rateCnt++;
    }

    $calendarDays = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($calendarDays < 1) $calendarDays = 1;

    return [
        'daily_abnormal' => $dailyAbnormal,
        'daily_ng_rate' => $dailyNgRate,
        'avg_abnormal_per_day' => round($totalAbnormal / $calendarDays, 2),
        'avg_ng_rate' => $rateCnt > 0 ? round($rateSum / $rateCnt, 4) : null,
    ];
}

/**
 * ul_qc_daily_items() 的依人彙總版本：各人檢驗筆數、NG筆數、平均等待工作天——
 * 平均等待工作天直接重用 ul_qc_wait_time() 已經算好的逐筆明細再依 person_id 分組，
 * 不重新寫一份 SQL（鐵律4）。
 *
 * 2026-10-07 使用者回報「各人負荷明細」檢驗筆數與NG筆數全部是 0，並指明要比對
 * `views/QC/QC_check_list.php`（正式版）內的異常／允收資料跟完成資料。查證後：
 * ①不是期間太窄——用整個 2026 年重算，筆數依然幾乎是 0 ②真因是本函式原本只讀
 * `qc_check_form`（線上檢驗 v2 的結構化表單，全年對這批品管人員合計只有 6 筆），
 * 但現場實際天天在用、記錄「異常(QQ)／允收(ok)」的是 `QC_check_list.php` 走的
 * `qc_check` 表（`_updateQC_check_list_QQ.php`／`_updateQC_check_list_ok.php` 寫入
 * `created_by`/`updated_by`＝操作當下登入者），全年同一批人在這張表合計將近
 * 9,000 筆，本函式完全沒讀到——這是邏輯漏算，不是「交接期資料真的很少」。
 * 已改成兩張表的筆數相加：`qc_check_form`（既有邏輯不動）＋`qc_check`
 * （COALESCE(created_by,updated_by) 分組，日期用 QC_check_date 缺值退 created_at
 * 當天，比照既有 date fallback 慣例）。NG 數同理相加，但實測全庫 `qc_check.QC_check`
 * 只曾出現過 'ok'／'QQ' 兩種值，從未記錄過 'ng'——這一項目前加總後仍可能是 0，
 * 那是現場真的沒有用 `qc_check` 的 'ng' 這條路記錄不良（『異常(QQ)』在這套流程裡
 * 代表特採／需進一步確認，不等同驗退 NG），不是本次漏算的範圍；若之後要把「NG」
 * 擴充到涵蓋 `qa_abnormal_order`（source_type='QC'）等其他異常來源，需要另外問
 * 使用者要不要納入，本次不擅自擴大。
 * 兩張表偶有同一個 bom_ing_fid 都留下紀錄的情況（實測 17 筆裡有 14 筆重疊）——
 * 兩者是不同的記錄機制（qc_check 是逐批快速登記，qc_check_form 是較嚴謹的線上
 * 檢驗單，常見於首件/末件/出貨檢驗），刻意當成兩筆各自獨立的檢驗動作相加，
 * 不嘗試去重。
 * @param array $deptIds 本單位設定範圍展開後的部門 id（見 ul_unit_dept_ids()），用於挑
 *              人員顯示用的部門/職稱——見 ul_design_by_person() 同一段註解，不帶的話兼任者
 *              會被顯示成他職級最高的那個（可能不在品管）兼任職務，不是讓他出現在這份
 *              品管名單裡的那一筆。
 * @return array 每列 ['user_id','name','dept_name','position_name','items_count','ng_count','avg_wait_workdays']
 */
function ul_qc_by_person(PDO $db, string $from, string $to, array $qcUserIds, array $deptIds = []): array
{
    $ids = ul_ids_norm($qcUserIds);
    if (!$ids) return [];
    $inQ = implode(',', array_map(fn($v) => "'" . $v . "'", $ids));
    $inN = implode(',', $ids);

    $people = $deptIds ? eg_people_list($db, ['user_ids' => $ids, 'dept_ids' => $deptIds])
                       : eg_people_list($db, ['user_ids' => $ids]);
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
            'items_count' => 0, 'ng_count' => 0, 'avg_wait_workdays' => null,
        ];
    }

    $st = $db->prepare(
        "SELECT COALESCE(inspector_by, approved_by) AS person,
                SUM(CASE WHEN check_result='NG' THEN 1 ELSE 0 END) AS ng_c, COUNT(*) AS cnt
         FROM qc_check_form
         WHERE status<>'DRAFT'
           AND COALESCE(check_date, DATE(created_at)) BETWEEN ? AND ?
           AND COALESCE(inspector_by, approved_by) IN ({$inQ})
         GROUP BY person"
    );
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['person'];
        if (isset($out[$uid])) { $out[$uid]['items_count'] = (int)$r['cnt']; $out[$uid]['ng_count'] = (int)$r['ng_c']; }
    }

    $st2 = $db->prepare(
        "SELECT COALESCE(created_by, updated_by) AS person,
                SUM(CASE WHEN QC_check='ng' THEN 1 ELSE 0 END) AS ng_c, COUNT(*) AS cnt
         FROM qc_check
         WHERE COALESCE(DATE(QC_check_date), DATE(created_at)) BETWEEN ? AND ?
           AND COALESCE(created_by, updated_by) IN ({$inN})
         GROUP BY person"
    );
    $st2->execute([$from, $to]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['person'];
        if (isset($out[$uid])) { $out[$uid]['items_count'] += (int)$r['cnt']; $out[$uid]['ng_count'] += (int)$r['ng_c']; }
    }

    $wait = ul_qc_wait_time($db, $from, $to, $ids);
    $sums = []; $cnts = [];
    foreach ($wait['rows'] as $r) {
        $uid = $r['person_id'];
        if ($uid === null || !in_array($uid, $ids, true)) continue;
        $sums[$uid] = ($sums[$uid] ?? 0) + $r['workdays'];
        $cnts[$uid] = ($cnts[$uid] ?? 0) + 1;
    }
    foreach ($cnts as $uid => $c) {
        if (isset($out[$uid]) && $c > 0) $out[$uid]['avg_wait_workdays'] = round($sums[$uid] / $c, 2);
    }

    return array_values($out);
}

/**
 * 未列入待驗由品管手動補檢驗：qc_check_form.bom_ing_fid=0（沒有掛在任何製程待驗佇列上）
 * AND status<>'DRAFT'，依 process_name 分組計數。日期用 check_date（缺值退 created_at
 * 當天）——這類單多半是補登或臨時抽驗，check_date 才是使用者認定的檢驗日，不是系統建檔
 * 時間。這是「有多少檢驗脫離了正常待驗流程」的系統缺口指標，跟是哪個品管做的無關，
 * 刻意不按人員篩選（同 ul_qc_abnormal_stats() 的道理）。
 * @return array ['by_process'=>[每項製程名稱：process_name/count，依數量由大到小], 'total'=>int]
 */
function ul_qc_adhoc(PDO $db, string $from, string $to): array
{
    $st = $db->prepare(
        "SELECT process_name, COUNT(*) c
         FROM qc_check_form
         WHERE bom_ing_fid=0 AND status<>'DRAFT'
           AND COALESCE(check_date, DATE(created_at)) BETWEEN ? AND ?
         GROUP BY process_name ORDER BY c DESC"
    );
    $st->execute([$from, $to]);
    $out = []; $total = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['process_name' => (string)$r['process_name'], 'count' => (int)$r['c']];
        $total += (int)$r['c'];
    }
    return ['by_process' => $out, 'total' => $total];
}

/* ══════════════════════════════════════════════════════════════════
 * G. 整頁自動分析與負荷門檻（通用，不綁定單一部門）
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 門檻預設值。鍵名風格「單位.指標」，之後管理員可在設定頁逐項覆寫（存進 ul_settings()
 * 的 thresholds，同一套鍵名、同一套清洗規則 ul_settings_clean_thresholds()）。
 * 數值只是給一個合理起點，不求精確，實際門檻由現場管理員調整。
 */
function ul_threshold_defaults(): array
{
    return [
        'design' => [
            'batch_pending' => ['value' => 20, 'label' => '批圖中筆數'],
            'avg_draw_workdays' => ['value' => 5, 'label' => '繪圖平均工作天'],
            'issue_orders' => ['value' => 10, 'label' => '設計備註待回覆訂單數'],
        ],
        'sales' => [
            'quote_backlog' => ['value' => 30, 'label' => '本期報價單筆數'],
            'open_issue_count' => ['value' => 20, 'label' => '待回覆問題筆數'],
        ],
        'pm' => [
            'outsource_wip' => ['value' => 100, 'label' => '委外加工中筆數'],
            'pending_recon_lines' => ['value' => 50, 'label' => '待對帳筆數'],
        ],
        'prod' => [
            'unassigned_count' => ['value' => 30, 'label' => '未指派機台筆數'],
            'avg_setup_minutes' => ['value' => 60, 'label' => '平均架機時間（分）'],
            'untracked_count' => ['value' => 10, 'label' => '未正式指派卻已報工筆數'],
        ],
        'qc' => [
            'ng_rate' => ['value' => 0.08, 'label' => 'NG比例'],
            'wait_days_avg' => ['value' => 5, 'label' => '待驗平均等待工作天'],
            'adhoc_count' => ['value' => 10, 'label' => '脫離待驗流程的補檢驗筆數'],
        ],
    ];
}

/** 把 ul_threshold_defaults() 的巢狀結構扁平成「單位.指標」=>['value'=>,'label'=>]，方便逐鍵查找 */
function ul_threshold_defaults_flat(): array
{
    $out = [];
    foreach (ul_threshold_defaults() as $unit => $items) {
        foreach ($items as $k => $v) $out[$unit . '.' . $k] = $v;
    }
    return $out;
}

/**
 * 依門檻判斷某個數值是否算「過重」。$key 用「單位.指標」風格（例如 'qc.ng_rate'）。
 * 門檻優先取 $thresholds（即 ul_settings() 回傳的 thresholds，可能是巢狀 ['qc'=>['ng_rate'=>5]]
 * 或扁平 ['qc.ng_rate'=>5] 兩種寫法都接受），查不到才退回 ul_threshold_defaults_flat()。
 * 「越低越糟」的判斷規則：鍵名以 '_rate' 結尾、但不是 ng_rate/abnormal_rate/defect_rate
 * 這幾種本來就「越高越糟」的異常比例，才視為達成率類（越低越糟）。這是簡化的經驗判斷，
 * 不追求完美，之後有需要再逐鍵指定方向。
 */
function ul_is_overload(float $value, string $key, array $thresholds = []): bool
{
    $th = null;
    if (isset($thresholds[$key]) && is_array($thresholds[$key]) && isset($thresholds[$key]['value'])) {
        $th = $thresholds[$key]['value'];
    } elseif (isset($thresholds[$key]) && is_numeric($thresholds[$key])) {
        $th = $thresholds[$key];
    } else {
        [$unit, $metric] = array_pad(explode('.', $key, 2), 2, '');
        if (isset($thresholds[$unit][$metric])) {
            $v = $thresholds[$unit][$metric];
            $th = is_array($v) ? ($v['value'] ?? null) : $v;
        }
    }
    if ($th === null) {
        $def = ul_threshold_defaults_flat();
        $th = $def[$key]['value'] ?? null;
    }
    if ($th === null) return false;
    $th = (float)$th;

    $lowBetter = false;
    if (str_ends_with($key, '_rate')) {
        $isBadWhenHigh = (bool)preg_match('/(ng_rate|abnormal_rate|defect_rate)$/', $key);
        $lowBetter = !$isBadWhenHigh;
    }

    return $lowBetter ? ($value < $th) : ($value > $th);
}

/**
 * 吃呼叫端已經組好的五個單位摘要資料，產生一組結論文字（純文字組合，本函式不查資料庫，
 * 單一職責——跟 order_analysis_lib.php 的 oa_insights() 同一種寫法）。
 * 預期輸入結構（每個單位鍵皆可省略，缺的那塊不產生結論；'cur' 底下放該單位對應函式的
 * 回傳值，'cmp' 放對比期間同一份資料可省略，'cmp_label' 預設「上一期」）：
 * [
 *   'design' => ['cur'=>ul_design_summary()回傳, 'cmp'=>?, 'cmp_label'=>?],
 *   'sales'  => ['cur'=>ul_sales_summary()回傳, 'cmp'=>?, 'cmp_label'=>?],
 *   'pm'     => ['cur'=>ul_pm_summary()回傳, 'cmp'=>?, 'cmp_label'=>?],
 *   'prod'   => ['by_process_type'=>ul_prod_by_process_type()回傳,
 *                'untracked'=>ul_prod_untracked_reports()回傳,
 *                'setup'=>ul_prod_setup_stats()回傳],
 *   'qc'     => ['wait'=>ul_qc_wait_time()回傳, 'abnormal'=>ul_qc_abnormal_stats()回傳,
 *                'adhoc'=>ul_qc_adhoc()回傳],
 * ]
 * @param array $thresholds ul_settings() 回傳的 thresholds（巢狀或扁平皆可，見 ul_is_overload()）
 * @return array 每列 ['level'=>good|warn|bad|info, 'title'=>, 'detail'=>, 'metric'=>]
 */
function ul_insights(array $allData, array $thresholds = []): array
{
    $out = [];
    $add = function ($level, $title, $detail, $metric = '') use (&$out) {
        $out[] = ['level' => $level, 'title' => $title, 'detail' => $detail, 'metric' => $metric];
    };

    // 設計課
    if (isset($allData['design']['cur'])) {
        $d = $allData['design']['cur'];
        $cl = $allData['design']['cmp_label'] ?? '上一期';
        $cmp = $allData['design']['cmp'] ?? null;
        if (isset($d['drawing_wip']) && ul_is_overload((float)$d['drawing_wip'], 'design.batch_pending', $thresholds)) {
            $add('bad', '設計課批圖中筆數偏高', '目前批圖中 ' . $d['drawing_wip'] . ' 筆（尚未轉生管、且設計備註仍有開放中問題）。', (string)$d['drawing_wip']);
        }
        if ($cmp && isset($d['drawing_wip'], $cmp['drawing_wip']) && (int)$cmp['drawing_wip'] > 0) {
            $delta = (int)$d['drawing_wip'] - (int)$cmp['drawing_wip'];
            if ($delta > 0) {
                $add('warn', '設計課批圖中筆數較' . $cl . '增加', '本期 ' . $d['drawing_wip'] . ' 筆，較' . $cl . '的 ' . $cmp['drawing_wip'] . ' 筆增加 ' . $delta . ' 筆。', '+' . $delta);
            }
        }
        if (isset($d['avg_draw_workdays']) && $d['avg_draw_workdays'] !== null
            && ul_is_overload((float)$d['avg_draw_workdays'], 'design.avg_draw_workdays', $thresholds)) {
            $add('warn', '設計課繪圖平均工作天偏長', '本期由業務轉設計到轉生管平均 ' . $d['avg_draw_workdays'] . ' 個工作天。', $d['avg_draw_workdays'] . ' 天');
        }
        if (isset($d['issue_orders']) && ul_is_overload((float)$d['issue_orders'], 'design.issue_orders', $thresholds)) {
            $add('warn', '設計課待回覆設計備註偏多', '目前有 ' . $d['issue_orders'] . ' 張訂單帶著開放中的設計備註問題。', (string)$d['issue_orders']);
        }
    }

    // 業務課
    if (isset($allData['sales']['cur'])) {
        $s = $allData['sales']['cur'];
        $cl = $allData['sales']['cmp_label'] ?? '上一期';
        $cmp = $allData['sales']['cmp'] ?? null;
        if (isset($s['quote_count']) && ul_is_overload((float)$s['quote_count'], 'sales.quote_backlog', $thresholds)) {
            $add('warn', '業務課本期報價單量偏高', '本期開立 ' . $s['quote_count'] . ' 張報價單。', (string)$s['quote_count']);
        }
        if ($cmp && isset($s['quote_count'], $cmp['quote_count']) && (int)$cmp['quote_count'] > 0) {
            $delta = (int)$s['quote_count'] - (int)$cmp['quote_count'];
            if (abs($delta) >= 5) {
                $add($delta > 0 ? 'warn' : 'info', '業務課報價單量較' . $cl . ($delta > 0 ? '增加' : '減少'),
                    '本期 ' . $s['quote_count'] . ' 張，' . $cl . ' ' . $cmp['quote_count'] . ' 張。', ($delta > 0 ? '+' : '') . $delta);
            }
        }
        if (isset($s['open_issue_count']) && ul_is_overload((float)$s['open_issue_count'], 'sales.open_issue_count', $thresholds)) {
            $add('warn', '業務課待回覆問題偏多', '目前累積 ' . $s['open_issue_count'] . ' 筆開放中設計備註問題。', (string)$s['open_issue_count']);
        }
    }

    // 生管
    if (isset($allData['pm']['cur'])) {
        $p = $allData['pm']['cur'];
        if (isset($p['outsource_wip']) && ul_is_overload((float)$p['outsource_wip'], 'pm.outsource_wip', $thresholds)) {
            $add('warn', '生管委外加工中筆數偏高', '目前委外加工中共 ' . $p['outsource_wip'] . ' 筆。', (string)$p['outsource_wip']);
        }
        if (isset($p['pending_recon_lines']) && ul_is_overload((float)$p['pending_recon_lines'], 'pm.pending_recon_lines', $thresholds)) {
            $add('bad', '生管待對帳筆數偏高', '目前待對帳 ' . ($p['pending_recon_parties'] ?? 0) . ' 家、共 ' . $p['pending_recon_lines'] . ' 筆。', (string)$p['pending_recon_lines']);
        }
    }

    // 生產課
    if (isset($allData['prod'])) {
        $pt = $allData['prod']['by_process_type'] ?? [];
        $totalUnassigned = array_sum(array_column($pt, 'unassigned'));
        if ($totalUnassigned > 0 && ul_is_overload((float)$totalUnassigned, 'prod.unassigned_count', $thresholds)) {
            $add('bad', '生產課未指派機台筆數偏高', '目前進行中的製程裡有 ' . $totalUnassigned . ' 筆還沒指派機台。', (string)$totalUnassigned);
        }
        $setup = $allData['prod']['setup'] ?? null;
        if ($setup && isset($setup['avg_minutes']) && $setup['avg_minutes'] !== null
            && ul_is_overload((float)$setup['avg_minutes'], 'prod.avg_setup_minutes', $thresholds)) {
            $add('warn', '生產課平均架機時間偏長', '本期平均架機時間 ' . $setup['avg_minutes'] . ' 分鐘。', $setup['avg_minutes'] . ' 分');
        }
        $untracked = $allData['prod']['untracked'] ?? null;
        if ($untracked && isset($untracked['total']) && ul_is_overload((float)$untracked['total'], 'prod.untracked_count', $thresholds)) {
            $add('bad', '生產課出現未正式指派卻已報工的件', '本期共 ' . $untracked['total'] . ' 筆報工對應的製程，當時沒有被正式指派機台或還沒進入正常流程。', (string)$untracked['total']);
        }
    }

    // 品管
    if (isset($allData['qc'])) {
        $ab = $allData['qc']['abnormal'] ?? null;
        if ($ab && isset($ab['avg_ng_rate']) && $ab['avg_ng_rate'] !== null
            && ul_is_overload((float)$ab['avg_ng_rate'], 'qc.ng_rate', $thresholds)) {
            $add('bad', '品管NG比例偏高', '本期平均每日NG比例約 ' . round($ab['avg_ng_rate'] * 100, 1) . '%。', round($ab['avg_ng_rate'] * 100, 1) . '%');
        }
        $wait = $allData['qc']['wait'] ?? null;
        if ($wait && isset($wait['avg_workdays']) && $wait['avg_workdays'] !== null
            && ul_is_overload((float)$wait['avg_workdays'], 'qc.wait_days_avg', $thresholds)) {
            $add('warn', '品管待驗平均等待工作天偏長', '本期完成檢驗的製程，平均等了 ' . $wait['avg_workdays'] . ' 個工作天才驗完。', $wait['avg_workdays'] . ' 天');
        }
        $adhoc = $allData['qc']['adhoc'] ?? null;
        if ($adhoc && isset($adhoc['total']) && ul_is_overload((float)$adhoc['total'], 'qc.adhoc_count', $thresholds)) {
            $add('warn', '品管脫離正常待驗流程的補檢驗偏多', '本期有 ' . $adhoc['total'] . ' 筆檢驗不是掛在正常待驗佇列上完成的。', (string)$adhoc['total']);
        }
    }

    return $out;
}
