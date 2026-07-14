<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M'); // 增加記憶體限制以處理大量資料

session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

// --- AJAX: 取得可用年份 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_available_years') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $years = $pdo->query("
            SELECT DISTINCT YEAR(Order_date) as yr FROM order_track WHERE Order_date IS NOT NULL 
            UNION SELECT DISTINCT YEAR(Order_date) as yr FROM is_list WHERE Order_date IS NOT NULL 
            UNION SELECT DISTINCT YEAR(transfer_date) as yr FROM bom_ing_transfer_log WHERE transfer_date IS NOT NULL
            ORDER BY yr DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'years' => $years]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

// --- AJAX: 取得全廠趨勢對比資料 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_global_trend_data') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $year = $_POST['year'] ?? date('Y');
        
        // 1. 訂單金額 (月)
        $orders = $pdo->prepare("SELECT DATE_FORMAT(Order_date,'%m') as m, SUM(Qty * unit_price) as total FROM order_track WHERE YEAR(Order_date) = ? AND unit_price > 0 GROUP BY m");
        $orders->execute([$year]);
        $order_data = $orders->fetchAll(PDO::FETCH_KEY_PAIR);

        // 2. 出貨金額 (月)
        $shipments = $pdo->prepare("SELECT DATE_FORMAT(Order_date,'%m') as m, SUM(Qty * Unit_price) as total FROM is_list WHERE YEAR(Order_date) = ? AND Unit_price > 0 GROUP BY m");
        $shipments->execute([$year]);
        $ship_data = $shipments->fetchAll(PDO::FETCH_KEY_PAIR);

        // 3. 加工成本 (月)
        $costs = $pdo->prepare("SELECT DATE_FORMAT(transfer_date,'%m') as m, SUM(price * paid_qty) as total FROM bom_ing_transfer_log WHERE YEAR(transfer_date) = ? AND price > 0 GROUP BY m");
        $costs->execute([$year]);
        $cost_data = $costs->fetchAll(PDO::FETCH_KEY_PAIR);

        // 4. 外包比例計算 (排除 maker_list.internal = 1 的廠內廠商)
        $ext_costs = $pdo->prepare("
            SELECT DATE_FORMAT(t.transfer_date,'%m') as m, SUM(t.price * t.paid_qty) as total 
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list ml ON t.maker_from = ml.maker_id_no
            WHERE YEAR(t.transfer_date) = ? AND t.price > 0 
            AND (ml.internal IS NULL OR ml.internal != 1)
            GROUP BY m
        ");
        $ext_costs->execute([$year]);
        $ext_data = $ext_costs->fetchAll(PDO::FETCH_KEY_PAIR);

        $result = [];
        for($i=1; $i<=12; $i++) {
            $m = str_pad($i, 2, '0', STR_PAD_LEFT);
            $result[] = ['m' => $m, 'order' => floatval($order_data[$m] ?? 0), 'ship' => floatval($ship_data[$m] ?? 0), 'cost' => floatval($cost_data[$m] ?? 0), 'ext_cost' => floatval($ext_data[$m] ?? 0)];
        }
        echo json_encode(['success' => true, 'data' => $result]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

// --- AJAX: 儲存設定 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cost_settings') {
    header('Content-Type: application/json');
    try {
        // 修正：實例化並持有 DBConnection 物件，避免連線被立即關閉
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $user = $_SESSION['userName'] ?? 'system';
        
        // 儲存材料製程類型
        $material_type = $_POST['material_process_type'] ?? null;
        $sql_material = "INSERT INTO system_parameters (param_group, param_key, param_value, updated_by, updated_at) 
                         VALUES ('ERP_COST_ANALYSIS', 'material_process_type', ?, ?, NOW()) 
                         ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_by = VALUES(updated_by), updated_at = NOW()";
        $pdo->prepare($sql_material)->execute([json_encode($material_type), $user]);

        // 儲存廠內廠商
        $inhouse_vendors = $_POST['inhouse_vendors'] ?? [];
        $sql_vendors = "INSERT INTO system_parameters (param_group, param_key, param_value, updated_by, updated_at) 
                        VALUES ('ERP_COST_ANALYSIS', 'inhouse_vendors', ?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_by = VALUES(updated_by), updated_at = NOW()";
        $pdo->prepare($sql_vendors)->execute([json_encode($inhouse_vendors), $user]);
        
        echo json_encode(['success' => true, 'message' => '設定已儲存']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 刪除製程 (BOM_ING) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_bom_process') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $fid = $_POST['bom_ing_fid'];
        $pdo->prepare("DELETE FROM bom_ing WHERE bom_ing_fid = ?")->execute([$fid]);
        echo json_encode(['success' => true, 'message' => '製程已刪除']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '刪除失敗: ' . $e->getMessage()]);
    }
    exit;
}

// --- AJAX: Get Part Setting ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_part_setting') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $pid = $_POST['product_id'];
        
        $sql = "SELECT 
                    ds.*,
                    c.customer as Client_Name_Lookup
                FROM d_setting ds
                LEFT JOIN customer_list c ON ds.Customer_Id = c.customer_id
                WHERE ds.D_Setting_Id = ? 
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pid]);
        $part_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$part_data) {
            throw new Exception("找不到料號資料");
        }

        // 獲取齒輪資料
        $stmt_gear = $pdo->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id = ?");
        $stmt_gear->execute([$part_data['d_id']]);
        $gear_data = $stmt_gear->fetchAll(PDO::FETCH_ASSOC); // Changed to fetchAll for multiple gears

        // 獲取組合件子件資料 (若有)
        $child_parts = [];
        try {
            $stmt_bom = $pdo->prepare("SELECT * FROM d_setting_bom WHERE parent_d_id = ?");
            $stmt_bom->execute([$part_data['d_id']]);
            $child_parts = $stmt_bom->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* Table might not exist yet */ }
        
        // 若無客戶名稱，嘗試從 BOM 找一個代表
        if (empty($part_data['Client_Name_Lookup'])) {
            $stmt_bom_client = $pdo->prepare("SELECT Client_Name FROM bom WHERE d_id = ? LIMIT 1");
            $stmt_bom_client->execute([$pid]);
            $part_data['Client_Name_Lookup'] = $stmt_bom_client->fetchColumn();
        }

        $part_data['gear_info'] = $gear_data;
        $part_data['child_parts'] = $child_parts;
        
        echo json_encode(['success' => true, 'data' => $part_data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// AJAX: Search for d_setting parts for autocomplete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_d_setting_parts') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $keyword = $_POST['keyword'] ?? '';
        
        $sql = "SELECT D_Setting_Id FROM d_setting WHERE D_Setting_Id LIKE ? LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$keyword%"]);
        $parts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode($parts); // Return a simple array of strings
    } catch (Exception $e) {
        echo json_encode([]); // Return empty array on error
    }
    exit;
}

