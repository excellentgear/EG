<?php
/**
 * src/common/shipping_lib.php — 出貨作業共用函式庫
 *
 * 供 views/Sales/Shipping_Quick.php（新版快速出貨）與 src/store/Shipping_API.php 共用。
 *
 * ⚠ 重要：製令「完工可出量」一律呼叫 sq_bom_avail_map()。
 *   舊版 Quick_Shipping.php 用 `SUM(sqty) WHERE processing_state='E'` 是錯的——
 *   bom_ing.bom_sn 是「製程序號」(10/20/30…)，每一列的 sqty 都是同一批的數量，
 *   跨製程加總會把 10 支的製令算成 40 支，且最後一道還在加工中也會被當成可出。
 *   正確：bom.processing_state='1'(ERP結案) → bom.sqty；
 *         否則看最後一道製程(MAX bom_sn)是否為 'E'(生管已移轉) → 取該道 sqty；否則 0。
 */

if (!defined('SQ_MODULE')) define('SQ_MODULE', 'shipping');

/* ============================================================
 * 權限（RBAC，比照 vendor_audit_lib.php）
 * ============================================================ */
function sq_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname = ?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sq_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                        WHERE ur.user_id = ? AND r.module = '" . SQ_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id = m.position_id
                        JOIN roles r ON r.role_id = pr.role_id
                        WHERE m.user_id = ? AND r.module = '" . SQ_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function sq_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canEdit' => false, 'canView' => false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin  || sq_has_role($db, $uid, ['shipping_admin']);
    $canEdit  = $canAdmin || sq_has_role($db, $uid, ['shipping_edit']);
    $canView  = $canEdit  || sq_has_role($db, $uid, ['shipping_view']);
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canEdit' => $canEdit, 'canView' => $canView];
}

/* ============================================================
 * 製令完工可出量
 * ============================================================ */

/**
 * 取得製令的完工／已出／可出量。
 *
 * @param array|null $boms 指定製令號；null = 全部（僅取 done_qty > 0 者）
 * @return array bom => ['done'=>int,'shipped'=>int,'avail'=>int,'closed'=>bool]
 */
function sq_bom_avail_map(PDO $db, ?array $boms = null): array
{
    $params = [];
    $where  = '';
    if ($boms !== null) {
        $boms = array_values(array_unique(array_filter($boms, fn($b) => $b !== '' && $b !== null)));
        if (!$boms) return [];
        $ph     = implode(',', array_fill(0, count($boms), '?'));
        $where  = "WHERE b.bom IN ($ph)";
        $params = $boms;
    }

    // 最後一道製程（同 bom_sn 有重複列時取 bom_ing_fid 最大者）
    $sql = "
        SELECT b.bom,
               CASE WHEN b.processing_state = '1' THEN b.sqty
                    WHEN bl.processing_state = 'E' THEN bl.sqty
                    ELSE 0 END                       AS done_qty,
               COALESCE(sm.shipped, 0)               AS shipped_qty,
               CASE WHEN b.processing_state = '1' THEN 1 ELSE 0 END AS erp_closed
        FROM bom b
        LEFT JOIN (
            SELECT bi.bom, bi.processing_state, bi.sqty
            FROM bom_ing bi
            JOIN (SELECT bom, MAX(bom_sn) AS msn FROM bom_ing GROUP BY bom) mx
              ON mx.bom = bi.bom AND mx.msn = bi.bom_sn
            JOIN (SELECT bom, bom_sn, MAX(bom_ing_fid) AS mf FROM bom_ing GROUP BY bom, bom_sn) dd
              ON dd.bom = bi.bom AND dd.bom_sn = bi.bom_sn AND dd.mf = bi.bom_ing_fid
        ) bl ON bl.bom = b.bom
        LEFT JOIN (SELECT bom, SUM(shipped_qty) AS shipped FROM is_bom_map GROUP BY bom) sm
          ON sm.bom = b.bom
        $where";

    $st = $db->prepare($sql);
    $st->execute($params);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $done    = (int)$r['done_qty'];
        $shipped = (int)$r['shipped_qty'];
        if ($boms === null && $done <= 0) continue;   // 全域查詢時略過未完工，減少記憶體
        $map[$r['bom']] = [
            'done'    => $done,
            'shipped' => $shipped,
            'avail'   => max(0, $done - $shipped),
            'closed'  => ((int)$r['erp_closed'] === 1),
        ];
    }
    return $map;
}

