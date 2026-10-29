<?php
/**
 * 風險與機會管理 共用庫 —— 2026-10-14 建立
 * ------------------------------------------------------------------
 * 一頁兩分頁（頁中分頁，views/GM/risk_mgmt.php）：
 *   組織處境分析表                 （原 2-GM-01-01）
 *   風險與機會因應措施管制表       （合併原 2-GM-01-02 策略行動措施管制表 ＋ 2-GM-03-01 風險評估及處理表）
 * 表單名稱與文件編號一律由「綁定的 AS 文件」自動取得（AS 文件改名後這裡跟著變，禁寫死）。
 * 利害關係者溝通記錄已經做在「溝通管理」頁（views/GM/communication_mgmt.php），這裡只放快捷轉跳。
 *
 * 風險評估的四種設定對應「2-GM-03 風險管理程序」的四張表，**全部由管理員在模組設定維護，
 * 程式裡不寫死任何一級的名稱、分數或門檻**：
 *   表A 風險準則   rm_levels      風險等級（名稱、起算分數、處理措施、是否需要因應措施）
 *   表B 嚴重度(S)分析   rm_impact      嚴重度(S)等級（值、名稱）＋可自訂的說明欄（預設：公司形象／財物損失／客戶抱怨件數）
 *   表C 發生率(O)分析 rm_likelihood  發生率(O)等級（值、名稱、發生機率百分比、詳細描述）
 *   表D 風險分布   rm_method      嚴重度(S)×發生率(O)的分數算法 multiply(相乘，程序書採此) / add / max；矩陣由 A～C 自動產生
 * 風險等級**一律讀取當下設定即時算出、不存進資料列**——設定改了，舊資料的等級就跟著重算
 * （存下來的話，改了門檻之後舊資料還顯示舊等級而且不報錯＝鐵律4）。
 * 唯一會存下來的是「風險確認編號」（配出去的編號不可因設定變動而消失）。
 *
 * 相關規則：ai-rules/16(列印)、20(日期顯示)、23(列印紀錄)
 */

require_once __DIR__ . '/comm_mgmt_lib.php';     // 借用 cm_current_user／cm_dept_list／cm_identities 等，不另寫一份
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';

const RM_ASDOC_MODULES = [
    'context' => ['module' => 'rm_context', 'label' => '組織處境分析表',           'fallback' => '2-GM-01-01'],
    'risk'    => ['module' => 'rm_risk',    'label' => '風險與機會因應措施管制表', 'fallback' => '2-GM-01-02／2-GM-03-01'],
];
const RM_SETTING_GROUP = 'RISK_MGMT';
const RM_SCOPES   = ['internal' => '內部', 'external' => '外部'];
const RM_RO_KINDS = ['risk' => '風險', 'opportunity' => '機會'];
/** SWOT 四象限：key 固定（資料存 key，不存名稱，名稱日後改了舊資料不會對不上）；內部／外部由 key 決定，不給使用者手選 */
const RM_SWOT = [
    'S' => ['scope' => 'internal', 'en' => 'Strengths'],
    'W' => ['scope' => 'internal', 'en' => 'Weaknesses'],
    'O' => ['scope' => 'external', 'en' => 'Opportunities'],
    'T' => ['scope' => 'external', 'en' => 'Threats'],
];
/** 風險等級的固定暖色盤（ai-rules/10）：依「等級由低到高的順位」取色，不是設定值；超過 4 級時最高色沿用 */
const RM_LEVEL_COLORS = [
    ['bg' => '#F7E0BD', 'fg' => '#5b3a1e'],
    ['bg' => '#F0A24B', 'fg' => '#5b3a1e'],
    ['bg' => '#DD5138', 'fg' => '#ffffff'],
    ['bg' => '#8B2E1E', 'fg' => '#ffffff'],
];
/* 機會等級用「綠色系」（與風險的橘紅色系明顯區分；風險的顏色不動）：由淺到深＝低度機會→高度機會 */
const RM_LEVEL_COLORS_OPP = [
    ['bg' => '#DDF0E3', 'fg' => '#1f4d2e'],
    ['bg' => '#9ED3B0', 'fg' => '#1f4d2e'],
    ['bg' => '#3E9B6B', 'fg' => '#ffffff'],
    ['bg' => '#1E6B47', 'fg' => '#ffffff'],
];

/** 設定預設值（只是「第一次使用時的起點」，管理員改了就以資料庫為準；陣列型一律存 JSON） */
function rm_settings_default(): array
{
    return [
        // 表B 嚴重度(S)分析（2-GM-03 表B）
        'rm_impact' => [
            'columns' => ['公司形象', '財物損失', '客戶抱怨件數'],
            'rows' => [
                ['v'=>1, 'label'=>'輕微',     'cells'=>['單一部門負面形象', '10萬以下', '1家客戶']],
                ['v'=>2, 'label'=>'中等',     'cells'=>['跨部門負面形象', '20萬以下 10萬(含)以上', '2家客戶']],
                ['v'=>3, 'label'=>'嚴重',     'cells'=>['公司整體負面形象', '50萬以下 20萬(含)以上', '3家客戶']],
                ['v'=>4, 'label'=>'非常嚴重', 'cells'=>['公司整體負面形象並影響客戶信任', '50萬(含)以上', '4家(含)客戶以上']],
            ],
        ],
        // 表C 發生率(O)分析（2-GM-03 表C）：等級數字由系統依順序自動給（1,2,3…），發生機率百分比是下限～上限（整數）
        'rm_likelihood' => [
            ['v'=>1, 'label'=>'幾乎不可能', 'prob_lo'=>0,  'prob_hi'=>25,  'prob'=>'0-25%',   'desc'=>'只會在特殊的情況下發生'],
            ['v'=>2, 'label'=>'不太可能',   'prob_lo'=>26, 'prob_hi'=>50,  'prob'=>'26-50%',  'desc'=>'少數情況下會發生'],
            ['v'=>3, 'label'=>'可能',       'prob_lo'=>51, 'prob_hi'=>75,  'prob'=>'51-75%',  'desc'=>'有些情況下會發生'],
            ['v'=>4, 'label'=>'幾乎確定',   'prob_lo'=>76, 'prob_hi'=>100, 'prob'=>'76-100%', 'desc'=>'在大部分的情況下會發生'],
        ],
        // 表D 風險分布：分數算法（程序書 6.3.3：嚴重度(S)等級×發生率(O)等級）
        'rm_method' => 'multiply',
        // 表A 風險準則（2-GM-03 表A）：評分是「下限～上限」區間，區間之間連續不留縫隙、最後一級的上限＝目前可能的最高分
        'rm_levels' => [
            ['name'=>'低度危險', 'lo'=>1,  'hi'=>2,  'min'=>1,  'action'=>false, 'measure'=>'低度危險的風險，以一般步驟處理',
             'name_opp'=>'低度機會', 'measure_opp'=>''],
            ['name'=>'中度危險', 'lo'=>3,  'hi'=>5,  'min'=>3,  'action'=>false, 'measure'=>'必須明定管理階層的責任範圍，持續監控',
             'name_opp'=>'中度機會', 'measure_opp'=>''],
            ['name'=>'高度危險', 'lo'=>6,  'hi'=>9,  'min'=>6,  'action'=>true,  'measure'=>'管理階層需督導所屬研擬計畫並提供資源',
             'name_opp'=>'高度機會', 'measure_opp'=>''],
            ['name'=>'極度危險', 'lo'=>10, 'hi'=>16, 'min'=>10, 'action'=>true,  'measure'=>'極度危險的風險，需立即採取行動',
             'name_opp'=>'極度機會', 'measure_opp'=>''],
        ],
        'rm_dimensions'     => ['優勢（S）', '劣勢（W）', '機會（O）', '威脅（T）'],
        'rm_confirm_prefix' => 'R',      // 風險與機會編號的前綴：風險用這個（R2026-001）
        'rm_opp_prefix'     => 'O',      // 機會用這個（O2026-001）；兩者各自流水，已配發的編號不因設定變動而改變
        // 機會與風險共用同一張評分矩陣（分數算法一樣），但顯示的用詞不同：
        // 風險問「後果多嚴重、多常發生」，機會問「效益多大、多有把握做到」。只有用詞不同，不另做一套分數。
        'rm_opp_terms' => ['sev'=>'預期效益(B)', 'freq'=>'可行性(F)', 'level'=>'機會等級(OL)'],
        // 機會有自己的評估項目（稽核老師建議：不與風險的嚴重度(S)、發生率(O)共用）：預期效益(B)×可行性(F)＝機會等級(OL)。
        // 等級固定 3 級（資料存 1～3，只可改名稱與說明），機會等級的評分區間與處理措施另設，不套用風險準則（表A）。
        'rm_opp_benefit' => [
            'columns' => ['公司形象', '財務效益', '新增或擴大客戶數'],
            'rows' => [
                ['v'=>1, 'label'=>'輕微',     'cells'=>['單一部門正面形象提升', '年增益10萬以下', '1家客戶']],
                ['v'=>2, 'label'=>'中等',     'cells'=>['跨部門正面形象提升', '年增益10萬(含)以上，20萬以下', '2家客戶']],
                ['v'=>3, 'label'=>'顯著',     'cells'=>['公司整體正面形象提升', '年增益20萬(含)以上，50萬以下', '3家客戶']],
                ['v'=>4, 'label'=>'非常顯著', 'cells'=>['公司整體正面形象提升並獲客戶肯定', '年增益50萬(含)以上', '4家(含)客戶以上']],
            ],
        ],
        'rm_opp_feas' => [
            'columns' => ['資源投入', '技術與人力', '執行期間'],
            'rows' => [
                ['v'=>1, 'label'=>'困難',   'cells'=>['需大額投資', '需新增技術或人力', '1年以上']],
                ['v'=>2, 'label'=>'不易',   'cells'=>['需較多投入', '需新增人力或專業訓練', '6個月～1年']],
                ['v'=>3, 'label'=>'可行',   'cells'=>['需部分投入', '現有人員可執行，需補強訓練', '3～6個月']],
                ['v'=>4, 'label'=>'容易',   'cells'=>['現有資源即可', '現有人員與技術即可', '3個月內']],
            ],
        ],
        'rm_opp_levels' => [
            ['name'=>'低度機會', 'lo'=>1,  'hi'=>2,  'action'=>false, 'measure'=>'記錄原因，每年檢討時重新評估'],
            ['name'=>'中度機會', 'lo'=>3,  'hi'=>5,  'action'=>false, 'measure'=>'每半年重新評估，條件成熟再推動'],
            ['name'=>'高度機會', 'lo'=>6,  'hi'=>9,  'action'=>true,  'measure'=>'編入預算與人力計畫，指派負責單位'],
            ['name'=>'極度機會', 'lo'=>10, 'hi'=>16, 'action'=>true,  'measure'=>'納入年度目標，建立措施追蹤並優先投入資源'],
        ],
        'rm_maker_depts'    => [],      // 填表人可以選的部門（department.id 清單，含下轄）；空＝不限，由管理員設定以免選錯
        'rm_outsource_tags' => [],      // 對外宣稱有外包的稽核製程（ot_as_proc_tag.tag_id 清單）；沒勾＝沒有外包，不產生任何委外分析
    ];
}

