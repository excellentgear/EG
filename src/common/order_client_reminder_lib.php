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
// 已確認的料號提示寫進業務備註時的行首記號：以此開頭的行「鎖定」，使用者無法在業務備註刪改（儲存時由後端併回）
if (!defined('OCR_LOCK_MARK')) define('OCR_LOCK_MARK', '✔ ');
// 待處理（尚未確認完成）的提醒行：同樣鎖定不可刪除，確認完成後換成 ✔ 行
if (!defined('OCR_PEND_MARK')) define('OCR_PEND_MARK', '⚑ ');
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

        // 料號提示（2026-11-06）：製程中紀錄勾「提示」後，同一張表多一種「綁在料號」的提醒——
        // part_d_id 有值＝料號提示（新訂單綁定該料號時跳窗），NULL＝原本的客戶提醒（全部既有資料）。
        // 客戶提醒的所有查詢都加 part_d_id IS NULL，兩種互不干擾。MySQL 無 ADD COLUMN IF NOT EXISTS，先查再加。
        $has = $db->query("SHOW COLUMNS FROM order_client_reminder LIKE 'part_d_id'")->fetch();
        if (!$has) {
            foreach ([
                "ALTER TABLE order_client_reminder ADD COLUMN part_d_id INT NULL COMMENT '料號提示：d_setting.d_id；NULL=客戶提醒'",
                "ALTER TABLE order_client_reminder ADD COLUMN src_item_id INT NULL COMMENT '料號提示：來源的製程中紀錄 eng_log_item.id'",
                "ALTER TABLE order_client_reminder ADD COLUMN disabled_reason VARCHAR(500) NULL",
                "ALTER TABLE order_client_reminder ADD COLUMN disabled_by INT NULL",
                "ALTER TABLE order_client_reminder ADD COLUMN disabled_by_name VARCHAR(50) NULL",
                "ALTER TABLE order_client_reminder ADD COLUMN disabled_at DATETIME NULL",
                "ALTER TABLE order_client_reminder ADD INDEX idx_part (part_d_id, is_active)",
                "ALTER TABLE order_client_reminder ADD INDEX idx_src_item (src_item_id)",
            ] as $sqlA) { try { $db->exec($sqlA); } catch (Exception $eA) {} }
        }
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
             WHERE r.customer_id = ? AND r.part_d_id IS NULL" . ($activeOnly ? " AND r.is_active=1" : "") . "
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
                              WHERE r.customer_id=? AND r.is_active=1 AND r.part_d_id IS NULL
                                AND NOT EXISTS (SELECT 1 FROM order_client_reminder_ack a WHERE a.reminder_id=r.id AND a.order_id=?)
                              ORDER BY r.id");
        $st->execute([$customerId, $orderId]);
    } else {
        $st = $db->prepare("SELECT r.* FROM order_client_reminder r WHERE r.customer_id=? AND r.is_active=1 AND r.part_d_id IS NULL ORDER BY r.id");
        $st->execute([$customerId]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) $r['attachments'] = ocr_attach_rows($db, (int)$r['id']);
    return $rows;
}
}

