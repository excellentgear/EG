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

// ── 快速綁定（料號／訂單）的權限：一定要對「BOM 總表」那一頁解析，不是對本頁 ────────────────
// 綁定改的是 `bom` 主檔＝BOM 總表的資料，所以權限要跟著那一頁走；唯一實作在 role_features_helper.php
// 的 oready_resolve_can_bind()，與 BOM 總表自己那兩顆按鈕同一套規則（鐵律4，兩邊各刻一份必定走鐘）。
// **不可以拿本頁的權限來判**：實測站上有 9 位使用者被個別把 BOM 總表的 page scope 權限壓成 'R'
// （唯讀），而他們在 group scope 'bom' 底下是 A／CRD——拿本頁去解析的話，這 9 個人就會在這一頁
// 拿回寫入權，等於繞過那筆刻意設定的限制。沒有權限者畫面上完全看不到這兩個功能（連欄位都不長出來），
// 後端也一律擋下（鐵律8）。
require_once __DIR__ . '/../../src/common/role_features_helper.php';
require_once __DIR__ . '/../../src/common/date_fmt_lib.php';   // 日期顯示一律 YYYY.MM.DD（ai-rules/20）
$ocq_bind_perm      = oready_resolve_can_bind($pdo, $id);
$ocq_can_bind_part  = !empty($ocq_bind_perm['part']);
$ocq_can_bind_order = !empty($ocq_bind_perm['order']);

// CSRF：本頁原本的 action 全是唯讀查詢用不到，新增的兩個寫入動作要。
// **順序一定是先驗登入再驗 token**（本檔開頭已先擋未登入）——token 會在同一個請求裡重新產生，
// 沒登入時比對必定不過，訊息講錯方向的話使用者只會一直重整卻永遠存不進去（2026-08-21 的教訓）。
function ocq_csrf_token(): string {
    if (empty($_SESSION['ocq_csrf'])) $_SESSION['ocq_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['ocq_csrf'];
}
$ocq_csrf = ocq_csrf_token();

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

$OCQ_COLS = "b.bom, b.d_id, b.sqty AS Qty, b.priority_type, b.d_setting_id, b.o_order_id, b.closed_by, b.closed_at,
    b.Delivery_date, $OCQ_CLIENT_DISP AS client_name_display,
    u_sales_primary.user_cname AS sales_name, u_close.user_cname AS closed_by_name,
    $OCQ_EFFDATE AS effective_date, (b.closed_at IS NULL) AS date_is_derived";

// $exclude：計算某個篩選欄位自己的可選清單(facet)時，要排除該欄位自己的條件
/**
 * 綁定狀態的 SQL 條件（唯一實作：清單篩選、自動綁定跳窗的掃描範圍共用同一份，
 * 兩邊各寫一次的話，篩選說有 N 筆、跳窗卻處理另一批，怎麼看都對不起來）。
 * 判定用的三個事實：
 *   料號＝bom.d_setting_id 有值
 *   訂單＝bom_order_process_map 有列，或 bom.o_order_id 是數字（舊資料只填這一欄）；
 *        o_order_id='B' 是備庫，本來就沒有訂單，一律不算「未綁訂單」
 *   報價單＝該 BOM 綁到的訂單上的 order_track.quote_no／quote_item_id 有值
 */
function ocq_has_order_sql($alias = 'b') {
    return "(EXISTS (SELECT 1 FROM bom_order_process_map m_b WHERE m_b.bom = $alias.bom)
             OR COALESCE($alias.o_order_id,'') <> '')";
}
/**
 * 「這筆 BOM 綁到的訂單裡，有沒有還沒綁報價單的」。
 * **刻意拆成兩個 EXISTS 而不是 `Order_id IN (…UNION…)`**：後者對每一列都要現做一張衍生表，
 * 清單頁（還要 JOIN 一堆表＋排序）實測直接跑到 60 秒逾時；拆開之後兩邊都走得到索引
 * （bom_order_process_map 的 bom、order_track 的主鍵）。兩種來源都要看，
 * 舊資料常常只在 bom.o_order_id 留一個訂單、bom_order_process_map 一列都沒有。
 */