/**
 * 取得指定訂單所綁定的製令（兩種綁定方式合併）。
 * A：bom_order_process_map（有分配量）  B：bom.o_order_id = order_track.Order_oo（全量）
 *
 * @return array order_id => [ ['bom'=>,'allocated'=>,'delivery'=>,'priority'=>,'bom_qty'=>,'bom_ps'=>], ... ]
 */
function sq_boms_for_orders(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_map('intval', array_filter($orderIds))));
    if (!$orderIds) return [];
    $ph = implode(',', array_fill(0, count($orderIds), '?'));

    $sql = "
        SELECT bopm.order_id, b.bom, bopm.allocated_qty AS allocated, b.sqty AS bom_qty,
               DATE_FORMAT(b.Delivery_date,'%Y-%m-%d') AS delivery,
               COALESCE(b.priority_type,'') AS priority, COALESCE(b.bom_ps,'') AS bom_ps
        FROM bom_order_process_map bopm
        JOIN bom b ON b.bom = bopm.bom
        WHERE bopm.order_id IN ($ph)
        UNION
        SELECT ot.Order_id AS order_id, b.bom, b.sqty AS allocated, b.sqty AS bom_qty,
               DATE_FORMAT(b.Delivery_date,'%Y-%m-%d') AS delivery,
               COALESCE(b.priority_type,'') AS priority, COALESCE(b.bom_ps,'') AS bom_ps
        FROM bom b
        JOIN order_track ot ON ot.Order_oo = b.o_order_id
        WHERE ot.Order_id IN ($ph)
          AND NOT EXISTS (SELECT 1 FROM bom_order_process_map x
                          WHERE x.bom = b.bom AND x.order_id = ot.Order_id)";

    $st = $db->prepare($sql);
    $st->execute(array_merge($orderIds, $orderIds));

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['order_id']][] = [
            'bom'       => $r['bom'],
            'allocated' => (int)$r['allocated'],
            'bom_qty'   => (int)$r['bom_qty'],
            'delivery'  => $r['delivery'],
            'priority'  => $r['priority'],
            'bom_ps'    => $r['bom_ps'],
        ];
    }
    return $out;
}

/** 以製令號關鍵字反查對應的訂單 Order_id（兩種綁定方式合併，最多 2000 筆） */
function sq_order_ids_by_bom_kw(PDO $db, string $kw): array
{
    $like = '%' . $kw . '%';
    $st = $db->prepare("
        SELECT bopm.order_id AS oid FROM bom_order_process_map bopm WHERE bopm.bom LIKE ?
        UNION
        SELECT ot.Order_id AS oid FROM bom b
        JOIN order_track ot ON ot.Order_oo = b.o_order_id
        WHERE b.bom LIKE ?
        LIMIT 2000");
    $st->execute([$like, $like]);
    return array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'oid'));
}

/* ============================================================
 * 待出貨清單（一列一訂單，製令為附屬資訊）
 * ============================================================ */

/**
 * @param array $f 篩選：kw, client_id, date_from, date_to, only_ready(bool),
 *                       include_paused(bool), page(int), per_page(int), sort
 * @return array ['rows'=>分頁後資料, 'total'=>符合筆數, 'summary'=>全部符合條件的合計]
 */
