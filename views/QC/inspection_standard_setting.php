<?php
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";

// 檢查登入狀態
if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    echo "<script>alert('連線逾時，請重新登入'); window.location.href='../../index.php';</script>";
    exit;
}

$db = new DBConnection();
$pdo = $db->getPDO();

// 自動建立樣板相關資料表 (若不存在)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_inspection_template (
    template_id INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_inspection_template_item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    item_name VARCHAR(255),
    standard_text VARCHAR(255),
    min_value DECIMAL(10,4), max_value DECIMAL(10,4),
    plus_tolerance DECIMAL(10,4), minus_tolerance DECIMAL(10,4),
    result_type VARCHAR(20), tool_ids VARCHAR(255), sort_order INT
)");

// 檢查並新增 sort_order 欄位 (用於量具排序)
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_tool_list LIKE 'sort_order'");
    if ($colChk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE qc_tool_list ADD COLUMN sort_order INT DEFAULT 0");
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 檢查並更新 qc_inspection_form_type 的 inspection_stage 欄位以支援 PKG
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_inspection_form_type LIKE 'inspection_stage'");
    if ($colChk && $colChk->rowCount() > 0) {
        $row = $colChk->fetch(PDO::FETCH_ASSOC);
        if (strpos($row['Type'], "'PKG'") === false) {
            $pdo->exec("ALTER TABLE qc_inspection_form_type MODIFY COLUMN inspection_stage ENUM('IQC','IPQC','FQC','PKG') COMMENT '檢驗階段：IQC=進料檢 IPQC=一般製程檢 FQC=成品檢 PKG=包裝檢'");
        }
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// =============================================================================
// 後端 API 處理區塊 (AJAX)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // 0. 輔助：獲取幾何公差特殊符號 (從 qc_special_characteristic)
    function getSpecialItems($pdo) {
        $stmt = $pdo->query("SELECT characteristic_id as id, name, symbol, description as code FROM qc_special_characteristic WHERE is_active = 1 ORDER BY characteristic_id ASC");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $items;
    }

    // 0. 輔助：獲取量具列表
    function getToolList($pdo) {
        $stmt = $pdo->query("SELECT QC_Tool_List_id, QC_Tool FROM qc_tool_list ORDER BY sort_order ASC, QC_Tool ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // 0. 輔助：獲取檢驗表類型
    function getFormTypes($pdo) {
        $stmt = $pdo->query("SELECT * FROM qc_inspection_form_type WHERE is_active = 1 ORDER BY form_type_id ASC");
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $types;
    }
    
    // 0. 輔助：初始化預設表單類型 (僅在完全無資料時)
    function initDefaultFormTypes($pdo) {
        $types = getFormTypes($pdo);

        // 檢查並補足預設類型
        $defaults = [
            ['GENERAL', '一般進貨檢', '適用於一般零件', 'IPQC'],
            ['FINAL', '成品檢驗', '適用於成品檢驗紀錄', 'FQC'],
            ['IN', '進貨檢', '適用於客供料或是其他進料檢', 'IQC'],
            ['PACKING', '包裝出貨檢', '包裝與標示檢查', 'PKG']
        ];

        $ins = $pdo->prepare("INSERT INTO qc_inspection_form_type (form_code, form_name, description, inspection_stage, is_active) VALUES (?, ?, ?, ?, 1)");
        
        foreach ($defaults as $d) {
            $exists = false;
            foreach ($types as $t) {
                if ($t['form_code'] === $d[0]) $exists = true;
            }
            if (!$exists) {
                $ins->execute($d);
            }
            // 重新讀取
            $types = getFormTypes($pdo);
        }
        return $types;
    }

    // 1. 搜尋料號 (從 d_setting)
    if ($_POST['action'] === 'search_parts') {
        try {
            $keyword = $_POST['keyword'] ?? '';
            $sql = "SELECT d.d_id, d.D_Setting_Id, d.Revision, d.Issue_Date, d.Remark, d.Type, d.Customer_Id, c.customer AS Client_Name 
                    FROM d_setting d
                    LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                    WHERE d.D_Setting_Id LIKE :kw OR c.customer LIKE :kw 
                    ORDER BY d.D_Setting_Id ASC, d.Issue_Date DESC 
                    ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':kw' => "%$keyword%"]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 處理顯示邏輯：若無版次則使用發行日
            foreach ($results as &$row) {
                if (!empty($row['Revision'])) {
                    $row['version_display'] = "Rev. " . $row['Revision'];
                } else {
                    $row['version_display'] = "發行日: " . ($row['Issue_Date'] ? $row['Issue_Date'] : '無日期');
                }
            }
            echo json_encode(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 2. 初始化料號設定資料 (獲取版本、類型、量具、特殊符號)
    if ($_POST['action'] === 'init_part_settings') {
        try {
            $d_id = $_POST['d_id'];
            
            // A. 獲取該料號的所有版本
            $stmt = $pdo->prepare("SELECT * FROM qc_inspection_version WHERE d_id = ? AND is_active = 1 ORDER BY version_id DESC");
            $stmt->execute([$d_id]);
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // B. 獲取檢驗表類型 (車床、一般...)
            $form_types = initDefaultFormTypes($pdo);

            // C. 獲取量具列表
            $tools = getToolList($pdo);

            // D. 獲取特殊符號
            $special_items = getSpecialItems($pdo);

            // E. 獲取齒輪資料 (若有)
            $stmt_gear = $pdo->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id = ? ORDER BY gear_id ASC");
            $stmt_gear->execute([$d_id]);
            $gears = $stmt_gear->fetchAll(PDO::FETCH_ASSOC);
            
            // 任務 3: 讀取資料時去尾數 0
            foreach ($gears as &$g) {
                if (isset($g['Helix_Angle']) && $g['Helix_Angle'] !== null) $g['Helix_Angle'] = (float)$g['Helix_Angle'];
                if (isset($g['Profile_Shift_X']) && $g['Profile_Shift_X'] !== null) $g['Profile_Shift_X'] = (float)$g['Profile_Shift_X'];
            }

            echo json_encode([
                'success' => true, 
                'versions' => $versions,
                'form_types' => $form_types,
                'tools' => $tools,
                'special_items' => $special_items,
                'gears' => $gears
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 2-0. 僅獲取特殊符號 (用於更新快取)
    if ($_POST['action'] === 'get_special_items') {
        echo json_encode(['success' => true, 'special_items' => getSpecialItems($pdo)]);
        exit;
    }

    // 2-0.5 搜尋客戶
    if ($_POST['action'] === 'search_customers') {
        try {
            $kw = $_POST['keyword'] ?? '';
            $stmt = $pdo->prepare("SELECT customer_id, customer FROM customer_list WHERE customer_id LIKE ? OR customer LIKE ? LIMIT 20");
            $stmt->execute(["%$kw%", "%$kw%"]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 2-1. 獲取特定版本與類型的檢驗項目
    if ($_POST['action'] === 'get_version_items') {
        try {
            $version_id = $_POST['version_id'];
            $form_type_id = $_POST['form_type_id'];

            // 改用 PHP 處理聚合，避免 SQL GROUP BY 在某些模式下導致查詢失敗或回傳空值
            $sql = "SELECT i.*, itt.QC_Tool_List_id 
                    FROM qc_inspection_item i
                    LEFT JOIN qc_inspection_item_tool_type itt ON i.item_id = itt.item_id
                    WHERE i.version_id = ? AND i.form_type_id = ?
                    ORDER BY i.sort_order ASC, i.item_id ASC, itt.is_primary DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$version_id, $form_type_id]);
            $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items_map = [];
            foreach ($raw_data as $row) {
                $iid = $row['item_id'];
                if (!isset($items_map[$iid])) {
                    $items_map[$iid] = $row;
                    $items_map[$iid]['tool_ids'] = [];
                    unset($items_map[$iid]['QC_Tool_List_id']); // 移除 join 欄位以免混淆
                }
                if (!empty($row['QC_Tool_List_id'])) {
                    $items_map[$iid]['tool_ids'][] = $row['QC_Tool_List_id'];
                }
            }

            // 轉回前端預期的格式 (tool_ids 為逗號分隔字串)
            $items = [];
            foreach ($items_map as $item) {
                $item['tool_ids'] = !empty($item['tool_ids']) ? implode(',', $item['tool_ids']) : null;

                // 將數值轉為 float 以去除資料庫 DECIMAL 欄位補的 0
                if ($item['min_value'] !== null) $item['min_value'] = (float)$item['min_value'];
                if ($item['max_value'] !== null) $item['max_value'] = (float)$item['max_value'];
                if (isset($item['plus_tolerance']) && $item['plus_tolerance'] !== null) $item['plus_tolerance'] = (float)$item['plus_tolerance'];
                if (isset($item['minus_tolerance']) && $item['minus_tolerance'] !== null) $item['minus_tolerance'] = (float)$item['minus_tolerance'];

                $items[] = $item;
            }

            echo json_encode(['success' => true, 'items' => $items]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 2-2. 建立新版本
    if ($_POST['action'] === 'create_version') {
        try {
            $d_id = $_POST['d_id'];
            $version_label = $_POST['version_label']; // 例如 "Rev 1.0" 或 "2023-10-01"
            $source_type = $_POST['source_type']; // 'REVISION' or 'ISSUE_DATE'

            // 檢查是否重複
            $check = $pdo->prepare("SELECT version_id FROM qc_inspection_version WHERE d_id=? AND version_label=?");
            $check->execute([$d_id, $version_label]);
            if ($check->fetch()) {
                throw new Exception("此版本名稱已存在");
            }

            $sql = "INSERT INTO qc_inspection_version (d_id, version_label, source_type) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$d_id, $version_label, $source_type]);
            
            echo json_encode(['success' => true, 'version_id' => $pdo->lastInsertId(), 'message' => '版本建立成功']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 2-3. 獲取版本啟用的表單 (qc_version_form_map)
    if ($_POST['action'] === 'get_version_form_map') {
        try {
            $version_id = $_POST['version_id'];
            $stmt = $pdo->prepare("SELECT form_type_id FROM qc_version_form_map WHERE version_id = ? AND is_enabled = 1");
            $stmt->execute([$version_id]);
            $enabled_forms = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['success' => true, 'enabled_forms' => $enabled_forms]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 2-4. 切換表單啟用狀態
    if ($_POST['action'] === 'toggle_form_enable') {
        try {
            $version_id = $_POST['version_id'];
            $form_type_id = $_POST['form_type_id'];
            $is_enabled = ($_POST['is_enabled'] === 'true') ? 1 : 0;

            // 使用 ON DUPLICATE KEY UPDATE (MySQL 特性) 或先查後改
            // 這裡使用簡單的先刪除舊設定(若有)再插入，或是 Update/Insert 邏輯
            // 為了兼容性，先檢查是否存在
            $check = $pdo->prepare("SELECT id FROM qc_version_form_map WHERE version_id = ? AND form_type_id = ?");
            $check->execute([$version_id, $form_type_id]);
            $exists = $check->fetch();

            if ($exists) {
                $pdo->prepare("UPDATE qc_version_form_map SET is_enabled = ? WHERE version_id = ? AND form_type_id = ?")
                    ->execute([$is_enabled, $version_id, $form_type_id]);
            } else {
                $pdo->prepare("INSERT INTO qc_version_form_map (version_id, form_type_id, is_enabled) VALUES (?, ?, ?)")
                    ->execute([$version_id, $form_type_id, $is_enabled]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 3. 儲存檢驗項目 (針對特定版本 + 類型)
    if ($_POST['action'] === 'save_items') {
        try {
            $version_id = $_POST['version_id'];
            $form_type_id = $_POST['form_type_id'];
            $items = $_POST['items'] ?? []; // 陣列

            if (empty($version_id) || empty($form_type_id)) throw new Exception("參數不足");

            $pdo->beginTransaction();

            // 策略：先刪除該版本+類型的所有項目，再重新寫入 (全量更新)
            // 1. 先刪除關聯的量具類型 (避免 Foreign Key 錯誤)
            $del_tool_sql = "DELETE t FROM qc_inspection_item_tool_type t 
                             INNER JOIN qc_inspection_item i ON t.item_id = i.item_id 
                             WHERE i.version_id = ? AND i.form_type_id = ?";
            $pdo->prepare($del_tool_sql)->execute([$version_id, $form_type_id]);

            // 2. 刪除項目
            $del_sql = "DELETE FROM qc_inspection_item WHERE version_id = ? AND form_type_id = ?";
            $pdo->prepare($del_sql)->execute([$version_id, $form_type_id]);

            if (!empty($items)) {
                $sql_item = "INSERT INTO qc_inspection_item 
                    (version_id, form_type_id, process_name, item_code, item_name, standard_text, min_value, max_value, plus_tolerance, minus_tolerance, result_type, sort_order, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                $stmt_item = $pdo->prepare($sql_item);
                
                $sql_tool = "INSERT INTO qc_inspection_item_tool_type (item_id, QC_Tool_List_id, is_primary) VALUES (?, ?, ?)";
                $stmt_tool = $pdo->prepare($sql_tool);

                foreach ($items as $idx => $item) {
                    $item_name = trim($item['name']);
                    if (empty($item_name)) continue;
                    
                    // 處理數值 (若為空則存 NULL)
                    $min = ($item['min'] !== '') ? $item['min'] : null;
                    $max = ($item['max'] !== '') ? $item['max'] : null;
                    $plus = (isset($item['plus_tolerance']) && $item['plus_tolerance'] !== '') ? $item['plus_tolerance'] : null;
                    $minus = (isset($item['minus_tolerance']) && $item['minus_tolerance'] !== '') ? $item['minus_tolerance'] : null;
                    // tool_id 可能為陣列或單一值
                    $tool_ids = !empty($item['tool_id']) ? $item['tool_id'] : [];
                    if (!is_array($tool_ids)) {
                        // 若為逗號分隔字串，則轉為陣列
                        $tool_ids = (strpos($tool_ids, ',') !== false) ? explode(',', $tool_ids) : [$tool_ids];
                    }

                    $stmt_item->execute([
                        $version_id,
                        $form_type_id,
                        $item['process_name'] ?? null, // 儲存製程名稱
                        $item['code'],      // A, B, 1, 2...
                        $item_name,
                        $item['standard'] ?? '',
                        $min,
                        $max,
                        $plus,
                        $minus,
                        $item['result_type'], // NUMERIC or OKNG
                        $idx + 1 // sort_order
                    ]);
                    
                    $new_item_id = $pdo->lastInsertId();

                    // 寫入量具關聯
                    foreach ($tool_ids as $t_idx => $tid) {
                        if (empty($tid)) continue;
                        // 假設第一個選擇的是主要量具
                        $is_primary = ($t_idx === 0) ? 1 : 0;
                        $stmt_tool->execute([$new_item_id, $tid, $is_primary]);
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => '儲存成功']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
        }
        exit;
    }

    // 6. 管理幾何公差 (新增/修改)
    if ($_POST['action'] === 'save_special_item') {
        try {
            $id = $_POST['id'] ?? '';
            $name = $_POST['name'] ?? '';
            $symbol = $_POST['symbol'] ?? '';
            $code = $_POST['code'] ?? '';

            if (empty($name)) throw new Exception("名稱為必填");

            if (!empty($id)) {
                // Update
                $stmt = $pdo->prepare("UPDATE qc_special_characteristic SET name=?, symbol=?, description=? WHERE characteristic_id=?");
                $stmt->execute([$name, $symbol, $code, $id]);
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO qc_special_characteristic (name, symbol, description, is_active) VALUES (?, ?, ?, 1)");
                $stmt->execute([$name, $symbol, $code]);
            }
            echo json_encode(['success' => true, 'message' => '儲存成功']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 7. 刪除幾何公差
    if ($_POST['action'] === 'delete_special_item') {
        try {
            $id = $_POST['id'];
            // 這裡使用物理刪除，若需保留歷史可改為 update is_active=0
            $stmt = $pdo->prepare("DELETE FROM qc_special_characteristic WHERE characteristic_id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => '刪除成功']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 4. 儲存/新增料號基本資料 (d_setting)
    if ($_POST['action'] === 'save_part_info') {
        try {
            $d_id = $_POST['d_id'] ?? '';
            $part_no = $_POST['part_no'] ?? '';
            $customer_id = $_POST['customer_id'] ?? null;
            $type = $_POST['type'] ?? 'N'; // 預設一般
            $revision = $_POST['revision'] ?? '';
            $issue_date = !empty($_POST['issue_date']) ? $_POST['issue_date'] : null;
            $remark = $_POST['remark'] ?? '';
            $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 'System'; // 獲取當前使用者
            $copy_source_d_id = $_POST['copy_source_d_id'] ?? '';
            $gears = isset($_POST['gears']) ? json_decode($_POST['gears'], true) : [];

            if (empty($part_no)) throw new Exception("料號 (D_Setting_Id) 為必填");

            $pdo->beginTransaction();

            if (!empty($d_id)) {
                // Update
                // 同步更新 Drawing_No 與 D_Setting_Id，並記錄修改人，更新 Customer_Id 與 Type
                $sql = "UPDATE d_setting SET D_Setting_Id=?, Drawing_No=?, Customer_Id=?, Type=?, Revision=?, Issue_Date=?, Remark=?, Modified_By=?, Modified_At=NOW() WHERE d_id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$part_no, $part_no, $customer_id, $type, $revision, $issue_date, $remark, $user_id, $d_id]);
            } else {
                // Insert
                $sql = "INSERT INTO d_setting (D_Setting_Id, Drawing_No, Customer_Id, Type, Revision, Issue_Date, Remark, Created_By, Created_At) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$part_no, $part_no, $customer_id, $type, $revision, $issue_date, $remark, $user_id]);
                $d_id = $pdo->lastInsertId();

                // 處理複製檢驗標準邏輯
                if (!empty($copy_source_d_id)) {
                    // 1. 獲取來源料號的所有版本
                    $src_vers = $pdo->prepare("SELECT * FROM qc_inspection_version WHERE d_id = ?");
                    $src_vers->execute([$copy_source_d_id]);
                    $versions = $src_vers->fetchAll(PDO::FETCH_ASSOC);

                    $ins_ver = $pdo->prepare("INSERT INTO qc_inspection_version (d_id, version_label, source_type, is_active, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $ins_map = $pdo->prepare("INSERT INTO qc_version_form_map (version_id, form_type_id, is_enabled) VALUES (?, ?, ?)");
                    $ins_item = $pdo->prepare("INSERT INTO qc_inspection_item (version_id, form_type_id, process_name, item_code, item_name, standard_text, min_value, max_value, plus_tolerance, minus_tolerance, result_type, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins_tool = $pdo->prepare("INSERT INTO qc_inspection_item_tool_type (item_id, QC_Tool_List_id, is_primary) VALUES (?, ?, ?)");

                    foreach ($versions as $ver) {
                        // 2. 建立新版本
                        $ins_ver->execute([$d_id, $ver['version_label'], $ver['source_type'], $ver['is_active']]);
                        $new_ver_id = $pdo->lastInsertId();

                        // 3. 複製表單啟用設定
                        $src_map = $pdo->prepare("SELECT * FROM qc_version_form_map WHERE version_id = ?");
                        $src_map->execute([$ver['version_id']]);
                        $maps = $src_map->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($maps as $m) {
                            $ins_map->execute([$new_ver_id, $m['form_type_id'], $m['is_enabled']]);
                        }

                        // 4. 複製檢驗項目
                        $src_items = $pdo->prepare("SELECT * FROM qc_inspection_item WHERE version_id = ?");
                        $src_items->execute([$ver['version_id']]);
                        $items = $src_items->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($items as $item) {
                            $ins_item->execute([
                                $new_ver_id, $item['form_type_id'], $item['process_name'], $item['item_code'], $item['item_name'],
                                $item['standard_text'], $item['min_value'], $item['max_value'], $item['plus_tolerance'], $item['minus_tolerance'],
                                $item['result_type'], $item['sort_order'], $item['is_active']
                            ]);
                            $new_item_id = $pdo->lastInsertId();

                            // 5. 複製量具關聯
                            $src_tools = $pdo->prepare("SELECT * FROM qc_inspection_item_tool_type WHERE item_id = ?");
                            $src_tools->execute([$item['item_id']]);
                            $tools = $src_tools->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($tools as $t) {
                                $ins_tool->execute([$new_item_id, $t['QC_Tool_List_id'], $t['is_primary']]);
                            }
                        }
                    }
                }
            }

            // 處理齒輪資料 (若 Type 為 G)
            // 先刪除舊資料
            $pdo->prepare("DELETE FROM d_setting_gear WHERE d_setting_id = ?")->execute([$d_id]);
            
            if ($type === 'G' && !empty($gears)) {
                // 任務 3: 儲存資料 (包含新欄位)
                $sql_gear = "INSERT INTO d_setting_gear (d_setting_id, Module, Teeth, Face_Width, Helix_Angle, Pressure_Angle, Workpiece_Length, Gear_Type, Spec_No, Remark_Gear, Created_By, Helix_Direction, Profile_Shift_X, Helix_Angle_Str) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_gear = $pdo->prepare($sql_gear);
                foreach ($gears as $g) {
                    // Helper to convert empty string to null
                    $v = function($k) use ($g) { return (isset($g[$k]) && $g[$k] !== '') ? $g[$k] : null; };
                    $stmt_gear->execute([
                        $d_id,
                        $v('Module'), $v('Teeth'), $v('Face_Width'), $v('Helix_Angle'), 
                        $v('Pressure_Angle'), $v('Workpiece_Length'), $v('Gear_Type'), 
                        $v('Spec_No'), $v('Remark_Gear'), $user_id,
                        $v('Helix_Direction'), $v('Profile_Shift_X'), $v('Helix_Angle_Str')
                    ]);
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => '料號資料儲存成功']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
        }
        exit;
    }

    // 5. 刪除料號 (d_setting)
    if ($_POST['action'] === 'delete_part') {
        try {
            $d_id = $_POST['d_id'] ?? '';
            if (empty($d_id)) throw new Exception("未指定 ID");

            // 這裡僅示範刪除 d_setting，實務上可能需檢查關聯或使用 Foreign Key Cascade
            $stmt = $pdo->prepare("DELETE FROM d_setting WHERE d_id = ?");
            $stmt->execute([$d_id]);

            echo json_encode(['success' => true, 'message' => '刪除成功']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => '刪除失敗: ' . $e->getMessage()]);
        }
        exit;
    }

    // 8. 獲取量具管理資料 (種類 + 編號)
    if ($_POST['action'] === 'get_tool_manage_data') {
        try {
            // 獲取所有種類
            $stmt = $pdo->query("SELECT * FROM qc_tool_list ORDER BY sort_order ASC, QC_Tool ASC");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 獲取所有編號
            $stmt = $pdo->query("SELECT * FROM qc_tool ORDER BY Tool_No ASC");
            $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'categories' => $categories, 'tools' => $tools]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 8-1. 更新量具種類排序
    if ($_POST['action'] === 'update_tool_category_order') {
        try {
            $ids = $_POST['ids'] ?? [];
            if (!empty($ids)) {
                $sql = "UPDATE qc_tool_list SET sort_order = ? WHERE QC_Tool_List_id = ?";
                $stmt = $pdo->prepare($sql);
                foreach ($ids as $idx => $id) {
                    $stmt->execute([$idx + 1, $id]);
                }
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 9. 儲存量具種類
    if ($_POST['action'] === 'save_tool_category') {
        try {
            $id = $_POST['id'] ?? '';
            $name = $_POST['name'] ?? '';
            if (empty($name)) throw new Exception("名稱必填");

            if ($id) {
                $pdo->prepare("UPDATE qc_tool_list SET QC_Tool = ? WHERE QC_Tool_List_id = ?")->execute([$name, $id]);
            } else {
                $pdo->prepare("INSERT INTO qc_tool_list (QC_Tool) VALUES (?)")->execute([$name]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 10. 刪除量具種類
    if ($_POST['action'] === 'delete_tool_category') {
        try {
            $id = $_POST['id'];
            // 檢查是否有關聯的量具編號
            $chk = $pdo->prepare("SELECT count(*) FROM qc_tool WHERE QC_Tool_List_id = ?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) throw new Exception("此種類下尚有量具編號，請先清空編號後再刪除");

            $pdo->prepare("DELETE FROM qc_tool_list WHERE QC_Tool_List_id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 11. 儲存量具編號
    if ($_POST['action'] === 'save_tool_instance') {
        try {
            $id = $_POST['id'] ?? '';
            $cat_id = $_POST['cat_id'] ?? '';
            $no = $_POST['no'] ?? '';
            if (empty($cat_id) || empty($no)) throw new Exception("資料不完整");

            if ($id) {
                $pdo->prepare("UPDATE qc_tool SET Tool_No = ?, QC_Tool_List_id = ? WHERE Tool_id = ?")->execute([$no, $cat_id, $id]);
            } else {
                $pdo->prepare("INSERT INTO qc_tool (Tool_No, QC_Tool_List_id, Created_at) VALUES (?, ?, ?)")->execute([$no, $cat_id, date('Y-m-d H:i:s')]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 12. 刪除量具編號
    if ($_POST['action'] === 'delete_tool_instance') {
        try {
            $id = $_POST['id'];
            $pdo->prepare("DELETE FROM qc_tool WHERE Tool_id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 13. 取代並刪除量具種類
    if ($_POST['action'] === 'replace_tool_category') {
        try {
            $old_id = $_POST['old_id'];
            $new_id = $_POST['new_id'];
            if (empty($old_id) || empty($new_id) || $old_id == $new_id) throw new Exception("無效的參數");

            $pdo->beginTransaction();
            // 1. 更新檢驗項目關聯
            $pdo->prepare("UPDATE qc_inspection_item_tool_type SET QC_Tool_List_id = ? WHERE QC_Tool_List_id = ?")->execute([$new_id, $old_id]);
            // 2. 更新實體量具編號關聯
            $pdo->prepare("UPDATE qc_tool SET QC_Tool_List_id = ? WHERE QC_Tool_List_id = ?")->execute([$new_id, $old_id]);
            // 3. 刪除舊種類
            $pdo->prepare("DELETE FROM qc_tool_list WHERE QC_Tool_List_id = ?")->execute([$old_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 14. 表單類型管理 (CRUD)
    if ($_POST['action'] === 'manage_form_types') {
        try {
            $sub_action = $_POST['sub_action'];
            if ($sub_action === 'list') {
                echo json_encode(['success' => true, 'data' => getFormTypes($pdo)]);
            } elseif ($sub_action === 'save') {
                $id = $_POST['id'] ?? '';
                $code = $_POST['code'];
                $name = $_POST['name'];
                $desc = $_POST['desc'];
                $stage = $_POST['stage'];
                if ($id) {
                    $pdo->prepare("UPDATE qc_inspection_form_type SET form_code=?, form_name=?, description=?, inspection_stage=? WHERE form_type_id=?")->execute([$code, $name, $desc, $stage, $id]);
                } else {
                    $pdo->prepare("INSERT INTO qc_inspection_form_type (form_code, form_name, description, inspection_stage, is_active) VALUES (?, ?, ?, ?, 1)")->execute([$code, $name, $desc, $stage]);
                }
                echo json_encode(['success' => true]);
            } elseif ($sub_action === 'delete') {
                $id = $_POST['id'];
                // 檢查是否被使用
                $chk = $pdo->prepare("SELECT count(*) FROM qc_inspection_item WHERE form_type_id = ?");
                $chk->execute([$id]);
                if ($chk->fetchColumn() > 0) throw new Exception("此類型已被檢驗項目使用，無法刪除");
                
                $pdo->prepare("DELETE FROM qc_inspection_form_type WHERE form_type_id = ?")->execute([$id]);
                echo json_encode(['success' => true]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 15. 通用樣板管理
    if ($_POST['action'] === 'manage_templates') {
        try {
            $sub_action = $_POST['sub_action'];
            
            if ($sub_action === 'list') {
                $stmt = $pdo->query("SELECT * FROM qc_inspection_template ORDER BY template_id DESC");
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } 
            elseif ($sub_action === 'get_items') {
                $tid = $_POST['template_id'];
                $stmt = $pdo->prepare("SELECT * FROM qc_inspection_template_item WHERE template_id = ? ORDER BY sort_order ASC");
                $stmt->execute([$tid]);
                // 格式化數值
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($items as &$item) {
                    if ($item['min_value'] !== null) $item['min_value'] = (float)$item['min_value'];
                    if ($item['max_value'] !== null) $item['max_value'] = (float)$item['max_value'];
                    if ($item['plus_tolerance'] !== null) $item['plus_tolerance'] = (float)$item['plus_tolerance'];
                    if ($item['minus_tolerance'] !== null) $item['minus_tolerance'] = (float)$item['minus_tolerance'];
                }
                echo json_encode(['success' => true, 'items' => $items]);
            }
            elseif ($sub_action === 'save') {
                $id = $_POST['template_id'] ?? '';
                $name = $_POST['name'];
                $items = $_POST['items'] ?? [];
                
                $pdo->beginTransaction();
                
                if ($id) {
                    // 更新模式：更新名稱並刪除舊項目
                    $pdo->prepare("UPDATE qc_inspection_template SET template_name = ? WHERE template_id = ?")->execute([$name, $id]);
                    $pdo->prepare("DELETE FROM qc_inspection_template_item WHERE template_id = ?")->execute([$id]);
                    $tid = $id;
                } else {
                    // 新增模式
                    $pdo->prepare("INSERT INTO qc_inspection_template (template_name) VALUES (?)")->execute([$name]);
                    $tid = $pdo->lastInsertId();
                }
                
                $stmt = $pdo->prepare("INSERT INTO qc_inspection_template_item (template_id, item_name, standard_text, min_value, max_value, plus_tolerance, minus_tolerance, result_type, tool_ids, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($items as $idx => $item) {
                    $stmt->execute([
                        $tid, $item['name'], $item['standard'], 
                        ($item['min'] !== '') ? $item['min'] : null,
                        ($item['max'] !== '') ? $item['max'] : null,
                        ($item['plus_tolerance'] !== '') ? $item['plus_tolerance'] : null,
                        ($item['minus_tolerance'] !== '') ? $item['minus_tolerance'] : null,
                        $item['result_type'], $item['tool_id'], $idx
                    ]);
                }
                $pdo->commit();
                echo json_encode(['success' => true]);
            }
            elseif ($sub_action === 'delete') {
                $tid = $_POST['template_id'];
                $pdo->prepare("DELETE FROM qc_inspection_template WHERE template_id = ?")->execute([$tid]);
                $pdo->prepare("DELETE FROM qc_inspection_template_item WHERE template_id = ?")->execute([$tid]);
                echo json_encode(['success' => true]);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>品管檢驗標準設定</title>
    <!-- 引用現有樣式 -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        .search-result-item {
            cursor: pointer;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .search-result-item:hover {
            background-color: #f5f5f5;
        }
        .search-result-item.active {
            background-color: #d9edf7;
            border-left: 4px solid #31708f;
        }
        .table-input {
            width: 100%;
            border: 1px solid #ccc;
            padding: 4px;
            border-radius: 3px;
        }
        .remove-row-btn {
            color: #d9534f;
            cursor: pointer;
        }
        /* 固定左側搜尋欄高度 */
        .scrollable-list {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }
        .nav-tabs > li.active > a, .nav-tabs > li.active > a:focus, .nav-tabs > li.active > a:hover {
            background-color: #fff;
            border-top: 3px solid #26b99a; /* Green top border for active tab */
        }
        .special-item-btn {
            cursor: pointer;
        }
        /* 焦點儲存格變色 */
        .focused-cell {
            background-color: #d9edf7 !important; /* 淺藍色背景 */
        }
        /* 固定表頭與捲動 */
        #table-container {
            max-height: 60vh;
            overflow-y: auto;
            border-bottom: 1px solid #ddd;
        }
        #items-table thead th {
            position: sticky;
            top: 0;
            background-color: #f5f5f5;
            z-index: 5;
        }
        /* 量具選擇框樣式優化 */
        .tool-display {
            min-height: 34px;
            border: 1px solid #ccc;
            border-radius: 3px;
            padding: 4px 4px 0 4px;
            cursor: pointer;
            background: #fff;
        }
        .tool-display:hover {
            border-color: #66afe9;
            background-color: #fcfcfc;
        }
        .tool-badge {
            display: inline-block;
            padding: 1px 4px;
            margin-right: 2px;
            margin-bottom: 2px;
            border-radius: 2px;
            color: #fff;
            font-size: 11px;
            vertical-align: middle;
            line-height: 1.4;
        }
        .tool-badge.primary {
            background-color: #337ab7; /* 主要量具：藍色 */
            border: 1px solid #2e6da4;
        }
        .tool-badge.secondary {
            background-color: #777; /* 次要量具：灰色 */
            border: 1px solid #555;
        }
        /* 自訂浮動選單 */
        .tool-selector-popup {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 10;
            background: #fff;
            border: 1px solid #999;
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
            z-index: 9999;
            min-width: 350px;
            max-height: 400px;
            overflow-y: auto;
            display: none;
            border-radius: 3px;
        }
        .tool-option {
            padding: 6px 10px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
            color: #333;
        }
        .tool-option:hover {
            background-color: #f5f5f5;
        }
        .tool-option.selected {
            background-color: #d9edf7;
            font-weight: bold;
            color: #31708f;
        }
        .tool-option .check-icon {
            float: right;
            color: #337ab7;
        }
        /* 儲存狀態提示 */
        .save-status {
            margin-right: 15px;
            font-weight: bold;
            font-size: 16px;
            vertical-align: middle;
        }
        .status-saved { color: #5cb85c; }
        .status-unsaved { color: #d9534f; }
        .cell-stacked input {
            margin-bottom: 3px;
        }
        .cell-stacked input:last-child {
            margin-bottom: 0;
        }
        /* 製程分頁樣式 */
        .process-tabs {
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        .process-tabs > li > a {
            padding: 5px 10px;
            font-size: 12px;
        }
        .process-tabs > li.active > a {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-bottom-color: transparent;
        }
        /* 設定唯讀輸入框的背景顏色為灰色，讓使用者能直觀識別 */
        .table-input[readonly] {
            background-color: #eee;
            cursor: not-allowed;
            color: #555;
        }
        /* 齒輪資料表格樣式 */
        .gear-row {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
        }
    </style>
</head>
<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <!-- 引入選單 -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title_left">
                            <h4>品管檢驗標準設定 <small>QC Inspection Setup</small></h4>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <div class="row">
                        <!-- 左側：料號搜尋與列表 -->
                        <div class="col-md-4 col-sm-4 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-search"></i> 選擇料號</h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><button type="button" class="btn btn-info btn-xs" id="btn-manage-part" title="新增/修改/刪除料號"><i class="fa fa-cog"></i> 管理</button></li>
                                    </ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div class="input-group">
                                        <input type="text" id="search-input" class="form-control" placeholder="輸入料號或客戶名稱...">
                                        <span class="input-group-btn">
                                            <button type="button" class="btn btn-primary" id="btn-search">搜尋</button>
                                        </span>
                                    </div>
                                    <div id="part-list" class="scrollable-list">
                                        <p class="text-muted text-center" style="margin-top: 20px;">請輸入關鍵字進行搜尋</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 右側：設定區域 -->
                        <div class="col-md-8 col-sm-8 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-sliders"></i> 檢驗標準設定</h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><button type="button" class="btn btn-default btn-xs" id="btn-manage-tools" title="設定量具"><i class="fa fa-wrench"></i> 量具設定</button></li>
                                        <li><button type="button" class="btn btn-default btn-xs" id="btn-manage-special-items" title="設定幾何公差"><i class="fa fa-cog"></i> 幾何公差管理</button></li>
                                    </ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content" id="setting-area" style="display: none;">
                                    <!-- 料號資訊卡片 -->
                                    <div class="well well-sm">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>料號:</strong> <span id="display-part-no" class="text-primary" style="font-size: 16px;"></span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>版次/日期:</strong> <span id="display-version" class="text-danger"></span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>客戶:</strong> <span id="display-client"></span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>備註:</strong> <span id="display-remark"></span>
                                            </div>
                                        </div>
                                        <input type="hidden" id="current-d-id">
                                        <div class="row" id="gear-summary-display" style="margin-top: 10px; display: none;"></div>
                                    </div>

                                    <!-- 版本控制區 -->
                                    <div class="row" style="margin-bottom: 15px; background: #f9f9f9; padding: 10px; border: 1px solid #e5e5e5;">
                                        <div class="col-md-8 form-inline">
                                            <label>選擇檢驗版本：</label>
                                            <select id="version-select" class="form-control input-sm" style="min-width: 200px;">
                                                <option value="">-- 請選擇 --</option>
                                            </select>
                                            <button type="button" class="btn btn-success btn-sm" id="btn-new-version" style="margin-bottom: 0; margin-left: 5px;">
                                                <i class="fa fa-plus"></i> 建立新版本
                                            </button>
                                        </div>
                                        <div class="col-md-4 text-right">
                                            <span class="text-muted" id="version-status-text"></span>
                                        </div>
                                    </div>

                                    <!-- 表單啟用選擇區 -->
                                    <div class="form-group" id="form-selection-container" style="display:none; margin-bottom: 15px; padding: 10px; background: #fff; border: 1px dashed #ccc;">
                                        <label style="display:block; margin-bottom: 5px;">啟用此版本的檢驗表單：</label>
                                        <button type="button" class="btn btn-xs btn-default pull-right" id="btn-manage-form-types" style="margin-top: -25px;"><i class="fa fa-cog"></i> 管理表單類型</button>
                                        <div id="form-selection-checkboxes">
                                            <!-- 動態生成 Checkboxes -->
                                        </div>
                                    </div>

                                    <!-- 檢驗表類型 Tabs -->
                                    <div role="tabpanel" id="tabs-container" style="display:none;">
                                        <ul class="nav nav-tabs bar_tabs" role="tablist" id="form-type-tabs">
                                            <!-- 動態生成 Tabs -->
                                        </ul>
                                        <div class="tab-content">
                                            <div role="tabpanel" class="tab-pane fade active in" id="tab_content1">
                                                <!-- 內容在下面 -->
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 製程分頁區 (僅在特定類型顯示) -->
                                    <div id="process-tabs-area" style="display:none; margin-bottom: 10px;">
                                        <div class="row">
                                            <div class="col-md-10">
                                                <ul class="nav nav-tabs process-tabs" id="process-tabs-ul">
                                                    <!-- 動態生成製程分頁 -->
                                                </ul>
                                            </div>
                                            <div class="col-md-2 text-right">
                                                <button type="button" class="btn btn-default btn-xs" id="btn-add-process"><i class="fa fa-plus"></i> 新增製程</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 檢驗項目表格 -->
                                    <div class="table-responsive" id="table-container" style="display:none;">
                                        <!-- 編號格式選擇 -->
                                        <div class="row" style="margin: 5px 0;">
                                            <div class="col-md-12 text-right form-inline">
                                                <label style="margin-right: 10px;">編號格式：</label>
                                                <label class="radio-inline"><input type="radio" name="code-style" value="123" checked> 1, 2, 3...</label>
                                                <label class="radio-inline"><input type="radio" name="code-style" value="ABC"> A, B, C...</label>
                                            </div>
                                        </div>
                                        <table class="table table-bordered table-striped" id="items-table">
                                            <thead>
                                                <tr class="headings">
                                                    <th width="50"><i class="fa fa-arrows-v"></i></th>
                                                    <th width="60">編號</th>
                                                    <th width="20%">檢驗項目 <span class="text-danger">*</span></th>
                                                    <th width="10%">標準值</th>
                                                    <th width="10%">上公差<br><small class="text-muted">上限(Max)</small></th>
                                                    <th width="10%">下公差<br><small class="text-muted">下限(Min)</small></th>
                                                    <th width="15%">量具種類</th>
                                                    <th width="10%">結果類型</th>
                                                    <th width="50"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- 動態插入列 -->
                                            </tbody>
                                        </table>

                                    <div class="form-group">
                                        <button type="button" class="btn btn-success btn-sm" id="btn-add-row"><i class="fa fa-plus"></i> 新增空白項目</button>
                                        <div class="pull-right">
                                            <button type="button" class="btn btn-info btn-sm" id="btn-import-template"><i class="fa fa-download"></i> 匯入通用樣板</button>
                                            <button type="button" class="btn btn-default btn-sm" id="btn-manage-templates"><i class="fa fa-list-alt"></i> 管理樣板</button>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                                
                                <!-- 未選擇時的提示 -->
                                <div class="x_content text-center" id="empty-state">
                                    <div style="padding: 50px; color: #ccc;">
                                        <i class="fa fa-arrow-left fa-3x"></i>
                                        <h3>請先從左側選擇一個料號</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 頁尾 -->
            <?php include '../partPage/footer.html' ?>
        </div>
    </div>

    <!-- 料號管理 Modal -->
    <div class="modal fade" id="partModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg"> <!-- 加寬 Modal 以容納齒輪資料 -->
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title" id="partModalLabel">料號資料維護</h4>
                </div>
                <div class="modal-body">
                    <form id="part-form" class="form-horizontal form-label-left">
                        <input type="hidden" id="modal-d-id">
                        <div class="form-group">
                            <label class="control-label col-md-3 col-sm-3 col-xs-12">料號 (Part No) <span class="required">*</span></label>
                            <div class="col-md-9 col-sm-9 col-xs-12">
                                <input type="text" id="modal-part-no" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label col-md-3 col-sm-3 col-xs-12">工件種類</label>
                            <div class="col-md-9 col-sm-9 col-xs-12">
                                <select id="modal-type" class="form-control">
                                    <option value="N">一般 (General)</option>
                                    <option value="G">齒輪 (Gear)</option>
                                    <option value="H">滾刀 (Hob)</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label col-md-3 col-sm-3 col-xs-12">客戶</label>
                            <div class="col-md-9 col-sm-9 col-xs-12">
                                <div class="input-group">
                                    <input type="text" id="modal-client-search" class="form-control" placeholder="輸入代碼或名稱搜尋...">
                                    <input type="hidden" id="modal-customer-id">
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button" id="btn-search-customer"><i class="fa fa-search"></i></button>
                                    </span>
                                </div>
                                <div id="customer-search-results" style="position:absolute; z-index:1000; background:white; border:1px solid #ccc; width:93%; max-height:150px; overflow-y:auto; display:none;"></div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label col-md-3 col-sm-3 col-xs-12">版次 (Revision)</label>
                            <div class="col-md-9 col-sm-9 col-xs-12">
                                <input type="text" id="modal-revision" class="form-control" placeholder="例如: 1.0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label col-md-3 col-sm-3 col-xs-12">發行日期</label>
                            <div class="col-md-9 col-sm-9 col-xs-12">
                                <input type="date" id="modal-issue-date" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label col-md-3 col-sm-3 col-xs-12">備註</label>
                            <div class="col-md-9 col-sm-9 col-xs-12">
                                <textarea id="modal-remark" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                        
                        <!-- 齒輪資料區塊 -->
                        <div id="gear-section" style="display:none; border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                            <h4 style="margin-left: 10px;">齒輪詳細資料 <button type="button" class="btn btn-xs btn-success" id="btn-add-gear"><i class="fa fa-plus"></i> 新增齒輪</button></h4>
                            <div id="gear-rows-container" style="padding: 0 10px;">
                                <!-- 動態生成齒輪列 -->
                            </div>
                        </div>

                        <div class="form-group" id="copy-source-group">
                            <label class="control-label col-md-3 col-sm-3 col-xs-12">複製檢驗標準 (選填)</label>
                            <div class="col-md-9 col-sm-9 col-xs-12">
                                <div class="input-group">
                                    <input type="text" id="modal-copy-source-name" class="form-control" placeholder="輸入料號搜尋...">
                                    <input type="hidden" id="modal-copy-source-id">
                                    <span class="input-group-btn"><button class="btn btn-default" type="button" id="btn-search-copy-source"><i class="fa fa-search"></i></button></span>
                                </div>
                                <div id="copy-source-results" style="position:absolute; z-index:1000; background:white; border:1px solid #ccc; width:93%; max-height:150px; overflow-y:auto; display:none;"></div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger pull-left" id="btn-delete-part" style="display: none;">刪除</button>
                    <button type="button" class="btn btn-default" id="btn-clear-part">清空/新增</button>
                    <button type="button" class="btn btn-primary" id="btn-save-part">儲存</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 特殊符號選擇 Modal -->
    <div class="modal fade" id="specialItemModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title">選擇幾何公差/特殊項目</h4>
                </div>
                <div class="modal-body">
                    <div class="list-group" id="special-item-list">
                        <!-- 動態生成 -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 幾何公差管理 Modal -->
    <div class="modal fade" id="specialItemManageModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title">幾何公差與特殊項目設定</h4>
                </div>
                <div class="modal-body">
                    <!-- 編輯表單 -->
                    <div class="well well-sm">
                        <form id="special-item-form" class="form-inline">
                            <input type="hidden" id="si-id">
                            <div class="form-group">
                                <input type="text" id="si-name" class="form-control input-sm" placeholder="名稱 (如: 真圓度)" required>
                            </div>
                            <div class="form-group">
                                <input type="text" id="si-symbol" class="form-control input-sm" placeholder="符號 (如: ○)" size="5">
                            </div>
                            <div class="form-group">
                                <input type="text" id="si-code" class="form-control input-sm" placeholder="代碼/英文" size="10">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm" id="btn-save-si">新增</button>
                            <button type="button" class="btn btn-default btn-sm" id="btn-cancel-si" style="display:none;">取消</button>
                        </form>
                    </div>
                    <hr>
                    <!-- 列表 -->
                    <div class="list-group scrollable-list" id="manage-special-list" style="max-height: 300px;">
                        <!-- 動態生成 -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 量具管理 Modal -->
    <div class="modal fade" id="toolManageModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title">量具設定與綁定</h4>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- 左側：量具種類 -->
                        <div class="col-md-5 col-sm-5 col-xs-12" style="border-right: 1px solid #eee;">
                            <h4>1. 量具種類 (Category)</h4>
                            <form id="tool-cat-form" class="form-inline" style="margin-bottom: 10px;">
                                <input type="hidden" id="tc-id">
                                <div class="form-group">
                                    <input type="text" id="tc-name" class="form-control input-sm" placeholder="種類名稱 (如: 游標卡尺)" required>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm" id="btn-save-tc">新增</button>
                                <button type="button" class="btn btn-default btn-sm" id="btn-cancel-tc" style="display:none;">取消</button>
                            </form>
                            <div class="list-group scrollable-list" id="tool-cat-list" style="max-height: 400px;">
                                <!-- 動態生成 -->
                            </div>
                        </div>

                        <!-- 右側：量具編號 -->
                        <div class="col-md-7 col-sm-7 col-xs-12">
                            <h4>2. 量具編號 (Tool ID)</h4>
                            <div id="tool-instance-area" style="display:none;">
                                <p class="text-info">當前選擇種類：<strong id="current-cat-name"></strong></p>
                                <form id="tool-inst-form" class="form-inline" style="margin-bottom: 10px;">
                                    <input type="hidden" id="ti-id">
                                    <input type="hidden" id="ti-cat-id">
                                    <div class="form-group">
                                        <input type="text" id="ti-no" class="form-control input-sm" placeholder="量具編號 (如: C01)" required>
                                    </div>
                                    <button type="submit" class="btn btn-success btn-sm" id="btn-save-ti">新增編號</button>
                                    <button type="button" class="btn btn-default btn-sm" id="btn-cancel-ti" style="display:none;">取消</button>
                                </form>
                                <table class="table table-striped table-bordered table-condensed">
                                    <thead><tr><th>編號</th><th width="80">操作</th></tr></thead>
                                    <tbody id="tool-inst-list"></tbody>
                                </table>
                            </div>
                            <div id="tool-instance-empty" class="text-muted" style="padding-top: 50px; text-align: center;">
                                <i class="fa fa-arrow-left"></i> 請先從左側選擇一個量具種類
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 建立新版本 Modal -->
    <div class="modal fade" id="newVersionModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title">建立新檢驗版本</h4>
                </div>
                <div class="modal-body">
                    <p>請選擇版本來源：</p>
                    <div class="radio">
                        <label><input type="radio" name="verSource" value="REVISION" checked> 使用版次 (Revision)</label>
                    </div>
                    <div class="radio">
                        <label><input type="radio" name="verSource" value="ISSUE_DATE"> 使用發行日 (Issue Date)</label>
                    </div>
                    <hr>
                    <div class="form-group">
                        <label>版本名稱 (自動帶入)</label>
                        <input type="text" id="new-version-label" class="form-control" readonly>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="btn-confirm-version">建立</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 量具取代 Modal -->
    <div class="modal fade" id="toolReplaceModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title">取代並刪除量具</h4>
                </div>
                <div class="modal-body">
                    <p class="text-danger">您即將刪除：<strong id="replace-old-name"></strong></p>
                    <p>請選擇要將現有資料轉移到哪個量具種類：</p>
                    <input type="hidden" id="replace-old-id">
                    <select id="replace-new-id" class="form-control"></select>
                    <p class="help-block"><small>注意：執行後，舊種類將被刪除，所有關聯的檢驗項目與編號將移至新種類。</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="btn-confirm-replace">確認取代並刪除</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 表單類型管理 Modal -->
    <div class="modal fade" id="formTypeManageModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title">檢驗表單類型管理</h4>
                </div>
                <div class="modal-body">
                    <form id="ft-form" class="form-inline well well-sm">
                        <input type="hidden" id="ft-id">
                        <input type="text" id="ft-code" class="form-control input-sm" placeholder="代碼" required>
                        <input type="text" id="ft-name" class="form-control input-sm" placeholder="名稱" required>
                        <select id="ft-stage" class="form-control input-sm">
                            <option value="IQC">IQC (進料檢)</option>
                            <option value="IPQC">IPQC (製程檢)</option>
                            <option value="FQC">FQC (成品檢)</option>
                        </select>
                        <input type="text" id="ft-desc" class="form-control input-sm" placeholder="描述">
                        <button type="submit" class="btn btn-primary btn-sm" id="btn-save-ft">新增</button>
                        <button type="button" class="btn btn-default btn-sm" id="btn-cancel-ft" style="display:none;">取消</button>
                    </form>
                    <table class="table table-striped">
                        <thead><tr><th>代碼</th><th>名稱</th><th>檢驗階段</th><th>描述</th><th>操作</th></tr></thead>
                        <tbody id="ft-list"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 樣板管理 Modal -->
    <div class="modal fade" id="templateManageModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title">通用檢驗樣板管理</h4>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">請先在主畫面設定好檢驗項目，然後點擊「新增樣板」將目前的設定存為樣板。</div>
                    <div class="input-group" style="margin-bottom: 10px;">
                        <input type="text" id="new-template-name" class="form-control" placeholder="輸入新樣板名稱 (例如: 雷刻檢查標準)">
                        <span class="input-group-btn">
                            <button class="btn btn-default" type="button" id="btn-cancel-edit-template" style="display:none;">取消編輯</button>
                            <button class="btn btn-success" type="button" id="btn-create-template">從當前表格建立樣板</button>
                        </span>
                    </div>
                    <hr>
                    <div class="list-group" id="template-list"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 匯入樣板 Modal -->
    <div class="modal fade" id="importTemplateModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>
                    <h4 class="modal-title">選擇樣板匯入</h4>
                </div>
                <div class="modal-body">
                    <div class="list-group" id="import-template-list"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    <!-- SortableJS 用於拖曳排序 -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <script>
        $(document).ready(function() {
            // 全域變數
            var globalTools = []; // 量具列表
            var globalFormTypes = []; // 檢驗表類型
            var globalSpecialItems = []; // 特殊符號
            var currentPartData = null; // 當前選中的料號資料
            var currentFormType = null; // 當前選中的檢驗表類型 (物件)
            var enabledFormTypes = []; // 當前版本啟用的表單 ID 列表
            var toolManageData = { categories: [], tools: [] }; // 量具管理資料快取
            var currentToolCatId = null; // 當前選中的量具種類ID
            var isLoading = false; // 載入狀態旗標
            
            var allItemsData = []; // 用於暫存當前類型的所有項目 (含不同製程分頁)
            var addedProcessNames = []; // 用於暫存手動新增的製程名稱 (避免空分頁消失)
            var currentProcessName = null; // 當前選中的製程名稱 (null 代表共用/無製程)
            var editingTemplateId = null; // 當前正在編輯的樣板 ID

            var processTabsSortable = null; // Sortable instance for process tabs
            // 初始化量具種類拖曳排序
            var toolCatEl = document.getElementById('tool-cat-list');
            new Sortable(toolCatEl, {
                animation: 150,
                onEnd: function() {
                    var newOrderIds = [];
                    $('#tool-cat-list .tool-cat-item').each(function() {
                        newOrderIds.push($(this).data('id'));
                    });

                    // 更新後端
                    $.post('inspection_standard_setting.php', {
                        action: 'update_tool_category_order',
                        ids: newOrderIds
                    }, function(res) {
                        if (res.success) {
                            // 同步更新全域變數 globalTools，讓主畫面選單即時生效
                            var newGlobalTools = [];
                            newOrderIds.forEach(function(id) {
                                var tool = globalTools.find(t => t.QC_Tool_List_id == id);
                                if (tool) newGlobalTools.push(tool);
                            });
                            // 補上可能遺漏的 (防呆)
                            globalTools.forEach(function(t) {
                                if (!newOrderIds.includes(t.QC_Tool_List_id) && !newOrderIds.includes(String(t.QC_Tool_List_id))) {
                                    newGlobalTools.push(t);
                                }
                            });
                            globalTools = newGlobalTools;
                            toolManageData.categories = globalTools;
                        }
                    }, 'json');
                }
            });

            // 初始化拖曳排序
            var el = document.getElementById('items-table').getElementsByTagName('tbody')[0];
            new Sortable(el, {
                handle: '.drag-handle',
                animation: 150,
                onEnd: function() {
                    reindexRows();
                    if (!isLoading) updateSaveStatus(false);
                }
            });

            // 量具選擇框優化：滑鼠移出時自動縮小 (失去焦點)，避免擋住下方資料
            $(document).on('mouseleave', '.item-tool', function() {
                $(this).blur();
            });

            // 檢驗項目輸入數值時自動帶入規格
            $(document).on('input', '.item-name', function() {
                var val = $(this).val();
                if ($.isNumeric(val)) {
                    $(this).closest('tr').find('.item-spec').val(val);
                }
            });

            // 自動計算公差與上下限邏輯
            function updateToleranceAndLimit($row, type) {
                var specVal = parseFloat($row.find('.item-spec').val());
                if (isNaN(specVal)) return; // 標準值非數值則不計算

                var $tol, $limit;
                if (type === 'upper') {
                    $tol = $row.find('.item-plus-tol');
                    $limit = $row.find('.item-max');
                } else {
                    $tol = $row.find('.item-minus-tol');
                    $limit = $row.find('.item-min');
                }

                // 判斷驅動方 (誰不是唯讀，誰就是輸入源)
                // 若兩個都沒唯讀(初始狀態)，優先以公差驅動(若公差有值)
                
                if (!$tol.prop('readonly') && $tol.val() !== '') {
                    // 公差驅動 -> 計算極限
                    var val = parseFloat($tol.val());
                    var limitVal = specVal + val;
                    $limit.val(parseFloat(limitVal.toFixed(4))).prop('readonly', true);
                } else if (!$limit.prop('readonly') && $limit.val() !== '') {
                    // 極限驅動 -> 計算公差
                    var val = parseFloat($limit.val());
                    var tolVal = val - specVal;
                    $tol.val(parseFloat(tolVal.toFixed(4))).prop('readonly', true);
                }
            }

            // 監聽輸入事件 (需確認有標準值才鎖定)
            $(document).on('input', '.item-plus-tol', function() {
                var $row = $(this).closest('tr');
                var val = $(this).val();
                var $max = $row.find('.item-max');
                var spec = parseFloat($row.find('.item-spec').val());
                
                if (val !== '' && !isNaN(spec)) {
                    $max.prop('readonly', true);
                    updateToleranceAndLimit($row, 'upper');
                } else if (val === '') {
                    $max.prop('readonly', false);
                }
            });

            $(document).on('input', '.item-max', function() {
                var $row = $(this).closest('tr');
                var val = $(this).val();
                var $tol = $row.find('.item-plus-tol');
                var spec = parseFloat($row.find('.item-spec').val());

                if (val !== '' && !isNaN(spec)) {
                    $tol.prop('readonly', true);
                    updateToleranceAndLimit($row, 'upper');
                } else if (val === '') {
                    $tol.prop('readonly', false);
                }
            });

            // 下公差/下限 同理
            $(document).on('input', '.item-minus-tol', function() {
                var $row = $(this).closest('tr');
                var val = $(this).val();
                var $min = $row.find('.item-min');
                var spec = parseFloat($row.find('.item-spec').val());

                if (val !== '' && !isNaN(spec)) {
                    $min.prop('readonly', true);
                    updateToleranceAndLimit($row, 'lower');
                } else if (val === '') {
                    $min.prop('readonly', false);
                }
            });

            $(document).on('input', '.item-min', function() {
                var $row = $(this).closest('tr');
                var val = $(this).val();
                var $tol = $row.find('.item-minus-tol');
                var spec = parseFloat($row.find('.item-spec').val());

                if (val !== '' && !isNaN(spec)) {
                    $tol.prop('readonly', true);
                    updateToleranceAndLimit($row, 'lower');
                } else if (val === '') {
                    $tol.prop('readonly', false);
                }
            });

            // 標準值變更 -> 重新計算
            $(document).on('input', '.item-spec', function() {
                var $row = $(this).closest('tr');
                // 嘗試更新兩邊
                updateToleranceAndLimit($row, 'upper');
                updateToleranceAndLimit($row, 'lower');
            });

            // 焦點變色效果 (輔助使用者辨識目前位置)
            $(document).on('focus', '.table-input', function() {
                $(this).closest('td').addClass('focused-cell');
            });
            $(document).on('blur', '.table-input', function() {
                $(this).closest('td').removeClass('focused-cell');
            });

            // Enter 鍵切換焦點 (快速建立)
            $(document).on('keydown', '#items-table input, #items-table select', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    var $inputs = $('#items-table').find('input:visible, select:visible');
                    var idx = $inputs.index(this);
                    if (idx + 1 < $inputs.length) {
                        var $next = $inputs.eq(idx + 1);
                        $next.focus();
                        if ($next.is('input')) $next.select();
                    } else {
                        $('#btn-add-row').focus();
                    }
                }
            });

            // 1. 搜尋功能 (含即時搜尋)
            var searchTimer;

            function doSearch() {
                var kw = $('#search-input').val().trim();
                if (!kw) { 
                    $('#part-list').empty();
                    return; 
                }

                $('#part-list').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 搜尋中...</p>');

                $.post('inspection_standard_setting.php', {
                    action: 'search_parts',
                    keyword: kw
                }, function(res) {
                    if (res.success) {
                        var html = '';
                        if (res.data.length === 0) {
                            html = '<p class="text-center text-muted">找不到符合的資料</p>';
                        } else {
                            res.data.forEach(function(item) {
                                html += `
                                    <div class="search-result-item" data-json='${JSON.stringify(item)}'>
                                        <h5 style="margin: 0 0 5px 0; color: #2A3F54; font-weight: 600;">${escapeHtml(item.D_Setting_Id)}</h5>
                                        <div style="font-size: 12px; color: #777;">
                                            <span><i class="fa fa-code-fork"></i> ${escapeHtml(item.version_display)}</span>
                                            <span class="pull-right"><i class="fa fa-user"></i> ${escapeHtml(item.Client_Name || '-')}</span>
                                        </div>
                                    </div>
                                `;
                            });
                        }
                        $('#part-list').html(html);
                    } else {
                        $('#part-list').html('<p class="text-center text-danger">搜尋失敗: ' + escapeHtml(res.message) + '</p>');
                    }
                }, 'json');
            }

            $('#btn-search').click(function() {
                doSearch();
            });

            // 支援即時搜尋與 Enter
            $('#search-input').on('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(doSearch, 300); // 延遲 300ms 執行
            });

            $('#search-input').keypress(function(e) {
                if(e.which == 13) {
                    clearTimeout(searchTimer);
                    doSearch();
                }
            });

            // 雙擊清除搜尋輸入框內容
            $('#search-input').dblclick(function() {
                if ($(this).val()) {
                    $(this).val('');
                    doSearch();
                }
            });

            // 2. 點擊料號載入設定
            $(document).on('click', '.search-result-item', function() {
                // 樣式切換
                $('.search-result-item').removeClass('active');
                $(this).addClass('active');

                var data = $(this).data('json');
                currentPartData = data;
                loadPartSetting(data);
            });

            function loadPartSetting(data) {
                // 填入基本資料
                $('#display-part-no').text(data.D_Setting_Id);
                $('#display-version').text(data.version_display);
                $('#display-client').text(data.Client_Name || '-');
                $('#display-remark').text(data.Remark || '-');
                $('#current-d-id').val(data.d_id);

                // 切換顯示區域
                $('#empty-state').hide();
                $('#setting-area').fadeIn();

                // 重置 UI
                $('#version-select').empty().append('<option value="">載入中...</option>');
                $('#tabs-container').hide();
                $('#table-container').hide();
                $('#form-selection-container').hide();
                $('#process-tabs-area').hide();
                
                // 呼叫後端初始化資料
                $.post('inspection_standard_setting.php', {
                    action: 'init_part_settings',
                    d_id: data.d_id
                }, function(res) {
                    if (res.success) {
                        // 1. 存入全域變數
                        globalTools = res.tools;
                        globalFormTypes = res.form_types;
                        globalSpecialItems = res.special_items;

                        // 更新 currentPartData 的 gears 資料，以便管理 Modal 使用
                        if (currentPartData) currentPartData.gears = res.gears;

                        // 開發者注意：請在您的 HTML 中，於顯示料號資訊的區塊 (例如 <div id="selected-bom-info">) 加入 <div class="row" id="gear-summary-display"></div>
                        $('#gear-summary-display').empty().hide();
                        if (res.gears && res.gears.length > 0) {
                            let summaryHtml = '<div class="col-md-12" style="margin-top: 5px; padding-top: 5px; border-top: 1px dashed #ccc;">';
                            res.gears.forEach(function(g) {
                                let parts = [];
                                if (g.Module) parts.push(g.Module);
                                if (g.Teeth) parts.push('T' + g.Teeth);
                                if (g.Pressure_Angle) parts.push('PA' + g.Pressure_Angle);
                                if (g.Face_Width) parts.push('W' + parseFloat(g.Face_Width));
                                if (g.Workpiece_Length) parts.push('L' + parseFloat(g.Workpiece_Length));
                                
                                summaryHtml += `<div style="margin-bottom: 2px;"><strong><i class="fa fa-cogs"></i> 齒輪規格:</strong> <span class="text-primary" style="font-weight:bold; margin-left: 5px;">${parts.join(' ')}</span></div>`;
                            });
                            summaryHtml += '</div>';
                            $('#gear-summary-display').html(summaryHtml).show();
                        }
                        
                        // 更新特殊符號 Modal 內容
                        updateSpecialItemModalList();

                        // 2. 填充版本下拉選單
                        var vSelect = $('#version-select');
                        vSelect.empty().append('<option value="">-- 請選擇 --</option>');
                        
                        if (res.versions.length > 0) {
                            res.versions.forEach(function(v) {
                                vSelect.append(`<option value="${v.version_id}">${v.version_label}</option>`);
                            });
                            // 自動選擇最新版本
                            vSelect.val(res.versions[0].version_id).trigger('change');
                        } else {
                            // 若無版本，提示建立
                            $('#version-status-text').text('尚無檢驗版本，請先建立');
                        }

                    } else {
                        alert('載入失敗: ' + res.message);
                    }
                }, 'json');
            }

            function updateSpecialItemModalList() {
                var specialHtml = '';
                globalSpecialItems.forEach(function(si) {
                    specialHtml += `<a href="#" class="list-group-item special-item-select" data-name="${si.name}" data-symbol="${si.symbol}">
                        <span class="badge">${si.symbol}</span> ${si.name}
                    </a>`;
                });
                $('#special-item-list').html(specialHtml);
            }

            // 版本選擇變更
            $('#version-select').change(function() {
                var verId = $(this).val();
                if (!verId) {
                    $('#tabs-container').hide();
                    $('#table-container').hide();
                    $('#form-selection-container').hide();
                    return;
                }

                // 載入該版本啟用的表單
                loadVersionForms(verId);
            });

            function loadVersionForms(verId) {
                $('#form-selection-checkboxes').html('<i class="fa fa-spinner fa-spin"></i> 載入中...');
                $('#form-selection-container').show();

                $.post('inspection_standard_setting.php', {
                    action: 'get_version_form_map',
                    version_id: verId
                }, function(res) {
                    if (res.success) {
                        // 轉換為字串陣列以利比較 (PHP fetchColumn 可能回傳 int 或 string)
                        enabledFormTypes = res.enabled_forms.map(String);
                        renderFormCheckboxes();
                        renderTabs();
                    }
                }, 'json');
            }

            function renderFormCheckboxes() {
                var html = '';
                globalFormTypes.forEach(function(ft) {
                    var isChecked = enabledFormTypes.includes(String(ft.form_type_id)) ? 'checked' : '';
                    html += `<label class="checkbox-inline" style="margin-right: 15px;">
                        <input type="checkbox" class="form-type-toggle" value="${ft.form_type_id}" ${isChecked}> 
                        ${ft.form_name}
                    </label>`;
                });
                $('#form-selection-checkboxes').html(html);
            }

            // 監聽表單啟用 Checkbox 變更
            $(document).on('change', '.form-type-toggle', function() {
                var verId = $('#version-select').val();
                var formTypeId = $(this).val();
                var isEnabled = $(this).prop('checked');

                $.post('inspection_standard_setting.php', {
                    action: 'toggle_form_enable',
                    version_id: verId,
                    form_type_id: formTypeId,
                    is_enabled: isEnabled
                }, function(res) {
                    if (res.success) {
                        // 重新載入 Tabs (簡單起見，重新 fetch map 確保同步，或直接更新 enabledFormTypes)
                        loadVersionForms(verId);
                    }
                }, 'json');
            });

            // 渲染 Tabs
            function renderTabs() {
                var $ul = $('#form-type-tabs');
                $ul.empty();

                // 排序: IQC -> IPQC -> FQC
                globalFormTypes.sort(function(a, b) {
                    var map = {'IQC': 1, 'IPQC': 2, 'FQC': 3, 'PKG': 4};
                    var sa = map[a.inspection_stage] || 4;
                    var sb = map[b.inspection_stage] || 4;
                    return sa - sb;
                });
                
                var hasActive = false;
                globalFormTypes.forEach(function(ft, index) {
                    // 只顯示已啟用的表單
                    if (enabledFormTypes.includes(String(ft.form_type_id))) {
                        var activeClass = !hasActive ? 'active' : '';                        
                        $ul.append(`<li role="presentation" class="${activeClass}">
                            <a href="#" class="tab-link" data-id="${ft.form_type_id}" data-code="${ft.form_code}" data-name="${ft.form_name}" data-stage="${ft.inspection_stage}">${ft.form_name}</a>
                        </li>`);
                        hasActive = true;
                    }
                });

                // 加入儲存按鈕 (位於分頁右側)
                if (hasActive) {
                    $ul.append(`<li class="pull-right" style="margin-top:2px;">
                        <span id="save-status-indicator" class="save-status" style="margin-right: 10px; font-size: 14px; vertical-align: middle;"></span>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-save-top">
                            <i class="fa fa-save"></i> 儲存設定
                        </button>
                    </li>`);
                }

                if (hasActive) {
                    $('#tabs-container').show();
                    // 觸發第一個 Tab 點擊
                    $ul.find('li.active a').click();
                } else {
                    $('#tabs-container').hide();
                    $('#table-container').hide();
                    $('#process-tabs-area').hide();
                }
            }

            // Tab 點擊事件
            $(document).on('click', '.tab-link', function(e) {
                e.preventDefault();
                // UI 切換
                $('#form-type-tabs li').removeClass('active');
                $(this).parent().addClass('active');

                // 設定當前類型
                currentFormType = {
                    id: $(this).data('id'),
                    code: $(this).data('code'),
                    name: $(this).data('name'),
                    stage: $(this).data('stage')
                };

                // 重置製程相關狀態
                allItemsData = [];
                addedProcessNames = [];
                currentProcessName = null;

                loadItemsForCurrentSelection();
            });

            // 載入檢驗項目
            function loadItemsForCurrentSelection() {
                var verId = $('#version-select').val();
                if (!verId || !currentFormType) return;

                $('#table-container').show();
                $('#process-tabs-area').hide(); // 先隱藏，視情況開啟
                $('#items-table tbody').html('<tr><td colspan="9" class="text-center">載入中...</td></tr>');
                isLoading = true;

                $.post('inspection_standard_setting.php', {
                    action: 'get_version_items',
                    version_id: verId,
                    form_type_id: currentFormType.id
                }, function(res) {
                    $('#items-table tbody').empty();
                    if (res.success) {
                        allItemsData = res.items; // 儲存所有資料
                        addedProcessNames = []; // 重置手動新增列表 (因為已從 DB 載入)

                        // 判斷並設定編號格式 (根據第一筆資料)
                        var useABC = false;
                        if (allItemsData.length > 0) {
                            var firstCode = allItemsData[0].item_code;
                            // 若第一筆編號不是數字，則視為 ABC 格式
                            if (isNaN(firstCode)) {
                                useABC = true;
                            }
                        } else {
                            // 若無資料，預設邏輯：車床類使用 ABC，其餘使用 123
                            if (currentFormType.name.indexOf('車床') > -1 || currentFormType.code === 'LATHE') useABC = true;
                        }
                        $('input[name="code-style"][value="' + (useABC ? 'ABC' : '123') + '"]').prop('checked', true);
                        
                        // 判斷是否啟用製程分頁 (例如 GENERAL 類型)
                        // 這裡設定：只要是 GENERAL 就啟用，或者如果有資料包含 process_name 也啟用
                        // 修正：增加防呆，若表單名稱包含「成品」或「出貨」，強制視為 FQC (不分頁)，避免因資料庫設定為 IPQC 或資料帶有製程名稱而被隱藏
                        var useProcessTabs = (currentFormType.stage === 'IPQC' && currentFormType.name.indexOf('成品') === -1 && currentFormType.name.indexOf('出貨') === -1 && currentFormType.name.indexOf('包裝') === -1);
                        
                        if (useProcessTabs) {
                            $('#process-tabs-area').fadeIn();
                            renderProcessTabs();
                        } else {
                            $('#process-tabs-area').hide();
                            currentProcessName = null;
                            renderTableFromData(null);
                        }

                        updateSaveStatus(true); // 載入完成視為已儲存
                    }
                    isLoading = false;
                }, 'json');
            }

            // 渲染製程分頁
            function renderProcessTabs() {
                var $ul = $('#process-tabs-ul');
                $ul.empty();

                // 找出所有獨特的 process_name
                var processes = ['共用標準']; // 預設第一個是 NULL 對應的顯示名稱
                var hasNull = false;

                // 從資料中提取現有的製程名稱
                allItemsData.forEach(function(item) {
                    if (item.process_name && !processes.includes(item.process_name)) {
                        processes.push(item.process_name);
                    }
                });

                // 加入手動新增的製程 (避免因為該製程暫無項目而被過濾掉)
                addedProcessNames.forEach(function(pName) {
                    if (!processes.includes(pName)) {
                        processes.push(pName);
                    }
                });

                // 確保當前選中的製程也在列表中 (防止剛新增時消失)
                if (currentProcessName && currentProcessName !== '共用標準' && !processes.includes(currentProcessName)) {
                    processes.push(currentProcessName);
                }
                
                // 若當前選中的製程無效(不在最終列表中)，則重置為共用標準 (例如刪除後)
                if (currentProcessName && currentProcessName !== '共用標準' && !processes.includes(currentProcessName)) {
                    currentProcessName = null;
                }

                processes.forEach(function(pName) {
                    var isActive = (currentProcessName === pName || (currentProcessName === null && pName === '共用標準')) ? 'active' : '';
                    var displayName = escapeHtml(pName);
                    var deleteBtn = (pName !== '共用標準') ? `<i class="fa fa-times text-danger btn-del-process" title="刪除此製程" style="cursor:pointer; margin-left:5px;"></i>` : '';
                    
                    $ul.append(`<li role="presentation" class="${isActive}">
                        <a href="#" class="process-tab-link" data-name="${escapeHtml(pName)}">
                            ${displayName} ${deleteBtn}
                        </a>
                    </li>`);
                });

                // 渲染當前分頁的表格
                if (processTabsSortable) {
                    processTabsSortable.destroy();
                }
                var ul = document.getElementById('process-tabs-ul');
                processTabsSortable = new Sortable(ul, {
                    animation: 150,
                    onEnd: function(evt) {
                        saveCurrentTableToModel(); // 拖曳結束前，先保存當前表格內容，避免資料遺失

                        var newOrder = [];
                        $('#process-tabs-ul .process-tab-link').each(function() {
                            newOrder.push($(this).data('name'));
                        });
                        
                        var newOrderedItems = [];
                        newOrder.forEach(function(pName) {
                            var processNameForFilter = (pName === '共用標準') ? null : pName;
                            var itemsForProcess = allItemsData.filter(item => item.process_name === processNameForFilter);
                            newOrderedItems = newOrderedItems.concat(itemsForProcess);
                        });
                        allItemsData = newOrderedItems;
                        updateSaveStatus(false);
                    }
                });
                renderTableFromData(currentProcessName);
            }

            // 根據資料渲染表格 (過濾 process_name)
            function renderTableFromData(pName) {
                $('#items-table tbody').empty();
                
                // 修正：同步上方的防呆邏輯，確保渲染時也不會誤用過濾
                var useProcessTabs = (currentFormType && currentFormType.stage === 'IPQC' && currentFormType.name.indexOf('成品') === -1 && currentFormType.name.indexOf('出貨') === -1 && currentFormType.name.indexOf('包裝') === -1);
                var filtered = useProcessTabs ? allItemsData.filter(item => item.process_name === pName) : allItemsData;

                // 根據當前分頁資料自動判斷並切換編號格式 (123 或 ABC)
                var useABC = false;
                if (filtered.length > 0) {
                    var firstCode = filtered[0].code || filtered[0].item_code;
                    if (firstCode && isNaN(firstCode)) {
                        useABC = true;
                    }
                } else {
                    // 若無資料，使用預設邏輯
                    if (currentFormType.name.indexOf('車床') > -1 || currentFormType.code === 'LATHE') useABC = true;
                }
                // 更新 Radio Button 狀態 (不觸發 change 事件以免重複執行)
                var $radio = $('input[name="code-style"][value="' + (useABC ? 'ABC' : '123') + '"]');
                if (!$radio.prop('checked')) $radio.prop('checked', true);

                if (filtered.length > 0) {
                    filtered.forEach(item => addRow(item));
                } else {
                    addRow(); // 預設一行
                }
                reindexRows();
            }

            // 點擊製程分頁
            $(document).on('click', '.process-tab-link', function(e) {                
                if ($(e.target).hasClass('btn-del-process')) return; // 避免觸發刪除
                e.preventDefault();
                
                // 1. 先將當前表格的內容存回 allItemsData
                saveCurrentTableToModel();

                // 2. 切換分頁
                var name = $(this).attr('data-name');
                currentProcessName = (name === '共用標準') ? null : name;
                $('#process-tabs-ul li').removeClass('active');
                $(this).parent().addClass('active');
                // 移除 renderProcessTabs() 呼叫，避免因資料重組導致分頁順序跳動
                // renderProcessTabs(); 
                renderTableFromData(currentProcessName);
            });

            // 新增製程分頁
            $('#btn-add-process').click(function() {
                var name = prompt("請輸入製程名稱 (例如: OP10-銑床, OP20-熱處理):");
                if (name) {
                    name = name.trim();
                    // 檢查重複
                    var exists = false;
                    $('#process-tabs-ul li a').each(function() {
                        if ($(this).attr('data-name') === name) exists = true;
                    });
                    
                    if (exists) {
                        alert("此製程名稱已存在");
                        return;
                    }

                    saveCurrentTableToModel();
                    currentProcessName = name;
                    // 加入手動列表，確保切換分頁後回來還在
                    if (!addedProcessNames.includes(name)) {
                        addedProcessNames.push(name);
                    }
                    // 不需要在 allItemsData 預先加資料，切換過去是空的，新增項目時會自動帶入 process_name
                    renderProcessTabs();
                    updateSaveStatus(false);
                }
            });

            // 刪除製程分頁
            $(document).on('click', '.btn-del-process', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var pName = $(this).closest('a').attr('data-name');
                if (!confirm(`確定要刪除製程 "${pName}" 及其所有檢驗項目嗎？`)) return;

                // 從 allItemsData 移除該製程的所有項目
                allItemsData = allItemsData.filter(function(item) {
                    return item.process_name !== pName;
                });

                // 從手動列表中移除
                addedProcessNames = addedProcessNames.filter(n => n !== pName);

                if (currentProcessName === pName) currentProcessName = null;
                renderProcessTabs();
                updateSaveStatus(false);
            });

            // 監聽編號格式切換
            $('input[name="code-style"]').change(function() {
                reindexRows();
                if (!isLoading) updateSaveStatus(false);
            });

            // 3. 新增列功能
            $('#btn-add-row').click(function() {
                addRow();
                reindexRows();
                if (!isLoading) updateSaveStatus(false);
            });

            // 匯入樣板按鈕
            $('#btn-import-template').click(function() {
                loadTemplates('import');
            });
            $('#btn-manage-templates').click(function() {
                loadTemplates('manage');
            });

            // =================================================
            // 自訂量具選擇器邏輯
            // =================================================
            
            // 1. 點擊顯示框開啟選單
            $(document).on('click', '.tool-display', function(e) {
                e.stopPropagation(); // 阻止冒泡
                
                // 關閉其他已開啟的選單
                $('.tool-selector-popup').remove();

                var $display = $(this);
                var $input = $display.siblings('.item-tool-val');
                var currentIds = $input.val() ? $input.val().split(',') : [];

                // 建立選單 HTML
                var popupHtml = '<div class="tool-selector-popup">';
                if (globalTools.length === 0) {
                    popupHtml += '<div class="tool-option text-muted">無可用量具</div>';
                } else {
                    globalTools.forEach(function(t) {
                        var isSel = currentIds.includes(t.QC_Tool_List_id.toString());
                        var selClass = isSel ? 'selected' : '';
                        var icon = isSel ? '<i class="fa fa-check check-icon"></i>' : '';
                        // 標示主要量具 (如果是選取陣列中的第一個)
                        var isPrimary = (currentIds.length > 0 && currentIds[0] == t.QC_Tool_List_id);
                        var primaryLabel = isPrimary ? ' <span class="label label-primary" style="font-size:10px;">Main</span>' : '';

                        popupHtml += `<div class="tool-option ${selClass}" data-id="${t.QC_Tool_List_id}">
                            ${escapeHtml(t.QC_Tool)} ${primaryLabel} ${icon}
                        </div>`;
                    });
                }
                popupHtml += '</div>';

                // 插入到 body 並定位
                var $popup = $(popupHtml);
                $('body').append($popup);

                var offset = $display.offset();
                $popup.css({
                    top: offset.top + $display.outerHeight(),
                    left: offset.left,
                    width: Math.max($display.outerWidth(), 350) // 至少 350px 寬
                });
                $popup.slideDown(100);

                // 綁定選單項目點擊事件 (使用閉包保存 input 參考)
                $popup.find('.tool-option').click(function(e) {
                    e.stopPropagation();
                    var id = $(this).data('id').toString();
                    var newIds = [...currentIds]; // 複製陣列

                    var idx = newIds.indexOf(id);
                    if (idx > -1) {
                        // 已存在 -> 移除
                        newIds.splice(idx, 1);
                    } else {
                        // 不存在 -> 加入 (加到最後面)
                        newIds.push(id);
                    }

                    // 更新資料
                    currentIds = newIds;
                    $input.val(newIds.join(','));
                    
                    // 更新顯示
                    $display.html(renderToolBadges(newIds));
                    
                    // 重新渲染選單 (為了更新勾選狀態和 Main 標籤)
                    // 簡單做法：關閉並重新觸發 click，或手動更新 DOM。這裡選擇手動更新 DOM 比較順暢
                    $popup.find('.tool-option').each(function() {
                        var optId = $(this).data('id').toString();
                        var isSel = newIds.includes(optId);
                        var isPri = (newIds.length > 0 && newIds[0] == optId);
                        
                        $(this).toggleClass('selected', isSel);
                        var content = escapeHtml(globalTools.find(t => t.QC_Tool_List_id == optId).QC_Tool);
                        if (isPri) content += ' <span class="label label-primary" style="font-size:10px;">Main</span>';
                        if (isSel) content += ' <i class="fa fa-check check-icon"></i>';
                        $(this).html(content);
                    });

                    if (!isLoading) updateSaveStatus(false);
                });
            });

            // 點擊頁面其他地方關閉選單
            $(document).on('click', function() {
                $('.tool-selector-popup').remove();
            });

            // 渲染標籤 HTML
            function renderToolBadges(ids) {
                if (!ids || ids.length === 0 || (ids.length === 1 && ids[0] === "")) {
                    return '<span class="text-muted" style="font-size:11px; padding:2px;">請選擇...</span>';
                }
                var html = '';
                ids.forEach(function(id, index) {
                    var tool = globalTools.find(t => t.QC_Tool_List_id == id);
                    if (tool) {
                        var badgeClass = (index === 0) ? 'primary' : 'secondary'; // 第一個是主要
                        var title = (index === 0) ? '主要量具' : '備用量具';
                        html += `<span class="tool-badge ${badgeClass}" title="${title}">${escapeHtml(tool.QC_Tool)}</span>`;
                    }
                });
                return html;
            }

            // 新增一行
            function addRow(data = null) {
                var code = data ? data.item_code : ''; // 編號由 reindexRows 覆蓋
                var name = data ? data.item_name : '';
                var spec = data ? data.standard_text : '';
                var min = (data && data.min_value !== null) ? data.min_value : '';
                var max = (data && data.max_value !== null) ? data.max_value : '';
                var rType = data ? data.result_type : 'NUMERIC';

                // 計算公差顯示 (若有標準值與極限值)
                var specVal = parseFloat(spec);
                var plusTol = (data && data.plus_tolerance != null) ? data.plus_tolerance : '';
                var minusTol = (data && data.minus_tolerance != null) ? data.minus_tolerance : '';
                var maxReadOnly = '';
                var minReadOnly = '';

                if (!isNaN(specVal)) {
                    if (plusTol === '' && max !== '') {
                        var t = parseFloat(max) - specVal;
                        plusTol = parseFloat(t.toFixed(4));
                    }
                    if (minusTol === '' && min !== '') {
                        var t = parseFloat(min) - specVal;
                        minusTol = parseFloat(t.toFixed(4));
                    }
                }
                
                // 處理量具 ID (可能是逗號分隔字串或單一值)
                var toolIds = [];
                if (data && data.tool_ids) {
                    toolIds = data.tool_ids.toString().split(',');
                } else if (data && (data.QC_Tool_List_id || data.tool_id)) {
                    toolIds = [data.QC_Tool_List_id || data.tool_id];
                }

                // 結果類型下拉
                var typeSelNum = (rType === 'NUMERIC') ? 'selected' : '';
                var typeSelOk = (rType === 'OKNG') ? 'selected' : '';

                // 唯讀屬性 (如果是特殊項目，名稱不可手輸，這裡簡單判斷：若名稱在特殊列表中，則鎖定)
                // 但為了方便，我們統一允許手輸，但提供按鈕覆蓋
                var nameInput = `<div class="input-group">
                    <input type="text" class="form-control input-sm item-name" value="${escapeHtml(name)}">
                    <span class="input-group-btn">
                        <button class="btn btn-default btn-sm special-item-btn" type="button" title="選擇特殊項目"><i class="fa fa-cube"></i></button>
                    </span>
                </div>`;

                var tr = `
                    <tr>
                        <td class="text-center drag-handle" style="vertical-align: middle; cursor: move;"><i class="fa fa-bars text-muted"></i></td>
                        <td class="text-center item-code-cell" style="vertical-align: middle; font-weight:bold;"></td>
                        <td>${nameInput}</td>
                        <td><input type="text" class="table-input item-spec" value="${escapeHtml(spec)}"></td>
                        
                        <td>
                            <div class="cell-stacked">
                                <input type="number" step="any" class="table-input item-plus-tol" value="${plusTol}" placeholder="上公差">
                                <input type="number" step="any" class="table-input item-max" value="${escapeHtml(max)}" placeholder="上限" ${maxReadOnly}>
                            </div>
                        </td>
                        <td>
                            <div class="cell-stacked">
                                <input type="number" step="any" class="table-input item-minus-tol" value="${minusTol}" placeholder="下公差">
                                <input type="number" step="any" class="table-input item-min" value="${escapeHtml(min)}" placeholder="下限" ${minReadOnly}>
                            </div>
                        </td>
                        
                        <td>
                            <div style="position: relative;">
                                <input type="hidden" class="item-tool-val" value="${toolIds.join(',')}">
                                <div class="tool-display">
                                    ${renderToolBadges(toolIds)}
                                </div>
                            </div>
                        </td>
                        <td>
                            <select class="table-input item-rtype">
                                <option value="NUMERIC" ${typeSelNum}>數值</option>
                                <option value="OKNG" ${typeSelOk}>OK/NG</option>
                            </select>
                        </td>
                        <td class="text-center" style="vertical-align: middle;"><i class="fa fa-trash remove-row-btn" title="刪除"></i></td>
                    </tr>
                `;
                $('#items-table tbody').append(tr);
                
                // 觸發類型變更以設定欄位狀態
                $('#items-table tbody tr:last .item-rtype').trigger('change');
            }

            // 將當前表格內容存回 allItemsData (記憶體中)
            function saveCurrentTableToModel() {
                // 1. 先移除 allItemsData 中屬於當前 process_name 的舊資料
                allItemsData = allItemsData.filter(function(item) {
                    return item.process_name !== currentProcessName;
                });

                // 2. 從 DOM 讀取新資料並加入
                $('#items-table tbody tr').each(function() {
                    var code = $(this).find('.item-code-cell').text();
                    var name = $(this).find('.item-name').val().trim();
                    var spec = $(this).find('.item-spec').val().trim();
                    var max = $(this).find('.item-max').val().trim();
                    var min = $(this).find('.item-min').val().trim();
                    var plus = $(this).find('.item-plus-tol').val().trim();
                    var minus = $(this).find('.item-minus-tol').val().trim();
                    var rtype = $(this).find('.item-rtype').val();
                    var toolVal = $(this).find('.item-tool-val').val();
                    var toolId = toolVal ? toolVal.split(',') : [];

                    if (name) { // 有名稱才存
                        allItemsData.push({
                            process_name: currentProcessName, // 關鍵：綁定當前製程
                            code: code,
                            item_name: name, // 注意後端欄位名稱差異，這裡統一用 item_name 方便
                            standard_text: spec,
                            max_value: max === '' ? null : max,
                            min_value: min === '' ? null : min,
                            plus_tolerance: plus === '' ? null : plus,
                            minus_tolerance: minus === '' ? null : minus,
                            result_type: rtype,
                            tool_ids: toolVal // 保持字串或陣列格式需注意，這裡簡單存
                        });
                    }
                });
            }

            // 重新編號邏輯
            function reindexRows() {
                if (!currentFormType) return;
                
                var isABC = $('input[name="code-style"]:checked').val() === 'ABC';

                $('#items-table tbody tr').each(function(index) {
                    var code = '';
                    if (isABC) {
                        // A=65
                        // 簡單處理 A-Z, 若超過 Z 則 AA, AB... (這裡先簡化 A-Z)
                        code = String.fromCharCode(65 + index); 
                    } else {
                        code = (index + 1).toString();
                    }
                    $(this).find('.item-code-cell').text(code);
                    $(this).data('code', code); // 存入 data 屬性
                });
            }

            // 拖曳結束後重新編號
            // SortableJS 的 onEnd 事件
            var el = document.getElementById('items-table').getElementsByTagName('tbody')[0];
            // 重新綁定 Sortable (因為上面已經綁過一次，這裡只是補充說明邏輯，實際在 ready 頂部綁定)
            // 我們需要在 Sortable 的 onEnd 中呼叫 reindexRows
            // 由於上面已經 new Sortable，這裡需要修改上面的 config (無法直接改)，
            // 建議將上面的 new Sortable 改為包含 onEnd: reindexRows
            
            // 修正上方的 Sortable 初始化
            // (請手動將上方的 Sortable 初始化區塊改為如下)
            /*
            new Sortable(el, {
                handle: '.drag-handle',
                animation: 150,
                onEnd: function() {
                    reindexRows();
                }
            });
            */

            // 結果類型變更連動
            $(document).on('change', '.item-rtype', function() {
                var val = $(this).val();
                var $tr = $(this).closest('tr');
                var $numericFields = $tr.find('.item-spec, .item-max, .item-min, .item-plus-tol, .item-minus-tol');
                
                if (val === 'OKNG') {
                    // 隱藏數值相關欄位
                    $numericFields.css('visibility', 'hidden').val('');
                } else {
                    // 顯示數值相關欄位
                    $numericFields.css('visibility', 'visible');
                    $tr.find('.item-max, .item-min, .item-plus-tol, .item-minus-tol').prop('disabled', false);
                    $tr.find('.item-max').attr('placeholder', '上限');
                    $tr.find('.item-min').attr('placeholder', '下限');
                    $tr.find('.item-plus-tol').attr('placeholder', '上公差');
                    $tr.find('.item-minus-tol').attr('placeholder', '下公差');
                }
            });

            // 4. 刪除列功能
            $(document).on('click', '.remove-row-btn', function() {
                $(this).closest('tr').remove();
                reindexRows();
                if (!isLoading) updateSaveStatus(false);
            });

            // 特殊符號 Modal 處理
            var $targetNameInput = null;
            $(document).on('click', '.special-item-btn', function() {
                $targetNameInput = $(this).closest('.input-group').find('input');
                $('#specialItemModal').modal('show');
            });

            $(document).on('click', '.special-item-select', function(e) {
                e.preventDefault();
                if ($targetNameInput) {
                    var name = $(this).data('name');
                    var symbol = $(this).data('symbol');
                    // 格式： 符號 名稱
                    $targetNameInput.val(symbol + ' ' + name);
                }
                $('#specialItemModal').modal('hide');
            });

            // 建立新版本 Modal 處理
            $('#btn-new-version').click(function() {
                if (!currentPartData) {
                    alert('請先選擇料號');
                    return;
                }
                // 預設帶入
                updateNewVersionLabel();
                $('#newVersionModal').modal('show');
            });

            $('input[name="verSource"]').change(function() {
                updateNewVersionLabel();
            });

            function updateNewVersionLabel() {
                var type = $('input[name="verSource"]:checked').val();
                var val = '';
                if (type === 'REVISION') {
                    val = currentPartData.Revision ? 'Rev ' + currentPartData.Revision : 'Rev (無資料)';
                } else {
                    val = currentPartData.Issue_Date ? currentPartData.Issue_Date : '(無發行日)';
                }
                $('#new-version-label').val(val);
            }

            $('#btn-confirm-version').click(function() {
                var label = $('#new-version-label').val();
                var source = $('input[name="verSource"]:checked').val();
                
                if (label.indexOf('無') > -1) {
                    if(!confirm('版本名稱包含 "無"，確定要建立嗎？')) return;
                }

                $.post('inspection_standard_setting.php', {
                    action: 'create_version',
                    d_id: currentPartData.d_id,
                    version_label: label,
                    source_type: source
                }, function(res) {
                    if (res.success) {
                        $('#newVersionModal').modal('hide');
                        // 重新載入設定以更新下拉選單
                        loadPartSetting(currentPartData);
                    } else {
                        alert(res.message);
                    }
                }, 'json');
            });

            // 5. 儲存功能
            $(document).on('click', '#btn-save-top', function() {
                var verId = $('#version-select').val();
                if (!verId || !currentFormType) {
                    alert('請確認已選擇版本與檢驗表類型');
                    return;
                }

                // 1. 確保當前顯示的表格資料已存入 allItemsData
                saveCurrentTableToModel();

                // 2. 根據目前分頁的 DOM 順序，重新排列 allItemsData
                // 這解決了 "saveCurrentTableToModel" 會把當前分頁資料移到陣列最後面，導致分頁順序跑掉的問題
                var orderedProcessNames = [];
                if ($('#process-tabs-ul').is(':visible')) {
                    $('#process-tabs-ul .process-tab-link').each(function() {
                        orderedProcessNames.push($(this).data('name'));
                    });
                } else {
                    // 若無分頁 (FQC 或未啟用分頁)，視為單一 NULL 製程
                    orderedProcessNames.push('共用標準');
                }

                var reorderedData = [];
                var processCounters = {}; // 用於計算每個製程的編號 (從 1 或 A 開始)

                orderedProcessNames.forEach(function(pName) {
                    var pKey = (pName === '共用標準') ? null : pName;
                    var items = allItemsData.filter(item => item.process_name === pKey);
                    
                    // 判斷該製程分頁的編號格式
                    var groupIsABC = false;
                    if (pKey === currentProcessName) {
                        // 若為當前分頁，依據 Radio Button 選擇
                        groupIsABC = $('input[name="code-style"]:checked').val() === 'ABC';
                    } else {
                        // 若為其他分頁，依據資料內容判斷
                        if (items.length > 0) {
                            var firstCode = items[0].code || items[0].item_code;
                            if (firstCode && isNaN(firstCode)) groupIsABC = true;
                        }
                    }

                    // 3. 強制更新 item_code (寫入資料庫用)
                    items.forEach(function(item, idx) {
                        item.code = groupIsABC ? String.fromCharCode(65 + idx) : (idx + 1).toString();
                    });
                    
                    reorderedData = reorderedData.concat(items);
                });

                // 4. 準備傳送的資料 (轉換格式以符合後端預期)
                var itemsToSend = reorderedData.map(function(item) {
                    return {
                        process_name: item.process_name,
                        code: item.code || item.item_code,
                        name: item.item_name || item.name, // 相容性處理
                        standard: item.standard_text || item.standard,
                        max: item.max_value,
                        min: item.min_value,
                        plus_tolerance: item.plus_tolerance,
                        minus_tolerance: item.minus_tolerance,
                        result_type: item.result_type,
                        tool_id: item.tool_ids // 後端預期 tool_id
                    };
                });

                if (itemsToSend.length === 0) {
                    if(!confirm('未輸入任何檢驗項目，確定要儲存嗎？(這將會清空此版本下的該類型檢驗項目)')) return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中...');

                $.post('inspection_standard_setting.php', {
                    action: 'save_items',
                    version_id: verId,
                    form_type_id: currentFormType.id,
                    items: itemsToSend
                }, function(res) {
                    $btn.prop('disabled', false).html('<i class="fa fa-save"></i> 儲存設定');
                    if (res.success) {
                        updateSaveStatus(true);
                    } else {
                        alert('儲存失敗: ' + res.message);
                    }
                }, 'json');
            });

            // 儲存狀態顯示邏輯
            function updateSaveStatus(isSaved) {
                var $ind = $('#save-status-indicator');
                if (isSaved) {
                    $ind.html('<i class="fa fa-check-circle"></i> 已儲存').removeClass('status-unsaved').addClass('status-saved');
                } else {
                    $ind.html('<i class="fa fa-exclamation-circle"></i> 未儲存').removeClass('status-saved').addClass('status-unsaved');
                }
            }

            // 監聽輸入變更以切換為未儲存狀態
            $(document).on('input change', '#items-table input, #items-table select', function() {
                if (!isLoading) updateSaveStatus(false);
            });

            // HTML 跳脫工具
            function escapeHtml(text) {
                if (text == null) return '';
                var map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
            }

            // =================================================
            // 料號管理功能 (Modal)
            // =================================================
            
            // 開啟 Modal
            $('#btn-manage-part').click(function() {
                var currentId = $('#current-d-id').val();
                
                // 如果當前有選中料號，預設帶入編輯
                if (currentId) {
                    // 從介面獲取目前顯示的資訊 (或重新 fetch，這裡簡化直接用介面值，但需注意版次顯示格式)
                    // 為了準確，我們使用 active 的 list item data
                    var $activeItem = $('.search-result-item.active');
                    if ($activeItem.length > 0) {
                        var data = $activeItem.data('json');
                        
                        // 若 currentPartData 有最新的 gears 資料，則使用它
                        if (currentPartData && currentPartData.d_id == data.d_id && currentPartData.gears) {
                            data.gears = currentPartData.gears;
                        }

                        $('#modal-d-id').val(data.d_id);
                        $('#modal-part-no').val(data.D_Setting_Id);
                        $('#modal-client-search').val(data.Client_Name);
                        $('#modal-customer-id').val(data.Customer_Id);
                        $('#modal-type').val(data.Type || 'N').trigger('change');
                        $('#modal-revision').val(data.Revision);
                        $('#modal-issue-date').val(data.Issue_Date);
                        $('#modal-remark').val(data.Remark);
                        
                        $('#copy-source-group').hide(); // 編輯模式隱藏複製功能
                        $('#btn-delete-part').show();
                        $('#partModalLabel').text('編輯料號');

                        // 載入齒輪資料
                        $('#gear-rows-container').empty();
                        if (data.Type === 'G' && data.gears) {
                            data.gears.forEach(g => addGearRow(g));
                        }
                    } else {
                        resetPartModal();
                    }
                } else {
                    resetPartModal();
                }
                
                $('#partModal').modal('show');
            });

            // 雙擊清除料號輸入框內容
            $('#modal-part-no').dblclick(function() {
                if ($(this).val()) {
                    $(this).val('');
                }
            });

            function resetPartModal() {
                $('#modal-d-id').val('');
                $('#part-form')[0].reset();
                $('#modal-customer-id').val('');
                $('#modal-type').val('N').trigger('change');
                $('#gear-rows-container').empty();
                $('#modal-copy-source-id').val('');
                $('#copy-source-group').show(); // 新增模式顯示複製功能
                $('#btn-delete-part').hide();
                $('#partModalLabel').text('新增料號');
            }

            $('#btn-clear-part').click(function() {
                resetPartModal();
            });

            // 儲存料號
            $('#btn-save-part').click(function() {
                // 收集齒輪資料
                var gears = [];
                if ($('#modal-type').val() === 'G') {
                    $('#gear-rows-container .gear-row').each(function() {
                        gears.push({
                            Module: $(this).find('.gear-module').val(),
                            Teeth: $(this).find('.gear-teeth').val(),
                            Face_Width: $(this).find('.gear-face-width').val(),
                            // 任務 2: 收集新欄位資料
                            Helix_Angle: $(this).find('.hidden-helix-val').val(), // 取計算後的十進位
                            Helix_Angle_Str: $(this).find('.hidden-helix-str').val(), // 取原始字串
                            Helix_Direction: $(this).find('.gear-direction').val(),
                            Profile_Shift_X: $(this).find('.gear-shift-x').val(),
                            Pressure_Angle: $(this).find('.gear-pressure-angle').val(),
                            Workpiece_Length: $(this).find('.gear-length').val(),
                            Gear_Type: $(this).find('.gear-type').val(),
                            // Spec_No: $(this).find('.gear-spec').val(), // 舊欄位若無對應可移除或保留
                            Remark_Gear: $(this).find('.gear-remark').val()
                        });
                    });
                }

                var payload = {
                    action: 'save_part_info',
                    d_id: $('#modal-d-id').val(),
                    part_no: $('#modal-part-no').val(),
                    customer_id: $('#modal-customer-id').val(),
                    type: $('#modal-type').val(),
                    revision: $('#modal-revision').val(),
                    issue_date: $('#modal-issue-date').val(),
                    remark: $('#modal-remark').val(),
                    copy_source_d_id: $('#modal-copy-source-id').val(),
                    gears: JSON.stringify(gears)
                };

                $.post('inspection_standard_setting.php', payload, function(res) {
                    if (res.success) {
                        $('#partModal').modal('hide');
                        // 重新搜尋以更新列表
                        doSearch(); 
                    } else {
                        alert(res.message);
                    }
                }, 'json');
            });

            // 刪除料號
            $('#btn-delete-part').click(function() {
                if (!confirm('確定要刪除此料號嗎？相關的檢驗標準也可能失效。')) return;
                
                $.post('inspection_standard_setting.php', {
                    action: 'delete_part',
                    d_id: $('#modal-d-id').val()
                }, function(res) {
                    if (res.success) {
                        alert(res.message);
                        $('#partModal').modal('hide');
                        $('#current-d-id').val(''); // 清空當前選取
                        $('#setting-area').hide();
                        $('#empty-state').show();
                        doSearch();
                    } else {
                        alert(res.message);
                    }
                }, 'json');
            });

            // 複製來源搜尋
            $('#modal-copy-source-name').on('input', function() {
                var kw = $(this).val().trim();
                if (kw.length < 1) { $('#copy-source-results').hide(); return; }
                
                $.post('inspection_standard_setting.php', { action: 'search_parts', keyword: kw }, function(res) {
                    if (res.success && res.data.length > 0) {
                        var html = '';
                        res.data.forEach(function(item) {
                            html += `<div class="copy-source-item" data-id="${item.d_id}" data-name="${escapeHtml(item.D_Setting_Id)}" style="padding:5px; cursor:pointer; border-bottom:1px solid #eee;">
                                <strong>${escapeHtml(item.D_Setting_Id)}</strong> <small>${escapeHtml(item.Client_Name || '')}</small>
                            </div>`;
                        });
                        $('#copy-source-results').html(html).show();
                    } else {
                        $('#copy-source-results').hide();
                    }
                }, 'json');
            });

            $(document).on('click', '.copy-source-item', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#modal-copy-source-id').val(id);
                $('#modal-copy-source-name').val(name);
                $('#copy-source-results').hide();
            });

            // Event handler for Gear Type change to hide/show Helix Angle
            $(document).on('change', '.gear-type', function() {
                var $row = $(this).closest('.gear-row');
                var selectedType = $(this).val();
                var $helixGroup = $row.find('.helix-angle-group');
                if (selectedType && selectedType.includes('螺旋')) {
                    $helixGroup.slideDown();
                } else {
                    $helixGroup.slideUp();
                    // $helixGroup.find('input').val(''); // Optional: Clear
                }
            });

            // Event handler for Module input blur to auto-format
            $(document).on('blur', '.gear-module', function() {
                let val = $(this).val().trim().toUpperCase();
                if (val !== '' && !isNaN(val.charAt(0))) { // If it starts with a number
                    $(this).val('M' + val);
                } else {
                    $(this).val(val); // Keep as is (e.g., DP10, CP5)
                }
            });
            
            // 任務 2: 螺旋角模式切換與計算 (同步自 ERP_Cost_Analysis.php)
            $(document).on('click', '.btn-mode-dec', function() {
                var $group = $(this).closest('.helix-angle-group');
                $group.find('.mode-decimal').show();
                $group.find('.mode-dms').hide();
                $(this).addClass('active').siblings().removeClass('active');
            });
            $(document).on('click', '.btn-mode-dms', function() {
                var $group = $(this).closest('.helix-angle-group');
                $group.find('.mode-decimal').hide();
                $group.find('.mode-dms').css('display', 'flex');
                $(this).addClass('active').siblings().removeClass('active');
            });

            // 計算並更新隱藏欄位
            $(document).on('input', '.gear-helix-val', function() {
                var val = $(this).val();
                var $group = $(this).closest('.helix-angle-group');
                $group.find('.hidden-helix-val').val(val);
                $group.find('.hidden-helix-str').val(val); // 十進位模式下，字串即數值
            });

            $(document).on('input', '.dms-d, .dms-m, .dms-s', function() {
                var $group = $(this).closest('.helix-angle-group');
                var d = parseFloat($group.find('.dms-d').val()) || 0;
                var m = parseFloat($group.find('.dms-m').val()) || 0;
                var s = parseFloat($group.find('.dms-s').val()) || 0;
                
                var decimal = d + (m / 60) + (s / 3600);
                $group.find('.hidden-helix-val').val(decimal.toFixed(6)); // 儲存計算值
                
                var str = d + "°" + m + "'" + s + '"';
                $group.find('.hidden-helix-str').val(str); // 儲存原始字串
            });

            // 客戶搜尋
            $('#modal-client-search').on('input', function() {
                var kw = $(this).val().trim();
                if (kw.length < 1) { $('#customer-search-results').hide(); return; }
                
                $.post('inspection_standard_setting.php', { action: 'search_customers', keyword: kw }, function(res) {
                    if (res.success && res.data.length > 0) {
                        var html = '';
                        res.data.forEach(function(item) {
                            html += `<div class="customer-search-item" data-id="${item.customer_id}" data-name="${escapeHtml(item.customer)}" style="padding:5px; cursor:pointer; border-bottom:1px solid #eee;">
                                <strong>${escapeHtml(item.customer_id)}</strong> ${escapeHtml(item.customer)}
                            </div>`;
                        });
                        $('#customer-search-results').html(html).show();
                    } else {
                        $('#customer-search-results').hide();
                    }
                }, 'json');
            });

            $(document).on('click', '.customer-search-item', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#modal-customer-id').val(id);
                $('#modal-client-search').val(name); // 顯示名稱
                $('#customer-search-results').hide();
            });

            // 工件種類切換
            $('#modal-type').change(function() {
                if ($(this).val() === 'G') {
                    $('#gear-section').slideDown();
                    if ($('#gear-rows-container').children().length === 0) {
                        addGearRow(); // 預設加一列
                    }
                } else {
                    $('#gear-section').slideUp();
                }
            });

            // 新增齒輪列
            $('#btn-add-gear').click(function() {
                addGearRow();
            });

            function addGearRow(data = {}) {
                const gearType = data.Gear_Type || '';
                const module = data.Module || '';
                const teeth = data.Teeth || '';
                const pa = data.Pressure_Angle || '';
                const width = data.Face_Width || '';
                const length = data.Workpiece_Length || '';
                const remark = data.Remark_Gear || '';
                
                // 任務 2: 處理新欄位與去尾數
                const helix_angle = (data.Helix_Angle !== undefined && data.Helix_Angle !== null && data.Helix_Angle !== '') ? parseFloat(data.Helix_Angle) : ''; 
                const helix_str = data.Helix_Angle_Str || ''; 
                const direction = data.Helix_Direction || ''; 
                const shift_x = data.Profile_Shift_X !== null ? parseFloat(data.Profile_Shift_X) : ''; 
                const showHelix = String(gearType).includes('螺旋');

                const html = `
                    <div class="gear-row" style="padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 10px; background-color: #f9f9f9;">
                        <div class="row">
                            <div class="col-md-3 form-group">
                                <label>齒輪類型</label>
                                <select class="form-control input-sm gear-type">
                                    <option value="" ${gearType === '' ? 'selected' : ''}>請選擇</option>
                                    <option value="直齒" ${gearType === '直齒' ? 'selected' : ''}>直齒</option>
                                    <option value="螺旋" ${gearType === '螺旋' ? 'selected' : ''}>螺旋</option>
                                    <option value="傘齒" ${gearType === '傘齒' ? 'selected' : ''}>傘齒</option>
                                    <option value="鏈輪" ${gearType === '鏈輪' ? 'selected' : ''}>鏈輪</option>
                                    <option value="蝸桿" ${gearType === '蝸桿' ? 'selected' : ''}>蝸桿</option>
                                    <option value="蝸輪" ${gearType === '蝸輪' ? 'selected' : ''}>蝸輪</option>
                                </select>
                            </div>
                            <div class="col-md-3 form-group">
                                <label>模數 (Module)</label>
                                <input type="text" class="form-control input-sm gear-module" value="${escapeHtml(module)}">
                            </div>
                            <div class="col-md-3 form-group">
                                <label>齒數 (Teeth)</label>
                                <input type="number" class="form-control input-sm gear-teeth" value="${escapeHtml(teeth)}">
                            </div>
                            <div class="col-md-3 form-group helix-angle-group" style="display: ${showHelix ? 'block' : 'none'}; background-color: #e9ecef; padding: 5px; border-radius: 4px;">
                                <label>螺旋角</label>
                                <div style="display:flex; gap:5px; margin-bottom:5px;">
                                    <select class="form-control input-sm gear-direction" style="width:70px;">
                                        <option value="" ${direction === '' ? 'selected' : ''}>旋向</option>
                                        <option value="RH" ${direction === 'RH' ? 'selected' : ''}>RH(右)</option>
                                        <option value="LH" ${direction === 'LH' ? 'selected' : ''}>LH(左)</option>
                                    </select>
                                    <div class="btn-group btn-group-xs" data-toggle="buttons">
                                        <label class="btn btn-default active btn-mode-dec"><input type="radio" name="options_${Date.now()}" autocomplete="off" checked> 十進位</label>
                                        <label class="btn btn-default btn-mode-dms"><input type="radio" name="options_${Date.now()}" autocomplete="off"> 度分秒</label>
                                    </div>
                                </div>
                                <div class="mode-decimal">
                                    <input type="number" step="any" class="form-control input-sm gear-helix-val" value="${helix_angle}" placeholder="例如 15.5">
                                </div>
                                <div class="mode-dms" style="display:none; align-items:center; gap:2px;">
                                    <input type="number" class="form-control input-sm dms-d" placeholder="度" style="width:45px;">°
                                    <input type="number" class="form-control input-sm dms-m" placeholder="分" style="width:45px;">'
                                    <input type="number" class="form-control input-sm dms-s" placeholder="秒" style="width:45px;">"
                                </div>
                                <input type="hidden" class="hidden-helix-val" value="${helix_angle}">
                                <input type="hidden" class="hidden-helix-str" value="${helix_str}">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3 form-group">
                                <label>壓力角 (PA)</label>
                                <input type="text" class="form-control input-sm gear-pressure-angle" placeholder="例如: 20" value="${escapeHtml(pa)}">
                            </div>
                            <div class="col-md-3 form-group">
                                <label>齒寬 (W)</label>
                                <input type="number" step="0.01" class="form-control input-sm gear-face-width" placeholder="單位 mm" value="${escapeHtml(width)}">
                            </div>
                            <div class="col-md-3 form-group">
                                <label>工件總長 (L)</label>
                                <input type="number" step="0.01" class="form-control input-sm gear-length" placeholder="單位 mm" value="${escapeHtml(length)}">
                            </div>
                            <div class="col-md-3 form-group">
                                <label>轉位係數 X</label>
                                <input type="number" class="form-control input-sm gear-shift-x" step="any" value="${shift_x}" placeholder="如 0.315">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-9 form-group">
                                <label>備註</label>
                                <input type="text" class="form-control input-sm gear-remark" value="${escapeHtml(remark)}">
                            </div>
                            <div class="col-md-3 form-group" style="text-align:right; padding-top:25px;">
                                 <button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest('.gear-row').remove()"><i class="fa fa-trash"></i> 刪除此齒輪</button>
                            </div>
                        </div>
                    </div>
                `;
                $('#gear-rows-container').append(html);

                // 初始化 DMS 顯示 (若有原始字串且包含度分秒符號)
                if (helix_str && (helix_str.includes('°') || helix_str.includes("'"))) {
                    const $lastRow = $('#gear-rows-container .gear-row').last();
                    $lastRow.find('.btn-mode-dms').trigger('click');
                    const d = helix_str.split('°')[0];
                    const m = helix_str.split('°')[1] ? helix_str.split('°')[1].split("'")[0] : '';
                    const s = helix_str.split("'")[1] ? helix_str.split("'")[1].split('"')[0] : '';
                    $lastRow.find('.dms-d').val(d);
                    $lastRow.find('.dms-m').val(m);
                    $lastRow.find('.dms-s').val(s);
                }
            }

            // =================================================
            // 幾何公差管理功能
            // =================================================
            $('#btn-manage-special-items').click(function() {
                loadSpecialItemsManage();
                $('#specialItemManageModal').modal('show');
            });

            function loadSpecialItemsManage() {
                $('#manage-special-list').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>');
                // 重新從後端獲取最新列表 (這裡重用 init_part_settings 的邏輯有點繞，建議直接用 PHP 渲染或新增 API，
                // 修正：使用專用 action
                $.post('inspection_standard_setting.php', { action: 'get_special_items' }, function(res) {
                    if(res.success) {
                        globalSpecialItems = res.special_items; // 更新全域變數
                        updateSpecialItemModalList(); // 更新選擇 Modal
                        renderSpecialItemsManage();
                    }
                }, 'json');
            }

            function renderSpecialItemsManage() {
                var html = '';
                globalSpecialItems.forEach(function(item) {
                    html += `<div class="list-group-item clearfix">
                        <span class="badge pull-left" style="margin-right: 10px; background-color: #777;">${escapeHtml(item.symbol)}</span>
                        <strong>${escapeHtml(item.name)}</strong> <small class="text-muted">(${escapeHtml(item.code)})</small>
                        <div class="pull-right">
                            <button class="btn btn-xs btn-info btn-edit-si" data-json='${JSON.stringify(item)}'><i class="fa fa-pencil"></i></button>
                            <button class="btn btn-xs btn-danger btn-del-si" data-id="${item.id}"><i class="fa fa-trash"></i></button>
                        </div>
                    </div>`;
                });
                $('#manage-special-list').html(html);
            }

            // 新增/修改 幾何公差
            $('#special-item-form').submit(function(e) {
                e.preventDefault();
                $.post('inspection_standard_setting.php', {
                    action: 'save_special_item',
                    id: $('#si-id').val(),
                    name: $('#si-name').val(),
                    symbol: $('#si-symbol').val(),
                    code: $('#si-code').val()
                }, function(res) {
                    if(res.success) {
                        resetSiForm();
                        loadSpecialItemsManage(); // 重新載入列表
                    } else {
                        alert(res.message);
                    }
                }, 'json');
            });

            // 編輯按鈕
            $(document).on('click', '.btn-edit-si', function() {
                var data = $(this).data('json');
                $('#si-id').val(data.id);
                $('#si-name').val(data.name);
                $('#si-symbol').val(data.symbol);
                $('#si-code').val(data.code); // description mapped to code in PHP
                $('#btn-save-si').text('儲存');
                $('#btn-cancel-si').show();
            });

            // 取消編輯
            $('#btn-cancel-si').click(function() {
                resetSiForm();
            });

            function resetSiForm() {
                $('#si-id').val('');
                $('#special-item-form')[0].reset();
                $('#btn-save-si').text('新增');
                $('#btn-cancel-si').hide();
            }

            // 刪除按鈕
            $(document).on('click', '.btn-del-si', function() {
                if(!confirm('確定要刪除嗎？')) return;
                var id = $(this).data('id');
                $.post('inspection_standard_setting.php', {
                    action: 'delete_special_item',
                    id: id
                }, function(res) {
                    if(res.success) {
                        loadSpecialItemsManage();
                    } else {
                        alert(res.message);
                    }
                }, 'json');
            });

            // =================================================
            // 量具管理功能
            // =================================================
            $('#btn-manage-tools').click(function() {
                loadToolManageData();
                $('#toolManageModal').modal('show');
            });

            function loadToolManageData() {
                $('#tool-cat-list').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>');
                $.post('inspection_standard_setting.php', { action: 'get_tool_manage_data' }, function(res) {
                    if(res.success) {
                        toolManageData = res;
                        // 同步更新全域量具列表 (讓主畫面下拉選單即時更新)
                        globalTools = res.categories;
                        renderToolCategories();
                        // 如果有選中種類，重新渲染右側
                        if(currentToolCatId) {
                            renderToolInstances(currentToolCatId);
                        } else {
                            $('#tool-instance-area').hide();
                            $('#tool-instance-empty').show();
                        }
                    }
                }, 'json');
            }

            function renderToolCategories() {
                var html = '';
                toolManageData.categories.forEach(function(cat) {
                    var activeClass = (cat.QC_Tool_List_id == currentToolCatId) ? 'active' : '';
                    html += `<a href="#" class="list-group-item tool-cat-item ${activeClass}" data-id="${cat.QC_Tool_List_id}" data-name="${escapeHtml(cat.QC_Tool)}" style="cursor: move;">
                        ${escapeHtml(cat.QC_Tool)}
                        <span class="pull-right">
                            <button class="btn btn-xs btn-warning btn-edit-tc" style="margin:0;"><i class="fa fa-pencil"></i></button>
                            <button class="btn btn-xs btn-info btn-replace-tc" title="取代並刪除" style="margin:0;"><i class="fa fa-exchange"></i></button>
                            <button class="btn btn-xs btn-danger btn-del-tc" style="margin:0;"><i class="fa fa-trash"></i></button>
                        </span>
                    </a>`;
                });
                $('#tool-cat-list').html(html);
            }

            // 點選種類
            $(document).on('click', '.tool-cat-item', function(e) {
                if($(e.target).closest('button').length) return; // 避免觸發編輯/刪除按鈕
                e.preventDefault();
                currentToolCatId = $(this).data('id');
                var name = $(this).data('name');
                
                $('#current-cat-name').text(name);
                $('#ti-cat-id').val(currentToolCatId);
                
                renderToolCategories(); // 更新 active 狀態
                renderToolInstances(currentToolCatId);
            });

            function renderToolInstances(catId) {
                $('#tool-instance-empty').hide();
                $('#tool-instance-area').show();
                
                var filteredTools = toolManageData.tools.filter(function(t) {
                    return t.QC_Tool_List_id == catId;
                });

                var html = '';
                if(filteredTools.length === 0) {
                    html = '<tr><td colspan="2" class="text-center text-muted">尚無編號</td></tr>';
                } else {
                    filteredTools.forEach(function(t) {
                        html += `<tr>
                            <td>${escapeHtml(t.Tool_No)}</td>
                            <td>
                                <button class="btn btn-xs btn-info btn-edit-ti" data-id="${t.Tool_id}" data-no="${escapeHtml(t.Tool_No)}"><i class="fa fa-pencil"></i></button>
                                <button class="btn btn-xs btn-danger btn-del-ti" data-id="${t.Tool_id}"><i class="fa fa-trash"></i></button>
                            </td>
                        </tr>`;
                    });
                }
                $('#tool-inst-list').html(html);
            }

            // 種類 CRUD
            $('#tool-cat-form').submit(function(e) {
                e.preventDefault();
                $.post('inspection_standard_setting.php', {
                    action: 'save_tool_category',
                    id: $('#tc-id').val(),
                    name: $('#tc-name').val()
                }, function(res) {
                    if(res.success) {
                        $('#tc-id').val(''); $('#tc-name').val('');
                        $('#btn-save-tc').text('新增'); $('#btn-cancel-tc').hide();
                        loadToolManageData();
                    } else { alert(res.message); }
                }, 'json');
            });

            $(document).on('click', '.btn-edit-tc', function() {
                var $item = $(this).closest('.tool-cat-item');
                $('#tc-id').val($item.data('id'));
                $('#tc-name').val($item.data('name'));
                $('#btn-save-tc').text('儲存'); $('#btn-cancel-tc').show();
            });
            
            $(document).on('click', '.btn-del-tc', function() {
                if(!confirm('確定刪除此種類？')) return;
                $.post('inspection_standard_setting.php', { action: 'delete_tool_category', id: $(this).closest('.tool-cat-item').data('id') }, function(res) {
                    if(res.success) { loadToolManageData(); currentToolCatId = null; } else { alert(res.message); }
                }, 'json');
            });

            $('#btn-cancel-tc').click(function() {
                $('#tc-id').val(''); $('#tc-name').val('');
                $('#btn-save-tc').text('新增'); $(this).hide();
            });

            // 取代並刪除量具
            $(document).on('click', '.btn-replace-tc', function(e) {
                e.stopPropagation();
                var $item = $(this).closest('.tool-cat-item');
                var oldId = $item.data('id');
                var oldName = $item.data('name');
                
                $('#replace-old-id').val(oldId);
                $('#replace-old-name').text(oldName);
                
                var $sel = $('#replace-new-id');
                $sel.empty();
                toolManageData.categories.forEach(function(c) {
                    if (c.QC_Tool_List_id != oldId) {
                        $sel.append(`<option value="${c.QC_Tool_List_id}">${escapeHtml(c.QC_Tool)}</option>`);
                    }
                });
                
                $('#toolReplaceModal').modal('show');
            });

            $('#btn-confirm-replace').click(function() {
                $.post('inspection_standard_setting.php', {
                    action: 'replace_tool_category',
                    old_id: $('#replace-old-id').val(),
                    new_id: $('#replace-new-id').val()
                }, function(res) {
                    if(res.success) { $('#toolReplaceModal').modal('hide'); loadToolManageData(); currentToolCatId = null; }
                    else { alert(res.message); }
                }, 'json');
            });

            // 編號 CRUD
            $('#tool-inst-form').submit(function(e) {
                e.preventDefault();
                $.post('inspection_standard_setting.php', {
                    action: 'save_tool_instance',
                    id: $('#ti-id').val(),
                    cat_id: $('#ti-cat-id').val(),
                    no: $('#ti-no').val()
                }, function(res) {
                    if(res.success) {
                        $('#ti-id').val(''); $('#ti-no').val('');
                        $('#btn-save-ti').text('新增編號'); $('#btn-cancel-ti').hide();
                        loadToolManageData();
                    } else { alert(res.message); }
                }, 'json');
            });

            $(document).on('click', '.btn-edit-ti', function() {
                var id = $(this).data('id');
                var no = $(this).data('no');
                $('#ti-id').val(id);
                $('#ti-no').val(no);
                $('#btn-save-ti').text('儲存');
                $('#btn-cancel-ti').show();
            });

            $('#btn-cancel-ti').click(function() {
                $('#ti-id').val('');
                $('#ti-no').val('');
                $('#btn-save-ti').text('新增編號');
                $(this).hide();
            });

            $(document).on('click', '.btn-del-ti', function() {
                if(!confirm('確定刪除此編號？')) return;
                $.post('inspection_standard_setting.php', { action: 'delete_tool_instance', id: $(this).data('id') }, function(res) {
                    if(res.success) { loadToolManageData(); } else { alert(res.message); }
                }, 'json');
            });

            // =================================================
            // 表單類型管理
            // =================================================
            $('#btn-manage-form-types').click(function() {
                loadFormTypesManage();
                $('#formTypeManageModal').modal('show');
            });

            function loadFormTypesManage() {
                $.post('inspection_standard_setting.php', { action: 'manage_form_types', sub_action: 'list' }, function(res) {
                    if(res.success) {
                        globalFormTypes = res.data; // 更新全域
                        var html = '';
                        res.data.forEach(function(ft) {
                            html += `<tr>
                                <td>${escapeHtml(ft.form_code)}</td>
                                <td>${escapeHtml(ft.form_name)}</td>
                                <td>${escapeHtml(ft.inspection_stage || '')}</td>
                                <td>${escapeHtml(ft.description || '')}</td>
                                <td class="text-center">
                                    <button class="btn btn-xs btn-info btn-edit-ft" data-json='${JSON.stringify(ft)}'><i class="fa fa-pencil"></i></button>
                                    <button class="btn btn-xs btn-danger btn-del-ft" data-id="${ft.form_type_id}"><i class="fa fa-trash"></i></button>
                                </td>
                            </tr>`;
                        });
                        $('#ft-list').html(html);
                        // 同步更新主畫面 Checkbox
                        if($('#version-select').val()) loadVersionForms($('#version-select').val());
                    }
                }, 'json');
            }

            $('#ft-form').submit(function(e) {
                e.preventDefault();
                $.post('inspection_standard_setting.php', {
                    action: 'manage_form_types', sub_action: 'save',
                    id: $('#ft-id').val(), code: $('#ft-code').val(), name: $('#ft-name').val(), desc: $('#ft-desc').val(), stage: $('#ft-stage').val()
                }, function(res) {
                    if(res.success) {
                        $('#ft-id').val(''); $('#ft-form')[0].reset(); $('#btn-save-ft').text('新增'); $('#btn-cancel-ft').hide();
                        loadFormTypesManage();
                    } else { alert(res.message); }
                }, 'json');
            });

            $(document).on('click', '.btn-edit-ft', function() {
                var d = $(this).data('json');
                $('#ft-id').val(d.form_type_id); $('#ft-code').val(d.form_code); $('#ft-name').val(d.form_name); $('#ft-desc').val(d.description); $('#ft-stage').val(d.inspection_stage);
                $('#btn-save-ft').text('儲存'); $('#btn-cancel-ft').show();
            });
            $('#btn-cancel-ft').click(function() { $('#ft-id').val(''); $('#ft-form')[0].reset(); $('#btn-save-ft').text('新增'); $(this).hide(); });
            $(document).on('click', '.btn-del-ft', function() {
                if(!confirm('確定刪除？')) return;
                $.post('inspection_standard_setting.php', { action: 'manage_form_types', sub_action: 'delete', id: $(this).data('id') }, function(res) {
                    if(res.success) loadFormTypesManage(); else alert(res.message);
                }, 'json');
            });

            // =================================================
            // 通用樣板管理
            // =================================================
            function loadTemplates(mode) {
                $.post('inspection_standard_setting.php', { action: 'manage_templates', sub_action: 'list' }, function(res) {
                    if(res.success) {
                        var html = '';
                        if(res.data.length === 0) html = '<div class="list-group-item">無樣板</div>';
                        res.data.forEach(function(t) {
                            if (mode === 'manage') {
                                html += `<div class="list-group-item clearfix">
                                    <strong class="template-name-display">${escapeHtml(t.template_name)}</strong>
                                    <div class="pull-right">
                                        <button class="btn btn-xs btn-info btn-edit-template" data-id="${t.template_id}" data-name="${escapeHtml(t.template_name)}"><i class="fa fa-pencil"></i> 編輯</button>
                                        <button class="btn btn-xs btn-danger btn-del-template" data-id="${t.template_id}"><i class="fa fa-trash"></i> 刪除</button>
                                    </div>
                                </div>`;
                            } else {
                                html += `<div class="list-group-item clearfix">
                                    <strong>${escapeHtml(t.template_name)}</strong>
                                    <div class="pull-right">
                                        <button class="btn btn-xs btn-primary btn-import-append" data-id="${t.template_id}">加入目前頁面</button>
                                        <button class="btn btn-xs btn-success btn-import-newtab" data-id="${t.template_id}" data-name="${escapeHtml(t.template_name)}">新增為製程頁面</button>
                                    </div>
                                </div>`;
                            }
                        });

                        if (mode === 'manage') {
                            $('#template-list').html(html);
                            $('#templateManageModal').modal('show');
                        } else {
                            $('#import-template-list').html(html);
                            $('#importTemplateModal').modal('show');
                        }
                    }
                }, 'json');
            }

            // 建立/更新樣板
            $('#btn-create-template').click(function() {
                var name = $('#new-template-name').val().trim();
                if (!name) { alert('請輸入樣板名稱'); return; }
                
                // 抓取當前表格資料
                saveCurrentTableToModel(); // 確保 allItemsData 最新
                // 過濾出當前製程的項目 (或全部? 這裡假設只存當前顯示的表格內容比較直觀)
                // 重新從 DOM 抓取比較準確，因為 allItemsData 可能包含其他分頁
                var items = [];
                $('#items-table tbody tr').each(function() {
                    var name = $(this).find('.item-name').val().trim();
                    if(name) {
                        items.push({
                            name: name,
                            standard: $(this).find('.item-spec').val(),
                            min: $(this).find('.item-min').val(),
                            max: $(this).find('.item-max').val(),
                            plus_tolerance: $(this).find('.item-plus-tol').val(),
                            minus_tolerance: $(this).find('.item-minus-tol').val(),
                            result_type: $(this).find('.item-rtype').val(),
                            tool_id: $(this).find('.item-tool-val').val()
                        });
                    }
                });

                if(items.length === 0) { alert('表格是空的，無法建立樣板'); return; }

                var payload = { action: 'manage_templates', sub_action: 'save', name: name, items: items };
                if (editingTemplateId) {
                    payload.template_id = editingTemplateId;
                }

                var $btn = $(this);
                var originalText = $btn.text();
                $btn.prop('disabled', true).text('儲存中...');

                $.post('inspection_standard_setting.php', payload, function(res) {
                    $btn.prop('disabled', false).text(originalText);
                    if(res.success) { 
                        // 成功後重置 UI
                        resetTemplateEditMode();
                        loadTemplates('manage');
                        // 顯示暫時性成功提示 (不跳 alert)
                        var $msg = $('<span class="text-success" style="margin-left:10px;"><i class="fa fa-check"></i> 成功</span>').insertAfter($btn);
                        setTimeout(function(){ $msg.fadeOut(function(){ $(this).remove(); }); }, 2000);
                    } else { 
                        alert(res.message); 
                    }
                }, 'json');
            });

            $(document).on('click', '.btn-del-template', function() {
                if(!confirm('確定刪除？')) return;
                $.post('inspection_standard_setting.php', { action: 'manage_templates', sub_action: 'delete', template_id: $(this).data('id') }, function(res) {
                    if(res.success) loadTemplates('manage');
                }, 'json');
            });

            // 編輯樣板按鈕
            $(document).on('click', '.btn-edit-template', function() {
                if(!confirm('這將會清空目前主畫面的表格，並載入此樣板內容以供編輯，確定嗎？')) return;
                
                var tid = $(this).data('id');
                var tname = $(this).data('name');

                // 載入樣板內容到主畫面
                $.post('inspection_standard_setting.php', { action: 'manage_templates', sub_action: 'get_items', template_id: tid }, function(res) {
                    if(res.success) {
                        // 清空當前表格
                        $('#items-table tbody').empty();
                        allItemsData = []; 
                        
                        res.items.forEach(function(item) {
                            addRow(item);
                        });
                        reindexRows();
                        saveCurrentTableToModel(); // 同步

                        // 設定編輯模式 UI
                        editingTemplateId = tid;
                        $('#new-template-name').val(tname);
                        $('#btn-create-template').text('更新樣板').removeClass('btn-success').addClass('btn-warning');
                        $('#btn-cancel-edit-template').show();
                        
                        // 關閉 Modal 讓使用者編輯
                        // $('#templateManageModal').modal('hide'); // 也可以不關閉，但通常編輯需要看表格
                        // 這裡選擇不關閉，因為使用者可能只是想改名，或者需要去主畫面操作
                        // 為了讓使用者知道發生什麼事，我們關閉 Modal 並捲動到表格
                        $('#templateManageModal').modal('hide');
                        $('html, body').animate({ scrollTop: $('#table-container').offset().top - 100 }, 500);
                    }
                }, 'json');
            });

            $('#btn-cancel-edit-template').click(function() {
                resetTemplateEditMode();
            });

            // 匯入：加入目前頁面
            $(document).on('click', '.btn-import-append', function() {
                var tid = $(this).data('id');
                var $btn = $(this); $btn.prop('disabled', true);
                $.post('inspection_standard_setting.php', { action: 'manage_templates', sub_action: 'get_items', template_id: tid }, function(res) {
                    if(res.success) {
                        res.items.forEach(function(item) {
                            // 轉換欄位名稱以符合 addRow
                            item.standard_text = item.standard_text; // addRow uses standard_text
                            item.tool_ids = item.tool_ids;
                            addRow(item);
                        });
                        reindexRows();
                        $('#importTemplateModal').modal('hide');
                        updateSaveStatus(false);
                    }
                    $btn.prop('disabled', false);
                }, 'json');
            });

            // 匯入：新增為製程頁面
            $(document).on('click', '.btn-import-newtab', function() {
                var tid = $(this).data('id');
                var tName = $(this).data('name');
                var $btn = $(this); $btn.prop('disabled', true);

                $.post('inspection_standard_setting.php', { action: 'manage_templates', sub_action: 'get_items', template_id: tid }, function(res) {
                    if(res.success) {
                        // 先保存當前分頁的狀態
                        saveCurrentTableToModel();

                        // 檢查分頁是否已存在
                        var tabExists = allItemsData.some(i => i.process_name === tName);
                        if (tabExists) {
                             if(!confirm('製程頁面 "' + tName + '" 已存在，確定要合併匯入到該頁面嗎？')) {
                                $btn.prop('disabled', false);
                                return;
                            }
                        }

                        // 設定當前 process name 為新樣板的名稱
                        currentProcessName = tName;
                        if (!addedProcessNames.includes(tName)) {
                            addedProcessNames.push(tName);
                        }

                        // 將樣板項目加入 allItemsData
                        res.items.forEach(function(item) {
                            // 強制將匯入項目的製程名稱設為樣板名稱
                            item.process_name = currentProcessName;
                            // 移除舊的 item_id，讓儲存時建立新的
                            delete item.id;
                            delete item.template_id;
                            allItemsData.push(item);
                        });

                        // 重新渲染所有分頁和表格
                        renderProcessTabs();

                        $('#importTemplateModal').modal('hide');
                        updateSaveStatus(false);
                    }
                    $btn.prop('disabled', false);
                }, 'json');
            });

            function resetTemplateEditMode() {
                editingTemplateId = null;
                $('#new-template-name').val('');
                $('#btn-create-template').text('從當前表格建立樣板').removeClass('btn-warning').addClass('btn-success');
                $('#btn-cancel-edit-template').hide();
            }
        });
    </script>
</body>
</html>