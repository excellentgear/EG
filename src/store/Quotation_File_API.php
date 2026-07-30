<?php
// Quotation_File_API.php — 報價單附件管理 API（含類別標籤 + 料號連結）
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
$db  = new DBConnection();
$pdo = $db->getPDO();

// 報價查閱權限（沿用報價單 quotation_view；判定失敗採寬鬆，避免鎖死既有功能）
require_once __DIR__ . '/../common/rbac.php';
function _quotCanView(PDO $pdo): bool {
    try { return rbac_has(rbac_user_features($pdo, (int)($_SESSION['id'] ?? 0)), 'quotation_view'); }
    catch (Exception $_e) { return true; }
}
// 補件相關權限：edit=可補件（另允許報價單建立者本人）、sign=可審核補件（沿用報價單簽核者）；管理者(all)全權。
function _quotUid(): int { return (int)($_SESSION['id'] ?? 0); }
function _quotFeats(PDO $pdo): array {
    try { return rbac_user_features($pdo, _quotUid()); } catch (Exception $_e) { return []; }
}

// ════════════════════════════════════════════════════
// 工具函式
// ════════════════════════════════════════════════════
function getUploadBase(PDO $pdo): string {
    $stmt = $pdo->prepare(
        "SELECT param_value FROM system_parameters
         WHERE param_group = 'QUOTATION' AND param_key = 'upload_path' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['param_value']) return '';
    $decoded = json_decode($row['param_value'], true);
    return is_string($decoded) ? $decoded : '';
}
function safeQuoteNo(string $no): string {
    return preg_replace('/[^a-zA-Z0-9\-_]/', '', $no);
}
function quoteDir(string $base, string $quoteNo): string {
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $quoteNo . DIRECTORY_SEPARATOR;
}
function fmtSize(int $bytes): string {
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1024*1024)  return round($bytes/1024, 1) . ' KB';
    return round($bytes/1024/1024, 1) . ' MB';
}
// 暫存/垃圾附件自動刪除天數（可於報價設定調整；system_parameters QUOTATION group，值為 json_encode 的數字）
function getQuotAttachDays(PDO $pdo, string $key, int $default): int {
    try {
        $stmt = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='QUOTATION' AND param_key=? LIMIT 1");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode($v, true);
        $n = is_numeric($d) ? (int)$d : (is_numeric($v) ? (int)$v : $default);
        return $n > 0 ? $n : $default;
    } catch (Exception $e) { return $default; }
}
// 垃圾桶實體資料夾（被否決補件先搬到這裡，7天後 purge；「先進暫存檔」）
function trashDir(string $base, string $quoteNo): string {
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . '_att_trash' . DIRECTORY_SEPARATOR . $quoteNo . DIRECTORY_SEPARATOR;
}
// 懶惰清除：永久刪除已到期的暫存(temp)/垃圾(trash)附件（實體檔＋DB列）。tick 與 list 皆呼叫。
function purgeExpiredQuotAttachments(PDO $pdo, string $base): int {
    if (empty($base)) return 0;
    try {
        $rows = $pdo->query(
            "SELECT id, quote_no, filename, status FROM quotation_attachments
             WHERE status IN ('temp','trash') AND expire_at IS NOT NULL AND expire_at < NOW()"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return 0; }
    if (!$rows) return 0;
    $expBase = realpath(rtrim($base, '/\\'));
    $del = $pdo->prepare("DELETE FROM quotation_attachments WHERE id=?");
    $n = 0;
    foreach ($rows as $r) {
        $qn  = safeQuoteNo($r['quote_no']);
        $dir = ($r['status'] === 'trash') ? trashDir($base, $qn) : quoteDir($base, $qn);
        $real = realpath($dir . $r['filename']);
        if ($real && $expBase && strpos($real, $expBase) === 0 && is_file($real)) { @unlink($real); }
        try { $del->execute([$r['id']]); $n++; } catch (Exception $e) {}
    }
    return $n;
}

// ── 自動建立資料表（首次使用時執行）──────────────────────────
function initTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS quotation_file_categories (
            id           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(50) NOT NULL,
            sort_order   INT          NOT NULL DEFAULT 0,
            is_active    TINYINT(1)   NOT NULL DEFAULT 1,
            created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // 初始類別（僅在空資料表時寫入）
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM quotation_file_categories")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("
            INSERT INTO quotation_file_categories (category_name, sort_order) VALUES
            ('圖面', 1), ('規格書', 2), ('報價計算', 3), ('合約文件', 4)
        ");
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS quotation_attachments (
            id            INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quote_no      VARCHAR(30)   NOT NULL,
            filename      VARCHAR(255)  NOT NULL,
            original_name VARCHAR(255)  DEFAULT NULL,
            category_id   INT           DEFAULT NULL,
            category_ids  VARCHAR(255)  DEFAULT NULL,
            linked_parts  TEXT          DEFAULT NULL,
            file_size     VARCHAR(20)   DEFAULT NULL,
            uploaded_by   VARCHAR(50)   DEFAULT NULL,
            uploaded_at   DATETIME      DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_qn  (quote_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    try { $pdo->exec("ALTER TABLE quotation_attachments ADD COLUMN category_ids VARCHAR(255) DEFAULT NULL AFTER category_id"); } catch(PDOException $e){}
    // 附件類別標籤擴充欄位
    try { $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN show_in_list TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否在料號列表顯示最新附件'"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN tag_variables TEXT NULL COMMENT '標籤變數定義 JSON [{key,hint,var_type}]'"); } catch(PDOException $e){}
    // 外來文件（AS9100）：是否列入外來文件清單＋清單上顯示的類別名稱（空=直接用標籤名稱）（2026-07-30）
    try { $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN is_external_doc TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否列入外來文件清單'"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN external_doc_name VARCHAR(100) NULL COMMENT '外來文件類別名稱(空=用標籤名)'"); } catch(PDOException $e){}
    // 暫存/補件/垃圾狀態機（2026-07-22）：temp=未存檔暫存 active=正式 pending=補件待審 trash=已否決待清
    try { $pdo->exec("ALTER TABLE quotation_attachments ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT 'temp/active/pending/trash' AFTER linked_parts"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE quotation_attachments ADD COLUMN expire_at DATETIME NULL COMMENT 'temp/trash 自動清除到期時間，NULL=不清' AFTER updated_at"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE quotation_attachments ADD COLUMN trashed_reason VARCHAR(500) NULL COMMENT '補件被否決原因' AFTER expire_at"); } catch(PDOException $e){}
    try { $pdo->exec("ALTER TABLE quotation_attachments ADD INDEX idx_status_expire (status, expire_at)"); } catch(PDOException $e){}
}

$uploadedBy = $_SESSION['id'] ?? $_SESSION['userName'] ?? '';

switch ($action) {

    // ── 取得檔案類別清單 ──────────────────────────────────────
    case 'get_categories':
        initTables($pdo);
        $rows = $pdo->query(
            "SELECT id, category_name, sort_order, COALESCE(show_in_list,0) AS show_in_list, tag_variables,
                    COALESCE(is_external_doc,0) AS is_external_doc, external_doc_name
             FROM quotation_file_categories WHERE is_active=1 ORDER BY sort_order, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'categories' => $rows]);
        break;

    // ── 取得所有類別（含停用，供設定頁使用）──────────────────
    case 'get_all_categories':
        initTables($pdo);
        $rows = $pdo->query(
            "SELECT id, category_name, sort_order, is_active, COALESCE(show_in_list,0) AS show_in_list, tag_variables,
                    COALESCE(is_external_doc,0) AS is_external_doc, external_doc_name
             FROM quotation_file_categories ORDER BY sort_order, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'categories' => $rows]);
        break;

    // ── 新增 / 修改類別 ──────────────────────────────────────
    case 'save_category':
        $catId      = intval($_POST['cat_id'] ?? 0);
        $name       = trim($_POST['category_name'] ?? '');
        $order      = intval($_POST['sort_order'] ?? 0);
        $reactivate = intval($_POST['reactivate'] ?? 0);
        $showInList = intval($_POST['show_in_list'] ?? 0) ? 1 : 0;
        $tagVars    = trim($_POST['tag_variables'] ?? '') ?: null;
        $isExtDoc   = intval($_POST['is_external_doc'] ?? 0) ? 1 : 0;
        $extDocName = trim($_POST['external_doc_name'] ?? '') ?: null;
        initTables($pdo);
        if ($catId && $reactivate) {
            $pdo->prepare("UPDATE quotation_file_categories SET is_active=1 WHERE id=?")
                ->execute([$catId]);
            echo json_encode(['success'=>true,'message'=>'已重新啟用','cat_id'=>$catId]);
        } elseif ($catId) {
            if (!$name) { echo json_encode(['success'=>false,'message'=>'類別名稱不可為空']); exit; }
            $pdo->prepare("UPDATE quotation_file_categories SET category_name=?,sort_order=?,show_in_list=?,tag_variables=?,is_external_doc=?,external_doc_name=? WHERE id=?")
                ->execute([$name, $order, $showInList, $tagVars, $isExtDoc, $extDocName, $catId]);
            echo json_encode(['success'=>true,'message'=>'已更新','cat_id'=>$catId]);
        } else {
            if (!$name) { echo json_encode(['success'=>false,'message'=>'類別名稱不可為空']); exit; }
            $pdo->prepare("INSERT INTO quotation_file_categories (category_name,sort_order,show_in_list,tag_variables,is_external_doc,external_doc_name) VALUES (?,?,?,?,?,?)")
                ->execute([$name, $order, $showInList, $tagVars, $isExtDoc, $extDocName]);
            echo json_encode(['success'=>true,'message'=>'已新增','cat_id'=>(int)$pdo->lastInsertId()]);
        }
        break;

    // ── 拖移排序類別 ──────────────────────────────────────────
    case 'reorder_categories':
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids)) { echo json_encode(['success'=>false]); exit; }
        $stmt = $pdo->prepare("UPDATE quotation_file_categories SET sort_order=? WHERE id=?");
        foreach ($ids as $i => $id) $stmt->execute([$i, intval($id)]);
        echo json_encode(['success' => true]);
        break;

    // ── 停用類別（軟刪除，保留歷史附件的 category_id 關聯）──
    case 'deactivate_category':
        $catId = intval($_POST['cat_id'] ?? 0);
        if (!$catId) { echo json_encode(['success'=>false,'message'=>'參數錯誤']); exit; }
        $pdo->prepare("UPDATE quotation_file_categories SET is_active=0 WHERE id=?")->execute([$catId]);
        echo json_encode(['success'=>true,'message'=>'已停用']);
        break;

    // ── 上傳檔案 ─────────────────────────────────────────────
    case 'upload_file':
        initTables($pdo);
        $quoteNo = safeQuoteNo($_POST['quote_no'] ?? '');
        if (empty($quoteNo)) {
            echo json_encode(['success' => false, 'message' => '請先填寫報價單號']); exit;
        }
        $base = getUploadBase($pdo);
        if (empty($base)) {
            echo json_encode(['success' => false, 'message' => '尚未設定儲存路徑，請點擊右上角 ⚙ 設定']); exit;
        }
        $dir = quoteDir($base, $quoteNo);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            echo json_encode(['success' => false, 'message' => '無法建立目錄：' . $dir]); exit;
        }
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => '檔案超過 PHP 設定上限',
                UPLOAD_ERR_FORM_SIZE  => '檔案超過表單上限',
                UPLOAD_ERR_PARTIAL    => '檔案只上傳一部分',
                UPLOAD_ERR_NO_FILE    => '未選擇檔案',
                UPLOAD_ERR_NO_TMP_DIR => '暫存目錄不存在',
                UPLOAD_ERR_CANT_WRITE => '無法寫入磁碟',
            ];
            $code = $_FILES['file']['error'] ?? -1;
            echo json_encode(['success' => false, 'message' => $errMap[$code] ?? '上傳失敗 (錯誤碼 '.$code.')']); exit;
        }
        $originalName = $_FILES['file']['name'];
        $safeName = preg_replace('/[\\/\\\\:*?"<>|]/', '_', $originalName);
        if (file_exists($dir . $safeName)) {
            $safeName = date('YmdHis') . '_' . $safeName;
        }
        $sizeStr = fmtSize((int)$_FILES['file']['size']);
        if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $safeName)) {
            // 寫入元數據——一律先存為暫存(temp)，存檔/存草稿後才由 promote 轉正式(active)；
            // 逾期(預設2天)未存檔則自動清除。存檔前不對外顯示（外部查閱一律只讀 active）。
            $tempDays = getQuotAttachDays($pdo, 'temp_attach_days', 2);
            $ins = $pdo->prepare("
                INSERT INTO quotation_attachments (quote_no, filename, original_name, file_size, uploaded_by, status, expire_at)
                VALUES (?, ?, ?, ?, ?, 'temp', DATE_ADD(NOW(), INTERVAL ? DAY))
            ");
            $ins->execute([$quoteNo, $safeName, $originalName, $sizeStr, $uploadedBy, $tempDays]);
            $attachId = (int)$pdo->lastInsertId();
            echo json_encode([
                'success'       => true,
                'attachment_id' => $attachId,
                'filename'      => $safeName,
                'original_name' => $originalName,
                'size'          => $sizeStr,
                'mtime'         => date('Y-m-d H:i'),
                'category_id'   => null,
                'category_ids'  => null,
                'category_name' => null,
                'linked_parts'  => null,
                'status'        => 'temp',
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '移動檔案失敗，請確認路徑權限']);
        }
        break;

    // ── 列出檔案（含類別 + 料號連結）────────────────────────
    case 'list_files':
        initTables($pdo);
        $quoteNo = safeQuoteNo($_GET['quote_no'] ?? '');
        $base    = getUploadBase($pdo);
        purgeExpiredQuotAttachments($pdo, $base); // 順帶清除已到期的暫存/垃圾附件
        $files   = [];
        if (!empty($base) && !empty($quoteNo)) {
            $dir = quoteDir($base, $quoteNo);
            if (is_dir($dir)) {
                // 讀 DB 元數據（JOIN 類別名稱）。編輯畫面為擁有者工作區，顯示 temp/active/pending，
                // 但不顯示 trash（已否決，實體檔已搬到 _att_trash）。
                $stmt = $pdo->prepare("
                    SELECT a.id, a.filename, a.original_name, a.category_id, a.category_ids,
                           a.linked_parts, a.file_size, a.status,
                           DATE_FORMAT(a.uploaded_at,'%Y-%m-%d %H:%i') AS mtime,
                           c.category_name
                    FROM quotation_attachments a
                    LEFT JOIN quotation_file_categories c ON c.id = a.category_id
                    WHERE a.quote_no = ? AND a.status <> 'trash'
                ");
                $stmt->execute([$quoteNo]);
                $dbMap = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $dbMap[$row['filename']] = $row;
                }
                foreach (scandir($dir) as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $fp = $dir . $f;
                    if (!is_file($fp)) continue;
                    $db = $dbMap[$f] ?? null;
                    $files[] = [
                        'attachment_id' => $db ? (int)$db['id']   : null,
                        'filename'      => $f,
                        'original_name' => $db['original_name'] ?? $f,
                        'size'          => $db['file_size']     ?? fmtSize((int)filesize($fp)),
                        'mtime'         => $db['mtime']         ?? date('Y-m-d H:i', filemtime($fp)),
                        'category_id'   => $db ? $db['category_id']   : null,
                        'category_name' => $db ? $db['category_name'] : null,
                        'linked_parts'  => $db ? $db['linked_parts']  : null,
                        'status'        => $db ? ($db['status'] ?? 'active') : 'active',
                    ];
                }
            }
        }
        echo json_encode(['success' => true, 'files' => $files]);
        break;

    // ── 更新附件的類別與料號連結 ──────────────────────────────
    case 'update_attachment':
        $attachId    = intval($_POST['attachment_id'] ?? 0);
        $rawCatIds   = trim($_POST['category_ids'] ?? $_POST['category_id'] ?? '');
        // 解析多類別 ID（逗號分隔整數）
        $catIdArr    = array_values(array_filter(array_map('intval', explode(',', $rawCatIds))));
        $catIdsStr   = $catIdArr ? implode(',', $catIdArr) : null;
        $firstCatId  = $catIdArr[0] ?? null;
        $rawParts    = $_POST['linked_parts'] ?? 'all';
        $linkedParts = ($rawParts === 'all' || $rawParts === '') ? null : $rawParts;

        if (!$attachId) {
            echo json_encode(['success' => false, 'message' => '缺少 attachment_id']); exit;
        }
        $pdo->prepare("
            UPDATE quotation_attachments
            SET category_id=?, category_ids=?, linked_parts=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$firstCatId, $catIdsStr, $linkedParts, $attachId]);
        echo json_encode(['success' => true]);
        break;

    // ── 刪除單一檔案（+ DB 記錄）─────────────────────────────
    case 'delete_file':
        $quoteNo  = safeQuoteNo($_POST['quote_no'] ?? '');
        $filename = basename($_POST['filename'] ?? '');
        $base     = getUploadBase($pdo);
        if (empty($quoteNo) || empty($filename) || empty($base)) {
            echo json_encode(['success' => false, 'message' => '參數錯誤']); exit;
        }
        $filepath    = quoteDir($base, $quoteNo) . $filename;
        $realPath    = realpath($filepath);
        $expectedDir = realpath(rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $quoteNo);
        if ($realPath && $expectedDir && strpos($realPath, $expectedDir) === 0 && is_file($realPath)) {
            if (unlink($realPath)) {
                $pdo->prepare("DELETE FROM quotation_attachments WHERE quote_no=? AND filename=?")
                    ->execute([$quoteNo, $filename]);
                echo json_encode(['success' => true, 'message' => '已刪除']);
            } else {
                echo json_encode(['success' => false, 'message' => '刪除失敗，請確認檔案權限']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '檔案不存在或路徑錯誤']);
        }
        break;

    // ── 刪除整個報價單資料夾（+ 所有 DB 記錄）──────────────
    case 'delete_folder':
        $quoteNo = safeQuoteNo($_POST['quote_no'] ?? '');
        $base    = getUploadBase($pdo);
        if (empty($quoteNo) || empty($base)) {
            echo json_encode(['success' => false, 'message' => '參數錯誤']); exit;
        }
        $dir          = quoteDir($base, $quoteNo);
        $realDir      = realpath($dir);
        $expectedBase = realpath(rtrim($base, '/\\'));
        if (!$realDir || !$expectedBase || strpos($realDir, $expectedBase) !== 0) {
            // 目錄不存在，仍清除 DB 記錄
            $pdo->prepare("DELETE FROM quotation_attachments WHERE quote_no=?")->execute([$quoteNo]);
            echo json_encode(['success' => true, 'message' => '目錄不存在，DB 記錄已清除']);
            exit;
        }
        $items = array_diff(scandir($realDir), ['.', '..']);
        $allOk = true;
        foreach ($items as $f) {
            $fp = $realDir . DIRECTORY_SEPARATOR . $f;
            if (is_file($fp) && !unlink($fp)) $allOk = false;
        }
        if ($allOk && rmdir($realDir)) {
            $pdo->prepare("DELETE FROM quotation_attachments WHERE quote_no=?")->execute([$quoteNo]);
            echo json_encode(['success' => true, 'message' => '資料夾已刪除']);
        } else {
            echo json_encode(['success' => false, 'message' => '部分檔案刪除失敗，請確認權限']);
        }
        break;

    // ── 下載 / 預覽檔案 ─────────────────────────────────────
    case 'download':
        if (!_quotCanView($pdo)) { http_response_code(403); echo '無報價查閱權限'; exit; }
        $quoteNo  = safeQuoteNo($_GET['quote_no'] ?? '');
        $filename = basename($_GET['filename'] ?? '');
        $base     = getUploadBase($pdo);
        if (empty($quoteNo) || empty($filename) || empty($base)) {
            http_response_code(400); echo '參數錯誤'; exit;
        }
        $filepath    = quoteDir($base, $quoteNo) . $filename;
        $realPath    = realpath($filepath);
        $expectedDir = realpath(rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $quoteNo);
        if (!$realPath || !$expectedDir || strpos($realPath, $expectedDir) !== 0 || !is_file($realPath)) {
            http_response_code(404); echo '檔案不存在'; exit;
        }
        while (ob_get_level()) ob_end_clean();
        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'              => 'application/pdf',
            'png'              => 'image/png',
            'jpg', 'jpeg'      => 'image/jpeg',
            'gif'              => 'image/gif',
            'xlsx', 'xls'      => 'application/vnd.ms-excel',
            'docx', 'doc'      => 'application/msword',
            default            => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($realPath));
        header('Cache-Control: private, max-age=3600');
        readfile($realPath);
        exit;

    // ── 旋轉影像附件並覆寫存檔（永久生效；需編輯權限）──
    // 只處理圖片檔(90/180/270)；PDF/Office 檔不支援(請用原軟體轉正後重新上傳)。
    case 'rotate_file': {
        $feats   = _quotFeats($pdo);
        $isAdmin = rbac_has($feats, 'all');
        if (!$isAdmin && !rbac_has($feats, 'quotation_edit')) {
            http_response_code(403); echo json_encode(['success'=>false,'message'=>'無編輯權限，不能旋轉附件']); break;
        }
        $quoteNo  = safeQuoteNo($_POST['quote_no'] ?? '');
        $filename = basename($_POST['filename'] ?? '');
        $deg      = (int)($_POST['deg'] ?? 90);            // 順時針度數：90 / 180 / -90(=逆時針90)
        if (!in_array($deg, [90, 180, -90, 270], true)) $deg = 90;
        $base = getUploadBase($pdo);
        if ($quoteNo === '' || $filename === '' || $base === '') { echo json_encode(['success'=>false,'message'=>'參數錯誤']); break; }
        $filepath    = quoteDir($base, $quoteNo) . $filename;
        $realPath    = realpath($filepath);
        $expectedDir = realpath(rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $quoteNo);
        if (!$realPath || !$expectedDir || strpos($realPath, $expectedDir) !== 0 || !is_file($realPath)) {
            echo json_encode(['success'=>false,'message'=>'檔案不存在']); break;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png','jpg','jpeg','gif','bmp','webp'], true)) {
            echo json_encode(['success'=>false,'message'=>'只有圖片檔可旋轉；PDF／Office 檔請用原軟體轉正後重新上傳']); break;
        }
        if (!function_exists('imagerotate')) { echo json_encode(['success'=>false,'message'=>'伺服器未啟用 GD imagerotate']); break; }
        try {
            $src = match ($ext) {
                'png'  => imagecreatefrompng($realPath),
                'gif'  => imagecreatefromgif($realPath),
                'bmp'  => (function_exists('imagecreatefrombmp')  ? imagecreatefrombmp($realPath)  : false),
                'webp' => (function_exists('imagecreatefromwebp') ? imagecreatefromwebp($realPath) : false),
                default => imagecreatefromjpeg($realPath),   // jpg / jpeg
            };
            if (!$src) { echo json_encode(['success'=>false,'message'=>'無法讀取圖片(格式不支援或檔案毀損)']); break; }
            // imagerotate 角度為「逆時針」；使用者送順時針度數 → 取負值。90/180/270 為直角旋轉，不會露出背景三角。
            imagealphablending($src, false);
            imagesavealpha($src, true);
            $bg  = imagecolorallocatealpha($src, 0, 0, 0, 127);
            $rot = imagerotate($src, -$deg, $bg);
            imagealphablending($rot, false);
            imagesavealpha($rot, true);
            $ok = match ($ext) {
                'png'  => imagepng($rot, $realPath),
                'gif'  => imagegif($rot, $realPath),
                'webp' => (function_exists('imagewebp') ? imagewebp($rot, $realPath) : false),
                'bmp'  => (function_exists('imagebmp')  ? imagebmp($rot, $realPath)  : false),
                default => imagejpeg($rot, $realPath, 92),
            };
            imagedestroy($src); imagedestroy($rot);
            if (!$ok) { echo json_encode(['success'=>false,'message'=>'旋轉後寫入失敗']); break; }
            @touch($realPath);   // 更新 mtime 供前端破快取
            echo json_encode(['success'=>true, 'message'=>'已旋轉並存檔']);
        } catch (Throwable $e) {
            echo json_encode(['success'=>false,'message'=>'旋轉失敗：'.$e->getMessage()]);
        }
        break;
    }

    // ══════════════════════════════════════════════════════════
    // 補件重審（功能2）：已核准報價單追加附件，需經簽核者審核「是否允許放入此報價單」
    // ══════════════════════════════════════════════════════════

    // ── 送出補件審核：把剛上傳的暫存(temp)附件轉為待審(pending)並通知簽核者 ──
    case 'submit_supplement': {
        initTables($pdo);
        require_once __DIR__ . '/../common/quotation_supplement.php';
        $quoteNo = safeQuoteNo($_POST['quote_no'] ?? '');
        $rawIds  = $_POST['attachment_ids'] ?? '';
        $ids     = is_array($rawIds) ? $rawIds : (json_decode($rawIds, true) ?: explode(',', (string)$rawIds));
        $ids     = array_values(array_filter(array_map('intval', (array)$ids)));
        if ($quoteNo === '' || empty($ids)) { echo json_encode(['success'=>false,'message'=>'參數不足']); break; }

        // 報價單須存在且已核准
        $qStmt = $pdo->prepare("SELECT quote_id, created_by, approval_status FROM quotation_list WHERE quote_no=? LIMIT 1");
        $qStmt->execute([$quoteNo]);
        $quote = $qStmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote) { echo json_encode(['success'=>false,'message'=>'找不到此報價單']); break; }
        if (($quote['approval_status'] ?? '') !== 'approved') {
            echo json_encode(['success'=>false,'message'=>'此報價單尚未核准，附件請直接編輯報價單並存檔即可，毋須補件審核']); break;
        }
        // 權限：報價單建立者本人，或具 quotation_edit（管理者全權）
        $feats = _quotFeats($pdo);
        $isAdmin = rbac_has($feats, 'all');
        $canEdit = $isAdmin || rbac_has($feats, 'quotation_edit');
        $isOwner = ((int)$quote['created_by'] === _quotUid());
        if (!$canEdit && !$isOwner) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無補件權限']); break; }

        $uid  = _quotUid();
        $name = eg_quotation_current_user_name($pdo, $uid);
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $sel  = $pdo->prepare("SELECT id, original_name, category_ids, linked_parts FROM quotation_attachments
                               WHERE quote_no=? AND status='temp' AND id IN ($ph)");
        $sel->execute(array_merge([$quoteNo], $ids));
        $targets = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (empty($targets)) { echo json_encode(['success'=>false,'message'=>'沒有可送審的補件附件（需先上傳並設定類別）']); break; }

        $done = 0; $skipped = [];
        foreach ($targets as $t) {
            if (empty($t['category_ids'])) { $skipped[] = ($t['original_name'] ?: ('#'.$t['id'])) . '（未設類別）'; continue; }
            $attId = (int)$t['id'];
            $pdo->prepare("UPDATE quotation_attachments SET status='pending', expire_at=NULL WHERE id=? AND status='temp'")->execute([$attId]);
            $apId  = eg_approval_submit($pdo, 'quotation_attach', $attId, 'manager', $uid, $name);
            $label = _quotPartLabel($t['linked_parts']);
            $evId  = eg_quot_supp_notify_request($pdo, $attId, $quoteNo, $label, $uid, $name);
            if ($evId) eg_approval_set_live_event($pdo, $apId, $evId);
            $done++;
        }
        echo json_encode(['success'=>$done>0, 'submitted'=>$done, 'skipped'=>$skipped,
                          'message'=>$done>0 ? "已送出 {$done} 件補件審核" : '沒有附件送審（請先設定類別）']);
        break;
    }

    // ── 簽核者：列出待我審核的補件附件 ──
    case 'list_pending_supplements': {
        initTables($pdo);
        $feats = _quotFeats($pdo);
        $canSign = rbac_has($feats, 'all') || rbac_has($feats, 'quotation_sign');
        if (!$canSign) { echo json_encode(['success'=>true,'items'=>[],'can_sign'=>false]); break; }
        $rows = $pdo->query("
            SELECT a.id, a.quote_no, a.original_name, a.filename, a.category_ids, a.linked_parts,
                   DATE_FORMAT(a.uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at,
                   COALESCE(u.user_cname, a.uploaded_by) AS uploader_name,
                   ql.client_name, ar.submitted_at
            FROM quotation_attachments a
            JOIN approval_record ar ON ar.module='quotation_attach' AND ar.entity_id=a.id AND ar.status='pending'
                 AND ar.id = (SELECT MAX(id) FROM approval_record WHERE module='quotation_attach' AND entity_id=a.id)
            LEFT JOIN quotation_list ql ON ql.quote_no=a.quote_no
            LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
            WHERE a.status='pending'
            ORDER BY ar.submitted_at DESC, a.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        // 類別名稱對照
        $catMap = [];
        foreach ($pdo->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $catMap[(int)$c['id']] = $c['category_name'];
        }
        foreach ($rows as &$r) {
            $cids = array_values(array_filter(array_map('intval', explode(',', (string)$r['category_ids']))));
            $r['category_label'] = implode('、', array_map(fn($i)=>$catMap[$i] ?? ('#'.$i), $cids));
            $r['part_label']     = _quotPartLabel($r['linked_parts']);
        }
        unset($r);
        echo json_encode(['success'=>true, 'items'=>$rows, 'can_sign'=>true]);
        break;
    }

    // ── 進站提醒／待處理單據：我被駁回的補件 ＋（簽核者）待審補件 ──
    case 'supplement_alerts': {
        initTables($pdo);
        $uid = _quotUid();
        // 類別名稱對照
        $catMap = [];
        foreach ($pdo->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $catMap[(int)$c['id']] = $c['category_name'];
        }
        $labelCats = function ($cids) use ($catMap) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$cids))));
            return implode('、', array_map(fn($i) => $catMap[$i] ?? ('#'.$i), $ids));
        };
        // 我被駁回的補件（status=trash + 有駁回原因 + 我上傳；trash 於保留天數內，逾期自動清除）
        $rj = $pdo->prepare("
            SELECT a.id, a.quote_no, a.original_name, a.filename, a.category_ids, a.linked_parts, a.trashed_reason,
                   DATE_FORMAT(a.expire_at,'%Y-%m-%d') AS expire_at, ql.client_name
            FROM quotation_attachments a
            LEFT JOIN quotation_list ql ON ql.quote_no = a.quote_no
            WHERE a.status='trash' AND a.trashed_reason IS NOT NULL AND a.trashed_reason<>''
              AND CAST(a.uploaded_by AS UNSIGNED) = ?
            ORDER BY a.id DESC");
        $rj->execute([$uid]);
        $rejected = $rj->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rejected as &$r) {
            $r['category_label'] = $labelCats($r['category_ids']);
            $r['part_label']     = _quotPartLabel($r['linked_parts']);
        }
        unset($r);
        // 待審補件（僅簽核者可見；與 list_pending_supplements 同來源）
        $pending = [];
        $feats   = _quotFeats($pdo);
        $canSign = rbac_has($feats, 'all') || rbac_has($feats, 'quotation_sign');
        if ($canSign) {
            $pending = $pdo->query("
                SELECT a.id, a.quote_no, a.original_name, a.filename, a.category_ids, a.linked_parts,
                       DATE_FORMAT(a.uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at,
                       COALESCE(u.user_cname, a.uploaded_by) AS uploader_name, ql.client_name
                FROM quotation_attachments a
                JOIN approval_record ar ON ar.module='quotation_attach' AND ar.entity_id=a.id AND ar.status='pending'
                     AND ar.id = (SELECT MAX(id) FROM approval_record WHERE module='quotation_attach' AND entity_id=a.id)
                LEFT JOIN quotation_list ql ON ql.quote_no = a.quote_no
                LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
                WHERE a.status='pending'
                ORDER BY a.id DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pending as &$r) {
                $r['category_label'] = $labelCats($r['category_ids']);
                $r['part_label']     = _quotPartLabel($r['linked_parts']);
            }
            unset($r);
        }
        echo json_encode(['success'=>true, 'rejected'=>$rejected, 'pending'=>$pending, 'can_sign'=>$canSign]);
        break;
    }

    // ── 簽核者：核准 / 駁回一件補件附件 ──
    case 'decide_supplement': {
        initTables($pdo);
        require_once __DIR__ . '/../common/quotation_supplement.php';
        $feats = _quotFeats($pdo);
        $canSign = rbac_has($feats, 'all') || rbac_has($feats, 'quotation_sign');
        if (!$canSign) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'無簽核權限']); break; }
        $attId    = intval($_POST['attachment_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : (($_POST['decision'] ?? '') === 'reject' ? 'rejected' : '');
        $note     = trim($_POST['note'] ?? '');
        if (!$attId || $decision === '') { echo json_encode(['success'=>false,'message'=>'參數錯誤']); break; }
        if ($decision === 'rejected' && $note === '') { echo json_encode(['success'=>false,'message'=>'駁回必須填寫原因']); break; }

        $aStmt = $pdo->prepare("SELECT id, quote_no, filename, original_name, uploaded_by, status FROM quotation_attachments WHERE id=? LIMIT 1");
        $aStmt->execute([$attId]);
        $att = $aStmt->fetch(PDO::FETCH_ASSOC);
        if (!$att || $att['status'] !== 'pending') { echo json_encode(['success'=>false,'message'=>'此附件不存在或已處理']); break; }
        $latest = eg_approval_latest($pdo, 'quotation_attach', $attId, 'manager');
        if (!$latest || $latest['status'] !== 'pending') { echo json_encode(['success'=>false,'message'=>'查無待審紀錄或已處理']); break; }

        $uid   = _quotUid();
        $name  = eg_quotation_current_user_name($pdo, $uid);
        $qno   = safeQuoteNo($att['quote_no']);
        $base  = getUploadBase($pdo);
        $fileLabel = $att['original_name'] ?: $att['filename'];

        $pdo->beginTransaction();
        try {
            $res = eg_approval_decide($pdo, (int)$latest['id'], $uid, $name, $decision, $note ?: null);
            if (!$res['success']) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$res['message']]); break; }
            if ($decision === 'approved') {
                $pdo->prepare("UPDATE quotation_attachments SET status='active', expire_at=NULL, trashed_reason=NULL WHERE id=?")->execute([$attId]);
            } else {
                // 先進暫存檔：實體檔搬到 _att_trash，狀態 trash，設定天數後由 tick 永久刪除
                if ($base !== '') {
                    $src = quoteDir($base, $qno) . $att['filename'];
                    $realSrc = realpath($src);
                    $expBase = realpath(rtrim($base, '/\\'));
                    if ($realSrc && $expBase && strpos($realSrc, $expBase) === 0 && is_file($realSrc)) {
                        $tdir = trashDir($base, $qno);
                        if (!is_dir($tdir)) @mkdir($tdir, 0755, true);
                        @rename($realSrc, $tdir . $att['filename']);
                    }
                }
                $trashDays = getQuotAttachDays($pdo, 'trash_attach_days', 7);
                $pdo->prepare("UPDATE quotation_attachments SET status='trash', trashed_reason=?, expire_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?")
                    ->execute([$note, $trashDays, $attId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'處理失敗：'.$e->getMessage()]); break;
        }

        // 交易外：解除其他簽核者通知（OR-gate）＋通知上傳者結果
        eg_quot_supp_close_notice($pdo, $attId, $uid);
        $uploaderUid = is_numeric($att['uploaded_by']) ? (int)$att['uploaded_by'] : 0;
        eg_quot_supp_notify_result($pdo, $attId, $att['quote_no'], $uploaderUid, $fileLabel, $name, $decision, $note ?: null);
        echo json_encode(['success'=>true, 'message'=>$decision==='approved' ? '已核准，附件已正式放入報價單' : '已駁回，附件已刪除並通知上傳者']);
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => '未知操作：' . $action]);
}

// 補件顯示用：把 linked_parts(JSON of D_Setting_Id) 轉為可讀料號標籤；NULL/空=共用附件
function _quotPartLabel(?string $lp): string {
    if ($lp === null || $lp === '') return '共用附件';
    $ids = json_decode($lp, true);
    return (is_array($ids) && $ids) ? implode('、', array_map('strval', $ids)) : '共用附件';
}
