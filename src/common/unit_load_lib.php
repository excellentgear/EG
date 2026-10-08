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
 *   期間切法：order_analysis_lib.php（oa_period_buckets 等）
 *   人員列表／含子部門展開：people_lib.php（eg_people_list）／org_role_lib.php（eg_dept_subtree_ids）
 *   工作日判定：leave_lib.php（eg_leave_is_workday）
 *   設計備註開放問題數：eng_log_lib.php（el_order_open_item_counts）
 *   待對帳家數/筆數（生管）：acc_track_lib.php（act_ap_rows，status='processing' 視為尚未對帳完成）
 *   新案件判定：data_audit_lib.php（dqa_bom_open_date，BOM 編號解析日期的唯一實作），
 *     見下方 ul_orders_new_case_map()。2026-10-07 使用者釐清這才是「新案件」真正的定義，
 *     取代更早的「系統裡首次出現的料號」（oa_first_seen，已不再使用）；同一天稍後又再次
 *     更正：不可以只看「NAS 現在有沒有圖面」（會被後補的圖面誤判），改成逐張訂單拿「這個
 *     料號最早一筆 bom 的日期」跟「這張訂單自己的接單日」比較，詳見函式註解。
 */

require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/leave_lib.php';
require_once __DIR__ . '/eng_log_lib.php';
require_once __DIR__ . '/order_analysis_lib.php';
require_once __DIR__ . '/acc_track_lib.php';
require_once __DIR__ . '/person_schedule_lib.php';

if (!defined('UL_PARAM_GROUP')) define('UL_PARAM_GROUP', 'UNIT_LOAD');

/**
 * 2026-10-08 新增：設計課「待回覆設計備註」／業務課「待回覆問題」這兩個「即時現況」指標
 * 查證後發現全部都是 2026-10-05 工程處理紀錄模組上線時，把訂單追蹤舊自由文字欄位
 * `ateNote`（只要還沒按「已處理」就永遠不會清空）整批搬成 eng_log 問題項的歷史遺留
 * 結果——實測 929 張設計課名下「待回覆」的訂單裡，只有 119 張（13%）是最近 90 天內
 * 下單的，810 張都是半年、一年以上的舊訂單，根本不是「這幾天的工作量」，門檻卻是照
 * 「本期應該有多少」的尺度設的（10／20），於是這兩個單位永遠卡在「負荷過重」，紅色
 * 警示因此失去信號意義。故把這兩個指標拆成「近 N 天下單、仍未回覆」（計入負荷過重
 * 判定）與「全部歷史總量」（只做參考，不計入判定）兩個數字，見 ul_design_summary()／
 * ul_sales_summary() 的 issue_orders_recent／open_issue_count_recent。
 */
if (!defined('UL_RECENT_ORDER_DAYS')) define('UL_RECENT_ORDER_DAYS', 90);

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

/**
 * 本模組目前已知的單位鍵。'packing'（包裝）2026-10-07 新增為獨立單位——人員範圍
 * （設定頁勾選的部門，實務上多為倉管組）與「這是不是包裝工作」的判定刻意分開：
 * 前者只決定「逐人負荷明細」要列哪些人，後者永遠看製程本身（process_no.ProcessName=
 * '包裝'，與既有 ul_prod_packing_stats() 同一套規則），bom_ing／pm_process_daily_report
 * 本來就沒有「負責人所屬部門」這種欄位可以拿來篩資料列。
 */
function ul_unit_keys(): array
{
    return ['design', 'sales', 'pm', 'prod', 'qc', 'packing'];
}

/**
 * 讀出設定：dept_cfg（每個單位對應的部門清單，可勾「含子部門」）＋ thresholds（之後各單位的門檻值）
 * ＋ prod_process_types（2026-10-07 新增：生產課「製程大類負荷」要列入哪些製程大類，管理員
 * 勾選的 process_type_id 清單，空陣列＝尚未設定、沿用舊行為顯示全部）＋ exclude_positions
 * （2026-10-07 新增：各單位「逐人負荷明細」要排除哪些職位，單位代碼=>position_id 陣列）。
 *
 * 回傳的 thresholds 裡，每個葉節點（'單位.指標'／巢狀 單位=>指標）可能是純數字（既有的
 * 門檻覆寫值，格式不變）或一個陣列 `['value'=>數字或缺省,'no_threshold'=>0/1]`——後者是
 * 2026-10-07 新增「不需要門檻」覆寫（見 ul_merge_no_threshold_overrides()）合併進來的
 * 結果，讓 ul_is_overload()／ul_threshold_value() 只要拿到這份 thresholds 就查得到完整
 * 設定，不必額外再傳一份；**刻意把 no_threshold 另外存成獨立的 `no_threshold` 參數鍵，
 * 不直接混進 `thresholds` 參數本身**——因為設定頁可能分區塊各自送出（見下方
 * ul_settings_save() 的 array_key_exists 規則），兩者存在一起的話，使用者只改門檻數值
 * 存檔時就會把「不需要門檻」的勾選狀態一併洗空，讀出來時再合併成同一份結構對外呈現，
 * 兩件事看起來像一件事、但各自獨立存取不會互相洗掉。
 * @return array ['dept_cfg'=>['design'=>[['dept_id'=>int,'include_sub'=>bool],...], 'sales'=>[...], ...],
 *                'thresholds'=>array,
 *                'prod_process_types'=>[int,...],
 *                'exclude_positions'=>['design'=>[int,...], 'sales'=>[...], ...]]
 */
function ul_settings(PDO $db): array
{
    $deptCfgRaw = ul_param_get($db, 'dept_cfg', []);
    if (!is_array($deptCfgRaw)) $deptCfgRaw = [];
    $thresholds = ul_param_get($db, 'thresholds', []);
    if (!is_array($thresholds)) $thresholds = [];
    $noThrRaw = ul_param_get($db, 'no_threshold', []);
    if (!is_array($noThrRaw)) $noThrRaw = [];
    $thresholds = ul_merge_no_threshold_overrides($thresholds, $noThrRaw);
    $pptRaw = ul_param_get($db, 'prod_process_types', []);
    $exclRaw = ul_param_get($db, 'exclude_positions', []);
    if (!is_array($exclRaw)) $exclRaw = [];

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

    $prodProcessTypes = is_array($pptRaw) ? ul_ids_norm($pptRaw) : [];

    $exclPositions = [];
    foreach (ul_unit_keys() as $k) {
        $list = isset($exclRaw[$k]) && is_array($exclRaw[$k]) ? $exclRaw[$k] : [];
        $exclPositions[$k] = ul_ids_norm($list);
    }

    return [
        'dept_cfg' => $deptCfg,
        'thresholds' => $thresholds,
        'prod_process_types' => $prodProcessTypes,
        'exclude_positions' => $exclPositions,
    ];
}

/**
 * 把獨立存放的「不需要門檻」覆寫（$noThrRaw，巢狀 單位=>指標=>0/1）併進 $thresholds
 * 結構裡——見 ul_settings() 函式註解。$thresholds 的既有葉節點可能是純數字（舊版唯一
 * 格式）或已經是陣列；有 no_threshold 覆寫的那個指標，一律轉成
 * `['value'=>原本的數值覆寫或不設,'no_threshold'=>0/1]`，沒有覆寫的指標完全不動
 * （維持純數字或完全不存在這個鍵，向後相容）。
 * @param array $thresholds ul_param_get($db,'thresholds',[]) 的原始值
 * @param array $noThrRaw ul_param_get($db,'no_threshold',[]) 的原始值
 * @return array 合併後的 thresholds（供 ul_settings() 回傳）
 */
