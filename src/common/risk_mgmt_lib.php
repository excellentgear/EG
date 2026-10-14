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
 *   表B 影響分析   rm_impact      影響等級（值、名稱）＋可自訂的說明欄（預設：公司形象／財物損失／客戶抱怨件數）
 *   表C 可能性分析 rm_likelihood  可能性等級（值、名稱、發生機率百分比、詳細描述）
 *   表D 風險分布   rm_method      影響×可能性的分數算法 multiply(相乘，程序書採此) / add / max；矩陣由 A～C 自動產生
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

/** 設定預設值（只是「第一次使用時的起點」，管理員改了就以資料庫為準；陣列型一律存 JSON） */
function rm_settings_default(): array
{
    return [
        // 表B 影響分析（2-GM-03 表B）
        'rm_impact' => [
            'columns' => ['公司形象', '財物損失', '客戶抱怨件數'],
            'rows' => [
                ['v'=>1, 'label'=>'輕微',     'cells'=>['單一部門負面形象', '10萬以下', '1家客戶']],
                ['v'=>2, 'label'=>'嚴重',     'cells'=>['跨部門負面形象', '20萬以下 10萬(含)以上', '2家(含)客戶']],
                ['v'=>3, 'label'=>'非常嚴重', 'cells'=>['公司整體負面形象', '20萬(含)以上', '3家(含)客戶以上']],
            ],
        ],
        // 表C 可能性分析（2-GM-03 表C）
        'rm_likelihood' => [
            ['v'=>1, 'label'=>'幾乎不可能', 'prob'=>'0-40%',   'desc'=>'只會在特殊的情況下發生'],
            ['v'=>2, 'label'=>'可能',       'prob'=>'41-60%',  'desc'=>'有些情況下會發生'],
            ['v'=>3, 'label'=>'幾乎確定',   'prob'=>'61-100%', 'desc'=>'在大部分的情況下會發生'],
        ],
        // 表D 風險分布：分數算法（程序書 6.3.3：影響等級×機率等級）
        'rm_method' => 'multiply',
        // 表A 風險準則（2-GM-03 表A）：分數 >= 起算分數的最高一級；低於最低一級的起算分數時歸最低級
        'rm_levels' => [
            ['name'=>'低度危險', 'min'=>1, 'action'=>false, 'measure'=>'低度危險的風險，以一般步驟處理'],
            ['name'=>'中度危險', 'min'=>2, 'action'=>false, 'measure'=>'必須明定管理階層的責任範圍'],
            ['name'=>'高度危險', 'min'=>3, 'action'=>true,  'measure'=>'管理階層需督導所屬研擬計畫並提供資源'],
            ['name'=>'極度危險', 'min'=>9, 'action'=>true,  'measure'=>'極度危險的風險，需立即採取行動'],
        ],
        'rm_dimensions'     => ['優勢（S）', '劣勢（W）', '機會（O）', '威脅（T）'],
        'rm_confirm_prefix' => 'R',
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
            severity    TINYINT NULL COMMENT '嚴重度＝影響等級（值取自 rm_impact 設定）',
            frequency   TINYINT NULL COMMENT '頻率＝可能性等級（值取自 rm_likelihood 設定）',
            confirm_no  VARCHAR(30) NULL COMMENT '風險確認編號（配發後不隨設定變動）',
            measure     TEXT NULL COMMENT '因應措施',
            effect_check TEXT NULL COMMENT '成效確認',
            ctx_item_id INT NULL COMMENT '引用的組織處境分析表項目（rm_item.item_id）',
            KEY idx_doc (doc_id, seq), KEY idx_ctx (ctx_item_id), KEY idx_cn (confirm_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='風險與機會管理：明細列'");
    } catch (Throwable $e) {}

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
    return $out;
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

/** 表B 影響分析：{columns:[說明欄名稱…], rows:[{v,label,cells[]}]}。回傳 [設定, 錯誤訊息] */
function rm_normalize_impact($raw): array
{
    if (!is_array($raw) || !isset($raw['rows']) || !is_array($raw['rows'])) return [[], '影響分析內容不正確'];
    $cols = [];
    foreach ((array)($raw['columns'] ?? []) as $c) {
        $c = mb_substr(trim((string)$c), 0, 20);
        if ($c !== '' && !in_array($c, $cols, true)) $cols[] = $c;
    }
    if (count($cols) > 6) return [[], '影響分析的說明欄最多 6 個'];
    $rows = $raw['rows'];
    if (count($rows) < 2 || count($rows) > 10) return [[], '影響分析至少要有 2 級、最多 10 級'];
    $out = []; $seen = [];
    foreach ($rows as $r) {
        $v = (int)($r['v'] ?? 0);
        $label = mb_substr(trim((string)($r['label'] ?? '')), 0, 20);
        if ($v < 1 || $v > 99) return [[], '影響分析的等級必須是 1～99 的整數'];
        if (isset($seen[$v])) return [[], '影響分析的等級 ' . $v . ' 重複了'];
        if ($label === '') return [[], '影響分析的等級 ' . $v . ' 沒有填名稱'];
        $seen[$v] = 1;
        $cells = [];
        $src = isset($r['cells']) && is_array($r['cells']) ? array_values($r['cells']) : [];
        for ($i = 0; $i < count($cols); $i++) $cells[] = mb_substr(trim((string)($src[$i] ?? '')), 0, 100);
        $out[] = ['v'=>$v, 'label'=>$label, 'cells'=>$cells];
    }
    usort($out, function ($a, $b) { return $a['v'] <=> $b['v']; });
    return [['columns'=>$cols, 'rows'=>$out], ''];
}

/** 表C 可能性分析：[{v,label,prob,desc}] */
function rm_normalize_likelihood($raw): array
{
    if (!is_array($raw) || count($raw) < 2 || count($raw) > 10) return [[], '可能性分析至少要有 2 級、最多 10 級'];
    $out = []; $seen = [];
    foreach ($raw as $r) {
        $v = (int)($r['v'] ?? 0);
        $label = mb_substr(trim((string)($r['label'] ?? '')), 0, 20);
        if ($v < 1 || $v > 99) return [[], '可能性分析的等級必須是 1～99 的整數'];
        if (isset($seen[$v])) return [[], '可能性分析的等級 ' . $v . ' 重複了'];
        if ($label === '') return [[], '可能性分析的等級 ' . $v . ' 沒有填名稱'];
        $seen[$v] = 1;
        $out[] = ['v'=>$v, 'label'=>$label, 'prob'=>mb_substr(trim((string)($r['prob'] ?? '')), 0, 30),
                  'desc'=>mb_substr(trim((string)($r['desc'] ?? '')), 0, 200)];
    }
    usort($out, function ($a, $b) { return $a['v'] <=> $b['v']; });
    return [$out, ''];
}

/** 表A 風險準則：[{name,min,action,measure}]（依起算分數由低到高） */
function rm_normalize_levels($raw): array
{
    if (!is_array($raw) || count($raw) < 1 || count($raw) > 8) return [[], '風險準則至少 1 級、最多 8 級'];
    $out = []; $seen = [];
    foreach ($raw as $r) {
        $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 10);
        $min  = (int)($r['min'] ?? 0);
        if ($name === '') return [[], '風險準則有一列沒有填等級名稱'];
        if ($min < 0 || $min > 9999) return [[], '風險等級「' . $name . '」的起算分數不合理'];
        if (isset($seen[$min])) return [[], '兩個風險等級的起算分數都是 ' . $min . '，無法分辨'];
        $seen[$min] = 1;
        $out[] = ['name'=>$name, 'min'=>$min, 'action'=>!empty($r['action']), 'measure'=>mb_substr(trim((string)($r['measure'] ?? '')), 0, 200)];
    }
    usort($out, function ($a, $b) { return $a['min'] <=> $b['min']; });
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
            'ask' => ['客戶有沒有新的需求或新市場（例如電動車、航太、醫材）？', '政府有沒有補助、法規變化對我們有利？', '技術或設備有沒有新的選擇可以降低成本？', '競爭對手有沒有退出或出現空缺？'],
            'ex' => [
                ['stakeholder'=>'客戶', 'needs'=>'一站式齒輪加工與短交期', 'issue'=>'電動車減速機與機器人關節需求成長，新客戶詢價增加'],
                ['stakeholder'=>'政府／主管機關', 'needs'=>'節能與設備升級', 'issue'=>'政府推動設備汰舊換新與智慧製造補助，可申請'],
                ['stakeholder'=>'客戶', 'needs'=>'供應鏈在地化', 'issue'=>'客戶為分散風險，增加國內供應商比重'],
            ],
        ],
        'T' => [
            'purpose' => '公司外部、可能對我們不利的趨勢或事件，需要預先防範。',
            'ask' => ['原物料、能源價格或供貨有沒有不穩定？', '客戶有沒有要求降價、縮短交期或提高驗證門檻？', '法規、環保、關稅有沒有新增要求？', '競爭對手或替代技術有沒有帶來壓力？'],
            'ex' => [
                ['stakeholder'=>'供應商', 'needs'=>'合理的價格與付款條件', 'issue'=>'特殊鋼材與熱處理委外價格上漲、交期不穩'],
                ['stakeholder'=>'客戶', 'needs'=>'更短交期與更低成本', 'issue'=>'客戶要求縮短交期並持續議價，產能與利潤受壓'],
                ['stakeholder'=>'主管機關／驗證機構', 'needs'=>'符合法規與驗證要求', 'issue'=>'環保與職安法規趨嚴，需持續投入合規成本'],
            ],
        ],
    ];
}

