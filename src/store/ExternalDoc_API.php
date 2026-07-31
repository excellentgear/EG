<?php
/**
 * 外來文件清單 API（AS9100 外來文件管制）
 * 資料來源：附件標籤勾了 is_external_doc 的
 *   - part_attachments（料號附件；d_id = d_setting.d_id 整數 PK）
 *   - quotation_attachments（報價附件；linked_parts 存料號字串，NULL=整張報價單料號適用）
 * 客戶＝d_setting.Customer_Id；發行日期＝附件上傳日；發行單位＝SALES_SETTING 業務單位部門。
 * AS 文件編號綁定存 system_parameters（EXTERNAL_DOC / as_doc_id）。
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']); exit;
}

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

// ── 權限：頁面 ACRUD 矩陣 OR external_doc 模組角色（仿 AS_Document_API）──────
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';
$extFeatures    = $uid ? rf_load_user_features_override($db, $uid, 'external_doc') : [];
$extIsRoleAdmin = in_array('all', $extFeatures, true);

function extdocPagePerm(PDO $db, int $uid): string {
    try {
        $st = $db->prepare("SELECT page_id, group_id FROM system_module_pages
                            WHERE page_url LIKE '%views/Sales/external_doc_list.php' LIMIT 1");
        $st->execute();
        $pg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pg) return '';
        $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $st->execute([$uid, $pg['page_id']]);
        $perms = $st->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms) && !empty($pg['group_id'])) {
            $st = $db->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $st->execute([$pg['group_id']]);
            $gCode = $st->fetchColumn();
            if ($gCode) {
                $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $st->execute([$uid, $gCode]);
                $perms = $st->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars = [];
        foreach ($perms as $p) { $chars = array_merge($chars, str_split($p)); }
        return implode('', array_unique($chars));
    } catch (Exception $e) { return ''; }
}
$extPagePerm = $uid ? extdocPagePerm($db, $uid) : '';

function extCan(string $what): bool {
    global $extFeatures, $extIsRoleAdmin, $extPagePerm;
    if ($extIsRoleAdmin || strpos($extPagePerm, 'A') !== false) return true;
    if ($what === 'view')   return strpos($extPagePerm, 'R') !== false || in_array('extdoc_view', $extFeatures, true);
    if ($what === 'manage') return in_array('extdoc_manage', $extFeatures, true);
    return false;
}

function jout($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

// ── 共用：業務單位（發行單位）部門名稱（同 Sales_Track 的 SALES_SETTING）────
function extdoc_issue_unit(PDO $db): string {
    try {
        $st = $db->query("SELECT param_value FROM system_parameters WHERE param_group='SALES_SETTING' AND param_key='sales_unit_id' LIMIT 1");
        $v = json_decode((string)$st->fetchColumn(), true);
        $deptId = (int)($v['id'] ?? 0);
        if (!$deptId) return '';
        $st = $db->prepare("SELECT name FROM department WHERE id=?");
        $st->execute([$deptId]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Exception $e) { return ''; }
}

// ── 共用：本公司名稱（列印大標題統一來源：customer_list.is_own_company=1 的 customer_full 客戶全名發票用）──
function extdoc_company_name(PDO $db): string {
    try {
        $st = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) { $n = trim((string)($r['customer_full'] ?: $r['customer'])); if ($n !== '') return $n; }
    } catch (Throwable $e) {}
    return '超正齒輪科技有限公司';
}

// ── 共用：AS 文件編號綁定 ─────────────────────────────────────────────
function extdoc_bound_asdoc(PDO $db): ?array {
    try {
        $st = $db->query("SELECT param_value FROM system_parameters WHERE param_group='EXTERNAL_DOC' AND param_key='as_doc_id' LIMIT 1");
        $docId = (int)json_decode((string)$st->fetchColumn(), true);
        if (!$docId) return null;
        $st = $db->prepare("SELECT id, doc_no, doc_name, current_version FROM as_document WHERE id=? AND is_deleted=0");
        $st->execute([$docId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) { return null; }
}

// ── 共用：外來文件標籤（含已停用標籤，歷史附件仍算數）─────────────────
function extdoc_categories(PDO $db): array {
    $rows = $db->query("SELECT id, category_name, COALESCE(external_doc_name,'') AS external_doc_name, is_active
                        FROM quotation_file_categories WHERE is_external_doc=1 ORDER BY sort_order, id")
               ->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['id']] = [
            'display' => $r['external_doc_name'] !== '' ? $r['external_doc_name'] : $r['category_name'],
            'tag'     => $r['category_name'],
        ];
    }
    return $map;
}

/**
 * 撈出全部符合條件的外來文件列（兩來源合併，PHP 端整理）
 * $opt: mode('bound'|'all'), customer_id(''=全部), year(0=全部)
 */
