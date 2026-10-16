<?php
/**
 * 組織處境分析表「依系統資料建議」引擎 —— 2026-10-14 建立
 * ------------------------------------------------------------------
 * 不是外部 AI：全部由本系統既有資料依「規則」即時算出，選哪一年就用那一年的實際資料，所以每年的建議會隨資料變動。
 * 每一條建議都附：①象限（S/W/O/T）②利害關係者與需求 ③帶實際數字的議題描述 ④佐證來源（KPI／出貨／人員／行事曆／委外／品質異常／溝通管理）
 * ⑤規則說明（為什麼系統會建議這一條）；有對應的溝通記錄時自動把單號附在「佐證」後面，加入表格後就是可追溯的。
 *
 * 規則一覽（門檻是常數，要調整改這支）：
 *   KPI 當年有月份未達年度目標 → W/T（依指標性質）；全部月份達標 → S
 *   出貨前三大客戶占當年出貨 ≥ RM_SG_CONC_PCT% → T
 *   當年首次出貨的新客戶 ≥ 1 家 → O
 *   當年（及前一年）有颱風假 → T
 *   當年某製程的委外只有 1 家廠商（≥ RM_SG_SINGLE_MIN 筆）／某廠商占委外筆數 ≥ RM_SG_VENDOR_PCT% → T
 *   當年品質異常處理單 ≥ 1 張 → W
 *   當年與政府單位（環保局、消防局…類別 kind=government）有溝通記錄 → T（法規遵循）
 *   措施追蹤逾期未結案 → W
 */
require_once __DIR__ . '/risk_mgmt_lib.php';

const RM_SG_CONC_PCT    = 30;   // 前三大客戶占比門檻（%）
const RM_SG_VENDOR_PCT  = 30;   // 單一廠商占委外筆數門檻（%）
const RM_SG_SINGLE_MIN  = 20;   // 單一來源製程要達到的當年發包筆數

/** KPI 名稱 → 建議的象限／利害關係者／需求（未命中的退回 W／客戶） */
function rm_sg_kpi_class(string $name): array
{
    if (mb_strpos($name, '廠商準時') !== false) return ['T', '供應商', '合理的交期與訂單預告'];
    if (mb_strpos($name, '準時出貨') !== false) return ['T', '客戶', '準時交貨'];
    if (mb_strpos($name, '客訴') !== false)     return ['W', '客戶', '低客訴、不良品不外流'];
    if (mb_strpos($name, '不良') !== false)     return ['W', '客戶', '品質穩定、不良品不外流'];
    if (mb_strpos($name, '校驗') !== false)     return ['W', '客戶', '量測數據可靠、可追溯'];
    if (mb_strpos($name, '接單') !== false || mb_strpos($name, '達成') !== false) return ['W', '客戶', '穩定的訂單與營收'];
    return ['W', '客戶', '符合年度目標'];
}