/* ============================ 風險等級（唯一實作；前端只拿設定在畫面預覽，存檔以此為準） ============================ */

/**
 * @return array|null  ['score'=>int,'name'=>string,'rank'=>int(0起,由低到高),'action'=>bool]；嚴重度或頻率沒填／不在級距內回 null
 */
function rm_level_calc(array $set, $sev, $freq): ?array
{
    if ($sev === null || $sev === '' || $freq === null || $freq === '') return null;
    $sev = (int)$sev; $freq = (int)$freq;
    $okS = false; $okF = false;
    foreach ((array)$set['rm_impact']['rows'] as $r) if ((int)$r['v'] === $sev)  $okS = true;
    foreach ((array)$set['rm_likelihood'] as $r)     if ((int)$r['v'] === $freq) $okF = true;
    if (!$okS || !$okF) return null;
    $score = rm_score($set, $sev, $freq);
    $lvls = (array)$set['rm_levels'];
    if (!$lvls) return null;
    $hit = $lvls[0]; $rank = 0;                       // 低於最低一級的起算分數時歸最低級（不會算不出等級）
    foreach ($lvls as $i => $lv) {
        if ($score >= (int)$lv['min']) { $hit = $lv; $rank = $i; }
    }
    return ['score'=>$score, 'name'=>(string)$hit['name'], 'rank'=>$rank, 'action'=>!empty($hit['action']),
            'measure'=>(string)($hit['measure'] ?? '')];
}

