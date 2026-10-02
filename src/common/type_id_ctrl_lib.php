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

// BOM/ERP 資料夾路徑、檔名編碼、檔名後綴標籤命中判定的唯一實作（本檔多處使用，
// 放在檔案層級載入，不再散在各函式內 require——漏一處就是那支函式突然找不到函式）
require_once __DIR__ . '/bom_dir_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';   // 顯示用日期一律 YYYY.MM.DD（ai-rules/20）
require_once __DIR__ . '/sopsip_lib.php';     // ss_doc/ss_ver 存取：SOP／SIP 型態識別文件來源（2026-09-24）

/* 型態類別與連結來源的顯示標籤 —— 唯一登記處。
   原本寫在 src/store/ConfigIdDoc_API.php，但 2026-09-22 起內部稽核的「產品型態稽核表」
   也要照同一份標籤把管制表的項目列帶進查檢表，兩邊各留一份遲早走鐘（鐵律4），故收斂到共用庫；
   ConfigIdDoc_API 原本的 TYPE_LABELS／SOURCE_LABELS 改成指向這裡，既有呼叫端一行都不必改。 */
const TIC_TYPE_LABELS   = ['drawing' => '圖面', 'jig' => '治夾具', 'report' => '報告', 'other' => '其他文件'];
const TIC_SOURCE_LABELS = ['part' => '外來文件', 'quote' => '外來文件', 'dev_eval' => '產品開發評估表',
                           'pfmea' => 'PFMEA', 'bomfile' => 'ERP/資材報告', 'sopsip' => 'SOP／SIP'];

/**
 * 連結來源標籤——sopsip 一律依 kind 細分成「SOP」／「SIP」，不要籠統顯示「SOP／SIP」
 * （2026-10-01 使用者要求：SOP 跟 SIP 要分開，混在同一個字樣會分不出連結到的到底是哪一種）。
 * 唯一登記處：畫面徽章(type_id_ctrl_item_view)、「選外來文件」彈窗分組(type_id_ctrl_candidate_group)
 * 與 fetch_ext_for_part 一律呼叫這裡，不各自寫一份判斷。
 */
function type_id_ctrl_ref_source_label(string $source, ?string $kind = null): string {
    if ($source === 'sopsip') return ($kind === 'sip') ? 'SIP' : 'SOP';
    return TIC_SOURCE_LABELS[$source] ?? '自動帶入';
}

/**
 * 「選外來文件」彈窗的分組標題——刻意不沿用 TIC_SOURCE_LABELS：那份是「已連結之後」要顯示的
 * 徽章文字，part/quote 兩種故意都寫成籠統的「外來文件」；但在挑選階段使用者要先分得出「這是料號
 * 本身的附件、還是報價單上的附件」才挑得準（2026-10-01 使用者回報「外來文件選單好亂」），
 * 所以這裡另外給一份更細的分組名稱，SOP／SIP 仍與 ref_source_label 用同一套 kind 判斷規則。
 */
function type_id_ctrl_candidate_group(string $source, ?string $kind = null): string {
    if ($source === 'sopsip') return ($kind === 'sip') ? 'SIP' : 'SOP';
    $map = ['part' => '料號附件', 'quote' => '報價附件', 'dev_eval' => '產品開發評估表',
            'pfmea' => 'PFMEA', 'bomfile' => 'ERP/資材報告'];
    return $map[$source] ?? $source;
}

/** 組出單筆項目列的顯示資料（即時解析連結，不快照）。原 ConfigIdDoc_API::buildItemView() */
function type_id_ctrl_item_view(PDO $db, array $it): array {
    $linked = null;
    // bomfile 來源沒有 attach_id，識別鍵是檔名（見本檔 type_id_ctrl_resolve_ref 的說明）
    $hasRef = $it['ref_source'] && ($it['ref_attach_id'] || ($it['ref_source'] === 'bomfile' && !empty($it['ref_file_name'])));
    if ($hasRef) {
        $linked = type_id_ctrl_resolve_ref($db, $it['ref_source'], (int)$it['ref_attach_id'], (int)$it['ref_ds_pk'], $it['ref_file_name'] ?? null, (int)($it['ref_cat_id'] ?? 0));
    }
    $printDocNo = ($linked && !empty($linked['doc_no_is_filename'])) ? '' : ($linked ? $linked['doc_name'] : $it['manual_doc_no']);
    // 檔名退回顯示、列印本應空白的情況：若這份文件填了發行章日期（自家出的圖），改印
    // 「發行章 YYYY.MM.DD」，總比整格空白看不出任何依據來得清楚。
    if ($printDocNo === '' && $linked && !empty($linked['issue_stamp_date'])) {
        $printDocNo = '發行章 ' . eg_fmt_date($linked['issue_stamp_date']);
    }
    return [
        'id' => (int)$it['id'],
        'seq' => (int)$it['seq'],
        'item_name' => $it['item_name'],
        'item_type' => $it['item_type'],
        'item_type_label' => TIC_TYPE_LABELS[$it['item_type']] ?? '其他文件',
        'process_tag' => $it['process_tag'] ?? null,
        'need_process_hint' => !empty($it['need_process_hint']),
        'is_linked' => $linked !== null,
        'is_excluded' => !empty($it['is_excluded']),
        'ref_source' => $it['ref_source'],
        'ref_source_label' => $it['ref_source'] ? type_id_ctrl_ref_source_label($it['ref_source'], $linked['kind'] ?? null) : '',
        'ref_attach_id' => $it['ref_attach_id'] ? (int)$it['ref_attach_id'] : null,
        'ref_ds_pk' => $it['ref_ds_pk'] ? (int)$it['ref_ds_pk'] : null,
        'ref_file_name' => $it['ref_file_name'] ?? null,
        'ref_bom_tag' => $it['ref_bom_tag'] ?? null,
        'ref_cat_id' => isset($it['ref_cat_id']) && $it['ref_cat_id'] !== null ? (int)$it['ref_cat_id'] : ($linked['cat_id'] ?? null),
        // 重複確認的狀態要回給前端，否則重新載入後「不是重複，各自保留」的那一組又會被判成待確認
        'dup_ignore' => !empty($it['dup_ignore']) ? 1 : 0,
        'superseded_by' => isset($it['superseded_by']) && $it['superseded_by'] !== null ? (int)$it['superseded_by'] : null,
        'ver_count' => $linked['ver_count'] ?? null,
        // 這一份有沒有真正的發行章日期（沒有＝日期是退回上傳日，日期檢核的提示要講清楚）
        'has_issue_stamp' => !empty($linked['first_has_stamp']),
        'ref_broken' => ($hasRef && $linked === null), // 曾連結但來源已消失
        'effective_date' => $linked ? $linked['doc_date'] : $it['manual_effective_date'],
        'doc_no_text' => $linked ? $linked['doc_name'] : $it['manual_doc_no'],
        // 列印版：連結列若沒有真正版次、退回顯示檔名時，檔名不算真正的「版別／文件編號」，
        // 一般不印（畫面上仍用 doc_no_text 顯示檔名以利辨識；手動輸入列一律視為真實文件編號）；
        // 有發行章日期時改印「發行章 YYYY.MM.DD」，見上方 $printDocNo 的計算
        'print_doc_no' => $printDocNo,
        'file_url' => $linked ? $linked['file_url'] : null,
        // 2026-09-24 使用者要求：已確認過的連結項目，來源內容事後被改了要偵測得出來——
        // 拿「上次確認時存的快照」跟「現在即時解析出的值」比對，不同就是內容已變更。
        // 快照是 NULL 的情況（從沒被確認過、或手動輸入列）一律不算變更。
        'confirmed_ref_snapshot' => $it['confirmed_ref_snapshot'] ?? null,
        'content_changed' => ($linked !== null && !empty($it['confirmed_ref_snapshot'])
                              && (string)$it['confirmed_ref_snapshot'] !== (string)$linked['doc_name']),
    ];
}


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

    // 修訂履歷（2026-10-01 使用者要求）：每一個項目列各自有「每次的修訂日期＋修訂後版別」。
    // 一列一次修訂，筆數不固定（原圖可能 0 次、加工圖 3 次），所以獨立成子表而不是在項目列上
    // 開固定幾組欄位（紙本那種「修訂1/2/3」的橫式欄位，超過就印不出來、大半還是空格）。
    // auto_key：由來源自動推導出來的那幾筆（料號附件的發行章日期＋版次、SOP／SIP 的 ss_ver）
    // 帶一個穩定識別鍵，重跑同步時才不會重複長出同一筆；人工自己加的列 auto_key 為 NULL。
    // 使用者刪掉自動帶入的那一筆時一律軟刪除並保留 auto_key，否則下次合併又會被加回來。
    $db->exec("CREATE TABLE IF NOT EXISTS type_id_ctrl_item_rev (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL COMMENT 'FK type_id_ctrl_item.id',
        seq INT NOT NULL DEFAULT 1 COMMENT '顯示排序(依修訂日期由舊到新)',
        rev_date DATE NULL COMMENT '修訂日期',
        rev_version VARCHAR(50) NULL COMMENT '修訂後版別',
        note VARCHAR(200) NULL COMMENT '修訂說明(選填)',
        auto_key VARCHAR(120) NULL COMMENT '由來源自動帶入時的識別鍵；人工新增為NULL',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        KEY idx_item (item_id),
        KEY idx_auto (item_id, auto_key)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='型態識別文件管制表-項目列修訂履歷'");

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
        // 2026-09-24 使用者要求：已確認的連結項目，來源內容（版別/文件編號）事後變了要偵測得到。
        // 存的是「上次確認當下」即時解析出來的 doc_no_text 快照，只在存檔/批次確認/批次更新時
        // 寫入（type_id_ctrl_snapshot_confirm），平時查詢一律拿它跟「現在」即時解析的值比對，
        // 不一致就是「內容已變更」。只對有連結來源的列有意義，手動輸入的列永遠是 NULL。
        // 2026-10-02：料號附件改成「一種文件一列」（同料號同類別的歷次上傳收斂成一列），
        // 識別鍵因此由附件 id 改為附件類別；ref_attach_id 仍留著記目前指到哪一份（現行版）。
        // 2026-10-02：同一種文件出現好幾份時由使用者確認（見 type_id_ctrl_dup_groups 說明）
        "ALTER TABLE type_id_ctrl_item ADD COLUMN superseded_by INT NULL COMMENT '這一列是哪一列的舊版(指向現行版的item id)；有值者不再是獨立項目列，改以修訂履歷呈現' AFTER is_excluded",
        "ALTER TABLE type_id_ctrl_item ADD COLUMN dup_ignore TINYINT(1) NOT NULL DEFAULT 0 COMMENT '使用者確認過「這幾份不是重複，各自保留」，之後不再提示' AFTER superseded_by",
        "ALTER TABLE type_id_ctrl_item ADD COLUMN ref_cat_id INT NULL COMMENT '連結料號附件時的附件類別id(ref_source=part專用)；同料號同類別只會有一列，改版時原地指到新檔' AFTER ref_bom_tag",
        "ALTER TABLE type_id_ctrl_item ADD COLUMN confirmed_ref_snapshot VARCHAR(255) NULL COMMENT '上次確認時，該連結來源即時解析出的版別/文件編號快照，供事後比對內容是否變更' AFTER ref_bom_tag",
    ] as $alter) {
        try { $db->exec($alter); } catch (Throwable $e) {}
    }

    foreach ([['type_id_ctrl_view','型態文件檢閱'],['type_id_ctrl_edit','型態文件登錄'],['type_id_ctrl_admin','型態文件管理員'],
              ['type_id_ctrl_batch_update','批次更新權限（管理員自行指派給需要的角色/人員）']] as $r) {
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
                        JOIN position_roles pr ON pr.position_id=m.position_id AND (pr.department_id=0 OR pr.department_id=m.department_id)
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='type_id_ctrl' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function type_id_ctrl_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false,'canBatchUpdate'=>false];
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
    // 批次更新是獨立授權，不是 canEdit 的自然延伸——管理員自行指派給需要的人（2026-09-24 使用者要求
    // 「管理員可以設定哪些角色有此功能」），canAdmin 一律涵蓋（管理員本來就什麼都能做）。
    $canBatchUpdate = $canAdmin || type_id_ctrl_has_role($db, $uid, ['type_id_ctrl_batch_update']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView,'canBatchUpdate'=>$canBatchUpdate];
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

