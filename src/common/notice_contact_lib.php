<?php
/**
 * 聯絡單（AS 2-DC-02-01）—— 公告 / 通知列印成紙本聯絡單的共用實作（唯一實作，禁止各頁自己再寫一份）
 *
 * 2026-09-22 建立。使用者要求：把「公告 / 通知」(views/liveEvent/createEvent.php) 印成紙本 2-DC-02-01 聯絡單，
 * 有回簽的人都要在紙上蓋章，並且管理員可以在列印當下補簽未簽的人。
 *
 * 三件事一定要先看懂，不然會改錯地方：
 *
 * ①「補簽」有兩種，寫入的地方完全不同（使用者明確定調）：
 *    - 一般公告的列印補簽 → 只寫 `live_event_contact_sign`，**不動原始回簽紀錄**。
 *      鈴鐺、已讀 / 應讀統計、回簽人數一律不受影響，純粹是「這張紙上要不要蓋這顆章」。
 *    - 補資料模式建立的聯絡單 → **就是要更動原始資料**，直接寫 `live_event_response`
 *      （signed_via 記下是哪一位管理員代簽，這是本站既有語意：NULL＝本人自己按、>0＝某人代按）
 *      並另寫 audit_log 留下「什麼時候、由哪一位管理員補的」。
 *    判定看 `live_event_contact.is_backfill`，不要用別的旗標猜。
 *
 * ② 聯絡單號（OI）與公告編號（PU）是兩個不同的序號，使用者要求「記錄在一起，方便查找」：
 *    PU 存 `live_event.event_no`（既有）、OI 存 `live_event.contact_no`（本次新增），同一列。
 *    OI 規則＝`OI` + 西元年月日8碼 + 當日流水3碼，而且**有列印才產生**（沒印過的公告不佔號）。
 *    日期一律取該公告的 `eventdate`（＝聯絡單上印的 DATE），不是配號當天——補印舊公告時
 *    編號才跟紙上的日期對得起來（與內部稽核件號、產品開發評估表同一套規則）。
 *
 * ③ 被通知人員的展開一律呼叫 `eg_push_event_recipients()`（全站唯一實作），本檔不自己再解析一次
 *    target_type。要「當時在職」的名單時傳第三個參數（見 push_send.php），不要在這裡另寫一套。
 */

require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/position_history_lib.php';
require_once __DIR__ . '/notice_mode_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';

if (!function_exists('nc_ensure_schema')) {

/** 設定存放位置（system_parameters）＋ AS 文件綁定的模組代碼 */
define('NC_PARAM_GROUP', 'NOTICE_CONTACT');
define('NC_ASDOC_MODULE', 'notice_contact');

/**
 * 建表 / 補欄位（可重複執行）。
 * **DDL 一定要在交易之外做**：MySQL 的 CREATE TABLE / ALTER 會造成隱式 commit，
 * 包在交易裡會讓外層 commit() 爆「There is no active transaction」——而且資料其實已經寫進去了，
 * 是本專案踩過兩次的坑（2026-08-03 eg_org_save、2026-09-21 資料稽核）。故先查存不存在、且不在交易中才下 DDL。
 */
function nc_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ($db->inTransaction()) return;

        $has = function (string $t) use ($db): bool {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
            $st->execute([$t]);
            return (int)$st->fetchColumn() > 0;
        };
        $hasCol = function (string $t, string $c) use ($db): bool {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $st->execute([$t, $c]);
            return (int)$st->fetchColumn() > 0;
        };

        if (!$hasCol('live_event', 'contact_no')) {
            $db->exec("ALTER TABLE live_event ADD COLUMN contact_no VARCHAR(20) NULL COMMENT '聯絡單號 OI+西元年月日+流水3碼（有列印才產生）' AFTER event_no, ADD KEY idx_le_contact_no (contact_no)");
        }
        if (!$has('live_event_contact')) {
            $db->exec("CREATE TABLE live_event_contact (
                live_event_id INT NOT NULL PRIMARY KEY COMMENT '對應 live_event.id',
                from_text     VARCHAR(200) NULL COMMENT '發文者覆寫（空＝用公告建立者）',
                to_text       VARCHAR(600) NULL COMMENT '受文者覆寫（空＝由通知對象自動組出）',
                maker_user_id INT NULL COMMENT '製表人（空＝依設定推導）',
                maker_date    DATE NULL,
                approver_user_id INT NULL COMMENT '核准（空＝依設定推導）',
                approver_date DATE NULL,
                show_reply    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '聯絡單上要不要印回覆內容',
                include_uids  TEXT NULL COMMENT 'JSON：回簽欄只印這些人（NULL＝全部被通知人員）',
                is_backfill   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1＝補資料建立（不發任何通知；補簽會寫回真實回簽紀錄）',
                backfill_by   INT NULL COMMENT '補資料是哪一位管理員建立的',
                backfill_at   DATETIME NULL,
                updated_by    INT NULL,
                updated_at    DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='聯絡單 2-DC-02-01 的逐則列印設定'");
        }
        if (!$has('live_event_contact_sign')) {
            $db->exec("CREATE TABLE live_event_contact_sign (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                live_event_id INT NOT NULL,
                user_id   INT NOT NULL,
                sign_date DATE NOT NULL COMMENT '印在章面上的簽章日期（不得早於公告日期、不得是未來）',
                created_by INT NULL COMMENT '哪一位管理員補的',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uk_lecs (live_event_id, user_id),
                KEY idx_lecs_ev (live_event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='聯絡單列印用補簽（只影響紙上的章，不動 live_event_response）'");
        }
    } catch (Throwable $e) {
        error_log('[notice_contact] ensure_schema: ' . $e->getMessage());
    }
}

/* ===================== 設定 ===================== */

/** 預設值＝唯一登記處；新增設定項只改這裡 */
function nc_setting_defaults(): array
{
    return [
        'stamp_tpl_id'     => '',        // 製表人 / 核准的圖章型式（stamp_template.id；空＝系統預設回墨印）
        'stamp_tpl_read_id'=> '',        // 已閱簽章的圖章型式（空＝沿用上面那個；再空＝系統預設回墨印）
        'maker_src'        => 'creator', // 製表人來源：creator＝公告建立者／user＝指定人員
        'maker_user_id'    => '',
        'approver_src'     => 'top',     // 核准來源：none＝留白手簽／top＝組織角色綁定的最高核准人員／user＝指定人員
        'approver_user_id' => '',
        'show_reply'       => '0',       // 預設要不要印回覆內容
    ];
}

/**
 * ⚠ `system_parameters.param_value` 是 **JSON 欄位**（不是 varchar）。
 * 寫進去的值一定要先 json_encode，否則像 ''、'creator'、'top' 這種裸字串會被 MySQL 以
 * 3140 Invalid JSON text 直接拒絕——而且 nc_settings_save() 是逐筆寫入、不是一次交易，
 * 前幾筆（剛好是數字的那些）已經寫進去了才爆，症狀是「有些設定存得起來、有些永遠存不起來」，
 * 尤其是「清空某個設定」一定失敗（空字串不是合法 JSON）。2026-09-22 實測踩到。
 * 讀的時候一律 json_decode，舊資料是純數字（例 9）也解得出來。
 */
function nc_settings(PDO $db): array
{
    $out = nc_setting_defaults();
    try {
        $st = $db->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group=?");
        $st->execute([NC_PARAM_GROUP]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!array_key_exists($r['param_key'], $out)) continue;
            $raw = (string)$r['param_value'];
            $dec = json_decode($raw, true);
            $out[$r['param_key']] = (is_scalar($dec) || $dec === null)
                ? (string)($dec === null ? '' : $dec)
                : $raw;
        }
    } catch (Throwable $e) {}
    return $out;
}

function nc_settings_save(PDO $db, array $in, string $by): void
{
    foreach (nc_setting_defaults() as $k => $_) {
        // 沒送的欄位不要動它（本專案踩過好幾次：沒送＝舊呼叫端，送空字串才是清空）
        if (!array_key_exists($k, $in)) continue;
        $v = json_encode((string)$in[$k], JSON_UNESCAPED_UNICODE);   // JSON 欄位，見上方說明
        $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([NC_PARAM_GROUP, $k]);
        $id = $st->fetchColumn();
        if ($id) {
            $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$v, $by, $id]);
        } else {
            $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by) VALUES (?,?,?,?,?)")
               ->execute([NC_PARAM_GROUP, $k, $v, '聯絡單 2-DC-02-01 設定', $by]);
        }
    }
}

