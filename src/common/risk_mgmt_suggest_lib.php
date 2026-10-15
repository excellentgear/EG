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
 *   近兩年新進人員占在職 ≥ RM_SG_NEWHIRE_PCT% → W
 *   當年（及前一年）有颱風假 → T
 *   當年某製程的委外只有 1 家廠商（≥ RM_SG_SINGLE_MIN 筆）／某廠商占委外筆數 ≥ RM_SG_VENDOR_PCT% → T
 *   當年品質異常處理單 ≥ 1 張 → W
 *   當年與政府單位（環保局、消防局…類別 kind=government）有溝通記錄 → T（法規遵循）
 *   措施追蹤逾期未結案 → W
 */
require_once __DIR__ . '/risk_mgmt_lib.php';

const RM_SG_CONC_PCT    = 30;   // 前三大客戶占比門檻（%）
const RM_SG_NEWHIRE_PCT = 20;   // 近兩年新進人員占比門檻（%）
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
    if (mb_strpos($name, '教育訓練') !== false) return ['W', '員工', '完整的訓練與經驗傳承'];
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
        $okNames = [];
        foreach ($inds->fetchAll(PDO::FETCH_ASSOC) as $ind) {
            $mv = $db->prepare("SELECT month, COALESCE(override_value, manual_value, auto_value) v FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? ORDER BY month");
            $mv->execute([(int)$ind['indicator_id'], $year]);
            $rows = array_values(array_filter($mv->fetchAll(PDO::FETCH_ASSOC), function ($r) { return $r['v'] !== null; }));
            if (count($rows) < 2) continue;                       // 資料太少不下結論
            $tv = (float)$ind['tv']; $gte = $ind['td'] === 'gte'; $unit = (string)$ind['tu'];
            $miss = array_values(array_filter($rows, function ($r) use ($tv, $gte) { return $gte ? (float)$r['v'] < $tv : (float)$r['v'] > $tv; }));
            $dirTxt = ($gte ? '≥' : '≤') . rm_sg_f($tv, 2) . $unit;
            $span = (int)$rows[0]['month'] . '～' . (int)$rows[count($rows) - 1]['month'] . ' 月';
            if (!$miss) { $okNames[] = $ind['name']; continue; }
            [$key, $sh, $needs] = rm_sg_kpi_class((string)$ind['name']);
            $vals = array_map(function ($r) use ($unit) { return (int)$r['month'] . ' 月 ' . rm_sg_f($r['v']) . $unit; }, array_slice($miss, -4));
            $last = $rows[count($rows) - 1]; $lastOk = $gte ? (float)$last['v'] >= $tv : (float)$last['v'] <= $tv;
            $trend = '';
            if (count($rows) >= 3) {
                $a = (float)$rows[count($rows) - 3]['v']; $b = (float)$rows[count($rows) - 2]['v']; $c = (float)$last['v'];
                if (($gte && $a > $b && $b > $c) || (!$gte && $a < $b && $b < $c)) $trend = '，且最近三個月逐月變差';
            }
            $issue = $ind['name'] . ' ' . $year . ' 年 ' . $span . '共有 ' . count($miss) . ' 個月未達目標（' . $dirTxt . '）：' . implode('、', $vals)
                   . ($lastOk ? '' : '；最近一個月（' . (int)$last['month'] . ' 月 ' . rm_sg_f($last['v']) . $unit . '）仍未達標') . $trend . '。佐證：KPI 年度總表';
            $add('KPI 未達標（' . $year . '）', $key, $sh, $needs, $issue, '規則：該年度有月份未達 KPI 年度目標，列為議題；請確認原因後再決定放在哪個象限');
        }
        if (count($okNames) >= 2)
            $add('KPI 達標（' . $year . '）', 'S', '客戶', '品質穩定、量測可追溯、人員具備能力',
                 $year . ' 年下列 KPI 已有資料的月份全部達到年度目標：' . implode('、', array_slice($okNames, 0, 8)) . '。佐證：KPI 年度總表', '規則：KPI 全部月份達標，可列為優勢');
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 2. 客戶集中度與新客戶（出貨紀錄） ---- */
    try {
        $tot = (float)$db->query("SELECT SUM(Qty*Unit_price) FROM is_list WHERE Order_date BETWEEN '$y1' AND '$y2'")->fetchColumn();
        if ($tot > 0) {
            $top = $db->query("SELECT Client_name, SUM(Qty*Unit_price) a FROM is_list WHERE Order_date BETWEEN '$y1' AND '$y2' AND Client_name<>'' GROUP BY Client_name ORDER BY a DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            $t3 = array_sum(array_column($top, 'a')); $share = $t3 * 100 / $tot;
            if ($share >= RM_SG_CONC_PCT) {
                $names = array_column($top, 'Client_name');
                $prevTxt = '';
                $pt = (float)$db->query("SELECT SUM(Qty*Unit_price) FROM is_list WHERE Order_date BETWEEN '" . ($year - 1) . "-01-01' AND '" . ($year - 1) . "-12-31'")->fetchColumn();
                if ($pt > 0) {
                    $pt3 = (float)$db->query("SELECT SUM(a) FROM (SELECT SUM(Qty*Unit_price) a FROM is_list WHERE Order_date BETWEEN '" . ($year - 1) . "-01-01' AND '" . ($year - 1) . "-12-31' AND Client_name<>'' GROUP BY Client_name ORDER BY a DESC LIMIT 3) t")->fetchColumn();
                    $prevTxt = '（前一年為 ' . rm_sg_pct($pt3, $pt) . '）';
                }
                $add('出貨紀錄（' . $year . '）', 'T', '客戶', '穩定供貨與交期',
                     $year . ' 年出貨前三大客戶（' . implode('、', array_map(function ($r) use ($tot) { return $r['Client_name'] . ' ' . rm_sg_pct($r['a'], $tot); }, $top)) . '）合計占出貨 ' . rm_sg_pct($t3, $tot) . $prevTxt
                     . '，客戶集中度偏高。佐證：出貨紀錄' . rm_sg_recs($db, $year, $names),
                     '規則：前三大客戶占比 ≥ ' . RM_SG_CONC_PCT . '%');
            }
        }
        $firsts = $db->query("SELECT Client_name, MIN(Order_date) fd, SUM(Qty*Unit_price) a FROM is_list WHERE Client_name<>'' GROUP BY Client_name HAVING MIN(Order_date) BETWEEN '$y1' AND '$y2' ORDER BY a DESC")->fetchAll(PDO::FETCH_ASSOC);
        if ($firsts) {
            $names = array_slice(array_column($firsts, 'Client_name'), 0, 3);
            $add('出貨紀錄（' . $year . '）', 'O', '客戶', '穩定的新供應商、短交期與合理報價',
                 $year . ' 年有 ' . count($firsts) . ' 家客戶首次出貨，累計 ' . number_format(array_sum(array_column($firsts, 'a'))) . ' 元，金額最大者為 ' . $firsts[0]['Client_name']
                 . '（首次 ' . substr($firsts[0]['fd'], 0, 10) . '，' . number_format($firsts[0]['a']) . ' 元）。佐證：出貨紀錄' . rm_sg_recs($db, $year, $names),
                 '規則：當年有首次出貨的新客戶');
        }
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 3. 人員 ---- */
    try {
        $st = $db->prepare("SELECT COUNT(*) n, SUM(hire_date>=DATE_SUB(?, INTERVAL 2 YEAR)) nw, MIN(hire_date) mh FROM user WHERE state=1 AND hire_date IS NOT NULL AND hire_date<=?");
        $st->execute([$y2, $y2]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$s['n'] > 0 && (int)$s['nw'] * 100 / (int)$s['n'] >= RM_SG_NEWHIRE_PCT)
            $add('人員資料（' . $year . '）', 'W', '員工', '標準作業書、固定師傅輔導、完整訓練',
                 '在職 ' . $s['n'] . ' 人中近兩年新進 ' . $s['nw'] . ' 人（' . rm_sg_pct($s['nw'], $s['n']) . '），最資深在職者自 ' . $s['mh'] . ' 到職，經驗傳承與標準作業書需要補強（依目前在職資料推算）。佐證：人員資料'
                 . rm_sg_recs($db, $year, [], 'employee'),
                 '規則：近兩年新進人員占比 ≥ ' . RM_SG_NEWHIRE_PCT . '%');
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
            $add('行事曆（' . $year . '）', 'T', '員工／供應商', '颱風豪雨期間的安全與正常出貨',
                 ($cur ? $year . ' 年已有 ' . count($cur) . ' 天颱風假（' . $fmt($cur) . '）' : $year . ' 年尚無颱風假') . ($prev ? '；前一年 ' . count($prev) . ' 天（' . $fmt($prev) . '）' : '')
                 . '。極端氣候會使本公司與委外廠停工、在途品項延遲回廠。佐證：行事曆颱風假',
                 '規則：行事曆在當年或前一年有颱風假');
        }
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 5. 供應鏈（委外） ---- */
    try {
        // 只看「稽核製程」（訂單追蹤設定的認證範圍，如齒研、插齒）；倒圓角、熱處理等不在認證範圍內的不納入
        require_once __DIR__ . '/order_as_tag_lib.php';
        $auditNos = array_map('intval', array_keys(ot_astag_audit_process_nos($db)));
        $inAudit = $auditNos ? implode(',', $auditNos) : '0';
        $st = $db->prepare("SELECT bi.maker_id_no, COUNT(*) n FROM bom_ing bi JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no AND COALESCE(ml.internal,0)=0 WHERE bi.maker_id_no<>'' AND bi.process_no IN ($inAudit) AND bi.outsource_date BETWEEN ? AND ? GROUP BY bi.maker_id_no ORDER BY n DESC");
        $st->execute([$y1, $y2 . ' 23:59:59']);
        $mk = $st->fetchAll(PDO::FETCH_ASSOC); $all = array_sum(array_column($mk, 'n'));
        $nm = function ($id) use ($db) { $q = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no=?"); $q->execute([$id]); return (string)$q->fetchColumn() ?: $id; };
        if ($mk && $all >= 50 && $mk[0]['n'] * 100 / $all >= RM_SG_VENDOR_PCT) {
            $n0 = $nm($mk[0]['maker_id_no']);
            $add('委外發包（' . $year . '）', 'T', '供應商', '合理的交期與訂單預告',
                 $year . ' 年委外發包 ' . $all . ' 筆中，' . $n0 . ' 占 ' . $mk[0]['n'] . ' 筆（' . rm_sg_pct($mk[0]['n'], $all) . '），對單一廠商依賴高（僅計稽核製程的外部委外廠商，主檔設為「廠內製程」者不算委外）。佐證：BOM 製程委外紀錄' . rm_sg_recs($db, $year, [$n0]),
                 '規則：稽核製程中單一廠商占委外筆數 ≥ ' . RM_SG_VENDOR_PCT . '%');
        }
        // 單一來源製程：當年實際委外紀錄裡，某製程只交給 1 家廠商做、且發包筆數夠多（≥ RM_SG_SINGLE_MIN 筆）
        $st = $db->prepare("SELECT b.process_no, COUNT(*) n, MIN(b.maker_id_no) mid FROM bom_ing b
                            JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no AND COALESCE(ml.internal,0)=0
                            WHERE b.maker_id_no<>'' AND b.process_no IN ($inAudit) AND b.outsource_date BETWEEN ? AND ?
                            GROUP BY b.process_no HAVING COUNT(DISTINCT b.maker_id_no)=1 AND COUNT(*)>=" . RM_SG_SINGLE_MIN . " ORDER BY n DESC LIMIT 4");
        $st->execute([$y1, $y2 . ' 23:59:59']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $sg) {
            $pn = $db->prepare("SELECT ProcessName FROM process_no WHERE ProcessNo=?"); $pn->execute([$sg['process_no']]);
            $pname = (string)$pn->fetchColumn() ?: ('製程 ' . $sg['process_no']);
            $mname = $nm($sg['mid']);
            $add('委外發包（' . $year . '）', 'T', '供應商', '穩定的委外加工來源',
                 $year . ' 年「' . $pname . '」製程委外 ' . $sg['n'] . ' 筆全部只交給 ' . $mname . ' 一家，沒有備援廠商，單一來源風險高。佐證：BOM 製程委外紀錄' . rm_sg_recs($db, $year, [$mname]),
                 '規則：當年某稽核製程只有 1 家委外廠商且發包 ≥ ' . RM_SG_SINGLE_MIN . ' 筆');
        }
        // 稽核製程由「廠內製程」廠商（maker_list.internal=1）承擔的比例：集中度高代表產能／設備／人力集中，屬內部劣勢，不是委外風險
        $st = $db->prepare("SELECT SUM(COALESCE(ml.internal,0)=1) a, COUNT(*) n FROM bom_ing b JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no
                            WHERE b.maker_id_no<>'' AND b.process_no IN ($inAudit) AND b.outsource_date BETWEEN ? AND ?");
        $st->execute([$y1, $y2 . ' 23:59:59']); $ih = $st->fetch(PDO::FETCH_ASSOC);
        if ($ih && (int)$ih['n'] >= RM_SG_SINGLE_MIN && (int)$ih['a'] * 100 / (int)$ih['n'] >= 80) {
            $add('BOM 製程紀錄（' . $year . '）', 'W', '客戶', '穩定交期與加工品質',
                 $year . ' 年稽核製程（齒研、插齒）加工 ' . $ih['n'] . ' 筆中，由廠內製程承擔 ' . $ih['a'] . ' 筆（' . rm_sg_pct($ih['a'], $ih['n']) . '），產能、設備與人力集中在廠內，設備故障或人員異動會直接影響交期。佐證：BOM 製程紀錄、機台設備一覽表',
                 '規則：稽核製程由廠內製程承擔的比例 ≥ 80%（至少 ' . RM_SG_SINGLE_MIN . ' 筆）');
        }
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 6. 品質異常、客戶退貨 ---- */
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM qa_abnormal_order WHERE deleted_at IS NULL AND YEAR(created_at)=?");
        $st->execute([$year]); $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $st = $db->prepare("SELECT c.name, COUNT(*) n FROM qa_abnormal_cause ac JOIN qa_abnormal_order o ON o.id=ac.order_id AND o.deleted_at IS NULL AND YEAR(o.created_at)=?
                                JOIN qa_cause_cat c ON c.cat_id=ac.cat_id GROUP BY c.cat_id, c.name ORDER BY n DESC LIMIT 3");
            $st->execute([$year]);
            $cs = array_map(function ($r) { return $r['name'] . ' ' . $r['n'] . ' 次'; }, $st->fetchAll(PDO::FETCH_ASSOC));
            $add('品質異常處理單（' . $year . '）', 'W', '客戶', '品質穩定、不良品不外流',
                 $year . ' 年品質異常處理單共 ' . $n . ' 張' . ($cs ? '，原因分類最多為：' . implode('、', $cs) : '') . '。佐證：品質異常處理單',
                 '規則：當年有品質異常處理單，重複出現的原因分類通常是內部需改善的項目');
        }
        $st = $db->prepare("SELECT COUNT(*) n, COUNT(DISTINCT Client_name) c FROM ir_track WHERE YEAR(IR_date)=?");
        $st->execute([$year]); $ir = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$ir['n'] > 0)
            $add('客戶退貨（' . $year . '）', 'W', '客戶', '品質穩定、不良品不外流',
                 $year . ' 年客戶退貨單共 ' . $ir['n'] . ' 筆，涉及 ' . $ir['c'] . ' 家客戶。佐證：客戶退貨紀錄', '規則：當年有客戶退貨');
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
                 $year . ' 年與政府單位的溝通：' . implode('；', array_map(function ($g) { return $g['cat'] . ' ' . $g['n'] . ' 次'; }, $gov))
                 . '。法定申報與檢修有期限，未依規定辦理會受處分並影響營運。佐證：溝通記錄 ' . implode('、', array_map(function ($g) { return $g['nos']; }, $gov)),
                 '規則：當年在溝通管理有「政府單位」類別的溝通記錄');
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 8. 措施追蹤逾期（溝通管理） ---- */
    try {
        $st = $db->prepare("SELECT COUNT(*) n, MIN(due_date) d FROM comm_track WHERE is_deleted=0 AND is_closed=0 AND due_date IS NOT NULL AND due_date<?");
        $st->execute([$today]); $o = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$o['n'] > 0 && $year >= (int)substr($today, 0, 4) - 0)
            $add('溝通管理・措施追蹤', 'W', '客戶／供應商／員工', '承諾事項如期完成',
                 '措施追蹤表目前有 ' . $o['n'] . ' 項已逾期未結案（最早預計完成日 ' . $o['d'] . '），承諾事項未如期完成會影響利害關係者信任。佐證：措施追蹤表',
                 '規則：措施追蹤有逾期未結案的項目（只在查看當年度時提示）');
    } catch (Throwable $e) { rm_sg_err($e); }

    /* ---- 9. 量具逾期校驗（現況，只在查看當年度時提示） ---- */
    try {
        if ($year === (int)substr($today, 0, 4)) {
            $n = (int)$db->query("SELECT COUNT(*) FROM qc_tool WHERE calib_managed=1 AND calibration_due IS NOT NULL AND calibration_due<CURDATE() AND (state IS NULL OR state=0)")->fetchColumn();
            if ($n > 0)
                $add('量測儀器校驗', 'W', '客戶', '量測數據可靠、可追溯', '目前有 ' . $n . ' 支列管量具已超過校驗到期日未校驗。佐證：量測儀器校驗管理', '規則：列管量具校驗到期日已過');
        }
    } catch (Throwable $e) { rm_sg_err($e); }

    return $out;
}
