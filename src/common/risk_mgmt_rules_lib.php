<?php
/**
 * 風險與機會管理：規則引擎 —— 2026-10-14 建立
 * ------------------------------------------------------------------
 * 不依賴外部 AI。所有判斷都是「規則」：看得到、可追溯、結果可重現。規則分五類（編號可在畫面「判斷規則」查看）：
 *   C 分類規則    組織處境的議題 → 屬於哪一種風險／機會（交期、供應鏈、品質、人員…）
 *   S 嚴重度規則  這一類議題發生時對公司的衝擊大小（依類別與實際資料，換算到「模組設定」目前的嚴重度(S)等級數）
 *   O 發生率規則  依實際資料（KPI 未達標月份比例、占比、颱風假天數、逾期追蹤…）換算到目前的發生率(O)等級數
 *   M 措施規則    每一類的預設因應措施與「成效確認方式」（要看哪個 KPI、達到什麼目標）
 *   E 成效規則    成效確認：蒐集佐證（KPI 逐月數字、相關溝通記錄與措施追蹤的結案／逾期狀況、占比變化）並建議結果
 * 為了不被「等級數量」綁死：嚴重度與發生率都先算成 0～1 的比例，再依設定目前有幾級換算成等級（設定改了級數，規則照樣適用）。
 *
 * 流程（使用者的作業順序）：
 *   年初  填「組織處境分析表」（可用「依系統資料建議」，年初用前一年的全年資料）
 *   年初  按「由處境分析表產生」→ 本檔的規則替每個議題產生風險／機會草稿（影響說明、嚴重度(S)、發生率(O)、因應措施、成效確認方式）
 *   年中  措施靠溝通管理的溝通記錄與措施追蹤落實
 *   年底／定期  「成效確認」：按「依資料產生佐證」→ 本檔蒐集佐證並建議結果，主管填裁示後存檔
 */
require_once __DIR__ . '/risk_mgmt_suggest_lib.php';

/** 成效確認結果（rm_item.effect_status） */
const RM_EFFECT_STATUS = ['unconfirmed' => '未確認', 'doing' => '進行中', 'effective' => '有效', 'partial' => '部分有效', 'ineffective' => '無效'];

/**
 * 類別定義。
 *  kw        分類用關鍵字（C 規則）          sev   嚴重度比例（S 規則）
 *  impact    對組織的影響說明範本            measures  預設因應措施（M 規則）
 *  metric    成效確認方式範本（{kpi} 會換成對應 KPI 與目標）
 *  kpi       成效確認對應的 KPI 名稱關鍵字   trk_kw  找相關措施追蹤用的關鍵字   trk_kind  相關溝通記錄的對象類型
 */
