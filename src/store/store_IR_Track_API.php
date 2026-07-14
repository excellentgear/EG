<?php
session_start();
header('Content-Type: application/json');

include '../common/DBConnection.php';
include '_setting.php';
include '../common/_config.php';

/*
-- New Data Model SQLs

CREATE TABLE IF NOT EXISTS `ir_flow_config` (
  `config_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '退貨流程設定主鍵',
  `config_name` VARCHAR(50) NOT NULL COMMENT '流程名稱',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否啟用',
  `Created_By` VARCHAR(11) NULL,
  `Created_At` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) COMMENT='退貨流程設定主表';

CREATE TABLE IF NOT EXISTS `ir_flow_config_detail` (
  `detail_id` INT AUTO_INCREMENT PRIMARY KEY,
  `config_id` INT NOT NULL,
  `dept_id` INT NOT NULL,
  `sort_order` INT NOT NULL,
  `include_mode` TINYINT(1) DEFAULT 0 COMMENT '0=含下級, 1=僅下級主管, 2=僅本部門',
  KEY `idx_config_sort` (`config_id`, `sort_order`)
) COMMENT='退貨流程設定明細';

CREATE TABLE IF NOT EXISTS `ir_flow` (
  `flow_id` INT AUTO_INCREMENT PRIMARY KEY,
  `IR_id` INT NOT NULL,
  `dept_id` INT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `user_id` INT NULL COMMENT 'Assignee',
  `status` VARCHAR(20) DEFAULT 'Pending',
  `include_mode` TINYINT(1) DEFAULT 0,
  `note` TEXT NULL,
  `receive_date` datetime DEFAULT NULL,
  `finish_date` datetime DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ir_sort` (`IR_id`, `sort_order`)
) COMMENT='退貨單實際執行流程';
*/

$conn = new DBConnection();
$pdo = $conn->getPDO();
$user_id = $_SESSION['id'] ?? 0;

$action = $_POST['action'] ?? '';