function extdoc_fetch_rows(PDO $db, array $opt): array {
    $cats = extdoc_categories($db);
    if (!$cats) return [];
    $catIds = array_keys($cats);

    $mode     = ($opt['mode'] ?? 'all') === 'bound' ? 'bound' : 'all';
    $custId   = trim((string)($opt['customer_id'] ?? ''));
    $year     = (int)($opt['year'] ?? 0);
    $catId    = (int)($opt['category'] ?? 0);   // 外來文件類別篩選（quotation_file_categories.id，0=全部）

    // 標籤條件（category_ids 為逗號分隔字串，去空白後 FIND_IN_SET）
    $catCond = function(string $col, string $singleCol = '') use ($catIds): string {
        $parts = [];
        foreach ($catIds as $cid) $parts[] = "FIND_IN_SET($cid, REPLACE(COALESCE($col,''),' ',''))";
        if ($singleCol !== '') $parts[] = "$singleCol IN (" . implode(',', $catIds) . ")";
        return '(' . implode(' OR ', $parts) . ')';
    };
    $boundCond = "EXISTS (SELECT 1 FROM order_track ot WHERE ot.d_id_ID = ds.d_id)";

    $rows = [];

    // ① 料號附件
    $sql = "SELECT pa.id AS attach_id, ds.d_id AS ds_pk, ds.D_Setting_Id AS part_no,
                   ds.Customer_Id AS customer_id, COALESCE(cl.customer,'') AS customer_name,
                   COALESCE(NULLIF(pa.original_name,''), pa.filename) AS doc_name,
                   pa.category_ids, '' AS category_id_single, pa.uploaded_at,
                   COALESCE(u.user_cname, pa.uploaded_by, '') AS uploaded_by, '' AS quote_no, '' AS quote_client
            FROM part_attachments pa
            JOIN d_setting ds ON ds.d_id = pa.d_id
            LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
            LEFT JOIN user u ON u.id = pa.uploaded_by_id
            WHERE pa.deleted_at IS NULL AND " . $catCond('pa.category_ids');
    $args = [];
    if ($year)          { $sql .= " AND YEAR(pa.uploaded_at) = ?"; $args[] = $year; }
    if ($custId !== '') { $sql .= " AND ds.Customer_Id = ?";       $args[] = $custId; }
    if ($mode === 'bound') $sql .= " AND $boundCond";
    $st = $db->prepare($sql); $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['source'] = 'part'; $rows[] = $r; }

    // ② 報價附件（linked_parts NULL＝整張報價單的料號都適用；以 quotation_item 展開，d_setting_d_id 為整數 PK）
    $where = ["a.status='active'", "a.linked_parts IS NULL", $catCond('a.category_ids', 'a.category_id')];
    $args  = [];
    if ($year)          { $where[] = "YEAR(a.uploaded_at) = ?"; $args[] = $year; }
    if ($custId !== '') { $where[] = "ds.Customer_Id = ?";      $args[] = $custId; }
    if ($mode === 'bound') $where[] = $boundCond;
    // ANY_VALUE：only_full_group_by 下，JOIN 出來的非鍵值欄位（同組內值必相同）需明確標示
    $sql = "SELECT a.id AS attach_id, ds.d_id AS ds_pk, ds.D_Setting_Id AS part_no,
                   ds.Customer_Id AS customer_id, ANY_VALUE(COALESCE(cl.customer,'')) AS customer_name,
                   COALESCE(NULLIF(a.original_name,''), a.filename) AS doc_name,
                   a.category_ids, COALESCE(a.category_id,'') AS category_id_single, a.uploaded_at,
                   ANY_VALUE(COALESCE(u.user_cname, a.uploaded_by, '')) AS uploaded_by, a.quote_no,
                   ANY_VALUE(ql.client_id) AS quote_client
            FROM quotation_attachments a
            JOIN quotation_list ql ON ql.quote_no = a.quote_no
            JOIN quotation_item qi ON qi.quote_id = ql.quote_id
            JOIN d_setting ds ON ds.d_id = qi.d_setting_d_id
            LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
            LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
            WHERE " . implode(' AND ', $where) . "
            GROUP BY a.id, ds.d_id";
    $st = $db->prepare($sql); $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['source'] = 'quote'; $rows[] = $r; }

    // ③ 報價附件（linked_parts 指定料號字串；同料號字串可能多筆 d_setting——先全撈，PHP 端優先取「客戶＝報價單客戶」那筆）
    $sql = "SELECT a.id AS attach_id, ds.d_id AS ds_pk, ds.D_Setting_Id AS part_no,
                   ds.Customer_Id AS customer_id, COALESCE(cl.customer,'') AS customer_name,
                   COALESCE(NULLIF(a.original_name,''), a.filename) AS doc_name,
                   a.category_ids, COALESCE(a.category_id,'') AS category_id_single, a.uploaded_at,
                   COALESCE(u.user_cname, a.uploaded_by, '') AS uploaded_by, a.quote_no, ql.client_id AS quote_client
            FROM quotation_attachments a
            JOIN quotation_list ql ON ql.quote_no = a.quote_no
            JOIN d_setting ds ON JSON_CONTAINS(a.linked_parts, JSON_QUOTE(ds.D_Setting_Id))
            LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
            LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
            WHERE a.status='active' AND a.linked_parts IS NOT NULL AND " . $catCond('a.category_ids', 'a.category_id');
    $args = [];
    if ($year) { $sql .= " AND YEAR(a.uploaded_at) = ?"; $args[] = $year; }
    if ($mode === 'bound') $sql .= " AND $boundCond";
    $st = $db->prepare($sql); $st->execute($args);
    $linkedRaw = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['attach_id'] . '|' . $r['part_no'];
        // 同附件×同料號字串可能對到多筆 d_setting（不同客戶）：客戶符合報價單客戶者優先
        if (!isset($linkedRaw[$key])) {
            $linkedRaw[$key] = $r;
        } elseif ($r['customer_id'] === $r['quote_client']
                  && $linkedRaw[$key]['customer_id'] !== $linkedRaw[$key]['quote_client']) {
            $linkedRaw[$key] = $r;
        }
    }
    foreach ($linkedRaw as $r) {
        if ($custId !== '' && $r['customer_id'] !== $custId) continue;
        $r['source'] = 'quote'; $rows[] = $r;
    }

    // 整理：類別顯示名稱（只列外來文件標籤）、日期、排序
    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $dedupKey = $r['source'] . '|' . $r['attach_id'] . '|' . $r['ds_pk'];
        if (isset($seen[$dedupKey])) continue;
        $seen[$dedupKey] = 1;
        $names = [];
        $idsCsv = str_replace(' ', '', (string)$r['category_ids']);
        foreach (array_filter(explode(',', $idsCsv)) as $cid) {
            $cid = (int)$cid;
            if (isset($cats[$cid])) $names[$cid] = $cats[$cid]['display'];
        }
        if (!$names && $r['category_id_single'] !== '' && isset($cats[(int)$r['category_id_single']])) {
            $names[(int)$r['category_id_single']] = $cats[(int)$r['category_id_single']]['display'];
        }
        if ($catId && !isset($names[$catId])) continue;   // 類別篩選（在完整資料上過濾）
        $out[] = [
            'source'        => $r['source'],
            'customer_id'   => (string)$r['customer_id'],
            'customer_name' => $r['customer_name'] !== '' ? $r['customer_name'] : (string)$r['customer_id'],
            'part_no'       => $r['part_no'],
            'doc_name'      => $r['doc_name'],
            'doc_date'      => substr((string)$r['uploaded_at'], 0, 10),
            'categories'    => array_values($names),
            'category_ids'  => array_keys($names),
            'uploaded_by'   => $r['uploaded_by'],
            'quote_no'      => $r['quote_no'],
        ];
    }
    usort($out, function($a, $b) {
        return [$a['customer_name'], $a['part_no'], $a['doc_date']] <=> [$b['customer_name'], $b['part_no'], $b['doc_date']];
    });
    return $out;
}