const RM_RULE_CATS = [
    'delivery' => ['label' => '交期／準時出貨', 'ro' => 'risk', 'sev' => 0.9,
        'kw' => ['準時出貨', '交期', '延後出貨', '出貨延', '交貨', '準時交貨', '遲交'],
        'impact' => '準時出貨率未達目標，客戶抱怨增加並可能失去訂單。',
        'measures' => ['生管組每週交期檢討與關鍵製程負荷預警，並每週回報客戶', '接單時確認關鍵製程產能，必要時調整交期或外包'],
        'metric' => '{kpi}回到年度目標並連續 3 個月達標',
        'kpi' => ['準時出貨'], 'trk_kw' => ['交期', '出貨', '延後', '延遲'], 'trk_kind' => 'customer', 'trk_not' => ['首次出貨', '年度報價', '客訴']],
    'supplier' => ['label' => '供應鏈／委外', 'ro' => 'risk', 'sev' => 0.8,
        'kw' => ['廠商準時', '委外', '供應商', '單一來源', '單一廠商', '備援', '缺料', '原料', '交期不穩'],
        'impact' => '委外或供料延遲造成製令停滯並連帶使客戶交期落後；單一來源會放大風險。',
        'measures' => ['與主要委外廠商建立每週交期與排程確認機制', '評估備援（第二家）廠商：初步篩選、供應商稽核、試做'],
        'metric' => '{kpi}回升並維持達標；備援廠商於年底前完成評估',
        'kpi' => ['廠商準時'], 'trk_kw' => ['委外', '齒研', '插齒', '排程', '備援', '廠商'], 'trk_kind' => 'supplier', 'trk_not' => ['颱風', '豪雨']],
    'quality' => ['label' => '品質／客訴', 'ro' => 'risk', 'sev' => 0.9,
        'kw' => ['客訴', '客戶退貨', '退貨', '品質異常', '不良', '矯正', '品質穩定'],
        'impact' => '客訴或不良持續發生，影響客戶信任與再下單意願，並增加重工、報廢與運費成本。',
        'measures' => ['客訴與不良逐件開立異常矯正處理單，每月品質檢討會檢討原因分類並回報客戶', '重複出現的原因建立預防措施並追蹤到結案'],
        'metric' => '{kpi}回到年度目標並連續 3 個月達標；重複原因不再發生',
        'kpi' => ['客訴', '不良率'], 'trk_kw' => ['客訴', '矯正', '不良', '預防措施'], 'trk_kind' => 'customer'],
    'people' => ['label' => '人員／能力傳承', 'ro' => 'risk', 'sev' => 0.4,
        'kw' => ['新進', '經驗', '傳承', '標準作業', '訓練', '輔導', '離職', '人力'],
        'impact' => '新進人員比例高、經驗傳承不足，可能增加不良與異常、拉長上手時間。',
        'measures' => ['補齊關鍵製程標準作業書', '指派資深同仁輔導新進同仁並留存輔導紀錄', '依職能規劃教育訓練'],
        'metric' => '標準作業書如期完成；輔導紀錄 100% 留存；{kpi}維持達標',
        'kpi' => ['教育訓練'], 'trk_kw' => ['標準作業', '輔導', '新進', '訓練'], 'trk_kind' => 'employee', 'by' => 'track'],
    'concentration' => ['label' => '客戶集中度', 'ro' => 'risk', 'sev' => 0.8,
        'kw' => ['集中度', '前三大客戶', '大客戶', '客戶集中'],
        'impact' => '大客戶訂單變動會直接衝擊產能與營收。',
        'measures' => ['與前三大客戶定期（每季）溝通訂單預測與產能預留', '持續開發新客戶以分散風險'],
        'metric' => '前三大客戶合計出貨占比逐年下降至 30% 以下；每季溝通紀錄完整',
        'kpi' => [], 'trk_kw' => ['預測', '產能預留'], 'trk_kind' => 'customer'],
    'new_customer' => ['label' => '新客戶／商機', 'ro' => 'opportunity', 'sev' => 0.4,
        'kw' => ['首次出貨', '新客戶', '新客', '商機', '開發客戶', '詢價'],
        'impact' => '新客戶持續開發，可分散對大客戶的依賴並提高產能利用率。',
        'measures' => ['新客戶首次出貨後 30 天內完成品質確認與年度報價', '每月檢討新客戶訂單與產能'],
        'metric' => '當年首次出貨客戶數與金額不低於前一年；年度報價回覆率 100%',
        'kpi' => ['接單率'], 'trk_kw' => ['首批', '報價', '新客戶', '年度需求'], 'trk_kind' => 'customer'],
    'climate' => ['label' => '氣候／天然災害', 'ro' => 'risk', 'sev' => 0.4,
        'kw' => ['颱風', '豪雨', '氣候', '淹水', '極端', '天災', '碳'],
        'impact' => '颱風豪雨造成停工與在途品項延遲，影響交期並可能損壞庫存。',
        'measures' => ['颱風警報發布當日由生管組通知客戶與委外廠在途品項預計回廠日', '每年汛期前檢查庫房與廠區防水'],
        'metric' => '颱風假期間無因未通知造成的交期客訴；汛期前完成廠區檢查',
        'kpi' => [], 'trk_kw' => ['颱風', '豪雨', '防水', '氣候'], 'trk_kind' => ''],
    'legal' => ['label' => '法規遵循', 'ro' => 'risk', 'sev' => 0.67,
        'kw' => ['法定', '申報', '法規', '環保', '消防', '職安', '勞工', '稽查', '政府'],
        'impact' => '未依法定期限申報或檢修會受處分，嚴重時影響營運與客戶稽核。',
        'measures' => ['管理課建立年度法定申報行事曆並指定負責人', '申報與檢修紀錄歸檔，列入溝通管制表每年提醒'],
        'metric' => '各項法定申報皆如期完成，逾期件數為 0；紀錄可即時調閱',
        'kpi' => [], 'trk_kw' => ['申報', '檢修', '健康檢查', '職安'], 'trk_kind' => 'government'],
    'measure' => ['label' => '量測／校驗', 'ro' => 'risk', 'sev' => 0.75,
        'kw' => ['量具', '校驗', '量測', '儀器'],
        'impact' => '量測失準或校驗逾期造成不良品流出並影響客戶信任。',
        'measures' => ['全面盤點量具並依類別重訂校驗週期', '校驗到期前 30 天提醒並追蹤完成'],
        'metric' => '{kpi}維持達標；逾期校驗件數為 0',
        'kpi' => ['校驗'], 'trk_kw' => ['量具', '校驗', '量測'], 'trk_kind' => ''],
    'sales' => ['label' => '業績／營收目標', 'ro' => 'risk', 'sev' => 0.75,
        'kw' => ['達成率', '受訂', '銷貨額', '營收', '接單', '訂單金額'],
        'impact' => '業績達成率落後，影響營收與產能規劃。',
        'measures' => ['業務課每月檢討達成率落後原因，並提出衝刺方案（客戶訂單預測、報價跟催）', '依訂單預測調整產能與交期承諾'],
        'metric' => '{kpi}回到年度目標並連續 3 個月達標',
        'kpi' => ['銷貨額達成', '受訂目標'], 'trk_kw' => ['預測', '報價', '衝刺'], 'trk_kind' => 'customer', 'trk_not' => ['延後', '客訴']],
    'generic' => ['label' => '一般議題', 'ro' => 'risk', 'sev' => 0.65,
        'kw' => [],
        'impact' => '此議題若未妥善因應，可能影響公司目標達成與利害關係者滿意度。',
        'measures' => ['指定負責人並訂定因應措施與完成期限，納入溝通管理措施追蹤'],
        'metric' => '依措施追蹤項目如期結案確認',
        'kpi' => [], 'trk_kw' => [], 'trk_kind' => ''],
];

