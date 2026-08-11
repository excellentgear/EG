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
 */
function type_id_ctrl_resolve_ref(PDO $db, string $source, int $attachId, int $dsPk): ?array {
    if ($source === 'part') {
        $st = $db->prepare("SELECT COALESCE(NULLIF(pa.original_name,''), pa.filename) AS doc_name,
                                    DATE(pa.uploaded_at) AS doc_date, pa.filename
                             FROM part_attachments pa
                             WHERE pa.id=? AND pa.d_id=? AND pa.deleted_at IS NULL LIMIT 1");
        $st->execute([$attachId, $dsPk]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'doc_name' => $r['doc_name'], 'doc_date' => $r['doc_date'],
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
