<?php
/**
 * 已完工BOM查詢列印（2026-08-10 新增，測試功能）
 * 逐筆列出已結案(processing_state='1')的BOM，供查找/列印/匯出用；跟 OreadyReply_ForPm_BaseOfTime2.php
 * 內建的「查詢已完工資料」跳窗（search_completed_bom，固定LIMIT 50、僅單一文字篩選）不同——
 * 這支是開新分頁的完整查詢版，篩選項目對齊主頁 .all-filters（客戶/業務/交期/優先權燈號/BOM/料號/
 * 廠商/發單數量/製程大類/全域關鍵字），並提供分頁、列印、CSV匯出；兩者並存不互相取代。
 * 篩選：結案日期區間(預設近30天，清空=不限日期查全部)。
 * 分頁走後端(不一次撈全部)；列印/CSV匯出走後端依目前篩選條件抓「全部」符合筆數(不受分頁限制)。
 * 製程大類為動態連動清單（get_facets action，比照 process_report_query.php 做法）；其餘篩選欄位
 * （客戶/業務/廠商）維持靜態 datalist（比照主頁本身這些篩選也不是動態連動，降低複雜度）。
 * 「業務」只顯示原本負責業務（customer_sales.role='primary'），不比照主頁即時代理判斷邏輯——
 * 因為這裡查的是歷史已結案資料，當時負責業務是固定事實，不需要判斷「現在」代理人是否在請假。
 */
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";

// 登入檢查（比照 process_report_query.php，AJAX-aware）
if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
        || isset($_POST['action']);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '連線逾時，請重新登入', 'timeout' => true, 'redirect' => '../../index.php']);
        exit;
    } else {
        echo "<script>alert('連線逾時，請重新登入'); window.location.href='../../index.php';</script>";
        exit;
    }
}

$db = new DBConnection();
$pdo = $db->getPDO();

// --- 權限檢查（比照 process_report_query.php，唯讀工具只需「有任一權限」即可檢視） ---
$id = intval($_SESSION['id'] ?? 0);
$current_script_path = $_SERVER['PHP_SELF'];
$permission_code = null;
try {
    $sql_page_info = "
        SELECT smp.page_id, smp.page_url, smp.page_url_readonly, smp.group_id
        FROM system_module_pages smp
        WHERE (:script LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
           OR (:script LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
        LIMIT 1";
    $stmt_page_info = $pdo->prepare($sql_page_info);
    $stmt_page_info->execute([':script' => $current_script_path]);
    $page_info = $stmt_page_info->fetch(PDO::FETCH_ASSOC);

    if ($page_info) {
        $page_id = $page_info['page_id'];
        $group_id = $page_info['group_id'];
        $group_module_code = null;
        if (!empty($group_id)) {
            $stmt_gm = $pdo->prepare("SELECT module_code FROM system_modules WHERE group_id = :gid LIMIT 1");
            $stmt_gm->execute([':gid' => $group_id]);
            $group_module_code = $stmt_gm->fetchColumn();
        }
        $user_perms = [];
        $stmt_pp = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:uid AND scope='page' AND module_code=:pid");
        $stmt_pp->execute([':uid' => $id, ':pid' => $page_id]);
        $pf = array_filter($stmt_pp->fetchAll(PDO::FETCH_COLUMN));
        if ($pf) {
            $user_perms = $pf;
        } elseif (!empty($group_module_code)) {
            $stmt_gp = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:uid AND scope='group' AND module_code=:mc");
            $stmt_gp->execute([':uid' => $id, ':mc' => $group_module_code]);
            $gf = array_filter($stmt_gp->fetchAll(PDO::FETCH_COLUMN));
            if ($gf) $user_perms = $gf;
        }
        $all = [];
        foreach ($user_perms as $p) { $all = array_merge($all, str_split($p)); }
        $uniq = array_unique($all);
        $permission_code = $uniq ? implode('', $uniq) : null;
    }
} catch (Exception $e) {
    $permission_code = null;
}

$is_ajax_req = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_POST['action']);
if (is_null($permission_code)) {
    if ($is_ajax_req) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '無權限存取此功能']);
        exit;
    }
    echo "<script>alert('無權限存取此頁面'); window.location.href='../../index.php';</script>";
    exit;
}

// ================= 共用：組出篩選條件（list / get_print / export_csv / get_facets / get_options 共用） =================
// $OCQ_FROM：bom 為單位的基礎 JOIN 鏈，抄自 src/store/_fetch_data2.php（業務只取 primary，不 join deputy）
$OCQ_FROM = "FROM bom b
    LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
    LEFT JOIN customer_list cl_ds ON cl_ds.customer_id = ds.Customer_Id
    LEFT JOIN customer_list cl ON cl.customer = b.Client_Name
    LEFT JOIN customer_sales cs_primary ON cs_primary.customer_id = COALESCE(cl_ds.customer_id, cl.customer_id)
        AND cs_primary.is_active = 1 AND cs_primary.role = 'primary'
    LEFT JOIN user u_sales_primary ON u_sales_primary.id = cs_primary.user_id
    LEFT JOIN user u_close ON u_close.id = b.closed_by";

$OCQ_CLIENT_DISP = "COALESCE(cl_ds.customer, cl.customer, b.Client_Name)";
$OCQ_CLIENT_ID = "COALESCE(cl_ds.customer_id, cl.customer_id)";

// closed_at 是 2026-05-22 才上線的「手動結案」功能才會填寫，在此之前就已 processing_state='1' 的舊資料
// （約佔已結案總數92%）完全沒有結案日期紀錄。BOM編號格式固定為 B-YYYMMDDNNN（YYY=民國年3碼／MM/DD／NNN=流水號3碼，
// 全站11641筆BOM長度一致驗證過），舊資料改用「BOM編號回推的建立日期」當作結案日期的替代值（使用者明確指示，
// 2026-08-11）：有 closed_at 就用真正的結案日期，沒有才退回 BOM 編號回推的日期，此值同時用於篩選/排序/顯示；
// 也用於「統整報表」的結案耗時統計（起算點＝BOM編號回推的建立日期，終點＝真正的closed_at，沒有closed_at的
// 舊資料無法算出真實耗時，直接排除在耗時統計外，不能拿推算日期自己減自己）。
$OCQ_BOMDATE = "STR_TO_DATE(CONCAT(
        CAST(SUBSTRING(SUBSTRING_INDEX(b.bom,'-',-1),1,3) AS UNSIGNED) + 1911, '-',
        SUBSTRING(SUBSTRING_INDEX(b.bom,'-',-1),4,2), '-',
        SUBSTRING(SUBSTRING_INDEX(b.bom,'-',-1),6,2)
    ), '%Y-%m-%d')";
$OCQ_EFFDATE = "COALESCE(DATE(b.closed_at), $OCQ_BOMDATE)";

$OCQ_COLS = "b.bom, b.d_id, b.sqty AS Qty, b.priority_type, b.d_setting_id, b.closed_by, b.closed_at,
    b.Delivery_date, $OCQ_CLIENT_DISP AS client_name_display,
    u_sales_primary.user_cname AS sales_name, u_close.user_cname AS closed_by_name,
    $OCQ_EFFDATE AS effective_date, (b.closed_at IS NULL) AS date_is_derived";

// $exclude：計算某個篩選欄位自己的可選清單(facet)時，要排除該欄位自己的條件
function ocq_build_filter($p, $exclude = []) {
    global $OCQ_CLIENT_DISP, $OCQ_CLIENT_ID, $OCQ_EFFDATE;
    $where = ["b.processing_state = '1'"];
    $params = [];

    if (!in_array('date', $exclude, true)) {
        if (!empty($p['date_from'])) { $where[] = "$OCQ_EFFDATE >= ?"; $params[] = $p['date_from']; }
        if (!empty($p['date_to']))   { $where[] = "$OCQ_EFFDATE <= ?"; $params[] = $p['date_to']; }
    }
    if (!in_array('customer', $exclude, true) && !empty($p['customer'])) {
        // 客戶名稱或客戶代號皆可模糊比對
        $where[] = "($OCQ_CLIENT_DISP LIKE ? OR $OCQ_CLIENT_ID LIKE ?)";
        $like = '%' . $p['customer'] . '%'; $params[] = $like; $params[] = $like;
    }
    if (!in_array('sales', $exclude, true) && !empty($p['sales'])) {
        $where[] = 'u_sales_primary.user_cname LIKE ?'; $params[] = '%' . $p['sales'] . '%';
    }
    if (!in_array('vendor', $exclude, true) && !empty($p['vendor'])) {
        // 廠商名稱或廠商代號(maker_id_no)皆可模糊比對
        $where[] = "EXISTS (SELECT 1 FROM bom_ing bi_v LEFT JOIN maker_list ml_v ON ml_v.maker_id_no = bi_v.maker_id_no
            WHERE bi_v.bom = b.bom AND (ml_v.maker_id LIKE ? OR bi_v.maker_id_no LIKE ?))";
        $like = '%' . $p['vendor'] . '%'; $params[] = $like; $params[] = $like;
    }
    if (!in_array('bom', $exclude, true) && !empty($p['bom'])) {
        $where[] = '(b.bom LIKE ? OR b.d_id LIKE ?)';
        $like = '%' . $p['bom'] . '%'; $params[] = $like; $params[] = $like;
    }
    if (!in_array('priority', $exclude, true) && !empty($p['priority'])) {
        if ($p['priority'] === 'N') { $where[] = "(b.priority_type IS NULL OR b.priority_type NOT IN ('U','E'))"; }
        else { $where[] = 'b.priority_type = ?'; $params[] = $p['priority']; }
    }
    if (!in_array('qty', $exclude, true) && !empty($p['qty'])) {
        $op = '='; $val = trim($p['qty']);
        if (in_array($val[0] ?? '', ['>', '<', '='], true)) { $op = $val[0]; $val = trim(substr($val, 1)); }
        if ($val !== '' && is_numeric($val)) { $where[] = "b.sqty $op ?"; $params[] = $val; }
    }
    if (!in_array('delivery', $exclude, true) && !empty($p['delivery'])) {
        $op = '='; $val = trim($p['delivery']);
        if (in_array($val[0] ?? '', ['>', '<', '='], true)) { $op = $val[0]; $val = trim(substr($val, 1)); }
        $val = str_replace('-', '/', $val);
        if (preg_match('#^\d{1,2}/\d{1,2}$#', $val)) { $val = date('Y') . '/' . $val; }
        $ts = $val !== '' ? strtotime($val) : false;
        if ($ts !== false) { $where[] = "b.Delivery_date $op ?"; $params[] = date('Y-m-d', $ts); }
    }
    if (!in_array('process_type', $exclude, true) && !empty($p['process_type'])) {
        $where[] = "EXISTS (SELECT 1 FROM bom_ing bi_p LEFT JOIN process_no pn_p ON pn_p.ProcessNo = bi_p.process_no
            WHERE bi_p.bom = b.bom AND pn_p.process_type_id = ?)";
        $params[] = intval($p['process_type']);
    }
    if (!in_array('keyword', $exclude, true) && !empty($p['keyword'])) {
        $kws = preg_split('/\s+/', trim($p['keyword']), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($kws as $kw) {
            $like = '%' . $kw . '%';
            $where[] = "(b.bom LIKE ? OR b.d_id LIKE ? OR $OCQ_CLIENT_DISP LIKE ? OR u_sales_primary.user_cname LIKE ?
                OR u_close.user_cname LIKE ?
                OR EXISTS (SELECT 1 FROM bom_ing bi_k LEFT JOIN process_no pn_k ON pn_k.ProcessNo = bi_k.process_no
                    LEFT JOIN maker_list ml_k ON ml_k.maker_id_no = bi_k.maker_id_no
                    WHERE bi_k.bom = b.bom AND (pn_k.ProcessName LIKE ? OR ml_k.maker_id LIKE ?)))";
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
    }
    return ['WHERE ' . implode(' AND ', $where), $params];
}

