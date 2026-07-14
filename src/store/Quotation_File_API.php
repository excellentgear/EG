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
}

$uploadedBy = $_SESSION['id'] ?? $_SESSION['userName'] ?? '';

switch ($action) {

    // ── 取得檔案類別清單 ──────────────────────────────────────
    case 'get_categories':
        initTables($pdo);
        $rows = $pdo->query(
            "SELECT id, category_name, sort_order, COALESCE(show_in_list,0) AS show_in_list, tag_variables
             FROM quotation_file_categories WHERE is_active=1 ORDER BY sort_order, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'categories' => $rows]);
        break;

    // ── 取得所有類別（含停用，供設定頁使用）──────────────────
    case 'get_all_categories':
        initTables($pdo);
        $rows = $pdo->query(
            "SELECT id, category_name, sort_order, is_active, COALESCE(show_in_list,0) AS show_in_list, tag_variables
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
        initTables($pdo);
        if ($catId && $reactivate) {
            $pdo->prepare("UPDATE quotation_file_categories SET is_active=1 WHERE id=?")
                ->execute([$catId]);
            echo json_encode(['success'=>true,'message'=>'已重新啟用','cat_id'=>$catId]);
        } elseif ($catId) {
            if (!$name) { echo json_encode(['success'=>false,'message'=>'類別名稱不可為空']); exit; }
            $pdo->prepare("UPDATE quotation_file_categories SET category_name=?,sort_order=?,show_in_list=?,tag_variables=? WHERE id=?")
                ->execute([$name, $order, $showInList, $tagVars, $catId]);
            echo json_encode(['success'=>true,'message'=>'已更新','cat_id'=>$catId]);
        } else {
            if (!$name) { echo json_encode(['success'=>false,'message'=>'類別名稱不可為空']); exit; }
            $pdo->prepare("INSERT INTO quotation_file_categories (category_name,sort_order,show_in_list,tag_variables) VALUES (?,?,?,?)")
                ->execute([$name, $order, $showInList, $tagVars]);
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
            // 寫入元數據
            $ins = $pdo->prepare("
                INSERT INTO quotation_attachments (quote_no, filename, original_name, file_size, uploaded_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([$quoteNo, $safeName, $originalName, $sizeStr, $uploadedBy]);
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
        $files   = [];
        if (!empty($base) && !empty($quoteNo)) {
            $dir = quoteDir($base, $quoteNo);
            if (is_dir($dir)) {
                // 讀 DB 元數據（JOIN 類別名稱）
                $stmt = $pdo->prepare("
                    SELECT a.id, a.filename, a.original_name, a.category_id, a.category_ids,
                           a.linked_parts, a.file_size,
                           DATE_FORMAT(a.uploaded_at,'%Y-%m-%d %H:%i') AS mtime,
                           c.category_name
                    FROM quotation_attachments a
                    LEFT JOIN quotation_file_categories c ON c.id = a.category_id
                    WHERE a.quote_no = ?
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

    default:
        echo json_encode(['success' => false, 'message' => '未知操作：' . $action]);
}
