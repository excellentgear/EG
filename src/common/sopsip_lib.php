<?php
/**
 * sopsip_lib.php — 作業標準書(SOP)／標準檢驗指導書(SIP) 唯一實作
 * 建立：2026-09-21
 *
 * 本模組一頁兩分頁：SOP（生產課為主）／SIP（品管課為主），資料結構共用。
 *
 * ── 三個版面（kind）＝照現行紙本，版面決定欄位與列印長相（使用者 2026-09-21 拍板）
 *   equip   設備操作說明書 3-TD-02-01：機台 SOP。機器編號＝machine_list.asset_no，綁 machine_id。
 *   process 製造製程說明書 3-TD-02-02：通用 SOP 與料號 SOP 都走這個版面，差別只在有沒有綁料號。
 *   sip     標準檢驗指導書 2-QA-02-01：通用 SIP 與料號 SIP。
 *
 * ── 適用範圍（scope）
 *   machine 綁機台（只有 equip 用）／general 通用（不綁）／part 綁特定料號
 *   **料號一律綁 d_setting.d_id（料號主檔 ID）不是料號文字**——同一個料號文字在 d_setting
 *   常常有好幾筆、分屬不同客戶（記憶 bom_client_name_cache 同一條）；part_no_text 只是顯示用快取。
 *   **機台一律綁 machine_list.machine_id**，畫面顯示用 asset_no（機器編號）與 field_no（現場編號）。
 *
 * ── 版次（使用者拍板：同一份文件保留版次歷程）
 *   ss_doc 是「一份文件」，ss_ver 是它的每一個版次。改版＝新增一個 ss_ver（內容由上一版帶過來），
 *   舊版查得到也印得出來，doc.cur_ver_id 指向現行版。紙本上的「修改記錄／修訂履歷」表不另外手打，
 *   由各版次的 ver_no＋form_date＋rev_note＋三個簽章組出來（鐵律4：同一份資訊只有一個來源）。
 *
 * ── 簽核（使用者拍板：三種表單統一成 製表→審核→核准 三關）
 *   關卡代碼與順序的唯一登記處＝ss_slots()。製造製程說明書紙本上的「初審」格因此不印。
 *   簽核人必須：①在**表單日期**當時在職（ai-rules/22 依業務日期回推當時職務與部門職稱）
 *              ②**簽章日期當天沒有請整天假**（半天假仍可簽）＝ss_person_day_ok()
 *   兩條都在前端擋一次、後端存檔時用同一支函式再擋一次（鐵律8）。
 */

require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/position_history_lib.php';
require_once __DIR__ . '/asdoc_lib.php';

/** 版面（kind）＝紙本的三種表單。唯一登記處，新增版面只改這裡 */
function ss_kinds(): array
{
    return [
        'equip'   => ['label' => '設備操作說明書', 'tab' => 'sop', 'as_no' => '3-TD-02-01', 'module' => 'sop_equip'],
        'process' => ['label' => '製造製程說明書', 'tab' => 'sop', 'as_no' => '3-TD-02-02', 'module' => 'sop_process'],
        'sip'     => ['label' => '標準檢驗指導書', 'tab' => 'sip', 'as_no' => '2-QA-02-01', 'module' => 'sip'],
    ];
}

/** 適用範圍 */
function ss_scopes(): array
{
    return ['machine' => '機台', 'general' => '通用', 'part' => '特定料號'];
}

/** 每個版面允許哪些適用範圍：機台 SOP 只能綁機台，其餘可通用或綁料號 */
function ss_kind_scopes(string $kind): array
{
    return $kind === 'equip' ? ['machine'] : ['general', 'part'];
}

/**
 * 簽核關卡（唯一登記處）。陣列順序就是必須依序完成的順序。
 * maker＝製表（填表人本人，送出當下即成立），review＝審核，approve＝核准。
 */
function ss_slots(): array
{
    return [
        'maker'   => ['label' => '製表', 'seq' => 1],
        'review'  => ['label' => '審核', 'seq' => 2],
        'approve' => ['label' => '核准', 'seq' => 3],
    ];
}

function ss_statuses(): array
{
    return ['draft' => '草稿', 'submitted' => '簽核中', 'approved' => '已核准', 'obsolete' => '已作廢'];
}

/* ════════════════════════════ schema ════════════════════════════ */

function ss_ensure_col(PDO $db, string $table, string $col, string $ddl): void
{
    try {
        $st = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        if (!$st->fetchColumn()) $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    } catch (Throwable $e) { /* 表還沒建時交給 CREATE TABLE */ }
}

/**
 * 建表。**一定要先確認表不存在、且不在交易中才下 DDL**——MySQL 的 CREATE TABLE 會造成隱式
 * commit，包在交易裡會讓外層 commit() 爆 "There is no active transaction"，而資料其實已經寫進去，
 * 畫面卻顯示失敗（本專案已在 eg_org_save() 與資料稽核踩過兩次）。
 */