/** 規則算失敗時不讓整個建議清單消失，但要留下紀錄（不然規則壞了完全看不出來） */
function rm_sg_err(Throwable $e): void { @error_log('[risk_mgmt_suggest] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); $GLOBALS['RM_SG_LAST_ERR'][] = $e->getMessage(); }

function rm_sg_f($v, int $d = 1): string { return rtrim(rtrim(number_format((float)$v, $d), '0'), '.'); }
function rm_sg_pct($a, $b, int $d = 1): string { return $b > 0 ? rm_sg_f($a * 100 / $b, $d) . '%' : '-'; }

/** 這一年、這些名稱相關的溝通記錄單號（最多 3 筆，新到舊），當作佐證 */
function rm_sg_recs(PDO $db, int $year, array $names, ?string $catKind = null): string
{
    $w = ["r.is_deleted=0", "YEAR(r.comm_date)=?"]; $a = [$year];
    if ($catKind !== null) { $w[] = "c.kind=?"; $a[] = $catKind; }
    if ($names) { $o = []; foreach ($names as $n) { $o[] = "r.party_name LIKE ?"; $a[] = '%' . $n . '%'; } $w[] = '(' . implode(' OR ', $o) . ')'; }
    try {
        $st = $db->prepare("SELECT r.rec_no FROM comm_record r LEFT JOIN comm_track_cat c ON c.cat_id=r.party_cat_id WHERE " . implode(' AND ', $w) . " ORDER BY r.comm_date DESC LIMIT 3");
        $st->execute($a);
        $no = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $no = []; }
    return $no ? '、溝通記錄 ' . implode('、', $no) : '';
}

/**
 * 稽核製程範圍（訂單追蹤設定的稽核製程標籤）：風險與機會的客戶／品質／退貨統計一律只算「稽核製程的料號」。
 * 料號判定與合約審查同一套（ot_astag_audit_part_set：主檔 id 優先，退路＝客戶＋料號文字）。
 * @return int[] d_setting.d_id 清單（沒有任何稽核製程訂單時回空陣列）
 */
function rm_audit_part_ids(PDO $db): array
{
    static $ids = null;
    if ($ids !== null) return $ids;
    require_once __DIR__ . '/order_as_tag_lib.php';
    $set = ot_astag_audit_part_set($db, true); $ids = array_map('intval', array_keys($set['pk']));
    try {
        foreach ($db->query("SELECT ds.d_id, ds.D_Setting_Id t, ds.Customer_Id ci, cl.customer cn FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id=ds.Customer_Id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = trim((string)$r['t']); if ($t === '') continue;
            if (($r['ci'] !== null && isset($set['txt'][trim((string)$r['ci']) . '|' . $t])) || ($r['cn'] !== null && isset($set['txt'][trim((string)$r['cn']) . '|' . $t]))) $ids[] = (int)$r['d_id'];
        }
    } catch (Throwable $e) { rm_sg_err($e); }
    return $ids = array_values(array_unique($ids));
}
/** SQL 片段：該欄位（料號主檔 id）屬於稽核製程料號。沒有稽核製程料號時一律不成立。 */
function rm_audit_in(PDO $db, string $col = 'd_setting_id'): string
{
    $ids = rm_audit_part_ids($db);
    return $col . ' IN (' . ($ids ? implode(',', $ids) : '0') . ')';
}
/** 目前設定的稽核製程（訂單追蹤的稽核製程標籤）清單，供模組設定勾選「對外宣稱有外包」 */
function rm_audit_procs_view(PDO $db): array
{
    require_once __DIR__ . '/order_as_tag_lib.php';
    $out = [];
    foreach (ot_astag_defs($db, true) as $d) if (($d['kind'] ?? '') === 'process') $out[] = ['tag_id' => (int)$d['tag_id'], 'name' => (string)$d['proc_name'], 'outsourced' => (int)($d['outsourced'] ?? 0)];
    return $out;
}
/** 對外宣稱「有外包」的稽核製程：製程編號 => 稽核製程名稱（模組設定勾選；沒勾＝沒有外包，所有委外分析都不列） */
function rm_outsource_proc_nos(PDO $db): array
{
    require_once __DIR__ . '/order_as_tag_lib.php';
    // 「對外宣稱有外包」改在訂單追蹤的稽核製程設定（ot_as_proc_tag.outsourced）
    $sel = [];
    foreach (ot_astag_defs($db, true) as $d) if (($d['kind'] ?? '') === 'process' && !empty($d['outsourced'])) $sel[] = (int)$d['tag_id'];
    $out = [];
    if (!$sel) return $out;
    foreach (ot_astag_audit_process_nos($db) as $no => $v) {
        foreach ($v['tags'] as $tid => $nm) if (in_array((int)$tid, $sel, true)) { $out[(int)$no] = $nm; break; }
    }
    return $out;
}

/**
 * 「加工項目」綁定判定（主檔管理→廠商→加工項目所綁定的製程大類／小類，dict_maker_sub_category.bind_*／ref_*）：
 * 廠商要綁定到「這些製程本身」或「這些製程所屬的大類」，才算那個製程的委外廠商。
 * 不可只看 bom_ing 的發包紀錄——發包資料可能掛在別的製程上（例如熱處理廠商掛到齒研製程），
 * 以廠商主檔的綁定為準。回傳可直接放進 IN (...) 的引號清單；沒有綁定任何廠商回 ''。
 */
function rm_bound_makers_sql(PDO $db, array $procNos): string
{
    $procNos = array_values(array_filter(array_map('intval', $procNos)));
    if (!$procNos) return "''";
    try {
        $in = implode(',', $procNos);
        $types = array_map('intval', $db->query("SELECT DISTINCT process_type_id FROM process_no WHERE ProcessNo IN ($in) AND process_type_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
        $tin = $types ? implode(',', $types) : '0';
        $ids = $db->query("SELECT DISTINCT m.maker_id_no FROM maker_sub_category_mapping m JOIN dict_maker_sub_category s ON s.sub_cat_id=m.sub_cat_id
                           WHERE COALESCE(s.is_active,1)=1 AND (s.bind_process_type_id IN ($tin) OR s.bind_process_no_id IN ($in) OR s.ref_process_type_id IN ($tin))")->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return "''";
        return implode(',', array_map(function ($x) use ($db) { return $db->quote((string)$x); }, $ids));
    } catch (Throwable $e) { rm_sg_err($e); return "''"; }
}

/**
 * @return array[] 每筆：['source','key','stakeholder','needs','issue','why']
 */
function rm_suggest_context(PDO $db, int $year): array
{
    $out = [];
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $y1 = $year . '-01-01'; $y2 = min($year . '-12-31', $today);
    $add = function ($src, $key, $sh, $needs, $issue, $why) use (&$out) {
        $out[] = ['source'=>$src, 'key'=>$key, 'stakeholder'=>$sh, 'needs'=>$needs, 'issue'=>$issue, 'why'=>$why];
    };

    /* ---- 1. KPI ---- */
    try {
        $inds = $db->prepare("SELECT y.indicator_id, i.name, y.target_direction td, y.target_value tv, y.target_unit tu
                              FROM kpi_as_indicator_year y JOIN kpi_as_indicator i ON i.indicator_id=y.indicator_id
                              WHERE y.year=? AND y.is_active=1 AND y.target_value IS NOT NULL ORDER BY i.item_no");
        $inds->execute([$year]);
        $okNames = []; $grp = [];
        foreach ($inds->fetchAll(PDO::FETCH_ASSOC) as $ind) {
            $mv = $db->prepare("SELECT month, COALESCE(override_value, manual_value, auto_value) v FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? ORDER BY month");
            $mv->execute([(int)$ind['indicator_id'], $year]);
            $rows = array_values(array_filter($mv->fetchAll(PDO::FETCH_ASSOC), function ($r) { return $r['v'] !== null; }));
            if (count($rows) < 2) continue;                       // 資料太少不下結論
            if (mb_strpos((string)$ind['name'], '教育訓練') !== false) continue;   // 不列新進人員／教育訓練類議題（使用者要求）
            if (mb_strpos((string)$ind['name'], '廠商準時') !== false && !rm_outsource_proc_nos($db)) continue;   // 稽核製程沒有外包時不列委外廠商指標
            $tv = (float)$ind['tv']; $gte = $ind['td'] === 'gte'; $unit = (string)$ind['tu'];
            $miss = array_values(array_filter($rows, function ($r) use ($tv, $gte) { return $gte ? (float)$r['v'] < $tv : (float)$r['v'] > $tv; }));
            $dirTxt = ($gte ? '≥' : '≤') . rm_sg_f($tv, 2) . $unit;
            $span = (int)$rows[0]['month'] . '～' . (int)$rows[count($rows) - 1]['month'] . ' 月';
            if (!$miss) { $okNames[] = $ind['name']; continue; }
            [$key, $sh, $needs] = rm_sg_kpi_class((string)$ind['name']);
            $vals = array_map(function ($r) use ($unit) { return (int)$r['month'] . ' 月 ' . rm_sg_f($r['v']) . $unit; }, array_slice($miss, -4));
            $last = $rows[count($rows) - 1]; $lastOk = $gte ? (float)$last['v'] >= $tv : (float)$last['v'] <= $tv;
            // 只寫事實與月份：不用「最近」這種沒有範圍的字眼，也不另外列「逐月變差」
            $issue = $ind['name'] . '未達年度目標' . ($lastOk ? '' : '，截至 ' . (int)$last['month'] . ' 月仍未達標');

            // 同一類需求的 KPI（例：受訂金額與銷貨額達成率都是「穩定的訂單與營收」）合併成一個議題，
            // 不然會長出兩列措施與成效幾乎一模一樣的風險
            $g = $key . '|' . $sh . '|' . $needs;
            if (!isset($grp[$g])) $grp[$g] = ['key'=>$key, 'sh'=>$sh, 'needs'=>$needs, 'issues'=>[], 'whys'=>[]];
            $grp[$g]['issues'][] = $issue;
            $grp[$g]['whys'][] = $ind['name'] . ' ' . $year . ' 年 ' . count($miss) . ' 個月未達';
        }
        foreach ($grp as $g) {
            $add('KPI 未達標（' . $year . '）', $g['key'], $g['sh'], $g['needs'], implode('；', $g['issues']),
                 '規則：該年度有月份未達 KPI 年度目標（' . implode('、', $g['whys']) . '），列為議題；請確認原因後再決定放在哪個象限');
        }
        if (count($okNames) >= 2)
            $add('KPI 達標（' . $year . '）', 'S', '客戶', '品質穩定、量測可追溯、人員具備能力',
                 '關鍵績效指標持續達標（如 ' . implode('、', array_slice($okNames, 0, 3)) . '），品質與交付穩定', '規則：KPI 全部月份達標，可列為優勢（' . $year . ' 年共 ' . count($okNames) . ' 項）');
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 2. 客戶集中度與新客戶（出貨紀錄） ---- */
    try {
        $tot = (float)$db->query("SELECT SUM(Qty*Unit_price) FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Order_date BETWEEN '$y1' AND '$y2'")->fetchColumn();
        if ($tot > 0) {
            $top = $db->query("SELECT Client_name, SUM(Qty*Unit_price) a FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Order_date BETWEEN '$y1' AND '$y2' AND Client_name<>'' GROUP BY Client_name ORDER BY a DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            $t3 = array_sum(array_column($top, 'a')); $share = $t3 * 100 / $tot;
            if ($share >= RM_SG_CONC_PCT) {
                $names = array_column($top, 'Client_name');
                $prevTxt = '';
                $pt = (float)$db->query("SELECT SUM(Qty*Unit_price) FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Order_date BETWEEN '" . ($year - 1) . "-01-01' AND '" . ($year - 1) . "-12-31'")->fetchColumn();
                if ($pt > 0) {
                    $pt3 = (float)$db->query("SELECT SUM(a) FROM (SELECT SUM(Qty*Unit_price) a FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Order_date BETWEEN '" . ($year - 1) . "-01-01' AND '" . ($year - 1) . "-12-31' AND Client_name<>'' GROUP BY Client_name ORDER BY a DESC LIMIT 3) t")->fetchColumn();
                    $prevTxt = '（前一年為 ' . rm_sg_pct($pt3, $pt) . '）';
                }
                $add('出貨紀錄（' . $year . '）', 'T', '客戶', '穩定供貨與交期',
                     '前三大客戶占出貨比例偏高，客戶集中度高，單一客戶變動會直接影響營收與產能',

                     '規則：前三大客戶占比 ≥ ' . RM_SG_CONC_PCT . '%（' . $year . ' 年前三大客戶合計占出貨 ' . rm_sg_pct($t3, $tot) . $prevTxt . '）');
            }
        }
        $firsts = $db->query("SELECT Client_name, MIN(Order_date) fd, SUM(Qty*Unit_price) a FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Client_name<>'' GROUP BY Client_name HAVING MIN(Order_date) BETWEEN '$y1' AND '$y2' ORDER BY a DESC")->fetchAll(PDO::FETCH_ASSOC);
        if ($firsts) {
            // 不顯示金額，改顯示「這些新客戶當年出貨占稽核製程出貨的比例」（只計稽核製程料號、當年度）
            $ncTxt = '';
            if ($tot > 0) {
                $in = implode(',', array_map(function ($n) use ($db) { return $db->quote((string)$n); }, array_column($firsts, 'Client_name')));
                $ya = (float)$db->query("SELECT SUM(Qty*Unit_price) FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Order_date BETWEEN '$y1' AND '$y2' AND Client_name IN ($in)")->fetchColumn();
                $ncTxt = '，占稽核製程出貨 ' . rm_sg_pct($ya, $tot);
            }
            $add('出貨紀錄（' . $year . '）', 'O', '客戶', '穩定的新供應商、短交期與合理報價',
                 '新客戶陸續首次出貨，需確認產能、交期與品質能穩定承接，並於首次出貨後持續溝通',

                 '規則：當年有首次出貨的新客戶（' . $year . ' 年有 ' . count($firsts) . ' 家客戶首次出貨' . $ncTxt . '）');
        }
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 4. 氣候（颱風假，行事曆） ---- */
    try {
        $st = $db->prepare("SELECT DATE(start) d FROM evenement WHERE title LIKE '%颱風%' AND start BETWEEN ? AND ? ORDER BY start");
        $st->execute([($year - 1) . '-01-01', $y2 . ' 23:59:59']);
        $ty = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($ty) {
            $cur = array_filter($ty, function ($d) use ($year) { return (int)substr($d, 0, 4) === $year; });
            $prev = array_filter($ty, function ($d) use ($year) { return (int)substr($d, 0, 4) === $year - 1; });
            $fmt = function ($a) { return implode('、', array_map(function ($d) { return substr($d, 5, 2) . '/' . substr($d, 8, 2); }, $a)); };
            $add('行事曆（' . $year . '）', 'T', '員工', '颱風豪雨期間的安全與正常出貨',
                 '颱風與豪雨可能造成停工、停電或委外廠停工，使在途品項延遲、交期延誤並可能損壞庫存',
                 '規則：行事曆在當年或前一年有颱風假（' . ($cur ? $year . ' 年 ' . count($cur) . ' 天：' . $fmt($cur) : $year . ' 年尚無') . '）');
        }
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 5. 供應鏈（委外） ---- */
    try {
        // 只看「稽核製程」（訂單追蹤設定的認證範圍，如齒研、插齒）；倒圓角、熱處理等不在認證範圍內的不納入
        require_once __DIR__ . '/order_as_tag_lib.php';
        $inAllAudit = implode(',', array_map('intval', array_keys(ot_astag_audit_process_nos($db, false, true)))) ?: '0';
        $outNos = rm_outsource_proc_nos($db);       // 只看「對外宣稱有外包」的稽核製程；沒設定就不產生任何委外建議
        $inAudit = $outNos ? implode(',', array_map('intval', array_keys($outNos))) : '0';
        $bnd = rm_bound_makers_sql($db, array_keys($outNos));     // 只計「加工項目」綁定到這些製程的廠商
        $st = $db->prepare("SELECT bi.maker_id_no, COUNT(*) n FROM bom_ing bi JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no AND COALESCE(ml.internal,0)=0 AND ml.maker_id_no IN (" . $bnd . ") WHERE bi.maker_id_no<>'' AND bi.process_no IN ($inAudit) AND bi.outsource_date BETWEEN ? AND ? GROUP BY bi.maker_id_no ORDER BY n DESC");
        $st->execute([$y1, $y2 . ' 23:59:59']);
        $mk = $st->fetchAll(PDO::FETCH_ASSOC); $all = array_sum(array_column($mk, 'n'));
        $nm = function ($id) use ($db) { $q = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no=?"); $q->execute([$id]); return (string)$q->fetchColumn() ?: $id; };
        if ($mk && $all >= 50 && $mk[0]['n'] * 100 / $all >= RM_SG_VENDOR_PCT) {
            $n0 = $nm($mk[0]['maker_id_no']);
            $add('委外發包（' . $year . '）', 'T', '供應商', '合理的交期與訂單預告',
                 '委外加工集中在單一廠商（' . $n0 . '），對單一廠商依賴高，交期或品質出狀況時缺乏替代來源',
                 '規則：稽核製程中單一廠商占委外筆數 ≥ ' . RM_SG_VENDOR_PCT . '%（' . $year . ' 年委外發包 ' . $all . ' 筆中，' . $n0 . ' 占 ' . rm_sg_pct($mk[0]['n'], $all) . '）');
        }
        // 單一來源製程：當年實際委外紀錄裡，某製程只交給 1 家廠商做、且發包筆數夠多（≥ RM_SG_SINGLE_MIN 筆）
        $st = $db->prepare("SELECT b.process_no, COUNT(*) n, MIN(b.maker_id_no) mid FROM bom_ing b
                            JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no AND COALESCE(ml.internal,0)=0 AND ml.maker_id_no IN (" . $bnd . ")
                            WHERE b.maker_id_no<>'' AND b.process_no IN ($inAudit) AND b.outsource_date BETWEEN ? AND ?
                            GROUP BY b.process_no HAVING COUNT(DISTINCT b.maker_id_no)=1 AND COUNT(*)>=" . RM_SG_SINGLE_MIN . " ORDER BY n DESC LIMIT 4");
        $st->execute([$y1, $y2 . ' 23:59:59']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $sg) {
            $pn = $db->prepare("SELECT ProcessName FROM process_no WHERE ProcessNo=?"); $pn->execute([$sg['process_no']]);
            $pname = (string)$pn->fetchColumn() ?: ('製程 ' . $sg['process_no']);
            $mname = $nm($sg['mid']);
            $add('委外發包（' . $year . '）', 'T', '供應商', '穩定的委外加工來源',
                 '「' . $pname . '」製程委外只交給 ' . $mname . ' 一家，沒有備援廠商',
                 '規則：當年某稽核製程只有 1 家委外廠商且發包 ≥ ' . RM_SG_SINGLE_MIN . ' 筆（' . $year . ' 年委外 ' . $sg['n'] . ' 筆）');
        }
        // 稽核製程由「廠內製程」廠商（maker_list.internal=1）承擔的比例：集中度高代表產能／設備／人力集中，屬內部劣勢，不是委外風險
        $st = $db->prepare("SELECT SUM(COALESCE(ml.internal,0)=1) a, COUNT(*) n FROM bom_ing b JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no
                            WHERE b.maker_id_no<>'' AND b.process_no IN ($inAllAudit) AND b.outsource_date BETWEEN ? AND ?");
        $st->execute([$y1, $y2 . ' 23:59:59']); $ih = $st->fetch(PDO::FETCH_ASSOC);
        if (!$outNos && $ih && (int)$ih['a'] >= RM_SG_SINGLE_MIN) {
            // 模組設定沒有勾選任何「對外宣稱有外包」的稽核製程＝宣告全部廠內加工：
            // 不可再顯示「廠內占 97%」這種暗示有外包的比例（會與設定矛盾），只陳述廠內承擔的筆數
            $add('BOM 製程紀錄（' . $year . '）', 'W', '客戶', '穩定交期與加工品質',
                 '齒研、插齒加工由廠內製程承擔，產能、設備與人力集中，設備故障或人員異動會直接影響交期',
                 '規則：未宣稱外包的稽核製程，廠內製程承擔筆數 ≥ ' . RM_SG_SINGLE_MIN . ' 筆（' . $year . ' 年 ' . (int)$ih['a'] . ' 筆）');
        } elseif ($outNos && $ih && (int)$ih['n'] >= RM_SG_SINGLE_MIN && (int)$ih['a'] * 100 / (int)$ih['n'] >= 80) {
            $add('BOM 製程紀錄（' . $year . '）', 'W', '客戶', '穩定交期與加工品質',
                 '齒研、插齒加工大多由廠內製程承擔，產能、設備與人力集中，設備故障或人員異動會直接影響交期',
                 '規則：稽核製程由廠內製程承擔的比例 ≥ 80%（至少 ' . RM_SG_SINGLE_MIN . ' 筆；' . $year . ' 年 ' . $ih['n'] . ' 筆中廠內承擔 ' . rm_sg_pct($ih['a'], $ih['n']) . '）');
        }
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 6. 品質異常、客戶退貨 ---- */
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM qa_abnormal_order WHERE " . rm_audit_in($db,'part_d_id') . " AND deleted_at IS NULL AND YEAR(created_at)=?");
        $st->execute([$year]); $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $st = $db->prepare("SELECT c.name, COUNT(*) n FROM qa_abnormal_cause ac JOIN qa_abnormal_order o ON o.id=ac.order_id AND " . rm_audit_in($db,'o.part_d_id') . " AND o.deleted_at IS NULL AND YEAR(o.created_at)=?
                                JOIN qa_cause_cat c ON c.cat_id=ac.cat_id GROUP BY c.cat_id, c.name ORDER BY n DESC LIMIT 3");
            $st->execute([$year]);
            $cs = array_map(function ($r) { return $r['name'] . ' ' . $r['n'] . ' 次'; }, $st->fetchAll(PDO::FETCH_ASSOC));
            $add('品質異常處理單（' . $year . '）', 'W', '客戶', '品質穩定、不良品不外流',
                 '品質異常持續發生，重複出現的原因分類是內部需改善的項目',
                 '規則：當年有品質異常處理單，重複出現的原因分類通常是內部需改善的項目（' . $year . ' 年共 ' . $n . ' 張）');
        }
        $st = $db->prepare("SELECT COUNT(*) n, COUNT(DISTINCT Client_name) c FROM ir_track WHERE " . rm_audit_in($db,'d_setting_id') . " AND YEAR(IR_date)=?");
        $st->execute([$year]); $ir = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$ir['n'] > 0)
            $add('客戶退貨（' . $year . '）', 'W', '客戶', '品質穩定、不良品不外流',
                 '發生客戶退貨，產品品質或製程仍有改善空間', '規則：當年有客戶退貨（' . $year . ' 年 ' . $ir['n'] . ' 筆，涉及 ' . $ir['c'] . ' 家客戶）');
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 7. 法規遵循（溝通管理裡「政府單位」類別的溝通記錄） ---- */
    try {
        $st = $db->prepare("SELECT c.name cat, COUNT(*) n, GROUP_CONCAT(r.rec_no ORDER BY r.comm_date DESC SEPARATOR '、') nos
                            FROM comm_record r JOIN comm_track_cat c ON c.cat_id=r.party_cat_id AND c.kind='government'
                            WHERE r.is_deleted=0 AND YEAR(r.comm_date)=? GROUP BY c.cat_id, c.name ORDER BY c.sort_order");
        $st->execute([$year]);
        $gov = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($gov)
            $add('溝通管理・政府單位（' . $year . '）', 'T', '政府單位（' . implode('、', array_column($gov, 'cat')) . '）', '依法申報、保存紀錄',
                 '與政府單位的法定申報與檢修有期限，未依規定辦理會受處分',

                 '規則：當年在溝通管理有「政府單位」類別的溝通記錄（' . $year . ' 年：' . implode('；', array_map(function ($g) { return $g['cat'] . ' ' . $g['n'] . ' 次'; }, $gov)) . '）');
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 8. 措施追蹤逾期（溝通管理） ---- */
    try {
        $st = $db->prepare("SELECT COUNT(*) n, MIN(due_date) d FROM comm_track WHERE is_deleted=0 AND is_closed=0 AND due_date IS NOT NULL AND due_date<?");
        $st->execute([$today]); $o = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$o['n'] > 0 && $year >= (int)substr($today, 0, 4) - 0)
            $add('溝通管理・措施追蹤', 'W', '客戶／供應商／員工', '承諾事項如期完成',
                 '措施追蹤有逾期未結案的項目，承諾事項未如期完成會影響利害關係者信任',
                 '規則：措施追蹤有逾期未結案的項目（只在查看當年度時提示；目前 ' . $o['n'] . ' 項）');
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 9. 量具逾期校驗（現況，只在查看當年度時提示） ---- */
    try {
        if ($year === (int)substr($today, 0, 4)) {
            $n = (int)$db->query("SELECT COUNT(*) FROM qc_tool WHERE calib_managed=1 AND calibration_due IS NOT NULL AND calibration_due<CURDATE() AND (state IS NULL OR state=0)")->fetchColumn();
            if ($n > 0)
                $add('量測儀器校驗', 'W', '客戶', '量測數據可靠、可追溯', '列管量具有已超過校驗到期日未校驗者，量測數據的可靠度與可追溯性有疑慮', '規則：列管量具校驗到期日已過（目前 ' . $n . ' 支）');
        }
    } catch (Throwable $e) { rm_sg_err($e); }

    return $out;
}
