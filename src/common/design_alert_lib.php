<?php
// design_alert_lib.php — 訂單追蹤「設計提醒」唯一實作（2026-10-14 使用者交辦）
//
// 用途：業務／設計主管針對「某客戶」「某料號」「某客戶＋規格關鍵字」「某客戶＋某料號」預先設定
// 一句提醒（例：台積電 → 圖面都需要齒研）。符合條件、且尚未轉生管的訂單，會自動在該訂單的
// 「設計備註」裡長出一則「系統提醒」（淺黃底），設計看過按「已確認」＝標記完成，按錯可「取消確認」，
// 每一次確認／取消都記錄是誰、什麼時候。與業務用的「客戶提醒」（order_client_reminder_lib.php，
// 選客戶就跳窗、有確認／待處理鈕）完全分開，互不影響。
//
// 刻意這樣做的地方：
//  1. 系統提醒就是 eng_log_item（設計備註同一套問題項）加一個 src_rule_id 標記——回覆、作廢、
//     歷史、列表預覽、凍結規則全部沿用，不另做一套狀態。「已確認」＝status='resolved'。
//  2. 同一張訂單、同一則規則只會產生一次（連被作廢的也算有過，不會被重新長出來）。
//  3. 提醒文字一律即時讀規則（來源若是客戶／料號設計備註，就即時讀那則備註與附件），
//     不複製一份——備註改了提示跟著改，不會兩邊對不起來（鐵律4）。已確認的不會因規則修改而
//     重新變成待確認（使用者拍板）。
//  4. 統計：系統提醒「不算」批圖溝通中（那是業務看的未回覆），另有「設計待處理」（有未確認的
//     系統提醒）與「PS」（只有純備註）兩張卡；三者的 SQL 片段全在這裡，頁面不要自己拼。

require_once __DIR__ . '/eng_log_lib.php';

