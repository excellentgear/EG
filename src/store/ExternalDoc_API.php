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
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';   // eg_fmt_date()：日期顯示 YYYY.MM.DD（ai-rules/20）

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

// ── 共用：AS 文件編號綁定（doc_no 已依 eg_asdoc_no() 規則附加版次，僅四階文件，見 ai-rules/16 第三節） ──
function extdoc_bound_asdoc(PDO $db): ?array {
    try {
        $st = $db->query("SELECT param_value FROM system_parameters WHERE param_group='EXTERNAL_DOC' AND param_key='as_doc_id' LIMIT 1");
        $docId = (int)json_decode((string)$st->fetchColumn(), true);
        if (!$docId) return null;
        $st = $db->prepare("SELECT id, doc_no, doc_name, current_version, doc_level FROM as_document WHERE id=? AND is_deleted=0");
        $st->execute([$docId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['doc_no'] = eg_asdoc_no($row);
        return $row;
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

// ── 共用：PFMEA（views/TD/pfmea.php）已建立的料號對照 ────────────────────
// 以 d_setting 主鍵(part_d_id) 為主；PFMEA 允許手打料號字串(part_no_text)，故另備字串對照。
// 回傳 ['by_id'=>[d_id=>doc_no], 'by_no'=>[大寫去空白料號=>doc_no]]（同料號多份取最新一份）
function extdoc_pfmea_map(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $byId = []; $byNo = [];
    try {
        $rows = $db->query("SELECT doc_no, part_d_id, part_no_text FROM pfmea_doc
                            WHERE is_deleted=0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!empty($r['part_d_id'])) $byId[(int)$r['part_d_id']] = (string)$r['doc_no'];
            $txt = strtoupper(str_replace(' ', '', trim((string)$r['part_no_text'])));
            if ($txt !== '') $byNo[$txt] = (string)$r['doc_no'];
        }
    } catch (Exception $e) {}   // PFMEA 模組未建表時視同沒有任何 PFMEA
    $cache = ['by_id' => $byId, 'by_no' => $byNo];
    return $cache;
}
// 單一列（料號主鍵＋料號字串）是否有 PFMEA，回傳 PFMEA 文件編號或 ''
function extdoc_pfmea_of(array $map, $dsPk, string $partNo): string {
    $dsPk = (int)$dsPk;
    if ($dsPk && isset($map['by_id'][$dsPk])) return $map['by_id'][$dsPk];
    $txt = strtoupper(str_replace(' ', '', trim($partNo)));
    return ($txt !== '' && isset($map['by_no'][$txt])) ? $map['by_no'][$txt] : '';
}

// ── 共用：料號模糊搜尋 ─────────────────────────────────────────────
// 多個關鍵字以空白分隔＝每個都要命中；比對前一律轉大寫並去掉空白（料號常被打成 rc105 n03）
function extdoc_kw_terms(string $kw): array {
    $kw = trim(str_replace("　", ' ', $kw));   // 全形空白視同空白
    if ($kw === '') return [];
    $out = [];
    foreach (preg_split('/\s+/', $kw) as $t) {
        $t = strtoupper(str_replace(' ', '', $t));
        if ($t !== '') $out[] = $t;
    }
    return $out;
}
function extdoc_kw_match(array $terms, string $val): bool {
    if (!$terms) return true;
    $v = strtoupper(str_replace(' ', '', $val));
    foreach ($terms as $t) if (strpos($v, $t) === false) return false;
    return true;
}

/**
 * 撈出全部符合條件的外來文件列（兩來源合併，PHP 端整理）
 * $opt: mode('bound'|'all'), customer_id(''=全部), year(0=全部), pfmea(''|'yes'|'no'), part_kw(料號模糊搜尋)
 */
function extdoc_fetch_rows(PDO $db, array $opt): array {
 // 同一次請求內同條件只算一次（一次頁面載入會問到 4 次：清單、選項、待補計數、不列入計數）
 static $memo = [];
 $key = json_encode([$opt['mode'] ?? 'all', (string)($opt['customer_id'] ?? ''), (int)($opt['year'] ?? 0),
                        (int)($opt['category'] ?? 0), $opt['show'] ?? 'active', $opt['pfmea'] ?? '',
                        (string)($opt['part_kw'] ?? ''), !empty($opt['show_history'])]);
 if (isset($memo[$key])) return $memo[$key];
 return $memo[$key] = extdoc_fetch_rows_raw($db, $opt);
}
function extdoc_fetch_rows_raw(PDO $db, array $opt): array {
    $cats = extdoc_categories($db);
    if (!$cats) return [];
    $catIds = array_keys($cats);

    $mode     = ($opt['mode'] ?? 'all') === 'bound' ? 'bound' : 'all';
    $custId   = trim((string)($opt['customer_id'] ?? ''));
    $year     = (int)($opt['year'] ?? 0);
    $catId    = (int)($opt['category'] ?? 0);   // 外來文件類別篩選（quotation_file_categories.id，0=全部）
    $show     = ($opt['show'] ?? 'active') === 'excluded' ? 'excluded' : 'active';   // excluded=只看已排除
    $pfmeaF   = in_array(($opt['pfmea'] ?? ''), ['yes','no'], true) ? $opt['pfmea'] : '';  // PFMEA 建立與否篩選
    $partKw   = extdoc_kw_terms((string)($opt['part_kw'] ?? ''));   // 料號模糊搜尋（多關鍵字全部命中才算）
    $showHist = !empty($opt['show_history']);   // 顯示歷史版本（預設只列現行版）
    $pfmeaMap = extdoc_pfmea_map($db);

    // 排除清單（附件×料號為單位）：active 檢視要跳過、excluded 檢視只留這些
    $excludes = [];
    try {
        foreach ($db->query("SELECT source, attach_id, ds_pk, excluded_by, excluded_at FROM external_doc_exclude")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $excludes[$e['source'].'|'.$e['attach_id'].'|'.$e['ds_pk']] = $e;
        }
    } catch (Exception $e) {}

    // 料號附件檔案 URL 根（鐵律5：即時組路徑，DB 只存檔名）
    $urlBase = '';
    try {
        $urlBase = rtrim((string)$db->query("SELECT setting_value FROM system_settings WHERE setting_key='part_attach_url_dir'")->fetchColumn(), '/\\');
    } catch (Exception $e) {}

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
                   pa.filename, COALESCE(pa.note,'') AS note,
                   pa.category_ids, '' AS category_id_single, pa.uploaded_at,
                   COALESCE(u.user_cname, pa.uploaded_by, '') AS uploaded_by, '' AS quote_no, '' AS quote_client
            FROM part_attachments pa
            JOIN d_setting ds ON ds.d_id = pa.d_id
            LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
            LEFT JOIN user u ON u.id = pa.uploaded_by_id
            WHERE pa.deleted_at IS NULL AND " . $catCond('pa.category_ids');
    $args = [];
    if ($custId !== '') { $sql .= " AND ds.Customer_Id = ?";       $args[] = $custId; }
    if ($mode === 'bound') $sql .= " AND $boundCond";
    $st = $db->prepare($sql); $st->execute($args);
    // 批圖工作檔(.egwork.json)不是文件、也打不開，一律不入管制清單。
    // 目前它們沒有標籤所以本來就不會被 $catCond 撈到，但附件跳窗可以編輯標籤，加一道保險。
    require_once __DIR__ . '/../common/imgedit_visibility.php';
    foreach (imgedit_strip_workfiles($st->fetchAll(PDO::FETCH_ASSOC), $db) as $r) { $r['source'] = 'part'; $rows[] = $r; }

    // ② 報價附件（linked_parts NULL＝整張報價單的料號都適用；以 quotation_item 展開，d_setting_d_id 為整數 PK）
    $where = ["a.status='active'", "a.linked_parts IS NULL", $catCond('a.category_ids', 'a.category_id')];
    $args  = [];
    if ($custId !== '') { $where[] = "ds.Customer_Id = ?";      $args[] = $custId; }
    if ($mode === 'bound') $where[] = $boundCond;
    // ANY_VALUE：only_full_group_by 下，JOIN 出來的非鍵值欄位（同組內值必相同）需明確標示
    $sql = "SELECT a.id AS attach_id, ds.d_id AS ds_pk, ds.D_Setting_Id AS part_no,
                   ds.Customer_Id AS customer_id, ANY_VALUE(COALESCE(cl.customer,'')) AS customer_name,
                   COALESCE(NULLIF(a.original_name,''), a.filename) AS doc_name,
                   a.filename, COALESCE(a.note,'') AS note,
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
    //   原本 JOIN d_setting ON JSON_CONTAINS(...)＝每筆報價附件都要拿全部料號逐一比 JSON（索引完全用不到），
    //   實測單這一句 1.86 秒、一次頁面載入問 4 次就是 7 秒的「載入中」。改成先撈附件、PHP 解出 linked_parts 的
    //   料號字串，再用 IN (...) 一次查 d_setting（走索引），最後在 PHP 端對回去；比對維持嚴格字串相等，
    //   語意與原本的 JSON_CONTAINS 相同（大小寫/尾空白不同的料號不會被多撈進來）。
    $sql = "SELECT a.id AS attach_id,
                   COALESCE(NULLIF(a.original_name,''), a.filename) AS doc_name,
                   a.filename, COALESCE(a.note,'') AS note, a.linked_parts,
                   a.category_ids, COALESCE(a.category_id,'') AS category_id_single, a.uploaded_at,
                   COALESCE(u.user_cname, a.uploaded_by, '') AS uploaded_by, a.quote_no, ql.client_id AS quote_client
            FROM quotation_attachments a
            JOIN quotation_list ql ON ql.quote_no = a.quote_no
            LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
            WHERE a.status='active' AND a.linked_parts IS NOT NULL AND " . $catCond('a.category_ids', 'a.category_id');
    $args = [];
    $st = $db->prepare($sql); $st->execute($args);
    $linkAtt = $st->fetchAll(PDO::FETCH_ASSOC);

    $wantNo = [];   // 大寫去空白 => 原字串（查 d_setting 用）
    foreach ($linkAtt as $i => $a) {
        $lp = json_decode((string)$a['linked_parts'], true);
        $ps = [];
        if (is_array($lp)) {
            foreach ($lp as $p) {
                if (!is_scalar($p)) continue;
                $p = (string)$p;
                if ($p !== '') { $ps[] = $p; $wantNo[strtoupper(trim($p))] = $p; }
            }
        }
        $linkAtt[$i]['_parts'] = array_values(array_unique($ps));
    }
    $dsByNo = [];   // 大寫去空白料號 => 該料號的 d_setting 候選列（同料號可能多客戶）
    foreach (array_chunk(array_values($wantNo), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $q  = "SELECT ds.d_id, ds.D_Setting_Id, ds.Customer_Id, COALESCE(cl.customer,'') AS customer_name
               FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
               WHERE ds.D_Setting_Id IN ($ph)";
        if ($mode === 'bound') $q .= " AND $boundCond";
        $s2 = $db->prepare($q); $s2->execute($chunk);
        foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $d) $dsByNo[strtoupper(trim((string)$d['D_Setting_Id']))][] = $d;
    }

    $linkedRaw = [];
    foreach ($linkAtt as $a) {
        foreach ($a['_parts'] as $pno) {
            foreach ($dsByNo[strtoupper(trim($pno))] ?? [] as $d) {
                if ((string)$d['D_Setting_Id'] !== $pno) continue;   // 嚴格相等＝原 JSON_CONTAINS 的比對語意
                $r = [
                    'attach_id'          => $a['attach_id'],
                    'ds_pk'              => $d['d_id'],
                    'part_no'            => $d['D_Setting_Id'],
                    'customer_id'        => $d['Customer_Id'],
                    'customer_name'      => $d['customer_name'],
                    'doc_name'           => $a['doc_name'],
                    'filename'           => $a['filename'],
                    'note'               => $a['note'],
                    'category_ids'       => $a['category_ids'],
                    'category_id_single' => $a['category_id_single'],
                    'uploaded_at'        => $a['uploaded_at'],
                    'uploaded_by'        => $a['uploaded_by'],
                    'quote_no'           => $a['quote_no'],
                    'quote_client'       => $a['quote_client'],
                ];
                $key = $r['attach_id'] . '|' . $r['part_no'];
                // 同附件×同料號字串可能對到多筆 d_setting（不同客戶）：客戶符合報價單客戶者優先
                if (!isset($linkedRaw[$key])) {
                    $linkedRaw[$key] = $r;
                } elseif ($r['customer_id'] === $r['quote_client']
                          && $linkedRaw[$key]['customer_id'] !== $linkedRaw[$key]['quote_client']) {
                    $linkedRaw[$key] = $r;
                }
            }
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
        if (!extdoc_kw_match($partKw, (string)$r['part_no'])) continue;   // 料號模糊搜尋
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
        $exKey = $r['source'].'|'.$r['attach_id'].'|'.$r['ds_pk'];
        $isExcluded = isset($excludes[$exKey]);
        // PFMEA（views/TD/pfmea.php）是否已針對此料號建立
        $pfmeaNo = extdoc_pfmea_of($pfmeaMap, $r['ds_pk'], (string)$r['part_no']);
        // 檔案連結：兩種來源都走各自的下載 API（鐵律5：不設 URL 前綴讓瀏覽器直連，
        // 附件位置只由 *_nas_dir 設定決定，換 NAS 免改 httpd.conf 也不綁磁碟機代號）
        $fileUrl = $r['source'] === 'part'
            ? '../../src/store/Part_Attachment_API.php?action=download&id='.(int)$r['attach_id']
            : '../../src/store/Quotation_File_API.php?action=download&quote_no='.rawurlencode($r['quote_no']).'&filename='.rawurlencode($r['filename']);
        $out[] = [
            'source'        => $r['source'],
            'attach_id'     => (int)$r['attach_id'],
            'ds_pk'         => (int)$r['ds_pk'],
            'customer_id'   => (string)$r['customer_id'],
            'customer_name' => $r['customer_name'] !== '' ? $r['customer_name'] : (string)$r['customer_id'],
            'part_no'       => $r['part_no'],
            'doc_name'      => $r['doc_name'],
            'doc_date'      => substr((string)$r['uploaded_at'], 0, 10),
            'categories'    => array_values($names),
            'category_ids'  => array_keys($names),
            'uploaded_by'   => $r['uploaded_by'],
            'quote_no'      => $r['quote_no'],
            'note'          => (string)$r['note'],
            'file_url'      => $fileUrl,
            'has_pfmea'     => $pfmeaNo !== '',
            'pfmea_doc_no'  => $pfmeaNo,
            'excluded_by'   => $isExcluded ? (string)($excludes[$exKey]['excluded_by'] ?? '') : '',
            'excluded_at'   => $isExcluded ? substr((string)($excludes[$exKey]['excluded_at'] ?? ''), 0, 16) : '',
            '_excluded'     => $isExcluded,
        ];
    }

    // ── 版本判定（同料號＋同類別只留最新版）────────────────────────────
    // 一定要在年度/類別/PFMEA 篩選「之前」算，否則篩到 2025 年時舊版會變成該範圍內的最新版。
    extdoc_mark_versions($db, $out);

    // ── 篩選（版本判定完才套用）──────────────────────────────────────
    $final = [];
    foreach ($out as $r) {
        if ($show === 'excluded' ? !$r['_excluded'] : $r['_excluded']) continue;
        if ($catId && !in_array($catId, $r['category_ids'], true)) continue;
        if ($year && (int)substr($r['doc_date'], 0, 4) !== $year) continue;
        if ($pfmeaF === 'yes' && !$r['has_pfmea']) continue;
        if ($pfmeaF === 'no'  &&  $r['has_pfmea']) continue;
        // 舊版預設不列（清單/列印/CSV 一致）；show_history=1 才一起帶出來，由前端標「舊版」
        if (!$showHist && $show !== 'excluded' && $r['ver_state'] === 'old') continue;
        unset($r['_excluded']);
        $final[] = $r;
    }
    usort($final, function($a, $b) {
        return [$a['customer_name'], $a['part_no'], $a['doc_date']] <=> [$b['customer_name'], $b['part_no'], $b['doc_date']];
    });
    return $final;
}

// ── 版本判定：同一料號＋同一外來文件類別為一組，只有最新版是現行版 ──────────
// 使用者拍板（2026-08-20）：
//   ① 分組鍵＝料號 × 類別標籤（一列有多個標籤就同時屬於多組；只要在任一組是現行版就顯示）
//   ② 同一天上傳的多筆視同同一版全部保留（多頁掃描不會被吃掉一頁）
//   ③ 人工覆寫優先於日期：pin=釘選現行版、keep_all=該組不做版本判定（同標籤下本來就是不同文件）
//   ④ 已排除(external_doc_exclude)的列不參與判定，也不會把別人擠成舊版
function extdoc_version_overrides(PDO $db, bool $fresh = false): array {
    static $cache = null;
    if ($fresh) $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach ($db->query("SELECT ds_pk, cat_id, kind, source, attach_id FROM external_doc_version_override")
                    ->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $cache[(int)$o['ds_pk'] . '|' . (int)$o['cat_id']] = $o;
        }
    } catch (Exception $e) {}   // 尚未跑 migration 時視同沒有任何覆寫（純自動判定）
    return $cache;
}

// 版本狀態的文字標示（CSV／列印共用）
function extdoc_ver_label(array $r): string {
    if (($r['ver_state'] ?? 'current') === 'old')
        return '舊版' . ($r['ver_superseded'] ? '（已由 ' . eg_fmt_date($r['ver_superseded']) . ' 版取代）' : '');
    if (!empty($r['ver_keep_all'])) return '並存（不判定版本）';
    if (!empty($r['ver_pinned']))   return '現行版（釘選）';
    return (int)($r['ver_total'] ?? 1) > 1 ? '現行版（最新）' : '現行版';
}

function extdoc_mark_versions(PDO $db, array &$rows): void {
    $ov = extdoc_version_overrides($db);
    // 先建組
    $grp = [];
    foreach ($rows as $i => $r) {
        if ($r['_excluded']) continue;
        foreach ($r['category_ids'] as $cid) $grp[$r['ds_pk'] . '|' . (int)$cid][] = $i;
    }
    // 預設值（沒有類別的列、已排除的列都當現行版，不會被自動隱藏）
    foreach ($rows as $i => $r) {
        $rows[$i]['ver_state']     = 'current';
        $rows[$i]['ver_by']        = '';       // pin / keep_all / date
        $rows[$i]['ver_total']     = 1;        // 同組（含自己）共幾筆，1＝沒有版本問題
        $rows[$i]['ver_superseded']= '';       // 舊版時＝取代它的那一版的發行日期
        $rows[$i]['ver_pinned']    = false;
        $rows[$i]['ver_keep_all']  = false;
        $rows[$i]['_cur']          = $r['_excluded'] || empty($r['category_ids']);
        $rows[$i]['_sup']          = [];
        $rows[$i]['_grpn']         = 1;
    }
    foreach ($grp as $key => $idxs) {
        $o = $ov[$key] ?? null;
        $n = count($idxs);
        foreach ($idxs as $i) $rows[$i]['_grpn'] = max($rows[$i]['_grpn'], $n);
        // ③-a 該組不做版本判定：全部保留
        if ($o && $o['kind'] === 'keep_all') {
            foreach ($idxs as $i) { $rows[$i]['_cur'] = true; $rows[$i]['ver_keep_all'] = true; if ($rows[$i]['ver_by'] !== 'pin') $rows[$i]['ver_by'] = 'keep_all'; }
            continue;
        }
        // ③-b 釘選：指定的那一筆是現行版（找不到＝該附件已被刪，退回日期判定）
        $pin = null;
        if ($o && $o['kind'] === 'pin') {
            foreach ($idxs as $i) {
                if ($rows[$i]['source'] === $o['source'] && (int)$rows[$i]['attach_id'] === (int)$o['attach_id']) { $pin = $i; break; }
            }
        }
        if ($pin !== null) {
            $rows[$pin]['_cur'] = true; $rows[$pin]['ver_pinned'] = true; $rows[$pin]['ver_by'] = 'pin';
            foreach ($idxs as $i) if ($i !== $pin) $rows[$i]['_sup'][] = $rows[$pin]['doc_date'];
            continue;
        }
        // ② 自動：發行日期最新者（同日全留）
        $max = '';
        foreach ($idxs as $i) if ($rows[$i]['doc_date'] > $max) $max = $rows[$i]['doc_date'];
        foreach ($idxs as $i) {
            if ($rows[$i]['doc_date'] === $max) { $rows[$i]['_cur'] = true; if ($rows[$i]['ver_by'] === '') $rows[$i]['ver_by'] = 'date'; }
            else $rows[$i]['_sup'][] = $max;
        }
    }
    foreach ($rows as $i => $r) {
        $rows[$i]['ver_state'] = $r['_cur'] ? 'current' : 'old';
        $rows[$i]['ver_total'] = (int)$r['_grpn'];
        if (!$r['_cur'] && $r['_sup']) { rsort($r['_sup']); $rows[$i]['ver_superseded'] = $r['_sup'][0]; }
        unset($rows[$i]['_cur'], $rows[$i]['_sup'], $rows[$i]['_grpn']);
    }
}

// ── 待補檔案項目（external_doc_pending）─────────────────────────────
// 「PFMEA 已建立、但外來文件清單一列都沒有」的料號，由偵測建立成待補項目；
// 補上傳附件後轉 status=done，該附件本身即成為正式清單的一列。
// ── 共用：目前正式清單裡「已經有外來文件」的料號集合（ds_pk => 最新一筆來源）────
// 一次算好給待補自動結案與 PFMEA 缺件偵測共用（同一次請求內快取，避免重複掃全部附件）
function extdoc_parts_with_doc(PDO $db, bool $fresh = false): array {
    static $cache = null;
    if ($fresh) $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (extdoc_fetch_rows($db, ['mode' => 'all']) as $r) {
        $pk = (int)$r['ds_pk'];
        if (!isset($cache[$pk]) || $r['doc_date'] >= $cache[$pk]['doc_date']) {
            $cache[$pk] = ['doc_date' => $r['doc_date'], 'source' => $r['source'], 'attach_id' => (int)$r['attach_id']];
        }
    }
    return $cache;
}

/**
 * 待補項目自動結案：料號只要已經有外來文件（不論從哪個入口上傳），待補項目就標記為已補。
 * 走型態識別文件管制表／主檔管理／報價單上傳的檔案不會經過本頁的「上傳補檔」，
 * 若只認本頁的補檔動作，待補清單會一直掛著一筆其實已經補好的項目（使用者實測回報）。
 * 「不列入(ignored)」是人工決定，維持原狀不自動動它。
 */
function extdoc_pending_autoclose(PDO $db): int {
    static $done = false;
    if ($done) return 0;
    $done = true;
    try {
        $rows = $db->query("SELECT id, ds_pk FROM external_doc_pending WHERE status='pending'")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return 0; }
    if (!$rows) return 0;
    $has = extdoc_parts_with_doc($db);
    $n = 0; $up = null;
    foreach ($rows as $r) {
        $pk = (int)$r['ds_pk'];
        if (!isset($has[$pk])) continue;
        if ($up === null) {
            $up = $db->prepare("UPDATE external_doc_pending
                                SET status='done', filled_attach_id=?, filled_at=NOW(), filled_by=?
                                WHERE id=? AND status='pending'");
        }
        // filled_attach_id 只在料號附件時有意義（報價附件是另一張表，同一個欄位塞進去語意會錯）
        $up->execute([$has[$pk]['source'] === 'part' ? $has[$pk]['attach_id'] : null,
                      '系統自動（已有外來文件）', (int)$r['id']]);
        $n++;
    }
    return $n;
}

function extdoc_pending_rows(PDO $db, string $status = 'pending'): array {
    $status = in_array($status, ['pending','ignored','done'], true) ? $status : 'pending';
    extdoc_pending_autoclose($db);   // 先把「已從別的入口補到文件」的項目結案，清單與計數才一致
    try {
        $st = $db->prepare("SELECT p.*, ds.D_Setting_Id AS cur_part_no, ds.Customer_Id AS cur_customer_id,
                                   COALESCE(cl.customer,'') AS customer_name
                            FROM external_doc_pending p
                            LEFT JOIN d_setting ds ON ds.d_id = p.ds_pk
                            LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                            WHERE p.status = ?
                            ORDER BY customer_name, cur_part_no, p.id");
        $st->execute([$status]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
    $map = extdoc_pfmea_map($db);
    $out = [];
    foreach ($rows as $r) {
        // 料號/客戶一律即時查 d_setting（料號改名或改客戶時清單要跟著對）
        $partNo = (string)($r['cur_part_no'] !== null && $r['cur_part_no'] !== '' ? $r['cur_part_no'] : $r['part_no']);
        $custId = (string)($r['cur_customer_id'] ?? $r['customer_id'] ?? '');
        $pfmeaNo = extdoc_pfmea_of($map, $r['ds_pk'], $partNo);
        $out[] = [
            'pending_id'    => (int)$r['id'],
            'source'        => 'pending',
            'ds_pk'         => (int)$r['ds_pk'],
            'part_no'       => $partNo,
            'customer_id'   => $custId,
            'customer_name' => $r['customer_name'] !== '' ? $r['customer_name'] : $custId,
            'source_kind'   => (string)$r['source_kind'],
            'ref_no'        => (string)($r['ref_no'] ?? ''),
            'has_pfmea'     => $pfmeaNo !== '',
            'pfmea_doc_no'  => $pfmeaNo !== '' ? $pfmeaNo : (string)($r['ref_no'] ?? ''),
            'created_by'    => (string)($r['created_by'] ?? ''),
            'created_at'    => substr((string)($r['created_at'] ?? ''), 0, 16),
            'part_missing'  => $r['cur_part_no'] === null,   // 料號已被刪除
        ];
    }
    return $out;
}

/**
 * PFMEA 缺件偵測：PFMEA 已建立、但該料號在外來文件清單一列都沒有者。
 * 判定基準（使用者拍板）＝完全沒有任何外來文件（料號附件與報價附件都算，已排除的不算數）。
 * 回傳每筆含 already（已有待補項目）/ignored（已標記不列入）狀態，供跳窗顯示。
 */
/**
 * 「有專案(2-GM-02)但外來文件清單一筆都沒有」的料號（2026-08-20 新增偵測來源）。
 * 判定基準沿用 PFMEA 缺件偵測的既有口徑：完全沒有任何外來文件（料號附件與報價附件都算）。
 * 回傳欄位與 extdoc_pfmea_missing() 對齊，前端與 pending_create 可以共用同一套處理。
 */
function extdoc_project_missing(PDO $db): array {
    try {
        require_once __DIR__ . '/../common/project_lib.php';
        prj_ensure_schema($db);
        $rows = prj_missing_for($db, 'ext_doc');
    } catch (Throwable $e) {
        return [];   // 專案模組不可用時只是少一個來源，不能讓整個偵測掛掉
    }
    if (!$rows) return [];

    $hasDoc = extdoc_parts_with_doc($db);
    $exist = [];
    try {
        foreach ($db->query("SELECT ds_pk, status FROM external_doc_pending WHERE source_kind='project'")
                    ->fetchAll(PDO::FETCH_ASSOC) as $e) $exist[(int)$e['ds_pk']] = $e['status'];
    } catch (Exception $e) {}

    $out = [];
    foreach ($rows as $r) {
        $dsPk = (int)$r['ds_pk'];
        if (isset($hasDoc[$dsPk])) continue;             // 已經有外來文件＝不缺
        $stt = $exist[$dsPk] ?? '';
        $out[] = [
            'ds_pk'         => $dsPk,
            'part_no'       => (string)$r['part_no'],
            'customer_id'   => (string)($r['Customer_Id'] ?? ''),
            'customer_name' => (string)($r['customer_name'] ?: $r['Customer_Id']),
            'pfmea_doc_no'  => '',                        // 這個來源不是從 PFMEA 來的
            'project_no'    => (string)$r['project_no'],
            'project_name'  => (string)$r['project_name'],
            'src_kind'      => 'project',
            'src_label'     => '專案 ' . $r['project_no'],
            'already'       => $stt === 'pending',
            'ignored'       => $stt === 'ignored',
            'done'          => $stt === 'done',
        ];
    }
    usort($out, static function ($a, $b) {
        return [$a['customer_name'], $a['part_no']] <=> [$b['customer_name'], $b['part_no']];
    });
    return $out;
}

function extdoc_pfmea_missing(PDO $db): array {
    $map = extdoc_pfmea_map($db);
    // ① PFMEA 的料號集合（純文字料號回查 d_setting 主鍵，重複料號取 MIN(d_id)＝全站歸戶慣例）
    $parts = [];   // ds_pk => pfmea doc_no
    foreach ($map['by_id'] as $dsPk => $docNo) $parts[(int)$dsPk] = $docNo;
    $unresolved = [];
    foreach ($map['by_no'] as $txt => $docNo) {
        try {
            $st = $db->prepare("SELECT MIN(d_id) FROM d_setting WHERE UPPER(REPLACE(D_Setting_Id,' ','')) = ?");
            $st->execute([$txt]);
            $dsPk = (int)$st->fetchColumn();
        } catch (Exception $e) { $dsPk = 0; }
        if ($dsPk) { if (!isset($parts[$dsPk])) $parts[$dsPk] = $docNo; }
        else $unresolved[] = ['part_no' => $txt, 'pfmea_doc_no' => $docNo];
    }
    if (!$parts) return ['rows' => [], 'unresolved' => $unresolved];

    // ② 目前清單（正式清單，不含已排除）已經有文件的料號
    $hasDoc = extdoc_parts_with_doc($db);

    // ③ 既有待補/忽略項目
    $exist = [];
    try {
        foreach ($db->query("SELECT ds_pk, status FROM external_doc_pending WHERE source_kind='pfmea'")
                    ->fetchAll(PDO::FETCH_ASSOC) as $e) $exist[(int)$e['ds_pk']] = $e['status'];
    } catch (Exception $e) {}

    $out = [];
    foreach ($parts as $dsPk => $docNo) {
        if (isset($hasDoc[$dsPk])) continue;                  // 已經有外來文件＝不缺
        $st = $db->prepare("SELECT ds.D_Setting_Id, ds.Customer_Id, COALESCE(cl.customer,'') AS customer_name
                            FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                            WHERE ds.d_id = ?");
        $st->execute([$dsPk]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) continue;                                    // 料號已刪除
        $stt = $exist[$dsPk] ?? '';
        $out[] = [
            'ds_pk'         => (int)$dsPk,
            'part_no'       => (string)$d['D_Setting_Id'],
            'customer_id'   => (string)$d['Customer_Id'],
            'customer_name' => $d['customer_name'] !== '' ? $d['customer_name'] : (string)$d['Customer_Id'],
            'pfmea_doc_no'  => $docNo,
            'already'       => $stt === 'pending',
            'ignored'       => $stt === 'ignored',
            'done'          => $stt === 'done',
        ];
    }
    usort($out, function($a, $b) {
        return [$a['customer_name'], $a['part_no']] <=> [$b['customer_name'], $b['part_no']];
    });
    return ['rows' => $out, 'unresolved' => $unresolved];
}

// 料號附件實體根目錄（同 Part_Attachment_API 的 part_attach_nas_dir；鐵律5：DB 只存檔名，路徑即時組）
function extdoc_part_attach_base(PDO $db): string {
    try {
        $v = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='part_attach_nas_dir'")->fetchColumn();
        return ($v !== false && $v !== null) ? trim((string)$v) : '';
    } catch (Exception $e) { return ''; }
}
function extdoc_op_name(PDO $db, int $uid): string {
    try {
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$uid]);
        $n = (string)($st->fetchColumn() ?: '');
        if ($n !== '') return $n;
    } catch (Exception $e) {}
    return (string)($_SESSION['userName'] ?? '');
}

// ════════════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'get_options':
    if (!extCan('view')) jout(['success'=>false,'message'=>'無檢閱權限']);
    // 選項跟著目前篩選連動：客戶下拉只列「目前範圍/年度/類別下真的有外來文件」的客戶，
    // 年度/類別鈕同理（各維度用「其他維度的篩選」交叉過濾，不含自己，才不會把自己清空）
    $selMode = $_POST['mode'] ?? 'all';
    $selCust = trim((string)($_POST['customer_id'] ?? ''));
    $selYear = (int)($_POST['year'] ?? 0);
    $selCat  = (int)($_POST['category'] ?? 0);
    $selPf   = in_array(($_POST['pfmea'] ?? ''), ['yes','no'], true) ? $_POST['pfmea'] : '';
    $selKw   = extdoc_kw_terms((string)($_POST['part_kw'] ?? ''));
    $all = extdoc_fetch_rows($db, ['mode'=>$selMode, 'show_history'=>(!empty($_POST['show_history']) && $_POST['show_history'] !== '0')]);
    $custs = []; $years = []; $presentCats = [];
    foreach ($all as $r) {
        $y = (int)substr($r['doc_date'], 0, 4);
        $mCust = $selCust === '' || $r['customer_id'] === $selCust;
        $mYear = !$selYear || $y === $selYear;
        $mCat  = !$selCat  || in_array($selCat, $r['category_ids']);
        $mPf   = $selPf === '' || ($selPf === 'yes' ? $r['has_pfmea'] : !$r['has_pfmea']);
        if (!$mPf) continue;   // PFMEA 篩選對每個維度都成立，直接先過濾
        if (!extdoc_kw_match($selKw, (string)$r['part_no'])) continue;   // 料號模糊搜尋同理
        if ($mYear && $mCat && $r['customer_id'] !== '') $custs[$r['customer_id']] = $r['customer_name'];
        if ($mCust && $mCat && $y) $years[$y] = 1;
        if ($mCust && $mYear) foreach ($r['category_ids'] as $cid) $presentCats[$cid] = 1;
    }
    asort($custs); krsort($years);
    // 類別鈕＝目前篩選下實際出現的；all_categories＝全部外來文件標籤（配色基準，跨篩選穩定）
    $catList = []; $catAll = [];
    foreach (extdoc_categories($db) as $cid => $c) {
        $catAll[] = ['id'=>$cid, 'name'=>$c['display']];
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
        'all_categories' => $catAll,
        'company_name' => extdoc_company_name($db),
        'issue_unit' => extdoc_issue_unit($db),
        'as_doc'     => extdoc_bound_asdoc($db),
        'as_docs'    => $asDocs,
        'can_manage' => extCan('manage'),
        'pending_count' => count(extdoc_pending_rows($db, 'pending')),
        'ignored_count' => count(extdoc_pending_rows($db, 'ignored')),
        'ext_cats'   => $catAll,   // 補檔上傳可選的外來文件類別（＝清單認列的標籤）
    ]);

case 'get_list':
    if (!extCan('view')) jout(['success'=>false,'message'=>'無檢閱權限']);
    $showArg = $_POST['show'] ?? 'active';
    if ($showArg === 'pending' || $showArg === 'ignored') {
        // 待補檔案分頁：資料來自 external_doc_pending（還沒有檔案，故不走附件查詢）
        $rows = extdoc_pending_rows($db, $showArg);
        $selCust = trim((string)($_POST['customer_id'] ?? ''));
        if ($selCust !== '') $rows = array_values(array_filter($rows, fn($r) => $r['customer_id'] === $selCust));
        $selKw = extdoc_kw_terms((string)($_POST['part_kw'] ?? ''));
        if ($selKw) $rows = array_values(array_filter($rows, fn($r) => extdoc_kw_match($selKw, (string)$r['part_no'])));
    } else {
        $rows = extdoc_fetch_rows($db, [
            'mode'        => $_POST['mode'] ?? 'all',
            'customer_id' => $_POST['customer_id'] ?? '',
            'year'        => (int)($_POST['year'] ?? 0),
            'show_history'=> !empty($_POST['show_history']) && $_POST['show_history'] !== '0',
            'category'    => (int)($_POST['category'] ?? 0),
            'pfmea'       => $_POST['pfmea'] ?? '',
        'part_kw'     => $_POST['part_kw'] ?? '',
            'show'        => $showArg,
        ]);
    }
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
        'pending_count' => count(extdoc_pending_rows($db, 'pending')),
        'ignored_count' => count(extdoc_pending_rows($db, 'ignored')),
    ]);

case 'get_print':
    // 列印：全部資料依客戶分組
    if (!extCan('view')) jout(['success'=>false,'message'=>'無檢閱權限']);
    $rows = extdoc_fetch_rows($db, [
        'mode'        => $_POST['mode'] ?? 'all',
        'customer_id' => $_POST['customer_id'] ?? '',
        'year'        => (int)($_POST['year'] ?? 0),
        'show_history'=> !empty($_POST['show_history']) && $_POST['show_history'] !== '0',
        'category'    => (int)($_POST['category'] ?? 0),
        'pfmea'       => $_POST['pfmea'] ?? '',
        'part_kw'     => $_POST['part_kw'] ?? '',
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
        'show_history'=> !empty($_GET['show_history']) && $_GET['show_history'] !== '0',
        'category'    => (int)($_GET['category'] ?? 0),
        'pfmea'       => $_GET['pfmea'] ?? '',
        'part_kw'     => $_GET['part_kw'] ?? '',
    ]);
    $unit = extdoc_issue_unit($db);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="external_doc_list_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";  // UTF-8 BOM（Excel 相容）
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['客戶', '料號', '文件名稱', '外來文件類別', '發行日期', '版本', '發行單位', 'PFMEA', '來源', '報價單號', '備註']);
    foreach ($rows as $r) {
        fputcsv($fp, [
            $r['customer_name'], $r['part_no'], $r['doc_name'],
            implode('、', $r['categories']), $r['doc_date'], extdoc_ver_label($r), $unit,
            $r['has_pfmea'] ? ('PFMEA已建立 ' . $r['pfmea_doc_no']) : '未建立',
            $r['source'] === 'part' ? '料號附件' : '報價附件', $r['quote_no'], $r['note'],
        ]);
    }
    fclose($fp);
    exit;

case 'save_note':
    // 備註回寫到附件本體（part_attachments.note / quotation_attachments.note），其他頁看到的是同一筆
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限（備註需「外來文件管理」角色）']);
    $src = ($_POST['source'] ?? '') === 'quote' ? 'quote' : 'part';
    $aid = (int)($_POST['attach_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($note) > 500) jout(['success'=>false,'message'=>'備註過長（上限 500 字）']);
    if (!$aid) jout(['success'=>false,'message'=>'參數錯誤']);
    $table = $src === 'part' ? 'part_attachments' : 'quotation_attachments';
    $st = $db->prepare("UPDATE $table SET note=? WHERE id=?");
    $st->execute([$note !== '' ? $note : null, $aid]);
    jout(['success'=>true, 'message'=>'備註已儲存', 'note'=>$note]);

case 'exclude_item':
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限（排除需「外來文件管理」角色）']);
    $src = ($_POST['source'] ?? '') === 'quote' ? 'quote' : 'part';
    $aid = (int)($_POST['attach_id'] ?? 0);
    $dpk = (int)($_POST['ds_pk'] ?? 0);
    if (!$aid || !$dpk) jout(['success'=>false,'message'=>'參數錯誤']);
    $opName = '';
    try {
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$uid]);
        $opName = (string)($st->fetchColumn() ?: '');
    } catch (Exception $e) {}
    if ($opName === '') $opName = (string)($_SESSION['userName'] ?? '');
    $db->prepare("INSERT IGNORE INTO external_doc_exclude (source, attach_id, ds_pk, part_no, excluded_by, excluded_at)
                  VALUES (?,?,?,?,?,NOW())")
       ->execute([$src, $aid, $dpk, trim((string)($_POST['part_no'] ?? '')), $opName]);
    jout(['success'=>true, 'message'=>'已排除，可在「已排除」分頁加回']);

case 'restore_item':
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限']);
    $src = ($_POST['source'] ?? '') === 'quote' ? 'quote' : 'part';
    $aid = (int)($_POST['attach_id'] ?? 0);
    $dpk = (int)($_POST['ds_pk'] ?? 0);
    if (!$aid || !$dpk) jout(['success'=>false,'message'=>'參數錯誤']);
    $db->prepare("DELETE FROM external_doc_exclude WHERE source=? AND attach_id=? AND ds_pk=?")
       ->execute([$src, $aid, $dpk]);
    jout(['success'=>true, 'message'=>'已加回清單']);

// ── 版本判定的人工覆寫（釘選現行版／該組不做版本判定）─────────────────
// 覆寫是以「料號 × 類別」為單位，一列附件掛幾個外來文件標籤就寫幾組（前端只送附件，類別後端自己查）。
case 'version_pin':        // 把這一筆釘成現行版（不看發行日期）
case 'version_keep_all':   // 這組不是改版：全部保留
case 'version_auto':       // 取消覆寫，回到自動判定
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限（版本設定需「外來文件管理」角色）']);
    $src = ($_POST['source'] ?? '') === 'quote' ? 'quote' : 'part';
    $aid = (int)($_POST['attach_id'] ?? 0);
    $dpk = (int)($_POST['ds_pk'] ?? 0);
    if (!$aid || !$dpk) jout(['success'=>false,'message'=>'參數錯誤']);
    // 後端自己驗（鐵律8）：附件要存在、料號要存在、且該附件真的掛了外來文件標籤
    $table = $src === 'part' ? 'part_attachments' : 'quotation_attachments';
    $st = $db->prepare("SELECT category_ids" . ($src === 'part' ? ", '' AS category_id" : ", COALESCE(category_id,'') AS category_id")
                       . " FROM $table WHERE id=?" . ($src === 'part' ? " AND deleted_at IS NULL" : " AND status='active'"));
    $st->execute([$aid]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) jout(['success'=>false,'message'=>'附件不存在或已刪除']);
    $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
    $st->execute([$dpk]);
    $pno = $st->fetchColumn();
    if ($pno === false) jout(['success'=>false,'message'=>'料號不存在']);
    $catsAll = extdoc_categories($db);
    $myCats  = [];
    foreach (array_filter(explode(',', str_replace(' ', '', (string)$att['category_ids']))) as $cid) {
        if (isset($catsAll[(int)$cid])) $myCats[] = (int)$cid;
    }
    if (!$myCats && $att['category_id'] !== '' && isset($catsAll[(int)$att['category_id']])) $myCats[] = (int)$att['category_id'];
    if (!$myCats) jout(['success'=>false,'message'=>'這筆附件沒有外來文件類別標籤，不列入版本判定']);
    $opName = extdoc_op_name($db, $uid);
    $db->beginTransaction();
    try {
        $del = $db->prepare("DELETE FROM external_doc_version_override WHERE ds_pk=? AND cat_id=?");
        foreach ($myCats as $cid) $del->execute([$dpk, $cid]);
        if ($action !== 'version_auto') {
            $ins = $db->prepare("INSERT INTO external_doc_version_override
                                 (ds_pk, cat_id, kind, source, attach_id, part_no, created_by, created_at)
                                 VALUES (?,?,?,?,?,?,?,NOW())");
            $kind = $action === 'version_pin' ? 'pin' : 'keep_all';
            foreach ($myCats as $cid) {
                $ins->execute([$dpk, $cid, $kind, $kind === 'pin' ? $src : null,
                               $kind === 'pin' ? $aid : null, (string)$pno, $opName]);
            }
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        jout(['success'=>false,'message'=>'設定失敗：'.$e->getMessage()]);
    }
    jout(['success'=>true, 'message'=> $action === 'version_pin' ? '已釘選為現行版'
                                     : ($action === 'version_keep_all' ? '這組已改為全部保留（不做版本判定）'
                                                                       : '已恢復自動判定（發行日期最新者為現行版）')]);

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

// ── PFMEA 缺件偵測（PFMEA 已建立、但外來文件清單一列都沒有的料號）────────
case 'pfmea_scan':
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限（偵測需「外來文件管理」角色）']);
    // 來源可複選（2026-08-20 使用者要求與既有偵測鈕合併）：
    //   pfmea  ＝PFMEA 已建立但外來文件一筆都沒有（原本唯一的來源）
    //   project＝有專案(2-GM-02)但外來文件一筆都沒有
    $src = trim((string)($_POST['sources'] ?? $_GET['sources'] ?? ''));
    $srcArr = $src === '' ? ['pfmea'] : array_map('trim', explode(',', $src));
    $rows = [];
    $unresolved = [];
    if (in_array('pfmea', $srcArr, true)) {
        $scan = extdoc_pfmea_missing($db);
        foreach ($scan['rows'] as $r) { $r['src_kind'] = 'pfmea'; $r['src_label'] = 'PFMEA'; $rows[] = $r; }
        $unresolved = $scan['unresolved'];
    }
    if (in_array('project', $srcArr, true)) {
        $seen = [];
        foreach ($rows as $r) $seen[(int)$r['ds_pk']] = true;
        foreach (extdoc_project_missing($db) as $r) {
            if (isset($seen[(int)$r['ds_pk']])) {
                // 兩個來源都命中：只留一筆，來源標示合併
                foreach ($rows as &$x) {
                    if ((int)$x['ds_pk'] === (int)$r['ds_pk']) { $x['src_label'] .= '＋專案 ' . $r['project_no']; break; }
                }
                unset($x);
                continue;
            }
            $seen[(int)$r['ds_pk']] = true;
            $rows[] = $r;
        }
    }
    jout([
        'success'    => true,
        'rows'       => $rows,
        'unresolved' => $unresolved,   // PFMEA 手打料號、在料號主檔找不到者（無法自動建立）
        'total'      => count($rows),
    ]);

// 建立待補項目（勾選的料號）
case 'pending_create':
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限']);
    $ids = $_POST['ds_pks'] ?? [];
    if (!is_array($ids)) $ids = array_filter(explode(',', (string)$ids));
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) jout(['success'=>false,'message'=>'請至少勾選一筆料號']);
    // 點開即刷新：以偵測當下的實際狀態再算一次，已補齊/已存在的不重複建立
    // 兩個來源都要重算（PFMEA 缺件＋有專案但未建立），否則勾了專案來源的列會被當成「不在候選清單」略過
    $scan = extdoc_pfmea_missing($db);
    $can = [];
    // done＝之前補過；但它會出現在偵測結果就代表文件現在又不在清單上了，故允許重新建立待補
    foreach ($scan['rows'] as $r) {
        if ($r['already']) continue;
        $r['src_kind'] = 'pfmea';
        $can[(int)$r['ds_pk']] = $r;
    }
    foreach (extdoc_project_missing($db) as $r) {
        if ($r['already'] || isset($can[(int)$r['ds_pk']])) continue;
        $can[(int)$r['ds_pk']] = $r;
    }
    $opName = extdoc_op_name($db, $uid);
    $made = 0; $skip = 0;
    $db->beginTransaction();
    try {
        // source_kind 隨該列實際來源寫入（唯一鍵是 ds_pk×source_kind，兩個來源不會互相蓋掉）
        $ins = $db->prepare("INSERT INTO external_doc_pending
            (ds_pk, part_no, customer_id, source_kind, ref_no, status, created_by, created_at)
            VALUES (?,?,?,?,?,'pending',?,NOW())
            ON DUPLICATE KEY UPDATE status='pending', ref_no=VALUES(ref_no),
                                    filled_attach_id=NULL, filled_at=NULL, filled_by=NULL");
        foreach ($ids as $dsPk) {
            if (!isset($can[$dsPk])) { $skip++; continue; }
            $r = $can[$dsPk];
            $kind = (string)($r['src_kind'] ?? 'pfmea');
            $refNo = $kind === 'project' ? (string)($r['project_no'] ?? '') : (string)($r['pfmea_doc_no'] ?? '');
            $ins->execute([$dsPk, $r['part_no'], $r['customer_id'], $kind, $refNo, $opName]);
            $made++;
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        jout(['success'=>false,'message'=>'建立失敗：'.$e->getMessage()]);
    }
    jout(['success'=>true, 'created'=>$made, 'skipped'=>$skip,
          'message'=>'已建立 '.$made.' 筆待補項目'.($skip ? '（'.$skip.' 筆已存在或已補齊，略過）' : '')]);

// 待補項目：標記不列入 / 加回待補 / 刪除
case 'pending_ignore':
case 'pending_restore':
case 'pending_delete':
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限']);
    $pid = (int)($_POST['pending_id'] ?? 0);
    if (!$pid) jout(['success'=>false,'message'=>'參數錯誤']);
    if ($action === 'pending_delete') {
        $db->prepare("DELETE FROM external_doc_pending WHERE id=? AND status<>'done'")->execute([$pid]);
        jout(['success'=>true,'message'=>'已刪除（下次偵測若仍缺件會再出現）']);
    }
    $to = $action === 'pending_ignore' ? 'ignored' : 'pending';
    $db->prepare("UPDATE external_doc_pending SET status=? WHERE id=? AND status<>'done'")->execute([$to, $pid]);
    jout(['success'=>true,'message'=>$to === 'ignored' ? '已標記不列入（可在「不列入」檢視加回）' : '已加回待補清單']);

// ── 補檔上傳：存成該料號的「料號附件」，上傳日期＝使用者輸入的文件日期 ────
// 清單的發行日期＝附件上傳日期，補歷史文件時要能指定實際文件日期（使用者明確要求）。
case 'upload_fill':
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限（補檔需「外來文件管理」角色）']);
    $pid   = (int)($_POST['pending_id'] ?? 0);
    $dsPk  = (int)($_POST['ds_pk'] ?? 0);
    $pend  = null;
    if ($pid) {
        $st = $db->prepare("SELECT * FROM external_doc_pending WHERE id=?");
        $st->execute([$pid]);
        $pend = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pend) jout(['success'=>false,'message'=>'待補項目不存在（清單可能已更新，請重新整理）']);
        if ($pend['status'] === 'done') jout(['success'=>false,'message'=>'這筆已經補過檔案了，請重新整理清單']);
        $dsPk = (int)$pend['ds_pk'];
    }
    if (!$dsPk) jout(['success'=>false,'message'=>'缺少料號']);
    $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
    $st->execute([$dsPk]);
    $partNo = $st->fetchColumn();
    if ($partNo === false) jout(['success'=>false,'message'=>'料號不存在（可能已被刪除）']);

    // 附件標籤鐵則（鐵律8）：沒勾類別一律不准存；且只能勾「列入外來文件清單」的標籤
    $extCats = array_keys(extdoc_categories($db));
    $catIds  = array_values(array_unique(array_filter(array_map('intval',
                 explode(',', (string)($_POST['category_ids'] ?? ''))))));
    if (!$catIds) jout(['success'=>false,'message'=>'請至少勾選一個外來文件類別']);
    foreach ($catIds as $cid) {
        if (!in_array($cid, $extCats, true)) jout(['success'=>false,'message'=>'類別不在「列入外來文件清單」的標籤範圍內']);
    }
    // 文件日期（＝附件上傳日期＝清單的發行日期）
    $docDate = trim((string)($_POST['doc_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $docDate)) jout(['success'=>false,'message'=>'請輸入文件日期（YYYY-MM-DD）']);
    $dParts = explode('-', $docDate);
    if (!checkdate((int)$dParts[1], (int)$dParts[2], (int)$dParts[0])) jout(['success'=>false,'message'=>'文件日期不是有效日期']);
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();   // 時間戳一律取 DB 時間（PHP date() 是 UTC）
    if ($docDate > $today) jout(['success'=>false,'message'=>'文件日期不可晚於今天']);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jout(['success'=>false,'message'=>'請選擇要上傳的檔案'.(isset($_FILES['file']) ? '（錯誤碼 '.$_FILES['file']['error'].'）' : '')]);
    }
    $base = extdoc_part_attach_base($db);
    if (!$base) jout(['success'=>false,'message'=>'尚未設定料號附件儲存路徑（主檔管理→附件路徑設定）']);
    $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $dsPk . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) jout(['success'=>false,'message'=>'無法建立目錄：'.$dir]);
    $orig = basename($_FILES['file']['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $blocked = ['php','php3','php4','php5','phtml','phar','exe','bat','sh','cmd','asp','aspx','jsp','py','rb','htaccess'];
    if ($ext === '' || in_array($ext, $blocked, true)) jout(['success'=>false,'message'=>'不允許此檔案類型']);
    $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) jout(['success'=>false,'message'=>'檔案寫入失敗']);

    $sz    = (int)$_FILES['file']['size'];
    $szTxt = $sz < 1024 ? $sz.' B' : ($sz < 1048576 ? round($sz/1024,1).' KB' : round($sz/1048576,1).' MB');
    $note  = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);
    // 上傳時刻取文件日期＋現在時分秒：日期是使用者指定的業務日期，時分秒僅用於同日多筆的排序
    $uploadedAt = $docDate . ' ' . (string)$db->query("SELECT CURTIME()")->fetchColumn();
    $opName = extdoc_op_name($db, $uid);
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO part_attachments
                (d_id, filename, original_name, category_ids, file_size, note, uploaded_by, uploaded_by_id, uploaded_at)
                VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$dsPk, $fname, $orig, implode(',', $catIds), $szTxt,
                      $note !== '' ? $note : null, $opName, $uid, $uploadedAt]);
        $newId = (int)$db->lastInsertId();
        if ($pid) {
            $db->prepare("UPDATE external_doc_pending
                          SET status='done', filled_attach_id=?, filled_at=NOW(), filled_by=? WHERE id=?")
               ->execute([$newId, $opName, $pid]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        @unlink($dir . $fname);
        jout(['success'=>false,'message'=>'存檔失敗：'.$e->getMessage()]);
    }
    jout(['success'=>true, 'attach_id'=>$newId, 'message'=>'已上傳並補入清單（發行日期＝'.$docDate.'）']);

// ── 發行日期（＝附件上傳日期）修改：回寫附件本體，主檔/報價頁看到的是同一筆 ──
case 'save_doc_date':
    if (!extCan('manage')) jout(['success'=>false,'message'=>'無管理權限（修改發行日期需「外來文件管理」角色）']);
    $src = ($_POST['source'] ?? '') === 'quote' ? 'quote' : 'part';
    $aid = (int)($_POST['attach_id'] ?? 0);
    $d   = trim((string)($_POST['doc_date'] ?? ''));
    if (!$aid) jout(['success'=>false,'message'=>'參數錯誤']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) jout(['success'=>false,'message'=>'日期格式錯誤（需 YYYY-MM-DD）']);
    $dp = explode('-', $d);
    if (!checkdate((int)$dp[1], (int)$dp[2], (int)$dp[0])) jout(['success'=>false,'message'=>'不是有效日期']);
    if ($d > (string)$db->query("SELECT CURDATE()")->fetchColumn()) jout(['success'=>false,'message'=>'發行日期不可晚於今天']);
    $table = $src === 'part' ? 'part_attachments' : 'quotation_attachments';
    // 只改日期、保留原本的時分秒（同日多筆的先後順序不變）
    $st = $db->prepare("UPDATE $table SET uploaded_at = CONCAT(?, ' ', TIME(COALESCE(uploaded_at, NOW()))) WHERE id=?");
    $st->execute([$d, $aid]);
    if (!$st->rowCount()) jout(['success'=>true,'message'=>'日期未變更','doc_date'=>$d]);
    jout(['success'=>true,'message'=>'發行日期已更新為 '.$d,'doc_date'=>$d]);

default:
    jout(['success'=>false,'message'=>'未知的 action']);
}
