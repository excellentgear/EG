<?php
// order_client_reminder_lib.php — 訂單追蹤：「客戶提醒」唯一實作（2026-09-23 使用者要求）
//
// 背景：客戶欄位旁的筆記本圖示，可以綁定「這個客戶有哪些事要注意」（同客戶可設多筆，各自有
// 附件、建立/修改者與時間，比照設計備註的多筆紀錄）。選定/變更客戶當下，若該客戶有這張訂單
// 還沒看過的有效提醒，跳窗顯示，逐筆「確認」或「設為待處理」；設為待處理的會被記進「訂單待
// 確認」卡片，並在業務備註欄下方插入一段精簡摘要（原內容不動，接在下方）；「完成」只靠使用者
// 主動按「已確認完成」按鈕（不猜測業務備註文字有沒有被改過——2026-09-23 使用者拍板）。
//
// 三個刻意這樣做的地方：
//  1. 提醒綁在**客戶主檔**（customer_list.customer_id），不是綁在訂單——同一個客戶底下所有
//     訂單共用同一份提醒庫，跟設計備註（綁在單一訂單）是不同層級的東西。
//  2. 「這張訂單對這則提醒有沒有跳過窗」用 order_client_reminder_ack 的 (reminder_id,order_id)
//     唯一鍵判定，不是判斷「業務備註裡有沒有那段文字」——業務備註本來就會被使用者自由編輯，
//     拿它當狀態來源遲早判斷不準。
//  3. order_track.Order_ps 只有 varchar(150)，插入的是**精簡摘要**不是全文，完整內容永遠只在
//     「客戶提醒」筆記本裡看——備註欄位太窄硬塞全文只會把既有內容擠爆。
//
// 三張新表：
//   order_client_reminder        提醒本體（一個客戶可有多筆，各自可停用）
//   order_client_reminder_attach 提醒附件（比照 internal_audit_lib.php 的 ia_attach 寫法）
//   order_client_reminder_ack    每一則提醒對每一張訂單的處理紀錄（跳出/確認/待處理/已完成）

require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/role_features_helper.php';
require_once __DIR__ . '/attach_lib.php';

if (!defined('OCR_ATTACH_MAX_BYTES')) define('OCR_ATTACH_MAX_BYTES', 20 * 1024 * 1024); // 單檔 20MB
if (!defined('OCR_ATTACH_DENY_EXT'))  define('OCR_ATTACH_DENY_EXT', ['php','php3','php4','php5','phtml','exe','bat','cmd','sh','vbs','js','jar','com','scr','ps1']);
if (!defined('OCR_PS_MAX_LEN'))       define('OCR_PS_MAX_LEN', 150); // order_track.Order_ps 欄位長度上限