if (!function_exists('oda_kinds')) {

function oda_kinds(): array
{
    return ['customer' => '客戶', 'part' => '料號', 'cust_kw' => '客戶＋關鍵字', 'cust_part' => '客戶＋料號'];
}

function oda_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    el_ensure_schema($db);   // 內含 eng_log_item.src_rule_id
    $db->exec("CREATE TABLE IF NOT EXISTS order_design_alert_rule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(12) NOT NULL COMMENT 'customer/part/cust_kw/cust_part',
        customer_id VARCHAR(30) NULL COMMENT 'customer_list.customer_id',
        customer_name VARCHAR(80) NULL COMMENT '建立當下的名稱，僅供顯示',
        part_d_id INT NULL COMMENT 'd_setting.d_id',
        part_label VARCHAR(120) NULL COMMENT '建立當下的料號文字，僅供顯示',
        keywords VARCHAR(600) NULL COMMENT 'JSON 陣列：比對 d_setting.Spec_No 的關鍵字（模糊）',
        kw_mode VARCHAR(4) NOT NULL DEFAULT 'any' COMMENT 'any=任一組符合 all=全部符合',
        content VARCHAR(500) NULL COMMENT '提醒文字（引用備註時為建立當下的快照，顯示一律即時讀備註）',
        source_note_id INT NULL COMMENT '引用的客戶／料號設計備註 design_notes.note_id',
        source_note_type VARCHAR(10) NULL COMMENT 'customer/part',
        is_active TINYINT NOT NULL DEFAULT 1,
        disabled_reason VARCHAR(500) NULL,
        disabled_by INT NULL, disabled_by_name VARCHAR(50) NULL, disabled_at DATETIME NULL,
        created_by INT NULL, created_by_name VARCHAR(50) NULL, created_at DATETIME NULL,
        updated_by INT NULL, updated_by_name VARCHAR(50) NULL, updated_at DATETIME NULL,
        KEY idx_active (is_active), KEY idx_cust (customer_id), KEY idx_note (source_note_id, source_note_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單追蹤：設計提醒規則'");
    $db->exec("CREATE TABLE IF NOT EXISTS order_design_alert_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        rule_id INT NULL,
        order_id INT NULL,
        action VARCHAR(10) NOT NULL COMMENT 'confirm/unconfirm',
        by_id INT NULL, by_name VARCHAR(50) NULL,
        at DATETIME NOT NULL,
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='設計提醒 確認／取消確認紀錄'");
}

/* ───────────────────────── 規則 CRUD ───────────────────────── */

/** 關鍵字字串 → 陣列（逗號／頓號／分號／換行／空白分隔，最多 10 組、每組 40 字） */
function oda_parse_keywords($raw): array
{
    if (is_array($raw)) $parts = $raw;
    else $parts = preg_split('/[,，、;；\n\r\t]+/u', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        if (mb_strlen($p, 'UTF-8') > 40) $p = mb_substr($p, 0, 40, 'UTF-8');
        $k = mb_strtolower($p, 'UTF-8');
        $out[$k] = $p;
    }
    return array_slice(array_values($out), 0, 10);
}

/** 驗證並整理一則規則的輸入；不合法丟 InvalidArgumentException（訊息直接給使用者看） */
function oda_rule_clean(PDO $db, array $in): array
{
    $kind = (string)($in['kind'] ?? '');
    if (!isset(oda_kinds()[$kind])) throw new InvalidArgumentException('請選擇提示對象類型');
    $r = ['kind' => $kind, 'customer_id' => null, 'customer_name' => null, 'part_d_id' => null, 'part_label' => null,
          'keywords' => null, 'kw_mode' => 'any'];
    if ($kind !== 'part') {
        $cid = trim((string)($in['customer_id'] ?? ''));
        if ($cid === '') throw new InvalidArgumentException('請選擇客戶（從客戶主檔挑選）');
        $st = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE customer_id=?");
        $st->execute([$cid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new InvalidArgumentException('查無這個客戶，請重新選擇');
        $r['customer_id'] = (string)$c['customer_id'];
        $r['customer_name'] = (string)$c['customer'];
    }
    if ($kind === 'part' || $kind === 'cust_part') {
        $pid = (int)($in['part_d_id'] ?? 0);
        if ($pid <= 0) throw new InvalidArgumentException('請選擇料號（從料號主檔挑選）');
        $st = $db->prepare("SELECT d_id, D_Setting_Id, Customer_Id FROM d_setting WHERE d_id=?");
        $st->execute([$pid]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new InvalidArgumentException('查無這個料號，請重新選擇');
        $r['part_d_id'] = (int)$p['d_id'];
        $r['part_label'] = (string)$p['D_Setting_Id'];
        if ($kind === 'cust_part' && trim((string)$p['Customer_Id']) !== '' && (string)$p['Customer_Id'] !== $r['customer_id']) {
            throw new InvalidArgumentException('這個料號不屬於所選的客戶，請確認客戶與料號');
        }
    }
    if ($kind === 'cust_kw') {
        $kw = oda_parse_keywords($in['keywords'] ?? '');
        if (!$kw) throw new InvalidArgumentException('請至少輸入一組關鍵字（多組用逗號分隔）');
        $r['keywords'] = json_encode($kw, JSON_UNESCAPED_UNICODE);
        $r['kw_mode'] = (($in['kw_mode'] ?? '') === 'all') ? 'all' : 'any';
    }
    return $r;
}

/** 新增／修改一則規則；回傳 id。引用備註的規則內容不可改（要改請改那則備註）。 */
function oda_rule_save(PDO $db, int $id, array $in, int $uid, string $uname): int
{
    oda_ensure_schema($db);
    $c = oda_rule_clean($db, $in);
    $content = trim((string)($in['content'] ?? ''));
    if ($id > 0) {
        $cur = oda_rule_get($db, $id);
        if (!$cur) throw new InvalidArgumentException('查無這則規則，請重新整理');
        if (!empty($cur['source_note_id'])) $content = (string)$cur['content'];   // 引用備註：內容不在這裡改
    }
    if ($content === '') throw new InvalidArgumentException('請填寫提醒內容');
    if (mb_strlen($content, 'UTF-8') > 500) throw new InvalidArgumentException('提醒內容請控制在 500 字以內');
    if ($id > 0) {
        $db->prepare("UPDATE order_design_alert_rule SET kind=?, customer_id=?, customer_name=?, part_d_id=?, part_label=?,
                      keywords=?, kw_mode=?, content=?, updated_by=?, updated_by_name=?, updated_at=NOW() WHERE id=?")
           ->execute([$c['kind'], $c['customer_id'], $c['customer_name'], $c['part_d_id'], $c['part_label'],
                      $c['keywords'], $c['kw_mode'], $content, ($uid ?: null), $uname, $id]);
        return $id;
    }
    $db->prepare("INSERT INTO order_design_alert_rule (kind, customer_id, customer_name, part_d_id, part_label, keywords, kw_mode,
                  content, is_active, created_by, created_by_name, created_at, updated_by, updated_by_name, updated_at)
                  VALUES (?,?,?,?,?,?,?,?,1,?,?,NOW(),?,?,NOW())")
       ->execute([$c['kind'], $c['customer_id'], $c['customer_name'], $c['part_d_id'], $c['part_label'],
                  $c['keywords'], $c['kw_mode'], $content, ($uid ?: null), $uname, ($uid ?: null), $uname]);
    return (int)$db->lastInsertId();
}

function oda_rule_get(PDO $db, int $id): ?array
{
    oda_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM order_design_alert_rule WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** 從客戶／料號設計備註「設為提示」。回傳 rule id；該備註已有規則就直接回傳那一則（必要時重新啟用）。 */
function oda_rule_from_note(PDO $db, int $noteId, string $noteType, int $partDid, int $uid, string $uname): int
{
    oda_ensure_schema($db);
    if (!in_array($noteType, ['customer', 'part'], true)) throw new InvalidArgumentException('備註類型不正確');
    $st = $db->prepare("SELECT note_id, target_type, target_id, note_text FROM design_notes WHERE note_id=?");
    $st->execute([$noteId]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n || (string)$n['target_type'] !== $noteType) throw new InvalidArgumentException('查無這則設計備註，請重新整理');
    $text = trim((string)$n['note_text']);
    if ($text === '') throw new InvalidArgumentException('這則備註沒有文字內容，無法設為提示');

    $ex = $db->prepare("SELECT id, is_active FROM order_design_alert_rule WHERE source_note_id=? AND source_note_type=? ORDER BY id DESC LIMIT 1");
    $ex->execute([$noteId, $noteType]);
    $e = $ex->fetch(PDO::FETCH_ASSOC);
    if ($e) {
        if (!(int)$e['is_active']) {
            $db->prepare("UPDATE order_design_alert_rule SET is_active=1, disabled_reason=NULL, updated_by=?, updated_by_name=?, updated_at=NOW() WHERE id=?")
               ->execute([($uid ?: null), $uname, (int)$e['id']]);
        }
        return (int)$e['id'];
    }
    $in = ['content' => mb_substr($text, 0, 500, 'UTF-8')];
    if ($noteType === 'customer') {
        $in['kind'] = 'customer';
        $in['customer_id'] = (string)$n['target_id'];
    } else {
        if ($partDid <= 0) throw new InvalidArgumentException('這筆訂單的料號尚未綁定料號主檔，無法把料號備註設為提示');
        $in['kind'] = 'part';
        $in['part_d_id'] = $partDid;
    }
    $c = oda_rule_clean($db, $in);
    $db->prepare("INSERT INTO order_design_alert_rule (kind, customer_id, customer_name, part_d_id, part_label, kw_mode, content,
                  source_note_id, source_note_type, is_active, created_by, created_by_name, created_at, updated_by, updated_by_name, updated_at)
                  VALUES (?,?,?,?,?, 'any', ?,?,?,1,?,?,NOW(),?,?,NOW())")
       ->execute([$c['kind'], $c['customer_id'], $c['customer_name'], $c['part_d_id'], $c['part_label'],
                  $in['content'], $noteId, $noteType, ($uid ?: null), $uname, ($uid ?: null), $uname]);
    return (int)$db->lastInsertId();
}

/** 停用（原因必填）／重新啟用。已產生的提醒一律保留。 */
function oda_rule_set_active(PDO $db, int $id, bool $active, string $reason, int $uid, string $uname): void
{
    $r = oda_rule_get($db, $id);
    if (!$r) throw new InvalidArgumentException('查無這則規則，請重新整理');
    if ($active) {
        if ((int)$r['is_active']) throw new InvalidArgumentException('這則規則已經是啟用狀態，請重新整理看最新狀態');
        $db->prepare("UPDATE order_design_alert_rule SET is_active=1, updated_by=?, updated_by_name=?, updated_at=NOW() WHERE id=?")
           ->execute([($uid ?: null), $uname, $id]);
        return;
    }
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('請填寫停用原因');
    if (mb_strlen($reason, 'UTF-8') > 500) throw new InvalidArgumentException('停用原因請控制在 500 字以內');
    if (!(int)$r['is_active']) throw new InvalidArgumentException('這則規則已經被停用了，請重新整理看最新狀態');
    $db->prepare("UPDATE order_design_alert_rule SET is_active=0, disabled_reason=?, disabled_by=?, disabled_by_name=?, disabled_at=NOW(),
                  updated_by=?, updated_by_name=?, updated_at=NOW() WHERE id=?")
       ->execute([$reason, ($uid ?: null), $uname, ($uid ?: null), $uname, $id]);
}

/** 刪除規則本體（呼叫端限全站管理員）。已產生的提醒保留，顯示改用建立當下的文字快照。 */
function oda_rule_delete(PDO $db, int $id): void
{
    oda_ensure_schema($db);
    $db->prepare("DELETE FROM order_design_alert_rule WHERE id=?")->execute([$id]);
}

/** 提醒文字：引用備註就即時讀備註，備註被刪了才退回規則上的快照 */
function oda_rule_text(PDO $db, array $rule): string
{
    if (!empty($rule['source_note_id'])) {
        $st = $db->prepare("SELECT note_text FROM design_notes WHERE note_id=?");
        $st->execute([(int)$rule['source_note_id']]);
        $t = $st->fetchColumn();
        if ($t !== false && trim((string)$t) !== '') return trim((string)$t);
    }
    return (string)($rule['content'] ?? '');
}

/** 規則清單（管理面板用）：附上已產生幾張訂單、其中幾張尚未確認 */
function oda_rule_rows(PDO $db): array
{
    oda_ensure_schema($db);
    $rows = $db->query("SELECT * FROM order_design_alert_rule ORDER BY is_active DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];
    $cnt = [];
    foreach ($db->query("SELECT src_rule_id AS rid, COUNT(*) AS n,
                                SUM(CASE WHEN voided_at IS NULL AND status NOT IN ('resolved','dropped') THEN 1 ELSE 0 END) AS open_n
                           FROM eng_log_item WHERE src_rule_id IS NOT NULL GROUP BY src_rule_id")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cnt[(int)$c['rid']] = $c;
    }
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['is_active'] = (int)$r['is_active'];
        $r['kind_label'] = oda_kinds()[$r['kind']] ?? $r['kind'];
        $r['keywords_arr'] = $r['keywords'] ? (json_decode((string)$r['keywords'], true) ?: []) : [];
        $r['text'] = oda_rule_text($db, $r);
        $r['gen_count'] = (int)($cnt[$r['id']]['n'] ?? 0);
        $r['open_count'] = (int)($cnt[$r['id']]['open_n'] ?? 0);
    }
    unset($r);
    return $rows;
}

/* ───────────────────────── 自動產生 ───────────────────────── */

/**
 * 依啟用中的規則，替「尚未轉生管」的訂單產生系統提醒（同一張訂單同一則規則只產生一次）。
 * @param ?array $orderIds 只處理這些訂單；null＝全部未轉生管訂單
 * @param ?int   $onlyRule 只跑這一則規則（規則剛新增／修改／啟用時用）
 * @return int 新產生的筆數
 */
function oda_sync(PDO $db, ?array $orderIds = null, ?int $onlyRule = null): int
{
    oda_ensure_schema($db);
    $sql = "SELECT * FROM order_design_alert_rule WHERE is_active=1" . ($onlyRule ? " AND id=" . (int)$onlyRule : "");
    $rules = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rules) return 0;

    $osql = "SELECT Order_id, Client_name_ID, d_id, d_id_ID FROM order_track
              WHERE pmGet IS NULL AND (Order_status IS NULL OR Order_status <> 6)";
    if ($orderIds !== null) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn($v) => $v > 0)));
        if (!$ids) return 0;
        $osql .= " AND Order_id IN (" . implode(',', $ids) . ")";
    }
    $orders = $db->query($osql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$orders) return 0;

    // 料號歸戶：主鍵優先；沒綁主鍵的用「料號文字＋同客戶」回查（同一個料號文字常掛好幾家客戶，必比客戶）
    $pkSet = []; $texts = [];
    foreach ($orders as $o) {
        if ((int)$o['d_id_ID'] > 0) $pkSet[(int)$o['d_id_ID']] = true;
        elseif (trim((string)$o['d_id']) !== '') $texts[trim((string)$o['d_id'])] = true;
    }
    $textMap = [];   // "文字|客戶id" => d_id ；"文字|" => d_id（該文字唯一時）
    foreach (array_chunk(array_keys($texts), 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT d_id, D_Setting_Id, Customer_Id FROM d_setting WHERE D_Setting_Id IN ($ph) ORDER BY d_id");
        $st->execute($chunk);
        $byText = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $k = (string)$d['D_Setting_Id'] . '|' . (string)$d['Customer_Id'];
            if (!isset($textMap[$k])) $textMap[$k] = (int)$d['d_id'];
            $byText[(string)$d['D_Setting_Id']][] = (int)$d['d_id'];
        }
        foreach ($byText as $t => $list) if (count($list) === 1) $textMap[$t . '|'] = $list[0];
    }
    foreach ($textMap as $pid) $pkSet[$pid] = true;
    $partInfo = [];
    foreach (array_chunk(array_keys($pkSet), 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT d_id, Customer_Id, Spec_No FROM d_setting WHERE d_id IN ($ph)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $partInfo[(int)$d['d_id']] = $d;
    }

    // 已經產生過的 (規則,訂單)——含被作廢的，作廢過的不會被重新長出來
    $ruleIds = array_map(fn($r) => (int)$r['id'], $rules);
    $have = [];
    $q = "SELECT i.src_rule_id AS rid, b.bind_id AS oid
            FROM eng_log_item i
            JOIN eng_log g ON g.id = i.log_id AND g.log_type = 'order_note'
            JOIN eng_log_bind b ON b.log_id = i.log_id AND b.bind_type = 'order'
           WHERE i.src_rule_id IN (" . implode(',', $ruleIds) . ")";
    foreach ($db->query($q)->fetchAll(PDO::FETCH_ASSOC) as $h) $have[(int)$h['rid'] . '|' . (int)$h['oid']] = true;

    $todo = [];   // [ [rule, orderId], ... ]
    foreach ($orders as $o) {
        $oid = (int)$o['Order_id'];
        $pk = (int)$o['d_id_ID'];
        $cust = trim((string)$o['Client_name_ID']);
        if ($pk <= 0) {
            $t = trim((string)$o['d_id']);
            if ($t !== '') $pk = (int)($textMap[$t . '|' . $cust] ?? ($cust === '' ? ($textMap[$t . '|'] ?? 0) : 0));
        }
        $spec = $pk > 0 ? (string)($partInfo[$pk]['Spec_No'] ?? '') : '';
        if ($cust === '' && $pk > 0) $cust = trim((string)($partInfo[$pk]['Customer_Id'] ?? ''));
        foreach ($rules as $r) {
            if (isset($have[(int)$r['id'] . '|' . $oid])) continue;
            $k = (string)$r['kind'];
            $custOk = ($r['customer_id'] !== null && $r['customer_id'] !== '' && $cust === (string)$r['customer_id']);
            $partOk = ($pk > 0 && (int)$r['part_d_id'] === $pk);
            $ok = false;
            if ($k === 'customer') $ok = $custOk;
            elseif ($k === 'part') $ok = $partOk;
            elseif ($k === 'cust_part') $ok = $custOk && $partOk;
            elseif ($k === 'cust_kw') $ok = $custOk && $spec !== '' && oda_spec_match($spec, (string)$r['keywords'], (string)$r['kw_mode']);
            if ($ok) $todo[] = [$r, $oid];
        }
    }
    if (!$todo) return 0;

    $now = (string)$db->query("SELECT NOW()")->fetchColumn();
    $today = substr($now, 0, 10);
    $text = [];
    $made = 0;
    $touchedLogs = [];
    try {
        $db->beginTransaction();
        foreach ($todo as [$r, $oid]) {
            $rid = (int)$r['id'];
            if (!isset($text[$rid])) $text[$rid] = oda_rule_text($db, $r);
            $logId = el_order_case_get_or_create($db, $oid, ['uid' => 0, 'dept_id' => null], $now, $today);
            $mx = $db->prepare("SELECT COALESCE(MAX(seq),0)+1 FROM eng_log_item WHERE log_id=?");
            $mx->execute([$logId]);
            // remind_sent=1＋follow_up_days 很大：系統提醒不是在等誰回覆，不可以被催回覆機制撿走
            $db->prepare("INSERT INTO eng_log_item (log_id, parent_item_id, seq, question, target_type, target_id, target_label,
                          target_post, target_contact, is_note, asked_at, status, follow_up_days, remind_sent, created_at, created_by, src_rule_id)
                          VALUES (?,NULL,?,?,NULL,NULL,NULL,NULL,NULL,0,?, 'waiting', 3650, 1, ?, NULL, ?)")
               ->execute([$logId, (int)$mx->fetchColumn(), mb_substr($text[$rid], 0, 500, 'UTF-8'), $today, $now, $rid]);
            $touchedLogs[$logId] = true;
            $made++;
        }
        foreach (array_keys($touchedLogs) as $lid) el_order_case_sync_status($db, (int)$lid, $now);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[design_alert] sync failed: ' . $e->getMessage());
        return 0;
    }
    return $made;
}

/** 規格（Spec_No）是否符合關鍵字：固定模糊比對、不分大小寫；any=任一組、all=全部 */
function oda_spec_match(string $spec, string $keywordsJson, string $mode): bool
{
    $kw = json_decode($keywordsJson, true);
    if (!is_array($kw) || !$kw) return false;
    $hit = 0;
    foreach ($kw as $k) {
        if ($k !== '' && mb_stripos($spec, (string)$k, 0, 'UTF-8') !== false) $hit++;
    }
    return $mode === 'all' ? ($hit === count($kw)) : ($hit > 0);
}

/** 順路觸發的全量同步：每 2 分鐘最多一次（頁面載入時呼叫；失敗不影響頁面） */
function oda_sync_auto(PDO $db): void
{
    try {
        $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eg_oda_sync.txt';
        if (is_file($f) && (time() - (int)@filemtime($f)) < 120) return;
        @touch($f);
        oda_sync($db);
    } catch (Throwable $e) { error_log('[design_alert] auto sync: ' . $e->getMessage()); }
}

/* ───────────────────────── 顯示資料 ───────────────────────── */

/** 系統提醒項目的附加資訊（提醒文字即時讀規則／附件／確認紀錄），給 ate_q_list 疊上去 */
function oda_item_meta(PDO $db, array $itemIds): array
{
    oda_ensure_schema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $items = $db->query("SELECT id, src_rule_id, status FROM eng_log_item WHERE id IN ($in) AND src_rule_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return [];
    $rids = array_values(array_unique(array_map(fn($x) => (int)$x['src_rule_id'], $items)));
    $rules = [];
    foreach ($db->query("SELECT * FROM order_design_alert_rule WHERE id IN (" . implode(',', $rids) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rules[(int)$r['id']] = $r;
    }
    $logs = [];
    foreach ($db->query("SELECT item_id, action, by_name, DATE_FORMAT(at,'%Y-%m-%d %H:%i') AS at FROM order_design_alert_log
                          WHERE item_id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $logs[(int)$l['item_id']][] = $l;
    }
    $imgSt = $db->prepare("SELECT file_name, original_name FROM note_images WHERE note_id=? AND note_type=? ORDER BY sort_order, img_id");
    $out = [];
    foreach ($items as $it) {
        $iid = (int)$it['id'];
        $r = $rules[(int)$it['src_rule_id']] ?? null;
        $hist = $logs[$iid] ?? [];
        $images = [];
        if ($r && !empty($r['source_note_id'])) {
            $imgSt->execute([(int)$r['source_note_id'], ($r['source_note_type'] === 'part') ? 'part_design' : 'customer_design']);
            $images = $imgSt->fetchAll(PDO::FETCH_ASSOC);
        }
        $out[$iid] = [
            'rule_id'    => (int)$it['src_rule_id'],
            'rule_gone'  => $r === null,
            'rule_off'   => $r !== null && !(int)$r['is_active'],
            'kind_label' => $r ? (oda_kinds()[$r['kind']] ?? '') : '',
            'text'       => $r ? oda_rule_text($db, $r) : null,   // null＝規則已被刪除，前端用項目自己的快照
            'images'     => $images,
            'confirmed'  => ((string)$it['status'] === 'resolved'),
            'history'    => $hist,
            'last'       => $hist ? $hist[count($hist) - 1] : null,
        ];
    }
    return $out;
}

/**
 * 確認／取消確認（兩者共用，唯一寫入點）。點下去當下先看最新狀態，跟畫面不一致就擋下並請重新整理。
 * 每一次都寫一筆紀錄（誰、什麼時候）。呼叫端負責權限與凍結判定。
 * @return int 這則提醒所屬的訂單 id（呼叫端要重算該列預覽用）
 */
function oda_confirm(PDO $db, int $itemId, bool $confirm, int $uid, string $uname): int
{
    oda_ensure_schema($db);
    $now = (string)$db->query("SELECT NOW()")->fetchColumn();
    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT id, log_id, status, src_rule_id, voided_at FROM eng_log_item WHERE id=? FOR UPDATE");
        $st->execute([$itemId]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it || $it['src_rule_id'] === null) throw new InvalidArgumentException('這一條不是系統提醒，請重新整理');
        if ($it['voided_at'] !== null) throw new InvalidArgumentException('這一條已被作廢，請重新整理');
        $isDone = ((string)$it['status'] === 'resolved');
        if ($confirm) {
            if ($isDone) throw new InvalidArgumentException('這則提醒已經被確認過了，請重新整理看最新狀態');
            $db->prepare("UPDATE eng_log_item SET status='resolved', updated_at=? WHERE id=?")->execute([$now, $itemId]);
        } else {
            if (!$isDone) throw new InvalidArgumentException('這則提醒目前不是已確認狀態，請重新整理看最新狀態');
            $new = el_item_auto_status($db, $itemId, 'waiting');   // 有回覆過就回到「已回覆」，沒有就是「待回覆」
            $db->prepare("UPDATE eng_log_item SET status=?, updated_at=? WHERE id=?")->execute([$new, $now, $itemId]);
        }
        $logId = (int)$it['log_id'];
        $oid = el_case_order_id($db, $logId);
        $db->prepare("INSERT INTO order_design_alert_log (item_id, rule_id, order_id, action, by_id, by_name, at) VALUES (?,?,?,?,?,?,?)")
           ->execute([$itemId, (int)$it['src_rule_id'], $oid ?: null, $confirm ? 'confirm' : 'unconfirm', ($uid ?: null), $uname, $now]);
        el_order_case_sync_status($db, $logId, $now);
        $db->commit();
        return $oid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/* ───────────────────────── 統計／篩選 SQL 片段 ───────────────────────── */

/** 「設計待處理」：這張訂單有尚未確認的系統提醒（只看訂單階段的設計備註案件） */
function oda_pending_exists_sql(string $orderAlias = 'ot'): string
{
    return "EXISTS (SELECT 1 FROM eng_log_bind odb
        JOIN eng_log odg ON odg.id = odb.log_id AND odg.log_type = 'order_note'
        JOIN eng_log_item odi ON odi.log_id = odb.log_id
        WHERE odb.bind_type='order' AND odb.bind_id = CAST({$orderAlias}.Order_id AS CHAR)
        AND odi.src_rule_id IS NOT NULL
        AND odi.voided_at IS NULL
        AND odi.status NOT IN ('resolved','dropped'))";
}

/** 「PS」：設計備註裡有純備註，而且沒有任何待處理的人工問題（系統提醒另算，不影響 PS 判定） */
function oda_ps_exists_sql(string $orderAlias = 'ot'): string
{
    return "(EXISTS (SELECT 1 FROM eng_log_bind psb
        JOIN eng_log psg ON psg.id = psb.log_id AND psg.log_type = 'order_note'
        JOIN eng_log_item psi ON psi.log_id = psb.log_id
        WHERE psb.bind_type='order' AND psb.bind_id = CAST({$orderAlias}.Order_id AS CHAR)
        AND psi.is_note = 1 AND psi.src_rule_id IS NULL AND psi.voided_at IS NULL)
        AND NOT " . el_order_open_exists_sql($orderAlias) . ")";
}

}