try {
    ensureIrSchema($pdo);

    switch ($action) {
        case 'get_part_data':
            $keyword = $_POST['keyword'] ?? '';
            if (empty($keyword)) {
                echo json_encode(['success' => false, 'message' => 'Keyword empty']);
                exit;
            }
            $sql = "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Revision, d.Issue_Date, c.customer as Client_Name, cs.user_id as sales_id
                    FROM d_setting d
                    LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                    LEFT JOIN customer_sales cs ON c.customer_id = cs.customer_id AND cs.role = 'primary' AND cs.is_active = 1
                    WHERE d.D_Setting_Id LIKE :kw OR d.Drawing_No LIKE :kw LIMIT 20";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':kw' => "%$keyword%"]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get_clients_by_part':
            $d_id = $_POST['d_id'] ?? '';
            $sql = "SELECT DISTINCT Client_Name FROM d_setting WHERE Drawing_No = :did OR D_Setting_Id = :did";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':did' => $d_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
            break;

        case 'get_all_depts':
            // Fetch departments for config
            $sql = "SELECT id, name FROM department ORDER BY sort_order, id";
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get_dept_users':
            // Fetch users for a specific department
            $dept_id = intval($_POST['dept_id']);
            $mode = isset($_POST['mode']) ? intval($_POST['mode']) : 0;
            
            $target_dept_ids = [$dept_id];
            
            if ($mode == 1 || $mode == 2) {
                // Get all departments to find descendants
                $all_depts = $pdo->query("SELECT id, parent_id FROM department")->fetchAll(PDO::FETCH_ASSOC);
                
                // Simple recursive finder
                $to_process = [$dept_id];
                while(!empty($to_process)) {
                    $current = array_shift($to_process);
                    foreach($all_depts as $d) {
                        if ($d['parent_id'] == $current) {
                            $target_dept_ids[] = $d['id'];
                            $to_process[] = $d['id'];
                        }
                    }
                }
                $target_dept_ids = array_unique($target_dept_ids);
            }
            
            $in_clause = implode(',', $target_dept_ids);
            
            if ($mode == 2) {
                // Mode 2: Current Dept Users + (Descendant Users WHERE Position Level IS NOT NULL)
                $sql = "SELECT u.id, u.user_cname, d.name as dept_name, p.name as position_name, m.is_main
                        FROM user u 
                        JOIN user_department_position_map m ON u.id = m.user_id 
                        JOIN department d ON m.department_id = d.id
                        LEFT JOIN position p ON m.position_id = p.id
                        LEFT JOIN position_level pl ON m.position_id = pl.position_id
                        WHERE u.state = 1 
                        AND (
                            m.department_id = :did 
                            OR (m.department_id IN ($in_clause) AND pl.level IS NOT NULL)
                        )
                        ORDER BY d.sort_order, p.sort_order, u.user_cname";
            } else {
                // Mode 0 or 1
                $sql = "SELECT u.id, u.user_cname, d.name as dept_name, p.name as position_name, m.is_main
                        FROM user u 
                        JOIN user_department_position_map m ON u.id = m.user_id 
                        JOIN department d ON m.department_id = d.id
                        LEFT JOIN position p ON m.position_id = p.id
                        WHERE m.department_id IN ($in_clause) AND u.state = 1 
                        ORDER BY d.sort_order, p.sort_order, u.user_cname";
            }

            $stmt = $pdo->prepare($sql);
            if ($mode == 2) {
                $stmt->execute([':did' => $dept_id]);
            } else {
                $stmt->execute();
            }
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get_all_users':
            // Fetch all users sorted by position for sales dropdown
            $sql = "SELECT u.id, u.user_cname, p.name as position_name
                    FROM user u
                    LEFT JOIN user_department_position_map m ON u.id = m.user_id AND m.is_main = 1
                    LEFT JOIN position p ON m.position_id = p.id
                    WHERE u.state = 1
                    ORDER BY p.sort_order, u.user_cname";
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get_sales_users':
            // Fetch sales unit ID from system_parameters
            $stmt = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'SALES_SETTING' AND param_key = 'sales_unit_id'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $salesDeptId = 0;
            if ($row && !empty($row['param_value'])) {
                $config = json_decode($row['param_value'], true);
                $salesDeptId = isset($config['id']) ? intval($config['id']) : 0;
            }

            if ($salesDeptId > 0) {
                // Get all departments to find descendants (recursive)
                $all_depts = $pdo->query("SELECT id, parent_id FROM department")->fetchAll(PDO::FETCH_ASSOC);
                $target_dept_ids = [$salesDeptId];
                $to_process = [$salesDeptId];
                while(!empty($to_process)) {
                    $current = array_shift($to_process);
                    foreach($all_depts as $d) {
                        if ($d['parent_id'] == $current) {
                            $target_dept_ids[] = $d['id'];
                            $to_process[] = $d['id'];
                        }
                    }
                }
                $in_clause = implode(',', array_unique($target_dept_ids));
                
                $sql = "SELECT u.id, u.user_cname, p.name as position_name
                        FROM user u
                        JOIN user_department_position_map m ON u.id = m.user_id
                        LEFT JOIN position p ON m.position_id = p.id
                        WHERE u.state = 1 AND m.department_id IN ($in_clause)
                        ORDER BY p.sort_order, u.user_cname";
                $stmt = $pdo->query($sql);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } else {
                echo json_encode(['success' => true, 'data' => []]);
            }
            break;

        case 'save_flow_config':
            // Create tables if not exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ir_flow_config` (
                `config_id` INT AUTO_INCREMENT PRIMARY KEY,
                `config_name` VARCHAR(50) NOT NULL,
                `is_active` TINYINT(1) DEFAULT 1,
                `Created_By` VARCHAR(11) NULL,
                `Created_At` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) DEFAULT CHARSET=utf8mb4;");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ir_flow_config_detail` (
                `detail_id` INT AUTO_INCREMENT PRIMARY KEY,
                `config_id` INT NOT NULL,
                `dept_id` INT NOT NULL,
                `sort_order` INT NOT NULL,
                `include_mode` TINYINT(1) DEFAULT 0,
                KEY `idx_config_sort` (`config_id`, `sort_order`)
            ) DEFAULT CHARSET=utf8mb4;");

            $config_name = $_POST['config_name'];
            $departments = json_decode($_POST['departments'], true); // Array of {dept_id}

            $pdo->beginTransaction();
            try {
                // Insert Master
                $stmt = $pdo->prepare("INSERT INTO ir_flow_config (config_name, Created_By) VALUES (:name, :user)");
                $stmt->execute([':name' => $config_name, ':user' => $user_id]);
                $config_id = $pdo->lastInsertId();

                // Insert Details
                $stmt_detail = $pdo->prepare("INSERT INTO ir_flow_config_detail (config_id, dept_id, sort_order, include_mode) VALUES (:cid, :did, :sort, :mode)");
                foreach ($departments as $index => $dept) {
                    $stmt_detail->execute([
                        ':cid' => $config_id,
                        ':did' => $dept['dept_id'],
                        ':sort' => $index + 1,
                        ':mode' => $dept['mode'] ?? 0
                    ]);
                }
                $pdo->commit();
                echo json_encode(['success' => true, 'config_id' => $config_id]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'get_flow_configs':
            // Check if table exists first to avoid error on fresh install
            try {
                $stmt = $pdo->query("SELECT config_id, config_name FROM ir_flow_config WHERE is_active = 1 ORDER BY config_id DESC");
                $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $configs]);
            } catch (Exception $e) {
                echo json_encode(['success' => true, 'data' => []]); // Return empty if table doesn't exist
            }
            break;

        case 'save_config':
            $config = $_POST['config'];
            $type = $_POST['type'] ?? 'IR';
            $param_key = ($type === 'NCR') ? 'NCR_DEPT_CONFIG' : 'DEPT_CONFIG';

            $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at) 
                    VALUES ('IR_TRACK', :key, :val, '退貨單部門設定', :user, NOW())
                    ON DUPLICATE KEY UPDATE param_value = :val_upd, updated_by = :user_upd, updated_at = NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':key' => $param_key, ':val' => $config, ':user' => $user_id, ':val_upd' => $config, ':user_upd' => $user_id]);
            echo json_encode(['success' => true]);
            break;

        case 'get_config': 
            $type = $_POST['type'] ?? 'IR';
            $param_key = ($type === 'NCR') ? 'NCR_DEPT_CONFIG' : 'DEPT_CONFIG';
            $sql = "SELECT param_value FROM system_parameters WHERE param_group = 'IR_TRACK' AND param_key = :key";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':key' => $param_key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $config = $row ? json_decode($row['param_value'], true) : [];
            echo json_encode(['success' => true, 'config' => $config]);
            break;

        case 'save_ir':
            // Ensure ir_flow table exists
            $createTableSql = "CREATE TABLE IF NOT EXISTS `ir_flow` (
              `flow_id` INT AUTO_INCREMENT PRIMARY KEY,
              `IR_id` INT NOT NULL,
              `dept_id` INT NOT NULL,
              `sort_order` INT NOT NULL DEFAULT 0,
              `user_id` int(11) DEFAULT NULL,
              `status` varchar(20) DEFAULT 'Pending',
              `include_mode` TINYINT(1) DEFAULT 0,
              `note` TEXT NULL,
              `receive_date` datetime DEFAULT NULL,
              `finish_date` datetime DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_ir_sort` (`IR_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($createTableSql);

            $pdo->beginTransaction();
            try {
                // Insert into ir_track
                $ir_no = $_POST['ir_no'];
                $d_id = $_POST['d_id'];
                $qty = $_POST['qty'];
                $reason = $_POST['reason'];
                $ir_date = $_POST['ir_date'];
                $c_ir = $_POST['c_ir'];
                $sale_assignee = !empty($_POST['sale_assignee']) ? (int)$_POST['sale_assignee'] : null;
                $config_id = $_POST['config_id'] ?? null;
                $departments = isset($_POST['departments']) ? json_decode($_POST['departments'], true) : []; // Fallback for manual selection

                $sql = "INSERT INTO ir_track (IR_no, IR_date, d_id, Qty, IR_ps, C_IR, sale_assignee, Created_At, Created_By)
                        VALUES (:no, :ir_date, :did, :qty, :reason, :c_ir, :assignee, NOW(), :user)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':no' => $ir_no,
                    ':ir_date' => $ir_date,
                    ':did' => $d_id,
                    ':qty' => $qty,
                    ':reason' => $reason,
                    ':c_ir' => $c_ir,
                    ':assignee' => $sale_assignee,
                    ':user' => $user_id
                ]);
                $ir_id = $pdo->lastInsertId();

                // Insert Flow
                if ($config_id) {
                    // Insert from Template
                    $sql_flow = "INSERT INTO ir_flow (IR_id, dept_id, sort_order, include_mode) 
                                 SELECT :irid, dept_id, sort_order, include_mode 
                                 FROM ir_flow_config_detail 
                                 WHERE config_id = :cid";
                    $stmt_flow = $pdo->prepare($sql_flow);
                    $stmt_flow->execute([':irid' => $ir_id, ':cid' => $config_id]);
                } elseif (!empty($departments)) {
                    // Insert Manual Flow (Backward Compatibility or Custom)
                    $sql_dept = "INSERT INTO ir_flow (IR_id, dept_id, user_id, sort_order, status, include_mode) 
                                 VALUES (:irid, :did, :uid, :sort, 0, :mode)";
                    $stmt_dept = $pdo->prepare($sql_dept);
                    $sort = 1;
                    foreach ($departments as $dept) {
                        // Handle both simple ID or object with user_id
                        $stmt_dept->execute([
                            ':irid' => $ir_id,
                            ':did' => is_array($dept) ? $dept['dept_id'] : $dept,
                            ':uid' => is_array($dept) ? ($dept['user_id'] ?? null) : null,
                            ':sort' => $sort++,
                            ':mode' => is_array($dept) ? ($dept['mode'] ?? 0) : 0
                        ]);
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'get_ir_list':
            // Ensure ir_return_type table and column exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ir_return_type` (
                `type_id` tinyint NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `type_name` varchar(30) NOT NULL,
                `is_note` tinyint(1) NOT NULL DEFAULT 0 COMMENT '備註模式',
                `allow_ncr` tinyint(1) NOT NULL DEFAULT 1 COMMENT '允許開立異常單',
                `sort_order` int NOT NULL DEFAULT 0,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `description` varchar(100) NULL
            ) DEFAULT CHARSET=utf8mb4 COMMENT='退貨性質設定表'");
            try { $pdo->query("SELECT return_type_id FROM ir_track LIMIT 1"); }
            catch (Exception $_e) { $pdo->exec("ALTER TABLE ir_track ADD COLUMN `return_type_id` tinyint NULL COMMENT '退貨性質 FK→ir_return_type.type_id'"); }
            try { $pdo->query("SELECT sale_assignee FROM ir_track LIMIT 1"); }
            catch (Exception $_e) { $pdo->exec("ALTER TABLE ir_track ADD COLUMN `sale_assignee` int NULL COMMENT '負責業務 FK→user.id'"); }

            // Fetch IR list with aggregated flow status from ir_flow
            $sql = "SELECT
    t.IR_id,
    t.IR_no,
    DATE_FORMAT(t.IR_date, '%Y/%m/%d') AS IR_date,

    COALESCE(ds.D_Setting_Id, t.d_id) AS d_id,
    COALESCE(cl.customer, t.Client_name) AS Client_Name,

    t.Qty,
    t.IR_ps,
    t.ERP_note,
    t.return_type_id,
    irt.type_name AS return_type_name,
    irt.is_note AS return_type_is_note,
    irt.allow_ncr AS return_type_allow_ncr,
    t.sale_assignee,
    ua.user_cname AS assignee_name,
    t.progress_note,
    t.IR_status,
    t.has_ncr,

    COALESCE(qao.abnormal_order_no, n.ncr_no) AS qa_abnormal_order_no,
    n.status AS ncr_status,

    u.user_cname AS creator,
    u2.user_cname AS modifier_name,
    DATE_FORMAT(t.Modified_At, '%Y/%m/%d %H:%i') AS Modified_At_Str,

    flow.dept_status_raw,
    ncr_flow.ncr_dept_status_raw

FROM ir_track t
LEFT JOIN user u ON t.Created_By = u.id
LEFT JOIN user u2 ON t.Modified_By = u2.id
LEFT JOIN d_setting ds ON ds.d_id = COALESCE(t.d_setting_id, IF(t.d_id REGEXP '^[0-9]+$', CAST(t.d_id AS UNSIGNED), NULL))
LEFT JOIN customer_list cl ON ds.Customer_Id = cl.customer_id
LEFT JOIN ir_return_type irt ON t.return_type_id = irt.type_id
LEFT JOIN user ua ON t.sale_assignee = ua.id
LEFT JOIN qa_ir_ncr n ON n.IR_id = t.IR_id
LEFT JOIN (
    SELECT source_id, MIN(abnormal_order_no) AS abnormal_order_no
    FROM qa_abnormal_order WHERE source_type = 'IR'
    GROUP BY source_id
) qao ON qao.source_id = t.IR_id
LEFT JOIN (
    SELECT 
        f.IR_id,
        GROUP_CONCAT(
            CONCAT(
                COALESCE(d.name, 'Unknown'), ':', 
                COALESCE(du.user_cname, '-'), ':',
                COALESCE(DATE_FORMAT(f.receive_date, '%m/%d'), '-'), ':',
                COALESCE(DATE_FORMAT(f.finish_date, '%m/%d'), '-'), ':',
                COALESCE(f.status, 0), ':',
                f.include_mode, ':',
                f.dept_id
            )
            ORDER BY f.sort_order
            SEPARATOR '|'
        ) AS dept_status_raw
    FROM ir_flow f
    LEFT JOIN department d ON f.dept_id = d.id
    LEFT JOIN user du ON f.user_id = du.id
    GROUP BY f.IR_id
) flow ON flow.IR_id = t.IR_id
LEFT JOIN (
    SELECT 
        n.IR_id,
        GROUP_CONCAT(
            CONCAT(
                COALESCE(d.name, 'Unknown'), ':', 
                COALESCE(du.user_cname, '-'), ':',
                COALESCE(DATE_FORMAT(nf.receive_date, '%m/%d'), '-'), ':',
                COALESCE(DATE_FORMAT(nf.return_date, '%m/%d'), '-'), ':',
                COALESCE(nf.status, 'Pending'), ':',
                nf.include_mode, ':',
                nf.dept_id
            )
            ORDER BY nf.sort_order
            SEPARATOR '|'
        ) AS ncr_dept_status_raw
    FROM qa_ir_ncr_flow nf
    JOIN qa_ir_ncr n ON nf.ncr_id = n.ncr_id
    LEFT JOIN department d ON nf.dept_id = d.id
    LEFT JOIN user du ON nf.user_id = du.id
    GROUP BY n.IR_id
) ncr_flow ON ncr_flow.IR_id = t.IR_id

ORDER BY t.IR_date DESC, t.IR_id DESC;
";
            
            $stmt = $pdo->query($sql);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process dept_status_raw for frontend
            foreach ($data as &$row) {
                $statuses = [];
                if ($row['dept_status_raw']) {
                    $parts = explode('|', $row['dept_status_raw']);
                    foreach ($parts as $part) {
                        // Ensure we have enough parts
                        $p = explode(':', $part);
                        $statuses[] = [
                            'dept' => $p[0] ?? 'Unknown',
                            'user' => $p[1] ?? '-',
                            'recv' => $p[2] ?? '-',
                            'done' => $p[3] ?? '-',
                            'status' => $p[4] ?? 'Pending',
                            'mode' => $p[5] ?? 0,
                            'dept_id' => $p[6] ?? 0
                        ];
                    }
                }
                $row['dept_status'] = $statuses;
                unset($row['dept_status_raw']);

                // Process ncr_dept_status_raw
                $ncr_statuses = [];
                if ($row['ncr_dept_status_raw']) {
                    $parts = explode('|', $row['ncr_dept_status_raw']);
                    foreach ($parts as $part) {
                        $p = explode(':', $part);
                        $ncr_statuses[] = [
                            'dept' => $p[0] ?? 'Unknown',
                            'user' => $p[1] ?? '-',
                            'recv' => $p[2] ?? '-',
                            'done' => $p[3] ?? '-',
                            'status' => $p[4] ?? 'Pending',
                            'mode' => $p[5] ?? 0,
                            'dept_id' => $p[6] ?? 0
                        ];
                    }
                }
                $row['ncr_dept_status'] = $ncr_statuses;
                unset($row['ncr_dept_status_raw']);
            }
            
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'update_progress':
            $ir_id = $_POST['ir_id'];
            $note = $_POST['note'];
            $sql = "UPDATE ir_track SET progress_note = :note, Modified_By = :uid, Modified_At = NOW() WHERE IR_id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':note' => $note, ':uid' => $user_id, ':id' => $ir_id]);
            
            // Fetch updated info
            $sql = "SELECT u.user_cname, DATE_FORMAT(t.Modified_At, '%Y/%m/%d %H:%i') as mod_time 
                    FROM ir_track t 
                    LEFT JOIN user u ON t.Modified_By = u.id 
                    WHERE t.IR_id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $ir_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'modifier' => $row['user_cname'] ?? '', 
                'time' => $row['mod_time'] ?? ''
            ]);
            break;

        case 'create_ncr':
            // Ensure tables exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS `qa_ir_ncr` (
                `ncr_id` INT AUTO_INCREMENT PRIMARY KEY,
                `IR_id` INT NOT NULL,
                `ncr_no` VARCHAR(50) NOT NULL,
                `final_decision_dept_id` INT NULL,
                `final_decision_user_id` INT NULL,
                `final_decision_mode` TINYINT(1) DEFAULT 0,
                `qa_ps` TEXT,
                `status` VARCHAR(20) DEFAULT 'Pending',
                `Created_By` VARCHAR(11) NULL,
                `Created_At` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `Modified_By` VARCHAR(11) NULL,
                `Modified_At` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_ir_ncr` (`IR_id`)
            ) DEFAULT CHARSET=utf8mb4;");

            // 檢查並新增 abnormal_type_id 欄位
            try {
                $pdo->query("SELECT abnormal_type_id FROM qa_ir_ncr LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("ALTER TABLE qa_ir_ncr ADD COLUMN abnormal_type_id INT NULL COMMENT '異常種類ID' AFTER ncr_no");
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS `qa_ir_ncr_flow` (
                `flow_id` INT AUTO_INCREMENT PRIMARY KEY,
                `ncr_id` INT NOT NULL,
                `dept_id` INT NOT NULL,
                `user_id` INT NULL,
                `include_mode` TINYINT(1) DEFAULT 0,
                `status` VARCHAR(20) DEFAULT 'Pending',
                `receive_date` DATETIME NULL,
                `return_date` DATETIME NULL,
                `note` TEXT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `Created_At` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `Modified_At` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_ncr_flow` (`ncr_id`)
            ) DEFAULT CHARSET=utf8mb4;");

            $ir_id = $_POST['ir_id'];
            $ncr_no = $_POST['ncr_no'];
            $qa_ps = $_POST['qa_ps'];
            $abnormal_type_id = !empty($_POST['abnormal_type_id']) ? $_POST['abnormal_type_id'] : null;
            $final_dept = !empty($_POST['final_dept_id']) ? $_POST['final_dept_id'] : null;
            $final_user = !empty($_POST['final_user_id']) ? $_POST['final_user_id'] : null;
            $final_mode = !empty($_POST['final_mode']) ? $_POST['final_mode'] : 0;
            $departments = json_decode($_POST['departments'], true);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO qa_ir_ncr (IR_id, ncr_no, abnormal_type_id, final_decision_dept_id, final_decision_user_id, final_decision_mode, qa_ps, Created_By) VALUES (:irid, :no, :typeid, :fdept, :fuser, :fmode, :ps, :user)");
                $stmt->execute([':irid' => $ir_id, ':no' => $ncr_no, ':typeid' => $abnormal_type_id, ':fdept' => $final_dept, ':fuser' => $final_user, ':fmode' => $final_mode, ':ps' => $qa_ps, ':user' => $user_id]);
                $ncr_id = $pdo->lastInsertId();

                $stmt_flow = $pdo->prepare("INSERT INTO qa_ir_ncr_flow (ncr_id, dept_id, user_id, include_mode, sort_order) VALUES (:ncrid, :did, :uid, :mode, :sort)");
                $sort = 1;
                foreach ($departments as $dept) {
                    $stmt_flow->execute([':ncrid' => $ncr_id, ':did' => $dept['dept_id'], ':uid' => !empty($dept['user_id']) ? $dept['user_id'] : null, ':mode' => $dept['mode'] ?? 0, ':sort' => $sort++]);
                }
                $pdo->prepare("UPDATE ir_track SET has_ncr = 1 WHERE IR_id = ?")->execute([$ir_id]);
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'update_ncr_full':
            $ncr_id = $_POST['ncr_id'];
            $ncr_no = $_POST['ncr_no'];
            $qa_ps = $_POST['qa_ps'];
            $abnormal_type_id = !empty($_POST['abnormal_type_id']) ? $_POST['abnormal_type_id'] : null;
            $departments = json_decode($_POST['departments'], true);

            $pdo->beginTransaction();
            try {
                // 1. Update Header
                $stmt = $pdo->prepare("UPDATE qa_ir_ncr SET ncr_no = :no, abnormal_type_id = :typeid, qa_ps = :ps, Modified_By = :user, Modified_At = NOW() WHERE ncr_id = :id");
                $stmt->execute([':no' => $ncr_no, ':typeid' => $abnormal_type_id, ':ps' => $qa_ps, ':user' => $user_id, ':id' => $ncr_id]);

                // 2. Sync Flow
                $stmt = $pdo->prepare("SELECT flow_id, dept_id, status FROM qa_ir_ncr_flow WHERE ncr_id = ?");
                $stmt->execute([$ncr_id]);
                $existing_flows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $existing_dept_map = [];
                foreach ($existing_flows as $f) {
                    $existing_dept_map[$f['dept_id']] = $f;
                }

                $new_dept_ids = [];
                $sort = 1;
                foreach ($departments as $dept) {
                    $dept_id = $dept['dept_id'];
                    $new_dept_ids[] = $dept_id;
                    $target_user_id = !empty($dept['user_id']) ? $dept['user_id'] : null;
                    $mode = $dept['mode'] ?? 0;

                    if (isset($existing_dept_map[$dept_id])) {
                        // Update existing: only update user/mode/sort
                        $f = $existing_dept_map[$dept_id];
                        $upd = $pdo->prepare("UPDATE qa_ir_ncr_flow SET user_id = ?, include_mode = ?, sort_order = ? WHERE flow_id = ?");
                        $upd->execute([$target_user_id, $mode, $sort, $f['flow_id']]);
                    } else {
                        // Insert new
                        $ins = $pdo->prepare("INSERT INTO qa_ir_ncr_flow (ncr_id, dept_id, user_id, include_mode, sort_order, status, Created_At) VALUES (?, ?, ?, ?, ?, 'Pending', NOW())");
                        $ins->execute([$ncr_id, $dept_id, $target_user_id, $mode, $sort]);
                    }
                    $sort++;
                }

                // 3. Delete removed departments (only if Pending)
                foreach ($existing_flows as $f) {
                    if (!in_array($f['dept_id'], $new_dept_ids) && $f['status'] == 'Pending') {
                        $del = $pdo->prepare("DELETE FROM qa_ir_ncr_flow WHERE flow_id = ?");
                        $del->execute([$f['flow_id']]);
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'get_ncr_info':
            $ir_id = $_POST['ir_id'];
            $ncr = $pdo->query("
                SELECT n.*, t.type_name as abnormal_type_name 
                FROM qa_ir_ncr n 
                LEFT JOIN qa_abnormal_type t ON n.abnormal_type_id = t.type_id 
                WHERE n.IR_id = $ir_id
            ")->fetch(PDO::FETCH_ASSOC);
            if ($ncr) {
                // Alias note as reply_content for frontend compatibility
                $ncr['flow'] = $pdo->query("SELECT f.*, f.note as reply_content, d.name as dept_name, u.user_cname as receiver_name FROM qa_ir_ncr_flow f LEFT JOIN department d ON f.dept_id = d.id LEFT JOIN user u ON f.user_id = u.id WHERE f.ncr_id = {$ncr['ncr_id']} ORDER BY f.sort_order")->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'data' => $ncr]);
            break;

        case 'get_next_ncr_no':
            $roc_year = date('Y') - 1911;
            $date_part = date('md');
            $prefix = sprintf("Q%03d%s", $roc_year, $date_part);
            
            $sql = "SELECT ncr_no FROM qa_ir_ncr WHERE ncr_no LIKE :prefix ORDER BY ncr_no DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':prefix' => $prefix . '%']);
            $last_no = $stmt->fetchColumn();
            
            $new_seq = 1;
            if ($last_no && strlen($last_no) >= 11) {
                $seq_str = substr($last_no, -3);
                if (is_numeric($seq_str)) {
                    $new_seq = intval($seq_str) + 1;
                }
            }
            
            echo json_encode(['success' => true, 'ncr_no' => $prefix . sprintf("%03d", $new_seq)]);
            break;

        case 'update_ncr_flow':
            $flow_id = $_POST['flow_id'];
            $type = $_POST['type'];
            
            if ($type == 'save_reply') {
                $content = $_POST['reply_content'];
                $sql = "UPDATE qa_ir_ncr_flow SET note = ? WHERE flow_id = ?";
                $pdo->prepare($sql)->execute([$content, $flow_id]);
            } elseif ($type == 'return') {
                $content = $_POST['reply_content'] ?? null;
                $sql = "UPDATE qa_ir_ncr_flow SET status = 'Returned', return_date = NOW(), note = ? WHERE flow_id = ?";
                $pdo->prepare($sql)->execute([$content, $flow_id]);
            } elseif ($type == 'rollback') {
                $target_status = $_POST['target_status'];
                
                $update_fields = "status = :target_status";
                $params = [':target_status' => $target_status, ':flow_id' => $flow_id];

                if ($target_status === 'Pending') {
                    $update_fields .= ", receive_date = NULL";
                } elseif ($target_status === 'Received') {
                    $update_fields .= ", return_date = NULL";
                }

                $sql = "UPDATE qa_ir_ncr_flow SET $update_fields WHERE flow_id = :flow_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                // receive
                $target_user_id = $_POST['target_user_id'] ?? null;
                if ($target_user_id) {
                    $sql = "UPDATE qa_ir_ncr_flow SET status = 'Received', receive_date = NOW(), user_id = ? WHERE flow_id = ? AND receive_date IS NULL";
                    $pdo->prepare($sql)->execute([$target_user_id, $flow_id]);
                } else {
                    $sql = "UPDATE qa_ir_ncr_flow SET status = 'Received', receive_date = NOW() WHERE flow_id = ? AND receive_date IS NULL";
                    $pdo->prepare($sql)->execute([$flow_id]);
                }
            }
            echo json_encode(['success' => true]);
            break;

        case 'get_abnormal_types':
            // 確保資料表存在
            $pdo->exec("CREATE TABLE IF NOT EXISTS `qa_abnormal_type` (
                `type_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '異常分類主鍵',
                `type_name` VARCHAR(50) NOT NULL COMMENT '異常分類名稱',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
                `sort_order` INT NOT NULL DEFAULT 0 COMMENT '顯示排序'
            ) COMMENT='品質異常分類主表';");

            $sql = "SELECT * FROM qa_abnormal_type WHERE is_active = 1 ORDER BY sort_order, type_id";
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'manage_abnormal_type':
            $sub_action = $_POST['sub_action'];
            if ($sub_action == 'add') {
                $name = $_POST['name'];
                $stmt = $pdo->prepare("INSERT INTO qa_abnormal_type (type_name, sort_order) VALUES (?, 0)");
                $stmt->execute([$name]);
            } elseif ($sub_action == 'update') {
                $id = $_POST['id'];
                $name = $_POST['name'];
                $active = $_POST['active'];
                $stmt = $pdo->prepare("UPDATE qa_abnormal_type SET type_name = ?, is_active = ? WHERE type_id = ?");
                $stmt->execute([$name, $active, $id]);
            } elseif ($sub_action == 'delete') {
                $id = $_POST['id'];
                $stmt = $pdo->prepare("DELETE FROM qa_abnormal_type WHERE type_id = ?");
                $stmt->execute([$id]);
            } elseif ($sub_action == 'get_all') {
                 $stmt = $pdo->query("SELECT * FROM qa_abnormal_type ORDER BY sort_order, type_id");
                 echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                 exit;
            }
            echo json_encode(['success' => true]);
            break;

        case 'resend_ncr_flow':
            $flow_id = $_POST['flow_id'];
            // 取得原流程資訊
            $stmt = $pdo->prepare("SELECT ncr_id, dept_id, include_mode FROM qa_ir_ncr_flow WHERE flow_id = ?");
            $stmt->execute([$flow_id]);
            $flow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($flow) {
                // 取得目前最大排序
                $stmt = $pdo->prepare("SELECT MAX(sort_order) FROM qa_ir_ncr_flow WHERE ncr_id = ?");
                $stmt->execute([$flow['ncr_id']]);
                $max_sort = $stmt->fetchColumn();
                
                // 新增一筆新的流程 (再次送交)
                $stmt = $pdo->prepare("INSERT INTO qa_ir_ncr_flow (ncr_id, dept_id, include_mode, sort_order, status, Created_At) VALUES (?, ?, ?, ?, 'Pending', NOW())");
                $stmt->execute([$flow['ncr_id'], $flow['dept_id'], $flow['include_mode'], $max_sort + 1]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Flow not found']);
            }
            break;

        // ── 退貨性質管理 ────────────────────────────────────────────
        case 'get_ir_return_types':
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ir_return_type` (
                `type_id` tinyint NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `type_name` varchar(30) NOT NULL,
                `is_note` tinyint(1) NOT NULL DEFAULT 0,
                `allow_ncr` tinyint(1) NOT NULL DEFAULT 1,
                `sort_order` int NOT NULL DEFAULT 0,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `description` varchar(100) NULL
            ) DEFAULT CHARSET=utf8mb4");
            $stmt = $pdo->query("SELECT * FROM ir_return_type ORDER BY sort_order, type_id");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save_ir_return_type':
            $id       = $_POST['type_id'] ?? '';
            $name     = trim($_POST['type_name'] ?? '');
            $is_note  = !empty($_POST['is_note']) ? 1 : 0;
            $allow_ncr= !empty($_POST['allow_ncr']) ? 1 : 0;
            $sort     = (int)($_POST['sort_order'] ?? 0);
            $active   = !empty($_POST['is_active']) ? 1 : 0;
            $desc     = trim($_POST['description'] ?? '');
            if (!$name) { echo json_encode(['success'=>false,'message'=>'名稱為必填']); break; }
            if ($id !== '') {
                $pdo->prepare("UPDATE ir_return_type SET type_name=?,is_note=?,allow_ncr=?,sort_order=?,is_active=?,description=? WHERE type_id=?")
                    ->execute([$name,$is_note,$allow_ncr,$sort,$active,$desc?:null,$id]);
            } else {
                $pdo->prepare("INSERT INTO ir_return_type (type_name,is_note,allow_ncr,sort_order,is_active,description) VALUES (?,?,?,?,?,?)")
                    ->execute([$name,$is_note,$allow_ncr,$sort,$active,$desc?:null]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete_ir_return_type':
            $id = (int)($_POST['type_id'] ?? 0);
            $pdo->prepare("DELETE FROM ir_return_type WHERE type_id=?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        case 'update_ir_return_type':
            $ir_ids  = json_decode($_POST['ir_ids'] ?? '[]', true);
            $type_id = $_POST['return_type_id'] ?? '';
            if (empty($ir_ids)) { echo json_encode(['success'=>false,'message'=>'無選取']); break; }
            $in = implode(',', array_map('intval', $ir_ids));
            $val = ($type_id !== '' && $type_id !== null) ? (int)$type_id : 'NULL';
            $pdo->exec("UPDATE ir_track SET return_type_id=$val WHERE IR_id IN ($in)");
            echo json_encode(['success' => true]);
            break;

        case 'update_ir_status':
            $ir_ids = json_decode($_POST['ir_ids'] ?? '[]', true);
            $status = (int)($_POST['status'] ?? 0);
            if (empty($ir_ids)) { echo json_encode(['success'=>false,'message'=>'無選取']); break; }
            $in = implode(',', array_map('intval', $ir_ids));
            $pdo->exec("UPDATE ir_track SET IR_status=$status WHERE IR_id IN ($in)");
            echo json_encode(['success' => true]);
            break;

        case 'update_ir_assignee':
            $ir_ids     = json_decode($_POST['ir_ids'] ?? '[]', true);
            $assignee_id = $_POST['assignee_id'] ?? '';
            if (empty($ir_ids)) { echo json_encode(['success'=>false,'message'=>'無選取']); break; }
            $in = implode(',', array_map('intval', $ir_ids));
            $val = ($assignee_id !== '' && $assignee_id !== null) ? (int)$assignee_id : 'NULL';
            $pdo->exec("UPDATE ir_track SET sale_assignee=$val WHERE IR_id IN ($in)");
            echo json_encode(['success' => true]);
            break;

        // ── 業務進度回覆記錄 ─────────────────────────────────────────
        case 'get_progress_notes':
            $irId = (int)($_POST['ir_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT n.*, u.user_cname AS created_by_name, uu.user_cname AS updated_by_name
                FROM ir_progress_notes n
                LEFT JOIN user u  ON u.id  = n.created_by
                LEFT JOIN user uu ON uu.id = n.updated_by
                WHERE n.ir_id = ?
                ORDER BY n.created_at ASC
            ");
            $stmt->execute([$irId]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($notes as &$note) {
                $aStmt = $pdo->prepare("SELECT id, file_name, original_name FROM ir_attachments WHERE note_id=? ORDER BY id");
                $aStmt->execute([$note['id']]);
                $note['attachments'] = $aStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'data' => $notes]);
            break;

        case 'save_progress_note':
            $irId     = (int)($_POST['ir_id'] ?? 0);
            $irNo     = trim($_POST['ir_no'] ?? '');
            $noteId   = (int)($_POST['note_id'] ?? 0);
            $noteText = trim($_POST['note_text'] ?? '');

            $pdo->beginTransaction();
            if ($noteId > 0) {
                $pdo->prepare("UPDATE ir_progress_notes SET note_text=?, updated_by=?, updated_at=NOW() WHERE id=? AND ir_id=?")
                    ->execute([$noteText, $user_id, $noteId, $irId]);
            } else {
                $pdo->prepare("INSERT INTO ir_progress_notes (ir_id, note_text, created_by) VALUES (?,?,?)")
                    ->execute([$irId, $noteText, $user_id]);
                $noteId = (int)$pdo->lastInsertId();
            }
            if (!empty($_FILES['files']['name'][0])) {
                saveIrFiles($pdo, $_FILES['files'], $irId, $irNo, $noteId, $user_id);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'note_id' => $noteId]);
            break;

        case 'delete_progress_note':
            $noteId = (int)($_POST['note_id'] ?? 0);
            $stmt   = $pdo->prepare("SELECT id, file_path, ir_id FROM ir_attachments WHERE note_id=?");
            $stmt->execute([$noteId]);
            $atts   = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $irId   = 0;
            foreach ($atts as $att) {
                if (file_exists($att['file_path'])) @unlink($att['file_path']);
                $irId = (int)$att['ir_id'];
            }
            $pdo->prepare("DELETE FROM ir_attachments WHERE note_id=?")->execute([$noteId]);
            $pdo->prepare("DELETE FROM ir_progress_notes WHERE id=?")->execute([$noteId]);
            if ($irId) cleanupIrFolder($pdo, $irId);
            echo json_encode(['success' => true]);
            break;

        // ── 退貨單本身附件 ───────────────────────────────────────────
        case 'get_ir_attachments':
            $irId = (int)($_POST['ir_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, file_name, original_name, created_at FROM ir_attachments WHERE ir_id=? AND note_id IS NULL ORDER BY id");
            $stmt->execute([$irId]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'upload_ir_attachment':
            $irId = (int)($_POST['ir_id'] ?? 0);
            $irNo = trim($_POST['ir_no'] ?? '');
            if (empty($_FILES['file']['tmp_name'])) { echo json_encode(['success'=>false,'message'=>'無附件']); break; }
            $folder = getIrFolder($irNo ?: (string)$irId);
            if (!is_dir($folder) && !mkdir($folder, 0777, true)) {
                echo json_encode(['success'=>false,'message'=>'無法建立資料夾：'.$folder]); break;
            }
            echo json_encode(saveOneIrFile($pdo, $_FILES['file'], $irId, $folder, null, $user_id));
            break;

        case 'upload_note_attachment':
            $noteId = (int)($_POST['note_id'] ?? 0);
            $irId   = (int)($_POST['ir_id']   ?? 0);
            $irNo   = trim($_POST['ir_no'] ?? '');
            if (empty($_FILES['file']['tmp_name'])) { echo json_encode(['success'=>false,'message'=>'無附件']); break; }
            $folder = getIrFolder($irNo ?: (string)$irId);
            if (!is_dir($folder) && !mkdir($folder, 0777, true)) {
                echo json_encode(['success'=>false,'message'=>'無法建立資料夾：'.$folder]); break;
            }
            echo json_encode(saveOneIrFile($pdo, $_FILES['file'], $irId, $folder, $noteId, $user_id));
            break;

        case 'delete_ir_attachment':
            $attachId = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT file_path, ir_id FROM ir_attachments WHERE id=?");
            $stmt->execute([$attachId]);
            $att = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($att) {
                if (file_exists($att['file_path'])) @unlink($att['file_path']);
                $pdo->prepare("DELETE FROM ir_attachments WHERE id=?")->execute([$attachId]);
                cleanupIrFolder($pdo, (int)$att['ir_id']);
            }
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ─── 輔助函式 ────────────────────────────────────────────────

function ensureIrSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ir_progress_notes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ir_id` INT NOT NULL,
        `note_text` TEXT NOT NULL,
        `created_by` INT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_by` INT NULL,
        `updated_at` DATETIME NULL,
        INDEX `idx_ir` (`ir_id`)
    ) DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `ir_attachments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ir_id` INT NOT NULL,
        `note_id` INT NULL COMMENT 'NULL=退貨單附件 非NULL=進度回覆附件',
        `file_name` VARCHAR(255) NOT NULL,
        `original_name` VARCHAR(255) NOT NULL,
        `file_path` VARCHAR(500) NOT NULL,
        `created_by` INT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_ir`   (`ir_id`),
        INDEX `idx_note` (`note_id`)
    ) DEFAULT CHARSET=utf8mb4");
}

function getIrFolder(string $irNo): string {
    return 'Z:\\BOM\\ERP\\業務\\退貨資料' . DIRECTORY_SEPARATOR . $irNo;
}

function saveOneIrFile(PDO $pdo, array $f, int $irId, string $folder, ?int $noteId, int $userId): array {
    $origName = basename($f['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $base     = preg_replace('/[^\w\x{4e00}-\x{9fff}]/u', '_', pathinfo($origName, PATHINFO_FILENAME));
    $newName  = $base . '_' . time() . '.' . $ext;
    $dest     = $folder . DIRECTORY_SEPARATOR . $newName;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        return ['success' => false, 'message' => '檔案儲存失敗'];
    }
    $pdo->prepare("INSERT INTO ir_attachments (ir_id, note_id, file_name, original_name, file_path, created_by) VALUES (?,?,?,?,?,?)")
        ->execute([$irId, $noteId, $newName, $origName, $dest, $userId]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId(), 'file_name' => $newName, 'original_name' => $origName];
}

function saveIrFiles(PDO $pdo, array $files, int $irId, string $irNo, ?int $noteId, int $userId): void {
    $folder = getIrFolder($irNo ?: (string)$irId);
    if (!is_dir($folder)) mkdir($folder, 0777, true);
    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        saveOneIrFile($pdo, [
            'name'     => $files['name'][$i],
            'tmp_name' => $files['tmp_name'][$i],
        ], $irId, $folder, $noteId, $userId);
    }
}

function cleanupIrFolder(PDO $pdo, int $irId): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ir_attachments WHERE ir_id=?");
    $stmt->execute([$irId]);
    if ($stmt->fetchColumn() > 0) return;

    $stmt = $pdo->prepare("SELECT IR_no FROM ir_track WHERE IR_id=?");
    $stmt->execute([$irId]);
    $irNo = $stmt->fetchColumn();
    if (!$irNo) return;

    $folder = getIrFolder($irNo);
    if (is_dir($folder) && count(array_diff(scandir($folder), ['.','..'])) === 0) {
        @rmdir($folder);
    }
}
?>
