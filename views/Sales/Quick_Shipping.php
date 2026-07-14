<?php
// views/Sales/Quick_Shipping.php — 快速出貨頁面
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

$db_conn = new DBConnection();
$pdo = $db_conn->getPDO();
$current_user_id = $_SESSION['id'] ?? '';

// ════════════════════════════════════════════════════════════════════════════
// 確保 is_bom_map 資料表存在（出貨單 ↔ BOM 橋接表）
// ════════════════════════════════════════════════════════════════════════════
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS is_bom_map (
        id          INT          NOT NULL AUTO_INCREMENT,
        IS_id       INT          NOT NULL COMMENT '對應 is_list.IS_id',
        bom         VARCHAR(30)  NOT NULL COMMENT '對應 bom.bom',
        shipped_qty INT          NOT NULL DEFAULT 0 COMMENT '此出貨單從此BOM出貨的數量',
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_is_bom (IS_id, bom),
        INDEX idx_ibm_bom   (bom),
        INDEX idx_ibm_is_id (IS_id)
    ) COMMENT='出貨單與BOM對應表，支援一張出貨單對多筆BOM'");
} catch (PDOException $e) {
    error_log("is_bom_map creation error: " . $e->getMessage());
}

// ════════════════════════════════════════════════════════════════════════════
// 共用：批次取得 BOM 進度（OreadyReply 邏輯）
// 以 outsource_date 最新日期為準，找非 E 步驟；全部 E 取 bom_sn 最大者
// ════════════════════════════════════════════════════════════════════════════
function qs_bom_progress_batch(array $bom_list, PDO $pdo): array
{
    if (empty($bom_list)) return [];

    $ph  = implode(',', array_fill(0, count($bom_list), '?'));
    $sql = "SELECT bi.bom, bi.bom_sn, bi.processing_state, bi.qc_completed,
                   DATE(bi.outsource_date) AS out_date,
                   COALESCE(pn.ProcessName, '') AS process_name
            FROM bom_ing bi
            INNER JOIN (
                SELECT bom, bom_sn, MAX(bom_ing_fid) AS max_fid
                FROM bom_ing WHERE bom IN ($ph) GROUP BY bom, bom_sn
            ) dedup ON bi.bom_ing_fid = dedup.max_fid
            LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
            ORDER BY bi.bom, bi.bom_sn ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bom_list);
    $ing_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bom_steps = [];
    foreach ($ing_rows as $ir) {
        $bom_steps[$ir['bom']][] = $ir;
    }

    $result    = [];
    $active_st = ['Q', 'P', 'ing', 'E'];

    foreach ($bom_steps as $bk => $steps) {
        $total = count($steps);

        // OreadyReply：找有 outsource_date 且 active 狀態的最新日期
        $max_date = null;
        foreach ($steps as $s) {
            if (in_array($s['processing_state'], $active_st) && !empty($s['out_date'])) {
                if ($max_date === null || $s['out_date'] > $max_date) {
                    $max_date = $s['out_date'];
                }
            }
        }

        $cur_pos = $cur_name = $cur_state = '';

        if ($max_date !== null) {
            // 最新日期的 active 步驟（含 1-indexed 位置）
            $at_max = [];
            foreach ($steps as $i => $s) {
                if (in_array($s['processing_state'], $active_st)
                    && !empty($s['out_date'])
                    && $s['out_date'] === $max_date) {
                    $at_max[] = ['pos' => $i + 1, 's' => $s];
                }
            }
            // 優先非 E；全部 E → bom_sn 最大者
            $display = array_values(array_filter($at_max, fn($x) => $x['s']['processing_state'] !== 'E'));
            if (empty($display)) {
                usort($at_max, fn($a, $b) => intval($b['s']['bom_sn']) - intval($a['s']['bom_sn']));
                $display = [$at_max[0]];
            }
            $ds        = $display[0];
            $cur_pos   = $ds['pos'];
            $cur_name  = $ds['s']['process_name'];
            $cur_state = $ds['s']['processing_state'];
            if ($cur_state === 'Q' && ($ds['s']['qc_completed'] ?? 0) == 1) $cur_state = 'P';
        } else {
            // 尚未發包：取第一個非 E 步驟
            foreach ($steps as $i => $s) {
                if ($s['processing_state'] !== 'E') {
                    $cur_pos   = $i + 1;
                    $cur_name  = $s['process_name'];
                    $cur_state = $s['processing_state'];
                    if ($cur_state === 'Q' && ($s['qc_completed'] ?? 0) == 1) $cur_state = 'P';
                    break;
                }
            }
            if ($cur_pos === '' && $total > 0) {
                $last = $steps[$total - 1];
                $cur_pos = $total; $cur_name = $last['process_name']; $cur_state = 'E';
            }
        }

        $result[$bk] = [
            'process_total' => $total,
            'current_pos'   => (int)$cur_pos,
            'current_name'  => $cur_name,
            'current_state' => $cur_state,
        ];
    }

    return $result;
}

