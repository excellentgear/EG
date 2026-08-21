<?php
/**
 * 型態識別文件管制表 —— 共用庫（本頁AS文件編號動態綁定，不寫死）
 * 每個料號一份「型態配置」清單，逐列記錄定義該料號目前狀態的文件（原圖/報價單/加工圖/
 * 產品開發評估表/PFMEA/檢驗報告…），可手動輸入版別/文件編號，也可連結「外來文件清單」
 * 既有附件（is_external_doc 標籤）即時取用其檔名與上傳日期——使用者明確要求即時連動不存快照，
 * 所以連結列的顯示內容一律當下查詢 part_attachments/quotation_attachments，不快取進本表。
 *
 * 資料表：type_id_ctrl_doc（表頭：客戶/料號/製程/本表文件編號）
 *        type_id_ctrl_item（項目列：型態項目名稱/生效日期/類別/版別文件編號或連結）
 * 權限：roles module='type_id_ctrl'（admin ⊃ edit ⊃ view），比照 vendor_audit_lib.php 慣例。
 */

function type_id_ctrl_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS type_id_ctrl_doc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_no VARCHAR(20) NOT NULL COMMENT '本表文件編號(YYYYMMDD+3位流水號)',
        customer_id CHAR(11) NULL COMMENT '對應customer_list.customer_id',
        part_d_id INT NULL COMMENT '對應d_setting.d_id(產品編號/料號)',
        process_desc VARCHAR(200) NULL COMMENT '製程',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        updated_at TIMESTAMP NULL,
        updated_by INT NULL,
        updated_by_name VARCHAR(50) NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_doc_no (doc_no),
        KEY idx_part (part_d_id),
        KEY idx_customer (customer_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='型態識別文件管制表-表頭'");

    $db->exec("CREATE TABLE IF NOT EXISTS type_id_ctrl_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL COMMENT 'FK type_id_ctrl_doc.id',
        seq INT NOT NULL COMMENT '項次(顯示排序)',
        item_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '型態項目名稱',
        item_type VARCHAR(10) NOT NULL DEFAULT 'other' COMMENT 'drawing=圖面 jig=治夾具 report=報告 other=其他文件',
        ref_source VARCHAR(10) NULL COMMENT '連結外來文件清單來源:part/quote;NULL=未連結(手動輸入)',
        ref_attach_id INT NULL COMMENT '連結:part_attachments.id或quotation_attachments.id',
        ref_ds_pk INT NULL COMMENT '連結:d_setting.d_id',
        manual_effective_date DATE NULL COMMENT '手動輸入的型態生效日期(未連結時用)',
        manual_doc_no VARCHAR(100) NULL COMMENT '手動輸入的版別/文件編號(未連結時用)',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        KEY idx_doc (doc_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='型態識別文件管制表-項目列'");

    // 確認流程欄位（2026-08-12 新增：外來文件清單自動同步 + 人工確認機制，使用者明確要求）
    foreach ([
        "ALTER TABLE type_id_ctrl_doc ADD COLUMN review_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending=待確認 confirmed=已確認 needs_recheck=需重新確認' AFTER process_desc",
        "ALTER TABLE type_id_ctrl_doc ADD COLUMN confirmed_by INT NULL AFTER review_status",
        "ALTER TABLE type_id_ctrl_doc ADD COLUMN confirmed_by_name VARCHAR(50) NULL AFTER confirmed_by",
        "ALTER TABLE type_id_ctrl_doc ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by_name",
        "ALTER TABLE type_id_ctrl_item ADD COLUMN is_excluded TINYINT(1) NOT NULL DEFAULT 0 COMMENT '人工確認此項不適用本製程(僅對連結自外來文件清單的列有意義)' AFTER ref_ds_pk",
        // review_status 原本建成 VARCHAR(12)，'needs_recheck' 13字會被截斷寫入失敗，補一次放寬（既有欄位已存在時 ADD COLUMN 會被上面的 try/catch 吃掉不會跑到這裡，故獨立用 MODIFY 確保既有環境也會放寬）
        "ALTER TABLE type_id_ctrl_doc MODIFY COLUMN review_status VARCHAR(20) NOT NULL DEFAULT 'pending'",
        // 廠內「自家出的圖」標籤納入本模組來源（2026-08-12 使用者要求）：quotation_file_categories 已有
        // is_own_drawing(自家出的圖)/is_external_doc(外來文件清單) 兩個既有旗標，這裡加第三個獨立旗標，
        // 讓管理員從「自家出的圖」的類別中，另外勾選哪些也要納入本模組（設定入口在本頁，不是主檔管理頁）。
        "ALTER TABLE quotation_file_categories ADD COLUMN type_id_ctrl_include TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否納入型態識別文件管制表(僅對is_own_drawing=1的類別有意義)'",
        // 「廠內圖面標籤設定」新增兩項可設定值（2026-08-12 使用者要求）：①顯示名稱沿用既有
        // external_doc_name 欄位(與外來文件清單共用同一顯示名稱，不另開欄位)；②need_process 標記
        // 該類別文件是否建議標示所屬製程，僅供項目列「所屬製程」欄留空時的視覺提示，不強制驗證。
        "ALTER TABLE quotation_file_categories ADD COLUMN type_id_ctrl_need_process TINYINT(1) NOT NULL DEFAULT 0 COMMENT '此類別文件是否建議標示所屬製程(僅視覺提示，設定入口見型態識別文件管制表「廠內圖面標籤設定」)'",
        "ALTER TABLE type_id_ctrl_item ADD COLUMN need_process_hint TINYINT(1) NOT NULL DEFAULT 0 COMMENT '同步當下來源類別是否建議標示所屬製程(僅視覺提示，快照非即時)' AFTER process_tag",
        // 架構改版（2026-08-12 使用者拍板）：原本「一料號一製程一份」造成同一張共用圖面在多份管制表
        // 重複出現，改成「一料號一份」，製程改記在每一列項目上（共用文件留空＝適用全部製程）。
        // process_desc 欄位保留但不再是尋找/建立表頭的鍵值，僅作歷史相容用途，新資料不寫入。
        "ALTER TABLE type_id_ctrl_item ADD COLUMN process_tag VARCHAR(200) NULL COMMENT '所屬製程(空=共用/適用全部製程)，自動由報價項目製程推導，可手動修改或清空' AFTER item_type",
        // 2026-08-20 使用者要求新增三種自動偵測來源：產品開發評估表(td_dev_eval)、PFMEA(pfmea_doc)
        // 與 part_viewer 的 ERP/資材報告 NAS 檔案。前兩者用單據 id 當 ref_attach_id 即可，
        // NAS 檔案沒有 id，另用檔名當識別鍵（同一料號同一標籤只會帶最新一份，故檔名足以識別）。
        "ALTER TABLE type_id_ctrl_item ADD COLUMN ref_file_name VARCHAR(255) NULL COMMENT '連結NAS檔案時的檔名(ref_source=bomfile專用，其餘來源為NULL)' AFTER ref_ds_pk",
        "ALTER TABLE type_id_ctrl_item ADD COLUMN ref_bom_tag VARCHAR(30) NULL COMMENT 'ERP/資材報告檔名標籤後綴(ref_source=bomfile專用)；同一標籤永遠只有一列，檔案換新版時原地更新不另開列' AFTER ref_file_name",
    ] as $alter) {
        try { $db->exec($alter); } catch (Throwable $e) {}
    }

    foreach ([['type_id_ctrl_view','型態文件檢閱'],['type_id_ctrl_edit','型態文件登錄'],['type_id_ctrl_admin','型態文件管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='type_id_ctrl' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'type_id_ctrl')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

function type_id_ctrl_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function type_id_ctrl_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='type_id_ctrl' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='type_id_ctrl' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function type_id_ctrl_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true) || $uid === 1;
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || type_id_ctrl_has_role($db, $uid, ['type_id_ctrl_admin']);
    $canEdit  = $canAdmin || type_id_ctrl_has_role($db, $uid, ['type_id_ctrl_edit']);
    $canView  = $canEdit  || type_id_ctrl_has_role($db, $uid, ['type_id_ctrl_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/** 本公司名稱（列印大標題統一來源：customer_list.is_own_company=1，見 ai-rules/16） */
function type_id_ctrl_company_name(PDO $db): string {
    try {
        $st = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) { $n = trim((string)($r['customer_full'] ?: $r['customer'])); if ($n !== '') return $n; }
    } catch (Throwable $e) {}
    return '超正齒輪科技有限公司';
}

/** 產生本表文件編號：YYYYMMDD + 3位流水號（以 DB 日期為準，避免 PHP 時區誤差） */
function type_id_ctrl_next_doc_no(PDO $db): string {
    $today = $db->query("SELECT DATE_FORMAT(CURDATE(),'%Y%m%d')")->fetchColumn();
    $like = $today . '%';
    $st = $db->prepare("SELECT doc_no FROM type_id_ctrl_doc WHERE doc_no LIKE ? ORDER BY doc_no DESC LIMIT 1");
    $st->execute([$like]);
    $last = $st->fetchColumn();
    $seq = $last ? ((int)substr((string)$last, 8, 3) + 1) : 1;
    return $today . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/**
 * 即時解析一筆連結外來文件（不快照）：回傳目前的檔名/日期/下載連結，來源已刪除則回傳 null。
 * part 來源優先用「版次(revision)」「發行章日期(issue_stamp_date)」顯示（自家出的圖才會填這兩欄；
 * 客戶提供的外來文件通常沒填，此時自動退回檔名/上傳日——2026-08-12 使用者要求）。
 */
function type_id_ctrl_resolve_ref(PDO $db, string $source, int $attachId, int $dsPk, ?string $fileName = null): ?array {
    // 2026-08-20 使用者要求新增的三種來源：本系統內建立的表單（產品開發評估表／PFMEA）與 NAS 上的
    // ERP/資材報告檔案。表單類的「版別／文件編號」＝該表單的表單編號（doc_no，已改為依表單日期產生），
    // 是真正的文件編號，所以 doc_no_is_filename=false（列印會印出來）。
    if ($source === 'dev_eval' || $source === 'pfmea') {
        $def = type_id_ctrl_form_doc_defs($db)[$source] ?? null;
        if (!$def) return null;
        $st = $db->prepare("SELECT doc_no, {$def['date_col']} AS doc_date FROM {$def['table']} WHERE id=? AND is_deleted=0 LIMIT 1");
        $st->execute([$attachId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'doc_name' => $r['doc_no'],
            'doc_no_is_filename' => false,
            'doc_date' => $r['doc_date'],
            'file_url' => $def['page'] . '?kw=' . rawurlencode((string)$r['doc_no']),
        ];
    }
    if ($source === 'bomfile') {
        $fileName = trim((string)$fileName);
        if ($fileName === '') return null;
        $full = type_id_ctrl_bom_file_path($db, $fileName);
        if ($full === null || !is_file($full)) return null;
        return [
            // NAS 檔案沒有版次欄位，doc_name 一律是檔名 → 比照料號附件，列印不印檔名當文件編號
            'doc_name' => $fileName,
            'doc_no_is_filename' => true,
            'doc_date' => date('Y-m-d', (int)filemtime($full)),
            'file_url' => '../../src/store/ConfigIdDoc_API.php?action=download_bom_file&name=' . rawurlencode($fileName),
        ];
    }
    if ($source === 'part') {
        $st = $db->prepare("SELECT COALESCE(NULLIF(pa.original_name,''), pa.filename) AS doc_name,
                                    DATE(pa.uploaded_at) AS doc_date, pa.filename, pa.revision, pa.issue_stamp_date
                             FROM part_attachments pa
                             WHERE pa.id=? AND pa.d_id=? AND pa.deleted_at IS NULL LIMIT 1");
        $st->execute([$attachId, $dsPk]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        // doc_no_is_filename：沒填版次時退回檔名充當顯示用途，但檔名不是真正的「版別／文件編號」，
        // 列印時不應印出（2026-08-12 使用者要求），僅畫面/跳窗仍顯示以利辨識檔案。
        $hasRevision = ($r['revision'] !== null && $r['revision'] !== '');
        return [
            'doc_name' => $hasRevision ? $r['revision'] : $r['doc_name'],
            'doc_no_is_filename' => !$hasRevision,
            'doc_date' => $r['issue_stamp_date'] ?: $r['doc_date'],
            'file_url' => '../../src/store/Part_Attachment_API.php?action=download&id=' . $attachId,
        ];
    }
    if ($source === 'quote') {
        $st = $db->prepare("SELECT COALESCE(NULLIF(a.original_name,''), a.filename) AS doc_name,
                                    DATE(a.uploaded_at) AS doc_date, a.filename, a.quote_no
                             FROM quotation_attachments a
                             WHERE a.id=? AND a.status='active' LIMIT 1");
        $st->execute([$attachId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            // 報價附件沒有版次欄位，doc_name 一律是檔名，同理列印時不印（僅畫面顯示供辨識）
            'doc_name' => $r['doc_name'], 'doc_no_is_filename' => true, 'doc_date' => $r['doc_date'],
            'file_url' => '../../src/store/Quotation_File_API.php?action=download&quote_no=' . rawurlencode($r['quote_no']) . '&filename=' . rawurlencode($r['filename']),
        ];
    }
    return null;
}

/**
 * 此料號目前所有可納入本模組的附件：外來文件清單標籤(is_external_doc=1) ＋ 管理員另外勾選納入的
 * 廠內「自家出的圖」標籤(type_id_ctrl_include=1，設定入口見本頁「廠內圖面標籤設定」)。
 * 與 ConfigIdDoc_API.php 舊版 search_ext_doc 邏輯相同來源，但不加關鍵字篩選（同步/自動產生用）。
 */
function type_id_ctrl_fetch_ext_docs_for_part(PDO $db, int $dsPk): array {
    $catRows = $db->query("SELECT id, COALESCE(NULLIF(external_doc_name,''), category_name) AS disp,
                                   COALESCE(type_id_ctrl_need_process,0) AS need_process
                            FROM quotation_file_categories WHERE is_external_doc=1 OR type_id_ctrl_include=1")->fetchAll(PDO::FETCH_ASSOC);
    // 2026-08-20 起本模組還會自動偵測「本系統內建立的表單」與「NAS 上的 ERP/資材報告」，
    // 跟附件類別設定無關，所以就算一個類別都沒設定也不能整支早退回空陣列。
    if (!$catRows) {
        return array_merge(type_id_ctrl_fetch_form_docs_for_part($db, $dsPk),
                           type_id_ctrl_fetch_bom_files_for_part($db, $dsPk));
    }
    $cats = [];
    foreach ($catRows as $cr) { $cats[(int)$cr['id']] = ['disp'=>$cr['disp'], 'need_process'=>(bool)$cr['need_process']]; }
    $catIds = array_keys($cats);
    $catCond = function (string $col, string $singleCol = '') use ($catIds): string {
        $parts = [];
        foreach ($catIds as $cid) $parts[] = "FIND_IN_SET($cid, REPLACE(COALESCE($col,''),' ',''))";
        if ($singleCol !== '') $parts[] = "$singleCol IN (" . implode(',', $catIds) . ")";
        return '(' . implode(' OR ', $parts) . ')';
    };
    $rows = [];

    // 料號附件：無製程資訊(本來就與特定製程無關的共用文件，如原圖)，origin_process 一律 NULL
    $sql = "SELECT pa.id AS attach_id, pa.d_id AS ds_pk,
                   COALESCE(NULLIF(pa.original_name,''), pa.filename) AS doc_name,
                   DATE(pa.uploaded_at) AS doc_date, pa.category_ids, '' AS category_id_single,
                   NULL AS origin_process
            FROM part_attachments pa
            WHERE pa.d_id=? AND pa.deleted_at IS NULL AND " . $catCond('pa.category_ids');
    $st = $db->prepare($sql); $st->execute([$dsPk]);
    // 批圖工作檔(.egwork.json)不是文件，一律不入管制表（同 ExternalDoc_API 的保險）
    require_once __DIR__ . '/imgedit_visibility.php';
    foreach (imgedit_strip_workfiles($st->fetchAll(PDO::FETCH_ASSOC)) as $r) { $r['source'] = 'part'; $rows[] = $r; }

    $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
    $st->execute([$dsPk]);
    $partNo = (string)$st->fetchColumn();
    if ($partNo !== '') {
        // 報價附件：若此料號在同一張報價單裡有「多個」報價項目(代表這張報價單本來就把此料號拆成多筆
        // 不同製程分開報價)，用各項目勾選的製程(quotation_item_process_map)GROUP_CONCAT自動帶入
        // （可手動修改/清空）；報價單裡此料號只有「一個」報價項目時，不論該項目勾了幾種製程，都不算
        // 有需要區分的多筆文件，直接留 NULL 當共用文件（2026-08-12 使用者要求：只有一種文件時不該
        // 自動代入製程，應自動留空——attachments 不記錄對應到哪一個報價項目，只有「存在多個報價項目」
        // 才代表這批文件本來就要按製程拆開看，單一項目一律視為共用）。對應不到報價項目也維持 NULL。
        $sql = "SELECT DISTINCT a.id AS attach_id, ? AS ds_pk,
                       COALESCE(NULLIF(a.original_name,''), a.filename) AS doc_name,
                       DATE(a.uploaded_at) AS doc_date, a.category_ids, COALESCE(a.category_id,'') AS category_id_single,
                       (SELECT CASE WHEN COUNT(DISTINCT qi3.item_id) > 1
                                    THEN GROUP_CONCAT(DISTINCT pn.ProcessName ORDER BY pn.ProcessName SEPARATOR '+')
                                    ELSE NULL END
                        FROM quotation_item qi3
                        JOIN quotation_item_process_map m3 ON m3.quotation_item_id = qi3.item_id
                        JOIN process_no pn ON pn.ProcessNo = m3.process_no
                        WHERE qi3.quote_id = (SELECT quote_id FROM quotation_list WHERE quote_no=a.quote_no)
                          AND qi3.d_setting_d_id = ?
                       ) AS origin_process
                FROM quotation_attachments a
                JOIN quotation_item qi ON qi.quote_id = (SELECT quote_id FROM quotation_list WHERE quote_no=a.quote_no)
                WHERE a.status='active' AND " . $catCond('a.category_ids', 'a.category_id') . "
                  AND ((a.linked_parts IS NULL AND qi.d_setting_d_id = ?)
                       OR (a.linked_parts IS NOT NULL AND JSON_CONTAINS(a.linked_parts, JSON_QUOTE(?))))";
        $st = $db->prepare($sql); $st->execute([$dsPk, $dsPk, $dsPk, $partNo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['source'] = 'quote'; $rows[] = $r; }
    }

    foreach ($rows as &$r) {
        $names = []; $needProcess = false;
        foreach (array_filter(explode(',', str_replace(' ', '', (string)$r['category_ids']))) as $cid) {
            if (isset($cats[(int)$cid])) {
                $names[] = $cats[(int)$cid]['disp'];
                if ($cats[(int)$cid]['need_process']) $needProcess = true;
            }
        }
        if (!$names && $r['category_id_single'] !== '' && isset($cats[(int)$r['category_id_single']])) {
            $names[] = $cats[(int)$r['category_id_single']]['disp'];
            if ($cats[(int)$r['category_id_single']]['need_process']) $needProcess = true;
        }
        $r['categories'] = $names;
        $r['need_process'] = $needProcess;
        unset($r['category_ids'], $r['category_id_single']);
    }
    unset($r);

    // 本系統內建立的表單（產品開發評估表／PFMEA）與 NAS 的 ERP/資材報告檔案（2026-08-20 使用者要求）
    return array_merge($rows,
                       type_id_ctrl_fetch_form_docs_for_part($db, $dsPk),
                       type_id_ctrl_fetch_bom_files_for_part($db, $dsPk));
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 自動偵測來源二：本系統內建立的表單（2026-08-20 使用者要求）
 *   dev_eval ＝產品開發評估表(td_dev_eval)、pfmea ＝潛在失效模式及效應分析(pfmea_doc)
 *   型態項目名稱＝該表單綁定的 AS 文件名稱（沒綁定才退回預設名稱，不寫死一份對照表＝鐵律4）
 *   型態生效日期＝表單自己的業務日期（填表日期／業務日期）
 *   版別/文件編號＝表單編號 doc_no（2026-08-20 起編號本身就是依這個日期產生的）
 *   型態類別一律「其他文件」(other)——使用者明確指定。
 * ══════════════════════════════════════════════════════════════════════════════ */

/** 兩種表單來源的定義表（資料表/日期欄/頁面/AS綁定模組代碼/預設名稱） */
function type_id_ctrl_form_doc_defs(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $defs = [
        'dev_eval' => ['table'=>'td_dev_eval', 'date_col'=>'fill_date', 'page'=>'td_dev_eval.php',
                       'asdoc_module'=>'td_dev_eval', 'default_name'=>'產品開發評估表'],
        'pfmea'    => ['table'=>'pfmea_doc',   'date_col'=>'biz_date',  'page'=>'pfmea.php',
                       'asdoc_module'=>'pfmea', 'default_name'=>'潛在失效模式及效應分析'],
    ];
    foreach ($defs as $k => $d) {
        $name = '';
        if (function_exists('eg_asdoc_get')) {
            $doc = eg_asdoc_get($db, $d['asdoc_module']);
            $name = trim((string)($doc['doc_name'] ?? ''));
        }
        $defs[$k]['item_name'] = $name !== '' ? $name : $d['default_name'];
    }
    return $cache = $defs;
}

/** 此料號目前已建立的表單（產品開發評估表／PFMEA），組成與外來文件附件相同格式的列 */
function type_id_ctrl_fetch_form_docs_for_part(PDO $db, int $dsPk): array {
    if (!$dsPk) return [];
    $out = [];
    foreach (type_id_ctrl_form_doc_defs($db) as $src => $d) {
        try {
            $st = $db->prepare("SELECT id, doc_no, {$d['date_col']} AS doc_date FROM {$d['table']}
                                 WHERE part_d_id=? AND is_deleted=0 ORDER BY {$d['date_col']}, id");
            $st->execute([$dsPk]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'source'      => $src,
                    'attach_id'   => (int)$r['id'],
                    'ds_pk'       => $dsPk,
                    'file_name'   => null,
                    'doc_name'    => (string)$r['doc_no'],
                    'doc_date'    => $r['doc_date'],
                    'categories'  => [$d['item_name']],
                    'need_process'=> false,
                    'origin_process' => null,
                    'force_type'  => 'other',   // 使用者指定：這兩種一律「其他文件」
                ];
            }
        } catch (Throwable $e) { /* 表不存在時略過 */ }
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 自動偵測來源三：料號圖面查閱(views/pm/part_viewer.php)的 ERP/資材報告 檔名標籤
 *   標籤本身（後綴→標籤名稱）的唯一來源是 part_viewer 既有的
 *   system_parameters('BOM_FILE_TAGS','tags_config')，本模組不另存一份（鐵律4）；
 *   本模組只另外存「這個標籤要不要列入／列入後的型態項目名稱與型態類別」，逐標籤分開設定。
 *   檔案 ↔ 料號的對應比照 part_viewer：檔名以「該料號的 BOM 名稱＋後綴」開頭，
 *   且後綴後面接的不是英數字（避免 -T 誤中 -TR）。
 *   使用者 2026-08-20 拍板：同一個標籤只帶「最新一份」（跨該料號所有 BOM 比檔案日期）。
 * ══════════════════════════════════════════════════════════════════════════════ */

/** ERP/資材報告資料夾（可在本頁「BOM檔案標籤設定」改；預設＝part_viewer 目前掃描的位置） */
function type_id_ctrl_bom_file_dir(PDO $db): string {
    $dir = '';
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='TYPE_ID_CTRL' AND param_key='bom_file_dir' LIMIT 1");
        $st->execute();
        $dir = trim((string)$st->fetchColumn());
    } catch (Throwable $e) {}
    if ($dir === '') $dir = 'Z:/BOM/ERP/資材(生管and業務)/BOM/';
    $dir = str_replace('\\', '/', $dir);
    if (substr($dir, -1) !== '/') $dir .= '/';
    return $dir;
}

/** UTF-8 路徑 → 實際可用來讀檔的路徑（Windows 的 NAS 目錄含中文，PHP 檔案函式吃 Big5） */
function type_id_ctrl_fs_path(string $utf8Path): string {
    $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    return $isWin ? mb_convert_encoding($utf8Path, 'Big5', 'UTF-8') : $utf8Path;
}

/**
 * 檔名 → 完整實體路徑（同時是下載端點的路徑守門：只允許單純檔名，
 * 擋掉 .. 與路徑分隔字元，避免被指定成資料夾外的任意檔案）。不合法回 null。
 */
function type_id_ctrl_bom_file_path(PDO $db, string $fileName): ?string {
    $fileName = trim($fileName);
    if ($fileName === '' || strpbrk($fileName, "/\\") !== false || strpos($fileName, '..') !== false) return null;
    return type_id_ctrl_fs_path(type_id_ctrl_bom_file_dir($db) . $fileName);
}

/** part_viewer 既有的檔名標籤設定（唯一來源，本模組只讀不寫） */
function type_id_ctrl_bom_tags_all(PDO $db): array {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='BOM_FILE_TAGS' AND param_key='tags_config' LIMIT 1");
        $st->execute();
        $arr = json_decode((string)$st->fetchColumn(), true);
    } catch (Throwable $e) { $arr = null; }
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $t) {
        $suffix = trim((string)($t['suffix'] ?? ''));
        if ($suffix === '') continue;
        $out[] = ['suffix'=>$suffix, 'label'=>trim((string)($t['label'] ?? '')), 'color'=>(string)($t['color'] ?? '')];
    }
    return $out;
}

/** 本模組對各標籤的設定：suffix => [item_name, item_type]（只存有勾選列入的） */
function type_id_ctrl_bom_tag_map_get(PDO $db): array {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='TYPE_ID_CTRL' AND param_key='bom_file_tags' LIMIT 1");
        $st->execute();
        $arr = json_decode((string)$st->fetchColumn(), true);
    } catch (Throwable $e) { $arr = null; }
    if (!is_array($arr)) return [];
    $valid = ['drawing','jig','report','other'];
    $out = [];
    foreach ($arr as $suffix => $cfg) {
        $suffix = trim((string)$suffix);
        if ($suffix === '' || !is_array($cfg)) continue;
        $type = (string)($cfg['item_type'] ?? 'other');
        $out[$suffix] = [
            'item_name' => trim((string)($cfg['item_name'] ?? '')),
            'item_type' => in_array($type, $valid, true) ? $type : 'other',
        ];
    }
    return $out;
}

/** 儲存標籤設定（$map: suffix => [item_name, item_type]，只傳有勾選列入的） */
function type_id_ctrl_bom_tag_map_save(PDO $db, array $map, string $dir, string $byUser): void {
    $tags = [];
    foreach (type_id_ctrl_bom_tags_all($db) as $t) $tags[$t['suffix']] = $t['label'];
    $valid = ['drawing','jig','report','other'];
    $clean = [];
    foreach ($map as $suffix => $cfg) {
        $suffix = trim((string)$suffix);
        if ($suffix === '' || !isset($tags[$suffix]) || !is_array($cfg)) continue;  // 只認 part_viewer 現有的標籤
        $name = trim((string)($cfg['item_name'] ?? ''));
        if ($name === '') $name = $tags[$suffix];                                    // 沒填就用標籤名稱
        $type = (string)($cfg['item_type'] ?? 'other');
        $clean[$suffix] = ['item_name'=>$name, 'item_type'=>in_array($type, $valid, true) ? $type : 'other'];
    }
    type_id_ctrl_param_save($db, 'bom_file_tags', json_encode($clean, JSON_UNESCAPED_UNICODE),
                            '型態識別文件管制表：要列入的 ERP/資材報告檔名標籤與對應型態項目名稱/類別', $byUser);
    $dir = trim($dir);
    if ($dir !== '') type_id_ctrl_param_save($db, 'bom_file_dir', $dir, '型態識別文件管制表：ERP/資材報告掃描資料夾', $byUser);
}

/** 本模組自己的設定值寫入 system_parameters(TYPE_ID_CTRL) */
function type_id_ctrl_param_save(PDO $db, string $key, string $value, string $desc, string $byUser): void {
    $st = $db->prepare("SELECT 1 FROM system_parameters WHERE param_group='TYPE_ID_CTRL' AND param_key=? LIMIT 1");
    $st->execute([$key]);
    if ($st->fetchColumn()) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW()
                       WHERE param_group='TYPE_ID_CTRL' AND param_key=?")->execute([$value, $byUser, $key]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
                       VALUES ('TYPE_ID_CTRL',?,?,?,?,NOW())")->execute([$key, $value, $desc, $byUser]);
    }
}

/**
 * 此料號的 ERP/資材報告檔案，依標籤設定轉成項目列（每個標籤只留最新一份）。
 * 以 glob 依 BOM 名稱前綴撈（該資料夾近 6000 個檔，不整個 scandir）。
 */
function type_id_ctrl_fetch_bom_files_for_part(PDO $db, int $dsPk): array {
    if (!$dsPk) return [];
    $map = type_id_ctrl_bom_tag_map_get($db);
    if (!$map) return [];                       // 一個標籤都沒設定列入＝這個來源整個關閉

    try {
        $st = $db->prepare("SELECT DISTINCT bom FROM bom WHERE d_setting_id=? AND bom<>''");
        $st->execute([$dsPk]);
        $boms = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
    if (!$boms) return [];

    $dirUtf8 = type_id_ctrl_bom_file_dir($db);
    $dirFs   = type_id_ctrl_fs_path($dirUtf8);
    if (!is_dir($dirFs)) return [];
    $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

    $best = [];   // suffix => 該標籤目前最新的一份
    foreach ($boms as $bom) {
        $bom = trim((string)$bom);
        if ($bom === '') continue;
        foreach (glob($dirFs . $bom . '*') ?: [] as $full) {
            if (!is_file($full)) continue;
            $nameUtf8 = $isWin ? mb_convert_encoding(basename($full), 'UTF-8', 'Big5') : basename($full);
            foreach ($map as $suffix => $cfg) {
                // 比照 part_viewer：BOM名稱+後綴 開頭，且後綴後面不可再接英數字（-T 不可誤中 -TR）
                $head = $bom . $suffix;
                if (stripos($nameUtf8, $head) !== 0) continue;
                $after = substr($nameUtf8, strlen($head));
                if ($after !== '' && !preg_match('/^[^a-zA-Z0-9]/', $after)) continue;
                $mtime = (int)filemtime($full);
                if (!isset($best[$suffix]) || $mtime > $best[$suffix]['mtime']) {
                    $best[$suffix] = ['mtime'=>$mtime, 'name'=>$nameUtf8, 'cfg'=>$cfg];
                }
            }
        }
    }

    $out = [];
    foreach ($best as $suffix => $b) {
        $out[] = [
            'source'      => 'bomfile',
            'attach_id'   => 0,
            'ds_pk'       => $dsPk,
            'file_name'   => $b['name'],
            'doc_name'    => $b['name'],
            'doc_date'    => date('Y-m-d', $b['mtime']),
            'categories'  => [$b['cfg']['item_name']],
            'need_process'=> false,
            'origin_process' => null,
            'force_type'  => $b['cfg']['item_type'],
            'bom_tag'     => $suffix,
        ];
    }
    return $out;
}

/**
 * 掃描「應該要有、但一筆型態識別文件管制表都還沒建立」的料號（2026-08-12 使用者要求，不想每次都要
 * 自己手動打料號）。來源有兩種，同一份清單一起列出並各自標示（2026-08-19 使用者要求加入 PFMEA）：
 *   ext   ＝外來文件清單（含管理員勾選納入的廠內圖面標籤）裡有附件的料號
 *   pfmea ＝PFMEA 潛在失效模式及效應分析（pfmea_doc）已建檔的料號
 * PFMEA 來的料號可能一份外來文件附件都沒有，建立出來會是空白清單——那正是預期行為，使用者接著
 * 用本頁的「上傳檔案」把資料補上去。回傳 [d_id, part_no, customer_name, ext_count, pfmea_count,
 * sources, source_label]，供逐筆勾選批次建立用。
 */
function type_id_ctrl_find_missing_parts(PDO $db): array {
    $existing = $db->query("SELECT DISTINCT part_d_id FROM type_id_ctrl_doc WHERE is_deleted=0 AND part_d_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $existingSet = array_flip(array_map('intval', $existing));

    $counts = [];
    $add = function (array $rows) use (&$counts) {
        foreach ($rows as $r) {
            $id = (int)($r['d_id'] ?? 0);
            if (!$id) continue;
            $counts[$id] = ($counts[$id] ?? 0) + (int)$r['c'];
        }
    };

    // ── 來源一：外來文件清單／廠內圖面標籤的附件 ──────────────────────────
    $cats = $db->query("SELECT id FROM quotation_file_categories WHERE is_external_doc=1 OR type_id_ctrl_include=1")->fetchAll(PDO::FETCH_COLUMN);
    if ($cats) {
        $catCond = function (string $col, string $singleCol = '') use ($cats): string {
            $parts = [];
            foreach ($cats as $cid) $parts[] = "FIND_IN_SET($cid, REPLACE(COALESCE($col,''),' ',''))";
            if ($singleCol !== '') $parts[] = "$singleCol IN (" . implode(',', $cats) . ")";
            return '(' . implode(' OR ', $parts) . ')';
        };

        $add($db->query("SELECT pa.d_id, COUNT(*) c FROM part_attachments pa
                          WHERE pa.deleted_at IS NULL AND " . $catCond('pa.category_ids') . " GROUP BY pa.d_id")->fetchAll(PDO::FETCH_ASSOC));

        $add($db->query("SELECT qi.d_setting_d_id AS d_id, COUNT(*) c
                          FROM quotation_attachments a
                          JOIN quotation_item qi ON qi.quote_id=(SELECT quote_id FROM quotation_list WHERE quote_no=a.quote_no)
                          WHERE a.status='active' AND a.linked_parts IS NULL AND " . $catCond('a.category_ids', 'a.category_id') . "
                          GROUP BY qi.d_setting_d_id")->fetchAll(PDO::FETCH_ASSOC));

        $add($db->query("SELECT ds.d_id AS d_id, COUNT(*) c
                          FROM quotation_attachments a
                          JOIN d_setting ds ON JSON_CONTAINS(a.linked_parts, JSON_QUOTE(ds.D_Setting_Id))
                          WHERE a.status='active' AND a.linked_parts IS NOT NULL AND " . $catCond('a.category_ids', 'a.category_id') . "
                          GROUP BY ds.d_id")->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── 來源二：PFMEA 已建檔的料號（可能完全沒有附件，照樣要列進建議名單）──────
    // PFMEA 模組若尚未建表就當作沒有這個來源，不能讓本掃描整個失敗。
    $pfmeaCounts = [];
    try {
        foreach ($db->query("SELECT part_d_id AS d_id, COUNT(*) c FROM pfmea_doc
                              WHERE is_deleted=0 AND part_d_id IS NOT NULL GROUP BY part_d_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['d_id'];
            if ($id) $pfmeaCounts[$id] = (int)$r['c'];
        }
    } catch (Throwable $e) { $pfmeaCounts = []; }

    // ── 來源三：有專案(2-GM-02)但還沒建管制表的料號（2026-08-20 新增，使用者要求與既有偵測合併）──
    // 專案模組不可用時只是少一個來源，絕不能讓整個掃描失敗。
    $projectMap = [];
    try {
        require_once __DIR__ . '/project_lib.php';
        prj_ensure_schema($db);
        foreach (prj_missing_for($db, 'type_id') as $r) {
            $projectMap[(int)$r['ds_pk']] = $r['project_no'] . ' ' . $r['project_name'];
        }
    } catch (Throwable $e) { $projectMap = []; }

    $allIds = array_unique(array_merge(array_keys($counts), array_keys($pfmeaCounts), array_keys($projectMap)));
    $missingIds = array_values(array_filter($allIds, function ($id) use ($existingSet) { return !isset($existingSet[$id]); }));
    if (!$missingIds) return [];

    $in = implode(',', array_map('intval', $missingIds));
    $rows = $db->query("SELECT ds.d_id, ds.D_Setting_Id AS part_no, COALESCE(cl.customer,'') AS customer_name
                         FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id=ds.Customer_Id
                         WHERE ds.d_id IN ($in) ORDER BY ds.D_Setting_Id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $id = (int)$r['d_id'];
        $r['ext_count']   = $counts[$id] ?? 0;
        $r['pfmea_count'] = $pfmeaCounts[$id] ?? 0;
        $r['project_ref'] = $projectMap[$id] ?? '';
        $hasExt = $r['ext_count'] > 0; $hasPf = $r['pfmea_count'] > 0; $hasPrj = $r['project_ref'] !== '';
        $srcs = [];
        if ($hasExt) $srcs[] = 'ext';
        if ($hasPf)  $srcs[] = 'pfmea';
        if ($hasPrj) $srcs[] = 'project';
        // sources 舊值 both/pfmea/ext 有既有前端在比對，維持相容：只有專案來源時才回 'project'
        $r['sources'] = ($hasExt && $hasPf) ? 'both' : ($hasPf ? 'pfmea' : ($hasExt ? 'ext' : 'project'));
        $r['source_list'] = implode(',', $srcs);
        $labels = [];
        if ($hasExt) $labels[] = '外來文件';
        if ($hasPf)  $labels[] = 'PFMEA';
        if ($hasPrj) $labels[] = '專案 ' . $r['project_ref'];
        $r['source_label'] = implode('＋', $labels);
    }
    unset($r);
    return $rows;
}

/** PFMEA 模組是否已建表（未安裝時本頁的 PFMEA 欄位/篩選一律當作沒有，不可讓查詢整個失敗） */
function type_id_ctrl_pfmea_table_exists(PDO $db): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $db->query("SELECT 1 FROM pfmea_doc LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * 本頁「上傳檔案」可選的附件類別：只列出會被本模組同步進項目列的類別（外來文件清單標籤
 * is_external_doc=1，或管理員勾選納入的廠內圖面標籤 type_id_ctrl_include=1）——挑到別的類別
 * 會發生「傳了卻不會出現在清單上」，所以候選一開始就只給這些。
 * need_issue_date=1 者屬「自家出的圖」，發行章日期必填（判準見 ai-rules/15）。
 */
function type_id_ctrl_upload_categories(PDO $db): array {
    $rows = $db->query("SELECT id, category_name,
                               COALESCE(NULLIF(external_doc_name,''), category_name) AS disp,
                               COALESCE(is_own_drawing,0) AS is_own_drawing
                          FROM quotation_file_categories
                         WHERE is_external_doc=1 OR type_id_ctrl_include=1
                         ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'   => (int)$r['id'],
            'name' => $r['disp'],
            'raw_name' => $r['category_name'],
            'need_issue_date' => (int)$r['is_own_drawing'] === 1 ? 1 : 0,
        ];
    }
    return $out;
}

/** 依類別名稱猜測型態類別代碼（僅供自動同步預設值，使用者仍可手動修改） */
function type_id_ctrl_guess_type(array $categoryNames): string {
    $joined = implode(' ', $categoryNames);
    if (mb_strpos($joined, '夾') !== false || mb_strpos($joined, '治具') !== false) return 'jig';
    if (mb_strpos($joined, '報告') !== false || mb_strpos($joined, '檢驗') !== false || mb_strpos($joined, '報表') !== false) return 'report';
    if (mb_strpos($joined, '圖') !== false) return 'drawing';
    return 'other';
}

/**
 * 此料號目前的製程候選清單（來源：訂單追蹤 order_track + 報價單 quotation_item_process_map），
 * 依文字去重，每個製程字串各留最新一筆單號/日期供參考（使用者 2026-08-12 要求製程來源要含報價單）。
 */
function type_id_ctrl_process_candidates(PDO $db, int $dsPk): array {
    $out = [];
    $st = $db->prepare("SELECT Processing_items AS process, Order_oo AS ref_no, Order_date AS ref_date, '訂單' AS ref_kind
                         FROM order_track WHERE d_id_ID=? AND Processing_items IS NOT NULL AND Processing_items<>''
                         ORDER BY Order_date DESC");
    $st->execute([$dsPk]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { if (!isset($out[$r['process']])) $out[$r['process']] = $r; }

    $st = $db->prepare("SELECT GROUP_CONCAT(pn.ProcessName ORDER BY pn.ProcessName SEPARATOR '+') AS process,
                                ql.quote_no AS ref_no, ql.quote_date AS ref_date, '報價單' AS ref_kind
                         FROM quotation_item qi
                         JOIN quotation_list ql ON ql.quote_id = qi.quote_id
                         JOIN quotation_item_process_map m ON m.quotation_item_id = qi.item_id
                         JOIN process_no pn ON pn.ProcessNo = m.process_no
                         WHERE qi.d_setting_d_id=?
                         GROUP BY qi.item_id
                         ORDER BY ql.quote_date DESC");
    $st->execute([$dsPk]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['process'] === null || $r['process'] === '') continue;
        if (!isset($out[$r['process']])) $out[$r['process']] = $r;
    }
    return array_values($out);
}

/**
 * 表頭「製程」摘要（2026-08-13 使用者要求修正：不可顯示「共用」字樣，必須是此料號所有相關
 * 報價單/訂單檢附的製程全部合併寫在一起，例如報價單A粗滾+打毛邊、報價單B齒研+雷刻、報價單C全製，
 * 表頭要顯示「粗滾、打毛邊、齒研、雷刻、全製」）。這跟項目列「所屬製程」是兩件事——項目列只在
 * 同一報價單裡此料號有多筆項目時才會標示、其餘留空代表共用，是給人工審閱用的細節欄；表頭要看的是
 * 「這個料號曾經出現過的所有製程」，直接沿用 type_id_ctrl_process_candidates() 同一套「此料號的
 * 訂單/報價紀錄」候選來源，取全部製程字串去重合併，不受項目是否已排除、有無連結等狀態影響。
 */
function type_id_ctrl_process_header_summary(PDO $db, int $dsPk): string {
    $procs = [];
    foreach (type_id_ctrl_process_candidates($db, $dsPk) as $c) {
        $p = trim((string)($c['process'] ?? ''));
        if ($p === '') continue;
        foreach (explode('+', $p) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && !in_array($piece, $procs, true)) $procs[] = $piece;
        }
    }
    return implode('、', $procs);
}

/**
 * 廠內圖面標籤的「顯示名稱」／「需要顯示製程」設定變更後，套用回已經同步進本模組、目前仍連結
 * 該附件的既有項目列（2026-08-12 使用者要求：沒有批次刪除重轉功能，改名要能直接更新舊資料，
 * 不必整批刪除重轉）。只更新「型態項目名稱」與「需要顯示製程」提示旗標，不動使用者可能已手動
 * 調整過的所屬製程／版別文件編號等其他欄位；連結來源已消失、或該附件目前類別已不在自動同步
 * 名單內的列跳過不動（維持原名）。受影響的表頭若原本已「已確認」，一併改回「需重新確認」
 * （比照 type_id_ctrl_sync_part 既有規則）。回傳 [updated_count, affected_docs]。
 */
function type_id_ctrl_refresh_synced_item_names(PDO $db): array {
    $catRows = $db->query("SELECT id, COALESCE(NULLIF(external_doc_name,''), category_name) AS disp,
                                   COALESCE(type_id_ctrl_need_process,0) AS need_process
                            FROM quotation_file_categories WHERE is_external_doc=1 OR type_id_ctrl_include=1")->fetchAll(PDO::FETCH_ASSOC);
    $cats = [];
    foreach ($catRows as $cr) { $cats[(int)$cr['id']] = ['disp'=>$cr['disp'], 'need_process'=>(bool)$cr['need_process']]; }

    $resolveNames = function (?string $categoryIds, $categoryIdSingle) use ($cats): array {
        $names = []; $needProcess = false;
        foreach (array_filter(explode(',', str_replace(' ', '', (string)$categoryIds))) as $cid) {
            if (isset($cats[(int)$cid])) { $names[] = $cats[(int)$cid]['disp']; if ($cats[(int)$cid]['need_process']) $needProcess = true; }
        }
        if (!$names && $categoryIdSingle !== null && $categoryIdSingle !== '' && isset($cats[(int)$categoryIdSingle])) {
            $names[] = $cats[(int)$categoryIdSingle]['disp'];
            if ($cats[(int)$categoryIdSingle]['need_process']) $needProcess = true;
        }
        return [$names, $needProcess];
    };

    $items = $db->query("SELECT id, doc_id, item_name, item_type, need_process_hint, ref_source, ref_attach_id, ref_ds_pk, ref_bom_tag
                          FROM type_id_ctrl_item WHERE is_deleted=0 AND ref_source IS NOT NULL AND ref_attach_id IS NOT NULL")
                ->fetchAll(PDO::FETCH_ASSOC);

    // 2026-08-20 新增的三種來源，名稱同樣要能被設定值改動後套用回既有列
    $formDefs = type_id_ctrl_form_doc_defs($db);
    $bomTagMap = type_id_ctrl_bom_tag_map_get($db);

    $updated = 0; $affectedDocs = [];
    $updSt = $db->prepare("UPDATE type_id_ctrl_item SET item_name=?, need_process_hint=?, updated_at=NOW() WHERE id=?");
    $updBomSt = $db->prepare("UPDATE type_id_ctrl_item SET item_name=?, item_type=?, updated_at=NOW() WHERE id=?");
    $partSt = $db->prepare("SELECT category_ids FROM part_attachments WHERE id=? AND d_id=? AND deleted_at IS NULL");
    $quoteSt = $db->prepare("SELECT category_ids, category_id FROM quotation_attachments WHERE id=? AND status='active'");

    foreach ($items as $it) {
        if ($it['ref_source'] === 'part') {
            $partSt->execute([$it['ref_attach_id'], $it['ref_ds_pk']]);
            $row = $partSt->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue; // 來源已消失，跳過
            [$names, $needProcess] = $resolveNames($row['category_ids'], null);
        } elseif ($it['ref_source'] === 'quote') {
            $quoteSt->execute([$it['ref_attach_id']]);
            $row = $quoteSt->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            [$names, $needProcess] = $resolveNames($row['category_ids'], $row['category_id']);
        } elseif (isset($formDefs[$it['ref_source']])) {
            // 產品開發評估表／PFMEA：名稱跟著該表單綁定的 AS 文件名稱走
            $names = [$formDefs[$it['ref_source']]['item_name']]; $needProcess = false;
        } elseif ($it['ref_source'] === 'bomfile') {
            // ERP/資材報告：名稱與型態類別跟著本模組的標籤設定走（該標籤被取消列入就維持原樣不動）
            $tag = (string)($it['ref_bom_tag'] ?? '');
            if ($tag === '' || !isset($bomTagMap[$tag])) continue;
            $cfg = $bomTagMap[$tag];
            if ($cfg['item_name'] === $it['item_name'] && $cfg['item_type'] === $it['item_type']) continue;
            $updBomSt->execute([$cfg['item_name'], $cfg['item_type'], $it['id']]);
            $updated++;
            $affectedDocs[(int)$it['doc_id']] = true;
            continue;
        } else {
            continue;
        }
        if (!$names) continue; // 目前類別已不在自動同步名單內，維持原名不動
        $newName = $names[0];
        $newHint = $needProcess ? 1 : 0;
        if ($newName === $it['item_name'] && $newHint == (int)$it['need_process_hint']) continue; // 沒變化
        $updSt->execute([$newName, $newHint, $it['id']]);
        $updated++;
        $affectedDocs[(int)$it['doc_id']] = true;
    }

    if ($affectedDocs) {
        $in = implode(',', array_map('intval', array_keys($affectedDocs)));
        $db->exec("UPDATE type_id_ctrl_doc SET review_status='needs_recheck' WHERE id IN ($in) AND review_status='confirmed'");
    }
    return ['updated_count'=>$updated, 'affected_docs'=>count($affectedDocs)];
}

/**
 * 依料號自動產生/同步型態識別文件管制表：每個料號一份(找不到就建立)，把此料號目前所有外來文件
 * 附件同步進項目列（已存在的 ref 不重複新增，已排除/已刪除的也不會被復活）；每一列的「所屬製程」
 * 自動由該文件originating報價項目的製程推導(共用文件留空)；若先前已「已確認」又同步進新項目，
 * 狀態改回「需重新確認」。回傳 [doc_id, is_new, added_count]（2026-08-12 使用者拍板改一料號一份）。
 */
function type_id_ctrl_sync_part(PDO $db, int $dsPk): array {
    $st = $db->prepare("SELECT Customer_Id FROM d_setting WHERE d_id=?");
    $st->execute([$dsPk]);
    $customerId = $st->fetchColumn();
    if ($customerId === false) return ['doc_id'=>0,'is_new'=>false,'added_count'=>0];

    $extRows = type_id_ctrl_fetch_ext_docs_for_part($db, $dsPk);

    $st = $db->prepare("SELECT id, review_status FROM type_id_ctrl_doc WHERE part_d_id=? AND is_deleted=0 ORDER BY id LIMIT 1");
    $st->execute([$dsPk]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    $isNew = !$doc;
    if ($doc) {
        $docId = (int)$doc['id'];
    } else {
        $docNo = type_id_ctrl_next_doc_no($db);
        $st = $db->prepare("INSERT INTO type_id_ctrl_doc (doc_no, customer_id, part_d_id, created_by_name, review_status)
                             VALUES (?,?,?,?,'pending')");
        $st->execute([$docNo, $customerId, $dsPk, '系統自動同步']);
        $docId = (int)$db->lastInsertId();
    }

    $st = $db->prepare("SELECT id, ref_source, ref_attach_id, ref_ds_pk, ref_file_name, ref_bom_tag
                          FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 AND ref_source IS NOT NULL");
    $st->execute([$docId]);
    $existingKeys = []; $bomRowsByTag = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existingKeys[$r['ref_source'] . '|' . $r['ref_attach_id'] . '|' . $r['ref_ds_pk']] = true;
        // ERP/資材報告：同一個標籤永遠只留一列，換新檔案時原地把它指到新檔（不另開一列），
        // 型態識別文件管制表要看的是「目前的型態」，不是歷次報告的清單（2026-08-20 使用者拍板）
        if ($r['ref_source'] === 'bomfile' && $r['ref_bom_tag'] !== null && $r['ref_bom_tag'] !== '') {
            $bomRowsByTag[$r['ref_bom_tag']] = $r;
        }
    }

    $st = $db->prepare("SELECT COALESCE(MAX(seq),0) FROM type_id_ctrl_item WHERE doc_id=?");
    $st->execute([$docId]);
    $seq = (int)$st->fetchColumn();

    $addedCount = 0; $updatedCount = 0;
    foreach ($extRows as $er) {
        // ERP/資材報告：先看這個標籤是不是已經有一列了，有就只把它指到最新的那份檔案
        if (($er['source'] ?? '') === 'bomfile') {
            $tag = (string)($er['bom_tag'] ?? '');
            if ($tag !== '' && isset($bomRowsByTag[$tag])) {
                $row = $bomRowsByTag[$tag];
                if ((string)$row['ref_file_name'] !== (string)$er['file_name']) {
                    $db->prepare("UPDATE type_id_ctrl_item SET ref_file_name=?, updated_at=NOW() WHERE id=?")
                       ->execute([$er['file_name'], $row['id']]);
                    $updatedCount++;
                }
                continue;
            }
        }
        $key = $er['source'] . '|' . $er['attach_id'] . '|' . $er['ds_pk'];
        if ($er['source'] !== 'bomfile' && isset($existingKeys[$key])) continue;
        $seq++;
        // force_type：表單類(其他文件)與 ERP/資材報告(各標籤自己的設定)由來源直接指定型態類別，
        // 只有附件類才需要用類別名稱猜
        $itemType = !empty($er['force_type']) ? $er['force_type'] : type_id_ctrl_guess_type($er['categories'] ?? []);
        $itemName = !empty($er['categories']) ? $er['categories'][0] : $er['doc_name'];
        $originProcess = $er['origin_process'] ?? null;
        $needProcessHint = !empty($er['need_process']) ? 1 : 0;
        $st = $db->prepare("INSERT INTO type_id_ctrl_item (doc_id, seq, item_name, item_type, process_tag, need_process_hint, ref_source, ref_attach_id, ref_ds_pk, ref_file_name, ref_bom_tag)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$docId, $seq, $itemName, $itemType, ($originProcess !== '' ? $originProcess : null), $needProcessHint,
                      $er['source'], $er['attach_id'], $er['ds_pk'], $er['file_name'] ?? null, $er['bom_tag'] ?? null]);
        $addedCount++;
    }

    if (($addedCount > 0 || $updatedCount > 0) && $doc && $doc['review_status'] === 'confirmed') {
        $db->prepare("UPDATE type_id_ctrl_doc SET review_status='needs_recheck' WHERE id=?")->execute([$docId]);
    }
    return ['doc_id'=>$docId, 'is_new'=>$isNew, 'added_count'=>$addedCount, 'updated_count'=>$updatedCount];
}