/** 表D：影響等級 × 可能性等級 的分數（算法由設定決定） */
function rm_score(array $set, int $impact, int $likelihood): int
{
    $m = (string)($set['rm_method'] ?? 'multiply');
    return $m === 'add' ? $impact + $likelihood : ($m === 'max' ? max($impact, $likelihood) : $impact * $likelihood);
}

/** 畫面用的風險等級清單：固定暖色盤顏色＋該等級在目前矩陣中實際會出現的分數範圍（例 3～6，與程序書表A一致） */
function rm_levels_view(array $set): array
{
    $scores = [];
    foreach ((array)$set['rm_impact']['rows'] as $i)
        foreach ((array)$set['rm_likelihood'] as $l) $scores[] = rm_score($set, (int)$i['v'], (int)$l['v']);
    $out = [];
    $lvls = (array)$set['rm_levels'];
    foreach ($lvls as $k => $lv) {
        $lo = (int)$lv['min'];
        $hi = isset($lvls[$k + 1]) ? (int)$lvls[$k + 1]['min'] - 1 : PHP_INT_MAX;
        $in = array_filter($scores, function ($x) use ($lo, $hi, $k) { return ($k === 0 || $x >= $lo) && $x <= $hi; });
        $range = '';
        if ($in) { $a = min($in); $b = max($in); $range = $a === $b ? (string)$a : $a . '～' . $b; }
        $out[] = array_merge($lv, rm_level_color($k), ['range'=>$range]);
    }
    return $out;
}

function rm_level_color(int $rank): array
{
    return RM_LEVEL_COLORS[min($rank, count(RM_LEVEL_COLORS) - 1)];
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

/** 風險確認編號：前綴＋填表年度＋流水（例 R2026-001）；全站同年度連號，已配出的不回收 */
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
