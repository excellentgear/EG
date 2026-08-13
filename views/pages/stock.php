<?php
// EGsystem/views/pages/stock.php  v2
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

// ── 權限初始化完成，以下為 AJAX 處理 ──────────────

function safe_html($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// 只引入一次 DBConnection
include_once '../../src/common/DBConnection.php';
$conn   = new DBConnection();
$pdo    = $conn->getPDO();
$userId = intval($_SESSION['id'] ?? 0);

// ── 權限檢查（非 AJAX 請求時，使用同一個 $pdo） ───
$PAGE_PERM = 'A'; // 預設全開（DB 查不到時不封鎖）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $permCheck = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock' LIMIT 1");
        $permCheck->execute([$userId]);
        $permRow = $permCheck->fetch(PDO::FETCH_ASSOC);
        if ($permRow && !empty($permRow['permission'])) {
            $PAGE_PERM = $permRow['permission'];
        } else {
            // 查無權限記錄 → 轉跳首頁
            header("Location:../../index.php?msg=no_permission");
            exit;
        }
    } catch(Exception $pe) {
        $PAGE_PERM = 'A'; // 資料表不存在時預設全開
    }
}

// ── 當前登入使用者資訊（非AJAX時查詢，供頁面JS使用） ──────────────────
$CURRENT_USER_CNAME = '';
$CURRENT_USER_ID = $userId;
$CURRENT_USER_DEPTS = [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $uRow=$pdo->prepare("SELECT user_cname FROM user WHERE id=? AND state=1"); $uRow->execute([$userId]);
        $uData=$uRow->fetch(PDO::FETCH_ASSOC); $CURRENT_USER_CNAME=$uData['user_cname']??'';
        $dRow=$pdo->prepare("SELECT DISTINCT d.id,d.name FROM department d JOIN user_department_position_map m ON m.department_id=d.id WHERE m.user_id=? ORDER BY d.name ASC");
        $dRow->execute([$userId]); $CURRENT_USER_DEPTS=$dRow->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e2) {}
}

// ── 自動建立領庫需求單資料表 ────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_requisitions (
        req_id INT AUTO_INCREMENT PRIMARY KEY,
        req_no VARCHAR(30) NOT NULL UNIQUE,
        title VARCHAR(100) NULL,
        dept_name VARCHAR(50) NULL,
        requester_name VARCHAR(50) NULL,
        status TINYINT NOT NULL DEFAULT 0,
        req_remark VARCHAR(300) NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        deleted_by INT NULL, deleted_at DATETIME NULL, delete_reason VARCHAR(300) NULL,
        Created_By INT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Modified_By INT NULL, Modified_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        issued_at DATETIME NULL, issued_by INT NULL, issued_by_name VARCHAR(50) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_requisition_items (
        req_item_id INT AUTO_INCREMENT PRIMARY KEY,
        req_id INT NOT NULL,
        stock_item_id INT NULL, d_id VARCHAR(30) NULL, client_name VARCHAR(60) NULL,
        storage_location VARCHAR(60) NULL, qty_requested INT NOT NULL DEFAULT 1,
        qty_issued INT NOT NULL DEFAULT 0, item_remark VARCHAR(200) NULL,
        is_urgent TINYINT NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0,
        Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_req_id (req_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_req_notifications (
        notif_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL, req_id INT NOT NULL, req_no VARCHAR(30) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'new', message VARCHAR(300) NULL,
        is_read TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_read (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $_e) {}
// 既有安裝的欄位補丁
foreach(['is_active TINYINT NOT NULL DEFAULT 1','deleted_by INT NULL','deleted_at DATETIME NULL','delete_reason VARCHAR(300) NULL'] as $_col){
    try { $pdo->exec("ALTER TABLE stock_requisitions ADD COLUMN $_col"); } catch(Exception $_e2){}
}

// ── 需求單通知輔助函式 ──────────────────────────────
function getNotifTargetUsers($pdo){
    try{ return $pdo->query("SELECT DISTINCT user_id FROM user_module_permissions WHERE module_code='stock' AND (permission='A' OR (permission LIKE '%C%' AND permission LIKE '%R%' AND permission LIKE '%U%' AND permission LIKE '%D%'))")->fetchAll(PDO::FETCH_COLUMN); }catch(Exception $e){ return []; }
}
function insertReqNotifications($pdo,$targets,$reqId,$reqNo,$type,$message){
    if(empty($targets)) return;
    try{ $st=$pdo->prepare("INSERT IGNORE INTO stock_req_notifications (user_id,req_id,req_no,type,message) VALUES (?,?,?,?,?)");
        foreach($targets as $uid) $st->execute([$uid,$reqId,$reqNo,$type,$message]); }catch(Exception $e){}
}

// ─────────────────────────────────────────────────
//  AJAX
// ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // AJAX 請求：關閉 display_errors 並啟動輸出緩衝，
    // 防止任何 PHP notice/warning 混入 JSON 導致前端解析失敗
    ini_set('display_errors', 0);
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['action'] === 'keepalive') { echo json_encode(['ok'=>true]); exit; }

    if ($_POST['action'] === 'check_permission') {
        try {
            $st=$pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock'");
            $st->execute([$userId]); $row=$st->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'is_admin'=>($row&&$row['permission']==='A')]);
        } catch(Exception $e){ echo json_encode(['success'=>true,'is_admin'=>false]); }
        exit;
    }

    // ── 統計 ──────────────────────────────────────
    if ($_POST['action'] === 'get_stats') {
        try {
            $tables = $pdo->query("SHOW TABLES LIKE 'stock_safety_stock'")->fetchColumn();
            $hasSafety = ($tables !== false);
            $cols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCat = in_array('item_type', $cols);
            $hasUnitPrice = in_array('unit_price', $cols);
            $hasCatCols = $pdo->query("SHOW COLUMNS FROM stock_item_categories")->fetchAll(PDO::FETCH_COLUMN);
            $hasShowDash = in_array('show_in_dashboard', $hasCatCols);

            $low = 0;
            if ($hasSafety) {
                try { $low = (int)$pdo->query("SELECT COUNT(DISTINCT si.stock_item_id) FROM stock_items si JOIN stock_safety_stock ss ON ss.d_id = si.d_id WHERE si.is_active=1 AND si.qty > 0 AND si.qty < ss.safety_qty")->fetchColumn(); } catch(Exception $e2){}
            }
            $s = [];
            $s['total'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_items WHERE is_active=1")->fetchColumn();
            $s['low']   = $low;
            $s['cost']  = (float)$pdo->query("SELECT COALESCE(SUM(qty*unit_cost),0) FROM stock_items WHERE is_active=1 AND unit_cost IS NOT NULL")->fetchColumn();
            // 可銷售總額：以 unit_price 計算有庫存的品項
            $s['sale_value'] = 0;
            if ($hasUnitPrice) {
                try { $s['sale_value'] = (float)$pdo->query("SELECT COALESCE(SUM(qty*unit_price),0) FROM stock_items WHERE is_active=1 AND qty>0 AND unit_price IS NOT NULL")->fetchColumn(); } catch(Exception $e2){}
            }
            $s['today'] = 0;
            try { $s['today'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE txn_date=CURDATE()")->fetchColumn(); } catch(Exception $e2){}
            // 依種類（只顯示 show_in_dashboard=1 或欄位不存在時全顯示）
            $cats = [];
            if ($hasCat) {
                $dashFilter = $hasShowDash ? "AND c.show_in_dashboard=1" : "";
                try { $cats = $pdo->query("SELECT c.category_id, c.category_name, c.color, COUNT(si.stock_item_id) AS cnt FROM stock_item_categories c LEFT JOIN stock_items si ON si.item_type=c.category_id AND si.is_active=1 WHERE c.is_active=1 $dashFilter GROUP BY c.category_id ORDER BY c.sort_order")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e2){}
            }
            $s['categories'] = $cats;
            echo json_encode(['success'=>true,'data'=>$s]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 合併庫存 ──────────────────────────────────
    if ($_POST['action'] === 'merge_stock') {
        try {
            $keepId   = intval($_POST['keep_id']??0);
            $mergeIds = array_filter(array_map('intval', json_decode($_POST['merge_ids']??'[]',true)));
            if (!$keepId || empty($mergeIds)) throw new Exception('請指定保留筆與合併筆');
            if (in_array($keepId, $mergeIds)) throw new Exception('保留筆不能與合併筆相同');

            $keep=$pdo->prepare("SELECT * FROM stock_items WHERE stock_item_id=? AND is_active=1");
            $keep->execute([$keepId]); $keepRow=$keep->fetch(PDO::FETCH_ASSOC);
            if (!$keepRow) throw new Exception('找不到保留筆');

            $pdo->beginTransaction();
            $totalMergeQty = 0;
            foreach ($mergeIds as $mid) {
                $mr=$pdo->prepare("SELECT * FROM stock_items WHERE stock_item_id=? AND is_active=1");
                $mr->execute([$mid]); $mRow=$mr->fetch(PDO::FETCH_ASSOC);
                if (!$mRow) continue;
                $totalMergeQty += floatval($mRow['qty']);
                // 把該筆異動記錄移到保留筆下
                $pdo->prepare("UPDATE stock_transactions SET stock_item_id=? WHERE stock_item_id=?")->execute([$keepId,$mid]);
                // 軟刪除合併筆
                $pdo->prepare("UPDATE stock_items SET is_active=0,qty=0,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$userId,$mid]);
            }
            $newQty = round(floatval($keepRow['qty'])+$totalMergeQty, 4);
            $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$newQty,$userId,$keepId]);
            // 記錄合併操作
            $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'adjust',?,?,?,CURDATE(),'合併庫存（共".count($mergeIds)."筆）',?)")->execute([$keepId,$keepRow['d_id'],$totalMergeQty,$keepRow['qty'],$newQty,$userId]);
            $pdo->commit();
            echo json_encode(['success'=>true,'new_qty'=>$newQty,'merged_count'=>count($mergeIds)]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得單一品項批次剩餘（FIFO 計算）──────────────
    if ($_POST['action'] === 'get_item_batches') {
        try {
            $sid = intval($_POST['stock_item_id'] ?? 0); if (!$sid) throw new Exception('未指定品項');
            $txnCols = $pdo->query("SHOW COLUMNS FROM stock_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasBomRef = in_array('bom_ref', $txnCols);
            $hasOrdRef = in_array('order_ref', $txnCols);
            $bomSel   = $hasBomRef ? "st.bom_ref AS txn_bom," : "NULL AS txn_bom,";
            $ordJoin  = $hasOrdRef ? "LEFT JOIN order_track ot_b ON ot_b.Order_id=st.order_ref" : '';
            $ordSel   = $hasOrdRef ? "ot_b.Order_oo AS txn_order_no," : "NULL AS txn_order_no,";

            $stItem = $pdo->prepare("SELECT stock_item_id, d_id, qty FROM stock_items WHERE stock_item_id=? AND is_active=1");
            $stItem->execute([$sid]);
            $item = $stItem->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new Exception('找不到品項');

            $hasRemark = in_array('remark', $txnCols);
            $remarkSel = $hasRemark ? "st.remark," : "NULL AS remark,";
            $txns = $pdo->prepare("SELECT st.stock_item_id, st.txn_type, st.txn_qty, st.txn_date, $bomSel $ordSel $remarkSel st.txn_id
                                  FROM stock_transactions st $ordJoin
                                  WHERE st.stock_item_id = ? ORDER BY st.txn_date ASC, st.txn_id ASC");
            $txns->execute([$sid]);
            $rows = $txns->fetchAll(PDO::FETCH_ASSOC);

            $in_txns = []; $out_qty = 0;
            foreach ($rows as $t) {
                if ($t['txn_type'] === 'in' && floatval($t['txn_qty']) > 0) {
                    $in_txns[] = $t;
                } elseif (floatval($t['txn_qty']) < 0) {
                    $out_qty += abs(floatval($t['txn_qty']));
                }
            }

            $batches = [];
            foreach ($in_txns as $t) {
                $inPcs = floatval($t['txn_qty']);
                $consumed = min($out_qty, $inPcs);
                $out_qty -= $consumed;
                $remaining = $inPcs - $consumed;
                if ($remaining > 0.0001) {
                    $batches[] = [
                        'batch_key'    => ($t['txn_date']??'').'|'.($t['txn_bom']??'').'|'.($t['txn_order_no']??''),
                        'txn_date'     => ($t['txn_date']??''),
                        'txn_bom'      => ($t['txn_bom']??''),
                        'txn_order_no' => ($t['txn_order_no']??''),
                        'remark'       => ($t['remark']??''),
                        'sets'         => $remaining,
                        'members'      => [
                            [
                                'stock_item_id' => $sid,
                                'd_id'          => $item['d_id'],
                                'remaining_qty' => $remaining
                            ]
                        ]
                    ];
                }
            }
            // 若無入庫記錄但有庫存（如 Excel 匯入），視為單一批次
            if (empty($batches) && floatval($item['qty']) > 0) {
                $synQty = floatval($item['qty']);
                $batches[] = [
                    'batch_key'    => 'synthetic||',
                    'txn_date'     => date('Y-m-d'),
                    'txn_bom'      => '',
                    'txn_order_no' => '',
                    'remark'       => '（原始庫存，無入庫記錄）',
                    'sets'         => $synQty,
                    'members'      => [
                        [
                            'stock_item_id' => $sid,
                            'd_id'          => $item['d_id'],
                            'remaining_qty' => $synQty
                        ]
                    ]
                ];
            }
            echo json_encode(['success'=>true,'batches'=>$batches]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 批次更新計量單位 ──────────────────────────
    if ($_POST['action'] === 'batch_update_unit') {
        try {
            $itemIds = json_decode($_POST['item_ids'] ?? '[]', true);
            $unitId  = intval($_POST['unit_id'] ?? 0);
            if (empty($itemIds)) throw new Exception('請先勾選品項');
            if (!$unitId) throw new Exception('請選擇計量單位');

            $inSql = implode(',', array_fill(0, count($itemIds), '?'));
            $pdo->prepare("UPDATE stock_items SET unit_id=?, Modified_By=?, Modified_At=NOW() WHERE stock_item_id IN ($inSql)")
                ->execute(array_merge([$unitId, $userId], $itemIds));
            echo json_encode(['success'=>true, 'message'=>'已成功更新 '.count($itemIds).' 筆品項的單位']);
        } catch(Exception $e) { echo json_encode(['success'=>false, 'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 合併現有料號為組合件 ──────────────────────
    if ($_POST['action'] === 'merge_existing_to_group') {
        try {
            $groupName    = trim($_POST['group_name'] ?? ''); if (!$groupName) throw new Exception('組合名稱必填');
            $groupPrice   = ($_POST['group_unit_price'] ?? '') !== '' ? floatval($_POST['group_unit_price']) : null;
            $customerIdIn = trim($_POST['group_customer_id'] ?? '') ?: null;
            $items        = json_decode($_POST['items_json'] ?? '[]', true);
            if (empty($items) || count($items) < 2) throw new Exception('請選取至少 2 筆庫存進行合併');

            // 確認群組表與欄位存在（group_name 儲存 d_setting.d_id 整數）
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'stock_item_groups'")->fetchColumn();
            if (!$tableCheck) {
                $pdo->exec("CREATE TABLE stock_item_groups (
                    group_id INT AUTO_INCREMENT PRIMARY KEY,
                    group_name INT NOT NULL COMMENT '對應 d_setting.d_id',
                    unit_price DECIMAL(12,4) NULL,
                    remark VARCHAR(200) NULL,
                    Created_By INT NULL,
                    Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    Modified_By INT NULL,
                    Modified_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                $gnColInfo = $pdo->query("SHOW COLUMNS FROM stock_item_groups LIKE 'group_name'")->fetch(PDO::FETCH_ASSOC);
                if ($gnColInfo && stripos($gnColInfo['Type'], 'int') === false) {
                    try { $pdo->exec("ALTER TABLE stock_item_groups MODIFY COLUMN group_name INT NOT NULL COMMENT '對應 d_setting.d_id'"); } catch(Exception $e_alt){}
                }
            }
            // 確認 stock_items 擴充欄位存在
            $colCheck = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'group_id'")->fetchColumn();
            if (!$colCheck) $pdo->exec("ALTER TABLE stock_items ADD COLUMN group_id INT NULL DEFAULT NULL AFTER is_active, ADD INDEX idx_group_id (group_id)");
            $pcsCheck = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'pcs_per_set'")->fetchColumn();
            if (!$pcsCheck) $pdo->exec("ALTER TABLE stock_items ADD COLUMN pcs_per_set INT NULL DEFAULT NULL AFTER group_id");

            $pdo->beginTransaction();

            // 同步 d_setting
            $stD2 = $pdo->prepare("SELECT d_id, Customer_Id FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
            $stD2->execute([$groupName]);
            $dEntry2 = $stD2->fetch(PDO::FETCH_ASSOC);
            $target_d_id2 = null;
            if ($dEntry2) {
                $target_d_id2 = $dEntry2['d_id'];
                $updCid2 = $customerIdIn ?? $dEntry2['Customer_Id'];
                $pdo->prepare("UPDATE d_setting SET Is_Assembly=1, Customer_Id=?, Modified_By=?, Modified_At=NOW() WHERE d_id=?")->execute([$updCid2, $userId, $target_d_id2]);
            } else {
                $pdo->prepare("INSERT INTO d_setting (D_Setting_Id, Type, Is_Assembly, Customer_Id, Created_By, Modified_By) VALUES (?, 'N', 1, ?, ?, ?)")
                    ->execute([$groupName, $customerIdIn, $userId, $userId]);
                $target_d_id2 = $pdo->lastInsertId();
            }

            $pdo->prepare("INSERT INTO stock_item_groups (group_name, unit_price, Created_By) VALUES (?,?,?)")->execute([$target_d_id2, $groupPrice, $userId]);
            $groupId = (int)$pdo->lastInsertId();

            $upd = $pdo->prepare("UPDATE stock_items SET group_id=?, pcs_per_set=?, Modified_By=?, Modified_At=NOW() WHERE stock_item_id=?");
            foreach ($items as $it) {
                $upd->execute([$groupId, intval($it['pcs_per_set'] ?? 1), $userId, intval($it['id'])]);
            }
            $pdo->commit();
            echo json_encode(['success'=>true, 'message'=>'組合件「'.$groupName.'」合併成功！']);
        } catch(Exception $e) { if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 庫存列表 ──────────────────────────────────
    if ($_POST['action'] === 'get_stock_list') {
        try {
            $page     = max(1, intval($_POST['page'] ?? 1));
            $ps       = max(10, min(100, intval($_POST['page_size'] ?? 10)));
            $offset   = ($page-1)*$ps;
            $search   = trim($_POST['search']    ?? '');
            $catF     = $_POST['category_id']    ?? '';
            $locF     = $_POST['location_id']    ?? '';
            $qtyF     = $_POST['qty_filter']     ?? 'all';
            $clientF  = trim($_POST['client_id'] ?? '');
            $staleYrs = intval($_POST['stale_years'] ?? 0);
            $todayOnly = intval($_POST['today_only'] ?? 0);
            $sortCol  = $_POST['sort_col']       ?? 'Modified_At';
            $sortDir  = ($_POST['sort_dir']??'desc')==='asc'?'ASC':'DESC';

            $allowed = ['d_id','client_name','storage_location','qty','Modified_At','stock_date','unit_cost','unit_price'];
            if (!in_array($sortCol,$allowed)) $sortCol='Modified_At';

            // 動態偵測欄位（兼容舊表結構）
            static $stockCols = null;
            if ($stockCols === null) $stockCols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCategory = in_array('item_type', $stockCols);
            $hasLocation = in_array('location_id', $stockCols);
            $hasUnit     = in_array('unit_id', $stockCols);
            $hasPkg      = in_array('package_box', $stockCols);
            $hasR1       = in_array('remark1', $stockCols);
            $hasDsid     = in_array('d_setting_id', $stockCols);

            // 品項種類顯示設定欄位（料號規格/標籤/保管者/廠商）
            static $catShowCols = null;
            if ($catShowCols === null) { try { $catShowCols = $pdo->query("SHOW COLUMNS FROM stock_item_categories")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $catShowCols=[]; } }
            $hasCatShow  = in_array('show_spec', $catShowCols);
            $anyShowSpec = false; $anyShowLabel = false;
            if ($hasCatShow) { try {
                $anyShowSpec  = (int)$pdo->query("SELECT COUNT(*) FROM stock_item_categories WHERE is_active=1 AND show_spec=1")->fetchColumn() > 0;
                $anyShowLabel = (int)$pdo->query("SELECT COUNT(*) FROM stock_item_categories WHERE is_active=1 AND show_label=1")->fetchColumn() > 0;
            } catch(Exception $e){} }

            $where = ['si.is_active=1'];
            $p = [];

            // 偵測組合件群組功能與定義 Join 語法
            $hasGroupCol = in_array('group_id', $stockCols);
            $groupJoin = $hasGroupCol ? "LEFT JOIN stock_item_groups sig ON sig.group_id=si.group_id LEFT JOIN d_setting sig_ds ON sig_ds.d_id=sig.group_name" : "";

            // 客戶欄位一律即時查料號綁定客戶（不信任 stock_items.client_name/client_id 舊快照，料號改綁客戶後快照不會跟著更新）
            $searchFields = ['si.d_id', ($hasDsid ? 'clp.customer' : 'si.client_name'), 'si.storage_location','si.bom_ref'];
            if ($hasGroupCol) $searchFields[] = 'sig_ds.D_Setting_Id';
            if ($hasPkg) $searchFields[] = 'si.package_box';
            if ($hasR1)  $searchFields[] = 'si.remark1';
            if ($search!=='') {
                $where[] = '('.implode(' LIKE :s OR ',$searchFields).' LIKE :s)';
                $p[':s'] = "%$search%";
            }
            if ($hasCategory && $catF!==''&&$catF!=='all') { $where[]='si.item_type=:cat'; $p[':cat']=(int)$catF; }
            if ($hasLocation && $locF!==''&&$locF!=='all') { $where[]='si.location_id=:loc'; $p[':loc']=(int)$locF; }
            if ($clientF!=='') { $where[]=($hasDsid?'dsp.Customer_Id':'si.client_id').'=:cli'; $p[':cli']=$clientF; }

            $safetyJoin = '';
            if ($qtyF==='low') {
                $safetyJoin = 'JOIN stock_safety_stock ss ON ss.d_id=si.d_id';
                $where[] = 'si.qty>0 AND si.qty<ss.safety_qty';
            } elseif ($qtyF==='zero') { $where[]='si.qty=0'; }
            elseif ($qtyF==='has')    { $where[]='si.qty>0'; }

            // 今日異動篩選
            if ($todayOnly) {
                $where[]="EXISTS(SELECT 1 FROM stock_transactions st3 WHERE st3.stock_item_id=si.stock_item_id AND st3.txn_date=CURDATE())";
            }
            if ($staleYrs > 0) {
                $where[]="si.qty>0 AND NOT EXISTS(SELECT 1 FROM stock_transactions st2 WHERE st2.stock_item_id=si.stock_item_id AND st2.txn_type='out' AND st2.txn_date>=DATE_SUB(CURDATE(),INTERVAL :stale_yr YEAR))";
                $p[':stale_yr']=$staleYrs;
            }

            $wSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
            // 客戶條件(dsp.Customer_Id)可能存在於 $wSQL，COUNT/篩選選項查詢也要一併 JOIN d_setting
            $dsJoinCnt = $hasDsid ? "LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id" : "";

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM stock_items si $safetyJoin $groupJoin $dsJoinCnt $wSQL");
            $cnt->execute($p); $total=(int)$cnt->fetchColumn();

            // 動態 JOIN
            $catJoin  = $hasCategory ? "LEFT JOIN stock_item_categories c ON c.category_id=si.item_type" : "";
            $locJoin  = $hasLocation ? "LEFT JOIN stock_locations l ON l.location_id=si.location_id LEFT JOIN stock_areas sa ON sa.area_id = l.area" : "";
            $unitJoin = $hasUnit     ? "LEFT JOIN stock_units u ON u.unit_id=si.unit_id" : "";
            $catShowSel = $hasCatShow ? "c.show_spec, c.show_label, c.show_keeper, c.show_vendor," : "0 AS show_spec, 0 AS show_label, 0 AS show_keeper, 0 AS show_vendor,";
            $catSel   = $hasCategory ? "c.category_name, c.color AS cat_color, c.need_bom_bind, c.need_order_bind, $catShowSel" : "NULL AS category_name, NULL AS cat_color, 0 AS need_bom_bind, 0 AS need_order_bind, $catShowSel";
            $locSel   = $hasLocation ? "l.location_code, sa.area_name AS area_display_name," : "si.storage_location AS location_code, NULL AS area_display_name,";
            $unitSel  = $hasUnit     ? "u.unit_name, u.unit_symbol," : "NULL AS unit_name, NULL AS unit_symbol,";

            // 群組 JOIN（group_id / pcs_per_set 欄位可能不存在舊資料表）
            $hasPcsPerSet = in_array('pcs_per_set', $stockCols);
            // 動態偵測 stock_item_groups.unit_price 欄位是否存在
            $hasGrpPrice = false;
            if ($hasGroupCol) {
                try {
                    $grpCols = $pdo->query("SHOW COLUMNS FROM stock_item_groups")->fetchAll(PDO::FETCH_COLUMN);
                    $hasGrpPrice = in_array('unit_price', $grpCols);
                } catch(Exception $e2){}
            }
            $grpPriceSel = $hasGrpPrice ? "sig.unit_price AS group_unit_price," : "NULL AS group_unit_price,";
            $groupSel    = $hasGroupCol
                ? "si.group_id, sig.group_name AS group_name_id, sig_ds.D_Setting_Id AS group_name, $grpPriceSel" . ($hasPcsPerSet ? " si.pcs_per_set," : " NULL AS pcs_per_set,")
                : "NULL AS group_id, NULL AS group_name_id, NULL AS group_name, NULL AS group_unit_price, NULL AS pcs_per_set,";
            // COALESCE 售價：sig.unit_price 只在欄位存在時使用
            $calcPriceSel = $hasGrpPrice
                ? "COALESCE(si.unit_price, sig.unit_price, ot.unit_price) AS calc_price,"
                : "COALESCE(si.unit_price, ot.unit_price) AS calc_price,";

            // 料號規格 / 標籤（依品項種類顯示設定，與 master_data 料號分頁同源）
            $dsJoin  = $hasDsid ? "LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id" : "";
            $specSel = ($hasDsid && $anyShowSpec) ? "dsp.Spec_No AS spec_no," : "NULL AS spec_no,";
            // 同料號判定欄位：料號備註(Remark) / 版次(Revision)，配合 料號+客戶+備註+版次 全等才算同料號
            $partIdSel = $hasDsid ? "dsp.Remark AS part_remark, dsp.Revision AS part_revision," : "NULL AS part_remark, NULL AS part_revision,";
            // 客戶：即時查料號目前綁定的客戶（覆蓋 si.* 帶出的舊快照 client_name/client_id），放在 si.* 之後讓同名欄位以此為準
            $clpJoin = $hasDsid ? "LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id" : "";
            $liveClientSel = $hasDsid ? "clp.customer AS client_name, dsp.Customer_Id AS client_id," : "";
            $labelsSubSql = <<<'LBLSQL'
(SELECT GROUP_CONCAT(
     CONCAT(l.label_id,'|',l.label_name,'|',l.input_type,'|',COALESCE(m.input_value,''),'|',l.has_draw_lathe,'|',COALESCE(CAST(m.draw_dim AS CHAR),''),'|',COALESCE(CAST(m.lathe_dim AS CHAR),''),'|',COALESCE((SELECT GROUP_CONCAT(CONCAT(ds.sub_name,'§',COALESCE(slm.input_value,''),'§',COALESCE(CAST(slm.value_min AS CHAR),''),'§',COALESCE(CAST(slm.value_max AS CHAR),''),'§',COALESCE(ds.is_range,0),'§',COALESCE(ds.has_tolerance,0),'§',COALESCE(CAST(slm.tol_upper AS CHAR),''),'§',COALESCE(CAST(slm.tol_lower AS CHAR),''),'§',COALESCE(ds.is_dimension,0),'§',COALESCE(ds.is_qty_dim,0),'§',COALESCE(ds.prefix_char,''),'§',COALESCE(ds.suffix_char,''),'§',COALESCE(CAST(slm.qty AS CHAR),''),'§',COALESCE(ds.is_imperial_dim,0),'§',COALESCE(ds.is_enum,0),'§',COALESCE(ds.hide_name_in_display,0),'§',COALESCE(ds.is_countersink,0),'§',COALESCE(ds.has_draw_lathe_depth,0),'§',COALESCE(CAST(slm.draw_dim AS CHAR),''),'§',COALESCE(CAST(slm.lathe_dim AS CHAR),''),'§',COALESCE(ds.is_triple_dim,0),'§',COALESCE(ds.is_qty_triple_dim,0)) ORDER BY ds.sort_order SEPARATOR '~') FROM item_sub_label_map slm JOIN dict_label_sub ds ON ds.sub_id=slm.sub_id WHERE slm.parent_map_id=m.map_id AND ds.is_active=1),''),'|',COALESCE(l.is_range,0),'|',COALESCE(CAST(m.value_min AS CHAR),''),'|',COALESCE(CAST(m.value_max AS CHAR),''),'|',COALESCE(l.has_tolerance,0),'|',COALESCE(CAST(m.tol_upper AS CHAR),''),'|',COALESCE(CAST(m.tol_lower AS CHAR),''),'|',COALESCE(l.is_calc_diff,0),'|',COALESCE(l.calc_base_name,''),'|',COALESCE(l.calc_sub_name,''),'|',COALESCE(CAST(m.calc_value AS CHAR),''),'|',COALESCE(CAST(m.calc_value_min AS CHAR),''),'|',COALESCE(CAST(m.calc_value_max AS CHAR),''),'|',COALESCE(l.is_dimension,0),'|',COALESCE(l.is_qty_dim,0),'|',COALESCE(l.prefix_char,''),'|',COALESCE(l.suffix_char,''),'|',COALESCE(l.is_hidden_frontend,0),'|',COALESCE(CAST(m.qty AS CHAR),''),'|',COALESCE(l.lathe_optional,0),'|',COALESCE(l.has_draw_lathe_depth,0),'|',COALESCE(l.is_triple_dim,0))
     ORDER BY l.sort_order, m.map_id SEPARATOR '\n')
 FROM item_label_map m JOIN dict_label l ON l.label_id=m.label_id
 WHERE m.d_id=si.d_setting_id AND l.is_active=1)
LBLSQL;
            $labelsSel = ($hasDsid && $anyShowLabel) ? "$labelsSubSql AS labels_str," : "NULL AS labels_str,";

            // 齒輪規格（料號下方顯示用，如「M1 T20 W10 PA20」）：有齒輪資料就顯示，取代標籤那一行，不受品項種類的標籤開關影響
            $gearSpecSel = $hasDsid ? "(SELECT CONCAT_WS(' ',
                    NULLIF(COALESCE(g.module_display, IF(g.Module IS NOT NULL AND g.Module<>'', IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M',g.Module)), '')),''),
                    IF(g.Teeth IS NOT NULL AND g.Teeth>0, CONCAT('T',g.Teeth), NULL),
                    IF(g.Face_Width IS NOT NULL AND g.Face_Width>0, CONCAT('W',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR)))), NULL),
                    IF(g.Pressure_Angle IS NOT NULL AND g.Pressure_Angle<>'', CONCAT('PA',TRIM(TRAILING '°' FROM g.Pressure_Angle)), NULL)
                ) FROM d_setting_gear g WHERE g.d_setting_id=si.d_setting_id ORDER BY g.gear_id LIMIT 1) AS gear_spec_str," : "NULL AS gear_spec_str,";

            // 料號是否有圖資（bom 圖面 / part_attachments 附件），供列表決定是否顯示點擊跳窗連結
            static $hasBomTbl=null,$hasAttachTbl=null;
            if ($hasBomTbl===null){ try{ $hasBomTbl=(bool)$pdo->query("SHOW TABLES LIKE 'bom'")->fetchColumn(); }catch(Exception $e){ $hasBomTbl=false; } }
            if ($hasAttachTbl===null){ try{ $hasAttachTbl=(bool)$pdo->query("SHOW TABLES LIKE 'part_attachments'")->fetchColumn(); }catch(Exception $e){ $hasAttachTbl=false; } }
            $drawSel   = $hasBomTbl ? "(SELECT EXISTS(SELECT 1 FROM bom WHERE d_id=si.d_id)) AS has_drawing," : "0 AS has_drawing,";
            $attachSel = ($hasAttachTbl && $hasDsid) ? "(SELECT EXISTS(SELECT 1 FROM part_attachments WHERE d_id=si.d_setting_id AND deleted_at IS NULL)) AS has_attach," : "0 AS has_attach,";

            $sortColSql = ($sortCol==='client_name' && $hasDsid) ? 'clp.customer' : "si.`$sortCol`";
            $sql = "
                SELECT si.*,
                    $catSel $locSel $unitSel $groupSel
                    $specSel $labelsSel $gearSpecSel $drawSel $attachSel $partIdSel
                    $liveClientSel
                    ot.Order_oo AS order_no,
                    $calcPriceSel
                    ss2.safety_qty,
                    CASE WHEN si.qty=0 THEN 1 ELSE 0 END AS _zero_flag
                FROM stock_items si
                $safetyJoin
                $catJoin $locJoin $unitJoin $groupJoin $dsJoin $clpJoin
                LEFT JOIN order_track ot ON ot.Order_id=si.order_ref
                LEFT JOIN stock_safety_stock ss2 ON ss2.d_id=si.d_id
                $wSQL
                ORDER BY _zero_flag ASC, $sortColSql $sortDir
                LIMIT :lim OFFSET :off
            ";
            $st = $pdo->prepare($sql);
            foreach ($p as $k=>$v) $st->bindValue($k,$v);
            $st->bindValue(':lim',$ps,PDO::PARAM_INT);
            $st->bindValue(':off',$offset,PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // 為本頁有 group_id 的品項，批量查詢同組所有料號（供列表顯示組合構成）
            $groupMembersMap = [];
            if ($hasGroupCol && $hasPcsPerSet) {
                $pageGroupIds = array_values(array_unique(array_filter(array_column($rows, 'group_id'))));
                if (!empty($pageGroupIds)) {
                    try {
                        $gph = implode(',', array_fill(0, count($pageGroupIds), '?'));
                        $siCols4 = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
                        $gmCostSel = in_array('bom_cost_snapshot', $siCols4) ? "si4.bom_cost_snapshot, si4.unit_cost" : "NULL AS bom_cost_snapshot, si4.unit_cost";
                        $gmSql = "SELECT si4.stock_item_id, si4.d_id, si4.qty, si4.pcs_per_set, si4.group_id, si4.bom_ref, $gmCostSel
                                  FROM stock_items si4 WHERE si4.group_id IN ($gph) AND si4.is_active=1 ORDER BY si4.group_id, si4.stock_item_id";
                        $gmSt = $pdo->prepare($gmSql);
                        $gmSt->execute($pageGroupIds);
                        foreach ($gmSt->fetchAll(PDO::FETCH_ASSOC) as $gm) {
                            $groupMembersMap[$gm['group_id']][] = $gm;
                        }
                    } catch(Exception $e2){}
                }
            }
            // 建立 group_order_ref map（供前端顯示[訂單]標籤）
            $groupOrderRefMap = [];
            if ($hasGroupCol && !empty($pageGroupIds ?? [])) {
                try {
                    $sigCols2 = $pdo->query("SHOW COLUMNS FROM stock_item_groups")->fetchAll(PDO::FETCH_COLUMN);
                    if (in_array('order_ref', $sigCols2)) {
                        $gph3 = implode(',', array_fill(0, count($pageGroupIds), '?'));
                        $gs3 = $pdo->prepare("SELECT group_id, order_ref FROM stock_item_groups WHERE group_id IN ($gph3) AND order_ref IS NOT NULL AND order_ref != ''");
                        $gs3->execute($pageGroupIds);
                        foreach ($gs3->fetchAll(PDO::FETCH_ASSOC) as $gr3) {
                            $groupOrderRefMap[$gr3['group_id']] = $gr3['order_ref'];
                        }
                    }
                } catch(Exception $e2){}
            }
            // 將 group_members 和 group_order_ref 附加到各筆 row
            foreach ($rows as &$r) {
                if (!empty($r['group_id']) && isset($groupMembersMap[$r['group_id']])) {
                    $r['group_members'] = $groupMembersMap[$r['group_id']];
                } else {
                    $r['group_members'] = [];
                }
                if (!empty($r['group_id']) && !empty($groupOrderRefMap[$r['group_id']])) {
                    $r['group_order_ref'] = $groupOrderRefMap[$r['group_id']];
                }
            }
            unset($r);

            // ── 本頁快照更新（成本 & 售價）──
            // 確認快照欄位存在（自動補加，首次載入時建立）
            $siColsCheck = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('bom_cost_snapshot', $siColsCheck)) {
                try { $pdo->exec("ALTER TABLE stock_items ADD COLUMN bom_cost_snapshot DECIMAL(12,4) NULL COMMENT 'BOM單顆成本快照：外包來自bom_ing_transfer_log各製程平均單價加總；廠內來自pm_process_daily_report報工KPI' AFTER unit_cost"); $siColsCheck[] = 'bom_cost_snapshot'; } catch(Exception $e2){}
            }
            if (!in_array('order_price_snapshot', $siColsCheck)) {
                try { $pdo->exec("ALTER TABLE stock_items ADD COLUMN order_price_snapshot DECIMAL(12,4) NULL COMMENT '訂單售價快照：優先modified_unit_price，無則unit_price，依order_ref或group.order_ref對應order_track' AFTER unit_price"); $siColsCheck[] = 'order_price_snapshot'; } catch(Exception $e2){}
            }
            // 確認 stock_item_groups.order_ref 欄位存在
            if ($hasGroupCol) {
                try {
                    $sigChk = $pdo->query("SHOW COLUMNS FROM stock_item_groups LIKE 'order_ref'")->fetchColumn();
                    if (!$sigChk) $pdo->exec("ALTER TABLE stock_item_groups ADD COLUMN order_ref VARCHAR(50) NULL COMMENT '組合件綁定訂單號：對應order_track.Order_oo' AFTER unit_price");
                } catch(Exception $e2){}
            }

            // 載入廠內加工商清單（用於區分外包/廠內成本）
            $inhouseMakers = [];
            try { $inhouseMakers = $pdo->query("SELECT maker_id FROM maker_list WHERE internal=1")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch(Exception $e2){}

            // 預先查組合件群組的訂單售價（array_key_exists 避免 isset(null) 漏判）
            $groupOrderPriceMap = [];
            if ($hasGroupCol) {
                try { $sigColsAll = $pdo->query("SHOW COLUMNS FROM stock_item_groups")->fetchAll(PDO::FETCH_COLUMN); $hasGrpOrderRef = in_array('order_ref', $sigColsAll); } catch(Exception $e2){ $hasGrpOrderRef = false; }
                if (!empty($hasGrpOrderRef)) {
                    $pageGids = array_values(array_unique(array_filter(array_column($rows, 'group_id'))));
                    if (!empty($pageGids)) {
                        try {
                            $gph2 = implode(',', array_fill(0, count($pageGids), '?'));
                            $gs2 = $pdo->prepare("SELECT sig.group_id, sig.order_ref, ot.unit_price, ot.modified_unit_price FROM stock_item_groups sig LEFT JOIN order_track ot ON ot.Order_oo=sig.order_ref WHERE sig.group_id IN ($gph2)");
                            $gs2->execute($pageGids);
                            foreach ($gs2->fetchAll(PDO::FETCH_ASSOC) as $gr2) {
                                $gid = $gr2['group_id'];
                                if (!$gid || empty($gr2['order_ref'])) continue;
                                $p2 = floatval($gr2['modified_unit_price'] ?? 0) > 0 ? floatval($gr2['modified_unit_price']) : floatval($gr2['unit_price'] ?? 0);
                                $groupOrderPriceMap[$gid] = $p2 > 0 ? $p2 : null;
                            }
                        } catch(Exception $e2){}
                    }
                }
            }

            // 預先載入 KPI 標準（廠內成本計算用）
            $kpsMap = [];
            try {
                foreach ($pdo->query("SELECT CONCAT(process_no,'_',COALESCE(d_setting_id,0)) AS k, base_time_sec AS base_t, coefficient AS coeff, base_price AS base_p, multiplier FROM kpi_part_standard")->fetchAll(PDO::FETCH_ASSOC) as $kp) $kpsMap[$kp['k']] = $kp;
                foreach ($pdo->query("SELECT CONCAT(g.process_no,'_0') AS k, d.base_time_sec AS base_t, def.default_coefficient AS coeff, d.base_price AS base_p, 1 AS multiplier FROM kpi_std_time_default d JOIN kpi_process_group g ON g.group_id=d.group_id LEFT JOIN kpi_difficulty_default def ON def.group_id=d.group_id")->fetchAll(PDO::FETCH_ASSOC) as $kd) { if (!isset($kpsMap[$kd['k']])) $kpsMap[$kd['k']] = $kd; }
            } catch(Exception $e2){}

            $updCostStmt  = in_array('bom_cost_snapshot',   $siColsCheck) ? $pdo->prepare("UPDATE stock_items SET bom_cost_snapshot=? WHERE stock_item_id=?")   : null;
            $updPriceStmt = in_array('order_price_snapshot', $siColsCheck) ? $pdo->prepare("UPDATE stock_items SET order_price_snapshot=? WHERE stock_item_id=?") : null;

            foreach ($rows as &$r) {
                $sid    = $r['stock_item_id'];
                $bomRef = $r['bom_ref'] ?? null;
                $ordRef = $r['order_ref'] ?? null;
                $dsid   = intval($r['d_setting_id'] ?? 0);

                // ── 成本快照 ──
                if ($updCostStmt && $bomRef) {
                    $costVal = null;
                    try {
                        $biS = $pdo->prepare("SELECT bi.bom_sn, bitl.price, bitl.paid_qty, bitl.modified_unit_price, bitl.maker_from FROM bom_ing_transfer_log bitl JOIN bom_ing bi ON bi.bom_ing_fid=bitl.bom_ing_fid WHERE bitl.bom=?");
                        $biS->execute([$bomRef]); $transferRows = $biS->fetchAll(PDO::FETCH_ASSOC);
                        $outsourceRows = array_filter($transferRows, fn($tr) => !in_array($tr['maker_from'], $inhouseMakers));
                        if (!empty($outsourceRows)) {
                            $byBomSn = [];
                            foreach ($outsourceRows as $tr) {
                                $sn = $tr['bom_sn'] ?? '_';
                                $price = floatval($tr['modified_unit_price'] ?? 0) > 0 ? floatval($tr['modified_unit_price']) : floatval($tr['price'] ?? 0);
                                $qty   = floatval($tr['paid_qty'] ?? 0);
                                if (!isset($byBomSn[$sn])) $byBomSn[$sn] = ['tp'=>0,'tq'=>0];
                                $byBomSn[$sn]['tp'] += $price * $qty; $byBomSn[$sn]['tq'] += $qty;
                            }
                            $uc = 0; foreach ($byBomSn as $d) { if ($d['tq'] > 0) $uc += $d['tp'] / $d['tq']; }
                            $costVal = $uc > 0 ? $uc : null;
                        } else {
                            $gd = null;
                            try { $gdS = $pdo->prepare("SELECT ds.Type, dg.Module, dg.Teeth, dg.Face_Width FROM d_setting ds LEFT JOIN d_setting_gear dg ON dg.d_id=ds.d_id WHERE ds.d_id=?"); $gdS->execute([$dsid]); $gd = $gdS->fetch(PDO::FETCH_ASSOC); } catch(Exception $e3){}
                            $rptS = $pdo->prepare("SELECT r.produced_qty, bi.process_no FROM pm_process_daily_report r JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid WHERE bi.bom=?");
                            $rptS->execute([$bomRef]); $reports = $rptS->fetchAll(PDO::FETCH_ASSOC);
                            $tAmt = 0; $tQty = 0;
                            foreach ($reports as $rpt) {
                                $qty = floatval($rpt['produced_qty'] ?? 0); if ($qty <= 0) continue;
                                $kps = $kpsMap[($rpt['process_no'] ?? '').'_'.$dsid] ?? $kpsMap[($rpt['process_no'] ?? '').'_0'] ?? null;
                                if (!$kps) continue;
                                $coeff = floatval($kps['coeff'] ?? 1); $baseT = floatval($kps['base_t'] ?? 0); $baseP = floatval($kps['base_p'] ?? 0); $multi = floatval($kps['multiplier'] ?? 1);
                                if ($gd && ($gd['Type'] ?? '') === 'G' && floatval($gd['Module'] ?? 0) > 0) {
                                    $amt = $baseT * floatval($gd['Module']) * floatval($gd['Teeth']) * floatval($gd['Face_Width']) * $coeff * $baseP * $qty;
                                } else { $amt = $baseT * $coeff * $multi * $baseP * $qty; }
                                $tAmt += $amt; $tQty += $qty;
                            }
                            $costVal = ($tQty > 0) ? ($tAmt / $tQty) : null;
                        }
                    } catch(Exception $e2){}
                    if ($costVal !== null) { $updCostStmt->execute([$costVal, $sid]); $r['bom_cost_snapshot'] = $costVal; }
                }

                // ── 售價快照 ──
                if ($updPriceStmt) {
                    $priceVal = null;
                    if (!empty($r['group_id']) && array_key_exists($r['group_id'], $groupOrderPriceMap)) {
                        $priceVal = $groupOrderPriceMap[$r['group_id']]; // 可能為 null（已綁訂單但無售價）
                    } elseif ($ordRef) {
                        try {
                            $opS = $pdo->prepare("SELECT unit_price, modified_unit_price FROM order_track WHERE Order_id=?");
                            $opS->execute([$ordRef]); $opr = $opS->fetch(PDO::FETCH_ASSOC);
                            if ($opr) $priceVal = floatval($opr['modified_unit_price'] ?? 0) > 0 ? floatval($opr['modified_unit_price']) : (floatval($opr['unit_price'] ?? 0) > 0 ? floatval($opr['unit_price']) : null);
                        } catch(Exception $e2){}
                    }
                    if ($priceVal !== null) { $updPriceStmt->execute([$priceVal, $sid]); $r['order_price_snapshot'] = $priceVal; }
                }
            }
            unset($r);

            // 取得篩選選項（只含當前篩選結果內有的值）
            $filterLocs=[]; $filterCats=[]; $filterClients=[];
            try {
                $fSql="SELECT DISTINCT si.storage_location,si.location_id FROM stock_items si $safetyJoin $catJoin $locJoin $dsJoinCnt $wSQL ORDER BY si.storage_location";
                $fSt=$pdo->prepare($fSql); foreach($p as $k=>$v) $fSt->bindValue($k,$v); $fSt->execute(); $filterLocs=$fSt->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e2){}
            try {
                // 客戶篩選下拉：即時查料號綁定客戶，不用 si.client_id/client_name 舊快照
                $fSql2 = $hasDsid
                    ? "SELECT DISTINCT dsp.Customer_Id AS client_id, clp.customer AS client_name FROM stock_items si $safetyJoin $catJoin $locJoin $dsJoinCnt LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id $wSQL AND dsp.Customer_Id IS NOT NULL ORDER BY clp.customer"
                    : "SELECT DISTINCT si.client_id,si.client_name FROM stock_items si $safetyJoin $catJoin $locJoin $wSQL WHERE si.client_id IS NOT NULL ORDER BY si.client_name";
                $fSt2=$pdo->prepare($fSql2); foreach($p as $k=>$v) $fSt2->bindValue($k,$v); $fSt2->execute(); $filterClients=$fSt2->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e2){}

            echo json_encode(['success'=>true,'data'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>$ps,'total_pages'=>(int)ceil($total/$ps),'filter_locs'=>$filterLocs,'filter_clients'=>$filterClients]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得訂單列表 (供編輯/入庫彈窗綁定用) ────────
    if ($_POST['action'] === 'get_orders_for_edit') {
        try {
            $did = trim($_POST['d_id'] ?? '');
            $bom = trim($_POST['bom'] ?? '');
            if (!$did) throw new Exception('缺少料號');

            // 搜尋該料號且進行中的訂單 (d_id 在 order_track 儲存的是料號字串)
            $sql = "SELECT Order_id, Order_oo, Qty, Open_Qty, DATE_FORMAT(Delivery_date,'%Y-%m-%d') AS Delivery_date, 
                           Client_name, Specification
                    FROM order_track 
                    WHERE d_id = ? AND (Order_status IS NULL OR Order_status <> 9)
                    ORDER BY Created_At DESC LIMIT 50";
            $st = $pdo->prepare($sql);
            $st->execute([$did]);
            $orders = $st->fetchAll(PDO::FETCH_ASSOC);

            // 載入當前 BOM 的綁定情形
            $my_allocs = [];
            if ($bom) {
                $st_my = $pdo->prepare("SELECT order_id, allocated_qty FROM bom_order_process_map WHERE bom = ?");
                $st_my->execute([$bom]);
                while ($r = $st_my->fetch(PDO::FETCH_ASSOC)) {
                    $my_allocs[$r['order_id']] = $r['allocated_qty'];
                }
            }

            foreach ($orders as &$o) {
                $oid = $o['Order_id'];
                $o['my_allocated'] = $my_allocs[$oid] ?? 0;
                $o['is_bound'] = array_key_exists($oid, $my_allocs);
                
                // 取得其他 BOM 對此訂單的佔用量
                $st_other = $pdo->prepare("SELECT COALESCE(SUM(allocated_qty), 0) FROM bom_order_process_map WHERE order_id = ? AND bom != ?");
                $st_other->execute([$oid, $bom]);
                $o['already_allocated'] = (float)$st_other->fetchColumn();
            }
            echo json_encode(['success' => true, 'orders' => $orders]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 完整刪除庫存品項（僅限A權限，硬刪除）──────
    if ($_POST['action'] === 'purge_stock_item') {
        try {
            // 確認 user 是否有 stock module A 權限
            $chkPerm=$pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock'");
            $chkPerm->execute([$userId]); $permRow=$chkPerm->fetch(PDO::FETCH_ASSOC);
            if (!$permRow || $permRow['permission']!=='A') throw new Exception('權限不足，需要 A 級權限');
            $confirm = trim($_POST['confirm_text']??''); if($confirm!=='Y') throw new Exception('確認文字必須輸入 Y');
            $id=intval($_POST['stock_item_id']??0); if(!$id) throw new Exception('未指定');
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM stock_count_details WHERE stock_item_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM stock_transactions WHERE stock_item_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM stock_item_units WHERE d_setting_id=(SELECT d_setting_id FROM stock_items WHERE stock_item_id=?)")->execute([$id]);
            $pdo->prepare("DELETE FROM stock_items WHERE stock_item_id=?")->execute([$id]);
            $pdo->commit();
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得單筆詳情 ──────────────────────────────
    if ($_POST['action'] === 'get_stock_detail') {
        try {
            $id = intval($_POST['stock_item_id']??0);
            if (!$id) throw new Exception('未指定ID');

            $cols=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCat=in_array('item_type',$cols);
            $hasLoc=in_array('location_id',$cols);
            $hasUnit=in_array('unit_id',$cols);

            $catJ=$hasCat?"LEFT JOIN stock_item_categories c ON c.category_id=si.item_type":"";
            $locJ=$hasLoc?"LEFT JOIN stock_locations l ON l.location_id=si.location_id":"";
            $unitJ=$hasUnit?"LEFT JOIN stock_units u ON u.unit_id=si.unit_id":"";
            $catS=$hasCat?"c.category_name, c.need_bom_bind, c.need_order_bind,":"NULL AS category_name,0 AS need_bom_bind,0 AS need_order_bind,";
            $locS=$hasLoc?"l.location_code,":"NULL AS location_code,";
            $unitS=$hasUnit?"u.unit_name, u.unit_symbol,":"NULL AS unit_name, NULL AS unit_symbol,";

            // 客戶：即時查料號目前綁定客戶，覆蓋 si.* 帶出的舊快照 client_name/client_id
            $st = $pdo->prepare("
                SELECT si.*, $catS $locS $unitS
                    ot.Order_oo AS order_no, ot.unit_price AS order_unit_price,
                    clp.customer AS client_name, dsp.Customer_Id AS client_id
                FROM stock_items si
                $catJ $locJ $unitJ
                LEFT JOIN order_track ot ON ot.Order_id=si.order_ref
                LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id
                LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id
                WHERE si.stock_item_id=?
            ");
            $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('找不到');

            // 異動歷程（加入每筆異動的 bom_ref / order_ref / package_box）
            $txnCols=$pdo->query("SHOW COLUMNS FROM stock_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasTxnBom=in_array('bom_ref',$txnCols);
            $hasTxnPkg=in_array('package_box',$txnCols);
            $hasTxnDept=in_array('out_dept_id',$txnCols);
            $txnBomS=$hasTxnBom?"st.bom_ref AS txn_bom, st.order_ref AS txn_order_ref,":"NULL AS txn_bom, NULL AS txn_order_ref,";
            $txnPkgS=$hasTxnPkg?"st.package_box AS txn_pkg,":"NULL AS txn_pkg,";
            $txnDeptJ=$hasTxnDept?"LEFT JOIN department d ON d.id=st.out_dept_id LEFT JOIN user ou ON ou.id=st.out_user_id":"";
            $txnDeptS=$hasTxnDept?"d.name AS dept_name, ou.user_cname AS out_user_name,":"NULL AS dept_name, NULL AS out_user_name,";
            $txnUnitJ=in_array('txn_unit_id',$txnCols)?"LEFT JOIN stock_units su ON su.unit_id=st.txn_unit_id":"";
            $txnUnitS=in_array('txn_unit_id',$txnCols)?"su.unit_name AS txn_unit_name,":"NULL AS txn_unit_name,";

            // 同時查詢每筆入庫關聯的訂單號
            $ts = $pdo->prepare("
                SELECT st.*, $txnBomS $txnPkgS $txnDeptS $txnUnitS
                    u.user_cname AS op_name,
                    ot2.Order_oo AS txn_order_no
                FROM stock_transactions st
                LEFT JOIN user u ON u.id=st.Created_By
                $txnDeptJ $txnUnitJ
                LEFT JOIN order_track ot2 ON ot2.Order_id=st.order_ref
                WHERE st.stock_item_id=?
                ORDER BY st.txn_date DESC, st.txn_id DESC LIMIT 60
            ");
            $ts->execute([$id]); $txns=$ts->fetchAll(PDO::FETCH_ASSOC);

            // BOM成本
            $bomCost=null;
            if (!empty($row['bom_ref'])) {
                try { $bs=$pdo->prepare("SELECT COALESCE(SUM(process_amount),0) AS tc, COUNT(*) AS pc FROM bom_ing_transfer_log WHERE bom=?"); $bs->execute([$row['bom_ref']]); $bomCost=$bs->fetch(PDO::FETCH_ASSOC); } catch(Exception $e2){}
            }
            // 同料號所有儲位（含本筆）— 組合件與非組合件分開，不混計
            $hasR1=in_array('remark1',$cols);
            $hasGroupIdCol=in_array('group_id',$cols);
            $locSelectCols="si2.stock_item_id, si2.d_id, si2.storage_location, si2.qty".($hasR1?", si2.remark1":"");
            $locJ2=$hasLoc?"LEFT JOIN stock_locations l2 ON l2.location_id=si2.location_id":"";
            $locS2=$hasLoc?", l2.location_code":"";
            $unitJ2=$hasUnit?"LEFT JOIN stock_units u2 ON u2.unit_id=si2.unit_id":"";
            $unitS2=$hasUnit?", u2.unit_name, u2.unit_symbol":"";
            // 根據本筆是否為組合件成員，篩選同類型品項
            $grpWhereExtra='';
            if($hasGroupIdCol){
                $rowGroupId=intval($row['group_id']??0);
                if($rowGroupId>0){
                    // 本筆為組合件成員 → 只顯示同組的成員
                    $grpWhereExtra=' AND si2.group_id='.intval($rowGroupId);
                } else {
                    // 本筆為一般品項 → 排除所有組合件成員
                    $grpWhereExtra=' AND (si2.group_id IS NULL OR si2.group_id=0)';
                }
            }
            // 同料號判定：料號+客戶+備註+版次 全等。取本筆料號備註/版次
            $rowRemark=''; $rowRevision='';
            if (!empty($row['d_setting_id'])) {
                try { $dq=$pdo->prepare("SELECT Remark,Revision FROM d_setting WHERE d_id=?"); $dq->execute([$row['d_setting_id']]); $dr=$dq->fetch(PDO::FETCH_ASSOC); if($dr){ $rowRemark=$dr['Remark']??''; $rowRevision=$dr['Revision']??''; } } catch(Exception $e2){}
            }
            // 客戶為料號綁定屬性，同料號必同客戶，不再比對 client_name
            $other=$pdo->prepare("SELECT $locSelectCols $locS2 $unitS2 FROM stock_items si2 LEFT JOIN d_setting ds2 ON ds2.d_id=si2.d_setting_id $locJ2 $unitJ2 WHERE si2.d_id=? AND si2.is_active=1 AND COALESCE(ds2.Remark,'')=? AND COALESCE(ds2.Revision,'')=?{$grpWhereExtra} ORDER BY si2.qty DESC");
            $other->execute([$row['d_id'], $rowRemark, $rowRevision]); $otherLocs=$other->fetchAll(PDO::FETCH_ASSOC);

            // 如果此品項屬於組合件，取得同組所有料號資訊
            $groupMembers = [];
            if (!empty($row['group_id'])) {
                try {
                    $hasGroupCol2 = in_array('group_id', $cols) && in_array('pcs_per_set', $cols);
                    if ($hasGroupCol2) {
                        // 動態偵測 stock_item_groups.unit_price
                        $hasGrpPrice2 = false;
                        try {
                            $grpCols2 = $pdo->query("SHOW COLUMNS FROM stock_item_groups")->fetchAll(PDO::FETCH_COLUMN);
                            $hasGrpPrice2 = in_array('unit_price', $grpCols2);
                        } catch(Exception $e3){}
                        $grpPriceSel2 = $hasGrpPrice2 ? "sig.unit_price AS group_unit_price" : "NULL AS group_unit_price";

                        $gmStmt = $pdo->prepare("
                            SELECT si3.stock_item_id, si3.d_id, si3.d_setting_id, si3.qty, si3.pcs_per_set, si3.unit_cost,
                                   si3.bom_ref, si3.order_ref, clp3.customer AS client_name,
                                   ot3.Order_oo AS order_no,
                                   " . ($hasUnit?"u3.unit_name, u3.unit_symbol,":"NULL AS unit_name, NULL AS unit_symbol,") . "
                                   sig.group_name AS group_name_id, sig_ds3.D_Setting_Id AS group_name, $grpPriceSel2
                            FROM stock_items si3
                            " . ($hasUnit?"LEFT JOIN stock_units u3 ON u3.unit_id=si3.unit_id":"") . "
                            LEFT JOIN stock_item_groups sig ON sig.group_id=si3.group_id
                            LEFT JOIN d_setting sig_ds3 ON sig_ds3.d_id=sig.group_name
                            LEFT JOIN order_track ot3 ON ot3.Order_id=si3.order_ref
                            LEFT JOIN d_setting dsp3 ON dsp3.d_id=si3.d_setting_id
                            LEFT JOIN customer_list clp3 ON clp3.customer_id=dsp3.Customer_Id
                            WHERE si3.group_id=? AND si3.is_active=1
                            ORDER BY si3.stock_item_id ASC
                        ");
                        $gmStmt->execute([$row['group_id']]);
                        $groupMembers = $gmStmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                } catch(Exception $e2){}
            }

            echo json_encode(['success'=>true,'data'=>$row,'transactions'=>$txns,'bom_cost'=>$bomCost,'other_locations'=>$otherLocs,'current_id'=>$id,'group_members'=>$groupMembers]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 儲存品項 ──────────────────────────────────
    if ($_POST['action'] === 'save_stock_item') {
        try {
            $pdo->beginTransaction(); // Start transaction for the entire operation

            $id          = intval($_POST['stock_item_id']??0);
            $d_id        = trim($_POST['d_id']??''); if (!$d_id) throw new Exception('料號必填');
            $d_setting_id= intval($_POST['d_setting_id']??0)?:null;
            $category_id = intval($_POST['category_id']??0)?:null;
            $location_id = intval($_POST['location_id']??0)?:null;
            $loc_str     = trim($_POST['storage_location']??'')?:null;
            $qty         = max(0, (float)($_POST['qty']??0));
            $unit_id     = intval($_POST['unit_id']??0)?:null;
            $bom_ref     = trim($_POST['bom_ref']??'')?:null;
            $order_ref   = intval($_POST['order_ref']??0)?:null;
            $unit_cost   = ($_POST['unit_cost']??'')!==''?floatval($_POST['unit_cost']):null;
            $unit_price  = ($_POST['unit_price']??'')!==''?floatval($_POST['unit_price']):null;
            $mfg         = ($_POST['mfg_date']??'')?:null;
            $sd          = ($_POST['stock_date']??'')?:null;
            $ey          = intval($_POST['expire_years']??0)?:null;
            $pkg         = trim($_POST['package_box']??'')?:null;
            $cname       = trim($_POST['client_name']??'')?:null;
            $cid         = trim($_POST['client_id']??'')?:null;
            $r1          = trim($_POST['remark1']??'')?:null;
            $keeperId    = intval($_POST['keeper_id']??0)?:null;
            $keeperName  = trim($_POST['keeper_name']??'')?:null;
            $vendorId    = trim($_POST['vendor_id']??'')?:null;
            $vendorName  = trim($_POST['vendor_name']??'')?:null;

            // 動態偵測欄位
            $existCols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCol = fn($c) => in_array($c, $existCols);

            // 位置字串快照
            if ($location_id && $hasCol('location_id')) {
                try {
                    $lr=$pdo->prepare("SELECT location_code FROM stock_locations WHERE location_id=?");
                    $lr->execute([$location_id]); $lr=$lr->fetch(PDO::FETCH_ASSOC);
                    if ($lr) $loc_str=$lr['location_code'];
                } catch(Exception $e2){}
            }
            // 訂單售價：不自動寫入 unit_price，售價由 order_price_snapshot 快照提供
            // 種類預設保存年限
            if ($ey===null && $category_id && $hasCol('item_type')) {
                try {
                    $cp=$pdo->prepare("SELECT default_expire_years FROM stock_item_categories WHERE category_id=?");
                    $cp->execute([$category_id]); $cpr=$cp->fetch(PDO::FETCH_ASSOC);
                    if ($cpr && $cpr['default_expire_years']) $ey=$cpr['default_expire_years'];
                    $sp=$pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='STOCK_EXPIRE' AND param_key=?");
                    $sp->execute(["category_{$category_id}"]);
                    $spr=$sp->fetch(PDO::FETCH_ASSOC);
                    if ($spr) { $spv=json_decode($spr['param_value'],true); if (!empty($spv['years'])) $ey=(int)$spv['years']; }
                } catch(Exception $e2){}
            }

            // 只加入資料表中存在的欄位
            $allFields = [
                'd_id'=>$d_id,
                'd_setting_id'=>$d_setting_id,
                'item_type'=>$category_id,
                'location_id'=>$location_id,
                'storage_location'=>$loc_str,
                'qty'=>$qty,
                'unit_id'=>$unit_id,
                'bom_ref'=>$bom_ref,
                'order_ref'=>$order_ref,
                'unit_cost'=>$unit_cost,
                'unit_price'=>$unit_price,
                'mfg_date'=>$mfg,
                'stock_date'=>$sd,
                'expire_years'=>$ey,
                'package_box'=>$pkg,
                'client_name'=>$cname,
                'client_id'=>$cid,
                'keeper_id'=>$keeperId,
                'keeper_name'=>$keeperName,
                'vendor_id'=>$vendorId,
                'vendor_name'=>$vendorName,
                'remark1'=>$r1,
                'remark2'=>null,  // 保留欄位但不從前端接收
            ];
            // 過濾不存在的欄位
            $fields = array_filter($allFields, fn($k) => $hasCol($k), ARRAY_FILTER_USE_KEY);

            // ── 組合件特殊處理：若是新增品項，自動偵測是否為組合件 ──
            if ($id == 0) {
                // 若前端未傳入 dsid (例如手打料號)，則由料號代碼補查
                if (!$d_setting_id && !empty($d_id)) {
                    $stD = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
                    $stD->execute([$d_id]);
                    $d_setting_id = (int)$stD->fetchColumn();
                }

                if ($d_setting_id) {
                $stAsm = $pdo->prepare("SELECT Is_Assembly, D_Setting_Id FROM d_setting WHERE d_id = ?");
                $stAsm->execute([$d_setting_id]);
                $asmInfo = $stAsm->fetch(PDO::FETCH_ASSOC);

                    if ($asmInfo && $asmInfo['Is_Assembly'] == 1) { // If it's an assembly
                    $stChild = $pdo->prepare("SELECT b.child_d_id, b.standard_qty, d.D_Setting_Id FROM d_setting_bom b JOIN d_setting d ON d.d_id = b.child_d_id WHERE b.parent_d_id = ?");
                    $stChild->execute([$d_setting_id]);
                    $children = $stChild->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($children)) {
                        // 確保表與欄位存在（group_name 改存 d_setting.d_id 整數）
                        if (!$pdo->query("SHOW TABLES LIKE 'stock_item_groups'")->fetchColumn()) {
                            $pdo->exec("CREATE TABLE stock_item_groups (group_id INT AUTO_INCREMENT PRIMARY KEY, group_name INT NOT NULL COMMENT '對應 d_setting.d_id', unit_price DECIMAL(12,4) NULL, remark VARCHAR(200) NULL, Created_By INT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, Modified_By INT NULL, Modified_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                        } else {
                            if (!$pdo->query("SHOW COLUMNS FROM stock_item_groups LIKE 'unit_price'")->fetchColumn()) $pdo->exec("ALTER TABLE stock_item_groups ADD COLUMN unit_price DECIMAL(12,4) NULL AFTER group_name");
                            $gnColInfo = $pdo->query("SHOW COLUMNS FROM stock_item_groups LIKE 'group_name'")->fetch(PDO::FETCH_ASSOC);
                            if ($gnColInfo && stripos($gnColInfo['Type'], 'int') === false) {
                                try { $pdo->exec("ALTER TABLE stock_item_groups MODIFY COLUMN group_name INT NOT NULL COMMENT '對應 d_setting.d_id'"); } catch(Exception $e_alt){}
                            }
                        }
                        if (!$pdo->query("SHOW COLUMNS FROM stock_items LIKE 'group_id'")->fetchColumn()) $pdo->exec("ALTER TABLE stock_items ADD COLUMN group_id INT NULL DEFAULT NULL AFTER is_active, ADD INDEX idx_group_id (group_id)");
                        if (!$pdo->query("SHOW COLUMNS FROM stock_items LIKE 'pcs_per_set'")->fetchColumn()) $pdo->exec("ALTER TABLE stock_items ADD COLUMN pcs_per_set INT NULL DEFAULT NULL AFTER group_id");
                        
                        $hasCol = fn($c) => in_array($c, $existCols);

                        // 2. 建立群組（group_name 存 d_setting.d_id 整數）
                        $pdo->prepare("INSERT INTO stock_item_groups (group_name, unit_price, remark, Created_By) VALUES (?,?,?,?)")
                            ->execute([$d_setting_id, $unit_price, $r1, $userId]);
                        $groupId = (int)$pdo->lastInsertId();

                        $sets = $qty; // 「數量」輸入框在此模式下視為組數
                        $createdCount = 0;

                        foreach ($children as $child) {
                            $childPps = max(1, (int)($child['standard_qty'] ?: 1));
                            $childQty = $childPps * $sets;
                            
                            $cFields = $allFields;
                            $cFields['d_id']         = $child['D_Setting_Id'];
                            $cFields['d_setting_id'] = $child['child_d_id'];
                            $cFields['qty']          = $childQty;
                            $cFields['unit_price']   = null; // 子件售價設為空，使其自動套用群組售價
                            $cFields['group_id']     = $groupId;
                            $cFields['pcs_per_set']  = $childPps;
                            $cFields['unit_id']      = 1; // 子件基本單位預設為 PCS
                            
                            $filtered = array_filter($cFields, fn($k) => $hasCol($k), ARRAY_FILTER_USE_KEY);
                            $cCols = implode(',', array_map(fn($k)=>"`$k`", array_keys($filtered)));
                            $cPhs  = implode(',', array_map(fn($k)=>":$k", array_keys($filtered)));
                            $stIns = $pdo->prepare("INSERT INTO stock_items ($cCols, Created_By, Modified_By) VALUES ($cPhs, :cby, :cby2)");
                            $stIns->execute(array_merge($filtered, [':cby'=>$userId, ':cby2'=>$userId]));
                            $newChildId = $pdo->lastInsertId();
                            $createdCount++;

                            $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_to,location_to_id,bom_ref,order_ref,package_box,txn_date,remark,Created_By) VALUES (?,?,'in',?,0,?,?,?,?,?,?,CURDATE(),?,?)")
                                ->execute([$newChildId, $child['D_Setting_Id'], $childQty, $childQty, $loc_str, $location_id, $bom_ref, $order_ref, $pkg, "組合件自動拆分建檔 (入庫 $sets 組)", $userId]);
                        }
                        // Removed premature commit. The transaction will be committed at the end of the action.
                        echo json_encode(['success'=>true, 'message'=>'組合件「'.$asmInfo['D_Setting_Id'].'」已依 BOM 結構建立庫存，入庫 '.$sets.' 組', 'id'=>0]);
                        exit;
                    }
                }}
            }

            if ($id > 0) {
                $old = $pdo->prepare("SELECT qty,storage_location" . (($hasCol('location_id')) ? ",location_id" : "") . " FROM stock_items WHERE stock_item_id=?");
                $old->execute([$id]);
                $oldR = $old->fetch(PDO::FETCH_ASSOC);
                $sets = implode(',', array_map(fn($k) => "`$k`=:$k", array_keys($fields)));
                $stmt = $pdo->prepare("UPDATE stock_items SET $sets,Modified_By=:mby,Modified_At=NOW() WHERE stock_item_id=:sid");
                $stmt->execute(array_merge($fields, [':mby' => $userId, ':sid' => $id]));

                if ($oldR && round((float)$oldR['qty'], 4) !== round($qty, 4)) {
                    $diff = round($qty - $oldR['qty'], 4);
                    try {
                        $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'adjust',?,?,?,CURDATE(),'手動調整數量',?)")->execute([$id, $d_id, $diff, $oldR['qty'], $qty, $userId]);
                    } catch (Exception $e2) {
                    }
                }
                if ($hasCol('location_id') && $oldR && ($oldR['storage_location'] ?? '') !== ($loc_str ?? '')) {
                    try {
                        $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_from,location_to,location_from_id,location_to_id,txn_date,remark,Created_By) VALUES (?,?,'move',0,?,?,?,?,?,?,CURDATE(),'位置變更',?)")->execute([$id, $d_id, $qty, $qty, $oldR['storage_location'], $loc_str, $oldR['location_id'] ?? null, $location_id, $userId]);
                    } catch (Exception $e2) {
                    }
                }
                echo json_encode(['success' => true, 'message' => '修改成功', 'id' => $id]);
            } else {
                $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($fields)));
                $phs = implode(',', array_map(fn($k) => ":$k", array_keys($fields)));
                $stmt = $pdo->prepare("INSERT INTO stock_items ($cols,Created_By,Modified_By) VALUES ($phs,:cby,:cby2)");
                $stmt->execute(array_merge($fields, [':cby' => $userId, ':cby2' => $userId]));
                $newId = $pdo->lastInsertId();
                if ($qty > 0) {
                    try {
                        $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_to,location_to_id,bom_ref,order_ref,package_box,txn_date,remark,Created_By) VALUES (?,?,'in',?,0,?,?,?,?,?,?,CURDATE(),'初始建檔入庫',?)")->execute([$newId, $d_id, $qty, $qty, $loc_str, $location_id, $bom_ref, $order_ref, $pkg, $userId]);
                    } catch (Exception $e2) {
                    }
                }
                echo json_encode(['success' => true, 'message' => '新增成功', 'id' => $newId]);
            }
            $pdo->commit(); // Commit the transaction at the end
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 快速入庫 ──────────────────────────────────
    if ($_POST['action'] === 'quick_in') {
        try {
            $id         = intval($_POST['stock_item_id']??0); if(!$id) throw new Exception('未指定');
            $chgQty     = (float)($_POST['change_qty']??0); if($chgQty<=0) throw new Exception('數量必須>0');
            $txnUnitId  = intval($_POST['txn_unit_id']??0)?:null;
            $qtyInUnit  = (float)($_POST['qty_in_unit']??$chgQty);
            $convFactor = $_POST['convert_factor']!==''?(float)$_POST['convert_factor']:null;
            $locId      = intval($_POST['location_to_id']??0)?:null;
            $locStr     = trim($_POST['location_to']??'')?:null;
            $bomRef     = trim($_POST['bom_ref']??'')?:null;
            $orderRef   = intval($_POST['order_ref']??0)?:null;
            $pkg        = trim($_POST['package_box']??'')?:null;
            $remark     = trim($_POST['remark']??'')?:null;
            $txnDate    = $_POST['txn_date']??date('Y-m-d');

            $cur=$pdo->prepare("SELECT d_id,qty,storage_location,location_id FROM stock_items WHERE stock_item_id=?");
            $cur->execute([$id]); $c=$cur->fetch(PDO::FETCH_ASSOC); if(!$c) throw new Exception('找不到');

            $qtyAfter = round($c['qty']+$chgQty, 4);

            $pdo->beginTransaction();
            // 若有指定新儲位，更新位置
            $newLoc   = $locStr   ?: $c['storage_location'];
            $newLocId = $locId    ?: $c['location_id'];
            // 若位置快照為空但有location_id，查詢
            if ($locId && !$locStr) {
                $ll=$pdo->prepare("SELECT location_code FROM stock_locations WHERE location_id=?");
                $ll->execute([$locId]); $llr=$ll->fetch(PDO::FETCH_ASSOC);
                if ($llr) $newLoc=$llr['location_code'];
            }
            $pdo->prepare("UPDATE stock_items SET qty=?,location_id=?,storage_location=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$qtyAfter,$newLocId,$newLoc,$userId,$id]);
            $stIn=$pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,txn_qty_in_unit,txn_unit_id,convert_factor,qty_before,qty_after,location_to,location_to_id,bom_ref,order_ref,package_box,txn_date,remark,Created_By) VALUES (?,?,'in',?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if(!$stIn->execute([$id,$c['d_id'],$chgQty,$qtyInUnit,$txnUnitId,$convFactor,$c['qty'],$qtyAfter,$newLoc,$newLocId,$bomRef,$orderRef,$pkg,$txnDate,$remark,$userId])){ $ei=$stIn->errorInfo(); throw new Exception('入庫紀錄寫入失敗：'.($ei[2]??'未知錯誤')); }
            $pdo->commit();
            echo json_encode(['success'=>true,'qty_after'=>$qtyAfter]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 快速出庫 ──────────────────────────────────
    if ($_POST['action'] === 'quick_out') {
        try {
            $id        = intval($_POST['stock_item_id']??0); if(!$id) throw new Exception('未指定');
            $chgQty    = (float)($_POST['change_qty']??0); if($chgQty<=0) throw new Exception('數量必須>0');
            $txnUnitId = intval($_POST['txn_unit_id']??0)?:null;
            $qtyInUnit = (float)($_POST['qty_in_unit']??$chgQty);
            $convFactor= $_POST['convert_factor']!==''?(float)$_POST['convert_factor']:null;
            $outDeptId = intval($_POST['out_dept_id']??0)?:null;
            $outUserId = intval($_POST['out_user_id']??0)?:null;
            $remark    = trim($_POST['remark']??'')?:null;
            $txnDate   = $_POST['txn_date']??date('Y-m-d');

            $cur=$pdo->prepare("SELECT d_id,qty FROM stock_items WHERE stock_item_id=?");
            $cur->execute([$id]); $c=$cur->fetch(PDO::FETCH_ASSOC); if(!$c) throw new Exception('找不到');
            if ($chgQty>$c['qty']) throw new Exception("出庫數量({$chgQty})超過庫存(".round($c['qty'],4).")");

            $qtyAfter=round($c['qty']-$chgQty,4);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$qtyAfter,$userId,$id]);
            $stOut=$pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,txn_qty_in_unit,txn_unit_id,convert_factor,qty_before,qty_after,out_dept_id,out_user_id,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?,?,?,?,?,?)");
            if(!$stOut->execute([$id,$c['d_id'],-$chgQty,$qtyInUnit,$txnUnitId,$convFactor,$c['qty'],$qtyAfter,$outDeptId,$outUserId,$txnDate,$remark,$userId])){ $eo=$stOut->errorInfo(); throw new Exception('出庫紀錄寫入失敗：'.($eo[2]??'未知錯誤')); }
            $pdo->commit();
            echo json_encode(['success'=>true,'qty_after'=>$qtyAfter]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 快速移位 ──────────────────────────────────
    if ($_POST['action'] === 'quick_move') {
        try {
            $id      = intval($_POST['stock_item_id']??0);
            $locId   = intval($_POST['location_to_id']??0)?:null;
            $locStr  = trim($_POST['location_to']??'')?:null;
            $remark  = trim($_POST['remark']??'')?:null;
            $txnDate = $_POST['txn_date']??date('Y-m-d');
            if (!$locId && !$locStr) throw new Exception('請指定移入位置');

            $cur=$pdo->prepare("SELECT d_id,d_setting_id,qty,storage_location,location_id,client_id FROM stock_items WHERE stock_item_id=? AND is_active=1");
            $cur->execute([$id]); $c=$cur->fetch(PDO::FETCH_ASSOC); if(!$c) throw new Exception('找不到');

            // 取來源料號的備註/版次（同料號判定：料號+客戶+備註+版次 全等）
            $srcRemark=''; $srcRevision='';
            if (!empty($c['d_setting_id'])) {
                try { $dsr=$pdo->prepare("SELECT Remark,Revision FROM d_setting WHERE d_id=?"); $dsr->execute([$c['d_setting_id']]); $dsrr=$dsr->fetch(PDO::FETCH_ASSOC); if($dsrr){ $srcRemark=$dsrr['Remark']??''; $srcRevision=$dsrr['Revision']??''; } } catch(Exception $e2){}
            }

            if ($locId && !$locStr) {
                $ll=$pdo->prepare("SELECT location_code FROM stock_locations WHERE location_id=?");
                $ll->execute([$locId]); $llr=$ll->fetch(PDO::FETCH_ASSOC);
                if ($llr) $locStr=$llr['location_code'];
            }

            // 偵測目標儲位是否有相同料號的庫存（料號+客戶+備註+版次 全等才算同料號）
            $existSql="SELECT si.stock_item_id,si.qty FROM stock_items si LEFT JOIN d_setting ds ON ds.d_id=si.d_setting_id WHERE si.d_id=? AND si.is_active=1 AND si.stock_item_id!=? AND COALESCE(ds.Remark,'')=? AND COALESCE(ds.Revision,'')=?";
            $existParams=[$c['d_id'],$id,$srcRemark,$srcRevision];
            if ($locId) { $existSql.=" AND si.location_id=?"; $existParams[]=$locId; }
            elseif ($locStr) { $existSql.=" AND si.storage_location=?"; $existParams[]=$locStr; }
            // 客戶為料號綁定屬性，同料號必同客戶，不再比對 client_id
            $existSql.=" LIMIT 1";
            $ex=$pdo->prepare($existSql); $ex->execute($existParams); $existRow=$ex->fetch(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            if ($existRow) {
                // ── 目標儲位已有相同料號+客戶 → 自動合併 ──
                $targetId  = $existRow['stock_item_id'];
                $targetQty = floatval($existRow['qty']);
                $moveQty   = floatval($c['qty']);
                $newQty    = round($targetQty + $moveQty, 4);

                // 把本筆異動記錄移到目標筆
                $pdo->prepare("UPDATE stock_transactions SET stock_item_id=? WHERE stock_item_id=?")->execute([$targetId,$id]);
                // 更新目標筆數量
                $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$newQty,$userId,$targetId]);
                // 軟刪除本筆
                $pdo->prepare("UPDATE stock_items SET is_active=0,qty=0,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$userId,$id]);
                // 記錄合併異動
                $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_from,location_to,location_from_id,location_to_id,txn_date,remark,Created_By) VALUES (?,?,'adjust',?,?,?,?,?,?,?,?,?,?)")->execute([$targetId,$c['d_id'],$moveQty,$targetQty,$newQty,$c['storage_location'],$locStr,$c['location_id'],$locId,$txnDate,'移位自動合併: '.($remark??''),$userId]);
                $pdo->commit();
                echo json_encode(['success'=>true,'location'=>$locStr,'merged'=>true,'new_qty'=>$newQty,'message'=>'目標儲位已有相同料號，已自動合併！新庫存：'.$newQty]);
            } else {
                // ── 一般移位 ──
                $pdo->prepare("UPDATE stock_items SET location_id=?,storage_location=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$locId,$locStr,$userId,$id]);
                $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_from,location_to,location_from_id,location_to_id,txn_date,remark,Created_By) VALUES (?,?,'move',0,?,?,?,?,?,?,?,?,?)")->execute([$id,$c['d_id'],$c['qty'],$c['qty'],$c['storage_location'],$locStr,$c['location_id'],$locId,$txnDate,$remark,$userId]);
                $pdo->commit();
                echo json_encode(['success'=>true,'location'=>$locStr,'merged'=>false]);
            }
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 刪除 ──────────────────────────────────────
    if ($_POST['action'] === 'delete_stock_item') {
        try {
            $id=intval($_POST['stock_item_id']??0);
            $pdo->prepare("UPDATE stock_items SET is_active=0,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$userId,$id]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 搜尋料號 ──────────────────────────────────
    if ($_POST['action'] === 'search_d_id') {
        try {
            $t=trim($_POST['term']??''); if(!$t){ echo json_encode(['success'=>true,'data'=>[]]); exit; }
            $st=$pdo->prepare("SELECT d.d_id,d.D_Setting_Id,d.Spec_No,d.Customer_Id,d.Revision,c.customer AS client_name FROM d_setting d LEFT JOIN customer_list c ON d.Customer_Id=c.customer_id WHERE d.D_Setting_Id LIKE :t OR d.Spec_No LIKE :t LIMIT 15");
            $st->execute([':t'=>"%$t%"]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 搜尋保管者（依姓名 / 部門模糊搜尋在職人員）──────
    if ($_POST['action'] === 'search_keeper') {
        try {
            $t=trim($_POST['term']??''); if(!$t){ echo json_encode(['success'=>true,'data'=>[]]); exit; }
            $st=$pdo->prepare("SELECT u.id, u.user_cname, GROUP_CONCAT(DISTINCT d.name ORDER BY d.name SEPARATOR '、') AS dept_names
                FROM user u
                LEFT JOIN user_department_position_map m ON m.user_id=u.id
                LEFT JOIN department d ON d.id=m.department_id
                WHERE u.state=1 AND (u.user_cname LIKE :t OR d.name LIKE :t)
                GROUP BY u.id, u.user_cname
                ORDER BY u.user_cname LIMIT 15");
            $st->execute([':t'=>"%$t%"]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 搜尋廠商（依簡稱 / 全稱 / 編號模糊搜尋）──────────
    if ($_POST['action'] === 'search_vendor') {
        try {
            $t=trim($_POST['term']??''); if(!$t){ echo json_encode(['success'=>true,'data'=>[]]); exit; }
            $st=$pdo->prepare("SELECT maker_id_no, maker_id, maker_id_all FROM maker_list WHERE maker_id LIKE :t OR maker_id_all LIKE :t OR maker_id_no LIKE :t ORDER BY maker_id LIMIT 15");
            $st->execute([':t'=>"%$t%"]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 批次新增：料號種類 / 工件小類 字典 ─────────────
    if ($_POST['action'] === 'get_wp_filter_dicts') {
        try {
            $types=[]; $subs=[];
            try { $types=$pdo->query("SELECT type_code,type_name FROM dict_workpiece_type WHERE is_active=1 ORDER BY sort_order,type_code")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e2){}
            try { $subs=$pdo->query("SELECT sub_type_id,type_code,sub_type_name FROM dict_workpiece_sub_type WHERE is_active=1 ORDER BY type_code,sort_order")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e2){}
            echo json_encode(['success'=>true,'types'=>$types,'subs'=>$subs]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 批次新增：依料號種類 / 工件小類 / 關鍵字 搜尋料號 ──
    if ($_POST['action'] === 'batch_search_parts') {
        try {
            $type=trim($_POST['filter_type']??'');
            $sub =intval($_POST['filter_sub']??0);
            $kw  =trim($_POST['keyword']??'');
            $where=['1=1']; $params=[];
            if ($type!=='' && $type!=='all'){ $where[]='d.Type=:t'; $params[':t']=$type; }
            if ($sub>0){ $where[]='d.workpiece_sub_type_id=:s'; $params[':s']=$sub; }
            if ($kw!==''){ $where[]='(d.D_Setting_Id LIKE :kw OR d.Spec_No LIKE :kw OR d.Drawing_No LIKE :kw)'; $params[':kw']="%$kw%"; }
            $sql="SELECT d.d_id, d.D_Setting_Id, d.Spec_No, d.Revision, d.Remark, d.Customer_Id, c.customer AS client_name
                  FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                  WHERE ".implode(' AND ',$where)."
                  ORDER BY d.D_Setting_Id LIMIT 500";
            $st=$pdo->prepare($sql); $st->execute($params);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'data'=>$rows,'capped'=>count($rows)>=500]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 批次新增庫存（整批同一品項種類）──────────────
    if ($_POST['action'] === 'batch_save_stock') {
        try {
            $category_id = intval($_POST['category_id']??0)?:null;
            if (!$category_id) throw new Exception('請選擇品項種類');
            $items = json_decode($_POST['items']??'[]', true);
            if (!is_array($items) || empty($items)) throw new Exception('無待新增資料');

            $existCols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCol = fn($c) => in_array($c, $existCols);

            // 種類預設保存年限（含 system_parameters 覆蓋）
            $defEy=null;
            try {
                $cp=$pdo->prepare("SELECT default_expire_years FROM stock_item_categories WHERE category_id=?"); $cp->execute([$category_id]);
                $cpr=$cp->fetch(PDO::FETCH_ASSOC); if($cpr && $cpr['default_expire_years']) $defEy=(int)$cpr['default_expire_years'];
                $sp=$pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='STOCK_EXPIRE' AND param_key=?"); $sp->execute(["category_{$category_id}"]);
                $spr=$sp->fetch(PDO::FETCH_ASSOC); if($spr){ $spv=json_decode($spr['param_value'],true); if(!empty($spv['years'])) $defEy=(int)$spv['years']; }
            } catch(Exception $e2){}

            $pdo->beginTransaction();
            $created=0;
            foreach ($items as $it) {
                $d_id = trim($it['d_id']??''); if ($d_id==='') continue;
                $d_setting_id = intval($it['d_setting_id']??0)?:null;
                $location_id  = intval($it['location_id']??0)?:null; if (!$location_id) throw new Exception('料號「'.$d_id.'」尚未選儲位');
                $qty   = max(0,(float)($it['qty']??0));
                $unit_id = intval($it['unit_id']??0)?:null;
                $sd    = ($it['stock_date']??'')?:null;
                $cname = trim($it['client_name']??'')?:null;
                $cid   = trim($it['client_id']??'')?:null;
                $keeperId=intval($it['keeper_id']??0)?:null;
                $keeperName=trim($it['keeper_name']??'')?:null;
                $vendorId=trim($it['vendor_id']??'')?:null;
                $vendorName=trim($it['vendor_name']??'')?:null;

                // 補 d_setting_id
                if (!$d_setting_id && $d_id!=='') {
                    $stD=$pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? LIMIT 1"); $stD->execute([$d_id]); $d_setting_id=(int)$stD->fetchColumn()?:null;
                }
                // 儲位代碼快照
                $loc_str=null;
                if ($location_id) { $lr=$pdo->prepare("SELECT location_code FROM stock_locations WHERE location_id=?"); $lr->execute([$location_id]); $loc_str=$lr->fetchColumn()?:null; }

                $allFields=[
                    'd_id'=>$d_id,'d_setting_id'=>$d_setting_id,'item_type'=>$category_id,
                    'location_id'=>$location_id,'storage_location'=>$loc_str,'qty'=>$qty,'unit_id'=>$unit_id,
                    'stock_date'=>$sd,'expire_years'=>$defEy,
                    'client_name'=>$cname,'client_id'=>$cid,
                    'keeper_id'=>$keeperId,'keeper_name'=>$keeperName,'vendor_id'=>$vendorId,'vendor_name'=>$vendorName,
                ];
                $fields = array_filter($allFields, fn($k)=>$hasCol($k), ARRAY_FILTER_USE_KEY);
                $cols=implode(',',array_map(fn($k)=>"`$k`",array_keys($fields)));
                $phs =implode(',',array_map(fn($k)=>":$k",array_keys($fields)));
                $stmt=$pdo->prepare("INSERT INTO stock_items ($cols,Created_By,Modified_By) VALUES ($phs,:cby,:cby2)");
                $stmt->execute(array_merge($fields,[':cby'=>$userId,':cby2'=>$userId]));
                $newId=$pdo->lastInsertId();
                if ($qty>0) {
                    try { $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_to,location_to_id,txn_date,remark,Created_By) VALUES (?,?,'in',?,0,?,?,?,CURDATE(),'批次新增入庫',?)")->execute([$newId,$d_id,$qty,$qty,$loc_str,$location_id,$userId]); } catch(Exception $e2){}
                }
                $created++;
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'created'=>$created]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 搜尋BOM ───────────────────────────────────
    if ($_POST['action'] === 'search_bom') {
        try {
            $t=trim($_POST['term']??''); $did=trim($_POST['d_id']??'');
            if (!$t && !$did){ echo json_encode(['success'=>true,'data'=>[]]); exit; }
            $costSql = "(SELECT SUM(sn_avg.avg_p) * b.sqty FROM (SELECT AVG(COALESCE(NULLIF(modified_unit_price, 0), price)) AS avg_p FROM bom_ing_transfer_log WHERE bom = b.bom AND (price > 0 OR modified_unit_price > 0) AND paid_qty > 0 GROUP BY bom_sn) sn_avg)";
            // 若有料號，優先以料號搜BOM
            if ($did) {
                $st=$pdo->prepare("SELECT b.bom,b.d_id,b.Client_Name,b.sqty,b.o_order_id,COALESCE($costSql,0) AS total_cost FROM bom b WHERE b.d_id LIKE :d ORDER BY b.Created_At DESC LIMIT 15");
                $st->execute([':d'=>"%$did%"]);
            } else {
                $st=$pdo->prepare("SELECT b.bom,b.d_id,b.Client_Name,b.sqty,b.o_order_id,COALESCE($costSql,0) AS total_cost FROM bom b WHERE b.bom LIKE :t ORDER BY b.Created_At DESC LIMIT 15");
                $st->execute([':t'=>"%$t%"]);
            }
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);
            // 補充對應訂單
            foreach ($rows as &$r) {
                $r['order_no']=''; $r['order_id']='';
                if (!empty($r['o_order_id'])) {
                    try {
                        $os=$pdo->prepare("SELECT Order_id,Order_oo,unit_price FROM order_track WHERE Order_oo=? LIMIT 1");
                        $os->execute([$r['o_order_id']]); $or=$os->fetch(PDO::FETCH_ASSOC);
                        if ($or){ $r['order_no']=$or['Order_oo']; $r['order_id']=$or['Order_id']; $r['order_price']=$or['unit_price']; }
                    } catch(Exception $e2){}
                }
            }
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 搜尋訂單（支援依料號篩選）──────────────────
    if ($_POST['action'] === 'search_order') {
        try {
            $t   = trim($_POST['term'] ?? '');
            $did = trim($_POST['d_id'] ?? ''); // 料號字串，有值時限制只查此料號訂單
            $params = [];
            if ($did) {
                if ($t) {
                    $sql = "SELECT Order_id,Order_oo,d_id,Client_name,unit_price,Order_date,Qty FROM order_track WHERE d_id=:did AND (Order_oo LIKE :t OR Client_name LIKE :t) AND (Order_status IS NULL OR Order_status!=9) ORDER BY Order_date DESC LIMIT 20";
                    $params = [':did'=>$did, ':t'=>"%$t%"];
                } else {
                    $sql = "SELECT Order_id,Order_oo,d_id,Client_name,unit_price,Order_date,Qty FROM order_track WHERE d_id=:did AND (Order_status IS NULL OR Order_status!=9) ORDER BY Order_date DESC LIMIT 20";
                    $params = [':did'=>$did];
                }
            } elseif ($t) {
                $sql = "SELECT Order_id,Order_oo,d_id,Client_name,unit_price,Order_date,Qty FROM order_track WHERE (Order_oo LIKE :t OR d_id LIKE :t OR Client_name LIKE :t) AND (Order_status IS NULL OR Order_status!=9) ORDER BY Order_date DESC LIMIT 15";
                $params = [':t'=>"%$t%"];
            } else {
                echo json_encode(['success'=>true,'data'=>[]]);
                exit;
            }
            $st=$pdo->prepare($sql); $st->execute($params);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 主資料：儲位列表 ──────────────────────────
    if ($_POST['action'] === 'get_locations') {
        try {
            $catId = intval($_POST['category_id'] ?? 0);
            $page  = intval($_POST['page'] ?? 0);
            $ps    = intval($_POST['page_size'] ?? 10);
            $off   = ($page - 1) * $ps;
            $areaFilter = $_POST['area_id'] ?? 'all';

            if ($catId > 0 && $page === 0) {
                // 自動建立關聯表（若不存在）
                $pdo->query("CREATE TABLE IF NOT EXISTS stock_category_locations (
                    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    category_id INT NOT NULL,
                    location_id INT NOT NULL,
                    Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_cat_loc (category_id, location_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                // 查詢此種類允許的儲位；若沒有設定則回傳全部
                $hasMappings = (int)$pdo->prepare("SELECT COUNT(*) FROM stock_category_locations WHERE category_id=?")->execute([$catId]) && (int)$pdo->query("SELECT COUNT(*) FROM stock_category_locations WHERE category_id=$catId")->fetchColumn();
                $hasMappings = (int)$pdo->query("SELECT COUNT(*) FROM stock_category_locations WHERE category_id=$catId")->fetchColumn();
                if ($hasMappings > 0) {
                    $rows=$pdo->prepare("SELECT l.*, sa.area_name FROM stock_locations l JOIN stock_category_locations cl ON cl.location_id=l.location_id LEFT JOIN stock_areas sa ON sa.area_id = l.area WHERE cl.category_id=? AND l.is_active=1 ORDER BY sa.sort_order,l.sort_order,l.location_code");
                    $rows->execute([$catId]); $rows=$rows->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    // 未設定限制，回傳全部，並標記
                    $rows=$pdo->query("SELECT l.*, sa.area_name FROM stock_locations l LEFT JOIN stock_areas sa ON sa.area_id = l.area WHERE l.is_active=1 ORDER BY sa.sort_order,l.sort_order,l.location_code")->fetchAll(PDO::FETCH_ASSOC);
                }
                echo json_encode(['success'=>true,'data'=>$rows,'filtered'=>true, 'total'=>count($rows)]);
            } else {
                $where = ["l.is_active=1"];
                $params = [];
                if ($areaFilter !== 'all') {
                    if ($areaFilter === 'none') {
                        $where[] = "(l.area IS NULL OR l.area = '' OR l.area = 0)";
                    } else {
                        $where[] = "l.area = :area";
                        $params[':area'] = intval($areaFilter);
                    }
                }
                // 模糊搜尋（儲位代碼 / 說明）
                $locSearch = trim($_POST['search'] ?? '');
                if ($locSearch !== '') {
                    $where[] = "(l.location_code LIKE :s OR l.location_name LIKE :s)";
                    $params[':s'] = "%$locSearch%";
                }
                $whereSql = implode(" AND ", $where);

                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_locations l WHERE $whereSql");
                $cntStmt->execute($params);
                $cnt = (int)$cntStmt->fetchColumn();

                $sql = "SELECT l.*, sa.area_name FROM stock_locations l LEFT JOIN stock_areas sa ON sa.area_id = l.area WHERE $whereSql ORDER BY sa.sort_order,l.sort_order,l.location_code";
                if ($page > 0) {
                    $sql .= " LIMIT :lim OFFSET :off";
                }

                $st = $pdo->prepare($sql);
                foreach ($params as $k => $v) $st->bindValue($k, $v);
                if ($page > 0) {
                    $st->bindValue(':lim', $ps, PDO::PARAM_INT);
                    $st->bindValue(':off', $off, PDO::PARAM_INT);
                }
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);

                $hasNone = (int)$pdo->query("SELECT COUNT(*) FROM stock_locations WHERE is_active=1 AND (area IS NULL OR area='' OR area=0)")->fetchColumn() > 0;
                echo json_encode(['success'=>true,'data'=>$rows,'filtered'=>false, 'total'=>$cnt, 'page'=>$page, 'page_size'=>$ps, 'has_none'=>$hasNone]);
            }
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得品項種類允許的儲位 ───────────────────
    if ($_POST['action'] === 'get_cat_locations') {
        try {
            $catId = intval($_POST['category_id']??0);
            $pdo->query("CREATE TABLE IF NOT EXISTS stock_category_locations (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, category_id INT NOT NULL, location_id INT NOT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_cat_loc (category_id, location_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $st=$pdo->prepare("SELECT location_id FROM stock_category_locations WHERE category_id=?");
            $st->execute([$catId]);
            $locIds=array_column($st->fetchAll(PDO::FETCH_ASSOC),'location_id');
            echo json_encode(['success'=>true,'location_ids'=>$locIds]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 儲存品項種類允許的儲位 ───────────────────
    if ($_POST['action'] === 'save_cat_locations') {
        try {
            $catId = intval($_POST['category_id']??0); if(!$catId) throw new Exception('未指定種類');
            $locIds = json_decode($_POST['location_ids']??'[]',true);
            $locIds = array_filter(array_map('intval',$locIds));
            $pdo->query("CREATE TABLE IF NOT EXISTS stock_category_locations (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, category_id INT NOT NULL, location_id INT NOT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_cat_loc (category_id, location_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM stock_category_locations WHERE category_id=?")->execute([$catId]);
            if (!empty($locIds)) {
                $ins=$pdo->prepare("INSERT IGNORE INTO stock_category_locations (category_id,location_id) VALUES (?,?)");
                foreach ($locIds as $lid) $ins->execute([$catId,$lid]);
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'count'=>count($locIds)]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'save_location') {
        try {
            $lid  = intval($_POST['location_id']??0);
            $code = trim($_POST['location_code']??''); if(!$code) throw new Exception('儲位代碼必填');
            $area = trim($_POST['area']??'');           if(!$area) throw new Exception('廠區為必填，請先建立廠區後再新增儲位');
            $name = trim($_POST['location_name']??'')?:null;
            $sort = intval($_POST['sort_order']??0);

            if ($lid>0) {
                // 編輯：檢查廠區+代碼是否與其他啟用中的儲位重複
                $dup=$pdo->prepare("SELECT location_id FROM stock_locations WHERE location_code=? AND area=? AND is_active=1 AND location_id!=?");
                $dup->execute([$code,$area,$lid]); $dupRow=$dup->fetch();
                if ($dupRow) throw new Exception('廠區「'.$area.'」已有儲位代碼「'.$code.'」，不允許重複');
                
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE stock_locations SET location_code=?,location_name=?,area=?,sort_order=?,Modified_By=?,Modified_At=NOW() WHERE location_id=?")->execute([$code,$name,$area,$sort,$userId,$lid]);
                // 自動同步更新 stock_items 內的 storage_location 快照，確保當前庫存位置資訊與基礎資料一致
                $pdo->prepare("UPDATE stock_items SET storage_location=? WHERE location_id=? AND is_active=1")->execute([$code, $lid]);
                $pdo->commit();
                echo json_encode(['success'=>true,'id'=>$lid]);
            } else {
                // 新增：先查是否有已軟刪除的同代碼+廠區，若有則復活
                $revive=$pdo->prepare("SELECT location_id FROM stock_locations WHERE location_code=? AND area=? AND is_active=0 LIMIT 1");
                $revive->execute([$code,$area]); $reviveRow=$revive->fetch();
                if ($reviveRow) {
                    // 復活同廠區同代碼的軟刪除記錄
                    $pdo->prepare("UPDATE stock_locations SET is_active=1,location_name=?,sort_order=?,Modified_By=?,Modified_At=NOW() WHERE location_id=?")->execute([$name,$sort,$userId,$reviveRow['location_id']]);
                    echo json_encode(['success'=>true,'id'=>$reviveRow['location_id'],'revived'=>true]);
                } else {
                    // 確認啟用中沒有廠區+代碼完全相同的
                    $dup=$pdo->prepare("SELECT location_id FROM stock_locations WHERE location_code=? AND area=? AND is_active=1");
                    $dup->execute([$code,$area]); $dupRow=$dup->fetch();
                    if ($dupRow) throw new Exception('廠區「'.$area.'」已有儲位代碼「'.$code.'」，不允許重複');
                    $pdo->prepare("INSERT INTO stock_locations (location_code,location_name,area,sort_order,Created_By) VALUES (?,?,?,?,?)")->execute([$code,$name,$area,$sort,$userId]);
                    echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
                }
            }
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'delete_location') {
        try {
            $lid=intval($_POST['location_id']??0);
            // 檢查是否有庫存品項目前存放在此儲位
            $using=$pdo->prepare("SELECT si.d_id, si.qty, clp.customer AS client_name FROM stock_items si LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id WHERE si.location_id=? AND si.is_active=1 AND si.qty>0 LIMIT 20");
            $using->execute([$lid]); $usingRows=$using->fetchAll(PDO::FETCH_ASSOC);
            if (count($usingRows)>0) {
                $list=array_map(function($r){ return $r['d_id'].'('.round($r['qty'],2).($r['client_name']?' '.$r['client_name']:'').')'; },$usingRows);
                throw new Exception('此儲位尚有庫存品項使用，請先移位後再刪除：'.implode('、',$list));
            }
            // 也檢查 stock_items 是否曾設定此 location_id（即使 qty=0 仍存在的記錄）
            $cnt=(int)$pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE location_id=? AND is_active=1")->execute([$lid]) && (int)$pdo->query("SELECT COUNT(*) FROM stock_items WHERE location_id=$lid AND is_active=1")->fetchColumn();
            $cnt=(int)$pdo->query("SELECT COUNT(*) FROM stock_items WHERE location_id=$lid AND is_active=1")->fetchColumn();
            if ($cnt>0) {
                $rows2=$pdo->query("SELECT d_id FROM stock_items WHERE location_id=$lid AND is_active=1 LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
                throw new Exception('此儲位仍有'.count($rows2).'筆庫存記錄（含零庫存），請先修改或移除這些記錄：'.implode('、',$rows2));
            }
            $pdo->prepare("UPDATE stock_locations SET is_active=0 WHERE location_id=?")->execute([$lid]);
            // 同步刪除 stock_category_locations 中所有綁定此儲位的記錄
            try {
                $hasTbl=$pdo->query("SHOW TABLES LIKE 'stock_category_locations'")->fetchColumn();
                if ($hasTbl) {
                    $delCL=$pdo->prepare("DELETE FROM stock_category_locations WHERE location_id=?");
                    $delCL->execute([$lid]);
                    $cleaned=$delCL->rowCount();
                }
            } catch(Exception $e2){}
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'move_location') {
        try {
            $lidFrom = intval($_POST['location_id_from'] ?? 0);
            $lidTo   = intval($_POST['location_id_to']   ?? 0);
            if (!$lidFrom || !$lidTo) throw new Exception('請指定來源和目標儲位');
            if ($lidFrom === $lidTo) throw new Exception('來源和目標儲位不能相同');

            $locFrom = $pdo->prepare("SELECT location_id, location_code FROM stock_locations WHERE location_id=? AND is_active=1");
            $locFrom->execute([$lidFrom]);
            $lfRow = $locFrom->fetch(PDO::FETCH_ASSOC);
            if (!$lfRow) throw new Exception('來源儲位不存在或已停用');

            $locTo = $pdo->prepare("SELECT location_id, location_code FROM stock_locations WHERE location_id=? AND is_active=1");
            $locTo->execute([$lidTo]);
            $ltRow = $locTo->fetch(PDO::FETCH_ASSOC);
            if (!$ltRow) throw new Exception('目標儲位不存在或已停用');

            $codeFrom = $lfRow['location_code'];
            $codeTo   = $ltRow['location_code'];

            // 取得來源儲位所有有效品項
            $items = $pdo->prepare("SELECT * FROM stock_items WHERE location_id=? AND is_active=1");
            $items->execute([$lidFrom]);
            $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);

            if (count($itemRows) === 0) throw new Exception('來源儲位沒有任何有效庫存記錄可移動');

            $movedCount  = 0;
            $mergedCount = 0;
            $today = date('Y-m-d');
            $uid = $_SESSION['id'] ?? 0;

            $pdo->beginTransaction();
            foreach ($itemRows as $item) {
                $sid  = $item['stock_item_id'];
                $did  = $item['d_id'];
                $qty  = intval($item['qty']);

                // 來源料號備註/版次（同料號判定：料號+備註+版次 全等；客戶為料號綁定屬性，同料號必同客戶，不再比對）
                $srcRmk=''; $srcRev='';
                if (!empty($item['d_setting_id'])) {
                    try { $dq=$pdo->prepare("SELECT Remark,Revision FROM d_setting WHERE d_id=?"); $dq->execute([$item['d_setting_id']]); $dr=$dq->fetch(PDO::FETCH_ASSOC); if($dr){ $srcRmk=$dr['Remark']??''; $srcRev=$dr['Revision']??''; } } catch(Exception $e2){}
                }
                // 檢查目標儲位是否已有相同料號（料號+備註+版次 全等）
                $dup = $pdo->prepare("SELECT si.stock_item_id, si.qty FROM stock_items si LEFT JOIN d_setting ds ON ds.d_id=si.d_setting_id WHERE si.location_id=? AND si.d_id=? AND si.is_active=1 AND COALESCE(ds.Remark,'')=? AND COALESCE(ds.Revision,'')=?");
                $dup->execute([$lidTo, $did, $srcRmk, $srcRev]);
                $dupRow = $dup->fetch(PDO::FETCH_ASSOC);

                if ($dupRow) {
                    // 合併：將數量加到目標，並停用來源
                    $targetId  = $dupRow['stock_item_id'];
                    $qtyBefore = intval($dupRow['qty']);
                    $qtyAfter  = $qtyBefore + $qty;

                    $pdo->prepare("UPDATE stock_items SET qty=?, Modified_By=?, Modified_At=NOW() WHERE stock_item_id=?")
                        ->execute([$qtyAfter, $uid, $targetId]);
                    $pdo->prepare("UPDATE stock_items SET is_active=0, qty=0, Modified_By=?, Modified_At=NOW() WHERE stock_item_id=?")
                        ->execute([$uid, $sid]);

                    // 記錄交易
                    $pdo->prepare("INSERT INTO stock_transactions (stock_item_id, d_id, txn_type, txn_qty, qty_before, qty_after, location_from, location_to, location_from_id, location_to_id, txn_date, remark, Created_By)
                                   VALUES (?, ?, 'move', ?, ?, ?, ?, ?, ?, ?, ?, '整批儲位移動（合併）', ?)")
                        ->execute([$targetId, $did, $qty, $qtyBefore, $qtyAfter, $codeFrom, $codeTo, $lidFrom, $lidTo, $today, $uid]);

                    $mergedCount++;
                } else {
                    // 直接移動：更新 location_id 和 storage_location
                    $qtyBefore = $qty;
                    $pdo->prepare("UPDATE stock_items SET location_id=?, storage_location=?, Modified_By=?, Modified_At=NOW() WHERE stock_item_id=?")
                        ->execute([$lidTo, $codeTo, $uid, $sid]);

                    // 記錄交易
                    $pdo->prepare("INSERT INTO stock_transactions (stock_item_id, d_id, txn_type, txn_qty, qty_before, qty_after, location_from, location_to, location_from_id, location_to_id, txn_date, remark, Created_By)
                                   VALUES (?, ?, 'move', 0, ?, ?, ?, ?, ?, ?, ?, '整批儲位移動', ?)")
                        ->execute([$sid, $did, $qtyBefore, $qtyBefore, $codeFrom, $codeTo, $lidFrom, $lidTo, $today, $uid]);

                    $movedCount++;
                }
            }
            $pdo->commit();

            echo json_encode(['success'=>true, 'moved'=>$movedCount, 'merged'=>$mergedCount,
                              'message'=>"完成：移動 {$movedCount} 筆，合併 {$mergedCount} 筆"]);
        } catch(Exception $e){
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 主資料：品項種類 ──────────────────────────
    if ($_POST['action'] === 'get_categories') {
        try {
            $page  = intval($_POST['page'] ?? 0);
            $ps    = intval($_POST['page_size'] ?? 10);
            $off   = ($page - 1) * $ps;

            $cnt = $pdo->query("SELECT COUNT(*) FROM stock_item_categories WHERE is_active=1")->fetchColumn();
            $sql = "SELECT * FROM stock_item_categories WHERE is_active=1 ORDER BY sort_order";
            if ($page > 0) {
                $sql .= " LIMIT $ps OFFSET $off";
            }
            $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            // 附加 system_parameters 中的覆蓋保存年限
            foreach ($rows as &$r) {
                try {
                    $sp=$pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='STOCK_EXPIRE' AND param_key=?");
                    $sp->execute(["category_{$r['category_id']}"]);
                    $spr=$sp->fetch(PDO::FETCH_ASSOC);
                    if ($spr){ $spv=json_decode($spr['param_value'],true); if (!empty($spv['years'])) $r['override_expire_years']=(int)$spv['years']; }
                } catch(Exception $e2){}
            }
            echo json_encode(['success'=>true,'data'=>$rows, 'total'=>(int)$cnt, 'page'=>$page, 'page_size'=>$ps]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'save_category') {
        try {
            $cid      = intval($_POST['category_id']??0);
            $name     = trim($_POST['category_name']??''); if(!$name) throw new Exception('種類名稱必填');
            $code     = trim($_POST['category_code']??'')?:null;
            $ey       = intval($_POST['default_expire_years']??0)?:null;
            $nbom     = intval($_POST['need_bom_bind']??0);
            $nord     = intval($_POST['need_order_bind']??0);
            $showDash = intval($_POST['show_in_dashboard']??1);
            $showSpec   = intval($_POST['show_spec']??0);
            $showLabel  = intval($_POST['show_label']??0);
            $showKeeper = intval($_POST['show_keeper']??0);
            $showVendor = intval($_POST['show_vendor']??0);
            $color    = trim($_POST['color']??'')?:null;
            $sort     = intval($_POST['sort_order']??0);

            // 動態組合欄位（兼容尚未建立新欄位的環境）
            $catCols = $pdo->query("SHOW COLUMNS FROM stock_item_categories")->fetchAll(PDO::FETCH_COLUMN);
            $extraCols = []; $extraVals = [];
            if (in_array('show_in_dashboard',$catCols)) { $extraCols[]='show_in_dashboard'; $extraVals[]=$showDash; }
            if (in_array('show_spec',$catCols))   { $extraCols[]='show_spec';   $extraVals[]=$showSpec; }
            if (in_array('show_label',$catCols))  { $extraCols[]='show_label';  $extraVals[]=$showLabel; }
            if (in_array('show_keeper',$catCols)) { $extraCols[]='show_keeper'; $extraVals[]=$showKeeper; }
            if (in_array('show_vendor',$catCols)) { $extraCols[]='show_vendor'; $extraVals[]=$showVendor; }

            if ($cid>0) {
                $setExtra = '';
                foreach ($extraCols as $ec) { $setExtra .= ",$ec=?"; }
                $params = array_merge([$name,$code,$ey,$nbom,$nord,$color,$sort], $extraVals, [$userId,$cid]);
                $pdo->prepare("UPDATE stock_item_categories SET category_name=?,category_code=?,default_expire_years=?,need_bom_bind=?,need_order_bind=?,color=?,sort_order=?{$setExtra},Modified_By=?,Modified_At=NOW() WHERE category_id=?")->execute($params);
            } else {
                $colExtra = ''; $phExtra = '';
                foreach ($extraCols as $ec) { $colExtra .= ",$ec"; $phExtra .= ',?'; }
                $params = array_merge([$name,$code,$ey,$nbom,$nord,$color,$sort], $extraVals, [$userId]);
                $pdo->prepare("INSERT INTO stock_item_categories (category_name,category_code,default_expire_years,need_bom_bind,need_order_bind,color,sort_order{$colExtra},Created_By) VALUES (?,?,?,?,?,?,?{$phExtra},?)")->execute($params);
                $cid=$pdo->lastInsertId();
            }
            if ($ey) {
                $spv=json_encode(['years'=>$ey,'note'=>"{$name}預設保存年限"]);
                try { $pdo->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at) VALUES ('STOCK_EXPIRE',:key,:val,:desc,:uid,NOW()) ON DUPLICATE KEY UPDATE param_value=:val2,updated_by=:uid2,updated_at=NOW()")->execute([':key'=>"category_{$cid}",':val'=>$spv,':desc'=>"{$name}保存年限設定",':uid'=>$userId,':val2'=>$spv,':uid2'=>$userId]); } catch(Exception $e2){}
            }
            echo json_encode(['success'=>true,'id'=>$cid]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'delete_category') {
        try {
            $cid=intval($_POST['category_id']??0);
            $using=$pdo->prepare("SELECT d_id FROM stock_items WHERE item_type=? AND is_active=1 LIMIT 20");
            $using->execute([$cid]); $usingRows=$using->fetchAll(PDO::FETCH_COLUMN);
            if (count($usingRows)>0) {
                throw new Exception('此品項種類尚有'.count($usingRows).'筆庫存使用，請先修改這些品項的種類後再刪除：'.implode('、',array_slice($usingRows,0,10)).(count($usingRows)>10?'…':''));
            }
            $pdo->prepare("UPDATE stock_item_categories SET is_active=0 WHERE category_id=?")->execute([$cid]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 主資料：單位 ──────────────────────────────
    if ($_POST['action'] === 'get_units') {
        try {
            $page  = intval($_POST['page'] ?? 0);
            $ps    = intval($_POST['page_size'] ?? 10);
            $off   = ($page - 1) * $ps;

            $cnt = $pdo->query("SELECT COUNT(*) FROM stock_units WHERE is_active=1")->fetchColumn();
            $sql = "SELECT u.*,b.unit_name AS base_unit_name FROM stock_units u LEFT JOIN stock_units b ON b.unit_id=u.base_unit_id WHERE u.is_active=1 ORDER BY u.sort_order";
            if ($page > 0) {
                $sql .= " LIMIT $ps OFFSET $off";
            }
            $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'data'=>$rows, 'total'=>(int)$cnt, 'page'=>$page, 'page_size'=>$ps]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'save_unit') {
        try {
            $uid      = intval($_POST['unit_id']??0);
            $name     = trim($_POST['unit_name']??''); if(!$name) throw new Exception('單位名稱必填');
            $sym      = trim($_POST['unit_symbol']??'')?:null;
            $type     = trim($_POST['unit_type']??'')?:null;
            $baseId   = intval($_POST['base_unit_id']??0)?:null;
            $factor   = ($_POST['convert_factor']??'')!==''?(float)$_POST['convert_factor']:null;
            $sort     = intval($_POST['sort_order']??0);
            $decPlaces= intval($_POST['decimal_places']??3); // 預設3位

            // 動態確認 decimal_places 欄位是否存在
            $unitCols=$pdo->query("SHOW COLUMNS FROM stock_units")->fetchAll(PDO::FETCH_COLUMN);
            $hasDecPlaces=in_array('decimal_places',$unitCols);
            if (!$hasDecPlaces) {
                try { $pdo->query("ALTER TABLE stock_units ADD COLUMN decimal_places TINYINT NOT NULL DEFAULT 3 COMMENT '小數點後幾位：0=整數,1~6=小數' AFTER sort_order"); $hasDecPlaces=true; } catch(Exception $e2){}
            }

            if ($uid>0) {
                if ($hasDecPlaces) $pdo->prepare("UPDATE stock_units SET unit_name=?,unit_symbol=?,unit_type=?,base_unit_id=?,convert_factor=?,sort_order=?,decimal_places=? WHERE unit_id=?")->execute([$name,$sym,$type,$baseId,$factor,$sort,$decPlaces,$uid]);
                else $pdo->prepare("UPDATE stock_units SET unit_name=?,unit_symbol=?,unit_type=?,base_unit_id=?,convert_factor=?,sort_order=? WHERE unit_id=?")->execute([$name,$sym,$type,$baseId,$factor,$sort,$uid]);
                echo json_encode(['success'=>true,'id'=>$uid]);
            } else {
                if ($hasDecPlaces) $pdo->prepare("INSERT INTO stock_units (unit_name,unit_symbol,unit_type,base_unit_id,convert_factor,sort_order,decimal_places,Created_By) VALUES (?,?,?,?,?,?,?,?)")->execute([$name,$sym,$type,$baseId,$factor,$sort,$decPlaces,$userId]);
                else $pdo->prepare("INSERT INTO stock_units (unit_name,unit_symbol,unit_type,base_unit_id,convert_factor,sort_order,Created_By) VALUES (?,?,?,?,?,?,?)")->execute([$name,$sym,$type,$baseId,$factor,$sort,$userId]);
                echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
            }
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'delete_unit') {
        try {
            $uid=intval($_POST['unit_id']??0);
            // 檢查是否有庫存使用此單位
            $usingItems=$pdo->query("SELECT COUNT(*) FROM stock_items WHERE unit_id=$uid AND is_active=1")->fetchColumn();
            if ($usingItems>0) {
                $dids=$pdo->query("SELECT d_id FROM stock_items WHERE unit_id=$uid AND is_active=1 LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
                throw new Exception('此計量單位有'.$usingItems.'筆庫存使用，請先修改這些品項的單位後再刪除：'.implode('、',$dids).(count($dids)<$usingItems?'…':''));
            }
            // 檢查是否有品項單位設定使用
            $usingItemUnits=$pdo->query("SELECT COUNT(*) FROM stock_item_units WHERE unit_id=$uid")->fetchColumn();
            if ($usingItemUnits>0) {
                throw new Exception('此計量單位已設定為'.$usingItemUnits.'個料號的可用單位，請先至各料號移除此單位設定後再刪除');
            }
            $pdo->prepare("UPDATE stock_units SET is_active=0 WHERE unit_id=?")->execute([$uid]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 品項可用單位 ──────────────────────────────
    if ($_POST['action'] === 'get_item_units') {
        try {
            $dsid=intval($_POST['d_setting_id']??0);
            $rows=$pdo->prepare("SELECT iu.*,u.unit_name,u.unit_symbol,u.convert_factor FROM stock_item_units iu JOIN stock_units u ON u.unit_id=iu.unit_id WHERE iu.d_setting_id=? ORDER BY iu.is_default DESC,u.sort_order");
            $rows->execute([$dsid]); echo json_encode(['success'=>true,'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'save_item_units') {
        try {
            $dsid  = intval($_POST['d_setting_id']??0); if(!$dsid) throw new Exception('未指定料號');
            $units = json_decode($_POST['units']??'[]',true);
            $pdo->prepare("DELETE FROM stock_item_units WHERE d_setting_id=?")->execute([$dsid]);
            $st=$pdo->prepare("INSERT INTO stock_item_units (d_setting_id,unit_id,is_default,convert_to_default,Created_By) VALUES (?,?,?,?,?)");
            foreach ($units as $u) { $st->execute([$dsid,(int)$u['unit_id'],(int)($u['is_default']??0),$u['convert']??null,$userId]); }
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 安全庫存設定 ──────────────────────────────
    if ($_POST['action'] === 'get_safety_stock') {
        try {
            $page  = intval($_POST['page'] ?? 0);
            $ps    = intval($_POST['page_size'] ?? 10);
            $off   = ($page - 1) * $ps;

            $cnt = $pdo->query("SELECT COUNT(*) FROM stock_safety_stock")->fetchColumn();
            $sql = "SELECT ss.*,u.unit_name FROM stock_safety_stock ss LEFT JOIN stock_units u ON u.unit_id=ss.unit_id ORDER BY ss.d_id";
            if ($page > 0) {
                $sql .= " LIMIT $ps OFFSET $off";
            }
            $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'data'=>$rows, 'total'=>(int)$cnt, 'page'=>$page, 'page_size'=>$ps]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'save_safety_stock') {
        try {
            $did   = trim($_POST['d_id']??''); if(!$did) throw new Exception('料號必填');
            $dsid  = intval($_POST['d_setting_id']??0)?:null;
            $qty   = (float)($_POST['safety_qty']??0);
            $uid   = intval($_POST['unit_id']??0)?:null;
            $rem   = trim($_POST['remark']??'')?:null;
            $pdo->prepare("INSERT INTO stock_safety_stock (d_id,d_setting_id,safety_qty,unit_id,remark,Created_By) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE safety_qty=?,unit_id=?,remark=?,Modified_By=?,Modified_At=NOW()")->execute([$did,$dsid,$qty,$uid,$rem,$userId,$qty,$uid,$rem,$userId]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'delete_safety_stock') {
        try {
            $pdo->prepare("DELETE FROM stock_safety_stock WHERE id=?")->execute([intval($_POST['id']??0)]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 客戶下拉（僅庫存內有的） ──────────────────
    if ($_POST['action'] === 'get_clients_in_stock') {
        try {
            $rows=$pdo->query("SELECT DISTINCT dsp.Customer_Id AS client_id, clp.customer AS client_name FROM stock_items si LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id WHERE si.is_active=1 AND dsp.Customer_Id IS NOT NULL AND clp.customer IS NOT NULL ORDER BY clp.customer")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得部門與人員 ─────────────────────────────
    if ($_POST['action'] === 'get_dept_users') {
        try {
            $deptId=intval($_POST['dept_id']??0);
            $depts=$pdo->query("SELECT id,name FROM department WHERE parent_id IS NOT NULL ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);
            $users=[];
            if ($deptId) {
                $us=$pdo->prepare("SELECT DISTINCT u.id,u.user_cname FROM user u JOIN user_department_position_map m ON m.user_id=u.id WHERE m.department_id=? AND u.state=1 ORDER BY u.user_cname");
                $us->execute([$deptId]); $users=$us->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success'=>true,'depts'=>$depts,'users'=>$users]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 盤點 ──────────────────────────────────────
    if ($_POST['action'] === 'create_count_session') {
        try {
            $name=$_POST['session_name']??''; if(!$name) throw new Exception('名稱必填');
            // 支援傳入指定的 stock_item_ids（隨機抽盤用）
            $itemIds = json_decode($_POST['item_ids']??'[]',true);
            $pdo->prepare("INSERT INTO stock_count_sessions (session_name,count_date,status,remark,Created_By) VALUES (?,?,'in_progress',?,?)")->execute([$name,$_POST['count_date']??date('Y-m-d'),$_POST['remark']??null,$userId]);
            $sid=$pdo->lastInsertId();
            if (!empty($itemIds)) {
                // 只加入指定項目
                $ins=$pdo->prepare("INSERT INTO stock_count_details (session_id,stock_item_id,d_id,system_qty) VALUES (?,?,?,?)");
                $st=$pdo->prepare("SELECT stock_item_id,d_id,qty FROM stock_items WHERE stock_item_id=? AND is_active=1");
                foreach ($itemIds as $iid) {
                    $st->execute([(int)$iid]); $row=$st->fetch(PDO::FETCH_ASSOC);
                    if ($row) $ins->execute([$sid,$row['stock_item_id'],$row['d_id'],$row['qty']]);
                }
                $count=count($itemIds);
            } else {
                $items=$pdo->query("SELECT stock_item_id,d_id,qty FROM stock_items WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);
                $ins=$pdo->prepare("INSERT INTO stock_count_details (session_id,stock_item_id,d_id,system_qty) VALUES (?,?,?,?)");
                foreach ($items as $i) $ins->execute([$sid,$i['stock_item_id'],$i['d_id'],$i['qty']]);
                $count=count($items);
            }
            echo json_encode(['success'=>true,'session_id'=>$sid,'item_count'=>$count]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 廠區 CRUD ────────────────────────────────
    if ($_POST['action'] === 'get_areas') {
        try {
            $hasTbl=$pdo->query("SHOW TABLES LIKE 'stock_areas'")->fetchColumn();
            if (!$hasTbl) {
                $pdo->query("CREATE TABLE IF NOT EXISTS stock_areas (area_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, area_name VARCHAR(50) NOT NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, Created_By INT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            $rows=$pdo->query("SELECT * FROM stock_areas WHERE is_active=1 ORDER BY sort_order,area_name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'save_area') {
        try {
            $aid=intval($_POST['area_id']??0);
            $name=trim($_POST['area_name']??''); if(!$name) throw new Exception('廠區名稱必填');
            $sort=intval($_POST['sort_order']??0);
            $hasTbl=$pdo->query("SHOW TABLES LIKE 'stock_areas'")->fetchColumn();
            if (!$hasTbl) $pdo->query("CREATE TABLE IF NOT EXISTS stock_areas (area_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, area_name VARCHAR(50) NOT NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, Created_By INT NULL, Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if ($aid>0) { $pdo->prepare("UPDATE stock_areas SET area_name=?,sort_order=? WHERE area_id=?")->execute([$name,$sort,$aid]); echo json_encode(['success'=>true,'id'=>$aid]); }
            else { $pdo->prepare("INSERT INTO stock_areas (area_name,sort_order,Created_By) VALUES (?,?,?)")->execute([$name,$sort,$userId]); echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]); }
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'delete_area') {
        try {
            $aid=intval($_POST['area_id']??0);
            // 先取得廠區名稱
            $areaRow=$pdo->prepare("SELECT area_name FROM stock_areas WHERE area_id=?"); $areaRow->execute([$aid]); $areaRow=$areaRow->fetch(PDO::FETCH_ASSOC);
            if ($areaRow) {
                $areaName=$areaRow['area_name'];
                // 檢查此廠區是否有儲位
                $locsInArea=$pdo->prepare("SELECT location_code FROM stock_locations WHERE area=? AND is_active=1 LIMIT 15");
                $locsInArea->execute([$aid]); $locList=$locsInArea->fetchAll(PDO::FETCH_COLUMN);
                if (count($locList)>0) {
                    throw new Exception('廠區「'.$areaName.'」仍有'.count($locList).'個儲位（'.implode('、',$locList).'），請先刪除或移動這些儲位後再刪除廠區');
                }
            }
            $pdo->prepare("UPDATE stock_areas SET is_active=0 WHERE area_id=?")->execute([$aid]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 搜尋客戶（建立料號用）──────────────────────
    if ($_POST['action'] === 'search_customer') {
        try {
            $t=trim($_POST['term']??''); if(!$t){ echo json_encode(['success'=>true,'data'=>[]]); exit; }
            $st=$pdo->prepare("SELECT customer_id,customer FROM customer_list WHERE customer LIKE :t OR customer_id LIKE :t ORDER BY customer LIMIT 15");
            $st->execute([':t'=>"%$t%"]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 建立料號 ──────────────────────────────────
    if ($_POST['action'] === 'create_d_id') {
        try {
            $did      = trim($_POST['D_Setting_Id']??''); if(!$did) throw new Exception('料號必填');
            $specNo   = trim($_POST['Spec_No']??'')?:null;
            $type     = 'N'; // 固定為 N（一般），其他類型由業務從訂單系統建立
            $remark   = trim($_POST['Remark']??'')?:null;
            $clientId = trim($_POST['Customer_Id']??'')?:null;
            // 確認料號不重複
            $chk=$pdo->prepare("SELECT COUNT(*) FROM d_setting WHERE D_Setting_Id=?");
            $chk->execute([$did]); if($chk->fetchColumn()>0) throw new Exception('料號 '.$did.' 已存在');
            $pdo->prepare("INSERT INTO d_setting (D_Setting_Id,Spec_No,Type,Remark,Customer_Id,Created_By,Modified_By) VALUES (?,?,?,?,?,?,?)")->execute([$did,$specNo,$type,$remark,$clientId,$userId,$userId]);
            $newId=$pdo->lastInsertId();
            // 回傳客戶資訊
            $clientName=null;
            if($clientId){ try{ $cn=$pdo->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $cn->execute([$clientId]); $cnr=$cn->fetch(PDO::FETCH_ASSOC); if($cnr)$clientName=$cnr['customer']; }catch(Exception $e2){} }
            echo json_encode(['success'=>true,'d_id'=>$newId,'D_Setting_Id'=>$did,'client_name'=>$clientName,'client_id'=>$clientId]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;

    }


    if ($_POST['action'] === 'get_count_sessions') {
        try {
            $rows=$pdo->query("SELECT cs.*,u.user_cname AS creator_name,(SELECT COUNT(*) FROM stock_count_details WHERE session_id=cs.session_id) AS total_items,(SELECT COUNT(*) FROM stock_count_details WHERE session_id=cs.session_id AND counted_qty IS NOT NULL) AS counted_items FROM stock_count_sessions cs LEFT JOIN user u ON u.id=cs.Created_By ORDER BY cs.session_id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'get_count_details') {
        try {
            $sid=intval($_POST['session_id']??0);
            $sortBy=($_POST['sort_by']??'did');
            $locF   = trim($_POST['location_filter']??'');
            $cliF   = trim($_POST['client_filter']??'');
            $areaF  = trim($_POST['area_filter']??'');
            $didF   = trim($_POST['did_filter']??'');

            $cols=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCat=in_array('item_type',$cols);
            $hasUnit=in_array('unit_id',$cols);

            $where=['cd.session_id=:sid'];
            $p=[':sid'=>$sid];
            if ($locF!=='') { $where[]='(si.storage_location LIKE :loc OR l.location_code LIKE :loc2)'; $p[':loc']="%$locF%"; $p[':loc2']="%$locF%"; }
            if ($cliF!=='') { $where[]='clp.customer LIKE :cli'; $p[':cli']="%$cliF%"; }
            if ($didF!=='') { $where[]='cd.d_id LIKE :did'; $p[':did']="%$didF%"; }
            // 廠區篩選：需 join stock_areas 透過 location_id
            if ($areaF!=='') {
                try {
                    $hasTblAreas=$pdo->query("SHOW TABLES LIKE 'stock_areas'")->fetchColumn();
                    if ($hasTblAreas) {
                        $where[]="EXISTS(SELECT 1 FROM stock_locations sl2 JOIN stock_areas sa ON sa.area_id=sl2.area WHERE sl2.location_id=si.location_id AND sa.area_name=:area)";
                        $p[':area']=$areaF;
                    } else {
                        $where[]='si.storage_location LIKE :area'; $p[':area']="%$areaF%";
                    }
                } catch(Exception $ea){ $where[]='si.storage_location LIKE :area'; $p[':area']="%$areaF%"; }
            }
            $wSQL='WHERE '.implode(' AND ',$where);

            $order='cd.d_id ASC';
            if ($sortBy==='location') $order='COALESCE(l.location_code,si.storage_location) ASC, cd.d_id ASC';
            elseif ($sortBy==='client') $order='clp.customer ASC, cd.d_id ASC';

            $catJoin=$hasCat?"LEFT JOIN stock_item_categories c ON c.category_id=si.item_type":"";
            $unitJoin=$hasUnit?"LEFT JOIN stock_units u ON u.unit_id=si.unit_id":"";
            $catSel=$hasCat?"c.category_name,":"NULL AS category_name,";
            $unitSel=$hasUnit?"u.unit_name,u.decimal_places,":"NULL AS unit_name, 3 AS decimal_places,";

            // 客戶：即時查料號綁定客戶，不用 si.client_name 舊快照
            $sql="SELECT cd.*, si.storage_location, clp.customer AS client_name, si.location_id, $catSel $unitSel
                         l.location_code, sa.area_name AS loc_area_name
                  FROM stock_count_details cd
                  LEFT JOIN stock_items si ON si.stock_item_id=cd.stock_item_id
                  $catJoin $unitJoin
                  LEFT JOIN stock_locations l ON l.location_id=si.location_id
                  LEFT JOIN stock_areas sa ON sa.area_id = l.area
                  LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id
                  LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id
                  $wSQL ORDER BY $order";
            $st=$pdo->prepare($sql);
            $st->execute($p);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);

            // 回傳可用篩選選項（此批次中有的）
            $locs=[]; $clients=[]; $areas=[];
            foreach($rows as $rw){
                $lc=$rw['location_code']??($rw['storage_location']??'');
                if($lc && !in_array($lc,$locs)) $locs[]=$lc;
                $cn=$rw['client_name']??'';
                if($cn && !in_array($cn,$clients)) $clients[]=$cn;
                $ar=$rw['loc_area_name']??'';
                if($ar && !in_array($ar,$areas)) $areas[]=$ar;
            }
            sort($locs); sort($clients); sort($areas);

            echo json_encode(['success'=>true,'data'=>$rows,'filter_locs'=>$locs,'filter_clients'=>$clients,'filter_areas'=>$areas]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'save_count_detail') {
        try {
            $did=(int)($_POST['detail_id']??0);
            $qty=(float)($_POST['counted_qty']??0);
            $rem=trim($_POST['remark']??'')?:null;
            $st=$pdo->prepare("SELECT system_qty FROM stock_count_details WHERE detail_id=?");
            $st->execute([$did]); $r=$st->fetch(PDO::FETCH_ASSOC);
            $diff=round($qty-$r['system_qty'],4);
            $pdo->prepare("UPDATE stock_count_details SET counted_qty=?,diff_qty=?,remark=?,Counted_By=?,Counted_At=NOW() WHERE detail_id=?")->execute([$qty,$diff,$rem,$userId,$did]);
            echo json_encode(['success'=>true,'diff_qty'=>$diff]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if ($_POST['action'] === 'apply_count_adjustments') {
        try {
            $sid=intval($_POST['session_id']??0);
            $det=$pdo->prepare("SELECT * FROM stock_count_details WHERE session_id=? AND counted_qty IS NOT NULL AND is_adjusted=0");
            $det->execute([$sid]); $rows=$det->fetchAll(PDO::FETCH_ASSOC);
            $pdo->beginTransaction();
            $ui=$pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?");
            $it=$pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'count',?,?,?,CURDATE(),?,?)");
            $ud=$pdo->prepare("UPDATE stock_count_details SET is_adjusted=1 WHERE detail_id=?");
            foreach ($rows as $r) {
                $ui->execute([$r['counted_qty'],$userId,$r['stock_item_id']]);
                $it->execute([$r['stock_item_id'],$r['d_id'],$r['diff_qty'],$r['system_qty'],$r['counted_qty'],"盤點調整(session:{$sid})",$userId]);
                $ud->execute([$r['detail_id']]);
            }
            $pdo->prepare("UPDATE stock_count_sessions SET status='completed',Completed_By=?,Completed_At=NOW() WHERE session_id=?")->execute([$userId,$sid]);
            $pdo->commit();
            echo json_encode(['success'=>true,'adjusted_count'=>count($rows)]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 刪除異動記錄（還原庫存） ──────────────────
    if ($_POST['action'] === 'delete_transaction') {
        try {
            $txnId = intval($_POST['txn_id']??0); if(!$txnId) throw new Exception('未指定');
            // 權限檢查：需要 D 或 A 權限
            $chkPermDel=$pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock'");
            $chkPermDel->execute([$userId]); $permRowDel=$chkPermDel->fetch(PDO::FETCH_ASSOC);
            $pDel=$permRowDel['permission']??'';
            if (!$permRowDel || ($pDel!=='A' && strpos($pDel,'D')===false)) throw new Exception('刪除異動記錄需要 D 或 A 權限');
            $st=$pdo->prepare("SELECT * FROM stock_transactions WHERE txn_id=?");
            $st->execute([$txnId]); $txn=$st->fetch(PDO::FETCH_ASSOC);
            if(!$txn) throw new Exception('找不到記錄');

            // 權限檢查：count 類型需 A 或 CRUD 完整權限
            if ($txn['txn_type'] === 'count') {
                $chkPerm=$pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock'");
                $chkPerm->execute([$userId]); $permRow2=$chkPerm->fetch(PDO::FETCH_ASSOC);
                $p2=$permRow2['permission']??'';
                $isCRUD=(strpos($p2,'C')!==false&&strpos($p2,'R')!==false&&strpos($p2,'U')!==false&&strpos($p2,'D')!==false);
                if (!$permRow2 || ($p2!=='A' && !$isCRUD)) throw new Exception('刪除盤點記錄需要 A 或 CRUD 完整權限');
            }

            $pdo->beginTransaction();
            // 還原庫存：把異動qty反向
            $reversalQty = -1 * floatval($txn['txn_qty']);
            $cur=$pdo->prepare("SELECT qty FROM stock_items WHERE stock_item_id=?");
            $cur->execute([$txn['stock_item_id']]); $curRow=$cur->fetch(PDO::FETCH_ASSOC);
            if(!$curRow) throw new Exception('找不到庫存品項');
            $newQty = round(floatval($curRow['qty']) + $reversalQty, 4);
            if($newQty < 0) throw new Exception('還原後庫存將為負數('.$newQty.')，無法刪除');

            $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$newQty,$userId,$txn['stock_item_id']]);
            // 記錄還原操作
            $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'adjust',?,?,?,CURDATE(),'刪除異動記錄#".$txnId."還原',?)")->execute([$txn['stock_item_id'],$txn['d_id'],$reversalQty,$curRow['qty'],$newQty,$userId]);
            $pdo->prepare("DELETE FROM stock_transactions WHERE txn_id=?")->execute([$txnId]);
            $pdo->commit();
            echo json_encode(['success'=>true,'new_qty'=>$newQty]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 刪除盤點批次 ──────────────────────────────
    if ($_POST['action'] === 'delete_count_session') {
        try {
            $sid=intval($_POST['session_id']??0); if(!$sid) throw new Exception('未指定');
            $pdo->prepare("DELETE FROM stock_count_details WHERE session_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM stock_count_sessions WHERE session_id=?")->execute([$sid]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 隨機盤點抽樣 ──────────────────────────────
    if ($_POST['action'] === 'random_count_sample') {
        try {
            $catId    = intval($_POST['category_id']??0)?:null;
            $count    = max(1,min(200,intval($_POST['count']??10)));
            $mode     = $_POST['mode']??'all'; // all|recent_active|recent_inactive
            $days     = max(1,intval($_POST['days']??30));

            $cols=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCat=in_array('item_type',$cols);
            $hasSDate=in_array('stock_date',$cols);

            $where=['si.is_active=1'];
            $p=[];
            if ($hasCat && $catId) { $where[]='si.item_type=:cat'; $p[':cat']=$catId; }
            if ($hasSDate && $mode==='recent_active') {
                // 查詢 stock_transactions 近N天有異動
                $where[]='EXISTS(SELECT 1 FROM stock_transactions st WHERE st.stock_item_id=si.stock_item_id AND st.txn_date>=DATE_SUB(CURDATE(),INTERVAL :days DAY))';
                $p[':days']=$days;
            } elseif ($hasSDate && $mode==='recent_inactive') {
                $where[]='NOT EXISTS(SELECT 1 FROM stock_transactions st WHERE st.stock_item_id=si.stock_item_id AND st.txn_date>=DATE_SUB(CURDATE(),INTERVAL :days DAY))';
                $p[':days']=$days;
            }
            $wSQL='WHERE '.implode(' AND ',$where);
            $catJoin=$hasCat?"LEFT JOIN stock_item_categories c ON c.category_id=si.item_type":"";
            $locCols=$hasCat?"c.category_name,":"NULL AS category_name,";
            $sql="SELECT si.stock_item_id,si.d_id,si.qty,si.storage_location,$locCols clp.customer AS client_name FROM stock_items si $catJoin LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id $wSQL ORDER BY RAND() LIMIT :lim";
            $st=$pdo->prepare($sql);
            foreach($p as $k=>$v) $st->bindValue($k,$v);
            $st->bindValue(':lim',$count,PDO::PARAM_INT);
            $st->execute();
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 分析 ──────────────────────────────────────
    if ($_POST['action'] === 'get_analysis') {
        try {
            $cols=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCat=in_array('item_type',$cols);
            $hasSDate=in_array('stock_date',$cols);
            $period   = $_POST['period']   ?? 'month';
            $compareA = trim($_POST['period_a'] ?? '');
            $compareB = trim($_POST['period_b'] ?? '');

            // 計算期間起訖日期（不用 nested function，直接 inline）
            $dateFrom = date('Y-m-01');
            $dateTo   = date('Y-m-d');
            if ($period === 'month') {
                if ($compareA) { $y=substr($compareA,0,4); $m=substr($compareA,5,2); $dateFrom="$y-$m-01"; $dateTo=date('Y-m-t',strtotime($dateFrom)); }
                else { $dateFrom=date('Y-m-01'); $dateTo=date('Y-m-d'); }
            } elseif ($period === 'quarter') {
                if ($compareA && preg_match('/(\d{4})-Q(\d)/',$compareA,$ma)) {
                    $y=$ma[1]; $q=$ma[2]; $sm=($q-1)*3+1; $em=min($q*3,12);
                    $dateFrom=$y.'-'.str_pad($sm,2,'0',STR_PAD_LEFT).'-01';
                    $dateTo=date('Y-m-t',strtotime($y.'-'.str_pad($em,2,'0',STR_PAD_LEFT).'-01'));
                } else {
                    $q=ceil(date('n')/3); $sm=($q-1)*3+1; $em=min($q*3,12);
                    $dateFrom=date('Y').'-'.str_pad($sm,2,'0',STR_PAD_LEFT).'-01';
                    $dateTo=date('Y-m-t',strtotime(date('Y').'-'.str_pad($em,2,'0',STR_PAD_LEFT).'-01'));
                }
            } else { // year
                $y = $compareA ? substr($compareA,0,4) : date('Y');
                $dateFrom = "$y-01-01"; $dateTo = "$y-12-31";
            }

            // 期間B日期
            $dfB=$dateFrom; $dtB=$dateTo;
            if ($compareB) {
                if ($period === 'month' && strlen($compareB)>=7) { $yb=substr($compareB,0,4); $mb=substr($compareB,5,2); $dfB="$yb-$mb-01"; $dtB=date('Y-m-t',strtotime($dfB)); }
                elseif ($period === 'quarter' && preg_match('/(\d{4})-Q(\d)/',$compareB,$mb2)) { $yb=$mb2[1]; $qb=$mb2[2]; $smb=($qb-1)*3+1; $emb=min($qb*3,12); $dfB=$yb.'-'.str_pad($smb,2,'0',STR_PAD_LEFT).'-01'; $dtB=date('Y-m-t',strtotime($yb.'-'.str_pad($emb,2,'0',STR_PAD_LEFT).'-01')); }
                elseif ($period === 'year') { $yb=substr($compareB,0,4); $dfB="$yb-01-01"; $dtB="$yb-12-31"; }
            }

            if ($hasCat) {
                $byCat=$pdo->query("SELECT COALESCE(c.category_name,'未分類') AS label,c.color,COUNT(*) AS cnt,SUM(si.qty) AS tq,SUM(si.qty*COALESCE(si.unit_cost,0)) AS tc FROM stock_items si LEFT JOIN stock_item_categories c ON c.category_id=si.item_type WHERE si.is_active=1 GROUP BY si.item_type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $byCat=[['label'=>'全部','color'=>'#1ABB9C','cnt'=>(int)$pdo->query("SELECT COUNT(*) FROM stock_items WHERE is_active=1")->fetchColumn(),'tq'=>0,'tc'=>0]];
            }
            $byCli=$pdo->query("SELECT COALESCE(clp.customer,'(未指定)') AS client_name, dsp.Customer_Id AS client_id, COUNT(*) AS cnt, ROUND(SUM(si.qty),2) AS tq FROM stock_items si LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id WHERE si.is_active=1 AND si.qty>0 GROUP BY dsp.Customer_Id ORDER BY tq DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

            // 趨勢
            $trend=[]; $trendB=[];
            try {
                $fmt = ($period==='month') ? "'%Y-%m-%d'" : "'%Y-%m'";
                $trendSql="SELECT DATE_FORMAT(txn_date,$fmt) AS txn_date,txn_type,COUNT(*) AS cnt,SUM(ABS(txn_qty)) AS tq FROM stock_transactions WHERE txn_date BETWEEN :df AND :dt GROUP BY txn_date,txn_type ORDER BY txn_date";
                $ts=$pdo->prepare($trendSql); $ts->execute([':df'=>$dateFrom,':dt'=>$dateTo]); $trend=$ts->fetchAll(PDO::FETCH_ASSOC);
                if ($compareB) {
                    $tsB=$pdo->prepare($trendSql); $tsB->execute([':df'=>$dfB,':dt'=>$dtB]); $trendB=$tsB->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch(Exception $e2){}

            $aging=[];
            if ($hasSDate) {
                try { $aging=$pdo->query("SELECT CASE WHEN stock_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) THEN '30天內' WHEN stock_date>=DATE_SUB(CURDATE(),INTERVAL 90 DAY) THEN '31~90天' WHEN stock_date>=DATE_SUB(CURDATE(),INTERVAL 180 DAY) THEN '91~180天' WHEN stock_date IS NOT NULL THEN '180天以上' ELSE '未知' END AS age_group,COUNT(*) AS cnt,SUM(qty) AS tq FROM stock_items WHERE is_active=1 GROUP BY age_group")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e2){}
            }
            echo json_encode(['success'=>true,'by_cat'=>$byCat,'by_client'=>$byCli,'trend'=>$trend,'trend_b'=>$trendB,'aging'=>$aging,'period_a'=>$compareA,'period_b'=>$compareB,'date_from'=>$dateFrom,'date_to'=>$dateTo]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 庫齡明細清單（個別品項停滯日數）──────────
    if ($_POST['action'] === 'get_aging_detail') {
        try {
            $cols=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCat=in_array('item_type',$cols);
            $hasSDate=in_array('stock_date',$cols);
            if (!$hasSDate) { echo json_encode(['success'=>true,'data'=>[]]); exit; }
            $group = trim($_POST['age_group'] ?? '');
            $catJoin=$hasCat?"LEFT JOIN stock_item_categories c ON c.category_id=si.item_type":"";
            $catCol=$hasCat?"COALESCE(c.category_name,'未分類') AS category_name,":"NULL AS category_name,";
            $ageExpr="CASE WHEN si.stock_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) THEN '30天內' WHEN si.stock_date>=DATE_SUB(CURDATE(),INTERVAL 90 DAY) THEN '31~90天' WHEN si.stock_date>=DATE_SUB(CURDATE(),INTERVAL 180 DAY) THEN '91~180天' WHEN si.stock_date IS NOT NULL THEN '180天以上' ELSE '未知' END";
            $sql="SELECT si.d_id,$catCol clp.customer AS client_name,si.storage_location,si.qty,si.stock_date,DATEDIFF(CURDATE(),si.stock_date) AS idle_days,$ageExpr AS age_group FROM stock_items si $catJoin LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id WHERE si.is_active=1";
            $p=[];
            if ($group!=='' && $group!=='全部') { $sql.=" AND $ageExpr=:g"; $p[':g']=$group; }
            $sql.=" ORDER BY (si.stock_date IS NULL), idle_days DESC, si.d_id";
            $st=$pdo->prepare($sql); $st->execute($p);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 檢查組合件料號是否存在 (含模糊搜尋) ────────
    if ($_POST['action'] === 'check_group_did') {
        try {
            $name = trim($_POST['group_name'] ?? '');
            $st = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
            $st->execute([$name]);
            $exact = $st->fetch(PDO::FETCH_ASSOC);
            if ($exact) {
                echo json_encode(['success'=>true, 'exists'=>true]);
            } else {
                $st2 = $pdo->prepare("SELECT D_Setting_Id FROM d_setting WHERE D_Setting_Id LIKE ? LIMIT 10");
                $st2->execute(["%$name%"]);
                $matches = $st2->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success'=>true, 'exists'=>false, 'matches'=>$matches]);
            }
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得組合件結構 ────────────────────────────
    if ($_POST['action'] === 'get_assembly_structure') {
        try {
            $dsid = intval($_POST['d_setting_id'] ?? 0);
            if (!$dsid) throw new Exception('未指定料號');
            
            // 檢查是否為組合件
            $st = $pdo->prepare("SELECT Is_Assembly FROM d_setting WHERE d_id = ?");
            $st->execute([$dsid]);
            $isAsm = $st->fetchColumn();
            
            $children = [];
            if ($isAsm == 1) {
                $st2 = $pdo->prepare("
                    SELECT b.child_d_id, b.standard_qty, d.D_Setting_Id AS child_part_no
                    FROM d_setting_bom b
                    JOIN d_setting d ON d.d_id = b.child_d_id
                    WHERE b.parent_d_id = ?
                    ORDER BY b.bom_id ASC
                ");
                $st2->execute([$dsid]);
                $children = $st2->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'is_assembly' => ($isAsm == 1), 'children' => $children]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 組合件整組入/出庫/調整 ────────────────────
    if ($_POST['action'] === 'group_txn') {
        try {
            $txnType    = $_POST['txn_type'] ?? '';
            if (!in_array($txnType, ['in','out','adjust'])) throw new Exception('異動類型錯誤');
            $sets       = (float)($_POST['sets'] ?? 0);
            $groupId    = intval($_POST['group_id'] ?? 0);
            $stockItemId = intval($_POST['stock_item_id'] ?? 0);
            if (!$groupId && !$stockItemId) throw new Exception('未指定群組或品項');

            $remark     = trim($_POST['remark'] ?? '') ?: null;
            $txnDate    = $_POST['txn_date'] ?? date('Y-m-d');
            $outDeptId  = intval($_POST['out_dept_id']  ?? 0) ?: null;
            $outUserId  = intval($_POST['out_user_id']  ?? 0) ?: null;
            $grpOrderRef= trim($_POST['group_order_ref'] ?? '') ?: null; // 整組入庫綁定訂單號
            // 若整組入庫有新的訂單綁定，更新 stock_item_groups.order_ref
            if ($txnType === 'in' && $grpOrderRef !== null) {
                try {
                    $orCheck = $pdo->query("SHOW COLUMNS FROM stock_item_groups LIKE 'order_ref'")->fetchColumn();
                    if ($orCheck) $pdo->prepare("UPDATE stock_item_groups SET order_ref=?, Modified_By=?, Modified_At=NOW() WHERE group_id=?")->execute([$grpOrderRef ?: null, $userId, $groupId]);
                } catch(Exception $e2){}
            }
            // 入庫：各子件個別 bom_ref/order_ref
            $itemsJson   = $_POST['items_json']   ?? '[]';
            $itemsMap    = []; // stock_item_id => [bom_ref, order_ref]
            foreach (json_decode($itemsJson, true) ?: [] as $it) {
                $sid = intval($it['stock_item_id'] ?? 0);
                if ($sid) $itemsMap[$sid] = [
                    'bom_ref'   => trim($it['bom_ref']   ?? '') ?: null,
                    'order_ref' => intval($it['order_ref'] ?? 0) ?: null,
                ];
            }
            // 出庫：批次選擇 [{batch_key, sets}, ...]
            $batchesJson = $_POST['batches_json'] ?? '[]';
            $batchesSel  = json_decode($batchesJson, true) ?: [];

            if ($groupId) {
                $members = $pdo->prepare("SELECT stock_item_id, d_id, qty, pcs_per_set FROM stock_items WHERE group_id=? AND is_active=1 ORDER BY stock_item_id");
                $members->execute([$groupId]);
            } else {
                $members = $pdo->prepare("SELECT stock_item_id, d_id, qty, 1 AS pcs_per_set FROM stock_items WHERE stock_item_id=? AND is_active=1");
                $members->execute([$stockItemId]);
            }
            $memberRows = $members->fetchAll(PDO::FETCH_ASSOC);
            if (empty($memberRows)) throw new Exception('找不到此組合件的料號');

            $pdo->beginTransaction();
            $results = [];

            if ($txnType === 'in') {
                // ── 入庫：每組統一，各子件可各自帶 bom_ref/order_ref ──
                foreach ($memberRows as $m) {
                    $pps    = max(1, intval($m['pcs_per_set'] ?? 1));
                    $chgQty = $pps * $sets;
                    $curQty = floatval($m['qty']);
                    $itemId = intval($m['stock_item_id']);
                    $d_id   = $m['d_id'];
                    $newQty = round($curQty + $chgQty, 4);
                    $bomRef   = $itemsMap[$itemId]['bom_ref']   ?? null;
                    $orderRef = $itemsMap[$itemId]['order_ref'] ?? null;
                    $txnRemark = ($remark ?: '').'（整組入庫 '.$sets.'組 × '.$pps.'PCS）';
                    $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$newQty,$userId,$itemId]);
                    $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,bom_ref,order_ref,txn_date,remark,Created_By) VALUES (?,?,'in',?,?,?,?,?,?,?,?)")
                        ->execute([$itemId,$d_id,$chgQty,$curQty,$newQty,$bomRef,$orderRef,$txnDate,$txnRemark,$userId]);
                    $results[] = ['d_id'=>$d_id,'pcs'=>$chgQty,'qty_after'=>$newQty];
                }
            } else {
                // ── 出庫：依批次選擇（batch_key = txn_date|txn_bom|txn_order），對各子件各自出庫 ──
                // 若沒有批次選擇（舊流程），直接整組出庫
                if (empty($batchesSel)) {
                    foreach ($memberRows as $m) {
                        $pps    = (float)($m['pcs_per_set'] ?? 1);
                        $chgQty = $pps * $sets;
                        $curQty = floatval($m['qty']);
                        $itemId = intval($m['stock_item_id']); $d_id = $m['d_id'];
                        $newQty = round($curQty - $chgQty, 4);
                        if ($newQty < 0) throw new Exception('料號 '.$d_id.' 庫存不足');
                        $txnRemark = ($remark ?: '');
                        if ($groupId) $txnRemark .= '（整組出庫 '.$sets.'組 × '.$pps.'PCS）';

                        $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$newQty,$userId,$itemId]);
                        // 動態偵測 out_dept_id 欄位
                        $txnColsD = $pdo->query("SHOW COLUMNS FROM stock_transactions")->fetchAll(PDO::FETCH_COLUMN);
                        $hasDept = in_array('out_dept_id', $txnColsD);
                        if ($hasDept) {
                            $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,out_dept_id,out_user_id,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?,?,?)")
                                ->execute([$itemId,$d_id,-$chgQty,$curQty,$newQty,$outDeptId,$outUserId,$txnDate,$txnRemark,$userId]);
                        } else {
                            $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?)")
                                ->execute([$itemId,$d_id,-$chgQty,$curQty,$newQty,$txnDate,$txnRemark,$userId]);
                        }
                        $results[] = ['d_id'=>$d_id,'pcs'=>$chgQty,'qty_after'=>$newQty];
                    }
                } else {
                    // 批次出庫：按選取的批次逐筆減庫存
                    $memberMap = [];
                    foreach ($memberRows as $m) $memberMap[intval($m['stock_item_id'])] = $m;
                    foreach ($batchesSel as $bSel) {
                        $bSets   = max(1, intval($bSel['sets'] ?? 0));
                        $bKey    = $bSel['batch_key'] ?? '';
                        // batch_key 格式 "txn_date|txn_bom|txn_order_no"
                        $parts   = explode('|', $bKey);
                        $bDate   = $parts[0] ?? '';
                        $bBom    = $parts[1] ?? '';
                        $bOrder  = $parts[2] ?? '';
                        foreach ($memberRows as $m) {
                            $pps    = (float)($m['pcs_per_set'] ?? 1);
                            $chgQty = $pps * $bSets;
                            $curQty = floatval($m['qty']);
                            $itemId = intval($m['stock_item_id']); $d_id = $m['d_id'];
                            $newQty = round($curQty - $chgQty, 4);
                            if ($newQty < 0) throw new Exception('料號 '.$d_id.' 庫存不足（批次出庫）');
                            $txnRemark = ($remark ?: '');
                            if ($groupId) $txnRemark .= '（批次出庫 '.$bSets.'組 × '.$pps.'PCS 批次:'.$bDate.')';
                            else $txnRemark .= '（批次出庫 批次:'.$bDate.')';

                            $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$newQty,$userId,$itemId]);
                            $txnCols2 = $pdo->query("SHOW COLUMNS FROM stock_transactions")->fetchAll(PDO::FETCH_COLUMN);
                            $hasDept2 = in_array('out_dept_id', $txnCols2); $hasBomRef2 = in_array('bom_ref', $txnCols2);
                            if ($hasDept2 && $hasBomRef2) {
                                $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,bom_ref,out_dept_id,out_user_id,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?,?,?,?)")
                                    ->execute([$itemId,$d_id,-$chgQty,$curQty,$newQty,$bBom?:null,$outDeptId,$outUserId,$txnDate,$txnRemark,$userId]);
                            } elseif ($hasBomRef2) {
                                $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,bom_ref,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?,?)")
                                    ->execute([$itemId,$d_id,-$chgQty,$curQty,$newQty,$bBom?:null,$txnDate,$txnRemark,$userId]);
                            } else {
                                $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?)")
                                    ->execute([$itemId,$d_id,-$chgQty,$curQty,$newQty,$txnDate,$txnRemark,$userId]);
                            }
                            $results[] = ['d_id'=>$d_id,'pcs'=>$chgQty,'qty_after'=>$newQty];
                            // 更新 $m['qty'] 以免下個批次計算錯誤
                            foreach ($memberRows as &$mref) { if ($mref['stock_item_id'] == $m['stock_item_id']) $mref['qty'] = $newQty; }
                            unset($mref);
                        }
                    }
                }
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'sets'=>$sets,'results'=>$results,'txn_type'=>$txnType]);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 取得組合件批次剩餘（FIFO 計算）──────────────
    if ($_POST['action'] === 'get_group_batches') {
        try {
            $groupId = intval($_POST['group_id'] ?? 0);
            $stockItemId = intval($_POST['stock_item_id'] ?? 0);
            if (!$groupId && !$stockItemId) throw new Exception('未指定群組或品項');

            $txnCols = $pdo->query("SHOW COLUMNS FROM stock_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasBomRef = in_array('bom_ref', $txnCols);
            $hasOrdRef = in_array('order_ref', $txnCols);
            $bomSel   = $hasBomRef ? "st.bom_ref AS txn_bom," : "NULL AS txn_bom,";
            $ordJoin  = $hasOrdRef ? "LEFT JOIN order_track ot_b ON ot_b.Order_id=st.order_ref" : '';
            $ordSel   = $hasOrdRef ? "ot_b.Order_oo AS txn_order_no," : "NULL AS txn_order_no,";

            if ($groupId) {
                $members = $pdo->prepare("SELECT stock_item_id, d_id, qty, pcs_per_set FROM stock_items WHERE group_id=? AND is_active=1 ORDER BY stock_item_id");
                $members->execute([$groupId]);
            } else {
                $members = $pdo->prepare("SELECT stock_item_id, d_id, qty, 1 AS pcs_per_set FROM stock_items WHERE stock_item_id=? AND is_active=1");
                $members->execute([$stockItemId]);
            }
            $memberRows = $members->fetchAll(PDO::FETCH_ASSOC);
            if (empty($memberRows)) { echo json_encode(['success'=>true,'batches'=>[]]); exit; }

            // 取各子件的入庫記錄（由舊到新）
            $memberMap = [];
            foreach ($memberRows as $m) {
                $sid = intval($m['stock_item_id']);
                $memberMap[$sid] = ['d_id'=>$m['d_id'],'qty'=>floatval($m['qty']),'pps'=>max(1,intval($m['pcs_per_set']??1)),'in_txns'=>[],'out_qty'=>0];
            }
            $hasRemark = in_array('remark', $txnCols);
            $remarkSel = $hasRemark ? "st.remark," : "NULL AS remark,";
            $sidList = implode(',', array_keys($memberMap));
            $txns = $pdo->query("SELECT st.stock_item_id, st.txn_type, st.txn_qty, st.txn_date, $bomSel $ordSel $remarkSel st.txn_id
                                  FROM stock_transactions st $ordJoin
                                  WHERE st.stock_item_id IN ($sidList) ORDER BY st.txn_date ASC, st.txn_id ASC")
                        ->fetchAll(PDO::FETCH_ASSOC);

            foreach ($txns as $t) {
                $sid = intval($t['stock_item_id']);
                if (!isset($memberMap[$sid])) continue;
                if ($t['txn_type'] === 'in' && floatval($t['txn_qty']) > 0) {
                    $memberMap[$sid]['in_txns'][] = $t;
                } elseif (floatval($t['txn_qty']) < 0) {
                    $memberMap[$sid]['out_qty'] += abs(floatval($t['txn_qty']));
                }
            }

            // 以第一個子件的批次為主，計算 FIFO 剩餘，再用相同 batch_key 組合所有子件
            $primarySid = array_keys($memberMap)[0];
            $primMember = $memberMap[$primarySid];
            $primPps    = $primMember['pps'];
            $primOutQty = $primMember['out_qty'];
            $batches = [];
            foreach ($primMember['in_txns'] as $t) {
                $inPcs   = floatval($t['txn_qty']);
                $consumed = min($primOutQty, $inPcs);
                $primOutQty -= $consumed;
                $remaining  = $inPcs - $consumed;
                if ($remaining > 0.001) {
                    $sets = floor($remaining / $primPps);
                    if ($sets < 1) continue;
                    $bKey = ($t['txn_date']??'').'|'.($t['txn_bom']??'').'|'.($t['txn_order_no']??'');
                    // 計算其他子件的剩餘
                    $bMembers = [];
                    foreach ($memberMap as $sid => $mm) {
                        $bMembers[] = ['stock_item_id'=>$sid,'d_id'=>$mm['d_id'],'remaining_qty'=>$sets*$mm['pps']];
                    }
                    $batches[] = [
                        'batch_key'    => $bKey,
                        'txn_date'     => ($t['txn_date']??''),
                        'txn_bom'      => ($t['txn_bom']??''),
                        'txn_order_no' => ($t['txn_order_no']??''),
                        'remark'       => ($t['remark']??''),
                        'sets'         => $sets,
                        'members'      => $bMembers,
                    ];
                }
            }
            // 若無入庫記錄但有庫存（如 Excel 匯入），視為單一批次
            if (empty($batches) && floatval($primMember['qty']) > 0) {
                $synSets = $primPps > 1 ? max(1, (int)floor($primMember['qty'] / $primPps)) : floatval($primMember['qty']);
                $bMembers = [];
                foreach ($memberMap as $mSid => $mm) {
                    $bMembers[] = ['stock_item_id'=>$mSid,'d_id'=>$mm['d_id'],'remaining_qty'=>$mm['qty']];
                }
                $batches[] = [
                    'batch_key'    => 'synthetic||',
                    'txn_date'     => date('Y-m-d'),
                    'txn_bom'      => '',
                    'txn_order_no' => '',
                    'remark'       => '（原始庫存，無入庫記錄）',
                    'sets'         => $synSets,
                    'members'      => $bMembers,
                ];
            }
            echo json_encode(['success'=>true,'batches'=>$batches]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 組合件整組調整庫存（編輯用） ──────────────
    if ($_POST['action'] === 'group_adjust') {
        try {
            $groupId      = intval($_POST['group_id'] ?? 0); if (!$groupId) throw new Exception('未指定群組');
            $sets         = max(0, intval($_POST['sets'] ?? 0));
            $remark       = trim($_POST['remark'] ?? '') ?: '整組調整組數';
            $groupOrderRef= trim($_POST['group_order_ref'] ?? '') ?: null; // 群組訂單號 Order_oo
            // 子件個別 bom_ref / order_ref：items_json = [{stock_item_id, bom_ref, order_ref}, ...]
            $itemsJson    = $_POST['items_json'] ?? '[]';
            $itemsMap     = []; // stock_item_id => [bom_ref, order_ref]
            foreach (json_decode($itemsJson, true) ?: [] as $it) {
                $sid = intval($it['stock_item_id'] ?? 0);
                if ($sid) $itemsMap[$sid] = [
                    'bom_ref'   => trim($it['bom_ref']   ?? '') ?: null,
                    'order_ref' => intval($it['order_ref'] ?? 0) ?: null,
                ];
            }

            $members = $pdo->prepare("SELECT stock_item_id, d_id, qty, pcs_per_set FROM stock_items WHERE group_id=? AND is_active=1 ORDER BY stock_item_id");
            $members->execute([$groupId]);
            $memberRows = $members->fetchAll(PDO::FETCH_ASSOC);
            if (empty($memberRows)) throw new Exception('找不到此組合件的料號');

            $pdo->beginTransaction();

            // 更新群組層級訂單綁定
            try {
                $sigCols = $pdo->query("SHOW COLUMNS FROM stock_item_groups")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('order_ref', $sigCols)) {
                    $newGroupPrice = null;
                    if ($groupOrderRef) {
                        $opR = $pdo->prepare("SELECT unit_price, modified_unit_price FROM order_track WHERE Order_oo=? LIMIT 1");
                        $opR->execute([$groupOrderRef]); $opRR = $opR->fetch(PDO::FETCH_ASSOC);
                        if ($opRR) {
                            $newGroupPrice = floatval($opRR['modified_unit_price'] ?? 0) > 0
                                ? floatval($opRR['modified_unit_price'])
                                : (floatval($opRR['unit_price'] ?? 0) > 0 ? floatval($opRR['unit_price']) : null);
                        }
                    }
                    if ($newGroupPrice !== null) {
                        $pdo->prepare("UPDATE stock_item_groups SET order_ref=?, unit_price=?, Modified_By=?, Modified_At=NOW() WHERE group_id=?")
                            ->execute([$groupOrderRef, $newGroupPrice, $userId, $groupId]);
                    } else {
                        $pdo->prepare("UPDATE stock_item_groups SET order_ref=?, Modified_By=?, Modified_At=NOW() WHERE group_id=?")
                            ->execute([$groupOrderRef, $userId, $groupId]);
                    }
                    // 同步子件 order_price_snapshot
                    $siCols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
                    if ($newGroupPrice && in_array('order_price_snapshot', $siCols)) {
                        $pdo->prepare("UPDATE stock_items SET order_price_snapshot=? WHERE group_id=?")
                            ->execute([$newGroupPrice, $groupId]);
                    }
                }
            } catch(Exception $e2){}

            foreach ($memberRows as $m) {
                $pps    = max(1, intval($m['pcs_per_set'] ?? 1));
                $newQty = $pps * $sets;
                $oldQty = floatval($m['qty']);
                $diff   = round($newQty - $oldQty, 4);
                $itemId = intval($m['stock_item_id']);
                $d_id   = $m['d_id'];
                // 更新子件個別的 bom_ref / order_ref（若有傳入）
                $bomRef  = isset($itemsMap[$itemId]) ? $itemsMap[$itemId]['bom_ref']   : null;
                $ordRef  = isset($itemsMap[$itemId]) ? $itemsMap[$itemId]['order_ref'] : null;
                $setParts = "qty=?, Modified_By=?, Modified_At=NOW()";
                $setVals  = [$newQty, $userId];
                if (array_key_exists($itemId, $itemsMap)) {
                    $setParts .= ", bom_ref=?, order_ref=?";
                    $setVals[] = $bomRef;
                    $setVals[] = $ordRef;
                }
                $setVals[] = $itemId;
                $pdo->prepare("UPDATE stock_items SET $setParts WHERE stock_item_id=?")->execute($setVals);
                if ($diff != 0) {
                    $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'adjust',?,?,?,CURDATE(),?,?)")
                        ->execute([$itemId,$d_id,$diff,$oldQty,$newQty,$remark.' ('.$sets.'組 × '.$pps.'PCS)',$userId]);
                }
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'message'=>'已調整為 '.$sets.' 組']);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 新增組合件群組 ────────────────────────────
    if ($_POST['action'] === 'save_stock_group') {
        try {
            $groupName    = trim($_POST['group_name'] ?? ''); if (!$groupName) throw new Exception('組合名稱必填');
            $groupRemark  = trim($_POST['group_remark'] ?? '') ?: null;
            $groupPrice   = ($_POST['group_unit_price'] ?? '') !== '' ? floatval($_POST['group_unit_price']) : null;
            $sets         = max(1, intval($_POST['sets'] ?? 1)); // 本次入庫幾組
            $itemsJson    = $_POST['items_json'] ?? '[]';
            $items        = json_decode($itemsJson, true);
            $customerIdIn = trim($_POST['group_customer_id'] ?? '') ?: null;
            $groupOrderRef= trim($_POST['group_order_ref'] ?? '') ?: null; // 組合件綁定訂單號：對應order_track.Order_oo
            if (!is_array($items) || count($items) < 2) throw new Exception('組合件至少需要 2 個料號');

            // 確認 stock_item_groups 表存在（group_name 儲存 d_setting.d_id 整數）
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'stock_item_groups'")->fetchColumn();
            if (!$tableCheck) {
                $pdo->exec("CREATE TABLE stock_item_groups (
                    group_id INT AUTO_INCREMENT PRIMARY KEY,
                    group_name INT NOT NULL COMMENT '對應 d_setting.d_id',
                    unit_price DECIMAL(12,4) NULL COMMENT '整組售價',
                    remark VARCHAR(200) NULL,
                    Created_By INT NULL,
                    Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    Modified_By INT NULL,
                    Modified_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                // 確認 unit_price 欄位存在（舊表補加）
                $upCheck = $pdo->query("SHOW COLUMNS FROM stock_item_groups LIKE 'unit_price'")->fetchColumn();
                if (!$upCheck) $pdo->exec("ALTER TABLE stock_item_groups ADD COLUMN unit_price DECIMAL(12,4) NULL COMMENT '手動輸入整組售價；有order_ref時以訂單價格為主' AFTER group_name");
                $orCheck = $pdo->query("SHOW COLUMNS FROM stock_item_groups LIKE 'order_ref'")->fetchColumn();
                if (!$orCheck) $pdo->exec("ALTER TABLE stock_item_groups ADD COLUMN order_ref VARCHAR(50) NULL COMMENT '組合件綁定訂單號：對應order_track.Order_oo，有綁定時售價自動從訂單抓取' AFTER unit_price");
                // 若 group_name 仍是 VARCHAR，嘗試改為 INT（僅當資料允許轉型時）
                $gnColInfo = $pdo->query("SHOW COLUMNS FROM stock_item_groups LIKE 'group_name'")->fetch(PDO::FETCH_ASSOC);
                if ($gnColInfo && stripos($gnColInfo['Type'], 'int') === false) {
                    try { $pdo->exec("ALTER TABLE stock_item_groups MODIFY COLUMN group_name INT NOT NULL COMMENT '對應 d_setting.d_id'"); } catch(Exception $e_alt){}
                }
            }
            // 確認快照欄位與 group 欄位存在
            $existCols0 = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('bom_cost_snapshot', $existCols0)) $pdo->exec("ALTER TABLE stock_items ADD COLUMN bom_cost_snapshot DECIMAL(12,4) NULL COMMENT 'BOM單顆成本快照：外包來自bom_ing_transfer_log各製程平均單價加總；廠內來自pm_process_daily_report報工KPI' AFTER unit_cost");
            if (!in_array('order_price_snapshot', $existCols0)) $pdo->exec("ALTER TABLE stock_items ADD COLUMN order_price_snapshot DECIMAL(12,4) NULL COMMENT '訂單售價快照：優先modified_unit_price，無則unit_price，依order_ref或group.order_ref對應order_track' AFTER unit_price");
            if (!in_array('group_id', $existCols0)) $pdo->exec("ALTER TABLE stock_items ADD COLUMN group_id INT NULL DEFAULT NULL AFTER is_active, ADD INDEX idx_group_id (group_id)");
            if (!in_array('pcs_per_set', $existCols0)) $pdo->exec("ALTER TABLE stock_items ADD COLUMN pcs_per_set INT NULL DEFAULT NULL COMMENT '每組含此料號幾PCS' AFTER group_id");

            $existCols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasCol = fn($c) => in_array($c, $existCols);

            $pdo->beginTransaction();

            // ── 同步至 d_setting 與 d_setting_bom (組合件邏輯) ──
            $stD = $pdo->prepare("SELECT d_id, Customer_Id FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
            $stD->execute([$groupName]);
            $dEntry = $stD->fetch(PDO::FETCH_ASSOC);
            $target_d_id = null;

            if ($dEntry) {
                $target_d_id = $dEntry['d_id'];
                // 若使用者有選擇客戶，一同更新 Customer_Id
                $updCid = $customerIdIn ?? $dEntry['Customer_Id'];
                $pdo->prepare("UPDATE d_setting SET Is_Assembly=1, Customer_Id=?, Modified_By=?, Modified_At=NOW() WHERE d_id=?")->execute([$updCid, $userId, $target_d_id]);
            } else {
                // 自動建立新料號：Type=N, Is_Assembly=1，客戶ID 採使用者選擇
                $pdo->prepare("INSERT INTO d_setting (D_Setting_Id, Type, Is_Assembly, Customer_Id, Created_By, Modified_By) VALUES (?, 'N', 1, ?, ?, ?)")
                    ->execute([$groupName, $customerIdIn, $userId, $userId]);
                $target_d_id = $pdo->lastInsertId();
            }

            // 更新 d_setting_bom 結構 (採先刪後增邏輯)
            $pdo->prepare("DELETE FROM d_setting_bom WHERE parent_d_id = ?")->execute([$target_d_id]);
            $insBom = $pdo->prepare("INSERT INTO d_setting_bom (parent_d_id, child_d_id, standard_qty, Created_By) VALUES (?, ?, ?, ?)");
            foreach ($items as $it) {
                $childDsid = intval($it['d_setting_id'] ?? 0);
                if ($childDsid) $insBom->execute([$target_d_id, $childDsid, intval($it['pcs_per_set'] ?? 1), $userId]);
            }

            // 建立群組（group_name 儲存 d_setting.d_id 整數）
            // 若有綁定訂單，從訂單自動取售價覆蓋 groupPrice
            if ($groupOrderRef) {
                try {
                    $opR = $pdo->prepare("SELECT unit_price, modified_unit_price FROM order_track WHERE Order_oo=? LIMIT 1");
                    $opR->execute([$groupOrderRef]); $opRR = $opR->fetch(PDO::FETCH_ASSOC);
                    if ($opRR) {
                        $fromOrder = floatval($opRR['modified_unit_price'] ?? 0) > 0 ? floatval($opRR['modified_unit_price']) : floatval($opRR['unit_price'] ?? 0);
                        if ($fromOrder > 0) $groupPrice = $fromOrder;
                    }
                } catch(Exception $e2){}
            }

            $pdo->prepare("INSERT INTO stock_item_groups (group_name, unit_price, order_ref, remark, Created_By) VALUES (?,?,?,?,?)")
                ->execute([$target_d_id, $groupPrice, $groupOrderRef, $groupRemark, $userId]);
            $groupId = (int)$pdo->lastInsertId();

            $createdIds = [];
            foreach ($items as $item) {
                $d_id        = trim($item['d_id'] ?? ''); if (!$d_id) continue;
                $d_setting_id= intval($item['d_setting_id'] ?? 0) ?: null;
                $category_id = intval($item['category_id'] ?? 0) ?: null;
                $location_id = intval($item['location_id'] ?? 0) ?: null;
                $loc_str     = trim($item['storage_location'] ?? '') ?: null;
                $pcs_per_set = max(1, intval($item['pcs_per_set'] ?? 1)); // 每組幾PCS
                $qty         = $pcs_per_set * $sets;                       // 實際入庫 = PCS × 組數
                $unit_id     = intval($item['unit_id'] ?? 0) ?: 31;       // 組合件預設單位 unit_id=31（組）
                $bom_ref     = trim($item['bom_ref'] ?? '') ?: null;
                $order_ref   = intval($item['order_ref'] ?? 0) ?: null;
                $unit_cost   = ($item['unit_cost'] ?? '') !== '' ? floatval($item['unit_cost']) : null;
                $unit_price  = ($item['unit_price'] ?? '') !== '' ? floatval($item['unit_price']) : $groupPrice;
                $mfg         = ($item['mfg_date'] ?? '') ?: null;
                $sd          = ($item['stock_date'] ?? '') ?: null;
                $ey          = intval($item['expire_years'] ?? 0) ?: null;
                $pkg         = trim($item['package_box'] ?? '') ?: null;
                $cname       = trim($item['client_name'] ?? '') ?: null;
                $cid         = trim($item['client_id'] ?? '') ?: null;
                $r1          = trim($item['remark1'] ?? '') ?: null;

                // 取得儲位字串快照
                if ($location_id && $hasCol('location_id')) {
                    try {
                        $lr = $pdo->prepare("SELECT location_code FROM stock_locations WHERE location_id=?");
                        $lr->execute([$location_id]); $lr = $lr->fetch(PDO::FETCH_ASSOC);
                        if ($lr) $loc_str = $lr['location_code'];
                    } catch(Exception $e2){}
                }

                $allFields = [
                    'd_id'=>$d_id, 'd_setting_id'=>$d_setting_id, 'item_type'=>$category_id,
                    'location_id'=>$location_id, 'storage_location'=>$loc_str, 'qty'=>$qty,
                    'unit_id'=>$unit_id, 'bom_ref'=>$bom_ref, 'order_ref'=>$order_ref,
                    'unit_cost'=>$unit_cost, 'unit_price'=>$unit_price, 'mfg_date'=>$mfg,
                    'stock_date'=>$sd, 'expire_years'=>$ey, 'package_box'=>$pkg,
                    'client_name'=>$cname, 'client_id'=>$cid, 'remark1'=>$r1,
                    'group_id'=>$groupId, 'pcs_per_set'=>$pcs_per_set,
                ];
                $fields = array_filter($allFields, fn($k) => $hasCol($k), ARRAY_FILTER_USE_KEY);

                $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($fields)));
                $phs  = implode(',', array_map(fn($k) => ":$k", array_keys($fields)));
                $stmt = $pdo->prepare("INSERT INTO stock_items ($cols,Created_By,Modified_By) VALUES ($phs,:cby,:cby2)");
                $stmt->execute(array_merge($fields, [':cby'=>$userId, ':cby2'=>$userId]));
                $newId = (int)$pdo->lastInsertId();
                $createdIds[] = $newId;

                if ($qty > 0) {
                    try {
                        $txnRemark = '組合件建檔入庫 '.$sets.'組 × '.$pcs_per_set.'PCS';
                        $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_to,location_to_id,bom_ref,order_ref,package_box,txn_date,remark,Created_By) VALUES (?,?,'in',?,0,?,?,?,?,?,?,CURDATE(),?,?)")
                            ->execute([$newId,$d_id,$qty,$qty,$loc_str,$location_id,$bom_ref,$order_ref,$pkg,$txnRemark,$userId]);
                    } catch(Exception $e2){}
                }
            }

            $pdo->commit();
            echo json_encode(['success'=>true,'message'=>'組合件「'.$groupName.'」建立成功，共 '.count($createdIds).' 筆（入庫 '.$sets.' 組）','group_id'=>$groupId,'created_count'=>count($createdIds)]);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 取得訂單列表（供組合件綁定選擇）────────────
    if ($_POST['action'] === 'get_orders_for_group') {
        try {
            $dsId  = trim($_POST['d_setting_id'] ?? ''); // D_Setting_Id 字串
            $year  = intval($_POST['year']  ?? 0);
            $month = intval($_POST['month'] ?? 0);
            $cond  = $dsId ? "WHERE ot.d_id = ?" : "WHERE 1=1";
            $params = $dsId ? [$dsId] : [];
            if ($year > 0) { $cond .= " AND YEAR(ot.Created_At) = ?"; $params[] = $year; }
            if ($month > 0) { $cond .= " AND MONTH(ot.Created_At) = ?"; $params[] = $month; }
            $cond .= " ORDER BY ot.Created_At DESC LIMIT 200";
            $st = $pdo->prepare("
                SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Client_name, ot.unit_price,
                       ot.Qty, ot.Delivery_date, ot.Order_status,
                       ot.process_name, ot.Created_At
                FROM order_track ot
                $cond
            ");
            $st->execute($params);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 批次更新本頁成本/售價快照 ─────────────────
    if ($_POST['action'] === 'refresh_cost_snapshot') {
        try {
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!is_array($ids) || empty($ids)) { echo json_encode(['success'=>true,'updated'=>0]); exit; }

            // 確認快照欄位存在
            $siCols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('bom_cost_snapshot', $siCols)) {
                $pdo->exec("ALTER TABLE stock_items
                    ADD COLUMN bom_cost_snapshot DECIMAL(12,4) NULL
                        COMMENT 'BOM單顆成本快照：外包加工來自bom_ing_transfer_log依bom_ref加總各製程平均單價；廠內加工來自pm_process_daily_report報工KPI計算平均單顆金額。每次頁面載入時批次更新。'
                    AFTER unit_cost");
            }
            if (!in_array('order_price_snapshot', $siCols)) {
                $pdo->exec("ALTER TABLE stock_items
                    ADD COLUMN order_price_snapshot DECIMAL(12,4) NULL
                        COMMENT '訂單售價快照：來自order_track.unit_price（優先modified_unit_price，無則unit_price），依stock_items.order_ref對應order_track.Order_id。每次頁面載入時批次更新。'
                    AFTER unit_price");
            }
            // stock_item_groups.order_ref
            $sigCols = [];
            try { $sigCols = $pdo->query("SHOW COLUMNS FROM stock_item_groups")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e2){}
            if (!empty($sigCols) && !in_array('order_ref', $sigCols)) {
                $pdo->exec("ALTER TABLE stock_item_groups
                    ADD COLUMN order_ref VARCHAR(50) NULL
                        COMMENT '組合件綁定訂單號：對應order_track.Order_oo，用於自動抓取整組售價。'
                    AFTER unit_price");
            }

            // 載入廠內加工商清單
            $inhouseMakers = [];
            try {
                $im = $pdo->query("SELECT maker_id_no FROM maker_list WHERE internal=1")->fetchAll(PDO::FETCH_COLUMN);
                $inhouseMakers = $im ?: [];
            } catch(Exception $e2){}

            // 抓取要更新的品項
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = $pdo->prepare("SELECT si.stock_item_id, si.bom_ref, si.order_ref, si.d_id, si.d_setting_id, si.group_id FROM stock_items si WHERE si.stock_item_id IN ($ph)")->execute($ids) ? null : null;
            $stmt = $pdo->prepare("SELECT si.stock_item_id, si.bom_ref, si.order_ref, si.d_id, si.d_setting_id, si.group_id FROM stock_items si WHERE si.stock_item_id IN ($ph)");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 預先查 group order_ref
            $groupOrderMap = [];
            $grpIds = array_values(array_unique(array_filter(array_column($rows, 'group_id'))));
            if (!empty($grpIds) && in_array('order_ref', $sigCols)) {
                $gph = implode(',', array_fill(0, count($grpIds), '?'));
                $gs = $pdo->prepare("SELECT sig.group_id, ot.Order_id, ot.unit_price, ot.modified_unit_price FROM stock_item_groups sig LEFT JOIN order_track ot ON ot.Order_oo=sig.order_ref WHERE sig.group_id IN ($gph)");
                $gs->execute($grpIds);
                foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $gr) {
                    if ($gr['Order_id']) {
                        $p = floatval($gr['modified_unit_price'] ?? 0) > 0 ? floatval($gr['modified_unit_price']) : floatval($gr['unit_price'] ?? 0);
                        $groupOrderMap[$gr['group_id']] = $p > 0 ? $p : null;
                    }
                }
            }

            $updCost  = $pdo->prepare("UPDATE stock_items SET bom_cost_snapshot=? WHERE stock_item_id=?");
            $updPrice = $pdo->prepare("UPDATE stock_items SET order_price_snapshot=? WHERE stock_item_id=?");
            $updated  = 0;

            foreach ($rows as $row) {
                $sid    = $row['stock_item_id'];
                $bomRef = $row['bom_ref'];
                $ordRef = $row['order_ref'];
                $dsid   = $row['d_setting_id'] ?: 0;

                // ── 成本快照 ──
                $costVal = null;
                if ($bomRef) {
                    // 1. 先嘗試外包加工：bom_ing_transfer_log 有非廠內加工商的資料
                    try {
                        // 取得此 bom 的所有製程（含 bom_sn）
                        $biRows = $pdo->prepare("
                            SELECT bi.bom_sn, bitl.price, bitl.paid_qty, bitl.modified_unit_price, bitl.maker_from
                            FROM bom_ing_transfer_log bitl
                            JOIN bom_ing bi ON bi.bom_ing_fid = bitl.bom_ing_fid
                            WHERE bitl.bom = ?
                        ");
                        $biRows->execute([$bomRef]);
                        $transferRows = $biRows->fetchAll(PDO::FETCH_ASSOC);

                        // 過濾掉廠內加工商
                        $outsourceRows = array_filter($transferRows, function($r) use($inhouseMakers) {
                            return !in_array($r['maker_from'], $inhouseMakers);
                        });

                        if (!empty($outsourceRows)) {
                            // 按 bom_sn 分組，各組算平均單價後加總
                            $byBomSn = [];
                            foreach ($outsourceRows as $tr) {
                                $sn = $tr['bom_sn'] ?? '_';
                                $price = floatval($tr['modified_unit_price'] ?? 0) > 0
                                    ? floatval($tr['modified_unit_price'])
                                    : floatval($tr['price'] ?? 0);
                                $qty = floatval($tr['paid_qty'] ?? 0);
                                if (!isset($byBomSn[$sn])) $byBomSn[$sn] = ['total_price'=>0,'total_qty'=>0];
                                $byBomSn[$sn]['total_price'] += $price * $qty;
                                $byBomSn[$sn]['total_qty']   += $qty;
                            }
                            $unitCost = 0;
                            foreach ($byBomSn as $sn => $d) {
                                if ($d['total_qty'] > 0) $unitCost += $d['total_price'] / $d['total_qty'];
                            }
                            $costVal = $unitCost > 0 ? $unitCost : null;
                        } else {
                            // 2. 廠內加工：透過 pm_process_daily_report + KPI 計算平均單顆成本
                            // bom_ref 對應 bom_ing.bom → pm_process_daily_report.bom_ing_fid
                            $kpsMap = [];
                            try {
                                $kpRows = $pdo->query("
                                    SELECT CONCAT(k.process_no,'_',COALESCE(k.d_setting_id,0)) AS mapkey,
                                           k.base_time_sec AS base_t, k.coefficient AS coeff,
                                           k.base_price AS base_p, k.multiplier
                                    FROM kpi_part_standard k
                                ")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($kpRows as $kp) $kpsMap[$kp['mapkey']] = $kp;
                                $kdRows = $pdo->query("
                                    SELECT CONCAT(g.process_no,'_0') AS mapkey,
                                           d.base_time_sec AS base_t, def.default_coefficient AS coeff,
                                           d.base_price AS base_p, 1 AS multiplier
                                    FROM kpi_std_time_default d
                                    JOIN kpi_process_group g ON g.group_id=d.group_id
                                    LEFT JOIN kpi_difficulty_default def ON def.group_id=d.group_id
                                ")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($kdRows as $kd) if (!isset($kpsMap[$kd['mapkey']])) $kpsMap[$kd['mapkey']] = $kd;
                            } catch(Exception $e2){}

                            // 齒輪資料
                            $gearData = null;
                            try {
                                $gd = $pdo->prepare("SELECT ds.Type, dg.Module, dg.Teeth, dg.Face_Width FROM d_setting ds LEFT JOIN d_setting_gear dg ON dg.d_id=ds.d_id WHERE ds.d_id=?");
                                $gd->execute([$dsid]); $gearData = $gd->fetch(PDO::FETCH_ASSOC);
                            } catch(Exception $e2){}

                            $rptRows = $pdo->prepare("
                                SELECT r.produced_qty, bi.process_no
                                FROM pm_process_daily_report r
                                JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
                                WHERE bi.bom = ?
                            ");
                            $rptRows->execute([$bomRef]);
                            $reports = $rptRows->fetchAll(PDO::FETCH_ASSOC);

                            $totalAmount = 0; $totalQty = 0;
                            foreach ($reports as $rpt) {
                                $procNo = $rpt['process_no'] ?? '';
                                $qty    = floatval($rpt['produced_qty'] ?? 0);
                                if ($qty <= 0) continue;
                                $kps = $kpsMap[$procNo.'_'.$dsid] ?? $kpsMap[$procNo.'_0'] ?? null;
                                if (!$kps) continue;
                                $coeff = floatval($kps['coeff'] ?? 1);
                                $baseT = floatval($kps['base_t'] ?? 0);
                                $baseP = floatval($kps['base_p'] ?? 0);
                                $multi = floatval($kps['multiplier'] ?? 1);
                                if ($gearData && $gearData['Type'] === 'G' && floatval($gearData['Module'] ?? 0) > 0) {
                                    $amount = $baseT * floatval($gearData['Module']) * floatval($gearData['Teeth']) * floatval($gearData['Face_Width']) * $coeff * $baseP * $qty;
                                } else {
                                    $amount = $baseT * $coeff * $multi * $baseP * $qty;
                                }
                                $totalAmount += $amount;
                                $totalQty    += $qty;
                            }
                            $costVal = ($totalQty > 0) ? ($totalAmount / $totalQty) : null;
                        }
                    } catch(Exception $e2){}
                }
                if ($costVal !== null) { $updCost->execute([$costVal, $sid]); $updated++; }

                // ── 售價快照 ──
                $priceVal = null;
                // 優先用組合件群組綁定的訂單售價
                if (!empty($row['group_id']) && isset($groupOrderMap[$row['group_id']])) {
                    $priceVal = $groupOrderMap[$row['group_id']];
                } elseif ($ordRef) {
                    try {
                        $op = $pdo->prepare("SELECT unit_price, modified_unit_price FROM order_track WHERE Order_id=?");
                        $op->execute([$ordRef]); $opr = $op->fetch(PDO::FETCH_ASSOC);
                        if ($opr) {
                            $priceVal = floatval($opr['modified_unit_price'] ?? 0) > 0
                                ? floatval($opr['modified_unit_price'])
                                : (floatval($opr['unit_price'] ?? 0) > 0 ? floatval($opr['unit_price']) : null);
                        }
                    } catch(Exception $e2){}
                }
                if ($priceVal !== null) { $updPrice->execute([$priceVal, $sid]); $updated++; }
            }

            echo json_encode(['success'=>true,'updated'=>$updated]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }


    if ($_POST['action'] === 'get_groups') {
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'stock_item_groups'")->fetchColumn();
            if (!$tableCheck) { echo json_encode(['success'=>true,'data'=>[]]); exit; }
            $rows = $pdo->query("SELECT sig.group_id, sig.group_name AS group_name_id, ds.D_Setting_Id AS group_name FROM stock_item_groups sig LEFT JOIN d_setting ds ON ds.d_id=sig.group_name ORDER BY sig.group_id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 異動歷程拆分（A權限） ─────────────────────
    if ($_POST['action'] === 'split_txn_items') {
        try {
            // 權限檢查
            $chkPerm=$pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock'");
            $chkPerm->execute([$userId]); $permRow3=$chkPerm->fetch(PDO::FETCH_ASSOC);
            if (!$permRow3 || $permRow3['permission']!=='A') throw new Exception('拆分功能需要 A 級權限');

            $stockItemId = intval($_POST['stock_item_id']??0);
            if (!$stockItemId) throw new Exception('未指定庫存品項');

            $txnIds = json_decode($_POST['txn_ids']??'[]', true);
            if (empty($txnIds)) throw new Exception('未選取要搬移的異動紀錄');
            
            $targetLocId = intval($_POST['target_location_id']??0) ?: null;
            $targetLocCode = trim($_POST['target_location_code']??'') ?: null;
            $targetUnitId = intval($_POST['target_unit_id']??0) ?: null;
            $confirmQty = floatval($_POST['confirm_total_qty']??0);

            $origSt = $pdo->prepare("SELECT * FROM stock_items WHERE stock_item_id=? AND is_active=1");
            $origSt->execute([$stockItemId]); $orig = $origSt->fetch(PDO::FETCH_ASSOC);
            if (!$orig) throw new Exception('找不到原始庫存品項');

            // 計算選取的異動總量
            $inSql = implode(',', array_fill(0, count($txnIds), '?'));
            $sumSt = $pdo->prepare("SELECT SUM(txn_qty) FROM stock_transactions WHERE txn_id IN ($inSql) AND stock_item_id=?");
            $sumSt->execute(array_merge($txnIds, [$stockItemId]));
            $actualSum = round(floatval($sumSt->fetchColumn()), 4);

            // 移除強制等量檢查，允許使用者輸入數字作為最終庫存（用於修正帳面誤差）
            // if (abs($actualSum - $confirmQty) > 0.0001) throw new Exception("確認數量({$confirmQty})與選取紀錄總和({$actualSum})不符");

            $pdo->beginTransaction();

            // 1. 建立新的 stock_item (複製原品項多數資訊)
            $existCols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $copyCols = array_diff($existCols, ['stock_item_id', 'qty', 'location_id', 'storage_location', 'unit_id', 'Created_By', 'Modified_By', 'Created_At', 'Modified_At']);
            $sqlCols = implode(',', array_map(fn($c)=>"`$c`", $copyCols));
            $pdo->prepare("INSERT INTO stock_items ($sqlCols, qty, location_id, storage_location, unit_id, Created_By, Modified_By) 
                           SELECT $sqlCols, 0, ?, ?, ?, ?, ? FROM stock_items WHERE stock_item_id=?")
                ->execute([$targetLocId, $targetLocCode, $targetUnitId, $userId, $userId, $stockItemId]);
            $newId = $pdo->lastInsertId();

            // 2. 搬移選取的異動紀錄到新 ID
            $pdo->prepare("UPDATE stock_transactions SET stock_item_id=? WHERE txn_id IN ($inSql)")
                ->execute(array_merge([$newId], $txnIds));

            // 3. 更新庫存總額
            $newOrigQty = isset($_POST['remain_total_qty']) ? floatval($_POST['remain_total_qty']) : round(floatval($orig['qty']) - $confirmQty, 4);
            if ($newOrigQty < 0) $newOrigQty = 0;
            $pdo->prepare("UPDATE stock_items SET qty=?, Modified_At=NOW() WHERE stock_item_id=?")->execute([$newOrigQty, $stockItemId]);
            $pdo->prepare("UPDATE stock_items SET qty=?, Modified_At=NOW() WHERE stock_item_id=?")->execute([$confirmQty, $newId]);

            // 4. 寫入調整日誌
            $diffOrig = round($newOrigQty - $orig['qty'], 4);
            $pdo->prepare("INSERT INTO stock_transactions (stock_item_id, d_id, txn_type, txn_qty, qty_before, qty_after, txn_date, remark, Created_By) 
                           VALUES (?,?,'adjust',?,?,?,CURDATE(),?,?)")
                ->execute([$stockItemId, $orig['d_id'], $diffOrig, $orig['qty'], $newOrigQty, "部分紀錄搬移至新庫存品項#$newId (搬移量:$confirmQty)", $userId]);
            
            $pdo->prepare("INSERT INTO stock_transactions (stock_item_id, d_id, txn_type, txn_qty, qty_before, qty_after, txn_date, remark, Created_By) 
                           VALUES (?,?,'adjust',?,?,?,CURDATE(),?,?)")
                ->execute([$newId, $orig['d_id'], $confirmQty, 0, $confirmQty, "由庫存品項#$stockItemId 拆分建立 (搬移紀錄數:".count($txnIds).")", $userId]);
            
            $pdo->commit();
            echo json_encode(['success'=>true,'message'=>"拆分成功！已建立新庫存品項，搬移總量：$confirmQty",'new_id'=>$newId]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ══════════════════════════════════════════════════════
    //  領庫需求單 AJAX
    // ══════════════════════════════════════════════════════

    // ── 搜尋庫存料號（每個儲位獨立顯示，組合件仍以整組呈現） ──
    if ($_POST['action'] === 'req_search_items') {
        try {
            $kw=trim($_POST['kw']??'');
            $clientFilter=trim($_POST['client_filter']??'');
            $catFilter=intval($_POST['cat_filter']??0);
            $where=['si.is_active=1']; $params=[];
            // kw 只比對料號和備註（客戶與種類由獨立篩選欄處理）
            // 客戶為料號綁定屬性，一律即時查 d_setting/customer_list，不信任 si.client_name 舊快照
            $cliJoinQ='LEFT JOIN d_setting dspq ON dspq.d_id=si.d_setting_id LEFT JOIN customer_list clp ON clp.customer_id=dspq.Customer_Id';
            if($kw!==''){ $lk='%'.$kw.'%'; $where[]="(si.d_id LIKE ? OR si.remark1 LIKE ? OR si.remark2 LIKE ?)"; $params=[$lk,$lk,$lk]; }
            if($clientFilter!==''){ $where[]="clp.customer LIKE ?"; $params[]='%'.$clientFilter.'%'; }
            if($catFilter>0){ $where[]="si.item_type=?"; $params[]=$catFilter; }
            $whereSql=implode(' AND ',$where);
            // 欄位探測（只查一次）
            $siColsQ=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasLocIdQ=in_array('location_id',$siColsQ); $hasGroupIdQ=in_array('group_id',$siColsQ);
            $catJoin='LEFT JOIN stock_item_categories sic ON sic.category_id=si.item_type';
            $locJoinQ=$hasLocIdQ?"LEFT JOIN stock_locations sl ON sl.location_id=si.location_id":"";
            $locSelQ=$hasLocIdQ?"COALESCE(sl.location_code,si.storage_location,'')":"COALESCE(si.storage_location,'')";
            $grpNull=$hasGroupIdQ?" AND (si.group_id IS NULL OR si.group_id=0)":"";

            // ① 非組合件：依 d_id+client_name+item_type 合併為一列，加總庫存，收集所有儲位
            $st=$pdo->prepare("SELECT si.stock_item_id,si.d_id,clp.customer AS client_name,$locSelQ AS storage_location,si.qty,si.remark1,si.unit_id,si.item_type,COALESCE(sic.category_name,'') AS category_name FROM stock_items si $catJoin $locJoinQ $cliJoinQ WHERE $whereSql$grpNull ORDER BY si.d_id ASC,si.stock_item_id ASC LIMIT 600");
            $st->execute($params); $rawNonGroup=$st->fetchAll(PDO::FETCH_ASSOC);
            $groupedMap=[];
            foreach($rawNonGroup as $r){
                $gkey=$r['d_id'].'||'.($r['client_name']??'').'||'.($r['item_type']??'');
                if(!isset($groupedMap[$gkey])){ $groupedMap[$gkey]=['stock_item_id'=>$r['stock_item_id'],'d_id'=>$r['d_id'],'client_name'=>$r['client_name']??'','total_qty'=>0,'locs'=>[],'remark1'=>$r['remark1']??'','unit_id'=>$r['unit_id'],'item_type'=>$r['item_type']??'','category_name'=>$r['category_name']??'']; }
                $groupedMap[$gkey]['total_qty']+=floatval($r['qty']);
                $loc=trim($r['storage_location']??'');
                if($loc!==''&&!in_array($loc,$groupedMap[$gkey]['locs'])) $groupedMap[$gkey]['locs'][]=$loc;
            }
            $nonGroupRows=[];
            foreach($groupedMap as $g){
                if(floatval($g['total_qty'])<=0) continue; // 領庫需求單不可選庫存<=0的料號
                $nonGroupRows[]=['stock_item_id'=>$g['stock_item_id'],'d_id'=>$g['d_id'],'client_name'=>$g['client_name'],'qty'=>$g['total_qty'],'total_qty'=>$g['total_qty'],'storage_location'=>implode(', ',$g['locs']),'remark1'=>$g['remark1'],'unit_id'=>$g['unit_id'],'item_type'=>$g['item_type'],'category_name'=>$g['category_name'],'is_group_item'=>false,'group_qty'=>0];
            }

            // ② 組合件：仍以整組顯示（floor(qty/pcs_per_set) 最小值 = 可領組數）
            //    kw 除了比對子件料號/備註，也比對組合名稱（stock_item_groups.group_name → d_setting.D_Setting_Id），
            //    例如搜「RB-93-2+RB-103-1+RB-103-2」或其中任一子件料號都能找到整組；
            //    先找出符合的 group_id，再撈整組全部子件計算可領組數，顯示名稱用組合名稱
            $groupRows=[];
            if($hasGroupIdQ){
                $hasPcsCol=in_array('pcs_per_set',$siColsQ);
                $pcsSel=$hasPcsCol?',si.pcs_per_set':'';
                $gWhere=['si.is_active=1','si.group_id IS NOT NULL','si.group_id>0']; $gParams=[];
                if($kw!==''){ $lk2='%'.$kw.'%'; $gWhere[]="(si.d_id LIKE ? OR si.remark1 LIKE ? OR si.remark2 LIKE ? OR gds.D_Setting_Id LIKE ?)"; $gParams=[$lk2,$lk2,$lk2,$lk2]; }
                if($clientFilter!==''){ $gWhere[]="clp.customer LIKE ?"; $gParams[]='%'.$clientFilter.'%'; }
                if($catFilter>0){ $gWhere[]="si.item_type=?"; $gParams[]=$catFilter; }
                $gIdSt=$pdo->prepare("SELECT DISTINCT si.group_id FROM stock_items si LEFT JOIN stock_item_groups sig ON sig.group_id=si.group_id LEFT JOIN d_setting gds ON gds.d_id=sig.group_name $cliJoinQ WHERE ".implode(' AND ',$gWhere)." LIMIT 100");
                $gIdSt->execute($gParams); $gIds=array_map('intval',$gIdSt->fetchAll(PDO::FETCH_COLUMN));
                $gGrouped=[];
                if($gIds){
                    $gPh=implode(',',array_fill(0,count($gIds),'?'));
                    $gSt=$pdo->prepare("SELECT si.stock_item_id,si.d_id,clp.customer AS client_name,$locSelQ AS storage_location,si.qty$pcsSel,si.remark1,si.unit_id,si.item_type,si.group_id,COALESCE(sic.category_name,'') AS category_name,COALESCE(gds.D_Setting_Id,'') AS group_display_name FROM stock_items si $catJoin $locJoinQ LEFT JOIN stock_item_groups sig ON sig.group_id=si.group_id LEFT JOIN d_setting gds ON gds.d_id=sig.group_name $cliJoinQ WHERE si.is_active=1 AND si.group_id IN ($gPh) ORDER BY si.group_id ASC,si.stock_item_id ASC");
                    $gSt->execute($gIds); $gRawRows=$gSt->fetchAll(PDO::FETCH_ASSOC);
                    foreach($gRawRows as $gr){
                        $gkey=intval($gr['group_id']);
                        if(!isset($gGrouped[$gkey])){ $gGrouped[$gkey]=['stock_item_id'=>$gr['stock_item_id'],'d_id'=>($gr['group_display_name']!==''?$gr['group_display_name']:$gr['d_id']),'client_name'=>$gr['client_name']??'','group_qty'=>PHP_INT_MAX,'locs'=>[],'remark1'=>$gr['remark1']??'','unit_id'=>$gr['unit_id'],'item_type'=>$gr['item_type']??'','category_name'=>$gr['category_name']??'']; }
                        $pcs=$hasPcsCol?max(1,intval($gr['pcs_per_set']??1)):1;
                        $avail=floor(floatval($gr['qty'])/$pcs);
                        if($avail<$gGrouped[$gkey]['group_qty']) $gGrouped[$gkey]['group_qty']=$avail;
                        if($gr['storage_location']&&!in_array($gr['storage_location'],$gGrouped[$gkey]['locs'])) $gGrouped[$gkey]['locs'][]=$gr['storage_location'];
                    }
                }
                // 同組合名稱+客戶+種類的多個 group 合併為一列（可領組數加總，與出庫時逐儲位批次一致）
                $gMerged=[];
                foreach($gGrouped as $g){
                    $gQty=$g['group_qty']===PHP_INT_MAX?0:$g['group_qty'];
                    if($gQty<=0) continue; // 領庫需求單不可選可領組數<=0的組合件
                    $mkey=$g['d_id'].'||'.$g['client_name'].'||'.$g['item_type'];
                    if(!isset($gMerged[$mkey])){ $g['group_qty']=$gQty; $gMerged[$mkey]=$g; }
                    else { $gMerged[$mkey]['group_qty']+=$gQty; foreach($g['locs'] as $l){ if(!in_array($l,$gMerged[$mkey]['locs'])) $gMerged[$mkey]['locs'][]=$l; } }
                }
                foreach($gMerged as $g){
                    $groupRows[]=['stock_item_id'=>$g['stock_item_id'],'d_id'=>$g['d_id'],'client_name'=>$g['client_name'],'qty'=>$g['group_qty'],'group_qty'=>$g['group_qty'],'storage_location'=>implode(', ',$g['locs']),'remark1'=>$g['remark1'],'unit_id'=>$g['unit_id'],'item_type'=>$g['item_type'],'category_name'=>$g['category_name'],'is_group_item'=>true];
                }
            }

            $rows=array_merge($nonGroupRows,$groupRows);
            $units=[];
            try{ foreach($pdo->query("SELECT unit_id,unit_name,unit_symbol FROM stock_units WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $u) $units[$u['unit_id']]=$u; }catch(Exception $e){}
            foreach($rows as &$r){ $uid=$r['unit_id']; $r['unit_label']=$uid&&isset($units[$uid])?($units[$uid]['unit_symbol']?:$units[$uid]['unit_name']):''; }
            echo json_encode(['success'=>true,'items'=>array_values($rows)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 建立需求單 ──
    if ($_POST['action'] === 'create_requisition') {
        try {
            $title=trim($_POST['title']??''); $deptName=trim($_POST['dept_name']??'');
            $requesterName=trim($_POST['requester_name']??''); $remark=trim($_POST['req_remark']??'');
            $items=json_decode($_POST['items']??'[]',true);
            if(empty($items)) throw new Exception('請至少選擇一筆料號');
            $today=date('Ymd');
            $lastNo=$pdo->query("SELECT MAX(req_no) FROM stock_requisitions WHERE req_no LIKE 'REQ-$today-%'")->fetchColumn();
            $seq=$lastNo?(intval(substr($lastNo,-3))+1):1;
            $reqNo=sprintf('REQ-%s-%03d',$today,$seq);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO stock_requisitions (req_no,title,dept_name,requester_name,req_remark,Created_By) VALUES (?,?,?,?,?,?)")
                ->execute([$reqNo,$title?:null,$deptName?:null,$requesterName?:null,$remark?:null,$userId]);
            $reqId=$pdo->lastInsertId(); $sort=0;
            foreach($items as $item){
                $pdo->prepare("INSERT INTO stock_requisition_items (req_id,stock_item_id,d_id,client_name,storage_location,qty_requested,item_remark,is_urgent,sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$reqId,intval($item['stock_item_id'])?:null,$item['d_id']??'',$item['client_name']??'',$item['storage_location']??'',max(1,intval($item['qty']??1)),trim($item['remark']??'')?:null,intval($item['is_urgent']??0),$sort++]);
            }
            $pdo->commit();
            $notifTargets=getNotifTargetUsers($pdo);
            insertReqNotifications($pdo,$notifTargets,$reqId,$reqNo,'new','新領料單：'.$reqNo.($title?' — '.$title:''));
            echo json_encode(['success'=>true,'req_id'=>$reqId,'req_no'=>$reqNo]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 需求單列表 ──
    if ($_POST['action'] === 'get_requisitions') {
        try {
            $status=$_POST['status']??'all'; $kw=trim($_POST['kw']??''); $page=max(1,intval($_POST['page']??1));
            $ps=in_array(intval($_POST['page_size']??20),[10,20,30])?intval($_POST['page_size']??20):20;
            $offset=($page-1)*$ps;
            $where='(rq.is_active=1 OR rq.is_active IS NULL)'; $params=[];
            if($status!=='all'&&$status!=='') { $where.=' AND rq.status=?'; $params[]=intval($status); }
            if($kw!=='') { $where.=" AND (rq.req_no LIKE ? OR rq.title LIKE ? OR rq.dept_name LIKE ? OR rq.requester_name LIKE ?)"; $lk='%'.$kw.'%'; $params=array_merge($params,[$lk,$lk,$lk,$lk]); }
            $cst=$pdo->prepare("SELECT COUNT(*) FROM stock_requisitions rq WHERE $where"); $cst->execute($params); $totalCount=intval($cst->fetchColumn());
            $st=$pdo->prepare("SELECT rq.*,u.user_cname AS creator_name FROM stock_requisitions rq LEFT JOIN user u ON u.id=rq.Created_By WHERE $where ORDER BY rq.Created_At DESC LIMIT $ps OFFSET $offset");
            $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
            $reqIds=array_column($rows,'req_id'); $itemCounts=[];
            if($reqIds){ $inSql=implode(',',array_fill(0,count($reqIds),'?'));
                $cst2=$pdo->prepare("SELECT req_id,COUNT(*) AS cnt,SUM(CASE WHEN qty_issued>=qty_requested THEN 1 ELSE 0 END) AS done,SUM(CASE WHEN is_urgent=1 THEN 1 ELSE 0 END) AS urgent_cnt FROM stock_requisition_items WHERE req_id IN ($inSql) GROUP BY req_id");
                $cst2->execute($reqIds); foreach($cst2->fetchAll(PDO::FETCH_ASSOC) as $c) $itemCounts[$c['req_id']]=$c; }
            foreach($rows as &$r){ $r['item_cnt']=$itemCounts[$r['req_id']]['cnt']??0; $r['done_cnt']=$itemCounts[$r['req_id']]['done']??0; $r['urgent_cnt']=$itemCounts[$r['req_id']]['urgent_cnt']??0; }
            echo json_encode(['success'=>true,'rows'=>$rows,'total'=>$totalCount,'pages'=>(int)ceil($totalCount/$ps),'page'=>$page]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 需求單詳情 ──
    if ($_POST['action'] === 'get_req_detail') {
        try {
            $reqId=intval($_POST['req_id']??0);
            $rq=$pdo->prepare("SELECT rq.*,u.user_cname AS creator_name FROM stock_requisitions rq LEFT JOIN user u ON u.id=rq.Created_By WHERE rq.req_id=?");
            $rq->execute([$reqId]); $req=$rq->fetch(PDO::FETCH_ASSOC);
            if(!$req) throw new Exception('找不到需求單');
            $items=$pdo->prepare("SELECT ri.*,si.item_type,si.group_id,COALESCE(sic.category_name,'') AS category_name FROM stock_requisition_items ri LEFT JOIN stock_items si ON si.stock_item_id=ri.stock_item_id LEFT JOIN stock_item_categories sic ON sic.category_id=si.item_type WHERE ri.req_id=? ORDER BY ri.sort_order,ri.req_item_id");
            $items->execute([$reqId]); $reqItems=$items->fetchAll(PDO::FETCH_ASSOC);
            // 計算各品項當前總庫存（合計所有同料號的非組合件儲位，反映實際可領總量；客戶為料號綁定屬性不再比對）
            $sumQ=$pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM stock_items WHERE d_id=? AND is_active=1 AND (group_id IS NULL OR group_id=0)");
            $grpMemQ=$pdo->prepare("SELECT qty,pcs_per_set FROM stock_items WHERE group_id=? AND is_active=1");
            // 查詢所有現有儲位（與 req_search_items 合併邏輯一致，讓修改畫面也能顯示多儲位）
            $siColsDet=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasLocIdDet=in_array('location_id',$siColsDet);
            $locSelDet=$hasLocIdDet?"COALESCE(sl.location_code,si.storage_location,'')":"COALESCE(si.storage_location,'')";
            $locJoinDet=$hasLocIdDet?"LEFT JOIN stock_locations sl ON sl.location_id=si.location_id":"";
            $allLocQ=$pdo->prepare("SELECT $locSelDet AS loc FROM stock_items si $locJoinDet WHERE si.d_id=? AND si.is_active=1 AND (si.group_id IS NULL OR si.group_id=0)");
            foreach($reqItems as &$ri){
                if(!empty($ri['group_id'])){
                    // 組合件：floor(qty/pcs_per_set) 最小值 = 可領組數
                    $grpMemQ->execute([$ri['group_id']]); $mems=$grpMemQ->fetchAll(PDO::FETCH_ASSOC);
                    $minSets=PHP_INT_MAX;
                    foreach($mems as $m){ $pcs=max(1,intval($m['pcs_per_set']??1)); $s=floor(floatval($m['qty'])/$pcs); if($s<$minSets) $minSets=$s; }
                    $ri['current_qty']=$minSets===PHP_INT_MAX?0:(float)$minSets;
                    $ri['all_locations']='';
                } else {
                    // 非組合件：加總所有同料號的儲位庫存，並收集所有儲位
                    $sumQ->execute([$ri['d_id']??'']);
                    $ri['current_qty']=(float)$sumQ->fetchColumn();
                    $allLocQ->execute([$ri['d_id']??'']);
                    $locs=array_filter(array_unique(array_column($allLocQ->fetchAll(PDO::FETCH_ASSOC),'loc')));
                    $ri['all_locations']=implode(', ',$locs);
                }
            }
            $req['items']=$reqItems;
            echo json_encode(['success'=>true,'req'=>$req]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 更新需求單 ──
    if ($_POST['action'] === 'update_requisition') {
        try {
            $reqId=intval($_POST['req_id']??0);
            $rq=$pdo->prepare("SELECT req_no,Created_By,status FROM stock_requisitions WHERE req_id=? AND (is_active=1 OR is_active IS NULL)"); $rq->execute([$reqId]); $req=$rq->fetch(PDO::FETCH_ASSOC);
            if(!$req) throw new Exception('找不到需求單');
            if(intval($req['status'])===2) throw new Exception('已完成出庫的需求單不可修改');
            $pp=$pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock'"); $pp->execute([$userId]);
            $p=($pp->fetch(PDO::FETCH_ASSOC)['permission']??'');
            $isCRUD=strpos($p,'C')!==false&&strpos($p,'R')!==false&&strpos($p,'U')!==false&&strpos($p,'D')!==false;
            if($req['Created_By']!=$userId&&$p!=='A'&&!$isCRUD) throw new Exception('無修改權限');
            $title=trim($_POST['title']??''); $deptName=trim($_POST['dept_name']??''); $requesterName=trim($_POST['requester_name']??''); $remark=trim($_POST['req_remark']??'');
            $items=json_decode($_POST['items_json']??'[]',true);
            $newItems=json_decode($_POST['new_items']??'[]',true);
            $deletedIds=json_decode($_POST['deleted_ids']??'[]',true);
            // 檢查修改數量不得低於已出庫數量
            foreach($items as $item){ $riId=intval($item['req_item_id']??0); if(!$riId) continue;
                $chk=$pdo->prepare("SELECT d_id,qty_issued FROM stock_requisition_items WHERE req_item_id=? AND req_id=?"); $chk->execute([$riId,$reqId]); $ri=$chk->fetch(PDO::FETCH_ASSOC);
                if($ri&&floatval($item['qty_requested']??0)<floatval($ri['qty_issued']??0)) throw new Exception('料號 '.$ri['d_id'].' 修改後數量不得低於已出庫量（'.$ri['qty_issued'].'）'); }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE stock_requisitions SET title=?,dept_name=?,requester_name=?,req_remark=?,Modified_By=? WHERE req_id=?")
                ->execute([$title?:null,$deptName?:null,$requesterName?:null,$remark?:null,$userId,$reqId]);
            // 更新現有料號
            foreach($items as $item){ $riId=intval($item['req_item_id']??0);
                if($riId) $pdo->prepare("UPDATE stock_requisition_items SET qty_requested=?,item_remark=?,is_urgent=? WHERE req_item_id=? AND req_id=?")
                    ->execute([max(1,floatval($item['qty_requested']??1)),trim($item['item_remark']??'')?:null,intval($item['is_urgent']??0),$riId,$reqId]); }
            // 刪除指定料號
            foreach($deletedIds as $dId){ $dId=intval($dId); if($dId) $pdo->prepare("DELETE FROM stock_requisition_items WHERE req_item_id=? AND req_id=?")->execute([$dId,$reqId]); }
            // 新增料號
            $sortRow=$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM stock_requisition_items WHERE req_id=$reqId")->fetchColumn();
            foreach($newItems as $ni){
                $pdo->prepare("INSERT INTO stock_requisition_items (req_id,stock_item_id,d_id,client_name,storage_location,qty_requested,item_remark,is_urgent,sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$reqId,intval($ni['stock_item_id'])?:null,$ni['d_id']??'',$ni['client_name']??'',$ni['storage_location']??'',max(1,floatval($ni['qty']??1)),trim($ni['remark']??'')?:null,intval($ni['is_urgent']??0),$sortRow++]);
            }
            // 依修改後的數量重算需求單狀態：全部已達請領量→已完成(2)；尚有出庫量→部分(1)；否則待出庫(0)
            $iStat=$pdo->prepare("SELECT COUNT(*) AS total,SUM(CASE WHEN qty_issued>=qty_requested THEN 1 ELSE 0 END) AS done,COALESCE(SUM(qty_issued),0) AS issued_sum FROM stock_requisition_items WHERE req_id=?");
            $iStat->execute([$reqId]); $iS=$iStat->fetch(PDO::FETCH_ASSOC);
            $newStatus=($iS['total']>0&&$iS['done']>=$iS['total'])?2:(floatval($iS['issued_sum'])>0?1:0);
            if($newStatus!=intval($req['status'])){
                if($newStatus===2)
                    $pdo->prepare("UPDATE stock_requisitions SET status=?,issued_at=COALESCE(issued_at,NOW()),Modified_By=? WHERE req_id=?")->execute([$newStatus,$userId,$reqId]);
                else
                    $pdo->prepare("UPDATE stock_requisitions SET status=?,Modified_By=? WHERE req_id=?")->execute([$newStatus,$userId,$reqId]);
            }
            $pdo->commit();
            $notifTargets=getNotifTargetUsers($pdo);
            insertReqNotifications($pdo,$notifTargets,$reqId,$req['req_no'],'modified','領料單已修改：'.$req['req_no'].($title?' — '.$title:''));
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 刪除需求單（軟刪除）──
    if ($_POST['action'] === 'delete_requisition') {
        try {
            $reqId=intval($_POST['req_id']??0);
            $deleteReason=trim($_POST['delete_reason']??'');
            if(!$deleteReason) throw new Exception('請輸入刪除原因');
            $rq=$pdo->prepare("SELECT req_no,Created_By FROM stock_requisitions WHERE req_id=? AND (is_active=1 OR is_active IS NULL)"); $rq->execute([$reqId]); $req=$rq->fetch(PDO::FETCH_ASSOC);
            if(!$req) throw new Exception('找不到需求單');
            $pp=$pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock'"); $pp->execute([$userId]);
            $p=($pp->fetch(PDO::FETCH_ASSOC)['permission']??'');
            $isCRUD=strpos($p,'C')!==false&&strpos($p,'R')!==false&&strpos($p,'U')!==false&&strpos($p,'D')!==false;
            if($req['Created_By']!=$userId&&$p!=='A'&&!$isCRUD) throw new Exception('無刪除權限');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE stock_requisitions SET is_active=0,deleted_by=?,deleted_at=NOW(),delete_reason=?,Modified_By=? WHERE req_id=?")
                ->execute([$userId,$deleteReason,$userId,$reqId]);
            $pdo->commit();
            // 通知建立者（若非自己刪的）
            if($req['Created_By']&&intval($req['Created_By'])!==$userId){
                try{ $pdo->prepare("INSERT INTO stock_req_notifications (user_id,req_id,req_no,type,message) VALUES (?,?,?,'deleted',?)")
                    ->execute([$req['Created_By'],$reqId,$req['req_no'],'您的領料單 '.$req['req_no'].' 已被刪除，原因：'.$deleteReason]); }catch(Exception $e){}
            }
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 已刪除需求單列表 ──
    if ($_POST['action'] === 'get_deleted_requisitions') {
        try {
            $st=$pdo->query("SELECT rq.req_id,rq.req_no,rq.title,rq.dept_name,rq.requester_name,rq.deleted_at,rq.delete_reason,ud.user_cname AS deleted_by_name,uc.user_cname AS creator_name FROM stock_requisitions rq LEFT JOIN user uc ON uc.id=rq.Created_By LEFT JOIN user ud ON ud.id=rq.deleted_by WHERE rq.is_active=0 ORDER BY rq.deleted_at DESC LIMIT 200");
            echo json_encode(['success'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 未讀通知 ──
    if ($_POST['action'] === 'get_unread_notifications') {
        try {
            $st=$pdo->prepare("SELECT * FROM stock_req_notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 30");
            $st->execute([$userId]); echo json_encode(['success'=>true,'notifications'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>true,'notifications'=>[]]); }
        exit;
    }

    // ── 標記通知已讀 ──
    if ($_POST['action'] === 'mark_notification_read') {
        try {
            $nid=intval($_POST['notif_id']??0);
            if($nid) $pdo->prepare("UPDATE stock_req_notifications SET is_read=1 WHERE notif_id=? AND user_id=?")->execute([$nid,$userId]);
            else $pdo->prepare("UPDATE stock_req_notifications SET is_read=1 WHERE user_id=?")->execute([$userId]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){ echo json_encode(['success'=>false]); }
        exit;
    }

    // ── 取得品項批次（領庫用） ──
    if ($_POST['action'] === 'req_get_batches') {
        try {
            $sid=intval($_POST['stock_item_id']??0); if(!$sid) throw new Exception('未指定品項');
            $siRow=$pdo->prepare("SELECT stock_item_id,d_id,qty,group_id,pcs_per_set,COALESCE(client_name,'') AS client_name FROM stock_items WHERE stock_item_id=? AND is_active=1");
            $siRow->execute([$sid]); $item=$siRow->fetch(PDO::FETCH_ASSOC);
            if(!$item) throw new Exception('找不到品項');
            // 組合件：列出「含此組合件料號」的所有 group（同客戶／種類），每個 group（＝一個儲位）一列批次，
            //          供使用者逐儲位選擇出庫組數（每個 group 的可用組數＝其各子件 floor(qty/pcs_per_set) 的最小值）
            if(!empty($item['group_id'])){
                $siColsG=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
                $hasLocIdG=in_array('location_id',$siColsG);
                $hasItemTypeG=in_array('item_type',$siColsG);
                // 本料號種類（避免混入不同種類的同料號）
                $itemTypeValG=null;
                if($hasItemTypeG){
                    try { $itq=$pdo->prepare("SELECT item_type FROM stock_items WHERE stock_item_id=?"); $itq->execute([$sid]); $itr=$itq->fetch(PDO::FETCH_ASSOC); if($itr && $itr['item_type']!==null && $itr['item_type']!=='') $itemTypeValG=intval($itr['item_type']); } catch(Exception $e2){}
                }
                $locJoinG=$hasLocIdG?"LEFT JOIN stock_locations sgl ON sgl.location_id=si.location_id":"";
                $locSelG=$hasLocIdG?"COALESCE(sgl.location_code,si.storage_location,'')":"COALESCE(si.storage_location,'')";
                // 客戶為料號綁定屬性，同料號必同客戶，只需依 d_id 比對即可
                $whereG="si.d_id=? AND si.group_id IS NOT NULL AND si.group_id>0 AND si.is_active=1";
                $paramsG=[$item['d_id']];
                if($hasItemTypeG){
                    if($itemTypeValG!==null){ $whereG.=" AND si.item_type=?"; $paramsG[]=$itemTypeValG; }
                    else { $whereG.=" AND (si.item_type IS NULL OR si.item_type=0)"; }
                }
                $gq=$pdo->prepare("SELECT si.group_id, {$locSelG} AS loc FROM stock_items si {$locJoinG} WHERE {$whereG} ORDER BY si.group_id");
                $gq->execute($paramsG); $grows=$gq->fetchAll(PDO::FETCH_ASSOC);
                $batches=[]; $totalSets=0; $seenG=[];
                foreach($grows as $gr){
                    $gid=intval($gr['group_id']); if(!$gid || isset($seenG[$gid])) continue; $seenG[$gid]=1;
                    $mems=$pdo->prepare("SELECT qty,COALESCE(pcs_per_set,1) AS pcs FROM stock_items WHERE group_id=? AND is_active=1");
                    $mems->execute([$gid]); $memRows=$mems->fetchAll(PDO::FETCH_ASSOC);
                    if(empty($memRows)) continue;
                    $minSets=PHP_INT_MAX;
                    foreach($memRows as $m){ $pcs=max(1,intval($m['pcs']??1)); $s=floor(floatval($m['qty'])/$pcs); if($s<$minSets) $minSets=$s; }
                    if($minSets===PHP_INT_MAX) $minSets=0;
                    if($minSets>0){ $batches[]=['batch_key'=>'group||'.$gid,'txn_date'=>'—','remark'=>'組合件整組','available'=>$minSets,'location'=>$gr['loc'],'stock_item_id'=>$sid]; $totalSets+=$minSets; }
                }
                echo json_encode(['success'=>true,'batches'=>$batches,'current_qty'=>$totalSets,'is_group_item'=>true,'available_sets'=>$totalSets]);
                exit;
            }
            // ── 查詢所有同料號+同客戶的儲位（多儲位批次支援）──
            // 儲位優先使用 stock_locations.location_code（透過 location_id JOIN）
            // 確保顯示的是最新儲位，而非入庫時的文字欄位 storage_location
            $did=$item['d_id']; $clientName=$item['client_name'];
            $siColsC=$pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $hasClientName=in_array('client_name',$siColsC);
            $hasGroupIdColB=in_array('group_id',$siColsC);
            $hasLocIdB=in_array('location_id',$siColsC);
            // JOIN stock_locations 取最新 location_code，回退到 storage_location 文字欄位
            $locJoinB=$hasLocIdB?"LEFT JOIN stock_locations slb ON slb.location_id=si.location_id":"";
            $locSelB=$hasLocIdB?"COALESCE(slb.location_code,si.storage_location,'')":"COALESCE(si.storage_location,'')";
            $grpNullFilterSi=$hasGroupIdColB?' AND (si.group_id IS NULL OR si.group_id=0)':'';
            // 同分類過濾：只抓與需求品項相同 item_type 的儲位，避免混入不同分類的同料號
            $hasItemTypeColB=in_array('item_type',$siColsC);
            $itemTypeFilterSi='';
            if($hasItemTypeColB){
                $itQ=$pdo->prepare("SELECT item_type FROM stock_items WHERE stock_item_id=?");
                $itQ->execute([$sid]); $itRow=$itQ->fetch(PDO::FETCH_ASSOC);
                $itemTypeVal=($itRow&&$itRow['item_type']!==null&&$itRow['item_type']!=='')?intval($itRow['item_type']):null;
                // 只比對完全相同的 item_type，不加 IS NULL 例外（避免把不同種類的同料號誤拉進來）
                if($itemTypeVal!==null) $itemTypeFilterSi=' AND si.item_type='.intval($itemTypeVal);
                else $itemTypeFilterSi=' AND (si.item_type IS NULL OR si.item_type=0)';
            }
            $extraFilterSi=$grpNullFilterSi.$itemTypeFilterSi;
            // 品項層級 BOM/訂單（作為批次顯示的兜底值：若入庫事務未記錄 bom_ref/order_ref，回退至品項本身所綁定的值）
            $hasSiBomRef=in_array('bom_ref',$siColsC); $hasSiOrderRef=in_array('order_ref',$siColsC);
            $siBomSel=$hasSiBomRef?", si.bom_ref AS item_bom_ref":", NULL AS item_bom_ref";
            // 用相關子查詢取訂單號，避免 LEFT JOIN order_track 產生重複列（Order_id 若不唯一會複製 stock_item 列）
            $siOrdSel=$hasSiOrderRef?",(SELECT ot_si2.Order_oo FROM order_track ot_si2 WHERE ot_si2.Order_id=si.order_ref LIMIT 1) AS item_order_no":", NULL AS item_order_no";
            // 客戶為料號綁定屬性，同料號必同客戶，只需依 d_id 比對即可
            $allSiQ=$pdo->prepare("SELECT si.stock_item_id,si.qty,{$locSelB} AS storage_location{$siBomSel}{$siOrdSel} FROM stock_items si {$locJoinB} WHERE si.d_id=? AND si.is_active=1{$extraFilterSi} ORDER BY si.stock_item_id");
            $allSiQ->execute([$did]);
            $allSiRows=$allSiQ->fetchAll(PDO::FETCH_ASSOC);
            if(empty($allSiRows)){
                // 兜底：查詢本筆正確儲位（含品項層級 BOM/訂單）
                $fbQ=$pdo->prepare("SELECT si.stock_item_id,si.qty,{$locSelB} AS storage_location{$siBomSel}{$siOrdSel} FROM stock_items si {$locJoinB} WHERE si.stock_item_id=?");
                $fbQ->execute([$sid]); $fbRow=$fbQ->fetch(PDO::FETCH_ASSOC);
                $allSiRows=[['stock_item_id'=>$sid,'qty'=>floatval($item['qty']),'storage_location'=>($fbRow['storage_location']??''),'item_bom_ref'=>($fbRow['item_bom_ref']??''),'item_order_no'=>($fbRow['item_order_no']??'')]];
            }
            $txnCols=$pdo->query("SHOW COLUMNS FROM stock_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasBomRef=in_array('bom_ref',$txnCols); $hasRemark=in_array('remark',$txnCols); $hasOrderRef=in_array('order_ref',$txnCols);
            $bomSel=$hasBomRef?"st.bom_ref AS txn_bom,":"NULL AS txn_bom,";
            $remarkSel=$hasRemark?"st.remark,":"NULL AS remark,";
            // JOIN order_track 取可讀訂單號（同 get_stock_detail 做法，避免只顯示數字 ID）
            $orderJoinB=$hasOrderRef?"LEFT JOIN order_track ot_r ON ot_r.Order_id=st.order_ref":"";
            $orderSel=$hasOrderRef?",ot_r.Order_oo AS order_no":"";
            $allBatches=[]; $totalQty=0;
            foreach($allSiRows as $si){
                $siId=intval($si['stock_item_id']); $siQty=floatval($si['qty']); $siLoc=$si['storage_location'];
                // 品項層級的 BOM/訂單兜底值（若入庫事務未填寫則回退）
                $itemBomRef=$si['item_bom_ref']??''; $itemOrderNo=$si['item_order_no']??'';
                $totalQty+=$siQty;
                $txns=$pdo->prepare("SELECT st.txn_type,st.txn_qty,st.txn_date,$bomSel $remarkSel st.txn_id$orderSel FROM stock_transactions st $orderJoinB WHERE st.stock_item_id=? ORDER BY st.txn_date ASC,st.txn_id ASC");
                $txns->execute([$siId]); $rows=$txns->fetchAll(PDO::FETCH_ASSOC);
                $in_txns=[]; $out_qty=0;
                // 與 JS 庫存詳情 FIFO 一致：out_qty 只計算 txn_type='out'，盤點(count)/調整(adjust) 不計入
                foreach($rows as $t){ if($t['txn_type']==='in'&&floatval($t['txn_qty'])>0) $in_txns[]=$t; elseif($t['txn_type']==='out'&&floatval($t['txn_qty'])<0) $out_qty+=abs(floatval($t['txn_qty'])); }
                $siBatches=[];
                foreach($in_txns as $t){ $inPcs=floatval($t['txn_qty']); $consumed=min($out_qty,$inPcs); $out_qty-=$consumed; $rem=$inPcs-$consumed;
                    // 事務層 bom/order 優先；若為空則回退至品項層級所綁定的 BOM/訂單
                    $batchBom=($t['txn_bom']??'')?:$itemBomRef; $batchOrder=($t['order_no']??'')?:$itemOrderNo;
                    if($rem>0.0001) $siBatches[]=['batch_key'=>'SID:'.$siId.'|'.substr($t['txn_date']??'',0,10).'|'.($t['txn_bom']??''),'txn_date'=>substr($t['txn_date']??'',0,10),'remark'=>($t['remark']??''),'bom_ref'=>$batchBom,'order_ref'=>$batchOrder,'available'=>$rem,'location'=>$siLoc,'stock_item_id'=>$siId]; }
                if(empty($siBatches)&&$siQty>0)
                    $siBatches[]=['batch_key'=>'SID:'.$siId.'|synthetic','txn_date'=>'—','remark'=>'（原始庫存，無入庫記錄）','bom_ref'=>$itemBomRef,'order_ref'=>$itemOrderNo,'available'=>$siQty,'location'=>$siLoc,'stock_item_id'=>$siId];
                // FIFO 上限校驗：批次合計不可超過該儲位實際庫存（從最舊批次尾端削減多餘部分）
                $batchTotal=array_sum(array_column($siBatches,'available'));
                if($batchTotal>$siQty+0.0001&&$siQty>=0){
                    $excess=$batchTotal-$siQty;
                    for($bi=count($siBatches)-1;$bi>=0&&$excess>0.0001;$bi--){
                        $reduce=min($siBatches[$bi]['available'],$excess);
                        $siBatches[$bi]['available']-=$reduce;
                        $excess-=$reduce;
                        if($siBatches[$bi]['available']<0.0001) array_splice($siBatches,$bi,1);
                    }
                }
                $allBatches=array_merge($allBatches,$siBatches);
            }
            echo json_encode(['success'=>true,'batches'=>$allBatches,'current_qty'=>floatval($item['qty']),'total_qty'=>$totalQty,'is_group_item'=>false]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 執行領庫出庫 ──
    if ($_POST['action'] === 'issue_requisition') {
        try {
            $reqId=intval($_POST['req_id']??0); $txnDate=$_POST['txn_date']??date('Y-m-d');
            $outDeptId=intval($_POST['out_dept_id']??0)?:null; $outUserId2=intval($_POST['out_user_id']??0)?:null;
            $issueItems=json_decode($_POST['issue_items_json']??'[]',true);
            if(empty($issueItems)) throw new Exception('請設定出庫明細');
            $pp=$pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='stock'"); $pp->execute([$userId]);
            $p=($pp->fetch(PDO::FETCH_ASSOC)['permission']??'');
            $isCRUD=strpos($p,'C')!==false&&strpos($p,'R')!==false&&strpos($p,'U')!==false&&strpos($p,'D')!==false;
            if($p!=='A'&&!$isCRUD) throw new Exception('無出庫權限');
            $rqRow=$pdo->prepare("SELECT * FROM stock_requisitions WHERE req_id=?"); $rqRow->execute([$reqId]); $req=$rqRow->fetch(PDO::FETCH_ASSOC);
            if(!$req) throw new Exception('找不到需求單');
            // 自動填入請領人信息（從需求單取得，確保出庫記錄可追蹤）
            if(!$outUserId2) $outUserId2=intval($req['Created_By'])?:null;
            if(!$outDeptId && !empty($req['dept_name'])){
                $dRow=$pdo->prepare("SELECT id FROM department WHERE name=? LIMIT 1");
                $dRow->execute([$req['dept_name']]); $outDeptId=intval($dRow->fetchColumn())?:null;
            }
            $txnCols=$pdo->query("SHOW COLUMNS FROM stock_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasDept=in_array('out_dept_id',$txnCols); $hasBomRefR=in_array('bom_ref',$txnCols);
            $pdo->beginTransaction(); $totalIssued=0;
            foreach($issueItems as $ii){
                $reqItemId=intval($ii['req_item_id']??0); $batches=$ii['batches']??[]; if(empty($batches)) continue;
                $riRow=$pdo->prepare("SELECT ri.* FROM stock_requisition_items ri WHERE ri.req_item_id=? AND ri.req_id=?");
                $riRow->execute([$reqItemId,$reqId]); $ri=$riRow->fetch(PDO::FETCH_ASSOC);
                if(!$ri||!$ri['stock_item_id']) continue;
                $stockItemId=intval($ri['stock_item_id']); $dId=$ri['d_id']; $thisIssued=0;
                foreach($batches as $b){
                    $bQty=floatval($b['qty']??0); if($bQty<=0) continue;
                    $bKey=$b['batch_key']??'';
                    // 組合件整組出庫（batch_key 以 group|| 開頭，bQty = 組數）
                    if(strncmp($bKey,'group||',7)===0){
                        $grpId=intval(substr($bKey,7));
                        if(!$grpId) continue;
                        $mems=$pdo->prepare("SELECT stock_item_id,d_id,qty,COALESCE(pcs_per_set,1) AS pcs FROM stock_items WHERE group_id=? AND is_active=1 ORDER BY stock_item_id");
                        $mems->execute([$grpId]); $memRows=$mems->fetchAll(PDO::FETCH_ASSOC);
                        if(empty($memRows)) throw new Exception('找不到組合件子件');
                        $sets=$bQty;
                        foreach($memRows as $m){
                            $mId=intval($m['stock_item_id']); $mDid=$m['d_id'];
                            $reduceQty=round($sets*floatval($m['pcs']),4);
                            $mCur=floatval($m['qty']);
                            if($reduceQty>$mCur+0.0001) throw new Exception('組合件子件 '.$mDid.' 庫存不足（可用：'.$mCur.'，欲扣：'.$reduceQty.'）');
                            $mNew=round($mCur-$reduceQty,4);
                            $txnRemarkG='組合件領庫出庫（單號:'.$req['req_no'].'）共 '.$sets.' 組';
                            $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$mNew,$userId,$mId]);
                            if($hasDept&&$hasBomRefR)
                                $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,bom_ref,out_dept_id,out_user_id,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?,?,?,?)")
                                    ->execute([$mId,$mDid,-$reduceQty,$mCur,$mNew,null,$outDeptId,$outUserId2,$txnDate,$txnRemarkG,$userId]);
                            elseif($hasDept)
                                $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,out_dept_id,out_user_id,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?,?,?)")
                                    ->execute([$mId,$mDid,-$reduceQty,$mCur,$mNew,$outDeptId,$outUserId2,$txnDate,$txnRemarkG,$userId]);
                            else
                                $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?)")
                                    ->execute([$mId,$mDid,-$reduceQty,$mCur,$mNew,$txnDate,$txnRemarkG,$userId]);
                        }
                        $thisIssued+=$sets; // 以組數計
                        continue;
                    }
                    // 一般料號出庫（含 SID: 多儲位格式）
                    $useStockItemId=$stockItemId; $actualBatchKey=$bKey;
                    if(strncmp($bKey,'SID:',4)===0){
                        $sidParts=explode('|',substr($bKey,4),2);
                        $useStockItemId=intval($sidParts[0]);
                        $actualBatchKey=isset($sidParts[1])?$sidParts[1]:'synthetic';
                    }
                    $siCur=$pdo->prepare("SELECT qty,d_id FROM stock_items WHERE stock_item_id=? AND is_active=1");
                    $siCur->execute([$useStockItemId]); $siCurRow=$siCur->fetch(PDO::FETCH_ASSOC);
                    $curQty=floatval($siCurRow['qty']??0); $actualDId=$siCurRow['d_id']??$dId;
                    if($bQty>$curQty+0.0001) throw new Exception('料號 '.$actualDId.' 庫存不足（可用：'.$curQty.'，欲出：'.$bQty.'）');
                    $newQty=round($curQty-$bQty,4);
                    $bParts=explode('|',$actualBatchKey); $bBom=isset($bParts[1])?$bParts[1]:'';
                    $bDatePart=$bParts[0]??''; $bDatePart=($bDatePart==='synthetic'||$bDatePart==='—')?'':$bDatePart;
                    $txnRemark='領庫出庫（單號:'.$req['req_no'].')'.($bDatePart&&$bDatePart!==date('Y-m-d')?' 批次:'.$bDatePart:'');
                    $pdo->prepare("UPDATE stock_items SET qty=?,Modified_By=?,Modified_At=NOW() WHERE stock_item_id=?")->execute([$newQty,$userId,$useStockItemId]);
                    if($hasDept&&$hasBomRefR)
                        $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,bom_ref,out_dept_id,out_user_id,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?,?,?,?)")
                            ->execute([$useStockItemId,$actualDId,-$bQty,$curQty,$newQty,$bBom?:null,$outDeptId,$outUserId2,$txnDate,$txnRemark,$userId]);
                    elseif($hasDept)
                        $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,out_dept_id,out_user_id,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?,?,?)")
                            ->execute([$useStockItemId,$actualDId,-$bQty,$curQty,$newQty,$outDeptId,$outUserId2,$txnDate,$txnRemark,$userId]);
                    else
                        $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,txn_date,remark,Created_By) VALUES (?,?,'out',?,?,?,?,?,?)")
                            ->execute([$useStockItemId,$actualDId,-$bQty,$curQty,$newQty,$txnDate,$txnRemark,$userId]);
                    $thisIssued+=$bQty;
                }
                if($thisIssued>0){
                    $newIssued=min(floatval($ri['qty_requested']),floatval($ri['qty_issued'])+$thisIssued);
                    $pdo->prepare("UPDATE stock_requisition_items SET qty_issued=? WHERE req_item_id=?")->execute([$newIssued,$reqItemId]);
                    $totalIssued++;
                }
            }
            $iStat=$pdo->prepare("SELECT COUNT(*) AS total,SUM(CASE WHEN qty_issued>=qty_requested THEN 1 ELSE 0 END) AS done FROM stock_requisition_items WHERE req_id=?");
            $iStat->execute([$reqId]); $iS=$iStat->fetch(PDO::FETCH_ASSOC);
            $newStatus=($iS['total']>0&&$iS['done']>=$iS['total'])?2:($totalIssued>0?1:intval($req['status']));
            if($newStatus>intval($req['status']))
                $pdo->prepare("UPDATE stock_requisitions SET status=?,issued_at=NOW(),issued_by=?,issued_by_name=?,Modified_By=? WHERE req_id=?")
                    ->execute([$newStatus,$userId,$_SESSION['userName']??'',$userId,$reqId]);
            $pdo->commit(); ob_end_clean(); echo json_encode(['success'=>true,'new_status'=>$newStatus,'issued_count'=>$totalIssued]);
        } catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); ob_end_clean(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得部門（需求單用） ──
    if ($_POST['action'] === 'req_get_depts') {
        try { $rows=$pdo->query("SELECT id,name FROM department ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC); echo json_encode(['success'=>true,'depts'=>$rows]); }
        catch(Exception $e){ echo json_encode(['success'=>true,'depts'=>[]]); }
        exit;
    }

    // ── 取得部門人員（需求單用） ──
    if ($_POST['action'] === 'req_get_users') {
        try {
            $deptId=intval($_POST['dept_id']??0);
            if($deptId){ $st=$pdo->prepare("SELECT DISTINCT u.id,u.user_cname FROM user u JOIN user_department_position_map udpm ON udpm.user_id=u.id WHERE udpm.department_id=? AND u.state=1 ORDER BY u.user_cname"); $st->execute([$deptId]); }
            else { $st=$pdo->query("SELECT id,user_cname FROM user WHERE state=1 ORDER BY user_cname"); }
            echo json_encode(['success'=>true,'users'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>true,'users'=>[]]); }
        exit;
    }

    // ── 列印需求單（含品項） ──
    if ($_POST['action'] === 'print_requisitions') {
        try {
            $status=$_POST['status']??'all'; $kw=trim($_POST['kw']??'');
            $where="WHERE sr.is_active=1"; $params=[];
            if($status!=='all'&&$status!=='') { $where.=" AND sr.status=?"; $params[]=intval($status); }
            if($kw) { $where.=" AND (sr.req_no LIKE ? OR sr.title LIKE ? OR sr.dept_name LIKE ?)"; $lk='%'.$kw.'%'; $params[]=$lk; $params[]=$lk; $params[]=$lk; }
            $sql="SELECT sr.req_id,sr.req_no,sr.title,sr.dept_name,sr.requester_name,sr.status,sr.Created_At,sr.issued_at,u.user_cname AS creator_name FROM stock_requisitions sr LEFT JOIN user u ON u.id=sr.Created_By $where ORDER BY sr.req_id DESC";
            $st=$pdo->prepare($sql); $st->execute($params); $reqs=$st->fetchAll(PDO::FETCH_ASSOC);
            foreach($reqs as &$rq){
                $items=$pdo->prepare("SELECT ri.d_id,ri.client_name,ri.qty_requested,ri.qty_issued,ri.item_remark,ri.is_urgent,COALESCE(sic.category_name,'') AS category_name,COALESCE(si.storage_location,'') AS storage_location FROM stock_requisition_items ri LEFT JOIN stock_items si ON si.stock_item_id=ri.stock_item_id LEFT JOIN stock_item_categories sic ON sic.category_id=si.item_type WHERE ri.req_id=? ORDER BY ri.sort_order,ri.req_item_id");
                $items->execute([$rq['req_id']]); $rq['items']=$items->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($rq);
            echo json_encode(['success'=>true,'reqs'=>$reqs]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ══════════════════════════════════════════════════════
    //  每日入出庫紀錄表 AJAX
    // ══════════════════════════════════════════════════════
    if ($_POST['action'] === 'get_daily_report') {
        try {
            $dateFrom=$_POST['date_from']??date('Y-m-d'); $dateTo=$_POST['date_to']??date('Y-m-d');
            $page=max(1,intval($_POST['page']??1)); $ps=in_array(intval($_POST['page_size']??20),[20,50,100])?intval($_POST['page_size']):20; $offset=($page-1)*$ps;
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)) $dateFrom=date('Y-m-d');
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo))   $dateTo=date('Y-m-d');
            if($dateTo<$dateFrom) $dateTo=$dateFrom;
            $txnCols=$pdo->query("SHOW COLUMNS FROM stock_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasDeptR=in_array('out_dept_id',$txnCols); $hasOutUsrR=in_array('out_user_id',$txnCols);
            $deptJoinR=$hasDeptR?"LEFT JOIN department dept ON dept.id=st.out_dept_id":"";
            $userJoinR=$hasOutUsrR?"LEFT JOIN user out_u ON out_u.id=st.out_user_id":"";
            $deptSelR=$hasDeptR?"dept.name AS out_dept_name,":"NULL AS out_dept_name,";
            $userSelR=$hasOutUsrR?"out_u.user_cname AS out_user_name,":"NULL AS out_user_name,";
            $cst=$pdo->prepare("SELECT COUNT(*) FROM stock_transactions st WHERE st.txn_date BETWEEN ? AND ? AND st.txn_type IN ('in','out')");
            $cst->execute([$dateFrom,$dateTo]); $total=intval($cst->fetchColumn());
            // 客戶：即時查料號綁定客戶，不用 si.client_name 舊快照
            $sql="SELECT st.txn_id,st.txn_type,st.txn_qty,st.txn_date,st.qty_before,st.qty_after,st.stock_item_id,st.d_id,st.remark,clp.customer AS client_name,si.storage_location,sl.location_code,cr_u.user_cname AS creator_name,COALESCE(sic.category_name,'') AS category_name,$deptSelR $userSelR (SELECT SUM(si2.qty) FROM stock_items si2 WHERE si2.d_id=st.d_id AND si2.is_active=1) AS total_d_id_qty FROM stock_transactions st LEFT JOIN stock_items si ON si.stock_item_id=st.stock_item_id LEFT JOIN stock_item_categories sic ON sic.category_id=si.item_type LEFT JOIN stock_locations sl ON sl.location_id=si.location_id LEFT JOIN user cr_u ON cr_u.id=st.Created_By LEFT JOIN d_setting dsp ON dsp.d_id=si.d_setting_id LEFT JOIN customer_list clp ON clp.customer_id=dsp.Customer_Id $deptJoinR $userJoinR WHERE st.txn_date BETWEEN ? AND ? AND st.txn_type IN ('in','out') ORDER BY st.txn_date DESC,st.txn_id DESC LIMIT $ps OFFSET $offset";
            $st2=$pdo->prepare($sql); $st2->execute([$dateFrom,$dateTo]); $rows=$st2->fetchAll(PDO::FETCH_ASSOC);
            // 領庫出庫的紀錄：備註改顯示需求單詳情所填的料號備註(item_remark)；
            // 從交易備註解析單號，依「單號+料號」對應需求單品項；非領庫紀錄(無單號)保留原交易備註。
            $rowReqNo=[]; $reqNoSet=[];
            foreach($rows as $i=>$row){
                if(!empty($row['remark'])&&preg_match('/單號[:：]\s*([^)）\s]+)/u',$row['remark'],$m)){
                    $rn=trim($m[1]); $rowReqNo[$i]=$rn; $reqNoSet[$rn]=true;
                }
            }
            if($reqNoSet){
                $rnList=array_keys($reqNoSet);
                $ph=implode(',',array_fill(0,count($rnList),'?'));
                $mapQ=$pdo->prepare("SELECT sr.req_no,ri.d_id,ri.item_remark FROM stock_requisition_items ri JOIN stock_requisitions sr ON sr.req_id=ri.req_id WHERE sr.req_no IN ($ph)");
                $mapQ->execute($rnList);
                $remarkMap=[];
                foreach($mapQ->fetchAll(PDO::FETCH_ASSOC) as $mr){ $remarkMap[$mr['req_no'].'||'.$mr['d_id']]=$mr['item_remark']; }
                foreach($rowReqNo as $i=>$rn){
                    $key=$rn.'||'.$rows[$i]['d_id'];
                    if(array_key_exists($key,$remarkMap)) $rows[$i]['remark']=$remarkMap[$key]; // 對應到需求單品項才覆蓋，否則保留原交易備註
                }
            }
            $ss=$pdo->prepare("SELECT txn_type,COUNT(*) AS cnt,SUM(ABS(txn_qty)) AS qty_sum FROM stock_transactions WHERE txn_date BETWEEN ? AND ? AND txn_type IN ('in','out') GROUP BY txn_type");
            $ss->execute([$dateFrom,$dateTo]); $stats=['in_cnt'=>0,'in_qty'=>0,'out_cnt'=>0,'out_qty'=>0];
            foreach($ss->fetchAll(PDO::FETCH_ASSOC) as $s){ if($s['txn_type']==='in'){$stats['in_cnt']=$s['cnt'];$stats['in_qty']=$s['qty_sum'];} if($s['txn_type']==='out'){$stats['out_cnt']=$s['cnt'];$stats['out_qty']=$s['qty_sum'];} }
            echo json_encode(['success'=>true,'rows'=>$rows,'total'=>$total,'pages'=>(int)ceil($total/$ps),'page'=>$page,'stats'=>$stats]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'未知操作']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>庫存管理</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--primary:#2A3F54;--accent:#1ABB9C;--warn:#F39C12;--danger:#E74C3C;--info:#3498DB;--purple:#9B59B6;--bg:#F4F7FC;--card:#fff;--border:#E6E9ED;--text:#495057}
body{background:var(--bg);font-family:"Segoe UI","Roboto",Arial,sans-serif;color:var(--text)}
/* 只禁止橫向捲軸 */
.right_col{background:var(--bg)!important;overflow-x:hidden!important;max-width:100%;box-sizing:border-box;}
html,body,.main_container,.container.body{overflow-x:hidden!important;}
/* 所有 tab 內容不超寬 */
#tab-list,#tab-count,#tab-setting,#tab-analysis,#tab-req,#tab-report{max-width:100%;overflow-x:hidden;box-sizing:border-box;}
/* 設定頁 grid */
#tab-setting>div{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;}
#count-tbl{width:100%;}
/* Modal flex layout：解決 modal-body 雙滾動條，只讓 modal-body 捲動 */
.modal-content{display:flex!important;flex-direction:column!important;max-height:90vh!important;}
.modal-body{overflow-y:auto!important;flex:1 1 auto!important;-webkit-overflow-scrolling:touch;}
.modal-footer,.modal-header{flex-shrink:0!important;}

/* 頁頭 */
.pg-header{display:flex;align-items:center;justify-content:space-between;background:var(--card);border-radius:10px;padding:13px 20px;margin-bottom:14px;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.pg-header h3{margin:0;font-size:19px;font-weight:700;color:var(--primary)}
.tab-sw{display:flex;gap:4px;background:#eef1f5;border-radius:8px;padding:4px}
.tab-btn{border:none;background:transparent;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;color:#888;cursor:pointer;transition:all .2s}
.tab-btn.active{background:var(--card);color:var(--primary);box-shadow:0 2px 5px rgba(0,0,0,.1)}

/* 統計卡片 */
.stats-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.sc{flex:1;min-width:130px;background:var(--card);border-radius:10px;padding:13px 16px;box-shadow:0 2px 6px rgba(0,0,0,.05);border-left:4px solid transparent;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.sc:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.1)}
.sc.active{box-shadow:0 0 0 3px var(--sc-color,var(--accent));transform:scale(1.02)}
.sc-icon{position:absolute;right:10px;top:10px;font-size:32px;opacity:.08}
.sc-val{font-size:24px;font-weight:800;color:var(--primary);line-height:1}
.sc-label{font-size:11px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.7px;margin-top:3px}
/* 成本卡片對齊 */
.sc-val.cost-val{font-size:17px;font-weight:800;color:var(--primary);line-height:1.2;margin-top:2px}

/* 篩選列 */
.fbar{background:var(--card);border-radius:10px;padding:10px 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;box-shadow:0 2px 6px rgba(0,0,0,.05);margin-bottom:14px}
.fbar .form-control,.fbar .btn{height:33px;font-size:13px}
.fbar input{max-width:200px}

/* 表格 */
.mc{background:var(--card);border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,.05);overflow:hidden;max-width:100%;}
.mc table thead th{background:#f8f9fa;color:#555;font-weight:700;padding:9px 7px;font-size:12px;border-bottom:2px solid var(--border);white-space:nowrap;vertical-align:middle}
.mc table tbody td{padding:6px 7px;vertical-align:middle;font-size:12px;border-bottom:1px solid #f0f2f5}
.mc table tbody tr:hover{background:#FAFBFF!important}
.sortable{cursor:pointer}
.sortable:hover{color:var(--primary)}

/* 分頁 */
.pager{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-top:1px solid var(--border);font-size:13px;overflow:hidden;flex-wrap:wrap;gap:4px;}
.pager-btns{display:flex;gap:3px;flex-wrap:wrap;overflow:hidden;}
.pager-btns button{padding:3px 10px;font-size:12px;border-radius:4px;border:1px solid var(--border);background:#fff;cursor:pointer}
.pager-btns button:hover{background:#f0f4ff}
.pager-btns button.active{background:var(--primary);color:#fff;border-color:var(--primary)}

/* 數量顏色 */
.qty-zero{color:var(--danger);font-weight:700}
.qty-low{color:var(--warn);font-weight:700}
.qty-ok{color:var(--primary);font-weight:600}

/* badge */
.cbadge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}

/* 操作按鈕 */
.ba{padding:2px 8px;font-size:12px;border-radius:4px;margin:1px;border:1px solid}
.ba-in{background:#d4f5ed;color:#0e7a5e;border-color:#aee8d3}
.ba-out{background:#fde8e8;color:#a52020;border-color:#f5b7b7}
.ba-move{background:#e0eeff;color:#1a4fa0;border-color:#b0cff5}
.ba-edit{background:#f0e8fd;color:#5b2a8c;border-color:#d5b0f0}
.ba-del{background:#f5f5f5;color:#c0392b;border-color:#ddd}
.ba-info{background:#f5f5f5;color:#555;border-color:#ddd}

/* 入出庫報表統計卡片 */
.stat-card{background:var(--card);border-radius:8px;padding:10px 16px;box-shadow:0 2px 6px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;flex-direction:column;justify-content:center;}
.stat-card .stat-num{font-size:24px;font-weight:800;line-height:1.1;}
.stat-card .stat-label{font-size:11px;color:#888;font-weight:600;letter-spacing:.5px;margin-top:3px;}

/* 圓角主要按鈕 (仿 master_data_management) */
.btn-pill{background:#1ABB9C;color:#fff;border:none;padding:6px 16px;border-radius:20px;font-weight:600;font-size:13px;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:5px;}
.btn-pill:hover{background:#159a82;transform:translateY(-1px);box-shadow:0 3px 10px rgba(0,0,0,.12);}
.btn-pill.pill-blue{background:#3498DB;}.btn-pill.pill-blue:hover{background:#217dbb;}
.btn-pill.pill-warn{background:#F39C12;}.btn-pill.pill-warn:hover{background:#d68910;}
.btn-pill.pill-danger{background:#E74C3C;}.btn-pill.pill-danger:hover{background:#c0392b;}
/* 報表/需求單列表分頁按鈕 */
.pg-btns{display:flex;gap:3px;flex-wrap:wrap;}
.pg-btns button{padding:3px 10px;font-size:12px;border-radius:4px;border:1px solid var(--border);background:#fff;cursor:pointer;transition:all .15s;}
.pg-btns button:hover{background:var(--primary);color:#fff;border-color:var(--primary);}
.pg-btns button.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.pg-btns button[disabled]{opacity:.35;cursor:not-allowed;pointer-events:none;}

/* 隱藏數字輸入框的上下箭頭 */
.no-spin::-webkit-inner-spin-button,.no-spin::-webkit-outer-spin-button{-webkit-appearance:none;appearance:none;margin:0;}
.no-spin[type=number]{-moz-appearance:textfield;appearance:textfield;}
.part-label-chip{display:inline-block;background:#e8f5f2;color:#1ABB9C;border:1px solid #a8dfd4;border-radius:10px;padding:0 6px;font-size:10.5px;font-weight:600;margin:1px 2px 1px 0;cursor:default;}
.part-drawing-link{color:var(--accent,#1ABB9C);cursor:pointer;text-decoration:none;border-bottom:1px dashed var(--accent,#1ABB9C);}
.part-drawing-link:hover{color:#138a76;}

/* 組合件群組標記 */
.group-badge{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:12px;font-size:10px;font-weight:700;background:#fff3cd;color:#856404;border:1px solid #ffc107;cursor:pointer;white-space:nowrap;}
.group-badge:hover{background:#ffe69c;}
.group-row-mark{border-left:3px solid #ffc107!important;}
tr.group-highlight>td{background:#fffbea!important;}

/* Modal */
.modal-header{background:var(--primary);color:#fff;border-radius:6px 6px 0 0}
.modal-header .modal-title{font-weight:700}
.modal-header .close{color:#fff;opacity:1}
.fs{background:#f8f9fc;border-radius:8px;padding:11px 14px;margin-bottom:12px}
.fs h6{font-weight:700;color:var(--primary);margin-bottom:9px;border-bottom:2px solid var(--accent);padding-bottom:5px;font-size:13px}
.form-control{font-size:13px}
label{font-size:13px;font-weight:600;color:var(--primary);margin-bottom:3px}

/* Autocomplete */
.ac-box{position:absolute;background:#fff;border:1px solid #ddd;border-radius:6px;z-index:9999;max-height:220px;overflow-y:auto;width:100%;box-shadow:0 4px 14px rgba(0,0,0,.12);top:100%}
.ac-item{padding:7px 11px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0}
.ac-item:hover{background:#f0f4ff}
.ac-item .sub{font-size:11px;color:#888}

/* 歷程 */
.txn-list{max-height:340px;overflow-y:auto}
.txn-item{display:flex;gap:9px;padding:7px 0;border-bottom:1px solid #f0f0f0;align-items:flex-start}
.txn-dot{width:10px;height:10px;border-radius:50%;margin-top:3px;flex-shrink:0}
.txn-in{background:var(--accent)}.txn-out{background:var(--danger)}.txn-move{background:var(--info)}.txn-adj{background:var(--warn)}.txn-cnt{background:var(--purple)}
.txn-text{font-size:12px;line-height:1.5}

/* 設定頁 */
.setting-card{background:var(--card);border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,.05);padding:16px;margin-bottom:14px}
.setting-card h5{font-weight:700;color:var(--primary);margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
.tbl-sm{font-size:12px}
.tbl-sm th{background:#f8f9fa;font-weight:700;padding:7px 9px}
.tbl-sm td{padding:5px 9px;vertical-align:middle}

/* 換算提示 */
.convert-hint{background:#f0fdf8;border-radius:6px;padding:7px 11px;font-size:12px;color:var(--accent);margin-top:5px}

/* 拆分工具列 */
#split-txn-toolbar{ flex-shrink:0; }
.txn-split-chk{ accent-color:var(--accent); width:14px; height:14px; }
.split-row-item:last-child{ border-color:var(--accent)!important; background:#f0fdf8!important; }

/* 安全庫存警示 */
.safety-warning{background:#fef3e2;border-left:3px solid var(--warn);border-radius:0 6px 6px 0;padding:4px 10px;font-size:11px;color:#a06000}

/* 分析 */
.chart-card{background:var(--card);border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.05);margin-bottom:14px}
.chart-card h5{font-weight:700;color:var(--primary);margin-bottom:14px}

/* 盤點 */
.sess-card{background:var(--card);border-radius:8px;padding:13px;box-shadow:0 2px 6px rgba(0,0,0,.05);margin-bottom:10px;cursor:pointer;transition:all .2s}
.sess-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.1)}
.sess-card.active{box-shadow:0 0 0 2px var(--accent)}

/* 空狀態 */
.empty{text-align:center;padding:50px 20px;color:#bbb}
.empty i{font-size:42px;display:block;margin-bottom:10px}

/* Toast */
#toast-wrap{position:fixed;bottom:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px}
.toast-msg{background:var(--primary);color:#fff;padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:380px;animation:toastIn .2s ease}
.toast-msg.success{background:var(--accent)}
.toast-msg.error{background:var(--danger)}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* 隨機盤點選取列 */
.rc-selected{background:#f0fdf8!important;}
.rc-selected td{border-left:2px solid var(--accent);}

@media(max-width:768px){.stats-row{flex-direction:column}.fbar{flex-direction:column;align-items:stretch}.fbar input{max-width:100%}}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

<!-- 頁頭 -->
<div class="pg-header" style="flex-wrap:wrap;gap:8px;">
  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <h3 style="margin:0;"><i class="fa fa-cubes" style="color:var(--accent);margin-right:8px;"></i>庫存管理</h3>
    <!-- 成本/售額內嵌在標題列 -->
    <div style="display:flex;gap:8px;" id="header-cost-row">
      <div style="background:rgba(255,255,255,.15);border-radius:6px;padding:3px 12px;border-left:3px solid #9B59B6;font-size:12px;">
        <span style="color:#888;">庫存總成本：</span><span id="sv-cost" style="font-weight:700;color:#9B59B6;">—</span>
      </div>
      <div style="background:rgba(255,255,255,.15);border-radius:6px;padding:3px 12px;border-left:3px solid #1ABB9C;font-size:12px;">
        <span style="color:#888;">庫存可銷售金額：</span><span id="sv-sale" style="font-weight:700;color:#1ABB9C;">—</span>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <div class="tab-sw">
      <button class="tab-btn active" onclick="switchTab('list',this)">📦 庫存列表</button>
      <button class="tab-btn" onclick="switchTab('analysis',this)">📊 分析報表</button>
      <button class="tab-btn" id="tab-btn-count" onclick="switchTab('count',this)" style="display:none;">📋 庫存盤點</button>
      <button class="tab-btn" id="tab-btn-setting" onclick="switchTab('setting',this)" style="display:none;">⚙️ 主資料設定</button>
      <button class="tab-btn" id="tab-btn-req" onclick="switchTab('req',this)">📋 領庫需求單</button>
      <button class="tab-btn" id="tab-btn-report" onclick="switchTab('report',this)">📊 入出庫紀錄</button>
    </div>
    <button class="btn btn-sm" id="btn-add-stock" style="background:var(--accent);color:#fff;border-radius:6px;font-weight:600;display:none;" onclick="openAddModal()"><i class="fa fa-plus"></i> 新增庫存</button>
    <button class="btn btn-sm" id="btn-batch-add" style="background:#16a085;color:#fff;border-radius:6px;font-weight:600;display:none;" onclick="openBatchAddModal()"><i class="fa fa-th-list"></i> 批次新增</button>
    <button class="btn btn-sm" id="btn-add-group" style="background:var(--warn);color:#fff;border-radius:6px;font-weight:600;display:none;" onclick="openGroupModal()"><i class="fa fa-cubes"></i> 新增組合件</button>
  </div>
</div>

<!-- ═══ TAB: 庫存列表 ═══ -->
<div id="tab-list">
  <!-- 統計卡片（可篩選） -->
  <div class="stats-row" id="stats-row">
    <div class="sc" style="border-left-color:#3498DB;--sc-color:#3498DB;" id="scard-all" onclick="filterCard('all',this)">
      <i class="fa fa-cubes sc-icon"></i><div class="sc-val" id="sv-total">—</div><div class="sc-label">全部品項</div>
    </div>
    <div class="sc" style="border-left-color:#F39C12;--sc-color:#F39C12;" id="scard-low" onclick="filterCard('low',this)">
      <i class="fa fa-arrow-down sc-icon"></i><div class="sc-val" id="sv-low">—</div><div class="sc-label">低於安全庫存</div>
    </div>
    <div class="sc" style="border-left-color:#3498DB;--sc-color:#3498DB;" id="scard-today" onclick="filterCard('today',this)">
      <i class="fa fa-exchange sc-icon"></i><div class="sc-val" id="sv-today">—</div><div class="sc-label">今日異動</div>
    </div>
    <div id="cat-cards" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
  </div>

  <!-- 篩選列 -->
  <div class="fbar">
    <div style="position:relative;flex:1;min-width:200px;">
      <input type="text" id="f-search" class="form-control" placeholder="🔍 Enter搜尋料號/包裝箱/備註（雙擊清除）" autocomplete="off" title="輸入後按 Enter 搜尋，雙擊清除">
    </div>
    <select id="f-cat" class="form-control" style="width:110px;" onchange="loadList(1)">
      <option value="all">全部種類</option>
    </select>
    <select id="f-loc" class="form-control" style="width:130px;" onchange="loadList(1)">
      <option value="all">全部儲位</option>
    </select>
    <select id="f-qty" class="form-control" style="width:120px;" onchange="loadList(1)">
      <option value="all">全部數量</option>
      <option value="has">有庫存</option>
      <option value="low">低於安全庫存</option>
      <option value="zero">零庫存</option>
    </select>
    <select id="f-client" class="form-control" style="width:130px;" onchange="loadList(1)">
      <option value="">全部客戶</option>
    </select>
    <select id="f-stale" class="form-control" style="width:120px;" onchange="loadList(1)" title="N年內無出庫記錄">
      <option value="0">全部更動</option>
      <option value="1">1年未出庫</option>
      <option value="2">2年未出庫</option>
      <option value="3">3年未出庫</option>
      <option value="5">5年未出庫</option>
    </select>
    <button class="btn btn-default btn-sm" onclick="resetFilters()">重置</button>
    <button class="btn btn-sm btn-info" id="btn-batch-group" style="display:none; border-radius:6px; font-weight:600;" onclick="openMergeExistingGroupModal()"><i class="fa fa-compress"></i> 合併為組合件 (<span id="sel-cnt">0</span>)</button>
    <button class="btn btn-sm" id="btn-batch-unit" style="display:none; background:#7f8c8d; color:#fff; border-radius:6px; font-weight:600;" onclick="openBatchUnitModal()"><i class="fa fa-balance-scale"></i> 設定單位</button>
    <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
      <span style="font-size:12px;color:#888;">每頁</span>
      <select id="f-ps" class="form-control" style="width:65px;" onchange="loadList(1)">
        <option value="10" selected>10</option><option value="20">20</option><option value="30">30</option><option value="50">50</option><option value="100">100</option>
      </select>
    </div>
  </div>

  <!-- 表格 -->
  <div class="mc">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
      <table class="table" id="stock-table" style="margin:0;width:100%;table-layout:auto;">
        <thead><tr>
          <th id="th-list-chk" style="width:36px; text-align:center;"><input type="checkbox" id="list-chk-all" onchange="toggleAllListChk(this)"></th>
          <th class="sortable" data-col="d_id">料號 <i class="fa fa-sort"></i></th>
          <th class="sortable" data-col="client_name">客戶 <i class="fa fa-sort"></i></th>
          <th>種類</th>
          <th class="sortable" data-col="storage_location">儲位 <i class="fa fa-sort"></i></th>
          <th class="sortable" data-col="qty" style="text-align:center;">庫存 <i class="fa fa-sort"></i></th>
          <th style="text-align:right;">成本</th>
          <th style="text-align:right;">售價</th>
          <th>製令/訂單</th>
          <th class="sortable" data-col="stock_date">入庫日 <i class="fa fa-sort"></i></th>
          <th style="text-align:center;width:130px;">操作</th>
        </tr></thead>
        <tbody id="stock-tbody"><tr><td colspan="11"><div class="empty"><i class="fa fa-spinner fa-spin"></i></div></td></tr></tbody>
      </table>
    </div>
    <div class="pager"><div id="pager-info" style="color:#888;"></div><div class="pager-btns" id="pager-btns"></div></div>
  </div>
</div>

<!-- ═══ TAB: 分析 ═══ -->
<div id="tab-analysis" style="visibility:hidden;position:absolute;pointer-events:none;width:100%;">
  <!-- 分析控制列 -->
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;background:var(--card);border-radius:10px;padding:10px 14px;box-shadow:0 2px 6px rgba(0,0,0,.05);">
    <div style="font-weight:700;font-size:13px;color:var(--primary);">📊 報表期間</div>
    <select id="an-period" class="form-control" style="width:95px;" onchange="onPeriodChange()">
      <option value="month">月報表</option>
      <option value="quarter">季報表</option>
      <option value="year">年報表</option>
    </select>
    <div style="display:flex;align-items:center;gap:4px;">
      <label style="margin:0;font-size:12px;color:#888;">期間A：</label>
      <input type="text" id="an-period-a" class="form-control" style="width:115px;" title="月:2025-04 季:2025-Q2 年:2025">
    </div>
    <div style="display:flex;align-items:center;gap:4px;">
      <label style="margin:0;font-size:12px;color:#888;">對比B（選填）：</label>
      <input type="text" id="an-period-b" class="form-control" placeholder="空=不對比" style="width:110px;">
    </div>
    <button class="btn btn-sm" style="background:var(--accent);color:#fff;border-color:var(--accent);" onclick="loadAnalysis()">載入</button>
    <button class="btn btn-sm btn-default" onclick="printAnalysis()" style="margin-left:auto;"><i class="fa fa-print"></i> 列印 / PDF</button>
    <span id="an-period-label" style="font-size:11px;color:#aaa;"></span>
  </div>
  <div id="analysis-content">
    <div style="display:grid;grid-template-columns:minmax(240px,320px) 1fr;gap:14px;margin-bottom:14px;max-width:100%;">
      <div class="chart-card" style="overflow:hidden;">
        <h5><i class="fa fa-pie-chart" style="color:var(--accent);margin-right:6px;"></i>庫存種類分佈</h5>
        <div style="max-width:280px;margin:0 auto;overflow:hidden;"><canvas id="chart-cat" height="260"></canvas></div>
      </div>
      <div class="chart-card" style="overflow:hidden;">
        <h5 style="display:flex;align-items:center;gap:8px;">
          <span><i class="fa fa-bar-chart" style="color:var(--info);margin-right:6px;"></i>客戶庫存 Top10</span>
          <div style="margin-left:auto;display:flex;gap:4px;">
            <button id="cli-btn-qty" class="btn btn-xs" style="background:var(--accent);color:#fff;font-size:11px;" onclick="switchCliMode('qty')">庫存數量</button>
            <button id="cli-btn-cnt" class="btn btn-xs btn-default" style="font-size:11px;" onclick="switchCliMode('cnt')">品項筆數</button>
          </div>
        </h5>
        <div style="overflow:hidden;"><canvas id="chart-cli"></canvas></div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;max-width:100%;">
      <div class="chart-card" style="overflow:hidden;">
        <h5><i class="fa fa-line-chart" style="color:var(--warn);margin-right:6px;"></i>異動趨勢 <span id="an-trend-label" style="font-size:11px;color:#aaa;font-weight:400;"></span></h5>
        <canvas id="chart-trend" height="150"></canvas>
      </div>
      <div class="chart-card"><h5><i class="fa fa-clock-o" style="color:var(--purple);margin-right:6px;"></i>庫齡分析<button class="btn btn-xs btn-default" style="float:right;" onclick="openAgingDetail('')" title="開啟個別品項停滯日數清單"><i class="fa fa-list"></i> 查看清單</button></h5><div id="aging-list"></div></div>
    </div>
  </div>
</div>

<!-- ═══ TAB: 盤點 ═══ -->
<div id="tab-count" style="display:none;">
  <div style="display:flex;gap:12px;align-items:flex-start;">
    <div style="width:300px;flex-shrink:0;">
      <div class="setting-card">
        <h5><i class="fa fa-list"></i> 盤點批次 <button class="btn btn-xs" style="background:var(--accent);color:#fff;" onclick="openCountModal()"><i class="fa fa-plus"></i> 建立</button></h5>
        <div id="count-sessions"></div>
      </div>
    </div>
    <div style="flex:1;"><div class="setting-card" id="count-detail-panel"><div class="empty"><i class="fa fa-hand-pointer-o"></i><div style="font-size:13px;">請選擇批次</div></div></div></div>
  </div>
</div>

<!-- ═══ TAB: 設定 ═══ -->
<div id="tab-setting" style="display:none;">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
    <!-- 儲位 -->
    <div class="setting-card">
      <h5><i class="fa fa-map-marker"></i> 儲位管理 <div style="display:flex;gap:4px;"><button id="btn-area-manage" class="btn btn-xs" style="background:#7f8c8d;color:#fff;" onclick="openAreaModal()"><i class="fa fa-building"></i> 廠區</button><button id="btn-add-loc" class="btn btn-xs" style="background:var(--accent);color:#fff;" onclick="openLocModal(0)"><i class="fa fa-plus"></i></button></div></h5>
      <div id="loc-area-switcher" style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px;padding:2px 0;"></div>
      <div style="position:relative;margin-bottom:8px;"><i class="fa fa-search" style="position:absolute;left:9px;top:9px;color:#bbb;font-size:12px;"></i><input type="text" id="loc-search" class="form-control input-sm" placeholder="模糊搜尋儲位代碼 / 說明..." style="height:30px;padding-left:28px;" autocomplete="off"></div>
      <div style="overflow-x:auto;"><table class="table tbl-sm" id="loc-table" style="margin:0;"><thead><tr><th>代碼</th><th>廠區</th><th>說明</th><th></th></tr></thead><tbody id="loc-tbody"><tr><td colspan="4" class="text-center text-muted">載入中...</td></tr></tbody></table></div>
      <div id="loc-pager" class="pager" style="padding:5px 10px;border-top:none;"></div>
    </div>
    <!-- 種類 -->
    <div class="setting-card">
      <h5><i class="fa fa-tags"></i> 品項種類 <button id="btn-add-cat" class="btn btn-xs" style="background:var(--accent);color:#fff;" onclick="openCatModal(0)"><i class="fa fa-plus"></i></button></h5>
      <div style="overflow-x:auto;"><table class="table tbl-sm" id="cat-table" style="margin:0;"><thead><tr><th>名稱</th><th>預設年限</th><th>BOM</th><th>訂單</th><th>首頁顯示</th><th>顯示欄位</th><th>儲位限制</th><th></th></tr></thead><tbody id="cat-tbody"><tr><td colspan="8" class="text-center text-muted">載入中...</td></tr></tbody></table></div>
      <div id="cat-pager" class="pager" style="padding:5px 10px;border-top:none;"></div>
    </div>
    <!-- 單位 -->
    <div class="setting-card">
      <h5><i class="fa fa-balance-scale"></i> 計量單位 <button id="btn-add-unit" class="btn btn-xs" style="background:var(--accent);color:#fff;" onclick="openUnitModal(0)"><i class="fa fa-plus"></i></button></h5>
      <div style="overflow-x:auto;"><table class="table tbl-sm" id="unit-table" style="margin:0;"><thead><tr><th>單位名</th><th>符號</th><th>類型</th><th>換算基準</th><th>係數</th><th></th></tr></thead><tbody id="unit-tbody"></tbody></table></div>
      <div id="unit-pager" class="pager" style="padding:5px 10px;border-top:none;"></div>
    </div>
    <!-- 安全庫存 -->
    <div class="setting-card">
      <h5><i class="fa fa-shield"></i> 安全庫存設定 <button id="btn-add-safety" class="btn btn-xs" style="background:var(--accent);color:#fff;" onclick="openSafetyModal(0)"><i class="fa fa-plus"></i></button></h5>
      <div style="overflow-x:auto;"><table class="table tbl-sm" id="safety-table" style="margin:0;"><thead><tr><th>料號</th><th>安全庫存</th><th>單位</th><th></th></tr></thead><tbody id="safety-tbody"></tbody></table></div>
      <div id="safety-pager" class="pager" style="padding:5px 10px;border-top:none;"></div>
    </div>
  </div>
</div>

<!-- ═══ TAB: 領庫需求單 ═══ -->
<div id="tab-req" style="display:none;">
  <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
    <h4 style="margin:0;font-weight:700;color:var(--primary);"><i class="fa fa-clipboard"></i> 領庫需求單</h4>
    <button id="btn-create-req" class="btn-pill" onclick="openCreateReqModal()"><i class="fa fa-plus"></i> 新增需求單</button>
    <button class="btn btn-sm btn-default" style="border-radius:12px;" onclick="openDeletedReqModal()"><i class="fa fa-trash-o"></i> 已刪除</button>
    <button class="btn btn-sm btn-default" style="border-radius:12px;" onclick="printAllReq()" title="列印目前篩選的所有需求單"><i class="fa fa-print"></i> 列印</button>
    <span id="req-perm-badge" style="font-size:11px;padding:3px 8px;border-radius:10px;background:#e8f4fd;color:#1a78c2;border:1px solid #b8d8f0;font-weight:600;"></span>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <div class="btn-group btn-group-sm" role="group" id="req-status-btns">
        <button type="button" class="btn btn-default req-status-btn" data-status="" onclick="setReqStatusFilter('',this)">全部</button>
        <button type="button" class="btn btn-primary req-status-btn" data-status="0" onclick="setReqStatusFilter('0',this)">待出庫</button>
        <button type="button" class="btn btn-default req-status-btn" data-status="1" onclick="setReqStatusFilter('1',this)">部分出庫</button>
        <button type="button" class="btn btn-default req-status-btn" data-status="2" onclick="setReqStatusFilter('2',this)">已完成</button>
      </div>
      <div class="input-group input-group-sm" style="width:200px;">
        <input type="text" id="req-filter-kw" class="form-control" placeholder="搜尋單號/標題/部門" oninput="loadRequisitions()" ondblclick="clearReqKw()">
        <span class="input-group-btn"><button class="btn btn-default" onclick="clearReqKw()" title="清除"><i class="fa fa-times"></i></button></span>
      </div>
    </div>
  </div>
  <!-- 翻頁控制（表格上方靠右）-->
  <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
    <div class="btn-group btn-group-sm">
      <button class="btn btn-default" onclick="expandAllReq()" title="全部展開" style="font-size:12px;"><i class="fa fa-expand"></i> 全展開</button>
      <button class="btn btn-default" onclick="collapseAllReq()" title="全部收起" style="font-size:12px;"><i class="fa fa-compress"></i> 全收起</button>
    </div>
    <div id="req-pager" class="pg-btns"></div>
    <label style="margin:0;font-size:12px;color:#888;">每頁</label>
    <select id="req-page-size" class="form-control input-sm" style="width:70px;" onchange="setReqPageSize(this.value)">
      <option value="10">10</option>
      <option value="20" selected>20</option>
      <option value="30">30</option>
    </select>
    <span style="font-size:12px;color:#888;">筆</span>
  </div>
  <div style="overflow-x:auto;">
    <table class="table table-hover tbl-sm" id="req-table" style="margin:0;">
      <thead><tr>
        <th style="width:28px;"></th>
        <th>需求單號</th><th>標題</th><th>申請部門</th><th>申請人</th><th>狀態</th>
        <th>料號數</th><th>建立時間</th><th>修改時間</th><th style="width:80px;">操作</th>
      </tr></thead>
      <tbody id="req-tbody"><tr><td colspan="10" class="text-center text-muted">點擊「領庫需求單」分頁以載入</td></tr></tbody>
    </table>
  </div>
</div>

<!-- ═══ TAB: 入出庫紀錄 ═══ -->
<div id="tab-report" style="display:none;">
  <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
    <h4 style="margin:0;font-weight:700;color:var(--primary);"><i class="fa fa-table"></i> 每日入出庫紀錄表</h4>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-primary rpt-view-btn" data-view="day" onclick="setRptView('day',this)">日</button>
        <button type="button" class="btn btn-default rpt-view-btn" data-view="week" onclick="setRptView('week',this)">週</button>
        <button type="button" class="btn btn-default rpt-view-btn" data-view="month" onclick="setRptView('month',this)">月</button>
        <button type="button" class="btn btn-default rpt-view-btn" data-view="range" onclick="setRptView('range',this)">自訂</button>
      </div>
      <div class="btn-group btn-group-sm" role="group">
        <button class="btn btn-default" onclick="rptNavigate(-1)" title="上一期"><i class="fa fa-chevron-left"></i></button>
        <button class="btn btn-default" style="font-weight:600;min-width:130px;cursor:default;" id="rpt-period-label">—</button>
        <button class="btn btn-default" onclick="rptNavigate(1)" title="下一期"><i class="fa fa-chevron-right"></i></button>
      </div>
      <div id="rpt-range-wrap" style="display:none;gap:6px;align-items:center;">
        <input type="date" id="rpt-date-from" class="form-control input-sm" style="width:130px;">
        <span class="text-muted">~</span>
        <input type="date" id="rpt-date-to" class="form-control input-sm" style="width:130px;">
        <button class="btn btn-sm btn-primary" onclick="loadDailyReport(1)"><i class="fa fa-search"></i> 查詢</button>
      </div>
    </div>
  </div>
  <!-- 統計卡片 -->
  <div id="rpt-stats" style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
    <div class="stat-card" style="flex:1;min-width:140px;"><div class="stat-num" id="rpt-in-cnt" style="color:var(--accent);">-</div><div class="stat-label">入庫筆數</div></div>
    <div class="stat-card" style="flex:1;min-width:140px;"><div class="stat-num" id="rpt-in-qty" style="color:var(--accent);">-</div><div class="stat-label">入庫總量</div></div>
    <div class="stat-card" style="flex:1;min-width:140px;"><div class="stat-num" id="rpt-out-cnt" style="color:var(--danger);">-</div><div class="stat-label">出庫筆數</div></div>
    <div class="stat-card" style="flex:1;min-width:140px;"><div class="stat-num" id="rpt-out-qty" style="color:var(--danger);">-</div><div class="stat-label">出庫總量</div></div>
  </div>
  <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:8px 0 6px;flex-wrap:wrap;">
    <div id="rpt-pager" class="pg-btns"></div>
    <label style="margin:0;font-size:12px;color:#888;">每頁</label>
    <select id="rpt-page-size-sel" class="form-control input-sm" style="width:75px;" onchange="setRptPageSize(parseInt(this.value))">
      <option value="20" selected>20</option>
      <option value="50">50</option>
      <option value="100">100</option>
    </select>
    <span style="font-size:12px;color:#888;">筆</span>
    <button class="btn btn-sm btn-default" style="border-radius:12px;" onclick="printDailyReport()"><i class="fa fa-print"></i> 列印</button>
  </div>
  <div style="overflow-x:auto;">
    <table class="table table-hover tbl-sm" id="rpt-table" style="margin:0;">
      <thead><tr>
        <th>異動日期</th><th>類型</th><th>料號</th><th>種類</th><th>客戶</th><th>儲位</th>
        <th>數量</th><th>異動前</th><th>異動後</th><th>料號總庫存</th>
        <th>請領部門</th><th>請領人</th><th>倉管</th><th>備註</th>
      </tr></thead>
      <tbody id="rpt-tbody"><tr><td colspan="14" class="text-center text-muted">點擊「入出庫紀錄」分頁以載入</td></tr></tbody>
    </table>
  </div>
</div>

</div></div></div><!-- /right_col /main_container /container -->
<div id="toast-wrap"></div>
<!-- 即時通知面板 -->
<div id="req-notif-panel" style="position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:6px;max-width:320px;pointer-events:none;"></div>

<!-- ══ Modal: 新增需求單 ══ -->
<div class="modal fade" id="createReqModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-clipboard"></i> 新增領庫需求單</h4></div>
      <div class="modal-body">
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label>申請部門</label>
            <select id="cr-dept" class="form-control input-sm" onchange="loadReqUsers()"><option value="">-- 選擇部門 --</option></select>
          </div></div>
          <div class="col-sm-4"><div class="form-group"><label>申請人 <span class="text-muted" style="font-size:11px;font-weight:400;">（固定為登入者）</span></label>
            <input type="text" id="cr-user-display" class="form-control input-sm" readonly style="background:#f5f5f5;cursor:not-allowed;">
          </div></div>
          <div class="col-sm-4"><div class="form-group"><label>標題（選填）</label>
            <input type="text" id="cr-title" class="form-control input-sm" placeholder="需求單標題">
          </div></div>
        </div>
        <div class="form-group"><label>備註（選填）</label>
          <textarea id="cr-remark" class="form-control input-sm" rows="2" placeholder="備註說明"></textarea>
        </div>
        <hr style="margin:10px 0;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
          <label style="font-weight:600;margin:0;"><i class="fa fa-search"></i> 搜尋並加入料號</label>
          <span id="cr-item-count" style="font-size:12px;color:#888;">已加入 <span id="cr-item-count-num">0</span> / 40 筆</span>
        </div>
        <div style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
          <div style="position:relative;flex:0 0 160px;">
            <input type="text" id="cr-filter-client" class="form-control input-sm" placeholder="客戶篩選" autocomplete="off" oninput="crClientAcInput()" style="width:100%;">
            <input type="hidden" id="cr-filter-client-val">
            <div id="cr-client-ac" style="display:none;position:absolute;z-index:9999;background:#fff;border:1px solid #ddd;border-radius:4px;max-height:160px;overflow-y:auto;width:220px;box-shadow:0 2px 8px rgba(0,0,0,.12);top:100%;left:0;"></div>
          </div>
          <select id="cr-filter-cat" class="form-control input-sm" style="flex:0 0 110px;" onchange="searchReqItems()">
            <option value="">全部種類</option>
          </select>
          <input type="text" id="cr-item-search" class="form-control input-sm" placeholder="料號 / 備註搜尋" style="flex:1;min-width:120px;" oninput="searchReqItems()">
          <button type="button" class="btn btn-default btn-sm" onclick="clearReqFilters()" title="清除所有篩選條件"><i class="fa fa-times"></i> 取消篩選</button>
        </div>
        <div id="cr-item-results" style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;margin-bottom:10px;display:none;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.1);"></div>
        <label style="font-weight:600;margin-top:4px;"><i class="fa fa-list"></i> 需求清單</label>
        <div style="overflow-x:auto;"><table class="table tbl-sm" style="margin:0;">
          <thead><tr><th>料號</th><th>客戶</th><th>種類</th><th>儲位</th><th>庫存</th><th>申請數量</th><th>備註</th><th style="text-align:center;">急</th><th></th></tr></thead>
          <tbody id="cr-items-tbody"><tr id="cr-empty-row"><td colspan="9" class="text-center text-muted" style="padding:20px;">尚未加入任何料號</td></tr></tbody>
        </table></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" onclick="submitCreateReq()"><i class="fa fa-save"></i> 建立需求單</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: 需求單詳情 ══ -->
<div class="modal fade" id="reqDetailModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-clipboard"></i> 需求單詳情</h4>
        <div id="req-detail-badges" style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;"></div>
      </div>
      <div class="modal-body" id="req-detail-body" style="padding:0 15px 15px;">
        <div class="text-center text-muted" style="padding:30px;">載入中...</div>
      </div>
      <div class="modal-footer" id="req-detail-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        <button type="button" id="btn-edit-req" class="btn btn-default" onclick="openEditReqModal()" style="display:none;"><i class="fa fa-pencil"></i> 修改需求單</button>
        <button type="button" id="btn-open-issue" class="btn btn-warning" onclick="openIssueModal()" style="display:none;"><i class="fa fa-sign-out"></i> 執行出庫</button>
        <button type="button" id="btn-print-req" class="btn btn-info" onclick="printRequisition()"><i class="fa fa-print"></i> 列印</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: 執行出庫 ══ -->
<div class="modal fade" id="issueReqModal" tabindex="-1">
  <div class="modal-dialog" id="issue-modal-dialog" style="width:min(95vw,960px);">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-sign-out"></i> 執行出庫</h4></div>
      <div class="modal-body" id="issue-modal-body" style="padding:12px 16px;">
        <div class="text-center text-muted" style="padding:30px;">載入中...</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" id="issue-req-confirm-btn" onclick="confirmIssueReq()"><i class="fa fa-check"></i> 確認出庫</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: 修改需求單 ══ -->
<div class="modal fade" id="editReqModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-pencil"></i> 修改需求單</h4></div>
      <div class="modal-body" style="padding:14px;">
        <div id="edit-req-header"></div>
        <hr style="margin:10px 0;">
        <div style="margin-bottom:6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <label style="font-weight:600;margin:0;"><i class="fa fa-search"></i> 搜尋並加入料號</label>
          <span id="edit-item-count" style="font-size:12px;color:#888;">共 <span id="edit-item-count-num">0</span> / 40 筆</span>
        </div>
        <div style="display:flex;gap:6px;margin-bottom:6px;align-items:center;flex-wrap:wrap;">
          <div style="position:relative;flex:0 0 160px;">
            <input type="text" id="edit-filter-client" class="form-control input-sm" placeholder="客戶篩選" autocomplete="off" oninput="editClientAcInput()" style="width:100%;">
            <input type="hidden" id="edit-filter-client-val">
            <div id="edit-client-ac" style="display:none;position:absolute;z-index:9999;background:#fff;border:1px solid #ddd;border-radius:4px;max-height:160px;overflow-y:auto;width:220px;box-shadow:0 2px 8px rgba(0,0,0,.12);top:100%;left:0;"></div>
          </div>
          <select id="edit-filter-cat" class="form-control input-sm" style="flex:0 0 110px;" onchange="searchEditItems()">
            <option value="">全部種類</option>
          </select>
          <input type="text" id="edit-item-search" class="form-control input-sm" placeholder="料號 / 備註搜尋" style="flex:1;min-width:120px;" oninput="searchEditItems()">
          <button type="button" class="btn btn-default btn-sm" onclick="clearEditFilters()" title="清除所有篩選條件"><i class="fa fa-times"></i> 取消篩選</button>
        </div>
        <div id="edit-item-results" style="max-height:180px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;margin-bottom:10px;display:none;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.1);"></div>
        <label style="font-weight:700;margin-bottom:4px;"><i class="fa fa-list"></i> 需求清單</label>
        <div style="overflow-x:auto;"><table class="table tbl-sm" style="margin:0;min-width:700px;">
          <thead><tr><th>料號</th><th>客戶</th><th>種類</th><th>儲位</th><th>總庫存</th><th style="width:100px;">申請量</th><th>備註</th><th style="width:40px;text-align:center;">急</th><th></th></tr></thead>
          <tbody id="edit-items-tbody"></tbody>
        </table></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" onclick="submitEditReq()"><i class="fa fa-save"></i> 儲存修改</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: 刪除需求單確認 ══ -->
<div class="modal fade" id="deleteReqModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-content">
      <div class="modal-header" style="background:#fdf2f2;border-bottom:1px solid #f5c6cb;">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title" style="color:#c0392b;"><i class="fa fa-trash"></i> 確認刪除需求單</h4>
      </div>
      <div class="modal-body">
        <p style="color:#555;">此操作將軟刪除需求單，可在「已刪除」清單中查看記錄。</p>
        <div class="form-group">
          <label style="font-weight:700;">刪除原因 <span style="color:red;">*</span></label>
          <textarea id="del-req-reason" class="form-control" rows="2" placeholder="請輸入刪除原因（必填）" maxlength="200"></textarea>
        </div>
        <div class="form-group">
          <label style="font-weight:700;">請輸入大寫 <span style="color:red;font-size:18px;">Y</span> 確認刪除</label>
          <input type="text" id="del-req-confirm-input" class="form-control" autocomplete="off" placeholder="輸入 Y 確認" maxlength="1" style="width:100px;font-size:18px;font-weight:700;text-align:center;">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" onclick="confirmDeleteReq()"><i class="fa fa-trash"></i> 確認刪除</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: 已刪除需求單 ══ -->
<div class="modal fade" id="deletedReqModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-trash-o"></i> 已刪除的領庫需求單</h4></div>
      <div class="modal-body" id="deleted-req-body" style="padding:12px;">
        <div class="text-center text-muted" style="padding:20px;">載入中...</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: 儲位移動 ══ -->
<div class="modal fade" id="moveLocModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-exchange"></i> 整批移動儲位</h4></div>
      <div class="modal-body">
        <p style="margin-bottom:14px;">將來源儲位的<strong>所有庫存品項</strong>一次移動到目標儲位。若目標儲位已有相同料號+客戶，數量將自動合併。</p>
        <div class="form-group">
          <label>來源儲位</label>
          <input type="text" class="form-control" id="ml-from-label" readonly style="background:#f5f5f5;">
          <input type="hidden" id="ml-from-id">
        </div>
        <div class="form-group">
          <label>目標儲位 <span style="color:red;">*</span></label>
          <select class="form-control" id="ml-to-id"></select>
        </div>
        <div id="ml-warn" style="display:none;color:#c0392b;font-size:12px;margin-top:6px;"><i class="fa fa-exclamation-triangle"></i> <span id="ml-warn-msg"></span></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-warning" id="ml-confirm-btn" onclick="confirmMoveLoc()"><i class="fa fa-exchange"></i> 確認移動</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: 新增/編輯庫存品項 ══ -->
<div class="modal fade" id="itemModal" tabindex="-1">
<div class="modal-dialog modal-lg" style="max-height:90vh;display:flex;flex-direction:column;margin-top:4vh;"><div class="modal-content" style="display:flex;flex-direction:column;max-height:88vh;overflow:hidden;">
<div class="modal-header" style="flex-shrink:0;"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="itemTitle">新增庫存品項</h4></div>
<div class="modal-body" style="padding:18px;overflow-y:auto;flex:1 1 auto;">
  <input type="hidden" id="item-id">
  <div class="row">
    <div class="col-sm-6">
      <div class="fs"><h6><i class="fa fa-tag"></i> 基本資料</h6>
        <div class="form-group" style="position:relative;">
          <label>料號 <span style="color:red;">*</span></label>
          <input type="text" id="item-did" class="form-control" placeholder="輸入料號搜尋..." autocomplete="off">
          <input type="hidden" id="item-dsid">
          <div class="ac-box" id="ac-did" style="display:none;"></div>
          <div id="item-asm-display" style="margin-top:5px;"></div>
        </div>
        <div class="form-group"><label>品項種類 <span style="color:red;">*</span></label>
          <select id="item-cat" class="form-control" onchange="onCatChange()"><option value="">— 選擇種類 —</option></select>
        </div>
        <div class="form-group"><label>客戶 <small class="text-muted">（由料號自動帶入）</small></label>
          <input type="text" id="item-client" class="form-control" readonly style="background:#f5f5f5;color:#555;" placeholder="選定料號後自動帶入">
          <input type="hidden" id="item-cid">
        </div>
        <div class="form-group" id="item-keeper-wrap" style="display:none;position:relative;"><label>保管者</label>
          <input type="text" id="item-keeper" class="form-control" placeholder="輸入部門 / 姓名搜尋..." autocomplete="off">
          <input type="hidden" id="item-keeper-id">
          <div class="ac-box" id="ac-keeper" style="display:none;"></div>
        </div>
        <div class="form-group" id="item-vendor-wrap" style="display:none;position:relative;"><label>廠商</label>
          <input type="text" id="item-vendor" class="form-control" placeholder="輸入廠商名稱 / 編號搜尋..." autocomplete="off">
          <input type="hidden" id="item-vendor-id">
          <div class="ac-box" id="ac-vendor" style="display:none;"></div>
        </div>
        <div class="row">
          <div class="col-xs-6"><div class="form-group"><label>庫存數量 <span style="color:red;">*</span></label><input type="number" id="item-qty" class="form-control no-spin" value="0" min="0" step="0.001"></div></div>
          <div class="col-xs-6"><div class="form-group"><label>主計量單位 <span style="color:red;">*</span></label>
            <select id="item-unit" class="form-control"><option value="">— 選擇 —</option></select>
          </div></div>
        </div>
        <div class="form-group" id="item-unit-manage-wrap" style="display:none;">
          <small class="text-muted">可 <a href="#" onclick="openItemUnitsModal();return false;">管理此料號的可用單位</a></small>
        </div>
      </div>
    </div>
    <div class="col-sm-6">
      <div class="fs"><h6><i class="fa fa-map-marker"></i> 位置與日期</h6>
        <div class="form-group"><label>儲位 <span style="color:red;">*</span></label>
          <select id="item-loc" class="form-control" onclick="if(!$('#item-cat').val()){event.preventDefault();toast('請先選擇品項種類，系統將依種類篩選可用儲位','error');$('#item-cat').focus();return false;}"><option value="">— 請先選擇品項種類 —</option></select>
          <small id="item-loc-hint" style="color:#aaa;font-size:11px;"></small>
        </div>
        <div class="row">
          <div class="col-xs-6"><div class="form-group"><label>入庫日期</label><input type="date" id="item-sd" class="form-control" onchange="onStockDateChange()"></div></div>
          <div class="col-xs-6"><div class="form-group"><label>製造日期 <small class="text-muted">（空=同入庫日）</small></label><input type="date" id="item-mfg" class="form-control"></div></div>
        </div>
        <div class="row">
          <div class="col-xs-5"><div class="form-group"><label>保存年限(年)</label><input type="number" id="item-ey" class="form-control" placeholder="自動帶入"></div></div>
          <div class="col-xs-7"><div class="form-group"><label>包裝箱</label><input type="text" id="item-pkg" class="form-control" placeholder="例：10入×20箱"></div></div>
        </div>
      </div>
    </div>
  </div>
  <div class="row" id="bom-order-section">
    <div class="col-sm-6" id="bom-col">
      <div class="fs"><h6><i class="fa fa-link"></i> 成本來源（BOM）</h6>
        <div class="form-group">
          <label>製令號 (BOM) <small id="item-bom-count" style="font-weight:400;color:#aaa;"></small></label>
          <select id="item-bom-sel" class="form-control" onchange="onItemBomSelect(this.value)">
            <option value="">— 選擇BOM（選定料號後自動載入）—</option>
          </select>
          <input type="hidden" id="item-bom">
          <div id="bom-info" style="margin-top:4px;font-size:11px;color:var(--accent);"></div>
        </div>
        <div class="row">
          <div class="col-xs-6"><div class="form-group">
            <label>單位成本 <small id="item-cost-src-lbl" style="font-weight:400;color:#27ae60;display:none;">（BOM自動）</small><small id="item-cost-manual-lbl" style="font-weight:400;color:#888;display:none;">（手動）</small></label>
            <input type="number" id="item-cost" class="form-control" step="0.0001" placeholder="有BOM時自動計算">
            <div id="item-cost-hint" style="font-size:11px;color:#27ae60;margin-top:2px;display:none;"></div>
          </div></div>
          <div class="col-xs-6"><div class="form-group">
            <label>售價 <small id="item-price-src-lbl" style="font-weight:400;color:#2980b9;display:none;">（訂單自動）</small><small id="item-price-manual-lbl" style="font-weight:400;color:#888;display:none;">（手動）</small></label>
            <input type="number" id="item-price" class="form-control" step="0.0001" placeholder="有訂單時自動帶入">
            <div id="item-price-hint" style="font-size:11px;color:#2980b9;margin-top:2px;display:none;"></div>
          </div></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6" id="order-col">
      <div class="fs"><h6><i class="fa fa-shopping-cart"></i> 售價來源（訂單）</h6>
        <div class="form-group">
          <label>對應訂單 <small id="item-order-count" style="font-weight:400;color:#aaa;"></small></label>
          <select id="item-order-sel" class="form-control" onchange="onItemOrderSelect(this.value,this.options[this.selectedIndex])">
            <option value="">— 選擇訂單（選定料號後自動載入）—</option>
          </select>
          <input type="hidden" id="item-order-disp">
          <input type="hidden" id="item-order-id">
          <div id="order-info" style="margin-top:4px;font-size:11px;color:var(--info);"></div>
        </div>
      </div>
    </div>
  </div>
  <div class="row">
    <div class="col-sm-6"><div class="fs"><h6><i class="fa fa-sticky-note"></i> 備註</h6>
      <div class="form-group"><label>備註</label><textarea id="item-r1" class="form-control" rows="2" placeholder="一般備註..."></textarea></div>
    </div></div>
  </div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" onclick="saveItem()" style="background:var(--accent);border-color:var(--accent);"><i class="fa fa-save"></i> 儲存</button>
</div>
</div></div></div>

<!-- ══ Modal: 組合件整組入/出庫 ══ -->
<div class="modal fade" id="groupTxnModal" tabindex="-1">
<div class="modal-dialog" style="max-width:800px;"><div class="modal-content">
<div class="modal-header" style="background:linear-gradient(135deg,#f8f9fc,#eef2ff);border-bottom:2px solid var(--accent);">
  <button class="close" data-dismiss="modal"><span>&times;</span></button>
  <h4 class="modal-title" id="grpTxnTitle" style="color:var(--primary);font-weight:700;"><i class="fa fa-cubes"></i> 整組入庫</h4>
</div>
<div class="modal-body" style="padding:20px;">
  <input type="hidden" id="grp-txn-group-id">
  <input type="hidden" id="grp-txn-type">

  <!-- 組合構成摘要 -->
  <div id="grp-txn-info" style="background:#f8f9fc;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;border-left:3px solid var(--accent);"></div>

  <!-- 日期 + 組數 -->
  <div style="display:flex;gap:16px;align-items:flex-end;margin-bottom:16px;flex-wrap:wrap;">
    <div class="form-group" style="margin:0;min-width:150px;">
      <label style="font-weight:600;color:var(--primary);">日期</label>
      <input type="date" id="grp-txn-date" class="form-control">
    </div>
    <div class="form-group" style="margin:0;min-width:120px;" id="grp-txn-sets-wrap">
      <label style="font-weight:600;color:var(--primary);" id="grp-txn-qty-label">入庫組數 <span style="color:red;">*</span></label>
      <input type="number" id="grp-txn-sets" class="form-control" value="1" min="1" step="1" oninput="updateGrpTxnCalc()">
    </div>
    <div id="grp-txn-calc" style="flex:1;background:#fff8e1;border-radius:6px;padding:8px 12px;font-size:12px;border:1px solid #f0d080;display:none;"></div>
  </div>

  <!-- 入庫：子件各自 BOM + 訂單 -->
  <div id="grp-txn-in-section" style="display:none;margin-bottom:16px;">
    <div style="font-weight:700;color:var(--primary);font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:8px;">
      <i class="fa fa-link" style="color:var(--accent);"></i> 各子件製令(BOM)與對應訂單
    </div>
    <div id="grp-txn-members-in" style="border:1px solid #e8edf5;border-radius:8px;overflow:hidden;"></div>
  </div>

  <!-- 出庫：FIFO 批次選擇 -->
  <div id="grp-txn-out-section" style="display:none;margin-bottom:16px;">
    <div style="font-weight:700;color:var(--danger,#e74c3c);font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:8px;">
      <i class="fa fa-arrow-up" style="color:var(--danger,#e74c3c);"></i> 出庫批次選擇
      <small style="font-weight:400;color:#888;">（依先進先出預設全選，可取消勾選特定批次）</small>
    </div>
    <div id="grp-txn-batches" style="border:1px solid #fde8e8;border-radius:8px;overflow:hidden;"></div>
    <div id="grp-txn-out-summary" style="margin-top:8px;padding:8px 12px;background:#fff8f8;border-radius:6px;font-size:12px;display:none;"></div>
  </div>

  <!-- 整組入庫：組合件料號綁定訂單 -->
  <div id="grp-txn-group-order-wrap" style="display:none;margin-bottom:14px;">
    <div style="font-weight:700;color:var(--primary);font-size:13px;margin-bottom:8px;"><i class="fa fa-shopping-cart" style="color:var(--info);"></i> 組合件料號綁定訂單</div>
    <div id="grp-txn-order-list-container" style="border:1px solid #dee2e6;border-radius:4px;max-height:180px;overflow-y:auto;"></div>
    <input type="hidden" id="grp-txn-order-ref">
  </div>

  <!-- 整組出庫：需求部門/人員 -->
  <div id="grp-txn-dept-wrap" style="display:none;margin-bottom:14px;">
    <div style="font-weight:700;color:var(--primary);font-size:13px;margin-bottom:8px;"><i class="fa fa-users" style="color:var(--danger,#e74c3c);"></i> 需求部門 / 人員</div>
    <div class="row">
      <div class="col-sm-6">
        <div class="form-group" style="margin-bottom:0;">
          <label style="font-size:12px;color:#666;">需求部門</label>
          <select id="grp-txn-dept" class="form-control" onchange="grpTxnLoadUsers()"><option value="">— 選擇部門 —</option></select>
        </div>
      </div>
      <div class="col-sm-6">
        <div class="form-group" style="margin-bottom:0;">
          <label style="font-size:12px;color:#666;">需求人員</label>
          <select id="grp-txn-user" class="form-control"><option value="">— 選擇人員 —</option></select>
        </div>
      </div>
    </div>
  </div>

  <div class="form-group" style="margin-bottom:0;">
    <label style="font-weight:600;color:var(--primary);">備註</label>
    <textarea id="grp-txn-remark" class="form-control" rows="2" placeholder="選填..."></textarea>
  </div>
</div>
<div class="modal-footer" style="background:#f8f9fc;border-top:1px solid #e8edf5;">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn" id="grp-txn-confirm-btn" style="background:var(--accent);color:#fff;border-color:var(--accent);font-weight:600;" onclick="confirmGroupTxn()">
    <i class="fa fa-arrow-down"></i> 確認整組入庫
  </button>
</div>
</div></div></div>

<!-- ══ Modal: 組合件整組編輯 ══ -->
<div class="modal fade" id="groupEditModal" tabindex="-1">
<div class="modal-dialog" style="max-width:2100px;"><div class="modal-content">
<div class="modal-header" style="background:linear-gradient(135deg,#f8f9fc,#eef2ff);border-bottom:2px solid var(--accent);">
  <button class="close" data-dismiss="modal"><span>&times;</span></button>
  <h4 class="modal-title" style="color:var(--primary);font-weight:700;"><i class="fa fa-pencil"></i> 編輯組合件庫存</h4>
</div>
<div class="modal-body">
  <input type="hidden" id="grp-edit-group-id">
  <div id="grp-edit-info" style="background:#f8f9fc;border-radius:8px;padding:9px 13px;margin-bottom:12px;font-size:12px;"></div>
  <div class="row">
    <div class="col-xs-4">
      <div class="form-group">
        <label>目前庫存（組）</label>
        <input type="number" id="grp-edit-sets" class="form-control" min="0" step="1" placeholder="幾組" oninput="updateGrpEditCalc()">
      </div>
    </div>
    <div class="col-xs-8">
      <div id="grp-edit-calc" style="padding-top:26px;font-size:12px;color:#856404;"></div>
    </div>
  </div>
  <div id="grp-edit-members" style="margin-top:8px;"></div>

  <!-- 組合件料號綁定訂單 -->
  <div id="grp-edit-order-section" style="display:none; margin-top:14px;padding-top:12px;border-top:1px solid #eee;">
    <label style="font-weight:700;color:var(--primary);font-size:13px;"><i class="fa fa-shopping-cart" style="color:var(--info);"></i> 組合件料號綁定訂單</label>
    <div id="grp-edit-order-list-container" style="border:1px solid #dee2e6;border-radius:4px;max-height:180px;overflow-y:auto;margin-top:6px;"></div>
    <input type="hidden" id="grp-edit-order-ref">
  </div>

  <div class="form-group" style="margin-top:10px;"><label>調整備註</label><textarea id="grp-edit-remark" class="form-control" rows="2" placeholder="選填"></textarea></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" style="background:var(--accent);border-color:var(--accent);" onclick="confirmGroupEdit()"><i class="fa fa-save"></i> 儲存</button>
</div>
</div></div></div>

<!-- ══ Modal: 入庫 ══ -->
<div class="modal fade" id="inModal" tabindex="-1">
<div class="modal-dialog" style="max-width:500px;"><div class="modal-content">
<div class="modal-header" style="background:linear-gradient(135deg,#f8f9fc,#eef2ff);border-bottom:2px solid var(--accent);"><button class="close" data-dismiss="modal"><span style="color:var(--primary);">&times;</span></button><h4 class="modal-title" style="color:var(--primary);font-weight:700;">入庫操作</h4></div>
<div class="modal-body">
  <input type="hidden" id="in-item-id">
  <div id="in-item-info" style="background:#f8f9fc;border-radius:8px;padding:9px 13px;margin-bottom:13px;font-size:12px;"></div>
  <!-- 儲位唯讀顯示：入庫只能到原儲位，新增到其他儲位請用「新增庫存品項」 -->
  <div style="background:#fff8e1;border-left:3px solid #F39C12;border-radius:4px;padding:6px 12px;font-size:12px;color:#a06000;margin-bottom:12px;">
    <i class="fa fa-info-circle"></i> 本次入庫將記錄到原儲位。若需入庫到不同儲位，請使用「新增庫存品項」。
  </div>
  <div class="row">
    <div class="col-xs-6"><div class="form-group"><label>入庫日期</label><input type="date" id="in-date" class="form-control"></div></div>
    <div class="col-xs-6"><div class="form-group"><label>儲位（唯讀）</label>
      <input type="text" id="in-loc-readonly" class="form-control" readonly style="background:#f5f5f5;color:#888;">
    </div></div>
  </div>
  <div class="row">
    <div class="col-xs-5"><div class="form-group"><label>入庫數量 <span style="color:red;">*</span></label><input type="number" id="in-qty" class="form-control" value="1" min="0.001" step="0.001" oninput="calcInConvert()"></div></div>
    <div class="col-xs-4"><div class="form-group"><label>單位</label>
      <select id="in-unit" class="form-control" onchange="calcInConvert()"><option value="">主單位</option></select>
    </div></div>
    <div class="col-xs-3"><div class="form-group"><label>換算後</label><input type="text" id="in-qty-conv" class="form-control" readonly style="background:#f5f5f5;"></div></div>
  </div>
  <div class="convert-hint" id="in-conv-hint" style="display:none;"></div>
  <!-- 綁定BOM：按鈕觸發下拉 -->
  <div class="form-group" style="margin-top:8px;">
    <label>綁定BOM（本次入庫）</label>
    <div style="position:relative;">
      <input type="text" id="in-bom" class="form-control" placeholder="點擊選擇BOM..." readonly style="background:#f9f9f9;cursor:pointer;" onclick="toggleInBomDrop()">
    </div>
    <div id="in-bom-drop" style="display:none;border:1px solid #ddd;border-radius:4px;background:#fff;margin-top:2px;max-height:200px;overflow-y:auto;box-shadow:0 3px 10px rgba(0,0,0,.1);position:relative;z-index:10;">
      <div style="padding:8px 12px;font-size:11px;color:#aaa;text-align:center;" id="in-bom-drop-loading"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>
    </div>
    <div id="in-bom-info" style="font-size:11px;color:var(--accent);margin-top:3px;"></div>
  </div>
  <!-- 對應訂單：按鈕觸發下拉 -->
  <div id="in-order-wrap" class="form-group">
    <label>對應訂單</label>
    <div style="position:relative;">
      <input type="text" id="in-order-disp" class="form-control" placeholder="點擊選擇訂單..." readonly style="background:#f9f9f9;cursor:pointer;" onclick="toggleInOrderDrop()">
      <input type="hidden" id="in-order-id">
    </div>
    <div id="in-order-drop" style="display:none;border:1px solid #ddd;border-radius:4px;background:#fff;margin-top:2px;max-height:220px;overflow-y:auto;box-shadow:0 3px 10px rgba(0,0,0,.1);position:relative;z-index:10;">
      <div style="padding:8px 12px;font-size:11px;color:#aaa;text-align:center;"><i class="fa fa-info-circle"></i> 請先選擇BOM以自動帶入，或直接展開選擇</div>
    </div>
  </div>
  <div class="form-group"><label>包裝箱</label><input type="text" id="in-pkg" class="form-control" placeholder="本次入庫包裝箱資訊"></div>
  <div class="form-group"><label>備註</label><textarea id="in-remark" class="form-control" rows="2" placeholder="入庫說明..."></textarea></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn" style="background:var(--accent);color:#fff;border-color:var(--accent);" onclick="confirmIn()"><i class="fa fa-arrow-down"></i> 確認入庫</button>
</div>
</div></div></div>

<!-- ══ Modal: 出庫 ══ -->
<div class="modal fade" id="outModal" tabindex="-1">
<div class="modal-dialog" style="max-width:480px;"><div class="modal-content">
<div class="modal-header" style="background:linear-gradient(135deg,#f8f9fc,#eef2ff);border-bottom:2px solid var(--danger);"><button class="close" data-dismiss="modal"><span style="color:var(--primary);">&times;</span></button><h4 class="modal-title" style="color:var(--primary);font-weight:700;">出庫操作 (FIFO 批次出庫)</h4></div>
<div class="modal-body">
  <input type="hidden" id="out-item-id">
  <div id="out-item-info" style="background:#f8f9fc;border-radius:8px;padding:9px 13px;margin-bottom:13px;font-size:12px;"></div>
  <div id="out-batches-wrap" style="border:1px solid #fde8e8;border-radius:8px;overflow:hidden;margin-bottom:14px;max-height:200px;overflow-y:auto;"></div>
  <div class="row" style="display:none;">
    <div class="col-xs-6"><div class="form-group"><label>出庫日期</label><input type="date" id="out-date" class="form-control"></div></div>
  </div>
  <div class="row">
    <div class="col-xs-5"><div class="form-group"><label>出庫數量 <span style="color:red;">*</span></label><input type="number" id="out-qty" class="form-control" value="1" min="0.001" step="0.001" oninput="calcOutConvert()"></div></div>
    <div class="col-xs-4"><div class="form-group"><label>單位</label><select id="out-unit" class="form-control" onchange="calcOutConvert()"><option value="">主單位</option></select></div></div>
    <div class="col-xs-3"><div class="form-group"><label>換算後</label><input type="text" id="out-qty-conv" class="form-control" readonly style="background:#f5f5f5;"></div></div>
  </div>
  <div class="convert-hint" id="out-conv-hint" style="display:none;"></div>
  <div class="row">
    <div class="col-xs-6"><div class="form-group"><label>需求部門</label>
      <select id="out-dept" class="form-control" onchange="loadOutUsers()"><option value="">— 選擇部門 —</option></select>
    </div></div>
    <div class="col-xs-6"><div class="form-group"><label>需求人員</label>
      <select id="out-user" class="form-control"><option value="">— 選擇人員 —</option></select>
    </div></div>
  </div>
  <div class="form-group"><label>備註</label><textarea id="out-remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn" id="out-confirm-btn" style="background:var(--danger);color:#fff;border-color:var(--danger);" onclick="confirmOut()"><i class="fa fa-arrow-up"></i> 確認出庫</button>
</div>
</div></div></div>

<!-- ══ Modal: 移位 ══ -->
<div class="modal fade" id="moveModal" tabindex="-1">
<div class="modal-dialog" style="max-width:440px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">位置移動</h4></div>
<div class="modal-body">
  <input type="hidden" id="move-item-id">
  <div id="move-item-info" style="background:#f8f9fc;border-radius:8px;padding:9px 13px;margin-bottom:13px;font-size:12px;"></div>
  <div class="form-group"><label>移出位置</label><input type="text" id="move-from" class="form-control" readonly style="background:#f5f5f5;"></div>
  <div class="form-group"><label>移入儲位 <span style="color:red;">*</span></label>
    <select id="move-to-id" class="form-control" onchange="onMoveLocChange()"><option value="">— 選擇儲位 —</option></select>
    <input type="text" id="move-to-str" class="form-control" placeholder="或手動輸入位置..." style="margin-top:5px;">
  </div>
  <div class="form-group"><label>日期</label><input type="date" id="move-date" class="form-control"></div>
  <div class="form-group"><label>備註</label><textarea id="move-remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn" style="background:var(--info);color:#fff;" onclick="confirmMove()"><i class="fa fa-exchange"></i> 確認移位</button>
</div>
</div></div></div>

<!-- ══ Modal: 詳情 ══ -->
<div class="modal fade" id="detailModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">庫存詳情</h4></div>
<div class="modal-body">
  <div class="row">
    <div class="col-sm-5"><div id="detail-info"></div></div>
    <div class="col-sm-7">
      <div class="fs" style="height:100%;">
        <h6 style="display:flex;align-items:center;justify-content:space-between;">
          <span><i class="fa fa-history"></i> 異動歷程</span>
          <span id="split-txn-toolbar" style="display:none;gap:6px;align-items:center;">
            <span id="split-sel-count" style="font-size:11px;color:#888;font-weight:400;"></span>
            <button class="btn btn-xs" style="background:var(--accent);color:#fff;" onclick="openSplitFromTxn()">
              <i class="fa fa-scissors"></i> 拆分選取
            </button>
            <button class="btn btn-xs btn-default" onclick="clearTxnSelection()">
              <i class="fa fa-times"></i> 清除選取
            </button>
          </span>
        </h6>
        <div class="txn-list" id="detail-txns"></div>
      </div>
    </div>
  </div>
</div>
</div></div></div>

<!-- ══ Modal: 異動歷程拆分（A權限）══ -->
<div class="modal fade" id="splitTxnModal" tabindex="-1">
<div class="modal-dialog modal-lg" style="max-width:680px;margin-top:4vh;">
  <div class="modal-content" style="display:flex;flex-direction:column;max-height:88vh;overflow:hidden;">
    <div class="modal-header">
      <button class="close" data-dismiss="modal"><span>&times;</span></button>
      <h4 class="modal-title"><i class="fa fa-scissors"></i> 拆分庫存料號</h4>
    </div>
    <div class="modal-body" style="overflow-y:auto;flex:1 1 auto;padding:16px;">
      <input type="hidden" id="split-stock-item-id">
      <!-- 原筆資訊 -->
      <div id="split-orig-info" style="background:#f8f9fc;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;"></div>
      
      <!-- 目的地與總數確認 -->
      <div class="row fs" style="margin: 0 0 12px 0; border: 1px solid var(--accent); background: #f0fdf8;">
          <div class="col-sm-6">
              <label>移入新儲位 <span style="color:red;">*</span></label>
              <select id="split-target-loc-id" class="form-control" onchange="var s=$('#split-target-loc-id option:selected'); $('#split-target-loc-code').val(s.val()?s.text().split('] ')[1]||s.text():'');">
                  <option value="">— 選擇儲位 —</option>
              </select>
              <input type="hidden" id="split-target-loc-code">
          </div>
          <div class="col-sm-6">
              <label>確認搬移總數 <span style="color:red;">*</span></label>
              <div style="display:flex; gap:4px;">
                  <input type="number" id="split-total-confirm" class="form-control" style="font-weight:bold; color:var(--accent); font-size:16px; flex:1;" placeholder="輸入數量" step="0.0001">
                  <select id="split-target-unit-id" class="form-control" style="width:85px;"></select>
              </div>
              <small class="text-muted">寫入新項 qty，可依實際庫存調整</small>
          </div>
          <div class="col-sm-6">
              <label>原項剩餘數量</label>
              <input type="number" id="split-remain-confirm" class="form-control" style="font-weight:bold; color:var(--primary);" step="0.0001">
              <small class="text-muted">更新原項 qty</small>
          </div>
      </div>

      <!-- 警示 -->
      <div style="background:#fff8e1;border-left:3px solid #F39C12;border-radius:0 6px 6px 0;padding:8px 12px;font-size:12px;color:#a06000;margin-bottom:14px;">
        <i class="fa fa-info-circle"></i>
        <strong>拆分規則：</strong>所選取的異動紀錄將「整筆搬移」至新的庫存品項下。請確認移入儲位，並輸入選取紀錄的總量作為確認。
      </div>
      <!-- 拆分列表 -->
      <div id="split-rows-wrap"></div>
      <!-- 數量摘要 -->
      <div id="split-qty-summary" style="margin-top:12px;padding:10px 14px;background:#f0fdf8;border-radius:8px;font-size:13px;font-weight:600;color:var(--primary);"></div>
    </div>
    <div class="modal-footer" style="flex-shrink:0;">
      <button class="btn btn-default" data-dismiss="modal">取消</button>
      <button class="btn" style="background:var(--accent);color:#fff;border-color:var(--accent);" onclick="confirmSplitTxn()">
        <i class="fa fa-scissors"></i> 確認拆分
      </button>
    </div>
  </div>
</div>
</div>

<!-- ══ Modal: 儲位編輯 ══ -->
<div class="modal fade" id="locModal" tabindex="-1">
    <div class="modal-dialog" style="max-width:420px;">
        <div class="modal-content">
            <div class="modal-header" style="flex-shrink:0;"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="locTitle">新增儲位</h4></div>
            <div class="modal-body">
  <input type="hidden" id="loc-id">
  <div class="form-group"><label>廠區 <span style="color:red;">*</span></label>
    <select id="loc-area" class="form-control"><option value="">— 請先選擇廠區 —</option></select>
    <small class="text-muted">廠區為必填，若無廠區請先至「廠區管理」新增</small>
  </div>
  <div class="form-group"><label>儲位代碼 <span style="color:red;">*</span></label>
    <input type="text" id="loc-code" class="form-control" placeholder="例：大鐵架 1-2-1">
    <small id="loc-code-hint" style="color:#aaa;font-size:11px;"></small>
  </div>
  <div class="form-group"><label>說明</label><input type="text" id="loc-name" class="form-control"></div>
  <div class="form-group"><label>排序</label><input type="number" id="loc-sort" class="form-control" value="0"></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" onclick="saveLoc()" style="background:var(--accent);border-color:var(--accent);">儲存</button>
</div>
</div></div></div>

<!-- ══ Modal: 廠區管理 ══ -->
<div class="modal fade" id="areaModal" tabindex="-1">
<div class="modal-dialog" style="max-width:420px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-building"></i> 廠區管理</h4></div>
<div class="modal-body">
  <div style="display:flex;gap:6px;margin-bottom:10px;">
    <input type="text" id="new-area-name" class="form-control" placeholder="廠區名稱，例：一廠、倉庫A">
    <input type="number" id="new-area-sort" class="form-control" placeholder="排序" style="width:70px;">
    <button class="btn btn-sm" style="background:var(--accent);color:#fff;white-space:nowrap;" onclick="saveArea(0)"><i class="fa fa-plus"></i> 新增</button>
  </div>
  <table class="table tbl-sm" style="margin:0;"><thead><tr><th>廠區名稱</th><th>排序</th><th></th></tr></thead>
  <tbody id="area-list-body"><tr><td colspan="3" class="text-center text-muted">載入中...</td></tr></tbody></table>
</div>
<div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>
</div></div></div>


<!-- ══ Modal: 批次新增庫存 ══ -->
<div class="modal fade" id="batchAddModal" tabindex="-1">
<div class="modal-dialog" style="width:1120px;max-width:97vw;"><div class="modal-content" style="display:flex;flex-direction:column;max-height:92vh;">
  <div class="modal-header" style="flex-shrink:0;"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-th-list"></i> 批次新增庫存</h4></div>
  <div class="modal-body" style="overflow-y:auto;flex:1 1 auto;padding:14px 18px;">
    <div class="row">
      <div class="col-sm-5"><div class="form-group"><label>品項種類 <span style="color:red;">*</span> <small class="text-muted">（整批限定同一品項種類）</small></label>
        <select id="batch-cat" class="form-control" onchange="onBatchCatChange()"><option value="">— 選擇品項種類 —</option></select></div></div>
    </div>
    <div class="row">
      <!-- 左：料號篩選 + 多選 -->
      <div class="col-sm-6">
        <div class="fs"><h6><i class="fa fa-filter"></i> 料號篩選與多選</h6>
          <div class="row">
            <div class="col-xs-6"><label style="font-size:12px;margin-bottom:2px;">料號種類</label><select id="batch-f-type" class="form-control input-sm" onchange="onBatchTypeChange()"><option value="all">全部</option></select></div>
            <div class="col-xs-6"><label style="font-size:12px;margin-bottom:2px;">工件小類</label><select id="batch-f-sub" class="form-control input-sm"><option value="0">全部</option></select></div>
          </div>
          <div style="margin-top:6px;display:flex;gap:6px;"><input type="text" id="batch-f-kw" class="form-control input-sm" placeholder="料號 / 規格 關鍵字..." onkeydown="if(event.key==='Enter'){batchSearchParts();return false;}"><button class="btn btn-sm btn-primary" onclick="batchSearchParts()" style="white-space:nowrap;"><i class="fa fa-search"></i> 查詢</button></div>
          <div style="margin-top:6px;display:flex;gap:10px;align-items:center;">
            <label style="font-weight:400;margin:0;font-size:12px;cursor:pointer;"><input type="checkbox" id="batch-chk-all" onclick="batchToggleAll(this.checked)"> 全選 / 取消全選</label>
            <span id="batch-found-cnt" style="font-size:11px;color:#888;"></span>
          </div>
          <div id="batch-part-list" style="margin-top:6px;max-height:300px;overflow-y:auto;border:1px solid #e0e0e0;border-radius:6px;padding:4px 6px;background:#fafafa;font-size:12px;">
            <div class="text-muted" style="padding:10px;">請設定篩選條件後按「查詢」</div>
          </div>
          <div style="margin-top:6px;"><button class="btn btn-sm btn-success" onclick="batchAddSelected()"><i class="fa fa-angle-double-right"></i> 加入待新增清單</button></div>
        </div>
      </div>
      <!-- 右：待新增清單 + 批次設定 -->
      <div class="col-sm-6">
        <div class="fs"><h6><i class="fa fa-list-ul"></i> 待新增清單 <small id="batch-sel-cnt" class="text-muted"></small></h6>
          <div style="background:#f0f7f5;border:1px solid #cfe8e0;border-radius:6px;padding:6px 8px;font-size:12px;">
            <div style="color:#666;margin-bottom:4px;">一鍵套用到<b>勾選列</b>（未勾選任何列＝套用全部）：</div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
              <span>數量 <input type="number" id="batch-set-qty" class="form-control input-sm no-spin" style="width:64px;display:inline-block;height:26px;"> <button class="btn btn-xs btn-default" onclick="batchApply('qty')">套用</button></span>
              <span>單位 <select id="batch-set-unit" class="form-control input-sm" style="width:90px;display:inline-block;height:26px;"></select> <button class="btn btn-xs btn-default" onclick="batchApply('unit')">套用</button></span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:5px;">
              <span>儲位 <select id="batch-set-loc" class="form-control input-sm" style="width:160px;display:inline-block;height:26px;"></select> <button class="btn btn-xs btn-default" onclick="batchApply('loc')">套用</button></span>
              <span>入庫日期 <input type="date" id="batch-set-date" class="form-control input-sm" style="width:130px;display:inline-block;height:26px;"> <button class="btn btn-xs btn-default" onclick="batchApply('date')">套用</button></span>
            </div>
            <div id="batch-set-kv" style="display:none;flex-wrap:wrap;gap:10px;align-items:center;margin-top:5px;">
              <span id="batch-set-keeper-wrap" style="display:none;position:relative;">保管者 <input type="text" id="batch-set-keeper" class="form-control input-sm" style="width:120px;display:inline-block;height:26px;" placeholder="姓名/部門" autocomplete="off"><input type="hidden" id="batch-set-keeper-id"><div class="ac-box" id="ac-batch-keeper" style="display:none;"></div> <button class="btn btn-xs btn-default" onclick="batchApply('keeper')">套用</button></span>
              <span id="batch-set-vendor-wrap" style="display:none;position:relative;">廠商 <input type="text" id="batch-set-vendor" class="form-control input-sm" style="width:120px;display:inline-block;height:26px;" placeholder="名稱/編號" autocomplete="off"><input type="hidden" id="batch-set-vendor-id"><div class="ac-box" id="ac-batch-vendor" style="display:none;"></div> <button class="btn btn-xs btn-default" onclick="batchApply('vendor')">套用</button></span>
            </div>
          </div>
          <div style="overflow-x:auto;margin-top:6px;"><table class="table tbl-sm" style="margin:0;font-size:12px;">
            <thead><tr><th style="width:24px;text-align:center;"><input type="checkbox" id="batch-row-chk-all" onclick="batchRowToggleAll(this.checked)"></th><th>料號</th><th style="width:66px;">數量</th><th style="width:78px;">單位</th><th style="width:150px;">儲位</th><th style="width:30px;"></th></tr></thead>
            <tbody id="batch-add-tbody"><tr><td colspan="6" class="text-center text-muted">尚未加入料號</td></tr></tbody>
          </table></div>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <span id="batch-msg" style="font-size:12px;color:#888;margin-right:10px;"></span>
    <button class="btn btn-default" data-dismiss="modal">取消</button>
    <button class="btn btn-primary" id="batch-save-btn" onclick="saveBatchStock()" style="background:var(--accent);border-color:var(--accent);"><i class="fa fa-save"></i> 批次儲存</button>
  </div>
</div></div></div>

<!-- ══ Modal: 種類編輯 ══ -->
<div class="modal fade" id="catModal" tabindex="-1">
<div class="modal-dialog" style="max-width:520px;max-height:90vh;display:flex;flex-direction:column;margin-top:4vh;"><div class="modal-content" style="display:flex;flex-direction:column;max-height:88vh;overflow:hidden;">
<div class="modal-header" style="flex-shrink:0;"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="catTitle">新增品項種類</h4></div>
<div class="modal-body" style="padding:18px;overflow-y:auto;flex:1 1 auto;">
  <input type="hidden" id="cat-id">
  <div class="row"><div class="col-xs-8"><div class="form-group"><label>種類名稱 <span style="color:red;">*</span></label><input type="text" id="cat-name" class="form-control"></div></div>
  <div class="col-xs-4"><div class="form-group"><label>代碼</label><input type="text" id="cat-code" class="form-control" placeholder="FG"></div></div></div>
  <div class="row"><div class="col-xs-6"><div class="form-group"><label>預設保存年限(年)</label><input type="number" id="cat-ey" class="form-control" placeholder="留空=不設定"></div></div>
  <div class="col-xs-6"><div class="form-group"><label>顯示顏色</label><input type="color" id="cat-color" class="form-control" value="#1ABB9C" style="height:33px;padding:2px;"></div></div></div>
  <div class="row"><div class="col-xs-6"><div class="form-group"><label>需要綁定BOM</label>
    <select id="cat-bom" class="form-control"><option value="0">否</option><option value="1">是</option></select></div></div>
  <div class="col-xs-6"><div class="form-group"><label>需要綁定訂單</label>
    <select id="cat-order" class="form-control"><option value="0">否</option><option value="1">是</option></select></div></div></div>
  <div class="row"><div class="col-xs-6"><div class="form-group"><label>顯示在首頁快速篩選</label>
    <select id="cat-dashboard" class="form-control"><option value="1">是</option><option value="0">否</option></select></div></div>
  <div class="col-xs-6"><div class="form-group"><label>排序</label><input type="number" id="cat-sort" class="form-control" value="0"></div></div></div>

  <!-- 列表顯示欄位設定（需求5） -->
  <div class="form-group" style="margin-top:4px;">
    <label><i class="fa fa-list-alt" style="color:var(--accent);"></i> 列表顯示欄位</label>
    <div style="display:flex;flex-wrap:wrap;gap:16px;padding:7px 10px;border:1px solid #e0e0e0;border-radius:6px;background:#fafafa;">
      <label style="font-weight:400;margin:0;cursor:pointer;"><input type="checkbox" id="cat-show-spec"> 料號規格</label>
      <label style="font-weight:400;margin:0;cursor:pointer;"><input type="checkbox" id="cat-show-label"> 料號標籤</label>
      <label style="font-weight:400;margin:0;cursor:pointer;"><input type="checkbox" id="cat-show-keeper"> 保管者</label>
      <label style="font-weight:400;margin:0;cursor:pointer;"><input type="checkbox" id="cat-show-vendor"> 廠商</label>
    </div>
    <small style="font-size:11px;color:#aaa;">保管者 / 廠商 會在新增/編輯庫存時提供選擇，並顯示於庫存列表「客戶」欄</small>
  </div>

  <!-- 允許儲位：搜尋 + 標籤多選（需求3）-->
  <div class="form-group" style="margin-top:4px;">
    <label style="display:flex;align-items:center;justify-content:space-between;">
      <span><i class="fa fa-map-marker" style="color:var(--accent);"></i> 允許使用的儲位</span>
      <span style="font-size:11px;color:#aaa;font-weight:400;">不選 = 全部儲位皆可用</span>
    </label>
    <div style="position:relative;">
      <input type="text" id="cat-loc-search" class="form-control" placeholder="輸入儲位名稱 / 說明關鍵字模糊搜尋，可多次選取..." autocomplete="off">
      <div class="ac-box" id="cat-loc-ac" style="display:none;"></div>
    </div>
    <div id="cat-loc-chips" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:5px;min-height:22px;"></div>
    <div style="margin-top:4px;"><span id="cat-loc-count" style="font-size:11px;color:#888;"></span> <a href="#" id="cat-loc-clear" onclick="_catLocClearAll();return false;" style="font-size:11px;margin-left:8px;display:none;">清除全部</a></div>
  </div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" onclick="saveCat()" style="background:var(--accent);border-color:var(--accent);">儲存</button>
</div>
</div></div></div>

<!-- ══ Modal: 單位編輯 ══ -->
<div class="modal fade" id="unitModal" tabindex="-1">
<div class="modal-dialog" style="max-width:440px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">新增/編輯計量單位</h4></div>
<div class="modal-body">
  <input type="hidden" id="unit-id">
  <div class="row"><div class="col-xs-6"><div class="form-group"><label>單位名稱</label><input type="text" id="unit-name" class="form-control" placeholder="PCS、公升、KG..."></div></div>
  <div class="col-xs-3"><div class="form-group"><label>符號</label><input type="text" id="unit-sym" class="form-control" placeholder="pcs"></div></div>
  <div class="col-xs-3"><div class="form-group"><label>類型</label>
    <select id="unit-type" class="form-control"><option value="">—</option><option value="count">計數</option><option value="weight">重量</option><option value="volume">體積</option></select>
  </div></div></div>
  <div class="row"><div class="col-xs-6"><div class="form-group"><label>換算基準單位</label>
    <select id="unit-base" class="form-control"><option value="">（本身為基準）</option></select>
  </div></div><div class="col-xs-6"><div class="form-group"><label>換算係數</label><input type="number" id="unit-factor" class="form-control" placeholder="1個本單位=?個基準" step="0.000001"></div></div></div>
  <small class="text-muted">例：1加侖 ≈ 3.785412 公升 → 基準=公升，係數=3.785412</small>
  <div class="row" style="margin-top:8px;">
    <div class="col-xs-6"><div class="form-group"><label>小數點後幾位</label>
      <select id="unit-decimal" class="form-control">
        <option value="0">0（整數，如PCS）</option>
        <option value="1">1位</option>
        <option value="2">2位</option>
        <option value="3" selected>3位（預設）</option>
        <option value="4">4位</option>
        <option value="6">6位</option>
      </select>
      <small class="text-muted">輸入數量時最多允許幾位小數</small>
    </div></div>
    <div class="col-xs-6"><div class="form-group"><label>排序</label><input type="number" id="unit-sort" class="form-control" value="0"></div></div>
  </div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" onclick="saveUnit()" style="background:var(--accent);border-color:var(--accent);">儲存</button>
</div>
</div></div></div>

<!-- ══ Modal: 安全庫存 ══ -->
<div class="modal fade" id="safetyModal" tabindex="-1">
<div class="modal-dialog" style="max-width:400px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">設定安全庫存</h4></div>
<div class="modal-body">
  <div class="form-group" style="position:relative;">
    <label>料號 <span style="color:red;">*</span></label>
    <input type="text" id="safety-did" class="form-control" placeholder="搜尋料號..." autocomplete="off">
    <input type="hidden" id="safety-dsid">
    <div class="ac-box" id="ac-safety" style="display:none;"></div>
  </div>
  <div class="row">
    <div class="col-xs-7"><div class="form-group"><label>安全庫存數量</label><input type="number" id="safety-qty" class="form-control" value="0" min="0" step="0.001"></div></div>
    <div class="col-xs-5"><div class="form-group"><label>單位</label><select id="safety-unit" class="form-control"><option value="">主單位</option></select></div></div>
  </div>
  <div class="form-group"><label>備註</label><input type="text" id="safety-rem" class="form-control"></div>
  <div class="alert alert-info" style="font-size:12px;margin-top:8px;">低於此數量時庫存列表中會顯示警示。不同料號可設定不同值。</div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" onclick="saveSafety()" style="background:var(--accent);border-color:var(--accent);">儲存</button>
</div>
</div></div></div>

<!-- ══ Modal: 盤點建立 ══ -->
<div class="modal fade" id="countModal" tabindex="-1">
<div class="modal-dialog" style="max-width:420px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">建立盤點批次</h4></div>
<div class="modal-body">
  <div class="form-group"><label>盤點名稱 <span style="color:red;">*</span></label><input type="text" id="count-name" class="form-control" placeholder="例：2025年Q2盤點"></div>
  <div class="form-group"><label>盤點日期</label><input type="date" id="count-date" class="form-control"></div>
  <div class="form-group"><label>備註</label><textarea id="count-remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" onclick="createCountSession()" style="background:var(--accent);border-color:var(--accent);"><i class="fa fa-plus"></i> 建立</button>
</div>
</div></div></div>

<!-- ══ Modal: 品項可用單位管理 ══ -->
<div class="modal fade" id="itemUnitsModal" tabindex="-1">
<div class="modal-dialog" style="max-width:500px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">管理料號可用單位</h4></div>
<div class="modal-body">
  <div id="item-units-content"><div class="text-center text-muted">載入中...</div></div>
  <div style="margin-top:10px;"><button class="btn btn-sm btn-default" onclick="addItemUnit()"><i class="fa fa-plus"></i> 新增可用單位</button></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" onclick="saveItemUnits()" style="background:var(--accent);border-color:var(--accent);">儲存</button>
</div>
</div></div></div>

<!-- ══ Modal: 隨機盤點 ══ -->
<div class="modal fade" id="randomCountModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-random"></i> 隨機抽盤設定</h4></div>
<div class="modal-body"><input type="hidden" id="random-sid"><div id="random-count-panel"></div></div>
<div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>
</div></div></div>

<!-- ══ Modal: 異動記錄刪除確認 ══ -->
<div class="modal fade" id="txnDeleteModal" tabindex="-1">
<div class="modal-dialog" style="max-width:420px;"><div class="modal-content">
<div class="modal-header" style="background:#E74C3C;"><button class="close" data-dismiss="modal"><span style="color:#fff;">&times;</span></button><h4 class="modal-title" style="color:#fff;"><i class="fa fa-trash"></i> 刪除異動記錄</h4></div>
<div class="modal-body">
  <input type="hidden" id="del-txn-id">
  <input type="hidden" id="del-txn-qty">
  <input type="hidden" id="del-txn-type">
  <input type="hidden" id="del-txn-item-id">
  <div id="del-txn-info" style="background:#fde8e8;border-radius:8px;padding:10px;margin-bottom:12px;font-size:13px;"></div>
  <div class="alert alert-warning" style="font-size:12px;"><i class="fa fa-exclamation-triangle"></i> 刪除此異動記錄將同步還原對應的庫存數量。此操作不可復原。</div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-danger" onclick="confirmDeleteTxn()"><i class="fa fa-trash"></i> 確認刪除並還原庫存</button>
</div>
</div></div></div>


<!-- ══ Modal: 合併庫存 ══ -->
<div class="modal fade" id="mergeModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header" style="background:var(--primary);"><button class="close" data-dismiss="modal"><span style="color:#fff;">&times;</span></button><h4 class="modal-title" style="color:#fff;"><i class="fa fa-compress"></i> 合併庫存</h4></div>
<div class="modal-body">
  <div class="alert alert-info" style="font-size:12px;"><i class="fa fa-info-circle"></i> 選擇要合併的料號，系統將把所有選取筆的庫存數量加總到「保留筆」，並將其他筆停用。異動記錄也會一併移到保留筆下。</div>
  <div class="form-group" style="position:relative;">
    <label>依料號搜尋</label>
    <input type="text" id="merge-search-did" class="form-control" placeholder="輸入料號搜尋..." autocomplete="off">
    <div class="ac-box" id="ac-merge-did" style="display:none;"></div>
  </div>
  <div id="merge-candidates" style="margin-top:10px;"></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn" style="background:var(--primary);color:#fff;" onclick="confirmMerge()"><i class="fa fa-compress"></i> 確認合併</button>
</div>
</div></div></div>


<!-- ══ Modal: 建立料號 ══ -->
<div class="modal fade" id="createDIdModal" tabindex="-1">
<div class="modal-dialog" style="max-width:480px;"><div class="modal-content">
<div class="modal-header" style="background:var(--primary);"><button class="close" data-dismiss="modal"><span style="color:#fff;">&times;</span></button><h4 class="modal-title" style="color:#fff;"><i class="fa fa-plus-circle"></i> 建立新料號</h4></div>
<div class="modal-body">
  <div class="alert alert-warning" style="font-size:12px;"><i class="fa fa-exclamation-triangle"></i> 成品、半成品等商品料號請由業務從訂單系統建立。此處建立耗材、物料等一般料號。</div>
  <div class="form-group"><label>料號 <span style="color:red;">*</span></label><input type="text" id="new-did-code" class="form-control" placeholder="例：MAT-2024001"></div>
  <div class="form-group"><label>規格說明</label><input type="text" id="new-did-spec" class="form-control" placeholder="規格/型號說明"></div>
  <div class="form-group"><label>對應客戶 <small class="text-muted">（耗材/物料也需對應客戶）</small></label>
    <div style="position:relative;">
      <input type="text" id="new-did-client-name" class="form-control" placeholder="輸入客戶名稱搜尋..." autocomplete="off">
      <input type="hidden" id="new-did-client-id">
      <div class="ac-box" id="ac-new-did-client" style="display:none;"></div>
    </div>
  </div>
  <div class="form-group"><label>工件種類</label>
    <input type="text" class="form-control" value="一般" readonly style="background:#f5f5f5;color:#888;">
    <input type="hidden" id="new-did-type" value="一般">
    <small class="text-muted">其他種類（齒輪/滾刀等）需由業務從訂單系統建立</small>
  </div>
  <div class="form-group"><label>備註</label><textarea id="new-did-remark" class="form-control" rows="2"></textarea></div>
  <div id="create-did-msg" style="display:none;"></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn" style="background:var(--accent);color:#fff;" onclick="confirmCreateDId()"><i class="fa fa-save"></i> 建立料號</button>
</div>
</div></div></div>

<!-- ══ Modal: 新增組合件 ══ -->
<div class="modal fade" id="groupModal" tabindex="-1">
<div class="modal-dialog modal-lg" style="max-width:900px;margin-top:4vh;"><div class="modal-content" style="max-height:90vh;display:flex;flex-direction:column;">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-cubes"></i> 新增組合件庫存</h4></div>
<div class="modal-body" style="overflow-y:auto;flex:1 1 auto;padding:16px;">

  <!-- 群組設定 -->
  <div class="fs" style="margin-bottom:12px;">
    <h6><i class="fa fa-tag"></i> 組合群組設定</h6>
    <div class="row">
      <div class="col-sm-4">
        <div class="form-group" style="position:relative;"><label>組合名稱 <span style="color:red;">*</span></label>
          <input type="text" id="grp-name" class="form-control" placeholder="例：B-1140528001 整組" autocomplete="off" oninput="grpNameAc()">
          <div class="ac-box" id="grp-name-ac" style="display:none;"></div>
        </div>
      </div>
      <div class="col-sm-4" style="position:relative;">
        <div class="form-group"><label>客戶名稱 <span style="color:red;">*</span> <small style="font-weight:400;color:#888;">（組合件所屬客戶）</small></label>
          <input type="text" id="grp-customer-name" class="form-control" placeholder="搜尋客戶..." autocomplete="off" oninput="grpCustomerAc()">
          <input type="hidden" id="grp-customer-id">
          <div class="ac-box" id="grp-customer-ac" style="display:none;"></div>
        </div>
      </div>
      <div class="col-sm-4" style="padding-left:0;">
        <div class="row">
          <div class="col-sm-6">
            <div class="form-group"><label>整組售價 <small id="grp-price-src-lbl" style="font-weight:400;color:#27ae60;display:none;">（訂單）</small><small id="grp-price-manual-lbl" style="font-weight:400;color:#888;">（手動）</small></label>
              <div class="input-group"><span class="input-group-addon">$</span>
              <input type="number" id="grp-unit-price" class="form-control" step="0.0001" placeholder="無訂單時手動輸入"></div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="form-group"><label>本次入庫 <span style="color:red;">*</span> <small style="color:#888;font-weight:400;">（組）</small></label>
              <input type="number" id="grp-sets" class="form-control" value="1" min="1" step="1" placeholder="幾組">
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="row">
      <div class="col-sm-12">
        <div class="form-group"><label>備註</label>
          <input type="text" id="grp-remark" class="form-control" placeholder="選填">
        </div>
      </div>
    </div>
  </div>

  <!-- 共用設定 -->
  <div class="fs" style="margin-bottom:12px;">
    <h6><i class="fa fa-copy"></i> 共用設定 <small style="font-weight:400;color:#888;">（套用至所有子料號，各料號可再個別調整）</small></h6>
    <div class="row">
      <div class="col-sm-3">
        <div class="form-group"><label>品項種類 <span style="color:red;">*</span></label>
          <select id="grp-cat" class="form-control" onchange="onGrpCatChange()"><option value="">— 選擇 —</option></select>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="form-group"><label>儲位 <span style="color:red;">*</span> <small style="font-weight:400;color:#888;">（共用）</small></label>
          <select id="grp-loc" class="form-control"><option value="">— 請先選種類 —</option></select>
          <small id="grp-loc-hint" style="color:#aaa;font-size:11px;"></small>
        </div>
      </div>
      <div class="col-sm-2">
        <div class="form-group"><label>入庫日期</label>
          <input type="date" id="grp-sd" class="form-control">
        </div>
      </div>
      <div class="col-sm-3">
        <div class="form-group"><label>製令號 (BOM) <small style="font-weight:400;color:#888;">（各自填）</small></label>
          <select id="grp-bom-shared-sel" class="form-control"><option value="">— 請先選定組合名稱 —</option></select>
        </div>
      </div>
    </div>
    <div class="row" style="margin-top:8px;">
      <div class="col-sm-6">
        <div class="form-group" style="margin-bottom:4px;">
          <label>綁定訂單 <small style="font-weight:400;color:#888;">（自動取得組合件售價）</small></label>
          <select id="grp-order-sel" class="form-control" onchange="_applyGrpOrderPrice(this.value, $(this).find(':selected').data('price'))">
            <option value="">— 請先選定組合名稱 —</option>
          </select>
        </div>
        <div id="grp-order-info" style="font-size:11px;color:#555;min-height:16px;padding:2px 0;"></div>
      </div>
    </div>
    <div style="text-align:right;">
      <button class="btn btn-xs btn-default" onclick="applyGrpShared()" style="font-size:12px;"><i class="fa fa-arrow-down"></i> 套用 BOM 到所有子料號</button>
    </div>
  </div>

  <!-- 子料號列表 -->
  <div class="fs">
    <h6><i class="fa fa-list"></i> 子料號列表 <small style="font-weight:400;color:#888;">（至少 2 個）— 填寫每組含此料號幾 PCS，實際入庫數量 = PCS × 組數</small></h6>
    <div id="grp-items-wrap"></div>
    <button class="btn btn-xs btn-default" onclick="addGrpItem()" style="margin-top:6px;"><i class="fa fa-plus"></i> 新增一筆料號</button>
  </div>

</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn" style="background:var(--warn);color:#fff;border-color:var(--warn);" onclick="confirmSaveGroup()"><i class="fa fa-save"></i> 建立組合件</button>
</div>
</div></div></div>

<!-- ══ Modal: 選取項合併為組合件 ══ -->
<div class="modal fade" id="mergeToGroupModal" tabindex="-1">
<div class="modal-dialog" style="max-width:520px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-compress"></i> 合併為組合件</h4></div>
<div class="modal-body">
  <div class="alert alert-info" style="font-size:12px;">將選取的現有料號關聯至新群組。請設定每組內各料號的構成數量(PCS)。</div>
  <div class="form-group"><label>組合名稱 <span style="color:red;">*</span></label><input type="text" id="m2g-name" class="form-control" placeholder="例如：B-1140528001 成套"></div>
  <div class="form-group" style="position:relative;">
    <label>客戶名稱 <span style="color:red;">*</span></label>
    <input type="text" id="m2g-customer-name" class="form-control" placeholder="搜尋客戶..." autocomplete="off" oninput="m2gCustomerAc()">
    <input type="hidden" id="m2g-customer-id">
    <div class="ac-box" id="m2g-customer-ac" style="display:none;"></div>
  </div>
  <div class="form-group"><label>整組售價</label><input type="number" id="m2g-price" class="form-control" step="0.0001" placeholder="選填"></div>
  <hr style="margin:10px 0;">
  <label style="color:var(--primary);"><i class="fa fa-list"></i> 子料號構成設定</label>
  <div id="m2g-items-list" style="max-height:260px; overflow-y:auto; border:1px solid #eee; border-radius:6px; padding:8px; background:#fcfcfc;"></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-info" onclick="confirmMergeExistingToGroup()">確認合併</button>
</div>
</div></div></div>

<!-- ══ Modal: 批次設定單位 ══ -->
<div class="modal fade" id="batchUnitModal" tabindex="-1">
<div class="modal-dialog" style="max-width:400px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-balance-scale"></i> 批次設定單位</h4></div>
<div class="modal-body">
  <div class="alert alert-info" style="font-size:12px;">將所選取的 <strong id="bu-count">0</strong> 筆品項全部更新為以下單位：</div>
  <div class="form-group"><label>選擇計量單位 <span style="color:red;">*</span></label>
    <select id="bu-unit-id" class="form-control"></select>
  </div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-primary" style="background:var(--accent); border-color:var(--accent);" onclick="confirmBatchUnitUpdate()">確認更新</button>
</div>
</div></div></div>

<!-- ══ Modal: 強制刪除庫存（A權限）══ -->
<div class="modal fade" id="purgeModal" tabindex="-1">
<div class="modal-dialog" style="max-width:420px;"><div class="modal-content">
<div class="modal-header" style="background:#c0392b;"><button class="close" data-dismiss="modal"><span style="color:#fff;">&times;</span></button><h4 class="modal-title" style="color:#fff;"><i class="fa fa-exclamation-triangle"></i> 強制刪除庫存記錄</h4></div>
<div class="modal-body">
  <input type="hidden" id="purge-item-id">
  <div id="purge-item-info" style="background:#fde8e8;border-radius:8px;padding:10px;margin-bottom:12px;font-size:13px;"></div>
  <div class="alert alert-danger" style="font-size:12px;"><i class="fa fa-exclamation-triangle"></i> <strong>此操作不可復原！</strong>將永久刪除此品項的所有庫存記錄、異動歷程、盤點明細。</div>
  <div class="form-group"><label style="font-weight:700;color:#c0392b;">請輸入 <code>Y</code> 確認刪除</label>
    <input type="text" id="purge-confirm-text" class="form-control" placeholder="輸入 Y" maxlength="1" style="border-color:#c0392b;font-size:18px;text-align:center;letter-spacing:6px;">
  </div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" data-dismiss="modal">取消</button>
  <button class="btn btn-danger" onclick="confirmPurge()"><i class="fa fa-trash"></i> 確認永久刪除</button>
</div>
</div></div></div>

<!-- ══ Modal: 庫齡明細清單 ══ -->
<div class="modal fade" id="agingDetailModal" tabindex="-1">
<div class="modal-dialog modal-lg" style="width:90%;max-width:1100px;"><div class="modal-content">
<div class="modal-header" style="background:var(--purple,#9B59B6);"><button class="close" data-dismiss="modal"><span style="color:#fff;">&times;</span></button><h4 class="modal-title" style="color:#fff;"><i class="fa fa-clock-o"></i> 庫齡明細清單（個別品項停滯日數）</h4></div>
<div class="modal-body">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
    <div style="display:flex;align-items:center;gap:8px;">
      <label style="margin:0;font-size:12px;font-weight:700;">庫齡區間</label>
      <select id="aging-group-filter" class="form-control input-sm" style="width:130px;display:inline-block;" onchange="G_agingGroup=this.value;_loadAgingDetail();">
        <option value="">全部</option>
        <option value="30天內">30天內</option>
        <option value="31~90天">31~90天</option>
        <option value="91~180天">91~180天</option>
        <option value="180天以上">180天以上</option>
        <option value="未知">未知</option>
      </select>
      <span id="aging-detail-summary" style="font-size:12px;color:#888;"></span>
    </div>
    <div style="display:flex;align-items:center;gap:6px;">
      <select id="aging-page-size" class="form-control input-sm" style="width:90px;" onchange="G_agingPS=parseInt(this.value);G_agingPage=1;_renderAgingDetail();">
        <option value="5">5筆/頁</option>
        <option value="10" selected>10筆/頁</option>
        <option value="20">20筆/頁</option>
        <option value="50">50筆/頁</option>
      </select>
      <span id="aging-pager"></span>
    </div>
  </div>
  <div id="aging-detail-body" style="max-height:60vh;overflow:auto;"></div>
</div>
<div class="modal-footer">
  <button class="btn btn-default" style="float:left;" onclick="exportAgingCSV()"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
  <button class="btn btn-info" style="float:left;" onclick="printAgingDetail()"><i class="fa fa-print"></i> 列印 / PDF</button>
  <button class="btn btn-default" data-dismiss="modal">關閉</button>
</div>
</div></div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<!-- 不使用 Chart.js，改用純 Canvas 2D API，徹底避開 custom.min.js 衝突 -->
<script src="../../resource/js/custom.min.js"></script>
<script>
// ── 全域 ────────────────────────────────────────
var G = { page:1, rows:[], sortCol:'Modified_At', sortDir:'desc', allLocs:[], allCats:[], allUnits:[], allDepts:[], allAreas:[], currentItemDsid:null, currentItemUnits:[], countLoaded:false, reqLoaded:false, reportLoaded:false, currentCountSession:null, _todayOnly:false, _isAdminUser:false, _canCount:false, _canBatch:false, locPage:1, catPage:1, unitPage:1, safetyPage:1, managePageSize:8, locAreaFilter: 'all',
    req:{page:1,pageSize:20,status:'0',kw:'',currentReqId:null,issueItems:[],currentReq:null,issueBatches:{},issuePendingItems:[]},
    rpt:{view:'day',dateFrom:'',dateTo:'',page:1,pageSize:20,refDate:new Date()} };
var PERM = '<?php echo htmlspecialchars($PAGE_PERM ?? "R", ENT_QUOTES); ?>';
var CURRENT_USER_ID = <?php echo intval($CURRENT_USER_ID ?? 0); ?>;
var CURRENT_USER_NAME = '<?php echo htmlspecialchars($CURRENT_USER_CNAME ?? '', ENT_QUOTES); ?>';
var CURRENT_USER_DEPTS = <?php echo json_encode($CURRENT_USER_DEPTS ?? []); ?>;
function hasP(p){ return PERM==='A' || PERM.indexOf(p)>=0; }

var G_allOrdersForEdit = [];
var G_allOrdersForEditMap = {};

// 載入訂單列表 (供編輯表單使用)
function loadOrdersForEditForm(d_id, bom, qty, containerEl, selectedOrderId = null) {
    var $container = $(containerEl);
    $container.html('<div style="padding:10px;text-align:center;color:#999;font-size:11px;"><i class="fa fa-spinner fa-spin"></i> 載入訂單...</div>');

    ajx({action:'get_orders_for_edit', d_id:d_id, bom:bom}, function(r){
        if(!r.success){ $container.html('<div style="padding:10px;text-align:center;color:red;font-size:11px;">載入失敗: '+r.message+'</div>'); return; }
        
        G_allOrdersForEdit = r.orders || [];
        G_allOrdersForEditMap = {};
        G_allOrdersForEdit.forEach(function(o){ G_allOrdersForEditMap[o.Order_id] = o; });

        if(G_allOrdersForEdit.length === 0){ $container.html('<div style="padding:10px;text-align:center;color:#999;font-size:11px;">無相關訂單</div>'); return; }

        var tbl = '<table style="width:100%;border-collapse:collapse;"><tbody>';
        G_allOrdersForEdit.forEach(function(o){
            var ooo = o.Order_oo || o.Order_id;
            var isBound = (o.is_bound || (selectedOrderId && o.Order_id == selectedOrderId));
            var allocatedQty = parseFloat(o.my_allocated || 0);
            var remaining = parseFloat(o.Qty || 0) - (parseFloat(o.already_allocated || 0) - allocatedQty);
            
            var qtyDisplay = o.Qty ? 'x' + o.Qty : '';
            var dateDisplay = o.Delivery_date ? ' (' + o.Delivery_date.substring(5).replace('-', '/') + ')' : '';
            var allocatedDisplay = allocatedQty > 0 ? ' <span style="color:var(--danger);">(已配'+allocatedQty+')</span>' : '';
            var remainingDisplay = remaining > 0 ? ' <span style="color:var(--accent);">(剩'+remaining+')</span>' : '';

            tbl += '<tr style="border-top:1px solid #eee;">'
                + '<td style="width:30px;padding:5px;text-align:center;"><input type="radio" name="order-bind-radio" class="order-bind-cb" value="'+o.Order_id+'" data-ono="'+esc(o.Order_oo)+'" '+(isBound?'checked':'')+' onchange="updateOrderBinding(this)"></td>'
                + '<td style="padding:5px;font-size:12px;font-weight:600;color:var(--primary);white-space:nowrap;">'+esc(ooo)+qtyDisplay+dateDisplay+'</td>'
                + '<td style="padding:5px;font-size:11px;color:#555;white-space:nowrap;">'+esc(o.Client_name||'')+'</td>'
                + '<td style="padding:5px;font-size:11px;text-align:right;white-space:nowrap;">'+allocatedDisplay+remainingDisplay+'</td>'
                + '</tr>';
        });
        tbl += '</tbody></table>';
        $container.html(tbl);
    });
}

// 更新訂單綁定 (radio button 點擊時)
function updateOrderBinding(radioEl) {
    var orderId = $(radioEl).val();
    var $modal = $(radioEl).closest('.modal');
    if($modal.attr('id') === 'groupTxnModal') $('#grp-txn-order-ref').val(orderId);
    if($modal.attr('id') === 'groupEditModal') $('#grp-edit-order-ref').val(orderId);
}

// ── 初始化 ──────────────────────────────────────
$(function(){
    // ── 修正雙滾動條：讀取 right_col 實際計算樣式後修正 ──
    (function fixScroll(){
        var rc = document.querySelector('.right_col');
        if(!rc) return;
        var cs = window.getComputedStyle(rc);
        if(cs.overflowY === 'scroll' || cs.overflowY === 'auto'){
            rc.style.setProperty('overflow-y','unset','important');
            rc.style.setProperty('height','auto','important');
            rc.style.setProperty('min-height','0','important');
        }
        document.documentElement.style.setProperty('overflow-y','auto','important');
        document.documentElement.style.setProperty('height','auto','important');
        document.body.style.setProperty('overflow-y','auto','important');
        document.body.style.setProperty('height','auto','important');
    })();

    // 套用權限控制
    _applyPermissions();

    loadStats(); loadList(1); loadMasterData();
    setInterval(function(){ ajx({action:'keepalive'},function(){}); }, 300000);
    ajx({action:'check_permission'},function(r){ if(r&&r.is_admin){ G._isAdminUser=true; } });
    // 頁面載入即開始通知輪詢（不需切到領庫需求頁）
    requestDesktopNotifPerm();
    startNotifPolling();

    // 搜尋框：即時篩選（搭配防抖處理）、雙擊清除
    $('#f-search').on('input', debounce(function(){ loadList(1); }, 400));
    $('#f-search').on('dblclick',function(){ $(this).val(''); loadList(1); });

    // ── 列表勾選邏輯 ──
    window.toggleAllListChk = function(el){
        $('.list-item-chk').prop('checked', el.checked);
        updateListSelection();
    };
    window.updateListSelection = function(){
        var n = $('.list-item-chk:checked').length;
        $('#sel-cnt').text(n);
        if(G._canBatch){
            $('#btn-batch-group').toggle(n >= 2);
            $('#btn-batch-unit').toggle(n >= 1);
        }
        var total = $('.list-item-chk').length;
        $('#list-chk-all').prop('checked', n > 0 && n === total);
        $('#list-chk-all').prop('indeterminate', n > 0 && n < total);
    };

    window.openBatchUnitModal = function(){
        if(!G._canBatch){ toast('無此操作權限','error'); return; }
        var n = $('.list-item-chk:checked').length;
        $('#bu-count').text(n);
        // 填充單位下拉
        var h = '<option value="">— 選擇計量單位 —</option>';
        G.allUnits.forEach(function(u){ h += '<option value="'+u.unit_id+'">'+esc(u.unit_name)+(u.unit_symbol?' ('+esc(u.unit_symbol)+')':'')+'</option>'; });
        $('#bu-unit-id').html(h);
        $('#batchUnitModal').modal('show');
    };

    window.confirmBatchUnitUpdate = function(){
        var unitId = $('#bu-unit-id').val(); if(!unitId){ toast('請選擇單位','error'); return; }
        var itemIds = [];
        $('.list-item-chk:checked').each(function(){ itemIds.push($(this).data('id')); });
        ajx({
            action: 'batch_update_unit',
            item_ids: JSON.stringify(itemIds),
            unit_id: unitId
        }, function(r){
            if(!r.success){ toast(r.message,'error'); return; }
            $('#batchUnitModal').modal('hide');
            toast(r.message,'success');
            loadList(G.page);
        });
    };

    window.openMergeExistingGroupModal = function(){
        if(!G._canBatch){ toast('無此操作權限','error'); return; }
        var checked = $('.list-item-chk:checked');
        var h = '';
        checked.each(function(){
            var d = $(this).data();
            h += '<div style="display:flex; justify-content:space-between; align-items:center; padding:6px; border-bottom:1px solid #eee;">'
               + '<div style="flex:1;"><strong>' + esc(d.did) + '</strong><br><small class="text-muted">' + esc(d.client) + '</small></div>'
               + '<div style="width:100px;"><input type="number" class="form-control input-sm m2g-pps" data-id="'+d.id+'" value="1" min="1" step="1"></div>'
               + '<div style="margin-left:8px; font-size:11px; color:#888;">PCS/組</div>'
               + '</div>';
        });
        $('#m2g-items-list').html(h);
        $('#m2g-name,#m2g-price').val('');
        $('#m2g-customer-name').val('');
        $('#m2g-customer-id').val('');
        $('#m2g-customer-ac').hide();
        $('#mergeToGroupModal').modal('show');
    };

    window.confirmMergeExistingToGroup = function(){
        var name = $('#m2g-name').val().trim(); if(!name){ toast('請輸入組合名稱','error'); return; }
        var custId = ($('#m2g-customer-id').val()||'').trim();
        if(!custId){ toast('請選擇客戶名稱','error'); $('#m2g-customer-name').focus(); return; }
        var items = [];
        $('.m2g-pps').each(function(){ items.push({id:$(this).data('id'), pcs_per_set:$(this).val()}); });
        ajx({action:'merge_existing_to_group', group_name:name, group_unit_price:$('#m2g-price').val(), group_customer_id:custId, items_json:JSON.stringify(items)}, function(r){
            if(!r.success){ toast(r.message,'error'); return; }
            $('#mergeToGroupModal').modal('hide'); toast(r.message,'success'); loadList(G.page);
        });
    };

    // 品項單位變更時調整 item-qty 的 step
    $('#item-unit').on('change',function(){ applyUnitStep($(this).val(),'item-qty'); });

    $(document).on('click','.sortable',function(){
        var col=$(this).data('col');
        if(G.sortCol===col) G.sortDir=G.sortDir==='asc'?'desc':'asc';
        else{G.sortCol=col;G.sortDir='asc';}
        $('.sortable i').attr('class','fa fa-sort');
        $(this).find('i').attr('class','fa fa-sort-'+(G.sortDir==='asc'?'asc':'desc'));
        loadList(G.page);
    });

    // 料號自動完成（新增品項）
    // 儲位管理 模糊搜尋（代碼 / 說明），300ms 防抖
    var _locSearchT=null;
    $('#loc-search').on('input',function(){
        G.locSearch=$(this).val().trim();
        clearTimeout(_locSearchT);
        _locSearchT=setTimeout(function(){ loadLocs(1); },300);
    });
    // 允許儲位 模糊搜尋（需求3）：依儲位名稱 / 說明過濾，點選加入
    $('#cat-loc-search').on('input',function(){
        var t=$(this).val().trim().toLowerCase();
        if(!t){$('#cat-loc-ac').hide();return;}
        var matches=(G.allLocs||[]).filter(function(l){
            if(_catSelLocs[l.location_id])return false;
            var code=(l.location_code||'').toLowerCase();
            var name=(l.location_name||'').toLowerCase();
            return code.indexOf(t)>=0||name.indexOf(t)>=0;
        }).slice(0,20);
        if(!matches.length){$('#cat-loc-ac').html('<div class="ac-item" style="color:#aaa;">查無符合的儲位</div>').show();return;}
        var h=''; matches.forEach(function(l){
            var label=(l.area_name?'['+esc(l.area_name)+'] ':'')+'<strong>'+esc(l.location_code)+'</strong>'+(l.location_name?'<span class="sub"> '+esc(l.location_name)+'</span>':'');
            h+='<div class="ac-item" data-id="'+l.location_id+'">'+label+'</div>';
        });
        $('#cat-loc-ac').html(h).show();
    });
    $(document).on('click','#cat-loc-ac .ac-item',function(){
        var id=$(this).data('id'); if(id===undefined||id==='')return;
        var l=(G.allLocs||[]).find(function(x){return x.location_id==id;});
        if(l){ _catSelLocs[l.location_id]=l; _renderCatLocChips(); }
        $('#cat-loc-search').val('').focus(); $('#cat-loc-ac').hide();
    });
    // 保管者 模糊搜尋（依姓名 / 部門）
    $('#item-keeper').on('input',function(){
        var t=$(this).val().trim(); $('#item-keeper-id').val('');
        if(t.length<1){$('#ac-keeper').hide();return;}
        ajx({action:'search_keeper',term:t},function(r){
            if(!r.success||!r.data.length){$('#ac-keeper').html('<div class="ac-item" style="color:#aaa;">查無人員</div>').show();return;}
            var h=''; r.data.forEach(function(u){ h+='<div class="ac-item" data-id="'+u.id+'" data-name="'+esc(u.user_cname)+'"><strong>'+esc(u.user_cname)+'</strong>'+(u.dept_names?'<span class="sub"> — '+esc(u.dept_names)+'</span>':'')+'</div>'; });
            $('#ac-keeper').html(h).show();
        });
    });
    $(document).on('click','#ac-keeper .ac-item',function(){
        var id=$(this).data('id'); if(id===undefined||id===''){return;}
        $('#item-keeper').val($(this).data('name')); $('#item-keeper-id').val(id); $('#ac-keeper').hide();
    });
    // 廠商 模糊搜尋（依簡稱 / 全稱 / 編號）
    $('#item-vendor').on('input',function(){
        var t=$(this).val().trim(); $('#item-vendor-id').val('');
        if(t.length<1){$('#ac-vendor').hide();return;}
        ajx({action:'search_vendor',term:t},function(r){
            if(!r.success||!r.data.length){$('#ac-vendor').html('<div class="ac-item" style="color:#aaa;">查無廠商</div>').show();return;}
            var h=''; r.data.forEach(function(m){ h+='<div class="ac-item" data-id="'+esc(m.maker_id_no)+'" data-name="'+esc(m.maker_id)+'"><strong>'+esc(m.maker_id)+'</strong>'+(m.maker_id_all&&m.maker_id_all!==m.maker_id?'<span class="sub"> '+esc(m.maker_id_all)+'</span>':'')+'<span class="sub"> ['+esc(m.maker_id_no)+']</span></div>'; });
            $('#ac-vendor').html(h).show();
        });
    });
    $(document).on('click','#ac-vendor .ac-item',function(){
        var id=$(this).data('id'); if(id===undefined||id===''){return;}
        $('#item-vendor').val($(this).data('name')); $('#item-vendor-id').val(id); $('#ac-vendor').hide();
    });
    // 批次設定：保管者 / 廠商 模糊搜尋
    $('#batch-set-keeper').on('input',function(){
        var t=$(this).val().trim(); $('#batch-set-keeper-id').val('');
        if(t.length<1){$('#ac-batch-keeper').hide();return;}
        ajx({action:'search_keeper',term:t},function(r){
            if(!r.success||!r.data.length){$('#ac-batch-keeper').html('<div class="ac-item" style="color:#aaa;">查無人員</div>').show();return;}
            var h=''; r.data.forEach(function(u){ h+='<div class="ac-item" data-id="'+u.id+'" data-name="'+esc(u.user_cname)+'"><strong>'+esc(u.user_cname)+'</strong>'+(u.dept_names?'<span class="sub"> — '+esc(u.dept_names)+'</span>':'')+'</div>'; });
            $('#ac-batch-keeper').html(h).show();
        });
    });
    $(document).on('click','#ac-batch-keeper .ac-item',function(){
        var id=$(this).data('id'); if(id===undefined||id===''){return;}
        $('#batch-set-keeper').val($(this).data('name')); $('#batch-set-keeper-id').val(id); $('#ac-batch-keeper').hide();
    });
    $('#batch-set-vendor').on('input',function(){
        var t=$(this).val().trim(); $('#batch-set-vendor-id').val('');
        if(t.length<1){$('#ac-batch-vendor').hide();return;}
        ajx({action:'search_vendor',term:t},function(r){
            if(!r.success||!r.data.length){$('#ac-batch-vendor').html('<div class="ac-item" style="color:#aaa;">查無廠商</div>').show();return;}
            var h=''; r.data.forEach(function(m){ h+='<div class="ac-item" data-id="'+esc(m.maker_id_no)+'" data-name="'+esc(m.maker_id)+'"><strong>'+esc(m.maker_id)+'</strong>'+(m.maker_id_all&&m.maker_id_all!==m.maker_id?'<span class="sub"> '+esc(m.maker_id_all)+'</span>':'')+'</div>'; });
            $('#ac-batch-vendor').html(h).show();
        });
    });
    $(document).on('click','#ac-batch-vendor .ac-item',function(){
        var id=$(this).data('id'); if(id===undefined||id===''){return;}
        $('#batch-set-vendor').val($(this).data('name')); $('#batch-set-vendor-id').val(id); $('#ac-batch-vendor').hide();
    });
    $('#item-did').on('input',function(){
        var t=$(this).val().trim(); if(t.length<1){$('#ac-did').hide();return;}
        ajx({action:'search_d_id',term:t},function(r){
            if(!r.success||!r.data.length){$('#ac-did').hide();return;}
            var h='';
            r.data.forEach(function(d){ h+='<div class="ac-item" data-did="'+esc(d.D_Setting_Id)+'" data-dsid="'+d.d_id+'" data-cname="'+esc(d.client_name||'')+'" data-cid="'+esc(d.Customer_Id||'')+'"><strong>'+esc(d.D_Setting_Id)+'</strong>'+(d.Spec_No?'<span class="sub"> '+esc(d.Spec_No)+'</span>':'')+(d.client_name?'<span class="sub"> — '+esc(d.client_name)+'</span>':'')+'</div>'; });
            $('#ac-did').html(h).show();
        });
    });
    $(document).on('click','#ac-did .ac-item',function(){
        var $t=$(this);
        $('#item-did').val($t.data('did'));
        $('#item-dsid').val($t.data('dsid'));
        $('#item-client').val($t.data('cname'));
        $('#item-cid').val($t.data('cid'));
        $('#ac-did').hide();
        G.currentItemDsid=$t.data('dsid');

        // 清除舊顯示
        $('#item-asm-display').empty();

        // 檢查組合件結構
        ajx({action:'get_assembly_structure', d_setting_id: $t.data('dsid')}, function(r){
            if(r.success && r.is_assembly){
                // 自動將計量單位設為「組」(unit_id=31)
                loadItemUnitsForSelect($t.data('dsid'), null, 31);
                
                // 修改數量欄位的標籤
                $('#item-qty').parent().find('label').html('入庫組數 <span style="color:red;">*</span>');

                if(r.children && r.children.length > 0){
                    var html = '<div style="margin-top:10px; background:#f0f7ff; border:1px solid #c8d8f8; border-radius:8px; padding:10px;">'
                             + '<div style="font-size:12px; font-weight:700; color:var(--primary); margin-bottom:6px;"><i class="fa fa-cubes"></i> 組合件模式：' + esc($t.data('did')) + '</div>'
                             + '<div style="font-size:11px; color:#555;">包含：' + r.children.map(c=>esc(c.child_part_no)+'×'+c.standard_qty).join(', ') + '</div>'
                             + '<div id="asm-input-preview" style="margin-top:8px; font-size:12px; font-weight:bold; color:var(--accent);"></div>'
                             + '</div>';
                    $('#item-asm-display').html(html);
                    
                    $('#item-qty').off('input.asm').on('input.asm', function(){
                        var s = parseInt($(this).val()||0);
                        $('#asm-input-preview').text('預計入庫：' + s + ' 組 (將自動建立 ' + r.children.length + ' 個子件庫存)');
                    }).trigger('input.asm');
                }
                // 載入單位，並強制選中 31
                loadItemUnitsForSelect($t.data('dsid'), null, 31);
            } else {
                loadItemUnitsForSelect($t.data('dsid'));
            }
        });

        // 觸發BOM自動搜尋
        triggerBomSearch($t.data('did'),'');
        $('#item-unit-manage-wrap').show();
    });

    // BOM/訂單選擇（新增/編輯 modal）已改為 select 下拉，不再需要 input 事件
    // 入庫 modal 仍保留 AC 文字搜尋
    $('#in-bom').on('input',function(){
        var t=$(this).val().trim(); var did=$('#in-item-did').val()||'';
        if(t.length<1){$('#ac-in-bom').hide();return;}
        ajx({action:'search_bom',term:t,d_id:did},function(r){
            if(!r.success||!r.data.length){$('#ac-in-bom').hide();return;}
            renderBomAc(r.data,'ac-in-bom','in-bom','in-bom-info','','in-order-disp','in-order-id','');
        });
    });
    $('#in-order-disp').on('input',function(){ orderAc($(this).val().trim(),'ac-in-order','in-order-disp','in-order-id','',''); });

    // 安全庫存料號搜尋
    $('#safety-did').on('input',function(){
        var t=$(this).val().trim(); if(!t){$('#ac-safety').hide();return;}
        ajx({action:'search_d_id',term:t},function(r){
            if(!r.success||!r.data.length){$('#ac-safety').hide();return;}
            var h=''; r.data.forEach(function(d){ h+='<div class="ac-item" data-did="'+esc(d.D_Setting_Id)+'" data-dsid="'+d.d_id+'"><strong>'+esc(d.D_Setting_Id)+'</strong>'+(d.Spec_No?'<span class="sub"> '+esc(d.Spec_No)+'</span>':'')+'</div>'; });
            $('#ac-safety').html(h).show();
        });
    });
    $(document).on('click','#ac-safety .ac-item',function(){ $('#safety-did').val($(this).data('did')); $('#safety-dsid').val($(this).data('dsid')); $('#ac-safety').hide(); });

    // 關閉 AC
    $(document).on('click',function(e){
        var acs=['#item-did,#ac-did','#item-keeper,#ac-keeper','#item-vendor,#ac-vendor','#batch-set-keeper,#ac-batch-keeper','#batch-set-vendor,#ac-batch-vendor','#cat-loc-search,#cat-loc-ac','#item-bom,#ac-bom','#item-order-disp,#ac-order','#in-bom,#ac-in-bom','#in-order-disp,#ac-in-order','#safety-did,#ac-safety','#new-did-client-name,#ac-new-did-client'];
        acs.forEach(function(pair){ var ps=pair.split(','); if(!$(e.target).closest(ps[0]+','+ps[1]).length) $(ps[1]).hide(); });
    });

    // 今日日期
    var today=new Date().toISOString().split('T')[0];
    $('#in-date,#out-date,#move-date,#count-date').val(today);
    $('#item-sd').val(today);

    // ── 日期欄位：輸入年份4碼後自動跳月份 ──────────
    (function initDateAutoAdvance(){
        // 當欄位獲得焦點、失去焦點或點擊時強制重置計數，並輸出日誌
        $(document).on('focus blur click', 'input[type="date"]', function(){
            $(this).data('y-buf', '');
            console.log('%c[Date Debug] 緩衝區已重置 (Focus/Blur/Click)', 'color: #888');
        });

        $(document).on('keydown', 'input[type="date"]', function(e){
            var el = this;
            var id = el.id || el.name || 'unnamed';
            
            // 僅累計 0-9 數字鍵
            if (/^[0-9]$/.test(e.key)) {
                var buf = ($(el).data('y-buf') || '') + e.key;
                console.log('[Date Debug] 欄位: ' + id + ' | 輸入: ' + e.key + ' | 目前緩衝字串: "' + buf + '" (長度: ' + buf.length + ')');
                
                if (buf.length >= 4) {
                    // 達到 4 碼立即重置並執行跳轉
                    $(el).data('y-buf', '');
                    
                    setTimeout(function(){
                        // 嘗試發送物理 Tab 鍵行為。
                        // 注意：原生日期控制項的內部區段跳轉由瀏覽器控制，JS 無法 100% 強制聚焦特定區段。
                        // 若年份是第一段，大部分現代瀏覽器在輸入滿 4 碼後會自動跳轉。
                        var tabEvent = $.Event('keydown', { keyCode: 9, which: 9 });
                        $(el).trigger(tabEvent);
                    }, 10); 
                } else {
                    $(el).data('y-buf', buf);
                }
            } else if (e.key === 'Backspace') {
                var currentBuf = ($(el).data('y-buf') || '');
                $(el).data('y-buf', currentBuf.slice(0, -1));
            } else if (e.key === 'Tab') {
                 // 使用者手動按 Tab 則清空緩衝
                 $(el).data('y-buf', '');
            } else if (e.key && e.key !== 'Tab' && !e.key.startsWith('Arrow')) {
                $(el).data('y-buf', '');
            }
        });
    })();
});

// ── 主資料載入 ──────────────────────────────────
function loadMasterData(){
    // 先載入廠區（儲位 modal 需要）
    ajx({action:'get_areas'},function(r){
        if(!r.success)return;
        G.allAreas=r.data||[];
        _refreshAreaDropdowns();
    });
    ajx({action:'get_locations', category_id:0, page:0},function(r){
        if(!r.success)return;
        G.allLocs=r.data;
        // 篩選下拉（f-loc）：廠區顯示在前
        var h='<option value="all">全部儲位</option>';
        r.data.forEach(function(l){ h+='<option value="'+l.location_id+'">'+(l.area?'['+esc(l.area)+'] ':'')+esc(l.location_code)+'</option>'; });
        $('#f-loc').html(h);
        // 移位下拉（move-to-id）：廠區顯示在前
        var hm='<option value="">— 選擇目標儲位 —</option>';
        r.data.forEach(function(l){ hm+='<option value="'+l.location_id+'">'+(l.area?'['+esc(l.area)+'] ':'')+esc(l.location_code)+'</option>'; });
        $('#move-to-id').html(hm);
        // 新增品項的 item-loc：初始提示先選種類（不填入選項）
        $('#item-loc').html('<option value="">— 請先選擇品項種類 —</option>');
    });
    ajx({action:'get_categories'},function(r){
        if(!r.success)return;
        G.allCats=r.data;
        var h='<option value="all">全部種類</option>';
        r.data.forEach(function(c){ h+='<option value="'+c.category_id+'">'+esc(c.category_name)+'</option>'; });
        $('#f-cat').html(h);
        var h2='<option value="">— 選擇種類 —</option>';
        r.data.forEach(function(c){ h2+='<option value="'+c.category_id+'">'+esc(c.category_name)+'</option>'; });
        $('#item-cat').html(h2);
        var h3='<option value="">全部種類</option>';
        r.data.forEach(function(c){ h3+='<option value="'+c.category_id+'">'+esc(c.category_name)+'</option>'; });
        $('#cr-filter-cat,#edit-filter-cat').html(h3);
        var catCards='';
        r.data.forEach(function(c){
            var showDash=(c.show_in_dashboard===undefined||c.show_in_dashboard===null||parseInt(c.show_in_dashboard)===1);
            if(!showDash) return;
            catCards+='<div class="sc" style="border-left-color:'+esc(c.color||'#888')+';--sc-color:'+esc(c.color||'#888')+';min-width:90px;flex:0 0 auto;" onclick="filterCard(\'cat_'+c.category_id+'\',this)"><div class="sc-val" id="sv-cat-'+c.category_id+'">—</div><div class="sc-label">'+esc(c.category_name)+'</div></div>';
        });
        $('#cat-cards').html(catCards);
    });
    ajx({action:'get_units'},function(r){
        if(!r.success)return;
        G.allUnits=r.data;
        var h='<option value="">— 選擇 —</option>';
        r.data.forEach(function(u){ h+='<option value="'+u.unit_id+'">'+esc(u.unit_name)+(u.unit_symbol&&u.unit_symbol!==u.unit_name?' ('+esc(u.unit_symbol)+')':'')+'</option>'; });
        $('#item-unit,#in-unit,#out-unit,#safety-unit,#unit-base').html(h.replace('— 選擇 —','— 選擇單位 —'));
        renderUnitTable(r.data);
    });
    ajx({action:'get_clients_in_stock'},function(r){
        if(!r.success)return;
        var h='<option value="">全部客戶</option>';
        r.data.forEach(function(c){ h+='<option value="'+esc(c.client_id)+'">'+esc(c.client_name)+'</option>'; });
        $('#f-client').html(h);
    });
    ajx({action:'get_dept_users',dept_id:0},function(r){
        if(!r.success)return;
        G.allDepts=r.depts||[];
        var h='<option value="">— 選擇部門 —</option>';
        (r.depts||[]).forEach(function(d){ h+='<option value="'+d.id+'">'+esc(d.name)+'</option>'; });
        $('#out-dept').html(h);
    });
}
function _refreshAreaDropdowns(){
    var h='<option value="">— 選擇廠區 —</option>';
    G.allAreas.forEach(function(a){ h+='<option value="'+a.area_id+'">'+esc(a.area_name)+'</option>'; });
    $('#loc-area').html(h);
}

// ── 權限控制 ─────────────────────────────────────
function _applyPermissions(){
    var canC=hasP('C'), canR=hasP('R'), canU=hasP('U'), canD=hasP('D'), isA=hasP('A');
    // 如果完全沒有讀取權限（理論上 PHP 已擋，這裡雙保險）
    if(!canR && !isA){ $('body').html('<div style="text-align:center;margin-top:80px;"><h2>無讀取權限</h2><p>請聯絡管理員開通 stock 模組讀取權限（R）</p></div>'); return; }
    // 記錄 admin 狀態
    if(isA) G._isAdminUser=true;
    // 盤點：需要 A 或同時有 C+R+U+D
    G._canCount = isA || (canC&&canR&&canU&&canD);
    if(G._canCount){ $('#tab-btn-count').show(); }
    // 設定頁：所有有效使用者均可看到（R-only 可唯讀，無操作按鈕）
    $('#tab-btn-setting').show();
    // 設定頁新增/廠區按鈕：僅 C 或 A 可用
    if(!canC && !isA){
        $('#btn-add-loc,#btn-add-cat,#btn-add-unit,#btn-add-safety').hide();
        $('#btn-area-manage').hide();
    }
    // 庫存列表新增按鈕：僅 C 或 A 可用
    if(canC||isA){ $('#btn-add-stock').show(); $('#btn-batch-add').show(); $('#btn-add-group').show(); }
    // 多選批次功能（合併組合件/設定單位）：僅 U 或 A 可見
    G._canBatch = canU || isA;
    if(!G._canBatch){
        $('#th-list-chk').hide();
        $('#btn-batch-group,#btn-batch-unit').hide();
    }
}

// ── 統計 ────────────────────────────────────────
function loadStats(){
    ajx({action:'get_stats'},function(r){
        if(!r.success)return;
        var d=r.data;
        $('#sv-total').text(fmt(d.total));
        $('#sv-low').text(fmt(d.low));
        var c=parseFloat(d.cost||0);
        $('#sv-cost').text(c>=1000000?'$'+(c/1000000).toFixed(1)+'M':'$'+Math.round(c).toLocaleString());
        var sv=parseFloat(d.sale_value||0);
        $('#sv-sale').text(sv>=1000000?'$'+(sv/1000000).toFixed(1)+'M':'$'+Math.round(sv).toLocaleString());
        $('#sv-today').text(fmt(d.today));
        (d.categories||[]).forEach(function(cat){ $('#sv-cat-'+cat.category_id).text(fmt(cat.cnt||0)); });
    });
}

// ── 篩選卡片 ────────────────────────────────────
function filterCard(type, el){
    $('.sc').removeClass('active'); $(el).addClass('active');
    $('#f-cat').val('all'); $('#f-qty').val('all'); $('#f-stale').val('0');
    if(type==='low') $('#f-qty').val('low');
    else if(type==='today'){
        // 今日異動：直接搜尋今日有異動的品項 — 用 search 欄位無法做，改為篩選今日有異動的 stock_item_id
        // 實際上透過後端 today_only 參數過濾
        G._todayOnly=true;
    } else { G._todayOnly=false; }
    if(type.indexOf('cat_')===0) $('#f-cat').val(type.replace('cat_',''));
    if(type!=='today') G._todayOnly=false;
    loadList(1);
}
function resetFilters(){
    $('#f-search').val(''); $('#f-cat').val('all'); $('#f-loc').val('all');
    $('#f-qty').val('all'); $('#f-client').val(''); $('#f-stale').val('0');
    G._todayOnly=false;
    $('.sc').removeClass('active'); $('#scard-all').addClass('active');
    loadList(1);
}

// ── 列表 ────────────────────────────────────────
function loadList(page){
    G.page=page;
    $('#list-chk-all').prop('checked',false).prop('indeterminate',false);
    G.rows = []; // 清空快取
    $('#btn-batch-group,#btn-batch-unit').hide();
    $('#stock-tbody').html('<tr><td colspan="11"><div class="empty"><i class="fa fa-spinner fa-spin"></i></div></td></tr>');
    ajx({
        action:'get_stock_list', page:page, page_size:$('#f-ps').val(),
        search:($('#f-search').val()||'').trim(), category_id:$('#f-cat').val(),
        location_id:$('#f-loc').val(), qty_filter:$('#f-qty').val(),
        client_id:$('#f-client').val(), stale_years:$('#f-stale').val()||0,
        today_only:G._todayOnly?1:0,
        sort_col:G.sortCol, sort_dir:G.sortDir
    }, function(r){
        if(!r.success){ $('#stock-tbody').html('<tr><td colspan="11" class="text-center text-danger">'+esc(r.message||'載入失敗')+'</td></tr>'); return; }
        G.rows = r.data; // 儲存本頁資料快取
        renderTable(r.data, page, parseInt($('#f-ps').val())||30);
        renderPager(r.total, r.page, r.page_size, r.total_pages);
        loadStats();
        // 更新篩選下拉只顯示當前結果有的資料
        _updateFilterDropdowns(r);
    });
}
function _updateFilterDropdowns(r){
    // 注意：不動態縮減 f-loc / f-client 的選項，
    // 因為篩選後的結果集若不含當前選項就會使選取消失。
    // 完整的下拉選項已在 loadMasterData 初始載入，保持不變即可。
    // （如有需要可在 resetFilters 後重新載入）
}

// ── 點擊料號開啟圖資跳窗（與 master_data 料號分頁相同：開啟 bom_viewer.php 可拖移視窗）──
function openPartDrawing(pid){
    if(!pid) return;
    var w=screen.availWidth, h=screen.availHeight;
    var pw=Math.min(1400, Math.round(w*0.85));
    var ph=Math.min(900,  Math.round(h*0.88));
    var pl=Math.round((w-pw)/2);
    var pt=Math.round((h-ph)/2);
    window.open('../pm/bom_viewer.php?d_id='+encodeURIComponent(pid),
        'bom_dv_'+pid,
        'width='+pw+',height='+ph+',left='+pl+',top='+pt+',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no');
}
// ── 料號標籤顯示 helper（移植自 master_data 料號分頁）──
function trimFloat(v){ if(v===''||v===null||v===undefined)return''; var n=parseFloat(v); if(isNaN(n))return String(v); return String(parseFloat(n.toPrecision(12))); }
function _stripLabelHint(name){ return String(name||'').replace(/\s*\[.*?\]\s*/g,' ').trim(); }
// 將 labels_str（list_parts 同款 GROUP_CONCAT 結果）轉成標籤 chip HTML
function buildLabelsHtml(labelsStr){
    if(!labelsStr) return '';
    var labelsHtml='';
    var _lsegs=labelsStr.split('\n');
    var _calcBaseSubIds={};
    _lsegs.forEach(function(seg){ var p=seg.split('|'); if(p.length<17)return; if(p[14]==='1'){ if(p[15])_calcBaseSubIds[p[15]]=1; if(p[16])_calcBaseSubIds[p[16]]=1; } });
    var _tf2=function(v){ var n=parseFloat(v); return (!isNaN(n)&&v!=='')?String(trimFloat(n)):String(v); };
    _lsegs.forEach(function(seg){
        var parts=seg.split('|');
        if(parts.length<5)return;
        var lid=parts[0]||'';
        if(_calcBaseSubIds[lid])return;
        var lname=parts[1]||'';
        var hasDl=(parts[4]==='1');
        var lval=parts[3]||'';
        var drawD=parts[5]||'';
        var lathD=parts[6]||'';
        var subNames=parts[7]||'';
        var isRng=(parts[8]==='1');
        var vmin=parts[9]||'';
        var vmax=parts[10]||'';
        var hasTolF=(parts[11]==='1');
        var tolU=parts[12]||'';
        var tolL=parts[13]||'';
        var isCalcF=(parts[14]==='1');
        var calcVal=parts[17]||'';
        var calcVMin=parts[18]||'';
        var calcVMax=parts[19]||'';
        var isDimF=(parts[20]==='1');
        var isQtyDimF=(parts[21]==='1');
        var pfxF=parts[22]||'';
        var sfxF=parts[23]||'';
        var isHiddenF=(parts[24]==='1');
        var qtyF=parts[25]||'';
        var hasDlDepthF=(parts[27]==='1');
        var isTripleDimF=(parts[28]==='1');
        if(isHiddenF)return; // 庫存頁無標籤篩選，隱藏標籤直接略過
        var chip=esc(_stripLabelHint(lname));
        var chipStyle='';
        if(isCalcF){
            chipStyle='background:#f3e5f5;border-color:#ce93d8;color:#6a1b9a;';
            if(calcVMin!==''&&calcVMax!==''){ chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+esc(_tf2(calcVMin))+'~'+esc(_tf2(calcVMax))+'</em>'; }
            else if(calcVal!==''){ chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+esc(_tf2(calcVal))+'</em>'; }
            else { chip+='<em style="font-style:normal;opacity:.6;"> 待計算</em>'; }
        } else if(isTripleDimF&&(vmin||vmax)){
            var sm3=vmin?_tf2(vmin):'', lg3=vmax?_tf2(vmax):'', dp3=drawD?_tf2(drawD):'';
            chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+(sm3?pfxF+sm3:'')+(lg3?'x'+pfxF+lg3:'')+(dp3?'x'+dp3+sfxF:'')+'</em>';
        } else if(hasDlDepthF&&(drawD||lathD)){
            var dd2=drawD?_tf2(drawD):'', ld2=lathD?_tf2(lathD):'', dep2=vmin?_tf2(vmin):'';
            chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+(dd2?'圖'+dd2:'')+(ld2?' 車'+ld2+(dep2?'x'+dep2+sfxF:''):'')+'</em>';
        } else if(hasDl){
            var dd=drawD!==''?parseFloat(drawD)||drawD:'', ld=lathD!==''?parseFloat(lathD)||lathD:'';
            if(dd!=='') chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+dd+(ld!==''?' 車'+ld:'')+'</em>';
        } else if(isQtyDimF&&(vmin||vmax)){
            var qtyNumF=(qtyF&&parseFloat(qtyF)>1)?esc(_tf2(qtyF))+'-':'';
            chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+qtyNumF+esc(pfxF)+esc(_tf2(vmin))+'×'+esc(_tf2(vmax))+esc(sfxF)+'</em>';
        } else if(isDimF&&(vmin||vmax)){
            chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+esc(pfxF)+esc(_tf2(vmin))+'×'+esc(_tf2(vmax))+esc(sfxF)+'</em>';
        } else if(isRng&&(vmin||vmax)){
            chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+esc(_tf2(vmin))+'~'+esc(_tf2(vmax))+'</em>';
        } else if(hasTolF){
            if(lval!==''){
                var tolStr='';
                if(tolU!=='') tolStr+=(parseFloat(tolU)>=0?'+':'')+esc(_tf2(tolU));
                if(tolL!=='') tolStr+=(parseFloat(tolL)>=0?'+':'')+esc(_tf2(tolL));
                chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+esc(_tf2(lval))+tolStr+'</em>';
            }
        } else if(lval){
            chip+='<em style="font-style:normal;font-weight:normal;opacity:.85;"> '+esc(lval)+'</em>';
        }
        if(!isCalcF&&subNames){
            subNames.split('~').forEach(function(subSeg){
                var sp=subSeg.split('§');
                var snameRaw=_stripLabelHint(sp[0]||'');
                var sIsEnumSp=(sp[14]==='1');
                var sHideNameSp=(sp[15]==='1');
                var sIsCountersinkSp=(sp[16]==='1');
                var sHasDlDepthSp=(sp[17]==='1');
                var sDrawDimSp=sp[18]||'';
                var sLatheDimSp=sp[19]||'';
                var sIsTripleDimSp=(sp[20]==='1');
                var sIsQtyTripleDimSp=(sp[21]==='1');
                if(!snameRaw&&!sIsEnumSp)return;
                var sname=sHideNameSp?'':snameRaw;
                var sIsRng=(sp[4]==='1');
                var sHasTol=(sp[5]==='1');
                var sIsDimSp=(sp[8]==='1');
                var sIsQtyDimSp=(sp[9]==='1');
                var sPfxSp=sp[10]||'';
                var sSfxSp=sp[11]||'';
                var sQtySp=sp[12]||'';
                var sIsImperialDimSp=(sp[13]==='1');
                var siv=sp[1]||'', svn=sp[2]||'', svx=sp[3]||'';
                var sivRaw=siv;
                var stU=sp[6]||'', stL=sp[7]||'';
                siv=_tf2(siv); svn=_tf2(svn); svx=_tf2(svx);
                var sDisp;
                if(sIsTripleDimSp&&(svn||svx)){
                    var ssm=svn?_tf2(svn):'', slg=svx?_tf2(svx):'', sdp3=sDrawDimSp?_tf2(sDrawDimSp):'';
                    sDisp=(ssm?sPfxSp+ssm:'')+(slg?'x'+sPfxSp+slg:'')+(sdp3?'x'+sdp3+sSfxSp:'');
                } else if(sIsQtyTripleDimSp&&(svn||svx||sDrawDimSp)){
                    var q3a=svn?_tf2(svn):'', q3b=svx?_tf2(svx):'', q3c=sDrawDimSp?_tf2(sDrawDimSp):'';
                    var q3n=(sQtySp&&parseFloat(sQtySp)>1)?_tf2(sQtySp)+'-':'';
                    sDisp=q3n+(q3a?sPfxSp+q3a:'')+(q3b?'×'+sPfxSp+q3b:'')+(q3c?'×'+q3c+sSfxSp:'');
                } else if(sHasDlDepthSp&&(sDrawDimSp||sLatheDimSp)){
                    var sdd2=sDrawDimSp?_tf2(sDrawDimSp):'', sld2=sLatheDimSp?_tf2(sLatheDimSp):'', sdep2=svn;
                    sDisp=(sdd2?'圖'+sdd2:'')+(sld2?' 車'+sld2+(sdep2?'x'+sdep2+sSfxSp:''):'');
                } else if(sIsCountersinkSp){
                    var csPart1sp=(svn||svx)?(sPfxSp+svn+(svx?'x'+svx+sSfxSp:'')):'';
                    var csPart2sp=(stU||stL)?(stU+(stL?'x'+_tf2(stL)+sSfxSp:'')):'';
                    var csDispSp=csPart1sp+(csPart2sp?'+'+csPart2sp:'');
                    if(sQtySp&&parseFloat(sQtySp)>1) csDispSp=_tf2(sQtySp)+'-'+csDispSp;
                    sDisp=csDispSp;
                } else if(sIsEnumSp){
                    sDisp=sivRaw;
                } else if(sIsImperialDimSp&&sivRaw){
                    var siQtyNumSp=(sQtySp&&parseFloat(sQtySp)>1)?_tf2(sQtySp)+'-':'';
                    var siUncSp=stU?_tf2(stU):'';
                    var siDepthSp=svx?svx:'';
                    var siSpecSp=sivRaw+(siUncSp?'-'+siUncSp+'UNC':'');
                    sDisp=siQtyNumSp+siSpecSp+(siDepthSp?' x'+siDepthSp+sSfxSp:'');
                } else if(sIsQtyDimSp&&(svn||svx)){
                    var sQtyNumSp=(sQtySp&&parseFloat(sQtySp)>1)?_tf2(sQtySp)+'-':'';
                    sDisp=sQtyNumSp+sPfxSp+svn+'×'+svx+sSfxSp;
                } else if(sIsDimSp&&(svn||svx)){
                    sDisp=sPfxSp+svn+'×'+svx+sSfxSp;
                } else if(sIsRng){
                    sDisp=(!svn&&!svx)?'':(svn||'')+'~'+(svx||'');
                } else if(sHasTol&&siv!==''){
                    var sTolStr='';
                    if(stU!=='') sTolStr+=(parseFloat(stU)>=0?'+':'')+_tf2(stU);
                    if(stL!=='') sTolStr+=(parseFloat(stL)>=0?'+':'')+_tf2(stL);
                    sDisp=siv+sTolStr;
                } else {
                    sDisp=sivRaw;
                }
                var _dispCombined=sname?(esc(sname)+(sDisp?' '+esc(sDisp):'')):esc(sDisp||'');
                if(!_dispCombined)return;
                chip+='<em style="font-style:normal;opacity:.8;font-size:10px;"> | '+_dispCombined+'</em>';
            });
        }
        labelsHtml+='<span class="part-label-chip"'+(chipStyle?' style="'+chipStyle+'"':'')+'>'+chip+'</span>';
    });
    return labelsHtml;
}

// 同料號判定鍵：料號 + 客戶 + 料號備註 + 料號版次 全等才算同料號（組合件另以 group_id 區分）
function _samePartKey(r){
    return (r.d_id||'')+'|'+(r.client_name||'')+'|'+(r.part_remark||'')+'|'+(r.part_revision||'')+'|'+(r.group_id||0);
}
function renderTable(rows, page, ps){
    if(!rows||!rows.length){ $('#stock-tbody').html('<tr><td colspan="12"><div class="empty"><i class="fa fa-inbox"></i><div>尚無庫存資料</div></div></td></tr>'); return; }
    var start=(page-1)*ps+1, h='';

    // 先統計同料號的所有行（在本頁內）；組合件與非組合件分開統計（不混計）
    // 同料號判定：料號 + 客戶 + 料號備註 + 料號版次 全等才算同料號
    var didGroups={};
    rows.forEach(function(r){ var gk=_samePartKey(r); if(!didGroups[gk]) didGroups[gk]=[]; didGroups[gk].push(r); });

    // 統計同群組的料號（在本頁內）
    var pageGroupMap={};
    rows.forEach(function(r){ if(r.group_id){ if(!pageGroupMap[r.group_id]) pageGroupMap[r.group_id]=[]; pageGroupMap[r.group_id].push(r.d_id); } });

    rows.forEach(function(r,i){
        var qty=parseFloat(r.qty||0);
        var safeQty=r.safety_qty?parseFloat(r.safety_qty):null;
        var qc=qty===0?'qty-zero':(safeQty&&qty<safeQty?'qty-low':'qty-ok');
        var catColor=r.cat_color||'#888';
        var catBadge=r.category_name?'<span class="cbadge" style="background:'+catColor+'22;color:'+catColor+';">'+esc(r.category_name)+'</span>':'—';
        // 成本顯示：組合件加總子件成本，非組合件用BOM快照或手動值
        var costVal = null; var costSrc = ''; var hasBomCost = false;
        var grpMembers = r.group_members || [];
        if (r.group_id && grpMembers.length > 0) {
            var grpCostTotal = 0; var grpCostOk = true;
            grpMembers.forEach(function(m){
                var mc = parseFloat(m.bom_cost_snapshot||0) > 0 ? parseFloat(m.bom_cost_snapshot) : (m.unit_cost ? parseFloat(m.unit_cost) : null);
                var pps = parseInt(m.pcs_per_set||1);
                if (mc != null) { grpCostTotal += mc * pps; } else { grpCostOk = false; }
            });
            if (grpCostOk && grpCostTotal > 0) {
                costVal = grpCostTotal; hasBomCost = true;
                costSrc = '<span style="font-size:9px;color:#27ae60;display:block;line-height:1.2;">[BOM整組]</span>';
            } else if (!grpCostOk) {
                costSrc = '<span style="font-size:9px;color:#e67e22;display:block;line-height:1.2;">[部分無成本]</span>';
            }
        } else {
            hasBomCost = r.bom_cost_snapshot != null && parseFloat(r.bom_cost_snapshot||0) > 0;
            costVal = hasBomCost ? parseFloat(r.bom_cost_snapshot) : (r.unit_cost ? parseFloat(r.unit_cost) : null);
            costSrc = hasBomCost
                ? '<span style="font-size:9px;color:#27ae60;display:block;line-height:1.2;">[BOM]</span>'
                : (r.unit_cost ? '<span style="font-size:9px;color:#888;display:block;line-height:1.2;">[手動]</span>' : '');
        }
        var costStr = costVal != null ? '<span title="'+(hasBomCost?'BOM自動計算':'手動輸入')+'">$'+costVal.toLocaleString('zh-TW',{maximumFractionDigits:2})+'</span>'+costSrc : (r.bom_ref?'<span style="font-size:10px;color:#e67e22;">[請先建立成本資料]</span>':'—');

        // 售價顯示：有 order_ref 或 group_order_ref 就標示[訂單]，不管快照是否已更新
        var hasOrderSnap = r.order_price_snapshot != null && parseFloat(r.order_price_snapshot||0) > 0;
        var isOrderBound = hasOrderSnap || !!r.order_ref || !!r.group_order_ref;
        var priceVal = hasOrderSnap ? parseFloat(r.order_price_snapshot) : (r.calc_price ? parseFloat(r.calc_price) : null);
        var priceSrc = isOrderBound
            ? '<span style="font-size:9px;color:#2980b9;display:block;line-height:1.2;">[訂單]</span>'
            : (priceVal ? '<span style="font-size:9px;color:#888;display:block;line-height:1.2;">[手動]</span>' : '');
        var priceStr = priceVal != null ? '<span title="'+(isOrderBound?'訂單自動帶入':'手動輸入')+'">$'+priceVal.toLocaleString('zh-TW',{maximumFractionDigits:2})+'</span>'+priceSrc : '—';
        var dateStr=r.stock_date?(r.stock_date+'').substring(0,10):'—';
        var unitStr=r.unit_symbol||r.unit_name||'';
        // 製令/訂單
        var refParts=[];
        if(r.bom_ref) refParts.push('<span style="color:var(--accent);font-size:11px;white-space:nowrap;"><i class="fa fa-link"></i> '+esc(r.bom_ref)+'</span>');
        if(r.order_no) refParts.push('<span style="color:var(--info);font-size:11px;white-space:nowrap;"><i class="fa fa-shopping-cart"></i> '+esc(r.order_no)+'</span>');
        var refs=refParts.length?refParts.join('<br>'):'—';
        // 儲位：若同料號有多筆，顯示本筆 + 其他儲位摘要（組合件與非組合件分開）
        var locStr=r.location_code||r.storage_location||'—';
        var locArea=r.area_display_name;
        var group=didGroups[_samePartKey(r)]||[];
        var locDisp='<span style="font-size:12px;">'+(locArea?'['+esc(locArea)+'] ':'')+esc(locStr)+'</span>';
        if(group.length>1){
            // 顯示本筆儲位 x 數量，其他儲位以小字附加
            var otherLocs=group.filter(function(x){return x.stock_item_id!==r.stock_item_id;});
            locDisp='<div style="font-size:12px;font-weight:600;color:var(--primary);">'+(locArea?'['+esc(locArea)+'] ':'')+esc(locStr)+' <span style="color:var(--accent);">×'+qty+(unitStr?' '+unitStr:'')+'</span></div>';
            otherLocs.forEach(function(o){
                var oLoc=o.location_code||o.storage_location||'?';
                var oArea=o.area_display_name;
                locDisp+='<div style="font-size:11px;color:#aaa;">'+(oArea?'['+esc(oArea)+'] ':'')+esc(oLoc)+' ×'+parseFloat(o.qty||0)+(unitStr?' '+unitStr:'')+'</div>';
            });
        }
        var safeBadge=(safeQty&&qty<safeQty&&qty>0)?'<span class="safety-warning">低庫存</span>':'';

        // 群組標記
        var groupBadge='';
        var trClass='';
        if(r.group_id && r.group_name){
            var grpMembers = r.group_members || [];
            var grpCount = grpMembers.length || 1;
            // 計算組數：本料號 qty ÷ pcs_per_set
            var pps = parseInt(r.pcs_per_set||0);
            var setsStr = (pps>0) ? Math.round(qty/pps)+' 組 ('+pps+' PCS/組)' : '';
            // 組合構成 tooltip 文字
            var compTip = grpMembers.map(function(m){ return esc(m.d_id)+' ×'+(m.pcs_per_set||'?'); }).join(' + ');
            groupBadge='<br><span class="group-badge" title="'+compTip+'\n點擊高亮同組" onclick="highlightGroup('+r.group_id+')">'
                +'<i class="fa fa-cubes"></i> '+esc(r.group_name)+' ('+grpCount+'件)</span>';
            if(setsStr) groupBadge+='<br><span style="font-size:10px;color:#856404;">'+setsStr+'</span>';
            trClass=' class="group-row-mark" data-group-id="'+r.group_id+'"';
        }

        var remarkStr = r.remark1 ? '<div style="font-size:11px;color:#999;margin-top:2px;line-height:1.2;">'+esc(r.remark1)+'</div>' : '';

        // 料號：有圖資(BOM圖面/附件)才顯示可點擊跳窗連結，否則純文字（參照 master_data 料號分頁邏輯）
        var _hasImg=(parseInt(r.has_drawing)||parseInt(r.has_attach));
        var didCell=_hasImg
            ? '<strong class="part-drawing-link" onclick="openPartDrawing(\''+esc(r.d_id)+'\')" title="點擊開啟圖資">'+esc(r.d_id)+'</strong>'
            : '<strong>'+esc(r.d_id)+'</strong>';
        var specLine=(parseInt(r.show_spec)&&r.spec_no)?'<div style="font-size:11px;color:#666;margin-top:2px;">規格：'+esc(r.spec_no)+'</div>':'';
        var labelLine='';
        if(r.gear_spec_str){ labelLine='<div style="font-size:11px;color:#666;margin-top:2px;"><i class="fa fa-cog"></i> '+esc(r.gear_spec_str)+'</div>'; }
        else if(parseInt(r.show_label)&&r.labels_str){ var _lh=buildLabelsHtml(r.labels_str); if(_lh) labelLine='<div style="margin-top:3px;">'+_lh+'</div>'; }
        // 客戶欄：依設定附加 廠商 / 保管者
        var clientExtra='';
        if(parseInt(r.show_vendor)&&r.vendor_name) clientExtra+='<div style="font-size:11px;color:#888;padding-left:8px;">廠商：'+esc(r.vendor_name)+'</div>';
        if(parseInt(r.show_keeper)&&r.keeper_name) clientExtra+='<div style="font-size:11px;color:#888;padding-left:8px;">保管：'+esc(r.keeper_name)+'</div>';

        h+='<tr'+trClass+'>'
          +(G._canBatch?'<td style="text-align:center;"><input type="checkbox" class="list-item-chk" data-id="'+r.stock_item_id+'" data-did="'+esc(r.d_id)+'" data-client="'+esc(r.client_name||'')+'" onchange="updateListSelection()"></td>':'')
          +'<td>'+didCell+remarkStr+safeBadge+groupBadge+specLine+labelLine+'</td>'
          +'<td style="font-size:12px;">'+esc(r.client_name||'—')+clientExtra+'</td>'
          +'<td>'+catBadge+'</td>'
          +'<td>'+locDisp+'</td>'
          +'<td style="text-align:center;"><span class="'+qc+'">'+qty+(unitStr?' '+unitStr:'')+'</span></td>'
          +'<td style="text-align:right;">'+costStr+'</td>'
          +'<td style="text-align:right;">'+priceStr+'</td>'
          +'<td style="font-size:11px;line-height:1.7;">'+refs+'</td>'
          +'<td style="font-size:11px;color:#888;">'+dateStr+'</td>'
          +'<td style="text-align:center;white-space:nowrap;">'
          +(hasP('C')||hasP('A')?'<button class="ba ba-in" onclick="openIn('+r.stock_item_id+')" title="入庫"><i class="fa fa-arrow-down"></i></button>':'')
          +(hasP('C')||hasP('A')?'<button class="ba ba-out" onclick="openOut('+r.stock_item_id+')" title="出庫"><i class="fa fa-arrow-up"></i></button>':'')
          +(hasP('U')||hasP('A')?'<button class="ba ba-move" onclick="openMove('+r.stock_item_id+')" title="移位"><i class="fa fa-exchange"></i></button>':'')
          +(hasP('U')||hasP('A')?'<button class="ba ba-edit" onclick="openEdit('+r.stock_item_id+')" title="編輯"><i class="fa fa-pencil"></i></button>':'')
          +'<button class="ba ba-info" onclick="openDetail('+r.stock_item_id+')" title="歷程"><i class="fa fa-history"></i></button>'
          +(G._isAdminUser?'<button class="ba ba-del" onclick="openPurge('+r.stock_item_id+',\''+esc(r.d_id)+'\')" title="強制刪除（A權限）"><i class="fa fa-trash"></i></button>':'')
          +'</td></tr>';
    });
    $('#stock-tbody').html(h);
}

// 高亮同組料號
var _lastHighlightGroup=null;
function highlightGroup(gid){
    if(_lastHighlightGroup===gid){
        // 取消高亮
        $('tr[data-group-id]').removeClass('group-highlight');
        _lastHighlightGroup=null;
    } else {
        $('tr[data-group-id]').removeClass('group-highlight');
        $('tr[data-group-id="'+gid+'"]').addClass('group-highlight');
        _lastHighlightGroup=gid;
    }
}

function renderPager(total,page,ps,totalPages){
    if(total<=0){$('#pager-info').text('');$('#pager-btns').html('');return;}
    var s=(page-1)*ps+1, e=Math.min(page*ps,total);
    $('#pager-info').text('第'+s+'~'+e+'筆，共'+total+'筆');
    var h='';
    if(page>1) h+='<button onclick="loadList(1)" title="第一頁">«</button><button onclick="loadList('+(page-1)+')" title="上一頁">‹</button>';
    // 最多顯示前後各2頁，避免按鈕過多溢出
    var from=Math.max(1,page-2), to=Math.min(totalPages,page+2);
    // 若頁數很多，也最多顯示5個頁碼按鈕
    if(to-from>4){to=from+4;}
    if(from>1) h+='<button onclick="loadList(1)">1</button>'+(from>2?'<span style="padding:0 3px;color:#aaa;">…</span>':'');
    for(var p=from;p<=to;p++) h+='<button class="'+(p===page?'active':'')+'" onclick="loadList('+p+')">'+p+'</button>';
    if(to<totalPages) h+=(to<totalPages-1?'<span style="padding:0 3px;color:#aaa;">…</span>':'')+'<button onclick="loadList('+totalPages+')">'+totalPages+'</button>';
    if(page<totalPages) h+='<button onclick="loadList('+(page+1)+')" title="下一頁">›</button><button onclick="loadList('+totalPages+')" title="最後頁">»</button>';
    $('#pager-btns').html(h);
}

// ── 新增/編輯 Modal ─────────────────────────────
function openAddModal(){
    clearItemForm(); $('#item-id').val(''); $('#itemTitle').text('新增庫存品項');
    $('#bom-order-section').show(); $('#itemModal').modal('show');
}
function openEdit(id){
    clearItemForm();
    ajx({action:'get_stock_detail',stock_item_id:id},function(r){
        if(!r.success){toast(r.message,'error');return;}
        var d=r.data;
        // 如果是組合件（檢查 group_id 且有成員），改走組合件編輯 UI
        if(d.group_id && r.group_members && r.group_members.length > 0){
            // 從 group_members 取 group_order_ref 補到 d 上，供 openGroupEdit 初始化
            if(!d.group_order_ref && r.group_members[0] && r.group_members[0].group_order_ref){
                d.group_order_ref = r.group_members[0].group_order_ref;
            }
            openGroupEdit(d, r.group_members);
            return;
        }
        $('#item-id').val(d.stock_item_id); $('#item-did').val(d.d_id); $('#item-dsid').val(d.d_setting_id||'');
        $('#item-cat').val(d.item_type||''); onCatChange(d.location_id);
        $('#item-client').val(d.client_name||''); $('#item-cid').val(d.client_id||'');
        // 保管者 / 廠商（欄位是否顯示已由 onCatChange 依種類設定處理）
        $('#item-keeper').val(d.keeper_name||''); $('#item-keeper-id').val(d.keeper_id||'');
        $('#item-vendor').val(d.vendor_name||''); $('#item-vendor-id').val(d.vendor_id||'');
        $('#item-qty').val(d.qty||0); $('#item-unit').val(d.unit_id||'');
        $('#item-bom').val(d.bom_ref||'');
        $('#item-order-id').val(d.order_ref||''); $('#item-order-disp').val(d.order_no||'');
        // 載入 BOM/訂單下拉並選中已綁定的值
        if(d.d_id){
            _loadItemBomSelect(d.d_id, d.bom_ref||'');
            _loadItemOrderSelect(d.d_id, parseInt(d.order_ref||0));
        }
        // 成本欄：有BOM綁定時反灰
        if(d.bom_ref){
            $('#item-cost').val(d.bom_cost_snapshot||'');
            _setItemCostBinding(true, d.bom_cost_snapshot?'BOM快照：$'+parseFloat(d.bom_cost_snapshot).toLocaleString():'<span style="color:#e67e22;">尚無成本資料，請先建立BOM成本</span>');
        } else {
            $('#item-cost').val(d.unit_cost||'');
            _setItemCostBinding(false, null);
        }
        // 售價欄：有訂單綁定時反灰
        if(d.order_ref){
            $('#item-price').val(d.order_price_snapshot||'');
            _setItemPriceBinding(true, d.order_price_snapshot?'訂單快照：$'+parseFloat(d.order_price_snapshot).toLocaleString():null);
        } else {
            $('#item-price').val(d.unit_price||'');
            _setItemPriceBinding(false, null);
        }
        $('#item-sd').val(d.stock_date?(d.stock_date+'').substring(0,10):'');
        $('#item-mfg').val(d.mfg_date?(d.mfg_date+'').substring(0,10):'');
        $('#item-ey').val(d.expire_years||''); $('#item-pkg').val(d.package_box||'');
        $('#item-r1').val(d.remark1||'');
        G.currentItemDsid=d.d_setting_id;
        if(d.d_setting_id) loadItemUnitsForSelect(d.d_setting_id, null, d.unit_id);
        $('#item-unit-manage-wrap').toggle(!!d.d_setting_id);
        $('#itemTitle').text('編輯庫存：'+d.d_id);
        // BOM/訂單 區塊顯示已由 onCatChange 依種類設定處理
        $('#itemModal').modal('show');
    });
}
function clearItemForm(){
    $('#item-id,#item-dsid,#item-order-id').val('');
    $('#item-did,#item-client,#item-cid,#item-bom,#item-order-disp').val('');
    $('#item-cost,#item-price,#item-ey,#item-pkg,#item-r1').val('');
    $('#item-keeper,#item-keeper-id,#item-vendor,#item-vendor-id').val('');
    $('#ac-keeper,#ac-vendor').hide(); $('#item-keeper-wrap,#item-vendor-wrap').hide();
    $('#item-qty').val(0); $('#item-cat,#item-loc').val('');
    _fillItemUnitDefaultPCS();
    // 重置 BOM/訂單下拉
    $('#item-bom-sel').html('<option value="">— 選擇BOM（選定料號後自動載入）—</option>');
    $('#item-order-sel').html('<option value="">— 選擇訂單（選定料號後自動載入）—</option>');
    $('#item-bom-count,#item-order-count').text('');
    // 重置數量標籤
    $('#item-qty').parent().find('label').html('庫存數量 <span style="color:red;">*</span>');
    $('#item-asm-display').empty();
    $('#item-sd').val(new Date().toISOString().split('T')[0]); $('#item-mfg').val('');
    $('#bom-info,#order-info').text(''); $('#ac-did').hide();
    $('#item-unit-manage-wrap').hide(); G.currentItemDsid=null;
    _setItemCostBinding(false, null); _setItemPriceBinding(false, null);
}

// ════════ 批次新增庫存 ════════
var _batch={ catId:'', cat:null, locList:[], rows:[], parts:[] };
function _pcsUnitId(){ var p=''; (G.allUnits||[]).forEach(function(u){ if(u.unit_name==='PCS') p=u.unit_id; }); return p; }
function _batchUnitOptions(sel){ var h=''; (G.allUnits||[]).forEach(function(u){ h+='<option value="'+u.unit_id+'"'+(u.unit_id==sel?' selected':'')+'>'+esc(u.unit_name)+'</option>'; }); return h; }
function _batchLocOptions(sel){ var h='<option value="">— 儲位 —</option>'; _batch.locList.forEach(function(l){ h+='<option value="'+l.location_id+'"'+(l.location_id==sel?' selected':'')+'>'+esc(l.label)+'</option>'; }); return h; }
function openBatchAddModal(){
    _batch={ catId:'', cat:null, locList:[], rows:[], parts:[] };
    var ch='<option value="">— 選擇品項種類 —</option>';
    (G.allCats||[]).forEach(function(c){ ch+='<option value="'+c.category_id+'">'+esc(c.category_name)+'</option>'; });
    $('#batch-cat').html(ch).val('');
    _loadBatchFilterDicts();
    $('#batch-set-unit').html(_batchUnitOptions(_pcsUnitId()));
    $('#batch-set-loc').html('<option value="">— 儲位 —</option>');
    $('#batch-set-date').val(new Date().toISOString().split('T')[0]);
    $('#batch-set-qty').val('');
    $('#batch-set-kv,#batch-set-keeper-wrap,#batch-set-vendor-wrap').hide();
    $('#batch-set-keeper,#batch-set-keeper-id,#batch-set-vendor,#batch-set-vendor-id').val('');
    $('#batch-f-kw').val('');
    $('#batch-part-list').html('<div class="text-muted" style="padding:10px;">請設定篩選條件後按「查詢」</div>');
    $('#batch-found-cnt').text(''); $('#batch-chk-all').prop('checked',false); $('#batch-msg').text('');
    renderBatchTable();
    $('#batchAddModal').modal('show');
}
function _loadBatchFilterDicts(){
    if(G.wpTypes){ _fillBatchTypeSel(); return; }
    ajx({action:'get_wp_filter_dicts'},function(r){
        if(!r.success)return;
        G.wpTypes=r.types||[]; G.wpSubs=r.subs||[];
        _fillBatchTypeSel();
    });
}
function _fillBatchTypeSel(){
    var h='<option value="all">全部</option>';
    (G.wpTypes||[]).forEach(function(t){ h+='<option value="'+esc(t.type_code)+'">'+esc(t.type_name||t.type_code)+'</option>'; });
    $('#batch-f-type').html(h); onBatchTypeChange();
}
function onBatchTypeChange(){
    var tc=$('#batch-f-type').val();
    var h='<option value="0">全部</option>';
    (G.wpSubs||[]).forEach(function(s){ if(tc==='all'||s.type_code===tc) h+='<option value="'+s.sub_type_id+'">'+esc(s.sub_type_name)+'</option>'; });
    $('#batch-f-sub').html(h);
}
function onBatchCatChange(){
    var cid=$('#batch-cat').val();
    _batch.catId=cid;
    _batch.cat=(G.allCats||[]).find(function(c){return c.category_id==cid;})||null;
    var showK=_batch.cat&&parseInt(_batch.cat.show_keeper)===1;
    var showV=_batch.cat&&parseInt(_batch.cat.show_vendor)===1;
    $('#batch-set-kv').toggle(!!(showK||showV));
    $('#batch-set-keeper-wrap').toggle(!!showK);
    $('#batch-set-vendor-wrap').toggle(!!showV);
    _batch.locList=[];
    if(!cid){ $('#batch-set-loc').html('<option value="">— 儲位 —</option>'); renderBatchTable(); return; }
    $('#batch-set-loc').html('<option value="">— 載入中 —</option>');
    ajx({action:'get_locations',category_id:cid},function(r){
        if(r.success&&r.data){
            var grouped={};
            r.data.forEach(function(l){ var a=l.area_name||l.area||'（未分廠區）'; if(!grouped[a])grouped[a]=[]; grouped[a].push(l); });
            var keys=Object.keys(grouped).sort(function(a,b){ var ao=99999,bo=99999; (G.allAreas||[]).forEach(function(x){ if(x.area_name===a)ao=parseInt(x.sort_order)||0; if(x.area_name===b)bo=parseInt(x.sort_order)||0; }); return ao-bo; });
            keys.forEach(function(a){ grouped[a].forEach(function(l){ _batch.locList.push({location_id:l.location_id,label:'['+a+'] '+l.location_code+(l.location_name?' - '+l.location_name:'')}); }); });
        }
        $('#batch-set-loc').html(_batchLocOptions(''));
        renderBatchTable();
    });
}
function batchSearchParts(){
    var type=$('#batch-f-type').val()||'all';
    var sub=$('#batch-f-sub').val()||0;
    var kw=($('#batch-f-kw').val()||'').trim();
    $('#batch-part-list').html('<div class="text-muted" style="padding:10px;"><i class="fa fa-spinner fa-spin"></i> 查詢中...</div>');
    ajx({action:'batch_search_parts',filter_type:type,filter_sub:sub,keyword:kw},function(r){
        if(!r.success){ $('#batch-part-list').html('<div style="color:#c00;padding:10px;">'+esc(r.message||'查詢失敗')+'</div>'); return; }
        _batch.parts=r.data||[];
        $('#batch-chk-all').prop('checked',false);
        if(!_batch.parts.length){ $('#batch-part-list').html('<div class="text-muted" style="padding:10px;">查無料號</div>'); $('#batch-found-cnt').text(''); return; }
        var h='';
        _batch.parts.forEach(function(p,i){
            var info=[]; if(p.client_name)info.push('客:'+esc(p.client_name)); if(p.Spec_No)info.push('規:'+esc(p.Spec_No)); if(p.Revision)info.push('版:'+esc(p.Revision)); if(p.Remark)info.push('註:'+esc(p.Remark));
            h+='<label style="display:flex;gap:6px;align-items:flex-start;padding:3px 2px;border-bottom:1px solid #eee;cursor:pointer;font-weight:400;margin:0;">'
             +'<input type="checkbox" class="batch-part-cb" data-i="'+i+'" style="margin-top:3px;">'
             +'<span><strong>'+esc(p.D_Setting_Id)+'</strong>'+(info.length?'<span style="color:#999;font-size:11px;"> '+info.join(' / ')+'</span>':'')+'</span></label>';
        });
        $('#batch-part-list').html(h);
        $('#batch-found-cnt').text('共 '+_batch.parts.length+' 筆'+(r.capped?'（已達上限，請縮小篩選）':''));
    });
}
function batchToggleAll(c){ $('.batch-part-cb').prop('checked',c); }
function batchAddSelected(){
    if(!_batch.catId){ toast('請先選擇品項種類','error'); $('#batch-cat').focus(); return; }
    var added=0; var pcs=_pcsUnitId();
    var today=$('#batch-set-date').val()||new Date().toISOString().split('T')[0];
    $('.batch-part-cb:checked').each(function(){
        var p=_batch.parts[$(this).data('i')]; if(!p)return;
        var key=(p.D_Setting_Id||'')+'|'+(p.client_name||'')+'|'+(p.Remark||'')+'|'+(p.Revision||'');
        if(_batch.rows.some(function(r){return r._key===key;})) return;
        _batch.rows.push({_key:key, d_id:p.D_Setting_Id, d_setting_id:p.d_id, client_name:p.client_name||'', client_id:p.Customer_Id||'', qty:0, unit_id:pcs, location_id:'', stock_date:today, keeper_id:'',keeper_name:'',vendor_id:'',vendor_name:'', _chk:false});
        added++;
    });
    if(added===0){ toast('未選取新料號（或已在清單中）','info'); return; }
    $('.batch-part-cb').prop('checked',false); $('#batch-chk-all').prop('checked',false);
    renderBatchTable();
    toast('已加入 '+added+' 筆','success');
}
function renderBatchTable(){
    var rows=_batch.rows;
    $('#batch-sel-cnt').text(rows.length?('共 '+rows.length+' 筆'):'');
    if(!rows.length){ $('#batch-add-tbody').html('<tr><td colspan="6" class="text-center text-muted">尚未加入料號</td></tr>'); return; }
    var h='';
    rows.forEach(function(r,i){
        var info=r.client_name?'<div style="font-size:10px;color:#999;">客:'+esc(r.client_name)+'</div>':'';
        h+='<tr>'
         +'<td style="text-align:center;"><input type="checkbox" class="batch-row-cb" data-i="'+i+'"'+(r._chk?' checked':'')+' onchange="batchRowChk('+i+',this.checked)"></td>'
         +'<td><strong>'+esc(r.d_id)+'</strong>'+info+'</td>'
         +'<td><input type="number" class="form-control input-sm no-spin" style="height:26px;padding:2px 4px;" value="'+(r.qty||0)+'" min="0" step="0.001" onchange="batchRowEdit('+i+',\'qty\',this.value)"></td>'
         +'<td><select class="form-control input-sm" style="height:26px;padding:1px 2px;" onchange="batchRowEdit('+i+',\'unit_id\',this.value)">'+_batchUnitOptions(r.unit_id)+'</select></td>'
         +'<td><select class="form-control input-sm" style="height:26px;padding:1px 2px;" onchange="batchRowEdit('+i+',\'location_id\',this.value)">'+_batchLocOptions(r.location_id)+'</select></td>'
         +'<td style="text-align:center;"><i class="fa fa-times" style="cursor:pointer;color:#c00;" title="移除" onclick="removeBatchRow('+i+')"></i></td>'
         +'</tr>';
    });
    $('#batch-add-tbody').html(h);
    $('#batch-row-chk-all').prop('checked',false);
}
function batchRowEdit(i,field,val){ if(_batch.rows[i]) _batch.rows[i][field]=val; }
function batchRowChk(i,c){ if(_batch.rows[i]) _batch.rows[i]._chk=c; }
function removeBatchRow(i){ _batch.rows.splice(i,1); renderBatchTable(); }
function batchRowToggleAll(c){ _batch.rows.forEach(function(r){r._chk=c;}); $('.batch-row-cb').prop('checked',c); }
function _batchTargets(){ var t=_batch.rows.filter(function(r){return r._chk;}); return t.length?t:_batch.rows; }
function batchApply(field){
    var tg=_batchTargets(); if(!tg.length){ toast('待新增清單為空','info'); return; }
    if(field==='qty'){ var v=$('#batch-set-qty').val(); if(v==='') {toast('請先輸入數量','info');return;} tg.forEach(function(r){r.qty=v;}); }
    else if(field==='unit'){ var u=$('#batch-set-unit').val(); tg.forEach(function(r){r.unit_id=u;}); }
    else if(field==='loc'){ var l=$('#batch-set-loc').val(); if(!l){toast('請先選儲位','info');return;} tg.forEach(function(r){r.location_id=l;}); }
    else if(field==='date'){ var d=$('#batch-set-date').val(); tg.forEach(function(r){r.stock_date=d;}); }
    else if(field==='keeper'){ var kid=$('#batch-set-keeper-id').val(),kn=($('#batch-set-keeper').val()||'').trim(); tg.forEach(function(r){r.keeper_id=kid;r.keeper_name=kn;}); }
    else if(field==='vendor'){ var vid=($('#batch-set-vendor-id').val()||'').trim(),vn=($('#batch-set-vendor').val()||'').trim(); tg.forEach(function(r){r.vendor_id=vid;r.vendor_name=vn;}); }
    renderBatchTable();
    toast('已套用到 '+tg.length+' 筆','success');
}
function saveBatchStock(){
    if(!_batch.catId){ toast('請先選擇品項種類','error'); return; }
    if(!_batch.rows.length){ toast('待新增清單為空','error'); return; }
    for(var i=0;i<_batch.rows.length;i++){
        var r=_batch.rows[i];
        if(!r.location_id){ toast('第 '+(i+1)+' 列「'+r.d_id+'」尚未選儲位','error'); return; }
        if(!r.unit_id){ toast('第 '+(i+1)+' 列「'+r.d_id+'」尚未選單位','error'); return; }
        if(parseFloat(r.qty||0)<0){ toast('第 '+(i+1)+' 列數量不可為負','error'); return; }
    }
    $('#batch-save-btn').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中...');
    ajx({action:'batch_save_stock',category_id:_batch.catId,items:JSON.stringify(_batch.rows)},function(r){
        $('#batch-save-btn').prop('disabled',false).html('<i class="fa fa-save"></i> 批次儲存');
        if(!r.success){ toast(r.message||'儲存失敗','error'); return; }
        toast('批次新增完成，共 '+(r.created||0)+' 筆','success');
        $('#batchAddModal').modal('hide');
        loadList(G.page);
    });
}
// 需求5：依品項種類設定，控制庫存表單的 保管者 / 廠商 欄位顯示
function _applyCatItemFields(cat){
    var showK = cat && parseInt(cat.show_keeper)===1;
    var showV = cat && parseInt(cat.show_vendor)===1;
    $('#item-keeper-wrap').toggle(!!showK);
    $('#item-vendor-wrap').toggle(!!showV);
    if(!showK){ $('#item-keeper,#item-keeper-id').val(''); $('#ac-keeper').hide(); }
    if(!showV){ $('#item-vendor,#item-vendor-id').val(''); $('#ac-vendor').hide(); }
}
function onCatChange(setLocId){
    var cid=$('#item-cat').val();
    var cat=G.allCats.find(function(c){return c.category_id==cid;});
    if(!cat){ $('#item-loc').html('<option value="">— 請先選擇品項種類 —</option>'); $('#item-loc-hint').text(''); $('#bom-order-section,#bom-col,#order-col').show(); _applyCatItemFields(null); return; }
    // 需求7：依種類 BOM/訂單 關聯設定，控制下方成本/售價區塊顯示（不關聯則不顯示）
    var needBom=parseInt(cat.need_bom_bind)===1, needOrd=parseInt(cat.need_order_bind)===1;
    $('#bom-col').toggle(needBom); $('#order-col').toggle(needOrd);
    $('#bom-order-section').toggle(needBom||needOrd);
    // 需求5：依種類顯示設定，控制保管者 / 廠商 欄位顯示
    _applyCatItemFields(cat);
    // 設定預設保存年限
    var ey=cat.override_expire_years||cat.default_expire_years;
    if(ey&&!$('#item-ey').val()) $('#item-ey').val(ey);
    // 依種類過濾儲位，廠區顯示在前
    $('#item-loc').html('<option value="">— 載入儲位中... —</option>').prop('disabled',true);
    $('#item-loc-hint').text('');
    ajx({action:'get_locations',category_id:cid},function(r){
        $('#item-loc').prop('disabled',false);
        if(r.success && r.data.length){
            // 依廠區分組，格式：[廠區] 代碼
            var grouped={};
            r.data.forEach(function(l){
                var area=l.area_name||l.area||'（未分廠區）';
                if(!grouped[area]) grouped[area]=[];
                grouped[area].push(l);
            });
            var h='<option value="">— 選擇儲位 —</option>';
            var areaKeys=Object.keys(grouped).sort(function(a,b){
                // 依 G.allAreas 的 sort_order 排廠區
                var ao=99999,bo=99999;
                G.allAreas.forEach(function(x){ if(x.area_name===a) ao=parseInt(x.sort_order)||0; if(x.area_name===b) bo=parseInt(x.sort_order)||0; });
                return ao-bo;
            });
            areaKeys.forEach(function(area){
                grouped[area].forEach(function(l){
                    var label='['+area+'] '+l.location_code+(l.location_name?' - '+l.location_name:'');
                    h+='<option value="'+l.location_id+'">'+esc(label)+'</option>';
                });
            });
            $('#item-loc').html(h);
            if(setLocId) $('#item-loc').val(setLocId);
            if(r.filtered){
                $('#item-loc-hint').text('✓ 已依「'+cat.category_name+'」篩選，共 '+r.data.length+' 個可用儲位');
                $('#item-loc-hint').css('color','var(--accent)');
            } else {
                $('#item-loc-hint').text('此種類未設定儲位限制，顯示全部 '+r.data.length+' 個儲位');
                $('#item-loc-hint').css('color','#aaa');
            }
        } else {
            $('#item-loc').html('<option value="">— 此種類無可用儲位 —</option>');
            $('#item-loc-hint').text('請至主資料設定 → 品項種類 設定允許的儲位').css('color','var(--danger)');
        }
    });
}
function onStockDateChange(){
    // 移除自動帶入邏輯，依使用者要求製造日期預設為空
    return;
}
function saveItem(){
    var did=($('#item-did').val()||'').trim(); if(!did){toast('料號為必填','error');return;}
    if(!$('#item-cat').val()){toast('品項種類為必填','error');return;}
    if(!$('#item-loc').val()){toast('儲位為必填','error');return;}
    if(parseFloat($('#item-qty').val()||0)<0){toast('庫存數量不可為負數','error');return;}
    if(!$('#item-unit').val()){toast('主計量單位為必填','error');return;}
    // 製造日期未填則帶入庫日期
    var sd=$('#item-sd').val()||''; var mfg=$('#item-mfg').val()||'';
    if(sd && !mfg){ mfg=sd; $('#item-mfg').val(mfg); }
    var params={action:'save_stock_item',stock_item_id:$('#item-id').val()||0,
        d_id:did,d_setting_id:$('#item-dsid').val()||0,category_id:$('#item-cat').val()||0,
        location_id:$('#item-loc').val()||0,storage_location:'',
        qty:$('#item-qty').val()||0,unit_id:$('#item-unit').val()||0,
        bom_ref:($('#item-bom').val()||'').trim(),order_ref:$('#item-order-id').val()||0,
        unit_cost:$('#item-cost').val()||'',unit_price:$('#item-price').val()||'',
        mfg_date:mfg,stock_date:sd,
        expire_years:$('#item-ey').val()||'',package_box:($('#item-pkg').val()||'').trim(),
        client_name:($('#item-client').val()||'').trim(),client_id:($('#item-cid').val()||'').trim(),
        keeper_id:$('#item-keeper-id').val()||'',keeper_name:($('#item-keeper').val()||'').trim(),
        vendor_id:($('#item-vendor-id').val()||'').trim(),vendor_name:($('#item-vendor').val()||'').trim(),
        remark1:($('#item-r1').val()||'').trim(),remark2:''};
    ajx(params,function(r){
        if(!r.success){toast(r.message||'儲存失敗','error');return;}
        $('#itemModal').modal('hide'); toast(r.message||'儲存成功','success'); loadList(G.page);
    });
}

// ── 入庫 ────────────────────────────────────────
var inRow=null;
function openIn(id){
    var row = G.rows.find(function(x){ return x.stock_item_id == id; });
    if(!row) return;

    // 若為組合件，改走整組入庫
    if(row.group_id && row.pcs_per_set && parseInt(row.pcs_per_set)>0){
        openGroupTxn('in', row);
        return;
    }
    inRow=row;
    $('#in-item-id').val(row.stock_item_id).data('did', row.d_id||'');
    $('#in-item-did').remove(); $('<input type="hidden" id="in-item-did">').val(row.d_id).appendTo('#inModal');
    $('#in-item-info').html('<strong>料號：</strong>'+esc(row.d_id)+' &nbsp;|&nbsp; <strong>客戶：</strong>'+esc(row.client_name||'—')+' &nbsp;|&nbsp; <strong>目前庫存：</strong><strong style="color:var(--primary);">'+row.qty+(row.unit_symbol?' '+row.unit_symbol:'')+'</strong>');
    $('#in-loc-readonly').val(row.location_code||row.storage_location||'（未設定儲位）');
    $('#in-qty').val(1); $('#in-unit').val('');
    // 重置 BOM/訂單下拉
    $('#in-bom').val(''); $('#in-bom-drop').hide(); $('#in-bom-info').html('');
    $('#in-order-disp').val(''); $('#in-order-id').val(''); $('#in-order-drop').hide();
    G_inBomList = []; G_inOrderList = [];
    $('#in-pkg,#in-remark').val('');
    $('#in-qty-conv').val(''); $('#in-conv-hint').hide();
    loadItemUnitsForTxn(row.d_setting_id, 'in-unit');
    var today=new Date().toISOString().split('T')[0]; $('#in-date').val(today);
    $('#inModal').modal('show');
}
function triggerBomSearch(did, ctx){
    if(ctx === 'in'){
        // 入庫 modal：仍用 AC 浮動列表
        ajx({action:'search_bom',term:'',d_id:did},function(r){
            if(!r.success||!r.data.length) return;
            renderBomAc(r.data,'ac-in-bom','in-bom','in-bom-info','','in-order-disp','in-order-id','');
        });
    } else {
        // 新增/編輯 modal：載入 select 下拉
        _loadItemBomSelect(did, $('#item-bom').val()||'');
        _loadItemOrderSelect(did, parseInt($('#item-order-id').val()||0));
    }
}
// 載入 BOM 下拉選單（依料號）
function _loadItemBomSelect(did, selectedBom){
    $('#item-bom-count').text('載入中...');
    ajx({action:'search_bom',term:'',d_id:did},function(r){
        G._itemBomList = r.data || [];
        var h='<option value="">— 無BOM（手動成本）—</option>';
        (r.data||[]).forEach(function(b){
            var tc=parseFloat(b.total_cost||0), sq=parseFloat(b.sqty||0);
            var costTag = tc>0 ? '（估算$'+(sq>0?(tc/sq).toFixed(1):tc.toFixed(1))+'）' : '（無成本）';
            h+='<option value="'+esc(b.bom)+'" data-tc="'+tc+'" data-sq="'+sq+'" data-ono="'+esc(b.order_no||'')+'" data-oid="'+esc(b.order_id||'')+'" data-oprice="'+esc(b.order_price||'')+'">'+esc(b.bom)+costTag+'</option>';
        });
        $('#item-bom-sel').html(h);
        $('#item-bom-count').text(r.data&&r.data.length?'（'+r.data.length+'筆）':'（無BOM記錄）');
        if(selectedBom){ $('#item-bom-sel').val(selectedBom); if($('#item-bom-sel').val()) onItemBomSelect(selectedBom); }
    });
}
// 載入訂單下拉選單（依料號 d_id）
function _loadItemOrderSelect(did, selectedOrderId){
    $('#item-order-count').text('載入中...');
    ajx({action:'search_order',term:'',d_id:did},function(r){
        var h='<option value="">— 無訂單（手動售價）—</option>';
        (r.data||[]).forEach(function(o){
            var dateStr=(o.Order_date||'').substring(0,10);
            var label=esc(o.Order_oo)+(dateStr?' '+dateStr:'')+(o.unit_price?' $'+o.unit_price:'');
            h+='<option value="'+o.Order_id+'" data-ono="'+esc(o.Order_oo)+'" data-price="'+parseFloat(o.unit_price||0)+'" data-date="'+esc(dateStr)+'">'+label+'</option>';
        });
        $('#item-order-sel').html(h);
        $('#item-order-count').text(r.data&&r.data.length?'（'+r.data.length+'筆）':'（無相關訂單）');
        if(selectedOrderId){
            $('#item-order-sel').val(selectedOrderId);
            var opt=$('#item-order-sel option:selected');
            if(opt.val()){
                var price=parseFloat(opt.data('price')||0);
                $('#item-order-disp').val(opt.data('ono')||'');
                _setItemPriceBinding(true, price>0?'訂單售價：$'+price.toLocaleString()+'（儲存後由系統快照更新）':null);
                if(price>0) $('#order-info').html('訂單售價：$'+price.toLocaleString());
            }
        }
    });
}
// 選擇 BOM 下拉時觸發
function onItemBomSelect(bomVal){
    $('#item-bom').val(bomVal);
    if(!bomVal){ _setItemCostBinding(false, null); $('#bom-info').text(''); return; }
    var bomData=(G._itemBomList||[]).find(function(b){ return b.bom===bomVal; });
    if(bomData){
        var tc=parseFloat(bomData.total_cost||0), sq=parseFloat(bomData.sqty||0);
        var uc=sq>0?(tc/sq).toFixed(2):'0';
        var costTxt=tc>0?'BOM總成本:$'+tc.toLocaleString()+'（估算單件:$'+uc+'）':'<span style="color:#e67e22;">[此BOM目前尚無加工單價記錄]</span>';
        $('#bom-info').html(costTxt);
        _setItemCostBinding(true, tc>0?'BOM估算單件：$'+uc+'（儲存後由系統快照更新）':'<span style="color:#e67e22;">此BOM尚無加工單價記錄，請先建立成本資料</span>');
        $('#item-cost').val('');
        // 若 BOM 有對應訂單，自動選中訂單 select
        if(bomData.order_id){
            var opt=$('#item-order-sel option[value="'+bomData.order_id+'"]');
            if(opt.length){ $('#item-order-sel').val(bomData.order_id); onItemOrderSelect(bomData.order_id, opt[0]); }
        }
    }
}
// 選擇訂單下拉時觸發
function onItemOrderSelect(orderId, selOpt){
    $('#item-order-id').val(orderId);
    if(!orderId){ $('#item-order-disp').val(''); _setItemPriceBinding(false, null); $('#order-info').text(''); return; }
    var price=parseFloat($(selOpt).data('price')||0);
    $('#item-order-disp').val($(selOpt).data('ono')||'');
    _setItemPriceBinding(true, price>0?'訂單售價：$'+price.toLocaleString()+'（儲存後由系統快照更新）':null);
    $('#item-price').val('');
    if(price>0) $('#order-info').html('訂單售價：$'+price.toLocaleString()); else $('#order-info').text('');
}
// 控制成本欄綁定狀態
function _setItemCostBinding(bound, hintText){
    if(bound){
        $('#item-cost').prop('readonly',true).css({'background':'#f5f5f5','color':'#888'});
        $('#item-cost-src-lbl').show(); $('#item-cost-manual-lbl').hide();
        if(hintText){ $('#item-cost-hint').html(hintText).show(); } else { $('#item-cost-hint').hide(); }
    } else {
        $('#item-cost').prop('readonly',false).css({'background':'','color':''});
        $('#item-cost-src-lbl').hide(); $('#item-cost-manual-lbl').show();
        $('#item-cost-hint').hide();
    }
}
// 控制售價欄綁定狀態
function _setItemPriceBinding(bound, hintText){
    if(bound){
        $('#item-price').prop('readonly',true).css({'background':'#f5f5f5','color':'#888'});
        $('#item-price-src-lbl').show(); $('#item-price-manual-lbl').hide();
        if(hintText){ $('#item-price-hint').html(hintText).show(); } else { $('#item-price-hint').hide(); }
    } else {
        $('#item-price').prop('readonly',false).css({'background':'','color':''});
        $('#item-price-src-lbl').hide(); $('#item-price-manual-lbl').show();
        $('#item-price-hint').hide();
    }
}
function calcInConvert(){ calcConvert('in-qty','in-unit','in-qty-conv','in-conv-hint'); }
function calcOutConvert(){ calcConvert('out-qty','out-unit','out-qty-conv','out-conv-hint'); }
function calcConvert(qtyId,unitId,convId,hintId){
    var qty=parseFloat($('#'+qtyId).val()||0);
    var uid=parseInt($('#'+unitId).val()||0);
    if(!uid||!qty){ $('#'+convId).val(''); $('#'+hintId).hide(); return; }
    var unit=G.allUnits.find(function(u){return u.unit_id==uid;});
    if(!unit||!unit.convert_factor){ $('#'+convId).val(qty); $('#'+hintId).hide(); return; }
    var conv=qty*parseFloat(unit.convert_factor);
    var baseUnit=G.allUnits.find(function(u){return u.unit_id==unit.base_unit_id;});
    $('#'+convId).val(conv.toFixed(4));
    $('#'+hintId).html(qty+' '+esc(unit.unit_name)+' = '+conv.toFixed(4)+' '+(baseUnit?esc(baseUnit.unit_name):'')).show();
}
function confirmIn(){
    var id=$('#in-item-id').val(); if(!id){toast('錯誤','error');return;}
    var qtyInUnit=parseFloat($('#in-qty').val()||0); if(qtyInUnit<=0){toast('數量必須>0','error');return;}
    var uid=parseInt($('#in-unit').val()||0);
    var unit=uid?G.allUnits.find(function(u){return u.unit_id==uid;}):null;
    var convFactor=unit&&unit.convert_factor?parseFloat(unit.convert_factor):null;
    var chgQty=convFactor?qtyInUnit*convFactor:qtyInUnit;
    ajx({action:'quick_in',stock_item_id:id,change_qty:chgQty,txn_qty_in_unit:qtyInUnit,txn_unit_id:uid||0,convert_factor:convFactor||'',qty_in_unit:qtyInUnit,location_to_id:'',location_to:'',bom_ref:($('#in-bom').val()||'').trim(),order_ref:$('#in-order-id').val()||0,package_box:($('#in-pkg').val()||'').trim(),remark:($('#in-remark').val()||'').trim(),txn_date:$('#in-date').val()},function(r){
        if(!r.success){toast(r.message||'入庫失敗','error');return;}
        $('#inModal').modal('hide'); toast('入庫成功！庫存：'+r.qty_after,'success'); loadList(G.page);
    });
}

// ── 出庫 ────────────────────────────────────────
function openOut(id){
    var row = G.rows.find(function(x){ return x.stock_item_id == id; });
    if(!row) return;

    // 統一格式：一般件出庫也使用與組合件相同的 FIFO 批次出庫 UI
    openGroupTxn('out', row);
    return;
    
    // 以下舊程式碼已廢棄，功能整合進 openGroupTxn
    $('#out-item-id').val(row.stock_item_id);
    $('#out-item-info').html('<strong>料號：</strong>'+esc(row.d_id)+' &nbsp;|&nbsp; <strong>目前庫存：</strong><strong style="color:var(--primary);">'+row.qty+(row.unit_symbol?' '+row.unit_symbol:'')+'</strong> &nbsp;|&nbsp; <strong>儲位：</strong>'+esc(row.location_code||row.storage_location||'—'));
    $('#out-qty').val(1); $('#out-unit').val(''); $('#out-remark').val('');
    $('#out-qty-conv').val(''); $('#out-conv-hint').hide();
    loadItemUnitsForTxn(row.d_setting_id,'out-unit');
    var today=new Date().toISOString().split('T')[0]; $('#out-date').val(today);
    // 記憶上次部門/人員（session storage，重整後清除）
    var lastDept=sessionStorage.getItem('stock_last_dept')||'';
    var lastUser=sessionStorage.getItem('stock_last_user')||'';
    var lastUserName=sessionStorage.getItem('stock_last_user_name')||'';
    // 先重建部門下拉
    var dh='<option value="">— 選擇部門 —</option>';
    G.allDepts.forEach(function(d){ dh+='<option value="'+d.id+'"'+(d.id==lastDept?' selected':'')+'>'+esc(d.name)+'</option>'; });
    $('#out-dept').html(dh);
    // 載入對應人員
    if(lastDept){
        ajx({action:'get_dept_users',dept_id:lastDept},function(r){
            if(!r.success)return;
            var uh='<option value="">— 選擇人員 —</option>';
            (r.users||[]).forEach(function(u){ uh+='<option value="'+u.id+'"'+(u.id==lastUser?' selected':'')+'>'+esc(u.user_cname)+'</option>'; });
            $('#out-user').html(uh);
        });
    } else {
        $('#out-user').html('<option value="">— 先選部門 —</option>');
    }
    $('#outModal').modal('show');
}
function loadOutUsers(){
    var deptId=$('#out-dept').val();
    ajx({action:'get_dept_users',dept_id:deptId||0},function(r){
        if(!r.success)return;
        var h='<option value="">— 選擇人員 —</option>';
        (r.users||[]).forEach(function(u){ h+='<option value="'+u.id+'">'+esc(u.user_cname)+'</option>'; });
        $('#out-user').html(h);
    });
}
function confirmOut(){
    var id=$('#out-item-id').val();
    var qtyInUnit=parseFloat($('#out-qty').val()||0); if(qtyInUnit<=0){toast('數量必須>0','error');return;}
    var uid=parseInt($('#out-unit').val()||0);
    var unit=uid?G.allUnits.find(function(u){return u.unit_id==uid;}):null;
    var convFactor=unit&&unit.convert_factor?parseFloat(unit.convert_factor):null;
    var chgQty=convFactor?qtyInUnit*convFactor:qtyInUnit;
    var deptId=$('#out-dept').val()||0;
    var userId=$('#out-user').val()||0;
    var userName=$('#out-user option:selected').text();
    // 記憶部門/人員到 sessionStorage
    if(deptId){ sessionStorage.setItem('stock_last_dept',deptId); sessionStorage.setItem('stock_last_user',userId); sessionStorage.setItem('stock_last_user_name',userName); }
    ajx({action:'quick_out',stock_item_id:id,change_qty:chgQty,txn_qty_in_unit:qtyInUnit,txn_unit_id:uid||0,convert_factor:convFactor||'',out_dept_id:deptId,out_user_id:userId,remark:($('#out-remark').val()||'').trim(),txn_date:$('#out-date').val()},function(r){
        if(!r.success){toast(r.message||'出庫失敗','error');return;}
        $('#outModal').modal('hide'); toast('出庫成功！庫存：'+r.qty_after,'success'); loadList(G.page);
    });
}

// ── 移位 ────────────────────────────────────────
function openMove(id){
    var row = G.rows.find(function(x){ return x.stock_item_id == id; });
    if(!row) return;

    $('#move-item-id').val(row.stock_item_id);
    $('#move-item-info').html('<strong>料號：</strong>'+esc(row.d_id)+' &nbsp;|&nbsp; <strong>目前庫存：</strong>'+row.qty+' &nbsp;|&nbsp; <strong>儲位：</strong>'+esc(row.location_code||row.storage_location||'—'));
    $('#move-from').val(row.location_code||row.storage_location||'');
    $('#move-to-id').val(''); $('#move-to-str').val(''); $('#move-remark').val('');
    var today=new Date().toISOString().split('T')[0]; $('#move-date').val(today);
    $('#moveModal').modal('show');
}
function onMoveLocChange(){
    var sel=$('#move-to-id option:selected'); var txt=sel.val()?sel.text().replace(/\(.*\)/,'').trim():'';
    $('#move-to-str').val(txt);
}
function confirmMove(){
    var id=$('#move-item-id').val();
    var locId=$('#move-to-id').val()||''; var locStr=($('#move-to-str').val()||'').trim();
    if(!locId&&!locStr){toast('請選擇或輸入移入位置','error');return;}
    ajx({action:'quick_move',stock_item_id:id,location_to_id:locId,location_to:locStr,remark:($('#move-remark').val()||'').trim(),txn_date:$('#move-date').val()},function(r){
        if(!r.success){toast(r.message||'移位失敗','error');return;}
        $('#moveModal').modal('hide');
        if(r.merged){
            toast(r.message||'移位完成，已自動合併！','success');
        } else {
            toast('移位成功！新位置：'+r.location,'success');
        }
        loadList(G.page); loadStats();
    });
}

// ── 詳情 ────────────────────────────────────────
function openDetail(id){
    $('#detail-info,#detail-txns').html('<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#detailModal').modal('show');
    ajx({action:'get_stock_detail',stock_item_id:id},function(r){
        if(!r.success){$('#detail-info').html('<div class="text-danger">載入失敗</div>');return;}
        var d=r.data;
        var catColor=d.cat_color||'#888';
        var unitStr=(d.unit_symbol||d.unit_name)?(' '+esc(d.unit_symbol||d.unit_name)):'';

        // ── 左側：品項基本資訊 + 各批次庫存分佈 ──
        var infoHtml='<div class="fs" style="height:100%;overflow-y:auto;max-height:520px;">';
        infoHtml+='<h6><i class="fa fa-cube"></i> 品項資訊</h6>'
            +'<table class="table" style="font-size:12px;margin:0 0 8px 0;">'
            +'<tr><th width="38%">料號</th><td><strong>'+esc(d.d_id)+'</strong></td></tr>'
            +'<tr><th>種類</th><td>'+(d.category_name?'<span class="cbadge" style="background:'+catColor+'22;color:'+catColor+';">'+esc(d.category_name)+'</span>':'—')+'</td></tr>'
            +'<tr><th>客戶</th><td>'+esc(d.client_name||'—')+'</td></tr>'
            +'<tr><th>保存年限</th><td>'+(d.expire_years?d.expire_years+'年':'—')+'</td></tr>'
            +'<tr><th>備註</th><td>'+esc(d.remark1||'—')+'</td></tr>'
            +'</table>';

        // ── 如果是組合件，顯示同組所有料號 ──
        var members = r.group_members || [];
        if (members.length > 0) {
            var gmName = (members[0] && members[0].group_name) ? members[0].group_name : (d.group_name || '組合件');
            var gmPrice = (members[0] && members[0].group_unit_price) ? '$'+parseFloat(members[0].group_unit_price).toLocaleString('zh-TW',{maximumFractionDigits:2}) : '—';
            // 計算組數（用本筆 qty ÷ pcs_per_set）
            var thisMember = members.find(function(m){ return m.stock_item_id == r.current_id; });
            var setsCount = (thisMember && thisMember.pcs_per_set && thisMember.pcs_per_set > 0)
                ? Math.round(parseFloat(d.qty) / thisMember.pcs_per_set) : '—';
            infoHtml += '<div style="margin-bottom:10px;">'
                + '<div style="font-size:12px;font-weight:700;color:var(--warn);margin-bottom:5px;"><i class="fa fa-cubes"></i> 組合件：'+esc(gmName)+'</div>'
                + '<div style="font-size:11px;color:#888;margin-bottom:5px;">整組售價：'+gmPrice+'　目前庫存：'+setsCount+' 組</div>'
                + '<table class="table" style="font-size:11px;margin:0;"><thead><tr style="background:#fff8e1;"><th>料號</th><th>每組PCS</th><th>現有庫存</th><th>BOM</th></tr></thead><tbody>';
            members.forEach(function(m){
                var isThis = (m.stock_item_id == r.current_id);
                var pps = m.pcs_per_set || '?';
                var mQty = parseFloat(m.qty || 0);
                infoHtml += '<tr style="'+(isThis?'background:#fffbea;font-weight:700;':'')+'">'
                    + '<td>'+(isThis?'<i class="fa fa-arrow-right" style="color:var(--warn);"></i> ':'')+esc(m.d_id)+'</td>'
                    + '<td style="text-align:center;">'+pps+' PCS</td>'
                    + '<td style="text-align:center;color:var(--primary);">'+mQty+'</td>'
                    + '<td style="color:#888;">'+esc(m.bom_ref||'—')+'</td>'
                    + '</tr>';
            });
            infoHtml += '</tbody></table></div>';
        }

        // 所有儲位庫存
        var locs=r.other_locations||[];
        if(locs.length){
            infoHtml+='<div style="font-size:12px;font-weight:700;color:var(--primary);margin-bottom:5px;"><i class="fa fa-map-marker"></i> 各儲位庫存</div>';
            var totalQty=0;
            locs.forEach(function(l){
                var isThis=(l.stock_item_id==r.current_id);
                var locQ=parseFloat(l.qty||0); totalQty+=locQ;
                var uStr=l.unit_symbol||l.unit_name||'';
                var locRemark=l.remark1?'<div style="font-size:11px;color:#999;margin-top:1px;">'+esc(l.remark1)+'</div>':'';
                infoHtml+='<div style="padding:4px 8px;background:'+(isThis?'#f0fdf8':'#f8f9fc')+';border-radius:5px;margin-bottom:3px;border-left:3px solid '+(isThis?'var(--accent)':'#ddd')+';font-size:12px;">'
                    +'<div style="display:flex;justify-content:space-between;">'
                    +'<span>'+(isThis?'<i class="fa fa-arrow-right" style="color:var(--accent);margin-right:3px;"></i>':'')+esc(l.location_code||l.storage_location||'未指定')+'</span>'
                    +'<strong>'+locQ+(uStr?' '+uStr:'')+'</strong></div>'
                    +locRemark+'</div>';
            });
            infoHtml+='<div style="text-align:right;font-size:12px;font-weight:700;padding-top:4px;border-top:1px solid #eee;color:var(--primary);">料號總庫存：'+totalQty+(unitStr||'')+'</div>';
        }

        // ── FIFO 批次剩餘計算 ──
        // 取所有入庫（時間由舊到新），依序被出庫消耗
        var allTxns=r.transactions||[];
        // 入庫批次（時間由舊到新）
        var inTxns=allTxns.filter(function(t){return t.txn_type==='in'&&parseFloat(t.txn_qty||0)>0;}).slice().reverse();
        // 出庫總量（只算 out 類型）
        var totalOut=allTxns.reduce(function(s,t){return t.txn_type==='out'?s+Math.abs(parseFloat(t.txn_qty||0)):s;},0);
        // FIFO 計算剩餘
        var remaining=totalOut;
        var batches=[];
        // 品項層級的 BOM/訂單（事務層若未填寫，作為兜底）
        var itemBomFallback=d.bom_ref||'';
        var itemOrderFallback=d.order_no||'';
        inTxns.forEach(function(t){
            var inQty=parseFloat(t.txn_qty||0);
            var consumed=Math.min(remaining,inQty);
            remaining=Math.max(0,remaining-consumed);
            var leftQty=inQty-consumed;
            if(leftQty>0.0001){
                batches.push({
                    qty:leftQty,
                    txn_bom:t.txn_bom||itemBomFallback,          // 事務層 → 品項層兜底
                    txn_order_no:t.txn_order_no||itemOrderFallback, // 事務層 → 品項層兜底
                    txn_pkg:t.txn_pkg||'',
                    remark:t.remark||'',
                    txn_date:(t.txn_date||'').substring(0,10),
                    txn_unit_name:t.txn_unit_name||''
                });
            }
        });
        // 每筆批次單獨顯示（不合併），讓使用者看到每一筆入庫的剩餘量、備註、BOM、訂單
        if(batches.length){
            infoHtml+='<div style="margin-top:10px;">'
                +'<div style="font-size:12px;font-weight:700;color:var(--primary);margin-bottom:5px;"><i class="fa fa-list-ol"></i> 入庫批次剩餘（FIFO）</div>';
            batches.forEach(function(b){
                var uStr=b.txn_unit_name?(unitStr||' '+b.txn_unit_name):(unitStr||'');
                var qtyStr='<strong style="color:var(--primary);font-size:13px;">'+Math.round(b.qty*1000)/1000+uStr+'</strong>';
                var dateStr=b.txn_date?'<span style="color:#999;font-size:11px;margin-right:6px;">'+esc(b.txn_date)+'</span>':'';
                var tags='';
                if(b.txn_bom) tags+=' <span style="background:#d4f5ed;color:#0e7a5e;padding:1px 6px;border-radius:3px;font-size:11px;"><i class="fa fa-link"></i> '+esc(b.txn_bom)+'</span>';
                if(b.txn_order_no) tags+=' <span style="background:#e0eeff;color:#1a4fa0;padding:1px 6px;border-radius:3px;font-size:11px;"><i class="fa fa-shopping-cart"></i> '+esc(b.txn_order_no)+'</span>';
                var sub='';
                if(b.txn_pkg) sub+='<span style="color:#888;font-size:11px;"><i class="fa fa-archive"></i> 箱:'+esc(b.txn_pkg)+'</span> ';
                if(b.remark) sub+='<span style="color:#888;font-size:11px;"><i class="fa fa-comment-o"></i> '+esc(b.remark)+'</span>';
                infoHtml+='<div style="padding:5px 8px;background:#f8fffe;border-left:3px solid var(--accent);border-radius:0 5px 5px 0;margin-bottom:5px;">'
                    +'<div>'+dateStr+qtyStr+tags+'</div>'
                    +(sub?'<div style="margin-top:2px;">'+sub+'</div>':'')
                    +'</div>';
            });
            infoHtml+='</div>';
        } else if(inTxns.length){
            infoHtml+='<div style="font-size:12px;color:#aaa;margin-top:8px;padding:6px;background:#f8f9fc;border-radius:5px;">所有入庫批次已出庫完畢</div>';
        }
        infoHtml+='</div>';
        $('#detail-info').html(infoHtml);

        // ── 右側：異動歷程（含每筆的製令/訂單/包裝箱/儲位） ──
        var txns=r.transactions||[];
        // 儲存當前詳情資料供拆分使用
        G._detailData = r;
        if(!txns.length){ $('#detail-txns').html('<div class="empty" style="padding:20px;"><i class="fa fa-history"></i><div style="margin-top:8px;font-size:12px;">無異動記錄</div></div>'); return; }
        var tmap={in:'入庫',out:'出庫',move:'移位',adjust:'調整',count:'盤點'};
        var tbg={in:'#d4f5ed',out:'#fde8e8',move:'#e0eeff',adjust:'#fef3e2',count:'#f3e8fd'};
        var th='<div class="txn-list">';
        txns.forEach(function(t){
            var dateStr=(t.txn_date||'').substring(0,10).replace(/-/g,'/');
            var isMove = (t.txn_type==='move');
            var qty= isMove ? 0 : parseFloat(t.txn_qty||0);
            var qtySign= isMove ? '' : (qty>=0?'+':'');
            var qtyColor= isMove ? '#888' : (qty>=0?'var(--accent)':'var(--danger)');
            var unitDisp=t.txn_unit_name&&t.txn_qty_in_unit?' ('+Math.abs(parseFloat(t.txn_qty_in_unit))+' '+esc(t.txn_unit_name)+')':'';
            var moveStr=t.txn_type==='move'&&(t.location_from||t.location_to)?' <span style="color:#888;">'+esc(t.location_from||'?')+'→'+esc(t.location_to||'?')+'</span>':'';
            // 顯示儲位（A權限且有 location_to 時顯示）
            var locStr='';
            if(G._isAdminUser){
                var locVal=t.location_to||t.location_from||'';
                if(locVal && t.txn_type!=='move'){
                    locStr=' <span style="font-size:11px;background:#e0eeff;color:#1a4fa0;padding:1px 6px;border-radius:3px;"><i class="fa fa-map-marker"></i> '+esc(locVal)+'</span>';
                }
            }
            // 每筆異動的製令/訂單/包裝箱
            var txnExtra='';
            if(t.txn_bom) txnExtra+='<span style="color:var(--accent);margin-right:6px;"><i class="fa fa-link"></i> '+esc(t.txn_bom)+'</span>';
            if(t.txn_order_no) txnExtra+='<span style="color:var(--info);margin-right:6px;"><i class="fa fa-shopping-cart"></i> '+esc(t.txn_order_no)+'</span>';
            if(t.txn_pkg) txnExtra+='<span style="color:#888;"><i class="fa fa-box"></i> '+esc(t.txn_pkg)+'</span>';
            var deptUserStr='';
            if(t.dept_name) deptUserStr+='<i class="fa fa-building-o"></i> '+esc(t.dept_name)+' ';
            if(t.out_user_name) deptUserStr+='<i class="fa fa-user-o"></i> '+esc(t.out_user_name);
            var canDel=(hasP('D')||hasP('A'))&&(t.txn_type==='in'||t.txn_type==='out'||(t.txn_type==='count'&&G._canCount));
            // A權限：可多選 checkbox（in / out / move / adjust / count 類型現在皆可拆分）
            var canSplit = G._isAdminUser && (t.txn_type==='in'||t.txn_type==='out'||t.txn_type==='move'||t.txn_type==='adjust'||t.txn_type==='count');
            var chkHtml = canSplit
                ? '<input type="checkbox" class="txn-split-chk" data-txn-id="'+t.txn_id+'" data-qty="'+qty+'" data-type="'+t.txn_type+'" data-loc="'+esc(t.location_to||t.location_from||'')+'" data-loc-id="'+(t.location_to_id||t.location_from_id||'')+'" data-remark="'+esc(t.remark||'')+'" style="margin-right:5px;cursor:pointer;" onchange="updateSplitSelection()">'
                : '';
            th+='<div class="txn-item" style="align-items:flex-start;">'
              +(canSplit?'<div style="padding-top:3px;flex-shrink:0;">'+chkHtml+'</div>':'')
              +'<div class="txn-dot '+(t.txn_type==='in'?'txn-in':t.txn_type==='out'?'txn-out':t.txn_type==='move'?'txn-move':t.txn_type==='count'?'txn-cnt':'txn-adj')+'" style="margin-top:5px;"></div>'
              +'<div class="txn-text" style="flex:1;min-width:0;">'
              +'<div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">'
              +'<span style="font-size:11px;color:#888;">'+dateStr+'</span>'
              +'<span style="font-size:11px;background:'+esc(tbg[t.txn_type]||'#eee')+';padding:1px 7px;border-radius:3px;font-weight:600;">'+esc(tmap[t.txn_type]||t.txn_type)+'</span>'
              +'<span style="font-weight:700;color:'+qtyColor+';">'+qtySign+qty+unitDisp+'</span>'
              +moveStr
              +locStr
              +'<span style="font-size:11px;color:#888;">→ 庫存'+t.qty_after+'</span>'
              +'</div>'
              +(txnExtra?'<div style="font-size:11px;margin-top:2px;">'+txnExtra+'</div>':'')
              +(t.remark?'<div style="color:#888;font-size:11px;">'+esc(t.remark)+'</div>':'')
              +(deptUserStr?'<div style="color:#aaa;font-size:11px;">'+deptUserStr+'</div>':'')
              +(t.op_name?'<div style="color:#ccc;font-size:10px;">'+esc(t.op_name)+'</div>':'')
              +'</div>'
              +(canDel?'<button class="btn btn-xs" style="border:none;color:#ccc;padding:2px 4px;flex-shrink:0;" onclick="openTxnDelete('+t.txn_id+',\''+esc(t.txn_type)+'\','+qty+','+t.stock_item_id+')" title="刪除此筆（還原庫存）"><i class="fa fa-times-circle"></i></button>':'')
              +'</div>';
        });
        th+='</div>';
        $('#detail-txns').html(th);
        // 若 A 權限且有可拆分的異動記錄，顯示 toolbar
        if(G._isAdminUser && $('.txn-split-chk').length > 0){
            $('#split-txn-toolbar').css('display','flex');
        } else {
            $('#split-txn-toolbar').hide();
        }
        updateSplitSelection();
    });
}

// ── 異動記錄刪除 ────────────────────────────────
function openTxnDelete(txnId,txnType,txnQty,itemId){
    $('#del-txn-id').val(txnId); $('#del-txn-qty').val(txnQty); $('#del-txn-type').val(txnType); $('#del-txn-item-id').val(itemId);
    var typeMap={in:'入庫',out:'出庫'};
    var reversal=-1*parseFloat(txnQty);
    $('#del-txn-info').html('<strong>異動類型：</strong>'+(typeMap[txnType]||txnType)+'<br><strong>異動數量：</strong>'+txnQty+'<br><strong>刪除後庫存將'+(reversal>0?'增加':'減少')+'</strong> '+Math.abs(reversal)+' 個單位');
    $('#detailModal').modal('hide');
    $('#txnDeleteModal').modal('show');
}
function confirmDeleteTxn(){
    var txnId=$('#del-txn-id').val();
    ajx({action:'delete_transaction',txn_id:txnId},function(r){
        if(!r.success){toast(r.message||'刪除失敗','error');$('#txnDeleteModal').modal('hide');return;}
        $('#txnDeleteModal').modal('hide');
        toast('異動記錄已刪除，庫存已還原為 '+r.new_qty,'success');
        loadList(G.page); loadStats();
        // 重開詳情
        var itemId=$('#del-txn-item-id').val();
        setTimeout(function(){ openDetail(itemId); },400);
    });
}

// ── BOM/訂單 AC 工具 ─────────────────────────────
function renderBomAc(data, acId, inputId, infoId, costId, orderDispId, orderIdId, orderInfoId){
    var h=''; data.forEach(function(r){
        var cost=r.total_cost&&r.total_cost>0?'成本$'+parseFloat(r.total_cost).toLocaleString():'無成本';
        h+='<div class="ac-item" data-bom="'+esc(r.bom)+'" data-tc="'+r.total_cost+'" data-sq="'+r.sqty+'" data-ono="'+esc(r.order_no||'')+'" data-oid="'+esc(r.order_id||'')+'" data-oprice="'+esc(r.order_price||'')+'">'
          +'<strong>'+esc(r.bom)+'</strong><span class="sub"> 料號:'+esc(r.d_id)+'</span><span class="sub"> '+cost+'</span></div>';
    });
    $('#'+acId).html(h).show();
    $(document).off('click.bom'+acId).on('click.bom'+acId,'#'+acId+' .ac-item',function(){
        var $t=$(this);
        $('#'+inputId).val($t.data('bom')); $('#'+acId).hide();
        var tc=$t.data('tc'), sq=$t.data('sq');
        if(infoId) {
            var uc = sq > 0 ? (tc / sq).toFixed(2) : 0;
            var costTxt = tc > 0 ? 'BOM總成本:$' + parseFloat(tc).toLocaleString() + '（估算單件:$' + uc + '）' : '<span style="color:#e67e22;">[此BOM目前尚無加工單價記錄]</span>';
            $('#' + infoId).html(costTxt);
            // 有 costId 時設為綁定反灰（成本由快照提供，不寫入手動欄位）
            if(costId){ _setItemCostBinding(true, tc>0?'BOM估算單件：$'+uc+'（儲存後由系統快照更新）':'<span style="color:#e67e22;">此BOM尚無加工單價記錄</span>'); $('#'+costId).val(''); }
        }
        if(orderDispId&&$t.data('ono')){ $('#'+orderDispId).val($t.data('ono')); if(orderIdId) $('#'+orderIdId).val($t.data('oid')); if(orderInfoId&&$t.data('oprice')>0) $('#'+orderInfoId).html('訂單售價:$'+parseFloat($t.data('oprice')).toLocaleString()); }
    });
}
function orderAc(term, acId, inputDispId, orderIdId, priceId, infoId){
    if(!term||term.length<1){$('#'+acId).hide();return;}
    ajx({action:'search_order',term:term},function(r){
        if(!r.success||!r.data.length){$('#'+acId).hide();return;}
        var h=''; r.data.forEach(function(o){
            h+='<div class="ac-item" data-oid="'+o.Order_id+'" data-ono="'+esc(o.Order_oo)+'" data-did="'+esc(o.d_id)+'" data-cname="'+esc(o.Client_name)+'" data-price="'+o.unit_price+'">'
              +'<strong>'+esc(o.Order_oo)+'</strong>'
              +'<span class="sub"> '+esc(o.d_id)+'</span>'
              +'<span class="sub"> — '+esc(o.Client_name)+'</span>'
              +(o.unit_price?'<span class="sub"> $'+o.unit_price+'</span>':'')
              +'</div>';
        });
        $('#'+acId).html(h).show();
        $(document).off('click.order'+acId).on('click.order'+acId,'#'+acId+' .ac-item',function(){
            var $t=$(this);
            // 顯示欄位填訂單號，不填料號
            $('#'+inputDispId).val($t.data('ono'));
            $('#'+acId).hide();
            if(orderIdId) $('#'+orderIdId).val($t.data('oid'));
            var price=$t.data('price');
            // 選定訂單後：反灰售價欄（不自動填入手動欄位）
            if(priceId){ _setItemPriceBinding(true, price>0?'訂單售價：$'+parseFloat(price).toLocaleString()+'（儲存後由系統快照更新）':null); $('#'+priceId).val(''); }
            if(infoId&&price>0) $('#'+infoId).html('訂單售價：$'+parseFloat(price).toLocaleString());
        });
    });
}

// ── 品項可用單位 ─────────────────────────────────
// 未選料號時，主計量單位下拉填入全部單位並預設選 PCS
function _fillItemUnitDefaultPCS(){
    var h='<option value="">— 選擇單位 —</option>';
    var pcsVal=null;
    (G.allUnits||[]).forEach(function(u){
        if(u.unit_name==='PCS') pcsVal=u.unit_id;
        h+='<option value="'+u.unit_id+'">'+esc(u.unit_name)+(u.unit_symbol&&u.unit_symbol!==u.unit_name?' ('+esc(u.unit_symbol)+')':'')+'</option>';
    });
    $('#item-unit').html(h);
    if(pcsVal!=null) $('#item-unit').val(pcsVal);
}
function loadItemUnitsForSelect(dsid, targetSelectId, forceUnitId){
    if(!dsid) return;
    var selId = targetSelectId || 'item-unit';
    ajx({action:'get_item_units',d_setting_id:dsid},function(r){
        var h='<option value="">— 選擇單位 —</option>';
        var hasForceId = false;
        var pcsVal = null; // 記錄 PCS 單位的 value，供無預設時帶入
        if(r.success && r.data.length){
            r.data.forEach(function(u){
                var sel = (forceUnitId ? (u.unit_id == forceUnitId) : u.is_default);
                if(u.unit_id == forceUnitId) hasForceId = true;
                if(u.unit_name==='PCS') pcsVal=u.unit_id;
                h+='<option value="'+u.unit_id+'"'+(sel?' selected':'')+'>'+esc(u.unit_name)+(u.unit_symbol&&u.unit_symbol!==u.unit_name?' ('+esc(u.unit_symbol)+')':'')+'</option>';
            });
        } else {
            // 如果該料號沒有設定可用單位，則顯示全部單位
            G.allUnits.forEach(function(u){
                var sel = (forceUnitId ? (u.unit_id == forceUnitId) : false);
                if(u.unit_id == forceUnitId) hasForceId = true;
                if(u.unit_name==='PCS') pcsVal=u.unit_id;
                h+='<option value="'+u.unit_id+'"'+(sel?' selected':'')+'>'+esc(u.unit_name)+(u.unit_symbol&&u.unit_symbol!==u.unit_name?' ('+esc(u.unit_symbol)+')':'')+'</option>';
            });
        }

        // 如果指定了單位(如組合件的 31) 但列表中沒有，則從全域單位中補上
        if(forceUnitId && !hasForceId){
            var unitObj = G.allUnits.find(function(u){ return u.unit_id == forceUnitId; });
            if(unitObj){
                h+='<option value="'+forceUnitId+'" selected>'+esc(unitObj.unit_name)+(unitObj.unit_symbol?' ('+esc(unitObj.unit_symbol)+')':'')+'</option>';
            }
        }

        $('#'+selId).html(h);
        if(forceUnitId) $('#'+selId).val(forceUnitId);
        // 主計量單位預設 PCS：未指定且該料號無預設單位時，自動選 PCS
        else if(!$('#'+selId).val() && pcsVal!=null) $('#'+selId).val(pcsVal);
    });
}
function loadItemUnitsForTxn(dsid, selectId){
    // 重置
    var h='<option value="">主單位</option>';
    G.allUnits.forEach(function(u){ h+='<option value="'+u.unit_id+'">'+esc(u.unit_name)+'</option>'; });
    $('#'+selectId).html(h);
    if(!dsid) return;
    ajx({action:'get_item_units',d_setting_id:dsid},function(r){
        if(!r.success||!r.data.length) return;
        var h2='<option value="">主單位</option>';
        r.data.forEach(function(u){ if(!u.is_default) h2+='<option value="'+u.unit_id+'">'+esc(u.unit_name)+(u.unit_symbol&&u.unit_symbol!==u.unit_name?' ('+esc(u.unit_symbol)+')':'')+'</option>'; });
        $('#'+selectId).html(h2);
    });
}

// ── 品項單位管理 ─────────────────────────────────
function openItemUnitsModal(){
    if(!G.currentItemDsid){toast('請先選擇料號','error');return;}
    ajx({action:'get_item_units',d_setting_id:G.currentItemDsid},function(r){
        G.currentItemUnits=r.data||[];
        renderItemUnitsForm();
        $('#itemUnitsModal').modal('show');
    });
}
function renderItemUnitsForm(){
    var h='<table class="table tbl-sm"><thead><tr><th>單位</th><th>主要單位</th><th>換算係數</th><th></th></tr></thead><tbody id="iu-tbody">';
    G.currentItemUnits.forEach(function(u,i){
        var uOpts=''; G.allUnits.forEach(function(au){ uOpts+='<option value="'+au.unit_id+'"'+(au.unit_id==u.unit_id?' selected':'')+'>'+esc(au.unit_name)+'</option>'; });
        h+='<tr><td><select class="form-control input-sm iu-unit" data-i="'+i+'">'+uOpts+'</select></td>'
         +'<td style="text-align:center;"><input type="checkbox" class="iu-def" data-i="'+i+'"'+(u.is_default?' checked':'')+'></td>'
         +'<td><input type="number" class="form-control input-sm iu-conv" data-i="'+i+'" value="'+(u.convert_to_default||'')+'" step="0.000001" placeholder="留空=使用單位預設"></td>'
         +'<td><button class="btn btn-xs btn-danger" onclick="removeItemUnit('+i+')"><i class="fa fa-trash"></i></button></td></tr>';
    });
    h+='</tbody></table>';
    $('#item-units-content').html(h);
}
function addItemUnit(){ G.currentItemUnits.push({unit_id:'',is_default:0,convert_to_default:null}); renderItemUnitsForm(); }
function removeItemUnit(i){ G.currentItemUnits.splice(i,1); renderItemUnitsForm(); }
function saveItemUnits(){
    var units=[]; $('#iu-tbody tr').each(function(){
        var uid=$('.iu-unit',this).val(); if(!uid) return;
        units.push({unit_id:uid,is_default:$('.iu-def',this).prop('checked')?1:0,convert:$('.iu-conv',this).val()||null});
    });
    ajx({action:'save_item_units',d_setting_id:G.currentItemDsid,units:JSON.stringify(units)},function(r){
        if(!r.success){toast(r.message,'error');return;}
        $('#itemUnitsModal').modal('hide'); toast('單位設定已儲存','success');
    });
}

// ── TAB 切換 ────────────────────────────────────
function switchTab(tab, btn){
    $('#tab-list,#tab-count,#tab-setting,#tab-req,#tab-report').hide();
    // 分析 tab 用 visibility 而非 display:none，讓 canvas 保持有尺寸
    $('#tab-analysis').css({'visibility':'hidden','position':'absolute','pointer-events':'none'});
    if(tab==='analysis'){
        $('#tab-analysis').css({'visibility':'visible','position':'relative','pointer-events':'auto','display':'block'});
        if(!$('#an-period-a').val()) onPeriodChange();
    } else {
        $('#tab-'+tab).show();
    }
    $('.tab-btn').removeClass('active'); $(btn).addClass('active');
    if(tab==='analysis') loadAnalysis();
    if(tab==='count'&&!G.countLoaded){loadCountSessions();G.countLoaded=true;}
    if(tab==='setting') loadSettingData();
    if(tab==='req'&&!G.reqLoaded){ showReqPermBadge(); loadRequisitions(); G.reqLoaded=true; }
    if(tab==='report'&&!G.reportLoaded){initDailyReport();G.reportLoaded=true;}
}

function showReqPermBadge(){
    var labels={'A':'管理員（全權）','R':'唯讀','C':'可新增','U':'可修改','D':'可刪除'};
    var p=PERM||'R';
    var text=p==='A'?'管理員（全權）':(p==='R'?'唯讀（R）':('權限：'+p));
    var color=p==='A'?'#155724':(p==='R'?'#856404':'#0c4a6e');
    var bg=p==='A'?'#d4edda':(p==='R'?'#fff3cd':'#e0f2fe');
    $('#req-perm-badge').css({background:bg,color:color,'border-color':bg}).text(text);
}

var _notifInterval = null;
var _notifUnreadCount = 0;
var _origTitle = document.title;
function requestDesktopNotifPerm(){
    // Notification API 只在 HTTPS 或 localhost 下可用；HTTP + IP 環境靜默降級為 title badge
    if(!('Notification' in window)) return;
    if(location.protocol==='https:'||location.hostname==='localhost'||location.hostname==='127.0.0.1'){
        if(Notification.permission==='default') Notification.requestPermission();
    }
}
function updateNotifTitleBadge(){
    document.title = _notifUnreadCount>0 ? '('+_notifUnreadCount+') '+_origTitle : _origTitle;
}
function startNotifPolling(){
    if(_notifInterval) return;
    pollNotifications();
    _notifInterval = setInterval(pollNotifications, 3000);
}
function pollNotifications(){
    ajx({action:'get_unread_notifications'}, function(r){
        if(!r.success||!r.notifications||!r.notifications.length) return;
        r.notifications.forEach(function(n){ showReqNotification(n); });
    });
}
function showDesktopNotification(n){
    if(!('Notification' in window)||Notification.permission!=='granted') return;
    var title={'new':'📋 新領料單','modified':'✏️ 領料單已修改','deleted':'🗑️ 領料單已刪除'}[n.type]||'📢 領料通知';
    var ntf=new Notification(title, {body: n.message||'', icon: '/favicon.ico', tag: 'req-notif-'+n.notif_id, requireInteraction: false});
    ntf.onclick=function(){
        window.focus();
        switchTab('req', document.querySelector('.tab-btn[onclick*="req"]'));
        if(n.req_id) setTimeout(function(){ openReqDetail(parseInt(n.req_id)); }, 300);
        ntf.close();
    };
    setTimeout(function(){ ntf.close(); }, 8000);
}
function showReqNotification(n){
    var panel=$('#req-notif-panel');
    var existing=$('#notif-'+n.notif_id);
    if(existing.length) return; // 已顯示過
    _notifUnreadCount++; updateNotifTitleBadge();
    showDesktopNotification(n); // OS 桌面通知（HTTPS only，HTTP 靜默略過）
    var icon={'new':'fa-plus-circle','modified':'fa-pencil','deleted':'fa-trash'}[n.type]||'fa-bell';
    var color={'new':'#1ABB9C','modified':'#f39c12','deleted':'#e74c3c'}[n.type]||'#555';
    var card=$('<div id="notif-'+n.notif_id+'" style="pointer-events:auto;background:#fff;border:1px solid #ddd;border-left:4px solid '+color+';border-radius:6px;padding:10px 14px;box-shadow:0 3px 12px rgba(0,0,0,.15);cursor:pointer;max-width:300px;font-size:13px;position:relative;">'
        +'<div style="font-weight:700;color:'+color+';margin-bottom:4px;"><i class="fa '+icon+'"></i> 領料通知</div>'
        +'<div style="color:#333;">'+esc(n.message||'')+'</div>'
        +'<div style="font-size:10px;color:#aaa;margin-top:4px;">'+esc((n.created_at||'').substr(0,16))+'</div>'
        +'<button onclick="dismissNotif('+n.notif_id+',\''+esc(n.req_id)+'\',event)" style="position:absolute;top:6px;right:8px;background:none;border:none;color:#aaa;font-size:14px;cursor:pointer;" title="關閉">&times;</button>'
        +'</div>');
    card.on('click',function(e){ if($(e.target).is('button')) return; dismissNotif(n.notif_id,n.req_id,e); switchTab('req',document.querySelector('.tab-btn[onclick*="req"]')); if(n.req_id) openReqDetail(parseInt(n.req_id)); });
    panel.append(card);
    // 5分鐘後自動消失
    setTimeout(function(){ card.fadeOut(400,function(){ card.remove(); if(_notifUnreadCount>0){_notifUnreadCount--;updateNotifTitleBadge();} }); }, 300000);
}
function dismissNotif(notifId, reqId, e){
    if(e) e.stopPropagation();
    $('#notif-'+notifId).fadeOut(200, function(){ $(this).remove(); });
    ajx({action:'mark_notification_read', notif_id:notifId}, function(){});
    if(_notifUnreadCount>0){ _notifUnreadCount--; updateNotifTitleBadge(); }
}

// ── 設定頁 ──────────────────────────────────────
function loadSettingData(){
    loadLocs(1); loadCats(1); loadUnits(1); loadSafety(1);
}
function loadLocs(p){
    G.locPage=p;
    ajx({action:'get_locations', category_id:0, page:p, page_size:G.managePageSize, area_id: G.locAreaFilter, search:(G.locSearch||'')}, function(r){
        if(r.success) { renderLocTable(r.data); renderMiniPager('loc-pager', r.total, r.page, r.page_size, 'loadLocs'); renderLocAreaSwitcher(r.has_none); }
    });
}
// 重新抓取全部儲位至 G.allLocs 快取（新增/修改/移位儲位後呼叫，確保編輯表單與種類允許儲位顯示最新資料）
function refreshAllLocs(cb){
    ajx({action:'get_locations', category_id:0, page:0}, function(r){
        if(r.success) G.allLocs=r.data;
        if(cb) cb();
    });
}
function loadCats(p){
    G.catPage=p;
    ajx({action:'get_categories', page:p, page_size:G.managePageSize}, function(r){
        if(r.success) { renderCatTable(r.data); renderMiniPager('cat-pager', r.total, r.page, r.page_size, 'loadCats'); }
    });
}
// 重新抓取全部品項種類至 G.allCats 快取（種類儲存後呼叫，確保編輯帶出最新設定，含顯示欄位勾選）
function refreshAllCats(cb){
    ajx({action:'get_categories'}, function(r){
        if(r.success) G.allCats=r.data;
        if(cb) cb();
    });
}
function loadUnits(p){
    G.unitPage=p;
    ajx({action:'get_units', page:p, page_size:G.managePageSize}, function(r){
        if(r.success) { renderUnitTable(r.data); renderMiniPager('unit-pager', r.total, r.page, r.page_size, 'loadUnits'); }
    });
}
function loadSafety(p){
    G.safetyPage=p;
    ajx({action:'get_safety_stock', page:p, page_size:G.managePageSize}, function(r){
        if(r.success) { renderSafetyTable(r.data); renderMiniPager('safety-pager', r.total, r.page, r.page_size, 'loadSafety'); }
    });
}
function renderLocAreaSwitcher(hasNone){
    var h = '<button class="btn btn-xs '+(G.locAreaFilter==='all'?'btn-primary':'btn-default')+'" style="border-radius:4px;" onclick="setLocAreaFilter(\'all\')">全部</button>';
    G.allAreas.forEach(function(a){
        h += '<button class="btn btn-xs '+(G.locAreaFilter==a.area_id?'btn-primary':'btn-default')+'" style="border-radius:4px;" onclick="setLocAreaFilter('+a.area_id+')">'+esc(a.area_name)+'</button>';
    });
    if(hasNone){
        h += '<button class="btn btn-xs '+(G.locAreaFilter==='none'?'btn-primary':'btn-default')+'" style="border-radius:4px;" onclick="setLocAreaFilter(\'none\')">未設定廠區</button>';
    }
    $('#loc-area-switcher').html(h);
}
function setLocAreaFilter(aid){
    G.locAreaFilter = aid;
    loadLocs(1);
}
function renderMiniPager(containerId, total, page, ps, cbName){
    var totalPages = Math.ceil(total/ps);
    if(totalPages <= 1){ $('#'+containerId).html('<div style="font-size:11px;color:#999;text-align:right;width:100%;">共 '+total+' 筆</div>'); return; }
    var h = '<div class="pager-btns" style="display:flex;align-items:center;gap:3px;">';
    
    var prevDis = page <= 1 ? 'disabled style="opacity:0.4;cursor:default;"' : 'onclick="'+cbName+'('+(page-1)+')"';
    h += '<button class="btn btn-xs btn-default" '+prevDis+'><i class="fa fa-chevron-left"></i> 上一頁</button>';
    
    h += '<span style="font-size:12px;font-weight:bold;margin:0 10px;color:var(--primary);">' + page + ' / ' + totalPages + '</span>';

    var nextDis = page >= totalPages ? 'disabled style="opacity:0.4;cursor:default;"' : 'onclick="'+cbName+'('+(page+1)+')"';
    h += '<button class="btn btn-xs btn-default" '+nextDis+'>下一頁 <i class="fa fa-chevron-right"></i></button>';
    
    h += '</div><div style="font-size:11px;color:#999;margin-left:auto;">共 '+total+' 筆</div>';
    $('#'+containerId).html(h);
}
function renderLocTable(data){
    // 依廠區分組，廠區本身依 sort_order 排（從 G.allAreas 取），同廠區內依 sort_order 再依 location_code 排
    var areaOrder={};
    G.allAreas.forEach(function(a,i){ areaOrder[a.area_name]=a.sort_order*1000+i; });
    var sorted=data.slice().sort(function(a,b){
        var ao=areaOrder[a.area_name]!==undefined?areaOrder[a.area_name]:99999;
        var bo=areaOrder[b.area_name]!==undefined?areaOrder[b.area_name]:99999;
        if(ao!==bo) return ao-bo;
        var so=(parseInt(a.sort_order)||0)-(parseInt(b.sort_order)||0);
        if(so!==0) return so;
        return (a.location_code||'').localeCompare(b.location_code||'');
    });
    var h=''; var lastArea=null;
    sorted.forEach(function(l){
        var area=l.area_name||l.area||'（未分廠區）';
        if(area!==lastArea){
            h+='<tr style="background:#f0f4f8;"><td colspan="4" style="font-size:11px;font-weight:700;color:#888;padding:4px 8px;letter-spacing:1px;">▸ '+esc(area)+'</td></tr>';
            lastArea=area;
        }
        h+='<tr>'
         +'<td style="padding-left:16px;"><strong>'+esc(l.location_code)+'</strong></td>'
         +'<td style="color:#888;font-size:11px;">'+esc(area)+'</td>'
         +'<td>'+esc(l.location_name||'')+'</td>'
         +'<td>'
         +(hasP('U')||hasP('A')?'<button class="btn btn-xs btn-default" onclick="openLocModal('+l.location_id+')" title="編輯"><i class="fa fa-pencil"></i></button> ':'')
         +(hasP('U')||hasP('A')?'<button class="btn btn-xs btn-info" onclick="openMoveLocModal('+l.location_id+',\''+esc(l.location_code)+'\')" title="整批移動到其他儲位"><i class="fa fa-exchange"></i></button> ':'')
         +(hasP('D')||hasP('A')?'<button class="btn btn-xs btn-danger" onclick="delLoc('+l.location_id+',\''+esc(l.location_code)+'\')"><i class="fa fa-trash"></i></button>':'')
         +'</td></tr>';
    });
    $('#loc-tbody').html(h||'<tr><td colspan="4" class="text-center text-muted">尚無儲位</td></tr>');
}
// 顯示欄位設定摘要（料號規格/標籤/保管者/廠商）
function _catShowFieldsStr(c){
    var t=[];
    if(parseInt(c.show_spec)===1) t.push('規格');
    if(parseInt(c.show_label)===1) t.push('標籤');
    if(parseInt(c.show_keeper)===1) t.push('保管');
    if(parseInt(c.show_vendor)===1) t.push('廠商');
    return t.map(function(x){ return '<span class="cbadge" style="background:#eef;color:#4a5;">'+x+'</span>'; }).join(' ');
}
function renderCatTable(data){
    var h=''; data.forEach(function(c){
        var ey=c.override_expire_years||c.default_expire_years;
        var dashVal=(c.show_in_dashboard!==undefined&&c.show_in_dashboard!==null)?parseInt(c.show_in_dashboard):1;
        h+='<tr>'
          +'<td><span class="cbadge" style="background:'+esc(c.color||'#888')+'22;color:'+esc(c.color||'#888')+';">'+esc(c.category_name)+'</span></td>'
          +'<td>'+(ey?ey+'年':'—')+'</td>'
          +'<td style="text-align:center;">'+(c.need_bom_bind?'✓':'—')+'</td>'
          +'<td style="text-align:center;">'+(c.need_order_bind?'✓':'—')+'</td>'
          +'<td style="text-align:center;">'+(dashVal?'<i class="fa fa-check" style="color:var(--accent);"></i>':'<i class="fa fa-minus" style="color:#ccc;"></i>')+'</td>'
          +'<td style="font-size:11px;">'+(_catShowFieldsStr(c)||'<span style="color:#ccc;">—</span>')+'</td>'
          +'<td id="cat-loc-cnt-'+c.category_id+'" style="font-size:11px;color:#888;">—</td>'
          +'<td>'+(hasP('U')||hasP('A')?'<button class="btn btn-xs btn-default" onclick="openCatModal('+c.category_id+')"><i class="fa fa-pencil"></i></button> ':'')+' '+(hasP('D')||hasP('A')?'<button class="btn btn-xs btn-danger" onclick="delCat('+c.category_id+')"><i class="fa fa-trash"></i></button>':'')+'</td>'
          +'</tr>';
    });
    $('#cat-tbody').html(h||'<tr><td colspan="8" class="text-center text-muted">尚無種類</td></tr>');
    // 非同步載入各種類的儲位綁定數
    data.forEach(function(c){
        ajx({action:'get_cat_locations',category_id:c.category_id},function(r){
            if(r.success){
                var cnt=r.location_ids.length;
                $('#cat-loc-cnt-'+c.category_id).html(cnt>0?'<span style="color:var(--accent);">'+cnt+'個儲位</span>':'<span style="color:#bbb;">全部</span>');
            }
        });
    });
}
function openCatModal(id){
    $('#cat-id').val(id||0); $('#catTitle').text(id?'編輯種類':'新增品項種類');
    if(!id){
        $('#cat-name,#cat-code').val(''); $('#cat-ey').val(''); $('#cat-color').val('#1ABB9C');
        $('#cat-bom,#cat-order').val(0); $('#cat-dashboard').val(1); $('#cat-sort').val(0);
        $('#cat-show-spec,#cat-show-label,#cat-show-keeper,#cat-show-vendor').prop('checked',false);
        _loadCatLocList(0);
        $('#catModal').modal('show');
        return;
    }
    // 編輯：先刷新種類快取，確保帶出最新已儲存設定（含顯示欄位勾選），不受上次儲存後快取未更新影響
    refreshAllCats(function(){
        var c=G.allCats.find(function(x){return x.category_id==id;});
        if(c){
            $('#cat-name').val(c.category_name); $('#cat-code').val(c.category_code||'');
            $('#cat-ey').val(c.override_expire_years||c.default_expire_years||'');
            $('#cat-color').val(c.color||'#1ABB9C');
            $('#cat-bom').val(c.need_bom_bind||0); $('#cat-order').val(c.need_order_bind||0);
            var dashVal=(c.show_in_dashboard!==undefined&&c.show_in_dashboard!==null)?parseInt(c.show_in_dashboard):1;
            $('#cat-dashboard').val(dashVal);
            $('#cat-sort').val(c.sort_order||0);
            $('#cat-show-spec').prop('checked',parseInt(c.show_spec)===1);
            $('#cat-show-label').prop('checked',parseInt(c.show_label)===1);
            $('#cat-show-keeper').prop('checked',parseInt(c.show_keeper)===1);
            $('#cat-show-vendor').prop('checked',parseInt(c.show_vendor)===1);
        }
        _loadCatLocList(id);
        $('#catModal').modal('show');
    });
}
// 需求3：以「搜尋 + 標籤多選」管理允許儲位
var _catSelLocs = {}; // location_id -> 儲位物件
function _loadCatLocList(catId){
    _catSelLocs = {};
    $('#cat-loc-search').val(''); $('#cat-loc-ac').hide();
    $('#cat-loc-chips').html(''); $('#cat-loc-count').text('');
    // 先刷新全部儲位快取（需求2：確保廠區等為最新），再帶出已綁定的儲位
    refreshAllLocs(function(){
        if(catId>0){
            ajx({action:'get_cat_locations',category_id:catId},function(r){
                var ids=(r.success?r.location_ids:[])||[];
                ids.forEach(function(id){
                    var l=(G.allLocs||[]).find(function(x){return x.location_id==id;});
                    if(l) _catSelLocs[l.location_id]=l;
                });
                _renderCatLocChips();
            });
        } else {
            _renderCatLocChips();
        }
    });
}
function _renderCatLocChips(){
    var h='';
    Object.keys(_catSelLocs).forEach(function(id){
        var l=_catSelLocs[id];
        var label=(l.area_name?'['+l.area_name+'] ':'')+l.location_code+(l.location_name?' '+l.location_name:'');
        h+='<span style="display:inline-flex;align-items:center;gap:5px;background:#e8f5f2;color:#138a76;border:1px solid #a8dfd4;border-radius:12px;padding:1px 7px 1px 10px;font-size:12px;">'
         +esc(label)+'<i class="fa fa-times" style="cursor:pointer;color:#999;" title="移除" onclick="_catLocRemove('+id+')"></i></span>';
    });
    $('#cat-loc-chips').html(h);
    _updateCatLocCount();
}
function _updateCatLocCount(){
    var n=Object.keys(_catSelLocs).length;
    $('#cat-loc-count').text(n===0?'（未選 = 全部儲位可用）':n+' 個儲位已選');
    $('#cat-loc-clear').toggle(n>0);
}
function _catLocRemove(id){ delete _catSelLocs[id]; _renderCatLocChips(); }
function _catLocClearAll(){ _catSelLocs={}; _renderCatLocChips(); }
function saveCat(){
    var name=($('#cat-name').val()||'').trim(); if(!name){toast('種類名稱必填','error');return;}
    var catId=$('#cat-id').val()||0;
    // 收集已選的 location_ids（需求3：搜尋+標籤多選）
    var locIds=Object.keys(_catSelLocs).map(function(x){return parseInt(x);});
    // 先儲存種類基本資料
    ajx({action:'save_category',
        category_id:catId,
        category_name:name,
        category_code:($('#cat-code').val()||'').trim(),
        default_expire_years:$('#cat-ey').val()||'',
        need_bom_bind:$('#cat-bom').val(),
        need_order_bind:$('#cat-order').val(),
        show_in_dashboard:$('#cat-dashboard').val(),
        show_spec:$('#cat-show-spec').is(':checked')?1:0,
        show_label:$('#cat-show-label').is(':checked')?1:0,
        show_keeper:$('#cat-show-keeper').is(':checked')?1:0,
        show_vendor:$('#cat-show-vendor').is(':checked')?1:0,
        color:$('#cat-color').val(),
        sort_order:$('#cat-sort').val()||0
    },function(r){
        if(!r.success){toast(r.message||'儲存失敗','error');return;}
        var savedCatId=r.id||catId;
        // 同步儲存儲位綁定
        ajx({action:'save_cat_locations',category_id:savedCatId,location_ids:JSON.stringify(locIds)},function(r2){
            $('#catModal').modal('hide');
            toast('種類已儲存'+(r2.success?'，儲位綁定：'+(locIds.length===0?'全部可用':locIds.length+'個'):''),'success');
            refreshAllCats(); // 更新快取，確保再次編輯帶出最新顯示欄位設定
            loadCats(G.catPage);
        });
    });
}
function delCat(id){
    var cat=G.allCats.find(function(c){return c.category_id==id;});
    var name=cat?cat.category_name:id;
    if(!confirm('確定刪除品項種類「'+name+'」？\n\n系統將先檢查是否有庫存使用此種類。')) return;
    ajx({action:'delete_category',category_id:id},function(r){
        if(!r.success){ _showDeleteError('無法刪除品項種類',r.message); return; }
        toast('種類已刪除','success'); loadCats(G.catPage);
    });
}
function delArea(id){
    if(!confirm('確定刪除此廠區？\n\n系統將先檢查是否有儲位屬於此廠區。')) return;
    ajx({action:'delete_area',area_id:id},function(r){
        if(!r.success){ _showDeleteError('無法刪除廠區',r.message); return; }
        toast('已刪除','success'); loadAreaList();
    });
}
function delUnit(id){
    var unit=G.allUnits.find(function(u){return u.unit_id==id;});
    var name=unit?unit.unit_name:id;
    if(!confirm('確定刪除計量單位「'+name+'」？\n\n系統將先檢查是否有庫存使用此單位。')) return;
    ajx({action:'delete_unit',unit_id:id},function(r){
        if(!r.success){ _showDeleteError('無法刪除計量單位',r.message); return; }
        toast('單位已刪除','success'); loadUnits(G.unitPage);
    });
}
// 顯示刪除失敗的詳細訊息（用 alert 顯示完整名單）
function _showDeleteError(title, msg){
    alert('⚠️ '+title+'\n\n'+msg+'\n\n請先處理以上使用記錄後，再嘗試刪除。');
}

// ── 合併庫存 ────────────────────────────────────
function openMergeModal(){
    $('#merge-search-did').val(''); $('#ac-merge-did').hide();
    $('#merge-candidates').html('<div class="text-muted text-center" style="font-size:13px;padding:20px;">請輸入料號搜尋要合併的庫存</div>');
    $('#mergeModal').modal('show');
}
$(document).on('input','#merge-search-did',function(){
    var t=$(this).val().trim(); if(!t){$('#ac-merge-did').hide();return;}
    ajx({action:'search_d_id',term:t},function(r){
        if(!r.success||!r.data.length){$('#ac-merge-did').hide();return;}
        var h=''; r.data.forEach(function(d){ h+='<div class="ac-item" data-did="'+esc(d.D_Setting_Id)+'"><strong>'+esc(d.D_Setting_Id)+'</strong>'+(d.client_name?'<span class="sub"> — '+esc(d.client_name)+'</span>':'')+'</div>'; });
        $('#ac-merge-did').html(h).show();
    });
});
$(document).on('click','#ac-merge-did .ac-item',function(){
    var did=$(this).data('did'); $('#merge-search-did').val(did); $('#ac-merge-did').hide();
    loadMergeCandidates(did);
});
function loadMergeCandidates(did){
    $('#merge-candidates').html('<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i></div>');
    ajx({action:'get_stock_list',page:1,page_size:100,search:did,sort_col:'storage_location',sort_dir:'asc'},function(r){
        var items=(r.data||[]).filter(function(x){return x.d_id===did;});
        if(items.length<2){$('#merge-candidates').html('<div class="text-muted text-center" style="padding:20px;">此料號只有'+items.length+'筆庫存，無需合併</div>');return;}
        var h='<div style="font-size:12px;color:#888;margin-bottom:8px;">選擇保留筆，並勾選要合併進來的筆</div>'
            +'<table class="table tbl-sm"><thead><tr><th>保留</th><th>合併</th><th>儲位</th><th>庫存</th><th>BOM</th><th>訂單</th></tr></thead><tbody>';
        items.forEach(function(row,i){
            var locStr=row.location_code||row.storage_location||'—';
            var unitStr=row.unit_symbol||row.unit_name||'';
            h+='<tr>'
              +'<td style="text-align:center;"><input type="radio" name="merge-keep" value="'+row.stock_item_id+'"'+(i===0?' checked':'')+' onchange="syncMergeKeep('+row.stock_item_id+')"></td>'
              +'<td style="text-align:center;"><input type="checkbox" class="merge-chk" value="'+row.stock_item_id+'"'+(i!==0?' checked':'')+' id="mchk-'+row.stock_item_id+'"></td>'
              +'<td>'+esc(locStr)+'</td>'
              +'<td>'+parseFloat(row.qty||0)+(unitStr?' '+unitStr:'')+'</td>'
              +'<td style="font-size:11px;">'+esc(row.bom_ref||'—')+'</td>'
              +'<td style="font-size:11px;">'+esc(row.order_no||'—')+'</td>'
              +'</tr>';
        });
        h+='</tbody></table>'
          +'<div class="alert alert-warning" style="font-size:12px;margin-top:8px;"><i class="fa fa-exclamation-triangle"></i> 勾選的筆庫存加總到保留筆後停用，不可復原。</div>';
        $('#merge-candidates').html(h);
    });
}
function syncMergeKeep(keepId){
    // 選了保留筆後，自動取消合併筆的勾選
    $('#mchk-'+keepId).prop('checked',false);
    $('.merge-chk').not('#mchk-'+keepId).prop('checked',true);
}
function confirmMerge(){
    var keepId=$('input[name="merge-keep"]:checked').val();
    var mergeIds=[];
    $('.merge-chk:checked').each(function(){ var v=parseInt($(this).val()); if(v!==parseInt(keepId)) mergeIds.push(v); });
    if(!keepId){toast('請選擇保留筆','error');return;}
    if(!mergeIds.length){toast('請勾選至少一筆要合併的記錄','error');return;}
    if(!confirm('確定要合併？被合併的筆將停用，不可復原。')) return;
    ajx({action:'merge_stock',keep_id:keepId,merge_ids:JSON.stringify(mergeIds)},function(r){
        if(!r.success){toast(r.message||'合併失敗','error');return;}
        $('#mergeModal').modal('hide');
        toast('合併成功！已合併'+r.merged_count+'筆，新庫存：'+r.new_qty,'success');
        loadList(G.page); loadStats();
    });
}
function renderUnitTable(data){
    var h=''; data.forEach(function(u){
        h+='<tr>'
         +'<td>'+esc(u.unit_name)+'</td>'
         +'<td>'+esc(u.unit_symbol||'')+'</td>'
         +'<td>'+esc(u.unit_type||'')+'</td>'
         +'<td>'+esc(u.base_unit_name||'（基準）')+'</td>'
         +'<td>'+esc(u.convert_factor||'')+'</td>'
         +'<td>'
         +(hasP('U')||hasP('A')?'<button class="btn btn-xs btn-default" onclick="openUnitModal('+u.unit_id+')"><i class="fa fa-pencil"></i></button> ':'')
         +(hasP('D')||hasP('A')?'<button class="btn btn-xs btn-danger" onclick="delUnit('+u.unit_id+')"><i class="fa fa-trash"></i></button>':'')
         +'</td></tr>';
    });
    $('#unit-tbody').html(h||'<tr><td colspan="6" class="text-center text-muted">尚無單位</td></tr>');
}
function renderSafetyTable(data){
    var h=''; data.forEach(function(s){
        // 依單位的 decimal_places 格式化數字
        var u=G.allUnits.find(function(x){return x.unit_id==s.unit_id;});
        var dp=u&&u.decimal_places!==undefined&&u.decimal_places!==null?parseInt(u.decimal_places):3;
        var qtyStr=parseFloat(s.safety_qty||0).toFixed(dp);
        h+='<tr>'
          +'<td><strong>'+esc(s.d_id)+'</strong></td>'
          +'<td>'+qtyStr+'</td>'
          +'<td>'+esc(s.unit_name||'主單位')+'</td>'
          +'<td>'
          +(hasP('U')||hasP('A')?'<button class="btn btn-xs btn-default" onclick="openSafetyModal('+s.id+',\''+esc(s.d_id)+'\','+s.safety_qty+','+(s.unit_id||0)+',\''+esc(s.remark||'')+'\')"><i class="fa fa-pencil"></i></button> ':'')
          +(hasP('D')||hasP('A')?'<button class="btn btn-xs btn-danger" onclick="delSafety('+s.id+')"><i class="fa fa-trash"></i></button>':'')
          +'</td></tr>';
    });
    $('#safety-tbody').html(h||'<tr><td colspan="4" class="text-center text-muted">尚未設定</td></tr>');
}

// 儲位 CRUD
function openLocModal(id){
    $('#loc-id').val(id||0); $('#locTitle').text(id?'編輯儲位':'新增儲位');
    $('#loc-code-hint').text('');
    _refreshAreaDropdowns();
    if(!id){
        $('#loc-code,#loc-name').val(''); $('#loc-area').val(''); $('#loc-sort').val(0);
        $('#locModal').modal('show');
    } else {
        // 先刷新快取，確保帶出「按下編輯的那筆」最新資料（不論是否重新整理頁面）
        refreshAllLocs(function(){
            var l=G.allLocs.find(function(x){return x.location_id==id;});
            if(l){ $('#loc-code').val(l.location_code); $('#loc-area').val(l.area||''); $('#loc-name').val(l.location_name||''); $('#loc-sort').val(l.sort_order||0); }
            else { $('#loc-code,#loc-name').val(''); $('#loc-area').val(''); $('#loc-sort').val(0); }
            $('#locModal').modal('show');
        });
    }
}
function saveLoc(){
    var code=($('#loc-code').val()||'').trim();
    var area=$('#loc-area').val()||'';
    if(!area){ toast('廠區為必填，請選擇廠區','error'); $('#loc-area').focus(); return; }
    if(!code){ toast('儲位代碼為必填','error'); $('#loc-code').focus(); return; }
    ajx({action:'save_location',location_id:$('#loc-id').val()||0,location_code:code,location_name:($('#loc-name').val()||'').trim(),area:area,sort_order:$('#loc-sort').val()||0},function(r){
        if(!r.success){ toast(r.message,'error'); return; }
        $('#locModal').modal('hide');
        if(r.revived) toast('儲位「'+code+'」（廠區：'+area+'）已從刪除記錄中復原','success');
        else toast('儲位已儲存','success');
        refreshAllLocs();
        loadLocs(G.locPage);
    });
}
function delLoc(id,code){
    if(!confirm('確定刪除儲位「'+(code||id)+'」？\n\n系統將先檢查是否有庫存使用此儲位。')) return;
    ajx({action:'delete_location',location_id:id},function(r){
        if(!r.success){ _showDeleteError('無法刪除儲位',r.message); return; }
        toast('儲位已刪除','success'); refreshAllLocs(); loadLocs(G.locPage);
    });
}
function openMoveLocModal(fromId, fromCode){
    $('#ml-from-id').val(fromId);
    $('#ml-from-label').val(fromCode);
    $('#ml-warn').hide();
    // 填充目標儲位選單（排除自己）
    var h='<option value="">— 選擇目標儲位 —</option>';
    (G.allLocs||[]).forEach(function(l){
        if(l.location_id!=fromId){
            h+='<option value="'+l.location_id+'">'+esc(l.location_code)+(l.location_name?' ('+esc(l.location_name)+')':'')+'</option>';
        }
    });
    $('#ml-to-id').html(h);
    $('#moveLocModal').modal('show');
}
function confirmMoveLoc(){
    var fromId=$('#ml-from-id').val();
    var toId=$('#ml-to-id').val();
    var toCode=$('#ml-to-id option:selected').text();
    if(!toId){ toast('請選擇目標儲位','error'); return; }
    if(!confirm('確定將儲位「'+$('#ml-from-label').val()+'」的所有庫存品項移動到「'+toCode+'」？\n\n若目標儲位已有相同料號+客戶，數量將自動合併。此操作不可撤銷。')) return;
    $('#ml-confirm-btn').prop('disabled',true).text('處理中...');
    ajx({action:'move_location',location_id_from:fromId,location_id_to:toId},function(r){
        $('#ml-confirm-btn').prop('disabled',false).html('<i class="fa fa-exchange"></i> 確認移動');
        if(!r.success){ $('#ml-warn-msg').text(r.message); $('#ml-warn').show(); return; }
        $('#moveLocModal').modal('hide');
        toast(r.message,'success');
        refreshAllLocs();
        loadLocs(G.locPage);
        loadTable();
    });
}

// ── 廠區管理 ─────────────────────────────────────
function openAreaModal(){
    loadAreaList();
    $('#areaModal').modal('show');
}
function loadAreaList(){
    ajx({action:'get_areas'},function(r){
        G.allAreas=r.data||[];
        _refreshAreaDropdowns();
        var h='';
        (r.data||[]).forEach(function(a){
            h+='<tr><td><strong>'+esc(a.area_name)+'</strong></td><td>'+esc(a.sort_order)+'</td>'
              +'<td>'
              +(hasP('U')||hasP('A')?'<button class="btn btn-xs btn-default" onclick="editArea('+a.area_id+',\''+esc(a.area_name)+'\','+a.sort_order+')"><i class="fa fa-pencil"></i></button> ':'')
              +(hasP('D')||hasP('A')?'<button class="btn btn-xs btn-danger" onclick="delArea('+a.area_id+')"><i class="fa fa-trash"></i></button>':'')
              +'</td></tr>';
        });
        $('#area-list-body').html(h||'<tr><td colspan="3" class="text-center text-muted">尚無廠區</td></tr>');
    });
}
function editArea(id,name,sort){
    $('#new-area-name').val(name); $('#new-area-sort').val(sort);
    $('#new-area-name').data('edit-id',id);
    $('#new-area-name').focus();
}
function saveArea(id){
    var eid=$('#new-area-name').data('edit-id')||id;
    var name=($('#new-area-name').val()||'').trim(); if(!name){toast('廠區名稱必填','error');return;}
    ajx({action:'save_area',area_id:eid||0,area_name:name,sort_order:$('#new-area-sort').val()||0},function(r){
        if(!r.success){toast(r.message,'error');return;}
        $('#new-area-name').val('').data('edit-id',0); $('#new-area-sort').val('');
        toast('廠區已儲存','success'); loadAreaList();
    });
}

// 單位 CRUD
function openUnitModal(id){
    $('#unit-id').val(id||'');
    var u=id?G.allUnits.find(function(x){return x.unit_id==id;}):null;
    if(u){$('#unit-name').val(u.unit_name);$('#unit-sym').val(u.unit_symbol||'');$('#unit-type').val(u.unit_type||'');$('#unit-base').val(u.base_unit_id||'');$('#unit-factor').val(u.convert_factor||'');$('#unit-sort').val(u.sort_order||0);$('#unit-decimal').val(u.decimal_places!==undefined&&u.decimal_places!==null?u.decimal_places:3);}
    else{$('#unit-name,#unit-sym,#unit-factor').val('');$('#unit-type,#unit-base').val('');$('#unit-sort').val(0);$('#unit-decimal').val(3);}
    $('#unitModal').modal('show');
}
function saveUnit(){
    ajx({action:'save_unit',unit_id:$('#unit-id').val()||0,unit_name:($('#unit-name').val()||'').trim(),unit_symbol:($('#unit-sym').val()||'').trim(),unit_type:$('#unit-type').val(),base_unit_id:$('#unit-base').val()||0,convert_factor:$('#unit-factor').val(),sort_order:$('#unit-sort').val()||0,decimal_places:$('#unit-decimal').val()||3},function(r){
        if(!r.success){toast(r.message,'error');return;}
        $('#unitModal').modal('hide'); toast('單位已儲存','success'); loadUnits(G.unitPage);
    });
}

// 安全庫存
function openSafetyModal(id,did,qty,unitId,rem){
    $('#safety-did').val(did||'');
    $('#safety-dsid').val('');
    $('#safety-qty').val(qty||0);
    $('#safety-rem').val(rem||'');
    // 帶入已設定的單位（等 allUnits 載完後設定）
    if(unitId){
        $('#safety-unit').val(unitId);
        // 若下拉還沒選到（選項還沒建好），延遲一下再設
        if(!$('#safety-unit').val()) setTimeout(function(){ $('#safety-unit').val(unitId); },300);
    } else {
        $('#safety-unit').val('');
    }
    $('#safetyModal').modal('show');
}
function saveSafety(){ var did=$('#safety-did').val().trim(); if(!did){toast('料號必填','error');return;} ajx({action:'save_safety_stock',d_id:did,d_setting_id:$('#safety-dsid').val()||0,safety_qty:$('#safety-qty').val()||0,unit_id:$('#safety-unit').val()||0,remark:$('#safety-rem').val().trim()},function(r){ if(!r.success){toast(r.message,'error');return;} $('#safetyModal').modal('hide'); toast('安全庫存已設定','success'); loadSafety(G.safetyPage); loadList(G.page); }); }
function delSafety(id){ if(!confirm('確定刪除此安全庫存設定？')) return; ajx({action:'delete_safety_stock',id:id},function(r){ if(!r.success){toast(r.message,'error');return;} toast('已刪除','success'); loadSafety(G.safetyPage); loadList(G.page); }); }

// ── 分析 ────────────────────────────────────────
function onPeriodChange(){
    var p=$('#an-period').val();
    var now=new Date();
    if(p==='month') $('#an-period-a').val(now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0'));
    else if(p==='quarter') $('#an-period-a').val(now.getFullYear()+'-Q'+Math.ceil((now.getMonth()+1)/3));
    else $('#an-period-a').val(String(now.getFullYear()));
    $('#an-period-b').val('');
}
function loadAnalysis(){
    var period=$('#an-period').val()||'month';
    var pA=$('#an-period-a').val()||''; var pB=$('#an-period-b').val()||'';
    var label={month:'月',quarter:'季',year:'年'}[period]||'月';
    $('#an-period-label').text((pA?'期間A: '+pA:'')+(pB?' vs '+pB:''));
    ajx({action:'get_analysis',period:period,period_a:pA,period_b:pB},function(r){
        if(!r.success){ toast('分析資料載入失敗：'+(r.message||''),'error'); return; }
        var trendTitle=(pA||'本'+label)+(pB?' vs '+(pB):'');
        $('#an-trend-label').text('（'+trendTitle+'）');
        setTimeout(function(){ _renderAnalysisCharts(r); }, 50);
    });
}
function printAnalysis(){
    var content=document.getElementById('analysis-content');
    if(!content) return;
    var w=window.open('','_blank','width=900,height=700');
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>庫存分析報告</title>'
        +'<style>body{font-family:Arial,sans-serif;margin:20px;}h2{color:#2c3e50;border-bottom:2px solid #1ABB9C;padding-bottom:8px;}'
        +'.chart-card{border:1px solid #eee;border-radius:8px;padding:14px;margin-bottom:14px;page-break-inside:avoid;}'
        +'h5{font-size:14px;font-weight:700;margin:0 0 10px 0;}canvas{max-width:100%!important;}'
        +'@media print{.no-print{display:none;}button{display:none;}}'
        +'</style></head><body>');
    w.document.write('<h2>庫存分析報告</h2>');
    w.document.write('<p style="color:#888;font-size:12px;">產出時間：'+new Date().toLocaleString('zh-TW')+'&nbsp;&nbsp;期間：'+($('#an-period-label').text()||'本期')+' （'+$('#an-period option:selected').text()+'）</p>');
    // Convert charts to images
    ['chart-cat','chart-cli','chart-trend'].forEach(function(id){
        var el=document.getElementById(id);
        if(el){
            try{
                var img=el.toDataURL('image/png');
                w.document.write('<div class="chart-card"><img src="'+img+'" style="max-width:100%;"></div>');
            }catch(ex){}
        }
    });
    // Aging list
    var aging=document.getElementById('aging-list');
    if(aging) w.document.write('<div class="chart-card"><h5>庫齡分析</h5>'+aging.innerHTML+'</div>');
    w.document.write('<div class="no-print" style="margin-top:20px;text-align:center;"><button onclick="window.print()" style="padding:10px 30px;background:#1ABB9C;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;">🖨️ 列印 / 儲存PDF</button></div>');
    w.document.write('</body></html>');
    w.document.close();
    w.onload=function(){ setTimeout(function(){ w.focus(); },300); };
}
// ── Canvas 繪圖工具：HiDPI 支援 ─────────────────
function _setupHiDPICanvas(el, cssW, cssH){
    var dpr = window.devicePixelRatio || 1;
    el.width  = Math.round(cssW * dpr);
    el.height = Math.round(cssH * dpr);
    el.style.width  = cssW + 'px';
    el.style.height = cssH + 'px';
    var ctx = el.getContext('2d');
    ctx.scale(dpr, dpr);
    return ctx; // 後續所有繪圖用 cssW/cssH 的邏輯座標即可
}

var G_cliLastData = null; // 儲存最後一次 by_client 資料供切換使用
var G_cliMode = 'qty';   // 'qty'=庫存數量, 'cnt'=品項筆數

function switchCliMode(mode){
    G_cliMode = mode;
    $('#cli-btn-qty').css({background: mode==='qty'?'var(--accent)':'', color: mode==='qty'?'#fff':''}).toggleClass('btn-default', mode!=='qty');
    $('#cli-btn-cnt').css({background: mode==='cnt'?'var(--accent)':'', color: mode==='cnt'?'#fff':''}).toggleClass('btn-default', mode!=='cnt');
    if(G_cliLastData) _drawCliChart(G_cliLastData);
}
function _drawCliChart(byCli){
    G_cliLastData = byCli;
    var cliEl = document.getElementById('chart-cli');
    if(!cliEl) return;
    var useQty = (G_cliMode !== 'cnt');
    // 依選擇的模式排序並取前10
    var sorted = byCli.slice().sort(function(a,b){
        return useQty ? (parseFloat(b.tq||0)-parseFloat(a.tq||0)) : (parseInt(b.cnt||0)-parseInt(a.cnt||0));
    });
    var cliData = sorted.slice(0, 10);
    var barH=28, padT=8, padB=8, padL=110, padR=55;
    var cliW = cliEl.parentElement ? Math.max(280, cliEl.parentElement.offsetWidth-32) : 420;
    var cliH = Math.min(padT + cliData.length * barH + padB, 320);
    var ctx2 = _setupHiDPICanvas(cliEl, cliW, cliH);
    ctx2.clearRect(0,0,cliW,cliH);
    if(cliData.length){
        var vals = cliData.map(function(x){ return useQty ? parseFloat(x.tq||0) : parseInt(x.cnt||0); });
        var maxV = Math.max.apply(null, vals) || 1;
        var chartW = cliW - padL - padR;
        // 格線
        ctx2.save(); ctx2.strokeStyle='#f2f2f2'; ctx2.lineWidth=1;
        for(var gx=1;gx<=4;gx++){
            var gxp=padL+Math.round((gx/4)*chartW);
            ctx2.beginPath(); ctx2.moveTo(gxp,padT); ctx2.lineTo(gxp,cliH-padB); ctx2.stroke();
        }
        ctx2.restore();
        cliData.forEach(function(item,i){
            var val = vals[i];
            var bw = Math.max(2, Math.round((val/maxV)*chartW));
            var y = padT + i*barH;
            var barMid = y + barH*0.5;
            // 標籤
            ctx2.save();
            ctx2.font='11px Arial'; ctx2.fillStyle='#555'; ctx2.textAlign='right'; ctx2.textBaseline='middle';
            var lbl=(item.client_name||'(未指定)'); if(lbl.length>14) lbl=lbl.substring(0,13)+'…';
            ctx2.fillText(lbl, padL-6, barMid); ctx2.restore();
            // 條
            ctx2.save();
            ctx2.fillStyle = i%2===0 ? 'rgba(52,152,219,0.82)' : 'rgba(41,128,185,0.68)';
            ctx2.fillRect(padL, y+5, bw, barH-10); ctx2.restore();
            // 數值（格式化）
            ctx2.save();
            ctx2.fillStyle='#2c3e50'; ctx2.font='bold 11px Arial'; ctx2.textAlign='left'; ctx2.textBaseline='middle';
            var dispVal = useQty ? (val>=1000?(val/1000).toFixed(1)+'K':val.toFixed(val%1===0?0:1)) : val;
            ctx2.fillText(dispVal, padL+bw+4, barMid); ctx2.restore();
        });
        // Y 軸線
        ctx2.save(); ctx2.strokeStyle='#ccc'; ctx2.lineWidth=1;
        ctx2.beginPath(); ctx2.moveTo(padL,padT); ctx2.lineTo(padL,cliH-padB); ctx2.stroke(); ctx2.restore();
    } else {
        ctx2.font='12px Arial'; ctx2.fillStyle='#bbb'; ctx2.textAlign='center'; ctx2.textBaseline='middle';
        ctx2.fillText('暫無客戶資料', cliW/2, cliH/2);
    }
}

function _renderAnalysisCharts(r){
    var FONT = '12px Arial, sans-serif';
    var FONT_BOLD = 'bold 13px Arial, sans-serif';

    // ── 1. 種類圓餅圖 ──────────────────────────────
    var byCat = r.by_cat || [];
    var catEl = document.getElementById('chart-cat');
    if(catEl){
        var cW=260, cH=290;
        var ctx = _setupHiDPICanvas(catEl, cW, cH);
        ctx.clearRect(0,0,cW,cH);
        if(byCat.length){
            var total = byCat.reduce(function(s,x){return s+parseInt(x.cnt||0);},0);
            if(total > 0){
                var cx=130, cy=120, outerR=100, innerR=50, angle=-Math.PI/2;
                byCat.forEach(function(item){
                    var cnt = parseInt(item.cnt||0);
                    var sweep = (cnt/total)*(2*Math.PI);
                    ctx.beginPath();
                    ctx.moveTo(cx, cy);
                    ctx.arc(cx, cy, outerR, angle, angle+sweep);
                    ctx.closePath();
                    ctx.fillStyle = item.color || '#888';
                    ctx.fill();
                    ctx.strokeStyle = '#fff';
                    ctx.lineWidth = 2;
                    ctx.stroke();
                    angle += sweep;
                });
                // 內圓
                ctx.beginPath(); ctx.arc(cx,cy,innerR,0,2*Math.PI);
                ctx.fillStyle='#fff'; ctx.fill();
                // 中心數字
                ctx.font = 'bold 20px Arial'; ctx.fillStyle='#2c3e50'; ctx.textAlign='center'; ctx.textBaseline='middle';
                ctx.fillText(total, cx, cy-6);
                ctx.font = '11px Arial'; ctx.fillStyle='#888';
                ctx.fillText('品項', cx, cy+12);
                ctx.textBaseline='alphabetic';
                // 圖例
                var ly=230, cols=2, itemsPerCol=Math.ceil(byCat.length/cols);
                byCat.forEach(function(item,i){
                    var col=Math.floor(i/itemsPerCol), row=i%itemsPerCol;
                    var lx=10+col*130, lyy=ly+row*16;
                    ctx.fillStyle=item.color||'#888'; ctx.fillRect(lx,lyy-9,10,10);
                    ctx.fillStyle='#444'; ctx.font='11px Arial'; ctx.textAlign='left';
                    var lbl=((item.label||'未分類').length>9?(item.label).substring(0,9)+'…':item.label)+'('+item.cnt+')';
                    ctx.fillText(lbl, lx+13, lyy);
                });
            }
        } else {
            ctx.font=FONT; ctx.fillStyle='#bbb'; ctx.textAlign='center'; ctx.textBaseline='middle';
            ctx.fillText('暫無種類資料', cW/2, cH/2);
        }
    }

    // ── 2. 客戶橫條圖 ─────────────────────────────
    // ── 2. 客戶橫條圖（委由 _drawCliChart 處理，支援模式切換）──
    var byCli = r.by_client || [];
    // 排序按鈕狀態初始化
    switchCliMode(G_cliMode);
    _drawCliChart(byCli);

    // ── 3. 趨勢折線圖 ─────────────────────────────
    var trend=r.trend||[], trendB=r.trend_b||[];
    var trendEl=document.getElementById('chart-trend');
    if(trendEl){
        var tw=trendEl.parentElement?Math.max(300,trendEl.parentElement.offsetWidth-28):660;
        var cssH=190, pL=48, pR=20, pT=20, pB=45;
        var ctx3=_setupHiDPICanvas(trendEl,tw,cssH);
        ctx3.clearRect(0,0,tw,cssH);
        if(trend.length || trendB.length){
            var dates=[];
            trend.forEach(function(x){if(dates.indexOf(x.txn_date)<0)dates.push(x.txn_date);});
            trendB.forEach(function(x){if(dates.indexOf(x.txn_date)<0)dates.push(x.txn_date);});
            dates.sort();
            var chartW=tw-pL-pR, chartH=cssH-pT-pB;
            var dStep=dates.length>1?(chartW/(dates.length-1)):chartW;

            function getVals(data){
                var inD=[],outD=[];
                dates.forEach(function(d){
                    var i=data.find(function(x){return x.txn_date===d&&x.txn_type==='in';});
                    var o=data.find(function(x){return x.txn_date===d&&x.txn_type==='out';});
                    inD.push(i?parseFloat(i.tq||0):0);
                    outD.push(o?parseFloat(o.tq||0):0);
                });
                return {inD:inD,outD:outD};
            }
            var vA=getVals(trend), vB=trendB.length?getVals(trendB):null;
            var allVals=vA.inD.concat(vA.outD);
            if(vB) allVals=allVals.concat(vB.inD,vB.outD);
            var maxVal=Math.max.apply(null,allVals)||1;

            // 格線 + Y 軸刻度
            ctx3.strokeStyle='#f0f0f0'; ctx3.lineWidth=1;
            for(var g=0;g<=4;g++){
                var gy=pT+chartH*(g/4);
                ctx3.beginPath(); ctx3.moveTo(pL,gy); ctx3.lineTo(tw-pR,gy); ctx3.stroke();
                var label=Math.round(maxVal*(1-g/4));
                ctx3.font='10px Arial'; ctx3.fillStyle='#aaa'; ctx3.textAlign='right'; ctx3.textBaseline='middle';
                ctx3.fillText(label, pL-4, gy);
            }
            // 軸線
            ctx3.strokeStyle='#ddd'; ctx3.lineWidth=1;
            ctx3.beginPath(); ctx3.moveTo(pL,pT); ctx3.lineTo(pL,pT+chartH); ctx3.lineTo(tw-pR,pT+chartH); ctx3.stroke();

            function drawLine(vals,color,dash,dotted){
                if(!vals.length) return;
                ctx3.beginPath();
                ctx3.strokeStyle=color; ctx3.lineWidth=dotted?1.5:2;
                if(dash) ctx3.setLineDash(dash); else ctx3.setLineDash([]);
                vals.forEach(function(v,i){
                    var x=pL+i*dStep;
                    var y=pT+chartH-(v/maxVal)*chartH;
                    if(i===0) ctx3.moveTo(x,y); else ctx3.lineTo(x,y);
                });
                ctx3.stroke();
                // 小圓點
                if(!dotted){
                    vals.forEach(function(v,i){
                        var x=pL+i*dStep, y=pT+chartH-(v/maxVal)*chartH;
                        ctx3.beginPath(); ctx3.arc(x,y,3,0,2*Math.PI);
                        ctx3.fillStyle=color; ctx3.fill();
                    });
                }
                ctx3.setLineDash([]);
            }
            drawLine(vA.inD,'#1ABB9C',null,false);
            drawLine(vA.outD,'#E74C3C',null,false);
            if(vB){drawLine(vB.inD,'#2980B9',[5,4],true); drawLine(vB.outD,'#8E44AD',[5,4],true);}

            // X 軸日期標籤
            var maxLabels=7, lstep=Math.ceil(dates.length/maxLabels);
            ctx3.font='10px Arial'; ctx3.fillStyle='#888'; ctx3.textAlign='center'; ctx3.textBaseline='top';
            dates.forEach(function(d,i){
                if(i%lstep===0||i===dates.length-1){
                    ctx3.fillText(d.length>7?d.substring(5):d, pL+i*dStep, pT+chartH+6);
                }
            });

            // 圖例
            var legends=[{c:'#1ABB9C',t:'入庫(A)'},{c:'#E74C3C',t:'出庫(A)'}];
            if(vB){legends.push({c:'#2980B9',t:'入庫(B)'});legends.push({c:'#8E44AD',t:'出庫(B)'});}
            var lx=pL, legendY=cssH-12;
            legends.forEach(function(lg){
                ctx3.fillStyle=lg.c; ctx3.fillRect(lx,legendY-8,12,8);
                ctx3.fillStyle='#555'; ctx3.font='10px Arial'; ctx3.textAlign='left'; ctx3.textBaseline='alphabetic';
                ctx3.fillText(lg.t, lx+15, legendY);
                lx+=55;
            });
        } else {
            ctx3.font=FONT; ctx3.fillStyle='#bbb'; ctx3.textAlign='center'; ctx3.textBaseline='middle';
            ctx3.fillText('此期間無異動記錄', tw/2, cssH/2);
        }
    }

    // ── 4. 庫齡分析（HTML）──────────────────────────
    var aging=r.aging||[];
    var agOrder=['30天內','31~90天','91~180天','180天以上','未知'];
    var agColors=['#1ABB9C','#F39C12','#E67E22','#E74C3C','#bbb'];
    var aTot=aging.reduce(function(s,x){return s+parseInt(x.cnt||0);},0);
    var ah='';
    if(aging.length && aTot>0){
        agOrder.forEach(function(label,i){
            var row=aging.find(function(x){return x.age_group===label;}); if(!row) return;
            var cnt=parseInt(row.cnt||0), pct=aTot>0?Math.round(cnt/aTot*100):0;
            ah+='<div style="margin-bottom:10px;cursor:pointer;" onclick="openAgingDetail(\''+label+'\')" title="點擊查看「'+label+'」個別品項停滯日數">'
              +'<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">'
              +'<span>'+label+' <i class="fa fa-search" style="color:#ccc;font-size:10px;"></i></span><span style="color:'+agColors[i]+';font-weight:700;">'+cnt+'筆 ('+pct+'%)</span></div>'
              +'<div style="background:#eee;border-radius:3px;height:6px;">'
              +'<div style="width:'+pct+'%;background:'+agColors[i]+';border-radius:3px;height:100%;"></div></div></div>';
        });
    } else {
        ah='<div class="text-muted text-center" style="font-size:12px;padding:20px;">暫無庫齡資料<br><small>請確認入庫日期是否已設定</small></div>';
    }
    $('#aging-list').html(ah);
}
// ── 庫齡明細清單 ────────────────────────────────
var G_agingRows=[], G_agingPage=1, G_agingPS=10, G_agingGroup='';
var G_agingColors={'30天內':'#1ABB9C','31~90天':'#F39C12','91~180天':'#E67E22','180天以上':'#E74C3C','未知':'#bbb'};
function openAgingDetail(group){
    G_agingGroup=group||'';
    $('#aging-group-filter').val(G_agingGroup);
    $('#agingDetailModal').modal('show');
    _loadAgingDetail();
}
function _loadAgingDetail(){
    $('#aging-detail-body').html('<div class="text-center text-muted" style="padding:30px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>');
    $('#aging-detail-summary').text(''); $('#aging-pager').html('');
    ajx({action:'get_aging_detail',age_group:G_agingGroup},function(r){
        if(!r.success){ $('#aging-detail-body').html('<div class="text-danger text-center" style="padding:20px;">載入失敗：'+esc(r.message||'')+'</div>'); return; }
        G_agingRows=r.data||[]; G_agingPage=1;
        _renderAgingDetail();
    });
}
function _agingPageTo(p){ G_agingPage=p; _renderAgingDetail(); }
function _renderAgingDetail(){
    var rows=G_agingRows, total=rows.length;
    if(!total){
        $('#aging-detail-body').html('<div class="text-muted text-center" style="padding:30px;font-size:13px;">此區間無庫存資料</div>');
        $('#aging-detail-summary').text('共 0 筆'); $('#aging-pager').html('');
        return;
    }
    var pages=Math.max(1,Math.ceil(total/G_agingPS));
    if(G_agingPage>pages) G_agingPage=pages;
    var start=(G_agingPage-1)*G_agingPS, pageRows=rows.slice(start,start+G_agingPS);
    var totQty=rows.reduce(function(s,x){return s+(parseInt(x.qty)||0);},0);
    var avgDays=0, dCnt=0;
    rows.forEach(function(x){ if(x.idle_days!==null && x.idle_days!==undefined){ avgDays+=parseInt(x.idle_days); dCnt++; } });
    avgDays=dCnt>0?Math.round(avgDays/dCnt):0;
    $('#aging-detail-summary').text('共 '+total+' 筆、總量 '+totQty+'、平均停滯 '+avgDays+' 天');
    var h='<table class="table table-striped table-hover" style="font-size:12px;margin-bottom:0;">'
      +'<thead><tr style="background:#f8f8f8;"><th style="width:36px;">#</th><th>料號</th><th>類別</th><th>客戶</th><th>儲位</th><th class="text-right">數量</th><th>入庫日期</th><th class="text-right">停滯日數</th><th>庫齡區間</th></tr></thead><tbody>';
    pageRows.forEach(function(x,i){
        var gc=G_agingColors[x.age_group]||'#bbb';
        var days=(x.idle_days===null||x.idle_days===undefined)?'-':x.idle_days;
        h+='<tr><td style="color:#aaa;">'+(start+i+1)+'</td>'
          +'<td><strong>'+esc(x.d_id||'')+'</strong></td>'
          +'<td>'+esc(x.category_name||'-')+'</td>'
          +'<td>'+esc(x.client_name||'-')+'</td>'
          +'<td>'+esc(x.storage_location||'-')+'</td>'
          +'<td class="text-right">'+(x.qty||0)+'</td>'
          +'<td>'+esc(x.stock_date||'未設定')+'</td>'
          +'<td class="text-right" style="font-weight:700;color:'+gc+';">'+days+'</td>'
          +'<td><span style="font-size:11px;background:'+gc+'22;color:'+gc+';padding:2px 8px;border-radius:20px;font-weight:700;">'+esc(x.age_group||'')+'</span></td></tr>';
    });
    h+='</tbody></table>';
    $('#aging-detail-body').html(h);
    // 分頁鈕
    var ph='';
    if(pages>1){
        ph+='<button class="btn btn-xs btn-default" '+(G_agingPage<=1?'disabled':'')+' onclick="_agingPageTo('+(G_agingPage-1)+')">&laquo;</button> ';
        ph+='<span style="font-size:12px;">'+G_agingPage+' / '+pages+'</span> ';
        ph+='<button class="btn btn-xs btn-default" '+(G_agingPage>=pages?'disabled':'')+' onclick="_agingPageTo('+(G_agingPage+1)+')">&raquo;</button>';
    }
    $('#aging-pager').html(ph);
}
function printAgingDetail(){
    var rows=G_agingRows;
    if(!rows.length){ toast('無資料可列印','error'); return; }
    var gLabel=G_agingGroup||'全部';
    var totQty=rows.reduce(function(s,x){return s+(parseInt(x.qty)||0);},0);
    var w=window.open('','_blank','width=900,height=700');
    var h='<!DOCTYPE html><html><head><meta charset="utf-8"><title>庫齡明細清單</title>'
      +'<style>body{font-family:Arial,"Microsoft JhengHei",sans-serif;margin:20px;}h2{color:#2c3e50;border-bottom:2px solid #9B59B6;padding-bottom:8px;font-size:20px;}'
      +'table{width:100%;border-collapse:collapse;font-size:11px;}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;}'
      +'th{background:#f0f0f0;}td.num,th.num{text-align:right;}thead{display:table-header-group;}tr{page-break-inside:avoid;}'
      +'@media print{.no-print{display:none;}}'
      +'</style></head><body>'
      +'<h2>庫齡明細清單（個別品項停滯日數）</h2>'
      +'<p style="color:#888;font-size:12px;">產出時間：'+new Date().toLocaleString('zh-TW')+'&nbsp;&nbsp;庫齡區間：'+gLabel+'&nbsp;&nbsp;共 '+rows.length+' 筆、總量 '+totQty+'</p>'
      +'<table><thead><tr><th>#</th><th>料號</th><th>類別</th><th>客戶</th><th>儲位</th><th class="num">數量</th><th>入庫日期</th><th class="num">停滯日數</th><th>庫齡區間</th></tr></thead><tbody>';
    rows.forEach(function(x,i){
        var days=(x.idle_days===null||x.idle_days===undefined)?'-':x.idle_days;
        h+='<tr><td>'+(i+1)+'</td><td>'+esc(x.d_id||'')+'</td><td>'+esc(x.category_name||'-')+'</td><td>'+esc(x.client_name||'-')+'</td><td>'+esc(x.storage_location||'-')+'</td>'
          +'<td class="num">'+(x.qty||0)+'</td><td>'+esc(x.stock_date||'未設定')+'</td><td class="num"><strong>'+days+'</strong></td><td>'+esc(x.age_group||'')+'</td></tr>';
    });
    h+='</tbody></table>'
      +'<div class="no-print" style="margin-top:20px;text-align:center;"><button onclick="window.print()" style="padding:10px 30px;background:#9B59B6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;">🖨️ 列印 / 儲存PDF</button></div>'
      +'</body></html>';
    w.document.write(h);
    w.document.close();
    w.onload=function(){ setTimeout(function(){ w.focus(); },300); };
}
function exportAgingCSV(){
    var rows=G_agingRows;
    if(!rows.length){ toast('無資料可匯出','error'); return; }
    var lines=['#,料號,類別,客戶,儲位,數量,入庫日期,停滯日數,庫齡區間'];
    rows.forEach(function(x,i){
        var days=(x.idle_days===null||x.idle_days===undefined)?'':x.idle_days;
        var vals=[i+1,x.d_id||'',x.category_name||'',x.client_name||'',x.storage_location||'',x.qty||0,x.stock_date||'',days,x.age_group||''];
        lines.push(vals.map(function(v){ v=String(v); return (v.indexOf(',')>=0||v.indexOf('"')>=0)?'"'+v.replace(/"/g,'""')+'"':v; }).join(','));
    });
    var blob=new Blob(['﻿'+lines.join('\r\n')],{type:'text/csv;charset=utf-8;'});
    var a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download='庫齡明細_'+(G_agingGroup||'全部')+'_'+new Date().toISOString().split('T')[0]+'.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}
// ── 盤點 ────────────────────────────────────────
function loadCountSessions(){
    ajx({action:'get_count_sessions'},function(r){
        if(!r.success){$('#count-sessions').html('<div class="text-danger text-center">載入失敗</div>');return;}
        if(!r.data.length){$('#count-sessions').html('<div class="empty" style="padding:20px;"><i class="fa fa-inbox"></i><div style="font-size:12px;">尚無批次</div></div>');return;}
        var smap={draft:'草稿',in_progress:'進行中',completed:'已完成',closed:'已結案'};
        var scol={draft:'#888',in_progress:'#F39C12',completed:'#1ABB9C',closed:'#3498DB'};
        var h=''; r.data.forEach(function(s){
            var pct=s.total_items>0?Math.round(s.counted_items/s.total_items*100):0;
            h+='<div class="sess-card'+(G.currentCountSession==s.session_id?' active':'')+'" id="sess-'+s.session_id+'">'
              +'<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">'
              +'<div style="cursor:pointer;flex:1;" onclick="loadCountDetails('+s.session_id+')">'
              +'<strong style="font-size:13px;">'+esc(s.session_name)+'</strong><br>'
              +'<span style="font-size:11px;color:#888;">'+esc(s.count_date||'')+'</span>'
              +'</div>'
              +'<div style="display:flex;gap:4px;align-items:center;">'
              +'<span style="font-size:11px;background:'+scol[s.status]+'22;color:'+scol[s.status]+';padding:2px 8px;border-radius:20px;font-weight:700;">'+smap[s.status]+'</span>'
              +'<button class="btn btn-xs btn-danger" onclick="deleteCountSession('+s.session_id+',\''+esc(s.session_name)+'\')" title="刪除批次"><i class="fa fa-trash"></i></button>'
              +'</div></div>'
              +'<div style="background:#eee;border-radius:3px;height:4px;margin-top:4px;"><div style="width:'+pct+'%;background:var(--accent);border-radius:3px;height:100%;"></div></div>'
              +'<div style="font-size:11px;color:#888;margin-top:2px;">'+s.counted_items+'/'+s.total_items+' ('+pct+'%)</div>'
              +'</div>';
        });
        $('#count-sessions').html(h);
    });
}
function deleteCountSession(sid,name){
    if(!confirm('確定刪除盤點批次「'+name+'」？此操作不可復原，盤點明細也會一併刪除。')) return;
    ajx({action:'delete_count_session',session_id:sid},function(r){
        if(!r.success){toast(r.message||'刪除失敗','error');return;}
        toast('已刪除批次','success');
        if(G.currentCountSession==sid){ G.currentCountSession=null; $('#count-detail-panel').html('<div class="empty"><i class="fa fa-hand-pointer-o"></i><div style="font-size:13px;">請選擇批次</div></div>'); }
        loadCountSessions();
    });
}
function openCountModal(){ var t=new Date().toISOString().split('T')[0]; $('#count-name').val(''); $('#count-date').val(t); $('#count-remark').val(''); $('#countModal').modal('show'); }
function createCountSession(){
    var name=$('#count-name').val().trim(); if(!name){toast('名稱必填','error');return;}
    ajx({action:'create_count_session',session_name:name,count_date:$('#count-date').val(),remark:$('#count-remark').val().trim()},function(r){
        if(!r.success){toast(r.message,'error');return;}
        $('#countModal').modal('hide'); toast('盤點批次建立，共'+r.item_count+'品項','success');
        G.countLoaded=false; loadCountSessions(); loadCountDetails(r.session_id);
    });
}
var G_countSortBy='did';
var G_countRows=[];
var G_countFilterOpts={locs:[],clients:[],areas:[]};

function _applyCountFilter(sid){
    var sort=$('#cd-sort-sel').val()||'did';
    G_countSortBy=sort;
    loadCountDetails(sid, sort,
        $('#cd-loc-filter').val()||'',
        $('#cd-cli-filter').val()||'',
        $('#cd-did-filter').val()||'',
        $('#cd-area-filter').val()||''
    );
}
function _resetCountFilter(sid){
    $('#cd-loc-filter').val(''); $('#cd-cli-filter').val('');
    $('#cd-did-filter').val(''); $('#cd-area-filter').val('');
    G_countSortBy='did';
    loadCountDetails(sid,'did','','','','');
}
// Enter 鍵觸發料號篩選
$(document).on('keypress','#cd-did-filter',function(e){ if(e.which===13){ var sid=G.currentCountSession; if(sid) _applyCountFilter(sid); } });
function loadCountDetails(sid,sortBy,locF,cliF,didF,areaF){
    G.currentCountSession=sid;
    sortBy=sortBy||G_countSortBy||'did';
    G_countSortBy=sortBy;
    loadCountSessions();
    $('#count-detail-panel').html('<div class="text-center text-muted" style="padding:40px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>');
    var params={
        action:'get_count_details',
        session_id:sid,
        sort_by:sortBy,
        location_filter: locF!==undefined ? locF : ($('#cd-loc-filter').val()||''),
        client_filter:   cliF!==undefined ? cliF : ($('#cd-cli-filter').val()||''),
        did_filter:      didF!==undefined ? didF : ($('#cd-did-filter').val()||''),
        area_filter:     areaF!==undefined ? areaF : ($('#cd-area-filter').val()||'')
    };
    ajx(params,function(r){
        if(!r.success){$('#count-detail-panel').html('<div class="text-danger">載入失敗</div>');return;}
        G_countRows=r.data;
        G_countFilterOpts={locs:r.filter_locs||[],clients:r.filter_clients||[],areas:r.filter_areas||[]};
        renderCountDetail(sid,r.data);
    });
}
function renderCountDetail(sid,rows){
    var uncounted=rows.filter(function(x){return x.counted_qty===null;}).length;
    var hasDiff=rows.filter(function(x){return x.counted_qty!==null&&x.diff_qty!==null&&parseFloat(x.diff_qty)!==0;}).length;
    var notAdj=rows.filter(function(x){return x.counted_qty!==null&&!parseInt(x.is_adjusted);}).length;
    var opts=G_countFilterOpts||{locs:[],clients:[],areas:[]};

    // 記住目前篩選值
    var curLoc=$('#cd-loc-filter').val()||'';
    var curCli=$('#cd-cli-filter').val()||'';
    var curDid=$('#cd-did-filter').val()||'';
    var curArea=$('#cd-area-filter').val()||'';

    var h='';
    // ── 篩選列 ──
    h+='<div style="background:#f8f9fc;border-radius:8px;padding:8px 10px;margin-bottom:8px;">'
     +'<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">'
     +'<span style="font-size:12px;color:#888;font-weight:600;">篩選：</span>'
     // 料號搜尋
     +'<input type="text" id="cd-did-filter" class="form-control" style="width:130px;height:30px;font-size:12px;" placeholder="料號搜尋..." value="'+esc(curDid)+'">'
     // 儲位下拉（動態：只顯示本批次有的）
     +'<select id="cd-loc-filter" class="form-control" style="width:110px;height:30px;font-size:12px;">'
     +'<option value="">全部儲位</option>'
     +opts.locs.map(function(l){return'<option value="'+esc(l)+'"'+(curLoc===l?' selected':'')+'>'+esc(l)+'</option>';}).join('')
     +'</select>'
     // 廠區下拉（動態：只顯示本批次有的）
     +'<select id="cd-area-filter" class="form-control" style="width:90px;height:30px;font-size:12px;">'
     +'<option value="">全部廠區</option>'
     +opts.areas.map(function(a){return'<option value="'+esc(a)+'"'+(curArea===a?' selected':'')+'>'+esc(a)+'</option>';}).join('')
     +'</select>'
     // 客戶下拉（動態：只顯示本批次有的）
     +'<select id="cd-cli-filter" class="form-control" style="width:110px;height:30px;font-size:12px;">'
     +'<option value="">全部客戶</option>'
     +opts.clients.map(function(c){return'<option value="'+esc(c)+'"'+(curCli===c?' selected':'')+'>'+esc(c)+'</option>';}).join('')
     +'</select>'
     // 排序
     +'<select id="cd-sort-sel" class="form-control" style="width:90px;height:30px;font-size:12px;">'
     +'<option value="did"'+(G_countSortBy==='did'?' selected':'')+'>依料號</option>'
     +'<option value="location"'+(G_countSortBy==='location'?' selected':'')+'>依儲位</option>'
     +'<option value="client"'+(G_countSortBy==='client'?' selected':'')+'>依客戶</option>'
     +'</select>'
     +'<button class="btn btn-sm" style="background:var(--accent);color:#fff;height:30px;font-size:12px;" onclick="_applyCountFilter('+sid+')"><i class="fa fa-search"></i> 篩選</button>'
     +'<button class="btn btn-sm btn-default" style="height:30px;font-size:12px;" onclick="_resetCountFilter('+sid+')">重置</button>'
     +'</div>'
     // 狀態列
     +'<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:6px;">'
     +'<strong style="color:var(--primary);font-size:13px;"><i class="fa fa-clipboard"></i> 盤點明細</strong>'
     +'<span style="font-size:12px;background:#fef3e2;color:#a06000;padding:2px 8px;border-radius:20px;">未盤：'+uncounted+'</span>'
     +'<span style="font-size:12px;background:#fde8e8;color:#a52020;padding:2px 8px;border-radius:20px;">差異：'+hasDiff+'</span>'
     +(notAdj>0?'<button class="btn btn-sm" style="background:var(--primary);color:#fff;font-size:12px;height:28px;" onclick="applyAdj('+sid+')"><i class="fa fa-check"></i> 套用調整</button>':'')
     // A 或 CRUD：拆分工具列
     +(G._canCount
         ? '<span id="count-split-toolbar" style="display:none;align-items:center;gap:4px;">'
           +'<span id="count-split-sel-count" style="font-size:11px;color:#888;"></span>'
           +'<button class="btn btn-sm" style="background:var(--accent);color:#fff;height:28px;font-size:12px;" onclick="openSplitFromCount()"><i class="fa fa-scissors"></i> 拆分選取</button>'
           +'<button class="btn btn-sm btn-default" style="height:28px;font-size:12px;" onclick="clearCountSelection()"><i class="fa fa-times"></i> 清除</button>'
           +'</span>'
         : '')
     +'<button class="btn btn-sm btn-default" style="height:28px;font-size:12px;margin-left:auto;" onclick="printCountDetail('+sid+')"><i class="fa fa-print"></i> 列印</button>'
     +'<button class="btn btn-sm btn-default" style="height:28px;font-size:12px;" onclick="openRandomCount('+sid+')"><i class="fa fa-random"></i> 隨機抽盤</button>'
     +'</div></div>';
    // A 或 CRUD：checkbox 欄（供拆分選取）
    var hasChk = G._canCount;
    h+='<div><table class="table tbl-sm" id="count-tbl" style="margin:0;width:100%;">'
     +'<thead><tr>'
     +(hasChk?'<th style="width:26px;"><input type="checkbox" id="count-chk-all" title="全選/取消" onchange="toggleAllCountChk(this)"></th>':'')
     +'<th>料號</th><th>客戶</th><th>種類</th><th>儲位</th><th style="text-align:center;">帳面</th><th style="text-align:center;">實盤</th><th style="text-align:center;">差異</th><th>備註</th><th>操作</th></tr></thead><tbody>';
    rows.forEach(function(row){
        var diff=row.diff_qty!==null?parseFloat(row.diff_qty):null;
        var diffStr=diff===null?'—':diff===0?'<span style="color:var(--accent);">0</span>':'<span style="color:'+(diff>0?'var(--accent)':'var(--danger)')+';font-weight:700;">'+(diff>0?'+':'')+diff+'</span>';
        var cntStr=row.counted_qty!==null?row.counted_qty+'':'<span style="color:#bbb;">未盤</span>';
        var adj=parseInt(row.is_adjusted)?'<span style="font-size:10px;color:var(--accent);">✓</span>':'';
        var locArea=row.loc_area_name;
        var locCode=row.location_code||row.storage_location||'';
        var locDisp=(locArea?'['+esc(locArea)+'] ':'')+esc(locCode||'—');
        var locId=row.location_id||'';
        // checkbox data
        var chkHtml=hasChk
            ? '<td style="text-align:center;"><input type="checkbox" class="count-split-chk"'
              +' data-item-id="'+esc(row.stock_item_id||'')+'"'
              +' data-qty="'+esc(row.system_qty||0)+'"'
              +' data-loc-id="'+esc(locId)+'"'
              +' data-loc-code="'+esc(locCode)+'"'
              +' data-remark="'+esc(row.remark||'')+'"'
              +' onchange="updateCountSplitSelection()"></td>'
            : '';
        h+='<tr id="cr-'+row.detail_id+'">'
          +chkHtml
          +'<td><strong>'+esc(row.d_id)+'</strong></td>'
          +'<td style="font-size:11px;">'+esc(row.client_name||'—')+'</td>'
          +'<td style="font-size:11px;">'+esc(row.category_name||'—')+'</td>'
          +'<td style="font-size:11px;">'+locDisp+'</td>'
          +'<td style="text-align:center;">'+row.system_qty+(row.unit_name?' '+row.unit_name:'')+'</td>'
          +'<td style="text-align:center;" id="cnt-'+row.detail_id+'">'+cntStr+'</td>'
          +'<td style="text-align:center;" id="dif-'+row.detail_id+'">'+diffStr+'</td>'
          +'<td id="rem-'+row.detail_id+'" style="font-size:11px;">'+esc(row.remark||'')+'</td>'
          +'<td>'+adj+(!parseInt(row.is_adjusted)?'<button class="btn btn-xs btn-default" onclick="inputCount('+row.detail_id+','+row.system_qty+')">盤</button>':'')+'</td></tr>';
    });
    h+='</tbody></table></div>';
    $('#count-detail-panel').html(h);
}

// ── 盤點拆分（A 權限）────────────────────────────
function updateCountSplitSelection(){
    var n = $('.count-split-chk:checked').length;
    if(n >= 1){
        $('#count-split-sel-count').text(n + ' 筆已選');
        $('#count-split-toolbar').css('display','flex');
    } else {
        $('#count-split-sel-count').text('');
        $('#count-split-toolbar').hide();
    }
    // 全選框同步
    var total = $('.count-split-chk').length;
    $('#count-chk-all').prop('indeterminate', n > 0 && n < total);
    $('#count-chk-all').prop('checked', n > 0 && n === total);
}
function toggleAllCountChk(el){
    $('.count-split-chk').prop('checked', el.checked);
    updateCountSplitSelection();
}
function clearCountSelection(){
    $('.count-split-chk').prop('checked', false);
    $('#count-chk-all').prop('checked', false);
    updateCountSplitSelection();
}
function openSplitFromCount(){
    if(!G._canCount){ toast('需要 A 或 CRUD 完整權限','error'); return; }
    var checked = $('.count-split-chk:checked');
    if(checked.length < 1){ toast('請至少勾選 1 筆進行拆分','error'); return; }

    // 確認所有勾選筆是同一個 stock_item_id（同一筆庫存才能拆）
    var itemIds = [];
    checked.each(function(){ var id=$(this).data('item-id'); if(itemIds.indexOf(id)<0) itemIds.push(id); });
    if(itemIds.length > 1){
        toast('拆分只能針對同一筆庫存（相同 stock_item_id），目前選取了 '+itemIds.length+' 筆不同庫存，請重新選取','error');
        return;
    }

    var stockItemId = itemIds[0];
    if(!stockItemId){ toast('找不到庫存 ID','error'); return; }

    // 取第一個 checked 的帳面數量（system_qty）作為原始庫存參考
    var firstChk = checked.first();
    var origQty  = parseFloat(firstChk.data('qty') || 0);

    // 先呼叫 get_stock_detail 取得完整資料，再開拆分 Modal
    $('#count-detail-panel').css('opacity','0.6');
    ajx({action:'get_stock_detail', stock_item_id: stockItemId}, function(r){
        $('#count-detail-panel').css('opacity','1');
        if(!r.success){ toast(r.message || '取得庫存資料失敗','error'); return; }
            var latestLocId = null;

        // 暫存到 G._detailData 供 split modal 使用
        G._detailData = r;

        // 清除拆分列
        G_splitRowCount = 0;
        $('#split-rows-wrap').html('');
        $('#split-stock-item-id').val(stockItemId);

        var d = r.data;
        var unitStr = (d.unit_symbol || d.unit_name) ? (' ' + esc(d.unit_symbol || d.unit_name)) : '';
        $('#split-orig-info').html(
            '<strong>料號：</strong>' + esc(d.d_id) +
            ' &nbsp;|&nbsp; <strong>原始庫存：</strong><strong style="color:var(--primary);">' + parseFloat(d.qty) + unitStr + '</strong>' +
            ' &nbsp;|&nbsp; <strong>原儲位：</strong>' + esc(d.location_code || d.storage_location || '—')
        );

        // 根據勾選的盤點列預填（用 data-loc-id / data-loc-code / data-qty / data-remark）
            checked.each(function(i){
            var locId   = $(this).data('loc-id') || '';
                if (i === 0 && locId) latestLocId = locId;
            var locCode = $(this).data('loc-code') || '';
            var qty     = Math.abs(parseFloat($(this).data('qty') || 0));
            var remark  = $(this).data('remark') || '';
            addSplitRow(locCode, qty, remark, locId);
        });

            // 自動選取最新紀錄的儲位
            if (latestLocId) $('#split-target-loc-id').val(latestLocId).trigger('change');

        updateSplitQtySummary();
        setTimeout(function(){ $('#splitTxnModal').modal('show'); }, 100);
    });
}
function printCountDetail(sid){
    var rows=G_countRows; if(!rows||!rows.length){toast('無資料可列印','error');return;}
    var w=window.open('','_blank','width=900,height=700');
    var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>盤點明細</title>'
        +'<style>body{font-family:Arial,sans-serif;font-size:12px;margin:20px;}table{width:100%;border-collapse:collapse;}th,td{border:1px solid #999;padding:5px 8px;text-align:left;}th{background:#f0f0f0;font-weight:bold;}tr:nth-child(even){background:#fafafa;}.sig{margin-top:40px;display:flex;gap:60px;}.sig div{border-top:1px solid #000;padding-top:4px;min-width:120px;text-align:center;}</style>'
        +'</head><body>'
        +'<h3 style="margin-bottom:4px;">庫存盤點明細表</h3>'
        +'<div style="font-size:12px;color:#888;margin-bottom:12px;">列印時間：'+new Date().toLocaleString('zh-TW')+'</div>'
        +'<table><thead><tr><th>料號</th><th>客戶</th><th>種類</th><th>儲位</th><th>帳面數量</th><th>實盤數量</th><th>差異</th><th>備註</th></tr></thead><tbody>';
    rows.forEach(function(row){
        var diff=row.diff_qty!==null?parseFloat(row.diff_qty):null;
        var diffStr=diff===null?'':diff===0?'0':(diff>0?'+':'')+diff;
        html+='<tr><td>'+esc(row.d_id)+'</td><td>'+esc(row.client_name||'')+'</td><td>'+esc(row.category_name||'')+'</td><td>'+esc(row.location_code||row.storage_location||'')+'</td><td style="text-align:center;">'+row.system_qty+(row.unit_name?' '+row.unit_name:'')+'</td><td style="text-align:center;">'+(row.counted_qty!==null?row.counted_qty+'':'')+'</td><td style="text-align:center;">'+diffStr+'</td><td>'+esc(row.remark||'')+'</td></tr>';
    });
    html+='</tbody></table><div class="sig"><div>盤點人員</div><div>覆核人員</div><div>主管</div></div></body></html>';
    w.document.write(html); w.document.close(); w.print();
}
// 隨機盤點 Modal
function openRandomCount(sid){
    $('#random-sid').val(sid);
    $('#random-count-panel').html('<div style="padding:10px 0;"><div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:10px;">'
        +'<div><label style="font-size:12px;font-weight:600;">品項種類</label><select id="rc-cat" class="form-control" style="width:120px;"><option value="">全部種類</option>'
        +G.allCats.map(function(c){return'<option value="'+c.category_id+'">'+esc(c.category_name)+'</option>';}).join('')
        +'</select></div>'
        +'<div><label style="font-size:12px;font-weight:600;">抽取數量</label><input id="rc-count" type="number" class="form-control" value="10" min="1" max="200" style="width:80px;"></div>'
        +'<div><label style="font-size:12px;font-weight:600;">篩選條件</label><select id="rc-mode" class="form-control" style="width:130px;" onchange="$(\'#rc-days-wrap\').toggle(this.value!==\'all\')">'
        +'<option value="all">不限</option>'
        +'<option value="recent_active">近N天有異動</option>'
        +'<option value="recent_inactive">近N天無異動</option>'
        +'</select></div>'
        +'<div id="rc-days-wrap" style="display:none;"><label style="font-size:12px;font-weight:600;">天數</label><input id="rc-days" type="number" class="form-control" value="30" min="1" max="365" style="width:80px;"></div>'
        +'<button class="btn btn-sm" style="background:var(--accent);color:#fff;" onclick="doRandomCount('+sid+')"><i class="fa fa-random"></i> 抽取</button>'
        +'</div><div id="rc-result"></div></div>');
    $('#randomCountModal').modal('show');
}
function doRandomCount(sid){
    $('#rc-result').html('<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> 抽取中...</div>');
    ajx({action:'random_count_sample',category_id:$('#rc-cat').val()||0,count:$('#rc-count').val()||10,mode:$('#rc-mode').val(),days:$('#rc-days').val()||30},function(r){
        if(!r.success){$('#rc-result').html('<div class="text-danger">'+esc(r.message)+'</div>');return;}
        if(!r.data.length){$('#rc-result').html('<div class="text-muted text-center">符合條件的品項不足</div>');return;}
        var h='<table class="table tbl-sm" style="margin-top:8px;"><thead><tr><th>料號</th><th>種類</th><th>儲位</th><th>帳面數量</th><th>勾選</th></tr></thead><tbody>';
        r.data.forEach(function(row){
            h+='<tr style="cursor:pointer;" onclick="$(this).find(\'.rc-chk\').prop(\'checked\',!$(this).find(\'.rc-chk\').prop(\'checked\'));$(this).toggleClass(\'rc-selected\',$(this).find(\'.rc-chk\').prop(\'checked\'));" class="rc-selected">'
              +'<td><strong>'+esc(row.d_id)+'</strong></td>'
              +'<td>'+esc(row.category_name||'—')+'</td>'
              +'<td>'+esc(row.storage_location||'—')+'</td>'
              +'<td>'+row.qty+'</td>'
              +'<td style="text-align:center;"><input type="checkbox" class="rc-chk" data-id="'+row.stock_item_id+'" data-did="'+esc(row.d_id)+'" checked onclick="event.stopPropagation();$(this).closest(\'tr\').toggleClass(\'rc-selected\',this.checked);"></td>'
              +'</tr>';
        });
        h+='</tbody></table><button class="btn btn-sm" style="background:var(--primary);color:#fff;" onclick="addRandomToCount('+sid+')"><i class="fa fa-plus"></i> 將勾選項目加入盤點批次</button>';
        $('#rc-result').html(h);
    });
}
function addRandomToCount(sid){
    var selectedIds=[];
    $('.rc-chk:checked').each(function(){ var id=parseInt($(this).data('id')); if(id) selectedIds.push(id); });
    if(!selectedIds.length){toast('請至少勾選一筆','error');return;}
    var name='隨機抽盤-'+new Date().toISOString().split('T')[0];
    if(!confirm('將以 ['+name+'] 建立新盤點批次，包含已勾選的 '+selectedIds.length+' 筆？')){
        $('#randomCountModal').modal('hide'); loadCountDetails(sid); return;
    }
    ajx({action:'create_count_session',session_name:name,count_date:new Date().toISOString().split('T')[0],remark:'隨機抽盤自動建立',item_ids:JSON.stringify(selectedIds)},function(r){
        if(!r.success){toast(r.message||'建立失敗','error');return;}
        $('#randomCountModal').modal('hide');
        toast('已建立盤點批次，共'+r.item_count+'筆','success');
        G.countLoaded=false; loadCountSessions(); loadCountDetails(r.session_id);
    });
}
function inputCount(did, sysQty){
    var v=prompt('實際數量（帳面：'+sysQty+'）：'); if(v===null) return;
    var cnt=parseFloat(v); if(isNaN(cnt)||cnt<0){toast('請輸入有效數量','error');return;}
    var rem=prompt('備註（選填）：')||'';
    ajx({action:'save_count_detail',detail_id:did,counted_qty:cnt,remark:rem},function(r){
        if(!r.success){toast(r.message,'error');return;}
        var diff=r.diff_qty;
        $('#cnt-'+did).html(cnt);
        $('#dif-'+did).html(diff===0?'<span style="color:var(--accent);">0</span>':'<span style="color:'+(diff>0?'var(--accent)':'var(--danger)')+';font-weight:700;">'+(diff>0?'+':'')+diff+'</span>');
        if(rem) $('#rem-'+did).text(rem);
        toast('已記錄','success');
    });
}
function applyAdj(sid){
    if(!confirm('確定依盤點結果調整庫存數量？')) return;
    ajx({action:'apply_count_adjustments',session_id:sid},function(r){
        if(!r.success){toast(r.message,'error');return;}
        toast('已調整'+r.adjusted_count+'筆庫存','success');
        loadCountDetails(sid); loadCountSessions();
        // AJAX 更新庫存列表（不需手動重載）
        loadList(G.page); loadStats();
    });
}

// ── 廠區盤點篩選 ─────────────────────────────────
function filterCountByArea(sid){
    var area=$('#cd-area-filter').val()||'';
    // 從 allLocs 找出此廠區的所有儲位名稱
    if(!area){ loadCountDetails(sid); return; }
    var areaLocs=G.allLocs.filter(function(l){return l.area===area;}).map(function(l){return l.location_code;});
    if(!areaLocs.length){ toast('此廠區沒有儲位','error'); return; }
    // 以第一個儲位篩選（或顯示所有廠區儲位 — 這裡簡化為用廠區名稱過濾 storage_location LIKE）
    ajx({action:'get_count_details',session_id:sid,sort_by:G_countSortBy||'did',location_filter:area,client_filter:''},function(r){
        if(!r.success) return;
        G_countRows=r.data; renderCountDetail(sid,r.data);
    });
}

// ── 建立料號 ─────────────────────────────────────
function openCreateDIdModal(preFill){
    $('#new-did-code').val(preFill||'');
    $('#new-did-spec').val('');
    $('#new-did-remark').val('');
    $('#new-did-client-name').val(''); $('#new-did-client-id').val('');
    $('#ac-new-did-client').hide();
    $('#create-did-msg').hide();
    $('#createDIdModal').modal('show');
}
// 客戶搜尋 AC（建立料號用）
$(document).on('input','#new-did-client-name',function(){
    var t=$(this).val().trim(); if(!t){$('#ac-new-did-client').hide();return;}
    ajx({action:'search_customer',term:t},function(r){
        if(!r.success||!r.data.length){$('#ac-new-did-client').hide();return;}
        var h=''; r.data.forEach(function(c){ h+='<div class="ac-item" data-cid="'+esc(c.customer_id)+'" data-cname="'+esc(c.customer)+'"><strong>'+esc(c.customer)+'</strong><span class="sub"> '+esc(c.customer_id)+'</span></div>'; });
        $('#ac-new-did-client').html(h).show();
    });
});
$(document).on('click','#ac-new-did-client .ac-item',function(){
    $('#new-did-client-name').val($(this).data('cname'));
    $('#new-did-client-id').val($(this).data('cid'));
    $('#ac-new-did-client').hide();
});
function confirmCreateDId(){
    var code=($('#new-did-code').val()||'').trim();
    if(!code){toast('料號為必填','error'); $('#new-did-code').focus(); return;}
    var cid=($('#new-did-client-id').val()||'').trim();
    var cname=($('#new-did-client-name').val()||'').trim();
    if(!cid&&!cname){toast('客戶為必填，請搜尋並選擇客戶','error'); $('#new-did-client-name').focus(); return;}
    ajx({action:'create_d_id',D_Setting_Id:code,Spec_No:($('#new-did-spec').val()||'').trim(),Remark:($('#new-did-remark').val()||'').trim(),Customer_Id:cid},function(r){
        if(!r.success){
            $('#create-did-msg').html('<div class="alert alert-danger" style="font-size:12px;">'+esc(r.message)+'</div>').show();
            return;
        }
        $('#createDIdModal').modal('hide');
        toast('料號 '+code+' 建立成功','success');
        $('#item-did').val(r.D_Setting_Id); $('#item-dsid').val(r.d_id);
        if(r.client_name){ $('#item-client').val(r.client_name); $('#item-cid').val(r.client_id||''); }
        $('#item-unit-manage-wrap').show(); G.currentItemDsid=r.d_id;
    });
}

// ── 圖表圖例修正（Chart.js v2）─────────────────────
// loadAnalysis 中的種類圓餅圖，v2 的 legend 已內建顯示，不需額外處理
// 只需確保 legend.display=true（預設）

// ── 入/出庫數量 step 依單位小數位數動態調整 ─────
function applyUnitStep(unitId, inputId){
    if(!unitId){
        // 無單位時維持預設 step
        $('#'+inputId).attr('step','0.001').attr('min', inputId==='item-qty'?'0':'0.001');
        return;
    }
    var u=G.allUnits.find(function(x){return x.unit_id==unitId;});
    if(!u){ return; }
    var dp=parseInt(u.decimal_places!==undefined&&u.decimal_places!==null?u.decimal_places:3);
    var step=dp===0?'1':('0.'+'0'.repeat(dp-1)+'1');
    var min=inputId==='item-qty'?'0':step;
    $('#'+inputId).attr('step',step).attr('min',min);
    // 修正現有值至合法小數位
    var curVal=$('#'+inputId).val();
    if(curVal!==''&&dp===0){
        var intVal=Math.round(parseFloat(curVal)||0);
        $('#'+inputId).val(intVal);
    }
}
// 入庫/出庫單位變更時調整 step
$('#in-unit').on('change',function(){ applyUnitStep($(this).val(),'in-qty'); calcInConvert(); });
$('#out-unit').on('change',function(){ applyUnitStep($(this).val(),'out-qty'); calcOutConvert(); });

// ── 強制刪除庫存（A權限）────────────────────────
function openPurge(id, did){
    if(!G._isAdminUser){toast('需要 A 級權限','error');return;}
    if(!confirm('⚠️ 第一次確認\n\n確定要永久刪除料號「'+did+'」的庫存記錄？\n\n此操作不可復原，將刪除所有異動歷程！')){return;}
    $('#purge-item-id').val(id);
    $('#purge-item-info').html('<strong>料號：</strong>'+esc(did)+'<br><span style="color:#c0392b;">此筆所有庫存記錄、異動歷程、盤點明細將永久刪除</span>');
    $('#purge-confirm-text').val('');
    $('#purgeModal').modal('show');
}
function confirmPurge(){
    var txt=($('#purge-confirm-text').val()||'').trim();
    if(txt!=='Y'){toast('請輸入大寫 Y 確認','error'); $('#purge-confirm-text').focus(); return;}
    var id=$('#purge-item-id').val();
    ajx({action:'purge_stock_item',stock_item_id:id,confirm_text:'Y'},function(r){
        if(!r.success){toast(r.message||'刪除失敗','error');return;}
        $('#purgeModal').modal('hide');
        toast('已永久刪除','success');
        loadList(G.page); loadStats();
    });
}

// ── 料號自動完成：查無時提示建立 ─────────────────
// 覆蓋原本的 #item-did input 事件，在查無時多加建立按鈕
$('#item-did').off('input').on('input',function(){
    var t=$(this).val().trim(); if(t.length<1){$('#ac-did').hide();return;}
    ajx({action:'search_d_id',term:t},function(r){
        if(!r.success){$('#ac-did').hide();return;}
        var h='';
        if(r.data&&r.data.length){
            r.data.forEach(function(d){ h+='<div class="ac-item" data-did="'+esc(d.D_Setting_Id)+'" data-dsid="'+d.d_id+'" data-cname="'+esc(d.client_name||'')+'" data-cid="'+esc(d.Customer_Id||'')+'"><strong>'+esc(d.D_Setting_Id)+'</strong>'+(d.Spec_No?'<span class="sub"> '+esc(d.Spec_No)+'</span>':'')+(d.client_name?'<span class="sub"> — '+esc(d.client_name)+'</span>':'')+'</div>'; });
        } else {
            h='<div class="ac-item" style="color:var(--warn);" onclick="openCreateDIdModal(\''+esc(t)+'\');$(\'#ac-did\').hide();"><i class="fa fa-plus-circle"></i> 找不到料號「'+esc(t)+'」，點此建立新料號</div>';
        }
        $('#ac-did').html(h).show();
    });
});

// ── 組合件 Modal ─────────────────────────────────
var G_grpItems = [];
var G_grpItemCount = 0;

function openGroupModal(){
    G_grpItems = [];
    G_grpItemCount = 0;
    $('#grp-remark').val('');
    $('#grp-unit-price').val('');
    $('#grp-sets').val('1');
    $('#grp-sd').val(new Date().toISOString().split('T')[0]);
    // 重置組合名稱搜尋與客戶欄位狀態
    $('#grp-name').val('');
    $('#grp-customer-name').val('').prop('readonly', false).css('background', '');
    $('#grp-customer-id').val('');
    $('#grp-bom-shared-sel').html('<option value="">— 請先選定組合名稱 —</option>');
    $('#grp-order-sel').html('<option value="">— 請先選定組合名稱 —</option>');
    $('#grp-customer-name').val('');
    $('#grp-customer-id').val('');
    $('#grp-customer-ac').hide();
    grpOrderClear();
    $('#grp-order-year').val('');
    $('#grp-order-month').val('');
    // 填充種類下拉
    var ch='<option value="">— 選擇 —</option>';
    G.allCats.forEach(function(c){ ch+='<option value="'+c.category_id+'">'+esc(c.category_name)+'</option>'; });
    $('#grp-cat').html(ch);
    $('#grp-loc').html('<option value="">— 請先選種類 —</option>').prop('disabled',true);
    $('#grp-loc-hint').text('');
    $('#grp-items-wrap').html('');
    addGrpItem();
    addGrpItem();
    $('#groupModal').modal('show');
}

function onGrpCatChange(){
    var cid=$('#grp-cat').val();
    if(!cid){ $('#grp-loc').html('<option value="">— 請先選種類 —</option>').prop('disabled',true); return; }
    $('#grp-loc').html('<option value="">— 載入中 —</option>').prop('disabled',true);
    ajx({action:'get_locations',category_id:cid},function(r){
        $('#grp-loc').prop('disabled',false);
        if(!r.success||!r.data.length){ $('#grp-loc').html('<option value="">— 此種類無可用儲位 —</option>'); return; }
        var grouped={};
        r.data.forEach(function(l){
            var area=l.area_name||l.area||'（未分廠區）';
            if(!grouped[area]) grouped[area]=[];
            grouped[area].push(l);
        });
        var h='<option value="">— 選擇儲位 —</option>';
        var areaKeys=Object.keys(grouped).sort(function(a,b){
            var ao=99999,bo=99999;
            G.allAreas.forEach(function(x){ if(x.area_name===a) ao=parseInt(x.sort_order)||0; if(x.area_name===b) bo=parseInt(x.sort_order)||0; });
            return ao-bo;
        });
        areaKeys.forEach(function(area){
            grouped[area].forEach(function(l){
                h+='<option value="'+l.location_id+'" data-code="'+esc(l.location_code)+'">['+(area)+'] '+esc(l.location_code)+(l.location_name?' - '+esc(l.location_name):'')+'</option>';
            });
        });
        $('#grp-loc').html(h);
        $('#grp-loc-hint').text('共 '+r.data.length+' 個可用儲位');
    });
}

function addGrpItem(){
    G_grpItemCount++;
    var idx = G_grpItemCount;
    var wrap = document.getElementById('grp-items-wrap');
    var div = document.createElement('div');
    div.id = 'grp-item-'+idx;
    div.style.cssText = 'background:#fff;border:1px solid #e0e4ea;border-radius:6px;padding:10px 12px;margin-bottom:8px;position:relative;';
    div.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
        +'<span style="font-size:12px;font-weight:700;color:var(--primary);">料號 #'+idx+'</span>'
        +'<button type="button" class="btn btn-xs btn-default" onclick="removeGrpItem('+idx+')" style="color:#c0392b;"><i class="fa fa-times"></i></button>'
        +'</div>'
        +'<div class="row">'
        +  '<div class="col-sm-3" style="position:relative;">'
        +    '<label style="font-size:12px;">料號 <span style="color:red;">*</span></label>'
        +    '<input type="text" id="gi-did-'+idx+'" class="form-control" placeholder="搜尋料號..." autocomplete="off" oninput="grpDIdAc('+idx+')">'
        +    '<input type="hidden" id="gi-dsid-'+idx+'">'
        +    '<input type="hidden" id="gi-did-val-'+idx+'">' 
        +    '<input type="hidden" id="gi-cname-'+idx+'">'
        +    '<input type="hidden" id="gi-cid-'+idx+'">'
        +    '<div class="ac-box" id="gi-ac-'+idx+'" style="display:none;"></div>'
        +  '</div>'
        +  '<div class="col-sm-2">'
        +    '<label style="font-size:12px;">每組含 <span style="color:red;">*</span> <small style="color:#e67e00;">(PCS)</small></label>'
        +    '<input type="number" id="gi-pps-'+idx+'" class="form-control" value="1" min="1" step="1" placeholder="幾PCS" title="此料號每組含幾PCS">'
        +  '</div>'
        +  '<div class="col-sm-2">'
        +    '<label style="font-size:12px;">單位</label>'
        +    '<select id="gi-unit-'+idx+'" class="form-control"><option value="">主單位</option></select>'
        +  '</div>'
        +  '<div class="col-sm-2">'
        +    '<label style="font-size:12px;">單筆售價</label>'
        +    '<input type="number" id="gi-price-'+idx+'" class="form-control" step="0.0001" placeholder="選填">'
        +  '</div>'
        +  '<div class="col-sm-3">'
        +    '<label style="font-size:12px;">製令號 (BOM)</label>'
        +    '<select id="gi-bom-'+idx+'" class="form-control gi-bom-sel"><option value="">— 無BOM —</option></select>'
        +  '</div>'
        +'</div>'
        +'<div class="row" style="margin-top:4px;">'
        +  '<div class="col-sm-12">'
        +    '<div id="gi-calc-'+idx+'" style="font-size:11px;color:#856404;background:#fff8e1;padding:3px 8px;border-radius:3px;display:none;"></div>'
        +  '</div>'
        +'</div>';
    wrap.appendChild(div);
    G_grpItems.push(idx);

    // 填入所有單位選項，並預選 unit_id=1 (PCS)
    var uh = '<option value="">主單位</option>';
    G.allUnits.forEach(function(u){ uh += '<option value="'+u.unit_id+'"'+(u.unit_id==1?' selected':'')+'>'+esc(u.unit_name)+(u.unit_symbol&&u.unit_symbol!==u.unit_name?' ('+esc(u.unit_symbol)+')':'')+'</option>'; });
    $('#gi-unit-'+idx).html(uh);

    // 即時計算顯示：每組PCS × 組數 = 入庫量
    $('#gi-pps-'+idx).on('input', function(){ updateGrpItemCalc(idx); });
    $('#grp-sets').off('input.grpcalc').on('input.grpcalc', function(){
        G_grpItems.forEach(function(i){ updateGrpItemCalc(i); });
    });
}
function updateGrpItemCalc(idx){
    var pps = parseInt($('#gi-pps-'+idx).val()||0);
    var sets = parseInt($('#grp-sets').val()||0);
    var el = document.getElementById('gi-calc-'+idx);
    if(!el) return;
    if(pps>0 && sets>0){
        el.textContent = '每組 '+pps+' PCS × '+sets+' 組 = 入庫 '+(pps*sets)+' PCS';
        el.style.display='block';
    } else {
        el.style.display='none';
    }
}

function removeGrpItem(idx){
    var el = document.getElementById('grp-item-'+idx);
    if(el) el.remove();
    G_grpItems = G_grpItems.filter(function(i){return i!==idx;});
}

function grpDIdAc(idx){
    var t = ($('#gi-did-'+idx).val()||'').trim();
    if(t.length<1){ $('#gi-ac-'+idx).hide(); return; }
    ajx({action:'search_d_id',term:t},function(r){
        if(!r.success){ $('#gi-ac-'+idx).hide(); return; }
        if(!r.data||!r.data.length){ $('#gi-ac-'+idx).hide(); return; }
        var h='';
        r.data.forEach(function(d){ h+='<div class="ac-item" data-did="'+esc(d.D_Setting_Id)+'" data-dsid="'+d.d_id+'" data-cname="'+esc(d.client_name||'')+'" data-cid="'+esc(d.Customer_Id||'')+'"><strong>'+esc(d.D_Setting_Id)+'</strong>'+(d.Spec_No?'<span class="sub"> '+esc(d.Spec_No)+'</span>':'')+(d.client_name?'<span class="sub"> — '+esc(d.client_name)+'</span>':'')+'</div>'; });
        $('#gi-ac-'+idx).html(h).show();
        $('#gi-ac-'+idx+' .ac-item').off('click').on('click',function(){
            $('#gi-did-'+idx).val($(this).data('did'));
            $('#gi-dsid-'+idx).val($(this).data('dsid'));
            $('#gi-cname-'+idx).val($(this).data('cname'));
            $('#gi-cid-'+idx).val($(this).data('cid'));
            $('#gi-did-val-'+idx).val($(this).data('did'));
            $('#gi-ac-'+idx).hide();
            var dsid=$(this).data('dsid');
            if(dsid){ 
                loadItemUnitsForSelect(dsid, 'gi-unit-'+idx);
                _loadGrpItemBomSelect(idx, $(this).data('did'));
            }
        });
    });
}

function _loadGrpItemBomSelect(idx, did) {
    ajx({action:'search_bom', term:'', d_id:did}, function(r) {
        var h = '<option value="">— 無BOM —</option>';
        (r.data || []).forEach(function(b) {
            h += '<option value="' + esc(b.bom) + '">' + esc(b.bom) + '</option>';
        });
        $('#gi-bom-' + idx).html(h);
    });
}

function grpNameAc() {
    var t = ($('#grp-name').val() || '').trim();
    var dd = $('#grp-name-ac');
    
    // 輸入時重置狀態
    $('#grp-customer-name').val('').prop('readonly', false).css('background', '');
    $('#grp-customer-id').val('');
    $('#grp-bom-shared-sel').html('<option value="">— 請先選定組合名稱 —</option>');
    $('#grp-order-sel').html('<option value="">— 請先選定組合名稱 —</option>');

    if (t.length < 1) { dd.hide(); return; }
    ajx({action: 'search_d_id', term: t}, function(r) {
        if (!r.success) { dd.hide(); return; }
        var h = '';
        if (r.data && r.data.length) {
            r.data.forEach(function(d) {
                h += '<div class="ac-item" data-did="' + esc(d.D_Setting_Id) + '" data-dsid="' + d.d_id + '" data-cname="' + esc(d.client_name || '') + '" data-cid="' + esc(d.Customer_Id || '') + '"><strong>' + esc(d.D_Setting_Id) + '</strong>' + (d.Spec_No ? '<span class="sub"> ' + esc(d.Spec_No) + '</span>' : '') + (d.client_name ? '<span class="sub"> — ' + esc(d.client_name) + '</span>' : '') + '</div>';
            });
        } else {
            h = '<div class="ac-item" style="color:var(--warn);"><i class="fa fa-info-circle"></i> 查無料號資料，將視為新增</div>';
        }
        dd.html(h).show();
        dd.find('.ac-item').on('click', function() {
            var $t = $(this);
            if ($t.data('dsid')) {
                $('#grp-name').val($t.data('did'));
                $('#grp-customer-name').val($t.data('cname')).prop('readonly', true).css('background', '#f5f5f5');
                $('#grp-customer-id').val($t.data('cid'));
                
                _loadGrpSharedBomSelect($t.data('did'));
                _loadGrpSharedOrderSelect($t.data('did'));
            }
            dd.hide();
        });
    });
}

function _loadGrpSharedBomSelect(did) {
    ajx({action: 'search_bom', term: '', d_id: did}, function(r) {
        var h = '<option value="">— 無BOM —</option>';
        (r.data || []).forEach(function(b) {
            h += '<option value="' + esc(b.bom) + '">' + esc(b.bom) + '</option>';
        });
        $('#grp-bom-shared-sel').html(h);
    });
}

function _loadGrpSharedOrderSelect(did) {
    ajx({action: 'search_order', term: '', d_id: did}, function(r) {
        var h = '<option value="">— 無訂單 —</option>';
        (r.data || []).forEach(function(o) {
            h += '<option value="' + esc(o.Order_oo) + '" data-price="' + (o.unit_price || 0) + '">' + esc(o.Order_oo) + '</option>';
        });
        $('#grp-order-sel').html(h);
    });
}

function applyGrpShared(){
    var bom = $('#grp-bom-shared-sel').val();
    if(!bom){ toast('共用 BOM 欄位為空，未套用','error'); return; }
    G_grpItems.forEach(function(idx){
        var el = document.getElementById('grp-item-'+idx);
        if(!el) return;
        var $childSel = $('#gi-bom-' + idx);
        if ($childSel.find('option[value="' + bom + '"]').length) {
            $childSel.val(bom);
        } else {
            $childSel.append(new Option(bom, bom)).val(bom);
        }
    });
    toast('已套用 BOM 到全部子料號','success');
}

// 客戶搜尋（新增組合件 modal）
function grpCustomerAc(){
    var t = ($('#grp-customer-name').val()||'').trim();
    if (!$('#grp-customer-name').prop('readonly')) $('#grp-customer-id').val(''); 
    if(t.length < 1){ $('#grp-customer-ac').hide(); return; }
    ajx({action:'search_customer', term:t}, function(r){
        if(!r.success||!r.data||!r.data.length){ $('#grp-customer-ac').hide(); return; }
        var h='';
        r.data.forEach(function(c){ h+='<div class="ac-item" data-cid="'+esc(c.customer_id)+'" data-cname="'+esc(c.customer)+'"><strong>'+esc(c.customer_id)+'</strong> <span class="sub">'+esc(c.customer)+'</span></div>'; });
        $('#grp-customer-ac').html(h).show();
        $('#grp-customer-ac .ac-item').off('click').on('click', function(){
            $('#grp-customer-name').val($(this).data('cname'));
            $('#grp-customer-id').val($(this).data('cid'));
            $('#grp-customer-ac').hide();
        });
    });
}

// 客戶搜尋（合併為組合件 modal）
function m2gCustomerAc(){
    var t = ($('#m2g-customer-name').val()||'').trim();
    $('#m2g-customer-id').val('');
    if(t.length < 1){ $('#m2g-customer-ac').hide(); return; }
    ajx({action:'search_customer', term:t}, function(r){
        if(!r.success||!r.data||!r.data.length){ $('#m2g-customer-ac').hide(); return; }
        var h='';
        r.data.forEach(function(c){ h+='<div class="ac-item" data-cid="'+esc(c.customer_id)+'" data-cname="'+esc(c.customer)+'"><strong>'+esc(c.customer_id)+'</strong> <span class="sub">'+esc(c.customer)+'</span></div>'; });
        $('#m2g-customer-ac').html(h).show();
        $('#m2g-customer-ac .ac-item').off('click').on('click', function(){
            $('#m2g-customer-name').val($(this).data('cname'));
            $('#m2g-customer-id').val($(this).data('cid'));
            $('#m2g-customer-ac').hide();
        });
    });
}

// 關閉客戶 AC
$(document).on('click', function(e){
    if(!$(e.target).closest('#grp-customer-name,#grp-customer-ac').length) $('#grp-customer-ac').hide();
    if(!$(e.target).closest('#m2g-customer-name,#m2g-customer-ac').length) $('#m2g-customer-ac').hide();
    if(!$(e.target).closest('#grp-order-search,#grp-order-ac').length) $('#grp-order-ac').hide();
});

// ── 組合件綁定訂單搜尋 ──────────────────────────
var _grpOrderTimer = null;
// 已由下拉選單取代連動搜尋

function _renderGrpOrderAc(forceData, term, year, month){
    var gName = ($('#grp-name').val()||'').trim();
    ajx({action:'get_orders_for_group', d_setting_id:gName, year:year||'', month:month||''}, function(r){
        if(!r.success||!r.data) return;
        var list = r.data;
        if(term){ list = list.filter(function(o){ return (o.Order_oo||'').indexOf(term)>=0 || (o.d_id||'').indexOf(term)>=0 || (o.Client_name||'').indexOf(term)>=0; }); }
        if(!list.length){ $('#grp-order-ac').hide(); return; }
        var h = '';
        list.forEach(function(o){
            var status = o.Order_status ? '<span style="color:#e74c3c;font-size:10px;">['+esc(o.Order_status)+']</span>' : '<span style="color:#27ae60;font-size:10px;">[進行中]</span>';
            var price = o.unit_price ? '$'+parseFloat(o.unit_price).toLocaleString() : '-';
            h += '<div class="ac-item" data-oid="'+esc(o.Order_oo)+'" data-price="'+esc(o.unit_price||'')+'" style="padding:5px 8px;border-bottom:1px solid #f0f0f0;">'
               + '<div style="display:flex;justify-content:space-between;align-items:center;">'
               + '<strong style="color:var(--primary);">'+esc(o.Order_oo)+'</strong>'+status+'</div>'
               + '<div style="font-size:11px;color:#555;">料號：'+esc(o.d_id)+'　客戶：'+esc(o.Client_name||'-')+'　售價：'+price+'　交期：'+esc(o.Delivery_date||'-')+'</div>'
               + '</div>';
        });
        $('#grp-order-ac').html(h).show();
        $('#grp-order-ac .ac-item').off('click').on('click', function(){
            var oid   = $(this).data('oid');
            var price = parseFloat($(this).data('price')||0);
            $('#grp-order-ref').val(oid);
            $('#grp-order-search').val(oid);
            $('#grp-order-ac').hide();
            _applyGrpOrderPrice(oid, price);
        });
    });
}

function _applyGrpOrderPrice(orderOo, price){
    if(price > 0){
        $('#grp-unit-price').val(price).prop('disabled', true).css('background','#f5f5f5');
        $('#grp-price-src-lbl').show();
        $('#grp-price-manual-lbl').hide();
        $('#grp-order-info').html('<i class="fa fa-check-circle" style="color:#27ae60;"></i> 已綁定訂單 <strong>'+esc(orderOo)+'</strong>，售價自動帶入：<strong>$'+price.toLocaleString()+'</strong>');
    } else {
        $('#grp-unit-price').prop('disabled', false).css('background','');
        $('#grp-price-src-lbl').show();
        $('#grp-price-manual-lbl').hide();
        $('#grp-order-info').html('<i class="fa fa-info-circle" style="color:#f39c12;"></i> 已綁定訂單 <strong>'+esc(orderOo)+'</strong>，但訂單無售價，請手動輸入');
    }
}

function grpOrderClear(){
    $('#grp-order-ref').val('');
    $('#grp-order-search').val('');
    $('#grp-order-ac').hide();
    $('#grp-order-info').html('');
    $('#grp-unit-price').prop('disabled', false).css('background','');
    $('#grp-price-src-lbl').hide();
    $('#grp-price-manual-lbl').show();
}
// ── groupEditModal 訂單搜尋 ──────────────────────────
function grpEditOrderSearch(){
    var term  = ($('#grp-edit-order-search').val()||'').trim();
    var year  = $('#grp-edit-order-year').val()||'';
    var month = $('#grp-edit-order-month').val()||'';
    var gDsid = (G_grpEditRow && G_grpEditRow.d_setting_id) ? G_grpEditRow.d_setting_id : '';
    ajx({action:'get_orders_for_group', d_setting_id:gDsid, year:year, month:month}, function(r){
        var list = r.data || [];
        if(term){ list = list.filter(function(o){ return (o.Order_oo||'').indexOf(term)>=0 || (o.d_id||'').indexOf(term)>=0 || (o.Client_name||'').indexOf(term)>=0; }); }
        if(!list.length && term){
            // 全文搜尋 fallback
            ajx({action:'search_order', term:term, d_id:''}, function(r2){
                if(!r2.success||!r2.data||!r2.data.length){ $('#grp-edit-order-ac').hide(); return; }
                _showGrpEditOrderAc(r2.data.map(function(o){ return {Order_oo:o.Order_oo, d_id:o.d_id, Client_name:o.Client_name, unit_price:o.unit_price, Order_status:null, Delivery_date:''}; }));
            }); return;
        }
        if(!list.length){ $('#grp-edit-order-ac').hide(); return; }
        _showGrpEditOrderAc(list);
    });
}
function _showGrpEditOrderAc(list){
    var h = '';
    list.forEach(function(o){
        var status = o.Order_status ? '<span style="color:#e74c3c;font-size:10px;">['+esc(o.Order_status)+']</span>' : '<span style="color:#27ae60;font-size:10px;">[進行中]</span>';
        var price  = o.unit_price ? '$'+parseFloat(o.unit_price).toLocaleString() : '-';
        h += '<div class="ac-item" data-oid="'+esc(o.Order_oo)+'" data-price="'+esc(o.unit_price||'')+'" style="padding:5px 8px;border-bottom:1px solid #f0f0f0;">'
           + '<div style="display:flex;justify-content:space-between;align-items:center;"><strong style="color:var(--primary);">'+esc(o.Order_oo)+'</strong>'+status+'</div>'
           + '<div style="font-size:11px;color:#555;">料號：'+esc(o.d_id)+'　客戶：'+esc(o.Client_name||'-')+'　售價：'+price+'　交期：'+esc(o.Delivery_date||'-')+'</div>'
           + '</div>';
    });
    $('#grp-edit-order-ac').html(h).show();
    $('#grp-edit-order-ac .ac-item').off('click').on('click', function(){
        var oid   = $(this).data('oid');
        var price = parseFloat($(this).data('price')||0);
        $('#grp-edit-order-ref').val(oid);
        $('#grp-edit-order-search').val(oid);
        $('#grp-edit-order-ac').hide();
        var priceHtml = price>0 ? '，售價：<strong>$'+price.toLocaleString()+'</strong>' : '';
        $('#grp-edit-order-info').html('<i class="fa fa-check-circle" style="color:var(--accent);"></i> 已選擇：<strong>'+esc(oid)+'</strong>'+priceHtml);
    });
}
function grpEditOrderClear(){
    $('#grp-edit-order-ref').val('');
    $('#grp-edit-order-search').val('');
    $('#grp-edit-order-info').html('<span style="color:#aaa;font-size:11px;">已清除訂單綁定</span>');
    $('#grp-edit-order-ac').hide();
}

function confirmSaveGroup(){
    var gName = ($('#grp-name').val()||'').trim();
    if(!gName){ toast('組合名稱為必填','error'); $('#grp-name').focus(); return; }

    // 檢查料號是否存在或有相似項
    ajx({action:'check_group_did', group_name:gName}, function(r){
        if(!r.success) { _executeConfirmSaveGroup(); return; }
        if(r.exists) {
            _executeConfirmSaveGroup();
        } else if(r.matches && r.matches.length > 0) {
            var promptMsg = "料號「" + gName + "」尚未建立。\n\n相似料號：\n" + r.matches.map(function(m, i){ return (i+1) + ". " + m; }).join('\n') 
                + "\n\n請輸入序號選取該料號，或輸入 0 以此名稱建立新料號：";
            var choice = prompt(promptMsg, "0");
            if(choice === null) return;
            var idx = parseInt(choice);
            if(idx > 0 && r.matches[idx-1]) { $('#grp-name').val(r.matches[idx-1]); _executeConfirmSaveGroup(); }
            else if(choice === "0") { _executeConfirmSaveGroup(); }
        } else { _executeConfirmSaveGroup(); }
    });
}

function _executeConfirmSaveGroup(){
    var gName = ($('#grp-name').val()||'').trim();
    if(!gName){ toast('組合名稱為必填','error'); $('#grp-name').focus(); return; }
    var catId = $('#grp-cat').val();
    if(!catId){ toast('品項種類為必填','error'); return; }
    var locId = $('#grp-loc').val();
    if(!locId){ toast('儲位為必填','error'); return; }
    var custId = ($('#grp-customer-id').val()||'').trim(); 
    var custName = ($('#grp-customer-name').val()||'').trim();
    if(!custId && !custName){ toast('請填寫或選取客戶名稱','error'); return; }
    var locCode = $('#grp-loc option:selected').data('code')||'';
    var sd = $('#grp-sd').val()||new Date().toISOString().split('T')[0];
    var sets = parseInt($('#grp-sets').val()||1); if(sets<1){ toast('入庫組數必須 ≥ 1','error'); return; }
    var gPrice = ($('#grp-unit-price').val()||'').trim();

    var items = [];
    var ok = true;
    G_grpItems.forEach(function(idx){
        var el = document.getElementById('grp-item-'+idx);
        if(!el) return;
        var did = ($('#gi-did-'+idx).val()||'').trim();
        if(!did){ toast('料號 #'+idx+' 的料號不可為空','error'); ok=false; return; }
        var pps = parseInt($('#gi-pps-'+idx).val()||1); if(pps<1) pps=1;
        items.push({
            d_id:        did,
            d_setting_id:$('#gi-dsid-'+idx).val()||0,
            client_name: $('#gi-cname-'+idx).val()||'',
            client_id:   $('#gi-cid-'+idx).val()||'',
            category_id: catId,
            location_id: locId,
            storage_location: locCode,
            pcs_per_set: pps,
            unit_id:     $('#gi-unit-'+idx).val()||0,
            unit_price:  ($('#gi-price-'+idx).val()||'').trim(),
            bom_ref:     ($('#gi-bom-'+idx).val()||'').trim(),
            stock_date:  sd,
            mfg_date:    sd,
        });
    });
    if(!ok) return;
    if(items.length < 2){ toast('組合件至少需要 2 個料號','error'); return; }

    ajx({
        action:             'save_stock_group',
        group_name:         gName,
        group_unit_price:   gPrice,
        group_order_ref:    ($('#grp-order-sel').val()||'').trim(),
        group_remark:       ($('#grp-remark').val()||'').trim(),
        group_customer_id:  custId || custName,
        sets:               sets,
        items_json:         JSON.stringify(items)
    }, function(r){
        if(!r.success){ toast(r.message||'建立失敗','error'); return; }
        $('#groupModal').modal('hide');
        toast(r.message||'建立成功','success');
        loadList(G.page);
        loadStats();
    });
}

// 關閉 groupModal AC
$(document).on('click', function(e){
    if(!$(e.target).closest('[id^="gi-did-"],[id^="gi-ac-"]').length){
        $('[id^="gi-ac-"]').hide();
    }
});

// ── 組合件整組入/出庫 ─────────────────────────────
var G_grpTxnMembers = [];
var G_grpTxnBomLists = {}; // { stock_item_id: [bom data] }
function openGroupTxn(txnType, row){
    var isGroup = !!(row.group_id && parseInt(row.pcs_per_set||0)>0);
    G_grpTxnMembers = row.group_members || [];
    if(!isGroup){ G_grpTxnMembers = [row]; }

    var gid = row.group_id;
    var gname = row.group_name || row.d_id || '庫存品項';
    var isIn = (txnType==='in');
    $('#grp-txn-group-id').val(gid);
    $('#grp-txn-item-id').remove();
    if(!isGroup) $('<input type="hidden" id="grp-txn-item-id">').val(row.stock_item_id).appendTo('#groupTxnModal .modal-body');

    $('#grp-txn-type').val(txnType);
    $('#grp-txn-sets').val(1);
    $('#grp-txn-remark').val('');
    $('#grp-txn-date').val(new Date().toISOString().split('T')[0]);
    $('#grp-txn-calc').hide();

    // 計算目前組數
    var currentQtyTotal = row.qty || 0;
    var currentSets = 0;
    if(G_grpTxnMembers.length>0){
        var m0 = G_grpTxnMembers[0];
        var pps0 = isGroup ? parseInt(m0.pcs_per_set||0) : 1;
        if(pps0>0) currentSets = Math.floor(parseFloat(m0.qty||0)/pps0);
    }

    // 標題與按鈕
    var accentColor = isIn ? 'var(--accent)' : 'var(--danger,#e74c3c)';
    var iconClass   = isIn ? 'fa-arrow-down' : 'fa-arrow-up';
    var actionLabel = isIn ? '入庫' : '出庫';
    if(!isGroup) actionLabel = isIn ? '單品入庫' : '單品出庫 (FIFO)';
    
    $('#grpTxnTitle').html('<i class="fa '+(isGroup?'fa-cubes':'fa-cube')+'"></i> '+actionLabel+'：<span style="color:'+accentColor+';">'+esc(gname)+'</span>');
    $('#grp-txn-qty-label').html((isIn?'入庫':'出庫')+(isGroup?'組數':'數量')+' <span style="color:red;">*</span>');
    $('#grp-txn-confirm-btn').html('<i class="fa '+iconClass+'"></i> 確認'+actionLabel)
        .css({'background':accentColor,'border-color':accentColor});

    // 組合摘要
    var badges = isGroup ? G_grpTxnMembers.map(function(m){
        return '<span style="background:#f0f4ff;border:1px solid #c8d8f8;border-radius:4px;padding:2px 8px;font-size:11px;display:inline-block;">'+esc(m.d_id)+' × '+(m.pcs_per_set||'?')+' PCS</span>';
    }).join(' ') : '';
    $('#grp-txn-info').html(
        '<div style="font-weight:700;color:var(--primary);margin-bottom:4px;">'+esc(gname)+(isGroup?' (組合件)':'')+'</div>'
        + '<div style="color:#888;margin-bottom:6px;">目前庫存：<strong>'+currentQtyTotal+'</strong>'+(isGroup?' ('+currentSets+'組)':'')+'</div>'
        + '<div style="display:flex;flex-wrap:wrap;gap:4px;">'+badges+'</div>'
    );

    // 顯示/隱藏入庫/出庫區塊
    $('#grp-txn-in-section').toggle(isIn && isGroup);
    $('#grp-txn-out-section').toggle(!isIn);
    $('#grp-txn-sets-wrap').toggle(isIn);
    $('#grp-txn-group-order-wrap').toggle(isIn);
    $('#grp-txn-dept-wrap').toggle(!isIn);

    if(isIn){
        // 重置整組入庫訂單綁定
        var existGrpOrder = row.group_order_ref || '';
        $('#grp-txn-order-ref').val(existGrpOrder); 
        loadOrdersForEditForm(row.d_setting_id || row.d_id, row.bom_ref, row.qty, document.getElementById('grp-txn-order-list-container'));
        if(isGroup) _buildGrpTxnInMembers();
    } else {
        // 出庫：部門/人員（記憶上次）
        var lastDept = sessionStorage.getItem('stock_last_dept')||'';
        var lastUser = sessionStorage.getItem('stock_last_user')||'';
        var dh = '<option value="">— 選擇部門 —</option>';
        G.allDepts.forEach(function(d){ dh += '<option value="'+d.id+'"'+(d.id==lastDept?' selected':'')+'>'+esc(d.name)+'</option>'; });
        $('#grp-txn-dept').html(dh);
        if(lastDept){
            ajx({action:'get_dept_users',dept_id:lastDept},function(r){
                if(!r.success)return;
                var uh='<option value="">— 選擇人員 —</option>';
                (r.users||[]).forEach(function(u){ uh+='<option value="'+u.id+'"'+(u.id==lastUser?' selected':'')+'>'+esc(u.user_cname)+'</option>'; });
                $('#grp-txn-user').html(uh);
            });
        } else {
            $('#grp-txn-user').html('<option value="">— 先選部門 —</option>');
        }
        if(isGroup) _buildGrpTxnOutBatches();
        else _loadItemOutBatches(row);
    }

    updateGrpTxnCalc();
    $('#groupTxnModal').modal('show');
}

function grpTxnLoadUsers(){
    var deptId = $('#grp-txn-dept').val();
    ajx({action:'get_dept_users',dept_id:deptId||0},function(r){
        if(!r.success)return;
        var h='<option value="">— 選擇人員 —</option>';
        (r.users||[]).forEach(function(u){ h+='<option value="'+u.id+'">'+esc(u.user_cname)+'</option>'; });
        $('#grp-txn-user').html(h);
    });
}

// ── 整組入庫：組合件料號綁定訂單搜尋 ──

// ── 入庫：建立子件 BOM/訂單選擇表格 ──
function _buildGrpTxnInMembers(){
    var tbl = '<table style="width:100%;border-collapse:collapse;">'
        + '<thead><tr style="background:#f8f9fc;">'
        + '<th style="padding:8px 12px;font-size:12px;font-weight:700;color:var(--primary);width:30%;">料號</th>'
        + '<th style="padding:8px 12px;font-size:12px;font-weight:700;color:var(--primary);">製令(BOM)</th>'
        + '<th style="padding:8px 12px;font-size:12px;font-weight:700;color:var(--primary);">對應訂單</th>'
        + '</tr></thead><tbody>';
    G_grpTxnMembers.forEach(function(m){
        var sid = m.stock_item_id;
        var pps = parseInt(m.pcs_per_set||1);
        tbl += '<tr style="border-top:1px solid #eef0f5;">'
            + '<td style="padding:8px 12px;font-size:12px;">'
            +   '<strong>'+esc(m.d_id)+'</strong>'
            +   '<div style="font-size:10px;color:#aaa;margin-top:1px;">每組 '+pps+' PCS</div>'
            +   '<input type="hidden" class="gtxn-sid" value="'+sid+'">'
            +   '<input type="hidden" class="gtxn-did" value="'+esc(m.d_id)+'">'
            + '</td>'
            + '<td style="padding:6px 12px;">'
            +   '<select class="form-control gtxn-bom-sel" style="font-size:11px;height:30px;padding:2px 6px;" data-sid="'+sid+'" onchange="gtxnBomChange(this)">'
            +     '<option value="">— 載入中... —</option>'
            +   '</select>'
            +   '<input type="hidden" class="gtxn-bom-val" value="">'
            + '</td>'
            + '<td style="padding:6px 12px;">'
            +   '<div style="position:relative;">'
            +     '<select class="form-control gtxn-order-sel" style="font-size:11px;height:30px;padding:2px 6px;" data-sid="'+sid+'" onchange="gtxnOnOrderSelect(this)">'
            +       '<option value="">— 載入中... —</option>'
            +     '</select>'
            +     '<input type="hidden" class="gtxn-order-id" value="">'
            +   '</div>'
            + '</td>'
            + '</tr>';
    });
    tbl += '</tbody></table>';
    $('#grp-txn-members-in').html(tbl);

    // 批次載入各子件 BOM + 訂單下拉
    G_grpTxnMembers.forEach(function(m){
        var sid = m.stock_item_id;
        var did = m.d_id;
        var existBom = m.bom_ref || '';
        var existOrd = m.order_ref || 0;
        // BOM
        ajx({action:'search_bom',term:'',d_id:did}, function(r){
            G_grpTxnBomLists[sid] = r.data || [];
            var h = '<option value="">— 無BOM —</option>';
            (r.data||[]).forEach(function(b){
                var tc=parseFloat(b.total_cost||0), sq=parseFloat(b.sqty||0);
                var tag = tc>0?'（$'+(sq>0?(tc/sq).toFixed(1):tc.toFixed(1))+'）':'（無成本）';
                h += '<option value="'+esc(b.bom)+'"'+(b.bom===existBom?' selected':'')+'>'+esc(b.bom)+tag+'</option>';
            });
            $('#grp-txn-members-in .gtxn-bom-sel[data-sid="'+sid+'"]').html(h).val(existBom);
            $('#grp-txn-members-in .gtxn-bom-sel[data-sid="'+sid+'"]').closest('tr').find('.gtxn-bom-val').val(existBom);
        });
        // 訂單
        ajx({action:'get_orders_for_edit', d_id: (m.d_setting_id || did), bom: existBom}, function(r){
            var h = '<option value="">— 請選取 —</option>';
            if(r.success && r.orders && r.orders.length > 0){
                r.orders.forEach(function(o){
                    var ooo = o.Order_oo ? o.Order_oo.replace(/OO(\d{3})(\d{2})(\d{2})\d{3}/i,'$1-$2$3') : o.Order_id;
                    var label = ooo + ' x' + (o.my_allocated || o.Qty) + ' (' + (o.Delivery_date||'').substring(5) + ')';
                    h += '<option value="'+o.Order_id+'" '+(o.is_bound?'selected':'')+'>'+esc(label)+'</option>';
                });
                h += '<option value="B" '+(existOrd == 'B'?'selected':'')+'>備庫</option>';
            } else {
                h = '<option value="">無訂單</option><option value="B" '+(existOrd == 'B'?'selected':'')+'>備庫</option>';
            }
            var $sel = $('#grp-txn-members-in .gtxn-order-sel[data-sid="'+sid+'"]');
            $sel.html(h);
            var $sel = $('#grp-txn-members-in .gtxn-order-sel[data-sid="'+sid+'"]');
            $sel.closest('tr').find('.gtxn-order-id').val($sel.val()||'');
        });
    });
}
function gtxnBomChange(sel){
    $(sel).closest('tr').find('.gtxn-bom-val').val($(sel).val()||'');
}
function gtxnOnOrderSelect(sel){
    $(sel).closest('tr').find('.gtxn-order-id').val($(sel).val()||'');
}

// ── 出庫：建立 FIFO 批次選擇 ──
function _buildGrpTxnOutBatches(){
    // 從各子件的 stock_transactions 取入庫批次（get_group_batches action）
    var gid = $('#grp-txn-group-id').val();
    $('#grp-txn-batches').html('<div style="padding:16px;text-align:center;color:#aaa;font-size:12px;"><i class="fa fa-spinner fa-spin"></i> 載入批次...</div>');
    ajx({action:'get_group_batches', group_id:gid}, function(r){
        if(!r.success||!r.batches||!r.batches.length){
            $('#grp-txn-batches').html('<div style="padding:16px;text-align:center;color:#aaa;font-size:12px;">無可用批次（庫存為 0 或無入庫記錄）</div>');
            return;
        }
        G_grpTxnBatches = r.batches;
        var tbl = '<table style="width:100%;border-collapse:collapse;">'
            + '<thead><tr style="background:#fff8f8;">'
            + '<th style="padding:7px 10px;font-size:11px;width:30px;"><input type="checkbox" id="gtxn-chk-all" onchange="gtxnToggleAll(this.checked)" checked></th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;">批次日期</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;">BOM</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;">訂單</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;">備註</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;text-align:right;">剩餘庫存</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;text-align:right;">本次出庫</th>'
            + '</tr></thead><tbody>';
        r.batches.forEach(function(b, i){
            var keyLabel = b.txn_date + (b.txn_bom?' / '+b.txn_bom:'') + (b.txn_order_no?' / '+b.txn_order_no:'');
            // 顯示每個子件的批次剩餘
            var memberRows = '';
            b.members.forEach(function(m){
                memberRows += '<div style="font-size:10px;color:#888;">'+esc(m.d_id)+'：剩 '+m.remaining_qty+' PCS</div>';
            });
            tbl += '<tr style="border-top:1px solid #fde8e8;" data-batch-idx="'+i+'">'
                + '<td style="padding:7px 10px;"><input type="checkbox" class="gtxn-batch-chk" data-idx="'+i+'" checked onchange="gtxnUpdateSummary()"></td>'
                + '<td style="padding:7px 10px;font-size:12px;font-weight:600;">'+esc(b.txn_date)+'</td>'
                + '<td style="padding:7px 10px;font-size:11px;color:var(--accent);">'+(b.txn_bom?'<i class="fa fa-link"></i> '+esc(b.txn_bom):'<span style="color:#ccc;">—</span>')+'</td>'
                + '<td style="padding:7px 10px;font-size:11px;color:var(--info);">'+(b.txn_order_no?'<i class="fa fa-shopping-cart"></i> '+esc(b.txn_order_no):'<span style="color:#ccc;">—</span>')+'</td>'
                + '<td style="padding:7px 10px;font-size:11px;color:#666;max-width:120px;">'+(b.remark?esc(b.remark):'<span style="color:#ccc;">—</span>')+'</td>'
                + '<td style="padding:7px 10px;font-size:12px;text-align:right;color:var(--primary);"><strong>'+b.sets+' 組</strong><br><div style="font-size:10px;margin-top:1px;">'+memberRows+'</div></td>'
                + '<td style="padding:7px 10px;text-align:right;">'
                +   '<input type="number" class="form-control gtxn-batch-qty" data-idx="'+i+'" value="" min="0" max="'+b.sets+'" step="1" style="width:70px;display:inline-block;font-size:12px;padding:2px 6px;height:28px;text-align:right;" placeholder="0" oninput="gtxnUpdateSummary()">'
                +   ' 組'
                + '</td>'
                + '</tr>';
        });
        tbl += '</tbody></table>';
        $('#grp-txn-batches').html(tbl);
        gtxnUpdateSummary();
    });
}
function gtxnToggleAll(checked){
    $('.gtxn-batch-chk').prop('checked', checked);
    // 取消勾選時出庫量清零，勾選時恢復
    $('.gtxn-batch-chk').each(function(){
        var idx = $(this).data('idx');
        if(!checked) $('[data-idx="'+idx+'"].gtxn-batch-qty').val(0);
        else if(G_grpTxnBatches && G_grpTxnBatches[idx]) $('[data-idx="'+idx+'"].gtxn-batch-qty').val(G_grpTxnBatches[idx].sets);
    });
    gtxnUpdateSummary();
}
function gtxnUpdateSummary(){
    var total = 0;
    $('.gtxn-batch-chk:checked').each(function(){
        var idx=$(this).data('idx');
        total += parseInt($('[data-idx="'+idx+'"].gtxn-batch-qty').val()||0);
    });
    var html = '本次出庫合計：<strong style="font-size:14px;color:var(--danger,#e74c3c);">'+total+' 組</strong>';
    if(total>0){
        var members = {};
        G_grpTxnMembers.forEach(function(m){ members[m.stock_item_id] = {d_id:m.d_id, pps:parseInt(m.pcs_per_set||1), total:0}; });
        $('.gtxn-batch-chk:checked').each(function(){
            var idx=$(this).data('idx');
            var qty=parseInt($('[data-idx="'+idx+'"].gtxn-batch-qty').val()||0);
            if(qty>0 && G_grpTxnBatches && G_grpTxnBatches[idx]){
                G_grpTxnBatches[idx].members.forEach(function(bm){
                    if(members[bm.stock_item_id]) members[bm.stock_item_id].total += qty * members[bm.stock_item_id].pps;
                });
            }
        });
        var mHtml = Object.values(members).filter(function(m){ return m.total>0; }).map(function(m){
            return '<span style="background:#fde8e8;border-radius:3px;padding:1px 6px;font-size:10px;margin:0 2px;">'+esc(m.d_id)+'：-'+m.total+' PCS</span>';
        }).join('');
        if(mHtml) html += '<div style="margin-top:4px;">'+mHtml+'</div>';
    }
    $('#grp-txn-out-summary').html(html).show();
}

function updateGrpTxnCalc(){
    var sets = parseInt($('#grp-txn-sets').val()||0);
    var txnType = $('#grp-txn-type').val();
    if(!sets || G_grpTxnMembers.length===0 || txnType!=='in'){ $('#grp-txn-calc').hide(); return; }
    var html = '入庫 '+sets+' 組，各料號：';
    var parts = G_grpTxnMembers.map(function(m){
        var pps = parseInt(m.pcs_per_set||1);
        return '<strong>'+esc(m.d_id)+'</strong> +'+pps*sets;
    });
    html += parts.join('　');
    $('#grp-txn-calc').html(html).show();
}

function confirmGroupTxn(){
    var gid = $('#grp-txn-group-id').val();
    var sid = $('#grp-txn-item-id').val();
    var txnType = $('#grp-txn-type').val();
    var remark = ($('#grp-txn-remark').val()||'').trim();
    var txnDate = $('#grp-txn-date').val();

    if(txnType === 'in'){
        // ── 入庫：收集各子件 BOM/訂單 ──
        var sets = parseInt($('#grp-txn-sets').val()||0);
        if(!sets||sets<1){ toast('組數必須 ≥ 1','error'); return; }
        var items = [];
        $('#grp-txn-members-in tbody tr').each(function(){
            var $r = $(this);
            items.push({
                stock_item_id: $r.find('.gtxn-sid').val(), 
                bom_ref:  $r.find('.gtxn-bom-val').val()||'', 
                order_ref: $r.find('.gtxn-order-id').val()||0
            });
        });
        // 組合件主料號綁定訂單
        var $grpChecked = $('#grp-txn-order-list-container input.order-bind-cb:checked');
        var grpOrderRef = $grpChecked.length > 0 ? $grpChecked.closest('tr').find('td:nth-child(2)').text().trim() : '';
        
        ajx({action:'group_txn', group_id:gid, stock_item_id:sid, txn_type:'in', sets:sets,
             group_order_ref: grpOrderRef,
             items_json:JSON.stringify(items), remark:remark, txn_date:txnDate},
        function(r){
            if(!r.success){ toast(r.message||'入庫失敗','error'); return; }
            $('#groupTxnModal').modal('hide');
            toast('整組入庫成功！'+sets+' 組，共 '+r.results.length+' 筆料號已更新','success');
            loadList(G.page); loadStats();
        });
    } else {
        // ── 出庫：收集勾選批次 + 記憶部門/人員 ──
        var deptId = $('#grp-txn-dept').val()||0;
        var userId2 = $('#grp-txn-user').val()||0;
        var userNameStr = $('#grp-txn-user option:selected').text();
        if(deptId){ sessionStorage.setItem('stock_last_dept',deptId); sessionStorage.setItem('stock_last_user',userId2); sessionStorage.setItem('stock_last_user_name',userNameStr); }
        var batchItems = [];
        var totalSets = 0;
        $('.gtxn-batch-chk:checked').each(function(){
            var idx=$(this).data('idx');
            var qty=parseInt($('[data-idx="'+idx+'"].gtxn-batch-qty').val()||0);
            if(qty>0 && G_grpTxnBatches && G_grpTxnBatches[idx]){
                batchItems.push({ batch_key: G_grpTxnBatches[idx].batch_key, sets: qty });
                totalSets += qty;
            }
        });
        if(!batchItems.length||totalSets<1){ toast('請勾選至少一個出庫批次','error'); return; }
        ajx({action:'group_txn', group_id:gid, stock_item_id:sid, txn_type:'out', sets:totalSets,
             out_dept_id:deptId, out_user_id:userId2,
             batches_json:JSON.stringify(batchItems), remark:remark, txn_date:txnDate},
        function(r){
            if(!r.success){ toast(r.message||'出庫失敗','error'); return; }
            $('#groupTxnModal').modal('hide');
            if(sid){ toast('出庫成功！','success'); } else { toast('整組出庫成功！共 '+totalSets+' 組','success'); }
            loadList(G.page); loadStats();
        });
    }
}

// ── 組合件整組編輯 ─────────────────────────────────
var G_grpEditRow = null;
function openGroupEdit(rowOrData, groupMembers){
    G_grpEditRow = rowOrData;
    var gid = rowOrData.group_id;
    var gname = rowOrData.group_name || '組合件';
    // 若已有 group_members（來自 get_stock_detail）則直接用，否則從 rowOrData.group_members 取
    var members = groupMembers || rowOrData.group_members || [];

    $('#grp-edit-group-id').val(gid);

    // 初始化群組訂單綁定（從 group_order_ref 欄位讀取，若有則直接顯示）
    var existingOrderRef = rowOrData.group_order_ref || ''; 
    $('#grp-edit-order-ref').val(existingOrderRef);
    loadOrdersForEditForm(rowOrData.d_setting_id || rowOrData.d_id, rowOrData.bom_ref, rowOrData.qty, document.getElementById('grp-edit-order-list-container'));

    // 計算目前組數
    var currentSets = 0;
    if(members.length>0){
        var m0 = members[0];
        var pps0 = parseInt(m0.pcs_per_set||0);
        if(pps0>0) currentSets = Math.round(parseFloat(m0.qty||0)/pps0);
    }
    $('#grp-edit-sets').val(currentSets);

    // 顯示群組資訊
    $('#grp-edit-info').html('<strong>組合：</strong>'+esc(gname)+'&nbsp; <span style="color:#888;">目前：'+currentSets+' 組</span>');

    // 顯示各料號明細（含 BOM 選擇 + 訂單選擇，每子件各自獨立）
    var mHtml = '<table class="table" style="font-size:12px;margin:0;">'
        + '<thead><tr style="background:#f8f9fc;">'
        + '<th style="width:1050px;">料號</th><th style="width:200px;">每組PCS</th><th>目前庫存</th><th>調整後</th>'
        + '<th>製令(BOM)</th><th>訂單</th>'
        + '</tr></thead><tbody id="grp-edit-preview-tbody">';
    members.forEach(function(m){
        var pps = parseInt(m.pcs_per_set||1);
        var curQty = parseFloat(m.qty||0);
        var sid = m.stock_item_id;
        var bomVal = m.bom_ref || '';
        var ordId  = m.order_ref || '';
        var ordNo  = m.order_no  || '';
        mHtml += '<tr id="grp-edit-row-'+sid+'">'
            + '<td><strong>'+esc(m.d_id)+'</strong>'
            +   '<input type="hidden" class="ge-sid" value="'+sid+'">'
            +   '<input type="hidden" class="ge-dsid" value="'+esc(m.d_setting_id||'')+'">'
            +   '<input type="hidden" class="ge-did" value="'+esc(m.d_id)+'">'
            + '</td>'
            + '<td style="text-align:center;">'+pps+' PCS</td>'
            + '<td style="text-align:center;color:var(--primary);">'+curQty+'</td>'
            + '<td style="text-align:center;" id="grp-edit-preview-'+sid+'">'+curQty+'</td>'
            // BOM select
            + '<td style="min-width:160px;">'
            +   '<select class="form-control ge-bom-sel" style="font-size:11px;padding:2px 4px;height:28px;" data-sid="'+sid+'" onchange="geOnBomChange(this)">'
            +     '<option value="">— 載入中... —</option>'
            +   '</select>'
            +   '<input type="hidden" class="ge-bom-val" value="'+esc(bomVal)+'">'
            + '</td>'
            // Order dropdown
        + '<td style="min-width:220px;">'
        +   '<select class="form-control ge-order-sel" style="font-size:11px;padding:2px 4px;height:28px;" data-sid="'+sid+'" onchange="$(this).closest(\'tr\').find(\'.ge-order-id\').val($(this).val())">'
        +     '<option value="">— 載入中... —</option>'
        +   '</select>'
        +   '<input type="hidden" class="ge-order-id" value="'+esc(ordId)+'">'
        + '</td>'
            + '</tr>';
    });
    mHtml += '</tbody></table>';
    $('#grp-edit-members').html(mHtml);
    $('#grp-edit-remark').val('');
    $('#grp-edit-calc').text('');

    // 批次載入各子件的 BOM select
    members.forEach(function(m){
        _geLoadBomSelect(m.stock_item_id, m.d_id, m.bom_ref||''); 
        // 恢復使用下拉選單載入訂單
        _geLoadOrderSelect(m.stock_item_id, m.d_id, m.bom_ref, m.order_ref);
    });

    // 繫結即時預覽
    $('#grp-edit-sets').off('input.grpedit').on('input.grpedit', function(){ updateGrpEditCalc(members); });
    updateGrpEditCalc(members);

    $('#groupEditModal').modal('show');
}
function updateGrpEditCalc(members){
    var sets = parseInt($('#grp-edit-sets').val()||0);
    if(!members){
        // 如果沒傳入 members，則從 DOM 結構中動態讀取子件資訊
        members = [];
        $('#grp-edit-preview-tbody tr').each(function(){
            var sid = $(this).find('.ge-sid').val();
            // 讀取「每組PCS」欄位（第2欄）
            var pps = parseInt($(this).find('td:nth-child(2)').text()) || 1;
            // 讀取「目前庫存」欄位（第3欄）
            var qty = parseFloat($(this).find('td:nth-child(3)').text()) || 0;
            members.push({ stock_item_id: sid, pcs_per_set: pps, qty: qty });
        });
    }
    if(members.length === 0){ $('#grp-edit-calc').text(''); return; }
    members.forEach(function(m){
        var pps = parseInt(m.pcs_per_set||1);
        var newQty = pps * sets;
        var el = document.getElementById('grp-edit-preview-'+m.stock_item_id);
        if(el){
            var curQty = parseFloat(m.qty||0);
            var diff = newQty - curQty;
            var color = diff>0?'var(--accent)':diff<0?'var(--danger)':'var(--primary)';
            el.innerHTML = '<strong style="color:'+color+';">'+newQty+'</strong>'+(diff!==0?' <span style="font-size:10px;color:'+color+';">'+(diff>0?'+':'')+diff+'</span>':'');
        }
    });
    $('#grp-edit-calc').text(sets+' 組 × 各 PCS = 各料號庫存如上表');
}
function confirmGroupEdit(){
    var gid      = $('#grp-edit-group-id').val();
    var sets     = parseInt($('#grp-edit-sets').val());
    if(isNaN(sets)||sets<0){ toast('組數不可為負數','error'); return; }
    var remark   = ($('#grp-edit-remark').val()||'').trim() || '整組調整組數'; 

    // 組合件主料號綁定訂單
    var $grpChecked = $('#grp-edit-order-list-container input.order-bind-cb:checked');
    var orderRef = $grpChecked.length > 0 ? $grpChecked.data('ono') : '';

    // 收集各子件的 bom_ref + order_ref
    var items = [];
    $('#grp-edit-preview-tbody tr').each(function(){
        var $row = $(this); 
        var sid    = $row.find('.ge-sid').val();
        var bomVal = $row.find('.ge-bom-val').val()||'';
        // 從子件的下拉選單獲取 ID
        var ordId = $row.find('.ge-order-sel').val() || '';
        items.push({stock_item_id: sid, bom_ref: bomVal, order_ref: ordId});
    });
    ajx({action:'group_adjust', group_id:gid, sets:sets, remark:remark,
         group_order_ref:orderRef, items_json:JSON.stringify(items)}, function(r){
        if(!r.success){ toast(r.message||'儲存失敗','error'); return; }
        $('#groupEditModal').modal('hide');
        toast(r.message||'已儲存','success');
        loadList(G.page); loadStats();
    });
}
// ── 組合件編輯 Modal：子件 BOM/訂單輔助函式 ─────────
// 載入子件 BOM 下拉
function _geLoadBomSelect(sid, did, selectedBom){
    ajx({action:'search_bom', term:'', d_id:did}, function(r){
        var $sel = $('#grp-edit-preview-tbody').find('[data-sid="'+sid+'"].ge-bom-sel');
        var h = '<option value="">— 無BOM —</option>';
        (r.data||[]).forEach(function(b){
            var tc=parseFloat(b.total_cost||0), sq=parseFloat(b.sqty||0);
            var tag = tc>0?'（$'+(sq>0?(tc/sq).toFixed(1):tc.toFixed(1))+'）':'（無成本）';
            h += '<option value="'+esc(b.bom)+'"'+(b.bom===selectedBom?' selected':'')+'>'+esc(b.bom)+tag+'</option>';
        });
        $sel.html(h);
        if(selectedBom) $sel.val(selectedBom);
        // 同步 hidden value
        $sel.closest('tr').find('.ge-bom-val').val($sel.val()||'');
    });
} 

// 載入子件訂單列表 (下拉式)
function _geLoadOrderSelect(sid, dsid, bom, selectedOrderId){
    ajx({action:'get_orders_for_edit', d_id: dsid, bom: bom}, function(r){
        var $sel = $('#grp-edit-preview-tbody').find('[data-sid="'+sid+'"].ge-order-sel');
        var h = '<option value="">— 無訂單 —</option>';
        if(r.success && r.orders && r.orders.length > 0){
            r.orders.forEach(function(o){
                var ooo = o.Order_oo ? o.Order_oo.replace(/OO(\d{3})(\d{2})(\d{2})\d{3}/i,'$1-$2$3') : o.Order_id;
                var label = ooo + ' x' + (o.my_allocated || o.Qty) + ' (' + (o.Delivery_date||'').substring(5) + ')';
                h += '<option value="'+o.Order_id+'" '+(o.is_bound?'selected':'')+'>'+esc(label)+'</option>';
            });
            h += '<option value="B" '+(selectedOrderId == 'B'?'selected':'')+'>備庫</option>';
        } else {
            h = '<option value="">無訂單</option><option value="B" '+(selectedOrderId == 'B'?'selected':'')+'>備庫</option>';
        }
        $sel.html(h);
        if(selectedOrderId) $sel.val(selectedOrderId);
        $sel.closest('tr').find('.ge-order-id').val($sel.val()||'');
    });
}

// BOM 下拉選擇後同步 hidden value
function geOnBomChange(sel){
    var sid = $(sel).data('sid');
    var bom = $(sel).val();
    var did = $(sel).closest('tr').find('.ge-did').val();
    $(sel).closest('tr').find('.ge-bom-val').val(bom||'');
    // BOM 變更後重抓該料號的訂單
    _geLoadOrderSelect(sid, did, bom, '');
}

// ── 一般件入庫：BOM 下拉按鈕 ──────────────────────
var G_inBomList = [];
function toggleInBomDrop(){
    var $drop = $('#in-bom-drop');
    if($drop.is(':visible')){ $drop.hide(); return; }
    $drop.show();
    if(G_inBomList.length === 0) _loadInBomList();
}
function _loadInBomList(){
    var did = $('#in-item-id').data('did') || '';
    $('#in-bom-drop').html('<div style="padding:8px 12px;font-size:11px;color:#aaa;text-align:center;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>');
    ajx({action:'search_bom', term:'', d_id:did}, function(r){
        G_inBomList = r.data || [];
        _renderInBomDrop(G_inBomList);
    });
}
function _renderInBomDrop(list){
    if(!list.length){
        $('#in-bom-drop').html('<div style="padding:10px 14px;font-size:12px;color:#aaa;text-align:center;">此料號尚無BOM資料</div>');
        return;
    }
    var h = '';
    list.forEach(function(b){
        var tc=parseFloat(b.total_cost||0), sq=parseFloat(b.sqty||0);
        var costTag = tc>0 ? '&nbsp;<span style="color:#27ae60;">$'+(sq>0?(tc/sq).toFixed(2):tc.toFixed(2))+'/件</span>' : '';
        h += '<div class="in-bom-item" data-bom="'+esc(b.bom)+'" style="padding:7px 14px;border-bottom:1px solid #f5f5f5;cursor:pointer;font-size:12px;">'
           + '<i class="fa fa-link" style="color:var(--accent);margin-right:6px;"></i><strong>'+esc(b.bom)+'</strong>'+costTag+'</div>';
    });
    $('#in-bom-drop').html(h);
    $('#in-bom-drop .in-bom-item').off('click').on('click', function(){
        var bom = $(this).data('bom');
        $('#in-bom').val(bom);
        $('#in-bom-drop').hide();
        $('#in-bom-info').html('<i class="fa fa-check-circle" style="color:var(--accent);"></i> 已選BOM：<strong>'+esc(bom)+'</strong>');
        _loadInOrderDropForBom(bom);
    });
}
function clearInBom(){
    $('#in-bom').val('');
    $('#in-bom-drop').hide();
    $('#in-bom-info').html('');
    G_inBomList = [];
    clearInOrder();
}

// ── 一般件入庫：訂單下拉按鈕 ──────────────────────
var G_inOrderList = [];
function toggleInOrderDrop(){
    var $drop = $('#in-order-drop');
    if($drop.is(':visible')){ $drop.hide(); return; }
    $drop.show();
    var did = $('#in-item-id').data('did') || '';
    _loadInOrderDrop(did, '');
}
function _loadInOrderDropForBom(bom){
    var did = $('#in-item-id').data('did') || '';
    $('#in-order-drop').html('<div style="padding:8px 12px;font-size:11px;color:#aaa;text-align:center;"><i class="fa fa-spinner fa-spin"></i> 載入關聯訂單...</div>').show();
    _loadInOrderDrop(did, bom);
}
function _loadInOrderDrop(did, term){
    ajx({action:'search_order', term:term||'', d_id:did}, function(r){
        G_inOrderList = r.data || [];
        _renderInOrderDrop(G_inOrderList);
    });
}
function _renderInOrderDrop(list){
    if(!list.length){
        $('#in-order-drop').html('<div style="padding:10px 14px;font-size:12px;color:#aaa;text-align:center;">查無對應訂單</div>');
        return;
    }
    var h = '';
    list.forEach(function(o){
        var status = o.Order_status ? '<span style="color:#e74c3c;font-size:10px;margin-left:4px;">['+esc(o.Order_status)+']</span>' : '<span style="color:#27ae60;font-size:10px;margin-left:4px;">[進行中]</span>';
        var price = o.unit_price ? '&nbsp;<span style="color:#27ae60;">$'+parseFloat(o.unit_price).toLocaleString()+'</span>' : '';
        var dateStr = (o.Order_date||'').substring(0,10);
        h += '<div class="in-order-item" data-oid="'+o.Order_id+'" data-ono="'+esc(o.Order_oo)+'" style="padding:7px 14px;border-bottom:1px solid #f5f5f5;cursor:pointer;font-size:12px;">'
           + '<div><strong>'+esc(o.Order_oo)+'</strong>'+status+price+'</div>'
           + '<div style="font-size:10px;color:#888;">料號：'+esc(o.d_id)+'&nbsp;'+esc(o.Client_name||'')+(dateStr?'&nbsp;'+dateStr:'')+'</div>'
           + '</div>';
    });
    $('#in-order-drop').html(h);
    $('#in-order-drop .in-order-item').off('click').on('click', function(){
        var oid = $(this).data('oid');
        var ono = $(this).data('ono');
        $('#in-order-id').val(oid);
        $('#in-order-disp').val(ono);
        $('#in-order-drop').hide();
    });
}
function clearInOrder(){
    $('#in-order-id').val('');
    $('#in-order-disp').val('');
    $('#in-order-drop').hide();
    G_inOrderList = [];
}
// ── 異動歷程拆分（A權限）────────────────────────
var G_splitRowCount = 0;

function updateSplitSelection(){
    var checked = $('.txn-split-chk:checked');
    var n = checked.length;
    if(n > 0){
        $('#split-sel-count').text(n + ' 筆已選');
        $('#split-txn-toolbar').css('display','flex');
    } else {
        $('#split-sel-count').text('');
    }
}

function clearTxnSelection(){
    $('.txn-split-chk').prop('checked', false);
    updateSplitSelection();
}

// ── 取得單一品項批次 ──
function _loadItemOutBatches(row){
    $('#grp-txn-batches').html('<div style="padding:16px;text-align:center;color:#aaa;font-size:12px;"><i class="fa fa-spinner fa-spin"></i> 載入批次...</div>');
    ajx({action:'get_item_batches', stock_item_id: row.stock_item_id}, function(r){
        if(!r.success||!r.batches||!r.batches.length){
            $('#grp-txn-batches').html('<div style="padding:16px;text-align:center;color:#aaa;font-size:12px;">無可用批次（庫存為 0 或無入庫記錄）</div>');
            return;
        }
        G_grpTxnBatches = r.batches;
        var tbl = '<table style="width:100%;border-collapse:collapse;">'
            + '<thead><tr style="background:#fff8f8;">'
            + '<th style="padding:7px 10px;font-size:11px;width:30px;"><input type="checkbox" id="gtxn-chk-all" onchange="gtxnToggleAll(this.checked)" checked></th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;">批次日期</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;">BOM</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;">訂單</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;">備註</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;text-align:right;">剩餘</th>'
            + '<th style="padding:7px 10px;font-size:11px;color:#c0392b;text-align:right;">出庫數量</th>'
            + '</tr></thead><tbody>';
        r.batches.forEach(function(b, i){
            tbl += '<tr style="border-top:1px solid #fde8e8;" data-batch-idx="'+i+'">'
                + '<td style="padding:7px 10px;"><input type="checkbox" class="gtxn-batch-chk" data-idx="'+i+'" checked onchange="gtxnUpdateSummary()"></td>'
                + '<td style="padding:7px 10px;font-size:12px;font-weight:600;">'+esc(b.txn_date)+'</td>'
                + '<td style="padding:7px 10px;font-size:11px;color:var(--accent);">'+(b.txn_bom?'<i class="fa fa-link"></i> '+esc(b.txn_bom):'<span style="color:#ccc;">—</span>')+'</td>'
                + '<td style="padding:7px 10px;font-size:11px;color:var(--info);">'+(b.txn_order_no?'<i class="fa fa-shopping-cart"></i> '+esc(b.txn_order_no):'<span style="color:#ccc;">—</span>')+'</td>'
                + '<td style="padding:7px 10px;font-size:11px;color:#666;max-width:120px;">'+(b.remark?esc(b.remark):'<span style="color:#ccc;">—</span>')+'</td>'
                + '<td style="padding:7px 10px;font-size:12px;text-align:right;">'+b.sets+'</td>'
                + '<td style="padding:7px 10px;text-align:right;"><input type="number" class="form-control gtxn-batch-qty" data-idx="'+i+'" value="" min="0" max="'+b.sets+'" step="0.0001" style="width:85px;display:inline-block;height:28px;text-align:right;" placeholder="0" oninput="gtxnUpdateSummary()"></td>'
                + '</tr>';
        });
        $('#grp-txn-batches').html(tbl + '</tbody></table>');
        gtxnUpdateSummary();
    });
}

function openSplitFromTxn(){
    var checked = $('.txn-split-chk:checked');
    if(checked.length < 1){ toast('請至少勾選 1 筆異動記錄進行拆分','error'); return; }

    var d = G._detailData;
    if(!d || !d.data){ toast('找不到品項資料','error'); return; }
    var stockItemId = d.current_id || d.data.stock_item_id;
    var origQty = parseFloat(d.data.qty || 0);
    var unitStr = (d.data.unit_symbol || d.data.unit_name) ? (' ' + esc(d.data.unit_symbol || d.data.unit_name)) : '';

    $('#split-stock-item-id').val(stockItemId);
    $('#split-orig-info').html(
        '<strong>料號：</strong>' + esc(d.data.d_id) +
        ' &nbsp;|&nbsp; <strong>原始庫存：</strong><strong style="color:var(--primary);">' + origQty + unitStr + '</strong>' +
        ' &nbsp;|&nbsp; <strong>目前儲位：</strong>' + esc(d.data.location_code || d.data.storage_location || '—')
    );

    // 初始化儲位下拉
    var lh = '<option value="">— 選擇目標儲位 —</option>';
    G.allLocs.forEach(function(l){ lh += '<option value="'+l.location_id+'">'+(l.area?'['+esc(l.area)+'] ':'')+esc(l.location_code)+'</option>'; });
    $('#split-target-loc-id').html(lh);
    
    // 初始化單位下拉
    var uh = '<option value="">主單位</option>';
    G.allUnits.forEach(function(u){ uh += '<option value="'+u.unit_id+'">'+esc(u.unit_name)+'</option>'; });
    $('#split-target-unit-id').html(uh).val(d.data.unit_id || '');

    $('#split-total-confirm').val('');

    G_splitRowCount = 0;
    $('#split-rows-wrap').html('');

    var latestLocId = null;
    var selectedTxns = [];
    checked.each(function(i){
        if (i === 0) latestLocId = $(this).data('loc-id'); // 第一筆即為最新紀錄
        selectedTxns.push({
            id:    $(this).data('txn-id'),
            loc:   $(this).data('loc') || '',
            type:  $(this).data('type') || '',
            qty:   parseFloat($(this).data('qty') || 0),
            remark: $(this).data('remark') || ''
        });
    });

    selectedTxns.forEach(function(r){ addSplitRow(r.loc, r.qty, r.remark, r.id, r.type); });
    
    // 自動選取最新紀錄的儲位
    if (latestLocId) $('#split-target-loc-id').val(latestLocId).trigger('change');

    updateSplitQtySummary(origQty, unitStr);
    $('#detailModal').modal('hide');
    setTimeout(function(){ $('#splitTxnModal').modal('show'); }, 400);
}

function addSplitRow(prefillLoc, prefillQty, prefillRemark, txnId, txnType){
    G_splitRowCount++;
    var idx = G_splitRowCount;

    var html = '<div class="split-row-item" id="split-row-' + idx + '" style="background:#fff;border:1px solid #e0e4ea;border-radius:6px;padding:10px 14px;margin-bottom:8px;">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">'
        +   '<span style="font-size:12px;font-weight:700;color:var(--primary);">拆分筆 #' + idx + '</span>'
        + '</div>'
        + '<div class="row" style="align-items:flex-end;">'
        +   '<div class="col-sm-3"><div style="font-size:11px;color:#888;">原儲位</div><div style="font-size:12px;">' + esc(prefillLoc || '—') + '</div></div>'
        +   '<div class="col-sm-6"><div style="font-size:11px;color:#888;">備註</div><div style="font-size:11px;color:#aaa;">' + esc(prefillRemark || '(無)') + '</div></div>'
        +   '<div class="col-sm-3" style="text-align:right;">'
        +     '<div style="font-size:11px;color:#888;">紀錄數量</div>'
        +     '<div style="font-size:13px;font-weight:700;color:' + (prefillQty >= 0 ? 'var(--accent)' : 'var(--danger)') + ';">' + (prefillQty > 0 ? '+' : '') + prefillQty + '</div>'
        +     '<input type="hidden" class="row-txn-id" value="' + txnId + '">'
        +     '<input type="hidden" class="row-type" value="' + txnType + '">'
        +     '<input type="hidden" class="row-qty" value="' + prefillQty + '">'
        +   '</div>'
        + '</div>'
        + '</div>';

    $('#split-rows-wrap').append(html);
    _refreshSplitLastTag();
    updateSplitQtySummary();
}

function _refreshSplitLastTag(){
    $('.split-row-item [id^="split-row-last-tag-"]').hide();
    var allRows = $('.split-row-item');
    if(allRows.length > 0){
        allRows.last().find('[id^="split-row-last-tag-"]').show();
    }
}

function updateSplitQtySummary(){
    // 改從異動歷程重新加總，避免 stock_items.qty 欄位與紀錄不符的誤差
    var origQty = 0;
    if(G._detailData && G._detailData.transactions) {
        G._detailData.transactions.forEach(function(t){
            // 移位不計入庫存增減，僅入庫/出庫/盤點/調整會計入
            if(t.txn_type !== 'move') origQty += parseFloat(t.txn_qty || 0);
        });
    } else {
        origQty = (G._detailData && G._detailData.data) ? parseFloat(G._detailData.data.qty || 0) : 0;
    }
    origQty = Math.round(origQty * 10000) / 10000;

    var unitStr = '';
    if(G._detailData && G._detailData.data){
        var d = G._detailData.data;
        unitStr = (d.unit_symbol || d.unit_name) ? (' ' + esc(d.unit_symbol || d.unit_name)) : '';
    }

    var total = 0;
    $('.split-row-item').each(function(){
        var type = $(this).find('.row-type').val();
        if(type !== 'move') {
            total = Math.round((total + parseFloat($(this).find('.row-qty').val() || 0)) * 10000) / 10000;
        }
    });

    var bucketRemain = Math.round((origQty - total) * 10000) / 10000;

    $('#split-qty-summary')
        .html('<i class="fa fa-calculator"></i> 搬移紀錄總量：<strong style="color:var(--accent); font-size:15px;">' + total + unitStr + '</strong>' +
              ' &nbsp;|&nbsp; 原品項剩餘庫存將為：<strong>' + bucketRemain + unitStr + '</strong>');
    
    // 自動填入確認總數（方便使用者核對）
    $('#split-total-confirm').val(total);
    $('#split-remain-confirm').val(bucketRemain);
}

function confirmSplitTxn(){
    if(!G._isAdminUser){ toast('需要 A 級權限','error'); return; }
    var stockItemId = $('#split-stock-item-id').val();
    if(!stockItemId){ toast('找不到品項','error'); return; }

    var targetLocId = $('#split-target-loc-id').val();
    if(!targetLocId){ toast('請選擇移入儲位','error'); return; }
    
    var targetUnitId = $('#split-target-unit-id').val();
    var remainConfirm = parseFloat($('#split-remain-confirm').val() || 0);
    var totalConfirm = parseFloat($('#split-total-confirm').val() || 0);
    if(!totalConfirm || totalConfirm <= 0){ toast('請輸入總數確認','error'); return; }

    var txnIds = [];
    var totalQty = 0;
    $('.split-row-item').each(function(){
        txnIds.push($(this).find('.row-txn-id').val());
        totalQty = Math.round((totalQty + parseFloat($(this).find('.row-qty').val() || 0)) * 10000) / 10000;
    });
    
    // 移除嚴格等量限制。若不符則僅提示，不中斷操作。
    if(totalConfirm > 0 && Math.abs(totalQty - totalConfirm) > 0.00011){
        if(!confirm('確認總數 (' + totalConfirm + ') 與選取紀錄總計 (' + totalQty + ') 不符，是否仍要依輸入數量搬移並建立庫存？\n(這將會修正該品項的帳面誤差)')) return;
        if(!confirm('確認總數 (' + totalConfirm + ') 與選取紀錄總計 (' + totalQty + ') 不符，是否仍要依輸入數量搬移並建立庫存？\n(這將會修正該品項的帳面誤差，單位將設為：' + $('#split-target-unit-id option:selected').text() + ')')) return;
    } else {
        if(!confirm('確定要搬移選取的 ' + txnIds.length + ' 筆紀錄至新庫存品項？\n\n新儲位：' + $('#split-target-loc-id option:selected').text() + '\n搬移總量：' + totalConfirm + '\n\n此操作不可復原。')) return;
        if(!confirm('確定要搬移選取的 ' + txnIds.length + ' 筆紀錄至新庫存品項？\n\n新儲位：' + $('#split-target-loc-id option:selected').text() + '\n搬移總量：' + totalConfirm + ' ' + $('#split-target-unit-id option:selected').text() + '\n\n此操作不可復原。')) return;
    }

    ajx({
        action: 'split_txn_items',
        stock_item_id: stockItemId,
        txn_ids: JSON.stringify(txnIds),
        target_location_id: targetLocId,
        target_location_code: $('#split-target-loc-code').val(),
        target_unit_id: targetUnitId,
        confirm_total_qty: totalConfirm,
        remain_total_qty: remainConfirm
    }, function(r){
        if(!r.success){ toast(r.message || '拆分失敗','error'); return; }
        $('#splitTxnModal').modal('hide');
        toast(r.message || '拆分成功','success');
        loadList(G.page); loadStats();
    });
}

// ══════════════════════════════════════════════════════
// ── 領庫需求單 JS ────────────────────────────────────
// ══════════════════════════════════════════════════════
var _reqSearchTimer = null;
var _reqCreateItems = []; // [{stock_item_id,d_id,client_name,storage_location,qty,current_qty,remark,is_urgent}]
var _reqSearchResults = []; // 暫存搜尋結果，供 addReqItem(index) 使用
var _crClientSuggestions = [];
var _editClientSuggestions = [];
var _crClientAcTimer = null;
var _editClientAcTimer = null;

function loadRequisitions(p){
    G.req.page = p || 1;
    var activeBtn = $('.req-status-btn.btn-primary');
    G.req.status = activeBtn.length ? (activeBtn.data('status')??'') : '0';
    G.req.kw = $('#req-filter-kw').val().trim();
    ajx({action:'get_requisitions', status:G.req.status===''?'all':G.req.status, kw:G.req.kw, page:G.req.page, page_size:G.req.pageSize}, function(r){
        if(!r.success){ toast(r.message||'載入失敗','error'); return; }
        var tb = $('#req-tbody'); tb.empty();
        if(!r.rows||!r.rows.length){ tb.html('<tr><td colspan="10" class="text-center text-muted" style="padding:20px;">目前沒有符合的需求單</td></tr>'); $('#req-pager').html(''); return; }
        var statusLabel = [
            '<span style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:13px;font-weight:700;background:#e8e8e8;color:#555;">待出庫</span>',
            '<span style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:13px;font-weight:700;background:#fff3cd;color:#856404;">部分出庫</span>',
            '<span style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:13px;font-weight:700;background:#d4edda;color:#155724;">已完成</span>'
        ];
        r.rows.forEach(function(row){
            var st = parseInt(row.status)||0;
            var modAt = (row.Modified_At||'').substr(0,16);
            var crAt = (row.Created_At||'').substr(0,16);
            tb.append('<tr id="req-row-'+row.req_id+'">'
                +'<td style="width:28px;text-align:center;">'
                  +'<button class="btn btn-xs btn-default req-expand-btn" onclick="toggleReqExpand('+row.req_id+',this)" title="展開/收起" style="padding:2px 6px;">'
                  +'<i class="fa fa-chevron-right"></i></button></td>'
                +'<td><a href="#" onclick="openReqDetail('+row.req_id+');return false;" style="font-weight:600;">'+esc(row.req_no)+'</a></td>'
                +'<td>'+esc(row.title||'')+'</td>'
                +'<td>'+esc(row.dept_name||'')+'</td>'
                +'<td>'+esc(row.requester_name||row.creator_name||'')+'</td>'
                +'<td>'+(statusLabel[st]||'')+'</td>'
                +'<td>'+esc(row.item_cnt||0)+' 項'+(parseInt(row.urgent_cnt||0)>0?' <span class="label label-danger" style="font-size:10px;"><i class="fa fa-bolt"></i> '+row.urgent_cnt+'急</span>':'')+(row.done_cnt>0?' <small class="text-muted">('+row.done_cnt+'完)</small>':'')+'</td>'
                +'<td style="font-size:12px;white-space:nowrap;">'+esc(crAt)+'</td>'
                +'<td style="font-size:12px;white-space:nowrap;color:'+(modAt!==crAt?'var(--accent)':'#aaa')+';">'+esc(modAt)+'</td>'
                +'<td><button class="btn btn-xs btn-info" onclick="openReqDetail('+row.req_id+')" title="查看"><i class="fa fa-eye"></i></button>'
                +(hasP('C')||hasP('A')?'<button class="btn btn-xs btn-danger" style="margin-left:3px;" onclick="deleteReq('+row.req_id+')" title="刪除"><i class="fa fa-trash"></i></button>':'')
                +'</td></tr>');
        });
        $('#req-pager').html(buildPager(r.page, r.pages, 'loadRequisitions'));
    });
}

function setReqPageSize(sz){
    G.req.pageSize = parseInt(sz)||20;
    loadRequisitions(1);
}

function toggleReqExpand(reqId, btn){
    var mainRow = $('#req-row-'+reqId);
    var subId = 'req-sub-'+reqId;
    var sub = $('#'+subId);
    var ico = $(btn).find('i');
    if(sub.length){
        if(sub.is(':visible')){
            sub.hide();
            ico.removeClass('fa-chevron-down').addClass('fa-chevron-right');
        } else {
            sub.show();
            ico.removeClass('fa-chevron-right').addClass('fa-chevron-down');
        }
        return;
    }
    // 尚未載入，fetch detail
    ico.removeClass('fa-chevron-right').addClass('fa-spinner fa-spin');
    ajx({action:'get_req_detail', req_id:reqId}, function(r){
        ico.removeClass('fa-spinner fa-spin').addClass('fa-chevron-down');
        if(!r.success){ toast('載入失敗','error'); ico.removeClass('fa-chevron-down').addClass('fa-chevron-right'); return; }
        var req=r.req;
        var isDeleted=parseInt(req.is_active)===0;
        var h='<tr id="'+subId+'"><td style="padding:0;border-top:none;"></td>'
            +'<td colspan="9" style="padding:0;border-top:none;">'
            +'<div style="background:#f4f6f9;border-left:3px solid #7b9ccc;padding:8px 14px 10px;margin:0;">';
        if(isDeleted) h+='<div style="margin-bottom:8px;font-size:12px;color:#c0392b;background:#fff0f0;border-radius:4px;padding:4px 10px;display:inline-block;"><i class="fa fa-trash"></i> 已刪除'+(req.delete_reason?' — '+esc(req.delete_reason):'')+'</div>';
        if(!req.items||!req.items.length){
            h+='<span class="text-muted" style="font-size:12px;">無料號明細</span>';
        } else {
            h+='<div style="display:flex;flex-direction:column;gap:5px;">';
            req.items.forEach(function(it){
                var done=parseFloat(it.qty_issued)>=parseFloat(it.qty_requested);
                var partial=!done&&parseFloat(it.qty_issued)>0;
                var borderColor=done?'#27ae60':partial?'#f39c12':'#adb5bd';
                var isUrg=parseInt(it.is_urgent)===1;
                var isGrp=!!it.group_id;
                // 出庫進度比例
                var pct=parseFloat(it.qty_requested)>0?Math.min(100,Math.round(parseFloat(it.qty_issued)/parseFloat(it.qty_requested)*100)):0;
                var progressBar='<div style="height:4px;background:#e9ecef;border-radius:2px;margin-top:4px;width:100px;">'
                    +'<div style="height:4px;background:'+(done?'#27ae60':partial?'#f39c12':'#dee2e6')+';border-radius:2px;width:'+pct+'%;"></div></div>';
                h+='<div style="display:flex;align-items:center;gap:10px;background:#fff;border-left:3px solid '+borderColor+';border-radius:0 4px 4px 0;padding:6px 10px;'+(isUrg?'box-shadow:inset 0 0 0 1px #f8d7da;':'')+'">'
                  // 料號 + 客戶
                  +'<div style="min-width:130px;">'
                    +(isUrg?'<span style="font-size:10px;color:#c0392b;font-weight:700;"><i class="fa fa-bolt"></i> 急件</span><br>':'')
                    +'<span style="font-weight:700;font-size:13px;">'+esc(it.d_id||'')+'</span>'
                    +(isGrp?'<span style="font-size:9px;background:#e67e22;color:#fff;padding:1px 4px;border-radius:3px;margin-left:4px;"><i class="fa fa-cubes"></i> 組合件</span>':'')
                    +(it.client_name?'<div style="font-size:11px;color:#888;margin-top:1px;">'+esc(it.client_name)+'</div>':'')
                  +'</div>'
                  // 種類
                  +'<div style="min-width:60px;">'+catBadgeHtml(it.category_name||'', 'font-size:10px;')+'</div>'
                  // 申請 / 出庫 / 未出庫 / 進度
                  +'<div style="min-width:160px;">'
                    +'<div style="font-size:11px;color:#666;">申請 <strong style="font-size:13px;color:#333;">'+esc(it.qty_requested)+(isGrp?' 組':'')+'</strong>'
                      +' → 已出庫 <strong style="font-size:13px;color:'+(done?'#27ae60':partial?'#e67e22':'#999')+';">'+esc(it.qty_issued)+'</strong></div>'
                    +'<div style="font-size:11px;margin-top:2px;">未出庫 <strong style="color:'+(done?'#aaa':'var(--info)')+';">'+(parseFloat(it.qty_requested)-parseFloat(it.qty_issued||0))+'</strong></div>'
                    +progressBar
                  +'</div>'
                  // 庫存
                  +'<div style="min-width:70px;text-align:center;">'
                    +'<div style="font-size:10px;color:#888;">庫存</div>'
                    +'<div style="font-size:13px;font-weight:600;">'+esc(it.current_qty||0)+'</div>'
                  +'</div>'
                  // 狀態
                  +'<div style="min-width:60px;text-align:center;">'
                    +(done
                      ?'<span style="font-size:11px;background:#d4edda;color:#155724;padding:2px 8px;border-radius:10px;font-weight:600;">完成</span>'
                      :partial
                        ?'<span style="font-size:11px;background:#fff3cd;color:#856404;padding:2px 8px;border-radius:10px;font-weight:600;">部分</span>'
                        :'<span style="font-size:11px;background:#e9ecef;color:#555;padding:2px 8px;border-radius:10px;">待出庫</span>')
                  +'</div>'
                  // 備註
                  +(it.item_remark?'<div style="font-size:11px;color:#888;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(it.item_remark)+'"><i class="fa fa-comment-o"></i> '+esc(it.item_remark)+'</div>':'')
                +'</div>';
            });
            h+='</div>';
        }
        h+='</div></td></tr>';
        mainRow.after(h);
    });
}

function expandAllReq(){
    $('.req-expand-btn').each(function(){
        var sub=$('#'+$(this).closest('tr').attr('id').replace('req-row-','req-sub-'));
        if(sub.length&&!sub.is(':visible')){
            sub.show();
            $(this).find('i').removeClass('fa-chevron-right').addClass('fa-chevron-down');
        } else if(!sub.length){
            toggleReqExpand($(this).closest('tr').attr('id').replace('req-row-',''), this);
        }
    });
}
function collapseAllReq(){
    $('[id^="req-sub-"]').hide();
    $('.req-expand-btn i').removeClass('fa-chevron-down fa-spinner fa-spin').addClass('fa-chevron-right');
}

function openCreateReqModal(){
    _reqCreateItems = [];
    $('#cr-title,#cr-remark,#cr-item-search').val('');
    $('#cr-filter-client,#cr-filter-client-val').val(''); $('#cr-client-ac').hide().empty(); $('#cr-filter-cat').val('');
    $('#cr-item-results').hide().empty();
    $('#cr-user-display').val(CURRENT_USER_NAME||'—');
    renderCreateReqItems();
    if(CURRENT_USER_DEPTS.length===1){
        // 只有一個部門 — 不可更動
        $('#cr-dept').html('<option value="'+CURRENT_USER_DEPTS[0].id+'">'+esc(CURRENT_USER_DEPTS[0].name)+'</option>')
            .val(CURRENT_USER_DEPTS[0].id).prop('disabled',true);
    } else {
        var h='';
        CURRENT_USER_DEPTS.forEach(function(d){ h+='<option value="'+d.id+'">'+esc(d.name)+'</option>'; });
        $('#cr-dept').html(h).prop('disabled',false);
        if(CURRENT_USER_DEPTS.length>0) $('#cr-dept').val(CURRENT_USER_DEPTS[0].id);
    }
    $('#createReqModal').modal('show');
}

function loadReqUsers(){ /* 保留供 dept 切換用，但申請人已改為唯讀 */ }

function catBadgeHtml(name, extraStyle){
    if(!name) return '';
    var cs=['#337ab7','#5cb85c','#f0ad4e','#9b59b6','#e67e22','#1abc9c','#c0392b','#2980b9'];
    var h=0; for(var i=0;i<name.length;i++) h=(Math.imul(31,h)+name.charCodeAt(i))|0;
    var bg=cs[Math.abs(h)%cs.length];
    return '<span class="label" style="background:'+bg+';color:#fff;font-size:10px;'+(extraStyle||'')+'">'+esc(name)+'</span>';
}
// 依儲位名稱產生固定色（用於出庫 modal 標示不同儲位）
var _locPalette=[
    {badge:'#1a78c2',row:'#e8f4fd'},{badge:'#27ae60',row:'#e8f5e9'},
    {badge:'#e67e22',row:'#fff3e0'},{badge:'#8e44ad',row:'#f3e5f5'},
    {badge:'#c0392b',row:'#fce4ec'},{badge:'#16a085',row:'#e0f7fa'},
    {badge:'#d35400',row:'#fff8e1'},{badge:'#2c3e50',row:'#ecf0f1'}
];
function locColorFor(loc){
    if(!loc) return _locPalette[0];
    var h=0; for(var i=0;i<loc.length;i++) h=(Math.imul(31,h)+loc.charCodeAt(i))|0;
    return _locPalette[Math.abs(h)%_locPalette.length];
}

function searchReqItems(){
    clearTimeout(_reqSearchTimer);
    var kw = $('#cr-item-search').val().trim();
    var clientFilter = ($('#cr-filter-client-val').val()||$('#cr-filter-client').val()||'').trim();
    var catFilter = parseInt($('#cr-filter-cat').val()||0)||0;
    if(!kw && !clientFilter && !catFilter){ $('#cr-item-results').hide().empty(); return; }
    _reqSearchTimer = setTimeout(function(){
        ajx({action:'req_search_items', kw:kw, client_filter:clientFilter, cat_filter:catFilter}, function(r){
            var box = $('#cr-item-results');
            if(!r.success||!r.items||!r.items.length){ box.html('<div style="padding:8px 12px;color:#999;font-size:12px;">無符合結果</div>').show(); return; }
            _reqSearchResults = r.items;
            var h='';
            r.items.forEach(function(it,idx){
                var locStr = it.storage_location ? '<span style="color:#999;"> | 儲位：'+esc(it.storage_location)+'</span>' : '';
                var remStr = it.remark1 ? '<span style="color:#bbb;"> | '+esc(it.remark1)+'</span>' : '';
                var unitStr = it.unit_label ? ' '+esc(it.unit_label) : '';
                var catBadge = catBadgeHtml(it.category_name, 'margin-left:6px;vertical-align:middle;');
                var isGrp = !!it.is_group_item;
                var grpMark = isGrp ? ' <span class="label" style="font-size:9px;background:#e67e22;color:#fff;padding:1px 5px;"><i class="fa fa-cubes"></i> 組合件</span>' : '';
                var qtyLabel = isGrp ? '可用 '+esc(it.qty||0)+' 組' : '庫存 '+esc(it.qty||0)+unitStr;
                h+='<div class="req-item-ac" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;'+(isGrp?'background:#fffaf5;':'')+'" '
                  +'onmousedown="addReqItemByIdx('+idx+');">'
                  +'<strong style="font-size:13px;">'+esc(it.d_id)+'</strong>'+catBadge+grpMark
                  +(it.client_name?' <span style="color:#555;"> — '+esc(it.client_name)+'</span>':'')
                  +'<span style="float:right;color:var(--accent);font-weight:600;">'+qtyLabel+'</span>'
                  +(locStr||remStr?'<div style="font-size:11px;color:#999;margin-top:2px;overflow:hidden;">'+locStr+remStr+'</div>':'')
                  +'</div>';
            });
            box.html(h).show();
        });
    }, 280);
}

function addReqItemByIdx(idx){ addReqItem(_reqSearchResults[idx]); }

function addReqItem(it){
    $('#cr-item-results').hide(); $('#cr-item-search').val('');
    if(_reqCreateItems.length>=40){ toast('每張需求單最多40筆','error'); return; }
    var isGrp=!!it.is_group_item;
    for(var i=0;i<_reqCreateItems.length;i++){
        // 同料號+客戶+種類+類型（單品/組合件）→ 不可重複加入（搜尋結果已合併同組，此檢查防止重複點選）
        if(_reqCreateItems[i].d_id===it.d_id && (_reqCreateItems[i].client_name||'')===(it.client_name||'') && (_reqCreateItems[i].category_name||'')===(it.category_name||'') && !!_reqCreateItems[i].is_group_item===isGrp){ toast('該料號已在清單中','info'); return; }
    }
    _reqCreateItems.push({stock_item_id:it.stock_item_id, d_id:it.d_id, client_name:it.client_name||'', storage_location:it.storage_location||'', current_qty:parseFloat(it.qty||it.total_qty||0), unit_label:it.unit_label||'', category_name:it.category_name||'', group_qty:parseFloat(it.group_qty||0), is_group_item:isGrp, qty:1, remark:'', is_urgent:0});
    renderCreateReqItems();
}

function clearReqKw(){
    $('#req-filter-kw').val('');
    loadRequisitions();
}

function clearReqSearch(){
    $('#cr-item-search').val('');
    $('#cr-item-results').hide().empty();
}

function clearReqFilters(){
    $('#cr-filter-client').val(''); $('#cr-filter-client-val').val(''); $('#cr-client-ac').hide().empty();
    $('#cr-filter-cat').val(''); $('#cr-item-search').val('');
    $('#cr-item-results').hide().empty();
}

function crClientAcInput(){
    clearTimeout(_crClientAcTimer);
    $('#cr-filter-client-val').val('');
    var term=$('#cr-filter-client').val().trim();
    if(term.length<1){ $('#cr-client-ac').hide().empty(); searchReqItems(); return; }
    _crClientAcTimer=setTimeout(function(){
        ajx({action:'search_customer', term:term}, function(r){
            if(!r.success||!r.data||!r.data.length){ $('#cr-client-ac').hide().empty(); searchReqItems(); return; }
            _crClientSuggestions=r.data;
            var h='';
            r.data.forEach(function(c,i){
                h+='<div style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #eee;font-size:12px;" onmousedown="selectCrClientByIdx('+i+');">'
                  +'<strong>'+esc(c.customer_id)+'</strong>'+(c.customer?' — '+esc(c.customer):'')+'</div>';
            });
            $('#cr-client-ac').html(h).show();
        });
    }, 280);
}

function selectCrClientByIdx(i){
    var c=_crClientSuggestions[i]; if(!c) return;
    $('#cr-filter-client').val(c.customer||c.customer_id);
    $('#cr-filter-client-val').val(c.customer||c.customer_id);
    $('#cr-client-ac').hide().empty();
    searchReqItems();
}

function setReqStatusFilter(status, btn){
    $('.req-status-btn').removeClass('btn-primary').addClass('btn-default');
    $(btn).removeClass('btn-default').addClass('btn-primary');
    loadRequisitions();
}

// ── 列印篩選結果 ──────────────────────────────────
function printAllReq(){
    var activeBtn=$('.req-status-btn.btn-primary');
    var status=activeBtn.length?(activeBtn.data('status')??''):'0';
    var kw=$('#req-filter-kw').val().trim();
    var statusLabel=['待出庫','部分出庫','已完成'];
    var filterDesc='篩選：'+(status===''||status==='all'?'全部':statusLabel[parseInt(status)]||'')+(kw?' ／ 關鍵字：'+kw:'');
    ajx({action:'print_requisitions', status:status===''?'all':status, kw:kw}, function(r){
        if(!r.success){ toast(r.message||'載入失敗，無法列印','error'); return; }
        var reqs=r.reqs||[];
        if(!reqs.length){ toast('目前篩選條件下沒有需求單','info'); return; }
        var sLabel=['待出庫','部分出庫','已完成'];
        var sColor=['#6c757d','#856404','#155724'];
        var sBg=['#e8e8e8','#fff3cd','#d4edda'];
        var now=new Date().toLocaleString('zh-TW');
        var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>領庫需求單列印</title>'
            +'<style>body{font-family:"微軟正黑體",Arial,sans-serif;font-size:12px;color:#333;margin:10px 20px;}'
            +'table{width:100%;border-collapse:collapse;margin-bottom:6px;}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;}'
            +'th{background:#f5f5f5;font-weight:700;}tr:nth-child(even){background:#fafafa;}'
            +'.req-hd{background:#2A3F54;color:#fff;padding:6px 10px;border-radius:4px 4px 0 0;display:flex;justify-content:space-between;align-items:center;}'
            +'.req-block{margin-bottom:16px;border:1px solid #ccc;border-radius:4px;page-break-inside:avoid;}'
            +'.status-pill{padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;}'
            +'.pg-hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #2A3F54;padding-bottom:6px;margin-bottom:12px;}'
            +'@media print{body{margin:5mm 8mm;}@page{size:A4;margin:10mm;}'
            +'.no-print{display:none;}}'
            +'</style></head><body>';
        html+='<div class="pg-hd"><div><strong style="font-size:16px;">領庫需求單</strong><br><small style="color:#888;">'+filterDesc+'</small></div>'
            +'<div style="text-align:right;font-size:11px;color:#888;">列印時間：'+now+'<br>共 '+reqs.length+' 筆</div></div>';
        html+='<div class="no-print" style="margin-bottom:12px;"><button onclick="window.print()" style="padding:6px 18px;background:#2A3F54;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;">🖨 列印</button>'
            +'<button onclick="window.close()" style="padding:6px 14px;border:1px solid #ccc;border-radius:4px;cursor:pointer;margin-left:8px;font-size:13px;">✕ 關閉</button></div>';
        reqs.forEach(function(req, idx){
            var st=parseInt(req.status)||0;
            html+='<div class="req-block">'
                +'<div class="req-hd"><span><strong>'+esc(req.req_no)+'</strong>'+(req.title?' — '+esc(req.title):'')+'</span>'
                +'<span class="status-pill" style="background:'+sBg[st]+';color:'+sColor[st]+';border:1px solid '+sColor[st]+';">'+sLabel[st]+'</span></div>'
                +'<div style="padding:6px 10px 0;display:flex;gap:20px;flex-wrap:wrap;font-size:11px;color:#555;border-bottom:1px solid #eee;padding-bottom:6px;">'
                +'<span>部門：<strong>'+esc(req.dept_name||'—')+'</strong></span>'
                +'<span>申請人：<strong>'+esc(req.requester_name||req.creator_name||'—')+'</strong></span>'
                +'<span>建立：'+esc((req.Created_At||'').substr(0,16))+'</span>'
                +(req.issued_at?'<span>出庫：'+esc((req.issued_at||'').substr(0,16))+'</span>':'')
                +'</div>';
            if(req.items&&req.items.length){
                html+='<table><thead><tr><th style="width:24px;">#</th><th>料號</th><th>客戶</th><th>種類</th><th>儲位</th><th>申請量</th><th>已出庫</th><th>未出庫</th><th>備註</th></tr></thead><tbody>';
                req.items.forEach(function(it,ii){
                    var done=parseFloat(it.qty_issued)>=parseFloat(it.qty_requested);
                    var rem=parseFloat(it.qty_requested)-parseFloat(it.qty_issued||0);
                    html+='<tr style="'+(done?'background:#e8f5e9;background:#e8f5e9;':'')+(ii%2===1&&!done?'background:#fafafa;':'')+'">'
                        +'<td>'+(ii+1)+'</td>'
                        +'<td><strong>'+(it.is_urgent?'⚡ ':'')+esc(it.d_id||'')+'</strong></td>'
                        +'<td>'+esc(it.client_name||'')+'</td>'
                        +'<td>'+esc(it.category_name||'')+'</td>'
                        +'<td style="font-size:11px;">'+esc(it.storage_location||'')+'</td>'
                        +'<td>'+esc(it.qty_requested)+'</td>'
                        +'<td>'+esc(it.qty_issued||0)+'</td>'
                        +'<td style="'+(rem>0?'color:#1a78c2;font-weight:700;':'color:#aaa;')+'">'+rem+'</td>'
                        +'<td style="font-size:11px;">'+esc(it.item_remark||'')+'</td></tr>';
                });
                html+='</tbody></table>';
            } else {
                html+='<div style="padding:8px 10px;color:#aaa;font-size:11px;">無料號明細</div>';
            }
            html+='</div>';
        });
        // 頁碼（CSS counter）
        html+='<style>@media print{'
            +'body::after{content:"第 "counter(page)" 頁 / 共 "counter(pages)" 頁";position:fixed;bottom:5mm;right:8mm;font-size:10px;color:#888;}'
            +'@page{counter-increment:page;}}</style>';
        html+='</body></html>';
        var win=window.open('','_blank','width=900,height=700,scrollbars=yes');
        if(!win){ toast('請允許彈出視窗以列印','warning'); return; }
        win.document.write(html); win.document.close();
    });
}

// 讓 loadRequisitions 讀 btn-group active 狀態


function renderCreateReqItems(){
    var tb = $('#cr-items-tbody'); tb.empty();
    $('#cr-item-count-num').text(_reqCreateItems.length);
    if(!_reqCreateItems.length){ tb.html('<tr id="cr-empty-row"><td colspan="9" class="text-center text-muted" style="padding:20px;">尚未加入任何料號</td></tr>'); return; }
    _reqCreateItems.forEach(function(it,i){
        var isGrp=!!it.is_group_item;
        var qtyMax=parseFloat(it.current_qty||0);
        var over=parseFloat(it.qty||1)>qtyMax;
        var grpBadge=isGrp?'<span class="label" style="font-size:9px;background:#e67e22;color:#fff;padding:1px 4px;margin-left:4px;"><i class="fa fa-cubes"></i> 組合件</span>':'';
        var qtyUnit=isGrp?'<small class="text-muted"> 組</small>':'';
        tb.append('<tr>'
            +'<td><strong>'+esc(it.d_id)+'</strong>'+grpBadge+'</td>'
            +'<td>'+esc(it.client_name)+'</td>'
            +'<td>'+catBadgeHtml(it.category_name||'—', 'font-size:11px;')+'</td>'
            +'<td style="font-size:11px;color:#888;">'+esc(it.storage_location)+'</td>'
            +'<td style="'+(over?'color:var(--danger);font-weight:700;':'')+'">'+esc(it.current_qty)+(isGrp?' <small class="text-muted">組</small>':(it.unit_label?' <small class="text-muted">'+esc(it.unit_label)+'</small>':''))
              +(over?'<div style="font-size:10px;"><i class="fa fa-exclamation-triangle"></i> 超量</div>':'')+'</td>'
            +'<td><input type="text" inputmode="decimal" class="form-control input-sm cr-qty no-spin" data-i="'+i+'" value="'+it.qty+'" style="width:75px;'+(over?'background:#ffe0e0;border-color:var(--danger);':'')+'" oninput="crQtyChange(this,'+i+','+parseFloat(it.current_qty||0)+')">'+(isGrp?'<small class="text-muted"> 組</small>':'')+'</td>'
            +'<td><input type="text" class="form-control input-sm cr-remark" data-i="'+i+'" value="'+esc(it.remark)+'" placeholder="備註" style="min-width:160px;width:100%;" oninput="_reqCreateItems['+i+'].remark=this.value"></td>'
            +'<td style="text-align:center;"><input type="checkbox" class="cr-urgent" data-i="'+i+'"'+(it.is_urgent?' checked':'')+' onchange="_reqCreateItems['+i+'].is_urgent=this.checked?1:0" title="急件"></td>'
            +'<td><button class="btn btn-xs btn-danger" onclick="removeReqItem('+i+')"><i class="fa fa-times"></i></button></td>'
        +'</tr>');
    });
}

function removeReqItem(i){ _reqCreateItems.splice(i,1); renderCreateReqItems(); }

function crQtyChange(el, idx, maxQty){
    var q=parseFloat(el.value)||0;
    _reqCreateItems[idx].qty=q||1;
    var over=maxQty>0&&q>maxQty;
    el.style.background=over?'#ffe0e0':'';
    el.style.borderColor=over?'var(--danger)':'';
}
function newEditQtyChange(el, ni, maxQty){
    var q=parseFloat(el.value)||0;
    _editReqNewItems[ni].qty=q||1;
    refreshEditCount();
    var over=maxQty>0&&q>maxQty;
    el.style.background=over?'#ffe0e0':'';
    el.style.borderColor=over?'var(--danger)':'';
}

function submitCreateReq(){
    var deptId=$('#cr-dept').val();
    var deptName=$('#cr-dept option:selected').text().trim();
    if(!deptId){ toast('請選擇申請部門','error'); return; }
    var title=$('#cr-title').val().trim(); var remark=$('#cr-remark').val().trim();
    if(!_reqCreateItems.length){ toast('請至少加入一項料號','error'); return; }
    var overItems=_reqCreateItems.filter(function(it){ return parseFloat(it.qty||1)>parseFloat(it.current_qty||0); });
    if(overItems.length){ toast('有 '+overItems.length+' 筆申請量超過總庫存（'+(overItems.map(function(it){return it.d_id;}).join('、'))+'），請修改後再提交','error'); return; }
    ajx({action:'create_requisition', dept_id:deptId, dept_name:deptName, user_id:CURRENT_USER_ID, requester_name:CURRENT_USER_NAME, title:title, remark:remark, items:JSON.stringify(_reqCreateItems)}, function(r){
        if(!r.success){ toast(r.message||'建立失敗','error'); return; }
        toast('需求單 '+r.req_no+' 已建立','success');
        $('#createReqModal').modal('hide');
        loadRequisitions(1);
    });
}

function openReqDetail(reqId){
    G.req.currentReqId = reqId;
    $('#req-detail-body').html('<div class="text-center text-muted" style="padding:30px;">載入中...</div>');
    $('#req-detail-badges').empty();
    $('#btn-open-issue,#btn-edit-req').hide();
    $('#reqDetailModal').modal('show');
    ajx({action:'get_req_detail', req_id:reqId}, function(r){
        if(!r.success){ $('#req-detail-body').html('<div class="text-center text-muted" style="padding:30px;">載入失敗</div>'); return; }
        var req=r.req; G.req.currentReq=req;
        var statusLabel=['<span class="label label-default">待出庫</span>','<span class="label label-warning">部分出庫</span>','<span class="label label-success">已完成</span>'];
        var isDeleted = parseInt(req.is_active)===0;
        var st=parseInt(req.status)||0;
        var badges=statusLabel[st]||'';
        if(isDeleted) badges='<span style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:13px;font-weight:700;background:#f8d7da;color:#721c24;"><i class="fa fa-trash"></i> 已刪除</span>';
        var hasUrgent=(req.items||[]).some(function(it){ return parseInt(it.is_urgent)===1; });
        if(!isDeleted&&hasUrgent) badges+=' <span class="label label-danger"><i class="fa fa-bolt"></i> 含急件</span>';
        $('#req-detail-badges').html(badges);
        var canIssue=!isDeleted&&(hasP('A')||(hasP('C')&&hasP('U')&&hasP('D'))) && st<2;
        var canEdit=!isDeleted&&(hasP('A')||(parseInt(req.Created_By)===CURRENT_USER_ID)) && st<2;
        var h='';
        if(isDeleted) h='<div class="alert alert-danger" style="padding:8px 12px;margin-bottom:8px;font-size:13px;"><i class="fa fa-trash"></i> 此需求單已被刪除'+(req.delete_reason?' — 原因：'+esc(req.delete_reason):'')+(req.deleted_at?' （'+esc((req.deleted_at||'').substr(0,16))+'）':'')+'</div>';
        h+='<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;background:#f8f9fa;border-radius:6px;padding:10px;">'
            +'<div><small style="color:#888;">需求單號</small><div style="font-weight:700;">'+esc(req.req_no)+'</div></div>'
            +'<div><small style="color:#888;">申請部門</small><div>'+esc(req.dept_name||'—')+'</div></div>'
            +'<div><small style="color:#888;">申請人</small><div>'+esc(req.requester_name||req.creator_name||'—')+'</div></div>'
            +'<div><small style="color:#888;">標題</small><div>'+esc(req.title||'—')+'</div></div>'
            +'<div><small style="color:#888;">備註</small><div>'+esc(req.req_remark||'—')+'</div></div>'
            +'<div><small style="color:#888;">建立時間</small><div>'+esc((req.Created_At||'').substr(0,16))+'</div></div>'
            +'</div>';
        h+='<div style="overflow-x:auto;"><table class="table tbl-sm" style="margin:0;"><thead><tr>'
            +'<th>料號</th><th>客戶</th><th>種類</th><th>儲位</th><th>申請量</th><th>已出庫</th>'
            +'<th style="color:var(--info);" title="申請量-已出庫">未出庫量</th>'
            +'<th>庫存</th><th>狀態</th><th>備註</th></tr></thead><tbody>';
        (req.items||[]).forEach(function(it){
            var done=parseFloat(it.qty_issued)>=parseFloat(it.qty_requested);
            var rem=parseFloat(it.qty_requested)-parseFloat(it.qty_issued||0);
            var urgBadge=parseInt(it.is_urgent)===1?'<span class="label label-danger" style="font-size:10px;padding:1px 4px;"><i class="fa fa-bolt"></i> 急</span> ':'';
            var grpBadgeDtl=it.group_id?'<span class="label" style="font-size:9px;background:#e67e22;color:#fff;padding:1px 4px;margin-left:4px;"><i class="fa fa-cubes"></i> 組合件</span>':'';
            h+='<tr'+(done?' style="background:#f0fff4;"':'')+'>'
                +'<td>'+urgBadge+'<strong>'+esc(it.d_id||'')+'</strong>'+grpBadgeDtl+'</td>'
                +'<td style="font-size:11px;">'+esc(it.client_name||'')+'</td>'
                +'<td>'+catBadgeHtml(it.category_name||'—','font-size:10px;')+'</td>'
                +'<td style="font-size:11px;">'+esc(it.storage_location||'')+'</td>'
                +'<td>'+esc(it.qty_requested)+(it.group_id?'<small class="text-muted"> 組</small>':'')+'</td>'
                +'<td>'+(done?'<span style="color:var(--accent);font-weight:700;">':'')+esc(it.qty_issued)+(done?'</span>':'')+'</td>'
                +'<td style="'+(rem>0?'color:var(--info);font-weight:600;':'color:#aaa;')+'">'+rem+'</td>'
                +'<td>'+esc(it.current_qty||0)+'</td>'
                +'<td>'+(done?'<span class="label label-success">完成</span>':'<span class="label label-default">待出庫</span>')+'</td>'
                +'<td style="font-size:11px;">'+esc(it.item_remark||'')+'</td></tr>';
        });
        h+='</tbody></table></div>';
        $('#req-detail-body').html(h);
        if(canIssue) $('#btn-open-issue').show();
        if(canEdit) $('#btn-edit-req').show();
    });
}

function openIssueModal(){
    var reqId=G.req.currentReqId; if(!reqId) return;
    var req=G.req.currentReq||{};
    // 每次開啟 modal 時重置確認按鈕狀態（防止殘留鎖定狀態）
    $('#issue-req-confirm-btn').prop('disabled', false).html('<i class="fa fa-check"></i> 確認出庫');
    $('#issue-modal-body').html('<div class="text-center text-muted" style="padding:30px;">載入中...</div>');
    $('#issueReqModal').modal('show');
    ajx({action:'get_req_detail', req_id:reqId}, function(r){
        if(!r.success){ $('#issue-modal-body').html('<div class="text-center text-danger" style="padding:20px;">載入失敗</div>'); return; }
        req=r.req; G.req.currentReq=req;
        var allItems=req.items||[];
        G.req.issueBatches={};  // {req_item_id: [{batch_key,available,txn_date,remark,location},...]}
        var pending=allItems.filter(function(it){ return parseFloat(it.qty_issued)<parseFloat(it.qty_requested); });
        // 基本資訊欄
        var h='<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">'
            +'<div style="flex:0 0 140px;"><label style="font-size:11px;color:#888;margin:0;">出庫日期</label>'
            +'<input type="date" id="issue-txn-date" class="form-control input-sm" value="'+new Date().toISOString().substr(0,10)+'"></div>'
            +'<div style="flex:0 0 120px;"><label style="font-size:11px;color:#888;margin:0;">領用部門</label>'
            +'<input type="text" id="issue-dept-name" class="form-control input-sm" value="'+esc(req.dept_name||'')+'" readonly style="background:#f5f5f5;"></div>'
            +'<div style="flex:0 0 120px;"><label style="font-size:11px;color:#888;margin:0;">領用人</label>'
            +'<input type="text" id="issue-user-name" class="form-control input-sm" value="'+esc(req.requester_name||'')+'" readonly style="background:#f5f5f5;"></div>'
            +'</div>';
        // 工具列
        h+='<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">'
            +'<div class="btn-group btn-group-sm"><button class="btn btn-default" onclick="issueSelectAll(true)"><i class="fa fa-check-square-o"></i> 全選</button><button class="btn btn-default" onclick="issueSelectAll(false)"><i class="fa fa-square-o"></i> 取消全選</button></div>'
            +'<button class="btn btn-sm btn-info" onclick="issueAutoFill()"><i class="fa fa-magic"></i> 自動填入（依需求量，僅勾選項）</button>'
            +'<span style="font-size:11px;color:#888;">· 已出庫項目顯示為灰色</span>'
            +'</div>';
        // 表格（13欄）
        h+='<div style="overflow-x:auto;"><table class="table tbl-sm" style="margin:0;min-width:920px;">'
            +'<thead><tr><th style="width:28px;"><input type="checkbox" id="issue-chk-all" onchange="issueSelectAll(this.checked)"></th>'
            +'<th>料號</th><th>客戶</th><th>種類</th><th>申請量</th><th>已出庫</th>'
            +'<th style="color:var(--info);" title="申請量 - 已出庫">未出庫量</th>'
            +'<th>庫存</th><th>儲位</th><th>備註</th>'
            +'<th>批次日期 / 備註</th><th title="該批次剩餘可用量（FIFO）">批次餘量</th>'
            +'<th style="width:100px;">本次出庫量</th></tr></thead>'
            +'<tbody id="issue-items-tbody"></tbody></table></div>';
        // 已完成的項目（灰色顯示）
        var doneItems=allItems.filter(function(it){ return parseFloat(it.qty_issued)>=parseFloat(it.qty_requested); });
        if(doneItems.length){
            h+='<div style="margin-top:8px;padding:8px;background:#f8f9fa;border-radius:4px;font-size:11px;color:#888;">'
                +'<strong>已完成出庫（'+doneItems.length+'筆）：</strong> '
                +doneItems.map(function(it){ return esc(it.d_id)+'('+it.qty_issued+'/'+it.qty_requested+')'; }).join('、')+'</div>';
        }
        $('#issue-modal-body').html(h);
        if(!pending.length){ $('#issue-items-tbody').html('<tr><td colspan="13" class="text-center text-muted" style="padding:16px;">所有料號已完成出庫</td></tr>'); return; }
        // 逐項載入批次
        pending.forEach(function(it){
            var remaining=parseFloat(it.qty_requested)-parseFloat(it.qty_issued||0);
            var urgBadge=parseInt(it.is_urgent)===1?'<span class="label label-danger" style="font-size:10px;padding:1px 3px;"><i class="fa fa-bolt"></i></span> ':'';
            var grpBadgeIssue=it.group_id?'<span class="label" style="font-size:9px;background:#e67e22;color:#fff;padding:1px 4px;margin-left:4px;"><i class="fa fa-cubes"></i> 組合件</span>':'';
            var qtyLow=parseFloat(it.current_qty||0)<remaining;
            var remStyle=remaining>0?'color:var(--info);font-weight:600;':'color:#aaa;';
            // 佔位列（10固定欄 + colspan=3佔位）
            $('#issue-items-tbody').append('<tr id="issue-row-'+it.req_item_id+'" class="issue-item-pending" data-remaining="'+remaining+'">'
                +'<td><input type="checkbox" class="issue-chk" data-rid="'+it.req_item_id+'" checked></td>'
                +'<td>'+urgBadge+'<strong>'+esc(it.d_id)+'</strong>'+grpBadgeIssue+'</td>'
                +'<td style="font-size:11px;">'+esc(it.client_name||'')+'</td>'
                +'<td>'+catBadgeHtml(it.category_name||'—','font-size:10px;')+'</td>'
                +'<td>'+it.qty_requested+(it.group_id?'<small class="text-muted"> 組</small>':'')+'</td>'
                +'<td>'+esc(it.qty_issued||0)+'</td>'
                +'<td style="'+remStyle+'">'+remaining+'</td>'
                +'<td>'+(qtyLow?'<span style="color:var(--danger);font-weight:700;">':'')+esc(it.current_qty||0)+(qtyLow?'</span>':'')+'</td>'
                +'<td style="font-size:11px;color:#555;">'+esc(it.storage_location||'—')+'</td>'
                +'<td style="font-size:11px;color:#777;">'+esc(it.item_remark||'')+'</td>'
                +'<td colspan="3" class="text-muted issue-batch-placeholder" style="font-size:11px;">載入批次中...</td>'
            +'</tr>');
            // AJAX 取批次
            if(it.stock_item_id){
                (function(it, remaining){
                    ajx({action:'req_get_batches', stock_item_id:it.stock_item_id}, function(br){
                        // ══ DEBUG：批次來源詳情（可在 F12 Console 查看）══
                        console.group('[DEBUG] req_get_batches ─ 料號:'+it.d_id+' | req_item_id='+it.req_item_id+' | stock_item_id='+it.stock_item_id+'（來自 stock_requisition_items.stock_item_id）');
                        console.log('  請求參數: stock_item_id='+it.stock_item_id);
                        console.log('  伺服器回傳: success='+br.success+', total_qty='+br.total_qty+', is_group_item='+br.is_group_item);
                        if(br.batches&&br.batches.length){
                            console.log('  批次數量: '+br.batches.length+' 筆（含所有同料號同客戶儲位之 FIFO 剩餘）');
                            br.batches.forEach(function(b,bi){
                                console.log('  批次['+bi+']'
                                    +' stock_item_id='+b.stock_item_id+'（stock_items.stock_item_id）'
                                    +' | 日期='+b.txn_date+'（stock_transactions.txn_date）'
                                    +' | FIFO剩餘='+b.available
                                    +' | 儲位='+(b.location||'—')+'（stock_locations.location_code / stock_items.storage_location）'
                                    +' | BOM='+(b.bom_ref||'—')+'（stock_transactions.bom_ref → stock_items.bom_ref 兜底）'
                                    +' | 訂單='+(b.order_ref||'—')+'（order_track.Order_oo，依 stock_transactions.order_ref 關聯）'
                                    +' | 備註='+(b.remark||'—')+'（stock_transactions.remark）'
                                    +' | batch_key='+b.batch_key
                                );
                            });
                        } else {
                            console.warn('  ⚠ 無批次資料，使用兜底值（current_qty='+it.current_qty+'）');
                        }
                        console.groupEnd();
                        // ══ END DEBUG ══
                        var isGrpBatch=!!(br.is_group_item);
                        var batches=br.success&&br.batches&&br.batches.length?br.batches:[{batch_key:'SID:'+it.stock_item_id+'|synthetic',txn_date:'—',remark:'（原始庫存）',available:parseFloat(it.current_qty||0),location:''}];
                        G.req.issueBatches[it.req_item_id]=batches;
                        var row=$('#issue-row-'+it.req_item_id);
                        var firstBatch=batches[0];
                        var unitLbl=isGrpBatch?' <small style="color:#e67e22;">(組)</small>':'';
                        var placeholderLbl=isGrpBatch?'0 組':'0';
                        // 判斷是否有多個不同儲位（用於決定是否顯示儲位提示）
                        var locSet={};
                        batches.forEach(function(b){ if(b.location) locSet[b.location]=1; });
                        var multiLoc=Object.keys(locSet).length>1;
                        // 批次附加資訊（備註、BOM、訂單）—— 同單品出庫格式，不在每列顯示儲位 badge
                        var mkBatchMeta=function(b){
                            var parts=[];
                            if(b.bom_ref) parts.push('<i class="fa fa-cogs" style="color:#888;"></i> BOM:'+esc(b.bom_ref));
                            if(b.order_ref) parts.push('<i class="fa fa-file-text-o" style="color:#888;"></i> 訂單#'+esc(b.order_ref));
                            if(b.remark&&b.remark.indexOf('原始庫存')<0) parts.push('<i class="fa fa-comment-o" style="color:#aaa;"></i> '+esc(b.remark));
                            return parts.length?'<span style="font-size:10px;color:#888;margin-left:4px;vertical-align:middle;">'+parts.join(' ')+'</span>':'';
                        };
                        // 多儲位時顯示細小灰字儲位提示；單一儲位則不顯示（儲位已在「儲位」欄顯示）
                        var mkLocHint=function(loc){
                            if(!multiLoc||!loc) return '';
                            var c=locColorFor(loc);
                            return '<span style="font-size:10px;color:'+c.badge+';border:1px solid '+c.badge+';border-radius:2px;padding:0 3px;margin-right:4px;vertical-align:middle;">'+esc(loc)+'</span>';
                        };
                        // 移除佔位 td，補上 3 個真實欄位（同單品出庫：日期 + 備註/BOM | 餘量 | 輸入）
                        var fLoc=firstBatch.location||'';
                        var fRowBg=multiLoc&&fLoc?(locColorFor(fLoc).row):'';
                        row.find('.issue-batch-placeholder').remove();
                        if(fRowBg) row.css('background',fRowBg);
                        row.append('<td style="font-size:11px;">'+mkLocHint(fLoc)+'<strong style="color:#444;">'+esc(firstBatch.txn_date||'—')+'</strong>'+mkBatchMeta(firstBatch)+'</td>'
                            +'<td>'+esc(firstBatch.available||0)+unitLbl+'</td>'
                            +'<td><input type="text" inputmode="decimal" class="form-control input-sm issue-qty no-spin" data-rid="'+it.req_item_id+'" data-bidx="0" data-remaining="'+remaining+'" value="" style="width:68px;" placeholder="'+placeholderLbl+'"></td>');
                        // 額外批次列（colspan=9 只顯示縮排符號 └，日期+備註放在「批次日期/備註」欄）
                        // ★ 用 .after() 插在上一列正後方，避免多個 AJAX 非同步完成時批次列亂跑到錯誤料號下方
                        var prevRow=row;
                        for(var bi=1;bi<batches.length;bi++){
                            var b=batches[bi];
                            var bLoc=b.location||'';
                            var bRowBg=multiLoc&&bLoc?(locColorFor(bLoc).row):'';
                            var bRowStyle=bRowBg?'background:'+bRowBg+';':'';
                            var $bRow=$('<tr class="issue-batch-row" data-parent="'+it.req_item_id+'" style="'+bRowStyle+'">'
                                +'<td></td><td colspan="9" style="padding-left:20px;font-size:13px;color:#ccc;line-height:1;">└</td>'
                                +'<td style="font-size:11px;">'+mkLocHint(bLoc)+'<strong style="color:#444;">'+esc(b.txn_date||'—')+'</strong>'+mkBatchMeta(b)+'</td>'
                                +'<td>'+esc(b.available||0)+unitLbl+'</td>'
                                +'<td><input type="text" inputmode="decimal" class="form-control input-sm issue-qty no-spin" data-rid="'+it.req_item_id+'" data-bidx="'+bi+'" data-remaining="'+remaining+'" value="" style="width:68px;" placeholder="'+placeholderLbl+'"></td>'
                            +'</tr>');
                            prevRow.after($bRow);
                            prevRow=$bRow;
                        }
                    });
                })(it, remaining);
            } else {
                // 無 stock_item_id，簡單顯示
                G.req.issueBatches[it.req_item_id]=[{batch_key:'synthetic||',txn_date:'—',remark:'',available:parseFloat(it.current_qty||0),location:''}];
                var row=$('#issue-row-'+it.req_item_id);
                row.find('.issue-batch-placeholder').remove();
                row.append('<td>—</td><td>'+esc(it.current_qty||0)+'</td>'
                    +'<td><input type="text" inputmode="decimal" class="form-control input-sm issue-qty no-spin" data-rid="'+it.req_item_id+'" data-bidx="0" data-remaining="'+remaining+'" value="" style="width:90px;" placeholder="0"></td>');
            }
        });
        G.req.issuePendingItems=pending;
    });
}

function issueSelectAll(v){
    $('#issue-chk-all').prop('checked',v);
    $('.issue-chk').prop('checked',v);
    if(!v) $('.issue-qty').val(''); // 取消全選時清空所有輸入
}

// 取消勾選單一項目 → 清空其出庫量輸入
$(document).on('change','.issue-chk',function(){
    var rid=$(this).data('rid');
    if(!$(this).prop('checked')){
        $('.issue-qty[data-rid="'+rid+'"]').val('');
    }
    // 若有項目未勾選，取消全選框勾選
    var allChk=$('.issue-chk').length; var chkd=$('.issue-chk:checked').length;
    $('#issue-chk-all').prop('checked', allChk>0&&chkd===allChk);
});

// 出庫量輸入驗證：不得超過未出庫量
$(document).on('input','.issue-qty',function(){
    var rid=$(this).data('rid');
    var remaining=parseFloat($(this).data('remaining')||$('#issue-row-'+rid).data('remaining')||9999);
    var total=0;
    $('.issue-qty[data-rid="'+rid+'"]').each(function(){ total+=parseFloat($(this).val()||0); });
    if(total>remaining+0.0001){
        var excess=total-remaining;
        var v=parseFloat($(this).val()||0);
        var capped=Math.max(0,Math.round((v-excess)*10000)/10000);
        $(this).val(capped>0?capped:'');
        toast('本次出庫量合計不可超過未出庫量（'+remaining+'）','warning');
    }
});

function issueAutoFill(){
    var req=G.req.currentReq||{}; var allItems=req.items||[];
    G.req.issuePendingItems.forEach(function(it){
        if(!$('.issue-chk[data-rid="'+it.req_item_id+'"]').prop('checked')) return;
        var remaining=parseFloat(it.qty_requested)-parseFloat(it.qty_issued||0);
        var batches=G.req.issueBatches[it.req_item_id]||[];
        var left=remaining;
        batches.forEach(function(b,bi){
            var fill=Math.min(left, parseFloat(b.available||0));
            $('.issue-qty[data-rid="'+it.req_item_id+'"][data-bidx="'+bi+'"]').val(fill>0?fill:'');
            left=Math.max(0,left-fill);
        });
    });
}

function confirmIssueReq(){
    if(!G.req.currentReqId){ toast('找不到需求單','error'); return; }
    var txnDate=$('#issue-txn-date').val();
    if(!txnDate){ toast('請選擇出庫日期','error'); return; }
    var $btn=$('#issue-req-confirm-btn');
    // 防止重複提交：按鈕已鎖定中則直接返回
    if($btn.prop('disabled')) return;
    // 收集有勾選 + 有填數量的項目
    var issueItemsJson=[]; var hasQty=false;
    G.req.issuePendingItems.forEach(function(it){
        if(!$('.issue-chk[data-rid="'+it.req_item_id+'"]').prop('checked')) return;
        var batches=G.req.issueBatches[it.req_item_id]||[];
        var batchData=[];
        batches.forEach(function(b,bi){
            var q=parseFloat($('.issue-qty[data-rid="'+it.req_item_id+'"][data-bidx="'+bi+'"]').val()||0);
            if(q>0) batchData.push({qty:q, batch_key:b.batch_key||'synthetic||'});
        });
        if(batchData.length){ hasQty=true; issueItemsJson.push({req_item_id:it.req_item_id, batches:batchData}); }
    });
    if(!hasQty){ toast('請至少填入一筆出庫數量（需有勾選）','error'); return; }
    if(!confirm('確認執行出庫？此操作將扣減庫存，無法復原')) return;
    // 鎖定按鈕，避免重複點擊造成重複出庫
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中...');
    ajx({action:'issue_requisition', req_id:G.req.currentReqId, txn_date:txnDate, issue_items_json:JSON.stringify(issueItemsJson)}, function(r){
        if(!r.success){
            // 真正的業務錯誤（庫存不足等）→ 解鎖按鈕讓使用者修正後重試
            $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 確認出庫');
            toast(r.message||'出庫失敗','error');
            return;
        }
        // 成功：不解鎖（modal 關閉），並刷新所有相關畫面
        toast('出庫成功，共 '+r.issued_count+' 筆','success');
        $('#issueReqModal').modal('hide');
        openReqDetail(G.req.currentReqId);
        loadRequisitions(G.req.page);
        loadStats(); loadList(G.page);
    });
}

var _deleteReqId = null;
function deleteReq(reqId){
    _deleteReqId = reqId;
    $('#del-req-reason').val('');
    $('#del-req-confirm-input').val('');
    $('#deleteReqModal').modal('show');
    setTimeout(function(){ $('#del-req-reason').focus(); }, 400);
}
function confirmDeleteReq(){
    var confirm = $('#del-req-confirm-input').val().trim();
    var reason = $('#del-req-reason').val().trim();
    if(!reason){ toast('請輸入刪除原因','error'); $('#del-req-reason').focus(); return; }
    if(confirm !== 'Y'){ toast('請輸入大寫 Y 確認刪除','error'); $('#del-req-confirm-input').focus(); return; }
    ajx({action:'delete_requisition', req_id:_deleteReqId, delete_reason:reason}, function(r){
        if(!r.success){ toast(r.message||'刪除失敗','error'); return; }
        toast('已刪除','success');
        $('#deleteReqModal,#reqDetailModal,#issueReqModal').modal('hide');
        G.req.currentReq = null; G.req.currentReqId = null;
        loadRequisitions(G.req.page);
    });
}

function openDeletedReqModal(){
    $('#deleted-req-body').html('<div class="text-center text-muted" style="padding:20px;">載入中...</div>');
    $('#deletedReqModal').modal('show');
    ajx({action:'get_deleted_requisitions'}, function(r){
        if(!r.success){ $('#deleted-req-body').html('<div class="text-center text-danger">載入失敗</div>'); return; }
        if(!r.rows||!r.rows.length){ $('#deleted-req-body').html('<div class="text-center text-muted" style="padding:20px;">無已刪除的需求單</div>'); return; }
        var h='<div style="overflow-x:auto;"><table class="table tbl-sm" style="margin:0;">'
            +'<thead><tr><th>單號</th><th>標題</th><th>申請部門</th><th>申請人</th><th>刪除人</th><th>刪除時間</th><th>刪除原因</th></tr></thead><tbody>';
        r.rows.forEach(function(row){
            h+='<tr>'
                +'<td style="font-size:12px;">'+esc(row.req_no||'')+'</td>'
                +'<td>'+esc(row.title||'')+'</td>'
                +'<td>'+esc(row.dept_name||'')+'</td>'
                +'<td>'+esc(row.requester_name||row.creator_name||'')+'</td>'
                +'<td>'+esc(row.deleted_by_name||'—')+'</td>'
                +'<td style="font-size:11px;white-space:nowrap;">'+esc((row.deleted_at||'').substr(0,16))+'</td>'
                +'<td style="color:#c0392b;">'+esc(row.delete_reason||'')+'</td>'
            +'</tr>';
        });
        h+='</tbody></table></div>';
        $('#deleted-req-body').html(h);
    });
}

var _editReqNewItems = [];   // 新增的料號 (尚未存入DB)
var _editReqDeletedIds = []; // 要刪除的 req_item_id
var _editSearchResults = [];
var _editSearchTimer;

function openEditReqModal(){
    var req=G.req.currentReq;
    if(!req){ toast('請先開啟需求單詳情','error'); return; }
    if(parseInt(req.status)===2){ toast('已完成出庫的需求單不可修改','error'); return; }
    _editReqNewItems=[]; _editReqDeletedIds=[];
    $('#edit-filter-client,#edit-filter-client-val,#edit-item-search').val(''); $('#edit-client-ac,#edit-item-results').hide().empty(); $('#edit-filter-cat').val('');
    // header form
    var h='<div class="row">'
        +'<div class="col-sm-4"><div class="form-group"><label>申請部門</label>'
        +'<input type="text" id="edit-dept" class="form-control input-sm" value="'+esc(req.dept_name||'')+'"></div></div>'
        +'<div class="col-sm-4"><div class="form-group"><label>申請人</label>'
        +'<input type="text" id="edit-requester" class="form-control input-sm" value="'+esc(req.requester_name||req.creator_name||'')+'"></div></div>'
        +'<div class="col-sm-4"><div class="form-group"><label>標題</label>'
        +'<input type="text" id="edit-title" class="form-control input-sm" value="'+esc(req.title||'')+'"></div></div>'
        +'</div>'
        +'<div class="form-group"><label>備註</label>'
        +'<textarea id="edit-remark" class="form-control input-sm" rows="2">'+esc(req.req_remark||'')+'</textarea></div>';
    $('#edit-req-header').html(h);
    $('#edit-item-count-num').text((req.items||[]).length);
    renderEditReqItems(req.items||[]);
    $('#editReqModal').modal('show');
}

function renderEditReqItems(items){
    var tb=$('#edit-items-tbody'); tb.empty();
    var allItems=items.concat(_editReqNewItems);
    $('#edit-item-count-num').text(allItems.length);
    if(!allItems.length){ tb.html('<tr><td colspan="9" class="text-center text-muted" style="padding:16px;">尚無料號</td></tr>'); return; }
    // 既有料號 (DB中)
    items.forEach(function(it){
        var isDeleted=_editReqDeletedIds.indexOf(it.req_item_id)>=0;
        var totalQty=parseFloat(it.current_qty||0);
        var curQ=parseFloat(it.qty_requested||1);
        var over=curQ>totalQty;
        var grpBadgeDB=it.group_id?'<span class="label" style="font-size:9px;background:#e67e22;color:#fff;padding:1px 4px;margin-left:4px;" title="此料號為組合件，不可單獨領取"><i class="fa fa-cubes"></i> 組合件</span>':'';
        tb.append('<tr'+(isDeleted?' style="opacity:.4;text-decoration:line-through;background:#fef9f9;"':over?' style="background:#fff8f8;"':'')+' data-rid="'+it.req_item_id+'">'
            +'<td><strong>'+esc(it.d_id||'')+'</strong>'+grpBadgeDB+'</td>'
            +'<td>'+esc(it.client_name||'')+'</td>'
            +'<td>'+catBadgeHtml(it.category_name||'—','font-size:10px;')+'</td>'
            +'<td style="font-size:11px;">'+esc(it.all_locations||it.storage_location||'')+'</td>'
            +'<td style="'+(over&&!isDeleted?'color:var(--danger);font-weight:700;':'')+'">'+totalQty+(it.group_id?' <small class="text-muted">組</small>':'')
              +(over&&!isDeleted?'<div style="font-size:10px;"><i class="fa fa-exclamation-triangle"></i> 超量</div>':'')+'</td>'
            +'<td>'+(isDeleted?'—':'<input type="text" inputmode="decimal" class="form-control input-sm edit-qty no-spin" value="'+curQ+'" style="width:80px;'+(over?'background:#ffe0e0;border-color:var(--danger);':'')+'" oninput="editQtyChange(this)">'+(it.group_id?'<small class="text-muted"> 組</small>':'')+'</td>')
            +'<td>'+(isDeleted?'—':'<input type="text" class="form-control input-sm edit-item-remark" value="'+esc(it.item_remark||'')+'"></td>')
            +'<td style="text-align:center;">'+(isDeleted?'—':'<input type="checkbox" class="edit-urgent"'+(parseInt(it.is_urgent)===1?' checked':'')+' style="width:16px;height:16px;">') +'</td>'
            +'<td style="text-align:center;">'
              +(isDeleted
                ?'<button class="btn btn-xs btn-default" onclick="restoreEditItem('+it.req_item_id+',this)" title="恢復"><i class="fa fa-undo"></i></button>'
                :'<button class="btn btn-xs btn-danger" onclick="deleteEditItem('+it.req_item_id+',this)" title="刪除"><i class="fa fa-times"></i></button>')
            +'</td></tr>');
    });
    // 新增料號
    _editReqNewItems.forEach(function(it,ni){
        var totalQty=parseFloat(it.current_qty||0);
        var curQ=parseFloat(it.qty||1);
        var over=curQ>totalQty;
        var isGrpNew=!!it.is_group_item;
        var grpBadgeNew=isGrpNew?'<span class="label" style="font-size:9px;background:#e67e22;color:#fff;padding:1px 4px;margin-left:4px;"><i class="fa fa-cubes"></i> 組合件</span>':'';
        tb.append('<tr'+(over?' style="background:#fff8f8;"':'')+' data-new="'+ni+'">'
            +'<td><strong>'+esc(it.d_id||'')+'</strong> <small class="label label-success" style="font-size:9px;">新增</small>'+grpBadgeNew+'</td>'
            +'<td>'+esc(it.client_name||'')+'</td>'
            +'<td>'+catBadgeHtml(it.category_name||'—','font-size:10px;')+'</td>'
            +'<td style="font-size:11px;">'+esc(it.storage_location||'')+'</td>'
            +'<td style="'+(over?'color:var(--danger);font-weight:700;':'')+'">'+totalQty+(isGrpNew?' <small class="text-muted">組</small>':'')
              +(over?'<div style="font-size:10px;"><i class="fa fa-exclamation-triangle"></i> 超量</div>':'')+'</td>'
            +'<td><input type="text" inputmode="decimal" class="form-control input-sm new-edit-qty no-spin" data-ni="'+ni+'" value="'+curQ+'" style="width:80px;'+(over?'background:#ffe0e0;border-color:var(--danger);':'')+'" oninput="newEditQtyChange(this,'+ni+','+totalQty+')">'+(isGrpNew?'<small class="text-muted"> 組</small>':'')+'</td>'
            +'<td><input type="text" class="form-control input-sm new-edit-remark" data-ni="'+ni+'" value="'+esc(it.remark||'')+'"></td>'
            +'<td style="text-align:center;"><input type="checkbox" class="new-edit-urgent" data-ni="'+ni+'"'+(it.is_urgent?' checked':'')+' style="width:16px;height:16px;"></td>'
            +'<td style="text-align:center;"><button class="btn btn-xs btn-danger" onclick="removeNewEditItem('+ni+')"><i class="fa fa-times"></i></button></td>'
        +'</tr>');
    });
}

function editQtyChange(el){
    var row=$(el).closest('tr'); var rid=row.data('rid');
    var req=G.req.currentReq; var it=(req.items||[]).find(function(i){ return i.req_item_id==rid; });
    if(!it) return;
    var over=parseFloat($(el).val())>parseFloat(it.current_qty||0);
    $(el).css({'border-color':over?'var(--danger)':'','background':over?'#ffe0e0':''});
}
function deleteEditItem(rid,btn){ _editReqDeletedIds.push(rid); var req=G.req.currentReq; renderEditReqItems(req.items||[]); }
function restoreEditItem(rid,btn){ _editReqDeletedIds=_editReqDeletedIds.filter(function(d){return d!==rid;}); var req=G.req.currentReq; renderEditReqItems(req.items||[]); }
function removeNewEditItem(ni){ _editReqNewItems.splice(ni,1); var req=G.req.currentReq; renderEditReqItems(req.items||[]); }
function refreshEditCount(){ var req=G.req.currentReq; $('#edit-item-count-num').text(((req.items||[]).length-_editReqDeletedIds.length)+_editReqNewItems.length); }

function searchEditItems(){
    clearTimeout(_editSearchTimer);
    var kw=$('#edit-item-search').val().trim();
    var clientFilter=($('#edit-filter-client-val').val()||$('#edit-filter-client').val()||'').trim();
    var catFilter=parseInt($('#edit-filter-cat').val()||0)||0;
    if(!kw && !clientFilter && !catFilter){ $('#edit-item-results').hide().empty(); return; }
    _editSearchTimer=setTimeout(function(){
        ajx({action:'req_search_items', kw:kw, client_filter:clientFilter, cat_filter:catFilter}, function(r){
            var box=$('#edit-item-results');
            if(!r.success||!r.items||!r.items.length){ box.html('<div style="padding:8px 12px;color:#999;font-size:12px;">無符合結果</div>').show(); return; }
            _editSearchResults=r.items;
            var h='';
            r.items.forEach(function(it,idx){
                var locStr2=it.storage_location?'<span style="color:#999;"> | 儲位：'+esc(it.storage_location)+'</span>':'';
                var remStr2=it.remark1?'<span style="color:#bbb;"> | '+esc(it.remark1)+'</span>':'';
                var unitStr2=it.unit_label?' '+esc(it.unit_label):'';
                var catBadge2=catBadgeHtml(it.category_name,'margin-left:6px;vertical-align:middle;');
                var isGrp2=!!it.is_group_item;
                var grpMark2=isGrp2?' <span class="label" style="font-size:9px;background:#e67e22;color:#fff;padding:1px 5px;"><i class="fa fa-cubes"></i> 組合件</span>':'';
                var qtyLabel2=isGrp2?'可用 '+esc(it.qty||0)+' 組':'庫存 '+esc(it.qty||0)+unitStr2;
                h+='<div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;'+(isGrp2?'background:#fffaf5;':'')+'" onmousedown="addEditItemByIdx('+idx+');">'
                  +'<strong style="font-size:13px;">'+esc(it.d_id)+'</strong>'+catBadge2+grpMark2
                  +(it.client_name?' <span style="color:#555;"> — '+esc(it.client_name)+'</span>':'')
                  +'<span style="float:right;color:var(--accent);font-weight:600;">'+qtyLabel2+'</span>'
                  +(locStr2||remStr2?'<div style="font-size:11px;color:#999;margin-top:2px;overflow:hidden;">'+locStr2+remStr2+'</div>':'')
                  +'</div>';
            });
            box.html(h).show();
        });
    }, 280);
}

function clearEditSearch(){ $('#edit-item-search').val(''); $('#edit-item-results').hide().empty(); }

function clearEditFilters(){
    $('#edit-filter-client').val(''); $('#edit-filter-client-val').val(''); $('#edit-client-ac').hide().empty();
    $('#edit-filter-cat').val(''); $('#edit-item-search').val('');
    $('#edit-item-results').hide().empty();
}

function editClientAcInput(){
    clearTimeout(_editClientAcTimer);
    $('#edit-filter-client-val').val('');
    var term=$('#edit-filter-client').val().trim();
    if(term.length<1){ $('#edit-client-ac').hide().empty(); searchEditItems(); return; }
    _editClientAcTimer=setTimeout(function(){
        ajx({action:'search_customer', term:term}, function(r){
            if(!r.success||!r.data||!r.data.length){ $('#edit-client-ac').hide().empty(); searchEditItems(); return; }
            _editClientSuggestions=r.data;
            var h='';
            r.data.forEach(function(c,i){
                h+='<div style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #eee;font-size:12px;" onmousedown="selectEditClientByIdx('+i+');">'
                  +'<strong>'+esc(c.customer_id)+'</strong>'+(c.customer?' — '+esc(c.customer):'')+'</div>';
            });
            $('#edit-client-ac').html(h).show();
        });
    }, 280);
}

function selectEditClientByIdx(i){
    var c=_editClientSuggestions[i]; if(!c) return;
    $('#edit-filter-client').val(c.customer||c.customer_id);
    $('#edit-filter-client-val').val(c.customer||c.customer_id);
    $('#edit-client-ac').hide().empty();
    searchEditItems();
}

function addEditItemByIdx(idx){
    var it=_editSearchResults[idx];
    $('#edit-item-results').hide(); $('#edit-item-search').val('');
    var req=G.req.currentReq;
    var allItems=(req.items||[]).concat(_editReqNewItems);
    if(allItems.length-_editReqDeletedIds.length>=40){ toast('每張需求單最多40筆','error'); return; }
    var isGrpE=!!it.is_group_item;
    // 同料號+客戶+種類+類型（單品/組合件）→ 不可重複加入
    for(var i=0;i<_editReqNewItems.length;i++){
        if(_editReqNewItems[i].d_id===it.d_id&&(_editReqNewItems[i].client_name||'')===(it.client_name||'')&&(_editReqNewItems[i].category_name||'')===(it.category_name||'')&&!!_editReqNewItems[i].is_group_item===isGrpE){ toast('該料號已在清單中','info'); return; }
    }
    for(var j=0;j<(req.items||[]).length;j++){
        if(_editReqDeletedIds.indexOf(req.items[j].req_item_id)>=0) continue;
        if((req.items[j].d_id===it.d_id)&&((req.items[j].client_name||'')===(it.client_name||''))&&((req.items[j].category_name||'')===(it.category_name||''))&&(!!req.items[j].group_id)===isGrpE){ toast('該料號已在清單中','info'); return; }
    }
    _editReqNewItems.push({stock_item_id:it.stock_item_id, d_id:it.d_id, client_name:it.client_name||'', storage_location:it.storage_location||'', current_qty:parseFloat(it.qty||it.total_qty||0), unit_label:it.unit_label||'', category_name:it.category_name||'', group_qty:parseFloat(it.group_qty||0), is_group_item:isGrpE, qty:1, remark:'', is_urgent:0});
    renderEditReqItems(req.items||[]);
}

function submitEditReq(){
    var req=G.req.currentReq; if(!req){ toast('找不到需求單','error'); return; }
    // 收集既有料號的修改值
    var items=[];
    $('#edit-items-tbody tr[data-rid]').each(function(){
        var rid=$(this).data('rid');
        if(_editReqDeletedIds.indexOf(rid)>=0) return; // 略過刪除項
        items.push({
            req_item_id:parseInt(rid),
            qty_requested:parseFloat($(this).find('.edit-qty').val())||1,
            item_remark:$(this).find('.edit-item-remark').val()||'',
            is_urgent:$(this).find('.edit-urgent').is(':checked')?1:0
        });
    });
    // 同步新增料號的備註/急件
    var newItems=[];
    $('#edit-items-tbody tr[data-new]').each(function(){
        var ni=parseInt($(this).data('new'));
        var it=_editReqNewItems[ni]; if(!it) return;
        newItems.push({
            stock_item_id:it.stock_item_id, d_id:it.d_id, client_name:it.client_name, storage_location:it.storage_location,
            qty:parseFloat($(this).find('.new-edit-qty').val())||1,
            remark:$(this).find('.new-edit-remark').val()||'',
            is_urgent:$(this).find('.new-edit-urgent').is(':checked')?1:0
        });
    });
    // 驗證：超庫存
    var overItems=items.filter(function(it){
        var orig=(req.items||[]).find(function(o){ return o.req_item_id==it.req_item_id; });
        return orig&&parseFloat(it.qty_requested)>parseFloat(orig.current_qty||0);
    }).concat(newItems.filter(function(ni){
        var orig=_editReqNewItems.find(function(o){ return o.d_id===ni.d_id&&o.client_name===ni.client_name; });
        return orig&&parseFloat(ni.qty)>parseFloat(orig.current_qty||0);
    }));
    if(overItems.length){ toast('有料號申請量超過總庫存，請修改後再儲存','error'); return; }
    // 驗證：不得低於已出庫數量
    var underItems=(req.items||[]).filter(function(it){
        if(_editReqDeletedIds.indexOf(it.req_item_id)>=0) return false;
        var found=items.find(function(x){ return x.req_item_id==it.req_item_id; });
        if(!found) return false;
        return parseFloat(found.qty_requested)<parseFloat(it.qty_issued||0);
    });
    if(underItems.length){ toast('料號 '+underItems[0].d_id+' 修改後數量不得低於已出庫量（'+underItems[0].qty_issued+'）','error'); return; }
    ajx({action:'update_requisition', req_id:req.req_id,
        title:$('#edit-title').val(),
        dept_name:$('#edit-dept').val(),
        requester_name:$('#edit-requester').val(),
        req_remark:$('#edit-remark').val(),
        items_json:JSON.stringify(items),
        new_items:JSON.stringify(newItems),
        deleted_ids:JSON.stringify(_editReqDeletedIds)
    }, function(r){
        if(!r.success){ toast(r.message||'儲存失敗','error'); return; }
        toast('修改成功','success');
        $('#editReqModal').modal('hide');
        openReqDetail(req.req_id);
        loadRequisitions(G.req.page);
    });
}

function printRequisition(){
    var req=G.req.currentReq;
    if(!req){ toast('請先開啟需求單詳情','error'); return; }
    var hasUrgent=(req.items||[]).some(function(it){ return parseInt(it.is_urgent)===1; });
    var hdr='<table style="width:100%;margin-bottom:8px;border-collapse:collapse;font-size:10px;">'
        +'<tr>'
        +'<td><strong>需求單號：</strong>'+esc(req.req_no||'—')+'</td>'
        +'<td><strong>申請部門：</strong>'+esc(req.dept_name||'—')+'</td>'
        +'<td><strong>申請人：</strong>'+esc(req.requester_name||req.creator_name||'—')+'</td>'
        +(hasUrgent?'<td style="color:red;font-weight:700;">★ 含急件</td>':'<td></td>')
        +'</tr>'
        +'<tr>'
        +'<td><strong>標題：</strong>'+esc(req.title||'—')+'</td>'
        +'<td colspan="2"><strong>備註：</strong>'+esc(req.req_remark||'—')+'</td>'
        +'<td><strong>日期：</strong>'+(req.Created_At||'').substr(0,10)+'</td>'
        +'</tr>'
        +'</table>';
    var tbody='';
    (req.items||[]).forEach(function(it){
        var urgCell=parseInt(it.is_urgent)===1?'<span style="color:red;font-weight:700;margin-right:3px;">★急</span>':'';
        tbody+='<tr>'
            +'<td>'+urgCell+esc(it.client_name||'')+'</td>'
            +'<td>'+esc(it.d_id||'')+'</td>'
            +'<td>'+esc(it.category_name||'—')+'</td>'
            +'<td>'+esc(it.storage_location||'')+'</td>'
            +'<td style="text-align:center;">'+esc(it.qty_requested)+'</td>'
            +'<td style="text-align:center;">'+esc(it.current_qty||0)+'</td>'
            +'<td></td>'
            +'<td>'+esc(it.item_remark||'')+'</td>'
            +'</tr>';
    });
    var tbl='<table style="width:100%;border-collapse:collapse;white-space:nowrap;">'
        +'<thead><tr style="background:#eee;">'
        +'<th>客戶</th><th>料號</th><th>倉別</th><th>儲位</th>'
        +'<th>申請量</th><th>庫存</th><th style="min-width:60px;">已領貨</th><th>備註</th>'
        +'</tr></thead><tbody>'+tbody+'</tbody></table>';
    var w=window.open('','_blank','width=1100,height=750');
    w.document.write('<html><head><title>領庫需求單 '+esc(req.req_no||'')+'</title><style>'
        +'body{font-family:Arial,sans-serif;font-size:9px;margin:12px;}'
        +'table{width:100%;border-collapse:collapse;}'
        +'th,td{border:1px solid #ccc;padding:3px 5px;white-space:nowrap;}'
        +'th{background:#eee;font-weight:600;}'
        +'@page{size:A4 landscape;margin:10mm;}'
        +'@media print{body{-webkit-print-color-adjust:exact;}}'
        +'</style></head><body>'
        +'<h4 style="margin-bottom:6px;font-size:12px;">領庫需求單</h4>'
        +hdr+tbl
        +'</body></html>');
    w.document.close(); w.focus();
    setTimeout(function(){ w.print(); }, 300);
}

// ══════════════════════════════════════════════════════
// ── 每日入出庫紀錄 JS ────────────────────────────────
// ══════════════════════════════════════════════════════
function initDailyReport(){
    G.rpt.refDate = new Date();
    G.rpt.view = 'day';
    $('.rpt-view-btn').removeClass('btn-primary').addClass('btn-default');
    $('.rpt-view-btn[data-view="day"]').removeClass('btn-default').addClass('btn-primary');
    rptUpdatePeriodLabel();
    loadDailyReport(1);
}

function setRptView(view, btn){
    G.rpt.view=view; G.rpt.refDate=new Date();
    $('.rpt-view-btn').removeClass('btn-primary').addClass('btn-default');
    $(btn).removeClass('btn-default').addClass('btn-primary');
    if(view==='range'){
        $('#rpt-range-wrap').css('display','flex');
        var today=new Date().toISOString().substr(0,10);
        $('#rpt-date-from').val(today); $('#rpt-date-to').val(today);
        $('#rpt-period-label').text('自訂範圍');
    } else {
        $('#rpt-range-wrap').hide();
        rptUpdatePeriodLabel();
        loadDailyReport(1);
    }
}

function rptNavigate(dir){
    var d=G.rpt.refDate;
    if(G.rpt.view==='day')  d.setDate(d.getDate()+dir);
    else if(G.rpt.view==='week') d.setDate(d.getDate()+dir*7);
    else if(G.rpt.view==='month') d.setMonth(d.getMonth()+dir);
    G.rpt.refDate=d;
    rptUpdatePeriodLabel();
    loadDailyReport(1);
}

function rptGetDates(){
    var d=new Date(G.rpt.refDate);
    var f,t;
    if(G.rpt.view==='day'){
        f=t=d.toISOString().substr(0,10);
    } else if(G.rpt.view==='week'){
        var day=d.getDay()||7; var mon=new Date(d); mon.setDate(d.getDate()-day+1);
        var sun=new Date(mon); sun.setDate(mon.getDate()+6);
        f=mon.toISOString().substr(0,10); t=sun.toISOString().substr(0,10);
    } else if(G.rpt.view==='month'){
        var y=d.getFullYear(),m=d.getMonth();
        f=y+'-'+String(m+1).padStart(2,'0')+'-01';
        var last=new Date(y,m+1,0);
        t=y+'-'+String(m+1).padStart(2,'0')+'-'+String(last.getDate()).padStart(2,'0');
    } else { // range
        f=$('#rpt-date-from').val(); t=$('#rpt-date-to').val();
    }
    return {from:f,to:t};
}

function rptUpdatePeriodLabel(){
    var dates=rptGetDates(); var lbl='';
    if(G.rpt.view==='day') lbl=dates.from;
    else if(G.rpt.view==='week') lbl=dates.from+' ~ '+dates.to;
    else if(G.rpt.view==='month') lbl=dates.from.substr(0,7);
    else lbl='自訂範圍';
    $('#rpt-period-label').text(lbl);
}

function loadDailyReport(p){
    G.rpt.page=p||1;
    var dates=rptGetDates();
    G.rpt.dateFrom=dates.from; G.rpt.dateTo=dates.to;
    if(!dates.from||!dates.to){ toast('請選擇日期範圍','error'); return; }
    $('#rpt-tbody').html('<tr><td colspan="14" class="text-center text-muted">載入中...</td></tr>');
    ajx({action:'get_daily_report', date_from:dates.from, date_to:dates.to, page:G.rpt.page, page_size:G.rpt.pageSize||20}, function(r){
        if(!r.success){ toast(r.message||'載入失敗','error'); $('#rpt-tbody').html('<tr><td colspan="14" class="text-center text-danger">載入失敗</td></tr>'); return; }
        var s=r.stats||{};
        $('#rpt-in-cnt').text(fmt(s.in_cnt||0));
        $('#rpt-in-qty').text(fmt(s.in_qty||0));
        $('#rpt-out-cnt').text(fmt(s.out_cnt||0));
        $('#rpt-out-qty').text(fmt(s.out_qty||0));
        var tb=$('#rpt-tbody'); tb.empty();
        if(!r.rows||!r.rows.length){ tb.html('<tr><td colspan="14" class="text-center text-muted" style="padding:20px;">此期間無異動記錄</td></tr>'); $('#rpt-pager').html(''); return; }
        r.rows.forEach(function(row){
            var isIn=row.txn_type==='in'; var qty=parseFloat(row.txn_qty||0);
            var catBadge=row.category_name?'<small class="label label-default" style="font-size:10px;margin-left:3px;">'+esc(row.category_name)+'</small>':'';
            tb.append('<tr>'
                +'<td>'+esc(row.txn_date||'')+'</td>'
                +'<td>'+(isIn?'<span class="label label-success">入庫</span>':'<span class="label label-danger">出庫</span>')+'</td>'
                +'<td>'+esc(row.d_id||'')+'</td>'
                +'<td>'+catBadge+'</td>'
                +'<td>'+esc(row.client_name||'')+'</td>'
                +'<td>'+esc(row.location_code||row.storage_location||'')+'</td>'
                +'<td style="font-weight:700;color:'+(isIn?'var(--accent)':'var(--danger)')+';">'+(isIn?'+':'-')+Math.abs(qty)+'</td>'
                +'<td>'+esc(row.qty_before||'')+'</td>'
                +'<td>'+esc(row.qty_after||'')+'</td>'
                +'<td>'+esc(row.total_d_id_qty||'')+'</td>'
                +'<td>'+esc(row.out_dept_name||'')+'</td>'
                +'<td>'+esc(row.out_user_name||'')+'</td>'
                +'<td>'+esc(row.creator_name||'')+'</td>'
                +'<td>'+esc(row.remark||'')+'</td>'
            +'</tr>');
        });
        $('#rpt-pager').html(buildPager(r.page, r.pages, 'loadDailyReport'));
    });
}

function setRptPageSize(ps){
    G.rpt.pageSize=parseInt(ps)||20;
    loadDailyReport(1);
}

function printDailyReport(){
    var dates=rptGetDates();
    var label=$('#rpt-period-label').text()||dates.from;
    var title='入出庫紀錄 '+label;
    // 重新建立列印表格（含重命名欄位）
    var thead='<tr><th>異動日期</th><th>類型</th><th>料號</th><th>種類</th><th>客戶</th><th>儲位</th>'
        +'<th>數量</th><th>異動前</th><th>異動後</th><th>料號總庫存</th>'
        +'<th>請領部門</th><th>請領人</th><th>倉管</th><th>備註</th></tr>';
    var tbody=$('#rpt-tbody').html();
    var tbl='<table><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table>';
    var w=window.open('','_blank','width=1200,height=750');
    w.document.write('<html><head><title>'+title+'</title><style>'
        +'body{font-family:Arial,sans-serif;font-size:9px;margin:12px;}'
        +'table{width:100%;border-collapse:collapse;white-space:nowrap;}'
        +'th,td{border:1px solid #ccc;padding:3px 5px;}'
        +'th{background:#f5f5f5;font-weight:600;}'
        +'.label{display:inline-block;padding:1px 4px;border-radius:3px;font-size:9px;}'
        +'.label-success{background:#5cb85c;color:#fff;}'
        +'.label-danger{background:#d9534f;color:#fff;}'
        +'.label-default{background:#888;color:#fff;}'
        +'@page{size:A4 landscape;margin:8mm;}'
        +'@media print{body{-webkit-print-color-adjust:exact;}}'
        +'</style></head><body>'
        +'<h4 style="margin-bottom:6px;font-size:12px;">'+title+'</h4>'
        +tbl
        +'</body></html>');
    w.document.close(); w.focus();
    setTimeout(function(){ w.print(); }, 300);
}

// ── 工具函式 ────────────────────────────────────
function buildPager(cur, total, fn){
    if(total<=1) return '';
    var h='<button '+(cur===1?'disabled':'')+' onclick="'+fn+'('+Math.max(1,cur-1)+')"><i class="fa fa-chevron-left"></i></button>';
    var s=Math.max(1,cur-2), e=Math.min(total,cur+2);
    if(s>1){ h+='<button onclick="'+fn+'(1)">1</button>'; if(s>2) h+='<button disabled style="cursor:default;">…</button>'; }
    for(var i=s;i<=e;i++) h+='<button class="'+(i===cur?'active':'')+'" onclick="'+fn+'('+i+')">'+i+'</button>';
    if(e<total){ if(e<total-1) h+='<button disabled style="cursor:default;">…</button>'; h+='<button onclick="'+fn+'('+total+')">'+total+'</button>'; }
    h+='<button '+(cur===total?'disabled':'')+' onclick="'+fn+'('+Math.min(total,cur+1)+')"><i class="fa fa-chevron-right"></i></button>';
    return h;
}
function debounce(fn, ms) {
    var t;
    return function() { var a=arguments, th=this; clearTimeout(t); t=setTimeout(function(){ fn.apply(th, a); }, ms); };
}
function ajx(data,cb){ $.ajax({url:window.location.href,type:'POST',data:data,dataType:'json',success:cb,error:function(){ if(cb) cb({success:false,message:'網路錯誤'}); }}); }
function esc(s){ if(s===null||s===undefined)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function fmt(n){ return parseInt(n||0).toLocaleString(); }
function toast(msg,type){
    var el=$('<div class="toast-msg '+(type||'')+'">').text(msg);
    $('#toast-wrap').append(el);
    setTimeout(function(){ el.fadeOut(300,function(){el.remove();}); },3000);
}
</script>
</body></html>