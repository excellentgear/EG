<?php
/**
 * AS 文件「已網頁化」對照 —— 全站共用（2026-08-24 建立）
 *
 * 解決的問題：哪一份 AS 文件已經做成系統頁面（可以線上填寫／查詢），過去只寫在
 * `views/ADM/as_flow_guide.php` 裡面一份；`views/ADM/as_document_management.php` 的
 * 結構總覽要用同一份資料，若各自再刻一份就一定會走鐘（鐵律4）。故抽成本庫，
 * **兩邊共用同一份判定，新模組綁定 AS 文件後兩頁同時自動出現，不必回頭登記。**
 *
 * 「已網頁化」四種來源（缺一不可，後者覆蓋前者）：
 *   form_tpl     — AS 線上表單設計器做出來的表單（as_form_template.form_doc_id）
 *   module       — 既有電子化模組（as_document.linked_module，如 car／qa_abnormal）
 *   legacy       — 尚未遷移到統一綁定庫的舊模組（各自存 system_settings／自己的 param_group，只能手動登記）
 *   bind         — 統一綁定庫 asdoc_lib.php（system_parameters param_group='AS_DOC_BIND'）＝**動態掃描永遠不漏**
 *                  其中 review_form_tpl_<id>／fsd_tpl_<id> 是「一個模板一組編號」的通用引擎，
 *                  來源另標成 review_form／form_signer，呼叫端可個別排除
 *                  （使用者拍板：form_signer 只是紙本簽好再補送的流程，**不算電子化**，結構總覽不列它）。
 *
 * 用法：
 *   require_once __DIR__.'/asdoc_page_lib.php';
 *   $map = eg_asdoc_page_map($db, ['skip_sources'=>['form_signer']]);   // [as_document.id => ['name','url','source','module']]
 *   $ok  = eg_asdoc_page_can_open($db, $userId, $map[$id]['url']);      // 該使用者有沒有權限開那一頁
 *
 * 鐵律5：url 一律是站內相對根路徑（/EGsystem/views/…），**不存 DB**、每次即時組。
 */
