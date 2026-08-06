<?php
// Order_Attachment_API.php — 訂單附件管理 API（新增訂單/OP轉訂單皆可用；簡易版，不含補件審核）
// 類別標籤共用報價單既有的 quotation_file_categories（使用者明確要求，不另建類別表）
// 儲存路徑走全站共用 attach_lib.php（設定鍵 order_attach_dir，預設 根目錄\訂單\，扁平資料夾不分子目錄）
session_start();
if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action !== 'download') {
    header('Content-Type: application/json; charset=utf-8');
}

include '../common/DBConnection.php';
require_once '../common/attach_lib.php';
require_once '../common/rbac.php';
$db  = new DBConnection();
$pdo = $db->getPDO();

$uid = (int)($_SESSION['id'] ?? 0);

// 訂單編輯權限：本頁目前仍是舊版 permission_code 機制在把關（$OT_USE_RBAC 尚未啟用，
// user_roles/role_features 對大多數使用者是空的），不能用 rbac_user_features 判斷，否則一律403。
// 沿用 NewOrder_Track222.php 寫入 session 的權限快取（_OrderChange_API.php 也是這樣沿用，見該檔 oc_perm_code）。
function _oaPermCode(int $uid): string {
    $key = 'perm_code_newordertrack_' . $uid;
    return (isset($_SESSION[$key]) && is_string($_SESSION[$key])) ? $_SESSION[$key] : '';
}
function _oaCanEdit(PDO $pdo, int $uid): bool {
    $code = _oaPermCode($uid);
    if ($code !== '') return $code === 'A' || strpos($code, 'U') !== false;
    // 沒有快取（例如尚未開過訂單頁就直接呼叫）：退回 RBAC 判斷，仍找不到就寬鬆放行避免鎖死
    try {
        $feats = rbac_user_features($pdo, $uid);
        return rbac_has($feats, 'all') || rbac_has($feats, 'ot_edit');
    } catch (Exception $e) { return true; }
}
function _oaIsAdmin(PDO $pdo, int $uid): bool {
    if (_oaPermCode($uid) === 'A') return true;
    if (in_array((int)($_SESSION['status'] ?? 0), [9, 90], true)) return true;
    try { return rbac_has(rbac_user_features($pdo, $uid), 'all'); }
    catch (Exception $e) { return true; }
}

// 本頁使用的附件標籤子集（避免共用類別表全部~16筆一次列出很混亂）；
// 未設定過（system_settings 沒有這筆或空字串）＝尚未客製化，顯示全部類別
function oaEnabledCatIds(PDO $pdo): ?array {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='order_attach_enabled_cats'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v === false || $v === null || trim((string)$v) === '') return null;
        return array_values(array_filter(array_map('intval', explode(',', $v))));
    } catch (Exception $e) { return null; }
}

function oaFmtSize(int $bytes): string {
    if ($bytes < 1024)      return $bytes . ' B';
    if ($bytes < 1024*1024) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1024/1024, 1) . ' MB';
}

function oaInitTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_attachments (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            order_id       INT NOT NULL DEFAULT 0 COMMENT '0=尚未歸屬(暫存中)',
            batch_key      VARCHAR(40)  NULL COMMENT '暫存階段識別碼：新增訂單/OP轉訂單畫面開啟時產生',
            linked_part_no VARCHAR(50)  NULL COMMENT '只對應批次裡某個料號；NULL=共用(單一料號情境自動視為該料號)',
            category_ids   VARCHAR(255) NULL COMMENT '逗號分隔，對應 quotation_file_categories.id（共用類別表）',
            filename       VARCHAR(255) NOT NULL,
            original_name  VARCHAR(255) NULL,
            file_size      VARCHAR(20)  NULL,
            uploaded_by    INT NULL,
            uploaded_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            status         VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT 'temp/active',
            expire_at      DATETIME NULL,
            INDEX idx_order (order_id),
            INDEX idx_batch (batch_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單附件（含OP轉訂單暫存批次）';
    ");
}

// 懶惰清除：已到期的暫存(temp)附件（實體檔＋DB列）
function oaPurgeExpired(PDO $pdo, string $dir): void {
    try {
        $rows = $pdo->query("SELECT id, filename FROM order_attachments WHERE status='temp' AND expire_at IS NOT NULL AND expire_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return; }
    if (!$rows) return;
    $del = $pdo->prepare("DELETE FROM order_attachments WHERE id=?");
    foreach ($rows as $r) {
        $fp = $dir . $r['filename'];
        if (is_file($fp)) @unlink($fp);
        try { $del->execute([$r['id']]); } catch (Exception $e) {}
    }
}

oaInitTable($pdo);
$dir = eg_attach_dir($pdo, 'order_attach_dir', '訂單');
eg_attach_ensure_dir($dir);
oaPurgeExpired($pdo, $dir);

switch ($action) {

    // ── 取得類別清單（共用報價單的 quotation_file_categories；依本頁客製化子集過濾）──
    case 'get_categories':
        try {
            $enabled = oaEnabledCatIds($pdo);
            if ($enabled !== null && !$enabled) {
                $rows = []; // 已客製化但一個都沒勾（極少見）：不擋，顯示空清單即可
            } else {
                $sql = "SELECT id, category_name, sort_order FROM quotation_file_categories WHERE is_active=1";
                $par = [];
                if ($enabled !== null) {
                    $ph = implode(',', array_fill(0, count($enabled), '?'));
                    $sql .= " AND id IN ($ph)";
                    $par = $enabled;
                }
                $sql .= " ORDER BY sort_order, id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($par);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) { $rows = []; }
        echo json_encode(['success' => true, 'categories' => $rows]);
        break;

    // ── 本頁使用的附件標籤設定：取得全部類別＋目前已啟用的子集（設定跳窗用）──
    case 'get_categories_setting': {
        try {
            $all = $pdo->query("SELECT id, category_name, sort_order FROM quotation_file_categories WHERE is_active=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $all = []; }
        $enabled = oaEnabledCatIds($pdo);
        echo json_encode(['success' => true, 'categories' => $all, 'enabled_ids' => $enabled, 'customized' => $enabled !== null]);
        break;
    }
    // ── 儲存本頁使用的附件標籤子集（僅管理員）───────────────────
    case 'save_categories_setting': {
        if (!_oaIsAdmin($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'僅管理員可設定']); break; }
        $ids = array_values(array_filter(array_map('intval', explode(',', trim($_POST['category_ids'] ?? '')))));
        $val = implode(',', $ids); // 允許存空字串＝客製化但目前一個都沒勾
        $uname = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? 'system');
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                       VALUES ('order_attach_enabled_cats', ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                          updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by), updated_at=NOW()")
            ->execute([$val, ($uid ?: null), $uname]);
        echo json_encode(['success' => true, 'message' => '已儲存']);
        break;
    }

    // ── 上傳檔案 ─────────────────────────────────────────────
    case 'upload_file': {
        if (!_oaCanEdit($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無訂單編輯權限']); break; }
        $orderId  = intval($_POST['order_id'] ?? 0);
        $batchKey = trim($_POST['batch_key'] ?? '');
        $linkPart = trim($_POST['linked_part_no'] ?? '') ?: null;
        // 附件標籤鐵則（CLAUDE.md 鐵律8）：允許先批次上傳再逐一點開設定標籤，但存檔/建單前
        // 一律要補齊（見 _NewOrder_Track222.php 的 or_new/or_update/create_orders_from_quotes 檢查）
        $catIds = array_values(array_filter(array_map('intval', explode(',', trim($_POST['category_ids'] ?? '')))));
        $catStr = $catIds ? implode(',', $catIds) : null;
        if ($orderId <= 0 && $batchKey === '') { echo json_encode(['success'=>false,'message'=>'缺少訂單ID或暫存批次碼']); break; }
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'message'=>'上傳失敗（檔案錯誤）']); break;
        }
        $orig = basename($_FILES['file']['name']);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $blocked = ['php','php3','php4','php5','phtml','phar','exe','bat','sh','cmd','asp','aspx','jsp','py','rb','htaccess'];
        if ($ext === '' || in_array($ext, $blocked, true)) { echo json_encode(['success'=>false,'message'=>'不允許此檔案類型']); break; }
        $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) { echo json_encode(['success'=>false,'message'=>'檔案寫入失敗']); break; }
        $sizeStr = oaFmtSize((int)$_FILES['file']['size']);
        if ($orderId > 0) {
            $pdo->prepare("INSERT INTO order_attachments (order_id, linked_part_no, category_ids, filename, original_name, file_size, uploaded_by, status)
                           VALUES (?,?,?,?,?,?,?,'active')")
                ->execute([$orderId, $linkPart, $catStr, $fname, $orig, $sizeStr, $uid]);
        } else {
            $pdo->prepare("INSERT INTO order_attachments (order_id, batch_key, linked_part_no, category_ids, filename, original_name, file_size, uploaded_by, status, expire_at)
                           VALUES (0,?,?,?,?,?,?,?,'temp', DATE_ADD(NOW(), INTERVAL 2 DAY))")
                ->execute([$batchKey, $linkPart, $catStr, $fname, $orig, $sizeStr, $uid]);
        }
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;
    }

    // ── 列出附件（依 order_id 或 batch_key）──────────────────
    case 'list_files': {
        $orderId  = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
        $batchKey = trim($_POST['batch_key'] ?? $_GET['batch_key'] ?? '');
        if ($orderId <= 0 && $batchKey === '') { echo json_encode(['success'=>true,'files'=>[]]); break; }
        if ($orderId > 0) {
            $stmt = $pdo->prepare("SELECT a.id, a.filename, a.original_name, a.file_size, a.category_ids, a.linked_part_no, a.status,
                                          DATE_FORMAT(a.uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at
                                   FROM order_attachments a WHERE a.order_id=? AND a.status='active' ORDER BY a.id");
            $stmt->execute([$orderId]);
        } else {
            $stmt = $pdo->prepare("SELECT a.id, a.filename, a.original_name, a.file_size, a.category_ids, a.linked_part_no, a.status,
                                          DATE_FORMAT(a.uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at
                                   FROM order_attachments a WHERE a.batch_key=? AND a.status='temp' ORDER BY a.id");
            $stmt->execute([$batchKey]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $catMap = [];
        try {
            foreach ($pdo->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $catMap[(int)$c['id']] = $c['category_name'];
            }
        } catch (Exception $e) {}
        foreach ($rows as &$r) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$r['category_ids']))));
            $r['category_name'] = implode('、', array_map(fn($i) => $catMap[$i] ?? ('#'.$i), $ids));
        }
        unset($r);
        echo json_encode(['success' => true, 'files' => $rows]);
        break;
    }

    // ── 更新附件的類別與料號連結 ──────────────────────────────
    case 'update_attachment': {
        if (!_oaCanEdit($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無訂單編輯權限']); break; }
        $attId    = intval($_POST['attachment_id'] ?? 0);
        $rawCats  = trim($_POST['category_ids'] ?? '');
        $catIds   = array_values(array_filter(array_map('intval', explode(',', $rawCats))));
        $linkPart = trim($_POST['linked_part_no'] ?? '') ?: null;
        if (!$attId) { echo json_encode(['success'=>false,'message'=>'缺少 attachment_id']); break; }
        if (!$catIds) { echo json_encode(['success'=>false,'message'=>'請至少保留一個附件類別標籤']); break; }
        $catStr = implode(',', $catIds);
        $pdo->prepare("UPDATE order_attachments SET category_ids=?, linked_part_no=? WHERE id=?")
            ->execute([$catStr, $linkPart, $attId]);
        echo json_encode(['success' => true]);
        break;
    }

    // ── 刪除附件 ─────────────────────────────────────────────
    case 'delete_file': {
        $attId = intval($_POST['attachment_id'] ?? 0);
        if (!$attId) { echo json_encode(['success'=>false,'message'=>'缺少 attachment_id']); break; }
        $st = $pdo->prepare("SELECT filename, status, uploaded_by FROM order_attachments WHERE id=?");
        $st->execute([$attId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'message'=>'找不到附件']); break; }
        // temp 限本人可刪；active 走訂單編輯權限
        $allowed = ($row['status'] === 'temp') ? ((int)$row['uploaded_by'] === $uid) : _oaCanEdit($pdo, $uid);
        if (!$allowed) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無刪除權限']); break; }
        $fp = $dir . $row['filename'];
        if (is_file($fp)) @unlink($fp);
        $pdo->prepare("DELETE FROM order_attachments WHERE id=?")->execute([$attId]);
        echo json_encode(['success' => true, 'message' => '已刪除']);
        break;
    }

    // ── 下載 / 預覽 ─────────────────────────────────────────
    case 'download': {
        $attId = intval($_GET['id'] ?? 0);
        if (!$attId) { http_response_code(404); echo '參數錯誤'; exit; }
        $st = $pdo->prepare("SELECT filename, original_name FROM order_attachments WHERE id=?");
        $st->execute([$attId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo '檔案不存在'; exit; }
        $fp = $dir . $row['filename'];
        if (!is_file($fp)) { http_response_code(404); echo '檔案不存在'; exit; }
        while (ob_get_level()) ob_end_clean();
        $ext  = strtolower(pathinfo($row['filename'], PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'          => 'application/pdf',
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            'gif'          => 'image/gif',
            'xlsx', 'xls'  => 'application/vnd.ms-excel',
            'docx', 'doc'  => 'application/msword',
            default        => 'application/octet-stream',
        };
        $dispName = $row['original_name'] ?: $row['filename'];
        header('Content-Type: ' . $mime);
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($dispName));
        header('Content-Length: ' . filesize($fp));
        header('Cache-Control: private, max-age=3600');
        readfile($fp);
        exit;
    }

    // ── 儲存路徑設定（僅管理員）───────────────────────────────
    case 'get_settings': {
        $v = '';
        try {
            $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='order_attach_dir'");
            $st->execute();
            $v = (string)($st->fetchColumn() ?: '');
        } catch (Exception $e) {}
        echo json_encode(['success' => true, 'path' => $v, 'resolved_dir' => $dir]);
        break;
    }
    case 'save_settings': {
        if (!_oaIsAdmin($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'僅管理員可設定']); break; }
        $val   = trim($_POST['path'] ?? '');
        $uname = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? 'system');
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                       VALUES ('order_attach_dir', ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                          updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by), updated_at=NOW()")
            ->execute([$val, ($uid ?: null), $uname]);
        echo json_encode(['success' => true, 'message' => '已儲存']);
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => '未知操作：' . $action]);
}
