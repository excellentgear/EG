<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header('Location: ../../index.php');
    exit();
}

// ── AJAX：取得檔名標籤設定 ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'get_file_tags_setting') {
    header('Content-Type: application/json');
    include_once '../../src/common/DBConnection.php';
    try {
        $pdo_t = (new DBConnection())->getPDO();
        $st = $pdo_t->prepare("SELECT param_value FROM system_parameters WHERE param_group='BOM_FILE_TAGS' AND param_key='tags_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $config = $row ? json_decode($row['param_value'], true) : [];
        echo json_encode(['success' => true, 'config' => $config ?: []]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX：儲存檔名標籤設定 ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'save_file_tags_setting') {
    header('Content-Type: application/json');
    include_once '../../src/common/DBConnection.php';
    $tags_config = $_POST['tags_config'] ?? '[]';
    $user = $_SESSION['id'] ?? 'system';
    try {
        $pdo_t = (new DBConnection())->getPDO();
        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
                VALUES ('BOM_FILE_TAGS', 'tags_config', :val, 'BOM檔案標籤設定', :user, NOW())
                ON DUPLICATE KEY UPDATE param_value = :val_upd, updated_by = :user_upd, updated_at = NOW()";
        $st = $pdo_t->prepare($sql);
        $st->execute([':val' => $tags_config, ':user' => $user, ':val_upd' => $tags_config, ':user_upd' => $user]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── 自家 AJAX：d_id 模式檔案清單 ─────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'get_files_by_did') {
    header('Content-Type: application/json');
    try {
        $did = trim($_POST['d_id'] ?? '');
        if (empty($did)) throw new Exception('缺少 d_id');
        include_once '../../src/common/DBConnection.php';
        require_once '../../src/common/part_alias_lib.php';
        $pdo2 = (new DBConnection())->getPDO();
        // 客戶代號／等同料號綁定的其他料號圖檔也一併撈出（合併顯示，標明來源）
        $bindLabelByPartNo = [];
        $partNos = [$did];
        foreach (eg_part_alias_linked_part_nos($pdo2, $did) as $lp) {
            $bindLabelByPartNo[$lp['part_no']] = $lp['part_no'] . ($lp['customer_name'] ? '／' . $lp['customer_name'] : '');
            $partNos[] = $lp['part_no'];
        }
        $partNos = array_values(array_unique($partNos));
        $phBom = implode(',', array_fill(0, count($partNos), '?'));
        $stmt = $pdo2->prepare("SELECT bom, sqty, d_id FROM bom WHERE d_id IN ($phBom) ORDER BY Created_At DESC");
        $stmt->execute($partNos);
        $bom_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // BOM 圖檔資料夾一律走設定鍵 bom_scan_dir（唯一實作 src/common/bom_dir_lib.php）。
        // 2026-08-25 起預設值改成 UNC `\\excellentnas\生產課\BOM\`：原本寫死的 Z: 是使用者
        // session 層級的持續連線，實測 `net use` 會變成「無法使用」，造成圖面時好時壞。
        require_once __DIR__ . '/../../src/common/bom_dir_lib.php';
        $scan_dir = eg_bom_fs_path(eg_bom_scan_dir($pdo2)); $url_dir = '/nas/';
        $files = [];
        // 載入 tags_config（自動檔名標籤設定）
        $tagsConfig = [];
        try {
            $tRow = $pdo2->query("SELECT param_value FROM system_parameters WHERE param_group='BOM_FILE_TAGS' AND param_key='tags_config'")->fetch(PDO::FETCH_ASSOC);
            if ($tRow) $tagsConfig = json_decode($tRow['param_value'], true) ?: [];
        } catch (Exception $_te) {}

        // 依檔名套用標籤的輔助函式
        $applyTags = function($filename) use ($tagsConfig) {
            $tags = [];
            $nameNoExt = pathinfo($filename, PATHINFO_FILENAME);
            foreach ($tagsConfig as $t) {
                $suffix = $t['suffix'] ?? '';
                if ($suffix !== '' && strpos($nameNoExt, $suffix) !== false) {
                    $tags[] = ['label' => $t['label'] ?? $suffix, 'color' => $t['color'] ?? '#777'];
                }
            }
            return $tags;
        };

        if (is_dir($scan_dir) && !empty($bom_rows)) {
            // 檔名一律經 bom_dir_lib 轉回 UTF-8（UNC 中文路徑在 Windows 可能是 Big5，
            // 漏轉不會報錯、只會整批比對不到＝畫面變成「沒有圖面」）；
            // withMtime=false：這個資料夾有 1.9 萬個檔又在網路磁碟上，逐檔 stat 會慢到分鐘級
            $allF = array_column(eg_bom_scan($scan_dir, [], '', false), 'name');
            foreach ($bom_rows as $row) {
                $bname = $row['bom']; $sqty = $row['sqty'];
                $bindFrom = $bindLabelByPartNo[$row['d_id']] ?? null;
                foreach ($allF as $fn) {
                    if ($fn==='.'||$fn==='..') continue;
                    if (strpos($fn, $bname) === 0) {
                        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
                            $label = $bname . ($sqty !== null ? ' (Qty:'.$sqty.')' : '');
                            $files[] = ['path'=>$url_dir.$fn, 'type'=>$ext, 'name'=>$fn,
                                        'label'=>$label, 'tags'=>$applyTags($fn), 'is_plus'=>false,
                                        'bom'=>$bname, 'bind_from'=>$bindFrom];
                        }
                    }
                }
            }
            // 排序：BOM 號碼新到舊；同一 BOM 內，檔名恰為純 BOM 號碼者最上（視為最新版），
            // 其後才是帶後綴的變體（如 B-xxx++、B-xxx ++）
            usort($files, function($a,$b){
                if ($a['bom'] !== $b['bom']) return strcmp($b['bom'], $a['bom']);
                $aPlain = (pathinfo($a['name'], PATHINFO_FILENAME) === $a['bom']) ? 0 : 1;
                $bPlain = (pathinfo($b['name'], PATHINFO_FILENAME) === $b['bom']) ? 0 : 1;
                if ($aPlain !== $bPlain) return $aPlain - $bPlain;
                return strcmp($a['name'], $b['name']);
            });
        }
        echo json_encode(['success'=>true, 'files'=>$files, 'erp_files'=>[]]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX：取得料號附件列表（供 bom_viewer 顯示）──────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'get_attachments_by_did') {
    header('Content-Type: application/json');
    try {
        // bom_viewer 傳入的是文字料號（order_track.d_id），需先查 d_setting 取整數 PK
        $partNo = trim($_POST['d_id'] ?? '');
        if (!$partNo) throw new Exception('缺少料號');
        include_once '../../src/common/DBConnection.php';
        require_once '../../src/common/part_alias_lib.php';
        $pdo2 = (new DBConnection())->getPDO();
        // 找出所有符合此料號的 d_setting.d_id（可能有多筆，不同客戶）
        $dsStmt = $pdo2->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ?");
        $dsStmt->execute([$partNo]);
        $dids = array_map('intval', $dsStmt->fetchAll(PDO::FETCH_COLUMN));
        // 客戶代號／等同料號綁定的其他料號附件也一併撈出（合併顯示，標明來源）
        $bindLabelByDid = [];
        foreach (eg_part_alias_linked_part_nos($pdo2, $partNo) as $lp) {
            $bindLabelByDid[$lp['d_id']] = $lp['part_no'] . ($lp['customer_name'] ? '／' . $lp['customer_name'] : '');
            $dids[] = $lp['d_id'];
        }
        $dids = array_values(array_unique($dids));
        if (empty($dids)) {
            echo json_encode(['success' => true, 'attachments' => []]);
            exit;
        }
        // 附件連結改走 Part_Attachment_API 的 download（見下方），不再需要 part_attach_url_dir 前綴
        // 附件清單（支援多筆 d_id）
        $ph = implode(',', array_fill(0, count($dids), '?'));
        $stmt = $pdo2->prepare("SELECT pa.id, pa.d_id, pa.filename, pa.original_name, pa.category_ids, pa.file_size, pa.note,
            pa.revision, pa.issue_stamp_date, pa.album_id,
            COALESCE(u.user_cname, pa.uploaded_by) AS uploaded_by, pa.uploaded_at
            FROM part_attachments pa
            LEFT JOIN user u ON u.id = pa.uploaded_by_id
            WHERE pa.d_id IN ($ph) AND pa.deleted_at IS NULL
            ORDER BY pa.uploaded_at DESC");
        $stmt->execute($dids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // 批圖編輯器檔案依分享範圍過濾（私人/部門/指定人員）
        require_once __DIR__ . '/../../src/common/imgedit_visibility.php';
        $rows = imgedit_filter_attachment_rows($pdo2, $rows, intval($_SESSION['id'] ?? 0));
        $rows = imgedit_strip_workfiles($rows, $pdo2);   // 工作檔與它配對的暫存輸出圖只在批圖編輯器裡看得到，檢視端不列
        // 類別名稱對照（順便取 is_own_drawing：自家出的圖才需要發行章日期，沒填要標紅提醒）
        $cats = [];
        $ownDrawCats = [];
        try {
            $cStmt = $pdo2->query("SELECT id, category_name, is_own_drawing FROM quotation_file_categories WHERE is_active = 1");
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cats[(int)$c['id']] = $c['category_name'];
                if (!empty($c['is_own_drawing'])) $ownDrawCats[(int)$c['id']] = true;
            }
        } catch (Exception $_e) {}
        // 相簿（產品照片這類標籤）：判定與相簿清單一律走共用庫 photo_album_lib，
        // 不在本頁再掃一次綁定（兩邊各刻一份必定走鐘＝鐵律4）
        require_once __DIR__ . '/../../src/common/photo_album_lib.php';
        $albumCatIds = pa_album_cat_ids($pdo2);
        $albums      = pa_album_list($pdo2, $dids);
        $albumNameOf = [];
        foreach ($albums as $a) $albumNameOf[(int)$a['id']] = $a['album_name'];
        $result = [];
        foreach ($rows as $r) {
            $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
            $catNames = [];
            $isOwnDraw = false;
            if ($r['category_ids']) {
                foreach (explode(',', $r['category_ids']) as $cid) {
                    $cid = (int)trim($cid);
                    if (isset($cats[$cid])) $catNames[] = $cats[$cid];
                    if (isset($ownDrawCats[$cid])) $isOwnDraw = true;
                }
            }
            $type = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']) ? 'image'
                  : ($ext === 'pdf' ? 'pdf' : 'other');
            // 每個附件依其 d_id 子目錄取 URL
            // 走附件 API 讀檔（inline 輸出），不再用 Apache /nas 別名直連：
            // 附件位置只由 part_attach_nas_dir 決定，換 NAS 免改 httpd.conf 也不綁磁碟機代號
            $fileUrl = '../../src/store/Part_Attachment_API.php?action=download&id=' . (int)$r['id'];
            $result[] = [
                'id'             => (int)$r['id'],
                'filename'       => $r['filename'],
                'display_name'   => $r['original_name'] ?: $r['filename'],
                'url'            => $fileUrl,
                'ext'            => $ext,
                'type'           => $type,
                'file_size'      => $r['file_size'] ?: '',
                'note'           => $r['note'] ?: '',
                'uploaded_by'    => $r['uploaded_by'] ?: '',
                'uploaded_at'    => substr($r['uploaded_at'] ?: '', 0, 16),
                'category_names' => $catNames,
                // 版次／發行章日期：只有料號附件（part_attachments）有這兩欄；
                // 發行章日期是圖面變更的新舊判定依據（ai-rules/15），檢視端也要看得到。
                'revision'         => ($r['revision'] === null ? '' : (string)$r['revision']),
                'issue_stamp_date' => $r['issue_stamp_date'] ?: '',
                'is_own_drawing'   => $isOwnDraw ? 1 : 0,
                // is_album＝此附件屬於「以相簿檢視」的標籤（標籤設定勾的，不寫死標籤名稱）
                'is_album'         => pa_album_cat_of($r['category_ids'], $albumCatIds) > 0,
                'album_id'         => $r['album_id'] ? (int)$r['album_id'] : 0,
                'album_name'       => ($r['album_id'] && isset($albumNameOf[(int)$r['album_id']])) ? $albumNameOf[(int)$r['album_id']] : '',
                'bind_from'      => $bindLabelByDid[(int)$r['d_id']] ?? null,
                'source'         => 'other',
            ];
        }
        // 併入標記「show_in_other_attach」的報價附件／訂單附件（master_data_management.php 類別字典設定），
        // 不影響它們原本上傳位置（報價資料／訂單附件分頁）仍然照常顯示，這裡只是額外一併帶出。
        try {
            $showOtherIds = array_map('intval', $pdo2->query("SELECT id FROM quotation_file_categories WHERE show_in_other_attach=1")->fetchAll(PDO::FETCH_COLUMN));
            if ($showOtherIds && $dids) {
                $phD2 = implode(',', array_fill(0, count($dids), '?'));
                $catCond = implode(' OR ', array_fill(0, count($showOtherIds), 'FIND_IN_SET(?, a.category_ids)'));

                $qStmt = $pdo2->prepare("SELECT DISTINCT a.id, a.filename, a.original_name, a.category_ids, a.file_size, a.uploaded_at,
                                                COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by, a.quote_no
                                         FROM quotation_attachments a
                                         LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
                                         JOIN quotation_list ql ON ql.quote_no = a.quote_no
                                         JOIN quotation_item qi ON qi.quote_id = ql.quote_id
                                         WHERE a.status='active' AND qi.d_setting_d_id IN ($phD2) AND ($catCond)");
                $qStmt->execute(array_merge($dids, $showOtherIds));
                foreach ($qStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
                    $catNames = [];
                    foreach (array_filter(explode(',', (string)$r['category_ids'])) as $cid) { if (isset($cats[(int)$cid])) $catNames[] = $cats[(int)$cid]; }
                    $result[] = [
                        'id'             => 'q' . $r['id'],
                        'filename'       => $r['filename'],
                        'display_name'   => $r['original_name'] ?: $r['filename'],
                        'url'            => '../../src/store/Quotation_File_API.php?action=download&quote_no=' . urlencode($r['quote_no']) . '&filename=' . urlencode($r['filename']),
                        'ext'            => $ext,
                        'type'           => in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'other'),
                        'file_size'      => $r['file_size'] ?: '',
                        'note'           => '來自報價單 ' . $r['quote_no'],
                        'uploaded_by'    => $r['uploaded_by'] ?: '',
                        'uploaded_at'    => substr($r['uploaded_at'] ?: '', 0, 16),
                        'category_names' => $catNames,
                        'bind_from'      => null,
                        'source'         => 'quote',
                    ];
                }

                $oStmt = $pdo2->prepare("SELECT a.id, a.filename, a.original_name, a.category_ids, a.file_size, a.uploaded_at,
                                                COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by, ot.Order_oo
                                         FROM order_attachments a
                                         JOIN order_track ot ON ot.Order_id = a.order_id
                                         LEFT JOIN user u ON u.id = a.uploaded_by
                                         WHERE a.status='active' AND ot.d_id_ID IN ($phD2) AND ($catCond)");
                $oStmt->execute(array_merge($dids, $showOtherIds));
                foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
                    $catNames = [];
                    foreach (array_filter(explode(',', (string)$r['category_ids'])) as $cid) { if (isset($cats[(int)$cid])) $catNames[] = $cats[(int)$cid]; }
                    $result[] = [
                        'id'             => 'o' . $r['id'],
                        'filename'       => $r['filename'],
                        'display_name'   => $r['original_name'] ?: $r['filename'],
                        'url'            => '../../src/store/Order_Attachment_API.php?action=download&id=' . (int)$r['id'],
                        'ext'            => $ext,
                        'type'           => in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'other'),
                        'file_size'      => $r['file_size'] ?: '',
                        'note'           => '來自訂單 ' . $r['Order_oo'],
                        'uploaded_by'    => $r['uploaded_by'] ?: '',
                        'uploaded_at'    => substr($r['uploaded_at'] ?: '', 0, 16),
                        'category_names' => $catNames,
                        'bind_from'      => null,
                        'source'         => 'order',
                    ];
                }
            }
        } catch (Exception $_e) {}
        echo json_encode(['success' => true, 'attachments' => $result, 'albums' => $albums], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX：取得報價單附件（報價資料分頁，唯讀；需 quotation_view 權限）──────
if (isset($_POST['action']) && $_POST['action'] === 'get_quote_attachments_by_did') {
    header('Content-Type: application/json');
    try {
        $partNo = trim($_POST['d_id'] ?? '');
        if (!$partNo) throw new Exception('缺少料號');
        include_once '../../src/common/DBConnection.php';
        require_once __DIR__ . '/../../src/common/rbac.php';
        require_once __DIR__ . '/../../src/common/part_alias_lib.php';
        $pdo2 = (new DBConnection())->getPDO();
        // 權限守門：報價資料查閱沿用報價單「檢視」權限（quotation_view）；無權限一律回空
        $feats = rbac_user_features($pdo2, (int)($_SESSION['id'] ?? 0));
        if (!rbac_has($feats, 'quotation_view')) {
            echo json_encode(['success' => true, 'attachments' => [], 'no_perm' => true]);
            exit;
        }
        // 找出所有符合此料號的 d_setting.d_id（可能多筆，不同客戶）
        $dsStmt = $pdo2->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ?");
        $dsStmt->execute([$partNo]);
        $ownDids = array_map('intval', $dsStmt->fetchAll(PDO::FETCH_COLUMN));
        if (empty($ownDids)) { echo json_encode(['success' => true, 'attachments' => []]); exit; }
        // 客戶代號／等同料號綁定的其他料號報價也一併撈出（合併顯示，標明來源）
        $linkedParts = eg_part_alias_linked_part_nos($pdo2, $partNo);
        $bindLabelByPartNo = []; $bindLabelByDid = []; $allPartNos = [$partNo]; $dids = $ownDids;
        foreach ($linkedParts as $lp) {
            $label = $lp['part_no'] . ($lp['customer_name'] ? '／' . $lp['customer_name'] : '');
            $bindLabelByPartNo[$lp['part_no']] = $label;
            $bindLabelByDid[$lp['d_id']] = $label;
            $allPartNos[] = $lp['part_no'];
            $dids[] = $lp['d_id'];
        }
        $dids = array_values(array_unique($dids));
        $ph = implode(',', array_fill(0, count($dids), '?'));
        $jsonConds = implode(' OR ', array_fill(0, count($allPartNos), 'JSON_CONTAINS(a.linked_parts, ?)'));
        // 報價附件：linked_parts JSON 含此料號（或綁定料號），或 linked_parts NULL 且該報價單包含此料號（或綁定料號）
        // status='active'：只顯示正式附件，隱藏 temp(未存檔)/pending(補件待審)/trash(已否決)
        $sql = "SELECT a.id, a.filename, a.original_name, a.category_ids, a.file_size, a.quote_no, a.linked_parts,
                       COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by, a.uploaded_at
                FROM quotation_attachments a
                LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
                WHERE a.status = 'active' AND (
                      (a.linked_parts IS NOT NULL AND ($jsonConds))
                   OR (a.linked_parts IS NULL AND a.quote_no IN (
                        SELECT ql.quote_no FROM quotation_item qi
                        JOIN quotation_list ql ON ql.quote_id = qi.quote_id
                        WHERE qi.d_setting_d_id IN ($ph))))
                ORDER BY a.uploaded_at DESC";
        $stmt = $pdo2->prepare($sql);
        $stmt->execute(array_merge(array_map('json_encode', $allPartNos), $dids));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // linked_parts 為 NULL 分支：靠 quote_no 對應的品項料號判斷是否「只透過綁定料號才對得上」
        $quoteBindLabel = [];
        if ($linkedParts) {
            try {
                $qnos = array_values(array_unique(array_column($rows, 'quote_no')));
                if ($qnos) {
                    $qph = implode(',', array_fill(0, count($qnos), '?'));
                    $mapStmt = $pdo2->prepare("SELECT DISTINCT ql.quote_no, qi.d_setting_d_id FROM quotation_item qi
                                                JOIN quotation_list ql ON ql.quote_id = qi.quote_id
                                                WHERE ql.quote_no IN ($qph)");
                    $mapStmt->execute($qnos);
                    $qnoDids = [];
                    foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $m) $qnoDids[$m['quote_no']][] = (int)$m['d_setting_d_id'];
                    foreach ($qnoDids as $qno => $dl) {
                        if (array_intersect($dl, $ownDids)) continue;   // 本身料號的品項就對得上，不算綁定帶入
                        foreach ($dl as $d0) { if (isset($bindLabelByDid[$d0])) { $quoteBindLabel[$qno] = $bindLabelByDid[$d0]; break; } }
                    }
                }
            } catch (Exception $_e) {}
        }
        // 類別名稱對照
        $cats = [];
        try {
            foreach ($pdo2->query("SELECT id, category_name FROM quotation_file_categories WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cats[(int)$c['id']] = $c['category_name'];
            }
        } catch (Exception $_e) {}
        $qDlBase = '../../src/store/Quotation_File_API.php';
        $result = [];
        foreach ($rows as $r) {
            $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
            $catNames = [];
            if ($r['category_ids']) {
                foreach (explode(',', $r['category_ids']) as $cid) {
                    $cid = (int)trim($cid);
                    if (isset($cats[$cid])) $catNames[] = $cats[$cid];
                }
            }
            $bindFrom = null;
            $lpJson = $r['linked_parts'] ? json_decode($r['linked_parts'], true) : null;
            if (is_array($lpJson)) {
                if (!in_array($partNo, $lpJson, true)) {
                    foreach ($lpJson as $x) { if (isset($bindLabelByPartNo[$x])) { $bindFrom = $bindLabelByPartNo[$x]; break; } }
                }
            } else {
                $bindFrom = $quoteBindLabel[$r['quote_no']] ?? null;
            }
            $result[] = [
                'id'             => (int)$r['id'],
                'filename'       => $r['filename'],
                'display_name'   => $r['original_name'] ?: $r['filename'],
                'url'            => $qDlBase . '?action=download&quote_no=' . urlencode($r['quote_no']) . '&filename=' . urlencode($r['filename']),
                'ext'            => $ext,
                'file_size'      => $r['file_size'] ?: '',
                'note'           => '',
                'uploaded_by'    => $r['uploaded_by'] ?: '',
                'uploaded_at'    => substr($r['uploaded_at'] ?: '', 0, 16),
                'category_names' => $catNames,
                'quote_no'       => $r['quote_no'],
                'bind_from'      => $bindFrom,
                'source'         => 'quote',
            ];
        }
        echo json_encode(['success' => true, 'attachments' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX：取得訂單附件（訂單附件分頁，唯讀）─────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'get_order_attachments_by_did') {
    header('Content-Type: application/json');
    try {
        $partNo = trim($_POST['d_id'] ?? '');
        if (!$partNo) throw new Exception('缺少料號');
        include_once '../../src/common/DBConnection.php';
        require_once __DIR__ . '/../../src/common/part_alias_lib.php';
        $pdo2 = (new DBConnection())->getPDO();

        $dsStmt = $pdo2->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ?");
        $dsStmt->execute([$partNo]);
        $ownDids = array_map('intval', $dsStmt->fetchAll(PDO::FETCH_COLUMN));
        if (empty($ownDids)) { echo json_encode(['success' => true, 'attachments' => []]); exit; }

        // 客戶代號／等同料號綁定的其他料號訂單也一併撈出（合併顯示，標明來源）
        $bindLabelByDid = []; $dids = $ownDids;
        foreach (eg_part_alias_linked_part_nos($pdo2, $partNo) as $lp) {
            $bindLabelByDid[$lp['d_id']] = $lp['part_no'] . ($lp['customer_name'] ? '／' . $lp['customer_name'] : '');
            $dids[] = $lp['d_id'];
        }
        $dids = array_values(array_unique($dids));
        $ph = implode(',', array_fill(0, count($dids), '?'));

        // order_attachments 建單當下就已把料號歸屬解析為真實 order_id（見 _NewOrder_Track222.php 的
        // create_orders_from_quotes／or_new），這裡直接 join order_track 依 d_id_ID 找即可，
        // 不需要像報價附件那樣另外解析 linked_parts JSON
        $sql = "SELECT a.id, a.filename, a.original_name, a.category_ids, a.file_size,
                       COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by, a.uploaded_at,
                       ot.Order_oo, ot.d_id_ID, ot.Order_date, ot.Delivery_date, ot.Qty, ot.Client_name, ot.quote_no
                FROM order_attachments a
                JOIN order_track ot ON ot.Order_id = a.order_id
                LEFT JOIN user u ON u.id = a.uploaded_by
                WHERE a.status='active' AND ot.d_id_ID IN ($ph)
                ORDER BY a.uploaded_at DESC";
        $stmt = $pdo2->prepare($sql);
        $stmt->execute($dids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cats = [];
        try {
            foreach ($pdo2->query("SELECT id, category_name FROM quotation_file_categories WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cats[(int)$c['id']] = $c['category_name'];
            }
        } catch (Exception $_e) {}
        $dlBase = '../../src/store/Order_Attachment_API.php';
        $result = [];
        // 同一訂單編號＋同一份實體檔案只顯示一列：一張訂單編號底下若有多列料號（同料號分行或多料號），
        // 「共用附件」在建單時會被複製掛到每一列（見 _NewOrder_Track.php 的 eg_sync_shared_order_attachments），
        // 實體檔案其實只有一份；這裡不去重會讓使用者只上傳一個附件卻在本頁看到兩個。
        $seenOrderFile = [];
        foreach ($rows as $r) {
            $dedupKey = $r['Order_oo'] . '|' . $r['filename'];
            if (isset($seenOrderFile[$dedupKey])) continue;
            $seenOrderFile[$dedupKey] = true;
            $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
            $catNames = [];
            if ($r['category_ids']) {
                foreach (explode(',', $r['category_ids']) as $cid) {
                    $cid = (int)trim($cid);
                    if (isset($cats[$cid])) $catNames[] = $cats[$cid];
                }
            }
            $bindFrom = isset($bindLabelByDid[(int)$r['d_id_ID']]) ? $bindLabelByDid[(int)$r['d_id_ID']] : null;
            $result[] = [
                'id'             => (int)$r['id'],
                'filename'       => $r['filename'],
                'display_name'   => $r['original_name'] ?: $r['filename'],
                'url'            => $dlBase . '?action=download&id=' . (int)$r['id'],
                'ext'            => $ext,
                'file_size'      => $r['file_size'] ?: '',
                'note'           => '訂單 ' . $r['Order_oo'],
                'uploaded_by'    => $r['uploaded_by'] ?: '',
                'uploaded_at'    => substr($r['uploaded_at'] ?: '', 0, 16),
                'category_names' => $catNames,
                'bind_from'      => $bindFrom,
                'source'         => 'order',
                'order_oo'       => $r['Order_oo'],
                'order_date'     => $r['Order_date'] ? substr($r['Order_date'], 0, 10) : null,
                'delivery_date'  => $r['Delivery_date'] ? substr($r['Delivery_date'], 0, 10) : null,
                'qty'            => $r['Qty'] !== null ? (int)$r['Qty'] : null,
                'client_name'    => $r['Client_name'] ?: null,
                'quote_no'       => $r['quote_no'] ?: null,
            ];
        }
        echo json_encode(['success' => true, 'attachments' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── 模式判斷：?bom=… 或 ?d_id=… ─────────────────────────────────────────
$bom  = trim($_GET['bom']  ?? '');
$d_id = trim($_GET['d_id'] ?? '');
$mode = '';   // 'bom' | 'did'

if (!empty($bom)) {
    $mode = 'bom';
} elseif (!empty($d_id)) {
    $mode = 'did';
    $bom  = $d_id;   // 用作標題顯示
} else {
    die('缺少 BOM 或 d_id 參數');
}
$bom_safe = htmlspecialchars($bom, ENT_QUOTES, 'UTF-8');

// ── 批圖編輯器按鈕權限（imgedit 模組；與 image_editor.php 進入時同一套判定）──
// 未被指派「批圖使用者」角色者不顯示按鈕；判定失敗預設不顯示（editor 進入時仍有自身閘門）。
$imgeditCanUse = false;
try {
    include_once __DIR__ . '/../../src/common/DBConnection.php';
    require_once __DIR__ . '/../../src/common/imgedit_permission.php';
    $pdoPerm    = (new DBConnection())->getPDO();
    $permUid    = (int)($_SESSION['id'] ?? 0);
    $permStatus = (int)($_SESSION['status'] ?? 0);
    // session 缺 status/id 時回查 user 表補上，避免管理者判定落空
    if (($permStatus === 0 || $permUid === 0) && ($_SESSION['userName'] ?? '') !== '') {
        $stS = $pdoPerm->prepare("SELECT id, user_status FROM user WHERE user_uname = ? AND state != 0 LIMIT 1");
        $stS->execute([$_SESSION['userName']]);
        if ($rS = $stS->fetch(PDO::FETCH_ASSOC)) {
            if ($permUid === 0)    $permUid    = (int)$rS['id'];
            if ($permStatus === 0) $permStatus = (int)$rS['user_status'];
        }
    }
    $imgeditCanUse = imgedit_can_use($pdoPerm, $permUid, in_array($permStatus, [9, 90], true));
} catch (Exception $e) {
    $imgeditCanUse = false;
}

// ── 分頁權限（僅 did 模式有「訂單／報價 / 其他附件」分頁；唯讀查閱）────────────
// 圖面：一律開放。報價（訂單／報價分頁內的報價區塊）：需 quotation_view（沿用報價單檢視權限）。
// 其他附件：過渡期 — 未指派 master_data 角色者維持開放；已指派則需 md_attach_view。
$canQuoteView = false;
$canOtherView = true;
try {
    require_once __DIR__ . '/../../src/common/rbac.php';
    if (empty($pdoPerm)) {
        include_once __DIR__ . '/../../src/common/DBConnection.php';
        $pdoPerm = (new DBConnection())->getPDO();
    }
    $uidF = (int)($permUid ?? ($_SESSION['id'] ?? 0));
    $featsF = rbac_user_features($pdoPerm, $uidF);
    $isAdminF = rbac_has($featsF, 'all');
    $canQuoteView = $isAdminF || rbac_has($featsF, 'quotation_view');
    $rq = $pdoPerm->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.module='master_data' LIMIT 1");
    $rq->execute([$uidF]);
    $hasMdRole = (bool)$rq->fetchColumn();
    $canOtherView = $isAdminF || !$hasMdRole || rbac_has($featsF, 'md_attach_view');
} catch (Exception $_e) { $canQuoteView = false; $canOtherView = true; }

// 開啟時預選分頁：?tab=drawing|quote|other|order_attach（預設自動挑第一個有資料的分頁）
$initTab = trim($_GET['tab'] ?? '');
if (!in_array($initTab, ['drawing','quote','other','order_attach'], true)) $initTab = '';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>圖面查閱 — <?= $bom_safe ?></title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f5f5; overflow: hidden; }
        .main-wrap { display: flex; height: 100vh; overflow: hidden; }

        /* ── 左側檔案列表面板 ── */
        #file-panel {
            width: 280px; min-width: 180px; overflow-y: auto; height: 100vh;
            background: #fff; border-right: 1px solid #ddd; flex-shrink: 0;
        }
        #file-panel-heading {
            padding: 9px 12px; font-weight: bold; font-size: 13px; color: #555;
            background: #f7f7f7; border-bottom: 1px solid #e0e0e0; word-break: break-all;
        }

        /* ── 右側查閱面板 ── */
        #viewer-panel { flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        #viewer-toolbar {
            padding: 6px 10px; background: #fff; border-bottom: 1px solid #ddd;
            display: flex; align-items: center; gap: 6px; flex-shrink: 0; flex-wrap: wrap;
        }
        #viewer-title {
            flex: 1; font-weight: bold; color: #333; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap; font-size: 13px; min-width: 0;
        }
        #viewer-content { flex: 1; overflow: hidden; background: #ddd; position: relative; }

        /* ── 圖片縮放區 ── */
        #img-zoom-wrap {
            width: 100%; height: 100%; overflow: hidden; display: none;
            align-items: center; justify-content: center; cursor: grab;
        }
        #img-zoom-wrap:active { cursor: grabbing; }
        #bom-zoom-img { max-width: 100%; max-height: 100%; transform-origin: 50% 50%; user-select: none; pointer-events: none; }

        /* ── PDF ── */
        #bom-pdf-frame { display: none; width: 100%; height: 100%; border: none; }

        /* ── 空狀態提示 ── */
        #viewer-placeholder {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            color: #999; font-size: 15px; text-align: center;
        }

        /* ── 檔案列表項目 ── */
        .bom-file-item.active { background: #337ab7 !important; color: #fff !important; border-color: #2e6da4 !important; }
        .bom-file-item.active .list-group-item-text { color: #fff !important; }
        .list-group-item { padding: 8px 12px; }
        .list-group-item-text { font-size: 12px; word-break: break-all; margin: 0; }
        .list-group-item-info, .list-group-item-warning, .list-group-item-danger { cursor: default; }
        /* ── 附件區塊 ── */
        .att-section-header { background:#e8f4fd !important; color:#1a5276 !important; border-top:2px solid #aed6f1 !important; margin-top:8px; cursor:default; }
        .att-file-item.active { background:#1a5276 !important; color:#fff !important; border-color:#154360 !important; }
        .att-file-item.active .list-group-item-text { color:#fff !important; }

        /* ── 儲存對話框遮罩 ── */
        #save-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 9000;
            align-items: center; justify-content: center;
        }
        #save-dialog {
            background: #fff; border-radius: 6px; padding: 20px 24px 16px;
            min-width: 340px; max-width: 480px; width: 90%;
            box-shadow: 0 6px 28px rgba(0,0,0,.35);
        }
        #save-dialog h5 { margin: 0 0 14px; font-size: 15px; color: #333; }
        #save-dialog .form-group { margin-bottom: 10px; }
        #save-dialog label { font-size: 13px; color: #555; margin-bottom: 4px; }
        #save-dialog .hint { font-size: 12px; color: #888; margin-top: 6px; }
        #save-dialog .btn-row { text-align: right; margin-top: 16px; }
        #save-dialog .btn-row .btn { min-width: 72px; }

        /* ── 分頁切換列（僅 did 模式：圖面查閱 / 訂單／報價 / 其他附件）── */
        #bom-tabbar { display: flex; border-bottom: 1px solid #e0d3c0; background: #faf5ec; }
        .bom-tab {
            flex: 1; text-align: center; padding: 8px 4px; font-size: 12.5px; cursor: pointer;
            color: #8a6a3f; border-bottom: 3px solid transparent; user-select: none; white-space: nowrap;
        }
        .bom-tab:hover { background: #f3e7d3; }
        .bom-tab.active { color: #8a4b0f; font-weight: bold; border-bottom-color: #d4761a; background: #fff; }
        .bom-tab .tab-count {
            display: inline-block; min-width: 16px; padding: 0 4px; margin-left: 3px;
            font-size: 10px; line-height: 15px; border-radius: 8px; background: #e6c9a0; color: #6b4a1f;
        }
        .bom-tab.active .tab-count { background: #d4761a; color: #fff; }

        /* ── 使用說明（鐵律7；本頁是滿版工具頁沒有 .page-title，按鈕改放工具列最右）── */
        .page-help-btn { height:24px; font-size:12px; padding:0 10px; border:1px solid #d98a33; border-radius:12px;
            background:#F0A24B; color:#fff; cursor:pointer; white-space:nowrap; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        #helpUseMask { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9500;
            align-items:center; justify-content:center; }
        #helpUseMask .help-box { background:#fff; border-radius:8px; width:92%; max-width:780px; max-height:86vh;
            overflow:auto; padding:18px 22px; box-shadow:0 6px 28px rgba(0,0,0,.35); }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
    </style>
</head>
<body>
<div class="main-wrap">

    <!-- 左側：檔案清單 -->
    <div id="file-panel">
        <div id="bom-tag-filter-bar" style="display:none;padding:5px 8px;background:#fbf7ef;border-bottom:1px solid #e6c9a0;font-size:11px;"></div>
        <div id="file-panel-heading"><i class="fa fa-folder-open-o"></i> <?= $bom_safe ?></div>
        <div id="bom-tabbar" style="display:none;"></div>
        <div id="bom-file-list">
            <p class="text-center" style="margin-top:24px; color:#999;">
                <i class="fa fa-spinner fa-spin"></i> 載入中...
            </p>
        </div>
    </div>

    <!-- 右側：查閱區 -->
    <div id="viewer-panel">
        <div id="viewer-toolbar">
            <span id="viewer-title"></span>
            <button id="btn-zoom-in"    class="btn btn-default btn-xs" style="display:none;" title="放大"><i class="fa fa-search-plus"></i></button>
            <button id="btn-zoom-out"   class="btn btn-default btn-xs" style="display:none;" title="縮小"><i class="fa fa-search-minus"></i></button>
            <button id="btn-zoom-reset" class="btn btn-default btn-xs" style="display:none;" title="重置縮放"><i class="fa fa-refresh"></i></button>
            <!-- 旋轉：直接覆蓋原檔（使用者 2026-08-25 拍板），所以每個看得到這張圖的人看到的都是轉正後的 -->
            <button id="btn-rot-ccw"    class="btn btn-warning btn-xs" style="display:none;" title="向左轉 90°（會直接覆蓋原檔，所有人看到的都是轉正後的圖）"><i class="fa fa-undo"></i> 左轉</button>
            <button id="btn-rot-cw"     class="btn btn-warning btn-xs" style="display:none;" title="向右轉 90°（會直接覆蓋原檔，所有人看到的都是轉正後的圖）"><i class="fa fa-repeat"></i> 右轉</button>
            <button id="btn-paint"      class="btn btn-info    btn-xs" style="display:none;" title="用小畫家開啟（需一次性安裝）"><i class="fa fa-paint-brush"></i> 小畫家</button>
            <button id="btn-save"       class="btn btn-success btn-xs" style="display:none;" title="儲存檔案"><i class="fa fa-floppy-o"></i> 儲存</button>
            <button id="btn-print"      class="btn btn-default btn-xs" style="display:none;" title="列印"><i class="fa fa-print"></i> 列印</button>
            <button id="btn-tags-setting" class="btn btn-info btn-xs" onclick="openFileTagsSetting()" title="設定檔名標籤"><i class="fa fa-tags"></i> 設定標籤</button>
            <?php if ($imgeditCanUse): ?>
            <!-- 批圖編輯器：獨立跳窗（未被指派 imgedit 角色者不顯示此鈕，見上方 $imgeditCanUse） -->
            <button id="btn-image-editor" type="button"
                onclick="openImageEditor()"
                title="批圖編輯器（貼上/拖入圖面、遮蓋客戶資料、加標籤文字、球標與設變標示、多圖合併、列印/另存）——開啟時自動帶入目前預覽的圖檔；PDF 會自動轉成圖檔開啟，多頁會問要開哪一頁"
                class="btn btn-xs" style="background:linear-gradient(135deg,#6a1b9a,#ab47bc);color:#fff;border:none;font-weight:600;"><i class="fa fa-paint-brush"></i> 批圖編輯器</button>
            <?php endif; ?>
            <button id="btnPageHelp" class="page-help-btn" type="button"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <!-- 小畫家提示列（每次點擊都顯示，讓使用者可視需要重新安裝） -->
        <div id="paint-install-hint" style="display:none; background:#fff3cd; color:#856404; padding:7px 12px; font-size:12px; border-bottom:2px solid #ffc107; flex-shrink:0;">
            <i class="fa fa-paint-brush" style="margin-right:4px;"></i>
            已呼叫小畫家。<strong>若未正常開啟</strong>，請
            <a href="install_paint_handler.php" style="color:#533f03; font-weight:bold; text-decoration:underline;">下載安裝程式</a>
            並雙擊執行（一次性安裝，之後即可直接使用）。
            <button type="button" onclick="document.getElementById('paint-install-hint').style.display='none';"
                style="background:none; border:none; cursor:pointer; font-size:15px; line-height:1; color:#856404; margin-left:8px; padding:0; vertical-align:middle;">&times;</button>
        </div>
        <div id="viewer-content">
            <div id="img-zoom-wrap"><img id="bom-zoom-img" src="" alt=""></div>
            <iframe id="bom-pdf-frame" src="" allowfullscreen></iframe>
            <div id="bom-quote-detail" style="display:none;position:absolute;inset:0;overflow:auto;background:#fff;"></div>
            <!-- 照片相簿的九宮格（點縮圖可放大；元件為三頁共用的 eg_photo_album.js）-->
            <div id="album-grid-wrap" style="display:none;position:absolute;inset:0;overflow:auto;background:#fff;"></div>
            <div id="viewer-placeholder"><i class="fa fa-arrow-left"></i> 從左側選擇檔案</div>
        </div>
    </div>
</div>

<!-- 儲存對話框 -->
<div id="save-overlay">
    <div id="save-dialog">
        <h5><i class="fa fa-floppy-o" style="margin-right:7px;color:#5cb85c;"></i>儲存檔案</h5>
        <div class="form-group">
            <label for="save-filename">檔案名稱</label>
            <div class="input-group">
                <input type="text" id="save-filename" class="form-control" placeholder="輸入儲存名稱">
                <span class="input-group-addon" id="save-ext-display" style="min-width:52px; text-align:center; background:#f5f5f5; color:#555;">.jpg</span>
            </div>
        </div>
        <p class="hint"><i class="fa fa-info-circle"></i> 若瀏覽器設定「每次詢問儲存位置」，將顯示另存新檔對話框；否則自動存至下載資料夾。</p>
        <div class="btn-row">
            <button id="save-cancel" class="btn btn-default btn-sm" style="margin-right:8px;">取消</button>
            <button id="save-confirm" class="btn btn-success btn-sm"><i class="fa fa-download"></i> 下載</button>
        </div>
    </div>
</div>

<!-- 使用說明（鐵律7） -->
<div id="helpUseMask">
  <div class="help-box">
    <div style="display:flex;align-items:center;margin-bottom:10px;">
      <h4 style="margin:0;color:#8A5A2B;"><i class="fa fa-question-circle"></i> 圖面查閱 使用說明</h4>
      <button type="button" class="btn btn-default btn-xs" style="margin-left:auto;" onclick="document.getElementById('helpUseMask').style.display='none';">關閉</button>
    </div>
    <div class="help-doc">
      <h4>這一頁在做什麼</h4>
      <ul>
        <li>把同一個料號相關的檔案集中在一個地方看：<b>圖面</b>（BOM 圖檔）、<b>訂單／報價</b>附件、<b>其他附件</b>（料號附件，含照片相簿）。</li>
        <li>左側點檔案、右側預覽；圖片可縮放拖曳，PDF 直接內嵌顯示。</li>
      </ul>

      <h4>工具列各按鈕</h4>
      <ul>
        <li><b>放大／縮小／重置</b>：只影響畫面顯示，不會動到檔案。也可用滑鼠滾輪縮放、按住拖曳平移。</li>
        <li><b>左轉／右轉</b>：把圖轉 90°，<b>轉完直接覆蓋原檔</b>——所以之後<u>每個人</u>看到的、列印出來的都是轉正後的圖（這正是設計目的：掃描歪掉的圖轉一次就好）。圖片與 PDF 都可以轉。</li>
        <li><b>小畫家</b>：用 Windows 小畫家開啟目前這張圖（第一次使用要先照提示列下載安裝程式，一次性安裝）。PDF 不會出現這顆——小畫家開不了 PDF，請改用批圖編輯器。</li>
        <li><b>儲存</b>：下載到自己電腦，可自訂檔名（不會動到伺服器上的原檔）。</li>
        <li><b>列印</b>：列印當下會另外產生一張縮小版餵給印表機，避免超大圖卡在「準備列印時出現錯誤」。</li>
        <li><b>設定標籤</b>：設定 ERP／資材報告的檔名後綴要顯示成什麼標籤。</li>
        <li><b>批圖編輯器</b>：遮蓋客戶資料、加註標示、多圖合併等（需「批圖使用者」角色才看得到這顆）。</li>
      </ul>

      <h4>重要行為／常見疑問</h4>
      <div class="tip">
        <b>旋轉是永久的，而且是給所有人看的。</b>它不是只改自己畫面上的角度——原檔會被覆蓋。
        轉錯了就往回轉（右轉三次或左轉一次即可轉回原角度）。
        <b>JPG 圖面是「無損旋轉」</b>：直接搬 JPEG 內部的資料、不重新編碼，所以轉幾次都不會掉畫質、
        檔案也不會變大（實測連轉四圈回到原角度，每一個畫素都跟原圖完全相同）。
        少數情況會自動改用一般旋轉（重新編碼）：手機拍的、帶有方向標籤的照片，以及 PNG。
        那種情況畫質差異一樣是肉眼看不出來的程度，但**不建議反覆亂轉**。
      </div>
      <ul>
        <li><b>轉完別人怎麼還是舊的？</b>瀏覽器會暫存圖檔約一分鐘，等一下重新整理就會是新的。</li>
        <li><b>轉不動、跳「記憶體不足」</b>：那是走一般旋轉（重新編碼）而圖的畫素又太大時才會發生，請改用批圖編輯器或小畫家處理後重新上傳。</li>
        <li><b>小畫家按了沒反應</b>：多半是還沒安裝那支一次性的開啟程式，點提示列裡的「下載安裝程式」雙擊執行即可。附件分頁的檔案會先向伺服器換一張三分鐘、用過即失效的臨時連結，不會把附件公開出去。</li>
        <li><b>檔案位置</b>：圖面在生產課 BOM 資料夾、附件在各自的 NAS 資料夾，位置都由系統設定值決定，本頁不寫死路徑。</li>
      </ul>

      <h4>權限</h4>
      <ul>
        <li><b>圖面分頁</b>：所有登入者都看得到。</li>
        <li><b>訂單／報價分頁</b>：報價區塊需「報價檢視（quotation_view）」。</li>
        <li><b>其他附件分頁</b>：過渡期開放；已被指派料號主檔（master_data）角色者需「附件檢視（md_attach_view）」。</li>
        <li><b>旋轉</b>：看得到那個檔案的人就能轉（後端會再驗一次權限，不是只擋畫面）。</li>
      </ul>
    </div>
  </div>
</div>


<!-- 設定標籤 Modal -->
<div class="modal fade" id="fileTagsSettingModal" tabindex="-1" role="dialog" style="z-index:10070;">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">設定 ERP/資材報告 檔名標籤</h4>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-condensed" id="tagsSettingTable">
                    <thead>
                        <tr>
                            <th>檔名後綴 (例: -T)</th>
                            <th>標籤名稱 (例: 叫料)</th>
                            <th>顏色</th>
                            <th width="50">操作</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="btn btn-success btn-sm" onclick="addTagRow()"><i class="fa fa-plus"></i> 新增規則</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveFileTagsSetting()">儲存設定</button>
            </div>
        </div>
    </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<!-- 照片相簿：九宮格＋點開放大（三頁共用同一份，禁止各頁自刻）-->
<script src="../../resource/js/eg_photo_album.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_photo_album.js') ?>"></script>
<script>
var _bom        = <?= json_encode($bom) ?>;
var _mode       = <?= json_encode($mode) ?>;   // 'bom' | 'did'
var _d_id       = <?= json_encode($d_id) ?>;   // only in did mode
var _canQuote   = <?= $canQuoteView ? 'true' : 'false' ?>;   // 報價資料權限（quotation_view，併入訂單／報價分頁）
var _canOther   = <?= $canOtherView ? 'true' : 'false' ?>;   // 其他附件分頁權限（md_attach_view，過渡期開放）
var _canOrder   = <?= $canOtherView ? 'true' : 'false' ?>;   // 訂單附件權限（沿用其他附件同一組權限，不新增角色碼）
var _initTab    = <?= json_encode($initTab) ?>;              // 預選分頁 drawing|quote|other|order_attach（quote 為舊連結相容值）
if (_initTab === 'quote') _initTab = 'order_attach';         // 舊版分頁鍵相容：報價資料已併入訂單／報價分頁
var _sc         = 1, _tx = 0, _ty = 0;
var _currentType = '';
var _currentPath = '';
var _currentName = '';
var _rotBust     = {};   // 剛旋轉過的檔案 → 新的 mtime（重新載入時當破快取參數用）

// ── 工具函數 ──────────────────────────────────────────────────────────────
// 顯示用日期格式化（ai-rules/20：一律 YYYY.MM.DD，唯一實作在 eg_date_fmt.js）。
// 注意：排序／比對用的日期字串仍要用原始 Y-m-d，不要經過這層。
function dispDate(d, withTime) { return egFmtDate(d, withTime); }
function escapeHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function applyTransform() {
    var img = document.getElementById('bom-zoom-img');
    if (img) img.style.transform = 'translate('+_tx+'px,'+_ty+'px) scale('+_sc+')';
}
function resetTransform() { _sc = 1; _tx = 0; _ty = 0; applyTransform(); }

// ── 檔案切換顯示 ─────────────────────────────────────────────────────────
function showFile(path, type, name) {
    _currentPath = path;
    _currentName = name || path;
    _currentType = (type || '').toLowerCase();
    var _isImg = ['jpg','jpeg','png','gif','bmp'].indexOf(_currentType) !== -1;

    // 圖面/ERP 檔案原本是 /nas/ 別名（走 Z: 磁碟機代號）直連，Z: 是使用者 session 層級的
    // 持續連線，實測過 `net use` 顯示狀態「無法使用」，造成圖面切換/列印時好時壞的慢；
    // 改走 bom_download.php（inline=1）以 UNC 路徑讀取＋可快取，跟附件分頁走 API 同一套穩定做法。
    // 附件分頁（其他附件/報價/訂單）的 path 本來就已經是 API 網址，不受影響。
    var viewPath = (path && path.indexOf('/nas/') === 0)
        ? 'bom_download.php?path=' + encodeURIComponent(path) + '&filename=' + encodeURIComponent(name || '') + '&inline=1'
        : path;
    // 剛按過旋轉的檔案要破快取，否則瀏覽器會拿舊圖（附件 API／bom_download 都允許短期快取）
    if (_rotBust[path]) viewPath += (viewPath.indexOf('?') >= 0 ? '&' : '?') + '_r=' + _rotBust[path];

    $('#viewer-title').text(_currentName);
    $('#img-zoom-wrap, #bom-pdf-frame, #viewer-placeholder, #bom-quote-detail, #album-grid-wrap').hide();
    $('#btn-print, #btn-zoom-in, #btn-zoom-out, #btn-zoom-reset, #btn-save, #btn-paint, #btn-rot-ccw, #btn-rot-cw').hide();
    resetTransform();

    // 小畫家吃得下的格式（**不含 pdf**：mspaint 開不了 PDF，按了只會下載一個開不起來的檔；
    // PDF 要改圖請按「批圖編輯器」，那邊會用 pdf.js 轉成圖檔再開）
    var _paintFormats = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff'];
    var _isPaintable   = _paintFormats.indexOf(_currentType) !== -1;

    if (_currentType === 'pdf') {
        $('#bom-pdf-frame').attr('src', viewPath).show();
        $('#btn-save, #btn-print, #btn-rot-ccw, #btn-rot-cw').show();
    } else if (_isImg) {
        $('#bom-zoom-img').attr('src', viewPath);
        $('#img-zoom-wrap').css('display', 'flex');
        $('#btn-zoom-in, #btn-zoom-out, #btn-zoom-reset, #btn-save, #btn-print, #btn-rot-ccw, #btn-rot-cw').show();
    } else {
        $('#viewer-placeholder')
            .html('<i class="fa fa-download"></i> 不支援預覽，<a href="'+escapeHtml(viewPath)+'" target="_blank">點此下載</a>')
            .show();
        $('#btn-save').show();
    }
    if (_isPaintable) { $('#btn-paint').show(); }
}

// ── 開啟批圖編輯器（自動帶入目前預覽的圖檔＋料號）────────────────────────────
function openImageEditor() {
    var url = '../Sales/image_editor.php';
    var params = [];
    // 圖片直接帶入；PDF 也帶入（編輯器端會用 pdf.js 轉成圖檔，多頁會跳窗問要開哪幾頁）；其他格式開空白編輯器
    var _type = (_currentType || '').toLowerCase();
    var _imgTypes = ['jpg','jpeg','png','gif','bmp'];
    if (_currentPath && (_imgTypes.indexOf(_type) !== -1 || _type === 'pdf')) {
        // 本頁在 views/pm/，編輯器在 views/Sales/，相對路徑無法共用；一律換算成絕對 URL 再傳
        var absSrc = new URL(_currentPath, window.location.href).href;
        params.push('preload=' + encodeURIComponent(absSrc));
        params.push('preload_name=' + encodeURIComponent(_currentName || ''));
        if (_type === 'pdf') params.push('preload_type=pdf');   // 走 API 下載端點的網址不一定有 .pdf 副檔名，型別直接註明
    }
    // 本頁看的就是這個料號，編輯器那邊「料號附件」存檔跳窗開啟時自動搜尋/選好這個料號、
    // 檔名也預設帶入，不用使用者自己再打一次（見 image_editor.php 的 PRELOAD_PART_NO）
    var _partNo = (_mode === 'did') ? _d_id : _bom;
    if (_partNo) params.push('part_no=' + encodeURIComponent(_partNo));
    if (params.length) url += '?' + params.join('&');
    window.open(url, 'egImgEditor_' + Date.now(),
        'width=1280,height=860,menubar=no,toolbar=no,location=no,status=no,resizable=yes');
}

// ── 圖片縮放與拖曳 ────────────────────────────────────────────────────────
(function() {
    var wrap = document.getElementById('img-zoom-wrap');
    var _pan = false, _px, _py, _ox, _oy;
    wrap.addEventListener('wheel', function(e) {
        e.preventDefault();
        _sc = Math.max(0.1, Math.min(10, _sc + (e.deltaY < 0 ? 0.12 : -0.12)));
        applyTransform();
    }, { passive: false });
    wrap.addEventListener('mousedown', function(e) {
        _pan = true; _px = e.clientX; _py = e.clientY; _ox = _tx; _oy = _ty;
        e.preventDefault();
    });
    window.addEventListener('mousemove', function(e) {
        if (!_pan) return;
        _tx = _ox + e.clientX - _px; _ty = _oy + e.clientY - _py;
        applyTransform();
    });
    window.addEventListener('mouseup', function() { _pan = false; });
})();

$('#btn-zoom-in').on('click',    function() { _sc = Math.min(10, _sc + 0.2); applyTransform(); });
$('#btn-zoom-out').on('click',   function() { _sc = Math.max(0.1, _sc - 0.2); applyTransform(); });
$('#btn-zoom-reset').on('click', resetTransform);

// ── 列印用縮圖 ────────────────────────────────────────────────────────────
// 批圖編輯器存出來的圖最長邊可達 8192px、數十 MB，直接整張塞給印表機驅動常常卡在
// 「準備列印時出現錯誤」或預覽跑很久（2026-08-07 使用者回報）。這裡不動縮放檢視／下載，
// 只在按下「列印」這一刻另外產生一張縮小版餵給瀏覽器列印；縮圖失敗就退回原圖網址。
// ★2026-08-20 調整：上限 4000→6000px、輸出格式 PNG→JPEG q0.98。
//   ‧原註解寫「4000px 相當於 A3 300dpi 有餘」是算錯的：4000px 攤在 A3 長邊 16.54 吋只有
//     242dpi，A3 要 300dpi 得有 4961px。批圖存檔改成 2× 之後（6682px）被砍到 4000 太可惜。
//   ‧真正會害列印卡住的是「送進印表機驅動的資料量」，不是縮圖耗時。實測同一張 6682×4834：
//       4000px PNG ＝ 221ms／6.98MB（原本）
//       6000px PNG ＝ 457ms／13.08MB（資料量翻倍，正是當初出事的量級，不可取）
//       6000px JPEG q0.98 ＝ 288ms／4.79MB（解析度多 50%，資料量反而比原本少三成）
//     故選 JPEG。品質係數 0.98 比照 image_editor.php 的 PDF 列印（0.95 對細字仍看得出糊）。
//   ‧這只是列印當下的暫時副本，存檔原圖／檢視／下載完全不受影響。
function _bomShrinkForPrint(url, cb) {
    var MAXD = 6000;
    var img = new Image();
    img.onload = function() {
        if (img.naturalWidth <= MAXD && img.naturalHeight <= MAXD) { cb(url); return; }
        var scale = MAXD / Math.max(img.naturalWidth, img.naturalHeight);
        var cv = document.createElement('canvas');
        cv.width = Math.round(img.naturalWidth * scale);
        cv.height = Math.round(img.naturalHeight * scale);
        var ctx = cv.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        // JPEG 沒有透明色版：不先鋪白底的話，帶透明背景的 PNG 會被壓成整片黑
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, cv.width, cv.height);
        try { ctx.drawImage(img, 0, 0, cv.width, cv.height); } catch (e) { cb(url); return; }
        cv.toBlob(function(blob) { cb(blob ? URL.createObjectURL(blob) : url); }, 'image/jpeg', 0.98);
    };
    img.onerror = function() { cb(url); };
    img.src = url;
}
// ── 列印 ──────────────────────────────────────────────────────────────────

// ── 列印紀錄（ai-rules/23：會列印的頁面一律留紀錄，共用 eg_print_log.js）──────
// 記的是「按下列印」這個動作；瀏覽器不會回報使用者最後有沒有真的送印，故取消也算一筆。
function _logPrintCurrent(isObs) {
    try {
        if (!window.EGPrintLog || !_currentName) return;
        // 附件的 id 藏在 API 網址（…?action=download&id=123），沒有就不填
        var m = String(_currentPath || '').match(/[?&]id=(\d+)/);
        var refTable = '';
        if (String(_currentPath || '').indexOf('Part_Attachment_API') >= 0)     refTable = 'part_attachments';
        else if (String(_currentPath || '').indexOf('Order_Attachment_API') >= 0) refTable = 'order_attachments';
        else if (String(_currentPath || '').indexOf('Quotation_File_API') >= 0)   refTable = 'quotation_attachments';
        EGPrintLog.record({
            source   : 'bom_viewer',
            doc_name : _currentName,
            doc_kind : 'attachment',
            ref_table: refTable,
            ref_id   : m ? m[1] : '',
            part_no  : (typeof _d_id !== 'undefined' && _d_id) ? _d_id : '',
            note     : isObs ? '作廢附件' : ''
        });
    } catch (e) {}
}
$('#btn-print').on('click', function() {
    var isObs = $('.bom-file-item.active').data('obsolete') === '1' || $('.bom-file-item.active').data('obsolete') === 1;
    _logPrintCurrent(isObs);
    if (_currentType === 'pdf') {
        if (isObs && !confirm('此為「作廢」附件，確定要列印？')) return;
        var frame = document.getElementById('bom-pdf-frame');
        try { frame.contentWindow.print(); } catch(e) { window.print(); }
    } else {
        var src = document.getElementById('bom-zoom-img').src;
        if (!src) return;
        var _printCss = '@page{margin:0;}html,body{margin:0;padding:0;width:100%;height:100%;overflow:hidden;}body{display:flex;align-items:center;justify-content:center;}img{display:block;max-width:100%;max-height:100%;object-fit:contain;}';
        var _wmCss = isObs
            ? '.wm{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-20deg);'
                + 'color:rgba(220,53,69,0.38);font-size:130px;font-weight:900;letter-spacing:16px;'
                + 'pointer-events:none;white-space:nowrap;z-index:999;user-select:none;font-family:Arial,sans-serif;}'
            : '';
        var _wmHtml = isObs ? '<div class="wm">作廢</div>' : '';
        _bomShrinkForPrint(src, function(printSrc) {
            // 用隱藏 iframe 列印，避免另開分頁（列印/取消後仍停留在本視窗）
            var _old = document.getElementById('bom-print-frame');
            if (_old) _old.parentNode.removeChild(_old);
            var ifr = document.createElement('iframe');
            ifr.id = 'bom-print-frame';
            ifr.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
            document.body.appendChild(ifr);
            var doc = ifr.contentWindow.document;
            doc.open();
            doc.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>列印</title><style>'
                + _printCss + _wmCss + '</style></head><body><img src="' + escapeHtml(printSrc) + '">'
                + _wmHtml + '</body></html>');
            doc.close();
            var _doPrint = function() { try { ifr.contentWindow.focus(); ifr.contentWindow.print(); } catch(e) { window.print(); } };
            var _img = doc.querySelector('img');
            if (_img && !_img.complete) { _img.onload = _doPrint; _img.onerror = _doPrint; }
            else { setTimeout(_doPrint, 60); }
            if (printSrc.indexOf('blob:') === 0) {
                var revoke = function() { URL.revokeObjectURL(printSrc); };
                setTimeout(revoke, 120000);
            }
        });
    }
});

// ── 小畫家：呼叫自訂協議，同時顯示提示列（讓使用者可視需要重新安裝）────
// 兩條路徑（2026-08-25 修正，原本只有第一條、附件分頁一按就跳「呼叫 open 方法後，才能
// 呼叫此方法」——那是把相對路徑 ../../src/store/… 直接接在 host 後面組出非法網址，
// VBScript 的 oHTTP.Open 失敗後才跑到 setOption 的錯）：
//   圖面(/nas/)   → Apache 別名直接給檔，維持原本作法
//   附件(API 網址) → 先換一張一次性權杖再打 bom_open.php；**VBScript 用
//                    MSXML2.ServerXMLHTTP 抓檔不會帶登入 cookie**，直接打附件 API 一定 403
$('#btn-paint').on('click', function() {
    var hint = document.getElementById('paint-install-hint');
    var host = window.location.host;
    if (!_currentPath) return;
    if (_currentPath.indexOf('/nas/') === 0) {
        // 只傳 host + path，避免嵌套 :// 造成 URL 解析錯誤；VBScript 端會自動補回 http://
        window.location.href = 'open-paint://' + host + _currentPath;
        hint.style.display = 'block';
        return;
    }
    var $btn = $(this).prop('disabled', true);
    $.post('bom_open_token.php', { url: _currentPath }, function(res) {
        $btn.prop('disabled', false);
        if (!res || !res.success) { alert((res && res.message) || '無法用小畫家開啟這個檔案'); return; }
        // 網址結尾一定要帶副檔名：VBScript 是用「最後一個點」決定暫存檔要存成什麼副檔名，
        // 沒帶就會存成 .php，小畫家開不起來
        var dir = window.location.pathname.replace(/[^/]*$/, '');   // /EGsystem/views/pm/
        var ext = (res.ext || _currentType || 'jpg');
        window.location.href = 'open-paint://' + host + dir + 'bom_open.php?t=' + res.token + '&x=.' + ext;
        hint.style.display = 'block';
    }, 'json').fail(function() {
        $btn.prop('disabled', false);
        alert('無法用小畫家開啟：與伺服器連線失敗');
    });
});

// ── 旋轉：就地覆蓋原檔（使用者 2026-08-25 拍板；權限＝看得到就轉得動，後端再驗一次）──
function doRotate(deg) {
    if (!_currentPath) return;
    var $btns = $('#btn-rot-ccw, #btn-rot-cw').prop('disabled', true);
    $.post('bom_rotate.php', { url: _currentPath, deg: deg }, function(res) {
        $btns.prop('disabled', false);
        if (!res || !res.success) { alert((res && res.message) || '旋轉失敗'); return; }
        _rotBust[_currentPath] = res.mtime || Date.now();
        showFile(_currentPath, _currentType, _currentName);   // 重新載入（帶破快取參數）
    }, 'json').fail(function() {
        $btns.prop('disabled', false);
        alert('旋轉失敗：與伺服器連線失敗');
    });
}
$('#btn-rot-ccw').on('click', function() { doRotate(270); });   // 向左轉＝順時針 270°
$('#btn-rot-cw').on('click',  function() { doRotate(90);  });

// ── 使用說明（鐵律7）──
$('#btnPageHelp').on('click', function() { $('#helpUseMask').css('display', 'flex'); });
$('#helpUseMask').on('click', function(e) { if (e.target === this) this.style.display = 'none'; });

// ── 儲存：開啟頁內對話框 ──────────────────────────────────────────────────
$('#btn-save').on('click', function() {
    var _ext = _currentType || 'file';
    // 用目前檔案的實際名稱（去副檔名）作為預設，避免料號含 / 等非法字元
    var defaultName = _currentName
        ? _currentName.replace(/\.[^/.]+$/, '')   // 去掉副檔名
        : _bom;
    $('#save-filename').val(defaultName);
    $('#save-ext-display').text('.' + _ext).data('ext', _ext);
    $('#save-overlay').css('display', 'flex');
    setTimeout(function() {
        var inp = document.getElementById('save-filename');
        if (inp) { inp.focus(); inp.select(); }
    }, 60);
});

$('#save-cancel').on('click', function() { $('#save-overlay').hide(); });

// 點擊遮罩關閉
$('#save-overlay').on('click', function(e) {
    if (e.target === this) $('#save-overlay').hide();
});

// Enter 確認 / Esc 關閉
$('#save-filename').on('keydown', function(e) {
    if (e.key === 'Enter')  { e.preventDefault(); $('#save-confirm').click(); }
    if (e.key === 'Escape') { $('#save-overlay').hide(); }
});

// ── 儲存確認：showSaveFilePicker → fetch+blob → <a download> ────────────
$('#save-confirm').on('click', function() {
    var basename = $('#save-filename').val().trim();
    var ext      = $('#save-ext-display').data('ext') || '';
    if (!basename) { $('#save-filename').focus(); return; }
    var fullName = basename + (ext ? '.' + ext : '');
    $('#save-overlay').hide();
    doDownload(_currentPath, fullName);
});

// ── 下載：透過 PHP 端點觸發瀏覽器原生下載（支援自訂檔名）──
// 若瀏覽器設定「每次詢問儲存位置」，會顯示另存新檔對話框；否則存至下載資料夾。
function doDownload(url, filename) {
    var a = document.createElement('a');
    if (url && url.indexOf('/nas/') === 0) {
        a.href = 'bom_download.php?path=' + encodeURIComponent(url)
               + '&filename=' + encodeURIComponent(filename);
    } else {
        // 附件分頁（其他附件／訂單／報價）的 path 本來就是附件 API 網址，再包一層
        // bom_download.php 只會得到 400（那支只收 /nas/ 開頭）＝2026-08-25 使用者回報的
        // 「無法存取網站」。改直接打 API 並帶 dl=1&dl_name：**Chrome 會讓伺服器的
        // Content-Disposition 檔名蓋掉 <a download>**，檔名一定要由後端指定才會生效。
        a.href = url + (url.indexOf('?') >= 0 ? '&' : '?')
               + 'dl=1&dl_name=' + encodeURIComponent(filename);
    }
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ── 檔案列表點擊 ─────────────────────────────────────────────────────────
$(document).on('click', '.bom-file-item', function(e) {
    e.preventDefault();
    $('.bom-file-item').removeClass('active');
    $(this).addClass('active');
    var isObs = $(this).data('obsolete') === '1' || $(this).data('obsolete') === 1;
    showFile($(this).data('path'), $(this).data('type'), $(this).data('name'));
    // 作廢 overlay
    $('#viewer-content .bom-obsolete-overlay').remove();
    if (isObs) {
        $('#viewer-content').css('position', 'relative').append(
            '<div class="bom-obsolete-overlay" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:10;">'
            + '<div style="color:rgba(220,53,69,0.45);font-size:88px;font-weight:900;letter-spacing:12px;text-shadow:0 2px 16px rgba(220,53,69,.15);user-select:none;transform:rotate(-20deg);">作廢</div>'
            + '</div>'
        );
    }
});

// ── 分頁：資料桶、渲染與切換（did 模式）；bom 模式維持單清單 ─────────────
// 訂單附件與報價資料自 2026-08 起合併為單一「訂單／報價」分頁：以訂單為主要分組，
// 若訂單來源是報價單（order_track.quote_no）則該報價單巢狀顯示在該訂單底下；
// 尚未轉單的報價單仍獨立列出。詳見 buildOrderQuoteGroups()/renderOrderQuoteTab()。
var _tabData    = { drawing: null, other: [], quote: [], quoteSummaries: {}, order_attach: [], albums: [] };
var _tabEnabled = { drawing: true, other: (_mode === 'did' && _canOther), order_attach: (_mode === 'did' && (_canOrder || _canQuote)) };
var _activeTab  = 'drawing';
var _tabMeta = {
    drawing:      { label: '圖面查閱', icon: 'fa-picture-o' },
    order_attach: { label: '訂單／報價', icon: 'fa-truck' },
    other:        { label: '其他附件', icon: 'fa-paperclip' }
};

function tabCount(tab) {
    if (tab === 'drawing') {
        var d = _tabData.drawing || {};
        return (d.files ? d.files.length : 0) + (d.erp_files ? d.erp_files.length : 0);
    }
    if (tab === 'order_attach') {
        var g = buildOrderQuoteGroups();
        return Object.keys(g.orderGroups).length + g.standaloneQnos.length;
    }
    return (_tabData[tab] || []).length;
}

function renderTabbar() {
    if (_mode !== 'did') return;
    var html = '';
    ['drawing','order_attach','other'].forEach(function(t) {
        if (!_tabEnabled[t]) return;
        html += '<div class="bom-tab'+(t===_activeTab?' active':'')+'" data-tab="'+t+'">'
             +  '<i class="fa '+_tabMeta[t].icon+'"></i> '+_tabMeta[t].label
             +  '<span class="tab-count">'+tabCount(t)+'</span></div>';
    });
    $('#bom-tabbar').html(html).show();
}

// 圖面/ERP 檔案項目
function makeItem(f, active) {
    var label = '';
    if (f.is_plus) label = '<span class="label label-warning" style="margin-right:4px;">加工圖</span>';
    if (f.tags && f.tags.length > 0) {
        f.tags.forEach(function(t) {
            label += '<span class="label" style="background:'+escapeHtml(t.color||'#777')+';color:#fff;margin-right:3px;">'+escapeHtml(t.label)+'</span>';
        });
    }
    var displayName = (f.label && _mode === 'did') ? f.label + ' / ' + f.name : f.name;
    var bindTag = f.bind_from ? '<br><span style="font-size:10px;color:#1ABB9C;"><i class="fa fa-link"></i> 來自綁定料號 '+escapeHtml(f.bind_from)+'</span>' : '';
    return '<a href="#" class="list-group-item bom-file-item'+(active?' active':'')+'"'
        +' data-path="'+escapeHtml(f.path)+'"'
        +' data-type="'+escapeHtml(f.type)+'"'
        +' data-name="'+escapeHtml(f.name)+'">'
        +'<p class="list-group-item-text">'+label+escapeHtml(displayName)+bindTag+'</p></a>';
}

/* ── 照片相簿（2026-08-25 使用者要求）─────────────────────────────────────
   產品照片這種一次好幾十張的附件，清單一列一個檔名根本看不出誰是誰：
   改成左側列相簿（資料夾）、右側九宮格，點任何一張都能放大並左右換張。
   九宮格與放大檢視走共用元件 eg_photo_album.js（與料號主檔附件跳窗同一份）。 */
var _albumPhotos = {};      // key(相簿id或'__none__') -> {name, photos:[]}

/** 把附件陣列中屬於相簿標籤的挑出來分組，回傳相簿列的 HTML（其餘附件照舊逐列顯示） */
function buildAlbumRows(atts) {
    _albumPhotos = {};
    var order = [];
    (_tabData.albums || []).forEach(function(a) {
        _albumPhotos[String(a.id)] = { name: a.album_name, photos: [] };
        order.push(String(a.id));
    });
    atts.forEach(function(att) {
        if (!att.is_album) return;
        var k = att.album_id ? String(att.album_id) : '__none__';
        if (!_albumPhotos[k]) { _albumPhotos[k] = { name: (att.album_name || '相簿'), photos: [] }; order.push(k); }
        _albumPhotos[k].photos.push({ id: att.id, url: att.url, name: att.display_name,
                                      uploaded_at: dispDate(att.uploaded_at, true), uploaded_by: att.uploaded_by });
    });
    order = order.filter(function(k){ return k !== '__none__'; }).concat(['__none__']);
    var html = '';
    order.forEach(function(k) {
        var g = _albumPhotos[k];
        if (!g || !g.photos.length) return;      // 空相簿在唯讀檢視頁不用列
        html += '<a href="#" class="list-group-item att-album-item" data-album="' + escapeHtml(k) + '">'
             +  '<p class="list-group-item-text" style="margin:0;display:flex;align-items:center;gap:7px;">'
             +  '<img src="' + escapeHtml(EGAlbum.thumb(g.photos[0].url, 120)) + '" style="width:38px;height:38px;object-fit:cover;border-radius:4px;border:1px solid #E4D3BC;background:#f4f5f7;">'
             +  '<span style="flex:1;min-width:0;">'
             +    '<span style="display:block;font-weight:600;color:#5B4526;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
             +      '<i class="fa fa-' + (k === '__none__' ? 'inbox' : 'folder') + '" style="color:#F0A24B;margin-right:3px;"></i>'
             +      escapeHtml(k === '__none__' ? '未分相簿' : g.name) + '</span>'
             +    '<small style="color:#aaa;font-size:10px;">' + g.photos.length + ' 張照片 · 點開看九宮格</small>'
             +  '</span></p></a>';
    });
    return html;
}
$(document).on('click', '.att-album-item', function(e) {
    e.preventDefault();
    $('.bom-file-item, .att-album-item').removeClass('active');
    $(this).addClass('active');
    showAlbumGrid($(this).data('album'));
});
function showAlbumGrid(key) {
    var g = _albumPhotos[String(key)];
    if (!g) return;
    $('#img-zoom-wrap, #bom-pdf-frame, #viewer-placeholder, #bom-quote-detail').hide();
    $('#btn-print, #btn-zoom-in, #btn-zoom-out, #btn-zoom-reset, #btn-save, #btn-paint').hide();
    $('#viewer-content .bom-obsolete-overlay').remove();
    $('#viewer-title').text((key === '__none__' ? '未分相簿' : g.name) + '（' + g.photos.length + ' 張）');
    $('#album-grid-wrap').show();
    EGAlbum.grid('album-grid-wrap', g.photos, { onOpen: function(i) { EGAlbum.viewer(g.photos, i); } });
}

// 附件（報價/其他）項目
// showSource=true 時前面加上來源徽章（訂單/報價/其他）：跨分頁全域標籤篩選結果用，
// 平常各分頁自己顯示時不需要（同分頁內來源已經很清楚）
var _bomSourceLabel = { quote: '報價', order: '訂單', other: '其他' };
var _bomSourceColor = { quote: '#8a4b0f', order: '#1ABB9C', other: '#7d3c98' };
function makeAttItem(att, showSource) {
    var catBadges = '';
    (att.category_names || []).forEach(function(cn) {
        if (cn === '作廢') return;
        catBadges += '<span class="label label-info" style="margin-right:2px;font-size:10px;">'+escapeHtml(cn)+'</span>';
    });
    var extBadge = '<span class="label label-default" style="margin-right:4px;font-size:10px;">'+escapeHtml((att.ext||'').toUpperCase())+'</span>';
    var srcBadge = '';
    if (showSource) {
        var src = att.source || 'other';
        srcBadge = '<span style="font-size:9px;font-weight:700;color:#fff;background:'+(_bomSourceColor[src]||'#999')+';border-radius:3px;padding:0 4px;margin-right:4px;">'+(_bomSourceLabel[src]||src)+'</span>';
    }
    var info = [dispDate(att.uploaded_at, true), att.uploaded_by, att.file_size, att.note].filter(Boolean).join(' · ');
    // 版次／發行章日期（只有料號附件有這兩欄；報價/訂單附件不顯示）
    // 版次 0 也要顯示：不可用 if(att.revision) 判斷（0 / "0" 會被當成沒填）
    var revBadge = '';
    if (att.revision !== null && att.revision !== undefined && String(att.revision) !== '')
        revBadge = '<span style="background:#eef4fb;color:#1a5276;font-size:10px;font-weight:600;padding:0 5px;border-radius:3px;margin-right:4px;" title="版次">Rev.'+escapeHtml(att.revision)+'</span>';
    // 發行章日期是圖面變更的新舊判定依據（ai-rules/15）：自家出的圖沒填就偵測不到變更，
    // 所以留白不夠，要紅字明講（跟 master_data_management 的附件清單同一套判斷）。
    var issBadge = '';
    if (att.issue_stamp_date)
        issBadge = '<span style="background:#FFF3E2;color:#8a5a12;border:1px solid #E4D3BC;font-size:10px;font-weight:600;padding:0 5px;border-radius:3px;margin-right:4px;" title="發行章日期（圖面變更的新舊依據）">發行 '+escapeHtml(dispDate(att.issue_stamp_date))+'</span>';
    else if (att.is_own_drawing == 1)
        issBadge = '<span style="background:#fdecea;color:#c0392b;border:1px solid #f5b7b1;font-size:10px;font-weight:600;padding:0 5px;border-radius:3px;margin-right:4px;" title="自家出的圖但沒有發行章日期，無法偵測圖面變更——請到料號主檔的附件編輯補上">未設發行日</span>';
    var isObs = (att.category_names || []).indexOf('作廢') >= 0;
    var st = isObs ? 'background:#fff0f0;border-left:3px solid #e74c3c;' : '';
    var bindTag = att.bind_from ? '<br><span style="font-size:10px;color:#1ABB9C;"><i class="fa fa-link"></i> 來自綁定料號 '+escapeHtml(att.bind_from)+'</span>' : '';
    return '<a href="#" class="list-group-item bom-file-item att-file-item"'
        + ' data-path="'+escapeHtml(att.url)+'"'
        + ' data-type="'+escapeHtml(att.ext)+'"'
        + ' data-name="'+escapeHtml(att.display_name)+'"'
        + ' data-obsolete="'+(isObs?'1':'0')+'"'
        + ' style="'+st+'">'
        + (isObs ? '<div style="display:inline-block;background:#e74c3c;color:#fff;font-size:10px;font-weight:700;padding:0 7px;border-radius:3px;letter-spacing:1px;margin-bottom:3px;">⊘ 作廢</div><br>' : '')
        + '<p class="list-group-item-text" style="'+(isObs?'color:#c0392b;text-decoration:line-through;':'')+'">'
        + srcBadge + extBadge + catBadges + revBadge + issBadge + escapeHtml(att.display_name)
        + (info ? '<br><small style="color:#aaa;font-size:10px;">'+escapeHtml(info)+'</small>' : '')
        + bindTag
        + '</p></a>';
}

function showEmpty(msg) {
    $('#img-zoom-wrap, #bom-pdf-frame, #bom-quote-detail, #album-grid-wrap').hide();
    $('#btn-print, #btn-zoom-in, #btn-zoom-out, #btn-zoom-reset, #btn-save, #btn-paint').hide();
    $('#viewer-content .bom-obsolete-overlay').remove();
    $('#viewer-title').text('');
    $('#viewer-placeholder').text(msg).show();
}
function applyObsoleteOverlay(isObs) {
    $('#viewer-content .bom-obsolete-overlay').remove();
    if (isObs) {
        $('#viewer-content').css('position','relative').append(
            '<div class="bom-obsolete-overlay" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:10;">'
            + '<div style="color:rgba(220,53,69,0.45);font-size:88px;font-weight:900;letter-spacing:12px;text-shadow:0 2px 16px rgba(220,53,69,.15);user-select:none;transform:rotate(-20deg);">作廢</div>'
            + '</div>'
        );
    }
}

function renderDrawingList() {
    var d = _tabData.drawing || {};
    var listHtml = '', first = null;
    if (d.files && d.files.length > 0) {
        listHtml += '<li class="list-group-item list-group-item-info"><strong>BOM 圖檔</strong></li>';
        d.files.forEach(function(f, i) { if (i === 0 && !first) first = f; listHtml += makeItem(f, i === 0); });
    }
    if (d.erp_files && d.erp_files.length > 0) {
        var bomM = d.erp_files.filter(function(f) { return f.match_type === 'bom' || !f.match_type; });
        var didM = d.erp_files.filter(function(f) { return f.match_type === 'did'; });
        var noBom = !(d.files && d.files.length);
        if (bomM.length > 0) {
            listHtml += '<li class="list-group-item list-group-item-warning" style="margin-top:6px;"><strong>ERP/資材報告</strong></li>';
            bomM.forEach(function(f, i) { var a = (noBom && i === 0); if (a && !first) first = f; listHtml += makeItem(f, a); });
        }
        if (didM.length > 0) {
            listHtml += '<li class="list-group-item list-group-item-danger" style="margin-top:6px;"><strong>不確定批號 (僅匹配料號)</strong></li>';
            didM.forEach(function(f, i) { var a = (noBom && !bomM.length && i === 0); if (a && !first) first = f; listHtml += makeItem(f, a); });
        }
    }
    if (!listHtml) { $('#bom-file-list').html('<div class="alert alert-warning" style="margin:10px;">無相關圖檔</div>'); showEmpty('無相關圖檔'); return; }
    $('#bom-file-list').html(listHtml);
    if (first) showFile(first.path, first.type, first.name);
}

function renderAttList(tab) {
    var arr = _tabData[tab] || [];
    if (arr.length === 0) {
        var msg = '無其他附件';
        $('#bom-file-list').html('<div class="alert alert-warning" style="margin:10px;">'+msg+'</div>');
        showEmpty(msg); return;
    }
    // 相簿標籤（產品照片等）的照片收成相簿列排在最上面，其餘附件照舊逐列顯示
    var html = buildAlbumRows(arr);
    var plain = arr.filter(function(att){ return !att.is_album; });
    plain.forEach(function(att) { html += makeAttItem(att); });
    $('#bom-file-list').html(html);
    if (plain.length) {
        var f0 = plain[0];
        $('#bom-file-list .bom-file-item').first().addClass('active');
        showFile(f0.url, f0.ext, f0.display_name);
        applyObsoleteOverlay((f0.category_names || []).indexOf('作廢') >= 0);
    } else {
        // 整個分頁都是照片時，直接打開第一本相簿（不要停在「從左側選擇檔案」）
        var first = $('#bom-file-list .att-album-item').first();
        if (first.length) { first.addClass('active'); showAlbumGrid(first.data('album')); }
        else showEmpty('無其他附件');
    }
}

// ── 訂單／報價分頁：以訂單為主分組，訂單來源報價單(order_track.quote_no)巢狀顯示於該訂單底下；
// 尚未轉單的報價單獨立列出（明細＋附件，仿附件二報價跳窗）──────────────────
function buildOrderQuoteGroups() {
    var oAtts = _tabData.order_attach || [];
    var qAtts = _tabData.quote || [];
    var summaries = _tabData.quoteSummaries || {};

    var orderGroups = {};
    oAtts.forEach(function(a) {
        var oo = a.order_oo || '__unknown__';
        if (!orderGroups[oo]) {
            orderGroups[oo] = { order_oo: oo, order_date: a.order_date, delivery_date: a.delivery_date,
                                 qty: a.qty, client_name: a.client_name, quote_no: a.quote_no || null, files: [] };
        }
        orderGroups[oo].files.push(a);
    });

    var quoteGroups = {};
    qAtts.forEach(function(f) { var q = f.quote_no || '__unknown__'; (quoteGroups[q] = quoteGroups[q] || []).push(f); });
    Object.keys(summaries).forEach(function(q) { if (!quoteGroups[q]) quoteGroups[q] = []; });

    // 已被某訂單巢狀帶出的報價單號不再重複於獨立清單出現
    var consumed = {};
    Object.keys(orderGroups).forEach(function(oo) { var qn = orderGroups[oo].quote_no; if (qn) consumed[qn] = true; });
    var standaloneQnos = Object.keys(quoteGroups).filter(function(q) { return !consumed[q]; });

    return { orderGroups: orderGroups, quoteGroups: quoteGroups, summaries: summaries, standaloneQnos: standaloneQnos };
}

// nested=true 時縮排＋加註「來自報價」，用於巢狀顯示在訂單底下；平常獨立顯示不需要
function quoteHeadHtml(qno, qs, nested) {
    var qProcs = [];
    if (qs && qs.items) {
        qs.items.forEach(function(it) {
            var procParts = (it.processes || []).map(function(p) { return p.name; });
            var subParts  = (it.subtags || []);
            if (!procParts.length && !subParts.length) return;
            var key = procParts.concat(subParts).join('|');
            var label = procParts.map(escapeHtml).join('・');
            if (subParts.length) label += '<span style="color:#555;">・' + subParts.map(escapeHtml).join('・') + '</span>';
            if (qProcs.filter(function(x) { return x.key === key; }).length === 0) qProcs.push({ key: key, label: label });
        });
    }
    var qOrderNos = [];
    if (qs && qs.items) {
        qs.items.forEach(function(it) { if (it.order_oo && qOrderNos.indexOf(it.order_oo) === -1) qOrderNos.push(it.order_oo); });
    }
    var html = '<div class="bom-quote-head" data-qno="' + escapeHtml(qno) + '" style="background:'
        + (nested ? '#fbf7ef' : '#faf1e0') + ';border-bottom:2px solid #e6c9a0;padding:' + (nested ? '6px 8px' : '8px 10px')
        + ';cursor:pointer;' + (nested ? 'border-left:3px solid #d4a24b;margin-left:10px;' : '') + '">';
    html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;">';
    html += '<span style="font-size:' + (nested ? '11px' : '12px') + ';font-weight:700;color:#8a4b0f;font-family:Consolas,monospace;">'
        + (nested ? '<i class="fa fa-level-up fa-rotate-90" style="margin-right:3px;color:#c99a55;"></i>來自報價 ' : '')
        + escapeHtml(qno === '__unknown__' ? '（未知報價單）' : qno) + '</span>';
    if (qs && qs.total_amount) html += '<span style="font-size:11px;color:#c0392b;font-weight:600;white-space:nowrap;">$' + Number(qs.total_amount).toLocaleString() + '</span>';
    html += '</div>';
    if (qOrderNos.length) {
        html += '<div style="margin-top:2px;">' + qOrderNos.map(function(o) {
            return '<span style="font-size:10px;font-weight:700;color:#fff;background:#1ABB9C;border-radius:3px;padding:0 5px;margin-right:3px;"><i class="fa fa-exchange"></i> ' + escapeHtml(o) + '</span>';
        }).join('') + '</div>';
    }
    if (qs && qs.bind_from) html += '<div style="font-size:10px;color:#1ABB9C;margin-top:2px;"><i class="fa fa-link"></i> 來自綁定料號 ' + escapeHtml(qs.bind_from) + '</div>';
    if (qProcs.length) {
        html += '<div style="margin-top:2px;display:flex;flex-direction:column;gap:1px;">';
        qProcs.forEach(function(p) { html += '<span style="font-size:10px;font-weight:600;color:#1b5e20;background:#e8f5e9;border-radius:3px;padding:0 5px;line-height:1.7;align-self:flex-start;">' + p.label + '</span>'; });
        html += '</div>';
    }
    if (qs) {
        html += '<div style="font-size:11px;color:#666;margin-top:2px;display:flex;gap:8px;flex-wrap:wrap;">';
        if (qs.quote_date)  html += '<span><i class="fa fa-calendar" style="color:#bbb;margin-right:2px;"></i>' + escapeHtml(dispDate(qs.quote_date)) + '</span>';
        if (qs.client_name) html += '<span><i class="fa fa-building-o" style="color:#bbb;margin-right:2px;"></i>' + escapeHtml(qs.client_name) + '</span>';
        html += '</div>';
    }
    html += '<div style="font-size:10px;color:#b5793a;margin-top:3px;"><i class="fa fa-eye"></i> 點此看報價明細</div>';
    html += '</div>';
    return html;
}

function orderGroupHtml(og, quoteGroups, summaries) {
    var html = '<div class="bom-order-head" style="background:#eafaf6;border-bottom:2px solid #b6e6da;padding:8px 10px;">';
    html += '<span style="font-size:12px;font-weight:700;color:#0e8c73;font-family:Consolas,monospace;"><i class="fa fa-truck"></i> '
        + escapeHtml(og.order_oo === '__unknown__' ? '（未知訂單）' : og.order_oo) + '</span>';
    var info = [];
    if (og.order_date) info.push(dispDate(og.order_date));
    if (og.client_name) info.push(og.client_name);
    if (og.qty) info.push('數量 ' + og.qty);
    if (og.delivery_date) info.push('交期 ' + dispDate(og.delivery_date));
    if (info.length) html += '<div style="font-size:11px;color:#666;margin-top:2px;">' + info.map(escapeHtml).join('　') + '</div>';
    html += '</div>';
    og.files.forEach(function(f) { html += makeAttItem(f); });
    if (og.quote_no && quoteGroups.hasOwnProperty(og.quote_no)) {
        html += quoteHeadHtml(og.quote_no, summaries[og.quote_no], true);
        (quoteGroups[og.quote_no] || []).forEach(function(f) { html += makeAttItem(f); });
    }
    return html;
}

function renderOrderQuoteTab() {
    var g = buildOrderQuoteGroups();
    var orderOos = Object.keys(g.orderGroups);

    // 排序鍵：訂單用訂單日期，尚未轉單的報價單用報價日期/附件上傳日期
    function sortKeyOrder(oo) {
        var og = g.orderGroups[oo];
        if (og.order_date) return og.order_date;
        var d = '';
        og.files.forEach(function(f) { if (f.uploaded_at > d) d = f.uploaded_at; });
        return d;
    }
    function sortKeyQuote(q) {
        var qs = g.summaries[q];
        var d = (qs && qs.quote_date) || '';
        (g.quoteGroups[q] || []).forEach(function(f) { if (f.uploaded_at > d) d = f.uploaded_at; });
        return d;
    }

    var entries = orderOos.filter(function(o) { return o !== '__unknown__'; })
        .map(function(o) { return { type: 'order', key: o, sortKey: sortKeyOrder(o) }; })
        .concat(g.standaloneQnos.filter(function(q) { return q !== '__unknown__'; })
        .map(function(q) { return { type: 'quote', key: q, sortKey: sortKeyQuote(q) }; }));
    if (g.orderGroups['__unknown__']) entries.push({ type: 'order', key: '__unknown__', sortKey: '' });
    if (g.standaloneQnos.indexOf('__unknown__') !== -1 && (g.quoteGroups['__unknown__'] || []).length) {
        entries.push({ type: 'quote', key: '__unknown__', sortKey: '' });
    }
    entries.sort(function(a, b) { return b.sortKey > a.sortKey ? 1 : (b.sortKey < a.sortKey ? -1 : 0); });

    if (!entries.length) {
        $('#bom-file-list').html('<div class="alert alert-warning" style="margin:10px;">無訂單附件或報價資料</div>');
        showEmpty('無訂單附件或報價資料'); return;
    }

    var html = '';
    entries.forEach(function(e) {
        if (e.type === 'order') {
            html += orderGroupHtml(g.orderGroups[e.key], g.quoteGroups, g.summaries);
        } else {
            html += quoteHeadHtml(e.key, g.summaries[e.key], false);
            (g.quoteGroups[e.key] || []).forEach(function(f) { html += makeAttItem(f); });
        }
    });
    $('#bom-file-list').html(html);

    var first = entries[0];
    if (first.type === 'order') {
        var og = g.orderGroups[first.key];
        if (og.files.length) {
            $('#bom-file-list .bom-file-item').first().addClass('active');
            showFile(og.files[0].url, og.files[0].ext, og.files[0].display_name);
            applyObsoleteOverlay((og.files[0].category_names || []).indexOf('作廢') >= 0);
        } else if (og.quote_no) {
            showQuoteDetail(og.quote_no);
        }
    } else {
        showQuoteDetail(first.key);
    }
}

function showQuoteDetail(qno) {
    var qs = (_tabData.quoteSummaries || {})[qno] || null;
    $('#bom-file-list .bom-file-item').removeClass('active');
    $('#bom-file-list .bom-quote-head').css('background', '#faf1e0');
    $('#bom-file-list .bom-quote-head[data-qno="' + qno + '"]').css('background', '#f2dcb8');
    $('#img-zoom-wrap, #bom-pdf-frame, #viewer-placeholder').hide();
    $('#viewer-content .bom-obsolete-overlay').remove();
    $('#btn-print, #btn-zoom-in, #btn-zoom-out, #btn-zoom-reset, #btn-save, #btn-paint').hide();
    $('#viewer-title').text(qno === '__unknown__' ? '（未知報價單）' : qno);

    var html = '<div style="width:100%;height:100%;overflow-y:auto;padding:16px;background:#fff;">';
    html += '<div style="margin-bottom:12px;">';
    html += '<div style="font-size:15px;font-weight:700;color:#8a4b0f;padding-bottom:8px;border-bottom:2px solid #e6c9a0;"><i class="fa fa-file-text-o" style="margin-right:6px;"></i>' + escapeHtml(qno === '__unknown__' ? '（未知報價單）' : qno) + '</div>';
    if (qs && qs.bind_from) html += '<div style="font-size:11px;color:#1ABB9C;margin-top:4px;"><i class="fa fa-link"></i> 來自綁定料號 ' + escapeHtml(qs.bind_from) + '</div>';
    html += '</div>';
    if (!qs) {
        html += '<div style="color:#aaa;font-size:12px;">此報價單無明細資料（可能僅有附件）。</div>';
    } else {
        html += '<table style="font-size:12px;width:100%;max-width:480px;margin-bottom:16px;border-collapse:collapse;">';
        var rows = [['報價日期', dispDate(qs.quote_date)], ['客戶名稱', qs.client_name], ['有效日期', dispDate(qs.valid_date)], ['負責人員', qs.handler_name], ['總金額', qs.total_amount ? '$' + Number(qs.total_amount).toLocaleString() : '']];
        rows.forEach(function(r) { if (!r[1]) return; html += '<tr><td style="color:#888;padding:3px 8px 3px 0;white-space:nowrap;">' + escapeHtml(r[0]) + '</td><td style="padding:3px 0;font-weight:600;color:#2c3e50;">' + escapeHtml(String(r[1])) + '</td></tr>'; });
        if (qs.quote_note) html += '<tr><td style="color:#888;padding:3px 8px 3px 0;white-space:nowrap;">報價單備註</td><td style="padding:3px 0;color:#7d3c98;">' + escapeHtml(qs.quote_note) + '</td></tr>';
        html += '</table>';
        if (qs.items && qs.items.length) {
            html += '<div style="font-size:12px;font-weight:700;color:#2c3e50;margin-bottom:6px;">報價品項</div>';
            html += '<table style="width:100%;font-size:11px;border-collapse:collapse;"><thead><tr style="background:#f7efe2;">';
            html += '<th style="padding:4px 6px;text-align:left;border-bottom:1px solid #e6d3b3;">料號</th><th style="padding:4px 6px;text-align:left;border-bottom:1px solid #e6d3b3;">製程／備註</th><th style="padding:4px 6px;text-align:right;border-bottom:1px solid #e6d3b3;">數量</th><th style="padding:4px 6px;text-align:right;border-bottom:1px solid #e6d3b3;">單價</th></tr></thead><tbody>';
            qs.items.forEach(function(it) {
                var procParts = (it.processes || []).map(function(p) { return escapeHtml(p.name); });
                var subParts  = (it.subtags || []).map(escapeHtml);
                var combined = '';
                if (procParts.length || subParts.length) {
                    var mainLine = procParts.join('・');
                    if (subParts.length) mainLine += '<span style="color:#555;">・' + subParts.join('・') + '</span>';
                    combined += '<div style="color:#1b5e20;font-weight:600;">' + mainLine + '</div>';
                }
                if (it.specification) combined += '<div style="color:#7d3c98;font-size:10px;">' + escapeHtml(it.specification) + '</div>';
                if (!combined) combined = '<span style="color:#ccc;">—</span>';
                html += '<tr style="border-bottom:1px solid #f0f0f0;">';
                html += '<td style="padding:4px 6px;color:#1a5276;font-weight:600;">' + escapeHtml(it.product_id || '') + '</td>';
                html += '<td style="padding:4px 6px;line-height:1.6;">' + combined + '</td>';
                html += '<td style="padding:4px 6px;text-align:right;">' + escapeHtml(String(it.quantity || '')) + ' ' + (it.unit || '') + '</td>';
                html += '<td style="padding:4px 6px;text-align:right;color:' + (it.is_tiered ? '#888' : '#c0392b') + ';">' + (it.is_tiered ? '(階梯)' : (it.unit_price ? '$' + Number(it.unit_price).toLocaleString() : '—')) + '</td>';
                html += '</tr>';
                // 已轉訂單：下方一併顯示該筆訂單資料與訂單附件
                if (it.order_oo) {
                    html += '<tr style="border-bottom:1px solid #f0f0f0;background:#eafaf6;">';
                    html += '<td colspan="4" style="padding:5px 6px;">';
                    html += '<div style="font-size:11px;color:#1ABB9C;font-weight:700;"><i class="fa fa-exchange"></i> 訂單 ' + escapeHtml(it.order_oo) + '</div>';
                    var oInfo = [];
                    if (it.order_date)     oInfo.push('訂單日期：' + dispDate(it.order_date));
                    if (it.order_delivery) oInfo.push('交期：' + dispDate(it.order_delivery));
                    if (it.order_qty)      oInfo.push('數量：' + it.order_qty);
                    if (it.order_unit_price) oInfo.push('單價：$' + Number(it.order_unit_price).toLocaleString());
                    if (oInfo.length) html += '<div style="font-size:10px;color:#555;margin-top:2px;">' + oInfo.map(escapeHtml).join('　') + '</div>';
                    if (it.order_attachments && it.order_attachments.length) {
                        html += '<div style="font-size:10px;color:#666;margin-top:3px;">';
                        html += it.order_attachments.map(function(oa) {
                            var catBadge = (oa.category_names || []).map(function(cn) {
                                return '<span style="background:#FFF3E2;border:1px solid #E4D3BC;color:#8a5a2b;border-radius:3px;padding:0 3px;margin-right:2px;">' + escapeHtml(cn) + '</span>';
                            }).join('');
                            return '<div style="margin-top:2px;"><i class="fa fa-file-o"></i> <a href="' + oa.url + '" target="_blank" style="color:#337ab7;">' + escapeHtml(oa.display_name) + '</a> ' + catBadge + '</div>';
                        }).join('');
                        html += '</div>';
                    } else {
                        html += '<div style="font-size:10px;color:#aaa;margin-top:3px;">（此訂單尚無附件）</div>';
                    }
                    html += '</td></tr>';
                }
            });
            html += '</tbody></table>';
        }
    }
    html += '</div>';
    $('#bom-quote-detail').html(html).show();
}

function switchTab(tab) {
    if (!_tabEnabled[tab]) return;
    _tagFilterActive = null; // 切換分頁＝離開全域篩選結果檢視，回到該分頁正常顯示
    renderTagFilterBar();
    _activeTab = tab;
    $('#bom-tabbar .bom-tab').removeClass('active').filter('[data-tab="'+tab+'"]').addClass('active');
    if (tab === 'drawing') renderDrawingList();
    else if (tab === 'order_attach') renderOrderQuoteTab();
    else renderAttList(tab);
}
$(document).on('click', '#bom-tabbar .bom-tab', function() { switchTab($(this).data('tab')); });
$(document).on('click', '.bom-quote-head', function() { showQuoteDetail($(this).data('qno')); });

// ── 頂列跨分頁全域標籤篩選（報價/其他/訂單附件三種來源的附件類別標籤一起篩，圖面分頁的圖檔沒有這種標籤不參與）──
var _tagFilterActive = null; // 目前套用的標籤名稱；null=未篩選
function renderTagFilterBar() {
    if (_mode !== 'did') { $('#bom-tag-filter-bar').hide(); return; }
    var tagSet = {};
    ['quote', 'other', 'order_attach'].forEach(function(t) {
        (_tabData[t] || []).forEach(function(f) { (f.category_names || []).forEach(function(cn) { if (cn !== '作廢') tagSet[cn] = true; }); });
    });
    var tags = Object.keys(tagSet).sort();
    if (!tags.length) { $('#bom-tag-filter-bar').hide(); return; }
    var html = '<span style="color:#8a5a2b;font-weight:700;margin-right:4px;"><i class="fa fa-filter"></i> 標籤篩選：</span>';
    tags.forEach(function(t) {
        var active = (_tagFilterActive === t);
        html += '<span class="bom-tag-chip" data-tag="' + escapeHtml(t) + '" style="display:inline-block;cursor:pointer;margin:0 4px 4px 0;padding:1px 8px;border-radius:10px;font-size:11px;'
            + (active ? 'background:#d4761a;color:#fff;' : 'background:#fff;color:#8a5a2b;border:1px solid #e6c9a0;') + '">' + escapeHtml(t) + '</span>';
    });
    if (_tagFilterActive) html += '<span id="bom-tag-clear" style="cursor:pointer;color:#c0392b;font-size:11px;margin-left:6px;"><i class="fa fa-times-circle"></i> 清除篩選</span>';
    $('#bom-tag-filter-bar').html(html).show();
}
function renderTagFilterResults() {
    $('#bom-tabbar .bom-tab').removeClass('active');
    var items = [];
    ['quote', 'other', 'order_attach'].forEach(function(t) {
        (_tabData[t] || []).forEach(function(f) { if ((f.category_names || []).indexOf(_tagFilterActive) !== -1) items.push(f); });
    });
    if (!items.length) { $('#bom-file-list').html('<div class="alert alert-warning" style="margin:10px;">此標籤沒有符合的附件</div>'); showEmpty('此標籤沒有符合的附件'); return; }
    $('#bom-file-list').html(items.map(function(f) { return makeAttItem(f, true); }).join(''));
    var f0 = items[0];
    $('#bom-file-list .bom-file-item').first().addClass('active');
    showFile(f0.url, f0.ext, f0.display_name);
    applyObsoleteOverlay((f0.category_names || []).indexOf('作廢') >= 0);
}
$(document).on('click', '.bom-tag-chip', function() {
    var t = $(this).data('tag');
    _tagFilterActive = (_tagFilterActive === t) ? null : t;
    renderTagFilterBar();
    if (_tagFilterActive) renderTagFilterResults(); else switchTab(_activeTab);
});
$(document).on('click', '#bom-tag-clear', function() {
    _tagFilterActive = null;
    renderTagFilterBar();
    switchTab(_activeTab);
});

// ── 載入 ───────────────────────────────────────────────────────────────
function loadBomMode() {
    $.post('OreadyReply_ForPm_BaseOfTime.php', { action: 'get_bom_files', bom: _bom }, function(res) {
        _tabData.drawing = {
            files:     (res && res.success && res.files)     ? res.files     : [],
            erp_files: (res && res.success && res.erp_files) ? res.erp_files : []
        };
        renderDrawingList();
    }, 'json').fail(function() {
        $('#bom-file-list').html('<div class="alert alert-danger" style="margin:10px;">載入失敗，請重試</div>');
    });
}

function loadDidMode() {
    var pending = 1 + (_tabEnabled.other ? 1 : 0) + (_tabEnabled.order_attach ? 1 : 0);
    var done = function() { if (--pending <= 0) finishDidLoad(); };
    // 圖面（一律載入）
    $.post('', { action: 'get_files_by_did', d_id: _d_id }, function(res) {
        _tabData.drawing = {
            files:     (res && res.success && res.files)     ? res.files     : [],
            erp_files: (res && res.success && res.erp_files) ? res.erp_files : []
        };
    }, 'json').always(function() { renderTabbar(); done(); });
    // 其他附件
    if (_tabEnabled.other) {
        $.post('', { action: 'get_attachments_by_did', d_id: _d_id }, function(res) {
            _tabData.other   = (res && res.success && res.attachments) ? res.attachments : [];
            _tabData.albums  = (res && res.albums) ? res.albums : [];   // 相簿清單（產品照片等標籤用）
        }, 'json').always(function() { renderTabbar(); done(); });
    }
    // 訂單／報價合併分頁：訂單附件（_canOrder）＋報價附件/明細（_canQuote，get_quote_summaries 以文字料號跨客戶查）
    if (_tabEnabled.order_attach) {
        var reqs = [];
        if (_canOrder) {
            reqs.push($.post('', { action: 'get_order_attachments_by_did', d_id: _d_id }, function(res) {
                _tabData.order_attach = (res && res.success && res.attachments) ? res.attachments : [];
            }, 'json'));
        }
        if (_canQuote) {
            reqs.push($.post('', { action: 'get_quote_attachments_by_did', d_id: _d_id }, function(res) {
                _tabData.quote = (res && res.success && res.attachments) ? res.attachments : [];
            }, 'json'));
            reqs.push($.post('../../src/store/Part_Attachment_API.php', { action: 'get_quote_summaries', part_no: _d_id }, function(res) {
                _tabData.quoteSummaries = (res && res.success && res.data && typeof res.data === 'object' && !Array.isArray(res.data)) ? res.data : {};
            }, 'json'));
        }
        $.when.apply($, reqs).always(function() { renderTabbar(); done(); });
    }
    renderTabbar();
}

function finishDidLoad() {
    renderTabbar();
    renderTagFilterBar();
    // 預選：指定 tab 優先；否則第一個「啟用且有資料」的分頁；再否則圖面
    var order = ['drawing','order_attach','other'], pick = '';
    if (_initTab && _tabEnabled[_initTab]) pick = _initTab;
    if (!pick) { for (var i = 0; i < order.length; i++) { if (_tabEnabled[order[i]] && tabCount(order[i]) > 0) { pick = order[i]; break; } } }
    if (!pick) pick = 'drawing';
    switchTab(pick);
}

if (_mode === 'bom') loadBomMode(); else loadDidMode();

// ── 設定標籤 ──────────────────────────────────────────────────────────────
var _colorMap = { 'default':'#777777','primary':'#337ab7','success':'#5cb85c','info':'#5bc0de','warning':'#f0ad4e','danger':'#d9534f' };

function openFileTagsSetting() {
    $.post('', { action: 'get_file_tags_setting' }, function(res) {
        if (res.success) {
            $('#tagsSettingTable tbody').empty();
            if (res.config && res.config.length > 0) {
                res.config.forEach(function(item) { addTagRow(item.suffix, item.label, item.color); });
            }
            $('#fileTagsSettingModal').modal('show');
        } else {
            alert('載入設定失敗: ' + (res.message || '未知錯誤'));
        }
    }, 'json');
}

function addTagRow(suffix, label, color) {
    var c = _colorMap[color] || (color && color.startsWith('#') ? color : '#777777');
    var row = '<tr>'
        + '<td><input type="text" class="form-control input-sm tag-suffix" value="' + escapeHtml(suffix||'') + '" placeholder="-T"></td>'
        + '<td><input type="text" class="form-control input-sm tag-label" value="' + escapeHtml(label||'') + '" placeholder="叫料"></td>'
        + '<td><input type="color" class="form-control input-sm tag-color" value="' + escapeHtml(c) + '"></td>'
        + '<td><button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest(\'tr\').remove()"><i class="fa fa-trash"></i></button></td>'
        + '</tr>';
    $('#tagsSettingTable tbody').append(row);
}

function saveFileTagsSetting() {
    var config = [];
    $('#tagsSettingTable tbody tr').each(function() {
        var suffix = $(this).find('.tag-suffix').val().trim();
        var label  = $(this).find('.tag-label').val().trim();
        var color  = $(this).find('.tag-color').val();
        if (suffix && label) config.push({ suffix: suffix, label: label, color: color });
    });
    $.post('', { action: 'save_file_tags_setting', tags_config: JSON.stringify(config) }, function(res) {
        if (res.success) {
            $('#fileTagsSettingModal').modal('hide');
            // 重新載入檔案清單以套用新標籤
            location.reload();
        } else {
            alert('儲存失敗: ' + (res.message || '未知錯誤'));
        }
    }, 'json');
}
</script>
</body>
</html>
