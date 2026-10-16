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
/*
 * 【成效確認方式的寫法原則】（2026-10-15 使用者要求：因應措施制訂時要先想好成效怎麼追蹤，
 *   不可以寫出「系統查不到」或「一開始就達不到」的成效，否則一定判無效、又自動衍生矯正單，只是造成困擾）
 *  ① 只寫系統查得到的：KPI 逐月數字、出貨金額與客戶數、措施追蹤是否如期結案。
 *     系統查不到的（「報價回覆率 100%」「紀錄 100% 留存」「重複原因不再發生」）一律不寫進成效方式，
 *     那些屬於主管裁示時人工確認的事，寫了只會讓判定永遠停在「未確認」。
 *  ② 目標要從「目前的實際水準」往前走，不是憑空寫一個遙不可及的絕對值：
 *     客戶集中度寫成「控制在警戒線 50% 以下」（依目前實際水準訂，不寫「降到 30% 以下」這種遙不可及的值）；
 *     KPI 在資料年度已有六成以上月份未達標時，先要求「逐月改善」，最終目標才是連續 3 個月達標。
 *  ③ 判無效的唯一條件＝措施已經執行（或逾期未做）而指標沒有改善；措施還在做就是「進行中」。
 */
const RM_RULE_CATS = [
    'delivery' => ['label' => '交期／準時出貨', 'ro' => 'risk', 'sev' => 0.9,
        'kw' => ['準時出貨', '交期', '延後出貨', '出貨延', '交貨', '準時交貨', '遲交'],
        'impact' => '準時出貨率未達目標，客戶抱怨增加並可能失去訂單。',
        'measures' => ['生管組每週確認關鍵製程負荷並預警，將交期狀況提供業務課回報客戶', '接單時由生管組確認關鍵製程產能，必要時調整交期或外包'],
        'metric' => '{kpi}回到年度目標並連續 3 個月達標',
        'kpi' => ['準時出貨'], 'trk_kw' => ['交期', '出貨', '延後', '延遲'], 'trk_kind' => 'customer', 'trk_not' => ['首次出貨', '年度報價', '客訴']],
    'supplier' => ['label' => '供應鏈／委外', 'ro' => 'risk', 'sev' => 0.8,
        'kw' => ['廠商準時', '委外', '供應商', '單一來源', '單一廠商', '備援', '缺料', '原料', '交期不穩'],
        'impact' => '委外或供料延遲造成製令停滯並連帶使客戶交期落後；單一來源會放大風險。',
        'measures' => ['生管組加強跟催主要委外廠商的交期與進度'],
        'metric' => '{kpi}回升並維持達標',
        'kpi' => ['廠商準時'], 'trk_kw' => ['跟催', '備援'], 'trk_kind' => 'supplier', 'trk_not' => ['颱風', '豪雨']],
    'quality' => ['label' => '品質／客訴', 'ro' => 'risk', 'sev' => 0.9,
        'kw' => ['客訴', '客戶退貨', '退貨', '品質異常', '不良', '矯正', '品質穩定'],
        'impact' => '客訴或不良持續發生，影響客戶信任與再下單意願，並增加重工、報廢與運費成本。',
        'measures' => ['客訴與不良逐件開立異常矯正處理單，並回報客戶處理結果', '重複出現的原因建立預防措施並追蹤到結案'],
        'metric' => '{kpi}回到年度目標並連續 3 個月達標；矯正與預防的措施追蹤如期結案',
        'kpi' => ['客訴', '不良率'], 'trk_kw' => ['客訴', '矯正', '不良', '預防措施'], 'trk_kind' => 'customer'],
    'concentration' => ['label' => '客戶集中度', 'ro' => 'risk', 'sev' => 0.8,
        'kw' => ['集中度', '前三大客戶', '大客戶', '客戶集中'],
        'impact' => '大客戶訂單變動會直接衝擊產能與營收。',
        'measures' => ['業務課每季與前三大客戶溝通訂單預測與產能預留，並提供生管組參考', '業務課持續開拓客源以分散風險'],
        'metric' => '前三大客戶合計出貨占比控制在 50% 以下（客戶集中度警戒線；目前 {share}）；每季溝通的措施追蹤如期結案',
        'kpi' => [], 'trk_kw' => ['訂單預測與產能預留'], 'trk_kind' => 'customer', 'trk_not' => ['交期']],
    'new_customer' => ['label' => '新客戶首次出貨', 'ro' => 'opportunity', 'sev' => 0.4,
        'kw' => ['首次出貨', '新客戶', '新客', '商機', '開發客戶'],
        'impact' => '新客戶首次出貨後若能持續下單，可分散對大客戶的依賴並提高產能利用率。',
        'measures' => ['與新客戶於首次出貨當下或 30 天內進行溝通（了解使用情形與後續需求），並留存溝通記錄', '每季由業務課與生管組確認新客戶的訂單與產能預留'],
        'metric' => '當年首次出貨客戶數與金額不低於前一年同期；首次出貨的新客戶皆於首次出貨當下或 30 天內留有溝通記錄',
        'kpi' => ['接單率'], 'trk_kw' => ['首批', '新客戶', '首次出貨', '年度需求'], 'trk_kind' => 'customer'],
    'inquiry' => ['label' => '接單評估與交期回覆', 'ro' => 'opportunity', 'sev' => 0.4,
        'kw' => ['詢價', '轉單', '評估與承接', '評估表', 'PFMEA', '導入'],
        'impact' => '接到訂單時若能快速完成製程、產能與品質評估並回覆交期，可提高接單轉換並避免交期承諾失準。',
        'measures' => ['接到訂單時，由技術課、生產課、生管組、品管課評估製程可行性、產能與品質要求並回覆交期，業務課彙整後回覆客戶', '業務課於客戶要求的時效內回覆交期'],
        'metric' => '{kpi}不低於年度目標；接單評估的措施追蹤如期結案',
        'kpi' => ['接單率'], 'trk_kw' => ['接單評估'], 'trk_kind' => ''],
    'existing_customer' => ['label' => '既有客戶擴單', 'ro' => 'opportunity', 'sev' => 0.4,
        'kw' => ['既有客戶', '擴大', '接單量', '擴單', '增加訂單', '同類'],
        'impact' => '既有客戶已通過品質驗證，爭取同類加工訂單可提高產能利用率與營收穩定度。',
        'measures' => ['每季盤點既有客戶同類料號的加工需求並主動報價', '依既有客戶的年度需求預留產能'],
        'metric' => '既有客戶（去年以前就有出貨的客戶）的稽核製程出貨金額不低於前一年同期；年度需求盤點的措施追蹤如期結案',
        'by' => 'existing',
        'kpi' => ['受訂目標'], 'trk_kw' => ['既有客戶'], 'trk_kind' => 'customer'],
    'climate' => ['label' => '氣候變異／天然災害', 'ro' => 'risk', 'sev' => 0.4,
        'kw' => ['颱風', '豪雨', '氣候', '淹水', '極端', '天災', '高溫', '限電', '停電'],
        'impact' => '極端氣候（颱風、豪雨、高溫、限電）造成停工與在途品項延遲，影響交期並可能損壞庫存。',
        'measures' => ['颱風警報發布當日，業務課通知客戶在途品項預計交期', '每年汛期前，倉管組檢查庫房防水、生產課檢查廠區防水；高溫或限電警示時，生管組與生產課調整排程'],
        'metric' => '颱風通知程序與汛期前廠區檢查的措施追蹤如期結案',
        'kpi' => [], 'trk_kw' => ['颱風', '豪雨', '防水', '氣候'], 'trk_kind' => ''],
    // 環境議題（減碳、環保法規、客戶的環境要求）：與颱風豪雨不同件事，措施與佐證都分開
    'environment' => ['label' => '環境議題／減碳', 'ro' => 'risk', 'sev' => 0.5,
        'kw' => ['減碳', '碳足跡', '環境議題', '永續', '有害物質', '節能', '廢棄物'],
        'impact' => '減碳與環保法規趨嚴、客戶提出環境要求，會增加合規與能源成本，未符合時可能影響客戶訂單。',
        'measures' => ['管理課每年盤點適用的環保與減碳法規及客戶的環境要求，指定負責部門並列入溝通管制表', '生產課與倉管組依法規處理廢棄物並保存處理紀錄'],
        'metric' => '環保與減碳相關的措施追蹤如期結案、沒有逾期未結案',
        'kpi' => [], 'trk_kw' => ['環保', '減碳', '廢棄物', '碳'], 'trk_kind' => ''],
    // 職安衛與作業安全：2-GM-05 6.1.4.9 明列「品質、安全、環境」，建議清單一定會列出
    'safety' => ['label' => '職業安全衛生／作業安全', 'ro' => 'risk', 'sev' => 0.67,
        'kw' => ['作業安全', '職業安全', '工安', '職災', '職業災害', '安全衛生', '安全的工作環境'],
        'impact' => '加工現場（砂輪、刀具、天車、搬運）若發生職業災害，會造成人員傷亡、停工並可能受政府處分，影響交期與客戶信任。',
        'measures' => ['生產課與管理課每年訂定職安衛教育訓練並確實執行，新進與換線人員上線前完成安全說明', '各課主管於現場巡檢時確認防護裝置與個人防護具齊備，發現缺失當場改善'],
        'metric' => '職安衛教育訓練與現場巡檢的措施追蹤如期結案、沒有逾期未結案；全年無停工以上的職業災害',
        'kpi' => [], 'trk_kw' => ['職安', '安全', '巡檢', '防護'], 'trk_kind' => ''],
    'legal' => ['label' => '法規遵循', 'ro' => 'risk', 'sev' => 0.67,
        'kw' => ['法定', '申報', '法規', '環保', '消防', '職安', '勞工', '稽查', '政府'],
        'impact' => '未依法定期限申報或檢修會受處分，嚴重時影響營運與客戶稽核。',
        'measures' => ['管理課建立年度法定申報行事曆並指定負責人', '申報與檢修紀錄歸檔，列入溝通管制表每年提醒'],
        'metric' => '法定申報與檢修的措施追蹤皆如期結案、沒有逾期未結案',
        'kpi' => [], 'trk_kw' => ['申報', '檢修', '健康檢查', '職安'], 'trk_kind' => 'government'],
    'measure' => ['label' => '量測／校驗', 'ro' => 'risk', 'sev' => 0.75,
        'kw' => ['量具', '校驗', '量測', '儀器'],
        'impact' => '量測失準或校驗逾期造成不良品流出並影響客戶信任。',
        'measures' => ['全面盤點量具並依類別重訂校驗週期', '校驗到期前 30 天提醒並追蹤完成'],
        'metric' => '{kpi}不低於年度目標；校驗的措施追蹤如期結案',
        'kpi' => ['校驗'], 'trk_kw' => ['量具', '校驗', '量測'], 'trk_kind' => ''],
    'sales' => ['label' => '業績／營收目標', 'ro' => 'risk', 'sev' => 0.75,
        'kw' => ['達成率', '受訂', '銷貨額', '營收', '接單', '訂單金額'],
        'impact' => '業績達成率落後，影響營收與產能規劃。',
        'measures' => ['業務課每月與主要客戶確認訂單預測，並跟催未回覆的報價', '依訂單預測調整產能與交期承諾'],
        // 同一類別底下對應不同 KPI 時，第一條措施依 KPI 區分（否則「受訂金額」與「銷貨額」兩列會寫得一模一樣）
        'measures_kpi' => ['受訂' => '業務課每月與主要客戶確認訂單預測，並跟催未回覆的報價', '銷貨額' => '業務課與生管組每月核對待出貨訂單與交期，已完工品項如期出貨'],
        'metric' => '{kpi}回到年度目標並連續 3 個月達標',
        'kpi' => ['銷貨額達成', '受訂目標'], 'trk_kw' => ['訂單預測與產能預留', '報價', '衝刺'], 'trk_kind' => 'customer', 'trk_not' => ['延後', '客訴']],
    // 國際局勢（戰爭、通膨、關稅、能源、匯率）：AS9100D 4.1 要求考量的外部議題，建議清單一定會列出
    'geopolitics' => ['label' => '國際局勢／總體經濟', 'ro' => 'risk', 'sev' => 0.75,
        'kw' => ['國際局勢', '戰爭', '地緣', '通膨', '關稅', '貿易', '匯率', '能源價格', '制裁', '航運', '物價'],
        'impact' => '國際局勢變動使客戶壓低加工單價或減少、延後下單，公司的能源與耗材等營運成本同時上升，壓縮利潤。',
        'measures' => ['業務課每季與主要客戶確認訂單預測與加工單價條件，成本明顯上升時與客戶協議調整加工單價', '管理課與生產課每月留意電力與刀具砂輪等營運成本的變動，異常上升時回報總經理室'],
        'metric' => '稽核製程出貨金額不低於前一年同期；加工單價與營運成本的措施追蹤如期結案',
        'kpi' => [], 'trk_kw' => ['加工單價', '營運成本', '刀具', '價格條件'], 'trk_kind' => ''],
    'generic' => ['label' => '一般議題', 'ro' => 'risk', 'sev' => 0.65,
        'kw' => [],
        'impact' => '此議題若未妥善因應，可能影響公司目標達成與利害關係者滿意度。',
        'measures' => ['指定負責人並訂定因應措施與完成期限，納入溝通管理措施追蹤'],
        'metric' => '依措施追蹤項目如期結案確認',
        'kpi' => [], 'trk_kw' => [], 'trk_kind' => ''],
];