// 單次查詢（畫面清單）的筆數上限：超過就不撈資料、請使用者縮小年份或加關鍵字（使用者拍板，2026-09-11）。
// 目前全站已結案 11,474 筆、單一年度最多 3,660 筆，所以 5000 的意思是「單年一定過、全部年份才會被擋」。
// 只影響畫面清單；列印／匯出／統整報表不套這個上限（維持原本的 3000 筆確認視窗）。
const OCQ_LIST_MAX = 5000;

/**
 * 判斷這批 BOM 在 NAS 的 BOM 資料夾裡有沒有對應的 .xlsm 檔（有才給 ms-excel 開檔連結）。
 * 兩個必須這樣做的理由：
 *  ① **絕不在畫面要用的路徑上掃 NAS**——那個資料夾 8,608 個檔，冷連線時可能要等很久（2026-09-03 事故），
 *     所以只讀 `eg_bom_file_cache_read()` 的共用快取；沒有快取就回 null（前端當作「無法判定」照樣給連結，
 *     跟 BOM總表現況一致，不會因為快取還沒建好就讓大家都點不到檔案），並回旗標讓前端在畫面畫完後背景重建。
 *  ② 檔名比對用小寫全名（`<BOM>.xlsm`），不是前綴——BOM 編號等長，前綴比對會把 B-1150903018 誤判成
 *     B-11509030181 之類的其他檔。
 * @return array{0: array<string,bool>, 1: bool}  [bom => 有沒有檔, 需不需要背景重建快取]
 */
function ocq_bom_file_map(array $bom_list): array {
    if (!$bom_list) return [[], false];
    require_once __DIR__ . '/../../src/common/bom_dir_lib.php';
    $dir   = eg_bom_scan_dir_auto();
    $age   = null;
    $files = eg_bom_file_cache_read($dir, ['xlsm'], $age);
    if ($files === null) return [[], true];                 // 完全沒有快取＝無法判定，請前端背景建一次
    $set = [];
    foreach ($files as $fn) $set[strtolower($fn)] = true;
    $map = [];
    foreach ($bom_list as $b) $map[$b] = isset($set[strtolower($b . '.xlsm')]);
    return [$map, ($age === null || $age > 300)];           // 超過 5 分鐘就順便請前端背景更新
}

// 批量撈已結案BOM的製程明細（每 bom+bom_sn 取最新一筆，避免重複），比照既有 search_completed_bom
function ocq_fetch_processes($pdo, $bom_list) {
    if (!$bom_list) return [[], 0];
    $ph = implode(',', array_fill(0, count($bom_list), '?'));
    $sp = $pdo->prepare("
        SELECT bi.bom, bi.bom_sn, bi.process_no, pn.ProcessName,
               DATE_FORMAT(bi.outsource_date,'%Y/%m/%d') AS outsource_date,
               DATE_FORMAT(bi.return_date,'%Y/%m/%d') AS return_date,
               ml.maker_id AS maker_id
        FROM bom_ing bi
        INNER JOIN (
            SELECT bom, bom_sn, MAX(bom_ing_fid) AS max_fid
            FROM bom_ing WHERE bom IN ($ph) GROUP BY bom, bom_sn
        ) latest ON bi.bom_ing_fid = latest.max_fid
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no
        ORDER BY bi.bom, CAST(bi.bom_sn AS UNSIGNED)
    ");
    $sp->execute($bom_list);
    $proc_map = []; $max_count = 0;
    foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $p) { $proc_map[$p['bom']][] = $p; }
    foreach ($proc_map as $ps) { if (count($ps) > $max_count) $max_count = count($ps); }
    return [$proc_map, $max_count];
}

