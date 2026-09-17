<?php
// Order_Attachment_API.php — 訂單附件管理 API（新增訂單/OP轉訂單皆可用；簡易版，不含補件審核）
// 類別標籤共用報價單既有的 quotation_file_categories（使用者明確要求，不另建類別表）
// 儲存路徑走全站共用 attach_lib.php（設定鍵 order_attach_dir，預設 根目錄\訂單\，扁平資料夾不分子目錄）
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action !== 'download') {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../common/DBConnection.php';   // 2026-08-24 改 require_once＋__DIR__：api_guard 已先載入過，用 include 會二次宣告 class 直接 500
require_once '../common/attach_lib.php';
require_once '../common/rbac.php';
require_once __DIR__ . '/../common/order_attach_cat_lib.php';   // 「需綁定料號」的類別判定（唯一實作）
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
// 刪除他人附件（ot_attach_delete，角色設定內可另外指派）：舊制沒有這麼細的字元可對應，只能靠 RBAC 角色。
// 查詢失敗一律當作沒有這項額外授權（跟 _oaIsAdmin/_oaCanEdit 故意放行避免鎖死的方向相反——這是新增的
// 破例授權，寧可查不到就不給，不能反過來變成大家都能刪別人的附件）。
function _oaHasAttachDelete(PDO $pdo, int $uid): bool {
    try { return rbac_has(rbac_user_features($pdo, $uid), 'ot_attach_delete'); }
    catch (Exception $e) { return false; }
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

// 這份附件所屬的「訂單編號」底下目前有哪些料號（挑選跳窗的候選清單／後端守門都用這一份）。
// 附件可能還在暫存批次(batch_key)階段（訂單都還沒建立），那時候料號由前端帶進來，這裡回空陣列。
function oaSiblingParts(PDO $pdo, int $attId): array {
    try {
        $st = $pdo->prepare("SELECT ot.Order_oo FROM order_attachments a
                             JOIN order_track ot ON ot.Order_id = a.order_id WHERE a.id=?");
        $st->execute([$attId]);
        $oo = $st->fetchColumn();
        if (!$oo) return [];
        $st2 = $pdo->prepare("SELECT DISTINCT d_id FROM order_track WHERE Order_oo=? AND d_id<>'' ORDER BY d_id");
        $st2->execute([$oo]);
        return $st2->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { return []; }
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
    // 一份附件可綁多個料號（2026-09-11）：單一料號仍原樣存字串，多個才存 JSON 陣列，
    // 所以欄位要放得下（原本 VARCHAR(50) 只夠一個）。可重複執行。
    try {
        $len = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_attachments'
                              AND COLUMN_NAME='linked_part_no'")->fetchColumn();
        if ($len !== false && (int)$len < 1000) {
            $pdo->exec("ALTER TABLE order_attachments MODIFY linked_part_no VARCHAR(1000) NULL
                        COMMENT '對應料號：NULL=共用(全部)；單一料號=原樣字串；多料號=JSON陣列'");
        }
    } catch (Exception $e) {}
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
        // 標記「需綁定料號」的類別（本頁自己的設定；未設定過時沿用報價單 required_attach_cats）：
        // 這些類別在批次真的有多種料號時不可設為共用，前端用來顯示 * 提示與擋料號選擇未填的送出
        $reqIds = eg_oa_require_part_cat_ids($pdo);
        foreach ($rows as &$r) { $r['required'] = in_array((int)$r['id'], $reqIds, true); }
        unset($r);
        echo json_encode(['success' => true, 'categories' => $rows]);
        break;

    // ── 本頁使用的附件標籤設定：取得全部類別＋目前已啟用的子集＋「需綁定料號」的子集（設定跳窗用）──
    case 'get_categories_setting': {
        try {
            $all = $pdo->query("SELECT id, category_name, sort_order FROM quotation_file_categories WHERE is_active=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $all = []; }
        $enabled = oaEnabledCatIds($pdo);
        // 需綁定料號：回傳「目前實際生效」的清單（未客製化時＝報價單那份），前端就照這份打勾，
        // 所以管理員即使只是來改別的東西、順手按了儲存，寫回去的也是同一組值＝行為不會被動改變。
        $reqOwn = eg_oa_require_part_setting($pdo);
        echo json_encode([
            'success'                 => true,
            'categories'              => $all,
            'enabled_ids'             => $enabled,
            'customized'              => $enabled !== null,
            'require_part_ids'        => eg_oa_require_part_cat_ids($pdo),
            'require_part_customized' => $reqOwn !== null,
        ]);
        break;
    }
    // ── 儲存本頁使用的附件標籤子集＋「需綁定料號」子集（僅管理員）──
    case 'save_categories_setting': {
        if (!_oaIsAdmin($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'僅管理員可設定']); break; }
        $ids = array_values(array_filter(array_map('intval', explode(',', trim($_POST['category_ids'] ?? '')))));
        $val = implode(',', $ids); // 允許存空字串＝客製化但目前一個都沒勾
        $uname = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? 'system');
        $save = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                       VALUES (?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                          updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by), updated_at=NOW()");
        $save->execute(['order_attach_enabled_cats', $val, ($uid ?: null), $uname]);
        // 需綁定料號：舊版前端不會送這個欄位，沒送就完全不動這一列（維持沿用報價單那份），
        // 不可以把「沒送」當成「一個都不勾」——那會在舊分頁按一次儲存就把設定整個清掉。
        if (array_key_exists('require_part_cat_ids', $_POST)) {
            $rIds = array_values(array_filter(array_map('intval', explode(',', trim((string)$_POST['require_part_cat_ids'])))));
            $save->execute(['order_attach_require_part_cats', implode(',', $rIds), ($uid ?: null), $uname]);
        }
        echo json_encode(['success' => true, 'message' => '已儲存']);
        break;
    }

    // ── 上傳檔案 ─────────────────────────────────────────────
    case 'upload_file': {
        if (!_oaCanEdit($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無訂單編輯權限']); break; }
        $orderId  = intval($_POST['order_id'] ?? 0);
        $batchKey = trim($_POST['batch_key'] ?? '');
        $linkPart = eg_oa_parts_encode(eg_oa_parts_from_post($_POST));   // 可複選料號（空＝共用）
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
                           VALUES (0,?,?,?,?,?,?,?,'temp', DATE_ADD(NOW(), INTERVAL 3 DAY))")
                ->execute([$batchKey, $linkPart, $catStr, $fname, $orig, $sizeStr, $uid]);
        }
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;
    }

    // ── 列出附件（依 order_id 或 batch_key，兩者可同時給）──────
    // 編輯既有訂單時：既有正式附件(order_id)＋這次編輯中新上傳、尚未按更新前的暫存附件(batch_key)要合併顯示，
    // 上傳一律先進暫存，按「確認更新/新增」才轉正歸屬到這張訂單；不存檔就關閉視窗＝暫存到期自動清除（見鐵律5）。
    case 'list_files': {
        $orderId  = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
        $batchKey = trim($_POST['batch_key'] ?? $_GET['batch_key'] ?? '');
        if ($orderId <= 0 && $batchKey === '') { echo json_encode(['success'=>true,'files'=>[]]); break; }
        $rows = [];
        if ($orderId > 0) {
            $stmt = $pdo->prepare("SELECT a.id, a.filename, a.original_name, a.file_size, a.category_ids, a.linked_part_no, a.status, a.uploaded_by,
                                          COALESCE(u.user_cname,'') AS uploader_name,
                                          DATE_FORMAT(a.uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at
                                   FROM order_attachments a
                                   LEFT JOIN user u ON u.id = a.uploaded_by
                                   WHERE a.order_id=? AND a.status='active' ORDER BY a.id");
            $stmt->execute([$orderId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($batchKey !== '') {
            $stmt2 = $pdo->prepare("SELECT a.id, a.filename, a.original_name, a.file_size, a.category_ids, a.linked_part_no, a.status, a.uploaded_by,
                                           COALESCE(u.user_cname,'') AS uploader_name,
                                           DATE_FORMAT(a.uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at
                                    FROM order_attachments a
                                    LEFT JOIN user u ON u.id = a.uploaded_by
                                    WHERE a.batch_key=? AND a.status='temp' ORDER BY a.id");
            $stmt2->execute([$batchKey]);
            $rows = array_merge($rows, $stmt2->fetchAll(PDO::FETCH_ASSOC));
        }
        $catMap = [];
        try {
            foreach ($pdo->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $catMap[(int)$c['id']] = $c['category_name'];
            }
        } catch (Exception $e) {}
        // 自動連動標示（使用者明確要求，2026-08-12）：同一實體檔名(filename)在 status='active' 底下出現超過
        // 一筆＝這份附件被自動同步掛到多張訂單（OP轉訂單批次共用／同訂單編號跨料號自動連動），前端要標示出來，
        // 且刪除時（delete_file）會連動整批一起刪，不能讓使用者誤以為只刪了自己看到的這一份。
        $sharedSet = [];
        $activeFilenames = array_values(array_unique(array_column(array_filter($rows, fn($r) => $r['status'] === 'active'), 'filename')));
        if ($activeFilenames) {
            $ph = implode(',', array_fill(0, count($activeFilenames), '?'));
            $sq = $pdo->prepare("SELECT filename FROM order_attachments WHERE status='active' AND filename IN ($ph) GROUP BY filename HAVING COUNT(*) > 1");
            $sq->execute($activeFilenames);
            $sharedSet = array_flip($sq->fetchAll(PDO::FETCH_COLUMN));
        }
        foreach ($rows as &$r) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$r['category_ids']))));
            $r['category_name'] = implode('、', array_map(fn($i) => $catMap[$i] ?? ('#'.$i), $ids));
            $r['is_shared'] = isset($sharedSet[$r['filename']]);
            // 多料號綁定：前端一律讀這個陣列，不要自己去 parse linked_part_no（單一料號存純字串、
            // 多料號存 JSON，解析規則只有 order_attach_cat_lib.php 那一份）
            $r['linked_parts'] = eg_oa_parts_decode($r['linked_part_no']);
        }
        unset($r);
        // 刪除按鈕顯示用：只有上傳者本人／管理員／被指派 ot_attach_delete 角色功能才看得到刪除鈕（後端 delete_file 同規則再擋一次）
        $canDeleteOthers = _oaIsAdmin($pdo, $uid) || _oaHasAttachDelete($pdo, $uid);
        echo json_encode(['success' => true, 'files' => $rows, 'current_uid' => $uid, 'can_delete_others' => $canDeleteOthers]);
        break;
    }

    // ── 更新附件的類別與料號連結 ──────────────────────────────
    case 'update_attachment': {
        if (!_oaCanEdit($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無訂單編輯權限']); break; }
        $attId    = intval($_POST['attachment_id'] ?? 0);
        $rawCats  = trim($_POST['category_ids'] ?? '');
        $catIds   = array_values(array_filter(array_map('intval', explode(',', $rawCats))));
        $parts    = eg_oa_parts_from_post($_POST);
        if (!$attId) { echo json_encode(['success'=>false,'message'=>'缺少 attachment_id']); break; }
        if (!$catIds) { echo json_encode(['success'=>false,'message'=>'請至少保留一個附件類別標籤']); break; }
        // 「需綁定料號」的標籤不可以設成共用（鐵律8：前端擋一次、後端同規則再擋一次）。
        // 只在這張附件真的隸屬多料號情境時才擋；單一料號沒有歧義（見 oaSiblingParts）。
        $reqIds = eg_oa_require_part_cat_ids($pdo);
        if (!$parts && eg_oa_cats_need_part(implode(',', $catIds), $reqIds)) {
            $sib = oaSiblingParts($pdo, $attId);
            if (count($sib) > 1) {
                echo json_encode(['success'=>false,'message'=>'這個標籤已設定為「需綁定料號」，必須指定對應料號，不可設為共用（全部）。']);
                break;
            }
        }
        $catStr = implode(',', $catIds);
        $pdo->prepare("UPDATE order_attachments SET category_ids=?, linked_part_no=? WHERE id=?")
            ->execute([$catStr, eg_oa_parts_encode($parts), $attId]);
        echo json_encode(['success' => true]);
        break;
    }

    // ── 這份附件目前對應哪些料號＋同一訂單編號底下有哪些料號可選（料號挑選跳窗用）──
    case 'part_options': {
        $attId = intval($_POST['attachment_id'] ?? 0);
        if (!$attId) { echo json_encode(['success'=>false,'message'=>'缺少 attachment_id']); break; }
        $st = $pdo->prepare("SELECT a.linked_part_no, a.category_ids, a.filename, a.original_name, a.order_id,
                                    COALESCE(ot.Order_oo,'') AS order_oo
                             FROM order_attachments a
                             LEFT JOIN order_track ot ON ot.Order_id = a.order_id WHERE a.id=?");
        $st->execute([$attId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'message'=>'找不到附件']); break; }
        $reqIds = eg_oa_require_part_cat_ids($pdo);
        // 同一訂單編號底下每個料號目前有沒有掛這份檔案（畫面要標出「目前已連動」）
        $have = [];
        if ($row['order_oo'] !== '') {
            $hq = $pdo->prepare("SELECT DISTINCT ot.d_id FROM order_attachments a2
                                 JOIN order_track ot ON ot.Order_id = a2.order_id
                                 WHERE a2.status='active' AND a2.filename=? AND ot.Order_oo=?");
            $hq->execute([$row['filename'], $row['order_oo']]);
            $have = $hq->fetchAll(PDO::FETCH_COLUMN);
        }
        echo json_encode([
            'success'       => true,
            'order_oo'      => $row['order_oo'],
            'display_name'  => $row['original_name'] ?: $row['filename'],
            'all_parts'     => oaSiblingParts($pdo, $attId),
            'linked_parts'  => eg_oa_parts_decode($row['linked_part_no']),
            'attached_parts'=> $have,
            'need_part'     => eg_oa_cats_need_part($row['category_ids'], $reqIds),
        ]);
        break;
    }

    // ── 套用料號綁定：依選定的料號，增刪這份檔案在「同一訂單編號」底下各張訂單的掛載 ──
    // 使用者明確要求的規則（2026-09-11）：有選定綁定料號的附件，就只跟被綁定的料號一起連動，其他不連動。
    // 所以這裡是「以選定清單為準」＝沒選到的料號要把掛載拿掉、選到卻還沒掛的要補上。
    // **實體檔案永遠不刪**（那是 delete_file 的事），這裡只動「哪張訂單看得到」。
    case 'apply_parts': {
        if (!_oaCanEdit($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無訂單編輯權限']); break; }
        $attId = intval($_POST['attachment_id'] ?? 0);
        $parts = eg_oa_parts_from_post($_POST);
        if (!$attId) { echo json_encode(['success'=>false,'message'=>'缺少 attachment_id']); break; }
        $st = $pdo->prepare("SELECT a.*, COALESCE(ot.Order_oo,'') AS order_oo FROM order_attachments a
                             LEFT JOIN order_track ot ON ot.Order_id = a.order_id WHERE a.id=?");
        $st->execute([$attId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'message'=>'找不到附件']); break; }
        $oo = $row['order_oo'];
        if ($oo === '') {  // 還在暫存批次階段：沒有訂單可增刪，只記綁定值，建單時才分派
            $pdo->prepare("UPDATE order_attachments SET linked_part_no=? WHERE id=?")
                ->execute([eg_oa_parts_encode($parts), $attId]);
            echo json_encode(['success'=>true,'added'=>0,'removed'=>0,'message'=>'已記錄對應料號']);
            break;
        }
        $reqIds = eg_oa_require_part_cat_ids($pdo);
        $allParts = oaSiblingParts($pdo, $attId);
        if (!$parts && count($allParts) > 1 && eg_oa_cats_need_part($row['category_ids'], $reqIds)) {
            echo json_encode(['success'=>false,'message'=>'這個標籤已設定為「需綁定料號」，必須至少指定一個料號。']);
            break;
        }
        // 送進來的料號一定要真的在這個訂單編號底下（不可以直打 API 把附件掛到別張訂單去）
        foreach ($parts as $p) {
            if (!in_array($p, $allParts, true)) {
                echo json_encode(['success'=>false,'message'=>'料號 ' . $p . ' 不在訂單編號 ' . $oo . ' 底下，無法綁定']);
                break 2;
            }
        }
        $keep = $parts ?: $allParts;   // 沒選＝共用（全部）：該訂單編號底下每個料號都要看得到

        $oq = $pdo->prepare("SELECT Order_id, d_id FROM order_track WHERE Order_oo=?");
        $oq->execute([$oo]);
        $orders = $oq->fetchAll(PDO::FETCH_ASSOC);
        $cq = $pdo->prepare("SELECT a.id, a.order_id, ot.d_id FROM order_attachments a
                             JOIN order_track ot ON ot.Order_id = a.order_id
                             WHERE a.status='active' AND a.filename=? AND ot.Order_oo=?");
        $cq->execute([$row['filename'], $oo]);
        $cur = $cq->fetchAll(PDO::FETCH_ASSOC);

        $encoded = eg_oa_parts_encode($parts);
        $added = 0; $removed = 0;
        $pdo->beginTransaction();
        try {
            // 1) 拿掉不該看到的（只刪掛載列，實體檔與其他料號的掛載都留著）
            $del = $pdo->prepare("DELETE FROM order_attachments WHERE id=?");
            $haveOrder = [];
            foreach ($cur as $c) {
                if (!in_array($c['d_id'], $keep, true)) { $del->execute([$c['id']]); $removed++; continue; }
                $haveOrder[(int)$c['order_id']] = (int)$c['id'];
            }
            // 2) 補上還沒掛的
            $ins = $pdo->prepare("INSERT INTO order_attachments (order_id, linked_part_no, category_ids, filename, original_name, file_size, uploaded_by, uploaded_at, status)
                                  VALUES (?,?,?,?,?,?,?,?,'active')");
            foreach ($orders as $o) {
                if (!in_array($o['d_id'], $keep, true)) continue;
                if (isset($haveOrder[(int)$o['Order_id']])) continue;
                $ins->execute([(int)$o['Order_id'], $encoded, $row['category_ids'], $row['filename'],
                               $row['original_name'], $row['file_size'], $row['uploaded_by'], $row['uploaded_at']]);
                $added++;
            }
            // 3) 綁定值寫回這份檔案在這個訂單編號底下的每一列（判定連動時要看得到）
            $pdo->prepare("UPDATE order_attachments a JOIN order_track ot ON ot.Order_id=a.order_id
                           SET a.linked_part_no=? WHERE a.status='active' AND a.filename=? AND ot.Order_oo=?")
                ->execute([$encoded, $row['filename'], $oo]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'套用失敗：' . $e->getMessage()]);
            break;
        }
        echo json_encode(['success'=>true,'added'=>$added,'removed'=>$removed,
                          'message'=>'已套用：新增 ' . $added . ' 個料號、移除 ' . $removed . ' 個料號']);
        break;
    }

    // ── 附件連動整理：找出「同一訂單編號＋同一份實體檔，卻掛在多個不同料號上」的組別 ──
    // 背景（2026-09-11）：改版前的同步程式只看標籤，不管附件有沒有綁料號，於是原本各自上傳到自己
    // 那張訂單的原圖，會在有人編輯同編號任一張訂單時被互相散佈到每個料號上。程式已修好不會再長，
    // 但既有資料要人工確認哪張圖屬於哪些料號（系統無法自己判斷），所以做成清單讓人逐組整理。
    // 預設排除「客戶訂單」這類本來就涵蓋整張單所有料號的標籤（使用者拍板，避免整頁都是假警報）。
    case 'link_audit_list': {
        if (!_oaIsAdmin($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'僅管理員可使用']); break; }
        $exCats = array_values(array_filter(array_map('intval', explode(',', trim($_POST['exclude_cats'] ?? '')))));
        $onlyCats = array_values(array_filter(array_map('intval', explode(',', trim($_POST['only_cats'] ?? '')))));
        $rows = $pdo->query("
            SELECT a.id, a.order_id, a.filename, a.original_name, a.category_ids, a.linked_part_no, a.uploaded_at,
                   ot.Order_oo, ot.d_id, ot.Client_name
            FROM order_attachments a
            JOIN order_track ot ON ot.Order_id = a.order_id
            WHERE a.status='active'
            ORDER BY ot.Order_oo, a.filename, a.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $catMap = [];
        foreach ($pdo->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $catMap[(int)$c['id']] = $c['category_name'];
        }
        $grp = [];
        foreach ($rows as $r) { $grp[$r['Order_oo'] . '|' . $r['filename']][] = $r; }

        $out = [];
        foreach ($grp as $list) {
            $parts = array_values(array_unique(array_column($list, 'd_id')));
            if (count($parts) < 2) continue;                      // 同一料號分多張訂單（拆批）＝正常
            $cids = array_values(array_filter(array_map('intval', explode(',', (string)$list[0]['category_ids']))));
            if ($exCats && array_intersect($cids, $exCats)) continue;
            if ($onlyCats && !array_intersect($cids, $onlyCats)) continue;
            $bound = eg_oa_parts_decode($list[0]['linked_part_no']);
            // 建議：檔名（去副檔名）剛好等於其中一個料號＝那張圖本來就是那個料號的（誠岱那種掃描命名）
            $base = pathinfo($list[0]['original_name'] ?: $list[0]['filename'], PATHINFO_FILENAME);
            $guess = null;
            foreach ($parts as $pp) { if (strcasecmp(trim($base), trim($pp)) === 0) { $guess = $pp; break; } }
            $out[] = [
                'attachment_id' => (int)$list[0]['id'],
                'order_oo'      => $list[0]['Order_oo'],
                'client'        => $list[0]['Client_name'],
                'display_name'  => $list[0]['original_name'] ?: $list[0]['filename'],
                'cats'          => implode('、', array_map(fn($i) => $catMap[$i] ?? ('#'.$i), $cids)),
                'parts'         => $parts,
                'bound'         => $bound,
                'guess'         => $guess,
                'rows'          => count($list),
                'first_at'      => $list[0]['uploaded_at'],
                'last_at'       => end($list)['uploaded_at'],
            ];
        }
        // 有建議的排前面（可以一鍵處理），其次依多出來的列數由多到少
        usort($out, function ($a, $b) {
            if (($a['guess'] ? 0 : 1) !== ($b['guess'] ? 0 : 1)) return ($a['guess'] ? 0 : 1) - ($b['guess'] ? 0 : 1);
            return $b['rows'] <=> $a['rows'];
        });
        echo json_encode(['success' => true, 'groups' => $out, 'total' => count($out)], JSON_UNESCAPED_UNICODE);
        break;
    }

    // ── 一鍵套用「檔名＝料號」的建議（只處理推得出原主的組，其餘一律留給人工）──
    // dry=1 只試算不寫入（預設就是試算，要真的寫必須明確送 apply=1）
    case 'link_audit_autofix': {
        if (!_oaIsAdmin($pdo, $uid)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'僅管理員可使用']); break; }
        $apply = (($_POST['apply'] ?? '') === '1');
        $onlyOo = trim($_POST['order_oo'] ?? '');       // 可限定只處理某一張訂單編號
        $rows = $pdo->query("
            SELECT a.id, a.order_id, a.filename, a.original_name, a.category_ids, a.linked_part_no,
                   ot.Order_oo, ot.d_id
            FROM order_attachments a
            JOIN order_track ot ON ot.Order_id = a.order_id
            WHERE a.status='active'
            ORDER BY ot.Order_oo, a.filename, a.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        $grp = [];
        foreach ($rows as $r) { $grp[$r['Order_oo'] . '|' . $r['filename']][] = $r; }

        $plan = [];
        foreach ($grp as $list) {
            if ($onlyOo !== '' && $list[0]['Order_oo'] !== $onlyOo) continue;
            $parts = array_values(array_unique(array_column($list, 'd_id')));
            if (count($parts) < 2) continue;
            $base = pathinfo($list[0]['original_name'] ?: $list[0]['filename'], PATHINFO_FILENAME);
            $keep = null;
            foreach ($parts as $pp) { if (strcasecmp(trim($base), trim($pp)) === 0) { $keep = $pp; break; } }
            if ($keep === null) continue;                // 推不出原主＝一律不碰，留給人工
            $drop = [];
            foreach ($list as $r) { if ($r['d_id'] !== $keep) $drop[] = (int)$r['id']; }
            if (!$drop) continue;
            $plan[] = ['order_oo'=>$list[0]['Order_oo'], 'file'=>$list[0]['original_name'] ?: $list[0]['filename'],
                       'keep'=>$keep, 'drop_parts'=>array_values(array_diff($parts, [$keep])),
                       'drop_ids'=>$drop, 'filename'=>$list[0]['filename']];
        }
        if (!$apply) {
            echo json_encode(['success'=>true,'dry'=>true,'groups'=>count($plan),
                              'rows'=>array_sum(array_map(fn($x)=>count($x['drop_ids']), $plan)),
                              'plan'=>$plan], JSON_UNESCAPED_UNICODE);
            break;
        }
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM order_attachments WHERE id=?");
            $bind = $pdo->prepare("UPDATE order_attachments a JOIN order_track ot ON ot.Order_id=a.order_id
                                   SET a.linked_part_no=? WHERE a.status='active' AND a.filename=? AND ot.Order_oo=?");
            $n = 0;
            foreach ($plan as $g) {
                foreach ($g['drop_ids'] as $id) { $del->execute([$id]); $n++; }
                // 留下來那一列要寫上綁定值，往後同步才知道它只屬於這個料號（否則又會被當成共用散出去）
                $bind->execute([$g['keep'], $g['filename'], $g['order_oo']]);
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'dry'=>false,'groups'=>count($plan),'rows'=>$n,
                              'message'=>'已整理 ' . count($plan) . ' 組、移除 ' . $n . ' 個多餘的料號連結（實體檔案都保留）']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'整理失敗：' . $e->getMessage()]);
        }
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
        // 刪除門檻（使用者明確要求收斂）：不論 temp（暫存中）或 active（已存檔），一律只有「上傳者本人」／
        // 「管理員」／被指派「刪除他人附件」角色功能(ot_attach_delete，角色設定內可勾選)才能刪——不再是
        // 「只要有訂單編輯權限就能刪任何人的附件」。
        $allowed = ((int)$row['uploaded_by'] === $uid) || _oaIsAdmin($pdo, $uid) || _oaHasAttachDelete($pdo, $uid);
        if (!$allowed) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無刪除權限：僅上傳者本人與管理員可刪除此附件']); break; }
        // 共用附件（OP轉訂單批次／同訂單編號跨料號自動連動）同一實體檔名(filename)會有多筆 order_attachments
        // 列分掛在不同訂單底下，視為同一份文件；刪除其中一份＝整份都不要了，一併連動刪除所有連結列＋實體檔
        // （使用者明確要求連動刪除，2026-08-12；不再是「刪一份、其他份留著變孤兒引用」）。
        $del = $pdo->prepare("DELETE FROM order_attachments WHERE filename=?");
        $del->execute([$row['filename']]);
        $linkedRemoved = $del->rowCount() - 1;
        $fp = $dir . $row['filename'];
        if (is_file($fp)) @unlink($fp);
        $msg = $linkedRemoved > 0 ? ('已刪除（含自動連動的 ' . $linkedRemoved . ' 筆）') : '已刪除';
        echo json_encode(['success' => true, 'message' => $msg, 'linked_removed' => max(0, $linkedRemoved)]);
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
        // inline 預覽／?dl=1 另存新檔（可用 dl_name 指定檔名）＋快取標頭，共用實作見 attach_lib
        eg_attach_send_disposition($dispName);
        header('Content-Length: ' . filesize($fp));
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