// ════════════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'get_options':
    if (!extCan('view')) jout(['success'=>false,'message'=>'無檢閱權限']);
    // 客戶下拉：只列「實際出現在外來文件清單」的客戶（依全部模式抓一次，不分年度）
    $all = extdoc_fetch_rows($db, ['mode'=>'all']);
    $custs = []; $years = []; $presentCats = [];
    foreach ($all as $r) {
        if ($r['customer_id'] !== '') $custs[$r['customer_id']] = $r['customer_name'];
        $y = (int)substr($r['doc_date'], 0, 4);
        if ($y) $years[$y] = 1;
        foreach ($r['category_ids'] as $cid) $presentCats[$cid] = 1;
    }
    asort($custs); krsort($years);
    // 類別篩選鈕：只回傳「實際出現在外來文件內」的類別（依標籤設定順序）
    $catList = [];
    foreach (extdoc_categories($db) as $cid => $c) {
        if (isset($presentCats[$cid])) $catList[] = ['id'=>$cid, 'name'=>$c['display']];
    }
    $asDocs = [];
    if (extCan('manage')) {
        try {
            $asDocs = $db->query("SELECT id, doc_no, doc_name FROM as_document WHERE is_deleted=0 ORDER BY doc_no")
                         ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
    jout([
        'success'    => true,
        'customers'  => array_map(fn($id) => ['customer_id'=>$id, 'customer'=>$custs[$id]], array_keys($custs)),
        'years'      => array_keys($years),
        'categories' => $catList,
        'company_name' => extdoc_company_name($db),
        'issue_unit' => extdoc_issue_unit($db),
        'as_doc'     => extdoc_bound_asdoc($db),
        'as_docs'    => $asDocs,
        'can_manage' => extCan('manage'),
    ]);

case 'get_list':
    if (!extCan('view')) jout(['success'=>false,'message'=>'無檢閱權限']);
    $rows = extdoc_fetch_rows($db, [
        'mode'        => $_POST['mode'] ?? 'all',
        'customer_id' => $_POST['customer_id'] ?? '',
        'year'        => (int)($_POST['year'] ?? 0),
        'category'    => (int)($_POST['category'] ?? 0),
    ]);
    $total   = count($rows);
    $perPage = max(0, (int)($_POST['per_page'] ?? 10));
    $page    = max(1, (int)($_POST['page'] ?? 1));
    $paged   = $perPage > 0 ? array_slice($rows, ($page - 1) * $perPage, $perPage) : $rows;
    jout([
        'success'    => true,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'rows'       => $paged,
        'issue_unit' => extdoc_issue_unit($db),
        'as_doc'     => extdoc_bound_asdoc($db),
    ]);

case 'get_print':
    // 列印：全部資料依客戶分組
    if (!extCan('view')) jout(['success'=>false,'message'=>'無檢閱權限']);
    $rows = extdoc_fetch_rows($db, [
        'mode'        => $_POST['mode'] ?? 'all',
        'customer_id' => $_POST['customer_id'] ?? '',
        'year'        => (int)($_POST['year'] ?? 0),
        'category'    => (int)($_POST['category'] ?? 0),
    ]);
    $groups = [];
    foreach ($rows as $r) {
        $key = $r['customer_id'] !== '' ? $r['customer_id'] : '(未設定客戶)';
        if (!isset($groups[$key])) $groups[$key] = ['customer_id'=>$r['customer_id'], 'customer_name'=>$r['customer_name'], 'rows'=>[]];
        $groups[$key]['rows'][] = $r;
    }
    jout([
        'success'      => true,
        'groups'       => array_values($groups),
        'total'        => count($rows),
        'issue_unit'   => extdoc_issue_unit($db),
        'as_doc'       => extdoc_bound_asdoc($db),
        'company_name' => extdoc_company_name($db),
    ]);

case 'export_csv':
    if (!extCan('view')) { http_response_code(403); exit('無檢閱權限'); }
    $rows = extdoc_fetch_rows($db, [
        'mode'        => $_GET['mode'] ?? 'all',
        'customer_id' => $_GET['customer_id'] ?? '',
        'year'        => (int)($_GET['year'] ?? 0),
        'category'    => (int)($_GET['category'] ?? 0),
    ]);
    $unit = extdoc_issue_unit($db);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="external_doc_list_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";  // UTF-8 BOM（Excel 相容）
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['客戶', '料號', '文件名稱', '外來文件類別', '發行日期', '發行單位', '來源', '報價單號']);
    foreach ($rows as $r) {
        fputcsv($fp, [
            $r['customer_name'], $r['part_no'], $r['doc_name'],
            implode('、', $r['categories']), $r['doc_date'], $unit,
            $r['source'] === 'part' ? '料號附件' : '報價附件', $r['quote_no'],
        ]);
    }
    fclose($fp);
    exit;

case 'save_as_doc':
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理設定權限']);
    $docId = (int)($_POST['as_doc_id'] ?? 0);
    if ($docId) {
        $st = $db->prepare("SELECT COUNT(*) FROM as_document WHERE id=? AND is_deleted=0");
        $st->execute([$docId]);
        if (!$st->fetchColumn()) jout(['success'=>false,'message'=>'AS 文件不存在或已刪除']);
    }
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group='EXTERNAL_DOC' AND param_key='as_doc_id' LIMIT 1");
    $st->execute();
    $pid = $st->fetchColumn();
    $opName = (string)($_SESSION['userName'] ?? '');
    if ($pid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")
           ->execute([json_encode($docId), $opName, $pid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by)
                      VALUES ('EXTERNAL_DOC', 'as_doc_id', ?, '外來文件清單綁定的 AS 文件 id（0=未綁定）', ?)")
           ->execute([json_encode($docId), $opName]);
    }
    jout(['success'=>true, 'message'=>$docId ? '已綁定' : '已解除綁定', 'as_doc'=>extdoc_bound_asdoc($db)]);

default:
    jout(['success'=>false,'message'=>'未知的 action']);
}