// 批量撈最新單價（每 bom+bom_sn 取最新一筆），比照既有 search_completed_bom
function ocq_fetch_prices($pdo, $bom_list) {
    if (!$bom_list) return [];
    $ph = implode(',', array_fill(0, count($bom_list), '?'));
    $sp2 = $pdo->prepare("
        SELECT tl.bom, tl.bom_sn, tl.price, tl.modified_unit_price
        FROM bom_ing_transfer_log tl
        INNER JOIN (
            SELECT bom, bom_sn, MAX(transfer_id) AS max_id
            FROM bom_ing_transfer_log WHERE bom IN ($ph) GROUP BY bom, bom_sn
        ) latest ON tl.bom = latest.bom AND tl.bom_sn = latest.bom_sn AND tl.transfer_id = latest.max_id
        WHERE tl.bom IN ($ph)
    ");
    $sp2->execute(array_merge($bom_list, $bom_list));
    $price_map = [];
    foreach ($sp2->fetchAll(PDO::FETCH_ASSOC) as $tl) {
        $price_map[$tl['bom']][$tl['bom_sn']] = ['price' => $tl['price'], 'modified_unit_price' => $tl['modified_unit_price']];
    }
    return $price_map;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'export_csv') {
        try {
            list($whereSql, $params) = ocq_build_filter($_POST);
            $sql = "SELECT $OCQ_COLS $OCQ_FROM $whereSql ORDER BY $OCQ_EFFDATE DESC, b.bom DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $bom_list = array_column($rows, 'bom');
            list($proc_map,) = ocq_fetch_processes($pdo, $bom_list);
            $price_map = ocq_fetch_prices($pdo, $bom_list);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="completed_bom_' . date('YmdHis') . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['客戶', 'BOM', '料號', '數量', '交期', '業務', '優先權', '結案人', '結案時間', '加工總單價', '製程明細']);
            foreach ($rows as $r) {
                $priLabel = $r['priority_type'] === 'E' ? '特急件' : ($r['priority_type'] === 'U' ? '急件' : '一般');
                $closedTxt = $r['date_is_derived'] ? ($r['effective_date'] . '（依BOM編號推算，非實際結案時間）') : $r['closed_at'];
                $procs = $proc_map[$r['bom']] ?? [];
                $bomPrices = $price_map[$r['bom']] ?? [];
                $total = 0;
                $procTxt = [];
                foreach ($procs as $p) {
                    $pi = $bomPrices[$p['bom_sn']] ?? null;
                    $pv = $pi ? (floatval($pi['modified_unit_price']) ?: floatval($pi['price'])) : 0;
                    if ($pv > 0) $total += $pv;
                    $procTxt[] = trim(($p['ProcessName'] ?: $p['process_no']) . '(' . ($p['maker_id'] ?: '') . ($p['return_date'] ? '/回廠:' . $p['return_date'] : '') . ')');
                }
                fputcsv($out, [
                    $r['client_name_display'], $r['bom'], $r['d_id'], $r['Qty'], $r['Delivery_date'],
                    $r['sales_name'], $priLabel, $r['closed_by_name'], $closedTxt,
                    $total > 0 ? $total : '', implode('; ', $procTxt),
                ]);
            }
            fclose($out);
        } catch (Exception $e) {
            header('Content-Type: text/plain; charset=utf-8');
            echo '匯出失敗：' . $e->getMessage();
        }
        exit;
    }

    header('Content-Type: application/json');
    try {
        if ($action === 'list') {
            list($whereSql, $params) = ocq_build_filter($_POST);
            $page = max(1, intval($_POST['page'] ?? 1));
            $page_size = intval($_POST['page_size'] ?? 10);
            if (!in_array($page_size, [5, 10, 20, 50], true)) $page_size = 10;
            $offset = ($page - 1) * $page_size;

            $stmtCnt = $pdo->prepare("SELECT COUNT(*) $OCQ_FROM $whereSql");
            $stmtCnt->execute($params);
            $total = (int)$stmtCnt->fetchColumn();

            // 單次查詢筆數上限：超過就不撈資料、直接請使用者縮小範圍（使用者拍板，2026-09-11）。
            // 只擋「畫面清單」這一條路；列印／匯出／統整報表維持原本的 3000 筆確認視窗不變，
            // 才不會把既有「清除篩選後整批匯出」的用法弄壞。
            if ($total > OCQ_LIST_MAX) {
                echo json_encode(['success' => true, 'total' => $total, 'page' => 1, 'page_size' => $page_size,
                    'rows' => [], 'max_process_count' => 0, 'price_map' => [],
                    'blocked' => true, 'limit_max' => OCQ_LIST_MAX]);
                exit;
            }

            $sql = "SELECT $OCQ_COLS $OCQ_FROM $whereSql ORDER BY $OCQ_EFFDATE DESC, b.bom DESC LIMIT $page_size OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $bom_list = array_column($rows, 'bom');
            list($proc_map, $max_count) = ocq_fetch_processes($pdo, $bom_list);
            $price_map = ocq_fetch_prices($pdo, $bom_list);
            list($file_map, $cache_stale) = ocq_bom_file_map($bom_list);
            foreach ($rows as &$row) {
                $row['processes'] = $proc_map[$row['bom']] ?? [];
                $row['has_file']  = $file_map[$row['bom']] ?? null;   // true/false；null＝快取還沒建好、無法判定
            }
            unset($row);

            echo json_encode(['success' => true, 'total' => $total, 'page' => $page, 'page_size' => $page_size,
                'rows' => $rows, 'max_process_count' => $max_count, 'price_map' => $price_map,
                'bom_cache_refresh' => $cache_stale, 'limit_max' => OCQ_LIST_MAX]);
        } elseif ($action === 'get_print') {
            list($whereSql, $params) = ocq_build_filter($_POST);
            $sql = "SELECT $OCQ_COLS $OCQ_FROM $whereSql ORDER BY $OCQ_EFFDATE DESC, b.bom DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $bom_list = array_column($rows, 'bom');
            list($proc_map, $max_count) = ocq_fetch_processes($pdo, $bom_list);
            $price_map = ocq_fetch_prices($pdo, $bom_list);
            foreach ($rows as &$row) { $row['processes'] = $proc_map[$row['bom']] ?? []; }
            unset($row);

            $company = '';
            $cr = $pdo->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($cr) $company = $cr['customer_full'];

            echo json_encode(['success' => true, 'rows' => $rows, 'total' => count($rows),
                'max_process_count' => $max_count, 'price_map' => $price_map, 'company_name' => $company]);
        } elseif ($action === 'get_summary') {
            list($whereSql, $params) = ocq_build_filter($_POST);
            $sql = "SELECT b.bom, $OCQ_CLIENT_DISP AS client_name_display, b.closed_at,
                    (b.closed_at IS NULL) AS date_is_derived,
                    DATEDIFF(DATE(b.closed_at), $OCQ_BOMDATE) AS duration_days
                    $OCQ_FROM $whereSql";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total = count($rows);
            $excluded = 0; $qualified = [];
            $customerCount = [];
            foreach ($rows as $r) {
                $nm = $r['client_name_display'] ?: '（未知客戶）';
                $customerCount[$nm] = ($customerCount[$nm] ?? 0) + 1;
                if ($r['date_is_derived']) { $excluded++; } else { $qualified[] = $r; }
            }
            arsort($customerCount);
            $qualifiedCount = count($qualified);
            $avgDuration = null; $minRec = null; $maxRec = null;
            if ($qualifiedCount) {
                $sum = 0;
                foreach ($qualified as $r) {
                    $d = (int)$r['duration_days'];
                    $sum += $d;
                    if ($minRec === null || $d < (int)$minRec['duration_days']) $minRec = $r;
                    if ($maxRec === null || $d > (int)$maxRec['duration_days']) $maxRec = $r;
                }
                $avgDuration = round($sum / $qualifiedCount, 1);
            }
            if ($maxRec) {
                list($maxProcMap,) = ocq_fetch_processes($pdo, [$maxRec['bom']]);
                $maxRec['processes'] = $maxProcMap[$maxRec['bom']] ?? [];
            }

            // 製程分布（依目前篩選出的全部BOM，含推算日期者一起算，跟清單/列印的母體一致；筆數由多到少）
            $bom_list = array_column($rows, 'bom');
            $processes = [];
            if ($bom_list) {
                $ph = implode(',', array_fill(0, count($bom_list), '?'));
                $stmtPr = $pdo->prepare("SELECT pt.process_type AS category_name, COUNT(DISTINCT bi.bom) cnt
                    FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                    LEFT JOIN process_type pt ON pt.process_type_id = pn.process_type_id
                    WHERE bi.bom IN ($ph) GROUP BY pt.process_type_id, pt.process_type ORDER BY cnt DESC");
                $stmtPr->execute($bom_list);
                $processes = $stmtPr->fetchAll(PDO::FETCH_ASSOC);
            }

            $company = '';
            $cr = $pdo->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($cr) $company = $cr['customer_full'];

            $customers = [];
            foreach ($customerCount as $nm => $cnt) { $customers[] = ['name' => $nm, 'cnt' => $cnt]; }

            echo json_encode(['success' => true, 'total' => $total, 'excluded' => $excluded,
                'qualified' => $qualifiedCount, 'avg_duration' => $avgDuration,
                'min_record' => $minRec, 'max_record' => $maxRec,
                'processes' => $processes, 'customers' => $customers, 'company_name' => $company]);
        } elseif ($action === 'get_facets') {
            list($whereP, $paramsP) = ocq_build_filter($_POST, ['process_type']);
            // 這裡有一個看不出來但差 7 倍的重點：有全域關鍵字時，**一定要先把「命中的 bom」收成衍生表**
            // 再去 JOIN bom_ing。直接 `$OCQ_FROM INNER JOIN bom_ing` 的話，關鍵字那段 EXISTS 子查詢會對
            // 「bom × bom_ing」的每一列各算一次（bom_ing 有 82,937 列）而不是每個 bom 算一次，實測要 5.9 秒。
            // 內層的 `GROUP BY b.bom` 不是為了去重（本來就一個 bom 一列），是為了**強制 MySQL 具現化衍生表**
            // ——沒有它優化器會把衍生表併回外層，等於沒改。實測 5.9 秒 → 0.81 秒、結果逐欄完全相同。
            // **但具現化本身有約 250ms 的固定成本**，所以沒有關鍵字時刻意不加這個 GROUP BY：此時衍生表會被
            // 併回外層＝執行計畫與改寫前完全一樣，像「只篩交期」這種很選擇性的條件仍維持原本的 18ms。
            $matHint = (trim($_POST['keyword'] ?? '') !== '') ? 'GROUP BY b.bom' : '';
            $stmtP = $pdo->prepare("SELECT pn.process_type_id, pt.process_type AS category_name, COUNT(DISTINCT m.bom) cnt
                FROM (SELECT b.bom $OCQ_FROM $whereP $matHint) m
                INNER JOIN bom_ing bi_f ON bi_f.bom = m.bom
                LEFT JOIN process_no pn ON pn.ProcessNo = bi_f.process_no
                LEFT JOIN process_type pt ON pt.process_type_id = pn.process_type_id
                GROUP BY pn.process_type_id, pt.process_type ORDER BY pn.process_type_id");
            $stmtP->execute($paramsP);
            $processes = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'processes' => $processes]);
        } elseif ($action === 'get_options') {
            $stmtC = $pdo->query("SELECT DISTINCT $OCQ_CLIENT_DISP AS nm $OCQ_FROM
                WHERE b.processing_state='1' AND $OCQ_CLIENT_DISP IS NOT NULL AND $OCQ_CLIENT_DISP <> '' ORDER BY nm");
            $customers = $stmtC->fetchAll(PDO::FETCH_COLUMN);

            $stmtS = $pdo->query("SELECT DISTINCT u_sales_primary.user_cname AS nm $OCQ_FROM
                WHERE b.processing_state='1' AND u_sales_primary.user_cname IS NOT NULL AND u_sales_primary.user_cname <> '' ORDER BY nm");
            $sales = $stmtS->fetchAll(PDO::FETCH_COLUMN);

            $stmtV = $pdo->query("SELECT DISTINCT ml.maker_id AS nm FROM bom b
                INNER JOIN bom_ing bi ON bi.bom = b.bom LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no
                WHERE b.processing_state='1' AND ml.maker_id IS NOT NULL AND ml.maker_id <> '' ORDER BY nm");
            $vendors = $stmtV->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(['success' => true, 'customers' => $customers, 'sales' => $sales, 'vendors' => $vendors]);
        } elseif ($action === 'bom_file_cache_refresh') {
            // BOM .xlsm 檔名快取重建：**很慢（要掃 NAS）**，只由前端在畫面畫完之後背景呼叫，
            // 絕不放在清單查詢那條路上。鎖檔由 lib 處理，同一時間只會有一個人在掃。
            @ignore_user_abort(true);
            @set_time_limit(300);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            require_once __DIR__ . '/../../src/common/bom_dir_lib.php';
            $r = eg_bom_file_cache_refresh(eg_bom_scan_dir_auto(), ['xlsm']);
            echo json_encode(['success' => true] + $r);
        } else {
            echo json_encode(['success' => false, 'message' => '未知操作']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 年份切換列要列出哪些年：取「實際有已結案資料」的最早／最晚年份（含沒有 closed_at、以 BOM 編號
// 回推日期的舊資料）。不寫死年份，資料跨到新的一年就自動多一顆鈕（鐵律4）。實測約 30ms。
$ocq_year_min = (int)date('Y');
$ocq_year_max = (int)date('Y');
try {
    $yr = $pdo->query("SELECT MIN(YEAR($OCQ_EFFDATE)) AS mn, MAX(YEAR($OCQ_EFFDATE)) AS mx
                       FROM bom b WHERE b.processing_state='1'")->fetch(PDO::FETCH_ASSOC);
    if ($yr && $yr['mn']) {
        $ocq_year_min = max(1990, (int)$yr['mn']);
        $ocq_year_max = max((int)$yr['mx'], (int)date('Y'));   // 今年一定要看得到（就算今年還沒有結案資料）
    }
} catch (Throwable $e) { /* 取不到就退回「只有今年」，不讓年份列害整頁開不起來 */ }

?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>已完工BOM查詢列印</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin: 8px 0 4px; overflow: hidden; clear: both; }
        .page-help-btn { height: 30px; font-size: 13px; padding: 0 12px; border: 1px solid #d98a33; border-radius: 15px;
            background: #F0A24B; color: #fff; cursor: pointer; }
        .page-help-btn:hover { background: #d98a33; }
        @media print { .page-help-btn { display: none !important; } }
        .help-doc { font-size: 13px; color: #5b3a1e; line-height: 1.75; }
        .help-doc h4 { color: #8A5A2B; border-bottom: 2px solid #F7E0BD; padding-bottom: 3px; margin: 14px 0 6px; font-size: 15px; }
        .help-doc h4:first-child { margin-top: 0; }
        .help-doc b { color: #8A5A2B; }
        .help-doc ul { margin: 4px 0 8px; padding-left: 20px; }
        .help-doc li { margin: 2px 0; }
        .help-doc .tip { background: #FFF7E8; border: 1px dashed #F0A24B; border-radius: 6px; padding: 6px 10px; margin: 6px 0; }
        .ocq-tabs { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; clear: both;
            border: 1.5px solid #E8D5B5; border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; background: #FDF8EF; }
        .ocq-tab { height: 28px; font-size: 12px; line-height: 1; padding: 0 12px; border: 1px solid #D8BE93; border-radius: 14px;
            background: #fff; color: #5b3a1e; cursor: pointer; }
        .ocq-tab:hover { background: #F7E0BD; }
        .ocq-tab.active { background: #F0A24B; color: #fff; border-color: #d98a33; font-weight: bold; }
        .ocq-toolbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; clear: both;
            border: 1.5px solid #E8D5B5; border-radius: 8px; padding: 8px 10px; margin-bottom: 10px; background: #FDF8EF; }
        .ocq-toolbar label { margin: 0 0 0 6px; font-size: 13px; color: #5b3a1e; }
        .ocq-toolbar label:first-child { margin-left: 0; }
        .ocq-toolbar select, .ocq-toolbar input[type=text], .ocq-toolbar input[type=date], .ocq-toolbar button {
            height: 30px; font-size: 13px; line-height: 1; padding: 0 8px; border: 1px solid #D8BE93;
            border-radius: 4px; background: #fff; color: #5b3a1e; }
        .ocq-toolbar button { cursor: pointer; }
        .ocq-toolbar button:hover { background: #F7E0BD; }
        .ocq-toolbar .btn-warm { background: #F0A24B; color: #fff; border-color: #d98a33; }
        .ocq-toolbar .btn-warm:hover { background: #d98a33; }
        .ocq-stat { display: flex; align-items: center; gap: 14px; margin-bottom: 8px; font-size: 13px; color: #5b3a1e; }
        .ocq-stat b { color: #8A5A2B; font-size: 16px; }
        /* 結案年份快速切換列（配色沿用本頁既有的暖色系） */
        .ocq-years { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; width: 100%;
            margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px dashed #EADFC8; }
        .ocq-ybtn { height: 26px; min-width: 30px; padding: 0 10px; font-size: 12.5px; line-height: 1;
            border: 1px solid #D8BE93; background: #fff; color: #5b3a1e; border-radius: 13px; cursor: pointer; }
        .ocq-ybtn:hover:not(:disabled) { background: #FBF0DC; }
        .ocq-ybtn.active { background: #F0A24B; border-color: #d98a33; color: #fff; font-weight: bold; }
        .ocq-ybtn:disabled { opacity: .4; cursor: default; }
        .ocq-ysep { width: 1px; height: 18px; background: #E0D2B4; margin: 0 4px; }
        .ocq-yhint { font-size: 12px; color: #a06a1f; }
        /* 從 BOM總表跳窗帶關鍵字進來時的提示條。**刻意不沿用 .ocq-stat**——那個是 display:flex + gap:14px，
           句子裡的每個 <b> 都會變成獨立的 flex item 被 14px 間距撐開，變成一句話被切成好幾塊。 */
        .ocq-carry { display: block; margin-bottom: 8px; padding: 7px 10px; border-radius: 4px;
            background: #F7E0BD; border: 1px solid #E0B378; color: #5b3a1e; font-size: 12.5px; line-height: 1.7; }
        .ocq-carry b { color: #8A5A2B; }
        .ocq-bom-link { color: #1d5fa8; text-decoration: none; }
        .ocq-bom-link:hover { color: #0d3f77; text-decoration: underline; }
        /* 製程大類統計是背景補上的（比清單慢），補回來之前先淡化，不要讓頁籤整排消失 */
        .ocq-facet-loading { opacity: .45; }
        .ocq-facet-loading::after { content: '統計中…'; font-size: 12px; color: #a06a1f; margin-left: 6px; align-self: center; }
        /* 超過單次查詢筆數上限時的提示區 */
        .ocq-blocked { padding: 22px 18px; text-align: center; color: #5b3a1e; }
        .ocq-blocked .bk-title { font-size: 14px; font-weight: bold; color: #a0521f; margin-bottom: 6px; }
        .ocq-blocked .bk-acts { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; }
        .ocq-table-wrap { overflow-x: auto; border: 1px solid #E8D5B5; border-radius: 6px; background: #fff; }
        table.ocq-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.ocq-table th, table.ocq-table td { border: 1px solid #EADFC8; padding: 5px 8px; white-space: nowrap; text-align: center; vertical-align: top; }
        table.ocq-table thead th { background: #F7E0BD; color: #5b3a1e; font-weight: bold; }
        table.ocq-table tbody tr:nth-child(even) { background: #FBF6EC; }
        table.ocq-table tbody tr:hover { background: #FBF0DD; }
        table.ocq-table td.t-left { text-align: left; white-space: normal; }
        .ocq-pager { display: flex; justify-content: flex-end; align-items: center; gap: 5px; margin: 10px 2px 4px; flex-wrap: wrap; }
        .ocq-pager .pg-info { font-size: 12px; color: #8a6d45; margin-right: auto; }
        .ocq-pager button { min-width: 30px; height: 28px; padding: 0 9px; border: 1px solid #D8BE93; background: #fff; color: #5b3a1e; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .ocq-pager button:hover:not(:disabled) { background: #F7E0BD; }
        .ocq-pager button.cur { background: #F0A24B; color: #fff; border-color: #F0A24B; font-weight: bold; }
        .ocq-pager button:disabled { opacity: .4; cursor: default; }
        .ocq-empty { padding: 30px; text-align: center; color: #8a6d45; }
        .circle_red, .circle_y, .circle_green { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
        .circle_red { background: #DD5138; }
        .circle_y { background: #F0A24B; }
        .circle_green { background: #F7E0BD; border: 1px solid #D8BE93; }
        .ocq-sub { font-size: 10px; color: #999; margin-top: 3px; line-height: 1.4; }
        .ocq-price { margin-top: 3px; font-size: 11px; line-height: 1.3; }
        .ocq-fillable { cursor: pointer; border-bottom: 1px dashed #D8BE93; }
        .ocq-fillable:hover { background: #FFF3E2; }
        .ocq-nowrap { white-space: nowrap; }
        /* 料號點一下開圖面查閱（bom_viewer）；整格仍可雙擊帶入篩選 */
        .ocq-part-link { cursor: pointer; color: #8a5a00; text-decoration: underline dotted; }
        .ocq-part-link:hover { color: #DD5138; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">已完工BOM查詢列印
                <small style="color:#8a6d45;">查詢已結案BOM，可篩選/分頁/列印/匯出CSV</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

        <!-- 製程大類：動態連動，只列目前其餘篩選條件下「有資料」的製程大類 -->
        <div class="ocq-tabs" id="processTabs">
            <button class="ocq-tab active" data-pn="">全部</button>
        </div>

        <div class="ocq-toolbar">
            <!-- 結案年份快速切換：預設「近1年」，點年份只看那一年、箭號往前/往後推一年（使用者拍板，2026-09-11）。
                 這一列會連動下方的結案日期區間欄位，使用者仍可自己手打日期（手打後年份鈕就不再反白＝自訂區間）。 -->
            <div class="ocq-years" id="yearBar">
                <label style="margin-left:0;white-space:nowrap;">結案年份</label>
                <button class="ocq-ybtn" id="yPrev" title="往前一年">&laquo;</button>
                <span id="yearList" style="display:inline-flex;gap:4px;"></span>
                <button class="ocq-ybtn" id="yNext" title="往後一年">&raquo;</button>
                <span class="ocq-ysep"></span>
                <button class="ocq-ybtn" data-range="1y" title="今天往前推一年（預設）">近1年</button>
                <button class="ocq-ybtn" data-range="all" title="不限日期查全部歷史（資料量大時會較慢）">全部年份</button>
                <span id="yearHint" class="ocq-yhint"></span>
            </div>
            <div style="display:flex;flex-wrap:nowrap;overflow-x:auto;gap:6px;align-items:center;width:100%;">
                <label title="2026-05-22「手動結案」功能上線前就已結案的舊資料沒有結案時間紀錄，改用BOM編號回推的建立日期篩選/顯示，並標註「(推算)」" style="cursor:help;border-bottom:1px dotted #a06a1f;white-space:nowrap;">結案日期 <i class="fa fa-info-circle" style="color:#a06a1f;"></i></label>
                <input type="date" id="fDateFrom" max="9999-12-31">
                <span>～</span>
                <input type="date" id="fDateTo" max="9999-12-31">
                <label style="white-space:nowrap;">客戶</label>
                <input type="text" id="fCustomer" list="ocqCustomerList" placeholder="客戶名稱或代號" style="width:110px;">
                <datalist id="ocqCustomerList"></datalist>
                <label>業務</label>
                <input type="text" id="fSales" list="ocqSalesList" placeholder="負責業務" style="width:90px;">
                <datalist id="ocqSalesList"></datalist>
                <label>優先權</label>
                <select id="fPriority">
                    <option value="">（全部）</option>
                    <option value="N">一般</option>
                    <option value="U">急件U</option>
                    <option value="E">特急件E</option>
                </select>
                <button class="btn-warm" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
                <button id="btnClear" title="清掉所有篩選條件，日期回到預設的近1年（要查全部歷史請按上方年份列的「全部年份」）"><i class="fa fa-eraser"></i> 清除篩選</button>
                <button id="btnPrint" style="margin-left:auto;"><i class="fa fa-print"></i> 列印</button>
                <button id="btnExportCsv"><i class="fa fa-file-excel-o"></i> 匯出CSV</button>
                <button id="btnSummary"><i class="fa fa-bar-chart"></i> 統整報表(PDF)</button>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;width:100%;margin-top:6px;padding-top:6px;border-top:1px dashed #EADFC8;">
                <label style="margin-left:0;">BOM/料號</label>
                <input type="text" id="fBom" placeholder="關鍵字" style="width:120px;">
                <label>廠商</label>
                <input type="text" id="fVendor" list="ocqVendorList" placeholder="廠商名稱或代號" style="width:110px;">
                <datalist id="ocqVendorList"></datalist>
                <label>發單數量</label>
                <input type="text" id="fQty" placeholder="例：>100" style="width:90px;">
                <label>交期</label>
                <input type="text" id="fDelivery" placeholder="例：2/8、>2/8" style="width:110px;">
                <label>全域搜尋</label>
                <input type="text" id="fKeyword" placeholder="關鍵字(可空白分隔多個)" style="width:200px;">
            </div>
        </div>

        <div class="ocq-stat">
            <span>共 <b id="statTotal">0</b> 筆</span>
            <small style="color:#a06a1f;"><i class="fa fa-hand-pointer-o"></i> 點料號可開啟圖面查閱；雙擊表格中的客戶／BOM／料號可快速帶入篩選</small>
            <label style="margin-left:auto;">每頁</label>
            <select id="pageSizeSel" style="height:28px;">
                <option value="5">5</option>
                <option value="10" selected>10</option>
                <option value="20">20</option>
                <option value="50">50</option>
            </select>
            <span>筆</span>
        </div>

        <div class="ocq-table-wrap">
            <table class="ocq-table" id="ocqTable">
                <thead><tr id="ocqTheadRow">
                    <th>客戶</th><th class="t-left">BOM</th><th class="t-left">料號</th><th>數量</th><th>交期</th><th>業務</th>
                </tr></thead>
                <tbody id="ocqTbody"><tr><td colspan="6" class="ocq-empty">請設定篩選條件後查詢</td></tr></tbody>
            </table>
        </div>
        <div class="ocq-pager" id="ocqPager"></div>
    </div>
</div>
</div>

<div class="ocq-mask" id="helpUseMask" style="display:none;position:fixed;inset:0;background:rgba(60,40,20,.45);z-index:1050;"><div style="background:#fff;border-radius:8px;max-width:560px;margin:60px auto;box-shadow:0 5px 25px rgba(0,0,0,.3);max-height:82vh;display:flex;flex-direction:column;">
    <div style="background:#F7E0BD;color:#5b3a1e;font-weight:bold;padding:10px 15px;border-radius:8px 8px 0 0;display:flex;justify-content:space-between;"><span><i class="fa fa-question-circle"></i> 已完工BOM查詢列印 使用說明</span><span style="cursor:pointer;color:#b5762a;" onclick="document.getElementById('helpUseMask').style.display='none'">✕</span></div>
    <div style="padding:15px;overflow-y:auto;" class="help-doc">
        <h4>功能說明</h4>
        <p>逐筆列出所有已結案(結案)的BOM，供查找特定客戶/業務/廠商/日期/製程的已完工資料並列印或匯出，取代主頁「查詢已完工資料」跳窗固定50筆上限、只能用單一關鍵字搜尋的限制；跳窗維持不變，兩者並存。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>上方篩選可組合使用：製程大類（頁籤按鈕）、結案日期區間、客戶、業務、優先權燈號、BOM/料號、廠商、發單數量、交期、全域關鍵字，<b>輸入時即時篩選</b>（不需按Enter或查詢鈕）。</li>
            <li>客戶／廠商欄位可輸入名稱或代號（部分字串模糊比對皆可）。</li>
            <li>表格內的<b>料號點一下</b>即開啟「圖面查閱」視窗（bom_viewer），可直接看該料號的圖面與附件；同一料號重複點會沿用同一個視窗，不會開一堆。</li>
            <li>表格內的<b>客戶／BOM／料號用滑鼠雙擊</b>可直接帶入對應篩選框並立即查詢，方便快速鎖定同客戶或同BOM的其他資料（料號欄請雙擊文字以外的空白處，文字本身是開圖面用的）。</li>
            <li>篩選框有內容時雙擊可清空（全站共用規則），清空後會自動連帶重新查詢。</li>
            <li>製程大類的可選清單會依「目前其餘篩選條件」動態連動，只列真的有資料的選項。</li>
            <li><b>結案年份</b>快速切換列：預設<b>近1年</b>；點年份只看那一年、用 &laquo; &raquo; 往前／往後推一年，另有「全部年份」（不限日期，資料量大時較慢）。自己手打結案日期時年份鈕會顯示「（自訂區間）」。</li>
            <li>按「清除篩選」可清掉所有條件、日期回到預設的近1年。</li>
            <li>從 BOM總表「查詢已完工資料」跳窗按<b>「前往完整查詢（無筆數上限）」</b>進來時，該跳窗的關鍵字會自動帶進上方<b>全域搜尋</b>，日期維持本頁預設的<b>近1年</b>；畫面上方的橘色提示條會一併告訴你「該跳窗以全部年份查到幾筆」，要看全部歷史按提示條上的<b>「改看全部年份」</b>即可。</li>
            <li>表格內的 <b>BOM 編號點一下</b>會用 Excel 開啟 NAS 上對應的 BOM 檔（與 BOM總表相同）；<b>NAS 上找不到該檔時就只顯示文字、不給連結</b>，不會點了沒反應。要雙擊帶入篩選請點該格文字以外的空白處。</li>
            <li>全域關鍵字可用空白分隔多個關鍵字，每個關鍵字都要在（可分散於不同欄位）命中才算符合。</li>
            <li>列表分頁顯示（避免一次載入全部拖慢速度），可調整每頁筆數。</li>
            <li>「列印」「匯出CSV」「統整報表」皆依目前篩選條件抓「全部」符合筆數（不受分頁限制）。</li>
        </ul>
        <h4>重要行為/常見疑問</h4>
        <div class="tip"><b>單次查詢上限 5,000 筆</b>：畫面清單查出來超過這個筆數時不會顯示資料，改提示你縮小結案年份或加上關鍵字／客戶等條件（提示裡直接附了各年份的快速按鈕）。「全部年份」目前共一萬多筆，所以按下去會看到這個提示，這是正常的。<b>「列印」「匯出CSV」「統整報表」不受這個上限限制</b>，要一次取得全部資料請用那三顆。</div>
        <div class="tip"><b>製程大類頁籤是背景載入的</b>：清單會先出現（約 0.1～1 秒），製程大類的筆數統計比較花時間，補上來之前該列會淡化並顯示「統計中…」，不會卡住整頁。</div>
        <div class="tip">若篩選結果筆數較多（超過3000筆），列印/匯出/統整報表前會先跳出確認提示，避免不小心產生過大的工作。</div>
        <div class="tip">結案日期是2026-05-22「手動結案」功能上線才開始記錄的，在此之前就已結案的舊資料（約佔已結案總數九成以上）完全沒有結案時間紀錄。這類舊資料改用「BOM編號回推的建立日期」代替（BOM編號格式固定為 B-民國年3碼+月2碼+日2碼+流水號3碼），清單/列印上會標註「(推算)」以資區別；此推算日期同時用於日期篩選與排序。</div>
        <div class="tip">「統整報表」的<b>結案耗時</b>統計（平均/最短/最長結案時間）只計算真的有結案時間紀錄的BOM（合格結案紀錄），無結案時間、改用BOM編號推算日期的舊資料一律不列入耗時計算（會顯示在「不列入計算筆數」），因為推算日期本身就是建立日期，拿來跟自己相減沒有意義。</div>
        <ul>
            <li>「業務」欄只顯示該客戶原本負責業務，不判斷代理人是否正在請假（歷史資料的負責業務是固定事實）。</li>
            <li>優先權燈號：橘色=急件U、紅色=特急件E、淺色=一般。</li>
            <li>統整報表的製程分布/客戶分布皆依BOM筆數由多到少排序。</li>
        </ul>
        <h4>設定入口</h4>
        <p>無需另外設定，資料即時取自BOM系統，結案狀態依主頁的結案/取消結案操作即時反映。</p>
        <h4>權限角色</h4>
        <p>凡對「BOM 總表」（bom_TEST）具有任一權限（檢視/新增/修改/刪除）者即可使用本頁查詢與列印。</p>
    </div>
    <div style="padding:10px 15px;border-top:1px solid #EADFC8;text-align:right;"><button style="height:30px;padding:0 16px;border-radius:4px;font-size:13px;border:1px solid #d98a33;cursor:pointer;background:#F0A24B;color:#fff;" onclick="document.getElementById('helpUseMask').style.display='none'">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
document.getElementById('btnPageHelp').addEventListener('click', function(){ document.getElementById('helpUseMask').style.display='block'; });

var lastTotal = 0;
var curPage = 1;
var curProcess = '';
// BOM 檔案的網址前綴，與 OreadyReply_ForPm_BaseOfTime.php 相同（Apache 的 Alias /nas 指到 BOM 資料夾）
var OCQ_NAS_BASE = window.location.protocol + '//' + window.location.host + '/nas/';

function esc(s){ return $('<div>').text(s==null?'':String(s)).html(); }
function todayStr(){ var d=new Date(); return d.toISOString().substr(0,10); }
function addDaysStr(days){ var d=new Date(); d.setDate(d.getDate()+days); return d.toISOString().substr(0,10); }

// ── 結案年份快速切換（2026-09-11 使用者拍板：預設「近1年」、單年頁籤＋前後箭號）─────────────
// 刻意**不另外記一份「目前選了哪個年份」的狀態**：唯一真相就是下方那兩個日期輸入框，年份鈕只是
// 「幫忙把日期填好」＋「依目前日期反推該反白哪一顆」。這樣清除篩選、從跳窗帶關鍵字進來、使用者
// 自己手打日期，三條路都不必各自去同步狀態，也不會出現「鈕反白著但日期其實是別的區間」。
var OCQ_YEAR_MIN = <?= (int)$ocq_year_min ?>, OCQ_YEAR_MAX = <?= (int)$ocq_year_max ?>;
var OCQ_LIST_MAX = <?= (int)OCQ_LIST_MAX ?>;

function ySetDates(from, to){ $('#fDateFrom').val(from); $('#fDateTo').val(to); }
function yRange1y(){ return [addDaysStr(-364), todayStr()]; }

// 依目前日期區間反推現在是哪一種模式：{mode:'year'|'1y'|'all'|'custom', year:數字或null}
function yCurrentMode(){
    var f = $('#fDateFrom').val(), t = $('#fDateTo').val();
    if (!f && !t) return { mode:'all', year:null };
    var m = /^(\d{4})-01-01$/.exec(f);
    if (m && t === m[1] + '-12-31') return { mode:'year', year:parseInt(m[1],10) };
    var r = yRange1y();
    if (f === r[0] && t === r[1]) return { mode:'1y', year:null };
    return { mode:'custom', year:null };
}

function renderYearBar(){
    var st = yCurrentMode();
    var $list = $('#yearList').empty();
    for (var y = OCQ_YEAR_MAX; y >= OCQ_YEAR_MIN; y--){
        $('<button class="ocq-ybtn' + (st.mode==='year' && st.year===y ? ' active' : '') + '">' + y + '</button>')
            .on('click', (function(yy){ return function(){ ySetDates(yy+'-01-01', yy+'-12-31'); renderYearBar(); applyFilters(); }; })(y))
            .appendTo($list);
    }
    $('#yearBar .ocq-ybtn[data-range="1y"]').toggleClass('active', st.mode==='1y');
    $('#yearBar .ocq-ybtn[data-range="all"]').toggleClass('active', st.mode==='all');
    // 箭號：單年模式下往前/往後推一年；不在單年模式時按下就跳到最新的一年當起點
    $('#yPrev').prop('disabled', st.mode==='year' && st.year<=OCQ_YEAR_MIN);
    $('#yNext').prop('disabled', st.mode==='year' && st.year>=OCQ_YEAR_MAX);
    $('#yearHint').text(st.mode==='custom' ? '（自訂區間）' : (st.mode==='all' ? '（不限日期，資料量大時較慢）' : ''));
}

function yStep(delta){
    var st = yCurrentMode();
    var y = (st.mode === 'year') ? st.year + delta : OCQ_YEAR_MAX;
    if (y < OCQ_YEAR_MIN) y = OCQ_YEAR_MIN;
    if (y > OCQ_YEAR_MAX) y = OCQ_YEAR_MAX;
    ySetDates(y+'-01-01', y+'-12-31');
    renderYearBar(); applyFilters();
}
$('#yPrev').on('click', function(){ yStep(-1); });
$('#yNext').on('click', function(){ yStep(1); });
$('#yearBar .ocq-ybtn[data-range]').on('click', function(){
    if ($(this).data('range') === 'all') ySetDates('', '');
    else { var r = yRange1y(); ySetDates(r[0], r[1]); }
    renderYearBar(); applyFilters();
});

// 預設近1年（原本是近30天；使用者拍板改為近1年，跨年時才不會1月1日一開就只剩幾天的資料）
(function(){ var r = yRange1y(); $('#fDateFrom').val(r[0]); $('#fDateTo').val(r[1]); })();

function curFilters(){
    return {
        date_from: $('#fDateFrom').val(),
        date_to: $('#fDateTo').val(),
        process_type: curProcess,
        customer: $.trim($('#fCustomer').val()),
        sales: $.trim($('#fSales').val()),
        priority: $('#fPriority').val(),
        bom: $.trim($('#fBom').val()),
        vendor: $.trim($('#fVendor').val()),
        qty: $.trim($('#fQty').val()),
        delivery: $.trim($('#fDelivery').val()),
        keyword: $.trim($('#fKeyword').val())
    };
}

function fmtPrice(v){
    var n = parseFloat(v) || 0;
    return n === 0 ? '' : (n % 1 === 0 ? n.toFixed(0) : n.toFixed(1));
}

function buildThead(maxProc){
    var $tr = $('#ocqTheadRow').empty();
    $tr.append('<th>客戶</th><th class="t-left">BOM</th><th class="t-left">料號</th><th>數量</th><th>交期</th><th>業務</th>');
    for (var i=1; i<=maxProc; i++) $tr.append('<th>製程'+i+'</th>');
    return 6 + maxProc;
}

function rowToTr(item, maxProc, priceMap){
    var cc = item.priority_type==='E' ? 'circle_red' : (item.priority_type==='U' ? 'circle_y' : 'circle_green');
    var closedInfo = '<div class="ocq-sub">' + (item.closed_by_name ? '結：'+esc(item.closed_by_name)+'　' : '')
        + (item.date_is_derived == 1 || item.date_is_derived === true
            ? '<span title="無結案時間紀錄，依BOM編號推算">'+esc(egFmtDate(item.effective_date))+'(推算)</span>'
            : esc(item.closed_at))
        + '</div>';
    var bomPrices = (priceMap && priceMap[item.bom]) || {};
    var totalUnitPrice = 0, noPriceCount = 0;
    (item.processes || []).forEach(function(p){
        var pi = bomPrices[String(p.bom_sn)] || null;
        var rawP = pi ? (parseFloat(pi.modified_unit_price) || parseFloat(pi.price) || 0) : 0;
        if (rawP > 0) totalUnitPrice += rawP; else noPriceCount++;
    });
    var priceHtml = (item.processes||[]).length
        ? '<div class="ocq-price">' + (totalUnitPrice > 0 ? '<span style="color:#0a6;font-weight:bold;">$'+fmtPrice(totalUnitPrice)+'</span>' : '<span style="color:#ccc;">$--</span>')
          + (noPriceCount > 0 ? ' <span style="color:#aaa;font-size:10px;">('+noPriceCount+'關無價)</span>' : '') + '</div>' : '';

    // 整格(含padding空白處)都要能雙擊帶入篩選，不能只有文字字元本身的範圍才有反應（客戶/料號常是短字串，
    // 文字四周空白很大，只綁在文字span上很容易點在空白處沒反應）；值改用 data-val(URI編碼) 傳遞，
    // 避免客戶名稱等內容含特殊字元時打斷HTML屬性字串。
    var custTd = '<td class="ocq-fillable" data-field="customer" data-val="'+encodeURIComponent(item.client_name_display||'')+'" title="雙擊帶入客戶篩選">'+esc(item.client_name_display||'')+'</td>';
    // BOM 編號：跟 BOM總表一樣點一下用 Excel 開 NAS 上的 <BOM>.xlsm。
    // has_file 是後端用檔名快取查出來的：true＝有檔給連結、false＝沒檔只印文字（使用者要求「若無檔案可不顯示超連結」）、
    // null＝快取還沒建好無法判定，此時比照 BOM總表現況照樣給連結（寧可點下去沒開，也不要讓大家突然都點不到）。
    var bomText = item.has_file === false
        ? '<span class="ocq-nowrap" title="NAS 上找不到這個 BOM 的 .xlsm 檔">'+esc(item.bom)+'</span>'
        : '<a class="ocq-nowrap ocq-bom-link" href="ms-excel:ofe|u|'+OCQ_NAS_BASE+encodeURIComponent(item.bom)+'.xlsm" target="_blank" title="點擊以 Excel 開啟此 BOM 檔">'+esc(item.bom)+'</a>';
    var bomTd = '<td class="t-left ocq-fillable" data-field="bom" data-val="'+encodeURIComponent(item.bom||'')+'" title="雙擊帶入BOM/料號篩選"><figure class="'+cc+'"></figure>'+bomText+closedInfo+'</td>';
    // 料號文字本身＝點一下開圖面查閱（bom_viewer）；文字以外的空白處維持雙擊帶入篩選
    var didText = item.d_id
        ? '<span class="ocq-part-link" data-part="'+encodeURIComponent(item.d_id)+'" data-pk="'+(parseInt(item.d_setting_id,10)||0)+'" title="點擊開啟圖面查閱">'+esc(item.d_id)+'</span>'
        : '';
    var didTd = '<td class="t-left ocq-fillable" data-field="bom" data-val="'+encodeURIComponent(item.d_id||'')+'" title="雙擊帶入BOM/料號篩選">'+didText+priceHtml+'</td>';
    var tds = custTd + bomTd + didTd
        + '<td>'+esc(item.Qty||'')+'</td>'
        + '<td>'+esc(item.Delivery_date ? egFmtDate(item.Delivery_date) : '')+'</td>'
        + '<td>'+esc(item.sales_name||'')+'</td>';
    var procs = item.processes || [];
    for (var i=0; i<maxProc; i++){
        var p = procs[i];
        if (!p) { tds += '<td></td>'; continue; }
        var pi = bomPrices[String(p.bom_sn)] || null;
        var pv = pi ? (parseFloat(pi.modified_unit_price) || parseFloat(pi.price) || 0) : 0;
        var cell = '<div>'+esc((p.process_no||'')+(p.ProcessName?' '+p.ProcessName:''))+'</div>';
        if (p.outsource_date || p.maker_id) cell += '<small style="color:#888;">'+esc((p.outsource_date||'')+(p.maker_id?' '+p.maker_id:''))+'</small>';
        if (p.return_date) cell += '<div style="color:#2a7ae2;font-weight:bold;">回廠:'+esc(p.return_date)+'</div>';
        if (pv > 0) cell += '<div style="color:#0a6;font-size:10px;">$'+fmtPrice(pv)+'</div>';
        tds += '<td class="t-left">'+cell+'</td>';
    }
    return '<tr>'+tds+'</tr>';
}

function renderPager(total, page, pageSize){
    var pages = Math.max(1, Math.ceil(total / pageSize));
    var $p = $('#ocqPager').empty();
    $p.append('<span class="pg-info">第 ' + page + ' / ' + pages + ' 頁</span>');
    var $prev = $('<button><i class="fa fa-chevron-left"></i></button>').prop('disabled', page<=1).on('click', function(){ loadList(page-1); });
    $p.append($prev);
    var from = Math.max(1, page-2), to = Math.min(pages, from+4);
    from = Math.max(1, Math.min(from, to-4));
    for (var i=from; i<=to; i++){
        var $b = $('<button>'+i+'</button>');
        if (i===page) $b.addClass('cur');
        $b.on('click', (function(pn){ return function(){ loadList(pn); }; })(i));
        $p.append($b);
    }
    var $next = $('<button><i class="fa fa-chevron-right"></i></button>').prop('disabled', page>=pages).on('click', function(){ loadList(page+1); });
    $p.append($next);
}

var _listSeq = 0;
function loadList(page){
    page = page || 1;
    curPage = page;
    var f = curFilters();
    f.action = 'list';
    f.page = page;
    f.page_size = $('#pageSizeSel').val();
    var seq = ++_listSeq;
    $('#ocqTbody').html('<tr><td colspan="6" class="ocq-empty"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    $.post('', f, function(res){
        if (seq !== _listSeq) return;             // 已經有更新的一次查詢在跑，這份丟掉
        if (!res.success){ $('#ocqTbody').html('<tr><td colspan="6" class="ocq-empty">' + esc(res.message||'查詢失敗') + '</td></tr>'); return; }
        lastTotal = res.total;
        $('#statTotal').text(Number(res.total).toLocaleString('en-US'));
        var colCount = buildThead(res.max_process_count || 0);
        if (res.blocked){
            $('#ocqTbody').html('<tr><td colspan="'+colCount+'">' + blockedHtml(res.total, res.limit_max) + '</td></tr>');
            $('#ocqPager').empty();
            bindBlockedActions();
            return;
        }
        if (!res.rows.length){
            $('#ocqTbody').html('<tr><td colspan="'+colCount+'" class="ocq-empty">查無符合條件的已完工BOM</td></tr>');
        } else {
            $('#ocqTbody').html(res.rows.map(function(r){ return rowToTr(r, res.max_process_count||0, res.price_map||{}); }).join(''));
        }
        renderPager(res.total, res.page, res.page_size);

        // BOM .xlsm 檔名快取還沒建好或已過期時，等畫面畫完才背景重建（掃 NAS 很慢，不可擋在查詢路徑上）。
        // 一次只發一輪；失敗也不提示，下次查詢會再試。
        if (res.bom_cache_refresh && !window.__ocqBomCacheRefreshing){
            window.__ocqBomCacheRefreshing = true;
            $.post('', { action: 'bom_file_cache_refresh' })
             .always(function(){ window.__ocqBomCacheRefreshing = false; });
        }
    }, 'json');
}

// 超過單次查詢筆數上限時顯示的內容：講清楚為什麼、並直接給可以馬上按的縮小範圍選項
function blockedHtml(total, max){
    var yrs = '';
    for (var y = OCQ_YEAR_MAX; y >= Math.max(OCQ_YEAR_MIN, OCQ_YEAR_MAX-4); y--){
        yrs += '<button class="ocq-ybtn bk-year" data-y="'+y+'">只看 '+y+' 年</button>';
    }
    return '<div class="ocq-blocked">'
        + '<div class="bk-title"><i class="fa fa-exclamation-triangle"></i> 查詢結果共 '
        + Number(total).toLocaleString('en-US') + ' 筆，超過單次查詢上限 ' + Number(max).toLocaleString('en-US') + ' 筆</div>'
        + '<div>資料量太大時整頁會變得很慢，請先縮小結案年份範圍、或加上關鍵字／客戶等條件再查詢。</div>'
        + '<div class="bk-acts">' + yrs
        + '<button class="ocq-ybtn bk-1y">近1年</button></div>'
        + '<div style="margin-top:10px;font-size:12px;color:#8a6d45;">'
        + '仍要一次取得全部資料時，可直接用上方的「列印」「匯出CSV」「統整報表」——那三項不受這個上限限制。</div>'
        + '</div>';
}
function bindBlockedActions(){
    $('#ocqTbody .bk-year').on('click', function(){
        var y = $(this).data('y');
        ySetDates(y+'-01-01', y+'-12-31'); renderYearBar(); applyFilters();
    });
    $('#ocqTbody .bk-1y').on('click', function(){
        var r = yRange1y(); ySetDates(r[0], r[1]); renderYearBar(); applyFilters();
    });
}

function renderProcessTabs(list){
    var $t = $('#processTabs').empty();
    var total = 0;
    list.forEach(function(p){ total += parseInt(p.cnt,10); });
    $('<button class="ocq-tab' + (curProcess===''?' active':'') + '">全部（' + total + '）</button>')
        .on('click', function(){ curProcess=''; applyFilters(); }).appendTo($t);
    var stillValid = false;
    list.forEach(function(p){
        var tid = String(p.process_type_id);
        if (tid === curProcess) stillValid = true;
        $('<button class="ocq-tab' + (tid===curProcess?' active':'') + '">' + esc(p.category_name || '（未分類）') + '（' + p.cnt + '）</button>')
            .on('click', function(){ curProcess=tid; applyFilters(); }).appendTo($t);
    });
    if (curProcess !== '' && !stillValid) curProcess = '';
}

// 製程大類頁籤是「慢的那一支」（要對命中的每個 BOM 去統計製程），而清單只要 0.1~0.6 秒。
// **絕對不要再讓清單等它**（原本是 refreshFacets 成功後才 loadList，於是畫面要空等 6 秒才有東西）。
// 這裡改成各跑各的：清單立刻載、頁籤在背景補上，補回來之前先顯示「統計中…」而不是讓頁籤消失。
var _facetSeq = 0;
function refreshFacets(cb){
    var f = curFilters();
    f.action = 'get_facets';
    var seq = ++_facetSeq;
    $('#processTabs').addClass('ocq-facet-loading');
    $.post('', f, function(res){
        if (seq !== _facetSeq) return;            // 使用者又改了條件，這份已經過期，丟掉不要蓋掉新的
        $('#processTabs').removeClass('ocq-facet-loading');
        if (res.success) renderProcessTabs(res.processes || []);
        if (cb) cb();
    }, 'json').fail(function(){
        if (seq === _facetSeq) $('#processTabs').removeClass('ocq-facet-loading');
    });
}

function loadOptions(){
    $.post('', { action: 'get_options' }, function(res){
        if (!res.success) return;
        $('#ocqCustomerList').html((res.customers||[]).map(function(v){ return '<option value="'+esc(v)+'">'; }).join(''));
        $('#ocqSalesList').html((res.sales||[]).map(function(v){ return '<option value="'+esc(v)+'">'; }).join(''));
        $('#ocqVendorList').html((res.vendors||[]).map(function(v){ return '<option value="'+esc(v)+'">'; }).join(''));
    }, 'json');
}
loadOptions();

// 清單先跑（快），製程大類頁籤在背景補（慢）——兩支各自發出，畫面不會空等。
function applyFilters(){ loadList(1); refreshFacets(); }

$('#btnSearch').on('click', applyFilters);
// 手打／用月曆改結案日期時，年份鈕要跟著重算該反白哪一顆（改成不是整年就顯示「（自訂區間）」）
['#fDateFrom','#fDateTo'].forEach(function(sel){ $(sel).on('change', renderYearBar); });
['#fDateFrom','#fDateTo','#fPriority'].forEach(function(sel){ $(sel).on('change', applyFilters); });
// 即時篩選（防抖200ms，跟主頁全域搜尋同款）；eg_input_rules.js的「有值雙擊清空」也會觸發input事件，
// 因此雙擊清空篩選框內容時會自動連帶重新查詢，不需要另外處理。
var _ocqDebounce = null;
function debouncedApplyFilters(){
    clearTimeout(_ocqDebounce);
    _ocqDebounce = setTimeout(applyFilters, 200);
}
['#fCustomer','#fSales','#fBom','#fVendor','#fQty','#fDelivery','#fKeyword'].forEach(function(sel){
    $(sel).on('input', debouncedApplyFilters);
});
$('#pageSizeSel').on('change', function(){ loadList(1); });

// 雙擊表格中的客戶／BOM／料號 → 帶入對應篩選框並立即查詢
$('#ocqTbody').on('dblclick', '.ocq-fillable', function(){
    var field = $(this).data('field');
    var raw = $(this).attr('data-val') || '';
    var val = $.trim(decodeURIComponent(raw));
    if (!val) return;
    if (field === 'customer') $('#fCustomer').val(val);
    else if (field === 'bom') $('#fBom').val(val);
    applyFilters();
});

// 點料號 → 開啟圖面查閱（bom_viewer.php?d_id=…）
$('#ocqTbody').on('click', '.ocq-part-link', function(e){
    e.stopPropagation();
    openPartDrawing(decodeURIComponent($(this).attr('data-part') || ''), $(this).attr('data-pk') || 0);
});
// 料號文字上的雙擊不再連帶觸發「帶入篩選」（避免同時開窗又改篩選條件）
$('#ocqTbody').on('dblclick', '.ocq-part-link', function(e){ e.stopPropagation(); });
// BOM 文字上的雙擊同理：擋掉「開第二次 Excel」與「帶入篩選」，要帶入篩選請雙擊該格文字以外的空白處
$('#ocqTbody').on('dblclick', '.ocq-bom-link', function(e){ e.preventDefault(); e.stopPropagation(); });

// 開啟圖面查閱視窗（同一料號重複點沿用同一個視窗，不會開一堆）
// pk＝d_setting.d_id（整數 PK）：同名料號可能有多筆主檔（不同客戶／版次），不指名會混在一起
function openPartDrawing(pid, pk){
    if (!pid && !pk) return;
    var w = screen.availWidth, h = screen.availHeight;
    var pw = Math.min(1400, Math.round(w * 0.85));
    var ph = Math.min(900,  Math.round(h * 0.88));
    var pl = Math.round((w - pw) / 2);
    var pt = Math.round((h - ph) / 2);
    var q = pk ? ('?pk=' + encodeURIComponent(pk)) : ('?d_id=' + encodeURIComponent(pid));
    window.open('bom_viewer.php' + q,
        'bom_dv_' + (pk || pid),
        'width='+pw+',height='+ph+',left='+pl+',top='+pt
            + ',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no');
}

$('#btnClear').on('click', function(){
    // 原本這顆是「清成不限日期＝查全部」；現在「全部年份」已經有自己的按鈕在年份列上，
    // 所以這顆改成回到預設的近1年（否則按一下就會撞到單次查詢筆數上限，看起來像壞掉）。
    $('#fCustomer, #fSales, #fBom, #fVendor, #fQty, #fDelivery, #fKeyword').val('');
    var r = yRange1y(); $('#fDateFrom').val(r[0]); $('#fDateTo').val(r[1]);
    $('#fPriority').val('');
    curProcess = '';
    renderYearBar();
    applyFilters();
});

function confirmLargeResult(actionLabel){
    if (lastTotal > 3000){
        return confirm('目前篩選結果共 ' + lastTotal + ' 筆，資料量較大，' + actionLabel + '可能需要一些時間，是否仍要繼續？');
    }
    return true;
}

// ── 列印：抓「全部」符合篩選條件的資料，開新視窗列印（不受分頁限制）──
$('#btnPrint').on('click', function(){
    if (!confirmLargeResult('列印')) return;
    var f = curFilters();
    f.action = 'get_print';
    $.post('', f, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var dateTxt = (f.date_from||f.date_to) ? ((f.date_from||'不限')+' ～ '+(f.date_to||'不限')) : '不限日期(全部)';
        var sub = '共 ' + res.total + ' 筆｜結案日期：' + dateTxt + '｜列印日期：' + todayStr();
        var maxProc = res.max_process_count || 0;
        var body = '<div class="p-comp">' + esc(res.company_name||'') + '</div>'
                 + '<div class="p-title">已完工BOM查詢列印</div>'
                 + '<div class="p-sub">' + esc(sub) + '</div>';
        body += '<table class="p-tb"><thead><tr><th>客戶</th><th>BOM</th><th>料號</th><th>數量</th><th>交期</th><th>業務</th><th>結案日期</th>';
        for (var ci=1; ci<=maxProc; ci++) body += '<th>製程'+ci+'</th>';
        body += '</tr></thead><tbody>';
        res.rows.forEach(function(r){
            var priLabel = r.priority_type==='E' ? '特急件' : (r.priority_type==='U' ? '急件' : '一般');
            var closedTxt = (r.date_is_derived == 1) ? (esc(egFmtDate(r.effective_date))+'(推算)') : esc(r.closed_at||'');
            body += '<tr><td>'+esc(r.client_name_display||'')+'</td><td class="tl">'+esc(r.bom)+'（'+priLabel+'）</td>'
                  + '<td class="tl">'+esc(r.d_id||'')+'</td><td>'+esc(r.Qty||'')+'</td>'
                  + '<td>'+esc(r.Delivery_date?egFmtDate(r.Delivery_date):'')+'</td><td>'+esc(r.sales_name||'')+'</td>'
                  + '<td>'+closedTxt+'</td>';
            var procs = r.processes || [];
            for (var pi=0; pi<maxProc; pi++){
                var p = procs[pi];
                body += '<td class="tl">' + (p ? esc((p.process_no||'')+(p.ProcessName?' '+p.ProcessName:'')+(p.maker_id?'/'+p.maker_id:'')+(p.return_date?'/回廠:'+p.return_date:'')) : '') + '</td>';
            }
            body += '</tr>';
        });
        body += '</tbody></table>';
        if (!res.rows.length) body += '<div style="padding:20px;color:#666;">查無符合條件的已完工BOM</div>';

        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:6px;margin-bottom:2px;}'
            + '.p-sub{font-size:11px;text-align:center;color:#555;margin-bottom:10px;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-tb thead{display:table-header-group;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 4px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;}'
            + 'table.p-tb td.tl{text-align:left;word-break:break-all;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '@page{margin:12mm 10mm 18mm;}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>已完工BOM查詢列印</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            +'var onePageA4=(297-30)*96/25.4;'
            +'if(document.body.scrollHeight>onePageA4*0.92){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);}'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    }, 'json');
});

// ── 匯出CSV：依目前篩選條件用表單送出觸發下載（非AJAX，才能觸發瀏覽器下載）──
$('#btnExportCsv').on('click', function(){
    if (!confirmLargeResult('匯出')) return;
    var f = curFilters();
    f.action = 'export_csv';
    var $form = $('<form method="POST" target="_blank"></form>').attr('action', '');
    $.each(f, function(k,v){ $form.append($('<input type="hidden">').attr('name', k).val(v)); });
    $('body').append($form);
    $form[0].submit();
    $form.remove();
});

// ── 統整報表：依目前篩選條件抓「全部」符合筆數，彙總後開新視窗列印（可用瀏覽器列印功能另存為PDF）──
function buildBarChartSvg(items){
    if (!items || !items.length) return '';
    var max = 0;
    items.forEach(function(it){ if (it.cnt > max) max = it.cnt; });
    if (max <= 0) max = 1;
    var barH = 18, gap = 8, labelW = 110, chartW = 320, rowH = barH + gap;
    var h = items.length * rowH + gap;
    var w = labelW + chartW + 50;
    var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" style="max-width:520px;height:auto;">';
    items.forEach(function(it, i){
        var y = gap + i * rowH;
        var bw = Math.max(2, Math.round(it.cnt / max * chartW));
        svg += '<text x="' + (labelW - 8) + '" y="' + (y + barH * 0.75) + '" text-anchor="end" font-size="11" fill="#5b3a1e">' + esc(it.category_name || '（未分類）') + '</text>';
        svg += '<rect x="' + labelW + '" y="' + y + '" width="' + bw + '" height="' + barH + '" rx="3" fill="#F0A24B"></rect>';
        svg += '<text x="' + (labelW + bw + 6) + '" y="' + (y + barH * 0.75) + '" font-size="11" fill="#5b3a1e">' + it.cnt + '</text>';
    });
    svg += '</svg>';
    return svg;
}

$('#btnSummary').on('click', function(){
    if (!confirmLargeResult('產生統整報表')) return;
    var f = curFilters();
    f.action = 'get_summary';
    $.post('', f, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }

        var critParts = [];
        critParts.push('結案日期：' + ((f.date_from||f.date_to) ? ((f.date_from||'不限')+' ～ '+(f.date_to||'不限')) : '不限日期(全部)'));
        if (f.process_type) critParts.push('製程：' + $.trim($('#processTabs .ocq-tab.active').text()));
        if (f.customer) critParts.push('客戶：'+f.customer);
        if (f.sales) critParts.push('業務：'+f.sales);
        if (f.priority) critParts.push('優先權：'+$('#fPriority option:selected').text());
        if (f.bom) critParts.push('BOM/料號：'+f.bom);
        if (f.vendor) critParts.push('廠商：'+f.vendor);
        if (f.qty) critParts.push('發單數量：'+f.qty);
        if (f.delivery) critParts.push('交期：'+f.delivery);
        if (f.keyword) critParts.push('全域關鍵字：'+f.keyword);

        var tiles = ''
            + '<div class="s-tile"><div class="s-lbl">總筆數</div><div class="s-val">'+res.total+'</div></div>'
            + '<div class="s-tile"><div class="s-lbl">合格結案紀錄筆數</div><div class="s-val">'+res.qualified+'</div></div>'
            + '<div class="s-tile"><div class="s-lbl">不列入計算筆數(推算日期)</div><div class="s-val">'+res.excluded+'</div></div>'
            + '<div class="s-tile"><div class="s-lbl">平均結案時間</div><div class="s-val">'+(res.avg_duration!=null?res.avg_duration+' 天':'—')+'</div></div>';

        var procTable = '<table class="p-tb"><thead><tr><th>製程</th><th>筆數</th></tr></thead><tbody>'
            + (res.processes||[]).map(function(p){ return '<tr><td class="tl">'+esc(p.category_name||'（未分類）')+'</td><td>'+p.cnt+'</td></tr>'; }).join('')
            + '</tbody></table>';

        var custTable = '<table class="p-tb"><thead><tr><th>客戶</th><th>筆數</th></tr></thead><tbody>'
            + (res.customers||[]).map(function(c){ return '<tr><td class="tl">'+esc(c.name)+'</td><td>'+c.cnt+'</td></tr>'; }).join('')
            + '</tbody></table>';

        function fmtRec(r){
            if (!r) return '<p style="color:#888;font-size:12px;">（無合格結案紀錄可統計——目前篩選結果中沒有具備真實結案時間的BOM）</p>';
            return '<table class="p-tb"><thead><tr><th>BOM</th><th>客戶</th><th>結案日期</th><th>結案耗時</th></tr></thead><tbody>'
                + '<tr><td class="tl">'+esc(r.bom)+'</td><td class="tl">'+esc(r.client_name_display||'')+'</td>'
                + '<td>'+esc(r.closed_at||'')+'</td><td>'+r.duration_days+' 天</td></tr></tbody></table>';
        }
        var maxProcTxt = '';
        if (res.max_record && res.max_record.processes && res.max_record.processes.length){
            maxProcTxt = '<p style="font-size:12px;color:#5b3a1e;">製程組成：' + res.max_record.processes.map(function(p){
                return esc((p.process_no||'')+(p.ProcessName?' '+p.ProcessName:''));
            }).join(' → ') + '</p>';
        }

        var body = '<div class="p-comp">' + esc(res.company_name||'') + '</div>'
                 + '<div class="p-title">已完工BOM統整報表</div>'
                 + '<div class="p-sub">篩選條件：' + esc(critParts.join('｜')) + '｜產出日期：' + todayStr() + '</div>'
                 + '<div class="s-bar">' + tiles + '</div>'
                 + '<div class="s-sec-title">製程分布（依BOM筆數，多到少）</div>'
                 + buildBarChartSvg(res.processes||[])
                 + procTable
                 + '<div class="s-sec-title">客戶分布（依BOM筆數，多到少）</div>'
                 + custTable
                 + '<div class="s-sec-title">最短結案時間</div>' + fmtRec(res.min_record)
                 + '<div class="s-sec-title">最長結案時間</div>' + fmtRec(res.max_record) + maxProcTxt;

        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:6px;margin-bottom:2px;}'
            + '.p-sub{font-size:11px;text-align:center;color:#555;margin-bottom:10px;}'
            + '.s-sec-title{font-size:13px;font-weight:bold;color:#8A5A2B;border-bottom:2px solid #F7E0BD;padding-bottom:2px;margin:14px 0 6px;}'
            + '.s-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px;}'
            + '.s-tile{flex:1 1 130px;border:1px solid #999;border-radius:4px;padding:6px 10px;}'
            + '.s-lbl{font-size:10px;color:#666;}'
            + '.s-val{font-size:18px;font-weight:bold;}'
            + 'table.p-tb{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 6px;text-align:center;}'
            + 'table.p-tb thead th{background:#f3ead6;}'
            + 'table.p-tb td.tl{text-align:left;}'
            + '@page{margin:12mm 10mm 18mm;}';

        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>已完工BOM統整報表</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            +'var onePageA4=(297-30)*96/25.4;'
            +'if(document.body.scrollHeight>onePageA4*0.92){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);}'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    }, 'json');
});

// 由 BOM總表「查詢已完工資料」跳窗的「前往完整查詢」按鈕帶進來的關鍵字（?kw=…&t=跳窗當時的總筆數）。
// 日期維持本頁預設的近1年（使用者拍板），但那個跳窗本身是不限日期的，所以提示條要把「跳窗當時查到
// 全部年份共幾筆」一併講出來並附一顆「改看全部年份」，否則使用者會覺得「改用完整查詢反而查更少」。
(function(){
    var qs = window.location.search || '';
    function qp(k){
        try { return (new URLSearchParams(qs)).get(k) || ''; }
        catch (e) { var m = new RegExp('[?&]'+k+'=([^&]*)').exec(qs); return m ? decodeURIComponent(m[1].replace(/\+/g,' ')) : ''; }
    }
    var kw = $.trim(qp('kw'));
    if (!kw) return;
    var srcTotal = parseInt(qp('t'), 10);
    $('#fKeyword').val(kw);
    var html = '<i class="fa fa-info-circle"></i>&nbsp;已從「BOM總表 → 查詢已完工資料」帶入關鍵字 <b>'
        + esc(kw) + '</b>，目前顯示<b>近1年</b>的結案資料。';
    if (srcTotal > 0) {
        html += '（該跳窗以<b>全部年份</b>查到的是 <b>' + srcTotal.toLocaleString('en-US') + '</b> 筆）';
    }
    html += '&nbsp;<button class="ocq-ybtn" id="kwGoAll" style="height:22px;font-size:12px;padding:0 9px;">改看全部年份</button>';
    $('<div class="ocq-carry"></div>').html(html).insertBefore('.ocq-stat:first');
    $('#kwGoAll').on('click', function(){ ySetDates('', ''); renderYearBar(); applyFilters(); });
})();

renderYearBar();
applyFilters();
</script>
</body>
</html>
