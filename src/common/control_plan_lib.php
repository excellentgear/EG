<?php
/**
 * 管制計畫 CP（Control Plan / 類似 QC 工程圖）唯一實作
 * 對應 AS9100 的 Control Plan，AIAG 三段式（試作 / 試產 / 量產），一張 CP 只屬於一個階段。
 *
 * ── 這份庫的存在理由（鐵律4）────────────────────────────────────────────
 * CP 的每一欄在本系統都已經有人填過，只是散在三個模組，靠 process_no 這個數字鍵串起來：
 *   製程列   ← bom_ing（被標為 AS 認證的那張訂單所綁的製令，依 bom_sn 排序）
 *   產品特性 ← ss_item.ctrl_point / q_char（SIP 檢驗項目）
 *   公差     ← ss_item.up_limit / lo_limit（計量值）、q_char（屬性值）
 *   檢驗方法／檢具編號 ← ss_item.method + tool_no
 *   頻率     ← ss_item.freq（SIP 優先，SIP 沒填才退回 cp_stage.default_freq）→ 抽樣規則
 *   特殊特性 ← pfmea_item.classification
 *   管制方法 ← pfmea_item.prevention_controls / detection_controls
 * 所以「自動帶入」不是另存一份資料，而是即時去那三個地方讀。CP 存下來的是「人確認過的版本」。
 *
 * ── 三個不處理就會出錯的資料事實（都是實測，不要憑直覺改）──────────────
 * ⑴ bom_ing.machine_id 只有 213/84068（0.25%）有值，機台一律從報工紀錄
 *    pm_process_daily_report.machine_id 取（4526/4526 全有值），顯示優先 machine_list.field_no。
 * ⑵ ss_ver 只有 17/71 是 approved，自動帶入一律只取 approved 版次——草稿還沒定案，
 *    進了 CP 就是把沒人審過的公差印在管制計畫上。
 * ⑶ ss_item.up_limit 只有 48/294 有值，那不是資料缺漏：外觀、精度等級這種「屬性值特性」
 *    本來就沒有上下限，規格寫在 q_char（例「表面無刮傷/壓傷」「圖面未標示，一般廠內做DIN 5內」）。
 *    所以 CP 的規格欄要同時吃 up/lo_limit 與 spec_text 兩種，不可只認數字。
 *
 * ── 製程列的來源（使用者定調，2026-10-02）──────────────────────────────
 * 「料號要認定設定為 AS 標籤所綁定的 BOM 製程，不是隨便找料號底下的一張 BOM」
 * 所以 cp_order_boms() 的入口一律是「訂單」：管理員設定哪些訂單標籤視為 AS 認證，
 * 有那個標籤的訂單 → 它綁的製令 → 該製令的製程鏈。訂單標籤欄位由訂單追蹤模組提供，
 * 本庫只讀不寫；標籤機制尚未完成時 cp_as_cert_tags() 回空陣列，建議清單自動退回
 * 「已建 PFMEA 的料號」當母體（那批本來就是客戶要求 APQP 的對象）。
 */

if (!defined('CP_LIB_LOADED')) {
define('CP_LIB_LOADED', 1);

require_once __DIR__ . '/date_fmt_lib.php';
require_once __DIR__ . '/part_cost_lib.php';          // ppc_kg_set()：客供料製程集合，IQC 判定直接沿用，不重寫一份判準
require_once __DIR__ . '/packing_process_lib.php';    // pk_packing_process_nos()：包裝製程的唯一權威來源，不自己再存一份代號
require_once __DIR__ . '/qc_inspection_lib.php';      // qc_v2_items_by_process()：線上檢驗標準庫，SIP查無時的退路來源
require_once __DIR__ . '/process_type_lib.php'; // eg_process_type_id()：製程大類查詢，通用SIP退回要用

/** 本模組在 system_parameters 的分組名 */
define('CP_PARAM_GROUP', 'CONTROL_PLAN');
/** AS 文件綁定的模組代碼（走共用 asdoc_lib） */
define('CP_ASDOC_MODULE', 'control_plan');

/* ===================================================================
 * 一、schema 防護
 * =================================================================== */

/**
 * 表不存在時建起來。
 * 刻意「先 SHOW TABLES 確認不存在、且不在交易中，才下 DDL」——
 * CREATE TABLE 會造成 MySQL 隱式 commit，在交易裡下會讓外層 commit() 爆
 * 「There is no active transaction」而且資料其實已經寫進去了（本專案踩過兩次）。
 */
function cp_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ($db->inTransaction()) return;
        $has = $db->query("SHOW TABLES LIKE 'cp_doc'")->fetch();
        if ($has) return;
        // 表還沒建：請執行 views/QA/migrations/2026-10-02_control_plan.php --run
        // 這裡不自動建，因為預設資料（階段/特殊特性/反應計畫）應該由 migration 一次塞齊。
    } catch (Throwable $e) {}
}

/* ===================================================================
 * 二、權限
 * =================================================================== */

/**
 * 本模組權限。
 * 品管部門（org_role 的 qc_dept）與既有的 qc_manage_settings 視同可檢視＋可編輯，
 * 不必為了填 CP 再去指派一次角色（比照 ncr_control_log 的既有做法）。
 */