/** 製表人圖章要套用的模板 id（0＝未設定，消費端退回 EGStamp 預設回墨印並套 91px，ai-rules/18 鐵則6） */
function type_id_ctrl_stamp_tpl_id(PDO $db): int {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='TYPE_ID_CTRL' AND param_key='stamp_tpl_id' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v === false) return 0;
        $d = json_decode((string)$v, true);
        return (int)(is_numeric($d) ? $d : (is_numeric($v) ? $v : 0));
    } catch (Throwable $e) { return 0; }
}

/** 圖章模板內容（停用或查無回 null） */
function type_id_ctrl_stamp_tpl(PDO $db, int $tplId): ?array {
    if (!$tplId) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$tplId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}

/** 圖章模板下拉清單（設定跳窗用） */
function type_id_ctrl_stamp_tpl_options(PDO $db): array {
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name FROM stamp_template p
                           LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 儲存製表人圖章模板 id（0＝取消，用系統預設印章） */
function type_id_ctrl_stamp_tpl_save(PDO $db, int $tplId, string $uname): void {
    $ex = $db->prepare("SELECT id FROM system_parameters WHERE param_group='TYPE_ID_CTRL' AND param_key='stamp_tpl_id' LIMIT 1");
    $ex->execute();
    $rid = $ex->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([(string)$tplId, $uname, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by) VALUES ('TYPE_ID_CTRL','stamp_tpl_id',?,?,?)")
           ->execute([(string)$tplId, '型態識別文件管制表列印：製表人圖章模板 id（0=用系統預設印章）', $uname]);
    }
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
function type_id_ctrl_resolve_ref(PDO $db, string $source, int $attachId, int $dsPk, ?string $fileName = null, int $catId = 0): ?array {
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
    // SOP／SIP（views/QA/sop_sip.php，2026-09-24 使用者要求）：「版別／文件編號」欄一律組成
    // 「SOP/SIP＋通用/專用＋文件名稱＋版別」（使用者指定格式），是真正的文件識別內容，
    // doc_no_is_filename=false（列印會印出來）。ref_attach_id 存的是 ss_doc.doc_id，
    // 版次即時解析現行版（ss_current_ver），文件事後改版／改綁定客戶會自動跟著變，不快照。
    if ($source === 'sopsip') {
        $doc = ss_doc_get($db, $attachId);
        if (!$doc) return null;
        $ver = ss_current_ver($db, $attachId);
        $tab = ss_kinds()[$doc['kind']]['tab'] ?? 'sop';
        return [
            'doc_name' => type_id_ctrl_sopsip_disp_name($doc, $ver),
            'doc_no_is_filename' => false,
            'doc_date' => $ver['form_date'] ?? null,
            'kind' => $doc['kind'],   // 'process'(SOP)／'sip'(SIP)，供 ref_source_label 細分顯示用
            'file_url' => '../QA/sop_sip.php?tab=' . $tab . '&kw=' . rawurlencode((string)$doc['title']),
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
        // 2026-10-02 起料號附件以「文件家族」（同料號同附件類別）為單位：版別取現行版、
        // 型態制定日期取家族裡最早一次發行（使用者拍板）。舊資料沒有 ref_cat_id 時由附件回推。
        if ($catId <= 0 && $attachId > 0) $catId = type_id_ctrl_cat_of_attach($db, $dsPk, $attachId);
        $fam = type_id_ctrl_part_families($db, $dsPk)[$catId] ?? null;
        if (!$fam) return null;                       // 這個料號已經沒有這種文件了＝來源已消失
        $cur = $fam['current'];
        // 版別取用順序（2026-10-02 使用者：「加工圖是採用發行日做為版別」）：
        //   ①有填版次就用版次 ②沒版次但有發行章日期 → 發行日就是版別（自家出的圖多半這樣管）
        //   ③兩者都沒有才退回檔名充當畫面辨識用，檔名不是真正的版別故列印不印（2026-08-12 既有規則）
        $verText = type_id_ctrl_version_text($cur);
        return [
            'doc_name' => $verText !== '' ? $verText : $cur['doc_name'],
            'doc_no_is_filename' => ($verText === ''),
            'doc_date' => $fam['first']['_date'],      // ＝型態制定日期：最早一次發行，不隨改版往後跳
            // 「自家出的圖」(如加工圖) 多半沒填版次，退回檔名充當畫面顯示，但列印時檔名不算真正的
            // 版別/文件編號故印空白；有發行章日期時改印「發行章 YYYY.MM.DD」取代空白
            // （2026-09-24 使用者要求：列印看不到任何依據，加工圖那一列整格空白）。
            'issue_stamp_date' => ($cur['issue_stamp_date'] !== null && $cur['issue_stamp_date'] !== '') ? $cur['issue_stamp_date'] : null,
            'cat_id' => $catId,
            // 制定日期取的是第一版，所以「這個日期是不是只有上傳日」要看第一版（不是現行版）
            'first_has_stamp' => !empty($fam['first']['issue_stamp_date']),
            'cur_attach_id' => (int)$cur['attach_id'],
            'ver_count' => count($fam['versions']),
            'file_url' => '../../src/store/Part_Attachment_API.php?action=download&id=' . (int)$cur['attach_id'],
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
                           type_id_ctrl_fetch_bom_files_for_part($db, $dsPk),
                           type_id_ctrl_fetch_sopsip_for_part($db, $dsPk));
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

    // 料號附件：2026-10-02 起「一種文件一列」——同一料號同一附件類別的歷次上傳收斂成一個家族，
    // 這裡每個家族只回一列（指向現行版），制定日期取家族最早一次發行，歷次上傳走修訂履歷。
    // 無製程資訊(本來就與特定製程無關的共用文件，如原圖)，origin_process 一律 NULL。
    foreach (type_id_ctrl_part_families($db, $dsPk) as $cid => $fam) {
        $cur = $fam['current'];
        $rows[] = [
            'attach_id' => (int)$cur['attach_id'],          // 現行版（改版後同步會原地指到新的那份）
            'ds_pk' => $dsPk,
            'filename' => $cur['filename'],
            // 版別優先（版次→發行日），都沒有才退回檔名——與 resolve_ref 同一套規則，
            // 否則「新增管制表」還沒存檔時看到的是檔名、存檔後又變成版別，同一份文件兩種顯示
            'doc_name' => (type_id_ctrl_version_text($cur) !== '') ? type_id_ctrl_version_text($cur) : $cur['doc_name'],
            'doc_date' => $fam['first']['_date'],           // 制定日期＝最早一次發行
            'cat_id' => (int)$cid,
            'categories' => [$fam['disp']],
            'need_process' => $fam['need_process'],
            'origin_process' => null,
            'source' => 'part',
        ];
    }

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
                /* 尚待確認的匯入報價單（pending_review=1）還不是正式報價單，其附件不列入外來文件 */
                WHERE a.status='active'
                  AND EXISTS (SELECT 1 FROM quotation_list qlp WHERE qlp.quote_no=a.quote_no AND qlp.pending_review=0)
                  AND " . $catCond('a.category_ids', 'a.category_id') . "
                  AND ((a.linked_parts IS NULL AND qi.d_setting_d_id = ?)
                       OR (a.linked_parts IS NOT NULL AND JSON_CONTAINS(a.linked_parts, JSON_QUOTE(?))))";
        $st = $db->prepare($sql); $st->execute([$dsPk, $dsPk, $dsPk, $partNo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['source'] = 'quote'; $rows[] = $r; }
    }

    foreach ($rows as &$r) {
        if (($r['source'] ?? '') === 'part') continue;   // 料號附件在上面已經以家族為單位組好了
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

    // 本系統內建立的表單（產品開發評估表／PFMEA）、NAS 的 ERP/資材報告檔案（2026-08-20 使用者要求）、
    // SOP／SIP 專用與通用限定客戶（2026-09-24 使用者要求，完全通用的另由 search_ext_doc 動作補入候選）
    return array_merge($rows,
                       type_id_ctrl_fetch_form_docs_for_part($db, $dsPk),
                       type_id_ctrl_fetch_bom_files_for_part($db, $dsPk),
                       type_id_ctrl_fetch_sopsip_for_part($db, $dsPk));
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
    if ($dir === '') $dir = eg_bom_erp_scan_dir_auto();
    $dir = str_replace('\\', '/', $dir);
    if (substr($dir, -1) !== '/') $dir .= '/';
    return $dir;
}

/**
 * UTF-8 路徑 → 實際可用來讀檔的路徑（Windows 的 NAS 目錄含中文，PHP 檔案函式可能吃 Big5）。
 * **不可以無條件轉 Big5**：來源有兩種——設定值（UTF-8）與 eg_bom_erp_scan_dir_auto()
 * （已經是「檔案系統吃得到」的路徑），後者再轉一次就轉壞了，is_dir() 直接 false＝
 * 整區安靜消失也不報錯。故比照 bom_dir_lib 的作法「兩種編碼都試，哪個存在就用哪個」。
 */
function type_id_ctrl_fs_path(string $utf8Path): string {
    if (is_dir($utf8Path) || is_file($utf8Path)) return $utf8Path;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $b5 = @mb_convert_encoding($utf8Path, 'Big5', 'UTF-8');
        if ($b5 !== false && $b5 !== '' && (is_dir($b5) || is_file($b5))) return $b5;
    }
    return $utf8Path;
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

    // 鍵＝「後綴＋份數」：-H 與 -H2 是兩份不同的熱處理報告，各自一列。
    // 併成同一個鍵的話，比較新的那份會把另一份擠掉＝畫面上安靜少一列。
    $best = [];   // 後綴(+份數) => 該份目前最新的檔案
    foreach ($boms as $bom) {
        $bom = trim((string)$bom);
        if ($bom === '') continue;
        foreach (glob($dirFs . $bom . '*') ?: [] as $full) {
            if (!is_file($full)) continue;
            $nameUtf8 = eg_bom_name_utf8(basename($full));
            foreach ($map as $suffix => $cfg) {
                // 命中判定唯一實作在 bom_dir_lib（-H2＝-H 的第二份，見該函式說明）
                $n = eg_bom_tag_seq($nameUtf8, $bom . $suffix);
                if ($n === null) continue;
                $key   = $suffix . ($n > 1 ? $n : '');
                $mtime = (int)filemtime($full);
                if (!isset($best[$key]) || $mtime > $best[$key]['mtime']) {
                    $best[$key] = ['mtime'=>$mtime, 'name'=>$nameUtf8, 'cfg'=>$cfg, 'seq'=>$n];
                }
            }
        }
    }

    $out = [];
    foreach ($best as $tagKey => $b) {
        $out[] = [
            'source'      => 'bomfile',
            'attach_id'   => 0,
            'ds_pk'       => $dsPk,
            'file_name'   => $b['name'],
            'doc_name'    => $b['name'],
            'doc_date'    => date('Y-m-d', $b['mtime']),
            'categories'  => [eg_bom_tag_label($b['cfg']['item_name'], $b['seq'])],
            'need_process'=> false,
            'origin_process' => null,
            'force_type'  => $b['cfg']['item_type'],
            'bom_tag'     => $tagKey,
        ];
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 自動偵測來源四：SOP／SIP（views/QA/sop_sip.php，2026-09-24 使用者要求）
 *   只認 SOP(kind=process)／SIP(kind=sip) 兩種版面——設備操作說明書(kind=equip) 是機台手冊，
 *   內容不隨料號改變，不算「定義這個料號目前狀態」的文件，故不列入。
 *   自動列入只有兩種：①專用——文件綁定此料號(scope=part AND part_d_id=此料號)
 *                     ②通用限定客戶——文件是通用但指定了客戶、且客戶等於此料號的客戶
 *                       (scope=general AND customer_id=此料號的客戶)，
 *   使用者原話：「綁訂此料號之客戶的應該要自動列入」。
 *   完全不限客戶的「通用」文件**不自動列入**，只能透過「選外來文件」手動挑選連結
 *   （使用者原話：「若無綁訂此料號之SOP/SIP 則可指定通用的SOP/SIP列入」）——
 *   見 type_id_ctrl_fetch_sopsip_generic()，由 ConfigIdDoc_API.php 的 search_ext_doc
 *   動作額外併入候選清單，不進自動同步（避免每個料號都被灌入全部通用 SOP/SIP）。
 *   「版別／文件編號」欄由 type_id_ctrl_sopsip_disp_name() 組出使用者指定的顯示格式。
 * ══════════════════════════════════════════════════════════════════════════════ */

/** SOP／SIP 模組是否已建表（未安裝時本來源一律當沒有，不可讓查詢整個失敗） */
function type_id_ctrl_sopsip_table_exists(PDO $db): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $db->query("SELECT 1 FROM ss_doc LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** SOP／SIP 各版面對應的型態項目名稱：優先取該版面綁定的 AS 文件名稱，沒綁定才用版面預設名稱 */
function type_id_ctrl_sopsip_kind_item_name(PDO $db, string $kind): string {
    // 2026-09-24 使用者更正：SOP 的型態項目名稱就是「SOP」、SIP 就是「SIP」，不要跟其他
    // 來源一樣去查綁定的 AS 文件全名（製造製程說明書／標準檢驗指導書）——兩種寫法混在一起
    // 反而讓同一批項目列看起來像是不同種類的文件。$db 參數保留供未來擴充，目前不使用。
    return ['process' => 'SOP', 'sip' => 'SIP'][$kind] ?? strtoupper($kind);
}

/** 組出「版別／文件編號」欄要顯示的字串：SOP/SIP＋通用/專用＋文件名稱＋版別（使用者指定的顯示格式） */
function type_id_ctrl_sopsip_disp_name(array $doc, ?array $ver): string {
    $kindShort = ['process' => 'SOP', 'sip' => 'SIP'][$doc['kind']] ?? strtoupper((string)$doc['kind']);
    $scopeLabel = ((string)$doc['scope'] === 'part') ? '專用' : '通用';
    $verNo = $ver ? trim((string)$ver['ver_no']) : '';
    $parts = [$kindShort, $scopeLabel, trim((string)$doc['title'])];
    if ($verNo !== '') $parts[] = $verNo . '版';
    return implode(' ', array_filter($parts, function ($p) { return $p !== ''; }));
}

/**
 * 此料號自動符合的 SOP／SIP（專用＋通用限定此客戶），組成與外來文件附件相同格式的列，
 * 供自動同步（type_id_ctrl_sync_part）與「選外來文件」手動連結（search_ext_doc）共用。
 */
function type_id_ctrl_fetch_sopsip_for_part(PDO $db, int $dsPk): array {
    if (!$dsPk || !type_id_ctrl_sopsip_table_exists($db)) return [];
    $st = $db->prepare("SELECT Customer_Id FROM d_setting WHERE d_id=?");
    $st->execute([$dsPk]);
    $customerId = trim((string)$st->fetchColumn());

    $cond = "d.scope='part' AND d.part_d_id=?";
    $params = [$dsPk];
    if ($customerId !== '') {
        $cond .= " OR (d.scope='general' AND d.customer_id=?)";
        $params[] = $customerId;
    }
    $sql = "SELECT d.doc_id, d.kind, d.scope, d.title, d.proc_name
             FROM ss_doc d WHERE d.is_deleted=0 AND d.kind IN ('process','sip') AND ($cond)";
    $st = $db->prepare($sql); $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ver = ss_current_ver($db, (int)$r['doc_id']);
        $out[] = [
            'source'         => 'sopsip',
            'attach_id'      => (int)$r['doc_id'],
            'ds_pk'          => $dsPk,
            'file_name'      => null,
            'doc_name'       => type_id_ctrl_sopsip_disp_name($r, $ver),
            'doc_date'       => $ver['form_date'] ?? null,
            'categories'     => [type_id_ctrl_sopsip_kind_item_name($db, $r['kind'])],
            'need_process'   => false,
            'origin_process' => $r['proc_name'] ?: null,
            'force_type'     => 'other',
            'kind'           => $r['kind'],   // 'process'(SOP)／'sip'(SIP)：供選取彈窗分組
            'bound'          => true,         // 自動命中此料號(專用或通用限定此客戶)，彈窗內優先顯示
        ];
    }
    return $out;
}

/**
 * 全部「通用」SOP／SIP（不限客戶），只用於手動挑選（不進自動同步，見本節開頭說明）。
 * $dsPk 只用來把回傳列的 ds_pk 填成目前正在編輯的料號，方便挑選後與其他來源用同一套鍵值比對。
 */
function type_id_ctrl_fetch_sopsip_generic(PDO $db, int $dsPk): array {
    if (!type_id_ctrl_sopsip_table_exists($db)) return [];
    $rows = $db->query("SELECT doc_id, kind, scope, title, proc_name
                         FROM ss_doc WHERE is_deleted=0 AND kind IN ('process','sip') AND scope='general'
                         ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $ver = ss_current_ver($db, (int)$r['doc_id']);
        $out[] = [
            'source'         => 'sopsip',
            'attach_id'      => (int)$r['doc_id'],
            'ds_pk'          => $dsPk,
            'file_name'      => null,
            'doc_name'       => type_id_ctrl_sopsip_disp_name($r, $ver),
            'doc_date'       => $ver['form_date'] ?? null,
            'categories'     => [type_id_ctrl_sopsip_kind_item_name($db, $r['kind'])],
            'need_process'   => false,
            'origin_process' => $r['proc_name'] ?: null,
            'force_type'     => 'other',
            'kind'           => $r['kind'],   // 'process'(SOP)／'sip'(SIP)：供選取彈窗分組
            'bound'          => false,        // 完全不限客戶，彈窗內排在「已自動比對」的候選之後
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

        // 批圖暫存檔（工作檔與其輸出圖）不是正式文件，不列入可用份數（要與實際列出的清單一致）
        require_once __DIR__ . '/imgedit_visibility.php';
        $add($db->query("SELECT pa.d_id, COUNT(*) c FROM part_attachments pa
                          WHERE pa.deleted_at IS NULL AND " . imgedit_sql_not_draft('pa') . "
                            AND " . $catCond('pa.category_ids') . " GROUP BY pa.d_id")->fetchAll(PDO::FETCH_ASSOC));

        $add($db->query("SELECT qi.d_setting_d_id AS d_id, COUNT(*) c
                          FROM quotation_attachments a
                          JOIN quotation_item qi ON qi.quote_id=(SELECT quote_id FROM quotation_list WHERE quote_no=a.quote_no)
                          WHERE a.status='active' AND a.linked_parts IS NULL
                            AND EXISTS (SELECT 1 FROM quotation_list qlp WHERE qlp.quote_no=a.quote_no AND qlp.pending_review=0)
                            AND " . $catCond('a.category_ids', 'a.category_id') . "
                          GROUP BY qi.d_setting_d_id")->fetchAll(PDO::FETCH_ASSOC));

        // 2026-09-24：原本用 JOIN d_setting ON JSON_CONTAINS(...) 讓 MySQL 對 740 筆附件×24000 筆
        // 料號主檔逐一配對比對（約 1770 萬次、每次還要解析 JSON），實測會卡 15~17 分鐘且拖慢全站
        // 其他人的查詢（慢查詢日誌 2026-09-22、2026-09-24 各記錄兩次）。改成：先把「有連結料號」的
        // 附件（本來就只有數百筆）在 PHP 端解開 linked_parts 陣列，彙總成「料號文字→命中次數」，
        // 再用單一 IN(...) 對 d_setting.D_Setting_Id（有索引 idx_dsid）查回 d_id，不掃全表。
        // 同一個料號文字掛在多筆主檔（重複料號）時，比照原本 JOIN 的語意——每一筆主檔都要算到。
        $linkRows = $db->query("SELECT a.linked_parts
                          FROM quotation_attachments a
                          WHERE a.status='active' AND a.linked_parts IS NOT NULL
                            AND EXISTS (SELECT 1 FROM quotation_list qlp WHERE qlp.quote_no=a.quote_no AND qlp.pending_review=0)
                            AND " . $catCond('a.category_ids', 'a.category_id') . "
                          ")->fetchAll(PDO::FETCH_ASSOC);
        $partNoHits = [];
        foreach ($linkRows as $r) {
            $arr = json_decode((string)$r['linked_parts'], true);
            if (!is_array($arr)) continue;
            foreach ($arr as $pn) {
                $pn = trim((string)$pn);
                if ($pn === '') continue;
                $partNoHits[$pn] = ($partNoHits[$pn] ?? 0) + 1;
            }
        }
        if ($partNoHits) {
            $partNos = array_keys($partNoHits);
            $ph = implode(',', array_fill(0, count($partNos), '?'));
            $st = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE D_Setting_Id IN ($ph)");
            $st->execute($partNos);
            $linkedRows2 = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $linkedRows2[] = ['d_id' => $r['d_id'], 'c' => $partNoHits[$r['D_Setting_Id']] ?? 0];
            }
            $add($linkedRows2);
        }
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
            // ref_bom_tag 可能是「後綴＋份數」（-H2＝-H 的第二份），要拆回 -H 才對得到標籤設定，
            // 否則改了標籤名稱時第二份以後的那幾列會安靜地同步不到。整個鍵本身就是已設定的
            // 後綴時優先照用（後綴本身以數字結尾的情況）。
            $tagSeq = 1;
            if ($tag !== '' && !isset($bomTagMap[$tag])) {
                [$tagBase, $tagSeq] = eg_bom_tag_key_parse($tag);
                if (isset($bomTagMap[$tagBase])) $tag = $tagBase; else $tagSeq = 1;
            }
            if ($tag === '' || !isset($bomTagMap[$tag])) continue;
            $cfg = $bomTagMap[$tag];
            $cfgName = eg_bom_tag_label($cfg['item_name'], $tagSeq);
            if ($cfgName === $it['item_name'] && $cfg['item_type'] === $it['item_type']) continue;
            $updBomSt->execute([$cfgName, $cfg['item_type'], $it['id']]);
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
/**
 * 把舊資料裡「同一料號同一附件類別卻各自成列」的料號附件項目收斂成一列（2026-10-02 使用者拍板：
 * 既有資料不另外批次處理，等那份管制表下次被開啟或同步時自動收斂）。
 *
 * 合併規則：
 *   ①留下 id 最小的那一列（最早建立的，通常就是這份文件第一次被收進來的那一列），其餘軟刪除。
 *   ②留下來的那一列指到現行版、補上 ref_cat_id；歷次上傳自動成為修訂履歷（不必落地）。
 *   ③被合併掉的列若曾被人工「取消納入」(is_excluded)，留下來的那一列一併視為取消納入——
 *     人工說過這份文件不適用，不可以因為系統合併就自己變回納入。
 *   ④被合併掉的列底下若有人工加的修訂履歷，整批搬到留下來的那一列，不丟掉。
 * 回傳實際合併掉幾列。
 */
function type_id_ctrl_collapse_part_items(PDO $db, int $docId, int $dsPk): int {
    if (!$docId || !$dsPk) return 0;
    $st = $db->prepare("SELECT id, ref_attach_id, ref_ds_pk, ref_cat_id, is_excluded
                          FROM type_id_ctrl_item
                         WHERE doc_id=? AND is_deleted=0 AND ref_source='part'
                      ORDER BY id");
    $st->execute([$docId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    $byCat = [];
    foreach ($rows as $r) {
        $cid = (int)($r['ref_cat_id'] ?? 0);
        if ($cid <= 0) $cid = type_id_ctrl_cat_of_attach($db, (int)$r['ref_ds_pk'] ?: $dsPk, (int)$r['ref_attach_id']);
        if ($cid <= 0) continue;                 // 類別已被取消列入／附件已刪除：留著不動，由既有的「來源已消失」提示處理
        $byCat[$cid][] = $r;
    }

    $fams = type_id_ctrl_part_families($db, $dsPk);
    $merged = 0;
    foreach ($byCat as $cid => $list) {
        $keep = $list[0];
        $curAttach = isset($fams[$cid]) ? (int)$fams[$cid]['current']['attach_id'] : (int)$keep['ref_attach_id'];
        $excluded = (int)$keep['is_excluded'];
        foreach ($list as $r) if ((int)$r['is_excluded'] === 1) $excluded = 1;

        if (count($list) > 1) {
            $dropIds = [];
            foreach (array_slice($list, 1) as $r) $dropIds[] = (int)$r['id'];
            $in = implode(',', array_fill(0, count($dropIds), '?'));
            // 人工加的修訂履歷不可以跟著被刪掉，先搬到留下來的那一列
            $db->prepare("UPDATE type_id_ctrl_item_rev SET item_id=?, updated_at=NOW()
                           WHERE item_id IN ($in) AND is_deleted=0 AND (auto_key IS NULL OR auto_key='')")
               ->execute(array_merge([(int)$keep['id']], $dropIds));
            $db->prepare("UPDATE type_id_ctrl_item_rev SET is_deleted=1, updated_at=NOW() WHERE item_id IN ($in)")->execute($dropIds);
            $db->prepare("UPDATE type_id_ctrl_item SET is_deleted=1, updated_at=NOW() WHERE id IN ($in)")->execute($dropIds);
            $merged += count($dropIds);
        }
        if ((int)($keep['ref_cat_id'] ?? 0) !== $cid || (int)$keep['ref_attach_id'] !== $curAttach || (int)$keep['is_excluded'] !== $excluded) {
            $db->prepare("UPDATE type_id_ctrl_item SET ref_cat_id=?, ref_attach_id=?, is_excluded=?, updated_at=NOW() WHERE id=?")
               ->execute([$cid, $curAttach, $excluded, (int)$keep['id']]);
        }
    }
    if ($merged > 0) {
        // 被合併過的清單一律打回「需重新確認」——內容跟當初確認時已經不一樣了
        $db->prepare("UPDATE type_id_ctrl_doc SET review_status='needs_recheck' WHERE id=? AND review_status='confirmed'")
           ->execute([$docId]);
        // 項次重編，不要留下跳號
        $st = $db->prepare("SELECT id FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 ORDER BY seq, id");
        $st->execute([$docId]);
        $i = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $iid) {
            $i++;
            $db->prepare("UPDATE type_id_ctrl_item SET seq=? WHERE id=?")->execute([$i, (int)$iid]);
        }
    }
    return $merged;
}

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

    // 先把舊資料的重複列收斂掉（同料號同類別本來一個檔案一列），再比對要不要新增（2026-10-02）
    type_id_ctrl_collapse_part_items($db, $docId, $dsPk);

    $st = $db->prepare("SELECT id, ref_source, ref_attach_id, ref_ds_pk, ref_file_name, ref_bom_tag, ref_cat_id
                          FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 AND ref_source IS NOT NULL");
    $st->execute([$docId]);
    $existingKeys = []; $bomRowsByTag = []; $partRowsByCat = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)($r['ref_cat_id'] ?? 0);
        if ($r['ref_source'] === 'part' && $cid <= 0) $cid = type_id_ctrl_cat_of_attach($db, (int)$r['ref_ds_pk'], (int)$r['ref_attach_id']);
        $existingKeys[type_id_ctrl_ref_key($r['ref_source'], (int)$r['ref_attach_id'], (int)$r['ref_ds_pk'], $r['ref_bom_tag'], $cid)] = true;
        // 料號附件：同一個類別永遠只留一列，改版上傳新圖時原地把它指到現行版（與 bomfile 同一套做法）
        if ($r['ref_source'] === 'part' && $cid > 0) $partRowsByCat[$cid] = $r + ['_cat' => $cid];
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
        // 料號附件：這個類別已經有一列了，就只把它指到現行版（不另開一列）
        if (($er['source'] ?? '') === 'part') {
            $cid = (int)($er['cat_id'] ?? 0);
            if ($cid > 0 && isset($partRowsByCat[$cid])) {
                $row = $partRowsByCat[$cid];
                if ((int)$row['ref_attach_id'] !== (int)$er['attach_id'] || (int)($row['ref_cat_id'] ?? 0) !== $cid) {
                    $db->prepare("UPDATE type_id_ctrl_item SET ref_attach_id=?, ref_cat_id=?, updated_at=NOW() WHERE id=?")
                       ->execute([(int)$er['attach_id'], $cid, $row['id']]);
                    $updatedCount++;
                }
                continue;
            }
        }
        $key = type_id_ctrl_ref_key($er['source'], (int)$er['attach_id'], (int)$er['ds_pk'], $er['bom_tag'] ?? null, (int)($er['cat_id'] ?? 0));
        if ($er['source'] !== 'bomfile' && isset($existingKeys[$key])) continue;
        $seq++;
        type_id_ctrl_insert_item_from_source($db, $docId, $seq, $er);
        $addedCount++;
    }

    if (($addedCount > 0 || $updatedCount > 0) && $doc && $doc['review_status'] === 'confirmed') {
        $db->prepare("UPDATE type_id_ctrl_doc SET review_status='needs_recheck' WHERE id=?")->execute([$docId]);
    }
    return ['doc_id'=>$docId, 'is_new'=>$isNew, 'added_count'=>$addedCount, 'updated_count'=>$updatedCount];
}

/**
 * 從一筆自動偵測來源列插入一筆新項目列，回傳新項目 id。
 * 唯一實作——sync_part()／type_id_ctrl_apply_diff()（點開自動加入、批次更新）共用同一段插入邏輯，
 * 不要各寫一份（鐵律4：INSERT 的欄位對應只要漏改一處，兩邊資料長相就會慢慢對不起來）。
 */
function type_id_ctrl_insert_item_from_source(PDO $db, int $docId, int $seq, array $er): int {
    // force_type：表單類(其他文件)與 ERP/資材報告(各標籤自己的設定)由來源直接指定型態類別，
    // 只有附件類才需要用類別名稱猜
    $itemType = !empty($er['force_type']) ? $er['force_type'] : type_id_ctrl_guess_type($er['categories'] ?? []);
    $itemName = !empty($er['categories']) ? $er['categories'][0] : $er['doc_name'];
    $originProcess = $er['origin_process'] ?? null;
    $needProcessHint = !empty($er['need_process']) ? 1 : 0;
    $st = $db->prepare("INSERT INTO type_id_ctrl_item (doc_id, seq, item_name, item_type, process_tag, need_process_hint, ref_source, ref_attach_id, ref_ds_pk, ref_file_name, ref_bom_tag, ref_cat_id)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$docId, $seq, $itemName, $itemType, ($originProcess !== '' ? $originProcess : null), $needProcessHint,
                  $er['source'], $er['attach_id'], $er['ds_pk'], $er['file_name'] ?? null, $er['bom_tag'] ?? null,
                  !empty($er['cat_id']) ? (int)$er['cat_id'] : null]);
    return (int)$db->lastInsertId();
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 已確認後的內容變動偵測與批次更新（2026-09-24 使用者要求，涵蓋全部五種來源）
 *   ①新檔案：目前符合的來源列，還沒變成這份文件的項目列（跟 sync_part 判「要不要加入」同一把鍵）。
 *   ②內容變更：既有已連結項目，即時解析出的「版別／文件編號」跟上次確認時存的快照不同
 *     （例：SOP／SIP 改版、料號附件重新填了版次、PFMEA 表單日期改了重編編號…）。
 *   兩者只在文件「已確認」時才有意義去偵測——待確認／需重新確認本來就還沒審過，不必疊加提示。
 * ══════════════════════════════════════════════════════════════════════════════ */

/** 來源列的識別鍵——sync_part／新檔案比對／內容變更比對共用同一套規則（bomfile 用標籤，其餘用 attach_id） */
function type_id_ctrl_ref_key(string $source, int $attachId, int $dsPk, ?string $bomTag, int $catId = 0): string {
    if ($source === 'bomfile') return 'bomfile|' . (string)$bomTag;
    // 料號附件：識別鍵是「料號＋附件類別」不是附件 id——每次改版都會上傳一張新圖，用附件 id 當鍵
    // 會讓同一種圖每改一次版就多長一列（2026-10-02 使用者回報）。
    if ($source === 'part') return 'part|cat' . $catId . '|' . $dsPk;
    return $source . '|' . $attachId . '|' . $dsPk;
}

/**
 * 比對「目前符合的來源」與「既有項目」，回傳新檔案／內容變更兩組差異。
 * 只有 part_d_id 存在時才有意義（本模組的項目一律掛在料號底下）。
 * 唯一實作——清單提示、點開編輯畫面自動加入、批次更新三處共用同一套判斷。
 */
function type_id_ctrl_source_diff(PDO $db, int $docId, int $dsPk): array {
    if (!$dsPk) return ['new' => [], 'changed' => []];
    $fresh = type_id_ctrl_fetch_ext_docs_for_part($db, $dsPk);
    $freshByKey = [];
    foreach ($fresh as $f) {
        $freshByKey[type_id_ctrl_ref_key($f['source'], (int)$f['attach_id'], (int)$f['ds_pk'], $f['bom_tag'] ?? null, (int)($f['cat_id'] ?? 0))] = $f;
    }

    $st = $db->prepare("SELECT id, ref_source, ref_attach_id, ref_ds_pk, ref_bom_tag, ref_cat_id, confirmed_ref_snapshot
                          FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 AND ref_source IS NOT NULL");
    $st->execute([$docId]);
    $existingByKey = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $cid = (int)($it['ref_cat_id'] ?? 0);
        if ($it['ref_source'] === 'part' && $cid <= 0) $cid = type_id_ctrl_cat_of_attach($db, (int)$it['ref_ds_pk'], (int)$it['ref_attach_id']);
        $k = type_id_ctrl_ref_key($it['ref_source'], (int)$it['ref_attach_id'], (int)$it['ref_ds_pk'], $it['ref_bom_tag'], $cid);
        $existingByKey[$k] = $it;
    }

    $new = []; $changed = [];
    foreach ($freshByKey as $k => $f) {
        if (!isset($existingByKey[$k])) { $new[] = $f; continue; }
        $it = $existingByKey[$k];
        $snap = $it['confirmed_ref_snapshot'];
        if ($snap !== null && (string)$snap !== (string)$f['doc_name']) {
            $changed[] = ['item_id' => (int)$it['id'], 'fresh' => $f, 'old_snapshot' => $snap];
        }
    }
    return ['new' => $new, 'changed' => $changed];
}

/** 把這份文件目前所有已連結項目的「版別／文件編號」即時解析值存成確認快照（唯一寫入點） */
function type_id_ctrl_snapshot_confirm(PDO $db, int $docId): void {
    $st = $db->prepare("SELECT id, ref_source, ref_attach_id, ref_ds_pk, ref_file_name
                          FROM type_id_ctrl_item WHERE doc_id=? AND is_deleted=0 AND ref_source IS NOT NULL");
    $st->execute([$docId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $linked = type_id_ctrl_resolve_ref($db, $it['ref_source'], (int)$it['ref_attach_id'], (int)$it['ref_ds_pk'], $it['ref_file_name'], (int)($it['ref_cat_id'] ?? 0));
        $snap = $linked ? $linked['doc_name'] : null;
        $db->prepare("UPDATE type_id_ctrl_item SET confirmed_ref_snapshot=? WHERE id=?")->execute([$snap, $it['id']]);
    }
}

/**
 * 套用一份文件的新檔案／內容變更差異：新檔案一律插入為新項目列；內容變更不動欄位本身
 * （反正即時解析，畫面自然顯示最新值），只影響要不要把狀態打回「需重新確認」。
 * $confirmAfter：使用者在批次更新時的選擇——true＝更新後直接視為已確認（重新寫入確認快照，
 * 不需要再人工確認一次）；false＝更新後改為「需重新確認」，仍要人工按確認清單（預設、較保守）。
 * 只有原本就是「已確認」的文件套用後才會變更狀態，非確認狀態的文件本來就還沒審過，不動它。
 */
function type_id_ctrl_apply_diff(PDO $db, int $docId, array $diff, bool $confirmAfter, int $uid, string $uname): array {
    $addedCount = 0;
    if ($diff['new']) {
        $st = $db->prepare("SELECT COALESCE(MAX(seq),0) FROM type_id_ctrl_item WHERE doc_id=?");
        $st->execute([$docId]);
        $seq = (int)$st->fetchColumn();
        foreach ($diff['new'] as $er) {
            $seq++;
            type_id_ctrl_insert_item_from_source($db, $docId, $seq, $er);
            $addedCount++;
        }
    }
    $changedCount = count($diff['changed']);

    $st = $db->prepare("SELECT review_status FROM type_id_ctrl_doc WHERE id=?");
    $st->execute([$docId]);
    $wasConfirmed = $st->fetchColumn() === 'confirmed';
    if ($wasConfirmed && ($addedCount > 0 || $changedCount > 0)) {
        if ($confirmAfter) {
            $db->prepare("UPDATE type_id_ctrl_doc SET review_status='confirmed', confirmed_by=?, confirmed_by_name=?, confirmed_at=NOW() WHERE id=?")
               ->execute([$uid, $uname, $docId]);
            type_id_ctrl_snapshot_confirm($db, $docId);
        } else {
            $db->prepare("UPDATE type_id_ctrl_doc SET review_status='needs_recheck' WHERE id=?")->execute([$docId]);
        }
    }
    return ['added_count' => $addedCount, 'changed_count' => $changedCount, 'was_confirmed' => $wasConfirmed];
}


/* ============================================================================
 * 料號附件「一種文件一列」（2026-10-02 使用者回報：加工圖/原圖是綁定圖面，每次改版都會
 * 上傳一張新圖，原本一個附件一列會讓同一種圖在管制表長出好幾列，改版資訊也等於顯示兩次）
 * --------------------------------------------------------------------------
 * 收斂單位＝「同一料號 × 同一附件類別」＝一個文件家族（family）：
 *   型態制定日期 ＝ 家族裡最早一次發行（使用者拍板：制定就是第一次訂出來那天，不隨改版往後跳）
 *   版別／文件編號 ＝ 現行版 ＝ 家族裡最新、且沒有被標成「作廢」的那一份
 *   修訂履歷      ＝ 第 2 份起的每一次發行（含現行版本身；只有一份時就沒有修訂）
 * 日期一律取「發行章日期，沒有才退回上傳日」——判準見 ai-rules/15（版次多半沒填，發行章日期才是
 * 圖面有沒有改版的依據）。
 * 作廢判定走管理員可設定的類別（type_id_ctrl_void_cat_ids），不寫死「作廢」這個名稱＝鐵律4；
 * 實測有 3 組家族最新那一份就是作廢，所以不能單純取最新當現行版。
 * ========================================================================== */

/** 被管理員標記為「代表已作廢」的附件類別 id（可複選；沒設定就是空陣列＝不做作廢判定） */
function type_id_ctrl_void_cat_ids(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='TYPE_ID_CTRL' AND param_key='void_category_ids' LIMIT 1");
        $st->execute();
        $raw = (string)$st->fetchColumn();
    } catch (Throwable $e) { $raw = ''; }
    $ids = [];
    foreach (explode(',', $raw) as $x) { $x = (int)trim($x); if ($x > 0) $ids[] = $x; }
    return $cache = array_values(array_unique($ids));
}

function type_id_ctrl_void_cat_save(PDO $db, array $ids, string $byUser): void {
    $clean = [];
    foreach ($ids as $x) { $x = (int)$x; if ($x > 0) $clean[] = $x; }
    $clean = array_values(array_unique($clean));
    type_id_ctrl_param_save($db, 'void_category_ids', implode(',', $clean),
                            '型態識別文件管制表：代表「已作廢」的附件類別（這些圖不會被當成現行版，只收進修訂履歷）', $byUser);
}

/**
 * 此料號的料號附件文件家族：cat_id => [
 *   'cat_id','disp','need_process','all'(由舊到新的每一份), 'first'(最早), 'current'(現行版)
 * ]
 * 只含「會列入本模組」的類別（外來文件清單 is_external_doc=1 ＋ 廠內圖面已勾選 type_id_ctrl_include=1）。
 * 一個附件同時掛兩個列入類別時，兩個家族各算一次（它本來就同時是兩種文件）。
 */
function type_id_ctrl_part_families(PDO $db, int $dsPk): array {
    static $cache = [];
    if (isset($cache[$dsPk])) return $cache[$dsPk];

    $catRows = $db->query("SELECT id, COALESCE(NULLIF(external_doc_name,''), category_name) AS disp,
                                  COALESCE(type_id_ctrl_need_process,0) AS need_process
                           FROM quotation_file_categories WHERE is_external_doc=1 OR type_id_ctrl_include=1")
                  ->fetchAll(PDO::FETCH_ASSOC);
    if (!$catRows || !$dsPk) return $cache[$dsPk] = [];
    $cats = [];
    foreach ($catRows as $cr) $cats[(int)$cr['id']] = ['disp'=>$cr['disp'], 'need_process'=>(bool)$cr['need_process']];

    $st = $db->prepare("SELECT pa.id AS attach_id, pa.d_id AS ds_pk, pa.filename,
                               COALESCE(NULLIF(pa.original_name,''), pa.filename) AS doc_name,
                               pa.category_ids, pa.revision, pa.issue_stamp_date,
                               DATE(pa.uploaded_at) AS up_date
                          FROM part_attachments pa
                         WHERE pa.d_id=? AND pa.deleted_at IS NULL");
    $st->execute([$dsPk]);
    // 批圖工作檔(.egwork.json)與「有工作檔的暫存輸出圖」都不是正式文件（imgedit_visibility.php）
    require_once __DIR__ . '/imgedit_visibility.php';
    $rows = imgedit_strip_workfiles($st->fetchAll(PDO::FETCH_ASSOC), $db);

    $voidIds = type_id_ctrl_void_cat_ids($db);
    $fams = [];
    foreach ($rows as $r) {
        $ids = [];
        foreach (explode(',', str_replace(' ', '', (string)$r['category_ids'])) as $x) { $x = (int)$x; if ($x > 0) $ids[] = $x; }
        $r['_is_void'] = (bool)array_intersect($ids, $voidIds);
        $r['_date']    = ($r['issue_stamp_date'] !== null && $r['issue_stamp_date'] !== '') ? $r['issue_stamp_date'] : $r['up_date'];
        foreach ($ids as $cid) {
            if (!isset($cats[$cid])) continue;      // 不列入本模組的類別（含作廢標記本身）不自成一族
            $fams[$cid][] = $r;
        }
    }

    $out = [];
    foreach ($fams as $cid => $list) {
        usort($list, function ($a, $b) {
            $c = strcmp((string)$a['_date'], (string)$b['_date']);
            return $c !== 0 ? $c : ((int)$a['attach_id'] <=> (int)$b['attach_id']);
        });
        // 版本的單位是「發行章日期」不是「檔案」：同一天上傳/掃描的好幾個檔案是同一版（一張圖掃成
        // 兩三個檔、或事後補傳同一版的另一份），各算一次修訂會在履歷上冒出「修訂日期跟制定日期同一天」
        // 這種看不懂的列（實測料號 669 的 BOSS圖就是：117 與 861 都是發行章 2026-02-25）。
        $vers = [];
        foreach ($list as $a) {
            $d = (string)$a['_date'];
            if (!isset($vers[$d])) $vers[$d] = ['date'=>$a['_date'], 'files'=>[], 'revision'=>'', 'is_void'=>true, 'rep'=>null];
            $vers[$d]['files'][] = $a;
            if ($vers[$d]['revision'] === '') { $t = type_id_ctrl_version_text($a); if ($t !== '') $vers[$d]['revision'] = $t; }
            if (!$a['_is_void']) { $vers[$d]['is_void'] = false; $vers[$d]['rep'] = $a; }   // 同一版裡優先用沒作廢的那一份
            if ($vers[$d]['rep'] === null) $vers[$d]['rep'] = $a;
        }
        $vers = array_values($vers);   // $list 已排序，PHP 會保留插入順序＝由舊到新
        // 現行版＝最新且未作廢的那一版；整族都作廢時退回最新那一版
        // （寧可指到一份作廢圖，也不要整列空著無從追溯）
        $curVer = null;
        for ($i = count($vers) - 1; $i >= 0; $i--) { if (!$vers[$i]['is_void']) { $curVer = $vers[$i]; break; } }
        if ($curVer === null) $curVer = $vers[count($vers) - 1];
        $out[$cid] = [
            'cat_id'       => $cid,
            'disp'         => $cats[$cid]['disp'],
            'need_process' => $cats[$cid]['need_process'],
            'all'          => $list,          // 全部檔案（供對照用）
            'versions'     => $vers,          // 版本（以發行章日期為單位，由舊到新）
            'first'        => $vers[0]['rep'],
            'current'      => $curVer['rep'],
        ];
    }
    return $cache[$dsPk] = $out;
}

/**
 * 一份附件的「版別」文字：有版次用版次，沒版次但有發行章日期就用發行日（使用者 2026-10-02 指定，
 * 加工圖這類自家出的圖本來就是以發行日當版別），兩者都沒有回空字串（呼叫端自行決定要不要退回檔名）。
 */
function type_id_ctrl_version_text(array $a): string {
    $rev = $a['revision'] ?? null;
    if ($rev !== null && $rev !== '') return (string)$rev;
    $d = $a['issue_stamp_date'] ?? null;
    if ($d !== null && $d !== '') return eg_fmt_date($d);
    return '';
}

/** 由附件 id 回推它屬於哪個列入類別（舊資料沒有 ref_cat_id 時用；取最先命中的那一個） */
function type_id_ctrl_cat_of_attach(PDO $db, int $dsPk, int $attachId): int {
    foreach (type_id_ctrl_part_families($db, $dsPk) as $cid => $f) {
        foreach ($f['all'] as $a) if ((int)$a['attach_id'] === $attachId) return (int)$cid;
    }
    return 0;
}

/* ============================================================================
 * 修訂履歷（2026-10-01 使用者要求：每一個項目列都要有「每次的修訂日期＋修訂後版別」）
 * --------------------------------------------------------------------------
 * 版面刻意維持 A4 直式、修訂履歷獨立成一欄（使用者拍板）：修訂次數每一列都不一樣，
 * 紙本那種固定開「修訂1/2/3」欄位的橫式表，超過次數就印不出來、大半格子還是空的。
 *
 * 資料來源＝「自動帶入＋可手動增修」（使用者拍板）。自動只做「來源本身真的查得到版次履歷」
 * 的兩種，查不到的一律留白讓人自己補，不猜：
 *   ① sopsip ── ss_ver 就是這份 SOP／SIP 的版次履歷（ver_no + form_date），最準。
 *   ② part   ── 同一個料號、同一組附件類別底下，發行章日期比「本列自己的日期」更新的那幾份，
 *               視為這份文件之後的改版（判定依據＝發行章日期，見 ai-rules/15；版次欄多半沒填，
 *               那就只有修訂日期沒有修訂後版別，仍然是有效的管制資訊）。
 * 其餘來源（quote 報價附件／bomfile ERP報告／dev_eval／pfmea）來源端沒有版次欄位可查，不自動帶。
 * ========================================================================== */

/** 自動推導出的修訂履歷；回傳 [['auto_key','rev_date','rev_version','note'], ...]（由舊到新） */
function type_id_ctrl_auto_revisions(PDO $db, array $it): array {
    $source   = (string)($it['ref_source'] ?? '');
    $attachId = (int)($it['ref_attach_id'] ?? 0);
    $dsPk     = (int)($it['ref_ds_pk'] ?? 0);
    $out = [];

    if ($source === 'sopsip' && $attachId) {
        try {
            $st = $db->prepare("SELECT ver_id, ver_no, form_date, rev_note FROM ss_ver WHERE doc_id=? ORDER BY form_date, ver_id");
            $st->execute([$attachId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $rows = []; }
        // 第一版是「制定」不是「修訂」，所以從第二版起才列入履歷
        foreach (array_slice($rows, 1) as $r) {
            $out[] = [
                'auto_key'    => 'sopsip:' . (int)$r['ver_id'],
                'rev_date'    => $r['form_date'] ?: null,
                'rev_version' => (string)($r['ver_no'] ?? ''),
                'note'        => trim((string)($r['rev_note'] ?? '')),
            ];
        }
        return $out;
    }

    if ($source === 'part' && $dsPk) {
        // 一種文件一列（2026-10-02）：這一列代表整個文件家族，所以修訂履歷＝家族裡第 2 份起的
        // 每一次上傳（第 1 份是「制定」不是修訂；現行版本身若不是第 1 份，它也是一次修訂）。
        $catId = (int)($it['ref_cat_id'] ?? 0);
        if ($catId <= 0 && $attachId > 0) $catId = type_id_ctrl_cat_of_attach($db, $dsPk, $attachId);
        $fam = type_id_ctrl_part_families($db, $dsPk)[$catId] ?? null;
        if (!$fam) return [];
        foreach (array_slice($fam['versions'], 1) as $v) {
            // auto_key 綁該版代表檔的 id：同一版補傳第二份檔案時不會再冒出一筆重複的修訂
            $out[] = [
                'auto_key'    => 'part:' . (int)$v['rep']['attach_id'],
                'rev_date'    => $v['date'] ?: null,
                'rev_version' => (string)$v['revision'],
                'note'        => !empty($v['is_void']) ? '已作廢' : '',
            ];
        }
    }
    return $out;
}

/**
 * 某一個項目列目前該顯示的修訂履歷＝已存的列 ＋ 來源新偵測到、還沒存過的自動列。
 * 合併規則：auto_key 已經存在（含被人工刪掉的軟刪除列）就不再加回來，所以人工把自動列刪掉
 * 之後不會每次開畫面又冒出來；人工改過的內容也不會被自動值蓋掉。
 */
function type_id_ctrl_item_revs(PDO $db, int $itemId, array $it): array {
    $saved = [];
    if ($itemId) {
        $st = $db->prepare("SELECT id, seq, rev_date, rev_version, note, auto_key, is_deleted
                             FROM type_id_ctrl_item_rev WHERE item_id=? ORDER BY seq, id");
        $st->execute([$itemId]);
        $saved = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $seenAuto = [];
    foreach ($saved as $r) { if ($r['auto_key'] !== null && $r['auto_key'] !== '') $seenAuto[$r['auto_key']] = true; }

    $rows = [];
    foreach ($saved as $r) {
        if ((int)$r['is_deleted'] === 1) continue;   // 軟刪除只是用來擋自動列被加回來，不顯示
        $rows[] = [
            'id'          => (int)$r['id'],
            'rev_date'    => $r['rev_date'],
            'rev_version' => (string)($r['rev_version'] ?? ''),
            'note'        => (string)($r['note'] ?? ''),
            'auto_key'    => $r['auto_key'],
            'is_auto'     => ($r['auto_key'] !== null && $r['auto_key'] !== ''),
            'is_new'      => false,
        ];
    }
    $auto = type_id_ctrl_auto_revisions($db, $it);
    // 使用者確認重複時被指為「舊版」的那幾列，也以修訂履歷呈現在現行版底下（2026-10-02）
    foreach (type_id_ctrl_superseded_revisions($db, $itemId) as $sp) $auto[] = $sp;
    foreach ($auto as $a) {
        if (isset($seenAuto[$a['auto_key']])) continue;
        $rows[] = [
            'id' => 0, 'rev_date' => $a['rev_date'], 'rev_version' => $a['rev_version'],
            'note' => $a['note'], 'auto_key' => $a['auto_key'], 'is_auto' => true, 'is_new' => true,
        ];
    }
    usort($rows, function ($x, $y) {
        $a = (string)($x['rev_date'] ?? ''); $b = (string)($y['rev_date'] ?? '');
        if ($a === $b) return 0;
        if ($a === '') return 1;        // 沒填日期的排最後
        if ($b === '') return -1;
        return strcmp($a, $b);
    });
    return $rows;
}

/**
 * 儲存某個項目列的修訂履歷（整批覆寫）。
 * 前端送來的列若帶 id 就更新、沒帶就新增；原本有、這次沒送到的一律軟刪除並保留 auto_key
 * （保留才擋得住「人工刪掉的自動列下次又被合併加回來」）。
 */
function type_id_ctrl_revs_save(PDO $db, int $itemId, array $rows): void {
    if (!$itemId) return;
    $st = $db->prepare("SELECT id FROM type_id_ctrl_item_rev WHERE item_id=? AND is_deleted=0");
    $st->execute([$itemId]);
    $existing = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    $seq = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $date = trim((string)($r['rev_date'] ?? ''));
        $ver  = trim((string)($r['rev_version'] ?? ''));
        $note = trim((string)($r['note'] ?? ''));
        if ($date === '' && $ver === '' && $note === '') continue;   // 整列空白不存（比照可增列表格鐵則）
        if ($date !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) $date = '';
        $autoKey = trim((string)($r['auto_key'] ?? ''));
        $seq++;
        $rid = (int)($r['id'] ?? 0);
        if ($rid && isset($existing[$rid])) {
            $db->prepare("UPDATE type_id_ctrl_item_rev SET seq=?, rev_date=?, rev_version=?, note=?, updated_at=NOW() WHERE id=?")
               ->execute([$seq, $date ?: null, $ver !== '' ? $ver : null, $note !== '' ? $note : null, $rid]);
            unset($existing[$rid]);
        } else {
            $db->prepare("INSERT INTO type_id_ctrl_item_rev (item_id, seq, rev_date, rev_version, note, auto_key)
                           VALUES (?,?,?,?,?,?)")
               ->execute([$itemId, $seq, $date ?: null, $ver !== '' ? $ver : null, $note !== '' ? $note : null,
                          $autoKey !== '' ? $autoKey : null]);
        }
    }
    if ($existing) {
        $ids = array_keys($existing);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE type_id_ctrl_item_rev SET is_deleted=1, updated_at=NOW() WHERE id IN ($in)")->execute($ids);
    }
}

/* ============================================================================
 * 日期合理性檢核（2026-10-02 使用者回報）
 * --------------------------------------------------------------------------
 * 使用者原話：「原圖的制定日期比加工圖還晚，這一看就不合理，加工圖是依據原圖製作，要卡相關日期。
 *              原圖的日期一定是最早，其他都是依據原圖/報價圖產出」
 *
 * 判定依據**不寫死「原圖」這個名稱**（鐵律4），改用附件類別既有的兩個旗標——這兩個旗標本來就是
 * 這個意思，不必另開設定：
 *   is_external_doc=1 ── 外來文件（客戶給的原圖、報價圖、規格書…）＝**基準**，日期應該最早
 *   is_own_drawing=1  ── 自家出的圖（加工圖、++圖…）＝**依據基準圖產出**，日期不得早於基準
 *
 * 實際踩到的資料問題（料號 447-000C-820-18）：原圖沒有發行章日期，所以日期退回「上傳日」
 * 2026-08-12，而加工圖有真正的發行章日期 2025-03-05 → 看起來像「加工圖比原圖早」。
 * 真正要修的是原圖的日期，所以提示要同時指出「原圖用的是上傳日、請補發行日期」，不能只罵加工圖。
 *
 * 只警示不硬擋**一般儲存**（補舊資料時本來就可能對不起來），但**確認清單會擋**——確認等於正式
 * 認可這份清單，日期自相矛盾的清單不該被確認掉。
 * ========================================================================== */

/**
 * 回傳每個項目列的日期問題：[ item_id(或陣列索引) => ['level'=>'error','text'=>'...'], ... ]
 * $items 用 type_id_ctrl_item_view() 產出的格式（需有 ref_source/ref_cat_id/effective_date/is_excluded）。
 * $keyField：用哪個欄位當鍵（已存檔用 'id'，尚未存檔的新管制表用陣列索引請傳 null）。
 */
function type_id_ctrl_date_issues(PDO $db, array $items, ?string $keyField = 'id'): array {
    static $catFlags = null;
    if ($catFlags === null) {
        $catFlags = [];
        foreach ($db->query("SELECT id, COALESCE(is_external_doc,0) ext, COALESCE(is_own_drawing,0) own,
                                    COALESCE(NULLIF(external_doc_name,''), category_name) disp
                               FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $catFlags[(int)$c['id']] = ['ext'=>(int)$c['ext'], 'own'=>(int)$c['own'], 'disp'=>$c['disp']];
        }
    }

    $base = null; $baseLabel = ''; $baseKey = null; $baseIsUploadDate = false;
    $derived = [];
    foreach ($items as $k => $it) {
        if (!empty($it['is_excluded'])) continue;
        $d = $it['effective_date'] ?? null;
        if (!$d) continue;
        $cid = (int)($it['ref_cat_id'] ?? 0);
        $f = $catFlags[$cid] ?? null;
        if (!$f) continue;                       // 非附件來源（表單、SOP/SIP、ERP報告）不參與這條檢核
        $key = ($keyField !== null && isset($it[$keyField])) ? $it[$keyField] : $k;
        if ($f['ext']) {
            if ($base === null || strcmp((string)$d, (string)$base) < 0) {
                $base = $d; $baseLabel = (string)($it['item_name'] ?? $f['disp']); $baseKey = $key;
                // 這份基準圖有沒有真正的發行章日期？沒有就是退回上傳日，提示要講清楚
                $baseIsUploadDate = empty($it['has_issue_stamp']);
            }
        } elseif ($f['own']) {
            $derived[] = ['key'=>$key, 'date'=>$d, 'name'=>(string)($it['item_name'] ?? $f['disp'])];
        }
    }
    if ($base === null || !$derived) return [];

    $issues = []; $earliest = null;
    foreach ($derived as $x) {
        if (strcmp((string)$x['date'], (string)$base) >= 0) continue;
        $issues[$x['key']] = ['level'=>'error',
            'text'=>'此日期（'.eg_fmt_date($x['date']).'）早於「'.$baseLabel.'」的 '.eg_fmt_date($base)
                   .'；'.$x['name'].'是依據'.$baseLabel.'製作的，日期不應該比它早。'];
        if ($earliest === null || strcmp((string)$x['date'], (string)$earliest) < 0) $earliest = $x['date'];
    }
    if ($issues && $baseKey !== null) {
        $issues[$baseKey] = ['level'=>'error',
            'text'=>'有依據它產出的文件日期更早（'.eg_fmt_date($earliest).'）。'
                   .($baseIsUploadDate
                        ? '這一份沒有填發行章日期，目前顯示的是「上傳日」'.eg_fmt_date($base).'，多半是它需要補上實際的發行日期。'
                        : '請確認是這一份的發行日期填錯，還是下面那幾份填錯。')];
    }
    return $issues;
}

/* ============================================================================
 * 同一種文件出現好幾份時，由使用者確認（2026-10-02 使用者回報）
 * --------------------------------------------------------------------------
 * 昨天的「一種文件一列」只收斂得了料號附件（同料號同附件類別）。實測料號 3004012570 還是出現
 * 兩列「原圖」——一份是料號附件(類別1)、一份是報價附件(類別1)，**跨來源**所以沒被收進同一個家族；
 * 而且那份報價附件的檔名是 300401257（比料號少一碼 0），比較像重複上傳或掛錯。
 * 這種「哪一份才是現行的、哪一份其實是重複」系統沒有把握，**一律不自動決定，交給人確認**。
 *
 * 使用者拍板：
 *   ①判定範圍＝**同一個「型態項目名稱」**（所以 BOSS圖／單製++圖 都叫「加工圖」也會被拿出來問；
 *     那本來就是兩種不同的圖，所以另外給一個「不是重複，各自保留」的出口，不然會被迫合併掉）。
 *   ②建立／同步當下跳窗，沒處理完也能先存檔，那幾列會持續標示「待確認重複」。
 *   ③每一組選一份「現行版」、另外勾選「不列入」，**其餘自動認定為舊版**。
 *
 * 三種結果怎麼存（都沿用既有欄位語意，不另開狀態機）：
 *   現行版 ── 一般項目列（superseded_by 為 NULL、is_excluded=0）
 *   舊版   ── 該列 superseded_by 指向現行版那一列：**不再是獨立項目列**，改以修訂履歷呈現在
 *             現行版底下。列本身保留著（不刪），同步才不會把它當成「新檔案」又加回來一列。
 *   不列入 ── is_excluded=1（與既有「取消納入」同一件事，同步也不會再加回來）
 * ========================================================================== */

/**
 * 這份管制表裡「同一個型態項目名稱有兩份以上」待確認的群組。
 * 已經處理過的不會再出現：被指為舊版的(superseded_by 有值)、不列入的(is_excluded)、
 * 以及使用者按過「不是重複」的(dup_ignore)都不算進來。
 * 回傳 [ ['name'=>..., 'items'=>[item_id,...]], ... ]
 */
function type_id_ctrl_dup_groups(PDO $db, int $docId): array {
    if (!$docId) return [];
    $st = $db->prepare("SELECT id, item_name FROM type_id_ctrl_item
                         WHERE doc_id=? AND is_deleted=0 AND is_excluded=0
                           AND superseded_by IS NULL AND COALESCE(dup_ignore,0)=0
                           AND item_name<>'' ORDER BY seq, id");
    $st->execute([$docId]);
    $byName = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byName[(string)$r['item_name']][] = (int)$r['id'];
    $out = [];
    foreach ($byName as $name => $ids) {
        if (count($ids) < 2) continue;
        $out[] = ['name' => $name, 'items' => $ids];
    }
    return $out;
}

/**
 * 使用者對某一組重複做出決定。
 *   $ignore=true ── 這幾份不是重複，各自保留（全部標 dup_ignore，之後不再問）
 *   否則 ── $currentId 為現行版、$excludeIds 標成不列入、其餘自動成為現行版的舊版
 * 一律只動「這個群組裡」的列（$memberIds 由後端自己算出來，不採信前端送的範圍＝鐵律8）。
 */
function type_id_ctrl_dup_resolve(PDO $db, int $docId, string $name, int $currentId, array $excludeIds, bool $ignore): array {
    $st = $db->prepare("SELECT id FROM type_id_ctrl_item
                         WHERE doc_id=? AND is_deleted=0 AND item_name=? ORDER BY seq, id");
    $st->execute([$docId, $name]);
    $members = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($members) < 2) return ['ok'=>false, 'message'=>'這一組已經不是重複了，請重新整理畫面'];

    if ($ignore) {
        $in = implode(',', array_fill(0, count($members), '?'));
        $db->prepare("UPDATE type_id_ctrl_item SET dup_ignore=1, superseded_by=NULL, updated_at=NOW() WHERE id IN ($in)")
           ->execute($members);
        return ['ok'=>true, 'mode'=>'ignore', 'count'=>count($members)];
    }

    if (!in_array($currentId, $members, true)) return ['ok'=>false, 'message'=>'請指定其中一份為現行版'];
    $excludeIds = array_values(array_intersect(array_map('intval', $excludeIds), $members));
    if (in_array($currentId, $excludeIds, true)) return ['ok'=>false, 'message'=>'現行版不可以同時標成不列入'];

    $old = 0;
    foreach ($members as $mid) {
        if ($mid === $currentId) {
            $db->prepare("UPDATE type_id_ctrl_item SET superseded_by=NULL, is_excluded=0, dup_ignore=0, updated_at=NOW() WHERE id=?")->execute([$mid]);
        } elseif (in_array($mid, $excludeIds, true)) {
            $db->prepare("UPDATE type_id_ctrl_item SET superseded_by=NULL, is_excluded=1, dup_ignore=0, updated_at=NOW() WHERE id=?")->execute([$mid]);
        } else {
            $db->prepare("UPDATE type_id_ctrl_item SET superseded_by=?, is_excluded=0, dup_ignore=0, updated_at=NOW() WHERE id=?")->execute([$currentId, $mid]);
            $old++;
        }
    }
    // 被指為舊版的列不可以同時又是別人的現行版（指向鏈只能一層）
    $db->prepare("UPDATE type_id_ctrl_item SET superseded_by=? WHERE doc_id=? AND is_deleted=0 AND superseded_by IN (SELECT * FROM (SELECT id FROM type_id_ctrl_item WHERE doc_id=? AND superseded_by=?) t)")
       ->execute([$currentId, $docId, $docId, $currentId]);
    return ['ok'=>true, 'mode'=>'resolve', 'current'=>$currentId, 'old'=>$old, 'excluded'=>count($excludeIds)];
}

/**
 * 被指為「舊版」的那幾列，轉成現行版那一列的修訂履歷項目。
 * 日期取該列自己的型態日期、版別取它的版別／文件編號；auto_key 綁 item id，所以不會重複長出來，
 * 使用者也可以把它刪掉（軟刪除保留 auto_key，見 type_id_ctrl_item_revs）。
 */
function type_id_ctrl_superseded_revisions(PDO $db, int $itemId): array {
    if (!$itemId) return [];
    $st = $db->prepare("SELECT * FROM type_id_ctrl_item WHERE superseded_by=? AND is_deleted=0 ORDER BY seq, id");
    $st->execute([$itemId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v = type_id_ctrl_item_view($db, $r);
        $out[] = [
            'auto_key'    => 'supersede:' . (int)$r['id'],
            'rev_date'    => $v['effective_date'] ?: null,
            'rev_version' => (string)($v['doc_no_text'] ?? ''),
            'note'        => '舊版（' . ($v['ref_source_label'] ?: '人工輸入') . '）',
        ];
    }
    return $out;
}

/* ============================================================================
 * 專案連動（2026-10-01 使用者要求）
 * --------------------------------------------------------------------------
 * ①清單要看得出「這個料號有沒有專案」並且可以篩選。
 * ②專案相關的資料要由管理員設定哪幾種自動列入項目列。
 * 專案料號的對應表是 project_part（ds_pk ↔ d_setting.d_id），不另外存一份對照。
 * ========================================================================== */

/**
 * 可設定自動列入的「專案相關資料」登記表（唯一登記處；加一列就多一種可勾選的來源）。
 *   ready=false 代表專案模組那邊的資料表還沒好，畫面上照樣列得出來但標示「尚未提供」，
 *   等專案頁完成後把 ready 改成 true 並補上 fetch 函式即可自動生效（鐵律4：不另存對照表）。
 */
function type_id_ctrl_project_sources(): array {
    return [
        'fai_confirm' => [
            'label' => '客戶首件確認書',
            'desc'  => '專案首件檢驗(FAI)經客戶確認回簽的確認書；專案頁面建置中，完成後自動生效。',
            'ready' => false,
        ],
    ];
}

/** 目前勾選要自動列入的專案資料來源代碼（預設全部不列入，管理員自己開） */
function type_id_ctrl_project_src_cfg(PDO $db): array {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='TYPE_ID_CTRL' AND param_key='project_sources' LIMIT 1");
        $st->execute();
        $arr = json_decode((string)$st->fetchColumn(), true);
    } catch (Throwable $e) { $arr = null; }
    if (!is_array($arr)) $arr = [];
    $out = [];
    foreach (type_id_ctrl_project_sources() as $code => $def) {
        $cfg = is_array($arr[$code] ?? null) ? $arr[$code] : [];
        $out[$code] = [
            'enabled'   => !empty($cfg['enabled']),
            'item_name' => trim((string)($cfg['item_name'] ?? '')) ?: $def['label'],
        ];
    }
    return $out;
}

/** 儲存專案資料來源設定（只認登記表裡有的代碼，其餘一律忽略） */
function type_id_ctrl_project_src_save(PDO $db, array $rows, string $byUser): void {
    $defs = type_id_ctrl_project_sources();
    $clean = [];
    foreach ($rows as $code => $cfg) {
        $code = trim((string)$code);
        if (!isset($defs[$code]) || !is_array($cfg)) continue;
        $name = trim((string)($cfg['item_name'] ?? ''));
        $clean[$code] = ['enabled' => !empty($cfg['enabled']) ? 1 : 0,
                         'item_name' => $name !== '' ? $name : $defs[$code]['label']];
    }
    type_id_ctrl_param_save($db, 'project_sources', json_encode($clean, JSON_UNESCAPED_UNICODE),
                            '型態識別文件管制表：哪些專案相關資料要自動列入項目列', $byUser);
}

/**
 * 這些料號各自有哪些專案（ds_pk => [['project_id','project_no','name','status'], ...]）。
 * 一次查一批，清單頁才不會逐列各打一次（N+1）。project_part 查不到就是沒有專案。
 */
function type_id_ctrl_part_projects(PDO $db, array $dsPks): array {
    $dsPks = array_values(array_unique(array_filter(array_map('intval', $dsPks))));
    if (!$dsPks) return [];
    $in = implode(',', array_fill(0, count($dsPks), '?'));
    try {
        $st = $db->prepare("SELECT pp.ds_pk, p.project_id, COALESCE(p.project_no,'') AS project_no,
                                   COALESCE(p.project_name,'') AS project_name,
                                   COALESCE(p.status,'') AS status, COALESCE(p.phase,'') AS phase
                             FROM project_part pp
                             JOIN project p ON p.project_id = pp.project_id AND COALESCE(p.is_deleted,0)=0
                             WHERE pp.ds_pk IN ($in)
                             ORDER BY p.project_id DESC");
        $st->execute($dsPks);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }   // 專案模組尚未建表時一律當成沒有專案，不讓本頁壞掉
    // 狀態／階段顯示文字一律在這裡轉（專案模組自己的對照表 PRJ_PHASES 只有階段，
    // 狀態的中文散在專案頁的下拉選項裡；本頁只是要顯示得懂，故在此集中一處轉換）
    $stLabel = ['draft'=>'草稿','submitted'=>'已送簽','approved'=>'已核准','rejected'=>'已退回','closed'=>'已結案'];
    $phLabel = ['planning'=>'規劃','executing'=>'執行','controlling'=>'控制','closing'=>'結案'];
    $map = [];
    foreach ($rows as $r) {
        $r['status_label'] = $stLabel[$r['status']] ?? (string)$r['status'];
        $r['phase_label']  = $phLabel[$r['phase']]  ?? (string)$r['phase'];
        $map[(int)$r['ds_pk']][] = $r;
    }
    return $map;
}