/** 圖章型式（id=0 或查無回 null，呼叫端拿不到就退回 EGStamp 預設回墨印） */
function nc_stamp_template(PDO $db, string $which = 'main'): ?array
{
    $s = nc_settings($db);
    // 已閱簽章沒有單獨設定時沿用製表 / 核准那一個（多數情況本來就想用同一顆章）
    $id = ($which === 'read')
        ? (int)($s['stamp_tpl_read_id'] ?: ($s['stamp_tpl_id'] ?? 0))
        : (int)($s['stamp_tpl_id'] ?? 0);
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id' => (int)$r['id'], 'name' => (string)$r['tpl_name'], 'schema' => json_decode((string)$r['schema_json'], true)];
    } catch (Throwable $e) { return null; }
}

/** 可選的圖章型式清單（設定跳窗用） */
function nc_stamp_template_list(PDO $db): array
{
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name FROM stamp_template p
                           LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY t.type_name, p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 本公司抬頭（全站列印標準 ai-rules/16：一律動態取、禁寫死） */
function nc_company(PDO $db): array
{
    try {
        $r = $db->query("SELECT customer_full, customer, customer_tel, customer_fax, customer_address
                         FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            return [
                'name' => trim((string)($r['customer_full'] ?: $r['customer'])),
                'tel'  => trim((string)$r['customer_tel']),
                'fax'  => trim((string)$r['customer_fax']),
                'addr' => trim((string)$r['customer_address']),
            ];
        }
    } catch (Throwable $e) {}
    return ['name' => '', 'tel' => '', 'fax' => '', 'addr' => ''];
}

/* ===================== 權限 ===================== */

/**
 * 本模組的權限判定（唯一實作；頁面、API 兩邊共用，不要各判一次＝鐵律8）
 *   canPrint    看得到公告就印得了聯絡單
 *   canSign     列印時補簽、更換製表／核准人員與日期、改受文者、勾選要印誰
 *   canSetting  聯絡單設定（AS 文件綁定、圖章型式、預設簽章人）
 *   canBackfill 補資料（建立一張完全不發通知的聯絡單）
 */
function nc_perms(PDO $db, int $uid): array
{
    require_once __DIR__ . '/rbac.php';
    $f = rbac_user_features($db, $uid);
    $admin = rbac_has($f, 'all');
    return [
        'isAdmin'     => $admin,
        'canPrint'    => $admin || rbac_has($f, 'notice_view'),
        'canSign'     => $admin || rbac_has($f, 'notice_contact_sign'),
        'canSetting'  => $admin || rbac_has($f, 'notice_contact_setting'),
        'canBackfill' => $admin || rbac_has($f, 'notice_contact_backfill'),
    ];
}

/* ===================== 聯絡單號（OI） ===================== */

/**
 * 產生聯絡單號：OI + 西元年月日(8) + 當日流水(3)，例 OI20260922001。
 * 日期取該公告的 eventdate（＝紙上印的 DATE），不是配號當天。
 */
function nc_gen_contact_no(PDO $db, string $date): string
{
    $ts = strtotime($date) ?: time();
    $prefix = 'OI' . date('Ymd', $ts);
    $n = 1;
    try {
        $st = $db->prepare("SELECT contact_no FROM live_event WHERE contact_no LIKE ? ORDER BY contact_no DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)$st->fetchColumn();
        if ($last !== '') $n = (int)substr($last, -3) + 1;
    } catch (Throwable $e) {}
    return $prefix . sprintf('%03d', $n);
}

/**
 * 取這則公告的聯絡單號；沒有就配一個寫回去（使用者要求：有列印才產生）。
 * 用 FOR UPDATE 鎖住該列避免兩個人同時按列印配到同一號（比照 car_alloc_numbers）。
 */
function nc_ensure_contact_no(PDO $db, int $eventId): string
{
    nc_ensure_schema($db);
    $own = !$db->inTransaction();
    try {
        if ($own) $db->beginTransaction();
        $st = $db->prepare("SELECT contact_no, eventdate FROM live_event WHERE id=? FOR UPDATE");
        $st->execute([$eventId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { if ($own) $db->rollBack(); return ''; }
        $no = trim((string)$row['contact_no']);
        if ($no === '') {
            $no = nc_gen_contact_no($db, (string)$row['eventdate']);
            $db->prepare("UPDATE live_event SET contact_no=? WHERE id=?")->execute([$no, $eventId]);
        }
        if ($own) $db->commit();
        return $no;
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        error_log('[notice_contact] ensure_contact_no: ' . $e->getMessage());
        return '';
    }
}

/* ===================== 逐則列印設定 ===================== */

function nc_cfg(PDO $db, int $eventId): array
{
    nc_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT * FROM live_event_contact WHERE live_event_id=?");
        $st->execute([$eventId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $r['include_uids'] = ($r['include_uids'] === null || $r['include_uids'] === '')
                ? null : (json_decode((string)$r['include_uids'], true) ?: []);
            return $r;
        }
    } catch (Throwable $e) {}
    return ['live_event_id' => $eventId, 'from_text' => null, 'to_text' => null,
            'maker_user_id' => null, 'maker_date' => null, 'approver_user_id' => null, 'approver_date' => null,
            'show_reply' => (int)(nc_settings($db)['show_reply'] ?? 0), 'include_uids' => null,
            'is_backfill' => 0, 'backfill_by' => null, 'backfill_at' => null,
            'updated_by' => null, 'updated_at' => null];
}

/** 存逐則設定；只更新「有送來」的欄位（沒送＝不要動它，送空字串才是清空） */
function nc_cfg_save(PDO $db, int $eventId, array $in, int $by): void
{
    nc_ensure_schema($db);
    $cur = nc_cfg($db, $eventId);
    $cols = ['from_text', 'to_text', 'maker_user_id', 'maker_date', 'approver_user_id', 'approver_date',
             'show_reply', 'include_uids', 'is_backfill', 'backfill_by', 'backfill_at'];
    $vals = [];
    foreach ($cols as $col) {
        if (!array_key_exists($col, $in)) {
            $v = $cur[$col] ?? null;
            if ($col === 'include_uids' && is_array($v)) $v = json_encode(array_values(array_map('intval', $v)));
            $vals[$col] = $v;
            continue;
        }
        $v = $in[$col];
        if ($col === 'include_uids') {
            $v = ($v === null) ? null : json_encode(array_values(array_unique(array_map('intval', (array)$v))));
        } elseif (in_array($col, ['maker_user_id', 'approver_user_id', 'show_reply', 'is_backfill', 'backfill_by'], true)) {
            $v = ($v === '' || $v === null) ? null : (int)$v;
        } elseif (in_array($col, ['maker_date', 'approver_date'], true)) {
            $v = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string)$v) ? (string)$v : null;
        } elseif ($col === 'backfill_at') {
            $v = (trim((string)$v) === '') ? null : (string)$v;
        } else {
            $v = (trim((string)$v) === '') ? null : mb_substr(trim((string)$v), 0, 600);
        }
        $vals[$col] = $v;
    }
    if ($vals['show_reply'] === null)  $vals['show_reply'] = 0;
    if ($vals['is_backfill'] === null) $vals['is_backfill'] = 0;

    $db->prepare("INSERT INTO live_event_contact
        (live_event_id, from_text, to_text, maker_user_id, maker_date, approver_user_id, approver_date,
         show_reply, include_uids, is_backfill, backfill_by, backfill_at, updated_by, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE from_text=VALUES(from_text), to_text=VALUES(to_text),
         maker_user_id=VALUES(maker_user_id), maker_date=VALUES(maker_date),
         approver_user_id=VALUES(approver_user_id), approver_date=VALUES(approver_date),
         show_reply=VALUES(show_reply), include_uids=VALUES(include_uids),
         is_backfill=VALUES(is_backfill), backfill_by=VALUES(backfill_by), backfill_at=VALUES(backfill_at),
         updated_by=VALUES(updated_by), updated_at=NOW()")
       ->execute([$eventId, $vals['from_text'], $vals['to_text'], $vals['maker_user_id'], $vals['maker_date'],
                  $vals['approver_user_id'], $vals['approver_date'], (int)$vals['show_reply'], $vals['include_uids'],
                  (int)$vals['is_backfill'], $vals['backfill_by'], $vals['backfill_at'], ($by ?: null)]);
}

/* ===================== 受文者 / 被通知人員 ===================== */

/** 受文者預設文字：由通知對象（全體 / 部門 / 身分 / 指名）組出，與列表上的對象籤同一份來源 */
function nc_targets_text(PDO $db, int $eventId): string
{
    $out = [];
    try {
        $st = $db->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id=? ORDER BY id");
        $st->execute([$eventId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $id = (int)$t['target_id'];
            switch ($t['target_type']) {
                case 'all':    $out[] = '全體員工'; break;
                case 'dept':   $out[] = (string)nc_name_of($db, 'department', 'name', $id); break;
                case 'status': $out[] = (string)nc_name_of($db, 'user_status', 'title', $id); break;
                case 'user':   $out[] = (string)nc_name_of($db, 'user', 'user_cname', $id); break;
            }
        }
    } catch (Throwable $e) {}
    $out = array_values(array_filter(array_map('trim', $out), function ($s) { return $s !== ''; }));
    return implode('、', $out);
}

/** 主檔名稱查詢（表名與欄名一律寫在程式裡，不吃外部輸入） */
function nc_name_of(PDO $db, string $table, string $col, int $id): string
{
    static $cache = [];
    $k = $table . '|' . $id;
    if (isset($cache[$k])) return $cache[$k];
    $allow = ['department' => 'id', 'user_status' => 'id', 'user' => 'id'];
    if (!isset($allow[$table])) return '';
    try {
        $st = $db->prepare("SELECT `$col` FROM `$table` WHERE `{$allow[$table]}`=? LIMIT 1");
        $st->execute([$id]);
        $v = (string)$st->fetchColumn();
    } catch (Throwable $e) { $v = ''; }
    return $cache[$k] = $v;
}

/**
 * 這則公告的被通知人員 + 每個人的簽署狀態（聯絡單回簽欄的資料來源）。
 *
 * 人員展開一律走 `eg_push_event_recipients()`（全站唯一實作）；部門／職稱依公告日期回推當時的
 * （ai-rules/22：補印舊公告時要印當時的單位與職稱，不是現在的）。
 * 排序依「部門 → 職稱 → 姓名」的 sort_order（人員列表鐵則⑤，不是姓名筆畫）。
 *
 * 每個人回傳：
 *   mode        實際生效的通知方式（autoread/read/sign/reply，取最高義務＝eg_notice_mode_pick）
 *   signed_at   真的回簽的時間（null＝沒簽）
 *   reply       回覆內容
 *   fill_date   列印補簽的日期（只有一般公告會有；補資料模式一律寫回真實回簽）
 *   sign_date   章面上要印的日期（真的簽的用 signed_at，否則用 fill_date；都沒有＝留白不蓋章）
 */
function nc_people(PDO $db, int $eventId, string $asof): array
{
    nc_ensure_schema($db);
    require_once __DIR__ . '/../push/push_send.php';

    $ids = [];
    try { $ids = array_map('intval', eg_push_event_recipients($db, $eventId, $asof)); } catch (Throwable $e) {}
    if (!$ids) return [];

    // 每個人符合到哪幾列對象 → 取最高義務的通知方式
    $modeOf = [];
    try {
        $st = $db->prepare("SELECT target_type, target_id, mode FROM live_event_target WHERE live_event_id=?");
        $st->execute([$eventId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $deptOf = [];
        $q = $db->query("SELECT user_id, department_id FROM user_department_position_map");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) $deptOf[(int)$m['user_id']][] = (int)$m['department_id'];
        $stOf = [];
        $q2 = $db->query("SELECT id, user_status, user_status2, user_status3 FROM user");
        foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $stOf[(int)$u['id']] = array_values(array_filter([(int)$u['user_status'], (int)$u['user_status2'], (int)$u['user_status3']]));
        }
        foreach ($ids as $uid) {
            $ms = [];
            foreach ($rows as $r) {
                $tid = (int)$r['target_id'];
                $hit = false;
                if ($r['target_type'] === 'all') $hit = true;
                elseif ($r['target_type'] === 'user')   $hit = ($tid === $uid);
                elseif ($r['target_type'] === 'dept')   $hit = in_array($tid, $deptOf[$uid] ?? [], true);
                elseif ($r['target_type'] === 'status') $hit = in_array($tid, $stOf[$uid] ?? [], true);
                if ($hit) $ms[] = (string)$r['mode'];
            }
            $modeOf[$uid] = $ms ? eg_notice_mode_pick($ms) : 'read';
        }
    } catch (Throwable $e) {}

    /* 真實回簽 / 回覆 / 已閱。
       **已閱有兩個來源，兩個都要讀**（與 _eventReaders.php 同一套合併規則）：
         live_event_response  → 回簽 / 回覆模式的紀錄（read_at / signed_at / reply_content）
         live_event_for_user  → 純「已閱」模式的閱讀紀錄（oready_read / read_at）
       只讀前者的話，通知方式是「已閱」的人一個都抓不到——實測 event 25 有 15 人已閱全部漏掉，
       畫面上只會看到「未簽、章面日期 —」，完全看不出是漏讀了一張表。 */
    $resp = [];
    try {
        foreach ($db->query("SELECT user_id, read_at, signed_at, reply_content, replied_at, signed_via
                             FROM live_event_response WHERE live_event_id=" . (int)$eventId)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $resp[(int)$r['user_id']] = $r;
        }
    } catch (Throwable $e) {}
    $readFlag = [];   // 有「已閱」這個事實（不管有沒有留下時間）
    try {
        foreach ($db->query("SELECT user_id, read_at FROM live_event_for_user
                             WHERE live_event_id=" . (int)$eventId . " AND oready_read=1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $u = (int)$r['user_id'];
            $readFlag[$u] = true;
            if (!isset($resp[$u])) $resp[$u] = ['read_at' => $r['read_at'], 'signed_at' => null,
                                                'reply_content' => null, 'replied_at' => null, 'signed_via' => null];
            elseif (empty($resp[$u]['read_at'])) $resp[$u]['read_at'] = $r['read_at'];
        }
    } catch (Throwable $e) {}

    // 列印用補簽
    $fill = [];
    try {
        $st = $db->prepare("SELECT user_id, sign_date, created_by, created_at FROM live_event_contact_sign WHERE live_event_id=?");
        $st->execute([$eventId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $fill[(int)$r['user_id']] = $r;
    } catch (Throwable $e) {}

    // 姓名 / 當時的部門職稱 / 排序鍵
    $snap = [];
    try { $snap = eg_position_snapshot_at_bulk($db, $asof); } catch (Throwable $e) {}
    $deptSort = $posSort = $deptName = $posName = [];
    try {
        foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM department")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $deptSort[(int)$x['id']] = (int)$x['s']; $deptName[(int)$x['id']] = (string)$x['name'];
        }
        foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM position")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $posSort[(int)$x['id']] = (int)$x['s']; $posName[(int)$x['id']] = (string)$x['name'];
        }
    } catch (Throwable $e) {}
    $names = [];
    try {
        $in = implode(',', array_map('intval', $ids));
        foreach ($db->query("SELECT id, user_cname, user_uname FROM user WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $names[(int)$u['id']] = (string)($u['user_cname'] ?: $u['user_uname']);
        }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($ids as $uid) {
        // 挑「以哪個職務出席」：職級最高那一筆（兼任常才是簽核身分，見 ai-rules/22）
        $best = null;
        foreach (($snap[$uid] ?? []) as $sp) {
            $key = [($posSort[(int)$sp['position_id']] ?? 999), -((int)$sp['is_main'])];
            if ($best === null || $key < $best['_k']) { $sp['_k'] = $key; $best = $sp; }
        }
        $dId = (int)($best['department_id'] ?? 0);
        $pId = (int)($best['position_id'] ?? 0);
        // 部門/職稱名稱一律取「目前設定值」，只有該 id 真的被刪掉才退回快照裡的舊名
        // （改名不是改組織——與 eg_people_list_asof() 同一條規則，兩邊不可走鐘）
        $dNm = $deptName[$dId] ?? (string)($best['department_name'] ?? '');
        $pNm = $posName[$pId]  ?? (string)($best['position_name'] ?? '');

        $r  = $resp[$uid] ?? null;
        $fl = $fill[$uid] ?? null;
        $signedAt = $r && !empty($r['signed_at']) ? (string)$r['signed_at'] : '';
        $readAt   = $r && !empty($r['read_at'])   ? (string)$r['read_at']   : '';

        /* 章面日期與「這顆章是怎麼來的」（使用者定調：**蓋章＝已閱**，回簽一定也已閱，所以兩種都蓋章）
           優先序：真的回簽 → 管理員補簽（那是他刻意指定的日期，要蓋得過自動推出來的已閱日）→ 已閱

           read_nodate＝舊資料裡「oready_read=1 但 read_at 是 NULL」的那幾筆（全庫 23 筆）：
           他確實讀過，但系統沒有留下時間。**刻意不替他編一個日期**——章面日期是 AS9100 紀錄的一部分，
           編一個看起來合理的日期比留白更糟；改成標示「已閱（無日期）」並照樣給補簽勾選框，
           由管理員指定一個講得出來的日期。 */
        if ($signedAt !== '')                 { $src = 'sign'; $signDate = substr($signedAt, 0, 10); }
        elseif (!empty($fl['sign_date']))     { $src = 'fill'; $signDate = (string)$fl['sign_date']; }
        elseif ($readAt !== '')               { $src = 'read'; $signDate = substr($readAt, 0, 10); }
        elseif (!empty($readFlag[$uid]))      { $src = 'read_nodate'; $signDate = ''; }
        else                                  { $src = 'none'; $signDate = ''; }

        $out[] = [
            'user_id'    => $uid,
            'name'       => $names[$uid] ?? ('#' . $uid),
            'dept'       => $dNm,
            'position'   => $pNm,
            'mode'       => $modeOf[$uid] ?? 'read',
            'mode_label' => eg_notice_mode_label($modeOf[$uid] ?? 'read'),
            'read_at'    => $readAt ?: null,
            'signed_at'  => $signedAt ?: null,
            'stamp_src'  => $src,   // sign＝真的回簽／read＝已閱／fill＝列印補簽／none＝完全沒動作（不蓋章）
            'signed_via' => $r['signed_via'] ?? null,
            'reply'      => $r['reply_content'] ?? null,
            'replied_at' => $r['replied_at'] ?? null,
            'fill_date'  => $fl['sign_date'] ?? null,
            'fill_by'    => isset($fl['created_by']) ? (int)$fl['created_by'] : null,
            'fill_at'    => $fl['created_at'] ?? null,
            'sign_date'  => $signDate ?: null,
            '_sk'        => [($deptSort[$dId] ?? 999), $dNm, ($posSort[$pId] ?? 999), $pNm, ($names[$uid] ?? '')],
        ];
    }
    usort($out, function ($a, $b) { return $a['_sk'] <=> $b['_sk']; });
    foreach ($out as &$o) unset($o['_sk']);
    unset($o);
    return $out;
}

/* ===================== 補簽 ===================== */

/**
 * 簽章日期的共用檢查（前端擋一次、後端用同一支再擋一次＝鐵律8）。
 * 使用者要求：不得早於公告日期；另外補簽本來就是補「已經發生的事」，故也不接受未來日期。
 */
function nc_sign_date_check(string $date, string $eventDate): array
{
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) return [false, '簽章日期格式不正確'];
    if ($eventDate !== '' && $date < substr($eventDate, 0, 10)) {
        return [false, '簽章日期不可早於公告 / 通知日期（' . eg_fmt_date($eventDate) . '）'];
    }
    if ($date > date('Y-m-d')) return [false, '簽章日期不可以是未來日期'];
    return [true, ''];
}

/** 這個人是不是這則公告的被通知人員（補簽的守門：簽名人員必須是被通知人員） */
function nc_is_recipient(PDO $db, int $eventId, int $uid, string $asof): bool
{
    foreach (nc_people($db, $eventId, $asof) as $p) if ((int)$p['user_id'] === $uid) return true;
    return false;
}

/**
 * 列印用補簽（一般公告）：只寫 live_event_contact_sign，**不動原始回簽紀錄**。
 * @return array [ok, msg]
 */
function nc_sign_fill(PDO $db, int $eventId, int $uid, string $date, int $by): array
{
    nc_ensure_schema($db);
    $st = $db->prepare("SELECT eventdate FROM live_event WHERE id=?");
    $st->execute([$eventId]);
    $ev = $st->fetchColumn();
    if ($ev === false) return [false, '找不到這則公告 / 通知'];

    [$ok, $msg] = nc_sign_date_check($date, (string)$ev);
    if (!$ok) return [false, $msg];
    if (!nc_is_recipient($db, $eventId, $uid, (string)$ev)) return [false, '簽名人員必須是這則通知的被通知人員'];

    $db->prepare("INSERT INTO live_event_contact_sign (live_event_id, user_id, sign_date, created_by, created_at)
                  VALUES (?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE sign_date=VALUES(sign_date), created_by=VALUES(created_by), created_at=NOW()")
       ->execute([$eventId, $uid, $date, ($by ?: null)]);
    nc_audit($db, $by, 'nc_sign_fill', $eventId, $uid, $date, '列印補簽（未寫入回簽紀錄）');
    return [true, ''];
}

/** 取消列印用補簽 */
function nc_sign_fill_del(PDO $db, int $eventId, int $uid, int $by): array
{
    nc_ensure_schema($db);
    $db->prepare("DELETE FROM live_event_contact_sign WHERE live_event_id=? AND user_id=?")->execute([$eventId, $uid]);
    nc_audit($db, $by, 'nc_sign_undo', $eventId, $uid, '', '取消列印補簽');
    return [true, ''];
}

/**
 * 補資料模式的補簽：**這是真的更動原始資料**——直接寫 live_event_response（回簽）與
 * live_event_for_user（鈴鐺已讀），signed_via 記下是哪一位管理員代簽（>0＝某人代按，站上既有語意），
 * 另外寫一筆 audit_log 留下「什麼時候、由哪一位管理員補的」（使用者明確要求）。
 *
 * 時間戳依 ai-rules/21：同一天多個人時接在前一個之後隨機錯開 5~180 分、不跨日，
 * 才不會出現一整批人「同一秒一起回簽」這種一看就知道是機器寫的紀錄。
 */
function nc_sign_real(PDO $db, int $eventId, int $uid, string $date, int $by): array
{
    nc_ensure_schema($db);
    $st = $db->prepare("SELECT eventdate FROM live_event WHERE id=?");
    $st->execute([$eventId]);
    $ev = $st->fetchColumn();
    if ($ev === false) return [false, '找不到這則公告 / 通知'];

    [$ok, $msg] = nc_sign_date_check($date, (string)$ev);
    if (!$ok) return [false, $msg];
    if (!nc_is_recipient($db, $eventId, $uid, (string)$ev)) return [false, '簽名人員必須是這則通知的被通知人員'];

    $at = nc_backfill_time($db, $eventId, $date);

    $q = $db->prepare("SELECT id, read_at, signed_at FROM live_event_response WHERE live_event_id=? AND user_id=?");
    $q->execute([$eventId, $uid]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at, signed_via) VALUES (?,?,?,?,?)")
           ->execute([$eventId, $uid, $at, $at, ($by ?: null)]);
    } else {
        if (!empty($row['signed_at'])) return [false, '這個人已經回簽過了，不需要補簽'];
        $db->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,?), signed_at=?, signed_via=COALESCE(signed_via,?) WHERE id=?")
           ->execute([$at, $at, ($by ?: null), (int)$row['id']]);
    }
    // 鈴鐺未讀是看 live_event_for_user，只寫 response 的話通知不會消失
    $c = $db->prepare("SELECT id FROM live_event_for_user WHERE user_id=? AND live_event_id=? LIMIT 1");
    $c->execute([$uid, $eventId]);
    $fid = $c->fetchColumn();
    if ($fid) {
        $db->prepare("UPDATE live_event_for_user SET oready_read=1, read_at=COALESCE(read_at,?), signed_via=COALESCE(signed_via,?) WHERE id=?")
           ->execute([$at, ($by ?: null), $fid]);
    } else {
        $db->prepare("INSERT INTO live_event_for_user (user_id, live_event_id, oready_read, read_at, signed_via) VALUES (?,?,1,?,?)")
           ->execute([$uid, $eventId, $at, ($by ?: null)]);
    }
    nc_audit($db, $by, 'nc_sign_real', $eventId, $uid, $at, '補資料補簽（已寫入真實回簽紀錄）');
    return [true, '', $at];
}

/** 補簽時間戳：同一天已經有人簽過就接在最後一位之後錯開 5~180 分，不跨日（ai-rules/21） */
function nc_backfill_time(PDO $db, int $eventId, string $date): string
{
    $base = strtotime($date . ' 09:00:00');
    try {
        $st = $db->prepare("SELECT MAX(signed_at) FROM live_event_response WHERE live_event_id=? AND DATE(signed_at)=?");
        $st->execute([$eventId, $date]);
        $last = (string)$st->fetchColumn();
        if ($last !== '') { $t = strtotime($last); if ($t > $base) $base = $t; }
    } catch (Throwable $e) {}
    $ts  = $base + random_int(5, 180) * 60;
    $end = strtotime($date . ' 23:55:00');
    return date('Y-m-d H:i:s', min($ts, $end));
}

/**
 * 稽核紀錄（補簽一律留痕：誰、什麼時候、對哪一則的哪一個人做了什麼）。
 *
 * ⚠ `audit_log.action_type` 只有 **VARCHAR(20)**：動作代碼取太長會被 MySQL 以嚴格模式擋下丟例外，
 * 而這支是 try/catch 吞掉例外的（稽核寫不進去不該擋住使用者），於是**紀錄默默不見、畫面上完全看不出來**。
 * 2026-09-22 實測就踩到：`contact_sign_backfill`(21) 與 `contact_backfill_create`(23) 兩個代碼整批寫不進去。
 * 代碼一律 ≤20 字元，並在下方再 substr 一次當保險。
 */
function nc_audit(PDO $db, int $by, string $action, int $eventId, int $uid, string $when, string $note): void
{
    try {
        $op = '';
        if ($by > 0) {
            $s = $db->prepare("SELECT user_cname FROM user WHERE id=?");
            $s->execute([$by]);
            $op = (string)$s->fetchColumn();
        }
        $target = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $target->execute([$uid]);
        $tn = (string)$target->fetchColumn();
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES (?,?,?,?,?,?,?,NOW())")
           ->execute([mb_substr($action, 0, 20), 'notice_contact', (string)$eventId, mb_substr($tn, 0, 200),
                      json_encode(['event_id' => $eventId, 'user_id' => $uid, 'sign_at' => $when, 'note' => $note], JSON_UNESCAPED_UNICODE),
                      ($by ?: null), mb_substr($op, 0, 100)]);
    } catch (Throwable $e) { error_log('[notice_contact] audit: ' . $e->getMessage()); }
}

/* ===================== 補資料（建立不發通知的聯絡單） ===================== */

/**
 * 補登一張歷史聯絡單：建立 live_event 與對象，但**完全不發任何通知**
 * （不呼叫 eg_push_for_event / eg_telegram_for_event，也不會出現在鈴鐺以外的推播管道）。
 * 使用者要求：管理員可直接指定發文者與受文者，受文者含「全體員工」。
 *
 * created_by 寫「發文者」＝紙上印的那個人（歷史資料本來就該是他發的）；
 * 真正動手補的管理員記在 live_event_contact.backfill_by / backfill_at 與 audit_log。
 *
 * @param array $in eventdate/title/content/from_user_id/targets[]/mode/source
 * @return array [ok, msg, event_id]
 */
function nc_backfill_create(PDO $db, array $in, int $by): array
{
    require_once __DIR__ . '/notice_event_lib.php';
    require_once __DIR__ . '/notice_files.php';
    nc_ensure_schema($db);

    $date  = trim((string)($in['eventdate'] ?? ''));
    $title = trim((string)($in['title'] ?? ''));
    $body  = trim((string)($in['content'] ?? ''));
    $from  = (int)($in['from_user_id'] ?? 0);
    $tgts  = is_array($in['targets'] ?? null) ? array_values(array_filter(array_map('strval', $in['targets']))) : [];
    $mode  = eg_notice_mode_valid((string)($in['mode'] ?? 'sign'));

    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) return [false, '請填寫正確的聯絡單日期', 0];
    if ($date > date('Y-m-d')) return [false, '補資料的日期不可以是未來日期', 0];
    if ($title === '') return [false, '請填寫標題', 0];
    if ($body === '')  return [false, '請填寫內容', 0];
    if ($from <= 0)    return [false, '請選擇發文者', 0];
    if (!$tgts)        return [false, '請選擇受文者', 0];

    $chk = $db->prepare("SELECT COUNT(*) FROM user WHERE id=?");
    $chk->execute([$from]);
    if (!(int)$chk->fetchColumn()) return [false, '發文者不存在', 0];

    $source = trim((string)($in['source'] ?? ''));
    if ($source === '') $source = eg_user_main_dept($db, $from);

    $own = !$db->inTransaction();
    try {
        if ($own) $db->beginTransaction();
        $db->prepare("INSERT INTO live_event (eventdate, title, content, status, created_by, source, show_status_to_others)
                      VALUES (?,?,?,0,?,?,0)")
           ->execute([$date, mb_substr($title, 0, 100), $body, $from, mb_substr($source, 0, 50)]);
        $eid = (int)$db->lastInsertId();

        // 公告編號（PU）照樣配號：附件資料夾與全站查找都靠它，補登的單也要有
        $db->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([eg_gen_event_no($db, $date), $eid]);

        // 對象一律走唯一實作，通知方式整批套同一種
        $modes = [];
        foreach ($tgts as $t) $modes[$t] = $mode;
        $primary = eg_save_event_targets($db, $eid, $tgts, $modes);
        $db->prepare("UPDATE live_event SET status=? WHERE id=?")->execute([$primary, $eid]);

        eg_log_event_history($db, $eid, 'create', $by, null, eg_event_snapshot($db, $eid));
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        error_log('[notice_contact] backfill_create: ' . $e->getMessage());
        return [false, '建立失敗：' . $e->getMessage(), 0];
    }

    // 標記成補資料（交易外做，nc_cfg_save 內含 ensure_schema 的 DDL 判斷）
    nc_cfg_save($db, $eid, ['is_backfill' => 1, 'backfill_by' => $by, 'backfill_at' => date('Y-m-d H:i:s')], $by);
    nc_ensure_contact_no($db, $eid);
    nc_audit($db, $by, 'nc_backfill', $eid, $from, $date, '補資料建立聯絡單（未發送任何通知）');
    return [true, '', $eid];
}

/* ===================== 列印資料 ===================== */

/** 依設定推導製表人 / 核准的預設人員（禁寫死人名；核准預設取組織角色綁定的最高核准人員） */
function nc_default_signers(PDO $db, array $ev): array
{
    $s = nc_settings($db);
    $maker = ($s['maker_src'] === 'user') ? (int)$s['maker_user_id'] : (int)($ev['created_by'] ?? 0);

    $appr = 0;
    if ($s['approver_src'] === 'user') {
        $appr = (int)$s['approver_user_id'];
    } elseif ($s['approver_src'] === 'top') {
        try {
            require_once __DIR__ . '/org_role_lib.php';
            $u = eg_org_user($db, 'top_approver');
            $appr = (int)($u['id'] ?? 0);
        } catch (Throwable $e) { $appr = 0; }
    }
    return ['maker' => $maker, 'approver' => $appr];
}

/** 一個人的顯示資料（姓名＋當時的部門職稱），給圖章用 */
function nc_person(PDO $db, int $uid, string $asof): array
{
    if ($uid <= 0) return ['id' => 0, 'name' => '', 'dept' => '', 'position' => ''];
    $name = '';
    try {
        $st = $db->prepare("SELECT user_cname, user_uname FROM user WHERE id=?");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $name = $r ? (string)($r['user_cname'] ?: $r['user_uname']) : '';
    } catch (Throwable $e) {}
    $dept = $pos = '';
    try {
        $snap = eg_position_snapshot_at($db, $uid, $asof);
        $best = null; $bestKey = null;
        $posSort = [];
        foreach ($db->query("SELECT id, COALESCE(sort_order,999) s FROM position")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $posSort[(int)$x['id']] = (int)$x['s'];
        }
        foreach ($snap as $sp) {
            $k = [($posSort[(int)$sp['position_id']] ?? 999), -((int)$sp['is_main'])];
            if ($bestKey === null || $k < $bestKey) { $bestKey = $k; $best = $sp; }
        }
        if ($best) {
            $d = $db->prepare("SELECT name FROM department WHERE id=?"); $d->execute([(int)$best['department_id']]);
            $dept = (string)($d->fetchColumn() ?: ($best['department_name'] ?? ''));
            $p = $db->prepare("SELECT name FROM position WHERE id=?"); $p->execute([(int)$best['position_id']]);
            $pos = (string)($p->fetchColumn() ?: ($best['position_name'] ?? ''));
        }
    } catch (Throwable $e) {}
    return ['id' => $uid, 'name' => $name, 'dept' => $dept, 'position' => $pos];
}

/**
 * 組出整張聯絡單要印的資料。
 * @param bool $alloc true＝真的要列印（此時才配聯絡單號；預覽設定時傳 false 不佔號）
 */
function nc_print_data(PDO $db, int $eventId, bool $alloc): array
{
    nc_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM live_event WHERE id=?");
    $st->execute([$eventId]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ev) return ['ok' => false, 'msg' => '找不到這則公告 / 通知'];

    $asof  = substr((string)$ev['eventdate'], 0, 10);
    $cfg   = nc_cfg($db, $eventId);
    $def   = nc_default_signers($db, $ev);
    $docId = eg_asdoc_id($db, NC_ASDOC_MODULE);
    $doc   = eg_asdoc_get($db, NC_ASDOC_MODULE);

    $makerId = (int)($cfg['maker_user_id'] ?: $def['maker']);
    $apprId  = (int)($cfg['approver_user_id'] ?: $def['approver']);

    $files = [];
    try {
        $f = $db->prepare("SELECT file_name FROM live_event_file WHERE live_event_id=? ORDER BY id");
        $f->execute([$eventId]);
        $files = $f->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}

    $contactNo = trim((string)$ev['contact_no']);
    if ($alloc && $contactNo === '') $contactNo = nc_ensure_contact_no($db, $eventId);

    return [
        'ok'         => true,
        'event_id'   => $eventId,
        'contact_no' => $contactNo,
        'event_no'   => (string)$ev['event_no'],
        'eventdate'  => $asof,
        'date_disp'  => eg_fmt_date($asof),
        'title'      => (string)$ev['title'],
        'content'    => (string)$ev['content'],
        'source'     => (string)$ev['source'],
        'files'      => $files,
        /* 發文者：①管理員在列印設定填的 → ②公告建立者（部門 職稱 姓名）→ ③來源（多半就是發文的部門名稱）
           →④留白給紙本手寫。**舊公告有 274 則的 created_by 是空的**（早期資料沒記建立者），
           沒有第③段的話那幾張印出來發文者會是一片空白，看起來像程式壞了。 */
        'from_text'  => $cfg['from_text'] !== null && $cfg['from_text'] !== ''
                        ? (string)$cfg['from_text']
                        : (nc_person_label(nc_person($db, (int)$ev['created_by'], $asof)) ?: trim((string)$ev['source'])),
        'to_text'    => $cfg['to_text'] !== null && $cfg['to_text'] !== ''
                        ? (string)$cfg['to_text'] : nc_targets_text($db, $eventId),
        'maker'      => nc_person($db, $makerId, $asof) + ['date' => (string)($cfg['maker_date'] ?: $asof)],
        'approver'   => nc_person($db, $apprId, $asof) + ['date' => (string)($cfg['approver_date'] ?: $asof)],
        'people'     => nc_people($db, $eventId, $asof),
        'include'    => $cfg['include_uids'],
        'show_reply' => (int)$cfg['show_reply'],
        'is_backfill'=> (int)$cfg['is_backfill'],
        'company'    => nc_company($db),
        'doc_name'   => $doc['doc_name'] ?? '聯絡單',
        'doc_no'     => $docId ? eg_asdoc_no_asof_id($db, $docId, $asof) : '',
        'stamp_tpl'  => nc_stamp_template($db),            // 製表人 / 核准
        'stamp_tpl_read' => nc_stamp_template($db, 'read'), // 已閱簽章
    ];
}

/** 「部門 職稱 姓名」的顯示字串（發文者欄位預設值用） */
function nc_person_label(array $p): string
{
    $s = trim(trim((string)$p['dept'] . ' ' . (string)$p['position']) . ' ' . (string)$p['name']);
    return $s !== '' ? $s : (string)$p['name'];
}
}

