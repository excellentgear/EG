<?php
/**
 * 公告／通知／聯絡單：種類、圖文內容、草稿、張貼期限 —— 唯一實作（2026-10-22）
 *
 * 設計重點（使用者明確要求「嚴禁影響現有使用者」的精神）：
 *  ① live_event.content 仍然是**純文字**（推播、Telegram、鈴鐺、列表一律照舊讀它），
 *    圖文版另存 content_html；存檔時由後端用 eg_richtext_to_text() 重算 content，不採信前端。
 *  ② 草稿**不放進 live_event**：全站有 80 多處直接讀 live_event，草稿混進去就得逐處過濾，
 *    漏一處就會有人收到沒發布的公告。草稿獨立存在 live_event_draft（整張表單的 JSON），
 *    發布時才轉成正式的 live_event，草稿隨即刪除。
 *  ③ 圖片只存資產編號（data-asset），src 由顯示時組回（richtext_lib 的 doc profile 規則）。
 *  ④ 種類 kind：notice 通知／announce 公告／contact 聯絡單。**只有聯絡單綁 AS 文件編號**。
 */
if (!defined('EG_NOTICE_DOC_LIB')) {
define('EG_NOTICE_DOC_LIB', 1);

require_once __DIR__ . '/richtext_lib.php';
require_once __DIR__ . '/attach_lib.php';

define('NDOC_ASSET_API', '/EGsystem/src/store/NoticeDoc_API.php');

/** 種類唯一登記處：code => 顯示名稱（列印大標題也用它） */
function ndoc_kinds(): array
{
    return ['notice' => '通知', 'announce' => '公告', 'contact' => '聯絡單'];
}
function ndoc_kind_norm($k): string
{
    $k = (string)$k;
    return isset(ndoc_kinds()[$k]) ? $k : 'notice';
}

/** 建表／補欄位（可重複執行；DDL 一律不在交易內做） */
function ndoc_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ($db->inTransaction()) { $done = false; return; }
        $hasCol = function (string $t, string $c) use ($db): bool {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $st->execute([$t, $c]);
            return (int)$st->fetchColumn() > 0;
        };
        $hasTb = function (string $t) use ($db): bool {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
            $st->execute([$t]);
            return (int)$st->fetchColumn() > 0;
        };
        if (!$hasCol('live_event', 'kind')) {
            $db->exec("ALTER TABLE live_event ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'notice' COMMENT 'notice通知/announce公告/contact聯絡單'");
            // 已經配過聯絡單號的舊資料＝當年就是當聯絡單印的
            $db->exec("UPDATE live_event SET kind='contact' WHERE contact_no IS NOT NULL AND contact_no<>''");
        }
        if (!$hasCol('live_event', 'content_html')) {
            $db->exec("ALTER TABLE live_event ADD COLUMN content_html MEDIUMTEXT NULL COMMENT '圖文內容（已清洗）；content 仍存純文字供推播／列表使用'");
        }
        if (!$hasCol('live_event', 'post_until')) {
            $db->exec("ALTER TABLE live_event ADD COLUMN post_until DATE NULL COMMENT '張貼期限（公告列印在右下角）'");
        }
        if (!$hasTb('live_event_asset')) {
            $db->exec("CREATE TABLE live_event_asset (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                event_id INT NULL COMMENT '對應 live_event.id；NULL＝還沒存檔的暫存圖',
                draft_id INT NULL COMMENT '對應 live_event_draft.id（草稿用）',
                file_name VARCHAR(120) NOT NULL COMMENT '只存檔名，目錄即時組（鐵律5）',
                orig_name VARCHAR(200) NULL,
                mime VARCHAR(40) NOT NULL,
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_lea_ev (event_id), KEY idx_lea_dr (draft_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告內文裡的圖片'");
        }
        if (!$hasTb('live_event_approval')) {
            $db->exec("CREATE TABLE live_event_approval (
                event_id INT NOT NULL PRIMARY KEY COMMENT '對應 live_event.id',
                approver_id INT NOT NULL COMMENT '核准人（印在章上的那一位）',
                approved_date DATE NOT NULL COMMENT '核准日期（業務日期，印在章面）',
                is_auto TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1＝依設定自動簽核准章',
                decided_by INT NULL COMMENT '實際按下核准的人（自動簽核為 NULL）',
                decided_at DATETIME NOT NULL,
                note VARCHAR(300) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告/通知/聯絡單的核准紀錄（列印簽章用）'");
        }
        if (!$hasTb('live_event_draft')) {
            $db->exec("CREATE TABLE live_event_draft (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL COMMENT '草稿擁有者（只有本人看得到）',
                kind VARCHAR(10) NOT NULL DEFAULT 'notice',
                title VARCHAR(100) NOT NULL DEFAULT '',
                payload MEDIUMTEXT NOT NULL COMMENT '整張表單的 JSON',
                updated_at DATETIME NOT NULL,
                KEY idx_led_user (user_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告/通知/聯絡單草稿（不進 live_event，不會被任何人收到）'");
        }
        // 草稿表補「待核准」欄位（草稿與送審中的單同一張表：state＝draft／pending／rejected）
        foreach (['state' => "VARCHAR(10) NOT NULL DEFAULT 'draft'", 'approver_id' => 'INT NULL',
                  'submitted_at' => 'DATETIME NULL', 'decided_at' => 'DATETIME NULL',
                  'reject_reason' => 'VARCHAR(300) NULL'] as $c => $def) {
            if (!$hasCol('live_event_draft', $c)) $db->exec("ALTER TABLE live_event_draft ADD COLUMN $c $def");
        }
    } catch (Throwable $e) {
        error_log('[notice_doc] ensure_schema: ' . $e->getMessage());
        $done = false;
    }
}

/* ===================== 內容清洗 ===================== */

/**
 * 前端送來的圖文 HTML → 可存的內容＋純文字。
 * 回傳 [html, text]；沒有任何內容時 html=''。
 * 純文字上限 2000 字（與原本 textarea 的 maxlength 一致）。
 */
function ndoc_clean_content(string $html): array
{
    $h = eg_richtext_sanitize($html, 60000, 'doc');
    $t = $h === '' ? '' : trim(eg_richtext_to_text($h));
    if ($t === '' && $h !== '') $t = '（圖文內容，請開啟檢視畫面）';
    if (mb_strlen($t, 'UTF-8') > 2000) $t = mb_substr($t, 0, 2000, 'UTF-8');
    return [$h, $t];
}

/** 內文裡引用的資產編號 */
function ndoc_html_assets(string $html): array
{
    if (!preg_match_all('/data-asset="(\d{1,10})"/', $html, $m)) return [];
    return array_values(array_unique(array_map('intval', $m[1])));
}

/**
 * 把內文用到的圖片認領到這則公告（或草稿）。
 * 只認「自己上傳、還沒被別處認領」或「本來就屬於這個對象」的圖；來路不明的 <img> 整個移除，
 * 否則有人可以在內文塞別人公告的圖片編號偷看（鐵律8）。
 */
function ndoc_bind_assets(PDO $db, string $html, int $uid, ?int $eventId, ?int $draftId): string
{
    foreach (ndoc_html_assets($html) as $aid) {
        $st = $db->prepare("SELECT event_id, draft_id, created_by FROM live_event_asset WHERE id=?");
        $st->execute([$aid]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        $ok = false;
        if ($a) {
            $unbound = ($a['event_id'] === null && $a['draft_id'] === null);
            $mine    = ((int)$a['created_by'] === $uid);
            if ($eventId && ((int)$a['event_id'] === $eventId || $unbound && $mine || ($a['draft_id'] !== null && $mine))) {
                $db->prepare("UPDATE live_event_asset SET event_id=?, draft_id=NULL WHERE id=?")->execute([$eventId, $aid]);
                $ok = true;
            } elseif ($draftId && ((int)$a['draft_id'] === $draftId || $unbound && $mine)) {
                $db->prepare("UPDATE live_event_asset SET draft_id=? WHERE id=?")->execute([$draftId, $aid]);
                $ok = true;
            }
        }
        if (!$ok) $html = preg_replace('/<img\b[^>]*data-asset="' . $aid . '"[^>]*>/i', '', $html);
    }
    return $html;
}

/** 顯示用：把 <img data-asset="N"> 補上 src（DB 不存 src） */
function ndoc_hydrate(string $html, string $base = NDOC_ASSET_API): string
{
    return preg_replace_callback('/<img\b([^>]*?)data-asset="(\d{1,10})"([^>]*)>/i', function ($m) use ($base) {
        return '<img' . $m[1] . 'data-asset="' . $m[2] . '"' . $m[3] . ' src="' . $base . '?action=asset&amp;id=' . $m[2] . '">';
    }, $html);
}

/* ===================== 圖片檔 ===================== */

function ndoc_asset_dir(PDO $db): string
{
    $dir = eg_attach_dir($db, 'notice_asset_dir', '公告通知圖片');
    eg_attach_ensure_dir($dir);
    return $dir;
}

/** 存一張圖；回傳 [id] 或 ['error'=>…] */
function ndoc_asset_store(PDO $db, int $uid, string $bytes, string $origName, ?int $draftId = null): array
{
    if ($bytes === '' || strlen($bytes) > 8 * 1024 * 1024) return ['error' => '圖片不可超過 8MB'];
    $info = @getimagesizefromstring($bytes);
    $map = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_GIF => ['gif', 'image/gif']];
    if (!$info || !isset($map[$info[2]])) return ['error' => '只接受 JPG / PNG / GIF 圖片'];
    [$ext, $mime] = $map[$info[2]];
    $now = $db->query("SELECT NOW()")->fetchColumn();
    $db->prepare("INSERT INTO live_event_asset (draft_id,file_name,orig_name,mime,created_by,created_at) VALUES (?,?,?,?,?,?)")
       ->execute([$draftId, 'pending', mb_substr($origName, 0, 200, 'UTF-8'), $mime, $uid, $now]);
    $id = (int)$db->lastInsertId();
    $fn = 'n' . $id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = ndoc_asset_dir($db) . $fn;
    if (@file_put_contents($path, $bytes) === false) {
        $db->prepare("DELETE FROM live_event_asset WHERE id=?")->execute([$id]);
        return ['error' => '圖片存檔失敗（儲存位置無法寫入）'];
    }
    $db->prepare("UPDATE live_event_asset SET file_name=? WHERE id=?")->execute([$fn, $id]);
    return ['id' => $id];
}

function ndoc_asset_get(PDO $db, int $id): ?array
{
    $st = $db->prepare("SELECT * FROM live_event_asset WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['path'] = ndoc_asset_dir($db) . basename((string)$r['file_name']);
    return $r;
}

/** 順路清掉兩天前上傳卻始終沒被任何公告／草稿認領的暫存圖 */
function ndoc_asset_gc(PDO $db): void
{
    try {
        $st = $db->query("SELECT id, file_name FROM live_event_asset WHERE event_id IS NULL AND draft_id IS NULL AND created_at < (NOW() - INTERVAL 2 DAY) LIMIT 50");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = ndoc_asset_dir($db) . basename((string)$r['file_name']);
            if (is_file($p)) @unlink($p);
            $db->prepare("DELETE FROM live_event_asset WHERE id=?")->execute([$r['id']]);
        }
    } catch (Throwable $e) {}
}

/* ===================== 草稿 ===================== */

function ndoc_draft_list(PDO $db, int $uid): array
{
    $st = $db->prepare("SELECT id, kind, title, state, updated_at FROM live_event_draft WHERE user_id=? ORDER BY updated_at DESC LIMIT 50");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ndoc_draft_get(PDO $db, int $uid, int $id): ?array
{
    $st = $db->prepare("SELECT * FROM live_event_draft WHERE id=? AND user_id=?");
    $st->execute([$id, $uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['data'] = json_decode((string)$r['payload'], true) ?: [];
    return $r;
}

/**
 * 存草稿（新增或更新）。$in 是表單欄位；內容一律後端清洗。草稿不驗必填（存草稿本來就是還沒填完）。
 * @return array{id:int}|array{error:string}
 */
function ndoc_draft_save(PDO $db, int $uid, int $draftId, array $in): array
{
    $kind = ndoc_kind_norm($in['kind'] ?? 'notice');
    [$html] = ndoc_clean_content((string)($in['content_html'] ?? ''));
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '' && $html === '') return ['error' => '標題與內容都是空的，沒有東西可存'];
    $date = function ($v) { $v = (string)$v; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; };
    $data = [
        'kind' => $kind,
        'eventdate' => $date($in['eventdate'] ?? ''),
        'enddate' => $date($in['enddate'] ?? ''),
        'reply_deadline' => $date($in['reply_deadline'] ?? ''),
        'post_until' => $date($in['post_until'] ?? ''),
        'show_status_to_others' => !empty($in['show_status_to_others']) ? 1 : 0,
        'title' => mb_substr($title, 0, 100, 'UTF-8'),
        'targets' => array_values(array_filter(array_map('strval', (array)($in['targets'] ?? [])), function ($s) { return preg_match('/^(all|(dept|status|user)-\d+)$/', $s); })),
        'target_modes' => (object)(json_decode((string)($in['target_modes'] ?? '{}'), true) ?: []),
        'co_editors' => (string)($in['co_editors'] ?? ''),
    ];
    // 越南文翻譯（選填）：跟著草稿／送審一起存，核准發布時一併寫入
    if (array_key_exists('vi_html', $in) || array_key_exists('vi_title', $in)) {
        [$viH] = ndoc_clean_content((string)($in['vi_html'] ?? ''));
        $data['vi_title'] = mb_substr(trim((string)($in['vi_title'] ?? '')), 0, 255, 'UTF-8');
        $data['vi_html'] = $viH;
    }
    $now = $db->query("SELECT NOW()")->fetchColumn();
    if ($draftId > 0) {
        if (!ndoc_draft_get($db, $uid, $draftId)) return ['error' => '找不到這份草稿'];
    } else {
        $db->prepare("INSERT INTO live_event_draft (user_id,kind,title,payload,updated_at) VALUES (?,?,?,?,?)")
           ->execute([$uid, $kind, $data['title'], '{}', $now]);
        $draftId = (int)$db->lastInsertId();
    }
    $data['content_html'] = ndoc_bind_assets($db, $html, $uid, null, $draftId);
    $db->prepare("UPDATE live_event_draft SET kind=?, title=?, payload=?, updated_at=? WHERE id=? AND user_id=?")
       ->execute([$kind, $data['title'], json_encode($data, JSON_UNESCAPED_UNICODE), $now, $draftId, $uid]);
    return ['id' => $draftId];
}

/**
 * 正式新增／修改公告後，把「種類、圖文內容、張貼期限」寫進去（只動這三欄，原本的流程一字不改）。
 * 並認領內文圖片；若這是由草稿發布的，順手刪掉草稿。
 */
function ndoc_apply_post(PDO $db, int $eventId, int $uid, array $in, bool $isNew): void
{
    $kind = ndoc_kind_norm($in['kind'] ?? 'notice');
    [$html] = ndoc_clean_content((string)($in['content_html'] ?? ''));
    $html = ndoc_bind_assets($db, $html, $uid, $eventId, null);
    $pu = (string)($in['post_until'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pu) || $kind !== 'announce') $pu = '';
    $set = "kind=?, content_html=?, post_until=?";
    $db->prepare("UPDATE live_event SET $set WHERE id=?")->execute([$kind, $html === '' ? null : $html, $pu === '' ? null : $pu, $eventId]);
    // 越南文翻譯：表單有送這兩個欄位才處理（沒送＝不動既有翻譯）
    if (array_key_exists('vi_html', $in) || array_key_exists('vi_title', $in)) {
        try {
            require_once __DIR__ . '/notice_i18n_lib.php';
            ni_save($db, $eventId, 'vi', (string)($in['vi_title'] ?? ''), (string)($in['vi_html'] ?? ''), $uid);
        } catch (Throwable $e) { error_log('[notice] save translation failed: ' . $e->getMessage()); }
    }
    if ($isNew && !empty($in['draft_id'])) ndoc_draft_delete($db, $uid, (int)$in['draft_id']);
}

/** 刪草稿（發布成功後呼叫、或使用者自己刪）；圖片若已被正式公告認領就不動 */
function ndoc_draft_delete(PDO $db, int $uid, int $id): void
{
    if (!ndoc_draft_get($db, $uid, $id)) return;
    $st = $db->prepare("SELECT id, file_name FROM live_event_asset WHERE draft_id=? AND event_id IS NULL");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = ndoc_asset_dir($db) . basename((string)$r['file_name']);
        if (is_file($p)) @unlink($p);
        $db->prepare("DELETE FROM live_event_asset WHERE id=?")->execute([$r['id']]);
    }
    $db->prepare("DELETE FROM live_event_draft WHERE id=? AND user_id=?")->execute([$id, $uid]);
}

/* ===================== 核准設定 ===================== */

define('NDOC_APPR_GROUP', 'NOTICE_APPROVAL');

/** 預設：三種都「自動簽核准章」＝跟改版前一樣直接發布；超級管理員可逐種改成「需核准」 */
function ndoc_appr_defaults(): array
{
    return ['mode_notice' => 'auto', 'mode_announce' => 'auto', 'mode_contact' => 'auto', 'approver_user_id' => ''];
}

/** ⚠ system_parameters.param_value 是 JSON 欄位，讀寫都要 json_encode/decode（同 notice_contact_lib 的說明） */
function ndoc_appr_settings(PDO $db): array
{
    $out = ndoc_appr_defaults();
    try {
        $st = $db->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group=?");
        $st->execute([NDOC_APPR_GROUP]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!array_key_exists($r['param_key'], $out)) continue;
            $dec = json_decode((string)$r['param_value'], true);
            $out[$r['param_key']] = is_scalar($dec) ? (string)$dec : '';
        }
    } catch (Throwable $e) {}
    foreach (['mode_notice', 'mode_announce', 'mode_contact'] as $k) {
        if (!in_array($out[$k], ['auto', 'required'], true)) $out[$k] = 'auto';
    }
    return $out;
}

/** 回傳錯誤訊息或 ''；後端驗證（鐵律8） */
function ndoc_appr_save(PDO $db, array $in, string $by): string
{
    $set = [];
    foreach (['mode_notice', 'mode_announce', 'mode_contact'] as $k) {
        if (!array_key_exists($k, $in)) continue;
        if (!in_array($in[$k], ['auto', 'required'], true)) return '核准方式只能是「自動簽核」或「需核准」';
        $set[$k] = $in[$k];
    }
    if (array_key_exists('approver_user_id', $in)) {
        $u = (int)$in['approver_user_id'];
        if ($u > 0) {
            $st = $db->prepare("SELECT COUNT(*) FROM user WHERE id=? AND state NOT IN (0,90)");
            $st->execute([$u]);
            if (!(int)$st->fetchColumn()) return '核准人員必須是在職人員';
        }
        $set['approver_user_id'] = $u > 0 ? (string)$u : '';
    }
    $cur = ndoc_appr_settings($db);
    $anyReq = false;
    foreach (['mode_notice', 'mode_announce', 'mode_contact'] as $k) if (($set[$k] ?? $cur[$k]) === 'required') $anyReq = true;
    if ($anyReq && ndoc_appr_approver($db, $set['approver_user_id'] ?? $cur['approver_user_id']) <= 0) {
        return '有種類設成「需核准」時必須有核准人員（請指定人員，或先到組織角色綁定設定最高核准人員）';
    }
    foreach ($set as $k => $v) {
        $j = json_encode($v, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([NDOC_APPR_GROUP, $k]);
        $id = $st->fetchColumn();
        if ($id) $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$j, $by, $id]);
        else $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by) VALUES (?,?,?,?,?)")
                ->execute([NDOC_APPR_GROUP, $k, $j, '公告/通知/聯絡單核准設定', $by]);
    }
    return '';
}

/** 核准人員（三種表單相同）：設定指定的人；沒指定就取組織角色綁定的最高核准人員，禁寫死人名 */
function ndoc_appr_approver(PDO $db, ?string $override = null): int
{
    $v = $override !== null ? $override : ndoc_appr_settings($db)['approver_user_id'];
    if ((int)$v > 0) return (int)$v;
    try {
        require_once __DIR__ . '/org_role_lib.php';
        $u = eg_org_user($db, 'top_approver');
        return (int)($u['id'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

function ndoc_appr_mode(PDO $db, string $kind): string
{
    return ndoc_appr_settings($db)['mode_' . ndoc_kind_norm($kind)] ?? 'auto';
}

/** 寫核准紀錄（列印簽章用）；同一則只留最新一筆 */
function ndoc_appr_record(PDO $db, int $eventId, int $approverId, string $date, bool $auto, ?int $decidedBy, string $note = ''): void
{
    $now = $db->query("SELECT NOW()")->fetchColumn();
    $db->prepare("REPLACE INTO live_event_approval (event_id,approver_id,approved_date,is_auto,decided_by,decided_at,note) VALUES (?,?,?,?,?,?,?)")
       ->execute([$eventId, $approverId, $date, $auto ? 1 : 0, $decidedBy, $now, $note === '' ? null : mb_substr($note, 0, 300, 'UTF-8')]);
}

function ndoc_appr_get(PDO $db, int $eventId): ?array
{
    $st = $db->prepare("SELECT * FROM live_event_approval WHERE event_id=?");
    $st->execute([$eventId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * 已通過簽核（有核准紀錄＝自動簽核或核准人核准）的公告／通知／聯絡單不可再編輯與刪除，
 * 只有全站管理員（features 含 'all'）例外；本頁管理員也不例外。翻譯版本不受此限（另有權限）。
 */
function ndoc_is_locked(PDO $db, int $eventId): bool
{
    try { ndoc_ensure_schema($db); } catch (Throwable $e) {}
    try {
        $st = $db->prepare("SELECT 1 FROM live_event_approval WHERE event_id=? LIMIT 1");
        $st->execute([$eventId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function ndoc_locked_ids(PDO $db, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    try {
        $in = implode(',', $ids);
        return array_map('intval', $db->query("SELECT event_id FROM live_event_approval WHERE event_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}
if (!defined('NDOC_LOCK_MSG')) define('NDOC_LOCK_MSG', '此公告／通知已通過簽核，不可編輯或刪除（僅全站管理員可處理）；如需補越南文請使用「翻譯」。');

/* ===================== 正式發布（唯一實作） ===================== */

/**
 * 新增一則公告／通知／聯絡單並發出推播。
 * 原本寫在 _setting.php 的 newEvent 分支，抽成函式是因為「需核准」的單要等核准後才發布，
 * 兩條路（直接發布／核准後發布）必須走同一份程式（鐵律4）。
 * @param array      $post  表單欄位（＝原本的 $_POST）
 * @param array|null $files 原生 $_FILES['notice_files']（核准後發布沒有，傳 null）
 * @param array|null $appr  核准紀錄 ['approver'=>id,'date'=>Y-m-d,'auto'=>bool,'by'=>?id]；null＝不寫
 * @return int 新公告 id
 */
function ndoc_publish_new(PDO $db, array $post, int $uid, ?array $files, ?array $appr): int
{
    require_once __DIR__ . '/notice_event_lib.php';
    require_once __DIR__ . '/notice_files.php';
    $enddate = !empty($post['enddate']) ? $post['enddate'] : null;
    $replyDeadline = !empty($post['reply_deadline']) ? $post['reply_deadline'] : null;
    $showStatus = !empty($post['show_status_to_others']) ? 1 : 0;
    $modes = $post['target_modes'] ?? [];
    if (is_string($modes)) $modes = json_decode($modes, true) ?: [];
    $modes = (array)$modes;
    $source = !empty($post['source']) ? trim($post['source']) : eg_user_main_dept($db, $uid);

    $db->prepare("INSERT INTO `live_event`(`eventdate`,`enddate`,`title`,`content`,`status`,`created_by`,`source`,`reply_deadline`,`show_status_to_others`) VALUES (?,?,?,?,0,?,?,?,?)")
       ->execute([$post['eventdate'], $enddate, $post['title'], $post['content'], ($uid ?: null), $source, $replyDeadline, $showStatus]);
    $newId = (int)$db->lastInsertId();

    $eventNo = eg_gen_event_no($db, $post['eventdate']);
    $db->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([$eventNo, $newId]);

    $primaryStatus = eg_save_event_targets($db, $newId, $post['targets'], $modes);
    $db->prepare("UPDATE live_event SET status=? WHERE id=?")->execute([$primaryStatus, $newId]);

    if (isset($post['co_editors'])) {
        try { eg_save_event_editors($db, $newId, $post['co_editors']); }
        catch (Throwable $e) { error_log('[notice] save co_editors failed: ' . $e->getMessage()); }
    }

    // 種類／圖文內容／張貼期限（草稿發布時順手刪草稿）
    try { ndoc_apply_post($db, $newId, $uid, $post, true); }
    catch (Throwable $e) { error_log('[notice] ndoc_apply_post(new) failed: ' . $e->getMessage()); }
    if ($appr) {
        try { ndoc_appr_record($db, $newId, (int)$appr['approver'], (string)$appr['date'], !empty($appr['auto']), $appr['by'] ?? null, (string)($appr['note'] ?? '')); }
        catch (Throwable $e) { error_log('[notice] approval record failed: ' . $e->getMessage()); }
    }

    if ($files && !empty($files['name'][0])) {
        foreach (eg_notice_save_files($files, eg_notice_event_dir($db, $eventNo)) as $sf) {
            $db->prepare("INSERT INTO live_event_file (live_event_id, file_name, file_path) VALUES (?,?,?)")->execute([$newId, $sf['name'], $sf['path']]);
        }
    }

    try {
        require_once __DIR__ . '/attachment_lib.php';
        eg_att_ensure_schema($db);
        eg_notice_bind_att_items($db, $newId, $eventNo, $post['att_items'] ?? '', $uid);
        $db->prepare("UPDATE live_event SET show_attach_inline=? WHERE id=?")->execute([!empty($post['show_attach_inline']) ? 1 : 0, $newId]);
    } catch (Throwable $e) { error_log('[notice] bind att_items on create failed: ' . $e->getMessage()); }

    eg_log_event_history($db, $newId, 'create', $uid, null, eg_event_snapshot($db, $newId));

    try { require_once __DIR__ . '/../push/push_send.php'; eg_push_for_event($db, $newId); }
    catch (\Throwable $e) { error_log('[push] on create event failed: ' . $e->getMessage()); }
    try { require_once __DIR__ . '/../../telegram/notify_event.php'; eg_telegram_for_event($db, $newId); }
    catch (\Throwable $e) { error_log('[telegram] on create event failed: ' . $e->getMessage()); }
    return $newId;
}

/* ===================== 送審／核准／退回 ===================== */

function ndoc_kind_label_of(string $k): string { return ndoc_kinds()[ndoc_kind_norm($k)]; }

/** 站內通知＋推播（比照 business_trip_lib 的 bt_notify_*）；ref_id 是草稿表的 id */
function ndoc_notify(PDO $db, string $refType, int $draftId, int $toUid, int $fromUid, string $title, string $content, string $mode): int
{
    if ($toUid <= 0) return 0;
    try {
        if ($mode === 'sign') ndoc_close_notice($db, $draftId);
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '公告/通知核准', 1, ?, ?)")
           ->execute([$title, $content, $fromUid ?: null, $refType, $draftId]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)")
           ->execute([$eid, $toUid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title' => $title, 'body' => mb_substr($content, 0, 480, 'UTF-8')]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { error_log('[notice_doc] notify: ' . $e->getMessage()); return 0; }
}
function ndoc_close_notice(PDO $db, int $draftId): void
{
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE ref_type='NOTICE_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")->execute([$draftId]);
    } catch (Throwable $e) {}
}
function ndoc_user_name(PDO $db, int $uid): string
{
    $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
    $st->execute([$uid]);
    return (string)$st->fetchColumn();
}

/** 送審：把整張表單存成 state=pending 的草稿並通知核准人（發起人本人就是核准人時由呼叫端直接發布） */
function ndoc_submit_pending(PDO $db, int $uid, int $draftId, array $post): array
{
    $kind = ndoc_kind_norm($post['kind'] ?? 'notice');
    $approver = ndoc_appr_approver($db);
    if ($approver <= 0) return ['error' => '尚未設定核准人員，請洽超級管理員'];
    $r = ndoc_draft_save($db, $uid, $draftId, $post);
    if (isset($r['error'])) return $r;
    $id = $r['id'];
    // 草稿的 payload 沒收 att_items／show_attach_inline／source，送審時補進去（核准後發布要用）
    $d = ndoc_draft_get($db, $uid, $id);
    $data = $d['data'];
    $data['att_items'] = (string)($post['att_items'] ?? '');
    $data['show_attach_inline'] = !empty($post['show_attach_inline']) ? 1 : 0;
    $data['source'] = (string)($post['source'] ?? '');
    $now = $db->query("SELECT NOW()")->fetchColumn();
    $db->prepare("UPDATE live_event_draft SET payload=?, state='pending', approver_id=?, submitted_at=?, reject_reason=NULL WHERE id=? AND user_id=?")
       ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $approver, $now, $id, $uid]);
    $by = ndoc_user_name($db, $uid);
    ndoc_notify($db, 'NOTICE_APPROVAL', $id, $approver, $uid,
        ndoc_kind_label_of($kind) . '待核准：' . $data['title'],
        '送審人：' . $by . "\n種類：" . ndoc_kind_label_of($kind) . "\n標題：" . $data['title'] . "\n點此開啟，可直接核准或退回（退回須填原因）；核准後才會正式發布給對象。", 'sign');
    return ['id' => $id];
}

/** 送審人撤回（回到草稿可再修改） */
function ndoc_withdraw(PDO $db, int $uid, int $id): string
{
    $d = ndoc_draft_get($db, $uid, $id);
    if (!$d || $d['state'] !== 'pending') return '這份單目前不在送審中';
    $db->prepare("UPDATE live_event_draft SET state='draft', approver_id=NULL WHERE id=? AND user_id=?")->execute([$id, $uid]);
    ndoc_close_notice($db, $id);
    return '';
}

/**
 * 核准／退回。呼叫端須先確認 $deciderUid 是核准人本人或超級管理員（這裡也再驗一次）。
 * 核准＝依送審時的內容正式發布；章上的人永遠是「指定的核准人」，核准日期由按的人指定（業務日期）。
 */
function ndoc_decide(PDO $db, int $id, int $deciderUid, bool $isAdmin, string $decision, string $note, string $date): array
{
    $st = $db->prepare("SELECT * FROM live_event_draft WHERE id=?");
    $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d || $d['state'] !== 'pending') return ['error' => '這份單已經被處理過或已撤回，請重新整理'];
    $approver = (int)$d['approver_id'];
    if ($deciderUid !== $approver && !$isAdmin) return ['error' => '您不是這份單的核准人'];
    $submitter = (int)$d['user_id'];
    $data = json_decode((string)$d['payload'], true) ?: [];
    $kind = ndoc_kind_norm($data['kind'] ?? $d['kind']);

    if ($decision === 'reject') {
        $note = trim($note);
        if ($note === '') return ['error' => '退回必須填寫原因'];
        $db->prepare("UPDATE live_event_draft SET state='rejected', reject_reason=?, decided_at=NOW() WHERE id=?")->execute([mb_substr($note, 0, 300, 'UTF-8'), $id]);
        ndoc_close_notice($db, $id);
        ndoc_notify($db, 'NOTICE_APPROVAL_RESULT', $id, $submitter, $deciderUid,
            ndoc_kind_label_of($kind) . '已退回：' . $d['title'],
            ndoc_user_name($db, $deciderUid) . ' 已退回您送審的' . ndoc_kind_label_of($kind) . "。\n退回原因：" . $note . "\n請到公告/通知管理的「我的草稿與送審」修改後重新送出。", 'read');
        return ['ok' => 1];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['error' => '請選擇核准日期'];
    $today = $db->query("SELECT CURDATE()")->fetchColumn();
    if ($date > $today) return ['error' => '核准日期不可以是未來日期'];
    if (empty($data['targets']) || trim((string)($data['title'] ?? '')) === '' || trim((string)($data['content_html'] ?? '')) === '') {
        return ['error' => '這份單的標題、內容或對象不完整，無法發布，請退回請送審人補齊'];
    }
    [$h, $t] = ndoc_clean_content((string)$data['content_html']);
    $post = $data;
    $post['content_html'] = $h;
    $post['content'] = $t;
    if (empty($post['eventdate'])) $post['eventdate'] = $today;
    $post['draft_id'] = $id;
    try {
        $newId = ndoc_publish_new($db, $post, $submitter, null, ['approver' => $approver, 'date' => $date, 'auto' => false, 'by' => $deciderUid, 'note' => $note]);
    } catch (Throwable $e) {
        error_log('[notice_doc] publish after approve: ' . $e->getMessage());
        return ['error' => '發布失敗：' . $e->getMessage()];
    }
    ndoc_close_notice($db, $id);
    ndoc_notify($db, 'NOTICE_APPROVAL_RESULT', $id, $submitter, $deciderUid,
        ndoc_kind_label_of($kind) . '已核准並發布：' . $d['title'],
        ndoc_user_name($db, $deciderUid) . ' 已核准您送審的' . ndoc_kind_label_of($kind) . '，已正式發布給對象。', 'read');
    return ['ok' => 1, 'event_id' => $newId];
}

/** 待我核准（核准人本人；超管看全部）＋我送出的（送審中／被退回） */
function ndoc_pending_lists(PDO $db, int $uid, bool $isAdmin): array
{
    $toMe = $db->prepare("SELECT d.id,d.kind,d.title,d.submitted_at,d.approver_id,u.user_cname AS submitter FROM live_event_draft d LEFT JOIN user u ON u.id=d.user_id
                          WHERE d.state='pending' AND (d.approver_id=? OR ?=1) ORDER BY d.submitted_at");
    $toMe->execute([$uid, $isAdmin ? 1 : 0]);
    $mine = $db->prepare("SELECT id,kind,title,state,submitted_at,reject_reason,updated_at FROM live_event_draft WHERE user_id=? AND state IN ('pending','rejected') ORDER BY updated_at DESC");
    $mine->execute([$uid]);
    $dr = $db->prepare("SELECT id,kind,title,state,updated_at FROM live_event_draft WHERE user_id=? AND state='draft' ORDER BY updated_at DESC LIMIT 50");
    $dr->execute([$uid]);
    return ['to_me' => $toMe->fetchAll(PDO::FETCH_ASSOC), 'mine' => $mine->fetchAll(PDO::FETCH_ASSOC), 'drafts' => $dr->fetchAll(PDO::FETCH_ASSOC)];
}

/** 草稿／送審單的預覽列印資料（格式與 nc_print_data 相同，讓前端共用同一支 buildBody；不配號、不寫列印紀錄） */
function ndoc_draft_print_data(PDO $db, array $d): array
{
    require_once __DIR__ . '/notice_contact_lib.php';
    require_once __DIR__ . '/asdoc_lib.php';
    $x = json_decode((string)$d['payload'], true) ?: [];
    $kind = ndoc_kind_norm($x['kind'] ?? $d['kind']);
    $asof = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($x['eventdate'] ?? '')) ? $x['eventdate'] : (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $isC = ($kind === 'contact');
    $docId = $isC ? eg_asdoc_id($db, NC_ASDOC_MODULE) : 0;
    $doc = $isC ? eg_asdoc_get($db, NC_ASDOC_MODULE) : null;
    $uid = (int)$d['user_id'];
    $appr = (int)($d['approver_id'] ?: ndoc_appr_approver($db));
    $html = (string)($x['content_html'] ?? '');
    return [
        'ok' => true, 'preview' => true, 'event_id' => 0,
        'contact_no' => $isC ? '（預覽，列印時才配號）' : '', 'event_no' => '（預覽）',
        'eventdate' => $asof, 'date_disp' => eg_fmt_date($asof),
        'title' => (string)($x['title'] ?? ''), 'content' => trim(eg_richtext_to_text($html)),
        'content_html' => $html === '' ? '' : ndoc_hydrate($html),
        'i18n' => (trim((string)($x['vi_title'] ?? '')) !== '') ? ['vi' => [
            'title' => (string)$x['vi_title'], 'content' => trim(eg_richtext_to_text((string)($x['vi_html'] ?? ''))),
            'content_html' => ($x['vi_html'] ?? '') === '' ? '' : ndoc_hydrate((string)$x['vi_html'])]] : (object)[],
        'kind' => $kind, 'kind_name' => ndoc_kinds()[$kind],
        'post_until' => ($kind === 'announce' && !empty($x['post_until'])) ? $x['post_until'] : '',
        'files' => [],
        'from_text' => nc_person_label(nc_person($db, $uid, $asof)),
        'to_text' => (function () use ($db, $x) {
            $o = [];
            foreach ((array)($x['targets'] ?? []) as $c) {
                if ($c === 'all') { $o[] = '全體同仁'; continue; }
                if (!preg_match('/^(dept|status|user)-(\d+)$/', (string)$c, $m)) continue;
                $o[] = (string)(['dept' => nc_name_of($db, 'department', 'name', (int)$m[2]), 'status' => nc_name_of($db, 'user_status', 'title', (int)$m[2]), 'user' => nc_name_of($db, 'user', 'user_cname', (int)$m[2])][$m[1]] ?? '');
            }
            return implode('、', array_filter($o));
        })(),
        'maker' => nc_person($db, $uid, $asof) + ['date' => $asof],
        'approver' => ['id' => 0, 'name' => '', 'dept' => '', 'position' => '', 'date' => ''],   // 尚未核准前不蓋核准章
        'people' => [], 'include' => null, 'show_reply' => 0, 'is_backfill' => 0,
        'company' => nc_company($db),
        'doc_name' => $isC ? ($doc['doc_name'] ?? '聯絡單') : ndoc_kinds()[$kind],
        'doc_no' => ($isC && $docId) ? eg_asdoc_no_asof_id($db, $docId, $asof) : '',
        'stamp_tpl' => nc_stamp_template($db), 'stamp_tpl_read' => nc_stamp_template($db, 'read'),
    ];
}

}
