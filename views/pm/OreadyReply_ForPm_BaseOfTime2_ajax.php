<?php
// OreadyReply_ForPm_BaseOfTime2_ajax.php
// 由 OreadyReply_ForPm_BaseOfTime2.php include，請勿直接開啟。
// session_start() 已由主檔案執行。

// AJAX BOM UPDATE HANDLER
if (isset($_POST['action']) && $_POST['action'] === 'update_bom_info') {

    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            try {
                $connInstance = new DBConnection();
                $db = $connInstance->getPDO();
            } catch (Exception $e) {
                $response['message'] = '資料庫連接實例化失敗: ' . $e->getMessage();
                echo json_encode($response);
                exit;
            }
        }
        if (!isset($db)) {
            $response['message'] = '資料庫連接失敗，無法處理請求。';
            echo json_encode($response);
            exit;
        }
    }

    // 修正：允許多筆訂單綁定，因此 order_id 非必填，改為檢查 order_pcs_json
    if (!isset($_POST['bom']) || !isset($_POST['client_name']) || !isset($_POST['order_pcs_json']) || !array_key_exists('bom_ps', $_POST)) {
        $response['message'] = '錯誤：缺少 BOM、客戶名稱、訂單 ID 或 BOM 備註參數。';
        echo json_encode($response);
        exit;
    }

    $bom_to_update        = trim($_POST['bom']);
    $client_name_to_update = trim($_POST['client_name']);
    $bom_ps_to_update     = $_POST['bom_ps'];
    $modified_by          = $_SESSION['id'] ?? 'system_ajax_update';
    session_write_close();

    if (empty($bom_to_update)) {
        $response['message'] = '錯誤：BOM 不可為空。';
        echo json_encode($response);
        exit;
    }
    
    // 處理多筆訂單綁定
    $order_pcs_json = $_POST['order_pcs_json'] ?? '[]';
    $orders = json_decode($order_pcs_json, true);
    
    // 決定 bom 主檔的 o_order_id (僅供參考或舊系統相容，取第一筆或 'B')
    $primary_order_id = 'B'; // 預設備庫
    if (!empty($orders) && is_array($orders) && count($orders) > 0) {
        $primary_order_id = $orders[0]['order_id'];
    }

    try {
        $db->beginTransaction();

        // 1. 更新 bom 主檔 (Client_Name, bom_ps, o_order_id 備用)
        $stmt = $db->prepare("UPDATE bom 
                SET Client_Name = :client_name, 
                    bom_ps = :bom_ps,
                    o_order_id = :o_order_id, 
                    Modified_At = NOW(),
                    Modified_By = :modified_by,
                    d_setting_id = :d_setting_id
                WHERE bom = :bom");
        $stmt->bindParam(':client_name', $client_name_to_update, PDO::PARAM_STR);
        $stmt->bindParam(':bom_ps', $bom_ps_to_update, PDO::PARAM_STR);
        $stmt->bindParam(':o_order_id', $primary_order_id, PDO::PARAM_STR);
        $stmt->bindParam(':modified_by', $modified_by, PDO::PARAM_STR);
        // 如果有傳 d_id (實際上是 d_setting_id)，也順便更新，確保關聯正確
        $d_setting_id = !empty($_POST['d_setting_id']) ? $_POST['d_setting_id'] : null;
        $stmt->bindParam(':d_setting_id', $d_setting_id, $d_setting_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':bom', $bom_to_update, PDO::PARAM_STR);
        $stmt->execute();

        // 2. 同步更新 bom_order_process_map
        // 先刪除此 BOM 的所有舊對應
        $db->prepare("DELETE FROM bom_order_process_map WHERE bom = ?")->execute([$bom_to_update]);
        
        if ($primary_order_id !== 'B' && !empty($orders)) {
            $ins = $db->prepare("INSERT INTO bom_order_process_map (bom, order_id, allocated_qty) VALUES (?, ?, ?)");
            foreach ($orders as $o) {
                $pcs = !empty($o['pcs']) ? $o['pcs'] : null;
                $ins->execute([$bom_to_update, $o['order_id'], $pcs]);
            }
        }

        $db->commit();

        // 確認 bom 是否存在
        $checkExist = $db->prepare("SELECT COUNT(*) FROM bom WHERE bom = ?");
        $checkExist->execute([$bom_to_update]);
        if ($checkExist->fetchColumn() == 0) {
            $response['message'] = '更新失敗：找不到指定的 BOM。';
        } else {
            $response['success'] = true;
            $response['message'] = 'BOM 資料更新成功。';
        }

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $response['message'] = '資料庫操作錯誤：' . $e->getMessage();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $response['message'] = '系統錯誤：' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// AJAX COMPLETED BOM SEARCH HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'search_completed_bom') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'data' => []];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            try {
                $connInstance = new DBConnection();
                $db = $connInstance->getPDO();
            } catch (Exception $e) {
                $response['message'] = '資料庫連接實例化失敗: ' . $e->getMessage();
                echo json_encode($response);
                exit;
            }
        }
        if (!isset($db)) {
            $response['message'] = '資料庫連接失敗，無法處理請求。';
            echo json_encode($response);
            exit;
        }
    }

    if (!isset($_POST['searchTerm']) || trim($_POST['searchTerm']) === '') {
        $response['message'] = '錯誤：缺少搜索條件。';
        echo json_encode($response);
        exit;
    }

    $searchTerm = trim($_POST['searchTerm']);

    try {
        $searchTermWildcard = '%' . $searchTerm . '%';

        // 1. 撈符合條件的已完工 BOM（processing_state='1'）
        // client_name_display：優先用 customer_list.customer（若有綁定 d_setting_id），否則用 bom.Client_Name
        $sql = "SELECT b.bom, b.d_id, b.Client_Name, b.sqty AS Qty, b.priority_type,
                       COALESCE(cl.customer, b.Client_Name) AS client_name_display,
                       b.d_setting_id, b.closed_by,
                       DATE_FORMAT(b.closed_at,'%Y-%m-%d %H:%i') AS closed_at,
                       u_close.user_cname AS closed_by_name
                FROM bom b
                LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
                LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                LEFT JOIN user u_close ON u_close.id = b.closed_by
                WHERE b.processing_state = '1'
                  AND (b.bom LIKE :searchTerm OR b.d_id LIKE :searchTerm OR b.Client_Name LIKE :searchTerm)
                ORDER BY b.bom ASC
                LIMIT 50";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':searchTerm', $searchTermWildcard, PDO::PARAM_STR);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($results)) {
            $bom_list = array_column($results, 'bom');
            $ph = implode(',', array_fill(0, count($bom_list), '?'));

            // 2. 批量撈 bom_ing（每個 bom+bom_sn 取最新一筆，避免重複）
            $sp = $db->prepare("
                SELECT bi.bom, bi.bom_sn, bi.process_no, pn.ProcessName,
                       DATE_FORMAT(bi.outsource_date,'%Y/%m/%d') AS outsource_date,
                       DATE_FORMAT(bi.return_date,'%Y/%m/%d') AS return_date,
                       ml.maker_id
                FROM bom_ing bi
                INNER JOIN (
                    SELECT bom, bom_sn, MAX(bom_ing_fid) AS max_fid
                    FROM bom_ing
                    WHERE bom IN ($ph)
                    GROUP BY bom, bom_sn
                ) latest ON bi.bom_ing_fid = latest.max_fid
                LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no
                ORDER BY bi.bom, CAST(bi.bom_sn AS UNSIGNED)
            ");
            $sp->execute($bom_list);
            $proc_map = []; $max_count = 0;
            foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $proc_map[$p['bom']][] = $p;
            }
            foreach ($proc_map as $b => $ps) {
                if (count($ps) > $max_count) $max_count = count($ps);
            }
            foreach ($results as &$row) {
                $row['processes'] = $proc_map[$row['bom']] ?? [];
            }
            unset($row);

            // 3. 批量撈 bom_ing_transfer_log 最新單價（每 bom+bom_sn 取最新一筆）
            $price_map = []; // [bom][bom_sn] = {price, modified_unit_price}
            $sp2 = $db->prepare("
                SELECT tl.bom, tl.bom_sn, tl.price, tl.modified_unit_price
                FROM bom_ing_transfer_log tl
                INNER JOIN (
                    SELECT bom, bom_sn, MAX(transfer_id) AS max_id
                    FROM bom_ing_transfer_log
                    WHERE bom IN ($ph)
                    GROUP BY bom, bom_sn
                ) latest ON tl.bom = latest.bom AND tl.bom_sn = latest.bom_sn AND tl.transfer_id = latest.max_id
                WHERE tl.bom IN ($ph)
            ");
            $sp2->execute(array_merge($bom_list, $bom_list));
            foreach ($sp2->fetchAll(PDO::FETCH_ASSOC) as $tl) {
                $price_map[$tl['bom']][$tl['bom_sn']] = [
                    'price'               => $tl['price'],
                    'modified_unit_price' => $tl['modified_unit_price'],
                ];
            }

            $response['success']           = true;
            $response['data']              = $results;
            $response['max_process_count'] = $max_count;
            $response['price_map']         = $price_map;
        } else {
            $response['success']           = true;
            $response['data']              = [];
            $response['max_process_count'] = 0;
            $response['price_map']         = [];
            $response['message']           = '查無已完工資料。';
        }
    } catch (PDOException $e) {
        $response['message'] = '資料庫操作錯誤：' . $e->getMessage();
    } catch (Exception $e) {
        $response['message'] = '系統錯誤：' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// AJAX VENDOR/MAKER SEARCH HANDLER (MODIFIED)
else if (isset($_POST['action']) && $_POST['action'] === 'search_maker') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'data' => []];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            try {
                $connInstance = new DBConnection();
                $db = $connInstance->getPDO();
            } catch (Exception $e) {
                $response['message'] = '資料庫連接實例化失敗: ' . $e->getMessage();
                echo json_encode($response);
                exit;
            }
        }
        if (!isset($db)) {
            $response['message'] = '資料庫連接失敗，無法處理請求。';
            echo json_encode($response);
            exit;
        }
    }

    if (!isset($_POST['term']) || !isset($_POST['search_type'])) {
        $response['message'] = '錯誤：缺少搜索條件或類型。';
        echo json_encode($response);
        exit;
    }

    $term = trim($_POST['term']);
    $search_type = $_POST['search_type']; // 'no' or 'name'

    if ($term === '') {
        $response['success'] = true; // Return success with empty data array
        echo json_encode($response);
        exit;
    }

    try {
        // 同時搜尋廠商編號與廠商中文，編號優先排序
        $sql = "SELECT `maker_id_no`,`maker_id`,`m_category`,`m_process_items`,
                       `m_tel`,`m_tel2`,`m_fax`,`factory_address`,`contact_person`,`contact_title`
                FROM `maker_list`
                WHERE (maker_id_no LIKE :term OR maker_id LIKE :term2)
                  AND (status IS NULL OR status != 'X')
                ORDER BY
                    CASE WHEN maker_id_no LIKE :term3 THEN 0 ELSE 1 END,
                    maker_id_no ASC
                LIMIT 20";

        $stmt = $db->prepare($sql);
        $searchTermWildcard = '%' . $term . '%';
        $stmt->bindParam(':term',  $searchTermWildcard, PDO::PARAM_STR);
        $stmt->bindParam(':term2', $searchTermWildcard, PDO::PARAM_STR);
        $stmt->bindParam(':term3', $searchTermWildcard, PDO::PARAM_STR);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Always return the list of results
        $response['success'] = true;
        $response['data'] = $results;
        if (empty($results)) {
            $response['message'] = '查無資料。';
        }
    } catch (PDOException $e) {
        $response['message'] = '資料庫操作錯誤：' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// AJAX TRANSFER PROCESS HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'transfer_process') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            try {
                $connInstance = new DBConnection();
                $db = $connInstance->getPDO();
            } catch (Exception $e) {
                $response['message'] = '資料庫連接實例化失敗: ' . $e->getMessage();
                echo json_encode($response);
                exit;
            }
        }
        if (!isset($db)) {
            $response['message'] = '資料庫連接失敗，無法處理請求。';
            echo json_encode($response);
            exit;
        }
    }

    // Validate input
    if (empty($_POST['bom_ing_fid']) || empty($_POST['transfer_date']) || empty($_POST['maker_no']) || empty($_POST['maker_name'])) {
        $response['message'] = '錯誤：移轉日期與廠商資訊不可為空。';
        echo json_encode($response);
        exit;
    }

    $bom_ing_fid = $_POST['bom_ing_fid'];
    $transfer_datetime = $_POST['transfer_date'] . ' 00:00:00';
    $maker_no = trim($_POST['maker_no']);
    $maker_name = trim($_POST['maker_name']);
    $user_id = $_SESSION['id'] ?? 'system_transfer';
    session_write_close();

    try {
        $sql = "UPDATE bom_ing
                SET processing_state = IF(
                        qc_completed = 1
                        OR EXISTS(SELECT 1 FROM qc_check WHERE bom_ing_fid_ref = bom_ing.bom_ing_fid LIMIT 1),
                        processing_state,
                        'ing'
                    ),
                    outsource_date = :transfer_date, maker_id_no = :maker_no, maker_id = :maker_name, Modified_At = NOW(), Modified_By = :user_id
                WHERE bom_ing_fid = :bom_ing_fid";
        $stmt = $db->prepare($sql);
        $stmt->execute([':transfer_date' => $transfer_datetime, ':maker_no' => $maker_no, ':maker_name' => $maker_name, ':user_id' => $user_id, ':bom_ing_fid' => $bom_ing_fid]);
        $response['success'] = true;
        $response['message'] = '製程已成功移轉。';
    } catch (PDOException $e) {
        $response['message'] = '資料庫操作錯誤：' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX UPDATE BOM DELIVERY DATE HANDLER (MANUAL SETTING)
else if (isset($_POST['action']) && $_POST['action'] === 'update_bom_delivery_date') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            try {
                $connInstance = new DBConnection();
                $db = $connInstance->getPDO();
            } catch (Exception $e) {
                $response['message'] = 'DB Error: ' . $e->getMessage();
                echo json_encode($response);
                exit;
            }
        }
    }

    $bom = $_POST['bom'] ?? '';
    $delivery_date = $_POST['delivery_date'] ?? null; // YYYY-MM-DD or empty

    try {
        $sql = "UPDATE bom SET Delivery_date = :delivery_date, Modified_At = NOW(), Modified_By = :modified_by WHERE bom = :bom";
        $stmt = $db->prepare($sql);
        $modified_by = $_SESSION['id'] ?? 'system_ajax';
        session_write_close();
        $val = empty($delivery_date) ? null : $delivery_date;
        $stmt->execute([':delivery_date' => $val, ':modified_by' => $modified_by, ':bom' => $bom]);
        $response['success'] = true;
        $response['message'] = '交期更新成功';
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX UPDATE SYSTEM PARAMETERS HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'update_system_params') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            try {
                $connInstance = new DBConnection();
                $db = $connInstance->getPDO();
            } catch (Exception $e) {
                $response['message'] = 'DB Error: ' . $e->getMessage();
                echo json_encode($response);
                exit;
            }
        }
    }

    $yellow = $_POST['yellow'] ?? '';
    $red = $_POST['red'] ?? '';
    $red_days_before = $_POST['red_days_before'] ?? '';
    $process = $_POST['process'] ?? '';
    $show_workday = $_POST['show_workday'] ?? 'false'; // 'true' or 'false' string
    $buffer_mode  = $_POST['buffer_mode']  ?? 'false';

    try {
        // Update light-control-sd
        $lightVal = json_encode(['yellow' => $yellow, 'red' => $red, 'red_days_before' => $red_days_before]);
        $sql1 = "INSERT INTO system_parameters (param_group, param_key, param_value, description) VALUES ('BOM_SETTING', 'light-control-sd', :val, '燈號設定') ON DUPLICATE KEY UPDATE param_value = :val2";
        $stmt1 = $db->prepare($sql1);
        $stmt1->execute([':val' => $lightVal, ':val2' => $lightVal]);

        // Update process_day
        $processVal = json_encode(['day' => $process]);
        $sql2 = "INSERT INTO system_parameters (param_group, param_key, param_value, description) VALUES ('BOM_SETTING', 'process_day', :val, '製程天數') ON DUPLICATE KEY UPDATE param_value = :val2";
        $stmt2 = $db->prepare($sql2);
        $stmt2->execute([':val' => $processVal, ':val2' => $processVal]);

        // Update show_workday
        $showWorkdayVal = json_encode(['show' => ($show_workday === 'true')]);
        $sql3 = "INSERT INTO system_parameters (param_group, param_key, param_value, description) VALUES ('BOM_SETTING', 'show_workday', :val, '顯示工作天數') ON DUPLICATE KEY UPDATE param_value = :val2";
        $stmt3 = $db->prepare($sql3);
        $stmt3->execute([':val' => $showWorkdayVal, ':val2' => $showWorkdayVal]);

        // Update buffer_mode (方案一)
        $bufferModeVal = json_encode(['enabled' => ($buffer_mode === 'true')]);
        $sql4 = "INSERT INTO system_parameters (param_group, param_key, param_value, description) VALUES ('BOM_SETTING', 'buffer_mode', :val, '緩衝比模式開關') ON DUPLICATE KEY UPDATE param_value = :val2";
        $stmt4 = $db->prepare($sql4);
        $stmt4->execute([':val' => $bufferModeVal, ':val2' => $bufferModeVal]);

        $response['success'] = true;
        $response['message'] = '設定已更新';
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX GET INVALID CUSTOMER DATA HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'get_invalid_customer_data') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'data' => []];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    try {
        // Assuming 'is_inactive' column exists. 0=valid, 1=invalid.
        $customers = $db->query("SELECT customer_id, customer, is_inactive FROM customer_list ORDER BY customer ASC")->fetchAll(PDO::FETCH_ASSOC);
        $response['success'] = true;
        $response['data'] = $customers;
    } catch (Exception $e) {
        $response['message'] = '資料讀取失敗：' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX UPDATE INVALID CUSTOMER STATUS HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'update_invalid_customer_status') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    $invalid_ids = $_POST['invalid_ids'] ?? [];

    try {
        $db->beginTransaction();

        // Reset all customers to valid first
        $db->exec("UPDATE customer_list SET is_inactive = 0");

        // Then, set the specified ones to invalid
        if (!empty($invalid_ids)) {
            $placeholders = implode(',', array_fill(0, count($invalid_ids), '?'));
            $stmt = $db->prepare("UPDATE customer_list SET is_inactive = 1 WHERE customer_id IN ($placeholders)");
            $stmt->execute($invalid_ids);
        }

        $db->commit();
        $response['success'] = true;
        $response['message'] = '無效客戶設定已更新';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $response['message'] = '更新失敗: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX UPDATE SALES UNIT SETTING HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'update_sales_unit_setting') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
        if (!isset($db)) {
            $response['message'] = '資料庫連接失敗。';
            echo json_encode($response);
            exit;
        }
    }
    $sales_unit_id = $_POST['sales_unit_id'] ?? '';
    $val = json_encode(['id' => $sales_unit_id]);

    try {
        $stmt = $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description) VALUES ('SALES_SETTING', 'sales_unit_id', :val, '業務單位設定') ON DUPLICATE KEY UPDATE param_value = :val2");
        $stmt->execute([':val' => $val, ':val2' => $val]);
        $response['success'] = true;
        $response['message'] = '業務單位設定已更新';
    } catch (Exception $e) {
        $response['message'] = '更新失敗: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX GET SALES SETTINGS DATA HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'get_sales_settings_data') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'data' => []];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    try {
        // Fetch Customers
        $customers = $db->query("SELECT customer_id, customer, customer_address FROM customer_list WHERE is_inactive = 0 ORDER BY customer ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch Departments
        $departments = $db->query("SELECT id, name, parent_id, level FROM department ORDER BY level ASC, sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Users with Department and Position info
        // Assuming user_department_position_map links users to departments and positions
        $sqlUsers = "SELECT u.id, u.user_cname, m.department_id, p.name as position_name, m.is_main
                     FROM user u 
                     JOIN user_department_position_map m ON u.id = m.user_id 
                     JOIN position p ON m.position_id = p.id 
                     ORDER BY u.user_cname ASC, m.is_main DESC";
        $users = $db->query($sqlUsers)->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch Current Mappings
        $mappings = $db->query("SELECT customer_id, user_id, role FROM customer_sales WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch Current Sales Unit Setting
        $salesUnitSetting = null;
        $stmtSetting = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'SALES_SETTING' AND param_key = 'sales_unit_id'");
        $stmtSetting->execute();
        $settingRow = $stmtSetting->fetch(PDO::FETCH_ASSOC);
        if ($settingRow) {
            $salesUnitSetting = json_decode($settingRow['param_value'], true);
        }

        $response['success'] = true;
        $response['data'] = [
            'customers' => $customers,
            'departments' => $departments,
            'users' => $users,
            'mappings' => $mappings,
            'sales_unit_setting' => $salesUnitSetting,
        ];
    } catch (Exception $e) {
        $response['message'] = '資料讀取失敗：' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX GET ORDERS FOR EDIT (Include allocation info)
else if (isset($_POST['action']) && $_POST['action'] === 'get_orders_for_edit') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json');

    if (!isset($db)) {
        $connInstance = new DBConnection();
        $db = $connInstance->getPDO();
    }

    $d_id        = trim($_POST['d_id']  ?? ''); // d_setting_id（數字）
    $current_bom = trim($_POST['bom']   ?? '');

    if (empty($d_id)) {
        echo json_encode(['success' => true, 'orders' => []]);
        exit;
    }

    try {
        // ── 與 get_orders_for_d_id 相同的四段 fallback 策略 ──
        $orders          = [];
        $strategy_used   = '';
        $strategy_errors = [];

        // 策略A: d_id_ID + JOIN d_setting（最嚴謹）
        $sql_A = "SELECT ot.Order_id, ot.Order_oo, ot.Qty, ot.Open_Qty,
                         CASE
                             WHEN ot.split_seq = 1 THEN
                                 ot.Qty - COALESCE((
                                     SELECT SUM(child.Qty)
                                     FROM order_track child
                                     WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1
                                 ), 0)
                             ELSE ot.Qty END AS Qty, ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                         COALESCE(ot.Specification,'') AS Specification,
                         COALESCE(ot.order_ps,'') AS order_ps,
                         COALESCE(ot.Processing_items,'') AS Processing_items,
                         COALESCE(ot.Order_ps,'') AS Order_ps
                  FROM order_track ot
                  INNER JOIN d_setting ds ON ds.d_id = ot.d_id_ID
                  WHERE ot.d_id_ID = ?
                    AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
                  ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50
";
        try {
            $s = $db->prepare($sql_A);
            $s->execute([$d_id]);
            $orders = $s->fetchAll(PDO::FETCH_ASSOC);
            $strategy_used = 'A';
        } catch(PDOException $eA) { $strategy_errors[] = 'A:'.$eA->getMessage(); }

        // 策略B: d_id_ID 無 JOIN
        if (empty($orders)) {
            $sql_B = "SELECT ot.Order_id, ot.Order_oo, ot.Qty, ot.Open_Qty,
                             CASE
                                 WHEN ot.split_seq = 1 THEN
                                     ot.Qty - COALESCE((
                                         SELECT SUM(child.Qty)
                                         FROM order_track child
                                         WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1
                                     ), 0)
                                 ELSE ot.Qty END AS Qty, ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                             COALESCE(ot.Specification,'') AS Specification,
                             COALESCE(ot.order_ps,'') AS order_ps,
                             COALESCE(ot.Processing_items,'') AS Processing_items,
                             COALESCE(ot.Order_ps,'') AS Order_ps
                      FROM order_track ot
                      WHERE ot.d_id_ID = ?
                        AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
                      ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50
";
            try {
                $s = $db->prepare($sql_B);
                $s->execute([$d_id]);
                $orders = $s->fetchAll(PDO::FETCH_ASSOC);
                $strategy_used = 'B';
            } catch(PDOException $eB) { $strategy_errors[] = 'B:'.$eB->getMessage(); }
        }

        // 策略C: fallback 到 order_track.d_id（舊欄位）
        if (empty($orders)) {
            $sql_C = "SELECT ot.Order_id, ot.Order_oo, ot.Qty, ot.Open_Qty,
                             CASE
                                 WHEN ot.split_seq = 1 THEN
                                     ot.Qty - COALESCE((
                                         SELECT SUM(child.Qty)
                                         FROM order_track child
                                         WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1
                                     ), 0)
                                 ELSE ot.Qty END AS Qty, ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                             COALESCE(ot.Specification,'') AS Specification,
                             COALESCE(ot.order_ps,'') AS order_ps,
                             COALESCE(ot.Processing_items,'') AS Processing_items,
                             COALESCE(ot.Order_ps,'') AS Order_ps
                      FROM order_track ot
                      WHERE ot.d_id = ?
                        AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
                      ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50
";
            try {
                $s = $db->prepare($sql_C);
                $s->execute([$d_id]);
                $orders = $s->fetchAll(PDO::FETCH_ASSOC);
                $strategy_used = 'C';
            } catch(PDOException $eC) { $strategy_errors[] = 'C:'.$eC->getMessage(); }
        }

        // ── 取本 BOM 已綁定量 ──
        $my_allocs = [];
        if (!empty($current_bom)) {
            try {
                $stmt_my = $db->prepare("SELECT order_id, allocated_qty FROM bom_order_process_map WHERE bom = ?");
                $stmt_my->execute([$current_bom]);
                while ($r = $stmt_my->fetch(PDO::FETCH_ASSOC)) {
                    $my_allocs[$r['order_id']] = $r['allocated_qty'];
                }
            } catch(PDOException $eM) { $strategy_errors[] = 'my_allocs:'.$eM->getMessage(); }
        }

        // ── 取其他 BOM 對各訂單的佔用量 ──
        $other_allocs = [];
        if (!empty($current_bom)) {
            try {
                $stmt_other = $db->prepare("SELECT order_id, SUM(allocated_qty) AS used FROM bom_order_process_map WHERE bom != ? GROUP BY order_id");
                $stmt_other->execute([$current_bom]);
                while ($r = $stmt_other->fetch(PDO::FETCH_ASSOC)) {
                    $other_allocs[$r['order_id']] = $r['used'];
                }
            } catch(PDOException $eO) { $strategy_errors[] = 'other_allocs:'.$eO->getMessage(); }
        }

        // ── 附加綁定資訊到每筆訂單 ──
        foreach ($orders as &$o) {
            $oid = $o['Order_id'];
            $o['my_allocated']        = $my_allocs[$oid] ?? 0;
            $o['is_bound']            = array_key_exists($oid, $my_allocs);
            $used_by_others           = $other_allocs[$oid] ?? 0;
            // available = 訂單總數 - 其他BOM佔用（不含本BOM，本BOM的份額可自由調整）
            $o['available_qty_for_bind'] = max(0, (int)$o['Qty'] - (int)$used_by_others);
        }
        unset($o);

        // ── 偵測料號 ID 不一致（相同料號文字但不同 d_id_ID 的訂單）──
        $mismatch_info = null;
        if (!empty($current_bom) && !empty($d_id)) {
            try {
                $stmt_bdt = $db->prepare("SELECT d_id FROM bom WHERE bom = ?");
                $stmt_bdt->execute([$current_bom]);
                $bom_d_id_text = $stmt_bdt->fetchColumn();

                if ($bom_d_id_text) {
                    $stmt_mm = $db->prepare(
                        "SELECT DISTINCT ot.d_id_ID, ds.D_Setting_Id
                         FROM order_track ot
                         LEFT JOIN d_setting ds ON ds.d_id = ot.d_id_ID
                         WHERE ot.d_id = ?
                           AND ot.d_id_ID IS NOT NULL
                           AND ot.d_id_ID != ?
                           AND (ot.Order_status IS NULL OR ot.Order_status <> 9)"
                    );
                    $stmt_mm->execute([$bom_d_id_text, (int)$d_id]);
                    $mismatched = $stmt_mm->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($mismatched)) {
                        $mismatch_info = [
                            'd_id_text'        => $bom_d_id_text,
                            'bom_d_setting_id' => (int)$d_id,
                            'mismatched'       => $mismatched,
                        ];
                    }
                }
            } catch (PDOException $eX) { /* 偵測失敗不影響主功能 */ }
        }

        echo json_encode([
            'success'         => true,
            'orders'          => $orders,
            'strategy_used'   => $strategy_used,
            'queried_value'   => $d_id,
            'strategy_errors' => $strategy_errors,
            'mismatch_info'   => $mismatch_info,
        ]);

    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// AJAX UPDATE CUSTOMER SALES HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'update_customer_sales') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    $updates = $_POST['updates'] ?? [];
    if (is_string($updates)) {
        $updates = json_decode($updates, true);
    }
    $userId = $_SESSION['id'] ?? 0;

    try {
        $db->beginTransaction();
        
        // This is the UPSERT statement. It will INSERT if the (customer_id, role) pair is new.
        // If the pair already exists (violating the unique key uk_customer_role), it will UPDATE the existing row.
        $stmtUpsert = $db->prepare(
            "INSERT INTO customer_sales (customer_id, user_id, role, is_active, Created_By, Created_At) 
             VALUES (:cid, :uid_sales, :role, 1, :uid, NOW())
             ON DUPLICATE KEY UPDATE 
                user_id = VALUES(user_id), 
                is_active = 1,
                Created_By = VALUES(Created_By), 
                Created_At = NOW()" // Also update timestamp on update
        );

        // This statement is for when a salesperson is removed (set to '-- 請選擇 --').
        // It deactivates the assignment for that role.
        $stmtDeactivate = $db->prepare(
            "UPDATE customer_sales SET is_active = 0 WHERE customer_id = :cid AND role = :role"
        );
        
        foreach ($updates as $upd) {
            $cid = $upd['customer_id'];
            $p_uid = $upd['primary_user_id'];
            $d_uid = $upd['deputy_user_id'];
            
            // Handle Primary salesperson
            if (!empty($p_uid)) {
                // If a primary user is selected, perform an UPSERT.
                $stmtUpsert->execute([':cid' => $cid, ':uid_sales' => $p_uid, ':role' => 'primary', ':uid' => $userId]);
            } else {
                // If no primary user is selected, deactivate any existing primary assignment.
                $stmtDeactivate->execute([':cid' => $cid, ':role' => 'primary']);
            }
            
            // Handle Deputy salesperson
            if (!empty($d_uid)) {
                // If a deputy user is selected, perform an UPSERT.
                $stmtUpsert->execute([':cid' => $cid, ':uid_sales' => $d_uid, ':role' => 'deputy', ':uid' => $userId]);
            } else {
                // If no deputy user is selected, deactivate any existing deputy assignment.
                $stmtDeactivate->execute([':cid' => $cid, ':role' => 'deputy']);
            }
        }
        
        $db->commit();
        $response['success'] = true;
        $response['message'] = '業務設定已更新';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $response['message'] = '更新失敗: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX ADD NEW CUSTOMER HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'add_new_customer') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    $customer_id = $_POST['customer_id'] ?? '';
    $customer_name = $_POST['customer_name'] ?? '';
    $customer_address = $_POST['customer_address'] ?? '';

    try {
        // Check if ID exists
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id = :id");
        $stmtCheck->execute([':id' => $customer_id]);
        if ($stmtCheck->fetchColumn() > 0) {
            $response['message'] = '客戶代碼已存在';
            echo json_encode($response);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO customer_list (customer_id, customer, customer_address, is_inactive) VALUES (:id, :name, :addr, 0)");
        $stmt->execute([':id' => $customer_id, ':name' => $customer_name, ':addr' => $customer_address]);
        $response['success'] = true;
        $response['message'] = '客戶已新增';
    } catch (Exception $e) {
        $response['message'] = '新增失敗: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX UPDATE CUSTOMER DATA HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'update_customer_data') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    $customer_id = $_POST['customer_id'] ?? '';
    $customer_name = $_POST['customer_name'] ?? '';
    $customer_address = $_POST['customer_address'] ?? '';

    try {
        $stmt = $db->prepare("UPDATE customer_list SET customer = :name, customer_address = :addr WHERE customer_id = :id");
        $stmt->execute([':name' => $customer_name, ':addr' => $customer_address, ':id' => $customer_id]);
        $response['success'] = true;
        $response['message'] = '客戶資料已更新';
    } catch (Exception $e) {
        $response['message'] = '更新失敗: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// AJAX SAVE FILE TAGS SETTING
else if (isset($_POST['action']) && $_POST['action'] === 'save_file_tags_setting') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json');
    
    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    $tags_config = $_POST['tags_config'] ?? '[]';
    $user = $_SESSION['id'] ?? 'system';

    try {
        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at) 
                VALUES ('BOM_FILE_TAGS', 'tags_config', :val, 'BOM檔案標籤設定', :user, NOW())
                ON DUPLICATE KEY UPDATE param_value = :val_upd, updated_by = :user_upd, updated_at = NOW()";
        $stmt = $db->prepare($sql);
        $stmt->execute([':val' => $tags_config, ':user' => $user, ':val_upd' => $tags_config, ':user_upd' => $user]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// AJAX GET FILE TAGS SETTING
else if (isset($_POST['action']) && $_POST['action'] === 'get_file_tags_setting') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json');
    
    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    try {
        $stmt = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'BOM_FILE_TAGS' AND param_key = 'tags_config'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $config = $row ? json_decode($row['param_value'], true) : [];
        echo json_encode(['success' => true, 'config' => $config]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// AJAX GET BOM FILES HANDLER
else if (isset($_POST['action']) && $_POST['action'] === 'get_bom_files') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'files' => [], 'erp_files' => []];

    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            try {
                $connInstance = new DBConnection();
                $db = $connInstance->getPDO();
            } catch (Exception $e) {
                // 忽略連線錯誤，僅影響料號查詢
            }
        }
    }
    
    $bom = $_POST['bom'] ?? '';
    if (empty($bom)) {
        $response['message'] = 'BOM is empty';
        echo json_encode($response);
        exit;
    }

    // 查詢料號 (d_id)
    $d_id = '';
    if (isset($db)) {
        $stmt = $db->prepare("SELECT d_id FROM bom WHERE bom = ?");
        $stmt->execute([$bom]);
        $d_id = $stmt->fetchColumn();
    }

    // Fetch tags config
    $tags_config = [];
    if (isset($db)) {
        $stmt = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'BOM_FILE_TAGS' AND param_key = 'tags_config'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tags_config = json_decode($row['param_value'], true);
        }
    }

    $scan_dir = 'Z:/BOM/'; // 實體路徑
    $url_dir = '/nas/';    // 網頁讀取路徑

    if (is_dir($scan_dir)) {
        $allFiles = scandir($scan_dir);
        foreach ($allFiles as $f) {
            if ($f === '.' || $f === '..') continue;
            // Check if file starts with BOM
            if (strpos($f, $bom) === 0) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                    $isPlus = (strpos($f, '++') !== false);
                    $fileList[] = [
                        'name' => $f,
                        'path' => $url_dir . $f,
                        'type' => $ext,
                        'is_plus' => $isPlus,
                        'mtime' => filemtime($scan_dir . $f)
                    ];
                }
            }
        }
        // Sort: Non-++ first, then by mtime desc
        usort($fileList, function($a, $b) {
            if ($a['is_plus'] !== $b['is_plus']) {
                return $a['is_plus'] ? 1 : -1; // false comes first
            }
            return $b['mtime'] - $a['mtime'];
        });
        $response['files'] = $fileList;
    }

    // --- Scan ERP Directory ---
    $erp_files = [];
    $erp_path_utf8 = 'Z:/BOM/ERP/資材(生管and業務)/BOM/';
    $os = PHP_OS;
    $erp_scan_path = $erp_path_utf8;
    
    // Windows 路徑編碼處理
    if (strtoupper(substr($os, 0, 3)) === 'WIN') {
        $erp_scan_path = mb_convert_encoding($erp_scan_path, 'Big5', 'UTF-8');
    }

    if (is_dir($erp_scan_path)) {
        $files = scandir($erp_scan_path);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            
            // 轉回 UTF-8 進行比對
            $f_utf8 = $f;
            if (strtoupper(substr($os, 0, 3)) === 'WIN') {
                $f_utf8 = mb_convert_encoding($f, 'UTF-8', 'Big5');
            }
            
            // 篩選條件：開頭為 BOM 或 包含 [料號]
            $isMatchBOM = (strpos($f_utf8, $bom) === 0);
            $isMatchDid = ($d_id && strpos($f_utf8, "[$d_id]") !== false);
            
            if ($isMatchBOM || $isMatchDid) {
                $ext = strtolower(pathinfo($f_utf8, PATHINFO_EXTENSION));
                
                $file_tags = [];
                if (!empty($tags_config) && is_array($tags_config)) {
                    foreach ($tags_config as $config) {
                        $suffix = $config['suffix'] ?? '';
                        if ($suffix !== '') {
                            $isTag = false;
                            // 1. Check BOM + Suffix (Starts with)
                            $searchBOM = $bom . $suffix;
                            if (stripos($f_utf8, $searchBOM) === 0) {
                                $after = substr($f_utf8, strlen($searchBOM));
                                if ($after === '' || preg_match('/^[^a-zA-Z0-9]/', $after)) {
                                    $isTag = true;
                                }
                            }
                            // 2. Check [d_id] + Suffix (Contains)
                            if (!$isTag && $d_id) {
                                $searchDid = "[$d_id]" . $suffix;
                                $pos = stripos($f_utf8, $searchDid);
                                if ($pos !== false) {
                                    $after = substr($f_utf8, $pos + strlen($searchDid));
                                    if ($after === '' || preg_match('/^[^a-zA-Z0-9]/', $after)) {
                                        $isTag = true;
                                    }
                                }
                            }

                            if ($isTag) {
                                $file_tags[] = [
                                    'label' => $config['label'] ?? '',
                                    'color' => $config['color'] ?? 'default'
                                ];
                            }
                        }
                    }
                } else {
                    // Legacy fallback if no config
                    $isTag = false;
                    $suffix = '-T';
                    
                    // 1. Check BOM + Suffix
                    $searchBOM = $bom . $suffix;
                    if (stripos($f_utf8, $searchBOM) === 0) {
                        $after = substr($f_utf8, strlen($searchBOM));
                        if ($after === '' || preg_match('/^[^a-zA-Z0-9]/', $after)) {
                            $isTag = true;
                        }
                    }
                    // 2. Check [d_id] + Suffix
                    if (!$isTag && $d_id) {
                        $searchDid = "[$d_id]" . $suffix;
                        $pos = stripos($f_utf8, $searchDid);
                        if ($pos !== false) {
                            $after = substr($f_utf8, $pos + strlen($searchDid));
                            if ($after === '' || preg_match('/^[^a-zA-Z0-9]/', $after)) {
                                $isTag = true;
                            }
                        }
                    }

                    if ($isTag) {
                        $file_tags[] = ['label' => '齒研報告', 'color' => 'success'];
                    }
                }
                
                $erp_files[] = [
                    'name' => $f_utf8,
                    'path' => '/nas/ERP/' . rawurlencode('資材(生管and業務)') . '/BOM/' . rawurlencode($f_utf8),
                    'type' => $ext,
                    'tags' => $file_tags,
                    'mtime' => filemtime($erp_scan_path . $f),
                    'match_type' => $isMatchBOM ? 'bom' : 'did'
                ];
            }
        }
        // 排序：依時間新到舊
        usort($erp_files, function($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });
    }
    $response['erp_files'] = $erp_files;
    $response['success'] = true;

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX GET REPORT DETAILS FOR POPOVER
else if (isset($_POST['action']) && $_POST['action'] === 'get_report_details_for_popover') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json');
    
    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    $fids = $_POST['bom_ing_fids'] ?? '';
    
    if (empty($fids)) {
        echo json_encode(['success' => false, 'message' => 'No FIDs provided']);
        exit;
    }

    // Sanitize FIDs
    $fidArray = explode(',', $fids);
    $fidArray = array_filter(array_map(function($val) {
        return is_numeric($val) ? intval($val) : null;
    }, $fidArray));
    
    if (empty($fidArray)) {
        echo json_encode(['success' => false, 'message' => 'Invalid FIDs']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($fidArray), '?'));

    try {
        $sql = "
            SELECT 
                r.report_date,
                r.produced_qty,
                r.is_finished,
                (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id = r.report_id) as ng_qty,
                u.user_cname as operator,
                u2.user_cname as setup_operator,
                r.remark,
                r.setup_start_time,
                r.production_start_time
            FROM pm_process_daily_report r
            LEFT JOIN user u ON r.production_user_id = u.id
            LEFT JOIN user u2 ON r.setup_user_id = u2.id
            WHERE r.bom_ing_fid IN ($placeholders)
            ORDER BY r.report_date DESC, r.report_id DESC
            LIMIT 5
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($fidArray));
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $reports]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// AJAX GET ALL REPORTS FOR BOM (NEW)
else if (isset($_POST['action']) && $_POST['action'] === 'get_all_reports_for_bom') {
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json');
    
    if (!isset($db)) {
        if (class_exists('DBConnection')) {
            $connInstance = new DBConnection();
            $db = $connInstance->getPDO();
        }
    }

    $bom = $_POST['bom'] ?? '';
    
    if (empty($bom)) {
        echo json_encode(['success' => false, 'message' => 'BOM is required']);
        exit;
    }

    try {
        $sql = "
            SELECT 
                pdr.*, 
                bi.bom_sn, 
                bi.process_no, 
                pn.ProcessName,
                u.user_cname as operator,
                u2.user_cname as setup_operator,
                (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id = pdr.report_id) as ng_qty
            FROM pm_process_daily_report pdr
            JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN user u ON pdr.production_user_id = u.id
            LEFT JOIN user u2 ON pdr.setup_user_id = u2.id
            WHERE bi.bom = ?
            ORDER BY pdr.report_date DESC, pdr.report_id DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$bom]);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $reports]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


// ── 全域搜索：查詢已結案資料筆數 ───────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'check_closed_bom') {
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $q = trim($_POST['q'] ?? '');
    if (empty($q)) { echo json_encode(['count' => 0]); exit; }
    try {
        $like = '%' . $q . '%';
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM bom b
            WHERE (b.bom_ing_id = 1 OR b.processing_state = '1')
              AND (b.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ? OR b.bom_ps LIKE ?)
        ");
        $stmt->execute([$like, $like, $like, $like]);
        echo json_encode(['count' => (int)$stmt->fetchColumn()]);
    } catch (PDOException $e) {
        echo json_encode(['count' => 0]);
    }
    exit;
}

// ── 取消移轉 ──────────────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'cancel_transfer') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $fid = trim($_POST['bom_ing_fid'] ?? '');
    $uid = $_SESSION['id'] ?? 'system';
    if (empty($fid)) { echo json_encode(['success'=>false,'message'=>'缺少fid']); exit; }
    try {
        // 先確認 bom_ing_fid 存在
        $chk = $db->prepare("SELECT bom_ing_fid, processing_state FROM bom_ing WHERE bom_ing_fid = ?");
        $chk->execute([$fid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'message'=>'找不到對應製程記錄 (fid='.$fid.')']); exit; }
        $cur = $row['processing_state'];
        // 根據當前狀態決定行為
        if ($cur === 'N') {
            // 最初狀態，無反應
            echo json_encode(['success'=>false,'no_action'=>true,'message'=>'目前已是最初狀態(N)，無須回歸。','fid'=>$fid,'current_state'=>$cur]);
            exit;
        }
        if ($cur === 'P') {
            // P 狀態由 QC 回報，不做回歸
            echo json_encode(['success'=>false,'qc_state'=>true,'message'=>'本狀態由QC回報，不可手動回歸。','fid'=>$fid,'current_state'=>$cur]);
            exit;
        }
        // ing → N，Q → ing，E → P
        if ($cur === 'ing') {
            $new_state = 'N';
            $stmt = $db->prepare("UPDATE bom_ing SET processing_state='N', outsource_date=NULL, maker_id_no=NULL, maker_id=NULL, Modified_At=NOW(), Modified_By=:u WHERE bom_ing_fid=:f");
            $stmt->execute([':u'=>$uid, ':f'=>$fid]);
        } elseif ($cur === 'Q') {
            $new_state = 'ing';
            $stmt = $db->prepare("UPDATE bom_ing SET processing_state='ing', return_date=NULL, Modified_At=NOW(), Modified_By=:u WHERE bom_ing_fid=:f");
            $stmt->execute([':u'=>$uid, ':f'=>$fid]);
        } elseif ($cur === 'E') {
            $new_state = 'P';
            $stmt = $db->prepare("UPDATE bom_ing SET processing_state='P', Modified_At=NOW(), Modified_By=:u WHERE bom_ing_fid=:f");
            $stmt->execute([':u'=>$uid, ':f'=>$fid]);
        } else {
            echo json_encode(['success'=>false,'message'=>'此狀態('.$cur.')不支援回歸。','fid'=>$fid,'current_state'=>$cur]);
            exit;
        }
        echo json_encode(['success'=>true,'message'=>'已回歸至前一狀態('.$new_state.')','rows_affected'=>$stmt->rowCount(),'fid'=>$fid,'prev_state'=>$cur,'new_state'=>$new_state]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage(),'fid'=>$fid]); }
    exit;
}

