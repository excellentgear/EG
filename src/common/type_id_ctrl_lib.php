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
        // 架構改版（2026-08-12 使用者拍板）：原本「一料號一製程一份」造成同一張共用圖面在多份管制表
        // 重複出現，改成「一料號一份」，製程改記在每一列項目上（共用文件留空＝適用全部製程）。
        // process_desc 欄位保留但不再是尋找/建立表頭的鍵值，僅作歷史相容用途，新資料不寫入。
        "ALTER TABLE type_id_ctrl_item ADD COLUMN process_tag VARCHAR(200) NULL COMMENT '所屬製程(空=共用/適用全部製程)，自動由報價項目製程推導，可手動修改或清空' AFTER item_type",
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
function type_id_ctrl_resolve_ref(PDO $db, string $source, int $attachId, int $dsPk): ?array {
    if ($source === 'part') {
        $st = $db->prepare("SELECT COALESCE(NULLIF(pa.original_name,''), pa.filename) AS doc_name,
                                    DATE(pa.uploaded_at) AS doc_date, pa.filename, pa.revision, pa.issue_stamp_date
                             FROM part_attachments pa
                             WHERE pa.id=? AND pa.d_id=? AND pa.deleted_at IS NULL LIMIT 1");
        $st->execute([$attachId, $dsPk]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'doc_name' => ($r['revision'] !== null && $r['revision'] !== '') ? $r['revision'] : $r['doc_name'],
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
            'doc_name' => $r['doc_name'], 'doc_date' => $r['doc_date'],
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
    $cats = $db->query("SELECT id, COALESCE(NULLIF(external_doc_name,''), category_name) AS disp
                         FROM quotation_file_categories WHERE is_external_doc=1 OR type_id_ctrl_include=1")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$cats) return [];
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
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['source'] = 'part'; $rows[] = $r; }

    $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
    $st->execute([$dsPk]);
    $partNo = (string)$st->fetchColumn();
    if ($partNo !== '') {
        // 報價附件：若能對應到「此料號在同一張報價單的報價項目」，用該項目勾選的製程(quotation_item_process_map)
        // 當作 origin_process 自動帶入（可手動修改/清空）；對應不到就是共用文件，維持 NULL 不顯示製程
        // （2026-08-12 使用者要求：相同附件被多份共用時不特定標記某製程）。
        $sql = "SELECT DISTINCT a.id AS attach_id, ? AS ds_pk,
                       COALESCE(NULLIF(a.original_name,''), a.filename) AS doc_name,
                       DATE(a.uploaded_at) AS doc_date, a.category_ids, COALESCE(a.category_id,'') AS category_id_single,
                       (SELECT GROUP_CONCAT(DISTINCT pn.ProcessName ORDER BY pn.ProcessName SEPARATOR '+')
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
        $names = [];
        foreach (array_filter(explode(',', str_replace(' ', '', (string)$r['category_ids']))) as $cid) {
            if (isset($cats[(int)$cid])) $names[] = $cats[(int)$cid];
        }
        if (!$names && $r['category_id_single'] !== '' && isset($cats[(int)$r['category_id_single']])) {
            $names[] = $cats[(int)$r['category_id_single']];
        }
        $r['categories'] = $names;
        unset($r['category_ids'], $r['category_id_single']);
    }
    unset($r);
    return $rows;
}

/**
 * 掃描「外來文件清單中有附件、但一筆型態識別文件管制表都還沒建立」的料號（2026-08-12 使用者要求，
 * 不想每次都要自己手動打料號）。回傳 [d_id, part_no, customer_name, ext_count] 陣列，供批次一鍵建立用。
 */
function type_id_ctrl_find_missing_parts(PDO $db): array {
    $existing = $db->query("SELECT DISTINCT part_d_id FROM type_id_ctrl_doc WHERE is_deleted=0 AND part_d_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $existingSet = array_flip(array_map('intval', $existing));

    $cats = $db->query("SELECT id FROM quotation_file_categories WHERE is_external_doc=1 OR type_id_ctrl_include=1")->fetchAll(PDO::FETCH_COLUMN);
    if (!$cats) return [];
    $catCond = function (string $col, string $singleCol = '') use ($cats): string {
        $parts = [];
        foreach ($cats as $cid) $parts[] = "FIND_IN_SET($cid, REPLACE(COALESCE($col,''),' ',''))";
        if ($singleCol !== '') $parts[] = "$singleCol IN (" . implode(',', $cats) . ")";
        return '(' . implode(' OR ', $parts) . ')';
    };

    $counts = [];
    $add = function (array $rows) use (&$counts) {
        foreach ($rows as $r) {
            $id = (int)($r['d_id'] ?? 0);
            if (!$id) continue;
            $counts[$id] = ($counts[$id] ?? 0) + (int)$r['c'];
        }
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

    $missingIds = array_values(array_filter(array_keys($counts), function ($id) use ($existingSet) { return !isset($existingSet[$id]); }));
    if (!$missingIds) return [];

    $in = implode(',', array_map('intval', $missingIds));
    $rows = $db->query("SELECT ds.d_id, ds.D_Setting_Id AS part_no, COALESCE(cl.customer,'') AS customer_name
                         FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id=ds.Customer_Id
                         WHERE ds.d_id IN ($in) ORDER BY ds.D_Setting_Id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['ext_count'] = $counts[(int)$r['d_id']] ?? 0; }
    unset($r);
    return $rows;
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

    $st = $db->prepare("SELECT ref_source, ref_attach_id, ref_ds_pk FROM type_id_ctrl_item WHERE doc_id=? AND ref_source IS NOT NULL");
    $st->execute([$docId]);
    $existingKeys = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $existingKeys[$r['ref_source'] . '|' . $r['ref_attach_id'] . '|' . $r['ref_ds_pk']] = true;

    $st = $db->prepare("SELECT COALESCE(MAX(seq),0) FROM type_id_ctrl_item WHERE doc_id=?");
    $st->execute([$docId]);
    $seq = (int)$st->fetchColumn();

    $addedCount = 0;
    foreach ($extRows as $er) {
        $key = $er['source'] . '|' . $er['attach_id'] . '|' . $er['ds_pk'];
        if (isset($existingKeys[$key])) continue;
        $seq++;
        $guessType = type_id_ctrl_guess_type($er['categories'] ?? []);
        $itemName = !empty($er['categories']) ? $er['categories'][0] : $er['doc_name'];
        $originProcess = $er['origin_process'] ?? null;
        $st = $db->prepare("INSERT INTO type_id_ctrl_item (doc_id, seq, item_name, item_type, process_tag, ref_source, ref_attach_id, ref_ds_pk)
                             VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$docId, $seq, $itemName, $guessType, ($originProcess !== '' ? $originProcess : null), $er['source'], $er['attach_id'], $er['ds_pk']]);
        $addedCount++;
    }

    if ($addedCount > 0 && $doc && $doc['review_status'] === 'confirmed') {
        $db->prepare("UPDATE type_id_ctrl_doc SET review_status='needs_recheck' WHERE id=?")->execute([$docId]);
    }
    return ['doc_id'=>$docId, 'is_new'=>$isNew, 'added_count'=>$addedCount];
}