/** 規則目錄（畫面「判斷規則」顯示用；規則編號也會寫進每一列的「依據規則」） */
function rm_rule_catalog(): array
{
    $rows = [];
    foreach (RM_RULE_CATS as $k => $c) {
        $K = strtoupper($k);
        if ($k !== 'generic') $rows[] = ['id'=>"C-$K", 'kind'=>'分類', 'cat'=>$c['label'], 'when'=>'議題文字（利害關係者＋需求＋描述）出現：' . implode('、', $c['kw']), 'then'=>'歸類為「' . $c['label'] . '」（' . ($c['ro'] === 'opportunity' ? '機會' : '風險') . '）；關鍵字命中最多的類別勝出，同分取清單中靠前者'];
        $rows[] = ['id'=>"S-$K", 'kind'=>'嚴重度(S)', 'cat'=>$c['label'], 'when'=>'此類議題的衝擊', 'then'=>'嚴重度比例 ' . $c['sev'] . '（再依「模組設定」目前的嚴重度(S)級數換算成等級）'];
    }
    $rows[] = ['id'=>'C-GENERIC', 'kind'=>'分類', 'cat'=>'一般議題', 'when'=>'沒有任何關鍵字命中', 'then'=>'歸類為「一般議題」，請人工確認'];
    $rows[] = ['id'=>'O-KPI', 'kind'=>'發生率(O)', 'cat'=>'有對應 KPI 的類別', 'when'=>'資料年度內 KPI 未達標月份占比 r', 'then'=>'r=0→0.34；r<30%→0.5；r<60%→0.67；其餘→1.0；最近一個月仍未達標時至少 0.67；資料不足 2 個月→0.5'];
    $rows[] = ['id'=>'O-SINGLE', 'kind'=>'發生率(O)', 'cat'=>'供應鏈／委外', 'when'=>'當年有稽核製程只交給 1 家外部委外廠商（≥20 筆；廠內製程不算）', 'then'=>'發生率比例至少 0.67'];
    $rows[] = ['id'=>'O-QA', 'kind'=>'發生率(O)', 'cat'=>'品質／客訴', 'when'=>'當年品質異常處理單張數', 'then'=>'≥6 張→1.0；≥3 張→0.67；≥1 張→0.5（與 KPI 規則取較高者）'];
    $rows[] = ['id'=>'O-RETURN', 'kind'=>'發生率(O)', 'cat'=>'品質／客訴', 'when'=>'當年客戶退貨筆數相對前一年', 'then'=>'增加 ≥30%→1.0；≥10%→0.67；有退貨但增幅較小→0.5；無退貨 0.34（與其他品質規則取最高者）'];
    $rows[] = ['id'=>'O-HIRE', 'kind'=>'發生率(O)', 'cat'=>'人員／能力傳承', 'when'=>'近兩年新進人員占在職比例', 'then'=>'≥30%→1.0；≥20%→0.67；≥10%→0.5；其餘 0.34（與教育訓練 KPI 規則取較高者）'];
    $rows[] = ['id'=>'O-CONC', 'kind'=>'發生率(O)', 'cat'=>'客戶集中度', 'when'=>'前三大客戶占當年出貨比例', 'then'=>'≥40%→0.67；≥30%→0.5；其餘 0.34；嚴重度比例同時依占比上調（≥40%→1.0；≥30%→0.8）'];
    $rows[] = ['id'=>'O-CLIMATE', 'kind'=>'發生率(O)', 'cat'=>'氣候／天然災害', 'when'=>'當年與前一年行事曆颱風假天數', 'then'=>'≥6 天→1.0；≥2 天→0.5；其餘 0.34'];
    $rows[] = ['id'=>'O-LEGAL', 'kind'=>'發生率(O)', 'cat'=>'法規遵循', 'when'=>'政府單位相關措施追蹤是否有逾期', 'then'=>'有逾期→0.67；否則 0.34'];
    $rows[] = ['id'=>'O-NEWCUST', 'kind'=>'發生率(O)', 'cat'=>'新客戶／商機', 'when'=>'當年首次出貨的客戶數', 'then'=>'≥20 家→1.0；≥5 家→0.67；≥1 家→0.5（機會：數字愈大代表實現機會愈高）'];
    foreach (RM_RULE_CATS as $k => $c) $rows[] = ['id'=>'M-' . strtoupper($k), 'kind'=>'措施', 'cat'=>$c['label'], 'when'=>'由處境分析表產生風險草稿時', 'then'=>'因應措施：' . implode('；', $c['measures']) . '　成效確認方式：' . $c['metric']];
    $rows[] = ['id'=>'E-KPI', 'kind'=>'成效', 'cat'=>'有對應 KPI 的類別', 'when'=>'成效確認時，KPI 資料年度的逐月數字', 'then'=>'最近 3 個月全部達標→有效；措施追蹤尚有未逾期未結案項目→進行中；較前期改善或有達標月份→部分有效；其餘→無效；資料不足 3 個月→未確認'];
    $rows[] = ['id'=>'E-TRACK', 'kind'=>'成效', 'cat'=>'沒有 KPI 的類別（以及人員／能力傳承，KPI 只列為參考）', 'when'=>'相關措施追蹤（依措施欄註記的單號、類別關鍵字、溝通對象類型找出）', 'then'=>'全部如期結案→有效；有逾期→無效；有未結案但未逾期→進行中；找不到追蹤項目→未確認'];
    $rows[] = ['id'=>'E-CONC', 'kind'=>'成效', 'cat'=>'客戶集中度', 'when'=>'前三大客戶占比 vs 目標 30% 與前一年', 'then'=>'低於 30%→有效；較前一年下降→部分有效；未下降→無效'];
    $rows[] = ['id'=>'E-NEWCUST', 'kind'=>'成效', 'cat'=>'新客戶／商機', 'when'=>'當年首次出貨客戶數與金額 vs 前一年', 'then'=>'兩者皆不低於前一年→有效；其一→部分有效；皆低於→無效'];
    return $rows;
}

