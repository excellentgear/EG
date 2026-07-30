<?php
// Part_Attachment_API.php — 料號附件管理 API
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
require_once __DIR__ . '/../common/imgedit_visibility.php';
require_once __DIR__ . '/../common/dwg_change_lib.php';   // 發行章日期判定／建立圖面變更（唯一實作點）
$db  = new DBConnection();
$pdo = $db->getPDO();

// 報價查閱權限（沿用報價單 quotation_view；判定失敗採寬鬆，避免鎖死既有功能）
require_once __DIR__ . '/../common/rbac.php';
function _paQuotCanView(PDO $pdo): bool {
    try { return rbac_has(rbac_user_features($pdo, (int)($_SESSION['id'] ?? 0)), 'quotation_view'); }
    catch (Exception $_e) { return true; }
}

$uploadedById   = intval($_SESSION['id'] ?? 0);
$uploadedByName = $_SESSION['userName'] ?? '';

// ── 工具函式 ──────────────────────────────────────────────────────
function getPartAttachBase(PDO $pdo): string {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='part_attach_nas_dir'");
        $s->execute();
        $v = $s->fetchColumn();
        return ($v !== false && $v !== null) ? trim($v) : '';
    } catch (Exception $_e) { return ''; }
}
function getPartAttachUrlDir(PDO $pdo): string {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='part_attach_url_dir'");
        $s->execute();
        $v = $s->fetchColumn();
        return ($v !== false && $v !== null) ? trim($v) : '';
    } catch (Exception $_e) { return ''; }
}
function fmtSzPa(int $bytes): string {
    if ($bytes < 1024)      return $bytes . ' B';
    if ($bytes < 1048576)   return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
function initPartAttachTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS part_attachments (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        d_id            INT NOT NULL                        COMMENT '料號 d_setting.d_id',
        filename        VARCHAR(255) NOT NULL,
        original_name   VARCHAR(255) NULL,
        category_ids    VARCHAR(255) NULL                   COMMENT '逗號分隔的 quotation_file_categories.id',
        tag_var_values  TEXT NULL                           COMMENT 'JSON {cat_id:{key:value}}',
        file_size       VARCHAR(20) NULL,
        note            TEXT NULL,
        uploaded_by     VARCHAR(50) NULL,
        uploaded_by_id  INT NULL,
        uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        deleted_at      DATETIME NULL                       COMMENT '軟刪除時間，NULL=正常',
        deleted_by      VARCHAR(100) NULL                   COMMENT '刪除者',
        INDEX idx_did (d_id),
        INDEX idx_cat (category_ids(50))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='料號附件'");
    // 若舊表缺少軟刪除欄位則補加
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN deleted_at DATETIME NULL COMMENT '軟刪除時間，NULL=正常' AFTER updated_at"); } catch(Exception $_e){}
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN deleted_by VARCHAR(100) NULL COMMENT '刪除者' AFTER deleted_at"); } catch(Exception $_e){}
    try { $pdo->exec("ALTER TABLE part_attachments ADD INDEX idx_deleted (deleted_at)"); } catch(Exception $_e){}
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN revision VARCHAR(50) NULL COMMENT '版次' AFTER note"); } catch(Exception $_e){}
}

initPartAttachTables($pdo);
dwg_ensure_schema($pdo);   // issue_stamp_date / is_own_drawing / trigger_attachment_id 欄位補建


switch ($action) {

    // ── 取得路徑設定 ──────────────────────────────────────────────
    case 'get_path':
        echo json_encode([
            'success'  => true,
            'nas_dir'  => getPartAttachBase($pdo),
            'url_dir'  => getPartAttachUrlDir($pdo),
        ]);
        break;

    // ── 儲存路徑設定（僅 A 等級） ─────────────────────────────────
    case 'save_path':
        $nasDir = trim($_POST['nas_dir'] ?? '');
        $urlDir = trim($_POST['url_dir'] ?? '');
        if (!$nasDir || !$urlDir) { echo json_encode(['success'=>false,'message'=>'路徑不可為空']); exit; }
        try {
            $upsert = $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $upsert->execute(['part_attach_nas_dir', $nasDir]);
            $upsert->execute(['part_attach_url_dir', $urlDir]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        break;

    // ── 上傳附件 ──────────────────────────────────────────────────
    case 'upload':
        $dId = intval($_POST['d_id'] ?? 0);
        if (!$dId) { echo json_encode(['success'=>false,'message'=>'缺少料號 ID']); exit; }
        // 發行章日期：屬於「自家出的圖」標籤時必填（判準見 ai-rules/15-圖面變更判定依據.md）。
        // 檔案還沒搬進去之前先擋，免得擋下來還留下孤兒檔。
        $upCatIds  = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['category_ids'] ?? '')))));
        $upIssue   = trim($_POST['issue_stamp_date'] ?? '');
        if ($upIssue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $upIssue)) {
            echo json_encode(['success'=>false,'message'=>'發行章日期格式錯誤（需 YYYY-MM-DD）']); exit;
        }
        if (dwg_needs_issue_date($pdo, $upCatIds) && $upIssue === '') {
            echo json_encode(['success'=>false,'message'=>'此標籤屬於「自家出的圖」，請填發行章日期（預設帶今天，可改成圖上實際的蓋章日）']); exit;
        }
        if ($upIssue === '') $upIssue = null;
        // 判定要在寫入這一筆之前算，否則會拿自己跟自己比
        $dwgVerdict = dwg_classify_upload($pdo, $dId, $upCatIds, $upIssue);
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'message'=>'上傳失敗（'.(isset($_FILES['file']) ? $_FILES['file']['error'] : 'no file').')']); exit;
        }
        $base = getPartAttachBase($pdo);
        if (!$base) { echo json_encode(['success'=>false,'message'=>'尚未設定儲存路徑']); exit; }
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $dId . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            echo json_encode(['success'=>false,'message'=>'無法建立目錄']); exit;
        }
        $orig = basename($_FILES['file']['name']);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $blocked = ['php','php3','php4','php5','phtml','phar','exe','bat','sh','cmd','asp','aspx','jsp','py','rb','htaccess'];
        if (in_array($ext, $blocked) || $ext === '') { echo json_encode(['success'=>false,'message'=>'不允許此檔案類型']); exit; }
        $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) {
            echo json_encode(['success'=>false,'message'=>'檔案寫入失敗']); exit;
        }
        $catIds   = trim($_POST['category_ids']   ?? '') ?: null;
        $tagVarVals = trim($_POST['tag_var_values'] ?? '') ?: null;
        $note     = trim($_POST['note']            ?? '') ?: null;
        $revision = trim($_POST['revision']        ?? '') ?: null;
        $sz       = fmtSzPa((int)$_FILES['file']['size']);
        try {
            $pdo->prepare("INSERT INTO part_attachments (d_id,filename,original_name,category_ids,tag_var_values,file_size,note,revision,issue_stamp_date,uploaded_by,uploaded_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$dId, $fname, $orig, $catIds, $tagVarVals, $sz, $note, $revision, $upIssue, $uploadedByName, $uploadedById]);
            $newAttachId = (int)$pdo->lastInsertId();
            // dwg_verdict.kind='change' 時前端要跳出「填變更內容」表單，再呼叫 create_dwg_change
            echo json_encode(['success'=>true,'id'=>$newAttachId,'filename'=>$fname,'original_name'=>$orig,
                              'dwg_verdict'=>$dwgVerdict]);
        } catch (Exception $e) {
            @unlink($dir . $fname);
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── 列出料號附件（含報價單附件）──────────────────────────────
    case 'list':
        $dId = intval($_GET['d_id'] ?? $_POST['d_id'] ?? 0);
        if (!$dId) { echo json_encode(['success'=>false,'message'=>'缺少料號 ID']); exit; }
        // 懶惰清除：每次 list 順帶永久刪除超過30天的軟刪除記錄與實體檔案
        try {
            $expiredStmt = $pdo->query("SELECT id, filename, d_id FROM part_attachments WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $expired = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($expired) {
                $base = getPartAttachBase($pdo);
                $expIds = [];
                foreach ($expired as $ex) {
                    $fp = rtrim($base,'/\\') . DIRECTORY_SEPARATOR . $ex['d_id'] . DIRECTORY_SEPARATOR . $ex['filename'];
                    if (file_exists($fp)) @unlink($fp);
                    $expIds[] = (int)$ex['id'];
                }
                if ($expIds) {
                    $phEx = implode(',', array_fill(0, count($expIds), '?'));
                    $pdo->prepare("DELETE FROM part_attachments WHERE id IN ($phEx)")->execute($expIds);
                }
            }
        } catch(Exception $_e) {}

        // 1. 直接上傳的料號附件（JOIN user 取中文名稱）
        $partStmt = $pdo->prepare("SELECT pa.id,'part' AS source,pa.filename,pa.original_name,pa.category_ids,pa.tag_var_values,pa.file_size,pa.note,pa.revision,
            COALESCE(u.user_cname, pa.uploaded_by) AS uploaded_by, pa.uploaded_at, '' AS quote_no
            FROM part_attachments pa
            LEFT JOIN user u ON u.id = pa.uploaded_by_id
            WHERE pa.d_id=? AND pa.deleted_at IS NULL");
        $partStmt->execute([$dId]);
        $data = $partStmt->fetchAll(PDO::FETCH_ASSOC);
        // 批圖編輯器檔案依分享範圍過濾（私人/部門/指定人員，成對 PNG 跟隨工作檔）
        $data = imgedit_filter_attachment_rows($pdo, $data, $uploadedById, $dId);

        // 2. 報價單附件（明確 linked 或 linked_parts IS NULL 的「全部料號」附件）
        try {
            $dsStmt = $pdo->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
            $dsStmt->execute([$dId]);
            $dSettingId = $dsStmt->fetchColumn();
            // 報價附件需 quotation_view 權限才併入清單（無權限則此料號不回傳報價檔）
            if ($dSettingId && _paQuotCanView($pdo)) {
                $qStmt = $pdo->prepare("
                    SELECT a.id,'quote' AS source,a.filename,a.original_name,a.category_ids,
                           NULL AS tag_var_values,a.file_size,NULL AS note,
                           COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by,
                           a.uploaded_at, a.quote_no
                    FROM quotation_attachments a
                    LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
                    WHERE a.status = 'active' AND (
                        /* 明確 linked 此料號 */
                        (a.linked_parts IS NOT NULL AND JSON_CONTAINS(a.linked_parts, ?))
                        OR
                        /* linked_parts IS NULL = 此報價單全部料號；需確認此報價單包含此料號 */
                        (a.linked_parts IS NULL AND a.quote_no IN (
                            SELECT ql.quote_no
                            FROM quotation_item qi
                            JOIN quotation_list ql ON ql.quote_id = qi.quote_id
                            WHERE qi.d_setting_d_id = ?
                        )))
                ");
                $qStmt->execute([json_encode($dSettingId), $dId]);
                foreach ($qStmt->fetchAll(PDO::FETCH_ASSOC) as $qr) { $data[] = $qr; }
            }
        } catch (Exception $_e) { /* quotation tables might not exist */ }

        // 依上傳時間排序
        usort($data, function($a, $b) { return strcmp($b['uploaded_at'], $a['uploaded_at']); });

        $urlDir  = getPartAttachUrlDir($pdo);
        $qDlBase = '../../src/store/Quotation_File_API.php';
        foreach ($data as &$row) {
            if ($row['source'] === 'part') {
                $row['url'] = rtrim($urlDir,'/') . '/' . $dId . '/' . rawurlencode($row['filename']);
            } else {
                $row['url'] = $qDlBase . '?action=download&quote_no=' . urlencode($row['quote_no']) . '&filename=' . urlencode($row['filename']);
            }
        }
        echo json_encode(['success'=>true,'data'=>$data]);
        break;

    // ── 取得多個料號的最新附件（供列表顯示）─────────────────────
    case 'list_latest_for_parts':
        $dIds = json_decode($_POST['d_ids'] ?? '[]', true);
        if (!is_array($dIds) || empty($dIds)) { echo json_encode(['success'=>true,'data'=>[]]); exit; }
        $dIds = array_values(array_filter(array_map('intval', $dIds)));
        if (empty($dIds)) { echo json_encode(['success'=>true,'data'=>[]]); exit; }
        $ph = implode(',', array_fill(0, count($dIds), '?'));

        // 直接料號附件（JOIN user 取中文名稱）
        $pStmt = $pdo->prepare("SELECT pa.id,pa.d_id,'part' AS source,pa.filename,pa.original_name,pa.category_ids,pa.file_size,pa.uploaded_at,'' AS quote_no,
            COALESCE(u.user_cname, pa.uploaded_by) AS uploaded_by
            FROM part_attachments pa LEFT JOIN user u ON u.id = pa.uploaded_by_id
            WHERE pa.d_id IN ($ph) AND pa.deleted_at IS NULL ORDER BY pa.d_id, pa.uploaded_at DESC");
        $pStmt->execute($dIds);
        $allRows = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        // 批圖編輯器檔案依分享範圍過濾（避免私人檔成為列表顯示的「最新附件」）
        $allRows = imgedit_filter_attachment_rows($pdo, $allRows, $uploadedById);
        // 工作檔（.egwork.json）不是圖面、無法預覽，不可成為列表縮圖的「最新附件」
        $allRows = imgedit_strip_workfiles($allRows);

        // 報價單附件：先取各 d_id 對應的 D_Setting_Id
        try {
            $dsMapStmt = $pdo->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id IN ($ph)");
            $dsMapStmt->execute($dIds);
            $dsMap = []; // d_id → D_Setting_Id
            foreach ($dsMapStmt->fetchAll(PDO::FETCH_ASSOC) as $dm) { $dsMap[$dm['d_id']] = $dm['D_Setting_Id']; }

            // 反向 map: D_Setting_Id → d_id
            $revMap = array_flip($dsMap);
            if (!empty($revMap)) {
                // ── 情況1：linked_parts 明確含此料號的 D_Setting_Id ──────────
                $orClauses = [];
                $qParams   = [];
                foreach ($revMap as $dsid => $did) {
                    $orClauses[] = "JSON_CONTAINS(a.linked_parts, ?)";
                    $qParams[]   = json_encode($dsid);
                }
                $qSql  = "SELECT a.id, a.filename, a.original_name, a.category_ids, a.file_size,
                          COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by, a.uploaded_at, a.quote_no, a.linked_parts
                          FROM quotation_attachments a
                          LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
                          WHERE a.status = 'active' AND a.linked_parts IS NOT NULL AND (".implode(' OR ', $orClauses).")";
                $qRes  = $pdo->prepare($qSql);
                $qRes->execute($qParams);
                foreach ($qRes->fetchAll(PDO::FETCH_ASSOC) as $qr) {
                    $lp = json_decode($qr['linked_parts'], true) ?: [];
                    foreach ($lp as $lpId) {
                        if (isset($revMap[$lpId])) {
                            $allRows[] = [
                                'id'           => $qr['id'],
                                'd_id'         => $revMap[$lpId],
                                'source'       => 'quote',
                                'filename'     => $qr['filename'],
                                'original_name'=> $qr['original_name'],
                                'category_ids' => $qr['category_ids'],
                                'file_size'    => $qr['file_size'],
                                'uploaded_at'  => $qr['uploaded_at'],
                                'quote_no'     => $qr['quote_no'],
                            ];
                        }
                    }
                }

                // ── 情況2：linked_parts IS NULL = 此報價單全部料號 ──────────
                // 用 d_setting_d_id 直接對應，不需 JSON_CONTAINS
                $phDids = implode(',', array_fill(0, count($dIds), '?'));
                $nullSql = "SELECT a.id, a.filename, a.original_name, a.category_ids, a.file_size,
                            COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by, a.uploaded_at, a.quote_no,
                            qi.d_setting_d_id AS matched_d_id
                            FROM quotation_attachments a
                            LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
                            JOIN quotation_list ql ON ql.quote_no = a.quote_no
                            JOIN quotation_item qi ON qi.quote_id = ql.quote_id
                                AND qi.d_setting_d_id IN ($phDids)
                            WHERE a.status = 'active' AND a.linked_parts IS NULL";
                $nullRes = $pdo->prepare($nullSql);
                $nullRes->execute($dIds);
                // 去除重複（同一附件對應多個料號時已用 joined d_setting_d_id 區分）
                $seenNull = [];
                foreach ($nullRes->fetchAll(PDO::FETCH_ASSOC) as $nr) {
                    $key = $nr['id'] . '_' . $nr['matched_d_id'];
                    if (isset($seenNull[$key])) continue;
                    $seenNull[$key] = true;
                    $allRows[] = [
                        'id'           => $nr['id'],
                        'd_id'         => (int)$nr['matched_d_id'],
                        'source'       => 'quote',
                        'filename'     => $nr['filename'],
                        'original_name'=> $nr['original_name'],
                        'category_ids' => $nr['category_ids'],
                        'file_size'    => $nr['file_size'],
                        'uploaded_at'  => $nr['uploaded_at'],
                        'quote_no'     => $nr['quote_no'],
                        'uploaded_by'  => $nr['uploaded_by'],
                    ];
                }
            }
        } catch (Exception $_e) { /* quotation tables might not exist */ }

        // 依 d_id + uploaded_at DESC 排序，分組
        usort($allRows, function($a, $b) {
            if ($a['d_id'] !== $b['d_id']) return $a['d_id'] - $b['d_id'];
            return strcmp($b['uploaded_at'], $a['uploaded_at']);
        });

        $grouped = [];
        foreach ($allRows as $row) { $grouped[$row['d_id']][] = $row; }

        // 計算總數（分兩表查）
        $cntPStmt = $pdo->prepare("SELECT d_id, COUNT(*) AS cnt FROM part_attachments WHERE d_id IN ($ph) AND deleted_at IS NULL GROUP BY d_id");
        $cntPStmt->execute($dIds);
        $counts = [];
        foreach ($cntPStmt->fetchAll(PDO::FETCH_ASSOC) as $c) { $counts[$c['d_id']] = (int)$c['cnt']; }
        // 加上報價單附件數
        foreach ($grouped as $did => $files) {
            $qCnt = count(array_filter($files, function($f){ return $f['source'] === 'quote'; }));
            $counts[$did] = ($counts[$did] ?? 0) + $qCnt;
        }

        // 查各料號是否有報價單（不論有無附件，用 d_setting_d_id 比對）
        $quoteCounts = [];
        try {
            $qiCntStmt = $pdo->prepare("SELECT d_setting_d_id, COUNT(DISTINCT quote_id) AS cnt FROM quotation_item WHERE d_setting_d_id IN ($ph) GROUP BY d_setting_d_id");
            $qiCntStmt->execute($dIds);
            foreach ($qiCntStmt->fetchAll(PDO::FETCH_ASSOC) as $qc) { $quoteCounts[(int)$qc['d_setting_d_id']] = (int)$qc['cnt']; }
        } catch (Exception $_e) {}

        $urlDir  = getPartAttachUrlDir($pdo);
        $qDlBase = '../../src/store/Quotation_File_API.php';
        $result  = [];
        foreach ($grouped as $did => $files) {
            // 加 URL
            foreach ($files as &$f) {
                if ($f['source'] === 'part') {
                    $f['url'] = rtrim($urlDir,'/') . '/' . $did . '/' . rawurlencode($f['filename']);
                } else {
                    $f['url'] = $qDlBase . '?action=download&quote_no=' . urlencode($f['quote_no']) . '&filename=' . urlencode($f['filename']);
                }
            }
            unset($f);
            $result[$did] = [
                'latest'    => $files,
                'total'     => $counts[$did] ?? count($files),
                'url_base'  => rtrim($urlDir,'/') . '/' . $did . '/',
                'has_quote' => isset($quoteCounts[$did]) && $quoteCounts[$did] > 0,
            ];
        }
        // 有報價但無附件的料號也加入結果（供前端顯示報價按鈕）
        foreach ($dIds as $did) {
            if (!isset($result[$did]) && isset($quoteCounts[$did]) && $quoteCounts[$did] > 0) {
                $result[$did] = [
                    'latest'    => [],
                    'total'     => 0,
                    'url_base'  => rtrim($urlDir,'/') . '/' . $did . '/',
                    'has_quote' => true,
                ];
            }
        }
        echo json_encode(['success'=>true,'data'=>$result]);
        break;

    // ── 取得報價單摘要（for 料號附件瀏覽器的報價模式）────────────────
    case 'get_quote_summaries':
        if (!_paQuotCanView($pdo)) { echo json_encode(['success'=>true,'data'=>[]]); exit; }
        $quoteNos = json_decode($_POST['quote_nos'] ?? '[]', true);
        $dId = intval($_POST['d_id'] ?? 0);
        $partNo = trim($_POST['part_no'] ?? '');   // 文字料號（bom_viewer 用；一料號可對多客戶 d_id）
        if (!is_array($quoteNos)) $quoteNos = [];
        $quoteNos = array_values(array_unique(array_filter(array_map('trim', $quoteNos))));
        // 解析要過濾的整數 d_id 清單：優先 part_no（可多筆，跨客戶），否則單一 d_id
        $dIds = [];
        if ($partNo !== '') {
            try {
                $dsP = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ?");
                $dsP->execute([$partNo]);
                $dIds = array_map('intval', $dsP->fetchAll(PDO::FETCH_COLUMN));
            } catch(Throwable $_e) {}
        } elseif ($dId > 0) {
            $dIds = [$dId];
        }
        // quote_nos 為空但有 d_id → 直接撈此料號所有報價單號
        if (empty($quoteNos) && !empty($dIds)) {
            try {
                $phD = implode(',', array_fill(0, count($dIds), '?'));
                $qnStmt = $pdo->prepare("SELECT DISTINCT ql.quote_no FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id = qi.quote_id WHERE qi.d_setting_d_id IN ($phD)");
                $qnStmt->execute($dIds);
                $quoteNos = $qnStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch(Throwable $e) {}
        }
        if (empty($quoteNos)) { echo json_encode(['success'=>true,'data'=>[]]); exit; }
        try {
            $phQ = implode(',', array_fill(0, count($quoteNos), '?'));
            // 報價單主資料
            $qlStmt = $pdo->prepare("SELECT ql.quote_no, ql.quote_date, ql.client_name,
                    ql.total_amount, ql.valid_until AS valid_date,
                    COALESCE(u.user_cname, ql.updated_by) AS handler_name,
                    ql.note AS quote_note
                FROM quotation_list ql
                LEFT JOIN user u ON u.id = ql.updated_by
                WHERE ql.quote_no IN ($phQ) ORDER BY ql.quote_date DESC, ql.quote_no DESC");
            $qlStmt->execute($quoteNos);
            $headers = [];
            foreach ($qlStmt->fetchAll(PDO::FETCH_ASSOC) as $h) { $headers[$h['quote_no']] = $h; }

            // 報價品項（含製程，依 d_id 過濾；part_no 模式可多個 d_id）
            $dFilter = !empty($dIds) ? ("AND qi.d_setting_d_id IN (" . implode(',', array_fill(0, count($dIds), '?')) . ")") : "";
            $qiStmt = $pdo->prepare("SELECT qi.item_id, qi.quote_id, ql2.quote_no, qi.product_id,
                    qi.specification, qi.quantity, qi.unit, qi.unit_price, qi.is_tiered,
                    qi.process_group_type, qi.process_notes,
                    GROUP_CONCAT(
                        CASE WHEN pn.ProcessName IS NOT NULL
                        THEN CONCAT(pn.ProcessName, CHAR(9), COALESCE(qipm.note, ''))
                        ELSE NULL END
                        ORDER BY qipm.id SEPARATOR '|||'
                    ) AS process_list
                FROM quotation_item qi
                JOIN quotation_list ql2 ON ql2.quote_id = qi.quote_id AND ql2.quote_no IN ($phQ)
                LEFT JOIN quotation_item_process_map qipm ON qipm.quotation_item_id = qi.item_id
                LEFT JOIN process_no pn ON pn.ProcessNo = qipm.process_no
                $dFilter
                GROUP BY qi.item_id
                ORDER BY qi.item_id");
            $qiParams = $quoteNos;
            if (!empty($dIds)) $qiParams = array_merge($qiParams, $dIds);
            $qiStmt->execute($qiParams);
            $items = [];
            foreach ($qiStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $it['processes'] = [];
                if ($it['process_list']) {
                    foreach (explode('|||', $it['process_list']) as $pstr) {
                        $parts = explode("\t", $pstr, 2);
                        $it['processes'][] = ['name' => $parts[0], 'note' => trim($parts[1] ?? '')];
                    }
                }
                unset($it['process_list']);
                $items[$it['quote_no']][] = $it;
            }

            // 批次查 process_notes sub-tag 名稱
            $allSubTagIds = [];
            foreach ($items as &$qItems) {
                foreach ($qItems as &$it2) {
                    if ($it2['process_notes']) {
                        foreach (array_filter(array_map('intval', explode(',', $it2['process_notes']))) as $sid) {
                            $allSubTagIds[$sid] = true;
                        }
                    }
                }
            }
            unset($it2, $qItems);
            $subTagNames = [];
            if (!empty($allSubTagIds)) {
                $phSt = implode(',', array_fill(0, count($allSubTagIds), '?'));
                try {
                    $stStmt = $pdo->prepare("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag WHERE sub_tag_id IN ($phSt)");
                    $stStmt->execute(array_keys($allSubTagIds));
                    foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $st) { $subTagNames[(int)$st['sub_tag_id']] = $st['sub_tag_name']; }
                } catch(Throwable $_e) {}
            }
            foreach ($items as &$qItems) {
                foreach ($qItems as &$it2) {
                    $it2['subtags'] = [];
                    if ($it2['process_notes']) {
                        foreach (array_filter(array_map('intval', explode(',', $it2['process_notes']))) as $sid) {
                            if (isset($subTagNames[$sid])) $it2['subtags'][] = $subTagNames[$sid];
                        }
                    }
                }
            }
            unset($it2, $qItems);

            $result = [];
            foreach ($headers as $qno => $h) {
                $result[$qno] = $h;
                $result[$qno]['items'] = $items[$qno] ?? [];
            }
            echo json_encode(['success'=>true,'data'=>$result]);
        } catch(Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        break;

    // ── 更新附件 meta ─────────────────────────────────────────────
    case 'update_meta':
        $id      = intval($_POST['id'] ?? 0);
        $catIds  = trim($_POST['category_ids']   ?? '') ?: null;
        $tagVals = trim($_POST['tag_var_values'] ?? '') ?: null;
        $note    = trim($_POST['note']           ?? '') ?: null;
        $revision = trim($_POST['revision']      ?? '') ?: null;
        $mIssue   = trim($_POST['issue_stamp_date'] ?? '');
        if (!$id) { echo json_encode(['success'=>false,'message'=>'缺少 ID']); exit; }
        if ($mIssue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mIssue)) {
            echo json_encode(['success'=>false,'message'=>'發行章日期格式錯誤（需 YYYY-MM-DD）']); exit;
        }
        $mCatIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['category_ids'] ?? '')))));
        if (dwg_needs_issue_date($pdo, $mCatIds) && $mIssue === '') {
            echo json_encode(['success'=>false,'message'=>'此標籤屬於「自家出的圖」，請填發行章日期']); exit;
        }
        $pdo->prepare("UPDATE part_attachments SET category_ids=?,tag_var_values=?,note=?,revision=?,issue_stamp_date=? WHERE id=?")
            ->execute([$catIds, $tagVals, $note, $revision, ($mIssue !== '' ? $mIssue : null), $id]);
        echo json_encode(['success'=>true]);
        break;

    // ── 圖面變更：表單用的下拉資料（製程／簽收人員）────────────────
    // 人員一律走 people_lib（人員列表鐵則：只列未在職者以外、標記長期請假、依職稱排序並顯示職稱）
    case 'dwg_lookups':
        try {
            $proc = $pdo->query("SELECT ProcessNo, ProcessName FROM process_no ORDER BY ProcessNo")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $proc = []; }
        $people = [];
        try {
            require_once __DIR__ . '/../common/people_lib.php';
            $rows = eg_people_list($pdo);
            $multiDept = function_exists('eg_people_multi_dept') ? eg_people_multi_dept($rows) : false;
            foreach ($rows as $r) {
                $label = $r['user_cname'] . ($r['position_name'] ? '（' . $r['position_name'] . '）' : '');
                if ($multiDept && $r['dept_name']) $label = $r['dept_name'] . '　' . $label;
                if (!empty($r['leave_note'])) $label .= '　※' . $r['leave_note'];
                $people[] = ['id' => $r['id'], 'label' => $label];
            }
        } catch (Throwable $e) {}
        echo json_encode(['success'=>true,'processes'=>$proc,'people'=>$people], JSON_UNESCAPED_UNICODE);
        break;

    // ── 圖面變更：由附件上傳的自動判定產生一筆變更紀錄 ─────────────
    // 建立單號、檢驗標準整組複製新版次、簽收名單、通知全在 dwg_change_lib（與圖面變更紀錄頁同一套）
    case 'create_dwg_change':
        try {
            $aId = intval($_POST['attachment_id'] ?? 0);
            $st  = $pdo->prepare("SELECT id, d_id, original_name, issue_stamp_date FROM part_attachments WHERE id=? AND deleted_at IS NULL");
            $st->execute([$aId]);
            $att = $st->fetch(PDO::FETCH_ASSOC);
            if (!$att) throw new Exception('找不到這筆附件，可能已被刪除');
            $r = dwg_create_change($pdo, [
                'd_id'                  => (int)$att['d_id'],
                'summary'               => ($_POST['summary'] ?? ''),
                'detail'                => ($_POST['detail'] ?? ''),
                'old_revision'          => ($_POST['old_revision'] ?? ''),
                'new_revision'          => ($_POST['new_revision'] ?? ''),
                'change_date'           => ($att['issue_stamp_date'] ?: ''),   // 變更日＝發行章日期
                'source'                => ($_POST['source'] ?? ''),
                'customer_doc_no'       => ($_POST['customer_doc_no'] ?? ''),
                'from_process_no'       => ($_POST['from_process_no'] ?? ''),
                'ack_users'             => (array)($_POST['ack_users'] ?? []),
                'created_by'            => $uploadedById,
                'trigger_attachment_id' => (int)$att['id'],
            ]);
            echo json_encode(['success'=>true,'id'=>$r['id'],'change_no'=>$r['change_no'],
                              'new_version_id'=>$r['new_version_id']], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        break;

    // ── 刪除附件 ─────────────────────────────────────────────────
    case 'delete':
        $id  = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'缺少 ID']); exit; }
        // 取刪除者中文名稱
        $deleterName = $uploadedByName;
        if ($uploadedById) {
            try {
                $cnQ = $pdo->prepare("SELECT user_cname FROM user WHERE id=? LIMIT 1");
                $cnQ->execute([$uploadedById]);
                $cn = $cnQ->fetchColumn();
                if ($cn) $deleterName = $cn;
            } catch(Exception $_e) {}
        }
        $pdo->prepare("UPDATE part_attachments SET deleted_at=NOW(), deleted_by=? WHERE id=? AND deleted_at IS NULL")
            ->execute([$deleterName, $id]);
        echo json_encode(['success'=>true]);
        break;

    // ── 列出軟刪除記錄（30天內）──────────────────────────────────
    case 'list_deleted':
        $dId = intval($_POST['d_id'] ?? 0);
        if (!$dId) { echo json_encode(['success'=>false,'message'=>'缺少 ID']); exit; }
        try {
            $dlStmt = $pdo->prepare("SELECT pa.id, pa.original_name, pa.filename, pa.category_ids,
                    pa.deleted_at, pa.deleted_by, pa.file_size, pa.note
                FROM part_attachments pa
                WHERE pa.d_id=? AND pa.deleted_at IS NOT NULL
                    AND pa.deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY pa.deleted_at DESC");
            $dlStmt->execute([$dId]);
            $rows = $dlStmt->fetchAll(PDO::FETCH_ASSOC);
            // 補上類別名稱
            $cats = $pdo->query("SELECT id, category_name FROM quotation_file_categories WHERE is_active=1")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as &$r) {
                $r['category_names'] = '';
                if ($r['category_ids']) {
                    $names = [];
                    foreach (explode(',', $r['category_ids']) as $cid) {
                        $cid = (int)trim($cid);
                        if ($cid && isset($cats[$cid])) $names[] = $cats[$cid];
                    }
                    $r['category_names'] = implode('、', $names);
                }
            }
            unset($r);
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        break;

    // ── 還原軟刪除附件 ────────────────────────────────────────────
    case 'restore':
        $id  = intval($_POST['id'] ?? 0);
        $dId = intval($_POST['d_id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'缺少 ID']); exit; }
        try {
            $pdo->prepare("UPDATE part_attachments SET deleted_at=NULL, deleted_by=NULL WHERE id=? AND d_id=? AND deleted_at IS NOT NULL AND deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")
                ->execute([$id, $dId]);
            echo json_encode(['success'=>true]);
        } catch(Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        break;

    // ── 下載 / 預覽 ──────────────────────────────────────────────
    case 'download':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { http_response_code(404); exit; }
        $row = $pdo->prepare("SELECT filename, original_name, d_id FROM part_attachments WHERE id=?");
        $row->execute([$id]);
        $rec = $row->fetch(PDO::FETCH_ASSOC);
        if (!$rec) { http_response_code(404); exit; }
        $base = getPartAttachBase($pdo);
        $fp   = rtrim($base,'/\\') . DIRECTORY_SEPARATOR . $rec['d_id'] . DIRECTORY_SEPARATOR . $rec['filename'];
        if (!file_exists($fp)) { http_response_code(404); exit; }
        $ext2 = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
        $mime = match($ext2) {
            'pdf'  => 'application/pdf',
            'jpg','jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
        header('Content-Type: '.$mime);
        header('Content-Disposition: inline; filename="'.rawurlencode($rec['original_name'] ?: $rec['filename']).'"');
        header('Content-Length: '.filesize($fp));
        readfile($fp);
        exit;

    default:
        echo json_encode(['success'=>false,'message'=>'未知 action: '.$action]);
        break;
}
