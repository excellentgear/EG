<?php
// _NewOrder_Track222.php
session_start();
include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

$conn = new DBConnection();
$db = $conn->getPDO();

try {
    header('Content-Type: application/json');

    // 訂單編號前綴強制大寫（雙重保險）
    if (isset($_POST['OrderNo'])) {
        $_POST['OrderNo'] = strtoupper(trim($_POST['OrderNo']));
    }

    $clientNameId = !empty($_POST['Client_name_ID']) ? $_POST['Client_name_ID'] : null;
    $dIdId        = !empty($_POST['d_id_ID'])        ? intval($_POST['d_id_ID']) : null;

    // 綁定料號時客戶由料號決定（伺服器端強制，避免前端繞過造成客戶與料號不符）
    if ($dIdId) {
        $q = $db->prepare("SELECT ds.Customer_Id, c.customer FROM d_setting ds LEFT JOIN customer_list c ON c.customer_id = ds.Customer_Id WHERE ds.d_id = ?");
        $q->execute([$dIdId]);
        $pRow = $q->fetch(PDO::FETCH_ASSOC);
        if ($pRow && !empty($pRow['Customer_Id'])) {
            $clientNameId = $pRow['Customer_Id'];
            if (!empty($pRow['customer'])) $_POST['Client_Name'] = $pRow['customer'];
        }
    }
    $unitPrice    = (isset($_POST['unit_price']) && $_POST['unit_price'] !== '') ? floatval($_POST['unit_price']) : null;
    $quoteNo      = !empty($_POST['quote_no'])       ? trim($_POST['quote_no'])  : null;
    $quoteItemId  = !empty($_POST['bound_quote_item_id']) ? intval($_POST['bound_quote_item_id']) : null;

    // ── 政策A：刪除訂單時作廢其變更單，並連動刪除衍生通知（避免孤兒通知）────
    // 回傳作廢筆數；order_change_log 尚未建立（從未用過變更功能）則跳過
    function eg_void_order_changes(PDO $db, array $orderIds, string $reason): int {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (empty($orderIds)) return 0;
        if (!$db->query("SHOW TABLES LIKE 'order_change_log'")->fetch()) return 0;
        if (!$db->query("SHOW COLUMNS FROM order_change_log LIKE 'is_void'")->fetch()) return 0;
        $uid   = intval($_SESSION['id'] ?? 0);
        $uname = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? 'system');
        $in = implode(',', $orderIds);
        $chgs = $db->query("SELECT id, change_no, live_event_id FROM order_change_log WHERE order_id IN ($in) AND is_void=0")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($chgs)) return 0;
        require_once __DIR__ . '/../common/notice_files.php';
        $void = $db->prepare("UPDATE order_change_log SET is_void=1, void_reason=?, voided_by=?, voided_by_id=?, voided_at=NOW() WHERE id=?");
        foreach ($chgs as $c) {
            $void->execute([$reason, $uname, ($uid ?: null), $c['id']]);
            $leid = (int)($c['live_event_id'] ?? 0);
            if (!$leid) continue;
            // 通知附件實體檔
            $fq = $db->prepare("SELECT file_path FROM live_event_file WHERE live_event_id=?");
            $fq->execute([$leid]);
            $paths = $fq->fetchAll(PDO::FETCH_COLUMN);
            $rq = $db->prepare("SELECT rf.file_path FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id=?");
            $rq->execute([$leid]);
            $paths = array_merge($paths, $rq->fetchAll(PDO::FETCH_COLUMN));
            foreach ($paths as $p) { $abs = eg_notice_abs_path($p); if ($abs && is_file($abs)) @unlink($abs); }
            $db->prepare("DELETE rf FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id=?")->execute([$leid]);
            $db->prepare("DELETE FROM live_event_response WHERE live_event_id=?")->execute([$leid]);
            $db->prepare("DELETE FROM live_event_for_user WHERE live_event_id=?")->execute([$leid]);
            $db->prepare("DELETE FROM live_event_file WHERE live_event_id=?")->execute([$leid]);
            $db->prepare("DELETE FROM live_event_target WHERE live_event_id=?")->execute([$leid]);
            $db->prepare("INSERT INTO live_event_history (live_event_id, action, changed_by, changed_at, before_data) VALUES (?,?,?,NOW(),?)")
                ->execute([$leid, 'delete', ($uid ?: null), json_encode(['reason' => $reason, 'change_no' => $c['change_no'] ?? ''], JSON_UNESCAPED_UNICODE)]);
            $db->prepare("DELETE FROM live_event WHERE id=?")->execute([$leid]);
        }
        return count($chgs);
    }

    // OP轉訂單用：把報價項目 process_notes（逗號分隔 sub_tag_id）轉成製程名稱字串（・連接）
    function eg_process_names_for(PDO $db, ?string $process_notes): string {
        if (empty($process_notes)) return '';
        $ids = [];
        foreach (explode(',', $process_notes) as $sid) {
            $sid = intval(trim($sid));
            if ($sid > 0) $ids[] = $sid;
        }
        if (empty($ids)) return '';
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag WHERE sub_tag_id IN ($ph)");
        $st->execute($ids);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['sub_tag_id']] = $r['sub_tag_name'];
        $names = [];
        foreach ($ids as $sid) { if (isset($map[$sid])) $names[] = $map[$sid]; }
        return implode('・', $names);
    }

    // ── 新增 ──────────────────────────────────────────────────────────
    if (isset($_POST['or_new']) || isset($_POST['or_new_copy'])) {
        $sql = "INSERT INTO order_track SET
                    Order_oo         = :OrderNo,
                    d_id             = :d_id,
                    Specification    = NULL,
                    Order_ps         = :Order_ps,
                    Client_name      = :Client_Name,
                    Qty              = :Qty,
                    Order_date       = :Order_date,
                    Delivery_date    = :Delivery_date,
                    Delivery_date_2  = NULL,
                    Delivery_date_3  = NULL,
                    C_order          = :Client_OrderNo,
                    Containers       = :Containers,
                    Sample           = :Sample,
                    JIG              = :JIG,
                    Processing_items = :Processing_items,
                    ate              = :ate,
                    ateGet           = :datepicker_ate,
                    drop_zone        = :drop_zone,
                    unit_price       = :unit_price,
                    quote_no         = :quote_no,
                    quote_item_id    = :quote_item_id,
                    Order_status     = NULL,
                    split_seq        = 1,
                    parent_order_id  = NULL,
                    Created_At       = NOW(),
                    Created_By       = :Created_By,
                    Client_name_ID   = :Client_name_ID,
                    d_id_ID          = :d_id_ID";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':OrderNo',          $_POST['OrderNo']);
        $stmt->bindParam(':d_id',             $_POST['d_id']);
        $stmt->bindParam(':Order_ps',         $_POST['Order_ps']);
        $stmt->bindParam(':Client_Name',      $_POST['Client_Name']);
        $stmt->bindParam(':Qty',              $_POST['Qty']);
        $stmt->bindParam(':Order_date',       $_POST['orderindate']);
        $stmt->bindParam(':Delivery_date',    $_POST['orderDdate']);
        $stmt->bindValue(':datepicker_ate',   !empty($_POST['datepicker_ate']) ? $_POST['datepicker_ate'] : null);
        $stmt->bindParam(':Client_OrderNo',   $_POST['Client_OrderNo']);
        $stmt->bindParam(':Containers',       $_POST['Containers']);
        $stmt->bindParam(':Sample',           $_POST['sample']);
        $stmt->bindParam(':JIG',              $_POST['jig']);
        $stmt->bindParam(':Processing_items', $_POST['Process']);
        $stmt->bindParam(':ate',              $_POST['ate']);
        $stmt->bindParam(':drop_zone',        $_POST['drop_zone']);
        $stmt->bindParam(':Created_By',       $_SESSION['id']);
        $stmt->bindParam(':Client_name_ID',   $clientNameId);
        $stmt->bindParam(':d_id_ID',          $dIdId);
        $stmt->bindValue(':unit_price',       $unitPrice);
        $stmt->bindValue(':quote_no',         $quoteNo);
        $stmt->bindValue(':quote_item_id',    $quoteItemId);
        $stmt->execute();
        $newId = $db->lastInsertId();
        echo json_encode(['success' => true, 'new_order_id' => $newId, 'message' => isset($_POST['or_new_copy']) ? '新增並複製成功' : '新增成功']);
        exit;
    }

    // ── 更新 ──────────────────────────────────────────────────────────
    if (isset($_POST['or_update'])) {
        $selStmt = $db->prepare("SELECT Delivery_date, Delivery_date_2 FROM order_track WHERE Order_id = ?");
        $selStmt->execute([intval($_POST['Order_id'])]);
        $row = $selStmt->fetch(PDO::FETCH_ASSOC);
        $curDel  = $row['Delivery_date']  ?? '';
        $curDel2 = $row['Delivery_date_2'] ?? null;

        $baseFields = "Order_oo=:OrderNo, d_id=:d_id, Specification=NULL,
                       Order_ps=:Order_ps, Client_name=:Client_Name, Qty=:Qty,
                       Order_date=:Order_date, C_order=:Client_OrderNo,
                       Containers=:Containers, Sample=:Sample, JIG=:JIG,
                       Processing_items=:Processing_items, ateGet=:datepicker_ate,
                       ate=:ate, drop_zone=:drop_zone,
                       unit_price=:unit_price, quote_no=:quote_no, quote_item_id=:quote_item_id,
                       Modified_By=:Modified_By, Modified_At=NOW(),
                       Client_name_ID=:Client_name_ID, d_id_ID=:d_id_ID";

        if ($_POST['orderDdate'] !== $curDel) {
            $sql = "UPDATE order_track SET $baseFields,
                        Delivery_date=:newDel, Delivery_date_2=:newDel2, Delivery_date_3=:newDel3
                    WHERE Order_id=:Order_id";
            $newDel  = $_POST['orderDdate'];
            $newDel2 = $curDel;
            $newDel3 = !empty($curDel2) ? $curDel2 : null;
        } else {
            $sql = "UPDATE order_track SET $baseFields,
                        Delivery_date=:Delivery_date
                    WHERE Order_id=:Order_id";
        }

        $stmt = $db->prepare($sql);
        $stmt->bindParam(':OrderNo',          $_POST['OrderNo']);
        $stmt->bindParam(':d_id',             $_POST['d_id']);
        $stmt->bindParam(':Order_ps',         $_POST['Order_ps']);
        $stmt->bindParam(':Client_Name',      $_POST['Client_Name']);
        $stmt->bindParam(':Qty',              $_POST['Qty']);
        $stmt->bindParam(':Order_date',       $_POST['orderindate']);
        $stmt->bindParam(':Client_OrderNo',   $_POST['Client_OrderNo']);
        $stmt->bindParam(':Containers',       $_POST['Containers']);
        $stmt->bindParam(':Sample',           $_POST['sample']);
        $stmt->bindParam(':JIG',              $_POST['jig']);
        $stmt->bindParam(':Processing_items', $_POST['Process']);
        $stmt->bindParam(':ate',              $_POST['ate']);
        $stmt->bindValue(':datepicker_ate',   !empty($_POST['datepicker_ate']) ? $_POST['datepicker_ate'] : null);
        $stmt->bindParam(':drop_zone',        $_POST['drop_zone']);
        $stmt->bindParam(':Order_id',         $_POST['Order_id']);
        $stmt->bindParam(':Modified_By',      $_SESSION['id']);
        $stmt->bindParam(':Client_name_ID',   $clientNameId);
        $stmt->bindParam(':d_id_ID',          $dIdId);
        $stmt->bindValue(':unit_price',       $unitPrice);
        $stmt->bindValue(':quote_no',         $quoteNo);
        $stmt->bindValue(':quote_item_id',    $quoteItemId);

        if ($_POST['orderDdate'] !== $curDel) {
            $stmt->bindParam(':newDel',  $newDel);
            $stmt->bindParam(':newDel2', $newDel2);
            $stmt->bindParam(':newDel3', $newDel3);
        } else {
            $stmt->bindParam(':Delivery_date', $_POST['orderDdate']);
        }
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => '更新成功']);
        exit;
    }

    // ── 刪除主訂單 ────────────────────────────────────────────────────
    if (isset($_POST['del_order_track'])) {
        $delId = intval($_POST['Order_id']);
        $db->beginTransaction();
        // 收集本單＋子批次ID，作廢其變更單並連動移除通知（政策A）
        $ids = [$delId];
        $cq = $db->prepare("SELECT Order_id FROM order_track WHERE parent_order_id = ?");
        $cq->execute([$delId]);
        foreach ($cq->fetchAll(PDO::FETCH_COLUMN) as $cid) $ids[] = (int)$cid;
        $onoq = $db->prepare("SELECT Order_oo FROM order_track WHERE Order_id = ?");
        $onoq->execute([$delId]);
        $orderNo = (string)$onoq->fetchColumn();
        $voided = eg_void_order_changes($db, $ids, '訂單刪除（單號 ' . ($orderNo !== '' ? $orderNo : $delId) . '）');
        // 先刪子批次，再刪主訂單
        $db->prepare("DELETE FROM order_track WHERE parent_order_id = ?")->execute([$delId]);
        $db->prepare("DELETE FROM order_track WHERE Order_id = ?")->execute([$delId]);
        $db->commit();
        echo json_encode(['success' => true, 'message' => '刪除完成', 'voided_changes' => $voided]);
        exit;
    }

    // ── 重設 ──────────────────────────────────────────────────────────
    if (isset($_POST['resetpSetting'])) {
        session_unset();
        header("Location: ../../views/Sales/NewOrder_Track222.php");
        exit;
    }

    // =====================================================================
    // ── 拆批：查詢子批次清單 ─────────────────────────────────────────────
    // =====================================================================
    if (isset($_POST['action']) && $_POST['action'] === 'get_splits') {
        $parentId = intval($_POST['parent_order_id'] ?? 0);
        if (!$parentId) { echo json_encode(['success' => false, 'message' => '未指定主訂單ID']); exit; }

        $stmtP = $db->prepare("SELECT Order_id, Order_oo, d_id, Qty, DATE_FORMAT(Delivery_date,'%Y-%m-%d') AS Delivery_date FROM order_track WHERE Order_id = ? AND (parent_order_id IS NULL OR parent_order_id = 0)");
        $stmtP->execute([$parentId]);
        $parent = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$parent) { echo json_encode(['success' => false, 'message' => '找不到主訂單']); exit; }

        $stmtS = $db->prepare("SELECT Order_id, split_seq, DATE_FORMAT(Delivery_date,'%Y-%m-%d') AS delivery_fmt, Qty, Order_ps
                                FROM order_track
                                WHERE parent_order_id = ?
                                ORDER BY Delivery_date ASC, split_seq ASC");
        $stmtS->execute([$parentId]);
        $splits = $stmtS->fetchAll(PDO::FETCH_ASSOC);

        $usedQty = array_sum(array_column($splits, 'Qty'));

        echo json_encode([
            'success'   => true,
            'parent'    => $parent,
            'splits'    => $splits,
            'used_qty'  => $usedQty,
            'remaining' => $parent['Qty'] - $usedQty,
        ]);
        exit;
    }

    // =====================================================================
    // ── 拆批：新增一筆子批次 ─────────────────────────────────────────────
    // =====================================================================
    if (isset($_POST['action']) && $_POST['action'] === 'add_split') {
        $parentId  = intval($_POST['parent_order_id'] ?? 0);
        $splitQty  = intval($_POST['split_qty'] ?? 0);
        $splitDate = trim($_POST['split_date'] ?? '');
        $splitPs   = trim($_POST['split_ps'] ?? '');
        $userId    = $_SESSION['id'] ?? 0;

        if (!$parentId) { echo json_encode(['success' => false, 'message' => '未指定主訂單ID']); exit; }
        if ($splitQty <= 0) { echo json_encode(['success' => false, 'message' => '數量必須大於0']); exit; }
        if (empty($splitDate)) { echo json_encode(['success' => false, 'message' => '請選擇交期']); exit; }

        $db->beginTransaction();

        $stmtP = $db->prepare("SELECT * FROM order_track WHERE Order_id = ? AND (parent_order_id IS NULL OR parent_order_id = 0) FOR UPDATE");
        $stmtP->execute([$parentId]);
        $parent = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$parent) { $db->rollBack(); echo json_encode(['success' => false, 'message' => '找不到主訂單']); exit; }

        $stmtUsed = $db->prepare("SELECT COALESCE(SUM(Qty),0) FROM order_track WHERE parent_order_id = ?");
        $stmtUsed->execute([$parentId]);
        $usedQty = (int)$stmtUsed->fetchColumn();

        if ($usedQty + $splitQty > $parent['Qty']) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => '子批次數量總和 ('.($usedQty+$splitQty).') 超過主訂單數量 ('.$parent['Qty'].')']);
            exit;
        }

        $stmtSeq = $db->prepare("SELECT COALESCE(MAX(split_seq),1)+1 FROM order_track WHERE parent_order_id = ?");
        $stmtSeq->execute([$parentId]);
        $nextSeq = (int)$stmtSeq->fetchColumn();

        $stmtIns = $db->prepare("INSERT INTO order_track SET
            Order_oo=:Order_oo, d_id=:d_id, Specification=NULL,
            Order_ps=:Order_ps, Client_name=:Client_name, Qty=:Qty,
            Order_date=:Order_date, Delivery_date=:Delivery_date,
            C_order=:C_order, Containers=:Containers,
            Processing_items=:Processing_items, ate=:ate, ateGet=:ateGet,
            drop_zone=:drop_zone, unit_price=:unit_price, quote_no=:quote_no,
            Order_status=NULL, split_seq=:split_seq, parent_order_id=:parent_order_id,
            Client_name_ID=:Client_name_ID, d_id_ID=:d_id_ID,
            Created_At=NOW(), Created_By=:Created_By");
        $stmtIns->execute([
            ':Order_oo'         => $parent['Order_oo'],
            ':d_id'             => $parent['d_id'],
            ':Order_ps'         => $splitPs !== '' ? $splitPs : null,
            ':Client_name'      => $parent['Client_name'],
            ':Qty'              => $splitQty,
            ':Order_date'       => $parent['Order_date'],
            ':Delivery_date'    => $splitDate,
            ':C_order'          => $parent['C_order'],
            ':Containers'       => $parent['Containers'],
            ':Processing_items' => $parent['Processing_items'],
            ':ate'              => $parent['ate'],
            ':ateGet'           => $parent['ateGet'],
            ':drop_zone'        => $parent['drop_zone'],
            ':unit_price'       => $parent['unit_price'],
            ':quote_no'         => $parent['quote_no'],
            ':split_seq'        => $nextSeq,
            ':parent_order_id'  => $parentId,
            ':Client_name_ID'   => $parent['Client_name_ID'],
            ':d_id_ID'          => $parent['d_id_ID'],
            ':Created_By'       => $userId,
        ]);
        $db->commit();
        echo json_encode(['success' => true, 'message' => '子批次新增成功']);
        exit;
    }

    // =====================================================================
    // ── 拆批：更新單筆子批次 ─────────────────────────────────────────────
    // =====================================================================
    if (isset($_POST['action']) && $_POST['action'] === 'update_split') {
        $splitId   = intval($_POST['split_order_id'] ?? 0);
        $splitQty  = intval($_POST['split_qty'] ?? 0);
        $splitDate = trim($_POST['split_date'] ?? '');
        $splitPs   = trim($_POST['split_ps'] ?? '');
        $userId    = $_SESSION['id'] ?? 0;

        if (!$splitId) { echo json_encode(['success' => false, 'message' => '未指定子批次ID']); exit; }
        if ($splitQty <= 0) { echo json_encode(['success' => false, 'message' => '數量必須大於0']); exit; }
        if (empty($splitDate)) { echo json_encode(['success' => false, 'message' => '請選擇交期']); exit; }

        $db->beginTransaction();

        $stmtSel = $db->prepare("SELECT * FROM order_track WHERE Order_id = ? AND parent_order_id IS NOT NULL FOR UPDATE");
        $stmtSel->execute([$splitId]);
        $split = $stmtSel->fetch(PDO::FETCH_ASSOC);
        if (!$split) { $db->rollBack(); echo json_encode(['success' => false, 'message' => '找不到子批次']); exit; }

        $parentId = $split['parent_order_id'];
        $stmtPQty = $db->prepare("SELECT Qty FROM order_track WHERE Order_id = ?");
        $stmtPQty->execute([$parentId]);
        $parentQty = (int)$stmtPQty->fetchColumn();

        $stmtOther = $db->prepare("SELECT COALESCE(SUM(Qty),0) FROM order_track WHERE parent_order_id = ? AND Order_id != ?");
        $stmtOther->execute([$parentId, $splitId]);
        $otherQty = (int)$stmtOther->fetchColumn();

        if ($otherQty + $splitQty > $parentQty) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => '更新後子批次總和 ('.($otherQty+$splitQty).') 超過主訂單數量 ('.$parentQty.')']);
            exit;
        }

        $db->prepare("UPDATE order_track SET Qty=:Qty, Delivery_date=:Delivery_date, Order_ps=:Order_ps, Modified_By=:uid, Modified_At=NOW() WHERE Order_id=:oid AND parent_order_id IS NOT NULL")
           ->execute([':Qty'=>$splitQty,':Delivery_date'=>$splitDate,':Order_ps'=>$splitPs!==''?$splitPs:null,':uid'=>$userId,':oid'=>$splitId]);
        $db->commit();
        echo json_encode(['success' => true, 'message' => '子批次更新成功']);
        exit;
    }

    // =====================================================================
    // ── 拆批：刪除單筆子批次 ─────────────────────────────────────────────
    // =====================================================================
    if (isset($_POST['action']) && $_POST['action'] === 'delete_split') {
        $splitId = intval($_POST['split_order_id'] ?? 0);
        if (!$splitId) { echo json_encode(['success' => false, 'message' => '未指定子批次ID']); exit; }

        $stmtChk = $db->prepare("SELECT Order_id, Order_oo FROM order_track WHERE Order_id = ? AND parent_order_id IS NOT NULL");
        $stmtChk->execute([$splitId]);
        $splitRow = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if (!$splitRow) { echo json_encode(['success' => false, 'message' => '找不到子批次']); exit; }

        $db->beginTransaction();
        eg_void_order_changes($db, [$splitId], '子批次刪除（單號 ' . (($splitRow['Order_oo'] ?? '') !== '' ? $splitRow['Order_oo'] : $splitId) . '）');
        $db->prepare("DELETE FROM order_track WHERE Order_id = ? AND parent_order_id IS NOT NULL")->execute([$splitId]);
        $db->commit();
        echo json_encode(['success' => true, 'message' => '子批次已刪除']);
        exit;
    }

    // =====================================================================
    // ── 拆批：刪除所有子批次（撤銷全部拆批）────────────────────────────────
    // =====================================================================
    if (isset($_POST['action']) && $_POST['action'] === 'delete_all_splits') {
        $parentId = intval($_POST['parent_order_id'] ?? 0);
        if (!$parentId) { echo json_encode(['success' => false, 'message' => '未指定主訂單ID']); exit; }

        $stmtChk = $db->prepare("SELECT Order_id FROM order_track WHERE Order_id = ? AND (parent_order_id IS NULL OR parent_order_id = 0)");
        $stmtChk->execute([$parentId]);
        if (!$stmtChk->fetch()) { echo json_encode(['success' => false, 'message' => '找不到主訂單']); exit; }

        $db->beginTransaction();
        $sq = $db->prepare("SELECT Order_id FROM order_track WHERE parent_order_id = ?");
        $sq->execute([$parentId]);
        $splitIds = array_map('intval', $sq->fetchAll(PDO::FETCH_COLUMN));
        eg_void_order_changes($db, $splitIds, '撤銷拆批刪除子批次（主訂單ID ' . $parentId . '）');
        $stmtDel = $db->prepare("DELETE FROM order_track WHERE parent_order_id = ?");
        $stmtDel->execute([$parentId]);
        $n = $stmtDel->rowCount();
        $db->commit();
        echo json_encode(['success' => true, 'message' => "已刪除 {$n} 筆子批次", 'deleted_count' => $n]);
        exit;
    }

    // =====================================================================
    // ── 組合件：自動展開子件訂單 ─────────────────────────────────────────
    //    依 d_setting_bom 為母訂單料號的每個子件各建一筆訂單：
    //    同單號/接單日/交期，數量＝母單數量×每組用量(無條件進位)，
    //    assembly_parent_order_id 標記來源母訂單（與拆批 parent_order_id 無關）
    // =====================================================================
    if (isset($_POST['action']) && $_POST['action'] === 'expand_assembly_children') {
        $parentId = intval($_POST['parent_order_id'] ?? 0);
        if (!$parentId) { echo json_encode(['success' => false, 'message' => '未指定母訂單ID']); exit; }
        $userId = $_SESSION['id'] ?? 0;

        $db->beginTransaction();

        $ps = $db->prepare("SELECT * FROM order_track WHERE Order_id = ? FOR UPDATE");
        $ps->execute([$parentId]);
        $parent = $ps->fetch(PDO::FETCH_ASSOC);
        if (!$parent) { $db->rollBack(); echo json_encode(['success' => false, 'message' => '找不到母訂單']); exit; }
        if (empty($parent['d_id_ID'])) { $db->rollBack(); echo json_encode(['success' => false, 'message' => '母訂單尚未綁定料號ID，無法展開']); exit; }

        $as = $db->prepare("SELECT Is_Assembly FROM d_setting WHERE d_id = ?");
        $as->execute([intval($parent['d_id_ID'])]);
        if (intval($as->fetchColumn()) !== 1) { $db->rollBack(); echo json_encode(['success' => false, 'message' => '母訂單料號不是組合件']); exit; }

        // 防重複展開：本單已建立過子件訂單則拒絕
        $ck = $db->prepare("SELECT COUNT(*) FROM order_track WHERE assembly_parent_order_id = ?");
        $ck->execute([$parentId]);
        if ((int)$ck->fetchColumn() > 0) { $db->rollBack(); echo json_encode(['success' => false, 'message' => '此訂單已展開過子件訂單，請勿重複展開']); exit; }

        $bs = $db->prepare("SELECT b.child_d_id, b.standard_qty, d.D_Setting_Id, d.Spec_No, d.Customer_Id, c.customer AS child_customer_name
                            FROM d_setting_bom b
                            JOIN d_setting d ON d.d_id = b.child_d_id
                            LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                            WHERE b.parent_d_id = ? ORDER BY b.bom_id ASC");
        $bs->execute([intval($parent['d_id_ID'])]);
        $bomRows = $bs->fetchAll(PDO::FETCH_ASSOC);
        if (empty($bomRows)) { $db->rollBack(); echo json_encode(['success' => false, 'message' => '此組合件尚未建立 BOM 子件資料']); exit; }

        $ins = $db->prepare("INSERT INTO order_track SET
            Order_oo=:Order_oo, d_id=:d_id, Specification=NULL, Order_ps=:Order_ps,
            Client_name=:Client_name, Qty=:Qty,
            Order_date=:Order_date, Delivery_date=:Delivery_date,
            C_order=:C_order, Processing_items=:Processing_items,
            ate=:ate, ateGet=:ateGet, ateNote=:ateNote,
            Order_status=NULL, split_seq=1, parent_order_id=NULL,
            assembly_parent_order_id=:asm_parent,
            Client_name_ID=:Client_name_ID, d_id_ID=:d_id_ID,
            Created_At=NOW(), Created_By=:Created_By");

        $created = 0;
        foreach ($bomRows as $b) {
            $stdQty = floatval($b['standard_qty']);
            $qty = (int)ceil(floatval($parent['Qty']) * $stdQty);
            if ($qty <= 0) continue;
            // 客戶由子件料號決定；子件無綁定客戶則沿用母訂單客戶
            $cid   = !empty($b['Customer_Id']) ? $b['Customer_Id'] : $parent['Client_name_ID'];
            $cname = (!empty($b['Customer_Id']) && !empty($b['child_customer_name'])) ? $b['child_customer_name'] : $parent['Client_name'];
            $stdQtyDisp = rtrim(rtrim(number_format($stdQty, 2, '.', ''), '0'), '.');
            $ins->execute([
                ':Order_oo'         => $parent['Order_oo'],
                ':d_id'             => $b['D_Setting_Id'],
                ':Order_ps'         => '由組合件 ' . $parent['d_id'] . ' 訂單自動展開（每組用量 ' . $stdQtyDisp . '）',
                ':Client_name'      => $cname,
                ':Qty'              => $qty,
                ':Order_date'       => $parent['Order_date'],
                ':Delivery_date'    => $parent['Delivery_date'],
                ':C_order'          => $parent['C_order'],
                ':Processing_items' => '全製',
                ':ate'              => $parent['ate'],
                ':ateGet'           => $parent['ateGet'],
                ':ateNote'          => $parent['ateNote'],
                ':asm_parent'       => $parentId,
                ':Client_name_ID'   => $cid,
                ':d_id_ID'          => intval($b['child_d_id']),
                ':Created_By'       => $userId,
            ]);
            $created++;
        }
        $db->commit();
        echo json_encode(['success' => true, 'created_count' => $created, 'message' => "已自動建立 {$created} 筆子件訂單"]);
        exit;
    }

    // =====================================================================
    // ── OP轉訂單：批次由報價單項目建立訂單 ──────────────────────────────────
    // =====================================================================
    if (isset($_POST['action']) && $_POST['action'] === 'create_orders_from_quotes') {
        $userId = $_SESSION['id'] ?? 0;
        $items = json_decode($_POST['items'] ?? '', true);
        if (!is_array($items) || empty($items)) {
            echo json_encode(['success' => false, 'message' => '未提供任何要轉單的料號項目']); exit;
        }

        // 相容舊表：order_track.qty_over_range（轉單數量超出報價階梯區間旗標）首次執行自動補欄
        try { $db->query("SELECT qty_over_range FROM order_track LIMIT 1"); }
        catch (PDOException $e) {
            try { $db->exec("ALTER TABLE order_track ADD COLUMN qty_over_range TINYINT(1) NOT NULL DEFAULT 0 COMMENT '轉單數量超出報價階梯區間(含容差後)=1,待補報價單'"); } catch (PDOException $e2) {}
        }

        // 階梯區間工具（與前端 opTolRange/opMatchTier 同邏輯；伺服器端為準）
        $tolRange = function(array $t): array {
            $mn = (int)round((float)($t['qty_min'] ?? 0));
            $mx = ($t['qty_max'] === null || $t['qty_max'] === '') ? null : (int)round((float)$t['qty_max']);
            $tv = ($t['tolerance_value'] === null || $t['tolerance_value'] === '') ? 0.0 : (float)$t['tolerance_value'];
            $lo = $mn; $hi = $mx;
            if ($tv > 0) {
                if (($t['tolerance_unit'] ?? '') === '%') {
                    $lo = max(1, (int)floor($mn * (1 - $tv / 100)));
                    if ($hi !== null) $hi = (int)ceil($hi * (1 + $tv / 100));
                } elseif (($t['tolerance_unit'] ?? '') === 'PCS') {
                    $lo = max(1, $mn - (int)round($tv));
                    if ($hi !== null) $hi = $hi + (int)round($tv);
                }
            }
            return ['base_lo' => $mn, 'base_hi' => $mx, 'tol_lo' => $lo, 'tol_hi' => $hi];
        };
        $matchTier = function(array $tiers, int $qty, bool $useTol) use ($tolRange): ?array {
            if ($qty <= 0) return null;
            foreach ($tiers as $t) {
                $r = $tolRange($t);
                if ($qty >= $r['base_lo'] && ($r['base_hi'] === null || $qty <= $r['base_hi'])) return $t;
            }
            if (!$useTol) return null;
            $best = null; $bestDist = PHP_INT_MAX;
            foreach ($tiers as $t) {
                $r = $tolRange($t);
                if ($qty >= $r['tol_lo'] && ($r['tol_hi'] === null || $qty <= $r['tol_hi'])) {
                    $dist = $qty < $r['base_lo'] ? ($r['base_lo'] - $qty) : ($r['base_hi'] === null ? 0 : $qty - $r['base_hi']);
                    if ($dist < $bestDist) { $best = $t; $bestDist = $dist; }
                }
            }
            return $best;
        };

        $db->beginTransaction();
        try {
            $created = [];
            foreach ($items as $row) {
                $quoteItemId  = intval($row['quote_item_id'] ?? 0);
                $orderNo      = strtoupper(trim($row['order_no'] ?? ''));
                $deliveryDate = trim($row['delivery_date'] ?? '');
                $orderPs      = trim($row['order_ps'] ?? '');
                $ateRaw       = trim((string)($row['ate'] ?? ''));
                $ate          = ($ateRaw !== '') ? intval($ateRaw) : 2; // 2 = 無(不經設計)，比照新增訂單表單預設值
                $ateGet       = trim($row['ateget'] ?? '');

                if (!$quoteItemId)          throw new Exception('缺少報價項目ID');
                if ($orderNo === '')        throw new Exception('缺少訂單編號');
                if ($deliveryDate === '')   throw new Exception('缺少交期');
                if ($ateGet === '')         throw new Exception('缺少設計接收日');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ateGet)) throw new Exception('設計接收日格式錯誤（' . $ateGet . '）');

                // 競態防護：確認此報價項目尚未被轉過訂單（避免同時多人操作重複轉單）
                $chk = $db->prepare("SELECT COUNT(*) FROM order_track WHERE quote_item_id = ?");
                $chk->execute([$quoteItemId]);
                if ((int)$chk->fetchColumn() > 0) {
                    throw new Exception("報價項目（ID {$quoteItemId}）已被轉建立過訂單，請重新整理清單後再試");
                }

                $qi = $db->prepare("SELECT qi.item_id, qi.quote_id, qi.product_id, qi.d_setting_d_id, qi.specification,
                                            qi.quantity, qi.unit_price, qi.process_notes, qi.is_tiered,
                                            ql.quote_no, ql.client_name AS quote_client_name, ql.client_id AS quote_client_id,
                                            ds.D_Setting_Id, ds.Is_Assembly, ds.Customer_Id AS part_customer_id,
                                            c.customer AS part_customer_name
                                     FROM quotation_item qi
                                     JOIN quotation_list ql ON ql.quote_id = qi.quote_id
                                     LEFT JOIN d_setting ds ON ds.d_id = qi.d_setting_d_id
                                     LEFT JOIN customer_list c ON c.customer_id = ds.Customer_Id
                                     WHERE qi.item_id = ?");
                $qi->execute([$quoteItemId]);
                $src = $qi->fetch(PDO::FETCH_ASSOC);
                if (!$src) throw new Exception("找不到報價項目（ID {$quoteItemId}）");
                if (empty($src['d_setting_d_id'])) throw new Exception('料號 ' . $src['product_id'] . ' 尚未綁定料號ID，無法轉單');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
                    throw new Exception('料號 ' . $src['product_id'] . ' 交期格式錯誤（' . $deliveryDate . '），請重新選擇日期');
                }

                // ── 階梯報價：以使用者輸入數量對價（伺服器端重算為準，不採信前端價格）──
                $ordQty   = $src['quantity'];
                $ordPrice = $src['unit_price'];
                $qtyOver  = 0;
                if ((int)$src['is_tiered'] === 1) {
                    $qtyIn    = (int)($row['qty'] ?? 0);
                    $useTol   = (int)($row['tol_match'] ?? 0) === 1;
                    if ($qtyIn <= 0) throw new Exception('料號 ' . $src['product_id'] . ' 為階梯報價，請輸入訂購數量');
                    $stTier = $db->prepare("SELECT qty_min, qty_max, unit_price, tolerance_value, tolerance_unit
                                            FROM quotation_item_tier WHERE item_id = ? ORDER BY sort_order ASC, qty_min ASC");
                    $stTier->execute([$quoteItemId]);
                    $tiers = $stTier->fetchAll(PDO::FETCH_ASSOC);
                    if (!$tiers) throw new Exception('料號 ' . $src['product_id'] . ' 階梯報價缺少區間資料');
                    $ordQty = $qtyIn;
                    $m = $matchTier($tiers, $qtyIn, $useTol);
                    if ($m) {
                        $ordPrice = $m['unit_price'];
                    } else {
                        // 依報價區間（與使用者選的對價模式）都對不到：
                        // 完全超出（含容差後）→ 無單價建立並標記，供列表紅字與篩選、後續補報價單
                        $mTol = $matchTier($tiers, $qtyIn, true);
                        if ($mTol && !$useTol) throw new Exception('料號 ' . $src['product_id'] . ' 數量 ' . $qtyIn . ' 僅落在容差後區間，請改用容差區間對價或修改數量');
                        $ordPrice = null;
                        $qtyOver  = 1;
                    }
                }

                // 客戶：料號已綁定客戶則以料號客戶為準，否則沿用報價單客戶（比照新增訂單既有規則）
                $clientId   = !empty($src['part_customer_id']) ? $src['part_customer_id'] : $src['quote_client_id'];
                $clientName = !empty($src['part_customer_id']) ? $src['part_customer_name'] : $src['quote_client_name'];

                $processes        = eg_process_names_for($db, $src['process_notes']);
                $processing_items = $processes !== '' ? $processes : ($src['specification'] ?? '');

                $ins = $db->prepare("INSERT INTO order_track SET
                    Order_oo=:Order_oo, d_id=:d_id, Specification=NULL, Order_ps=:Order_ps,
                    Client_name=:Client_name, Qty=:Qty,
                    Order_date=CURDATE(), Delivery_date=:Delivery_date,
                    Processing_items=:Processing_items, ate=:ate, ateGet=:ateGet,
                    unit_price=:unit_price, quote_no=:quote_no, quote_item_id=:quote_item_id,
                    Order_status=NULL, split_seq=1, parent_order_id=NULL,
                    Client_name_ID=:Client_name_ID, d_id_ID=:d_id_ID,
                    qty_over_range=:qty_over_range,
                    Created_At=NOW(), Created_By=:Created_By");
                $ins->execute([
                    ':Order_oo'         => $orderNo,
                    ':d_id'             => $src['D_Setting_Id'],
                    ':Order_ps'         => $orderPs !== '' ? $orderPs : null,
                    ':Client_name'      => $clientName,
                    ':Qty'              => $ordQty,
                    ':Delivery_date'    => $deliveryDate,
                    ':Processing_items' => $processing_items,
                    ':ate'              => $ate,
                    ':ateGet'           => $ateGet,
                    ':unit_price'       => $ordPrice,
                    ':qty_over_range'   => $qtyOver,
                    ':quote_no'         => $src['quote_no'],
                    ':quote_item_id'    => $quoteItemId,
                    ':Client_name_ID'   => $clientId,
                    ':d_id_ID'          => intval($src['d_setting_d_id']),
                    ':Created_By'       => $userId,
                ]);
                $created[] = [
                    'order_id'    => (int)$db->lastInsertId(),
                    'order_oo'    => $orderNo,
                    'd_id'        => $src['D_Setting_Id'],
                    'is_assembly' => intval($src['Is_Assembly']) === 1,
                ];
            }
            $db->commit();
            echo json_encode(['success' => true, 'created' => $created, 'message' => '已建立 ' . count($created) . ' 筆訂單']);
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => '資料庫寫入失敗，請確認交期、訂單編號等欄位格式是否正確']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => '一般錯誤：' . $e->getMessage()]);
    exit;
}
?>