/* ============================ 基本工具 ============================ */

/** 0～1 的比例 → 目前設定的等級數字（v）。levels 為已排序的 [{v,…}] */
function rm_rule_frac_to_v(array $levels, float $f): int
{
    $n = count($levels); if ($n < 1) return 1;
    $idx = max(1, min($n, (int)round($f * $n)));
    return (int)$levels[$idx - 1]['v'];
}

/** 資料年度：年初（1～3 月）填當年度的表時當年資料還很少，改用前一年的全年資料；其餘情況用該年度自己的資料 */
function rm_data_year(PDO $db, int $year): int
{
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    return ($year === (int)substr($today, 0, 4) && (int)substr($today, 5, 2) <= 3) ? $year - 1 : $year;
}

/** 議題文字 → [類別 key, 命中的規則編號, 命中的關鍵字] */
function rm_rule_classify(string $text, string $dim = ''): array
{
    $best = 'generic'; $bestN = 0; $hits = [];
    foreach (RM_RULE_CATS as $k => $c) {
        if (!$c['kw']) continue;
        $n = 0; $h = [];
        foreach ($c['kw'] as $w) { $cnt = mb_substr_count($text, $w); if ($cnt) { $n += $cnt; $h[] = $w; } }
        if ($k === 'new_customer' && $dim === 'O') $n += 1;                  // 機會象限的「首次出貨／新客戶」優先歸商機
        if ($n > $bestN) { $bestN = $n; $best = $k; $hits = $h; }
    }
    return [$best, 'C-' . strtoupper($best), $hits];
}

/** 某 KPI（名稱含關鍵字）在某年度的逐月資料與是否達標 */
function rm_rule_kpi(PDO $db, int $year, array $kws): ?array
{
    foreach ($kws as $kw) {
        $st = $db->prepare("SELECT y.indicator_id, i.name, y.target_direction td, y.target_value tv, y.target_unit tu FROM kpi_as_indicator_year y
                            JOIN kpi_as_indicator i ON i.indicator_id=y.indicator_id WHERE y.year=? AND y.target_value IS NOT NULL AND i.name LIKE ? ORDER BY i.item_no LIMIT 1");
        $st->execute([$year, '%' . $kw . '%']);
        $ind = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ind) continue;
        $mv = $db->prepare("SELECT month, COALESCE(override_value, manual_value, auto_value) v FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? ORDER BY month");
        $mv->execute([(int)$ind['indicator_id'], $year]);
        $months = [];
        foreach ($mv->fetchAll(PDO::FETCH_ASSOC) as $r) if ($r['v'] !== null) $months[(int)$r['month']] = (float)$r['v'];
        $gte = $ind['td'] === 'gte'; $tv = (float)$ind['tv'];
        $ok = function ($v) use ($gte, $tv) { return $gte ? $v >= $tv : $v <= $tv; };
        $miss = 0; foreach ($months as $v) if (!$ok($v)) $miss++;
        $keys = array_keys($months); $lastM = $keys ? max($keys) : 0;
        $last3 = array_slice($months, -3, 3, true);
        $met3 = count($last3) >= 3; foreach ($last3 as $v) if (!$ok($v)) $met3 = false;
        $half = (int)floor(count($months) / 2);
        $vals = array_values($months);
        $avgA = $half ? array_sum(array_slice($vals, 0, $half)) / $half : null;
        $avgB = $half ? array_sum(array_slice($vals, $half)) / (count($vals) - $half) : null;
        $improved = $avgA !== null && ($gte ? $avgB > $avgA : $avgB < $avgA);
        return ['name'=>$ind['name'], 'gte'=>$gte, 'target'=>$tv, 'unit'=>(string)$ind['tu'], 'months'=>$months, 'n'=>count($months), 'miss'=>$miss,
                'last_month'=>$lastM, 'last_ok'=>$lastM ? $ok($months[$lastM]) : true, 'met3'=>$met3, 'improved'=>$improved, 'ok'=>$ok];
    }
    return null;
}
function rm_rule_kpi_txt(array $k): string
{
    $o = [];
    foreach ($k['months'] as $m => $v) $o[] = $m . '月 ' . rm_sg_f($v) . $k['unit'] . ($k['ok']($v) ? '' : '✗');
    return $k['name'] . '（目標 ' . ($k['gte'] ? '≥' : '≤') . rm_sg_f($k['target'], 2) . $k['unit'] . '；✗＝未達標）：' . implode('、', $o);
}