// ── 取消結案 ──────────────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'cancel_bom_close') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom         = strtoupper(trim($_POST['bom'] ?? ''));
    $uid         = $_SESSION['id'] ?? 'system';
    $operator_id = isset($_SESSION['id']) && is_numeric($_SESSION['id']) ? (int)$_SESSION['id'] : null;
    if (empty($bom)) { echo json_encode(['success'=>false,'message'=>'缺少BOM編號']); exit; }
    try {
        $chk = $db->prepare("SELECT bom, processing_state FROM bom WHERE bom = ?");
        $chk->execute([$bom]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'message'=>'找不到對應BOM記錄 (bom='.$bom.')']); exit; }
        $stmt = $db->prepare("UPDATE bom SET processing_state=NULL, bom_ing_id=NULL, closed_by=NULL, closed_at=NULL, Modified_By=?, Modified_At=NOW() WHERE bom=?");
        $stmt->execute([$uid, $bom]);
        // 流水帳
        try {
            $db->prepare("INSERT INTO bom_operation_log (bom, operation_type, operator_id, details_json) VALUES (?, 'cancel_close', ?, ?)")
               ->execute([$bom, $operator_id, json_encode(['操作'=>'取消結案'])]);
        } catch(PDOException $le){ error_log('bom_operation_log insert error: '.$le->getMessage()); }
        echo json_encode(['success'=>true,'message'=>'已取消結案，BOM已回到進行中狀態','rows_affected'=>$stmt->rowCount(),'bom'=>$bom]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage(),'bom'=>$bom]); }
    exit;
}