// ════════════════════════════════════════════════════════════════════════════
// AJAX 處理區塊
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // ── 取得 BOM 待出貨建議（使用預聚合 JOIN，避免 correlated subquery） ──
    if ($_POST['action'] === 'get_ready_to_ship') {
        try {
            // 預聚合：各 BOM 最後一道完工批次數量
            $sub_done    = "(SELECT bom, SUM(sqty) AS done_qty FROM bom_ing
                             WHERE is_consumed = 0 AND processing_state = 'E' GROUP BY bom)";
            // 預聚合：各 BOM 已出貨數量
            $sub_shipped = "(SELECT bom, SUM(shipped_qty) AS bom_shipped FROM is_bom_map GROUP BY bom)";
            // 預聚合：各訂單已出貨數量
            $sub_order   = "(SELECT Order_id, SUM(Qty) AS ord_shipped FROM is_list GROUP BY Order_id)";

            $sel = "b.bom,
                    b.sqty                                   AS bom_total_qty,
                    b.d_id,
                    b.d_setting_id,
                    COALESCE(b.processing_state,'')          AS bom_processing_state,
                    COALESCE(b.bom_ps,'')                    AS bom_ps,
                    COALESCE(b.priority_type,'')             AS priority_type,
                    DATE_FORMAT(b.Delivery_date,'%Y-%m-%d')  AS bom_delivery,
                    CASE WHEN b.processing_state='1' THEN b.sqty
                         ELSE COALESCE(bd.done_qty,0) END    AS bom_completed_qty,
                    COALESCE(ibm.bom_shipped,0)              AS bom_shipped_qty,
                    ot.Order_oo,
                    ot.Qty                                   AS order_qty,
                    ot.unit_price                            AS order_unit_price,
                    ot.Specification                         AS order_spec,
                    DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS order_delivery,
                    COALESCE(cl.customer,ot.Client_name)     AS client_display,
                    ot.Client_name_ID                        AS client_id,
                    ot.Client_name                           AS client_name,
                    COALESCE(ds.Spec_No,'')                  AS part_spec,
                    COALESCE(il.ord_shipped,0)               AS order_shipped_qty";

            $joins = "LEFT JOIN $sub_done    bd  ON bd.bom      = b.bom
                      LEFT JOIN $sub_shipped ibm  ON ibm.bom     = b.bom
                      LEFT JOIN $sub_order   il   ON il.Order_id = ot.Order_id
                      LEFT JOIN customer_list cl  ON cl.customer_id = ot.Client_name_ID
                      LEFT JOIN d_setting    ds   ON ds.d_id = b.d_setting_id";

            // HAVING 使用 SELECT alias（MySQL 允許在 HAVING 引用 SELECT 清單別名）
            $having = "HAVING LEAST(
                bom_completed_qty - bom_shipped_qty,
                allocated_qty,
                order_qty - order_shipped_qty
            ) > 0";

            // 方法 A：透過 bom_order_process_map
            $sql_a = "SELECT $sel, bopm.order_id, bopm.allocated_qty
                      FROM bom b
                      JOIN bom_order_process_map bopm ON bopm.bom = b.bom
                      JOIN order_track ot ON ot.Order_id = bopm.order_id
                      $joins
                      WHERE (ot.Order_status IS NULL OR ot.Order_status NOT IN (9))
                      $having";

            // 方法 B：透過 bom.o_order_id（排除已在方法 A）
            $sql_b = "SELECT $sel, ot.Order_id AS order_id, b.sqty AS allocated_qty
                      FROM bom b
                      JOIN order_track ot ON ot.Order_oo = b.o_order_id
                      $joins
                      WHERE (ot.Order_status IS NULL OR ot.Order_status NOT IN (9))
                        AND NOT EXISTS (
                            SELECT 1 FROM bom_order_process_map x
                            WHERE x.bom = b.bom AND x.order_id = ot.Order_id
                        )
                      $having";

            $rows_a = $pdo->query($sql_a)->fetchAll(PDO::FETCH_ASSOC);
            $rows_b = $pdo->query($sql_b)->fetchAll(PDO::FETCH_ASSOC);

            $suggestions = [];
            foreach (array_merge($rows_a, $rows_b) as $row) {
                $bom_available   = max(0, intval($row['bom_completed_qty']) - intval($row['bom_shipped_qty']));
                $order_remaining = max(0, intval($row['order_qty'])         - intval($row['order_shipped_qty']));
                $suggested_qty   = min($bom_available, intval($row['allocated_qty']), $order_remaining);
                if ($suggested_qty <= 0) continue;

                $suggestions[] = array_merge($row, [
                    'bom_available'   => $bom_available,
                    'order_remaining' => $order_remaining,
                    'suggested_qty'   => $suggested_qty,
                    'is_manual_close' => ($row['bom_processing_state'] === '1'),
                ]);
            }
            usort($suggestions, fn($a, $b) => strcmp($a['order_delivery'] ?? '', $b['order_delivery'] ?? ''));
            echo json_encode(['success' => true, 'data' => $suggestions]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 搜尋訂單 ──────────────────────────────────────────────────────────
    if ($_POST['action'] === 'search_orders') {
        try {
            $where = ["(ot.Order_status IS NULL OR ot.Order_status NOT IN (9))"];
            $params = [];

            if (!empty($_POST['client'])) {
                $where[] = "ot.Client_name LIKE :client";
                $params[':client'] = '%' . $_POST['client'] . '%';
            }
            if (!empty($_POST['order_oo'])) {
                $where[] = "ot.Order_oo LIKE :order_oo";
                $params[':order_oo'] = '%' . $_POST['order_oo'] . '%';
            }
            if (!empty($_POST['d_id'])) {
                $where[] = "ot.d_id LIKE :d_id";
                $params[':d_id'] = '%' . $_POST['d_id'] . '%';
            }
            if (!empty($_POST['date_from'])) {
                $where[] = "ot.Delivery_date >= :date_from";
                $params[':date_from'] = $_POST['date_from'];
            }
            if (!empty($_POST['date_to'])) {
                $where[] = "ot.Delivery_date <= :date_to";
                $params[':date_to'] = $_POST['date_to'];
            }

            $where_sql = implode(' AND ', $where);
            $sql = "SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Client_name,
                           ot.Qty, ot.unit_price, ot.Specification,
                           ot.Processing_items as processing_items,
                           ot.Order_ps as order_ps,
                           DATE_FORMAT(ot.Order_date,'%Y-%m-%d') as Order_date,
                           DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') as Delivery_date,
                           COALESCE(cl.customer, ot.Client_name) as client_display,
                           ot.Client_name_ID as client_id,
                           COALESCE(ds.Spec_No, '') as part_spec,
                           (SELECT COUNT(DISTINCT bom_id) FROM (
                               SELECT bom AS bom_id FROM bom_order_process_map WHERE order_id = ot.Order_id
                               UNION
                               SELECT bom AS bom_id FROM bom WHERE o_order_id = ot.Order_oo
                           ) _bc) as bom_count_total,
                           ot.Qty - COALESCE(
                               (SELECT SUM(il.Qty) FROM is_list il WHERE il.Order_id = ot.Order_id), 0
                           ) as undelivered_qty
                    FROM order_track ot
                    LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                    LEFT JOIN d_setting ds ON ds.d_id = ot.d_id_ID
                    WHERE $where_sql
                    ORDER BY ot.Delivery_date ASC, ot.Order_oo DESC
                    LIMIT 200";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 批次附加每筆訂單的 BOM 狀態（讓訂單列表直接顯示，不需先查詢製令）
            if (!empty($rows)) {
                $oids = array_map('intval', array_column($rows, 'Order_id'));
                $ph_o = implode(',', array_fill(0, count($oids), '?'));

                // 兩種綁定方式合併取 BOM 清單
                $stmt_bm = $pdo->prepare(
                    "SELECT bopm.order_id, bopm.bom FROM bom_order_process_map bopm WHERE bopm.order_id IN ($ph_o)
                     UNION
                     SELECT ot.Order_id AS order_id, b.bom FROM bom b
                     JOIN order_track ot ON ot.Order_oo = b.o_order_id
                     WHERE ot.Order_id IN ($ph_o)"
                );
                $stmt_bm->execute(array_merge($oids, $oids));
                $bm_rows = $stmt_bm->fetchAll(PDO::FETCH_ASSOC);

                $order_bom_map = [];
                $all_boms = [];
                foreach ($bm_rows as $br) {
                    $order_bom_map[(int)$br['order_id']][] = $br['bom'];
                    $all_boms[] = $br['bom'];
                }
                $all_boms = array_values(array_unique($all_boms));

                $bom_progress = qs_bom_progress_batch($all_boms, $pdo);

                foreach ($rows as &$row) {
                    $oid   = (int)$row['Order_id'];
                    $boms  = $order_bom_map[$oid] ?? [];
                    $stats = [];
                    foreach ($boms as $bv) {
                        if (isset($bom_progress[$bv])) $stats[] = $bom_progress[$bv];
                    }
                    $row['bom_statuses'] = $stats;
                }
                unset($row);
            }

            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 依選定訂單查詢相關製令 ────────────────────────────────────────────
    if ($_POST['action'] === 'get_boms_for_orders') {
        try {
            $order_ids = json_decode($_POST['order_ids'] ?? '[]', true);
            if (empty($order_ids)) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }
            $order_ids = array_map('intval', $order_ids);
            $ph = implode(',', array_fill(0, count($order_ids), '?'));

            // 方法A：透過 bom_order_process_map
            $sql_a = "SELECT
                        bopm.bom, bopm.order_id, bopm.allocated_qty,
                        b.d_id, b.Client_Name as bom_client_name, b.sqty as bom_qty,
                        COALESCE(b.processing_state,'') as bom_processing_state,
                        COALESCE(b.state,'') as bom_state,
                        b.priority_type, b.bom_ps, b.d_setting_id,
                        DATE_FORMAT(b.Delivery_date,'%Y-%m-%d') as bom_delivery,
                        ot.Order_oo, ot.unit_price as order_unit_price,
                        ot.Specification as order_spec,
                        COALESCE(cl.customer, ot.Client_name) as client_display,
                        ot.Client_name_ID as client_id,
                        ot.Client_name as client_name,
                        ds.Spec_No as part_spec
                      FROM bom_order_process_map bopm
                      JOIN bom b ON b.bom = bopm.bom
                      JOIN order_track ot ON ot.Order_id = bopm.order_id
                      LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                      LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
                      WHERE bopm.order_id IN ($ph)
                      ORDER BY ot.Order_oo, bopm.bom";
            $stmt_a = $pdo->prepare($sql_a);
            $stmt_a->execute($order_ids);
            $rows_a = $stmt_a->fetchAll(PDO::FETCH_ASSOC);

            // 找出哪些 order_id 在方法A沒結果，用方法B補查
            $found_orders = array_unique(array_column($rows_a, 'order_id'));
            $missing_orders = array_diff($order_ids, $found_orders);

            $rows_b = [];
            if (!empty($missing_orders)) {
                $ph2 = implode(',', array_fill(0, count($missing_orders), '?'));
                // 方法B：透過 bom.o_order_id = order_track.Order_oo
                $sql_b = "SELECT
                            b.bom, ot.Order_id as order_id, b.sqty as allocated_qty,
                            b.d_id, b.Client_Name as bom_client_name, b.sqty as bom_qty,
                            COALESCE(b.processing_state,'') as bom_processing_state,
                            COALESCE(b.state,'') as bom_state,
                            b.priority_type, b.bom_ps, b.d_setting_id,
                            DATE_FORMAT(b.Delivery_date,'%Y-%m-%d') as bom_delivery,
                            ot.Order_oo, ot.unit_price as order_unit_price,
                            ot.Specification as order_spec,
                            COALESCE(cl.customer, ot.Client_name) as client_display,
                            ot.Client_name_ID as client_id,
                            ot.Client_name as client_name,
                            ds.Spec_No as part_spec
                          FROM order_track ot
                          JOIN bom b ON b.o_order_id = ot.Order_oo
                          LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                          LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
                          WHERE ot.Order_id IN ($ph2)
                          ORDER BY ot.Order_oo, b.bom";
                $stmt_b = $pdo->prepare($sql_b);
                $stmt_b->execute(array_values($missing_orders));
                $rows_b = $stmt_b->fetchAll(PDO::FETCH_ASSOC);
            }

            $rows = array_merge($rows_a, $rows_b);

            // 使用共用 helper 計算 BOM 進度（OreadyReply 邏輯）
            if (!empty($rows)) {
                $bom_list    = array_values(array_unique(array_column($rows, 'bom')));
                $bom_progress = qs_bom_progress_batch($bom_list, $pdo);

                foreach ($rows as &$row) {
                    $b = $row['bom'];
                    $p = $bom_progress[$b] ?? ['process_total' => 0, 'current_pos' => 0, 'current_name' => '', 'current_state' => ''];
                    $row['process_total'] = $p['process_total'];
                    $row['current_pos']   = $p['current_pos'];
                    $row['current_name']  = $p['current_name'];
                    $row['current_state'] = $p['current_state'];
                }
                unset($row);
            }

            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 查詢製令（供手動綁定用）────────────────────────────────────────────
    // ── 查詢製令（Modal 用：支援 BOM 號、料號、客戶，三條件 OR 可混用）────
    if ($_POST['action'] === 'search_bom_for_bind') {
        $bom_q    = trim($_POST['bom']    ?? '');
        $d_id_q   = trim($_POST['d_id']   ?? '');
        $client_q = trim($_POST['client'] ?? '');

        $where = []; $params = [];
        if ($bom_q    !== '') { $where[] = 'b.bom LIKE ?';          $params[] = $bom_q . '%'; }
        if ($d_id_q   !== '') { $where[] = 'b.d_id LIKE ?';         $params[] = '%' . $d_id_q . '%'; }
        if ($client_q !== '') { $where[] = 'b.Client_Name LIKE ?';  $params[] = '%' . $client_q . '%'; }

        if (empty($where)) {
            echo json_encode(['success' => false, 'message' => '請輸入至少一個搜尋條件']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT b.bom, b.sqty, b.d_id,
                       COALESCE(b.Client_Name,'') AS client_name,
                       COALESCE(b.bom_ps,'')      AS bom_ps,
                       COALESCE(ds.Spec_No,'')    AS part_spec,
                       COALESCE(SUM(ibm.shipped_qty),0)  AS total_bound,
                       COUNT(DISTINCT ibm.IS_id)          AS shipment_bind_count,
                       (SELECT COUNT(*) FROM bom_order_process_map WHERE bom = b.bom) AS order_count
                FROM bom b
                LEFT JOIN d_setting ds   ON ds.d_id  = b.d_setting_id
                LEFT JOIN is_bom_map ibm ON ibm.bom  = b.bom
                WHERE " . implode(' AND ', $where) . "
                GROUP BY b.bom, b.sqty, b.d_id, b.Client_Name, b.bom_ps, ds.Spec_No
                ORDER BY b.bom DESC LIMIT 20");
            $stmt->execute($params);
            $boms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 批次取各 BOM 的製程清單
            if (!empty($boms)) {
                $bom_ids = array_column($boms, 'bom');
                $ph = implode(',', array_fill(0, count($bom_ids), '?'));
                $proc_stmt = $pdo->prepare("
                    SELECT bi.bom,
                           GROUP_CONCAT(DISTINCT pn.ProcessName
                               ORDER BY bi.bom_sn SEPARATOR ' → ') AS proc_list
                    FROM (SELECT DISTINCT bom, bom_sn, process_no FROM bom_ing WHERE bom IN ($ph)) bi
                    LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                    GROUP BY bi.bom");
                $proc_stmt->execute($bom_ids);
                $proc_map = array_column($proc_stmt->fetchAll(PDO::FETCH_ASSOC), 'proc_list', 'bom');
                foreach ($boms as &$bv) {
                    $bv['proc_list']   = $proc_map[$bv['bom']] ?? '';
                    $bv['unbound_qty'] = max(0, intval($bv['sqty']) - intval($bv['total_bound']));
                }
                unset($bv);
            }
            echo json_encode(['success' => true, 'data' => $boms]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 取得某張出貨單的所有 BOM 綁定（Modal 用）───────────────────────────
    if ($_POST['action'] === 'get_shipment_bindings') {
        $is_id = intval($_POST['IS_id'] ?? 0);
        if ($is_id <= 0) { echo json_encode(['success' => false, 'message' => '參數錯誤']); exit; }
        try {
            $stmt = $pdo->prepare("
                SELECT ibm.id, ibm.bom, ibm.shipped_qty,
                       COALESCE(b.sqty, 0) AS bom_total,
                       COALESCE(b.d_id, '') AS d_id,
                       COALESCE(ds.Spec_No,'') AS part_spec,
                       COALESCE(SUM(ibm2.shipped_qty),0) AS bom_total_bound
                FROM is_bom_map ibm
                LEFT JOIN bom b ON b.bom = ibm.bom
                LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
                LEFT JOIN is_bom_map ibm2 ON ibm2.bom = ibm.bom
                WHERE ibm.IS_id = ?
                GROUP BY ibm.id, ibm.bom, ibm.shipped_qty, b.sqty, b.d_id, ds.Spec_No
                ORDER BY ibm.bom");
            $stmt->execute([$is_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 查詢出貨單（供手動綁定用）──────────────────────────────────────────
    if ($_POST['action'] === 'search_is_list_for_bind') {
        $where = []; $params = [];
        $is_num  = trim($_POST['is_number'] ?? '');
        $date_f  = trim($_POST['date_from'] ?? '');
        $date_t  = trim($_POST['date_to']   ?? '');
        $product = trim($_POST['product']   ?? '');
        $client  = trim($_POST['client']    ?? '');

        if ($is_num)  { $where[] = 'il.IS_number LIKE ?';  $params[] = '%'.$is_num.'%'; }
        if ($date_f)  { $where[] = 'il.Order_date >= ?';   $params[] = $date_f; }
        if ($date_t)  { $where[] = 'il.Order_date <= ?';   $params[] = $date_t; }
        if ($product) { $where[] = 'il.Product_id LIKE ?'; $params[] = '%'.$product.'%'; }
        if ($client)  { $where[] = 'il.Client_name LIKE ?';$params[] = '%'.$client.'%'; }

        if (empty($where)) { echo json_encode(['success' => false, 'message' => '請輸入至少一個搜尋條件']); exit; }

        try {
            $sql = "SELECT il.IS_id, il.IS_number,
                           DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
                           il.Client_name, il.Product_id, il.Qty,
                           COALESCE(il.Specification,'') AS spec,
                           COALESCE(il.Content,'')       AS content,
                           COALESCE(il.Note,'')          AS note,
                           COALESCE(SUM(ibm.shipped_qty),0) AS already_bound
                    FROM is_list il
                    LEFT JOIN is_bom_map ibm ON ibm.IS_id = il.IS_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY il.IS_id, il.IS_number, il.Order_date, il.Client_name,
                             il.Product_id, il.Specification, il.Content, il.Qty, il.Note
                    ORDER BY il.Order_date DESC LIMIT 60";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 查詢此 BOM 已綁定的訂單 ───────────────────────────────────────────
    if ($_POST['action'] === 'get_bom_bound_orders') {
        $bom = trim($_POST['bom'] ?? '');
        if ($bom === '') { echo json_encode(['success'=>false]); exit; }
        try {
            $stmt = $pdo->prepare("
                SELECT bopm.id, bopm.order_id, bopm.allocated_qty,
                       ot.Order_oo, ot.d_id, ot.Qty AS order_qty,
                       COALESCE(cl.customer, ot.Client_name) AS client_display,
                       DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS delivery_date
                FROM bom_order_process_map bopm
                JOIN order_track ot ON ot.Order_id = bopm.order_id
                LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                WHERE bopm.bom = ?");
            $stmt->execute([$bom]);
            echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 儲存綁定（is_bom_map，可同時綁定訂單）──────────────────────────────
    if ($_POST['action'] === 'bind_bom_shipment') {
        $bom      = trim($_POST['bom']      ?? '');
        $is_id    = intval($_POST['IS_id']   ?? 0);
        $qty      = intval($_POST['qty']     ?? 0);
        $order_id = intval($_POST['order_id']  ?? 0);   // 選填
        $alloc_qty = intval($_POST['alloc_qty'] ?? 0);  // 選填
        if ($bom === '' || $is_id <= 0 || $qty <= 0) {
            echo json_encode(['success' => false, 'message' => '參數不完整']); exit;
        }
        try {
            $pdo->beginTransaction();

            // 1. 出貨單 ↔ BOM
            $check = $pdo->prepare("SELECT id FROM is_bom_map WHERE IS_id=? AND bom=?");
            $check->execute([$is_id, $bom]);
            $existing = $check->fetchColumn();
            if ($existing) {
                $pdo->prepare("UPDATE is_bom_map SET shipped_qty=? WHERE id=?")->execute([$qty, $existing]);
                $msg = '更新出貨綁定成功';
            } else {
                $pdo->prepare("INSERT INTO is_bom_map (IS_id,bom,shipped_qty) VALUES(?,?,?)")->execute([$is_id,$bom,$qty]);
                $msg = '新增出貨綁定成功';
            }

            // 2. 訂單 ↔ BOM（選填）
            $order_bound = false;
            if ($order_id > 0 && $alloc_qty > 0) {
                $ck2 = $pdo->prepare("SELECT id FROM bom_order_process_map WHERE bom=? AND order_id=?");
                $ck2->execute([$bom, $order_id]);
                $ex2 = $ck2->fetchColumn();
                if ($ex2) {
                    $pdo->prepare("UPDATE bom_order_process_map SET allocated_qty=?, updated_at=NOW() WHERE id=?")->execute([$alloc_qty,$ex2]);
                } else {
                    $pdo->prepare("INSERT INTO bom_order_process_map (bom,order_id,allocated_qty) VALUES(?,?,?)")->execute([$bom,$order_id,$alloc_qty]);
                }
                $order_bound = true;
                $msg .= '，訂單綁定成功';
            }

            // 3. 檢查完全綁定 + 超量警告
            $info = $pdo->prepare("
                SELECT b.sqty,
                       COALESCE(SUM(ibm.shipped_qty),0)  AS total_bound,
                       COUNT(DISTINCT ibm.IS_id)          AS shipment_count
                FROM bom b LEFT JOIN is_bom_map ibm ON ibm.bom=b.bom
                WHERE b.bom=? GROUP BY b.sqty");
            $info->execute([$bom]);
            $row  = $info->fetch(PDO::FETCH_ASSOC);
            $sqty = intval($row['sqty']        ?? 0);
            $tbd  = intval($row['total_bound'] ?? 0);
            $scnt = intval($row['shipment_count'] ?? 0);
            $fully   = $row && $tbd >= $sqty;
            $over    = $row && $tbd > $sqty;
            $warning = $over ? "注意：BOM 總綁定量（{$tbd} pcs）已超過製令總量（{$sqty} pcs），請確認各出貨單分配是否正確。" : '';

            $pdo->commit();
            echo json_encode([
                'success'          => true,
                'message'          => $msg,
                'is_fully_bound'   => $fully,
                'order_bound'      => $order_bound,
                'total_bound'      => $tbd,
                'bom_sqty'         => $sqty,
                'shipment_count'   => $scnt,
                'over_allocated'   => $over,
                'warning'          => $warning,
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 查詢訂單（供 BOM-訂單綁定用）──────────────────────────────────────
    if ($_POST['action'] === 'search_order_for_bind') {
        $where = []; $params = [];
        $oo     = trim($_POST['order_oo'] ?? '');
        $client = trim($_POST['client']   ?? '');
        $d_id   = trim($_POST['d_id']     ?? '');
        $df     = trim($_POST['date_from'] ?? '');
        $dt     = trim($_POST['date_to']   ?? '');
        if ($oo)     { $where[] = 'ot.Order_oo LIKE ?';    $params[] = '%'.$oo.'%'; }
        if ($client) { $where[] = 'ot.Client_name LIKE ?'; $params[] = '%'.$client.'%'; }
        if ($d_id)   { $where[] = 'ot.d_id LIKE ?';        $params[] = '%'.$d_id.'%'; }
        if ($df)     { $where[] = 'ot.Order_date >= ?';    $params[] = $df; }
        if ($dt)     { $where[] = 'ot.Order_date <= ?';    $params[] = $dt; }
        if (empty($where)) { echo json_encode(['success'=>false,'message'=>'請輸入至少一個搜尋條件']); exit; }
        try {
            $sql = "SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Client_name,
                           ot.Qty, DATE_FORMAT(ot.Order_date,'%Y-%m-%d') AS order_date,
                           DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS delivery_date,
                           COALESCE(ot.Order_ps,'') AS order_ps,
                           COALESCE(cl.customer, ot.Client_name) AS client_display,
                           COALESCE(ds.Spec_No,'') AS part_spec,
                           COALESCE(SUM(bopm.allocated_qty),0) AS allocated_sum,
                           COUNT(DISTINCT bopm.bom) AS bom_count
                    FROM order_track ot
                    LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                    LEFT JOIN d_setting ds     ON ds.d_id        = ot.d_id_ID
                    LEFT JOIN bom_order_process_map bopm ON bopm.order_id = ot.Order_id
                    WHERE (ot.Order_status IS NULL OR ot.Order_status NOT IN (9))
                      AND " . implode(' AND ', $where) . "
                    GROUP BY ot.Order_id, ot.Order_oo, ot.d_id, ot.Client_name,
                             ot.Qty, ot.Order_date, ot.Delivery_date, ot.Order_ps,
                             cl.customer, ds.Spec_No
                    ORDER BY ot.Order_date DESC LIMIT 80";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 取得某訂單已綁的 BOM（Modal 用）────────────────────────────────────
    if ($_POST['action'] === 'get_order_bom_bindings') {
        $order_id = intval($_POST['order_id'] ?? 0);
        if ($order_id <= 0) { echo json_encode(['success'=>false,'message'=>'參數錯誤']); exit; }
        try {
            $stmt = $pdo->prepare("
                SELECT bopm.id, bopm.bom, bopm.allocated_qty,
                       COALESCE(b.sqty,0) AS bom_sqty,
                       COALESCE(b.d_id,'') AS bom_d_id,
                       COALESCE(b.Client_Name,'') AS bom_client,
                       COALESCE(b.bom_ps,'') AS bom_ps
                FROM bom_order_process_map bopm
                LEFT JOIN bom b ON b.bom = bopm.bom
                WHERE bopm.order_id = ?
                ORDER BY bopm.bom");
            $stmt->execute([$order_id]);
            echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 儲存 BOM-訂單綁定 ──────────────────────────────────────────────────
    if ($_POST['action'] === 'bind_bom_to_order') {
        $bom      = trim($_POST['bom']      ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);
        $qty      = intval($_POST['qty']      ?? 0);
        if ($bom==='' || $order_id<=0 || $qty<=0) {
            echo json_encode(['success'=>false,'message'=>'參數不完整']); exit;
        }
        try {
            $check = $pdo->prepare("SELECT id FROM bom_order_process_map WHERE bom=? AND order_id=?");
            $check->execute([$bom, $order_id]);
            $existing = $check->fetchColumn();
            if ($existing) {
                $pdo->prepare("UPDATE bom_order_process_map SET allocated_qty=?, updated_at=NOW() WHERE id=?")
                    ->execute([$qty, $existing]);
                $msg = '更新綁定成功';
            } else {
                $pdo->prepare("INSERT INTO bom_order_process_map (bom,order_id,allocated_qty) VALUES(?,?,?)")
                    ->execute([$bom, $order_id, $qty]);
                $msg = '新增綁定成功';
            }
            echo json_encode(['success'=>true,'message'=>$msg]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 刪除 BOM-訂單綁定 ──────────────────────────────────────────────────
    if ($_POST['action'] === 'delete_bom_order_binding') {
        $id = intval($_POST['id'] ?? 0);
        if ($id<=0) { echo json_encode(['success'=>false,'message'=>'參數錯誤']); exit; }
        try {
            $pdo->prepare("DELETE FROM bom_order_process_map WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 刪除綁定 ──────────────────────────────────────────────────────────
    if ($_POST['action'] === 'delete_bom_binding') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => '參數錯誤']); exit; }
        try {
            $pdo->prepare("DELETE FROM is_bom_map WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 建立出貨單 ────────────────────────────────────────────────────────
    if ($_POST['action'] === 'create_shipments') {
        try {
            $items = json_decode($_POST['items'] ?? '[]', true);
            $ship_date = trim($_POST['ship_date'] ?? date('Y-m-d'));

            if (empty($items)) {
                echo json_encode(['success' => false, 'message' => '沒有出貨資料']);
                exit;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ship_date)) {
                echo json_encode(['success' => false, 'message' => '日期格式錯誤']);
                exit;
            }

            // IS_number 前綴（民國年 + MMDD）
            $republic_year = intval(substr($ship_date, 0, 4)) - 1911;
            $mmdd = substr($ship_date, 5, 2) . substr($ship_date, 8, 2);
            $prefix = 'IS' . str_pad($republic_year, 3, '0', STR_PAD_LEFT) . $mmdd;

            $pdo->beginTransaction();
            $inserted = 0;
            $errors = [];

            $stmt_ins = $pdo->prepare(
                "INSERT INTO is_list
                 (Order_date, IS_number, Client_id, Client_name, d_setting_id, Product_id,
                  Specification, Qty, Unit_price, Order_id, Note, Created_By, Created_At)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt_bom_map = $pdo->prepare(
                "INSERT IGNORE INTO is_bom_map (IS_id, bom, shipped_qty) VALUES (?, ?, ?)"
            );

            $order_ids_used = [];
            foreach ($items as $item) {
                $qty = intval($item['qty'] ?? 0);
                if ($qty <= 0) continue;

                $order_id   = !empty($item['order_id']) ? intval($item['order_id']) : null;
                $unit_price = floatval($item['unit_price'] ?? 0);
                $product_id = trim($item['product_id'] ?? '');
                $spec       = trim($item['specification'] ?? '');
                $client_id  = trim($item['client_id'] ?? '');
                $client_name = trim($item['client_name'] ?? '');
                $note       = trim($item['note'] ?? '');
                $d_setting_id = !empty($item['d_setting_id']) ? intval($item['d_setting_id']) : null;

                if (empty($product_id)) {
                    $errors[] = "缺少料號，略過 BOM: " . ($item['bom'] ?? '?');
                    continue;
                }
                if (empty($client_name)) {
                    $errors[] = "缺少客戶名稱，略過 BOM: " . ($item['bom'] ?? '?');
                    continue;
                }

                // 取得當日最大序號（在 transaction 內，可見本次已插入的資料）
                $stmt_seq = $pdo->prepare(
                    "SELECT MAX(CAST(SUBSTRING(IS_number, 10) AS UNSIGNED)) as max_seq
                     FROM is_list WHERE IS_number LIKE ?"
                );
                $stmt_seq->execute([$prefix . '%']);
                $seq_row = $stmt_seq->fetch(PDO::FETCH_ASSOC);
                $seq = intval($seq_row['max_seq'] ?? 0) + 1;
                $is_number = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);

                $stmt_ins->execute([
                    $ship_date,
                    $is_number,
                    $client_id ?: null,
                    $client_name,
                    $d_setting_id,
                    $product_id,
                    $spec,
                    $qty,
                    $unit_price,
                    $order_id,
                    $note,
                    $current_user_id,
                ]);
                $is_id = (int)$pdo->lastInsertId();
                if ($is_id > 0 && !empty($item['bom'])) {
                    $stmt_bom_map->execute([$is_id, $item['bom'], $qty]);
                }
                if ($order_id > 0) $order_ids_used[$order_id] = true;
                $inserted++;
            }

            if ($inserted > 0) {
                $pdo->commit();

                // 自動結案：出貨數 >= 訂購數時將訂單設為結束(9)
                $closed_orders = [];
                foreach (array_keys($order_ids_used) as $oid) {
                    try {
                        $stChk = $pdo->prepare("
                            SELECT ot.Qty,
                                   COALESCE(SUM(CASE WHEN ist.is_count IS NULL OR ist.is_count != 0 THEN il.Qty ELSE 0 END), 0) AS shipped_qty
                            FROM order_track ot
                            LEFT JOIN is_list il  ON il.Order_id  = ot.Order_id
                            LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
                            WHERE ot.Order_id = ?
                              AND (ot.Order_status IS NULL OR ot.Order_status NOT IN (6, 9))
                            GROUP BY ot.Order_id, ot.Qty
                        ");
                        $stChk->execute([$oid]);
                        $chk = $stChk->fetch(PDO::FETCH_ASSOC);
                        if ($chk && intval($chk['shipped_qty']) >= intval($chk['Qty'])) {
                            $pdo->prepare("UPDATE order_track SET Order_status = 9, Modified_At = NOW(), Modified_By = ? WHERE Order_id = ?")->execute([$current_user_id, $oid]);
                            $closed_orders[] = $oid;
                        }
                    } catch (\Throwable $e) {}
                }

                $msg = "成功建立 {$inserted} 筆出貨單";
                if ($closed_orders) $msg .= "，" . count($closed_orders) . " 筆訂單已自動結案";
                echo json_encode([
                    'success'       => true,
                    'inserted'      => $inserted,
                    'errors'        => $errors,
                    'closed_orders' => $closed_orders,
                    'message'       => $msg,
                ]);
            } else {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => '沒有有效的出貨資料（數量需大於 0）',
                    'errors'  => $errors,
                ]);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => '未知操作']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>快速出貨</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* ── 全局 ─────────────────────────────────── */
        :root {
            --pri: #2A3F54;
            --acc: #1ABB9C;
            --bg:  #EEF2F7;
            --bd:  #E2E8F0;
            --txt: #2D3748;
            --muted: #718096;
        }
        body { background: var(--bg); }
        .right_col { background: var(--bg) !important; padding: 20px; }

        /* 移除數字輸入上下箭頭 */
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; appearance: textfield; }

        /* ── 頁首 ─────────────────────────────────── */
        .qs-header { margin-bottom: 14px; }
        .qs-title  { font-size: 20px; font-weight: 700; color: var(--pri); margin: 0; }
        .qs-sub    { font-size: 12px; color: var(--muted); margin-top: 2px; }

        /* ── Tab 導覽 ─────────────────────────────── */
        .qs-tab-nav {
            display: flex; background: #fff;
            border-radius: 8px 8px 0 0;
            border-bottom: 2px solid var(--bd);
            overflow: hidden;
        }
        .qs-tab-btn {
            flex: 1; padding: 13px 10px 10px; cursor: pointer;
            text-align: center; font-size: 12.5px; font-weight: 600;
            color: var(--muted); border: none; background: none; outline: none;
            border-bottom: 3px solid transparent; margin-bottom: -2px;
            transition: background .15s, color .15s; white-space: nowrap;
        }
        .qs-tab-btn i { margin-right: 4px; }
        .qs-tab-btn:hover:not(.active) { background: #F8FAFC; color: var(--txt); }
        .qs-tab-btn.active { color: var(--pri); border-bottom-color: var(--acc); background: #F4FDFB; }
        .qs-tab-btn .tab-badge {
            background: #E53E3E; color: #fff; border-radius: 10px;
            padding: 1px 6px; font-size: 10px; margin-left: 4px; font-weight: 700;
        }

        /* ── Tab 內容 ─────────────────────────────── */
        .qs-card    { background: #fff; border-radius: 0 0 8px 8px; box-shadow: 0 1px 4px rgba(0,0,0,.07); min-height: 200px; }
        .qs-pane    { display: none; }
        .qs-pane.active { display: block; }

        /* ── 篩選列 ───────────────────────────────── */
        .qs-filter {
            background: #F8FAFC; border-bottom: 1px solid var(--bd);
            padding: 9px 14px;
        }
        .qs-frow { display: flex; flex-wrap: wrap; align-items: center; gap: 5px 10px; }
        .qs-fg   { display: flex; align-items: center; gap: 4px; }
        .qs-fl   { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; }
        .qs-fi {
            height: 28px !important; font-size: 12px !important; padding: 3px 8px !important;
            border: 1px solid #CBD5E0 !important; border-radius: 4px !important;
            background: #fff; outline: none;
        }
        .qs-fi:focus { border-color: var(--acc) !important; box-shadow: 0 0 0 2px rgba(26,187,156,.12) !important; }
        .qs-sep { color: #CBD5E0; }

        /* ── 選擇列 ───────────────────────────────── */
        .qs-sel-bar {
            display: flex; align-items: center; gap: 7px;
            padding: 6px 14px; background: #FFFBEB;
            border-bottom: 1px solid #FDE68A; font-size: 12px;
        }

        /* ── 表格 ─────────────────────────────────── */
        .qs-tbl-wrap { overflow-x: auto; overflow-y: auto; }
        .qs-tbl {
            width: 100%; border-collapse: collapse;
            font-size: 12.5px; table-layout: auto;
        }
        .qs-tbl thead th {
            position: sticky; top: 0; z-index: 2;
            background: var(--pri); color: #ECF0F1;
            font-size: 11.5px; font-weight: 600; white-space: nowrap;
            padding: 8px 10px; border: none;
        }
        .qs-tbl.tbl-suggest thead th { background: #1A6B5A; }
        .qs-tbl tbody tr { border-bottom: 1px solid #F1F5F9; }
        .qs-tbl tbody td { padding: 6px 10px; vertical-align: middle; color: var(--txt); }
        .qs-tbl tbody tr:hover td { background: #F0FDF9 !important; }
        .qs-tbl tbody tr:last-child { border-bottom: none; }

        /* ── 底部操作列 ───────────────────────────── */
        .qs-action {
            display: flex; align-items: center; justify-content: space-between;
            padding: 9px 14px; background: #F8FAFC; border-top: 1px solid var(--bd);
        }
        .qs-action .dg { display: flex; align-items: center; gap: 7px; }
        .qs-action label { margin: 0; font-size: 12px; font-weight: 600; color: var(--muted); }

        /* ── 步驟列 ───────────────────────────────── */
        .qs-steps {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 14px; background: #F8FAFC; border-bottom: 1px solid var(--bd);
        }
        .qs-step    { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--muted); }
        .qs-step.on { color: var(--pri); font-weight: 600; }
        .qs-step.ok { color: var(--acc); }
        .qs-sn {
            width: 20px; height: 20px; border-radius: 50%;
            background: #E2E8F0; color: #718096;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 700; flex-shrink: 0;
        }
        .qs-step.on .qs-sn { background: var(--pri); color: #fff; }
        .qs-step.ok .qs-sn { background: var(--acc); color: #fff; }
        .qs-sarrow { color: #CBD5E0; font-size: 10px; }

        /* ── 狀態標籤 ─────────────────────────────── */
        .qs-badge {
            display: inline-block; padding: 2px 7px; border-radius: 10px;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }
        .bg-ok   { background: #D1FAE5; color: #065F46; }
        .bg-warn { background: #FEF3C7; color: #92400E; }
        .bg-info { background: #DBEAFE; color: #1E40AF; }
        .bg-def  { background: #F1F5F9; color: #64748B; }
        .bg-err  { background: #FEE2E2; color: #991B1B; }

        /* 優先級 */
        .pri-E { color: #DC2626; font-weight: 700; }
        .pri-U { color: #D97706; font-weight: 700; }

        /* 綁定狀態 */
        .bind-full    { color: #059669; font-weight: 600; }
        .bind-partial { color: #D97706; }
        .bind-none    { color: #9CA3AF; }

        /* ── 建議表格輸入 ──────────────────────────── */
        .sq-qty   { width: 74px !important; text-align: right; }
        .sq-price { width: 84px !important; text-align: right; }
        .sq-note  { width: 116px !important; }
        .sq-over  { border-color: #F59E0B !important; background: #FFFBEB !important; }

        /* ── BOM表格輸入 ───────────────────────────── */
        .bom-qty-input   { width: 74px; text-align: right; }
        .bom-price-input { width: 84px; text-align: right; }
        .bom-spec-input  { width: 128px; }
        .bom-note-input  { width: 116px; }

        /* ── 空狀態 / 載入 ─────────────────────────── */
        .qs-empty {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; padding: 36px; color: #9CA3AF; gap: 8px;
        }
        .qs-empty i { font-size: 28px; }
        .qs-empty p { margin: 0; font-size: 13px; }
        .qs-loading {
            display: flex; align-items: center; justify-content: center;
            padding: 28px; color: var(--muted); gap: 8px; font-size: 13px;
        }

        /* ── Modal ────────────────────────────────── */
        .qs-mhd {
            background: var(--pri); color: #fff; padding: 12px 18px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .qs-mhd h4  { margin: 0; font-size: 15px; font-weight: 600; }
        .qs-mhd .close { color: #fff; opacity: .8; font-size: 20px; margin-top: -1px; }
        .qs-isinfo {
            background: #F8FAFC; border: 1px solid var(--bd); border-radius: 5px;
            padding: 9px 12px; font-size: 12.5px; margin-bottom: 12px;
        }
        .binding-tag {
            display: inline-flex; align-items: center;
            background: #EFF6FF; border: 1px solid #BFDBFE;
            border-radius: 18px; padding: 3px 9px 3px 11px;
            margin: 3px; font-size: 12px;
        }
        .binding-tag .del-bind {
            background: none; border: none; color: #DC2626;
            cursor: pointer; padding: 0 0 0 5px; font-size: 13px; line-height: 1;
        }
        .bom-search-item {
            padding: 6px 10px; border: 1px solid #E5E7EB; border-radius: 4px;
            margin-bottom: 3px; cursor: pointer; display: flex;
            justify-content: space-between; align-items: flex-start;
            font-size: 12px; transition: all .1s;
        }
        .bom-search-item:hover    { background: #EFF6FF; border-color: #93C5FD; }
        .bom-search-item.selected { background: #DBEAFE; border-color: #3B82F6; }
        .bom-search-item .bom-num { font-weight: 700; font-size: 13px; color: var(--pri); }
        .order-search-item {
            padding: 5px 10px; border: 1px solid #E5E7EB; border-radius: 4px;
            margin-bottom: 3px; cursor: pointer; display: flex;
            justify-content: space-between; align-items: center;
            font-size: 12px; transition: all .1s;
        }
        .order-search-item:hover    { background: #F0FDF4; border-color: #86EFAC; }
        .order-search-item.selected { background: #DCFCE7; border-color: #4ADE80; }

        /* ── Toast 提示 ──────────────────────────── */
        #alertBox {
            position: fixed; top: 62px; right: 20px; z-index: 9999;
            min-width: 250px; max-width: 360px; border-radius: 7px;
            padding: 11px 15px; box-shadow: 0 4px 16px rgba(0,0,0,.12);
            font-size: 13px; display: none;
        }

        /* ── 細節 ─────────────────────────────────── */
        .cell-sub  { font-size: 11px; color: #9CA3AF; margin-top: 1px; }
        .bom-status { font-size: 11px; }
    </style>

</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">

      <!-- 頁首 -->
      <div class="qs-header">
        <h2 class="qs-title"><i class="fa fa-truck" style="color:var(--acc);margin-right:8px;"></i>快速出貨</h2>
        <div class="qs-sub">待出貨建議 ／ 手動搜尋 ／ BOM 綁定管理</div>
      </div>

      <!-- Toast 提示 -->
      <div id="alertBox" class="alert"></div>

      <!-- Tab 導覽 -->
      <div class="qs-tab-nav">
        <button class="qs-tab-btn active" data-tab="suggest">
          <i class="fa fa-lightbulb-o"></i> 待出貨建議
          <span id="suggestBadge" class="tab-badge" style="display:none;"></span>
        </button>
        <button class="qs-tab-btn" data-tab="manual">
          <i class="fa fa-search"></i> 手動搜尋出貨
        </button>
        <button class="qs-tab-btn" data-tab="bind-is">
          <i class="fa fa-link"></i> BOM↔出貨單
        </button>
        <button class="qs-tab-btn" data-tab="bind-order">
          <i class="fa fa-file-text-o"></i> BOM↔訂單
        </button>
      </div>

      <div class="qs-card">

        <!-- ══ Tab 1：待出貨建議 ══════════════════════════════════ -->
        <div class="qs-pane active" id="pane-suggest">
          <div id="suggestLoading" class="qs-loading" style="display:none;">
            <i class="fa fa-spinner fa-spin"></i> 載入中…
          </div>
          <div id="suggestEmpty" class="qs-empty" style="display:none;">
            <i class="fa fa-check-circle" style="color:var(--acc);"></i>
            <p>目前無待出貨項目</p>
          </div>
          <div id="suggestTableWrap" style="display:none;">
            <div class="qs-sel-bar">
              <input type="checkbox" id="suggestSelectAll" checked>
              <label for="suggestSelectAll" style="margin:0;cursor:pointer;font-weight:600;">全選</label>
              <span style="color:#D1D5DB;">|</span>
              <span id="suggestSelInfo" style="color:var(--muted);font-size:12px;"></span>
              <div style="flex:1;"></div>
              <button class="btn btn-xs btn-default" id="btnRefreshSuggest">
                <i class="fa fa-refresh"></i> 重新整理
              </button>
            </div>
            <div class="qs-tbl-wrap" style="max-height:440px;">
              <table class="qs-tbl tbl-suggest" id="suggestTable">
                <thead><tr>
                  <th width="28"></th>
                  <th>製令</th><th>訂單號碼</th><th>交期</th><th>客戶</th><th>料號</th>
                  <th class="text-right" title="已完工/BOM總量">完工/總量</th>
                  <th class="text-right" title="訂單尚未出貨量">訂單未出</th>
                  <th class="text-right">出貨數<span style="color:#E74C3C;">*</span></th>
                  <th class="text-right">單價</th>
                  <th>備註</th><th>提醒</th>
                </tr></thead>
                <tbody id="suggestTableBody"></tbody>
              </table>
            </div>
            <div class="qs-action">
              <div class="dg">
                <label>出貨日期</label>
                <input type="date" id="suggestShipDate" class="form-control input-sm"
                       value="<?= date('Y-m-d') ?>" style="width:148px;">
              </div>
              <button class="btn btn-success btn-sm" id="btnConfirmSuggest">
                <i class="fa fa-paper-plane"></i> 確認出貨
              </button>
            </div>
          </div>
        </div>

        <!-- ══ Tab 2：手動搜尋出貨 ══════════════════════════════ -->
        <div class="qs-pane" id="pane-manual">
          <!-- 步驟列 -->
          <div class="qs-steps">
            <div class="qs-step on" id="stp1"><div class="qs-sn">1</div><span>選擇訂單</span></div>
            <i class="fa fa-angle-right qs-sarrow"></i>
            <div class="qs-step" id="stp2"><div class="qs-sn">2</div><span>填入出貨數量</span></div>
            <i class="fa fa-angle-right qs-sarrow"></i>
            <div class="qs-step" id="stp3"><div class="qs-sn">3</div><span>建立出貨單</span></div>
          </div>

          <!-- Step 1 -->
          <div id="step1Panel">
            <div class="qs-filter">
              <div class="qs-frow">
                <div class="qs-fg"><span class="qs-fl">客戶</span>
                  <input type="text" id="f_client" class="qs-fi" style="width:118px;" placeholder="客戶名稱…"></div>
                <div class="qs-fg"><span class="qs-fl">訂單號</span>
                  <input type="text" id="f_order_oo" class="qs-fi" style="width:118px;" placeholder="訂單號碼…"></div>
                <div class="qs-fg"><span class="qs-fl">料號</span>
                  <input type="text" id="f_d_id" class="qs-fi" style="width:108px;" placeholder="料號…"></div>
                <div class="qs-fg"><span class="qs-fl">交期</span>
                  <input type="date" id="f_date_from" class="qs-fi" style="width:128px;">
                  <span class="qs-sep">–</span>
                  <input type="date" id="f_date_to" class="qs-fi" style="width:128px;"></div>
                <button class="btn btn-primary btn-sm" id="btnSearchOrders" style="height:28px;padding:0 14px;">
                  <i class="fa fa-search"></i> 搜尋</button>
              </div>
            </div>
            <div id="orderSelInfo" class="qs-sel-bar" style="display:none;">
              <button class="btn btn-xs btn-default" id="btnSelectAll">全選</button>
              <button class="btn btn-xs btn-default" id="btnDeselectAll">取消全選</button>
              <span id="orderCount" style="color:var(--muted);margin-left:4px;font-size:12px;"></span>
              <div style="flex:1;"></div>
              <button class="btn btn-success btn-sm" id="btnGetBoms" disabled style="height:26px;padding:0 12px;">
                <i class="fa fa-cogs"></i> 查詢製令 <span id="selectedCount" class="badge" style="background:#4B5563;">0</span>
              </button>
            </div>
            <div id="orderEmpty" class="qs-empty" style="display:none;">
              <i class="fa fa-inbox"></i><p>查無符合訂單</p>
            </div>
            <div class="qs-tbl-wrap" style="max-height:360px;">
              <table class="qs-tbl" id="orderTable" style="display:none;">
                <thead><tr>
                  <th width="28"></th>
                  <th>訂單號碼</th><th>交期</th><th>客戶</th><th>料號</th><th>製程</th>
                  <th class="text-right">訂單量</th><th class="text-right">未交量</th>
                  <th class="text-right">單價</th><th>業務備註</th><th>已綁製令</th>
                </tr></thead>
                <tbody id="orderTableBody"></tbody>
              </table>
            </div>
          </div>

          <!-- Step 2 -->
          <div id="bomSection" style="display:none;">
            <div class="qs-filter" style="display:flex;align-items:center;justify-content:space-between;">
              <button class="btn btn-xs btn-default" id="btnBackToOrders">
                <i class="fa fa-arrow-left"></i> 返回選訂單</button>
              <div style="display:flex;align-items:center;gap:7px;">
                <label style="margin:0;font-size:12px;font-weight:600;color:var(--muted);">出貨日期</label>
                <input type="date" id="shipDate" class="form-control input-sm"
                       value="<?= date('Y-m-d') ?>" style="width:140px;">
              </div>
            </div>
            <div id="bomTable">
              <div class="qs-tbl-wrap" style="max-height:440px;">
                <table class="qs-tbl">
                  <thead><tr>
                    <th>製令號碼</th><th>訂單號碼</th><th>客戶</th><th>料號</th><th>規格</th>
                    <th class="text-right">製令量</th><th class="text-right">分配量</th><th>狀態</th>
                    <th class="text-right">出貨數<span style="color:#E74C3C;">*</span></th>
                    <th class="text-right">單價</th><th>備註</th>
                  </tr></thead>
                  <tbody id="bomTableBody"></tbody>
                </table>
              </div>
              <div id="bomEmpty" class="qs-empty" style="display:none;">
                <i class="fa fa-info-circle"></i><p>所選訂單查無關聯製令</p>
              </div>
            </div>
            <div class="qs-action">
              <div></div>
              <button class="btn btn-success btn-sm" id="btnSubmit">
                <i class="fa fa-check"></i> 建立出貨單</button>
            </div>
          </div>
        </div>

        <!-- ══ Tab 3：BOM↔出貨單綁定 ════════════════════════════ -->
        <div class="qs-pane" id="pane-bind-is">
          <div class="qs-filter" id="bindToggle">
            <div class="qs-frow">
              <div class="qs-fg"><span class="qs-fl">出貨日期</span>
                <input type="date" id="bindIsDateFrom" class="qs-fi" style="width:126px;" autocomplete="off">
                <span class="qs-sep">–</span>
                <input type="date" id="bindIsDateTo" class="qs-fi" style="width:126px;" autocomplete="off"></div>
              <div class="qs-fg"><span class="qs-fl">客戶</span>
                <input type="text" id="bindIsClientInput" class="qs-fi" style="width:118px;" placeholder="客戶名稱…"></div>
              <div class="qs-fg"><span class="qs-fl">料號</span>
                <input type="text" id="bindIsProductInput" class="qs-fi" style="width:108px;" placeholder="料號…"></div>
              <div class="qs-fg"><span class="qs-fl">單號</span>
                <input type="text" id="bindIsNumInput" class="qs-fi" style="width:126px;" placeholder="IS…"></div>
              <button class="btn btn-primary btn-sm" id="btnSearchIsForBind" style="height:28px;padding:0 14px;">
                <i class="fa fa-search"></i> 搜尋</button>
            </div>
          </div>
          <div id="bindBody">
            <div id="bindIsLoading" class="qs-loading" style="display:none;">
              <i class="fa fa-spinner fa-spin"></i> 搜尋中…</div>
            <div id="bindIsEmpty" class="qs-empty" style="display:none;">
              <i class="fa fa-inbox"></i><p>查無符合的出貨單</p></div>
            <div id="bindIsTableWrap" style="display:none;">
              <div class="qs-tbl-wrap" style="max-height:440px;">
                <table class="qs-tbl">
                  <thead><tr>
                    <th>出貨單號</th><th>日期</th><th>客戶</th><th>料號／規格</th>
                    <th class="text-right">數量</th><th class="text-right">已綁量</th>
                    <th class="text-center">綁定狀態</th><th class="text-center" width="58"></th>
                  </tr></thead>
                  <tbody id="bindIsTableBody"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ Tab 4：BOM↔訂單綁定 ══════════════════════════════ -->
        <div class="qs-pane" id="pane-bind-order">
          <div class="qs-filter" id="bindOrderToggle">
            <div class="qs-frow">
              <div class="qs-fg"><span class="qs-fl">接單日期</span>
                <input type="date" id="boDateFrom" class="qs-fi" style="width:126px;" autocomplete="off">
                <span class="qs-sep">–</span>
                <input type="date" id="boDateTo" class="qs-fi" style="width:126px;" autocomplete="off"></div>
              <div class="qs-fg"><span class="qs-fl">客戶</span>
                <input type="text" id="boClient" class="qs-fi" style="width:118px;" placeholder="客戶名稱…"></div>
              <div class="qs-fg"><span class="qs-fl">料號</span>
                <input type="text" id="boDId" class="qs-fi" style="width:108px;" placeholder="料號…"></div>
              <div class="qs-fg"><span class="qs-fl">訂單號</span>
                <input type="text" id="boOrderOo" class="qs-fi" style="width:126px;" placeholder="訂單號碼…"></div>
              <button class="btn btn-primary btn-sm" id="btnSearchOrderForBind" style="height:28px;padding:0 14px;">
                <i class="fa fa-search"></i> 搜尋</button>
            </div>
          </div>
          <div id="bindOrderBody">
            <div id="boLoading" class="qs-loading" style="display:none;">
              <i class="fa fa-spinner fa-spin"></i> 搜尋中…</div>
            <div id="boEmpty" class="qs-empty" style="display:none;">
              <i class="fa fa-inbox"></i><p>查無符合訂單</p></div>
            <div id="boTableWrap" style="display:none;">
              <div class="qs-tbl-wrap" style="max-height:440px;">
                <table class="qs-tbl">
                  <thead><tr>
                    <th>訂單號碼</th><th>接單日</th><th>交期</th><th>客戶</th><th>料號</th>
                    <th>備註</th><th class="text-right">訂單量</th>
                    <th class="text-right">已綁量</th><th class="text-center">製令數</th>
                    <th class="text-center" width="58"></th>
                  </tr></thead>
                  <tbody id="boTableBody"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /qs-card -->

      <!-- ══ Modal：BOM↔出貨單 ═══════════════════════════════════ -->
      <div class="modal fade" id="bindModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg">
          <div class="modal-content" style="border-radius:7px;overflow:hidden;">
            <div class="qs-mhd">
              <h4><i class="fa fa-link"></i> BOM 綁定
                <span id="modalIsTitle" style="font-weight:400;font-size:13px;margin-left:8px;opacity:.85;"></span></h4>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body" style="padding:14px 18px;">
              <div class="qs-isinfo" id="modalIsInfo"></div>
              <div style="margin-bottom:10px;">
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">已綁定製令</div>
                <div id="modalBindingTags" style="min-height:26px;"></div>
              </div>
              <hr style="margin:10px -18px;border-color:#F1F5F9;">
              <div style="margin-top:10px;">
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">新增綁定</div>
                <div id="modalBomRecommendWrap" style="margin-bottom:8px;">
                  <div style="font-size:11px;color:#9CA3AF;margin-bottom:3px;"><i class="fa fa-magic"></i> 相同料號推薦</div>
                  <div id="modalBomRecommendList"></div>
                </div>
                <div style="display:flex;gap:5px;margin-bottom:5px;flex-wrap:wrap;">
                  <input type="text" id="modalBomInput" class="form-control input-sm" style="width:138px;" placeholder="製令號碼…" autocomplete="off">
                  <input type="text" id="modalBomClientInput" class="form-control input-sm" style="width:118px;" placeholder="客戶名稱…" autocomplete="off">
                  <input type="text" id="modalBomProductInput" class="form-control input-sm" style="width:118px;" placeholder="料號…" autocomplete="off">
                  <button class="btn btn-default btn-sm" id="btnModalSearchBom"><i class="fa fa-search"></i> 搜尋製令</button>
                </div>
                <div id="modalBomList" style="max-height:180px;overflow-y:auto;margin-bottom:7px;"></div>
                <div id="modalBindActionRow" style="display:none;background:#F8FAFC;border:1px solid var(--bd);border-radius:5px;padding:9px 11px;">
                  <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;">
                    <div style="flex:1;">
                      <span style="font-size:12px;color:#555;">已選：</span>
                      <strong id="modalSelectedBomLabel" style="color:var(--pri);"></strong>
                      <span id="modalBomHint" style="font-size:11px;color:#888;margin-left:5px;"></span>
                    </div>
                    <div class="input-group input-group-sm" style="width:128px;">
                      <span class="input-group-addon">出貨量</span>
                      <input type="number" id="modalBindQty" class="form-control" min="1" placeholder="0">
                    </div>
                    <button class="btn btn-primary btn-sm" id="btnModalSaveBind">
                      <i class="fa fa-link"></i> 確認綁定</button>
                  </div>
                  <div id="modalOrderSection" style="margin-top:9px;padding-top:9px;border-top:1px dashed #E5E7EB;">
                    <div style="font-size:12px;font-weight:600;color:#555;margin-bottom:6px;">
                      <i class="fa fa-file-text-o"></i> 同時綁定訂單
                      <small style="font-weight:400;color:#9CA3AF;margin-left:4px;">選填</small>
                    </div>
                    <div id="modalBomExistingOrders" style="margin-bottom:5px;"></div>
                    <div style="font-size:11px;color:#9CA3AF;margin-bottom:3px;"><i class="fa fa-magic"></i> 相同料號訂單推薦</div>
                    <div id="modalOrderRecommendList" style="max-height:148px;overflow-y:auto;margin-bottom:7px;"></div>
                    <div style="display:flex;gap:5px;margin-bottom:5px;flex-wrap:wrap;">
                      <input type="text" id="modalOrderOoInput" class="form-control input-sm" style="width:138px;" placeholder="訂單號碼…">
                      <input type="text" id="modalOrderClientInput" class="form-control input-sm" style="width:138px;" placeholder="客戶名稱…">
                      <button class="btn btn-default btn-sm" id="btnModalSearchOrder"><i class="fa fa-search"></i> 搜尋訂單</button>
                    </div>
                    <div id="modalOrderSearchList" style="max-height:140px;overflow-y:auto;margin-bottom:5px;"></div>
                    <div id="modalSelectedOrderArea" style="display:none;background:#F0FDF4;border:1px solid #86EFAC;border-radius:4px;padding:7px 11px;">
                      <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;">
                        <div style="flex:1;font-size:13px;">
                          <i class="fa fa-check-circle" style="color:#059669;"></i>
                          <strong id="modalSelectedOrderLabel" style="color:var(--pri);margin-left:4px;"></strong>
                        </div>
                        <div class="input-group input-group-sm" style="width:128px;">
                          <span class="input-group-addon">分配量</span>
                          <input type="number" id="modalOrderAllocQty" class="form-control" min="1" placeholder="0">
                        </div>
                        <button class="btn btn-xs btn-link" id="btnClearSelectedOrder" style="color:#DC2626;">
                          <i class="fa fa-times"></i></button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer" style="background:#F8FAFC;padding:8px 18px;">
              <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ Modal：BOM↔訂單 ════════════════════════════════════ -->
      <div class="modal fade" id="bindOrderModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg">
          <div class="modal-content" style="border-radius:7px;overflow:hidden;">
            <div class="qs-mhd">
              <h4><i class="fa fa-file-text-o"></i> BOM 綁定訂單
                <span id="boModalTitle" style="font-weight:400;font-size:13px;margin-left:8px;opacity:.85;"></span></h4>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body" style="padding:14px 18px;">
              <div class="qs-isinfo" id="boModalOrderInfo"></div>
              <div style="margin-bottom:10px;">
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">已綁定製令</div>
                <div id="boModalBindingTags" style="min-height:26px;"></div>
              </div>
              <hr style="margin:10px -18px;border-color:#F1F5F9;">
              <div style="margin-top:10px;">
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">新增製令綁定</div>
                <div id="boModalRecommendWrap" style="margin-bottom:8px;">
                  <div style="font-size:11px;color:#9CA3AF;margin-bottom:3px;"><i class="fa fa-magic"></i> 相同料號推薦</div>
                  <div id="boModalRecommendList"></div>
                </div>
                <div style="display:flex;gap:5px;margin-bottom:5px;flex-wrap:wrap;">
                  <input type="text" id="boModalBomInput" class="form-control input-sm" style="width:138px;" placeholder="製令號碼…">
                  <input type="text" id="boModalClientInput" class="form-control input-sm" style="width:118px;" placeholder="客戶名稱…">
                  <input type="text" id="boModalProductInput" class="form-control input-sm" style="width:118px;" placeholder="料號…">
                  <button class="btn btn-default btn-sm" id="btnBoModalSearchBom"><i class="fa fa-search"></i> 搜尋製令</button>
                </div>
                <div id="boModalBomList" style="max-height:180px;overflow-y:auto;margin-bottom:7px;"></div>
                <div id="boModalActionRow" style="display:none;background:#F8FAFC;border:1px solid var(--bd);border-radius:5px;padding:9px 11px;">
                  <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;">
                    <div style="flex:1;">
                      <span style="font-size:12px;color:#555;">已選：</span>
                      <strong id="boModalSelectedBomLabel" style="color:var(--pri);"></strong>
                      <span id="boModalBomHint" style="font-size:11px;color:#888;margin-left:5px;"></span>
                    </div>
                    <div class="input-group input-group-sm" style="width:128px;">
                      <span class="input-group-addon">分配量</span>
                      <input type="number" id="boModalBindQty" class="form-control" min="1" placeholder="0">
                    </div>
                    <button class="btn btn-primary btn-sm" id="btnBoModalSaveBind">
                      <i class="fa fa-link"></i> 確認綁定</button>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer" style="background:#F8FAFC;padding:8px 18px;">
              <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /right_col -->
  </div><!-- /main_container -->
</div><!-- /container body -->
<?php include '../partPage/footer.html' ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
(function ($) {
    'use strict';
    var PAGE_URL  = window.location.pathname.split('/').pop();
    var bomRows   = [];
    var suggestData = [];

    // ── 工具函式 ─────────────────────────────────────────────────────────────
    function intval(v) { return parseInt(v) || 0; }
    function fmtPrice(val) {
        var n = parseFloat(val);
        return isNaN(n) ? '' : parseFloat(n.toPrecision(10)).toString();
    }
    function escHtml(str) {
        if (str == null) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function showAlert(msg, type) {
        var box = $('#alertBox');
        box.removeClass('alert-success alert-danger alert-warning alert-info')
           .addClass('alert-' + (type || 'info')).html(msg).show();
        clearTimeout(box.data('t'));
        box.data('t', setTimeout(function () { box.fadeOut(); }, 4500));
    }

    // ── Tab 切換 ─────────────────────────────────────────────────────────────
    $('.qs-tab-btn').on('click', function () {
        var tab = $(this).data('tab');
        $('.qs-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.qs-pane').removeClass('active');
        $('#pane-' + tab).addClass('active');
    });

    // ── 步驟指示器（Tab 2） ──────────────────────────────────────────────────
    function setStep(n) {
        ['#stp1','#stp2','#stp3'].forEach(function (sel, i) {
            var el = $(sel).removeClass('on ok');
            if (i + 1 < n) el.addClass('ok');
            else if (i + 1 === n) el.addClass('on');
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    // Tab 1：BOM 待出貨建議
    // ════════════════════════════════════════════════════════════════════════
    function loadSuggestions() {
        $('#suggestLoading').show();
        $('#suggestEmpty, #suggestTableWrap').hide();
        var badge = $('#suggestBadge');
        badge.hide();

        $.post(PAGE_URL, { action: 'get_ready_to_ship' }, function (res) {
            $('#suggestLoading').hide();
            if (!res.success) { showAlert(res.message, 'danger'); return; }
            suggestData = res.data || [];
            if (!suggestData.length) {
                $('#suggestEmpty').show();
            } else {
                renderSuggestTable(suggestData);
                $('#suggestTableWrap').show();
                badge.text(suggestData.length).show();
                updateSuggestSelInfo();
            }
        }, 'json').fail(function () {
            $('#suggestLoading').hide();
            showAlert('載入建議清單失敗，請重試', 'danger');
        });
    }

    function renderSuggestTable(data) {
        var tbody = document.getElementById('suggestTableBody');
        tbody.innerHTML = '';
        var frag = document.createDocumentFragment();
        data.forEach(function (r, idx) {
            var tr = document.createElement('tr');
            tr.dataset.idx = idx;
            var priorCls  = r.priority_type === 'E' ? 'pri-E' : (r.priority_type === 'U' ? 'pri-U' : '');
            var priorIcon = r.priority_type === 'E' ? '🔴 ' : (r.priority_type === 'U' ? '🟡 ' : '');
            var orderLink = '<a href="NewOrder_Track.php?oo=' + encodeURIComponent(r.Order_oo) + '" target="_blank">' +
                escHtml(r.Order_oo) + ' <i class="fa fa-external-link" style="font-size:10px;"></i></a>';
            var didCell = '<strong>' + escHtml(r.d_id) + '</strong>' +
                (r.part_spec ? '<div class="cell-sub">' + escHtml(r.part_spec) + '</div>' : '');
            var warnHtml = r.is_manual_close
                ? '<span class="qs-badge bg-def" title="手動結案，出貨量以製令總量計算"><i class="fa fa-info-circle"></i> 手動結案</span>'
                : '';
            tr.innerHTML =
                '<td><input type="checkbox" class="suggest-chk" checked></td>' +
                '<td><span class="' + priorCls + '">' + priorIcon + '</span><small>' + escHtml(r.bom) + '</small></td>' +
                '<td>' + orderLink + '</td>' +
                '<td style="white-space:nowrap;">' + escHtml(r.order_delivery || '') + '</td>' +
                '<td>' + escHtml(r.client_display || r.client_name) + '</td>' +
                '<td>' + didCell + '</td>' +
                '<td class="text-right"><span style="color:#059669;">' + r.bom_completed_qty + '</span>/' + r.bom_total_qty + '</td>' +
                '<td class="text-right">' + r.order_remaining + '</td>' +
                '<td><input type="number" class="form-control input-sm sq-qty suggest-qty-input" value="' + r.suggested_qty +
                    '" min="0" data-suggested="' + r.suggested_qty + '" data-bom-available="' + r.bom_available + '"></td>' +
                '<td><input type="number" class="form-control input-sm sq-price suggest-price-input" value="' + fmtPrice(r.order_unit_price) + '" min="0" step="any"></td>' +
                '<td><input type="text" class="form-control input-sm sq-note suggest-note-input" placeholder="備註…"></td>' +
                '<td style="white-space:nowrap;">' + warnHtml + '</td>';
            frag.appendChild(tr);
        });
        tbody.appendChild(frag);
    }

    function updateSuggestSelInfo() {
        var total   = $('#suggestTableBody .suggest-chk').length;
        var checked = $('#suggestTableBody .suggest-chk:checked').length;
        $('#suggestSelInfo').text('已選 ' + checked + ' / ' + total + ' 筆');
    }

    $(document).on('change', '.suggest-chk', updateSuggestSelInfo);

    $('#suggestSelectAll').on('change', function () {
        $('#suggestTableBody .suggest-chk').prop('checked', $(this).prop('checked'));
        updateSuggestSelInfo();
    });

    $(document).on('input', '.suggest-qty-input', function () {
        var $inp = $(this), val = intval($inp.val()), avail = intval($inp.data('bom-available'));
        $inp.toggleClass('sq-over', val > avail);
        var $warn = $inp.closest('tr').find('td:last-child');
        $warn.find('.qty-warn').remove();
        if (val > avail) {
            $warn.append('<span class="qs-badge bg-warn qty-warn" ' +
                'title="超出BOM完工量 ' + (val - avail) + ' pcs"><i class="fa fa-warning"></i> 含庫存？(+' + (val - avail) + ')</span>');
        }
    });

    $('#btnConfirmSuggest').on('click', function () {
        var items = [], hasQty = false;
        $('#suggestTableBody tr').each(function () {
            if (!$(this).find('.suggest-chk').prop('checked')) return;
            var r = suggestData[intval($(this).data('idx'))];
            var qty = intval($(this).find('.suggest-qty-input').val());
            if (qty > 0) {
                hasQty = true;
                items.push({
                    bom: r.bom, order_id: r.order_id, product_id: r.d_id,
                    d_setting_id: r.d_setting_id || '', client_id: r.client_id || '',
                    client_name: r.client_display || r.client_name,
                    specification: r.order_spec || r.part_spec || '',
                    qty: qty, unit_price: parseFloat($(this).find('.suggest-price-input').val()) || 0,
                    note: $(this).find('.suggest-note-input').val()
                });
            }
        });
        if (!hasQty) { showAlert('請至少輸入一筆出貨數量（> 0）', 'warning'); return; }
        var shipDate = $('#suggestShipDate').val();
        if (!shipDate) { showAlert('請填入出貨日期', 'warning'); return; }
        if (!confirm('確定要建立 ' + items.length + ' 筆出貨單嗎？\n出貨日期：' + shipDate)) return;
        var btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 建立中…');
        $.post(PAGE_URL, { action: 'create_shipments', ship_date: shipDate, items: JSON.stringify(items) }, function (res) {
            btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> 確認出貨');
            if (res.success) {
                var msg = res.message;
                if (res.errors && res.errors.length) msg += '<br><small>略過：' + res.errors.join('；') + '</small>';
                showAlert(msg, 'success'); loadSuggestions();
            } else {
                showAlert(res.message + (res.errors && res.errors.length ? '<br><small>' + res.errors.join('；') + '</small>' : ''), 'danger');
            }
        }, 'json').fail(function () { btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> 確認出貨'); showAlert('網路錯誤，請重試', 'danger'); });
    });

    $('#btnRefreshSuggest').on('click', loadSuggestions);

    // ════════════════════════════════════════════════════════════════════════
    // Tab 3：BOM↔出貨單手動綁定
    // ════════════════════════════════════════════════════════════════════════
    var bindCurrentIS = null, bindSelectedBom = null;

    function doBindIsSearch() {
        var params = {
            action: 'search_is_list_for_bind',
            is_number: $('#bindIsNumInput').val().trim(),
            date_from: $('#bindIsDateFrom').val(),
            date_to:   $('#bindIsDateTo').val(),
            product:   $('#bindIsProductInput').val().trim(),
            client:    $('#bindIsClientInput').val().trim()
        };
        $('#bindIsLoading').show(); $('#bindIsEmpty, #bindIsTableWrap').hide();
        var btn = $('#btnSearchIsForBind').prop('disabled', true);
        $.post(PAGE_URL, params, function (res) {
            btn.prop('disabled', false); $('#bindIsLoading').hide();
            if (!res.success) { showAlert(res.message, 'warning'); return; }
            renderBindIsTable(res.data);
        }, 'json').fail(function () { btn.prop('disabled', false); $('#bindIsLoading').hide(); showAlert('搜尋失敗', 'danger'); });
    }
    $('#btnSearchIsForBind').on('click', doBindIsSearch);
    $('#bindIsNumInput, #bindIsClientInput, #bindIsProductInput').on('keydown', function (e) { if (e.key === 'Enter') doBindIsSearch(); });

    // 雙擊篩選輸入框 → 清除並重搜
    $('#bindIsDateFrom, #bindIsDateTo, #bindIsClientInput, #bindIsProductInput, #bindIsNumInput').on('dblclick', function () {
        if ($(this).val() !== '') { $(this).val(''); doBindIsSearch(); }
    });

    // 雙擊表格列 → 帶入篩選並重搜（col: 0=單號 2=客戶 3=料號）
    $(document).on('dblclick', '#bindIsTableBody td', function () {
        var $tr  = $(this).closest('tr');
        var col  = $(this).index();
        var filled = false;
        if (col === 0) { $('#bindIsNumInput').val($tr.data('isnum'));   filled = true; }
        else if (col === 2) { $('#bindIsClientInput').val($tr.data('client'));  filled = true; }
        else if (col === 3) { $('#bindIsProductInput').val($tr.data('product')); filled = true; }
        if (filled) doBindIsSearch();
    });

    function renderBindIsTable(rows) {
        var tbody = document.getElementById('bindIsTableBody');
        tbody.innerHTML = '';
        if (!rows.length) { $('#bindIsEmpty').show(); return; }
        var frag = document.createDocumentFragment();
        rows.forEach(function (r) {
            var bound = intval(r.already_bound), qty = intval(r.Qty);
            var statusHtml = bound <= 0
                ? '<span class="bind-none">— 未綁定</span>'
                : (bound >= qty
                    ? '<span class="bind-full"><i class="fa fa-check-circle"></i> 完全綁定</span>'
                    : '<span class="bind-partial"><i class="fa fa-adjust"></i> 部分 ' + bound + '/' + qty + '</span>');
            var tr = document.createElement('tr');
            tr.dataset.isid    = r.IS_id;
            tr.dataset.isnum   = r.IS_number;
            tr.dataset.qty     = qty;
            tr.dataset.client  = r.Client_name;
            tr.dataset.product = r.Product_id;
            tr.dataset.spec    = r.spec    || '';
            tr.dataset.content = r.content || '';
            tr.dataset.bound   = bound;
            tr.dataset.date    = r.ship_date;
            tr.innerHTML =
                '<td><strong style="color:#2c3e50;">' + escHtml(r.IS_number) + '</strong></td>' +
                '<td style="white-space:nowrap;">' + escHtml(r.ship_date) + '</td>' +
                '<td>' + escHtml(r.Client_name) + '</td>' +
                '<td style="max-width:280px;"><strong>' + escHtml(r.Product_id) + '</strong>' +
                    ((r.spec || r.content) ? '<div class="cell-sub">' +
                        (r.spec ? escHtml(r.spec) : '') +
                        (r.spec && r.content
                            ? ' <span style="color:#CBD5E0;margin:0 3px;">|</span><span style="color:#A0AEC0;">' + escHtml(r.content) + '</span>'
                            : r.content ? '<span style="color:#A0AEC0;">' + escHtml(r.content) + '</span>' : '') +
                    '</div>' : '') +
                    (r.note ? '<div class="cell-sub" style="color:#A0AEC0;">' + escHtml(r.note) + '</div>' : '') + '</td>' +
                '<td class="text-right">' + qty + '</td>' +
                '<td class="text-right">' + (bound || '—') + '</td>' +
                '<td class="text-center" style="white-space:nowrap;">' + statusHtml + '</td>' +
                '<td class="text-center"><button class="btn btn-xs btn-primary btn-open-bind-modal"><i class="fa fa-link"></i> 綁定</button></td>';
            frag.appendChild(tr);
        });
        tbody.appendChild(frag);
        $('#bindIsTableWrap').show();
    }

    $(document).on('click', '.btn-open-bind-modal', function () {
        var $tr = $(this).closest('tr');
        bindCurrentIS = {
            IS_id: intval($tr.data('isid')), IS_number: $tr.data('isnum'),
            qty: intval($tr.data('qty')), client: $tr.data('client'),
            product: $tr.data('product'), spec: $tr.data('spec'),
            content: $tr.data('content') || '',
            date: $tr.data('date'), bound: intval($tr.data('bound'))
        };
        bindSelectedBom = null;
        $('#modalBomInput, #modalBomClientInput, #modalBomProductInput').val('');
        $('#modalBomList, #modalBomRecommendList').empty();
        $('#modalBindActionRow').hide(); $('#modalBindQty').val('');
        $('#modalIsTitle').text(bindCurrentIS.IS_number);
        $('#modalIsInfo').html(
            '<strong>' + escHtml(bindCurrentIS.IS_number) + '</strong> &nbsp;|&nbsp; ' +
            escHtml(bindCurrentIS.date) + ' &nbsp;|&nbsp; ' + escHtml(bindCurrentIS.client) +
            ' &nbsp;|&nbsp; <strong>' + escHtml(bindCurrentIS.product) + '</strong>' +
            (bindCurrentIS.spec    ? ' <small class="text-muted">(' + escHtml(bindCurrentIS.spec)    + ')</small>' : '') +
            (bindCurrentIS.content ? ' <small class="text-muted">' + escHtml(bindCurrentIS.content) + '</small>' : '') +
            ' &nbsp;|&nbsp; <strong>' + bindCurrentIS.qty + ' pcs</strong>');
        loadModalBindings();
        $('#modalBomRecommendList').html('<span class="text-muted" style="font-size:12px;"><i class="fa fa-spinner fa-spin"></i></span>');
        $.post(PAGE_URL, { action: 'search_bom_for_bind', d_id: bindCurrentIS.product }, function (res) {
            if (!res.success || !res.data.length) {
                $('#modalBomRecommendList').html('<span class="text-muted" style="font-size:12px;">無相同料號製令</span>'); return;
            }
            renderModalBomItems(res.data, '#modalBomRecommendList');
        }, 'json');
        $('#bindModal').modal('show');
    });

    function loadModalBindings() {
        $('#modalBindingTags').html('<span class="text-muted" style="font-size:12px;">載入中…</span>');
        $.post(PAGE_URL, { action: 'get_shipment_bindings', IS_id: bindCurrentIS.IS_id }, function (res) {
            if (!res.success) { $('#modalBindingTags').html('<span class="text-muted">無法載入</span>'); return; }
            renderModalBindingTags(res.data);
        }, 'json');
    }

    function renderModalBindingTags(bindings) {
        var $wrap = $('#modalBindingTags').empty();
        if (!bindings.length) {
            $wrap.html('<span class="text-muted" style="font-size:12px;"><i class="fa fa-info-circle"></i> 尚未綁定任何製令</span>'); return;
        }
        bindings.forEach(function (b) {
            var pct = b.bom_total > 0 ? Math.round(intval(b.bom_total_bound) / intval(b.bom_total) * 100) : 0;
            var color = pct >= 100 ? '#059669' : '#D97706';
            $wrap.append('<span class="binding-tag">' +
                '<span style="color:' + color + ';font-weight:700;">' + escHtml(b.bom) + '</span>' +
                '&nbsp;<span style="color:#555;">' + b.shipped_qty + ' pcs</span>' +
                '&nbsp;<small style="color:#9CA3AF;">(' + pct + '%)</small>' +
                '<button class="del-bind" data-id="' + b.id + '" title="刪除此綁定">&times;</button></span>');
        });
    }

    $(document).on('click', '#modalBindingTags .del-bind', function () {
        var id = $(this).data('id');
        if (!confirm('確定要移除此綁定？')) return;
        $.post(PAGE_URL, { action: 'delete_bom_binding', id: id }, function (res) {
            if (res.success) { loadModalBindings(); doBindIsSearch(); loadSuggestions(); }
            else showAlert(res.message, 'danger');
        }, 'json');
    });

    var modalSelectedOrder = null;

    function loadModalOrderSection(bom, productHint) {
        modalSelectedOrder = null;
        $('#modalSelectedOrderArea').hide();
        $('#modalOrderRecommendList').html('<span class="text-muted" style="font-size:11px;"><i class="fa fa-spinner fa-spin"></i></span>');
        $('#modalOrderSearchList').empty();
        $('#modalOrderOoInput, #modalOrderClientInput').val('');
        $.post(PAGE_URL, { action: 'get_bom_bound_orders', bom: bom }, function (res) {
            var $ex = $('#modalBomExistingOrders').empty();
            if (res.success && res.data.length) {
                var html = '<div style="font-size:11px;color:#D97706;margin-bottom:4px;"><i class="fa fa-info-circle"></i> 此BOM已綁定以下訂單</div>';
                res.data.forEach(function (o) {
                    html += '<span class="qs-badge bg-warn" style="margin-right:3px;">' +
                        escHtml(o.Order_oo) + ' / ' + escHtml(o.client_display) + ' / 分配' + o.allocated_qty + 'pcs</span>';
                });
                $ex.html(html);
            }
        }, 'json');
        var d_id = productHint || (bindCurrentIS ? bindCurrentIS.product : '');
        if (!d_id) { $('#modalOrderRecommendList').html('<span class="text-muted" style="font-size:11px;">無料號資訊</span>'); return; }
        $.post(PAGE_URL, { action: 'search_order_for_bind', d_id: d_id }, function (res) {
            if (!res.success || !res.data.length) { $('#modalOrderRecommendList').html('<span class="text-muted" style="font-size:11px;">無相同料號訂單</span>'); return; }
            renderModalOrderItems(res.data, '#modalOrderRecommendList');
        }, 'json');
    }

    function renderModalOrderItems(rows, sel) {
        var $wrap = $(sel).empty();
        if (!rows.length) { $wrap.html('<span class="text-muted" style="font-size:11px;">查無訂單</span>'); return; }
        rows.forEach(function (r) {
            var boundInfo = intval(r.bom_count) > 0
                ? '<span style="color:#D97706;font-size:11px;">已綁' + r.bom_count + '筆製令</span>'
                : '<span style="color:#9CA3AF;font-size:11px;">未綁製令</span>';
            $wrap.append('<div class="order-search-item" data-oid="' + r.Order_id + '" data-oo="' + escHtml(r.Order_oo) +
                '" data-qty="' + r.Qty + '" data-client="' + escHtml(r.client_display) + '" data-did="' + escHtml(r.d_id) + '">' +
                '<span><strong>' + escHtml(r.Order_oo) + '</strong>' +
                ' <small class="text-muted">' + escHtml(r.client_display) + ' · ' + escHtml(r.d_id) + ' · 交期 ' + escHtml(r.delivery_date) + '</small></span>' +
                '<span>' + r.Qty + 'pcs &nbsp;' + boundInfo + '</span></div>');
        });
    }

    $(document).on('click', '#modalOrderRecommendList .order-search-item, #modalOrderSearchList .order-search-item', function () {
        $('#modalOrderRecommendList .order-search-item, #modalOrderSearchList .order-search-item').removeClass('selected');
        $(this).addClass('selected');
        modalSelectedOrder = { order_id: intval($(this).data('oid')), order_oo: $(this).data('oo'), qty: intval($(this).data('qty')) };
        var allocSuggested = bindSelectedBom ? Math.min(bindSelectedBom.sqty, modalSelectedOrder.qty) : modalSelectedOrder.qty;
        $('#modalOrderAllocQty').val(allocSuggested);
        $('#modalSelectedOrderLabel').text(modalSelectedOrder.order_oo + '（' + modalSelectedOrder.qty + ' pcs）');
        $('#modalSelectedOrderArea').show();
    });

    $('#btnModalSearchOrder').on('click', function () {
        var oo = $('#modalOrderOoInput').val().trim(), client = $('#modalOrderClientInput').val().trim();
        var d_id = bindCurrentIS ? bindCurrentIS.product : '';
        if (!oo && !client && !d_id) { showAlert('請輸入訂單號碼或客戶名稱', 'warning'); return; }
        var btn = $(this).prop('disabled', true);
        $.post(PAGE_URL, { action: 'search_order_for_bind', order_oo: oo, client: client, d_id: d_id }, function (res) {
            btn.prop('disabled', false);
            if (!res.success) { showAlert(res.message, 'warning'); return; }
            renderModalOrderItems(res.data, '#modalOrderSearchList');
        }, 'json').fail(function () { btn.prop('disabled', false); });
    });
    $('#modalOrderOoInput, #modalOrderClientInput').on('keydown', function (e) { if (e.key === 'Enter') $('#btnModalSearchOrder').click(); });

    $('#btnClearSelectedOrder').on('click', function () {
        modalSelectedOrder = null; $('#modalSelectedOrderArea').hide();
        $('#modalOrderRecommendList .order-search-item, #modalOrderSearchList .order-search-item').removeClass('selected');
    });

    function doModalBomSearch() {
        var bom = $('#modalBomInput').val().trim(), client = $('#modalBomClientInput').val().trim(), product = $('#modalBomProductInput').val().trim();
        if (!bom && !client && !product) { showAlert('請輸入製令號碼、客戶或料號', 'warning'); return; }
        var $list = $('#modalBomList').html('<span class="text-muted" style="font-size:12px;"><i class="fa fa-spinner fa-spin"></i> 搜尋中…</span>');
        bindSelectedBom = null; $('#modalBindActionRow').hide();
        $.post(PAGE_URL, { action: 'search_bom_for_bind', bom: bom, d_id: product, client: client }, function (res) {
            if (!res.success || !res.data.length) { $list.html('<span class="text-muted" style="font-size:12px;">查無符合製令</span>'); return; }
            renderModalBomItems(res.data, '#modalBomList');
        }, 'json');
    }
    $('#btnModalSearchBom').on('click', doModalBomSearch);
    $('#modalBomInput, #modalBomClientInput, #modalBomProductInput').on('keydown', function (e) { if (e.key === 'Enter') doModalBomSearch(); });

    function renderModalBomItems(boms, targetSel) {
        var $wrap = $(targetSel).empty();
        if (!boms.length) { $wrap.html('<span class="text-muted" style="font-size:12px;">查無製令</span>'); return; }
        boms.forEach(function (b) {
            var unbound = intval(b.unbound_qty);
            var color = unbound <= 0 ? '#9CA3AF' : (unbound >= intval(b.sqty) ? '#059669' : '#D97706');
            var fullyTxt = unbound <= 0
                ? '<span style="color:#9CA3AF;font-size:11px;">已完全綁定</span>'
                : '<span style="color:' + color + ';font-size:12px;">未綁 ' + unbound + '/' + b.sqty + ' pcs</span>';
            var orderCnt = intval(b.order_count);
            var orderBadge = orderCnt > 0
                ? '<span class="qs-badge bg-ok" style="margin-left:4px;"><i class="fa fa-check"></i> 已綁' + orderCnt + '筆訂單</span>'
                : '<span class="qs-badge bg-def" style="margin-left:4px;">未綁訂單</span>';
            var shipBadge = intval(b.shipment_bind_count) > 0
                ? '<span class="qs-badge bg-warn" style="margin-left:3px;" title="已有 ' + b.shipment_bind_count + ' 張出貨單綁定"><i class="fa fa-share-alt"></i> 共' + b.shipment_bind_count + '張</span>'
                : '';
            var subLines = [];
            if (b.client_name) subLines.push(escHtml(b.client_name));
            if (b.part_spec)   subLines.push(escHtml(b.part_spec));
            if (b.proc_list)   subLines.push('<span style="color:#1a7abf;">' + escHtml(b.proc_list) + '</span>');
            if (b.bom_ps)      subLines.push('<span style="color:#888;">備：' + escHtml(b.bom_ps) + '</span>');
            $wrap.append('<div class="bom-search-item" data-bom="' + escHtml(b.bom) + '" data-sqty="' + b.sqty + '" data-unbound="' + unbound + '">' +
                '<div style="flex:1;min-width:0;">' +
                    '<span class="bom-num">' + escHtml(b.bom) + '</span>' +
                    ' <small class="text-muted">' + escHtml(b.d_id) + '</small>' +
                    orderBadge + shipBadge +
                    (subLines.length ? '<br><small style="line-height:1.8;color:#718096;">' + subLines.join(' · ') + '</small>' : '') +
                '</div>' +
                '<div style="text-align:right;white-space:nowrap;padding-left:8px;flex-shrink:0;">' + fullyTxt + '</div>' +
                '</div>');
        });
    }

    $(document).on('click', '#modalBomList .bom-search-item, #modalBomRecommendList .bom-search-item', function () {
        $('#modalBomList .bom-search-item, #modalBomRecommendList .bom-search-item').removeClass('selected');
        $(this).addClass('selected');
        bindSelectedBom = { bom: $(this).data('bom'), sqty: intval($(this).data('sqty')), unbound: intval($(this).data('unbound')) };
        var suggested = Math.min(bindSelectedBom.unbound, bindCurrentIS ? bindCurrentIS.qty : 0);
        $('#modalBindQty').val(suggested > 0 ? suggested : '');
        $('#modalSelectedBomLabel').text(bindSelectedBom.bom);
        $('#modalBomHint').text('製令總量 ' + bindSelectedBom.sqty + ' pcs，未綁 ' + bindSelectedBom.unbound + ' pcs');
        $('#modalBindActionRow').show(); $('#modalBindQty').focus();
        loadModalOrderSection(bindSelectedBom.bom, bindCurrentIS ? bindCurrentIS.product : '');
    });

    $('#btnModalSaveBind').on('click', function () {
        if (!bindCurrentIS || !bindSelectedBom) return;
        var qty = intval($('#modalBindQty').val());
        if (qty <= 0) { showAlert('請輸入綁定數量', 'warning'); return; }
        var postData = { action: 'bind_bom_shipment', bom: bindSelectedBom.bom, IS_id: bindCurrentIS.IS_id, qty: qty };
        if (modalSelectedOrder) {
            postData.order_id  = modalSelectedOrder.order_id;
            postData.alloc_qty = intval($('#modalOrderAllocQty').val()) || qty;
        }
        var btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        $.post(PAGE_URL, postData, function (res) {
            btn.prop('disabled', false).html('<i class="fa fa-link"></i> 確認綁定');
            if (res.success) {
                var msg = res.message;
                if (res.is_fully_bound) msg += '（製令完全綁定 ✓）';
                if (res.shipment_count > 1) msg += '（共 ' + res.shipment_count + ' 張出貨單）';
                showAlert(msg, 'success');
                if (res.warning) showAlert(res.warning, 'warning');
                loadModalBindings(); doBindIsSearch(); loadSuggestions();
                bindSelectedBom = null; modalSelectedOrder = null;
                $('#modalBomList .bom-search-item, #modalBomRecommendList .bom-search-item').removeClass('selected');
                $('#modalBindActionRow').hide(); $('#modalBindQty').val(''); $('#modalSelectedOrderArea').hide();
                $('#modalOrderRecommendList .order-search-item, #modalOrderSearchList .order-search-item').removeClass('selected');
                $.post(PAGE_URL, { action: 'search_bom_for_bind', d_id: bindCurrentIS.product }, function (r) {
                    if (r.success && r.data.length) renderModalBomItems(r.data, '#modalBomRecommendList');
                }, 'json');
            } else showAlert(res.message, 'danger');
        }, 'json').fail(function () { btn.prop('disabled', false).html('<i class="fa fa-link"></i> 確認綁定'); showAlert('網路錯誤', 'danger'); });
    });

    $('#bindModal').on('hidden.bs.modal', function () {
        bindCurrentIS = bindSelectedBom = modalSelectedOrder = null;
        $('#modalBomList, #modalOrderRecommendList, #modalOrderSearchList, #modalBomExistingOrders').empty();
        $('#modalBomInput, #modalBomClientInput, #modalBomProductInput').val('');
        $('#modalOrderOoInput, #modalOrderClientInput').val('');
        $('#modalBindActionRow, #modalSelectedOrderArea').hide();
    });

    // ════════════════════════════════════════════════════════════════════════
    // Tab 4：BOM↔訂單手動綁定
    // ════════════════════════════════════════════════════════════════════════
    var boCurrentOrder = null, boSelectedBom = null;

    function doBoOrderSearch() {
        var params = {
            action: 'search_order_for_bind',
            date_from: $('#boDateFrom').val(), date_to: $('#boDateTo').val(),
            client: $('#boClient').val().trim(), d_id: $('#boDId').val().trim(),
            order_oo: $('#boOrderOo').val().trim()
        };
        $('#boLoading').show(); $('#boEmpty, #boTableWrap').hide();
        var btn = $('#btnSearchOrderForBind').prop('disabled', true);
        $.post(PAGE_URL, params, function (res) {
            btn.prop('disabled', false); $('#boLoading').hide();
            if (!res.success) { showAlert(res.message, 'warning'); return; }
            renderBoOrderTable(res.data);
        }, 'json').fail(function () { btn.prop('disabled', false); $('#boLoading').hide(); });
    }
    $('#btnSearchOrderForBind').on('click', doBoOrderSearch);
    $('#boClient, #boDId, #boOrderOo').on('keydown', function (e) { if (e.key === 'Enter') doBoOrderSearch(); });

    function renderBoOrderTable(rows) {
        var tbody = document.getElementById('boTableBody');
        tbody.innerHTML = '';
        if (!rows.length) { $('#boEmpty').show(); return; }
        var frag = document.createDocumentFragment();
        rows.forEach(function (r) {
            var bound = intval(r.allocated_sum), bomCnt = intval(r.bom_count);
            var bondCls = bomCnt === 0 ? 'bind-none' : (bound >= intval(r.Qty) ? 'bind-full' : 'bind-partial');
            var bondTxt = bomCnt === 0 ? '— 未綁定' : (bound >= intval(r.Qty) ? '✓ 已足量' : '部分 ' + bound + '/' + r.Qty);
            var tr = document.createElement('tr');
            tr.dataset.oid   = r.Order_id;
            tr.dataset.oo    = r.Order_oo;
            tr.dataset.client = r.client_display;
            tr.dataset.did   = r.d_id;
            tr.dataset.spec  = r.part_spec || '';
            tr.dataset.qty   = r.Qty;
            tr.dataset.ps    = r.order_ps || '';
            tr.dataset.odate = r.order_date;
            tr.dataset.ddate = r.delivery_date;
            tr.innerHTML =
                '<td><a href="NewOrder_Track.php?oo=' + encodeURIComponent(r.Order_oo) + '" target="_blank">' + escHtml(r.Order_oo) + '</a></td>' +
                '<td style="white-space:nowrap;">' + escHtml(r.order_date) + '</td>' +
                '<td style="white-space:nowrap;">' + escHtml(r.delivery_date) + '</td>' +
                '<td>' + escHtml(r.client_display) + '</td>' +
                '<td><strong>' + escHtml(r.d_id) + '</strong>' + (r.part_spec ? '<div class="cell-sub">' + escHtml(r.part_spec) + '</div>' : '') + '</td>' +
                '<td style="max-width:90px;white-space:normal;color:#718096;font-size:12px;">' + escHtml(r.order_ps || '') + '</td>' +
                '<td class="text-right">' + r.Qty + '</td>' +
                '<td class="text-right">' + (bound || '—') + '</td>' +
                '<td class="text-center"><span class="' + bondCls + '">' + bondTxt + '</span></td>' +
                '<td class="text-center"><button class="btn btn-xs btn-primary btn-open-bo-modal"><i class="fa fa-link"></i> 綁定</button></td>';
            frag.appendChild(tr);
        });
        tbody.appendChild(frag);
        $('#boTableWrap').show();
    }

    $(document).on('click', '.btn-open-bo-modal', function () {
        var $tr = $(this).closest('tr');
        boCurrentOrder = {
            order_id: intval($tr.data('oid')), order_oo: $tr.data('oo'),
            client: $tr.data('client'), d_id: $tr.data('did'),
            spec: $tr.data('spec'), qty: intval($tr.data('qty')),
            order_ps: $tr.data('ps'), odate: $tr.data('odate'), ddate: $tr.data('ddate')
        };
        boSelectedBom = null;
        $('#boModalBomInput, #boModalClientInput, #boModalProductInput').val('');
        $('#boModalBomList, #boModalRecommendList').empty();
        $('#boModalActionRow').hide(); $('#boModalBindQty').val('');
        $('#boModalTitle').text(boCurrentOrder.order_oo);
        $('#boModalOrderInfo').html(
            '<strong>' + escHtml(boCurrentOrder.order_oo) + '</strong> &nbsp;|&nbsp; 接單 ' + escHtml(boCurrentOrder.odate) +
            ' &nbsp;|&nbsp; 交期 ' + escHtml(boCurrentOrder.ddate) + ' &nbsp;|&nbsp; ' + escHtml(boCurrentOrder.client) +
            ' &nbsp;|&nbsp; <strong>' + escHtml(boCurrentOrder.d_id) + '</strong>' +
            (boCurrentOrder.spec ? ' <small class="text-muted">(' + escHtml(boCurrentOrder.spec) + ')</small>' : '') +
            ' &nbsp;|&nbsp; <strong>' + boCurrentOrder.qty + ' pcs</strong>' +
            (boCurrentOrder.order_ps ? ' &nbsp;|&nbsp; <span style="color:#718096;">' + escHtml(boCurrentOrder.order_ps) + '</span>' : ''));
        loadBoModalBindings();
        $('#boModalRecommendList').html('<span class="text-muted" style="font-size:12px;"><i class="fa fa-spinner fa-spin"></i></span>');
        $.post(PAGE_URL, { action: 'search_bom_for_bind', d_id: boCurrentOrder.d_id }, function (res) {
            if (!res.success || !res.data.length) { $('#boModalRecommendList').html('<span class="text-muted" style="font-size:12px;">無相同料號製令</span>'); return; }
            renderModalBomItems(res.data, '#boModalRecommendList');
        }, 'json');
        $('#bindOrderModal').modal('show');
    });

    function loadBoModalBindings() {
        $('#boModalBindingTags').html('<span class="text-muted" style="font-size:12px;">載入中…</span>');
        $.post(PAGE_URL, { action: 'get_order_bom_bindings', order_id: boCurrentOrder.order_id }, function (res) {
            if (!res.success) { $('#boModalBindingTags').html('<span class="text-muted">無法載入</span>'); return; }
            var $wrap = $('#boModalBindingTags').empty();
            if (!res.data.length) { $wrap.html('<span class="text-muted" style="font-size:12px;"><i class="fa fa-info-circle"></i> 尚未綁定任何製令</span>'); return; }
            res.data.forEach(function (b) {
                $wrap.append('<span class="binding-tag"><span style="font-weight:700;color:var(--pri);">' + escHtml(b.bom) + '</span>' +
                    '&nbsp;<span style="color:#555;">分配 ' + b.allocated_qty + ' pcs</span>' +
                    (b.bom_ps ? '&nbsp;<small style="color:#9CA3AF;">(' + escHtml(b.bom_ps) + ')</small>' : '') +
                    '<button class="del-bind" data-id="' + b.id + '" title="刪除此綁定">&times;</button></span>');
            });
        }, 'json');
    }

    $(document).on('click', '#boModalBindingTags .del-bind', function () {
        var id = $(this).data('id');
        if (!confirm('確定要移除此綁定？')) return;
        $.post(PAGE_URL, { action: 'delete_bom_order_binding', id: id }, function (res) {
            if (res.success) { loadBoModalBindings(); doBoOrderSearch(); }
            else showAlert(res.message, 'danger');
        }, 'json');
    });

    function doBoModalBomSearch() {
        var bom = $('#boModalBomInput').val().trim(), client = $('#boModalClientInput').val().trim(), product = $('#boModalProductInput').val().trim();
        if (!bom && !client && !product) { showAlert('請輸入製令號碼、客戶或料號', 'warning'); return; }
        $('#boModalBomList').html('<span class="text-muted" style="font-size:12px;"><i class="fa fa-spinner fa-spin"></i></span>');
        boSelectedBom = null; $('#boModalActionRow').hide();
        $.post(PAGE_URL, { action: 'search_bom_for_bind', bom: bom, d_id: product, client: client }, function (res) {
            if (!res.success || !res.data.length) { $('#boModalBomList').html('<span class="text-muted" style="font-size:12px;">查無符合製令</span>'); return; }
            renderModalBomItems(res.data, '#boModalBomList');
        }, 'json');
    }
    $('#btnBoModalSearchBom').on('click', doBoModalBomSearch);
    $('#boModalBomInput, #boModalClientInput, #boModalProductInput').on('keydown', function (e) { if (e.key === 'Enter') doBoModalBomSearch(); });

    $(document).on('click', '#boModalBomList .bom-search-item, #boModalRecommendList .bom-search-item', function () {
        $('#boModalBomList .bom-search-item, #boModalRecommendList .bom-search-item').removeClass('selected');
        $(this).addClass('selected');
        boSelectedBom = { bom: $(this).data('bom'), sqty: intval($(this).data('sqty')), unbound: intval($(this).data('unbound')) };
        var suggested = Math.min(boSelectedBom.unbound, boCurrentOrder ? boCurrentOrder.qty : 0);
        $('#boModalBindQty').val(suggested > 0 ? suggested : '');
        $('#boModalSelectedBomLabel').text(boSelectedBom.bom);
        $('#boModalBomHint').text('製令總量 ' + boSelectedBom.sqty + ' pcs，未綁 ' + boSelectedBom.unbound + ' pcs');
        $('#boModalActionRow').show(); $('#boModalBindQty').focus();
    });

    $('#btnBoModalSaveBind').on('click', function () {
        if (!boCurrentOrder || !boSelectedBom) return;
        var qty = intval($('#boModalBindQty').val());
        if (qty <= 0) { showAlert('請輸入分配數量', 'warning'); return; }
        var btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        $.post(PAGE_URL, { action: 'bind_bom_to_order', bom: boSelectedBom.bom, order_id: boCurrentOrder.order_id, qty: qty }, function (res) {
            btn.prop('disabled', false).html('<i class="fa fa-link"></i> 確認綁定');
            if (res.success) {
                showAlert(res.message, 'success'); loadBoModalBindings(); doBoOrderSearch();
                boSelectedBom = null; $('.bom-search-item').removeClass('selected');
                $('#boModalActionRow').hide(); $('#boModalBindQty').val('');
                $.post(PAGE_URL, { action: 'search_bom_for_bind', d_id: boCurrentOrder.d_id }, function (r) {
                    if (r.success && r.data.length) renderModalBomItems(r.data, '#boModalRecommendList');
                }, 'json');
            } else showAlert(res.message, 'danger');
        }, 'json').fail(function () { btn.prop('disabled', false).html('<i class="fa fa-link"></i> 確認綁定'); showAlert('網路錯誤', 'danger'); });
    });

    $('#bindOrderModal').on('hidden.bs.modal', function () { boCurrentOrder = boSelectedBom = null; });

    // ════════════════════════════════════════════════════════════════════════
    // Tab 2：手動搜尋出貨
    // ════════════════════════════════════════════════════════════════════════
    function updateSelectedCount() {
        var n = $('#orderTableBody input[type=checkbox]:checked').length;
        $('#selectedCount').text(n); $('#btnGetBoms').prop('disabled', n === 0);
    }

    $('#btnSearchOrders').on('click', function () {
        var btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        $.post(PAGE_URL, {
            action: 'search_orders', client: $('#f_client').val(), order_oo: $('#f_order_oo').val(),
            d_id: $('#f_d_id').val(), date_from: $('#f_date_from').val(), date_to: $('#f_date_to').val()
        }, function (res) {
            btn.prop('disabled', false).html('<i class="fa fa-search"></i> 搜尋');
            if (!res.success) { showAlert(res.message, 'danger'); return; }
            var data = res.data, tbody = document.getElementById('orderTableBody');
            tbody.innerHTML = '';
            $('#orderTable, #orderSelInfo').toggle(data.length > 0);
            $('#orderEmpty').toggle(data.length === 0);
            if (!data.length) return;
            var frag = document.createDocumentFragment();
            data.forEach(function (r) {
                var statusHtml = '';
                if (Array.isArray(r.bom_statuses) && r.bom_statuses.length > 0) {
                    r.bom_statuses.forEach(function (s) { statusHtml += getProgressLabel(s) + ' '; });
                } else if (parseInt(r.bom_count_total) > 0) {
                    statusHtml = '<span class="qs-badge bg-ok">' + r.bom_count_total + '</span>';
                } else {
                    statusHtml = '<span class="text-muted">—</span>';
                }
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><input type="checkbox" class="order-chk"' +
                        ' data-id="' + r.Order_id + '" data-oo="' + escHtml(r.Order_oo) + '"' +
                        ' data-client="' + escHtml(r.client_display) + '" data-clientid="' + escHtml(r.client_id || '') + '"' +
                        ' data-did="' + escHtml(r.d_id) + '" data-price="' + (r.unit_price || 0) + '"></td>' +
                    '<td><a href="NewOrder_Track.php?oo=' + encodeURIComponent(r.Order_oo) + '" target="_blank">' +
                        escHtml(r.Order_oo) + ' <i class="fa fa-external-link" style="font-size:10px;"></i></a></td>' +
                    '<td style="white-space:nowrap;">' + escHtml(r.Delivery_date || '') + '</td>' +
                    '<td>' + escHtml(r.client_display) + '</td>' +
                    '<td><strong>' + escHtml(r.d_id) + '</strong>' + (r.part_spec ? '<div class="cell-sub">' + escHtml(r.part_spec) + '</div>' : '') + '</td>' +
                    '<td style="max-width:110px;white-space:normal;font-size:12px;color:#718096;">' + escHtml(r.processing_items || '') + '</td>' +
                    '<td class="text-right">' + (r.Qty || '') + '</td>' +
                    '<td class="text-right">' + (r.undelivered_qty !== null ? r.undelivered_qty : '') + '</td>' +
                    '<td class="text-right">' + fmtPrice(r.unit_price) + '</td>' +
                    '<td style="max-width:110px;white-space:normal;font-size:12px;color:#718096;">' + escHtml(r.order_ps || '') + '</td>' +
                    '<td class="text-center bom-count-cell">' + statusHtml + '</td>';
                frag.appendChild(tr);
            });
            tbody.appendChild(frag);
            $('#orderCount').text('共 ' + data.length + ' 筆訂單');
            updateSelectedCount();
        }, 'json').fail(function () { btn.prop('disabled', false).html('<i class="fa fa-search"></i> 搜尋'); showAlert('網路錯誤，請重試', 'danger'); });
    });

    $('#btnSelectAll').on('click',   function () { $('#orderTableBody .order-chk').prop('checked', true);  updateSelectedCount(); });
    $('#btnDeselectAll').on('click', function () { $('#orderTableBody .order-chk').prop('checked', false); updateSelectedCount(); });
    $(document).on('change', '.order-chk', updateSelectedCount);
    $('#f_client, #f_order_oo, #f_d_id').on('keydown', function (e) { if (e.key === 'Enter') $('#btnSearchOrders').click(); });

    $('#btnGetBoms').on('click', function () {
        var checkedBoxes = $('#orderTableBody .order-chk:checked');
        if (!checkedBoxes.length) { showAlert('請先勾選訂單', 'warning'); return; }
        var orderIds = [];
        checkedBoxes.each(function () { orderIds.push($(this).data('id')); });
        var btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 查詢中…');
        $.post(PAGE_URL, { action: 'get_boms_for_orders', order_ids: JSON.stringify(orderIds) }, function (res) {
            btn.prop('disabled', false).html('<i class="fa fa-cogs"></i> 查詢製令 <span class="badge" style="background:#4B5563;">' + orderIds.length + '</span>');
            if (!res.success) { showAlert(res.message, 'danger'); return; }
            bomRows = res.data;
            renderBomTable(bomRows);
            $('#bomSection').show();
            setStep(2);
            $('html, body').animate({ scrollTop: $('#bomSection').offset().top - 20 }, 300);
        }, 'json').fail(function () { btn.prop('disabled', false).html('<i class="fa fa-cogs"></i> 查詢製令'); showAlert('網路錯誤，請重試', 'danger'); });
    });

    function renderBomTable(data) {
        var tbody = document.getElementById('bomTableBody');
        tbody.innerHTML = '';
        $('#bomEmpty').toggle(data.length === 0);
        if (!data.length) return;
        var frag = document.createDocumentFragment();
        data.forEach(function (r, idx) {
            var stateLabel = getBomStateLabel(r);
            var priorCls  = r.priority_type === 'E' ? 'pri-E' : (r.priority_type === 'U' ? 'pri-U' : '');
            var priorIcon = r.priority_type === 'E' ? '🔴' : (r.priority_type === 'U' ? '🟡' : '');
            var spec      = r.order_spec || r.part_spec || '';
            var tr        = document.createElement('tr');
            tr.dataset.idx = idx;
            tr.innerHTML =
                '<td><span class="' + priorCls + '">' + priorIcon + escHtml(r.bom) + '</span></td>' +
                '<td>' + escHtml(r.Order_oo) + '</td>' +
                '<td>' + escHtml(r.client_display || r.client_name) + '</td>' +
                '<td><strong>' + escHtml(r.d_id) + '</strong>' + (r.part_spec ? '<div class="cell-sub">' + escHtml(r.part_spec) + '</div>' : '') + '</td>' +
                '<td><input type="text" class="form-control input-sm bom-spec-input" value="' + escHtml(spec) + '"></td>' +
                '<td class="text-right">' + (r.bom_qty || '') + '</td>' +
                '<td class="text-right">' + (r.allocated_qty !== null ? r.allocated_qty : '') + '</td>' +
                '<td class="text-center">' + stateLabel + '</td>' +
                '<td><input type="number" class="form-control input-sm bom-qty-input" min="0" placeholder="0"></td>' +
                '<td><input type="number" class="form-control input-sm bom-price-input" min="0" step="any" value="' + fmtPrice(r.order_unit_price) + '"></td>' +
                '<td><input type="text" class="form-control input-sm bom-note-input" placeholder="備註…"></td>';
            frag.appendChild(tr);
        });
        tbody.appendChild(frag);
    }

    $('#btnBackToOrders').on('click', function () {
        $('#bomSection').hide(); bomRows = []; setStep(1);
    });

    $('#btnSubmit').on('click', function () {
        var items = [], hasQty = false;
        $('#bomTableBody tr').each(function () {
            var idx = intval($(this).data('idx')), r = bomRows[idx];
            var qty = intval($(this).find('.bom-qty-input').val());
            if (qty > 0) {
                hasQty = true;
                items.push({
                    bom: r.bom, order_id: r.order_id, product_id: r.d_id,
                    d_setting_id: r.d_setting_id || '', client_id: r.client_id || '',
                    client_name: r.client_display || r.client_name,
                    specification: $(this).find('.bom-spec-input').val(),
                    qty: qty, unit_price: parseFloat($(this).find('.bom-price-input').val()) || 0,
                    note: $(this).find('.bom-note-input').val()
                });
            }
        });
        if (!hasQty) { showAlert('請至少輸入一筆出貨數量（> 0）', 'warning'); return; }
        var shipDate = $('#shipDate').val();
        if (!shipDate) { showAlert('請填入出貨日期', 'warning'); return; }
        if (!confirm('確定要建立 ' + items.length + ' 筆出貨單嗎？\n出貨日期：' + shipDate)) return;
        setStep(3);
        var btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 建立中…');
        $.post(PAGE_URL, { action: 'create_shipments', ship_date: shipDate, items: JSON.stringify(items) }, function (res) {
            btn.prop('disabled', false).html('<i class="fa fa-check"></i> 建立出貨單');
            if (res.success) {
                var msg = res.message;
                if (res.errors && res.errors.length) msg += '<br><small>略過：' + res.errors.join('；') + '</small>';
                showAlert(msg, 'success');
                $('#bomSection').hide(); $('#bomTableBody').empty();
                $('#orderTableBody .order-chk').prop('checked', false);
                updateSelectedCount(); bomRows = []; setStep(1);
            } else {
                showAlert(res.message + (res.errors && res.errors.length ? '<br><small>' + res.errors.join('；') + '</small>' : ''), 'danger');
                setStep(2);
            }
        }, 'json').fail(function () { btn.prop('disabled', false).html('<i class="fa fa-check"></i> 建立出貨單'); showAlert('網路錯誤，請重試', 'danger'); setStep(2); });
    });

    // ── 進度標籤 ─────────────────────────────────────────────────────────────
    function getProgressLabel(s) {
        var total = parseInt(s.process_total) || 0, pos = parseInt(s.current_pos) || 0, state = s.current_state || '';
        if (total === 0 || pos === 0) return '<span class="qs-badge bg-def bom-status">待加工</span>';
        var cls = 'bg-def', stName = '—';
        switch (state) {
            case 'ing': cls = 'bg-warn'; stName = '加工中'; break;
            case 'Q':   cls = 'bg-info'; stName = 'QC待驗'; break;
            case 'P':   cls = 'bg-def';  stName = '待移轉'; break;
            case 'E':   cls = 'bg-ok';   stName = '已移轉'; break;
            case 'N':   cls = 'bg-def';  stName = '待加工'; break;
            default:    stName = state || '—';
        }
        return '<span class="qs-badge ' + cls + ' bom-status">' + escHtml(stName) + ' <strong>' + pos + '/' + total + '</strong></span>';
    }

    function getBomStateLabel(r) {
        if (r.bom_processing_state === '1') return '<span class="qs-badge bg-def bom-status">ERP結案</span>';
        if ((parseInt(r.process_total) || 0) > 0) return getProgressLabel(r);
        switch (r.bom_state) {
            case 'ing': return '<span class="qs-badge bg-warn bom-status">加工中</span>';
            case 'Q':   return '<span class="qs-badge bg-info bom-status">QC待驗</span>';
            case 'P':   return '<span class="qs-badge bg-def bom-status">待移轉</span>';
            default:    return '<span class="qs-badge bg-def bom-status">' + escHtml(r.bom_state || '—') + '</span>';
        }
    }

    // ── 頁面載入 ─────────────────────────────────────────────────────────────
    loadSuggestions();

}(jQuery));
</script>
</body>
</html>