if (!function_exists('rm_ensure_schema')) {

function rm_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS rm_doc (
            doc_id     INT AUTO_INCREMENT PRIMARY KEY,
            doc_no     VARCHAR(20) NULL COMMENT '單號＝填表日期YYYYMMDD+3位流水（依表單上的日期產生，不是建檔日）',
            kind       VARCHAR(10) NOT NULL COMMENT 'context=組織處境分析表／risk=風險與機會因應措施管制表',
            project_no VARCHAR(60) NULL COMMENT '專案編號',
            dept_ids   VARCHAR(300) NULL COMMENT '相關部門 department.id（JSON 陣列）',
            dept_names VARCHAR(500) NULL COMMENT '相關部門顯示名稱快照（部門改名後歷史單據仍顯示當時的名稱）',
            fill_date  DATE NULL COMMENT '填表日期＝本單業務日期（AS 版次回推用它）',
            remark     VARCHAR(500) NULL,
            created_by INT NULL, created_by_name VARCHAR(60) NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0,
            KEY idx_kind (kind, is_deleted, fill_date), KEY idx_no (doc_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='風險與機會管理：表頭'");

        $db->exec("CREATE TABLE IF NOT EXISTS rm_item (
            item_id     INT AUTO_INCREMENT PRIMARY KEY,
            doc_id      INT NOT NULL,
            seq         INT NOT NULL DEFAULT 0,
            scope       VARCHAR(10) NULL COMMENT 'internal=內部／external=外部議題',
            dimension   VARCHAR(40) NULL COMMENT '組織處境分析表的構面（管理員設定清單的文字快照）',
            stakeholder VARCHAR(200) NULL COMMENT '利害關係者',
            needs       TEXT NULL COMMENT '需求與期望',
            issue       TEXT NULL COMMENT '議題描述',
            ro_kind     VARCHAR(12) NULL COMMENT 'risk=風險／opportunity=機會',
            ro_text     TEXT NULL COMMENT '風險/機會說明',
            severity    TINYINT NULL COMMENT '嚴重度＝嚴重度(S)等級（值取自 rm_impact 設定）',
            frequency   TINYINT NULL COMMENT '頻率＝發生率(O)等級（值取自 rm_likelihood 設定）',
            confirm_no  VARCHAR(30) NULL COMMENT '風險確認編號（配發後不隨設定變動）',
            measure     TEXT NULL COMMENT '因應措施',
            effect_check TEXT NULL COMMENT '成效確認',
            ctx_item_id INT NULL COMMENT '引用的組織處境分析表項目（rm_item.item_id）',
            KEY idx_doc (doc_id, seq), KEY idx_ctx (ctx_item_id), KEY idx_cn (confirm_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='風險與機會管理：明細列'");
    } catch (Throwable $e) {}

    /* 成效確認（裁示與佐證）與規則引擎的欄位：既有資料不重建，一律 ALTER */
    foreach ([
        "ALTER TABLE rm_item ADD COLUMN effect_status VARCHAR(12) NULL COMMENT '成效確認結果 unconfirmed/doing/effective/partial/ineffective'",
        "ALTER TABLE rm_item ADD COLUMN effect_date DATE NULL COMMENT '成效確認日期（業務日期）'",
        "ALTER TABLE rm_item ADD COLUMN effect_ruling TEXT NULL COMMENT '成效確認裁示'",
        "ALTER TABLE rm_item ADD COLUMN effect_evidence TEXT NULL COMMENT '成效確認佐證（確認當下的快照，之後資料再變也不改寫歷史）'",
        "ALTER TABLE rm_item ADD COLUMN car_id INT NULL COMMENT '成效無效時開立的異常矯正處理單 id（car_order.id）'",
        "ALTER TABLE rm_item ADD COLUMN car_no VARCHAR(40) NULL COMMENT '異常矯正處理單單號（開單當下存一份供列印，單據本身以 car_id 為準）'",
        "ALTER TABLE rm_item ADD COLUMN recheck_note TEXT NULL COMMENT '重新評估原因（年度中途因重大變化重新評估時填寫，AS9100D 6.1 的再評估紀錄）'",
        "ALTER TABLE rm_item ADD COLUMN recheck_date DATE NULL COMMENT '重新評估日期（業務日期）'",
        "ALTER TABLE rm_item ADD COLUMN rule_ids VARCHAR(200) NULL COMMENT '產生這一列時用到的規則編號'",
        "ALTER TABLE rm_item ADD COLUMN resources TEXT NULL COMMENT '所需資源（人員、設施、工作環境、方法；2-GM-05 6.1.5.3 管理審查要對資源需求做決議）'",
        "ALTER TABLE rm_item ADD COLUMN frequency_after TINYINT NULL COMMENT '採取措施後的發生率(O) / 可行性(F)等級（嚴重度(S) / 預期效益(B)與措施前相同，故不另存）'",
        "ALTER TABLE rm_doc ADD COLUMN is_draft TINYINT NOT NULL DEFAULT 0 COMMENT '1＝系統依處境分析表產生的草稿，使用者確認後才算正式資料（畫面標示、列印不顯示）'",
        "ALTER TABLE rm_doc ADD COLUMN draft_at DATETIME NULL COMMENT '草稿產生時間'",
    ] as $alt) { try { $db->exec($alt); } catch (Throwable $e) {} }

    /* 角色（自動出現在「使用者權限設定」頁的動態角色區塊）。module 欄位是依據，不是顯示名 */
    foreach ([['rm_view', '風險與機會檢閱'], ['rm_admin', '風險與機會管理員']] as $r) {
        try {
            $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='risk_mgmt' LIMIT 1");
            $st->execute([$r[0]]);
            if (!$st->fetchColumn()) {
                $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?,'risk_mgmt')")->execute($r);
            }
        } catch (Throwable $e) {}
    }
}

/* ============================ 權限 ============================ */

function rm_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='risk_mgmt' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * isAdmin 系統管理者／canAdmin 管理員（設定、刪除、改任何一張）／canView 檢閱（看全部）／
 * canUse 全體在職員工（建立與編輯「自己建立的」表單）。與溝通管理同一套口徑。
 */
function rm_perms(PDO $db, ?array $u): array
{
    $none = ['isAdmin'=>false,'canAdmin'=>false,'canView'=>false,'canUse'=>false,'uid'=>0,'name'=>''];
    if (!$u) return $none;
    $uid = (int)$u['id'];
    if ((int)($u['state'] ?? 0) === 0 || (int)($u['user_status'] ?? 0) === 90) return $none;
    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;
    $canAdmin = $isAdmin || rm_has_role($db, $uid, ['rm_admin']);
    $canView  = $canAdmin || rm_has_role($db, $uid, ['rm_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canView'=>$canView,'canUse'=>true,
            'uid'=>$uid,'name'=>(string)$u['user_cname']];
}

/** 角色說明一律查目前實際資料組出（鐵律4：改名／刪除後才不會顯示舊內容） */
function rm_roles_now(PDO $db): array
{
    try {
        return $db->query("SELECT role_code, role_name FROM roles WHERE module='risk_mgmt' ORDER BY role_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================ 設定 ============================ */

/** 讀取設定（陣列型自動 decode）；沒存過的鍵退回預設 */
function rm_settings(PDO $db): array
{
    $out = rm_settings_default();
    try {
        $st = $db->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group=?");
        $st->execute([RM_SETTING_GROUP]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['param_key'];
            if (!array_key_exists($k, $out)) continue;
            $d = json_decode((string)$r['param_value'], true);
            if ($d !== null || strtolower(trim((string)$r['param_value'])) === 'null') {
                if (is_array($out[$k]) === is_array($d)) $out[$k] = $d;
            }
        }
    } catch (Throwable $e) {}
    return rm_settings_fix($out);
}

/** 舊格式相容：機率只有字串、風險準則只有起算分數時，補出上下限（讀取時補，不寫回資料庫） */
function rm_settings_fix(array $set): array
{
    foreach ($set['rm_likelihood'] as $i => $r) {
        if (!isset($r['prob_lo']) || !isset($r['prob_hi'])) {
            $lo = 0; $hi = 100;
            if (preg_match('/(\d+)\s*[-~～]\s*(\d+)/', (string)($r['prob'] ?? ''), $m)) { $lo = (int)$m[1]; $hi = (int)$m[2]; }
            $set['rm_likelihood'][$i]['prob_lo'] = $lo; $set['rm_likelihood'][$i]['prob_hi'] = $hi;
            $set['rm_likelihood'][$i]['prob'] = $lo . '-' . $hi . '%';
        }
    }
    $lv = array_values((array)$set['rm_levels']);
    $max = rm_max_score((array)$set['rm_impact']['rows'], (array)$set['rm_likelihood'], (string)$set['rm_method']);
    $ach = [];                                   // 目前實際算得出來的分數（舊格式只有起算分數時，用它決定每一級的上限）
    foreach ((array)$set['rm_impact']['rows'] as $i) foreach ((array)$set['rm_likelihood'] as $l) {
        $m = (string)$set['rm_method']; $x = (int)$i['v']; $y = (int)$l['v'];
        $ach[] = $m === 'add' ? $x + $y : ($m === 'max' ? max($x, $y) : $x * $y);
    }
    foreach ($lv as $k => $r) {
        if (isset($r['lo']) && isset($r['hi'])) continue;
        $lo = (int)($r['min'] ?? 1);
        $nextMin = isset($lv[$k + 1]) ? (int)($lv[$k + 1]['min'] ?? ($lo + 1)) : PHP_INT_MAX;
        $below = array_filter($ach, function ($x) use ($lo, $nextMin) { return $x >= $lo && $x < $nextMin; });
        $lv[$k]['lo'] = $lo;
        $lv[$k]['hi'] = $k === count($lv) - 1 ? $max : ($below ? max($below) : $lo);
    }
    $prev = 0;                                   // 區間要連續：每一級下限＝上一級上限＋1，第一級從 1 起算
    foreach ($lv as $k => $r) {
        $lv[$k]['lo'] = $k === 0 ? 1 : $prev + 1;
        if ($lv[$k]['hi'] < $lv[$k]['lo']) $lv[$k]['hi'] = $lv[$k]['lo'];
        $lv[$k]['min'] = (int)$lv[$k]['lo'];
        $prev = (int)$lv[$k]['hi'];
    }
    // 舊設定沒有「機會」那兩欄時就地補出來（只補在讀取，不寫回資料庫）：
    // 名稱把「危險／風險」換成「機會」（低度危險→低度機會），換不出來就沿用風險那一套名稱。
    foreach ($lv as $k => $r) {
        if (trim((string)($r['name_opp'] ?? '')) === '') {
            $n = str_replace(['危險', '風險'], '機會', (string)$r['name']);
            $lv[$k]['name_opp'] = $n;
        }
        if (trim((string)($r['measure_opp'] ?? '')) === '') {
            $d = rm_settings_default()['rm_levels'][$k] ?? null;     // 沒改過名稱的那幾級，直接用預設的機會處理措施
            $lv[$k]['measure_opp'] = ($d && (string)$d['name'] === (string)$r['name']) ? (string)$d['measure_opp'] : '';
        }
    }
    $set['rm_levels'] = $lv;
    if (!isset($set['rm_opp_terms']) || !is_array($set['rm_opp_terms'])) $set['rm_opp_terms'] = [];
    $set['rm_opp_terms'] += ['sev'=>'預期效益(B)', 'freq'=>'可行性(F)', 'level'=>'機會等級(OL)'];
    // 舊版預設名稱（機會與風險共用評分時期）就地換成新的評估項目名稱
    foreach (['sev'=>['預期效益','預期效益(B)'], 'freq'=>['實現可能性','可行性(F)'], 'level'=>['機會等級','機會等級(OL)']] as $k => $m)
        if ((string)$set['rm_opp_terms'][$k] === $m[0]) $set['rm_opp_terms'][$k] = $m[1];
    return $set;
}

/** 目前設定下可能出現的最高風險分數（嚴重度(S)最高等級 × 發生率(O)最高等級，依算法） */
function rm_max_score(array $impactRows, array $likeRows, string $method): int
{
    $mx = 0;
    foreach ($impactRows as $i) foreach ($likeRows as $l) {
        $a = (int)$i['v']; $b = (int)$l['v'];
        $sc = $method === 'add' ? $a + $b : ($method === 'max' ? max($a, $b) : $a * $b);
        if ($sc > $mx) $mx = $sc;
    }
    return $mx;
}

/** 單一設定寫入（param_value 是 JSON 欄位，一律 json_encode；見溝通管理同一個坑） */
function rm_setting_write(PDO $db, string $key, $value, string $by = ''): void
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
    $st->execute([RM_SETTING_GROUP, $key]);
    $rid = $st->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $by, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by) VALUES (?,?,?,?,?)")
           ->execute([RM_SETTING_GROUP, $key, $json, '風險與機會管理：' . $key, $by]);
    }
}

/** 表B 嚴重度(S)分析：{columns:[說明欄名稱…], rows:[{label,cells[],orig}]}。等級數字一律由順序自動給（1,2,3…，不跳號、不接受前端指定）。
 *  回傳 [設定, 錯誤訊息, 舊等級→新等級對照]（orig＝這一列原本的等級數字，新增的列為 0） */
function rm_normalize_impact($raw): array
{
    if (!is_array($raw) || !isset($raw['rows']) || !is_array($raw['rows'])) return [[], '嚴重度(S)分析內容不正確', []];
    $cols = [];
    foreach ((array)($raw['columns'] ?? []) as $c) {
        $c = mb_substr(trim((string)$c), 0, 20);
        if ($c !== '' && !in_array($c, $cols, true)) $cols[] = $c;
    }
    if (count($cols) > 6) return [[], '嚴重度(S)分析的說明欄最多 6 個', []];
    $rows = array_values($raw['rows']);
    if (count($rows) < 2 || count($rows) > 10) return [[], '嚴重度(S)分析至少要有 2 級、最多 10 級', []];
    $out = []; $map = [];
    foreach ($rows as $i => $r) {
        $v = $i + 1;
        $label = mb_substr(trim((string)($r['label'] ?? '')), 0, 20);
        if ($label === '') return [[], '嚴重度(S)分析的第 ' . $v . ' 級沒有填名稱', []];
        $orig = (int)($r['orig'] ?? 0);
        if ($orig > 0) { if (isset($map[$orig])) return [[], '嚴重度(S)分析的等級資料重複，請重新整理後再改', []]; $map[$orig] = $v; }
        $cells = [];
        $src = isset($r['cells']) && is_array($r['cells']) ? array_values($r['cells']) : [];
        for ($k = 0; $k < count($cols); $k++) $cells[] = mb_substr(trim((string)($src[$k] ?? '')), 0, 100);
        $out[] = ['v'=>$v, 'label'=>$label, 'cells'=>$cells];
    }
    return [['columns'=>$cols, 'rows'=>$out], '', $map];
}

/** 表C 發生率(O)分析：[{label,lo,hi,desc,orig}]。等級數字自動給；發生機率百分比是整數區間：
 *  第一級下限固定 0、最後一級上限固定 100、每一級下限＝上一級上限＋1（連續、不重疊、不留縫），上限不可小於下限。 */
function rm_normalize_likelihood($raw): array
{
    if (!is_array($raw)) return [[], '發生率(O)分析內容不正確', []];
    $rows = array_values($raw);
    $n = count($rows);
    if ($n < 2 || $n > 10) return [[], '發生率(O)分析至少要有 2 級、最多 10 級', []];
    $out = []; $map = []; $prevHi = -1;
    foreach ($rows as $i => $r) {
        $v = $i + 1;
        $label = mb_substr(trim((string)($r['label'] ?? '')), 0, 20);
        if ($label === '') return [[], '發生率(O)分析的第 ' . $v . ' 級沒有填名稱', []];
        $lo = $i === 0 ? 0 : $prevHi + 1;
        if ($i === $n - 1) $hi = 100;
        else {
            $h = $r['hi'] ?? ($r['prob_hi'] ?? '');
            if (!is_numeric($h) || floor((float)$h) != (float)$h) return [[], '「' . $label . '」的發生機率上限要填整數（0～100）', []];
            $hi = (int)$h;
            if ($hi < $lo) return [[], '「' . $label . '」的發生機率上限（' . $hi . '%）不可小於下限（' . $lo . '%）', []];
            $cap = 100 - ($n - 1 - $i);
            if ($hi > $cap) return [[], '「' . $label . '」的發生機率上限最多只能到 ' . $cap . '%（後面的每一級至少各要保留 1%）', []];
        }
        $prevHi = $hi;
        $orig = (int)($r['orig'] ?? 0);
        if ($orig > 0) { if (isset($map[$orig])) return [[], '發生率(O)分析的等級資料重複，請重新整理後再改', []]; $map[$orig] = $v; }
        $out[] = ['v'=>$v, 'label'=>$label, 'prob_lo'=>$lo, 'prob_hi'=>$hi, 'prob'=>$lo . '-' . $hi . '%',
                  'desc'=>mb_substr(trim((string)($r['desc'] ?? '')), 0, 200)];
    }
    return [$out, '', $map];
}

/** 表A 風險準則：[{name,lo,hi,measure,action,orig_name}]。評分是整數區間：第一級下限固定 1、最後一級上限固定＝目前可能的最高分($maxScore)、
 *  每一級下限＝上一級上限＋1，上限不可小於下限，且要替後面的每一級各留至少 1 分。已儲存的風險等級名稱不提供修改（orig_name 必須與 name 相同）。 */
function rm_normalize_levels($raw, int $maxScore, array $savedNames): array
{
    if (!is_array($raw)) return [[], '風險準則內容不正確'];
    $rows = array_values($raw);
    $n = count($rows);
    if ($n < 1 || $n > 8) return [[], '風險準則至少 1 級、最多 8 級'];
    if ($maxScore < $n) return [[], '目前的嚴重度(S)與發生率(O)等級最高只會算出 ' . $maxScore . ' 分，沒有辦法分成 ' . $n . ' 個風險等級'];
    $out = []; $seen = []; $seenOpp = []; $prevHi = 0;
    foreach ($rows as $i => $r) {
        $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 10);
        if ($name === '') return [[], '風險準則有一列沒有填等級名稱'];
        if (isset($seen[$name])) return [[], '風險等級「' . $name . '」重複了'];
        $seen[$name] = 1;
        $orig = (string)($r['orig_name'] ?? '');
        if ($orig !== '') {
            if (!in_array($orig, $savedNames, true)) return [[], '風險等級「' . $orig . '」已不存在，請重新整理後再改'];
            if ($orig !== $name) return [[], '風險等級「' . $orig . '」是已儲存的內容，名稱不提供修改（要改請刪除後重新新增）'];
        }
        $lo = $i === 0 ? 1 : $prevHi + 1;
        if ($i === $n - 1) $hi = $maxScore;
        else {
            $h = $r['hi'] ?? '';
            if (!is_numeric($h) || floor((float)$h) != (float)$h) return [[], '「' . $name . '」的評分上限要填整數'];
            $hi = (int)$h;
            if ($hi < $lo) return [[], '「' . $name . '」的評分上限（' . $hi . '）不可小於下限（' . $lo . '）'];
            $cap = $maxScore - ($n - 1 - $i);
            if ($hi > $cap) return [[], '「' . $name . '」的評分上限最多只能到 ' . $cap . '（後面的每一級至少各要保留 1 分，目前最高分是 ' . $maxScore . '）'];
        }
        $prevHi = $hi;
        $nameOpp = mb_substr(trim((string)($r['name_opp'] ?? '')), 0, 10);
        if ($nameOpp !== '') {
            if (isset($seenOpp[$nameOpp])) return [[], '機會等級「' . $nameOpp . '」重複了'];
            $seenOpp[$nameOpp] = 1;
        }
        $out[] = ['name'=>$name, 'lo'=>$lo, 'hi'=>$hi, 'min'=>$lo, 'action'=>!empty($r['action']),
                  'measure'=>mb_substr(trim((string)($r['measure'] ?? '')), 0, 200),
                  'name_opp'=>$nameOpp, 'measure_opp'=>mb_substr(trim((string)($r['measure_opp'] ?? '')), 0, 200)];
    }
    return [$out, ''];
}

/** SWOT 四象限名稱（依 S,W,O,T 順序各一個，不可空白） */
function rm_normalize_swot_names($raw): array
{
    $keys = array_keys(RM_SWOT);
    if (!is_array($raw) || count($raw) !== 4) return [[], 'SWOT 要剛好 4 個名稱（優勢、劣勢、機會、威脅各一個）'];
    $out = [];
    foreach (array_values($raw) as $i => $n) {
        $n = mb_substr(trim((string)$n), 0, 20);
        if ($n === '') return [[], 'SWOT「' . $keys[$i] . '」的名稱不可空白'];
        $out[] = $n;
    }
    return [$out, ''];
}

/** 畫面用：[{key,name,en,scope}]（名稱取自設定） */
function rm_swot_view(array $set): array
{
    $names = array_values((array)$set['rm_dimensions']);
    $out = []; $i = 0;
    foreach (RM_SWOT as $k => $m) { $out[] = ['key'=>$k, 'name'=>(string)($names[$i] ?? $k), 'en'=>$m['en'], 'scope'=>$m['scope']]; $i++; }
    return $out;
}

/**
 * 填寫提示與範例（內建；範例為製造業／AS9100 常見情境，只是起點，使用者按一下加入後可自行修改）。
 * 'ask' ＝ 這個象限該問自己的問題；'ex' ＝ 範例條目（利害關係者／需求與期望／議題描述）
 */
function rm_swot_guide(): array
{
    return [
        'S' => [
            'purpose' => '公司內部做得好、可以拿來競爭或降低風險的條件。',
            'ask' => ['我們的技術、設備、人才有哪些別人比不上的？', '客戶最常稱讚我們什麼？', '哪些管理制度（驗證、系統、SOP）已經運作良好？', '財務、交期、品質哪一項長期穩定？'],
            'ex' => [
                ['stakeholder'=>'客戶', 'needs'=>'穩定的品質與準時交貨', 'issue'=>'已建立 AS9100 品質系統並通過驗證，品質與交期績效穩定'],
                ['stakeholder'=>'客戶', 'needs'=>'高精度齒輪與特殊規格加工能力', 'issue'=>'具備齒輪、花鍵一貫加工能力，可承接少量多樣訂單'],
                ['stakeholder'=>'員工', 'needs'=>'穩定的工作與學習機會', 'issue'=>'核心技術人員資歷深，現場經驗豐富'],
            ],
        ],
        'W' => [
            'purpose' => '公司內部不足、可能拖累目標達成的條件。',
            'ask' => ['哪些流程常出狀況（異常單、客訴、延遲）？', '人員是否有斷層、過度依賴特定人？', '設備是否老舊、校驗或保養是否落後？', '哪些資訊還靠人工紀錄、容易漏或錯？'],
            'ex' => [
                ['stakeholder'=>'員工', 'needs'=>'完整的訓練與經驗傳承', 'issue'=>'資深技術人員即將退休，關鍵製程經驗傳承不足'],
                ['stakeholder'=>'客戶', 'needs'=>'量測數據可靠、可追溯', 'issue'=>'部分量測設備老舊，校驗週期管理不一致'],
                ['stakeholder'=>'供應商', 'needs'=>'明確的訂單預告與規格', 'issue'=>'生產排程變動頻繁，委外廠商交期不易配合'],
            ],
        ],
        'O' => [
            'purpose' => '公司外部、對我們有利的趨勢或事件，可以主動把握。',
            'ask' => ['客戶有沒有新的需求或新市場（例如電動車、航太、醫材）？', '政府有沒有補助、法規變化對我們有利？', '技術或設備有沒有新的選擇可以降低成本？', '競爭對手有沒有退出或出現空缺？',
                      '全球局勢變動（供應鏈重組、貿易協定、客戶轉單）有沒有把訂單帶到我們這裡？', '減碳、綠色製造的趨勢有沒有讓我們的技術或產品更有價值？'],
            'scan' => [
                ['cat'=>'國際局勢', 'hint'=>'戰爭與地緣衝突造成客戶供應鏈改道、轉單到友善或在地供應商'],
                ['cat'=>'貿易與政策', 'hint'=>'自由貿易協定、關稅調整、各國扶植製造業與國防／航太投資'],
                ['cat'=>'氣候與永續', 'hint'=>'低碳製程、節能設備補助、客戶要求綠色供應商的商機'],
                ['cat'=>'科技與市場', 'hint'=>'電動車、機器人、風電、智慧製造等新應用帶來的新需求'],
            ],
            'ex' => [
                ['stakeholder'=>'客戶', 'needs'=>'一站式齒輪加工與短交期', 'issue'=>'電動車減速機與機器人關節需求成長，新客戶詢價增加'],
                ['stakeholder'=>'政府／主管機關', 'needs'=>'節能與設備升級', 'issue'=>'政府推動設備汰舊換新與智慧製造補助，可申請'],
                ['stakeholder'=>'客戶', 'needs'=>'供應鏈在地化', 'issue'=>'客戶為分散風險，增加國內供應商比重'],
                ['stakeholder'=>'客戶', 'needs'=>'非紅供應鏈、地緣風險較低的供應來源', 'issue'=>'國際地緣衝突促使歐美客戶重組供應鏈，轉單至台灣供應商'],
                ['stakeholder'=>'客戶', 'needs'=>'低碳、可提供碳足跡數據的供應商', 'issue'=>'客戶推動綠色供應鏈，及早建立碳盤查能力可成為接單加分'],
            ],
        ],
        'T' => [
            'purpose' => '公司外部、可能對我們不利的趨勢或事件，需要預先防範。',
            'ask' => ['原物料、能源價格或供貨有沒有不穩定？', '客戶有沒有要求降價、縮短交期或提高驗證門檻？', '法規、環保、關稅有沒有新增要求？', '競爭對手或替代技術有沒有帶來壓力？',
                      '國際上有沒有戰爭、制裁、出口管制、海運受阻，影響到原料、運費或客戶訂單？', '氣候變遷（颱風、豪雨、乾旱、極端高溫、限電）會不會中斷我們或供應商的生產與交貨？',
                      '客戶或法規有沒有要求碳盤查、碳足跡、碳費或碳邊境稅（CBAM）？'],
            'scan' => [
                ['cat'=>'國際局勢', 'hint'=>'戰爭與地緣衝突、經濟制裁、出口管制、海運航線受阻、能源與原料價格劇烈波動'],
                ['cat'=>'貿易與政策', 'hint'=>'關稅、貿易壁壘、美中科技與供應鏈脫鉤、各國產業政策與補貼'],
                ['cat'=>'氣候與環境', 'hint'=>'碳足跡與碳費／碳邊境稅、颱風與豪雨淹水、缺水缺電、極端高溫、供應商所在地的氣候災害'],
                ['cat'=>'經濟與金融', 'hint'=>'匯率、利率、通膨、景氣循環、客戶產業需求下滑'],
                ['cat'=>'社會與疫情', 'hint'=>'疫情或公衛事件、人口老化與缺工、罷工、社會動盪'],
                ['cat'=>'法規與科技', 'hint'=>'環保與職安法規趨嚴、資安與個資規範、替代技術（例如 3D 列印、一體成型）'],
            ],
            'ex' => [
                ['stakeholder'=>'供應商', 'needs'=>'合理的價格與付款條件', 'issue'=>'特殊鋼材與委外加工價格上漲、交期不穩'],
                ['stakeholder'=>'客戶', 'needs'=>'更短交期與更低成本', 'issue'=>'客戶要求縮短交期並持續議價，產能與利潤受壓'],
                ['stakeholder'=>'主管機關／驗證機構', 'needs'=>'符合法規與驗證要求', 'issue'=>'環保與職安法規趨嚴，需持續投入合規成本'],
                ['stakeholder'=>'供應商', 'needs'=>'穩定的原料與能源供應', 'issue'=>'國際戰爭與制裁推升能源與特殊合金鋼價格，並造成海運延誤'],
                ['stakeholder'=>'客戶', 'needs'=>'提供碳足跡與減碳計畫', 'issue'=>'歐盟碳邊境稅（CBAM）與客戶供應鏈減碳要求，需建立碳盤查並可能增加成本'],
                ['stakeholder'=>'員工／供應商', 'needs'=>'颱風豪雨期間的安全與正常出貨', 'issue'=>'氣候變遷使颱風與豪雨更頻繁，廠區淹水、停電或委外廠停工會造成交期延誤'],
            ],
        ],
    ];
}

/* ============================ 風險等級（唯一實作；前端只拿設定在畫面預覽，存檔以此為準） ============================ */

/**
 * @return array|null  ['score'=>int,'name'=>string,'rank'=>int(0起,由低到高),'action'=>bool]；嚴重度或頻率沒填／不在級距內回 null
 */
function rm_level_calc(array $set, $sev, $freq, string $roKind = 'risk'): ?array
{
    if ($sev === null || $sev === '' || $freq === null || $freq === '') return null;
    if ($roKind === 'opportunity') return rm_opp_level_calc($set, (int)$sev, (int)$freq);
    $sev = (int)$sev; $freq = (int)$freq;
    $okS = false; $okF = false;
    foreach ((array)$set['rm_impact']['rows'] as $r) if ((int)$r['v'] === $sev)  $okS = true;
    foreach ((array)$set['rm_likelihood'] as $r)     if ((int)$r['v'] === $freq) $okF = true;
    if (!$okS || !$okF) return null;
    $score = rm_score($set, $sev, $freq);
    $lvls = (array)$set['rm_levels'];
    if (!$lvls) return null;
    $hit = $lvls[0]; $rank = 0;                       // 區間連續，正常一定命中；沒命中（舊資料）時取「下限不超過分數的最高一級」
    foreach ($lvls as $i => $lv) {
        if ($score >= (int)$lv['lo']) { $hit = $lv; $rank = $i; }
        if ($score >= (int)$lv['lo'] && $score <= (int)$lv['hi']) { $hit = $lv; $rank = $i; break; }
    }
    $opp = $roKind === 'opportunity';
    return ['score'=>$score, 'rank'=>$rank, 'action'=>!empty($hit['action']), 'is_opp'=>$opp,
            'name'=>(string)(($opp && !empty($hit['name_opp'])) ? $hit['name_opp'] : $hit['name']),
            'measure'=>(string)(($opp && !empty($hit['measure_opp'])) ? $hit['measure_opp'] : ($hit['measure'] ?? ''))];
}

/** 機會等級(OL)：預期效益(B)×可行性(F)，用機會自己的準則（rm_opp_levels）；回傳格式與 rm_level_calc 相同 */
function rm_opp_level_calc(array $set, int $b, int $f): ?array
{
    $okB = false; $okF = false;
    foreach ((array)$set['rm_opp_benefit']['rows'] as $r) if ((int)$r['v'] === $b) $okB = true;
    foreach ((array)$set['rm_opp_feas']['rows'] as $r)    if ((int)$r['v'] === $f) $okF = true;
    if (!$okB || !$okF) return null;
    $score = $b * $f;
    $lvls = array_values((array)$set['rm_opp_levels']);
    if (!$lvls) return null;
    $hit = $lvls[0]; $rank = 0;
    foreach ($lvls as $i => $lv) {
        if ($score >= (int)$lv['lo']) { $hit = $lv; $rank = $i; }
        if ($score >= (int)$lv['lo'] && $score <= (int)$lv['hi']) { $hit = $lv; $rank = $i; break; }
    }
    return ['score'=>$score, 'rank'=>$rank, 'action'=>!empty($hit['action']), 'is_opp'=>true,
            'name'=>(string)$hit['name'], 'measure'=>(string)($hit['measure'] ?? '')];
}

/**
 * 採取措施後的「發生率(O)／可行性(F)」建議值（嚴重度／預期效益與措施前相同）：
 * 風險＝發生率降到「風險等級要降低」的最高一級：措施前需要因應者降到「不需要因應措施」為止，措施前就不需因應者降到「低一個等級」為止，
 *       怎麼降都降不了等級時取最低級；機會＝可行性提高到最高級。目的是讓措施後的等級一定比措施前改善（風險降低、機會提高）。
 * @return int|null  建議的等級數字；措施前評分沒填回 null
 */
function rm_after_suggest(array $set, $sev, $freq, string $roKind)
{
    if ($sev === null || $sev === '' || $freq === null || $freq === '') return null;
    $sev = (int)$sev; $freq = (int)$freq;
    if ($roKind === 'opportunity') {
        $vs = array_map(function ($r) { return (int)$r['v']; }, (array)$set['rm_opp_feas']['rows']);
        return $vs ? max($vs) : $freq;
    }
    $vs = array_map(function ($r) { return (int)$r['v']; }, (array)$set['rm_likelihood']);
    sort($vs);
    $b = rm_level_calc($set, $sev, $freq, 'risk');
    if (!$b) return $freq;
    $best = null;
    foreach ($vs as $v) {
        if ($v > $freq) break;
        $lv = rm_level_calc($set, $sev, $v, 'risk');
        if (!$lv) continue;
        $ok = !empty($b['action']) ? !$lv['action'] : ($lv['rank'] < $b['rank']);
        if ($ok) $best = $v;
    }
    return $best ?? ($vs ? min($vs) : $freq);
}

/**
 * 採取措施後的評估是否「真的有改善」：風險等級要降低、機會等級要提高，與改善前相同就沒有改善的意義。
 * 沒有改善就一律要求使用者修改（包含已經到頂、怎麼調都無法改善的列：要改因應措施或評估內容，不能以相同等級完成）。
 * @return string  空字串＝通過；否則是要顯示給使用者的原因
 */
function rm_after_check(array $set, $sev, $freq, $after, string $roKind): string
{
    $b = rm_level_calc($set, $sev, $freq, $roKind);
    $a = rm_level_calc($set, $sev, $after, $roKind);
    if (!$b || !$a) return '';
    $opp = $roKind === 'opportunity';
    if ($opp ? $a['rank'] <= $b['rank'] : $a['rank'] >= $b['rank'])
        return '採取措施後的' . ($opp ? '機會等級(' . $a['name'] . ')仍與措施前相同，機會要提高才有改善的意義，請調整可行性(F)或修改把握方案' : '風險等級(' . $a['name'] . ')仍不低於措施前，風險要降低才有改善的意義，請調整發生率(O)或修改因應措施');
    return '';
}

/** 「需要因應措施」的門檻等級名稱（第一個勾選「需要因應措施」的等級）；沒有任何一級需要時回空字串 */
function rm_action_threshold(array $set, string $roKind): string
{
    foreach (array_values((array)($roKind === 'opportunity' ? $set['rm_opp_levels'] : $set['rm_levels'])) as $lv) if (!empty($lv['action'])) return (string)$lv['name'];
    return '';
}

/** 等級低於「需要因應措施」門檻時，因應措施欄自動帶入的說明（這種列不需要採取措施後評估與成效確認） */
function rm_noaction_text(array $set, string $roKind, string $lvName): string
{
    if ($roKind === 'opportunity') return '低於需提出把握方案的門檻，故不另提出把握方案，由權責單位依現有作業掌握。';
    return '低於需採取因應措施的門檻，故不另採取因應措施，由權責單位依現有作業掌握。';
}

/** 因應措施欄是不是「系統自動帶入的不需因應說明」（使用者自己寫的不覆蓋） */
function rm_is_noaction_text(string $t): bool
{
    return (bool)preg_match('/^((風險等級為「[^」]*」，)?低於需採取因應措施的門檻|(機會等級為「[^」]*」，)?低於需提出把握方案的門檻)/u', trim($t));
}

/**
 * 一份表單裡「等級低於因應門檻」的列一律整理成：因應措施＝自動說明、不需要採取措施後評估與成效確認（相關欄位清空）。
 * 回傳整理了幾列。
 */
function rm_noaction_normalize(PDO $db, array $set, int $docId): int
{
    $n = 0;
    $st = $db->prepare("SELECT item_id, severity, frequency, ro_kind, measure FROM rm_item WHERE doc_id=? AND severity IS NOT NULL AND frequency IS NOT NULL AND ro_kind IS NOT NULL AND ro_kind<>''");
    $st->execute([$docId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lv = rm_level_calc($set, $r['severity'], $r['frequency'], (string)$r['ro_kind']);
        if (!$lv || !empty($lv['action'])) continue;
        $txt = rm_noaction_text($set, (string)$r['ro_kind'], $lv['name']);      // 不需因應的列一律是自動說明（不保留舊的措施文字）
        $db->prepare("UPDATE rm_item SET measure=?, frequency_after=NULL, effect_check=NULL, effect_status=NULL, effect_date=NULL, effect_ruling=NULL, effect_evidence=NULL WHERE item_id=?")
           ->execute([$txt, $r['item_id']]);
        $n++;
    }
    return $n;
}

/** 機會的評估等級／準則（畫面用的顏色一併附上） */
function rm_opp_levels_view(array $set): array
{
    $out = [];
    foreach (array_values((array)$set['rm_opp_levels']) as $k => $lv) {
        $c = rm_level_color($k, true);
        $out[] = array_merge($lv, ['bg'=>$c['bg'], 'fg'=>$c['fg'], 'range'=>(int)$lv['lo'] === (int)$lv['hi'] ? (string)$lv['lo'] : $lv['lo'] . '～' . $lv['hi']]);
    }
    return $out;
}

/** 機會的預期效益(B)／可行性(F)準則：固定 4 級，只可改名稱與說明欄 */
function rm_normalize_opp_table($raw, string $title, int $n = 4): array
{
    if (!is_array($raw) || !isset($raw['rows']) || !is_array($raw['rows'])) return [[], $title . '內容不正確'];
    $cols = [];
    foreach ((array)($raw['columns'] ?? []) as $c) { $c = mb_substr(trim((string)$c), 0, 20); if ($c !== '' && !in_array($c, $cols, true)) $cols[] = $c; }
    if (!$cols || count($cols) > 6) return [[], $title . '的說明欄要 1～6 個'];
    $rows = array_values($raw['rows']);
    if (count($rows) !== $n) return [[], $title . '固定為 ' . $n . ' 級'];
    $out = [];
    foreach ($rows as $i => $r) {
        $label = mb_substr(trim((string)($r['label'] ?? '')), 0, 20);
        if ($label === '') return [[], $title . '的第 ' . ($i + 1) . ' 級沒有填名稱'];
        $src = isset($r['cells']) && is_array($r['cells']) ? array_values($r['cells']) : [];
        $cells = [];
        for ($k = 0; $k < count($cols); $k++) $cells[] = mb_substr(trim((string)($src[$k] ?? '')), 0, 100);
        $out[] = ['v'=>$i + 1, 'label'=>$label, 'cells'=>$cells];
    }
    return [['columns'=>$cols, 'rows'=>$out], ''];
}

/** 機會等級準則：固定 4 級，評分＝B×F；第一級下限 1、最後一級上限＝最高分 */
function rm_normalize_opp_levels($raw, int $maxScore): array
{
    if (!is_array($raw)) return [[], '機會等級準則內容不正確'];
    $rows = array_values($raw); $n = count($rows);
    if ($n !== 4) return [[], '機會等級準則固定為 4 級'];
    $out = []; $seen = []; $prev = 0;
    foreach ($rows as $i => $r) {
        $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 10);
        if ($name === '') return [[], '機會等級準則有一列沒有填等級名稱'];
        if (isset($seen[$name])) return [[], '機會等級「' . $name . '」重複了'];
        $seen[$name] = 1;
        $lo = $i === 0 ? 1 : $prev + 1;
        if ($i === $n - 1) $hi = $maxScore;
        else {
            $h = $r['hi'] ?? '';
            if (!is_numeric($h) || floor((float)$h) != (float)$h) return [[], '「' . $name . '」的評分上限要填整數'];
            $hi = (int)$h;
            if ($hi < $lo) return [[], '「' . $name . '」的評分上限（' . $hi . '）不可小於下限（' . $lo . '）'];
            $cap = $maxScore - ($n - 1 - $i);
            if ($hi > $cap) return [[], '「' . $name . '」的評分上限最多只能到 ' . $cap . '（機會等級最高分是 ' . $maxScore . '，後面每一級至少各留 1 分）'];
        }
        $prev = $hi;
        $out[] = ['name'=>$name, 'lo'=>$lo, 'hi'=>$hi, 'action'=>!empty($r['action']), 'measure'=>mb_substr(trim((string)($r['measure'] ?? '')), 0, 200)];
    }
    return [$out, ''];
}

/** 表D：嚴重度(S)等級 × 發生率(O)等級 的分數（算法由設定決定） */
function rm_score(array $set, int $impact, int $likelihood): int
{
    $m = (string)($set['rm_method'] ?? 'multiply');
    return $m === 'add' ? $impact + $likelihood : ($m === 'max' ? max($impact, $likelihood) : $impact * $likelihood);
}

/** 畫面用的風險等級清單：固定暖色盤顏色＋該等級在目前矩陣中實際會出現的分數範圍（例 3～6，與程序書表A一致） */
function rm_levels_view(array $set): array
{
    $out = [];
    foreach ((array)$set['rm_levels'] as $k => $lv) {
        $lo = (int)$lv['lo']; $hi = (int)$lv['hi'];
        $oc = rm_level_color($k, true);
        $out[] = array_merge($lv, rm_level_color($k), ['bg_opp'=>$oc['bg'], 'fg_opp'=>$oc['fg'], 'range'=>$lo === $hi ? (string)$lo : $lo . '～' . $hi]);
    }
    return $out;
}

function rm_level_color(int $rank, bool $opp = false): array
{
    $pal = $opp ? RM_LEVEL_COLORS_OPP : RM_LEVEL_COLORS;
    return $pal[min($rank, count($pal) - 1)];
}

/* ============================ 編號 ============================ */

function rm_next_doc_no(PDO $db, string $fillDate): string
{
    $d = preg_replace('/\D/', '', substr($fillDate, 0, 10));
    if (strlen($d) !== 8) $d = date('Ymd');
    try {
        $st = $db->prepare("SELECT doc_no FROM rm_doc WHERE doc_no LIKE ? ORDER BY doc_no DESC LIMIT 1");
        $st->execute([$d . '%']);
        $last = (string)$st->fetchColumn();
        $n = $last !== '' ? (int)substr($last, 8) : 0;
    } catch (Throwable $e) { $n = 0; }
    return $d . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
}

/**
 * 填表人候選（唯一實作，下拉與後端驗證共用）：填表日期當天在職的人（補歷史單據時當時在職、現已離職者也選得到），
 * 且有「管理員設定可選部門」時，只列在那些部門（含下轄）任職的人。
 * 一個職務一列（兼任者的主職務與兼任職務都要列得出來，value 用 post_key；只留職級最高那筆會讓主職消失）。
 */
function rm_maker_candidates(PDO $db, string $date): array
{
    require_once __DIR__ . '/people_lib.php';
    require_once __DIR__ . '/org_role_lib.php';
    $set = rm_settings($db);
    $dept = [];
    foreach ((array)($set['rm_maker_depts'] ?? []) as $d) foreach (eg_dept_subtree_ids($db, (int)$d) as $x) $dept[(int)$x] = true;
    $opt = $dept ? ['dept_ids' => array_keys($dept)] : [];
    return eg_people_posts_asof($db, $opt, $date);
}

/** 依「風險／機會」選前綴（機會用 rm_opp_prefix，其餘用 rm_confirm_prefix） */
function rm_prefix_for(array $set, string $roKind): string
{
    return $roKind === 'opportunity' ? (string)($set['rm_opp_prefix'] ?? 'O') : (string)$set['rm_confirm_prefix'];
}

/** 風險與機會編號：前綴＋填表年度＋流水（例 R2026-001）；全站同年度連號，已配出的不回收 */
function rm_next_confirm_no(PDO $db, string $prefix, string $fillDate): string
{
    $prefix = preg_replace('/[^A-Za-z0-9\-]/', '', $prefix);
    $y = substr($fillDate, 0, 4);
    if (!preg_match('/^\d{4}$/', $y)) $y = date('Y');
    $head = $prefix . $y . '-';
    try {
        $st = $db->prepare("SELECT confirm_no FROM rm_item WHERE confirm_no LIKE ? ORDER BY CHAR_LENGTH(confirm_no) DESC, confirm_no DESC LIMIT 1");
        $st->execute([$head . '%']);
        $last = (string)$st->fetchColumn();
        $n = $last !== '' ? (int)substr($last, strlen($head)) : 0;
    } catch (Throwable $e) { $n = 0; }
    return $head . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
}

/* ============================ AS 文件綁定 ============================ */

function rm_asdoc_all(PDO $db): array
{
    $out = [];
    foreach (RM_ASDOC_MODULES as $k => $cfg) {
        $doc = eg_asdoc_get($db, $cfg['module']);
        $out[$k] = ['module'=>$cfg['module'], 'label'=>$cfg['label'], 'fallback'=>$cfg['fallback'],
                    'doc'=>$doc, 'doc_id'=>(int)($doc['id'] ?? 0)];
    }
    return $out;
}

/** 列印表頭／頁尾（ai-rules/16）：公司全名動態取；表頭＝綁定 AS 文件的 doc_name；單筆單據版次依填表日期回推 */
function rm_print_meta(PDO $db, string $kind, ?string $bizDate = null): array
{
    $cfg   = RM_ASDOC_MODULES[$kind] ?? null;
    $docId = $cfg ? eg_asdoc_id($db, $cfg['module']) : 0;
    $doc   = $cfg ? eg_asdoc_get($db, $cfg['module']) : null;
    return [
        'company'  => eg_company_full_name($db),
        'doc_id'   => $docId,
        'doc_no'   => $docId ? ($bizDate !== null ? eg_asdoc_no_asof_id($db, $docId, $bizDate) : eg_asdoc_no($doc)) : '',
        'doc_name' => $doc['doc_name'] ?? ($cfg['label'] ?? ''),
        'bound'    => (bool)$doc,
    ];
}

}


/* ============================ 成效無效 → 開異常矯正處理單 ============================
 * 開單、配號、簽章、紀錄、通知全部走 CAR 模組自己的函式（car_lib／car_notify），這裡只負責把風險列的內容組成異常說明。
 * 一條風險只開一張（rm_item.car_id 擋重複）；責任單位不在這裡猜——風險列沒有單一責任單位，
 * 交給矯正單的流程由主管指派回覆人（與內部稽核開單同一做法：解析不到人也不能讓單子開不出來）。 */
function rm_car_create(PDO $db, array $item, array $doc, int $uid, string $uname, bool $notify = true): array
{
    require_once __DIR__ . '/car_lib.php';
    require_once __DIR__ . '/car_notify.php';
    if ((int)($item['car_id'] ?? 0) > 0) return ['car_id'=>(int)$item['car_id'], 'car_no'=>(string)($item['car_no'] ?? ''), 'existed'=>true];

    $biz = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $set = rm_settings($db);
    $lv  = rm_level_calc($set, $item['severity'], $item['frequency'], (string)($item['ro_kind'] ?? 'risk'));
    $conf = trim((string)($item['confirm_no'] ?? ''));
    $eff = ['effective'=>'有效', 'partial'=>'部分有效', 'ineffective'=>'無效', 'doing'=>'進行中'][(string)($item['effect_status'] ?? '')] ?? '';

    $desc = '【風險與機會因應措施管制表 ' . $doc['doc_no'] . '】' . "\n"
          . ($conf !== '' ? '風險與機會編號：' . $conf . "\n" : '')
          . '議題：' . trim((string)$item['issue']) . "\n"
          . '風險等級：' . ($lv ? $lv['name'] . '（' . $lv['score'] . ' 分）' : '未評估') . "\n"
          . '因應措施：' . trim((string)$item['measure']) . "\n"
          . '成效確認：' . $eff . ($item['effect_date'] ? '（' . $item['effect_date'] . '）' : '') . "\n"
          . ($item['effect_ruling'] ? '裁示：' . trim((string)$item['effect_ruling']) . "\n" : '')
          . ($item['effect_evidence'] ? "佐證：\n" . trim((string)$item['effect_evidence']) . "\n" : '')
          . '請說明原因並提出矯正措施，必要時調整因應措施或風險評分。';

    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $no = car_alloc_numbers($db, 1, date('Ymd', strtotime($biz)))[0];
        $db->prepare(
            "INSERT INTO car_order (car_no, group_no, source_type, source_no, source_desc, fill_date, found_date,
                 created_by, created_by_name, abnormal_desc, status, stage_since, created_at, updated_at)
             VALUES (?,?, 'OTHER', ?,?, ?,?, ?,?, ?, 'open', NOW(), NOW(), NOW())")
           ->execute([$no, $no, mb_substr('風險與機會管制表 ' . $doc['doc_no'] . ($conf !== '' ? ' ' . $conf : ''), 0, 50),
                      mb_substr(trim((string)$item['issue']), 0, 255), $biz, $biz, $uid, $uname, $desc]);
        $carId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO car_signature (car_id, section, signed_by, signed_name, signed_at, signed_date_label) VALUES (?, 'desc', ?, ?, NOW(), ?)")
           ->execute([$carId, $uid, $uname, eg_fmt_date($biz)]);
        car_log($db, $carId, 'create', $uid, $uname, '由風險與機會因應措施管制表 ' . $doc['doc_no'] . ' 成效確認無效開立');
        $db->prepare("UPDATE rm_item SET car_id=?, car_no=? WHERE item_id=?")->execute([$carId, $no, (int)$item['item_id']]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if (!$notify) return ['car_id'=>$carId, 'car_no'=>$no, 'existed'=>false];      // 測試用：不發通知
    try {   // 通知走 CAR 模組自己的機制；失敗不影響開單
        $ro = $db->prepare("SELECT * FROM car_order WHERE id=?"); $ro->execute([$carId]);
        $o = $ro->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($o) car_notify($db, $carId, car_notify_title('🔧', $o, '待指派回覆人'),
            car_notify_body($db, $o, '由風險與機會因應措施管制表成效確認「無效」自動開立，請指派回覆人說明原因並提出矯正措施。'),
            car_pm_supervisors($db) ?: car_final_deciders($db), $uid);
    } catch (Throwable $e) {}
    return ['car_id'=>$carId, 'car_no'=>$no, 'existed'=>false];
}

/**
 * 追蹤週期建議：AS9100D 6.1 沒有規定多久追蹤一次，慣例依風險高低——高度風險每月、中度每季、低度每半年；機會每季。
 * 起算日取「最後一次成效確認／重新評估／填表日」中最晚的一天，算出下一次追蹤日；沒有因應措施的等級不建議追蹤。
 * 只是建議（畫面顯示，不寫入資料）。
 */
function rm_follow_up(array $set, array $lv, array $row, string $fillDate): ?array
{
    if (empty($lv['action'])) return null;
    $n = max(1, count((array)$set['rm_levels']) - 1);
    $ratio = $n > 0 ? ((int)$lv['rank'] / $n) : 1;
    if (!empty($lv['is_opp'])) { $label = '每季'; $mon = 3; }
    elseif ($ratio >= 0.75) { $label = '每月'; $mon = 1; }
    elseif ($ratio >= 0.4) { $label = '每季'; $mon = 3; }
    else { $label = '每半年'; $mon = 6; }
    $base = max(array_filter([(string)($row['effect_date'] ?? ''), (string)($row['recheck_date'] ?? ''), $fillDate], function ($x) { return $x !== ''; }) ?: [date('Y-m-d')]);
    $next = date('Y-m-d', strtotime($base . ' +' . $mon . ' months'));
    return ['cycle' => $label, 'months' => $mon, 'base' => $base, 'next' => $next, 'overdue' => $next < date('Y-m-d')];
}
