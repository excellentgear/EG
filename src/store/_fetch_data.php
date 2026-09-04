<?php
// ✅【OOM 防護】display_errors=0 確保錯誤不污染 JSON；錯誤由 PHP error_log 記錄
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
// ✅【記憶體限制】256M 通常足夠；若仍超限請優化 SQL，勿盲目加大
ini_set('memory_limit', '256M');
set_time_limit(300); // 允許最多 5 分鐘，避免大資料集 fetchAll 超時

// c:\MAMP\htdocs\EGsystem\src/store/_fetch_data.php
// --- 強制清除所有舊緩衝並關閉壓縮 ---
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start();

include("../common/_config.php");
include_once '../common/DBConnection.php';



header('Content-Type: application/json');

$conn = new DBConnection();
$db = $conn->getPDO(); // Get PDO instance for prepared statements

// --- 階段 A — 快速改善：後端分頁 ---
// 1. 獲取分頁參數，但如果 fetchAll=1 則忽略
$limitClause = "";
$fetchAll = isset($_GET['fetchAll']) && $_GET['fetchAll'] == '1';

if (!$fetchAll) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    // ✅【錯誤修正與強化】: 確保 $perPage 是一個有效的正整數，避免 SQL 錯誤
    $perPage = isset($_GET['perPage']) ? (int)$_GET['perPage'] : 10;
    if ($perPage < 1) { $perPage = 10; } // 如果傳入無效值，則使用預設值
    $offset = ($page - 1) * $perPage;
    $limitClause = "LIMIT :limit OFFSET :offset";
}


// ✅ 根據 GET 參數決定排序方式
$orderByClause = "ORDER BY vw.Created_At_end DESC, CAST(SUBSTRING_INDEX(b.bom, '-', -1) AS UNSIGNED) ASC";
if (isset($_GET['sort_by_qc_date']) && $_GET['sort_by_qc_date'] == '1') {
    // 用 bi.QC_check_date（已經有在 SELECT 裡），DESC 排序，NULL 在後面
    $orderByClause = "ORDER BY bi.QC_check_date DESC, vw.Created_At_end DESC, CAST(SUBSTRING_INDEX(b.bom, '-', -1) AS UNSIGNED) ASC";
}

// 2. 組合基礎 SQL 查詢 (FROM...JOIN...WHERE)
$baseSql = "
    FROM bom b
    LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
    -- 優先：透過 d_setting.Customer_Id → customer_list（有綁定料號時使用）
    LEFT JOIN customer_list cl_ds ON cl_ds.customer_id = ds.Customer_Id
    -- 備用：透過 bom.Client_Name 比對客戶名稱（未綁定料號時使用）
    LEFT JOIN customer_list cl ON cl.customer = b.Client_Name
    -- 業務查詢：優先用 cl_ds（綁定料號），否則用 cl（名稱比對）
    LEFT JOIN customer_sales cs_primary ON cs_primary.customer_id = COALESCE(cl_ds.customer_id, cl.customer_id) AND cs_primary.is_active = 1 AND cs_primary.role = 'primary'
    LEFT JOIN user u_sales_primary ON u_sales_primary.id = cs_primary.user_id
    LEFT JOIN customer_sales cs_deputy ON cs_deputy.customer_id = COALESCE(cl_ds.customer_id, cl.customer_id) AND cs_deputy.is_active = 1 AND cs_deputy.role = 'deputy'
    LEFT JOIN user u_sales_deputy ON u_sales_deputy.id = cs_deputy.user_id
    -- ⚠「目前製程」的認定必須與 OreadyReply_ForPm_BaseOfTime.php 首次載入的 bi 子查詢完全一致，
    --    否則 30 秒自動更新後畫面上的製程／廠商／進度／燈號會跟剛載入時不一樣（2026-08-27 修正）。
    --    兩條規則缺一不可：
    --    (1) 以「移轉日(outsource_date)最新的那一天」為準，未發單(NULL)的製程不列入比較
    --        ——舊版用 ROW_NUMBER 把 NULL 排在後面但仍會選中，導致只有客供料(未發包)的 BOM
    --          首次載入是空白、自動更新後卻冒出「客供料」並讓進度由 0% 跳成 33%。
    --    (2) 同一天有多個製程時要「全部合併」顯示（GROUP_CONCAT 以 / 分隔），不可只取其中一筆
    --        ——舊版只取一筆，導致「客供料/齒研」自動更新後被砍成「齒研」。
    LEFT JOIN (
        SELECT
            bi_grouped.bom,
            GROUP_CONCAT(DISTINCT bi_grouped.bom_ing_id ORDER BY bi_grouped.bom_sn) AS bom_ing_id,
            GROUP_CONCAT(DISTINCT bi_grouped.bom_sn ORDER BY bi_grouped.bom_sn) AS bom_sn,
            GROUP_CONCAT(DISTINCT pn2.process_type_id ORDER BY bi_grouped.bom_sn) AS pti,
            GROUP_CONCAT(DISTINCT pn2.ProcessName ORDER BY bi_grouped.bom_sn SEPARATOR '/') AS ProcessName,
            GROUP_CONCAT(DISTINCT ml2.maker_id ORDER BY bi_grouped.bom_sn SEPARATOR '/') AS maker_name,
            GROUP_CONCAT(DISTINCT bi_grouped.process_no ORDER BY bi_grouped.bom_sn) AS process_no,
            GROUP_CONCAT(DISTINCT bi_grouped.bom_ing_fid ORDER BY bi_grouped.bom_sn) AS bom_ing_fid,
            GROUP_CONCAT(DISTINCT bi_grouped.ps ORDER BY bi_grouped.bom_sn) AS ps,
            GROUP_CONCAT(DISTINCT bi_grouped.sqty ORDER BY bi_grouped.bom_sn) AS sqty,
            GROUP_CONCAT(DISTINCT bi_grouped.QC_check ORDER BY bi_grouped.bom_sn) AS QC_check,
            GROUP_CONCAT(DISTINCT bi_grouped.QC_ps ORDER BY bi_grouped.bom_sn) AS QC_ps,
            GROUP_CONCAT(DISTINCT bi_grouped.single_bet_ps ORDER BY bi_grouped.bom_sn) AS single_bet_ps,
            MAX(bi_grouped.outsource_date)  AS outsource_date,
            MAX(bi_grouped.return_date)     AS return_date,
            MAX(bi_grouped.QC_check_date)   AS QC_check_date,
            MAX(bi_grouped.maker_id_no)     AS maker_id_no,
            MAX(bi_grouped.Created_At)      AS Created_At,
            MAX(bi_grouped.qc_completed)    AS qc_completed,
            MAX(bi_grouped.qc_completed_at) AS qc_completed_at,
            SUBSTRING_INDEX(GROUP_CONCAT(bi_grouped.processing_state ORDER BY bi_grouped.bom_sn), ',', 1) AS processing_state
        FROM bom_ing bi_grouped
        INNER JOIN ( -- 每個 bom 最新的一個「有發單日」的日期
            SELECT bom, MAX(DATE(outsource_date)) AS max_effective_date
            FROM bom_ing
            WHERE processing_state IN ('Q', 'P', 'ing', 'E')
              AND outsource_date IS NOT NULL
              AND is_schedule_split = 0
            GROUP BY bom
        ) bi_latest ON bi_grouped.bom = bi_latest.bom
                   AND bi_grouped.outsource_date IS NOT NULL
                   AND DATE(bi_grouped.outsource_date) = bi_latest.max_effective_date
        LEFT JOIN process_no pn2 ON pn2.ProcessNo = bi_grouped.process_no
        LEFT JOIN maker_list ml2 ON ml2.maker_id_no = bi_grouped.maker_id_no
        WHERE bi_grouped.processing_state IN ('Q', 'P', 'ing', 'E') AND bi_grouped.is_schedule_split = 0
        GROUP BY bi_grouped.bom
    ) bi ON bi.bom = b.bom
    -- bom_ing_fid 現在可能是「多個製程的清單」，故比照首次載入改用 FIND_IN_SET
    LEFT JOIN vw_vw_oreadyreply_forpm vw ON FIND_IN_SET(vw.bom_ing_id, bi.bom_ing_fid)
    LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
    LEFT JOIN (
        SELECT d_id, SUM(Open_Qty) AS total_open_qty
        FROM order_track
        WHERE (Order_status IS NULL OR Order_status <> '9') AND Open_Qty > 0
        GROUP BY d_id
    ) unshipped_orders ON unshipped_orders.d_id = b.d_id
    WHERE b.d_id <> ''
      AND b.processing_state IS NULL