// --- AJAX: Search Customers (Autocomplete) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_customers') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $keyword = $_POST['keyword'] ?? '';
        
        $sql = "SELECT customer FROM customer_list WHERE (customer LIKE ? OR customer_id LIKE ?) AND is_inactive = 0 LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $term = "%$keyword%";
        $stmt->execute([$term, $term]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// --- AJAX: Search Customer (Single) - Task 1 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_customer') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $keyword = $_POST['keyword'] ?? '';
        
        $sql = "SELECT customer_id, customer FROM customer_list WHERE is_inactive = 0 AND (customer LIKE ? OR customer_id LIKE ?) LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $term = "%$keyword%";
        $stmt->execute([$term, $term]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// --- AJAX: Search Other BOM (Fuzzy Search & Append) - Task 1 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_other_bom') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $part_no = $_POST['part_no'] ?? '';
        
        // Query similar to get_bom_candidates but using LIKE for d_id
        $sql = "
            SELECT 
                b.bom, 
                b.sqty as bom_qty,
                bi.bom_ing_fid, 
                bi.bom_sn, 
                bi.process_no, 
                pn.ProcessName,
                bi.sqty,
                bi.processing_state,
                ml.maker_id,
                COALESCE(mapped_orders_sub.mapped_orders, '') AS mapped_orders, -- Get aggregated orders from subquery
                (
                    SELECT AVG(price) 
                    FROM bom_ing_transfer_log t 
                    WHERE t.bom = bi.bom AND t.bom_sn = bi.bom_sn AND t.price > 0 AND t.paid_qty > 0
                ) as avg_price
            FROM bom b
            JOIN bom_ing bi ON b.bom = bi.bom -- Assuming bi.bom_ing_fid is unique in bom_ing
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
            LEFT JOIN (
                SELECT 
                    bopm.bom, 
                    GROUP_CONCAT(DISTINCT ol.Order_oo SEPARATOR ', ') AS mapped_orders
                FROM bom_order_process_map bopm
                LEFT JOIN order_list ol ON bopm.order_id = ol.Order_id
                GROUP BY bopm.bom
            ) AS mapped_orders_sub ON b.bom = mapped_orders_sub.bom
            WHERE b.d_id LIKE ?
            -- No need for GROUP BY here if bi.bom_ing_fid is unique and mapped_orders_sub is already grouped by bom
            ORDER BY b.bom DESC, bi.bom_sn ASC
            LIMIT 100
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$part_no%"]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $candidates]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: Task 3 - Search Part Numbers for BOM ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_part_numbers_for_bom') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $keyword = $_POST['keyword'] ?? '';
        
        $sql = "SELECT D_Setting_Id FROM d_setting WHERE D_Setting_Id LIKE ? LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$keyword%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $results]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: Task 3 - Get BOMs by Specific Part ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_boms_by_specific_part') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $part_no = $_POST['part_no'] ?? '';
        
        // 1. Fetch full BOM details (needed for client-side allBomCandidates)
        $sql = "SELECT b.bom, b.sqty as bom_qty, b.d_id, bi.bom_ing_fid, bi.bom_sn, bi.process_no, pn.ProcessName, bi.sqty, bi.processing_state, ml.maker_id, GROUP_CONCAT(DISTINCT ol.Order_oo SEPARATOR ', ') as mapped_orders, (SELECT AVG(price) FROM bom_ing_transfer_log t WHERE t.bom = bi.bom AND t.bom_sn = bi.bom_sn AND t.price > 0 AND t.paid_qty > 0) as avg_price FROM bom b JOIN bom_ing bi ON b.bom = bi.bom LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no LEFT JOIN bom_order_process_map bopm ON bi.bom = bopm.bom LEFT JOIN order_list ol ON bopm.order_id = ol.Order_id WHERE b.d_id = ? GROUP BY bi.bom_ing_fid ORDER BY b.bom DESC, bi.bom_sn ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$part_no]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Generate HTML for checkboxes
        $uniqueBoms = [];
        foreach ($candidates as $c) {
            if (!isset($uniqueBoms[$c['bom']])) {
                $uniqueBoms[$c['bom']] = [
                    'qty' => $c['bom_qty'],
                    'did' => $c['d_id']
                ];
            }
        }

        $htmlString = '';
        foreach ($uniqueBoms as $bom => $info) {
            $bomEsc = htmlspecialchars($bom);
            $qty = $info['qty'];
            $did = htmlspecialchars($info['did']);
            
            $htmlString .= '<div style="display: flex; align-items: flex-start; margin-bottom: 6px; padding: 4px 0; border-bottom: 1px dashed #eee;">';
            $htmlString .= '    <div style="width: 24px; flex-shrink: 0; display: flex; justify-content: center; padding-top: 3px;">';
            $htmlString .= '        <input type="checkbox" class="bom-select-cb" name="selected_boms[]" value="' . $bomEsc . '" style="margin: 0; cursor: pointer;">';
            $htmlString .= '    </div>';
            $htmlString .= '    <div style="flex: 1; word-break: break-word; line-height: 1.4; color: #3D405B; padding-left: 4px;">';
            $htmlString .=          $bomEsc . ' <span class="text-muted" style="font-size:0.9em;">x' . $qty . 'pcs</span> <span class="text-muted" style="font-size:0.85em;">(' . $did . ')</span>';
            $htmlString .= '    </div>';
            $htmlString .= '</div>';
        }
        
        echo json_encode(['success' => true, 'html' => $htmlString, 'data' => $candidates]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: Save Item Settings (New) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_item_settings') {
    header('Content-Type: application/json');
    try {
        // 任務 2：加入 Try-Catch 與防呆檢查
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $user = $_SESSION['userName'] ?? 'system';

        $d_id = $_POST['d_id'] ?? ''; // This is d_setting.d_id (PK), can be empty for new
        $part_no = $_POST['part_no']; // This is D_Setting_Id
        $drawing_no = $_POST['drawing_no']; // 品名
        $spec_no = $_POST['spec_no'];       // 規格
        $client_name = $_POST['client_name']; // 客戶
        $type = $_POST['type'] ?? 'N';
        $revision = $_POST['revision'] ?? '';
        $issue_date = !empty($_POST['issue_date']) ? $_POST['issue_date'] : null;
        $remark = $_POST['remark'] ?? '';
        $is_assembly = (isset($_POST['is_assembly']) && $_POST['is_assembly'] == '1') ? 1 : 0;

        if (empty($part_no)) {
            throw new Exception("料號為必填欄位");
        }
        
        // Find customer_id from customer_list for both insert and update
        $customer_id = null;
        if (!empty($client_name) && $client_name !== 'Unknown') {
            $stmt_cust = $pdo->prepare("SELECT customer_id FROM customer_list WHERE customer = ?");
            $stmt_cust->execute([$client_name]);
            $customer_id = $stmt_cust->fetchColumn();
        }

        // 確保資料表存在 (DDL 應在交易前執行，避免隱式提交)
        $pdo->exec("CREATE TABLE IF NOT EXISTS d_setting_bom (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_d_id INT NOT NULL,
            child_part_no VARCHAR(100),
            standard_qty DECIMAL(10,4),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->beginTransaction();

        if (!empty($d_id)) {
            // Check if Is_Assembly column exists, if not add it (Auto-migration for dev)
            try {
                $pdo->query("SELECT Is_Assembly FROM d_setting LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("ALTER TABLE d_setting ADD COLUMN Is_Assembly TINYINT(1) DEFAULT 0");
            }

            // --- UPDATE LOGIC (Revised) ---
            $sql_d_setting = "UPDATE d_setting SET 
                        Drawing_No = ?, Spec_No = ?, Customer_Id = ?,
                        Type = ?, Revision = ?, Issue_Date = ?, Remark = ?,
                        Is_Assembly = ?,
                        Modified_By = ?, 
                        Modified_At = NOW() 
                    WHERE d_id = ?";
            $pdo->prepare($sql_d_setting)->execute([
                $drawing_no, $spec_no, $customer_id, 
                $type, $revision, $issue_date, $remark, 
                $is_assembly,
                $user, $d_id
            ]);
            $message = '料號資料已更新';
        } else {
            // --- INSERT LOGIC ---
            $stmt_check = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ?");
            $stmt_check->execute([$part_no]);
            if ($stmt_check->fetch()) {
                throw new Exception("料號 " . htmlspecialchars($part_no) . " 已存在。");
            }

            $sql_insert = "INSERT INTO d_setting (D_Setting_Id, Drawing_No, Spec_No, Customer_Id, Type, Revision, Issue_Date, Remark, Is_Assembly, Created_By, Created_At, Modified_By, Modified_At) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())";
            $pdo->prepare($sql_insert)->execute([
                $part_no, $drawing_no, $spec_no, $customer_id, 
                $type, $revision, $issue_date, $remark,
                $is_assembly,
                $user, $user
            ]);
            $d_id = $pdo->lastInsertId();
            $message = '新料號已建立';
        }
        
        // --- Gear Details Logic ---
        // 先刪除舊的齒輪資料 (簡單起見，一對一或一對多都適用)
        $pdo->prepare("DELETE FROM d_setting_gear WHERE d_setting_id = ?")->execute([$d_id]);
        
        $gears_json = $_POST['gears'] ?? '[]';
        $gears = json_decode($gears_json, true);

        if ($type === 'G' && !empty($gears) && is_array($gears)) { // 任務 2：檢查陣列
            $sql_gear = "INSERT INTO d_setting_gear (d_setting_id, Gear_Type, Module, Teeth, Pressure_Angle, Helix_Angle, Face_Width, Workpiece_Length, Remark_Gear, Created_By) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_gear = $pdo->prepare($sql_gear);
            
            foreach ($gears as $g) {
                $stmt_gear->execute([
                    $d_id, $g['type'], $g['module'], $g['teeth'], $g['pa'], $g['helix'] ?? null, $g['width'], $g['length'], $g['remark'], $user
                ]);
            }
        }

        // --- Assembly Child Parts Logic ---
        $pdo->prepare("DELETE FROM d_setting_bom WHERE parent_d_id = ?")->execute([$d_id]);
        
        $child_parts_json = $_POST['child_parts'] ?? '[]';
        $child_parts = json_decode($child_parts_json, true);

        if ($is_assembly && !empty($child_parts) && is_array($child_parts)) { // 任務 2：檢查陣列
            $sql_child = "INSERT INTO d_setting_bom (parent_d_id, child_part_no, standard_qty) VALUES (?, ?, ?)";
            $stmt_child = $pdo->prepare($sql_child);
            foreach ($child_parts as $cp) {
                $stmt_child->execute([$d_id, $cp['part_no'], $cp['qty']]);
            }
        }

        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => $message]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // 任務 2：回傳詳細錯誤訊息，不使用 500 狀態碼以免前端無法解析 JSON
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()]);
    }
    exit;
}

// --- AJAX: 儲存異常設定 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_abnormal_settings') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $user = $_SESSION['userName'] ?? 'system';
        
        $ignored_types = $_POST['ignored_process_types'] ?? [];
        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at) 
                VALUES ('ERP_COST_ANALYSIS', 'ignored_zero_cost_process_types', ?, '金額為0不列為異常的製程類別', ?, NOW()) 
                ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_by = VALUES(updated_by), updated_at = NOW()";
        $pdo->prepare($sql)->execute([json_encode($ignored_types), $user]);
        
        echo json_encode(['success' => true, 'message' => '異常設定已儲存']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取得訂單 BOM 對應候選清單 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_bom_candidates') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $pid = $_POST['product_id'];

        // 取 d_setting 整數 PK 與料號類型
        $stmt_ds = $pdo->prepare("SELECT d_id, Type FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
        $stmt_ds->execute([$pid]);
        $ds_row   = $stmt_ds->fetch(PDO::FETCH_ASSOC);
        $ds_id_int = $ds_row ? intval($ds_row['d_id'])  : 0;
        $part_type = $ds_row ? ($ds_row['Type'] ?? 'N') : 'N';

        // 齒輪參數
        $gear_factor = 0;
        if ($part_type === 'G' && $ds_id_int) {
            $stmt_g = $pdo->prepare("SELECT Module, Teeth, Face_Width FROM d_setting_gear WHERE d_setting_id = ? LIMIT 1");
            $stmt_g->execute([$ds_id_int]);
            $gr = $stmt_g->fetch(PDO::FETCH_ASSOC);
            if ($gr && floatval($gr['Module']) > 0) {
                $gear_factor = floatval($gr['Module']) * floatval($gr['Teeth']) * floatval($gr['Face_Width']);
            }
        }

        // 主查詢：avg_price 改 LEFT JOIN，同時取 maker_list.internal
        $sql = "
            SELECT
                b.bom,
                b.sqty AS bom_qty,
                bi.bom_ing_fid,
                bi.bom_sn,
                bi.process_no,
                pn.ProcessName,
                bi.sqty,
                bi.processing_state,
                ml.maker_id,
                ml.internal AS maker_internal,
                bi.maker_id_no,
                COALESCE(ext_t.avg_price, 0) AS avg_price
            FROM bom b
            JOIN bom_ing bi ON b.bom = bi.bom
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
            LEFT JOIN (
                SELECT t.bom, t.bom_sn, AVG(t.price) AS avg_price
                FROM bom_ing_transfer_log t
                LEFT JOIN maker_list ml2 ON t.maker_from = ml2.maker_id_no
                WHERE t.price > 0 AND t.paid_qty > 0
                  AND (ml2.internal IS NULL OR ml2.internal != 1)
                GROUP BY t.bom, t.bom_sn
            ) ext_t ON bi.bom = ext_t.bom AND bi.bom_sn = ext_t.bom_sn
            WHERE b.d_id = ?
            GROUP BY bi.bom_ing_fid
            ORDER BY b.bom DESC, bi.bom_sn ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pid]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 批次取需要計算廠內成本的製程群組設定
        $inhouse_proc_nos = [];
        foreach ($candidates as $c) {
            if (intval($c['maker_internal']) === 1 && floatval($c['avg_price']) == 0 && $c['process_no']) {
                $inhouse_proc_nos[] = intval($c['process_no']);
            }
        }
        $inhouse_proc_nos = array_values(array_unique($inhouse_proc_nos));

        $kpi_cache = []; // [process_no => cost_per_pc]
        if (!empty($inhouse_proc_nos) && $ds_id_int) {
            $ph_pn = implode(',', array_fill(0, count($inhouse_proc_nos), '?'));

            $stmt_gm = $pdo->prepare("SELECT process_no, group_id FROM kpi_process_group_map WHERE process_no IN ($ph_pn)");
            $stmt_gm->execute($inhouse_proc_nos);
            $pno_grp = [];
            foreach ($stmt_gm->fetchAll(PDO::FETCH_ASSOC) as $r) $pno_grp[$r['process_no']] = $r['group_id'];

            $grp_ids = array_values(array_unique(array_values($pno_grp)));
            if (!empty($grp_ids)) {
                $ph_gi = implode(',', array_fill(0, count($grp_ids), '?'));

                $stmt_kps = $pdo->prepare("SELECT group_id, coefficient, base_time_sec, base_price, multiplier FROM kpi_part_standard WHERE d_setting_id = ? AND group_id IN ($ph_gi)");
                $stmt_kps->execute(array_merge([$ds_id_int], $grp_ids));
                $kps_m = [];
                foreach ($stmt_kps->fetchAll(PDO::FETCH_ASSOC) as $r) $kps_m[$r['group_id']] = $r;

                $stmt_ksd = $pdo->prepare("SELECT group_id, base_time_sec, base_price FROM kpi_std_time_default WHERE group_id IN ($ph_gi)");
                $stmt_ksd->execute($grp_ids);
                $ksd_m = [];
                foreach ($stmt_ksd->fetchAll(PDO::FETCH_ASSOC) as $r) $ksd_m[$r['group_id']] = $r;

                $stmt_kdd = $pdo->prepare("SELECT group_id, default_coefficient FROM kpi_difficulty_default WHERE group_id IN ($ph_gi)");
                $stmt_kdd->execute($grp_ids);
                $kdd_m = [];
                foreach ($stmt_kdd->fetchAll(PDO::FETCH_ASSOC) as $r) $kdd_m[$r['group_id']] = floatval($r['default_coefficient']);

                foreach ($inhouse_proc_nos as $pno) {
                    $gid = $pno_grp[$pno] ?? null;
                    if (!$gid) continue;
                    $kps = $kps_m[$gid] ?? null;
                    if ($kps && $kps['base_time_sec'] !== null && $kps['base_price'] !== null) {
                        $base_t = floatval($kps['base_time_sec']);
                        $base_p = floatval($kps['base_price']);
                        $coeff  = floatval($kps['coefficient'] ?? 1);
                        $multi  = floatval($kps['multiplier']  ?? 1);
                    } else {
                        $ksd = $ksd_m[$gid] ?? null;
                        if (!$ksd) continue;
                        $base_t = floatval($ksd['base_time_sec']);
                        $base_p = floatval($ksd['base_price']);
                        $coeff  = $kdd_m[$gid] ?? 1.0;
                        $multi  = 1.0;
                    }
                    if ($part_type === 'G' && $gear_factor > 0) {
                        $kpi_cache[$pno] = round($base_t * $gear_factor * $coeff * $base_p, 4);
                    } else {
                        $kpi_cache[$pno] = round($base_t * $coeff * $multi * $base_p, 4);
                    }
                }
            }
        }

        // 填入廠內計算成本
        foreach ($candidates as &$c) {
            $pno = intval($c['process_no']);
            if (intval($c['maker_internal']) === 1 && floatval($c['avg_price']) == 0 && isset($kpi_cache[$pno])) {
                $c['inhouse_calc_price'] = $kpi_cache[$pno];
            } else {
                $c['inhouse_calc_price'] = null;
            }
        }
        unset($c);

        // 查詢每個 BOM 已綁定的訂單資訊與總 allocated_qty
        $bom_list = array_values(array_unique(array_column($candidates, 'bom')));
        $bom_bound_map = [];
        if (!empty($bom_list)) {
            $ph = implode(',', array_fill(0, count($bom_list), '?'));
            $stmt_b = $pdo->prepare("
                SELECT bopm.bom, bopm.order_id, bopm.allocated_qty, ot.Order_oo
                FROM bom_order_process_map bopm
                JOIN order_track ot ON bopm.order_id = ot.Order_id
                WHERE bopm.bom IN ($ph)
            ");
            $stmt_b->execute($bom_list);
            while ($br = $stmt_b->fetch(PDO::FETCH_ASSOC)) {
                $b = $br['bom'];
                if (!isset($bom_bound_map[$b])) $bom_bound_map[$b] = ['total_allocated' => 0, 'orders' => []];
                $bom_bound_map[$b]['total_allocated'] += intval($br['allocated_qty']);
                $bom_bound_map[$b]['orders'][] = ['order_oo' => $br['Order_oo'], 'allocated_qty' => intval($br['allocated_qty']), 'order_id' => intval($br['order_id'])];
            }
        }

        foreach ($candidates as &$c) {
            $bom = $c['bom'];
            $bom_qty = intval($c['bom_qty']);
            $total_allocated = isset($bom_bound_map[$bom]) ? $bom_bound_map[$bom]['total_allocated'] : 0;
            $c['bom_remaining'] = $bom_qty - $total_allocated;
            $c['bound_orders']  = isset($bom_bound_map[$bom]) ? $bom_bound_map[$bom]['orders'] : [];
        }
        unset($c);

        echo json_encode(['success' => true, 'data' => $candidates]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取得訂單目前的 BOM 對應 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_order_bom_mapping') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $order_id = $_POST['order_id'];
        
        $stmt = $pdo->prepare("SELECT bom, allocated_qty FROM bom_order_process_map WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // mapped_fids: array of bom strings (for backward compat)
        // mapped_details: [bom => allocated_qty]
        $mapped_fids = array_column($rows, 'bom');
        $mapped_details = [];
        foreach ($rows as $r) $mapped_details[$r['bom']] = intval($r['allocated_qty']);
        
        echo json_encode(['success' => true, 'mapped_fids' => $mapped_fids, 'mapped_details' => $mapped_details]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 儲存訂單 BOM 對應 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_order_bom_mapping') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $order_id = $_POST['order_id'];
        $bom_qty_map = $_POST['bom_qty_map'] ?? []; // [bom => allocated_qty]

        $pdo->beginTransaction();

        // 查出已綁定本訂單的 BOM（禁止刪除，只允許新增）
        $stmt_existing = $pdo->prepare("SELECT bom FROM bom_order_process_map WHERE order_id = ?");
        $stmt_existing->execute([$order_id]);
        $existing_boms = $stmt_existing->fetchAll(PDO::FETCH_COLUMN);

        // 只 INSERT 尚未綁定的新 BOM
        $stmt_ins = $pdo->prepare("
            INSERT INTO bom_order_process_map (bom, order_id, allocated_qty, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        foreach ($bom_qty_map as $bom_val => $qty) {
            $bom_val = trim($bom_val);
            if ($bom_val && !in_array($bom_val, $existing_boms)) {
                $stmt_ins->execute([$bom_val, $order_id, intval($qty)]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '設定已儲存']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 更新單一 BOM 綁定數量 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_bom_order_mapping') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $order_id   = intval($_POST['order_id'] ?? 0);
        $bom_qty_raw = $_POST['bom_qty_map'] ?? '{}';
        $bom_qty_map = is_string($bom_qty_raw) ? json_decode($bom_qty_raw, true) : $bom_qty_raw;
        if ($order_id <= 0 || empty($bom_qty_map)) throw new Exception('參數不足');

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO bom_order_process_map (bom, order_id, allocated_qty, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE allocated_qty = VALUES(allocated_qty), updated_at = NOW()");
        foreach ($bom_qty_map as $bom_val => $qty) {
            $bom_val = trim($bom_val);
            if ($bom_val && intval($qty) > 0) {
                $stmt->execute([$bom_val, $order_id, intval($qty)]);
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取消 BOM 綁定 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_bom_order_mapping') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $bom      = trim($_POST['bom'] ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);
        if ($bom === '' || $order_id <= 0) throw new Exception('參數不足');
        $pdo->prepare("DELETE FROM bom_order_process_map WHERE bom = ? AND order_id = ?")
            ->execute([$bom, $order_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取得出貨單候選清單 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_shipment_candidates') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $pid = $_POST['product_id'];
        
        $sql = "SELECT il.IS_id, il.IS_number, il.Order_date, il.Qty, il.Client_name, il.Specification, il.Unit_price,
                COALESCE(SUM(som.shipped_qty), 0) as mapped_qty,
                GROUP_CONCAT(DISTINCT ot.Order_oo SEPARATOR ', ') as mapped_orders
                FROM is_list il
                LEFT JOIN shipment_order_map som ON il.IS_id = som.IS_id
                LEFT JOIN order_track ot ON som.Order_id = ot.Order_id
                WHERE il.Product_id = ?
                GROUP BY il.IS_id
                ORDER BY il.Order_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pid]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $candidates]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取得訂單出貨對應 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_order_shipment_mapping') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $order_id = $_POST['order_id'];
        
        // 只從 shipment_order_map 查（來源2 is_list.Order_id 會造成刪除後仍顯示）
        $sql1 = "SELECT som.IS_id, som.shipped_qty, il.IS_number, il.Order_date,
                        il.Client_name, il.Specification, il.Qty, il.Unit_price
                 FROM shipment_order_map som
                 JOIN is_list il ON som.IS_id = il.IS_id
                 WHERE som.Order_id = ?
                 ORDER BY il.Order_date DESC";
        $stmt1 = $pdo->prepare($sql1);
        $stmt1->execute([$order_id]);
        $mappings = $stmt1->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $mappings]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 儲存訂單出貨對應 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_order_shipment_mapping') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $order_id = $_POST['order_id'];
        $mappings = $_POST['mappings'] ?? [];
        
        $pdo->beginTransaction();
        
        // [FIX] 暫時關閉外鍵檢查，避免 shipment_order_map 的 Order_id FK 指向錯誤資料表
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        // 先取得要清除 is_list.Order_id 的 IS_id 清單（只清除透過 shipment_order_map 綁定的）
        $to_clear_ids = $pdo->prepare("SELECT IS_id FROM shipment_order_map WHERE Order_id = ?");
        $to_clear_ids->execute([$order_id]);
        $clear_ids = array_column($to_clear_ids->fetchAll(PDO::FETCH_ASSOC), 'IS_id');
        $pdo->prepare("DELETE FROM shipment_order_map WHERE Order_id = ?")->execute([$order_id]);
        // 清除 is_list.Order_id（只清除原本透過 shipment_order_map 綁定的，且沒有其他 shipment_order_map 記錄的）
        if (!empty($clear_ids)) {
            $ph_clear = implode(',', array_fill(0, count($clear_ids), '?'));
            $pdo->prepare("UPDATE is_list SET Order_id = NULL WHERE IS_id IN ($ph_clear)")->execute($clear_ids);
        }
        
        if (!empty($mappings)) {
            $stmt_ins = $pdo->prepare("INSERT INTO shipment_order_map (IS_id, Order_id, shipped_qty, created_at) VALUES (?, ?, ?, NOW())");
            foreach ($mappings as $map) {
                if (empty($map['IS_id'])) continue;
                $stmt_ins->execute([$map['IS_id'], $order_id, $map['shipped_qty']]);
            }
        }
        
        // 同步更新 is_list.Order_id（讓子查詢能直接查到出貨單）
        if (!empty($mappings)) {
            $upd = $pdo->prepare("UPDATE is_list SET Order_id=? WHERE IS_id=? AND (Order_id IS NULL OR Order_id=0)");
            foreach ($mappings as $map) {
                if (empty($map['IS_id'])) continue;
                $upd->execute([$order_id, intval($map['IS_id'])]);
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '出貨對應已儲存']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch(Exception $e2) {}
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取得產品圖檔 (整合 OreadyReply 邏輯) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_files') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        
        $pid = $_POST['product_id'];
        
        // 1. 搜尋關聯的 BOM (由新到舊)
        $stmt = $pdo->prepare("SELECT bom, sqty FROM bom WHERE d_id = ? ORDER BY Created_At DESC");
        $stmt->execute([$pid]);
        $bom_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. 取得標籤設定
        $tags_config = [];
        $stmt_tags = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'BOM_FILE_TAGS' AND param_key = 'tags_config'");
        $stmt_tags->execute();
        $row_tags = $stmt_tags->fetch(PDO::FETCH_ASSOC);
        if ($row_tags) {
            $tags_config = json_decode($row_tags['param_value'], true);
        }

        $files = [];
        $erp_files = [];
        
        // 3. 掃描 Z:/BOM/ (標準圖檔)
        $scan_dir = 'Z:/BOM/'; 
        $url_dir = '/nas/';

        if (is_dir($scan_dir)) {
            $allFiles = scandir($scan_dir);
            foreach ($bom_rows as $row) {
                $bom = $row['bom'];
                $qty = $row['sqty'];
                foreach ($allFiles as $f) {
                    if ($f === '.' || $f === '..') continue;
                    if (strpos($f, $bom) === 0) {
                        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                            $isPlus = (strpos($f, '++') !== false);
                            $display_bom = $bom . ' (Qty:' . ($qty !== null ? $qty : '?') . ')';
                            $files[] = [
                                'bom' => $display_bom, 
                                'name' => $f, 
                                'path' => $url_dir . $f, 
                                'type' => $ext,
                                'is_plus' => $isPlus,
                                'mtime' => filemtime($scan_dir . $f)
                            ];
                        }
                    }
                }
            }
        }
        // Sort standard files
        usort($files, function($a, $b) {
            if ($a['is_plus'] !== $b['is_plus']) return $a['is_plus'] ? 1 : -1;
            return $b['mtime'] - $a['mtime'];
        });

        // 4. 掃描 ERP 目錄
        $erp_path_utf8 = 'Z:/BOM/ERP/資材(生管and業務)/BOM/';
        $os = PHP_OS;
        $erp_scan_path = $erp_path_utf8;
        if (strtoupper(substr($os, 0, 3)) === 'WIN') {
            $erp_scan_path = mb_convert_encoding($erp_scan_path, 'Big5', 'UTF-8');
        }

        if (is_dir($erp_scan_path)) {
            $dir_files = scandir($erp_scan_path);
            foreach ($dir_files as $f) {
                if ($f === '.' || $f === '..') continue;
                
                $f_utf8 = $f;
                if (strtoupper(substr($os, 0, 3)) === 'WIN') {
                    $f_utf8 = mb_convert_encoding($f, 'UTF-8', 'Big5');
                }

                // 比對條件：包含 [d_id] 或 開頭為任一 BOM
                $isMatch = false;
                $matchType = '';
                
                // Check d_id
                if ($pid && strpos($f_utf8, "[$pid]") !== false) {
                    $isMatch = true;
                    $matchType = 'did';
                }
                
                // Check BOMs
                if (!$isMatch) {
                    foreach ($bom_rows as $row) {
                        if (strpos($f_utf8, $row['bom']) === 0) {
                            $isMatch = true;
                            $matchType = 'bom';
                            break;
                        }
                    }
                }

                if ($isMatch) {
                    $ext = strtolower(pathinfo($f_utf8, PATHINFO_EXTENSION));
                    // 簡化：不在此處做複雜的 Tag 判斷，直接回傳
                    $erp_files[] = [
                        'name' => $f_utf8,
                        'path' => '/nas/ERP/' . rawurlencode('資材(生管and業務)') . '/BOM/' . rawurlencode($f_utf8),
                        'type' => $ext,
                        'mtime' => filemtime($erp_scan_path . $f),
                        'match_type' => $matchType
                    ];
                }
            }
            // Sort ERP files
            usort($erp_files, function($a, $b) {
                return $b['mtime'] - $a['mtime'];
            });
        }

        echo json_encode(['success' => true, 'files' => $files, 'erp_files' => $erp_files]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取得儀表板統計數據 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_dashboard_stats') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();

        $f_start = $_POST['filter_start'] ?? '';
        $f_end   = $_POST['filter_end'] ?? '';
        $has_date = ($f_start !== '' && $f_end !== '');
        $ds = $f_start . ' 00:00:00';
        $de = $f_end   . ' 23:59:59';

        // 總成本
        if ($has_date) {
            $stmt_tc = $pdo->prepare("
                SELECT COALESCE(SUM(t.price * t.paid_qty),0) 
                FROM bom_ing_transfer_log t
                LEFT JOIN maker_list ml ON t.maker_from = ml.maker_id_no
                WHERE t.price*t.paid_qty > 0 AND t.transfer_date BETWEEN ? AND ?
                AND (ml.internal IS NULL OR ml.internal != 1)
            ");
            $stmt_tc->execute([$ds, $de]);
        } else {
            $stmt_tc = $pdo->query("
                SELECT COALESCE(SUM(t.price * t.paid_qty),0) 
                FROM bom_ing_transfer_log t
                LEFT JOIN maker_list ml ON t.maker_from = ml.maker_id_no
                WHERE t.price*t.paid_qty > 0 AND (ml.internal IS NULL OR ml.internal != 1)
            ");
        }
        $total_cost = $stmt_tc->fetchColumn();

        // 總料號數
        $total_parts = $pdo->query("SELECT COUNT(DISTINCT d_id) FROM bom WHERE d_id IS NOT NULL AND d_id != ''")->fetchColumn();

        // 訂單無BOM綁定數
        if ($has_date) {
            $stmt_nb = $pdo->prepare("SELECT COUNT(*) FROM order_track o WHERE (o.Order_status IS NULL OR o.Order_status != 9) AND o.Order_date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM bom_order_process_map WHERE order_id = o.Order_id)");
            $stmt_nb->execute([$ds, $de]);
        } else {
            $stmt_nb = $pdo->query("SELECT COUNT(*) FROM order_track o WHERE (o.Order_status IS NULL OR o.Order_status != 9) AND NOT EXISTS (SELECT 1 FROM bom_order_process_map WHERE order_id = o.Order_id)");
        }
        $no_bom = $stmt_nb->fetchColumn();

        // allocated_qty < Qty (綁定不足)
        if ($has_date) {
            $stmt_ub = $pdo->prepare("SELECT COUNT(*) FROM order_track o WHERE (o.Order_status IS NULL OR o.Order_status != 9) AND o.Order_date BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM bom_order_process_map WHERE order_id = o.Order_id) AND (SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id = o.Order_id) < o.Qty AND (SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id = o.Order_id) > 0");
            $stmt_ub->execute([$ds, $de]);
        } else {
            $stmt_ub = $pdo->query("SELECT COUNT(*) FROM order_track o WHERE (o.Order_status IS NULL OR o.Order_status != 9) AND EXISTS (SELECT 1 FROM bom_order_process_map WHERE order_id = o.Order_id) AND (SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id = o.Order_id) < o.Qty AND (SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id = o.Order_id) > 0");
        }
        $under_bind = $stmt_ub->fetchColumn();

        // allocated_qty > Qty (綁定超額)
        if ($has_date) {
            $stmt_ob = $pdo->prepare("SELECT COUNT(*) FROM order_track o WHERE (o.Order_status IS NULL OR o.Order_status != 9) AND o.Order_date BETWEEN ? AND ? AND (SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id = o.Order_id) > o.Qty");
            $stmt_ob->execute([$ds, $de]);
        } else {
            $stmt_ob = $pdo->query("SELECT COUNT(*) FROM order_track o WHERE (o.Order_status IS NULL OR o.Order_status != 9) AND (SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id = o.Order_id) > o.Qty");
        }
        $over_bind = $stmt_ob->fetchColumn();

        // 無單價
        if ($has_date) {
            $stmt_np = $pdo->prepare("SELECT COUNT(*) FROM order_track o WHERE (o.Order_status IS NULL OR o.Order_status != 9) AND o.Order_date BETWEEN ? AND ? AND (o.unit_price IS NULL OR o.unit_price = 0) AND NOT EXISTS (SELECT 1 FROM is_list WHERE Order_id = o.Order_id AND Unit_price > 0)");
            $stmt_np->execute([$ds, $de]);
        } else {
            $stmt_np = $pdo->query("SELECT COUNT(*) FROM order_track o WHERE (o.Order_status IS NULL OR o.Order_status != 9) AND (o.unit_price IS NULL OR o.unit_price = 0) AND NOT EXISTS (SELECT 1 FROM is_list WHERE Order_id = o.Order_id AND Unit_price > 0)");
        }
        $no_price = $stmt_np->fetchColumn();

        // 出貨價≠訂單價
        if ($has_date) {
            $stmt_pd = $pdo->prepare("SELECT COUNT(DISTINCT o.Order_id) FROM order_track o JOIN is_list i ON o.Order_id = i.Order_id WHERE o.unit_price IS NOT NULL AND o.unit_price > 0 AND i.Unit_price IS NOT NULL AND i.Unit_price != o.unit_price AND (o.Order_status IS NULL OR o.Order_status != 9) AND o.Order_date BETWEEN ? AND ?");
            $stmt_pd->execute([$ds, $de]);
        } else {
            $stmt_pd = $pdo->query("SELECT COUNT(DISTINCT o.Order_id) FROM order_track o JOIN is_list i ON o.Order_id = i.Order_id WHERE o.unit_price IS NOT NULL AND o.unit_price > 0 AND i.Unit_price IS NOT NULL AND i.Unit_price != o.unit_price AND (o.Order_status IS NULL OR o.Order_status != 9)");
        }
        $price_diff = $stmt_pd->fetchColumn();

        // 月度成本趨勢 — 依篩選日期區間，若無日期則取近12個月
        if ($has_date) {
            $stmt_trend = $pdo->prepare("SELECT DATE_FORMAT(transfer_date,'%Y-%m') as ym, SUM(price*paid_qty) as cost FROM bom_ing_transfer_log WHERE price*paid_qty > 0 AND transfer_date BETWEEN ? AND ? GROUP BY ym ORDER BY ym");
            $stmt_trend->execute([$ds, $de]);
        } else {
            $stmt_trend = $pdo->query("SELECT DATE_FORMAT(transfer_date,'%Y-%m') as ym, SUM(price*paid_qty) as cost FROM bom_ing_transfer_log WHERE price*paid_qty > 0 AND transfer_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym");
        }
        $trend = $stmt_trend->fetchAll(PDO::FETCH_ASSOC);
        $trend_label = $has_date ? ($f_start . ' ~ ' . $f_end) : '近12個月';
        // 製程類型成本占比 — 依日期區間
        if ($has_date) {
            $stmt_pc = $pdo->prepare("SELECT COALESCE(pt.process_type,'未分類') as type_name, SUM(t.price*t.paid_qty) as cost FROM bom_ing_transfer_log t LEFT JOIN bom_ing bi ON t.bom=bi.bom AND t.bom_sn=bi.bom_sn LEFT JOIN process_no pn ON bi.process_no=pn.ProcessNo LEFT JOIN process_type pt ON pn.process_type_id=pt.process_type_id WHERE t.price*t.paid_qty > 0 AND t.transfer_date BETWEEN ? AND ? GROUP BY pt.process_type ORDER BY cost DESC LIMIT 8");
            $stmt_pc->execute([$ds, $de]);
        } else {
            $stmt_pc = $pdo->query("SELECT COALESCE(pt.process_type,'未分類') as type_name, SUM(t.price*t.paid_qty) as cost FROM bom_ing_transfer_log t LEFT JOIN bom_ing bi ON t.bom=bi.bom AND t.bom_sn=bi.bom_sn LEFT JOIN process_no pn ON bi.process_no=pn.ProcessNo LEFT JOIN process_type pt ON pn.process_type_id=pt.process_type_id WHERE t.price*t.paid_qty > 0 GROUP BY pt.process_type ORDER BY cost DESC LIMIT 8");
        }
        $proc_cost = $stmt_pc->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success'=>true,'total_cost'=>floatval($total_cost),'total_parts'=>intval($total_parts),'no_bom'=>intval($no_bom),'under_bind'=>intval($under_bind),'over_bind'=>intval($over_bind),'no_price'=>intval($no_price),'price_diff'=>intval($price_diff),'trend'=>$trend,'trend_label'=>$trend_label,'proc_cost'=>$proc_cost]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取得成本清單 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_cost_list') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $pdo->exec("SET NAMES utf8mb4");

        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = min(100, max(1, intval($_POST['per_page'] ?? 10)));
        $offset   = ($page - 1) * $per_page;
        $f_part    = trim($_POST['filter_part']    ?? '');
        $f_keyword = trim($_POST['filter_keyword'] ?? '');
        $f_start   = $_POST['filter_start'] ?? '';
        $f_end     = $_POST['filter_end']   ?? '';
        $f_bind    = $_POST['filter_bind']  ?? '';
        $f_margin  = $_POST['filter_margin']?? '';
        $sort_dir  = strtoupper($_POST['sort_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        // ── Step 1: 快速篩選預查詢（先取出符合 bind 條件的 d_id）──
        $bind_d_ids = null; // null = 未啟用快速篩選
        if ($f_bind !== '' && $f_bind !== 'price_diff') {
            $bd_sql = ($f_start && $f_end)
                ? " AND o.Order_date BETWEEN '{$f_start} 00:00:00' AND '{$f_end} 23:59:59'" : "";
            $bq = null;
            if ($f_bind === 'no_bom') {
                $bq = $pdo->query("SELECT DISTINCT COALESCE(o.d_id, ds.D_Setting_Id) as d FROM order_track o LEFT JOIN d_setting ds ON ds.d_id=o.d_id_ID WHERE (o.Order_status IS NULL OR o.Order_status!=9){$bd_sql} AND NOT EXISTS(SELECT 1 FROM bom_order_process_map bp WHERE bp.order_id=o.Order_id) AND COALESCE(o.d_id,ds.D_Setting_Id) IS NOT NULL");
            } elseif ($f_bind === 'over') {
                $bq = $pdo->query("SELECT DISTINCT COALESCE(o.d_id, ds.D_Setting_Id) as d FROM order_track o LEFT JOIN d_setting ds ON ds.d_id=o.d_id_ID WHERE (o.Order_status IS NULL OR o.Order_status!=9){$bd_sql} AND (SELECT COALESCE(SUM(bp.allocated_qty),0) FROM bom_order_process_map bp WHERE bp.order_id=o.Order_id)>o.Qty AND COALESCE(o.d_id,ds.D_Setting_Id) IS NOT NULL");
            } elseif ($f_bind === 'under') {
                $bq = $pdo->query("SELECT DISTINCT COALESCE(o.d_id, ds.D_Setting_Id) as d FROM order_track o LEFT JOIN d_setting ds ON ds.d_id=o.d_id_ID WHERE (o.Order_status IS NULL OR o.Order_status!=9){$bd_sql} AND (SELECT COALESCE(SUM(bp.allocated_qty),0) FROM bom_order_process_map bp WHERE bp.order_id=o.Order_id)>0 AND (SELECT COALESCE(SUM(bp.allocated_qty),0) FROM bom_order_process_map bp WHERE bp.order_id=o.Order_id)<o.Qty AND COALESCE(o.d_id,ds.D_Setting_Id) IS NOT NULL");
            } elseif ($f_bind === 'no_price') {
                $bq = $pdo->query("SELECT DISTINCT COALESCE(o.d_id, ds.D_Setting_Id) as d FROM order_track o LEFT JOIN d_setting ds ON ds.d_id=o.d_id_ID WHERE (o.Order_status IS NULL OR o.Order_status!=9){$bd_sql} AND (o.unit_price IS NULL OR o.unit_price=0) AND NOT EXISTS(SELECT 1 FROM is_list il WHERE il.Order_id=o.Order_id AND il.Unit_price>0) AND COALESCE(o.d_id,ds.D_Setting_Id) IS NOT NULL");
            }
            if ($bq !== null) {
                $bind_d_ids = $bq->fetchAll(PDO::FETCH_COLUMN);
                if (empty($bind_d_ids)) {
                    echo json_encode(['success'=>true,'data'=>[],'total'=>0,'page'=>1,'per_page'=>$per_page]);
                    exit;
                }
            }
        }

        // ── Step 2: 建立 WHERE 條件（全 positional）──
        $w = ["b.d_id IS NOT NULL AND b.d_id != ''"]; // where parts
        $p = []; // params (positional，不含 JOIN 的 date params)

        if ($f_part !== '') {
            $w[] = "b.d_id = ?";
            $p[] = $f_part;
        }
        if ($f_keyword !== '') {
            $w[] = "(b.d_id LIKE ? OR b.Client_Name LIKE ?)";
            $p[] = '%'.$f_keyword.'%';
            $p[] = '%'.$f_keyword.'%';
        }
        if ($bind_d_ids !== null) {
            $ph = implode(',', array_fill(0, count($bind_d_ids), '?'));
            $w[] = "b.d_id IN ($ph)";
            $p  = array_merge($p, $bind_d_ids);
        }
        $where_sql = implode(' AND ', $w);

        // date 條件放在 JOIN ON（JOIN params 要在 WHERE params 前面）
        $join_cond   = '';
        $join_params = [];
        if ($f_start !== '' && $f_end !== '') {
            $join_cond   = " AND t.transfer_date BETWEEN ? AND ?";
            $join_params = [$f_start.' 00:00:00', $f_end.' 23:59:59'];
        }

        // ── Step 3: COUNT ──
        $cnt_sql = "SELECT COUNT(DISTINCT b.d_id) FROM bom b LEFT JOIN bom_ing_transfer_log t ON t.bom=b.bom AND t.price>0 AND t.paid_qty>0{$join_cond} LEFT JOIN maker_list ml_cnt ON t.maker_from = ml_cnt.maker_id_no WHERE {$where_sql} AND (ml_cnt.internal IS NULL OR ml_cnt.internal != 1)";
        $cnt_stmt = $pdo->prepare($cnt_sql);
        $cnt_stmt->execute(array_merge($join_params, $p));
        $total_count = (int)$cnt_stmt->fetchColumn();

        if ($total_count === 0) {
            echo json_encode(['success'=>true,'data'=>[],'total'=>0,'page'=>1,'per_page'=>$per_page]);
            exit;
        }

        // ── Step 4: 主查詢（分頁）──
        $main_sql = "
            SELECT b.d_id AS part_no, MAX(b.Client_Name) AS bom_client,
                   COUNT(DISTINCT b.bom) AS bom_count,
                   COALESCE(SUM(t.price*t.paid_qty),0) AS total_cost,
                   COALESCE(SUM(t.paid_qty),0) AS total_qty,
                   MAX(b.bom) AS latest_bom
            FROM bom b
            LEFT JOIN bom_ing_transfer_log t ON t.bom=b.bom AND t.price>0 AND t.paid_qty>0{$join_cond}
            LEFT JOIN maker_list ml_m ON t.maker_from = ml_m.maker_id_no
            WHERE {$where_sql}
            AND (ml_m.internal IS NULL OR ml_m.internal != 1)
            GROUP BY b.d_id ORDER BY b.d_id {$sort_dir}
            LIMIT ? OFFSET ?
        ";
        $main_stmt = $pdo->prepare($main_sql);
        $all_params = array_merge($join_params, $p);
        foreach ($all_params as $i => $v) $main_stmt->bindValue($i+1, $v);
        $main_stmt->bindValue(count($all_params)+1, $per_page, PDO::PARAM_INT);
        $main_stmt->bindValue(count($all_params)+2, $offset,   PDO::PARAM_INT);
        $main_stmt->execute();
        $cost_rows = $main_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cost_rows)) {
            echo json_encode(['success'=>true,'data'=>[],'total'=>$total_count,'page'=>$page,'per_page'=>$per_page]);
            exit;
        }

        $all_part_nos = array_column($cost_rows, 'part_no');
        $cost_map = [];
        foreach ($cost_rows as $cr) $cost_map[$cr['part_no']] = $cr;

        // ── Step 4.5: 批次查各 BOM 製程均價 ──
        $all_latest_boms = array_filter(array_column(array_values($cost_map), 'latest_bom'));
        $bom_proc_cost_map = [];
        if (!empty($all_latest_boms)) {
            $ph_b = implode(',', array_fill(0, count($all_latest_boms), '?'));
            $bpc  = $pdo->prepare("SELECT bom, COALESCE(SUM(avg_p),0) as uc FROM (SELECT bi.bom, bi.bom_sn, AVG(t.price) as avg_p FROM bom_ing bi JOIN bom_ing_transfer_log t ON t.bom=bi.bom AND t.bom_sn=bi.bom_sn AND t.price>0 AND t.paid_qty>0 LEFT JOIN maker_list ml_p ON t.maker_from = ml_p.maker_id_no WHERE bi.bom IN ($ph_b) AND (ml_p.internal IS NULL OR ml_p.internal != 1) GROUP BY bi.bom,bi.bom_sn) sub GROUP BY bom");
            $bpc->execute(array_values($all_latest_boms));
            while ($r = $bpc->fetch(PDO::FETCH_ASSOC)) $bom_proc_cost_map[$r['bom']] = floatval($r['uc']);
        }

        // ── Step 5: 查訂單 ──
        $did_map = [];
        $ph_p = implode(',', array_fill(0, count($all_part_nos), '?'));
        $ds   = $pdo->prepare("SELECT D_Setting_Id, d_id FROM d_setting WHERE D_Setting_Id IN ($ph_p)");
        $ds->execute($all_part_nos);
        foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $r) $did_map[$r['D_Setting_Id']] = intval($r['d_id']);

        $int_to_part = [];
        foreach ($did_map as $pno => $dint) $int_to_part[$dint] = $pno;

        $did_ints = array_values(array_filter(array_map(fn($p2)=>$did_map[$p2]??null, $all_part_nos)));
        $ord_params = []; $ord_where = [];
        if (!empty($did_ints)) {
            $ph_di = implode(',', array_fill(0, count($did_ints), '?'));
            $ord_where[] = "o.d_id_ID IN ($ph_di)";
            $ord_params  = array_merge($ord_params, $did_ints);
        }
        $ph_pt = implode(',', array_fill(0, count($all_part_nos), '?'));
        $ord_where[]  = "(o.d_id_ID IS NULL AND o.d_id IN ($ph_pt))";
        $ord_params   = array_merge($ord_params, $all_part_nos);

        $ord_date = '';
        if ($f_start && $f_end) {
            $ord_date = " AND o.Order_date BETWEEN ? AND ?";
            $ord_params[] = $f_start.' 00:00:00';
            $ord_params[] = $f_end.' 23:59:59';
        }

        $ord_sql = "SELECT o.Order_id,o.d_id AS o_d_id,o.d_id_ID AS o_d_id_ID,o.Qty,o.unit_price,COALESCE(cl.customer,o.Client_name) AS client_name,(SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id=o.Order_id) AS allocated_qty,(SELECT COUNT(*) FROM bom_order_process_map WHERE order_id=o.Order_id) AS bom_mapped,(SELECT COALESCE(SUM(il_s.Qty),0) FROM is_list il_s WHERE il_s.Order_id=o.Order_id) AS shipped_qty,(SELECT MIN(il_m.Unit_price) FROM is_list il_m WHERE il_m.Order_id=o.Order_id AND il_m.Unit_price>0) AS is_min_price,(SELECT MAX(il_x.Unit_price) FROM is_list il_x WHERE il_x.Order_id=o.Order_id AND il_x.Unit_price>0) AS is_max_price FROM order_track o LEFT JOIN customer_list cl ON o.Client_name_ID=cl.customer_id WHERE (".implode(' OR ',$ord_where).") AND (o.Order_status IS NULL OR o.Order_status!=9){$ord_date} GROUP BY o.Order_id";
        $ord_stmt = $pdo->prepare($ord_sql);
        $ord_stmt->execute($ord_params);
        $all_orders = $ord_stmt->fetchAll(PDO::FETCH_ASSOC);

        $orders_by_part = [];
        foreach ($all_orders as $ord) {
            $pno = null;
            if ($ord['o_d_id_ID']) $pno = $int_to_part[$ord['o_d_id_ID']] ?? null;
            if (!$pno) $pno = $ord['o_d_id'] ?? null;
            if ($pno && isset($cost_map[$pno])) $orders_by_part[$pno][] = $ord;
        }

        // ── Step 6: 組合結果 ──
        $result_data = [];
        foreach ($cost_map as $part_no => $cost_row) {
            $orders     = $orders_by_part[$part_no] ?? [];
            $client_name = $cost_row['bom_client'] ?? '';
            $no_bom_cnt = $under_cnt = $over_cnt = $price_diff_cnt = 0;
            $selling_prices = [];

            $lastDate = '';
            if (!empty($cost_row['latest_bom'])) {
                $s = substr($cost_row['latest_bom'], 2, 7);
                if (strlen($s) >= 7) {
                    $yyy=$s[0].$s[1].$s[2]; $mm=$s[3].$s[4]; $dd=$s[5].$s[6];
                    $lastDate = ($yyy+1911).'-'.$mm.'-'.$dd;
                }
            }

            foreach ($orders as $ord) {
                if (!$client_name && $ord['client_name']) $client_name = $ord['client_name'];
                $alloc  = floatval($ord['allocated_qty']);
                $qty    = floatval($ord['Qty']);
                $mapped = intval($ord['bom_mapped']);
                if ($mapped == 0)                     $no_bom_cnt++;
                elseif ($alloc > $qty)                $over_cnt++;
                elseif ($alloc > 0 && $alloc < $qty)  $under_cnt++;
                $op   = floatval($ord['unit_price']??0);
                $iMin = floatval($ord['is_min_price']??0);
                $iMax = floatval($ord['is_max_price']??0);
                $eff  = $op>0 ? $op : $iMin;
                if ($eff>0) $selling_prices[] = $eff;
                if ($op>0 && ($iMin>0||$iMax>0) && ($iMin!=$op||$iMax!=$op)) $price_diff_cnt++;
            }

            if ($f_bind==='price_diff' && $price_diff_cnt===0) continue;
            if ($f_margin==='loss' && ($margin??null)!==null && ($margin??1)>=0) continue;
            if ($f_margin==='low'  && ($margin??null)!==null && ($margin??1)>=20) continue;

            $avg_sell  = !empty($selling_prices) ? array_sum($selling_prices)/count($selling_prices) : 0;
            $unit_cost = $bom_proc_cost_map[$cost_row['latest_bom']??''] ?? 0;
            if ($unit_cost<=0) $unit_cost = floatval($cost_row['total_qty'])>0 ? floatval($cost_row['total_cost'])/floatval($cost_row['total_qty']) : 0;
            $margin = ($avg_sell>0&&$unit_cost>0) ? (($avg_sell-$unit_cost)/$avg_sell*100) : null;

            if ($f_bind==='no_bom'  && $no_bom_cnt===0) continue;
            if ($f_bind==='under'   && $under_cnt===0)   continue;
            if ($f_bind==='over'    && $over_cnt===0)     continue;
            if ($f_bind==='no_price'&& $avg_sell>0)       continue;
            if ($f_margin==='loss' && ($margin===null||$margin>=0))  continue;
            if ($f_margin==='low'  && ($margin===null||$margin>=20)) continue;

            $result_data[] = [
                'part_no'=>$part_no,'client'=>$client_name,
                'bom_count'=>intval($cost_row['bom_count']),
                'total_orders'=>count($orders),
                'no_bom_orders'=>$no_bom_cnt,'under_orders'=>$under_cnt,
                'over_orders'=>$over_cnt,'price_diff_orders'=>$price_diff_cnt,
                'total_cost'=>floatval($cost_row['total_cost']),
                'unit_cost'=>$unit_cost,'avg_sell_price'=>$avg_sell,
                'margin'=>$margin,'last_date'=>$lastDate,
            ];
        }

        echo json_encode([
            'success'  => true,
            'data'     => array_values($result_data),
            'total'    => ($bind_d_ids!==null||$f_bind==='price_diff'||$f_margin!=='') ? count($result_data) : $total_count,
            'page'     => $page,
            'per_page' => $per_page
        ]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}
// --- AJAX: 取得篩選選單選項 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_filter_options') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $clients_raw = $pdo->query("SELECT DISTINCT cl.customer FROM order_track ot JOIN customer_list cl ON ot.Client_name_ID = cl.customer_id WHERE cl.customer IS NOT NULL ORDER BY cl.customer")->fetchAll(PDO::FETCH_COLUMN);
        // 確保 utf8mb4 轉換
        $clients = array_values(array_filter(array_map(function($c) {
            return $c ? mb_convert_encoding($c, 'UTF-8', 'UTF-8') : null;
        }, $clients_raw)));
        $parts = [];
        echo json_encode(['success'=>true,'clients'=>$clients,'parts'=>$parts], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(['success'=>false,'clients'=>[],'parts'=>[]]); }
    exit;
}

// --- AJAX: 客戶分析資料 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_client_analysis') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $clients_input = $_POST['clients'] ?? [];
        $f_start = $_POST['filter_start'] ?? '';
        $f_end   = $_POST['filter_end']   ?? '';
        if (empty($clients_input)) { echo json_encode(['success'=>false,'message'=>'請選擇客戶']); exit; }

        $ph = implode(',', array_fill(0, count($clients_input), '?'));
        $date_cond = '';
        $date_params = [];
        if ($f_start && $f_end) {
            $date_cond = " AND o.Order_date BETWEEN ? AND ?";
            $date_params = [$f_start.' 00:00:00', $f_end.' 23:59:59'];
        }

        // 各客戶彙總
        $sql = "
            SELECT
                COALESCE(cl.customer, o.Client_name) AS client_name,
                COUNT(DISTINCT b.d_id) AS part_count,
                COUNT(DISTINCT o.Order_id) AS order_count,
                COALESCE(SUM(o.Qty * o.unit_price), 0) AS total_revenue,
                COALESCE(SUM(t.price * t.paid_qty), 0) AS total_cost,
                AVG(NULLIF(o.unit_price, 0)) AS avg_sell_price,
                DATE_FORMAT(o.Order_date, '%Y-%m') AS ym
            FROM order_track o
            LEFT JOIN customer_list cl ON o.Client_name_ID = cl.customer_id
            LEFT JOIN bom b ON b.d_id = o.d_id
            LEFT JOIN bom_ing_transfer_log t ON t.bom = b.bom AND t.price > 0 AND t.paid_qty > 0
            WHERE CONVERT(COALESCE(cl.customer, o.Client_name) USING utf8mb4) IN ($ph)
              AND (o.Order_status IS NULL OR o.Order_status != 9)
              {$date_cond}
            GROUP BY client_name, ym
            ORDER BY client_name, ym
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($clients_input, $date_params));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 料號明細（依客戶）
        $sql2 = "
            SELECT
                COALESCE(cl.customer, o.Client_name) AS client_name,
                b.d_id AS part_no,
                COUNT(DISTINCT o.Order_id) AS order_count,
                COALESCE(SUM(o.Qty * o.unit_price), 0) AS total_revenue,
                COALESCE(SUM(t.price * t.paid_qty), 0) AS total_cost,
                AVG(NULLIF(o.unit_price, 0)) AS avg_sell_price,
                COALESCE(SUM(t.paid_qty), 0) AS total_qty
            FROM order_track o
            LEFT JOIN customer_list cl ON o.Client_name_ID = cl.customer_id
            LEFT JOIN bom b ON b.d_id = o.d_id
            LEFT JOIN bom_ing_transfer_log t ON t.bom = b.bom AND t.price > 0 AND t.paid_qty > 0
            WHERE CONVERT(COALESCE(cl.customer, o.Client_name) USING utf8mb4) IN ($ph)
              AND (o.Order_status IS NULL OR o.Order_status != 9)
              AND b.d_id IS NOT NULL
              {$date_cond}
            GROUP BY client_name, b.d_id
            ORDER BY client_name, total_cost DESC
        ";
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute(array_merge($clients_input, $date_params));
        $part_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success'=>true, 'trend_rows'=>$rows, 'part_rows'=>$part_rows]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// --- AJAX: 取得單項產品分析資料 (Product Order Analysis) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_order_analysis') {
    header('Content-Type: application/json');
    try {
        $db_conn_ajax = new DBConnection();
        $pdo = $db_conn_ajax->getPDO();
        $pid = $_POST['product_id'];

        // ── 前置：取得 d_setting.d_id（整數PK）──
        $stmt_did = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
        $stmt_did->execute([$pid]);
        $ds_id_int = $stmt_did->fetchColumn(); // 可能為 false

        // 1. 取得該產品的所有訂單（基本欄位，不含子查詢）
        $where_parts = ["(o.Order_status IS NULL OR o.Order_status != 9)"];
        $bind_params  = [];
        if ($ds_id_int) {
            $where_parts[] = "(o.d_id_ID = ? OR (o.d_id_ID IS NULL AND o.d_id = ?))";
            $bind_params[]  = $ds_id_int;
            $bind_params[]  = $pid;
        } else {
            $where_parts[] = "o.d_id = ?";
            $bind_params[]  = $pid;
        }
        $where_sql = implode(' AND ', $where_parts);

        $stmt_orders = $pdo->prepare("
            SELECT
                o.Order_id,
                o.Order_oo   AS order_no,
                o.Order_date AS order_date,
                o.Qty        AS order_qty,
                o.unit_price AS selling_price,
                o.Specification AS spec,
                o.Client_name   AS client,
                o.d_id_ID,
                COALESCE(cl.customer, o.Client_name) AS client_display,
                (o.Qty * o.unit_price) AS total_revenue,
                IF(o.d_id_ID IS NULL, '無綁定料號', NULL) AS did_warning
            FROM order_track o
            LEFT JOIN customer_list cl ON o.Client_name_ID = cl.customer_id
            WHERE $where_sql
            GROUP BY o.Order_id
            ORDER BY o.Order_date DESC
        ");
        $stmt_orders->execute($bind_params);
        $orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orders)) {
            echo json_encode(['success' => true, 'orders' => [], 'process_breakdown' => [], 'avg_unit_cost' => 0, 'total_product_qty' => 0]);
            exit;
        }

        $order_ids = array_column($orders, 'Order_id');
        $ph_oids   = implode(',', array_fill(0, count($order_ids), '?'));

        // 2. 批次查：BOM綁定數 + is_mapped 標記
        $bom_map_agg = []; // [order_id => ['cnt'=>, 'alloc'=>]]
        $stmt_bmap = $pdo->prepare("
            SELECT order_id, COUNT(*) AS cnt, COALESCE(SUM(allocated_qty),0) AS alloc
            FROM bom_order_process_map
            WHERE order_id IN ($ph_oids)
            GROUP BY order_id
        ");
        $stmt_bmap->execute($order_ids);
        foreach ($stmt_bmap->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bom_map_agg[$r['order_id']] = ['cnt' => intval($r['cnt']), 'alloc' => floatval($r['alloc'])];
        }

        // 3. 批次查：出貨單對應 (shipment_order_map)
        $ship_map_agg = []; // [order_id => shipped_qty]
        $stmt_smap = $pdo->prepare("
            SELECT Order_id, COALESCE(SUM(shipped_qty),0) AS mapped_ship_qty
            FROM shipment_order_map
            WHERE Order_id IN ($ph_oids)
            GROUP BY Order_id
        ");
        $stmt_smap->execute($order_ids);
        foreach ($stmt_smap->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ship_map_agg[$r['Order_id']] = floatval($r['mapped_ship_qty']);
        }

        // 4. 批次查：is_list 資訊（出貨單號、出貨量、單價）
        // 先從 shipment_order_map 取所有關聯的 IS_id，再補 is_list.Order_id 的
        $stmt_is_ids = $pdo->prepare("
            SELECT DISTINCT IS_id, Order_id FROM shipment_order_map WHERE Order_id IN ($ph_oids)
            UNION
            SELECT DISTINCT IS_id, Order_id FROM is_list WHERE Order_id IN ($ph_oids)
        ");
        $stmt_is_ids->execute(array_merge($order_ids, $order_ids));
        $is_id_to_order = [];
        $all_is_ids     = [];
        foreach ($stmt_is_ids->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $is_id_to_order[$r['IS_id']][] = $r['Order_id'];
            $all_is_ids[] = $r['IS_id'];
        }
        $all_is_ids = array_values(array_unique($all_is_ids));

        $is_agg = []; // [order_id => ['is_numbers'=>, 'shipped_qty'=>, 'min_price'=>, 'max_price'=>, 'latest_date'=>]]
        if (!empty($all_is_ids)) {
            $ph_isids = implode(',', array_fill(0, count($all_is_ids), '?'));
            $stmt_is  = $pdo->prepare("
                SELECT IS_id, IS_number, Qty, Unit_price, Order_date
                FROM is_list
                WHERE IS_id IN ($ph_isids)
            ");
            $stmt_is->execute($all_is_ids);
            $is_rows = $stmt_is->fetchAll(PDO::FETCH_ASSOC);

            foreach ($is_rows as $ir) {
                $oid_list = $is_id_to_order[$ir['IS_id']] ?? [];
                foreach ($oid_list as $oid) {
                    if (!isset($is_agg[$oid])) {
                        $is_agg[$oid] = ['is_numbers' => [], 'shipped_qty' => 0, 'min_price' => null, 'max_price' => null, 'latest_date' => '1900-01-01'];
                    }
                    $is_agg[$oid]['is_numbers'][]  = $ir['IS_number'];
                    $is_agg[$oid]['shipped_qty']   += floatval($ir['Qty']);
                    $p = floatval($ir['Unit_price']);
                    if ($p > 0) {
                        if ($is_agg[$oid]['min_price'] === null || $p < $is_agg[$oid]['min_price']) $is_agg[$oid]['min_price'] = $p;
                        if ($is_agg[$oid]['max_price'] === null || $p > $is_agg[$oid]['max_price']) $is_agg[$oid]['max_price'] = $p;
                    }
                    if ($ir['Order_date'] && $ir['Order_date'] > $is_agg[$oid]['latest_date']) {
                        $is_agg[$oid]['latest_date'] = $ir['Order_date'];
                    }
                }
            }
        }

        // 5. 批次查：各訂單對應的製程明細（外包平均單價）
        //    同時取出 bom_ing.maker_id_no 判斷是否廠內
        $stmt_proc_details = $pdo->prepare("
            SELECT
                bopm.order_id,
                bi.bom,
                bi.bom_sn,
                bi.process_no,
                bi.maker_id_no,
                pn.ProcessName,
                ml.internal AS maker_internal,
                COALESCE(ext_t.avg_price, 0) AS avg_price
            FROM bom_order_process_map bopm
            JOIN bom_ing bi ON bopm.bom = bi.bom
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
            LEFT JOIN (
                SELECT t2.bom, t2.bom_sn, AVG(t2.price) AS avg_price
                FROM bom_ing_transfer_log t2
                LEFT JOIN maker_list ml2 ON t2.maker_from = ml2.maker_id_no
                WHERE t2.price > 0 AND t2.paid_qty > 0
                  AND (ml2.internal IS NULL OR ml2.internal != 1)
                GROUP BY t2.bom, t2.bom_sn
            ) ext_t ON bi.bom = ext_t.bom AND bi.bom_sn = ext_t.bom_sn
            WHERE bopm.order_id IN ($ph_oids)
            ORDER BY bopm.order_id, bi.bom_sn
        ");
        $stmt_proc_details->execute($order_ids);
        $raw_proc_rows = $stmt_proc_details->fetchAll(PDO::FETCH_ASSOC);

        // 收集所有需要計算廠內成本的 (bom, bom_sn, process_no, d_setting_id)
        // 廠內製程：maker_internal = 1 且 avg_price = 0
        $inhouse_keys = []; // key => [bom, bom_sn, process_no]
        foreach ($raw_proc_rows as $r) {
            if (intval($r['maker_internal']) === 1 && floatval($r['avg_price']) == 0) {
                $k = $r['bom'] . '_' . $r['bom_sn'];
                if (!isset($inhouse_keys[$k])) {
                    $inhouse_keys[$k] = [
                        'bom'        => $r['bom'],
                        'bom_sn'     => $r['bom_sn'],
                        'process_no' => $r['process_no'],
                    ];
                }
            }
        }

        // 6. 計算廠內加工每PC成本（KPI公式）
        // 廠內成本 = base_time_sec × 難易係數 × 倍數 × base_price × produced_qty / produced_qty
        //          = base_time_sec × 難易係數 × 倍數 × base_price  (每PC，良品數約掉)
        // 齒輪：base_time_sec × (Module × Teeth × Face_Width) × 難易係數 × base_price
        $inhouse_cost_map = []; // [bom_bom_sn => cost_per_pc]

        if (!empty($inhouse_keys) && $ds_id_int) {
            // 6a. 取得料號基本資訊（Type, d_id）
            $stmt_ds = $pdo->prepare("SELECT d_id, Type FROM d_setting WHERE d_id = ? LIMIT 1");
            $stmt_ds->execute([$ds_id_int]);
            $ds_row   = $stmt_ds->fetch(PDO::FETCH_ASSOC);
            $part_type = $ds_row['Type'] ?? 'N';

            // 6b. 若為齒輪，取第一筆齒輪資料（Module, Teeth, Face_Width）
            $gear_factor = 0;
            if ($part_type === 'G') {
                $stmt_gear = $pdo->prepare("SELECT Module, Teeth, Face_Width FROM d_setting_gear WHERE d_setting_id = ? LIMIT 1");
                $stmt_gear->execute([$ds_id_int]);
                $gr = $stmt_gear->fetch(PDO::FETCH_ASSOC);
                if ($gr && floatval($gr['Module']) > 0) {
                    $gear_factor = floatval($gr['Module']) * floatval($gr['Teeth']) * floatval($gr['Face_Width']);
                }
            }

            // 6c. 批次取所有涉及製程的 KPI 群組設定
            $inhouse_process_nos = array_unique(array_column(array_values($inhouse_keys), 'process_no'));
            $ph_pnos = implode(',', array_fill(0, count($inhouse_process_nos), '?'));

            // 群組對應
            $stmt_grp = $pdo->prepare("
                SELECT process_no, group_id FROM kpi_process_group_map WHERE process_no IN ($ph_pnos)
            ");
            $stmt_grp->execute($inhouse_process_nos);
            $pno_to_group = [];
            foreach ($stmt_grp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pno_to_group[$r['process_no']] = $r['group_id'];
            }

            $group_ids = array_values(array_unique(array_values($pno_to_group)));

            if (!empty($group_ids)) {
                $ph_gids = implode(',', array_fill(0, count($group_ids), '?'));

                // 料號個別設定
                $stmt_kps = $pdo->prepare("
                    SELECT group_id, coefficient, base_time_sec, base_price, multiplier
                    FROM kpi_part_standard
                    WHERE d_setting_id = ? AND group_id IN ($ph_gids)
                ");
                $stmt_kps->execute(array_merge([$ds_id_int], $group_ids));
                $kps_map = [];
                foreach ($stmt_kps->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $kps_map[$r['group_id']] = $r;
                }

                // 群組預設工時/金額
                $stmt_ksd = $pdo->prepare("
                    SELECT group_id, base_time_sec, base_price FROM kpi_std_time_default WHERE group_id IN ($ph_gids)
                ");
                $stmt_ksd->execute($group_ids);
                $ksd_map = [];
                foreach ($stmt_ksd->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ksd_map[$r['group_id']] = $r;
                }

                // 群組預設係數
                $stmt_kdd = $pdo->prepare("
                    SELECT group_id, default_coefficient FROM kpi_difficulty_default WHERE group_id IN ($ph_gids)
                ");
                $stmt_kdd->execute($group_ids);
                $kdd_map = [];
                foreach ($stmt_kdd->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $kdd_map[$r['group_id']] = floatval($r['default_coefficient']);
                }

                // 6d. 批次取各廠內製程的 produced_qty 彙總
                $inhouse_bom_sn_pairs = [];
                foreach ($inhouse_keys as $ik) {
                    $inhouse_bom_sn_pairs[] = "(bi.bom = '" . addslashes($ik['bom']) . "' AND bi.bom_sn = " . intval($ik['bom_sn']) . ")";
                }
                $bom_sn_where = implode(' OR ', $inhouse_bom_sn_pairs);
                $stmt_produced = $pdo->query("
                    SELECT bi.bom, bi.bom_sn, COALESCE(SUM(r.produced_qty), 0) AS total_produced
                    FROM bom_ing bi
                    LEFT JOIN pm_process_daily_report r ON r.bom_ing_fid = bi.bom_ing_fid AND r.produced_qty > 0
                    WHERE ($bom_sn_where)
                    GROUP BY bi.bom, bi.bom_sn
                ");
                $produced_map = []; // [bom_bom_sn => total_produced]
                foreach ($stmt_produced->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $produced_map[$r['bom'] . '_' . $r['bom_sn']] = floatval($r['total_produced']);
                }

                // 6e. 套用公式計算每PC廠內成本
                foreach ($inhouse_keys as $k => $ik) {
                    $pno      = $ik['process_no'];
                    $group_id = $pno_to_group[$pno] ?? null;
                    if (!$group_id) continue;

                    $kps = $kps_map[$group_id] ?? null;
                    if ($kps && $kps['base_time_sec'] !== null && $kps['base_price'] !== null) {
                        $base_t = floatval($kps['base_time_sec']);
                        $base_p = floatval($kps['base_price']);
                        $coeff  = floatval($kps['coefficient'] ?? 1);
                        $multi  = floatval($kps['multiplier']  ?? 1);
                    } else {
                        $ksd    = $ksd_map[$group_id] ?? null;
                        if (!$ksd) continue;
                        $base_t = floatval($ksd['base_time_sec']);
                        $base_p = floatval($ksd['base_price']);
                        $coeff  = $kdd_map[$group_id] ?? 1.0;
                        $multi  = 1.0;
                    }

                    if ($part_type === 'G' && $gear_factor > 0) {
                        $cost_per_pc = $base_t * $gear_factor * $coeff * $base_p;
                    } else {
                        $cost_per_pc = $base_t * $coeff * $multi * $base_p;
                    }

                    $inhouse_cost_map[$k] = round($cost_per_pc, 4);
                }
            }
        }

        // 7. 組合製程明細 map，廠內製程填入計算成本
        $order_processes_map = [];
        foreach ($raw_proc_rows as $r) {
            $k = $r['bom'] . '_' . $r['bom_sn'];
            $avg_price = floatval($r['avg_price']);
            $is_inhouse = intval($r['maker_internal']) === 1;
            $inhouse_cost = $inhouse_cost_map[$k] ?? 0;

            // 廠內製程：若無外包紀錄，用KPI計算值；前端須知道是計算結果
            $use_inhouse_calc = ($is_inhouse && $avg_price == 0 && $inhouse_cost > 0);

            $order_processes_map[$r['order_id']][] = [
                'ProcessName'      => $r['ProcessName'],
                'avg_price'        => $use_inhouse_calc ? $inhouse_cost : $avg_price,
                'is_inhouse_calc'  => $use_inhouse_calc,
                'is_inhouse'       => $is_inhouse,
            ];
        }

        // 8. 取得該產品的外包加工成本紀錄（製程breakdown用）
        $stmt_costs = $pdo->prepare("
            SELECT
                t.price,
                t.paid_qty,
                pn.ProcessName
            FROM bom_ing_transfer_log t
            JOIN bom b ON t.bom = b.bom
            JOIN bom_ing bi ON t.bom = bi.bom AND t.bom_sn = bi.bom_sn
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN maker_list ml ON t.maker_from = ml.maker_id_no
            WHERE b.d_id = ?
              AND t.price > 0 AND t.paid_qty > 0
              AND (ml.internal IS NULL OR ml.internal != 1)
        ");
        $stmt_costs->execute([$pid]);
        $costs = $stmt_costs->fetchAll(PDO::FETCH_ASSOC);

        // 計算全期平均單位成本
        $process_stats = [];
        foreach ($costs as $c) {
            $price  = floatval($c['price']);
            $qty    = floatval($c['paid_qty']);
            $amount = $price * $qty;
            if ($amount >= 0) {
                $p_name = $c['ProcessName'] ?: '其他';
                if (!isset($process_stats[$p_name])) $process_stats[$p_name] = ['cost' => 0, 'qty' => 0];
                $process_stats[$p_name]['cost'] += $amount;
                if ($price > 0) $process_stats[$p_name]['qty'] += $qty;
            }
        }

        $avg_unit_cost    = 0;
        $process_breakdown = [];
        foreach ($process_stats as $p_name => $stats) {
            $proc_unit_cost = ($stats['qty'] > 0) ? $stats['cost'] / $stats['qty'] : 0;
            $avg_unit_cost += $proc_unit_cost;
            $process_breakdown[$p_name] = $stats['cost'];
        }

        // 9. 組合回傳資料（使用批次查詢結果填充各欄）
        foreach ($orders as &$order) {
            $oid           = $order['Order_id'];
            $order_qty     = floatval($order['order_qty'] ?? 0);
            $selling_price = floatval($order['selling_price'] ?? 0);

            // 從批次查詢結果填入
            $bmap_row    = $bom_map_agg[$oid] ?? ['cnt' => 0, 'alloc' => 0];
            $total_alloc = floatval($bmap_row['alloc']);
            $order['is_mapped']           = $bmap_row['cnt'] + (isset($ship_map_agg[$oid]) && $ship_map_agg[$oid] > 0 ? 1 : 0);
            $order['total_allocated_qty'] = $total_alloc;

            $is_row = $is_agg[$oid] ?? null;
            $shipped_qty_is  = $is_row ? floatval($is_row['shipped_qty']) : 0;
            $mapped_ship_qty = $ship_map_agg[$oid] ?? 0;
            $shipped_qty     = $shipped_qty_is > 0 ? $shipped_qty_is : $mapped_ship_qty;
            $is_min_price    = $is_row ? floatval($is_row['min_price'] ?? 0) : 0;
            $is_max_price    = $is_row ? floatval($is_row['max_price'] ?? 0) : 0;
            $latest_date     = $is_row ? $is_row['latest_date'] : '1900-01-01';

            $order['shipped_qty']       = $shipped_qty;
            $order['mapped_shipped_qty']= $mapped_ship_qty;
            $order['is_numbers']        = $is_row ? implode('<br>', array_unique($is_row['is_numbers'])) : '';
            $order['is_min_price']      = $is_min_price;
            $order['is_max_price']      = $is_max_price;
            $order['latest_ship_date']  = ($latest_date === '1900-01-01') ? null : $latest_date;

            // ── 有效出貨單價 ──
            $eff_sell_price = 0;
            $price_source   = 'none';
            if ($selling_price > 0) {
                $eff_sell_price = $selling_price;
                $price_source   = 'order';
            } elseif ($is_min_price > 0) {
                $eff_sell_price = $is_min_price;
                $price_source   = 'shipment';
            }

            // 若有訂單售價但尚無任何出貨記錄（is_list 和 shipment_order_map 都是 0）
            if ($selling_price > 0 && $shipped_qty <= 0 && $is_min_price <= 0) {
                $price_source = 'order_fallback'; // 未綁定出貨單，自動套用訂單售價
            }

            $order['effective_sell_price'] = $eff_sell_price;
            $order['price_source']         = $price_source;

            if ($order['is_mapped'] > 0) {
                $procs = $order_processes_map[$oid] ?? [];
                $combo_parts = [];
                $zero_count  = 0;
                $total_count = count($procs);
                $unit_cost_1pc = 0; // 1pc 成本（各製程平均單價之和）

                foreach ($procs as $p) {
                    $price = floatval($p['avg_price']);
                    $unit_cost_1pc += $price;
                    if ($price <= 0) {
                        $zero_count++;
                        $combo_parts[] = "<span style='background:#ffffcc;padding:0 2px;border-radius:2px;'>" . htmlspecialchars($p['ProcessName'] ?? '') . "</span>";
                    } elseif (!empty($p['is_inhouse_calc'])) {
                        // 廠內加工：以KPI計算值標示
                        $combo_parts[] = "<span style='background:#e8f4fd;padding:0 2px;border-radius:2px;' title='廠內加工(計算值)'>" . htmlspecialchars($p['ProcessName'] ?? '') . "</span>";
                    } else {
                        $combo_parts[] = htmlspecialchars($p['ProcessName'] ?? '');
                    }
                }

                // ── BOM 實際生產數量（allocated_qty 代表綁定的 BOM 數量）──
                $bom_qty = ($total_alloc > 0) ? $total_alloc : $order_qty;

                // ── 調整後成本：不再自動調整
                // 製程外包單價本身就是「每 1pc」的成本，不因 BOM數≠出貨數 而調整
                // 若需分析成本差異，請直接比較 unit_cost_1pc 與出貨數量
                $has_qty_mismatch = false;
                $adj_unit_cost    = null;

                $order['process_combo_html']  = implode('+', $combo_parts);
                $order['zero_cost_count']     = $zero_count;
                $order['total_proc_count']    = $total_count;
                $order['unit_cost']           = $unit_cost_1pc;   // 標準 1pc 成本
                $order['adj_unit_cost']       = $adj_unit_cost;   // 調整後 1pc 成本（null=不需調整）
                $order['has_qty_mismatch']    = $has_qty_mismatch;
                $order['bom_qty_used']        = $bom_qty;

                // ── 毛利率：標準（用 unit_cost_1pc）──
                if ($unit_cost_1pc > 0 && $eff_sell_price > 0) {
                    $order['gross_margin'] = (($eff_sell_price - $unit_cost_1pc) / $eff_sell_price) * 100;
                } else {
                    $order['gross_margin'] = null;
                }

                // ── 調整後毛利率（出貨數不符時才有）──
                if ($adj_unit_cost !== null && $eff_sell_price > 0) {
                    $order['adj_gross_margin'] = (($eff_sell_price - $adj_unit_cost) / $eff_sell_price) * 100;
                } else {
                    $order['adj_gross_margin'] = null;
                }

                $order['gross_profit'] = $eff_sell_price > 0
                    ? ($eff_sell_price - $unit_cost_1pc) * ($shipped_qty > 0 ? $shipped_qty : $order_qty)
                    : null;

            } else {
                $order['unit_cost']        = null;
                $order['adj_unit_cost']    = null;
                $order['gross_profit']     = null;
                $order['gross_margin']     = null;
                $order['adj_gross_margin'] = null;
                $order['zero_cost_count']  = 0;
                $order['total_proc_count'] = 0;
                $order['has_qty_mismatch'] = false;
                $order['bom_qty_used']     = 0;
            }

            $order['process_count'] = count($process_breakdown);
            $order['process_combo'] = implode('+', array_keys($process_breakdown));
            $order['price_diff_pct'] = 0;
        }

        // 依最新出貨日降序排列（批次查詢版無 ORDER BY 子查詢，在PHP端排序）
        usort($orders, function($a, $b) {
            $da = $a['latest_ship_date'] ?? $a['order_date'] ?? '1900-01-01';
            $db = $b['latest_ship_date'] ?? $b['order_date'] ?? '1900-01-01';
            return strcmp($db, $da);
        });

        // 取得該產品的總生產數量
        $stmt_total_qty = $pdo->prepare("SELECT COALESCE(SUM(sqty), 0) FROM bom WHERE d_id = ?");
        $stmt_total_qty->execute([$pid]);
        $total_product_qty = $stmt_total_qty->fetchColumn();

        echo json_encode(['success' => true, 'orders' => $orders, 'process_breakdown' => $process_breakdown, 'avg_unit_cost' => $avg_unit_cost, 'total_product_qty' => $total_product_qty]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


$conn = new DBConnection();
$pdo  = $conn->getPDO();

// --- 載入成本分析設定 ---
$cost_settings = ['material_process_type'=>null,'inhouse_vendors'=>[],'ignored_zero_cost_process_types'=>[]];
$stmt_settings = $pdo->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group = 'ERP_COST_ANALYSIS'");
$stmt_settings->execute();
while ($row_s = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
    if ($row_s['param_key'] == 'material_process_type') {
        $cost_settings['material_process_type'] = json_decode($row_s['param_value'], true);
    } elseif ($row_s['param_key'] == 'inhouse_vendors') {
        $d = json_decode($row_s['param_value'], true);
        $cost_settings['inhouse_vendors'] = is_array($d) ? $d : [];
    } elseif ($row_s['param_key'] == 'ignored_zero_cost_process_types') {
        $d = json_decode($row_s['param_value'], true);
        $cost_settings['ignored_zero_cost_process_types'] = is_array($d) ? $d : [];
    }
}

// --- 設定 Modal 所需資料 ---
$all_process_types = $pdo->query("SELECT process_type_id, process_type FROM process_type ORDER BY process_type_id")->fetchAll(PDO::FETCH_ASSOC);
$all_makers = $pdo->query("SELECT maker_id_no, maker_id FROM maker_list WHERE maker_id_no IS NOT NULL AND maker_id_no != '' ORDER BY maker_id")->fetchAll(PDO::FETCH_ASSOC);

?>
<?php
// 取得設定 Modal 用 PHP 資料（供後面 HTML inline PHP 使用）
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ERP 產品成本分析儀表板</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <style>
    :root {
        --primary: #2c5aa0; --primary-dark: #1e4278; --primary-light: #e8f0fb;
        --success: #27ae60; --danger: #e74c3c; --warning: #f39c12; --info: #2980b9;
        --bg: #f0f3f8; --card: #fff; --border: #dde4ed; --text: #2d3748; --muted: #718096;
        --shadow: 0 2px 8px rgba(44,90,160,0.08);
    }
    body { background: var(--bg); color: var(--text); overflow-x: hidden; }
    /* ── 頂部篩選列 ── */
    .ca-toolbar {
        background: var(--card); border-bottom: 1px solid var(--border);
        padding: 10px 20px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        box-shadow: var(--shadow); position: sticky; top: 0; z-index: 100;
    }
    .ca-toolbar .tb-group { display: flex; align-items: center; gap: 6px; }
    .ca-toolbar label { margin: 0; font-size: 12px; color: var(--muted); white-space: nowrap; }
    .ca-toolbar .form-control { height: 32px; font-size: 12px; padding: 4px 8px; }
    .ca-toolbar .btn { height: 32px; padding: 0 14px; font-size: 12px; line-height: 32px; }
    .ca-toolbar .select2-container .select2-selection--single { height: 32px; line-height: 30px; }
    /* 快速篩選 badge */
    .qf-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 4px 10px; border-radius: 16px; font-size: 11px; cursor: pointer;
        border: 1px solid transparent; transition: all .15s; white-space: nowrap;
    }
    .qf-badge:hover, .qf-badge.active { filter: brightness(0.92); }
    .qf-badge .cnt { font-weight: 700; font-size: 13px; }
    .qf-no-bom   { background: #ffeaea; color: #c0392b; border-color: #f5b7b1; }
    .qf-under    { background: #fff3cd; color: #856404; border-color: #ffc107; }
    .qf-over     { background: #fff8e1; color: #e65100; border-color: #ffb300; }
    .qf-pdiff    { background: #e8f4fd; color: #1565c0; border-color: #90caf9; }
    .qf-loss     { background: #fce4ec; color: #880e4f; border-color: #f48fb1; }
    .qf-low      { background: #fdf3e3; color: #7d5a00; border-color: #f5cba7; }
    /* ── KPI 卡 ── */
    .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 12px; padding: 14px 20px 0; }
    .kpi-card {
        background: var(--card); border-radius: 10px; padding: 14px 16px;
        box-shadow: var(--shadow); border-left: 4px solid var(--primary);
    }
    .kpi-card.kpi-warn { border-left-color: var(--warning); }
    .kpi-card.kpi-danger { border-left-color: var(--danger); }
    .kpi-card.kpi-success { border-left-color: var(--success); }
    .kpi-label { font-size: 11px; color: var(--muted); margin-bottom: 4px; }
    .kpi-val { font-size: 22px; font-weight: 700; color: var(--text); line-height: 1.2; }
    .kpi-val small { font-size: 12px; font-weight: 400; color: var(--muted); }
    /* ── 圖表區 ── */
    .chart-row { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; padding: 14px 20px 0; }
    .chart-card { background: var(--card); border-radius: 10px; padding: 16px; box-shadow: var(--shadow); }
    .chart-card .chart-title { font-size: 13px; font-weight: 600; color: var(--primary); margin-bottom: 10px; border-bottom: 1px solid var(--border); padding-bottom: 6px; }
    /* ── 主列表區 ── */
    .list-section { padding: 14px 20px 20px; }
    .list-card { background: var(--card); border-radius: 10px; box-shadow: var(--shadow); overflow: hidden; }
    .list-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: var(--primary); color: #fff; }
    .list-header h4 { margin: 0; font-size: 14px; font-weight: 600; }
    .list-header .hdr-right { display: flex; align-items: center; gap: 8px; }
    /* 主表格 */
    #mainTable { width: 100%; border-collapse: collapse; }
    #mainTable thead tr { background: var(--primary-light); }
    #mainTable thead th { padding: 9px 10px; font-size: 11px; font-weight: 600; color: var(--primary); border-bottom: 2px solid var(--border); white-space: nowrap; cursor: pointer; user-select: none; }
    #mainTable thead th .sort-icon { font-size: 10px; color: var(--muted); margin-left: 3px; }
    #mainTable tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
    #mainTable tbody tr:hover { background: var(--primary-light); cursor: pointer; }
    #mainTable tbody td { padding: 8px 10px; font-size: 12px; vertical-align: middle; }
    .badge-warn { background: #fff3cd; color: #856404; border-radius: 4px; padding: 1px 6px; font-size: 10px; font-weight: 600; }
    .badge-danger { background: #fdecea; color: #c0392b; border-radius: 4px; padding: 1px 6px; font-size: 10px; font-weight: 600; }
    .badge-ok { background: #eafaf1; color: #1e8449; border-radius: 4px; padding: 1px 6px; font-size: 10px; font-weight: 600; }
    .badge-info { background: #eaf4fb; color: #1a6fa0; border-radius: 4px; padding: 1px 6px; font-size: 10px; font-weight: 600; }
    .margin-bar { display: flex; align-items: center; gap: 6px; }
    .margin-bar .bar-bg { height: 6px; width: 60px; background: #eee; border-radius: 3px; overflow: hidden; }
    .margin-bar .bar-fill { height: 100%; border-radius: 3px; }
    /* 分頁 */
    .pagination-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-top: 1px solid var(--border); font-size: 12px; }
    .pagination-row .pg-info { color: var(--muted); }
    .pagination-row .pg-btns { display: flex; gap: 4px; }
    .pg-btn { border: 1px solid var(--border); background: #fff; border-radius: 4px; padding: 3px 10px; font-size: 12px; cursor: pointer; }
    .pg-btn:hover { background: var(--primary-light); }
    .pg-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
    .pg-btn:disabled { opacity: .4; cursor: default; }
    /* ── 單項分析 Modal ── */
    #productAnalysisModal .modal-dialog { width: 95%; max-width: 1400px; }
    #productAnalysisModal .modal-content { background: var(--bg); border: none; }
    #productAnalysisModal .modal-header { background: var(--primary); color: #fff; }
    #productAnalysisModal .modal-header .close { color: #fff; opacity: .8; }
    .pa-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--border); margin-bottom: 16px; background: var(--card); border-radius: 8px 8px 0 0; overflow: hidden; }
    .pa-tab { padding: 10px 20px; font-size: 13px; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; color: var(--muted); }
    .pa-tab.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; background: var(--primary-light); }
    .pa-tab-pane { display: none; }
    .pa-tab-pane.active { display: block; }
    .pa-kpi-row { display: grid; grid-template-columns: repeat(5,1fr); gap: 10px; margin-bottom: 16px; }
    .pa-kpi { background: var(--card); border-radius: 8px; padding: 12px 14px; box-shadow: var(--shadow); text-align: center; }
    .pa-kpi .lbl { font-size: 11px; color: var(--muted); }
    .pa-kpi .val { font-size: 18px; font-weight: 700; }
    .pa-section { background: var(--card); border-radius: 8px; padding: 14px; box-shadow: var(--shadow); margin-bottom: 14px; }
    .pa-section-title { font-size: 13px; font-weight: 600; color: var(--primary); margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
    /* 訂單表格 */
    .pa-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
    .pa-tbl thead th { background: var(--primary); color: #fff; padding: 8px 9px; white-space: nowrap; text-align: center; font-weight: 600; font-size: 11px; }
    .pa-tbl tbody tr { border-bottom: 1px solid var(--border); }
    .pa-tbl tbody tr:hover { background: var(--primary-light); }
    .pa-tbl tbody td { padding: 7px 9px; vertical-align: middle; }
    .pa-tbl td.right { text-align: right; }
    .pa-tbl td.center { text-align: center; }
    /* 製程明細彈出 */
    .proc-drawer { position: fixed; right: -480px; top: 0; width: 460px; height: 100vh; background: #fff; box-shadow: -4px 0 20px rgba(0,0,0,.15); z-index: 10080; transition: right .3s; overflow-y: auto; padding: 20px; }
    .proc-drawer.open { right: 0; }
    .proc-drawer .dr-close { position: absolute; top: 12px; right: 14px; font-size: 24px; cursor: pointer; color: var(--muted); }
    .proc-drawer .dr-title { font-size: 15px; font-weight: 700; color: var(--primary); margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
    .proc-item { background: var(--bg); border-radius: 6px; padding: 10px 12px; margin-bottom: 8px; }
    .proc-item .pi-head { display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; cursor: pointer; }
    .proc-item .pi-body { margin-top: 8px; font-size: 11px; display: none; }
    .proc-item .pi-body.show { display: block; }
    .proc-log-table { width: 100%; border-collapse: collapse; font-size: 11px; }
    .proc-log-table th { background: #f0f3f8; padding: 4px 6px; }
    .proc-log-table td { padding: 4px 6px; border-bottom: 1px solid var(--border); }
    /* Toast */
    #toast-container { position: fixed; top: 20px; right: 20px; z-index: 10090; }
    .toast-msg { background: var(--success); color: #fff; padding: 10px 20px; border-radius: 6px; margin-bottom: 8px; box-shadow: 0 3px 10px rgba(0,0,0,.15); font-size: 13px; animation: fadeIn .2s; }
    .toast-msg.err { background: var(--danger); }
    @keyframes fadeIn { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
    /* 分析 modal CSS 舊有相容 */
    .analysis-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:10050; overflow-y:auto; }
    .analysis-modal .modal-content { background:#fff; width:700px; max-width:95%; margin:40px auto; border-radius:10px; padding:0; overflow:hidden; }
    .popover-header { background:var(--primary); color:#fff; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; }
    .popover-title { font-size:16px; font-weight:700; }
    .popover-total { font-size:13px; }
    .process-list .process-item { border:1px solid var(--border); border-radius:6px; margin-bottom:8px; overflow:hidden; }
    .process-item-header { background:var(--primary-light); padding:8px 14px; cursor:pointer; display:flex; justify-content:space-between; font-size:13px; font-weight:600; }
    .process-item-body { padding:10px; display:none; font-size:12px; }
    .section-title { font-weight:600; color:var(--primary); font-size:14px; margin-bottom:8px; }
    /* 產品分析 Modal 不被側邊欄遮住 */
    #productAnalysisModal .modal-dialog {
        position: fixed !important;
        top: 40px;
        left: 50% !important;
        transform: translateX(-50%) !important;
        margin: 0 !important;
        width: 96% !important;
        max-width: 1400px !important;
    }
    /* dual listbox */
    .dual-listbox { display:flex; justify-content:space-between; align-items:center; }
    .dual-listbox .list-container { width:45%; display:flex; flex-direction:column; }
    .dual-listbox .list-container select[multiple] { height:300px; margin-top:5px; }
    .dual-listbox .buttons-container { display:flex; flex-direction:column; gap:10px; padding:0 10px; }
    @media(max-width:768px) {
        .chart-row { grid-template-columns: 1fr; }
        .pa-kpi-row { grid-template-columns: repeat(2,1fr); }
        .kpi-row { grid-template-columns: repeat(2,1fr); }
    }
    /* ══ 列印樣式 ══ */
    @media print {
        .no-print, .ca-toolbar, .nav-sm, .left_col, .top_nav, .footer,
        .page-title, .pa-tabs, .pg-btns, .pg-info,
        .kpi-row, #procDrawer, #drawer-overlay,
        .qf-badge, .list-header .hdr-right { display: none !important; }

        .main-tab-pane { display: none !important; }
        body, html { margin:0 !important; padding:0 !important; }
        .main_container, .right_col, .container, .container.body {
            margin:0 !important; padding:0 !important; width:100% !important;
            float:none !important; overflow:visible !important;
        }
        .left_col { display:none !important; }
        .right_col { width:100% !important; margin-left:0 !important; }

        /* 關鍵：列印時圖表不能超出頁面 */
        canvas { max-width:100% !important; max-height:200px !important; height:200px !important; }
        div[style*="position:relative"] { height:auto !important; }

        /* 基礎 @page — 實際紙張大小由各列印函式動態注入 */
        @page { size: A4 portrait; margin: 0; }
        #print-trend-header, #print-dashboard-header,
        #print-client-header, #print-multi-header { display: none; }

        /* ── 主頁圖表（橫向 A4）── */
        body.print-dashboard #main-pane-list { display: block !important; }
        body.print-dashboard #print-dashboard-header { display: block !important; font-size:16px; font-weight:700; margin-bottom:8px; }
        body.print-dashboard .list-section, body.print-dashboard .pagination-row { display: none !important; }
        body.print-dashboard .chart-row { display: flex !important; flex-direction: column; gap: 6mm; }
        body.print-dashboard .chart-card { page-break-inside: avoid; }
        body.print-dashboard canvas { max-height:160px !important; height:160px !important; }

        /* ── 趨勢分析（直式 A4）── */
        body.print-trend #main-pane-trend { display: block !important; }
        body.print-trend #print-trend-header { display: block !important; }
        body.print-trend #print-trend-header h2 { font-size:20px; font-weight:700; margin:0 0 6px; }
        body.print-trend div[style*="height:280px"] { height: 180px !important; }
        body.print-trend canvas#globalTrendChart { max-height:180px !important; height:180px !important; }
        body.print-trend #trend-report-table-area table { width:100%; font-size:8.5px; border-collapse:collapse; }
        body.print-trend #trend-report-table-area th,
        body.print-trend #trend-report-table-area td { padding:2px 4px !important; border:1px solid #ddd; }
        body.print-trend #trend-report-table-area tr { page-break-inside:avoid; }

        /* ── 單一客戶分析（直式 A4）── */
        body.print-client-single #main-pane-customer { display: block !important; }
        body.print-client-single #print-client-header { display: block !important; }
        body.print-client-single #print-client-header h2 { font-size:20px; font-weight:700; margin:0 0 4px; }
        body.print-client-single #print-client-header div { font-size:12px; color:#444; }
        body.print-client-single #ca-multi-area { display: none !important; }
        body.print-client-single .ca-client-toolbar { display: none !important; }
        body.print-client-single #ca-single-area { display: block !important; }
        body.print-client-single canvas { max-height:140px !important; height:140px !important; }
        body.print-client-single table { font-size:8px; width:100%; }
        body.print-client-single th, body.print-client-single td { padding:2px 3px !important; }
        body.print-client-single #ca-single-area > div { margin-bottom:5px !important; }

        /* ── 多客戶比較（A3 橫向）── */
        body.print-client-multi #main-pane-customer { display: block !important; }
        body.print-client-multi #print-multi-header { display: block !important; }
        body.print-client-multi #print-multi-header h2 { font-size:22px; font-weight:700; margin:0 0 4px; }
        body.print-client-multi #ca-single-area { display: none !important; }
        body.print-client-multi .ca-client-toolbar { display: none !important; }
        body.print-client-multi #ca-multi-area { display: block !important; page-break-inside:avoid; }
        body.print-client-multi canvas { max-height:160px !important; height:160px !important; }
        body.print-client-multi table { font-size:9px; width:100%; border-collapse:collapse; }
        body.print-client-multi th, body.print-client-multi td { padding:2px 4px !important; border:1px solid #ddd; }
    }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">

<div id="toast-container"></div>

<!-- ══ 列印專用標頭（@media print 才顯示）══ -->
<div id="print-dashboard-header" style="display:none; margin-bottom:12px;">
    <h2 style="margin:0; font-size:16px;">ERP 產品成本分析 — <span id="ph-dash-range"></span></h2>
    <div style="font-size:11px; color:#666; margin-top:4px;">月度加工成本趨勢 &amp; 製程類型成本占比</div>
</div>
<div id="print-trend-header" style="display:none; margin-bottom:12px;">
    <h2 style="margin:0; font-size:16px;"><span id="ph-trend-year"></span>年度趨勢分析報告</h2>
</div>
<div id="print-client-header" style="display:none; margin-bottom:12px;">
    <h2 style="margin:0; font-size:16px;">客戶分析報告 — <span id="ph-client-name"></span></h2>
    <div style="font-size:11px; color:#666; margin-top:4px;">期間：<span id="ph-client-range"></span></div>
</div>
<div id="print-multi-header" style="display:none; margin-bottom:12px;">
    <h2 style="margin:0; font-size:16px;">客戶比較分析報告</h2>
    <div style="font-size:12px; color:#444; margin-top:4px;">比較客戶：<span id="ph-multi-clients"></span></div>
    <div style="font-size:11px; color:#666; margin-top:2px;">期間：<span id="ph-multi-range"></span></div>
</div>

<!-- 頁面標題 -->
<div class="page-title">
    <div class="title_left"><h3>ERP 產品成本分析 <small>Cost Analysis Dashboard</small></h3></div>
    <div class="title_right">
        <div class="col-md-5 col-sm-5 col-xs-12 form-group pull-right top_search">
            <div class="input-group" style="float:right;">
                <button type="button" class="btn btn-info btn-sm" data-toggle="modal" data-target="#costConfigModal">成本分類設定</button>
            </div>
        </div>
    </div>
</div>
<div class="clearfix"></div>

<!-- ══ 篩選列 ══ -->
<div class="ca-toolbar">
    <div class="tb-group">
        <label>年份</label>
        <select id="fc-year" class="form-control" style="width:100px;" onchange="onYearChange()"></select>
    </div>
    <div class="tb-group">
        <label>日期區間</label>
        <input type="date" id="fc-start" class="form-control" style="width:130px;">
        <span style="color:#ccc;">~</span>
        <input type="date" id="fc-end" class="form-control" style="width:130px;">
    </div>
    <div class="tb-group">
        <input type="text" id="fc-keyword" class="form-control" placeholder="搜尋料號/客戶/客戶ID…" style="width:200px;">
    </div>
    <button class="btn btn-primary btn-sm" onclick="loadDashStats(); loadList(1);"><i class="fa fa-search"></i> 查詢</button>
    <button class="btn btn-default btn-sm" onclick="resetFilters()"><i class="fa fa-times"></i> 清除</button>
</div>
<!-- 快速篩選列（獨立第二列）-->
<div class="ca-toolbar" style="padding:6px 20px; border-top:1px solid var(--border); flex-wrap:nowrap; overflow-x:auto;">
    <span style="font-size:11px; color:var(--muted); white-space:nowrap; margin-right:4px;">快速篩選：</span>
    <span class="qf-badge qf-no-bom" id="qf-no-bom" onclick="quickFilter('no_bom',this)" title="有訂單但未綁定任何BOM">
        <i class="fa fa-unlink"></i> 無BOM綁定 <span class="cnt" id="qc-no-bom">-</span>
    </span>
    <span class="qf-badge qf-under" id="qf-under" onclick="quickFilter('under',this)" title="BOM allocated_qty 合計 < 訂單Qty">
        <i class="fa fa-arrow-down"></i> 綁定不足 <span class="cnt" id="qc-under">-</span>
    </span>
    <span class="qf-badge qf-over" id="qf-over" onclick="quickFilter('over',this)" title="BOM allocated_qty 合計 > 訂單Qty">
        <i class="fa fa-arrow-up"></i> 綁定超額 <span class="cnt" id="qc-over">-</span>
    </span>
    <span class="qf-badge qf-pdiff" id="qf-pdiff" onclick="quickFilter('price_diff',this)" title="出貨單價≠訂單單價">
        <i class="fa fa-exclamation-circle"></i> 價格不符 <span class="cnt" id="qc-pdiff">-</span>
    </span>
    <span class="qf-badge" id="qf-no-price" onclick="quickFilter('no_price',this)" title="訂單售價與出貨價皆為0" style="background:#f3e5f5; color:#6a1b9a; border-color:#ce93d8;">
        <i class="fa fa-tag"></i> 無單價 <span class="cnt" id="qc-no-price">-</span>
    </span>
    <span class="qf-badge qf-loss" id="qf-loss" onclick="quickFilter('loss',this)" title="利潤率 < 0%">
        <i class="fa fa-minus-circle"></i> 虧損 <span class="cnt" id="qc-loss">-</span>
    </span>
</div>

<!-- ══ KPI 卡 ══ -->
                    <div class="kpi-row no-print">
    <div class="kpi-card"><div class="kpi-label">總加工成本</div><div class="kpi-val" id="kpi-total-cost">-</div></div>
    <div class="kpi-card kpi-success"><div class="kpi-label">料號總數</div><div class="kpi-val" id="kpi-total-parts">-</div></div>
    <!-- <div class="kpi-card kpi-danger"><div class="kpi-label">無BOM訂單</div><div class="kpi-val" id="kpi-no-bom" style="cursor:pointer;" onclick="quickFilter('no_bom',null)">-</div></div> -->
    <!-- <div class="kpi-card kpi-warn"><div class="kpi-label">綁定不足訂單</div><div class="kpi-val" id="kpi-under" style="cursor:pointer;" onclick="quickFilter('under',null)">-</div></div> -->
    <!-- <div class="kpi-card kpi-warn"><div class="kpi-label">綁定超額訂單</div><div class="kpi-val" id="kpi-over" style="cursor:pointer;" onclick="quickFilter('over',null)">-</div></div> -->
                        <!-- <div class="kpi-card kpi-danger"><div class="kpi-label">無單價訂單</div><div class="kpi-val" id="kpi-no-price" style="cursor:pointer;" onclick="quickFilter('no_price',null)">-</div></div> -->
    <!-- <div class="kpi-card kpi-danger"><div class="kpi-label">價格不符訂單</div><div class="kpi-val" id="kpi-pdiff" style="cursor:pointer;" onclick="quickFilter('price_diff',null)">-</div></div> -->
</div>

                    <!-- ══ 分頁標籤 ══ -->
                    <div class="pa-tabs no-print" style="margin: 14px 20px 0;">
                        <div class="pa-tab active" data-main-tab="list">產品分析清單</div>
                        <div class="pa-tab" data-main-tab="trend">趨勢分析報告</div>
                        <div class="pa-tab" data-main-tab="customer">客戶分析</div>
                        <div style="margin-left:auto; display:flex; gap:6px; align-items:center;">
                            <button class="btn btn-default btn-xs no-print" id="btn-print-current" onclick="printCurrentTab()" title="列印目前頁面"><i class="fa fa-print"></i> 列印/PDF</button>
                        </div>
                    </div>

                    <!-- ══ 分頁內容：產品清單 ══ -->
                    <div id="main-pane-list" class="main-tab-pane active">
                        <!-- ══ 原有圖表區 ══ -->
                        <div class="chart-row no-print">
                            <div class="chart-card">
                                <div class="chart-title" id="trend-chart-title"><i class="fa fa-line-chart"></i> 月度加工成本趨勢（近12個月）</div>
                                <div style="position:relative; height:160px;"><canvas id="ca_trendChart"></canvas></div>
                            </div>
                            <div class="chart-card">
                                <div class="chart-title"><i class="fa fa-pie-chart"></i> 製程類型成本占比</div>
                                <div style="position:relative; height:160px;"><canvas id="ca_procPieChart"></canvas></div>
                            </div>
                        </div>

                        <!-- ══ 主列表 ══ -->
                        <div class="list-section">
    <div class="list-card">
        <div class="list-header">
            <h4><i class="fa fa-table"></i> 成本分析清單</h4>
            <div class="hdr-right">
                <span id="list-stat" style="font-size:12px; opacity:.8;"></span>
                <select id="per-page-sel" class="form-control" style="height:28px; font-size:12px; width:80px; padding:2px 6px;" onchange="loadList(1)">
                                            <option value="10">10筆</option><option value="20">20筆</option><option value="30">30筆</option><option value="50">50筆</option><option value="100">100筆</option>
                </select>
                <select id="sort-col-sel" class="form-control" style="height:28px; font-size:12px; width:100px; padding:2px 6px;" onchange="loadList(1)">
                    <option value="part_no">料號</option><option value="client">客戶</option>
                                            <option value="total_cost">總成本</option><option value="unit_cost">單位成本</option><option value="avg_sell_price">售價</option>
                    <option value="last_date">最新日期</option>
                </select>
                <button class="btn btn-default btn-xs" id="sort-dir-btn" onclick="toggleSortDir()" title="切換排序方向"><i class="fa fa-sort-asc" id="sort-dir-icon"></i></button>
            </div>
        </div>
        <div style="overflow-x:auto;">
        <table id="mainTable">
            <thead><tr>
                <th>料號</th><th>客戶</th><th>BOM數</th>
                <th>訂單數</th><th>綁定狀況</th>
                <th>總加工成本</th><th>單位成本</th>
                <th>平均售價</th><th>利潤率</th>
                <th>最新加工日</th><th>操作</th>
            </tr></thead>
            <tbody id="mainTbody"><tr><td colspan="11" class="text-center" style="padding:40px; color:var(--muted);">載入中…</td></tr></tbody>
        </table>
        </div>
        <div class="pagination-row">
            <div class="pg-info" id="pg-info"></div>
            <div class="pg-btns" id="pg-btns"></div>
        </div>
    </div>
</div>
</div><!-- /main-pane-list -->

<!-- ══ 分頁內容：趨勢分析報告 ══ -->
<div id="main-pane-trend" class="main-tab-pane" style="display:none; padding:16px 20px;">
    <div style="margin-bottom:12px;">
        <div style="font-size:13px; font-weight:600; color:var(--primary);" id="trend-report-year-label"></div>
    </div>
    <div style="background:var(--card); border-radius:10px; padding:16px; box-shadow:var(--shadow); margin-bottom:16px;">
        <div style="font-size:13px; font-weight:600; color:var(--primary); margin-bottom:12px;"><i class="fa fa-line-chart"></i> 年度趨勢圖表（訂單/出貨/加工成本/外包）</div>
        <div style="position:relative; height:280px;">
        <canvas id="globalTrendChart"></canvas>
        </div>
    </div>
    <div id="trend-report-table-area" style="background:var(--card); border-radius:10px; padding:16px; box-shadow:var(--shadow);">
        <div class="text-center" style="padding:40px; color:var(--muted);">請選擇年份後載入</div>
    </div>
</div>

<!-- ══ 分頁內容：客戶分析 ══ -->
<div id="main-pane-customer" class="main-tab-pane" style="display:none; padding:16px 20px;">
    <!-- 客戶選擇工具列 -->
    <div class="ca-client-toolbar" style="background:var(--card); border-radius:10px; padding:14px 16px; box-shadow:var(--shadow); margin-bottom:14px;">
        <div style="display:grid; grid-template-columns:1fr auto 1fr; gap:20px; align-items:start;">
            <!-- 單一客戶 -->
            <div>
                <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:8px;"><i class="fa fa-user"></i> 單一客戶分析</div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <select id="ca-single-client" class="form-control input-sm" style="flex:1; max-width:240px;">
                        <option value="">選擇客戶…</option>
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="loadSingleClientAnalysis()"><i class="fa fa-bar-chart"></i> 分析</button>
                    <button class="btn btn-default btn-xs no-print" onclick="exportSingleClientPdf()" id="btn-export-single-pdf" style="display:none;"><i class="fa fa-file-pdf-o"></i> PDF</button>
                </div>
            </div>
            <!-- 分隔 -->
            <div style="width:1px; background:var(--border); height:100%; min-height:60px; margin:0 8px;"></div>
            <!-- 多客戶比較 -->
            <div>
                <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:8px;"><i class="fa fa-line-chart"></i> 多客戶比較（最多5個）</div>
                <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                    <div style="flex:1; min-width:220px;">
                        <input type="text" id="ca-multi-search" class="form-control input-sm" placeholder="搜尋客戶…" style="margin-bottom:6px;" oninput="filterMultiClientList()">
                        <div id="ca-multi-list" style="border:1px solid var(--border); border-radius:6px; max-height:120px; overflow-y:auto; padding:4px 0; background:#fff;"></div>
                    </div>
                    <div style="min-width:160px;">
                        <div style="font-size:11px; color:var(--muted); margin-bottom:4px;">已選擇（<span id="ca-selected-count">0</span>/5）：</div>
                        <div id="ca-selected-tags" style="display:flex; flex-wrap:wrap; gap:4px; min-height:32px; padding:4px; border:1px dashed var(--border); border-radius:6px; background:#f8f9fc;"></div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <button class="btn btn-info btn-sm" onclick="loadMultiClientComparison()"><i class="fa fa-bar-chart"></i> 比較</button>
                        <button class="btn btn-default btn-xs" onclick="clearMultiSelection()"><i class="fa fa-times"></i> 清除</button>
                        <button class="btn btn-default btn-xs no-print" onclick="exportMultiClientPdf()" id="btn-export-multi-pdf" style="display:none;"><i class="fa fa-file-pdf-o"></i> A3 PDF</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 單一客戶分析區 -->
    <div id="ca-single-area" style="display:none;">
        <!-- KPI -->
        <div class="kpi-row" style="margin-bottom:14px;" id="ca-kpi-row"></div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
            <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
                <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-line-chart"></i> 月度訂單金額趨勢</div>
                <div style="position:relative; height:200px;"><canvas id="ca-trend-chart"></canvas></div>
            </div>
            <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
                <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-pie-chart"></i> 料號成本佔比</div>
                <div style="position:relative; height:200px;"><canvas id="ca-part-pie-chart"></canvas></div>
            </div>
        </div>
        <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow); margin-bottom:14px;">
            <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-bar-chart"></i> 成本 vs 售價（依料號）</div>
            <div style="position:relative; height:220px;"><canvas id="ca-cost-bar-chart"></canvas></div>
        </div>
        <!-- 料號明細表 -->
        <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
            <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-table"></i> 料號明細</div>
            <div style="overflow-x:auto;"><table class="pa-tbl" id="ca-part-table">
                <thead><tr><th>料號</th><th>訂單數</th><th>總訂單額</th><th>總加工成本</th><th>平均售價</th><th>平均成本</th><th>平均毛利率</th></tr></thead>
                <tbody id="ca-part-tbody"></tbody>
            </table></div>
        </div>
    </div>

    <!-- 多客戶比較區 -->
    <div id="ca-multi-area" style="display:none;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
            <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
                <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-bar-chart"></i> 各客戶總加工成本比較</div>
                <div style="position:relative; height:220px;"><canvas id="ca-multi-cost-chart"></canvas></div>
            </div>
            <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
                <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-line-chart"></i> 各客戶平均毛利率比較</div>
                <div style="position:relative; height:220px;"><canvas id="ca-multi-margin-chart"></canvas></div>
            </div>
        </div>
        <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow); margin-bottom:14px;">
            <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-line-chart"></i> 各客戶月度趨勢對比</div>
            <div style="position:relative; height:240px;"><canvas id="ca-multi-trend-chart"></canvas></div>
        </div>
        <!-- 比較表格 -->
        <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
            <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-table"></i> 客戶比較表</div>
            <div style="overflow-x:auto;"><table class="pa-tbl" id="ca-multi-table">
                <thead><tr><th>客戶</th><th>料號數</th><th>訂單數</th><th>總訂單額</th><th>總加工成本</th><th>平均售價</th><th>平均成本</th><th>平均毛利率</th></tr></thead>
                <tbody id="ca-multi-tbody"></tbody>
            </table></div>
        </div>
    </div>
</div>

</div><!-- /right_col -->
<?php include '../partPage/footer.html' ?>
</div><!-- /main_container -->
</div><!-- /container -->

<!-- ══════════════════════════════
     製程明細側邊抽屜
══════════════════════════════ -->
<div class="proc-drawer" id="procDrawer">
    <span class="dr-close" onclick="closeProcDrawer()">&times;</span>
    <div class="dr-title" id="dr-title">製程明細</div>
    <div id="dr-content"></div>
</div>
<div id="drawer-overlay" style="display:none; position:fixed; inset:0; z-index:10079; background:transparent; cursor:pointer;" onclick="closeProcDrawer()"></div>

<!-- ══════════════════════════════
     單項產品分析 Modal
══════════════════════════════ -->
<div class="modal fade" id="productAnalysisModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:96%; max-width:1400px; margin-left:auto; margin-right:auto;">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" style="display:flex; align-items:center; gap:10px;">
                    <button class="btn btn-default btn-xs no-print" onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
                    單項產品分析
                    <span id="pa-product-id" style="font-weight:700; color:#fff;"></span>
                    <button id="btn-edit-part-no" class="btn btn-warning btn-xs no-print">料號設定</button>
                </h4>
            </div>
            <div class="modal-body" style="padding:16px; max-height:88vh; overflow-y:auto;">
                <!-- KPI -->
                <div class="pa-kpi-row">
                    <div class="pa-kpi"><div class="lbl">訂單總數</div><div class="val" id="pa-kpi-orders">-</div></div>
                    <div class="pa-kpi"><div class="lbl">平均售價</div><div class="val" id="pa-kpi-avgprice">-</div></div>
                    <div class="pa-kpi"><div class="lbl">平均單位成本</div><div class="val" id="pa-kpi-avgcost">-</div></div>
                    <div class="pa-kpi"><div class="lbl">平均毛利率</div><div class="val" id="pa-kpi-margin">-</div></div>
                    <div class="pa-kpi"><div class="lbl">虧損訂單數</div><div class="val" id="pa-kpi-loss" style="color:var(--danger);">-</div></div>
                </div>
                <!-- 篩選 -->
                <div class="pa-section no-print" style="padding:10px 14px; margin-bottom:10px;">
                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <div><label style="font-size:12px; margin:0 6px 0 0;">客戶：</label><span id="pa-client" style="font-weight:600; color:var(--primary);"></span></div>
                        <div>
                            <label style="font-size:12px; margin:0 4px 0 0;">年度</label>
                            <select class="form-control input-sm" id="pa-yr" style="width:90px;" onchange="filterPaOrders()">
                                <option value="">全部</option>
                            </select>
                        </div>
                        <label style="font-size:12px; margin:0;"><input type="checkbox" id="pa-chk-loss" onchange="filterPaOrders()"> 只看虧損</label>
                        <label style="font-size:12px; margin:0;"><input type="checkbox" id="pa-chk-nobom" onchange="filterPaOrders()"> 只看未綁BOM</label>
                        <label style="font-size:12px; margin:0;"><input type="checkbox" id="pa-chk-pdiff" onchange="filterPaOrders()"> 只看價格不符</label>
                    </div>
                </div>
                <!-- 分頁Tab -->
                <div class="pa-tabs" id="pa-tabs">
                    <div class="pa-tab active" data-tab="orders">訂單分析</div>
                    <div class="pa-tab" data-tab="bom">BOM 成本結構</div>
                    <div class="pa-tab" data-tab="chart">趨勢圖表</div>
                </div>
                <!-- Tab: 訂單分析 -->
                <div class="pa-tab-pane active" id="pa-pane-orders">
                    <div class="pa-section" style="padding:0; overflow-x:auto;">
                        <table class="pa-tbl" id="pa-order-tbl">
                            <thead><tr>
                                <th>訂單號</th><th>日期</th><th>規格</th><th>訂單數</th>
                                <th>出貨數</th><th>最新出貨日</th><th>出貨單號</th>
                                <th>訂單售價</th><th>出貨單價</th><th>價格狀態</th>
                                <th>單顆成本(1pc)</th><th>毛利率</th><th>製程組合</th>
                                <th>BOM綁定</th><th>操作</th>
                            </tr></thead>
                            <tbody id="pa-order-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <!-- Tab: BOM成本結構 -->
                <div class="pa-tab-pane" id="pa-pane-bom">
                    <div class="pa-section">
                        <div class="pa-section-title">製程成本明細</div>
                        <div id="pa-proc-list"></div>
                    </div>
                </div>
                <!-- Tab: 趨勢圖表 -->
                <div class="pa-tab-pane" id="pa-pane-chart">
                    <div class="pa-section">
                        <div class="pa-section-title">成本 vs 售價 趨勢</div>
                        <div style="position:relative; height:240px;"><canvas id="pa-trend-chart"></canvas></div>
                    </div>
                    <div class="pa-section">
                        <div class="pa-section-title">製程成本占比</div>
                        <div style="position:relative; height:240px;"><canvas id="pa-pie-chart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     BOM Mapping Modal（保留原有）
══════════════════════════════ -->
<div class="modal fade" id="bomMappingModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document" style="width:90%;">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">BOM 設定</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="mapping_order_id">
                <input type="hidden" id="mapping_product_id">
                <input type="hidden" id="mapping_order_qty">
                <div class="alert alert-default" style="margin-bottom:10px; padding:10px; background:#f8f9fa; border:1px solid #dee2e6; color:#212529;">
                    <strong>訂單號：</strong><span id="mapping_order_no"></span>
                    <span id="mapping_order_info"></span>
                </div>
                <ul class="nav nav-tabs" role="tablist">
                    <li role="presentation" class="active"><a href="#tab_bom_map" role="tab" data-toggle="tab">BOM 製程對應</a></li>
                    <li role="presentation"><a href="#tab_shipment_map" role="tab" data-toggle="tab">出貨單對應</a></li>
                </ul>
                <div class="tab-content" style="padding-top:15px;">
                    <div role="tabpanel" class="tab-pane active" id="tab_bom_map">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="panel panel-default">
                                    <div class="panel-heading" style="padding-bottom:5px;">
                                        1. 選擇 BOM
                                        <div class="position-relative mb-2" id="other_bom_search_container" style="margin-top:5px;">
                                            <input type="text" id="search_other_bom" class="form-control border-warning" placeholder="輸入部分料號並按 Enter" autocomplete="off" style="font-size:12px;">
                                            <div id="bom_part_search_results" class="list-group position-absolute w-100 shadow" style="z-index:1050; display:none; max-height:200px; overflow-y:auto; position:absolute; width:100%;"></div>
                                        </div>
                                    </div>
                                    <div class="panel-body" style="max-height:400px; overflow-y:auto; padding:5px;" id="bomSelectionList"></div>
                                </div>
                            </div>
                            <div class="col-md-9">
                                <div class="panel panel-default">
                                    <div class="panel-heading">2. BOM 製程明細</div>
                                    <div class="panel-body" style="padding:0;">
                                        <div style="max-height:400px; overflow-y:auto;">
                                            <table class="table table-bordered table-condensed table-hover" id="bomCandidateTable" style="margin-bottom:0;">
                                                <thead></thead><tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div role="tabpanel" class="tab-pane" id="tab_shipment_map">
                        <div class="row">
                            <div class="col-md-5">
                                <div class="panel panel-default">
                                    <div class="panel-heading">可選出貨單 (同料號)</div>
                                    <div class="panel-body" style="max-height:400px; overflow-y:auto; padding:0;">
                                        <table class="table table-hover table-condensed" id="shipmentCandidateTable" style="margin-bottom:0; font-size:12px;">
                                            <thead><tr><th>單號</th><th>規格</th><th>單價</th><th>日期</th><th>數量</th><th>操作</th></tr></thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <div class="panel panel-default">
                                    <div class="panel-heading">已對應出貨單</div>
                                    <div class="panel-body" style="padding:0;">
                                        <table class="table table-bordered table-condensed" id="shipmentMappedTable" style="margin-bottom:0; font-size:12px;">
                                            <thead><tr><th>日期</th><th>出貨單號</th><th>規格</th><th>出貨數</th><th>單價</th><th>本次對應數</th><th>操作</th></tr></thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveAllMappings()">儲存設定</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     料號設定 Modal（保留原有）
══════════════════════════════ -->
<div class="modal fade" id="partSettingModal" tabindex="-1" role="dialog" style="z-index:10060;">
    <div class="modal-dialog modal-lg" role="document" style="width:90%; max-width:1200px;">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">料號設定</h4>
            </div>
            <div class="modal-body">
                <form id="partSettingForm">
                    <input type="hidden" id="setting_d_id">
                    <div class="row">
                        <div class="col-md-4 col-sm-6 form-group"><label>料號 (Part No)</label><input type="text" id="setting_part_no" class="form-control" readonly></div>
                        <div class="col-md-4 col-sm-6 form-group"><label>工件種類 (Type)</label><select id="setting_type" class="form-control"><option value="N">一般 (N)</option><option value="G">齒輪 (G)</option><option value="H">滾刀 (H)</option></select></div>
                        <div class="col-md-4 col-sm-6 form-group" style="display:flex; align-items:flex-end; padding-bottom:5px;"><label style="cursor:pointer; font-weight:bold; margin-bottom:0;"><input type="checkbox" id="setting_is_assembly" name="is_assembly" value="1" style="vertical-align:middle; margin:0 5px 0 0;"> 是否為組合件</label></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 col-sm-6 form-group"><label>品名 (Drawing No)</label><input type="text" id="setting_drawing_no" class="form-control"></div>
                        <div class="col-md-6 col-sm-6 form-group"><label>規格 (Spec No)</label><input type="text" id="setting_spec_no" class="form-control"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 col-sm-6 form-group"><label>客戶 (Customer)</label><input type="text" class="form-control" id="setting_customer" list="customer_datalist" autocomplete="off" placeholder="輸入客戶名稱…"><datalist id="customer_datalist"></datalist></div>
                        <div class="col-md-3 col-sm-3 form-group"><label>版次 (Rev)</label><input type="text" id="setting_revision" class="form-control"></div>
                        <div class="col-md-3 col-sm-3 form-group"><label>發行日期</label><input type="date" id="setting_issue_date" class="form-control"></div>
                    </div>
                    <div class="form-group"><label>備註</label><textarea id="setting_remark" class="form-control" rows="2"></textarea></div>
                    <div class="row" id="assembly-info-display-container" style="display:none;"><div class="col-md-12"><div class="alert alert-info" id="assembly-info-display"></div></div></div>
                    <div id="gear_details_section" style="display:none; background:#fff3cd; padding:15px; border-radius:5px; border:1px solid #ffeeba; margin-top:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;"><h5 style="margin:0; color:#856404; font-weight:bold;">齒輪詳細規格</h5><button type="button" class="btn btn-warning btn-xs" onclick="addGearRow()">+ 新增齒輪規格</button></div>
                        <div id="gear_rows_container"></div>
                    </div>
                    <div id="assembly_details_section" style="display:none; background:#e8f4f8; padding:15px; border-radius:5px; border:1px solid #bce8f1; margin-top:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;"><h6 style="margin:0; color:#31708f; font-weight:bold;">組合件子件設定</h6><button type="button" class="btn btn-info btn-xs" onclick="addChildPartRow()">+ 新增子件</button></div>
                        <table class="table table-condensed table-bordered" style="background:white;"><thead><tr><th>子件料號</th><th width="150">標準用量</th><th width="50">操作</th></tr></thead><tbody id="child_parts_container"></tbody></table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveItemSettings()">儲存</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     成本分類設定 Modal（保留原有）
══════════════════════════════ -->
<div class="modal fade" id="costConfigModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">成本分類設定</h4></div>
            <div class="modal-body">
                <div class="form-group">
                    <label><strong>材料製程類型</strong></label>
                    <select id="material_process_type_select" class="form-control">
                        <option value="">-- 未設定 --</option>
                        <?php foreach($all_process_types as $pt): ?>
                        <option value="<?= $pt['process_type_id'] ?>" <?= ($cost_settings['material_process_type'] == $pt['process_type_id']) ? 'selected' : '' ?>><?= htmlspecialchars($pt['process_type']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><strong>廠內廠商（不計為外包成本）</strong></label>
                    <div class="dual-listbox">
                        <div class="list-container">
                            <label>可選廠商</label>
                            <div style="margin-bottom:5px;"><input type="text" id="vendor-search" class="form-control input-sm" placeholder="搜尋廠商…"></div>
                            <select id="available-vendors" multiple class="form-control">
                                <?php foreach($all_makers as $m): if(!in_array($m['maker_id_no'], $cost_settings['inhouse_vendors'])): ?>
                                <option value="<?= htmlspecialchars($m['maker_id_no']) ?>"><?= htmlspecialchars($m['maker_id']) ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                        <div class="buttons-container">
                            <button class="btn btn-default btn-sm" id="btn-add-vendor">&gt;&gt;</button>
                            <button class="btn btn-default btn-sm" id="btn-remove-vendor">&lt;&lt;</button>
                        </div>
                        <div class="list-container">
                            <label>廠內廠商</label>
                            <select id="selected-vendors" multiple class="form-control">
                                <?php foreach($all_makers as $m): if(in_array($m['maker_id_no'], $cost_settings['inhouse_vendors'])): ?>
                                <option value="<?= htmlspecialchars($m['maker_id_no']) ?>"><?= htmlspecialchars($m['maker_id']) ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label><strong>零成本不列入異常的製程類型</strong></label>
                    <div><?php foreach($all_process_types as $pt): $checked = in_array($pt['process_type_id'], $cost_settings['ignored_zero_cost_process_types']) ? 'checked' : ''; ?>
                    <label style="margin-right:15px; font-weight:normal;"><input type="checkbox" class="ignored-type-cb" value="<?= $pt['process_type_id'] ?>" <?= $checked ?>> <?= htmlspecialchars($pt['process_type']) ?></label>
                    <?php endforeach; ?></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveCostSettings()">儲存設定</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     舊有製程分析 Modal（相容保留）
══════════════════════════════ -->
<div id="analysisModal" class="analysis-modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <div class="popover-header">
            <div class="popover-title" id="modalTitle">料號</div>
            <div class="popover-total" id="modalTotalCost">平均單位成本: NT$ 0.00</div>
        </div>
        <div id="modalCostBreakdown" style="display:flex; justify-content:space-between; margin:10px 20px; font-size:13px; color:#555;"></div>
        <div style="padding:0 20px 20px;">
            <div class="section-title" style="display:flex; justify-content:space-between; align-items:center;">
                <span>製程細節</span>
                <div><button class="btn btn-xs btn-default" onclick="expandAllLogs()">全部展開</button> <button class="btn btn-xs btn-default" onclick="collapseAllLogs()">全部收起</button></div>
            </div>
            <div class="process-list" id="modalProcessList"></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     Drawing Choice Modal
══════════════════════════════ -->
<div class="modal fade" id="drawingChoiceModal" tabindex="-1" role="dialog" style="z-index:10070;">
    <div class="modal-dialog" role="document" style="width:80%; max-width:900px;">
        <div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="drawingChoiceModalTitle">選擇圖檔</h4></div>
            <div class="modal-body" id="drawingChoiceModalBody" style="max-height:60vh; overflow-y:auto;"></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     JS Libraries
══════════════════════════════ -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<!-- Chart.js 必須在 custom.min.js 之後載入，避免被舊版覆蓋 -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// custom.min.js 內建舊版 Chart.js v2 相容 patch
if (typeof Chart !== 'undefined') {
    if (!Chart.defaults.global) Chart.defaults.global = {};
    if (!Chart.defaults.global.legend) Chart.defaults.global.legend = {};
}
</script>
<script src="../../resource/js/jquery.dataTables.min.js"></script>
<script src="../../resource/js/dataTables.bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.js"></script>

<script>
// ══════════════════════════════════════════════════════════
//  全域狀態
// ══════════════════════════════════════════════════════════
var currentPage = 1, totalPages = 1, sortCol = 'part_no', sortDir = 'ASC', perPage = 10;
var activeQuickFilter = '';
var currentAnalysisData = null;   // 單項分析資料
var allPaOrders = [];
var paCharts = {};                // 單項分析圖表
var dashCharts = {};              // 儀表板圖表

// BOM Mapping 相關（保留）
var allBomCandidates = [];
var currentMappedFids = [];
var currentMappedDetails = {};
var currentShipmentMappings = [];
var lastShipmentCandidates = []; // 暫存搜尋結果

// ── 工具函式 ──
function escapeHtml(s) {
    if (typeof s !== 'string') return s || '';
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function fmt(n, d=0) {
    if (n===null||n===undefined||isNaN(n)) return '-';
    return Number(n).toLocaleString('zh-TW', {minimumFractionDigits:d, maximumFractionDigits:d});
}
function fmtNT(n, d=0) { return n===null||n===undefined ? '-' : 'NT$' + fmt(n, d); }
function fmtPct(n, d=1) {
    if (n===null||n===undefined) return '-';
    const c = n>=20?'var(--success)':n>=0?'var(--warning)':'var(--danger)';
    return `<span style="color:${c}; font-weight:600;">${Number(n).toFixed(d)}%</span>`;
}
function marginBar(n) {
    if (n===null) return '<span style="color:var(--muted); font-size:11px;">-</span>';
    const capped = Math.min(Math.max(n, -100), 100);
    const pct = Math.max(0, capped);
    const c = n>=20?'#27ae60':n>=0?'#f39c12':'#e74c3c';
    return `<div class="margin-bar">${fmtPct(n)}<div class="bar-bg"><div class="bar-fill" style="width:${pct}%; background:${c};"></div></div></div>`;
}
function showToast(msg, type='ok') {
    const $t = $(`<div class="toast-msg ${type==='err'?'err':''}">${escapeHtml(msg)}</div>`);
    $('#toast-container').append($t);
    setTimeout(()=>$t.fadeOut(400,()=>$t.remove()), 3000);
}

// ══════════════════════════════════════════════════════════
//  初始化
// ══════════════════════════════════════════════════════════
$(document).ready(function() {
    // 初始化日期：預設今年至今
    const now = new Date();
    $('#fc-start').val(now.getFullYear() + '-01-01');
    $('#fc-end').val(now.toISOString().split('T')[0]);

    // 載入可用年份
    $.post('ERP_Cost_Analysis.php', {action:'get_available_years'}, function(res) {
        if (res.success) {
            res.years.forEach(y => $('#fc-year').append(`<option value="${y}" ${y==new Date().getFullYear()?'selected':''}>${y}年</option>`));
        }
    }, 'json');

    // 載入篩選選項（只需客戶，用於客戶分析下拉）
    $.post('ERP_Cost_Analysis.php', {action:'get_filter_options'}, function(res) {
        if (res.success) {
            res.clients.forEach(c => {
                $('#ca-single-client').append(`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`);
            });
            // 初始化多客戶 checkbox 清單
            window._allClientList = res.clients;
            renderMultiClientList(res.clients);
        }
    }, 'json');

    // 載入儀表板統計（優先，一開始就載入）
    loadDashStats();
    // [FIX] 清單延遲 100ms 後才載入，讓統計先完成，避免並行搶資源
    setTimeout(function() { loadList(1); }, 100);

    // 主分頁切換
    $('.pa-tab[data-main-tab]').on('click', function() {
        const tab = $(this).data('main-tab');
        $('.pa-tab[data-main-tab]').removeClass('active'); $(this).addClass('active');
        $('.main-tab-pane').hide();
        $('#main-pane-' + tab).show();
        if (tab === 'trend') {
            loadGlobalTrendReport();
        }
        // 更新列印按鈕
        const labels = {list:'列印清單', trend:'列印趨勢', customer:'列印分析'};
        $('#btn-print-current').html(`<i class="fa fa-print"></i> ${labels[tab]||'列印'}/PDF`);
    });

    // 單項分析 Tab 切換
    $(document).on('click', '.pa-tab', function() {
        const tab = $(this).data('tab');
        $(this).siblings().removeClass('active'); $(this).addClass('active');
        $(this).closest('.modal-body').find('.pa-tab-pane').removeClass('active');
        $(this).closest('.modal-body').find('#pa-pane-' + tab).addClass('active');
        if (tab === 'chart') renderPaCharts();
    });

    // 篩選 Enter 鍵
    $('#fc-keyword').on('keypress', function(e){ if(e.which==13) { loadDashStats(); loadList(1); } });

    // 料號設定按鈕
    $('#btn-edit-part-no').on('click', function() {
        const pid = $('#pa-product-id').text().trim();
        if (!pid) return;
        openPartSettingModal(pid);
    });

    // ── 成本設定 Modal：廠商雙欄 ──
    var allVendors = [];
    function initVendorCache() {
        allVendors = [];
        $('#available-vendors option').each(function(){ allVendors.push({val:$(this).val(), text:$(this).text()}); });
        $('#selected-vendors option').each(function(){ allVendors.push({val:$(this).val(), text:$(this).text()}); });
    }
    initVendorCache();
    function refreshAvailableList() {
        var kw = $('#vendor-search').val().toLowerCase();
        var sel = [];
        $('#selected-vendors option').each(function(){ sel.push($(this).val()); });
        $('#available-vendors').empty();
        allVendors.filter(v => !sel.includes(v.val) && (!kw || v.text.toLowerCase().includes(kw))).forEach(v => {
            $('#available-vendors').append($('<option>',{value:v.val, text:v.text}));
        });
    }
    $('#vendor-search').on('input', refreshAvailableList);
    $('#btn-add-vendor').on('click', function() {
        $('#available-vendors option:selected').each(function(){ $('#selected-vendors').append($(this).clone()); $(this).remove(); });
        refreshAvailableList();
    });
    $('#btn-remove-vendor').on('click', function() {
        $('#selected-vendors option:selected').each(function(){ allVendors.find(v=>v.val==$(this).val()) && $('#available-vendors').append($(this).clone()); $(this).remove(); });
        refreshAvailableList();
    });

    // BOM 搜尋（保留原有邏輯）
    $('#search_other_bom').off('keypress').on('keypress', function(e) {
        if (e.which != 13) return;
        e.preventDefault();
        let kw = $(this).val().trim(); if (!kw) return;
        $.post('ERP_Cost_Analysis.php', {action:'search_part_numbers_for_bom', keyword:kw}, function(res) {
            let html = '';
            if (res.success && res.data.length > 0) {
                res.data.forEach(p => { html += `<a href="#" class="list-group-item list-group-item-action py-1 select-part-for-bom" data-part-no="${p.D_Setting_Id}"><i class="fa fa-search text-muted me-2"></i> ${p.D_Setting_Id}</a>`; });
            } else { html = '<div class="list-group-item text-muted py-1">查無相關料號</div>'; }
            $('#bom_part_search_results').html(html).slideDown(200);
        }, 'json');
    });
    $(document).off('click', '.select-part-for-bom').on('click', '.select-part-for-bom', function(e) {
        e.preventDefault();
        let part = $(this).data('part-no');
        $('#search_other_bom').val(part); $('#bom_part_search_results').slideUp(200);
        $.post('ERP_Cost_Analysis.php', {action:'get_boms_by_specific_part', part_no:part}, function(res) {
            if (res.success && res.html) {
                $('#bomSelectionList').append(res.html);
                if (res.data && res.data.length > 0) {
                    const newBoms = res.data.filter(nb => !allBomCandidates.some(eb => eb.bom === nb.bom));
                    allBomCandidates = allBomCandidates.concat(newBoms);
                }
            } else { alert(`找不到料號 ${part} 的 BOM 資料`); }
        }, 'json');
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#other_bom_search_container').length) $('#bom_part_search_results').slideUp(200);
    });
    // Shipment mapping events
    $(document).on('click', '.btn-add-shipment', function() { addShipmentMapping($(this).data('json')); });
    $(document).on('click', '.btn-remove-shipment', function() { removeShipmentMapping($(this).data('index')); });
});

// ══════════════════════════════════════════════════════════
//  儀表板統計
// ══════════════════════════════════════════════════════════
function loadDashStats() {
    const filters = getFilters();
    $.post('ERP_Cost_Analysis.php', {action:'get_dashboard_stats', filter_start: filters.filter_start, filter_end: filters.filter_end}, function(res) {
        if (!res.success) { console.error('loadDashStats failed:', res.message); return; }
        $('#kpi-total-cost').html(fmtNT(res.total_cost) + ' <small>NT$</small>');
        $('#kpi-total-parts').html(fmt(res.total_parts) + ' <small>個</small>');
        $('#kpi-no-bom').html(fmt(res.no_bom) + ' <small>筆</small>');
        $('#kpi-under').html(fmt(res.under_bind) + ' <small>筆</small>');
        $('#kpi-over').html(fmt(res.over_bind) + ' <small>筆</small>');
        $('#kpi-no-price').html(fmt(res.no_price || 0) + ' <small>筆</small>');
        $('#kpi-pdiff').html(fmt(res.price_diff) + ' <small>筆</small>');
        $('#qc-no-bom').text(res.no_bom);
        $('#qc-under').text(res.under_bind);
        $('#qc-over').text(res.over_bind);
        $('#qc-no-price').text(res.no_price || 0);
        $('#qc-pdiff').text(res.price_diff);

        // 趨勢圖（使用改名後的 canvas，避開 custom.min.js 衝突）
        try {
            const trendLabel = res.trend_label || '近12個月';
            // 更新圖表標題
            $('#trend-chart-title').html(`<i class="fa fa-line-chart"></i> 月度加工成本趨勢（${trendLabel}）`);
            const labels = res.trend.map(r => r.ym);
            const costs  = res.trend.map(r => parseFloat(r.cost));
            if (dashCharts.trend) { dashCharts.trend.destroy(); dashCharts.trend = null; }
            const ctxT = document.getElementById('ca_trendChart');
            if (ctxT) {
                dashCharts.trend = new Chart(ctxT, {
                    type: 'line',
                    data: {
                        labels, datasets: [{
                            label: '月加工成本', data: costs,
                            borderColor: '#2c5aa0', backgroundColor: 'rgba(44,90,160,0.08)',
                            tension: 0.4, fill: true, pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { ticks: { callback: function(v) { return v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v; } } } }
                    }
                });
            }
        } catch(e) { console.warn('trendChart error:', e); }

        // 製程圓餅（使用改名後的 canvas）
        try {
            const pLabels = res.proc_cost.map(r => r.type_name);
            const pData   = res.proc_cost.map(r => parseFloat(r.cost));
            const pColors = ['#2c5aa0','#27ae60','#f39c12','#e74c3c','#8e44ad','#16a085','#d35400','#2980b9'];
            if (dashCharts.pie) { dashCharts.pie.destroy(); dashCharts.pie = null; }
            const ctxP = document.getElementById('ca_procPieChart');
            if (ctxP) {
                dashCharts.pie = new Chart(ctxP, {
                    type: 'doughnut',
                    data: { labels: pLabels, datasets: [{ data: pData, backgroundColor: pColors, borderWidth: 1 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 11 }, boxWidth: 12 } } } }
                });
            }
        } catch(e) { console.warn('procPieChart error:', e); }
    }, 'json');
}

// ══════════════════════════════════════════════════════════
//  主列表
// ══════════════════════════════════════════════════════════
function getFilters() {
    return {
        filter_client:  '',
        filter_part:    '',
        filter_keyword: $('#fc-keyword').val() || '',
        filter_start:   $('#fc-start').val() || '',
        filter_end:     $('#fc-end').val() || '',
        filter_bind:    activeQuickFilter,
        filter_margin:  '',
        sort_col:       sortCol,
        sort_dir:       sortDir,
        per_page:       parseInt($('#per-page-sel').val()) || 10,
    };
}

function loadList(page) {
    currentPage = page;
    const data = Object.assign({action:'get_cost_list', page}, getFilters());
    $('#mainTbody').html('<tr><td colspan="11" class="text-center" style="padding:30px; color:var(--muted);"><i class="fa fa-spinner fa-spin"></i> 載入中…</td></tr>');
    // [FIX] 移除 loadDashStats()，統計不隨翻頁重載，只在篩選條件變更（applyFilters）時才重載
    $.post('ERP_Cost_Analysis.php', data, function(res) {
        if (!res.success) { showToast('載入失敗: ' + res.message, 'err'); return; }
        renderList(res.data);
        totalPages = Math.ceil(res.total / (parseInt($('#per-page-sel').val()) || 10));
        renderPagination(res.total, res.page, totalPages);
    }, 'json');
}

function renderList(data) {
    if (!data || data.length === 0) {
        $('#mainTbody').html('<tr><td colspan="11" class="text-center" style="padding:40px; color:var(--muted);">無符合資料</td></tr>');
        return;
    }
    let html = '';
    data.forEach(r => {
        // 綁定狀況 badge
        let bindBadges = '';
        if (r.no_bom_orders > 0) bindBadges += `<span class="badge-danger">${r.no_bom_orders}無BOM</span> `;
        if (r.under_orders > 0)  bindBadges += `<span class="badge-warn">${r.under_orders}不足</span> `;
        if (r.over_orders > 0)   bindBadges += `<span class="badge-warn" style="background:#fff8e1;">${r.over_orders}超額</span> `;
        if (r.price_diff_orders > 0) bindBadges += `<span class="badge-info">${r.price_diff_orders}價差</span> `;
        if (!bindBadges) bindBadges = '<span class="badge-ok">正常</span>';

        const marginHtml = marginBar(r.margin);
        const lastDate = r.last_date ? r.last_date.substr(0,10) : '-';

        html += `<tr onclick="openProductAnalysis('${escapeHtml(r.part_no)}')">
            <td><strong style="color:var(--primary);">${escapeHtml(r.part_no)}</strong></td>
            <td>${escapeHtml(r.client||'-')}</td>
            <td class="center">${r.bom_count}</td>
            <td class="center">${r.total_orders}</td>
            <td>${bindBadges}</td>
            <td class="right">${fmtNT(r.total_cost)}</td>
            <td class="right">${fmtNT(r.unit_cost, 2)}</td>
            <td class="right">${r.avg_sell_price > 0 ? fmtNT(r.avg_sell_price, 2) : '-'}</td>
            <td>${marginHtml}</td>
            <td class="center" style="font-size:11px;">${lastDate}</td>
            <td class="center" onclick="event.stopPropagation();">
                <button class="btn btn-xs btn-info" onclick="openProductAnalysis('${escapeHtml(r.part_no)}')" title="詳細分析"><i class="fa fa-bar-chart"></i></button>
                <button class="btn btn-xs btn-default" onclick="openAnalysisModalById('${escapeHtml(r.part_no)}')" title="製程明細"><i class="fa fa-list"></i></button>
                <button class="btn btn-xs btn-default" onclick="openProductFiles('${escapeHtml(r.part_no)}')" title="圖檔"><i class="fa fa-file"></i></button>
            </td>
        </tr>`;
    });
    $('#mainTbody').html(html);
}

function renderPagination(total, page, pages) {
    const perPage = parseInt($('#per-page-sel').val()) || 10;
    const from = (page-1)*perPage+1, to = Math.min(page*perPage, total);
    $('#pg-info').text(`第 ${from}–${to} 筆，共 ${total} 筆`);
    $('#list-stat').text(`共 ${total} 筆`);
    let html = '';
    html += `<button class="pg-btn" onclick="loadList(1)" ${page<=1?'disabled':''}>«</button>`;
    html += `<button class="pg-btn" onclick="loadList(${page-1})" ${page<=1?'disabled':''}>‹</button>`;
    const start = Math.max(1, page-2), end = Math.min(pages, page+2);
    for (let i=start; i<=end; i++) html += `<button class="pg-btn ${i===page?'active':''}" onclick="loadList(${i})">${i}</button>`;
    html += `<button class="pg-btn" onclick="loadList(${page+1})" ${page>=pages?'disabled':''}>›</button>`;
    html += `<button class="pg-btn" onclick="loadList(${pages})" ${page>=pages?'disabled':''}>»</button>`;
    $('#pg-btns').html(html);
}

function resetFilters() {
    $('#fc-keyword').val('');
    const now = new Date();
    $('#fc-start').val(now.getFullYear() + '-01-01');
    $('#fc-end').val(now.toISOString().split('T')[0]);
    activeQuickFilter = '';
    $('.qf-badge').removeClass('active');
    loadDashStats();
    loadList(1);
}

function quickFilter(type, el) {
    if (activeQuickFilter === type) {
        activeQuickFilter = '';
        $('.qf-badge').removeClass('active');
    } else {
        activeQuickFilter = type;
        $('.qf-badge').removeClass('active');
        if (el) $(el).addClass('active');
        else $('#qf-'+type.replace('_','-')).addClass('active');
    }
    loadList(1);
}

function toggleSortDir() {
    sortDir = sortDir === 'ASC' ? 'DESC' : 'ASC';
    $('#sort-dir-icon').attr('class', sortDir==='ASC' ? 'fa fa-sort-asc' : 'fa fa-sort-desc');
    loadList(1);
}

// ══════════════════════════════════════════════════════════
//  年份切換 → 自動更新日期區間 + 觸發趨勢報告
// ══════════════════════════════════════════════════════════
function onYearChange() {
    const yr = parseInt($('#fc-year').val());
    if (!yr) return;
    const now = new Date();
    const isThisYear = (yr === now.getFullYear());
    $('#fc-start').val(yr + '-01-01');
    $('#fc-end').val(isThisYear ? now.toISOString().split('T')[0] : yr + '-12-31');
    loadGlobalTrendReport();
}

// ══════════════════════════════════════════════════════════
//  趨勢分析報告
// ══════════════════════════════════════════════════════════
function loadGlobalTrendReport() {
    const year = $('#fc-year').val();
    $('#trend-report-year-label').html(`<i class="fa fa-calendar"></i> ${year}年 趨勢分析報告`);
    $('#trend-report-table-area').html('<div class="text-center" style="padding:40px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
    $.post('ERP_Cost_Analysis.php', {action:'get_global_trend_data', year:year}, function(res) {
        if (!res.success) return;
        const data = res.data;
        
        // 圖表
        const labels = data.map(d => d.m + '月');
        if (dashCharts.globalTrend) { dashCharts.globalTrend.destroy(); dashCharts.globalTrend = null; }
        const ctxGT = document.getElementById('globalTrendChart');
        if (ctxGT) {
            dashCharts.globalTrend = new Chart(ctxGT, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label:'訂單金額', data:data.map(d=>d.order), borderColor:'#3498db', backgroundColor:'transparent', tension:0.3 },
                        { label:'出貨金額', data:data.map(d=>d.ship), borderColor:'#27ae60', backgroundColor:'transparent', tension:0.3 },
                        { label:'加工成本', data:data.map(d=>d.cost), borderColor:'#e74c3c', backgroundColor:'transparent', tension:0.3 }
                    ]
                },
                options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'top'}}, scales:{y:{ticks:{callback:function(v){ return v>=1000000?(v/1000000).toFixed(1)+'M':v>=1000?(v/1000).toFixed(0)+'K':v; }}}} }
            });
        }

        // 表格彙整
        let html = '<table class="pa-tbl"><thead><tr><th>月份/季度</th><th>訂單金額</th><th>出貨金額</th><th>加工成本</th><th>外包成本</th><th>外包比例</th></tr></thead><tbody>';
        let qOrder=0, qShip=0, qCost=0, qExt=0;
        data.forEach((d, i) => {
            qOrder += d.order; qShip += d.ship; qCost += d.cost; qExt += d.ext_cost;
            const ratio = d.cost > 0 ? (d.ext_cost/d.cost*100).toFixed(1) : '-';
            html += `<tr><td class="center">${d.m}月</td><td class="right">${fmtNT(d.order)}</td><td class="right">${fmtNT(d.ship)}</td><td class="right">${fmtNT(d.cost)}</td><td class="right">${fmtNT(d.ext_cost)}</td><td class="center">${ratio}%</td></tr>`;
            
            if ((i+1)%3 === 0) {
                const qRatio = qCost > 0 ? (qExt/qCost*100).toFixed(1) : '-';
                html += `<tr style="background:#eee; font-weight:700;"><td class="center">Q${(i+1)/3} 季度</td><td class="right">${fmtNT(qOrder)}</td><td class="right">${fmtNT(qShip)}</td><td class="right">${fmtNT(qCost)}</td><td class="right">${fmtNT(qExt)}</td><td class="center">${qRatio}%</td></tr>`;
                qOrder=0; qShip=0; qCost=0; qExt=0;
            }
        });
        const totalOrder = data.reduce((a,b)=>a+b.order,0);
        const totalShip = data.reduce((a,b)=>a+b.ship,0);
        const totalCost = data.reduce((a,b)=>a+b.cost,0);
        const totalExt = data.reduce((a,b)=>a+b.ext_cost,0);
        const totalRatio = totalCost > 0 ? (totalExt/totalCost*100).toFixed(1) : '-';
        html += `<tr style="background:var(--primary); color:#fff; font-weight:700;"><td class="center">年度合計</td><td class="right">${fmtNT(totalOrder)}</td><td class="right">${fmtNT(totalShip)}</td><td class="right">${fmtNT(totalCost)}</td><td class="right">${fmtNT(totalExt)}</td><td class="center">${totalRatio}%</td></tr>`;
        html += '</tbody></table>';
        $('#trend-report-table-area').html(html);
    }, 'json');
}

// ══════════════════════════════════════════════════════════
//  單項產品分析
// ══════════════════════════════════════════════════════════
function openProductAnalysis(pid) {
    $('#pa-product-id').text(pid);
    $('#pa-order-tbody').html('<tr><td colspan="15" class="text-center" style="padding:30px;"><i class="fa fa-spinner fa-spin"></i> 載入中…</td></tr>');
    $('#pa-proc-list').html('');
    $('.pa-tab').first().click(); // 重設到第一個 tab
    $('#productAnalysisModal').modal('show');

    $.post('ERP_Cost_Analysis.php', {action:'get_product_order_analysis', product_id:pid}, function(res) {
        if (!res.success) { showToast('載入失敗: '+res.message, 'err'); return; }
        currentAnalysisData = res;
        allPaOrders = res.orders || [];

        // 填入客戶
        const client = (allPaOrders[0] || {}).client_display || (allPaOrders[0] || {}).client || '';
        $('#pa-client').text(client);

        // 填入年度篩選選項
        const years = [...new Set(allPaOrders.map(o => o.order_date ? o.order_date.substr(0,4) : '').filter(Boolean))].sort().reverse();
        $('#pa-yr').html('<option value="">全部</option>');
        years.forEach(y => $('#pa-yr').append(`<option value="${y}">${y}</option>`));

        filterPaOrders();
        renderPaProcs(res.process_breakdown);
    }, 'json');
}

function filterPaOrders() {
    const yr = $('#pa-yr').val();
    const onlyLoss = $('#pa-chk-loss').is(':checked');
    const onlyNoBom = $('#pa-chk-nobom').is(':checked');
    const onlyPdiff = $('#pa-chk-pdiff').is(':checked');

    let orders = allPaOrders.filter(o => {
        if (yr && (!o.order_date || o.order_date.substr(0,4) !== yr)) return false;
        if (onlyLoss && (o.gross_margin === null || parseFloat(o.gross_margin) >= 0)) return false;
        if (onlyNoBom && parseInt(o.is_mapped) > 0) return false;
        if (onlyPdiff) {
            const op = parseFloat(o.selling_price||0);
            const iMin = parseFloat(o.is_min_price||0);
            const iMax = parseFloat(o.is_max_price||0);
            if (op <= 0 || (iMin <= 0 && iMax <= 0)) return false;
            if (iMin === op && iMax === op) return false;
        }
        return true;
    });

    // 售價自動帶入邏輯：若 selling_price 為 0 且 is_min_price > 0，則以出貨價計
    orders.forEach(o => {
        if (parseFloat(o.selling_price||0) <= 0 && parseFloat(o.is_min_price||0) > 0) o.effective_price = parseFloat(o.is_min_price);
    });

    renderPaKpi(orders);
    renderPaOrders(orders);
}

function renderPaKpi(orders) {
    const total = orders.length;
    const prices = orders.filter(o=>parseFloat(o.selling_price||0)>0).map(o=>parseFloat(o.selling_price));
    const avgP = prices.length ? prices.reduce((a,b)=>a+b,0)/prices.length : null;
    const avgC = currentAnalysisData ? currentAnalysisData.avg_unit_cost : null;
    const margins = orders.filter(o=>o.gross_margin!==null&&o.gross_margin!==undefined).map(o=>parseFloat(o.gross_margin));
    const avgM = margins.length ? margins.reduce((a,b)=>a+b,0)/margins.length : null;
    const lossN = orders.filter(o=>o.gross_margin!==null&&parseFloat(o.gross_margin)<0).length;

    $('#pa-kpi-orders').text(total);
    $('#pa-kpi-avgprice').html(avgP ? fmtNT(avgP, 2) : '-');
    $('#pa-kpi-avgcost').html(avgC ? fmtNT(avgC, 2) : '-');
    $('#pa-kpi-margin').html(avgM !== null ? fmtPct(avgM) : '-');
    $('#pa-kpi-loss').text(lossN);
}

function renderPaOrders(orders) {
    if (!orders.length) {
        $('#pa-order-tbody').html('<tr><td colspan="17" class="text-center" style="padding:30px; color:var(--muted);">無資料</td></tr>');
        return;
    }
    let html = '';
    orders.forEach(o => {
        const sellP    = parseFloat(o.selling_price||0);
        const isMinP   = parseFloat(o.is_min_price||0);
        const isMaxP   = parseFloat(o.is_max_price||0);
        const effSell  = parseFloat(o.effective_sell_price||0);
        const src      = o.price_source || 'none';
        const unitCost = o.unit_cost !== null && o.unit_cost !== undefined ? parseFloat(o.unit_cost) : null;
        const adjCost  = o.adj_unit_cost !== null && o.adj_unit_cost !== undefined ? parseFloat(o.adj_unit_cost) : null;
        const mismatch = o.has_qty_mismatch;
        const bomQty   = parseFloat(o.bom_qty_used||0);
        const ordQ     = parseInt(o.order_qty||0);
        const shipQ    = parseFloat(o.shipped_qty||0);

        // ── 售價欄 ──
        let sellDisplay = '<span style="color:var(--muted);">-</span>';
        if (sellP > 0) {
            sellDisplay = fmtNT(sellP, 2);
        }

        // ── 出貨單價欄（含自動套用標示）──
        let shipPriceDisplay = '-';
        if (src === 'order_fallback') {
            // 有訂單售價但無出貨單，自動套用訂單售價
            shipPriceDisplay = `${fmtNT(sellP, 2)} <span title="尚無出貨單，自動套用訂單售價" style="color:#e67e22; font-size:10px; cursor:help;">⚠ 套用訂單價</span>`;
        } else if (src === 'shipment' && isMinP > 0) {
            shipPriceDisplay = fmtNT(isMinP, 2) + (isMinP !== isMaxP ? '~' + fmtNT(isMaxP, 2) : '');
        } else if (src === 'order' && isMinP > 0) {
            shipPriceDisplay = fmtNT(isMinP, 2) + (isMinP !== isMaxP ? '~' + fmtNT(isMaxP, 2) : '');
        }

        // ── 價格狀態 ──
        let priceStatus = '<span style="color:var(--muted);">-</span>';
        if (sellP > 0 && isMinP > 0) {
            if (isMinP === sellP && isMaxP === sellP) priceStatus = '<span class="badge-ok">相符</span>';
            else priceStatus = `<span class="badge-danger" title="出貨價 ${isMinP !== isMaxP ? isMinP+'~'+isMaxP : isMinP}">不符</span>`;
        } else if (sellP <= 0 && isMinP > 0) {
            priceStatus = `<span class="badge-warn">以出貨計</span>`;
        }

        // ── 綁定狀況 ──
        const isMapped = parseInt(o.is_mapped||0);
        const allocQ   = parseInt(o.total_allocated_qty||0);
        let bindBadge  = '';
        if (isMapped === 0) bindBadge = '<span class="badge-danger">未綁定</span>';
        else if (allocQ < ordQ) bindBadge = `<span class="badge-warn">不足 ${allocQ}/${ordQ}</span>`;
        else if (allocQ > ordQ) bindBadge = `<span class="badge-warn" style="background:#fff8e1;">超額 ${allocQ}/${ordQ}</span>`;
        else bindBadge = `<span class="badge-ok">${allocQ}pcs</span>`;

        // ── 數量不符警示 ──
        let qtyWarning = '';
        if (mismatch && bomQty > 0) {
            qtyWarning = `<span title="BOM ${bomQty}pcs，出貨 ${shipQ}pcs，數量不符" style="color:#e74c3c; font-size:13px; margin-left:4px; cursor:help;">⚠</span>`;
        }

        // ── 成本欄（標準 + 調整後）──
        let costDisplay = '<span style="color:var(--muted);">-</span>';
        if (unitCost !== null) {
            costDisplay = fmtNT(unitCost, 2);
            if (adjCost !== null) {
                costDisplay += `<br><span style="font-size:10px; color:#e74c3c;" title="BOM ${bomQty}pcs 總成本 ${fmtNT(bomQty * unitCost)} ÷ 實際出貨 ${shipQ}pcs">調整後: ${fmtNT(adjCost, 2)}</span>`;
            }
        }

        // ── 毛利率（標準 + 調整後）──
        let marginDisplay = '<span style="color:var(--muted);">-</span>';
        if (o.gross_margin !== null && o.gross_margin !== undefined) {
            marginDisplay = fmtPct(parseFloat(o.gross_margin));
            if (o.adj_gross_margin !== null && o.adj_gross_margin !== undefined) {
                marginDisplay += `<br><span style="font-size:10px;" title="以調整後成本計算">調整後: ${fmtPct(parseFloat(o.adj_gross_margin))}</span>`;
            }
        }

        const did_warn = o.did_warning ? `<span class="badge-danger" title="${o.did_warning}"><i class="fa fa-exclamation"></i></span>` : '';

        html += `<tr>
            <td><span style="font-size:11px; font-weight:600;">${escapeHtml(o.order_no||'')}${did_warn}</span></td>
            <td class="center" style="font-size:11px;">${(o.order_date||'').substr(0,10)}</td>
            <td style="font-size:11px; max-width:120px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(o.spec||'')}">${escapeHtml(o.spec||'-')}</td>
            <td class="right">${fmt(ordQ)}</td>
            <td class="right">${fmt(shipQ)}${qtyWarning}</td>
            <td class="center" style="font-size:11px;">${o.latest_ship_date ? o.latest_ship_date.substr(0,10) : '-'}</td>
            <td style="font-size:10px;">${(o.is_numbers||'').replace(/<br>/g,', ')}</td>
            <td class="right">${sellDisplay}</td>
            <td class="right" style="font-size:11px;">${shipPriceDisplay}</td>
            <td class="center">${priceStatus}</td>
            <td class="right" style="line-height:1.6;">${costDisplay}</td>
            <td class="center" style="line-height:1.6;">${marginDisplay}</td>
            <td style="font-size:10px; max-width:150px;">${o.process_combo_html || o.configured_process_combo || '-'}</td>
            <td class="center">${bindBadge}</td>
            <td class="center" style="white-space:nowrap;">
                <button class="btn btn-xs btn-primary" onclick="handleSettingClick(event,'${o.Order_id}','${escapeHtml($('#pa-product-id').text())}','${escapeHtml(o.order_no||'')}')" title="BOM設定"><i class="fa fa-cog"></i></button>
                <button class="btn btn-xs btn-default" onclick="showOrderProcDetail('${o.Order_id}')" title="製程明細"><i class="fa fa-list"></i></button>
            </td>
        </tr>`;
    });
    $('#pa-order-tbody').html(html);
}

function showOrderProcDetail(orderId) {
    if (!currentAnalysisData) return;
    const o = (currentAnalysisData.orders||[]).find(x => x.Order_id == orderId);
    if (!o) return;
    const title = `訂單 ${o.order_no} 製程明細（單顆成本）`;
    let html = '';
    if (!o.process_combo_html && !o.configured_process_combo) {
        html = '<p class="text-muted" style="text-align:center; padding:20px;">此訂單未綁定 BOM，無製程成本資料。</p>';
    } else {
        const unitCost  = o.unit_cost !== null ? parseFloat(o.unit_cost) : null;
        const adjCost   = o.adj_unit_cost !== null && o.adj_unit_cost !== undefined ? parseFloat(o.adj_unit_cost) : null;
        const effSell   = parseFloat(o.effective_sell_price || 0);
        const mismatch  = o.has_qty_mismatch;
        const bomQty    = parseFloat(o.bom_qty_used || 0);
        const shipQ     = parseFloat(o.shipped_qty  || 0);
        const ordQ      = parseFloat(o.order_qty    || 0);

        // 摘要區
        html += `<div style="background:#f0f3f8; border-radius:6px; padding:10px 14px; margin-bottom:12px; font-size:12px;">`;
        html += `<div><b>訂單數量：</b>${fmt(ordQ)} pcs　<b>BOM數量：</b>${fmt(bomQty)} pcs　<b>出貨數量：</b>${fmt(shipQ)} pcs`;
        if (mismatch) html += ` <span style="color:#e74c3c; font-weight:600;">⚠ 數量不符</span>`;
        html += `</div>`;
        if (unitCost !== null) {
            html += `<div style="margin-top:6px;"><b>標準單顆成本（1pc）：</b><span style="color:#2c5aa0; font-weight:700;">${fmtNT(unitCost, 2)}</span>`;
            if (adjCost !== null) {
                html += ` &nbsp;→&nbsp; <b>調整後單顆成本：</b><span style="color:#e74c3c; font-weight:700;">${fmtNT(adjCost, 2)}</span>`;
                html += `<br><small style="color:#888;">（BOM ${fmt(bomQty)}pcs × ${fmtNT(unitCost, 2)} = 總成本 ${fmtNT(bomQty * unitCost)} ÷ 實際出貨 ${fmt(shipQ)}pcs）</small>`;
            }
            html += `</div>`;
            if (effSell > 0) {
                const margin = ((effSell - unitCost) / effSell * 100);
                html += `<div style="margin-top:4px;"><b>售價：</b>${fmtNT(effSell, 2)}　<b>毛利率（標準）：</b>${fmtPct(margin)}`;
                if (adjCost !== null) {
                    const adjMargin = ((effSell - adjCost) / effSell * 100);
                    html += `　<b>毛利率（調整後）：</b>${fmtPct(adjMargin)}`;
                }
                html += `</div>`;
            }
        }
        html += `</div>`;

        // 各製程成本明細
        html += `<table class="proc-log-table"><thead><tr><th>製程</th><th class="right">平均單顆價格 (1pc)</th><th>備註</th></tr></thead><tbody>`;
        if (currentAnalysisData.process_breakdown) {
            // 從 process_breakdown 取整批成本，反推單顆
            // 但這裡改從每訂單製程資料來顯示
        }
        // 直接從 order 的 process_combo_html 解析不到數字，需從後端傳 per-proc detail
        // 暫用 process_breakdown 顯示各製程佔比（整體平均），並標示這是全料號平均
        const bd = currentAnalysisData.process_breakdown || {};
        const hasPerOrder = o.unit_cost !== null;
        if (hasPerOrder && Object.keys(bd).length > 0) {
            const totalBd = Object.values(bd).reduce((a,b)=>a+b, 0);
            Object.entries(bd).forEach(([pname, cost]) => {
                // 按比例推算本製程的單顆成本
                const pct    = totalBd > 0 ? cost / totalBd : 0;
                const perPc  = unitCost !== null ? unitCost * pct : null;
                html += `<tr>
                    <td>${escapeHtml(pname)}</td>
                    <td class="right">${perPc !== null ? fmtNT(perPc, 2) : '-'}</td>
                    <td style="font-size:10px; color:#888;">佔比 ${(pct*100).toFixed(1)}%（全料號平均）</td>
                </tr>`;
            });
        } else {
            html += `<tr><td colspan="3" class="text-center" style="color:var(--muted); padding:12px;">無詳細製程資料</td></tr>`;
        }
        html += `</tbody></table>`;
        html += `<p style="font-size:10px; color:#aaa; margin-top:8px;">* 製程佔比依全料號歷史加工成本計算，單顆成本為本訂單綁定BOM之製程平均單價加總。</p>`;
    }
    openProcDrawer(title, html);
}

function renderPaProcs(breakdown) {
    if (!breakdown || !Object.keys(breakdown).length) {
        $('#pa-proc-list').html('<p class="text-muted" style="text-align:center; padding:20px;">無製程資料</p>');
        return;
    }
    const avgUnitCost = currentAnalysisData ? parseFloat(currentAnalysisData.avg_unit_cost || 0) : 0;
    const total = Object.values(breakdown).reduce((a,b)=>a+b, 0);
    let html = `<p style="font-size:12px; color:var(--muted); margin-bottom:10px;">
        以下為全料號歷史加工成本佔比，單顆成本為各製程平均單價（1pc）。
        <strong>合計單顆成本：${fmtNT(avgUnitCost, 2)}</strong>
    </p>`;
    Object.entries(breakdown).sort((a,b)=>b[1]-a[1]).forEach(([pname, cost]) => {
        const pct    = total > 0 ? (cost/total*100).toFixed(1) : 0;
        const perPc  = avgUnitCost > 0 && total > 0 ? avgUnitCost * (cost / total) : 0;
        const bar    = `<div style="height:6px; width:${Math.min(pct,100)}%; background:var(--primary); border-radius:3px;"></div>`;
        html += `<div style="display:flex; align-items:center; gap:10px; margin-bottom:8px; font-size:12px;">
            <div style="min-width:120px; font-weight:600;">${escapeHtml(pname)}</div>
            <div style="flex:1; background:#eee; border-radius:3px; height:6px;">${bar}</div>
            <div style="min-width:40px; text-align:right; color:var(--muted);">${pct}%</div>
            <div style="min-width:90px; text-align:right; font-weight:600;" title="1pc 約 ${fmtNT(perPc, 2)}">${fmtNT(perPc, 2)}<small style="color:#aaa;">/pc</small></div>
        </div>`;
    });
    $('#pa-proc-list').html(html);
}

function renderPaCharts() {
    if (!currentAnalysisData) return;
    const orders = allPaOrders.filter(o => o.order_date);

    const sorted = [...orders].sort((a,b)=>a.order_date.localeCompare(b.order_date));
    const tLabels = sorted.map(o => o.order_date.substr(0,10));
    const tCosts  = sorted.map(o => o.unit_cost ? parseFloat(o.unit_cost) : null);
    const tPrices = sorted.map(o => o.selling_price ? parseFloat(o.selling_price) : null);

    try {
        if (paCharts.trend) { paCharts.trend.destroy(); paCharts.trend = null; }
        const ctxPT = document.getElementById('pa-trend-chart');
        if (ctxPT) {
            paCharts.trend = new Chart(ctxPT, {
                type: 'line',
                data: {
                    labels: tLabels,
                    datasets: [
                        { label:'單位成本', data:tCosts, borderColor:'#e74c3c', backgroundColor:'rgba(231,76,60,0.06)', tension:0.3, spanGaps:true, pointRadius:4 },
                        { label:'訂單售價', data:tPrices, borderColor:'#27ae60', backgroundColor:'rgba(39,174,96,0.06)', tension:0.3, spanGaps:true, pointRadius:4 }
                    ]
                },
                options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{y:{ticks:{callback:function(v){return 'NT$'+fmt(v);}}}} }
            });
        }
    } catch(e) { console.warn('pa-trend-chart error:', e); }

    try {
        const bd = currentAnalysisData.process_breakdown || {};
        const pLabels = Object.keys(bd);
        const pData = Object.values(bd).map(Number);
        const pColors = ['#2c5aa0','#27ae60','#f39c12','#e74c3c','#8e44ad','#16a085','#d35400','#2980b9'];
        if (paCharts.pie) { paCharts.pie.destroy(); paCharts.pie = null; }
        const ctxPP = document.getElementById('pa-pie-chart');
        if (ctxPP) {
            paCharts.pie = new Chart(ctxPP, {
                type: 'doughnut',
                data: { labels:pLabels, datasets:[{data:pData, backgroundColor:pColors, borderWidth:1}] },
                options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'right'}} }
            });
        }
    } catch(e) { console.warn('pa-pie-chart error:', e); }
}

// ══════════════════════════════════════════════════════════
//  多客戶選擇 UI
// ══════════════════════════════════════════════════════════
var _selectedClients = [];

function renderMultiClientList(clients) {
    if (!clients || !clients.length) {
        $('#ca-multi-list').html('<div style="padding:8px; color:var(--muted); font-size:12px;">載入中或無客戶資料</div>');
        return;
    }
    const kw = ($('#ca-multi-search').val()||'').toLowerCase();
    const filtered = clients.filter(c => !kw || c.toLowerCase().includes(kw));
    let html = '';
    filtered.forEach(c => {
        const checked = _selectedClients.includes(c);
        const disabled = !checked && _selectedClients.length >= 5;
        html += `<label style="display:flex; align-items:center; padding:3px 8px; cursor:${disabled?'not-allowed':'pointer'}; color:${disabled?'#bbb':'inherit'}; font-size:12px; font-weight:normal;">
            <input type="checkbox" value="${escapeHtml(c)}" ${checked?'checked':''} ${disabled?'disabled':''} onchange="toggleClientSelection('${escapeHtml(c).replace(/'/g,"\\'")}',this.checked)" style="margin-right:6px;">
            ${escapeHtml(c)}
        </label>`;
    });
    $('#ca-multi-list').html(html || '<div style="padding:8px; color:var(--muted); font-size:12px;">無符合客戶</div>');
}

function filterMultiClientList() {
    renderMultiClientList(window._allClientList || []);
}

function toggleClientSelection(client, checked) {
    if (checked) {
        if (_selectedClients.length >= 5) return;
        if (!_selectedClients.includes(client)) _selectedClients.push(client);
    } else {
        _selectedClients = _selectedClients.filter(c => c !== client);
    }
    updateSelectedTags();
    renderMultiClientList(window._allClientList || []);
}

function updateSelectedTags() {
    const count = _selectedClients.length;
    $('#ca-selected-count').text(count);
    let html = '';
    _selectedClients.forEach(c => {
        html += `<span style="background:var(--primary); color:#fff; padding:2px 8px; border-radius:12px; font-size:11px; display:flex; align-items:center; gap:4px;">
            ${escapeHtml(c)}
            <span onclick="toggleClientSelection('${escapeHtml(c)}',false)" style="cursor:pointer; font-size:14px; line-height:1;">&times;</span>
        </span>`;
    });
    $('#ca-selected-tags').html(html || '<span style="font-size:11px; color:var(--muted);">尚未選擇</span>');
}

function clearMultiSelection() {
    _selectedClients = [];
    updateSelectedTags();
    renderMultiClientList(window._allClientList || []);
    $('#ca-multi-area').hide();
    $('#btn-export-multi-pdf').hide();
}
var caCharts = {};
var caData = null; // 最後一次客戶分析結果
var caMode = ''; // 'single' | 'multi'
var caCurrentClients = [];

const CA_COLORS = ['#2c5aa0','#27ae60','#e74c3c','#f39c12','#8e44ad'];

function getClientFilters() {
    return { filter_start: $('#fc-start').val()||'', filter_end: $('#fc-end').val()||'' };
}

function loadSingleClientAnalysis() {
    const client = $('#ca-single-client').val();
    if (!client) { showToast('請選擇客戶', 'err'); return; }
    caMode = 'single';
    caCurrentClients = [client];
    $('#ca-multi-area').hide();
    $('#btn-export-multi-pdf').hide();
    $('#ca-single-area').html('<div class="text-center" style="padding:40px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>').show();
    $('#btn-export-single-pdf').show();

    const f = getClientFilters();
    $.post('ERP_Cost_Analysis.php', { action:'get_client_analysis', clients:[client], filter_start:f.filter_start, filter_end:f.filter_end }, function(res) {
        if (!res.success) { showToast('載入失敗: '+res.message, 'err'); return; }
        caData = res;
        renderSingleClientUI(client, res);
    }, 'json');
}

function renderSingleClientUI(client, res) {
    // 彙總
    const partRows = (res.part_rows||[]).filter(r => r.client_name === client);
    const totalRevenue = partRows.reduce((a,r)=>a+parseFloat(r.total_revenue||0),0);
    const totalCost    = partRows.reduce((a,r)=>a+parseFloat(r.total_cost||0),0);
    const totalOrders  = partRows.reduce((a,r)=>a+parseInt(r.order_count||0),0);
    const avgSell      = partRows.filter(r=>parseFloat(r.avg_sell_price||0)>0).map(r=>parseFloat(r.avg_sell_price));
    const avgSellAvg   = avgSell.length ? avgSell.reduce((a,b)=>a+b,0)/avgSell.length : 0;
    const avgCostAvg   = partRows.filter(r=>parseFloat(r.total_qty||0)>0).reduce((a,r)=> a + parseFloat(r.total_cost||0)/parseFloat(r.total_qty), 0) / (partRows.filter(r=>parseFloat(r.total_qty||0)>0).length || 1);
    const margin       = avgSellAvg > 0 && avgCostAvg > 0 ? (avgSellAvg - avgCostAvg) / avgSellAvg * 100 : null;

    let html = `<div class="kpi-row" style="margin-bottom:14px;">
        <div class="kpi-card"><div class="kpi-label">訂單總數</div><div class="kpi-val">${fmt(totalOrders)}</div></div>
        <div class="kpi-card kpi-success"><div class="kpi-label">總訂單金額</div><div class="kpi-val" style="font-size:16px;">${fmtNT(totalRevenue)}</div></div>
        <div class="kpi-card"><div class="kpi-label">總加工成本</div><div class="kpi-val" style="font-size:16px;">${fmtNT(totalCost)}</div></div>
        <div class="kpi-card"><div class="kpi-label">平均售價</div><div class="kpi-val">${avgSellAvg>0?fmtNT(avgSellAvg,2):'-'}</div></div>
        <div class="kpi-card ${margin!==null&&margin<0?'kpi-danger':'kpi-success'}"><div class="kpi-label">平均毛利率</div><div class="kpi-val">${margin!==null?margin.toFixed(1)+'%':'-'}</div></div>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
        <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
            <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-line-chart"></i> 月度訂單金額趨勢</div>
            <div style="position:relative; height:200px;"><canvas id="ca-trend-chart"></canvas></div>
        </div>
        <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
            <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-pie-chart"></i> 料號加工成本佔比</div>
            <div style="position:relative; height:200px;"><canvas id="ca-part-pie-chart"></canvas></div>
        </div>
    </div>
    <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow); margin-bottom:14px;">
        <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-bar-chart"></i> 成本 vs 售價（依料號，前10筆）</div>
        <div style="position:relative; height:220px;"><canvas id="ca-cost-bar-chart"></canvas></div>
    </div>
    <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
        <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-table"></i> 料號明細</div>
        <div style="overflow-x:auto;"><table class="pa-tbl">
            <thead><tr><th>料號</th><th>訂單數</th><th>總訂單額</th><th>總加工成本</th><th>平均售價</th><th>平均成本(/pc)</th><th>估算毛利率</th></tr></thead>
            <tbody id="ca-part-tbody"></tbody>
        </table></div>
    </div>`;
    $('#ca-single-area').html(html);

    // 月度趨勢
    const trendRows = (res.trend_rows||[]).filter(r=>r.client_name===client);
    const ymSet = [...new Set(trendRows.map(r=>r.ym))].sort();
    const ymRevMap = {}; trendRows.forEach(r=>{ ymRevMap[r.ym]=(ymRevMap[r.ym]||0)+parseFloat(r.total_revenue||0); });
    const ymCostMap = {}; trendRows.forEach(r=>{ ymCostMap[r.ym]=(ymCostMap[r.ym]||0)+parseFloat(r.total_cost||0); });
    if (caCharts.trend) { caCharts.trend.destroy(); caCharts.trend=null; }
    const ctxT = document.getElementById('ca-trend-chart');
    if (ctxT) {
        caCharts.trend = new Chart(ctxT, {
            type:'line',
            data:{ labels:ymSet, datasets:[
                {label:'訂單金額', data:ymSet.map(m=>ymRevMap[m]||0), borderColor:'#2c5aa0', backgroundColor:'rgba(44,90,160,0.07)', tension:0.3},
                {label:'加工成本', data:ymSet.map(m=>ymCostMap[m]||0), borderColor:'#e74c3c', backgroundColor:'rgba(231,76,60,0.07)', tension:0.3}
            ]},
            options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{y:{ticks:{callback:v=>v>=1000000?(v/1000000).toFixed(1)+'M':v>=1000?(v/1000).toFixed(0)+'K':v}}}}
        });
    }

    // 料號佔比 pie
    const topParts = [...partRows].sort((a,b)=>parseFloat(b.total_cost||0)-parseFloat(a.total_cost||0)).slice(0,8);
    if (caCharts.pie) { caCharts.pie.destroy(); caCharts.pie=null; }
    const ctxP = document.getElementById('ca-part-pie-chart');
    if (ctxP && topParts.length) {
        caCharts.pie = new Chart(ctxP, {
            type:'doughnut',
            data:{ labels:topParts.map(r=>r.part_no), datasets:[{data:topParts.map(r=>parseFloat(r.total_cost||0)), backgroundColor:CA_COLORS.concat(['#16a085','#d35400','#2980b9']), borderWidth:1}] },
            options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'right', labels:{font:{size:10}}}}}
        });
    }

    // 成本 vs 售價 bar（前10）
    const top10 = [...partRows].sort((a,b)=>parseFloat(b.total_cost||0)-parseFloat(a.total_cost||0)).slice(0,10);
    if (caCharts.bar) { caCharts.bar.destroy(); caCharts.bar=null; }
    const ctxB = document.getElementById('ca-cost-bar-chart');
    if (ctxB && top10.length) {
        caCharts.bar = new Chart(ctxB, {
            type:'bar',
            data:{ labels:top10.map(r=>r.part_no), datasets:[
                {label:'平均售價', data:top10.map(r=>parseFloat(r.avg_sell_price||0)), backgroundColor:'rgba(39,174,96,0.7)'},
                {label:'平均成本(/pc)', data:top10.map(r=>parseFloat(r.total_qty||0)>0?parseFloat(r.total_cost)/parseFloat(r.total_qty):0), backgroundColor:'rgba(231,76,60,0.7)'}
            ]},
            options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{y:{ticks:{callback:v=>'NT$'+fmt(v)}}}}
        });
    }

    // 料號明細 table
    let tbHtml = '';
    partRows.forEach(r=>{
        const avgCostPc = parseFloat(r.total_qty||0)>0 ? parseFloat(r.total_cost)/parseFloat(r.total_qty) : 0;
        const m = parseFloat(r.avg_sell_price||0)>0 && avgCostPc>0 ? ((parseFloat(r.avg_sell_price)-avgCostPc)/parseFloat(r.avg_sell_price)*100) : null;
        const mc = m===null?'':m<0?'color:#e74c3c':m<20?'color:#f39c12':'color:#27ae60';
        tbHtml += `<tr>
            <td><strong style="color:var(--primary);">${escapeHtml(r.part_no)}</strong></td>
            <td class="center">${r.order_count}</td>
            <td class="right">${fmtNT(r.total_revenue)}</td>
            <td class="right">${fmtNT(r.total_cost)}</td>
            <td class="right">${parseFloat(r.avg_sell_price||0)>0?fmtNT(r.avg_sell_price,2):'-'}</td>
            <td class="right">${avgCostPc>0?fmtNT(avgCostPc,2):'-'}</td>
            <td class="center" style="${mc}">${m!==null?m.toFixed(1)+'%':'-'}</td>
        </tr>`;
    });
    $('#ca-part-tbody').html(tbHtml||'<tr><td colspan="7" class="text-center text-muted">無資料</td></tr>');
}

function loadMultiClientComparison() {
    const selected = _selectedClients;
    if (!selected.length) { showToast('請選擇至少一個客戶', 'err'); return; }
    if (selected.length > 5) { showToast('最多選擇5個客戶', 'err'); return; }
    caMode = 'multi';
    caCurrentClients = selected;
    $('#ca-single-area').hide();
    $('#btn-export-single-pdf').hide();
    $('#ca-multi-area').html('<div class="text-center" style="padding:40px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>').show();
    $('#btn-export-multi-pdf').show();

    const f = getClientFilters();
    $.post('ERP_Cost_Analysis.php', { action:'get_client_analysis', clients:selected, filter_start:f.filter_start, filter_end:f.filter_end }, function(res) {
        if (!res.success) { showToast('載入失敗: '+res.message, 'err'); return; }
        caData = res;
        renderMultiClientUI(selected, res);
    }, 'json');
}

function renderMultiClientUI(clients, res) {
    // 各客戶彙總
    const summary = clients.map((cl, ci) => {
        const pr = (res.part_rows||[]).filter(r=>r.client_name===cl);
        const tr = (res.trend_rows||[]).filter(r=>r.client_name===cl);
        const totalRev  = pr.reduce((a,r)=>a+parseFloat(r.total_revenue||0),0);
        const totalCost = pr.reduce((a,r)=>a+parseFloat(r.total_cost||0),0);
        const totalOrd  = pr.reduce((a,r)=>a+parseInt(r.order_count||0),0);
        const partCnt   = new Set(pr.map(r=>r.part_no)).size;
        const avgSell   = pr.filter(r=>parseFloat(r.avg_sell_price||0)>0);
        const avgSellAvg= avgSell.length ? avgSell.reduce((a,r)=>a+parseFloat(r.avg_sell_price),0)/avgSell.length : 0;
        const totalQty  = pr.reduce((a,r)=>a+parseFloat(r.total_qty||0),0);
        const avgCostPc = totalQty>0 ? totalCost/totalQty : 0;
        const margin    = avgSellAvg>0 && avgCostPc>0 ? (avgSellAvg-avgCostPc)/avgSellAvg*100 : null;
        return { client:cl, partCnt, totalOrd, totalRev, totalCost, avgSellAvg, avgCostPc, margin, pr, tr, color:CA_COLORS[ci]||'#999' };
    });

    // 重建 HTML 結構
    $('#ca-multi-area').html(`
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
            <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
                <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-bar-chart"></i> 各客戶總加工成本比較</div>
                <div style="position:relative; height:220px;"><canvas id="ca-multi-cost-chart"></canvas></div>
            </div>
            <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
                <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-bar-chart"></i> 各客戶平均毛利率比較</div>
                <div style="position:relative; height:220px;"><canvas id="ca-multi-margin-chart"></canvas></div>
            </div>
        </div>
        <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow); margin-bottom:14px;">
            <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-line-chart"></i> 各客戶月度訂單金額趨勢對比</div>
            <div style="position:relative; height:240px;"><canvas id="ca-multi-trend-chart"></canvas></div>
        </div>
        <div style="background:var(--card); border-radius:10px; padding:14px; box-shadow:var(--shadow);">
            <div style="font-size:12px; font-weight:600; color:var(--primary); margin-bottom:10px;"><i class="fa fa-table"></i> 客戶比較表</div>
            <div style="overflow-x:auto;"><table class="pa-tbl">
                <thead><tr><th>客戶</th><th>料號數</th><th>訂單數</th><th>總訂單額</th><th>總加工成本</th><th>平均售價</th><th>平均成本(/pc)</th><th>平均毛利率</th></tr></thead>
                <tbody id="ca-multi-tbody"></tbody>
            </table></div>
        </div>
    `);

    // 成本 bar chart
    if (caCharts.multiCost) { caCharts.multiCost.destroy(); caCharts.multiCost=null; }
    const ctxMC = document.getElementById('ca-multi-cost-chart');
    if (ctxMC) {
        caCharts.multiCost = new Chart(ctxMC, {
            type:'bar',
            data:{ labels:summary.map(s=>s.client), datasets:[
                {label:'總加工成本', data:summary.map(s=>s.totalCost), backgroundColor:summary.map(s=>s.color+'bb')},
                {label:'總訂單額', data:summary.map(s=>s.totalRev), backgroundColor:summary.map(s=>s.color+'44'), borderColor:summary.map(s=>s.color), borderWidth:1}
            ]},
            options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{y:{ticks:{callback:v=>v>=1000000?(v/1000000).toFixed(1)+'M':v>=1000?(v/1000).toFixed(0)+'K':v}}}}
        });
    }

    // 毛利率 bar chart
    if (caCharts.multiMargin) { caCharts.multiMargin.destroy(); caCharts.multiMargin=null; }
    const ctxMM = document.getElementById('ca-multi-margin-chart');
    if (ctxMM) {
        caCharts.multiMargin = new Chart(ctxMM, {
            type:'bar',
            data:{ labels:summary.map(s=>s.client), datasets:[{label:'平均毛利率 (%)', data:summary.map(s=>s.margin), backgroundColor:summary.map(s=>s.margin!==null&&s.margin<0?'#e74c3c99':'#27ae6099')}]},
            options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}, scales:{y:{ticks:{callback:v=>v+'%'}}}}
        });
    }

    // 月度趨勢 line chart（訂單金額趨勢）
    const allYm = [...new Set((res.trend_rows||[]).map(r=>r.ym))].sort();
    if (caCharts.multiTrend) { caCharts.multiTrend.destroy(); caCharts.multiTrend=null; }
    const ctxMT = document.getElementById('ca-multi-trend-chart');
    if (ctxMT) {
        // 訂單金額 datasets
        const revDatasets = summary.map(s=>{
            const revByYm = {};
            s.tr.forEach(r=>{ revByYm[r.ym]=(revByYm[r.ym]||0)+parseFloat(r.total_revenue||0); });
            return {
                label: s.client + ' 訂單額',
                data: allYm.map(m=>revByYm[m]||0),
                borderColor: s.color,
                backgroundColor: 'transparent',
                tension: 0.3,
                yAxisID: 'y',
                borderWidth: 2,
            };
        });

        caCharts.multiTrend = new Chart(ctxMT, {
            type: 'line',
            data: { labels: allYm, datasets: revDatasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 10 },
                            boxWidth: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const v = ctx.parsed.y;
                                if (v === null) return null;
                                return ctx.dataset.label + ': ' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        position: 'left',
                        title: { display: true, text: '訂單金額', font: { size: 10 } },
                        ticks: { callback: v => v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v }
                    }
                }
            }
        });
    }

    // 比較 table
    let tbHtml = '';
    summary.forEach(s=>{
        const mc = s.margin===null?'':s.margin<0?'color:#e74c3c':s.margin<20?'color:#f39c12':'color:#27ae60';
        tbHtml += `<tr>
            <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${s.color};margin-right:6px;"></span><strong>${escapeHtml(s.client)}</strong></td>
            <td class="center">${s.partCnt}</td><td class="center">${s.totalOrd}</td>
            <td class="right">${fmtNT(s.totalRev)}</td><td class="right">${fmtNT(s.totalCost)}</td>
            <td class="right">${s.avgSellAvg>0?fmtNT(s.avgSellAvg,2):'-'}</td>
            <td class="right">${s.avgCostPc>0?fmtNT(s.avgCostPc,2):'-'}</td>
            <td class="center" style="${mc}">${s.margin!==null?s.margin.toFixed(1)+'%':'-'}</td>
        </tr>`;
    });
    $('#ca-multi-tbody').html(tbHtml);
}

// ══════════════════════════════════════════════════════════
//  PDF 匯出
// ══════════════════════════════════════════════════════════
function printCurrentTab() {
    const tab = $('.pa-tab[data-main-tab].active').data('main-tab') || 'list';
    if (tab === 'list')     exportListPdf();
    else if (tab === 'trend') exportTrendPdf();
    else if (tab === 'customer') {
        if (caMode === 'multi') exportMultiClientPdf();
        else exportSingleClientPdf();
    }
}

// ── 統一列印 style 注入 ──
function _injectPrintStyle(pageSize, landscape) {
    const old = document.getElementById('dynamic-print-style');
    if (old) old.remove();
    const s = document.createElement('style');
    s.id = 'dynamic-print-style';
    const size = pageSize + (landscape ? ' landscape' : ' portrait');
    s.textContent = `
        @media print {
            @page { size: ${size}; margin: 0; }
            body { zoom: 0.90; padding: 10mm; box-sizing: border-box;
                   -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    `;
    document.head.appendChild(s);
}
function _removePrintStyle() {
    const s = document.getElementById('dynamic-print-style');
    if (s) s.remove();
}

function _doPrint(bodyClass, setup, teardown) {
    const prev = document.body.className;
    document.body.className = bodyClass;
    if (setup) setup();
    function onAfterPrint() {
        window.removeEventListener('afterprint', onAfterPrint);
        document.body.className = prev;
        _removePrintStyle();
        // 重建客戶分析圖表，避免 canvas 因列印 CSS 殘留變形
        if (typeof caData !== 'undefined' && caData) {
            if (caMode === 'single' && caCurrentClients[0]) {
                try { renderSingleClientUI(caCurrentClients[0], caData); } catch(e) {}
            } else if (caMode === 'multi' && caCurrentClients.length) {
                try { renderMultiClientUI(caCurrentClients, caData); } catch(e) {}
            }
        }
        if (teardown) teardown();
    }
    window.addEventListener('afterprint', onAfterPrint);
    setTimeout(function() { window.print(); }, 300);
}

function exportListPdf() {
    const range = ($('#fc-start').val()||'') + ' ~ ' + ($('#fc-end').val()||'');
    $('#ph-dash-range').text(range);
    _injectPrintStyle('A4', true); // 主頁圖表橫向
    _doPrint('print-dashboard', null, null);
}

function exportTrendPdf() {
    const year = $('#fc-year').val() || new Date().getFullYear();
    $('#ph-trend-year').text(year);
    _injectPrintStyle('A4', false); // 趨勢報告直式
    _doPrint('print-trend', null, null);
}

function exportSingleClientPdf() {
    const client = caCurrentClients[0] || '';
    const range = ($('#fc-start').val()||'') + ' ~ ' + ($('#fc-end').val()||'');
    $('#ph-client-name').text(client);
    $('#ph-client-range').text(range);
    _injectPrintStyle('A4', false); // 單客戶直式
    _doPrint('print-client-single', null, null);
}

function exportMultiClientPdf() {
    const clients = caCurrentClients.join('、');
    const range = ($('#fc-start').val()||'') + ' ~ ' + ($('#fc-end').val()||'');
    $('#ph-multi-clients').text(clients);
    $('#ph-multi-range').text(range);
    _injectPrintStyle('A3', true); // 多客戶 A3 橫向
    _doPrint('print-client-multi', null, null);
}
function openProcDrawer(title, content) {
    $('#dr-title').html(title);
    $('#dr-content').html(content);
    $('#procDrawer').addClass('open');
    $('#drawer-overlay').show();
}
function closeProcDrawer() {
    $('#procDrawer').removeClass('open');
    $('#drawer-overlay').hide();
}

// ══════════════════════════════════════════════════════════
//  舊有 analysisModal 相容（主列表製程明細按鈕用）
// ══════════════════════════════════════════════════════════
var legacyDbData = [];
function openAnalysisModalById(partNo) {
    // 以 AJAX 取得該料號製程明細資料
    $.post('ERP_Cost_Analysis.php', {action:'get_product_order_analysis', product_id:partNo}, function(res) {
        if (!res.success) { showToast('載入失敗', 'err'); return; }
        const bd = res.process_breakdown || {};
        const total = Object.values(bd).reduce((a,b)=>a+b, 0);
        document.getElementById('modalTitle').innerHTML = `<a href="javascript:void(0);" onclick="openProductFiles('${partNo}')">${partNo}</a>`;
        document.getElementById('modalTotalCost').innerText = '平均單位成本: NT$ ' + fmt(res.avg_unit_cost, 2);
        document.getElementById('modalCostBreakdown').innerHTML = '';
        let procHtml = '';
        Object.entries(bd).forEach(([pname, cost]) => {
            const pct = total > 0 ? (cost/total*100).toFixed(1) : 0;
            procHtml += `<div class="process-item">
                <div class="process-item-header" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='block'?'none':'block'">
                    <span>${escapeHtml(pname)}</span><span>NT$ ${fmt(cost)} (${pct}%)</span>
                </div>
                <div class="process-item-body" style="display:none;"><p style="color:var(--muted);">製程占比: ${pct}%</p></div>
            </div>`;
        });
        document.getElementById('modalProcessList').innerHTML = procHtml || '<p class="text-muted text-center" style="padding:20px;">無製程資料</p>';
        document.getElementById('analysisModal').style.display = 'block';
    }, 'json');
}
function closeModal() { document.getElementById('analysisModal').style.display = 'none'; }
function expandAllLogs() { document.querySelectorAll('.process-item-body').forEach(el => el.style.display='block'); }
function collapseAllLogs() { document.querySelectorAll('.process-item-body').forEach(el => el.style.display='none'); }

// ══════════════════════════════════════════════════════════
//  成本設定儲存
// ══════════════════════════════════════════════════════════
function saveCostSettings() {
    const matType = $('#material_process_type_select').val();
    const vendors = [];
    $('#selected-vendors option').each(function(){ vendors.push($(this).val()); });
    const ignored = [];
    $('.ignored-type-cb:checked').each(function(){ ignored.push($(this).val()); });
    $.post('ERP_Cost_Analysis.php', {action:'save_cost_settings', material_process_type:matType, inhouse_vendors:vendors}, function(res) {
        $.post('ERP_Cost_Analysis.php', {action:'save_ignored_zero_cost_types', ignored_process_types:ignored}, function() {
            showToast('設定已儲存');
            $('#costConfigModal').modal('hide');
        }, 'json');
    }, 'json');
}

// ══════════════════════════════════════════════════════════
//  料號設定 Modal
// ══════════════════════════════════════════════════════════
function openPartSettingModal(pid) {
    $.post('ERP_Cost_Analysis.php', {action:'get_part_setting', product_id:pid}, function(res) {
        if (!res.success) {
            // 料號不存在於 d_setting，開啟建立新料號的介面
            $('#setting_d_id').val('');
            $('#setting_part_no').val(pid);
            $('#setting_type').val('N');
            $('#setting_is_assembly').prop('checked', false);
            $('#setting_drawing_no').val('');
            $('#setting_spec_no').val('');
            $('#setting_customer').val('');
            $('#setting_revision').val('');
            $('#setting_issue_date').val('');
            $('#setting_remark').val('');
            $('#gear_details_section').hide();
            $('#gear_rows_container').empty();
            $('#assembly_details_section').hide();
            $('#child_parts_container').empty();
            $.post('ERP_Cost_Analysis.php', {action:'search_customers', keyword:''}, function(clients) {
                $('#customer_datalist').empty();
                (clients||[]).forEach(c => $('#customer_datalist').append(`<option value="${escapeHtml(c)}">`));
            }, 'json');
            showToast('此料號尚未建立設定，請填寫後儲存。', 'ok');
            $('#partSettingModal').modal('show');
            return;
        }
        const d = res.data;
        $('#setting_d_id').val(d.d_id||'');
        $('#setting_part_no').val(pid);
        $('#setting_type').val(d.Type||'N');
        $('#setting_is_assembly').prop('checked', d.Is_Assembly == 1);
        $('#setting_drawing_no').val(d.Drawing_No||'');
        $('#setting_spec_no').val(d.Spec_No||'');
        $('#setting_customer').val(d.Client_Name_Lookup||'');
        $('#setting_revision').val(d.Revision||'');
        $('#setting_issue_date').val(d.Issue_Date||'');
        $('#setting_remark').val(d.Remark||'');
        // 齒輪
        const isGear = d.Type === 'G' || d.Type === 'H';
        $('#gear_details_section').toggle(isGear);
        if (isGear && d.gear_info && d.gear_info.length > 0) {
            $('#gear_rows_container').empty();
            d.gear_info.forEach(g => addGearRow(g));
        }
        // 組合件
        const isAssembly = d.Is_Assembly == 1;
        $('#assembly_details_section').toggle(isAssembly);
        if (isAssembly && d.child_parts && d.child_parts.length > 0) {
            $('#child_parts_container').empty();
            d.child_parts.forEach(cp => addChildPartRow(cp));
        }
        // 客戶 autocomplete
        $.post('ERP_Cost_Analysis.php', {action:'search_customers', keyword:''}, function(clients) {
            $('#customer_datalist').empty();
            (clients||[]).forEach(c => $('#customer_datalist').append(`<option value="${escapeHtml(c)}">`));
        }, 'json');
        $('#partSettingModal').modal('show');
    }, 'json');
}

function saveItemSettings() {
    const did = $('#setting_d_id').val();
    const partNo = $('#setting_part_no').val();
    const type = $('#setting_type').val();
    const isAssembly = $('#setting_is_assembly').is(':checked') ? 1 : 0;
    const drawingNo = $('#setting_drawing_no').val();
    const specNo = $('#setting_spec_no').val();
    const customer = $('#setting_customer').val();
    const revision = $('#setting_revision').val();
    const issueDate = $('#setting_issue_date').val();
    const remark = $('#setting_remark').val();
    // Gear rows
    const gearRows = [];
    $('#gear_rows_container .gear-row').each(function() {
        const $r = $(this);
        gearRows.push({ gear_id:$r.find('.gear-id').val(), Module:$r.find('.gear-module').val(), Teeth:$r.find('.gear-teeth').val(), Face_Width:$r.find('.gear-width').val(), Helix_Direction:$r.find('.gear-helix-dir').val(), Helix_Angle_Str:$r.find('.gear-helix-angle').val(), Pressure_Angle:$r.find('.gear-pressure').val(), Gear_Type:$r.find('.gear-type').val(), Profile_Shift_X:$r.find('.gear-shift').val(), Workpiece_Length:$r.find('.gear-length').val(), Spec_No:$r.find('.gear-spec').val(), Remark_Gear:$r.find('.gear-remark').val() });
    });
    const childParts = [];
    $('#child_parts_container .child-row').each(function() {
        const $r = $(this);
        childParts.push({ bom_id:$r.find('.child-bom-id').val(), child_d_id_str:$r.find('.child-part-no').val(), standard_qty:$r.find('.child-qty').val(), Remark_Bom:$r.find('.child-remark').val() });
    });
    $.post('ERP_Cost_Analysis.php', {action:'save_part_setting', d_id:did, part_no:partNo, type:type, is_assembly:isAssembly, drawing_no:drawingNo, spec_no:specNo, customer:customer, revision:revision, issue_date:issueDate, remark:remark, gear_rows:JSON.stringify(gearRows), child_parts:JSON.stringify(childParts)}, function(res) {
        if (res.success) { showToast('料號設定已儲存'); $('#partSettingModal').modal('hide'); }
        else alert('儲存失敗: ' + res.message);
    }, 'json');
}

function addGearRow(data) {
    data = data || {};
    const html = `<div class="gear-row" style="background:#fff; border:1px solid #ffd; border-radius:4px; padding:10px; margin-bottom:8px;">
        <input type="hidden" class="gear-id" value="${data.gear_id||''}">
        <div class="row"><div class="col-md-2"><label style="font-size:11px;">模數</label><input type="text" class="form-control input-sm gear-module" value="${escapeHtml(data.Module||'')}"></div>
        <div class="col-md-2"><label style="font-size:11px;">齒數</label><input type="number" class="form-control input-sm gear-teeth" value="${data.Teeth||''}"></div>
        <div class="col-md-2"><label style="font-size:11px;">齒寬(mm)</label><input type="number" class="form-control input-sm gear-width" step="0.01" value="${data.Face_Width||''}"></div>
        <div class="col-md-2"><label style="font-size:11px;">旋向</label><select class="form-control input-sm gear-helix-dir"><option value="">-</option><option ${data.Helix_Direction=='LH'?'selected':''}>LH</option><option ${data.Helix_Direction=='RH'?'selected':''}>RH</option></select></div>
        <div class="col-md-2"><label style="font-size:11px;">螺旋角</label><input type="text" class="form-control input-sm gear-helix-angle" value="${escapeHtml(data.Helix_Angle_Str||'')}"></div>
        <div class="col-md-2"><label style="font-size:11px;">壓力角</label><input type="text" class="form-control input-sm gear-pressure" value="${escapeHtml(data.Pressure_Angle||'')}"></div></div>
        <div class="row" style="margin-top:6px;"><div class="col-md-2"><label style="font-size:11px;">齒輪類型</label><input type="text" class="form-control input-sm gear-type" value="${escapeHtml(data.Gear_Type||'')}"></div>
        <div class="col-md-2"><label style="font-size:11px;">轉位係數X</label><input type="number" class="form-control input-sm gear-shift" step="0.0001" value="${data.Profile_Shift_X||''}"></div>
        <div class="col-md-2"><label style="font-size:11px;">工件總長</label><input type="number" class="form-control input-sm gear-length" step="0.01" value="${data.Workpiece_Length||''}"></div>
        <div class="col-md-2"><label style="font-size:11px;">規格</label><input type="text" class="form-control input-sm gear-spec" value="${escapeHtml(data.Spec_No||'')}"></div>
        <div class="col-md-3"><label style="font-size:11px;">備註</label><input type="text" class="form-control input-sm gear-remark" value="${escapeHtml(data.Remark_Gear||'')}"></div>
        <div class="col-md-1" style="display:flex; align-items:flex-end;"><button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest('.gear-row').remove()"><i class="fa fa-trash"></i></button></div></div>
    </div>`;
    $('#gear_rows_container').append(html);
}

function addChildPartRow(data) {
    data = data || {};
    $('#child_parts_container').append(`<tr class="child-row">
        <td><input type="hidden" class="child-bom-id" value="${data.bom_id||''}"><input type="text" class="form-control input-sm child-part-no" value="${escapeHtml(data.child_d_id_str||'')}"></td>
        <td><input type="number" class="form-control input-sm child-qty" value="${data.standard_qty||1}" step="0.01"></td>
        <td><button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest('tr').remove()"><i class="fa fa-trash"></i></button></td>
    </tr>`);
}

// ══════════════════════════════════════════════════════════
//  圖檔 Modal
// ══════════════════════════════════════════════════════════
function openProductFiles(pid) {
    $('#drawingChoiceModalTitle').text('圖檔 - ' + pid);
    $('#drawingChoiceModalBody').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中…</p>');
    $('#drawingChoiceModal').modal('show');
    $.post('ERP_Cost_Analysis.php', {action:'get_product_files', product_id:pid}, function(res) {
        if (!res.success) { $('#drawingChoiceModalBody').html('<p class="text-muted text-center">載入失敗</p>'); return; }
        let html = '';
        const allFiles = [...(res.files||[]), ...(res.erp_files||[])];
        if (!allFiles.length) { $('#drawingChoiceModalBody').html('<p class="text-muted text-center">無圖檔</p>'); return; }
        allFiles.forEach(f => {
            html += `<div style="display:flex; align-items:center; gap:8px; padding:6px 10px; border-bottom:1px solid #eee;">
                <i class="fa fa-file-o"></i>
                <a href="${f.path}" target="_blank" style="flex:1;">${escapeHtml(f.name)}</a>
                <small class="text-muted">${new Date(f.mtime*1000).toLocaleDateString()}</small>
            </div>`;
        });
        $('#drawingChoiceModalBody').html(html);
    }, 'json');
}

// ══════════════════════════════════════════════════════════
//  BOM Mapping Modal（保留原有完整邏輯）
// ══════════════════════════════════════════════════════════
function handleSettingClick(event, orderId, productId, orderNo) {
    event.stopPropagation();
    openBomMappingModal(orderId, productId, orderNo);
}

function openBomMappingModal(orderId, productId, orderNo) {
    $('#mapping_order_id').val(orderId);
    $('#mapping_product_id').val(productId);
    $('#mapping_order_no').html(`<span style="color:#212529; font-size:1.2em; font-weight:600;">${escapeHtml(orderNo)}</span>`);

    let spec='', qty=0, priceVal=0, shippedQty=0, mappedShipQty=0;
    if (currentAnalysisData && currentAnalysisData.orders) {
        const order = currentAnalysisData.orders.find(o => o.Order_id == orderId);
        if (order) {
            spec = order.spec || '';
            qty  = parseInt(order.order_qty) || 0;
            priceVal = parseFloat(order.selling_price || order.effective_sell_price || 0);
            shippedQty   = parseFloat(order.shipped_qty || 0);
            mappedShipQty = parseFloat(order.mapped_shipped_qty || 0);
        }
    }
    // 未出貨數量 = 訂單數 - 已出貨數（取 shipment_order_map 精確數或 is_list 數，取較大）
    const actualShipped = Math.max(shippedQty, mappedShipQty);
    const unboundQty = Math.max(0, qty - actualShipped);
    const priceDisplay = priceVal > 0 ? 'NT$' + priceVal.toLocaleString() : '<span class="text-muted">未設定</span>';
    $('#mapping_order_qty').val(qty);
    let infoHtml = `<strong>料號：</strong><span style="font-size:1.2em; color:var(--primary);">${escapeHtml(productId)}</span>`
        + ` &nbsp;&nbsp; <strong>規格：</strong>${escapeHtml(spec)}`
        + ` &nbsp;&nbsp; <strong>訂單數：</strong>${qty}`
        + ` &nbsp;&nbsp; <strong>售價：</strong>${priceDisplay}`
        + ` &nbsp;&nbsp; <strong style="color:${unboundQty<=0?'#27ae60':'#c0392b'}; font-size:1.1em;">未出貨：${unboundQty} pcs</strong>`
        + ` <span id="shipment_mapped_total_display" style="margin-left:12px; font-size:12px; color:#2980b9;"></span>`;
    $('#mapping_order_info').html(infoHtml);

    $('#bomSelectionList').html('<p class="text-center">載入中…</p>');
    $('#bomCandidateTable tbody').html('<tr><td colspan="7" class="text-center">請先選擇左側 BOM</td></tr>');
    $('.nav-tabs a[href="#tab_bom_map"]').tab('show');
    $('#bomMappingModal').modal('show');

    $.post('ERP_Cost_Analysis.php', {action:'get_bom_candidates', product_id:productId}, function(resCand) {
        if (resCand.success) {
            allBomCandidates = resCand.data;
            $.post('ERP_Cost_Analysis.php', {action:'get_order_bom_mapping', order_id:orderId}, function(resMap) {
                if (resMap.success) {
                    currentMappedFids = resMap.mapped_fids.map(String);
                    currentMappedDetails = resMap.mapped_details || {};
                    renderBomSelection();
                    loadShipmentMapping(orderId, productId);
                } else { alert('載入設定失敗: ' + resMap.message); }
            }, 'json');
        } else { alert('載入 BOM 失敗: ' + resCand.message); }
    }, 'json');
}

function renderBomSelection() {
    const uniqueBoms = [...new Set(allBomCandidates.map(item => item.bom))];
    const orderQty = parseInt($('#mapping_order_qty').val()) || 0;
    const orderId = parseInt($('#mapping_order_id').val());
    let html = '';
    if (!uniqueBoms.length) { html = '<p class="text-muted text-center" style="margin-top:15px;">無 BOM 資料</p>'; }
    else {
        uniqueBoms.forEach(bom => {
            const candidate = allBomCandidates.find(c => c.bom === bom);
            const bomQty = parseInt(candidate ? candidate.bom_qty : 0);
            const bomRemaining = parseInt(candidate ? candidate.bom_remaining : 0);
            const boundOrders = candidate ? (candidate.bound_orders || []) : [];
            const isBoundToThisOrder = currentMappedFids.includes(bom);
            const canSelect = !isBoundToThisOrder && bomRemaining > 0;
            let statusHtml = '';
            if (isBoundToThisOrder) {
                const allocQty = currentMappedDetails[bom] || 0;
                const safeIdInner = bom.replace(/[^a-z0-9]/gi,'_');
                statusHtml = `<span class="label label-success" style="font-size:11px;">已綁定 ${allocQty}pcs</span>
                    <div style="margin-top:4px; display:flex; gap:4px; align-items:center;">
                        <input type="number" id="edit_alloc_${safeIdInner}" value="${allocQty}" min="1" style="width:70px;" class="form-control input-sm">
                        <button type="button" class="btn btn-xs btn-primary btn-update-bom-alloc" data-bom="${bom}" data-order-id="${orderId}" title="儲存修改數量"><i class="fa fa-save"></i></button>
                        <button type="button" class="btn btn-xs btn-danger btn-remove-bom-alloc" data-bom="${bom}" data-order-id="${orderId}" title="取消此 BOM 與訂單的綁定"><i class="fa fa-chain-broken"></i> 取消</button>
                    </div>`;
            } else {
                statusHtml = `<span class="text-${bomRemaining>0?'success':'danger'}" style="font-size:11px;">剩餘可綁: ${bomRemaining}pcs</span>`;
                boundOrders.forEach(bo => { if (bo.order_id != orderId) statusHtml += ` <small class="text-muted">已綁定 ${bo.order_oo||('訂單#'+bo.order_id)} x${bo.allocated_qty}</small>`; });
            }
            const safeId = bom.replace(/[^a-z0-9]/gi,'_');
            html += `<div style="display:flex; align-items:flex-start; margin-bottom:6px; padding:4px 0; border-bottom:1px dashed #eee;">
                <div style="width:24px; flex-shrink:0; display:flex; justify-content:center; padding-top:3px;">
                    <input type="checkbox" class="bom-select-cb" name="selected_boms[]" value="${bom}" ${isBoundToThisOrder?'checked':''} ${!canSelect&&!isBoundToThisOrder?'disabled':''} ${isBoundToThisOrder?'disabled':''} style="margin:0; cursor:${canSelect?'pointer':'not-allowed'};">
                </div>
                <div style="flex:1; word-break:break-word; line-height:1.6; color:#3D405B; padding-left:4px;">
                    <div>${bom} <span class="text-muted" style="font-size:0.9em;">x${bomQty}pcs</span></div>
                    <div>${statusHtml}</div>
                    ${isBoundToThisOrder?'':
                    `<div id="qty_row_${safeId}" style="display:none; margin-top:4px;">
                        <input type="number" class="form-control input-sm bom-alloc-qty" data-bom="${bom}" data-bom-remaining="${bomRemaining}" min="1" max="${bomRemaining}" style="width:90px; display:inline-block;" placeholder="綁定量">
                        <small class="text-muted"> / ${bomRemaining}pcs 可綁</small>
                    </div>`}
                </div>
            </div>`;
        });
    }
    $('#bomSelectionList').html(html);
    $(document).off('change','input[name="selected_boms[]"]').on('change','input[name="selected_boms[]"]', function() {
        const bom = $(this).val();
        const safeId = bom.replace(/[^a-z0-9]/gi,'_');
        const $qtyRow = $('#qty_row_' + safeId);
        if ($(this).is(':checked')) {
            const candidate = allBomCandidates.find(c => c.bom === bom);
            const bomRemaining = parseInt(candidate ? candidate.bom_remaining : 0);
            let alreadyAllocated = 0;
            $('.bom-alloc-qty').each(function(){ if ($(this).data('bom') !== bom) alreadyAllocated += parseInt($(this).val())||0; });
            const suggested = Math.min(bomRemaining, Math.max(0, orderQty - alreadyAllocated));
            $qtyRow.find('.bom-alloc-qty').val(suggested);
            $qtyRow.show();
        } else { $qtyRow.hide().find('.bom-alloc-qty').val(''); }
        updateBomCandidateTable();
    });
    updateBomCandidateTable();
}

function updateBomCandidateTable() {
    const selectedBoms = $('input[name="selected_boms[]"]:checked').map(function(){ return this.value; }).get();
    const allDisplayBoms = [...new Set([...selectedBoms, ...currentMappedFids])];
    if (!allDisplayBoms.length) {
        $('#bomCandidateTable tbody').html('<tr><td colspan="7" class="text-center text-muted" style="padding:20px;">請先於左側選擇 BOM</td></tr>');
        return;
    }
    const filtered = allBomCandidates.filter(item => allDisplayBoms.includes(item.bom));
    let html = '';
    filtered.forEach(c => {
        const isBound = currentMappedFids.includes(c.bom);
        let boundInfo = '';
        if (c.bound_orders && c.bound_orders.length > 0) {
            boundInfo = c.bound_orders.map(bo => `<small class="text-info">已綁定 ${bo.order_oo||('訂單#'+bo.order_id)} x${bo.allocated_qty}</small>`).join('<br>');
        }

        // ── 外包單價欄：優先外包均價；廠內製程顯示KPI計算值並加備註 ──
        let priceDisplay = '-';
        const avgP = parseFloat(c.avg_price);
        const inhouseP = c.inhouse_calc_price !== null && c.inhouse_calc_price !== undefined
            ? parseFloat(c.inhouse_calc_price) : null;

        if (avgP > 0) {
            priceDisplay = avgP.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2});
        } else if (inhouseP !== null && inhouseP > 0) {
            priceDisplay = `<span style="color:#1a6fa0; font-weight:600;">${inhouseP.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2})}</span>`
                + `<br><small style="color:#888; font-size:10px;">※廠內加工(計算值)</small>`;
        }

        html += `<tr style="${isBound?'background:#f0fff0;':''}">
            <td style="white-space:nowrap;">${c.bom}</td><td style="white-space:nowrap;">${c.bom_sn}</td>
            <td style="white-space:nowrap;">${c.process_no} ${c.ProcessName||''}</td>
            <td>${c.maker_id||''}</td>
            <td style="text-align:right;">${c.sqty}</td>
            <td style="text-align:right; line-height:1.6;">${priceDisplay}</td>
            <td>${boundInfo||'-'}</td>
        </tr>`;
    });
    const thead = `<tr><th>BOM</th><th>SN</th><th>製程</th><th>廠商</th><th style="text-align:right;">數量</th><th style="text-align:right;">外包單價</th><th>已對應訂單</th></tr>`;
    $('#bomCandidateTable thead').html(thead);
    $('#bomCandidateTable tbody').html(html);
}

// 更新已綁定 BOM 數量
$(document).on('click', '.btn-update-bom-alloc', function() {
    const bom = $(this).data('bom');
    const orderId = $(this).data('order-id');
    const safeId = bom.replace(/[^a-z0-9]/gi,'_');
    const qty = parseInt($('#edit_alloc_' + safeId).val() || '0');
    if (qty <= 0) { alert('請輸入有效數量'); return; }
    $.post('', { action: 'save_bom_order_mapping', bom_qty_map: JSON.stringify({[bom]: qty}), order_id: orderId }, function(res) {
        if (res.success) {
            const pid = $('#mapping_product_id').val();
            const orderNo = $('#mapping_order_no').text() || '';
            openBomMappingModal(orderId, pid, orderNo);
        } else { alert('更新失敗: ' + (res.message || '')); }
    }, 'json');
});

// 取消已綁定 BOM
$(document).on('click', '.btn-remove-bom-alloc', function() {
    const bom = $(this).data('bom');
    const orderId = $(this).data('order-id');
    if (!confirm('確定取消 BOM ' + bom + ' 與此訂單的綁定？')) return;
    $.post('', { action: 'delete_bom_order_mapping', bom: bom, order_id: orderId }, function(res) {
        if (res.success) {
            const pid = $('#mapping_product_id').val();
            const orderNo = $('#mapping_order_no').text() || '';
            openBomMappingModal(orderId, pid, orderNo);
        } else { alert('取消失敗: ' + (res.message || '')); }
    }, 'json');
});

function saveAllMappings() {
    const orderId = $('#mapping_order_id').val();
    const productId = $('#mapping_product_id').val();
    const bomQtyMap = {};
    $('input[name="selected_boms[]"]:checked:not(:disabled)').each(function() {
        const bom = $(this).val();
        if (currentMappedFids.includes(bom)) return;
        const safeId = bom.replace(/[^a-z0-9]/gi,'_');
        const qty = parseInt($('#qty_row_'+safeId+' .bom-alloc-qty').val()) || 0;
        if (qty > 0) bomQtyMap[bom] = qty;
    });
    const doSave = (cb) => {
        if (!Object.keys(bomQtyMap).length) { cb && cb(); return; }
        $.post('ERP_Cost_Analysis.php', {action:'save_order_bom_mapping', order_id:orderId, bom_qty_map:bomQtyMap}, function(res) {
            if (!res.success) { alert('儲存失敗: '+res.message); return; }
            cb && cb();
        }, 'json');
    };
    doSave(() => {
        saveShipmentMapping(orderId, () => {
            $('#bomMappingModal').modal('hide');
            showToast('設定已儲存');
            openProductAnalysis(productId);
        });
    });
}

function loadShipmentMapping(orderId, productId) {
    $.post('ERP_Cost_Analysis.php', {action:'get_shipment_candidates', product_id:productId}, function(resCand) {
        if (resCand.success) {
            lastShipmentCandidates = resCand.data; // 儲存候選清單
            $.post('ERP_Cost_Analysis.php', {action:'get_order_shipment_mapping', order_id:orderId}, function(resMap) {
                if (resMap.success) { currentShipmentMappings = resMap.data; renderShipmentTables(lastShipmentCandidates, currentShipmentMappings); }
            }, 'json');
        }
    }, 'json');
}

function renderShipmentTables(candidates, mapped) {
    const $cand = $('#shipmentCandidateTable tbody'), $map = $('#shipmentMappedTable tbody');
    $cand.empty(); $map.empty();
    candidates.forEach(c => {
        const remaining = parseFloat(c.Qty) - parseFloat(c.mapped_qty);
        let btnHtml = remaining <= 0
            ? `<span class="text-muted" style="font-size:0.9em;">已被 ${c.mapped_orders} 綁定</span>`
            : `<button class="btn btn-xs btn-success btn-add-shipment" data-json='${JSON.stringify(c).replace(/'/g,"&#39;")}'><i class="fa fa-plus"></i></button>`;
        $cand.append(`<tr><td>${c.IS_number}</td><td>${c.Specification||''}</td><td>${c.Unit_price||''}</td><td>${c.Order_date}</td><td>${c.Qty}</td><td>${btnHtml}</td></tr>`);
    });
    mapped.forEach((m, i) => {
        $map.append(`<tr data-is-id="${m.IS_id}"><td>${m.Order_date}</td><td>${m.IS_number}</td><td>${m.Specification||''}</td><td>${m.Qty||'-'}</td><td class="text-right">${m.Unit_price > 0 ? 'NT$'+Number(m.Unit_price).toLocaleString() : '-'}</td><td><input type="number" class="form-control input-sm map-qty" value="${m.shipped_qty}" style="width:80px;"></td><td><button class="btn btn-xs btn-danger btn-remove-shipment" data-index="${i}"><i class="fa fa-trash"></i></button></td></tr>`);
    });
    updateShipmentTotal();
}

function updateShipmentTotal() {
    let total = 0;
    $('#shipmentMappedTable tbody .map-qty').each(function() {
        total += parseInt($(this).val() || 0);
    });
    const orderQty = parseInt($('#mapping_order_qty').val() || 0);
    const remaining = orderQty > 0 ? orderQty - total : null;
    const remainText = remaining !== null ? `（訂單剩餘：${remaining} pcs）` : '';
    $('#shipment_mapped_total_display').html(
        `<strong>已對應出貨合計：<span style="color:#e74c3c; font-size:1.1em;">${total} pcs</span></strong> ${remainText}`
    );
}

// map-qty 輸入時即時更新合計
$(document).on('input', '.map-qty', function() { updateShipmentTotal(); });

function addShipmentMapping(shipment) {
    const totalQty = parseFloat(shipment.Qty), mappedQty = parseFloat(shipment.mapped_qty);
    const remaining = Math.max(0, totalQty - mappedQty);
    if (mappedQty > 0 && !confirm(`此出貨單已有 ${mappedQty}pcs 綁定。\n建議綁定剩餘: ${remaining}pcs`)) return;
    currentShipmentMappings.push({IS_id:shipment.IS_id, IS_number:shipment.IS_number, Order_date:shipment.Order_date, Qty:shipment.Qty, shipped_qty:remaining, Specification:shipment.Specification, Unit_price:shipment.Unit_price||0});
    renderShipmentTables(lastShipmentCandidates, currentShipmentMappings); // 僅更新本地渲染，不重抓伺服器資料
}

function removeShipmentMapping(i) {
    currentShipmentMappings.splice(i, 1);
    renderShipmentTables(lastShipmentCandidates, currentShipmentMappings); // 僅更新本地渲染
}

function saveShipmentMapping(orderId, callback) {
    const mappings = [];
    $('#shipmentMappedTable tbody tr').each(function() { mappings.push({IS_id:$(this).data('is-id'), shipped_qty:$(this).find('.map-qty').val()}); });
    $.post('ERP_Cost_Analysis.php', {action:'save_order_shipment_mapping', order_id:orderId, mappings}, function(res) {
        if (res.success) { callback && callback(); }
        else alert('出貨對應儲存失敗: ' + res.message);
    }, 'json');
}

function refreshProductData(productId) {
    openProductAnalysis(productId);
}

window.deleteProcess = function(fid) {
    if (!confirm('確定要刪除此製程？(bom_ing_fid: ' + fid + ')')) return;
    $.post('ERP_Cost_Analysis.php', {action:'delete_bom_process', bom_ing_fid:fid}, function(res) {
        if (res.success) { showToast('製程已刪除'); allBomCandidates = allBomCandidates.filter(item => item.bom_ing_fid != fid); updateBomCandidateTable(); }
        else alert(res.message);
    }, 'json');
};
</script>
<!-- 出貨單與 BOM 之間的綁定並非直接紀錄在單一個資料表中，而是透過**「訂單 (Order ID)」**作為中間橋樑來達成關聯。

具體紀錄這些關聯的資料表如下：

bom_order_process_map：

用途：紀錄 BOM 編號與訂單 (order_id) 的對應關係。
關鍵欄位：bom (BOM 編號)、order_id (對應 order_track 或 order_list 的 ID)、allocated_qty (分配數量)。
shipment_order_map：

用途：紀錄 出貨單 (is_list.IS_id) 與訂單 (Order_id) 的對應關係。
關鍵欄位：IS_id (出貨單自動編號)、Order_id (訂單 ID)、shipped_qty (本次出貨對應數)。
總結
當系統需要分析某張出貨單對應到哪個 BOM 時，邏輯如下：

從 shipment_order_map 找到該出貨單所屬的 Order_id。
再透過該 Order_id 到 bom_order_process_map 找到與其綁定的 bom。
此外，在 is_list 資料表中也存在一個 Order_id 欄位，這可能是早期系統直接紀錄對應關係的地方，而 shipment_order_map 則是用於處理更複雜的多對多或精確數量拆分的情況。 -->
</body>
</html>
<!-- BOM 成本與利潤分析的計算邏輯非常嚴謹，主要採用「製程單價加總法」來推算單顆成本。以下是詳細的邏輯與資料來源對照：

1. 核心計算公式
A. BOM 單位成本 (Unit Cost)
這是分析最關鍵的數據，代表生產「一顆」產品所需的外包加工總費用。

計算邏輯：針對特定 BOM，將其包含的每一個製程序號 (bom_sn) 分別計算平均單價後再相加。
公式：BOM 單位成本 = Σ (各製程序號的平均單價)
資料來源：
bom_ing_transfer_log 表的 price (單價) 與 paid_qty (計價數量)。
bom_ing 表的 bom_sn (製程序號) 與 process_no (製程編號)。
備援邏輯：若該 BOM 缺乏製程明細，則降級使用：總金額 (Σ price * paid_qty) / 總計價數量 (Σ paid_qty)。
B. 平均售價 (Average Selling Price)
計算邏輯：彙整該料號在查詢期間內所有有效訂單的價格。
取值優先權：
優先取 order_track.unit_price (訂單設定單價)。
若訂單單價為 0 或 NULL，則取 is_list.Unit_price (出貨單實際單價) 的最小值。
資料來源：
order_track 表的 unit_price。
is_list 表的 Unit_price。
C. 利潤率 (Gross Margin %)
公式：((平均售價 - 單位成本) / 平均售價) * 100
2. 詳細資料來源對照表
分析項目	計算方式 / 邏輯	涉及資料表	關鍵欄位
總加工成本	期間內所有移轉單金額加總	bom_ing_transfer_log	price * paid_qty
製程平均單價	單一 BOM 內特定製程的平均價格	bom_ing_transfer_log	price (需 paid_qty > 0)
訂單總額	訂單數量乘以售價	order_track	Qty * unit_price
出貨單價	實際出貨給客戶的價格	is_list	Unit_price
綁定數量	BOM 分配給該訂單的數量	bom_order_process_map	allocated_qty
料號規格	顯示產品詳細參數	d_setting_gear	Module, Teeth, Face_Width
3. 特殊過濾與修正邏輯
1. 排除廠內成本 (In-house Vendors)
邏輯：若製程的加工廠商被標記為「廠內」，則該筆支出不計入外包成本。
設定來源：system_parameters 表中 param_group = 'ERP_COST_ANALYSIS' 且 param_key = 'inhouse_vendors' 的 JSON 清單。
對比欄位：bom_ing_transfer_log.maker_from。
2. 排除特定製程 (Excluded Processes)
邏輯：使用者可在「成本分類設定」中排除特定製程（例如：材料費、運費）。
設定來源：system_parameters 表中 param_key = 'calculation_config' 的 excluded_processes 陣列。
對比欄位：bom_ing.process_no。
3. 價格不符偵測 (Price Mismatch)
邏輯：比較訂單預設售價與實際出貨單價。
異常判定：order_track.unit_price != is_list.Unit_price。
4. 數量不符與成本調整 (Qty Mismatch)
邏輯：當「BOM 生產數量」與「訂單出貨數量」不一致時。
處理：系統會標示「調整後單顆成本」，計算方式為：(BOM 生產總成本) / (實際出貨數量)。這能更準確反映因報廢或溢產造成的實際利潤衝擊。
4. 總結流程
定義對象：從 is_list 找出期間內有出貨的 Product_id。
抓成本：到 bom_ing_transfer_log 找對應 BOM 的各製程單價。
抓收入：到 order_track 和 is_list 找銷售單價。
校正：套用「廠內廠商」與「排除製程」設定。
輸出：產出毛利與利潤率，並針對「無BOM綁定」或「售價為0」的項目發出警示。 -->