function ocq_order_missing_quote_sql($alias = 'b') {
    return "(EXISTS (SELECT 1 FROM bom_order_process_map m_q JOIN order_track o_q ON o_q.Order_id = m_q.order_id
                     WHERE m_q.bom = $alias.bom AND COALESCE(o_q.quote_no,'') = '' AND o_q.quote_item_id IS NULL)
             OR ($alias.o_order_id REGEXP '^[0-9]+$'
                 AND EXISTS (SELECT 1 FROM order_track o_q2 WHERE o_q2.Order_id = CAST($alias.o_order_id AS UNSIGNED)
                             AND COALESCE(o_q2.quote_no,'') = '' AND o_q2.quote_item_id IS NULL)))";
}
function ocq_bind_state_sql($kind) {
    switch ($kind) {
        case 'no_part':
            return "(b.d_setting_id IS NULL OR b.d_setting_id = 0)";
        case 'no_order':
            return "(b.d_setting_id IS NOT NULL AND b.d_setting_id <> 0 AND COALESCE(b.o_order_id,'') = ''
                     AND NOT EXISTS (SELECT 1 FROM bom_order_process_map m_b WHERE m_b.bom = b.bom))";
        case 'no_quote':
            return "(" . ocq_has_order_sql('b') . " AND COALESCE(b.o_order_id,'') <> 'B'
                     AND " . ocq_order_missing_quote_sql('b') . ")";
        case 'all_done':
            return "(b.d_setting_id IS NOT NULL AND b.d_setting_id <> 0 AND " . ocq_has_order_sql('b') . "
                     AND (COALESCE(b.o_order_id,'') = 'B' OR NOT " . ocq_order_missing_quote_sql('b') . "))";
    }
    return '';
}

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
    // 綁定狀態篩選（使用者要求：要能把「還沒綁的」直接篩出來）。
    // 唯一實作 ocq_bind_state_sql()，清單／列印／匯出／統整報表與自動綁定跳窗全部吃同一份條件。
    if (!in_array('bind', $exclude, true) && !empty($p['bind'])) {
        $bs = ocq_bind_state_sql($p['bind']);
        if ($bs !== '') $where[] = $bs;
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
               DATE_FORMAT(bi.outsource_date,'%Y-%m-%d') AS outsource_date,
               DATE_FORMAT(bi.return_date,'%Y-%m-%d') AS return_date,
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

// ================= 快速綁定：共用判定（唯一實作，清單顯示與寫入守門共用同一套） =================
// 「這筆 BOM 到底綁了訂單沒有」全站有兩個地方存：`bom_order_process_map`（多筆＋分配量，正解）與
// `bom.o_order_id`（單一欄位，舊資料與相容用，值可能是訂單 PK 或 'B'＝備庫）。**兩邊都要看**——
// 只看 map 會把舊資料判成未綁定，只看 o_order_id 會漏掉一對多的新資料。這支是唯一判定點，
// 畫面清單、開跳窗前的即時檢查、送出時的守門一律呼叫它，避免三個地方各寫一套而走鐘。

/**
 * 批次撈這一頁 BOM 的訂單綁定狀態。
 * 回傳 [bom => ['orders'=>[...], 'stock'=>bool, 'legacy'=>訂單編號或null, 'legacy_deliv'=>交期或null]]
 * 訂單的交期一併帶回來——**清單的「交期」欄要用它**：`bom.Delivery_date` 是「手動交期」，
 * 已結案的 11,524 筆裡有 11,272 筆（97.8%）是空的，只看那一欄整欄都會是空白（使用者回報的症狀）；
 * 比照 BOM 總表的規則＝手動交期優先，沒有就用綁定訂單的交期。
 */
function ocq_bind_map($pdo, $bom_list, $rows_by_bom = []) {
    if (!$bom_list) return [];
    $out = [];
    // 列印／匯出是「全部符合條件」的上萬列，用 IN 會塞上萬個參數；分配表全表才 2,297 筆，
    // 量大時直接整張撈回來在 PHP 端取交集反而又快又簡單。
    $big = (count($bom_list) > 800);
    if ($big) {
        $want = array_flip($bom_list);
        $st = $pdo->query("SELECT m.bom, m.order_id, m.allocated_qty, ot.Order_oo,
                DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS deliv
            FROM bom_order_process_map m LEFT JOIN order_track ot ON ot.Order_id = m.order_id
            ORDER BY m.id");
    } else {
        $ph = implode(',', array_fill(0, count($bom_list), '?'));
        $st = $pdo->prepare("SELECT m.bom, m.order_id, m.allocated_qty, ot.Order_oo,
                DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS deliv
            FROM bom_order_process_map m LEFT JOIN order_track ot ON ot.Order_id = m.order_id
            WHERE m.bom IN ($ph) ORDER BY m.id");
        $st->execute($bom_list);
        $want = null;
    }
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if ($want !== null && !isset($want[$m['bom']])) continue;
        $out[$m['bom']]['orders'][] = ['order_id' => (int)$m['order_id'],
            'order_oo' => $m['Order_oo'] ?: ('#' . $m['order_id']), 'qty' => (int)$m['allocated_qty'],
            'deliv' => $m['deliv'] ?: null];
    }
    // 舊資料：map 沒有列、但 bom.o_order_id 有值。'B'＝備庫（不是未綁定，比照 BOM總表不給綁定鈕）；
    // 其餘是訂單 PK，要反查訂單編號才看得懂（只查這一頁真的用得到的那幾個，不是整批 JOIN）。
    $legacy_ids = [];
    foreach ($bom_list as $b) {
        $oid = trim((string)($rows_by_bom[$b]['o_order_id'] ?? ''));
        if ($oid === '' || isset($out[$b]['orders'])) continue;
        if ($oid === 'B') { $out[$b]['stock'] = true; continue; }
        if (ctype_digit($oid)) { $legacy_ids[$oid][] = $b; }
    }
    if ($legacy_ids) {
        $lp = implode(',', array_fill(0, count($legacy_ids), '?'));
        $sl = $pdo->prepare("SELECT Order_id, Order_oo, DATE_FORMAT(Delivery_date,'%Y-%m-%d') AS deliv
            FROM order_track WHERE Order_id IN ($lp)");
        $sl->execute(array_keys($legacy_ids));
        $oo = [];
        foreach ($sl->fetchAll(PDO::FETCH_ASSOC) as $r) $oo[(string)$r['Order_id']] = $r;
        foreach ($legacy_ids as $oid => $boms) {
            $r = $oo[(string)$oid] ?? null;
            foreach ($boms as $b) {
                $out[$b]['legacy']       = ($r && $r['Order_oo']) ? $r['Order_oo'] : ('#' . $oid);
                $out[$b]['legacy_deliv'] = ($r && $r['deliv']) ? $r['deliv'] : null;
            }
        }
    }
    return $out;
}

/**
 * 算出每一列真正要顯示的交期，並掛回 $rows（清單／列印／CSV 共用同一份判定，不要各算一次）。
 * 規則比照 BOM 總表：`bom.Delivery_date`（手動交期）優先，沒有才用綁定訂單的交期；
 * 一筆 BOM 綁到多張訂單時交期可能不只一個，全部列出（畫面上下分行，不要串成一行）。
 */
function ocq_attach_delivery(&$rows, $bind_map) {
    foreach ($rows as &$r) {
        $manual = trim((string)($r['Delivery_date'] ?? ''));
        if ($manual !== '' && $manual !== '0000-00-00') {
            $r['deliv_src']  = 'manual';
            $r['deliv_list'] = [['date' => $manual, 'order_oo' => null]];
            continue;
        }
        $r['deliv_src'] = ''; $r['deliv_list'] = [];
        $bi = $bind_map[$r['bom']] ?? [];
        $seen = [];
        foreach (($bi['orders'] ?? []) as $o) {
            if (!$o['deliv'] || isset($seen[$o['deliv']])) continue;
            $seen[$o['deliv']] = 1;
            $r['deliv_list'][] = ['date' => $o['deliv'], 'order_oo' => $o['order_oo']];
        }
        if (!$r['deliv_list'] && !empty($bi['legacy_deliv'])) {
            $r['deliv_list'][] = ['date' => $bi['legacy_deliv'], 'order_oo' => $bi['legacy'] ?? null];
        }
        if ($r['deliv_list']) $r['deliv_src'] = 'order';
    }
    unset($r);
}

/** 單筆 BOM 的即時綁定狀態（開跳窗前與送出時都用它重新查一次＝點開即刷新鐵則）。 */
function ocq_bind_state($pdo, $bom) {
    $st = $pdo->prepare("SELECT b.bom, b.d_id, b.d_setting_id, b.Client_Name, b.o_order_id, b.sqty, b.processing_state,
            COALESCE(ds.D_Setting_Id,'') AS part_no, COALESCE(cl.customer,'') AS part_customer
        FROM bom b
        LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
        LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
        WHERE b.bom = ? LIMIT 1");
    $st->execute([$bom]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $bind = ocq_bind_map($pdo, [$bom], [$bom => $row]);
    $b = $bind[$bom] ?? [];
    $row['bound_orders'] = $b['orders'] ?? [];
    $row['is_stock']     = !empty($b['stock']);
    $row['legacy_order'] = $b['legacy'] ?? null;
    $row['has_part']     = !empty($row['d_setting_id']);
    $row['has_order']    = ($row['bound_orders'] || $row['is_stock'] || $row['legacy_order'] !== null);
    return $row;
}

// ── 自動綁定料號：判定規則（唯一實作，掃描與寫入共用同一支）─────────────────────────
// 舊 BOM 有 9,600 多筆沒有綁到料號主檔（bom.d_setting_id 空），只留下 ERP 匯入的料號文字
// bom.d_id；這批在資料稽核、毛利分析、出貨反推那幾頁一律被判成「查不到製令」。
// 判定只做一件事：拿料號文字去 d_setting 找同名主檔，**能指得出唯一一筆才自動綁**，
// 指不出來的一律留給人工判定——同一個料號文字在 d_setting 常常有好幾筆、分屬不同客戶
// （記憶 bom_client_name_cache／bom_d_setting_id_mostly_null），綁錯家比不綁更難查回來。
// 掃描（試算）與實際寫入都呼叫這一支，兩邊規則不可能走鐘。

/** 客戶名稱比對：任一邊沒填＝無從比對（不算衝突也不算相符）。 */
function ocq_ab_cust_cmp($bomCust, $dsCust) {
    $a = trim((string)$bomCust);
    $b = trim((string)$dsCust);
    if ($a === '' || $b === '') return 'unknown';
    if ($a === $b) return 'same';
    // BOM 的 Client_Name 是 ERP 匯入的簡稱，跟主檔寫法常常不同（「高鋒工業」vs「高鋒」），
    // 所以互相包含也算相符；真的完全不同（「聚大」vs「超正」）才判成衝突。
    if (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) return 'like';
    return 'diff';
}

/** 一次撈出這批料號文字在 d_setting 的所有同名主檔，回傳 [小寫料號文字 => [候選主檔…]]。 */
function ocq_ab_candidate_map($pdo, array $partTexts) {
    $keys = [];
    foreach ($partTexts as $t) {
        $t = trim((string)$t);
        if ($t !== '') $keys[mb_strtolower($t)] = $t;
    }
    if (!$keys) return [];
    $map = [];
    // 分批 IN：一次塞幾千個參數會超過 placeholder 上限
    foreach (array_chunk(array_values($keys), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT ds.d_id, TRIM(ds.D_Setting_Id) AS part_no,
                COALESCE(ds.Drawing_No,'') AS drawing_no, COALESCE(ds.Spec_No,'') AS spec_no,
                COALESCE(ds.Revision,'') AS revision, COALESCE(ds.Customer_Id,'') AS customer_id,
                COALESCE(cl.customer,'') AS customer_name
            FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
            WHERE TRIM(ds.D_Setting_Id) IN ($ph)
            ORDER BY ds.d_id ASC");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[mb_strtolower($r['part_no'])][] = $r;
        }
    }
    return $map;
}

/**
 * 判定一批 BOM 該怎麼綁。$rows 每列要有 bom / d_id / Client_Name。
 * level：auto＝可自動綁（指得出唯一一筆）／manual＝要人工判定／nomatch＝主檔查無此料號／
 *        nopart＝這筆 BOM 連料號文字都沒有。
 */
function ocq_ab_classify($pdo, array $rows) {
    $cand = ocq_ab_candidate_map($pdo, array_column($rows, 'd_id'));
    $out = [];
    foreach ($rows as $r) {
        $bom  = $r['bom'];
        $text = trim((string)($r['d_id'] ?? ''));
        $cust = (string)($r['Client_Name'] ?? '');
        if ($text === '') {
            $out[$bom] = ['level' => 'nopart', 'reason' => '這筆 BOM 沒有料號文字，無法比對主檔',
                'candidates' => [], 'pick' => null];
            continue;
        }
        $list = $cand[mb_strtolower($text)] ?? [];
        foreach ($list as &$c) { $c['cust_cmp'] = ocq_ab_cust_cmp($cust, $c['customer_name']); }
        unset($c);

        if (!$list) {
            $out[$bom] = ['level' => 'nomatch', 'reason' => '料號主檔裡找不到「' . $text . '」，要先到主檔建立這個料號',
                'candidates' => [], 'pick' => null];
            continue;
        }
        if (count($list) === 1) {
            $one = $list[0];
            if ($one['cust_cmp'] === 'diff') {
                // 料號文字雖然只有一筆，但客戶完全對不上（實測 51 筆，例：BOM 寫「聚大」、主檔寫
                // 「超正」）。這種可能是主檔建錯客戶、也可能是代工關係，一律交給人看過再綁。
                $out[$bom] = ['level' => 'manual',
                    'reason' => '主檔只有一筆，但客戶對不上（BOM：' . trim($cust) . '／主檔：' . $one['customer_name'] . '）',
                    'candidates' => $list, 'pick' => null];
            } else {
                $out[$bom] = ['level' => 'auto', 'reason' => '料號主檔唯一命中',
                    'candidates' => $list, 'pick' => $one];
            }
            continue;
        }
        // 同一個料號文字有好幾筆主檔：用客戶收斂，剛好剩一筆才自動綁
        $hit = array_values(array_filter($list, function ($c) {
            return $c['cust_cmp'] === 'same' || $c['cust_cmp'] === 'like';
        }));
        if (count($hit) === 1) {
            $out[$bom] = ['level' => 'auto',
                'reason' => '同名主檔有 ' . count($list) . ' 筆，以客戶「' . $hit[0]['customer_name'] . '」收斂成唯一',
                'candidates' => $list, 'pick' => $hit[0]];
        } elseif (count($hit) > 1) {
            $out[$bom] = ['level' => 'manual',
                'reason' => '同名主檔有 ' . count($list) . ' 筆，其中 ' . count($hit) . ' 筆都是同一個客戶，分不出是哪一筆',
                'candidates' => $list, 'pick' => null];
        } else {
            $out[$bom] = ['level' => 'manual',
                'reason' => '同名主檔有 ' . count($list) . ' 筆，沒有一筆的客戶對得上'
                    . (trim($cust) === '' ? '（這筆 BOM 沒有客戶名稱可比對）' : '（BOM：' . trim($cust) . '）'),
                'candidates' => $list, 'pick' => null];
        }
    }
    return $out;
}

/** 自動綁定的掃描範圍：本頁篩選條件 ＋「還沒綁料號」。 */
function ocq_ab_where($p) {
    // 畫面上的「綁定狀態」篩選一律排除：每個階段的範圍由階段自己決定（否則使用者選了
    // 「未綁料號」再切到綁訂單分頁，會看到 0 筆而以為壞掉）
    list($whereSql, $params) = ocq_build_filter($p, ['bind']);
    return [$whereSql . " AND " . ocq_bind_state_sql('no_part'), $params];
}

// ── 自動綁定訂單：判定規則（唯一實作，掃描與寫入共用同一支）─────────────────────────
// 與料號同一套精神：**候選訂單指得出唯一一筆才自動綁**，多筆一律列出來讓人挑。
// 候選＝同一個料號主檔（order_track.d_id_ID = bom.d_setting_id）且未作廢的訂單。

/** 綁訂單的掃描範圍：本頁篩選 ＋ 已綁料號 ＋ 還沒綁訂單（備庫不算，它本來就沒有訂單）。 */
function ocq_ob_where($p) {
    list($whereSql, $params) = ocq_build_filter($p, ['bind']);
    return [$whereSql . " AND " . ocq_bind_state_sql('no_order'), $params];
}

/** 候選訂單要撈的欄位（分配量預設值要用到訂單量與已分配量，與逐筆跳窗完全同一份）。 */
function ocq_ob_order_cols() {
    return "ot.Order_id, COALESCE(ot.Order_oo,'') AS Order_oo, COALESCE(ot.Client_name,'') AS Client_name, ot.d_id_ID,
        (CASE WHEN ot.split_seq = 1 THEN ot.Qty - COALESCE((SELECT SUM(c.Qty) FROM order_track c
              WHERE c.parent_order_id = ot.Order_id AND c.split_seq > 1), 0) ELSE ot.Qty END) AS Qty,
        DATE_FORMAT(ot.Order_date,'%Y-%m-%d') AS Order_date, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
        COALESCE(ot.Specification,'') AS Specification, COALESCE(ot.Order_ps,'') AS Order_ps,
        COALESCE((SELECT SUM(m2.allocated_qty) FROM bom_order_process_map m2 WHERE m2.order_id = ot.Order_id), 0) AS already_allocated";
}

/**
 * 分配量的預設值（**唯一實作**：逐筆綁定跳窗、需人工判定清單、綁定後的即時刷新都走這一支）。
 * 已經被別的 BOM 分配滿（未分配量＝0）時一律回 0——舊版在這種情況回的是 BOM 發單量，
 * 等於「這張訂單早就滿了，系統還預先幫你再超配一次」，同一個料號好幾筆 BOM 排在一起判定時
 * 特別容易就這樣按下去（使用者回報的就是這件事）。
 */
function ocq_ob_qty_default($bomQty, $o) {
    $bomQty = (int)$bomQty;
    $left   = max(0, (int)($o['Qty'] ?? 0) - (int)($o['already_allocated'] ?? 0));
    if ($left <= 0) return 0;
    return ($bomQty > 0) ? min($bomQty, $left) : $left;
}

/**
 * 判定一批 BOM 的訂單該怎麼綁。$rows 每列要有 bom / d_setting_id / sqty。
 * level：auto＝同料號底下只有一張訂單／manual＝好幾張要人工挑／nomatch＝這個料號底下沒有訂單。
 * $withCand=true 時才把候選訂單整批撈回來（清單頁用；掃描時只需要筆數，同料號動輒上千張訂單，
 * 全撈會把記憶體吃光）。
 */
function ocq_ob_classify($pdo, array $rows, $withCand = false, $candLimit = 8) {
    $dsids = [];
    foreach ($rows as $r) { $d = (int)($r['d_setting_id'] ?? 0); if ($d > 0) $dsids[$d] = 1; }
    $cnt = []; $one = [];
    foreach (array_chunk(array_keys($dsids), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT ot.d_id_ID, COUNT(*) n, MIN(ot.Order_id) oid FROM order_track ot
            WHERE ot.d_id_ID IN ($ph) AND (ot.Order_status IS NULL OR ot.Order_status <> 9) GROUP BY ot.d_id_ID");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $cnt[(int)$g['d_id_ID']] = (int)$g['n'];
            if ((int)$g['n'] === 1) $one[(int)$g['d_id_ID']] = (int)$g['oid'];
        }
    }
    // 唯一那一張的明細（自動綁定要用它算分配量）
    $oneRow = [];
    if ($one) {
        foreach (array_chunk(array_values($one), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT " . ocq_ob_order_cols() . " FROM order_track ot WHERE ot.Order_id IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) $oneRow[(int)$o['Order_id']] = $o;
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $bom = $r['bom'];
        $d   = (int)($r['d_setting_id'] ?? 0);
        $n   = $cnt[$d] ?? 0;
        if ($n === 0) {
            $out[$bom] = ['level' => 'nomatch', 'reason' => '這個料號主檔底下沒有任何未作廢的訂單', 'candidates' => [], 'pick' => null];
            continue;
        }
        if ($n === 1) {
            $o = $oneRow[$one[$d] ?? 0] ?? null;
            if ($o) {
                $o['qty_default'] = ocq_ob_qty_default($r['sqty'] ?? 0, $o);
                $out[$bom] = ['level' => 'auto', 'reason' => '這個料號底下只有一張訂單（' . $o['Order_oo'] . '）',
                    'candidates' => [$o], 'pick' => $o];
                continue;
            }
        }
        $out[$bom] = ['level' => 'manual',
            'reason' => '這個料號底下有 ' . $n . ' 張訂單，系統無從得知這批貨是做哪一張，請自行挑選（量分屬好幾張時可複選）',
            'candidates' => [], 'pick' => null, 'cand_total' => $n];
    }
    if (!$withCand) return $out;

    // 清單頁：逐列撈候選（只取「訂單日與這筆 BOM 的日期最接近」的前幾張——同料號常有上千張訂單，
    // 照日期新到舊取會把真正該綁的那幾張整批切掉，這個坑 2026-09-03 快速出貨那次踩過）
    $st = $pdo->prepare("SELECT " . ocq_ob_order_cols() . " FROM order_track ot
        WHERE ot.d_id_ID = ? AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
        ORDER BY ABS(DATEDIFF(COALESCE(ot.Order_date, ot.Delivery_date), ?)) ASC, ot.Order_id DESC
        LIMIT " . (int)$candLimit);
    foreach ($rows as $r) {
        $bom = $r['bom'];
        if (($out[$bom]['level'] ?? '') !== 'manual') continue;
        $st->execute([(int)$r['d_setting_id'], $r['eff_date'] ?? date('Y-m-d')]);
        $cands = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cands as &$o) { $o['qty_default'] = ocq_ob_qty_default($r['sqty'] ?? 0, $o); }
        unset($o);
        $out[$bom]['candidates'] = $cands;
    }
    return $out;
}

// ── 自動綁定報價單：單位是「訂單」不是 BOM ────────────────────────────────────────
// 報價單是綁在訂單上的（order_track.quote_no / quote_item_id），所以這個階段處理的是
// 「本頁範圍內的 BOM 綁到的那些訂單裡，還沒綁報價單的」。寫入一律轉呼叫會計模組既有的
// acc_recon_bind_quote()，不在這裡另寫一份（鐵律4）。

/** 本頁篩選範圍內的 BOM 綁到了哪些訂單 id。 */
function ocq_qb_order_ids($pdo, $p) {
    global $OCQ_FROM;
    list($whereSql, $params) = ocq_build_filter($p, ['bind']);
    $st = $pdo->prepare("SELECT b.bom, COALESCE(b.o_order_id,'') AS o_order_id $OCQ_FROM $whereSql");
    $st->execute($params);
    $boms = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = []; $bomOf = [];
    foreach ($boms as $r) {
        $oo = trim((string)$r['o_order_id']);
        if ($oo !== '' && $oo !== 'B' && ctype_digit($oo)) { $ids[(int)$oo] = 1; $bomOf[(int)$oo][] = $r['bom']; }
    }
    if ($boms) {
        foreach (array_chunk(array_column($boms, 'bom'), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st2 = $pdo->prepare("SELECT bom, order_id FROM bom_order_process_map WHERE bom IN ($ph)");
            $st2->execute($chunk);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $m) { $ids[(int)$m['order_id']] = 1; $bomOf[(int)$m['order_id']][] = $m['bom']; }
        }
    }
    return [array_keys($ids), $bomOf];
}

/** 還沒綁報價單的那幾張訂單（從上面那份 id 清單篩）。 */
function ocq_qb_orders($pdo, array $ids) {
    if (!$ids) return [];
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT ot.Order_id, COALESCE(ot.Order_oo,'') AS Order_oo, COALESCE(ot.Client_name,'') AS Client_name,
                COALESCE(ot.d_id,'') AS part_no, ot.d_id_ID, ot.Qty,
                DATE_FORMAT(ot.Order_date,'%Y-%m-%d') AS Order_date
            FROM order_track ot
            WHERE ot.Order_id IN ($ph) AND COALESCE(ot.quote_no,'') = '' AND ot.quote_item_id IS NULL
              AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
            ORDER BY ot.Order_date DESC, ot.Order_id DESC");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r;
    }
    return $out;
}

/**
 * 判定這批訂單的報價單該怎麼綁。
 * **候選一定要是「報價料號文字與訂單料號文字完全相同」的**——acc_recon_bind_quote() 就是這樣擋的，
 * 只用料號主檔 id 找候選的話會挑得到、按下去卻被擋掉，而且看不出原因。
 */
function ocq_qb_classify($pdo, array $orders, $candLimit = 8) {
    $texts = []; $dsids = [];
    foreach ($orders as $o) {
        $t = trim((string)$o['part_no']); if ($t !== '') $texts[mb_strtolower($t)] = $t;
        $d = (int)$o['d_id_ID']; if ($d > 0) $dsids[$d] = 1;
    }
    $byText = [];
    if ($texts) {
        foreach (array_chunk(array_values($texts), 300) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT qi.item_id, qi.quote_id, TRIM(qi.product_id) AS product_id, qi.d_setting_d_id,
                    qi.unit_price, qi.quantity, COALESCE(qi.specification,'') AS specification,
                    ql.quote_no, DATE_FORMAT(ql.quote_date,'%Y-%m-%d') AS quote_date, COALESCE(ql.client_name,'') AS client_name
                FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id = qi.quote_id
                WHERE TRIM(qi.product_id) IN ($ph)
                  AND COALESCE(ql.pending_review,0) = 0 AND COALESCE(ql.is_draft,0) = 0
                ORDER BY ql.quote_date DESC, qi.item_id DESC");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) $byText[mb_strtolower($q['product_id'])][] = $q;
        }
    }
    $out = [];
    foreach ($orders as $o) {
        $oid  = (int)$o['Order_id'];
        $text = trim((string)$o['part_no']);
        $list = $text === '' ? [] : ($byText[mb_strtolower($text)] ?? []);
        if (!$list) {
            $out[$oid] = ['level' => 'nomatch', 'candidates' => [], 'pick' => null,
                'reason' => $text === '' ? '這張訂單沒有料號文字，找不到可比對的報價單'
                                         : '報價單裡找不到料號「' . $text . '」（草稿與待審的報價單不列入）'];
            continue;
        }
        if (count($list) === 1) {
            $out[$oid] = ['level' => 'auto', 'candidates' => $list, 'pick' => $list[0],
                'reason' => '這個料號只有一筆報價明細（' . $list[0]['quote_no'] . '）'];
            continue;
        }
        // 多筆：照「報價日與訂單日最接近、且報價日不晚於訂單日者優先」排序後列出來讓人挑
        $od = strtotime($o['Order_date'] ?: 'now');
        usort($list, function ($a, $b) use ($od) {
            $fa = ocq_qb_rank($a, $od); $fb = ocq_qb_rank($b, $od);
            return $fa[0] <=> $fb[0] ?: $fa[1] <=> $fb[1];
        });
        $out[$oid] = ['level' => 'manual', 'candidates' => array_slice($list, 0, $candLimit), 'pick' => null,
            'cand_total' => count($list),
            'reason' => '這個料號有 ' . count($list) . ' 筆報價明細（不同報價單或不同版次），請挑一筆'];
    }
    return $out;
}
/** 排序鍵：先分「報價日不晚於訂單日」再比日期差，讓下單當時那份報價排最前面。 */
function ocq_qb_rank($q, $orderTs) {
    $qd = $q['quote_date'] ? strtotime($q['quote_date']) : 0;
    if (!$qd || !$orderTs) return [2, PHP_INT_MAX];
    return [$qd <= $orderTs ? 0 : 1, abs($orderTs - $qd)];
}

/** 寫一筆稽核紀錄（綁定是會影響下游對帳/毛利分析的異動，一定要留得下來是誰在什麼時候綁的）。 */

/**
 * 把一筆料號主檔綁到 BOM 上（**唯一實作**：逐筆挑選、人工判定批次套用、快速建立料號三條路都走這支，
 * 三邊各寫一次 UPDATE 遲早走鐘）。回傳的陣列直接就是要回給前端的 JSON 內容。
 */
function ocq_bind_part_to_bom($pdo, $bom, $dsid, $uid, $label, $extraAudit = []) {
    $bom  = trim((string)$bom);
    $dsid = (int)$dsid;
    if ($bom === '' || $dsid <= 0) return ['success' => false, 'message' => '缺少必要參數'];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT bom, d_id, d_setting_id, Client_Name FROM bom WHERE bom = ? FOR UPDATE");
        $st->execute([$bom]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) { $pdo->rollBack(); return ['success' => false, 'message' => '找不到這筆 BOM，請重新整理清單']; }
        if (!empty($cur['d_setting_id'])) {
            // 已經有人綁過了：一律不覆蓋，請使用者重新整理再看（點開即刷新鐵則）。
            $pdo->rollBack();
            return ['success' => false, 'code' => 'CONFLICT',
                'message' => '這筆 BOM 已經綁定料號「' . ($cur['d_id'] ?: $cur['d_setting_id']) . '」（可能是其他人剛綁的），為避免蓋掉別人的資料已停止本次操作，請重新整理清單確認。'];
        }
        $sd = $pdo->prepare("SELECT ds.d_id, COALESCE(ds.D_Setting_Id, CAST(ds.d_id AS CHAR),'') AS display_id,
                COALESCE(cl.customer,'') AS customer_name
            FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id WHERE ds.d_id = ? LIMIT 1");
        $sd->execute([$dsid]);
        $ds = $sd->fetch(PDO::FETCH_ASSOC);
        if (!$ds) { $pdo->rollBack(); return ['success' => false, 'message' => '找不到這個料號主檔（d_id=' . $dsid . '）']; }

        // 客戶名稱：主檔有綁客戶才一起換掉（之後各頁讀哪一邊都一致）；**主檔沒綁客戶時要保留原值**，
        // 不可以寫空字串把 ERP 匯入的原本名稱洗掉（與 BOM總表 apply_dsetting_to_bom 同一條規則）。
        if (trim((string)$ds['customer_name']) !== '') {
            $pdo->prepare("UPDATE bom SET d_setting_id=?, d_id=?, Client_Name=?, Modified_By=?, Modified_At=NOW() WHERE bom=?")
                ->execute([$dsid, $ds['display_id'], $ds['customer_name'], $uid, $bom]);
        } else {
            $pdo->prepare("UPDATE bom SET d_setting_id=?, d_id=?, Modified_By=?, Modified_At=NOW() WHERE bom=?")
                ->execute([$dsid, $ds['display_id'], $uid, $bom]);
        }
        $pdo->commit();
        ocq_bind_audit($pdo, $uid, $bom, $label, array_merge([
            'before' => ['d_setting_id' => $cur['d_setting_id'], 'd_id' => $cur['d_id'], 'Client_Name' => $cur['Client_Name']],
            'after'  => ['d_setting_id' => $dsid, 'd_id' => $ds['display_id'], 'Client_Name' => $ds['customer_name'] ?: $cur['Client_Name']],
        ], $extraAudit));
        return ['success' => true, 'd_setting_id' => $dsid, 'd_id' => $ds['display_id'],
            'client_name' => $ds['customer_name'] ?: $cur['Client_Name'], 'message' => '已綁定料號 ' . $ds['display_id']];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * 把訂單綁到 BOM 上（**唯一實作**：逐筆挑選跳窗、自動綁訂單、勾選批次套用共用同一支）。
 * $list＝[['order_id'=>int,'qty'=>int], …]，回傳的陣列就是要回給前端的 JSON 內容。
 */
function ocq_bind_orders_to_bom($pdo, $bom, $list, $uid, $label) {
    $bom = trim((string)$bom);
    if ($bom === '' || !is_array($list) || !$list) return ['success' => false, 'message' => '請至少勾選一張訂單'];
    if (count($list) > 20) return ['success' => false, 'message' => '一次最多綁定 20 張訂單'];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT bom, d_id, d_setting_id, o_order_id, sqty FROM bom WHERE bom = ? FOR UPDATE");
        $st->execute([$bom]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) { $pdo->rollBack(); return ['success' => false, 'message' => '找不到這筆 BOM，請重新整理清單']; }
        if (empty($cur['d_setting_id'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => '這筆 BOM 還沒有綁定料號，請先綁定料號才能挑訂單（候選訂單是依料號找出來的）'];
        }
        // 已綁定就不再覆蓋——**這點很重要**：舊資料常常只在 bom.o_order_id 留一個訂單、
        // bom_order_process_map 一列都沒有，若直接寫 map 再同步，那張舊訂單會安靜地消失。
        $mc = $pdo->prepare("SELECT COUNT(*) FROM bom_order_process_map WHERE bom = ?");
        $mc->execute([$bom]);
        $oldOid = trim((string)$cur['o_order_id']);
        if ((int)$mc->fetchColumn() > 0 || ($oldOid !== '' && $oldOid !== 'B')) {
            $pdo->rollBack();
            return ['success' => false, 'code' => 'CONFLICT',
                'message' => '這筆 BOM 已經綁定訂單（可能是其他人剛綁的），為避免蓋掉既有綁定已停止本次操作，請重新整理清單確認；要改綁請到 BOM 總表的更新表單處理。'];
        }

        // 逐張驗證：訂單要存在、沒作廢、而且**必須屬於這筆 BOM 綁定的料號**。
        // 前端的候選清單本來就只列同料號的，這裡是防止直接打 API 把 BOM 綁到別的料號／別家客戶
        // 的訂單上（鐵律8）。
        $ins = $pdo->prepare("INSERT INTO bom_order_process_map (bom, order_id, allocated_qty, created_at) VALUES (?,?,?,NOW())");
        $chk = $pdo->prepare("SELECT Order_id, Order_oo, d_id_ID, Order_status FROM order_track WHERE Order_id = ? LIMIT 1");
        $applied = []; $seen = [];
        foreach ($list as $o) {
            $oid = intval($o['order_id'] ?? 0);
            $qty = max(0, intval($o['qty'] ?? 0));
            if ($oid <= 0 || isset($seen[$oid])) continue;
            $seen[$oid] = 1;
            $chk->execute([$oid]);
            $ot = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$ot) { $pdo->rollBack(); return ['success' => false, 'message' => '找不到訂單（id=' . $oid . '）']; }
            if ((string)$ot['Order_status'] === '9') { $pdo->rollBack(); return ['success' => false, 'message' => '訂單 ' . $ot['Order_oo'] . ' 已作廢，不可綁定']; }
            if ((int)$ot['d_id_ID'] !== (int)$cur['d_setting_id']) {
                $pdo->rollBack();
                return ['success' => false, 'message' => '訂單 ' . $ot['Order_oo'] . ' 不屬於這筆 BOM 的料號，已擋下'];
            }
            $ins->execute([$bom, $oid, $qty]);
            $applied[] = ['order_id' => $oid, 'order_oo' => $ot['Order_oo'], 'qty' => $qty];
        }
        if (!$applied) { $pdo->rollBack(); return ['success' => false, 'message' => '沒有有效的訂單可綁定']; }
        // bom.o_order_id 只是「主要訂單」的相容欄位，取第一張（與 BOM總表 update_bom_info 同義）
        $pdo->prepare("UPDATE bom SET o_order_id=?, Modified_By=?, Modified_At=NOW() WHERE bom=?")
            ->execute([(string)$applied[0]['order_id'], $uid, $bom]);
        $pdo->commit();
        ocq_bind_audit($pdo, $uid, $bom, $label, [
            'before' => ['o_order_id' => $cur['o_order_id'], 'map' => []],
            'after'  => ['o_order_id' => $applied[0]['order_id'], 'map' => $applied],
        ]);
        return ['success' => true, 'orders' => $applied, 'message' => '已綁定 ' . count($applied) . ' 張訂單'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** 寫一筆稽核紀錄（綁定是會影響下游對帳/毛利分析的異動，一定要留得下來是誰在什麼時候綁的）。 */
function ocq_bind_audit($pdo, $uid, $bom, $what, $changes) {
    try {
        $op = $pdo->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $op->execute([$uid]);
        $name = $op->fetchColumn() ?: (string)$uid;
        $pdo->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                       VALUES ('update', 'bom_bind', ?, ?, ?, ?, ?, NOW())")
            ->execute([$bom, $what, json_encode($changes, JSON_UNESCAPED_UNICODE), (int)$uid, $name]);
    } catch (Exception $e) { /* 稽核寫不進去不可以害正事失敗 */ }
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
            $rows_by_bom = []; foreach ($rows as $r0) $rows_by_bom[$r0['bom']] = $r0;
            ocq_attach_delivery($rows, ocq_bind_map($pdo, $bom_list, $rows_by_bom));

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="completed_bom_' . date('YmdHis') . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['客戶', 'BOM', '料號', '數量', '交期', '業務', '優先權', '結案人', '結案時間', '加工總單價', '製程明細']);
            foreach ($rows as $r) {
                $priLabel = $r['priority_type'] === 'E' ? '特急件' : ($r['priority_type'] === 'U' ? '急件' : '一般');
                $closedTxt = $r['date_is_derived']
                    ? (eg_fmt_date($r['effective_date']) . '（依BOM編號推算，非實際結案時間）')
                    : eg_fmt_date($r['closed_at'], true);
                $procs = $proc_map[$r['bom']] ?? [];
                $bomPrices = $price_map[$r['bom']] ?? [];
                $total = 0;
                $procTxt = [];
                foreach ($procs as $p) {
                    $pi = $bomPrices[$p['bom_sn']] ?? null;
                    $pv = $pi ? (floatval($pi['modified_unit_price']) ?: floatval($pi['price'])) : 0;
                    if ($pv > 0) $total += $pv;
                    $procTxt[] = trim(($p['ProcessName'] ?: $p['process_no']) . '(' . ($p['maker_id'] ?: '')
                        . ($p['return_date'] ? '/' . eg_fmt_date($p['return_date']) . ' 回' : '') . ')');
                }
                $delivTxt = [];
                foreach ($r['deliv_list'] as $d) $delivTxt[] = eg_fmt_date($d['date']);
                fputcsv($out, [
                    $r['client_name_display'], $r['bom'], $r['d_id'], $r['Qty'], implode(' / ', $delivTxt),
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

    // ── 快速綁定四個動作的後端守門（鐵律8：前端把按鈕藏起來不算守門）──────────────────────
    // 判定與畫面用的是同一個 $ocq_can_bind_*（上方由 oready_resolve_can_bind() 一次算好），
    // 所以不會出現「按鈕藏了、直打 API 卻寫得進去」這種只擋 UI 的半套。
    // 兩個真正寫入的動作另外驗 CSRF；兩個唯讀的（搜料號、查狀態）只驗權限，才不會因為 token
    // 過期就連查都查不了。
    $ocq_bind_actions = ['bind_search_part', 'bind_get_state', 'bind_apply_part', 'bind_apply_order',
        'autobind_scan', 'autobind_list', 'autobind_apply', 'bind_customers', 'bind_create_part',
        'autobind_order_scan', 'autobind_order_list', 'autobind_order_apply', 'autobind_order_refresh',
        'autobind_quote_scan', 'autobind_quote_list', 'autobind_quote_apply'];
    $ocq_part_actions = ['bind_search_part', 'bind_apply_part', 'autobind_scan', 'autobind_list', 'autobind_apply',
        'bind_customers', 'bind_create_part'];
    if (in_array($action, $ocq_bind_actions, true)) {
        $ok = in_array($action, $ocq_part_actions, true) ? $ocq_can_bind_part
            : (in_array($action, ['bind_apply_order', 'autobind_order_scan', 'autobind_order_list', 'autobind_order_apply',
                                  'autobind_order_refresh',
                                  'autobind_quote_scan', 'autobind_quote_list', 'autobind_quote_apply'], true)
                ? $ocq_can_bind_order : ($ocq_can_bind_part || $ocq_can_bind_order));
        if (!$ok) {
            echo json_encode(['success' => false, 'message' => '無綁定權限：此功能需要「BOM 總表」的修改權限，請聯絡管理員設定']);
            exit;
        }
        if (in_array($action, ['bind_apply_part', 'bind_apply_order', 'autobind_apply', 'bind_create_part',
                               'autobind_order_apply', 'autobind_quote_apply'], true)) {
            if (!hash_equals((string)($_SESSION['ocq_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
                echo json_encode(['success' => false, 'code' => 'CSRF', 'message' => '連線憑證失效，請重新整理頁面後再試 (CSRF)']);
                exit;
            }
        }
    }

    try {
        if ($action === 'bind_get_state') {
            // 點開即刷新（ai-rules/08 第六節）：開跳窗前一律重新跟後端要一次最新狀態，
            // 不用畫面上那份可能已經過期的快取。已經綁好的就不再給綁，請使用者重新整理。
            $state = ocq_bind_state($pdo, trim($_POST['bom'] ?? ''));
            if (!$state) { echo json_encode(['success' => false, 'message' => '找不到這筆 BOM，請重新整理清單']); exit; }
            $orders = [];
            if ($state['has_part'] && !empty($_POST['want_orders'])) {
                // 候選訂單：**只列這個料號底下的**（送出時後端會再驗一次，見 bind_apply_order）。
                // 排序刻意用「與這筆 BOM 的日期最接近」而不是訂單日期由新到舊——本頁補的是歷史已結案
                // 資料，同一料號動輒上千張訂單，照日期新到舊取 100 筆會把真正該綁的那幾張整批切掉
                // （2026-09-03 快速出貨那次踩過同一個坑）；撈回來再照訂單日期排序顯示。
                $bomDate = $pdo->prepare("SELECT COALESCE(DATE(b.closed_at), STR_TO_DATE(CONCAT(
                        CAST(SUBSTRING(SUBSTRING_INDEX(b.bom,'-',-1),1,3) AS UNSIGNED) + 1911, '-',
                        SUBSTRING(SUBSTRING_INDEX(b.bom,'-',-1),4,2), '-',
                        SUBSTRING(SUBSTRING_INDEX(b.bom,'-',-1),6,2)), '%Y-%m-%d')) FROM bom b WHERE b.bom = ?");
                $bomDate->execute([$state['bom']]);
                $refDate = $bomDate->fetchColumn() ?: date('Y-m-d');

                $kw   = trim($_POST['q'] ?? '');
                $args = [$state['d_setting_id']];
                $kwSql = '';
                if ($kw !== '') {
                    $kwSql = " AND (ot.Order_oo LIKE ? OR ot.Specification LIKE ? OR ot.Order_ps LIKE ? OR ot.Client_name LIKE ?)";
                    $like = '%' . $kw . '%';
                    array_push($args, $like, $like, $like, $like);
                }
                $args[] = $refDate;
                $so = $pdo->prepare("SELECT ot.Order_id, ot.Order_oo, ot.Client_name,
                        (CASE WHEN ot.split_seq = 1 THEN ot.Qty - COALESCE((SELECT SUM(c.Qty) FROM order_track c
                              WHERE c.parent_order_id = ot.Order_id AND c.split_seq > 1), 0) ELSE ot.Qty END) AS Qty,
                        ot.Open_Qty, DATE_FORMAT(ot.Order_date,'%Y-%m-%d') AS Order_date,
                        DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                        COALESCE(ot.Specification,'') AS Specification, COALESCE(ot.Order_ps,'') AS Order_ps,
                        COALESCE((SELECT SUM(m.allocated_qty) FROM bom_order_process_map m WHERE m.order_id = ot.Order_id), 0) AS already_allocated,
                        EXISTS(SELECT 1 FROM bom_order_process_map m2 WHERE m2.order_id = ot.Order_id) AS has_other_bom
                    FROM order_track ot
                    WHERE ot.d_id_ID = ? AND (ot.Order_status IS NULL OR ot.Order_status <> 9) $kwSql
                    ORDER BY ABS(DATEDIFF(COALESCE(ot.Order_date, ot.Delivery_date), ?)) ASC, ot.Order_id DESC
                    LIMIT 100");
                $so->execute($args);
                $orders = $so->fetchAll(PDO::FETCH_ASSOC);
                usort($orders, function ($a, $b) {
                    return strcmp((string)$b['Order_date'], (string)$a['Order_date'])
                        ?: ((int)$b['Order_id'] <=> (int)$a['Order_id']);
                });
            }
            echo json_encode(['success' => true, 'state' => $state, 'orders' => $orders,
                'can_part' => $ocq_can_bind_part, 'can_order' => $ocq_can_bind_order]);

        } elseif ($action === 'bind_search_part') {
            // 搜尋料號主檔（d_setting）：料號／圖號／規格三欄模糊比對，比照 BOM總表的快速綁定搜尋。
            $term = trim($_POST['term'] ?? '');
            if ($term === '') { echo json_encode(['success' => true, 'results' => []]); exit; }
            $client = trim($_POST['client'] ?? '');
            $like = '%' . $term . '%';
            $sp = $pdo->prepare("SELECT ds.d_id, COALESCE(ds.D_Setting_Id, CAST(ds.d_id AS CHAR), '') AS display_id,
                    COALESCE(ds.Drawing_No,'') AS drawing_no, COALESCE(ds.Spec_No,'') AS spec_no,
                    COALESCE(ds.Customer_Id,'') AS customer_id, COALESCE(cl.customer,'') AS customer_name
                FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                WHERE ds.D_Setting_Id LIKE ? OR ds.Drawing_No LIKE ? OR ds.Spec_No LIKE ?
                ORDER BY (ds.D_Setting_Id = ?) DESC, ds.D_Setting_Id ASC LIMIT 30");
            $sp->execute([$like, $like, $like, $term]);
            $results = $sp->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as &$r) {
                $r['exact_match']  = (strcasecmp($r['display_id'], $term) === 0);
                // 客戶對不對得上只是提示，不當作擋下的條件：BOM 的 Client_Name 是 ERP 匯入的簡稱，
                // 常跟料號主檔綁的客戶寫法不同，硬比會變成一筆都選不到。
                $r['client_match'] = ($client !== '' && $r['customer_name'] !== ''
                    && (mb_strpos($r['customer_name'], $client) !== false || mb_strpos($client, $r['customer_name']) !== false));
            }
            unset($r);
            echo json_encode(['success' => true, 'results' => $results]);

        } elseif ($action === 'bind_apply_part') {
            echo json_encode(ocq_bind_part_to_bom($pdo, $_POST['bom'] ?? '', $_POST['d_setting_id'] ?? 0,
                $id, '快速綁定料號（已完工BOM查詢）'));

        } elseif ($action === 'bind_customers') {
            // 快速建立料號時要挑客戶：一律從客戶主檔挑，**不給打字自由輸入**——打錯一個字就會建出
            // 一筆綁到不存在客戶的料號，而且完全不報錯（比照 ai-rules/08 的「對象一律從主檔挑」）。
            // 已停用的客戶不列（customer_list.is_inactive=1）。
            $cs = $pdo->query("SELECT customer_id, COALESCE(customer,'') AS customer, COALESCE(customer_full,'') AS customer_full
                FROM customer_list WHERE COALESCE(is_inactive,0) <> 1 ORDER BY customer");
            echo json_encode(['success' => true, 'customers' => $cs->fetchAll(PDO::FETCH_ASSOC)]);

        } elseif ($action === 'bind_create_part') {
            // 主檔查無、或同名主檔的客戶跟 BOM 對不上時，直接用 BOM 上的料號文字建一筆料號主檔再綁定。
            // 建立與綁定是同一個動作：建了卻沒綁等於留一筆沒人知道要做什麼的主檔。
            $bom     = trim($_POST['bom'] ?? '');
            $partNo  = trim($_POST['part_no'] ?? '');
            $custId  = trim($_POST['customer_id'] ?? '');
            $specNo  = trim($_POST['spec_no'] ?? '');
            $remark  = trim($_POST['remark'] ?? '');
            if ($bom === '' || $partNo === '') { echo json_encode(['success' => false, 'message' => '請填寫料號']); exit; }
            if (mb_strlen($partNo) > 100) { echo json_encode(['success' => false, 'message' => '料號最多 100 個字']); exit; }

            // 先確認這筆 BOM 還沒被綁（真正的把關在 ocq_bind_part_to_bom 的 FOR UPDATE，這裡先擋掉
            // 明顯不必做的情況，免得白建一筆主檔）
            $sb = $pdo->prepare("SELECT d_setting_id, d_id, Client_Name FROM bom WHERE bom = ? LIMIT 1");
            $sb->execute([$bom]);
            $bomRow = $sb->fetch(PDO::FETCH_ASSOC);
            if (!$bomRow) { echo json_encode(['success' => false, 'message' => '找不到這筆 BOM，請重新整理清單']); exit; }
            if (!empty($bomRow['d_setting_id'])) {
                echo json_encode(['success' => false, 'code' => 'CONFLICT',
                    'message' => '這筆 BOM 已經綁定料號「' . ($bomRow['d_id'] ?: $bomRow['d_setting_id']) . '」，請重新整理清單確認。']); exit;
            }
            // 客戶一律驗證存在（前端擋一次、後端同規則再擋一次＝鐵律8）
            $custName = '';
            if ($custId !== '') {
                $sc = $pdo->prepare("SELECT COALESCE(customer,'') FROM customer_list WHERE customer_id = ? LIMIT 1");
                $sc->execute([$custId]);
                $custName = $sc->fetchColumn();
                if ($custName === false) { echo json_encode(['success' => false, 'message' => '找不到這個客戶（' . $custId . '），請從清單重新挑一個']); exit; }
            }

            // 同料號＋同客戶已經有主檔了就直接用那一筆，不要再建第二筆（重複主檔正是這頁在收拾的問題）
            $dup = $pdo->prepare("SELECT d_id FROM d_setting WHERE TRIM(D_Setting_Id) = ? AND COALESCE(Customer_Id,'') = ? ORDER BY d_id LIMIT 1");
            $dup->execute([$partNo, $custId]);
            $dsid = (int)$dup->fetchColumn();
            $created = false;
            if ($dsid <= 0) {
                $pdo->prepare("INSERT INTO d_setting (D_Setting_Id, Spec_No, Remark, Type, Customer_Id, Created_By, Created_At)
                               VALUES (?, ?, ?, 'N', ?, ?, NOW())")
                    ->execute([$partNo, ($specNo !== '' ? $specNo : null), ($remark !== '' ? $remark : null),
                               ($custId !== '' ? $custId : null), $id]);
                $dsid = (int)$pdo->lastInsertId();
                $created = true;
                ocq_bind_audit($pdo, $id, $bom, '快速建立料號主檔（已完工BOM查詢）', [
                    'after' => ['d_id' => $dsid, 'D_Setting_Id' => $partNo, 'Customer_Id' => $custId,
                                'customer' => $custName, 'Spec_No' => $specNo, 'Remark' => $remark],
                ]);
            }

            $res = ocq_bind_part_to_bom($pdo, $bom, $dsid, $id,
                $created ? '快速建立料號並綁定（已完工BOM查詢）' : '快速綁定料號（已完工BOM查詢）',
                ['rule' => $created ? '主檔查無／客戶對不上，由本頁新建料號主檔後綁定' : '同料號同客戶的主檔已存在，直接沿用']);
            if (!$res['success'] && $created) {
                // 綁不上去就把剛建的那筆收回來（別人同時綁走的極小機會），不要留下一筆沒人用的主檔
                try { $pdo->prepare("DELETE FROM d_setting WHERE d_id = ?")->execute([$dsid]); } catch (Exception $e) {}
            }
            $res['created']   = $created;
            $res['part_no']   = $partNo;
            $res['cust_name'] = $custName;
            if ($res['success']) {
                $res['message'] = ($created ? '已建立料號主檔 ' : '已沿用既有料號主檔 ') . $partNo
                    . ($custName !== '' ? '（' . $custName . '）' : '（未綁客戶）') . ' 並完成綁定';
            }
            echo json_encode($res);

        } elseif ($action === 'bind_apply_order') {
            echo json_encode(ocq_bind_orders_to_bom($pdo, $_POST['bom'] ?? '',
                json_decode($_POST['orders_json'] ?? '[]', true), $id, '快速綁定訂單（已完工BOM查詢）'));

        } elseif ($action === 'autobind_scan') {
            // 試算：只看不寫。把目前篩選範圍內「還沒綁料號」的 BOM 全部判定一次，回統計與樣本。
            list($whereSql, $params) = ocq_ab_where($_POST);
            $st = $pdo->prepare("SELECT b.bom, b.d_id, b.Client_Name $OCQ_FROM $whereSql
                ORDER BY $OCQ_EFFDATE DESC, b.bom DESC");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $cls = ocq_ab_classify($pdo, $rows);
            $cnt = ['auto' => 0, 'manual' => 0, 'nomatch' => 0, 'nopart' => 0];
            $auto_boms = [];
            $sample = [];
            foreach ($rows as $r) {
                $c = $cls[$r['bom']] ?? null;
                if (!$c) continue;
                $cnt[$c['level']]++;
                if ($c['level'] === 'auto') {
                    $auto_boms[] = $r['bom'];
                    if (count($sample) < 20) {
                        $sample[] = ['bom' => $r['bom'], 'part_no' => $r['d_id'],
                            'bom_client' => trim((string)$r['Client_Name']),
                            'to_no' => $c['pick']['part_no'], 'to_client' => $c['pick']['customer_name'],
                            'reason' => $c['reason']];
                    }
                }
            }
            echo json_encode(['success' => true, 'total' => count($rows), 'count' => $cnt,
                'auto_boms' => $auto_boms, 'sample' => $sample]);

        } elseif ($action === 'autobind_list') {
            // 需人工判定（或主檔查無）的清單，分頁列出並帶各自的候選主檔
            list($whereSql, $params) = ocq_ab_where($_POST);
            $kind = in_array($_POST['kind'] ?? '', ['manual', 'nomatch'], true) ? $_POST['kind'] : 'manual';
            $page = max(1, intval($_POST['page'] ?? 1));
            $per  = 20;
            $st = $pdo->prepare("SELECT b.bom, b.d_id, b.Client_Name, b.sqty,
                    DATE_FORMAT($OCQ_EFFDATE,'%Y-%m-%d') AS eff_date $OCQ_FROM $whereSql
                ORDER BY $OCQ_EFFDATE DESC, b.bom DESC");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $cls = ocq_ab_classify($pdo, $rows);

            $hit = [];
            foreach ($rows as $r) {
                $c = $cls[$r['bom']] ?? null;
                if (!$c) continue;
                $lv = $c['level'];
                // 「這筆 BOM 連料號文字都沒有」歸在主檔查無那一頁一起列出來，不要讓它整批消失
                if ($kind === 'nomatch' && $lv !== 'nomatch' && $lv !== 'nopart') continue;
                if ($kind === 'manual' && $lv !== 'manual') continue;
                $hit[] = ['bom' => $r['bom'], 'part_no' => (string)$r['d_id'],
                    'bom_client' => trim((string)$r['Client_Name']), 'qty' => $r['sqty'],
                    'eff_date' => $r['eff_date'], 'reason' => $c['reason'],
                    'candidates' => array_map(function ($x) {
                        return ['d_id' => (int)$x['d_id'], 'part_no' => $x['part_no'],
                            'drawing_no' => $x['drawing_no'], 'spec_no' => $x['spec_no'],
                            'revision' => $x['revision'], 'customer_name' => $x['customer_name'],
                            'customer_id' => $x['customer_id'], 'cust_cmp' => $x['cust_cmp']];
                    }, $c['candidates'])];
            }
            $total = count($hit);
            echo json_encode(['success' => true, 'total' => $total, 'page' => $page, 'per' => $per,
                'rows' => array_slice($hit, ($page - 1) * $per, $per)]);

        } elseif ($action === 'autobind_apply') {
            // 實際寫入：前端分批送 BOM 清單過來。**每一筆都要在交易裡重新判定一次**——
            // 試算跟按下執行之間可能隔了很久，期間別人可能已經綁好、或剛建了同名料號主檔
            // 讓原本唯一的變成多筆（ai-rules/08 第六節點開即刷新）。
            $list = json_decode($_POST['boms_json'] ?? '[]', true);
            if (!is_array($list) || !$list) { echo json_encode(['success' => false, 'message' => '沒有要處理的 BOM']); exit; }
            if (count($list) > 500) { echo json_encode(['success' => false, 'message' => '一批最多 500 筆']); exit; }

            $ph = implode(',', array_fill(0, count($list), '?'));
            $sr = $pdo->prepare("SELECT bom, d_id, d_setting_id, Client_Name FROM bom WHERE bom IN ($ph)");
            $sr->execute(array_values($list));
            $cur_rows = $sr->fetchAll(PDO::FETCH_ASSOC);
            $cls = ocq_ab_classify($pdo, $cur_rows);

            $done = 0; $skipped = [];
            foreach ($cur_rows as $cur) {
                $bom = $cur['bom'];
                if (!empty($cur['d_setting_id'])) { $skipped[] = ['bom' => $bom, 'why' => '已經有人綁過了']; continue; }
                $c = $cls[$bom] ?? null;
                if (!$c || $c['level'] !== 'auto' || empty($c['pick'])) {
                    $skipped[] = ['bom' => $bom, 'why' => $c['reason'] ?? '判定不出唯一料號'];
                    continue;
                }
                $pick = $c['pick'];
                $pdo->beginTransaction();
                try {
                    // FOR UPDATE 再確認一次，擋兩個人同時按下去
                    $lk = $pdo->prepare("SELECT d_setting_id, d_id, Client_Name FROM bom WHERE bom = ? FOR UPDATE");
                    $lk->execute([$bom]);
                    $now = $lk->fetch(PDO::FETCH_ASSOC);
                    if (!$now || !empty($now['d_setting_id'])) {
                        $pdo->rollBack();
                        $skipped[] = ['bom' => $bom, 'why' => '已經有人綁過了'];
                        continue;
                    }
                    // 客戶名稱：主檔有綁客戶才一起換掉；主檔沒綁客戶時要保留 BOM 原本的名稱，
                    // 不可以寫空字串把 ERP 匯入的原值洗掉（與 bind_apply_part 同一條規則）
                    if (trim((string)$pick['customer_name']) !== '') {
                        $pdo->prepare("UPDATE bom SET d_setting_id=?, d_id=?, Client_Name=?, Modified_By=?, Modified_At=NOW() WHERE bom=?")
                            ->execute([(int)$pick['d_id'], $pick['part_no'], $pick['customer_name'], $id, $bom]);
                    } else {
                        $pdo->prepare("UPDATE bom SET d_setting_id=?, d_id=?, Modified_By=?, Modified_At=NOW() WHERE bom=?")
                            ->execute([(int)$pick['d_id'], $pick['part_no'], $id, $bom]);
                    }
                    $pdo->commit();
                    $done++;
                    ocq_bind_audit($pdo, $id, $bom, '自動綁定料號（已完工BOM查詢）', [
                        'before' => ['d_setting_id' => $now['d_setting_id'], 'd_id' => $now['d_id'], 'Client_Name' => $now['Client_Name']],
                        'after'  => ['d_setting_id' => (int)$pick['d_id'], 'd_id' => $pick['part_no'],
                            'Client_Name' => $pick['customer_name'] ?: $now['Client_Name']],
                        'rule'   => $c['reason'],
                    ]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $skipped[] = ['bom' => $bom, 'why' => '寫入失敗：' . $e->getMessage()];
                }
            }
            echo json_encode(['success' => true, 'done' => $done, 'skipped' => $skipped]);

        } elseif ($action === 'autobind_order_scan') {
            // 試算：只看不寫。範圍＝篩選條件內「已綁料號、還沒綁訂單」的 BOM。
            list($whereSql, $params) = ocq_ob_where($_POST);
            $st = $pdo->prepare("SELECT b.bom, b.d_id, b.d_setting_id, b.sqty, b.Client_Name,
                    DATE_FORMAT($OCQ_EFFDATE,'%Y-%m-%d') AS eff_date $OCQ_FROM $whereSql
                ORDER BY $OCQ_EFFDATE DESC, b.bom DESC");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $cls  = ocq_ob_classify($pdo, $rows);
            $cnt = ['auto' => 0, 'manual' => 0, 'nomatch' => 0];
            $auto = []; $sample = [];
            foreach ($rows as $r) {
                $c = $cls[$r['bom']] ?? null;
                if (!$c) continue;
                $cnt[$c['level']]++;
                if ($c['level'] === 'auto') {
                    $auto[] = ['bom' => $r['bom'], 'order_id' => (int)$c['pick']['Order_id'], 'qty' => (int)$c['pick']['qty_default']];
                    if (count($sample) < 20) {
                        $sample[] = ['bom' => $r['bom'], 'part_no' => $r['d_id'], 'bom_qty' => $r['sqty'],
                            'order_oo' => $c['pick']['Order_oo'], 'order_date' => $c['pick']['Order_date'],
                            'order_qty' => $c['pick']['Qty'], 'used' => $c['pick']['already_allocated'],
                            'qty' => $c['pick']['qty_default'], 'reason' => $c['reason']];
                    }
                }
            }
            echo json_encode(['success' => true, 'total' => count($rows), 'count' => $cnt,
                'auto_rows' => $auto, 'sample' => $sample]);

        } elseif ($action === 'autobind_order_list') {
            list($whereSql, $params) = ocq_ob_where($_POST);
            $kind = in_array($_POST['kind'] ?? '', ['manual', 'nomatch'], true) ? $_POST['kind'] : 'manual';
            $page = max(1, intval($_POST['page'] ?? 1));
            $per  = 20;
            $st = $pdo->prepare("SELECT b.bom, b.d_id, b.d_setting_id, b.sqty, b.Client_Name,
                    DATE_FORMAT($OCQ_EFFDATE,'%Y-%m-%d') AS eff_date $OCQ_FROM $whereSql
                ORDER BY $OCQ_EFFDATE DESC, b.bom DESC");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $cls  = ocq_ob_classify($pdo, $rows);          // 先只判等級（不撈候選）
            $hit  = [];
            foreach ($rows as $r) {
                $lv = $cls[$r['bom']]['level'] ?? '';
                if ($lv === $kind) $hit[] = $r;
            }
            $total = count($hit);
            $pageRows = array_slice($hit, ($page - 1) * $per, $per);
            $cls2 = ocq_ob_classify($pdo, $pageRows, true);  // 這一頁的才撈候選訂單
            $out = [];
            foreach ($pageRows as $r) {
                $c = $cls2[$r['bom']] ?? [];
                $out[] = ['bom' => $r['bom'], 'part_no' => (string)$r['d_id'], 'bom_qty' => $r['sqty'],
                    'bom_client' => trim((string)$r['Client_Name']), 'eff_date' => $r['eff_date'],
                    'reason' => $c['reason'] ?? '', 'cand_total' => $c['cand_total'] ?? count($c['candidates'] ?? []),
                    'candidates' => array_map(function ($o) {
                        return ['order_id' => (int)$o['Order_id'], 'order_oo' => $o['Order_oo'],
                            'order_date' => $o['Order_date'], 'delivery_date' => $o['Delivery_date'],
                            'qty' => (int)$o['Qty'], 'used' => (int)$o['already_allocated'],
                            'qty_default' => (int)$o['qty_default'],
                            'spec' => trim($o['Specification'] . ($o['Order_ps'] !== '' ? '／' . $o['Order_ps'] : ''))];
                    }, $c['candidates'] ?? [])];
            }
            echo json_encode(['success' => true, 'total' => $total, 'page' => $page, 'per' => $per, 'rows' => $out]);

        } elseif ($action === 'autobind_order_apply') {
            // 前端分批送 [{bom, orders:[{order_id,qty},…]}]；每一筆都在交易裡重新驗一次（點開即刷新）
            // **同一筆 BOM 的多張訂單一定要一次送進 ocq_bind_orders_to_bom()**：那支的既有綁定保護是
            // 「已經有 bom_order_process_map 就整個擋下」，拆成好幾次呼叫的話第一張寫成功、第二張起
            // 一律被自己剛寫的那一列擋成 CONFLICT，畫面上看起來就是「只綁得進去一張」。
            $list = json_decode($_POST['rows_json'] ?? '[]', true);
            if (!is_array($list) || !$list) { echo json_encode(['success' => false, 'message' => '沒有要處理的資料']); exit; }
            if (count($list) > 200) { echo json_encode(['success' => false, 'message' => '一批最多 200 筆']); exit; }
            $done = 0; $skipped = [];
            foreach ($list as $it) {
                $bom = trim((string)($it['bom'] ?? ''));
                $orders = [];
                if (isset($it['orders']) && is_array($it['orders'])) {
                    foreach ($it['orders'] as $o) {
                        $oid = intval($o['order_id'] ?? 0);
                        if ($oid > 0) $orders[] = ['order_id' => $oid, 'qty' => max(0, intval($o['qty'] ?? 0))];
                    }
                } elseif (intval($it['order_id'] ?? 0) > 0) {
                    // 舊型態（自動綁定那批送的是單張）：維持原樣收下，不必改那一段
                    $orders[] = ['order_id' => intval($it['order_id']), 'qty' => max(0, intval($it['qty'] ?? 0))];
                }
                if ($bom === '' || !$orders) { $skipped[] = ['bom' => $bom, 'why' => '缺少訂單']; continue; }
                try {
                    $r = ocq_bind_orders_to_bom($pdo, $bom, $orders, $id, '自動綁定訂單（已完工BOM查詢）');
                } catch (Exception $e) { $r = ['success' => false, 'message' => '寫入失敗：' . $e->getMessage()]; }
                if (!empty($r['success'])) $done++;
                else $skipped[] = ['bom' => $bom, 'why' => $r['message'] ?? '綁定失敗'];
            }
            echo json_encode(['success' => true, 'done' => $done, 'skipped' => $skipped]);

        } elseif ($action === 'autobind_order_refresh') {
            // 綁完之後把「剛剛被分配到的那幾張訂單」的最新狀態撈回來，讓畫面上**其他還沒判定的列**
            // 就地更新（同一個料號的好幾筆 BOM 共用同一批候選訂單，不更新就會照著舊的「已分配」
            // 再分配一次）。**數字一律重查資料庫不在前端自己扣**——同一時間別人也在綁。
            // 收 [{order_id, bom_qty}]：分配量預設值要看那一列 BOM 的發單量，所以連 bom_qty 一起送，
            // 才能直接用 ocq_ob_qty_default()（唯一實作）算，不必在 JS 再寫一份同樣的規則。
            $req = json_decode($_POST['orders_json'] ?? '[]', true);
            if (!is_array($req) || !$req) { echo json_encode(['success' => true, 'rows' => []]); exit; }
            $pairs = []; $ids = [];
            foreach ($req as $p) {
                $oid = intval($p['order_id'] ?? 0);
                if ($oid <= 0) continue;
                $bq = max(0, intval($p['bom_qty'] ?? 0));
                $pairs[$oid . ':' . $bq] = ['order_id' => $oid, 'bom_qty' => $bq];
                $ids[$oid] = 1;
                if (count($pairs) >= 400) break;
            }
            if (!$pairs) { echo json_encode(['success' => true, 'rows' => []]); exit; }
            $ids = array_keys($ids);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $st  = $pdo->prepare("SELECT " . ocq_ob_order_cols() . " FROM order_track ot WHERE ot.Order_id IN ($ph)");
            $st->execute($ids);
            $om = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) $om[(int)$o['Order_id']] = $o;
            // 這張訂單目前掛在哪幾筆 BOM 上（畫面要寫「已被 B-xxx 綁走」，只講數字看不出是被誰拿走的）
            $bm = [];
            $sb = $pdo->prepare("SELECT order_id, bom FROM bom_order_process_map WHERE order_id IN ($ph) ORDER BY id");
            $sb->execute($ids);
            foreach ($sb->fetchAll(PDO::FETCH_ASSOC) as $b) $bm[(int)$b['order_id']][] = $b['bom'];
            $out = [];
            foreach ($pairs as $key => $p) {
                $o = $om[$p['order_id']] ?? null;
                if (!$o) continue;
                $qty = (int)$o['Qty']; $used = (int)$o['already_allocated'];
                $out[] = ['key' => $key, 'order_id' => $p['order_id'], 'bom_qty' => $p['bom_qty'],
                    'qty' => $qty, 'used' => $used, 'left' => max(0, $qty - $used),
                    'qty_default' => ocq_ob_qty_default($p['bom_qty'], $o),
                    'boms' => $bm[$p['order_id']] ?? []];
            }
            echo json_encode(['success' => true, 'rows' => $out]);

        } elseif ($action === 'autobind_quote_scan') {
            list($ids, ) = ocq_qb_order_ids($pdo, $_POST);
            $orders = ocq_qb_orders($pdo, $ids);
            $cls = ocq_qb_classify($pdo, $orders);
            $cnt = ['auto' => 0, 'manual' => 0, 'nomatch' => 0];
            $auto = []; $sample = [];
            foreach ($orders as $o) {
                $c = $cls[(int)$o['Order_id']] ?? null;
                if (!$c) continue;
                $cnt[$c['level']]++;
                if ($c['level'] === 'auto') {
                    $auto[] = ['order_id' => (int)$o['Order_id'], 'item_id' => (int)$c['pick']['item_id']];
                    if (count($sample) < 20) {
                        $sample[] = ['order_oo' => $o['Order_oo'], 'client' => $o['Client_name'], 'part_no' => $o['part_no'],
                            'order_date' => $o['Order_date'], 'quote_no' => $c['pick']['quote_no'],
                            'quote_date' => $c['pick']['quote_date'], 'price' => $c['pick']['unit_price'], 'reason' => $c['reason']];
                    }
                }
            }
            echo json_encode(['success' => true, 'total' => count($orders), 'count' => $cnt,
                'auto_rows' => $auto, 'sample' => $sample]);

        } elseif ($action === 'autobind_quote_list') {
            $kind = in_array($_POST['kind'] ?? '', ['manual', 'nomatch'], true) ? $_POST['kind'] : 'manual';
            $page = max(1, intval($_POST['page'] ?? 1));
            $per  = 20;
            list($ids, ) = ocq_qb_order_ids($pdo, $_POST);
            $orders = ocq_qb_orders($pdo, $ids);
            $cls = ocq_qb_classify($pdo, $orders);
            $hit = [];
            foreach ($orders as $o) {
                $c = $cls[(int)$o['Order_id']] ?? null;
                if (!$c || $c['level'] !== $kind) continue;
                $hit[] = ['order_id' => (int)$o['Order_id'], 'order_oo' => $o['Order_oo'], 'client' => $o['Client_name'],
                    'part_no' => $o['part_no'], 'order_date' => $o['Order_date'], 'order_qty' => (int)$o['Qty'],
                    'reason' => $c['reason'], 'cand_total' => $c['cand_total'] ?? count($c['candidates']),
                    'candidates' => array_map(function ($q) {
                        return ['item_id' => (int)$q['item_id'], 'quote_no' => $q['quote_no'], 'quote_date' => $q['quote_date'],
                            'price' => $q['unit_price'], 'qty' => $q['quantity'], 'client' => $q['client_name'],
                            'spec' => mb_substr((string)$q['specification'], 0, 40)];
                    }, $c['candidates'])];
            }
            echo json_encode(['success' => true, 'total' => count($hit), 'page' => $page, 'per' => $per,
                'rows' => array_slice($hit, ($page - 1) * $per, $per)]);

        } elseif ($action === 'autobind_quote_apply') {
            // 寫入一律走會計模組既有的 acc_recon_bind_quote()（唯一實作，含料號文字比對與稽核）
            require_once __DIR__ . '/../../src/common/acc_lib.php';
            $list = json_decode($_POST['rows_json'] ?? '[]', true);
            if (!is_array($list) || !$list) { echo json_encode(['success' => false, 'message' => '沒有要處理的資料']); exit; }
            if (count($list) > 200) { echo json_encode(['success' => false, 'message' => '一批最多 200 筆']); exit; }
            $me = ['user_id' => $id, 'id' => $id];
            $done = 0; $skipped = [];
            foreach ($list as $it) {
                $oid = intval($it['order_id'] ?? 0);
                $iid = intval($it['item_id'] ?? 0);
                if ($oid <= 0 || $iid <= 0) { $skipped[] = ['order_id' => $oid, 'why' => '缺少報價明細']; continue; }
                // 已經有人綁走的一律不覆蓋（點開即刷新鐵則；acc_recon_bind_quote 本身不檢查這件事）
                $chk = $pdo->prepare("SELECT COALESCE(quote_no,'') qn, quote_item_id FROM order_track WHERE Order_id = ? LIMIT 1");
                $chk->execute([$oid]);
                $cur = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$cur) { $skipped[] = ['order_id' => $oid, 'why' => '找不到這張訂單']; continue; }
                if ($cur['qn'] !== '' || $cur['quote_item_id'] !== null) {
                    $skipped[] = ['order_id' => $oid, 'why' => '已經綁了報價單 ' . $cur['qn'] . '（可能是其他人剛綁的）'];
                    continue;
                }
                try { $r = acc_recon_bind_quote($pdo, $oid, $iid, $me); }
                catch (Throwable $e) { $r = ['success' => false, 'message' => '寫入失敗：' . $e->getMessage()]; }
                if (!empty($r['success'])) $done++;
                else $skipped[] = ['order_id' => $oid, 'why' => $r['message'] ?? '綁定失敗'];
            }
            echo json_encode(['success' => true, 'done' => $done, 'skipped' => $skipped]);

        } elseif ($action === 'list') {
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
            // 訂單綁定狀態一律撈（不再只在有綁定權限時）——**交期欄要靠它**：手動交期九成七是空的，
            // 真正的交期在綁定訂單上。有沒有綁定權限只影響「綁定」那一欄畫不畫得出來。
            $rows_by_bom = [];
            foreach ($rows as $r0) $rows_by_bom[$r0['bom']] = $r0;
            $bind_map = ocq_bind_map($pdo, $bom_list, $rows_by_bom);
            ocq_attach_delivery($rows, $bind_map);
            foreach ($rows as &$row) {
                $row['processes'] = $proc_map[$row['bom']] ?? [];
                $row['has_file']  = $file_map[$row['bom']] ?? null;   // true/false；null＝快取還沒建好、無法判定
                $bi = $bind_map[$row['bom']] ?? [];
                $row['bound_orders'] = $bi['orders'] ?? [];
                $row['is_stock']     = !empty($bi['stock']);
                $row['legacy_order'] = $bi['legacy'] ?? null;
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
            $rows_by_bom = []; foreach ($rows as $r0) $rows_by_bom[$r0['bom']] = $r0;
            ocq_attach_delivery($rows, ocq_bind_map($pdo, $bom_list, $rows_by_bom));
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
        /* ── 快速綁定（料號／訂單）──────────────────────────────────────────────
           徽章／小按鈕一律自己寫死 line-height：表格列的 line-height 是繼承來的 28px，
           字級再小也會佔掉整列的行高，一個 11px 的字就能把整列撐高（2026-09-03 急件徽章的教訓）。 */
        /* 綁定欄一律靠左（使用者回報：置中時「料號／訂單」兩行各自置中，看起來像散開的兩段） */
        .ocq-bind-td { white-space: nowrap; text-align: left !important; }
        .ocq-bind-td > div { text-align: left; }
        .ocq-bind-btn { font-size: 11px; line-height: 16px; padding: 1px 6px; margin: 1px 0; cursor: pointer;
            border: 1px solid #D8BE93; border-radius: 3px; background: #fff; color: #8A5A2B; }
        .ocq-bind-btn:hover { background: #F7E0BD; }
        .ocq-bind-btn.is-wait { opacity: .6; }
        .ocq-bind-ok { font-size: 11px; line-height: 16px; color: #2f7a3f; margin: 1px 0;
            max-width: 150px; overflow: hidden; text-overflow: ellipsis; }
        /* 綁多張訂單時第二張起各自一行（line-height 一定要自己寫，見 [[td_span_line_height_trap]]） */
        .ocq-bind-more { font-size: 11px; line-height: 16px; padding-left: 14px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ocq-bind-no { font-size: 11px; line-height: 16px; color: #b0a08a; margin: 1px 0; }
        .ocq-bind-box { background: #fff; border-radius: 8px; width: 760px; max-width: 94vw; margin: 50px auto;
            box-shadow: 0 5px 25px rgba(0,0,0,.3); max-height: 84vh; display: flex; flex-direction: column; }
        .ocq-bind-hd { background: #F7E0BD; color: #5b3a1e; font-weight: bold; padding: 10px 15px;
            border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .ocq-bind-bd { padding: 12px 15px; overflow-y: auto; font-size: 13px; color: #5b3a1e; }
        .ocq-bind-ft { padding: 10px 15px; border-top: 1px solid #EADFC8; text-align: right; }
        .ocq-bind-meta { background: #FFF7E8; border: 1px dashed #F0A24B; border-radius: 6px; padding: 6px 10px; margin-bottom: 10px; line-height: 1.8; }
        .ocq-bind-meta b { color: #8A5A2B; }
        table.ocq-pick { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.ocq-pick th, table.ocq-pick td { border: 1px solid #EADFC8; padding: 4px 7px; text-align: center; }
        table.ocq-pick thead th { background: #F7E0BD; position: sticky; top: 0; }
        table.ocq-pick td.tl { text-align: left; }
        table.ocq-pick tbody tr:hover { background: #FBF0DD; }
        table.ocq-pick tbody tr.hit { background: #EDF7EC; }
        table.ocq-pick tbody tr.ord-full { background: #FBE7E1; }
        table.ocq-pick tbody tr.ord-full.hit { background: #F3EDD9; }
        .ocq-pick-wrap { max-height: 46vh; overflow: auto; border: 1px solid #E8D5B5; border-radius: 4px; }
        .ocq-pick-qty { width: 72px; height: 24px; font-size: 12px; padding: 0 4px; border: 1px solid #D8BE93; border-radius: 3px; text-align: right; }
        .ocq-bind-err { color: #DD5138; font-size: 12.5px; margin-top: 6px; line-height: 1.7; }
        /* ── 自動綁定料號跳窗（固定像素寬，不可用 vw：會蓋過左側選單，見記憶 modal_width_convention）── */
        .ocq-ab-box { background: #fff; border-radius: 8px; width: 1000px; max-width: 94vw; margin: 40px auto;
            box-shadow: 0 5px 25px rgba(0,0,0,.3); display: flex; flex-direction: column; max-height: 88vh; }
        .ocq-ab-cards { display: flex; gap: 10px; margin-bottom: 10px; }
        .ocq-ab-card { flex: 1; border: 1px solid #EADFC8; border-radius: 6px; padding: 8px 10px; background: #FFFCF6; }
        .ocq-ab-card .n { font-size: 22px; font-weight: bold; line-height: 1.2; color: #8A5A2B; }
        .ocq-ab-card .t { font-size: 13px; color: #5b3a1e; font-weight: bold; }
        .ocq-ab-card .d { font-size: 11.5px; color: #a08a6a; line-height: 1.5; margin-top: 2px; }
        .ocq-ab-card.is-auto { border-color: #9BC79B; background: #F4FAF4; }
        .ocq-ab-card.is-auto .n { color: #2f7a3f; }
        .ocq-ab-card.is-manual { border-color: #E0B378; background: #FFF7E8; }
        .ocq-ab-card.is-manual .n { color: #C77A22; }
        .ocq-ab-card.is-none { border-color: #E3C4BC; background: #FDF5F3; }
        .ocq-ab-card.is-none .n { color: #B4543B; }
        /* 三個階段（料號→訂單→報價單）的切換列 */
        .ocq-ab-stages { display: flex; gap: 6px; margin-bottom: 10px; }
        .ocq-ab-stage { height: 30px; padding: 0 16px; font-size: 13px; font-weight: bold; border-radius: 5px;
            border: 1px solid #D8BE93; background: #fff; color: #8A5A2B; cursor: pointer; }
        .ocq-ab-stage.active { background: #8A5A2B; border-color: #6d4520; color: #fff; }
        .ocq-ab-tabs { display: flex; gap: 4px; border-bottom: 1px solid #EADFC8; margin-bottom: 8px; }
        .ocq-ab-tab { height: 28px; padding: 0 14px; font-size: 12.5px; border: 1px solid #D8BE93;
            border-bottom: none; border-radius: 5px 5px 0 0; background: #fff; color: #5b3a1e; cursor: pointer; }
        .ocq-ab-tab.active { background: #F0A24B; border-color: #d98a33; color: #fff; font-weight: bold; }
        .ocq-ab-tb { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .ocq-ab-tb th { background: #F7E0BD; color: #5b3a1e; padding: 5px 7px; text-align: left;
            border: 1px solid #EADFC8; white-space: nowrap; }
        .ocq-ab-tb td { padding: 5px 7px; border: 1px solid #EADFC8; color: #5b3a1e; vertical-align: top; }
        .ocq-ab-tb tr:nth-child(even) td { background: #FFFCF6; }
        .ocq-ab-why { font-size: 11.5px; color: #a0521f; line-height: 1.6; }
        /* 候選料號：一列一筆，客戶對得上的標綠、對不上的標紅，讓人一眼看出差在哪 */
        .ocq-ab-cand { display: block; padding: 3px 5px; border: 1px solid #EADFC8; border-radius: 4px;
            margin-bottom: 3px; background: #fff; cursor: pointer; line-height: 1.6; }
        .ocq-ab-cand:hover { background: #FBF0DC; }
        /* 已被別的 BOM 分配滿的候選訂單：不停用（現場偶爾真的要超配），但一定要一眼看得出來 */
        .ocq-ab-cand.cand-full { background: #FBE7E1; border-color: #E0B9AC; }
        .ocq-cand-full { color: #B4543B; }
        .ocq-ab-cand input { margin-right: 5px; }
        .ocq-ab-cand .cm-same { color: #2f7a3f; font-weight: bold; }
        .ocq-ab-cand .cm-diff { color: #B4543B; }
        .ocq-ab-cand .cm-unknown { color: #a08a6a; }
        .ocq-ab-sub { font-size: 11px; color: #a08a6a; }
        .ocq-ab-pg { margin-top: 8px; text-align: right; font-size: 12.5px; color: #5b3a1e; }
        .ocq-ab-pg button { height: 26px; padding: 0 10px; font-size: 12px; border: 1px solid #D8BE93;
            background: #fff; color: #5b3a1e; border-radius: 4px; cursor: pointer; margin-left: 3px; }
        .ocq-ab-pg button:disabled { opacity: .4; cursor: default; }
        .ocq-ab-bar { height: 16px; border-radius: 8px; background: #F2E6CE; overflow: hidden; margin: 8px 0; }
        .ocq-ab-bar > i { display: block; height: 100%; background: #F0A24B; width: 0; transition: width .2s; }
        /* 勾選後一次套用：工具列釘在清單上方，捲到下面一樣按得到 */
        .ocq-ab-bulk { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; gap: 8px;
            background: #FFF7E8; border: 1px solid #E0B378; border-radius: 6px; padding: 6px 10px; margin-bottom: 6px; }
        .ocq-ab-bulk .sp { flex: 1; }
        .ocq-ab-bulk button { height: 26px; padding: 0 12px; font-size: 12.5px; border-radius: 4px;
            border: 1px solid #d98a33; background: #F0A24B; color: #fff; cursor: pointer; }
        .ocq-ab-bulk button.sec { border-color: #D8BE93; background: #fff; color: #5b3a1e; }
        .ocq-ab-bulk button:disabled { opacity: .45; cursor: default; }
        .ocq-ab-tb td.ck, .ocq-ab-tb th.ck { text-align: center; width: 34px; }
        .ocq-ab-tb tr.picked td { background: #FBF3E3 !important; }
        .ocq-ab-tb tr.done td { background: #F4FAF4 !important; }
        .ocq-ab-rowmsg { font-size: 11px; line-height: 16px; color: #DD5138; margin-top: 2px; }
        /* 快速建立料號跳窗：疊在自動綁定跳窗之上（固定像素寬，不可用 vw，見 [[modal_width_convention]]） */
        .ocq-np-box { background: #fff; border-radius: 8px; width: 880px; max-width: 94vw; margin: 40px auto;
            box-shadow: 0 5px 25px rgba(0,0,0,.3); display: flex; flex-direction: column; max-height: 86vh; }
        .ocq-np-tb input.pn { width: 100%; height: 24px; font-size: 12.5px; padding: 0 5px;
            border: 1px solid #D8BE93; border-radius: 3px; color: #5b3a1e; }
        .ocq-np-tb select.cs { width: 100%; height: 24px; font-size: 12.5px; border: 1px solid #D8BE93;
            border-radius: 3px; color: #5b3a1e; background: #fff; }
        .ocq-np-tb select.cs.auto-hit { border-color: #9BC79B; background: #F4FAF4; }
        .ocq-np-tb td.st { font-size: 11px; line-height: 1.6; }
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
                <?php if ($ocq_can_bind_part || $ocq_can_bind_order): ?>
                <label style="white-space:nowrap;">綁定狀態</label>
                <select id="fBind" title="把「還沒綁好的」直接篩出來；篩完可直接按右邊的「自動綁定」整批處理">
                    <option value="">（全部）</option>
                    <option value="no_part">未綁料號</option>
                    <option value="no_order">未綁訂單（已綁料號）</option>
                    <option value="no_quote">未綁報價單（已綁訂單）</option>
                    <option value="all_done">料號·訂單·報價單都綁好</option>
                </select>
                <?php endif; ?>
                <button class="btn-warm" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
                <button id="btnClear" title="清掉所有篩選條件，日期回到預設的近1年（要查全部歷史請按上方年份列的「全部年份」）"><i class="fa fa-eraser"></i> 清除篩選</button>
                <?php if ($ocq_can_bind_part || $ocq_can_bind_order): ?>
                <button id="btnAutoBind" style="margin-left:auto;" title="把目前篩選範圍內還沒綁好的 BOM 一次處理：①綁料號 ②綁訂單 ③綁報價單，指得出唯一一筆的可以整批自動綁，指不出來的列出來讓你逐筆判定"><i class="fa fa-magic"></i> 自動綁定</button>
                <button id="btnPrint"><i class="fa fa-print"></i> 列印</button>
                <?php else: ?>
                <button id="btnPrint" style="margin-left:auto;"><i class="fa fa-print"></i> 列印</button>
                <?php endif; ?>
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
        <?php if ($ocq_can_bind_part || $ocq_can_bind_order): ?>
        <h4>快速綁定料號／訂單（需權限）</h4>
        <ul>
            <li>表格多出一欄<b>「綁定」</b>，顯示這筆 BOM 有沒有綁到料號主檔與訂單；<b>已綁定的會顯示綠色狀態、不再出現按鈕</b>。</li>
            <li><b>綁料號</b>：按下後以目前料號文字自動搜尋料號主檔（料號／圖號／規格都可比對），挑一筆按「套用」即綁定。綁定後會一併把 BOM 的料號文字與客戶名稱改成料號主檔上的值；<b>料號主檔沒有綁客戶時保留原客戶名稱不動</b>，不會被清空。</li>
            <li><b>綁訂單</b>：勾選訂單（可多張）並填「分配量」後按「確定綁定」。<b>候選訂單只會列出這個料號底下的</b>，所以要先綁好料號才挑得到訂單；沒綁料號就按會直接提示。</li>
            <li>候選訂單依「訂單日期與這筆 BOM 的日期最接近」取前 100 張，不是照日期由新到舊——同一料號常有上千張訂單，照新到舊取會把真正該綁的那幾張整批切掉。可用上方關鍵字再篩（訂單編號／規格／備註／客戶）。</li>
            <li>「分配量」＝這筆 BOM 算在該訂單頭上的數量，預設帶入「BOM 發單量」與「該訂單尚未被分配的量」中較小者，可自行修改。</li>
            <li>綁好之後<b>只有那一列就地更新</b>，清單不會整份重查，所以連續補好幾筆時畫面位置不會跳掉。</li>
        </ul>
        <div class="tip"><b>已經綁過的一律不覆蓋</b>：按下按鈕的當下會先跟後端要一次最新狀態，若這筆已經被別人綁走，會直接擋下、把該列更新成最新狀態並提示你，不會蓋掉別人剛做好的資料。<b>要改綁或解除綁定請到「BOM 總表」的更新表單處理</b>，本頁只做「從未綁定 → 綁定」這一步。</div>
        <div class="tip"><b>備庫（bom.o_order_id = B）不算未綁定</b>：那種 BOM 本來就沒有對應訂單，畫面顯示「備庫」並且不提供綁訂單按鈕，與 BOM 總表的處理一致。</div>
        <?php endif; ?>
        <?php if ($ocq_can_bind_part || $ocq_can_bind_order): ?>
        <h4>綁定狀態篩選</h4>
        <div class="tip">篩選列的<b>「綁定狀態」</b>可以把還沒綁好的直接篩出來：<b>未綁料號</b>／<b>未綁訂單</b>（已綁料號的才算）／<b>未綁報價單</b>（已綁訂單、但那張訂單上沒有報價單號）／<b>都綁好了</b>。篩完可以直接按右邊的「自動綁定」整批處理，<b>列印／匯出CSV／統整報表也吃同一個條件</b>。備庫（o_order_id=B）不會被算成「未綁訂單」。</div>
        <h4>自動綁定（需權限）</h4>
        <div class="tip">工具列的<b>「自動綁定」</b>是一條鏈，跳窗上方分成三個階段：<b>①綁料號 →②綁訂單 →③綁報價單</b>（料號綁好才挑得到訂單、訂單綁好才挑得到報價單）。<b>每個階段各自掃描、範圍也各自不同</b>，所以綁完一階切到下一階會重新比對一次；三個階段都吃<b>目前的篩選條件</b>（含日期區間），要處理全部歷史資料請先按年份列的「全部年份」。</div>
        <ul>
            <li><b>②綁訂單</b>：候選＝<b>同一筆料號主檔底下、未作廢的訂單</b>。這個料號底下<b>只有一張訂單時才自動綁</b>；有好幾張時列出來讓你挑（依「訂單日與這筆 BOM 的日期最接近」排序），<b>分配量可以逐張改</b>，預設帶入「BOM 發單量」與「該訂單未被分配量」中較小者。<b>同一筆 BOM 的量分屬好幾張訂單時可以一次挑好幾張</b>（一次最多 20 張，各自寫入分配量；挑兩張以上會顯示「分配量合計／發單量」，超過會標紅提醒但不擋你，因為確實有一張 BOM 補足好幾張訂單缺量的情形）。</li>
            <li><b>③綁報價單</b>：報價單是綁在<b>訂單</b>上的（不是 BOM），所以這一階處理的是「範圍內的 BOM 綁到的訂單裡還沒綁報價單的」。候選＝<b>報價明細的料號與訂單料號完全相同</b>的報價單（草稿與待審核的不列入）；只有一筆時自動綁，多筆時依「<b>報價日不晚於訂單日、且日期最接近</b>」排序，第一筆會標「建議：下單當時的報價」。</li>
            <li>兩個階段都可以<b>逐筆按「套用」，也可以勾選起來一次全部套用</b>；挑好候選會自動幫你勾起該列。已經被別人綁走的一律擋下、不覆蓋。</li>
            <li><b>綁完訂單後，畫面上其他還沒判定的列會自動更新</b>：同一個料號的好幾筆 BOM 會排在一起、而且共用同一批候選訂單，綁完第一筆之後其餘各列的「已分配／未分配」會立刻重查更新，<b>已經被分配滿的那張訂單會標紅寫明是被哪一筆 BOM 綁走、分配量預設帶 0</b>，避免照著舊數字把同一張訂單再分配一次。已經判定完成的列與捲動位置不會被洗掉（不是整份重載）。</li>
        </ul>
        <?php endif; ?>
        <?php if ($ocq_can_bind_part): ?>
        <h4>①綁料號的三張分頁</h4>
        <ul>
            <li>拿 BOM 上的料號文字去跟料號主檔比對，分成：<b>可自動綁定</b>（指得出唯一一筆）／<b>需人工判定</b>（同名主檔多筆、或客戶對不上）／<b>主檔查無</b>。</li>
            <li><b>可自動綁定</b>：按「開始自動綁定」整批寫入，分批送、有進度條；每一筆在寫入的當下會重新判定一次，已經被別人綁走的自動略過。</li>
            <li><b>需人工判定</b>：逐筆挑一個候選料號後，可以<b>直接按該列的「套用」，也可以勾選起來一次全部套用</b>——挑好候選會自動幫你勾起該列，上方工具列按「套用勾選的」即整批綁定。<b>勾了卻沒挑候選的不會替你猜</b>，會在該列寫明原因略過。</li>
            <li><b>候選的客戶都對不上</b>（例如主檔只有一筆、但那筆綁的是別家客戶）時，請改按該列的<b>「建立新料號」</b>：以這張 BOM 的料號文字建一筆新的料號主檔並立刻綁定。</li>
            <li><b>主檔查無</b>：同樣可以逐筆按「建立料號」或勾選後一次建立，不必先跑一趟主檔管理。</li>
            <li>建立料號時<b>客戶一律從客戶主檔挑</b>（下拉可打字篩選），系統會先用 BOM 上的客戶名稱自動對應（ERP 的「高鋒工業」對得到主檔的「高鋒」），對到的會標成綠色；<b>對不到就留白請你自己挑</b>，不會亂猜。單筆建立時可另外填規格說明與備註，其餘欄位（圖號、版次、齒輪規格、重量…）請建立後到「主檔管理」補齊。</li>
            <li><b>同料號＋同客戶的主檔已經存在時不會重複建立</b>，會直接沿用那一筆並完成綁定（畫面上會寫明是新建還是沿用）。</li>
            <li>綁定與建立都會留下稽核紀錄（誰、什麼時候、依哪條規則）；已經被別人綁走的一律擋下不覆蓋。</li>
        </ul>
        <?php endif; ?>
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

<?php if ($ocq_can_bind_part || $ocq_can_bind_order): ?>
<!-- 快速綁定（料號／訂單）共用跳窗：兩種模式共用同一層外框，內容由 JS 依模式填入 -->
<div class="ocq-mask" id="ocqBindMask" style="display:none;position:fixed;inset:0;background:rgba(60,40,20,.45);z-index:1060;">
    <div class="ocq-bind-box">
        <div class="ocq-bind-hd"><span id="ocqBindTitle"><i class="fa fa-link"></i> 快速綁定</span>
            <span style="cursor:pointer;color:#b5762a;" id="ocqBindClose">✕</span></div>
        <div class="ocq-bind-bd" id="ocqBindBody"></div>
        <div class="ocq-bind-ft">
            <span id="ocqBindMsg" style="float:left;font-size:12.5px;color:#a0521f;line-height:30px;"></span>
            <button type="button" id="ocqBindCancel" style="height:30px;padding:0 14px;border-radius:4px;font-size:13px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;cursor:pointer;">取消</button>
            <button type="button" id="ocqBindApply" style="height:30px;padding:0 16px;margin-left:6px;border-radius:4px;font-size:13px;border:1px solid #d98a33;background:#F0A24B;color:#fff;cursor:pointer;">確定綁定</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($ocq_can_bind_part || $ocq_can_bind_order): ?>
<!-- 自動綁定料號：先試算（不寫入）→ 可自動綁的整批執行；指不出唯一料號的逐筆人工判定 -->
<div class="ocq-mask" id="ocqAbMask" style="display:none;position:fixed;inset:0;background:rgba(60,40,20,.45);z-index:1060;">
    <div class="ocq-ab-box">
        <div class="ocq-bind-hd"><span><i class="fa fa-magic"></i> 自動綁定</span>
            <span style="cursor:pointer;color:#b5762a;" id="ocqAbClose">✕</span></div>
        <div class="ocq-bind-bd" id="ocqAbBody" style="max-height:70vh;">
            <!-- 三個階段是一條鏈：料號綁好才挑得到訂單，訂單綁好才挑得到報價單 -->
            <div class="ocq-ab-stages">
                <?php if ($ocq_can_bind_part): ?>
                <button type="button" class="ocq-ab-stage active" data-stage="part">① 綁料號</button>
                <?php endif; ?>
                <?php if ($ocq_can_bind_order): ?>
                <button type="button" class="ocq-ab-stage<?= $ocq_can_bind_part ? '' : ' active' ?>" data-stage="order">② 綁訂單</button>
                <button type="button" class="ocq-ab-stage" data-stage="quote">③ 綁報價單</button>
                <?php endif; ?>
            </div>
            <?php if ($ocq_can_bind_part): ?>
            <div id="ocqStagePart">
            <div class="ocq-bind-meta">
                判定方式：拿 BOM 上的<b>料號文字</b>去料號主檔比對，<b>指得出唯一一筆才會自動綁</b>；
                同一個料號文字在主檔常有好幾筆、分屬不同客戶，這種一律留給你逐筆判定，系統不會替你猜。
                <div style="margin-top:4px;">範圍＝<b>目前的篩選條件</b>（含日期區間）底下還沒綁料號的已完工 BOM。要處理全部歷史資料，請先按年份列的「全部年份」再開這個視窗。</div>
            </div>
            <div id="ocqAbScan" style="text-align:center;padding:18px 0;color:#a0521f;">
                <i class="fa fa-spinner fa-spin"></i> 正在比對料號主檔…
            </div>
            <div id="ocqAbResult" style="display:none;">
                <div class="ocq-ab-cards">
                    <div class="ocq-ab-card is-auto"><div class="n" id="ocqAbNAuto">0</div><div class="t">可自動綁定</div>
                        <div class="d">料號主檔指得出唯一一筆</div></div>
                    <div class="ocq-ab-card is-manual"><div class="n" id="ocqAbNManual">0</div><div class="t">需人工判定</div>
                        <div class="d">同名主檔多筆／客戶對不上</div></div>
                    <div class="ocq-ab-card is-none"><div class="n" id="ocqAbNNomatch">0</div><div class="t">主檔查無</div>
                        <div class="d">要先到主檔建立這個料號</div></div>
                </div>
                <div class="ocq-ab-tabs">
                    <button type="button" class="ocq-ab-tab active" data-tab="auto">可自動綁定</button>
                    <button type="button" class="ocq-ab-tab" data-tab="manual">需人工判定</button>
                    <button type="button" class="ocq-ab-tab" data-tab="nomatch">主檔查無</button>
                </div>
                <div class="ocq-ab-pane" id="ocqAbPaneAuto"></div>
                <div class="ocq-ab-pane" id="ocqAbPaneManual" data-card="#ocqAbNManual" style="display:none;"></div>
                <div class="ocq-ab-pane" id="ocqAbPaneNomatch" data-card="#ocqAbNNomatch" style="display:none;"></div>
            </div>
            </div><!-- /#ocqStagePart -->
            <?php endif; ?>

            <?php if ($ocq_can_bind_order): ?>
            <div id="ocqStageOrder"<?= $ocq_can_bind_part ? ' style="display:none;"' : '' ?>>
                <div class="ocq-bind-meta">
                    範圍＝目前篩選條件內<b>已經綁好料號、但還沒綁訂單</b>的已完工 BOM（備庫不算，它本來就沒有訂單）。
                    候選訂單＝<b>同一筆料號主檔底下、未作廢的訂單</b>；<b>只有一張時才自動綁</b>，好幾張一律列出來讓你挑。
                    <div style="margin-top:4px;">「分配量」＝這筆 BOM 算在該訂單頭上的數量，預設帶入「BOM 發單量」與「該訂單未被分配量」中較小者，挑的時候可以改。</div>
                </div>
                <div class="ocq-sx-scan" style="text-align:center;padding:18px 0;color:#a0521f;">
                    <i class="fa fa-spinner fa-spin"></i> 正在比對訂單…
                </div>
                <div class="ocq-sx-result" style="display:none;">
                    <div class="ocq-ab-cards">
                        <div class="ocq-ab-card is-auto"><div class="n" id="ocqObNAuto">0</div><div class="t">可自動綁定</div>
                            <div class="d">這個料號底下只有一張訂單</div></div>
                        <div class="ocq-ab-card is-manual"><div class="n" id="ocqObNManual">0</div><div class="t">需人工判定</div>
                            <div class="d">同料號有好幾張訂單</div></div>
                        <div class="ocq-ab-card is-none"><div class="n" id="ocqObNNomatch">0</div><div class="t">查無訂單</div>
                            <div class="d">這個料號底下沒有訂單</div></div>
                    </div>
                    <div class="ocq-ab-tabs">
                        <button type="button" class="ocq-ab-tab active" data-stage="order" data-tab="auto">可自動綁定</button>
                        <button type="button" class="ocq-ab-tab" data-stage="order" data-tab="manual">需人工判定</button>
                        <button type="button" class="ocq-ab-tab" data-stage="order" data-tab="nomatch">查無訂單</button>
                    </div>
                    <div class="ocq-ab-pane" id="ocqObPaneAuto"></div>
                    <div class="ocq-ab-pane" id="ocqObPaneManual" data-card="#ocqObNManual" style="display:none;"></div>
                    <div class="ocq-ab-pane" id="ocqObPaneNomatch" data-card="#ocqObNNomatch" style="display:none;"></div>
                </div>
            </div>

            <div id="ocqStageQuote" style="display:none;">
                <div class="ocq-bind-meta">
                    報價單是綁在<b>訂單</b>上的（不是綁在 BOM 上），所以這一頁處理的是
                    「目前篩選範圍內的 BOM 所綁到的訂單裡，<b>還沒綁報價單</b>的那幾張」。
                    <div style="margin-top:4px;">候選＝<b>報價明細的料號與訂單料號完全相同</b>的報價單（草稿與待審核的報價單不列入）；
                        只有一筆時才自動綁，好幾筆時依「報價日不晚於訂單日、且日期最接近」排序讓你挑。</div>
                </div>
                <div class="ocq-sx-scan" style="text-align:center;padding:18px 0;color:#a0521f;">
                    <i class="fa fa-spinner fa-spin"></i> 正在比對報價單…
                </div>
                <div class="ocq-sx-result" style="display:none;">
                    <div class="ocq-ab-cards">
                        <div class="ocq-ab-card is-auto"><div class="n" id="ocqQbNAuto">0</div><div class="t">可自動綁定</div>
                            <div class="d">這個料號只有一筆報價明細</div></div>
                        <div class="ocq-ab-card is-manual"><div class="n" id="ocqQbNManual">0</div><div class="t">需人工判定</div>
                            <div class="d">同料號有好幾筆報價</div></div>
                        <div class="ocq-ab-card is-none"><div class="n" id="ocqQbNNomatch">0</div><div class="t">查無報價</div>
                            <div class="d">報價單裡沒有這個料號</div></div>
                    </div>
                    <div class="ocq-ab-tabs">
                        <button type="button" class="ocq-ab-tab active" data-stage="quote" data-tab="auto">可自動綁定</button>
                        <button type="button" class="ocq-ab-tab" data-stage="quote" data-tab="manual">需人工判定</button>
                        <button type="button" class="ocq-ab-tab" data-stage="quote" data-tab="nomatch">查無報價</button>
                    </div>
                    <div class="ocq-ab-pane" id="ocqQbPaneAuto"></div>
                    <div class="ocq-ab-pane" id="ocqQbPaneManual" data-card="#ocqQbNManual" style="display:none;"></div>
                    <div class="ocq-ab-pane" id="ocqQbPaneNomatch" data-card="#ocqQbNNomatch" style="display:none;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="ocq-bind-ft">
            <span id="ocqAbMsg" style="float:left;font-size:12.5px;color:#a0521f;line-height:30px;"></span>
            <button type="button" id="ocqAbCancel" style="height:30px;padding:0 14px;border-radius:4px;font-size:13px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;cursor:pointer;">關閉</button>
            <?php if ($ocq_can_bind_part): ?>
            <button type="button" id="ocqAbRun" style="height:30px;padding:0 16px;margin-left:6px;border-radius:4px;font-size:13px;border:1px solid #d98a33;background:#F0A24B;color:#fff;cursor:pointer;">開始自動綁定料號</button>
            <?php endif; ?>
            <?php if ($ocq_can_bind_order): ?>
            <button type="button" id="ocqObRun" style="display:none;height:30px;padding:0 16px;margin-left:6px;border-radius:4px;font-size:13px;border:1px solid #d98a33;background:#F0A24B;color:#fff;cursor:pointer;">開始自動綁定訂單</button>
            <button type="button" id="ocqQbRun" style="display:none;height:30px;padding:0 16px;margin-left:6px;border-radius:4px;font-size:13px;border:1px solid #d98a33;background:#F0A24B;color:#fff;cursor:pointer;">開始自動綁定報價單</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 快速建立料號主檔並綁定：主檔查無、或同名主檔客戶對不上時用；可一次處理勾選的多筆 -->
<div class="ocq-mask" id="ocqNpMask" style="display:none;position:fixed;inset:0;background:rgba(60,40,20,.5);z-index:1070;">
    <div class="ocq-np-box">
        <div class="ocq-bind-hd"><span><i class="fa fa-plus-square-o"></i> 建立料號主檔並綁定</span>
            <span style="cursor:pointer;color:#b5762a;" id="ocqNpClose">✕</span></div>
        <div class="ocq-bind-bd" id="ocqNpBody" style="max-height:66vh;">
            <div class="ocq-bind-meta">
                以 BOM 上的<b>料號文字</b>在料號主檔建立一筆新料號並<b>立刻綁定</b>這張 BOM。
                <div style="margin-top:4px;"><b>客戶一定要從主檔挑</b>（不給自由輸入）；系統會先用 BOM 的客戶名稱自動對應，
                    對到的會標成綠色，請確認過再建立。<b>同料號＋同客戶的主檔已經存在時不會重複建立</b>，會直接沿用那一筆。</div>
            </div>
            <table class="ocq-ab-tb ocq-np-tb" id="ocqNpTb">
                <thead><tr><th style="width:130px;">BOM</th><th style="width:230px;">料號（可修改）</th>
                    <th style="width:110px;">BOM 客戶</th><th>建立到客戶</th><th style="width:150px;">狀態</th></tr></thead>
                <tbody></tbody>
            </table>
            <div id="ocqNpOne" style="margin-top:8px;display:none;">
                <div style="display:flex;gap:10px;align-items:center;">
                    <label style="font-size:12.5px;color:#5b3a1e;white-space:nowrap;margin:0;">規格說明</label>
                    <input type="text" id="ocqNpSpec" maxlength="50" style="flex:1;height:26px;font-size:12.5px;padding:0 6px;border:1px solid #D8BE93;border-radius:3px;">
                    <label style="font-size:12.5px;color:#5b3a1e;white-space:nowrap;margin:0;">備註</label>
                    <input type="text" id="ocqNpRemark" maxlength="200" style="flex:1;height:26px;font-size:12.5px;padding:0 6px;border:1px solid #D8BE93;border-radius:3px;">
                </div>
                <div class="ocq-ab-sub" style="margin-top:3px;">其餘欄位（圖號、版次、齒輪規格、重量…）請建立後到「主檔管理」補齊。</div>
            </div>
        </div>
        <div class="ocq-bind-ft">
            <span id="ocqNpMsg" style="float:left;font-size:12.5px;color:#a0521f;line-height:30px;"></span>
            <button type="button" id="ocqNpCancel" style="height:30px;padding:0 14px;border-radius:4px;font-size:13px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;cursor:pointer;">取消</button>
            <button type="button" id="ocqNpRun" style="height:30px;padding:0 16px;margin-left:6px;border-radius:4px;font-size:13px;border:1px solid #d98a33;background:#F0A24B;color:#fff;cursor:pointer;">建立並綁定</button>
        </div>
    </div>
</div>
<?php endif; ?>

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

// 快速綁定的權限由後端算好帶進來（同一份判定後端每個寫入動作還會再擋一次＝鐵律8）。
// 兩種權限都沒有的人，「綁定」這一欄整欄不會長出來，畫面與改版前完全相同。
var OCQ_BIND = { part: <?= $ocq_can_bind_part ? 'true' : 'false' ?>, order: <?= $ocq_can_bind_order ? 'true' : 'false' ?>,
                 csrf: <?= json_encode($ocq_csrf) ?> };
var OCQ_CAN_BIND  = (OCQ_BIND.part || OCQ_BIND.order);
var OCQ_BASE_COLS = OCQ_CAN_BIND ? 7 : 6;

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
        bind: $('#fBind').val() || '',
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
    $tr.append('<th>客戶</th><th class="t-left">BOM</th><th class="t-left">料號</th>');
    if (OCQ_CAN_BIND) $tr.append('<th title="料號／訂單的綁定狀態，未綁定者可直接在這裡快速綁定">綁定</th>');
    $tr.append('<th>數量</th><th>交期</th><th>業務</th>');
    for (var i=1; i<=maxProc; i++) $tr.append('<th>製程'+i+'</th>');
    return OCQ_BASE_COLS + maxProc;
}

// 「綁定」欄的內容：已綁定就只顯示狀態，未綁定且有權限才長出按鈕。
// 備庫（bom.o_order_id='B'）比照 BOM總表，**不視為未綁定、也不給綁訂單的按鈕**。
function bindCellHtml(item){
    if (!OCQ_CAN_BIND) return '';
    var hasPart  = !!(parseInt(item.d_setting_id, 10) || 0);
    var orders   = item.bound_orders || [];
    var hasOrder = (orders.length > 0) || !!item.legacy_order || !!item.is_stock;
    var h = '';

    if (hasPart) {
        h += '<div class="ocq-bind-ok" title="已綁定料號主檔"><i class="fa fa-check"></i> 料號</div>';
    } else if (OCQ_BIND.part) {
        h += '<div><button type="button" class="ocq-bind-btn ocq-bind-part" data-bom="'+esc(item.bom)+'"'
           + ' title="搜尋料號主檔並綁定到這筆 BOM"><i class="fa fa-search"></i> 綁料號</button></div>';
    } else {
        h += '<div class="ocq-bind-no" title="未綁定料號（你沒有綁定料號的權限）">未綁料號</div>';
    }

    if (item.is_stock) {
        h += '<div class="ocq-bind-ok" title="備庫（bom.o_order_id = B），本來就沒有對應訂單"><i class="fa fa-archive"></i> 備庫</div>';
    } else if (hasOrder) {
        // 多張訂單一律上下分行（使用者要求）——串成一行時訂單編號會連在一起，根本分不出是幾張單
        var lines = orders.length
            ? orders.map(function(o){ return o.order_oo + (o.qty > 0 ? '×' + o.qty : ''); })
            : [item.legacy_order];
        h += '<div class="ocq-bind-ok" title="已綁定訂單：'+esc(lines.join('、'))+'"><i class="fa fa-chain"></i> '
           + lines.map(function(t, i){ return (i === 0 ? '' : '<div class="ocq-bind-more">') + esc(t) + (i === 0 ? '' : '</div>'); }).join('')
           + '</div>';
    } else if (OCQ_BIND.order) {
        var needPart = !hasPart;
        h += '<div><button type="button" class="ocq-bind-btn ocq-bind-order'+(needPart?' is-wait':'')+'" data-bom="'+esc(item.bom)+'"'
           + ' title="'+(needPart ? '要先綁定料號才挑得到訂單（候選訂單是依料號找出來的）' : '挑選這個料號底下的訂單並綁定')+'">'
           + '<i class="fa fa-chain-broken"></i> 綁訂單</button></div>';
    } else {
        h += '<div class="ocq-bind-no" title="未綁定訂單（你沒有綁定訂單的權限）">未綁訂單</div>';
    }
    return h;
}

// 交期欄：`bom.Delivery_date`（手動交期）優先，沒有就用綁定訂單的交期（判定在後端 ocq_attach_delivery，
// 這裡只負責畫）。已結案的 BOM 有 97.8% 沒有手動交期，所以只看那一欄整欄都會是空白。
// 一筆 BOM 綁到多張交期不同的訂單時上下分行，不要串成一行。
function delivCellHtml(item){
    var list = item.deliv_list || [];
    if (!list.length) return '';
    return list.map(function(d){
        var t = esc(egFmtDate(d.date));
        if (item.deliv_src === 'order') {
            t = '<span title="取自綁定訂單 ' + esc(d.order_oo || '') + ' 的交期（本筆 BOM 沒有填手動交期）" '
              + 'style="border-bottom:1px dotted #D8BE93;">' + t + '</span>';
        }
        return '<div>' + t + '</div>';
    }).join('');
}

function rowToTr(item, maxProc, priceMap){
    var cc = item.priority_type==='E' ? 'circle_red' : (item.priority_type==='U' ? 'circle_y' : 'circle_green');
    var closedInfo = '<div class="ocq-sub">' + (item.closed_by_name ? '結：'+esc(item.closed_by_name)+'　' : '')
        + (item.date_is_derived == 1 || item.date_is_derived === true
            ? '<span title="無結案時間紀錄，依BOM編號推算">'+esc(egFmtDate(item.effective_date))+'(推算)</span>'
            : esc(egFmtDate(item.closed_at, true)))
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
    var bindTd = OCQ_CAN_BIND ? '<td class="ocq-bind-td" data-bom="'+esc(item.bom)+'">'+bindCellHtml(item)+'</td>' : '';
    var tds = custTd + bomTd + didTd + bindTd
        + '<td>'+esc(item.Qty||'')+'</td>'
        + '<td class="ocq-nowrap">'+delivCellHtml(item)+'</td>'
        + '<td>'+esc(item.sales_name||'')+'</td>';
    var procs = item.processes || [];
    for (var i=0; i<maxProc; i++){
        var p = procs[i];
        if (!p) { tds += '<td></td>'; continue; }
        var pi = bomPrices[String(p.bom_sn)] || null;
        var pv = pi ? (parseFloat(pi.modified_unit_price) || parseFloat(pi.price) || 0) : 0;
        var cell = '<div>'+esc((p.process_no||'')+(p.ProcessName?' '+p.ProcessName:''))+'</div>';
        if (p.outsource_date || p.maker_id) cell += '<small style="color:#888;">'+esc((p.outsource_date?egFmtDate(p.outsource_date):'')+(p.maker_id?' '+p.maker_id:''))+'</small>';
        // 回廠日依使用者指定寫成「日期 回」（日期在前），不是「回廠:日期」
        if (p.return_date) cell += '<div style="color:#2a7ae2;font-weight:bold;">'+esc(egFmtDate(p.return_date))+' 回</div>';
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
    $('#ocqTbody').html('<tr><td colspan="'+OCQ_BASE_COLS+'" class="ocq-empty"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    $.post('', f, function(res){
        if (seq !== _listSeq) return;             // 已經有更新的一次查詢在跑，這份丟掉
        if (!res.success){ $('#ocqTbody').html('<tr><td colspan="'+OCQ_BASE_COLS+'" class="ocq-empty">' + esc(res.message||'查詢失敗') + '</td></tr>'); return; }
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
['#fDateFrom','#fDateTo','#fPriority','#fBind'].forEach(function(sel){ $(sel).on('change', applyFilters); });
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

/* ══════════════ 快速綁定：料號／訂單 ══════════════════════════════════════════════════
   兩件事在這裡是共用的：
   ① **點開即刷新**（ai-rules/08 第六節）：按下按鈕的當下先向後端要一次這筆 BOM 的最新狀態，
      不用畫面上那份可能已經過期的快取；若別人剛綁走了就擋下、提示、並把那一列就地更新。
   ② 送出後**只更新那一列**，不重新查整份清單——重查會讓使用者好不容易捲到的位置整個跳掉，
      連續補好幾筆的時候特別明顯。
   後端對這四個動作都有同一套權限判定＋兩個寫入動作的 CSRF（鐵律8），前端這裡擋的只是體感。 */
var ocqBind = { mode: null, bom: null, state: null, orders: [], chosen: 0, busy: false };

function ocqBindOpen(title){
    $('#ocqBindTitle').html(title);
    $('#ocqBindMsg').text('');
    $('#ocqBindApply').prop('disabled', false).show();
    $('#ocqBindMask').show();
}
function ocqBindClose(){ $('#ocqBindMask').hide(); ocqBind.mode = null; }
$('#ocqBindClose, #ocqBindCancel').on('click', ocqBindClose);
$('#ocqBindMask').on('click', function(e){ if (e.target === this) ocqBindClose(); });

function ocqBindErr(msg){ $('#ocqBindMsg').html('<span class="ocq-bind-err">' + esc(msg) + '</span>'); }

// 就地更新某一列的「綁定」欄（不重查整份清單）
function ocqBindRefreshRow(bom, patch){
    var $td = $('#ocqTbody .ocq-bind-td').filter(function(){ return $(this).data('bom') === bom; });
    if (!$td.length) return;
    var $row = $td.closest('tr');
    var item = $.extend({ bom: bom }, patch);
    $td.html(bindCellHtml(item));
    if (patch.d_id != null) {
        // 料號欄也要跟著長出來，否則畫面上會變成「綁定欄說有料號、料號欄卻空白」
        var $did = $row.find('td.ocq-fillable[data-field="bom"]').last();
        if ($did.length) {
            $did.attr('data-val', encodeURIComponent(patch.d_id || ''));
            $did.find('.ocq-part-link').remove();
            $did.prepend('<span class="ocq-part-link" data-part="'+encodeURIComponent(patch.d_id)+'" data-pk="'+(parseInt(patch.d_setting_id,10)||0)+'" title="點擊開啟圖面查閱">'+esc(patch.d_id)+'</span>');
        }
    }
    if (patch.client_name) $row.find('td.ocq-fillable[data-field="customer"]').text(patch.client_name)
        .attr('data-val', encodeURIComponent(patch.client_name));
}

// 先查最新狀態，再決定開哪個跳窗（或擋下）
function ocqBindStart(bom, mode){
    if (ocqBind.busy) return;
    ocqBind.busy = true;
    $.post('', { action: 'bind_get_state', bom: bom, want_orders: (mode === 'order' ? 1 : 0) }, function(res){
        ocqBind.busy = false;
        if (!res || !res.success) { alert((res && res.message) || '無法取得這筆 BOM 的最新狀態'); return; }
        var st = res.state;
        // 點開即刷新：畫面上看到「未綁定」但後端其實已經綁好了 → 不開窗，就地更新那一列
        if (mode === 'part' && st.has_part) {
            ocqBindRefreshRow(bom, { d_setting_id: st.d_setting_id, d_id: st.d_id, bound_orders: st.bound_orders,
                                     is_stock: st.is_stock, legacy_order: st.legacy_order });
            alert('這筆 BOM 已經綁定料號「' + (st.d_id || st.part_no) + '」了（可能是其他人剛綁的），畫面已更新為最新狀態。');
            return;
        }
        if (mode === 'order' && st.has_order) {
            ocqBindRefreshRow(bom, { d_setting_id: st.d_setting_id, d_id: null, bound_orders: st.bound_orders,
                                     is_stock: st.is_stock, legacy_order: st.legacy_order });
            alert('這筆 BOM 已經綁定訂單了（可能是其他人剛綁的），畫面已更新為最新狀態。');
            return;
        }
        if (mode === 'order' && !st.has_part) {
            alert('這筆 BOM 還沒有綁定料號。\n候選訂單是依「料號」找出來的，請先用「綁料號」綁好料號，再回來挑訂單。');
            return;
        }
        ocqBind.bom = bom;
        ocqBind.state = st;
        ocqBind.mode = mode;
        if (mode === 'part') ocqBindOpenPart(st);
        else ocqBindOpenOrder(st, res.orders || []);
    }, 'json').fail(function(){ ocqBind.busy = false; alert('與伺服器連線失敗，請稍後再試'); });
}

function ocqBindMetaHtml(st){
    return '<div class="ocq-bind-meta">BOM <b>' + esc(st.bom) + '</b>'
        + '　發單數量 <b>' + esc(st.sqty || '') + '</b>'
        + '　客戶 <b>' + esc(st.Client_Name || '') + '</b>'
        + (st.d_id ? '　料號 <b>' + esc(st.d_id) + '</b>' : '')
        + '</div>';
}

/* ── 模式一：快速綁定料號 ─────────────────────────────────────────────────────── */
function ocqBindOpenPart(st){
    ocqBindOpen('<i class="fa fa-search"></i> 快速綁定料號');
    $('#ocqBindApply').hide();   // 這個模式是每一列各自一顆「套用」，底下不需要總確認鈕
    $('#ocqBindBody').html(ocqBindMetaHtml(st)
        + '<div style="display:flex;gap:6px;margin-bottom:8px;">'
        + '<input type="text" id="ocqPartTerm" placeholder="輸入料號／圖號／規格搜尋…" style="flex:1;height:30px;padding:0 8px;border:1px solid #D8BE93;border-radius:4px;">'
        + '<button type="button" id="ocqPartSearch" style="height:30px;padding:0 14px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;"><i class="fa fa-search"></i> 搜尋</button></div>'
        + '<div class="ocq-pick-wrap" id="ocqPartResult"><div style="padding:14px;color:#8a6d45;">請輸入關鍵字後按搜尋</div></div>'
        + '<div style="font-size:12px;color:#8a6d45;margin-top:6px;line-height:1.7;">'
        + '綁定後會一併把 BOM 的料號文字與客戶名稱改成料號主檔上的值（主檔沒有綁客戶時保留原客戶名稱不動）。'
        + '<br>綠色勾表示該料號主檔的客戶與這筆 BOM 的客戶對得起來，只是提示、不影響能不能綁。</div>');
    var term = st.d_id || '';
    $('#ocqPartTerm').val(term).focus();
    $('#ocqPartSearch').on('click', ocqBindSearchPart);
    $('#ocqPartTerm').on('keydown', function(e){ if (e.which === 13) { e.preventDefault(); ocqBindSearchPart(); } });
    if (term) ocqBindSearchPart();
}

function ocqBindSearchPart(){
    var term = $.trim($('#ocqPartTerm').val());
    var $box = $('#ocqPartResult');
    if (!term) { $box.html('<div style="padding:14px;color:#8a6d45;">請輸入關鍵字後按搜尋</div>'); return; }
    $box.html('<div style="padding:14px;color:#8a6d45;"><i class="fa fa-spinner fa-spin"></i> 搜尋中…</div>');
    $.post('', { action: 'bind_search_part', term: term, client: ocqBind.state.Client_Name || '' }, function(res){
        if (!res || !res.success) { $box.html('<div style="padding:14px;color:#DD5138;">' + esc((res&&res.message)||'搜尋失敗') + '</div>'); return; }
        if (!res.results.length) { $box.html('<div style="padding:14px;color:#DD5138;">查無料號「' + esc(term) + '」</div>'); return; }
        var h = '<table class="ocq-pick"><thead><tr><th>料號</th><th>圖號</th><th>規格</th><th>客戶</th><th></th></tr></thead><tbody>';
        res.results.forEach(function(r){
            h += '<tr class="' + (r.exact_match ? 'hit' : '') + '">'
               + '<td class="tl"><b>' + esc(r.display_id) + '</b></td>'
               + '<td class="tl">' + esc(r.drawing_no || '') + '</td>'
               + '<td class="tl">' + esc(r.spec_no || '') + '</td>'
               + '<td class="tl">' + esc(r.customer_name || '') + (r.client_match ? ' <span style="color:#2f7a3f;">✓</span>' : '') + '</td>'
               + '<td><button type="button" class="ocq-bind-btn ocq-part-apply" data-did="' + esc(r.d_id) + '" data-no="' + esc(r.display_id) + '">套用</button></td></tr>';
        });
        $box.html(h + '</tbody></table>');
    }, 'json').fail(function(){ $box.html('<div style="padding:14px;color:#DD5138;">與伺服器連線失敗</div>'); });
}

$('#ocqBindMask').on('click', '.ocq-part-apply', function(){
    var $b = $(this), did = $b.data('did'), no = $b.data('no'), bom = ocqBind.bom;
    if (!confirm('確定把 BOM ' + bom + ' 綁定到料號「' + no + '」？')) return;
    $b.prop('disabled', true).text('綁定中…');
    $.post('', { action: 'bind_apply_part', bom: bom, d_setting_id: did, csrf: OCQ_BIND.csrf }, function(res){
        if (!res || !res.success) {
            $b.prop('disabled', false).text('套用');
            ocqBindErr((res && res.message) || '綁定失敗');
            if (res && res.code === 'CONFLICT') { ocqBindClose(); loadList(curPage); }
            return;
        }
        ocqBindRefreshRow(bom, { d_setting_id: res.d_setting_id, d_id: res.d_id, client_name: res.client_name,
                                 bound_orders: [], is_stock: false, legacy_order: null });
        ocqBindClose();
    }, 'json').fail(function(){ $b.prop('disabled', false).text('套用'); ocqBindErr('與伺服器連線失敗'); });
});

/* ── 模式二：快速綁定訂單 ─────────────────────────────────────────────────────── */
function ocqBindOpenOrder(st, orders){
    ocqBind.orders = orders;
    ocqBindOpen('<i class="fa fa-link"></i> 快速綁定訂單');
    $('#ocqBindBody').html(ocqBindMetaHtml(st)
        + '<div style="display:flex;gap:6px;align-items:center;margin-bottom:8px;">'
        + '<input type="text" id="ocqOrderQ" placeholder="訂單編號／規格／備註關鍵字…" style="flex:1;height:30px;padding:0 8px;border:1px solid #D8BE93;border-radius:4px;">'
        + '<button type="button" id="ocqOrderSearch" style="height:30px;padding:0 14px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;border-radius:4px;cursor:pointer;"><i class="fa fa-search"></i> 篩選</button>'
        + '<span style="font-size:12px;color:#8a6d45;">已勾選 <b id="ocqOrderCnt">0</b> 張<span id="ocqOrderSum"></span></span></div>'
        + '<div class="ocq-pick-wrap" id="ocqOrderList"></div>'
        + '<div style="font-size:12px;color:#8a6d45;margin-top:6px;line-height:1.7;">'
        + '只會列出<b>這個料號底下</b>的訂單（後端送出時會再驗一次，不屬於本料號的一律擋下）。'
        + '<br>候選依「訂單日期與這筆 BOM 的日期最接近」取前 100 張——同一料號常有上千張訂單，照日期由新到舊取會把該綁的那幾張整批切掉。'
        + '<br>「分配量」＝這筆 BOM 算在該訂單頭上的數量，預設帶入「BOM 發單量」與「該訂單未被分配量」中較小者，可自行修改。</div>');
    ocqBindRenderOrders();
    $('#ocqOrderSearch').on('click', ocqBindReloadOrders);
    $('#ocqOrderQ').on('keydown', function(e){ if (e.which === 13) { e.preventDefault(); ocqBindReloadOrders(); } });
}

function ocqBindReloadOrders(){
    var $box = $('#ocqOrderList');
    $box.html('<div style="padding:14px;color:#8a6d45;"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>');
    $.post('', { action: 'bind_get_state', bom: ocqBind.bom, want_orders: 1, q: $.trim($('#ocqOrderQ').val()) }, function(res){
        if (!res || !res.success) { $box.html('<div style="padding:14px;color:#DD5138;">' + esc((res&&res.message)||'載入失敗') + '</div>'); return; }
        ocqBind.orders = res.orders || [];
        ocqBindRenderOrders();
    }, 'json').fail(function(){ $box.html('<div style="padding:14px;color:#DD5138;">與伺服器連線失敗</div>'); });
}

function ocqBindRenderOrders(){
    var $box = $('#ocqOrderList'), list = ocqBind.orders || [];
    if (!list.length) {
        $box.html('<div style="padding:14px;color:#DD5138;">這個料號底下找不到可綁定的訂單（已作廢的訂單不列出）。</div>');
        $('#ocqOrderCnt').text('0');
        return;
    }
    var bomQty = parseInt(ocqBind.state.sqty, 10) || 0;
    var h = '<table class="ocq-pick"><thead><tr><th style="width:34px;"></th><th>訂單編號</th><th>訂單日</th><th>交期</th>'
          + '<th>訂單量</th><th>已分配</th><th>分配量</th><th>規格／備註</th></tr></thead><tbody>';
    list.forEach(function(o){
        var qty = parseInt(o.Qty, 10) || 0, used = parseInt(o.already_allocated, 10) || 0;
        var left = Math.max(0, qty - used);
        // 規則與後端 ocq_ob_qty_default() 一致：已經被別的 BOM 分配滿就預設 0，不預先幫人再超配一次
        var pre  = (left <= 0) ? 0 : (bomQty > 0 ? Math.min(bomQty, left) : left);
        h += '<tr data-oid="' + o.Order_id + '"' + (left <= 0 ? ' class="ord-full"' : '') + '>'
           + '<td><input type="checkbox" class="ocq-ock" data-oid="' + o.Order_id + '"></td>'
           + '<td class="tl"><b>' + esc(o.Order_oo || ('#'+o.Order_id)) + '</b>'
           + (left <= 0 ? ' <b class="ocq-cand-full">⚠ 已分配滿</b>' : '') + '</td>'
           + '<td>' + esc(o.Order_date ? egFmtDate(o.Order_date) : '') + '</td>'
           + '<td>' + esc(o.Delivery_date ? egFmtDate(o.Delivery_date) : '') + '</td>'
           + '<td>' + qty + '</td>'
           + '<td' + (used > 0 ? ' style="color:#a0521f;" title="這張訂單已經被其他 BOM 分配掉的數量"' : '') + '>' + (used || '') + '</td>'
           + '<td><input type="number" class="ocq-pick-qty ocq-oqty" data-oid="' + o.Order_id + '" min="0" value="' + pre + '"></td>'
           + '<td class="tl" style="max-width:220px;white-space:normal;">' + esc([o.Specification, o.Order_ps].filter(Boolean).join('／')) + '</td></tr>';
    });
    $box.html(h + '</tbody></table>');
    ocqBindCountOrders();
}

function ocqBindCountOrders(){
    var n = 0, sum = 0;
    $('#ocqOrderList .ocq-ock:checked').each(function(){
        n++;
        var oid = $(this).data('oid');
        sum += parseInt($('#ocqOrderList .ocq-oqty[data-oid="' + oid + '"]').val(), 10) || 0;
    });
    $('#ocqOrderCnt').text(n);
    // 挑了不只一張才寫合計：分配量預設各自帶「發單量與該訂單未分配量中較小者」，多挑幾張會重複計到
    var bq = parseInt(ocqBind.state && ocqBind.state.sqty, 10) || 0;
    var over = (n > 1 && bq > 0 && sum > bq);
    $('#ocqOrderSum').text(n > 1 ? '，分配量合計 ' + sum + (bq > 0 ? '／發單量 ' + bq : '')
                                   + (over ? '（超過發單量，請確認分配量）' : '') : '')
                     .css('color', over ? '#DD5138' : '');
    $('#ocqOrderList tbody tr').each(function(){
        $(this).toggleClass('hit', $(this).find('.ocq-ock').prop('checked'));
    });
}
$('#ocqBindMask').on('change', '.ocq-ock', ocqBindCountOrders);
$('#ocqBindMask').on('input change', '.ocq-oqty', ocqBindCountOrders);

$('#ocqBindApply').on('click', function(){
    if (ocqBind.mode !== 'order') return;
    var picked = [];
    $('#ocqOrderList .ocq-ock:checked').each(function(){
        var oid = $(this).data('oid');
        picked.push({ order_id: oid, qty: parseInt($('#ocqOrderList .ocq-oqty[data-oid="' + oid + '"]').val(), 10) || 0 });
    });
    if (!picked.length) { ocqBindErr('請至少勾選一張訂單'); return; }
    var $b = $(this).prop('disabled', true).text('綁定中…');
    $.post('', { action: 'bind_apply_order', bom: ocqBind.bom, orders_json: JSON.stringify(picked), csrf: OCQ_BIND.csrf }, function(res){
        $b.prop('disabled', false).text('確定綁定');
        if (!res || !res.success) {
            ocqBindErr((res && res.message) || '綁定失敗');
            if (res && res.code === 'CONFLICT') { ocqBindClose(); loadList(curPage); }
            return;
        }
        ocqBindRefreshRow(ocqBind.bom, { d_setting_id: ocqBind.state.d_setting_id, d_id: null,
            bound_orders: res.orders.map(function(o){ return { order_id: o.order_id, order_oo: o.order_oo, qty: o.qty }; }),
            is_stock: false, legacy_order: null });
        ocqBindClose();
    }, 'json').fail(function(){ $b.prop('disabled', false).text('確定綁定'); ocqBindErr('與伺服器連線失敗'); });
});

// 表格上的兩顆按鈕（事件委派，換頁重繪後一樣有效）
$('#ocqTbody').on('click', '.ocq-bind-part', function(e){ e.stopPropagation(); ocqBindStart($(this).data('bom'), 'part'); });
$('#ocqTbody').on('click', '.ocq-bind-order', function(e){ e.stopPropagation(); ocqBindStart($(this).data('bom'), 'order'); });

// ══ 自動綁定料號 ══════════════════════════════════════════════════════════════════
// 流程：開窗＝先試算（只看不寫）→ 三張統計卡 →「開始自動綁定」分批寫入 →
// 指不出唯一料號的在「需人工判定」分頁逐筆挑選（走既有的 bind_apply_part，規則與手動綁定同一套）。
if (OCQ_BIND.part || OCQ_BIND.order) {
var ocqAb = { scanned: false, autoBoms: [], sample: [], running: false, tab: 'auto',
              page: { manual: 1, nomatch: 1 } };
var ocqStage = OCQ_BIND.part ? 'part' : 'order';   // 目前在哪一個階段（料號／訂單／報價單）

function ocqAbMsg(html, isErr){
    $('#ocqAbMsg').html(isErr ? '<span class="ocq-bind-err">' + html + '</span>' : html);
}
function ocqAbClose(){ $('#ocqAbMask').hide(); }
$('#ocqAbClose, #ocqAbCancel').on('click', function(){
    if (ocqAb.running) { if (!confirm('自動綁定還在進行中，確定要關閉嗎？（已經綁好的不會退回）')) return; }
    ocqAbClose();
});
$('#ocqAbMask').on('click', function(e){ if (e.target === this && !ocqAb.running) ocqAbClose(); });

// 掃描（試算）。開窗要跑、**綁定完也要再跑一次**——數字的真相在後端，不要在前端自己扣：
// 綁定過程中可能有人同時在別的地方綁、也可能有筆數被略過，自己扣會跟資料庫對不起來。
// $keepMsg＝綁定完成後重掃時不要蓋掉「完成：已綁定 N 筆」那句話。
function ocqAbScan(keepMsg){
    $('#ocqAbScan').show();
    $('#ocqAbResult').hide();
    $('#ocqAbRun').prop('disabled', true);
    var f = curFilters();
    f.action = 'autobind_scan';
    $.post('', f, function(res){
        $('#ocqAbScan').hide();
        if (!res || !res.success) { ocqAbMsg((res && res.message) || '掃描失敗', true); return; }
        ocqAb.scanned  = true;
        ocqAb.autoBoms = res.auto_boms || [];
        ocqAb.sample   = res.sample || [];
        var c = res.count || {};
        $('#ocqAbNAuto').text(c.auto || 0);
        $('#ocqAbNManual').text(c.manual || 0);
        // 「連料號文字都沒有」的併進主檔查無那張卡一起顯示，不要讓它憑空消失
        $('#ocqAbNNomatch').text((c.nomatch || 0) + (c.nopart || 0));
        $('#ocqAbResult').show();
        ocqAbRenderAuto();
        $('#ocqAbRun').prop('disabled', !(c.auto > 0));
        // 目前在哪個分頁就重畫哪個分頁（綁完之後「需人工判定」那份清單也可能變了）
        if (ocqAb.tab === 'manual' || ocqAb.tab === 'nomatch') ocqAbLoadList(ocqAb.tab, 1);
        if (!keepMsg) ocqAbMsg('範圍內還沒綁料號的共 <b>' + (res.total || 0) + '</b> 筆');
    }, 'json').fail(function(){ $('#ocqAbScan').hide(); ocqAbMsg('掃描失敗，請重新整理後再試', true); });
}

$('#btnAutoBind').on('click', function(){
    ocqAb = { scanned: false, autoBoms: [], sample: [], running: false, tab: 'auto', page: { manual: 1, nomatch: 1 } };
    if (window.ocqOb) ocqOb.reset();
    if (window.ocqQb) ocqQb.reset();
    $('#ocqAbMask').show();
    ocqAbMsg('');
    ocqStageGo(OCQ_BIND.part ? 'part' : 'order');
});

// 階段切換（①綁料號 ②綁訂單 ③綁報價單）。**每個階段各自掃描、各自的範圍**——
// 料號階段看的是「還沒綁料號」的，訂單階段看的是「已綁料號、還沒綁訂單」的，
// 所以綁完料號切到訂單階段一定要重新掃一次，不可以沿用上一次的結果。
function ocqStageGo(stage){
    ocqStage = stage;
    $('.ocq-ab-stage').removeClass('active').filter('[data-stage="' + stage + '"]').addClass('active');
    $('#ocqStagePart').toggle(stage === 'part');
    $('#ocqStageOrder').toggle(stage === 'order');
    $('#ocqStageQuote').toggle(stage === 'quote');
    ocqAbMsg('');
    $('#ocqAbRun, #ocqObRun, #ocqQbRun').hide();
    if (stage === 'part') {
        if (!ocqAb.scanned) { ocqAbSwitchTab('auto'); ocqAbScan(false); }
        else { ocqAbSwitchTab(ocqAb.tab); }
        return;
    }
    var S = (stage === 'order') ? window.ocqOb : window.ocqQb;
    if (!S) return;
    if (!S.scanned) S.scan(false); else S.switchTab(S.tab);
}
$('.ocq-ab-stage').on('click', function(){ ocqStageGo($(this).data('stage')); });

function ocqAbSwitchTab(tab){
    ocqAb.tab = tab;
    $('.ocq-ab-tab').removeClass('active').filter('[data-tab="' + tab + '"]').addClass('active');
    $('#ocqAbPaneAuto').toggle(tab === 'auto');
    $('#ocqAbPaneManual').toggle(tab === 'manual');
    $('#ocqAbPaneNomatch').toggle(tab === 'nomatch');
    // 「開始自動綁定」只對可自動綁的那批有意義，切到別的分頁就收起來免得誤按
    $('#ocqAbRun').toggle(tab === 'auto');
}
$('.ocq-ab-tab').on('click', function(){
    if (($(this).data('stage') || 'part') !== 'part') return;   // 訂單／報價單階段的分頁各自有處理
    var tab = $(this).data('tab');
    ocqAbSwitchTab(tab);
    if (tab === 'manual' || tab === 'nomatch') ocqAbLoadList(tab, ocqAb.page[tab] || 1);
});

function ocqAbRenderAuto(){
    if (!ocqAb.autoBoms.length) {
        $('#ocqAbPaneAuto').html('<div style="padding:14px;color:#a08a6a;">這個範圍內沒有可以自動綁定的 BOM。</div>');
        return;
    }
    var h = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
          + '以下列出前 ' + ocqAb.sample.length + ' 筆做為抽樣確認，按「開始自動綁定」會處理全部 <b>'
          + ocqAb.autoBoms.length + '</b> 筆。綁定會同時把 BOM 上的料號文字與客戶名稱更新成主檔的值，'
          + '每一筆都會留下稽核紀錄（誰、什麼時候、依哪條規則綁的）。</div>'
          + '<div class="ocq-ab-bar" id="ocqAbBar" style="display:none;"><i></i></div>'
          + '<table class="ocq-ab-tb"><thead><tr><th>BOM</th><th>料號</th><th>BOM 客戶</th>'
          + '<th>將綁定到</th><th>判定依據</th></tr></thead><tbody>';
    ocqAb.sample.forEach(function(r){
        h += '<tr><td>' + esc(r.bom) + '</td><td>' + esc(r.part_no) + '</td>'
           + '<td>' + (r.bom_client ? esc(r.bom_client) : '<span class="ocq-ab-sub">（空白）</span>') + '</td>'
           + '<td>' + esc(r.to_no) + (r.to_client ? ' <span class="ocq-ab-sub">／' + esc(r.to_client) + '</span>' : '') + '</td>'
           + '<td class="ocq-ab-why">' + esc(r.reason) + '</td></tr>';
    });
    h += '</tbody></table>';
    $('#ocqAbPaneAuto').html(h);
}

// 分批送：一次 200 筆，邊跑邊更新進度條。**不要一次把幾千筆丟給後端**——PHP 會逾時，
// 而且逾時的當下使用者完全不知道已經綁了幾筆。
$('#ocqAbRun').on('click', function(){
    if (ocqAb.running || !ocqAb.autoBoms.length) return;
    if (!confirm('即將自動綁定 ' + ocqAb.autoBoms.length + ' 筆 BOM 的料號主檔。\n\n'
               + '系統只會綁「指得出唯一一筆」的；已經有人綁過的會自動略過。\n確定要執行嗎？')) return;

    ocqAb.running = true;
    $('#ocqAbRun').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 綁定中…');
    $('#ocqAbBar').show();

    var todo = ocqAb.autoBoms.slice(), all = todo.length, done = 0, skipped = [];
    function step(){
        if (!todo.length) {
            ocqAb.running = false;
            $('#ocqAbRun').html('<i class="fa fa-magic"></i> 開始自動綁定');
            var msg = '完成：已綁定 <b>' + done + '</b> 筆';
            if (skipped.length) msg += '，略過 ' + skipped.length + ' 筆（多半是其他人已經先綁好了）';
            ocqAbMsg(msg);
            $('#ocqAbBar').hide();
            $('#ocqAbBar > i').css('width', '0');
            loadList(curPage);   // 後方清單同步換成綁好的狀態
            ocqAbScan(true);     // 三張統計卡與抽樣清單也要重新跟後端要一次（keepMsg＝保留上面那句完成訊息）
            return;
        }
        var batch = todo.splice(0, 200);
        $.post('', { action: 'autobind_apply', boms_json: JSON.stringify(batch), csrf: OCQ_BIND.csrf },
        function(res){
            if (!res || !res.success) {
                ocqAb.running = false;
                $('#ocqAbRun').prop('disabled', false).html('<i class="fa fa-magic"></i> 開始自動綁定');
                ocqAbMsg((res && res.message) || '綁定失敗', true);
                return;
            }
            done += (res.done || 0);
            skipped = skipped.concat(res.skipped || []);
            var pct = Math.round((all - todo.length) / all * 100);
            $('#ocqAbBar > i').css('width', pct + '%');
            ocqAbMsg('進行中… 已綁定 <b>' + done + '</b> / ' + all + ' 筆');
            step();
        }, 'json').fail(function(){
            ocqAb.running = false;
            $('#ocqAbRun').prop('disabled', false).html('<i class="fa fa-magic"></i> 開始自動綁定');
            ocqAbMsg('這一批送出失敗，已綁定 ' + done + ' 筆；請重新整理後再執行一次剩下的', true);
        });
    }
    step();
});

function ocqAbLoadList(kind, page){
    ocqAb.page[kind] = page;
    var $pane = (kind === 'manual') ? $('#ocqAbPaneManual') : $('#ocqAbPaneNomatch');
    $pane.html('<div style="padding:14px;color:#a0521f;"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>');
    var f = curFilters();
    f.action = 'autobind_list';
    f.kind = kind;
    f.page = page;
    $.post('', f, function(res){
        if (!res || !res.success) { $pane.html('<div style="padding:14px;" class="ocq-bind-err">載入失敗</div>'); return; }
        $pane.html(kind === 'manual' ? ocqAbManualHtml(res) : ocqAbNomatchHtml(res));
    }, 'json').fail(function(){ $pane.html('<div style="padding:14px;" class="ocq-bind-err">載入失敗</div>'); });
}

function ocqAbCustTag(cmp, name){
    var cls = (cmp === 'same' || cmp === 'like') ? 'cm-same' : (cmp === 'diff' ? 'cm-diff' : 'cm-unknown');
    var txt = name || '（主檔沒有客戶）';
    var mark = (cmp === 'same') ? '✓ ' : (cmp === 'like' ? '≈ ' : (cmp === 'diff' ? '✕ ' : ''));
    return '<span class="' + cls + '">' + mark + esc(txt) + '</span>';
}

function ocqAbManualHtml(res){
    if (!res.total) return '<div style="padding:14px;color:#a08a6a;">沒有需要人工判定的 BOM。</div>';
    var h = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
          + '以下這幾筆系統<b>指不出唯一的料號主檔</b>，挑好候選之後可以<b>逐筆按「套用」，也可以勾選起來一次全部套用</b>。'
          + '候選後方的客戶標示：<span class="cm-same">✓ 完全相同／≈ 名稱相近</span>、'
          + '<span class="cm-diff">✕ 對不上</span>、<span class="cm-unknown">灰字＝其中一邊沒填</span>。'
          + '<br>候選的客戶都對不上（例如主檔只有一筆、但客戶是別家）時，請改用'
          + '<b>「建立新料號」</b>——以這張 BOM 的料號文字與客戶建一筆新的料號主檔再綁定。</div>'
          + ocqAbBulkBar('manual', true)
          + '<table class="ocq-ab-tb"><thead><tr><th class="ck"><input type="checkbox" class="ocq-ab-all" data-kind="manual" title="全選／全不選"></th>'
          + '<th style="width:130px;">BOM</th><th style="width:150px;">料號／客戶</th>'
          + '<th>候選料號主檔（請挑一筆）</th><th style="width:92px;"></th></tr></thead><tbody>';
    (res.rows || []).forEach(function(r){
        h += '<tr data-bom="' + esc(r.bom) + '" data-part="' + esc(r.part_no) + '" data-client="' + esc(r.bom_client || '') + '">'
           + '<td class="ck"><input type="checkbox" class="ocq-ab-ck"></td>'
           + '<td>' + esc(r.bom)
           + '<div class="ocq-ab-sub">' + esc(egFmtDate(r.eff_date)) + '</div></td>'
           + '<td>' + esc(r.part_no)
           + '<div class="ocq-ab-sub">' + (r.bom_client ? esc(r.bom_client) : '（沒有客戶）') + '</div></td><td class="cnd">';
        if (!r.candidates.length) {
            h += '<span class="ocq-ab-sub">主檔查無同名料號</span>';
        } else {
            r.candidates.forEach(function(c){
                h += '<label class="ocq-ab-cand"><input type="radio" name="abp_' + esc(r.bom) + '" value="' + c.d_id + '">'
                   + '<b>' + esc(c.part_no) + '</b> ／ ' + ocqAbCustTag(c.cust_cmp, c.customer_name)
                   + '<span class="ocq-ab-sub">'
                   + (c.drawing_no ? '　圖號 ' + esc(c.drawing_no) : '')
                   + (c.revision ? '　Rev.' + esc(c.revision) : '')
                   + (c.spec_no ? '　規格 ' + esc(c.spec_no) : '')
                   + '　#' + c.d_id + '</span></label>';
            });
        }
        h += '<div class="ocq-ab-why">' + esc(r.reason) + '</div></td>'
           + '<td style="white-space:nowrap;"><button type="button" class="ocq-bind-btn ocq-ab-pick" data-bom="' + esc(r.bom) + '">套用</button>'
           + '<br><button type="button" class="ocq-bind-btn ocq-ab-new" title="以這張 BOM 的料號文字與客戶，在料號主檔建立一筆新料號並立刻綁定">建立新料號</button></td></tr>';
    });
    h += '</tbody></table>' + ocqAbPagerHtml('manual', res);
    return h;
}

/**
 * 勾選工具列（三個階段的清單共用同一條）。
 * $withApply＝有候選可套用的那張才長出「套用勾選的」；$withNew＝只有料號階段才有「建立新料號」。
 */
function ocqAbBulkBar(kind, withApply, stage, applyLabel, hint){
    stage = stage || 'part';
    return '<div class="ocq-ab-bulk" data-kind="' + kind + '" data-stage="' + stage + '">'
         + '<span>已勾選 <b class="ocq-ab-ckn">0</b> 筆</span>'
         + '<span class="ocq-ab-sub ocq-ab-ckhint">' + (hint || '（挑好候選料號會自動幫你勾起來）') + '</span>'
         + '<span class="sp"></span>'
         + (withApply ? '<button type="button" class="ocq-ab-bulk-apply" disabled>' + (applyLabel || '套用勾選的（綁定已挑的主檔）') + '</button>' : '')
         + (stage === 'part' ? '<button type="button" class="sec ocq-ab-bulk-new" disabled>建立新料號並綁定</button>' : '')
         + '</div>';
}

function ocqAbNomatchHtml(res){
    if (!res.total) return '<div style="padding:14px;color:#a08a6a;">沒有這一類的 BOM。</div>';
    var h = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
          + '以下這幾筆的料號在<b>料號主檔裡根本不存在</b>（或這筆 BOM 連料號文字都沒有）。'
          + '可以直接在這裡<b>建立料號主檔並綁定</b>——逐筆按「建立料號」，或勾選起來一次建立；'
          + '客戶會先用 BOM 的客戶名稱自動對應到客戶主檔，建立前可以逐筆確認或改掉。'
          + '<br><b>連料號文字都沒有的那幾筆建不了</b>（沒有料號可建），要到「BOM 總表」補上料號文字，'
          + '其餘欄位（圖號、版次、齒輪規格…）請建立後到「主檔管理」補齊。</div>'
          + ocqAbBulkBar('nomatch', false)
          + '<table class="ocq-ab-tb"><thead><tr><th class="ck"><input type="checkbox" class="ocq-ab-all" data-kind="nomatch" title="全選／全不選（沒有料號文字的建不了，不會被勾起來）"></th>'
          + '<th style="width:130px;">BOM</th><th>料號文字</th>'
          + '<th style="width:120px;">BOM 客戶</th><th style="width:60px;">數量</th><th>說明</th><th style="width:78px;"></th></tr></thead><tbody>';
    (res.rows || []).forEach(function(r){
        var canNew = !!(r.part_no && $.trim(r.part_no) !== '');
        h += '<tr data-bom="' + esc(r.bom) + '" data-part="' + esc(r.part_no || '') + '" data-client="' + esc(r.bom_client || '') + '">'
           + '<td class="ck"><input type="checkbox" class="ocq-ab-ck"' + (canNew ? '' : ' disabled title="這筆 BOM 沒有料號文字，建不了料號"') + '></td>'
           + '<td>' + esc(r.bom) + '<div class="ocq-ab-sub">' + esc(egFmtDate(r.eff_date)) + '</div></td>'
           + '<td>' + (r.part_no ? esc(r.part_no) : '<span class="ocq-ab-sub">（空白）</span>') + '</td>'
           + '<td>' + (r.bom_client ? esc(r.bom_client) : '<span class="ocq-ab-sub">（空白）</span>') + '</td>'
           + '<td>' + esc(r.qty) + '</td>'
           + '<td class="ocq-ab-why cnd">' + esc(r.reason) + '</td>'
           + '<td>' + (canNew ? '<button type="button" class="ocq-bind-btn ocq-ab-new">建立料號</button>'
                              : '<span class="ocq-ab-sub">—</span>') + '</td></tr>';
    });
    h += '</tbody></table>' + ocqAbPagerHtml('nomatch', res);
    return h;
}

function ocqAbPagerHtml(kind, res){
    var pages = Math.max(1, Math.ceil(res.total / res.per));
    if (pages <= 1) return '<div class="ocq-ab-pg">共 ' + res.total + ' 筆</div>';
    return '<div class="ocq-ab-pg">共 ' + res.total + ' 筆，第 ' + res.page + ' / ' + pages + ' 頁'
         + '<button type="button" class="ocq-ab-pgbtn" data-kind="' + kind + '" data-page="' + (res.page - 1) + '"'
         + (res.page <= 1 ? ' disabled' : '') + '>上一頁</button>'
         + '<button type="button" class="ocq-ab-pgbtn" data-kind="' + kind + '" data-page="' + (res.page + 1) + '"'
         + (res.page >= pages ? ' disabled' : '') + '>下一頁</button></div>';
}
$('#ocqAbMask').on('click', '.ocq-ab-pgbtn', function(){
    var stage = $(this).data('stage') || 'part', kind = $(this).data('kind'), page = parseInt($(this).data('page'), 10) || 1;
    if (stage === 'part') { ocqAbLoadList(kind, page); return; }
    var S = (stage === 'order') ? window.ocqOb : window.ocqQb;
    if (S) S.loadList(kind, page);
});

// 綁好的那一列就地標示完成，不整頁重載（使用者通常會一次判定好幾筆；重畫會把清單捲回最上面打斷操作）。
// 逐筆套用、勾選批次套用、快速建立料號三條路都走這一支，三邊各寫一次遲早長得不一樣。
function ocqAbMarkDone($tr, res){
    if (!$tr || !$tr.length) return;
    $tr.addClass('done').removeClass('picked');
    $tr.find('.ocq-ab-rowmsg').remove();
    $tr.find('td.cnd').html('<span class="ocq-bind-ok" style="display:inline-block;"><i class="fa fa-check"></i> 已綁定 '
        + esc(res.d_id || '') + (res.client_name ? '／' + esc(res.client_name) : '') + '</span>');
    $tr.find('.ocq-ab-pick, .ocq-ab-new, .ocq-sx-pick').remove();   // 三個階段的按鈕都要收掉
    $tr.find('.ocq-ab-ck').prop('checked', false).prop('disabled', true);
    if (!$tr.find('.ocq-ab-donetag').length) {
        $tr.find('td').last().append('<span class="ocq-ab-sub ocq-ab-donetag">已完成</span>');
    }
    // 卡片數字減 1（後端剛回報成功，這個扣是準的）
    // 哪一張卡片寫在 pane 的 data-card 上，三個階段（料號／訂單／報價單）共用這一支，
    // 不要在這裡用 id 一個一個比對——新階段的 pane 就是因此扣不到數字（實測抓到）。
    var card = $tr.closest('.ocq-ab-pane').data('card');
    if (card) { var $n = $(card); $n.text(Math.max(0, (parseInt($n.text(), 10) || 0) - 1)); }
    ocqAbCkSync($tr.closest('.ocq-ab-pane'));
}
function ocqAbRowErr($tr, msg){
    if (!$tr || !$tr.length) return;
    $tr.find('.ocq-ab-rowmsg').remove();
    $tr.find('.ocq-ab-ck').prop('checked', false);
    $tr.removeClass('picked').find('td.cnd').append('<div class="ocq-ab-rowmsg">' + esc(msg) + '</div>');
}

// ── 勾選狀態（需人工判定／主檔查無兩張清單共用）──────────────────────────────
function ocqAbCkSync($pane){
    if (!$pane || !$pane.length) return;
    var $ck = $pane.find('.ocq-ab-ck').not(':disabled');
    var n   = $ck.filter(':checked').length;
    $pane.find('.ocq-ab-ckn').text(n);
    $pane.find('.ocq-ab-bulk-apply, .ocq-ab-bulk-new').prop('disabled', n === 0);
    $pane.find('.ocq-ab-all').prop('checked', n > 0 && n === $ck.length);
    $ck.each(function(){ $(this).closest('tr').toggleClass('picked', this.checked); });
}
$('#ocqAbMask').on('change', '.ocq-ab-ck', function(){ ocqAbCkSync($(this).closest('.ocq-ab-pane')); });
$('#ocqAbMask').on('change', '.ocq-ab-all', function(){
    var on = this.checked, $pane = $(this).closest('.ocq-ab-pane');
    $pane.find('.ocq-ab-ck').not(':disabled').prop('checked', on);
    ocqAbCkSync($pane);
});
// 挑好候選＝多半就是要套用它，順手幫忙勾起來（少按一次；不想套用的再取消勾選即可）。
// **選擇器要用候選列的 class 不是 name 前綴**——三個階段的 radio name 各自不同
// （abp_／abo_／abq_），用前綴綁只會有料號那一階生效，而且完全不報錯。
// **綁訂單那一階的候選是 checkbox（一筆 BOM 的量可以分屬好幾張訂單），料號與報價單仍是 radio**，
// 所以這裡兩種都要收；候選全部取消勾選時，該列的勾選也要跟著取消（不然會送出一筆沒挑訂單的）。
$('#ocqAbMask').on('change', '.ocq-ab-cand input[type=radio], .ocq-ab-cand input[type=checkbox]', function(){
    var $tr = $(this).closest('tr');
    if (!$tr.hasClass('done')) {
        var any = $tr.find('.ocq-ab-cand input[type=radio]:checked, .ocq-ab-cand input[type=checkbox]:checked').length > 0;
        $tr.find('.ocq-ab-ck').not(':disabled').prop('checked', any);
    }
    ocqAbOrderSum($tr);
    ocqAbCkSync($tr.closest('.ocq-ab-pane'));
});
// 綁訂單那一階：把這一列已挑訂單的分配量合計寫出來，超過發單量標紅（只提醒不擋，
// 現場確實有「一張 BOM 補足好幾張訂單的缺量」這種情形，數字對不對由挑的人判斷）
function ocqAbOrderSum($tr){
    var $box = $tr.find('.ocq-ab-osum');
    if (!$box.length) return;
    var n = 0, sum = 0;
    $tr.find('.abo-ck:checked').each(function(){
        var oid = parseInt($(this).val(), 10) || 0;
        n++;
        sum += parseInt($tr.find('.oqty[data-oid="' + oid + '"]').val(), 10) || 0;
    });
    if (n < 2) { $box.text('').css('color', ''); return; }
    var bq = parseInt($tr.data('bomqty'), 10) || 0;
    var over = (bq > 0 && sum > bq);
    $box.text('已挑 ' + n + ' 張，分配量合計 ' + sum + (bq > 0 ? '／發單量 ' + bq : '')
              + (over ? '（超過發單量，請確認分配量）' : ''))
        .css('color', over ? '#DD5138' : '');
}
// 使用者自己改過的分配量不可以被「綁定後即時刷新」蓋掉（那是他刻意填的數字）
$('#ocqAbMask').on('input change', '.oqty', function(){ $(this).data('touched', 1); ocqAbOrderSum($(this).closest('tr')); });

// ── 綁定後即時更新「其他還沒判定的列」──────────────────────────────────────────
// 同一個料號的好幾筆 BOM 會排在一起，而且**共用同一批候選訂單**；綁完第一筆之後，
// 其他列若還印著舊的「已分配」數字，就會照著舊數字把同一張訂單再分配一次
// （使用者回報的就是這件事）。所以綁定成功後把受影響的那幾張訂單重新查一次並就地更新。
// **刻意不整份重載清單**：重載會把已經判定好的列與捲動位置一起洗掉，現場通常是一口氣判十幾筆。
function ocqObAllocHtml(qty, used){
    qty = parseInt(qty, 10) || 0; used = parseInt(used, 10) || 0;
    var left = Math.max(0, qty - used);
    return '　訂單量 ' + qty + (used > 0 ? '（已分配 ' + used + '、未分配 ' + left + '）' : '');
}
function ocqObFullHtml(left, boms){
    if ((parseInt(left, 10) || 0) > 0) return '';
    var who = '已分配滿';
    if (boms && boms.length) {
        who = '已被 ' + boms.slice(0, 3).map(esc).join('、') + (boms.length > 3 ? ' 等 ' + boms.length + ' 筆' : '') + ' 綁走';
    }
    return ' <b class="ocq-cand-full">⚠ ' + who + '，分配量預設 0</b>';
}
var ocqObRefPend = {}, ocqObRefTimer = null;
function ocqObRefreshOrders(orderIds){
    (orderIds || []).forEach(function(oid){ if (oid > 0) ocqObRefPend[oid] = 1; });
    if (ocqObRefTimer) clearTimeout(ocqObRefTimer);
    // 批次套用是一筆一筆送的，這裡等它跑完再一次問後端（不要每綁一筆就打一支）
    ocqObRefTimer = setTimeout(ocqObRefreshRun, 250);
}
function ocqObRefreshRun(){
    ocqObRefTimer = null;
    var ids = Object.keys(ocqObRefPend).map(Number); ocqObRefPend = {};
    if (!ids.length) return;
    // 分配量預設值要依「那一列 BOM 的發單量」算，所以連發單量一起送，由後端用同一支
    // ocq_ob_qty_default() 算好回來（規則只有一份，不在 JS 再寫一次）
    var pairs = {}, $pane = $('#ocqObPaneManual');
    $pane.find('tbody tr').not('.done').each(function(){
        var bq = parseInt($(this).data('bomqty'), 10) || 0;
        $(this).find('.ocq-ab-cand').each(function(){
            var oid = parseInt($(this).data('oid'), 10) || 0;
            if (oid > 0 && ids.indexOf(oid) >= 0) pairs[oid + ':' + bq] = { order_id: oid, bom_qty: bq };
        });
    });
    var list = Object.keys(pairs).map(function(k){ return pairs[k]; });
    if (!list.length) return;
    $.post('', { action: 'autobind_order_refresh', orders_json: JSON.stringify(list) }, function(res){
        if (!res || !res.success) return;
        var touched = [];
        (res.rows || []).forEach(function(rw){
            $pane.find('tbody tr').not('.done').each(function(){
                var $tr = $(this);
                if ((parseInt($tr.data('bomqty'), 10) || 0) !== rw.bom_qty) return;
                var $lab = $tr.find('.ocq-ab-cand[data-oid="' + rw.order_id + '"]');
                if (!$lab.length) return;
                $lab.find('.oalloc').html(ocqObAllocHtml(rw.qty, rw.used));
                $lab.find('.ofull').html(ocqObFullHtml(rw.left, rw.boms));
                $lab.toggleClass('cand-full', rw.left <= 0);
                // 已經勾起來或自己改過數字的不動（那是使用者正在判定的內容）
                var $q = $lab.find('.oqty');
                if ($q.length && $q.data('touched') !== 1 && !$lab.find('.abo-ck').prop('checked')) $q.val(rw.qty_default);
                if (touched.indexOf(this) < 0) touched.push(this);
            });
        });
        touched.forEach(function(tr){ ocqAbOrderSum($(tr)); });
    }, 'json');
}

// 批次跑：一筆一筆送（每一筆都走跟逐筆按鈕完全相同的後端動作，規則不可能走鐘），
// 邊跑邊把結果寫回該列；中途失敗的只記在那一列，不中斷其餘的。
function ocqAbBulkRun($pane, jobs, doOne, doneMsg){
    var i = 0, ok = 0, fail = 0;
    $pane.find('.ocq-ab-bulk button').prop('disabled', true);
    (function next(){
        if (i >= jobs.length) {
            $pane.find('.ocq-ab-bulk button').prop('disabled', false);
            ocqAbCkSync($pane);
            ocqAbMsg(doneMsg(ok, fail), fail > 0);
            loadList(curPage);          // 後方清單同步換成綁好的狀態
            return;
        }
        var job = jobs[i++];
        ocqAbMsg('處理中… <b>' + i + '</b> / ' + jobs.length + ' 筆');
        doOne(job, function(res){
            if (res && res.success) { ok++; ocqAbMarkDone(job.$tr, res); }
            else { fail++; ocqAbRowErr(job.$tr, (res && res.message) || '處理失敗'); }
            next();
        });
    })();
}

// 逐筆套用：走既有的 bind_apply_part（與手動綁定完全同一條路徑，含 CONFLICT 檢查）
$('#ocqAbMask').on('click', '.ocq-ab-pick', function(){
    var $btn = $(this), $tr = $btn.closest('tr'), bom = $btn.data('bom');
    var did = $tr.find('input[type=radio]:checked').val();
    if (!did) { ocqAbMsg('請先挑一筆候選料號再按套用', true); return; }
    $btn.prop('disabled', true).text('處理中');
    $.post('', { action: 'bind_apply_part', bom: bom, d_setting_id: did, csrf: OCQ_BIND.csrf }, function(res){
        if (!res || !res.success) {
            $btn.prop('disabled', false).text('套用');
            ocqAbMsg((res && res.message) || '綁定失敗', true);
            return;
        }
        ocqAbMarkDone($tr, res);
        ocqAbMsg('已綁定 ' + esc(bom) + ' → ' + esc(res.d_id));
        loadList(curPage);
    }, 'json').fail(function(){
        $btn.prop('disabled', false).text('套用');
        ocqAbMsg('綁定失敗，請重新整理後再試', true);
    });
});

// 勾選後一次全部套用
$('#ocqAbMask').on('click', '.ocq-ab-bulk-apply', function(){
    var $pane = $(this).closest('.ocq-ab-pane');
    var stage = $(this).closest('.ocq-ab-bulk').data('stage') || 'part';
    if (stage !== 'part') {                       // 訂單／報價單階段走各自的引擎
        var S = (stage === 'order') ? window.ocqOb : window.ocqQb;
        if (S) S.bulkApply($pane);
        return;
    }
    var jobs = [], noPick = 0;
    $pane.find('tbody tr').each(function(){
        var $tr = $(this);
        if (!$tr.find('.ocq-ab-ck').prop('checked')) return;
        var did = $tr.find('input[type=radio]:checked').val();
        // 勾了卻沒挑候選的不猜，就地寫明原因略過（猜錯家比不綁更難查回來）
        if (!did) { noPick++; ocqAbRowErr($tr, '還沒挑候選料號，這一筆略過'); return; }
        jobs.push({ $tr: $tr, bom: String($tr.data('bom') || ''), did: did });
    });
    if (!jobs.length) { ocqAbMsg('勾選的列都還沒挑候選料號，請先挑一筆再套用', true); return; }
    if (!confirm('即將套用 ' + jobs.length + ' 筆綁定'
        + (noPick ? '（另有 ' + noPick + ' 筆還沒挑候選料號，會略過）' : '') + '。\n\n'
        + '綁定會同時把 BOM 上的料號文字與客戶名稱更新成料號主檔的值，每一筆都會留下稽核紀錄。\n'
        + '已經被別人綁走的會自動擋下、不會覆蓋。\n確定要執行嗎？')) return;
    ocqAbBulkRun($pane, jobs, function(job, cb){
        $.post('', { action: 'bind_apply_part', bom: job.bom, d_setting_id: job.did, csrf: OCQ_BIND.csrf },
            function(res){ cb(res); }, 'json').fail(function(){ cb({ success: false, message: '連線失敗' }); });
    }, function(ok, fail){
        return '完成：已綁定 <b>' + ok + '</b> 筆' + (fail ? '，失敗 ' + fail + ' 筆（原因寫在該列）' : '');
    });
});

// ── 快速建立料號主檔並綁定（主檔查無／候選客戶都對不上時用）────────────────────
var ocqCust = null;   // 客戶主檔清單，一個 session 只跟後端要一次
function ocqCustLoad(cb){
    if (ocqCust) { cb(); return; }
    $.post('', { action: 'bind_customers' }, function(res){
        ocqCust = (res && res.success) ? (res.customers || []) : [];
        cb();
    }, 'json').fail(function(){ ocqCust = []; cb(); });
}
// BOM 的客戶名稱是 ERP 匯入的簡稱，跟主檔寫法常常不同（「高鋒工業」vs「高鋒」），
// 所以完全相同找不到時再用互相包含找一次，取名字最長的那筆（最具體）；再找不到就留白讓人自己挑。
function ocqCustGuess(name){
    var n = $.trim(name || '');
    if (!n || !ocqCust) return null;
    var i, c, hit = null;
    for (i = 0; i < ocqCust.length; i++) { if ($.trim(ocqCust[i].customer) === n) return ocqCust[i]; }
    for (i = 0; i < ocqCust.length; i++) {
        c = $.trim(ocqCust[i].customer);
        if (!c) continue;
        if (n.indexOf(c) >= 0 || c.indexOf(n) >= 0) {
            if (!hit || c.length > $.trim(hit.customer).length) hit = ocqCust[i];
        }
    }
    return hit;
}
function ocqCustOptions(sel){
    var h = '<option value="">（不綁客戶）</option>';
    for (var i = 0; i < ocqCust.length; i++) {
        var c = ocqCust[i];
        h += '<option value="' + esc(c.customer_id) + '"' + (c.customer_id === sel ? ' selected' : '') + '>'
           + esc(c.customer) + '（' + esc(c.customer_id) + '）</option>';
    }
    return h;
}
var ocqNp = { rows: [], running: false };
function ocqNpOpen(rows){
    if (!rows.length) return;
    ocqCustLoad(function(){
        ocqNp.rows = rows;
        var one = (rows.length === 1), h = '';
        rows.forEach(function(r, i){
            var g = ocqCustGuess(r.client);
            r.done = false;
            h += '<tr data-i="' + i + '"><td>' + esc(r.bom) + '</td>'
               + '<td><input type="text" class="pn" maxlength="100" value="' + esc(r.part) + '"></td>'
               + '<td>' + (r.client ? esc(r.client) : '<span class="ocq-ab-sub">（空白）</span>') + '</td>'
               + '<td><select class="cs' + (g ? ' auto-hit' : '') + '" data-eg-filter="輸入客戶名稱或代號篩選…">'
               + ocqCustOptions(g ? g.customer_id : '') + '</select></td>'
               + '<td class="st">' + (g ? '<span style="color:#2f7a3f;">已自動對應主檔客戶</span>'
                                        : '<span style="color:#B4543B;">對不到客戶主檔，請自己挑</span>') + '</td></tr>';
        });
        $('#ocqNpTb tbody').html(h);
        $('#ocqNpOne').toggle(one);
        $('#ocqNpSpec, #ocqNpRemark').val('');
        $('#ocqNpMsg').html('');
        $('#ocqNpRun').prop('disabled', false).text(one ? '建立並綁定' : '建立並綁定（' + rows.length + ' 筆）');
        $('#ocqNpMask').show();
    });
}
function ocqNpClose(){
    if (ocqNp.running) { if (!confirm('還在建立中，確定要關閉嗎？（已經建好的不會退回）')) return; }
    $('#ocqNpMask').hide();
}
$('#ocqNpClose, #ocqNpCancel').on('click', ocqNpClose);
$('#ocqNpMask').on('click', function(e){ if (e.target === this && !ocqNp.running) ocqNpClose(); });
// 改了客戶就把「已自動對應」的綠底拿掉，免得看起來像系統挑的
$('#ocqNpMask').on('change', 'select.cs', function(){ $(this).removeClass('auto-hit').closest('tr').find('td.st').html(''); });

$('#ocqAbMask').on('click', '.ocq-ab-new', function(){
    var $tr = $(this).closest('tr');
    ocqNpOpen([{ bom: String($tr.data('bom') || ''), part: String($tr.data('part') || ''), client: String($tr.data('client') || ''), $tr: $tr }]);
});
$('#ocqAbMask').on('click', '.ocq-ab-bulk-new', function(){
    var rows = [];
    $(this).closest('.ocq-ab-pane').find('tbody tr').each(function(){
        var $tr = $(this);
        if (!$tr.find('.ocq-ab-ck').prop('checked')) return;
        var part = $.trim(String($tr.data('part') || ''));
        if (!part) { ocqAbRowErr($tr, '這筆 BOM 沒有料號文字，建不了料號'); return; }
        rows.push({ bom: String($tr.data('bom') || ''), part: part, client: String($tr.data('client') || ''), $tr: $tr });
    });
    if (!rows.length) { ocqAbMsg('勾選的列都沒有料號文字，無法建立料號', true); return; }
    ocqNpOpen(rows);
});

$('#ocqNpRun').on('click', function(){
    if (ocqNp.running) return;
    var jobs = [], blank = 0;
    $('#ocqNpTb tbody tr').each(function(){
        var $r = $(this), r = ocqNp.rows[parseInt($r.data('i'), 10)];
        if (!r || r.done) return;
        var pn = $.trim($r.find('input.pn').val() || '');
        if (!pn) { blank++; $r.find('td.st').html('<span style="color:#DD5138;">料號不可空白</span>'); return; }
        jobs.push({ row: r, $row: $r, $tr: r.$tr, bom: r.bom, part: pn, cust: $r.find('select.cs').val() || '' });
    });
    if (blank) { $('#ocqNpMsg').html('<span class="ocq-bind-err">有 ' + blank + ' 筆的料號是空白的，請補上</span>'); return; }
    if (!jobs.length) { $('#ocqNpMsg').html('<span class="ocq-bind-err">沒有要建立的資料</span>'); return; }
    var noCust = 0;
    jobs.forEach(function(j){ if (!j.cust) noCust++; });
    if (!confirm('即將建立 ' + jobs.length + ' 筆料號主檔，並立刻綁定對應的 BOM。\n\n'
        + (noCust ? '其中 ' + noCust + ' 筆沒有指定客戶，會建成「沒有綁客戶」的料號。\n' : '')
        + '同料號＋同客戶的主檔已經存在時不會重複建立，會直接沿用那一筆。\n確定要執行嗎？')) return;

    ocqNp.running = true;
    $('#ocqNpRun').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 建立中…');
    var i = 0, ok = 0, fail = 0, spec = $.trim($('#ocqNpSpec').val() || ''), remark = $.trim($('#ocqNpRemark').val() || '');
    (function next(){
        if (i >= jobs.length) {
            ocqNp.running = false;
            $('#ocqNpRun').prop('disabled', fail === 0).text('建立並綁定');
            $('#ocqNpMsg').html(fail ? '<span class="ocq-bind-err">完成：成功 ' + ok + ' 筆、失敗 ' + fail + ' 筆（原因寫在各列狀態欄）</span>'
                                     : '已完成 ' + ok + ' 筆');
            ocqAbMsg('已建立並綁定 <b>' + ok + '</b> 筆料號' + (fail ? '，失敗 ' + fail + ' 筆' : ''), fail > 0);
            loadList(curPage);
            if (!fail) setTimeout(function(){ $('#ocqNpMask').hide(); }, 700);
            return;
        }
        var job = jobs[i++];
        job.$row.find('td.st').html('<span class="ocq-ab-sub">處理中…</span>');
        $('#ocqNpMsg').html('處理中… <b>' + i + '</b> / ' + jobs.length + ' 筆');
        $.post('', { action: 'bind_create_part', bom: job.bom, part_no: job.part, customer_id: job.cust,
                     spec_no: spec, remark: remark, csrf: OCQ_BIND.csrf }, function(res){
            if (res && res.success) {
                ok++;
                job.row.done = true;
                job.$row.find('td.st').html('<span style="color:#2f7a3f;">' + (res.created ? '已建立並綁定' : '沿用既有主檔並綁定')
                    + '（#' + esc(res.d_setting_id) + '）</span>');
                job.$row.find('input.pn, select.cs').prop('disabled', true);
                ocqAbMarkDone(job.$tr, res);
            } else {
                fail++;
                job.$row.find('td.st').html('<span style="color:#DD5138;">' + esc((res && res.message) || '建立失敗') + '</span>');
                ocqAbRowErr(job.$tr, (res && res.message) || '建立失敗');
            }
            next();
        }, 'json').fail(function(){
            fail++;
            job.$row.find('td.st').html('<span style="color:#DD5138;">連線失敗</span>');
            next();
        });
    })();
});

// ══ 第二、三階段：綁訂單／綁報價單 ═══════════════════════════════════════════════
// 兩個階段的流程與畫面結構完全一樣（掃描 → 三張統計卡 → 三個分頁 → 可自動綁的整批執行、
// 指不出唯一一筆的逐筆挑＋勾選後一次套用），只有「一列長什麼樣子」與「送出去的欄位」不同，
// 所以做成同一支引擎餵兩份設定，不要複製兩份（複製出去的那份遲早跟這份長得不一樣）。
function ocqSxPagerHtml(stage, kind, res){
    var pages = Math.max(1, Math.ceil(res.total / res.per));
    if (pages <= 1) return '<div class="ocq-ab-pg">共 ' + res.total + ' 筆</div>';
    return '<div class="ocq-ab-pg">共 ' + res.total + ' 筆，第 ' + res.page + ' / ' + pages + ' 頁'
         + '<button type="button" class="ocq-ab-pgbtn" data-stage="' + stage + '" data-kind="' + kind + '" data-page="' + (res.page - 1) + '"'
         + (res.page <= 1 ? ' disabled' : '') + '>上一頁</button>'
         + '<button type="button" class="ocq-ab-pgbtn" data-stage="' + stage + '" data-kind="' + kind + '" data-page="' + (res.page + 1) + '"'
         + (res.page >= pages ? ' disabled' : '') + '>下一頁</button></div>';
}

function ocqMakeStage(cfg){
    var S = { scanned: false, auto: [], sample: [], total: 0, tab: 'auto', running: false,
              page: { manual: 1, nomatch: 1 } };
    function $pane(t){ return $(cfg.pane[t]); }

    S.reset = function(){
        S.scanned = false; S.auto = []; S.sample = []; S.total = 0; S.tab = 'auto';
        S.page = { manual: 1, nomatch: 1 };
        $(cfg.root).find('.ocq-sx-result').hide();
    };
    // 掃描（試算，只看不寫）。**綁完一定要再掃一次**——數字的真相在後端，不要在前端自己扣。
    S.scan = function(keepMsg){
        var $root = $(cfg.root);
        $root.find('.ocq-sx-scan').show();
        $root.find('.ocq-sx-result').hide();
        $(cfg.runBtn).prop('disabled', true).hide();
        var f = curFilters(); f.action = cfg.scanAction;
        $.post('', f, function(res){
            $root.find('.ocq-sx-scan').hide();
            if (!res || !res.success) { ocqAbMsg((res && res.message) || '掃描失敗', true); return; }
            S.scanned = true; S.auto = res.auto_rows || []; S.sample = res.sample || []; S.total = res.total || 0;
            var c = res.count || {};
            $(cfg.card.auto).text(c.auto || 0);
            $(cfg.card.manual).text(c.manual || 0);
            $(cfg.card.nomatch).text(c.nomatch || 0);
            $root.find('.ocq-sx-result').show();
            $pane('auto').html(cfg.renderAuto(S));
            $(cfg.runBtn).prop('disabled', !(c.auto > 0));
            if (S.tab === 'manual' || S.tab === 'nomatch') S.loadList(S.tab, S.page[S.tab] || 1);
            else S.switchTab('auto');
            if (!keepMsg) ocqAbMsg(cfg.scanMsg(S));
        }, 'json').fail(function(){ $root.find('.ocq-sx-scan').hide(); ocqAbMsg('掃描失敗，請重新整理後再試', true); });
    };
    S.switchTab = function(tab){
        S.tab = tab;
        $(cfg.root).find('.ocq-ab-tab').removeClass('active').filter('[data-tab="' + tab + '"]').addClass('active');
        $pane('auto').toggle(tab === 'auto');
        $pane('manual').toggle(tab === 'manual');
        $pane('nomatch').toggle(tab === 'nomatch');
        // 「整批執行」只對可自動綁的那批有意義，切到別的分頁就收起來免得誤按
        $(cfg.runBtn).toggle(tab === 'auto' && ocqStage === cfg.stage && S.auto.length > 0);
    };
    S.loadList = function(kind, page){
        S.page[kind] = page;
        var $p = $pane(kind);
        $p.html('<div style="padding:14px;color:#a0521f;"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>');
        var f = curFilters(); f.action = cfg.listAction; f.kind = kind; f.page = page;
        $.post('', f, function(res){
            if (!res || !res.success) { $p.html('<div style="padding:14px;" class="ocq-bind-err">載入失敗</div>'); return; }
            $p.html(cfg.renderList(res, kind) + ocqSxPagerHtml(cfg.stage, kind, res));
            ocqAbCkSync($p);
        }, 'json').fail(function(){ $p.html('<div style="padding:14px;" class="ocq-bind-err">載入失敗</div>'); });
    };
    // 可自動綁的那批：分批送（一次 100 筆），邊跑邊更新進度條
    S.runAuto = function(){
        if (S.running || !S.auto.length) return;
        if (!confirm(cfg.confirmAuto(S.auto.length))) return;
        S.running = true;
        $(cfg.runBtn).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 綁定中…');
        $(cfg.root).find('.ocq-ab-bar').show();
        var todo = S.auto.slice(), all = todo.length, done = 0, skipped = [];
        (function step(){
            if (!todo.length) {
                S.running = false;
                $(cfg.runBtn).html(cfg.runLabel);
                var m = '完成：已綁定 <b>' + done + '</b> 筆';
                if (skipped.length) m += '，略過 ' + skipped.length + ' 筆（多半是其他人已經先綁好了）';
                ocqAbMsg(m);
                $(cfg.root).find('.ocq-ab-bar').hide().find('> i').css('width', '0');
                loadList(curPage);
                S.scan(true);
                return;
            }
            var batch = todo.splice(0, 100);
            $.post('', { action: cfg.applyAction, rows_json: JSON.stringify(batch), csrf: OCQ_BIND.csrf }, function(res){
                if (!res || !res.success) {
                    S.running = false;
                    $(cfg.runBtn).prop('disabled', false).html(cfg.runLabel);
                    ocqAbMsg((res && res.message) || '綁定失敗', true);
                    return;
                }
                done += (res.done || 0);
                skipped = skipped.concat(res.skipped || []);
                $(cfg.root).find('.ocq-ab-bar > i').css('width', Math.round((all - todo.length) / all * 100) + '%');
                ocqAbMsg('進行中… 已綁定 <b>' + done + '</b> / ' + all + ' 筆');
                step();
            }, 'json').fail(function(){
                S.running = false;
                $(cfg.runBtn).prop('disabled', false).html(cfg.runLabel);
                ocqAbMsg('這一批送出失敗，已綁定 ' + done + ' 筆；請重新整理後再執行一次剩下的', true);
            });
        })();
    };
    // 逐筆套用（跟勾選批次套用送的是同一支動作、同一組欄位）
    S.applyOne = function($tr, cb){
        var job = cfg.rowJob($tr);
        if (!job) { cb({ success: false, message: cfg.pickHint }); return; }
        $.post('', { action: cfg.applyAction, rows_json: JSON.stringify([job]), csrf: OCQ_BIND.csrf }, function(res){
            if (!res || !res.success) { cb({ success: false, message: (res && res.message) || '綁定失敗' }); return; }
            if ((res.done || 0) > 0) {
                // 綁成功了：讓這一階自己決定要不要順手更新畫面上的其他列（綁訂單那一階要，
                // 因為同一個料號的好幾筆 BOM 共用同一批候選訂單）
                if (cfg.afterApply) { try { cfg.afterApply(job, $tr); } catch (e) {} }
                cb({ success: true, d_id: cfg.doneLabel($tr, job) });
            }
            else cb({ success: false, message: ((res.skipped || [])[0] || {}).why || '綁定失敗' });
        }, 'json').fail(function(){ cb({ success: false, message: '連線失敗' }); });
    };
    S.bulkApply = function($p){
        var jobs = [], noPick = 0;
        $p.find('tbody tr').each(function(){
            var $tr = $(this);
            if (!$tr.find('.ocq-ab-ck').prop('checked')) return;
            var job = cfg.rowJob($tr);
            if (!job) { noPick++; ocqAbRowErr($tr, cfg.pickHint); return; }
            jobs.push({ $tr: $tr, job: job });
        });
        if (!jobs.length) { ocqAbMsg(cfg.pickHint, true); return; }
        if (!confirm(cfg.confirmBulk(jobs.length, noPick))) return;
        ocqAbBulkRun($p, jobs, function(j, cb){ S.applyOne(j.$tr, cb); }, function(ok, fail){
            if (ok > 0) S.scanCountsSoon();
            return '完成：已綁定 <b>' + ok + '</b> 筆' + (fail ? '，失敗 ' + fail + ' 筆（原因寫在該列）' : '');
        });
    };
    // 綁完之後卡片數字已經就地扣過了；真正的數字仍以後端為準，等使用者切分頁時會重掃
    S.scanCountsSoon = function(){ S.scanned = S.scanned; };
    $(cfg.root).on('click', '.ocq-ab-tab', function(){
        var tab = $(this).data('tab');
        S.switchTab(tab);
        if (tab === 'manual' || tab === 'nomatch') S.loadList(tab, S.page[tab] || 1);
    });
    $(cfg.root).on('click', '.ocq-sx-pick', function(){
        var $btn = $(this), $tr = $btn.closest('tr');
        $btn.prop('disabled', true).text('處理中');
        S.applyOne($tr, function(res){
            if (!res.success) { $btn.prop('disabled', false).text('套用'); ocqAbMsg(res.message || '綁定失敗', true); return; }
            ocqAbMarkDone($tr, res);
            ocqAbMsg('已綁定 ' + esc(res.d_id || ''));
            loadList(curPage);
        });
    });
    $(cfg.runBtn).on('click', function(){ S.runAuto(); });
    return S;
}

if (OCQ_BIND.order) {
    // ── ② 綁訂單 ────────────────────────────────────────────────────────────────
    window.ocqOb = ocqMakeStage({
        stage: 'order', root: '#ocqStageOrder', runBtn: '#ocqObRun', runLabel: '<i class="fa fa-magic"></i> 開始自動綁定訂單',
        card: { auto: '#ocqObNAuto', manual: '#ocqObNManual', nomatch: '#ocqObNNomatch' },
        pane: { auto: '#ocqObPaneAuto', manual: '#ocqObPaneManual', nomatch: '#ocqObPaneNomatch' },
        scanAction: 'autobind_order_scan', listAction: 'autobind_order_list', applyAction: 'autobind_order_apply',
        pickHint: '請先挑候選訂單再套用（可以挑好幾張）',
        scanMsg: function(S){ return '範圍內已綁料號、還沒綁訂單的共 <b>' + S.total + '</b> 筆'; },
        confirmAuto: function(n){ return '即將自動綁定 ' + n + ' 筆 BOM 的訂單。\n\n'
            + '系統只會綁「這個料號底下只有一張訂單」的；已經有人綁過的會自動略過。\n'
            + '分配量帶入「BOM 發單量」與「該訂單未被分配量」中較小者。\n確定要執行嗎？'; },
        confirmBulk: function(n, noPick){ return '即將綁定 ' + n + ' 筆'
            + (noPick ? '（另有 ' + noPick + ' 筆還沒挑訂單，會略過）' : '') + '。\n\n'
            + '已經有人綁過的會自動擋下、不會覆蓋。\n確定要執行嗎？'; },
        // 一筆 BOM 的量可能分屬好幾張訂單，所以候選是**可複選**的（送出的是整組，見後端註解）
        rowJob: function($tr){
            var orders = [];
            $tr.find('.abo-ck:checked').each(function(){
                var oid = parseInt($(this).val(), 10) || 0;
                if (!oid) return;
                var qty = parseInt($tr.find('.oqty[data-oid="' + oid + '"]').val(), 10);
                if (isNaN(qty) || qty < 0) qty = 0;
                orders.push({ order_id: oid, qty: qty });
            });
            if (!orders.length) return null;
            return { bom: String($tr.data('bom') || ''), orders: orders };
        },
        doneLabel: function($tr, job){
            var t = [];
            $tr.find('.abo-ck:checked').each(function(i){
                t.push($(this).data('oo') + '×' + ((job.orders[i] || {}).qty || 0));
            });
            return t.join('、');
        },
        // 綁完就把這幾張訂單的最新已分配量抓回來、更新畫面上其他還沒判定的列
        afterApply: function(job){ ocqObRefreshOrders((job.orders || []).map(function(o){ return o.order_id; })); },
        renderAuto: function(S){
            if (!S.auto.length) return '<div style="padding:14px;color:#a08a6a;">這個範圍內沒有可以自動綁定的 BOM（'
                + '要嘛這個料號底下有好幾張訂單、要嘛一張都沒有，請看另外兩個分頁）。</div>';
            var h = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
                  + '以下列出前 ' + S.sample.length + ' 筆做為抽樣確認，按「開始自動綁定訂單」會處理全部 <b>'
                  + S.auto.length + '</b> 筆。每一筆都會留下稽核紀錄。</div>'
                  + '<div class="ocq-ab-bar" style="display:none;"><i></i></div>'
                  + '<table class="ocq-ab-tb"><thead><tr><th>BOM</th><th>料號</th><th>發單量</th>'
                  + '<th>將綁定的訂單</th><th>訂單量／已分配</th><th>分配量</th><th>判定依據</th></tr></thead><tbody>';
            S.sample.forEach(function(r){
                h += '<tr><td>' + esc(r.bom) + '</td><td>' + esc(r.part_no) + '</td><td>' + esc(r.bom_qty) + '</td>'
                   + '<td><b>' + esc(r.order_oo) + '</b><div class="ocq-ab-sub">' + esc(egFmtDate(r.order_date)) + '</div></td>'
                   + '<td>' + esc(r.order_qty) + ' ／ ' + esc(r.used || 0) + '</td>'
                   + '<td><b>' + esc(r.qty) + '</b></td>'
                   + '<td class="ocq-ab-why">' + esc(r.reason) + '</td></tr>';
            });
            return h + '</tbody></table>';
        },
        renderList: function(res, kind){
            if (!res.total) return '<div style="padding:14px;color:#a08a6a;">沒有這一類的 BOM。</div>';
            if (kind === 'nomatch') {
                var hn = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
                       + '以下這幾筆 BOM 的<b>料號主檔底下一張訂單都沒有</b>（可能是備料、樣品，或訂單根本沒建進系統），'
                       + '系統無法綁訂單。</div>'
                       + '<table class="ocq-ab-tb"><thead><tr><th style="width:130px;">BOM</th><th>料號</th>'
                       + '<th style="width:120px;">客戶</th><th style="width:70px;">發單量</th><th>說明</th></tr></thead><tbody>';
                (res.rows || []).forEach(function(r){
                    hn += '<tr><td>' + esc(r.bom) + '<div class="ocq-ab-sub">' + esc(egFmtDate(r.eff_date)) + '</div></td>'
                        + '<td>' + esc(r.part_no) + '</td><td>' + (r.bom_client ? esc(r.bom_client) : '<span class="ocq-ab-sub">（空白）</span>') + '</td>'
                        + '<td>' + esc(r.bom_qty) + '</td><td class="ocq-ab-why">' + esc(r.reason) + '</td></tr>';
                });
                return hn + '</tbody></table>';
            }
            var h = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
                  + '以下這幾筆的料號底下<b>有好幾張訂單</b>，系統無從得知這批貨是做哪一張，請挑選後再套用；'
                  + '挑好可以<b>逐筆按「套用」，也可以勾選起來一次全部套用</b>。'
                  + '候選訂單依「訂單日與這筆 BOM 的日期最接近」排序，<b>分配量可以逐張改</b>。'
                  + '<br><b>同一筆 BOM 的量分屬好幾張訂單時，可以一次挑好幾張</b>（一次最多 20 張，會各自寫入分配量）。</div>'
                  + ocqAbBulkBar('manual', true, 'order', '套用勾選的（綁定已挑的訂單）', '（挑好訂單會自動幫你勾起來）')
                  + '<table class="ocq-ab-tb"><thead><tr><th class="ck"><input type="checkbox" class="ocq-ab-all" title="全選／全不選"></th>'
                  + '<th style="width:130px;">BOM</th><th style="width:140px;">料號／客戶</th>'
                  + '<th>候選訂單（可複選）</th><th style="width:60px;"></th></tr></thead><tbody>';
            (res.rows || []).forEach(function(r){
                h += '<tr data-bom="' + esc(r.bom) + '" data-bomqty="' + (parseInt(r.bom_qty, 10) || 0) + '">'
                   + '<td class="ck"><input type="checkbox" class="ocq-ab-ck"></td>'
                   + '<td>' + esc(r.bom) + '<div class="ocq-ab-sub">' + esc(egFmtDate(r.eff_date))
                   + '　發單 ' + esc(r.bom_qty) + '</div></td>'
                   + '<td>' + esc(r.part_no) + '<div class="ocq-ab-sub">' + (r.bom_client ? esc(r.bom_client) : '（沒有客戶）') + '</div></td>'
                   + '<td class="cnd">';
                (r.candidates || []).forEach(function(o){
                    var left = Math.max(0, (parseInt(o.qty, 10) || 0) - (parseInt(o.used, 10) || 0));
                    h += '<label class="ocq-ab-cand" data-oid="' + o.order_id + '"><input type="checkbox" class="abo-ck" value="' + o.order_id + '" data-oo="' + esc(o.order_oo) + '">'
                       + '<b>' + esc(o.order_oo) + '</b>'
                       + '<span class="ocq-ab-sub">　訂單日 ' + esc(o.order_date ? egFmtDate(o.order_date) : '—')
                       + '　交期 ' + esc(o.delivery_date ? egFmtDate(o.delivery_date) : '—')
                       + '<span class="oalloc">' + ocqObAllocHtml(o.qty, o.used) + '</span>'
                       + (o.spec ? '　' + esc(o.spec) : '') + '</span>'
                       + '<span class="ofull">' + ocqObFullHtml(left, []) + '</span>'
                       + '　分配量 <input type="number" class="ocq-pick-qty oqty" data-oid="' + o.order_id + '" min="0" value="' + o.qty_default + '" onclick="event.preventDefault();event.stopPropagation();">'
                       + '</label>';
                });
                if ((r.cand_total || 0) > (r.candidates || []).length) {
                    h += '<div class="ocq-ab-sub">另有 ' + (r.cand_total - r.candidates.length) + ' 張較早／較晚的訂單沒列出來，'
                       + '要綁那幾張請用清單上的「綁訂單」逐筆處理。</div>';
                }
                // 挑了不只一張時把「分配量合計 vs 發單量」寫出來——分配量預設各自帶「發單量與該訂單
                // 未分配量中較小者」，多挑幾張就會重複計到，不寫出來看不出已經超量
                h += '<div class="ocq-ab-osum ocq-ab-sub"></div>'
                   + '<div class="ocq-ab-why">' + esc(r.reason) + '</div></td>'
                   + '<td><button type="button" class="ocq-bind-btn ocq-sx-pick">套用</button></td></tr>';
            });
            return h + '</tbody></table>';
        }
    });

    // ── ③ 綁報價單（單位是訂單，不是 BOM）──────────────────────────────────────
    window.ocqQb = ocqMakeStage({
        stage: 'quote', root: '#ocqStageQuote', runBtn: '#ocqQbRun', runLabel: '<i class="fa fa-magic"></i> 開始自動綁定報價單',
        card: { auto: '#ocqQbNAuto', manual: '#ocqQbNManual', nomatch: '#ocqQbNNomatch' },
        pane: { auto: '#ocqQbPaneAuto', manual: '#ocqQbPaneManual', nomatch: '#ocqQbPaneNomatch' },
        scanAction: 'autobind_quote_scan', listAction: 'autobind_quote_list', applyAction: 'autobind_quote_apply',
        pickHint: '請先挑一筆報價明細再套用',
        scanMsg: function(S){ return '範圍內的 BOM 綁到的訂單中，還沒綁報價單的共 <b>' + S.total + '</b> 張'; },
        confirmAuto: function(n){ return '即將自動綁定 ' + n + ' 張訂單的報價單。\n\n'
            + '系統只會綁「這個料號只有一筆報價明細」的；已經有人綁過的會自動略過。\n確定要執行嗎？'; },
        confirmBulk: function(n, noPick){ return '即將綁定 ' + n + ' 張訂單的報價單'
            + (noPick ? '（另有 ' + noPick + ' 張還沒挑報價，會略過）' : '') + '。\n\n'
            + '已經有人綁過的會自動擋下、不會覆蓋。\n確定要執行嗎？'; },
        rowJob: function($tr){
            var $r = $tr.find('input[type=radio]:checked');
            if (!$r.length) return null;
            return { order_id: parseInt($tr.data('oid'), 10) || 0, item_id: parseInt($r.val(), 10) || 0 };
        },
        doneLabel: function($tr){ return $tr.find('input[type=radio]:checked').data('qn') || ''; },
        renderAuto: function(S){
            if (!S.auto.length) return '<div style="padding:14px;color:#a08a6a;">這個範圍內沒有可以自動綁定的訂單'
                + '（要嘛同料號有好幾筆報價、要嘛報價單裡沒有這個料號，請看另外兩個分頁）。</div>';
            var h = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
                  + '以下列出前 ' + S.sample.length + ' 筆做為抽樣確認，按「開始自動綁定報價單」會處理全部 <b>'
                  + S.auto.length + '</b> 張訂單。綁定寫的是訂單上的報價單號與報價明細，每一筆都會留下稽核紀錄。</div>'
                  + '<div class="ocq-ab-bar" style="display:none;"><i></i></div>'
                  + '<table class="ocq-ab-tb"><thead><tr><th>訂單</th><th>客戶</th><th>料號</th>'
                  + '<th>將綁定的報價單</th><th>報價日</th><th>單價</th><th>判定依據</th></tr></thead><tbody>';
            S.sample.forEach(function(r){
                h += '<tr><td>' + esc(r.order_oo) + '<div class="ocq-ab-sub">' + esc(r.order_date ? egFmtDate(r.order_date) : '') + '</div></td>'
                   + '<td>' + esc(r.client) + '</td><td>' + esc(r.part_no) + '</td>'
                   + '<td><b>' + esc(r.quote_no) + '</b></td>'
                   + '<td>' + esc(r.quote_date ? egFmtDate(r.quote_date) : '—') + '</td>'
                   + '<td>' + esc(r.price) + '</td>'
                   + '<td class="ocq-ab-why">' + esc(r.reason) + '</td></tr>';
            });
            return h + '</tbody></table>';
        },
        renderList: function(res, kind){
            if (!res.total) return '<div style="padding:14px;color:#a08a6a;">沒有這一類的訂單。</div>';
            if (kind === 'nomatch') {
                var hn = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
                       + '以下這幾張訂單的料號<b>在報價單裡找不到</b>（報價單上的料號文字要與訂單料號完全相同才綁得起來；'
                       + '草稿與待審核的報價單不列入）。請到報價單管理確認，或先在報價單上把料號綁好。</div>'
                       + '<table class="ocq-ab-tb"><thead><tr><th style="width:130px;">訂單</th><th style="width:110px;">客戶</th>'
                       + '<th>料號</th><th style="width:70px;">數量</th><th>說明</th></tr></thead><tbody>';
                (res.rows || []).forEach(function(r){
                    hn += '<tr><td>' + esc(r.order_oo) + '<div class="ocq-ab-sub">' + esc(r.order_date ? egFmtDate(r.order_date) : '') + '</div></td>'
                        + '<td>' + esc(r.client) + '</td><td>' + esc(r.part_no) + '</td><td>' + esc(r.order_qty) + '</td>'
                        + '<td class="ocq-ab-why">' + esc(r.reason) + '</td></tr>';
                });
                return hn + '</tbody></table>';
            }
            var h = '<div style="font-size:12.5px;color:#5b3a1e;margin-bottom:6px;">'
                  + '以下這幾張訂單的料號<b>有好幾筆報價明細</b>（不同報價單或調過價），請挑一筆再套用；'
                  + '挑好可以<b>逐筆按「套用」，也可以勾選起來一次全部套用</b>。'
                  + '候選依「<b>報價日不晚於訂單日、且日期最接近</b>」排序，第一筆通常就是下單當時的那份報價。</div>'
                  + ocqAbBulkBar('manual', true, 'quote', '套用勾選的（綁定已挑的報價）', '（挑好報價會自動幫你勾起來）')
                  + '<table class="ocq-ab-tb"><thead><tr><th class="ck"><input type="checkbox" class="ocq-ab-all" title="全選／全不選"></th>'
                  + '<th style="width:130px;">訂單</th><th style="width:140px;">料號／客戶</th>'
                  + '<th>候選報價明細（請挑一筆）</th><th style="width:60px;"></th></tr></thead><tbody>';
            (res.rows || []).forEach(function(r){
                h += '<tr data-oid="' + r.order_id + '"><td class="ck"><input type="checkbox" class="ocq-ab-ck"></td>'
                   + '<td>' + esc(r.order_oo) + '<div class="ocq-ab-sub">' + esc(r.order_date ? egFmtDate(r.order_date) : '')
                   + '　' + esc(r.order_qty) + ' 支</div></td>'
                   + '<td>' + esc(r.part_no) + '<div class="ocq-ab-sub">' + esc(r.client) + '</div></td>'
                   + '<td class="cnd">';
                (r.candidates || []).forEach(function(q, i){
                    h += '<label class="ocq-ab-cand"><input type="radio" name="abq_' + r.order_id + '" value="' + q.item_id + '" data-qn="' + esc(q.quote_no) + '">'
                       + '<b>' + esc(q.quote_no) + '</b>'
                       + (i === 0 ? ' <span style="color:#2f7a3f;">（建議：下單當時的報價）</span>' : '')
                       + '<span class="ocq-ab-sub">　報價日 ' + esc(q.quote_date ? egFmtDate(q.quote_date) : '—')
                       + '　單價 ' + esc(q.price) + '　數量 ' + esc(q.qty)
                       + (q.client ? '　' + esc(q.client) : '') + (q.spec ? '　' + esc(q.spec) : '') + '</span></label>';
                });
                if ((r.cand_total || 0) > (r.candidates || []).length) {
                    h += '<div class="ocq-ab-sub">另有 ' + (r.cand_total - r.candidates.length) + ' 筆報價沒列出來，'
                       + '要綁那幾筆請到對帳作業的「報價／訂單對照」處理。</div>';
                }
                h += '<div class="ocq-ab-why">' + esc(r.reason) + '</div></td>'
                   + '<td><button type="button" class="ocq-bind-btn ocq-sx-pick">套用</button></td></tr>';
            });
            return h + '</tbody></table>';
        }
    });
}

}
// 綁定欄裡的雙擊不要連帶觸發「帶入篩選」
$('#ocqTbody').on('dblclick', '.ocq-bind-td', function(e){ e.stopPropagation(); });

$('#btnClear').on('click', function(){
    // 原本這顆是「清成不限日期＝查全部」；現在「全部年份」已經有自己的按鈕在年份列上，
    // 所以這顆改成回到預設的近1年（否則按一下就會撞到單次查詢筆數上限，看起來像壞掉）。
    $('#fCustomer, #fSales, #fBom, #fVendor, #fQty, #fDelivery, #fKeyword').val('');
    var r = yRange1y(); $('#fDateFrom').val(r[0]); $('#fDateTo').val(r[1]);
    $('#fPriority').val('');
    $('#fBind').val('');
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
            var closedTxt = (r.date_is_derived == 1) ? (esc(egFmtDate(r.effective_date))+'(推算)') : esc(egFmtDate(r.closed_at, true));
            body += '<tr><td>'+esc(r.client_name_display||'')+'</td><td class="tl">'+esc(r.bom)+'（'+priLabel+'）</td>'
                  + '<td class="tl">'+esc(r.d_id||'')+'</td><td>'+esc(r.Qty||'')+'</td>'
                  + '<td>'+(r.deliv_list||[]).map(function(d){return esc(egFmtDate(d.date));}).join('<br>')+'</td><td>'+esc(r.sales_name||'')+'</td>'
                  + '<td>'+closedTxt+'</td>';
            var procs = r.processes || [];
            for (var pi=0; pi<maxProc; pi++){
                var p = procs[pi];
                body += '<td class="tl">' + (p ? esc((p.process_no||'')+(p.ProcessName?' '+p.ProcessName:'')+(p.maker_id?'/'+p.maker_id:'')+(p.return_date?'/'+egFmtDate(p.return_date)+' 回':'')) : '') + '</td>';
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
        if (f.bind) critParts.push('綁定狀態：'+$('#fBind option:selected').text());
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
                + '<td>'+esc(egFmtDate(r.closed_at, true))+'</td><td>'+r.duration_days+' 天</td></tr></tbody></table>';
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