";

try { // ✅ 建議：使用 try-catch 捕捉所有資料庫操作的錯誤
    // 3. 執行 COUNT 查詢以獲取總筆數
    $countSql = "SELECT COUNT(DISTINCT b.bom) " . $baseSql;
    $totalRecords = $db->query($countSql)->fetchColumn();

    // 4. 組合獲取分頁資料的完整 SQL
    $dataSql = "SELECT
        b.bom,
        b.bom_ps                AS bom_ALL_bom_ps,
        b.priority_type,
        bi.bom_sn               AS current_bom_sn,
        bi.bom_sn               AS bom_sn,
        bi.bom_ing_id           AS bom_ing_id,
        (
            SELECT bi_first.QC_check
            FROM bom_ing bi_first
            WHERE bi_first.bom = b.bom
              AND bi_first.processing_state IN ('Q', 'P', 'ing', 'E')
            ORDER BY CAST(bi_first.bom_sn AS UNSIGNED) ASC
            LIMIT 1
        ) AS first_process_QC_check,
        b.processing_state      AS bom_processing_state,
        bi.processing_state,
        b.sqty                  AS Qty,
        b.d_id,
        b.d_setting_id,
        COALESCE(ds.D_Setting_Id, b.d_id, '') AS d_display,
        COALESCE(ds.Drawing_No, '') AS d_drawing_no,
        b.Delivery_date,
        b.Client_Name           AS Client_Name_Full,
        SUBSTRING(REPLACE(b.Client_Name, ' ', ''), 1, 3) AS Client_Name,
        COALESCE(cl_ds.customer, b.Client_Name)           AS client_name_display,
        COALESCE(cl_ds.customer_id, cl.customer_id, '')   AS d_customer_id,
        bi.process_no,
        bi.bom_ing_fid,
        bi.ps,
        bi.single_bet_ps        AS bom_bom_ps,
        bi.ProcessName,
        bi.pti,
        bi.maker_name           AS maker_id,
        -- ⚠ 廠商代號清單只能取「目前製程」(移轉日最新的那一批)，
        --    否則自動更新後會比首次載入多出舊製程的廠商代號，導致用代號片段搜尋跳出無關廠商。
        --    條件需與 OreadyReply_ForPm_BaseOfTime.php 首次載入的 bi 子查詢一致。
        (SELECT GROUP_CONCAT(DISTINCT CAST(bi_ml.maker_id_no AS CHAR) ORDER BY bi_ml.bom_sn SEPARATOR '/')
         FROM bom_ing bi_ml
         WHERE bi_ml.bom = b.bom
           AND bi_ml.processing_state IN ('Q', 'P', 'ing', 'E')
           AND bi_ml.is_schedule_split = 0
           AND bi_ml.outsource_date IS NOT NULL
           AND DATE(bi_ml.outsource_date) = (
                SELECT MAX(DATE(bi_ml2.outsource_date))
                FROM bom_ing bi_ml2
                WHERE bi_ml2.bom = b.bom
                  AND bi_ml2.processing_state IN ('Q', 'P', 'ing', 'E')
                  AND bi_ml2.is_schedule_split = 0
                  AND bi_ml2.outsource_date IS NOT NULL
           )
        ) AS maker_id_no_list,
        bi.sqty                 AS bom_ing_sqty,
        COALESCE(
            (SELECT bopm_first.order_id FROM bom_order_process_map bopm_first WHERE bopm_first.bom = b.bom ORDER BY bopm_first.id ASC LIMIT 1),
            b.o_order_id
        ) AS Order_id,
        (SELECT GROUP_CONCAT(CONCAT(bopm_all.order_id, ':', COALESCE(bopm_all.allocated_qty, 0), ':', COALESCE(ot_all.Order_oo, '')) SEPARATOR ';')
         FROM bom_order_process_map bopm_all
         LEFT JOIN order_track ot_all ON bopm_all.order_id = ot_all.Order_id
         WHERE bopm_all.bom = b.bom
        ) AS bound_orders_info,
        vw.OreadyReply_id,
        vw.oready_sqty_total,
        vw.ng_sqty_total,
        vw.Created_At_end,
        bi.QC_check,
        bi.QC_ps,
        CONCAT(DATE_FORMAT(vw.Created_At_end, '%y'), 'y/', DATE_FORMAT(vw.Created_At_end, '%c/%e')) AS Created_At_s,
        CONCAT(DATE_FORMAT(bi.Created_At, '%y'), 'y/', DATE_FORMAT(bi.Created_At, '%c/%e')) AS Created_At_bi,
        DATE_FORMAT(bi.outsource_date, '%Y/%m/%d')  AS outsource_date,
        DATE_FORMAT(bi.return_date, '%Y/%m/%d')     AS return_date,
        DATE_FORMAT(bi.QC_check_date, '%Y/%m/%d')   AS QC_check_date,
        bi.qc_completed,
        DATE_FORMAT(bi.qc_completed_at, '%Y/%m/%d') AS qc_completed_at,
        ml.m_tel, ml.m_fax, ml.m_tel2, ml.factory_address, ml.contact_person, ml.contact_title,
        IFNULL(unshipped_orders.total_open_qty, 0)  AS unshipped_qty,
        u_sales_primary.id          AS PrimarySalesId,
        u_sales_primary.user_cname  AS PrimarySalesName,
        u_sales_deputy.id           AS DeputySalesId,
        u_sales_deputy.user_cname   AS DeputySalesName "
        . $baseSql . " " . $orderByClause . " " . $limitClause;
    
    $stmt = $db->prepare($dataSql);
    if (!$stmt) {
        // ✅ 建議：檢查 prepare 是否成功
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'SQL 預備語句失敗。', 'error' => $db->errorInfo()]);
        exit;
    }

    if (!$fetchAll) {
        // ✅ 建議：確保綁定變數為整數
        $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $OreadyReply_list_base = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) { // ✅ 建議：捕捉 PDO 例外
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '資料庫查詢失敗。',
        'error' => $e->getMessage() // 將詳細錯誤訊息回傳給前端
    ]);
    exit;
}