// ── 料號提示（製程中紀錄勾「提示」產生，2026-11-06）────────────────────────
if (!function_exists('ocr_unacked_part_for_order')) {
/** 這個料號目前有效的提示中，這張訂單還沒處理過的（orderId<=0＝新增中訂單，全回）。 */
function ocr_unacked_part_for_order(PDO $db, int $partDid, int $orderId, bool $includeShown = false): array {
    ocr_ensure_schema($db);
    if ($partDid <= 0) return [];
    if ($orderId > 0) {
        $st = $db->prepare("SELECT r.* FROM order_client_reminder r
                             WHERE r.part_d_id=? AND r.is_active=1
                               AND NOT EXISTS (SELECT 1 FROM order_client_reminder_ack a WHERE a.reminder_id=r.id AND a.order_id=?"
                               . ($includeShown ? " AND a.status<>'shown'" : "") . ")
                             ORDER BY r.id");
        $st->execute([$partDid, $orderId]);
    } else {
        $st = $db->prepare("SELECT r.* FROM order_client_reminder r WHERE r.part_d_id=? AND r.is_active=1 ORDER BY r.id");
        $st->execute([$partDid]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

if (!function_exists('ocr_hint_open_map')) {
/**
 * 清單用：這批訂單中，「綁定的料號有啟用中的提示、這張訂單建立在提示之後、尚未轉生管、還沒確認/設為待處理」者。
 * 回傳 Order_id => [['content'=>…,'need'=>是否要求確認],…]。涵蓋所有建單途徑（複製、匯入、跳窗沒處理就關掉…），不只靠選料號當下那一次跳窗。
 */
function ocr_hint_open_map(PDO $db, array $orderIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    ocr_ensure_schema($db);
    $out = [];
    // 所有「綁定料號有啟用中提示」的訂單都列出（含已轉生管、建立在提示之前的）；need=true 才要求確認
    // 停用的提示：從停用當下起不再套用到新訂單；停用之前就存在的訂單照舊顯示（不再要求確認）
    $q = "SELECT o.Order_id, r.content, (r.is_active = 1 AND o.pmGet IS NULL AND (o.Order_status IS NULL OR o.Order_status <> 6) AND o.Created_At >= r.created_at) AS need
            FROM order_track o
            JOIN order_client_reminder r ON r.part_d_id = o.d_id_ID AND r.src_item_id IS NOT NULL
                 AND (r.is_active = 1 OR o.Created_At < r.disabled_at)
           WHERE o.Order_id IN (" . implode(',', $ids) . ")
             AND NOT EXISTS (SELECT 1 FROM order_client_reminder_ack a WHERE a.reminder_id = r.id AND a.order_id = o.Order_id AND a.status <> 'shown')
           ORDER BY r.id";
    foreach ($db->query($q)->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['Order_id']][] = ['content' => (string)$r['content'], 'need' => (int)$r['need'] === 1];
    return $out;
}
}

if (!function_exists('ocr_hint_order_counts')) {
/** 清單用：Order_id => 這張訂單適用的提示數（提示綁料號 ID；停用的只算「停用之前就存在」的訂單） */
function ocr_hint_order_counts(PDO $db, array $orderIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    ocr_ensure_schema($db);
    $out = [];
    foreach ($db->query("SELECT o.Order_id, COUNT(*) n FROM order_track o
                          JOIN order_client_reminder r ON r.part_d_id = o.d_id_ID AND r.src_item_id IS NOT NULL
                               AND (r.is_active = 1 OR o.Created_At < r.disabled_at)
                         WHERE o.Order_id IN (" . implode(',', $ids) . ") GROUP BY o.Order_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['Order_id']] = (int)$r['n'];
    }
    return $out;
}
}

if (!function_exists('ocr_hint_for_order')) {
/** 這張訂單適用的料號提示（含已停用但訂單建立在停用之前的）；排除「來源就在這張訂單」的。 */
function ocr_hint_for_order(PDO $db, int $orderId): array {
    if ($orderId <= 0) return [];
    ocr_ensure_schema($db);
    $st = $db->prepare("SELECT r.id AS rid, r.content, r.is_active, r.src_item_id, r.created_by_name,
                               DATE_FORMAT(r.created_at,'%Y-%m-%d') AS created_date,
                               r.disabled_by_name, r.disabled_reason, DATE_FORMAT(r.disabled_at,'%Y-%m-%d %H:%i') AS disabled_at,
                               i.log_id, i.created_by, src.Order_oo
                          FROM order_track o
                          JOIN order_client_reminder r ON r.part_d_id = o.d_id_ID AND r.src_item_id IS NOT NULL
                               AND (r.is_active = 1 OR o.Created_At < r.disabled_at)
                          JOIN eng_log_item i ON i.id = r.src_item_id AND i.voided_at IS NULL
                          LEFT JOIN eng_log_bind b ON b.log_id = i.log_id AND b.bind_type = 'order'
                          LEFT JOIN order_track src ON src.Order_id = b.bind_id
                         WHERE o.Order_id = ? AND (b.bind_id IS NULL OR b.bind_id <> ?)
                         ORDER BY r.is_active DESC, r.id DESC");
    $st->execute([$orderId, (string)$orderId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

if (!function_exists('ocr_hint_create')) {
/** 由製程中紀錄項目建立一則料號提示；同一項目只會有一則（重複呼叫回傳既有的）。 */
function ocr_hint_create(PDO $db, int $itemId, int $partDid, string $content, int $uid, string $uname): int {
    ocr_ensure_schema($db);
    $content = trim($content);
    if ($content === '') throw new InvalidArgumentException('提示內容不可空白');
    if (mb_strlen($content, 'UTF-8') > 500) $content = mb_substr($content, 0, 500, 'UTF-8');
    $ex = $db->prepare("SELECT id FROM order_client_reminder WHERE src_item_id=? LIMIT 1");
    $ex->execute([$itemId]);
    if ($eid = $ex->fetchColumn()) return (int)$eid;
    $p = $db->prepare("SELECT d_id, Customer_Id FROM d_setting WHERE d_id=?");
    $p->execute([$partDid]);
    $part = $p->fetch(PDO::FETCH_ASSOC);
    if (!$part) throw new InvalidArgumentException('查無這個料號主檔，無法設為提示');
    $db->prepare("INSERT INTO order_client_reminder
        (customer_id, customer_name, content, is_active, part_d_id, src_item_id, created_by, created_by_name, created_at, updated_by, updated_by_name, updated_at)
        VALUES (?,NULL,?,1,?,?,?,?,NOW(),?,?,NOW())")
       ->execute([(string)($part['Customer_Id'] ?? ''), $content, $partDid, $itemId, ($uid ?: null), $uname, ($uid ?: null), $uname]);
    return (int)$db->lastInsertId();
}
}

if (!function_exists('ocr_hint_info')) {
/** 一批製程中紀錄項目的提示狀態：itemId => [rule_id,is_active,disabled_by_name,disabled_at,disabled_reason] */
function ocr_hint_info(PDO $db, array $itemIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    ocr_ensure_schema($db);
    $out = [];
    foreach ($db->query("SELECT id, src_item_id, is_active, disabled_by_name, disabled_reason,
                                DATE_FORMAT(disabled_at,'%Y-%m-%d %H:%i') AS disabled_at
                           FROM order_client_reminder WHERE src_item_id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['src_item_id']] = ['rule_id' => (int)$r['id'], 'is_active' => (int)$r['is_active'],
            'disabled_by_name' => (string)$r['disabled_by_name'], 'disabled_at' => (string)$r['disabled_at'],
            'disabled_reason' => (string)$r['disabled_reason']];
    }
    return $out;
}
}

if (!function_exists('ocr_hint_set_active')) {
/** 停用（備註選填，記錄是誰、什麼時候）／重新啟用。點下去當下以資料庫現況為準，不一致就擋下請重新整理。 */
function ocr_hint_set_active(PDO $db, int $itemId, bool $active, string $note, int $uid, string $uname): void {
    ocr_ensure_schema($db);
    $st = $db->prepare("SELECT id, is_active FROM order_client_reminder WHERE src_item_id=? LIMIT 1");
    $st->execute([$itemId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new InvalidArgumentException('這一筆不是提示，請重新整理');
    $note = trim($note);
    if (mb_strlen($note, 'UTF-8') > 500) throw new InvalidArgumentException('備註請控制在 500 字以內');
    if ($active) {
        if ((int)$r['is_active']) throw new InvalidArgumentException('這則提示已經是啟用狀態，請重新整理看最新狀態');
        $db->prepare("UPDATE order_client_reminder SET is_active=1, updated_by=?, updated_by_name=?, updated_at=NOW() WHERE id=?")
           ->execute([($uid ?: null), $uname, (int)$r['id']]);
        return;
    }
    if (!(int)$r['is_active']) throw new InvalidArgumentException('這則提示已經被停用了，請重新整理看最新狀態');
    $db->prepare("UPDATE order_client_reminder SET is_active=0, disabled_reason=?, disabled_by=?, disabled_by_name=?, disabled_at=NOW(),
                  updated_by=?, updated_by_name=?, updated_at=NOW() WHERE id=?")
       ->execute([($note === '' ? null : $note), ($uid ?: null), $uname, ($uid ?: null), $uname, (int)$r['id']]);
}
}

// ── 摘要文字（插入業務備註用，受 Order_ps 欄位長度限制） ───────────────────
if (!function_exists('ocr_summary_text')) {
function ocr_summary_text(string $content, string $uname, string $dateShort, string $prefix = '【客戶提醒待處理】', string $word = '設為待處理'): string {
    $suffix = '（' . $uname . ' ' . $dateShort . ' ' . $word . '）';
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

if (!function_exists('ocr_ps_lock_merge')) {
/**
 * 業務備註儲存時呼叫：把資料庫裡既有的鎖定行（✔ 開頭）原樣併回最前面，並剔除使用者送來的 ✔ 行
 * （既防刪除也防偽造／重複）。沒有鎖定行時，只是剔除送來的 ✔ 行。
 */
function ocr_ps_lock_merge(PDO $db, int $orderId, ?string $posted): ?string {
    if ($posted === null) return null;
    $locked = [];
    if ($orderId > 0) {
        $st = $db->prepare("SELECT Order_ps FROM order_track WHERE Order_id=?");
        $st->execute([$orderId]);
        foreach (preg_split('/
|
|/', (string)($st->fetchColumn() ?: '')) as $ln) {
            if (strncmp($ln, OCR_LOCK_MARK, strlen(OCR_LOCK_MARK)) === 0 || strncmp($ln, OCR_PEND_MARK, strlen(OCR_PEND_MARK)) === 0) $locked[] = $ln;
        }
    }
    $rest = [];
    foreach (preg_split('/
|
|/', $posted) as $ln) {
        if (strncmp($ln, OCR_LOCK_MARK, strlen(OCR_LOCK_MARK)) !== 0 && strncmp($ln, OCR_PEND_MARK, strlen(OCR_PEND_MARK)) !== 0) $rest[] = $ln;
    }
    $restTxt = trim(implode("
", $rest), "
");
    return implode("
", array_merge($locked, $restTxt === '' ? [] : [$restTxt]));
}
}

if (!function_exists('ocr_ps_split')) {
/** 列表用：把業務備註拆成 [鎖定行陣列, 其餘可編輯文字] */
function ocr_ps_split(string $ps): array {
    $locked = []; $rest = [];
    foreach (preg_split('/
|
|/', $ps) as $ln) {
        if (strncmp($ln, OCR_LOCK_MARK, strlen(OCR_LOCK_MARK)) === 0) $locked[] = substr($ln, strlen(OCR_LOCK_MARK));
        elseif (strncmp($ln, OCR_PEND_MARK, strlen(OCR_PEND_MARK)) === 0) $locked[] = substr($ln, strlen(OCR_PEND_MARK));   // 待處理行（以「【」開頭，畫面另標示）
        else $rest[] = $ln;
    }
    return [$locked, trim(implode("
", $rest), "
")];
}
}

if (!function_exists('ocr_hint_ps_append')) {
/**
 * 料號提示「確認」後，把已確認的提示摘要接在業務備註下方（客戶提醒的確認維持純表態，不動備註）。
 * 回傳更新後的 Order_ps；不是料號提示／已寫過／欄位放不下 → 回傳 null（不動備註）。
 */
function ocr_hint_ps_append(PDO $db, int $reminderId, int $orderId, string $uname, bool $force = false): ?string {
    $rem = ocr_get($db, $reminderId);
    if (!$rem || (empty($rem['part_d_id']) && !$force) || $orderId <= 0) return null;
    $st = $db->prepare("SELECT Order_ps FROM order_track WHERE Order_id=?");
    $st->execute([$orderId]);
    $cur = $st->fetchColumn();
    if ($cur === false) return null;
    $cur = (string)($cur ?: '');
    $tag = OCR_LOCK_MARK;
    $head = mb_substr(trim((string)$rem['content']), 0, 8, 'UTF-8');
    if (mb_strpos($cur, $tag . $head, 0, 'UTF-8') !== false) return $cur;   // 同一則已寫過，不重複
    $dateShort = (string)$db->query("SELECT DATE_FORMAT(NOW(),'%m/%d')")->fetchColumn();
    $new = $cur . ($cur !== '' ? "
" : '') . ocr_summary_text((string)$rem['content'], $uname, $dateShort, $tag, '確認');
    if (mb_strlen($new, 'UTF-8') > OCR_PS_MAX_LEN) return null;
    $db->prepare("UPDATE order_track SET Order_ps=? WHERE Order_id=?")->execute([$new, $orderId]);
    return $new;
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
        $summary = ocr_summary_text((string)$rem['content'], $uname, $dateShort,
            !empty($rem['part_d_id']) ? '【料號提示待處理】' : '【客戶提醒待處理】');
        $sep = ($cur !== '') ? "\n" : '';
        $newVal = $cur . $sep . OCR_PEND_MARK . $summary;   // 鎖定行，不可從備註欄刪除；確認完成後換成 ✔ 行
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
    // 把業務備註裡「【客戶提醒／料號提示待處理】…」那行換成鎖定的「✔ …（誰 日期 確認）」（不可刪、顯示已確認資料）
    try {
        $rem = ocr_get($db, (int)$a['reminder_id']);
        $oid = (int)$a['order_id'];
        if ($rem && $oid > 0) {
            $head = mb_substr(trim((string)$rem['content']), 0, 8, 'UTF-8');
            $pfxs = ['【料號提示待處理】' . $head, '【客戶提醒待處理】' . $head];
            $s = $db->prepare("SELECT Order_ps FROM order_track WHERE Order_id=?");
            $s->execute([$oid]);
            $cur = $s->fetchColumn();
            if ($cur !== false) {
                $keep = [];
                foreach (preg_split('/\r\n|\r|\n/', (string)($cur ?: '')) as $ln) {
                    $t = (strncmp($ln, OCR_PEND_MARK, strlen(OCR_PEND_MARK)) === 0) ? substr($ln, strlen(OCR_PEND_MARK)) : $ln;
                    $hit = false;
                    foreach ($pfxs as $pf) { if (mb_strpos($t, $pf, 0, 'UTF-8') === 0) { $hit = true; break; } }
                    if ($hit) continue;
                    $keep[] = $ln;
                }
                $db->prepare("UPDATE order_track SET Order_ps=? WHERE Order_id=?")
                   ->execute([trim(implode("\n", $keep), "\n"), $oid]);
                ocr_hint_ps_append($db, (int)$a['reminder_id'], $oid, $uname, true);
            }
        }
    } catch (Exception $e) {}
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