/** 前三大客戶占比 [本年占比%, 前一年占比%] */
function rm_rule_share(PDO $db, int $year): array
{
    $f = function ($y) use ($db) {
        $tot = (float)$db->query("SELECT SUM(Qty*Unit_price) FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Order_date BETWEEN '$y-01-01' AND '$y-12-31'")->fetchColumn();
        if ($tot <= 0) return null;
        $t3 = (float)$db->query("SELECT SUM(a) FROM (SELECT SUM(Qty*Unit_price) a FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Order_date BETWEEN '$y-01-01' AND '$y-12-31' AND Client_name<>'' GROUP BY Client_name ORDER BY a DESC LIMIT 3) t")->fetchColumn();
        return $t3 * 100 / $tot;
    };
    return [$f($year), $f($year - 1)];
}
/** 當年首次出貨客戶 [家數, 金額] */
function rm_rule_newcust(PDO $db, int $year, string $mmdd = '12-31'): array
{
    $r = $db->query("SELECT COUNT(*) c, COALESCE(SUM(a),0) a FROM (SELECT SUM(Qty*Unit_price) a FROM is_list WHERE " . rm_audit_in($db,'d_setting_id') . " AND Client_name<>'' AND Order_date<='$year-$mmdd' GROUP BY Client_name HAVING MIN(Order_date) BETWEEN '$year-01-01' AND '$year-$mmdd') t")->fetch(PDO::FETCH_ASSOC);
    return [(int)$r['c'], (float)$r['a']];
}
function rm_rule_typhoon(PDO $db, int $year): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM evenement WHERE title LIKE '%颱風%' AND start BETWEEN ? AND ?");
    $st->execute([$year . '-01-01', $year . '-12-31 23:59:59']);
    return (int)$st->fetchColumn();
}

/** 相關措施追蹤：先看措施欄有沒有註記單號，再用類別關鍵字與溝通對象類型找（只找 $year 年的） */
function rm_rule_tracks(PDO $db, int $year, string $cat, string $text): array
{
    $c = RM_RULE_CATS[$cat] ?? RM_RULE_CATS['generic'];
    $found = [];
    $base = "SELECT t.track_id, t.party, t.content, t.action, t.owner_name, t.due_date, t.is_closed, t.closed_date, r.rec_no, r.comm_date, cc.name cat_name
             FROM comm_track t LEFT JOIN comm_record r ON r.rec_id=t.src_rec_id LEFT JOIN comm_track_cat cc ON cc.cat_id=t.cat_id WHERE t.is_deleted=0 ";
    try {
        if (preg_match_all('/措施追蹤\s*#(\d+)/u', $text, $m)) {
            foreach ($m[1] as $id) { $st = $db->prepare($base . "AND t.track_id=?"); $st->execute([(int)$id]); if ($r = $st->fetch(PDO::FETCH_ASSOC)) $found[$r['track_id']] = $r; }
        }
        if (preg_match_all('/溝通記錄\s*(\d{8,11})/u', $text, $m)) {
            foreach ($m[1] as $no) { $st = $db->prepare($base . "AND r.rec_no=?"); $st->execute([$no]); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $found[$r['track_id']] = $r; }
        }
        $w = []; $a = [$year];
        foreach ($c['trk_kw'] as $kw) { $w[] = "(t.content LIKE ? OR t.action LIKE ?)"; $a[] = "%$kw%"; $a[] = "%$kw%"; }
        $cond = [];
        if ($w) $cond[] = '(' . implode(' OR ', $w) . ')';
        if ($c['trk_kind'] === 'government') $cond[] = "cc.kind='government'";
        elseif ($c['trk_kind'] === 'supplier') $cond[] = "r.party_kind='supplier'";
        elseif ($c['trk_kind'] === 'employee') $cond[] = "r.party_kind='employee'";
        elseif ($c['trk_kind'] === 'customer') $cond[] = "r.party_kind='customer'";
        if ($cond) {
            // 關鍵字與溝通對象類型「同時」符合才算相關（只符合其一會把別類的追蹤也算進來）
            $not = '';
            foreach (($c['trk_not'] ?? []) as $nk) { $not .= " AND t.content NOT LIKE ? AND t.action NOT LIKE ?"; $a[] = "%$nk%"; $a[] = "%$nk%"; }
            $st = $db->prepare($base . "AND YEAR(t.react_date)=? AND " . implode(' AND ', $cond) . $not . " ORDER BY t.react_date, t.track_id LIMIT 8");
            // 參數順序：react_date 年度在前，其後是關鍵字（kind 條件不吃參數）
            $st->execute($a);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $found[$r['track_id']] = $r;
        }
    } catch (Throwable $e) { rm_sg_err($e); }
    ksort($found);
    return array_slice(array_values($found), 0, 8);
}
function rm_rule_track_txt(array $t, string $today): string
{
    $state = (int)$t['is_closed'] ? '已結案' . ($t['closed_date'] ? ' ' . substr($t['closed_date'], 0, 10) : '')
           : ($t['due_date'] && $t['due_date'] < $today ? '未結案・已逾期（預計 ' . $t['due_date'] . '）' : '未結案（預計 ' . ($t['due_date'] ?: '未訂') . '）');
    return '措施追蹤 #' . $t['track_id'] . '（' . mb_substr((string)$t['action'], 0, 40) . '；負責 ' . $t['owner_name'] . '；' . $state . '）' . ($t['rec_no'] ? '，來自溝通記錄 ' . $t['rec_no'] : '');
}

/* ============================ 產生風險草稿（M／S／O 規則） ============================ */

/**
 * 由組織處境的一個議題產生風險／機會草稿。$dataYear＝評分所依據的資料年度（年初用前一年）。
 * @return array 欄位：ro_kind, ro_text, severity, frequency, measure, effect_check, rule_ids, why（給人看的判斷說明）
 */