/** 各類別的「所需資源」（2-GM-05 6.1.5.3：人員、設施、工作環境、方法…），產生因應措施時一併帶入 */
const RM_RULE_RESOURCES = [
    'delivery' => '人員：生管組排程與跟催人力；設施：關鍵製程設備與工時；方法：每週製程負荷檢視',
    'supplier' => '人員：生管組跟催、採購組評估備援廠商；方法：廠商評鑑與備援廠商評估',
    'quality' => '人員：品管課檢驗與矯正人力；設施：量測設備；方法：異常矯正處理單流程',
    'concentration' => '人員：業務課開發客源與客戶溝通；方法：每季與主要客戶溝通訂單預測',
    'new_customer' => '人員：業務課；方法：首次出貨 30 天內溝通並留存溝通記錄',
    'inquiry' => '人員：技術課、生產課、生管組、品管課的評估人力；方法：產品開發評估表與 PFMEA',
    'existing_customer' => '人員：業務課；設施與產能：預留稽核製程（齒研、插齒）產能',
    'climate' => '設施：庫房與廠區防水設施；人員：倉管組、生產課檢查人力；工作環境：高溫與限電時的排程與現場因應',
    'environment' => '人員：管理課法規盤點人力；設施：廢棄物處理與暫存設施；方法：適用法規與客戶環境要求清單',
    'safety' => '人員：管理課職安衛管理人員與各課主管；設施：防護與消防設備；工作環境：現場作業安全；方法：教育訓練與巡檢',
    'legal' => '人員：管理課申報負責人；方法：年度法定申報行事曆',
    'measure' => '人員：品管課校驗人力；設施：標準件與校驗設備；方法：校驗週期與到期提醒',
    'sales' => '人員：業務課、生管組；方法：每月訂單預測與待出貨訂單核對',
    'geopolitics' => '人員：業務課客戶溝通、管理課營運成本監控；方法：加工單價條件與營運成本變動回報',
    'generic' => '由負責部門評估所需人員、設施、工作環境與方法後填寫',
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
    $rows[] = ['id'=>'O-KPI', 'kind'=>'發生率(O)', 'cat'=>'有對應 KPI 的類別', 'when'=>'資料年度內 KPI 未達標月份占比 r', 'then'=>'r=0→0.34；r<30%→0.5；r<60%→0.67；其餘→1.0；資料中最後一個月仍未達標時至少 0.67；資料不足 2 個月→0.5'];
    $rows[] = ['id'=>'O-VENDOR', 'kind'=>'發生率(O)', 'cat'=>'供應鏈／委外', 'when'=>'對外宣稱委外的稽核製程中，單一外部廠商占委外筆數 ≥30%（至少 20 筆）', 'then'=>'發生率比例至少 0.5（準時交貨率好不代表供應中斷「幾乎不可能」）'];
    $rows[] = ['id'=>'O-OPP', 'kind'=>'發生率(O)', 'cat'=>'既有客戶擴單／新客戶詢價', 'when'=>'機會的發生率＝實現可能性：依對應 KPI 當年達標月份占比 m', 'then'=>'m≥80%→1.0；≥50%→0.67；≥30%→0.5；其餘 0.34（與風險相反：達標越多可能性越高）'];
    $rows[] = ['id'=>'E-GUARD', 'kind'=>'成效', 'cat'=>'全部類別', 'when'=>'指標沒改善、但找不到任何對應的措施追蹤', 'then'=>'不判無效，改為「未確認」並提示先建立措施追蹤（沒有措施可對照就判無效，等於還沒開始做就開矯正單）'];
    $rows[] = ['id'=>'M-WRITE', 'kind'=>'措施', 'cat'=>'全部類別', 'when'=>'產生因應措施與成效確認方式時', 'then'=>'成效方式只寫系統查得到的（KPI 逐月數字、出貨金額與客戶數、措施追蹤是否結案）；目標從目前實際水準往前走（客戶集中度＝不高於前一年；KPI 前一年已有六成以上月份未達標＝先要求逐月改善）'];
    $rows[] = ['id'=>'E-EXISTING', 'kind'=>'成效', 'cat'=>'既有客戶擴單', 'when'=>'既有客戶（去年以前就有出貨）的稽核製程出貨金額，與前一年同期比較', 'then'=>'不低於→有效；差距 10% 內→部分有效；低於超過 10%→無效；前一年沒有資料→未確認'];
    $rows[] = ['id'=>'O-SINGLE', 'kind'=>'發生率(O)', 'cat'=>'供應鏈／委外', 'when'=>'當年有稽核製程只交給 1 家外部委外廠商（≥20 筆；廠內製程不算）', 'then'=>'發生率比例至少 0.67'];
    $rows[] = ['id'=>'O-QA', 'kind'=>'發生率(O)', 'cat'=>'品質／客訴', 'when'=>'當年品質異常處理單張數', 'then'=>'≥6 張→1.0；≥3 張→0.67；≥1 張→0.5（與 KPI 規則取較高者）'];
    $rows[] = ['id'=>'O-RETURN', 'kind'=>'發生率(O)', 'cat'=>'品質／客訴', 'when'=>'當年客戶退貨筆數相對前一年', 'then'=>'增加 ≥30%→1.0；≥10%→0.67；有退貨但增幅較小→0.5；無退貨 0.34（與其他品質規則取最高者）'];
    $rows[] = ['id'=>'O-CONC', 'kind'=>'發生率(O)', 'cat'=>'客戶集中度', 'when'=>'前三大客戶占當年出貨比例', 'then'=>'≥40%→0.67；≥30%→0.5；其餘 0.34；嚴重度比例同時依占比上調（≥40%→1.0；≥30%→0.8）'];
    $rows[] = ['id'=>'O-CLIMATE', 'kind'=>'發生率(O)', 'cat'=>'氣候／天然災害', 'when'=>'當年與前一年行事曆颱風假天數', 'then'=>'≥6 天→1.0；≥2 天→0.5；其餘 0.34'];
    $rows[] = ['id'=>'O-LEGAL', 'kind'=>'發生率(O)', 'cat'=>'法規遵循', 'when'=>'政府單位相關措施追蹤是否有逾期', 'then'=>'有逾期→0.67；否則 0.34'];
    $rows[] = ['id'=>'O-NEWCUST', 'kind'=>'發生率(O)', 'cat'=>'新客戶／商機', 'when'=>'當年首次出貨的客戶數', 'then'=>'≥20 家→1.0；≥5 家→0.67；≥1 家→0.5（機會：數字愈大代表實現機會愈高）'];
    foreach (RM_RULE_CATS as $k => $c) $rows[] = ['id'=>'M-' . strtoupper($k), 'kind'=>'措施', 'cat'=>$c['label'], 'when'=>'由處境分析表產生風險草稿時', 'then'=>'因應措施：' . implode('；', $c['measures']) . '　成效確認方式：' . $c['metric']];
    $rows[] = ['id'=>'E-KPI', 'kind'=>'成效', 'cat'=>'有對應 KPI 的類別', 'when'=>'成效確認時，KPI 資料年度的逐月數字', 'then'=>'資料中最後 3 個月（說明會寫出實際月份，例如 7～9 月）全部達標→有效；措施追蹤尚有未逾期未結案項目→進行中；較前期改善或有達標月份→部分有效；其餘→無效；資料不足 3 個月→未確認'];
    $rows[] = ['id'=>'E-TRACK', 'kind'=>'成效', 'cat'=>'沒有 KPI 的類別（以及人員／能力傳承，KPI 只列為參考）', 'when'=>'相關措施追蹤（依措施欄註記的單號、類別關鍵字、溝通對象類型找出）', 'then'=>'全部如期結案→有效；有逾期→無效；有未結案但未逾期→進行中；找不到追蹤項目→未確認'];
    $rows[] = ['id'=>'E-CONC', 'kind'=>'成效', 'cat'=>'客戶集中度', 'when'=>'前三大客戶占比 vs 警戒線 50%', 'then'=>'低於 50%→有效；50%～未達 60%→部分有效；60% 以上→無效'];
    $rows[] = ['id'=>'E-NEWCUST', 'kind'=>'成效', 'cat'=>'新客戶／商機', 'when'=>'當年首次出貨客戶數與金額 vs 前一年；首次出貨起 30 天內是否留有溝通記錄', 'then'=>'兩者皆不低於前一年→有效；其一→部分有效；皆低於→無效；有新客戶沒有 30 天內的溝通記錄時最多部分有效'];
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
/** 月份清單 → 文字：連續就「7～9 月」，不連續就「5、7、9 月」 */
function rm_rule_month_span(array $ms): string
{
    $ms = array_values(array_unique(array_map('intval', $ms))); sort($ms);
    if (!$ms) return '';
    if (count($ms) === 1) return $ms[0] . ' 月';
    $contig = true; for ($i = 1; $i < count($ms); $i++) if ($ms[$i] !== $ms[$i - 1] + 1) $contig = false;
    return $contig ? $ms[0] . '～' . end($ms) . ' 月' : implode('、', $ms) . ' 月';
}
/** $hint＝議題文字：同一類別有好幾個 KPI 時，優先用名稱出現在議題裡的那一個（否則兩條不同 KPI 的議題會對到同一個 KPI） */
function rm_rule_kpi(PDO $db, int $year, array $kws, string $hint = ''): ?array
{
    if ($hint !== '') usort($kws, function ($a, $b) use ($hint) { return (mb_strpos($hint, $b) !== false ? 1 : 0) <=> (mb_strpos($hint, $a) !== false ? 1 : 0); });
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
                'last_month'=>$lastM, 'last3_txt'=>rm_rule_month_span(array_keys($last3)), 'span_txt'=>$keys ? rm_rule_month_span([min($keys), max($keys)]) : '', 'last_ok'=>$lastM ? $ok($months[$lastM]) : true, 'met3'=>$met3, 'improved'=>$improved, 'ok'=>$ok];
    }
    return null;
}
function rm_rule_kpi_txt(array $k): string
{
    $o = [];
    foreach ($k['months'] as $m => $v) $o[] = $m . '月 ' . rm_sg_f($v) . $k['unit'] . ($k['ok']($v) ? '' : '✗');
    return $k['name'] . '（每月統計；目標 ' . ($k['gte'] ? '≥' : '≤') . rm_sg_f($k['target'], 2) . $k['unit'] . '；✗＝未達標）：' . implode('、', $o);
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
/** 當年首次出貨的新客戶（稽核製程料號），各自首次出貨日起 30 天內有沒有溝通記錄（溝通管理的客戶溝通記錄）。
 *  回傳 [新客戶數, 已溝通數, 未溝通客戶名稱[], 只計到 mmdd 為止] */
function rm_rule_newcust_comm(PDO $db, int $year, string $mmdd = '12-31'): array
{
    $st = $db->prepare("SELECT Client_name, MIN(Order_date) fd FROM is_list WHERE " . rm_audit_in($db, 'd_setting_id') . " AND Client_name<>''
                        GROUP BY Client_name HAVING MIN(Order_date) BETWEEN ? AND ? ORDER BY fd");
    $st->execute([$year . '-01-01', $year . '-' . $mmdd]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC); $done = 0; $miss = [];
    $q = $db->prepare("SELECT COUNT(*) FROM comm_record WHERE is_deleted=0 AND party_kind='customer' AND party_name LIKE ? AND comm_date BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)");
    foreach ($all as $r) {
        $q->execute(['%' . $r['Client_name'] . '%', $r['fd'], $r['fd']]);
        if ((int)$q->fetchColumn() > 0) $done++; else $miss[] = $r['Client_name'];
    }
    return [count($all), $done, $miss];
}
/** 既有客戶（該年 1/1 以前就有出貨）的稽核製程出貨金額：[本年 1/1～mmdd 金額, 前一年同期金額, 客戶數] */
function rm_rule_existing(PDO $db, int $year, string $mmdd = '12-31'): array
{
    $aud = rm_audit_in($db, 'd_setting_id');
    $sum = function (int $y, int $baseYear) use ($db, $aud, $mmdd) {
        $q = $db->prepare("SELECT COUNT(DISTINCT Client_name) c, COALESCE(SUM(Qty*Unit_price),0) a FROM is_list
                           WHERE $aud AND Client_name<>'' AND Order_date BETWEEN ? AND ?
                             AND Client_name IN (SELECT Client_name FROM (SELECT DISTINCT Client_name FROM is_list WHERE $aud AND Order_date < ?) x)");
        $q->execute([$y . '-01-01', $y . '-' . $mmdd, $baseYear . '-01-01']);
        return $q->fetch(PDO::FETCH_ASSOC);
    };
    $a = $sum($year, $year); $b = $sum($year - 1, $year - 1);
    return [(float)$a['a'], (float)$b['a'], (int)$a['c']];
}
function rm_rule_typhoon(PDO $db, int $year): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM evenement WHERE title LIKE '%颱風%' AND start BETWEEN ? AND ?");
    $st->execute([$year . '-01-01', $year . '-12-31 23:59:59']);
    return (int)$st->fetchColumn();
}

/** 相關措施追蹤：先看措施欄有沒有註記單號，再用類別關鍵字與溝通對象類型找（只找 $year 年的） */
function rm_rule_tracks(PDO $db, int $year, string $cat, string $text, bool $annotatedOnly = false, bool $withDraft = false): array
{
    // $annotatedOnly＝人工填寫的列（沒有規則編號）：只認措施欄裡註記的「措施追蹤 #編號／溝通記錄 單號」，
    // 不用類別關鍵字猜——猜出來的追蹤常常是別的部門的事（例如收貨列被掛上委外跟催）
    $c = RM_RULE_CATS[$cat] ?? RM_RULE_CATS['generic'];
    $found = [];
    $base = "SELECT t.track_id, t.track_no, t.party, t.content, t.action, t.owner_name, t.owner_dept_name, t.due_date, t.is_closed, t.closed_date, r.rec_no, r.comm_date, cc.name cat_name
             FROM comm_track t LEFT JOIN comm_record r ON r.rec_id=t.src_rec_id LEFT JOIN comm_track_cat cc ON cc.cat_id=t.cat_id WHERE t.is_deleted=0 " . ($withDraft ? '' : 'AND t.is_draft=0 ');   // 草稿（尚未確認）的追蹤不列入成效判定，只在畫面上顯示
    try {
        // 措施欄可註記「措施追蹤 F2026-004」（新編號）或舊式「措施追蹤 #274」
        if (preg_match_all('/措施追蹤\s*(?:(F\d{4}-\d{3})|#(\d+))/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $g) {
                if (!empty($g[1])) { $st = $db->prepare($base . "AND t.track_no=?"); $st->execute([$g[1]]); }
                else { $st = $db->prepare($base . "AND t.track_id=?"); $st->execute([(int)$g[2]]); }
                if ($r = $st->fetch(PDO::FETCH_ASSOC)) $found[$r['track_id']] = $r;
            }
        }
        if (preg_match_all('/溝通記錄\s*(\d{8,11})/u', $text, $m)) {
            foreach ($m[1] as $no) { $st = $db->prepare($base . "AND r.rec_no=?"); $st->execute([$no]); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $found[$r['track_id']] = $r; }
        }
        if ($annotatedOnly) { ksort($found); return array_slice(array_values($found), 0, 8); }
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
    return '措施追蹤 ' . (!empty($t['track_no']) ? $t['track_no'] : '#' . $t['track_id']) . '（' . mb_substr((string)$t['action'], 0, 40) . '；負責 ' . (!empty($t['owner_dept_name']) ? $t['owner_dept_name'] . ' ' : '') . $t['owner_name'] . '；' . $state . '）' . ($t['rec_no'] ? '，來自溝通記錄 ' . $t['rec_no'] : '');
}

/* ============================ 產生風險草稿（M／S／O 規則） ============================ */

/**
 * 由組織處境的一個議題產生風險／機會草稿。$dataYear＝評分所依據的資料年度（年初用前一年）。
 * @return array 欄位：ro_kind, ro_text, severity, frequency, measure, effect_check, rule_ids, why（給人看的判斷說明）
 */
function rm_rule_make_row(PDO $db, array $set, array $ctx, int $dataYear, ?int $docYear = null): array
{
    $text = trim(($ctx['stakeholder'] ?? '') . ' ' . ($ctx['needs'] ?? '') . ' ' . ($ctx['issue'] ?? ''));
    [$cat, $cid, $hits] = rm_rule_classify($text, (string)($ctx['dimension'] ?? ''));
    $c = RM_RULE_CATS[$cat]; $ids = [$cid, 'S-' . strtoupper($cat), 'M-' . strtoupper($cat)];
    $why = ['分類：' . $c['label'] . ($hits ? '（命中關鍵字：' . implode('、', $hits) . '）' : '（沒有關鍵字命中，請人工確認）')];
    $sevF = $c['sev']; $occF = 0.5;

    $kpi = ($c['kpi'] && $cat !== 'new_customer') ? rm_rule_kpi($db, $dataYear, $c['kpi'], (string)($ctx['issue'] ?? '')) : null;
    $fromKpi = null;
    if ($kpi) {
        if ($kpi['n'] < 2) { $fromKpi = 0.5; $why[] = $kpi['name'] . ' ' . $dataYear . ' 年資料不足 2 個月，發生率取中間值'; }
        else {
            $r = $kpi['miss'] / $kpi['n'];
            $fromKpi = $r == 0 ? 0.34 : ($r < 0.3 ? 0.5 : ($r < 0.6 ? 0.67 : 1.0));
            if (!$kpi['last_ok']) $fromKpi = max($fromKpi, 0.67);
            $why[] = $kpi['name'] . ' ' . $dataYear . ' 年 ' . $kpi['n'] . ' 個月中 ' . $kpi['miss'] . ' 個月未達標（' . rm_sg_f($r * 100, 0) . '%）' . ($kpi['last_ok'] ? '' : '，截至 ' . $kpi['last_month'] . ' 月仍未達標');
        }
        $ids[] = 'O-KPI';
    }
    if ($kpi && in_array($cat, ['existing_customer', 'inquiry'], true) && $kpi['n'] >= 2) {
        $met = 1 - $kpi['miss'] / $kpi['n'];
        $fromKpi = $met >= 0.8 ? 1.0 : ($met >= 0.5 ? 0.67 : ($met >= 0.3 ? 0.5 : 0.34));
        $why = array_values(array_filter($why, function ($w) use ($kpi) { return strpos($w, $kpi['name']) !== 0; }));
        $why[] = '機會的實現可能性：' . $kpi['name'] . ' ' . $dataYear . ' 年 ' . $kpi['n'] . ' 個月中有 ' . ($kpi['n'] - $kpi['miss']) . ' 個月達標（' . rm_sg_f($met * 100, 0) . '%），達標越多、實現可能性越高';
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
            $bnd = rm_bound_makers_sql($db, array_keys(rm_outsource_proc_nos($db)));     // 以廠商「加工項目」綁定為準
            $st = $db->prepare("SELECT COUNT(*) FROM (SELECT b.process_no FROM bom_ing b JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no AND COALESCE(ml.internal,0)=0 AND ml.maker_id_no IN (" . $bnd . ") WHERE b.maker_id_no<>'' AND b.process_no IN (" . $auditIn . ") AND b.outsource_date BETWEEN ? AND ? GROUP BY b.process_no HAVING COUNT(DISTINCT b.maker_id_no)=1 AND COUNT(*)>=" . RM_SG_SINGLE_MIN . ") t");
            $st->execute([$dataYear . '-01-01', $dataYear . '-12-31 23:59:59']); $sg = (int)$st->fetchColumn();
            if ($sg > 0) { $fromKpi = max($fromKpi ?? 0.5, 0.67); $why[] = $dataYear . ' 年有 ' . $sg . ' 個製程的委外只交給 1 家廠商'; $ids[] = 'O-SINGLE'; }
            // 集中度：單一外部廠商占外宣稱委外製程的筆數 ≥ RM_SG_VENDOR_PCT％，發生率至少「中間」
            // （準時交貨率好只代表目前沒出事，不代表供應中斷「幾乎不可能」；備援廠商評估完成前不下調）
            $vs = $db->prepare("SELECT COUNT(*) n, MAX(c) m FROM (SELECT COUNT(*) c FROM bom_ing b JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no AND COALESCE(ml.internal,0)=0 AND ml.maker_id_no IN (" . $bnd . ")
                                WHERE b.maker_id_no<>'' AND b.process_no IN (" . $auditIn . ") AND b.outsource_date BETWEEN ? AND ? GROUP BY b.maker_id_no) t");
            $vs->execute([$dataYear . '-01-01', $dataYear . '-12-31 23:59:59']); $vr = $vs->fetch(PDO::FETCH_ASSOC);
            $vt = $db->prepare("SELECT COUNT(*) FROM bom_ing b JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no AND COALESCE(ml.internal,0)=0 AND ml.maker_id_no IN (" . $bnd . ")
                                WHERE b.maker_id_no<>'' AND b.process_no IN (" . $auditIn . ") AND b.outsource_date BETWEEN ? AND ?");
            $vt->execute([$dataYear . '-01-01', $dataYear . '-12-31 23:59:59']); $vall = (int)$vt->fetchColumn();
            if ($vall >= RM_SG_SINGLE_MIN && (int)$vr['m'] * 100 / $vall >= RM_SG_VENDOR_PCT) {
                $fromKpi = max($fromKpi ?? 0.5, 0.5); $why[] = $dataYear . ' 年對外宣稱委外製程中，單一廠商占 ' . rm_sg_f((int)$vr['m'] * 100 / $vall) . '%（≥ ' . RM_SG_VENDOR_PCT . '%），發生率至少取中間值';
                $ids[] = 'O-VENDOR';
            }
            break;
    }
    if ($fromKpi !== null) $occF = $fromKpi;

    // 嚴重度(S)與發生率(O)換算成「目前設定」的等級
    $impRows = array_values($set['rm_impact']['rows']); $likRows = array_values($set['rm_likelihood']);
    $sev = rm_rule_frac_to_v($impRows, $sevF); $occ = rm_rule_frac_to_v($likRows, $occF);
    $why[] = '嚴重度(S)比例 ' . rm_sg_f($sevF * 100, 0) . '% → 第 ' . $sev . ' 級；發生率(O)比例 ' . rm_sg_f($occF * 100, 0) . '% → 第 ' . $occ . ' 級';

    // 措施與成效確認方式
    $ms = $c['measures'];
    if (!empty($c['measures_kpi']) && $kpi) foreach ($c['measures_kpi'] as $kw => $mtxt) if (mb_strpos($kpi['name'], $kw) !== false) { $ms[0] = $mtxt; break; }
    $measure = implode('；', $ms);
    // 成效確認方式寫的是「這張表的年度」要達成的目標：KPI 目標值會逐年調整（例：客訴頻率 2025 是 ≤5%、2026 改成 ≤2 件），
    // 評分用的是前一年資料、但目標一定要寫當年度的，否則措施欄與日後的佐證會出現兩個不同的目標值。
    $kpiDoc = ($docYear !== null && $docYear !== $dataYear && $c['kpi']) ? rm_rule_kpi($db, $docYear, $c['kpi'], (string)($ctx['issue'] ?? '')) : null;
    $kpiT = $kpiDoc ?: $kpi;
    $kpiName = $kpiT ? $kpiT['name'] : ($c['kpi'] ? $c['kpi'][0] . '相關 KPI' : '');
    $metric = str_replace('{kpi}', $kpiName ? ' KPI「' . $kpiName . '」' . ($kpiT ? '（' . ($docYear ?: $dataYear) . ' 年度目標 ' . ($kpiT['gte'] ? '≥' : '≤') . rm_sg_f($kpiT['target'], 2) . $kpiT['unit'] . '）' : '') : '指標', $c['metric']);
    if (strpos($metric, '{share}') !== false) {
        [$sh0] = rm_rule_share($db, $dataYear);
        $metric = str_replace('{share}', $sh0 !== null ? rm_sg_f($sh0) . '%' : '以前一年為基準', $metric);
    }
    if ($kpiT && ($kpiT['n'] ?? 0) >= 3 && $kpi && $kpi['n'] >= 3 && $kpi['miss'] / $kpi['n'] >= 0.6) {
        // 資料年度已有六成以上月份未達標：一開始就要求「連續 3 個月達標」幾乎一定判無效，先要求逐月改善
        $metric = str_replace('回到年度目標並連續 3 個月達標', '逐月改善並朝年度目標邁進（最終目標：連續 3 個月達標）', $metric);
        $metric = str_replace('回升並維持達標', '逐月回升並朝年度目標邁進', $metric);
    }
    $ro = ((string)($ctx['dimension'] ?? '') === 'O' || $c['ro'] === 'opportunity') ? 'opportunity' : 'risk';
    return ['category'=>$cat, 'ro_kind'=>$ro, 'ro_text'=>$c['impact'], 'severity'=>$sev, 'frequency'=>$occ, 'measure'=>$measure, 'resources'=>str_replace('；', "\n", RM_RULE_RESOURCES[$cat] ?? RM_RULE_RESOURCES['generic']),
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

    $tracks = rm_rule_tracks($db, $year, $cat, (string)($row['measure'] ?? '') . ' ' . (string)($row['issue'] ?? ''), trim((string)($row['rule_ids'] ?? '')) === '' && !empty($row['item_id']));
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

    $kpi = ($c['kpi'] && $cat !== 'new_customer') ? rm_rule_kpi($db, $year, $c['kpi'], (string)($row['issue'] ?? '')) : null;
    if ($cat === 'quality') {
        $irq = $db->prepare("SELECT COUNT(*) FROM ir_track WHERE " . rm_audit_in($db,'d_setting_id') . " AND YEAR(IR_date)=?");
        $irq->execute([$year]); $i0 = (int)$irq->fetchColumn(); $irq->execute([$year - 1]); $i1 = (int)$irq->fetchColumn();
        $lines[] = $year . ' 年客戶退貨 ' . $i0 . ' 筆（前一年 ' . $i1 . ' 筆）';
    }
    if ($cat === 'concentration') {
        [$s0, $s1] = rm_rule_share($db, $year); $ids[] = 'E-CONC';
        if ($s0 !== null) {
            array_unshift($lines, '前三大客戶占 ' . $year . ' 年出貨 ' . rm_sg_f($s0) . '%' . ($s1 !== null ? '（前一年 ' . rm_sg_f($s1) . '%）' : '') . '；目標：控制在 50% 以下');
            if ($s0 < 50) { $status = 'effective'; $reason = '占比低於警戒線 50%'; }
            elseif ($s0 < 60) { $status = 'partial'; $reason = '占比超過 50% 警戒線但未達 60%'; }
            else { $status = 'ineffective'; $reason = '占比達 60% 以上'; }
        } else $reason = '當年出貨資料不足';
    } elseif ($cat === 'existing_customer') {
        $mmdd = $year === (int)substr($today, 0, 4) ? substr($today, 5, 5) : '12-31';
        [$a0, $a1, $n0] = rm_rule_existing($db, $year, $mmdd); $ids[] = 'E-EXISTING';
        array_unshift($lines, $year . ' 年（1/1～' . str_replace('-', '/', $mmdd) . '）既有客戶（' . $n0 . ' 家）稽核製程出貨金額 ' . number_format($a0) . ' 元；前一年同期 ' . number_format($a1) . ' 元');
        if ($a1 <= 0) { $status = 'unconfirmed'; $reason = '前一年同期沒有可比較的既有客戶出貨金額'; }
        elseif ($a0 >= $a1) { $status = 'effective'; $reason = '既有客戶出貨金額不低於前一年同期'; }
        elseif ($a0 >= $a1 * 0.9) { $status = 'partial'; $reason = '既有客戶出貨金額略低於前一年同期（差距在 10% 內）'; }
        else { $status = 'ineffective'; $reason = '既有客戶出貨金額低於前一年同期超過 10%'; }
    } elseif ($cat === 'new_customer') {
        // 當年度還沒過完時，前一年也只比到同一天（不拿半年去比整年）
        $mmdd = $year === (int)substr($today, 0, 4) ? substr($today, 5, 5) : '12-31';
        [$n0, $a0] = rm_rule_newcust($db, $year, $mmdd); [$n1, $a1] = rm_rule_newcust($db, $year - 1, $mmdd); $ids[] = 'E-NEWCUST';
        // 不顯示金額，改顯示新客戶出貨占稽核製程出貨的比例（判定仍用金額比較）
        $tt = function ($y) use ($db, $mmdd) {
            return (float)$db->query("SELECT SUM(Qty*Unit_price) FROM is_list WHERE " . rm_audit_in($db, 'd_setting_id') . " AND Order_date BETWEEN '$y-01-01' AND '$y-$mmdd'")->fetchColumn();
        };
        $pc = function ($a, $t) { return $t > 0 ? '占稽核製程出貨 ' . rm_sg_f($a * 100 / $t) . '%' : '無可比較的出貨'; };
        array_unshift($lines, $year . ' 年（1/1～' . str_replace('-', '/', $mmdd) . '）首次出貨客戶 ' . $n0 . ' 家，' . $pc($a0, $tt($year)) . '；前一年同期 ' . $n1 . ' 家，' . $pc($a1, $tt($year - 1)));
        [$cn, $cd, $cm] = rm_rule_newcust_comm($db, $year, $mmdd); $ids[] = 'E-NEWCOMM';
        $lines[] = '新客戶 ' . $cn . ' 家中，' . $cd . ' 家於首次出貨當日起 30 天內留有溝通記錄' . ($cm ? '；尚無溝通記錄：' . implode('、', array_slice($cm, 0, 6)) . (count($cm) > 6 ? ' 等 ' . count($cm) . ' 家' : '') : '');
        $good = ($n0 >= $n1 ? 1 : 0) + ($a0 >= $a1 ? 1 : 0);
        $status = $good == 2 ? 'effective' : ($good == 1 ? 'partial' : 'ineffective');
        $reason = $good == 2 ? '首次出貨客戶數與金額皆不低於前一年' : ($good == 1 ? '客戶數或金額其中一項不低於前一年' : '客戶數與金額皆低於前一年');
        if ($cn > 0 && $cd < $cn) {      // 措施「首次出貨 30 天內溝通」沒有全部做到：最多部分有效
            if ($status === 'effective') $status = 'partial';
            $reason .= '；新客戶 ' . $cn . ' 家中有 ' . ($cn - $cd) . ' 家尚無 30 天內的溝通記錄';
        }
    } elseif ($kpi && $kpi['n'] >= 1 && ($c['by'] ?? 'kpi') === 'kpi') {
        $ids[] = 'E-KPI';
        array_unshift($lines, rm_rule_kpi_txt($kpi));
        if ($kpi['n'] < 3) { $status = 'unconfirmed'; $reason = $kpi['name'] . ' 目前只有 ' . $kpi['n'] . ' 個月資料，不足 3 個月無法判斷'; }
        elseif ($kpi['met3']) { $status = 'effective'; $reason = $year . ' 年 ' . $kpi['last3_txt'] . '（連續 3 個月）' . $kpi['name'] . ' 全部達標'; }
        elseif ($open && !$over) { $status = 'doing'; $reason = $kpi['name'] . ' 尚未連續 3 個月達標，但措施追蹤仍在執行中'; }
        elseif ($kpi['improved'] || ($kpi['n'] - $kpi['miss']) > 0 && $kpi['last_ok']) { $status = 'partial'; $reason = $kpi['name'] . ' 較前期改善或已有月份達標，但尚未連續 3 個月達標'; }
        else { $status = 'ineffective'; $reason = $kpi['name'] . ' 未改善' . ($tracks && !$open ? '，而相關措施已執行完畢' : ''); }
        // 單一來源／備援廠商：準時交貨率好只代表目前沒出事；措施要求「備援廠商評估」，沒有任何措施追蹤佐證就不能判有效
        if ($cat === 'supplier' && !$tracks && $status === 'effective') { $status = 'partial'; $reason .= '；但備援廠商評估尚無對應的措施追蹤紀錄，無法證明降低單一來源風險'; }
    } else {
        $ids[] = 'E-TRACK';
        if ($kpi && $kpi['n'] >= 1) array_unshift($lines, '（參考）' . rm_rule_kpi_txt($kpi));
        [$status, $reason] = $trackVerdict();
        if ($cat === 'climate') { $d = rm_rule_typhoon($db, $year); array_unshift($lines, $year . ' 年行事曆颱風假 ' . $d . ' 天'); }
    }
    if (!$lines) $lines[] = '系統找不到對應資料（沒有對應的 KPI、措施追蹤或溝通記錄）';
    // 判無效的前提是「措施確實執行過（或逾期未做）而指標沒改善」。完全沒有措施追蹤可對照時，
    // 不能直接判無效——那等於還沒開始做就開矯正單；改回「未確認」並提示先建立措施追蹤。
    if ($status === 'ineffective' && !$tracks) {
        $status = 'unconfirmed';
        $reason = '指標尚未改善，但找不到對應的措施追蹤、無法確認措施是否已執行；請先在溝通管理建立措施追蹤再確認成效（原判定依據：' . $reason . '）';
    }
    // 佐證本文只放資料本身（簡潔）；「系統建議結果／規則編號」顯示在畫面上，規則編號另存在 rule_ids 欄位，不寫進佐證文字
    $lines[] = '資料截至 ' . $today;

    // 重新評估提示：用「現在」的資料再算一次發生率，比表上的高（例：年初評為幾乎不可能、今年卻已經發生）就提醒要不要重新評估
    $hint = '';
    try {
        if ($row['frequency'] !== null && $row['frequency'] !== '' && ($row['severity'] ?? '') !== '') {
            $ctx = ['stakeholder'=>$row['stakeholder'] ?? '', 'needs'=>$row['needs'] ?? '', 'issue'=>$row['issue'] ?? '', 'dimension'=>$row['dimension'] ?? ''];
            $now = rm_rule_make_row($db, $set, $ctx, $year, $year);
            if ((int)$now['frequency'] > (int)$row['frequency'])
                $hint = '依 ' . $year . ' 年資料，發生率建議由 ' . (int)$row['frequency'] . ' 提高為 ' . (int)$now['frequency'] . '（' . implode('；', array_slice($now['why'], 1, 2)) . '）；請評估是否在「重新評估」欄記錄並調整評分。';
        }
    } catch (Throwable $e) { rm_sg_err($e); }
    return ['lines'=>$lines, 'text'=>implode("\n", $lines), 'status'=>$status, 'reason'=>$reason, 'rule_ids'=>implode(',', $ids), 'category'=>$cat, 'recheck_hint'=>$hint];
}

/* ============================ 風險列的措施追蹤（顯示用） ============================
 * 風險表本身不另外填負責單位與預計完成日——那些資料已經在溝通管理的「措施追蹤」裡，
 * 兩邊各填一次遲早對不起來（鐵律4）。這裡即時把相關追蹤項目查出來顯示在因應措施底下。 */
if (!function_exists('rm_item_tracks')) {
function rm_item_tracks(PDO $db, int $year, array $row): array
{
    $cx = (int)($row['ctx_item_id'] ?? 0);
    $r = $row;
    if ($cx > 0) {
        $q = $db->prepare("SELECT dimension, stakeholder, needs, issue FROM rm_item WHERE item_id=?"); $q->execute([$cx]);
        if ($c0 = $q->fetch(PDO::FETCH_ASSOC)) foreach (['dimension', 'stakeholder', 'needs', 'issue'] as $k) $r[$k] = $c0[$k];
    }
    [$cat] = rm_rule_classify(trim(($r['stakeholder'] ?? '') . ' ' . ($r['needs'] ?? '') . ' ' . ($r['issue'] ?? '')), (string)($r['dimension'] ?? ''));
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $out = [];
    foreach (rm_rule_tracks($db, $year, $cat, (string)($row['measure'] ?? '') . ' ' . (string)($row['issue'] ?? ''), trim((string)($row['rule_ids'] ?? '')) === '' && !empty($row['item_id']), true) as $t) {
        $st = (int)$t['is_closed'] ? 'closed' : (($t['due_date'] && $t['due_date'] < $today) ? 'over' : 'open');
        $out[] = ['id'=>(int)$t['track_id'], 'no'=>(string)(!empty($t['track_no']) ? $t['track_no'] : '#' . $t['track_id']), 'owner'=>(string)$t['owner_name'], 'owner_dept'=>(string)($t['owner_dept_name'] ?? ''), 'due'=>(string)($t['due_date'] ?? ''),
                  'state'=>$st, 'action'=>(string)$t['action'], 'rec_no'=>(string)($t['rec_no'] ?? '')];
    }
    return $out;
}
}

/* ============================ 成效確認「自動建立完整」 ============================
 * 產生因應措施的當下就一併建立成效確認：結果（系統建議）、確認日期、主管裁示（建議文字）、佐證（資料快照）。
 * 這些都只是「系統建議」，風險表本身若是草稿要經使用者確認；直接產生到正式表上的，主管看過可再修改。
 * 規則算不出結果（資料不足、找不到措施追蹤）時只寫佐證與原因，不硬塞結果。 */
const RM_EFFECT_RULING_SUGGEST = [
    'effective' => '經確認措施已落實，指標或追蹤項目達成，本項成效有效；維持現行作法並納入下年度持續監控。',
    'partial' => '部分達成；要求負責人於下次確認前補強措施並持續追蹤。',
    'doing' => '措施執行中，尚未到可判定成效的時點；維持追蹤至下次確認。',
    'ineffective' => '依規則判定措施已執行（或逾期未做）但指標沒有改善，成效不足；請主管裁示是否開立異常矯正處理單並調整措施。',
];
function rm_effect_autofill(PDO $db, array $set, array $row, int $year, string $date): array
{
    $ev = rm_rule_evidence($db, $set, $row, $year);
    $ok = isset(RM_EFFECT_RULING_SUGGEST[$ev['status']]);
    return ['effect_status' => $ok ? $ev['status'] : null, 'effect_date' => $ok ? $date : null,
            'effect_ruling' => $ok ? RM_EFFECT_RULING_SUGGEST[$ev['status']] : '', 'effect_evidence' => (string)$ev['text'],
            'reason' => (string)$ev['reason'], 'status' => $ev['status']];
}

/* ============================ 處境分析表與前一年度比較（2-GM-05 5.1 b：內外部議題的變更） ============================
 * 逐項標示新增／變更／沿用，並列出前一年有、今年沒有的議題。比對鍵＝象限＋需求與期望（議題描述改了＝變更）。 */
function rm_ctx_compare(PDO $db, int $docId, string $fillDate): array
{
    $none = ['prev_year'=>null, 'marks'=>[], 'removed'=>[], 'counts'=>['new'=>0, 'changed'=>0, 'same'=>0, 'removed'=>0]];
    $st = $db->prepare("SELECT doc_id, YEAR(fill_date) y FROM rm_doc WHERE kind='context' AND is_deleted=0 AND doc_id<>? AND fill_date<? ORDER BY fill_date DESC, doc_id DESC LIMIT 1");
    $st->execute([$docId, $fillDate]);
    $prev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$prev) return $none;
    $norm = function ($s) { return preg_replace('/\s+/u', '', (string)$s); };
    $q = $db->prepare("SELECT item_id, dimension, stakeholder, needs, issue FROM rm_item WHERE doc_id=? ORDER BY seq, item_id");
    $q->execute([(int)$prev['doc_id']]); $P = $q->fetchAll(PDO::FETCH_ASSOC);
    $q->execute([$docId]); $N = $q->fetchAll(PDO::FETCH_ASSOC);
    $pk = []; foreach ($P as $r) $pk[$r['dimension'] . '|' . $norm($r['needs'])][] = $r;
    $used = []; $marks = []; $cnt = ['new'=>0, 'changed'=>0, 'same'=>0, 'removed'=>0];
    foreach ($N as $r) {
        $k = $r['dimension'] . '|' . $norm($r['needs']); $m = 'new';
        foreach ($pk[$k] ?? [] as $p) {
            if (isset($used[$p['item_id']])) continue;
            $used[$p['item_id']] = true;
            $m = $norm($p['issue']) === $norm($r['issue']) ? 'same' : 'changed'; break;
        }
        $marks[(int)$r['item_id']] = $m; $cnt[$m]++;
    }
    $removed = [];
    foreach ($P as $p) if (!isset($used[$p['item_id']])) { $removed[] = ['dimension'=>$p['dimension'], 'stakeholder'=>$p['stakeholder'], 'needs'=>$p['needs'], 'issue'=>mb_substr((string)$p['issue'], 0, 80)]; $cnt['removed']++; }
    return ['prev_year'=>(int)$prev['y'], 'marks'=>$marks, 'removed'=>$removed, 'counts'=>$cnt];
}

/* ============================ 管理審查管理程序（2-GM-05）條文提示 ============================
 * 處境分析表／風險表是管理審查（6.1.4 輸入、6.1.5 輸出）的資料來源。條文文字「即時」取自 AS 文件線上版的最新內容
 * （程序書改版、條號變動時這裡自動跟著變，不在程式裡抄一份），並對每一條說明「這兩張表怎麼對應」。 */
function rm_clause_hints(PDO $db): array
{
    $map = [
        ['kw' => '要求變化', 'how' => '處境分析表的外部議題（國際局勢、極端氣候、環境議題、客戶集中度）與「職業安全衛生」議題，涵蓋品質、安全、環境三個面向，每年一定要列入評估。'],
        ['kw' => '資源的需求', 'how' => '風險與機會因應措施管制表每一條都有「所需資源」欄（人員、設施、工作環境、方法），管理審查可直接據以決議。'],
        ['kw' => '風險識別', 'how' => '風險與機會因應措施管制表就是這項輸出：每條議題評嚴重度(S)與發生率(O)得風險等級，需因應措施者配發編號追蹤。'],
        ['kw' => '議題的變更', 'how' => '處境分析表每一條議題會與前一年度比較，標示「新增／變更／沿用」，並列出已刪除的議題。'],
        ['kw' => '行動的有效性', 'how' => '風險表的「成效確認」：產生因應措施時就依 KPI 與措施追蹤自動建立結果、佐證與裁示建議，主管看過確認；管審會議要項可帶入前一年的成效。'],
        ['kw' => '預防和矯正措施', 'how' => '成效確認結果為「無效」時，可直接開立異常矯正處理單，狀況在管審時一併報告。'],
        ['kw' => '目標執行狀況', 'how' => 'KPI 年度目標未達標的指標會列為劣勢，並連到風險表；成效確認方式寫明當年度目標。'],
        ['kw' => '供應商的績效', 'how' => '供應商績效（廠商準時交貨率）未達標，或委外集中於單一廠商時，列為供應商議題。'],
        ['kw' => '前次管理審查', 'how' => '措施追蹤（溝通管理）逾期未結案會列入；管審會議要項會帶入追蹤情形。'],
        ['kw' => '次年度目標', 'how' => '次年度 KPI 目標由 KPI 模組設定；風險表成效確認方式一律寫該年度目標。'],
    ];
    $proc = ['doc_no' => '2-GM-05', 'name' => '管理審查管理程序', 'version' => ''];
    $lines = [];
    try {
        $r = $db->query("SELECT c.content_html, v.version, d.doc_name FROM as_document d JOIN as_doc_content c ON c.doc_id=d.id JOIN as_document_version v ON v.id=c.version_id
                         WHERE d.doc_no='2-GM-05' ORDER BY c.version_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $proc['version'] = (string)$r['version']; $proc['name'] = (string)$r['doc_name'];
            $t = html_entity_decode(strip_tags(preg_replace('/<\/(p|div|tr|h\d|li)>|<br\s*\/?>/i', "\n", (string)$r['content_html'])), ENT_QUOTES, 'UTF-8');
            foreach (preg_split('/\R/u', $t) as $l) { $l = trim(preg_replace('/[ \t\x{3000}]+/u', ' ', $l)); if ($l !== '') $lines[] = $l; }
        }
    } catch (Throwable $e) { rm_sg_err($e); }
    $items = []; $pending = '';
    $nl = count($lines);
    foreach ($map as $m) {
        $hit = '';
        for ($i = 0; $i < $nl; $i++) {
            if (mb_strpos($lines[$i], $m['kw']) === false) continue;
            $l = $lines[$i];
            // 5.1 表格的「a)、b)」常與內容分在不同行：前一行若只是編號就併進來
            if (preg_match('/^[a-z]\)$|^\d+\)$/u', $lines[$i - 1] ?? '')) $l = $lines[$i - 1] . ' ' . $l;
            $hit = $l; break;
        }
        $items[] = ['clause' => $hit, 'how' => $m['how']];
    }
    return ['proc' => $proc, 'items' => $items];
}

/* ============================ 處境分析表每一項對應的程序書條文（2-GM-05） ============================
 * 條文號碼與文字「即時」取自 AS 文件線上版目前內容（用關鍵字找那一行；程序書改版、條號變動自動跟著變；
 * 程序書裡找不到那一條時，該條文從選單消失）。每個條文只出現在「適用的象限」：不適合的象限不會出現這個選項。
 * 自動對應用 akw（議題文字關鍵字）由前端判定，使用者可改選或選「不對應」。 */
function rm_proc_lines(PDO $db): array
{
    $proc = ['doc_no' => '2-GM-05', 'name' => '管理審查管理程序', 'version' => '']; $lines = [];
    try {
        $r = $db->query("SELECT c.content_html, v.version, d.doc_name FROM as_document d JOIN as_doc_content c ON c.doc_id=d.id JOIN as_document_version v ON v.id=c.version_id
                         WHERE d.doc_no='2-GM-05' ORDER BY c.version_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $proc['version'] = (string)$r['version']; $proc['name'] = (string)$r['doc_name'];
            $t = html_entity_decode(strip_tags(preg_replace('/<\/(p|div|tr|h\d|li)>|<br\s*\/?>/i', "\n", (string)$r['content_html'])), ENT_QUOTES, 'UTF-8');
            foreach (preg_split('/\R/u', $t) as $l) { $l = trim(preg_replace('/[ \t\x{3000}]+/u', ' ', $l)); if ($l !== '') $lines[] = $l; }
        }
    } catch (Throwable $e) { rm_sg_err($e); }
    return [$proc, $lines];
}
function rm_clause_catalog(PDO $db): array
{
    // key => [lkw 程序書那一行的關鍵字, dims 適用象限, akw 自動對應關鍵字, hint 該條文要看什麼／怎麼寫, ex 各象限範例]
    $def = [
        'audit' => ['lkw' => '稽核結果報告', 'dims' => ['S', 'W'], 'akw' => ['稽核', '不符合'],
            'hint' => '管理審查要看內外部稽核結果。優勢：連續無重大不符合、稽核制度運作成熟；劣勢：重複出現的不符合項目、矯正完成時間過長。寫「目前的狀況」，不要寫要怎麼改善。',
            'ex' => ['S' => ['客戶、驗證機構', '品質系統持續符合 AS9100 要求', '內外部稽核近年沒有重大不符合，品質系統運作成熟'],
                     'W' => ['客戶、驗證機構', '稽核發現能如期改善', '內部稽核的不符合項目有重複出現的情形，矯正措施完成時間偏長']]],
        'complaint' => ['lkw' => '客訴統計', 'dims' => ['S', 'W'], 'akw' => ['客訴', '退貨', '客戶抱怨'],
            'hint' => '看客訴件數、趨勢與主要原因分類。優勢：客訴少且逐年下降；劣勢：特定原因重複發生、客訴處理時間長。',
            'ex' => ['S' => ['客戶', '低客訴、不良品不外流', '客訴件數連續兩年低於年度目標，處理時效符合客戶要求'],
                     'W' => ['客戶', '低客訴、不良品不外流', '客訴集中在加工尺寸與外觀，同一類原因重複出現']]],
        'satisfaction' => ['lkw' => '客戶滿意度統計', 'dims' => ['S', 'W'], 'akw' => ['滿意度'],
            'hint' => '看客戶滿意度（品質、交期、服務、價格）的分數與趨勢。優勢：分數穩定偏高；劣勢：某一項分數偏低或逐年下滑。',
            'ex' => ['S' => ['客戶', '穩定的品質與準時交貨', '主要客戶的滿意度調查分數穩定，品質與交期項目評價高'],
                     'W' => ['客戶', '合理的價格與回覆速度', '客戶滿意度調查中「回覆速度」一項分數偏低']]],
        'supplier' => ['lkw' => '供應商的績效', 'dims' => ['W', 'T'], 'akw' => ['供應商', '委外', '廠商', '單一來源', '單一廠商'],
            'hint' => '看委外與供應商的準時交貨、品質與依賴程度。劣勢：廠內單點依賴；威脅：外部供應商交期不穩、單一來源、刀具砂輪供貨。',
            'ex' => ['W' => ['供應商', '穩定的委外加工來源', '特定製程只有一家委外廠商，缺少備援'],
                     'T' => ['供應商', '合理的交期與訂單預告', '委外廠商交期不穩，使訂單交期受影響']]],
        'objectives' => ['lkw' => '品質目標達成狀況', 'dims' => ['S', 'W'], 'akw' => ['KPI', '未達年度目標', '達成率', '績效指標', '品質目標', '目標'],
            'hint' => '看各部門品質目標（KPI）的達成狀況，尤其是製造過程的能力。優勢：指標持續達標；劣勢：指標未達年度目標（寫出是哪個指標、截至幾月）。',
            'ex' => ['S' => ['客戶', '品質穩定、量測可追溯', '關鍵績效指標持續達標，品質與交付穩定'],
                     'W' => ['公司內部（業務課）', '穩定的訂單與營收（公司年度目標）', '月銷貨額達成率未達年度目標，截至 9 月仍未達標']]],
        'corrective' => ['lkw' => '預防和矯正措施', 'dims' => ['W'], 'akw' => ['矯正', '品質異常', '不良', '異常'],
            'hint' => '看矯正與預防措施的開立、結案狀況。劣勢：同類異常重複發生、矯正單逾期未結案、成效確認為「無效」。',
            'ex' => ['W' => ['公司內部（品管課、生產課）', '品質穩定、不良品不外流', '同一類加工異常重複發生，矯正措施未能根除原因']]],
        'other_sys' => ['lkw' => '其他相關品質系統運作狀況', 'dims' => ['W', 'T'], 'akw' => ['計畫變動', '計劃變動', '搬遷', '新設備', '組織調整'],
            'hint' => '看可能影響品質管理系統的計畫變動（設備、人員、組織、廠區、環境審查與防護審核的結果）。劣勢：變動帶來的運作風險；威脅：外部造成的計畫變動。',
            'ex' => ['W' => ['公司內部', '品質系統在變動期間仍有效運作', '預計新增設備與人員異動，作業標準與訓練需同步調整'],
                     'T' => ['政府單位、客戶', '符合新法規與客戶新要求', '外部法規或客戶要求即將變動，需調整現有作業']]],
        'followup' => ['lkw' => '前次管理審查', 'dims' => ['W'], 'akw' => ['逾期', '承諾事項', '前次管理審查', '追蹤行動'],
            'hint' => '看前次管理審查決議的追蹤與完成情形。劣勢：決議事項或措施追蹤逾期未結案。',
            'ex' => ['W' => ['公司內部', '承諾事項如期完成', '前次管理審查的決議事項與措施追蹤有逾期未結案的項目']]],
        'market' => ['lkw' => '要求變化', 'dims' => ['W', 'O', 'T'], 'akw' => ['國際局勢', '極端氣候', '颱風', '豪雨', '氣候', '環保', '減碳', '環境議題', '法規', '客戶集中', '作業安全', '職業安全', '職業災害', '市場', '關稅', '通膨', '戰爭'],
            'hint' => '客戶／市場要求的變化對本廠的風險與影響，要涵蓋「品質、安全、環境」：國際局勢（戰爭、關稅、通膨、匯率）、極端氣候與環保減碳、職業安全衛生、客戶集中與客戶新要求。外部變化多寫成威脅／機會，內部因應不足寫成劣勢。',
            'ex' => ['T' => ['國際環境（客戶、營運成本）', '客戶訂單與合理的加工單價、可控的營運成本', '國際局勢使客戶壓低加工單價或減少、延後下單，同時能源與耗材成本上升，壓縮利潤'],
                     'O' => ['客戶', '符合客戶新增的環境與品質要求', '客戶提出新的環保或品質要求，符合者可取得更多訂單'],
                     'W' => ['員工、政府單位（勞動檢查機構）', '安全的工作環境、符合職業安全衛生法規', '加工現場的作業安全與防護、教育訓練不足，可能發生職業災害']]],
        'improve_qms' => ['lkw' => '過程有效性的改善', 'dims' => ['W', 'O'], 'akw' => ['有效性', '流程簡化', '整合'],
            'hint' => '管理審查要決議品質管理系統及其過程如何改善。劣勢：現有流程重複、資料分散；機會：整合流程、導入系統化管理。',
            'ex' => ['W' => ['公司內部', '簡化且有效的作業流程', '部分表單與流程重複，資料分散在不同單據'],
                     'O' => ['公司內部', '以系統管理取代紙本', '已有網頁化表單與資料整合基礎，可再擴大電子化範圍']]],
        'customer_improve' => ['lkw' => '顧客要求關於產品服務的改善', 'dims' => ['W', 'O'], 'akw' => ['新客戶', '詢價', '既有客戶', '同類齒輪', '快速評估', '更多加工訂單'],
            'hint' => '客戶對產品與服務提出的要求，以及可以因此爭取的商機。機會：新客戶詢價、既有客戶擴單；劣勢：回覆與評估速度不足。',
            'ex' => ['O' => ['客戶', '更多加工訂單', '稽核製程已有穩定訂單，可向既有客戶爭取更多同類齒輪加工訂單'],
                     'W' => ['客戶', '接到訂單時快速評估並回覆交期', '詢價與接單評估需跨部門確認，回覆時間偏長']]],
        'resource' => ['lkw' => '資源的需求', 'dims' => ['W', 'O'], 'akw' => ['人力', '設備', '產能集中', '資源', '人員異動', '不足'],
            'hint' => '管理審查要對資源需求（人員、設施、工作環境、方法）做決議。劣勢：關鍵人力或設備集中、不足；機會：投入資源即可擴大的商機。',
            'ex' => ['W' => ['公司內部（生管組、生產課）', '依約定交期與品質交貨', '加工皆由廠內製程承擔，產能、設備與人力集中，設備故障或人員異動會直接影響交期'],
                     'O' => ['客戶', '更高產能與更短交期', '增加關鍵設備或人力即可承接更多訂單']]],
        'risk_id' => ['lkw' => '風險識別', 'dims' => ['W', 'O', 'T'], 'akw' => [],
            'hint' => '這一項要列入風險與機會的識別（管理審查輸出）。寫清楚「發生什麼事、影響什麼」，之後會由處境分析表產生風險與機會因應措施管制表。',
            'ex' => []],
        'next_goal' => ['lkw' => '次年度目標', 'dims' => ['W', 'O'], 'akw' => [],
            'hint' => '這一項會影響次年度品質目標的制定或修訂。劣勢：目標長期未達、需要調整；機會：可以提高目標的項目。',
            'ex' => ['W' => ['公司內部', '可達成且有挑戰性的年度目標', '部分年度目標連續兩年未達，需要檢視目標值是否合理'],
                     'O' => ['客戶', '更高的品質與交期績效', '績效長期超過目標，可提高次年度目標值']]],
    ];
    // 「處境分析表應該涵蓋」的管理審查輸入條文（輸出類——資源需求、風險識別、次年度目標——不在這裡檢查）；
    // 6.1.4.9 另外要求涵蓋「品質、安全、環境」三個面向，加上本公司要求必列的國際局勢
    $check = ['audit', 'complaint', 'satisfaction', 'supplier', 'objectives', 'corrective', 'other_sys', 'followup', 'market'];
    $aspects = ['market' => [['品質', ['品質', '檢驗', '客戶要求', '不良']], ['安全', ['安全', '職災']], ['環境', ['環境', '環保', '減碳', '氣候', '碳', '颱風', '豪雨']], ['國際局勢', ['國際局勢', '戰爭', '關稅', '通膨', '匯率']]]];
    [$proc, $lines] = rm_proc_lines($db);
    $out = [];
    foreach ($def as $key => $d) {
        $hit = '';
        foreach ($lines as $l) if (preg_match('/^6\.\d/u', $l) && mb_strpos($l, $d['lkw']) !== false) { $hit = $l; break; }   // 只認「6.」作業內容的條文（權責 3.x、過程輸入 5.x 的敘述不是）
        if ($hit === '' && $lines) continue;            // 程序書目前沒有這一條：不出現在選單
        $no = ''; $txt = $hit;
        if (preg_match('/^(\d+(?:\.\d+)+)\s*(.*)$/u', $hit, $m)) { $no = $m[1]; $txt = $m[2]; }
        $out[] = ['key' => $key, 'no' => $no, 'text' => preg_replace('/[。：:]+$/u', '', $txt), 'dims' => $d['dims'], 'akw' => $d['akw'], 'hint' => $d['hint'], 'ex' => (object)$d['ex'], 'check' => in_array($key, $check, true), 'aspects' => $aspects[$key] ?? []];
    }
    return ['proc' => $proc, 'clauses' => $out];
}
/** 儲存時檢查：條文存在於目前的程序書、而且適用該象限 */
function rm_clause_valid(PDO $db, string $key, string $dim): bool
{
    foreach (rm_clause_catalog($db)['clauses'] as $c) if ($c['key'] === $key) return in_array($dim, $c['dims'], true);
    return false;
}