function ss_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ($db->inTransaction()) return;
        $have = [];
        foreach ($db->query("SHOW TABLES LIKE 'ss\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) $have[$t] = 1;

        if (empty($have['ss_doc'])) $db->exec("CREATE TABLE ss_doc (
            doc_id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(10) NOT NULL COMMENT 'equip/process/sip 三種版面',
            scope VARCHAR(10) NOT NULL COMMENT 'machine/general/part',
            machine_id INT NULL COMMENT '綁 machine_list.machine_id',
            part_d_id INT NULL COMMENT '綁 d_setting.d_id 料號主檔ID，不是料號文字',
            part_no_text VARCHAR(120) NULL COMMENT '料號文字，顯示用快取',
            title VARCHAR(200) NOT NULL DEFAULT '' COMMENT '文件名稱',
            proc_name VARCHAR(120) NULL COMMENT '製程（工程名稱）',
            cur_ver_id INT NULL COMMENT '現行版次',
            is_deleted TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NULL, created_by INT NULL, created_by_name VARCHAR(60) NULL,
            modified_at DATETIME NULL, modified_by INT NULL,
            INDEX idx_kind (kind, scope), INDEX idx_part (part_d_id), INDEX idx_machine (machine_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SOP／SIP 文件主檔'");

        if (empty($have['ss_ver'])) $db->exec("CREATE TABLE ss_ver (
            ver_id INT AUTO_INCREMENT PRIMARY KEY,
            doc_id INT NOT NULL,
            ver_no VARCHAR(16) NOT NULL DEFAULT '01' COMMENT '版次，照紙本可為 01/02 或 A/D 或 0',
            form_date DATE NULL COMMENT '表單日期（製表日期）＝業務日期',
            rev_note VARCHAR(255) NULL COMMENT '制/修訂事項',
            status VARCHAR(12) NOT NULL DEFAULT 'draft',
            customer_id VARCHAR(20) NULL, customer_name VARCHAR(120) NULL,
            order_no VARCHAR(60) NULL COMMENT '製令單號（SIP 表頭）',
            qty VARCHAR(40) NULL COMMENT '數量（SIP 表頭）',
            m_maker VARCHAR(120) NULL COMMENT '機器製造商（equip）',
            m_name VARCHAR(120) NULL COMMENT '機器名稱（equip）',
            m_spec VARCHAR(200) NULL COMMENT '型式規格（equip）',
            m_range VARCHAR(200) NULL COMMENT '加工適用範圍（equip）',
            op_method TEXT NULL COMMENT '操作方法（equip）',
            cautions TEXT NULL COMMENT '使用注意事項（equip）',
            maintain TEXT NULL COMMENT '保養維修要點（equip）',
            use_equip VARCHAR(200) NULL COMMENT '使用設備（process）',
            est_hours VARCHAR(60) NULL COMMENT '預計工時（process）',
            notice TEXT NULL COMMENT '注意事項（sip）',
            draw_file_id INT NULL COMMENT '帶入的圖面 ss_file.file_id',
            as_doc_id INT NULL COMMENT '綁定的 AS 文件 id，空＝用版面預設',
            created_at DATETIME NULL, created_by INT NULL,
            modified_at DATETIME NULL, modified_by INT NULL,
            INDEX idx_doc (doc_id, ver_id), INDEX idx_status (status)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SOP／SIP 版次'");

        if (empty($have['ss_step'])) $db->exec("CREATE TABLE ss_step (
            step_id INT AUTO_INCREMENT PRIMARY KEY,
            ver_id INT NOT NULL, seq INT NOT NULL DEFAULT 1,
            step_name VARCHAR(120) NULL COMMENT '項次名稱',
            img_file_id INT NULL COMMENT '參考圖示 ss_file.file_id',
            step_text TEXT NULL COMMENT '操作步驟',
            note TEXT NULL COMMENT '說明',
            INDEX idx_ver (ver_id, seq)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='製造製程說明書操作步驟'");

        if (empty($have['ss_item'])) $db->exec("CREATE TABLE ss_item (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            ver_id INT NOT NULL, seq INT NOT NULL DEFAULT 1,
            ctrl_point VARCHAR(160) NULL COMMENT '管理重點',
            q_char VARCHAR(200) NULL COMMENT '品質特性',
            up_limit VARCHAR(40) NULL COMMENT '上限',
            lo_limit VARCHAR(40) NULL COMMENT '下限',
            owner VARCHAR(40) NULL COMMENT '擔當者',
            method VARCHAR(120) NULL COMMENT '檢驗方法',
            tool_no VARCHAR(60) NULL COMMENT '檢具編號',
            freq VARCHAR(80) NULL COMMENT '檢驗頻率',
            note VARCHAR(255) NULL COMMENT '備註',
            INDEX idx_ver (ver_id, seq)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='標準檢驗指導書檢驗項目'");

        if (empty($have['ss_sign'])) $db->exec("CREATE TABLE ss_sign (
            sign_id INT AUTO_INCREMENT PRIMARY KEY,
            ver_id INT NOT NULL,
            slot VARCHAR(12) NOT NULL COMMENT 'maker/review/approve',
            user_id INT NULL, user_name VARCHAR(60) NULL,
            dept_name VARCHAR(60) NULL COMMENT '簽核當時的部門',
            position_name VARCHAR(60) NULL COMMENT '簽核當時的職稱',
            sign_date DATE NULL COMMENT '印章日期＝業務日期',
            signed_at DATETIME NULL COMMENT '實際按下的時刻，與業務日期分開存',
            is_auto TINYINT NOT NULL DEFAULT 0 COMMENT '1＝自動簽核，畫面與列印不顯示此字樣',
            by_deputy INT NULL COMMENT '代理人代簽時記被代理人 id',
            note VARCHAR(255) NULL,
            UNIQUE KEY uk_slot (ver_id, slot)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SOP／SIP 三個簽章格'");

        if (empty($have['ss_file'])) $db->exec("CREATE TABLE ss_file (
            file_id INT AUTO_INCREMENT PRIMARY KEY,
            doc_id INT NOT NULL, ver_id INT NULL,
            usage_kind VARCHAR(10) NOT NULL DEFAULT 'other' COMMENT 'draw/step/scan/other',
            src VARCHAR(10) NOT NULL DEFAULT 'upload' COMMENT 'upload 或 part（料號附件帶入）',
            part_attach_id INT NULL COMMENT 'src=part 時的 part_attachments.id，只記關聯不複製檔',
            file_name VARCHAR(255) NULL COMMENT '實體檔名，只存檔名不存路徑（鐵律5）',
            orig_name VARCHAR(255) NULL,
            mime VARCHAR(80) NULL, file_size INT NULL,
            uploaded_at DATETIME NULL, uploaded_by INT NULL,
            INDEX idx_doc (doc_id), INDEX idx_ver (ver_id, usage_kind)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SOP／SIP 圖面、步驟圖與紙本掃描檔'");
    } catch (Throwable $e) { /* 交給呼叫端失敗得明確一點 */ }
}

/* ════════════════════════ 設定（system_parameters） ════════════════════════ */

const SS_PARAM_GROUP = 'SOP_SIP';

function ss_setting_get(PDO $db, string $key, $default = null)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([SS_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}

function ss_setting_set(PDO $db, string $key, $val): void
{
    $st = $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value)
                        VALUES (?,?,?) ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)");
    $st->execute([SS_PARAM_GROUP, $key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
}

/**
 * 圖章模板：逐關卡可各自綁一個模板（ai-rules/18）。
 * 沒綁＝用預設回墨印；**有模板時前端一定要連 eg_stamp_tpl.js 一起載**，只載 eg_stamp.js 會靜默退回預設章。
 */
function ss_stamp_tpl(PDO $db, string $slot): ?array
{
    $id = (int)ss_setting_get($db, 'stamp_tpl_' . $slot, 0);
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** 自動簽核是否開啟（逐版面各自可設；ai-rules/21） */
function ss_auto_sign_on(PDO $db, string $kind): bool
{
    return (int)ss_setting_get($db, 'auto_sign_' . $kind, 0) === 1;
}

/** 各關卡的預設簽核人（逐版面各自可設，存 user_id；0＝未設定） */
function ss_default_signer(PDO $db, string $kind, string $slot): int
{
    return (int)ss_setting_get($db, 'signer_' . $kind . '_' . $slot, 0);
}

/* ════════════════════════════ 權限 ════════════════════════════ */

function ss_user_role_codes(PDO $db, int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $codes = [];
    try {
        $st = $db->prepare("SELECT DISTINCT r.role_code FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=?");
        $st->execute([$uid]);
        $codes = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {}
    try {
        $st = $db->prepare("SELECT DISTINCT rf.feature_code FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id WHERE ur.user_id=?");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $f) $codes[] = (string)$f;
    } catch (Throwable $e) {}
    return $cache[$uid] = array_values(array_unique($codes));
}

function ss_user_dept_ids(PDO $db, int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $ids = [];
    try {
        $st = $db->prepare("SELECT DISTINCT department_id FROM user_position WHERE user_id=? AND department_id IS NOT NULL");
        $st->execute([$uid]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {}
    return $cache[$uid] = $ids;
}

/** 這個人是不是屬於某個全站組織角色綁定的部門（含子部門） */
function ss_in_org_dept(PDO $db, int $uid, string $key): bool
{
    require_once __DIR__ . '/org_role_lib.php';
    $ids = eg_org_dept_ids($db, $key);
    if (!$ids) return false;
    foreach (ss_user_dept_ids($db, $uid) as $d) if (in_array($d, $ids, true)) return true;
    return false;
}

/**
 * 權限。SOP（生產課）與 SIP（品管課）分開授權——兩個分頁是不同課室在用，
 * 生產課不該改得動檢驗指導書、品管課也不該改設備操作說明書。
 * 組織角色的部門綁定與 RBAC 角色並用：本來就在那個課的人不必再指派角色。
 */
function ss_perms(PDO $db, int $uid): array
{
    $none = ['uid' => 0, 'name' => '', 'isAdmin' => false, 'canAdmin' => false,
             'canViewSop' => false, 'canEditSop' => false, 'canSignSop' => false,
             'canViewSip' => false, 'canEditSip' => false, 'canSignSip' => false, 'canView' => false];
    if ($uid <= 0) return $none;
    $st = $db->prepare("SELECT id, user_cname, user_uname, state, user_status FROM `user` WHERE id=?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return $none;
    if ((int)$u['state'] === 0 || (int)$u['user_status'] === 90) return $none;   // 離職／特殊帳號 fail-closed

    $codes = ss_user_role_codes($db, $uid);
    $has = function (array $c) use ($codes) { return (bool)array_intersect($c, $codes); };
    $isAdmin  = $uid === 1 || $has(['admin', 'superadmin']);
    $canAdmin = $isAdmin || $has(['sopsip_admin']);

    $prodDept = ss_in_org_dept($db, $uid, 'prod_dept') || ss_in_org_dept($db, $uid, 'pm_dept');
    $qcDept   = ss_in_org_dept($db, $uid, 'qc_dept');

    // 技術課也算數：設備操作說明書與製造製程說明書是 3-TD-* 的文件，本來就由技術課制訂
    $rdDept   = ss_in_org_dept($db, $uid, 'rd_dept');
    $canEditSop = $canAdmin || $has(['sop_edit']) || $prodDept || $rdDept;
    $canSignSop = $canAdmin || $has(['sop_sign']) || $prodDept || $rdDept;
    $canViewSop = $canEditSop || $canSignSop || $has(['sop_view', 'sip_view', 'sopsip_view']) || $qcDept;

    $canEditSip = $canAdmin || $has(['sip_edit']) || $qcDept || $has(['qc_manage_settings']);
    $canSignSip = $canAdmin || $has(['sip_sign']) || $qcDept || $has(['qc_manage_settings']);
    $canViewSip = $canEditSip || $canSignSip || $has(['sip_view', 'sop_view', 'sopsip_view']) || $prodDept;

    return ['uid' => $uid, 'name' => (string)($u['user_cname'] ?: $u['user_uname']),
            'isAdmin' => $isAdmin, 'canAdmin' => $canAdmin,
            'canViewSop' => $canViewSop, 'canEditSop' => $canEditSop, 'canSignSop' => $canSignSop,
            'canViewSip' => $canViewSip, 'canEditSip' => $canEditSip, 'canSignSip' => $canSignSip,
            'canView' => $canViewSop || $canViewSip];
}

/** 這個版面屬於哪一個分頁的權限（equip/process→sop，sip→sip） */
function ss_perm_for_kind(array $P, string $kind, string $what): bool
{
    $tab = ss_kinds()[$kind]['tab'] ?? 'sop';
    $key = ($what === 'view' ? 'canView' : ($what === 'edit' ? 'canEdit' : 'canSign')) . ($tab === 'sip' ? 'Sip' : 'Sop');
    return !empty($P[$key]);
}

/* ══════════════════ 人員：當時在職 ＋ 當天沒請整天假 ══════════════════ */

/** 上班時段（判定「請整天假」用，管理員可改）。預設 08:00~17:00 */
function ss_work_window(PDO $db): array
{
    $s = (string)ss_setting_get($db, 'work_start', '08:00');
    $e = (string)ss_setting_get($db, 'work_end', '17:00');
    if (!preg_match('/^\d{2}:\d{2}$/', $s)) $s = '08:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $e)) $e = '17:00';
    return [$s, $e];
}

/**
 * 一批人在某一天「有沒有請整天假」。
 *
 * 判定走全站共用的 person_schedule_lib，不自己查 leave_request（假別、時數、代理的規則都在那裡）。
 * **但它的 `allday` 只有跨日的假才會是 1**——單日的整天假（08:00~17:30）回來的是時間區間，
 * 只看 allday 會把整天請假的人判成可以簽章。所以這裡改判「這筆假有沒有涵蓋整個上班時段」，
 * 半天假不涵蓋、仍可簽（現場半天假那半天還是有來）。
 *
 * @return array user_id => 原因文字（沒請整天假的人不會出現在回傳裡）
 */
function ss_leave_allday_map(PDO $db, array $userIds, string $date): array
{
    $out = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $out;
    require_once __DIR__ . '/person_schedule_lib.php';
    [$ws, $we] = ss_work_window($db);
    try {
        $map = eg_psched_for_users($db, $ids, $date, $ws, $we);
    } catch (Throwable $e) { return $out; }
    foreach ($map as $uid => $items) {
        foreach ($items as $it) {
            if (($it['source'] ?? '') !== 'leave') continue;
            $txt = (string)($it['text'] ?? '請假');
            if (!empty($it['allday'])) { $out[(int)$uid] = $txt; break; }
            if (preg_match('/^(\d{2}:\d{2})~(\d{2}:\d{2})/', (string)($it['time'] ?? ''), $m)
                && $m[1] <= $ws && $m[2] >= $we) {
                $out[(int)$uid] = $txt;
                break;
            }
        }
    }
    return $out;
}

/**
 * 某個日期當天，這個人是不是「有上班」＝沒有請整天假（使用者要求）。
 * @return array [ok, why]  ok=false 時 why 講清楚是哪一天請了什麼假
 */
function ss_person_day_ok(PDO $db, int $uid, string $date): array
{
    if ($uid <= 0) return [false, '未指定人員'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [true, ''];   // 沒有日期就不判定
    $m = ss_leave_allday_map($db, [$uid], $date);
    if (isset($m[$uid])) return [false, $date . ' 請整天假（' . $m[$uid] . '）'];
    return [true, ''];
}

/**
 * 某個表單日期當時「可以簽這一格的人」。
 * ①以表單日期回推當時在職者與當時的部門職稱（ai-rules/22 第5坑：用現況清單會讓補歷史文件時
 *   當時在職、現已離職的人一個都挑不到，而且完全不報錯）
 * ②再把「簽章日期當天請整天假」的標成不可選（仍列出來並寫明原因，不是安靜消失）
 *
 * @param string $formDate 表單日期（決定在職與職稱）
 * @param string $signDate 簽章日期（決定當天有沒有上班），空＝同表單日期
 */
function ss_signer_candidates(PDO $db, string $formDate, string $signDate = ''): array
{
    $formDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDate) ? $formDate : date('Y-m-d');
    $signDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $signDate) ? $signDate : $formDate;
    $rows = eg_people_posts_asof($db, [], $formDate);
    $ids  = [];
    foreach ($rows as $r) $ids[] = (int)$r['id'];
    $ids = array_values(array_unique(array_filter($ids)));

    $leave = ss_leave_allday_map($db, $ids, $signDate);

    $out = [];
    foreach ($rows as $r) {
        $uid = (int)$r['id'];
        $out[] = [
            'id'        => $uid,
            'post_key'  => (string)($r['post_key'] ?? ($uid . ':0')),
            'name'      => (string)($r['user_cname'] ?? $r['name'] ?? ''),
            'dept_id'   => (int)($r['dept_id'] ?? 0),
            'is_former' => (int)($r['is_former'] ?? 0),
            'dept'      => (string)($r['dept_name'] ?? ''),
            'position'  => (string)($r['position_name'] ?? ''),
            'blocked'   => isset($leave[$uid]) ? 1 : 0,
            'block_why' => $leave[$uid] ?? '',
        ];
    }
    return $out;
}

/**
 * 存檔前驗一次「這個人那一天能不能簽這一格」（鐵律8：前端擋過了後端仍要再擋）。
 * @return array [ok, why, snap]  snap＝當時的部門職稱，直接寫進 ss_sign 當圖章快照
 */
function ss_signer_check(PDO $db, int $uid, string $formDate, string $signDate, int $deptId = 0): array
{
    if ($uid <= 0) return [false, '未指定簽核人員', []];
    $hit = null; $any = null;
    foreach (ss_signer_candidates($db, $formDate, $signDate) as $c) {
        if ($c['id'] !== $uid) continue;
        $any = $any ?: $c;
        // 兼任者有好幾列（一個職務一列），呼叫端有指定是哪一個部門就取那一列，
        // 否則取第一列——**絕不可回推時自己挑職級最高的那個兼任**（ai-rules/22 第3坑）
        if ($deptId > 0 && (int)($c['dept_id'] ?? 0) === $deptId) { $hit = $c; break; }
    }
    $c = $hit ?: $any;
    if (!$c) return [false, '該人員在表單日期 ' . $formDate . ' 當時不在職，無法列為簽核人', []];
    if ($c['blocked']) return [false, $c['name'] . ' 在 ' . $signDate . ' ' . $c['block_why'] . '，不可簽章', []];
    return [true, '', ['dept_name' => $c['dept'], 'position_name' => $c['position'], 'user_cname' => $c['name']]];
}

/* ════════════════════════════ 讀取 ════════════════════════════ */

function ss_doc_get(PDO $db, int $docId): ?array
{
    $st = $db->prepare("SELECT * FROM ss_doc WHERE doc_id=? AND is_deleted=0");
    $st->execute([$docId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    return $d ?: null;
}

function ss_ver_get(PDO $db, int $verId): ?array
{
    $st = $db->prepare("SELECT * FROM ss_ver WHERE ver_id=?");
    $st->execute([$verId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    return $v ?: null;
}

/** 一份文件的全部版次，新→舊（修訂履歷表就是它反過來印） */
function ss_ver_rows(PDO $db, int $docId): array
{
    $st = $db->prepare("SELECT * FROM ss_ver WHERE doc_id=? ORDER BY ver_id DESC");
    $st->execute([$docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ss_sign_map(PDO $db, int $verId): array
{
    $st = $db->prepare("SELECT * FROM ss_sign WHERE ver_id=?");
    $st->execute([$verId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(string)$r['slot']] = $r;
    return $out;
}

function ss_step_rows(PDO $db, int $verId): array
{
    $st = $db->prepare("SELECT * FROM ss_step WHERE ver_id=? ORDER BY seq, step_id");
    $st->execute([$verId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ss_item_rows(PDO $db, int $verId): array
{
    $st = $db->prepare("SELECT * FROM ss_item WHERE ver_id=? ORDER BY seq, item_id");
    $st->execute([$verId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ss_file_rows(PDO $db, int $docId, int $verId = 0): array
{
    if ($verId > 0) {
        $st = $db->prepare("SELECT * FROM ss_file WHERE doc_id=? AND (ver_id IS NULL OR ver_id=?) ORDER BY file_id");
        $st->execute([$docId, $verId]);
    } else {
        $st = $db->prepare("SELECT * FROM ss_file WHERE doc_id=? ORDER BY file_id");
        $st->execute([$docId]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** 一個版次的完整內容（表頭＋明細＋簽章＋檔案），畫面與列印共用同一份 */
function ss_ver_full(PDO $db, int $verId): ?array
{
    $v = ss_ver_get($db, $verId);
    if (!$v) return null;
    $d = ss_doc_get($db, (int)$v['doc_id']);
    if (!$d) return null;
    $kind = (string)$d['kind'];
    return [
        'doc'   => $d,
        'ver'   => $v,
        'kind'  => $kind,
        'steps' => $kind === 'sip' ? [] : ss_step_rows($db, $verId),
        'items' => $kind === 'sip' ? ss_item_rows($db, $verId) : [],
        'signs' => ss_sign_map($db, $verId),
        'files' => ss_file_rows($db, (int)$d['doc_id'], $verId),
        'machine' => ss_machine_row($db, (int)($d['machine_id'] ?? 0)),
    ];
}

/** 機台資料（機器編號一律取 asset_no，現場編號 field_no 只是輔助顯示） */
function ss_machine_row(PDO $db, int $machineId): ?array
{
    if ($machineId <= 0) return null;
    try {
        $st = $db->prepare("SELECT machine_id, machine, field_no, asset_no, machine_model, manufacturer, spec
                            FROM machine_list WHERE machine_id=?");
        $st->execute([$machineId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* ════════════════════════════ 版次 ════════════════════════════ */

/**
 * 下一個版次號。紙本上版次寫法不統一（01/02、A/D、0），所以規則是「看得懂就遞增，看不懂就照抄加1」：
 * 純數字 → 補零遞增（01→02）；單一英文字母 → A→B；其餘 → 後面接 -2、-3。
 */
function ss_next_ver_no(string $cur): string
{
    $cur = trim($cur);
    if ($cur === '') return '01';
    if (preg_match('/^\d+$/', $cur)) {
        $n = (int)$cur + 1;
        return str_pad((string)$n, max(2, strlen($cur)), '0', STR_PAD_LEFT);
    }
    if (preg_match('/^[A-Za-z]$/', $cur)) {
        return strtoupper($cur) === 'Z' ? 'AA' : chr(ord(strtoupper($cur)) + 1);
    }
    if (preg_match('/^(.*)-(\d+)$/', $cur, $m)) return $m[1] . '-' . ((int)$m[2] + 1);
    return $cur . '-2';
}

/** 現行版次＝最新一個已核准的版次；一份都還沒核准時退回最新的那一版（草稿也看得到） */
function ss_current_ver(PDO $db, int $docId): ?array
{
    $st = $db->prepare("SELECT * FROM ss_ver WHERE doc_id=? AND status='approved' ORDER BY ver_id DESC LIMIT 1");
    $st->execute([$docId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if ($v) return $v;
    $st = $db->prepare("SELECT * FROM ss_ver WHERE doc_id=? ORDER BY ver_id DESC LIMIT 1");
    $st->execute([$docId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 重算 doc.cur_ver_id（任何會改變版次狀態的動作之後都要呼叫，唯一實作） */
function ss_refresh_cur_ver(PDO $db, int $docId): void
{
    $v = ss_current_ver($db, $docId);
    $st = $db->prepare("UPDATE ss_doc SET cur_ver_id=? WHERE doc_id=?");
    $st->execute([$v ? (int)$v['ver_id'] : null, $docId]);
}

/* ════════════════════════════ 簽核 ════════════════════════════ */

/**
 * 自動簽核的時間戳（ai-rules/21）：業務日期＝sign_date，實際時刻刻意錯開 5~30 分、**不跨日**，
 * 所以不會印出「核准早於製表」。$prev＝前一格的時刻（Y-m-d H:i:s），空＝從 08:30 起算。
 */
function ss_auto_time(string $signDate, string $prev = ''): string
{
    $base = ($prev !== '' && strpos($prev, $signDate) === 0) ? strtotime($prev) : strtotime($signDate . ' 08:30:00');
    $t    = $base + random_int(5, 30) * 60;
    $end  = strtotime($signDate . ' 23:59:00');
    if ($t > $end) $t = $end;
    return date('Y-m-d H:i:s', $t);
}

/**
 * 寫一格簽章（唯一寫入點）。人員與日期一律先過 ss_signer_check()，前端擋過了這裡仍要再擋（鐵律8）。
 * @throws RuntimeException 人員當時不在職、或當天請整天假
 */
function ss_sign_set(PDO $db, int $verId, string $slot, int $uid, string $signDate, bool $isAuto = false,
                     string $note = '', int $byDeputy = 0, int $deptId = 0): void
{
    if (!isset(ss_slots()[$slot])) throw new RuntimeException('簽核關卡代碼不正確');
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    $formDate = (string)($v['form_date'] ?: date('Y-m-d'));
    $signDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $signDate) ? $signDate : $formDate;
    if ($signDate < $formDate) throw new RuntimeException('簽章日期不可早於表單日期 ' . $formDate);
    if ($signDate > date('Y-m-d')) throw new RuntimeException('簽章日期不可以是未來');

    [$ok, $why, $snap] = ss_signer_check($db, $uid, $formDate, $signDate, $deptId);
    if (!$ok) throw new RuntimeException($why);

    // 同一天多格時接在前一格之後，避免印出「核准早於製表」
    $prev = '';
    foreach (ss_slots() as $k => $def) {
        if ($k === $slot) break;
        $s = ss_sign_map($db, $verId)[$k] ?? null;
        if ($s && !empty($s['signed_at'])) $prev = (string)$s['signed_at'];
    }
    $signedAt = $isAuto ? ss_auto_time($signDate, $prev) : date('Y-m-d H:i:s');

    $st = $db->prepare("INSERT INTO ss_sign (ver_id, slot, user_id, user_name, dept_name, position_name,
                            sign_date, signed_at, is_auto, by_deputy, note)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), user_name=VALUES(user_name),
                            dept_name=VALUES(dept_name), position_name=VALUES(position_name),
                            sign_date=VALUES(sign_date), signed_at=VALUES(signed_at),
                            is_auto=VALUES(is_auto), by_deputy=VALUES(by_deputy), note=VALUES(note)");
    $st->execute([$verId, $slot, $uid, (string)($snap['user_cname'] ?? ''), (string)($snap['dept_name'] ?? ''),
                  (string)($snap['position_name'] ?? ''), $signDate, $signedAt, $isAuto ? 1 : 0,
                  $byDeputy > 0 ? $byDeputy : null, $note !== '' ? $note : null]);
}

/** 三格都簽完了嗎 */
function ss_sign_complete(PDO $db, int $verId): bool
{
    $map = ss_sign_map($db, $verId);
    foreach (array_keys(ss_slots()) as $k) if (empty($map[$k]['user_id'])) return false;
    return true;
}

/** 下一個還沒簽的關卡代碼；全簽完回空字串 */
function ss_next_slot(PDO $db, int $verId): string
{
    $map = ss_sign_map($db, $verId);
    foreach (array_keys(ss_slots()) as $k) if (empty($map[$k]['user_id'])) return $k;
    return '';
}

/** 簽完三格就把版次改成已核准，並重算現行版 */
function ss_maybe_approve(PDO $db, int $verId): void
{
    if (!ss_sign_complete($db, $verId)) return;
    $st = $db->prepare("UPDATE ss_ver SET status='approved' WHERE ver_id=? AND status<>'obsolete'");
    $st->execute([$verId]);
    $v = ss_ver_get($db, $verId);
    if ($v) ss_refresh_cur_ver($db, (int)$v['doc_id']);
}

/* ════════════════════════════ 寫入 ════════════════════════════ */

/** 版次表頭可寫入的欄位（唯一登記處；不在這份清單上的欄位一律不給前端寫） */
function ss_ver_fields(): array
{
    return ['ver_no', 'form_date', 'rev_note', 'customer_id', 'customer_name', 'order_no', 'qty',
            'm_maker', 'm_name', 'm_spec', 'm_range', 'op_method', 'cautions', 'maintain',
            'use_equip', 'est_hours', 'notice', 'draw_file_id', 'as_doc_id'];
}

/**
 * 建立／更新文件主檔。
 * 料號一律存 d_setting.d_id，part_no_text 由主檔即時回查（不採信前端送的料號文字）；
 * 機台一律存 machine_list.machine_id。綁定對象不存在就擋下（鐵律8）。
 */
function ss_doc_save(PDO $db, array $in, int $uid, string $uname): int
{
    $docId = (int)($in['doc_id'] ?? 0);
    $kind  = (string)($in['kind'] ?? '');
    if (!isset(ss_kinds()[$kind])) throw new RuntimeException('表單版面代碼不正確');
    $scope = (string)($in['scope'] ?? '');
    if (!in_array($scope, ss_kind_scopes($kind), true)) {
        throw new RuntimeException(ss_kinds()[$kind]['label'] . ' 不適用「' . (ss_scopes()[$scope] ?? $scope) . '」這個適用範圍');
    }

    $machineId = 0; $partDId = 0; $partNo = null;
    if ($scope === 'machine') {
        $machineId = (int)($in['machine_id'] ?? 0);
        if (!ss_machine_row($db, $machineId)) throw new RuntimeException('請選擇機台（找不到這台機器）');
    } elseif ($scope === 'part') {
        $partDId = (int)($in['part_d_id'] ?? 0);
        $st = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id=?");
        $st->execute([$partDId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('請選擇料號（找不到這筆料號主檔）');
        $partNo = (string)$p['D_Setting_Id'];
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') throw new RuntimeException('請填寫文件名稱');
    $proc = trim((string)($in['proc_name'] ?? ''));

    if ($docId > 0) {
        if (!ss_doc_get($db, $docId)) throw new RuntimeException('找不到這份文件');
        $st = $db->prepare("UPDATE ss_doc SET scope=?, machine_id=?, part_d_id=?, part_no_text=?, title=?,
                                proc_name=?, modified_at=NOW(), modified_by=? WHERE doc_id=?");
        $st->execute([$scope, $machineId ?: null, $partDId ?: null, $partNo, $title, $proc ?: null, $uid, $docId]);
        return $docId;
    }
    $st = $db->prepare("INSERT INTO ss_doc (kind, scope, machine_id, part_d_id, part_no_text, title, proc_name,
                            created_at, created_by, created_by_name)
                        VALUES (?,?,?,?,?,?,?,NOW(),?,?)");
    $st->execute([$kind, $scope, $machineId ?: null, $partDId ?: null, $partNo, $title, $proc ?: null, $uid, $uname]);
    return (int)$db->lastInsertId();
}

/**
 * 建立一個版次。$in 沒送的欄位一律不動（array_key_exists 判「有沒有送這個欄位」，
 * 送空字串才是清空——本專案已踩過好幾次「沒送的欄位被一起寫成 NULL」）。
 */
function ss_ver_save(PDO $db, int $verId, array $in, int $uid): void
{
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    if ((string)$v['status'] === 'approved') throw new RuntimeException('已核准的版次不可修改，請建立新版次');
    if ((string)$v['status'] === 'obsolete') throw new RuntimeException('已作廢的版次不可修改');

    $set = []; $par = [];
    foreach (ss_ver_fields() as $f) {
        if (!array_key_exists($f, $in)) continue;
        $val = $in[$f];
        if ($f === 'form_date') {
            $val = trim((string)$val);
            if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) throw new RuntimeException('表單日期格式不正確');
            if ($val !== '' && $val > date('Y-m-d')) throw new RuntimeException('表單日期不可以是未來');
            $val = $val !== '' ? $val : null;
        } elseif ($f === 'draw_file_id' || $f === 'as_doc_id') {
            $val = (int)$val ?: null;
        } else {
            $val = is_string($val) ? trim($val) : $val;
            if ($val === '') $val = null;
        }
        $set[] = "`$f`=?"; $par[] = $val;
    }
    if (!$set) return;
    $set[] = 'modified_at=NOW()'; $set[] = 'modified_by=?'; $par[] = $uid;
    $par[] = $verId;
    $db->prepare("UPDATE ss_ver SET " . implode(',', $set) . " WHERE ver_id=?")->execute($par);
}

/** 建立第一個版次（新文件用） */
function ss_ver_create(PDO $db, int $docId, array $in, int $uid): int
{
    $formDate = trim((string)($in['form_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDate)) $formDate = date('Y-m-d');
    $verNo = trim((string)($in['ver_no'] ?? '')) ?: '01';
    $st = $db->prepare("INSERT INTO ss_ver (doc_id, ver_no, form_date, rev_note, status, created_at, created_by)
                        VALUES (?,?,?,?, 'draft', NOW(), ?)");
    $st->execute([$docId, $verNo, $formDate, trim((string)($in['rev_note'] ?? '初訂')) ?: null, $uid]);
    $verId = (int)$db->lastInsertId();
    ss_ver_save($db, $verId, $in, $uid);
    ss_refresh_cur_ver($db, $docId);
    return $verId;
}

/**
 * 改版：以某一版為底複製出新版次（內容全部帶過來，明細也複製），狀態回到草稿。
 * 舊版不動——修訂履歷就是靠舊版一列一列留著才印得出來。
 */
function ss_ver_clone(PDO $db, int $fromVerId, array $in, int $uid): int
{
    $src = ss_ver_get($db, $fromVerId);
    if (!$src) throw new RuntimeException('找不到要改版的版次');
    $docId  = (int)$src['doc_id'];
    $verNo  = trim((string)($in['ver_no'] ?? '')) ?: ss_next_ver_no((string)$src['ver_no']);
    $formDate = trim((string)($in['form_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDate)) $formDate = date('Y-m-d');
    $revNote = trim((string)($in['rev_note'] ?? ''));

    $cols = ss_ver_fields();
    $copy = array_values(array_diff($cols, ['ver_no', 'form_date', 'rev_note']));
    $names = implode(',', array_map(fn($c) => "`$c`", $copy));
    $sel   = [];
    foreach ($copy as $c) $sel[] = $src[$c] ?? null;

    $st = $db->prepare("INSERT INTO ss_ver (doc_id, ver_no, form_date, rev_note, status, created_at, created_by, $names)
                        VALUES (?,?,?,?, 'draft', NOW(), ?" . str_repeat(',?', count($copy)) . ")");
    $st->execute(array_merge([$docId, $verNo, $formDate, $revNote ?: null, $uid], $sel));
    $newId = (int)$db->lastInsertId();

    foreach (ss_step_rows($db, $fromVerId) as $s) {
        $db->prepare("INSERT INTO ss_step (ver_id, seq, step_name, img_file_id, step_text, note) VALUES (?,?,?,?,?,?)")
           ->execute([$newId, (int)$s['seq'], $s['step_name'], $s['img_file_id'], $s['step_text'], $s['note']]);
    }
    foreach (ss_item_rows($db, $fromVerId) as $i) {
        $db->prepare("INSERT INTO ss_item (ver_id, seq, ctrl_point, q_char, up_limit, lo_limit, owner, method, tool_no, freq, note)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$newId, (int)$i['seq'], $i['ctrl_point'], $i['q_char'], $i['up_limit'], $i['lo_limit'],
                      $i['owner'], $i['method'], $i['tool_no'], $i['freq'], $i['note']]);
    }
    ss_refresh_cur_ver($db, $docId);
    return $newId;
}

/** 覆寫某一版的步驟明細（整批取代；呼叫端已在交易中） */
function ss_steps_replace(PDO $db, int $verId, array $rows): void
{
    $db->prepare("DELETE FROM ss_step WHERE ver_id=?")->execute([$verId]);
    $seq = 0;
    foreach ($rows as $r) {
        $txt = trim((string)($r['step_text'] ?? ''));
        $nm  = trim((string)($r['step_name'] ?? ''));
        if ($txt === '' && $nm === '') continue;           // 整列空白的不存（可增列表格的末列）
        $seq++;
        $db->prepare("INSERT INTO ss_step (ver_id, seq, step_name, img_file_id, step_text, note) VALUES (?,?,?,?,?,?)")
           ->execute([$verId, $seq, $nm ?: null, (int)($r['img_file_id'] ?? 0) ?: null, $txt ?: null,
                      trim((string)($r['note'] ?? '')) ?: null]);
    }
}

/** 覆寫某一版的檢驗項目明細 */
function ss_items_replace(PDO $db, int $verId, array $rows): void
{
    $db->prepare("DELETE FROM ss_item WHERE ver_id=?")->execute([$verId]);
    $seq = 0;
    $f = fn($k, $r) => (($s = trim((string)($r[$k] ?? ''))) !== '' ? $s : null);
    foreach ($rows as $r) {
        if (trim((string)($r['ctrl_point'] ?? '')) === '' && trim((string)($r['q_char'] ?? '')) === '') continue;
        $seq++;
        $db->prepare("INSERT INTO ss_item (ver_id, seq, ctrl_point, q_char, up_limit, lo_limit, owner, method, tool_no, freq, note)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$verId, $seq, $f('ctrl_point', $r), $f('q_char', $r), $f('up_limit', $r), $f('lo_limit', $r),
                      $f('owner', $r), $f('method', $r), $f('tool_no', $r), $f('freq', $r), $f('note', $r)]);
    }
}

/* ════════════════════════════ 送簽 ════════════════════════════ */

/**
 * 送簽前的必填檢查。前端會即時標紅，這裡是後端同規則再擋一次（鐵律8）。
 * @return array 缺什麼的清單，空陣列＝可以送
 */
function ss_submit_check(PDO $db, int $verId): array
{
    $full = ss_ver_full($db, $verId);
    if (!$full) return ['找不到這個版次'];
    $v = $full['ver']; $d = $full['doc']; $bad = [];
    if (empty($v['form_date'])) $bad[] = '表單日期';
    if (trim((string)$d['title']) === '') $bad[] = '文件名稱';
    if (trim((string)$v['ver_no']) === '') $bad[] = '版次';

    if ($full['kind'] === 'equip') {
        if (trim((string)$v['op_method']) === '') $bad[] = '操作方法';
    } elseif ($full['kind'] === 'process') {
        if (!$full['steps']) $bad[] = '至少一列操作步驟';
    } else {
        if (!$full['items']) $bad[] = '至少一列檢驗項目';
    }
    return $bad;
}

/**
 * 送簽。maker（製表）在送出當下就成立，業務日期＝表單日期（ai-rules/21：業務日期與精確時間戳分離）。
 * 管理員可在 $opt 指定填表人與各關簽核人、以及自動簽核（$opt['auto']）。
 * 自動簽核會把 review／approve 一起簽完，時間依 ss_auto_time() 錯開且不跨日。
 *
 * @param array $opt maker_id / review_id / approve_id / sign_date / auto(bool)
 */
function ss_submit(PDO $db, int $verId, int $uid, array $opt = []): array
{
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    if ((string)$v['status'] !== 'draft') throw new RuntimeException('這個版次已經送簽過了，請重新整理頁面');
    $bad = ss_submit_check($db, $verId);
    if ($bad) throw new RuntimeException('這些欄位還沒填：' . implode('、', $bad));

    $d    = ss_doc_get($db, (int)$v['doc_id']);
    $kind = (string)($d['kind'] ?? '');
    $formDate = (string)$v['form_date'];
    $signDate = trim((string)($opt['sign_date'] ?? '')) ?: $formDate;

    $makerId = (int)($opt['maker_id'] ?? 0) ?: $uid;
    ss_sign_set($db, $verId, 'maker', $makerId, $signDate, !empty($opt['auto']));
    $db->prepare("UPDATE ss_ver SET status='submitted' WHERE ver_id=?")->execute([$verId]);

    $done = ['maker'];
    if (!empty($opt['auto']) || ss_auto_sign_on($db, $kind)) {
        foreach (['review', 'approve'] as $slot) {
            $sid = (int)($opt[$slot . '_id'] ?? 0) ?: ss_default_signer($db, $kind, $slot);
            if ($sid <= 0) break;                       // 沒設定預設簽核人就停在這一關，不亂猜人
            ss_sign_set($db, $verId, $slot, $sid, $signDate, true);
            $done[] = $slot;
        }
    }
    ss_maybe_approve($db, $verId);
    $after = ss_ver_get($db, $verId);
    return ['signed' => $done, 'status' => (string)($after['status'] ?? 'submitted')];
}

/** 作廢一個版次（不刪資料；作廢後重算現行版） */
function ss_ver_obsolete(PDO $db, int $verId, int $uid): void
{
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    $db->prepare("UPDATE ss_ver SET status='obsolete', modified_at=NOW(), modified_by=? WHERE ver_id=?")
       ->execute([$uid, $verId]);
    ss_refresh_cur_ver($db, (int)$v['doc_id']);
}

/* ════════════════════════════ 清單 ════════════════════════════ */

/**
 * 清單查詢。$f 支援：tab(sop/sip)、kind、scope、status、keyword、machine_id、part_d_id、
 * customer、year、only_current(只列現行版)。
 * 回傳的是「文件」一列一份，帶現行版的版次／日期／狀態／簽核進度。
 */
function ss_list(PDO $db, array $f): array
{
    $tab = (string)($f['tab'] ?? 'sop');
    $kinds = [];
    foreach (ss_kinds() as $k => $def) if ($def['tab'] === $tab) $kinds[] = $k;
    if (!empty($f['kind']) && isset(ss_kinds()[$f['kind']])) $kinds = [(string)$f['kind']];
    if (!$kinds) return [];

    $w = ["d.is_deleted=0", "d.kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ")"];
    $p = $kinds;
    if (!empty($f['scope']) && isset(ss_scopes()[$f['scope']])) { $w[] = "d.scope=?"; $p[] = (string)$f['scope']; }
    if (!empty($f['machine_id'])) { $w[] = "d.machine_id=?"; $p[] = (int)$f['machine_id']; }
    if (!empty($f['part_d_id'])) { $w[] = "d.part_d_id=?"; $p[] = (int)$f['part_d_id']; }
    if (!empty($f['status']) && isset(ss_statuses()[$f['status']])) { $w[] = "v.status=?"; $p[] = (string)$f['status']; }
    if (!empty($f['year'])) { $w[] = "YEAR(v.form_date)=?"; $p[] = (int)$f['year']; }
    if (!empty($f['customer'])) { $w[] = "v.customer_name LIKE ?"; $p[] = '%' . (string)$f['customer'] . '%'; }

    // 全表搜尋：畫面上看得到的欄位都要搜得到，多個關鍵字每個都要命中（可分散在不同欄位）
    $kw = trim((string)($f['keyword'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/u', $kw) as $word) {
            if ($word === '') continue;
            $w[] = "(d.title LIKE ? OR d.part_no_text LIKE ? OR d.proc_name LIKE ? OR v.ver_no LIKE ?
                     OR v.customer_name LIKE ? OR v.order_no LIKE ? OR v.m_name LIKE ? OR v.use_equip LIKE ?
                     OR m.asset_no LIKE ? OR m.field_no LIKE ? OR m.machine LIKE ?)";
            for ($i = 0; $i < 11; $i++) $p[] = '%' . $word . '%';
        }
    }

    $sql = "SELECT d.*, v.ver_id, v.ver_no, v.form_date, v.status, v.customer_name, v.order_no,
                   v.use_equip, v.m_name, v.rev_note,
                   m.asset_no, m.field_no, m.machine AS machine_name,
                   (SELECT COUNT(*) FROM ss_ver v2 WHERE v2.doc_id=d.doc_id) AS ver_cnt,
                   (SELECT COUNT(*) FROM ss_sign s WHERE s.ver_id=v.ver_id AND s.user_id IS NOT NULL) AS sign_cnt
            FROM ss_doc d
            JOIN ss_ver v ON v.ver_id = COALESCE(d.cur_ver_id, (SELECT MAX(ver_id) FROM ss_ver x WHERE x.doc_id=d.doc_id))
            LEFT JOIN machine_list m ON m.machine_id = d.machine_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY d.kind, d.scope, COALESCE(d.part_no_text, m.asset_no, d.title), d.doc_id";
    $st = $db->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $slotCnt = count(ss_slots());
    foreach ($rows as &$r) {
        $r['kind_label']  = ss_kinds()[$r['kind']]['label'] ?? $r['kind'];
        $r['scope_label'] = ss_scopes()[$r['scope']] ?? $r['scope'];
        $r['status_label'] = ss_statuses()[$r['status']] ?? $r['status'];
        $r['sign_total']  = $slotCnt;
    }
    return $rows;
}

/* ════════════════════ AS 文件綁定與列印 ════════════════════ */

/**
 * 這一版要印哪一個 AS 編號。版次依**表單日期**回推當時生效的版次（ai-rules/16 第三之四節），
 * 不是一律印現在最新版；逐版次可覆寫綁定（as_doc_id），沒覆寫就用該版面的模組綁定。
 */
function ss_as_no(PDO $db, string $kind, ?int $verAsDocId, ?string $formDate): string
{
    $docId = (int)($verAsDocId ?: 0);
    if ($docId <= 0) $docId = eg_asdoc_id($db, ss_kinds()[$kind]['module'] ?? '');
    if ($docId > 0) {
        $no = eg_asdoc_no_asof_id($db, $docId, $formDate);
        if ($no !== '') return $no;
    }
    return (string)(ss_kinds()[$kind]['as_no'] ?? '');
}

/** 表頭要印的表單名稱＝綁定 AS 文件的 doc_name（ai-rules/16：禁寫死） */
function ss_as_title(PDO $db, string $kind, ?int $verAsDocId): string
{
    $docId = (int)($verAsDocId ?: 0);
    if ($docId <= 0) $docId = eg_asdoc_id($db, ss_kinds()[$kind]['module'] ?? '');
    if ($docId > 0) {
        try {
            $st = $db->prepare("SELECT doc_name FROM as_document WHERE id=?");
            $st->execute([$docId]);
            $n = (string)$st->fetchColumn();
            if ($n !== '') return $n;
        } catch (Throwable $e) {}
    }
    return (string)(ss_kinds()[$kind]['label'] ?? '');
}

/** 本公司全名（列印大標題；動態取自客戶主檔標記為本公司的那一筆，禁寫死） */
function ss_company_name(PDO $db): string
{
    try {
        $n = (string)$db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn();
        if ($n !== '') return $n;
    } catch (Throwable $e) {}
    return '';
}

/* ════════════════════════════ 檔案 ════════════════════════════ */

/** 本模組自己上傳的檔案放哪（鐵律5：全站統一根資料夾＋以頁面名稱為子資料夾，只存檔名不存路徑） */
function ss_attach_dir(PDO $db): string
{
    require_once __DIR__ . '/attach_lib.php';
    $dir = eg_attach_dir($db, 'sopsip_attach_dir', 'SOP_SIP');
    eg_attach_ensure_dir($dir);
    return $dir;
}

/**
 * 把 ss_file 一列解析成實體檔案路徑。
 * src=part 的一律轉呼叫 bom_view_file_lib 的既有解析（料號附件的實體位置只有它一個來源，
 * 在這裡再寫一份就會在 NAS 搬家時漏改）；src=upload 才是本模組自己的資料夾。
 */
function ss_file_path(PDO $db, array $f): ?string
{
    if ((string)($f['src'] ?? '') === 'part') {
        require_once __DIR__ . '/bom_view_file_lib.php';
        $r = eg_bvf_resolve($db, ['src' => 'part', 'ref' => ['id' => (int)($f['part_attach_id'] ?? 0)]]);
        return $r['fs'] ?? null;
    }
    $name = (string)($f['file_name'] ?? '');
    if ($name === '' || strpbrk($name, "/\\") !== false || strpos($name, '..') !== false) return null;
    return rtrim(ss_attach_dir($db), "/\\") . DIRECTORY_SEPARATOR . $name;
}

/** 這個副檔名適合當「帶入的圖面」嗎（圖片與 PDF；PDF 列印時轉成連結不內嵌） */
function ss_is_image(string $name): bool
{
    return (bool)preg_match('/\.(jpe?g|png|gif|bmp|webp)$/i', $name);
}

/**
 * 某個料號可以帶入的圖面候選＝該料號的料號附件（使用者要求可自己選要帶哪一個檔案）。
 * 批圖暫存檔一律不列（那不是正式圖面，見 imgedit_visibility）。
 */
function ss_part_draw_candidates(PDO $db, int $partDId): array
{
    if ($partDId <= 0) return [];
    require_once __DIR__ . '/imgedit_visibility.php';
    try {
        $st = $db->prepare("SELECT pa.id, pa.filename, pa.original_name, pa.category_ids, pa.revision,
                                   DATE(pa.uploaded_at) AS uploaded_on, pa.issue_stamp_date
                            FROM part_attachments pa
                            WHERE pa.d_id=? AND pa.deleted_at IS NULL AND " . imgedit_sql_not_draft('pa') . "
                            ORDER BY pa.uploaded_at DESC, pa.id DESC");
        $st->execute([$partDId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }

    $cats = [];
    try {
        foreach ($db->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c)
            $cats[(int)$c['id']] = (string)$c['category_name'];
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $r) {
        $name = (string)($r['original_name'] ?: $r['filename']);
        if (!ss_is_image($name) && !preg_match('/\.pdf$/i', $name)) continue;
        $tags = [];
        foreach (preg_split('/\s*,\s*/', (string)$r['category_ids']) as $cid) {
            $cid = (int)$cid;
            if ($cid > 0 && isset($cats[$cid])) $tags[] = $cats[$cid];
        }
        $out[] = ['id' => (int)$r['id'], 'name' => $name, 'tags' => $tags,
                  'revision' => (string)($r['revision'] ?? ''),
                  'uploaded_on' => (string)($r['uploaded_on'] ?? ''),
                  'is_image' => ss_is_image($name) ? 1 : 0];
    }
    return $out;
}

/* ════════════════════════════ 搜尋 ════════════════════════════ */

/** 料號搜尋（綁定用；一律回傳料號主檔 id，同名料號用客戶分辨） */
function ss_search_part(PDO $db, string $kw, int $limit = 30): array
{
    $kw = trim($kw);
    if ($kw === '') return [];
    $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id, d.Customer_Id, c.customer
                        FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                        WHERE d.D_Setting_Id LIKE ? ORDER BY d.D_Setting_Id, d.d_id LIMIT $limit");
    $st->execute(['%' . $kw . '%']);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** 機台搜尋（機器編號 asset_no／現場編號 field_no／名稱都搜得到；停用的不列） */
function ss_search_machine(PDO $db, string $kw, int $limit = 30): array
{
    $kw = trim($kw);
    $w  = ["(state IS NULL OR state <> '1')"];   // state='1' 才是停用（見 kpi_main 的機台資產設定）
    $p  = [];
    if ($kw !== '') {
        $w[] = "(asset_no LIKE ? OR field_no LIKE ? OR machine LIKE ? OR machine_model LIKE ?)";
        for ($i = 0; $i < 4; $i++) $p[] = '%' . $kw . '%';
    }
    $st = $db->prepare("SELECT machine_id, machine, field_no, asset_no, machine_model, manufacturer, spec
                        FROM machine_list WHERE " . implode(' AND ', $w) . "
                        ORDER BY asset_no, field_no LIMIT $limit");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