if (!function_exists('eg_asdoc_page_map')) {

/** 站內頁面根路徑 */
define('EG_ASDOC_PAGE_BASE', '/EGsystem/views/');

/**
 * 已網頁化的 AS 文件對照表
 * @param array $opt  skip_sources：要排除的來源（form_signer／review_form／form_tpl／module／legacy／bind）
 * @return array      [as_document.id => ['name'=>用途, 'url'=>頁面, 'source'=>來源, 'module'=>模組代碼]]
 */
function eg_asdoc_page_map(PDO $db, array $opt = []): array {
    $skip = array_flip((array)($opt['skip_sources'] ?? []));
    $out  = [];
    $put  = function (int $docId, string $name, string $url, string $src, string $mod = '') use (&$out, $skip) {
        if ($docId <= 0 || $url === '' || isset($skip[$src])) { return; }
        $out[$docId] = ['name' => $name, 'url' => $url, 'source' => $src, 'module' => $mod];
    };
    // 'ADM/xxx.php' => /EGsystem/views/ADM/xxx.php；'../src/store/xxx.php' => /EGsystem/src/store/xxx.php
    $U = function (string $rel) {
        return strpos($rel, '../') === 0
            ? '/EGsystem/' . substr($rel, 3)
            : EG_ASDOC_PAGE_BASE . $rel;
    };

    // ① AS 線上表單設計器（優先序最低，之後被實際模組頁面覆蓋）
    try {
        $sql = "SELECT t.id, t.form_doc_id FROM as_form_template t
                JOIN as_document d ON d.id = t.form_doc_id AND d.is_deleted = 0
                WHERE t.is_deleted = 0 AND t.form_doc_id > 0 ORDER BY t.id";
        foreach ($db->query($sql, PDO::FETCH_ASSOC) as $r) {
            $put((int)$r['form_doc_id'], 'AS 線上表單', $U('ADM/as_form_fill.php?template_id=' . (int)$r['id']), 'form_tpl');
        }
    } catch (Throwable $e) { error_log('asdoc_page_map form_tpl: ' . $e->getMessage()); }

    // ② 既有電子化模組（as_document.linked_module）
    $MODULE_PAGES = [
        'car'         => ['異常矯正處理單(CAR)', 'QA/correction_order.php'],
        'qa_abnormal' => ['品質異常處理單',      'QA/qa_abnormal_view.php'],
    ];
    try {
        $sql = "SELECT id, linked_module FROM as_document
                WHERE is_deleted=0 AND linked_module IS NOT NULL AND linked_module<>''";
        foreach ($db->query($sql, PDO::FETCH_ASSOC) as $r) {
            $m = (string)$r['linked_module'];
            if (isset($MODULE_PAGES[$m])) { $put((int)$r['id'], $MODULE_PAGES[$m][0], $U($MODULE_PAGES[$m][1]), 'module', $m); }
        }
    } catch (Throwable $e) { error_log('asdoc_page_map module: ' . $e->getMessage()); }

    // ③ 舊式各自存放的綁定（沒有共同 group 可整批掃，只能手動登記；新增此類舊式綁定時才要回來補一列）
    //    [來源(ss=system_settings／sp=system_parameters), 鍵(sp 用「群組|鍵」), 用途, 頁面]
    $LEGACY = [
        ['ss', 'vendor_audit_as_doc_id',      '供應商稽核管理 · 稽核查檢表',         'pm/vendor_audit.php'],
        ['ss', 'vendor_record_as_doc_id',     '供應商稽核管理 · 品質系統評鑑記錄表', 'pm/vendor_audit.php'],
        ['ss', 'vendor_roster_as_doc_id',     '供應商稽核管理 · 合格供應商清冊',     'pm/vendor_audit.php'],
        ['ss', 'vendor_eval_as_doc_id',       '供應商稽核管理 · 定期評核表',         'pm/vendor_audit.php'],
        ['ss', 'vendor_plan_as_doc_id',       '供應商稽核管理 · 供應商稽核計劃',     'pm/vendor_audit.php'],
        ['ss', 'as_doc_tree_print_as_doc_id', 'AS文件審核樹 · 列印版',               'ADM/as_tree_approval_view.php'],
        ['ss', 'qc_inspection_as_doc_id',     '線上檢驗記錄表',                      'QC/inspection_entry_v2.php'],
        ['ss', 'training_as_doc_plan',        '教育訓練 · 訓練計劃表',               'ADM/training_record.php'],
        ['ss', 'training_as_doc_result',      '教育訓練 · 訓練成果表',               'ADM/training_record.php'],
        ['ss', 'training_as_doc_target',      '教育訓練 · 訓練目標表',               'ADM/training_record.php'],
        ['ss', 'training_as_doc_request',     '教育訓練 · 需求申請單',               'ADM/training_record.php'],
        ['ss', 'training_as_doc_signsheet',   '教育訓練 · 簽到表',                   'ADM/training_record.php'],
        ['sp', 'EXTERNAL_DOC|as_doc_id',      '外來文件清單',                        'Sales/external_doc_list.php'],
        ['sp', 'QUOTATION|as_doc_id',         '報價單',                              'Sales/quotation_list_NEW.php'],
    ];
    foreach ($LEGACY as $b) {
        try {
            if ($b[0] === 'ss') {
                $q = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
                $q->execute([$b[1]]);
                $val = (string)$q->fetchColumn();
            } else {
                list($grp, $key) = explode('|', $b[1]);
                $q = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
                $q->execute([$grp, $key]);
                $raw = (string)$q->fetchColumn();
                $val = (string)(json_decode($raw, true) ?? $raw);   // 有的存 JSON 有的存純數字
            }
            $put((int)$val, $b[2], $U($b[3]), 'legacy', $b[1]);
        } catch (Throwable $e) { error_log('asdoc_page_map legacy: ' . $e->getMessage()); }
    }

    // ④ 統一綁定庫 AS_DOC_BIND：整個 group 動態掃描，新模組綁定後自動出現。
    //    這裡只維護「模組代碼 → 用途／頁面」的名稱對照；忘了補也不會漏判「已綁定」，
    //    只是查不到對應頁面時不給連結（寧可不連，也不要連到錯的頁面）。
    $LABELS = [
        'meeting_signsheet'     => ['會議管理 · 簽到表',             'ADM/meeting_record.php'],
        'meeting_record'        => ['會議管理 · 會議紀錄表',         'ADM/meeting_record.php'],
        'part_process_report'   => ['零件製程報告',                  'Sales/part_process_report.php'],
        'process_report_query'  => ['製程報告查詢',                  'pm/process_report_query.php'],
        // 訂單變更做在 views/Sales/NewOrder_Track.php 裡面，但那支要帶訂單才進得去、也沒登記進選單，
        // 故一律指向選單上的入口「未交訂單」（＝與使用者從選單點進去完全一樣的路徑）
        'order_change'          => ['訂單變更 · 變更單（未交訂單內）',   '../src/store/_cleanNewOrder_Track.php'],
        'order_change_history'  => ['訂單變更 · 歷史清單（未交訂單內）', '../src/store/_cleanNewOrder_Track.php'],
        'hr_form_job_desc'      => ['職務說明書',                    'ADM/hr_position_forms.php'],
        'hr_form_skill_assess'  => ['專業技能鑑定考核表',            'ADM/hr_position_forms.php'],
        'hr_form_competency'    => ['職能鑑定表',                    'ADM/hr_position_forms.php'],
        'business_trip'         => ['公出單',                        'ADM/business_trip.php'],
        'doc_apply'             => ['文件制、修申請單',              'ADM/doc_apply.php'],
        'training_record_card'  => ['教育訓練 · 員工教育訓練紀錄卡', 'ADM/training_record.php'],
        'kpi_as'                => ['KPI 關鍵績效指標',              'news/KPI.php'],
        'project_card'          => ['專案管理 · 專案卡',             'GM/project_mgmt.php'],
        'project_plan'          => ['專案管理 · 專案計劃書',         'GM/project_mgmt.php'],
        'pfmea'                 => ['PFMEA',                         'TD/pfmea.php'],
        'td_dev_eval'           => ['產品開發評估表',                'TD/td_dev_eval.php'],
        'type_id_ctrl'          => ['型態識別文件管制表',            'TD/type_id_ctrl_doc.php'],
        'equip_machine_list'    => ['機台設備一覽表',                'pm/equipment_machine_list.php'],
        'tool_calib_plan'       => ['量測儀器校驗 · 校驗計劃表',     'QC/tool_calibration.php'],
        'tool_calib_dossier'    => ['量測儀器校驗 · 檢驗設備履歷表', 'QC/tool_calibration.php'],
        'tool_calib_equip_list' => ['檢驗設備一覽表',                'QC/tool_calibration.php'],
        'stock_req'             => ['領料需求單',                    'pages/stock.php'],
        'as_doc_quality_record_list' => ['AS 文件管理 · 品質紀錄清單', 'ADM/as_document_management.php'],
    ];
    // 「一個模板一組編號」的通用引擎：模組代碼是動態組出來的，名稱現查模板表
    $tplNames = function (string $table) use ($db) {
        $o = [];
        try { foreach ($db->query("SELECT id, name FROM `$table`") as $r) { $o[(int)$r['id']] = (string)$r['name']; } }
        catch (Throwable $e) { /* 表不存在時忽略 */ }
        return $o;
    };
    $RVF = $tplNames('rf_template');
    $FSD = $tplNames('fsd_template');
    try {
        foreach ($db->query("SELECT param_key, param_value FROM system_parameters WHERE param_group='AS_DOC_BIND'") as $r) {
            $did = (int)(json_decode((string)$r['param_value'], true) ?? $r['param_value']);
            if ($did <= 0) { continue; }
            $key = (string)$r['param_key'];
            if (preg_match('/^review_form_tpl_(\d+)$/', $key, $m)) {
                $put($did, '審核表單 · ' . ($RVF[(int)$m[1]] ?? ('模板#' . $m[1])), $U('ADM/review_form.php'), 'review_form', $key);
                continue;
            }
            if (preg_match('/^fsd_tpl_(\d+)$/', $key, $m)) {
                $put($did, '表單簽核設計器 · ' . ($FSD[(int)$m[1]] ?? ('模板#' . $m[1])), $U('ADM/form_signer.php'), 'form_signer', $key);
                continue;
            }
            $lb = $LABELS[$key] ?? null;
            if ($lb) { $put($did, $lb[0], $U($lb[1]), 'bind', $key); }
            else     { $put($did, $key, '', 'bind', $key); }   // 沒登記名稱對照＝不知道是哪一頁，不給連結
        }
    } catch (Throwable $e) { error_log('asdoc_page_map bind: ' . $e->getMessage()); }

    return $out;
}

/**
 * 這個使用者有沒有權限開這一頁（判定與左側選單 sideAndTopBarMenu.html 同一套：
 * user_module_permissions 有 page scope 或所屬群組的 group scope 任一列即可，
 * 或走新版角色機制 roles/user_roles 取得該群組模組角色）。
 * 查不到該頁面（未登記進選單的子頁，如帶參數的線上表單填寫頁）一律回 false，
 * 由呼叫端自行決定要不要給管理員例外。
 */
/**
 * 這個網址有沒有登記進選單（system_module_pages）。
 * 沒登記的多半是「帶參數才進得去的子頁」（線上表單填寫頁、總覽表列印版），
 * 它們沒有自己的選單權限，權限由開啟它的模組決定——呼叫端要自己補判斷。
 */
function eg_asdoc_page_registered(PDO $db, string $url): bool {
    if ($url === '') { return false; }
    static $c = [];
    $path = strtok($url, '?');
    if (isset($c[$path])) { return $c[$path]; }
    try {
        $st = $db->prepare("SELECT 1 FROM system_module_pages
                            WHERE (page_url IS NOT NULL AND page_url<>'' AND ? LIKE CONCAT('%', page_url))
                               OR (page_url_readonly IS NOT NULL AND page_url_readonly<>'' AND ? LIKE CONCAT('%', page_url_readonly))
                            LIMIT 1");
        $st->execute([$path, $path]);
        return $c[$path] = (bool)$st->fetchColumn();
    } catch (Throwable $e) { return $c[$path] = false; }
}

function eg_asdoc_page_can_open(PDO $db, int $uid, string $url): bool {
    if ($uid <= 0 || $url === '') { return false; }
    static $cache = [];
    $path = strtok($url, '?');
    $ck   = $uid . '|' . $path;
    if (isset($cache[$ck])) { return $cache[$ck]; }

    $ok = false;
    try {
        $st = $db->prepare("SELECT page_id, group_id FROM system_module_pages
                            WHERE (page_url IS NOT NULL AND page_url<>'' AND ? LIKE CONCAT('%', page_url))
                               OR (page_url_readonly IS NOT NULL AND page_url_readonly<>'' AND ? LIKE CONCAT('%', page_url_readonly))
                            LIMIT 1");
        $st->execute([$path, $path]);
        $pg = $st->fetch(PDO::FETCH_ASSOC);
        if ($pg) {
            $st = $db->prepare("SELECT 1 FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=? LIMIT 1");
            $st->execute([$uid, $pg['page_id']]);
            $ok = (bool)$st->fetchColumn();
            if (!$ok && !empty($pg['group_id'])) {
                $st = $db->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
                $st->execute([$pg['group_id']]);
                $gCode = (string)$st->fetchColumn();
                if ($gCode !== '') {
                    $st = $db->prepare("SELECT 1 FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=? LIMIT 1");
                    $st->execute([$uid, $gCode]);
                    $ok = (bool)$st->fetchColumn();
                    if (!$ok && function_exists('rf_has_module_role')) { $ok = (bool)rf_has_module_role($db, $uid, $gCode); }
                }
            }
        }
    } catch (Throwable $e) { error_log('asdoc_page_can_open: ' . $e->getMessage()); }
    return $cache[$ck] = $ok;
}

}