function rm_rule_make_row(PDO $db, array $set, array $ctx, int $dataYear): array
{
    $text = trim(($ctx['stakeholder'] ?? '') . ' ' . ($ctx['needs'] ?? '') . ' ' . ($ctx['issue'] ?? ''));
    [$cat, $cid, $hits] = rm_rule_classify($text, (string)($ctx['dimension'] ?? ''));
    $c = RM_RULE_CATS[$cat]; $ids = [$cid, 'S-' . strtoupper($cat), 'M-' . strtoupper($cat)];
    $why = ['分類：' . $c['label'] . ($hits ? '（命中關鍵字：' . implode('、', $hits) . '）' : '（沒有關鍵字命中，請人工確認）')];
    $sevF = $c['sev']; $occF = 0.5;

    $kpi = ($c['kpi'] && $cat !== 'new_customer') ? rm_rule_kpi($db, $dataYear, $c['kpi']) : null;
    $fromKpi = null;
    if ($kpi) {
        if ($kpi['n'] < 2) { $fromKpi = 0.5; $why[] = $kpi['name'] . ' ' . $dataYear . ' 年資料不足 2 個月，發生率取中間值'; }
        else {
            $r = $kpi['miss'] / $kpi['n'];
            $fromKpi = $r == 0 ? 0.34 : ($r < 0.3 ? 0.5 : ($r < 0.6 ? 0.67 : 1.0));
            if (!$kpi['last_ok']) $fromKpi = max($fromKpi, 0.67);
            $why[] = $kpi['name'] . ' ' . $dataYear . ' 年 ' . $kpi['n'] . ' 個月中 ' . $kpi['miss'] . ' 個月未達標（' . rm_sg_f($r * 100, 0) . '%）' . ($kpi['last_ok'] ? '' : '，最近一個月仍未達標');
        }
        $ids[] = 'O-KPI';
    }
    switch ($cat) {
        case 'concentration':
            [$s0, $s1] = rm_rule_share($db, $dataYear);
            if ($s0 !== null) { $sevF = $s0 >= 40 ? 1.0 : ($s0 >= 30 ? 0.8 : 0.65); $occF = $s0 >= 40 ? 0.67 : ($s0 >= 30 ? 0.5 : 0.34); $why[] = '前三大客戶占 ' . $dataYear . ' 年出貨 ' . rm_sg_f($s0) . '%'; $ids[] = 'O-CONC'; }
            break;
        case 'climate':
            $d = rm_rule_typhoon($db, $dataYear) + rm_rule_typhoon($db, $dataYear - 1);
            $occF = $d >= 6 ? 1.0 : ($d >= 2 ? 0.5 : 0.34); $why[] = $dataYear . ' 與前一年颱風假共 ' . $d . ' 天'; $ids[] = 'O-CLIMATE';
            break;
        case 'new_customer':
            [$n, $amt] = rm_rule_newcust($db, $dataYear);
            $occF = $n >= 20 ? 1.0 : ($n >= 5 ? 0.67 : ($n >= 1 ? 0.5 : 0.34)); $why[] = $dataYear . ' 年首次出貨客戶 ' . $n . ' 家'; $ids[] = 'O-NEWCUST';
            break;
        case 'legal':
            $tr = rm_rule_tracks($db, $dataYear, 'legal', '');
            $today = (string)$db->query("SELECT CURDATE()")->fetchColumn(); $over = 0;
            foreach ($tr as $t) if (!(int)$t['is_closed'] && $t['due_date'] && $t['due_date'] < $today) $over++;
            $occF = $over ? 0.67 : 0.34; $why[] = '政府單位相關追蹤共 ' . count($tr) . ' 項' . ($over ? '，其中 ' . $over . ' 項逾期' : '，無逾期'); $ids[] = 'O-LEGAL';
            break;
        case 'people':
            $st = $db->prepare("SELECT COUNT(*) n, SUM(hire_date>=DATE_SUB(?, INTERVAL 2 YEAR)) nw FROM user WHERE state=1 AND hire_date IS NOT NULL AND hire_date<=?");
            $y2 = min($dataYear . '-12-31', (string)$db->query("SELECT CURDATE()")->fetchColumn()); $st->execute([$y2, $y2]); $h = $st->fetch(PDO::FETCH_ASSOC);
            $ratio = (int)$h['n'] ? (int)$h['nw'] * 100 / (int)$h['n'] : 0;
            $occH = $ratio >= 30 ? 1.0 : ($ratio >= 20 ? 0.67 : ($ratio >= 10 ? 0.5 : 0.34));
            $occF = max($occH, $fromKpi ?? 0); $fromKpi = null; $why[] = '近兩年新進人員占 ' . rm_sg_f($ratio) . '%'; $ids[] = 'O-HIRE';
            break;
        case 'quality':
            $st = $db->prepare("SELECT COUNT(*) FROM qa_abnormal_order WHERE " . rm_audit_in($db,'part_d_id') . " AND deleted_at IS NULL AND YEAR(created_at)=?"); $st->execute([$dataYear]); $n = (int)$st->fetchColumn();
            $occQ = $n >= 6 ? 1.0 : ($n >= 3 ? 0.67 : ($n >= 1 ? 0.5 : 0.34));
            $ir = function ($y) use ($db) { $q = $db->prepare("SELECT COUNT(*) FROM ir_track WHERE " . rm_audit_in($db,'d_setting_id') . " AND YEAR(IR_date)=?"); $q->execute([$y]); return (int)$q->fetchColumn(); };
            $n0 = $ir($dataYear); $n1 = $ir($dataYear - 1);
            $occR = 0.34;
            if ($n0 > 0) { $inc = $n1 > 0 ? ($n0 - $n1) * 100 / $n1 : 0; $occR = $inc >= 30 ? 1.0 : ($inc >= 10 ? 0.67 : 0.5); }
            $occF = max($occQ, $occR, $fromKpi ?? 0); $fromKpi = null;
            $why[] = $dataYear . ' 年品質異常處理單 ' . $n . ' 張；客戶退貨 ' . $n0 . ' 筆（前一年 ' . $n1 . ' 筆）'; $ids[] = 'O-QA'; $ids[] = 'O-RETURN';
            break;
        case 'supplier':
            $auditIn = implode(',', array_map('intval', array_keys(rm_outsource_proc_nos($db)))) ?: '0';
            $st = $db->prepare("SELECT COUNT(*) FROM (SELECT b.process_no FROM bom_ing b JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no AND COALESCE(ml.internal,0)=0 WHERE b.maker_id_no<>'' AND b.process_no IN (" . $auditIn . ") AND b.outsource_date BETWEEN ? AND ? GROUP BY b.process_no HAVING COUNT(DISTINCT b.maker_id_no)=1 AND COUNT(*)>=" . RM_SG_SINGLE_MIN . ") t");
            $st->execute([$dataYear . '-01-01', $dataYear . '-12-31 23:59:59']); $sg = (int)$st->fetchColumn();
            if ($sg > 0) { $fromKpi = max($fromKpi ?? 0.5, 0.67); $why[] = $dataYear . ' 年有 ' . $sg . ' 個製程的委外只交給 1 家廠商'; $ids[] = 'O-SINGLE'; }
            break;
    }
    if ($fromKpi !== null) $occF = $fromKpi;

    // 嚴重度(S)與發生率(O)換算成「目前設定」的等級
    $impRows = array_values($set['rm_impact']['rows']); $likRows = array_values($set['rm_likelihood']);
    $sev = rm_rule_frac_to_v($impRows, $sevF); $occ = rm_rule_frac_to_v($likRows, $occF);
    $why[] = '嚴重度(S)比例 ' . rm_sg_f($sevF * 100, 0) . '% → 第 ' . $sev . ' 級；發生率(O)比例 ' . rm_sg_f($occF * 100, 0) . '% → 第 ' . $occ . ' 級';

    // 措施與成效確認方式
    $measure = implode('；', $c['measures']);
    $kpiName = $kpi ? $kpi['name'] : ($c['kpi'] ? $c['kpi'][0] . '相關 KPI' : '');
    $metric = str_replace('{kpi}', $kpiName ? ' KPI「' . $kpiName . '」' . ($kpi ? '（目標 ' . ($kpi['gte'] ? '≥' : '≤') . rm_sg_f($kpi['target'], 2) . $kpi['unit'] . '）' : '') : '指標', $c['metric']);
    $ro = ((string)($ctx['dimension'] ?? '') === 'O' || $c['ro'] === 'opportunity') ? 'opportunity' : 'risk';
    return ['category'=>$cat, 'ro_kind'=>$ro, 'ro_text'=>$c['impact'], 'severity'=>$sev, 'frequency'=>$occ, 'measure'=>$measure,
            'effect_check'=>trim($metric), 'rule_ids'=>implode(',', array_values(array_unique($ids))), 'why'=>$why];
}