function sq_pending_orders(PDO $db, array $f): array
{
    // 排除無訂單號／訂單號 NA 的列：多半是廠內治具製作，不是要出給客戶的貨
    $where  = ["(ot.Order_status IS NULL OR ot.Order_status <> 9)",
               "ot.Order_oo IS NOT NULL",
               "TRIM(ot.Order_oo) <> ''",
               "UPPER(REPLACE(TRIM(ot.Order_oo), '.', '')) NOT IN ('NA', 'N/A')"];
    $params = [];

    if (empty($f['include_paused'])) {
        $where[] = "(ot.Order_status IS NULL OR ot.Order_status <> 6)";
    }
    if (!empty($f['client_id'])) {
        $where[] = "ot.Client_name_ID = :client_id";
        $params[':client_id'] = $f['client_id'];
    }
    if (!empty($f['date_from'])) {
        $where[] = "ot.Delivery_date >= :date_from";
        $params[':date_from'] = $f['date_from'];
    }
    if (!empty($f['date_to'])) {
        $where[] = "ot.Delivery_date <= :date_to";
        $params[':date_to'] = $f['date_to'];
    }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        // 單一搜尋框通吃：訂單號／料號／客戶／品名規格／製令號
        // 製令號不用 correlated EXISTS（7800 筆訂單逐列子查詢要 20 秒以上），
        // 改成先一次解析出對應的 Order_id 再用 IN。
        $conds = ["ot.Order_oo LIKE :kw", "ot.d_id LIKE :kw",
                  "ot.Client_name LIKE :kw", "ot.Specification LIKE :kw"];
        $bomOids = sq_order_ids_by_bom_kw($db, $kw);
        if ($bomOids) $conds[] = "ot.Order_id IN (" . implode(',', $bomOids) . ")";
        $where[] = '(' . implode(' OR ', $conds) . ')';
        $params[':kw'] = '%' . $kw . '%';
    }

    $sql = "
        SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.d_id_ID, ot.Specification, ot.Order_ps,
               ot.Client_name, ot.Client_name_ID, ot.Qty, ot.unit_price, ot.Order_status,
               COALESCE(ot.Processing_items,'')          AS processing_items,
               DATE_FORMAT(ot.Order_date,'%Y-%m-%d')     AS order_date,
               DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d')  AS delivery_date,
               COALESCE(cl.customer, ot.Client_name)     AS client_display,
               cl.customer_id,
               COALESCE(ds.Spec_No,'')                   AS part_spec,
               COALESCE(sh.sq, 0)                        AS shipped_qty
        FROM order_track ot
        LEFT JOIN (SELECT Order_id, SUM(Qty) AS sq FROM is_list
                   WHERE Order_id IS NOT NULL GROUP BY Order_id) sh ON sh.Order_id = ot.Order_id
        LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
        LEFT JOIN d_setting   ds ON ds.d_id = ot.d_id_ID
        WHERE " . implode(' AND ', $where) . "
        HAVING ot.Qty - shipped_qty > 0";

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['rows' => [], 'total' => 0,
                'summary' => ['orders' => 0, 'remain' => 0, 'ready' => 0, 'amount' => 0]];
    }

    // 附加製令與可出量
    $orderIds = array_column($rows, 'Order_id');
    $bomsByOrder = sq_boms_for_orders($db, $orderIds);
    $allBoms = [];
    foreach ($bomsByOrder as $list) foreach ($list as $b) $allBoms[] = $b['bom'];
    $availMap = sq_bom_avail_map($db, $allBoms);

    $out = [];
    foreach ($rows as $r) {
        $oid    = (int)$r['Order_id'];
        $remain = (int)$r['Qty'] - (int)$r['shipped_qty'];
        $boms   = $bomsByOrder[$oid] ?? [];

        // 依交期排序製令（FIFO：先到期的先出）
        usort($boms, fn($a, $b) => strcmp($a['delivery'] ?: '9999', $b['delivery'] ?: '9999')
                                   ?: strcmp($a['bom'], $b['bom']));

        $readyTotal = 0;
        $bomView    = [];
        foreach ($boms as $b) {
            $av    = $availMap[$b['bom']] ?? ['done' => 0, 'shipped' => 0, 'avail' => 0, 'closed' => false];
            $canUse = min($av['avail'], $b['allocated'] > 0 ? $b['allocated'] : $av['avail']);
            $readyTotal += max(0, $canUse);
            $bomView[] = [
                'bom'       => $b['bom'],
                'bom_qty'   => $b['bom_qty'],
                'allocated' => $b['allocated'],
                'done'      => $av['done'],
                'shipped'   => $av['shipped'],
                'avail'     => max(0, $canUse),
                'closed'    => $av['closed'],
                'delivery'  => $b['delivery'],
                'priority'  => $b['priority'],
                'bom_ps'    => $b['bom_ps'],
            ];
        }
        $ready = min($remain, $readyTotal);

        $out[] = [
            'order_id'         => $oid,
            'order_oo'         => $r['Order_oo'],
            'd_id'             => $r['d_id'],
            'd_setting_id'     => $r['d_id_ID'] !== null ? (int)$r['d_id_ID'] : null,
            'specification'    => $r['Specification'],
            'part_spec'        => $r['part_spec'],
            'order_ps'         => $r['Order_ps'],
            'processing_items' => $r['processing_items'],
            'client_id'        => $r['customer_id'] ?: ($r['Client_name_ID'] ?: null),
            'client_name'      => $r['Client_name'],
            'client_display'   => $r['client_display'],
            'order_qty'        => (int)$r['Qty'],
            'shipped_qty'      => (int)$r['shipped_qty'],
            'remain_qty'       => $remain,
            'ready_qty'        => $ready,
            'unit_price'       => (float)$r['unit_price'],
            'order_date'       => $r['order_date'],
            'delivery_date'    => $r['delivery_date'],
            'order_status'     => $r['Order_status'] !== null ? (int)$r['Order_status'] : null,
            'boms'             => $bomView,
            'bom_count'        => count($bomView),
        ];
    }

    if (!empty($f['only_ready'])) {
        $out = array_values(array_filter($out, fn($r) => $r['ready_qty'] > 0));
    }

    // 全部符合條件才算合計（不可只用當頁）
    $summary = [
        'orders' => count($out),
        'remain' => array_sum(array_column($out, 'remain_qty')),
        'ready'  => array_sum(array_column($out, 'ready_qty')),
        'amount' => 0,
    ];
    foreach ($out as $r) $summary['amount'] += $r['ready_qty'] * $r['unit_price'];

    // 排序（sort 欄位 + dir 方向；預設可立即出貨者優先、再依交期）
    $sort = $f['sort'] ?? 'delivery';
    $dir  = (($f['dir'] ?? 'asc') === 'desc') ? -1 : 1;
    usort($out, function ($a, $b) use ($sort, $dir) {
        switch ($sort) {
            case 'order_oo': return $dir * strnatcasecmp($a['order_oo'], $b['order_oo']);
            case 'd_id':     return $dir * strnatcasecmp($a['d_id'], $b['d_id']);
            case 'client':   return $dir * strnatcasecmp($a['client_display'], $b['client_display']);
            case 'ready':    return $dir * ($a['ready_qty'] <=> $b['ready_qty']);
            case 'remain':   return $dir * ($a['remain_qty'] <=> $b['remain_qty']);
            case 'delivery': return $dir * strcmp($a['delivery_date'] ?: '9999-12-31',
                                                  $b['delivery_date'] ?: '9999-12-31');
            default:
                // 預設：可立即出貨的排前面，再依交期
                if (($a['ready_qty'] > 0) !== ($b['ready_qty'] > 0)) return $b['ready_qty'] > 0 ? 1 : -1;
                return strcmp($a['delivery_date'] ?: '9999-12-31', $b['delivery_date'] ?: '9999-12-31');
        }
    });

    $total   = count($out);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) {                       // 0 = 取全部（匯出用）
        return ['rows' => $out, 'total' => $total, 'page' => 1,
                'per_page' => 0, 'summary' => $summary];
    }
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page   = max(1, (int)($f['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    return [
        'rows'     => array_slice($out, $offset, $perPage),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'summary'  => $summary,
    ];
}

/* ============================================================
 * 建立出貨單（一張單多個料號明細，比照 ERP 現行結構）
 * ============================================================ */

/** 取得指定日期的下一個出貨單號：IS + 民國年(3) + MMDD + 序號(3) */
function sq_next_is_number(PDO $db, string $shipDate): string
{
    $y      = (int)substr($shipDate, 0, 4) - 1911;
    $prefix = 'IS' . str_pad((string)$y, 3, '0', STR_PAD_LEFT) . substr($shipDate, 5, 2) . substr($shipDate, 8, 2);
    $st = $db->prepare("SELECT MAX(CAST(SUBSTRING(IS_number, 10) AS UNSIGNED)) FROM is_list WHERE IS_number LIKE ?");
    $st->execute([$prefix . '%']);
    $seq = (int)$st->fetchColumn() + 1;
    return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/**
 * 建立出貨單。同一客戶 + 同一出貨日 → 共用一個 IS_number（一單多明細）。
 *
 * @param array $items 每筆：order_id, d_setting_id, product_id, specification,
 *                          client_id, client_name, qty, unit_price, note, warehouse, boms[]
 * @return array ['success'=>bool,'shipments'=>[單號=>明細數],'closed_orders'=>[],'errors'=>[],'message'=>]
 */
function sq_create_shipment(PDO $db, string $shipDate, array $items, string $userId): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shipDate)) {
        return ['success' => false, 'message' => '出貨日期格式錯誤'];
    }
    $clean = [];
    $errors = [];
    foreach ($items as $i => $it) {
        $qty = (int)($it['qty'] ?? 0);
        if ($qty <= 0) continue;
        $name = trim((string)($it['client_name'] ?? ''));
        $pid  = trim((string)($it['product_id'] ?? ''));
        if ($name === '') { $errors[] = "第" . ($i + 1) . "列缺少客戶，已略過"; continue; }
        if ($pid  === '') { $errors[] = "第" . ($i + 1) . "列缺少料號，已略過"; continue; }
        $clean[] = $it + ['qty' => $qty, 'client_name' => $name, 'product_id' => $pid];
    }
    if (!$clean) {
        return ['success' => false, 'message' => '沒有有效的出貨資料（數量需大於 0）', 'errors' => $errors];
    }

    // 依客戶分組 → 一組一張出貨單
    $groups = [];
    foreach ($clean as $it) {
        $key = ($it['client_id'] ?? '') . '|' . $it['client_name'];
        $groups[$key][] = $it;
    }

    try {
        $db->beginTransaction();

        $insIs = $db->prepare(
            "INSERT INTO is_list
             (Order_date, IS_number, Client_id, Client_name, d_setting_id, Product_id,
              Specification, Qty, Unit_price, Order_id, Warehouse, Note, Created_By, Created_At)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
        );
        $insMap = $db->prepare(
            "INSERT INTO is_bom_map (IS_id, bom, shipped_qty) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE shipped_qty = shipped_qty + VALUES(shipped_qty)"
        );

        $shipments   = [];
        $ordersUsed  = [];

        foreach ($groups as $rows) {
            $isNumber = sq_next_is_number($db, $shipDate);
            $cnt = 0;
            foreach ($rows as $it) {
                $orderId = !empty($it['order_id']) ? (int)$it['order_id'] : null;
                $insIs->execute([
                    $shipDate,
                    $isNumber,
                    ($it['client_id'] ?? '') !== '' ? $it['client_id'] : null,
                    $it['client_name'],
                    !empty($it['d_setting_id']) ? (int)$it['d_setting_id'] : null,
                    $it['product_id'],
                    trim((string)($it['specification'] ?? '')),
                    $it['qty'],
                    (float)($it['unit_price'] ?? 0),
                    $orderId,
                    trim((string)($it['warehouse'] ?? '')) ?: null,
                    trim((string)($it['note'] ?? '')),
                    $userId,
                ]);
                $isId = (int)$db->lastInsertId();

                // 製令扣帳：依前端帶回的分配結果；未帶則自動 FIFO 分配
                $alloc = $it['boms'] ?? [];
                if (!$alloc && $orderId) $alloc = sq_auto_allocate($db, $orderId, $it['qty']);
                foreach ($alloc as $a) {
                    $bq = (int)($a['qty'] ?? 0);
                    if ($bq > 0 && !empty($a['bom'])) $insMap->execute([$isId, $a['bom'], $bq]);
                }
                if ($orderId) $ordersUsed[$orderId] = true;
                $cnt++;
            }
            $shipments[$isNumber] = $cnt;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立失敗：' . $e->getMessage()];
    }

    // 自動結案（交易外，失敗不影響已建立的出貨單）
    $closed = [];
    foreach (array_keys($ordersUsed) as $oid) {
        try {
            $st = $db->prepare("
                SELECT ot.Qty,
                       COALESCE(SUM(CASE WHEN ist.is_count IS NULL OR ist.is_count <> 0 THEN il.Qty ELSE 0 END), 0) AS shipped
                FROM order_track ot
                LEFT JOIN is_list il ON il.Order_id = ot.Order_id
                LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
                WHERE ot.Order_id = ? AND (ot.Order_status IS NULL OR ot.Order_status NOT IN (6, 9))
                GROUP BY ot.Order_id, ot.Qty");
            $st->execute([$oid]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if ($c && (int)$c['shipped'] >= (int)$c['Qty']) {
                $db->prepare("UPDATE order_track SET Order_status = 9, Modified_At = NOW(), Modified_By = ?
                              WHERE Order_id = ?")->execute([$userId, $oid]);
                $closed[] = $oid;
            }
        } catch (Throwable $e) { /* 結案失敗不阻斷出貨 */ }
    }

    $msg = '已建立 ' . count($shipments) . ' 張出貨單（共 ' . array_sum($shipments) . ' 筆明細）';
    if ($closed) $msg .= '，' . count($closed) . ' 筆訂單自動結案';

    return ['success' => true, 'shipments' => $shipments, 'closed_orders' => $closed,
            'errors' => $errors, 'message' => $msg];
}

/** 依訂單的製令可出量做 FIFO 分配（交期早的先出） */
function sq_auto_allocate(PDO $db, int $orderId, int $qty): array
{
    $boms = sq_boms_for_orders($db, [$orderId])[$orderId] ?? [];
    if (!$boms) return [];
    usort($boms, fn($a, $b) => strcmp($a['delivery'] ?: '9999', $b['delivery'] ?: '9999')
                               ?: strcmp($a['bom'], $b['bom']));
    $avail = sq_bom_avail_map($db, array_column($boms, 'bom'));

    $out = [];
    $left = $qty;
    foreach ($boms as $b) {
        if ($left <= 0) break;
        $can = $avail[$b['bom']]['avail'] ?? 0;
        if ($b['allocated'] > 0) $can = min($can, $b['allocated']);
        if ($can <= 0) continue;
        $take = min($can, $left);
        $out[] = ['bom' => $b['bom'], 'qty' => $take];
        $left -= $take;
    }
    return $out;
}

/* ============================================================
 * 舊資料回填：is_list.Order_id 幾乎全空（42071 筆僅 1 筆有值），
 * 導致訂單未出量算不出來。用「客戶簡稱 + 料號id(d_setting_id) + 日期先後」
 * 比對出候選，交由人工確認後才寫入（不自動寫）。
 * 料號一律用 d_setting_id 歸戶，不可用料號字串 join（有 159 個重複料號會灌水）。
 * ============================================================ */

/**
 * 產生回填候選。同一 (客戶, 料號) 群組內，出貨依日期 FIFO 吃訂單剩餘量。
 * @return array ['pairs'=>[...], 'summary'=>[...]]
 */
function sq_match_preview(PDO $db, string $from, string $to): array
{
    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
               il.Client_name, il.Product_id, il.d_setting_id, il.Qty, il.Unit_price
        FROM is_list il
        WHERE il.Order_id IS NULL AND il.d_setting_id IS NOT NULL
          AND il.Order_date BETWEEN ? AND ?
        ORDER BY il.Order_date, il.IS_id");
    $st->execute([$from, $to]);
    $ships = $st->fetchAll(PDO::FETCH_ASSOC);

    $summary = ['ship_rows' => count($ships), 'matched' => 0, 'unmatched' => 0, 'qty_matched' => 0];
    if (!$ships) return ['pairs' => [], 'summary' => $summary];

    // 取這批料號相關的訂單（含已結案，回填要涵蓋歷史）
    $dids = array_values(array_unique(array_map('intval', array_column($ships, 'd_setting_id'))));
    $ph   = implode(',', array_fill(0, count($dids), '?'));
    $so   = $db->prepare("
        SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.d_id, ot.d_id_ID, ot.Qty,
               ot.unit_price, ot.Order_status,
               DATE_FORMAT(ot.Order_date,'%Y-%m-%d')    AS order_date,
               DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS delivery_date,
               COALESCE(sh.sq, 0) AS used_qty
        FROM order_track ot
        LEFT JOIN (SELECT Order_id, SUM(Qty) AS sq FROM is_list
                   WHERE Order_id IS NOT NULL GROUP BY Order_id) sh ON sh.Order_id = ot.Order_id
        WHERE ot.d_id_ID IN ($ph)
        ORDER BY ot.Order_date, ot.Order_id");
    $so->execute($dids);

    // 依 (客戶簡稱, 料號id) 分組
    $ordersByKey = [];
    foreach ($so->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $key = $o['Client_name'] . '|' . (int)$o['d_id_ID'];
        $o['left'] = max(0, (int)$o['Qty'] - (int)$o['used_qty']);
        $ordersByKey[$key][] = $o;
    }

    $pairs = [];
    foreach ($ships as $s) {
        $key  = $s['Client_name'] . '|' . (int)$s['d_setting_id'];
        $cand = $ordersByKey[$key] ?? [];
        $hit  = null;

        foreach ($cand as $idx => $o) {
            if ($o['left'] <= 0) continue;
            if ($o['order_date'] > $s['ship_date']) continue;   // 不可出在下單之前
            $ordersByKey[$key][$idx]['left'] = $o['left'] - (int)$s['Qty'];
            $hit = $o;
            break;
        }

        if (!$hit) { $summary['unmatched']++; continue; }

        $exact      = ((int)$hit['left'] === (int)$s['Qty']);
        $over       = ((int)$s['Qty'] > (int)$hit['left']);   // 出貨量超過訂單剩餘量
        $priceMatch = (abs((float)$hit['unit_price'] - (float)$s['Unit_price']) < 0.01);
        $pairs[] = [
            'is_id'        => (int)$s['IS_id'],
            'is_number'    => $s['IS_number'],
            'ship_date'    => $s['ship_date'],
            'client_name'  => $s['Client_name'],
            'product_id'   => $s['Product_id'],
            'ship_qty'     => (int)$s['Qty'],
            'ship_price'   => (float)$s['Unit_price'],
            'order_id'     => (int)$hit['Order_id'],
            'order_oo'     => $hit['Order_oo'],
            'order_date'   => $hit['order_date'],
            'order_qty'    => (int)$hit['Qty'],
            'order_left'   => (int)$hit['left'],
            'order_price'  => (float)$hit['unit_price'],
            // 信心：high=數量剛好吃完且單價相符；low=出貨量超過訂單剩餘量（需人工判斷）；其餘 mid
            'confidence'   => $over ? 'low' : (($exact && $priceMatch) ? 'high' : 'mid'),
            'price_match'  => $priceMatch,
            'over_qty'     => $over,
        ];
        $summary['matched']++;
        $summary['qty_matched'] += (int)$s['Qty'];
    }

    return ['pairs' => $pairs, 'summary' => $summary];
}

/** 寫入回填結果（僅覆寫 Order_id 仍為 NULL 的列，避免蓋掉已正確的資料） */
function sq_match_apply(PDO $db, array $pairs, string $userId): array
{
    $applied = 0; $skipped = 0;
    try {
        $db->beginTransaction();
        $up = $db->prepare("UPDATE is_list SET Order_id = ? WHERE IS_id = ? AND Order_id IS NULL");
        foreach ($pairs as $p) {
            $isId = (int)($p['is_id'] ?? 0);
            $oid  = (int)($p['order_id'] ?? 0);
            if ($isId <= 0 || $oid <= 0) { $skipped++; continue; }
            $up->execute([$oid, $isId]);
            if ($up->rowCount() > 0) $applied++; else $skipped++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '回填失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'applied' => $applied, 'skipped' => $skipped,
            'message' => "已回填 {$applied} 筆" . ($skipped ? "，略過 {$skipped} 筆（已有訂單或資料異動）" : '')];
}

/* ============================================================
 * 已出貨單查詢（當日／近期，供出貨後檢視與列印送貨單）
 * ============================================================ */
function sq_recent_shipments(PDO $db, array $f): array
{
    $where  = [];
    $params = [];
    if (!empty($f['date_from'])) { $where[] = "il.Order_date >= :df"; $params[':df'] = $f['date_from']; }
    if (!empty($f['date_to']))   { $where[] = "il.Order_date <= :dt"; $params[':dt'] = $f['date_to']; }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(il.IS_number LIKE :kw OR il.Client_name LIKE :kw OR il.Product_id LIKE :kw)";
        $params[':kw'] = '%' . $kw . '%';
    }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT il.IS_number,
                   DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
                   il.Client_name, MAX(il.Client_id) AS client_id,
                   COUNT(*) AS item_count, SUM(il.Qty) AS total_qty,
                   SUM(il.Qty * il.Unit_price) AS total_amount,
                   MAX(il.Created_By) AS created_by, MAX(il.Created_At) AS created_at
            FROM is_list il
            $ws
            GROUP BY il.IS_number, il.Order_date, il.Client_name
            ORDER BY il.Order_date DESC, il.IS_number DESC
            LIMIT 200";
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 取單一出貨單的所有明細（列印送貨單用） */
function sq_shipment_detail(PDO $db, string $isNumber): array
{
    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
               il.Client_id, il.Client_name, il.Product_id, il.d_setting_id, il.Specification,
               il.Qty, il.Unit_price, il.Order_id, il.Warehouse, il.Note,
               ot.Order_oo,
               COALESCE(cl.customer, il.Client_name) AS client_display,
               cl.customer_full, cl.tax_id, cl.customer_address,
               GROUP_CONCAT(ibm.bom ORDER BY ibm.bom SEPARATOR ',') AS boms
        FROM is_list il
        LEFT JOIN order_track   ot  ON ot.Order_id    = il.Order_id
        LEFT JOIN customer_list cl  ON cl.customer_id = il.Client_id
        LEFT JOIN is_bom_map    ibm ON ibm.IS_id      = il.IS_id
        WHERE il.IS_number = ?
        GROUP BY il.IS_id
        ORDER BY il.IS_id");
    $st->execute([$isNumber]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