function ul_merge_no_threshold_overrides(array $thresholds, array $noThrRaw): array
{
    foreach (ul_unit_keys() as $u) {
        $metrics = $noThrRaw[$u] ?? null;
        if (!is_array($metrics)) continue;
        foreach ($metrics as $mk => $flag) {
            if (!is_string($mk) || $mk === '') continue;
            $existing = $thresholds[$u][$mk] ?? null;
            if (is_array($existing)) {
                $thresholds[$u][$mk]['no_threshold'] = !empty($flag) ? 1 : 0;
            } else {
                $leaf = ['no_threshold' => !empty($flag) ? 1 : 0];
                if ($existing !== null && is_numeric($existing)) $leaf['value'] = $existing + 0;
                $thresholds[$u][$mk] = $leaf;
            }
        }
    }
    return $thresholds;
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
 * thresholds 裡的葉節點值都要是數字，非數字的整支略過；prod_process_types／exclude_positions
 * 裡的值都正規化成正整數陣列去重。
 *
 * dept_cfg／thresholds 沿用既有行為——呼叫端（設定頁）本來就是整份表單一起送出，沒送的鍵
 * 視同空陣列整個覆蓋。但 prod_process_types／exclude_positions／no_threshold（2026-10-07
 * 新增「不需要門檻」逐指標覆寫）是之後的設定頁可能分頁簽／分區塊各自送出（例如「製程
 * 大類顯示設定」跟「各單位部門設定」不在同一個表單），比照全站「沒送這個欄位＝不要動它、
 * 送空陣列才是刻意清空」的既有規則（array_key_exists 判斷有沒有送，不是判斷值是否為空），
 * 避免管理員只改部門設定、卻把已經設定好的製程大類清單或排除職位整批洗空。
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

    if (array_key_exists('prod_process_types', $in)) {
        $pptOut = is_array($in['prod_process_types']) ? ul_ids_norm($in['prod_process_types']) : [];
        ul_param_save($db, 'prod_process_types', $pptOut, $byStr);
    }

    if (array_key_exists('exclude_positions', $in)) {
        $exclIn = is_array($in['exclude_positions']) ? $in['exclude_positions'] : [];
        $exclOut = [];
        foreach (ul_unit_keys() as $k) {
            $list = isset($exclIn[$k]) && is_array($exclIn[$k]) ? $exclIn[$k] : [];
            $exclOut[$k] = ul_ids_norm($list);
        }
        ul_param_save($db, 'exclude_positions', $exclOut, $byStr);
    }

    // no_threshold（2026-10-07 新增，「不需要門檻」逐指標覆寫）：同 prod_process_types／
    // exclude_positions 一樣的 array_key_exists 規則——沒送這個鍵就完全不動既有覆寫，
    // 送了才整批覆蓋。值一律正規化成 0/1，非字串鍵（指標代碼）直接略過（鐵律8）。
    if (array_key_exists('no_threshold', $in)) {
        $ntIn = is_array($in['no_threshold']) ? $in['no_threshold'] : [];
        $ntOut = [];
        foreach (ul_unit_keys() as $k) {
            $metrics = isset($ntIn[$k]) && is_array($ntIn[$k]) ? $ntIn[$k] : [];
            $clean = [];
            foreach ($metrics as $mk => $v) {
                if (!is_string($mk) || $mk === '') continue;
                $clean[$mk] = !empty($v) ? 1 : 0;
            }
            $ntOut[$k] = $clean;
        }
        ul_param_save($db, 'no_threshold', $ntOut, $byStr);
    }

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
 * ul_dept_user_ids() 之上多一層「排除特定職位」的過濾（2026-10-07 新增）——使用者希望某個
 * 單位的部門範圍勾了一整個部門（例如技術課），但逐人負荷明細不想列出某些職位的人（例如
 * 課長、經理這種不實際動手畫圖的管理職）。
 *
 * **不是取代 ul_dept_user_ids()，是多一層過濾**：呼叫端把「逐人負荷明細」原本呼叫
 * ul_dept_user_ids() 的地方改成呼叫這支即可，其餘所有呼叫端（彙總卡片的人數篩選等）
 * 不受影響，繼續用原本那支。
 *
 * **不需要另外查 user_department_position_map 交叉比對**：ul_dept_user_ids() 內部是用
 * eg_people_list(['dept_ids'=>...,'all_posts'=>true]) 取得「一個職務一列」的展開結果
 * （見 people_lib.php 的 eg_people_expand_posts()），每一列天生就帶著 position_id，而且
 * eg_people_expand_posts() 已經把每個人的職務限定在 $deptIds 範圍內（濾掉同一人在範圍外
 * 的其他兼任職務）——這份清單本身就是「這個單位部門範圍內的職務列表」，直接濾掉
 * position_id 落在排除清單裡的列即可。
 *
 * @param array $settings ul_settings() 回傳的完整設定
 * @param string $unitKey 單位代碼（design/sales/pm/prod/qc/packing）
 * @return array 同 ul_dept_user_ids() 的回傳格式，已濾掉 settings['exclude_positions'][$unitKey]
 *               裡列出的職位；該單位沒有設定排除職位時原樣回傳（不複製、零額外成本）。
 */
function ul_dept_user_ids_filtered(PDO $db, array $settings, string $unitKey): array
{
    $rows = ul_dept_user_ids($db, $settings, $unitKey);
    if (!$rows) return $rows;

    $exclRaw = $settings['exclude_positions'][$unitKey] ?? [];
    $excl = is_array($exclRaw) ? ul_ids_norm($exclRaw) : [];
    if (!$excl) return $rows;

    $exclSet = array_flip($excl);
    return array_values(array_filter($rows, function ($r) use ($exclSet) {
        $pid = $r['position_id'] ?? null;
        return $pid === null || !isset($exclSet[(int)$pid]);
    }));
}

/**
 * 給設定頁「要排除哪些職位」的勾選清單用（2026-10-07 新增）：$deptIds（已展開含子部門）
 * 這些部門底下實際存在哪些職位——**只列「這個範圍內真的有人掛著」的職位，不是列出全公司的
 * 職位**，否則勾選清單會長到沒意義（全公司可能有幾十種職位，這個單位可能只用得到三五種）。
 * 在職判定比照 eg_people_list() 的既有規則（排除離職／特殊帳號／最高權限帳號），已經沒有
 * 真人掛著的舊職位不列入候選。
 * @param array $deptIds 部門 id（通常是 ul_unit_dept_ids() 展開後的結果，含子部門）
 * @return array 每列 ['position_id'=>int,'position_name'=>string]，依 sort_order／名稱排序、去重
 */
function ul_positions_in_depts(PDO $db, array $deptIds): array
{
    $ids = ul_ids_norm($deptIds);
    if (!$ids) return [];
    $in = implode(',', $ids);

    $rows = $db->query(
        "SELECT DISTINCT m.position_id, p.name AS position_name, COALESCE(p.sort_order, 999) AS position_sort
         FROM user_department_position_map m
         JOIN `user` u ON u.id = m.user_id
         LEFT JOIN position p ON p.id = m.position_id
         WHERE m.department_id IN ({$in}) AND u.state NOT IN (" . EG_PEOPLE_EXCLUDE_STATES . ")
         ORDER BY position_sort, position_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        if ($r['position_id'] === null) continue; // 沒有設定職稱的職務列，無法勾選排除，略過
        $out[] = [
            'position_id' => (int)$r['position_id'],
            'position_name' => (string)($r['position_name'] ?: ('#' . $r['position_id'])),
        ];
    }
    return $out;
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

if (!defined('UL_PSCHED_MAX_DAYS')) define('UL_PSCHED_MAX_DAYS', 31);

/**
 * 批次版「某批人在這段期間，每一天各自有沒有會議／外出／請假」（2026-10-08 新增，
 * 使用者交辦：設計課／品管的逐人每日工作量要能看到當天狀態）。**一律轉呼叫全站唯一
 * 實作** `person_schedule_lib.php` 的 `eg_psched_for_users()`（會議紀錄挑出席人員已經
 * 在用的同一支），不自己另外判斷請假/公出/會議——這裡只是把它包成「逐日呼叫一次、
 * 整段期間一次要齊」的批次版本。
 *
 * 只在期間不超過 UL_PSCHED_MAX_DAYS 天時才展開（跟畫面「逐日」顯示粒度用的門檻一致，
 * 見前端 pickTimeGran() 的 31 天），超過就回空陣列——拉長到一整年份還要逐日查 4 張表，
 * 對這個「附加提示」功能而言不划算，而且那種長期間本來就會改用週/月分桶，不是逐日
 * 一列，看不到「當天」這件事也沒有意義。
 *
 * @return array 'YYYY-MM-DD' => uid => eg_psched_for_users() 的那份清單（可能是空陣列）
 */
function ul_person_day_status(PDO $db, array $userIds, string $from, string $to): array
{
    $ids = ul_ids_norm($userIds);
    if (!$ids) return [];
    $dayCount = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($dayCount < 1 || $dayCount > UL_PSCHED_MAX_DAYS) return [];

    $out = [];
    $cur = strtotime($from);
    $endTs = strtotime($to);
    while ($cur <= $endTs) {
        $d = date('Y-m-d', $cur);
        $out[$d] = eg_psched_for_users($db, $ids, $d);
        $cur = strtotime('+1 day', $cur);
    }
    return $out;
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
 * 2026-10-07：SELECT 新增 `maker_id_no`——供 `ul_pm_summary()` 統一改用本函式判定
 * 「委外加工中／廠內加工中」時，不必再另外查一次 bom_ing 取得廠商編號（純加一欄，
 * 既有呼叫端 `ul_prod_by_process_type()` 不受影響，多出來的欄位沒人讀）。
 *
 * @return array 每列 ['bom_ing_fid','bom','process_no','machine_id','processing_state','maker_id_no']
 */
function ul_prod_current_step_rows(PDO $db): array
{
    // 2026-10-07 效能修正：這是「現況快照」查詢（不吃 $from/$to），但 ul_pm_summary() 同一次
    // request 內會被呼叫兩次（本期＋比較期，兩次結果本來就該一樣），overview 又把 ul_pm_summary()
    // 呼叫兩次——等於同一個 CTE 查詢在一次頁面載入裡重複跑 4 次。實測這條查詢單次約 0.3 秒，
    // 用 request 內靜態快取（同一支 PHP 行程/請求內共用，不跨請求）省掉重複開銷，絕不改變回傳
    // 內容（純記憶化，無副作用）。
    static $cache = null;
    if ($cache !== null) return $cache;
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
        SELECT bi.bom_ing_fid, bi.bom, bi.process_no, bi.machine_id, bi.processing_state, bi.maker_id_no
        FROM bom_ing bi
        JOIN cur_sn c ON c.bom = bi.bom AND c.cur_sn = bi.bom_sn
        WHERE bi.is_consumed = 0
          AND " . ul_bom_active_cond('bi') . "
    ";
    $cache = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return $cache;
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
 * 這批料號文字「目前完全沒有任何圖面」的判定（＝訂單追蹤清單上的 NEW 徽章）。
 * 2026-10-07 使用者釐清「新案件」＝NEW 圖示者，完全取代舊版「系統裡首次出現的料號」
 * 那套判法（oa_first_seen，已移除呼叫）——那是另一個概念，不是使用者這次要的。
 *
 * **刻意用料號文字（order_track.d_id／bom.d_id，兩邊都是 varchar(30)）而不是料號主檔 id
 * （order_track.d_id_ID）**：查證 NewOrder_Track.php 第 3325 行 NEW 徽章實際讀的是
 * `$has_drawing_map[$order['d_id']]`（文字鍵），`$all_d_ids`（第 2815 行）也是
 * `array_column($order_list,'d_id')` 取文字欄位；`bom.d_id` 這欄本身就是存料號文字、
 * 不是外鍵整數，拿料號主檔 id 去比對會比不到任何東西。這跟「同一個料號文字可能分屬
 * 不同客戶、各有一筆主檔」是已知的既有限制（見本機記憶 bom_client_name_cache），
 * 但 NEW 徽章本身就是這樣判的——本函式的任務是跟它**完全一致**，不是另外發明一套更
 * 嚴謹但對不起來的規則，否則清單上的 NEW 徽章跟這裡算出來的「新案件」數會兩套各算各的。
 *
 * 判定邏輯其餘部分逐字重用 NewOrder_Track.php 第 2919~2982 行那套：
 * ① 查 bom 表取得每個料號文字底下的全部 BOM 編號 ② 用 bom_dir_lib.php 既有的 NAS 掃描
 * 快取（絕不在這裡同步掃描，那個資料夾近 2 萬個檔，掃一次要一分半）比對副檔名
 * jpg/jpeg/png/pdf 的檔案是否存在 ③ 快取還沒建好、或 NAS 資料夾無法存取時，退回
 * 「bom 表有記錄就視為有圖面」（與 NewOrder_Track.php 同一條既有退路）。
 *
 * @param array $partNos 料號文字（order_track.d_id），可含重複值或空字串（會被濾掉）
 * @return array [d_id文字 => bool] true＝這個料號目前查不到任何圖面＝新案件；
 *               查無 bom 記錄的料號一律回 true（沒有圖面可言）。
 */
/**
 * 「新案件」判定（2026-10-07 第三版，使用者拍板更正）：改用 BOM 編號解析出來的日期
 * 跟每一張訂單自己的接單日比較，取代上一版「NAS 現在有沒有圖面」的二元判斷。
 *
 * 【起因】NAS 現在有沒有圖面是「現在」的快照，會被「後來才補掃進 NAS 的圖面」污染——
 * 某個料號當初下單時根本沒有圖面（現場憑經驗生產），後來有人把圖面補掃進 NAS，於是
 * 「現在查有沒有圖面」查到「有」，連帶把這個料號歷史上所有舊訂單都誤判成「不是新案件」，
 * 即使那些訂單下單當下圖面根本不存在。
 *
 * 【改法】對「這個料號文字至少有一筆 bom 紀錄」的訂單，取這個料號文字底下**最早**一筆
 * bom 編號解析出來的日期，代表「這個料號的圖面大概是什麼時候備齊」，拿去跟**這張訂單
 * 自己的接單日**（Order_date）比較：
 *   訂單接單日 <= 代表日期 → 新案件（下單當下圖面可能還沒備齊，這張訂單很可能正是促成
 *                             那筆最早製令本身的那一張）
 *   訂單接單日 >  代表日期 → 不是新案件（下單時這個料號已經有製令紀錄在案，圖面應該
 *                             已經備齊，是重複下單）
 *
 * 【為什麼代表日期要用「料號最早一筆 bom」，不是「這張訂單自己對應到的那一筆 bom」】
 * 已用真實資料驗證過兩種算法（見 unit_load_lib 開發時的 CLI 驗證，當時取樣 bom_order_
 * process_map／bom.o_order_id 兩種訂單↔製令直接綁定，各抽 500 組比對）：訂單自己綁定
 * 的那一筆 bom，幾乎必然是「先有訂單、後開製令」（99%+ 的綁定裡 bom 日期都晚於或等於
 * 訂單日期——製令本來就是為了生產這張訂單才開的），拿「這張訂單自己的製令」去比「這張
 * 訂單自己」，答案幾乎永遠是「訂單早於製令」＝永遠判成新案件，完全沒有判別力。改用
 * 「這個料號文字底下最早一筆 bom」才真正代表「系統裡第一次有這個料號生產紀錄」的時間
 * 點——早於或等於這個時間點下單的那一張（通常正是促成那筆最早製令本身的訂單）才是真正
 * 的新案件，之後重複下單的料號已經有生產履歷在先，圖面應該已經備齊。拿同一料號有多筆
 * 跨年度 bom 紀錄的真實案例覆核：每個料號只有最早（或等於最早製令日期）那幾張訂單被
 * 判定新案件，之後的重複下單全部判定「不是新案件」，與人工檢視結果一致。
 *
 * 【沒有任何 bom 紀錄的料號，判定方式完全不變】查無 bom 紀錄＝沒有任何圖面可言，一律
 * 維持預設 true（新案件）——這批訂單從來沒有機會比對日期，過去的版本也是這樣處理。
 *
 * 【為什麼改成以「訂單」為單位，不再是「料號」為單位】同一個料號文字可能被橫跨好幾年
 * 的好幾張訂單引用，新舊判定現在要逐張訂單各自跟「這個料號最早一筆 bom 的日期」比較，
 * 同一個料號不同時期的訂單判定結果不再相同，故回傳鍵改成 Order_id（不是 d_id 料號文字）。
 *
 * @param array $orders [Order_id => ['d_id'=>料號文字, 'order_date'=>'Y-m-d'（接單日）]]
 * @return array [Order_id => bool]  true=新案件
 */
function ul_orders_new_case_map(PDO $db, array $orders): array
{
    $norm = [];
    foreach ($orders as $oid => $info) {
        $oid = (int)$oid;
        if ($oid <= 0 || !is_array($info)) continue;
        $did = isset($info['d_id']) ? trim((string)$info['d_id']) : '';
        $odate = isset($info['order_date']) ? trim((string)$info['order_date']) : '';
        if ($did === '') continue;
        $norm[$oid] = ['d_id' => $did, 'order_date' => $odate];
    }
    if (!$norm) return [];

    $out = [];
    foreach (array_keys($norm) as $oid) $out[$oid] = true; // 預設：查無 bom 記錄＝新案件（沒有任何圖面可言）

    $nos = array_values(array_unique(array_column($norm, 'd_id')));
    $ph = implode(',', array_fill(0, count($nos), '?'));
    $st = $db->prepare("SELECT d_id, bom, Created_At FROM bom WHERE d_id IN ({$ph})");
    $st->execute($nos);
    $bomByDid = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bomByDid[(string)$r['d_id']][] = ['bom' => (string)$r['bom'], 'created_at' => $r['Created_At']];
    }
    if (!$bomByDid) return $out; // 全部都查無 bom 記錄，維持預設 true（新案件）

    require_once __DIR__ . '/data_audit_lib.php'; // dqa_bom_open_date()：BOM 編號解析日期的唯一實作，不另寫一套
    // 每個料號文字 → 最早一筆 bom 解析出的日期（代表「這個料號大概什麼時候有生產/圖面紀錄」）
    $earliestByDid = [];
    foreach ($bomByDid as $did => $rows) {
        $min = null;
        foreach ($rows as $r) {
            $d = dqa_bom_open_date($r['bom'], $r['created_at']);
            if ($d !== '' && ($min === null || $d < $min)) $min = $d;
        }
        if ($min !== null) $earliestByDid[$did] = $min;
    }

    foreach ($norm as $oid => $r) {
        $did = $r['d_id'];
        if (!isset($earliestByDid[$did])) continue; // 這個料號的 bom 一筆都解不出日期，維持預設 true
        if ($r['order_date'] === '') continue;       // 訂單沒有接單日可比，維持預設，不可亂猜
        $out[$oid] = ($r['order_date'] <= $earliestByDid[$did]);
    }

    return $out;
}

/**
 * 設計課本期彙總卡片。
 * @return array drawing_wip（批圖中，現況快照：2026-10-07 使用者釐清定義＝「有分配給
 *               此部門人員的訂單，且還沒按審圖、也還沒轉生管」，與是否有開放中設計備註
 *               問題無關——那是獨立指標 issue_orders）
 *               in_review（本期按下審圖）／pm_get（本期轉生管）
 *               new_case（現況快照：這批設計師名下目前有效訂單裡，料號目前完全沒有任何
 *               圖面的張數＝NEW 圖示者，見 ul_orders_new_case_map()；拆成「已處理」=已轉
 *               生管／「批圖中」=比照 drawing_wip 同一個定義兩種子狀態＋各自佔比）
 *               issue_orders（現況：有開放中設計備註問題的訂單數）
 *               avg_draw_workdays（2026-10-07 修正：只取 order_track.need_design_draw=1
 *               的訂單，見下方函式內註解）、draw_case_count（算進這個平均值的筆數）
 *               avg_sample_draw_workdays／sample_draw_case_count（2026-10-07 新增：
 *               need_sample_draw=1，由樣品繪圖，比一般繪圖更花時間，獨立另算一個平均值，
 *               不跟一般繪圖案件混在同一個平均裡——見下方函式內註解）
 *               period_total_days／period_elapsed_days（2026-10-07 新增：$from~$to 這個
 *               期間總共幾天／若本期尚未結束則是到今天為止已經過了幾天，本期已經結束則
 *               為 null——供 ul_insights() 判斷「本期 vs 比較期」要不要换算成日均比較，
 *               不受兩個期間長度不同影響，見 ul_period_daily_ctx_from_summary()）
 */
function ul_design_summary(PDO $db, string $from, string $to, array $designerIds): array
{
    $out = ['drawing_wip' => 0, 'in_review' => 0, 'pm_get' => 0,
            'new_case' => ['total' => 0, 'processed' => 0, 'processed_pct' => null,
                            'in_progress' => 0, 'in_progress_pct' => null],
            'issue_orders' => 0, 'issue_orders_recent' => 0,
            'avg_draw_workdays' => null, 'draw_case_count' => 0,
            'avg_sample_draw_workdays' => null, 'sample_draw_case_count' => 0,
            'period_total_days' => null, 'period_elapsed_days' => null];

    // period_total_days／period_elapsed_days：不依賴 $designerIds，先算好再判斷是否提早
    // 回傳——即使這個單位目前沒有設定任何人員，呼叫端（ul_insights()）也還是看得到這個
    // 期間本身的長度資訊（雖然沒有意義，但至少欄位存在、型別一致，不必額外判斷有沒有
    // 這個鍵）。
    $out['period_total_days'] = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
    $out['period_elapsed_days'] = oa_elapsed_days(['start' => $from, 'end' => $to]);

    $ids = ul_ids_norm($designerIds);
    if (!$ids) return $out;
    $in = implode(',', $ids);

    // 批圖中：這是「目前狀態」的即時快照，刻意不受 $from/$to 限制。2026-10-07 使用者回報
    // 這裡的數字跟 NewOrder_Track.php 自己的「批圖中」KPI 卡（59筆）對不起來，查證後發現
    // 兩個錯誤：①這裡誤加了 in_review IS NULL 當條件——但官方頁面（NewOrder_Track.php 第
    // 2547 行 $mainStatsSql 的 processing 欄）真正的定義只是「還沒轉生管、且不是暫停/取消」，
    // 完全不管審圖狀態，已按審圖但還沒轉生管的單依然算「批圖中」；②`Order_status<>6` 在
    // MySQL 裡 Order_status 為 NULL（=進行中，最常見的狀態）時整個條件判為 NULL（視同
    // false），等於把幾乎所有正常進行中的訂單都排除掉——這才是 0 筆的真正原因。官方頁面
    // 用的是 `(Order_status IS NULL OR Order_status<>6)`，以下全部比照這個寫法。
    // ③同一次查證另外發現：官方頁面的基準 WHERE 還固定排除拆批子單（parent_order_id 非空
    // 的列是從某張母單拆出來的子單，`NewOrder_Track.php` 第 2427 行每一次統計都排除，不是
    // 可選篩選），不排除會把拆批的子單重複算進「批圖中」——實測全公司批圖中數字因此從
    // 130 筆錯算成誤差值，加回這條才對回官方頁面的 59 筆。
    $ordStatOk = "(ot.Order_status IS NULL OR ot.Order_status<>6)";
    $ordNotSplit = "(ot.parent_order_id IS NULL OR ot.parent_order_id=0)";
    $out['drawing_wip'] = (int)$db->query(
        "SELECT COUNT(*) FROM order_track ot
         WHERE ot.ate IN ({$in}) AND ot.pmGet IS NULL AND {$ordStatOk} AND {$ordNotSplit}"
    )->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.in_review IS NOT NULL AND DATE(ot.in_review) BETWEEN ? AND ? AND {$ordStatOk} AND {$ordNotSplit}");
    $st->execute([$from, $to]);
    $out['in_review'] = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.pmGet IS NOT NULL AND DATE(ot.pmGet) BETWEEN ? AND ? AND {$ordStatOk} AND {$ordNotSplit}");
    $st->execute([$from, $to]);
    $out['pm_get'] = (int)$st->fetchColumn();

    // 問題訂單數／新案件：兩者都是「目前狀態」快照，共用同一次查詢（鐵律8的精神：
    // 不必要的重複查詢也是要避免的浪費）。新案件的判定一律用 d_id（料號文字，與
    // NewOrder_Track.php 的 NEW 徽章同一個鍵），不是 d_id_ID（料號主檔 id）——
    // 見 ul_orders_new_case_map() 的函式註解。
    $orderRows = $db->query(
        "SELECT Order_id, d_id, Order_date, pmGet, in_review, Created_At FROM order_track ot
         WHERE ot.ate IN ({$in}) AND {$ordStatOk} AND {$ordNotSplit}"
    )->fetchAll(PDO::FETCH_ASSOC);
    $orderIds = array_map(fn($r) => (int)$r['Order_id'], $orderRows);
    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        $out['issue_orders'] = count(array_filter($openMap, fn($c) => $c > 0));
        // issue_orders_recent：同一批「目前仍有未回覆問題」的訂單裡，只算「下單日期在最近
        // UL_RECENT_ORDER_DAYS 天內」的——見本檔頂部常數註解，這才是真正代表「本期工作量」
        // 的數字，用來驅動負荷過重判定；issue_orders（總量）純粹留著當參考，不再直接比門檻。
        $recentCutoff = date('Y-m-d', strtotime('-' . UL_RECENT_ORDER_DAYS . ' days'));
        $createdByOid = [];
        foreach ($orderRows as $r) $createdByOid[(int)$r['Order_id']] = substr((string)$r['Created_At'], 0, 10);
        $recentCnt = 0;
        foreach ($openMap as $oid => $cnt) {
            if ($cnt > 0 && ($createdByOid[$oid] ?? '') >= $recentCutoff) $recentCnt++;
        }
        $out['issue_orders_recent'] = $recentCnt;
    }

    // 新案件：2026-10-07 使用者重新定義＝NEW 圖示者，完全取代舊版「系統裡首次出現的料號」；
    // 同一天稍後再更正為「逐張訂單拿料號最早一筆 bom 日期跟訂單自己的接單日比較」（見
    // ul_orders_new_case_map() 函式註解，不再是單純看 NAS 現在有沒有圖面）。同樣是現況快照，
    // 不受 $from/$to 限制。拆成「已處理」（已轉生管＝pmGet 有值）與「批圖中」（比照上面修正
    // 後的 drawing_wip 同一個定義：還沒轉生管，不管審圖狀態）兩種子狀態＋各自佔比——pmGet
    // 非空即非空，兩者互斥又完整，processed+in_progress 恆等於 total。
    $ncInput = [];
    foreach ($orderRows as $r) {
        $pn = (string)$r['d_id'];
        if ($pn === '') continue;
        $ncInput[(int)$r['Order_id']] = ['d_id' => $pn, 'order_date' => (string)$r['Order_date']];
    }
    $newCaseMap = ul_orders_new_case_map($db, $ncInput);
    $ncTotal = 0; $ncProcessed = 0; $ncInProgress = 0;
    foreach ($orderRows as $r) {
        $oid = (int)$r['Order_id'];
        $pn  = (string)$r['d_id'];
        if ($pn === '' || empty($newCaseMap[$oid])) continue; // 查不到料號文字／未判定新案件的訂單不計入
        $ncTotal++;
        if ($r['pmGet'] !== null) $ncProcessed++;
        else $ncInProgress++;
    }
    $out['new_case'] = [
        'total' => $ncTotal,
        'processed' => $ncProcessed,
        'processed_pct' => $ncTotal > 0 ? round($ncProcessed / $ncTotal, 4) : null,
        'in_progress' => $ncInProgress,
        'in_progress_pct' => $ncTotal > 0 ? round($ncInProgress / $ncTotal, 4) : null,
    ];

    // 2026-10-07 修正：使用者回報「繪圖平均工作天」這個指標意義不明、「極少有繪圖案件」。
    // 根因是舊版只要有指派 ate（設計者）就算進平均值，但指派設計者不代表這張訂單真的
    // 需要畫圖——`NewOrder_Track.php` 已新增兩個旗標欄位分辨「這張訂單是不是真的需要
    // 畫圖」：`need_design_draw`（一般繪圖）／`need_sample_draw`（由樣品繪圖，比一般
    // 繪圖更花時間），才是判斷「這是不是繪圖案件」的正確依據。兩者各自獨立統計一個
    // 平均工作天，不混在一起看——樣品繪圖本來就比較花時間，混進同一個平均會把一般繪圖
    // 的數字拉高、也會把樣品繪圖的數字拉低，兩邊都失真。
    $st = $db->prepare("SELECT ateGet, pmGet FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.ateGet IS NOT NULL AND ot.pmGet IS NOT NULL
          AND ot.need_design_draw = 1
          AND DATE(ot.pmGet) BETWEEN ? AND ? AND {$ordStatOk} AND {$ordNotSplit}");
    $st->execute([$from, $to]);
    $sum = 0.0; $cnt = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = ul_workdays_between($db, substr((string)$r['ateGet'], 0, 10), substr((string)$r['pmGet'], 0, 10));
        if ($d === null) continue;
        $sum += $d; $cnt++;
    }
    $out['avg_draw_workdays'] = $cnt > 0 ? round($sum / $cnt, 2) : null;
    $out['draw_case_count'] = $cnt;

    $st2 = $db->prepare("SELECT ateGet, pmGet FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.ateGet IS NOT NULL AND ot.pmGet IS NOT NULL
          AND ot.need_sample_draw = 1
          AND DATE(ot.pmGet) BETWEEN ? AND ? AND {$ordStatOk} AND {$ordNotSplit}");
    $st2->execute([$from, $to]);
    $sumS = 0.0; $cntS = 0;
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = ul_workdays_between($db, substr((string)$r['ateGet'], 0, 10), substr((string)$r['pmGet'], 0, 10));
        if ($d === null) continue;
        $sumS += $d; $cntS++;
    }
    $out['avg_sample_draw_workdays'] = $cntS > 0 ? round($sumS / $cntS, 2) : null;
    $out['sample_draw_case_count'] = $cntS;

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
            'issue_orders' => 0, 'issue_orders_recent' => 0,
            'avg_draw_workdays' => null, 'draw_case_count' => 0,
            'avg_sample_draw_workdays' => null, 'sample_draw_case_count' => 0,
        ];
    }

    // 批圖中定義見 ul_design_summary() 同一段註解（還沒轉生管即算批圖中，不管審圖狀態；
    // Order_status 用 IS NULL OR <>6，NULL=最常見的進行中狀態，不可只寫 <>6；還要排除
    // parent_order_id 非空的拆批子單，否則同一張母單拆出的子單會被重複算）
    $ordStatOk = "(ot.Order_status IS NULL OR ot.Order_status<>6)";
    $ordNotSplit = "(ot.parent_order_id IS NULL OR ot.parent_order_id=0)";
    foreach ($db->query(
        "SELECT ot.ate k, COUNT(*) c FROM order_track ot
         WHERE ot.ate IN ({$in}) AND ot.pmGet IS NULL AND {$ordStatOk} AND {$ordNotSplit}
         GROUP BY ot.ate"
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['drawing_wip'] = (int)$r['c'];
    }

    $st = $db->prepare("SELECT ot.ate k, COUNT(*) c FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.in_review IS NOT NULL AND DATE(ot.in_review) BETWEEN ? AND ? AND {$ordStatOk} AND {$ordNotSplit}
        GROUP BY ot.ate");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['in_review'] = (int)$r['c'];
    }

    $st = $db->prepare("SELECT ot.ate k, COUNT(*) c FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.pmGet IS NOT NULL AND DATE(ot.pmGet) BETWEEN ? AND ? AND {$ordStatOk} AND {$ordNotSplit}
        GROUP BY ot.ate");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['pm_get'] = (int)$r['c'];
    }

    $orderRows = $db->query("SELECT Order_id, ate, Created_At FROM order_track ot WHERE ot.ate IN ({$in}) AND {$ordStatOk} AND {$ordNotSplit}")
                    ->fetchAll(PDO::FETCH_ASSOC);
    $orderIds = []; $ateByOrder = []; $createdByOrder = [];
    foreach ($orderRows as $r) {
        $oid = (int)$r['Order_id']; $orderIds[] = $oid; $ateByOrder[$oid] = (int)$r['ate'];
        $createdByOrder[$oid] = substr((string)$r['Created_At'], 0, 10);
    }
    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        $recentCutoff = date('Y-m-d', strtotime('-' . UL_RECENT_ORDER_DAYS . ' days'));
        foreach ($openMap as $oid => $cnt) {
            if ($cnt <= 0) continue;
            $uid = $ateByOrder[$oid] ?? 0;
            if (!isset($out[$uid])) continue;
            $out[$uid]['issue_orders']++;
            if (($createdByOrder[$oid] ?? '') >= $recentCutoff) $out[$uid]['issue_orders_recent']++;
        }
    }

    // 2026-10-07 修正：同 ul_design_summary() 的道理，只有 need_design_draw=1 的訂單才算
    // 進「繪圖平均工作天」，need_sample_draw=1 另外獨立算一次（見下方），不要混在一起。
    $st = $db->prepare("SELECT ot.ate k, ot.ateGet, ot.pmGet FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.ateGet IS NOT NULL AND ot.pmGet IS NOT NULL
          AND ot.need_design_draw = 1
          AND DATE(ot.pmGet) BETWEEN ? AND ? AND {$ordStatOk} AND {$ordNotSplit}");
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
        if (isset($out[$uid]) && $c > 0) {
            $out[$uid]['avg_draw_workdays'] = round($sums[$uid] / $c, 2);
            $out[$uid]['draw_case_count'] = $c;
        }
    }

    $st2 = $db->prepare("SELECT ot.ate k, ot.ateGet, ot.pmGet FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.ateGet IS NOT NULL AND ot.pmGet IS NOT NULL
          AND ot.need_sample_draw = 1
          AND DATE(ot.pmGet) BETWEEN ? AND ? AND {$ordStatOk} AND {$ordNotSplit}");
    $st2->execute([$from, $to]);
    $sumsS = []; $cntsS = [];
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k'];
        $d = ul_workdays_between($db, substr((string)$r['ateGet'], 0, 10), substr((string)$r['pmGet'], 0, 10));
        if ($d === null) continue;
        $sumsS[$uid] = ($sumsS[$uid] ?? 0) + $d;
        $cntsS[$uid] = ($cntsS[$uid] ?? 0) + 1;
    }
    foreach ($cntsS as $uid => $c) {
        if (isset($out[$uid]) && $c > 0) {
            $out[$uid]['avg_sample_draw_workdays'] = round($sumsS[$uid] / $c, 2);
            $out[$uid]['sample_draw_case_count'] = $c;
        }
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
          AND (Order_status IS NULL OR Order_status<>6) AND (parent_order_id IS NULL OR parent_order_id=0)
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
          AND (Order_status IS NULL OR Order_status<>6) AND (parent_order_id IS NULL OR parent_order_id=0)
        GROUP BY d, ate ORDER BY d");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 稽核製程標籤的「正式顯示順序」（2026-10-08 新增，使用者交辦：逐人標籤分布的順序要跟
 * 訂單追蹤「設定→稽核製程標籤（AS 認定）」區塊看到的順序一致）。**不自己另訂一套排序
 * 規則**——直接重用 `order_as_tag_lib.php` 既有的 `ot_astag_defs()`（依 sort_order 排序，
 * 含停用的也要給順序，否則舊資料裡停用標籤的那幾列會被排到不可預期的位置）＋
 * `ot_astag_variants()`（同一個定義展開成幾個 scope 變體時，變體之間的先後順序），
 * 兩者都是該檔唯一實作，這裡只是照抄官方順序組一份 'tagid:scope' => 排序索引的對照表。
 * @return array 'tagid:scope' => 排序索引（數字愈小排愈前面）
 */
function ul_design_tag_order_map(PDO $db): array
{
    require_once __DIR__ . '/order_as_tag_lib.php';
    $map = []; $i = 0;
    foreach (ot_astag_defs($db, false) as $def) {
        foreach (ot_astag_variants($def) as $sc) {
            $map[$def['tag_id'] . ':' . $sc] = $i++;
        }
    }
    return $map;
}

/** ul_design_tag_order_map() 排序用：查不到的（理論上不會發生，defs 已含全部定義）排最後 */
function ul_design_tags_sort(array $rows, array $orderMap): array
{
    usort($rows, function ($a, $b) use ($orderMap) {
        $ka = $a['tag_id'] . ':' . ($a['_scope'] ?? '');
        $kb = $b['tag_id'] . ':' . ($b['_scope'] ?? '');
        $oa = $orderMap[$ka] ?? PHP_INT_MAX;
        $ob = $orderMap[$kb] ?? PHP_INT_MAX;
        return $oa <=> $ob;
    });
    foreach ($rows as &$r) unset($r['_scope']); // 排序用的內部欄位，回傳前拿掉
    return $rows;
}

/**
 * 訂單標籤分布（AS 認定的稽核製程標籤，order_track.as_tag_id → ot_as_proc_tag 唯一定義表）。
 * 依下單日歸屬期間——標籤是訂單的屬性，用下單日判斷「這張單算不算這一期」最直觀；
 * 若要改成依「貼標籤當下」(as_tag_at) 篩選，留給下一階段依使用者意見再調。
 *
 * 2026-10-08 修正：順序依「訂單追蹤設定→稽核製程標籤（AS 認定）」官方順序（sort_order），
 * 不再依筆數由大到小排——使用者明確要求順序要跟那個設定畫面一致，方便對照。
 *
 * 2026-10-08 修正：使用者回報本頁顯示的標籤名稱跟訂單追蹤設定的標籤「好像不同」——
 * 查證發現根因是**顯示文字沒有呼叫唯一實作 `ot_astag_make_label()`**，直接拿
 * `ot_as_proc_tag.proc_name` 原始值當名稱：kind='process' 的稽核製程標籤（如「齒研」）
 * 依 `order_track.as_tag_scope` 不同，正式顯示文字其實是「單製齒研」或「全製含齒研」
 * （kind='fixed'/'other' 如「全製」「廠內治具」才是裸名稱本身）；GROUP BY 也只用
 * `tag_id` 沒有連 `as_tag_scope` 一起分組，同一個 tag_id 若曾被用在不同 scope 會被
 * 誤併成一列。全站顯示「這個標籤叫什麼」只能呼叫 `ot_astag_label_map()`（唯一實作，
 * `order_as_tag_lib.php`），不可以再各自拼 proc_name。
 */
function ul_design_tags(PDO $db, string $from, string $to, array $designerIds): array
{
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return [];
    $in = implode(',', $ids);
    require_once __DIR__ . '/order_as_tag_lib.php';
    $labelMap = ot_astag_label_map($db);
    $orderMap = ul_design_tag_order_map($db);
    $st = $db->prepare("SELECT ot.as_tag_id tid, ot.as_tag_scope sc, COUNT(*) c
        FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.as_tag_id IS NOT NULL
          AND ot.Order_date BETWEEN ? AND ? AND (ot.Order_status IS NULL OR ot.Order_status<>6)
          AND (ot.parent_order_id IS NULL OR ot.parent_order_id=0)
        GROUP BY ot.as_tag_id, ot.as_tag_scope");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tid = (int)$r['tid']; $sc = (string)($r['sc'] ?? '');
        $label = $labelMap[$tid . ':' . $sc] ?? ('（標籤#' . $tid . '）');
        $out[] = ['tag_id' => $tid, 'tag_name' => $label, 'c' => (int)$r['c'], '_scope' => $sc];
    }
    return ul_design_tags_sort($out, $orderMap);
}

/** ul_design_tags() 的逐人版本（2026-10-08 新增），GROUP BY 多一欄 ate；標籤文字修正見
 * ul_design_tags() 函式註解，同樣改用 ot_astag_label_map()、同樣連 as_tag_scope 一起分組。
 * @return array uid => [{tag_id,tag_name,c}, ...]（依該人該標籤筆數由大到小） */
function ul_design_tags_by_person(PDO $db, string $from, string $to, array $designerIds): array
{
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return [];
    $in = implode(',', $ids);
    require_once __DIR__ . '/order_as_tag_lib.php';
    $labelMap = ot_astag_label_map($db);
    $orderMap = ul_design_tag_order_map($db);
    $st = $db->prepare("SELECT ot.ate k, ot.as_tag_id tid, ot.as_tag_scope sc, COUNT(*) c
        FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.as_tag_id IS NOT NULL
          AND ot.Order_date BETWEEN ? AND ? AND (ot.Order_status IS NULL OR ot.Order_status<>6)
          AND (ot.parent_order_id IS NULL OR ot.parent_order_id=0)
        GROUP BY ot.ate, ot.as_tag_id, ot.as_tag_scope");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k'];
        $tid = (int)$r['tid']; $sc = (string)($r['sc'] ?? '');
        $label = $labelMap[$tid . ':' . $sc] ?? ('（標籤#' . $tid . '）');
        if (!isset($out[$uid])) $out[$uid] = [];
        $out[$uid][] = ['tag_id' => $tid, 'tag_name' => $label, 'c' => (int)$r['c'], '_scope' => $sc];
    }
    foreach ($out as $uid => $rows) $out[$uid] = ul_design_tags_sort($rows, $orderMap);
    return $out;
}

/**
 * 設計課「逐人每日工作量」明細（2026-10-08 新增，使用者交辦：要能看到每個人每天處理的
 * 批圖中／審圖／已轉生管量，並知道其中有多少是新料號）。同一批訂單依三個時間點各自
 * 統計：ateGet＝新進批圖中（業務轉設計日）、in_review＝審圖、pmGet＝已轉生管，三者是
 * 同一條流程的三個不同站點，各自可能落在不同天，所以分開各查一次。
 * 回傳最細的逐筆事件列表（不預先彙總），日/週/月的收合與週/月平均都交給前端
 * bucketKeyFor()／pickTimeGran()（既有的每日完成數趨勢圖已經在用同一套邏輯，這裡沿用
 * 不重寫第二套時間分桶規則）。
 * 新料號判定與 ul_design_summary() 的 new_case 完全同一套（ul_orders_new_case_map()），
 * 同一張訂單在三個事件裡都可能出現，但新料號與否是訂單本身的屬性，判一次即可。
 * @return array 每列一筆事件：['d'=>日期,'ate'=>設計者id,'metric'=>batch_in|review|pm_get,
 *               'is_new'=>bool]
 */
function ul_design_daily_detail(PDO $db, string $from, string $to, array $designerIds): array
{
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return [];
    $in = implode(',', $ids);
    $ordStatOk = "(ot.Order_status IS NULL OR ot.Order_status<>6)";
    $ordNotSplit = "(ot.parent_order_id IS NULL OR ot.parent_order_id=0)";

    $metricCols = ['batch_in' => 'ateGet', 'review' => 'in_review', 'pm_get' => 'pmGet'];
    $raw = [];    // oid => ['d_id'=>, 'order_date'=>]（供 new_case 判定用）
    $events = []; // [d, ate, oid, metric]
    foreach ($metricCols as $metric => $col) {
        $st = $db->prepare("SELECT ot.Order_id oid, ot.ate k, DATE(ot.{$col}) d, ot.d_id, ot.Order_date
            FROM order_track ot
            WHERE ot.ate IN ({$in}) AND ot.{$col} IS NOT NULL AND DATE(ot.{$col}) BETWEEN ? AND ?
              AND {$ordStatOk} AND {$ordNotSplit}");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oid = (int)$r['oid'];
            $raw[$oid] = ['d_id' => (string)$r['d_id'], 'order_date' => (string)$r['Order_date']];
            $events[] = ['d' => (string)$r['d'], 'ate' => (int)$r['k'], 'oid' => $oid, 'metric' => $metric];
        }
    }
    if (!$events) return [];

    $newCaseMap = ul_orders_new_case_map($db, $raw);
    $out = [];
    foreach ($events as $e) {
        $out[] = [
            'd' => $e['d'], 'ate' => $e['ate'], 'metric' => $e['metric'],
            'is_new' => !empty($newCaseMap[$e['oid']]),
        ];
    }
    return $out;
}

if (!defined('UL_WIP_ASOF_MAX_DAYS')) define('UL_WIP_ASOF_MAX_DAYS', 366);

/**
 * 設計課「累積待批圖數量」（2026-10-08 新增，使用者交辦）：重建「統計日當日」還卡在
 * 批圖中、尚未轉生管的筆數——這是**存量**（某一天結束時還積著多少），跟
 * ul_design_daily_detail() 的 batch_in（**流量**：那一天新進了幾筆）是完全不同的兩件事，
 * 不能互相替代；ul_design_summary() 的 drawing_wip 也只有「現在」這一個時間點的存量，
 * 沒有歷史序列，所以在這裡另外重建。
 *
 * 做法：先抓「這段期間內任何一天都可能算在製中」的訂單——進入批圖的日期(ateGet)不晚於
 * 期末、且轉生管日期(pmGet)是空值或不早於期初，這樣就涵蓋了「期間開始前就已經在批圖中、
 * 拖到期間內才轉出」與「期間內才新進、可能拖到期間結束都還沒轉出」兩種情況；再逐日逐人
 * 數「ateGet<=當天 且 (pmGet 是空值 或 pmGet>當天)」有幾筆。
 *
 * 效能：天數×訂單數的雙迴圈，全在 PHP 記憶體裡跑（不逐日查一次 DB）；UL_WIP_ASOF_MAX_DAYS
 * 限制最長一年，超過就不逐日重建（避免「整年」篩選時被要求跑 365×N 筆的無謂運算），
 * 改回傳空陣列，前端這個欄位會顯示「—」。
 * @return array 每列一筆：['d'=>日期,'ate'=>設計者id,'wip'=>截至當天還在批圖中的筆數]
 */
function ul_design_wip_daily(PDO $db, string $from, string $to, array $designerIds): array
{
    $ids = ul_ids_norm($designerIds);
    if (!$ids) return [];
    $dayCount = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($dayCount < 1 || $dayCount > UL_WIP_ASOF_MAX_DAYS) return [];

    $in = implode(',', $ids);
    $ordStatOk = "(ot.Order_status IS NULL OR ot.Order_status<>6)";
    $ordNotSplit = "(ot.parent_order_id IS NULL OR ot.parent_order_id=0)";
    $st = $db->prepare("SELECT ot.ate k, DATE(ot.ateGet) ag, DATE(ot.pmGet) pg
        FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.ateGet IS NOT NULL AND DATE(ot.ateGet) <= ?
          AND (ot.pmGet IS NULL OR DATE(ot.pmGet) >= ?)
          AND {$ordStatOk} AND {$ordNotSplit}");
    $st->execute([$to, $from]);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$orders) return [];

    $out = [];
    $cur = strtotime($from);
    $endTs = strtotime($to);
    while ($cur <= $endTs) {
        $d = date('Y-m-d', $cur);
        $cnt = [];
        foreach ($orders as $o) {
            if ($o['ag'] <= $d && ($o['pg'] === null || $o['pg'] > $d)) {
                $uid = (int)$o['k'];
                $cnt[$uid] = ($cnt[$uid] ?? 0) + 1;
            }
        }
        foreach ($cnt as $uid => $c) $out[] = ['d' => $d, 'ate' => $uid, 'wip' => $c];
        $cur = strtotime('+1 day', $cur);
    }
    return $out;
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

    $orderRows = $db->query("SELECT Order_id, ate FROM order_track
        WHERE ate IN ({$in}) AND (Order_status IS NULL OR Order_status<>6) AND (parent_order_id IS NULL OR parent_order_id=0)"
    )->fetchAll(PDO::FETCH_ASSOC);
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
          AND ot.ate IN ({$in}) AND (ot.Order_status IS NULL OR ot.Order_status<>6)
          AND (ot.parent_order_id IS NULL OR ot.parent_order_id=0)");
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
    $out = ['quote_count' => 0, 'quote_item_count' => 0, 'order_count' => 0, 'open_issue_count' => 0,
            'open_issue_count_recent' => 0,
            'period_total_days' => null, 'period_elapsed_days' => null];
    // period_total_days／period_elapsed_days：2026-10-07 新增，道理與 ul_design_summary()
    // 同一段註解——不依賴 $salesIds，先算好才判斷要不要提早回傳，讓 ul_insights() 不管
    // 這個單位有沒有設定人員都能拿到期間長度資訊。
    $out['period_total_days'] = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
    $out['period_elapsed_days'] = oa_elapsed_days(['start' => $from, 'end' => $to]);

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
        WHERE (Order_status IS NULL OR Order_status<>6) AND (parent_order_id IS NULL OR parent_order_id=0)
          AND DATE(Created_At) BETWEEN ? AND ? AND Created_By IN ({$inQ})");
    $st->execute([$from, $to]);
    $out['order_count'] = (int)$st->fetchColumn();

    $ordRows = $db->query(
        "SELECT Order_id, Created_At FROM order_track WHERE (Order_status IS NULL OR Order_status<>6)
          AND (parent_order_id IS NULL OR parent_order_id=0) AND Created_By IN ({$inQ})"
    )->fetchAll(PDO::FETCH_ASSOC);
    $orderIds = []; $createdAtOf = [];
    foreach ($ordRows as $r) {
        $oid = (int)$r['Order_id']; $orderIds[] = $oid;
        $createdAtOf[$oid] = substr((string)$r['Created_At'], 0, 10);
    }
    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        $out['open_issue_count'] = array_sum($openMap);
        // open_issue_count_recent：同 ul_design_summary() 的 issue_orders_recent，只算
        // 下單日期在最近 UL_RECENT_ORDER_DAYS 天內的那一部分，驅動負荷過重判定；
        // open_issue_count（總量）留著參考，不再直接比門檻。
        $recentCutoff = date('Y-m-d', strtotime('-' . UL_RECENT_ORDER_DAYS . ' days'));
        $recentSum = 0;
        foreach ($openMap as $oid => $cnt) {
            if ($cnt > 0 && ($createdAtOf[$oid] ?? '') >= $recentCutoff) $recentSum += $cnt;
        }
        $out['open_issue_count_recent'] = $recentSum;
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
            'open_issue_count_recent' => 0,
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
        WHERE (Order_status IS NULL OR Order_status<>6) AND (parent_order_id IS NULL OR parent_order_id=0)
          AND DATE(Created_At) BETWEEN ? AND ? AND Created_By IN ({$inQ})
        GROUP BY Created_By");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['k']; if (isset($out[$uid])) $out[$uid]['order_count'] = (int)$r['c'];
    }

    $orderRows = $db->query(
        "SELECT Order_id, Created_By, Created_At FROM order_track WHERE (Order_status IS NULL OR Order_status<>6)
          AND (parent_order_id IS NULL OR parent_order_id=0) AND Created_By IN ({$inQ})"
    )->fetchAll(PDO::FETCH_ASSOC);
    $orderIds = []; $createdByOf = []; $createdAtOf = [];
    foreach ($orderRows as $r) {
        $oid = (int)$r['Order_id']; $orderIds[] = $oid; $createdByOf[$oid] = (int)$r['Created_By'];
        $createdAtOf[$oid] = substr((string)$r['Created_At'], 0, 10);
    }
    if ($orderIds) {
        $openMap = el_order_open_item_counts($db, $orderIds, 'order_note');
        $recentCutoff = date('Y-m-d', strtotime('-' . UL_RECENT_ORDER_DAYS . ' days'));
        foreach ($openMap as $oid => $cnt) {
            if ($cnt <= 0) continue;
            $uid = $createdByOf[$oid] ?? 0;
            if (!isset($out[$uid])) continue;
            $out[$uid]['open_issue_count'] += $cnt;
            if (($createdAtOf[$oid] ?? '') >= $recentCutoff) $out[$uid]['open_issue_count_recent'] += $cnt;
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
 * 跟 ul_design_summary() 的「批圖中」、「問題訂單數」是同一種道理。**2026-10-07 起
 * 這五項一律統一取自 ul_prod_current_step_rows()／ul_qc_pending_queue()（見下方實作裡的
 * 註解）——這三處原本各自獨立判定「現在進行中有多少」，outsource_wip 等四項只簡單篩
 * processing_state、to_qc 只篩 'Q'，都沒有排除「整條製程路線裡還沒輪到的站」，數字因此
 * 系統性偏高且彼此對不上；統一之後本函式與生產課「製程大類現況」、品管「目前待驗佇列」
 * 永遠是同一份判定，不會再各算出不同答案。**
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

    // 2026-10-07 修正：outsource_wip／internal_wip／pending_transfer／transferred 改成
    // 直接呼叫 ul_prod_current_step_rows()（「現在正卡在等這一關」，每張現役 BOM 只取
    // 最早那個還沒走完的站），不再自己另外整表查一次 bom_ing 的 processing_state——
    // 使用者回報總覽「目前待QC驗 290 筆」跟品管分頁「目前待驗佇列」（已對齊官方畫面的
    // ul_qc_pending_queue()，103 筆）對不起來，查證後本函式從一開始就沒有套用
    // ul_prod_current_step_rows() 已經建好的「只算目前這一關，不要把整條路線還沒輪到的
    // 站也算進去」這套邏輯，而是自己另外查了一次全部 bom_ing（含還沒輪到的 N 站），
    // 數字因此系統性偏高。改用它之後，這裡與 ul_prod_by_process_type()／
    // ul_qc_pending_queue() 永遠是同一份資料來源，三處不會再各算出不同答案。
    $rows = ul_prod_current_step_rows($db);
    $makerIds = [];
    foreach ($rows as $r) if (!empty($r['maker_id_no'])) $makerIds[] = $r['maker_id_no'];
    $makerIds = array_values(array_unique($makerIds));
    $internalMap = [];
    if ($makerIds) {
        $inM = implode(',', array_map(fn($v) => $db->quote($v), $makerIds));
        foreach ($db->query("SELECT maker_id_no, internal FROM maker_list WHERE maker_id_no IN ({$inM})")
                    ->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $internalMap[$m['maker_id_no']] = ((int)($m['internal'] ?? 0)) === 1;
        }
    }
    foreach ($rows as $r) {
        $st = (string)$r['processing_state'];
        if ($st === 'ing') {
            $isInternal = !empty($r['maker_id_no']) && !empty($internalMap[$r['maker_id_no']]);
            if ($isInternal) $out['internal_wip']++; else $out['outsource_wip']++;
        } elseif ($st === 'P') $out['pending_transfer']++;
        elseif ($st === 'E') $out['transferred']++;
        // 'N'／NULL（還沒開工）不計入任何一項，與修正前的既有行為一致（修正前的
        // if/elseif 鏈同樣只認得 ing/Q/P/E 四種，其餘狀態一樣不計入任何桶）。
    }

    // to_qc：直接取 ul_qc_pending_queue() 的 total——全站口徑最精確的「目前待驗佇列」
    // （已對齊 QC_check_list_test.php 官方畫面並逐欄核對過，見該函式註解），不要自己
    // 另外算一次，否則這裡跟品管分頁的「目前待驗佇列」永遠可能各算出不同的數字（本次
    // 問題正是由此而來）。這裡只需要 total 這個現況快照，不傳 $from/$to（avg_wait_workdays
    // 不需要算，省一次查詢）。
    $out['to_qc'] = (int)ul_qc_pending_queue($db)['total'];

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
 *
 * $allowedTypes（2026-10-07 新增）：管理員在設定頁勾選「要列入哪些製程大類」
 * （見 ul_settings() 的 prod_process_types，通常由呼叫端直接把那份設定傳進來），只有
 * process_type_id 落在這份清單裡的才計入／回傳。**空陣列（預設）＝沿用舊行為顯示全部**，
 * 向後相容——原本的四個呼叫端（UnitLoad_API.php）不傳這個參數時行為完全不變。
 * @param array $allowedTypes 要列入的 process_type_id 清單，空陣列＝不篩選（顯示全部）
 * @return array 每列 ['process_type_id','process_type_name','unassigned','assigned','total']，依 total 由大到小排序
 */
function ul_prod_by_process_type(PDO $db, string $from, string $to, array $prodUserIds = [], array $allowedTypes = []): array
{
    $typeMap = ul_process_type_map($db);
    $rows = ul_prod_current_step_rows($db);
    $allowed = ul_ids_norm($allowedTypes);
    $allowedSet = $allowed ? array_flip($allowed) : null;

    $buckets = [];
    foreach ($rows as $r) {
        $pno = $r['process_no'] !== null ? (int)$r['process_no'] : 0;
        $info = $typeMap[$pno] ?? ['process_type_id' => 0, 'process_type_name' => '（未設定製程大類）'];
        $ptid = $info['process_type_id'];
        if ($allowedSet !== null && !isset($allowedSet[$ptid])) continue;
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
 *
 * $allowedTypes（2026-10-07 新增，與 ul_prod_by_process_type() 同一套規則）：只有
 * process_type_id 落在清單裡的才計入 by_process_type／examples／total；**examples 一併
 * 套用篩選**（而不是只篩 by_process_type 的彙總），否則開了白名單之後範例清單還是會混進
 * 被排除的製程大類，看起來像篩選沒生效。空陣列（預設）＝不篩選，向後相容既有呼叫端。
 * @param array $allowedTypes 要列入的 process_type_id 清單，空陣列＝不篩選（顯示全部）
 * @return array ['by_process_type'=>[每類筆數，依數量由大到小], 'examples'=>最多20筆範例, 'total'=>int]
 */
function ul_prod_untracked_reports(PDO $db, string $from, string $to, array $prodUserIds = [], array $allowedTypes = []): array
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

    $allowed = ul_ids_norm($allowedTypes);
    $allowedSet = $allowed ? array_flip($allowed) : null;

    $buckets = [];
    $examples = [];
    $total = 0;
    foreach ($rows as $r) {
        $pno = (int)$r['process_no'];
        $info = $typeMap[$pno] ?? ['process_type_id' => 0, 'process_type_name' => '（未設定製程大類）', 'process_name' => '#' . $pno];
        $ptid = $info['process_type_id'];
        if ($allowedSet !== null && !isset($allowedSet[$ptid])) continue;
        $total++;
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

    return ['by_process_type' => $out, 'examples' => $examples, 'total' => $total];
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
 * 包裝負荷：判定「包裝製程」改用全站唯一實作 `packing_process_lib.php` 的
 * `pk_packing_process_nos()`（2026-10-07 改用；管理員在 `views/pm/packing_schedule.php`
 * 「包裝製程設定」勾選的 process_no 清單，存在 `pm_packing_process_setting`）——
 * **本函式原本自己寫死 `process_no.ProcessName='包裝'` 比對，管理員在包裝排程頁改了設定
 * 完全不會跟著變**，本次收斂成同一個來源（鐵律4）。實測目前設定恰好就是 ProcessNo
 * 168／169（與舊的 ProcessName='包裝' 判定結果一致），改用共用函式後數字不變；往後
 * 管理員若調整這份設定，本函式與包裝排程頁、線上檢驗的「包裝製程」認定會自動保持一致。
 * **尚未設定任何包裝製程時**（`pk_packing_process_nos()` 回傳空陣列）回傳空結果並在
 * `note` 說明原因，不可以查全部製程或丟例外。
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
 *                'shortest'=>同結構最多5筆,
 *                'note'=>string（尚未設定包裝製程時才非空，說明原因）]
 */
function ul_prod_packing_stats(PDO $db, string $from, string $to): array
{
    $out = ['pending' => 0, 'daily' => [], 'avg_workdays' => null, 'longest' => [], 'shortest' => [], 'note' => ''];

    require_once __DIR__ . '/packing_process_lib.php';
    $packingNos = pk_packing_process_nos($db);
    if (!$packingNos) {
        $out['note'] = '尚未設定「包裝製程」（包裝排程頁的「包裝製程設定」目前一個製程都沒勾），本項無資料可算。';
        return $out;
    }
    $pNoIn = implode(',', array_map('intval', $packingNos));

    $stPending = $db->query(
        "SELECT COUNT(*) FROM bom_ing bi
         WHERE bi.process_no IN ({$pNoIn})
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
         WHERE r.process_no IN ({$pNoIn}) AND r.is_finished = 1
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

/**
 * 包裝逐人負荷（獨立單位，2026-10-07 新增）。$packingUserIds 是設定頁為「包裝」勾選的
 * 部門展開出的人員——使用者說明這實際上多半是倉管組（隸屬資材課），但判定「這是不是
 * 包裝工作」一律看製程本身（process_no.ProcessName='包裝'，跟 ul_prod_packing_stats()
 * 同一套規則），不是看「這個人是不是倉管組的人」：bom_ing／pm_process_daily_report
 * 本來就沒有「負責人所屬部門」這種欄位可以拿來篩資料列，只能反過來查「這個人有沒有
 * 真的報過包裝製程的工」。
 *
 * 2026-10-07 實測現場包裝報工（pm_process_daily_report.production_user_id）跟倉管組
 * 人員 id 幾乎對不上——全庫目前只有 1 筆包裝完工報工紀錄，報工人不在倉管組編制裡；
 * 包裝多半是現場其他線上人員順手報的工，不是記在倉管組編制名下。**如果交叉比對後
 * 查不到任何資料，一律如實回傳空陣列，不勉強湊數字**——呼叫端（UnitLoad_API.php 的
 * data_packing）會依是否為空另外組一句說明文字，跟 ul_pm_by_person()「沒有資料可用」
 * 時的處理方式同一套道理：本函式只回資料列，不在這裡內嵌中文說明（保持跟其餘
 * *_by_person 函式一致的回傳格式）。
 * @param array $deptIds 本單位設定範圍展開後的部門 id（見 ul_unit_dept_ids()），只用於
 *              挑人員顯示用的部門/職稱，不影響「誰報過工」這件事的判定。
 * @return array 每列 ['user_id','name','dept_name','position_name','packing_count']，
 *               依 packing_count 由大到小排序；交叉比對不到任何資料時回傳空陣列。
 */
function ul_packing_by_person(PDO $db, string $from, string $to, array $packingUserIds, array $deptIds = []): array
{
    $ids = ul_ids_norm($packingUserIds);
    if (!$ids) return [];
    $in = implode(',', $ids);

    $st = $db->prepare(
        "SELECT r.production_user_id AS person, COUNT(*) c
         FROM pm_process_daily_report r
         JOIN process_no pn ON pn.ProcessNo = r.process_no
         WHERE pn.ProcessName='包裝' AND r.is_finished = 1
           AND r.report_date BETWEEN ? AND ?
           AND r.production_user_id IN ({$in})
         GROUP BY r.production_user_id"
    );
    $st->execute([$from, $to]);
    $counts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[(int)$r['person']] = (int)$r['c'];
    }
    if (!$counts) return []; // 設定的人員名下完全查不到包裝報工，如實回空，不湊數字

    $uidsWithData = array_keys($counts);
    $people = $deptIds ? eg_people_list($db, ['user_ids' => $uidsWithData, 'dept_ids' => $deptIds])
                       : eg_people_list($db, ['user_ids' => $uidsWithData]);
    $byId = [];
    foreach ($people as $p) $byId[(int)$p['id']] = $p;

    $out = [];
    foreach ($counts as $uid => $c) {
        $p = $byId[$uid] ?? null;
        $out[] = [
            'user_id' => $uid,
            'name' => $p['user_cname'] ?? ('#' . $uid),
            'dept_name' => $p['dept_name'] ?? '',
            'position_name' => $p['position_name'] ?? '',
            'packing_count' => $c,
        ];
    }
    usort($out, fn($x, $y) => $y['packing_count'] <=> $x['packing_count']);
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
    // 2026-10-07 效能修正：這支查詢本身較重（相關子查詢＋自我 JOIN），ul_pm_summary()
    // 一次 request 內會呼叫兩次（本期/比較期，snapshot 部分結果本該相同），overview 又把
    // ul_pm_summary() 呼叫兩次，同一支查詢因此可能重複跑 4 次。純記憶化（同一次請求內
    // 共用、依 $from/$to 組合分別快取，不跨請求），不改變任何回傳內容。
    static $cache = [];
    $key = ($from ?? '') . '|' . ($to ?? '');
    if (array_key_exists($key, $cache)) return $cache[$key];

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
    $cache[$key] = ['by_process' => $byProcess, 'total' => $total];
    return $cache[$key];
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
 *
 * 2026-10-07 新增：每筆逐筆明細（rows／longest／shortest）補上 'd_id'（料號文字，由
 * bom_ing.bom 回查 bom.d_id）——使用者要求最長/最短清單要看得到卡住的是哪支料號，不必
 * 再點進製令反查；查無對應 bom 紀錄時為空字串，不報錯。
 * @return array ['rows'=>逐筆明細（含 person_id／d_id，供 ul_qc_by_person() 分組重用）,
 *                'avg_workdays'=>float|null,
 *                'longest'=>最長前5筆[bom/bom_ing_fid/process_no/process_name/d_id/
 *                           enter_at/finish_at/workdays/person_id]，
 *                'shortest'=>最短前5筆（同結構）,
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

    // 補上料號文字（2026-10-07 新增：待驗等待工作天的最長/最短清單要顯示料號，方便一眼看出
    // 卡住的是哪支料號，不必再點進製令反查）。bom.d_id 是料號文字（與 bom_ing.bom 一對一
    // 綁定同一張製令），直接用 bom_ing.bom 回查即可——不像 order_track 那邊「料號文字」與
    // 「料號主檔 id」要分兩段處理（見本機記憶 bom_client_name_cache），這裡 bom 表本身就是
    // 以製令編號為鍵、每張製令只會對到一個固定的料號文字。
    $bomNos = array_values(array_unique(array_column($rowsOut, 'bom')));
    $dIdMap = [];
    if ($bomNos) {
        $inB = implode(',', array_map(fn($v) => $db->quote($v), $bomNos));
        foreach ($db->query("SELECT bom, d_id FROM bom WHERE bom IN ({$inB})")->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $dIdMap[$b['bom']] = (string)($b['d_id'] ?? '');
        }
    }
    foreach ($rowsOut as &$r) {
        $r['d_id'] = $dIdMap[$r['bom']] ?? '';
    }
    unset($r);

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
 * 的日子本來就該算進分母，否則平均值會被墊高）。
 *
 * 2026-10-07 修正：avg_ng_rate 改成「整個期間加權」（總 NG 筆數 ÷ 總檢驗筆數），不再是
 * 舊版「逐日比例先各自算好、再對這些比例取簡單平均」。舊版在樣本量小、天與天之間檢驗
 * 筆數差異很大時會嚴重失真——實測 2026-10 只有兩天資料：10/6（4筆檢驗、0筆NG）、
 * 10/7（1筆檢驗、1筆NG），舊版算出 (0%+100%)/2=50%，但真正的整體比例應該是
 * 1筆NG÷5筆總檢驗=20%；10/7 那天剛好只驗了1筆又是NG，比例就是100%，直接把平均值
 * 拉爆，這不是「平常日子異常比例大概多少」，是統計方法本身的瑕疵（沒有用檢驗量當權重）。
 * 改成總數相除之後，樣本量大的日子自然佔比較大的權重，單筆的極端日子不會再把整體
 * 數字拉歪。daily_ng_rate（逐日明細，供趨勢圖用）維持不變、仍是逐日各自的比例，
 * 只有這個整體彙總值 avg_ng_rate 改算法。新增 total_ng／total_inspected 兩個欄位，
 * 讓畫面可以印出「20%（1/5）」這種帶分母的呈現，不是只給一個光禿禿的百分比。
 * @return array ['daily_abnormal'=>[每日：date/count],'daily_ng_rate'=>[每日：date/rate(0~1)/ng_count/total_count],
 *                'avg_abnormal_per_day'=>float,'avg_ng_rate'=>float|null,
 *                'total_ng'=>int,'total_inspected'=>int]
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
    $totalNg = 0; $totalInspected = 0;
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cnt = (int)$r['cnt'];
        if ($cnt <= 0) continue;
        $ngC = (int)$r['ng_c'];
        $rate = $ngC / $cnt;
        $dailyNgRate[] = ['date' => $r['d'], 'rate' => round($rate, 4), 'ng_count' => $ngC, 'total_count' => $cnt];
        $totalNg += $ngC; $totalInspected += $cnt;
    }

    $calendarDays = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($calendarDays < 1) $calendarDays = 1;

    return [
        'daily_abnormal' => $dailyAbnormal,
        'daily_ng_rate' => $dailyNgRate,
        'avg_abnormal_per_day' => round($totalAbnormal / $calendarDays, 2),
        'avg_ng_rate' => $totalInspected > 0 ? round($totalNg / $totalInspected, 4) : null,
        'total_ng' => $totalNg,
        'total_inspected' => $totalInspected,
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
 * 品管「逐人每日工作量」明細（2026-10-08 新增，使用者交辦，仿設計課同一種做法）：逐日
 * 逐人的檢驗筆數與其中 NG 筆數。**資料來源與 ul_qc_by_person() 完全一致**（qc_check_form
 * 的 inspector_by/approved_by ＋ qc_check 的 created_by/updated_by 兩個來源都要算，只算
 * 其中一個會跟「各人負荷明細」裡期間彙總的數字對不起來），這裡只是多了「依日期分組」
 * 這一層，不彙總——同一天同一人若兩個來源都有資料會各自一列，呼叫端自己加總。
 * @return array 每列一筆：['d'=>日期,'uid'=>品管人員id,'items'=>當天這個來源的筆數,'ng'=>其中NG筆數]
 */
function ul_qc_daily_detail(PDO $db, string $from, string $to, array $qcUserIds): array
{
    $ids = ul_ids_norm($qcUserIds);
    if (!$ids) return [];
    $inQ = implode(',', array_map(fn($v) => "'" . $v . "'", $ids));
    $inN = implode(',', $ids);
    $out = [];

    $st = $db->prepare(
        "SELECT COALESCE(check_date, DATE(created_at)) AS d, COALESCE(inspector_by, approved_by) AS person,
                SUM(CASE WHEN check_result='NG' THEN 1 ELSE 0 END) AS ng_c, COUNT(*) AS cnt
         FROM qc_check_form
         WHERE status<>'DRAFT'
           AND COALESCE(check_date, DATE(created_at)) BETWEEN ? AND ?
           AND COALESCE(inspector_by, approved_by) IN ({$inQ})
         GROUP BY d, person"
    );
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['d' => (string)$r['d'], 'uid' => (int)$r['person'], 'items' => (int)$r['cnt'], 'ng' => (int)$r['ng_c']];
    }

    $st2 = $db->prepare(
        "SELECT COALESCE(DATE(QC_check_date), DATE(created_at)) AS d, COALESCE(created_by, updated_by) AS person,
                SUM(CASE WHEN QC_check='ng' THEN 1 ELSE 0 END) AS ng_c, COUNT(*) AS cnt
         FROM qc_check
         WHERE COALESCE(DATE(QC_check_date), DATE(created_at)) BETWEEN ? AND ?
           AND COALESCE(created_by, updated_by) IN ({$inN})
         GROUP BY d, person"
    );
    $st2->execute([$from, $to]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['d' => (string)$r['d'], 'uid' => (int)$r['person'], 'items' => (int)$r['cnt'], 'ng' => (int)$r['ng_c']];
    }
    return $out;
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
 *
 * 2026-10-07 新增 `no_threshold`（可選，預設視為 false）：標 true 的指標純供參考顯示，
 * 不列入「負荷過重」判定（ul_is_overload() 一律直接回 false）——使用者原話「本期報價單
 * 筆數應該要是看當日門檻(即時)，像是 NG比例(本期每日平均)這根本不需要門檻，只是顯示用」。
 * 這個旗標本身也可被管理員在設定頁逐指標覆寫（存／讀見 ul_settings_save()／
 * ul_merge_no_threshold_overrides()），這裡只是預設值。
 *
 * `sales.quote_backlog` 2026-10-07 改成跟「日均報價單數」比較，不再是跟「本期累積總數」
 * 比較（見 ul_sales_quote_daily_rate()／ul_unit_overload_check() 的呼叫處）——本期還沒
 * 走完時，直接拿累積總數跟一個固定門檻比一定會失真（期初一定比較小）；預設值因此由
 * 「30（本期累積張數）」改成「10（每天幾張）」，取自 2026 年 1~9 月實測日均約
 * 6.5~8 張／天，10 代表「比平常明顯多」。
 */
function ul_threshold_defaults(): array
{
    return [
        'design' => [
            'batch_pending' => ['value' => 20, 'label' => '批圖中筆數（即時現況門檻）'],
            'avg_draw_workdays' => ['value' => 5, 'label' => '繪圖平均工作天（每筆訂單，僅計真正需要繪圖的訂單）'],
            'issue_orders' => ['value' => 10, 'label' => '設計備註待回覆訂單數（近' . UL_RECENT_ORDER_DAYS . '天下單者，即時現況門檻）'],
        ],
        'sales' => [
            'quote_backlog' => ['value' => 10, 'label' => '本期日均報價單數（張/天，本期尚未結束時用已過天數換算）'],
            'open_issue_count' => ['value' => 20, 'label' => '待回覆問題筆數（近' . UL_RECENT_ORDER_DAYS . '天下單者，即時現況門檻）'],
        ],
        'pm' => [
            'outsource_wip' => ['value' => 100, 'label' => '委外加工中筆數（即時現況門檻）'],
            'pending_recon_lines' => ['value' => 50, 'label' => '待對帳筆數（即時現況門檻）'],
        ],
        'prod' => [
            'unassigned_count' => ['value' => 30, 'label' => '未指派機台筆數（即時現況門檻）'],
            'avg_setup_minutes' => ['value' => 60, 'label' => '平均架機時間（分，每次架機）'],
            'untracked_count' => ['value' => 10, 'label' => '未正式指派卻已報工筆數（本期累積）'],
        ],
        'qc' => [
            'ng_rate' => ['value' => 0.08, 'label' => 'NG比例（本期整體加權，僅供參考，不計入負荷過重判定）',
                          'no_threshold' => true],
            'wait_days_avg' => ['value' => 5, 'label' => '待驗平均等待工作天（每筆）'],
            'adhoc_count' => ['value' => 10, 'label' => '脫離待驗流程的補檢驗筆數（本期累積）'],
        ],
        'packing' => [
            'pending' => ['value' => 200, 'label' => '待包裝筆數（即時現況門檻）'],
        ],
    ];
}

/** 把 ul_threshold_defaults() 的巢狀結構扁平成「單位.指標」=>['value'=>,'label'=>,'no_threshold'=>?]，方便逐鍵查找 */
function ul_threshold_defaults_flat(): array
{
    $out = [];
    foreach (ul_threshold_defaults() as $unit => $items) {
        foreach ($items as $k => $v) $out[$unit . '.' . $k] = $v;
    }
    return $out;
}

/**
 * 查「單位.指標」風格的門檻數值。$key 例如 'qc.ng_rate'。門檻優先取 $thresholds
 * （即 ul_settings() 回傳的 thresholds，可能是巢狀 ['qc'=>['ng_rate'=>5]] 或扁平
 * ['qc.ng_rate'=>5] 兩種寫法都接受），查不到才退回 ul_threshold_defaults_flat()。
 * 抽成獨立函式供 ul_is_overload() 與 ul_unit_overload_check() 共用，兩處都要知道
 * 「這個指標現在的門檻是多少」，不應該各自寫一份查找優先序（鐵律4）。
 * @return float|null 查不到任何門檻（含預設值）時回 null
 */
function ul_threshold_value(string $key, array $thresholds = []): ?float
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
    return $th === null ? null : (float)$th;
}

/**
 * 「越低越糟」的判斷規則：鍵名以 '_rate' 結尾、但不是 ng_rate/abnormal_rate/defect_rate
 * 這幾種本來就「越高越糟」的異常比例，才視為達成率類（越低越糟）。這是簡化的經驗判斷，
 * 不追求完美，之後有需要再逐鍵指定方向。抽成獨立函式供 ul_is_overload() 與
 * ul_unit_overload_check() 共用（後者要知道該印 '>' 還是 '<' 才能組出正確的理由文字）。
 */
function ul_threshold_low_is_better(string $key): bool
{
    if (!str_ends_with($key, '_rate')) return false;
    $isBadWhenHigh = (bool)preg_match('/(ng_rate|abnormal_rate|defect_rate)$/', $key);
    return !$isBadWhenHigh;
}

/**
 * 查「單位.指標」風格的「不需要門檻」旗標（2026-10-07 新增）。優先序與 ul_threshold_value()
 * 完全對稱：$thresholds 裡該指標的覆寫（巢狀或扁平皆可）先查，查到才算（即使管理員把
 * 預設 true 的指標改回 false，也要讓他做得到）；沒有覆寫才退回 ul_threshold_defaults_flat()
 * 登記的預設值；兩者都沒有就是 false（需要門檻）。
 * @return bool true＝這個指標純供參考，不列入負荷過重判定
 */
function ul_threshold_no_threshold(string $key, array $thresholds = []): bool
{
    $leaf = null;
    if (isset($thresholds[$key]) && is_array($thresholds[$key])) {
        $leaf = $thresholds[$key];
    } else {
        [$unit, $metric] = array_pad(explode('.', $key, 2), 2, '');
        if (isset($thresholds[$unit][$metric]) && is_array($thresholds[$unit][$metric])) {
            $leaf = $thresholds[$unit][$metric];
        }
    }
    if ($leaf !== null && array_key_exists('no_threshold', $leaf)) {
        return !empty($leaf['no_threshold']);
    }
    $def = ul_threshold_defaults_flat();
    return !empty($def[$key]['no_threshold'] ?? false);
}

/**
 * 依門檻判斷某個數值是否算「過重」。$key 用「單位.指標」風格（例如 'qc.ng_rate'）。
 * 2026-10-07 新增：標了「不需要門檻」(ul_threshold_no_threshold()) 的指標一律直接回
 * false——這批指標純供參考顯示，不該被標紅、也不該出現在「部門負荷總表」的超標理由裡。
 */
function ul_is_overload(float $value, string $key, array $thresholds = []): bool
{
    if (ul_threshold_no_threshold($key, $thresholds)) return false;
    $th = ul_threshold_value($key, $thresholds);
    if ($th === null) return false;
    return ul_threshold_low_is_better($key) ? ($value < $th) : ($value > $th);
}

/**
 * 「本期報價單日均張數」（quote_count÷已過天數；本期已經結束則÷整個期間總天數）。
 * sales.quote_backlog 門檻 2026-10-07 改成跟這個值比較，不再跟「本期累積總數」比較——
 * 累積型指標在期間還沒走完時，跟一個固定門檻比一定會失真（期初一定比較小，使用者原話
 * 「本期報價單筆數應該要是看當日門檻(即時)」）。ul_unit_overload_check() 與 ul_insights()
 * 共用同一支，不要各自算一次（鐵律4）。period_elapsed_days／period_total_days 由
 * ul_sales_summary() 已經算好帶出（見該函式 2026-10-07 新增的欄位），本函式只負責
 * 挑哪個當分母、算除法：
 *   本期已經走完（period_elapsed_days===null）→ 分母＝period_total_days（完整期間的
 *     真正日均，跟期間長度不同造成的偏差無關）；
 *   本期還在進行中（period_elapsed_days 是 0~N 的整數）→ 分母＝已過天數（至少 1 天，
 *     避免剛開始當天除以 0）。
 * @param array $s ul_sales_summary() 的回傳值
 * @return float 日均張數
 */
function ul_sales_quote_daily_rate(array $s): float
{
    $totalDays = (int)($s['period_total_days'] ?? 1);
    if ($totalDays < 1) $totalDays = 1;
    $elapsedRaw = $s['period_elapsed_days'] ?? null;
    $denom = ($elapsedRaw === null) ? $totalDays : max(1, (int)$elapsedRaw);
    return ((float)($s['quote_count'] ?? 0)) / $denom;
}

/**
 * 從 ul_design_summary()／ul_sales_summary() 已經算好的 period_elapsed_days／
 * period_total_days（2026-10-07 新增），組出「本期 vs 比較期要不要换算成日均比較」的
 * 判斷依據——供 ul_insights() 裡多處「本期數字較上一期增減」共用（鐵律4：不要各自判斷
 * 一次）。
 *
 * 使用者回報「本期轉生管量較2025/10月減少」這類結論：今天是 2026/10/7，本期（10月）
 * 才過 7 天，卻拿去跟「整個」比較期（例如整月 31 天）比累積總數，天生就會顯得「大幅
 * 減少」——這不是真的業績下滑，是比較基礎不公平。修法改用「日均」比較（本期累積總數÷
 * 本期已過天數，比較期累積總數÷比較期總天數），不受兩個期間長度不同影響。
 *
 * 本期已經走完（period_elapsed_days===null，oa_elapsed_days() 的既有語意）時回 null——
 * 兩個都是完整期間，直接比累積總數沒有系統性偏差，不必多算一次除法、也不必在文字裡
 * 多講一句「已換算日均」（反而可能讓使用者誤以為平常也是這樣算的）。
 * 本期還沒開始（period_elapsed_days===0）或任一天數缺資訊時同樣回 null（退回舊行為、
 * 不强行换算一個除以 0 或沒有意義的比例）。
 * @param array $cur 該單位 ul_*_summary() 的本期回傳值（需含 period_elapsed_days）
 * @param array $cmp 該單位 ul_*_summary() 的比較期回傳值（需含 period_total_days）
 * @return array|null ['cur_days'=>int,'cmp_days'=>int]
 */
function ul_period_daily_ctx_from_summary(array $cur, array $cmp): ?array
{
    $curElapsed = $cur['period_elapsed_days'] ?? null;
    if ($curElapsed === null || $curElapsed < 1) return null;
    $cmpDays = $cmp['period_total_days'] ?? null;
    if ($cmpDays === null || $cmpDays < 1) return null;
    return ['cur_days' => (int)$curElapsed, 'cmp_days' => (int)$cmpDays];
}

/**
 * 計算「本期 vs 比較期」的比較基礎——配合 ul_period_daily_ctx_from_summary() 用。
 * $dctx 為 null（本期已結束，或資訊不足）時，回傳跟修正前完全相同的「原始累積總數」
 * 比較結果（mode='total'）；$dctx 非 null（本期尚未結束）時改用「日均」比較
 * （mode='daily'，見上方函式註解），兩個數值的「增加/減少」判斷改依日均而非累積總數，
 * 不會再出現「本期才過幾天就被判定大幅減少」這種系統性誤判。
 * @return array|null null＝兩邊換算後完全沒有變化（沿用既有「無變化不產生結論」規則）；
 *              否則 ['mode'=>'total'|'daily','cur'=>顯示用本期數值,'cmp'=>顯示用比較期數值,
 *              'delta'=>float（正＝本期比比較期高）,'dir'=>'up'|'down',
 *              'note'=>string（mode=daily 時才非空，補充說明換算依據）]
 */
function ul_period_delta_calc(?array $dctx, float $curVal, float $cmpVal): ?array
{
    if ($dctx === null) {
        $delta = $curVal - $cmpVal;
        if (abs($delta) < 0.0005) return null;
        return ['mode' => 'total', 'cur' => $curVal, 'cmp' => $cmpVal, 'delta' => $delta,
                'dir' => $delta > 0 ? 'up' : 'down', 'note' => ''];
    }

    $curDaily = $curVal / $dctx['cur_days'];
    $cmpDaily = $cmpVal / $dctx['cmp_days'];
    $delta = $curDaily - $cmpDaily;
    if (abs($delta) < 0.0005) return null;
    $note = '（本期才過 ' . $dctx['cur_days'] . ' 天，比較期共 ' . $dctx['cmp_days'] . ' 天，直接比累積總數會失真，'
          . '已換算成日均比較；本期累積 ' . ul_fmt_num($curVal) . '，比較期累積 ' . ul_fmt_num($cmpVal) . '）';
    return ['mode' => 'daily', 'cur' => $curDaily, 'cmp' => $cmpDaily, 'delta' => $delta,
            'dir' => $delta > 0 ? 'up' : 'down', 'note' => $note];
}

/**
 * 數字格式化，只服務 ul_period_delta_calc() 組句用（不是全站規則）：整數印成不帶千分位
 * 的整數字串（刻意不加千分位逗號，保持跟修正前舊版直接印整數變數完全相同的外觀）；
 * 非整數印一位小數。
 */
function ul_fmt_num(float $v): string
{
    if (abs($v - round($v)) > 0.001) return number_format($v, 1, '.', '');
    return (string)(int)round($v);
}

/**
 * 把某個指標的數值格式化成人話（供 ul_unit_overload_check() 組理由文字用）：
 * '_rate' 結尾的一律當比例印成百分之一位小數；其餘數字若非整數印一位小數，否則印整數
 * （千分位）。本函式只服務理由文字的可讀性，不是全站的數字格式化規則。
 */
function ul_fmt_metric_value(string $metric, float $value): string
{
    if (str_ends_with($metric, '_rate')) return round($value * 100, 1) . '%';
    if (abs($value - round($value)) > 0.001) return number_format($value, 1);
    return number_format($value);
}

/**
 * 依 ul_threshold_defaults() 登記的全部「單位.指標」逐一檢查是否超過門檻，供總覽的
 * 「部門負荷總表」小卡判斷要不要標紅、並列出具體超標理由（ai-rules/10：顏色不可是唯一
 * 資訊，不能只給紅色沒有理由）。
 *
 * 吃的 $allData 結構跟 ul_insights() 完全一樣（同一次 overview 呼叫共用同一份已經組好
 * 的資料，不重新查資料庫）：
 * [
 *   'design' => ['cur'=>ul_design_summary()回傳, ...],
 *   'sales'  => ['cur'=>ul_sales_summary()回傳, ...],
 *   'pm'     => ['cur'=>ul_pm_summary()回傳, ...],
 *   'prod'   => ['by_process_type'=>ul_prod_by_process_type()回傳,
 *                'untracked'=>ul_prod_untracked_reports()回傳, 'setup'=>ul_prod_setup_stats()回傳],
 *   'qc'     => ['wait'=>ul_qc_wait_time()回傳, 'abnormal'=>ul_qc_abnormal_stats()回傳,
 *                'adhoc'=>ul_qc_adhoc()回傳],
 *   'packing'=> ['pending'=>int, ...],
 * ]
 * 本函式刻意只負責「超過門檻與否」這一件事，不產生趨勢或集中度那類結論文字——
 * 那仍是 ul_insights() 的職責，兩者互補不是取代，而且兩者共用同一份輸入資料，
 * 絕不會出現「總表說過重、自動分析卻沒提到」這種互相矛盾的情形。
 * @return array [unit_key => ['overloaded'=>bool, 'reasons'=>[{metric_key,label,value,threshold,text}]]]
 */
function ul_unit_overload_check(array $allData, array $thresholds = []): array
{
    $defs = ul_threshold_defaults();
    $out = [];
    foreach (ul_unit_keys() as $u) $out[$u] = ['overloaded' => false, 'reasons' => []];

    $check = function (string $unit, string $metric, $value) use (&$out, $defs, $thresholds) {
        if ($value === null) return;
        $key = $unit . '.' . $metric;
        $val = (float)$value;
        if (!ul_is_overload($val, $key, $thresholds)) return;
        $th = ul_threshold_value($key, $thresholds);
        $label = $defs[$unit][$metric]['label'] ?? $metric;
        $sign = ul_threshold_low_is_better($key) ? '<' : '>';
        $out[$unit]['overloaded'] = true;
        $out[$unit]['reasons'][] = [
            'metric_key' => $metric,
            'label' => $label,
            'value' => $val,
            'threshold' => $th,
            'text' => $label . ' ' . ul_fmt_metric_value($metric, $val) . ' ' . $sign . ' '
                    . ($th !== null ? ul_fmt_metric_value($metric, $th) : '?'),
        ];
    };

    if (isset($allData['design']['cur'])) {
        $d = $allData['design']['cur'];
        $check('design', 'batch_pending', $d['drawing_wip'] ?? null);
        $check('design', 'avg_draw_workdays', $d['avg_draw_workdays'] ?? null);
        // 2026-10-08 修正：issue_orders 改比「近 90 天下單仍未回覆」的 issue_orders_recent，
        // 不是全歷史總量——見本檔頂部 UL_RECENT_ORDER_DAYS 常數註解，全歷史總量永遠遠
        // 高於門檻、會讓紅色警示失去信號意義。
        $check('design', 'issue_orders', $d['issue_orders_recent'] ?? null);
    }
    if (isset($allData['sales']['cur'])) {
        $s = $allData['sales']['cur'];
        // 2026-10-07 修正：quote_backlog 改跟「日均報價單數」比較，不是跟本期累積總數比較
        // ——見 ul_sales_quote_daily_rate() 函式註解；與 ul_insights() 的 KPI 顯示共用同一支。
        $check('sales', 'quote_backlog', ul_sales_quote_daily_rate($s));
        // 2026-10-08 修正：同上，open_issue_count 改比近 90 天內的 open_issue_count_recent。
        $check('sales', 'open_issue_count', $s['open_issue_count_recent'] ?? null);
    }
    if (isset($allData['pm']['cur'])) {
        $p = $allData['pm']['cur'];
        $check('pm', 'outsource_wip', $p['outsource_wip'] ?? null);
        $check('pm', 'pending_recon_lines', $p['pending_recon_lines'] ?? null);
    }
    if (isset($allData['prod'])) {
        $pt = $allData['prod']['by_process_type'] ?? [];
        $check('prod', 'unassigned_count', array_sum(array_column($pt, 'unassigned')));
        $setup = $allData['prod']['setup'] ?? null;
        $check('prod', 'avg_setup_minutes', $setup['avg_minutes'] ?? null);
        $untracked = $allData['prod']['untracked'] ?? null;
        $check('prod', 'untracked_count', $untracked['total'] ?? null);
    }
    if (isset($allData['qc'])) {
        $ab = $allData['qc']['abnormal'] ?? null;
        $check('qc', 'ng_rate', $ab['avg_ng_rate'] ?? null);
        $wait = $allData['qc']['wait'] ?? null;
        $check('qc', 'wait_days_avg', $wait['avg_workdays'] ?? null);
        $adhoc = $allData['qc']['adhoc'] ?? null;
        $check('qc', 'adhoc_count', $adhoc['total'] ?? null);
    }
    if (isset($allData['packing'])) {
        $check('packing', 'pending', $allData['packing']['pending'] ?? null);
    }

    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * H. 月／季趨勢分析（2026-10-07 新增）
 *
 * 六個單位各自選一個「累積型」代表指標（期間內新發生的量，不是現況快照），逐期算出來
 * 供總覽畫趨勢折線圖，也讓 ul_insights() 判斷「連續兩期上升/下滑」這種單看一期比較
 * 看不出來的趨勢型訊息。
 *
 * **為什麼不能直接拿總覽 KPI 卡用的那些指標**：批圖中筆數／委外加工中筆數／未指派
 * 機台筆數／目前待驗佇列筆數／待包裝筆數這些全部是「現況快照」（processing_state 等
 * 欄位沒有時間戳可以切期間，見 ul_pm_summary() 等函式的既有註解）——不管你問的是今年
 * 1月還是10月，這些數字都會是「查詢當下」同一個答案，逐期疊起來只會是一條水平線，
 * 畫趨勢圖沒有意義。所以每個單位改選一個「在那段期間內真的發生了多少」的累積型指標：
 *   設計 → 本期轉生管筆數（order_track.pmGet 落在期間內，衡量繪圖產出量能）
 *   業務 → 本期開立報價單張數
 *   生管 → 本期新發包委外筆數（outsource_date 落在期間內、外包廠商）
 *   生產 → 本期報工產出數量（pm_process_daily_report.produced_qty 加總，需設定人員）
 *   品管 → 本期檢驗項目數（qc_check_form + qc_check 兩張表合計，不限特定人員）
 *   包裝 → 本期包裝完成筆數（ul_prod_packing_stats() 的 daily 加總）
 * 這些全部都是「做了多少」而不是「現在卡著多少」，才有逐期變化可言。
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 從「今天」往回數 $buckets 期（含當期所在那一期），正確跨年度——同一年度的期別用完
 * 就換成上一年度最後一期，逐步往回退。**不自己重算期間的起訖日**，起訖日一律呼叫
 * order_analysis_lib.php 既有的 oa_period_buckets()／oa_period_pick()（唯一實作），
 * 本函式只負責「決定今天落在第幾期、該往回數到哪幾期」這件事。
 * @return array 由舊到新排序，每列同 oa_period_pick() 的回傳格式（idx/label/start/end/year）
 */
function ul_trend_period_list(string $gran, int $buckets, ?string $today = null): array
{
    if ($buckets < 1) $buckets = 1;
    if ($buckets > 24) $buckets = 24;
    $today = $today ?: date('Y-m-d');
    $year = (int)substr($today, 0, 4);

    $idx = 1;
    foreach (oa_period_buckets($year, $gran) as $b) {
        if ($today >= $b['start'] && $today <= $b['end']) { $idx = (int)$b['idx']; break; }
    }

    $picked = [];
    for ($i = 0; $i < $buckets; $i++) {
        $picked[] = oa_period_pick($year, $gran, $idx);
        $idx--;
        if ($idx < 1) {
            $year--;
            $idx = count(oa_period_buckets($year, $gran));
        }
    }
    return array_reverse($picked);
}

/** 設計課趨勢代表指標：本期轉生管筆數（order_track.pmGet 落在期間內） */
function ul_trend_metric_design(PDO $db, string $from, string $to, array $ids): int
{
    $ids = ul_ids_norm($ids);
    if (!$ids) return 0;
    $in = implode(',', $ids);
    $st = $db->prepare("SELECT COUNT(*) FROM order_track
        WHERE ate IN ({$in}) AND pmGet IS NOT NULL AND DATE(pmGet) BETWEEN ? AND ? AND (Order_status IS NULL OR Order_status<>6)
          AND (parent_order_id IS NULL OR parent_order_id=0)");
    $st->execute([$from, $to]);
    return (int)$st->fetchColumn();
}

/** 設計課趨勢代表指標（2026-10-08 新增，使用者交辦）：本期新進批圖筆數（ateGet 落在
 * 期間內）——與 ul_trend_metric_design() 的「本期轉生管」是流程的一進一出兩端，兩條線
 * 一起看才看得出「進得比出得快」還是「出得比進得快」。 */
function ul_trend_metric_design_batch_in(PDO $db, string $from, string $to, array $ids): int
{
    $ids = ul_ids_norm($ids);
    if (!$ids) return 0;
    $in = implode(',', $ids);
    $st = $db->prepare("SELECT COUNT(*) FROM order_track
        WHERE ate IN ({$in}) AND ateGet IS NOT NULL AND DATE(ateGet) BETWEEN ? AND ? AND (Order_status IS NULL OR Order_status<>6)
          AND (parent_order_id IS NULL OR parent_order_id=0)");
    $st->execute([$from, $to]);
    return (int)$st->fetchColumn();
}

/** 設計課趨勢代表指標（2026-10-08 新增，使用者交辦）：累積待批圖存量，重建「這一期
 * 結束那一天」還卡著多少（跟 ul_design_wip_daily() 同一套存量重建邏輯，只是這裡只評估
 * 單一天——每期的期末——不是逐日展開，成本低很多）。 */
function ul_trend_metric_design_wip_end(PDO $db, string $periodEnd, array $ids): int
{
    $ids = ul_ids_norm($ids);
    if (!$ids) return 0;
    $in = implode(',', $ids);
    $st = $db->prepare("SELECT COUNT(*) FROM order_track ot
        WHERE ot.ate IN ({$in}) AND ot.ateGet IS NOT NULL AND DATE(ot.ateGet) <= ?
          AND (ot.pmGet IS NULL OR DATE(ot.pmGet) > ?)
          AND (ot.Order_status IS NULL OR ot.Order_status<>6) AND (ot.parent_order_id IS NULL OR ot.parent_order_id=0)");
    $st->execute([$periodEnd, $periodEnd]);
    return (int)$st->fetchColumn();
}

/** 業務課趨勢代表指標：本期開立報價單張數 */
function ul_trend_metric_sales(PDO $db, string $from, string $to, array $ids): int
{
    $ids = ul_ids_norm($ids);
    if (!$ids) return 0;
    $inQ = implode(',', array_map(fn($v) => "'" . $v . "'", $ids));
    $st = $db->prepare("SELECT COUNT(*) FROM quotation_list
        WHERE is_draft=0 AND DATE(quote_date) BETWEEN ? AND ? AND created_by IN ({$inQ})");
    $st->execute([$from, $to]);
    return (int)$st->fetchColumn();
}

/**
 * 生管趨勢代表指標：本期新發包委外筆數（outsource_date 落在期間內、maker_list.internal<>1）。
 * 跟 ul_pm_summary() 不同的是這裡刻意不篩 $pmIds——bom_ing 沒有「這張製令由哪個生管負責」
 * 的欄位（見 ul_pm_by_person() 註解），全公司共用同一條生管工作量，沒有人員可篩。
 */
function ul_trend_metric_pm(PDO $db, string $from, string $to): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM bom_ing bi
        LEFT JOIN maker_list m ON m.maker_id_no = bi.maker_id_no
        WHERE bi.outsource_date IS NOT NULL AND DATE(bi.outsource_date) BETWEEN ? AND ?
          AND COALESCE(m.internal,0)<>1");
    $st->execute([$from, $to]);
    return (int)$st->fetchColumn();
}

/** 生產課趨勢代表指標：本期報工產出數量，直接重用 ul_prod_daily_output()（鐵律4） */
function ul_trend_metric_prod(PDO $db, string $from, string $to, array $ids): int
{
    $ids = ul_ids_norm($ids);
    if (!$ids) return 0;
    return (int)ul_prod_daily_output($db, $from, $to, $ids)['total_qty'];
}

/**
 * 品管趨勢代表指標：本期檢驗項目數，qc_check_form（結構化線上檢驗單）＋qc_check
 * （現場逐批快速登記，同 ul_qc_by_person() 2026-10-07 查證到的既有缺口：現場天天在用的
 * qc_check 表不可漏算）兩張表合計，不限特定人員——全公司共用同一條待驗流程。
 */
function ul_trend_metric_qc(PDO $db, string $from, string $to): int
{
    $st1 = $db->prepare("SELECT COUNT(*) FROM qc_check_form
        WHERE status<>'DRAFT' AND COALESCE(check_date, DATE(created_at)) BETWEEN ? AND ?");
    $st1->execute([$from, $to]);
    $st2 = $db->prepare("SELECT COUNT(*) FROM qc_check
        WHERE COALESCE(DATE(QC_check_date), DATE(created_at)) BETWEEN ? AND ?");
    $st2->execute([$from, $to]);
    return (int)$st1->fetchColumn() + (int)$st2->fetchColumn();
}

/** 包裝趨勢代表指標：本期包裝完成筆數，直接重用 ul_prod_packing_stats() 的 daily 加總（鐵律4） */
function ul_trend_metric_packing(PDO $db, string $from, string $to): int
{
    $stats = ul_prod_packing_stats($db, $from, $to);
    return (int)array_sum(array_column($stats['daily'], 'count'));
}

/**
 * 六個單位的月／季趨勢序列，供總覽畫折線圖，也供 ul_insights() 判斷連續上升/下滑。
 * @param string $gran 僅支援 'month'／'quarter'（半年/整年的「上一期」間距太粗，趨勢線
 *               沒有意義，呼叫端若傳其他值一律視為 'month'）
 * @param int $buckets 往回看幾期（含當期），1~24，預設 6
 * @return array ['gran'=>, 'labels'=>[依期別由舊到新], 'periods'=>[同 labels 順序的
 *                {label,start,end,year,idx}], 'series'=>[unit_key => [依 labels 順序的數值]],
 *                'metric_labels'=>[unit_key => 該單位這條線代表什麼的中文說明]]
 */
function ul_trend_series(PDO $db, array $settings, string $gran, int $buckets = 6): array
{
    if (!in_array($gran, ['month', 'quarter'], true)) $gran = 'month';
    $periods = ul_trend_period_list($gran, $buckets);

    $designIds = array_column(ul_dept_user_ids($db, $settings, 'design'), 'id');
    $salesIds  = array_column(ul_dept_user_ids($db, $settings, 'sales'), 'id');
    $prodIds   = array_column(ul_dept_user_ids($db, $settings, 'prod'), 'id');

    $labels = [];
    // 2026-10-08 新增：design_batch_in（新進批圖）／design_wip_end（期末累積待批圖存量）
    // 兩條額外的設計課序列（使用者交辦），**不取代** design（本期轉生管）這個既有序列——
    // ul_insights() 的 $streak() 只認 series['design']（轉生管量連續上升/下滑），拿掉或
    // 改掉它會讓那段既有判斷跟著壞掉；新序列用新的鍵名並列存在。
    $series = ['design' => [], 'sales' => [], 'pm' => [], 'prod' => [], 'qc' => [], 'packing' => [],
               'design_batch_in' => [], 'design_wip_end' => []];
    foreach ($periods as $p) {
        $labels[] = $p['label'];
        $series['design'][]  = ul_trend_metric_design($db, $p['start'], $p['end'], $designIds);
        $series['sales'][]   = ul_trend_metric_sales($db, $p['start'], $p['end'], $salesIds);
        $series['pm'][]      = ul_trend_metric_pm($db, $p['start'], $p['end']);
        $series['prod'][]    = ul_trend_metric_prod($db, $p['start'], $p['end'], $prodIds);
        $series['qc'][]      = ul_trend_metric_qc($db, $p['start'], $p['end']);
        $series['packing'][] = ul_trend_metric_packing($db, $p['start'], $p['end']);
        $series['design_batch_in'][] = ul_trend_metric_design_batch_in($db, $p['start'], $p['end'], $designIds);
        $series['design_wip_end'][]  = ul_trend_metric_design_wip_end($db, $p['end'], $designIds);
    }

    return [
        'gran' => $gran,
        'labels' => $labels,
        'periods' => array_map(fn($p) => [
            'label' => $p['label'], 'start' => $p['start'], 'end' => $p['end'],
            'year' => $p['year'], 'idx' => $p['idx'],
        ], $periods),
        'series' => $series,
        'metric_labels' => [
            'design'  => '本期轉生管筆數',
            'sales'   => '本期開立報價單張數',
            'pm'      => '本期新發包委外筆數',
            'prod'    => '本期報工產出數量',
            'qc'      => '本期檢驗項目數',
            'packing' => '本期包裝完成筆數',
            'design_batch_in' => '本期新進批圖筆數',
            'design_wip_end'  => '期末累積待批圖存量',
        ],
    ];
}

/**
 * 吃呼叫端已經組好的六個單位摘要資料，產生「依單位分組」的結論文字（純文字組合，本函式
 * 不查資料庫，單一職責——跟 order_analysis_lib.php 的 oa_insights() 同一種寫法）。
 * 2026-10-07 使用者要求「分不同部門的內容顯示」且「內容過於簡略」：
 *   ① 回傳格式由「扁平一串」改成「依單位分組」：['design'=>[...], 'sales'=>[...], ...]，
 *      前端用單位中文名稱當分組標題；某單位完全沒有可講的內容就回傳空陣列（不硬湊）。
 *   ② 每個單位盡量涵蓋：現況/本期核心數字（含門檻超過與否）、與比較期增減、
 *      （有 $trend 時）連續上升/下滑的趨勢型訊息、至少一條該單位特有的補充內容。
 *
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
 *   'packing'=> ['pending'=>int, 'avg_workdays'=>float|null],
 * ]
 * $trend 可選——傳入 ul_trend_series() 的回傳值時，才會多算「連續兩期以上上升/下降」這種
 * 趨勢型訊息（仿 order_analysis_lib.php 的 oa_insights()「連續兩期下滑」寫法）；不傳就跳過
 * 這一條，其餘結論不受影響。**trend 固定以「今天」往回推算，不一定等於呼叫端目前選的
 * 期間篩選**（例如使用者正在看去年某個月），所以文字一律講「最近N個月/季」而不是「本期」，
 * 避免跟上面那些真正對應目前篩選期間的結論混在一起看不出差異。
 *
 * ── 2026-10-07 修正：「本期還沒過完卻拿去跟完整期間比」的系統性失真 ──
 * 使用者實測回報「本期轉生管量較2025/10月減少69→291筆」：今天是 2026/10/7，本期（10月）
 * 才過 7 天，卻拿去跟整個 2025/10（31天）比累積總數，天生就會顯得「大幅減少」，不是真的
 * 業績下滑。全面檢查後兩種情形都會踩到同一個問題，修法不同：
 *   ① 「連續兩期上升/下滑」（$streak 內部）：trend 序列最後一期固定是「今天所在的那一期」
 *      （見 ul_trend_series()），只要它還沒走完就直接排除在趨勢判斷之外（改看前三個已經
 *      完整結束的期別），不需要呼叫端額外提供任何期間資訊——$trend 本身就帶著每一期真實
 *      的 start/end，這段修正不依賴任何新參數，對既有（未更新的）呼叫端立即生效。
 *   ② 設計課「本期轉生管量」、業務課「報價單量」「訂單建立量」這三個 cur-vs-cmp 的累積型
 *      比較，改用 ul_period_delta_calc()／ul_period_daily_ctx_from_summary()：本期已經
 *      走完時行為與修正前完全相同（直接比累積總數）；本期還在進行中時改成「日均」比較
 *      （本期累積÷已過天數 vs 比較期累積÷比較期總天數），不受兩個期間長度不同影響。
 *      這兩個函式讀的是 ul_design_summary()／ul_sales_summary() 2026-10-07 新增的
 *      period_elapsed_days／period_total_days 欄位（這兩支函式本來就拿得到 $from/$to，
 *      在函式內部直接算好附帶回傳，不需要改 ul_insights() 的參數、也不需要改呼叫端）。
 *   業務課 sales.quote_backlog 門檻同一批改成跟「日均報價單數」比較（不是跟本期累積
 *   總數比較），見 ul_sales_quote_daily_rate()。
 * @param array $thresholds ul_settings() 回傳的 thresholds（巢狀或扁平皆可，見 ul_is_overload()）
 * @param array|null $trend ul_trend_series() 的回傳值（可選）
 * @return array ['design'=>[每列 level/title/detail/metric], 'sales'=>[...], 'pm'=>[...],
 *                'prod'=>[...], 'qc'=>[...], 'packing'=>[...]]
 */
function ul_insights(array $allData, array $thresholds = [], ?array $trend = null): array
{
    $out = ['design' => [], 'sales' => [], 'pm' => [], 'prod' => [], 'qc' => [], 'packing' => []];
    $add = function (string $unit, string $level, string $title, string $detail, string $metric = '') use (&$out) {
        $out[$unit][] = ['level' => $level, 'title' => $title, 'detail' => $detail, 'metric' => $metric];
    };
    $granName = ['month' => '月', 'quarter' => '季', 'half' => '半年', 'year' => '年'];
    $streak = function (string $unit, string $label) use ($trend, $add, $granName) {
        if (!$trend || empty($trend['series'][$unit])) return;
        $series = $trend['series'][$unit];
        $labels = $trend['labels'] ?? [];
        $periods = $trend['periods'] ?? [];
        $n = count($series);
        if ($n < 1) return;

        // 2026-10-07 修正：trend 固定以「今天」往回推算（ul_trend_series() 函式註解），
        // 最後一期永遠是「今天所在的那一期」——如果那一期還沒走完（例如今天是10/7、
        // 最後一期是10月），它的累積值天生就會比完整期間的前幾期小，不是真的下滑。
        // 這跟使用者回報「本期轉生管量較2025/10月減少」是同一種系統性問題，只是出現在
        // 「連續兩期下滑/上升」這個趨勢判斷裡——而且這裡完全不需要額外的期間資訊，
        // $trend['periods'] 本身就帶著每一期真實的 start/end，直接跟「今天」比對即可。
        // 判定為「還沒走完」時排除最後一期，改用往前一期開始的三期判斷；排除後若不足
        // 三期就不產生這條結論（跟原本資料不足三期時的既有行為一致）。
        $lastEnded = true;
        if (!empty($periods[$n - 1]['end'])) {
            $today = date('Y-m-d');
            $lastEnded = ($today >= $periods[$n - 1]['end']);
        }
        $endIdx = $lastEnded ? $n : $n - 1;
        if ($endIdx < 3) return;

        $a2 = (float)$series[$endIdx - 3]; $a1 = (float)$series[$endIdx - 2]; $a0 = (float)$series[$endIdx - 1];
        $gn = $granName[$trend['gran'] ?? 'month'] ?? '期';
        $note = $lastEnded ? '' : '（最近一' . $gn . '尚未結束，已排除在外，改看前三個已完整結束的' . $gn . '）';
        if ($a0 < $a1 && $a1 < $a2 && $a2 > 0) {
            $add($unit, 'warn', $label . '連續兩' . $gn . '下滑',
                '最近三' . $gn . '「' . $labels[$endIdx - 3] . '」→「' . $labels[$endIdx - 2] . '」→「' . $labels[$endIdx - 1]
                . '」的' . $label . '一路往下（' . $a2 . ' → ' . $a1 . ' → ' . $a0 . '）' . $note . '，這種趨勢單看一期是看不出來的。',
                round(($a0 - $a2) / $a2 * 100, 1) . '%');
        } elseif ($a0 > $a1 && $a1 > $a2 && $a2 >= 0) {
            $add($unit, 'info', $label . '連續兩' . $gn . '上升',
                '最近三' . $gn . '「' . $labels[$endIdx - 3] . '」→「' . $labels[$endIdx - 2] . '」→「' . $labels[$endIdx - 1]
                . '」的' . $label . '持續增加（' . $a2 . ' → ' . $a1 . ' → ' . $a0 . '）' . $note . '。');
        }
    };

    // 設計課
    if (isset($allData['design']['cur'])) {
        $d = $allData['design']['cur'];
        $cl = $allData['design']['cmp_label'] ?? '上一期';
        $cmp = $allData['design']['cmp'] ?? null;

        // 2026-10-07 使用者明確要求：「自動分析」只分析區間內能計算的內容，不要顯示整個
        // 即時現況（現況是「現在這一刻」，跟上面的期間篩選器完全無關，混在依區間分析的
        // 內容裡會讓人誤以為是在講這個期間）。批圖中（drawing_wip）是即時佇列快照——
        // bom_ing/order_track 只存「現在」的值，系統沒有保留「過去某個區間當時」的歷史
        // 記錄，技術上做不出依區間計算的版本，所以乾脆不放進自動分析；它本來就已經在
        // KPI 卡片上用「即時現況」標示過一次，不需要在這裡重複又混淆。下面那段原本比較
        // drawing_wip 本期/比較期的結論也一併移除——兩次呼叫 ul_design_summary() 查的是
        // 同一個現況，delta 恆為 0，從來不會觸發，是死碼。

        if ($cmp && isset($cmp['pm_get']) && (int)$cmp['pm_get'] > 0) {
            // 2026-10-07 修正：本期轉生管量是累積型指標（pmGet 落在期間內才算），本期
            // 還沒走完時直接拿累積總數跟完整比較期比一定失真（使用者實測回報「本期轉
            // 生管量較2025/10月減少69→291筆」，當時本期只過了幾天）——改用
            // ul_period_delta_calc()，本期未結束時自動换算成日均比較。
            $dctx = ul_period_daily_ctx_from_summary($d, $cmp);
            $calc = ul_period_delta_calc($dctx, (float)$d['pm_get'], (float)$cmp['pm_get']);
            if ($calc) {
                $unitTxt = $calc['mode'] === 'daily' ? '筆/天' : '筆';
                $add('design', $calc['dir'] === 'down' ? 'warn' : 'good',
                    '本期轉生管量較' . $cl . ($calc['dir'] === 'up' ? '增加' : '減少'),
                    '本期轉生管 ' . ul_fmt_num($calc['cur']) . ' ' . $unitTxt . '，較' . $cl . '的 '
                    . ul_fmt_num($calc['cmp']) . ' ' . $unitTxt . ($calc['dir'] === 'up' ? '增加' : '減少') . ' '
                    . ul_fmt_num(abs($calc['delta'])) . ' ' . $unitTxt . '（這是繪圖產出量能的指標）' . $calc['note'] . '。',
                    ($calc['dir'] === 'up' ? '+' : '-') . ul_fmt_num(abs($calc['delta'])));
            }
        }

        if ($d['avg_draw_workdays'] !== null
            && ul_is_overload((float)$d['avg_draw_workdays'], 'design.avg_draw_workdays', $thresholds)) {
            $add('design', 'warn', '繪圖平均工作天偏長',
                '本期由業務轉設計到轉生管平均 ' . $d['avg_draw_workdays'] . ' 個工作天，已超過門檻。', $d['avg_draw_workdays'] . ' 天');
        }

        // 待回覆設計備註（issue_orders）與新案件／無圖面（new_case）同屬即時現況快照，理由
        // 同上一段——移除，不塞進依區間分析的「自動分析」裡；KPI 卡片上已經用「即時現況」
        // 標示過，不重複顯示。

        $streak('design', '轉生管量');
    }

    // 業務課
    if (isset($allData['sales']['cur'])) {
        $s = $allData['sales']['cur'];
        $cl = $allData['sales']['cmp_label'] ?? '上一期';
        $cmp = $allData['sales']['cmp'] ?? null;

        // 2026-10-07 修正：quote_backlog 門檻改跟「日均報價單數」比較，不是跟本期累積
        // 總數比較——使用者原話「本期報價單筆數應該要是看當日門檻(即時)」，見
        // ul_sales_quote_daily_rate() 函式註解（與 ul_unit_overload_check() 共用同一支）。
        $qDaily = ul_sales_quote_daily_rate($s);
        $qBad = ul_is_overload($qDaily, 'sales.quote_backlog', $thresholds);
        $add('sales', $qBad ? 'bad' : 'info', $qBad ? '本期報價單量偏高' : '本期報價單現況',
            '本期開立 ' . $s['quote_count'] . ' 張報價單（日均 ' . round($qDaily, 1) . ' 張/天），共 '
            . $s['quote_item_count'] . ' 個報價項目' . ($qBad ? '，日均張數已超過門檻。' : '。'),
            round($qDaily, 1) . ' 張/天');

        // 2026-10-07 修正：報價單量、訂單建立量都是累積型指標，本期還沒走完時直接拿
        // 累積總數跟完整比較期比一定失真——跟上面設計課「本期轉生管量」同一種系統性
        // 問題，一併改用 ul_period_delta_calc() 的日均换算（見該函式註解）。
        $dctxS = ul_period_daily_ctx_from_summary($s, $cmp ?? []);
        if ($cmp && (int)$cmp['quote_count'] > 0) {
            $calc = ul_period_delta_calc($dctxS, (float)$s['quote_count'], (float)$cmp['quote_count']);
            if ($calc) {
                $unitTxt = $calc['mode'] === 'daily' ? '張/天' : '張';
                $add('sales', $calc['dir'] === 'up' ? 'warn' : 'info', '報價單量較' . $cl . ($calc['dir'] === 'up' ? '增加' : '減少'),
                    '本期 ' . ul_fmt_num($calc['cur']) . ' ' . $unitTxt . '，較' . $cl . '的 '
                    . ul_fmt_num($calc['cmp']) . ' ' . $unitTxt . '，' . ($calc['dir'] === 'up' ? '增加' : '減少') . ' '
                    . ul_fmt_num(abs($calc['delta'])) . ' ' . $unitTxt . $calc['note'] . '。',
                    ($calc['dir'] === 'up' ? '+' : '-') . ul_fmt_num(abs($calc['delta'])));
            }
        }

        if ($cmp && (int)$cmp['order_count'] > 0) {
            $calcO = ul_period_delta_calc($dctxS, (float)$s['order_count'], (float)$cmp['order_count']);
            if ($calcO) {
                $unitTxt = $calcO['mode'] === 'daily' ? '張/天' : '張';
                $add('sales', $calcO['dir'] === 'down' ? 'warn' : 'good', '訂單建立量較' . $cl . ($calcO['dir'] === 'up' ? '增加' : '減少'),
                    '本期建立 ' . ul_fmt_num($calcO['cur']) . ' ' . $unitTxt . '，較' . $cl . '的 '
                    . ul_fmt_num($calcO['cmp']) . ' ' . $unitTxt . ($calcO['dir'] === 'up' ? '增加' : '減少') . ' '
                    . ul_fmt_num(abs($calcO['delta'])) . ' ' . $unitTxt . $calcO['note'] . '。',
                    ($calcO['dir'] === 'up' ? '+' : '-') . ul_fmt_num(abs($calcO['delta'])));
            }
        }

        // 待回覆問題（open_issue_count）是即時快照（現在有多少張訂單帶著開放中的設計備註
        // 問題，不受期間篩選影響），同理移除，不放進依區間分析的「自動分析」；KPI 卡片
        // 已標示即時現況與過重與否。

        if ((int)$s['quote_count'] > 0) {
            $avgItems = round($s['quote_item_count'] / $s['quote_count'], 1);
            $add('sales', 'info', '平均每張報價單項目數', '本期每張報價單平均含 ' . $avgItems . ' 個項目。', $avgItems . ' 項');
        }

        $streak('sales', '報價單量');
    }

    // 生管
    if (isset($allData['pm']['cur'])) {
        $p = $allData['pm']['cur'];

        // 委外/廠內加工中、製程流轉現況（to_qc/pending_transfer/transferred）都是 bom_ing
        // 目前狀態的即時快照，跟上一段設計課同理移除，不放進依區間分析的「自動分析」；
        // KPI 卡片已標示即時現況與過重與否，不在這裡重複。

        // 待對帳（pending_recon_lines）是唯一真正依區間計算的——依選定期間涵蓋的帳款月份
        // 彙總 acc_recon_track 的對帳進度（ul_billing_months_between()），不是即時佇列，
        // 標題原本寫「現況」會跟上面那些真現況混在一起，正名為「本期」。
        $rcBad = ul_is_overload((float)$p['pending_recon_lines'], 'pm.pending_recon_lines', $thresholds);
        if ($rcBad || (int)$p['pending_recon_lines'] > 0) {
            $add('pm', $rcBad ? 'bad' : 'info', $rcBad ? '待對帳筆數偏高' : '本期待對帳',
                '本期涵蓋帳款月份待對帳 ' . ($p['pending_recon_parties'] ?? 0) . ' 家廠商、共 ' . $p['pending_recon_lines'] . ' 筆'
                . ($rcBad ? '，已超過門檻。' : '。'), (string)$p['pending_recon_lines']);
        }

        $streak('pm', '新發包委外量');
    }

    // 生產課
    if (isset($allData['prod'])) {
        // 機台指派現況、未指派集中在哪個製程大類：都是 ul_prod_current_step_rows() 的即時
        // 快照（現在卡在哪一關），同理移除，不放進依區間分析的「自動分析」；KPI 卡片已
        // 標示即時現況與過重與否。

        $setup = $allData['prod']['setup'] ?? null;
        if ($setup && $setup['avg_minutes'] !== null) {
            // 平均架機時間是本期內實際報工的架機紀錄算出來的，真正依區間計算，標題原本
            // 寫「現況」容易跟上面那些真現況混在一起，正名為「本期」。
            $stBad = ul_is_overload((float)$setup['avg_minutes'], 'prod.avg_setup_minutes', $thresholds);
            $add('prod', $stBad ? 'warn' : 'info', $stBad ? '平均架機時間偏長' : '本期平均架機時間',
                '本期平均架機時間 ' . $setup['avg_minutes'] . ' 分鐘' . ($stBad ? '，已超過門檻。' : '。'),
                $setup['avg_minutes'] . ' 分');
        }

        $untracked = $allData['prod']['untracked'] ?? null;
        if ($untracked) {
            $utBad = ul_is_overload((float)$untracked['total'], 'prod.untracked_count', $thresholds);
            if ($utBad || (int)$untracked['total'] > 0) {
                $add('prod', $utBad ? 'bad' : 'warn', $utBad ? '未正式指派卻已報工偏多' : '出現未正式指派卻已報工的件',
                    '本期共 ' . $untracked['total'] . ' 筆報工對應的製程，當時沒有被正式指派機台或還沒進入正常流程'
                    . ($utBad ? '，已超過門檻。' : '。'), (string)$untracked['total']);
            }
        }

        $streak('prod', '報工產出數量');
    }

    // 品管
    if (isset($allData['qc'])) {
        $ab = $allData['qc']['abnormal'] ?? null;
        $wait = $allData['qc']['wait'] ?? null;
        $adhoc = $allData['qc']['adhoc'] ?? null;

        if ($ab && $ab['avg_ng_rate'] !== null) {
            // 2026-10-07 修正：avg_ng_rate 改成整體加權算法（見 ul_qc_abnormal_stats()
            // 函式註解），文字同步加上分子/分母；此指標預設「不需要門檻」（樣本量小、
            // 波動本來就大），不列入負荷過重判定，ul_is_overload() 一律回 false。
            $ngBad = ul_is_overload((float)$ab['avg_ng_rate'], 'qc.ng_rate', $thresholds);
            $ngNoThr = ul_threshold_no_threshold('qc.ng_rate', $thresholds);
            $add('qc', $ngBad ? 'bad' : 'info', $ngBad ? 'NG比例偏高' : '本期NG比例',
                '本期NG比例約 ' . round($ab['avg_ng_rate'] * 100, 1) . '%（' . ($ab['total_ng'] ?? 0) . '/'
                . ($ab['total_inspected'] ?? 0) . '），平均每日異常單 ' . $ab['avg_abnormal_per_day'] . ' 件'
                . ($ngBad ? '，已超過門檻。' : ($ngNoThr ? '（僅供參考，不計入負荷過重判定）。' : '。')),
                round($ab['avg_ng_rate'] * 100, 1) . '%');
        }

        if ($wait && $wait['avg_workdays'] !== null) {
            $wBad = ul_is_overload((float)$wait['avg_workdays'], 'qc.wait_days_avg', $thresholds);
            $add('qc', $wBad ? 'warn' : 'info', $wBad ? '待驗平均等待工作天偏長' : '本期待驗平均等待工作天',
                '本期完成檢驗的製程，平均等了 ' . $wait['avg_workdays'] . ' 個工作天才驗完' . ($wBad ? '，已超過門檻。' : '。'),
                $wait['avg_workdays'] . ' 天');
            if (!empty($wait['longest'])) {
                $lg = $wait['longest'][0];
                $add('qc', 'warn', '等待最久的一筆',
                    '製令 ' . $lg['bom'] . '（' . $lg['process_name'] . '）本期等了 ' . $lg['workdays'] . ' 個工作天才驗完。',
                    $lg['workdays'] . ' 天');
            }
        }

        if ($adhoc) {
            $adBad = ul_is_overload((float)$adhoc['total'], 'qc.adhoc_count', $thresholds);
            if ($adBad || (int)$adhoc['total'] > 0) {
                $add('qc', $adBad ? 'warn' : 'info', $adBad ? '脫離正常待驗流程的補檢驗偏多' : '脫離正常待驗流程的補檢驗',
                    '本期有 ' . $adhoc['total'] . ' 筆檢驗不是掛在正常待驗佇列上完成的' . ($adBad ? '，已超過門檻。' : '。'),
                    (string)$adhoc['total']);
            }
        }

        $streak('qc', '檢驗項目數');
    }

    // 包裝（獨立單位，2026-10-07 新增；資料來源沿用既有 ul_prod_packing_stats()）
    if (isset($allData['packing'])) {
        $pk = $allData['packing'];

        // 待包裝筆數是即時佇列快照，同理移除，不放進依區間分析的「自動分析」；KPI 卡片
        // 已標示即時現況與過重與否。

        if (isset($pk['avg_workdays']) && $pk['avg_workdays'] !== null) {
            $add('packing', 'info', '平均包裝處理工作天',
                '本期完成包裝的件，從進入待包裝到完成平均 ' . $pk['avg_workdays'] . ' 個工作天。', $pk['avg_workdays'] . ' 天');
        }

        $streak('packing', '包裝完成筆數');
    }

    return $out;
}