/* ============================ 成效確認：佐證與建議結果（E 規則） ============================ */

/**
 * @param array $row 風險列（stakeholder/needs/issue/ro_text/measure/effect_check/ctx_item_id/dimension…，含畫面上尚未存檔的內容）
 * @param int   $year 成效確認所看的年度（通常是這張表的年度）
 * @return array ['lines'=>string[], 'text'=>string, 'status'=>code, 'reason'=>string, 'rule_ids'=>string]
 */
function rm_rule_evidence(PDO $db, array $set, array $row, int $year): array
{
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    // 有連動組織處境議題時，一律以處境議題的內容（含所在象限）判斷——風險列自己沒有象限，機會象限的加權會漏掉
    $cx = (int)($row['ctx_item_id'] ?? 0);
    if ($cx > 0) {
        $q = $db->prepare("SELECT dimension, stakeholder, needs, issue FROM rm_item WHERE item_id=?"); $q->execute([$cx]);
        if ($c0 = $q->fetch(PDO::FETCH_ASSOC)) { foreach (['dimension', 'stakeholder', 'needs', 'issue'] as $k) $row[$k] = $c0[$k]; }
    }
    $text = trim(($row['stakeholder'] ?? '') . ' ' . ($row['needs'] ?? '') . ' ' . ($row['issue'] ?? ''));     // 只看議題本身，不含套用範本產生的影響說明
    [$cat] = rm_rule_classify($text, (string)($row['dimension'] ?? ''));
    $c = RM_RULE_CATS[$cat]; $lines = []; $ids = ['C-' . strtoupper($cat)];
    $status = 'unconfirmed'; $reason = '';

    $tracks = rm_rule_tracks($db, $year, $cat, (string)($row['measure'] ?? '') . ' ' . (string)($row['issue'] ?? ''));
    $open = 0; $over = 0; $closed = 0;
    foreach ($tracks as $t) {
        if ((int)$t['is_closed']) $closed++; elseif ($t['due_date'] && $t['due_date'] < $today) $over++; else $open++;
        $lines[] = rm_rule_track_txt($t, $today);
    }
    $trackVerdict = function () use ($tracks, $closed, $over, $open) {
        if (!$tracks) return ['unconfirmed', '找不到對應的措施追蹤項目，無法判斷措施是否已落實'];
        if ($over) return ['ineffective', $over . ' 項措施追蹤已逾期未結案'];
        if ($open) return ['doing', '尚有 ' . $open . ' 項措施追蹤在執行中（未逾期）'];
        return ['effective', '相關 ' . $closed . ' 項措施追蹤全部如期結案'];
    };

    $kpi = ($c['kpi'] && $cat !== 'new_customer') ? rm_rule_kpi($db, $year, $c['kpi']) : null;
    if ($cat === 'quality') {
        $irq = $db->prepare("SELECT COUNT(*) FROM ir_track WHERE " . rm_audit_in($db,'d_setting_id') . " AND YEAR(IR_date)=?");
        $irq->execute([$year]); $i0 = (int)$irq->fetchColumn(); $irq->execute([$year - 1]); $i1 = (int)$irq->fetchColumn();
        $lines[] = $year . ' 年客戶退貨 ' . $i0 . ' 筆（前一年 ' . $i1 . ' 筆）';
    }
    if ($cat === 'concentration') {
        [$s0, $s1] = rm_rule_share($db, $year); $ids[] = 'E-CONC';
        if ($s0 !== null) {
            array_unshift($lines, '前三大客戶占 ' . $year . ' 年出貨 ' . rm_sg_f($s0) . '%' . ($s1 !== null ? '（前一年 ' . rm_sg_f($s1) . '%）' : '') . '；目標：低於 30%');
            if ($s0 < 30) { $status = 'effective'; $reason = '占比已低於 30%'; }
            elseif ($s1 !== null && $s0 < $s1) { $status = 'partial'; $reason = '占比較前一年下降，但仍高於 30%'; }
            else { $status = 'ineffective'; $reason = '占比未較前一年下降'; }
        } else $reason = '當年出貨資料不足';
    } elseif ($cat === 'new_customer') {
        // 當年度還沒過完時，前一年也只比到同一天（不拿半年去比整年）
        $mmdd = $year === (int)substr($today, 0, 4) ? substr($today, 5, 5) : '12-31';
        [$n0, $a0] = rm_rule_newcust($db, $year, $mmdd); [$n1, $a1] = rm_rule_newcust($db, $year - 1, $mmdd); $ids[] = 'E-NEWCUST';
        array_unshift($lines, $year . ' 年（1/1～' . str_replace('-', '/', $mmdd) . '）首次出貨客戶 ' . $n0 . ' 家、金額 ' . number_format($a0) . ' 元；前一年同期 ' . $n1 . ' 家、' . number_format($a1) . ' 元');
        $good = ($n0 >= $n1 ? 1 : 0) + ($a0 >= $a1 ? 1 : 0);
        $status = $good == 2 ? 'effective' : ($good == 1 ? 'partial' : 'ineffective');
        $reason = $good == 2 ? '首次出貨客戶數與金額皆不低於前一年' : ($good == 1 ? '客戶數或金額其中一項不低於前一年' : '客戶數與金額皆低於前一年');
    } elseif ($kpi && $kpi['n'] >= 1 && ($c['by'] ?? 'kpi') === 'kpi') {
        $ids[] = 'E-KPI';
        array_unshift($lines, rm_rule_kpi_txt($kpi));
        if ($kpi['n'] < 3) { $status = 'unconfirmed'; $reason = $kpi['name'] . ' 目前只有 ' . $kpi['n'] . ' 個月資料，不足 3 個月無法判斷'; }
        elseif ($kpi['met3']) { $status = 'effective'; $reason = '最近 3 個月 ' . $kpi['name'] . ' 全部達標'; }
        elseif ($open && !$over) { $status = 'doing'; $reason = $kpi['name'] . ' 尚未連續 3 個月達標，但措施追蹤仍在執行中'; }
        elseif ($kpi['improved'] || ($kpi['n'] - $kpi['miss']) > 0 && $kpi['last_ok']) { $status = 'partial'; $reason = $kpi['name'] . ' 較前期改善或已有月份達標，但尚未連續 3 個月達標'; }
        else { $status = 'ineffective'; $reason = $kpi['name'] . ' 未改善' . ($tracks && !$open ? '，而相關措施已執行完畢' : ''); }
    } else {
        $ids[] = 'E-TRACK';
        if ($kpi && $kpi['n'] >= 1) array_unshift($lines, '（參考）' . rm_rule_kpi_txt($kpi));
        [$status, $reason] = $trackVerdict();
        if ($cat === 'climate') { $d = rm_rule_typhoon($db, $year); array_unshift($lines, $year . ' 年行事曆颱風假 ' . $d . ' 天'); }
    }
    if (!$lines) $lines[] = '系統找不到對應資料（沒有對應的 KPI、措施追蹤或溝通記錄）';
    $lines[] = '系統建議結果：' . RM_EFFECT_STATUS[$status] . '（' . $reason . '）。資料截至 ' . $today . '，規則：' . implode('、', $ids) . '；最後仍由主管裁示。';
    return ['lines'=>$lines, 'text'=>implode("\n", $lines), 'status'=>$status, 'reason'=>$reason, 'rule_ids'=>implode(',', $ids), 'category'=>$cat];
}
