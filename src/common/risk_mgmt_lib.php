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
 * 風險評估（嚴重度／頻率／風險等級）比照 PFMEA 的「評級對照表」概念，但**全部由管理員在模組設定維護，
 * 程式裡不寫死任何一級的名稱、分數或門檻**：
 *   rm_severity / rm_frequency  級距清單（值、名稱、說明）
 *   rm_method                   分數算法 multiply(嚴重度×頻率) / add(相加) / max(取大)
 *   rm_levels                   風險等級門檻（名稱、起算分數、是否需要因應措施）
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
        'rm_severity'  => [
            ['v'=>1,'label'=>'輕微','desc'=>'影響很小，可立即處理'],
            ['v'=>2,'label'=>'低',  'desc'=>'局部影響，可在日常作業中處理'],
            ['v'=>3,'label'=>'中',  'desc'=>'影響單一流程或單一客戶'],
            ['v'=>4,'label'=>'高',  'desc'=>'影響多個流程或造成客訴／損失'],
            ['v'=>5,'label'=>'嚴重','desc'=>'影響公司營運、法規或重大客戶'],
        ],
        'rm_frequency' => [
            ['v'=>1,'label'=>'極少','desc'=>'幾年才發生一次'],
            ['v'=>2,'label'=>'偶爾','desc'=>'一年發生一次左右'],
            ['v'=>3,'label'=>'有時','desc'=>'每季發生一次左右'],
            ['v'=>4,'label'=>'經常','desc'=>'每月發生'],
            ['v'=>5,'label'=>'頻繁','desc'=>'每週以上發生'],
        ],
        'rm_method'    => 'multiply',
        'rm_levels'    => [
            ['name'=>'低',  'min'=>0,  'action'=>false],
            ['name'=>'中',  'min'=>6,  'action'=>false],
            ['name'=>'高',  'min'=>12, 'action'=>true],
            ['name'=>'極高','min'=>20, 'action'=>true],
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
            severity    TINYINT NULL COMMENT '嚴重度（值取自 rm_severity 設定）',
            frequency   TINYINT NULL COMMENT '頻率（值取自 rm_frequency 設定）',
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

/** 級距清單（嚴重度／頻率）驗證與整理。回傳 [清單, 錯誤訊息]；錯誤訊息非空代表不可存 */
function rm_normalize_scale($raw, string $title): array
{
    if (!is_array($raw) || count($raw) < 2 || count($raw) > 10) return [[], $title . '至少要有 2 級、最多 10 級'];
    $out = []; $seen = [];
    foreach ($raw as $r) {
        $v = (int)($r['v'] ?? 0);
        $label = mb_substr(trim((string)($r['label'] ?? '')), 0, 20);
        if ($v < 1 || $v > 99) return [[], $title . '的數值必須是 1～99 的整數'];
        if (isset($seen[$v])) return [[], $title . '的數值 ' . $v . ' 重複了'];
        if ($label === '') return [[], $title . '的數值 ' . $v . ' 沒有填名稱'];
        $seen[$v] = 1;
        $out[] = ['v'=>$v, 'label'=>$label, 'desc'=>mb_substr(trim((string)($r['desc'] ?? '')), 0, 200)];
    }
    usort($out, function ($a, $b) { return $a['v'] <=> $b['v']; });
    return [$out, ''];
}

function rm_normalize_levels($raw): array
{
    if (!is_array($raw) || count($raw) < 1 || count($raw) > 8) return [[], '風險等級至少 1 級、最多 8 級'];
    $out = []; $seen = [];
    foreach ($raw as $r) {
        $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 10);
        $min  = (int)($r['min'] ?? 0);
        if ($name === '') return [[], '風險等級有一列沒有填名稱'];
        if ($min < 0 || $min > 9999) return [[], '風險等級「' . $name . '」的起算分數不合理'];
        if (isset($seen[$min])) return [[], '兩個風險等級的起算分數都是 ' . $min . '，無法分辨'];
        $seen[$min] = 1;
        $out[] = ['name'=>$name, 'min'=>$min, 'action'=>!empty($r['action'])];
    }
    usort($out, function ($a, $b) { return $a['min'] <=> $b['min']; });
    $out[0]['min'] = 0;       // 最低一級一定從 0 起算，否則低分數會「落在所有等級之外」算不出等級
    return [$out, ''];
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
    foreach ((array)$set['rm_severity'] as $r)  if ((int)$r['v'] === $sev)  $okS = true;
    foreach ((array)$set['rm_frequency'] as $r) if ((int)$r['v'] === $freq) $okF = true;
    if (!$okS || !$okF) return null;
    $m = (string)($set['rm_method'] ?? 'multiply');
    $score = $m === 'add' ? $sev + $freq : ($m === 'max' ? max($sev, $freq) : $sev * $freq);
    $hit = null; $rank = 0;
    foreach ((array)$set['rm_levels'] as $i => $lv) {
        if ($score >= (int)$lv['min']) { $hit = $lv; $rank = $i; }
    }
    if (!$hit) return null;
    return ['score'=>$score, 'name'=>(string)$hit['name'], 'rank'=>$rank, 'action'=>!empty($hit['action'])];
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