// ── 操作流水帳查詢 ────────────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'fetch_bom_operation_log') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }

    $mode   = trim($_POST['mode']   ?? 'day');   // day | month
    $date   = trim($_POST['date']   ?? date('Y-m-d'));
    $month  = trim($_POST['month']  ?? date('Y-m'));
    $search = trim($_POST['search'] ?? '');

    // 驗證格式
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))   $date  = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}$/', $month))         $month = date('Y-m');

    try {
        $binds = [];
        if ($mode === 'day') {
            $dateCond = "DATE(bol.operated_at) = ?";
            $binds[] = $date;
        } else {
            $dateCond = "DATE_FORMAT(bol.operated_at,'%Y-%m') = ?";
            $binds[] = $month;
        }
        $searchCond = '';
        if ($search !== '') {
            $like = '%' . $search . '%';
            $searchCond = " AND (bol.bom LIKE ? OR b.d_id LIKE ? OR COALESCE(cl.customer, b.Client_Name) LIKE ?)";
            $binds = array_merge($binds, [$like, $like, $like]);
        }

        $sql = "SELECT bol.id, bol.bom, bol.bom_ing_fid, bol.operation_type,
                       bol.operator_id, u.user_cname AS operator_name,
                       DATE_FORMAT(bol.operated_at,'%Y-%m-%d %H:%i:%s') AS operated_at,
                       bol.details_json,
                       b.d_id, COALESCE(cl.customer, b.Client_Name) AS client_name
                FROM bom_operation_log bol
                LEFT JOIN bom b ON b.bom = bol.bom
                LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
                LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                LEFT JOIN user u ON u.id = bol.operator_id
                WHERE $dateCond $searchCond
                ORDER BY bol.operated_at DESC
                LIMIT 300";
        $stmt = $db->prepare($sql);
        $stmt->execute($binds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 簡易統計（依操作類型）
        $opLabels = ['manual_close'=>'人工結案','cancel_close'=>'取消結案','transfer'=>'移轉','create_bom'=>'新增BOM'];
        $stats = [];
        foreach ($rows as $r) {
            $t = $r['operation_type'];
            $stats[$t] = ($stats[$t] ?? 0) + 1;
        }

        echo json_encode(['success'=>true,'data'=>$rows,'stats'=>$stats,'op_labels'=>$opLabels,'mode'=>$mode,'date'=>$date,'month'=>$month]);
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── 新增 BOM ───────────────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'create_bom') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom          = strtoupper(trim($_POST['bom'] ?? ''));
    $d_setting_id = trim($_POST['d_setting_id'] ?? ''); // d_setting.d_id 內部ID
    $d_id         = trim($_POST['d_id'] ?? '');         // d_setting.D_Setting_Id 顯示文字
    $cname        = trim($_POST['client_name'] ?? '');
    $sqty   = intval($_POST['sqty'] ?? 0);
    $bom_ps = trim($_POST['bom_ps'] ?? '');
    $procs  = json_decode($_POST['processes'] ?? '[]', true) ?: [];
    $uid    = $_SESSION['id'] ?? 'system';
    // 訂單綁定清單 [{ order_id, pcs }]
    $orders_json = json_decode($_POST['order_pcs_json'] ?? '[]', true) ?: [];

    if (empty($bom)||empty($d_id)||$sqty<1) { echo json_encode(['success'=>false,'message'=>'必填欄位不足']); exit; }
    $chk = $db->prepare("SELECT COUNT(*) FROM bom WHERE bom=?"); $chk->execute([$bom]);
    if ($chk->fetchColumn()>0) { echo json_encode(['success'=>false,'bom_exists'=>true,'message'=>'BOM號碼「'.$bom.'」已存在。']); exit; }

    // 找第一筆勾選訂單的 order_id 存入 o_order_id
    $first_order_id = null;
    if (!empty($orders_json) && isset($orders_json[0]['order_id'])) {
        $first_order_id = $orders_json[0]['order_id'];
    }

    try {
        $db->beginTransaction();
        // 1. 新增 bom 主檔
        $db->prepare("INSERT INTO bom (bom,d_id,d_setting_id,sqty,Client_Name,o_order_id,bom_ps,Created_By,Modified_By) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$bom, $d_id, ($d_setting_id ?: null), $sqty, $cname, $first_order_id, $bom_ps, $uid, $uid]);
        // 2. 新增 bom_ing（每個製程），含廠商、備註
        $si = $db->prepare("INSERT INTO bom_ing (bom_ing_id,bom,process_no,bom_sn,processing_sequence,sqty,processing_state,maker_id_no,maker_id,ps,Created_By,Modified_By) VALUES (?,?,?,?,?,?,'P',?,?,?,?,?)");
        foreach ($procs as $idx=>$p) {
            $pno       = intval($p['process_no']);
            $bsn       = ($idx+1)*10;
            $iid       = substr($bom,-9).'-'.$pno.'-'.$bsn.'-'.$sqty;
            $maker_no  = $p['maker_id_no'] ?? null;
            $maker_id  = $p['maker_id'] ?? null;
            $ps        = $p['ps'] ?? null;
            $si->execute([$iid,$bom,$pno,$bsn,$idx+1,$sqty,$maker_no,$maker_id,$ps,$uid,$uid]);
        }
        // 3. 新增 bom_order_process_map（每筆勾選訂單）
        if (!empty($orders_json)) {
            $sm = $db->prepare("INSERT IGNORE INTO bom_order_process_map (bom, order_id, allocated_qty) VALUES (?,?,?)");
            foreach ($orders_json as $oj) {
                $oid_m = $oj['order_id'] ?? null;
                $pcs_m = intval($oj['pcs'] ?? 0) ?: null;
                if ($oid_m) $sm->execute([$bom, $oid_m, $pcs_m]);
            }
        }
        $db->commit();
        echo json_encode(['success'=>true,'bom'=>$bom]);
    } catch(PDOException $e){ $db->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 搜尋料號 ───────────────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'search_d_id_and_orders') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $term = trim($_POST['term'] ?? '');
    if (strlen($term)<1) { echo json_encode(['success'=>true,'d_ids'=>[]]); exit; }
    try {
        // 先嘗試 d_setting 表，若無資料則 fallback 到 order_track.d_id（舊版可用）
        $d_ids = [];
        $rows = [];
        try {
            // 搜尋 D_Setting_Id（顯示料號）和 Spec_No，回傳 d_id（內部ID）和 display_id（顯示文字）
            $s = $db->prepare("
                SELECT ds.d_id AS d_setting_id,
                       COALESCE(ds.D_Setting_Id, CAST(ds.d_id AS CHAR), '') AS display_id,
                       COALESCE(ds.Drawing_No,'') AS drawing_no,
                       COALESCE(ds.Spec_No,'') AS spec_no,
                       COALESCE(cl.customer_id,'') AS customer_id,
                       COALESCE(cl.customer,'') AS customer_name
                FROM d_setting ds
                LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                WHERE ds.D_Setting_Id LIKE ? OR ds.Drawing_No LIKE ? OR ds.Spec_No LIKE ?
                ORDER BY ds.D_Setting_Id ASC LIMIT 30
            ");
            $s->execute(['%'.$term.'%', '%'.$term.'%', '%'.$term.'%']);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e2) { $rows = []; }

        if (!empty($rows)) {
            foreach($rows as $r){
                $d_ids[]=[
                    'd_id'         => $r['d_setting_id'],   // 數字內部ID，傳後端查訂單
                    'display_id'   => $r['display_id'],     // 前端顯示料號文字
                    'spec_no'      => $r['spec_no'],
                    'customer_id'  => $r['customer_id'],
                    'customer_name'=> $r['customer_name'],
                    'client'       => $r['customer_name'],  // 相容舊欄位
                ];
            }
        } else {
            // Fallback：使用 order_track.d_id（前端顯示文字，直接搜尋）
            $s2 = $db->prepare("SELECT DISTINCT d_id, Client_name FROM order_track WHERE d_id LIKE ? ORDER BY d_id ASC LIMIT 30");
            $s2->execute(['%'.$term.'%']);
            $rows2 = $s2->fetchAll(PDO::FETCH_ASSOC);
            $seen = [];
            foreach($rows2 as $r){
                if(!isset($seen[$r['d_id']])){
                    $seen[$r['d_id']]=1;
                    $d_ids[]=['d_id'=>null,'display_id'=>$r['d_id'],'spec_no'=>'','customer_id'=>'','customer_name'=>$r['Client_name'],'client'=>$r['Client_name']];
                }
            }
        }
        echo json_encode(['success'=>true,'d_ids'=>$d_ids]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 取得料號訂單清單 ──────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'get_orders_for_d_id') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $d_id = trim($_POST['d_id'] ?? '');
    if (empty($d_id)) { echo json_encode(['success'=>true,'orders'=>[],'client'=>'']); exit; }
    try {
        // 先嘗試 d_id_ID 欄位（料號正式欄位），失敗則 fallback 到 d_id（暫存欄位）
        $orders = [];
        $strategy_used = '';
        $strategy_errors = [];
        // 策略A: order_track.d_id_ID + JOIN d_setting + allocated_qty
        $sql_A = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.Qty, ot.Open_Qty,
                   CASE
                       WHEN ot.split_seq = 1 THEN
                           ot.Qty - COALESCE((
                               SELECT SUM(child.Qty)
                               FROM order_track child
                               WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1
                           ), 0)
                       ELSE ot.Qty END AS Qty, ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                   COALESCE(ot.Specification,'') AS Specification,
                   COALESCE(ot.order_ps,'') AS order_ps,
                   COALESCE(SUM(bopm.allocated_qty),0) AS already_allocated,
                   (ot.Qty - COALESCE(SUM(bopm.allocated_qty),0)) AS available_qty
            FROM order_track ot
            INNER JOIN d_setting ds ON ds.d_id = ot.d_id_ID
            LEFT JOIN bom_order_process_map bopm ON bopm.order_id = ot.Order_id
            WHERE ot.d_id_ID = ?
              AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
            GROUP BY ot.Order_id, ot.Order_oo, ot.Client_name, ot.Qty, ot.Open_Qty,
                     ot.Delivery_date, ot.Specification, ot.order_ps
            ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50";
        $sql_A = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name,
                   (CASE WHEN ot.split_seq = 1 THEN ot.Qty - COALESCE((SELECT SUM(child.Qty) FROM order_track child WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1), 0) ELSE ot.Qty END) AS Qty,
                   ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                   COALESCE(ot.Specification,'') AS Specification, COALESCE(ot.order_ps,'') AS order_ps,
                   COALESCE(SUM(bopm.allocated_qty),0) AS already_allocated,
                   ((CASE WHEN ot.split_seq = 1 THEN ot.Qty - COALESCE((SELECT SUM(child.Qty) FROM order_track child WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1), 0) ELSE ot.Qty END) - COALESCE(SUM(bopm.allocated_qty),0)) AS available_qty
            FROM order_track ot INNER JOIN d_setting ds ON ds.d_id = ot.d_id_ID LEFT JOIN bom_order_process_map bopm ON bopm.order_id = ot.Order_id WHERE ot.d_id_ID = ? AND (ot.Order_status IS NULL OR ot.Order_status <> 9) GROUP BY ot.Order_id, ot.Order_oo, ot.Client_name, ot.split_seq, ot.Qty, ot.Open_Qty, ot.Delivery_date, ot.Specification, ot.order_ps ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50
";
        try {
            $s = $db->prepare($sql_A);
            $s->execute([$d_id]);
            $orders = $s->fetchAll(PDO::FETCH_ASSOC);
            $strategy_used = 'A';
        } catch(PDOException $eA) { $strategy_errors[] = 'A:'.$eA->getMessage(); }

        // 策略B: order_track.d_id_ID (無JOIN)
        if (empty($orders)) {
            $sql_B = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.Qty, ot.Open_Qty,
                   CASE
                       WHEN ot.split_seq = 1 THEN
                           ot.Qty - COALESCE((
                               SELECT SUM(child.Qty)
                               FROM order_track child
                               WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1
                           ), 0)
                       ELSE ot.Qty END AS Qty, ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                   COALESCE(ot.Specification,'') AS Specification,
                   COALESCE(ot.order_ps,'') AS order_ps,
                   COALESCE(SUM(bopm.allocated_qty),0) AS already_allocated,
                   (ot.Qty - COALESCE(SUM(bopm.allocated_qty),0)) AS available_qty
            FROM order_track ot
            LEFT JOIN bom_order_process_map bopm ON bopm.order_id = ot.Order_id
            WHERE ot.d_id_ID = ?
              AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
            GROUP BY ot.Order_id, ot.Order_oo, ot.Client_name, ot.Qty, ot.Open_Qty,
                     ot.Delivery_date, ot.Specification, ot.order_ps
            ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50";
        $sql_B = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name,
                   (CASE WHEN ot.split_seq = 1 THEN ot.Qty - COALESCE((SELECT SUM(child.Qty) FROM order_track child WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1), 0) ELSE ot.Qty END) AS Qty,
                   ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                   COALESCE(ot.Specification,'') AS Specification, COALESCE(ot.order_ps,'') AS order_ps,
                   COALESCE(SUM(bopm.allocated_qty),0) AS already_allocated,
                   ((CASE WHEN ot.split_seq = 1 THEN ot.Qty - COALESCE((SELECT SUM(child.Qty) FROM order_track child WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1), 0) ELSE ot.Qty END) - COALESCE(SUM(bopm.allocated_qty),0)) AS available_qty
            FROM order_track ot LEFT JOIN bom_order_process_map bopm ON bopm.order_id = ot.Order_id WHERE ot.d_id_ID = ? AND (ot.Order_status IS NULL OR ot.Order_status <> 9) GROUP BY ot.Order_id, ot.Order_oo, ot.Client_name, ot.split_seq, ot.Qty, ot.Open_Qty, ot.Delivery_date, ot.Specification, ot.order_ps ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50
";
            try {
                $s = $db->prepare($sql_B);
                $s->execute([$d_id]);
                $orders = $s->fetchAll(PDO::FETCH_ASSOC);
                $strategy_used = 'B';
            } catch(PDOException $eB) { $strategy_errors[] = 'B:'.$eB->getMessage(); }
        }

        // 策略C: order_track.d_id_ID (無allocated_qty)
        if (empty($orders)) {
            $sql_C = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.Qty, ot.Open_Qty,
                   CASE
                       WHEN ot.split_seq = 1 THEN
                           ot.Qty - COALESCE((
                               SELECT SUM(child.Qty)
                               FROM order_track child
                               WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1
                           ), 0)
                       ELSE ot.Qty END AS Qty, ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                   COALESCE(ot.Specification,'') AS Specification,
                   COALESCE(ot.order_ps,'') AS order_ps,
                   0 AS already_allocated, ot.Open_Qty AS available_qty
            FROM order_track ot
            WHERE ot.d_id_ID = ?
              AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
            ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50
";
            try {
                $s = $db->prepare($sql_C);
                $s->execute([$d_id]);
                $orders = $s->fetchAll(PDO::FETCH_ASSOC);
                $strategy_used = 'C';
            } catch(PDOException $eC) { $strategy_errors[] = 'C:'.$eC->getMessage(); }
        }

        // 策略D: fallback order_track.d_id (舊欄位)
        if (empty($orders)) {
            $sql_D = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.Qty, ot.Open_Qty,
                   CASE
                       WHEN ot.split_seq = 1 THEN
                           ot.Qty - COALESCE((
                               SELECT SUM(child.Qty)
                               FROM order_track child
                               WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1
                           ), 0)
                       ELSE ot.Qty END AS Qty, ot.Open_Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                   COALESCE(ot.Specification,'') AS Specification,
                   COALESCE(ot.order_ps,'') AS order_ps,
                   0 AS already_allocated, ot.Open_Qty AS available_qty
            FROM order_track ot
            WHERE ot.d_id = ?
              AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
            ORDER BY CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) DESC, ot.Order_oo DESC LIMIT 50
";
            try {
                $s = $db->prepare($sql_D);
                $s->execute([$d_id]);
                $orders = $s->fetchAll(PDO::FETCH_ASSOC);
                $strategy_used = 'D';
            } catch(PDOException $eD) { $strategy_errors[] = 'D:'.$eD->getMessage(); }
        }

        $client = !empty($orders) ? $orders[0]['Client_name'] : '';
        echo json_encode([
            'success'        => true,
            'orders'         => $orders,
            'client'         => $client,
            'strategy_used'  => $strategy_used,
            'queried_value'  => $d_id,
            'strategy_errors'=> $strategy_errors
        ]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 取得製程加工單價（bom_ing_transfer_log）────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'get_process_price') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom = trim($_POST['bom'] ?? '');
    if (empty($bom)) { echo json_encode(['success'=>true,'prices'=>[]]); exit; }
    try {
        // 取每個 bom_sn 最新一筆的單價（price 或 modified_unit_price）
        $s = $db->prepare("
            SELECT t1.bom_sn,
                   t1.maker_from,
                   t1.price,
                   t1.modified_unit_price,
                   t1.transfer_date,
                   t1.transfer_no,
                   t1.note
            FROM bom_ing_transfer_log t1
            INNER JOIN (
                SELECT bom_sn, MAX(transfer_id) AS max_id
                FROM bom_ing_transfer_log
                WHERE bom = ?
                GROUP BY bom_sn
            ) t2 ON t1.bom_sn = t2.bom_sn AND t1.transfer_id = t2.max_id
            WHERE t1.bom = ?
            ORDER BY t1.bom_sn ASC
        ");
        $s->execute([$bom, $bom]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'prices'=>$rows]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 搜尋客戶（用於新增料號視窗）────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'search_customer_for_part') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $term = trim($_POST['term'] ?? '');
    if (empty($term)) { echo json_encode(['success'=>true,'customers'=>[]]); exit; }
    try {
        $s = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE (status IS NULL OR status <> 'X') AND (customer_id LIKE ? OR customer LIKE ?) ORDER BY customer ASC LIMIT 20");
        $s->execute(['%'.$term.'%', '%'.$term.'%']);
        echo json_encode(['success'=>true,'customers'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 搜尋廠商（新增BOM用）───────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'search_maker_for_bom') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $term = trim($_POST['term'] ?? '');
    if (empty($term)) { echo json_encode(['success'=>true,'makers'=>[]]); exit; }
    try {
        $s = $db->prepare("
            SELECT maker_id_no, maker_id, factory_address, m_category, m_process_items
            FROM maker_list
            WHERE (status IS NULL OR status <> 'X')
              AND (maker_id_no LIKE ? OR maker_id LIKE ?)
            ORDER BY maker_id_no ASC LIMIT 30
        ");
        $s->execute(['%'.$term.'%', '%'.$term.'%']);
        echo json_encode(['success'=>true,'makers'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 複製BOM製程（新增BOM用）────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'copy_bom_processes') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $term = trim($_POST['term'] ?? '');
    if (empty($term)) { echo json_encode(['success'=>true,'results'=>[]]); exit; }
    try {
        // 搜尋 bom 號碼、料號（bom.d_id）、或客戶名稱
        $s = $db->prepare("SELECT DISTINCT b.bom, b.d_id, b.Client_Name FROM bom b WHERE b.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ? ORDER BY b.bom ASC LIMIT 20");
        $s->execute(['%'.$term.'%', '%'.$term.'%', '%'.$term.'%']);
        $boms = $s->fetchAll(PDO::FETCH_ASSOC);
        $results = [];
        foreach ($boms as $b) {
            $sp = $db->prepare("
                SELECT bi.bom_sn, bi.process_no, pn.ProcessName,
                       bi.maker_id_no, bi.maker_id, bi.ps
                FROM bom_ing bi
                LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                WHERE bi.bom = ?
                GROUP BY bi.bom_sn
                ORDER BY CAST(bi.bom_sn AS UNSIGNED) ASC
            ");
            $sp->execute([$b['bom']]);
            $results[] = ['bom'=>$b['bom'],'d_id'=>$b['d_id'],'client'=>$b['Client_Name'],'processes'=>$sp->fetchAll(PDO::FETCH_ASSOC)];
        }
        echo json_encode(['success'=>true,'results'=>$results,'debug_count'=>count($boms),'debug_term'=>$term]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 搜尋製程 ───────────────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'search_process') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $term = trim($_POST['term'] ?? '');
    try {
        if (is_numeric($term)) {
            $s=$db->prepare("SELECT ProcessNo,ProcessName FROM process_no WHERE ProcessNo LIKE ? OR ProcessName LIKE ? ORDER BY ProcessNo ASC LIMIT 20");
            $s->execute([$term.'%','%'.$term.'%']);
        } else {
            $s=$db->prepare("SELECT ProcessNo,ProcessName FROM process_no WHERE ProcessName LIKE ? ORDER BY ProcessNo ASC LIMIT 20");
            $s->execute(['%'.$term.'%']);
        }
        echo json_encode(['success'=>true,'processes'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 按需撈取子資料 ────────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'get_row_details') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom  = trim($_POST['bom']  ?? '');
    $d_id = trim($_POST['d_id'] ?? '');
    if (empty($bom)) { echo json_encode(['success'=>false]); exit; }
    try {
        $result = ['success'=>true,'shipment_history'=>[],'qq_details'=>[],'ok_details'=>[]];
        if (!empty($d_id)) {
            $s=$db->prepare("SELECT Product_id,Qty,Specification,DATE_FORMAT(Order_date,'%Y-%m-%d') AS formatted_date,DATE_FORMAT(Order_date,'%Y-%m-%d') AS shipment_iso_date FROM is_list WHERE Product_id=? ORDER BY Order_date DESC LIMIT 20");
            $s->execute([$d_id]);
            $result['shipment_history']=$s->fetchAll(PDO::FETCH_ASSOC);
        }
        $s2=$db->prepare("SELECT bi.bom,qc.bom_ing_fid_ref,qc.QC_check,DATE_FORMAT(qc.QC_check_date,'%c/%e') AS qc_date_formatted,qc.QC_check_date,qc.QC_QQ_sqty,qc.QC_ps,bi.bom_sn,pn.ProcessName,bi.QC_ps AS bQC_ps,bi.QC_ps2 AS bQC_ps2 FROM QC_check qc LEFT JOIN bom_ing bi ON qc.bom_ing_fid_ref=bi.bom_ing_fid LEFT JOIN process_no pn ON bi.process_no=pn.ProcessNo WHERE bi.bom=? AND qc.QC_check='QQ' ORDER BY bi.bom_sn DESC,qc.QC_check_date DESC");
        $s2->execute([$bom]); $result['qq_details']=$s2->fetchAll(PDO::FETCH_ASSOC);
        $s3=$db->prepare("SELECT bi.bom,qc.bom_ing_fid_ref,qc.QC_check,qc.QC_check_date,DATE_FORMAT(qc.QC_check_date,'%c/%e') AS qc_date_formatted,qc.QC_ok_sqty,qc.QC_ps_ok,bi.bom_sn,pn.ProcessName,bi.QC_ps AS bQC_ps,bi.QC_ps2 AS bQC_ps2 FROM QC_check qc LEFT JOIN bom_ing bi ON qc.bom_ing_fid_ref=bi.bom_ing_fid LEFT JOIN process_no pn ON bi.process_no=pn.ProcessNo WHERE bi.bom=? AND qc.QC_check='ok' ORDER BY bi.bom_sn DESC,qc.QC_check_date DESC");
        $s3->execute([$bom]); $result['ok_details']=$s3->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result,JSON_UNESCAPED_UNICODE);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── 搜尋料號設定 (d_setting) ─────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'search_d_setting') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $term       = trim($_POST['term'] ?? '');
    $client     = trim($_POST['client'] ?? '');
    $d_setting_id = trim($_POST['d_setting_id'] ?? ''); // 前端傳入的 bom.d_setting_id（數字ID）
    if (empty($term) && empty($d_setting_id)) { echo json_encode(['success'=>true,'results'=>[]]); exit; }
    try {
        $rows = [];

        // ── 策略1：若有 d_setting_id（數字ID），直接精確查詢 ──
        if (!empty($d_setting_id) && is_numeric($d_setting_id)) {
            $s1 = $db->prepare("
                SELECT ds.d_id,
                       COALESCE(ds.D_Setting_Id, CAST(ds.d_id AS CHAR), '') AS display_id,
                       COALESCE(ds.Spec_No,'') AS spec_no,
                       COALESCE(ds.Customer_Id,'') AS customer_id,
                       COALESCE(cl.customer,'') AS customer_name
                FROM d_setting ds
                LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                WHERE ds.d_id = ?
                LIMIT 1
            ");
            $s1->execute([(int)$d_setting_id]);
            $rows = $s1->fetchAll(PDO::FETCH_ASSOC);
        }

        // ── 策略2：用 term 搜尋 D_Setting_Id 或 Spec_No ──
        if (empty($rows) && !empty($term)) {
            $s2 = $db->prepare("
                SELECT ds.d_id,
                       COALESCE(ds.D_Setting_Id, CAST(ds.d_id AS CHAR), '') AS display_id,
                       COALESCE(ds.Drawing_No,'') AS drawing_no,
                       COALESCE(ds.Spec_No,'') AS spec_no,
                       COALESCE(ds.Customer_Id,'') AS customer_id,
                       COALESCE(cl.customer,'') AS customer_name
                FROM d_setting ds
                LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                WHERE ds.D_Setting_Id LIKE ? OR ds.Drawing_No LIKE ? OR ds.Spec_No LIKE ?
                ORDER BY ds.D_Setting_Id ASC LIMIT 30
            ");
            $s2->execute(['%'.$term.'%', '%'.$term.'%', '%'.$term.'%']);
            $rows = $s2->fetchAll(PDO::FETCH_ASSOC);
        }

        // ── 策略3：fallback 用 term 搜尋 CAST(d_id) ──
        if (empty($rows) && !empty($term)) {
            $s3 = $db->prepare("
                SELECT ds.d_id,
                       COALESCE(ds.D_Setting_Id, CAST(ds.d_id AS CHAR), '') AS display_id,
                       COALESCE(ds.Spec_No,'') AS spec_no,
                       COALESCE(ds.Customer_Id,'') AS customer_id,
                       COALESCE(cl.customer,'') AS customer_name
                FROM d_setting ds
                LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                WHERE CAST(ds.d_id AS CHAR) LIKE ?
                ORDER BY ds.d_id ASC LIMIT 30
            ");
            $s3->execute(['%'.$term.'%']);
            $rows = $s3->fetchAll(PDO::FETCH_ASSOC);
        }

        // 標記客戶是否相符，以及料號是否完全精確比對（非 LIKE）
        $exact_term = trim($_POST['term'] ?? '');
        foreach ($rows as &$r) {
            $r['client_match'] = (!empty($client) &&
                (!empty($r['customer_name']) && stripos($r['customer_name'], $client) !== false ||
                 !empty($r['customer_id'])   && stripos($r['customer_id'],   $client) !== false));
            // 只有 D_Setting_Id 完全等於搜尋詞才標記 exact_match（供前端顯示綠底）
            $r['exact_match'] = (!empty($exact_term) && isset($r['display_id']) && $r['display_id'] === $exact_term);
        }
        unset($r);
        echo json_encode(['success'=>true,'results'=>$rows,'debug'=>[
            'term'=>$term, 'd_setting_id'=>$d_setting_id, 'rows_count'=>count($rows)
        ]]);
    } catch(PDOException $e){
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── 綁定料號設定到BOM（快速綁定套用）────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'apply_dsetting_to_bom') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom          = trim($_POST['bom'] ?? '');
    $d_setting_id = trim($_POST['d_setting_id'] ?? '');
    $uid          = $_SESSION['id'] ?? 'system';
    if (empty($bom) || empty($d_setting_id)) {
        echo json_encode(['success'=>false,'message'=>'缺少必要參數']); exit;
    }
    try {
        // 取得 d_setting 資料
        $s = $db->prepare("SELECT ds.d_id, COALESCE(ds.D_Setting_Id, CAST(ds.d_id AS CHAR),'') AS display_id, COALESCE(ds.Customer_Id,'') AS customer_id, COALESCE(cl.customer,'') AS customer_name FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id WHERE ds.d_id = ? LIMIT 1");
        $s->execute([$d_setting_id]);
        $ds = $s->fetch(PDO::FETCH_ASSOC);
        if (!$ds) { echo json_encode(['success'=>false,'message'=>'找不到料號設定 d_setting_id='.$d_setting_id]); exit; }
        // 更新 bom.d_setting_id 和 bom.d_id（顯示料號文字）及 bom.Client_Name (同步客戶)
        $db->prepare("UPDATE bom SET d_setting_id=?, d_id=?, Client_Name=?, Modified_By=? WHERE bom=?")
           ->execute([$d_setting_id, $ds['display_id'], $ds['customer_name'], $uid, $bom]);
        echo json_encode(['success'=>true, 'display_id'=>$ds['display_id'], 'customer_id'=>$ds['customer_id'], 'customer_name'=>$ds['customer_name']]);
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── 新增/更新料號設定（modal_part_setting.php 攔截用）──
else if (isset($_POST['action']) && $_POST['action'] === 'save_part_info') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $d_id        = trim($_POST['d_id'] ?? '');
    $part_no     = trim($_POST['part_no'] ?? '');
    $type        = trim($_POST['type'] ?? 'N');
    $customer_id = trim($_POST['customer_id'] ?? '');
    $revision    = trim($_POST['revision'] ?? '');
    $issue_date  = trim($_POST['issue_date'] ?? '') ?: null;
    $remark      = trim($_POST['remark'] ?? '');
    $uid         = $_SESSION['id'] ?? 'system';
    if (empty($part_no)) { echo json_encode(['success'=>false,'message'=>'料號不可為空']); exit; }
    try {
        if (empty($d_id)) {
            $s = $db->prepare("INSERT INTO d_setting (D_Setting_Id,Type,Customer_Id,Revision,Issue_Date,Remark,Created_By,Modified_By) VALUES (?,?,?,?,?,?,?,?)");
            $s->execute([$part_no,$type,$customer_id?:null,$revision,$issue_date,$remark,$uid,$uid]);
            echo json_encode(['success'=>true,'message'=>'料號新增成功','new_d_id'=>$db->lastInsertId()]);
        } else {
            $s = $db->prepare("UPDATE d_setting SET D_Setting_Id=?,Type=?,Customer_Id=?,Revision=?,Issue_Date=?,Remark=?,Modified_By=? WHERE d_id=?");
            $s->execute([$part_no,$type,$customer_id?:null,$revision,$issue_date,$remark,$uid,$d_id]);
            echo json_encode(['success'=>true,'message'=>'料號更新成功']);
        }
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}
// ── 刪除料號設定（modal_part_setting.php 攔截用）──
else if (isset($_POST['action']) && $_POST['action'] === 'delete_part') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $d_id = trim($_POST['d_id'] ?? '');
    if (empty($d_id)) { echo json_encode(['success'=>false,'message'=>'缺少料號ID']); exit; }
    try {
        $db->prepare("DELETE FROM d_setting WHERE d_id=?")->execute([$d_id]);
        echo json_encode(['success'=>true,'message'=>'料號已刪除']);
    } catch(PDOException $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}
// ── 刪除 BOM ────────────────────────────────────────────────────────────────
// ── 取得所有製程分類 (process_type) ─────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'get_process_types') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    try {
        $rows = $db->query("SELECT process_type_id, process_type FROM process_type ORDER BY process_type_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        // 同時取出目前已儲存的設定
        $saved = $db->query("SELECT param_value FROM system_parameters WHERE param_group = 'OreadyReply_PM' AND param_key = 'pti_filter_buttons' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $savedVal = $saved ? json_decode($saved['param_value'], true) : null;
        echo json_encode(['success'=>true,'process_types'=>$rows,'saved'=>$savedVal]);
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── 儲存PTI篩選按鈕設定 ────────────────────────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'save_pti_filter_setting') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $selected_json = trim($_POST['selected_json'] ?? '');
    if (empty($selected_json)) { echo json_encode(['success'=>false,'message'=>'缺少設定資料']); exit; }
    $decoded = json_decode($selected_json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        echo json_encode(['success'=>false,'message'=>'設定資料格式錯誤']); exit;
    }
    $uid = $_SESSION['id'] ?? 'system';
    try {
        $val = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
                VALUES ('OreadyReply_PM', 'pti_filter_buttons', :val, 'OreadyReply_ForPm_BaseOfTime2.php 頁面 - PTI製程分類篩選按鈕顯示設定，儲存已選取的 process_type_id 清單', :uid, NOW())
                ON DUPLICATE KEY UPDATE param_value = :val2, updated_by = :uid2, updated_at = NOW()";
        $stmt = $db->prepare($sql);
        $stmt->execute([':val'=>$val,':val2'=>$val,':uid'=>$uid,':uid2'=>$uid]);
        echo json_encode(['success'=>true,'message'=>'PTI篩選設定已儲存']);
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── 取得製程類別對應製程設定 ─────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'get_process_type_map') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    try {
        $types     = $db->query("SELECT process_type_id, process_type FROM process_type ORDER BY process_type_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $processes = $db->query("SELECT ProcessNo AS process_no_id, ProcessName FROM process_no ORDER BY ProcessNo ASC")->fetchAll(PDO::FETCH_ASSOC);
        // 若對應表不存在則回傳空陣列
        try {
            $maps = $db->query("SELECT process_type_id, process_no_id FROM process_type_process_map ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e2) {
            $maps = [];
        }
        echo json_encode(['success'=>true,'types'=>$types,'processes'=>$processes,'maps'=>$maps]);
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── 儲存製程類別對應製程設定 ─────────────────────────────────
else if (isset($_POST['action']) && $_POST['action'] === 'save_process_type_map') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $uid     = $_SESSION['id'] ?? null;
    $decoded = json_decode(trim($_POST['map_json'] ?? ''), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        echo json_encode(['success'=>false,'message'=>'格式錯誤']); exit;
    }
    try {
        $db->beginTransaction();
        $db->exec("DELETE FROM process_type_process_map");
        $ins = $db->prepare("INSERT INTO process_type_process_map (process_type_id, process_no_id, sort_order, updated_by) VALUES (?,?,?,?)");
        foreach ($decoded as $i => $row) {
            $ptId = (int)($row['process_type_id'] ?? 0);
            $pnId = (int)($row['process_no_id']   ?? 0);
            if ($ptId && $pnId) $ins->execute([$ptId, $pnId, $i, $uid]);
        }
        $db->commit();
        echo json_encode(['success'=>true]);
    } catch(PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

else if (isset($_POST['action']) && $_POST['action'] === 'delete_bom') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom = trim($_POST['bom'] ?? '');
    $uid = $_SESSION['id'] ?? 'system';
    if (empty($bom)) { echo json_encode(['success'=>false,'message'=>'缺少BOM']); exit; }
    try {
        $db->beginTransaction();
        // 1. 刪除 pm_process_daily_ng（若有）
        $db->prepare("DELETE pdn FROM pm_process_daily_ng pdn
            JOIN pm_process_daily_report pdr ON pdn.report_id = pdr.report_id
            JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
            WHERE bi.bom = ?")->execute([$bom]);
        // 2. 刪除 pm_process_daily_report（若有）
        $db->prepare("DELETE pdr FROM pm_process_daily_report pdr
            JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
            WHERE bi.bom = ?")->execute([$bom]);
        // 3. 刪除 QC_check 相關
        $db->prepare("DELETE qc FROM QC_check qc
            JOIN bom_ing bi ON qc.bom_ing_fid_ref = bi.bom_ing_fid
            WHERE bi.bom = ?")->execute([$bom]);
        // 4. 刪除 bom_ing
        $db->prepare("DELETE FROM bom_ing WHERE bom = ?")->execute([$bom]);
        // 4.5 刪除 bom_order_process_map
        $db->prepare("DELETE FROM bom_order_process_map WHERE bom = ?")->execute([$bom]);
        // 5. 刪除 bom 主檔
        $stmt = $db->prepare("DELETE FROM bom WHERE bom = ?");
        $stmt->execute([$bom]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            echo json_encode(['success'=>false,'message'=>'找不到此 BOM，刪除失敗']);
            exit;
        }
        $db->commit();
        echo json_encode(['success'=>true,'message'=>'BOM 及相關資料已刪除']);
    } catch(PDOException $e){
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

else if (isset($_POST['action']) && $_POST['action'] === 'delete_bom_ing') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom_ing_fid = trim($_POST['bom_ing_fid'] ?? '');
    if (empty($bom_ing_fid)) { echo json_encode(['success'=>false,'message'=>'缺少 bom_ing_fid']); exit; }
    try {
        $info = $db->prepare("SELECT bom FROM bom_ing WHERE bom_ing_fid = ?");
        $info->execute([$bom_ing_fid]);
        $row_info = $info->fetch(PDO::FETCH_ASSOC);
        if (!$row_info) { echo json_encode(['success'=>false,'message'=>'找不到此製程項目']); exit; }
        $cnt = $db->prepare("SELECT COUNT(*) FROM bom_ing WHERE bom = ?");
        $cnt->execute([$row_info['bom']]);
        if ((int)$cnt->fetchColumn() <= 1) { echo json_encode(['success'=>false,'message'=>'無法刪除 BOM 的最後一個製程']); exit; }
        $db->beginTransaction();
        $del = $db->prepare("DELETE FROM bom_ing WHERE bom_ing_fid = ?");
        $del->execute([$bom_ing_fid]);
        if ($del->rowCount() === 0) { $db->rollBack(); echo json_encode(['success'=>false,'message'=>'刪除失敗，找不到該製程']); exit; }
        $db->commit();
        echo json_encode(['success'=>true,'message'=>'製程項目已刪除']);
    } catch(PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// 批次拆分：取得 BOM 各製程批次狀態
// ══════════════════════════════════════════════════════════════
else if (isset($_POST['action']) && $_POST['action'] === 'get_batch_status') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom_val = trim($_POST['bom'] ?? '');
    if (empty($bom_val)) { echo json_encode(['success'=>false,'message'=>'缺少 bom']); exit; }
    try {
        // 所有 bom_ing 含批次欄位
        $stmt = $db->prepare("
            SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, bi.batch_label,
                   bi.sqty, bi.maker_id_no, bi.maker_id, bi.processing_state,
                   bi.is_consumed, bi.outsource_date, bi.return_date, bi.qc_check, bi.ps,
                   pn.ProcessName
            FROM bom_ing bi
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            WHERE bi.bom = ?
            ORDER BY CAST(bi.bom_sn AS UNSIGNED) ASC,
                     COALESCE(bi.batch_label,'~') ASC,
                     bi.bom_ing_fid ASC
        ");
        $stmt->execute([$bom_val]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // lineage 事件
        $stmt_ev = $db->prepare("
            SELECT e.event_id, e.bom_ing_fid AS from_fid,
                   e.related_bom_ing_fid AS to_fid,
                   e.affected_qty AS transfer_qty, e.event_type
            FROM bom_ing_event e
            INNER JOIN bom_ing bi ON bi.bom_ing_fid = e.bom_ing_fid
            WHERE bi.bom = ?
            ORDER BY e.event_id ASC
        ");
        $stmt_ev->execute([$bom_val]);
        $events = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);

        // BOM 總數
        $stmt_sq = $db->prepare("SELECT sqty FROM bom WHERE bom=?");
        $stmt_sq->execute([$bom_val]);
        $bom_sqty = (int)($stmt_sq->fetchColumn() ?: 0);

        // 按 bom_sn 分組
        $groups = [];
        foreach ($rows as $r) {
            $sn = (int)$r['bom_sn'];
            if (!isset($groups[$sn])) $groups[$sn] = [];
            $groups[$sn][] = $r;
        }
        ksort($groups);

        // 廠商清單（供前端搜尋）
        $makers = $db->query("SELECT maker_id_no, maker_id FROM maker_list WHERE maker_id_no IS NOT NULL ORDER BY maker_id ASC")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'  => true,
            'bom_sqty' => $bom_sqty,
            'groups'   => $groups,
            'events'   => $events,
            'makers'   => $makers,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// 批次拆分：執行批次操作（拆分 / 合併 / 繼續）
// ══════════════════════════════════════════════════════════════
else if (isset($_POST['action']) && $_POST['action'] === 'do_batch_operation') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }

    $bom_val = trim($_POST['bom']    ?? '');
    $bom_sn  = (int)($_POST['bom_sn'] ?? 0);
    $uid     = $_SESSION['id'] ?? 'system';
    // targets: [{qty, maker_id_no, maker_id, note, sources:[{from_fid, transfer_qty}]}]
    $targets = json_decode($_POST['targets'] ?? '[]', true) ?: [];

    if (empty($bom_val) || $bom_sn <= 0 || empty($targets)) {
        echo json_encode(['success'=>false,'message'=>'缺少必要參數']); exit;
    }
    try {
        $db->beginTransaction();

        // 取本 bom_sn 現有未消耗的模板（取 process_no / processing_sequence）
        $stmt_tpl = $db->prepare("
            SELECT * FROM bom_ing
            WHERE bom=? AND bom_sn=? AND is_consumed=0
            ORDER BY bom_ing_fid ASC
        ");
        $stmt_tpl->execute([$bom_val, $bom_sn]);
        $templates = $stmt_tpl->fetchAll(PDO::FETCH_ASSOC);
        if (empty($templates)) {
            $db->rollBack();
            echo json_encode(['success'=>false,'message'=>'找不到此製程序號的未消耗記錄']); exit;
        }
        // 限制：Q 狀態且 QC 尚未完成的批次不允許執行拆分/合併操作
        foreach ($templates as $tpl) {
            if (($tpl['processing_state'] ?? '') === 'Q' && empty($tpl['qc_completed'])) {
                $db->rollBack();
                $lbl = !empty($tpl['batch_label']) ? '批次 '.$tpl['batch_label'] : '此製程';
                echo json_encode(['success'=>false,'message'=>$lbl.' 正在 QC 待驗中（尚未完成 QC 檢驗），無法執行批次操作。請先完成 QC 驗收後再試。']); exit;
            }
        }
        $process_no     = $templates[0]['process_no'];
        $processing_seq = $templates[0]['processing_sequence'];

        // 驗證：各來源分配總量 = 來源批次實際數量
        $source_totals = [];
        foreach ($targets as $t) {
            foreach (($t['sources'] ?? []) as $s) {
                $fid = (int)$s['from_fid'];
                $source_totals[$fid] = ($source_totals[$fid] ?? 0) + (int)$s['transfer_qty'];
            }
        }
        if (!empty($source_totals)) {
            $fids = array_keys($source_totals);
            $ph   = implode(',', array_fill(0, count($fids), '?'));
            $stmt_src = $db->prepare("SELECT bom_ing_fid, sqty, processing_state, qc_completed, batch_label FROM bom_ing WHERE bom_ing_fid IN ($ph) AND is_consumed=0");
            $stmt_src->execute($fids);
            $src_rows_full = $stmt_src->fetchAll(PDO::FETCH_ASSOC);
            $src_rows = array_column($src_rows_full, 'sqty', 'bom_ing_fid');
            foreach ($src_rows_full as $src) {
                if (($src['processing_state'] ?? '') === 'Q' && empty($src['qc_completed'])) {
                    $db->rollBack();
                    $lbl = !empty($src['batch_label']) ? '來源批次 '.$src['batch_label'] : '來源批次';
                    echo json_encode(['success'=>false,'message'=>$lbl.' 正在 QC 待驗中（尚未完成 QC 檢驗），無法作為來源執行批次操作。']); exit;
                }
            }
            foreach ($source_totals as $fid => $alloc) {
                $actual = (int)($src_rows[$fid] ?? 0);
                if ($actual === 0) {
                    $db->rollBack();
                    echo json_encode(['success'=>false,'message'=>"來源批次 fid={$fid} 不存在或已消耗"]); exit;
                }
                if ($alloc !== $actual) {
                    $db->rollBack();
                    echo json_encode(['success'=>false,'message'=>"來源批次 fid={$fid}：已分配 {$alloc}，但批次數量為 {$actual}，請確認總量一致"]); exit;
                }
            }
        }

        // 標記所有模板為已消耗
        foreach ($templates as $tpl) {
            $db->prepare("UPDATE bom_ing SET is_consumed=1, Modified_By=?, Modified_At=NOW() WHERE bom_ing_fid=?")
               ->execute([$uid, $tpl['bom_ing_fid']]);
        }
        // 若有來源批次（跨 bom_sn），也標記為已消耗
        foreach (array_keys($source_totals) as $fid) {
            $db->prepare("UPDATE bom_ing SET is_consumed=1, Modified_By=?, Modified_At=NOW() WHERE bom_ing_fid=?")
               ->execute([$uid, $fid]);
        }

        // 建立新批次 bom_ing
        $labels  = array_merge(range('A','Z'), ['AA','AB','AC','AD','AE','AF']);
        $new_fids = [];
        $ins = $db->prepare("INSERT INTO bom_ing
            (bom_ing_id, bom, process_no, bom_sn, processing_sequence, sqty,
             processing_state, maker_id_no, maker_id, batch_label, is_consumed, ps, Created_By, Modified_By)
            VALUES (?, ?, ?, ?, ?, ?, 'N', ?, ?, ?, 0, ?, ?, ?)");

        foreach ($targets as $idx => $t) {
            $label  = $labels[$idx] ?? ('X'.$idx);
            $qty    = (int)$t['qty'];
            $mkr_no = $t['maker_id_no'] ?: null;
            $mkr_id = $t['maker_id']    ?: null;
            $note   = $t['note']        ?: null;
            $iid    = substr($bom_val,-9).'-'.$process_no.'-'.$bom_sn.'-'.$qty.'-'.$label;
            $ins->execute([$iid, $bom_val, $process_no, $bom_sn, $processing_seq,
                           $qty, $mkr_no, $mkr_id, $label, $note, $uid, $uid]);
            $new_fids[$idx] = (int)$db->lastInsertId();
        }

        // 寫入 bom_ing_event lineage
        $ins_ev = $db->prepare("INSERT INTO bom_ing_event
            (bom_ing_fid, related_bom_ing_fid, event_type, affected_qty, target_maker_id, event_note, Created_By)
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($targets as $idx => $t) {
            $to_fid   = $new_fids[$idx];
            $n_src    = count($t['sources'] ?? []);
            foreach (($t['sources'] ?? []) as $s) {
                $from_fid = (int)$s['from_fid'];
                $qty      = (int)$s['transfer_qty'];
                // 計算此來源派給幾個目標
                $n_targets_for_src = 0;
                foreach ($targets as $t2) {
                    foreach (($t2['sources'] ?? []) as $s2) {
                        if ((int)$s2['from_fid'] === $from_fid) $n_targets_for_src++;
                    }
                }
                if ($n_src > 1)             $type = 'merge';
                elseif ($n_targets_for_src > 1) $type = 'split';
                else                         $type = 'continue';

                $ins_ev->execute([$from_fid, $to_fid, $type, $qty, $t['maker_id'] ?: null, $t['note'] ?: null, $uid]);
            }
        }

        $db->commit();
        echo json_encode(['success'=>true, 'message'=>'批次設定完成', 'new_fids'=>array_values($new_fids)]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// 方案一：取得 BOM 所有製程歷史工時加總（緩衝比計算用）
// ══════════════════════════════════════════════════════════════
else if (isset($_POST['action']) && $_POST['action'] === 'get_bom_buffer_worktime') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom_val = trim($_POST['bom'] ?? '');
    if (empty($bom_val)) { echo json_encode(['success'=>false,'message'=>'缺少 bom']); exit; }
    try {
        $stmt_procs = $db->prepare("
            SELECT bi.bom_sn, bi.process_no, bi.processing_state,
                   pn.process_type_id, pn.ProcessName,
                   b.d_setting_id, ml.maker_id, ml.internal
            FROM bom_ing bi
            JOIN bom b ON bi.bom = b.bom
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
            WHERE bi.bom = ? ORDER BY bi.bom_sn ASC
        ");
        $stmt_procs->execute([$bom_val]);
        $procs = $stmt_procs->fetchAll(PDO::FETCH_ASSOC);
        if (empty($procs)) { echo json_encode(['success'=>false,'message'=>'找不到製程資料']); exit; }
        $d_setting_id = $procs[0]['d_setting_id'] ?? null;
        $avg_cache = [];
        if ($d_setting_id) {
            $stmt_avg = $db->prepare("SELECT process_type_id, avg_min_per_pc, sample_count FROM kpi_avg_time_cache WHERE d_setting_id = ?");
            $stmt_avg->execute([$d_setting_id]);
            foreach ($stmt_avg->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $avg_cache[$row['process_type_id']] = $row;
            }
        }
        $std_cache = [];
        $stmt_std = $db->query("
            SELECT kstd.group_id, kstd.base_time_sec, pn.process_type_id
            FROM kpi_std_time_default kstd
            JOIN kpi_process_group_map kpgm ON kstd.group_id = kpgm.group_id
            JOIN process_no pn ON kpgm.process_no = pn.ProcessNo
        ");
        foreach ($stmt_std->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pt = $row['process_type_id'];
            if (!isset($std_cache[$pt]) || $row['base_time_sec'] > $std_cache[$pt]) {
                $std_cache[$pt] = (float)$row['base_time_sec'];
            }
        }
        $setting_process_days = 3;
        $sp = $db->query("SELECT param_value FROM system_parameters WHERE param_group='BOM_SETTING' AND param_key='process_day' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($sp) {
            $spv = json_decode($sp['param_value'], true);
            $setting_process_days = floatval($spv['day'] ?? $spv['days'] ?? $spv['process'] ?? 3);
        }
        $MINUTES_PER_DAY = 480;
        $process_list = [];
        $total_opt = $total_norm = $total_pess = 0;
        $completed_states = ['E', '1'];
        $fallback_used = false;
        foreach ($procs as $p) {
            $pt_id = $p['process_type_id'];
            $is_done = in_array($p['processing_state'], $completed_states);
            $is_outsource = (isset($p['internal']) && $p['internal'] != 1);
            $days_norm = null; $source = '';
            if ($pt_id && isset($avg_cache[$pt_id]) && $avg_cache[$pt_id]['sample_count'] >= 3) {
                $days_norm = floatval($avg_cache[$pt_id]['avg_min_per_pc']) / $MINUTES_PER_DAY;
                $source = 'history';
            } elseif ($pt_id && isset($std_cache[$pt_id])) {
                $days_norm = ($std_cache[$pt_id] / 60) / $MINUTES_PER_DAY;
                $source = 'std_default'; $fallback_used = true;
            } else {
                $days_norm = $setting_process_days;
                $source = 'setting_days'; $fallback_used = true;
            }
            $days_opt  = round($days_norm * 0.8, 1);
            $days_pess = round($days_norm * 1.5, 1);
            $days_norm = round($days_norm, 1);
            $process_list[] = [
                'bom_sn'=>$p['bom_sn'], 'ProcessName'=>$p['ProcessName']??'(未知)',
                'is_outsource'=>$is_outsource, 'is_done'=>$is_done,
                'days_optimistic'=>$days_opt, 'days_normal'=>$days_norm,
                'days_pessimistic'=>$days_pess, 'source'=>$source,
                'sample_count'=>($source==='history')?$avg_cache[$pt_id]['sample_count']:0,
            ];
            if (!$is_done) { $total_opt += $days_opt; $total_norm += $days_norm; $total_pess += $days_pess; }
        }
        echo json_encode([
            'success'=>true, 'bom'=>$bom_val, 'process_list'=>$process_list,
            'total_remain_optimistic'=>round($total_opt,1),
            'total_remain_normal'=>round($total_norm,1),
            'total_remain_pessimistic'=>round($total_pess,1),
            'total_process_count'=>count($procs),
            'fallback_used'=>$fallback_used,
        ]);
    } catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ══════════════════════════════════════════════════════════════
// 方案二：BOM 衝擊評分
// ══════════════════════════════════════════════════════════════
else if (isset($_POST['action']) && $_POST['action'] === 'get_bom_impact_score') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom_val    = trim($_POST['bom'] ?? '');
    $proc_count = intval($_POST['process_count'] ?? 0);
    if (empty($bom_val)) { echo json_encode(['success'=>false,'message'=>'缺少 bom']); exit; }
    try {
        // 1. 從 bom 取得 d_setting_id（不信任前端傳入）
        $b_row = $db->prepare("SELECT d_setting_id FROM bom WHERE bom = ? LIMIT 1");
        $b_row->execute([$bom_val]);
        $b_data = $b_row->fetch(PDO::FETCH_ASSOC);
        $d_setting_id = $b_data ? intval($b_data['d_setting_id']) : 0;

        // 2. 讀取例外內製製程設定
        $exception_pt_row = $db->query("SELECT param_value FROM system_parameters WHERE param_group='BOM_SETTING' AND param_key='internal_process_types' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $exception_pt_ids = [];
        if ($exception_pt_row && !empty($exception_pt_row['param_value'])) {
            $decoded_ep = json_decode($exception_pt_row['param_value'], true);
            if (is_array($decoded_ep)) $exception_pt_ids = array_map('intval', $decoded_ep);
        }

        // 3. 取所有製程
        $stmt_all = $db->prepare("
            SELECT bi.bom_ing_fid, bi.process_no, bi.maker_id_no,
                   ml.internal, pn.process_type_id
            FROM bom_ing bi
            LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            WHERE bi.bom = ?
        ");
        $stmt_all->execute([$bom_val]);
        $all_procs = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
        $total = count($all_procs);
        $outsource_count = 0; $pt_ids = [];
        foreach ($all_procs as $p) {
            $is_internal_maker   = isset($p['internal']) && $p['internal'] == 1;
            $is_exception_proc   = $p['process_type_id'] && in_array((int)$p['process_type_id'], $exception_pt_ids);
            $is_internal = $is_internal_maker || $is_exception_proc;
            if (!$is_internal) $outsource_count++;
            if ($p['process_type_id']) $pt_ids[] = $p['process_type_id'];
        }
        $outsource_pct = $total > 0 ? round($outsource_count / $total * 100) : 0;

        // 4. 產能排擠：機台有指派用機台，沒指派用製程類型
        //    排除已結案 BOM(bom.processing_state='1') 及當前 BOM 本身
        $queue_detail = [];
        $max_queue = 0;

        // 先取此 BOM 廠內製程的機台 ID 和 process_type_id
        $my_machines = [];
        $my_pt_ids_internal = [];
        foreach ($all_procs as $p) {
            $is_internal_m = isset($p['internal']) && $p['internal'] == 1;
            $is_exception_p = $p['process_type_id'] && in_array((int)$p['process_type_id'], $exception_pt_ids);
            if ($is_internal_m || $is_exception_p) {
                if (!empty($p['machine_id'])) $my_machines[] = (int)$p['machine_id'];
                if (!empty($p['process_type_id'])) $my_pt_ids_internal[] = (int)$p['process_type_id'];
            }
        }
        $my_machines    = array_unique($my_machines);
        $my_pt_ids_internal = array_unique($my_pt_ids_internal);

        // A. 機台有指派：按機台統計
        if (!empty($my_machines)) {
            $ph_m = implode(',', array_fill(0, count($my_machines), '?'));
            $stmt_qm = $db->prepare("
                SELECT bi2.machine_id, COUNT(DISTINCT bi2.bom) AS queue_count
                FROM bom_ing bi2
                JOIN bom b2 ON bi2.bom = b2.bom
                WHERE bi2.processing_state = 'ing'
                  AND bi2.return_date IS NULL
                  AND b2.processing_state IS NULL
                  AND bi2.bom <> ?
                  AND bi2.machine_id IN ($ph_m)
                GROUP BY bi2.machine_id
            ");
            $stmt_qm->execute(array_merge([$bom_val], $my_machines));
            foreach ($stmt_qm->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $queue_detail[] = [
                    'type' => 'machine',
                    'machine_id'  => (int)$r['machine_id'],
                    'queue_count' => (int)$r['queue_count'],
                ];
                $max_queue = max($max_queue, (int)$r['queue_count']);
            }
        }

        // B. 機台未指派：按製程類型統計（排除已用機台對應的製程）
        $pt_ids_no_machine = array_unique(array_filter($my_pt_ids_internal, function($pt) use ($all_procs, $my_machines) {
            foreach ($all_procs as $p) {
                if ((int)($p['process_type_id'] ?? 0) === $pt && !empty($p['machine_id'])) return false;
            }
            return true;
        }));
        if (!empty($pt_ids_no_machine)) {
            $ph_pt = implode(',', array_fill(0, count($pt_ids_no_machine), '?'));
            $stmt_qpt = $db->prepare("
                SELECT pn2.process_type_id, COUNT(DISTINCT bi2.bom) AS queue_count
                FROM bom_ing bi2
                JOIN bom b2 ON bi2.bom = b2.bom
                JOIN process_no pn2 ON bi2.process_no = pn2.ProcessNo
                WHERE bi2.processing_state = 'ing'
                  AND bi2.return_date IS NULL
                  AND b2.processing_state IS NULL
                  AND bi2.bom <> ?
                  AND bi2.machine_id IS NULL
                  AND pn2.process_type_id IN ($ph_pt)
                GROUP BY pn2.process_type_id
            ");
            $stmt_qpt->execute(array_merge([$bom_val], $pt_ids_no_machine));
            foreach ($stmt_qpt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $queue_detail[] = [
                    'type' => 'process_type',
                    'process_type_id' => (int)$r['process_type_id'],
                    'queue_count'     => (int)$r['queue_count'],
                ];
                $max_queue = max($max_queue, (int)$r['queue_count']);
            }
        }
        usort($queue_detail, fn($a,$b) => $b['queue_count'] - $a['queue_count']);

        // 5. 瓶頸製程佔比
        $bottleneck_count = 0;
        if ($d_setting_id && !empty($pt_ids)) {
            $unique_pt = array_unique($pt_ids);
            $ph = implode(',', array_fill(0, count($unique_pt), '?'));
            $stmt_p75 = $db->prepare("SELECT process_type_id, AVG(avg_min_per_pc) as avg_val, STDDEV(avg_min_per_pc) as std_val FROM kpi_avg_time_cache WHERE process_type_id IN ($ph) GROUP BY process_type_id");
            $stmt_p75->execute($unique_pt);
            $p75_map = [];
            foreach ($stmt_p75->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p75_map[$r['process_type_id']] = floatval($r['avg_val']) + 0.675 * floatval($r['std_val']);
            }
            $stmt_this = $db->prepare("SELECT process_type_id, avg_min_per_pc FROM kpi_avg_time_cache WHERE d_setting_id = ? AND process_type_id IN ($ph)");
            $stmt_this->execute(array_merge([$d_setting_id], $unique_pt));
            $this_cache = [];
            foreach ($stmt_this->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $this_cache[$r['process_type_id']] = floatval($r['avg_min_per_pc']);
            }
            foreach ($all_procs as $p) {
                $pt = $p['process_type_id'];
                if ($pt && isset($this_cache[$pt]) && isset($p75_map[$pt]) && $this_cache[$pt] > $p75_map[$pt]) $bottleneck_count++;
            }
        }
        $bottleneck_pct = $total > 0 ? round($bottleneck_count / $total * 100) : 0;

        // 6. 歷史完工中位數
        $hist_days_median = null; $hist_sample = 0;
        if ($d_setting_id) {
            $proc_min = max(1, $proc_count - 2); $proc_max = $proc_count + 2;
            $stmt_hist = $db->prepare("
                SELECT b.bom, DATEDIFF(b.Modified_At, ot.Order_date) AS lead_days, COUNT(bi2.bom_ing_fid) AS proc_n
                FROM bom b
                JOIN bom_order_process_map bopm ON bopm.bom = b.bom
                JOIN order_track ot ON ot.Order_id = bopm.order_id
                JOIN bom_ing bi2 ON bi2.bom = b.bom
                WHERE b.d_setting_id = ? AND b.processing_state = '1' AND b.bom <> ?
                GROUP BY b.bom, ot.Order_date, b.Modified_At
                HAVING proc_n BETWEEN ? AND ?
                ORDER BY b.Modified_At DESC LIMIT 20
            ");
            $stmt_hist->execute([$d_setting_id, $bom_val, $proc_min, $proc_max]);
            $hist_rows = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
            $hist_sample = count($hist_rows);
            if ($hist_sample > 0) {
                $days_arr = array_map(fn($r) => max(0, (int)$r['lead_days']), $hist_rows);
                sort($days_arr);
                $mid = floor($hist_sample / 2);
                $hist_days_median = ($hist_sample % 2 === 0) ? round(($days_arr[$mid-1]+$days_arr[$mid])/2) : $days_arr[$mid];
            }
        }

        // 7. 風險評分
        $risk_score = 0;
        if ($outsource_pct >= 60) $risk_score += 3; elseif ($outsource_pct >= 40) $risk_score += 2; elseif ($outsource_pct >= 20) $risk_score += 1;
        if ($bottleneck_pct >= 30) $risk_score += 3; elseif ($bottleneck_pct >= 15) $risk_score += 2; elseif ($bottleneck_pct > 0) $risk_score += 1;
        if ($max_queue >= 5) $risk_score += 2; elseif ($max_queue >= 3) $risk_score += 1;
        $score_level = $risk_score >= 5 ? 'high' : ($risk_score >= 2 ? 'medium' : 'low');

        echo json_encode([
            'success'=>true, 'outsource_pct'=>$outsource_pct, 'bottleneck_pct'=>$bottleneck_pct,
            'hist_days_median'=>$hist_days_median, 'hist_sample'=>$hist_sample,
            'risk_score'=>$risk_score, 'score_level'=>$score_level,
            'max_queue'=>$max_queue, 'queue_detail'=>$queue_detail,
            'exception_pt_ids'=>$exception_pt_ids,
        ]);
    } catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ══════════════════════════════════════════════════════════════
// 例外內製製程設定 (save + get)
// ══════════════════════════════════════════════════════════════
else if (isset($_POST['action']) && $_POST['action'] === 'save_internal_process_types') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $selected_json = trim($_POST['selected_json'] ?? '[]');
    $selected = json_decode($selected_json, true);
    if (!is_array($selected)) $selected = [];
    $selected = array_map('intval', $selected);
    try {
        $val = json_encode($selected);
        $stmt = $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description)
            VALUES ('BOM_SETTING', 'internal_process_types', :val, '例外內製製程設定')
            ON DUPLICATE KEY UPDATE param_value = :val2");
        $stmt->execute([':val'=>$val, ':val2'=>$val]);
        echo json_encode(['success'=>true,'saved'=>$selected]);
    } catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}
else if (isset($_POST['action']) && $_POST['action'] === 'get_internal_process_types') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    try {
        $row = $db->query("SELECT param_value FROM system_parameters WHERE param_group='BOM_SETTING' AND param_key='internal_process_types' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $saved = [];
        if ($row && !empty($row['param_value'])) {
            $decoded = json_decode($row['param_value'], true);
            if (is_array($decoded)) $saved = array_map('intval', $decoded);
        }
        echo json_encode(['success'=>true,'saved'=>$saved]);
    } catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ══════════════════════════════════════════════════════════════
// 方案四：外包回廠預測
// ══════════════════════════════════════════════════════════════
else if (isset($_POST['action']) && $_POST['action'] === 'get_outsource_predict') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom_val = trim($_POST['bom'] ?? '');
    if (empty($bom_val)) { echo json_encode(['success'=>false,'message'=>'缺少 bom']); exit; }
    try {
        $stmt_current = $db->prepare("
            SELECT bi.bom_ing_fid, bi.bom_sn, bi.maker_id_no, bi.process_no,
                   bi.outsource_date, bi.return_date, bi.processing_state,
                   ml.maker_id, ml.internal, pn.ProcessName, pn.process_type_id
            FROM bom_ing bi
            LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            WHERE bi.bom = ? AND (ml.internal IS NULL OR ml.internal != 1)
            ORDER BY bi.bom_sn ASC
        ");
        $stmt_current->execute([$bom_val]);
        $current_procs = $stmt_current->fetchAll(PDO::FETCH_ASSOC);
        if (empty($current_procs)) { echo json_encode(['success'=>true,'data'=>[],'message'=>'無外包製程']); exit; }
        $results = [];
        foreach ($current_procs as $p) {
            $maker_id_no = $p['maker_id_no']; $pt_id = $p['process_type_id'];
            $hist_stat = ['avg_days'=>null,'min_days'=>null,'max_days'=>null,'p80_days'=>null,'sample_n'=>0];
            if ($maker_id_no && $pt_id) {
                $stmt_hist = $db->prepare("
                    SELECT DATEDIFF(bi2.return_date, bi2.outsource_date) AS turnaround_days
                    FROM bom_ing bi2 JOIN process_no pn2 ON bi2.process_no = pn2.ProcessNo
                    WHERE bi2.maker_id_no = ? AND pn2.process_type_id = ?
                      AND bi2.return_date IS NOT NULL AND bi2.outsource_date IS NOT NULL
                      AND DATEDIFF(bi2.return_date, bi2.outsource_date) > 0
                      AND DATEDIFF(bi2.return_date, bi2.outsource_date) < 180
                    ORDER BY bi2.return_date DESC LIMIT 50
                ");
                $stmt_hist->execute([$maker_id_no, $pt_id]);
                $hist_days = $stmt_hist->fetchAll(PDO::FETCH_COLUMN);
                $n = count($hist_days);
                if ($n >= 3) {
                    sort($hist_days);
                    $p80_idx = (int)ceil($n * 0.8) - 1;
                    $hist_stat = ['avg_days'=>round(array_sum($hist_days)/$n,1),'min_days'=>$hist_days[0],'max_days'=>$hist_days[$n-1],'p80_days'=>$hist_days[$p80_idx],'sample_n'=>$n];
                } elseif ($n > 0) {
                    sort($hist_days);
                    $hist_stat = ['avg_days'=>round(array_sum($hist_days)/$n,1),'min_days'=>$hist_days[0],'max_days'=>$hist_days[$n-1],'p80_days'=>$hist_days[$n-1],'sample_n'=>$n];
                }
            }
            $results[] = [
                'bom_sn'=>$p['bom_sn'], 'ProcessName'=>$p['ProcessName']??'(未知)',
                'maker_id'=>$p['maker_id']??$maker_id_no, 'maker_id_no'=>$maker_id_no,
                'outsource_date'=>$p['outsource_date']?date('Y/m/d',strtotime($p['outsource_date'])):null,
                'return_date'=>$p['return_date']?date('Y/m/d',strtotime($p['return_date'])):null,
                'is_returned'=>!empty($p['return_date']),
                'processing_state'=>$p['processing_state'], 'hist'=>$hist_stat,
            ];
        }
        echo json_encode(['success'=>true,'data'=>$results]);
    } catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ══════════════════════════════════════════════════════════════
// 急單歷史等級查詢（生管 BOM 頁用）
// ══════════════════════════════════════════════════════════════
else if (isset($_POST['action']) && $_POST['action'] === 'get_order_urgent_level') {
    session_write_close();
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $bom_val = trim($_POST['bom'] ?? '');
    if (empty($bom_val)) { echo json_encode(['success'=>false,'message'=>'缺少 bom']); exit; }
    try {
        $stmt_bom = $db->prepare("
            SELECT b.d_setting_id, b.Delivery_date, ot.Order_date
            FROM bom b
            LEFT JOIN bom_order_process_map bopm ON bopm.bom = b.bom
            LEFT JOIN order_track ot ON ot.Order_id = bopm.order_id
            WHERE b.bom = ? LIMIT 1
        ");
        $stmt_bom->execute([$bom_val]);
        $bom_row = $stmt_bom->fetch(PDO::FETCH_ASSOC);
        if (!$bom_row || !$bom_row['d_setting_id']) {
            echo json_encode(['success'=>true,'level'=>'unknown','avg_days'=>null,'sample_n'=>0]); exit;
        }
        $d_setting_id = (int)$bom_row['d_setting_id'];
        $delivery_date = $bom_row['Delivery_date'];
        $stmt_pc = $db->prepare("SELECT COUNT(*) AS n FROM bom_ing WHERE bom = ?");
        $stmt_pc->execute([$bom_val]);
        $proc_count = (int)($stmt_pc->fetchColumn() ?: 0);
        $proc_min = max(1, $proc_count - 2); $proc_max = max($proc_count + 2, 5);
        $stmt_h = $db->prepare("
            SELECT ROUND(AVG(lead_days)) AS avg_days, COUNT(*) AS n
            FROM (
                SELECT DATEDIFF(b2.Modified_At, ot2.Order_date) AS lead_days
                FROM bom b2
                JOIN bom_order_process_map bopm2 ON bopm2.bom = b2.bom
                JOIN order_track ot2 ON ot2.Order_id = bopm2.order_id
                JOIN (SELECT bom, COUNT(*) AS cnt FROM bom_ing GROUP BY bom) pc ON pc.bom = b2.bom
                WHERE b2.d_setting_id = ? AND b2.processing_state = '1' AND b2.bom <> ?
                  AND ot2.Order_date IS NOT NULL
                  AND DATEDIFF(b2.Modified_At, ot2.Order_date) BETWEEN 1 AND 365
                  AND pc.cnt BETWEEN ? AND ?
                ORDER BY b2.Modified_At DESC LIMIT 10
            ) sub
        ");
        $stmt_h->execute([$d_setting_id, $bom_val, $proc_min, $proc_max]);
        $hist = $stmt_h->fetch(PDO::FETCH_ASSOC);
        if (!$hist || $hist['n'] < 2) {
            $stmt_o = $db->prepare("
                SELECT ROUND(AVG(lead_days)) AS avg_days, COUNT(*) AS n
                FROM (
                    SELECT DATEDIFF(b2.Modified_At, ot2.Order_date) AS lead_days
                    FROM bom b2
                    JOIN bom_order_process_map bopm2 ON bopm2.bom = b2.bom
                    JOIN order_track ot2 ON ot2.Order_id = bopm2.order_id
                    WHERE b2.d_setting_id = ? AND b2.processing_state = '1' AND b2.bom <> ?
                      AND ot2.Order_date IS NOT NULL
                      AND DATEDIFF(b2.Modified_At, ot2.Order_date) BETWEEN 1 AND 365
                    ORDER BY b2.Modified_At DESC LIMIT 15
                ) sub
            ");
            $stmt_o->execute([$d_setting_id, $bom_val]);
            $hist = $stmt_o->fetch(PDO::FETCH_ASSOC);
        }
        $avg_days = ($hist && $hist['n'] >= 1 && $hist['avg_days'] !== null) ? (int)round(floatval($hist['avg_days'])) : null;
        $sample_n = ($hist && $hist['n'] >= 1) ? (int)$hist['n'] : 0;
        $dtd = null;
        if ($delivery_date) {
            $now = new DateTime(); $del = new DateTime($delivery_date);
            $diff = (int)$now->diff($del)->days;
            $dtd = ($now > $del) ? -$diff : $diff;
        }
        $level = 'unknown';
        if ($dtd !== null && $dtd <= 0) $level = 'overdue';
        elseif ($avg_days !== null && $dtd !== null) {
            if ($dtd < $avg_days * 0.8) $level = 'urgent_high';
            elseif ($dtd < $avg_days * 1.2) $level = 'urgent_medium';
            else $level = 'normal';
        }
        echo json_encode(['success'=>true,'level'=>$level,'avg_days'=>$avg_days,'sample_n'=>$sample_n,'days_to_delivery'=>$dtd]);
    } catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}