// ── 資料表 ──────────────────────────────────────────────────────────────
if (!function_exists('ocr_ensure_schema')) {
function ocr_ensure_schema(PDO $db): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS order_client_reminder (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(20) NOT NULL COMMENT '客戶ID（customer_list.customer_id）',
            customer_name VARCHAR(60) DEFAULT NULL COMMENT '建立當下的客戶名稱，僅供顯示',
            content VARCHAR(500) NOT NULL COMMENT '提醒內容',
            is_active TINYINT NOT NULL DEFAULT 1 COMMENT '1=有效（選定客戶會跳出）0=已停用（保留歷史不刪）',
            created_by INT DEFAULT NULL,
            created_by_name VARCHAR(50) DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            updated_by_name VARCHAR(50) DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            KEY idx_customer (customer_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單追蹤：客戶提醒（綁在客戶主檔，同客戶可多筆）'");

        $db->exec("CREATE TABLE IF NOT EXISTS order_client_reminder_attach (
            att_id INT AUTO_INCREMENT PRIMARY KEY,
            reminder_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL COMMENT '只存檔名，不存絕對路徑',
            orig_name VARCHAR(255) DEFAULT NULL,
            file_size INT DEFAULT NULL,
            uploaded_by INT DEFAULT NULL,
            uploaded_by_name VARCHAR(50) DEFAULT NULL,
            uploaded_at DATETIME DEFAULT NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0,
            KEY idx_reminder (reminder_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客戶提醒附件'");

        $db->exec("CREATE TABLE IF NOT EXISTS order_client_reminder_ack (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reminder_id INT NOT NULL,
            order_id INT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'shown' COMMENT 'shown/confirmed/pending/resolved',
            shown_at DATETIME DEFAULT NULL,
            confirmed_by INT DEFAULT NULL, confirmed_by_name VARCHAR(50) DEFAULT NULL, confirmed_at DATETIME DEFAULT NULL,
            pending_by INT DEFAULT NULL, pending_by_name VARCHAR(50) DEFAULT NULL, pending_at DATETIME DEFAULT NULL,
            resolved_by INT DEFAULT NULL, resolved_by_name VARCHAR(50) DEFAULT NULL, resolved_at DATETIME DEFAULT NULL,
            UNIQUE KEY uk_reminder_order (reminder_id, order_id),
            KEY idx_order (order_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客戶提醒×訂單 的處理紀錄（跳出過一次就不再重跳）'");
        $ok = true;
    } catch (Exception $e) { $ok = false; }
    return $ok;
}
}

// ── 附件資料夾（全站統一根路徑，見鐵律5） ─────────────────────────────
if (!function_exists('ocr_attach_dir')) {
function ocr_attach_dir(PDO $db): string {
    return eg_attach_dir($db, 'ocr_attach_dir', '訂單追蹤-客戶提醒');
}
}

// ── 權限：誰能新增/編輯/停用提醒（管理員 all 亦可，查詢失敗或無角色一律回 false） ──
if (!function_exists('ocr_can_manage')) {
function ocr_can_manage(PDO $pdo, int $uid): bool {
    if ($uid <= 0) return false;
    try {
        $features = rf_load_user_features_all($pdo, $uid);
        if (empty($features)) return false;
        return rf_has_feature($features, 'all') || rf_has_feature($features, 'ot_client_reminder_manage');
    } catch (Exception $e) { return false; }
}
}

// ── 提醒 CRUD ────────────────────────────────────────────────────────────
if (!function_exists('ocr_list_for_customer')) {
/** 某客戶的提醒清單（管理面板用，含停用的）；$activeOnly=true 只回有效的 */
function ocr_list_for_customer(PDO $db, string $customerId, bool $activeOnly = false): array {
    ocr_ensure_schema($db);
    $customerId = trim($customerId);
    if ($customerId === '') return [];
    $sql = "SELECT r.*,
                   (SELECT COUNT(*) FROM order_client_reminder_attach a WHERE a.reminder_id=r.id AND a.is_deleted=0) AS attach_count
              FROM order_client_reminder r
             WHERE r.customer_id = ?" . ($activeOnly ? " AND r.is_active=1" : "") . "
             ORDER BY r.is_active DESC, r.id DESC";
    $st = $db->prepare($sql);
    $st->execute([$customerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

if (!function_exists('ocr_get')) {
function ocr_get(PDO $db, int $id): ?array {
    ocr_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM order_client_reminder WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
}

if (!function_exists('ocr_save')) {
/** 新增或修改一筆提醒（id<=0＝新增）；回傳 id */
function ocr_save(PDO $db, int $id, string $customerId, string $customerName, string $content, int $uid, string $uname): int {
    ocr_ensure_schema($db);
    $customerId = trim($customerId);
    $content = trim($content);
    if ($customerId === '') throw new Exception('未指定客戶');
    if ($content === '') throw new Exception('提醒內容不可空白');
    if (mb_strlen($content, 'UTF-8') > 500) $content = mb_substr($content, 0, 500, 'UTF-8');
    if ($id > 0) {
        $exists = ocr_get($db, $id);
        if (!$exists) throw new Exception('查無這筆提醒');
        $st = $db->prepare("UPDATE order_client_reminder SET content=?, updated_by=?, updated_by_name=?, updated_at=NOW() WHERE id=?");
        $st->execute([$content, ($uid ?: null), $uname, $id]);
        return $id;
    }
    $st = $db->prepare("INSERT INTO order_client_reminder
        (customer_id, customer_name, content, is_active, created_by, created_by_name, created_at, updated_by, updated_by_name, updated_at)
        VALUES (?,?,?,1,?,?,NOW(),?,?,NOW())");
    $st->execute([$customerId, mb_substr($customerName, 0, 60, 'UTF-8') ?: null, $content, ($uid ?: null), $uname, ($uid ?: null), $uname]);
    return (int)$db->lastInsertId();
}
}

if (!function_exists('ocr_set_active')) {
function ocr_set_active(PDO $db, int $id, bool $active, int $uid, string $uname): bool {
    ocr_ensure_schema($db);
    $r = ocr_get($db, $id);
    if (!$r) return false;
    $st = $db->prepare("UPDATE order_client_reminder SET is_active=?, updated_by=?, updated_by_name=?, updated_at=NOW() WHERE id=?");
    $st->execute([$active ? 1 : 0, ($uid ?: null), $uname, $id]);
    return true;
}
}

// ── 附件 ─────────────────────────────────────────────────────────────────
if (!function_exists('ocr_attach_rows')) {
function ocr_attach_rows(PDO $db, int $reminderId): array {
    ocr_ensure_schema($db);
    $st = $db->prepare("SELECT att_id, file_name, orig_name, file_size, uploaded_by, uploaded_by_name,
                                DATE_FORMAT(uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at
                           FROM order_client_reminder_attach
                          WHERE reminder_id=? AND is_deleted=0 ORDER BY att_id");
    $st->execute([$reminderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $b = (int)($r['file_size'] ?? 0);
        $r['size_text'] = $b <= 0 ? '' : ($b < 1024 ? $b . ' B' : ($b < 1048576 ? round($b / 1024) . ' KB' : round($b / 1048576, 1) . ' MB'));
    }
    return $rows;
}
}

if (!function_exists('ocr_attach_add')) {
/** 上傳一個檔（回傳 att_id）。檔名衝突一律改名，不覆蓋別人的檔（比照 ia_attach_add）。 */
function ocr_attach_add(PDO $db, int $reminderId, array $file, int $uid, string $uname): int {
    ocr_ensure_schema($db);
    if (!ocr_get($db, $reminderId)) throw new Exception('查無這筆提醒');
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new Exception('檔案超過伺服器允許的大小');
    if ($err !== UPLOAD_ERR_OK) throw new Exception('檔案上傳失敗（代碼 ' . $err . '）');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) throw new Exception('檔案是空的');
    if ($size > OCR_ATTACH_MAX_BYTES) throw new Exception('單一檔案不可超過 20MB');

    $orig = trim((string)($file['name'] ?? ''));
    if ($orig === '') throw new Exception('取不到檔名');
    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' || in_array($ext, OCR_ATTACH_DENY_EXT, true)) throw new Exception('不接受這種檔案類型：.' . $ext);

    $dir = ocr_attach_dir($db);
    if (!eg_attach_ensure_dir($dir)) throw new Exception('附件資料夾無法建立或無法存取：' . $dir);

    $stamp = date('Ymd_His');
    try { $stamp = (string)$db->query("SELECT DATE_FORMAT(NOW(),'%Y%m%d_%H%i%s')")->fetchColumn(); } catch (Exception $e) {}
    $base = 'reminder' . $reminderId . '_' . $stamp . '_' . bin2hex(random_bytes(3));
    $name = $base . '.' . $ext;
    $i = 1;
    while (is_file($dir . $name)) { $name = $base . '_' . (++$i) . '.' . $ext; }

    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new Exception('來源檔案不正確');
    if (!@move_uploaded_file($tmp, $dir . $name)) throw new Exception('寫入附件資料夾失敗：' . $dir);

    try {
        $db->prepare("INSERT INTO order_client_reminder_attach (reminder_id, file_name, orig_name, file_size, uploaded_by, uploaded_by_name, uploaded_at)
                      VALUES (?,?,?,?,?,?,NOW())")
           ->execute([$reminderId, $name, mb_substr($orig, 0, 255, 'UTF-8'), $size, ($uid ?: null), $uname]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        @unlink($dir . $name);
        throw $e;
    }
}
}

if (!function_exists('ocr_attach_one')) {
function ocr_attach_one(PDO $db, int $attId): ?array {
    ocr_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM order_client_reminder_attach WHERE att_id=? AND is_deleted=0");
    $st->execute([$attId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $fn = basename((string)$r['file_name']);
    if ($fn === '' || $fn !== (string)$r['file_name']) return null; // 只准單純檔名＝鐵律5 最後一道
    $r['fs_path'] = ocr_attach_dir($db) . $fn;
    return $r;
}
}

if (!function_exists('ocr_attach_del')) {
function ocr_attach_del(PDO $db, int $attId): bool {
    $a = ocr_attach_one($db, $attId);
    if (!$a) return false;
    $db->prepare("UPDATE order_client_reminder_attach SET is_deleted=1 WHERE att_id=?")->execute([$attId]);
    if (is_file($a['fs_path'])) @unlink($a['fs_path']);
    return true;
}
}

// ── 選定客戶時要不要跳窗：這張訂單「還沒看過」的有效提醒 ───────────────────
if (!function_exists('ocr_unacked_for_order')) {
/**
 * $orderId<=0（新增中的訂單，尚未存檔）→ 回傳該客戶全部有效提醒（無從判斷有沒有跳過窗）。
 * $orderId>0（既有訂單）→ 只回傳「這則提醒對這張訂單還沒有 ack 紀錄」的那些。
 */
function ocr_unacked_for_order(PDO $db, string $customerId, int $orderId): array {
    ocr_ensure_schema($db);
    $customerId = trim($customerId);
    if ($customerId === '') return [];
    if ($orderId > 0) {
        $st = $db->prepare("SELECT r.* FROM order_client_reminder r
                              WHERE r.customer_id=? AND r.is_active=1
                                AND NOT EXISTS (SELECT 1 FROM order_client_reminder_ack a WHERE a.reminder_id=r.id AND a.order_id=?)
                              ORDER BY r.id");
        $st->execute([$customerId, $orderId]);
    } else {
        $st = $db->prepare("SELECT r.* FROM order_client_reminder r WHERE r.customer_id=? AND r.is_active=1 ORDER BY r.id");
        $st->execute([$customerId]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) $r['attachments'] = ocr_attach_rows($db, (int)$r['id']);
    return $rows;
}
}

// ── 摘要文字（插入業務備註用，受 Order_ps 欄位長度限制） ───────────────────
if (!function_exists('ocr_summary_text')) {
function ocr_summary_text(string $content, string $uname, string $dateShort): string {
    $prefix = '【客戶提醒待處理】';
    $suffix = '（' . $uname . ' ' . $dateShort . ' 設為待處理）';
    $room = OCR_PS_MAX_LEN - mb_strlen($prefix, 'UTF-8') - mb_strlen($suffix, 'UTF-8') - 1; // -1 給換行
    if ($room < 5) $room = 5;
    $snippet = $content;
    if (mb_strlen($snippet, 'UTF-8') > $room) $snippet = mb_substr($snippet, 0, $room, 'UTF-8') . '…';
    return $prefix . $snippet . $suffix;
}
}

// ── ack：跳窗當下先記「已跳出」；確認/設為待處理各自更新狀態 ─────────────────
if (!function_exists('ocr_ack_mark_shown')) {
/** 訂單新增/編輯彈出提醒視窗當下呼叫；只在 order_id>0（既有訂單）時才有意義寫入。 */
function ocr_ack_mark_shown(PDO $db, int $reminderId, int $orderId): void {
    if ($orderId <= 0) return;
    ocr_ensure_schema($db);
    try {
        $db->prepare("INSERT IGNORE INTO order_client_reminder_ack (reminder_id, order_id, status, shown_at) VALUES (?,?,'shown',NOW())")
           ->execute([$reminderId, $orderId]);
    } catch (Exception $e) {}
}
}

if (!function_exists('ocr_ack_confirm')) {
/** 確認：純表態，不動業務備註。order_id 必須是既有訂單（新增中訂單此動作只在前端記錄，存檔後才補寫）。 */
function ocr_ack_confirm(PDO $db, int $reminderId, int $orderId, int $uid, string $uname): bool {
    if (!ocr_get($db, $reminderId)) throw new Exception('查無這則提醒');
    if ($orderId <= 0) throw new Exception('未指定訂單');
    ocr_ensure_schema($db);
    $db->prepare("INSERT INTO order_client_reminder_ack (reminder_id, order_id, status, shown_at, confirmed_by, confirmed_by_name, confirmed_at)
                  VALUES (?,?,'confirmed',NOW(),?,?,NOW())
                  ON DUPLICATE KEY UPDATE status='confirmed', confirmed_by=VALUES(confirmed_by), confirmed_by_name=VALUES(confirmed_by_name), confirmed_at=NOW()")
       ->execute([$reminderId, $orderId, ($uid ?: null), $uname]);
    return true;
}
}

if (!function_exists('ocr_ack_pending')) {
/**
 * 設為待處理：寫 ack、並把摘要插入 order_track.Order_ps（原內容下方）。
 * 回傳 ['order_ps'=>更新後的完整內容, 'inserted'=>是否成功插入, 'reason'=>插入不了的原因]
 */
function ocr_ack_pending(PDO $db, int $reminderId, int $orderId, int $uid, string $uname): array {
    $rem = ocr_get($db, $reminderId);
    if (!$rem) throw new Exception('查無這則提醒');
    if ($orderId <= 0) throw new Exception('未指定訂單');
    ocr_ensure_schema($db);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO order_client_reminder_ack (reminder_id, order_id, status, shown_at, pending_by, pending_by_name, pending_at)
                      VALUES (?,?,'pending',NOW(),?,?,NOW())
                      ON DUPLICATE KEY UPDATE status='pending', pending_by=VALUES(pending_by), pending_by_name=VALUES(pending_by_name), pending_at=NOW(),
                            resolved_by=NULL, resolved_by_name=NULL, resolved_at=NULL")
           ->execute([$reminderId, $orderId, ($uid ?: null), $uname]);

        $st = $db->prepare("SELECT Order_ps FROM order_track WHERE Order_id=? FOR UPDATE");
        $st->execute([$orderId]);
        $cur = (string)($st->fetchColumn() ?: '');
        $dateShort = date('m/d');
        try { $dateShort = (string)$db->query("SELECT DATE_FORMAT(NOW(),'%m/%d')")->fetchColumn(); } catch (Exception $e) {}
        $summary = ocr_summary_text((string)$rem['content'], $uname, $dateShort);
        $sep = ($cur !== '') ? "\n" : '';
        $newVal = $cur . $sep . $summary;
        $inserted = true; $reason = '';
        if (mb_strlen($newVal, 'UTF-8') > OCR_PS_MAX_LEN) {
            // 原內容已經太滿，連精簡摘要都塞不下：不動業務備註，但 ack/待確認狀態照樣成立
            $inserted = false;
            $reason = '業務備註欄位已滿（上限150字），提醒已記入「待確認」，但沒有寫入備註文字，請至客戶提醒查看完整內容。';
            $newVal = $cur;
        } else {
            $db->prepare("UPDATE order_track SET Order_ps=? WHERE Order_id=?")->execute([$newVal, $orderId]);
        }
        $db->commit();
        return ['order_ps' => $newVal, 'inserted' => $inserted, 'reason' => $reason];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
}

if (!function_exists('ocr_ack_resolve')) {
/** 標記「已確認完成」：只有狀態是 pending 的才能標完成 */
function ocr_ack_resolve(PDO $db, int $ackId, int $uid, string $uname): bool {
    ocr_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM order_client_reminder_ack WHERE id=?");
    $st->execute([$ackId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new Exception('查無這筆待確認紀錄');
    if ((string)$a['status'] !== 'pending') throw new Exception('這筆紀錄目前不是待處理狀態，可能已被其他人處理過，請重新整理頁面');
    $db->prepare("UPDATE order_client_reminder_ack SET status='resolved', resolved_by=?, resolved_by_name=?, resolved_at=NOW() WHERE id=? AND status='pending'")
       ->execute([($uid ?: null), $uname, $ackId]);
    return true;
}
}

if (!function_exists('ocr_pending_for_order')) {
/** 這張訂單目前狀態=pending 的提醒清單（給列表「已確認完成」按鈕與挑選用） */
function ocr_pending_for_order(PDO $db, int $orderId): array {
    ocr_ensure_schema($db);
    $st = $db->prepare("SELECT a.id AS ack_id, a.reminder_id, a.pending_by_name,
                                DATE_FORMAT(a.pending_at,'%Y-%m-%d %H:%i') AS pending_at,
                                r.content
                           FROM order_client_reminder_ack a
                           JOIN order_client_reminder r ON r.id = a.reminder_id
                          WHERE a.order_id=? AND a.status='pending'
                          ORDER BY a.id");
    $st->execute([$orderId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

// ── 統計用：「這張訂單目前有沒有待處理中的客戶提醒」EXISTS 片段（給多處 SQL 共用避免走鐘） ──
if (!function_exists('ocr_pending_exists_sql')) {
function ocr_pending_exists_sql(string $orderAlias = 'ot'): string {
    return "EXISTS (SELECT 1 FROM order_client_reminder_ack _ocra WHERE _ocra.order_id = {$orderAlias}.Order_id AND _ocra.status='pending')";
}
}