function cp_perms(PDO $db, ?int $uid = null): array
{
    $uid = $uid ?? (int)($_SESSION['id'] ?? 0);
    $p = ['view' => false, 'edit' => false, 'approve' => false, 'admin' => false, 'uid' => $uid];
    if ($uid <= 0) return $p;

    // 系統管理員
    $isSys = false;
    try {
        $st = $db->prepare("SELECT user_status FROM user WHERE id=?");
        $st->execute([$uid]);
        $isSys = ((int)$st->fetchColumn() === 1) || $uid === 1;
    } catch (Throwable $e) {}
    if ($isSys) return ['view'=>true,'edit'=>true,'approve'=>true,'admin'=>true,'uid'=>$uid];

    /* RBAC 權限碼。
       全站這套 RBAC 的實際用法是「role_code 本身就是權限碼」（既有模組的 role_features
       多半是空的），所以 role_code 與 feature_code 兩種都要讀，寫法比照
       qa_abnormal_lib.php 的 qab_user_role_codes()——只讀其中一種的話，
       管理員在權限設定頁建的角色會讀不到、人被判成沒權限（鐵律4：不另立一套讀法）。
       兩段都刻意不限 module：role_code 有 cp_ 前綴不會跟別的模組撞。

       注意 roles 的主鍵是 role_id 不是 id。本次實際踩過：寫成 r.id 會丟 SQL 錯誤，
       被 try/catch 吞掉之後權限碼變空陣列 → 明明已經指派角色的人全部被判成沒權限，
       而且用超級管理員測永遠測不到（他在上面那個 if 就已經 return）。 */
    $codes = [];
    $st = $db->prepare("SELECT DISTINCT r.role_code FROM user_roles ur
                          JOIN roles r ON r.role_id = ur.role_id WHERE ur.user_id = ?");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $c) $codes[] = (string)$c;
    $st = $db->prepare("SELECT DISTINCT rf.feature_code FROM user_roles ur
                          JOIN role_features rf ON rf.role_id = ur.role_id WHERE ur.user_id = ?");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $c) $codes[] = (string)$c;
    $codes = array_values(array_unique($codes));
    $has = function ($c) use ($codes) { return in_array($c, $codes, true); };

    $p['admin']   = $has('cp_admin');
    $p['approve'] = $p['admin'] || $has('cp_approve');
    $p['edit']    = $p['approve'] || $has('cp_edit');
    $p['view']    = $p['edit'] || $has('cp_view');

    // 品管部門視同可檢視＋可編輯
    if (!$p['edit']) {
        try {
            require_once __DIR__ . '/org_role_lib.php';
            $qcIds = eg_org_dept_ids($db, 'qc_dept');
            if ($qcIds) {
                $in = implode(',', array_map('intval', $qcIds));
                $st = $db->prepare(
                    "SELECT COUNT(*) FROM user_department_position_map
                      WHERE user_id = ? AND department_id IN ($in)"
                );
                $st->execute([$uid]);
                if ((int)$st->fetchColumn() > 0) { $p['view'] = true; $p['edit'] = true; }
            }
        } catch (Throwable $e) {}
    }
    return $p;
}

/* ===================================================================
 * 三、設定（階段 / 特殊特性 / 反應計畫 / AS 認證標籤 / 核准人）
 * =================================================================== */

/** 階段清單。$onlyActive=true 時只回啟用的（畫面挑選用；列印與既有資料一律全撈，否則停用後舊 CP 會顯示不出階段名稱） */
function cp_stages(PDO $db, bool $onlyActive = false): array
{
    $w = $onlyActive ? "WHERE is_active=1" : "";
    try {
        return $db->query("SELECT * FROM cp_stage $w ORDER BY sort_order, stage_id")
                  ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function cp_stage_one(PDO $db, int $stageId): ?array
{
    try {
        $st = $db->prepare("SELECT * FROM cp_stage WHERE stage_id=?");
        $st->execute([$stageId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

function cp_special_classes(PDO $db, bool $onlyActive = false): array
{
    $w = $onlyActive ? "WHERE is_active=1" : "";
    try {
        return $db->query("SELECT * FROM cp_special_class $w ORDER BY sort_order, class_id")
                  ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 依嚴重度(severity)／發生率(occurrence)比對出對應的特殊特性分類。
 * 依 AS 文件 3-TD-01「失效模式及效應分析應用辦法」第5.14節：
 *   關鍵特性 CC：嚴重度9~10
 *   重要特性 SC：嚴重度5~8 或 發生率4~10
 * 兩個條件（嚴重度落在範圍內、或發生率落在範圍內）是 OR，任一成立即算命中——
 * 3-TD-01 原文就是寫「嚴重度5~8發生率4~10」，不是兩者都要成立。
 *
 * 【為什麼不比對 PFMEA 填的 classification 文字，改依數值判定】
 * PFMEA 自己的自動判定（pfmea_classify_rule_get）目前是二分法，門檻只有
 * S 5~8 或 O 4~10 命中「重要特性」，否則「一般特性」——沒有 9~10 那一段，
 * 全站 109 筆 PFMEA 資料嚴重度最高只到 8，從未出現過 9~10，這解釋了為什麼
 * 一開始完全沒注意到 3-TD-01 定義的是三級分類（CP 模組最初用的是 AIAG 通用符號
 * ◇▽☆，不是這裡的 CC/SC，2026-10-02 由使用者指出並查證確認）。
 * 只比對文字永遠配不到「關鍵特性」；改成直接依數值判定，才能真正依 3-TD-01 的
 * 官方標準認定，不管 PFMEA 那邊填的文字是什麼、也不受 PFMEA 判定邏輯二分法的限制。
 *
 * 門檻存在 cp_special_class 的 sev_min/sev_max/occ_min/occ_max（管理員可在設定頁調，
 * 不寫死在程式碼——3-TD-01 文件若被修訂，門檻要跟著改）；只對「有設門檻」的分類
 * 做自動比對，「客戶指定」這種沒設門檻的分類不會被自動配到，只能手動選。
 * 多個分類同時命中時取 sort_order 最小（最嚴重）的那一個。
 */
function cp_special_class_match(PDO $db, ?int $severity, ?int $occurrence): ?array
{
    if ($severity === null && $occurrence === null) return null;
    $defs = cp_special_classes($db, true);
    foreach ($defs as $d) {
        $hit = false;
        if ($severity !== null && $d['sev_min'] !== null && $d['sev_max'] !== null
            && $severity >= (int)$d['sev_min'] && $severity <= (int)$d['sev_max']) $hit = true;
        if (!$hit && $occurrence !== null && $d['occ_min'] !== null && $d['occ_max'] !== null
            && $occurrence >= (int)$d['occ_min'] && $occurrence <= (int)$d['occ_max']) $hit = true;
        if ($hit) return $d;   // cp_special_classes() 已依 sort_order 排序，第一個命中的就是最嚴重的
    }
    return null;
}

/**
 * 預設反應計畫（管理員在 cp_reaction_opt 把其中一列 is_default=1）。
 * 沒有指定時回第一個啟用的——總比整欄空白好（那是稽核必看的欄位），帶入後仍可逐列改。
 */
function cp_default_reaction(PDO $db): string
{
    try {
        $v = $db->query("SELECT opt_text FROM cp_reaction_opt
                          WHERE is_active=1 AND is_default=1
                          ORDER BY sort_order, opt_id LIMIT 1")->fetchColumn();
        if ($v !== false && trim((string)$v) !== '') return (string)$v;
    } catch (Throwable $e) {}
    try {
        $v = $db->query("SELECT opt_text FROM cp_reaction_opt WHERE is_active=1
                          ORDER BY sort_order, opt_id LIMIT 1")->fetchColumn();
        return $v !== false ? (string)$v : '';
    } catch (Throwable $e) { return ''; }
}

function cp_reaction_opts(PDO $db, bool $onlyActive = true): array
{
    $w = $onlyActive ? "WHERE is_active=1" : "";
    try {
        return $db->query("SELECT * FROM cp_reaction_opt $w ORDER BY sort_order, opt_id")
                  ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 管制方法快選庫（cp_ctrl_method_default，使用者 2026-10-06 交辦）：管理員維護
 * 「特性名稱關鍵字 → 預設管制方法」，自動帶入時依**特性自己的名稱**（ss_item.ctrl_point）
 * 比對套用——刻意跟稍早修掉的 bug（管制方法照搬製程層級PFMEA、外觀被套到齒輪咬合
 * 的管制方法）走不同維度：這裡比對的是這一列特性自己叫什麼，不是它所屬製程最嚴重
 * 的失效模式是什麼，所以不會錯套到不相干的特性。
 */
function cp_ctrl_method_defaults(PDO $db, bool $onlyActive = true): array
{
    $w = $onlyActive ? "WHERE is_active=1" : "";
    try {
        return $db->query("SELECT * FROM cp_ctrl_method_default $w ORDER BY sort_order, id")
                  ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 依特性名稱找預設管制方法：CONTAINS 比對（match_text 是 ctrl_point 的子字串），
 * 多筆命中時取 **match_text 最長**的那一筆（越具體的關鍵字優先，例如「表面外觀不良」
 * 要贏過「外觀」），長度相同再依 sort_order。查無命中回空字串（自動帶入維持留白，
 * 不是每個特性都一定要有管理員設過的管制方法）。
 */
function cp_ctrl_method_match(PDO $db, string $ctrlPoint): string
{
    $ctrlPoint = trim($ctrlPoint);
    if ($ctrlPoint === '') return '';
    $defs = cp_ctrl_method_defaults($db, true);
    $best = null; $bestLen = -1;
    foreach ($defs as $d) {
        $mt = trim((string)($d['match_text'] ?? ''));
        if ($mt === '' || mb_strpos($ctrlPoint, $mt) === false) continue;
        $len = mb_strlen($mt);
        if ($len > $bestLen) { $bestLen = $len; $best = $d; }
    }
    return $best ? (string)$best['control_method'] : '';
}

/**
 * 讀本模組的 system_parameters 設定值。
 * 【注意 param_value 是 json 型別（NOT NULL）】所以存進去的一律是 JSON、讀出來要 decode。
 * 本次實際踩過：把空清單存成空字串 '' 會丟 MySQL 3140「Invalid JSON text: The document is empty」，
 * 而「清空設定」正是最常見的操作。既有模組（ORDER_ANALYSIS 的 scope_master 存 [77,112]）
 * 本來就是存 JSON 陣列，照同一套做。
 */
/**
 * 請求內快取（可清除）。寫法比照 qc_tool_display_lib.php 的 qc_tool_disp_cache()——
 * 單純的 static 快取從外部清不掉，會出現「同一個請求內存完設定再重算，卻吃到舊值」
 * （資料稽核的 dqa_excl_map() 踩過這個坑：規則存進去了、當下重算卻完全沒作用）。
 */
function cp_param_cache(?array $set = null, bool $clear = false): ?array
{
    static $c = null;
    if ($clear) { $c = null; return null; }
    if ($set !== null) $c = $set;
    return $c;
}
function cp_param_cache_clear(): void { cp_param_cache(null, true); }

function cp_param(PDO $db, string $key, $default = null)
{
    $cache = cp_param_cache();
    if ($cache === null) {
        $cache = [];
        try {
            $st = $db->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group=?");
            $st->execute([CP_PARAM_GROUP]);
            foreach ($st as $r) {
                $v = json_decode((string)$r['param_value'], true);
                // 舊值可能是純量（例最初存成 "8"），decode 失敗就沿用原字串，不要整個丟掉
                $cache[$r['param_key']] = (json_last_error() === JSON_ERROR_NONE) ? $v : $r['param_value'];
            }
        } catch (Throwable $e) {}
        cp_param_cache($cache);
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function cp_param_save(PDO $db, string $key, $val): void
{
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    if ($json === false) $json = 'null';
    $st = $db->prepare(
        "INSERT INTO system_parameters (param_group, param_key, param_value, updated_at)
         VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE param_value=VALUES(param_value), updated_at=NOW()"
    );
    $st->execute([CP_PARAM_GROUP, $key, $json]);
    cp_param_cache_clear();
}

/* ===================================================================
 * 三之二、「哪些訂單需要建 CP」＝訂單追蹤的稽核製程標籤
 *
 * 使用者定調（2026-10-02）：「NewOrder_Track.php 的設定已經增加設定『稽核製程』的標籤，
 * 請認定有此標籤之訂單為需要 CP 之訂單」。
 *
 * 標籤的唯一實作在 src/common/order_as_tag_lib.php，本庫只讀不寫。結構：
 *   定義 ot_as_proc_tag.kind 有三種
 *     process ＝管理員自訂的「稽核製程」（綁 process_type_id 製程大類）→ **這種才需要 CP**
 *     fixed   ＝內建三選項：全製／單製非AS認證／廠內治具 → 不需要
 *     other   ＝管理員自己加的其他固定選項（實測有一筆「單/全製含齒研(不列AS認證)」）→ 不需要
 *   訂單 order_track.as_tag_id ＋ as_tag_scope（single／full／none，一張訂單只有一個標籤）
 *
 * 所以判定就是一句話：**訂單的 as_tag_id 指向 kind='process' 的定義**。
 * 預設認定全部稽核製程（零設定就生效＝使用者要的），但允許管理員排除特定幾個
 * （例如新加了一道稽核製程但暫時還不做 CP）；存的是「排除名單」而不是「納入名單」，
 * 這樣管理員之後在訂單追蹤新增一道稽核製程時，CP 這邊會自動跟上、不必回來補設定。
 * =================================================================== */

/** 稽核製程的標籤定義（kind='process'）。$onlyActive=false 連停用的也回（舊 CP 要顯示得出名稱） */
function cp_as_tag_defs(PDO $db, bool $onlyActive = true): array
{
    $w = $onlyActive ? " AND t.is_active=1" : "";
    try {
        $st = $db->query(
            "SELECT t.tag_id, t.proc_name, t.scope, t.process_type_id, t.sub_no_json,
                    t.is_active, t.sort_order
               FROM ot_as_proc_tag t
              WHERE t.kind='process' $w
              ORDER BY t.sort_order, t.tag_id"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 被管理員排除的稽核製程標籤 id（有這個標籤但不要求建 CP 的那幾個）。
 * 空＝全部稽核製程都需要（預設）。
 */
function cp_excluded_as_tags(PDO $db): array
{
    $v = cp_param($db, 'excluded_as_tags', []);
    if (is_array($v)) return array_values(array_filter(array_map('intval', $v)));
    // 相容舊格式（曾存成逗號字串或單一數字）
    if (is_numeric($v)) return [(int)$v];
    $s = trim((string)$v);
    return $s === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $s))));
}

function cp_excluded_as_tags_save(PDO $db, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    sort($ids);
    cp_param_save($db, 'excluded_as_tags', $ids);   // 存 JSON 陣列（空陣列也是合法 JSON）
}

/**
 * 真正「需要建 CP」的標籤 id 陣列＝啟用中的稽核製程，扣掉被排除的。
 * 停用的定義不納入新的認定（但既有 CP 仍看得到自己的快照標籤名稱）。
 */
function cp_required_as_tags(PDO $db): array
{
    $ex = cp_excluded_as_tags($db);
    $out = [];
    foreach (cp_as_tag_defs($db, true) as $d) {
        $id = (int)$d['tag_id'];
        if (in_array($id, $ex, true)) continue;
        $out[] = $id;
    }
    return $out;
}

/**
 * 標籤顯示名稱。語意與訂單追蹤那邊一致：
 *   single ＝「單製○○」客戶送料來、只做這一道稽核製程 → CP 只涵蓋那一道
 *   full   ＝「全製含○○」從頭做到成品、過程中有這一道 → CP 涵蓋整條製程鏈
 * 刻意只做顯示用的簡版、不去呼叫 ot_astag_make_label()：那一支要連固定三選項與
 * both 變體一起處理，這裡只需要「稽核製程 × single/full」兩種。
 */
function cp_as_tag_label(?string $procName, ?string $scope): string
{
    $n = trim((string)$procName);
    if ($n === '') return '';
    switch ((string)$scope) {
        case 'single': return '單製' . $n;
        case 'full':   return '全製含' . $n;
    }
    return $n;
}

/**
 * 某一張訂單的稽核製程標籤（含「需不需要 CP」的判定結果與理由）。
 * 回 null＝訂單不存在；need_cp=false 時 reason 一定有話可說——
 * 「為什麼這張訂單不用建 CP」是使用者會當場問的問題，不能只回一個 false。
 */
function cp_order_as_tag(PDO $db, int $orderId): ?array
{
    if ($orderId <= 0) return null;
    try {
        $st = $db->prepare(
            "SELECT o.Order_id, o.as_tag_id, o.as_tag_scope, o.as_tag_at,
                    t.kind, t.proc_name, t.scope AS def_scope, t.is_active
               FROM order_track o
               LEFT JOIN ot_as_proc_tag t ON t.tag_id = o.as_tag_id
              WHERE o.Order_id = ?"
        );
        $st->execute([$orderId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
    } catch (Throwable $e) { return null; }

    $tagId = (int)($r['as_tag_id'] ?? 0);
    $kind  = (string)($r['kind'] ?? '');
    $scope = (string)($r['as_tag_scope'] ?? '');
    $req   = cp_required_as_tags($db);
    $need  = ($tagId > 0 && $kind === 'process' && in_array($tagId, $req, true));

    $why = '';
    if ($tagId <= 0) {
        $why = '這張訂單還沒設定稽核製程標籤（訂單追蹤的訂單跳窗標題右側可以設定）。';
    } elseif ($kind === 'fixed') {
        $why = '標籤是「' . $r['proc_name'] . '」＝內建選項不是稽核製程，依認定不需要管制計畫。';
    } elseif ($kind === 'other') {
        $why = '標籤是「' . $r['proc_name'] . '」＝其他固定選項（不列 AS 認證），依認定不需要管制計畫。';
    } elseif ($kind === 'process' && !in_array($tagId, $req, true)) {
        $why = '稽核製程「' . $r['proc_name'] . '」已被管理員在管制計畫設定裡排除，或該定義已停用。';
    }

    return [
        'order_id'  => (int)$r['Order_id'],
        'tag_id'    => $tagId ?: null,
        'kind'      => $kind,
        'scope'     => $scope,
        'proc_name' => (string)($r['proc_name'] ?? ''),
        'label'     => cp_as_tag_label($r['proc_name'] ?? '', $scope),
        'is_active' => $r['is_active'] !== null ? (int)$r['is_active'] : null,
        'need_cp'   => $need,
        'reason'    => $why,
        'tagged_at' => $r['as_tag_at'] ?? null,
        /* 這張 CP 該涵蓋多少製程：single＝客戶送料只做那一道、full＝整條製程鏈。
           自動帶入取的是該訂單綁的製令的完整製程鏈，單製的製令本來就只有那幾道，
           所以不必在這裡裁切；但畫面要講出來，否則使用者不知道範圍對不對。 */
        'scope_hint' => $scope === 'single'
            ? '單製：客戶送料來、只做這一道稽核製程，這張 CP 只需涵蓋該製程'
            : ($scope === 'full' ? '全製含：從頭做到成品，這張 CP 要涵蓋整條製程鏈' : ''),
    ];
}

/**
 * 稽核製程標籤機制現況偵測。
 * 回 ['ready'=>bool, 'reason'=>string, 'n_tag'=>int, 'n_order'=>int, 'n_part'=>int]
 */
function cp_order_tag_status(PDO $db): array
{
    $base = ['ready' => false, 'n_tag' => 0, 'n_order' => 0, 'n_part' => 0, 'reason' => ''];
    try {
        if (!$db->query("SHOW TABLES LIKE 'ot_as_proc_tag'")->fetch()) {
            $base['reason'] = '訂單追蹤的稽核製程標籤還沒建立（找不到 ot_as_proc_tag）。';
            return $base;
        }
        $cols = $db->query("SHOW COLUMNS FROM order_track")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('as_tag_id', $cols, true)) {
            $base['reason'] = 'order_track 還沒有 as_tag_id 欄位，訂單還無法掛稽核製程標籤。';
            return $base;
        }
    } catch (Throwable $e) {
        $base['reason'] = '偵測失敗：' . $e->getMessage();
        return $base;
    }

    $req = cp_required_as_tags($db);
    if (!$req) {
        $base['reason'] = '目前沒有任何啟用中、且未被排除的「稽核製程」標籤定義'
                        . '（訂單追蹤→設定→稽核製程標籤可以新增），所以還認定不出哪些訂單需要管制計畫。';
        return $base;
    }
    $in = implode(',', array_map('intval', $req));
    $nOrd = 0; $nPart = 0;
    try {
        $r = $db->query(
            "SELECT COUNT(*) n_ord, COUNT(DISTINCT d_id_ID) n_part
               FROM order_track
              WHERE as_tag_id IN ($in) AND (Order_status IS NULL OR Order_status <> 6)"
        )->fetch(PDO::FETCH_ASSOC);
        $nOrd  = (int)($r['n_ord'] ?? 0);
        $nPart = (int)($r['n_part'] ?? 0);
    } catch (Throwable $e) {}

    return ['ready' => true, 'n_tag' => count($req), 'n_order' => $nOrd, 'n_part' => $nPart, 'reason' => ''];
}

/* ===================================================================
 * 四、編號與版次（一律依「表單日期」這個業務日期，不是建檔當天）
 * =================================================================== */

/**
 * 產生 CP 編號 CP-YYYYMMDD-NNN。
 * 依表單日期而不是今天——補歷史紙本時編號才跟表單上的日期對得起來（ai-rules/16 第三之四節同一精神）。
 * $excludeId 是「重編自己」時要排除的 cp_id，否則同一天重算會一直往後跳號。
 */
function cp_next_no(PDO $db, ?string $formDate, int $excludeId = 0): string
{
    $d = $formDate && $formDate !== '0000-00-00' ? $formDate : date('Y-m-d');
    $ts = strtotime($d);
    if (!$ts) $ts = time();
    $pre = 'CP-' . date('Ymd', $ts) . '-';
    $sql = "SELECT cp_no FROM cp_doc WHERE cp_no LIKE ?";
    $par = [$pre . '%'];
    if ($excludeId > 0) { $sql .= " AND cp_id <> ?"; $par[] = $excludeId; }
    $sql .= " ORDER BY cp_no DESC LIMIT 1";
    try {
        $st = $db->prepare($sql);
        $st->execute($par);
        $last = (string)($st->fetchColumn() ?: '');
        $n = $last !== '' ? (int)substr($last, strlen($pre)) : 0;
        return $pre . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return $pre . '001';
    }
}

/**
 * 表單日期被改過時重編編號。
 * 只重編「還是草稿」的——已送出/已核准的紙本上印著舊號，改了對不起來。
 * 回 ['changed'=>bool,'old'=>string,'new'=>string]
 */
function cp_sync_no(PDO $db, int $cpId): array
{
    $st = $db->prepare("SELECT cp_no, form_date, status FROM cp_doc WHERE cp_id=?");
    $st->execute([$cpId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['changed' => false, 'old' => '', 'new' => ''];
    if ($row['status'] !== 'draft') return ['changed' => false, 'old' => $row['cp_no'], 'new' => $row['cp_no']];

    $want = cp_next_no($db, $row['form_date'], $cpId);
    // 已經是同一天的號碼就不動（避免每次存檔都跳號）
    $curPre = substr((string)$row['cp_no'], 0, 12);   // CP-YYYYMMDD-
    $wantPre = substr($want, 0, 12);
    if ($curPre === $wantPre) return ['changed' => false, 'old' => $row['cp_no'], 'new' => $row['cp_no']];

    $up = $db->prepare("UPDATE cp_doc SET cp_no=? WHERE cp_id=?");
    $up->execute([$want, $cpId]);
    return ['changed' => true, 'old' => (string)$row['cp_no'], 'new' => $want];
}

/** 下一個版次 A→B→C（空的回 A） */
function cp_next_ver(?string $cur): string
{
    $c = strtoupper(trim((string)$cur));
    if ($c === '') return 'A';
    if (preg_match('/^[A-Y]$/', $c)) return chr(ord($c) + 1);
    if ($c === 'Z') return 'AA';
    if (preg_match('/^([A-Z]*)([A-Y])$/', $c, $m)) return $m[1] . chr(ord($m[2]) + 1);
    return $c;
}

/* ===================================================================
 * 五、來源資料：AS 認證訂單 → 製令 → 製程
 * =================================================================== */

/**
 * 該訂單綁到的製令。
 * 兩種來源都要讀（記憶 ship_order_bind_two_sources 的教訓：綁定有兩種來源，
 * 只讀一種會安靜地少掉一半）：
 *   ⑴ bom_order_process_map（訂單↔製令多對多分配表，2957 筆）
 *   ⑵ bom.o_order_id（直接綁定；注意它存的是 order_track.Order_id 整數主鍵，
 *      不是訂單編號——資料字典註解是錯的，用 Order_oo 去 JOIN 會零筆）
 * 96% 的訂單只對一張製令，但有對 2~8 張的，所以一律回陣列讓人選，不自動挑。
 */
function cp_order_boms(PDO $db, int $orderId): array
{
    if ($orderId <= 0) return [];
    $boms = [];
    try {
        $st = $db->prepare(
            "SELECT bom, SUM(allocated_qty) qty FROM bom_order_process_map
              WHERE order_id = ? GROUP BY bom"
        );
        $st->execute([$orderId]);
        foreach ($st as $r) $boms[(string)$r['bom']] = ['bom' => (string)$r['bom'], 'alloc_qty' => $r['qty'], 'src' => 'map'];
    } catch (Throwable $e) {}
    try {
        $st = $db->prepare("SELECT bom FROM bom WHERE o_order_id = ?");
        $st->execute([$orderId]);
        foreach ($st as $r) {
            $b = (string)$r['bom'];
            if (!isset($boms[$b])) $boms[$b] = ['bom' => $b, 'alloc_qty' => null, 'src' => 'direct'];
        }
    } catch (Throwable $e) {}
    if (!$boms) return [];

    // 補製令本身的資訊
    $in = implode(',', array_fill(0, count($boms), '?'));
    $keys = array_keys($boms);
    try {
        $st = $db->prepare(
            "SELECT bom, sqty, processing_state, Client_Name, d_id, d_setting_id, closed_at
               FROM bom WHERE bom IN ($in)"
        );
        $st->execute($keys);
        foreach ($st as $r) {
            $b = (string)$r['bom'];
            if (!isset($boms[$b])) continue;
            $boms[$b] += [
                'sqty'       => $r['sqty'],
                'state'      => $r['processing_state'],
                'client'     => $r['Client_Name'],
                'd_id'       => $r['d_id'],
                'd_setting_id' => $r['d_setting_id'],
                'closed_at'  => $r['closed_at'],
            ];
        }
    } catch (Throwable $e) {}

    foreach ($boms as $b => &$v) {
        $v['open_date'] = cp_bom_open_date($b);
        $v['proc_count'] = 0;
    }
    unset($v);

    // 各製令有幾道製程（讓人選的時候看得出差異）
    try {
        $st = $db->prepare("SELECT bom, COUNT(*) c FROM bom_ing WHERE bom IN ($in) GROUP BY bom");
        $st->execute($keys);
        foreach ($st as $r) {
            $b = (string)$r['bom'];
            if (isset($boms[$b])) $boms[$b]['proc_count'] = (int)$r['c'];
        }
    } catch (Throwable $e) {}

    $out = array_values($boms);
    usort($out, function ($a, $b) { return strcmp((string)$b['bom'], (string)$a['bom']); });
    return $out;
}

/**
 * 製令開立日由編號回推（B- + 民國年3碼 + MMDD + 流水）。
 * 不用 bom.Created_At——那是 ERP 匯入這套系統的時間，實測 400 筆有 13 筆差到 3 天；
 * 也不用 closed_at——記憶 bom_closed_at_gap：92% 舊已結案資料是 NULL。
 */
function cp_bom_open_date(string $bom): ?string
{
    if (preg_match('/^[A-Za-z]-(\d{3})(\d{2})(\d{2})/', trim($bom), $m)) {
        $y = (int)$m[1] + 1911;
        $mo = (int)$m[2]; $d = (int)$m[3];
        if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    return null;
}

/**
 * 該製令的製程鏈。
 * 排序一律用 bom_sn（記憶 bom_process_order_is_bom_sn：processing_sequence 是排程順序
 * 且多數 NULL，拿來排序會把有值那站排到最前面）。
 * 機台從報工紀錄取（見檔頭資料事實⑴）。
 */
function cp_bom_processes(PDO $db, string $bom): array
{
    $bom = trim($bom);
    if ($bom === '') return [];
    $rows = [];
    // 注意欄位名：maker_list 的是小寫 maker_id_no，廠商「名稱」存在 maker_id 欄（資料字典的命名就是這樣）。
    // 這裡刻意不用 try/catch 吞掉錯誤——欄位名打錯時若靜默回空陣列，畫面只會顯示「查不到製程」，
    // 而 bom_choices 又用另一支 SQL 算得出道數，兩邊打架且完全查不出原因（本次實際踩過）。
    $st = $db->prepare(
        "SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, bi.maker_id_no, bi.sqty,
                pn.ProcessName AS process_name,
                mk.maker_id AS maker_name, mk.internal AS maker_internal
           FROM bom_ing bi
           LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
           LEFT JOIN maker_list mk ON mk.maker_id_no = bi.maker_id_no
          WHERE bi.bom = ?
          ORDER BY bi.bom_sn"
    );
    $st->execute([$bom]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    // 機台：該製程報工紀錄用過的機台（取最近一次；多台時全部列出）
    $fids = array_values(array_filter(array_map(function ($r) { return (int)$r['bom_ing_fid']; }, $rows)));
    $mMap = [];
    if ($fids) {
        $in = implode(',', array_fill(0, count($fids), '?'));
        try {
            $st = $db->prepare(
                "SELECT r.bom_ing_fid,
                        COALESCE(NULLIF(TRIM(m.field_no),''), m.machine) AS mname,
                        MAX(r.report_date) AS last_date
                   FROM pm_process_daily_report r
                   JOIN machine_list m ON m.machine_id = r.machine_id
                  WHERE r.bom_ing_fid IN ($in)
                  GROUP BY r.bom_ing_fid, mname
                  ORDER BY last_date DESC"
            );
            $st->execute($fids);
            foreach ($st as $r) {
                $f = (int)$r['bom_ing_fid'];
                if (!isset($mMap[$f])) $mMap[$f] = [];
                $n = trim((string)$r['mname']);
                if ($n !== '' && !in_array($n, $mMap[$f], true)) $mMap[$f][] = $n;
            }
        } catch (Throwable $e) {}
    }

    $out = [];
    foreach ($rows as $i => $r) {
        $f = (int)$r['bom_ing_fid'];
        $isOut = $r['maker_internal'] !== null ? ((int)$r['maker_internal'] === 0) : (trim((string)$r['maker_id_no']) !== '');
        $out[] = [
            'seq'          => $i + 1,
            'bom_ing_fid'  => $f,
            'bom_sn'       => (int)$r['bom_sn'],
            'process_no'   => $r['process_no'] !== null ? (int)$r['process_no'] : null,
            'process_name' => (string)($r['process_name'] ?? ''),
            'machine'      => isset($mMap[$f]) ? implode('、', array_slice($mMap[$f], 0, 3)) : '',
            'maker_id_no'  => (string)($r['maker_id_no'] ?? ''),
            'maker_name'   => (string)($r['maker_name'] ?? ''),
            'is_outsource' => $isOut ? 1 : 0,
            'src'          => 'bom',
        ];
    }
    return $out;
}

/* ===================================================================
 * 六、自動帶入（核心）
 * =================================================================== */

/**
 * 製程大類查詢（唯一實作在 process_type_lib.php 的 eg_process_type_id()，這裡保留
 * 同名包裝只是不必改既有呼叫端）。
 */
function cp_process_type_id(PDO $db, int $processNo): ?int
{
    return eg_process_type_id($db, $processNo);
}

/**
 * 通用文件（scope='general'）依「製程大類」退回：精準 process_no 比對找不到時，
 * 改找同一個製程大類（process_type_id）底下、綁在別的 process_no 上的通用文件。
 *
 * 為什麼需要這一層（2026-10-05 使用者交辦）：包裝製程代號現場有 168／169 兩個
 * （還可能繼續增加），檢驗標準卻是同一套——通用 SIP 若只能綁單一 process_no，
 * 不是兩個代號各建一份內容要手動同步的文件，就是新代號一出現又抓不到。
 * process_type 主檔本來就把同一類製程歸在一起（168／169 皆屬
 * process_type_id=16「雷刻與包裝」），用它當退回鍵，管理員只要把新製程代號
 * 掛進同一個 process_type，不必回頭改任何通用 SIP 的綁定。
 *
 * 刻意放在「精準 process_no 比對」之後、「ss_item_tpl 範本」之前：
 * 已經綁了自己專屬通用文件的製程（如 process_no=12 齒研）優先權不變，
 * 這一層只補「同大類底下沒有自己專屬通用文件」的那些製程代號。
 */
function cp_sip_general_doc_by_type(PDO $db, string $kind, int $processNo): ?array
{
    $typeId = cp_process_type_id($db, $processNo);
    if (!$typeId) return null;
    try {
        $sql = "SELECT d.doc_id, v.ver_id, v.ver_no
                  FROM ss_doc d
                  JOIN ss_ver v ON v.ver_id = d.cur_ver_id AND v.status='approved'
                  JOIN process_no pn ON pn.ProcessNo = d.process_no AND pn.process_type_id = ?
                 WHERE d.is_deleted=0 AND d.kind=? AND d.scope='general' AND d.process_no<>?
                 ORDER BY d.doc_id DESC LIMIT 1";
        $st = $db->prepare($sql);
        $st->execute([$typeId, $kind, $processNo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * qc_inspection_item 一列 → 跟 ss_item 相同形狀（下游顯示/計算邏輯不必另外判斷
 * 來源是哪一套系統）。cp_sip_items() 的 qcv2 退路與「出貨檢驗」內容（見
 * qc_v2_ship_items／cp_fqc_ship_items）共用同一份轉換，不要各自寫一次。
 */
function cp_qcv2_row_to_sip_item(array $r): array
{
    $std = trim((string)($r['standard_text'] ?? ''));
    $plus = $r['plus_tolerance']; $minus = $r['minus_tolerance'];
    $up = ''; $lo = ''; $qChar = '';
    if ((string)($r['result_type'] ?? '') === 'NUMERIC' && $std !== '' && is_numeric($std)) {
        $base = (float)$std;
        $up = $plus !== null ? (string)($base + (float)$plus) : $std;
        $lo = $minus !== null ? (string)($base - (float)$minus) : $std;
    } else {
        $qChar = $std;
    }
    return [
        'item_id' => $r['item_id'], 'seq' => (int)($r['sort_order'] ?? 0),
        'ctrl_point' => (string)($r['item_name'] ?? ''), 'q_char' => $qChar,
        'up_limit' => $up, 'lo_limit' => $lo,
        'method' => '', 'tool_no' => '', 'tool_id' => null, 'freq' => '',
        'note' => '線上檢驗標準' . (($r['item_code'] ?? '') !== '' ? '　編號' . $r['item_code'] : ''),
    ];
}

/**
 * 該料號／該製程的 SIP 檢驗項目。
 * 優先序：綁這個料號的 SIP → 該製程的通用 SIP → 同製程大類的通用 SIP（見
 * cp_sip_general_doc_by_type 說明）→ 該製程的檢驗項目預設值範本。
 * 一律只取 approved 版次（見檔頭資料事實⑵）。
 */
function cp_sip_items(PDO $db, ?int $partDId, ?int $processNo): array
{
    if ($processNo === null) return ['items' => [], 'src' => '', 'src_ref' => ''];

    $try = function ($scope) use ($db, $partDId, $processNo) {
        $sql = "SELECT d.doc_id, v.ver_id, v.ver_no
                  FROM ss_doc d
                  JOIN ss_ver v ON v.ver_id = d.cur_ver_id AND v.status='approved'
                 WHERE d.is_deleted=0 AND d.kind='sip' AND d.scope=? AND d.process_no=?";
        $par = [$scope, $processNo];
        if ($scope === 'part') { $sql .= " AND d.part_d_id=?"; $par[] = (int)$partDId; }
        $sql .= " ORDER BY d.doc_id DESC LIMIT 1";
        try {
            $st = $db->prepare($sql);
            $st->execute($par);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    };

    $doc = null; $src = '';
    if ($partDId) { $doc = $try('part'); if ($doc) $src = 'sip'; }
    if (!$doc)    { $doc = $try('general'); if ($doc) $src = 'sip_general'; }
    if (!$doc)    { $doc = cp_sip_general_doc_by_type($db, 'sip', $processNo); if ($doc) $src = 'sip_general_cat'; }

    if ($doc) {
        try {
            $st = $db->prepare(
                "SELECT i.item_id, i.seq, i.ctrl_point, i.q_char, i.up_limit, i.lo_limit,
                        i.method, i.tool_no, i.tool_id, i.freq, i.note
                   FROM ss_item i WHERE i.ver_id=? ORDER BY i.seq, i.item_id"
            );
            $st->execute([(int)$doc['ver_id']]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($items) {
                return ['items' => $items, 'src' => $src, 'src_ref' => 'ver' . $doc['ver_id']];
            }
        } catch (Throwable $e) {}
    }

    /* 退回「線上檢驗」(inspection_entry_v2.php) 自己的檢驗標準庫（qc_inspection_item，
       料號專屬、沒有通用版）——使用者 2026-10-06 明確要求：SIP 完全查無時（連通用、
       同製程大類都沒有）才退到這裡，不可以優先於正式 SIP 文件；比對一律用 process_no
       （2026-10-06 已補欄位並回填，不信任舊有的自由文字 process_name 比對，同一製程
       現場有「車床」「車 床」兩種寫法）。欄位格式轉成跟 ss_item 一樣的形狀，下游顯示
       邏輯不必另外判斷來源是哪一套系統。 */
    if ($partDId) {
        $v2 = qc_v2_items_by_process($db, $partDId, $processNo);
        if ($v2) {
            return ['items' => array_map('cp_qcv2_row_to_sip_item', $v2), 'src' => 'qcv2',
                    'src_ref' => 'qcv2_part' . $partDId . '_proc' . $processNo];
        }
    }

    // 退回「依製程的檢驗項目預設值」範本（精準 process_no，查不到再退回同製程大類）
    $tplFetch = function (int $pno) use ($db) {
        try {
            $st = $db->prepare(
                "SELECT tpl_id AS item_id, seq, ctrl_point, q_char, up_limit, lo_limit,
                        method, tool_no, tool_id, freq, note
                   FROM ss_item_tpl
                  WHERE is_active=1 AND tpl_kind='proc' AND process_no=? ORDER BY seq, tpl_id"
            );
            $st->execute([$pno]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    };
    $items = $tplFetch($processNo);
    if ($items) return ['items' => $items, 'src' => 'tpl', 'src_ref' => 'proc' . $processNo];

    $typeId = eg_process_type_id($db, $processNo);
    if ($typeId) {
        try {
            $st = $db->prepare(
                "SELECT t.process_no
                   FROM ss_item_tpl t
                   JOIN process_no pn ON pn.ProcessNo = t.process_no AND pn.process_type_id = ?
                  WHERE t.tpl_kind='proc' AND t.process_no<>? AND t.is_active=1
                  ORDER BY t.process_no LIMIT 1"
            );
            $st->execute([$typeId, $processNo]);
            $srcNo = $st->fetchColumn();
            if ($srcNo) {
                $items = $tplFetch((int)$srcNo);
                if ($items) return ['items' => $items, 'src' => 'tpl_cat', 'src_ref' => 'proc' . $srcNo];
            }
        } catch (Throwable $e) {}
    }

    return ['items' => [], 'src' => '', 'src_ref' => ''];
}

/**
 * 同製程大類（process_type_id）底下，有哪些「通用SIP」可以選（切換器用，使用者
 * 2026-10-06 明確要求：包裝常見同一大類底下分齒輪/非齒輪好幾份通用SIP，cp_sip_items()
 * 自動只會抓到大類裡第一份找到的，管理員/使用者要能自己換成另一份）。
 * 只回 scope='general' 且已核准的，排除 part 專屬文件（那個不該被挑去借給別的料號）；
 * 固定依 process_no 排序，不是「最新的排前面」——切換器要的是逐一比較，不是猜哪份新。
 */
function cp_sip_general_candidates(PDO $db, int $processNo): array
{
    $typeId = cp_process_type_id($db, $processNo);
    if (!$typeId) return [];
    try {
        $st = $db->prepare(
            "SELECT d.doc_id, d.process_no, pn.ProcessName AS process_name, v.ver_id, v.ver_no, d.title
               FROM ss_doc d
               JOIN ss_ver v ON v.ver_id = d.cur_ver_id AND v.status='approved'
               JOIN process_no pn ON pn.ProcessNo = d.process_no AND pn.process_type_id = ?
              WHERE d.is_deleted=0 AND d.kind='sip' AND d.scope='general'
              ORDER BY d.process_no, d.doc_id DESC"
        );
        $st->execute([$typeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 切換器選定某份通用SIP後，重新抓它的檢驗項目（格式與 cp_autofill_preview 組 items
 * 用的完全一樣，前端直接替換那一列的 items，不必另外寫一套映射）。$stageFreq 比照
 * autofill 的頻率覆蓋規則（SIP 自己的頻率優先，SIP 沒填才用階段預設）。
 */
function cp_sip_items_for_switch(PDO $db, int $processNo, string $stageFreq = ''): array
{
    $sip = cp_sip_items($db, null, $processNo);   // 切換器只挑 general，不走 part 專屬那一層
    $out = [];
    foreach ($sip['items'] as $k => $it) {
        $up = trim((string)($it['up_limit'] ?? ''));
        $lo = trim((string)($it['lo_limit'] ?? ''));
        $specText = trim((string)($it['q_char'] ?? ''));
        if ($up !== '' || $lo !== '') {
            $num = function ($v) {
                if ($v === '' || $v === null) return '';
                return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
            };
            $rangeTxt = $num($lo) . ' ~ ' . $num($up);
            $specText = $specText !== '' ? ($rangeTxt . '（' . $specText . '）') : $rangeTxt;
        }
        $itFreq = trim((string)($it['freq'] ?? ''));
        $freq = $itFreq !== '' ? $itFreq : $stageFreq;
        $ctrlPoint = trim((string)($it['ctrl_point'] ?? ''));
        $out[] = [
            'seq' => $k + 1, 'char_product' => $ctrlPoint, 'char_process' => '',
            'special_class_id' => null, 'special_class_text' => '', 'spec_text' => $specText,
            'up_limit' => $up, 'lo_limit' => $lo, 'eval_method' => trim((string)($it['method'] ?? '')),
            'tool_no' => trim((string)($it['tool_no'] ?? '')), 'tool_id' => (int)($it['tool_id'] ?? 0) ?: null,
            'sample_size' => '', 'sample_freq' => $freq, 'control_method' => cp_ctrl_method_match($db, $ctrlPoint), 'reaction_plan' => '',
            'src' => $sip['src'] ?: 'manual', 'src_ref' => $sip['src_ref'] ?? '', 'note' => trim((string)($it['note'] ?? '')),
        ];
    }
    return $out;
}

/**
 * 該料號的 PFMEA，依製程彙整出「特殊特性」與「管制方法」。
 * pfmea_item.process_code 存的是 process_no 的數字（實測 "12"、"4"），對得上。
 * 注意 pfmea_item.process_desc 存的是失效描述（「齒研中心定位不佳」）不是製程名稱，
 * 製程名稱一律查 process_no.ProcessName，不要用它。
 */
function cp_pfmea_by_process(PDO $db, ?int $partDId): array
{
    $out = ['doc_id' => null, 'by_proc' => []];
    if (!$partDId) return $out;
    try {
        $st = $db->prepare(
            "SELECT id FROM pfmea_doc WHERE is_deleted=0 AND part_d_id=? ORDER BY id DESC LIMIT 1"
        );
        $st->execute([(int)$partDId]);
        $docId = (int)($st->fetchColumn() ?: 0);
        if (!$docId) return $out;
        $out['doc_id'] = $docId;

        $st = $db->prepare(
            "SELECT process_code, classification, prevention_controls, detection_controls,
                    requirement, function_desc, severity, occurrence, detection, rpn
               FROM pfmea_item WHERE is_deleted=0 AND doc_id=? ORDER BY seq, id"
        );
        $st->execute([$docId]);
        foreach ($st as $r) {
            $pn = (int)$r['process_code'];
            if (!isset($out['by_proc'][$pn])) {
                $out['by_proc'][$pn] = ['class' => [], 'prev' => [], 'det' => [], 'req' => [], 'func' => [],
                                         'max_rpn' => 0, 'matched_class' => null];
            }
            $b = &$out['by_proc'][$pn];
            foreach ([['classification','class'],['prevention_controls','prev'],['detection_controls','det'],
                      ['requirement','req'],['function_desc','func']] as $pair) {
                $v = trim((string)($r[$pair[0]] ?? ''));
                if ($v !== '' && !in_array($v, $b[$pair[1]], true)) $b[$pair[1]][] = $v;
            }
            $b['max_rpn'] = max($b['max_rpn'], (int)$r['rpn']);

            // 依 3-TD-01：這個失效模式的 severity/occurrence 查出對應分類，
            // 一個製程下有好幾個失效模式時，整個製程取「最嚴重」的那一個（見 cp_special_class_match 排序）。
            $sev = $r['severity'] !== null ? (int)$r['severity'] : null;
            $occ = $r['occurrence'] !== null ? (int)$r['occurrence'] : null;
            $m = cp_special_class_match($db, $sev, $occ);
            if ($m) {
                $cur = $b['matched_class'];
                if (!$cur || (int)$m['sort_order'] < (int)$cur['sort_order']) $b['matched_class'] = $m;
            }
            unset($b);
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 自動帶入預覽。不寫入任何資料，只回「如果採用會長什麼樣」。
 * $opt: order_id（優先）或 part_d_id、bom（選定的製令）、stage_id
 */
function cp_autofill_preview(PDO $db, array $opt): array
{
    $stageId = (int)($opt['stage_id'] ?? 0);
    $stage   = $stageId ? cp_stage_one($db, $stageId) : null;
    $orderId = (int)($opt['order_id'] ?? 0);
    $partDId = (int)($opt['part_d_id'] ?? 0);
    $bom     = trim((string)($opt['bom'] ?? ''));

    $res = [
        'head' => [], 'src' => [], 'processes' => [],
        'pfmea_only' => [], 'bom_choices' => [], 'warn' => [], 'info' => [],
        'as_tag' => null,
    ];

    // --- 由訂單推料號與製令 ---
    $orderRow = null;
    if ($orderId > 0) {
        try {
            $st = $db->prepare(
                "SELECT o.Order_id, o.Order_oo, o.d_id, o.d_id_ID, o.Client_name, o.Client_name_ID,
                        o.Qty, o.Order_date, o.Specification, o.Processing_items
                   FROM order_track o WHERE o.Order_id=?"
            );
            $st->execute([$orderId]);
            $orderRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
        if ($orderRow) {
            if (!$partDId) $partDId = (int)($orderRow['d_id_ID'] ?? 0);

            /* 稽核製程標籤＝「這張訂單到底需不需要 CP」的唯一依據（使用者 2026-10-02 定調）。
               不需要時刻意只給警示、不擋下建立——補歷史資料、或標籤還沒設好就想先建 CP
               都是合理的；但畫面上一定要講清楚「依認定這張不需要」，否則使用者會以為
               系統認可了這張單需要 CP。 */
            $tag = cp_order_as_tag($db, $orderId);
            $res['as_tag'] = $tag;
            if ($tag && !$tag['need_cp']) {
                $res['warn'][] = '這張訂單依稽核製程標籤的認定**不需要**管制計畫：' . $tag['reason']
                               . '（仍可繼續建立，但請確認是刻意的）';
            } elseif ($tag && $tag['need_cp']) {
                $res['info'][] = '依稽核製程標籤認定需要管制計畫：' . $tag['label']
                               . '。' . $tag['scope_hint'];
            }

            $res['bom_choices'] = cp_order_boms($db, $orderId);
            if ($bom === '' && count($res['bom_choices']) === 1) {
                $bom = (string)$res['bom_choices'][0]['bom'];
            } elseif ($bom === '' && count($res['bom_choices']) > 1) {
                $res['warn'][] = '這張訂單綁了 ' . count($res['bom_choices'])
                    . ' 張製令，製程可能不同，請選定要用哪一張當製程來源。';
            } elseif ($bom === '' && !$res['bom_choices']) {
                $res['warn'][] = '這張訂單還沒有綁定製令，無法帶出製程。請先在生管模組建立或綁定製令。';
            }
        } else {
            $res['warn'][] = '找不到訂單 id=' . $orderId . '。';
        }
    }

    // --- 料號表頭 ---
    if ($partDId > 0) {
        try {
            $st = $db->prepare(
                "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Revision, d.Remark, d.Customer_Id,
                        c.customer, c.customer_full
                   FROM d_setting d
                   LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                  WHERE d.d_id=?"
            );
            $st->execute([$partDId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if ($p) {
                $res['head'] = [
                    'part_d_id'    => (int)$p['d_id'],
                    'part_no_text' => (string)$p['D_Setting_Id'],
                    'product_name' => (string)($p['Remark'] ?: ''),
                    'part_rev'     => (string)($p['Revision'] ?: ''),
                    'drawing_no'   => (string)($p['Drawing_No'] ?: ''),
                    'customer_id'  => (string)($p['Customer_Id'] ?: ''),
                    'customer_name'=> (string)($p['customer'] ?: ''),
                ];
            }
        } catch (Throwable $e) {}
    }
    if ($orderRow) {
        $res['head']['order_qty'] = $orderRow['Qty'];
        if (empty($res['head']['part_no_text'])) $res['head']['part_no_text'] = (string)$orderRow['d_id'];
        if (empty($res['head']['customer_name'])) $res['head']['customer_name'] = (string)$orderRow['Client_name'];
        if (empty($res['head']['product_name'])) $res['head']['product_name'] = (string)($orderRow['Specification'] ?: '');
    }

    // --- PFMEA ---
    $pf = cp_pfmea_by_process($db, $partDId ?: null);
    $res['head']['pfmea_doc_id'] = $pf['doc_id'];
    if (!$pf['doc_id'] && $partDId) {
        $res['info'][] = '這個料號還沒有建 PFMEA，所以「特殊特性」與「管制方法」兩欄帶不出來，需要人工填寫。';
    }

    // --- 製程列 ---
    $procs = $bom !== '' ? cp_bom_processes($db, $bom) : [];
    if ($bom !== '' && !$procs) {
        $res['warn'][] = '製令 ' . $bom . ' 查不到任何製程（bom_ing 無資料）。';
    }
    // 插入FQC合成列（見六之二節⑤）要在這裡做——下面的 items 迴圈才會自然幫這道合成列
    // 也撈一份 SIP 內容，不必另外寫一套抓取邏輯。
    if ($procs) $procs = cp_insert_fqc_row($db, $procs, $partDId ?: null);
    $tagSrc = $res['as_tag'] ?? null;
    $res['src'] = [
        'order_id'  => $orderId ?: null,
        'order_oo'  => $orderRow ? (string)$orderRow['Order_oo'] : null,
        'bom'       => $bom !== '' ? $bom : null,
        'bom_date'  => $bom !== '' ? cp_bom_open_date($bom) : null,
        'stage_id'  => $stageId ?: null,
        'stage_name'=> $stage ? (string)$stage['stage_name'] : '',
        // 標籤快照：存下來才能回答「這張 CP 當初是因為什麼認定而建的」（標籤之後可能被改）
        'as_tag_id'    => $tagSrc['tag_id'] ?? null,
        'as_tag_scope' => $tagSrc['scope'] ?? null,
        'as_tag_label' => $tagSrc['label'] ?? null,
    ];

    $stageFreq = $stage ? trim((string)($stage['default_freq'] ?? '')) : '';
    /* 反應計畫的預設值（管理員在設定裡把其中一列標為預設）。
       這一欄是稽核必看的，整欄空白一定被問；但它是業務判斷不能亂猜，
       所以做成「管理員指定一個最通用的預設」，帶入後仍可逐列改。 */
    $defReact  = cp_default_reaction($db);
    $seenProc  = [];

    foreach ($procs as $p) {
        $pn = $p['process_no'];
        if ($pn !== null) $seenProc[$pn] = true;

        // 總製程數＞門檻時插入的 FQC 合成列不綁 process_no，內容改查「出貨檢驗」（見 cp_insert_fqc_row）
        $sip = (($p['src'] ?? '') === 'fqc_insert_ship')
            ? cp_fqc_ship_items($db, $partDId ?: null)
            : cp_sip_items($db, $partDId ?: null, $pn);
        $pfp = ($pn !== null && isset($pf['by_proc'][$pn])) ? $pf['by_proc'][$pn] : null;

        /* 管制方法／特殊分類：2026-10-06 使用者實測抓到的錯誤——「外觀」這種定性特性
           被套上「齒輪咬合 X 0.0812」那組尺寸類失效模式的管制方法與 SC 分類，兩者完全
           無關。根因跟下面「製程特性」刻意留白是同一件事：PFMEA 資料目前只到「製程」
           這個粒度（pfmea_item 用 process_code 分組，沒有「這筆失效模式對應哪一個
           特性」的欄位），系統配不出哪一筆失效模式該套到哪一列特性——同一製程常常
           同時存在好幾種失效模式（尺寸偏擺、外觀刮傷、咬合精度…），各自的管制方法
           與風險等級本來就不一樣，硬套「這個製程最嚴重的那一筆」到每一列，必定會
           出現像這次「外觀套到尺寸管制」的語意矛盾。
           依 AS9100／APQP 慣例，Control Plan 的管制方法與特殊特性分類本來就是**逐一
           特性**由人員依實際風險判斷填入（且不等於 SIP 的量測技術——SIP 只回答
           「怎麼量」，管制方法回答「怎麼防、怎麼守」，兩者是不同概念，不應該混用
           同一個來源）；系統能做的是把 PFMEA 整理過的資訊**當參考**攤出來，不能替
           使用者下判斷。故**兩欄一律留白由人逐列填**，PFMEA 的全部失效模式整理成
           一段參考文字放在製程層級的提示列（pfmea_note，畫面在「作業說明」下方），
           供建 CP 的人自己對照該製程的所有特性，判斷哪一條適用於哪一列。
           管制方法 2026-10-06 再追加：管理員可在「設定→管制方法快選庫」維護
           「特性名稱關鍵字→預設管制方法」，下面 items 迴圈會依**每一列自己的
           特性名稱**（不是製程）比對套用，見 cp_ctrl_method_match()。 */
        $specialClassId = null;
        $specialText = '';

        /* 製程特性（CP 上指「對應這個產品特性的可控製程變數」，例如砂輪修整量、轉速）
           刻意不自動帶 PFMEA 的 function_desc：那是製程層級的「製程功能／要求」，
           逐列套上去會讓「外觀」「包裝」那幾列也印出「幾何精度」，稽核一看就是矛盾
           （2026-10-02 由列印版截圖目視發現，字串檢查抓不到這種錯）。
           系統裡沒有逐特性的製程變數資料，所以這一欄一律留白由人填，
           PFMEA 的製程功能改帶到製程列的「作業說明」當參考，一個製程只出現一次。 */
        $opDesc = $pfp && $pfp['func']
            ? ('PFMEA 製程功能／要求：' . implode('；', array_slice($pfp['func'], 0, 3)))
            : '';

        /* PFMEA 參考提示（製程層級，一個製程只出現一次，供人逐列判斷用，不寫入任何欄位）：
           這個製程底下 PFMEA 列出的每一種失效模式各自的要求／分類／管制方法都列出來，
           而不是只挑「最嚴重的那一筆」代表全部——挑最嚴重那筆正是目前問題的根因。 */
        $pfmeaNote = '';
        if ($pfp) {
            $lines = [];
            // req/class 陣列已去重但彼此長度不一定對齊（來源是逐失效模式彙整），
            // 這裡只需要給人看「這製程有哪些要求、哪些分類被登記過」，不必逐筆一一配對。
            if ($pfp['req'])   $lines[] = '要求／偏差項目：' . implode('、', array_slice($pfp['req'], 0, 6));
            if ($pfp['class']) $lines[] = 'PFMEA 自填分類：' . implode('、', array_slice($pfp['class'], 0, 6));
            if ($pfp['prev'])  $lines[] = '預防管制：' . implode('；', array_slice($pfp['prev'], 0, 4));
            if ($pfp['det'])   $lines[] = '偵測管制：' . implode('；', array_slice($pfp['det'], 0, 4));
            if ($lines) {
                $pfmeaNote = 'PFMEA 參考（這個製程全部失效模式整理，哪一條適用哪一列特性請自行判斷）：'
                    . "\n" . implode("\n", $lines);
            }
        }

        $items = [];
        foreach ($sip['items'] as $k => $it) {
            // 規格：計量值用上下限，屬性值（外觀/精度等級）寫在 q_char（見檔頭資料事實⑶）
            $up = trim((string)($it['up_limit'] ?? ''));
            $lo = trim((string)($it['lo_limit'] ?? ''));
            $specText = trim((string)($it['q_char'] ?? ''));
            if ($up !== '' || $lo !== '') {
                $num = function ($v) {
                    if ($v === '' || $v === null) return '';
                    return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
                };
                $rangeTxt = $num($lo) . ' ~ ' . $num($up);
                $specText = $specText !== '' ? ($rangeTxt . '（' . $specText . '）') : $rangeTxt;
            }

            /* 頻率：SIP 自己填的頻率優先（那是已核准文件上逐特性訂定的依據，不可被
               一個階段層級的通用值蓋掉——否則同一道製程底下尺寸/精度等級/外觀等
               不同特性原本各自不同的頻率，會被全部抹平成同一句「100%全尺寸(首件)」），
               SIP 沒填這一項才退回階段預設（cp_stage.default_freq，用於 SIP 完全沒有
               指定頻率的項目）。2026-10-06 使用者回報糾正：先前版本寫反了優先序。
               **FQC 合成列例外**（使用者 2026-10-06 交辦「FQC內容是齒研製程SIP內容，
               只是檢驗頻率改寫同抽樣規範」）：FQC 代表批次放行前的最終確認，業界慣例
               是照抽樣規則表抽驗，不是沿用製程中檢驗(IPQC)那種「每顆/100%全尺寸」的
               頻率寫法；CP 是範本不是對應單一批次的紀錄，沒有實際批量可以算出具體
               抽驗數，所以印的是規則本身的參照，不是一個寫死的數字（跟線上檢驗
               qc_suggest_sample_qty() 查的是同一張 qc_sampling_rule 表）。 */
            $freq = (($p['src'] ?? '') === 'fqc_insert' || ($p['src'] ?? '') === 'fqc_insert_ship')
                ? '依抽樣規則表抽驗（同線上檢驗，依實際批量查表）'
                : (trim((string)($it['freq'] ?? '')) !== '' ? trim((string)($it['freq'] ?? '')) : $stageFreq);
            $ctrlPoint = trim((string)($it['ctrl_point'] ?? ''));
            $items[] = [
                'seq'                => $k + 1,
                'char_no'            => (string)($p['seq'] . '-' . ($k + 1)),
                'char_product'       => $ctrlPoint,
                'char_process'       => '',   // 見上方說明：不逐列套製程層級的 PFMEA 功能描述
                'special_class_id'   => $specialClassId,
                'special_class_text' => $specialText,
                'spec_text'          => $specText,
                'up_limit'           => $up,
                'lo_limit'           => $lo,
                'eval_method'        => trim((string)($it['method'] ?? '')),
                'tool_no'            => trim((string)($it['tool_no'] ?? '')),
                'tool_id'            => (int)($it['tool_id'] ?? 0) ?: null,
                'sample_size'        => '',
                'sample_freq'        => $freq,
                'control_method'     => cp_ctrl_method_match($db, $ctrlPoint),
                'reaction_plan'      => $defReact,
                'src'                => $sip['src'] ?: 'manual',
                'src_ref'            => $sip['src_ref'] ? ($sip['src_ref'] . '#' . $it['item_id']) : '',
                'note'               => trim((string)($it['note'] ?? '')),
            ];
        }

        $p['items'] = $items;
        if (empty($p['op_desc'])) $p['op_desc'] = $opDesc;
        $p['pfmea_note'] = $pfmeaNote;
        $p['sip_src'] = $sip['src'];
        $p['has_pfmea'] = $pfp ? 1 : 0;
        if (!$items) {
            $p['hint'] = (($p['src'] ?? '') === 'fqc_insert_ship')
                ? '這個料號還沒有「出貨檢驗」紀錄可以帶出內容——請先到線上檢驗按「出貨檢驗→自動生成」建立過一次，或在這裡人工填。'
                : '這道製程查不到 SIP 檢驗項目，也沒有該製程的預設值範本，特性列要人工填。';
        }
        $res['processes'][] = $p;
    }

    // --- PFMEA 有、BOM 沒有的製程（只提示不自動加，使用者定調製程以 BOM 為主）---
    foreach ($pf['by_proc'] as $pn => $b) {
        if (isset($seenProc[$pn])) continue;
        $nm = '';
        try {
            $st = $db->prepare("SELECT ProcessName FROM process_no WHERE ProcessNo=?");
            $st->execute([$pn]);
            $nm = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        $res['pfmea_only'][] = ['process_no' => $pn, 'process_name' => $nm];
    }
    if ($res['pfmea_only']) {
        $names = array_map(function ($r) { return $r['process_name'] ?: ('製程' . $r['process_no']); }, $res['pfmea_only']);
        $res['info'][] = 'PFMEA 有評估、但這張製令沒有走的製程：' . implode('、', $names)
            . '。製程以 BOM 為主，故不自動加入；若確實該納入請手動新增一列。';
    }

    // 檢驗類別（IQC/IPQC/FQC）：只對「來自BOM」的製程鏈算，鏈條完整才算得出「最早」「前一道」
    if ($res['processes']) {
        $res['processes'] = cp_compute_insp_stages($db, $res['processes']);
        $res['processes'] = cp_insp_gap_annotate($db, $res['processes'], $partDId ?: null);
    }

    // 同料號是否已有製程鏈完全相同的既有CP可以直接複製內容（見 cp_find_similar_cp 說明）
    if ($partDId && $res['processes']) {
        $realNos = [];
        foreach ($res['processes'] as $p) {
            if (in_array($p['src'] ?? '', ['fqc_insert', 'fqc_insert_ship'], true)) continue;
            if ($p['process_no'] !== null) $realNos[] = (int)$p['process_no'];
        }
        $res['similar_cp'] = $realNos ? cp_find_similar_cp($db, $partDId, $realNos) : null;
    }

    return $res;
}

/* ===================================================================
 * 六之二、檢驗類別自動判定（IQC／IPQC／FQC）
 *
 * 使用者定調（2026-10-05／10-06，四輪）：四種檢驗類別在現場的真實對應——
 *   IQC  進料檢驗：客供料的檢驗項目。
 *   IPQC 製程中檢驗：SOP（製造製程說明書）中的檢驗項目。
 *   FQC  最終檢驗：網頁「成品檢驗」（線上檢驗模組）——**FQC 不是鏈上某一道製程本身
 *        被改判，而是在包裝前面插入一道新的合成列**（使用者原話「我是要你在包裝跟
 *        前一道製程中間增加一道叫FQC」）。觸發條件是**位置**不是製程類型：**只要
 *        鏈上有包裝，包裝前一道一律自動插入，無條件觸發，不必登記任何製程代號**
 *        （使用者原話二次更正「應該是認定包裝前自動加入FQC，不是認定哪個製程後要加
 *        FQC」——最初做法是讓管理員登記「齒研/插齒」這種FQC來源清單，後來發現這跟
 *        下面④AS稽核製程的「次站檢驗類別」欄位是同一件事、不該有兩個設定入口，
 *        中途一度改成從那個設定推導，最後確認連那個設定都不必要，直接看位置即可）。
 *   OQC  出貨檢驗：網頁的「包裝自主檢驗表」（packing_schedule.php，各自有單號）——
 *        **本模組刻意不做 OQC 分類**（使用者拍板取消），包裝檢驗已經是獨立模組、
 *        自己有單號可查，不必在 CP 的 insp_stage 裡重複表達一次。
 *
 * 四條規則，優先序由下往上（下面的會覆蓋上面的）：
 *   ①預設＝IPQC（製程中間，介於 IQC 與 FQC 之間的一律是 IPQC）。
 *   ②**客供料自動判定**＝IQC：一條製程鏈只認**最早出現**的那一個客供料製程（沿用
 *     part_cost_lib.php 的 ppc_kg_set()＝ProcessNo=138 或製程名稱含「客供料」，
 *     與成本推算用的是同一份判準，不另設一份會走鐘的「IQC代號」清單）。
 *   ③**包裝自動判定**：鏈中最早出現的包裝製程（沿用 packing_process_lib.php 的
 *     pk_packing_process_nos()＝packing_schedule.php「包裝製程設定」的權威登記，
 *     不在 CP 另存一份代號清單）。包裝本身 insp_stage=null（非檢驗點，走獨立的
 *     包裝自主檢驗）。
 *   ④AS 稽核製程（訂單追蹤的「稽核製程」標籤，kind='process'）可逐個標籤**額外**設定
 *     「下一站固定是 IQC」（FQC 選項已移除，見下方 cp_as_tag_insp_save 說明），命中時
 *     覆蓋的是**該製程的下一列**（不是它自己）；下一列若剛好是包裝列或⑤插入的合成
 *     FQC 列，不覆蓋。
 *
 * ⑤**FQC 合成列插入**（cp_insert_fqc_row()，唯一實作，在 cp_autofill_preview()
 * 跑 items 迴圈「之前」就先插好）：**只要鏈上有包裝**（pk_packing_process_nos()
 * 判定）**且包裝不是第一道**，就在它前面無條件插入一道合成列，insp_stage 直接
 * 定案='FQC'，process_name 加註「（最終檢驗）」。內容依**插入前的總製程數**
 * （含客供料／包裝在內）對照管理員設定的門檻 cp_fqc_threshold()（預設4）二選一：
 *   - 總製程數 ≤ 門檻：process_no 借用「包裝前一道」自己的 process_no（讓下面
 *     items 迴圈像對待一般列一樣自然查到它的 SIP，不必另外寫抓取邏輯），
 *     src='fqc_insert'。
 *   - 總製程數 ＞ 門檻：process_no=null，改在 items 迴圈呼叫 cp_fqc_ship_items()
 *     查該料號的「出貨檢驗」內容（qc_v2_ship_items，跨製程彙整、不綁單一
 *     process_no），src='fqc_insert_ship'。
 * 插入後是**一般的製程列**，可以手動調順序／改內容／刪除，不受保護。
 *
 * 文件缺口提示（使用者原話「無SOP時要求補SOP」「FQC一定要有此製程的SIP」）：
 * 分類本身不因缺文件而改判，但 cp_insp_gap_annotate() 會逐列即時查——IPQC 列查無
 * 已核准 SOP、FQC 列查無**真正的**已核准 SIP（排除 ss_item_tpl 範本退路，那不算
 * 正式文件）——命中的補上 insp_gap='sop'/'sip'，供畫面顯示警示並引導去 SOP/SIP
 * 模組補建。這一欄每次顯示都即時查、不落庫（與 insp_src 同一種「顯示用」欄位），
 * 所以草稿、預覽、已存檔的 CP 都看得到最新缺口狀態。
 *
 * 只在「自動帶入」（cp_autofill_preview，製程來自 BOM）算一次，存進 cp_process.insp_stage
 * 之後就是人工可覆蓋的欄位（比照 special_class_id 同一套思路）；手動新增的製程列
 * 預設 IPQC，不會被自動規則碰到。畫面另有「依設定重新判定」按鈕可在手動調整過
 * 製程順序／增刪列之後，重新套用規則（仍只套用在目前畫面的列，使用者確認後才存檔；
 * 這顆按鈕只送 process_no 沒有 items，所以**不會**重新插入FQC合成列，只重算分類）。
 * =================================================================== */

/** insp_stage 合法值正規化：非法值一律存 NULL，不讓壞資料寫進 ENUM 欄位爆例外。 */
function cp_insp_stage_norm($v): ?string
{
    $v = strtoupper(trim((string)$v));
    return in_array($v, ['IQC', 'IPQC', 'FQC'], true) ? $v : null;
}

/** 設定：AS稽核製程標籤 → 下一站固定檢驗類別（tag_id => 'IQC'）。只收合法值與真實存在的tag_id。
 *  FQC 2026-10-06（三次）起改用「包裝前一道」位置判定自動插入（見 cp_insert_fqc_row），
 *  不再透過這裡設定；舊資料若存了 'FQC' 一併讀出但下游（rule④）已不處理它，等同失效。 */
function cp_as_tag_insp(PDO $db): array
{
    $v = cp_param($db, 'as_tag_insp', []);
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $tid => $val) {
        $val = cp_insp_stage_norm($val);
        if ($val === 'IPQC' || $val === null) continue;   // 這個設定只收 IQC/FQC，IPQC是預設不必存
        $out[(int)$tid] = $val;
    }
    return $out;
}
function cp_as_tag_insp_save(PDO $db, array $map): void
{
    $valid = array_map(function ($d) { return (int)$d['tag_id']; }, cp_as_tag_defs($db, false));
    $out = [];
    foreach ($map as $tid => $val) {
        $tid = (int)$tid;
        if (!in_array($tid, $valid, true)) continue;
        $val = cp_insp_stage_norm($val);
        if ($val === 'IQC') $out[$tid] = $val;   // FQC 不再收（見上方函式說明）
    }
    cp_param_save($db, 'as_tag_insp', $out);
}

/**
 * 展開某個 AS 稽核製程標籤定義會涵蓋的 process_no 清單。
 * sub_no_json 有指定小類就用它；沒指定＝整個製程大類（process_no.process_type_id）。
 * 走 order_as_tag_lib.php 既有的 ot_astag_sub_nos()，不重寫一份 JSON 解析。
 */
function cp_as_tag_proc_nos(PDO $db, array $def): array
{
    require_once __DIR__ . '/order_as_tag_lib.php';
    $subs = ot_astag_sub_nos($def['sub_no_json'] ?? '');
    if ($subs) return array_map('intval', $subs);
    $pt = (int)($def['process_type_id'] ?? 0);
    if ($pt <= 0) return [];
    try {
        $st = $db->prepare("SELECT ProcessNo FROM process_no WHERE process_type_id=?");
        $st->execute([$pt]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) { return []; }
}

/**
 * 核心：依上面三＋一條規則，判定整條製程鏈每一列的檢驗類別。
 * 純函式、不寫 DB。$procs 必須已依製程順序（bom_sn／seq）排好，每列至少要有 process_no
 * （手動列若沒填製程代號，一律落在預設 IPQC，規則判不到它）。
 * 回傳同一份陣列，每列補上：
 *   insp_stage ＝ 'IQC'/'IPQC'/'FQC'/null（null 只用在「包裝列本身」，代表不是檢驗點）
 *   insp_src   ＝ 判定依據（顯示用，不落庫）：iqc_code／fqc_pack／pack／as_tag／default
 */
function cp_compute_insp_stages(PDO $db, array $procs): array
{
    if (!$procs) return $procs;

    $kgSet     = array_flip(ppc_kg_set($db));              // 客供料製程（IQC），與 part_cost_lib 同一份判準
    $packCodes = array_flip(pk_packing_process_nos($db));  // 包裝製程，與 packing_schedule.php 同一份權威登記
    $asInsp    = cp_as_tag_insp($db);

    /* 展開 AS 稽核製程 → process_no 對應，**這裡只收 IQC**（一個代號若同時屬於多個設了值
       的標籤，取第一個找到的）。FQC 已改由 cp_insert_fqc_row() 用「包裝前一道」的位置
       判定自動插入合成列，不再靠任何登記（2026-10-06 三次：使用者定調「認定包裝前
       自動加入FQC，不是認定哪個製程後要加FQC」），這裡的「次站檢驗類別」下拉也只
       保留 IQC 選項。 */
    $asNoMap = [];
    if ($asInsp) {
        foreach (cp_as_tag_defs($db, true) as $def) {
            $want = $asInsp[(int)$def['tag_id']] ?? null;
            if ($want !== 'IQC') continue;
            foreach (cp_as_tag_proc_nos($db, $def) as $no) {
                if (!isset($asNoMap[$no])) $asNoMap[$no] = $want;
            }
        }
    }

    // ⑤插入的合成FQC列已經定案，一般規則一律跳過（不可被①~④改判或誤判成別的分類）
    $isFqcInsert = function ($p) { return in_array($p['src'] ?? '', ['fqc_insert', 'fqc_insert_ship'], true); };

    // ①預設：全部 IPQC（合成列除外，它進來時就已經是 insp_stage=FQC/insp_src=fqc_insert）
    foreach ($procs as &$p) {
        if ($isFqcInsert($p)) continue;
        $p['insp_stage'] = 'IPQC'; $p['insp_src'] = 'default';
    }
    unset($p);

    $procNoOf = function ($p) { return $p['process_no'] !== null && $p['process_no'] !== '' ? (int)$p['process_no'] : null; };

    // ③包裝：鏈中最早出現的包裝製程本身不算檢驗點（FQC 現在是⑤獨立插入的列，不再相走這裡的前一道）
    $packIdx = null;
    if ($packCodes) {
        foreach ($procs as $i => $p) {
            if ($isFqcInsert($p)) continue;
            $pn = $procNoOf($p);
            if ($pn !== null && isset($packCodes[$pn])) { $packIdx = $i; break; }
        }
    }
    if ($packIdx !== null) {
        $procs[$packIdx]['insp_stage'] = null;
        $procs[$packIdx]['insp_src']   = 'pack';
    }

    // ②客供料：鏈中最早出現的客供料製程本身＝IQC；若那一列已被③佔用（短鏈撞在一起），包裝優先、放棄這條
    if ($kgSet) {
        foreach ($procs as $i => $p) {
            if ($isFqcInsert($p)) continue;
            $pn = $procNoOf($p);
            if ($pn === null || !isset($kgSet[$pn])) continue;
            if (($procs[$i]['insp_src'] ?? '') === 'pack') break;   // 只試最早那一個，撞到就不找第二個
            $procs[$i]['insp_stage'] = 'IQC';
            $procs[$i]['insp_src']   = 'kg';
            break;
        }
    }

    // ④AS稽核製程：覆蓋「下一列」，優先序最高，放最後套用；下一列若是包裝列或合成FQC列不覆蓋
    if ($asNoMap) {
        $n = count($procs);
        foreach ($procs as $i => $p) {
            if ($isFqcInsert($p)) continue;
            $pn = $procNoOf($p);
            if ($pn === null || !isset($asNoMap[$pn])) continue;
            $j = $i + 1;
            if ($j >= $n) continue;                 // 鏈上最後一道，沒有下一站可覆蓋
            if ($j === $packIdx) continue;           // 下一列是包裝本身，不覆蓋
            if ($isFqcInsert($procs[$j])) continue;  // 下一列是合成FQC列，已經定案不覆蓋
            $procs[$j]['insp_stage'] = $asNoMap[$pn];
            $procs[$j]['insp_src']   = 'as_tag';
        }
    }

    return $procs;
}

/** 設定：FQC 內容切換門檻（總製程數，管理員可調，預設 4）。見 cp_insert_fqc_row() 說明。 */
function cp_fqc_threshold(PDO $db): int
{
    $v = (int)cp_param($db, 'fqc_threshold', 4);
    return $v > 0 ? $v : 4;
}
function cp_fqc_threshold_save(PDO $db, int $v): void
{
    cp_param_save($db, 'fqc_threshold', $v > 0 ? $v : 4);
}

/**
 * 該料號的「出貨檢驗」內容，轉成跟 cp_sip_items() 相同的回傳形狀（items/src/src_ref），
 * 讓呼叫端不必分兩套邏輯處理。查無資料時 items 為空陣列，src 仍標 'ship' 讓畫面知道
 * 「這是應該去查出貨檢驗、但目前還沒有資料」，不會被誤判成完全沒處理過這一列。
 */
function cp_fqc_ship_items(PDO $db, ?int $partDId): array
{
    $rows = $partDId ? qc_v2_ship_items($db, $partDId) : [];
    return ['items' => array_map('cp_qcv2_row_to_sip_item', $rows), 'src' => 'ship',
            'src_ref' => $partDId ? ('ship_part' . $partDId) : ''];
}

/**
 * ⑤FQC 合成列插入（見六之二節說明，唯一實作）。純函式、不寫 DB。
 * 在 cp_bom_processes() 之後、items 迴圈之前呼叫。
 *
 * 2026-10-06（三次）使用者定調，整個改成**位置判定**，不再靠任何「哪些製程代號算
 * FQC來源」的登記（不管是獨立清單還是借用 AS稽核製程的「次站檢驗類別」）——
 * 原話「應該是認定包裝前自動加入FQC，不是認定哪個製程後要加FQC」：**只要鏈上有
 * 包裝，packaging 前一道就一定插入FQC**，無條件觸發，不必登記任何東西。
 *
 * 內容來源依**插入前的總製程數**（含客供料/包裝在內，管理員可調門檻
 * cp_fqc_threshold()，預設 4）二選一：
 *   - 總製程數 ≤ 門檻：借用「包裝前一道」那個製程自己的 SIP（process_no 直接沿用，
 *     讓下面 items 迴圈像對待一般列一樣自然查得到，不必另外寫抓取邏輯）。
 *   - 總製程數 ＞ 門檻：改用「出貨檢驗」(inspection_entry_v2.php insp_kind=SHIP)
 *     的內容（qc_v2_ship_items，料號專屬、不綁 process_no）——製程數少時代表
 *     工序單純，最後一道加工站的 SIP 本身就足以代表最終檢驗；製程數一多，最後
 *     一道站的 SIP 不見得涵蓋得了整個成品該確認的全部特性，改用本來就是「跨製程
 *     彙整」性質的出貨檢驗內容較合理。
 */
function cp_insert_fqc_row(PDO $db, array $procs, ?int $partDId): array
{
    if (!$procs) return $procs;

    $packSet = array_flip(pk_packing_process_nos($db));
    $procNoOf = function ($p) { return $p['process_no'] !== null && $p['process_no'] !== '' ? (int)$p['process_no'] : null; };

    // 找鏈中最早出現的包裝列位置
    $packIdx = null;
    foreach ($procs as $i => $p) {
        $pn = $procNoOf($p);
        if ($pn !== null && isset($packSet[$pn])) { $packIdx = $i; break; }
    }
    if ($packIdx === null || $packIdx === 0) return $procs;   // 沒有包裝、或包裝是第一道（沒有前一道可借），不插入

    $totalProc = count($procs);   // 插入前的總製程數，門檻判定用這個
    $threshold = cp_fqc_threshold($db);

    if ($totalProc <= $threshold) {
        $src = $procs[$packIdx - 1];
        $row = [
            'seq' => 0, 'bom_ing_fid' => null, 'bom_sn' => null,
            'process_no'   => $src['process_no'],
            'process_name' => trim((string)($src['process_name'] ?? '')) . '（最終檢驗）',
            'machine' => '', 'maker_id_no' => '', 'maker_name' => '', 'is_outsource' => 0,
            'src' => 'fqc_insert', 'insp_stage' => 'FQC', 'insp_src' => 'fqc_insert',
        ];
    } else {
        $row = [
            'seq' => 0, 'bom_ing_fid' => null, 'bom_sn' => null,
            'process_no' => null, 'process_name' => '出貨檢驗（最終檢驗）',
            'machine' => '', 'maker_id_no' => '', 'maker_name' => '', 'is_outsource' => 0,
            'src' => 'fqc_insert_ship', 'insp_stage' => 'FQC', 'insp_src' => 'fqc_insert',
        ];
    }

    array_splice($procs, $packIdx, 0, [$row]);
    foreach ($procs as $i => &$p) { $p['seq'] = $i + 1; }
    unset($p);

    return $procs;
}

/**
 * 同料號是否已有一份「製程鏈完全相同」的既有管制計畫可以直接複製（使用者 2026-10-06
 * 交辦）：自動帶入永遠重新從 SIP/PFMEA 帶，管制方法／特殊分類一律留白或走快選庫；
 * 但如果這個料號、這個製程鏈之前已經建過 CP，那份 CP 上的管制方法／特殊分類是人工
 * 確認過的內容，比重新留白再填一次更有效率——找得到就在預覽畫面提供「複製」選項，
 * 找不到就跟現在一樣走全新自動帶入，兩條路並存不互相影響。
 * 比對只看「真正的製程鏈」（排除 cp_insert_fqc_row 插入的合成列），那是每次自動帶入
 * 都會重算的衍生資料，不是製令本身真正的製程順序，拿來比對只會永遠比不中。
 * 多筆候選時取**最近更新**的那一份（最新的最可能反映目前的正確做法）。
 */
function cp_find_similar_cp(PDO $db, int $partDId, array $realProcNos): ?array
{
    if (!$partDId || !$realProcNos) return null;
    $want = array_values(array_map('intval', $realProcNos));
    try {
        $st = $db->prepare(
            "SELECT cp_id, cp_no, status, stage_id, COALESCE(modified_at, created_at) AS updated_at FROM cp_doc
              WHERE is_deleted=0 AND scope='part' AND part_d_id=?
              ORDER BY COALESCE(modified_at, created_at) DESC"
        );
        $st->execute([$partDId]);
        $cands = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($cands as $c) {
            $st2 = $db->prepare(
                "SELECT process_no FROM cp_process WHERE cp_id=? AND src NOT IN ('fqc_insert','fqc_insert_ship') ORDER BY seq"
            );
            $st2->execute([(int)$c['cp_id']]);
            $chain = array_values(array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN) ?: []));
            if ($chain === $want) {
                $c['stage_name'] = ($s = cp_stage_one($db, (int)$c['stage_id'])) ? $s['stage_name'] : '';
                $c['status_label'] = cp_status_label((string)$c['status']);
                return $c;
            }
        }
    } catch (Throwable $e) {}
    return null;
}

/**
 * 複製既有 CP 的內容（含人工確認過的管制方法／特殊分類），回傳格式比照
 * cp_autofill_preview() 的 processes 形狀，前端可以直接拿去替換預覽內容、
 * 走同一條「用這些內容建立」存檔流程（不直接複製整張 cp_doc，使用者在預覽階段
 * 還可以再調整，跟一般自動帶入預覽的體驗一致）。
 */
function cp_copy_from_cp(PDO $db, int $srcCpId): array
{
    $doc = cp_get($db, $srcCpId);
    if (!$doc) return [];
    $out = [];
    foreach (($doc['processes'] ?? []) as $p) {
        $items = [];
        foreach (($p['items'] ?? []) as $it) {
            $items[] = [
                'char_no' => $it['char_no'], 'char_product' => $it['char_product'], 'char_process' => $it['char_process'],
                'special_class_id' => $it['special_class_id'], 'special_class_text' => $it['special_class_text'],
                'spec_text' => $it['spec_text'], 'up_limit' => $it['up_limit'], 'lo_limit' => $it['lo_limit'],
                'eval_method' => $it['eval_method'], 'tool_id' => $it['tool_id'], 'tool_no' => $it['tool_no'],
                'sample_size' => $it['sample_size'], 'sample_freq' => $it['sample_freq'],
                'control_method' => $it['control_method'], 'reaction_plan' => $it['reaction_plan'],
                'src' => 'copy_cp', 'src_ref' => 'cp' . $srcCpId, 'note' => $it['note'],
            ];
        }
        $out[] = [
            'process_no' => $p['process_no'], 'process_name' => $p['process_name'], 'op_desc' => $p['op_desc'],
            'machine' => $p['machine'], 'jig_tool' => $p['jig_tool'], 'maker_id_no' => $p['maker_id_no'], 'maker_name' => $p['maker_name'],
            'is_outsource' => $p['is_outsource'], 'insp_stage' => $p['insp_stage'], 'insp_src' => 'copy_cp', 'insp_gap' => null,
            'bom_sn' => $p['bom_sn'], 'src' => ($p['src'] ?: 'copy_cp'), 'note' => $p['note'], 'hint' => '', 'pfmea_note' => '',
            'items' => $items,
        ];
    }
    // 複製進來的分類/文件缺口是「當時」的狀態，查無已核准SOP/SIP的提示要用現在的資料重查一次，
    // 否則會出現「複製過來的列永遠不會標缺口」的假象。
    $partDId = (int)($doc['part_d_id'] ?? 0);
    return cp_insp_gap_annotate($db, $out, $partDId ?: null);
}

/**
 * 這個料號／製程有沒有「已核准的 SOP」（kind='process'，製造製程說明書）。
 * 優先序比照 cp_sip_items 的 doc 查找：綁這個料號的 → 該製程的通用版。
 * 只回傳存不存在，IPQC 文件缺口提示只需要這個，不必展開內容。
 */
function cp_has_sop(PDO $db, ?int $partDId, ?int $processNo): bool
{
    return cp_ss_doc_exists($db, 'process', $partDId, $processNo);
}

/**
 * 這個料號／製程有沒有「真正的已核准 SIP」（kind='sip'，標準檢驗指導書）。
 * 刻意不接受 cp_sip_items() 的 ss_item_tpl 範本退路——範本只是「查不到正式文件時
 * 的檢驗項目預設值」，不是一份已審核過的 SIP，FQC 的文件缺口提示要看的是「有沒有
 * 真的建立並核准這份文件」。
 */
function cp_has_real_sip(PDO $db, ?int $partDId, ?int $processNo): bool
{
    return cp_ss_doc_exists($db, 'sip', $partDId, $processNo);
}

/**
 * cp_has_sop／cp_has_real_sip 共用：ss_doc 是否存在已核准版本
 * （part 優先 → 該製程的通用 → 同製程大類的通用，與 cp_sip_items() 同一套優先序，
 * 否則會出現「CP 已經顯示包裝檢驗項目了，缺口提示卻還說缺 SIP」的矛盾）。
 */
function cp_ss_doc_exists(PDO $db, string $kind, ?int $partDId, ?int $processNo): bool
{
    if ($processNo === null) return false;
    $try = function ($scope) use ($db, $kind, $partDId, $processNo) {
        $sql = "SELECT 1 FROM ss_doc d JOIN ss_ver v ON v.ver_id = d.cur_ver_id AND v.status='approved'
                 WHERE d.is_deleted=0 AND d.kind=? AND d.scope=? AND d.process_no=?";
        $par = [$kind, $scope, $processNo];
        if ($scope === 'part') { $sql .= " AND d.part_d_id=?"; $par[] = (int)$partDId; }
        $sql .= " LIMIT 1";
        try { $st = $db->prepare($sql); $st->execute($par); return (bool)$st->fetchColumn(); }
        catch (Throwable $e) { return false; }
    };
    if ($partDId && $try('part')) return true;
    if ($try('general')) return true;
    return cp_sip_general_doc_by_type($db, $kind, $processNo) !== null;
}

/**
 * 逐列補上文件缺口提示：IPQC 查無已核准 SOP → insp_gap='sop'；FQC 查無真正的已核准
 * SIP → insp_gap='sip'。不改判分類（包裝前一道照樣是 FQC），只是標出「這份分類背後
 * 該有的文件還沒建」。每次顯示即時查、不落庫（與 insp_src 同一種顯示用欄位）。
 *
 * 包裝本身（insp_src='pack'）刻意被 cp_compute_insp_stages() 判成 insp_stage=null
 * （它不是一個檢驗點），但它的特性內容仍是 cp_sip_items() 從「包裝通用SIP」查來的
 * （見同檔 cp_sip_general_doc_by_type() 說明）——這份SIP沒建好一樣要提示缺口，不能
 * 因為沒有 insp_stage 就被上面那段 IPQC/FQC 判斷略過，否則「CP已經顯示包裝檢驗項目
 * 了，缺口提示卻完全不提」會變成一個使用者找不到原因的死角（2026-10-06 使用者問
 * 「一般應該顯示包裝自主檢驗項目還是包裝SIP內容」，答案是後者——本來就是，這裡補
 * 上對應的缺口偵測，不是另外接 packing_schedule.php 那套綁在具體製令上的即時檢驗
 * 記錄，那是不同層級的東西，CP這種料號範本頁面查不到也不該查）。
 */
function cp_insp_gap_annotate(PDO $db, array $procs, ?int $partDId): array
{
    foreach ($procs as &$p) {
        $p['insp_gap'] = null;
        $pn = isset($p['process_no']) && $p['process_no'] !== null && $p['process_no'] !== ''
            ? (int)$p['process_no'] : null;
        if ($pn === null) continue;
        $stage = $p['insp_stage'] ?? null;
        if ($stage === 'IPQC' && !cp_has_sop($db, $partDId, $pn)) {
            $p['insp_gap'] = 'sop';
        } elseif ($stage === 'FQC' && !cp_has_real_sip($db, $partDId, $pn)) {
            $p['insp_gap'] = 'sip';
        } elseif (!$stage && ($p['insp_src'] ?? '') === 'pack' && !cp_has_real_sip($db, $partDId, $pn)) {
            $p['insp_gap'] = 'sip';
        }
    }
    unset($p);
    return $procs;
}

/** 一次查出多個製程代號的名稱（設定頁顯示用，避免逐筆查） */
function cp_process_name_map(PDO $db, array $nos): array
{
    $nos = array_values(array_unique(array_filter(array_map('intval', $nos), function ($n) { return $n > 0; })));
    if (!$nos) return [];
    $in = implode(',', array_fill(0, count($nos), '?'));
    try {
        $st = $db->prepare("SELECT ProcessNo, ProcessName FROM process_no WHERE ProcessNo IN ($in)");
        $st->execute($nos);
        $out = [];
        foreach ($st as $r) $out[(int)$r['ProcessNo']] = (string)($r['ProcessName'] ?? '');
        return $out;
    } catch (Throwable $e) { return []; }
}

/** 目前系統認定的客供料製程（IQC判定依據），純顯示用，不可在這裡編輯——要改請改 process_no 主檔的製程名稱 */
function cp_kg_codes_detail(PDO $db): array { return cp_codes_detail($db, ppc_kg_set($db)); }
/** 目前被 packing_schedule.php 認定為包裝的製程（③判定依據），純顯示用，不可在這裡編輯——要改請到「包裝製程設定」 */
function cp_pack_codes_detail(PDO $db): array { return cp_codes_detail($db, pk_packing_process_nos($db)); }
function cp_codes_detail(PDO $db, array $nos): array
{
    $map = cp_process_name_map($db, $nos);
    $out = [];
    foreach ($nos as $n) $out[] = ['no' => $n, 'name' => $map[$n] ?? ('#' . $n)];
    return $out;
}

/** insp_stage 的顯示文字（設定頁／編輯畫面共用，避免前端各自判斷字串） */
function cp_insp_stage_label(?string $s): string
{
    switch ($s) {
        case 'IQC':  return 'IQC（進料檢驗）';
        case 'IPQC': return 'IPQC（製程檢驗）';
        case 'FQC':  return 'FQC（最終檢驗）';
    }
    return '';
}

/* ===================================================================
 * 七、CRUD
 * =================================================================== */

function cp_list(PDO $db, array $opt = []): array
{
    $w = ['d.is_deleted=0'];
    $par = [];
    if (!empty($opt['stage_id'])) { $w[] = 'd.stage_id=?'; $par[] = (int)$opt['stage_id']; }
    if (!empty($opt['status']))   { $w[] = 'd.status=?';   $par[] = (string)$opt['status']; }
    if (!empty($opt['part_d_id'])){ $w[] = 'd.part_d_id=?';$par[] = (int)$opt['part_d_id']; }
    if (!empty($opt['kw'])) {
        $kw = '%' . str_replace(['%','_'], ['\%','\_'], trim((string)$opt['kw'])) . '%';
        $w[] = '(d.cp_no LIKE ? OR d.part_no_text LIKE ? OR d.product_name LIKE ? OR d.customer_name LIKE ? OR d.family_name LIKE ? OR d.src_order_oo LIKE ? OR d.src_bom LIKE ?)';
        for ($i = 0; $i < 7; $i++) $par[] = $kw;
    }
    $where = implode(' AND ', $w);

    $total = 0;
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM cp_doc d WHERE $where");
        $st->execute($par);
        $total = (int)$st->fetchColumn();
    } catch (Throwable $e) { return ['rows' => [], 'total' => 0]; }

    $per  = max(1, min(200, (int)($opt['per'] ?? 20)));
    $page = max(1, (int)($opt['page'] ?? 1));
    $off  = ($page - 1) * $per;

    $sql = "SELECT d.*, s.stage_name, s.stage_code,
                   (SELECT COUNT(*) FROM cp_process p WHERE p.cp_id=d.cp_id) proc_cnt,
                   (SELECT COUNT(*) FROM cp_item i
                      JOIN cp_process p2 ON p2.cp_proc_id=i.cp_proc_id
                     WHERE p2.cp_id=d.cp_id) item_cnt,
                   u.user_cname AS approver_name
              FROM cp_doc d
              LEFT JOIN cp_stage s ON s.stage_id=d.stage_id
              LEFT JOIN user u ON u.id=d.approved_by
             WHERE $where
             ORDER BY d.form_date DESC, d.cp_id DESC
             LIMIT $per OFFSET $off";
    try {
        $st = $db->prepare($sql);
        $st->execute($par);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $rows = []; }

    foreach ($rows as &$r) {
        $r['form_date_disp'] = eg_fmt_date($r['form_date']);
        $r['status_label'] = cp_status_label((string)$r['status']);
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per];
}

function cp_status_label(string $s): string
{
    switch ($s) {
        case 'draft':     return '草稿';
        case 'submitted': return '待核准';
        case 'approved':  return '已核准';
    }
    return $s;
}

function cp_get(PDO $db, int $cpId): ?array
{
    $st = $db->prepare(
        "SELECT d.*, s.stage_name, s.stage_code, s.default_freq AS stage_default_freq
           FROM cp_doc d LEFT JOIN cp_stage s ON s.stage_id=d.stage_id
          WHERE d.cp_id=? AND d.is_deleted=0"
    );
    $st->execute([$cpId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) return null;

    $doc['form_date_disp'] = eg_fmt_date($doc['form_date']);
    $doc['status_label']   = cp_status_label((string)$doc['status']);

    $st = $db->prepare("SELECT * FROM cp_process WHERE cp_id=? ORDER BY seq, cp_proc_id");
    $st->execute([$cpId]);
    $procs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($procs) {
        $ids = array_map(function ($p) { return (int)$p['cp_proc_id']; }, $procs);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT * FROM cp_item WHERE cp_proc_id IN ($in) ORDER BY cp_proc_id, seq, cp_item_id");
        $st->execute($ids);
        $byProc = [];
        foreach ($st as $it) $byProc[(int)$it['cp_proc_id']][] = $it;
        foreach ($procs as &$p) {
            $p['items'] = $byProc[(int)$p['cp_proc_id']] ?? [];
        }
        unset($p);

        // 文件缺口即時查（不落庫，見 cp_insp_gap_annotate 說明）——已存檔的 CP 一樣要看得到
        // 目前查無 SOP/SIP 的提示，不是只有自動帶入那一刻才算一次。
        $procs = cp_insp_gap_annotate($db, $procs, (int)($doc['part_d_id'] ?? 0) ?: null);
    }
    $doc['processes'] = $procs;

    // 產品族成員
    $doc['family_parts'] = [];
    if ($doc['scope'] === 'family') {
        $st = $db->prepare(
            "SELECT f.part_d_id, f.part_no_text, d.D_Setting_Id, d.Revision
               FROM cp_family_part f LEFT JOIN d_setting d ON d.d_id=f.part_d_id
              WHERE f.cp_id=? ORDER BY f.id"
        );
        $st->execute([$cpId]);
        $doc['family_parts'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $st = $db->prepare("SELECT * FROM cp_revision WHERE cp_id=? ORDER BY rev_id DESC");
    $st->execute([$cpId]);
    $doc['revisions'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* 來源訂單的稽核製程標籤「現在」是什麼，與這張 CP 建立時的快照比對。
       不自動改寫快照（見 cp_save 的說明），只在不一致時給提示——
       標籤被改成「不列 AS」卻還有一張 CP 掛著，是稽核會問的事，要讓人看見。 */
    $doc['as_tag_now'] = null;
    $doc['as_tag_changed'] = 0;
    if (!empty($doc['src_order_id'])) {
        $now = cp_order_as_tag($db, (int)$doc['src_order_id']);
        $doc['as_tag_now'] = $now;
        $snapId = (int)($doc['src_as_tag_id'] ?? 0);
        if ($now && $snapId > 0) {
            $doc['as_tag_changed'] = ((int)($now['tag_id'] ?? 0) !== $snapId
                                   || (string)($now['scope'] ?? '') !== (string)($doc['src_as_tag_scope'] ?? '')) ? 1 : 0;
        }
    }

    return $doc;
}

/**
 * 存檔（新增或修改）。
 * 一律整份覆寫製程與特性列（CP 是一張表，逐列 diff 的複雜度換不到好處）。
 * 已核准的一律擋下——要改請改版（cp_revise）。
 */
function cp_save(PDO $db, array $in, array $perm): array
{
    $cpId = (int)($in['cp_id'] ?? 0);
    $uid  = (int)$perm['uid'];
    if (!$perm['edit']) return ['ok' => false, 'msg' => '沒有建立或修改管制計畫的權限。'];

    $stageId = (int)($in['stage_id'] ?? 0);
    if ($stageId <= 0) return ['ok' => false, 'msg' => '請選擇階段（試作／試產／量產）。'];
    $stage = cp_stage_one($db, $stageId);
    if (!$stage) return ['ok' => false, 'msg' => '階段不存在。'];
    if ((int)$stage['is_active'] !== 1 && $cpId <= 0) {
        return ['ok' => false, 'msg' => '階段「' . $stage['stage_name'] . '」已停用，不能用它建立新的管制計畫。'];
    }

    $scope = ($in['scope'] ?? 'part') === 'family' ? 'family' : 'part';
    $partDId = (int)($in['part_d_id'] ?? 0);
    $familyName = trim((string)($in['family_name'] ?? ''));
    if ($scope === 'part' && $partDId <= 0) return ['ok' => false, 'msg' => '請選擇料號。'];
    if ($scope === 'family' && $familyName === '') return ['ok' => false, 'msg' => '請填寫產品族名稱。'];

    $formDate = trim((string)($in['form_date'] ?? ''));
    if ($formDate === '') return ['ok' => false, 'msg' => '請填寫表單日期。'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDate)) return ['ok' => false, 'msg' => '表單日期格式不正確。'];
    if (strtotime($formDate) > strtotime(date('Y-m-d'))) {
        return ['ok' => false, 'msg' => '表單日期不可以是未來日期。'];
    }

    // 已核准不給直接改
    if ($cpId > 0) {
        $st = $db->prepare("SELECT status FROM cp_doc WHERE cp_id=? AND is_deleted=0");
        $st->execute([$cpId]);
        $cur = (string)($st->fetchColumn() ?: '');
        if ($cur === '') return ['ok' => false, 'msg' => '這份管制計畫不存在或已刪除。'];
        if ($cur === 'approved' && !$perm['admin']) {
            return ['ok' => false, 'msg' => '已核准的管制計畫不可直接修改，請按「改版」建立新版次。'];
        }
    }

    $uname = '';
    try {
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$uid]);
        $uname = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    $fields = [
        'stage_id' => $stageId, 'scope' => $scope,
        'part_d_id' => $scope === 'part' ? $partDId : null,
        'part_no_text' => trim((string)($in['part_no_text'] ?? '')),
        'product_name' => trim((string)($in['product_name'] ?? '')),
        'family_name' => $scope === 'family' ? $familyName : null,
        'customer_id' => trim((string)($in['customer_id'] ?? '')) ?: null,
        'customer_name' => trim((string)($in['customer_name'] ?? '')),
        'part_rev' => trim((string)($in['part_rev'] ?? '')),
        'form_date' => $formDate,
        'src_order_id' => (int)($in['src_order_id'] ?? 0) ?: null,
        'src_order_oo' => trim((string)($in['src_order_oo'] ?? '')) ?: null,
        'src_bom' => trim((string)($in['src_bom'] ?? '')) ?: null,
        'src_bom_date' => trim((string)($in['src_bom_date'] ?? '')) ?: null,
        // 稽核製程標籤快照：一律由來源訂單即時重新解析，不採信前端送來的值
        // （鐵律8；前端那三個欄位只是顯示用，被改掉也不影響存進去的認定依據）
        'src_as_tag_id' => null, 'src_as_tag_scope' => null, 'src_as_tag_label' => null,
        'pfmea_doc_id' => (int)($in['pfmea_doc_id'] ?? 0) ?: null,
        'org_code' => trim((string)($in['org_code'] ?? '')),
        'key_contact' => trim((string)($in['key_contact'] ?? '')),
        'core_team' => trim((string)($in['core_team'] ?? '')),
        'customer_eng_appr' => trim((string)($in['customer_eng_appr'] ?? '')),
        'customer_qa_appr' => trim((string)($in['customer_qa_appr'] ?? '')),
        'other_appr' => trim((string)($in['other_appr'] ?? '')),
        'note' => (string)($in['note'] ?? ''),
    ];

    /* 有來源訂單時，標籤快照一律重新解析一次（不採信前端）。
       刻意只在「新建」或「快照還是空的」時候寫入：已經建好的 CP 事後若訂單標籤被改，
       不該回頭改寫它的認定依據——那張 CP 當初就是依當時的認定建的，
       現況不一致要用提示讓人看見，而不是靜默覆蓋（同型態識別文件管制表的快照思路）。 */
    if (!empty($fields['src_order_id'])) {
        $needSnap = true;
        if ($cpId > 0) {
            try {
                $q = $db->prepare("SELECT src_as_tag_id FROM cp_doc WHERE cp_id=?");
                $q->execute([$cpId]);
                $needSnap = ((int)($q->fetchColumn() ?: 0) === 0);
            } catch (Throwable $e) {}
        }
        if ($needSnap) {
            $tg = cp_order_as_tag($db, (int)$fields['src_order_id']);
            if ($tg && $tg['tag_id']) {
                $fields['src_as_tag_id']    = $tg['tag_id'];
                $fields['src_as_tag_scope'] = $tg['scope'];
                $fields['src_as_tag_label'] = $tg['label'];
            }
        } else {
            unset($fields['src_as_tag_id'], $fields['src_as_tag_scope'], $fields['src_as_tag_label']);
        }
    }

    $own = $db->inTransaction() ? false : true;
    if ($own) $db->beginTransaction();
    try {
        if ($cpId > 0) {
            $set = []; $par = [];
            foreach ($fields as $k => $v) { $set[] = "`$k`=?"; $par[] = $v; }
            $set[] = "modified_at=NOW()"; $set[] = "modified_by=?"; $par[] = $uid;
            $par[] = $cpId;
            $db->prepare("UPDATE cp_doc SET " . implode(',', $set) . " WHERE cp_id=?")->execute($par);
        } else {
            $fields['cp_no'] = cp_next_no($db, $formDate);
            $fields['ver_no'] = trim((string)($in['ver_no'] ?? '')) ?: 'A';
            $fields['status'] = 'draft';
            $cols = array_keys($fields);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO cp_doc (`" . implode('`,`', $cols) . "`, created_at, created_by, created_by_name)
                    VALUES ($ph, NOW(), ?, ?)";
            $par = array_values($fields);
            $par[] = $uid; $par[] = $uname;
            $db->prepare($sql)->execute($par);
            $cpId = (int)$db->lastInsertId();
        }

        // 表單日期改過時重編編號（只有草稿）
        $noInfo = cp_sync_no($db, $cpId);

        // 產品族成員
        if ($scope === 'family') {
            $db->prepare("DELETE FROM cp_family_part WHERE cp_id=?")->execute([$cpId]);
            $parts = is_array($in['family_parts'] ?? null) ? $in['family_parts'] : [];
            $st = $db->prepare("INSERT IGNORE INTO cp_family_part (cp_id, part_d_id, part_no_text) VALUES (?,?,?)");
            foreach ($parts as $fp) {
                $pid = (int)($fp['part_d_id'] ?? 0);
                if ($pid <= 0) continue;
                $st->execute([$cpId, $pid, trim((string)($fp['part_no_text'] ?? ''))]);
            }
        } else {
            $db->prepare("DELETE FROM cp_family_part WHERE cp_id=?")->execute([$cpId]);
        }

        // 製程與特性列：整份覆寫
        $old = $db->prepare("SELECT cp_proc_id FROM cp_process WHERE cp_id=?");
        $old->execute([$cpId]);
        $oldIds = $old->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($oldIds) {
            $in2 = implode(',', array_fill(0, count($oldIds), '?'));
            $db->prepare("DELETE FROM cp_item WHERE cp_proc_id IN ($in2)")->execute($oldIds);
            $db->prepare("DELETE FROM cp_process WHERE cp_id=?")->execute([$cpId]);
        }

        $procs = is_array($in['processes'] ?? null) ? $in['processes'] : [];
        $pSt = $db->prepare(
            "INSERT INTO cp_process
               (cp_id, seq, process_no, process_name, op_desc, machine, jig_tool,
                maker_id_no, maker_name, is_outsource, insp_stage, bom_sn, src, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $iSt = $db->prepare(
            "INSERT INTO cp_item
               (cp_proc_id, seq, char_no, char_product, char_process, special_class_id,
                special_class_text, spec_text, up_limit, lo_limit, eval_method, tool_id,
                tool_no, sample_size, sample_freq, control_method, reaction_plan, src, src_ref, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $nProc = 0; $nItem = 0;
        foreach ($procs as $pi => $p) {
            $pname = trim((string)($p['process_name'] ?? ''));
            $pno   = (int)($p['process_no'] ?? 0) ?: null;
            if ($pname === '' && !$pno) continue;
            $inspStage = cp_insp_stage_norm($p['insp_stage'] ?? null);
            $pSt->execute([
                $cpId, $pi + 1, $pno, $pname,
                trim((string)($p['op_desc'] ?? '')),
                trim((string)($p['machine'] ?? '')),
                trim((string)($p['jig_tool'] ?? '')),
                trim((string)($p['maker_id_no'] ?? '')) ?: null,
                trim((string)($p['maker_name'] ?? '')),
                (int)!empty($p['is_outsource']),
                $inspStage,
                isset($p['bom_sn']) && $p['bom_sn'] !== '' ? (int)$p['bom_sn'] : null,
                trim((string)($p['src'] ?? 'manual')),
                trim((string)($p['note'] ?? '')),
            ]);
            $procId = (int)$db->lastInsertId();
            $nProc++;
            $items = is_array($p['items'] ?? null) ? $p['items'] : [];
            foreach ($items as $ii => $it) {
                $cprod = trim((string)($it['char_product'] ?? ''));
                $cproc = trim((string)($it['char_process'] ?? ''));
                if ($cprod === '' && $cproc === '') continue;
                $iSt->execute([
                    $procId, $ii + 1,
                    trim((string)($it['char_no'] ?? '')),
                    $cprod, $cproc,
                    (int)($it['special_class_id'] ?? 0) ?: null,
                    trim((string)($it['special_class_text'] ?? '')),
                    trim((string)($it['spec_text'] ?? '')),
                    trim((string)($it['up_limit'] ?? '')),
                    trim((string)($it['lo_limit'] ?? '')),
                    trim((string)($it['eval_method'] ?? '')),
                    (int)($it['tool_id'] ?? 0) ?: null,
                    trim((string)($it['tool_no'] ?? '')),
                    trim((string)($it['sample_size'] ?? '')),
                    trim((string)($it['sample_freq'] ?? '')),
                    trim((string)($it['control_method'] ?? '')),
                    trim((string)($it['reaction_plan'] ?? '')),
                    trim((string)($it['src'] ?? 'manual')),
                    trim((string)($it['src_ref'] ?? '')),
                    trim((string)($it['note'] ?? '')),
                ]);
                $nItem++;
            }
        }

        if ($own) $db->commit();
        return [
            'ok' => true, 'cp_id' => $cpId, 'proc_cnt' => $nProc, 'item_cnt' => $nItem,
            'no_changed' => $noInfo['changed'], 'cp_no' => $noInfo['new'] ?: null,
            'msg' => '已儲存（製程 ' . $nProc . ' 道、特性 ' . $nItem . ' 項）'
                   . ($noInfo['changed'] ? '，編號已依表單日期重編為 ' . $noInfo['new'] : ''),
        ];
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '儲存失敗：' . $e->getMessage()];
    }
}

/** 送出待核准 */
function cp_submit(PDO $db, int $cpId, array $perm): array
{
    if (!$perm['edit']) return ['ok' => false, 'msg' => '沒有權限。'];
    $doc = cp_get($db, $cpId);
    if (!$doc) return ['ok' => false, 'msg' => '找不到這份管制計畫。'];
    if ($doc['status'] !== 'draft') {
        return ['ok' => false, 'msg' => '目前狀態是「' . cp_status_label($doc['status']) . '」，不是草稿（畫面可能不是最新的，請重新整理）。'];
    }
    $chk = cp_validate_for_submit($doc);
    if (!$chk['ok']) return $chk;
    $db->prepare("UPDATE cp_doc SET status='submitted', submitted_at=NOW(), submitted_by=?, modified_at=NOW(), modified_by=? WHERE cp_id=?")
       ->execute([$perm['uid'], $perm['uid'], $cpId]);
    return ['ok' => true, 'msg' => '已送出，等待核准。'];
}

/**
 * 送出前檢查。
 * 刻意只擋「空的管制計畫」與「特性列沒有量測技術或頻率」這兩種——
 * 那是稽核一定會問的兩欄；其餘欄位留白由核准人判斷，不在這裡越權擋下。
 */
function cp_validate_for_submit(array $doc): array
{
    $procs = $doc['processes'] ?? [];
    if (!$procs) return ['ok' => false, 'msg' => '還沒有任何製程列，不能送出。'];
    $nItem = 0; $bad = [];
    foreach ($procs as $p) {
        foreach (($p['items'] ?? []) as $it) {
            $nItem++;
            $miss = [];
            if (trim((string)($it['eval_method'] ?? '')) === '' && trim((string)($it['tool_no'] ?? '')) === '') $miss[] = '檢驗方法';
            if (trim((string)($it['sample_freq'] ?? '')) === '' && trim((string)($it['sample_size'] ?? '')) === '') $miss[] = '樣本/頻率';
            if ($miss) {
                $bad[] = ($p['process_name'] ?: ('製程' . $p['process_no'])) . '「'
                       . (trim((string)($it['char_product'] ?? '')) ?: '未命名特性') . '」缺：' . implode('、', $miss);
            }
        }
    }
    if ($nItem === 0) return ['ok' => false, 'msg' => '每一道製程都還沒有特性列，不能送出。'];
    if ($bad) {
        return ['ok' => false, 'msg' => "以下特性列資料不完整，稽核必查這兩欄：\n・" . implode("\n・", array_slice($bad, 0, 8))
            . (count($bad) > 8 ? "\n（其餘 " . (count($bad) - 8) . " 項略）" : '')];
    }
    return ['ok' => true];
}

/** 核准 */
function cp_approve(PDO $db, int $cpId, array $perm): array
{
    if (!$perm['approve']) return ['ok' => false, 'msg' => '沒有核准權限。'];
    $st = $db->prepare("SELECT status, submitted_by FROM cp_doc WHERE cp_id=? AND is_deleted=0");
    $st->execute([$cpId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'msg' => '找不到這份管制計畫。'];
    if ($row['status'] !== 'submitted') {
        return ['ok' => false, 'msg' => '目前狀態是「' . cp_status_label((string)$row['status']) . '」，不是待核准（請重新整理）。'];
    }
    // SoD：不可核准自己送出的（比照 ai-rules/19 第五節的強制迴避）
    if ((int)$row['submitted_by'] === (int)$perm['uid'] && !$perm['admin']) {
        return ['ok' => false, 'msg' => '不可核准自己送出的管制計畫，請由其他核准人處理。'];
    }
    $db->prepare("UPDATE cp_doc SET status='approved', approved_at=NOW(), approved_by=? WHERE cp_id=?")
       ->execute([$perm['uid'], $cpId]);
    return ['ok' => true, 'msg' => '已核准。'];
}

/** 退回草稿（必填原因，寫進版次履歷留痕） */
function cp_reject(PDO $db, int $cpId, string $reason, array $perm): array
{
    if (!$perm['approve']) return ['ok' => false, 'msg' => '沒有核准權限。'];
    $reason = trim($reason);
    if ($reason === '') return ['ok' => false, 'msg' => '請填寫退回原因。'];
    $st = $db->prepare("SELECT status, ver_no, form_date FROM cp_doc WHERE cp_id=? AND is_deleted=0");
    $st->execute([$cpId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'msg' => '找不到這份管制計畫。'];
    if ($row['status'] !== 'submitted') {
        return ['ok' => false, 'msg' => '目前狀態不是待核准（請重新整理）。'];
    }
    $uname = '';
    try {
        $q = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $q->execute([$perm['uid']]);
        $uname = (string)($q->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE cp_doc SET status='draft', submitted_at=NULL, submitted_by=NULL WHERE cp_id=?")->execute([$cpId]);
        $db->prepare(
            "INSERT INTO cp_revision (cp_id, ver_no, form_date, rev_note, changed_by, changed_by_name, changed_at)
             VALUES (?,?,?,?,?,?,NOW())"
        )->execute([$cpId, $row['ver_no'], $row['form_date'], '【退回】' . $reason, $perm['uid'], $uname]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '退回失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'msg' => '已退回草稿。'];
}

/**
 * 改版：把已核准的內容整份複製成新版次（舊版保留，CP 要留歷史）。
 * 回新的 cp_id。
 */
function cp_revise(PDO $db, int $cpId, string $note, array $perm): array
{
    if (!$perm['edit']) return ['ok' => false, 'msg' => '沒有權限。'];
    $doc = cp_get($db, $cpId);
    if (!$doc) return ['ok' => false, 'msg' => '找不到這份管制計畫。'];

    $uname = '';
    try {
        $q = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $q->execute([$perm['uid']]);
        $uname = (string)($q->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    $newVer = cp_next_ver((string)$doc['ver_no']);
    $today  = date('Y-m-d');

    $db->beginTransaction();
    try {
        $cols = ['stage_id','scope','part_d_id','part_no_text','product_name','family_name',
                 'customer_id','customer_name','part_rev','src_order_id','src_order_oo',
                 'src_bom','src_bom_date','pfmea_doc_id','org_code','key_contact','core_team',
                 'customer_eng_appr','customer_qa_appr','other_appr','note'];
        $vals = [];
        foreach ($cols as $c) $vals[] = $doc[$c];
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare(
            "INSERT INTO cp_doc (`" . implode('`,`', $cols) . "`, cp_no, ver_no, form_date, status,
                                 created_at, created_by, created_by_name)
             VALUES ($ph, ?, ?, ?, 'draft', NOW(), ?, ?)"
        )->execute(array_merge($vals, [cp_next_no($db, $today), $newVer, $today, $perm['uid'], $uname]));
        $newId = (int)$db->lastInsertId();

        $pSt = $db->prepare(
            "INSERT INTO cp_process
               (cp_id, seq, process_no, process_name, op_desc, machine, jig_tool,
                maker_id_no, maker_name, is_outsource, insp_stage, bom_sn, src, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $iSt = $db->prepare(
            "INSERT INTO cp_item
               (cp_proc_id, seq, char_no, char_product, char_process, special_class_id,
                special_class_text, spec_text, up_limit, lo_limit, eval_method, tool_id,
                tool_no, sample_size, sample_freq, control_method, reaction_plan, src, src_ref, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach (($doc['processes'] ?? []) as $p) {
            $pSt->execute([$newId, $p['seq'], $p['process_no'], $p['process_name'], $p['op_desc'],
                $p['machine'], $p['jig_tool'], $p['maker_id_no'], $p['maker_name'],
                $p['is_outsource'], cp_insp_stage_norm($p['insp_stage'] ?? null), $p['bom_sn'], $p['src'], $p['note']]);
            $np = (int)$db->lastInsertId();
            foreach (($p['items'] ?? []) as $it) {
                $iSt->execute([$np, $it['seq'], $it['char_no'], $it['char_product'], $it['char_process'],
                    $it['special_class_id'], $it['special_class_text'], $it['spec_text'],
                    $it['up_limit'], $it['lo_limit'], $it['eval_method'], $it['tool_id'],
                    $it['tool_no'], $it['sample_size'], $it['sample_freq'], $it['control_method'],
                    $it['reaction_plan'], $it['src'], $it['src_ref'], $it['note']]);
            }
        }
        if ($doc['scope'] === 'family') {
            $fSt = $db->prepare("INSERT IGNORE INTO cp_family_part (cp_id, part_d_id, part_no_text) VALUES (?,?,?)");
            foreach (($doc['family_parts'] ?? []) as $fp) {
                $fSt->execute([$newId, $fp['part_d_id'], $fp['part_no_text']]);
            }
        }
        $db->prepare(
            "INSERT INTO cp_revision (cp_id, ver_no, form_date, rev_note, changed_by, changed_by_name, changed_at)
             VALUES (?,?,?,?,?,?,NOW())"
        )->execute([$newId, $newVer, $today, ($note !== '' ? $note : '由 ' . $doc['cp_no'] . '（版次 ' . $doc['ver_no'] . '）改版'),
                    $perm['uid'], $uname]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '改版失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'cp_id' => $newId, 'ver_no' => $newVer, 'msg' => '已建立新版次 ' . $newVer . '（草稿）。'];
}

function cp_delete(PDO $db, int $cpId, array $perm): array
{
    if (!$perm['admin']) return ['ok' => false, 'msg' => '只有管制計畫管理員可以刪除。'];
    $st = $db->prepare("SELECT cp_no FROM cp_doc WHERE cp_id=? AND is_deleted=0");
    $st->execute([$cpId]);
    $no = (string)($st->fetchColumn() ?: '');
    if ($no === '') return ['ok' => false, 'msg' => '找不到這份管制計畫。'];
    $db->prepare("UPDATE cp_doc SET is_deleted=1, modified_at=NOW(), modified_by=? WHERE cp_id=?")
       ->execute([$perm['uid'], $cpId]);
    return ['ok' => true, 'msg' => '已刪除 ' . $no . '。'];
}

/* ===================================================================
 * 八、建議建立清單
 * =================================================================== */

/**
 * 「該建 CP 但還沒建」的清單。
 * 母體＝**訂單掛了稽核製程標籤（kind='process'）的料號**（使用者 2026-10-02 定調）。
 * 標籤機制還沒建立或一個稽核製程都沒定義時，退回「已建 PFMEA 的料號」當母體
 * （那批本來就是客戶要求 APQP 的對象），並在 note 講明為什麼退回。
 *
 * 【一個料號一列，不是一張訂單一列】同一個料號會重複下單（實測 1,126 張需 CP 訂單
 * 只對應 848 個料號），逐訂單列會讓同一個料號出現十幾次。每一列帶「最近一張該標籤的訂單」
 * 當自動帶入的入口。
 *
 * 【為什麼要算資料齊全度並據以排序】實測 848 個需 CP 料號裡只有 25 個有 PFMEA、
 * 0 個有已核准的 SIP。848 筆清單若不排序，使用者不知道從哪裡開始；
 * 把「自動帶得出東西的」排在最前面，才有辦法一筆一筆推進。
 */
function cp_suggest_rows(PDO $db, array $opt = []): array
{
    $stageId = (int)($opt['stage_id'] ?? 0);
    $tagInfo = cp_order_tag_status($db);
    $rows = [];
    $mode = 'pfmea';
    $note = '';
    $tagIds = cp_required_as_tags($db);

    if ($tagInfo['ready'] && $tagIds) {
        $mode = 'as_tag';
        $in = implode(',', array_map('intval', $tagIds));
        /* 一個料號取「最近一張掛該標籤的訂單」。
           用 MAX(Order_id) 的子查詢而不是 GROUP BY + ORDER BY 其他欄位——
           本站 sql_mode 含 ONLY_FULL_GROUP_BY，後者會丟 1055
           （這個坑在本庫已經踩過一次，見 git 紀錄）。 */
        try {
            $st = $db->query(
                "SELECT o.Order_id, o.Order_oo, o.d_id, o.d_id_ID, o.Client_name, o.Order_date, o.Qty,
                        o.as_tag_id, o.as_tag_scope,
                        t.proc_name, t.kind,
                        ds.D_Setting_Id, ds.Revision, ds.Customer_Id, ds.Remark,
                        c.customer,
                        m.n_order
                   FROM order_track o
                   /* 同一個料號取「最近一張掛該標籤的訂單」，筆數一起在這支子查詢算完。
                      刻意不用相關子查詢去數 n_order——那會對 848 列各跑一次，實測慢 1 秒以上
                      （記憶 slow_list_query_pattern 同一類坑）。 */
                   JOIN (SELECT d_id_ID, MAX(Order_id) AS mid, COUNT(*) AS n_order
                           FROM order_track
                          WHERE as_tag_id IN ($in) AND d_id_ID > 0
                            AND (Order_status IS NULL OR Order_status <> 6)
                          GROUP BY d_id_ID) m ON m.mid = o.Order_id
                   JOIN ot_as_proc_tag t ON t.tag_id = o.as_tag_id
                   LEFT JOIN d_setting ds ON ds.d_id = o.d_id_ID
                   LEFT JOIN customer_list c ON c.customer_id = ds.Customer_Id
                  ORDER BY o.Order_date DESC, o.Order_id DESC"
            );
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $note = '母體＝訂單掛了「稽核製程」標籤的料號（' . $tagInfo['n_order'] . ' 張訂單、'
                  . $tagInfo['n_part'] . ' 個料號）。一個料號一列，帶最近一張該標籤的訂單當帶入入口。';
        } catch (Throwable $e) {
            $mode = 'pfmea';
            $note = '讀取稽核製程標籤失敗（' . $e->getMessage() . '），已退回以 PFMEA 為母體。';
        }
    } else {
        $note = ($tagInfo['reason'] ?: '稽核製程標籤尚未可用。')
              . ' 暫以「已建 PFMEA 的料號」為母體。';
    }

    if ($mode === 'pfmea') {
        $st = $db->query(
            "SELECT p.part_d_id, p.part_no_text, p.product_name, p.id AS pfmea_doc_id, p.biz_date,
                    d.D_Setting_Id, d.Revision, d.Customer_Id, c.customer
               FROM pfmea_doc p
               JOIN (SELECT part_d_id, MAX(id) AS mid
                       FROM pfmea_doc
                      WHERE is_deleted=0 AND part_d_id IS NOT NULL AND part_d_id > 0
                      GROUP BY part_d_id) t ON t.mid = p.id
               LEFT JOIN d_setting d ON d.d_id = p.part_d_id
               LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
              ORDER BY p.id DESC"
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // 已建 CP 的料號（依階段）
    $done = [];
    try {
        foreach ($db->query("SELECT part_d_id, stage_id FROM cp_doc
                              WHERE is_deleted=0 AND part_d_id IS NOT NULL") as $r) {
            $done[(int)$r['part_d_id']][(int)$r['stage_id']] = true;
        }
    } catch (Throwable $e) {}

    // 忽略名單
    $ign = [];
    try {
        foreach ($db->query("SELECT part_d_id, stage_id FROM cp_suggest_ignore") as $r) {
            $ign[(int)$r['part_d_id']][(int)$r['stage_id']] = true;
        }
    } catch (Throwable $e) {}

    // 先篩出真正要列的料號，再一次算資料齊全度（逐列各查一次＝848 次 N+1）
    $cand = [];
    foreach ($rows as $r) {
        $pid = (int)($r['part_d_id'] ?? $r['d_id_ID'] ?? 0);
        if ($pid <= 0) continue;
        if (isset($ign[$pid][0])) continue;
        if ($stageId && isset($ign[$pid][$stageId])) continue;
        $haveStages = $done[$pid] ?? [];
        if ($stageId) {
            if (isset($haveStages[$stageId])) continue;
        } elseif ($haveStages) {
            // 不指定階段時，只要有任何一張就不再建議（避免清單被已處理的洗滿）
            continue;
        }
        $r['_pid'] = $pid;
        $r['_have'] = array_keys($haveStages);
        $cand[$pid] = $r;
    }

    $ready = $cand ? cp_part_data_ready($db, array_keys($cand)) : [];

    $out = [];
    foreach ($cand as $pid => $r) {
        $rd = $ready[$pid] ?? ['pfmea' => 0, 'sip' => 0, 'sip_draft' => 0, 'bom' => 0, 'proc' => 0];
        $out[] = [
            'part_d_id'    => $pid,
            'part_no_text' => (string)($r['D_Setting_Id'] ?? $r['part_no_text'] ?? $r['d_id'] ?? ''),
            'product_name' => (string)($r['Remark'] ?? $r['product_name'] ?? ''),
            'part_rev'     => (string)($r['Revision'] ?? ''),
            'customer_id'  => (string)($r['Customer_Id'] ?? ''),
            'customer_name'=> (string)($r['customer'] ?? $r['Client_name'] ?? ''),
            'order_id'     => isset($r['Order_id']) ? (int)$r['Order_id'] : null,
            'order_oo'     => (string)($r['Order_oo'] ?? ''),
            'order_date'   => $r['Order_date'] ?? null,
            'n_order'      => isset($r['n_order']) ? (int)$r['n_order'] : null,
            'as_tag_id'    => isset($r['as_tag_id']) ? (int)$r['as_tag_id'] : null,
            'as_tag_scope' => (string)($r['as_tag_scope'] ?? ''),
            'as_tag_label' => cp_as_tag_label($r['proc_name'] ?? '', $r['as_tag_scope'] ?? ''),
            'pfmea_doc_id' => $rd['pfmea'] ?: (isset($r['pfmea_doc_id']) ? (int)$r['pfmea_doc_id'] : null),
            'has_sip'      => (int)$rd['sip'],
            'sip_draft'    => (int)$rd['sip_draft'],
            'has_bom'      => (int)$rd['bom'],
            'n_proc'       => (int)$rd['proc'],
            'ready_score'  => ($rd['pfmea'] ? 2 : 0) + ($rd['sip'] ? 4 : 0)
                            + ($rd['sip_draft'] ? 1 : 0) + ($rd['proc'] ? 1 : 0),
            'have_stages'  => $r['_have'],
        ];
    }
    // 自動帶得出東西的排前面（齊全度高→訂單多→日期新）
    usort($out, function ($a, $b) {
        if ($a['ready_score'] !== $b['ready_score']) return $b['ready_score'] - $a['ready_score'];
        if ((int)$a['n_order'] !== (int)$b['n_order']) return (int)$b['n_order'] - (int)$a['n_order'];
        return strcmp((string)$b['order_date'], (string)$a['order_date']);
    });

    $sum = ['total' => count($out), 'with_pfmea' => 0, 'with_sip' => 0, 'with_sip_draft' => 0, 'with_proc' => 0];
    foreach ($out as $o) {
        if ($o['pfmea_doc_id']) $sum['with_pfmea']++;
        if ($o['has_sip'])      $sum['with_sip']++;
        if ($o['sip_draft'])    $sum['with_sip_draft']++;
        if ($o['n_proc'])       $sum['with_proc']++;
    }

    return ['rows' => $out, 'mode' => $mode, 'note' => $note,
            'tag_ready' => $tagInfo['ready'], 'tag_status' => $tagInfo, 'summary' => $sum];
}

/**
 * 一批料號的「自動帶入資料齊全度」（一次查完，不要逐列 N+1）。
 * 回 [part_d_id => ['pfmea'=>pfmea_doc_id, 'sip'=>1/0, 'sip_draft'=>1/0, 'bom'=>1/0, 'proc'=>n]]
 *
 * sip 與 sip_draft 分開：**自動帶入只取已核准的版次**（草稿的公差不該印在 CP 上），
 * 但「有 SIP 只是還沒核准」是個可以馬上行動的提示（去把它核准就帶得出來了），
 * 跟「根本沒有 SIP」是兩件事，混在一起會讓使用者白跑一趟。
 */
function cp_part_data_ready(PDO $db, array $partIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $partIds))));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    foreach ($ids as $p) $out[$p] = ['pfmea' => 0, 'sip' => 0, 'sip_draft' => 0, 'bom' => 0, 'proc' => 0];

    try {
        foreach ($db->query("SELECT part_d_id, MAX(id) mid FROM pfmea_doc
                              WHERE is_deleted=0 AND part_d_id IN ($in)
                              GROUP BY part_d_id") as $r) {
            $out[(int)$r['part_d_id']]['pfmea'] = (int)$r['mid'];
        }
    } catch (Throwable $e) {}

    try {
        foreach ($db->query("SELECT d.part_d_id,
                                    MAX(v.status='approved') app,
                                    MAX(v.status<>'approved') dft
                               FROM ss_doc d
                               JOIN ss_ver v ON v.ver_id = d.cur_ver_id
                              WHERE d.is_deleted=0 AND d.kind='sip' AND d.part_d_id IN ($in)
                              GROUP BY d.part_d_id") as $r) {
            $p = (int)$r['part_d_id'];
            $out[$p]['sip']       = (int)$r['app'];
            $out[$p]['sip_draft'] = ((int)$r['app'] === 0 && (int)$r['dft'] === 1) ? 1 : 0;
        }
    } catch (Throwable $e) {}

    /* 有沒有製令、製令上有幾道製程（製程列帶不帶得出來）。
       用該料號最近一張製令；bom.d_setting_id 八成是 NULL（記憶 bom_d_setting_id_mostly_null），
       所以兩個鍵都要比——但**不可以寫成單一個 OR 條件**：`b.d_setting_id=o.d_id_ID
       OR (b.d_setting_id IS NULL AND b.d_id=o.d_id)` 讓 MySQL 完全放棄索引，兩邊
       各自 ALL scan 再 hash join，實測 998 個候選料號要 **2.6 秒**（EXPLAIN 顯示
       衍生表估算 574 萬列中間結果）。拆成 UNION ALL 兩段各自乾淨的等號 join，
       每段都能用上既有索引（`idx_bom_did`／候選 id 的小範圍），實測降到 **0.07 秒**，
       與原寫法逐 pid 比對零差異（2026-10-05 實測 975 筆完全相同）。 */
    try {
        foreach ($db->query(
            "SELECT x.pid, COUNT(bi.bom_ing_fid) n_proc
               FROM (
                 SELECT pid, MAX(bom) bom FROM (
                   SELECT o.d_id_ID pid, b.bom
                     FROM order_track o
                     JOIN bom b ON b.d_setting_id = o.d_id_ID
                    WHERE o.d_id_ID IN ($in)
                   UNION ALL
                   SELECT o.d_id_ID pid, b.bom
                     FROM order_track o
                     JOIN bom b ON b.d_id = o.d_id AND b.d_setting_id IS NULL
                    WHERE o.d_id_ID IN ($in)
                 ) u GROUP BY pid
               ) x
               LEFT JOIN bom_ing bi ON bi.bom = x.bom
              GROUP BY x.pid") as $r) {
            $p = (int)$r['pid'];
            if (!isset($out[$p])) continue;
            $out[$p]['bom']  = 1;
            $out[$p]['proc'] = (int)$r['n_proc'];
        }
    } catch (Throwable $e) {}

    return $out;
}

function cp_suggest_ignore(PDO $db, int $partDId, int $stageId, string $reason, array $perm): array
{
    if (!$perm['edit']) return ['ok' => false, 'msg' => '沒有權限。'];
    if ($partDId <= 0) return ['ok' => false, 'msg' => '料號不正確。'];
    try {
        $db->prepare(
            "INSERT INTO cp_suggest_ignore (part_d_id, stage_id, reason, created_by, created_at)
             VALUES (?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE reason=VALUES(reason), created_by=VALUES(created_by), created_at=NOW()"
        )->execute([$partDId, max(0, $stageId), trim($reason), $perm['uid']]);
    } catch (Throwable $e) { return ['ok' => false, 'msg' => '失敗：' . $e->getMessage()]; }
    return ['ok' => true, 'msg' => '已加入忽略名單。'];
}

function cp_suggest_unignore(PDO $db, int $partDId, int $stageId, array $perm): array
{
    if (!$perm['edit']) return ['ok' => false, 'msg' => '沒有權限。'];
    try {
        $db->prepare("DELETE FROM cp_suggest_ignore WHERE part_d_id=? AND stage_id=?")
           ->execute([$partDId, max(0, $stageId)]);
    } catch (Throwable $e) { return ['ok' => false, 'msg' => '失敗：' . $e->getMessage()]; }
    return ['ok' => true, 'msg' => '已移出忽略名單。'];
}

/* ===================================================================
 * 九、列印用
 * =================================================================== */

/**
 * 列印表頭三固定元素（ai-rules/16）：
 *   大標題＝本公司全名（動態取，禁寫死）
 *   表頭名稱＝綁定的 AS 文件名稱
 *   頁尾右下＝AS 編號，版次依本單的表單日期回推
 */
function cp_print_meta(PDO $db, ?string $bizDate = null): array
{
    $meta = ['company' => '', 'doc_name' => '管制計畫', 'doc_no' => '', 'as_doc_id' => null];
    try {
        $st = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $meta['company'] = (string)($r['customer_full'] ?: $r['customer']);
    } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/asdoc_lib.php';
        $bind = eg_asdoc_get($db, CP_ASDOC_MODULE);
        if ($bind && !empty($bind['doc_id'])) {
            $meta['as_doc_id'] = (int)$bind['doc_id'];
            if (!empty($bind['doc_name'])) $meta['doc_name'] = (string)$bind['doc_name'];
            $meta['doc_no'] = eg_asdoc_no_asof_id($db, (int)$bind['doc_id'], $bizDate);
        }
    } catch (Throwable $e) {}
    return $meta;
}

} // CP_LIB_LOADED