// --- START: Logic to add OrderList and qq_details to ensure data consistency with initial load ---
$data_to_return = [];
if (is_array($OreadyReply_list_base)) {
    // 預先一次性獲取所有相關的訂單資料
    $order_list_map = [];
    $all_d_ids_for_orders = array_column($OreadyReply_list_base, 'd_id');
    $all_d_ids_for_orders = array_filter(array_unique($all_d_ids_for_orders));

    if (!empty($all_d_ids_for_orders)) {
        $placeholders_orders = implode(',', array_fill(0, count($all_d_ids_for_orders), '?'));
        $sql_order_list = "
            SELECT ot.d_id, ot.Order_id, ot.Order_oo,
                   DATE_FORMAT(ot.Delivery_date, '%Y-%m-%d') AS Delivery_date,
                   ot.Qty, ot.Open_Qty,
                   COALESCE(ot.Specification,'') AS Specification
            FROM order_track ot
            WHERE ot.d_id IN ($placeholders_orders)
              AND (ot.Order_status IS NULL OR ot.Order_status <> '9')
            ORDER BY ot.d_id, ot.Delivery_date ASC
        ";
        try {
            $stmt_order_list = $db->prepare($sql_order_list);
            $stmt_order_list->execute(array_values($all_d_ids_for_orders));
            $order_list_raw = $stmt_order_list->fetchAll(PDO::FETCH_ASSOC);

            foreach ($order_list_raw as $order) {
                $order_list_map[$order['d_id']][] = $order;
            }
        } catch (PDOException $e) {
            error_log("Error fetching order_list in _fetch_data.php: " . $e->getMessage());
        }
    }

    try {
        $all_boms = array_column($OreadyReply_list_base, 'bom');
        $all_boms = array_filter(array_unique($all_boms));

        $qq_details_map = [];
        if (!empty($all_boms)) {
            $placeholders = implode(',', array_fill(0, count($all_boms), '?'));
            $sql_qq_details = "
                SELECT
                    bi.bom,
                    qc.bom_ing_fid_ref,
                    DATE_FORMAT(qc.QC_check_date, '%c/%e') AS qc_date_formatted, qc.QC_check_date,
                    qc.QC_QQ_sqty,
                    qc.QC_ps,
                    bi.bom_sn, pn.ProcessName,
                    bi.QC_ps AS bQC_ps, bi.QC_ps2 AS bQC_ps2
                FROM QC_check AS qc
                LEFT JOIN bom_ing AS bi ON qc.bom_ing_fid_ref = bi.bom_ing_fid
                LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                WHERE bi.bom IN ($placeholders) AND qc.QC_check = 'QQ'
                ORDER BY bi.bom, bi.bom_sn DESC, qc.QC_check_date DESC
            ";
            $stmt_qq_details = $db->prepare($sql_qq_details);
            $stmt_qq_details->execute(array_values($all_boms));
            $qq_details_raw = $stmt_qq_details->fetchAll(PDO::FETCH_ASSOC);

            foreach ($qq_details_raw as $detail) {
                $qq_details_map[$detail['bom']][] = $detail;
            }
        }

        $ok_details_map = [];
        if (!empty($all_boms)) {
            $sql_ok_details = "
                SELECT
                    bi.bom,
                    qc.bom_ing_fid_ref,
                    qc.QC_check_date,
                    DATE_FORMAT(qc.QC_check_date, '%c/%e') AS qc_date_formatted,
                    qc.QC_ok_sqty, qc.QC_ps_ok,
                    bi.bom_sn, pn.ProcessName, bi.QC_ps AS bQC_ps, bi.QC_ps2 AS bQC_ps2
                FROM QC_check AS qc
                LEFT JOIN bom_ing AS bi ON qc.bom_ing_fid_ref = bi.bom_ing_fid
                LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                WHERE bi.bom IN ($placeholders) AND qc.QC_check = 'ok'
                ORDER BY bi.bom, bi.bom_sn DESC, qc.QC_check_date DESC
            ";
            $stmt_ok_details = $db->prepare($sql_ok_details);
            $stmt_ok_details->execute(array_values($all_boms));
            $ok_details_raw = $stmt_ok_details->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ok_details_raw as $detail) {
                $ok_details_map[$detail['bom']][] = $detail;
            }
        }

        // ── QC 燈號彙總（各 BOM 的 QQ/ok/ng/aod 數量加總，供 AJAX 刷新後正確顯示燈號）──
        $qc_summary_map = [];
        if (!empty($all_boms)) {
            try {
                $ph_qcs = implode(',', array_fill(0, count($all_boms), '?'));
                $stmt_qcs = $db->prepare("
                    SELECT bi.bom,
                        SUM(CASE WHEN qc.QC_check='QQ'  THEN IFNULL(qc.QC_QQ_sqty,  0) ELSE 0 END) AS qc_qq_qty,
                        SUM(CASE WHEN qc.QC_check='ok'  THEN IFNULL(qc.QC_ok_sqty,  0) ELSE 0 END) AS qc_ok_qty,
                        SUM(CASE WHEN qc.QC_check='ng'  THEN IFNULL(qc.QC_ng_sqty,  0) ELSE 0 END) AS qc_ng_qty,
                        SUM(CASE WHEN qc.QC_check='AOD' THEN IFNULL(qc.QC_aod_sqty, 0) ELSE 0 END) AS qc_aod_qty
                    FROM qc_check qc
                    JOIN bom_ing bi ON qc.bom_ing_fid_ref = bi.bom_ing_fid
                    WHERE bi.bom IN ($ph_qcs)
                    GROUP BY bi.bom
                ");
                $stmt_qcs->execute(array_values($all_boms));
                foreach ($stmt_qcs->fetchAll(PDO::FETCH_ASSOC) as $_qcs) {
                    $qc_summary_map[$_qcs['bom']] = $_qcs;
                }
            } catch (PDOException $e) { error_log('_fetch_data qc_summary_map: ' . $e->getMessage()); }
        }

        // ✅ 修正：一次性獲取出貨紀錄並正確放入回傳資料（原版未將 shipment_history 放入 $item）
        $shipment_history_map = [];
        $all_d_ids = array_column($OreadyReply_list_base, 'd_id');
        $all_d_ids = array_filter(array_unique($all_d_ids));

        if (!empty($all_d_ids)) {
            $placeholders_shipment = implode(',', array_fill(0, count($all_d_ids), '?'));
            $sql_shipment_history = "
                SELECT
                    il.Product_id,
                    il.Qty, il.Specification,
                    DATE_FORMAT(il.Order_date, '%m/%d') AS formatted_date,
                    DATE_FORMAT(il.Order_date, '%Y-%m-%d') AS shipment_iso_date,
                    il.Order_date
                FROM is_list il
                WHERE il.Product_id IN ({$placeholders_shipment})
                ORDER BY il.Product_id, il.Order_date DESC
            ";
            $stmt_shipment_history = $db->prepare($sql_shipment_history);
            $stmt_shipment_history->execute(array_values($all_d_ids));
            foreach ($stmt_shipment_history->fetchAll(PDO::FETCH_ASSOC) as $shipment) {
                $shipment_history_map[$shipment['Product_id']][] = $shipment;
            }
        }

        // ✅【業務休假狀態】與主頁面邏輯一致，用於 IsPrimaryOnLeave / IsDeputyOnLeave
        $users_on_leave_ids = [];
        try {
            $today_str = date('Y-m-d');
            $stmt_param = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'ALL' AND param_key = 'day_off'");
            $stmt_param->execute();
            $param_row = $stmt_param->fetch(PDO::FETCH_ASSOC);
            $day_off_ids = [];
            if ($param_row && !empty($param_row['param_value'])) {
                $decoded = json_decode($param_row['param_value'], true);
                if (is_array($decoded)) $day_off_ids = $decoded;
                elseif (is_numeric($decoded)) $day_off_ids = [$decoded];
            }
            if (!empty($day_off_ids)) {
                $day_off_ids = array_filter(array_map('intval', $day_off_ids));
                if (!empty($day_off_ids)) {
                    $in_clause = implode(',', $day_off_ids);
                    $stmt_leave = $db->prepare("
                        SELECT DISTINCT u.id FROM evenement_actor ea
                        JOIN evenement e ON ea.event_id = e.id
                        JOIN user u ON u.id = ea.user_id
                        WHERE e.category_id IN ($in_clause) AND :today BETWEEN DATE(e.start) AND DATE(e.end)
                    ");
                    $stmt_leave->execute([':today' => $today_str]);
                    $users_on_leave_ids = array_map('intval', $stmt_leave->fetchAll(PDO::FETCH_COLUMN));
                }
            }
        } catch (Exception $e) { error_log("_fetch_data users_on_leave: " . $e->getMessage()); }

        // ✅【報工資訊修正】補齊 pm_has_report / pm_total_processed / pm_total_ng 欄位
        // 主頁面初始載入有這段邏輯，但 _fetch_data.php 原本沒有，導致自動刷新後報工資訊消失
        $pm_report_map   = [];
        $pm_schedule_map = [];
        $finished_fids_set = [];
        $bom_all_fids_map  = [];
        $max_reported_sn_map = [];

        $all_boms_for_pm = array_values(array_filter(array_unique(array_column($OreadyReply_list_base, 'bom'))));

        if (!empty($all_boms_for_pm)) {
            $ph_pm = implode(',', array_fill(0, count($all_boms_for_pm), '?'));

            // 報工良品與NG加總
            try {
                $stmt_pm = $db->prepare("
                    SELECT pdr.bom_ing_fid,
                           MAX(COALESCE(pdr.production_start_time, pdr.setup_start_time, pdr.report_date)) AS latest_date,
                           SUM(COALESCE(pdr.produced_qty, 0)) AS total_good,
                           (
                               SELECT COALESCE(SUM(ng.ng_qty), 0)
                               FROM pm_process_daily_ng ng
                               JOIN pm_process_daily_report pdr2 ON ng.report_id = pdr2.report_id
                               WHERE pdr2.bom_ing_fid = pdr.bom_ing_fid
                           ) AS total_ng
                    FROM pm_process_daily_report pdr
                    JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
                    WHERE bi.bom IN ($ph_pm)
                    GROUP BY pdr.bom_ing_fid
                ");
                $stmt_pm->execute(array_values($all_boms_for_pm));
                foreach ($stmt_pm->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $pm_report_map[$r['bom_ing_fid']] = $r;
                }
            } catch (PDOException $e) { error_log("_fetch_data pm_report_map: " . $e->getMessage()); }

            // 排程順位
            try {
                $stmt_sched = $db->prepare("
                    SELECT ps.bom_ing_fid, ps.schedule_order
                    FROM pm_process_schedule ps
                    JOIN bom_ing bi ON ps.bom_ing_fid = bi.bom_ing_fid
                    WHERE bi.bom IN ($ph_pm)
                ");
                $stmt_sched->execute(array_values($all_boms_for_pm));
                foreach ($stmt_sched->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $pm_schedule_map[$r['bom_ing_fid']] = $r['schedule_order'];
                }
            } catch (PDOException $e) { error_log("_fetch_data pm_schedule_map: " . $e->getMessage()); }

            // ── 每個 bom 最新一筆報工備註（key=bom，跨製程，與主頁面邏輯一致）──
            $latest_report_info_map = [];
            try {
                $sql_latest = "
                    SELECT
                        bi.bom,
                        pdr.report_id,
                        pdr.remark AS report_remark,
                        pn.ProcessName AS report_process_name,
                        COALESCE(pdr.production_start_time, pdr.setup_start_time, pdr.report_date) AS report_activity_time
                    FROM pm_process_daily_report pdr
                    JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
                    LEFT JOIN process_no pn ON pdr.process_no = pn.ProcessNo
                    INNER JOIN (
                        SELECT bi2.bom, MAX(pdr2.report_id) AS max_rid
                        FROM pm_process_daily_report pdr2
                        JOIN bom_ing bi2 ON pdr2.bom_ing_fid = bi2.bom_ing_fid
                        WHERE bi2.bom IN ($ph_pm)
                        GROUP BY bi2.bom
                    ) lr ON pdr.report_id = lr.max_rid
                ";
                $stmt_latest = $db->prepare($sql_latest);
                $stmt_latest->execute(array_values($all_boms_for_pm));
                $latest_rows = $stmt_latest->fetchAll(PDO::FETCH_ASSOC);
                // 補查最後一筆報工的 NG 明細
                $latest_rids = array_column($latest_rows, 'report_id');
                $latest_ng_raw = [];
                if (!empty($latest_rids)) {
                    $ph_rids = implode(',', array_fill(0, count($latest_rids), '?'));
                    $stmt_ng2 = $db->prepare("
                        SELECT ng.report_id, ng.ng_qty, nt.ng_txt, ng.ng_remark
                        FROM pm_process_daily_ng ng
                        LEFT JOIN ng_txt nt ON ng.ng_id = nt.ng_id
                        WHERE ng.report_id IN ($ph_rids)
                    ");
                    $stmt_ng2->execute($latest_rids);
                    foreach ($stmt_ng2->fetchAll(PDO::FETCH_ASSOC) as $ngr) {
                        $latest_ng_raw[$ngr['report_id']][] = $ngr;
                    }
                }
                foreach ($latest_rows as $lr) {
                    $rid = $lr['report_id'];
                    $ng_parts = [];
                    if (isset($latest_ng_raw[$rid])) {
                        foreach ($latest_ng_raw[$rid] as $n) {
                            $ng_parts[] = $n['ng_qty'] . ':::' . (!empty($n['ng_txt']) ? $n['ng_txt'] : '其它') . ':::' . ($n['ng_remark'] ?? '');
                        }
                    }
                    $latest_report_info_map[$lr['bom']] = [
                        'report_remark'       => $lr['report_remark'],
                        'report_process_name' => $lr['report_process_name'],
                        'report_activity_time' => $lr['report_activity_time'],
                        'ng_info'             => implode('|', $ng_parts),
                    ];
                }
            } catch (PDOException $e) { error_log("_fetch_data latest_report_info error: " . $e->getMessage()); }

            // ── 累計 NG 明細（key=bom_ing_fid，含 ng_txt 原因與備註）──
            $ng_by_fid = [];
            try {
                $sql_ng_by_fid = "
                    SELECT
                        pdr.bom_ing_fid, bi.bom, pn.ProcessName,
                        IFNULL(nt.ng_txt, '其它')  AS ng_reason,
                        SUM(ng.ng_qty)             AS ng_qty_sum,
                        GROUP_CONCAT(
                            DISTINCT NULLIF(TRIM(ng.ng_remark), '')
                            ORDER BY ng.ng_remark SEPARATOR ' / '
                        ) AS ng_remark_agg
                    FROM pm_process_daily_ng ng
                    LEFT JOIN ng_txt nt ON ng.ng_id = nt.ng_id
                    JOIN pm_process_daily_report pdr ON ng.report_id = pdr.report_id
                    JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
                    LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                    WHERE bi.bom IN ($ph_pm)
                    GROUP BY pdr.bom_ing_fid, bi.bom, pn.ProcessName, IFNULL(nt.ng_txt, '其它')
                    ORDER BY pdr.bom_ing_fid, ng_qty_sum DESC
                ";
                $stmt_ng_fid = $db->prepare($sql_ng_by_fid);
                $stmt_ng_fid->execute(array_values($all_boms_for_pm));
                foreach ($stmt_ng_fid->fetchAll(PDO::FETCH_ASSOC) as $ngr) {
                    $fid = $ngr['bom_ing_fid'];
                    $part = $ngr['ng_qty_sum'] . ':::' . $ngr['ng_reason'] . ':::' . ($ngr['ng_remark_agg'] ?? '');
                    if (!isset($ng_by_fid[$fid])) {
                        $ng_by_fid[$fid] = ['bom' => $ngr['bom'], 'ProcessName' => $ngr['ProcessName'], 'parts' => []];
                    }
                    $ng_by_fid[$fid]['parts'][] = $part;
                }
            } catch (PDOException $e) { error_log("_fetch_data ng_by_fid error: " . $e->getMessage()); }

            // 所有製程 fid（用於完工判斷）
            try {
                $stmt_fids = $db->prepare("
                    SELECT bi.bom, bi.bom_ing_fid
                    FROM bom_ing bi
                    WHERE bi.bom IN ($ph_pm)
                    ORDER BY bi.bom, CAST(bi.bom_sn AS UNSIGNED)
                ");
                $stmt_fids->execute(array_values($all_boms_for_pm));
                foreach ($stmt_fids->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $bom_all_fids_map[$r['bom']][] = $r['bom_ing_fid'];
                }
            } catch (PDOException $e) { error_log("_fetch_data bom_all_fids_map: " . $e->getMessage()); }

            // 有完工報工（is_finished=1）的 fid 集合
            try {
                $stmt_fin = $db->prepare("
                    SELECT pdr.bom_ing_fid
                    FROM pm_process_daily_report pdr
                    JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
                    WHERE bi.bom IN ($ph_pm)
                    GROUP BY pdr.bom_ing_fid
                    HAVING MAX(COALESCE(pdr.is_finished, 0)) = 1
                ");
                $stmt_fin->execute(array_values($all_boms_for_pm));
                foreach ($stmt_fin->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $finished_fids_set[$r['bom_ing_fid']] = true;
                }
            } catch (PDOException $e) { error_log("_fetch_data finished_fids_set: " . $e->getMessage()); }

            // 有新製程報工的最大 bom_sn
            try {
                $stmt_maxsn = $db->prepare("
                    SELECT bi.bom, MAX(CAST(bi.bom_sn AS UNSIGNED)) AS max_sn
                    FROM pm_process_daily_report pdr
                    JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
                    WHERE bi.bom IN ($ph_pm)
                    GROUP BY bi.bom
                ");
                $stmt_maxsn->execute(array_values($all_boms_for_pm));
                foreach ($stmt_maxsn->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $max_reported_sn_map[$r['bom']] = intval($r['max_sn']);
                }
            } catch (PDOException $e) { error_log("_fetch_data max_reported_sn_map: " . $e->getMessage()); }
        }

        foreach ($OreadyReply_list_base as $item) {
            $item['qq_details']        = $qq_details_map[$item['bom']] ?? [];
            $item['ok_details']        = $ok_details_map[$item['bom']] ?? [];
            $_qcs = $qc_summary_map[$item['bom']] ?? [];
            $item['qc_qq_qty']  = (float)($_qcs['qc_qq_qty']  ?? 0);
            $item['qc_ok_qty']  = (float)($_qcs['qc_ok_qty']  ?? 0);
            $item['qc_ng_qty']  = (float)($_qcs['qc_ng_qty']  ?? 0);
            $item['qc_aod_qty'] = (float)($_qcs['qc_aod_qty'] ?? 0);
            $item['qc_total']   = $item['qc_qq_qty'] + $item['qc_ok_qty'] + $item['qc_ng_qty'] + $item['qc_aod_qty'];
            // ✅ 關鍵修正：將 shipment_history 加入回傳，讓 10 秒刷新後客戶欄位仍能顯示最近出貨
            $item['shipment_history']  = $shipment_history_map[$item['d_id']] ?? [];
            $item['OrderList']         = $order_list_map[$item['d_id']] ?? [];

            // ✅【報工欄位計算】與主頁面邏輯一致
            $pm_latest_date         = null;
            $pm_total_good          = 0;
            $pm_total_ng            = 0;
            $pm_has_report          = false;
            $pm_first_schedule_order = null;

            if (!empty($item['bom_ing_fid'])) {
                $fids = explode(',', $item['bom_ing_fid']);
                if (isset($fids[0])) {
                    $first_fid = trim($fids[0]);
                    $pm_first_schedule_order = $pm_schedule_map[$first_fid] ?? null;
                }
                // 使用 bom_all_fids_map 涵蓋所有製程（含非目前製程的報工）
                $all_fids_for_pm = !empty($bom_all_fids_map[$item['bom']]) ? $bom_all_fids_map[$item['bom']] : $fids;
                foreach ($all_fids_for_pm as $fid_val) {
                    $fid_val = trim($fid_val);
                    if (isset($pm_report_map[$fid_val])) {
                        $rpt = $pm_report_map[$fid_val];
                        $pm_has_report  = true;
                        $pm_total_good += (float)$rpt['total_good'];
                        $pm_total_ng   += (float)$rpt['total_ng'];
                        if ($pm_latest_date === null || $rpt['latest_date'] > $pm_latest_date) {
                            $pm_latest_date = $rpt['latest_date'];
                        }
                    }
                }
            }
            $item['pm_has_report']       = $pm_has_report;
            $item['pm_latest_date']      = $pm_latest_date;
            $item['pm_total_processed']  = $pm_total_good + $pm_total_ng;
            $item['pm_total_ng']         = $pm_total_ng;
            $item['pm_schedule_order']   = $pm_first_schedule_order;

            // 是否所有製程都已完工
            $all_fids_of_bom  = $bom_all_fids_map[$item['bom']] ?? [];
            $pm_is_all_finished = false;
            if (!empty($all_fids_of_bom) && !empty($finished_fids_set)) {
                $pm_is_all_finished = true;
                foreach ($all_fids_of_bom as $check_fid) {
                    if (empty($finished_fids_set[$check_fid])) { $pm_is_all_finished = false; break; }
                }
            }
            $item['pm_is_all_finished'] = $pm_is_all_finished;

            // ── 填入最後一筆報工備註 + 目前製程 NG + 前置製程 NG ──
            $cur_fid = !empty($item['bom_ing_fid']) ? trim(explode(',', $item['bom_ing_fid'])[0]) : null;
            // latest_report_info_map key 是 bom（跨製程取最新）
            $l_rpt = $latest_report_info_map[$item['bom']] ?? null;
            $item['latest_report_remark']        = $l_rpt['report_remark']        ?? null;
            $item['latest_report_process']       = $l_rpt['report_process_name']  ?? null;
            $item['latest_report_activity_time'] = $l_rpt['report_activity_time'] ?? null;
            // NG 明細：優先用最新報工的 NG（跨製程），無則用目前製程累計 NG
            $item['latest_ng_info_str'] = ($l_rpt && !empty($l_rpt['ng_info']))
                ? $l_rpt['ng_info']
                : (($cur_fid && isset($ng_by_fid[$cur_fid])) ? implode('|', $ng_by_fid[$cur_fid]['parts']) : null);
            $prev_ng_list = [];
            $all_fids_ordered = $bom_all_fids_map[$item['bom']] ?? [];
            foreach ($all_fids_ordered as $prev_fid) {
                if ($prev_fid == $cur_fid || !isset($ng_by_fid[$prev_fid])) continue;
                $prev_ng_list[] = [
                    'process_name' => $ng_by_fid[$prev_fid]['ProcessName'] ?? '前關卡',
                    'ng_info_str'  => implode('|', $ng_by_fid[$prev_fid]['parts']),
                ];
            }
            $item['prev_process_ng_list'] = $prev_ng_list;

            // 有新製程報工判斷
            $current_sn = 0;
            if (!empty($item['bom_sn'])) {
                foreach (explode(',', $item['bom_sn']) as $s) {
                    $v = intval($s); if ($v > $current_sn) $current_sn = $v;
                }
            }
            $max_sn = $max_reported_sn_map[$item['bom']] ?? 0;
            $item['has_new_process_report'] = ($max_sn > $current_sn);
            $item['has_any_pm_report']      = isset($max_reported_sn_map[$item['bom']]);

            // ✅【業務休假】與主頁面一致
            $item['IsPrimaryOnLeave'] = !empty($item['PrimarySalesId']) && in_array(intval($item['PrimarySalesId']), $users_on_leave_ids, true);
            $item['IsDeputyOnLeave']  = !empty($item['DeputySalesId'])  && in_array(intval($item['DeputySalesId']),  $users_on_leave_ids, true);

            $data_to_return[] = $item;
        }
    } catch (PDOException $e) {
        error_log("Error fetching sub-details in _fetch_data.php: " . $e->getMessage());
        if (!empty($OreadyReply_list_base)) {
            foreach ($OreadyReply_list_base as $item) {
                if (!isset($item['qq_details']))       { $item['qq_details'] = []; }
                if (!isset($item['ok_details']))       { $item['ok_details'] = []; }
                if (!isset($item['shipment_history'])) { $item['shipment_history'] = []; }
                $item['OrderList'] = [];
                $data_to_return[] = $item;
            }
        }
        $data_to_return = $OreadyReply_list_base;
    }
}
// --- END ---

// ✅【記憶體優化】bom_ps_list 只查詢「當前頁面」的 BOM，避免全表掃描造成 OOM
// 1. 從已查出的 $data_to_return 取得本頁 BOM 清單
$current_page_boms = array_values(array_filter(array_unique(array_column($data_to_return, 'bom'))));

// 2. 計算最大製程數：只針對本頁 BOM 計算（全域值改為僅供版面參考，不影響顯示邏輯）
$bom_ps_list_max = 0;
$bom_ps_list = [];

if (!empty($current_page_boms)) {
    $ph_boms = implode(',', array_fill(0, count($current_page_boms), '?'));

    // max_bom_ing_count：只算本頁 BOM 的最大製程數
    $max_row = $db->prepare("
        SELECT MAX(bom_ing_count) AS max_bom_ing_count
        FROM (
            SELECT bi.bom, COUNT(DISTINCT bi.bom_sn) AS bom_ing_count
            FROM bom_ing bi
            WHERE bi.bom IN ($ph_boms)
              AND bi.is_schedule_split = 0 -- 與首次載入一致：拆批列不另算一欄，否則刷新後製程欄數會多出來
            GROUP BY bi.bom
        ) AS sub
    ");
    $max_row->execute(array_values($current_page_boms));
    $bom_ps_list_max = (int)($max_row->fetchColumn() ?? 0);

    // bom_ps_list：只查本頁 BOM 的製程明細
    $raw_ps = $db->prepare("
        SELECT bi.*, pn.ProcessName, pn.is_exclude_qc, ml.maker_id
        FROM bom_ing AS bi
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no
        WHERE bi.bom IN ($ph_boms)
          AND bi.is_schedule_split = 0
        ORDER BY bi.bom, bi.bom_sn
    ");
    $raw_ps->execute(array_values($current_page_boms));
    $raw_ps_rows = $raw_ps->fetchAll(PDO::FETCH_ASSOC);

    $ps_map             = [];
    $all_active_per_key = [];
    $all_split_per_key  = [];
    $fd = function($v) { return (!empty($v) && $v !== '0000-00-00 00:00:00') ? date('Y/m/d', strtotime($v)) : null; };
    foreach ($raw_ps_rows as $r) {
        $key = $r['bom'] . '_' . $r['bom_sn'];
        $batch_entry = [
            'batch_label'      => $r['batch_label'] ?? null,
            'sqty'             => $r['sqty'],
            'maker_id'         => $r['maker_id'] ?? '',
            'outsource_date'   => $fd($r['outsource_date']),
            'return_date'      => $fd($r['return_date']),
            'processing_state' => $r['processing_state'] ?? '',
            'is_consumed'      => (int)($r['is_consumed'] ?? 0),
            'QC_check'         => $r['QC_check'] ?? null,
            'qc_completed'     => (int)($r['qc_completed'] ?? 0),
            'QC_check_date'    => $fd($r['QC_check_date'] ?? null),
        ];
        // 活躍批次（is_consumed=0）→ 供 split_batches
        if (empty($r['is_consumed'])) {
            $all_active_per_key[$key][] = $batch_entry;
        }
        // 所有有 batch_label 的批次（含已消耗）→ 供 all_split_batches 歷史顯示
        if (!empty($r['batch_label'])) {
            $all_split_per_key[$key][] = $batch_entry;
        }
        $t = strtotime($r['Modified_At'] ?: $r['Created_At']);
        if (!isset($ps_map[$key]) || $t > strtotime($ps_map[$key]['Modified_At'] ?: $ps_map[$key]['Created_At'])) {
            $ps_map[$key] = $r;
        }
    }
    // 附加 split_batches 與 all_split_batches 到每筆代表記錄
    foreach ($ps_map as $key => &$entry) {
        $batches = $all_active_per_key[$key] ?? [];
        usort($batches, function($a, $b) {
            return strcmp((string)($a['batch_label'] ?? ''), (string)($b['batch_label'] ?? ''));
        });
        $entry['split_batches'] = $batches;

        $all_split = $all_split_per_key[$key] ?? [];
        usort($all_split, function($a, $b) {
            return strcmp((string)($a['batch_label'] ?? ''), (string)($b['batch_label'] ?? ''));
        });
        $entry['all_split_batches'] = $all_split;
    }
    unset($entry);
    $bom_ps_list = array_values($ps_map);
}

$all_process_types = $conn->getAll("SELECT ProcessNo, ProcessName FROM process_no ORDER BY ProcessNo");

if (ob_get_level()) ob_end_clean();

// ── transfer_price_map：每個 bom+bom_sn 取最新移轉單價（供前端費用加總用）──
$transfer_price_map = [];
if (!empty($current_page_boms)) {
    $ph_tp = implode(',', array_fill(0, count($current_page_boms), '?'));
    try {
        // 欄位需與頁面初載查詢一致（含日期/廠商/數量/備註），否則 AJAX 更新後「加工單價歷史」彈窗的本 BOM 列只剩單價
        $stmt_tp = $db->prepare("
            SELECT tl.bom, tl.bom_sn, tl.maker_from, tl.sqty, tl.transfer_date,
                   tl.price, tl.modified_unit_price, tl.note,
                   ml.maker_id AS maker_name
            FROM bom_ing_transfer_log tl
            INNER JOIN (
                SELECT bom, bom_sn, MAX(transfer_id) AS max_id
                FROM bom_ing_transfer_log
                WHERE bom IN ($ph_tp)
                GROUP BY bom, bom_sn
            ) latest ON tl.bom = latest.bom AND tl.bom_sn = latest.bom_sn AND tl.transfer_id = latest.max_id
            LEFT JOIN maker_list ml ON ml.maker_id_no = tl.maker_from
            WHERE tl.bom IN ($ph_tp)
        ");
        $stmt_tp->execute(array_merge(array_values($current_page_boms), array_values($current_page_boms)));
        foreach ($stmt_tp->fetchAll(PDO::FETCH_ASSOC) as $tp) {
            $transfer_price_map[$tp['bom']][$tp['bom_sn']] = $tp;
        }
    } catch (PDOException $e) { error_log('_fetch_data transfer_price_map error: ' . $e->getMessage()); }
}

// ing_active_map：用原始清單（含所有批次），過濾 is_consumed=0，保留 batch_label
$ing_active_map = [];
foreach ($raw_ps_rows as $_iam_item) {
    $_iam_state = $_iam_item['processing_state'] ?? '';
    $_iam_has_label = !empty($_iam_item['batch_label']);
    if (!in_array($_iam_state, ['Q', 'P', 'ing', 'E']) && !($_iam_state === 'N' && $_iam_has_label)) continue;
    if (!empty($_iam_item['is_consumed'])) continue;
    $_iam_bom = $_iam_item['bom'];
    $_iam_fmt = function($v) { return (!empty($v) && $v !== '0000-00-00 00:00:00') ? date('Y/m/d', strtotime($v)) : null; };
    $_iam_qcd = $_iam_item['QC_check_date'] ?? null;
    $ing_active_map[$_iam_bom][] = [
        'bom_sn'           => $_iam_item['bom_sn'],
        'batch_label'      => $_iam_item['batch_label'] ?? null,
        'bom_ing_id'       => $_iam_item['bom_ing_id'],
        'bom_ing_fid'      => $_iam_item['bom_ing_fid'],
        'process_no'       => $_iam_item['process_no'],
        'ProcessName'      => $_iam_item['ProcessName'] ?? '',
        'processing_state' => $_iam_state,
        'outsource_date'   => $_iam_fmt($_iam_item['outsource_date'] ?? null),
        'return_date'      => $_iam_fmt($_iam_item['return_date'] ?? null),
        'QC_check'         => $_iam_item['QC_check'] ?? null,
        'QC_check_date'    => !empty($_iam_qcd) && $_iam_qcd !== '0000-00-00 00:00:00' ? $_iam_fmt($_iam_qcd) : null,
        // 容器四欄必須與頁面初載（OreadyReply_ForPm_BaseOfTime.php 的 $bom_ing_active_map）
        // 完全一致，缺一欄就會出現「初載看得到容器、自動更新後容器消失」——
        // 生管回報的容器存在 pm_ps/pm_ps2，之前這裡只給 QC_ps，所以只有生管填過的那些
        // BOM（畫面上的 2E 等）在自動更新後整個不見（2026-09-04 使用者實測回報）。
        'QC_ps'            => $_iam_item['QC_ps'] ?? null,
        'QC_ps2'           => $_iam_item['QC_ps2'] ?? null,
        'pm_ps'            => $_iam_item['pm_ps'] ?? null,
        'pm_ps2'           => $_iam_item['pm_ps2'] ?? null,
        'qc_completed'     => (int)($_iam_item['qc_completed'] ?? 0),
        'qc_completed_at'  => $_iam_fmt($_iam_item['qc_completed_at'] ?? null),
        'maker_id'         => $_iam_item['maker_id'] ?? '',
        'maker_id_no'      => $_iam_item['maker_id_no'] ?? null,
        'sqty'             => $_iam_item['sqty'] ?? null,
        'is_exclude_qc'    => (int)($_iam_item['is_exclude_qc'] ?? 0),
    ];
}
foreach ($ing_active_map as &$_iam_procs) {
    usort($_iam_procs, function($a, $b) { return (int)$a['bom_sn'] - (int)$b['bom_sn']; });
}
unset($_iam_procs);

echo json_encode([
    'success' => true,
    'data' => $data_to_return,
    'totalRecords' => (int)$totalRecords,
    'bom_ps_list' => $bom_ps_list,
    'bom_ps_list_max' => $bom_ps_list_max,
    'all_process_types' => $all_process_types,
    'transfer_price_map' => $transfer_price_map,
    'ing_active_map' => $ing_active_map ?: (object)[],
]);

?>