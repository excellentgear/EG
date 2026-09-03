<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// c:\MAMP\htdocs\EGsystem\views\Sales\NewOrder_Track.php

// ── 延長 Session 到 12 小時 ──────────────────────────────────────────────
ini_set('session.gc_maxlifetime', 43200);   // 43200 秒 = 12 小時
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

function safe_html($val) {
    return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8');
}

// 將報價項目 process_notes（逗號分隔的 sub_tag_id）轉為製程名稱字串（・連接），寫回每列 'processes'
function eg_build_process_names($pdo, array $rows) {
    $allSubTagIds = [];
    foreach ($rows as $r) {
        if (!empty($r['process_notes'])) {
            foreach (explode(',', $r['process_notes']) as $sid) {
                $sid = intval(trim($sid));
                if ($sid > 0) $allSubTagIds[$sid] = true;
            }
        }
    }
    $subTagMap = [];
    if (!empty($allSubTagIds)) {
        try {
            $sids = array_keys($allSubTagIds);
            $ph = implode(',', array_fill(0, count($sids), '?'));
            $stStmt = $pdo->prepare("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag WHERE sub_tag_id IN ($ph)");
            $stStmt->execute($sids);
            foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
                $subTagMap[intval($st['sub_tag_id'])] = $st['sub_tag_name'];
            }
        } catch (Exception $eIgnore) {}
    }
    foreach ($rows as &$r) {
        if (!empty($r['process_notes'])) {
            $names = [];
            foreach (explode(',', $r['process_notes']) as $sid) {
                $sid = intval(trim($sid));
                if ($sid > 0 && isset($subTagMap[$sid])) $names[] = $subTagMap[$sid];
            }
            $r['processes'] = implode('・', $names);
        } else {
            $r['processes'] = '';
        }
    }
    unset($r);
    return $rows;
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';
require_once '../../src/common/part_alias_lib.php';
require_once '../../src/common/quote_customer_lib.php';   // 訂單 ↔ 來源OP單客戶連動（唯一實作）

$conn = new DBConnection();

// --- AJAX: 設計部門設定相關處理 ---
// --- AND OTHER AJAX ACTIONS FOR THIS PAGE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // 為了讓 AJAX 能存取 DB，在此重新建立連線
    $ajaxDb = new DBConnection();
    $pdo = $ajaxDb->getPDO();

    // ── Session Keep-Alive ───────────────────────────────────────────────────
    if ($_POST['action'] === 'keepalive') {
        $_SESSION['last_activity'] = time(); // 刷新 session 活躍時間
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── 取得單筆訂單完整資料（確保回傳 Client_name_ID, d_id_ID 綁定欄位）────
    if ($_POST['action'] === 'get_order_detail') {
        header('Content-Type: application/json');
        try {
            $oid = intval($_POST['order_id'] ?? 0);
            if (!$oid) throw new Exception('未指定訂單ID');
            $stmt = $pdo->prepare("SELECT ot.*,
                DATE_FORMAT(ot.Order_date, '%Y-%m-%d') AS orderindate,
                DATE_FORMAT(ot.Delivery_date, '%Y-%m-%d') AS orderDdate,
                DATE_FORMAT(ot.ateGet, '%Y-%m-%d') AS datepicker_ate,
                cl.customer AS cl_customer_name,
                ds.Drawing_No AS part_drawing_no
                FROM order_track ot
                LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                LEFT JOIN d_setting ds ON ds.d_id = ot.d_id_ID
                WHERE ot.Order_id = ?");
            $stmt->execute([$oid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('找不到訂單');
            // 客戶顯示名稱：已綁定優先從 customer_list 取中文名，否則退回暫存名稱
            $client_name_display = !empty($row['cl_customer_name']) ? $row['cl_customer_name'] : ($row['Client_name'] ?? '');

            // 若 order_track.unit_price 為空，嘗試從報價單查單價
            $unit_price = $row['unit_price'] ?? '';
            $unit_price_source = 'order_track';
            if ($unit_price === '' || $unit_price === null || floatval($unit_price) == 0) {
                try {
                    // 優先：有 quote_item_id 直接精確查詢
                    if (!empty($row['quote_item_id'])) {
                        $sq = $pdo->prepare("SELECT unit_price FROM quotation_item WHERE item_id = ? LIMIT 1");
                        $sq->execute([intval($row['quote_item_id'])]);
                        $qrow = $sq->fetch(PDO::FETCH_ASSOC);
                        if ($qrow && floatval($qrow['unit_price']) > 0) {
                            $unit_price = $qrow['unit_price'];
                            $unit_price_source = 'quotation';
                        }
                    }
                    // 退而求其次：有 quote_no 用 quote_no + 料號 查
                    if (($unit_price === '' || floatval($unit_price) == 0) && !empty($row['quote_no'])) {
                        $sq2 = $pdo->prepare("SELECT qi.unit_price FROM quotation_list ql
                            JOIN quotation_item qi ON ql.quote_id = qi.quote_id
                            WHERE ql.quote_no = ? AND qi.product_id LIKE ?
                            ORDER BY qi.item_id DESC LIMIT 1");
                        $sq2->execute([$row['quote_no'], '%' . ($row['d_id'] ?? '') . '%']);
                        $qrow2 = $sq2->fetch(PDO::FETCH_ASSOC);
                        if ($qrow2 && floatval($qrow2['unit_price']) > 0) {
                            $unit_price = $qrow2['unit_price'];
                            $unit_price_source = 'quotation';
                        }
                    }
                    // 最後備援：用料號查最近報價
                    if (($unit_price === '' || floatval($unit_price) == 0) && !empty($row['d_id'])) {
                        $sq3 = $pdo->prepare("SELECT qi.unit_price FROM quotation_list ql
                            JOIN quotation_item qi ON ql.quote_id = qi.quote_id
                            WHERE qi.product_id LIKE ? AND qi.unit_price > 0
                            ORDER BY ql.quote_date DESC LIMIT 1");
                        $sq3->execute(['%' . $row['d_id'] . '%']);
                        $qrow3 = $sq3->fetch(PDO::FETCH_ASSOC);
                        if ($qrow3 && floatval($qrow3['unit_price']) > 0) {
                            $unit_price = $qrow3['unit_price'];
                            $unit_price_source = 'quotation_by_part';
                        }
                    }
                } catch (Exception $eIgnore) {}
            }

            // 來源OP單目前的客戶（2026-08-28）：純供畫面提示用，不覆蓋任何欄位值
            $op_client_id = ''; $op_client_name = ''; $op_mismatch = false;
            if (!empty($row['quote_no'])) {
                try {
                    $sqc = $pdo->prepare("SELECT quote_no, client_id, client_name FROM quotation_list WHERE quote_no=? LIMIT 1");
                    $sqc->execute([$row['quote_no']]);
                    if ($qrow0 = $sqc->fetch(PDO::FETCH_ASSOC)) {
                        $op_client_id   = (string)($qrow0['client_id'] ?? '');
                        $op_client_name = (string)($qrow0['client_name'] ?? '');
                        $op_mismatch    = qcc_customer_differs($row, $qrow0);
                    }
                } catch (Exception $eOpc2) {}
            }

            echo json_encode(['success' => true, 'data' => [
                'Order_id'           => $row['Order_id'],
                'OrderNo'            => $row['Order_oo'] ?? '',
                'op_client_id'       => $op_client_id,
                'op_client_name'     => $op_client_name,
                'op_mismatch'        => $op_mismatch,
                'Client_Name'        => $row['Client_name'] ?? '',
                'Client_Name_Display'=> $client_name_display,
                'Client_name_ID'     => $row['Client_name_ID'] ?? '',
                'Client_OrderNo'     => $row['C_order'] ?? '',
                'd_id'               => $row['d_id'] ?? '',
                'd_id_ID'            => $row['d_id_ID'] ?? '',
                'Drawing_No'         => (!empty($row['part_drawing_no']) && $row['part_drawing_no'] !== ($row['d_id'] ?? '')) ? $row['part_drawing_no'] : '',
                'Process'            => $row['Processing_items'] ?? '',
                'Qty'                => $row['Qty'] ?? '',
                'unit_price'         => $unit_price,
                'unit_price_source'  => $unit_price_source,
                'quote_no'           => $row['quote_no'] ?? '',
                'quote_item_id'      => $row['quote_item_id'] ?? null,
                'drop_zone'          => $row['drop_zone'] ?? '',
                'Containers'         => $row['Containers'] ?? '',
                'Order_ps'           => $row['Order_ps'] ?? '',
                'ate'                => $row['ate'] ?? '',
                'orderindate'        => $row['orderindate'] ?? '',
                'orderDdate'         => $row['orderDdate'] ?? '',
                'datepicker_ate'     => $row['datepicker_ate'] ?? '',
                'Order_status'       => $row['Order_status'] ?? null,
                'is_urgent'          => (int)($row['is_urgent'] ?? 0),
            ]]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'save_designer_config') {
        header('Content-Type: application/json');
        // 前端只有管理員看得到「指派設計」旁的齒輪，後端同規則再擋一次（鐵律8：不可只擋 UI）
        require_once __DIR__ . '/../../src/common/order_track_perm_lib.php';
        if (!ot_is_admin($pdo, (int)($_SESSION['id'] ?? 0))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '您沒有修改此設定的權限。']);
            exit;
        }
        try {
            $config = $_POST['config']; // JSON string
            $user_id = $_SESSION['id'] ?? 0;
            
            $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at) 
                    VALUES ('DESIGNER_SETTING', 'designer_config', :val, '設計部門與人員設定', :user, NOW())
                    ON DUPLICATE KEY UPDATE param_value = :val_upd, updated_by = :user_upd, updated_at = NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':val' => $config, ':user' => $user_id, ':val_upd' => $config, ':user_upd' => $user_id]);

            // 同一個跳窗內的「指定特定設計＝存檔自動轉生管」名單（唯一實作在 order_auto_pmget_lib.php）
            if (isset($_POST['auto_pmget_ates'])) {
                require_once __DIR__ . '/../../src/common/order_auto_pmget_lib.php';
                ot_auto_pmget_save_ids($pdo, (json_decode($_POST['auto_pmget_ates'], true) ?: []), (int)$user_id);
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // ── 指定特定設計(技術)＝訂單存檔自動轉生管：既有訂單一次回填／退回 ──────
    //    設定值本身由 save_designer_config 一併寫入；這裡只負責對既有資料套用。
    if ($_POST['action'] === 'auto_pmget_backfill' || $_POST['action'] === 'save_auto_pmget') {
        header('Content-Type: application/json');
        require_once __DIR__ . '/../../src/common/order_track_perm_lib.php';
        require_once __DIR__ . '/../../src/common/order_auto_pmget_lib.php';
        $_apm_uid = (int)($_SESSION['id'] ?? 0);
        if (!ot_is_admin($pdo, $_apm_uid)) {   // 鐵律8：前端擋一次，後端同規則再擋一次
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '您沒有修改此設定的權限。']);
            exit;
        }
        try {
            if ($_POST['action'] === 'save_auto_pmget') {
                $_apm_ids = ot_auto_pmget_save_ids($pdo, (json_decode($_POST['ates'] ?? '[]', true) ?: []), $_apm_uid);
                echo json_encode(['success' => true, 'ates' => $_apm_ids]);
            } else {
                // dry=1 只試算筆數不寫入，讓使用者確認後才真的動既有資料
                echo json_encode(['success' => true, 'result' => ot_auto_pmget_backfill($pdo, !empty($_POST['dry']))]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_dept_data') {
        header('Content-Type: application/json');
        try {
            $depts = $ajaxDb->getAll("SELECT id, name FROM department ORDER BY sort_order, id");
            $users = [];
            if (!empty($_POST['dept_id'])) {
                $dept_id = intval($_POST['dept_id']);
                // 修正 JOIN 條件：p.id = pl.position_id（原為 p.position_id）
                $sql = "SELECT DISTINCT u.id, u.user_cname, p.name as position_name, p.sort_order as pos_sort,
                               COALESCE(pl.level, 9999) as lvl
                        FROM user u 
                        JOIN user_department_position_map m ON u.id = m.user_id 
                        LEFT JOIN `position` p ON m.position_id = p.id
                        LEFT JOIN position_level pl ON p.id = pl.position_id
                        WHERE m.department_id = $dept_id AND u.state = 1 
                        ORDER BY lvl ASC, pos_sort ASC, u.user_cname ASC";
                $rows = $ajaxDb->getAll($sql);
                $seen = [];
                foreach ($rows as $row) {
                    if (isset($seen[$row['id']])) continue;
                    $seen[$row['id']] = true;
                    // 顯示完整姓名，不截短
                    $users[] = ['id' => $row['id'], 'user_cname' => $row['user_cname']];
                }
            }
            $config_row = $pdo->query("SELECT param_value FROM system_parameters WHERE param_group = 'DESIGNER_SETTING' AND param_key = 'designer_config'")->fetch(PDO::FETCH_ASSOC);
            // 主要設計部門＝全站「組織角色綁定設定」的設計／技術部門，本頁不可改（2026-08-03）
            require_once __DIR__ . '/../../src/common/org_role_lib.php';
            require_once __DIR__ . '/../../src/common/order_auto_pmget_lib.php';
            echo json_encode(['success' => true, 'depts' => $depts, 'users' => $users,
                'rd_dept_id' => eg_org_dept($pdo, 'rd_dept'),
                'auto_pmget_ates' => ot_auto_pmget_ids($pdo, true),
                'config' => $config_row ? json_decode($config_row['param_value'], true) : null]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // --- AJAX: Customer, Part, and Quotation Data ---
    if ($_POST['action'] === 'search_data') {
        header('Content-Type: application/json');
        $type = $_POST['type'] ?? '';
        $term = $_POST['term'] ?? '';
        $results = [];
        try {
            if ($type === 'customer' && !empty($term)) {
                $query = "SELECT customer_id, customer, customer_address FROM customer_list WHERE (customer LIKE :term OR customer_id LIKE :term) AND is_inactive = 0 LIMIT 20";
                $stmt = $pdo->prepare($query);
                $stmt->execute([':term' => "%$term%"]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($type === 'part' && !empty($term)) {
                $customer_id_filter = trim($_POST['customer_id'] ?? '');
                if (!empty($customer_id_filter)) {
                    // 客戶已選定：只搜尋此客戶底下的料號
                    // alias_hit：命中的客戶代號／等同料號（src/common/part_alias_lib.php），前端顯示「正確料號（＝等同料號）」
                    $query = "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, d.Revision, d.Customer_Id, c.customer as Client_Name,
                                     d.Is_Assembly,
                                     (SELECT a.alias_code FROM d_setting_alias a WHERE a.d_id=d.d_id AND a.alias_code LIKE :term LIMIT 1) AS alias_hit,
                                     EXISTS(SELECT 1 FROM d_setting_bom bb WHERE bb.child_d_id = d.d_id) AS Is_Bom_Child
                              FROM d_setting d LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                              WHERE (d.D_Setting_Id LIKE :term OR d.Drawing_No LIKE :term OR d.Spec_No LIKE :term
                                     OR EXISTS(SELECT 1 FROM d_setting_alias a2 WHERE a2.d_id=d.d_id AND a2.alias_code LIKE :term)) AND d.Customer_Id = :cid
                              LIMIT 50";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([':term' => "%$term%", ':cid' => $customer_id_filter]);
                } else {
                    $query = "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, d.Revision, d.Customer_Id, c.customer as Client_Name,
                                     d.Is_Assembly,
                                     (SELECT a.alias_code FROM d_setting_alias a WHERE a.d_id=d.d_id AND a.alias_code LIKE :term LIMIT 1) AS alias_hit,
                                     EXISTS(SELECT 1 FROM d_setting_bom bb WHERE bb.child_d_id = d.d_id) AS Is_Bom_Child
                              FROM d_setting d LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                              WHERE (d.D_Setting_Id LIKE :term OR d.Drawing_No LIKE :term OR d.Spec_No LIKE :term
                                     OR EXISTS(SELECT 1 FROM d_setting_alias a2 WHERE a2.d_id=d.d_id AND a2.alias_code LIKE :term))
                              LIMIT 20";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([':term' => "%$term%"]);
                }
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 組合件展開：儲存後檢查此訂單料號是否為組合件、是否需詢問展開子件訂單 ──
    if ($_POST['action'] === 'check_assembly_expand') {
        header('Content-Type: application/json');
        try {
            $oid = intval($_POST['order_id'] ?? 0);
            if (!$oid) { echo json_encode(['success' => false, 'message' => '未指定訂單ID']); exit; }
            $st = $pdo->prepare("SELECT ot.Order_id, ot.Order_oo, ot.Qty, ot.d_id_ID, ds.D_Setting_Id, ds.Spec_No, ds.Is_Assembly
                                 FROM order_track ot JOIN d_setting ds ON ds.d_id = ot.d_id_ID
                                 WHERE ot.Order_id = ?");
            $st->execute([$oid]);
            $ord = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ord || intval($ord['Is_Assembly']) !== 1) { echo json_encode(['success' => true, 'need_prompt' => false]); exit; }
            // 本單已展開過子件訂單 → 不再詢問
            $chk = $pdo->prepare("SELECT COUNT(*) FROM order_track WHERE assembly_parent_order_id = ?");
            $chk->execute([$oid]);
            if ((int)$chk->fetchColumn() > 0) { echo json_encode(['success' => true, 'need_prompt' => false, 'already_expanded' => true]); exit; }
            // BOM 子件清單（展開數量 = 訂單數量 × 每組用量，無條件進位）
            $bs = $pdo->prepare("SELECT b.child_d_id, b.standard_qty, d.D_Setting_Id, d.Spec_No
                                 FROM d_setting_bom b JOIN d_setting d ON d.d_id = b.child_d_id
                                 WHERE b.parent_d_id = ? ORDER BY b.bom_id ASC");
            $bs->execute([intval($ord['d_id_ID'])]);
            $children = [];
            foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $stdQty = floatval($c['standard_qty']);
                $children[] = [
                    'child_d_id'   => (int)$c['child_d_id'],
                    'part_no'      => $c['D_Setting_Id'],
                    'spec'         => $c['Spec_No'],
                    'standard_qty' => rtrim(rtrim(number_format($stdQty, 2, '.', ''), '0'), '.'),
                    'expand_qty'   => (int)ceil(floatval($ord['Qty']) * $stdQty),
                ];
            }
            if (empty($children)) { echo json_encode(['success' => true, 'need_prompt' => false, 'no_bom' => true]); exit; }
            echo json_encode([
                'success'     => true,
                'need_prompt' => true,
                'order'       => ['order_id' => (int)$ord['Order_id'], 'order_no' => $ord['Order_oo'], 'qty' => (int)$ord['Qty']],
                'assembly'    => ['part_no' => $ord['D_Setting_Id'], 'spec' => $ord['Spec_No']],
                'children'    => $children,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // --- From inspection_standard_setting.php ---
    // 搜尋客戶 (給料號管理用)
    if ($_POST['action'] === 'search_customers') {
        header('Content-Type: application/json');
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

    // 儲存/新增料號基本資料 (d_setting)
    if ($_POST['action'] === 'save_part_info') {
        header('Content-Type: application/json');
        try {
            $d_id = $_POST['d_id'] ?? '';
            $part_no = $_POST['part_no'] ?? '';
            $customer_id = !empty($_POST['customer_id']) ? $_POST['customer_id'] : null;
            $type = $_POST['type'] ?? 'N'; // 預設一般
            $revision = $_POST['revision'] ?? '';
            $issue_date = !empty($_POST['issue_date']) ? $_POST['issue_date'] : null;
            $remark = $_POST['remark'] ?? '';
            $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 'System'; // 獲取當前使用者
            $gears = isset($_POST['gears']) ? json_decode($_POST['gears'], true) : [];

            if (empty($part_no)) throw new Exception("料號 (D_Setting_Id) 為必填");

            // ※ 新增：客戶ID 為必填（所有情境皆強制）
            if (empty($customer_id)) throw new Exception("客戶為必填，請先選定要綁定的客戶");

            $pdo->beginTransaction();

            if (!empty($d_id)) {
                // Update — 不做重複檢查（修改現有資料）
                // 不碰 Drawing_No（圖面代號）：那是料號主檔設定的獨立欄位，
                // 這裡若一併寫成料號，會把使用者設好的圖面代號洗掉
                $sql = "UPDATE d_setting SET D_Setting_Id=?, Customer_Id=?, Type=?, Revision=?, Issue_Date=?, Remark=?, Modified_By=?, Modified_At=NOW() WHERE d_id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$part_no, $customer_id, $type, $revision, $issue_date, $remark, $user_id, $d_id]);
            } else {
                // Insert — 先檢查是否重複
                // 重複判斷規則：
                //   料號(D_Setting_Id) + 客戶ID(Customer_Id) 相同，且：
                //   - 版次與發行日皆為空 → 直接視為重複
                //   - 版次非空 → 比對版次；發行日非空 → 比對發行日
                //   - 兩者都非空 → 任一相符即重複
                //   - 只有一個非空 → 只比對非空的那個（空值跳過）
                $dupSql = "SELECT d.d_id, d.D_Setting_Id, d.Revision, d.Issue_Date, c.customer AS client_name
                           FROM d_setting d
                           LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                           WHERE d.D_Setting_Id = ?
                             AND d.Customer_Id = ?";
                $dupParams = [$part_no, $customer_id];

                $hasRevision  = !empty($revision);
                $hasIssueDate = !empty($issue_date);

                if ($hasRevision || $hasIssueDate) {
                    // 有任一非空：只比對非空的欄位（空值跳過，不納入比對條件）
                    $conditions = [];
                    if ($hasRevision)  { $conditions[] = "d.Revision = ?";   $dupParams[] = $revision; }
                    if ($hasIssueDate) { $conditions[] = "d.Issue_Date = ?"; $dupParams[] = $issue_date; }
                    // 兩者皆非空時用 OR（任一相符即重複）；只有一個時直接比對
                    $dupSql .= " AND (" . implode(' OR ', $conditions) . ")";
                }
                // 兩者皆空：料號+客戶相同就算重複（不加額外條件）

                $dupStmt = $pdo->prepare($dupSql);
                $dupStmt->execute($dupParams);
                $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $pdo->rollBack();
                    $dupInfo = $existing['D_Setting_Id'];
                    if ($existing['client_name']) $dupInfo .= '（' . $existing['client_name'] . '）';
                    if ($existing['Revision'])    $dupInfo .= ' 版次:' . $existing['Revision'];
                    if ($existing['Issue_Date'])  $dupInfo .= ' 發行日:' . $existing['Issue_Date'];
                    echo json_encode(['success' => false, 'duplicate' => true, 'message' => '已存在相同料號資料，請勿重複新增。重複項：' . $dupInfo]);
                    exit;
                }

                // Drawing_No 不自動帶入料號（沒有圖面代號就留空，才不會與別筆料號互相誤判）
                $sql = "INSERT INTO d_setting (D_Setting_Id, Customer_Id, Type, Revision, Issue_Date, Remark, Created_By, Created_At) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$part_no, $customer_id, $type, $revision, $issue_date, $remark, $user_id]);
                $d_id = $pdo->lastInsertId();
            }

            // 處理齒輪資料 (若 Type 為 G)
            // 先刪除舊資料
            $pdo->prepare("DELETE FROM d_setting_gear WHERE d_setting_id = ?")->execute([$d_id]);
            
            if ($type === 'G' && !empty($gears)) {
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

    // 刪除料號 (d_setting)
    if ($_POST['action'] === 'delete_part') {
        header('Content-Type: application/json');
        try {
            $d_id = $_POST['d_id'] ?? '';
            if (empty($d_id)) throw new Exception("未指定 ID");

            $stmt = $pdo->prepare("DELETE FROM d_setting WHERE d_id = ?");
            $stmt->execute([$d_id]);

            echo json_encode(['success' => true, 'message' => '刪除成功']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => '刪除失敗: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_all_customers') {
        header('Content-Type: application/json');
        try {
            $results = $ajaxDb->getAll("SELECT * FROM customer_list WHERE is_inactive = 0 ORDER BY customer_id ASC");
            echo json_encode(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'save_customer') {
        header('Content-Type: application/json');
        try {
            $id = $_POST['customer_id_modal'] ?? '';
            $name = $_POST['customer_name_modal'] ?? '';
            $addr = $_POST['customer_address_modal'] ?? '';
            $tel = $_POST['customer_tel_modal'] ?? '';
            $fax = $_POST['customer_fax_modal'] ?? '';
            
            if (empty($name)) throw new Exception('客戶名稱不可為空');

            if (empty($id)) { // New customer
                if(empty($_POST['customer_id_new'])) throw new Exception('新增客戶時，客戶代碼不可為空');
                $id = $_POST['customer_id_new'];
                $check = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id = ?");
                $check->execute([$id]);
                if($check->fetchColumn() > 0) throw new Exception('客戶代碼已存在');

                $sql = "INSERT INTO customer_list (customer_id, customer, customer_address, customer_tel, customer_fax, is_inactive, Created_By) VALUES (?, ?, ?, ?, ?, 0, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id, $name, $addr, $tel, $fax, $_SESSION['id']]);
            } else { // Update existing
                $sql = "UPDATE customer_list SET customer = ?, customer_address = ?, customer_tel = ?, customer_fax = ?, Modified_By = ?, Modified_At = NOW() WHERE customer_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$name, $addr, $tel, $fax, $_SESSION['id'], $id]);
            }
            echo json_encode(['success' => true, 'message' => '客戶資料已儲存']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_all_parts') {
        header('Content-Type: application/json');
        try {
            $results = $ajaxDb->getAll("SELECT * FROM d_setting ORDER BY D_Setting_Id ASC");
            echo json_encode(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    
    // ── 快速新增客戶 ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'quick_add_customer') {
        header('Content-Type: application/json');
        try {
            $customer_id   = trim($_POST['customer_id']   ?? '');
            $customer_name = trim($_POST['customer_name'] ?? '');
            $customer_addr = trim($_POST['customer_addr'] ?? '');
            if (empty($customer_id))   throw new Exception('客戶代碼不可為空');
            if (empty($customer_name)) throw new Exception('客戶名稱不可為空');
            $chk = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id = ?");
            $chk->execute([$customer_id]);
            if ($chk->fetchColumn() > 0) throw new Exception('客戶代碼已存在，請換一個');
            $ins = $pdo->prepare("INSERT INTO customer_list (customer_id, customer, customer_address, is_inactive, Created_By) VALUES (?,?,?,0,?)");
            $ins->execute([$customer_id, $customer_name, $customer_addr, $_SESSION['id'] ?? 'sys']);
            echo json_encode(['success' => true, 'customer_id' => $customer_id, 'customer_name' => $customer_name]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 快速新增料號 ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'quick_add_part') {
        header('Content-Type: application/json');
        try {
            $part_no     = trim($_POST['part_no']     ?? '');
            $spec_no     = trim($_POST['spec_no']     ?? '');
            $revision    = trim($_POST['revision']    ?? '');
            $customer_id = trim($_POST['customer_id'] ?? '') ?: null;
            $user_id     = $_SESSION['id'] ?? 'sys';
            if (empty($part_no))   throw new Exception('料號不可為空');
            if (empty($customer_id)) throw new Exception('客戶為必填，無法建立無客戶的料號');
            if ($customer_id) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM d_setting WHERE D_Setting_Id = ? AND Customer_Id = ?");
                $chk->execute([$part_no, $customer_id]);
            } else {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM d_setting WHERE D_Setting_Id = ? AND Customer_Id IS NULL");
                $chk->execute([$part_no]);
            }
            if ($chk->fetchColumn() > 0) throw new Exception('料號已存在（此客戶下已有相同料號）');
            $ins = $pdo->prepare("INSERT INTO d_setting (D_Setting_Id, Spec_No, Revision, Customer_Id, Type, Created_By, Created_At) VALUES (?,?,?,?,'N',?,NOW())");
            $ins->execute([$part_no, $spec_no, $revision, $customer_id, $user_id]);
            $new_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'd_id' => $new_id, 'D_Setting_Id' => $part_no]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 依料號查詢相關報價單 ─────────────────────────────────────────────────
    if ($_POST['action'] === 'get_quotes_by_part') {
        header('Content-Type: application/json');
        try {
            $part_text = trim($_POST['part_text'] ?? '');
            $d_id_id   = intval($_POST['d_id_id'] ?? 0);

            // Step 1: 查報價單主體
            // 有精準料號ID：連同「客戶代號／等同料號」綁定的其他 d_id 一併比對（part_alias_lib.php），
            // 否則報價當年用的是舊／客戶代號料號時，換成現行料號查詢會找不到相關報價單
            $base_sql = "SELECT ql.quote_no, ql.quote_date, ql.client_name, ql.note AS quote_note,
                                ql.is_negotiation,
                                qi.item_id, qi.d_setting_d_id, qi.product_id, qi.specification,
                                qi.quantity, qi.unit_price, qi.process_notes
                         FROM quotation_list ql
                         JOIN quotation_item qi ON ql.quote_id = qi.quote_id";
            if ($d_id_id > 0) {
                $relatedIds = eg_part_alias_related_dids($pdo, $d_id_id);
                $ph = implode(',', array_fill(0, count($relatedIds), '?'));
                $stmt = $pdo->prepare($base_sql . " WHERE qi.d_setting_d_id IN ($ph) ORDER BY ql.quote_date DESC, ql.quote_id DESC LIMIT 20");
                $stmt->execute($relatedIds);
            } else {
                $stmt = $pdo->prepare($base_sql . " WHERE qi.product_id LIKE :term ORDER BY ql.quote_date DESC, ql.quote_id DESC LIMIT 20");
                $stmt->execute([':term' => "%$part_text%"]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // 標示透過「客戶代號／等同料號」帶入、料號跟目前選定料號不同的項目
            if ($d_id_id > 0) {
                foreach ($rows as &$r) {
                    $r['alias_hit'] = ((int)$r['d_setting_d_id'] !== $d_id_id) ? $r['product_id'] : null;
                }
                unset($r);
            }

            // Step 2: 收集所有 sub_tag_id（來自 process_notes 欄位），批次查名稱
            $allSubTagIds = [];
            foreach ($rows as $r) {
                if (!empty($r['process_notes'])) {
                    foreach (explode(',', $r['process_notes']) as $sid) {
                        $sid = intval(trim($sid));
                        if ($sid > 0) $allSubTagIds[$sid] = true;
                    }
                }
            }
            $subTagMap = [];
            if (!empty($allSubTagIds)) {
                try {
                    $sids = array_keys($allSubTagIds);
                    $ph = implode(',', array_fill(0, count($sids), '?'));
                    $stStmt = $pdo->prepare("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag WHERE sub_tag_id IN ($ph)");
                    $stStmt->execute($sids);
                    foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
                        $subTagMap[intval($st['sub_tag_id'])] = $st['sub_tag_name'];
                    }
                } catch (Exception $eIgnore) {}
            }

            // Step 3: 組成製程名稱字串（與報價單相同，sub_tag 名稱以 ・ 連接）
            foreach ($rows as &$r) {
                if (!empty($r['process_notes'])) {
                    $names = [];
                    foreach (explode(',', $r['process_notes']) as $sid) {
                        $sid = intval(trim($sid));
                        if ($sid > 0 && isset($subTagMap[$sid])) {
                            $names[] = $subTagMap[$sid];
                        }
                    }
                    $r['processes'] = implode('・', $names);
                } else {
                    $r['processes'] = '';
                }
            }
            unset($r);

            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 依料號查詢出貨歷史 ───────────────────────────────────────────────────
    if ($_POST['action'] === 'get_shipment_history') {
        header('Content-Type: application/json');
        try {
            $part_text = trim($_POST['part_text'] ?? '');
            $d_id_id   = intval($_POST['d_id_id'] ?? 0);
            if (empty($part_text)) { echo json_encode(['success' => true, 'data' => []]); exit; }

            $base_sql = "SELECT il.IS_id, il.IS_number, il.Order_date,
                                il.Client_name, il.Product_id, il.Specification,
                                il.Qty, il.Unit_price, il.Note, il.Content,
                                ol.Order_oo, ol.Specification AS order_spec
                         FROM is_list il
                         LEFT JOIN order_list ol ON il.Order_id = ol.Order_id";

            // 有精確 d_id：先取 D_Setting_Id 再做精確比對，避免 "209" 比到其他客戶的 209XXXX
            $exact_id = null;
            if ($d_id_id > 0) {
                $sr = $pdo->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id = ? LIMIT 1");
                $sr->execute([$d_id_id]);
                $dr = $sr->fetch(PDO::FETCH_ASSOC);
                if ($dr) $exact_id = $dr['D_Setting_Id'];
            }

            if ($exact_id !== null) {
                $stmt = $pdo->prepare($base_sql . " WHERE il.Product_id = :term ORDER BY il.Order_date DESC LIMIT 15");
                $stmt->execute([':term' => $exact_id]);
            } else {
                $stmt = $pdo->prepare($base_sql . " WHERE il.Product_id LIKE :term ORDER BY il.Order_date DESC LIMIT 15");
                $stmt->execute([':term' => "%$part_text%"]);
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── OP轉訂單：依OP單號模糊搜尋 ──────────────────────────────────────────
    if ($_POST['action'] === 'op_search_by_no') {
        header('Content-Type: application/json');
        try {
            $term = trim($_POST['term'] ?? '');
            if ($term === '') { echo json_encode(['success' => true, 'data' => []]); exit; }
            $stmt = $pdo->prepare("SELECT quote_id, quote_no, quote_date, client_name, is_negotiation
                                    FROM quotation_list
                                    WHERE quote_no LIKE :term
                                    ORDER BY quote_date DESC, quote_id DESC
                                    LIMIT 30");
            $stmt->execute([':term' => "%$term%"]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── OP轉訂單：依料號搜尋跨OP單的報價項目 ────────────────────────────────
    if ($_POST['action'] === 'op_search_by_part') {
        header('Content-Type: application/json');
        try {
            $part_text = trim($_POST['part_text'] ?? '');
            $d_id_id   = intval($_POST['d_id_id'] ?? 0);
            if ($part_text === '' && !$d_id_id) { echo json_encode(['success' => true, 'data' => []]); exit; }

            $base_sql = "SELECT ql.quote_id, ql.quote_no, ql.quote_date, ql.client_name, ql.is_negotiation,
                                qi.item_id, qi.d_setting_d_id, qi.product_id, qi.specification, qi.quantity, qi.unit_price, qi.process_notes
                         FROM quotation_list ql
                         JOIN quotation_item qi ON ql.quote_id = qi.quote_id";
            if ($d_id_id > 0) {
                $relatedIds = eg_part_alias_related_dids($pdo, $d_id_id);
                $ph = implode(',', array_fill(0, count($relatedIds), '?'));
                $stmt = $pdo->prepare($base_sql . " WHERE qi.d_setting_d_id IN ($ph) ORDER BY ql.quote_date DESC, ql.quote_id DESC LIMIT 50");
                $stmt->execute($relatedIds);
            } else {
                $like = "%$part_text%";
                // 客戶代號／等同料號（src/common/part_alias_lib.php）：報價可能是用「舊／客戶代號」自己的
                // 料號建的（該代號本身也有 d_setting 記錄），現行正確料號另有其人時，兩邊互查都要找得到彼此，
                // 故先找出文字命中的 d_id（料號本身或別名代號），再展開成同一實體的完整料號家族一併搜尋
                $seedIds = [];
                $stD = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id LIKE :term");
                $stD->execute([':term' => $like]);
                foreach ($stD->fetchAll(PDO::FETCH_COLUMN) as $x) $seedIds[(int)$x] = true;
                $stA = $pdo->prepare("SELECT DISTINCT d_id FROM d_setting_alias WHERE alias_code LIKE :term");
                $stA->execute([':term' => $like]);
                foreach ($stA->fetchAll(PDO::FETCH_COLUMN) as $x) $seedIds[(int)$x] = true;

                $relatedIds = [];
                foreach (array_keys($seedIds) as $sid) {
                    foreach (eg_part_alias_related_dids($pdo, $sid) as $rid) $relatedIds[$rid] = true;
                }
                $relatedIds = array_keys($relatedIds);

                if ($relatedIds) {
                    $ph = implode(',', array_fill(0, count($relatedIds), '?'));
                    $stmt = $pdo->prepare($base_sql . " WHERE qi.product_id LIKE ? OR qi.d_setting_d_id IN ($ph)
                                                         ORDER BY ql.quote_date DESC, ql.quote_id DESC LIMIT 50");
                    $stmt->execute(array_merge([$like], $relatedIds));
                } else {
                    $stmt = $pdo->prepare($base_sql . " WHERE qi.product_id LIKE :term ORDER BY ql.quote_date DESC, ql.quote_id DESC LIMIT 50");
                    $stmt->execute([':term' => $like]);
                }
            }
            $rows = eg_build_process_names($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));
            // 標示轉單時會自動更正的料號（part_alias_lib.php eg_part_alias_canonical，與 op_get_items 同邏輯）
            $canonicalCache = [];
            foreach ($rows as &$r) {
                $srcDid = (int)($r['d_setting_d_id'] ?? 0);
                $r['corrected_to'] = null;
                if ($srcDid <= 0) continue;
                if (!isset($canonicalCache[$srcDid])) $canonicalCache[$srcDid] = eg_part_alias_canonical($pdo, $srcDid);
                $c = $canonicalCache[$srcDid];
                if ($c['corrected']) {
                    $cd = $pdo->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id = ?");
                    $cd->execute([$c['d_id']]);
                    $r['corrected_to'] = (string)$cd->fetchColumn();
                }
            }
            unset($r);
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── OP轉訂單：取得指定OP單的完整料號項目清單（含是否已轉過訂單）───────────
    if ($_POST['action'] === 'op_get_items') {
        header('Content-Type: application/json');
        try {
            $quote_id = intval($_POST['quote_id'] ?? 0);
            if (!$quote_id) throw new Exception('未指定報價單ID');

            $stmtQ = $pdo->prepare("SELECT quote_id, quote_no, quote_date, client_name, is_negotiation FROM quotation_list WHERE quote_id = ?");
            $stmtQ->execute([$quote_id]);
            $quote = $stmtQ->fetch(PDO::FETCH_ASSOC);
            if (!$quote) throw new Exception('找不到報價單');

            $stmt = $pdo->prepare("SELECT qi.item_id, qi.product_id, qi.d_setting_d_id, qi.specification,
                                           qi.quantity, qi.unit_price, qi.process_notes, qi.is_tiered,
                                           ds.D_Setting_Id, ds.Is_Assembly,
                                           (SELECT COUNT(*) FROM order_track ot2 WHERE ot2.quote_item_id = qi.item_id) AS converted_count,
                                           (SELECT GROUP_CONCAT(ot2.Order_oo ORDER BY ot2.Order_id ASC SEPARATOR '、') FROM order_track ot2 WHERE ot2.quote_item_id = qi.item_id) AS converted_order_oo
                                    FROM quotation_item qi
                                    LEFT JOIN d_setting ds ON ds.d_id = qi.d_setting_d_id
                                    WHERE qi.quote_id = ?
                                    ORDER BY qi.sort_order ASC, qi.item_id ASC");
            $stmt->execute([$quote_id]);
            $rows = eg_build_process_names($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));
            // 客戶代號／等同料號自動更正：報價當年用的料號如果後來被登記成別的料號的別名（linked_d_id），
            // 轉訂單一律改用現行正確料號，不可繼續拿舊料號建單（part_alias_lib.php eg_part_alias_canonical）
            $canonicalCache = [];
            foreach ($rows as &$r) {
                $srcDid = (int)($r['d_setting_d_id'] ?? 0);
                $r['corrected_from'] = null;
                if ($srcDid <= 0) continue;
                if (!isset($canonicalCache[$srcDid])) $canonicalCache[$srcDid] = eg_part_alias_canonical($pdo, $srcDid);
                $c = $canonicalCache[$srcDid];
                if ($c['corrected']) {
                    $cd = $pdo->prepare("SELECT D_Setting_Id, Is_Assembly FROM d_setting WHERE d_id = ?");
                    $cd->execute([$c['d_id']]);
                    if ($cr = $cd->fetch(PDO::FETCH_ASSOC)) {
                        $r['corrected_from'] = $r['D_Setting_Id'];
                        $r['D_Setting_Id']   = $cr['D_Setting_Id'];
                        $r['Is_Assembly']    = $cr['Is_Assembly'];
                        $r['d_setting_d_id'] = $c['d_id'];
                    }
                }
            }
            unset($r);
            // 階梯報價項目：附上各區間（含容差），供轉單時輸入數量自動對價
            $tieredIds = [];
            foreach ($rows as $r) { if (!empty($r['is_tiered'])) $tieredIds[] = (int)$r['item_id']; }
            if ($tieredIds) {
                $phT = implode(',', array_fill(0, count($tieredIds), '?'));
                $stT = $pdo->prepare("SELECT item_id, qty_min, qty_max, unit_price, tolerance_value, tolerance_unit, tolerance_note
                                      FROM quotation_item_tier WHERE item_id IN ($phT)
                                      ORDER BY item_id ASC, sort_order ASC, qty_min ASC");
                $stT->execute($tieredIds);
                $tierMap = [];
                foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $t) $tierMap[$t['item_id']][] = $t;
                foreach ($rows as &$r) { if (!empty($r['is_tiered'])) $r['tiers'] = $tierMap[$r['item_id']] ?? []; }
                unset($r);
            }
            echo json_encode(['success' => true, 'quote' => $quote, 'data' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 來源OP單客戶連動（2026-08-28）──────────────────────────────────────
    //    用 A 客戶報價、接單後客戶要求改掛 B 客戶的情況：報價單那邊改了客戶之後，
    //    由該 OP 轉出的訂單原本不會跟著動（客戶是轉單當下寫進去的快照）。列表上會用
    //    橘色徽章標出「與來源OP的客戶已不一致」，按下去就走這支同步（只改客戶欄位，
    //    不動料號、金額與任何日期）。判定與寫入的唯一實作都在 quote_customer_lib.php。
    if ($_POST['action'] === 'sync_quote_customer') {
        header('Content-Type: application/json');
        try {
            $uid = intval($_SESSION['id'] ?? 0);
            // 後端同規則再擋一次（鐵律8）：畫面上沒有編輯權的人看得到徽章但按不到，直打 API 一樣擋下
            require_once __DIR__ . '/../../src/common/order_track_perm_lib.php';
            if (!ot_has_feature($pdo, $uid, 'ot_edit')) {
                echo json_encode(['success' => false, 'message' => '您沒有修改訂單的權限']); exit;
            }
            $oid = intval($_POST['order_id'] ?? 0);
            if (!$oid) throw new Exception('未指定訂單ID');
            echo json_encode(['success' => true, 'data' => qcc_sync_order_from_quote($pdo, $oid, $uid)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 取得料號詳細資料（含齒輪）─────────────────────────────────────────
    if ($_POST['action'] === 'get_part_detail') {
        header('Content-Type: application/json');
        try {
            $d_id = intval($_POST['d_id'] ?? 0);
            if (!$d_id) throw new Exception('未指定料號ID');
            $stmt = $pdo->prepare("SELECT d.*, c.customer AS client_name FROM d_setting d LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id WHERE d.d_id = ?");
            $stmt->execute([$d_id]);
            $part = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$part) throw new Exception('找不到料號');
            // 齒輪資料 — PK 為 gear_id
            $gears = [];
            if ($part['Type'] === 'G') {
                $sg = $pdo->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id = ? ORDER BY gear_id ASC");
                $sg->execute([$d_id]);
                $gears = $sg->fetchAll(PDO::FETCH_ASSOC);
            }
            $part['gears'] = $gears;
            echo json_encode(['success' => true, 'data' => $part]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 快速綁定：查詢一筆訂單可能對應的客戶ID與料號ID ──────────────────
    if ($_POST['action'] === 'quick_bind_lookup') {
        header('Content-Type: application/json');
        try {
            $order_id  = $_POST['order_id'] ?? '';
            $client_name = trim($_POST['client_name'] ?? '');
            $d_id_text   = trim($_POST['d_id_text'] ?? '');

            // 搜尋符合客戶名稱的客戶列表
            $customers = [];
            if ($client_name !== '') {
                $stmt = $pdo->prepare("SELECT customer_id, customer FROM customer_list WHERE customer LIKE ? AND is_inactive = 0 ORDER BY customer_id LIMIT 10");
                $stmt->execute(["%$client_name%"]);
                $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // 取得此訂單已綁定的客戶 ID（若已綁定，料號搜尋時一併篩選）
            $bound_customer_id = null;
            if ($order_id !== '') {
                $s = $pdo->prepare("SELECT Client_name_ID FROM order_track WHERE Order_id = ? LIMIT 1");
                $s->execute([$order_id]);
                $brow = $s->fetch(PDO::FETCH_ASSOC);
                if ($brow && !empty($brow['Client_name_ID'])) {
                    $bound_customer_id = $brow['Client_name_ID'];
                }
            }

            // 搜尋符合料號的 d_setting 列表（模糊搜尋，上限 50 筆，精確符合排前）
            $parts = [];
            if ($d_id_text !== '') {
                if ($bound_customer_id !== null) {
                    // 客戶已綁定：同時篩選此客戶底下的料號
                    $stmt = $pdo->prepare("SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, d.Customer_Id AS customer_id, c.customer AS client_name,
                                                  d.Is_Assembly,
                                                  (SELECT a.alias_code FROM d_setting_alias a WHERE a.d_id=d.d_id AND a.alias_code LIKE ? LIMIT 1) AS alias_hit,
                                                  EXISTS(SELECT 1 FROM d_setting_bom bb WHERE bb.child_d_id = d.d_id) AS Is_Bom_Child
                                           FROM d_setting d
                                           LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                                           WHERE (d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ?
                                                  OR EXISTS(SELECT 1 FROM d_setting_alias a2 WHERE a2.d_id=d.d_id AND a2.alias_code LIKE ?)) AND d.Customer_Id = ?
                                           ORDER BY CASE WHEN d.D_Setting_Id = ? THEN 0 ELSE 1 END, d.D_Setting_Id
                                           LIMIT 50");
                    $stmt->execute(["%$d_id_text%", "%$d_id_text%", "%$d_id_text%", "%$d_id_text%", $bound_customer_id, $d_id_text]);
                } else {
                    // 無綁定客戶：全範圍模糊搜尋
                    $stmt = $pdo->prepare("SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, d.Customer_Id AS customer_id, c.customer AS client_name,
                                                  d.Is_Assembly,
                                                  (SELECT a.alias_code FROM d_setting_alias a WHERE a.d_id=d.d_id AND a.alias_code LIKE ? LIMIT 1) AS alias_hit,
                                                  EXISTS(SELECT 1 FROM d_setting_bom bb WHERE bb.child_d_id = d.d_id) AS Is_Bom_Child
                                           FROM d_setting d
                                           LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                                           WHERE (d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ?
                                                  OR EXISTS(SELECT 1 FROM d_setting_alias a2 WHERE a2.d_id=d.d_id AND a2.alias_code LIKE ?))
                                           ORDER BY CASE WHEN d.D_Setting_Id = ? THEN 0 ELSE 1 END, d.D_Setting_Id
                                           LIMIT 50");
                    $stmt->execute(["%$d_id_text%", "%$d_id_text%", "%$d_id_text%", "%$d_id_text%", $d_id_text]);
                }
                $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'customers' => $customers, 'parts' => $parts]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 快速綁定：儲存選定的客戶ID/料號ID/報價單到 order_track ─────────────
    if ($_POST['action'] === 'save_order_ids') {
        header('Content-Type: application/json');
        try {
            $order_id    = $_POST['order_id']   ?? '';
            $customer_pk = !empty($_POST['customer_pk']) ? $_POST['customer_pk'] : null;
            $part_pk     = !empty($_POST['part_pk'])     ? intval($_POST['part_pk']) : null;
            $quote_no    = !empty($_POST['quote_no'])    ? trim($_POST['quote_no'])  : null;
            $unit_price  = isset($_POST['unit_price']) && $_POST['unit_price'] !== '' ? floatval($_POST['unit_price']) : null;

            if (empty($order_id)) throw new Exception('未指定訂單ID');

            $sets   = [];
            $params = [];
            if ($customer_pk !== null) {
                $sets[] = 'Client_name_ID = :cid';
                $params[':cid'] = $customer_pk;
                // 綁定客戶後清除手動輸入的暫存客戶名稱，避免與前端顯示不同
                $sets[] = 'Client_name = :cname_clear';
                $params[':cname_clear'] = '';
            }
            if ($part_pk     !== null) { $sets[] = 'd_id_ID = :pid';        $params[':pid'] = $part_pk; }
            if ($quote_no    !== null) { $sets[] = 'quote_no = :qno';       $params[':qno'] = $quote_no; }
            if ($unit_price  !== null) { $sets[] = 'unit_price = :upr';     $params[':upr'] = $unit_price; }
            if (empty($sets)) throw new Exception('沒有要更新的欄位');

            $params[':oid'] = $order_id;
            $params[':uid'] = $_SESSION['id'] ?? 0;
            $sql = "UPDATE order_track SET " . implode(', ', $sets) . ", Modified_By = :uid, Modified_At = NOW() WHERE Order_id = :oid";
            $pdo->prepare($sql)->execute($params);

            // 若料號無客戶綁定，且本次有指定客戶 → 自動補上客戶ID
            $part_customer_fixed = false;
            if ($part_pk !== null && $customer_pk !== null) {
                $dsCheck = $pdo->prepare("SELECT Customer_Id FROM d_setting WHERE d_id = ?");
                $dsCheck->execute([$part_pk]);
                $dsRow = $dsCheck->fetch(PDO::FETCH_ASSOC);
                if ($dsRow && empty($dsRow['Customer_Id'])) {
                    $pdo->prepare("UPDATE d_setting SET Customer_Id = ?, Modified_By = ?, Modified_At = NOW() WHERE d_id = ?")
                        ->execute([$customer_pk, $_SESSION['id'] ?? 0, $part_pk]);
                    $part_customer_fixed = true;
                }
            }

            // 回傳綁定後的客戶顯示名稱（從 customer_list 取中文名）
            $display_client_name = '';
            if ($customer_pk !== null) {
                $stmtCL = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id = ?");
                $stmtCL->execute([$customer_pk]);
                $clRow = $stmtCL->fetch(PDO::FETCH_ASSOC);
                $display_client_name = $clRow ? $clRow['customer'] : '';
            }

            echo json_encode(['success' => true, 'display_client_name' => $display_client_name, 'part_customer_fixed' => $part_customer_fixed]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 更新訂單狀態（暫停/解除暫停/完結/解除完結）────────────────────────
    if ($_POST['action'] === 'save_order_status') {
        header('Content-Type: application/json');
        require_once __DIR__ . '/../../src/common/order_track_perm_lib.php';
        try {
            $order_id = intval($_POST['order_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? '';
            if (!$order_id) throw new Exception('未指定訂單ID');
            if (!in_array($new_status, ['6', '9', ''])) throw new Exception('不合法的狀態值');
            $uid = $_SESSION['id'] ?? 0;
            // 權限：本檔原本完全沒有任何檢查，任何登入者都能暫停/結案任一訂單；補上功能碼門檻
            // （結案/解除結案看 ot_close；暫停/取消/解除暫停看 ot_cancel；此區塊跑在頁面權限計算段之前，
            // $can_*/ot_hasF() 尚未定義，改用獨立可重用的 ot_has_feature()，此處不重寫檢查邏輯）
            $_reqFeature = ($new_status === '9') ? 'ot_close' : 'ot_cancel';
            if ($new_status === '') {
                $curSt = $pdo->prepare("SELECT Order_status FROM order_track WHERE Order_id = ?");
                $curSt->execute([$order_id]);
                $_reqFeature = ((int)$curSt->fetchColumn() === 9) ? 'ot_close' : 'ot_cancel';
            }
            if (!ot_has_feature($pdo, (int)$uid, $_reqFeature)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '您沒有此操作的權限。']);
                exit;
            }
            if ($new_status === '') {
                $pdo->prepare("UPDATE order_track SET Order_status = NULL, Modified_By = ?, Modified_At = NOW() WHERE Order_id = ?")
                    ->execute([$uid, $order_id]);
            } else {
                $pdo->prepare("UPDATE order_track SET Order_status = ?, Modified_By = ?, Modified_At = NOW() WHERE Order_id = ?")
                    ->execute([intval($new_status), $uid, $order_id]);
            }
            $rowFmt = $pdo->prepare("SELECT DATE_FORMAT(Modified_At, '%c/%e') AS modified_fmt FROM order_track WHERE Order_id = ?");
            $rowFmt->execute([$order_id]);
            $rFmt = $rowFmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'modified_fmt' => $rFmt['modified_fmt'] ?? '']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'search_quotations') {
        header('Content-Type: application/json');
        $term = $_POST['term'] ?? '';
        try {
            // Step 1: 查報價單主體
            $sql = "SELECT ql.quote_no, ql.quote_date, ql.client_name, ql.note as quote_note,
                           qi.item_id, qi.product_id, qi.specification, qi.quantity, qi.unit_price, qi.process_notes,
                           GROUP_CONCAT(qipm.process_no ORDER BY qipm.process_no SEPARATOR ',') as process_nos
                    FROM quotation_list ql
                    JOIN quotation_item qi ON ql.quote_id = qi.quote_id
                    LEFT JOIN quotation_item_process_map qipm ON qi.item_id = qipm.quotation_item_id
                    WHERE (ql.quote_no LIKE :term OR ql.client_name LIKE :term OR qi.product_id LIKE :term)
                    GROUP BY qi.item_id
                    ORDER BY ql.quote_date DESC, ql.quote_no DESC
                    LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':term' => "%$term%"]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Step 2: 批次查製程中文名 (ProcessNo 為 int PK)
            $allPnos = [];
            foreach ($results as $r) {
                if (!empty($r['process_nos'])) {
                    foreach (explode(',', $r['process_nos']) as $pno) {
                        $pno = trim($pno);
                        if ($pno !== '') $allPnos[intval($pno)] = true;
                    }
                }
            }
            $pnMap = [];
            if (!empty($allPnos)) {
                try {
                    $pnosArr = array_keys($allPnos);
                    $ph = implode(',', array_fill(0, count($pnosArr), '?'));
                    $spn = $pdo->prepare("SELECT ProcessNo, ProcessName FROM `process_no` WHERE ProcessNo IN ($ph)");
                    $spn->execute($pnosArr);
                    foreach ($spn->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                        $pnMap[intval($pr['ProcessNo'])] = $pr['ProcessName'];
                    }
                } catch (Exception $eIgnore) {}
            }
            foreach ($results as &$r) {
                if (!empty($r['process_nos'])) {
                    $parts = array_map(function($pno) use ($pnMap) {
                        $pno = intval(trim($pno));
                        return isset($pnMap[$pno]) ? $pnMap[$pno] : $pno;
                    }, explode(',', $r['process_nos']));
                    $r['processes'] = implode(' / ', $parts);
                } else {
                    $r['processes'] = '';
                }
            }
            unset($r);
            echo json_encode(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ⚠️ [疑似未使用] get_paginated_orders：
    //    此 handler 回傳純 JSON 資料（data/stats/pagination），
    //    但前端 fetchTableData() 實際呼叫的是 'load_page_data'（回傳渲染好的 HTML）。
    //    經搜尋，此頁面前端及已知外部呼叫均無使用此 action，疑似早期版本遺留。
    //    若確認無外部呼叫（如其他頁面 iframe / API），可考慮移除。
    //    ※ 注意：此 handler 的客戶篩選與 global 搜尋條件尚未配合新版邏輯更新。
    if ($_POST['action'] === 'get_paginated_orders') {
        header('Content-Type: application/json');
        try {
            $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
            $limit = 8; // 每頁筆數，與原前端設定一致
            $offset = ($page - 1) * $limit;

            // 接收篩選條件
            $year = $_POST['year'] ?? 'ALL';
            $status = $_POST['status'] ?? 'all';
            $designer = $_POST['designer'] ?? '';
            $client = $_POST['client'] ?? '';
            $part = $_POST['part'] ?? '';
            $global = $_POST['global'] ?? '';

            $params = [];
            $whereClauses = ["1=1"];

            if ($year !== 'ALL') {
                $whereClauses[] = "YEAR(ot.Order_date) = :year";
                $params[':year'] = $year;
            }

            if (!empty($designer)) {
                $whereClauses[] = "u.user_cname LIKE :designer";
                $params[':designer'] = '%' . $designer . '%';
            }
            if (!empty($client)) {
                $whereClauses[] = "ot.Client_name LIKE :client";
                $params[':client'] = '%' . $client . '%';
            }
            if (!empty($part)) {
                $whereClauses[] = "ot.d_id LIKE :part";
                $params[':part'] = '%' . $part . '%';
            }

            if ($status === 'in_progress') {
                $whereClauses[] = "(ot.pmGet IS NULL OR ot.pmGet = '')";
            } elseif ($status === 'transferred') {
                $whereClauses[] = "(ot.pmGet IS NOT NULL AND ot.pmGet != '')";
            } elseif ($status === 'communication') {
                // 溝通中＝有設計備註且還沒轉生管，不論目前是否在審圖中都算（審圖中仍可能持續溝通修改）
                $whereClauses[] = "((ot.pmGet IS NULL OR ot.pmGet = '') AND ot.ateNote IS NOT NULL AND ot.ateNote != '')";
            }

            if (!empty($global)) {
                // 與主列表同一套：LIKE 掃全部可見欄位、多關鍵字皆須命中（此查詢未 join customer_list）
                $gFields = ['ot.d_id','ot.Client_name','ot.Processing_items','ot.Order_ps','ot.ateNote',
                            'u.user_cname','ot.Order_oo','ot.C_order','ot.Containers','ot.Sample',
                            'ot.JIG','ot.Order_date'];
                $gTokens = preg_split('/\s+/u', trim($global), -1, PREG_SPLIT_NO_EMPTY) ?: [$global];
                foreach ($gTokens as $ti => $tk) {
                    $ors = [];
                    foreach ($gFields as $fi => $f) {
                        $ph = ":gg{$ti}_{$fi}";
                        $ors[] = "$f LIKE $ph";
                        $params[$ph] = "%$tk%";
                    }
                    $whereClauses[] = '(' . implode(' OR ', $ors) . ')';
                }
            }

            $whereSql = "WHERE " . implode(' AND ', $whereClauses);

            // Query 1: 統計數據
            $statsSql = "SELECT COUNT(*) as total_records, SUM(CASE WHEN (ot.pmGet IS NULL OR ot.pmGet = '') THEN 1 ELSE 0 END) as processing, SUM(CASE WHEN (ot.pmGet IS NOT NULL AND ot.pmGet != '') THEN 1 ELSE 0 END) as done, SUM(CASE WHEN ((ot.pmGet IS NULL OR ot.pmGet = '') AND ot.ateNote IS NOT NULL AND ot.ateNote != '') THEN 1 ELSE 0 END) as communication FROM order_track ot LEFT JOIN user u ON u.id = ot.ate $whereSql";
            $stmtStats = $pdo->prepare($statsSql);
            $stmtStats->execute($params);
            $statsResult = $stmtStats->fetch(PDO::FETCH_ASSOC);

            $totalRecords = $statsResult['total_records'] ?? 0;
            $totalPages = ceil($totalRecords / $limit);

            // Query 2: 當頁資料（LEFT JOIN customer_list 取得已綁定客戶的中文名稱）
            $dataSql = "SELECT ot.*, CONCAT(DATE_FORMAT(ot.Order_date, '%y'), 'y/', DATE_FORMAT(ot.Order_date, '%c/%e')) AS Order_date_formatted, CONCAT(DATE_FORMAT(ot.Delivery_date, '%y'), 'y/', DATE_FORMAT(ot.Delivery_date, '%c/%e')) AS Delivery_date_formatted, DATE_FORMAT(ot.ateGet, '%c/%e') AS ateGet_formatted, DATE_FORMAT(ot.pmGet, '%c/%e') AS pmGet_formatted, DATE_FORMAT(ot.Created_At, '%c/%e') AS Created_At_formatted, DATE_FORMAT(ot.in_review, '%c/%e') AS in_review_formatted, u.user_cname, creator.user_cname AS creator_name, cl.customer AS cl_customer_name FROM order_track ot LEFT JOIN user u ON u.id = ot.ate LEFT JOIN user AS creator ON creator.id = ot.Created_By LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID $whereSql ORDER BY ot.last_activity_at DESC LIMIT :limit OFFSET :offset";
            $stmtData = $pdo->prepare($dataSql);
            
            $dataParams = $params;
            $dataParams[':limit'] = $limit;
            $dataParams[':offset'] = $offset;
            
            $stmtData->execute($dataParams);
            $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

            // 取得設計師每月件數 (為了顯示 badge)
            $designerCountYear = ($year === 'ALL') ? date('Y') : $year;
            $designerCountSql = "SELECT u.user_cname, DATE_FORMAT(ot.ateGet, '%c') AS month, COUNT(*) as count FROM order_track ot JOIN user u ON u.id = ot.ate WHERE YEAR(ot.ateGet) = :year AND ot.ate IS NOT NULL AND ot.ateGet IS NOT NULL GROUP BY u.user_cname, month";
            $stmtDesigner = $pdo->prepare($designerCountSql);
            $stmtDesigner->execute([':year' => $designerCountYear]);
            $designerCountsRaw = $stmtDesigner->fetchAll(PDO::FETCH_ASSOC);
            $orderCountsByDesignerMonth = [];
            foreach ($designerCountsRaw as $c) {
                $orderCountsByDesignerMonth[$c['user_cname']][(int)$c['month']] = $c['count'];
            }

            foreach ($data as &$order) {
                if (!empty($order['ateGet_formatted'])) {
                    list($m,) = explode('/', $order['ateGet_formatted']);
                    $month = intval($m);
                    $designer = $order['user_cname'];
                    $order['monthly_count'] = $orderCountsByDesignerMonth[$designer][$month] ?? 0;
                } else {
                    $order['monthly_count'] = 0;
                }
            }
            unset($order);

            echo json_encode([
                'success' => true,
                'data' => $data,
                'stats' => [
                    'all' => $totalRecords,
                    'processing' => $statsResult['processing'] ?? 0,
                    'done' => $statsResult['done'] ?? 0,
                    'communication' => $statsResult['communication'] ?? 0,
                ],
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => $totalPages,
                    'totalRecords' => $totalRecords,
                    'limit' => $limit,
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => '後端錯誤：' . $e->getMessage()]);
        }
        exit;
    }

    // ── 設計備註查看（訂單追蹤用，唯讀）───────────────────────────────────
    if ($_POST['action'] === 'get_design_notes_ot') {
        header('Content-Type: application/json');
        try {
            $part_id = trim($_POST['part_id'] ?? '');
            $cust_id = trim($_POST['cust_id'] ?? '');
            // 備註圖片改走讀檔 API（與主檔管理同一支），不再用 Apache /nas 別名直連：
            // 存放位置只由 notes_nas_dir 決定，換 NAS 免改 httpd.conf 也不綁磁碟機代號
            $nas_url = '../../src/store/NoteImage_API.php?f=';
            $result  = ['part_notes' => [], 'cust_notes' => [], 'nas_url' => $nas_url];
            $imgStmt = $pdo->prepare("SELECT img_id, file_name, original_name FROM note_images
                WHERE note_id=? AND note_type=? ORDER BY sort_order, img_id");
            if ($part_id) {
                $stmt = $pdo->prepare("SELECT note_id, note_text, created_by,
                    DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at
                    FROM design_notes WHERE target_type='part' AND target_id=? ORDER BY created_at ASC");
                $stmt->execute([$part_id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $imgStmt->execute([$r['note_id'], 'part_design']);
                    $r['images'] = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
                    $result['part_notes'][] = $r;
                }
            }
            if ($cust_id) {
                $stmt2 = $pdo->prepare("SELECT note_id, note_text, created_by,
                    DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at
                    FROM design_notes WHERE target_type='customer' AND target_id=? ORDER BY created_at ASC");
                $stmt2->execute([$cust_id]);
                foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $imgStmt->execute([$r['note_id'], 'customer_design']);
                    $r['images'] = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
                    $result['cust_notes'][] = $r;
                }
            }
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 依料號查庫存（新增/編輯訂單 Modal 用）──────────────────────────────
    if ($_POST['action'] === 'get_stock_by_did') {
        header('Content-Type: application/json');
        try {
            $did = trim($_POST['d_id'] ?? '');
            if ($did === '') { echo json_encode(['success'=>true,'data'=>[]]); exit; }
            // 與 stock.php 相同邏輯：偵測 location_id 欄位，有則 JOIN stock_locations 取正確儲位顯示碼
            $stk_cols_chk = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
            $stk_has_loc  = in_array('location_id', $stk_cols_chk);
            $stk_loc_join = $stk_has_loc ? "LEFT JOIN stock_locations _sl ON _sl.location_id = si.location_id" : "";
            $stk_loc_sel  = $stk_has_loc ? "COALESCE(_sl.location_code, si.storage_location)" : "si.storage_location";
            $sq = $pdo->prepare("SELECT $stk_loc_sel AS storage_location,
                si.qty,
                COALESCE(si.remark1,'') AS remark,
                COALESCE(sic.category_name,'') AS category_name,
                COALESCE(sic.color,'#777')     AS category_color,
                CASE WHEN si.group_id IS NOT NULL THEN 1 ELSE 0 END AS is_combo
                FROM stock_items si
                LEFT JOIN stock_item_categories sic ON sic.category_id = si.item_type
                $stk_loc_join
                WHERE si.d_id = ? AND si.is_active = 1
                ORDER BY (si.group_id IS NULL) DESC, si.qty DESC");
            $sq->execute([$did]);
            echo json_encode(['success'=>true, 'data'=>$sq->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 客戶模糊搜尋（篩選列 autocomplete 用）─────────────────────────────
    if ($_POST['action'] === 'autocomplete_customers_ot') {
        header('Content-Type: application/json');
        try {
            $kw = trim($_POST['keyword'] ?? '');
            if ($kw === '') { echo json_encode(['success'=>true,'data'=>[]]); exit; }
            $stmt = $pdo->prepare("SELECT customer_id, customer FROM customer_list
                WHERE is_active=1 AND (customer_id LIKE ? OR customer LIKE ?)
                ORDER BY customer LIMIT 20");
            $stmt->execute(["%$kw%", "%$kw%"]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// --- 權限管理 (參考 OreadyReply_ForPm_BaseOfTime.php) ---
$db = $conn->getPDO();
$id = intval($_SESSION['id'] ?? 0);
// $current_script_path = $_SERVER['PHP_SELF'];
$current_script_path = '/EGsystem/src/store/_cleanOrder_Track_ate_only.php';

$permission_code = null;
$page_url_editable = '';
$page_url_readonly = '';

try {
    // 1. 依據 URL 找到頁面
    $sql_page_info = "
        SELECT smp.page_id, smp.page_url, smp.page_url_readonly, smp.group_id
        FROM system_module_pages smp
        WHERE (:script LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
           OR (:script LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
        LIMIT 1
    ";
    $stmt_page_info = $db->prepare($sql_page_info);
    $stmt_page_info->execute([':script' => $current_script_path]);
    $page_info = $stmt_page_info->fetch(PDO::FETCH_ASSOC);

    if ($page_info) {
        $page_url_editable = $page_info['page_url'];
        $page_url_readonly = $page_info['page_url_readonly'];
        $page_id = $page_info['page_id'];
        $group_id = $page_info['group_id'];

        // 2. 取得群組模組代碼
        $group_module_code = null;
        if (!empty($group_id)) {
            $stmt_group = $db->prepare("SELECT module_code FROM system_modules WHERE group_id = :gid LIMIT 1");
            $stmt_group->execute([':gid' => $group_id]);
            $group_module_code = $stmt_group->fetchColumn();
        }

        // 3. 查找使用者權限 (優先查頁面，再查群組)
        $user_perms = [];
        
        // 3a. 頁面權限
        $stmt_page_perm = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id = :uid AND scope = 'page' AND module_code = :pid");
        $stmt_page_perm->execute([':uid' => $id, ':pid' => $page_id]);
        $page_perms = $stmt_page_perm->fetchAll(PDO::FETCH_COLUMN);
        $page_perms = array_filter($page_perms);

        if (!empty($page_perms)) {
            $user_perms = $page_perms;
        } elseif (!empty($group_module_code)) {
            // 3b. 群組權限
            $stmt_group_perm = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id = :uid AND scope = 'group' AND module_code = :mcode");
            $stmt_group_perm->execute([':uid' => $id, ':mcode' => $group_module_code]);
            $group_perms = $stmt_group_perm->fetchAll(PDO::FETCH_COLUMN);
            $group_perms = array_filter($group_perms);
            if (!empty($group_perms)) {
                $user_perms = $group_perms;
            }
        }

        // 4. 整合權限
        $all_chars = [];
        foreach ($user_perms as $p) {
            $all_chars = array_merge($all_chars, str_split($p));
        }
        $unique_perms = array_unique($all_chars);

        if (in_array('A', $unique_perms)) {
            $permission_code = 'A';
        } elseif (!empty($unique_perms)) {
            sort($unique_perms);
            $permission_code = implode('', $unique_perms);
        }
    }
} catch (Exception $e) {
    error_log("Permission check error: " . $e->getMessage());
}

// 將權限結果寫入 Session 快取，供 _OrderChange_API.php 等子請求沿用
if ($id) {
    $_SESSION['perm_code_newordertrack_' . $id] = $permission_code;
}

// 判斷權限並導向
if (is_null($permission_code)) {
    // 若資料庫未設定此頁面，暫時允許訪問但無權限 (或可選擇導向)
    // echo "<script>alert('您無權限訪問此頁面'); window.location.href='../../index.php';</script>";
    // exit;
}

if ($permission_code === 'R') {
    // 唯讀權限導向
    if (!empty($page_url_editable) && substr($current_script_path, -strlen($page_url_editable)) === $page_url_editable) {
        if (!empty($page_url_readonly)) {
            header("Location: " . $page_url_readonly);
            exit;
        }
    }
}

// 設定功能變數
$can_create = ($permission_code && (strpos($permission_code, 'A') !== false || strpos($permission_code, 'C') !== false));
$can_update = ($permission_code && (strpos($permission_code, 'A') !== false || strpos($permission_code, 'U') !== false));
$can_delete = ($permission_code && (strpos($permission_code, 'A') !== false || strpos($permission_code, 'D') !== false));
// 設計備註可編輯：只有 A 或有 'X'(設計)權限 才能編輯 ateNote；業務人員(C+R+U+D 但無 A)不可編輯
$can_edit_ateNote = ($permission_code === 'A' || ($permission_code && strpos($permission_code, 'X') !== false));
// 以下細部按鈕的舊制判斷（沿用原本散落頁面各處的門檻）；RBAC 啟用後改走對應功能碼，見下方 $OT_USE_RBAC 覆寫區塊
$is_perm_a                = ($permission_code === 'A');  // 設計備註徽章/查詢等顯示用
$can_batch_draw            = ($can_update && $permission_code === 'A'); // 審圖/取消審圖
$can_to_pm                 = ($can_update && $permission_code === 'A'); // 轉生管/取消轉生管
$can_order_change          = $can_update;                               // 訂單變更（舊制沿用一般編輯權限）
$can_order_change_setting  = ($permission_code === 'A');                // 訂單變更設定
$can_op_convert            = $can_create;                               // OP轉訂單（舊制沿用一般新增權限）
$can_view_amount           = true;                                      // 金額顯示（舊制從未限制過，一律可見）
$can_keyway_calc           = true;                                      // 鍵槽計算（舊制從未限制過，一律可見）
$can_designer_assign_cog   = ($permission_code === 'A');                // 指派設計旁的設定齒輪（無專屬功能碼，沿用管理員門檻）
$can_master_edit           = ($permission_code === 'A');                // 前往料號主檔編輯按鈕（舊制沿用管理員門檻；RBAC 啟用後改走 ot_master_edit）

// 是否顯示操作欄位 (只有 R 權限時不顯示)
$show_op_col = ($can_create || $can_update);

// ── 齒輪計算工具：顯示權限 ────────────────────────────────────────────────
$is_gear_admin  = in_array(intval($_SESSION['status'] ?? 0), [9, 90]);
$show_gear_tool = $is_gear_admin; // 管理員一律可見
if (!$show_gear_tool) {
    try {
        require_once __DIR__ . '/../../src/common/org_role_lib.php';   // 技術部門統一設定（含子部門）
        $gear_dept_ids = eg_org_dept_ids($db, 'rd_dept');
        if (!$gear_dept_ids) {
            $gs_r = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='gear_tool_tech_dept_ids'")->fetch(PDO::FETCH_ASSOC);
            $gear_dept_ids = $gs_r ? (json_decode($gs_r['setting_value'], true) ?: []) : [];
        }
        if (!empty($gear_dept_ids)) {
            $gph = implode(',', array_fill(0, count($gear_dept_ids), '?'));
            $gc  = $db->prepare("SELECT 1 FROM user_department_position_map WHERE user_id=? AND department_id IN ($gph) LIMIT 1");
            $gc->execute(array_merge([intval($_SESSION['id'] ?? 0)], array_map('intval', $gear_dept_ids)));
            $show_gear_tool = (bool)$gc->fetch();
        }
    } catch (Exception $e) { /* 無法查詢時不顯示 */ }
}


// 計算顯示用的權限代碼 (例如: C+R, R+U+D)
$display_permission_code = '';
if ($permission_code === 'A') {
    $display_permission_code = 'A';
} elseif ($permission_code) {
    $parts = str_split($permission_code);
    sort($parts);
    $display_permission_code = implode('+', $parts);
}

// ══════════════════════════════════════════════════════════════════════════
// RBAC 角色權限（module='order_track'）—— 2026-08-11 已啟用（測試版）
// 使用者已在角色設定頁補齊原本舊制有權限、但尚未指派新角色的 6 人，並確認要在測試版切換。
// ══════════════════════════════════════════════════════════════════════════
$OT_USE_RBAC = true; // ★切換開關：true = 改用 roles/role_features 檢查功能權限

$_ot_features  = [];
$_ot_my_roles  = [];
$_ot_has_roles = false;
$IS_OT_RBAC_ADMIN = false;
try {
    // 功能碼一律走全站共用 helper（個人指派 ∪ 職稱指派 ∪ 請假完整承接代理，且非在職者自動回傳空陣列），
    // 不要在本頁自己重複拼 user_roles 查詢——否則會漏掉職稱指派與離職封鎖，跟其他模組行為不一致
    require_once __DIR__ . '/../../src/common/role_features_helper.php';
    $_ot_features = rf_load_user_features_all($db, $id);
    $IS_OT_RBAC_ADMIN = in_array('all', $_ot_features, true);
    $_ot_has_roles = !empty($_ot_features);
    if ($_ot_has_roles) {
        // 僅供本頁「目前角色」顯示用（非權限判斷）：只列對本頁有權限的角色名稱
        $_ot_st = $db->prepare("
            SELECT DISTINCT r.role_name, rf.feature_code
            FROM user_roles ur
            JOIN roles r ON r.role_id = ur.role_id
            JOIN role_features rf ON rf.role_id = ur.role_id
            WHERE ur.user_id = ?");
        $_ot_st->execute([$id]);
        foreach ($_ot_st->fetchAll(PDO::FETCH_ASSOC) as $_ot_r) {
            if ($_ot_r['feature_code'] === 'all' || strpos((string)$_ot_r['feature_code'], 'ot_') === 0) {
                $_ot_my_roles[] = $_ot_r['role_name'];
            }
        }
        $_ot_my_roles = array_unique($_ot_my_roles);
    }
} catch (Exception $_ot_e) {}

function ot_hasF(string $f): bool {
    global $_ot_features;
    return in_array('all', $_ot_features, true) || in_array($f, $_ot_features, true);
}

// ── 本頁功能清單（角色設定勾選用；依功能分組）────────────────────────────
$OT_PAGE_FEATURES = [
    ['group'=>'訂單基本操作', 'code'=>'ot_view',                 'label'=>'檢視訂單'],
    ['group'=>'訂單基本操作', 'code'=>'ot_edit',                 'label'=>'新建/編輯訂單'],
    ['group'=>'訂單基本操作', 'code'=>'ot_delete',               'label'=>'刪除訂單按鈕'],
    ['group'=>'訂單基本操作', 'code'=>'ot_view_amount',          'label'=>'顯示訂單金額（單價）'],
    ['group'=>'訂單基本操作', 'code'=>'ot_attach_delete',        'label'=>'刪除他人上傳的附件（預設只有上傳者本人與管理員可刪）'],
    ['group'=>'訂單流程',     'code'=>'ot_batch_draw',           'label'=>'批圖按鈕（審圖/取消審圖）'],
    ['group'=>'訂單流程',     'code'=>'ot_to_pm',                'label'=>'轉生管按鈕（含取消轉生管）'],
    ['group'=>'訂單流程',     'code'=>'ot_close',                'label'=>'結案按鈕（訂單完結/解除完結）'],
    ['group'=>'訂單流程',     'code'=>'ot_cancel',               'label'=>'取消訂單按鈕（暫停/取消/解除）'],
    ['group'=>'訂單流程',     'code'=>'ot_op_convert',           'label'=>'OP轉訂單'],
    ['group'=>'訂單變更',     'code'=>'ot_order_change',         'label'=>'訂單變更'],
    ['group'=>'訂單變更',     'code'=>'ot_order_change_setting', 'label'=>'訂單變更設定'],
    ['group'=>'設計與批圖',   'code'=>'ot_design_note',          'label'=>'設計備註（編輯）'],
    ['group'=>'設計與批圖',   'code'=>'ot_img_editor',           'label'=>'批圖編輯器'],
    ['group'=>'設計與批圖',   'code'=>'ot_master_edit',          'label'=>'前往料號主檔編輯按鈕'],
    ['group'=>'計算工具',     'code'=>'ot_gear_calc',            'label'=>'齒輪計算'],
    ['group'=>'計算工具',     'code'=>'ot_keyway_calc',          'label'=>'鍵槽計算'],
];

// ── RBAC 權限檢查（$OT_USE_RBAC = true 時生效）───────────────────────────
// 全頁散落的舊制 `$permission_code === 'A'`／`$can_update` 判斷已於上方全部
// 改成呼叫下列覆寫變數，啟用後即整頁一致改走角色制；JS 端以 window.OT_FEAT
//（見頁尾 script）判斷。
if ($OT_USE_RBAC) {
    $can_create               = ot_hasF('ot_edit');
    $can_update                = ot_hasF('ot_edit');
    $can_delete                = ot_hasF('ot_delete');
    $can_edit_ateNote           = ot_hasF('ot_design_note');
    $is_perm_a                 = ot_hasF('ot_design_note');
    $can_batch_draw             = ot_hasF('ot_batch_draw');
    $can_to_pm                  = ot_hasF('ot_to_pm');
    $can_order_change           = ot_hasF('ot_order_change');
    $can_order_change_setting  = ot_hasF('ot_order_change_setting');
    $can_op_convert             = ot_hasF('ot_op_convert');
    $can_view_amount            = ot_hasF('ot_view_amount');
    $can_keyway_calc            = ot_hasF('ot_keyway_calc');
    $can_designer_assign_cog   = $IS_OT_RBAC_ADMIN;
    $can_master_edit           = ot_hasF('ot_master_edit');
    $show_op_col      = ($can_create || $can_update);
    $show_gear_tool   = $IS_OT_RBAC_ADMIN || ot_hasF('ot_gear_calc'); // 齒輪計算改由角色控制
    if (!$IS_OT_RBAC_ADMIN && !ot_hasF('ot_view')) {
        header('HTTP/1.1 403 Forbidden');
        echo '您沒有瀏覽此頁面的權限。';
        exit;
    }
}
// 審圖/轉生管的「只有本人（該訂單指定的設計人員 order_track.ate）才能操作」限制，誰可以豁免。
// 2026-08-20 使用者拍板：【只有超級管理員(id=1)】，其餘任何人（含持有系統「管理員」角色者）一律受限。
// 為什麼改：本頁 2026-08-11 切換 RBAC 後，這裡原本寫 $IS_OT_RBAC_ADMIN（＝全站系統角色「管理員」，
// 功能碼 all），認定基準跟舊制的頁面權限碼 'A' 不一樣——舊制只有 CRUD 沒有 'A'、order_track 角色是
// 「業務」（刻意沒勾 ot_batch_draw/ot_to_pm）的人，只要另外掛著系統管理員角色就會每一列都長出
// 審圖/轉生管按鈕（原本只顯示文字狀態）。後端同規則在 src/common/order_track_perm_lib.php 的
// ot_can_operate_design()，兩邊要一起改。
$OT_IS_ADMIN_ANY = ($id === 1);

if (!($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))) {
    $ate_list = $conn->getAll("SELECT `user_cname`,`user_uname`,`id` FROM `user` WHERE `user_status`=63");

    // 補充：將 designer_config 裡額外支援部門的人員也加入 $ate_list
    try {
        $cfg_row = $conn->getPDO()->query(
            "SELECT param_value FROM system_parameters
             WHERE param_group = 'DESIGNER_SETTING' AND param_key = 'designer_config'"
        )->fetch(PDO::FETCH_ASSOC);
        if ($cfg_row) {
            $cfg = json_decode($cfg_row['param_value'], true);
            $extra_ids = [];
            foreach (($cfg['extra_depts'] ?? []) as $ed) {
                foreach (($ed['users'] ?? []) as $eu) {
                    $uid = is_array($eu) ? intval($eu['id'] ?? 0) : intval($eu);
                    if ($uid > 0) $extra_ids[] = $uid;
                }
            }
            // 去除已在 $ate_list 裡的 ID
            $existing_ids = array_column($ate_list, 'id');
            $new_ids = array_values(array_diff($extra_ids, $existing_ids));
            if (!empty($new_ids)) {
                // 建立 id => desc 對照（從所有 extra_depts 裡收集）
                $extra_id_desc = [];
                foreach (($cfg['extra_depts'] ?? []) as $ed) {
                    foreach (($ed['users'] ?? []) as $eu) {
                        if (is_array($eu) && !empty($eu['id'])) {
                            $extra_id_desc[intval($eu['id'])] = $eu['desc'] ?? '';
                        }
                    }
                }
                $ph = implode(',', array_fill(0, count($new_ids), '?'));
                $st = $conn->getPDO()->prepare(
                    "SELECT `user_cname`,`user_uname`,`id` FROM `user` WHERE `id` IN ($ph)"
                );
                $st->execute($new_ids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    $u['extra_desc'] = $extra_id_desc[intval($u['id'])] ?? '';
                    $ate_list[] = $u;
                }
            }
        }
    } catch (Exception $e) { /* 查不到設定時靜默跳過 */ }
}
// =============================================================================
// AJAX: Get Product Files
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] === 'get_product_files') {
            $pid = $_POST['product_id'];
            
            $stmt = $conn->getPDO()->prepare("SELECT bom, sqty FROM bom WHERE d_id = ? ORDER BY Created_At DESC");
            $stmt->execute([$pid]);
            $bom_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $files = [];
            require_once __DIR__ . '/../../src/common/bom_dir_lib.php';   // 資料夾位置走設定鍵 bom_scan_dir，不再寫死 Z: 磁碟機代號
            $scan_dir = eg_bom_scan_dir_auto(); 
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
                                $display_bom = $bom . ' (Qty:' . ($qty !== null ? $qty : '?') . ')';
                                $files[] = [
                                    'bom' => $display_bom, 
                                    'name' => $f, 
                                    'path' => $url_dir . $f, 
                                    'type' => $ext,
                                    'mtime' => filemtime($scan_dir . $f)
                                ];
                            }
                        }
                    }
                }
            }
            
            usort($files, function($a, $b) {
                return $b['mtime'] - $a['mtime'];
            });

            echo json_encode(['success' => true, 'files' => $files]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// --- 新增：AJAX 分頁與篩選 API (由前端 JS 呼叫) ---
if (isset($_POST['action']) && $_POST['action'] === 'load_page_data') {
    $pdo = $conn->getPDO(); // [FIX] 確保 $pdo 變數在此作用域可用
    ob_start(); // 【防護罩開啟】攔截所有意外警告、空白行

    header('Content-Type: application/json');

    // 1. 權限變數：load_page_data 這個 action 不在檔案最上方的 POST 動作分派區塊內，
    // 執行到這裡之前一定會先跑過檔案前段「權限管理」那段（$permission_code/$can_*/RBAC 覆寫都已算好），
    // 不要在這裡重複重算一次――否則 RBAC 開關切換時，AJAX 分頁這邊會繼續用舊邏輯，跟整頁載入不一致。

    // 2. 處理分頁變數、SQL WHERE 條件、執行 Query
    $page = max(1, intval($_POST['page'] ?? 1));
    $limit = 8;
    $offset = ($page - 1) * $limit;
    
    $year = $_POST['year'] ?? 'ALL';
    $status = $_POST['status'] ?? 'all';
    $order_status_filter = trim($_POST['order_status_filter'] ?? ''); // 半高卡片第一優先篩選
    $designer = $_POST['designer'] ?? '';
    $client = $_POST['client'] ?? '';
    $part = $_POST['part'] ?? '';
    $global = $_POST['global'] ?? '';
    $unbound = !empty($_POST['unbound']) ? (int)$_POST['unbound'] : 0;
    $unbound_op = !empty($_POST['unbound_op']) ? (int)$_POST['unbound_op'] : 0;
    $qty_over = !empty($_POST['qty_over']) ? (int)$_POST['qty_over'] : 0; // 轉單數量超出階梯區間篩選
    // 相容舊表：qty_over_range 首次執行自動補欄（統計/篩選 SQL 會引用；部署後第一個請求可能是 AJAX 而非整頁載入）
    try { $pdo->query("SELECT qty_over_range FROM order_track LIMIT 1"); }
    catch (Exception $_eQov) {
        try { $pdo->exec("ALTER TABLE order_track ADD COLUMN qty_over_range TINYINT(1) NOT NULL DEFAULT 0 COMMENT '轉單數量超出報價階梯區間(含容差後)=1,待補報價單'"); } catch (Exception $_eQov2) {}
    }
    // 相容舊表：is_repeat_conversion（OP追加轉單旗標）首次執行自動補欄
    try { $pdo->query("SELECT is_repeat_conversion FROM order_track LIMIT 1"); }
    catch (Exception $_eRep) {
        try { $pdo->exec("ALTER TABLE order_track ADD COLUMN is_repeat_conversion TINYINT(1) NOT NULL DEFAULT 0 COMMENT '同一報價項目先前已轉過訂單、此為追加訂單=1(不影響KPI報價轉訂單比例統計)'"); } catch (Exception $_eRep2) {}
    }
    // 相容舊表：is_urgent（急件旗標）首次執行自動補欄
    try { $pdo->query("SELECT is_urgent FROM order_track LIMIT 1"); }
    catch (Exception $_eUrg) {
        try { $pdo->exec("ALTER TABLE order_track ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 COMMENT '急件=1；篩選批圖中時排最上方(多筆依接單日新到舊)，清單以淺暖粉紅底色標示'"); } catch (Exception $_eUrg2) {}
    }

    $whereClauses = ["1=1", "(ot.parent_order_id IS NULL OR ot.parent_order_id = 0)"];
    $params = [];

    if ($year !== 'ALL') {
        $whereClauses[] = "ot.Order_date >= :year_start AND ot.Order_date < :year_end";
        $params[':year_start'] = intval($year) . '-01-01';
        $params[':year_end']   = (intval($year) + 1) . '-01-01';
    }
    if (!empty($designer)) {
        if ($designer === '__none__') {
            // 無設計：ate = 2 (無/不經設計) 或 ate IS NULL
            $whereClauses[] = "(ot.ate IS NULL OR ot.ate = 2)";
        } else {
            $whereClauses[] = "u.user_cname LIKE :designer";
            $params[':designer'] = "%$designer%";
        }
    }
    if (!empty($client)) {
        // 支援客戶ID / 客戶名稱 / 手輸暫存名 模糊搜尋
        $whereClauses[] = "(cl.customer LIKE :client OR cl.customer_id LIKE :client OR ot.Client_name LIKE :client)";
        $params[':client'] = "%$client%";
    }
    if (!empty($part)) {
        $params[':part'] = "%$part%";
        // 先查出 Drawing_No 符合的料號清單，避免 EXISTS 關聯子查詢拖慢主查詢
        try {
            $dnStmt = $pdo->prepare("SELECT DISTINCT D_Setting_Id FROM d_setting WHERE Drawing_No LIKE ? AND Drawing_No IS NOT NULL AND Drawing_No != ''");
            $dnStmt->execute(["%$part%"]);
            $dnMatchIds = $dnStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $_e) { $dnMatchIds = []; }
        if (!empty($dnMatchIds)) {
            $dnInSql = implode(',', array_map([$pdo, 'quote'], $dnMatchIds));
            $whereClauses[] = "(ot.d_id LIKE :part OR ot.d_id IN ($dnInSql))";
        } else {
            $whereClauses[] = "ot.d_id LIKE :part";
        }
    }
    if ($unbound === 1) {
        $whereClauses[] = "(ot.Client_name_ID IS NULL OR ot.Client_name_ID = '' OR ot.d_id_ID IS NULL OR ot.d_id_ID = 0)";
    }
    if ($unbound_op === 1) {
        // 未綁定OP條件暫時停用（OP單據尚未開始使用）；恢復時在 OR 前取消 /*…OR*/ 的 SQL 注解
        $whereClauses[] = "(/* ot.quote_no IS NULL OR ot.quote_no = '' OR */ ot.unit_price IS NULL OR ot.unit_price = 0)";
    }
    if ($qty_over === 1) {
        // OP轉訂單時輸入數量超出報價階梯區間（含容差後區間）的訂單，供補報價單追蹤
        $whereClauses[] = "ot.qty_over_range = 1";
    }
    // 半高卡片第一優先篩選（優先於 status 篩選）
    if ($order_status_filter === 'unfinished') {
        $whereClauses[] = "(ot.Order_status IS NULL)";
    } elseif ($order_status_filter === 'paused') {
        $whereClauses[] = "(ot.Order_status = 6)";
    } elseif ($order_status_filter === 'closed') {
        $whereClauses[] = "(ot.Order_status = 9)";
    }
    if ($status === 'in_progress') {
        $whereClauses[] = "(ot.pmGet IS NULL AND (ot.Order_status IS NULL OR ot.Order_status != 6))";
    } elseif ($status === 'transferred') {
        $whereClauses[] = "(ot.pmGet IS NOT NULL AND (ot.Order_status IS NULL OR ot.Order_status != 6))";
    } elseif ($status === 'communication') {
        $whereClauses[] = "(ot.pmGet IS NULL AND ot.ateNote IS NOT NULL AND ot.ateNote != '' AND (ot.Order_status IS NULL OR ot.Order_status != 6))";
    }
    if (!empty($global)) {
        // 全表搜尋一律用 LIKE 掃全部可見欄位。
        // 【禁止改回 ngram FULLTEXT】MATCH ... AGAINST('+"RC105-N03-A"') 對含「-」的料號回傳 0 筆
        // （ngram 把 - 當分隔字元，片語比對不成立），LIKE 才找得到；本表僅約 8500 列，LIKE 全掃夠快。
        $gFields = ['ot.d_id','ot.Client_name','cl.customer','ot.Processing_items','ot.Order_ps',
                    'ot.ateNote','u.user_cname','ot.Order_oo','ot.C_order','ot.Containers',
                    'ot.Sample','ot.JIG','ot.Order_date'];
        // 多個關鍵字＝每個字都要命中（可命中不同欄位）
        $gTokens = preg_split('/\s+/u', trim($global), -1, PREG_SPLIT_NO_EMPTY) ?: [$global];
        foreach ($gTokens as $ti => $tk) {
            $ors = [];
            foreach ($gFields as $fi => $f) {
                $ph = ":g{$ti}_{$fi}";
                $ors[] = "$f LIKE $ph";
                $params[$ph] = "%$tk%";
            }
            $whereClauses[] = '(' . implode(' OR ', $ors) . ')';
        }
    }

    $whereSql = "WHERE " . implode(' AND ', $whereClauses);

    // 主統計：total_records/processing/done/communication/unbound_op 依照當前 WHERE（含 order_status_filter）
    // processing/done/communication 只在 unfinished 篩選時有意義
    if ($order_status_filter === 'unfinished') {
        $mainStatsSql = "SELECT COUNT(*) as total_records,
            SUM(CASE WHEN (ot.pmGet IS NULL AND ot.Order_status IS NULL) THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN (ot.pmGet IS NOT NULL AND ot.Order_status IS NULL) THEN 1 ELSE 0 END) as done,
            SUM(CASE WHEN (ot.pmGet IS NULL AND ot.ateNote IS NOT NULL AND ot.ateNote != '' AND ot.Order_status IS NULL) THEN 1 ELSE 0 END) as communication,
            SUM(CASE WHEN (/* ot.quote_no IS NULL OR ot.quote_no = '' OR */ ot.unit_price IS NULL OR ot.unit_price = 0) THEN 1 ELSE 0 END) as unbound_op,
            SUM(CASE WHEN (ot.qty_over_range = 1) THEN 1 ELSE 0 END) as qty_over
            FROM order_track ot LEFT JOIN user u ON u.id = ot.ate LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID $whereSql";
    } else {
        // 無半高篩選（paused/closed/無選）：正常計算 processing/done/communication
        // paused/closed 時後端已在 $whereSql 加入 Order_status=6/9 條件，這三個自然會是0
        $mainStatsSql = "SELECT COUNT(*) as total_records,
            SUM(CASE WHEN (ot.pmGet IS NULL AND (ot.Order_status IS NULL OR ot.Order_status != 6)) THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN (ot.pmGet IS NOT NULL AND (ot.Order_status IS NULL OR ot.Order_status != 6)) THEN 1 ELSE 0 END) as done,
            SUM(CASE WHEN (ot.pmGet IS NULL AND ot.ateNote IS NOT NULL AND ot.ateNote != '' AND (ot.Order_status IS NULL OR ot.Order_status != 6)) THEN 1 ELSE 0 END) as communication,
            SUM(CASE WHEN (/* ot.quote_no IS NULL OR ot.quote_no = '' OR */ ot.unit_price IS NULL OR ot.unit_price = 0) THEN 1 ELSE 0 END) as unbound_op,
            SUM(CASE WHEN (ot.qty_over_range = 1) THEN 1 ELSE 0 END) as qty_over
            FROM order_track ot LEFT JOIN user u ON u.id = ot.ate LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID $whereSql";
    }
    $stmtMain = $pdo->prepare($mainStatsSql);
    $stmtMain->execute($params);
    $mainResult = $stmtMain->fetch(PDO::FETCH_ASSOC);

    // 三個半高卡片數字：只跟年份走，不受其他篩選影響
    $y = intval($year);
    $yearCond = ($year !== 'ALL') ? "WHERE ot.Order_date >= '$y-01-01' AND ot.Order_date < '" . ($y+1) . "-01-01'" : "WHERE 1=1";
    $globalStatsSql = "SELECT
        SUM(CASE WHEN (ot.Order_status = 6) THEN 1 ELSE 0 END) as paused,
        SUM(CASE WHEN (ot.Order_status = 9) THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN (ot.Order_status IS NULL) THEN 1 ELSE 0 END) as unfinished
        FROM order_track ot $yearCond";
    $globalResult = $pdo->query($globalStatsSql)->fetch(PDO::FETCH_ASSOC);

    $statsResult = [
        'total_records' => $mainResult['total_records'] ?? 0,
        'processing'    => $mainResult['processing'] ?? 0,
        'done'          => $mainResult['done'] ?? 0,
        'communication' => $mainResult['communication'] ?? 0,
        'unbound_op'    => $mainResult['unbound_op'] ?? 0,
        'qty_over'      => $mainResult['qty_over'] ?? 0,
        'paused'        => $globalResult['paused'] ?? 0,
        'closed'        => $globalResult['closed'] ?? 0,
        'unfinished'    => $globalResult['unfinished'] ?? 0,
    ];

    // 排序（使用者明確要求，2026-09-03）：只有「批圖中」篩選會把急件拉到最上方，
    // 多筆急件依接單日(Order_date)由新到舊；非急件與其他篩選一律維持原本的建檔時間新到舊，不動既有行為。
    $orderBySql = ($status === 'in_progress')
        ? "ORDER BY ot.is_urgent DESC, CASE WHEN ot.is_urgent = 1 THEN ot.Order_date END DESC, ot.Created_At DESC"
        : "ORDER BY ot.Created_At DESC";

    $dataSql = "SELECT ot.*, 
        CONCAT(DATE_FORMAT(ot.Order_date, '%y'), 'y/', DATE_FORMAT(ot.Order_date, '%c/%e')) AS Order_date_formatted, 
        CONCAT(DATE_FORMAT(ot.Delivery_date, '%y'), 'y/', DATE_FORMAT(ot.Delivery_date, '%c/%e')) AS Delivery_date_formatted, 
        DATE_FORMAT(ot.ateGet, '%c/%e') AS ateGet_formatted, 
        DATE_FORMAT(ot.pmGet, '%c/%e') AS pmGet_formatted, 
        DATE_FORMAT(ot.Created_At, '%c/%e') AS Created_At_formatted, 
        DATE_FORMAT(ot.in_review, '%c/%e') AS in_review_formatted, 
        u.user_cname, creator.user_cname AS creator_name,
        CASE WHEN (ot.Client_name_ID IS NOT NULL AND ot.Client_name_ID != '') THEN 1 ELSE 0 END AS has_client_id,
        CASE WHEN (ot.d_id_ID IS NOT NULL AND ot.d_id_ID != 0) THEN 1 ELSE 0 END AS has_part_id,
        cl.customer AS cl_customer_name,
        DATE_FORMAT(ot.Modified_At, '%c/%e') AS Modified_At_formatted
        FROM order_track ot LEFT JOIN user u ON u.id = ot.ate LEFT JOIN user AS creator ON creator.id = ot.Created_By
        LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
        $whereSql $orderBySql LIMIT $limit OFFSET $offset";
    $stmtData = $pdo->prepare($dataSql);
    $stmtData->execute($params);
    $order_list = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // 批次查詢 display_unit_price（取代 correlated subquery，整頁只跑一次）
    if (!empty($order_list)) {
        $needQuoteNos = [];
        foreach ($order_list as $o) {
            if ((empty($o['unit_price']) || floatval($o['unit_price']) == 0) && !empty($o['quote_no'])) {
                $needQuoteNos[] = $o['quote_no'];
            }
        }
        $needQuoteNos = array_values(array_unique($needQuoteNos));
        $quoteItemMap = [];
        if (!empty($needQuoteNos)) {
            $phQ = implode(',', array_fill(0, count($needQuoteNos), '?'));
            $sqStmt = $pdo->prepare("SELECT ql.quote_no, qi.product_id, qi.unit_price, qi.item_id
                FROM quotation_list ql
                JOIN quotation_item qi ON ql.quote_id = qi.quote_id
                WHERE ql.quote_no IN ($phQ) AND qi.unit_price > 0
                ORDER BY qi.item_id DESC");
            $sqStmt->execute($needQuoteNos);
            foreach ($sqStmt->fetchAll(PDO::FETCH_ASSOC) as $qi) {
                $quoteItemMap[$qi['quote_no']][] = $qi;
            }
        }
        foreach ($order_list as &$order) {
            $up = floatval($order['unit_price'] ?? 0);
            if ($up > 0) {
                $order['display_unit_price'] = $up;
                continue;
            }
            $qno = $order['quote_no'] ?? '';
            $did = $order['d_id'] ?? '';
            $order['display_unit_price'] = null;
            if (!empty($qno) && !empty($did) && isset($quoteItemMap[$qno])) {
                foreach ($quoteItemMap[$qno] as $qi) {
                    if (strpos($qi['product_id'], $did) !== false) {
                        $order['display_unit_price'] = floatval($qi['unit_price']);
                        break;
                    }
                }
            }
        }
        unset($order);
    }

    // 設計師當月接單統計（1 分鐘 Session 快取）
    $ldpDesignerCounts = [];
    try {
        $ldpYear    = ($year !== 'ALL') ? intval($year) : intval(date('Y'));
        $dc_key     = 'designer_counts_' . $ldpYear;
        $dc_key_ts  = $dc_key . '_ts';
        if (array_key_exists($dc_key, $_SESSION) && (time() - ($_SESSION[$dc_key_ts] ?? 0)) < 60) {
            $ldpDesignerCounts = $_SESSION[$dc_key];
        } else {
            $ldpDcSql = "SELECT u.user_cname, DATE_FORMAT(ot.ateGet, '%c') AS month, COUNT(*) as count
                         FROM order_track ot JOIN user u ON u.id = ot.ate
                         WHERE ot.ateGet >= ? AND ot.ateGet < ? AND ot.ate IS NOT NULL AND ot.ateGet IS NOT NULL
                         GROUP BY u.user_cname, month";
            $ldpDcStmt = $pdo->prepare($ldpDcSql);
            $ldpDcStmt->execute([$ldpYear . '-01-01', ($ldpYear + 1) . '-01-01']);
            foreach ($ldpDcStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $ldpDesignerCounts[$c['user_cname']][(int)$c['month']] = $c['count'];
            }
            $_SESSION[$dc_key]    = $ldpDesignerCounts;
            $_SESSION[$dc_key_ts] = time();
        }
    } catch (Exception $eIgnoreDc) {}
    // 計算每筆訂單的 monthly_count
    foreach ($order_list as &$order) {
        if (!empty($order['ateGet_formatted'])) {
            list($m,) = explode('/', $order['ateGet_formatted']);
            $month = (int)$m;
            $designer = $order['user_cname'];
            $order['monthly_count'] = $ldpDesignerCounts[$designer][$month] ?? 0;
        } else {
            $order['monthly_count'] = 0;
        }
    }
    unset($order);

    // 批次查詢本頁所有主訂單的子批次（避免 N+1）
    $splitsMap = [];
    if (!empty($order_list)) {
        $parentIds  = array_column($order_list, 'Order_id');
        $phSplits   = implode(',', array_fill(0, count($parentIds), '?'));
        $stmtSplits = $pdo->prepare("SELECT parent_order_id, split_seq,
            DATE_FORMAT(Delivery_date,'%y/%c/%e') AS del_fmt, Qty, Order_ps
            FROM order_track
            WHERE parent_order_id IN ($phSplits)
            ORDER BY parent_order_id, Delivery_date ASC, split_seq ASC");
        $stmtSplits->execute($parentIds);
        foreach ($stmtSplits->fetchAll(PDO::FETCH_ASSOC) as $sp) {
            $splitsMap[$sp['parent_order_id']][] = $sp;
        }
    }

    // 批次查詢本頁子件訂單的來源組合件訂單資訊（assembly_parent_order_id → 母單料號/單號）
    $asmSrcMap = [];
    if (!empty($order_list)) {
        $asmIds = array_values(array_filter(array_unique(array_map('intval', array_column($order_list, 'assembly_parent_order_id')))));
        if (!empty($asmIds)) {
            try {
                $phAsm = implode(',', array_fill(0, count($asmIds), '?'));
                $stmtAsm = $pdo->prepare("SELECT Order_id, Order_oo, d_id FROM order_track WHERE Order_id IN ($phAsm)");
                $stmtAsm->execute($asmIds);
                foreach ($stmtAsm->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                    $asmSrcMap[(int)$ar['Order_id']] = $ar;
                }
            } catch (Exception $e) {}
        }
    }

    // 批次查詢本頁料號是否為組合件，以及該組合件訂單是否已展開過子件訂單
    $assemblyIsMap    = [];   // d_id_ID => Is_Assembly (1/0)
    $expandedParentIds = [];  // Order_id => true（此組合件母單已展開過子件訂單）
    if (!empty($order_list)) {
        $dIdIds = array_values(array_filter(array_unique(array_map('intval', array_column($order_list, 'd_id_ID')))));
        if (!empty($dIdIds)) {
            try {
                $phDid = implode(',', array_fill(0, count($dIdIds), '?'));
                $stmtIsAsm = $pdo->prepare("SELECT d_id, Is_Assembly FROM d_setting WHERE d_id IN ($phDid)");
                $stmtIsAsm->execute($dIdIds);
                foreach ($stmtIsAsm->fetchAll(PDO::FETCH_ASSOC) as $dr) {
                    $assemblyIsMap[(int)$dr['d_id']] = (int)$dr['Is_Assembly'];
                }
            } catch (Exception $e) {}
        }
        $orderIds = array_values(array_filter(array_unique(array_map('intval', array_column($order_list, 'Order_id')))));
        if (!empty($orderIds)) {
            try {
                $phOid = implode(',', array_fill(0, count($orderIds), '?'));
                $stmtExp = $pdo->prepare("SELECT DISTINCT assembly_parent_order_id FROM order_track WHERE assembly_parent_order_id IN ($phOid)");
                $stmtExp->execute($orderIds);
                foreach ($stmtExp->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $expandedParentIds[(int)$pid] = true;
                }
            } catch (Exception $e) {}
        }

        // 批次查詢本頁訂單的BOM綁定狀態（bom_order_process_map：多BOM對多訂單，依allocated_qty加總判斷全/部分綁定）
        $bomBindMap = []; // Order_id => ['sum'=>N, 'boms'=>[bom1,bom2,...]]
        if (!empty($orderIds)) {
            try {
                $phBom = implode(',', array_fill(0, count($orderIds), '?'));
                $stmtBom = $pdo->prepare("SELECT order_id, bom, allocated_qty FROM bom_order_process_map WHERE order_id IN ($phBom)");
                $stmtBom->execute($orderIds);
                foreach ($stmtBom->fetchAll(PDO::FETCH_ASSOC) as $br) {
                    $oid = (int)$br['order_id'];
                    if (!isset($bomBindMap[$oid])) { $bomBindMap[$oid] = ['sum' => 0, 'boms' => []]; }
                    $bomBindMap[$oid]['sum'] += (int)$br['allocated_qty'];
                    $bomBindMap[$oid]['boms'][] = $br['bom'];
                }
            } catch (Exception $e) {}
        }
    }

    // ── 批次查詢：設計備註 / 標籤 / 圖面狀態 / 庫存 ─────────────────────────
    // $is_perm_a 已於檔案前段權限區塊算好（含 RBAC 覆寫），這裡不再重算
    $part_dn_map     = [];   // d_id string => ['count'=>N,'has_img'=>bool]
    $cust_dn_map     = [];   // customer_id  => ['count'=>N,'has_img'=>bool]
    $labels_map      = [];   // d_id_ID int  => [['name'=>'…','val'=>'…'],…]
    $has_drawing_map = [];   // d_id string => true（bom 圖面）
    $has_quote_map   = [];   // d_id string => true（報價單附件／報價明細）
    $has_att_map     = [];   // d_id string => true（料號其他附件）
    $has_order_map   = [];   // d_id string => true（訂單附件）
    $stock_map       = [];   // d_id string => ['qty_single'=>N,'qty_combo'=>N,'locs'=>'…']
    $gear_map        = [];   // d_id_ID int  => gear_spec_str
    $drawing_no_map  = [];   // d_id_ID int  => Drawing_No string
    // 來源OP單目前的客戶（2026-08-28）：Order_id => ['quote_no','op_client_id','op_client_name','mismatch']
    // 整頁一次查完（一支 IN 查詢），不要每列各打一次；判定實作在 quote_customer_lib.php
    $op_cust_map     = [];

    if (!empty($order_list)) {
        try { $op_cust_map = qcc_orders_op_customer_map($pdo, $order_list); } catch (Throwable $eOpc) { $op_cust_map = []; }
    }

    if (!empty($order_list)) {
        $all_d_ids   = array_values(array_filter(array_unique(array_column($order_list, 'd_id'))));
        $all_did_ids = array_values(array_filter(array_unique(array_column($order_list, 'd_id_ID'))));
        $all_cust_ids= array_values(array_filter(array_unique(array_column($order_list, 'Client_name_ID'))));

        // 設計備註（A 權限）
        if ($is_perm_a) {
            if (!empty($all_d_ids)) {
                try {
                    $ph = implode(',', array_fill(0, count($all_d_ids), '?'));
                    $dq = $pdo->prepare("SELECT dn.target_id, COUNT(*) AS cnt,
                        MAX(CASE WHEN EXISTS(SELECT 1 FROM note_images ni WHERE ni.note_id=dn.note_id AND ni.note_type='part_design') THEN 1 ELSE 0 END) AS has_img
                        FROM design_notes dn WHERE dn.target_type='part' AND dn.target_id IN ($ph)
                        GROUP BY dn.target_id");
                    $dq->execute($all_d_ids);
                    foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $dn) {
                        $part_dn_map[$dn['target_id']] = ['count'=>(int)$dn['cnt'],'has_img'=>(bool)$dn['has_img']];
                    }
                } catch(Exception $e) {}
            }
            if (!empty($all_cust_ids)) {
                try {
                    $ph2 = implode(',', array_fill(0, count($all_cust_ids), '?'));
                    $dq2 = $pdo->prepare("SELECT dn.target_id, COUNT(*) AS cnt,
                        MAX(CASE WHEN EXISTS(SELECT 1 FROM note_images ni WHERE ni.note_id=dn.note_id AND ni.note_type='customer_design') THEN 1 ELSE 0 END) AS has_img
                        FROM design_notes dn WHERE dn.target_type='customer' AND dn.target_id IN ($ph2)
                        GROUP BY dn.target_id");
                    $dq2->execute($all_cust_ids);
                    foreach ($dq2->fetchAll(PDO::FETCH_ASSOC) as $dn) {
                        $cust_dn_map[$dn['target_id']] = ['count'=>(int)$dn['cnt'],'has_img'=>(bool)$dn['has_img']];
                    }
                } catch(Exception $e) {}
            }
        }

        // 料號標籤（所有人）：主標籤 + 子標籤 + 計算差異值
        if (!empty($all_did_ids)) {
            try {
                $ph3 = implode(',', array_fill(0, count($all_did_ids), '?'));
                $lq = $pdo->prepare("SELECT ilm.map_id, ilm.d_id AS part_pk, dl.label_name,
                    COALESCE(ilm.input_value,'') AS input_value,
                    dl.is_range, ilm.value_min, ilm.value_max,
                    dl.is_calc_diff, ilm.calc_value, ilm.calc_value_min, ilm.calc_value_max,
                    dl.has_draw_lathe, ilm.draw_dim, ilm.lathe_dim
                    FROM item_label_map ilm
                    JOIN dict_label dl ON dl.label_id=ilm.label_id AND dl.is_active=1
                    WHERE ilm.d_id IN ($ph3) ORDER BY dl.sort_order ASC, ilm.map_id ASC");
                $lq->execute($all_did_ids);
                $raw_labels = $lq->fetchAll(PDO::FETCH_ASSOC);
                // 建立索引以便後續附加子標籤
                $lbl_map_idx = []; // map_id => ['pk'=>int,'idx'=>int]
                foreach ($raw_labels as $lb) {
                    $pk = (int)$lb['part_pk'];
                    $labels_map[$pk][] = [
                        'map_id' => (int)$lb['map_id'],
                        'name'   => $lb['label_name'],
                        'val'    => $lb['input_value'],
                        'is_range' => (int)$lb['is_range'],
                        'vmin'   => $lb['value_min'],
                        'vmax'   => $lb['value_max'],
                        'is_calc'=> (int)$lb['is_calc_diff'],
                        'cval'   => $lb['calc_value'],
                        'cvmin'  => $lb['calc_value_min'],
                        'cvmax'  => $lb['calc_value_max'],
                        'has_dl' => (int)$lb['has_draw_lathe'],
                        'ddim'   => $lb['draw_dim'],
                        'ldim'   => $lb['lathe_dim'],
                        'subs'   => [],
                    ];
                    $lbl_map_idx[(int)$lb['map_id']] = ['pk'=>$pk, 'idx'=>count($labels_map[$pk])-1];
                }
                // 子標籤
                if (!empty($lbl_map_idx)) {
                    $all_map_ids = array_keys($lbl_map_idx);
                    $ph_sub = implode(',', array_fill(0, count($all_map_ids), '?'));
                    $sq = $pdo->prepare("SELECT s.parent_map_id, ds.sub_name,
                        COALESCE(s.input_value,'') AS input_value,
                        ds.is_range, s.value_min, s.value_max,
                        ds.has_draw_lathe, s.draw_dim, s.lathe_dim
                        FROM item_sub_label_map s
                        JOIN dict_label_sub ds ON ds.sub_id=s.sub_id AND ds.is_active=1
                        WHERE s.parent_map_id IN ($ph_sub)
                        ORDER BY ds.sort_order ASC, s.sub_map_id ASC");
                    $sq->execute($all_map_ids);
                    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $sl) {
                        $mid = (int)$sl['parent_map_id'];
                        if (isset($lbl_map_idx[$mid])) {
                            $pk  = $lbl_map_idx[$mid]['pk'];
                            $idx = $lbl_map_idx[$mid]['idx'];
                            $labels_map[$pk][$idx]['subs'][] = [
                                'name'     => $sl['sub_name'],
                                'val'      => $sl['input_value'],
                                'is_range' => (int)$sl['is_range'],
                                'vmin'     => $sl['value_min'],
                                'vmax'     => $sl['value_max'],
                                'has_dl'   => (int)$sl['has_draw_lathe'],
                                'ddim'     => $sl['draw_dim'],
                                'ldim'     => $sl['lathe_dim'],
                            ];
                        }
                    }
                }
            } catch(Exception $e) {}
        }

        // 是否有圖面：與 bom_viewer.php 邏輯一致
        // NAS 可存取 → 掃描 Z:/BOM/ 確認有實際檔案才標記有圖面
        // NAS 不可存取 → fallback：bom 表有記錄即視為有圖面（避免暫時斷線時連結全消失）
        if (!empty($all_d_ids)) {
            try {
                $ph4 = implode(',', array_fill(0, count($all_d_ids), '?'));
                $dr = $pdo->prepare("SELECT d_id, bom FROM bom WHERE d_id IN ($ph4)");
                $dr->execute($all_d_ids);
                // d_id => [bom_number, ...]
                $bom_by_did = [];
                foreach ($dr->fetchAll(PDO::FETCH_ASSOC) as $brow) {
                    $bom_by_did[$brow['d_id']][] = $brow['bom'];
                }
                if (!empty($bom_by_did)) {
                    require_once __DIR__ . '/../../src/common/bom_dir_lib.php';   // 資料夾位置走設定鍵 bom_scan_dir，不再寫死 Z: 磁碟機代號
                    $nas_scan_dir = eg_bom_scan_dir_auto();
                    $draw_valid_ext = ['jpg','jpeg','png','pdf'];
                    if (is_dir($nas_scan_dir)) {
                        // NAS 可存取：掃目錄（結果快取 5 分鐘，避免每次篩選都掃 NAS）
                        $nas_cache_key = 'nas_bom_files_' . md5($nas_scan_dir);
                        if (!array_key_exists($nas_cache_key, $_SESSION) || (time() - ($_SESSION[$nas_cache_key . '_ts'] ?? 0)) > 300) {
                            $_SESSION[$nas_cache_key]        = scandir($nas_scan_dir);
                            $_SESSION[$nas_cache_key . '_ts'] = time();
                        }
                        $nas_files = $_SESSION[$nas_cache_key];
                        $all_bom_nums = array_unique(array_merge(...array_values($bom_by_did)));
                        $bom_has_file = [];
                        foreach ($all_bom_nums as $bnum) {
                            foreach ($nas_files as $fn) {
                                if ($fn === '.' || $fn === '..') continue;
                                if (strpos($fn, $bnum) === 0) {
                                    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                                    if (in_array($ext, $draw_valid_ext)) {
                                        $bom_has_file[$bnum] = true;
                                        break; // 找到一個就夠了
                                    }
                                }
                            }
                        }
                        foreach ($bom_by_did as $did => $bnums) {
                            foreach ($bnums as $bnum) {
                                if (!empty($bom_has_file[$bnum])) {
                                    $has_drawing_map[$did] = true;
                                    break;
                                }
                            }
                        }
                    } else {
                        // NAS 不可存取：fallback — bom 表有記錄視為有圖面
                        foreach (array_keys($bom_by_did) as $did) {
                            $has_drawing_map[$did] = true;
                        }
                    }
                }
            } catch(Exception $e) {}
        }

        // 是否有「報價資料 / 其他附件」：bom_viewer.php 是三分頁（圖面／報價資料／其他附件），
        // 只要任一分頁有資料，料號就該是可點的超連結（原本只看圖面，導致只有報價單或料號附件的料號點不開）
        // 權限判定與 bom_viewer.php 完全一致：報價需 quotation_view；其他附件過渡期（未指派 master_data 角色者開放）
        if (!empty($all_d_ids)) {
            $can_quote_view = false;
            $can_other_view = true;
            try {
                require_once __DIR__ . '/../../src/common/rbac.php';
                $_uidF    = intval($_SESSION['id'] ?? 0);
                $_featsF  = rbac_user_features($pdo, $_uidF);
                $_isAdminF= rbac_has($_featsF, 'all');
                $can_quote_view = $_isAdminF || rbac_has($_featsF, 'quotation_view');
                $_rq = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.module='master_data' LIMIT 1");
                $_rq->execute([$_uidF]);
                $_hasMdRole = (bool)$_rq->fetchColumn();
                $can_other_view = $_isAdminF || !$_hasMdRole || rbac_has($_featsF, 'md_attach_view');
            } catch (Exception $e) { $can_quote_view = false; $can_other_view = true; }

            // 料號文字 → d_setting.d_id（同一料號可能對到多筆，不同客戶）
            $did_of_part = [];   // d_setting.d_id(int) => 料號文字
            try {
                $ph5 = implode(',', array_fill(0, count($all_d_ids), '?'));
                $ds5 = $pdo->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE D_Setting_Id IN ($ph5)");
                $ds5->execute($all_d_ids);
                foreach ($ds5->fetchAll(PDO::FETCH_ASSOC) as $r5) {
                    $did_of_part[(int)$r5['d_id']] = $r5['D_Setting_Id'];
                }
            } catch (Exception $e) {}

            // 其他附件（part_attachments）：批圖工作檔依分享範圍過濾，與 bom_viewer 相同
            if ($can_other_view && !empty($did_of_part)) {
                try {
                    $pks6 = array_keys($did_of_part);
                    $ph6  = implode(',', array_fill(0, count($pks6), '?'));
                    $pa6  = $pdo->prepare("SELECT id, d_id, filename FROM part_attachments WHERE d_id IN ($ph6) AND deleted_at IS NULL");
                    $pa6->execute($pks6);
                    $paRows = $pa6->fetchAll(PDO::FETCH_ASSOC);
                    require_once __DIR__ . '/../../src/common/imgedit_visibility.php';
                    $paRows = imgedit_filter_attachment_rows($pdo, $paRows, intval($_SESSION['id'] ?? 0));
                    // 工作檔不是圖面：只有它存在時不該把「有附件」旗標點亮（看不到的檔案不算有附件）
                    $paRows = imgedit_strip_workfiles($paRows, $pdo);
                    foreach ($paRows as $r6) {
                        $pn6 = $did_of_part[(int)$r6['d_id']] ?? null;
                        if ($pn6 !== null) $has_att_map[$pn6] = true;
                    }
                } catch (Exception $e) {}
            }

            // 報價資料（quotation_attachments，只算 status='active'）
            if ($can_quote_view) {
                try {
                    // (1) linked_parts 明確指定此料號
                    $qa7 = $pdo->prepare("SELECT DISTINCT linked_parts FROM quotation_attachments
                                          WHERE status='active' AND linked_parts IS NOT NULL AND linked_parts <> ''
                                            AND JSON_VALID(linked_parts)
                                            AND JSON_OVERLAPS(CAST(linked_parts AS JSON), CAST(? AS JSON))");
                    $qa7->execute([json_encode(array_values($all_d_ids), JSON_UNESCAPED_UNICODE)]);
                    $partSet7 = array_flip($all_d_ids);
                    foreach ($qa7->fetchAll(PDO::FETCH_COLUMN) as $lp7) {
                        $arr7 = json_decode((string)$lp7, true);
                        if (!is_array($arr7)) continue;
                        foreach ($arr7 as $pn7) {
                            if (isset($partSet7[$pn7])) $has_quote_map[$pn7] = true;
                        }
                    }
                } catch (Exception $e) {}
                // (2) linked_parts NULL（該報價單的共用附件）→ 報價單含此料號即算有資料
                if (!empty($did_of_part)) {
                    try {
                        $pks8 = array_keys($did_of_part);
                        $ph8  = implode(',', array_fill(0, count($pks8), '?'));
                        $qs8  = $pdo->prepare("SELECT DISTINCT qi.d_setting_d_id
                                               FROM quotation_attachments a
                                               JOIN quotation_list ql ON ql.quote_no = a.quote_no
                                               JOIN quotation_item qi ON qi.quote_id = ql.quote_id
                                               WHERE a.status='active' AND a.linked_parts IS NULL
                                                 AND qi.d_setting_d_id IN ($ph8)");
                        $qs8->execute($pks8);
                        foreach ($qs8->fetchAll(PDO::FETCH_COLUMN) as $pk8) {
                            $pn8 = $did_of_part[(int)$pk8] ?? null;
                            if ($pn8 !== null) $has_quote_map[$pn8] = true;
                        }
                    } catch (Exception $e) {}
                }
                // (3) 沒有任何報價附件、但這個料號本來就有報價明細 → bom_viewer 的「訂單／報價」分頁一樣有內容
                //     （該分頁的報價區塊是靠 quotation_item 撈出來的，不是只看附件），所以料號一樣要能點開
                if (!empty($did_of_part)) {
                    try {
                        $pks9 = array_keys($did_of_part);
                        $ph9  = implode(',', array_fill(0, count($pks9), '?'));
                        $qi9  = $pdo->prepare("SELECT DISTINCT qi.d_setting_d_id FROM quotation_item qi WHERE qi.d_setting_d_id IN ($ph9)");
                        $qi9->execute($pks9);
                        foreach ($qi9->fetchAll(PDO::FETCH_COLUMN) as $pk9) {
                            $pn9 = $did_of_part[(int)$pk9] ?? null;
                            if ($pn9 !== null) $has_quote_map[$pn9] = true;
                        }
                    } catch (Exception $e) {}
                }
            }

            // 訂單附件（order_attachments）：bom_viewer 的「訂單／報價」分頁也會列訂單附件，
            // 所以就算這個料號沒有圖面／報價／料號附件，只要訂單上傳過附件，料號一樣要是可點的超連結。
            // 權限沿用其他附件同一組（與 bom_viewer 的 _canOrder = canOtherView 一致）。
            if ($can_other_view && !empty($did_of_part)) {
                try {
                    $pksA = array_keys($did_of_part);
                    $phA  = implode(',', array_fill(0, count($pksA), '?'));
                    $oaA  = $pdo->prepare("SELECT DISTINCT ot.d_id_ID
                                           FROM order_attachments a
                                           JOIN order_track ot ON ot.Order_id = a.order_id
                                           WHERE a.status='active' AND ot.d_id_ID IN ($phA)");
                    $oaA->execute($pksA);
                    foreach ($oaA->fetchAll(PDO::FETCH_COLUMN) as $pkA) {
                        $pnA = $did_of_part[(int)$pkA] ?? null;
                        if ($pnA !== null) $has_order_map[$pnA] = true;
                    }
                } catch (Exception $e) {}   // order_attachments 尚未建表時略過
            }
        }

        // 庫存摘要（所有人可見）：依料號合計數量，組合件與非組合件分開
        if (!empty($all_d_ids)) {
            try {
                // 偵測 location_id 欄位（結果快取在 Session，schema 不會變）
                if (!array_key_exists('stock_items_has_loc', $_SESSION)) {
                    $stk_cols_b = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);
                    $_SESSION['stock_items_has_loc'] = in_array('location_id', $stk_cols_b);
                }
                $stk_has_loc_b  = $_SESSION['stock_items_has_loc'];
                $stk_loc_join_b = $stk_has_loc_b ? "LEFT JOIN stock_locations _slb ON _slb.location_id = si.location_id" : "";
                $stk_loc_sel_b  = $stk_has_loc_b ? "COALESCE(_slb.location_code, si.storage_location)" : "si.storage_location";
                $ph5 = implode(',', array_fill(0, count($all_d_ids), '?'));
                $stk = $pdo->prepare("SELECT si.d_id,
                    SUM(CASE WHEN si.group_id IS NULL THEN si.qty ELSE 0 END)     AS qty_single,
                    SUM(CASE WHEN si.group_id IS NOT NULL THEN si.qty ELSE 0 END) AS qty_combo,
                    GROUP_CONCAT(
                        CONCAT(
                            COALESCE($stk_loc_sel_b, '?'), '·',
                            COALESCE(sic.category_name, ''), '×',
                            CAST(CAST(si.qty AS DECIMAL(15,2)) AS CHAR),
                            CASE WHEN si.group_id IS NOT NULL THEN '(組)' ELSE '' END
                        )
                        ORDER BY si.qty DESC SEPARATOR '  '
                    ) AS loc_summary
                    FROM stock_items si
                    LEFT JOIN stock_item_categories sic ON sic.category_id = si.item_type
                    $stk_loc_join_b
                    WHERE si.d_id IN ($ph5) AND si.is_active = 1
                    GROUP BY si.d_id");
                $stk->execute($all_d_ids);
                foreach ($stk->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                    $stock_map[$sr['d_id']] = [
                        'qty_single' => (float)$sr['qty_single'],
                        'qty_combo'  => (float)$sr['qty_combo'],
                        'locs'       => $sr['loc_summary'] ?? '',
                    ];
                }
            } catch(Exception $e) {}
        }

        // 齒輪規格（批次查詢 d_setting_gear，供 Type=G 料號顯示）
        if (!empty($all_did_ids)) {
            try {
                // 建立巢狀 REPLACE 表達式套用 display_template（與 master_data_management.php 相同邏輯）
                $tmpl_replacements = [
                    '{Module}'               => "COALESCE(NULLIF(g.module_display,''), IF(g.Module IS NOT NULL AND g.Module<>'', IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M',g.Module)), ''))",
                    '{Teeth}'                => "COALESCE(CAST(NULLIF(g.Teeth,0) AS CHAR),'')",
                    '{Face_Width}'           => "IF(g.Face_Width IS NOT NULL AND g.Face_Width>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR))), '')",
                    '{Pressure_Angle}'       => "COALESCE(NULLIF(TRIM(TRAILING '°' FROM TRIM(COALESCE(g.Pressure_Angle,''))),''),'20')",   // 空白＝業界預設20°，樣板的「PA」後才不會空著
                    '{Helix_Direction}'      => "COALESCE(NULLIF(g.Helix_Direction,''),'')",
                    '{Helix_Angle_Str}'      => "COALESCE(NULLIF(g.Helix_Angle_Str,''), IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR))), ''))",
                    '{spec_starts}'          => "COALESCE(CAST(NULLIF(g.spec_starts,0) AS CHAR),'')",
                    '{X_PART}'               => "IF(g.Profile_Shift_X IS NOT NULL AND g.Profile_Shift_X<>0, CONCAT('X',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Profile_Shift_X AS CHAR)))), '')",
                    '{GRADE}'                => "IF(g.gear_quality_std IS NOT NULL AND g.gear_quality_std<>'', CONCAT(g.gear_quality_std,COALESCE(CAST(g.gear_quality_grade AS CHAR),'')), '')",
                    '{spec_chain_size}'      => "COALESCE(g.spec_chain_size,'')",
                    '{spec_pitch}'           => "IF(g.spec_pitch IS NOT NULL AND g.spec_pitch>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_pitch AS CHAR))), '')",
                    '{spec_roller_dia}'      => "IF(g.spec_roller_dia IS NOT NULL AND g.spec_roller_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_roller_dia AS CHAR))), '')",
                    '{spec_spline_type}'     => "COALESCE(g.spec_spline_type,'')",
                    '{spec_spline_major_dia}'=> "IF(g.spec_spline_major_dia IS NOT NULL AND g.spec_spline_major_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_major_dia AS CHAR))), '')",
                    '{spec_spline_minor_dia}'=> "IF(g.spec_spline_minor_dia IS NOT NULL AND g.spec_spline_minor_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_minor_dia AS CHAR))), '')",
                    '{spec_spline_width}'    => "IF(g.spec_spline_width IS NOT NULL AND g.spec_spline_width>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_width AS CHAR))), '')",
                    '{spec_pulley_profile}'  => "COALESCE(g.spec_pulley_profile,'')",
                    '{Remark_Gear}'          => "COALESCE(NULLIF(g.Remark_Gear,''),'')",
                ];
                $tmpl_expr = 'dt.display_template';
                foreach ($tmpl_replacements as $token => $expr) {
                    $tmpl_expr = "REPLACE($tmpl_expr, '$token', $expr)";
                }

                $ph_g = implode(',', array_fill(0, count($all_did_ids), '?'));
                $gq = $pdo->prepare("SELECT g.d_setting_id,
                    GROUP_CONCAT(
                        CASE
                          WHEN dt.display_template IS NOT NULL AND dt.display_template<>'' THEN
                            $tmpl_expr
                          WHEN dt.spec_category='spline' AND g.spec_spline_type='矩形' THEN
                            CONCAT(IF(g.Teeth>0, CONCAT(g.Teeth,'鍵 '),''), COALESCE(CAST(g.spec_spline_minor_dia AS CHAR),'?'), ' × ', COALESCE(CAST(g.spec_spline_major_dia AS CHAR),'?'), ' × ', COALESCE(CAST(g.spec_spline_width AS CHAR),'?'))
                          ELSE
                            CONCAT(
                                IF(g.Module IS NOT NULL AND g.Module != '',
                                   IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M', g.Module)), ''),
                                IF(dt.spec_category='worm_gear' AND g.spec_starts IS NOT NULL AND g.spec_starts > 0,
                                   CONCAT('×', g.spec_starts, '條'),
                                   IF(g.Teeth IS NOT NULL AND g.Teeth > 0, CONCAT('×', g.Teeth, 'T'), '')),
                                IF(g.Face_Width IS NOT NULL AND g.Face_Width > 0,
                                   CONCAT(' W', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR)))), ''),
                                IF(g.Pressure_Angle IS NOT NULL AND g.Pressure_Angle != '',
                                   CONCAT(' PA', g.Pressure_Angle, '°'), ''),
                                IF(g.Helix_Direction IS NOT NULL AND g.Helix_Direction != '',
                                   CONCAT(' ', g.Helix_Direction), ''),
                                IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle > 0,
                                   CONCAT(' ', COALESCE(NULLIF(g.Helix_Angle_Str,''),
                                   TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR)))), '°'), ''),
                                IF(g.Profile_Shift_X IS NOT NULL AND g.Profile_Shift_X != 0,
                                   CONCAT(' X', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Profile_Shift_X AS CHAR)))), '')
                            )
                        END
                        ORDER BY g.gear_id SEPARATOR ' / '
                    ) AS gear_str
                    FROM d_setting_gear g
                    LEFT JOIN dict_gear_type dt ON dt.gear_type_id = g.Gear_Type
                    WHERE g.d_setting_id IN ($ph_g)
                    GROUP BY g.d_setting_id");
                $gq->execute($all_did_ids);
                foreach ($gq->fetchAll(PDO::FETCH_ASSOC) as $gr) {
                    $gear_map[(int)$gr['d_setting_id']] = $gr['gear_str'];
                }
            } catch (Exception $e) {}
        }

        // 料號別名（Drawing_No）批次查詢
        if (!empty($all_did_ids)) {
            try {
                $ph_dn = implode(',', array_fill(0, count($all_did_ids), '?'));
                $dnq = $pdo->prepare("SELECT d_id, Drawing_No FROM d_setting WHERE d_id IN ($ph_dn) AND Drawing_No IS NOT NULL AND Drawing_No != '' AND Drawing_No != D_Setting_Id");
                $dnq->execute($all_did_ids);
                foreach ($dnq->fetchAll(PDO::FETCH_ASSOC) as $dnr) {
                    $drawing_no_map[(int)$dnr['d_id']] = $dnr['Drawing_No'];
                }
            } catch (Exception $e) {}
        }
    }

    // 3. 第二層緩衝區：專心抓取 HTML
    ob_start();
    if(count($order_list) > 0) {
        foreach ($order_list as $order) {
            // BOM綁定狀態圖示：依 bom_order_process_map 加總 allocated_qty 與訂單Qty比較，全綁定/部分綁定才顯示
            // 存成 $_bomIconHtml 供狀態欄使用，同時存進 <tr data-bom-icon> 供JS更新狀態欄後補回（見 updatePmGet 等函式）
            $_bomIconHtml = '';
            $_bomBindRow = $bomBindMap[intval($order['Order_id'])] ?? null;
            $_bomSumRow  = $_bomBindRow['sum'] ?? 0;
            if ($_bomSumRow > 0) {
                $_bomQtyRow   = intval($order['Qty'] ?? 0);
                $_bomFullRow  = ($_bomSumRow >= $_bomQtyRow);
                $_bomColorRow = $_bomFullRow ? '#1ABB9C' : '#F0A24B';
                $_bomIconRow  = $_bomFullRow ? 'fa-check-circle' : 'fa-exclamation-triangle';
                $_bomTitleRow = ($_bomFullRow ? '生管已開立BOM（全部綁定 ' : '生管已開立BOM（部分綁定 ') . $_bomSumRow . '/' . $_bomQtyRow . '），點擊查看BOM';
                $_bomIconHtml = ' <a href="../pm/OreadyReply_ForPm_BaseOfTime.php?order_id_filter=' . intval($order['Order_id']) . '" target="_blank" title="' . safe_html($_bomTitleRow) . '" style="text-decoration:none;">'
                               . '<i class="fa ' . $_bomIconRow . '" style="color:' . $_bomColorRow . ';font-size:12px;margin-left:4px;"></i></a>';
            }
?>
            <tr class="<?= !empty($order['is_urgent']) ? 'order-urgent' : '' ?>" data-orderid="<?= safe_html($order['Order_id']) ?>" data-bom-icon="<?= htmlspecialchars($_bomIconHtml, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($show_op_col): ?>
                <td style="text-align: center;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 3px;">
                        <div style="display: flex; justify-content: center; gap: 3px;">
                            <?php if ($can_update): ?>
                            <button type="button" class="btn btn-info btn-xs" title="修改" onclick="editOrder('<?= $order['Order_id'] ?>')" style="margin:0;"><i class="fa fa-pencil"></i></button>
                            <?php endif; ?>
                            <?php if ($can_create): ?>
                            <button type="button" class="btn btn-default btn-xs" title="複製並新增" onclick="copyOrder('<?= $order['Order_id'] ?>')" style="margin:0;"><i class="fa fa-copy"></i></button>
                            <?php endif; ?>
                        </div>
                        <?php if ($can_order_change): ?>
                        <button type="button" class="btn btn-warning btn-xs" title="訂單變更" onclick="openOrderChange('<?= $order['Order_id'] ?>')" style="margin:0;"><i class="fa fa-exchange"></i> 變更</button>
                        <?php endif; ?>
                    </div>
                </td>
                <?php endif; ?>
                
                <td class="col-date">
                    <div style="line-height:1.7;font-size:11px;">
                        <?php if (!empty($order['is_urgent'])): ?><div><span class="urgent-badge" title="急件：篩選「批圖中」時會排在最上方"><i class="fa fa-bolt"></i> 急件</span></div><?php endif; ?>
                        <div style="color:#555;" title="接單日"><?= $order['Order_date_formatted'] ?></div>
                        <div style="color:#e67e22;font-weight:600;" title="交期"><?= $order['Delivery_date_formatted'] ?></div>
                        <?php if (!empty($order['Created_At_formatted'])): ?><div style="font-size:9px;color:#bbb;">(<?= $order['creator_name'] ? mb_substr($order['creator_name'], -2, 2, 'UTF-8') : '' ?> <?= $order['Created_At_formatted'] ?>)</div><?php endif; ?>
                    </div>
                </td>
                <td class="col-client" data-has-part="<?= !empty($order['has_part_id']) ? 1 : 0 ?>" title="<?= safe_html(!empty($order['cl_customer_name']) ? $order['cl_customer_name'] : $order['Client_name']) ?>">
                    <?php // 客戶名稱包一層 span：同步來源OP客戶時只換這裡的文字，不必整頁重新載入 ?>
                    <span class="oc-client-name"><?= safe_html(!empty($order['cl_customer_name']) ? $order['cl_customer_name'] : $order['Client_name']) ?></span>
                    <span class="oc-bind-icon">
                    <?php if (!empty($order['has_client_id']) && !empty($order['has_part_id'])): ?>
                        <i class="fa fa-link" title="客戶與料號均已綁定ID" style="color:#1ABB9C; font-size:10px; margin-left:3px;"></i>
                    <?php elseif (!empty($order['has_client_id']) || !empty($order['has_part_id'])): ?>
                        <i class="fa fa-chain-broken" title="部分綁定（客戶:<?= $order['has_client_id'] ? '✓' : '✗' ?> / 料號:<?= $order['has_part_id'] ? '✓' : '✗' ?>）" style="color:#F39C12; font-size:10px; margin-left:3px;"></i>
                    <?php else: ?>
                        <i class="fa fa-unlink" title="尚未綁定ID，點此快速綁定" style="color:#ccc; font-size:10px; margin-left:3px; cursor:pointer;" onclick="openQuickBind('<?= $order['Order_id'] ?>','<?= safe_html($order['Client_name']) ?>','<?= safe_html($order['d_id']) ?>')"></i>
                    <?php endif; ?>
                    </span>
                    <?php
                    // ── 與來源OP單的客戶不一致（2026-08-28）────────────────────────────
                    // 常見情境：用 A 客戶報價，接單後客戶要求改成 B 客戶名稱，報價單那邊改了、
                    // 這張訂單卻還停在轉單當下的舊客戶。純提示，不自動改資料；有編輯權的人
                    // 可以點一下同步過來（只動客戶欄位）。沒有來源OP或兩邊一致時什麼都不顯示。
                    $__opc = $op_cust_map[(int)$order['Order_id']] ?? null;
                    if ($__opc && !empty($__opc['mismatch'])):
                    ?>
                        <div class="op-cust-diff" style="margin-top:2px;font-size:10px;line-height:1.4;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:1px 4px;<?= $can_update ? 'cursor:pointer;' : '' ?>"
                             title="來源報價單 <?= safe_html($__opc['quote_no']) ?> 目前的客戶是「<?= safe_html($__opc['op_client_name']) ?>」，與本訂單不一致<?= $can_update ? '，點此同步（只改客戶，不動料號/金額/日期）' : '' ?>"
                             <?= $can_update ? 'onclick="syncQuoteCustomer(' . (int)$order['Order_id'] . ')"' : '' ?>>
                            <i class="fa fa-exclamation-triangle"></i> OP客戶：<?= safe_html($__opc['op_client_name']) ?><?= $can_update ? ' <i class="fa fa-refresh"></i>' : '' ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="col-part">
                    <?php
                    $_part_dn    = $is_perm_a ? ($part_dn_map[$order['d_id']] ?? null) : null;
                    $__cid       = $order['Client_name_ID'] ?? '';
                    $_cust_dn    = ($is_perm_a && $__cid) ? ($cust_dn_map[$__cid] ?? null) : null;
                    $_order_lbls = $labels_map[(int)($order['d_id_ID'] ?? 0)] ?? [];
                    $_has_draw   = $has_drawing_map[$order['d_id']] ?? false;
                    $_has_quote  = $has_quote_map[$order['d_id']]   ?? false;
                    $_has_att    = $has_att_map[$order['d_id']]     ?? false;
                    $_has_ordatt = $has_order_map[$order['d_id']]  ?? false;
                    // 只要圖面／報價／訂單附件／其他附件任一有資料就可點開（bom_viewer 會自動切到第一個有資料的分頁）
                    $_has_files  = $_has_draw || $_has_quote || $_has_att || $_has_ordatt;
                    $_file_kinds = [];
                    if ($_has_draw)   $_file_kinds[] = '圖面';
                    if ($_has_quote)  $_file_kinds[] = '報價資料';
                    if ($_has_ordatt) $_file_kinds[] = '訂單附件';
                    if ($_has_att)    $_file_kinds[] = '其他附件';
                    $_file_tip   = $_has_files ? ('點擊查閱：' . implode('／', $_file_kinds)) : '';
                    $_dn_total   = ($_part_dn ? $_part_dn['count'] : 0) + ($_cust_dn ? $_cust_dn['count'] : 0);
                    $_dn_img     = ($_part_dn && $_part_dn['has_img']) || ($_cust_dn && $_cust_dn['has_img']);
                    // 庫存摘要
                    $_stk   = $stock_map[$order['d_id']] ?? null;
                    $_stk_s = 0.0; $_stk_c = 0.0; $_stk_t = 0.0;
                    $_stk_locs = ''; $_stk_title = '';
                    if ($_stk !== null) {
                        $_stk_s    = (float)$_stk['qty_single'];
                        $_stk_c    = (float)$_stk['qty_combo'];
                        $_stk_t    = $_stk_s + $_stk_c;
                        $_stk_locs = $_stk['locs'];
                        $_stk_title = $_stk_locs ?: '無庫存';
                        if ($_stk_c > 0) $_stk_title .= '  （組合件另計）';
                    }
                    ?>
                    <div style="display:flex;align-items:center;gap:3px;flex-wrap:nowrap;min-width:0;">
                        <i class="fa fa-copy copy-icon" title="複製" onclick="copyToClipboard('<?= safe_html($order['d_id']) ?>', this)" style="flex-shrink:0;"></i>
                        <?php if ($_has_files): ?>
                        <span class="part-link" style="cursor:pointer;" title="<?= safe_html($_file_tip) ?>" onclick="openPartDrawing('<?= safe_html($order['d_id']) ?>', <?= (int)($order['d_id_ID'] ?? 0) ?>)"><?= safe_html($order['d_id']) ?></span>
                        <?php else: ?>
                        <span style="color:#555;cursor:default;" onclick="showNoDrawingToast()" title="無圖面／報價／訂單附件／其他附件資料"><?= safe_html($order['d_id']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($order['assembly_parent_order_id'])):
                            $_asm_src = $asmSrcMap[(int)$order['assembly_parent_order_id']] ?? null;
                            $_asm_tip = $_asm_src
                                ? '由組合件訂單展開：來源組合件 ' . $_asm_src['d_id'] . '（單號 ' . $_asm_src['Order_oo'] . '）'
                                : '由組合件訂單展開（來源訂單已刪除）';
                        ?>
                        <span style="background:#8e6bbf;color:#fff;border-radius:3px;padding:0 4px;font-size:9px;line-height:15px;flex-shrink:0;white-space:nowrap;cursor:default;"
                              title="<?= safe_html($_asm_tip) ?>"><i class="fa fa-sitemap" style="font-size:8px;"></i> 組合件展開</span>
                        <?php endif; ?>
                        <?php if (($assemblyIsMap[(int)($order['d_id_ID'] ?? 0)] ?? 0) === 1): ?>
                        <span style="background:#3498db;color:#fff;border-radius:3px;padding:0 4px;font-size:9px;line-height:15px;flex-shrink:0;white-space:nowrap;cursor:default;"
                              title="此料號為組合件"><i class="fa fa-cubes" style="font-size:8px;"></i> 組合件</span>
                        <?php if (isset($expandedParentIds[(int)$order['Order_id']])): ?>
                        <span style="background:#27ae60;color:#fff;border-radius:3px;padding:0 4px;font-size:9px;line-height:15px;flex-shrink:0;white-space:nowrap;cursor:default;"
                              title="此組合件訂單已展開子件訂單"><i class="fa fa-check-circle" style="font-size:8px;"></i> 子件已展開</span>
                        <?php else: ?>
                        <span style="background:#e67e22;color:#fff;border-radius:3px;padding:0 4px;font-size:9px;line-height:15px;flex-shrink:0;white-space:nowrap;cursor:default;"
                              title="此組合件訂單尚未展開子件訂單"><i class="fa fa-exclamation-circle" style="font-size:8px;"></i> 子件未展開</span>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($is_perm_a): ?>
                        <?php if ($_dn_total > 0): ?>
                        <button type="button" class="btn btn-xs ot-dn-btn"
                            style="padding:0 4px;font-size:10px;line-height:16px;background:#fff5f5;border:1px solid #e74c3c;color:#c0392b;flex-shrink:0;"
                            onclick="showOtDesignNotes(<?= safe_html(json_encode($order['d_id'])) ?>,<?= safe_html(json_encode($__cid)) ?>,<?= safe_html(json_encode($order['cl_customer_name'] ?? $order['Client_name'] ?? '')) ?>)"
                            title="設計備註 <?= $_dn_total ?>筆<?= $_dn_img?' (含圖)':'' ?>">
                            <i class="fa fa-file-text-o"></i><?= $_dn_total ?><?= $_dn_img ? '<i class="fa fa-image" style="font-size:8px;margin-left:1px;"></i>' : '' ?>
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($can_master_edit): ?>
                        <?php if (!empty($order['d_id_ID'])): ?>
                        <a href="../../views/pages/master_data_management.php?open_part=<?= intval($order['d_id_ID']) ?>&part_search=<?= urlencode($order['d_id'] ?? '') ?>" target="_blank"
                            class="btn btn-xs" style="padding:0 4px;font-size:10px;line-height:16px;background:#f0fff8;border:1px solid #1ABB9C;color:#1ABB9C;flex-shrink:0;" title="前往料號主檔編輯">
                            <i class="fa fa-cog"></i></a>
                        <?php else: ?>
                        <button type="button" class="btn btn-xs"
                            style="padding:0 4px;font-size:10px;line-height:16px;background:#fffaf0;border:1px solid #e67e22;color:#e67e22;flex-shrink:0;"
                            onclick="openQuickBind('<?= $order['Order_id'] ?>','<?= safe_html($order['Client_name'] ?? '') ?>','<?= safe_html($order['d_id']) ?>')"
                            title="料號未綁定主檔，點此先綁定"><i class="fa fa-cog"></i><i class="fa fa-exclamation" style="font-size:8px;margin-left:1px;"></i></button>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($_stk_t > 0): ?>
                        <button type="button" class="btn btn-xs"
                            style="padding:0 5px;font-size:10px;line-height:16px;background:#f0fff4;border:1px solid #27ae60;color:#27ae60;flex-shrink:0;white-space:nowrap;"
                            onclick="openStockModal(<?= safe_html(json_encode($order['d_id'])) ?>)"
                            title="<?= safe_html($_stk_title) ?>">
                            <i class="fa fa-archive" style="font-size:9px;margin-right:2px;"></i>庫存：<?= number_format($_stk_t, 0) ?></button>
                        <?php endif; ?>
                    </div>
                    <?php $_drawing_no_disp = $drawing_no_map[(int)($order['d_id_ID'] ?? 0)] ?? ''; ?>
                    <?php if (!empty($_drawing_no_disp)): ?>
                    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;margin-top:-3px;line-height:1.2;">
                        <span style="font-size:10px;color:#1a7abf;">代：<?= safe_html($_drawing_no_disp) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($_order_lbls)):
                    // ── 輔助函式：將一個標籤結構轉成顯示文字 ──────────────────
                    $fn_lbl_val = function($l) {
                        if (!empty($l['is_calc'])) {
                            // 計算差異標籤
                            if ($l['cvmin'] !== null && $l['cvmin'] !== '' &&
                                $l['cvmax'] !== null && $l['cvmax'] !== '') {
                                $mn = rtrim(rtrim(number_format((float)$l['cvmin'], 4, '.', ''), '0'), '.');
                                $mx = rtrim(rtrim(number_format((float)$l['cvmax'], 4, '.', ''), '0'), '.');
                                return $mn . '~' . $mx;
                            }
                            if ($l['cval'] !== null && $l['cval'] !== '') {
                                return rtrim(rtrim(number_format((float)$l['cval'], 4, '.', ''), '0'), '.');
                            }
                            return '';
                        }
                        if (!empty($l['is_range'])) {
                            $mn = $l['vmin'] ?? ''; $mx = $l['vmax'] ?? '';
                            if ($mn !== '' || $mx !== '') {
                                return rtrim(rtrim(number_format((float)$mn, 4, '.', ''), '0'), '.') . '~'
                                     . rtrim(rtrim(number_format((float)$mx, 4, '.', ''), '0'), '.');
                            }
                        }
                        if (!empty($l['has_dl']) && ($l['ddim'] !== null || $l['ldim'] !== null)) {
                            $dd = ($l['ddim'] !== null) ? rtrim(rtrim(number_format((float)$l['ddim'], 4, '.', ''), '0'), '.') : '?';
                            $ld = ($l['ldim'] !== null) ? rtrim(rtrim(number_format((float)$l['ldim'], 4, '.', ''), '0'), '.') : '?';
                            return '圖'.$dd.'/車'.$ld;
                        }
                        return $l['val'] ?? '';
                    };
                    // 子標籤同邏輯（欄位相同）
                    $fn_sub_val = function($s) use ($fn_lbl_val) {
                        // dict_label_sub 欄位與主標籤對應：is_range,vmin,vmax,has_dl,ddim,ldim
                        // is_calc 子標籤無 calc 欄位，直接走一般/range 分支
                        $l2 = ['is_calc'=>0,'is_range'=>$s['is_range'],'vmin'=>$s['vmin'],'vmax'=>$s['vmax'],
                               'has_dl'=>$s['has_dl'],'ddim'=>$s['ddim'],'ldim'=>$s['ldim'],
                               'val'=>$s['val'],'cval'=>null,'cvmin'=>null,'cvmax'=>null];
                        return $fn_lbl_val($l2);
                    };
                    // 建立 tooltip 全文
                    $_lbl_title_parts = [];
                    foreach ($_order_lbls as $_lbl) {
                        $_lv = $fn_lbl_val($_lbl);
                        $_t  = $_lbl['name'].($_lv !== '' ? ': '.$_lv : '');
                        foreach ($_lbl['subs'] as $_sl) {
                            $_sv = $fn_sub_val($_sl);
                            if ($_sv !== '' || $_sl['name'] !== '') {
                                $_t .= ' ['.$_sl['name'].($_sv !== '' ? ': '.$_sv : '').']';
                            }
                        }
                        $_lbl_title_parts[] = $_t;
                    }
                    ?>
                    <div style="display:none;overflow:hidden;white-space:normal;max-height:30px;line-height:14px;margin-top:2px;"
                        title="<?= safe_html(implode('  ', $_lbl_title_parts)) ?>">
                        <?php foreach ($_order_lbls as $_lbl):
                            $_lv = $fn_lbl_val($_lbl);
                            $_lbl_text = $_lbl['name'].($_lv !== '' ? ' '.$_lv : '');
                        ?>
                        <span style="display:inline-block;background:#eef2ff;border:1px solid #c5cae9;border-radius:2px;padding:0 3px;margin:0 2px 2px 0;font-size:9px;color:#5c6bc0;line-height:13px;vertical-align:top;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= safe_html($_lbl_text) ?>"><?= safe_html($_lbl_text) ?></span>
                        <?php foreach ($_lbl['subs'] as $_sl):
                            $_sv = $fn_sub_val($_sl);
                            if ($_sv === '' && $_sl['name'] === '') continue;
                            $_sub_text = $_sl['name'].($_sv !== '' ? ' '.$_sv : '');
                        ?>
                        <span style="display:inline-block;background:#f5f5f5;border:1px solid #ddd;border-radius:2px;padding:0 2px;margin:0 2px 2px 0;font-size:8px;color:#888;line-height:12px;vertical-align:top;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= safe_html($_sub_text) ?>">↳<?= safe_html($_sub_text) ?></span>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php
                    $_gear_spec = $gear_map[(int)($order['d_id_ID'] ?? 0)] ?? '';
                    if (!empty($_gear_spec)): ?>
                    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;margin-top:-8px;line-height:1.2;" title="<?= safe_html($_gear_spec) ?>">
                        <span style="font-size:11px;color:#888;font-family:Consolas,monospace;letter-spacing:.5px;"><?= safe_html($_gear_spec) ?></span>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="col-process"><?= safe_html($order['Processing_items']) ?></td>
                <td class="col-qty"><?= number_format($order['Qty'] ?? 0) ?><?php if (!empty($order['qty_over_range'])): ?><br><span style="color:#DD5138;font-size:10px;font-weight:600;white-space:nowrap;" title="OP轉訂單時輸入的數量超出報價階梯區間（含容差後區間），請補報價單">數量超出區間</span><?php endif; ?><?php if (!empty($order['is_repeat_conversion'])): ?><br><span style="color:#F0A24B;font-size:10px;font-weight:600;white-space:nowrap;" title="同一報價項目先前已轉過訂單，這是同一組合的追加訂單">追加訂單</span><?php endif; ?></td>
                <?php
                $upDisplay = '';
                $upRaw = $order['display_unit_price'] ?? $order['unit_price'] ?? '';
                if (!empty($upRaw) && floatval($upRaw) > 0) {
                    $upDisplay = rtrim(rtrim(number_format(floatval($upRaw), 4, '.', ''), '0'), '.');
                }
                ?>
                <td class="col-qty" style="color:#27ae60;"><?= $can_view_amount ? $upDisplay : '' ?></td>
                <td>
                    <div class="textarea-wrap">
                        <textarea class="table-textarea" name="Order_ps" <?= $can_update ? '' : 'readonly' ?> rows="1" data-orig="<?= safe_html($order['Order_ps']) ?>" oninput="autoResize(this)" onfocus="autoResize(this)" onblur="autoResize(this)" onkeydown="handleKeyDown(event, this, '<?= $order['Order_id'] ?>')"><?= safe_html($order['Order_ps']) ?></textarea>
                        <span class="note-more-hint" title="內容超過5行，點擊欄位可展開查看全文"><i class="fa fa-ellipsis-h"></i> 還有更多</span>
                    </div>
                </td>
                <td data-designer-name="<?= safe_html($order['user_cname'] ?? '') ?>">
                    <?php if ($order['user_cname']):
                        $shortName = mb_substr($order['user_cname'], -2, 2, 'UTF-8');
                    ?>
                    <div style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap;white-space:nowrap;">
                        <strong><?= safe_html($shortName) ?></strong>
                        <small style="color:#888;"><?= safe_html($order['ateGet_formatted']) ?></small>
                        <?php if (!empty($order['monthly_count'])): ?>
                        <span class="badge" style="background:#5bc0de;font-size:9px;padding:2px 4px;"><?= intval($order['monthly_count']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="textarea-wrap">
                        <textarea class="table-textarea" name="ateNote" <?= $can_edit_ateNote ? '' : 'readonly' ?> rows="1" data-orig="<?= safe_html($order['ateNote']) ?>" oninput="autoResize(this)" onfocus="autoResize(this)" onblur="autoResize(this)" onkeydown="handleKeyDown(event, this, '<?= $order['Order_id'] ?>')"><?= safe_html($order['ateNote']) ?></textarea>
                        <span class="note-more-hint" title="內容超過5行，點擊欄位可展開查看全文"><i class="fa fa-ellipsis-h"></i> 還有更多</span>
                    </div>
                </td>
                <td class="col-status" name="pmGetCell">
                    <?php
                    $order_status_val = (isset($order['Order_status']) && $order['Order_status'] !== null && $order['Order_status'] !== '') ? intval($order['Order_status']) : null;
                    $is_paused = ($order_status_val === 6);
                    $is_closed = ($order_status_val === 9);
                    // 審圖/轉生管新規則：一般設計人員只能操作自己被指定(order_track.ate)的訂單，管理員不受此限
                    $_row_is_own_design = $OT_IS_ADMIN_ANY || ((int)($order['ate'] ?? 0) > 0 && (int)$order['ate'] === (int)($_SESSION['id'] ?? 0));
                    $_row_can_batch_draw = $can_batch_draw && $_row_is_own_design;
                    $_row_can_to_pm      = $can_to_pm && $_row_is_own_design;
                    if ($is_paused) {
                        $pause_date = $order['Modified_At_formatted'] ?? '';
                        echo '<span style="color:#E67E22; font-size:11px;"><i class="fa fa-pause-circle"></i> ' . ($pause_date ? $pause_date . ' ' : '') . '訂單暫停/取消</span>';
                    } else {
                        if (!(isset($order['pmGet_formatted']) && !empty($order['pmGet_formatted']))) {
                            if (isset($order['in_review_formatted']) && !empty($order['in_review_formatted'])) {
                                echo '<span style="color: green; font-size: 11px; margin-right: 3px;">' . $order['in_review_formatted'] . '審圖</span>';
                                if ($_row_can_batch_draw) {
                                    echo '<button type="button" class="btn btn-xs btn-danger" style="padding: 1px 5px; font-size: 11px;" onclick="cancelInReview(\'' . $order['Order_id'] . '\')">X</button>';
                                }
                            } else {
                                if ($_row_can_batch_draw) {
                                    echo '<button type="button" class="btn btn-xs btn-success" style="padding: 2px 6px; font-size: 11px;" onclick="updateInReview(\'' . $order['Order_id'] . '\')">審圖</button>';
                                } else {
                                    echo '<span style="font-size: 12px; color: #999;">批圖中</span>';
                                }
                            }
                            if ($_row_can_to_pm) {
                                echo '<button type="button" class="btn btn-warning btn-xs" style="padding: 2px 6px; font-size: 11px;" onclick="updatePmGet(\'' . $order['Order_id'] . '\')">轉生管</button>';
                            }
                        } else {
                            if ($_row_can_to_pm) {
                                echo '<button type="button" class="btn btn-xs btn-danger" style="padding: 1px 5px; font-size: 11px;" onclick="cancelPmGet(\'' . $order['Order_id'] . '\')">X</button> ';
                            }
                            echo '<span style="font-size: 12px;">' . $order['pmGet_formatted'] . '</span>';
                            // 系統依「指定特定設計自動轉生管」設定蓋上的，標一個小圖示讓人知道改掉指派設計就會自動退回
                            if (!empty($order['pmGet_auto'])) {
                                echo ' <i class="fa fa-magic" style="color:#F0A24B;font-size:10px;" title="依「指定特定設計自動轉生管」設定自動標記；把指派設計改成名單外的對象即自動退回"></i>';
                            }
                        }
                        if ($is_closed) {
                            echo '<div style="font-size:11px;color:#8e44ad;margin-top:2px;"><i class="fa fa-check-circle"></i> 已結案</div>';
                        }
                    }
                    echo $_bomIconHtml;
                    ?>
                </td>
                <td>
                    <div style="font-size: 11px; color: #999; line-height: 1.3;">
                        <?php if(!empty($order['Order_oo'])): ?><div title="訂單編號"><i class="fa fa-hashtag"></i> <?= safe_html($order['Order_oo']) ?><?php if (!empty($order['quote_no'])): ?> <span style="font-size:10px;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:0 4px;white-space:nowrap;" title="由此報價單(OP)轉入/綁定">來自 <?= safe_html($order['quote_no']) ?></span><?php endif; ?></div><?php endif; ?>
                        <?php if(!empty($order['C_order'])): ?><div title="客戶單號"><i class="fa fa-user"></i> <?= safe_html($order['C_order']) ?></div><?php endif; ?>
                        <?php if(!empty($order['Containers'])): ?><div title="容器"><i class="fa fa-cube"></i> <?= safe_html($order['Containers']) ?></div><?php endif; ?>
                        <?php if($order['Sample'] || $order['JIG']): ?>
                            <div title="樣品/治具">
                                <i class="fa fa-wrench"></i>
                                <?= $order['Sample'] ? safe_html($order['Sample']) : '' ?>
                                <?= ($order['Sample'] && $order['JIG']) ? '/' : '' ?>
                                <?= $order['JIG'] ? safe_html($order['JIG']) : '' ?>
                            </div>
                        <?php endif; ?>
                        <div class="oc-chgbadge-slot" data-oid="<?= intval($order['Order_id']) ?>"></div>
                    </div>
                </td>
            </tr>
<?php
            // ── 子批展開列 ──────────────────────────────────────────────
            $ordSplits = $splitsMap[$order['Order_id']] ?? [];
            if (!empty($ordSplits)):
                $colspan = $show_op_col ? 13 : 12;
?>
            <tr class="split-expand-row" data-parent="<?= $order['Order_id'] ?>" style="background:#f9fafb;">
                <td colspan="<?= $colspan ?>" style="padding:2px 8px 4px 30px;border-top:none;">
                    <div class="split-expand-content" style="display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;">
                        <span style="font-size:10px;color:#888;font-weight:600;white-space:nowrap;"><i class="fa fa-code-fork" style="color:#1ABB9C;"></i> 拆批：</span>
<?php foreach ($ordSplits as $sp): ?>
                        <span style="font-size:10px;background:#fff;border:1px solid #d0e8d8;border-radius:4px;padding:2px 6px;color:#333;white-space:nowrap;">
                            <span style="color:#1ABB9C;font-weight:600;">#<?= intval($sp['split_seq']) ?></span>
                            <span style="color:#555;"><?= safe_html($sp['del_fmt']) ?></span>
                            <strong><?= number_format(intval($sp['Qty'])) ?></strong>件
                            <?php if (!empty($sp['Order_ps'])): ?><span style="color:#999;font-style:italic;"> · <?= safe_html(mb_substr($sp['Order_ps'],0,20,'UTF-8')) . (mb_strlen($sp['Order_ps'],'UTF-8')>20?'…':'') ?></span><?php endif; ?>
                        </span>
<?php endforeach; ?>
                    </div>
                </td>
            </tr>
<?php
            endif;
            // ── 子批展開列結束 ──────────────────────────────────────────
        }
    } else {
        $colspan = $show_op_col ? 12 : 11;
        echo "<tr><td colspan='{$colspan}' class='text-center' style='padding: 20px;'>找不到符合的資料</td></tr>";
    }
    $html = ob_get_clean();

    // 4. 計算分頁 HTML（簡潔版：上一頁 / 頁碼資訊 / 下一頁，顯示於表格右上角）
    $totalPages = max(1, (int)ceil(($statsResult['total_records'] ?? 0) / $limit));
    $pagination = '';
    if ($statsResult['total_records'] > 0) {
        $prevDisabled = ($page <= 1) ? 'disabled' : '';
        $nextDisabled = ($page >= $totalPages) ? 'disabled' : '';
        $startRow = ($offset + 1);
        $endRow   = min($offset + $limit, $statsResult['total_records']);

        $pagination  = '<div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;">';
        $pagination .= '<small style="color:#888;white-space:nowrap;">' . $startRow . '–' . $endRow . ' / ' . $statsResult['total_records'] . ' 筆</small>';
        $pagination .= '<button class="btn btn-default btn-xs" ' . ($prevDisabled ? 'disabled' : '') . ' onclick="fetchTableData(' . max(1,$page-1) . ');return false;" title="上一頁"><i class="fa fa-chevron-left"></i></button>';
        $pagination .= '<span style="font-size:12px;color:#555;">' . $page . ' / ' . $totalPages . '</span>';
        $pagination .= '<button class="btn btn-default btn-xs" ' . ($nextDisabled ? 'disabled' : '') . ' onclick="fetchTableData(' . min($totalPages,$page+1) . ');return false;" title="下一頁"><i class="fa fa-chevron-right"></i></button>';
        $pagination .= '</div>';
    }

    // 5. 【防護罩關閉並丟棄垃圾】
    ob_end_clean(); 

    // 6. 輸出絕對純淨的 JSON
    echo json_encode(['success' => true, 'html' => $html, 'pagination' => $pagination, 'stats' => $statsResult]);
    exit;
}

// =============================================================================
// =============================================================================
// Data Preparation (極致優化版：只撈統計與第一頁，絕不當機)
// =============================================================================
$selectedYear = $_GET['year'] ?? 'ALL'; // 預設改為 ALL

// 1. 統計 SQL (絕對不加 LIMIT，使用 SUM CASE WHEN 在資料庫端直接算好，效能極高)
// 1. 統計 SQL (修正 TIMESTAMP 比對問題)
// 初始頁面統計：paused/closed/unfinished 只看年份
$yearCondInit = ($selectedYear !== 'ALL') ? "WHERE YEAR(ot.Order_date) = " . intval($selectedYear) . " AND (ot.parent_order_id IS NULL OR ot.parent_order_id = 0)" : "WHERE (ot.parent_order_id IS NULL OR ot.parent_order_id = 0)";
// 相容舊表：qty_over_range（OP轉訂單數量超出報價階梯區間旗標）首次載入自動補欄
try { $conn->getPDO()->query("SELECT qty_over_range FROM order_track LIMIT 1"); }
catch (Exception $_eQov) {
    try { $conn->getPDO()->exec("ALTER TABLE order_track ADD COLUMN qty_over_range TINYINT(1) NOT NULL DEFAULT 0 COMMENT '轉單數量超出報價階梯區間(含容差後)=1,待補報價單'"); } catch (Exception $_eQov2) {}
}
// 相容舊表：is_repeat_conversion（OP追加轉單旗標）首次載入自動補欄
try { $conn->getPDO()->query("SELECT is_repeat_conversion FROM order_track LIMIT 1"); }
catch (Exception $_eRep) {
    try { $conn->getPDO()->exec("ALTER TABLE order_track ADD COLUMN is_repeat_conversion TINYINT(1) NOT NULL DEFAULT 0 COMMENT '同一報價項目先前已轉過訂單、此為追加訂單=1(不影響KPI報價轉訂單比例統計)'"); } catch (Exception $_eRep2) {}
}
// 相容舊表：is_urgent（急件旗標）首次載入自動補欄
try { $conn->getPDO()->query("SELECT is_urgent FROM order_track LIMIT 1"); }
catch (Exception $_eUrg) {
    try { $conn->getPDO()->exec("ALTER TABLE order_track ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 COMMENT '急件=1；篩選批圖中時排最上方(多筆依接單日新到舊)，清單以淺暖粉紅底色標示'"); } catch (Exception $_eUrg2) {}
}
$initStatsSql = "SELECT
    COUNT(*) as total_records,
    SUM(CASE WHEN (/* ot.quote_no IS NULL OR ot.quote_no = '' OR */ ot.unit_price IS NULL OR ot.unit_price = 0) THEN 1 ELSE 0 END) as unbound_op,
    SUM(CASE WHEN (ot.qty_over_range = 1) THEN 1 ELSE 0 END) as qty_over,
    SUM(CASE WHEN (ot.Order_status = 6) THEN 1 ELSE 0 END) as paused,
    SUM(CASE WHEN (ot.Order_status = 9) THEN 1 ELSE 0 END) as closed,
    SUM(CASE WHEN (ot.Order_status IS NULL) THEN 1 ELSE 0 END) as unfinished
    FROM order_track ot $yearCondInit";
$initResult = $conn->getPDO()->query($initStatsSql)->fetch(PDO::FETCH_ASSOC);

// 初始 processing/done/communication：排除 Order_status=6（暫停），計算全部訂單
$processingSql = "SELECT
    SUM(CASE WHEN (ot.pmGet IS NULL AND (ot.Order_status IS NULL OR ot.Order_status != 6)) THEN 1 ELSE 0 END) as processing,
    SUM(CASE WHEN (ot.pmGet IS NOT NULL AND (ot.Order_status IS NULL OR ot.Order_status != 6)) THEN 1 ELSE 0 END) as done,
    SUM(CASE WHEN (ot.pmGet IS NULL AND ot.ateNote IS NOT NULL AND ot.ateNote != '' AND (ot.Order_status IS NULL OR ot.Order_status != 6)) THEN 1 ELSE 0 END) as communication
    FROM order_track ot $yearCondInit";
$processingResult = $conn->getPDO()->query($processingSql)->fetch(PDO::FETCH_ASSOC);

$stats = [
    'all'          => $initResult['total_records'] ?? 0,
    'processing'   => $processingResult['processing'] ?? 0,
    'done'         => $processingResult['done'] ?? 0,
    'communication'=> $processingResult['communication'] ?? 0,
    'unbound_op'   => $initResult['unbound_op'] ?? 0,
    'qty_over'     => $initResult['qty_over'] ?? 0,
    'paused'       => $initResult['paused'] ?? 0,
    'closed'       => $initResult['closed'] ?? 0,
    'unfinished'   => $initResult['unfinished'] ?? 0,
];

// 2. 列表 SQL (強制加上 LIMIT 0, 50，首次進網頁只渲染 50 筆)
$dataSql = "SELECT 
    ot.*, 
    CONCAT(DATE_FORMAT(ot.Order_date, '%y'), 'y/', DATE_FORMAT(ot.Order_date, '%c/%e')) AS Order_date_formatted, 
    CONCAT(DATE_FORMAT(ot.Delivery_date, '%y'), 'y/', DATE_FORMAT(ot.Delivery_date, '%c/%e')) AS Delivery_date_formatted, 
    DATE_FORMAT(ot.ateGet, '%c/%e') AS ateGet_formatted, 
    DATE_FORMAT(ot.pmGet, '%c/%e') AS pmGet_formatted, 
    DATE_FORMAT(ot.Created_At, '%c/%e') AS Created_At_formatted, 
    DATE_FORMAT(ot.in_review, '%c/%e') AS in_review_formatted, 
    u.user_cname, 
    creator.user_cname AS creator_name 
FROM order_track ot 
LEFT JOIN user u ON u.id = ot.ate 
LEFT JOIN user AS creator ON creator.id = ot.Created_By 
WHERE (ot.parent_order_id IS NULL OR ot.parent_order_id = 0) " . ($selectedYear !== 'ALL' ? "AND YEAR(ot.Order_date) = " . intval($selectedYear) : "") . " 
ORDER BY ot.Created_At DESC LIMIT 0, 50";

$order_list = $conn->getPDO()->query($dataSql)->fetchAll(PDO::FETCH_ASSOC);

// 3. 取得設計師當月件數 (獨立查詢，避免複雜迴圈)
$orderCountsByDesignerMonth = [];
$designerCountYear = ($selectedYear !== 'ALL') ? intval($selectedYear) : intval(date('Y'));
$designerSql = "SELECT u.user_cname, DATE_FORMAT(ot.ateGet, '%c') AS month, COUNT(*) as count FROM order_track ot JOIN user u ON u.id = ot.ate WHERE YEAR(ot.ateGet) = :year AND ot.ate IS NOT NULL AND ot.ateGet IS NOT NULL GROUP BY u.user_cname, month";
$dStmt = $conn->getPDO()->prepare($designerSql);
$dStmt->execute([':year' => $designerCountYear]);
$dCounts = $dStmt->fetchAll(PDO::FETCH_ASSOC);
foreach($dCounts as $c) {
    $orderCountsByDesignerMonth[$c['user_cname']][(int)$c['month']] = $c['count'];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>訂單追蹤 (Creative)</title>
    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <!-- DataTables -->
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">

    <style>
        /* Modern Variables */
        :root {
            --primary-color: #2A3F54;
            --accent-color: #1ABB9C;
            --bg-color: #F4F7FC;
            --card-bg: #FFFFFF;
            --text-color: #495057;
            --border-color: #E6E9ED;
        }
        .autocomplete-suggestions {
            border: 1px solid #ddd;
            max-height: 150px;
            overflow-y: auto;
            position: absolute;
            background: #fff;
            z-index: 9999;
            width: 100%;
        }
        .suggestion-item {
            padding: 8px;
            cursor: pointer;
        }
        .suggestion-item:hover {
            background-color: #f0f0f0;
        }

        body {
            background-color: var(--bg-color);
            font-family: "Segoe UI", "Roboto", "Helvetica Neue", Arial, sans-serif;
            color: var(--text-color);
            overflow-x: hidden;
        }
        
        .right_col { background-color: var(--bg-color) !important; 

            border-radius: 20px;
            padding: 5px 15px;
            background: #fff;
            font-weight: 600;
            color: var(--primary-color);
            cursor: pointer;
            outline: none;
        }

        /* Stats Cards */
        .stats-container {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        .stat-card {
            flex: 1;
            background: var(--card-bg);
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
            cursor: pointer;
            border-left: 4px solid transparent;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .stat-card.active { background-color: #fff; transform: scale(1.02); box-shadow: 0 0 0 3px #2A3F54; z-index: 1; }
        
        .stat-card.card-all { border-left-color: #3498DB; }
        .stat-card.card-all.active { box-shadow: 0 0 0 3px #3498DB; }
        .stat-card.card-processing { border-left-color: #F39C12; }
        .stat-card.card-processing.active { box-shadow: 0 0 0 3px #F39C12; }
        .stat-card.card-done { border-left-color: #1ABB9C; }
        .stat-card.card-done.active { box-shadow: 0 0 0 3px #1ABB9C; }
        .stat-card.card-communication { border-left-color: #9B59B6; }
        .stat-card.card-communication.active { box-shadow: 0 0 0 3px #9B59B6; }
        .stat-card.card-unbound-op { border-left-color: #E67E22; }
        .stat-card.card-unbound-op.active { box-shadow: 0 0 0 3px #E67E22; }

        .stat-icon {
            position: absolute;
            right: 15px;
            top: 15px;
            font-size: 32px;
            opacity: 0.1;
        }
        .stat-value { font-size: 24px; font-weight: 800; margin-bottom: 2px; color: var(--primary-color); }
        .stat-label { font-size: 13px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }


        /* Main Table Card */
        .main-card {
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            padding: 15px;
            border: none;
        }

        /* Table Styling - Compact */
        table.dataTable {
            border-collapse: collapse !important;
            width: 100% !important;
        }
        table.dataTable thead th {
            background-color: #F8F9FA;
            color: #555;
            font-weight: 700;
            border-bottom: 2px solid #E9ECEF;
            padding: 10px 5px;
            font-size: 13px;
            white-space: nowrap;
            vertical-align: middle;
        }
        table.dataTable tbody td {
            padding: 6px 5px; /* Reduced padding for height */
            vertical-align: middle;
            border-bottom: 1px solid #F1F3F5;
            font-size: 13px;
            line-height: 1.4;
        }
        table.dataTable tbody tr:hover { background-color: #FAFBFE !important; }
        /* 急件（2026-09-03）：淺暖粉紅底色，需蓋過 table-striped 的斑馬紋故用 !important */
        #orderTable tbody tr.order-urgent > td { background-color: #FBE3DD !important; }
        #orderTable tbody tr.order-urgent:hover > td { background-color: #F7D5CC !important; }
        .urgent-badge { display:inline-block;background:#F0A24B;color:#4A2A0A;font-size:10px;font-weight:700;
                        border-radius:3px;padding:0 5px;letter-spacing:1px; }
        
        /* Column Specifics */
        .col-date { width: 88px; text-align: center; font-family: "Consolas", monospace; color: #666; }
        .col-process { min-width: 90px; }
        .col-client { font-weight: 600; color: #2A3F54; max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .stat-card-half:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important; opacity:0.9; }
        .stat-card-half.active { outline: 2px solid #2A3F54; }
        .stat-value-sm { font-size: 18px; font-weight: 700; line-height: 1; }
        .stat-card-half:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.15); opacity:0.9; }
        .stat-card-half.active { outline: 2px solid #2A3F54; }
        .stat-value-sm { font-size: 18px; font-weight: 700; line-height: 1; }
        /* 料號欄：改成「可換行完整顯示」而非硬撐寬度——長料號自動折行，不會被遮蔽、
           也不會把總寬撐到出現左右捲軸（2026-07-31 使用者要求頁面完整顯示、禁用左右拉桿） */
        .col-part { font-family: "Consolas", monospace; font-weight: 600; color: #007bff; min-width: 150px; word-break: break-all; }
        .col-part > div { flex-wrap: wrap !important; }   /* 料號旁的徽章/按鈕擠不下時換行，不壓縮料號本文 */
        .col-qty { text-align: right; font-weight: 700; color: var(--accent-color); width: 60px; }
        .col-status { text-align: center; white-space: nowrap; width: 96px; }

        /* 料號欄（.col-part 已設 min-width，此規則作為補強）
           欄位順序（有操作欄）：1操作 2接單/交期 3客戶 4料號 */
        #orderTable th:nth-child(4),
        #orderTable td:nth-child(4) {
            min-width: 150px;
        }
        
        /* Editable Textarea（備註類欄位＝可壓縮的次要欄位：min-width 200→110，
           內容自動換行、點入編輯時仍會自動長高，全文不會遺失，只是預設佔位變窄） */
        .table-textarea {
            width: 100%;
            min-width: 110px;
            max-width: 400px;
            min-height: 32px;
            resize: vertical;
            border: 1px solid transparent;
            background: transparent;
            padding: 4px;
            font-size: 13px;
            border-radius: 3px;
            transition: all 0.2s;
            line-height: 1.4;
            overflow-y: hidden;
        }
        .table-textarea:hover { border-color: #ddd; background: #f9f9f9; }
        .table-textarea:focus { border-color: #3498DB; background: #fff; outline: none; box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1); }

        /* 備註欄位超過5行時的「還有更多」提示 */
        .textarea-wrap { position: relative; }
        .textarea-wrap .note-more-hint {
            display: none;
            position: absolute;
            right: 5px;
            bottom: 3px;
            background: rgba(255, 193, 7, 0.9);
            color: #7a5200;
            font-size: 9px;
            line-height: 1;
            padding: 2px 4px;
            border-radius: 3px;
            pointer-events: none;
            box-shadow: 0 1px 2px rgba(0,0,0,.15);
        }
        .textarea-wrap.has-more .note-more-hint { display: block; }

        /* Part Link */
        .part-link {
            cursor: pointer;
            text-decoration: none;
            border-bottom: 1px dashed #007bff;
            transition: all 0.2s;
        }
        .part-link:hover { border-bottom-style: solid; color: #0056b3; }
        .copy-icon {
            color: #adb5bd;
            cursor: pointer;
            margin-right: 5px;
            transition: color 0.2s;
        }
        .copy-icon:hover { color: #495057; }

        /* Modal Styling */
        .modal-content { border-radius: 8px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        /* 新增訂單視窗：內容過高（顯示庫存後）時不再把底部按鈕擠出畫面，改為只捲動內容區、頁尾按鈕固定可見 */
        #newOrderModal .modal-content { display: flex; flex-direction: column; max-height: calc(100vh - 40px); }
        #newOrderModal .modal-header, #newOrderModal .modal-footer { flex: 0 0 auto; }
        #newOrderModal .modal-body { flex: 1 1 auto; overflow-y: auto; min-height: 0; }
        .modal-header {
            background: var(--primary-color);
            color: white;
            border-radius: 8px 8px 0 0;
            padding: 15px 20px;
        }
        .modal-title { font-weight: 600; }
        .close { color: white; opacity: 0.8; text-shadow: none; }
        .close:hover { opacity: 1; }
        
        .file-list-container {
            background: #F8F9FA;
            border-right: 1px solid #E9ECEF;
            height: 82vh;
            overflow-y: auto;
        }
        .file-viewer-container {
            background: #E9ECEF;
            height: 82vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .file-item {
            padding: 10px 15px;
            border-bottom: 1px solid #E9ECEF;
            cursor: pointer;
            transition: background 0.2s;
        }
        .file-item:hover { background: #E2E6EA; }
        .file-item.active { background: #fff; border-left: 4px solid var(--accent-color); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .file-item h5 { margin: 0 0 3px 0; font-size: 14px; font-weight: 600; color: #333; }
        .file-item p { margin: 0; font-size: 11px; color: #888; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 45px;
            height: 45px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            cursor: pointer;
            z-index: 100;
            transition: all 0.3s;
            opacity: 0;
            transform: translateY(20px);
        }
        .fab.visible { opacity: 1; transform: translateY(0); }
        .fab:hover { background: var(--accent-color); transform: translateY(-3px); }

        /* Loading Spinner */
        .loading-spinner { font-size: 12px; color: #666; font-style: italic; }

        .form-group.has-feedback .form-control-feedback {
            pointer-events: auto; /* 讓圖示可以被點擊 */
        }

        /* ── 鍵槽計算工具 ─────────────────────────────────────────────────── */
        /* ── 鍵槽計算工具 ─────────────────────────────────────────────── */
        #kw-tool-window {
            position:fixed; z-index:10400; display:none;
            width:920px; max-width:96vw; top:55px; left:50%; transform:translateX(-50%);
            background:#fff; border-radius:8px;
            box-shadow:0 12px 40px rgba(0,0,0,.35); border:1px solid #a5d6a7;
        }
        #kw-tool-hdr {
            border-radius:8px 8px 0 0;
            background:linear-gradient(135deg,#1a3a2a,#27ae60);
            color:#fff; padding:9px 14px; cursor:move;
            display:flex; align-items:center; justify-content:space-between; user-select:none;
        }
        #kw-tool-hdr .kw-hdr-title { font-size:14px; font-weight:700; display:flex; align-items:center; gap:8px; }
        #kw-tool-hdr .kw-hdr-btns  { display:flex; gap:6px; }
        #kw-tool-hdr button { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3); color:#fff; border-radius:4px; padding:2px 10px; font-size:12px; cursor:pointer; }
        #kw-tool-hdr button:hover { background:rgba(255,255,255,.3); }
        #kw-tool-body { padding:10px 12px 12px; }
        /* 3-column layout */
        .kw-layout { display:flex; gap:0; align-items:flex-start; }
        .kw-col-l { width:205px; flex-shrink:0; }
        .kw-col-c { width:225px; flex-shrink:0; display:flex; align-items:flex-start; }
        .kw-col-r { flex:1; min-width:0; }
        /* Cards */
        .kw-card { border:1.5px solid #bbb; border-radius:4px; overflow:hidden; margin-bottom:5px; }
        .kw-card:last-child { margin-bottom:0; }
        .kw-ch { font-size:11px; font-weight:700; color:#fff; padding:4px 7px; letter-spacing:.2px; }
        .kw-ch-lt   { background:#5b2c6f; }
        .kw-ch-lb   { background:#1a5276; }
        .kw-ch-rt   { background:#7b241c; }
        .kw-ch-rr   { background:#4a5320; }
        .kw-ch-rb   { background:#784212; }
        .kw-ch-mach { background:#0e6655; }
        .kw-mutex-tag { font-size:9px; font-weight:400; opacity:.8; }
        /* Dimension row: [nom] [tol_up (lim_up)] / [tol_lo (lim_lo)] */
        .kw-dr { display:flex; align-items:center; padding:4px 7px; gap:5px; background:#fafafa; }
        .kw-dr + .kw-dr { border-top:1px solid #eee; }
        /* Nominal cell */
        .kw-ni, .kw-no {
            width:64px; flex-shrink:0; font-family:"Consolas","Courier New",monospace;
            font-size:17px; font-weight:700; text-align:center;
            border:1px solid #aaa; border-radius:3px; padding:3px 2px; box-sizing:border-box;
            display:block;
        }
        .kw-ni { background:#d5d5d5; color:#222; appearance:textfield; -moz-appearance:textfield; }
        .kw-ni:focus { outline:none; background:#fff; border-color:#27ae60; }
        .kw-no { background:#a8d9f5; color:#1a3a50; }
        /* Tolerance+limit column */
        .kw-tc { display:flex; flex-direction:column; gap:3px; flex:1; min-width:0; }
        .kw-tr { display:flex; align-items:center; gap:4px; }
        .kw-ti, .kw-to {
            width:52px; flex-shrink:0; font-family:"Consolas","Courier New",monospace;
            font-size:11px; text-align:center; border-radius:2px; padding:2px 3px; box-sizing:border-box;
            display:inline-block;
        }
        .kw-ti { background:#d5d5d5; color:#333; border:1px solid #aaa; appearance:textfield; -moz-appearance:textfield; }
        .kw-ti:focus { outline:none; background:#fff; border-color:#27ae60; }
        .kw-to { background:#a8d9f5; color:#1a3a50; border:none; }
        .kw-lv { font-family:"Consolas","Courier New",monospace; font-size:11px; color:#1a3a50; min-width:52px; display:inline-block; }
        .kw-ni::-webkit-outer-spin-button,.kw-ni::-webkit-inner-spin-button,
        .kw-ti::-webkit-outer-spin-button,.kw-ti::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        /* Diff area */
        .kw-diff { font-size:10.5px; padding:4px 7px; background:#edf4ff; border-top:1px solid #c5d8f5; }
        .kw-diff-r { display:flex; gap:5px; align-items:center; margin:2px 0; }
        .kw-diff-lbl { color:#555; min-width:0; }
        .kw-diff-v { font-family:"Consolas","Courier New",monospace; font-weight:700; color:#1a3a50; }
        /* Small note */
        .kw-note { font-size:9.5px; color:#777; padding:2px 7px; background:#f4f4f4; border-bottom:1px solid #e0e0e0; }
        /* Message box */
        #kw-msg-box { font-size:11px; padding:5px 10px; border-radius:4px; margin-top:6px; display:none; }
        #kw-msg-box.warn { background:#fffbea; border:1px solid #f5c518; color:#856404; display:block; }
        #kw-msg-box.err  { background:#fff0f0; border:1px solid #e74c3c; color:#c0392b; display:block; }
        @media (max-width:768px) { #kw-tool-window { width:98vw; top:10px; } .kw-layout { flex-direction:column; } .kw-col-c { width:100%; } }
    </style>
</head>
<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <div class="right_col" role="main">
                <div class="">
                    <!-- Header -->
                    <div class="page-title">
                        <div class="title_left">
                            <?php
                            // 權限標籤：舊制代碼一律顯示，若使用者有指派本頁角色則一併顯示角色名稱（$OT_USE_RBAC 尚未啟用前僅供對照參考，
                            // 實際生效的仍是舊制代碼；管理員顯示為「管理員」而非角色名清單，避免跟指派的個別角色混淆）
                            $_perm_label = $display_permission_code !== '' ? $display_permission_code : '無';
                            if ($IS_OT_RBAC_ADMIN) {
                                $_perm_label .= '｜角色：管理員';
                            } elseif (!empty($_ot_my_roles)) {
                                $_perm_label .= '｜角色：' . implode('、', $_ot_my_roles);
                            }
                            ?>
                            <h3>訂單追蹤 <small>(權限：<?= safe_html($_perm_label) ?>)</small></h3>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <!-- Stats Cards (Filters) -->
                    <div class="stats-container">
                        <div class="stat-card card-all active" id="stat-card-all">
                            <i class="fa fa-list-alt stat-icon"></i>
                            <div class="stat-value" id="count-all"><?= number_format($stats['all']) ?></div>
                            <div class="stat-label">全部訂單</div>
                            <div id="count-paused-note" style="font-size:10px;color:#E67E22;margin-top:2px;<?= ($stats['paused'] ?? 0) > 0 ? '' : 'display:none;' ?>"><?= number_format($stats['paused'] ?? 0) ?>筆訂單已暫停/取消</div>
                        </div>
                        <div class="stat-card card-processing">
                            <i class="fa fa-clock-o stat-icon"></i>
                            <div class="stat-value" id="count-processing"><?= number_format($stats['processing']) ?></div>
                            <div class="stat-label">批圖中</div>
                        </div>
                        <div class="stat-card card-communication">
                            <i class="fa fa-comments stat-icon"></i>
                            <div class="stat-value" id="count-communication"><?= number_format($stats['communication']) ?></div>
                            <div class="stat-label">批圖溝通中</div>
                        </div>
                        <div class="stat-card card-done">
                            <i class="fa fa-check-circle stat-icon"></i>
                            <div class="stat-value" id="count-done"><?= number_format($stats['done']) ?></div>
                            <div class="stat-label">已轉生管</div>
                        </div>
                        <?php if ($can_create): ?>
                        <div class="stat-card card-unbound-op" id="stat-card-unbound-op" onclick="toggleUnboundOp(this)" style="cursor:pointer;">
                            <i class="fa fa-exclamation-triangle stat-icon"></i>
                            <div class="stat-value" id="count-unbound-op" style="color:#E67E22;"><?= number_format($stats['unbound_op']) ?></div>
                            <div class="stat-label">單價為0</div>
                            <div id="count-qty-over-note" onclick="event.stopPropagation();toggleQtyOver(this);"
                                 title="OP轉訂單數量超出報價階梯區間（含容差後）的訂單，點擊篩選，需補報價單"
                                 style="font-size:10px;color:#DD5138;margin-top:2px;cursor:pointer;text-decoration:underline dotted;<?= ($stats['qty_over'] ?? 0) > 0 ? '' : 'display:none;' ?>">
                                <span id="count-qty-over"><?= number_format($stats['qty_over'] ?? 0) ?></span>筆數量超出區間
                            </div>
                        </div>
                        <!-- 三個半高卡片 -->
                        <div style="display:flex;flex-direction:column;gap:4px;flex:0 0 auto;justify-content:center;">
                            <div class="stat-card-half" id="stat-card-unfinished" onclick="toggleStatusCard('unfinished',this)" style="cursor:pointer;padding:5px 10px;display:flex;align-items:center;justify-content:space-between;background:#f0f4f8;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);min-width:110px;">
                                <div><span class="stat-value-sm" id="count-unfinished"><?= number_format($stats['unfinished'] ?? 0) ?></span><span style="font-size:11px;color:#555;margin-left:4px;">未結案</span></div>
                                <i class="fa fa-inbox" style="color:#7f8c8d;font-size:14px;"></i>
                            </div>
                            <div class="stat-card-half" id="stat-card-paused" onclick="toggleStatusCard('paused',this)" style="cursor:pointer;padding:5px 10px;display:flex;align-items:center;justify-content:space-between;background:#fef9f0;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);min-width:110px;">
                                <div><span class="stat-value-sm" id="count-paused" style="color:#E67E22;"><?= number_format($stats['paused'] ?? 0) ?></span><span style="font-size:11px;color:#E67E22;margin-left:4px;">訂單暫停/取消</span></div>
                                <i class="fa fa-pause-circle" style="color:#E67E22;font-size:14px;"></i>
                            </div>
                            <div class="stat-card-half" id="stat-card-closed" onclick="toggleStatusCard('closed',this)" style="cursor:pointer;padding:5px 10px;display:flex;align-items:center;justify-content:space-between;background:#f5f0fb;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);min-width:110px;">
                                <div><span class="stat-value-sm" id="count-closed" style="color:#8e44ad;"><?= number_format($stats['closed'] ?? 0) ?></span><span style="font-size:11px;color:#8e44ad;margin-left:4px;">已結案</span></div>
                                <i class="fa fa-check-circle" style="color:#8e44ad;font-size:14px;"></i>
                            </div>
                        </div>
                        <div class="stat-card" style="background:#26B99A;color:white;flex:0 0 90px;display:flex;align-items:center;justify-content:center;padding:0;" onclick="openNewOrderModal()">
                            <div style="text-align:center;">
                                <i class="fa fa-plus-circle" style="font-size:24px;margin-bottom:3px;"></i>
                                <div style="font-weight:600;font-size:13px;">新增訂單</div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($can_op_convert): ?>
                        <div class="stat-card" style="background:#e67e22;color:white;flex:0 0 90px;display:flex;align-items:center;justify-content:center;padding:0;" onclick="openOpConvertModal()">
                            <div style="text-align:center;">
                                <i class="fa fa-exchange" style="font-size:24px;margin-bottom:3px;"></i>
                                <div style="font-weight:600;font-size:13px;">OP轉訂單</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Filter Bar -->
                    <style>
                    /* 篩選列自適應：一行放不下時加上 .fb-compact，按鈕只顯示圖示（滑鼠移過看 title 名稱） */
                    .filter-bar.fb-compact .fb-txt { display:none; }
                    /* 圖示化後仍塞不下而換行：分頁不再靠右推出大片空白，改為緊接按鈕排列 */
                    .filter-bar.fb-packed #pagination-container { margin-left:0 !important; }
                    </style>
                    <div class="filter-bar" style="background: #fff; padding: 8px 10px; border-radius: 8px; margin-bottom: 15px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                        <select id="year-select" class="form-control input-sm" style="width: 92px; display: inline-block;">
                            <option value="ALL" <?= $selectedYear === 'ALL' ? 'selected' : '' ?>>全部年份</option>
                            <?php 
                            $currY = date('Y');
                            for($y=2024; $y<=$currY; $y++) {
                                $sel = ($y == $selectedYear) ? 'selected' : '';
                                echo "<option value='$y' $sel>$y 年</option>";
                            }
                            ?>
                        </select>
                        <label style="margin:0; font-weight:600; color:#555;">篩選:</label>
                        <select id="filter-designer" class="form-control input-sm" style="width: 80px; display: inline-block;">
                            <option value="">全部設計</option>
                            <option value="__none__">無</option>
                            <?php foreach($ate_list as $ate): 
                                $name = $ate['user_cname'];
                                // 只取括號前的部分，再取後兩字
                                $baseName = (strpos($name, ' (') !== false) ? trim(explode(' (', $name, 2)[0]) : $name;
                                $displayName = mb_substr($baseName, -2, 2, 'UTF-8');
                            ?>
                                <option value="<?= safe_html($name) ?>"><?= safe_html($displayName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="position:relative;display:inline-block;vertical-align:middle;">
                            <input type="text" id="filter-client" class="form-control input-sm" placeholder="搜尋客戶(名稱/ID)" style="width:115px;" autocomplete="off">
                            <div id="filter-client-dd" style="display:none;position:absolute;top:100%;left:0;z-index:9999;background:#fff;border:1px solid #ccc;border-radius:4px;box-shadow:0 3px 8px rgba(0,0,0,.15);min-width:220px;max-height:220px;overflow-y:auto;font-size:12px;"></div>
                        </div>
                        <input type="text" id="filter-part" class="form-control input-sm" placeholder="搜尋料號" style="width: 105px;">
                        <input type="text" id="filter-global" class="form-control input-sm" placeholder="全表搜尋" style="width: 130px;">
                        <button type="button" class="btn btn-warning btn-sm" id="filter-unbound" style="margin:0;" title="篩選尚未綁定客戶ID或料號ID的訂單">
                            <i class="fa fa-unlink"></i><span class="fb-txt"> 未綁定</span>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" id="clear-filters" style="margin: 0;" title="清除篩選條件"><i class="fa fa-times"></i><span class="fb-txt"> 取消</span></button>
                        <?php if ($show_gear_tool): ?>
                        <button type="button" id="btn-open-gear-tool"
                            onclick="openGearTool()"
                            title="齒輪計算工具（技術課專用）"
                            style="margin:0;padding:4px 10px;font-size:12px;background:linear-gradient(135deg,#1a252f,#2980b9);color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fa fa-cog" style="font-size:13px;"></i><span class="fb-txt"> 齒輪計算</span>
                        </button>
                        <?php endif; ?>
                        <?php if ($can_keyway_calc): ?>
                        <button type="button" id="btn-open-kw-tool"
                            onclick="openKwTool()"
                            title="軸件鍵槽計算工具"
                            style="margin:0;padding:4px 10px;font-size:12px;background:linear-gradient(135deg,#1a3a2a,#27ae60);color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fa fa-key" style="font-size:13px;"></i><span class="fb-txt"> 鍵槽計算</span>
                        </button>
                        <?php endif; ?>
                        <!-- 訂單變更：變更(全部歷史) + 設定(限A權限，見 $can_order_change_setting)，緊鄰鍵槽計算右側 -->
                        <button type="button" id="btn-order-change-history"
                            onclick="openChangeHistory()"
                            title="全部訂單變更歷史紀錄"
                            style="margin:0;padding:4px 10px;font-size:12px;background:linear-gradient(135deg,#5d4037,#a1887f);color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fa fa-history" style="font-size:13px;"></i><span class="fb-txt"> 變更</span>
                        </button>
                        <?php if ($can_order_change_setting): ?>
                        <button type="button" id="btn-order-change-settings"
                            onclick="openChangeSettings()"
                            title="訂單變更設定（通知對象、附件路徑、列印表頭表尾）"
                            style="margin:0;padding:4px 10px;font-size:12px;background:linear-gradient(135deg,#37474f,#607d8b);color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fa fa-cog" style="font-size:13px;"></i><span class="fb-txt"> 設定</span>
                        </button>
                        <?php endif; ?>
                        <!-- 批圖編輯器：獨立跳窗（可拖到另一個螢幕），小畫家+Figma 混合式圖面編輯 -->
                        <button type="button" id="btn-image-editor"
                            onclick="window.open('image_editor.php', 'egImgEditor_' + Date.now(), 'width=1280,height=860,menubar=no,toolbar=no,location=no,status=no,resizable=yes')"
                            title="批圖編輯器（貼上/拖入圖面、遮蓋客戶資料、加標籤文字、球標與設變標示、多圖合併、列印/另存）"
                            style="margin:0;padding:4px 10px;font-size:12px;background:linear-gradient(135deg,#6a1b9a,#ab47bc);color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fa fa-paint-brush" style="font-size:13px;"></i><span class="fb-txt"> 批圖</span>
                        </button>
                        <?php if ($IS_OT_RBAC_ADMIN): ?>
                        <!-- 角色設定：僅管理員可見，設定本頁各功能的角色權限 -->
                        <button type="button" id="btn-ot-role-settings"
                            onclick="otOpenRoleModal()"
                            title="角色設定（本頁各功能的角色權限，僅管理員）"
                            style="margin:0;padding:4px 10px;font-size:12px;background:linear-gradient(135deg,#8a5a2b,#c0762c);color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fa fa-key" style="font-size:13px;"></i><span class="fb-txt"> 角色設定</span>
                        </button>
                        <?php endif; ?>
                        <!-- 分頁控制：推到最右側與按鈕同列 -->
                        <div id="pagination-container" style="margin-left:auto; display:flex; align-items:center; white-space:nowrap;"></div>
                    </div><!-- /filter-bar -->

                    <!-- Main Table -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="main-card">
                                <!-- 未綁定篩選警示橫幅 -->
                                <div id="unbound-filter-banner" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:5px;padding:7px 12px;margin-bottom:8px;color:#856404;font-size:12px;">
                                    <i class="fa fa-exclamation-triangle" style="color:#e67e22;margin-right:5px;"></i>
                                    <strong>未綁定篩選已啟用</strong> — 目前顯示的是 <span id="unbound-banner-type">未綁定客戶/料號 ID</span> 的訂單，請逐筆確認並完成綁定。
                                    <button type="button" class="btn btn-xs btn-default pull-right" onclick="clearUnboundFilter()" style="margin-top:-1px;"><i class="fa fa-times"></i> 取消篩選</button>
                                </div>
                                <!-- 2026-07-31 使用者要求：頁面完整顯示、禁用左右拉桿——overflow-x 改 hidden，
                                     欄寬以「料號可折行＋備註/次要欄可壓縮」讓表格自然塞進容器，不再出現水平捲軸 -->
                                <div style="overflow-x:hidden; width:100%;">
                                <table id="orderTable" class="table table-striped" data-no-dt="1">
                                    <thead>
                                        <tr>
                                            <?php if ($show_op_col): ?>
                                                <th style="width: 50px; text-align: center;">操作</th>
                                            <?php endif; ?>
                                            <th class="col-date">接單日<br>交期</th>
                                            <th class="col-client">客戶</th>
                                            <th class="col-part">料號 (圖面)</th>
                                            <th class="col-process">製程</th>
                                            <th class="col-qty">數量</th>
                                            <th class="col-qty">單價</th>
                                            <th>業務備註</th>
                                            <th style="white-space:nowrap;">設計/日期</th>
                                            <th>設計備註</th>
                                            <th class="col-status">轉生管日<br>BOM開立</th>
                                            <th>其他資訊</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr><td colspan="20" class="text-center" style="padding:20px;"><i class="fa fa-spinner fa-spin fa-2x"></i> 載入中...</td></tr>
                                    </tbody>
                                </table>
                                </div><!-- end overflow-x wrapper -->
                        </div>
                    </div>
                </div>
            </div>
            <?php include '../partPage/footer.html' ?>
        </div>
    </div>

    <!-- BOM File Modal -->
    <div class="modal fade" id="bomFileModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" style="width: 70%; max-width: 1600px;">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-image"></i> 產品圖檔: <span id="modal-product-title"></span></h4>
                </div>
                <div class="modal-body" style="padding: 0;">
                    <div class="row" style="margin: 0;">
                        <div class="col-md-3 file-list-container">
                            <div id="bom-file-list">
                                <!-- File items injected here -->
                            </div>
                        </div>
                        <div class="col-md-9 file-viewer-container">
                            <div id="bom-file-viewer">
                                <!-- Preview content -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- New Order Modal -->
    <div class="modal fade" id="newOrderModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" style="width:92%;max-width:1200px;" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <button type="button" id="btn-toggle-closed" class="btn btn-xs pull-right" style="display:none;margin-right:8px;margin-top:2px;" onclick="toggleOrderStatus('closed')"></button>
                    <button type="button" id="btn-toggle-urgent" class="btn btn-xs pull-right" style="margin-right:8px;margin-top:2px;" onclick="toggleUrgentFlag()" title="標記為急件：篩選「批圖中」時會排在最上方，清單以淺暖粉紅底色標示"></button>
                    <h4 class="modal-title"><i class="fa fa-plus-circle"></i> 新增訂單</h4>
                </div>
                <div class="modal-body" style="background:#f7f7f7;padding:12px;">
                    <div class="row" style="margin:0;">
                        <!-- ── 左側表單 ── -->
                        <div class="col-md-7" style="padding-right:12px;">
                            <form id="newOrderForm">
                                <input type="hidden" name="Order_id"            id="hidden_Order_id">
                                <input type="hidden" name="Client_name_ID"      id="selected_customer_pk">
                                <input type="hidden" name="d_id_ID"             id="selected_part_pk">
                                <input type="hidden" name="bound_quote_item_id" id="bound_quote_item_id">
                                <input type="hidden" name="quote_no"            id="hidden_quote_no">
                                <input type="hidden" name="batch_key"           id="order_attach_batch_key">
                                <input type="hidden" name="is_urgent"           id="hidden_is_urgent" value="0">
                                <div class="row" style="margin:0 -5px;">

                                    <!-- Row 1: 訂單編號 | 客戶單號 -->
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">訂單編號 <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control input-sm" name="OrderNo" placeholder="訂單編號">
                                    </div>
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">客戶單號</label>
                                        <input type="text" class="form-control input-sm" name="Client_OrderNo" placeholder="客戶單號">
                                    </div>

                                    <!-- Row 2: 接單日期 | 交期 -->
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">接單日期</label>
                                        <input type="date" class="form-control input-sm" name="orderindate" value="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">交期 <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control input-sm" name="orderDdate">
                                    </div>

                                    <!-- Row 3: 客戶 | 料號 (各佔半排) -->
                                    <div class="col-xs-6 form-group" style="padding:0 5px;position:relative;">
                                        <label class="ctrl-label">客戶 <span class="text-danger">*</span>
                                            <small id="customer-id-badge" style="display:none;background:#d4edda;color:#155724;padding:1px 5px;border-radius:10px;font-size:10px;font-weight:600;margin-left:3px;"></small>
                                            <small id="customer-id-missing" style="display:none;color:#c0392b;font-size:10px;margin-left:3px;">⚠未綁定</small>
                                            <button type="button" id="btn-quick-add-customer" class="btn btn-xs btn-link" style="display:none;color:#27ae60;padding:0;margin-left:4px;font-size:10px;" onclick="openQuickAddCustomer()"><i class="fa fa-plus-circle"></i>新增</button>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" name="Client_Name" id="client_name_input" placeholder="客戶代碼或名稱..." autocomplete="off">
                                            <span class="input-group-btn">
                                                <button type="button" class="btn btn-default" title="客戶資料管理" onclick="openCustomerSettingsModal()" tabindex="-1"><i class="fa fa-cog"></i></button>
                                            </span>
                                        </div>
                                        <div id="client-suggestions" class="autocomplete-suggestions"></div>
                                        <!-- 來源OP單客戶不一致提示（2026-08-28）：純提示，不自動改欄位值 -->
                                        <div id="op-cust-diff-hint" style="display:none;margin-top:3px;font-size:10px;line-height:1.5;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:2px 5px;"></div>
                                    </div>
                                    <div class="col-xs-6 form-group" style="padding:0 5px;position:relative;">
                                        <label class="ctrl-label">料號 <span class="text-danger">*</span>
                                            <small id="part-id-badge" style="display:none;background:#d4edda;color:#155724;padding:1px 5px;border-radius:10px;font-size:10px;font-weight:600;margin-left:3px;"></small>
                                            <small id="part-id-missing" style="display:none;color:#c0392b;font-size:10px;margin-left:3px;">⚠未綁定</small>
                                            <small id="part-drawing-no-badge" style="display:none;font-size:10px;color:#1a7abf;margin-left:4px;"></small>
                                            <button type="button" id="btn-quick-add-part" class="btn btn-xs btn-link" style="display:none;color:#27ae60;padding:0;margin-left:4px;font-size:10px;" onclick="openQuickAddPart()"><i class="fa fa-plus-circle"></i>新增</button>
                                        </label>
                                        <input type="hidden" id="selected_part_drawing_no" value="">
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" name="d_id" id="part_id_input" placeholder="料號或規格..." autocomplete="off">
                                            <span class="input-group-btn">
                                                <button type="button" class="btn btn-default" title="料號資料管理" onclick="openPartSettingsModal()" tabindex="-1"><i class="fa fa-cog"></i></button>
                                            </span>
                                        </div>
                                        <div id="part-suggestions" class="autocomplete-suggestions"></div>
                                    </div>

                                    <!-- 料號庫存資訊面板（自動載入） -->
                                    <div class="col-xs-12" style="padding:0 5px;">
                                        <div id="part-stock-panel" style="display:none;background:#f9f9f9;border:1px solid #e5e5e5;border-radius:4px;padding:6px 8px;margin-bottom:8px;font-size:11px;">
                                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                                                <strong style="color:#555;font-size:11px;"><i class="fa fa-archive" style="margin-right:5px;color:#27ae60;"></i>庫存狀況</strong>
                                                <button type="button" class="btn btn-xs btn-link" onclick="loadPartStock()" style="padding:0 2px;color:#aaa;font-size:10px;" title="重新載入"><i class="fa fa-refresh"></i></button>
                                            </div>
                                            <div id="part-stock-content">
                                                <span style="color:#aaa;font-size:10px;">載入中...</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Row 4: 製程(1/2) | 數量(1/4) | 單價(1/4) -->
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">製程 <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control input-sm" name="Process" id="process_input" placeholder="製程">
                                    </div>
                                    <div class="col-xs-3 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">數量 <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control input-sm" name="Qty" id="qty_input" placeholder="數量" min="1">
                                        <small id="qty-warn" style="display:none;color:#e67e22;font-size:10px;line-height:1.2;"></small>
                                    </div>
                                    <div class="col-xs-3 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">單價
                                            <span id="price-lock-icon" style="display:none;" title="單價來自報價單，唯讀"><i class="fa fa-lock" style="color:#888;font-size:10px;"></i></span>
                                        </label>
                                        <input type="text" class="form-control input-sm" name="unit_price" id="unit_price_input" placeholder="單價">
                                        <small id="price-source" style="color:#27ae60;font-size:10px;"></small>
                                    </div>

                                    <!-- Row 5: 指派設計 | 設計接收日 -->
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">指派設計
                                            <?php if ($can_designer_assign_cog): ?>
                                            <button type="button" class="btn btn-xs btn-default" onclick="openDesignerSetting()" style="margin-left:3px;padding:0 3px;"><i class="fa fa-cog"></i></button>
                                            <?php endif; ?>
                                        </label>
                                        <select class="form-control input-sm" name="ate">
                                            <option value="2">無 (不經設計)</option>
                                            <?php foreach($ate_list as $ate):
                                                $dn = $ate['user_cname'];
                                                if (strpos($dn,' (') !== false) { $pts = explode(' (',$dn,2); $dn = mb_substr($pts[0],-2,2,'UTF-8').' ('.$pts[1]; }
                                                else { $dn = mb_substr($dn,-2,2,'UTF-8'); }
                                                if (!empty($ate['extra_desc'])) { $dn .= '(' . $ate['extra_desc'] . ')'; }
                                            ?>
                                            <option value="<?= $ate['id'] ?>"><?= safe_html($dn) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">設計接收日</label>
                                        <input type="date" class="form-control input-sm" name="datepicker_ate" value="<?= date('Y-m-d') ?>">
                                    </div>

                                    <!-- Row 6: 下料區域 | 容器 -->
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">下料區域</label>
                                        <input type="text" class="form-control input-sm" name="drop_zone" placeholder="下料區域" autocomplete="off">
                                    </div>
                                    <div class="col-xs-6 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">容器</label>
                                        <input type="text" class="form-control input-sm" name="Containers" placeholder="容器" autocomplete="off">
                                    </div>

                                    <!-- Row 7: 業務備註 -->
                                    <div class="col-xs-12 form-group" style="padding:0 5px;">
                                        <label class="ctrl-label">業務備註</label>
                                        <textarea class="form-control input-sm" name="Order_ps" rows="2" placeholder="業務備註"></textarea>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- ── 右側：報價單 & 出貨歷史（選定料號後才顯示） ── -->
                        <div class="col-md-5" style="padding-left:10px;border-left:1px solid #ddd;">
                            <!-- 右側 placeholder（未選料號時） -->
                            <div id="panel-quotes-placeholder" style="color:#bbb;font-size:12px;text-align:center;padding:30px 0;">
                                <i class="fa fa-cube fa-2x" style="display:block;margin-bottom:8px;opacity:.4;"></i>
                                選取料號後顯示<br>相關報價單與出貨紀錄
                            </div>

                            <!-- 右側內容（選定料號後顯示） -->
                            <div id="panel-right-content" style="display:none;">
                                <!-- 報價單區 -->
                                <div style="font-weight:700;font-size:12px;color:#2A3F54;margin-bottom:4px;">
                                    <i class="fa fa-file-text-o"></i> 相關報價單
                                    <small style="font-weight:400;color:#999;margin-left:3px;font-size:10px;">點擊帶入單價</small>
                                    <button type="button" id="btn-clear-quote" style="display:none;float:right;background:none;border:none;color:#c0392b;font-size:10px;cursor:pointer;padding:0;" onclick="clearQuoteBinding()">✕ 清除</button>
                                </div>
                                <div id="quote-list" style="max-height:170px;overflow-y:auto;margin-bottom:3px;"></div>
                                <div id="quote-bound-info" style="display:none;background:#eaf7ee;border:1px solid #c3e6cb;border-radius:4px;padding:4px 7px;font-size:10px;margin-bottom:4px;line-height:1.5;"></div>

                                <hr style="margin:7px 0;">

                                <!-- 出貨歷史區 -->
                                <div style="font-weight:700;font-size:12px;color:#2A3F54;margin-bottom:4px;">
                                    <i class="fa fa-truck"></i> 近期出貨
                                    <small style="font-weight:400;color:#999;font-size:10px;margin-left:3px;">最近15筆，僅供參考</small>
                                </div>
                                <div id="shipment-history-list" style="max-height:230px;overflow-y:auto;"></div>
                            </div>
                        </div>

                    </div>

                    <!-- 附件（新增/編輯訂單皆可用；類別共用報價單附件類別；新增中未存檔先暫存，存檔後自動歸屬） -->
                    <div id="order-attach-section" style="margin:10px 0 0;padding-top:10px;border-top:1px solid #ddd;">
                        <div style="font-weight:700;font-size:12px;color:#2A3F54;margin-bottom:6px;">
                            <i class="fa fa-paperclip"></i> 附件
                            <small style="font-weight:400;color:#999;">（可一次選多檔上傳，上傳後再點每筆設定標籤；存檔前務必全部設定完成）</small>
                            <div style="font-weight:400;font-size:11px;color:#8a5a2b;margin-top:2px;">備註：僅能刪除本人上傳的附件；他人上傳的檔案需由管理員或具附件刪除權限者處理。</div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                            <span style="font-size:11px;color:#888;">預設標籤<small style="color:#bbb;">（選填，套用到本次上傳的所有檔案）</small>：</span>
                            <div id="order-attach-cats" style="display:flex;gap:8px;flex-wrap:wrap;font-size:11px;"></div>
                            <button type="button" class="btn btn-xs btn-default" onclick="$('#order-attach-file-input').click()"><i class="fa fa-upload"></i> 上傳</button>
                            <input type="file" id="order-attach-file-input" multiple style="display:none;" onchange="orderAttachUpload(this.files)">
                        </div>
                        <div id="order-attach-list" style="font-size:11px;color:#aaa;">尚無附件</div>
                    </div>
                </div>
                <div class="modal-footer" style="padding:8px 15px;">
                    <?php if ($can_delete): ?>
                        <button type="button" id="btn-delete" class="btn btn-danger btn-sm pull-left" style="display:none;" onclick="deleteOrder()"><i class="fa fa-trash"></i> 刪除</button>
                    <?php endif; ?>
                    <button type="button" id="btn-toggle-paused" class="btn btn-warning btn-sm pull-left" style="display:none;margin-left:5px;" onclick="toggleOrderStatus('paused')"></button>
                    <button type="button" id="btn-open-split" class="btn btn-info btn-sm pull-left" style="display:none;margin-left:5px;" onclick="openSplitModal()">
                        <i class="fa fa-code-fork"></i> 拆分交期
                    </button>
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
                    <?php if ($can_create || $can_update): ?>
                        <button type="button" id="btn-save-copy" class="btn btn-success btn-sm" onclick="submitNewOrder(true)">新增並複製</button>
                        <button type="button" id="btn-save" class="btn btn-primary btn-sm" onclick="submitNewOrder(false)">確認新增</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 快速新增客戶 Mini Modal -->
    <div class="modal fade" id="quickAddCustomerModal" tabindex="-1" role="dialog" style="z-index:1060;">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background:#27ae60;color:white;padding:10px 15px;">
                    <button type="button" class="close" style="color:white;" data-dismiss="modal"><span>&times;</span></button>
                    <h5 class="modal-title"><i class="fa fa-plus-circle"></i> 新增客戶</h5>
                </div>
                <div class="modal-body" style="padding:12px;">
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="font-size:12px;">客戶代碼 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control input-sm" id="qac_id" placeholder="例：ABC001">
                    </div>
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="font-size:12px;">客戶名稱 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control input-sm" id="qac_name" placeholder="客戶全名">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="font-size:12px;">地址 <small style="color:#999;">(選填)</small></label>
                        <input type="text" class="form-control input-sm" id="qac_addr" placeholder="地址">
                    </div>
                    <div id="qac-error" style="display:none;color:#c0392b;font-size:12px;margin-top:6px;"></div>
                </div>
                <div class="modal-footer" style="padding:8px 12px;">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="submitQuickAddCustomer()"><i class="fa fa-save"></i> 建立並選取</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 快速新增料號 Mini Modal -->
    <div class="modal fade" id="quickAddPartModal" tabindex="-1" role="dialog" style="z-index:1060;">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background:#27ae60;color:white;padding:10px 15px;">
                    <button type="button" class="close" style="color:white;" data-dismiss="modal"><span>&times;</span></button>
                    <h5 class="modal-title"><i class="fa fa-plus-circle"></i> 新增料號</h5>
                </div>
                <div class="modal-body" style="padding:12px;">
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="font-size:12px;">料號 <span class="text-danger">*</span> <small style="color:#999;">（系統帶入，不可修改）</small></label>
                        <input type="text" class="form-control input-sm" id="qap_no" placeholder="料號" readonly style="background:#f5f5f5;cursor:not-allowed;">
                    </div>
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="font-size:12px;">規格</label>
                        <input type="text" class="form-control input-sm" id="qap_spec" placeholder="規格描述">
                    </div>
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="font-size:12px;">版次</label>
                        <input type="text" class="form-control input-sm" id="qap_rev" placeholder="A / B / 01...">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="font-size:12px;">關聯客戶 <small style="color:#999;">(自動帶入)</small></label>
                        <input type="text" class="form-control input-sm" id="qap_customer_name" readonly style="background:#f5f5f5;">
                        <input type="hidden" id="qap_customer_id">
                    </div>
                    <div id="qap-error" style="display:none;color:#c0392b;font-size:12px;margin-top:6px;"></div>
                </div>
                <div class="modal-footer" style="padding:8px 12px;">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="submitQuickAddPart()"><i class="fa fa-save"></i> 建立並選取</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Designer Setting Modal -->
    <div class="modal fade" id="designerSettingModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">設定設計名單</h4>
                </div>
                <div class="modal-body">
                    <form id="designerSettingForm">
                        <div class="panel panel-primary">
                            <div class="panel-heading">主要設計部門</div>
                            <div class="panel-body">
                                <div class="form-group"><label>選擇部門</label><select class="form-control" id="design_dept_select" onchange="loadDeptUsers(this.value, 'design_users_container')"></select></div>
                                <div class="form-group"><label>選擇人員</label><div id="design_users_container" style="max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 5px;"></div></div>
                            </div>
                        </div>
                        <div class="panel panel-info">
                            <div class="panel-heading">額外支援部門 1</div>
                            <div class="panel-body">
                                <div class="form-group"><label>選擇部門</label><select class="form-control" id="extra1_dept_select" onchange="loadDeptUsers(this.value, 'extra1_users_container', true)"></select></div>
                                <div class="form-group"><label>選擇人員 (需填寫說明)</label><div id="extra1_users_container" style="max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 5px;"></div></div>
                            </div>
                        </div>
                        <div class="panel panel-info">
                            <div class="panel-heading">額外支援部門 2</div>
                            <div class="panel-body">
                                <div class="form-group"><label>選擇部門</label><select class="form-control" id="extra2_dept_select" onchange="loadDeptUsers(this.value, 'extra2_users_container', true)"></select></div>
                                <div class="form-group"><label>選擇人員 (需填寫說明)</label><div id="extra2_users_container" style="max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 5px;"></div></div>
                            </div>
                        </div>

                        <!-- 指定特定設計(技術)＝訂單存檔即自動轉生管 -->
                        <div class="panel" style="border-color:#E4D3BC;">
                            <div class="panel-heading" style="background:#FFF3E2;color:#8a5a2b;border-color:#E4D3BC;font-weight:600;">
                                <i class="fa fa-magic"></i> 指定這些設計對象時，存檔自動轉生管
                            </div>
                            <div class="panel-body">
                                <div style="font-size:11px;color:#8a5a2b;background:#FFF9F0;border:1px solid #F0E2CC;border-radius:3px;padding:6px 8px;margin-bottom:8px;line-height:1.6;">
                                    勾選的對象一旦被指派為「指派設計」，訂單一存檔就直接變成
                                    <b>已轉生管</b>的狀態（不必再按「審圖」「轉生管」）。<br>
                                    ・轉生管日取該筆訂單的<b>設計接收日</b>，沒填才用存檔當天。<br>
                                    ・之後把指派設計改成<b>沒有勾選</b>的對象，系統會自動把它蓋上的轉生管日退回「未按任何按鈕」的狀態。<br>
                                    ・<b>人工按過「轉生管」鈕的日期不會被動到</b>，也不會被自動覆蓋或清除。<br>
                                    ・若手動按 X 取消了自動蓋上的日期，下次這張訂單存檔時會依設定再蓋回來（要停掉請改指派設計或取消勾選）。<br>
                                    ・不勾任何一個＝關閉本功能（原本自動蓋上的會在下次存檔或按下方按鈕時退回）。
                                </div>
                                <div id="auto_pmget_container" style="max-height:170px;overflow-y:auto;border:1px solid #eee;padding:5px;">
                                    <div class="checkbox" style="margin:3px 0;"><label style="font-weight:normal;">
                                        <input type="checkbox" class="auto-pmget-check" value="2"> 無 (不經設計)
                                    </label></div>
<?php foreach($ate_list as $ate):
    $dn = $ate['user_cname'];
    if (!empty($ate['extra_desc'])) { $dn .= '（' . $ate['extra_desc'] . '）'; }
?>
                                    <div class="checkbox" style="margin:3px 0;"><label style="font-weight:normal;">
                                        <input type="checkbox" class="auto-pmget-check" value="<?= intval($ate['id']) ?>"> <?= safe_html($dn) ?>
                                    </label></div>
<?php endforeach; ?>
                                </div>
                                <div style="margin-top:8px;">
                                    <button type="button" class="btn btn-xs btn-default" onclick="runAutoPmgetBackfill()">
                                        <i class="fa fa-refresh"></i> 存設定並套用到既有訂單
                                    </button>
                                    <span style="font-size:11px;color:#999;margin-left:4px;">
                                        會先試算影響筆數再請你確認；只處理<b>未結案</b>的訂單。
                                    </span>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveDesignerConfig()">儲存設定</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 拆批管理 Modal -->
    <div class="modal fade" id="splitModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" style="width:650px;max-width:95%;" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background:#2A3F54;color:white;border-radius:8px 8px 0 0;">
                    <button type="button" class="close" style="color:white;" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-code-fork"></i> 拆分交期批次</h4>
                </div>
                <div class="modal-body" style="background:#f7f7f7;padding:14px;">
                    <!-- 主訂單資訊 -->
                    <div style="background:#fff;border-radius:6px;padding:10px 14px;margin-bottom:10px;border-left:4px solid #1ABB9C;font-size:12px;">
                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <span><strong>訂單編號：</strong><span id="split-parent-oo"></span></span>
                            <span><strong>料號：</strong><span id="split-parent-did"></span></span>
                            <span><strong>總數量：</strong><strong id="split-parent-qty" style="color:#2A3F54;font-size:14px;"></strong> 件</span>
                            <span><strong>已拆數量：</strong><span id="split-used-qty" style="color:#E67E22;"></span> 件</span>
                            <span><strong>剩餘可拆：</strong><span id="split-remaining-qty" style="color:#1ABB9C;font-weight:600;"></span> 件</span>
                        </div>
                    </div>

                    <!-- 現有子批次列表 -->
                    <div style="margin-bottom:10px;">
                        <div style="font-weight:600;font-size:12px;color:#2A3F54;margin-bottom:6px;">
                            <i class="fa fa-list"></i> 已拆批次
                            <button type="button" class="btn btn-xs btn-danger pull-right" id="btn-delete-all-splits" onclick="deleteAllSplits()" style="display:none;">
                                <i class="fa fa-trash"></i> 撤銷全部拆批
                            </button>
                        </div>
                        <div id="split-list-container" style="max-height:220px;overflow-y:auto;">
                            <div style="color:#bbb;font-size:11px;text-align:center;padding:12px;">尚無子批次</div>
                        </div>
                    </div>

                    <!-- 新增/編輯子批次表單 -->
                    <div style="background:#fff;border-radius:6px;padding:10px 14px;border:1px solid #e0e0e0;">
                        <div style="font-weight:600;font-size:12px;color:#2A3F54;margin-bottom:8px;">
                            <i class="fa fa-plus-circle" style="color:#1ABB9C;"></i>
                            <span id="split-form-title">新增子批次</span>
                        </div>
                        <input type="hidden" id="split-editing-id">
                        <div class="row" style="margin:0 -5px;">
                            <div class="col-xs-4 form-group" style="padding:0 5px;">
                                <label style="font-size:11px;">交期 <span class="text-danger">*</span></label>
                                <input type="date" class="form-control input-sm" id="split-input-date">
                            </div>
                            <div class="col-xs-3 form-group" style="padding:0 5px;">
                                <label style="font-size:11px;">數量 <span class="text-danger">*</span></label>
                                <input type="number" class="form-control input-sm" id="split-input-qty" min="1" placeholder="數量">
                                <small id="split-qty-warn" style="display:none;color:#e67e22;font-size:10px;"></small>
                            </div>
                            <div class="col-xs-5 form-group" style="padding:0 5px;">
                                <label style="font-size:11px;">備註</label>
                                <input type="text" class="form-control input-sm" id="split-input-ps" placeholder="此批次備註（選填）" maxlength="150">
                            </div>
                        </div>
                        <div style="text-align:right;margin-top:4px;">
                            <button type="button" class="btn btn-default btn-sm" id="btn-split-cancel-edit" style="display:none;" onclick="cancelSplitEdit()">取消編輯</button>
                            <button type="button" class="btn btn-success btn-sm" id="btn-split-submit" onclick="submitSplit()">
                                <i class="fa fa-save"></i> <span id="split-submit-label">新增批次</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding:8px 15px;">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>

    <!-- NOTE: Per user request, modals are embedded here instead of separate popup files to adhere to the "only modify this file" constraint. -->

    <!-- Customer Settings Modal -->
    <div class="modal fade" id="customerSettingsModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">客戶資料設定</h4>
                </div>
                <div class="modal-body">
                    <form id="customerForm" class="form-horizontal">
                        <input type="hidden" name="customer_id_modal" id="customer_id_modal">
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label>客戶代碼 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="customer_id_new" id="customer_id_new" placeholder="新增時必填">
                            </div>
                            <div class="col-md-8 form-group">
                                <label>客戶名稱 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="customer_name_modal" id="customer_name_modal">
                            </div>
                            <div class="col-md-12 form-group">
                                <label>地址</label>
                                <input type="text" class="form-control" name="customer_address_modal" id="customer_address_modal">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>電話</label>
                                <input type="text" class="form-control" name="customer_tel_modal" id="customer_tel_modal">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>傳真</label>
                                <input type="text" class="form-control" name="customer_fax_modal" id="customer_fax_modal">
                            </div>
                        </div>
                        <div id="customer-modal-error" style="display:none;color:#c0392b;font-size:12px;margin-bottom:8px;"></div>
                        <button type="button" class="btn btn-primary" onclick="saveCustomer()"><i class="fa fa-save"></i> 儲存</button>
                        <button type="button" class="btn btn-default" onclick="resetCustomerForm()">清除/新增</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Part Settings Modal -->
    <div class="modal fade" id="partSettingsModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">料號資料設定</h4>
                </div>
                <div class="modal-body">
                    <form id="partForm">
                        <input type="hidden" id="d_id_modal_pk">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>料號 (Part No) <span class="text-danger">*</span> <small class="text-muted">（系統帶入，不可修改）</small></label>
                                <input type="text" class="form-control" id="part_modal_no" placeholder="料號" readonly style="background:#f5f5f5;cursor:not-allowed;">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>工件種類</label>
                                <select class="form-control" id="part_modal_type" onchange="toggleGearSection(this.value)">
                                    <option value="N">一般 (General)</option>
                                    <option value="G">齒輪 (Gear)</option>
                                    <option value="H">滾刀 (Hob)</option>
                                </select>
                            </div>
                            <div class="col-md-12 form-group" style="position:relative;">
                                <label>客戶 <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="part_modal_customer_name" placeholder="輸入代碼或名稱搜尋..." autocomplete="off">
                                    <input type="hidden" id="part_modal_customer_id">
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn-default" id="part-modal-customer-search-btn" onclick="searchPartModalCustomer()"><i class="fa fa-search"></i></button>
                                    </span>
                                </div>
                                <div id="part-modal-customer-suggestions" class="autocomplete-suggestions" style="top:34px;left:0;right:0;z-index:9999;"></div>
                                <!-- ※ 新增：客戶未綁定錯誤提示 -->
                                <div id="part-modal-customer-error" class="text-danger" style="display:none;font-size:12px;margin-top:3px;"></div>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>版次 (Revision)</label>
                                <input type="text" class="form-control" id="part_modal_revision" placeholder="例如 1.0">
                            </div>
                            <div class="col-md-4 form-group">
                                <label>發行日期</label>
                                <input type="date" class="form-control" id="part_modal_issue_date">
                            </div>
                            <div class="col-md-12 form-group">
                                <label>備註</label>
                                <textarea class="form-control" id="part_modal_remark" rows="2"></textarea>
                            </div>
                        </div>

                        <!-- 齒輪詳細資料（工件種類=齒輪時顯示） -->
                        <div id="gear-detail-section" style="display:none;margin-top:4px;">
                            <div style="background:#f0f4f8;border-radius:6px;padding:7px 12px;margin-bottom:7px;display:flex;align-items:center;justify-content:space-between;">
                                <strong style="font-size:13px;color:#2A3F54;"><i class="fa fa-cog"></i> 齒輪詳細資料</strong>
                                <button type="button" class="btn btn-xs btn-success" onclick="addGearRow()"><i class="fa fa-plus"></i> 新增齒輪組</button>
                            </div>
                            <div id="gear-rows-container"></div>
                        </div>

                        <div style="margin-top:10px;">
                            <!-- ※ 新增：重複建檔明顯警告 -->
                            <div id="part-modal-dup-msg" class="alert alert-danger" style="display:none;font-size:13px;margin-bottom:8px;">
                                <i class="fa fa-exclamation-triangle"></i> <strong>重複建檔警告：</strong> <span></span>
                            </div>
                            <button type="button" class="btn btn-primary" onclick="savePartModal()"><i class="fa fa-save"></i> 儲存</button>
                            <button type="button" class="btn btn-default" onclick="resetPartModal()">清除/新增</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Quotation Select Modal -->
    <div class="modal fade" id="quotationSelectModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document" style="width: 80%;">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">從報價單選取項目</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <input type="text" id="quotation-search-input" class="form-control" placeholder="搜尋報價單號、客戶、料號...">
                    </div>
                    <div style="max-height: 500px; overflow-y: auto;">
                        <table id="quotationListTable" class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th>操作</th>
                                    <th>報價單號</th>
                                    <th>報價日期</th>
                                    <th>客戶</th>
                                    <th>料號</th>
                                    <th>規格</th>
                                    <th>數量</th>
                                    <th>單價</th>
                                    <th>製程</th>
                                    <th>備註</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- AJAX content here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 快速綁定 Modal -->
    <div class="modal fade" id="quickBindModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document" style="max-width:700px;">
            <div class="modal-content">
                <div class="modal-header" style="background:#2A3F54; color:white; border-radius:8px 8px 0 0;">
                    <button type="button" class="close" style="color:white; opacity:0.8;" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-link"></i> 快速綁定</h4>
                </div>
                <div class="modal-body" style="padding:12px;">
                    <input type="hidden" id="qb_order_id">
                    <div id="qb-loading" class="text-center" style="padding:20px;"><i class="fa fa-spinner fa-spin fa-2x"></i><p>查詢中...</p></div>
                    <div id="qb-content" style="display:none;">
                        <div class="row" style="margin:0;">
                            <!-- 左欄：客戶 + 料號 -->
                            <div class="col-md-6" style="padding-right:8px; border-right:1px solid #eee;">
                                <!-- 客戶綁定 -->
                                <div style="font-weight:700;font-size:12px;color:#2A3F54;margin-bottom:4px;">
                                    <i class="fa fa-user"></i> 客戶綁定
                                    <span id="qb-client-current" style="font-size:11px;color:#888;margin-left:6px;"></span>
                                </div>
                                <!-- 手動搜尋客戶（僅自動搜尋找不到時顯示） -->
                                <div id="qb-client-search-area" style="display:none;margin-bottom:6px;">
                                    <div class="input-group input-group-xs">
                                        <input type="text" id="qb-client-search-input" class="form-control" placeholder="搜尋客戶名稱或ID..." style="font-size:11px;" onkeydown="if(event.key==='Enter'){qbSearchCustomer();}">
                                        <span class="input-group-btn">
                                            <button type="button" class="btn btn-default" onclick="qbSearchCustomer()" style="font-size:11px;padding:2px 7px;" title="搜尋"><i class="fa fa-search"></i></button>
                                        </span>
                                    </div>
                                </div>
                                <div id="qb-client-list" style="margin-bottom:6px;"></div>
                                <!-- 找不到客戶時顯示快速新建按鈕 -->
                                <div id="qb-client-new-area" style="display:none;margin-top:4px;margin-bottom:10px;">
                                    <button type="button" class="btn btn-success btn-xs" onclick="openCustomerSettingsFromQb()">
                                        <i class="fa fa-plus"></i> 新建客戶
                                    </button>
                                    <small style="color:#888;margin-left:4px;">找不到符合客戶？點此新建</small>
                                </div>
                                <!-- 料號綁定 -->
                                <div style="font-weight:700;font-size:12px;color:#2A3F54;margin-bottom:4px;">
                                    <i class="fa fa-cube"></i> 料號綁定
                                    <span id="qb-part-current" style="font-size:11px;color:#888;margin-left:6px;"></span>
                                </div>
                                <div id="qb-part-list" style="margin-bottom:6px;"></div>
                                <!-- 找不到料號時顯示快速新建按鈕 -->
                                <div id="qb-part-new-area" style="display:none;margin-top:6px;">
                                    <button type="button" class="btn btn-success btn-xs" onclick="openPartSettingsFromQb()">
                                        <i class="fa fa-plus"></i> 新建料號
                                    </button>
                                    <small style="color:#888;margin-left:4px;">找不到符合料號？點此新建</small>
                                </div>
                                <!-- 找到料號但屬其他客戶時，提供新建此客戶版本 -->
                                <div id="qb-part-new-for-customer-area" style="display:none;margin-top:6px;padding:5px 7px;background:#fff8e6;border:1px solid #f0c040;border-radius:4px;">
                                    <button type="button" class="btn btn-warning btn-xs" onclick="openPartSettingsFromQb()">
                                        <i class="fa fa-plus"></i> 為此客戶新建料號
                                    </button>
                                    <small style="color:#888;margin-left:4px;">現有料號屬其他客戶，點此新建對應版本</small>
                                </div>
                            </div>
                            <!-- 右欄：報價單綁定 -->
                            <div class="col-md-6" style="padding-left:10px;">
                                <div style="font-weight:700;font-size:12px;color:#2A3F54;margin-bottom:4px;">
                                    <i class="fa fa-file-text-o"></i> 報價單綁定
                                    <small style="font-weight:400;color:#999;font-size:10px;margin-left:4px;">點選帶入單價</small>
                                </div>
                                <div id="qb-quote-loading" class="text-center" style="padding:8px;display:none;"><i class="fa fa-spinner fa-spin"></i></div>
                                <div id="qb-quote-list" style="max-height:220px;overflow-y:auto;"></div>
                                <div id="qb-quote-bound-info" style="display:none;background:#eaf7ee;border:1px solid #c3e6cb;border-radius:4px;padding:4px 7px;font-size:11px;margin-top:4px;"></div>
                                <small style="color:#bbb;font-size:10px;">※ 目前報價單資料尚未完整建立，此功能待報價單匯入後使用</small>
                            </div>
                        </div>
                        <div id="qb-selected-summary" style="background:#f0f4f8;border-radius:5px;padding:7px 12px;font-size:12px;margin-top:10px;display:none;border-left:3px solid #2A3F54;">
                            <strong>將綁定：</strong> <span id="qb-summary-text"></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding:8px 12px;">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
                    <button type="button" id="qb-save-btn" class="btn btn-primary btn-sm" style="display:none;" onclick="saveQuickBind()"><i class="fa fa-save"></i> 確認綁定</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 組合件展開子件訂單 Modal -->
    <div class="modal fade" id="assemblyExpandModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header" style="padding:10px 15px;">
                    <h4 class="modal-title"><i class="fa fa-sitemap"></i> 是否自動展開子件訂單？</h4>
                </div>
                <div class="modal-body" style="padding:12px 15px;">
                    <div id="asm-expand-parent" style="background:#eef5fb;border-left:3px solid #3498db;padding:8px 12px;border-radius:4px;margin-bottom:10px;"></div>
                    <table class="table table-bordered table-condensed" style="margin-bottom:6px;font-size:12px;">
                        <thead>
                            <tr style="background:#f5f7fa;">
                                <th>子件料號</th><th>規格</th>
                                <th style="text-align:right;">每組用量</th>
                                <th style="text-align:right;">展開數量</th>
                            </tr>
                        </thead>
                        <tbody id="asm-expand-children"></tbody>
                    </table>
                    <div style="font-size:11px;color:#888;">※ 子件訂單沿用同一訂單編號、接單日與交期；數量＝訂單數量 × 每組用量（無條件進位）；製程帶入「全製」，並標記來源為本組合件訂單。</div>
                    <div id="asm-expand-error" class="alert alert-danger" style="display:none;margin:8px 0 0;padding:6px 10px;font-size:12px;"></div>
                </div>
                <div class="modal-footer" style="padding:8px 12px;">
                    <button type="button" class="btn btn-default btn-sm" id="asm-expand-skip">不展開</button>
                    <button type="button" class="btn btn-primary btn-sm" id="asm-expand-confirm"><i class="fa fa-sitemap"></i> 展開建立子件訂單</button>
                </div>
            </div>
        </div>
    </div>

    <!-- OP轉訂單 Modal -->
    <select id="op-ate-options-template" style="display:none;">
        <option value="2">無 (不經設計)</option>
        <?php foreach($ate_list as $ate):
            $dn = $ate['user_cname'];
            if (strpos($dn,' (') !== false) { $pts = explode(' (',$dn,2); $dn = mb_substr($pts[0],-2,2,'UTF-8').' ('.$pts[1]; }
            else { $dn = mb_substr($dn,-2,2,'UTF-8'); }
            if (!empty($ate['extra_desc'])) { $dn .= '(' . $ate['extra_desc'] . ')'; }
        ?>
        <option value="<?= $ate['id'] ?>"><?= safe_html($dn) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="modal fade" id="opConvertModal" tabindex="-1" role="dialog" data-backdrop="static">
        <div class="modal-dialog" role="document" style="width:960px;">
            <div class="modal-content">
                <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:8px 8px 0 0;padding:15px 20px;">
                    <button type="button" class="close" style="color:#fff;opacity:0.8;" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-exchange"></i> OP轉訂單</h4>
                </div>
                <div class="modal-body" style="background:#f7f7f7;padding:14px;max-height:74vh;overflow-y:auto;">

                    <!-- 畫面一：搜尋OP單 -->
                    <div id="op-step-search" style="background:#fff;border-radius:6px;padding:12px 14px;border:1px solid #e0e0e0;">
                        <ul class="nav nav-tabs" style="margin-bottom:12px;">
                            <li class="active" id="op-tab-no-li"><a href="#" onclick="opSwitchSearchTab('no');return false;">OP單號搜尋</a></li>
                            <li id="op-tab-part-li"><a href="#" onclick="opSwitchSearchTab('part');return false;">料號搜尋</a></li>
                        </ul>

                        <div id="op-search-by-no">
                            <div class="input-group input-group-sm" style="max-width:360px;">
                                <input type="text" class="form-control" id="op-search-no-input" placeholder="輸入OP單號關鍵字，按Enter搜尋">
                                <span class="input-group-btn"><button type="button" class="btn btn-primary" onclick="opSearchByNo()"><i class="fa fa-search"></i></button></span>
                            </div>
                            <div id="op-search-no-result" style="margin-top:12px;"></div>
                        </div>

                        <div id="op-search-by-part" style="display:none;">
                            <div class="input-group input-group-sm" style="max-width:360px;">
                                <input type="text" class="form-control" id="op-search-part-input" placeholder="輸入料號／客戶代號／等同料號，按Enter搜尋">
                                <span class="input-group-btn"><button type="button" class="btn btn-primary" onclick="opSearchByPart()"><i class="fa fa-search"></i></button></span>
                            </div>
                            <div id="op-search-part-result" style="margin-top:12px;max-height:420px;overflow-y:auto;"></div>
                        </div>
                    </div>

                    <!-- 畫面二：OP單料號勾選＋批次設定 -->
                    <div id="op-step-items" style="display:none;">
                        <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;border-radius:6px;padding:9px 14px;margin-bottom:10px;border-left:4px solid #1ABB9C;">
                            <span id="op-items-header" style="font-weight:600;font-size:13px;color:#2A3F54;"></span>
                            <button type="button" class="btn btn-xs btn-default" onclick="opBackToSearch()"><i class="fa fa-arrow-left"></i> 返回搜尋</button>
                        </div>

                        <div style="background:#fff;border-radius:6px;border:1px solid #e0e0e0;overflow:hidden;">
                            <div style="max-height:320px;overflow-y:auto;">
                                <table class="table table-striped table-hover table-bordered" style="font-size:11px;margin-bottom:0;">
                                    <thead>
                                        <tr style="background:#f5f7fa;">
                                            <th style="width:26px;"><input type="checkbox" id="op-check-all" onclick="opToggleAll(this)"></th>
                                            <th>料號</th>
                                            <th>客戶</th>
                                            <th>製程</th>
                                            <th>料號備註</th>
                                            <th style="text-align:right;">數量</th>
                                            <th style="text-align:right;">單價</th>
                                            <th style="min-width:110px;">交期</th>
                                            <th style="min-width:120px;">業務備註</th>
                                            <th style="min-width:110px;">指派設計</th>
                                            <th style="min-width:110px;">設計接收日</th>
                                            <th style="min-width:150px;">訂單編號</th>
                                        </tr>
                                    </thead>
                                    <tbody id="op-items-tbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div style="background:#fff;border-radius:6px;padding:10px 14px;border:1px solid #e0e0e0;margin-top:10px;">
                            <div style="font-weight:600;font-size:12px;color:#2A3F54;margin-bottom:8px;">
                                <i class="fa fa-magic" style="color:#1ABB9C;"></i> 批次套用
                                <small style="font-weight:400;color:#999;">（套用到目前已勾選的列，套用後仍可個別列手動修改）</small>
                            </div>
                            <div class="row" style="margin:0 -5px;">
                                <div class="col-xs-3" style="padding:0 5px;">
                                    <label style="font-size:11px;">交期</label>
                                    <div class="input-group input-group-sm">
                                        <input type="date" class="form-control" id="op-batch-delivery">
                                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="opApplyBatch('delivery')">套用</button></span>
                                    </div>
                                </div>
                                <div class="col-xs-3" style="padding:0 5px;">
                                    <label style="font-size:11px;">業務備註</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" id="op-batch-ps" placeholder="業務備註">
                                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="opApplyBatch('ps')">套用</button></span>
                                    </div>
                                </div>
                                <div class="col-xs-3" style="padding:0 5px;">
                                    <label style="font-size:11px;">指派設計</label>
                                    <div class="input-group input-group-sm">
                                        <select class="form-control" id="op-batch-ate"></select>
                                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="opApplyBatch('ate')">套用</button></span>
                                    </div>
                                </div>
                                <div class="col-xs-3" style="padding:0 5px;">
                                    <label style="font-size:11px;">設計接收日</label>
                                    <div class="input-group input-group-sm">
                                        <input type="date" class="form-control" id="op-batch-ateget">
                                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="opApplyBatch('ateget')">套用</button></span>
                                    </div>
                                </div>
                                <div class="col-xs-3" style="padding:0 5px;">
                                    <label style="font-size:11px;">訂單編號</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" id="op-batch-orderno" placeholder="OO+民國年月日+流水號">
                                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="opApplyBatch('orderno')">套用</button></span>
                                    </div>
                                </div>
                                <div class="col-xs-3" style="padding:0 5px;">
                                    <label style="font-size:11px;">階梯對價區間<small style="color:#999;">（僅階梯報價列）</small></label>
                                    <div class="input-group input-group-sm">
                                        <select class="form-control" id="op-batch-tolmatch">
                                            <option value="0">容差前（報價區間）</option>
                                            <option value="1">容差後區間</option>
                                        </select>
                                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="opApplyBatch('tolmatch')">套用</button></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 附件（整批共用一個暫存批次；批次內只有一種料號時免選、多種料號才要選對應料號或「共用」）-->
                        <div style="background:#fff;border-radius:6px;padding:10px 14px;border:1px solid #e0e0e0;margin-top:10px;">
                            <div style="font-weight:600;font-size:12px;color:#2A3F54;margin-bottom:8px;">
                                <i class="fa fa-paperclip" style="color:#1ABB9C;"></i> 附件
                                <small style="font-weight:400;color:#999;">（可一次選多檔上傳，建單後自動歸屬到對應的新訂單；建單前務必逐一設定標籤）</small>
                                <div style="font-weight:400;font-size:11px;color:#8a5a2b;margin-top:2px;">備註：僅能刪除本人上傳的附件；他人上傳的檔案需由管理員或具附件刪除權限者處理。</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                                <span style="font-size:11px;color:#888;">預設標籤<small style="color:#bbb;">（選填，套用到本次上傳的所有檔案）</small>：</span>
                                <div id="op-attach-cats" style="display:flex;gap:8px;flex-wrap:wrap;font-size:11px;"></div>
                                <span id="op-attach-part-wrap" style="display:none;align-items:center;gap:4px;">
                                    <span style="font-size:11px;color:#888;">對應料號：</span>
                                    <select id="op-attach-part" class="form-control input-sm" style="width:auto;display:inline-block;"></select>
                                </span>
                                <button type="button" class="btn btn-xs btn-default" onclick="$('#op-attach-file-input').click()"><i class="fa fa-upload"></i> 上傳</button>
                                <input type="file" id="op-attach-file-input" multiple style="display:none;" onchange="opAttachUpload(this.files)">
                            </div>
                            <div id="op-attach-list" style="font-size:11px;color:#aaa;">尚無附件</div>
                        </div>

                        <div id="op-create-error" class="alert alert-danger" style="display:none;margin:10px 0 0;padding:6px 10px;font-size:12px;"></div>
                    </div>

                </div>
                <div class="modal-footer" style="padding:8px 15px;" id="op-modal-footer-items">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary btn-sm" id="op-btn-create" onclick="opCreateOrders()"><i class="fa fa-check"></i> 確認建立訂單</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Shared Dynamic Modal -->
    <div class="modal fade" id="sharedDynamicModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title" id="sharedModalTitle">Loading...</h4>
                </div>
                <div class="modal-body" id="sharedModalBody">
                    <div class="text-center" style="padding: 20px;"><i class="fa fa-spinner fa-spin fa-3x"></i><p style="margin-top: 10px;">載入中...</p></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Back to Top FAB -->
    <div id="backToTop" class="fab" onclick="scrollToTop()">
        <i class="fa fa-arrow-up"></i>
    </div>

    <!-- Scripts -->
    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/jquery.dataTables.min.js"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js"></script>
    <script src="../../resource/js/dataTables.fixedHeader.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    <script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>

    <script>
        // ── 阻止 custom.min.js 的 init_DataTables 初始化 #orderTable ────────
        $(document).ready(function() {
            // 銷毀 custom.min.js 可能已建立的 DataTable，之後完全不再重建
            if ($.fn.DataTable && $.fn.DataTable.isDataTable('#orderTable')) {
                try { $('#orderTable').DataTable().destroy(); } catch(e) {}
            }

            // ── 日期欄位：輸入年份4碼後自動跳月份 ──────────
            (function initDateAutoAdvance(){
                    // 當日期欄位獲得焦點、失去焦點或被點擊時，重置年份緩衝區
                $(document).on('focus blur click', 'input[type="date"]', function(){
                    $(this).data('y-buf', '');
                });

                $(document).on('keydown', 'input[type="date"]', function(e){
                    var el = this;
                        // 僅偵測並累計 0-9 的數字輸入
                    if (/^[0-9]$/.test(e.key)) {
                        var buf = ($(el).data('y-buf') || '') + e.key;
                        if (buf.length >= 4) {
                                // 滿 4 碼（年份完成）後立即重置緩衝並準備跳轉
                            $(el).data('y-buf', '');
                            setTimeout(function(){
                                    // 關鍵：使用 KeyboardEvent 模擬 Tab 鍵跳轉
                                    // 補齊 keyCode 與 which (9) 以觸發日期元件內部的欄位切換邏輯
                                    var eTab = new KeyboardEvent('keydown', {
                                    key: 'Tab',
                                    code: 'Tab',
                                        keyCode: 9,   // 關鍵：Legacy 屬性
                                        which: 9,     // 關鍵：Legacy 屬性
                                    bubbles: true,
                                    cancelable: true
                                });
                                    el.dispatchEvent(eTab);
                                }, 30); // 關鍵延遲：30ms 給予瀏覽器足夠時間處理最後一碼輸入
                        } else {
                            $(el).data('y-buf', buf);
                        }
                    } else if (e.key === 'Backspace') {
                            // 支援倒退鍵，同步修正緩衝字串長度
                        $(el).data('y-buf', ($(el).data('y-buf') || '').slice(0, -1));
                    } else if (e.key !== 'Tab' && !e.key.startsWith('Arrow')) {
                            // 按下其他非功能鍵時清空緩衝，避免誤判
                        $(el).data('y-buf', '');
                    }
                });
            })();

            // 移除 table class 讓 custom.min.js 找不到，防止重複初始化
            $('#orderTable').removeClass('table').addClass('table');
        });
        // 權限變數
        window.canCreate = <?= json_encode($can_create) ?>;
        window.canUpdate = <?= json_encode($can_update) ?>;
        window.canDelete = <?= json_encode($can_delete) ?>;
        window.canUpdatePmget = <?= json_encode($can_to_pm) ?>; // 轉生管操作權限（ot_to_pm）
        window.designerList = <?= json_encode($ate_list) ?>; // 傳遞設計師列表給 JS
        
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return $('<div>').text(text).html();
        }

        // 計算欄位偏移量：如果沒有操作欄位 (showOpCol 為 false)，則後續欄位索引需 -1
        var showOpCol = (window.canCreate || window.canUpdate);
        var colOffset = showOpCol ? 0 : -1;

        // =====================================================================
        // 核心狀態變數
        // =====================================================================
        var currentPage = 1;
        var currentFilters = { status: 'all', unbound: false, unbound_op: false, qty_over: false };
        var isStatCardFilter = false;
        var lockedStats = null;

        // =====================================================================
        // 主要 AJAX 載入函數
        // =====================================================================
        function fetchTableData(page) {
            page = page || 1;
            currentPage = page;

            $('#orderTable tbody').html('<tr><td colspan="20" class="text-center"><i class="fa fa-spinner fa-spin fa-2x"></i> 載入中...</td></tr>');

            var globalVal = $('#filter-global').val().trim();
            var yearVal;
            if (globalVal !== '') {
                yearVal = 'ALL';
                $('#year-select').val('ALL');
                if ($('#global-search-notice').length === 0) {
                    $('.stats-container').before('<div id="global-search-notice" style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:6px 12px;margin-bottom:8px;font-size:12px;color:#856404;"><i class="fa fa-info-circle"></i> 全域搜尋模式：已自動切換為全部年份</div>');
                }
            } else {
                yearVal = $('#year-select').val() || 'ALL';
                $('#global-search-notice').remove();
            }

            var designerVal = $('#filter-designer').val(); // 完整 value（可能含括號說明）
            // 後端 LIKE 比對的是 user.user_cname（DB 原始名，不含括號）
            // 所以傳出去前截取括號前的部分
            var designerSearch = designerVal;
            if (designerVal && designerVal !== '__none__') {
                var parenIdx = designerVal.indexOf(' (');
                if (parenIdx !== -1) designerSearch = designerVal.substring(0, parenIdx).trim();
            }

            $.post('', {
                action:            'load_page_data',
                page:              currentPage,
                year:              yearVal,
                designer:          designerSearch,
                client:            $('#filter-client').val(),
                part:              $('#filter-part').val(),
                global:            globalVal,
                status:            currentFilters.status,
                order_status_filter: currentStatusCardFilter || '',
                unbound:           currentFilters.unbound    ? 1 : 0,
                unbound_op:        currentFilters.unbound_op ? 1 : 0,
                qty_over:          currentFilters.qty_over   ? 1 : 0
            }, function(res) {
                if (!res.success) {
                    $('#orderTable tbody').html('<tr><td colspan="20" class="text-center text-danger">載入失敗: ' + (res.message || '未知錯誤') + '</td></tr>');
                    return;
                }

                // ── 統計數字更新 ──────────────────────────────────────────
                if (isStatCardFilter) {
                    if (!lockedStats) {
                        // 鎖定統計：帶入設計師/客戶/料號篩選，但 status='all'，這樣卡片顯示該設計師各狀態真實計數
                        $.post('', {
                            action:'load_page_data', page:1, year:yearVal,
                            designer: designerSearch,
                            client:   $('#filter-client').val(),
                            part:     $('#filter-part').val(),
                            global:   globalVal,
                            status:   'all',
                            order_status_filter: currentStatusCardFilter || '',
                            unbound:  currentFilters.unbound    ? 1 : 0,
                            unbound_op: currentFilters.unbound_op ? 1 : 0,
                            qty_over:   currentFilters.qty_over   ? 1 : 0
                        }, function(r2) {
                            if (r2.success) {
                                lockedStats = r2.stats;
                                applyStats(lockedStats);
                            }
                        }, 'json');
                    } else {
                        applyStats(lockedStats);
                    }
                } else {
                    lockedStats = null;
                    applyStats(res.stats);
                }

                // ── 替換表格內容 ──────────────────────────────────────────
                $('#orderTable tbody').html(res.html);
                $('#pagination-container').html(res.pagination);
                $('textarea.table-textarea').each(function() { autoResize(this); });
                if (typeof ocDecorateOrderBadges === 'function') ocDecorateOrderBadges();

            }, 'json').fail(function(xhr) {
                $('#orderTable tbody').html('<tr><td colspan="20" class="text-center text-danger" style="font-weight:bold;">載入失敗！請按 F12 查看 Console。</td></tr>');
                console.error('AJAX 錯誤：', xhr.responseText);
            });
        }

        function applyStats(s) {
            if (!s) return;
            // 全部訂單數 = 當前篩選總數（含 order_status_filter）
            $('#count-all').text(s.total_records || 0);
            // unbound_op 跟著當前篩選
            if (s.unbound_op !== undefined) $('#count-unbound-op').text(s.unbound_op || 0);
            // 數量超出區間（OP轉訂單超出階梯區間）子連結：>0 才顯示
            if (s.qty_over !== undefined) {
                $('#count-qty-over').text(s.qty_over || 0);
                $('#count-qty-over-note').toggle((parseInt(s.qty_over) || 0) > 0 || currentFilters.qty_over);
            }
            // 三個半高卡片始終顯示全域數字（後端獨立計算，只跟年份走）
            if (s.paused !== undefined) $('#count-paused').text(s.paused || 0);
            if (s.closed !== undefined) $('#count-closed').text(s.closed || 0);
            if (s.unfinished !== undefined) $('#count-unfinished').text(s.unfinished || 0);
            // processing/done/communication 後端已依 order_status_filter 處理好
            $('#count-processing').text(s.processing || 0);
            $('#count-done').text(s.done || 0);
            $('#count-communication').text(s.communication || 0);
            // 全部訂單卡片標題 & 暫停備註
            if (currentStatusCardFilter === 'unfinished') {
                $('.stat-card.card-all .stat-label').text('全部未結案訂單');
                $('#count-paused-note').hide();
                // 未結案時批圖/溝通/轉生管可以點擊
                $('.stat-card.card-processing, .stat-card.card-communication, .stat-card.card-done').css({'opacity':'','pointer-events':'','cursor':''});
            } else if (currentStatusCardFilter === 'paused') {
                $('.stat-card.card-all .stat-label').text('全部暫停/取消訂單');
                $('#count-paused-note').hide();
                // 暫停/已結案時批圖/溝通/轉生管禁用
                $('.stat-card.card-processing, .stat-card.card-communication, .stat-card.card-done').css({'opacity':'0.4','pointer-events':'none','cursor':'not-allowed'});
            } else if (currentStatusCardFilter === 'closed') {
                $('.stat-card.card-all .stat-label').text('全部已結案訂單');
                $('#count-paused-note').hide();
                $('.stat-card.card-processing, .stat-card.card-communication, .stat-card.card-done').css({'opacity':'0.4','pointer-events':'none','cursor':'not-allowed'});
            } else {
                $('.stat-card.card-all .stat-label').text('全部訂單');
                $('.stat-card.card-processing, .stat-card.card-communication, .stat-card.card-done').css({'opacity':'','pointer-events':'','cursor':''});
                var p = parseInt(s.paused) || 0;
                if (p > 0) { $('#count-paused-note').text(p + '筆訂單已暫停/取消').show(); }
                else { $('#count-paused-note').hide(); }
            }
        }

        // =====================================================================
        // DOM Ready
        // =====================================================================
        $(document).ready(function() {

            // 【初始載入】第一次進頁面用 AJAX 載入（非資料塊模式）
            isStatCardFilter = false;
            fetchTableData(1);

            // ── 年份 / 設計師 下拉 ─────────────────────────────────────────
            $('#year-select').on('change', function() {
                isStatCardFilter = false;
                lockedStats = null;
                fetchTableData(1);
            });

            $('#filter-designer').on('change', function() {
                isStatCardFilter = false;
                lockedStats = null;
                fetchTableData(1);
            });

            // ── 文字篩選（防抖） ────────────────────────────────────────────
            var debounceTimer;
            $('#filter-client, #filter-part, #filter-global').on('keyup input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    isStatCardFilter = false;
                    lockedStats = null;
                    fetchTableData(1);
                }, 400);
            });

            // ── 資料塊點擊 ──────────────────────────────────────────────────
            // 規則：點資料塊後，四個卡片的「絕對數量」鎖定，不隨下方篩選改變
            //       只有點「全部訂單」卡片才解鎖（回到正常模式）
            $('.stat-card').on('click', function() {
                var $card = $(this);
                // 新增訂單／OP轉訂單按鈕不做篩選
                if ($card.find('.fa-plus-circle, .fa-exchange').length) return;

                $('.stat-card').removeClass('active');
                $card.addClass('active');

                if ($card.hasClass('card-processing')) {
                    currentFilters.status = 'in_progress';
                    isStatCardFilter = (currentStatusCardFilter === null); // 有半高篩選時不鎖定統計
                    lockedStats = null;
                } else if ($card.hasClass('card-done')) {
                    currentFilters.status = 'transferred';
                    isStatCardFilter = (currentStatusCardFilter === null);
                    lockedStats = null;
                } else if ($card.hasClass('card-communication')) {
                    currentFilters.status = 'communication';
                    isStatCardFilter = (currentStatusCardFilter === null);
                    lockedStats = null;
                } else {
                    // card-all：解除資料塊篩選，回到正常模式
                    currentFilters.status = 'all';
                    isStatCardFilter = false;
                    lockedStats = null;
                }
                fetchTableData(1);
            });

            // ── 取消篩選按鈕 ────────────────────────────────────────────────
            $('#clear-filters').on('click', function() {
                $('#filter-designer').val('');
                $('#filter-client').val('');
                $('#filter-part').val('');
                $('#filter-global').val('');
                currentFilters.status = 'all';
                currentFilters.unbound = false;
                currentFilters.unbound_op = false;
                currentFilters.qty_over = false;
                $('#count-qty-over-note').css({'font-weight':''});
                currentStatusCardFilter = null;
                isStatCardFilter = false;
                lockedStats = null;
                $('.stat-card').removeClass('active');
                $('.stat-card.card-all').addClass('active');
                $('.stat-card-half').removeClass('active');
                $('.stat-card.card-processing, .stat-card.card-communication, .stat-card.card-done').css({'opacity':'','pointer-events':'','cursor':''});
                // 重置未綁定OP按鈕
                $('#stat-card-unbound-op').removeClass('active');
                // 隱藏未綁定橫幅
                $('#unbound-filter-banner').hide();
                fetchTableData(1);
            });

            // ── 未綁定篩選按鈕 (toggle) ─────────────────────────────────────
            $('#filter-unbound').on('click', function() {
                currentFilters.unbound = !currentFilters.unbound;
                if (currentFilters.unbound) {
                    $(this).removeClass('btn-warning').addClass('btn-primary');
                    $('#unbound-banner-type').text('未綁定客戶/料號 ID');
                    $('#unbound-filter-banner').show();
                } else {
                    $(this).removeClass('btn-primary').addClass('btn-warning');
                    $('#unbound-filter-banner').hide();
                }
                isStatCardFilter = false;
                lockedStats = null;
                fetchTableData(1);
            });

            // ── 篩選欄位雙擊清除 ────────────────────────────────────────────
            $('#filter-designer, #filter-client, #filter-part, #filter-global').on('dblclick', function() {
                if ($(this).val()) {
                    $(this).val('');
                    isStatCardFilter = false;
                    lockedStats = null;
                    fetchTableData(1);
                }
            });

            // ── 表格內雙擊：客戶 / 料號 / 設計師欄位篩選 ──────────────────
            // 欄位順序（接單/交期已合併為1欄）：
            //   0:操作  1:接單/交期  2:客戶  3:料號  4:製程  5:數量  6:單價
            //   7:業務備註  8:設計/日期  9:設計備註  10:轉生管  11:其他資訊
            // colOffset = 0（有操作欄）或 -1（無操作欄）
            $('#orderTable').on('dblclick', 'td', function() {
                var colIdx = $(this).index();
                if (colIdx === 2 + colOffset) {
                    // 客戶欄雙擊篩選
                    var val = $(this).text().trim();
                    var $f = $('#filter-client');
                    $f.val($f.val() ? '' : val);
                    isStatCardFilter = false; lockedStats = null;
                    fetchTableData(1);
                } else if (colIdx === 3 + colOffset) {
                    // 料號欄雙擊篩選
                    var val = $(this).find('.part-link').text().trim() || $(this).text().trim();
                    var $f = $('#filter-part');
                    $f.val($f.val() ? '' : val);
                    isStatCardFilter = false; lockedStats = null;
                    fetchTableData(1);
                } else if (colIdx === 8 + colOffset) {
                    // 設計師欄雙擊篩選 — 用 data-designer-name 取全名比對 option value
                    var fullName = $(this).data('designer-name') || '';
                    var shortName = $(this).find('strong').text().trim();
                    if (!shortName && !fullName) return;
                    var $sel = $('#filter-designer');
                    var matchedVal = '';
                    // 優先用全名直接比對 value
                    if (fullName && $sel.find('option[value="' + fullName + '"]').length) {
                        matchedVal = fullName;
                    } else {
                        // fallback：用短名比對 option text
                        $sel.find('option').each(function() {
                            if ($(this).text().trim() === shortName) {
                                matchedVal = $(this).val();
                                return false;
                            }
                        });
                    }
                    $sel.val($sel.val() === matchedVal && matchedVal !== '' ? '' : matchedVal);
                    isStatCardFilter = false; lockedStats = null;
                    fetchTableData(1);
                }
            });

            // ── scroll FAB ──────────────────────────────────────────────────
            $(window).scroll(function() {
                if ($(this).scrollTop() > 300) $('#backToTop').addClass('visible');
                else $('#backToTop').removeClass('visible');
            });

            // ── 新增訂單視窗雙擊清除 ────────────────────────────────────────
            $('#newOrderForm').on('dblclick', 'input[type="text"], input[type="number"], textarea', function() {
                if ($(this).val()) {
                    // 已綁定料號時客戶名稱鎖定，不可雙擊清除（客戶由料號決定）
                    if ($(this).is('#client_name_input') && $('#selected_part_pk').val()) {
                        showToast('已綁定料號，客戶名稱不可修改；如需更換客戶請先清除料號。', 'info');
                        return;
                    }
                    $(this).val('').trigger('input');
                    if ($(this).is('#client_name_input')) $('#selected_customer_pk').val('');
                    if ($(this).is('#part_id_input')) { $('#selected_part_pk').val(''); updateIdBadges(); }
                }
            });

            // ── Autocomplete ────────────────────────────────────────────────
            setupAutocomplete('#client_name_input', '#client-suggestions', 'customer');
            setupAutocomplete('#part_id_input', '#part-suggestions', 'part');

            $(document).on('click', function(e) {
                if (!$(e.target).closest('.form-group.has-feedback').length) {
                    $('.autocomplete-suggestions').hide().empty();
                }
            });

            // ── Shared Modal 提交 ───────────────────────────────────────────
            $(document).on('submit', '#sharedDynamicModal .dynamic-modal-form', function(e) {
                e.preventDefault();
                var form = $(this);
                var url = form.attr('action');
                var formData = form.serialize();
                $.post(url, formData, function(res) {
                    showToast(res.message || '操作成功');
                    if (res.success) {
                        form.closest('.modal-body').load(form.data('reload-url'));
                    }
                }, 'json').fail(function() { alert('請求失敗，請檢查網路連線。'); });
            });

        }); // end document.ready

        // ── Session Keep-Alive（每 10 分鐘 ping 一次，防止 12 小時內被登出）────
        setInterval(function() {
            $.post('', { action: 'keepalive' });
        }, 600000); // 600,000 ms = 10 分鐘

        // ── 列表庫存跳窗 ─────────────────────────────────────────────────────
        function openStockModal(did) {
            did = (did || '').trim();
            if (!did) return;
            $('#slm-part-id').text(did);
            $('#slm-body').html('<div class="text-center" style="padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
            $('#stock-list-modal').modal('show');

            $.post('', { action: 'get_stock_by_did', d_id: did }, function(res) {
                if (!res || !res.success) {
                    $('#slm-body').html('<div class="text-center" style="padding:20px;color:#c0392b;"><i class="fa fa-exclamation-circle"></i> 載入失敗</div>');
                    return;
                }
                var rows = res.data || [];
                if (rows.length === 0) {
                    $('#slm-body').html('<div class="text-center" style="padding:20px;color:#aaa;"><i class="fa fa-inbox fa-2x"></i><br>無庫存紀錄</div>');
                    return;
                }
                var totalSingle = 0, totalCombo = 0;
                rows.forEach(function(r) {
                    var q = parseFloat(r.qty) || 0;
                    if (r.is_combo == 1) totalCombo += q; else totalSingle += q;
                });
                var totalAll = totalSingle + totalCombo;
                // 合計橫幅
                var summaryHtml = '<div style="background:#f0fff4;border:1px solid #a8e6c3;border-radius:4px;padding:8px 12px;margin-bottom:10px;font-size:12px;">';
                summaryHtml += '<i class="fa fa-archive" style="color:#27ae60;margin-right:5px;"></i>';
                summaryHtml += '<strong style="color:#27ae60;">總計：' + totalAll.toFixed(0) + '</strong>';
                if (totalSingle > 0 && totalCombo > 0) {
                    summaryHtml += '<span style="color:#888;margin-left:8px;font-size:11px;">（非組合件 ' + totalSingle.toFixed(0) + ' + 組合件 ' + totalCombo.toFixed(0) + '）</span>';
                } else if (totalCombo > 0) {
                    summaryHtml += '<span style="color:#3498db;margin-left:8px;font-size:11px;">（全為組合件）</span>';
                }
                summaryHtml += '</div>';
                // 明細表格
                var tableHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                tableHtml += '<thead><tr style="background:#f7f7f7;border-bottom:2px solid #e0e0e0;">'
                           + '<th style="padding:5px 8px;font-weight:600;color:#555;">儲位</th>'
                           + '<th style="padding:5px 8px;font-weight:600;color:#555;">種類</th>'
                           + '<th style="padding:5px 8px;font-weight:600;color:#555;text-align:right;">數量</th>'
                           + '<th style="padding:5px 8px;font-weight:600;color:#555;">備註</th>'
                           + '</tr></thead><tbody>';
                rows.forEach(function(r) {
                    var qty = parseFloat(r.qty) || 0;
                    var qtyColor = qty > 0 ? '#27ae60' : '#e74c3c';
                    var comboTag = r.is_combo == 1
                        ? ' <span style="display:inline-block;background:#3498db;color:#fff;border-radius:2px;padding:0 3px;font-size:10px;vertical-align:middle;">組合</span>' : '';
                    var catBg = escapeHtml(r.category_color || '#777');
                    var catHtml = r.category_name
                        ? '<span style="background:' + catBg + ';color:#fff;border-radius:3px;padding:1px 6px;font-size:11px;">' + escapeHtml(r.category_name) + '</span>'
                        : '<span style="color:#aaa;">—</span>';
                    tableHtml += '<tr style="border-bottom:1px solid #f0f0f0;">'
                               + '<td style="padding:5px 8px;font-family:Consolas,monospace;color:#444;">' + escapeHtml(r.storage_location || '—') + '</td>'
                               + '<td style="padding:5px 8px;">' + catHtml + comboTag + '</td>'
                               + '<td style="padding:5px 8px;text-align:right;font-weight:700;font-size:13px;color:' + qtyColor + ';">' + qty.toFixed(0) + '</td>'
                               + '<td style="padding:5px 8px;color:#888;font-size:11px;">' + escapeHtml(r.remark || '') + '</td>'
                               + '</tr>';
                });
                tableHtml += '</tbody></table>';
                $('#slm-body').html(summaryHtml + tableHtml);
            }, 'json').fail(function() {
                $('#slm-body').html('<div class="text-center" style="padding:20px;color:#c0392b;"><i class="fa fa-exclamation-circle"></i> 網路錯誤，載入失敗</div>');
            });
        }

        // ── 料號庫存面板：依料號查詢並渲染 ──────────────────────────────────
        var _stockLoadTimer = null;
        function loadPartStock(did) {
            did = (did || '').trim();
            if (!did) { $('#part-stock-panel').hide(); return; }

            // 防抖：300ms 內連續觸發只執行最後一次
            clearTimeout(_stockLoadTimer);
            _stockLoadTimer = setTimeout(function() {
                $('#part-stock-content').html('<span style="color:#aaa;font-size:10px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</span>');
                $('#part-stock-panel').show();

                $.post('', { action: 'get_stock_by_did', d_id: did }, function(res) {
                    if (!res || !res.success) {
                        $('#part-stock-content').html('<span style="color:#c0392b;font-size:10px;">載入失敗</span>');
                        return;
                    }
                    var rows = res.data || [];
                    if (rows.length === 0) {
                        $('#part-stock-content').html('<span style="color:#aaa;font-size:10px;"><i class="fa fa-inbox"></i> 無庫存紀錄</span>');
                        return;
                    }
                    // 彙整單件 / 組合件合計
                    var totalSingle = 0, totalCombo = 0;
                    rows.forEach(function(r) {
                        var q = parseFloat(r.qty) || 0;
                        if (r.is_combo == 1) totalCombo += q; else totalSingle += q;
                    });
                    // 合計行
                    var totalHtml = '<div style="margin-bottom:4px;font-size:10px;">';
                    totalHtml += '<i class="fa fa-archive" style="color:' + ((totalSingle+totalCombo)>0?'#27ae60':'#bbb') + ';margin-right:3px;"></i>';
                    if (totalSingle > 0) totalHtml += '<span style="color:#27ae60;font-weight:700;">' + totalSingle.toFixed(0) + '</span><span style="color:#888;"> 單件</span>';
                    if (totalCombo  > 0) totalHtml += (totalSingle>0?'  ':'') + '<span style="color:#3498db;font-weight:700;">' + totalCombo.toFixed(0) + '</span><span style="color:#888;"> 組合件</span>';
                    totalHtml += '</div>';
                    // 明細表格
                    var tableHtml = '<table style="width:100%;border-collapse:collapse;font-size:10px;">';
                    tableHtml += '<tr style="border-bottom:1px solid #e0e0e0;color:#999;">'
                               + '<th style="padding:2px 4px;font-weight:600;">儲位</th>'
                               + '<th style="padding:2px 4px;font-weight:600;">種類</th>'
                               + '<th style="padding:2px 4px;font-weight:600;text-align:right;">數量</th>'
                               + '<th style="padding:2px 4px;font-weight:600;">備註</th>'
                               + '</tr>';
                    rows.forEach(function(r) {
                        var qty = parseFloat(r.qty) || 0;
                        var qtyColor = qty > 0 ? '#27ae60' : '#e74c3c';
                        var comboTag = r.is_combo == 1
                            ? ' <span style="background:#3498db;color:#fff;border-radius:2px;padding:0 3px;font-size:8px;">組合</span>' : '';
                        var catBg = escapeHtml(r.category_color || '#777');
                        var catHtml = r.category_name
                            ? '<span style="background:' + catBg + ';color:#fff;border-radius:3px;padding:0 4px;">' + escapeHtml(r.category_name) + '</span>'
                            : '<span style="color:#aaa;">-</span>';
                        tableHtml += '<tr style="border-bottom:1px solid #f5f5f5;">'
                                   + '<td style="padding:2px 4px;">' + escapeHtml(r.storage_location || '-') + '</td>'
                                   + '<td style="padding:2px 4px;">' + catHtml + comboTag + '</td>'
                                   + '<td style="padding:2px 4px;text-align:right;font-weight:700;color:' + qtyColor + ';">' + qty.toFixed(0) + '</td>'
                                   + '<td style="padding:2px 4px;color:#888;">' + escapeHtml(r.remark || '') + '</td>'
                                   + '</tr>';
                    });
                    tableHtml += '</table>';
                    $('#part-stock-content').html(totalHtml + tableHtml);
                }, 'json').fail(function() {
                    $('#part-stock-content').html('<span style="color:#c0392b;font-size:10px;">載入失敗</span>');
                });
            }, 300);
        }

        // ── Modal ID Badge 更新 + 右側面板觸發 ──────────────────────────────
        function updateIdBadges() {
            var custPk  = $('#selected_customer_pk').val();
            var partPk  = $('#selected_part_pk').val();
            var custTxt = $('#client_name_input').val().trim();
            var partTxt = $('#part_id_input').val().trim();

            // 客戶 badge
            if (custPk && custPk !== '' && custPk !== '0') {
                $('#customer-id-badge').text('ID: ' + custPk).show();
                $('#customer-id-missing').hide();
                $('#btn-quick-add-customer').hide();
            } else if (custTxt !== '') {
                $('#customer-id-badge').hide();
                $('#customer-id-missing').show();
                $('#btn-quick-add-customer').show(); // 有輸入但無綁定 → 顯示「新增客戶」
            } else {
                $('#customer-id-badge').hide();
                $('#customer-id-missing').hide();
                $('#btn-quick-add-customer').hide();
            }

            // 綁定料號後鎖定客戶名稱：客戶由料號決定，避免改名後與後端資料不符
            var lockClient = !!(partPk && partPk !== '' && partPk !== '0');
            $('#client_name_input').prop('readonly', lockClient)
                .css('background-color', lockClient ? '#f5f5f5' : '')
                .attr('title', lockClient ? '已綁定料號，客戶名稱由料號決定，不可修改；如需更換客戶請先清除料號（可雙擊料號欄清除）' : '');
            if (lockClient) $('#client-suggestions').hide().empty();

            // 料號 badge + 觸發右側面板 + 庫存面板
            var partDrawingNo = $('#selected_part_drawing_no').val() || '';
            if (partPk && partPk !== '' && partPk !== '0') {
                $('#part-id-badge').text('ID: ' + partPk).show();
                $('#part-id-missing').hide();
                $('#btn-quick-add-part').hide();
                if (partDrawingNo) {
                    $('#part-drawing-no-badge').text('代：' + partDrawingNo).show();
                } else {
                    $('#part-drawing-no-badge').hide();
                }
                loadQuotesAndHistory(partTxt);
                loadPartStock(partTxt);
            } else if (partTxt !== '') {
                $('#part-id-badge').hide();
                $('#part-id-missing').show();
                $('#btn-quick-add-part').show();
                $('#part-drawing-no-badge').hide();
                loadQuotesAndHistory(partTxt);
                loadPartStock(partTxt);
            } else {
                $('#part-id-badge').hide();
                $('#part-id-missing').hide();
                $('#btn-quick-add-part').hide();
                $('#part-drawing-no-badge').hide();
                // 隱藏右側，顯示 placeholder
                $('#panel-right-content').hide();
                $('#panel-quotes-placeholder').show();
                // 隱藏庫存面板
                $('#part-stock-panel').hide();
            }

            // 拆批按鈕：僅在新增模式（無 Order_id）且必填條件滿足時顯示
            var isNewMode = !$('#hidden_Order_id').val();
            if (isNewMode) {
                var orderNo  = $('input[name="OrderNo"]').val().trim();
                var orderDd  = $('input[name="orderDdate"]').val().trim();
                var qty      = parseInt($('input[name="Qty"]').val()) || 0;
                var process  = $('input[name="Process"]').val().trim();
                var canSplit = (orderNo !== '' && orderDd !== '' && qty > 0 && process !== '' &&
                                custPk && custPk !== '' && custPk !== '0' &&
                                partPk && partPk !== '' && partPk !== '0');
                if (canSplit) {
                    $('#btn-open-split').show().removeData('order-id');
                } else {
                    $('#btn-open-split').hide();
                }
            }
        }

        // ── 載入報價單 & 出貨歷史 ───────────────────────────────────────────
        var _lastLoadedPart = '';
        function loadQuotesAndHistory(partText) {
            if (!partText || partText === _lastLoadedPart) return;
            _lastLoadedPart = partText;

            // 顯示右側面板
            $('#panel-quotes-placeholder').hide();
            $('#panel-right-content').show();
            $('#quote-list').html('<div class="text-center" style="padding:8px;"><i class="fa fa-spinner fa-spin"></i></div>');
            $('#shipment-history-list').html('<div class="text-center" style="padding:8px;"><i class="fa fa-spinner fa-spin"></i></div>');

            // 報價單查詢
            $.post('', { action: 'get_quotes_by_part', part_text: partText, d_id_id: $('#selected_part_pk').val() || 0 }, function(res) {
                var $ql = $('#quote-list').empty();
                if (res.success && res.data.length > 0) {
                    res.data.forEach(function(q) {
                        var price = parseFloat(q.unit_price);
                        var priceDisplay = price > 0 ? formatPrice(price) : '未報價';
                        var priceColor   = price > 0 ? '#27ae60' : '#bbb';
                        var qtyStr       = q.quantity ? 'x' + parseInt(q.quantity).toLocaleString() : '';
                        // 製程：與報價單相同格式 製程名(・連接) / 料號備註(specification)
                        var _procParts = [];
                        if ((q.processes||'').trim())     _procParts.push(q.processes.trim());
                        if ((q.specification||'').trim()) _procParts.push(q.specification.trim());
                        var procText = _procParts.join(' / ');
                        var procHtml = procText
                            ? '<div style="color:#555;font-size:10px;margin-top:2px;"><i class="fa fa-cogs" style="color:#aaa;"></i> ' + escapeHtml(procText.substring(0, 60)) + '</div>'
                            : '';
                        // 報價單備註（僅顯示 quote_note，不顯示 process_notes 原始 ID）
                        var noteText = (q.quote_note || '').trim();
                        var noteHtml = noteText
                            ? '<div style="color:#888;font-size:10px;margin-top:1px;font-style:italic;"><i class="fa fa-sticky-note-o" style="color:#bbb;"></i> ' + escapeHtml(noteText.substring(0, 50)) + '</div>'
                            : '';

                        var negoBadge = q.is_negotiation == 1 ? ' <span style="display:inline-block;font-size:9px;padding:1px 6px;background:#e8f8f0;color:#1e8449;border:1px solid #a9dfbf;border-radius:10px;font-weight:600;white-space:nowrap;">議價</span>' : '';
                        // 透過客戶代號／等同料號帶入的報價（報價料號跟目前選定料號不同）：標示原報價料號
                        var aliasHtml = q.alias_hit
                            ? '<div style="font-size:10px;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:0 4px;display:inline-block;margin-top:2px;">報價料號：' + escapeHtml(q.alias_hit) + '</div>'
                            : '';
                        var $item = $('<div class="quote-item-row" style="padding:5px 7px;border:1px solid #e0e0e0;border-radius:4px;margin-bottom:4px;cursor:pointer;background:#fff;transition:background .15s;">' +
                            '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:4px;">' +
                            '<strong style="font-size:11px;">' + escapeHtml(q.quote_no) + '</strong>' + negoBadge +
                            '<span style="font-size:10px;color:#999;white-space:nowrap;">' + (q.quote_date||'') + '</span>' +
                            '<span style="font-size:12px;font-weight:700;color:' + priceColor + ';">$' + priceDisplay + '</span>' +
                            '<span style="font-size:10px;color:#888;">' + qtyStr + '</span>' +
                            '</div>' +
                            aliasHtml + procHtml + noteHtml +
                            '</div>');
                        $item.data('quote', q);
                        $item.on('mouseenter', function() { $(this).css('background','#f0f8ff'); });
                        $item.on('mouseleave', function() { if (!$(this).hasClass('quote-selected')) $(this).css('background','#fff'); });
                        $item.on('click', function() {
                            $('.quote-item-row').css('background','#fff').removeClass('quote-selected');
                            $(this).css('background','#e8f5e9').addClass('quote-selected');
                            bindQuoteItem($(this).data('quote'));
                        });
                        $ql.append($item);
                    });
                } else {
                    $ql.html('<div style="color:#bbb;font-size:11px;text-align:center;padding:8px;">此料號無相關報價單</div>');
                }
            }, 'json').fail(function() { $('#quote-list').html('<div style="color:#e74c3c;font-size:11px;padding:4px;">查詢失敗</div>'); });

            // 出貨歷史查詢
            $.post('', { action: 'get_shipment_history', part_text: partText, d_id_id: $('#selected_part_pk').val() || 0 }, function(res) {
                var $sl = $('#shipment-history-list').empty();
                if (res.success && res.data.length > 0) {
                    var html = '<table style="width:100%;font-size:10px;border-collapse:collapse;">';
                    html += '<thead><tr style="background:#f5f5f5;"><th style="padding:2px 4px;text-align:left;">日期</th><th style="padding:2px 4px;">客戶</th><th style="padding:2px 4px;text-align:right;">數量</th><th style="padding:2px 4px;text-align:right;">單價</th><th style="padding:2px 4px;">製程/規格</th><th style="padding:2px 4px;">備註</th></tr></thead><tbody>';
                    res.data.forEach(function(s, idx) {
                        var bg    = idx % 2 === 0 ? '#fff' : '#fafafa';
                        var price = parseFloat(s.Unit_price);
                        var priceStr  = price > 0 ? '<span style="color:#27ae60;font-weight:600;">$' + formatPrice(price) + '</span>' : '<span style="color:#bbb;">-</span>';
                        var specTxt   = (s.Specification || '').substring(0, 14);
                        var noteTxt   = (s.Content || s.Note || '').substring(0, 20);
                        html += '<tr style="background:' + bg + ';border-bottom:1px solid #eee;">';
                        html += '<td style="padding:2px 4px;white-space:nowrap;color:#666;">' + (s.Order_date||'').substring(0,10).replace(/-/g,'.') + '</td>';
                        html += '<td style="padding:2px 4px;max-width:60px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(s.Client_name||'') + '">' + escapeHtml((s.Client_name||'').substring(0,6)) + '</td>';
                        html += '<td style="padding:2px 4px;text-align:right;font-weight:600;">' + (parseInt(s.Qty)||0).toLocaleString() + '</td>';
                        html += '<td style="padding:2px 4px;text-align:right;">' + priceStr + '</td>';
                        html += '<td style="padding:2px 4px;max-width:70px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#666;" title="' + escapeHtml(s.Specification||'') + '">' + escapeHtml(specTxt) + '</td>';
                        html += '<td style="padding:2px 4px;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#888;" title="' + escapeHtml(s.Content||s.Note||'') + '">' + escapeHtml(noteTxt) + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    html += '<div style="color:#bbb;font-size:9px;text-align:right;margin-top:2px;">※ 價格因製程/數量/議價而異，僅供參考</div>';
                    $sl.html(html);
                } else {
                    $sl.html('<div style="color:#bbb;font-size:11px;text-align:center;padding:8px;">此料號無出貨紀錄</div>');
                }
            }, 'json').fail(function() { $('#shipment-history-list').html('<div style="color:#e74c3c;font-size:11px;">查詢失敗</div>'); });
        }

        // ── 單價格式化（去掉尾部多餘的0）───────────────────────────────────
        function formatPrice(val) {
            var n = parseFloat(val);
            if (isNaN(n) || n === 0) return '0';
            // 最多保留4位小數，去除尾部0
            var s = n.toFixed(4).replace(/\.?0+$/, '');
            return s;
        }

        // ── 綁定報價單項目 ───────────────────────────────────────────────────
        var _boundQuotePrice = null;
        var _boundQuoteQty   = null;
        function bindQuoteItem(q) {
            _boundQuotePrice = parseFloat(q.unit_price);
            _boundQuoteQty   = parseInt(q.quantity) || 0;

            $('#bound_quote_item_id').val(q.item_id);
            $('#hidden_quote_no').val(q.quote_no || '');

            // 單價：帶入並設為唯讀文字顯示
            if (_boundQuotePrice > 0) {
                $('#unit_price_input')
                    .val(formatPrice(_boundQuotePrice))
                    .prop('readonly', true)
                    .css({'background':'#f0f8ff','color':'#27ae60','fontWeight':'600'});
                $('#price-lock-icon').show();
                $('#price-source').text('來自 ' + q.quote_no);
            }

            // 製程：帶入可修改（優先顯示製程名，再顯示料號備註）
            var proc = (q.processes || q.specification || '').trim();
            if (proc) {
                var curProc = $('#process_input').val().trim();
                if (!curProc) {
                    $('#process_input').val(proc);
                } else if (curProc !== proc) {
                    if (confirm('以報價單製程覆蓋目前的製程？\n報價：' + proc + '\n目前：' + curProc)) {
                        $('#process_input').val(proc);
                    }
                }
            }

            // 數量：帶入可修改，差異超20%時警告
            var curQty = parseInt($('#qty_input').val()) || 0;
            if (_boundQuoteQty > 0) {
                if (curQty === 0) {
                    $('#qty_input').val(_boundQuoteQty);
                    $('#qty-warn').hide();
                } else {
                    var ratio = Math.abs(curQty - _boundQuoteQty) / _boundQuoteQty;
                    if (ratio > 0.2) {
                        $('#qty-warn').text('⚠ 與報價數量 ' + _boundQuoteQty.toLocaleString() + ' 差 ' + Math.round(ratio*100) + '%').show();
                    }
                }
            }

            // 更新綁定摘要
            $('#quote-bound-info').html(
                '<i class="fa fa-check-circle" style="color:#27ae60;"></i> 已綁定 <strong>' + q.quote_no + '</strong>' +
                (q.quote_date ? '（' + q.quote_date + '）' : '') +
                '，單價 <strong style="color:#27ae60;">$' + formatPrice(_boundQuotePrice) + '</strong>'
            ).show();
            $('#btn-clear-quote').show();
            showToast('已綁定報價單 ' + q.quote_no);
        }

        function clearQuoteBinding() {
            $('#bound_quote_item_id').val('');
            $('#hidden_quote_no').val('');
            // 恢復單價可編輯
            $('#unit_price_input')
                .prop('readonly', false)
                .css({'background':'','color':'','fontWeight':''});
            $('#price-lock-icon').hide();
            $('#price-source').text('');
            $('#quote-bound-info').hide();
            $('#btn-clear-quote').hide();
            $('.quote-item-row').css('background','#fff').removeClass('quote-selected');
            _boundQuotePrice = null;
            _lastLoadedPart  = '';
            showToast('已清除報價單綁定');
        }

        // ── 數量變更時檢查與報價差異 ────────────────────────────────────────
        $(document).on('input', '#qty_input', function() {
            if (_boundQuoteQty && _boundQuoteQty > 0) {
                var curQty = parseInt($(this).val()) || 0;
                var ratio  = curQty > 0 ? Math.abs(curQty - _boundQuoteQty) / _boundQuoteQty : 1;
                if (curQty > 0 && ratio > 0.2) {
                    $('#qty-warn').text('⚠ 與報價數量 ' + _boundQuoteQty + ' 差異 ' + Math.round(ratio*100) + '%，請確認').show();
                } else {
                    $('#qty-warn').hide();
                }
            }
        });

        // ── 快速新增客戶 ────────────────────────────────────────────────────
        function openQuickAddCustomer() {
            $('#qac_id').val('');
            $('#qac_name').val($('#client_name_input').val()); // 預填已輸入的名稱
            $('#qac_addr').val('');
            $('#qac-error').hide();
            $('#quickAddCustomerModal').modal('show');
        }
        function submitQuickAddCustomer() {
            var id   = $('#qac_id').val().trim();
            var name = $('#qac_name').val().trim();
            var addr = $('#qac_addr').val().trim();
            if (!id || !name) { $('#qac-error').text('客戶代碼與名稱均為必填').show(); return; }
            $.post('', { action:'quick_add_customer', customer_id:id, customer_name:name, customer_addr:addr }, function(res) {
                if (res.success) {
                    // 自動帶入新建的客戶
                    $('#client_name_input').val(res.customer_name);
                    $('#selected_customer_pk').val(res.customer_id);
                    updateIdBadges();
                    $('#quickAddCustomerModal').modal('hide');
                    showToast('客戶 [' + res.customer_id + '] 建立成功並已選取');
                } else {
                    $('#qac-error').text(res.message).show();
                }
            }, 'json');
        }

        // ── 快速新增料號 ────────────────────────────────────────────────────
        function openQuickAddPart() {
            // ※ 新增：新建料號前先確認已選定客戶ID
            if (!$('#selected_customer_pk').val()) {
                showToast('請先選定要綁定的客戶（需從建議列表選取並取得客戶ID），再新建料號。');
                $('#client_name_input').focus();
                return;
            }
            $('#qap_no').val($('#part_id_input').val()); // 預填
            $('#qap_spec').val('');
            $('#qap_rev').val('');
            $('#qap_customer_name').val($('#client_name_input').val());
            $('#qap_customer_id').val($('#selected_customer_pk').val());
            $('#qap-error').hide();
            $('#quickAddPartModal').modal('show');
        }

        // ※ 新增：從快速綁定 Modal 開啟料號設定 Modal
        // 儲存成功後自動選取新料號並回到快速綁定 Modal
        var _qbPartNewContext = false; // 標記是否從快速綁定開啟
        function openPartSettingsFromQb() {
            // ※ 檢查：快速綁定中必須先選定客戶
            if (!qbSelectedCustomer) {
                showToast('請先在上方選定要綁定的客戶，再新建料號。');
                return;
            }
            _qbPartNewContext = true;
            resetPartModal();
            // 預填料號（從快速綁定的 qb-part-current 取得原始文字）
            var partText = $('#qb-part-current').text().replace(/「|」/g, '').trim();
            if (partText) $('#part_modal_no').val(partText);
            // 預填客戶（從快速綁定的客戶選取結果）
            $('#part_modal_customer_name').val(qbSelectedCustomer.customer || '');
            $('#part_modal_customer_id').val(qbSelectedCustomer.customer_id || '');
            // ※ 新增：客戶由系統帶入，設為唯讀
            setPartModalCustomerReadonly(true);
            // 先隱藏快速綁定 Modal，再開料號設定 Modal
            $('#quickBindModal').modal('hide');
            $('#quickBindModal').one('hidden.bs.modal', function() {
                $('#partSettingsModal').modal('show');
            });
        }
        function submitQuickAddPart() {
            var no  = $('#qap_no').val().trim();
            var sp  = $('#qap_spec').val().trim();
            var rv  = $('#qap_rev').val().trim();
            var cid = $('#qap_customer_id').val().trim();
            if (!no)  { $('#qap-error').text('料號不可為空').show(); return; }
            if (!cid) { $('#qap-error').text('客戶為必填，請先在訂單中選定已綁定的客戶。').show(); return; }
            $('#qap-error').hide();
            $.post('', { action:'quick_add_part', part_no:no, spec_no:sp, revision:rv, customer_id:cid }, function(res) {
                if (res.success) {
                    $('#part_id_input').val(res.D_Setting_Id);
                    $('#selected_part_pk').val(res.d_id);
                    _lastLoadedPart = '';
                    updateIdBadges();
                    $('#quickAddPartModal').modal('hide');
                    showToast('料號 [' + res.D_Setting_Id + '] 建立成功並已選取');
                } else {
                    $('#qap-error').text(res.message).show();
                }
            }, 'json');
        }

        // quickAddPartModal：Enter 鍵觸發建立（防止意外送出空客戶）
        $('#quickAddPartModal').on('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); submitQuickAddPart(); }
        });

        // 在 Modal 顯示時重設 badge
        $('#newOrderModal').on('shown.bs.modal', function() {
            updateIdBadges();
        });

        // ※ 新增：料號設定 Modal 關閉時，若不是回流中，重置快速綁定情境旗標
        $('#partSettingsModal').on('hidden.bs.modal', function() {
            // 只有當 _qbPartNewContext 為 true 且不是在儲存成功的回流中（回流中已設為 false）
            // 此情況代表使用者手動取消，清除旗標
            if (_qbPartNewContext) {
                _qbPartNewContext = false;
                // 使用者取消，重新開啟快速綁定 Modal
                $('#quickBindModal').modal('show');
            }
        });

        // 監聽 autocomplete 輸入框的 input 事件來即時更新 badge
        $(document).on('input', '#client_name_input, #part_id_input', function() {
            // 短暫延遲讓 autocomplete 有機會更新 hidden PK
            setTimeout(updateIdBadges, 300);
        });

        // 監聽必填欄位變動，更新拆批按鈕顯示狀態（新增模式）
        $(document).on('input change', 'input[name="OrderNo"], input[name="orderDdate"], input[name="Qty"], input[name="Process"]', function() {
            setTimeout(updateIdBadges, 100);
        });

        // 訂單編號：輸入時自動轉大寫（OO 前綴）
        $(document).on('input', 'input[name="OrderNo"]', function() {
            var el = this, pos = el.selectionStart, v = el.value, up = v.toUpperCase();
            if (v !== up) { el.value = up; el.setSelectionRange(pos, pos); }
        });

        // ── 快速綁定：組合件/子件標記（HTML 版與純文字版）────────────────────
        function qbBomMark(p) {
            if (parseInt(p.Is_Assembly) === 1) return ' <span style="background:#3498db;color:#fff;border-radius:3px;padding:0 4px;font-size:10px;">組合件</span>';
            if (parseInt(p.Is_Bom_Child) === 1) return ' <span style="background:#95a5a6;color:#fff;border-radius:3px;padding:0 4px;font-size:10px;">子件</span>';
            return '';
        }
        function qbBomMarkText(p) {
            if (parseInt(p.Is_Assembly) === 1) return '【組合件】';
            if (parseInt(p.Is_Bom_Child) === 1) return '【子件】';
            return '';
        }

        // ── 快速綁定函數 ────────────────────────────────────────────────────
        var qbSelectedCustomer = null;
        var qbSelectedPart     = null;
        var qbSelectedQuote    = null; // { item_id, quote_no, unit_price, specification }

        function openQuickBind(orderId, clientName, dIdText) {
            qbSelectedCustomer = null;
            qbSelectedPart     = null;
            qbSelectedQuote    = null;
            $('#qb_order_id').val(orderId);
            $('#qb-client-search-input').val(clientName || '');
            $('#qb-loading').show();
            $('#qb-content').hide();
            $('#qb-save-btn').hide();
            $('#qb-selected-summary').hide();
            $('#qb-quote-list').empty();
            $('#qb-quote-bound-info').hide();
            $('#quickBindModal').modal('show');

            // 客戶 + 料號查詢
            $.post('', {
                action:      'quick_bind_lookup',
                order_id:    orderId,
                client_name: clientName,
                d_id_text:   dIdText
            }, function(res) {
                $('#qb-loading').hide();
                if (!res.success) {
                    $('#qb-content').html('<div class="alert alert-danger">' + res.message + '</div>').show();
                    return;
                }

                // ── 客戶選項 ──────────────────────────────────────────────
                $('#qb-client-current').text('「' + clientName + '」');
                var $cl = $('#qb-client-list').empty();
                $('#qb-client-new-area').hide();
                $('#qb-client-search-area').hide(); // 預設隱藏，找不到時才顯示
                if (res.customers.length === 0) {
                    $cl.html('<div style="color:#999;font-size:11px;padding:3px 0;">找不到符合的客戶</div>');
                    $('#qb-client-new-area').show();
                    $('#qb-client-search-area').show(); // 找不到才開放手動搜尋
                } else if (res.customers.length === 1) {
                    qbSelectedCustomer = res.customers[0];
                    $cl.html('<span class="label label-success" style="font-size:11px;"><i class="fa fa-check"></i> ' + res.customers[0].customer_id + ' ' + escapeHtml(res.customers[0].customer) + '</span>');
                    updateQbSummary();
                } else {
                    res.customers.forEach(function(c) {
                        $('<button type="button" class="btn btn-default btn-xs qb-customer-btn" style="margin:2px 2px 2px 0;font-size:11px;"></button>')
                            .text(c.customer_id + ' ' + c.customer)
                            .data('customer', c)
                            .on('click', function() {
                                qbSelectedCustomer = $(this).data('customer');
                                $('.qb-customer-btn').removeClass('btn-primary').addClass('btn-default');
                                $(this).removeClass('btn-default').addClass('btn-primary');
                                updateQbSummary();
                            })
                            .appendTo($cl);
                    });
                }

                // ── 料號選項 ──────────────────────────────────────────────
                $('#qb-part-current').text('「' + dIdText + '」');
                var $pl = $('#qb-part-list').empty();
                $('#qb-part-new-area').hide();
                $('#qb-part-new-for-customer-area').hide();
                if (res.parts.length === 0) {
                    $pl.html('<div style="color:#999;font-size:11px;padding:3px 0;">找不到符合的料號</div>');
                    $('#qb-part-new-area').show();
                } else if (res.parts.length === 1) {
                    qbSelectedPart = res.parts[0];
                    var _p0 = res.parts[0];
                    var _p0dn = (_p0.Drawing_No && _p0.Drawing_No !== _p0.D_Setting_Id) ? ' <span style="color:#b8d4f0;">代：' + escapeHtml(_p0.Drawing_No) + '</span>' : '';
                    if (_p0.alias_hit) _p0dn += ' <span style="color:#FFF3E2;">＝' + escapeHtml(_p0.alias_hit) + '</span>';
                    var _p0bom = qbBomMark(_p0);
                    $pl.html('<span class="label label-success" style="font-size:11px;"><i class="fa fa-check"></i> ' + escapeHtml(_p0.D_Setting_Id) + _p0dn + _p0bom + (_p0.client_name ? ' (' + escapeHtml(_p0.client_name) + ')' : '') + '</span>');
                } else {
                    res.parts.forEach(function(p) {
                        var _pdn = (p.Drawing_No && p.Drawing_No !== p.D_Setting_Id) ? ' 代：' + p.Drawing_No : '';
                        if (p.alias_hit) _pdn += '（＝' + p.alias_hit + '）';
                        var label = p.D_Setting_Id + _pdn + qbBomMarkText(p) + (p.Spec_No ? '/' + p.Spec_No : '') + (p.client_name ? ' (' + p.client_name + ')' : '');
                        $('<button type="button" class="btn btn-default btn-xs qb-part-btn" style="margin:2px 2px 2px 0;font-size:11px;"></button>')
                            .text(label)
                            .data('part', p)
                            .on('click', function() {
                                qbSelectedPart = $(this).data('part');
                                $('.qb-part-btn').removeClass('btn-primary').addClass('btn-default');
                                $(this).removeClass('btn-default').addClass('btn-primary');
                                updateQbSummary();
                                // 選定料號後載入報價單（帶入料號PK，精準比對）
                                loadQbQuotes(dIdText, qbSelectedPart.d_id);
                            })
                            .appendTo($pl);
                    });
                }

                // 「為此客戶新建料號」：僅當所有找到的料號都屬於不同客戶時才顯示
                var expectedCid = (res.customers.length === 1) ? String(res.customers[0].customer_id || '') : null;
                var allPartsDiffCustomer = res.parts.length > 0 && expectedCid !== null &&
                    res.parts.every(function(p) { return String(p.customer_id || '') !== expectedCid; });
                $('#qb-part-new-for-customer-area').toggle(allPartsDiffCustomer);

                $('#qb-content').show();
                updateQbSummary();

                // 自動載入報價單（用原始料號文字查詢）
                if (dIdText) loadQbQuotes(dIdText);

            }, 'json').fail(function() {
                $('#qb-loading').hide();
                $('#qb-content').html('<div class="alert alert-danger">查詢失敗，請稍後再試</div>').show();
            });
        }

        // ── 快速綁定：載入報價單 ─────────────────────────────────────────────
        function loadQbQuotes(partText, partPk) {
            var $ql = $('#qb-quote-list').empty();
            $('#qb-quote-loading').show();
            $.post('', { action: 'get_quotes_by_part', part_text: partText, d_id_id: partPk || 0 }, function(res) {
                $('#qb-quote-loading').hide();
                if (res.success && res.data.length > 0) {
                    res.data.forEach(function(q) {
                        var price = parseFloat(q.unit_price);
                        var priceStr = price > 0 ? ' <strong style="color:#27ae60;">$' + formatPrice(price) + '</strong>' : ' <span style="color:#bbb;">未報價</span>';
                        var procText = (q.processes || q.process_notes || q.specification || '').trim();
                        var procHtml = procText ? '<div style="font-size:10px;color:#666;margin-top:1px;"><i class="fa fa-cogs" style="color:#bbb;"></i> ' + escapeHtml(procText.substring(0, 40)) + '</div>' : '';
                        var noteText = (q.quote_note || '').trim();
                        var noteHtml = noteText ? '<div style="font-size:10px;color:#aaa;font-style:italic;"><i class="fa fa-sticky-note-o"></i> ' + escapeHtml(noteText.substring(0, 40)) + '</div>' : '';
                        var negoBadge = q.is_negotiation == 1 ? ' <span style="display:inline-block;font-size:9px;padding:1px 6px;background:#e8f8f0;color:#1e8449;border:1px solid #a9dfbf;border-radius:10px;font-weight:600;white-space:nowrap;">議價</span>' : '';
                        var aliasHtml = q.alias_hit
                            ? '<div style="font-size:10px;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:0 4px;display:inline-block;margin-top:2px;">報價料號：' + escapeHtml(q.alias_hit) + '</div>'
                            : '';

                        var $item = $('<div class="qb-quote-row" style="padding:4px 6px;border:1px solid #e0e0e0;border-radius:3px;margin-bottom:3px;cursor:pointer;background:#fff;font-size:11px;">' +
                            '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                            '<strong>' + escapeHtml(q.quote_no) + '</strong>' + negoBadge +
                            '<span style="color:#999;font-size:10px;">' + (q.quote_date || '') + '</span>' +
                            priceStr +
                            '<span style="color:#888;font-size:10px;">x' + (parseInt(q.quantity)||0) + '</span>' +
                            '</div>' + aliasHtml + procHtml + noteHtml + '</div>');
                        $item.data('quote', q);
                        $item.on('mouseenter', function() { if (!$(this).hasClass('qb-quote-selected')) $(this).css('background','#f0f8ff'); });
                        $item.on('mouseleave', function() { if (!$(this).hasClass('qb-quote-selected')) $(this).css('background','#fff'); });
                        $item.on('click', function() {
                            qbSelectedQuote = $(this).data('quote');
                            $('.qb-quote-row').css('background','#fff').removeClass('qb-quote-selected');
                            $(this).css('background','#e8f5e9').addClass('qb-quote-selected');
                            var p = parseFloat(qbSelectedQuote.unit_price);
                            $('#qb-quote-bound-info')
                                .html('<i class="fa fa-check-circle" style="color:#27ae60;"></i> 已選取 <strong>' + qbSelectedQuote.quote_no + '</strong>' +
                                      (p > 0 ? '，單價 <strong style="color:#27ae60;">$' + formatPrice(p) + '</strong>' : ''))
                                .show();
                            updateQbSummary();
                        });
                        $ql.append($item);
                    });
                } else {
                    $ql.html('<div style="color:#bbb;font-size:11px;text-align:center;padding:8px;">無相關報價單</div>');
                }
            }, 'json').fail(function() {
                $('#qb-quote-loading').hide();
                $ql.html('<div style="color:#e74c3c;font-size:11px;">查詢失敗</div>');
            });
        }

        // 手動搜尋客戶
        function qbSearchCustomer() {
            var term = $('#qb-client-search-input').val().trim();
            if (!term) return;
            var $cl = $('#qb-client-list').html('<span style="font-size:11px;color:#999;"><i class="fa fa-spinner fa-spin"></i> 搜尋中...</span>');
            $('#qb-client-new-area').hide();
            qbSelectedCustomer = null;
            updateQbSummary();
            $.post('', { action: 'search_data', type: 'customer', term: term }, function(res) {
                $cl.empty();
                if (res.success && res.data.length > 0) {
                    if (res.data.length === 1) {
                        qbSelectedCustomer = res.data[0];
                        $cl.html('<span class="label label-success" style="font-size:11px;"><i class="fa fa-check"></i> ' + escapeHtml(res.data[0].customer_id) + ' ' + escapeHtml(res.data[0].customer) + '</span>');
                        $('#qb-client-search-area').hide(); // 找到後隱藏搜尋欄
                        updateQbSummary();
                    } else {
                        res.data.forEach(function(c) {
                            $('<button type="button" class="btn btn-default btn-xs qb-customer-btn" style="margin:2px 2px 2px 0;font-size:11px;"></button>')
                                .text(c.customer_id + ' ' + c.customer)
                                .data('customer', c)
                                .on('click', function() {
                                    qbSelectedCustomer = $(this).data('customer');
                                    $('.qb-customer-btn').removeClass('btn-primary').addClass('btn-default');
                                    $(this).removeClass('btn-default').addClass('btn-primary');
                                    updateQbSummary();
                                })
                                .appendTo($cl);
                        });
                        $('#qb-client-search-area').hide(); // 有多個選項時也不需要再搜尋
                    }
                } else {
                    $cl.html('<div style="color:#999;font-size:11px;padding:3px 0;">找不到符合的客戶</div>');
                    $('#qb-client-new-area').show();
                    $('#qb-client-search-area').show(); // 找不到才保持開放
                }
            }, 'json').fail(function() {
                $cl.html('<div style="color:#e74c3c;font-size:11px;">搜尋失敗，請重試</div>');
            });
        }

        function updateQbSummary() {
            var parts = [];
            if (qbSelectedCustomer) parts.push('客戶 <strong>' + qbSelectedCustomer.customer_id + '</strong>');
            if (qbSelectedPart)     parts.push('料號 <strong>' + qbSelectedPart.D_Setting_Id + '</strong>');
            if (qbSelectedQuote)    parts.push('報價單 <strong>' + qbSelectedQuote.quote_no + '</strong>');
            if (parts.length > 0) {
                $('#qb-summary-text').html(parts.join(' ＋ '));
                $('#qb-selected-summary').show();
                $('#qb-save-btn').show();
            } else {
                $('#qb-selected-summary').hide();
                $('#qb-save-btn').hide();
            }
        }

        function saveQuickBind() {
            var orderId = $('#qb_order_id').val();
            if (!orderId) return;
            if (!qbSelectedCustomer && !qbSelectedPart && !qbSelectedQuote) {
                showToast('請先選取要綁定的項目');
                return;
            }

            $('#qb-save-btn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中...');
            $.post('', {
                action:      'save_order_ids',
                order_id:    orderId,
                customer_pk: qbSelectedCustomer ? qbSelectedCustomer.customer_id : '',
                part_pk:     qbSelectedPart     ? qbSelectedPart.d_id             : '',
                quote_no:    qbSelectedQuote    ? qbSelectedQuote.quote_no         : '',
                unit_price:  qbSelectedQuote    ? qbSelectedQuote.unit_price       : ''
            }, function(res) {
                $('#qb-save-btn').prop('disabled', false).html('<i class="fa fa-save"></i> 確認綁定');
                if (res.success) {
                    var msg = '綁定成功！';
                    if (res.part_customer_fixed) msg += '（料號無客戶，已自動補上客戶綁定）';
                    showToast(msg);
                    $('#quickBindModal').modal('hide');
                    // 即時更新該列圖示
                    var $row = $('tr[data-orderid="' + orderId + '"]');
                    if (qbSelectedCustomer && qbSelectedPart) {
                        $row.find('.fa-unlink, .fa-chain-broken').removeClass('fa-unlink fa-chain-broken').addClass('fa-link').css('color', '#1ABB9C').attr('title', '已綁定').off('click');
                    } else {
                        $row.find('.fa-unlink').removeClass('fa-unlink').addClass('fa-chain-broken').css('color', '#F39C12').attr('title', '部分綁定');
                    }
                    // 即時更新客戶欄文字：有綁定客戶且回傳中文名稱，則更新顯示
                    if (qbSelectedCustomer && res.display_client_name) {
                        var $clientTd = $row.find('td.col-client');
                        var $nameSpan = $clientTd.find('.oc-client-name');
                        if ($nameSpan.length) {
                            // 客戶名稱已包在 .oc-client-name 內：只換這個 span 的文字，
                            // 才不會把綁定圖示與「與來源OP客戶不一致」提示徽章一起清掉
                            $nameSpan.text(res.display_client_name);
                        } else {
                            // 舊版結構備援：保留 td 內的圖示 <i>，只替換文字節點
                            var $icon = $clientTd.find('i').detach();
                            $clientTd.text(res.display_client_name);
                            $clientTd.append($icon);
                        }
                        $clientTd.attr('title', res.display_client_name);
                    }
                    // 齒輪按鈕：料號綁定後將 ⚙! 橘色按鈕換成綠色主檔連結
                    if (qbSelectedPart && qbSelectedPart.d_id) {
                        var $gearBtn = $row.find('button[title="料號未綁定主檔，點此先綁定"]');
                        if ($gearBtn.length) {
                            var $newLink = $('<a target="_blank"></a>')
                                .attr('href', '../../views/pages/master_data_management.php?open_part=' + qbSelectedPart.d_id)
                                .attr('class', $gearBtn.attr('class'))
                                .attr('title', '前往料號主檔編輯')
                                .css({background:'#f0fff8', borderColor:'#1ABB9C', color:'#1ABB9C'})
                                .html('<i class="fa fa-cog"></i>');
                            $gearBtn.replaceWith($newLink);
                        }
                    }
                    // 若綁定報價單更新單價，統計卡片也需刷新
                    if (qbSelectedQuote) {
                        setTimeout(function() { fetchTableData(currentPage); }, 500);
                    }
                } else {
                    showToast('綁定失敗：' + res.message);
                }
            }, 'json').fail(function() {
                $('#qb-save-btn').prop('disabled', false).html('<i class="fa fa-save"></i> 確認綁定');
                showToast('請求失敗，請檢查網路');
            });
        }

        // ── 快速綁定 Modal：Enter 鍵觸發確認綁定 ────────────────────────────
        $('#quickBindModal').on('keydown', function(e) {
            if (e.key !== 'Enter') return;
            var $btn = $('#qb-save-btn');
            if (!$btn.is(':visible') || $btn.prop('disabled')) return;
            // 有多個選項但用戶尚未點選 → 等待用戶選定，不自動觸發
            var customerPending = $('.qb-customer-btn').length > 0 && !qbSelectedCustomer;
            var partPending     = $('.qb-part-btn').length > 0 && !qbSelectedPart;
            if (customerPending || partPending) return;
            // 料號客戶與選定客戶不符 → 禁止 Enter，必須手動確認
            if (qbSelectedCustomer && qbSelectedPart) {
                var partCid     = String(qbSelectedPart.customer_id || '').trim();
                var selectedCid = String(qbSelectedCustomer.customer_id || '').trim();
                if (partCid && selectedCid && partCid !== selectedCid) {
                    showToast('料號所屬客戶與選定客戶不同，請確認後手動點擊「確認綁定」。', 'warning');
                    return;
                }
            }
            e.preventDefault();
            saveQuickBind();
        });

        function updateDesignerDropdown(availableSet) {
            var $select = $('#filter-designer');
            var currentVal = $select.val();
            
            $select.empty();
            $select.append('<option value="">全部設計</option>');
            
            var sortedDesigners = Array.from(availableSet).sort();
            sortedDesigners.forEach(function(name) {
                var opt = $('<option>').val(name).text(name);
                $select.append(opt);
            });
            
            $select.val(currentVal); // 嘗試恢復原本的選擇
        }

        // ── 訂單狀態 UI 控制 ─────────────────────────────────────────────────
        function applyOrderStatusUI(orderStatus) {
            var isLocked = (orderStatus === 6 || orderStatus === 9);
            var isPaused = (orderStatus === 6);
            var isClosed = (orderStatus === 9);
            var $form = $('#newOrderForm');
            $form.find('input[type="text"], input[type="number"], input[type="date"], textarea, select').prop('readonly', isLocked).prop('disabled', isLocked);
            $('#unit_price_input').prop('readonly', isLocked);
            $('#btn-save, #btn-save-copy').prop('disabled', isLocked);
            if (window.canDelete) { $('#btn-delete').prop('disabled', isLocked); }
            if (window.canUpdate || window.canCreate) {
                $('#btn-toggle-paused').show();
                if (isPaused) {
                    $('#btn-toggle-paused').text('解除暫停').removeClass('btn-warning').addClass('btn-default');
                } else {
                    $('#btn-toggle-paused').text('訂單暫停/取消').removeClass('btn-default').addClass('btn-warning');
                }
                $('#btn-toggle-paused').prop('disabled', isClosed);
            } else {
                $('#btn-toggle-paused').hide();
            }
            if (window.canUpdate || window.canCreate) {
                $('#btn-toggle-closed').show();
                if (isClosed) {
                    $('#btn-toggle-closed').text('解除完結').removeClass('btn-danger').addClass('btn-default');
                } else {
                    $('#btn-toggle-closed').text('訂單已完結').removeClass('btn-default').addClass('btn-danger');
                }
                $('#btn-toggle-closed').prop('disabled', isPaused);
            } else {
                $('#btn-toggle-closed').hide();
            }
        }

        function toggleOrderStatus(type) {
            var orderId = $('#hidden_Order_id').val();
            if (!orderId) return;
            var isPaused = ($('#btn-toggle-paused').text() === '解除暫停');
            var isClosed = ($('#btn-toggle-closed').text() === '解除完結');
            var newStatus = '';
            if (type === 'paused') {
                newStatus = isPaused ? '' : '6';
            } else if (type === 'closed') {
                newStatus = isClosed ? '' : '9';
            }
            $.post('', { action: 'save_order_status', order_id: orderId, new_status: newStatus }, function(res) {
                if (res.success) {
                    var newStatusInt = newStatus === '' ? null : parseInt(newStatus);
                    applyOrderStatusUI(newStatusInt);
                    showToast(newStatus === '' ? '已解除狀態' : (newStatus === '6' ? '訂單已暫停/取消' : '訂單已完結'));
                    var $row = $('tr[data-orderid="' + orderId + '"]');
                    var $cell = $row.find('td[name="pmGetCell"]');
                    if (newStatus === '6') {
                        $cell.html('<span style="color:#E67E22;font-size:11px;"><i class="fa fa-pause-circle"></i> ' + (res.modified_fmt || '') + ' 訂單暫停/取消</span>');
                    } else if (newStatus === '9') {
                        if ($cell.find('.closed-label').length === 0) {
                            $cell.append('<div class="closed-label" style="font-size:11px;color:#8e44ad;margin-top:2px;"><i class="fa fa-check-circle"></i> 已結案</div>');
                        }
                    } else {
                        fetchTableData(currentPage);
                    }
                } else {
                    showToast('操作失敗：' + res.message);
                }
            }, 'json');
        }

        var currentStatusCardFilter = null;
        function toggleStatusCard(statusKey, el) {
            var $el = $(el);
            if (currentStatusCardFilter === statusKey) {
                // 取消半高篩選，回到全部
                currentStatusCardFilter = null;
                $el.removeClass('active');
                currentFilters.status = 'all';
                isStatCardFilter = false;
                lockedStats = null;
                $('.stat-card').not('.stat-card-half').removeClass('active');
                $('#stat-card-all').addClass('active');
                // 恢復主卡片可點擊
                $('.stat-card.card-processing, .stat-card.card-communication, .stat-card.card-done').css({'opacity':'','pointer-events':'','cursor':''});
            } else {
                // 啟用半高篩選：清除主卡片 status 篩選（避免衝突），保留 unbound
                currentStatusCardFilter = statusKey;
                currentFilters.status = 'all';
                isStatCardFilter = false;
                lockedStats = null;
                $('.stat-card-half').removeClass('active');
                $el.addClass('active');
                $('.stat-card').not('.stat-card-half').removeClass('active');
                $('#stat-card-all').addClass('active');
            }
            fetchTableData(1);
        }

        function scrollToTop() {
            $('html, body').animate({scrollTop : 0}, 600);
        }

        // ── 開啟客戶設定 Modal ──────────────────────────────────────────────
        // 從快速綁定開啟的情境旗標
        var _qbCustomerNewContext = false;

        // 從快速綁定 Modal 開啟 customerSettingsModal 新建客戶
        function openCustomerSettingsFromQb() {
            _qbCustomerNewContext = true;
            resetCustomerForm();
            // 預填客戶名稱（從快速綁定顯示的原始文字）
            var clientText = $('#qb-client-current').text().replace(/「|」/g, '').trim();
            if (clientText) $('#customer_name_modal').val(clientText);
            // 先隱藏快速綁定 Modal，再開客戶設定 Modal
            $('#quickBindModal').modal('hide');
            $('#quickBindModal').one('hidden.bs.modal', function() {
                $('#customerSettingsModal').modal('show');
            });
        }

        // 客戶設定 Modal 關閉時，若使用者手動取消（非儲存成功回流），清除旗標並重開快速綁定
        $('#customerSettingsModal').on('hidden.bs.modal', function() {
            if (_qbCustomerNewContext) {
                _qbCustomerNewContext = false;
                $('#quickBindModal').modal('show');
            }
        });

        function openCustomerSettingsModal() {
            resetCustomerForm();
            var custPk  = $('#selected_customer_pk').val();
            var custTxt = $('#client_name_input').val().trim();
            if (custPk) {
                // 已綁定：從後端載入完整客戶資料（含地址、電話、傳真）
                $.post('', { action: 'get_all_customers' }, function(res) {
                    if (res.success && res.data) {
                        var found = res.data.find(function(c) { return c.customer_id === custPk; });
                        if (found) {
                            $('#customer_id_modal').val(found.customer_id);
                            $('#customer_id_new').val(found.customer_id).prop('readonly', true);
                            $('#customer_name_modal').val(found.customer || '');
                            $('#customer_address_modal').val(found.customer_address || '');
                            $('#customer_tel_modal').val(found.customer_tel || '');
                            $('#customer_fax_modal').val(found.customer_fax || '');
                        }
                    }
                    $('#customerSettingsModal').modal('show');
                }, 'json');
            } else {
                if (custTxt) $('#customer_name_modal').val(custTxt);
                $('#customerSettingsModal').modal('show');
            }
        }
        function resetCustomerForm() {
            $('#customer_id_modal').val('');
            $('#customerForm')[0].reset();
            $('#customer_id_new').prop('readonly', false);
            $('#customer-modal-error').hide();
        }
        function saveCustomer() {
            var id   = $('#customer_id_modal').val();
            var newId = $('#customer_id_new').val().trim();
            var name = $('#customer_name_modal').val().trim();
            var addr = $('#customer_address_modal').val().trim();
            var tel  = $('#customer_tel_modal').val().trim();
            var fax  = $('#customer_fax_modal').val().trim();
            if (!name) { $('#customer-modal-error').text('客戶名稱不可為空').show(); return; }
            if (!id && !newId) { $('#customer-modal-error').text('新增時客戶代碼不可為空').show(); return; }
            $('#customer-modal-error').hide();
            $.post('', {
                action: 'save_customer',
                customer_id_modal: id,
                customer_id_new:   newId,
                customer_name_modal:    name,
                customer_address_modal: addr,
                customer_tel_modal:     tel,
                customer_fax_modal:     fax
            }, function(res) {
                showToast(res.message || (res.success ? '儲存成功' : '儲存失敗'));
                if (res.success) {
                    resetCustomerForm();
                    var savedId   = id || newId;
                    var savedName = name;

                    // 情境 A：從快速綁定開啟 → 儲存成功後自動帶入快速綁定客戶欄
                    if (_qbCustomerNewContext) {
                        _qbCustomerNewContext = false; // 先清旗標，避免 hidden.bs.modal 再次觸發重開
                        var newCustomer = { customer_id: savedId, customer: savedName };
                        qbSelectedCustomer = newCustomer;
                        // 關閉 customerSettingsModal，重開快速綁定 Modal
                        $('#customerSettingsModal').modal('hide');
                        $('#customerSettingsModal').one('hidden.bs.modal', function() {
                            // 更新客戶欄顯示
                            $('#qb-client-list').html('<span class="label label-success" style="font-size:11px;"><i class="fa fa-check"></i> ' + escapeHtml(savedId) + ' ' + escapeHtml(savedName) + '</span>');
                            $('#qb-client-new-area').hide();
                            updateQbSummary();
                            $('#quickBindModal').modal('show');
                        });
                        return;
                    }

                    // 情境 B：從新增訂單 modal 開啟 → 帶入訂單表單客戶欄
                    if ($('#newOrderModal').hasClass('in')) {
                        if (savedId) {
                            $('#client_name_input').val(savedName);
                            $('#selected_customer_pk').val(savedId);
                            updateIdBadges();
                        }
                    }
                    $('#customerSettingsModal').modal('hide');
                } else {
                    $('#customer-modal-error').text(res.message || '儲存失敗').show();
                }
            }, 'json');
        }

        // ── 開啟料號設定 Modal ──────────────────────────────────────────────
        function openPartSettingsModal() {
            resetPartModal();
            var partPk  = $('#selected_part_pk').val();
            var partTxt = $('#part_id_input').val().trim();

            if (partPk) {
                // 已綁定：從後端載入完整資料（編輯現有料號，不需檢查客戶）
                $.post('', { action: 'get_part_detail', d_id: partPk }, function(res) {
                    if (res.success && res.data) {
                        var p = res.data;
                        $('#d_id_modal_pk').val(p.d_id);
                        $('#part_modal_no').val(p.D_Setting_Id);
                        $('#part_modal_type').val(p.Type || 'N');
                        $('#part_modal_customer_name').val(p.client_name || '');
                        $('#part_modal_customer_id').val(p.Customer_Id || '');
                        $('#part_modal_revision').val(p.Revision || '');
                        $('#part_modal_issue_date').val(p.Issue_Date ? p.Issue_Date.substring(0,10) : '');
                        $('#part_modal_remark').val(p.Remark || '');
                        toggleGearSection(p.Type || 'N');
                        if (p.Type === 'G' && p.gears && p.gears.length > 0) {
                            $('#gear-rows-container').empty();
                            p.gears.forEach(function(g) { addGearRow(g); });
                        }
                    }
                    // ※ 新增：編輯現有料號，客戶欄可編輯
                    setPartModalCustomerReadonly(false);
                    $('#partSettingsModal').modal('show');
                }, 'json');
            } else {
                // ※ 修改：新建料號前先確認已選定客戶ID
                var custPk  = $('#selected_customer_pk').val();
                var custTxt = $('#client_name_input').val().trim();
                if (!custPk) {
                    showToast('請先選定要綁定的客戶（需從建議列表選取並取得客戶ID），再新建料號。');
                    $('#client_name_input').focus();
                    return;
                }
                // 預填目前輸入的料號文字
                if (partTxt) $('#part_modal_no').val(partTxt);
                // 預填客戶（已確認有 PK）
                $('#part_modal_customer_name').val(custTxt);
                $('#part_modal_customer_id').val(custPk);
                // ※ 新增：客戶由系統帶入，設為唯讀
                setPartModalCustomerReadonly(true);
                $('#partSettingsModal').modal('show');
            }
        }

        function resetPartModal() {
            $('#d_id_modal_pk').val('');
            $('#part_modal_no').val('');
            $('#part_modal_type').val('N');
            $('#part_modal_customer_name').val('');
            $('#part_modal_customer_id').val('');
            $('#part_modal_revision').val('');
            $('#part_modal_issue_date').val('');
            $('#part_modal_remark').val('');
            $('#gear-rows-container').empty();
            toggleGearSection('N');
            // 清除錯誤提示
            $('#part-modal-customer-error').hide();
            $('#part-modal-dup-msg').hide();
            // ※ 新增：清除/新增時恢復客戶欄可編輯（此時無自動帶入客戶）
            setPartModalCustomerReadonly(false);
        }

        // ※ 新增：統一控制料號 Modal 客戶欄位唯讀/可編輯
        function setPartModalCustomerReadonly(isReadonly) {
            var $inp = $('#part_modal_customer_name');
            var $btn = $('#part-modal-customer-search-btn');
            if (isReadonly) {
                $inp.prop('readonly', true).css({'background':'#f5f5f5','cursor':'not-allowed'});
                $btn.prop('disabled', true);
            } else {
                $inp.prop('readonly', false).css({'background':'','cursor':''});
                $btn.prop('disabled', false);
            }
        }

        function loadPartModalList(keyword) {
            if (!keyword) { $('#part-modal-list-body').html('<tr><td colspan="4" class="text-center text-muted" style="padding:10px;">（請先輸入料號後儲存）</td></tr>'); return; }
            $.post('', { action: 'search_data', type: 'part', term: keyword }, function(res) {
                var $tb = $('#part-modal-list-body').empty();
                if (res.success && res.data.length > 0) {
                    res.data.forEach(function(p) {
                        $tb.append('<tr>' +
                            '<td>' + escapeHtml(p.D_Setting_Id) + '</td>' +
                            '<td>' + escapeHtml(p.Revision||'') + '</td>' +
                            '<td>' + escapeHtml(p.Client_Name||'') + '</td>' +
                            '<td><button type="button" class="btn btn-xs btn-info" onclick="editPartModalRow(' + p.d_id + ')"><i class="fa fa-pencil"></i></button>' +
                            '<button type="button" class="btn btn-xs btn-danger" style="margin-left:2px;" onclick="deletePartModalRow(' + p.d_id + ')"><i class="fa fa-trash"></i></button></td>' +
                            '</tr>');
                    });
                } else {
                    $tb.html('<tr><td colspan="4" class="text-center text-muted">查無符合料號</td></tr>');
                }
            }, 'json');
        }

        function editPartModalRow(dId) {
            $.post('', { action: 'get_part_detail', d_id: dId }, function(res) {
                if (!res.success) { showToast(res.message || '讀取失敗'); return; }
                var p = res.data;
                $('#d_id_modal_pk').val(p.d_id);
                $('#part_modal_no').val(p.D_Setting_Id);
                $('#part_modal_type').val(p.Type || 'N');
                $('#part_modal_customer_name').val(p.client_name || '');
                $('#part_modal_customer_id').val(p.Customer_Id || '');
                $('#part_modal_revision').val(p.Revision || '');
                $('#part_modal_issue_date').val(p.Issue_Date ? p.Issue_Date.substring(0,10) : '');
                $('#part_modal_remark').val(p.Remark || '');
                toggleGearSection(p.Type || 'N');
                // 載入齒輪資料
                if (p.Type === 'G' && p.gears && p.gears.length > 0) {
                    $('#gear-rows-container').empty();
                    p.gears.forEach(function(g) { addGearRow(g); });
                }
                // ※ 新增：編輯現有料號，客戶欄可編輯
                setPartModalCustomerReadonly(false);
            }, 'json');
        }

        function deletePartModalRow(dId) {
            if (!confirm('確定要刪除此料號嗎？')) return;
            $.post('', { action: 'delete_part', d_id: dId }, function(res) {
                showToast(res.message || (res.success ? '刪除成功' : '刪除失敗'));
                if (res.success) {
                    loadPartModalList($('#part_modal_no').val());
                    resetPartModal();
                }
            }, 'json');
        }

        function savePartModal() {
            var partNo = $('#part_modal_no').val().trim();
            if (!partNo) { showToast('料號不可為空'); return; }

            // ※ 修改：客戶ID 為全面必填
            var custName = $('#part_modal_customer_name').val().trim();
            var custId   = $('#part_modal_customer_id').val().trim();
            if (!custId) {
                // 無論是否有填文字，沒有選定 ID 一律擋住
                var errMsg = custName
                    ? '請從搜尋建議中選取客戶（目前只有輸入文字，尚未綁定客戶ID）'
                    : '客戶為必填，請搜尋並選取要綁定的客戶';
                $('#part-modal-customer-error').text(errMsg).show();
                $('#part_modal_customer_name').focus();
                return;
            }
            $('#part-modal-customer-error').hide();

            var type = $('#part_modal_type').val();
            var gears = [];
            if (type === 'G') {
                // 齒輪必填驗證
                var gearRows = $('#gear-rows-container .gear-row');
                if (gearRows.length === 0) { showToast('工件種類為齒輪時，至少需填寫一組齒輪詳細資料'); return; }
                var gearValid = true;
                var gearErrMsg = '';
                gearRows.each(function(i) {
                    var $r = $(this);
                    var gt  = $r.find('.gear-type').val();
                    var mod = $r.find('.gear-module').val().trim();
                    var tth = $r.find('.gear-teeth').val().trim();
                    var fw  = $r.find('.gear-face-width').val().trim();
                    var wl  = $r.find('.gear-length').val().trim();
                    if (!gt)  { gearErrMsg = '第' + (i+1) + '組齒輪：齒輪類型為必填'; gearValid = false; return false; }
                    if (!mod) { gearErrMsg = '第' + (i+1) + '組齒輪：模數為必填'; gearValid = false; return false; }
                    if (!tth) { gearErrMsg = '第' + (i+1) + '組齒輪：齒數為必填'; gearValid = false; return false; }
                    if (!fw)  { gearErrMsg = '第' + (i+1) + '組齒輪：齒寬為必填'; gearValid = false; return false; }
                    if (!wl)  { gearErrMsg = '第' + (i+1) + '組齒輪：工件總長為必填'; gearValid = false; return false; }
                    // 螺旋齒輪需有螺旋角
                    if (gt.indexOf('螺旋') !== -1) {
                        var helixVal = $r.find('.hidden-helix-val').val().trim();
                        if (!helixVal || isNaN(parseFloat(helixVal))) {
                            gearErrMsg = '第' + (i+1) + '組齒輪（螺旋）：螺旋角為必填';
                            gearValid = false;
                            return false;
                        }
                    }
                });
                if (!gearValid) { showToast(gearErrMsg); return; }

                $('#gear-rows-container .gear-row').each(function() {
                    var $r = $(this);
                    gears.push({
                        Gear_Type:        $r.find('.gear-type').val(),
                        Module:           $r.find('.gear-module').val(),
                        Teeth:            $r.find('.gear-teeth').val(),
                        Face_Width:       $r.find('.gear-face-width').val(),
                        Helix_Angle:      $r.find('.hidden-helix-val').val(),
                        Helix_Angle_Str:  $r.find('.hidden-helix-str').val(),
                        Helix_Direction:  $r.find('.gear-direction').val(),
                        Profile_Shift_X:  $r.find('.gear-shift-x').val(),
                        Pressure_Angle:   $r.find('.gear-pressure-angle').val(),
                        Workpiece_Length: $r.find('.gear-length').val(),
                        Spec_No:          '',
                        Remark_Gear:      $r.find('.gear-remark').val()
                    });
                });
            }
            $.post('', {
                action:      'save_part_info',
                d_id:        $('#d_id_modal_pk').val(),
                part_no:     partNo,
                customer_id: $('#part_modal_customer_id').val(),
                type:        type,
                revision:    $('#part_modal_revision').val(),
                issue_date:  $('#part_modal_issue_date').val(),
                remark:      $('#part_modal_remark').val(),
                gears:       JSON.stringify(gears)
            }, function(res) {
                if (res.duplicate) {
                    // ※ 重複建檔：顯示明顯警告，取消寫入（後端已擋住）
                    $('#part-modal-dup-msg span').text(res.message);
                    $('#part-modal-dup-msg').show();
                    return;
                }
                $('#part-modal-dup-msg').hide();
                showToast(res.message || (res.success ? '儲存成功' : '儲存失敗'));
                if (res.success) {

                    // ※ 修改：若是從快速綁定 Modal 開啟，儲存成功後查詢新料號 PK，
                    //         自動帶入快速綁定並重新開啟快速綁定 Modal
                    if (_qbPartNewContext) {
                        _qbPartNewContext = false;
                        $.post('', { action: 'search_data', type: 'part', term: partNo }, function(r2) {
                            var found = null;
                            if (r2.success && r2.data.length > 0) {
                                found = r2.data.find(function(x) { return x.D_Setting_Id === partNo; });
                                if (!found) found = r2.data[0]; // fallback 取第一筆
                            }
                            // 關閉料號設定 Modal，開啟快速綁定 Modal
                            $('#partSettingsModal').modal('hide');
                            $('#partSettingsModal').one('hidden.bs.modal', function() {
                                if (found) {
                                    // 自動選取新建的料號
                                    qbSelectedPart = { d_id: found.d_id, D_Setting_Id: found.D_Setting_Id, Spec_No: found.Spec_No || '', client_name: found.Client_Name || '' };
                                    // 更新料號列表顯示為已選取
                                    var $pl = $('#qb-part-list');
                                    $pl.html('<span class="label label-success" style="font-size:11px;"><i class="fa fa-check"></i> ' + escapeHtml(found.D_Setting_Id) + (found.Client_Name ? ' (' + escapeHtml(found.Client_Name) + ')' : '') + '</span>');
                                    $('#qb-part-new-area').hide();
                                }
                                updateQbSummary();
                                $('#quickBindModal').modal('show');
                            });
                        }, 'json');
                        return;
                    }

                    // 若在新增訂單 modal 中，帶入新料號
                    if ($('#newOrderModal').hasClass('in')) {
                        $('#part_id_input').val(partNo);
                        // 重新查詢帶入 d_id
                        $.post('', { action: 'search_data', type: 'part', term: partNo }, function(r2) {
                            if (r2.success && r2.data.length > 0) {
                                var found = r2.data.find(function(x) { return x.D_Setting_Id === partNo; });
                                if (found) {
                                    $('#selected_part_pk').val(found.d_id);
                                    _lastLoadedPart = '';
                                    updateIdBadges();
                                }
                            }
                        }, 'json');
                    }
                }
            }, 'json');
        }

        // ── 齒輪區塊顯示切換 ────────────────────────────────────────────────
        function toggleGearSection(type) {
            if (type === 'G') {
                $('#gear-detail-section').show();
                if ($('#gear-rows-container .gear-row').length === 0) addGearRow();
            } else {
                $('#gear-detail-section').hide();
            }
        }

        // ── 新增齒輪列（符合原始 modal_part_setting 完整欄位）──────────────
        var _gearRowIdx = 0;
        function addGearRow(data) {
            data = data || {};
            var idx = _gearRowIdx++;
            var gearType = data.Gear_Type || '';
            var module   = data.Module || '';
            var teeth    = data.Teeth  || '';
            var pa       = data.Pressure_Angle || '';
            var fw       = data.Face_Width || '';
            var wl       = data.Workpiece_Length || '';
            var remark   = data.Remark_Gear || '';
            var helixAngle = (data.Helix_Angle !== undefined && data.Helix_Angle !== null && data.Helix_Angle !== '') ? parseFloat(data.Helix_Angle) : '';
            var helixStr   = data.Helix_Angle_Str || '';
            var direction  = data.Helix_Direction || '';
            var shiftX     = (data.Profile_Shift_X !== undefined && data.Profile_Shift_X !== null && data.Profile_Shift_X !== '') ? parseFloat(data.Profile_Shift_X) : '';
            var showHelix  = String(gearType).indexOf('螺旋') !== -1;

            var typeOpts = ['', '直齒', '螺旋', '傘齒', '蝸桿', '蝸輪'].map(function(t) {
                return '<option value="' + t + '"' + (gearType === t ? ' selected' : '') + '>' + (t || '請選擇') + '</option>';
            }).join('');

            var dirOpts = [['','旋向'],['RH','RH(右)'],['LH','LH(左)']].map(function(d) {
                return '<option value="' + d[0] + '"' + (direction === d[0] ? ' selected' : '') + '>' + d[1] + '</option>';
            }).join('');

            var html = '<div class="gear-row" style="padding:12px;border:1px solid #ddd;border-radius:5px;margin-bottom:10px;background:#f9f9f9;" data-idx="' + idx + '">' +
                '<div class="row">' +
                '<div class="col-md-3 form-group"><label style="font-size:12px;">齒輪類型</label><select class="form-control input-sm gear-type" onchange="onGearTypeChange(this)">' + typeOpts + '</select></div>' +
                '<div class="col-md-3 form-group"><label style="font-size:12px;">模數 (Module)</label><input type="text" class="form-control input-sm gear-module" value="' + escapeHtml(module) + '" placeholder="例如 M2.0"></div>' +
                '<div class="col-md-3 form-group"><label style="font-size:12px;">齒數 (Teeth)</label><input type="number" class="form-control input-sm gear-teeth" value="' + escapeHtml(teeth) + '"></div>' +
                '<div class="col-md-3 form-group"><label style="font-size:12px;">壓力角 (PA)</label><input type="text" class="form-control input-sm gear-pressure-angle" value="' + escapeHtml(pa) + '" placeholder="例如 20"></div>' +
                '</div>' +
                '<div class="row">' +
                '<div class="col-md-3 form-group"><label style="font-size:12px;">齒寬 (W)</label><input type="number" step="0.01" class="form-control input-sm gear-face-width" value="' + escapeHtml(fw) + '" placeholder="mm"></div>' +
                '<div class="col-md-3 form-group"><label style="font-size:12px;">工件總長 (L)</label><input type="number" step="0.01" class="form-control input-sm gear-length" value="' + escapeHtml(wl) + '" placeholder="mm"></div>' +
                '<div class="col-md-3 form-group"><label style="font-size:12px;">轉位係數 X</label><input type="number" step="any" class="form-control input-sm gear-shift-x" value="' + (shiftX !== '' ? shiftX : '') + '" placeholder="例如 0.315"></div>' +
                '</div>' +
                // 螺旋角區塊（僅齒輪類型含「螺旋」時顯示）
                '<div class="helix-angle-group" style="display:' + (showHelix ? 'block' : 'none') + ';background:#e9ecef;padding:8px;border-radius:4px;margin-bottom:8px;">' +
                '<label style="font-size:12px;">螺旋角</label>' +
                '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:5px;">' +
                '<select class="form-control input-sm gear-direction" style="width:80px;">' + dirOpts + '</select>' +
                '<div class="btn-group btn-group-xs">' +
                '<button type="button" class="btn btn-default btn-mode-dec active">十進位</button>' +
                '<button type="button" class="btn btn-default btn-mode-dms">度分秒</button>' +
                '</div></div>' +
                '<div class="mode-decimal"><input type="number" step="any" class="form-control input-sm gear-helix-val" value="' + (helixAngle !== '' ? helixAngle : '') + '" placeholder="例如 15.5"></div>' +
                '<div class="mode-dms" style="display:none;"><div style="display:flex;align-items:center;gap:3px;">' +
                '<input type="number" class="form-control input-sm dms-d" placeholder="度" style="width:55px;">°' +
                '<input type="number" class="form-control input-sm dms-m" placeholder="分" style="width:55px;">\''+
                '<input type="number" class="form-control input-sm dms-s" placeholder="秒" style="width:55px;">"' +
                '</div></div>' +
                '<input type="hidden" class="hidden-helix-val" value="' + (helixAngle !== '' ? helixAngle : '') + '">' +
                '<input type="hidden" class="hidden-helix-str" value="' + escapeHtml(helixStr) + '">' +
                '</div>' +
                '<div class="row">' +
                '<div class="col-md-12 form-group"><label style="font-size:12px;">備註</label>' +
                '<div style="display:flex;gap:6px;align-items:center;">' +
                '<input type="text" class="form-control input-sm gear-remark" value="' + escapeHtml(remark) + '">' +
                '<button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest(\'.gear-row\').remove();" style="white-space:nowrap;"><i class="fa fa-trash"></i> 刪除</button>' +
                '</div></div>' +
                '</div>' +
                '</div>';

            $('#gear-rows-container').append(html);

            // 如果有度分秒格式的螺旋角，自動切到度分秒模式
            if (helixStr && (helixStr.indexOf('°') !== -1 || helixStr.indexOf("'") !== -1)) {
                var $lastRow = $('#gear-rows-container .gear-row').last();
                $lastRow.find('.btn-mode-dms').trigger('click');
                var parts = helixStr.split('°');
                var deg = parts[0] || '';
                var rest = parts[1] || '';
                var mparts = rest.split("'");
                var min = mparts[0] || '';
                var sec = (mparts[1] || '').replace('"','');
                $lastRow.find('.dms-d').val(deg);
                $lastRow.find('.dms-m').val(min);
                $lastRow.find('.dms-s').val(sec);
            }
        }

        // 齒輪類型改變 → 螺旋角顯示/隱藏
        $(document).on('change', '.gear-type', function() {
            var $row = $(this).closest('.gear-row');
            if ($(this).val().indexOf('螺旋') !== -1) {
                $row.find('.helix-angle-group').slideDown(150);
            } else {
                $row.find('.helix-angle-group').slideUp(150);
            }
        });
        function onGearTypeChange(el) { $(el).trigger('change'); }

        // 螺旋角模式切換
        $(document).on('click', '.btn-mode-dec', function() {
            var $g = $(this).closest('.helix-angle-group');
            $g.find('.mode-decimal').show();
            $g.find('.mode-dms').hide();
            $(this).addClass('active').siblings().removeClass('active');
        });
        $(document).on('click', '.btn-mode-dms', function() {
            var $g = $(this).closest('.helix-angle-group');
            $g.find('.mode-decimal').hide();
            $g.find('.mode-dms').show();
            $(this).addClass('active').siblings().removeClass('active');
        });

        // 十進位螺旋角輸入 → 更新隱藏欄位
        $(document).on('input', '.gear-helix-val', function() {
            var val = $(this).val();
            var $g = $(this).closest('.helix-angle-group');
            $g.find('.hidden-helix-val').val(val);
            $g.find('.hidden-helix-str').val(val);
        });

        // 度分秒輸入 → 計算並更新隱藏欄位
        $(document).on('input', '.dms-d, .dms-m, .dms-s', function() {
            var $g = $(this).closest('.helix-angle-group');
            var d = parseFloat($g.find('.dms-d').val()) || 0;
            var m = parseFloat($g.find('.dms-m').val()) || 0;
            var s = parseFloat($g.find('.dms-s').val()) || 0;
            var decimal = d + (m / 60) + (s / 3600);
            $g.find('.hidden-helix-val').val(decimal.toFixed(6));
            $g.find('.hidden-helix-str').val(d + '°' + m + "'" + s + '"');
        });

        // ── 料號 Modal 客戶搜尋 ──────────────────────────────────────────────
        function searchPartModalCustomer() {
            var kw = $('#part_modal_customer_name').val().trim();
            if (!kw) { $('#part_modal_customer_id').val(''); return; }
            $.post('', { action: 'search_customers', keyword: kw }, function(res) {
                var $sug = $('#part-modal-customer-suggestions').empty();
                if (res.success && res.data.length > 0) {
                    res.data.forEach(function(c) {
                        $('<div class="suggestion-item"></div>')
                            .text(c.customer_id + ' - ' + c.customer)
                            .on('click', function() {
                                $('#part_modal_customer_name').val(c.customer);
                                $('#part_modal_customer_id').val(c.customer_id);
                                $sug.hide().empty();
                            })
                            .appendTo($sug);
                    });
                    $sug.show();
                } else {
                    $sug.html('<div class="suggestion-item" style="color:#999;">找不到客戶</div>').show();
                }
            }, 'json');
        }
        // partSettingsModal：Enter 鍵行為
        $('#partSettingsModal').on('keydown', function(e) {
            if (e.key !== 'Enter') return;
            var $active = $(document.activeElement);
            // textarea → 保持預設換行
            if ($active.is('textarea')) return;
            // 客戶名稱欄且 ID 尚未選定 → 觸發搜尋
            if ($active.is('#part_modal_customer_name') && !$('#part_modal_customer_id').val().trim()) {
                e.preventDefault();
                searchPartModalCustomer();
                return;
            }
            // 其他欄位：客戶 ID + 名稱都已填 → 儲存
            var custId   = $('#part_modal_customer_id').val().trim();
            var custName = $('#part_modal_customer_name').val().trim();
            if (custId && custName) {
                e.preventDefault();
                savePartModal();
            }
        });

        // 客戶輸入框即時搜尋，清空時也清除 ID
        $(document).on('input', '#part_modal_customer_name', function() {
            var kw = $(this).val().trim();
            if (!kw) {
                $('#part_modal_customer_id').val('');
                $('#part-modal-customer-suggestions').hide().empty();
            } else {
                $('#part_modal_customer_id').val(''); // 輸入時先清除ID，等選取後再設定
                searchPartModalCustomer();
            }
        });

        // ── 未綁定OP/單價為0 快速篩選 toggle ────────────────────────────────
        function toggleUnboundOp(el) {
            currentFilters.unbound_op = !currentFilters.unbound_op;
            if (currentFilters.unbound_op) {
                $(el).addClass('active');
                $('#unbound-banner-type').text('單價為0');
                $('#unbound-filter-banner').show();
            } else {
                $(el).removeClass('active');
                if (!currentFilters.unbound) $('#unbound-filter-banner').hide();
            }
            isStatCardFilter = false;
            lockedStats = null;
            fetchTableData(1);
        }

        // ── 數量超出區間（OP轉訂單超出階梯區間，待補報價單）快速篩選 toggle ──
        function toggleQtyOver(el) {
            currentFilters.qty_over = !currentFilters.qty_over;
            if (currentFilters.qty_over) {
                $(el).css({'font-weight':'700'});
                $('#unbound-banner-type').text('數量超出區間（待補報價單）');
                $('#unbound-filter-banner').show();
            } else {
                $(el).css({'font-weight':''});
                if (!currentFilters.unbound && !currentFilters.unbound_op) $('#unbound-filter-banner').hide();
            }
            isStatCardFilter = false;
            lockedStats = null;
            fetchTableData(1);
        }

        // ── 橫幅右側「取消篩選」按鈕 ────────────────────────────────────────
        function clearUnboundFilter() {
            currentFilters.unbound    = false;
            currentFilters.unbound_op = false;
            currentFilters.qty_over   = false;
            $('#filter-unbound').removeClass('btn-primary').addClass('btn-warning');
            $('#stat-card-unbound-op').removeClass('active');
            $('#count-qty-over-note').css({'font-weight':''});
            $('#unbound-filter-banner').hide();
            isStatCardFilter = false;
            lockedStats = null;
            fetchTableData(1);
        }

        function copyToClipboard(text, el) {
            var $temp = $("<input>");
            $("body").append($temp);
            $temp.val(text).select();
            document.execCommand("copy");
            $temp.remove();
            
            var originalClass = $(el).attr('class');
            $(el).removeClass('fa-copy').addClass('fa-check text-success');
            setTimeout(function() {
                $(el).attr('class', originalClass);
            }, 1000);
        }

        // --- Inline Editing & Status Functions ---

        function autoResize(textarea) {
            var MAX_LINES = 5;
            textarea.style.height = 'auto';
            var fullHeight = textarea.scrollHeight;
            var lineHeight = parseFloat(getComputedStyle(textarea).lineHeight) || 18;
            var paddingV = 8; // 上下 padding 各4px
            var maxHeight = Math.ceil(lineHeight * MAX_LINES) + paddingV;
            var isFocused = (document.activeElement === textarea);
            var overflowing = fullHeight > maxHeight;
            var targetHeight = (overflowing && !isFocused) ? maxHeight : fullHeight;
            textarea.style.height = targetHeight + 'px';
            textarea.style.overflowY = (overflowing && !isFocused) ? 'auto' : 'hidden';
            var wrap = textarea.closest('.textarea-wrap');
            if (wrap) wrap.classList.toggle('has-more', overflowing && !isFocused);
        }

        function handleKeyDown(event, textarea, orderId) {
            var key = event.key || event.keyCode;
            if ((key === "Enter" || key === 13) && event.shiftKey) {
                // Allow new line
            } else if ((key === "Enter" || key === 13) && !event.shiftKey) {
                event.preventDefault();
                var currentVal = textarea.value;
                var origVal = textarea.getAttribute("data-orig") || "";
                if (currentVal !== origVal) {
                    updateOrderNote(textarea, orderId);
                }
            }
        }

        function updateOrderNote(textarea, orderId) {
            var note = textarea.value;
            var noteType = textarea.name; // "Order_ps" or "ateNote"
            
            $.post("../../src/store/_update_order_Tdata.php", {
                orderId: orderId,
                noteType: noteType,
                note: note
            }, function(response) {
                // Simple visual feedback
                $(textarea).css('background-color', '#d4edda');
                textarea.setAttribute("data-orig", note);
                setTimeout(function() {
                    $(textarea).css('background-color', 'transparent');
                }, 1000);
            });
        }

        // --- Status Update Functions ---

        function updateInReview(orderId) {
            var cell = $(`tr[data-orderid='${orderId}'] td[name='pmGetCell']`);
            cell.html('<span class="loading-spinner">處理中...</span>');
            
            $.post("../../src/store/_update_inReview.php", { Order_id: orderId, action: 'set_in_review' }, function(res) {
                var data = (typeof res === 'object') ? res : JSON.parse(res);
                if (data.success) {
                    var html = `<span style="color: green; font-size: 11px; margin-right: 3px;">${data.in_review_date}審圖</span>`;
                    if (window.canUpdatePmget) {
                        html += `<button type="button" class="btn btn-xs btn-danger" style="padding: 1px 5px; font-size: 11px;" onclick="cancelInReview('${orderId}')">X</button>
                                 <button type="button" class="btn btn-warning btn-xs" style="padding: 2px 6px; font-size: 11px;" onclick="updatePmGet('${orderId}')">轉生管</button>`;
                    }
                    cell.html(html + (cell.closest('tr').attr('data-bom-icon') || ''));
                } else {
                    alert('Error: ' + data.message);
                    location.reload();
                }
            }).fail(function() {
                alert('連線失敗，請重試');
                location.reload();
            });
        }

        function cancelInReview(orderId) {
            var cell = $(`tr[data-orderid='${orderId}'] td[name='pmGetCell']`);
            cell.html('<span class="loading-spinner">處理中...</span>');
            
            $.post("../../src/store/_update_inReview.php", { Order_id: orderId, action: 'cancel_in_review' }, function(res) {
                var data = (typeof res === 'object') ? res : JSON.parse(res);
                if (data.success) {
                    var html = '';
                    if (window.canUpdatePmget) {
                        html = `<button type="button" class="btn btn-xs btn-success" style="padding: 2px 6px; font-size: 11px;" onclick="updateInReview('${orderId}')">審圖</button>
                                <button type="button" class="btn btn-warning btn-xs" style="padding: 2px 6px; font-size: 11px;" onclick="updatePmGet('${orderId}')">轉生管</button>`;
                    } else {
                        html = '<span style="font-size: 12px; color: #999;">批圖中</span>';
                    }
                    cell.html(html + (cell.closest('tr').attr('data-bom-icon') || ''));
                } else {
                    alert('Error: ' + data.message);
                    location.reload();
                }
            }).fail(function() {
                alert('連線失敗，請重試');
                location.reload();
            });
        }

        function updatePmGet(orderId) {
            var cell = $(`tr[data-orderid='${orderId}'] td[name='pmGetCell']`);
            cell.html('<span class="loading-spinner">處理中...</span>');
            
            $.post("../../src/store/simple_update_pmGet.php", { Order_id: orderId }, function(res) {
                var data = (typeof res === 'object') ? res : JSON.parse(res);
                if (data.success) {
                    var html = '';
                    if (window.canUpdatePmget) {
                        html += `<button type="button" class="btn btn-xs btn-danger" style="padding: 1px 5px; font-size: 11px;" onclick="cancelPmGet('${orderId}')">X</button> `;
                    }
                    html += `<span style="font-size: 12px;">${data.pmGet_date}</span>`;
                    cell.html(html + (cell.closest('tr').attr('data-bom-icon') || ''));
                } else {
                    alert('Error: ' + data.message);
                    location.reload();
                }
            }).fail(function() {
                alert('連線失敗，請重試');
                location.reload();
            });
        }

        function cancelPmGet(orderId) {
            var cell = $(`tr[data-orderid='${orderId}'] td[name='pmGetCell']`);
            cell.html('<span class="loading-spinner">處理中...</span>');
            
            $.post("../../src/store/simple_update_pmGet.php", { Order_id: orderId, action: 'cancel' }, function(res) {
                var data = (typeof res === 'object') ? res : JSON.parse(res);
                if (data.success) {
                    var html = '';
                    if (window.canUpdatePmget) {
                        html = `<button type="button" class="btn btn-xs btn-success" style="padding: 2px 6px; font-size: 11px;" onclick="updateInReview('${orderId}')">審圖</button>
                                <button type="button" class="btn btn-warning btn-xs" style="padding: 2px 6px; font-size: 11px;" onclick="updatePmGet('${orderId}')">轉生管</button>`;
                    } else {
                        html = '<span style="font-size: 12px; color: #999;">批圖中</span>';
                    }
                    cell.html(html + (cell.closest('tr').attr('data-bom-icon') || ''));
                } else {
                    alert('Error: ' + data.message);
                    location.reload();
                }
            }).fail(function() {
                alert('連線失敗，請重試');
                location.reload();
            });
        }

        // --- File Viewing ---

        // 開啟圖面（有圖面資料）→ 在新分頁/彈窗中開啟 bom_viewer.php
        function openProductFiles(pid, pk) { openPartDrawing(pid, pk); }  // 舊名相容
        // pk＝d_setting.d_id（整數 PK）：同名料號可能有多筆主檔（不同客戶／版次），不指名會混在一起
        function openPartDrawing(pid, pk) {
            if (!pid && !pk) return;
            var w = screen.availWidth, h = screen.availHeight;
            var pw = Math.min(1400, Math.round(w * 0.85));
            var ph = Math.min(900,  Math.round(h * 0.88));
            var pl = Math.round((w - pw) / 2);
            var pt = Math.round((h - ph) / 2);
            var q = pk ? ('?pk=' + encodeURIComponent(pk)) : ('?d_id=' + encodeURIComponent(pid));
            window.open(
                '../../views/pm/bom_viewer.php' + q,
                'drawing_' + (pk || pid),
                'width=' + pw + ',height=' + ph + ',left=' + pl + ',top=' + pt
                    + ',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no'
            );
        }

        // 無圖面資料提示（5 秒後自動消失）
        function showNoDrawingToast() {
            showToast('此料號無圖面／報價／訂單附件／其他附件資料', 'warning');
        }

        function showFile(el, path, type) {
            if (el) {
                $('.file-item').removeClass('active');
                $(el).addClass('active');
            }
            
            var html = '';
            if (type === 'pdf') {
                html = `<iframe src="${path}" style="width:100%; height:100%; border:none;"></iframe>`;
                $('#bom-file-viewer').html(html);
                // Remove listeners for PDF
                $('#bom-file-viewer').off('wheel');
                $(document).off('.bomview');
            } else {
                // Reset state
                imgState = { scale: 1, x: 0, y: 0, isDragging: false, startX: 0, startY: 0 };
                
                // Ensure viewer is centered and full size
                $('#bom-file-viewer').css({
                    'width': '100%',
                    'height': '100%',
                    'display': 'flex',
                    'justify-content': 'center',
                    'align-items': 'center'
                });

                var img = new Image();
                img.src = path;
                // Initial 90% size
                img.style.width = "90%"; 
                img.style.height = "auto";
                img.style.cursor = "grab";
                img.style.transformOrigin = "center center";
                img.style.transition = "transform 0.05s linear";
                img.id = "bom-preview-image";
                img.ondragstart = function() { return false; };
                
                $('#bom-file-viewer').html('').append(img);
                
                var $container = $('#bom-file-viewer');

                // Wheel zoom
                $container.off('wheel').on('wheel', function(e) {
                    if ($('#bom-preview-image').length === 0) return;
                    e.preventDefault();
                    var delta = e.originalEvent.deltaY;
                    var step = 0.1;
                    if (delta < 0) {
                        imgState.scale += step;
                    } else {
                        imgState.scale -= step;
                        if (imgState.scale < 0.1) imgState.scale = 0.1;
                    }
                    updateImageTransform();
                });

                // Dragging
                var $img = $('#bom-preview-image');
                $img.on('mousedown', function(e) {
                    imgState.isDragging = true;
                    imgState.startX = e.clientX - imgState.x;
                    imgState.startY = e.clientY - imgState.y;
                    $(this).css('cursor', 'grabbing');
                    $(this).css('transition', 'none'); 
                });

                $(document).off('mousemove.bomview').on('mousemove.bomview', function(e) {
                    if (!imgState.isDragging) return;
                    e.preventDefault();
                    imgState.x = e.clientX - imgState.startX;
                    imgState.y = e.clientY - imgState.startY;
                    updateImageTransform();
                });

                $(document).off('mouseup.bomview').on('mouseup.bomview', function() {
                    if (imgState.isDragging) {
                        imgState.isDragging = false;
                        $('#bom-preview-image').css('cursor', 'grab');
                        $('#bom-preview-image').css('transition', 'transform 0.05s linear');
                    }
                });
            }
        }

        function updateImageTransform() {
            $('#bom-preview-image').css('transform', `translate(${imgState.x}px, ${imgState.y}px) scale(${imgState.scale})`);
        }

        // 編輯訂單跳窗：客戶欄下方的「與來源OP單客戶不一致」提示（2026-08-28）
        // 純提示，**不會**自動把欄位改成 OP 的客戶——要不要改由使用者按下同步決定。
        function renderOpCustDiffHint(data) {
            var $h = $('#op-cust-diff-hint');
            if (!$h.length) return;
            if (!data || !data.op_mismatch) { $h.hide().empty(); return; }
            var oid  = parseInt(data.Order_id) || 0;
            var canU = <?= json_encode((bool)$can_update) ?>;   // 與列表徽章用同一個 PHP 權限判定
            $h.html('<i class="fa fa-exclamation-triangle"></i> 來源 ' + escapeHtml(data.quote_no || 'OP')
                  + ' 目前的客戶是「<strong>' + escapeHtml(data.op_client_name || '') + '</strong>」，與本訂單不一致'
                  + (canU && oid ? ' <a href="javascript:void(0)" onclick="syncQuoteCustomer(' + oid + ')" style="color:#DD5138;font-weight:600;">［同步過來］</a>' : ''))
              .show();
        }

        // --- New Order Modal Functions ---
        function openNewOrderModal() {
            $('#newOrderForm')[0].reset();
            $('#op-cust-diff-hint').hide().empty();
            $('#hidden_Order_id').val('');
            $('#selected_customer_pk').val('');
            $('#selected_part_pk').val('');
            $('#selected_part_drawing_no').val('');
            $('#bound_quote_item_id').val('');
            $('#part-stock-panel').hide();
            $('#hidden_quote_no').val('');
            _lastLoadedPart = '';
            _boundQuotePrice = null;
            _boundQuoteQty = null;

            // 重置右側面板
            $('#panel-right-content').hide();
            $('#panel-quotes-placeholder').show();
            $('#quote-list').empty();
            $('#quote-bound-info').hide();
            $('#btn-clear-quote').hide();
            $('#price-lock-icon').hide();
            $('#price-source').text('');
            $('#qty-warn').hide();
            $('#unit_price_input').prop('readonly', false).css({'background':'','color':'','fontWeight':''});
            $('#shipment-history-list').html('');

            // 重置 badge
            $('#customer-id-badge,#customer-id-missing,#part-id-badge,#part-id-missing,#part-drawing-no-badge,#btn-quick-add-customer,#btn-quick-add-part').hide();

            $('#btn-save-copy').text('新增並複製');
            $('#btn-save').text('確認新增');
            if (window.canDelete) { $('#btn-delete').hide(); }
            $('.modal-title').html('<i class="fa fa-plus-circle"></i> 新增訂單');
            $('#btn-toggle-paused').hide();
            $('#btn-toggle-closed').hide();
            $('#btn-open-split').hide();

            var today = new Date().toISOString().split('T')[0];
            $('input[name="orderindate"]').val(today);
            $('input[name="datepicker_ate"]').val(today);

            setUrgentFlag(false);

            // 附件：新增模式尚無 Order_id，先產生暫存批次碼，存檔後由後端歸屬到新訂單
            $('#order_attach_batch_key').val(orderAttachNewBatchKey());
            orderAttachRefresh();

            $('#newOrderModal').modal('show');
        }

        // 急件旗標（使用者明確要求，2026-09-03）
        // 只是一個標記，不影響任何既有流程；效果有兩個：(1)篩選「批圖中」時排最上方
        // （多筆急件依接單日由新到舊）(2)清單該列淺暖粉紅底色＋「急件」文字標籤。
        // 存檔走既有 or_new/or_update（後端已有 ot_edit 權限守門），不另開 API。
        function setUrgentFlag(on) {
            $('#hidden_is_urgent').val(on ? '1' : '0');
            var $b = $('#btn-toggle-urgent');
            if (on) {
                $b.css({ 'background': '#F0A24B', 'border-color': '#D98B33', 'color': '#4A2A0A', 'font-weight': '700' })
                  .html('<i class="fa fa-bolt"></i> 急件（點此取消）');
            } else {
                $b.css({ 'background': '#fff', 'border-color': '#ccc', 'color': '#777', 'font-weight': '400' })
                  .html('<i class="fa fa-bolt"></i> 設為急件');
            }
        }
        function toggleUrgentFlag() { setUrgentFlag($('#hidden_is_urgent').val() !== '1'); }

        function editOrder(orderId) {
            $.post('', { action: 'get_order_detail', order_id: orderId }, function(res) {
                if (!res.success) { alert('讀取資料失敗：' + (res.message || '')); return; }
                var data = res.data;
                var form = $('#newOrderForm');
                form.find('input[type="text"], input[type="number"], input[type="date"], textarea, select').val('');

                $('#hidden_Order_id').val(data.Order_id || '');
                $('#selected_customer_pk').val(data.Client_name_ID || '');
                $('#selected_part_pk').val(data.d_id_ID || '');
                $('#selected_part_drawing_no').val(data.Drawing_No || '');
                $('#bound_quote_item_id').val(data.quote_item_id || '');
                $('#hidden_quote_no').val(data.quote_no || '');

                form.find('input[name="OrderNo"]').val(data.OrderNo || '');
                form.find('input[name="Client_Name"]').val(data.Client_Name_Display || data.Client_Name || '');
                renderOpCustDiffHint(data);
                form.find('input[name="Client_OrderNo"]').val(data.Client_OrderNo || '');
                form.find('input[name="d_id"]').val(data.d_id || '');
                form.find('input[name="Process"]').val(data.Process || '');
                form.find('input[name="Qty"]').val(data.Qty || '');
                // 單價 debug + 帶入
                console.log('[editOrder] unit_price raw:', data.unit_price, 'source:', data.unit_price_source, 'type:', typeof data.unit_price);
                var priceVal = (data.unit_price !== null && data.unit_price !== '' && parseFloat(data.unit_price) > 0)
                    ? formatPrice(parseFloat(data.unit_price)) : '';
                console.log('[editOrder] priceVal:', priceVal);
                $('#unit_price_input')
                    .val(priceVal)
                    .prop('readonly', false).css({'background':'','color':'','fontWeight':''});
                form.find('input[name="drop_zone"]').val(data.drop_zone || '');
                form.find('input[name="Containers"]').val(data.Containers || '');
                form.find('textarea[name="Order_ps"]').val(data.Order_ps || '');
                if (data.orderindate)    form.find('input[name="orderindate"]').val(data.orderindate);
                if (data.orderDdate)     form.find('input[name="orderDdate"]').val(data.orderDdate);
                if (data.ate)            form.find('select[name="ate"]').val(data.ate);
                if (data.datepicker_ate) form.find('input[name="datepicker_ate"]').val(data.datepicker_ate);
                setUrgentFlag(parseInt(data.is_urgent || 0) === 1);

                $('#btn-save-copy').text('更新並複製');
                $('#btn-save').text('確認更新');
                if (window.canDelete) { $('#btn-delete').show(); }
                $('.modal-title').html('<i class="fa fa-pencil"></i> 編輯訂單');
                // 依 Order_status 控制欄位鎖定與狀態按鈕
                var orderStatus = (data.Order_status !== null && data.Order_status !== undefined) ? parseInt(data.Order_status) : null;
                if (isNaN(orderStatus)) orderStatus = null;
                applyOrderStatusUI(orderStatus);
                $('#price-lock-icon').hide(); $('#price-source').text(''); $('#qty-warn').hide();
                $('#panel-right-content').hide(); $('#panel-quotes-placeholder').show();
                _lastLoadedPart = '';
                // 拆批按鈕：編輯模式下顯示
                $('#btn-open-split').show().data('order-id', data.Order_id);

                // 附件：既有正式附件直接載入；本次編輯中新上傳的附件仍先走暫存批次碼，
                // 按「確認更新」才歸給這張訂單，不按存檔就關閉視窗＝暫存到期自動清除（比照新增訂單）
                $('#order_attach_batch_key').val(orderAttachNewBatchKey());
                orderAttachRefresh(data.Order_id);

                $('#newOrderModal').modal('show');

                // ※ 新增：若客戶名稱有值但客戶ID為空，開啟後自動查詢嘗試綁定
                var autoBindName = data.Client_Name_Display || data.Client_Name;
                if (!data.Client_name_ID && autoBindName) {
                    $.post('', { action: 'search_data', type: 'customer', term: autoBindName }, function(r) {
                        if (!r.success) return;
                        var exact = r.data.filter(function(c) {
                            return c.customer.trim() === autoBindName.trim();
                        });
                        if (exact.length === 1) {
                            $('#selected_customer_pk').val(exact[0].customer_id);
                            updateIdBadges();
                            showToast('已自動綁定客戶 [' + exact[0].customer_id + '] ' + exact[0].customer);
                        } else if (exact.length > 1) {
                            showToast('客戶「' + autoBindName + '」有 ' + exact.length + ' 筆相同名稱，請手動從建議清單選取以綁定ID。');
                            updateIdBadges();
                        } else {
                            showToast('找不到與「' + autoBindName + '」完全相符的客戶，請手動搜尋選取以綁定ID。');
                            updateIdBadges();
                        }
                    }, 'json');
                } else {
                    setTimeout(function() { updateIdBadges(); }, 100);
                }
            }, 'json').fail(function() { alert('讀取資料失敗'); });
        }

        // ═══ 訂單附件（新增/編輯訂單皆可用；類別共用報價單附件類別，不需另設審核流程）═══
        // 共用元件：批次上傳（不強制先選標籤）＋逐筆點開設定標籤（勾選式）＋未設標籤紅字警示，
        // 訂單附件與 OP轉訂單附件（見下方 opAttach*）共用同一套 render/delegate，只是掛載的容器與參數不同。
        var ORDER_ATTACH_API = '../../src/store/Order_Attachment_API.php';
        var orderAttachCats = null; // 類別清單快取（get_categories 只需載入一次；本頁客製化子集由後端過濾好回傳）
        var orderAttachFiles = [];  // 目前訂單附件清單快取，供存檔前檢查是否都設定了標籤
        var opAttachFilesCache = []; // OP轉訂單附件清單快取，同上用途
        var oaCurrentUid = 0;         // 目前登入者 id（list_files 回傳），用來判斷刪除鈕是否顯示
        var oaCanDeleteOthers = false; // 是否可刪除非本人上傳的附件（管理員或被指派 ot_attach_delete）；後端 delete_file 同規則再擋一次
        function orderAttachNewBatchKey() { return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }

        // 產生單一附件列的 HTML（tagToggle/tagApply/fileDel 走事件委派，data-id 標記附件ID）
        // availableParts：showPart 情境下這批目前有哪些料號可選（>1 筆才顯示下拉，讓使用者事後改料號連結）
        function oaBuildFileRowHtml(f, cats, showPart, availableParts) {
            var tagged = !!f.category_name;
            var checkedIds = String(f.category_ids || '').split(',').filter(Boolean);
            var panelHtml = (cats || []).map(function(c) {
                var ck = checkedIds.indexOf(String(c.id)) !== -1 ? ' checked' : '';
                return '<label style="font-weight:400;display:inline-flex;align-items:center;gap:3px;margin:0 10px 4px 0;cursor:pointer;">' +
                    '<input type="checkbox" value="' + c.id + '"' + ck + '>' + escapeHtml(c.category_name) +
                    (c.required ? ' <span style="color:#DD5138;" title="必備類別，需連結單一料號">*</span>' : '') + '</label>';
            }).join('') || '<span style="color:#aaa;">尚未設定本頁可用標籤，請至「設定」跳窗設定</span>';
            var parts = availableParts || [];
            // 必備類別（沿用報價單 required_attach_cats）：這個檔已勾到必備類別時，「共用（全部）」選項直接不給選，
            // 逼使用者一定要指定料號（比照報價單附件標籤功能 renderFileTagPanel 的 hasReqCat 做法）
            var reqIds = (cats || []).filter(function(c) { return c.required; }).map(function(c) { return String(c.id); });
            var hasReq = checkedIds.some(function(id) { return reqIds.indexOf(id) !== -1; });
            var needPick = hasReq && !f.linked_part_no;
            var partTag = showPart
                ? (parts.length > 1
                    ? '<select class="oa-part-select" style="font-size:10px;padding:0 2px;' + (needPick ? 'border-color:#DD5138;color:#DD5138;' : '') + '">' +
                        (hasReq
                            ? '<option value=""' + (needPick ? ' selected disabled' : '') + '>請選擇料號…</option>'
                            : '<option value="">共用（全部）</option>') +
                        parts.map(function(p) { return '<option value="' + escapeHtml(p) + '"' + (f.linked_part_no === p ? ' selected' : '') + '>' + escapeHtml(p) + '</option>'; }).join('') +
                      '</select>' +
                      (hasReq ? ' <span style="font-size:9px;color:#DD5138;" title="必備類別附件不可設為共用，須連結單一料號">*必選料號</span>' : '')
                    : '<span style="font-size:10px;color:#999;">' + (f.linked_part_no ? ('料號：' + escapeHtml(f.linked_part_no)) : '共用（全部）') + '</span>')
                : '';
            // 刪除鈕：只有上傳者本人／管理員／被指派 ot_attach_delete 才顯示（後端 delete_file 同規則再擋一次，不可只靠前端隱藏）
            var canDel = oaCanDeleteOthers || (!!f.uploaded_by && String(f.uploaded_by) === String(oaCurrentUid));
            var delHtml = canDel
                ? '<span class="oa-file-del" style="margin-left:auto;color:#c0392b;cursor:pointer;"><i class="fa fa-trash"></i></span>'
                : '<span style="margin-left:auto;"></span>';
            return '<div class="oa-file-row" data-id="' + f.id + '" style="padding:4px 0;border-bottom:1px dotted #eee;' + (tagged ? '' : 'background:#FFF6F0;') + '">' +
                '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">' +
                '<i class="fa fa-file-o" style="color:#999;"></i>' +
                '<a href="' + ORDER_ATTACH_API + '?action=download&id=' + f.id + '" target="_blank" style="color:#337ab7;">' + escapeHtml(f.original_name || f.filename) + '</a>' +
                '<span style="color:#aaa;">' + (f.file_size || '') + '</span>' +
                oaUploaderHtml(f) +
                partTag +
                (f.is_shared ? '<span style="font-size:10px;color:#8a5a2b;background:#FDEBD0;border:1px solid #E8B76C;border-radius:3px;padding:0 4px;" title="這份附件已自動連動到相同訂單編號的其他料號訂單；刪除會一併移除所有連結"><i class="fa fa-link"></i> 自動連動</span>' : '') +
                '<span class="oa-tag-badge">' + (tagged
                    ? '<span style="font-size:10px;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:0 4px;">' + escapeHtml(f.category_name) + '</span>'
                    : '<span style="font-size:10px;color:#c0392b;font-weight:600;"><i class="fa fa-exclamation-circle"></i> 尚未設定標籤</span>') + '</span>' +
                '<span class="oa-tag-toggle" style="cursor:pointer;color:#337ab7;font-size:10px;"><i class="fa fa-tags"></i> 標籤</span>' +
                delHtml +
                '</div>' +
                '<div class="oa-tag-panel" style="display:' + (tagged ? 'none' : 'block') + ';padding:4px 0 4px 22px;">' + panelHtml + '</div>' +
                '</div>';
        }
        // 上傳者與上傳時間（使用者明確要求，2026-09-03）：uploaded_by 仍是 user id（刪除鈕判定要用），
        // 姓名由後端 list_files 以 LEFT JOIN user 回傳的 uploader_name 提供；日期顯示走共用 egFmtDate（ai-rules/20）。
        function oaUploaderHtml(f) {
            var who = f.uploader_name || '';
            var when = f.uploaded_at ? (typeof egFmtDate === 'function' ? egFmtDate(f.uploaded_at, true) : f.uploaded_at) : '';
            var txt = [who, when].filter(Boolean).join(' · ');
            if (!txt) return '';
            return '<span style="font-size:10px;color:#999;" title="上傳者／上傳時間"><i class="fa fa-user-o"></i> ' + escapeHtml(txt) + '</span>';
        }
        function oaHasUntagged(files) { return (files || []).some(function(f) { return !f.category_name; }); }
        // 事後改料號連結（多料號批次時的下拉選單，change 即時存檔）
        $(document).on('change', '.oa-part-select', function() {
            var $row = $(this).closest('.oa-file-row');
            var attId = $row.data('id');
            var newPart = $(this).val();
            var catIds = [];
            $row.find('.oa-tag-panel input:checked').each(function() { catIds.push($(this).val()); });
            $.post(ORDER_ATTACH_API, { action: 'update_attachment', attachment_id: attId, category_ids: catIds.join(','), linked_part_no: newPart }, function(res) {
                if (!res.success) { showToast(res.message || '設定失敗', 'info'); return; }
                [orderAttachFiles, opAttachFilesCache].forEach(function(arr) {
                    (arr || []).forEach(function(f) { if (String(f.id) === String(attId)) f.linked_part_no = newPart || null; });
                });
                oaRefreshRowPartSelect($row, catIds); // 選了實際料號後解除紅框/請選擇提示
            }, 'json');
        });
        // 目前有哪些附件清單快取存放這筆 attId（同時可能存在於訂單附件與OP附件），存回去讓存檔前檢查看到最新狀態
        function oaSyncCachedFile(attId, categoryName, categoryIds) {
            [orderAttachFiles, opAttachFilesCache].forEach(function(arr) {
                (arr || []).forEach(function(f) { if (String(f.id) === String(attId)) { f.category_name = categoryName; f.category_ids = categoryIds; } });
            });
        }
        // 類別勾選改變後，就地重繪這一列的料號下拉（不整列重畫，避免標籤面板被收合打斷使用者連續勾選）：
        // 勾到必備類別就拿掉「共用（全部）」選項逼選料號；取消勾選必備類別則恢復「共用」選項
        function oaRefreshRowPartSelect($row, ids) {
            var $sel = $row.find('.oa-part-select');
            if (!$sel.length) return; // 訂單附件/單一料號情境沒有這顆下拉，略過
            var parts = opAttachPartsList || [];
            var reqIds = (orderAttachCats || []).filter(function(c) { return c.required; }).map(function(c) { return String(c.id); });
            var hasReq = (ids || []).some(function(id) { return reqIds.indexOf(String(id)) !== -1; });
            var curVal = $sel.val() || '';
            var needPick = hasReq && !curVal;
            var optsHtml = (hasReq
                ? '<option value=""' + (needPick ? ' selected disabled' : '') + '>請選擇料號…</option>'
                : '<option value="">共用（全部）</option>') +
                parts.map(function(p) { return '<option value="' + escapeHtml(p) + '">' + escapeHtml(p) + '</option>'; }).join('');
            $sel.html(optsHtml);
            if (curVal && parts.indexOf(curVal) !== -1) $sel.val(curVal);
            $sel.css({'border-color': needPick ? '#DD5138' : '', 'color': needPick ? '#DD5138' : ''});
            $row.find('.oa-part-required-hint').remove();
            if (hasReq) $sel.after(' <span class="oa-part-required-hint" style="font-size:9px;color:#DD5138;" title="必備類別附件不可設為共用，須連結單一料號">*必選料號</span>');
        }

        // ── 事件委派（訂單附件／OP附件共用；一次綁定即可）──────────────────
        $(document).on('click', '.oa-tag-toggle', function() {
            $(this).closest('.oa-file-row').find('.oa-tag-panel').toggle();
        });
        // 勾選/取消勾選類別標籤即時存檔，不需要另外按「套用」；只在至少保留一個勾選時才送出
        // （後端不允許清空成沒有任何標籤，全部取消勾選時先不送、等使用者勾回至少一個）
        $(document).on('change', '.oa-tag-panel input[type="checkbox"]', function() {
            var $row = $(this).closest('.oa-file-row');
            var attId = $row.data('id');
            var $checked = $row.find('.oa-tag-panel input:checked');
            if (!$checked.length) return;
            var ids = [];
            $checked.each(function() { ids.push($(this).val()); });
            var names = ids.map(function(id) {
                var c = (orderAttachCats || []).filter(function(x) { return String(x.id) === String(id); })[0];
                return c ? c.category_name : '';
            }).filter(Boolean);
            $.post(ORDER_ATTACH_API, { action: 'update_attachment', attachment_id: attId, category_ids: ids.join(',') }, function(res) {
                if (!res.success) { showToast(res.message || '設定失敗', 'info'); return; }
                var nameStr = names.join('、');
                $row.find('.oa-tag-badge').html('<span style="font-size:10px;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:0 4px;">' + escapeHtml(nameStr) + '</span>');
                $row.css('background', '');
                oaSyncCachedFile(attId, nameStr, ids.join(','));
                oaRefreshRowPartSelect($row, ids);
            }, 'json');
        });
        $(document).on('click', '.oa-file-del', function() {
            var $row = $(this).closest('.oa-file-row');
            var isShared = $row.find('[title*="自動連動"]').length > 0;
            var confirmMsg = isShared
                ? '這份附件已自動連動到相同訂單編號的其他料號訂單，刪除會一併移除所有連結，確定要刪除？'
                : '確定要刪除這份附件？';
            if (!confirm(confirmMsg)) return;
            var attId = $row.data('id');
            $.post(ORDER_ATTACH_API, { action: 'delete_file', attachment_id: attId }, function(res) {
                if (!res.success) { showToast(res.message || '刪除失敗', 'info'); return; }
                if (res.linked_removed > 0) showToast(res.message, 'info');
                oaRefreshScopeOf($row);
            }, 'json');
        });
        // 依這一列屬於哪個清單容器，決定要重新整理訂單附件還是 OP附件
        function oaRefreshScopeOf($row) {
            if ($row.closest('#op-attach-list').length) { opAttachRefreshList(); return; }
            var orderId = parseInt($('#hidden_Order_id').val()) || 0;
            orderAttachRefresh(orderId || null);
        }

        function orderAttachRenderList(files) {
            orderAttachFiles = files || [];
            var $list = $('#order-attach-list');
            if (!files || !files.length) { $list.html('<span style="color:#aaa;">尚無附件</span>'); return; }
            $list.html(files.map(function(f) { return oaBuildFileRowHtml(f, orderAttachCats, false); }).join(''));
        }
        // orderId 有值＝編輯模式，合併顯示既有正式附件(order_id)＋本次編輯中新上傳尚未存檔的暫存附件(batch_key)；
        // 新增模式 orderId 為空，只查 batch_key
        function orderAttachRefresh(orderId) {
            var params = { action: 'list_files', batch_key: $('#order_attach_batch_key').val() || '' };
            if (orderId) params.order_id = orderId;
            function doList() {
                $.post(ORDER_ATTACH_API, params, function(res) {
                    if (res.success) { oaCurrentUid = res.current_uid || 0; oaCanDeleteOthers = !!res.can_delete_others; }
                    orderAttachRenderList(res.success ? res.files : []);
                }, 'json');
            }
            if (orderAttachCats === null) {
                $.post(ORDER_ATTACH_API, { action: 'get_categories' }, function(res) {
                    orderAttachCats = (res.success && res.categories) ? res.categories : [];
                    oaRenderPresetCats('#order-attach-cats');
                    doList();
                }, 'json');
            } else {
                oaRenderPresetCats('#order-attach-cats');
                doList();
            }
        }
        // 上傳前的「預設標籤」勾選區塊（訂單/OP轉訂單共用）；必備類別（沿用報價單 required_attach_cats）標紅色 *
        function oaRenderPresetCats(selector) {
            $(selector).html((orderAttachCats || []).map(function(c) {
                return '<label style="font-weight:400;display:inline-flex;align-items:center;gap:3px;margin:0;cursor:pointer;">' +
                    '<input type="checkbox" value="' + c.id + '">' + escapeHtml(c.category_name) +
                    (c.required ? ' <span style="color:#DD5138;" title="必備類別，需連結單一料號">*</span>' : '') + '</label>';
            }).join(''));
        }
        // 批次上傳：可一次選多檔；預設標籤（若有勾選）套用到全部檔案，不勾也能先上傳、之後再逐一設定。
        // 一律走暫存批次碼（即使是編輯既有訂單）：要按「確認更新/新增」才轉正歸屬到訂單，不按存檔就關閉視窗
        // 暫存到期(3天)自動清除，避免半路關視窗卻已經憑空掛上訂單。
        function orderAttachUpload(fileList) {
            if (!fileList || !fileList.length) return;
            var presetCats = [];
            $('#order-attach-cats input:checked').each(function() { presetCats.push($(this).val()); });
            var batchKey = $('#order_attach_batch_key').val() || '';
            var orderIdForRefresh = parseInt($('#hidden_Order_id').val()) || 0;
            var tasks = [];
            Array.prototype.forEach.call(fileList, function(file) {
                var fd = new FormData();
                fd.append('action', 'upload_file');
                fd.append('order_id', 0);
                fd.append('batch_key', batchKey);
                fd.append('category_ids', presetCats.join(','));
                fd.append('file', file);
                tasks.push($.ajax({ url: ORDER_ATTACH_API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' }));
            });
            $.when.apply($, tasks).always(function() {
                $('#order-attach-file-input').val('');
                orderAttachRefresh(orderIdForRefresh || null);
            });
        }
        // 訂單附件儲存路徑設定（訂單頁「設定」跳窗內的獨立區塊，與訂單變更設定分開儲存，僅管理員可存）
        function orderAttachLoadPathSetting() {
            $.post(ORDER_ATTACH_API, { action: 'get_settings' }, function(res) {
                if (!res.success) return;
                $('#oat-path').val(res.path || '');
                $('#oat-path-resolved').text('目前實際生效路徑：' + (res.resolved_dir || ''));
                $('#oat-path-msg').text('');
            }, 'json');
        }
        function orderAttachSavePathSetting() {
            $.post(ORDER_ATTACH_API, { action: 'save_settings', path: $('#oat-path').val().trim() }, function(res) {
                var $msg = $('#oat-path-msg');
                if (!res.success) { $msg.css('color', '#c0392b').text(res.message || '儲存失敗'); return; }
                $msg.css('color', '#27ae60').text('已儲存');
                orderAttachLoadPathSetting();
            }, 'json');
        }
        // 本頁使用的附件標籤（子集）設定：全部類別打勾清單＋目前啟用的打勾狀態
        function orderAttachLoadCatsSetting() {
            $.post(ORDER_ATTACH_API, { action: 'get_categories_setting' }, function(res) {
                var $box = $('#oat-cats-setting');
                if (!res.success) { $box.html('<span style="color:#c0392b;">讀取失敗</span>'); return; }
                var enabled = res.enabled_ids; // null=尚未客製化(全部顯示)；陣列=目前啟用的id清單
                $box.html((res.categories || []).map(function(c) {
                    var ck = (enabled === null || enabled.indexOf(c.id) !== -1) ? ' checked' : '';
                    return '<label style="font-weight:400;display:inline-flex;align-items:center;gap:4px;margin:0;cursor:pointer;">' +
                        '<input type="checkbox" value="' + c.id + '"' + ck + '>' + escapeHtml(c.category_name) + '</label>';
                }).join('') || '<span style="color:#aaa;">尚無可用類別</span>');
                $('#oat-cats-msg').text(enabled === null ? '（尚未客製化，目前顯示全部標籤）' : '').css('color', '#888');
            }, 'json');
        }
        function orderAttachSaveCatsSetting() {
            var ids = [];
            $('#oat-cats-setting input:checked').each(function() { ids.push($(this).val()); });
            $.post(ORDER_ATTACH_API, { action: 'save_categories_setting', category_ids: ids.join(',') }, function(res) {
                var $msg = $('#oat-cats-msg');
                if (!res.success) { $msg.css('color', '#c0392b').text(res.message || '儲存失敗'); return; }
                $msg.css('color', '#27ae60').text('已儲存');
                orderAttachCats = null; // 清快取，訂單/OP附件的標籤選單下次開啟時重新載入過濾後的清單
                orderAttachLoadCatsSetting();
            }, 'json');
        }

        function copyOrder(orderId) {
            $.post('', { action: 'get_order_detail', order_id: orderId }, function(res) {
                if (!res.success) { alert('讀取資料失敗：' + (res.message || '')); return; }
                var data = res.data;
                var form = $('#newOrderForm');
                form.find('input[type="text"], input[type="number"], input[type="date"], textarea, select').val('');

                $('#hidden_Order_id').val('');
                $('#selected_customer_pk').val(data.Client_name_ID || '');
                $('#selected_part_pk').val(data.d_id_ID || '');
                $('#bound_quote_item_id').val('');
                $('#hidden_quote_no').val(data.quote_no || '');

                $('#btn-save-copy').text('新增並複製');
                $('#btn-save').text('確認新增');
                if (window.canDelete) { $('#btn-delete').hide(); }
                $('.modal-title').html('<i class="fa fa-plus-circle"></i> 新增訂單');

                form.find('input[name="OrderNo"]').val(data.OrderNo || '');
                form.find('input[name="Client_Name"]').val(data.Client_Name_Display || data.Client_Name || '');
                renderOpCustDiffHint(data);
                form.find('input[name="Client_OrderNo"]').val(data.Client_OrderNo || '');
                form.find('input[name="d_id"]').val(data.d_id || '');
                form.find('input[name="Process"]').val(data.Process || '');
                form.find('input[name="Qty"]').val(data.Qty || '');
                console.log('[copyOrder] unit_price raw:', data.unit_price, 'type:', typeof data.unit_price);
                var priceValC = (data.unit_price !== null && data.unit_price !== '' && parseFloat(data.unit_price) > 0)
                    ? formatPrice(parseFloat(data.unit_price)) : '';
                console.log('[copyOrder] priceValC:', priceValC);
                $('#unit_price_input')
                    .val(priceValC)
                    .prop('readonly', false).css({'background':'','color':'','fontWeight':''});
                form.find('input[name="drop_zone"]').val(data.drop_zone || '');
                form.find('input[name="Containers"]').val(data.Containers || '');
                form.find('textarea[name="Order_ps"]').val(data.Order_ps || '');
                if (data.orderindate)    form.find('input[name="orderindate"]').val(data.orderindate);
                if (data.orderDdate)     form.find('input[name="orderDdate"]').val(data.orderDdate);
                if (data.ate)            form.find('select[name="ate"]').val(data.ate);
                if (data.datepicker_ate) form.find('input[name="datepicker_ate"]').val(data.datepicker_ate);

                $('#price-lock-icon').hide(); $('#price-source').text(''); $('#qty-warn').hide();
                $('#panel-right-content').hide(); $('#panel-quotes-placeholder').show();
                _lastLoadedPart = '';
                // 複製為新訂單，不繼承狀態（急件旗標同樣不繼承），重置狀態按鈕與欄位
                setUrgentFlag(false);
                $('#btn-toggle-paused').hide();
                $('#btn-toggle-closed').hide();
                $('#newOrderForm').find('input, textarea, select').prop('readonly', false).prop('disabled', false);
                $('#btn-save, #btn-save-copy').prop('disabled', false);

                // 複製為新訂單：附件不繼承，重新產生暫存批次碼（同新增模式）
                $('#order_attach_batch_key').val(orderAttachNewBatchKey());
                orderAttachRefresh();

                $('#newOrderModal').modal('show');
                setTimeout(function() { updateIdBadges(); }, 100);
            }, 'json').fail(function() { alert('讀取資料失敗'); });
        }

        function validateOrderNo(orderNo) {
            // 格式：OO + 民國年3碼 + 月2碼 + 日2碼 + 流水號3碼
            var m = orderNo.match(/^OO(\d{3})(\d{2})(\d{2})(\d{3})$/);
            if (!m) return { valid: false, msg: '格式應為 OO＋民國年3碼＋月2碼＋日2碼＋流水號3碼（共12碼），例：OO1150601001' };

            var rocYear = parseInt(m[1], 10);
            var month   = parseInt(m[2], 10);
            var day     = parseInt(m[3], 10);

            // 民國年：只接受今年 ±1
            var todayAD = new Date();
            var curRoc  = todayAD.getFullYear() - 1911;
            if (rocYear < curRoc - 1 || rocYear > curRoc + 1) {
                return { valid: false, msg: '民國年 ' + rocYear + ' 超出允許範圍（' + (curRoc-1) + '～' + (curRoc+1) + '）' };
            }

            // 月份 01~12
            if (month < 1 || month > 12) {
                return { valid: false, msg: '月份 ' + m[2] + ' 無效（應為 01～12）' };
            }

            // 日期：依月份實際天數判定
            var adYear  = rocYear + 1911;
            var isLeap  = (adYear % 4 === 0 && adYear % 100 !== 0) || (adYear % 400 === 0);
            var maxDays = [0,31,isLeap?29:28,31,30,31,30,31,31,30,31,30,31][month];
            if (day < 1 || day > maxDays) {
                return { valid: false, msg: adYear + ' 年 ' + month + ' 月無第 ' + day + ' 天（最多 ' + maxDays + ' 天）' };
            }

            // 是否為今日
            var orderDate = new Date(adYear, month - 1, day);
            var today     = new Date(todayAD.getFullYear(), todayAD.getMonth(), todayAD.getDate());
            var rocStr    = '民國' + rocYear + '年' + m[2] + '月' + m[3] + '日';
            return { valid: true, isToday: (orderDate.getTime() === today.getTime()), rocDateStr: rocStr };
        }

        function submitNewOrder(isCopy) {
            // 附件標籤鐵則：存檔前先擋（後端 or_new/or_update 仍會再驗一次，防止繞過前端直打API）
            if (oaHasUntagged(orderAttachFiles)) {
                showOrderAlert('尚有附件未設定類別標籤，請點附件列的「標籤」設定後再存檔。');
                return;
            }
            // 訂單編號驗證
            var rawNo = $('input[name="OrderNo"]').val().trim();
            if (rawNo === '') {
                showOrderAlert('請輸入訂單編號。'); $('input[name="OrderNo"]').focus(); return;
            }
            var upperNo = rawNo.toUpperCase();
            $('input[name="OrderNo"]').val(upperNo);
            if (upperNo !== 'NA') {
                var vr = validateOrderNo(upperNo);
                if (!vr.valid) {
                    showOrderAlert('訂單編號有誤：\n' + vr.msg); $('input[name="OrderNo"]').focus(); return;
                }
                if (!vr.isToday) {
                    if (!confirm('⚠️ 提醒：訂單編號日期為 ' + vr.rocDateStr + '，非今日，確定要儲存嗎？')) return;
                }
            }
            // 交期驗證
            if ($('input[name="orderDdate"]').val().trim() === '') {
                showOrderAlert('請選擇交期。'); $('input[name="orderDdate"]').focus(); return;
            }
            // 客戶驗證（名稱必填，ID 必須綁定）
            if ($('#client_name_input').val().trim() === '') {
                showOrderAlert('請填寫客戶名稱。'); $('#client_name_input').focus(); return;
            }
            var custPk = $('#selected_customer_pk').val();
            if (!custPk || custPk === '0') {
                showOrderAlert('客戶尚未綁定ID，請從建議清單選取或新增客戶。'); $('#client_name_input').focus(); return;
            }
            // 料號驗證（名稱必填，ID 必須綁定）
            if ($('#part_id_input').val().trim() === '') {
                showOrderAlert('請填寫料號。'); $('#part_id_input').focus(); return;
            }
            var partPk = $('#selected_part_pk').val();
            if (!partPk || partPk === '0') {
                showOrderAlert('料號尚未綁定ID，請從建議清單選取或新增料號。'); $('#part_id_input').focus(); return;
            }
            // 製程驗證
            if ($('input[name="Process"]').val().trim() === '') {
                showOrderAlert('請填寫製程。'); $('input[name="Process"]').focus(); return;
            }
            // 數量驗證
            var qty = $('input[name="Qty"]').val();
            if (!qty || parseFloat(qty) <= 0) {
                showOrderAlert('請輸入有效的數量（須大於0）。'); $('input[name="Qty"]').focus(); return;
            }

            var formData = new FormData(document.getElementById('newOrderForm'));
            var orderId = $('#hidden_Order_id').val();
            
            // Debug: 確認單價有帶入
            console.log('[submitNewOrder] unit_price:', formData.get('unit_price'), '| quote_no:', formData.get('quote_no'), '| orderId:', orderId);
            
            if (orderId) {
                formData.append('action', 'save_order');
                formData.append('or_update', '1');
            } else {
                formData.append('action', 'save_order');
                formData.append('or_new', '1');
            }

            // 鎖定按鈕避免重複提交
            $('#btn-save, #btn-save-copy, #btn-delete').prop('disabled', true);

            $.ajax({
                url: '../../src/store/_NewOrder_Track.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    // 解鎖按鈕
                    $('#btn-save, #btn-save-copy, #btn-delete').prop('disabled', false);

                    var data = response;
                    // 如果回應是字串（例如 PHP 錯誤訊息），嘗試解析或直接顯示
                    if (typeof response === 'string') {
                        try {
                            data = JSON.parse(response);
                        } catch (e) {
                            if (response.indexOf('資料庫錯誤') !== -1 || response.indexOf('一般錯誤') !== -1 || response.indexOf('Fatal error') !== -1) {
                                alert('操作失敗，請檢查資料。\n' + response);
                                return;
                            }
                        }
                    }

                    if (data && typeof data === 'object') {
                        if (data.success === false) {
                            alert('操作失敗：' + (data.message || '未知錯誤'));
                            return;
                        }
                    }

                    var newOrderId = (data && data.new_order_id) ? data.new_order_id : null;

                    // 若是「拆批觸發的新增」，新增完成後立即開拆批 Modal
                    if (window._splitAfterSave && newOrderId) {
                        window._splitAfterSave = false;
                        $('#hidden_Order_id').val(newOrderId);
                        $('#btn-open-split').show().data('order-id', newOrderId);
                        $('#btn-save').text('確認更新');
                        $('#btn-save-copy').text('更新並複製');
                        if (window.canDelete) { $('#btn-delete').show(); }
                        $('.modal-title').html('<i class="fa fa-pencil"></i> 編輯訂單');
                        // 開拆批 Modal（newOrderModal 先留著）
                        openSplitModal();
                        refreshOrderTable();
                        return;
                    }

                    // 「指定特定設計＝存檔自動轉生管」的結果提示（後端回傳 set/clear/none）
                    var autoPmMsg = '';
                    if (data && data.auto_pmget === 'set')        autoPmMsg = '（已依「指定特定設計自動轉生管」設定標記為已轉生管）';
                    else if (data && data.auto_pmget === 'clear') autoPmMsg = '（指派設計已改為不自動轉生管的對象，系統自動蓋的轉生管日已退回）';

                    // 儲存成功後：若料號為組合件且尚未展開過，先詢問是否自動展開子件訂單，再走原本收尾
                    var proceedAfterSave = function(promptShown) {
                        if (isCopy) {
                            if (orderId) {
                                showToast('更新成功！' + autoPmMsg + '已切換為新增模式，您可以繼續編輯下一筆。');
                                $('#hidden_Order_id').val('');
                                $('#btn-save-copy').text('新增並複製');
                                $('#btn-save').text('確認新增');
                                if (window.canDelete) {
                                    $('#btn-delete').hide();

                                }
                                $('.modal-title').html('<i class="fa fa-plus-circle"></i> 新增訂單');
                            } else {
                                showToast('新增成功！' + autoPmMsg + '您可以繼續編輯下一筆。');
                            }
                            // 剛剛的附件已隨訂單存檔轉正，清空畫面上的暫存附件清單（batch_key 字串不變，下一筆仍可續用）
                            orderAttachRenderList([]);
                            refreshOrderTable();
                            // 詢問過程會先收起訂單編輯 Modal，複製模式需重新開啟供繼續編輯
                            if (promptShown) {
                                setTimeout(function() { $('#newOrderModal').modal('show'); }, 350);
                            }
                        } else {
                            showToast('操作成功！' + autoPmMsg + '頁面將重新整理...');
                            setTimeout(function() { location.reload(); }, autoPmMsg ? 1800 : 800);
                        }
                    };
                    maybePromptAssemblyExpand(newOrderId || orderId || null, proceedAfterSave);
                },
                error: function(xhr, status, err) {
                    $('#btn-save, #btn-save-copy, #btn-delete').prop('disabled', false);
                    var msg = '新增失敗 (HTTP ' + xhr.status + ')\n';
                    if (xhr.responseText) msg += xhr.responseText.substring(0, 500);
                    alert(msg);
                    console.error('submitNewOrder error:', xhr.status, xhr.responseText);
                }
            });
        }

        function deleteOrder() {
            var orderId = $('#hidden_Order_id').val();
            var d_id = $('input[name="d_id"]').val(); // 取得料號用於提示

            if (!orderId) return;

            // 政策A：先查有效變更紀錄數，讓刪除的人知道會連動作廢與移除通知（查詢失敗仍可刪除）
            var fd = new FormData(); fd.append('action','history_order'); fd.append('order_id',orderId);
            fetch('../../src/store/_OrderChange_API.php', {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.json(); }).catch(function(){ return null; })
                .then(function(res){
                    var cnt = (res && res.success && res.data) ? res.data.filter(function(x){ return String(x.is_void)!=='1'; }).length : 0;
                    var msg = cnt > 0
                        ? ('此訂單有 '+cnt+' 筆變更紀錄，刪除後將一併作廢並移除相關通知。\n確定要刪除此筆資料嗎？')
                        : '確定要刪除此筆資料嗎？';
                    doDeleteOrder(msg);
                });

            function doDeleteOrder(confirmMsg) {
            if (confirm(confirmMsg)) {
                // 鎖定按鈕
                $('#btn-save, #btn-save-copy, #btn-delete').prop('disabled', true);
                
                $.ajax({
                    url: '../../src/store/_NewOrder_Track.php',
                    type: 'POST',
                    data: { del_order_track: 1, Order_id: orderId },
                    success: function(response) {
                        var data = response;
                        if (typeof response === 'string') {
                            try { data = JSON.parse(response); } catch(e) {}
                        }
                        
                        if (data && data.success === false) {
                            alert('刪除失敗：' + (data.message || '未知錯誤'));
                            $('#btn-save, #btn-save-copy, #btn-delete').prop('disabled', false);
                            return;
                        }

                        $('#newOrderModal').modal('hide');
                        // 移除表格中的該行 (AJAX 即時更新視覺)
                        $('tr[data-orderid="' + orderId + '"]').fadeOut(500, function(){ 
                            $(this).remove(); 
                            refreshOrderTable();
                        });
                        
                        showToast((d_id ? d_id : '該筆') + ' 資料已刪除');
                        
                        // 解鎖按鈕 (雖然 Modal 關閉了，但為了下次開啟)
                        $('#btn-save, #btn-save-copy, #btn-delete').prop('disabled', false);
                    },
                    error: function() {
                        alert('刪除失敗');
                        $('#btn-save, #btn-save-copy, #btn-delete').prop('disabled', false);
                    }
                });
            }
            }
        }

        function refreshOrderTable() {
            // 用現有的 AJAX 分頁機制刷新表格，不需要重建 DataTable
            fetchTableData(currentPage);
        }

        // ── 組合件：儲存後詢問是否自動展開子件訂單 ──────────────────────────
        var _asmExpandDone = null;     // 展開詢問結束後要執行的後續動作（reload / refresh）
        var _asmExpandOrderId = null;  // 詢問中的母訂單 Order_id
        function maybePromptAssemblyExpand(orderId, done) {
            // done(promptShown)：promptShown=true 表示曾跳出詢問（訂單編輯 Modal 已被收起）
            if (!orderId) { done(false); return; }
            $.post('', { action: 'check_assembly_expand', order_id: orderId }, function(res) {
                if (!res || !res.success || !res.need_prompt) { done(false); return; }
                _asmExpandDone = done;
                _asmExpandOrderId = orderId;
                var a = res.assembly, o = res.order;
                $('#asm-expand-parent').html(
                    '<div style="font-size:13px;font-weight:700;color:#2A3F54;"><i class="fa fa-cubes"></i> 組合件：' + escapeHtml(a.part_no || '') +
                    (a.spec ? ' <span style="color:#888;font-weight:400;">(' + escapeHtml(a.spec) + ')</span>' : '') + '</div>' +
                    '<div style="font-size:11px;color:#555;margin-top:2px;">訂單 ' + escapeHtml(o.order_no || '') + '　數量 ' + o.qty + '</div>');
                var rows = '';
                res.children.forEach(function(c) {
                    rows += '<tr><td><span style="background:#95a5a6;color:#fff;border-radius:3px;padding:0 4px;font-size:9px;margin-right:3px;">子件</span>' + escapeHtml(c.part_no || '') + '</td>' +
                            '<td>' + escapeHtml(c.spec || '') + '</td>' +
                            '<td style="text-align:right;">' + escapeHtml(String(c.standard_qty)) + '</td>' +
                            '<td style="text-align:right;font-weight:700;color:#2A3F54;">' + c.expand_qty + '</td></tr>';
                });
                $('#asm-expand-children').html(rows);
                $('#asm-expand-error').hide();
                $('#asm-expand-confirm').prop('disabled', false).html('<i class="fa fa-sitemap"></i> 展開建立子件訂單');
                // 先收起訂單編輯 Modal，避免 Bootstrap 雙 Modal 背板疊層問題
                $('#newOrderModal').modal('hide');
                setTimeout(function() { $('#assemblyExpandModal').modal('show'); }, 350);
            }, 'json').fail(function() { done(false); });
        }
        $(document).on('click', '#asm-expand-skip', function() {
            $('#assemblyExpandModal').modal('hide');
            var d = _asmExpandDone; _asmExpandDone = null;
            if (d) d(true);
        });
        $(document).on('click', '#asm-expand-confirm', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 建立中...');
            $.post('../../src/store/_NewOrder_Track.php', {
                action: 'expand_assembly_children',
                parent_order_id: _asmExpandOrderId
            }, function(res) {
                if (res && res.success) {
                    showToast('已自動建立 ' + res.created_count + ' 筆子件訂單');
                    $('#assemblyExpandModal').modal('hide');
                    var d = _asmExpandDone; _asmExpandDone = null;
                    if (d) d(true);
                } else {
                    $('#asm-expand-error').text((res && res.message) || '建立失敗，請稍後再試').show();
                    $btn.prop('disabled', false).html('<i class="fa fa-sitemap"></i> 展開建立子件訂單');
                }
            }, 'json').fail(function() {
                $('#asm-expand-error').text('連線失敗，請稍後再試').show();
                $btn.prop('disabled', false).html('<i class="fa fa-sitemap"></i> 展開建立子件訂單');
            });
        });

        // ══════════════════════════════════════════════════════════════════
        // ── OP轉訂單 ─────────────────────────────────────────────────────
        // ══════════════════════════════════════════════════════════════════
        var opCurrentQuote = null; // {quote_id, quote_no, client_name, is_negotiation}

        function openOpConvertModal() {
            opResetToSearch();
            // 標題共用 .modal-title class selector，可能被其他跳窗（新增/編輯訂單）的邏輯覆寫，開啟時強制重設
            $('#opConvertModal .modal-title').html('<i class="fa fa-exchange"></i> OP轉訂單');
            $('#opConvertModal').modal('show');
        }

        function opResetToSearch() {
            $('#op-step-items').hide();
            $('#op-step-search').show();
            $('#op-modal-footer-items').hide();
            $('#op-search-no-input').val('');
            $('#op-search-part-input').val('');
            $('#op-search-no-result').empty();
            $('#op-search-part-result').empty();
            $('#op-items-tbody').empty();
            $('#op-create-error').hide();
            // 清除批次套用區殘留的上次輸入
            $('#op-batch-delivery, #op-batch-ateget').val('');
            $('#op-batch-ps, #op-batch-orderno').val('');
            $('#op-batch-ate').val('2');
            opCurrentQuote = null;
            opSwitchSearchTab('no');

            // 附件：整批(從開啟這個Modal到建單/關閉)共用一個暫存批次碼
            opAttachBatchKey = orderAttachNewBatchKey();
            $('#op-attach-list').html('<span style="color:#aaa;">尚無附件</span>');
            $('#op-attach-part-wrap').hide();
            $('#op-attach-file-input').val('');
            opAttachRefreshCats();
        }

        function opSwitchSearchTab(mode) {
            if (mode === 'no') {
                $('#op-tab-no-li').addClass('active');
                $('#op-tab-part-li').removeClass('active');
                $('#op-search-by-no').show();
                $('#op-search-by-part').hide();
            } else {
                $('#op-tab-no-li').removeClass('active');
                $('#op-tab-part-li').addClass('active');
                $('#op-search-by-no').hide();
                $('#op-search-by-part').show();
            }
        }

        function opBackToSearch() {
            $('#op-step-items').hide();
            $('#op-modal-footer-items').hide();
            $('#op-step-search').show();
        }

        function opNegoBadge(isNego) {
            return isNego == 1 ? ' <span style="display:inline-block;font-size:9px;padding:1px 6px;background:#e8f8f0;color:#1e8449;border:1px solid #a9dfbf;border-radius:10px;font-weight:600;white-space:nowrap;">議價</span>' : '';
        }

        // ── 搜尋：OP單號 ─────────────────────────────────────────────────
        function opSearchByNo() {
            var term = $('#op-search-no-input').val().trim();
            var $r = $('#op-search-no-result');
            if (!term) { $r.empty(); return; }
            $r.html('<div class="text-center" style="padding:8px;"><i class="fa fa-spinner fa-spin"></i></div>');
            $.post('', { action: 'op_search_by_no', term: term }, function(res) {
                if (!res.success || !res.data.length) { $r.html('<div style="color:#999;font-size:12px;padding:6px;">查無符合的OP單</div>'); return; }
                var html = '<table class="table table-striped table-hover table-bordered" style="font-size:12px;margin-bottom:0;"><thead><tr style="background:#f5f7fa;"><th>OP單號</th><th>客戶</th><th>報價日期</th><th></th></tr></thead><tbody>';
                res.data.forEach(function(q) {
                    html += '<tr style="cursor:pointer;" onclick="opSelectQuote(' + q.quote_id + ')">' +
                        '<td><strong>' + escapeHtml(q.quote_no) + '</strong>' + opNegoBadge(q.is_negotiation) + '</td>' +
                        '<td>' + escapeHtml(q.client_name || '') + '</td>' +
                        '<td>' + (q.quote_date || '') + '</td>' +
                        '<td><button type="button" class="btn btn-xs btn-primary">選取</button></td></tr>';
                });
                html += '</tbody></table>';
                $r.html(html);
            }, 'json').fail(function() { $r.html('<div style="color:#e74c3c;font-size:12px;">查詢失敗</div>'); });
        }
        $(document).on('keydown', '#op-search-no-input', function(e) { if (e.which === 13) { e.preventDefault(); opSearchByNo(); } });

        // ── 搜尋：料號（跨OP單）───────────────────────────────────────────
        function opSearchByPart() {
            var term = $('#op-search-part-input').val().trim();
            var $r = $('#op-search-part-result');
            if (!term) { $r.empty(); return; }
            $r.html('<div class="text-center" style="padding:8px;"><i class="fa fa-spinner fa-spin"></i></div>');
            $.post('', { action: 'op_search_by_part', part_text: term, d_id_id: 0 }, function(res) {
                if (!res.success || !res.data.length) { $r.html('<div style="color:#999;font-size:12px;padding:6px;">查無符合的報價項目</div>'); return; }
                var html = '<table class="table table-striped table-hover table-bordered" style="font-size:11px;margin-bottom:0;"><thead><tr style="background:#f5f7fa;">' +
                    '<th>OP單號</th><th>客戶</th><th>料號</th><th>製程</th><th>料號備註</th><th style="text-align:right;">數量</th><th style="text-align:right;">單價</th></tr></thead><tbody>';
                res.data.forEach(function(q) {
                    var price = parseFloat(q.unit_price);
                    var priceStr = price > 0 ? formatPrice(price) : '-';
                    // 報價料號跟目前現行正確料號不同（客戶代號／等同料號綁定）：轉單時會自動更正，先標示提醒
                    var corrBadge = q.corrected_to ? ' <span style="font-size:10px;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:0 4px;" title="轉單時將自動更正為現行正確料號">→' + escapeHtml(q.corrected_to) + '</span>' : '';
                    html += '<tr style="cursor:pointer;" onclick="opSelectQuote(' + q.quote_id + ')">' +
                        '<td><strong>' + escapeHtml(q.quote_no) + '</strong>' + opNegoBadge(q.is_negotiation) + '<div style="color:#999;font-size:10px;">' + (q.quote_date||'') + '</div></td>' +
                        '<td>' + escapeHtml(q.client_name || '') + '</td>' +
                        '<td>' + escapeHtml(q.product_id || '') + corrBadge + '</td>' +
                        '<td>' + escapeHtml(q.processes || '') + '</td>' +
                        '<td>' + escapeHtml((q.specification||'').substring(0,30)) + '</td>' +
                        '<td style="text-align:right;">' + (parseInt(q.quantity)||0) + '</td>' +
                        '<td style="text-align:right;">' + priceStr + '</td></tr>';
                });
                html += '</tbody></table>';
                $r.html(html);
            }, 'json').fail(function() { $r.html('<div style="color:#e74c3c;font-size:12px;">查詢失敗</div>'); });
        }
        $(document).on('keydown', '#op-search-part-input', function(e) { if (e.which === 13) { e.preventDefault(); opSearchByPart(); } });

        // ── 選定OP單 → 載入完整料號項目清單（畫面二）────────────────────────
        function opSelectQuote(quoteId) {
            $.post('', { action: 'op_get_items', quote_id: quoteId }, function(res) {
                if (!res.success) { showOrderAlert(res.message || '載入失敗，請稍後再試'); return; }
                opCurrentQuote = res.quote;
                opRenderItems(res.data);
                $('#op-step-search').hide();
                $('#op-step-items').show();
                $('#op-modal-footer-items').show();
                $('#op-items-header').html('<i class="fa fa-file-text-o"></i> ' + escapeHtml(opCurrentQuote.quote_no) + opNegoBadge(opCurrentQuote.is_negotiation) +
                    '　客戶：' + escapeHtml(opCurrentQuote.client_name || ''));
            }, 'json').fail(function() { showOrderAlert('連線失敗，請稍後再試'); });
        }

        // ── 階梯報價工具：容差後區間計算、依輸入數量對價 ──────────────────
        // base_lo/base_hi＝報價（容差前）區間；tol_lo/tol_hi＝容差後區間（hi=null 表示無上限）
        function opTolRange(t) {
            var mn = Math.round(parseFloat(t.qty_min) || 0);
            var mx = (t.qty_max === null || t.qty_max === undefined || t.qty_max === '') ? null : Math.round(parseFloat(t.qty_max));
            var tv = parseFloat(t.tolerance_value);
            var lo = mn, hi = mx;
            if (!isNaN(tv) && tv > 0) {
                if ((t.tolerance_unit || '') === '%') {
                    lo = Math.max(1, Math.floor(mn * (1 - tv / 100)));
                    if (hi !== null) hi = Math.ceil(hi * (1 + tv / 100));
                } else if ((t.tolerance_unit || '') === 'PCS') {
                    lo = Math.max(1, mn - Math.round(tv));
                    if (hi !== null) hi = hi + Math.round(tv);
                }
            }
            return { base_lo: mn, base_hi: mx, tol_lo: lo, tol_hi: hi };
        }
        // 對價：先用容差前（報價）區間精確比對；useTol=true 時再用容差後區間，
        // 落在相鄰兩階容差區重疊處時取「距離原區間邊界較近」的那一階
        function opMatchTier(tiers, qty, useTol) {
            if (!(qty > 0)) return null;
            for (var i = 0; i < tiers.length; i++) {
                var r = opTolRange(tiers[i]);
                if (qty >= r.base_lo && (r.base_hi === null || qty <= r.base_hi)) return { tier: tiers[i], idx: i, byTol: false };
            }
            if (!useTol) return null;
            var best = null;
            for (var j = 0; j < tiers.length; j++) {
                var rr = opTolRange(tiers[j]);
                if (qty >= rr.tol_lo && (rr.tol_hi === null || qty <= rr.tol_hi)) {
                    var dist = qty < rr.base_lo ? (rr.base_lo - qty) : (rr.base_hi === null ? 0 : qty - rr.base_hi);
                    if (!best || dist < best.dist) best = { tier: tiers[j], idx: j, byTol: true, dist: dist };
                }
            }
            return best;
        }
        function opIsTieredItem(it) { return parseInt(it.is_tiered) === 1 && (it.tiers || []).length > 0; }
        // 依輸入數量與對價模式更新該列單價顯示
        function opUpdateTierPrice($tr) {
            var it  = $tr.data('item');
            var qty = parseInt($tr.find('.op-f-qty').val()) || 0;
            var useTol = $tr.find('.op-f-tolmatch').is(':checked');
            var $disp  = $tr.find('.op-f-price-display');
            if (!qty) { $disp.html('-'); $tr.data('op-match', null); return; }
            var m = opMatchTier(it.tiers || [], qty, useTol);
            $tr.data('op-match', m || null);
            if (m) {
                $disp.html(formatPrice(parseFloat(m.tier.unit_price) || 0) +
                    '<div style="font-size:9px;font-weight:400;color:' + (m.byTol ? '#a06a1f' : '#888') + ';">區間' + (m.idx + 1) + (m.byTol ? '（容差）' : '') + '</div>');
            } else {
                var mTol = opMatchTier(it.tiers || [], qty, true);
                $disp.html(mTol
                    ? '<span style="color:#DD5138;font-size:10px;">僅容差區間符合<br>請勾容差對價</span>'
                    : '<span style="color:#DD5138;font-size:11px;font-weight:600;">超出區間</span>');
            }
        }
        $(document).on('input',  '#op-items-tbody .op-f-qty',      function() { opUpdateTierPrice($(this).closest('tr')); });
        $(document).on('change', '#op-items-tbody .op-f-tolmatch', function() { opUpdateTierPrice($(this).closest('tr')); });

        function opRenderItems(items) {
            var $tb = $('#op-items-tbody').empty();
            var ateOptionsHtml = $('#op-ate-options-template').html();
            $('#op-batch-ate').html(ateOptionsHtml);
            var todayStr = new Date().toISOString().split('T')[0];
            $('#op-batch-ateget').val(todayStr);
            opAttachUpdatePartPicker(items);
            items.forEach(function(it) {
                // 已轉過訂單的料號不再整列鎖死：客戶可能重複下單同一組合，允許再次勾選建立「追加訂單」；
                // 是否重複轉單只看 converted_order_oo 是否有值，不影響 KPI 報價轉訂單比例（quote_to_order 是用 quote_no 判斷 EXISTS，不是算次數）
                var converted = !!it.converted_order_oo;
                var bomBadge = parseInt(it.Is_Assembly) === 1
                    ? ' <span style="background:#3498db;color:#fff;border-radius:3px;padding:0 4px;font-size:9px;"><i class="fa fa-cubes" style="font-size:8px;"></i> 組合件</span>' : '';
                var convBadge = converted
                    ? ' <span style="background:#F0A24B;color:#fff;border-radius:3px;padding:0 4px;font-size:9px;" title="先前已轉過訂單：' + escapeHtml(it.converted_order_oo) + '（勾選此列將建立追加訂單，不影響報價轉訂單比例統計）"><i class="fa fa-refresh" style="font-size:8px;"></i> 已轉訂單×' + (parseInt(it.converted_count) || 1) + '</span>' : '';
                // 客戶代號／等同料號自動更正：報價當年用的料號已被登記成別的現行料號的別名，轉單一律改用現行正確料號
                var corrBadge = it.corrected_from
                    ? '<div style="font-size:9px;color:#a06a1f;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:0 4px;margin-top:2px;" title="報價當時用的料號是「' + escapeHtml(it.corrected_from) + '」，已依客戶代號／等同料號綁定自動更正為現行正確料號">已更正：' + escapeHtml(it.corrected_from) + ' → ' + escapeHtml(it.D_Setting_Id) + '</div>'
                    : '';
                var $tr = $('<tr></tr>').data('item', it);
                // 已轉過的列不再整列 disabled：改用較淡的提示底色，讓使用者知道「這是追加」但仍可正常填寫、送出
                if (converted) $tr.css({'background':'#FFF8ED'});
                $tr.append('<td><input type="checkbox" class="op-row-check"></td>');
                $tr.append('<td>' + escapeHtml(it.D_Setting_Id || it.product_id || '') + bomBadge + convBadge + corrBadge + '</td>');
                $tr.append('<td>' + escapeHtml((opCurrentQuote && opCurrentQuote.client_name) || '') + '</td>');
                $tr.append('<td>' + escapeHtml(it.processes || '') + '</td>');
                $tr.append('<td>' + escapeHtml((it.specification||'').substring(0,40)) + '</td>');
                if (opIsTieredItem(it)) {
                    // 階梯報價：數量改為自行輸入；下方分開列出「報價區間」與「容差後區間」
                    var rangeLines = it.tiers.map(function(t, i) {
                        var r = opTolRange(t);
                        var baseTxt = r.base_hi === null ? (r.base_lo + '以上') : (r.base_lo + '~' + r.base_hi);
                        var tolDiff = (r.tol_lo !== r.base_lo) || (r.tol_hi !== r.base_hi);
                        var tolTxt  = tolDiff
                            ? '｜<span style="color:#a06a1f;">容差後 ' + (r.tol_hi === null ? (r.tol_lo + '以上') : (r.tol_lo + '~' + r.tol_hi)) + '</span>' : '';
                        return '<div style="font-size:10px;line-height:1.5;white-space:nowrap;">' +
                               '區間' + (i + 1) + '：' + baseTxt + ' @' + formatPrice(parseFloat(t.unit_price) || 0) + tolTxt + '</div>';
                    }).join('');
                    var $qtyTd = $('<td style="text-align:right;min-width:150px;"></td>');
                    $qtyTd.append($('<input type="number" class="form-control input-sm op-f-qty" placeholder="輸入數量" min="1" step="1">'));
                    $qtyTd.append('<div style="text-align:left;margin-top:2px;">' + rangeLines + '</div>');
                    $tr.append($qtyTd);
                    var $priceTd = $('<td style="text-align:right;min-width:100px;"></td>');
                    $priceTd.append('<div class="op-f-price-display" style="font-weight:600;">-</div>');
                    $priceTd.append('<label style="font-weight:400;font-size:10px;display:flex;align-items:center;gap:3px;justify-content:flex-end;cursor:pointer;margin:2px 0 0;white-space:nowrap;" title="數量落在容差後區間內也視為對應該區間取價">' +
                        '<input type="checkbox" class="op-f-tolmatch" style="margin:0;">容差區間對價</label>');
                    $tr.append($priceTd);
                } else {
                    $tr.append('<td style="text-align:right;">' + (parseInt(it.quantity)||0) + '</td>');
                    $tr.append('<td style="text-align:right;">' + (parseFloat(it.unit_price) > 0 ? formatPrice(parseFloat(it.unit_price)) : '-') + '</td>');
                }
                $tr.append($('<td></td>').append($('<input type="date" class="form-control input-sm op-f-delivery">')));
                $tr.append($('<td></td>').append($('<input type="text" class="form-control input-sm op-f-ps">')));
                $tr.append($('<td></td>').append($('<select class="form-control input-sm op-f-ate"></select>').html(ateOptionsHtml)));
                $tr.append($('<td></td>').append($('<input type="date" class="form-control input-sm op-f-ateget">').val(todayStr)));
                $tr.append($('<td></td>').append($('<input type="text" class="form-control input-sm op-f-orderno" placeholder="OO...">')));
                $tb.append($tr);
            });
            $('#op-check-all').prop('checked', false);
        }

        // ── OP轉訂單附件（整批共用一個暫存批次；多料號時可指定對應料號或「共用(全部)」）──
        var opAttachBatchKey = null;
        var opAttachMultiPart = false; // 這批是否有 >1 種料號；影響「必備類別是否可設為共用」的檢查
        var opAttachPartsList = [];    // 這批目前有哪些料號（供附件列表的料號下拉使用）
        function opAttachRefreshCats() {
            if (orderAttachCats === null) {
                $.post(ORDER_ATTACH_API, { action: 'get_categories' }, function(res) {
                    orderAttachCats = (res.success && res.categories) ? res.categories : [];
                    oaRenderPresetCats('#op-attach-cats');
                }, 'json');
            } else { oaRenderPresetCats('#op-attach-cats'); }
        }
        // 批次內出現多種料號才顯示下拉（含「共用(全部)」）；只有一種料號時隱藏、自動視為該料號整批共用
        function opAttachUpdatePartPicker(items) {
            var parts = [];
            (items || []).forEach(function(it) {
                var p = it.D_Setting_Id || it.product_id || '';
                if (p && parts.indexOf(p) === -1) parts.push(p);
            });
            opAttachMultiPart = parts.length > 1;
            opAttachPartsList = parts;
            if (parts.length > 1) {
                $('#op-attach-part-wrap').css('display', 'inline-flex');
                opAttachRebuildPartSelect();
            } else {
                $('#op-attach-part-wrap').hide();
            }
        }
        // 目前預設標籤是否勾到必備類別（沿用報價單 required_attach_cats）
        function opAttachHasReqCatChecked() {
            var reqIds = (orderAttachCats || []).filter(function(c) { return c.required; }).map(function(c) { return String(c.id); });
            if (!reqIds.length) return false;
            var hit = false;
            $('#op-attach-cats input:checked').each(function() { if (reqIds.indexOf($(this).val()) !== -1) hit = true; });
            return hit;
        }
        // 上傳前的「對應料號」下拉：預設標籤勾到必備類別時，比照報價單附件標籤功能拿掉「共用（全部）」選項，
        // 逼使用者上傳前就先指定料號（而不是等存檔時才被擋下）
        function opAttachRebuildPartSelect() {
            var parts = opAttachPartsList || [];
            if (parts.length <= 1) return;
            var $sel = $('#op-attach-part');
            var curVal = $sel.val() || '';
            var hasReq = opAttachHasReqCatChecked();
            var optsHtml = (hasReq
                ? '<option value="" disabled' + (curVal ? '' : ' selected') + '>請選擇料號…</option>'
                : '<option value="">共用（全部）</option>') +
                parts.map(function(p) { return '<option value="' + escapeHtml(p) + '">' + escapeHtml(p) + '</option>'; }).join('');
            $sel.html(optsHtml);
            if (curVal && parts.indexOf(curVal) !== -1) $sel.val(curVal);
        }
        $(document).on('change', '#op-attach-cats input[type="checkbox"]', opAttachRebuildPartSelect);
        // 必備類別（reqCats，沿用報價單 required_attach_cats）在批次真的有多種料號時不可設為共用；
        // 只有單一料號時已自動視為該料號，不會落入這個問題
        function oaHasUnboundRequired(files) {
            if (!opAttachMultiPart) return false;
            var reqIds = (orderAttachCats || []).filter(function(c) { return c.required; }).map(function(c) { return String(c.id); });
            if (!reqIds.length) return false;
            return (files || []).some(function(f) {
                if (f.linked_part_no) return false;
                var catIds = String(f.category_ids || '').split(',').filter(Boolean);
                return catIds.some(function(id) { return reqIds.indexOf(id) !== -1; });
            });
        }
        function opAttachRenderList(files) {
            opAttachFilesCache = files || [];
            var $list = $('#op-attach-list');
            if (!files || !files.length) { $list.html('<span style="color:#aaa;">尚無附件</span>'); return; }
            $list.html(files.map(function(f) { return oaBuildFileRowHtml(f, orderAttachCats, true, opAttachPartsList); }).join(''));
        }
        function opAttachRefreshList() {
            $.post(ORDER_ATTACH_API, { action: 'list_files', batch_key: opAttachBatchKey }, function(res) {
                if (res.success) { oaCurrentUid = res.current_uid || 0; oaCanDeleteOthers = !!res.can_delete_others; }
                opAttachRenderList(res.success ? res.files : []);
            }, 'json');
        }
        // 批次上傳：可一次選多檔，套同一個預設標籤（選填）與同一個對應料號
        function opAttachUpload(fileList) {
            if (!fileList || !fileList.length) return;
            var presetCats = [];
            $('#op-attach-cats input:checked').each(function() { presetCats.push($(this).val()); });
            var linkPart = $('#op-attach-part-wrap').is(':visible') ? $('#op-attach-part').val() : '';
            if ($('#op-attach-part-wrap').is(':visible') && opAttachHasReqCatChecked() && !linkPart) {
                showOrderAlert('預設標籤含必備類別，這批有多種料號，請先在「對應料號」選好要連結的料號再上傳。');
                return;
            }
            var tasks = [];
            Array.prototype.forEach.call(fileList, function(file) {
                var fd = new FormData();
                fd.append('action', 'upload_file');
                fd.append('order_id', 0);
                fd.append('batch_key', opAttachBatchKey);
                fd.append('linked_part_no', linkPart || '');
                fd.append('category_ids', presetCats.join(','));
                fd.append('file', file);
                tasks.push($.ajax({ url: ORDER_ATTACH_API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' }));
            });
            $.when.apply($, tasks).always(function() {
                $('#op-attach-file-input').val('');
                opAttachRefreshList();
            });
        }

        function opToggleAll(cb) {
            // 全選時跳過已轉過訂單的列（追加訂單需使用者逐列主動勾選，避免不小心整批重複下單）；取消全選則一律清空
            $('#op-items-tbody tr').each(function() {
                var $tr = $(this);
                var it = $tr.data('item') || {};
                if (cb.checked && it.converted_order_oo) return;
                $tr.find('.op-row-check').prop('checked', cb.checked);
            });
        }

        function opApplyBatch(field) {
            var $checked = $('#op-items-tbody tr').filter(function() { return $(this).find('.op-row-check').is(':checked'); });
            if (!$checked.length) { showOrderAlert('請先勾選要套用的料號列。'); return; }
            if (field === 'delivery') {
                $checked.find('.op-f-delivery').val($('#op-batch-delivery').val().trim());
            } else if (field === 'ps') {
                $checked.find('.op-f-ps').val($('#op-batch-ps').val());
            } else if (field === 'ate') {
                $checked.find('.op-f-ate').val($('#op-batch-ate').val());
            } else if (field === 'ateget') {
                $checked.find('.op-f-ateget').val($('#op-batch-ateget').val());
            } else if (field === 'orderno') {
                $checked.find('.op-f-orderno').val($('#op-batch-orderno').val().trim().toUpperCase());
            } else if (field === 'tolmatch') {
                // 全部改用容差區間對價／改回容差前（報價）區間對價
                var on = $('#op-batch-tolmatch').val() === '1';
                $checked.each(function() {
                    var $tr = $(this);
                    var $cb = $tr.find('.op-f-tolmatch');
                    if ($cb.length) { $cb.prop('checked', on); opUpdateTierPrice($tr); }
                });
            }
        }

        function opCreateOrders() {
            var $checked = $('#op-items-tbody tr').filter(function() { return $(this).find('.op-row-check').is(':checked'); });
            $('#op-create-error').hide();
            if (!$checked.length) { $('#op-create-error').text('請至少勾選一筆料號。').show(); return; }
            // 附件標籤鐵則：存檔前先擋（後端 create_orders_from_quotes 仍會再驗一次，防止繞過前端直打API）
            if (oaHasUntagged(opAttachFilesCache)) {
                $('#op-create-error').text('尚有附件未設定類別標籤，請點附件列的「標籤」設定後再建立訂單。').show();
                return;
            }
            // 必備類別附件在多料號批次中不可設為共用，必須指定對應料號
            if (oaHasUnboundRequired(opAttachFilesCache)) {
                $('#op-create-error').text('這批有多種料號，含必備類別的附件必須指定對應料號（不可設為共用），請在附件列的料號下拉選擇後再建立訂單。').show();
                return;
            }

            var items = [];
            var errMsg = '';
            var overRangeLabels = []; // 完全超出區間（含容差後）仍執意轉單的料號，確認後存檔並標記
            var repeatLabels = [];    // 已轉過訂單、這次是追加訂單的料號，確認後存檔並標記（不計入報價轉訂單比例）
            $checked.each(function() {
                if (errMsg) return;
                var $tr = $(this);
                var it = $tr.data('item');
                var delivery = $tr.find('.op-f-delivery').val().trim();
                var orderNo  = $tr.find('.op-f-orderno').val().trim().toUpperCase();
                var ps       = $tr.find('.op-f-ps').val();
                var ate      = $tr.find('.op-f-ate').val();
                var ateget   = $tr.find('.op-f-ateget').val().trim();
                var label = it.D_Setting_Id || it.product_id || ('項目' + it.item_id);
                if (!delivery) { errMsg = '料號 ' + label + ' 尚未填交期。'; return; }
                if (!/^\d{4}-\d{2}-\d{2}$/.test(delivery)) { errMsg = '料號 ' + label + ' 交期格式錯誤，請用日曆選擇。'; return; }
                if (!ateget) { errMsg = '料號 ' + label + ' 尚未填設計接收日。'; return; }
                if (!/^\d{4}-\d{2}-\d{2}$/.test(ateget)) { errMsg = '料號 ' + label + ' 設計接收日格式錯誤，請用日曆選擇。'; return; }
                if (!orderNo)  { errMsg = '料號 ' + label + ' 尚未填訂單編號。'; return; }
                if (orderNo !== 'NA') {
                    var vr = validateOrderNo(orderNo);
                    if (!vr.valid) { errMsg = '料號 ' + label + ' 訂單編號有誤：' + vr.msg; return; }
                }
                var payload = { quote_item_id: it.item_id, order_no: orderNo, delivery_date: delivery, order_ps: ps, ate: ate, ateget: ateget };
                if (it.converted_order_oo) {
                    payload.repeat_confirm = 1;
                    repeatLabels.push(label + '（先前：' + it.converted_order_oo + '）');
                }
                if (opIsTieredItem(it)) {
                    var qtyIn  = parseInt($tr.find('.op-f-qty').val()) || 0;
                    var useTol = $tr.find('.op-f-tolmatch').is(':checked');
                    if (!qtyIn) { errMsg = '料號 ' + label + ' 為階梯報價，請輸入訂購數量。'; return; }
                    var mMode = opMatchTier(it.tiers, qtyIn, useTol);   // 依使用者選的對價模式
                    var mTol  = opMatchTier(it.tiers, qtyIn, true);     // 含容差後區間的最寬判定
                    if (!mMode && mTol) {
                        errMsg = '料號 ' + label + ' 數量 ' + qtyIn + ' 僅落在容差後區間：請勾選該列「容差區間對價」取得單價，或修改數量。';
                        return;
                    }
                    if (!mTol) overRangeLabels.push(label + '（數量 ' + qtyIn + '）');
                    payload.qty = qtyIn;
                    payload.tol_match = useTol ? 1 : 0;
                }
                items.push(payload);
            });
            if (errMsg) { $('#op-create-error').text(errMsg).show(); return; }
            // 超出區間／追加訂單兩種情況都要先跳窗提醒使用者確認，再存檔（可能同時發生，訊息合併成一個跳窗）
            var warnBlocks = [];
            if (repeatLabels.length) {
                warnBlocks.push('下列料號<b style="color:#F0A24B;">先前已轉過訂單</b>，這次將建立<b>追加訂單</b>（不會重複計入報價轉訂單比例統計）：<br>' +
                    repeatLabels.map(function(s){ return '・' + escapeHtml(s); }).join('<br>'));
            }
            if (overRangeLabels.length) {
                warnBlocks.push('下列料號輸入的數量<b style="color:#DD5138;">超出階梯報價區間範圍（含容差後區間）</b>，將以<b>無單價</b>建立訂單並標記「數量超出區間」，之後請補報價單：<br>' +
                    overRangeLabels.map(function(s){ return '・' + escapeHtml(s); }).join('<br>'));
            }
            if (warnBlocks.length) {
                opConfirm(warnBlocks.join('<br><br>'), function() { opSubmitOrders(items); });
                return;
            }
            opSubmitOrders(items);
        }

        // 超出區間確認跳窗（繼續存檔／取消）
        function opConfirm(htmlMsg, onOk) {
            var ov = document.createElement('div');
            ov.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:99998;';
            var box = document.createElement('div');
            box.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-top:4px solid #F0A24B;border-radius:6px;padding:24px 30px 18px;z-index:99999;min-width:320px;max-width:480px;width:92%;box-shadow:0 10px 40px rgba(0,0,0,0.3);text-align:center;';
            box.innerHTML = '<div style="color:#F0A24B;font-size:30px;margin-bottom:8px;"><i class="fa fa-exclamation-triangle"></i></div>'
                          + '<div style="font-size:13px;color:#333;line-height:1.8;text-align:left;">' + htmlMsg + '</div>'
                          + '<div style="margin-top:16px;display:flex;gap:10px;justify-content:center;">'
                          + '<button type="button" class="btn btn-sm btn-default" id="op-confirm-cancel">取消</button>'
                          + '<button type="button" class="btn btn-sm btn-warning" id="op-confirm-ok" style="font-weight:600;">仍要繼續存檔</button>'
                          + '</div>';
            document.body.appendChild(ov);
            document.body.appendChild(box);
            function dismiss() { ov.remove(); box.remove(); }
            box.querySelector('#op-confirm-cancel').onclick = dismiss;
            ov.onclick = dismiss;
            box.querySelector('#op-confirm-ok').onclick = function() { dismiss(); onOk(); };
        }

        function opSubmitOrders(items) {
            var $btn = $('#op-btn-create');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 建立中...');
            $.post('../../src/store/_NewOrder_Track.php', {
                action: 'create_orders_from_quotes',
                items: JSON.stringify(items),
                batch_key: opAttachBatchKey || ''
            }, function(res) {
                $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 確認建立訂單');
                if (!res.success) { $('#op-create-error').text(res.message || '建立失敗，請稍後再試').show(); return; }
                $('#opConvertModal').modal('hide');
                var created = res.created || [];
                var asmQueue = created.filter(function(c) { return c.is_assembly; }).map(function(c) { return c.order_id; });
                showToast(res.message || ('已建立 ' + created.length + ' 筆訂單'));
                opProcessAssemblyQueue(asmQueue, function() {
                    fetchTableData(currentPage);
                });
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 確認建立訂單');
                $('#op-create-error').text('連線失敗，請稍後再試').show();
            });
        }

        // 依序對每筆新建的組合件訂單詢問是否展開子件訂單（沿用單筆訂單既有互動）
        function opProcessAssemblyQueue(orderIds, done) {
            if (!orderIds.length) { done(); return; }
            var orderId = orderIds.shift();
            maybePromptAssemblyExpand(orderId, function() {
                opProcessAssemblyQueue(orderIds, done);
            });
        }

        // ── 同步來源OP單的客戶（2026-08-28）────────────────────────────────────
        //    只有「客戶與來源報價單已不一致」的訂單才會出現那顆橘色徽章；按下去把訂單客戶
        //    改成報價單目前的客戶（只改客戶欄位，不動料號／金額／任何日期），並連動該訂單
        //    底下的 BOM 客戶名稱。判定與寫入都在後端 quote_customer_lib.php，前端不自己算。
        function syncQuoteCustomer(orderId) {
            if (!confirm('要把這張訂單的客戶同步成來源報價單(OP)目前的客戶嗎？\n\n只會修改客戶欄位（及該訂單底下 BOM 的客戶名稱），不會動到料號、金額與任何日期。')) return;
            $.post('', { action: 'sync_quote_customer', order_id: orderId }, function (res) {
                if (!res || !res.success) { showOrderAlert((res && res.message) || '同步失敗，請稍後再試'); return; }
                var d = res.data || {};
                // 這個動作只會改到「客戶名稱」與「客戶ID」兩個值，所以就地更新那一列即可，
                // 不要 location.reload()——整頁重載會把篩選、捲動位置、展開中的子批列全部弄丟。
                applySyncedCustomerToRow(orderId, d);
                showToast(d.message || '已同步');
            }, 'json').fail(function () { showOrderAlert('連線失敗，請稍後再試'); });
        }

        // 就地把某一列（與開啟中的編輯跳窗）換成同步後的客戶
        function applySyncedCustomerToRow(orderId, d) {
            if (!d || !d.changed) return;
            var name = d.to || '', cid = d.to_id || '';
            var $tr  = $('#orderTable tbody tr[data-orderid="' + orderId + '"]');
            var $td  = $tr.find('td.col-client');
            if ($td.length) {
                $td.find('.oc-client-name').text(name);
                $td.attr('title', name);
                $td.find('.op-cust-diff').remove();          // 已一致，提示徽章拿掉
                // 綁定圖示：同步一定會寫入 Client_name_ID，所以客戶那一邊必為已綁定
                var hasPart = String($td.attr('data-has-part')) === '1';
                $td.find('.oc-bind-icon').html(hasPart
                    ? '<i class="fa fa-link" title="客戶與料號均已綁定ID" style="color:#1ABB9C;font-size:10px;margin-left:3px;"></i>'
                    : '<i class="fa fa-chain-broken" title="部分綁定（客戶:✓ / 料號:✗）" style="color:#F39C12;font-size:10px;margin-left:3px;"></i>');
                // 淡黃底閃一下，讓使用者看得到剛剛改的是哪一列（純 CSS，不依賴 jQuery UI 的顏色動畫）
                $td.css('transition', 'background-color .4s').css('background-color', '#FFF3E2');
                setTimeout(function () { $td.css('background-color', ''); }, 1400);
            }
            // 編輯跳窗正開著同一張訂單時，一併更新欄位與提示（避免畫面上兩邊不一致）
            if (String($('#hidden_Order_id').val() || '') === String(orderId)) {
                $('#newOrderForm').find('input[name="Client_Name"]').val(name);
                if (cid) $('#selected_customer_pk').val(cid);
                $('#op-cust-diff-hint').hide().empty();
                if (typeof updateIdBadges === 'function') updateIdBadges();
            }
        }

        function showOrderAlert(msg) {
            var ov = document.createElement('div');
            ov.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:99998;cursor:pointer;';
            var box = document.createElement('div');
            box.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-top:4px solid #e74c3c;border-radius:6px;padding:28px 36px 20px;z-index:99999;min-width:280px;max-width:420px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,0.3);text-align:center;cursor:pointer;';
            box.innerHTML = '<div style="color:#e74c3c;font-size:30px;margin-bottom:10px;"><i class="fa fa-exclamation-circle"></i></div>'
                          + '<div style="font-size:15px;color:#333;line-height:1.7;font-weight:500;">' + msg.replace(/\n/g,'<br>') + '</div>'
                          + '<div style="font-size:11px;color:#aaa;margin-top:16px;">點擊任意處關閉</div>';
            document.body.appendChild(ov);
            document.body.appendChild(box);
            function dismiss() { ov.remove(); box.remove(); }
            ov.onclick = dismiss;
            box.onclick = dismiss;
        }

        function showToast(message) {
            var container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
                document.body.appendChild(container);
            }

            var toast = document.createElement('div');
            toast.style.cssText = 'background-color: rgba(0,0,0,0.8); color: #fff; padding: 15px 25px; border-radius: 5px; margin-bottom: 10px; opacity: 0; transition: opacity 0.5s ease-in-out; box-shadow: 0 4px 6px rgba(0,0,0,0.1); font-size: 14px;';
            toast.innerText = message;

            container.appendChild(toast);

            // Trigger reflow
            void toast.offsetWidth;
            toast.style.opacity = '1';

            setTimeout(function() {
                toast.style.opacity = '0';
                setTimeout(function() {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 500);
            }, 3000);
        }

        // --- Designer Setting Functions ---
        function openDesignerSetting() {
            $.post('', { action: 'get_dept_data' }, function(res) {
                if (res.success) {
                    var opts = '<option value="">-- 請選擇 --</option>';
                    res.depts.forEach(function(d) { opts += '<option value="' + d.id + '">' + d.name + '</option>'; });
                    $('#design_dept_select, #extra1_dept_select, #extra2_dept_select').html(opts);
                    
                    // 主要設計部門：一律取全站「組織角色綁定設定」的設計／技術部門，本頁反灰不可改（2026-08-03）
                    if (res.rd_dept_id) {
                        $('#design_dept_select').val(String(res.rd_dept_id))
                            .prop('disabled', true)
                            .css({background:'#eee', color:'#888', cursor:'not-allowed'});
                        if (!$('#design_dept_lock').length) {
                            $('#design_dept_select').after('<div id="design_dept_lock" style="font-size:11px;color:#8a6d45;margin-top:4px;">'
                                + '此部門由<a href="../admin/org_role_setting.php" target="_blank"><b>組織角色綁定設定</b></a>的'
                                + '「設計／技術部門」統一決定，僅能在該頁修改；底下的人員仍可自行勾選。</div>');
                        }
                        loadDeptUsers(res.rd_dept_id, 'design_users_container', false,
                                      (res.config && res.config.design_users) || []);
                    }
                    if (res.config) {
                        if (res.config.design_dept_id && !res.rd_dept_id) {
                            $('#design_dept_select').val(res.config.design_dept_id);
                            loadDeptUsers(res.config.design_dept_id, 'design_users_container', false, res.config.design_users);
                        }
                        if (res.config.extra_depts && res.config.extra_depts[0]) {
                            $('#extra1_dept_select').val(res.config.extra_depts[0].dept_id);
                            loadDeptUsers(res.config.extra_depts[0].dept_id, 'extra1_users_container', true, res.config.extra_depts[0].users);
                        }
                        if (res.config.extra_depts && res.config.extra_depts[1]) {
                            $('#extra2_dept_select').val(res.config.extra_depts[1].dept_id);
                            loadDeptUsers(res.config.extra_depts[1].dept_id, 'extra2_users_container', true, res.config.extra_depts[1].users);
                        }
                    }
                    // 指定特定設計＝存檔自動轉生管：把已存的名單勾回來
                    var autoIds = (res.auto_pmget_ates || []).map(String);
                    $('#auto_pmget_container .auto-pmget-check').each(function() {
                        $(this).prop('checked', autoIds.indexOf(String($(this).val())) !== -1);
                    });
                    $('#designerSettingModal').modal('show');
                }
            }, 'json');
        }

        function loadDeptUsers(deptId, containerId, withDesc, selectedUsers) {
            var $container = $('#' + containerId);
            $container.empty();
            if (!deptId) return;
            
            $.post('', { action: 'get_dept_data', dept_id: deptId }, function(res) {
                if (res.success) {
                    res.users.forEach(function(u) {
                        var isChecked = false;
                        var descVal = '';
                        if (selectedUsers) {
                            if (withDesc) {
                                var found = selectedUsers.find(function(su) { return su.id == u.id; });
                                if (found) { isChecked = true; descVal = found.desc || ''; }
                            } else {
                                if (selectedUsers.some(function(id) { return id == u.id; })) isChecked = true;
                            }
                        }
                        if (withDesc) {
                            // 有說明欄位：用 flex 排版讓 checkbox 和說明輸入框在同一行
                            var html = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">' +
                                '<label style="margin:0;font-weight:normal;white-space:nowrap;min-width:80px;">' +
                                '<input type="checkbox" class="user-check" value="' + u.id + '" ' + (isChecked ? 'checked' : '') + '> ' + escapeHtml(u.user_cname) + '</label>' +
                                '<input type="text" class="form-control input-sm user-desc" placeholder="說明（如：外包/支援）" value="' + escapeHtml(descVal) + '" style="flex:1;max-width:200px;">' +
                                '</div>';
                        } else {
                            var html = '<div class="checkbox" style="margin:3px 0;"><label style="font-weight:normal;">' +
                                '<input type="checkbox" class="user-check" value="' + u.id + '" ' + (isChecked ? 'checked' : '') + '> ' + escapeHtml(u.user_cname) + '</label></div>';
                        }
                        $container.append(html);
                    });
                }
            }, 'json');
        }

        function saveDesignerConfig() {
            var config = { design_dept_id: $('#design_dept_select').val(), design_users: [], extra_depts: [] };
            $('#design_users_container .user-check:checked').each(function() { config.design_users.push($(this).val()); });
            var extra1Id = $('#extra1_dept_select').val();
            if (extra1Id) { var users = []; $('#extra1_users_container .user-check:checked').each(function() { users.push({ id: $(this).val(), desc: $(this).closest('.checkbox').find('.user-desc').val() }); }); config.extra_depts.push({ dept_id: extra1Id, users: users }); }
            var extra2Id = $('#extra2_dept_select').val();
            if (extra2Id) { var users = []; $('#extra2_users_container .user-check:checked').each(function() { users.push({ id: $(this).val(), desc: $(this).closest('.checkbox').find('.user-desc').val() }); }); config.extra_depts.push({ dept_id: extra2Id, users: users }); }
            $.post('', { action: 'save_designer_config', config: JSON.stringify(config), auto_pmget_ates: JSON.stringify(collectAutoPmgetIds()) }, function(res) { if (res.success) { alert('設定已儲存，頁面將重新整理'); location.reload(); } else { alert('儲存失敗: ' + res.message); } }, 'json');
        }

        // --- 指定特定設計(技術)＝訂單存檔自動轉生管 ---
        function collectAutoPmgetIds() {
            var ids = [];
            $('#auto_pmget_container .auto-pmget-check:checked').each(function() { ids.push($(this).val()); });
            return ids;
        }

        // 先存名單，再試算會影響幾筆既有訂單，使用者確認後才真的寫入
        function runAutoPmgetBackfill() {
            var NL = String.fromCharCode(10);
            var ids = collectAutoPmgetIds();
            $.post('', { action: 'save_auto_pmget', ates: JSON.stringify(ids) }, function(res) {
                if (!res || !res.success) { alert('設定儲存失敗：' + ((res && res.message) || '未知錯誤')); return; }
                $.post('', { action: 'auto_pmget_backfill', dry: 1 }, function(r2) {
                    if (!r2 || !r2.success) { alert('試算失敗：' + ((r2 && r2.message) || '未知錯誤')); return; }
                    var nSet = r2.result.set || 0, nClr = r2.result.clear || 0;
                    if (nSet === 0 && nClr === 0) { alert('設定已儲存。既有的未結案訂單沒有需要異動的資料。'); return; }
                    if (!confirm('設定已儲存。' + NL + NL + '即將對既有的未結案訂單套用：' + NL + '・自動補上轉生管日：' + nSet + ' 筆' + NL + '・退回（清除系統自動蓋的轉生管日）：' + nClr + ' 筆' + NL + NL + '人工按過「轉生管」鈕的訂單不受影響。要執行嗎？')) return;
                    $.post('', { action: 'auto_pmget_backfill', dry: 0 }, function(r3) {
                        if (!r3 || !r3.success) { alert('執行失敗：' + ((r3 && r3.message) || '未知錯誤')); return; }
                        alert('已完成：補上 ' + (r3.result.set || 0) + ' 筆、退回 ' + (r3.result.clear || 0) + ' 筆，頁面將重新整理。');
                        location.reload();
                    }, 'json');
                }, 'json');
            }, 'json');
        }

        // --- Shared Modal Functions ---
        function openSharedModal(title, url, pk) { // Add pk parameter
            var finalUrl = url;
            if (pk) {
                finalUrl += '?pk=' + pk; // Append pk to URL
            }
            $('#sharedModalTitle').text(title);
            // 儲存 url 以便內容可以自我重新載入
            $('#sharedModalBody').data('reload-url', finalUrl); 
            $('#sharedModalBody').html('<div class="text-center" style="padding: 20px;"><i class="fa fa-spinner fa-spin fa-3x"></i><p style="margin-top: 10px;">載入中...</p></div>');
            $('#sharedDynamicModal').modal('show');
            $('#sharedModalBody').load(finalUrl, function(response, status, xhr) {
                if (status == "error") {
                    $(this).html("<p>抱歉，載入內容時發生錯誤: " + xhr.status + " " + xhr.statusText + "</p>");
                }
            });
        }

        // --- Quotation Modal Functions ---
        function openQuotationModal() {
            $('#quotationSelectModal').modal('show');
            // Trigger an initial search to show recent quotations
            searchQuotations('');
        }

        var searchDebounce;
        $('#quotation-search-input').on('keyup', function() {
            clearTimeout(searchDebounce);
            var term = $(this).val();
            searchDebounce = setTimeout(function() {
                searchQuotations(term);
            }, 300); // 300ms delay
        });

        function searchQuotations(term) {
            var $tbody = $('#quotationListTable tbody');
            $tbody.html('<tr><td colspan="10" class="text-center"><i class="fa fa-spinner fa-spin"></i> 搜尋中...</td></tr>');

            $.post('', {
                action: 'search_quotations',
                term: term
            }, function(res) {
                $tbody.empty();
                if (res.success && res.data.length > 0) {
                    res.data.forEach(function(item) {
                        var tr = `<tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-success select-quotation-item">選取</button>
                            </td>
                            <td>${escapeHtml(item.quote_no)}</td>
                            <td>${escapeHtml(item.quote_date)}</td>
                            <td>${escapeHtml(item.client_name)}</td>
                            <td>${escapeHtml(item.product_id)}</td>
                            <td>${escapeHtml(item.specification)}</td>
                            <td>${escapeHtml(item.quantity)}</td>
                            <td>${escapeHtml(item.unit_price)}</td>
                            <td>${escapeHtml(item.processes)}</td>
                            <td>${escapeHtml(item.process_notes || item.quote_note || '')}</td>
                        </tr>`;
                        var $tr = $(tr);
                        $tr.data('item', item);
                        $tbody.append($tr);
                    });
                } else {
                    $tbody.html('<tr><td colspan="10" class="text-center">找不到符合的報價單項目</td></tr>');
                }
            }, 'json').fail(function() {
                $tbody.html('<tr><td colspan="10" class="text-center text-danger">搜尋失敗，請檢查網路連線。</td></tr>');
            });
        }

        $('#quotationListTable').on('click', '.select-quotation-item', function() {
            var item = $(this).closest('tr').data('item');
            var $form = $('#newOrderForm');

            $form.find('input[name="Client_Name"]').val(item.client_name).trigger('input');
            $form.find('input[name="d_id"]').val(item.product_id).trigger('input');
            $form.find('input[name="Process"]').val(item.specification);
            $form.find('input[name="Qty"]').val(item.quantity);
            $form.find('input[name="unit_price"]').val(item.unit_price);
            $form.find('textarea[name="Order_ps"]').val(`從報價單 ${item.quote_no} 帶入。`);

            $('#quotationSelectModal').modal('hide');
        });
        // --- Autocomplete ---
        function setupAutocomplete(inputId, suggestionsId, type) {
            const $input = $(inputId);
            const $suggestions = $(suggestionsId);

            // Add blur event to clear if no valid selection is made
            $input.on('blur', function() {
                // Use a small timeout to allow click on suggestion to happen before blur
                setTimeout(function() {
                    if ($suggestions.is(':visible')) {
                        return; // Don't clear if user is about to click a suggestion
                    }
                    var pk = (type === 'customer') ? $('#selected_customer_pk').val() : $('#selected_part_pk').val();
                    if (!pk || pk === '0') {
                        if ($input.val() !== '') {
                            $input.val('');
                            // ※ 修改：不清除製程欄位，製程只有從報價單帶入時才覆蓋
                            showToast('請從建議列表中選擇一個有效的項目。', 'info');
                        }
                    }
                }, 250); // 250ms delay
            });

            $input.on('input', function() {
                const term = $(this).val();

                if (term.length < 1) {
                    $suggestions.hide().empty();
                    return;
                }

                // As user types, clear the selected PK. It will be re-validated on selection or exact match.
                if (type === 'customer') {
                    $('#selected_customer_pk').val('');
                } else if (type === 'part') {
                    $('#selected_part_pk').val('');
                }

                $.post('', {
                    action: 'search_data',
                    type: type,
                    term: term,
                    customer_id: (type === 'part') ? ($('#selected_customer_pk').val() || '') : ''
                }, function(res) {
                    if (res.success && res.data.length > 0) {
                        let itemsHtml = '';
                        let exactMatchFound = false;
                        if (type === 'customer') {
                            res.data.forEach(function(item) {
                                const safePk = $('<div>').text(item.customer_id).html();
                                const safeName = $('<div>').text(item.customer).html();
                                itemsHtml += `<div class="suggestion-item" data-pk="${safePk}" data-name="${safeName}">${safePk} - ${safeName}</div>`;
                                if (item.customer.toLowerCase() === term.toLowerCase()) {
                                    $('#selected_customer_pk').val(item.customer_id);
                                    exactMatchFound = true;
                                }
                            });
                        } else if (type === 'part') {
                            res.data.forEach(function(item) {
                                const safePk = $('<div>').text(item.d_id).html();
                                const safeDisplayId = $('<div>').text(item.D_Setting_Id).html();
                                const safeSpec = $('<div>').text(item.Spec_No).html();
                                const safeClientId = $('<div>').text(item.Customer_Id).html();
                                const safeClientName = $('<div>').text(item.Client_Name).html();
                                const safeDrawingNo = (item.Drawing_No && item.Drawing_No !== item.D_Setting_Id) ? $('<div>').text(item.Drawing_No).html() : '';
                                let drawingNoBadge = safeDrawingNo ? ` <span style="font-size:10px;color:#1a7abf;">代：${safeDrawingNo}</span>` : '';
                                // 用客戶代號／等同料號查到的：標示「＝被查到的代號」，選取後綁的仍是我方正確料號
                                if (item.alias_hit) {
                                    drawingNoBadge += ` <span style="font-size:10px;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:0 4px;">＝${$('<div>').text(item.alias_hit).html()}</span>`;
                                }
                                // 組合件 / 子件標籤（子件＝出現在任一組合件 BOM 中的料號）
                                let bomBadge = '';
                                if (parseInt(item.Is_Assembly) === 1) {
                                    bomBadge = ' <span style="background:#3498db;color:#fff;border-radius:3px;padding:0 4px;font-size:10px;"><i class="fa fa-cubes" style="font-size:9px;"></i> 組合件</span>';
                                } else if (parseInt(item.Is_Bom_Child) === 1) {
                                    bomBadge = ' <span style="background:#95a5a6;color:#fff;border-radius:3px;padding:0 4px;font-size:10px;">子件</span>';
                                }
                                itemsHtml += `<div class="suggestion-item" data-pk="${safePk}" data-display-id="${safeDisplayId}" data-spec="${safeSpec}" data-client-id="${safeClientId}" data-client-name="${safeClientName}" data-drawing-no="${safeDrawingNo}">${safeDisplayId}${drawingNoBadge}${bomBadge} (${item.Spec_No || '無規格'}) - ${item.Client_Name || '無客戶'}</div>`;
                                if (item.D_Setting_Id.toLowerCase() === term.toLowerCase()) {
                                    $('#selected_part_pk').val(item.d_id);
                                    if ($('input[name="Process"]').val() === '') {
                                        $('input[name="Process"]').val(item.Spec_No || '');
                                    }
                                    // 綁定料號後客戶由料號決定：一律以料號客戶覆蓋，避免與後端資料不符
                                    $('#client_name_input').val(item.Client_Name || '');
                                    $('#selected_customer_pk').val(item.Customer_Id || '');
                                    exactMatchFound = true;
                                }
                            });
                        }
                        $suggestions.html(itemsHtml).show();
                    } else {
                        $suggestions.hide().empty();
                        // No results, ensure pk is cleared
                        if (type === 'customer') $('#selected_customer_pk').val('');
                        else if (type === 'part') $('#selected_part_pk').val('');
                    }
                }, 'json');
            });
            $suggestions.on('click', '.suggestion-item', function() {
                if (type === 'customer') {
                    $input.val($(this).data('name'));
                    $('#selected_customer_pk').val($(this).data('pk'));
                } else if (type === 'part') {
                    $input.val($(this).data('display-id'));
                    $('#selected_part_pk').val($(this).data('pk'));
                    $('#selected_part_drawing_no').val($(this).data('drawing-no') || '');
                    // ※ 修改：只有製程欄位為空時才帶入料號規格，不覆蓋已填內容
                    if ($('input[name="Process"]').val().trim() === '') {
                        $('input[name="Process"]').val($(this).data('spec') || '');
                    }
                    $('#client_name_input').val($(this).data('client-name') || '');
                    $('#selected_customer_pk').val($(this).data('client-id') || '');
                }
                $suggestions.hide().empty();
                updateIdBadges(); // ← 選取後立即更新 ID badge
            });
        }

        // =====================================================================
        // ── 拆批管理 JS ──────────────────────────────────────────────────────
        // =====================================================================
        var _splitParentId = null;  // 目前操作的主訂單 Order_id
        // ── 開啟拆批 Modal ────────────────────────────────────────────────────
        function openSplitModal() {
            var orderId = $('#btn-open-split').data('order-id') || $('#hidden_Order_id').val();

            // 新增模式：先執行儲存，再開拆批（flag 觸發）
            if (!orderId) {
                window._splitAfterSave = true;
                submitNewOrder(false);
                return;
            }

            _splitParentId = parseInt(orderId);
            $('#split-editing-id').val('');
            $('#split-input-date').val('');
            $('#split-input-qty').val('');
            $('#split-input-ps').val('');
            $('#split-qty-warn').hide();
            $('#split-form-title').text('新增子批次');
            $('#split-submit-label').text('新增批次');
            $('#btn-split-cancel-edit').hide();
            $('#split-list-container').html('<div class="text-center" style="padding:10px;"><i class="fa fa-spinner fa-spin"></i></div>');

            loadSplitList();
            $('#splitModal').modal('show');
        }

        // ── 載入子批次列表 ────────────────────────────────────────────────────
        function loadSplitList() {
            $.post('../../src/store/_NewOrder_Track.php', {
                action: 'get_splits',
                parent_order_id: _splitParentId
            }, function(res) {
                if (!res.success) { showToast(res.message || '載入失敗'); return; }

                var p = res.parent;
                $('#split-parent-oo').text(p.Order_oo);
                $('#split-parent-did').text(p.d_id);
                $('#split-parent-qty').text(parseInt(p.Qty).toLocaleString());
                $('#split-used-qty').text(parseInt(res.used_qty).toLocaleString());
                $('#split-remaining-qty').text(parseInt(res.remaining).toLocaleString());

                var $c = $('#split-list-container').empty();
                if (res.splits.length === 0) {
                    $c.html('<div style="color:#bbb;font-size:11px;text-align:center;padding:12px;">尚無子批次</div>');
                    $('#btn-delete-all-splits').hide();
                    return;
                }

                $('#btn-delete-all-splits').show();
                var html = '<table style="width:100%;font-size:11px;border-collapse:collapse;">';
                html += '<thead><tr style="background:#f0f4f8;"><th style="padding:4px 6px;width:30px;">#</th><th style="padding:4px 6px;">交期</th><th style="padding:4px 6px;text-align:right;">數量</th><th style="padding:4px 6px;">備註</th><th style="padding:4px 6px;width:70px;text-align:center;">操作</th></tr></thead><tbody>';
                res.splits.forEach(function(sp) {
                    html += '<tr style="border-bottom:1px solid #eee;" data-split-id="' + sp.Order_id + '">';
                    html += '<td style="padding:4px 6px;color:#1ABB9C;font-weight:600;">' + parseInt(sp.split_seq) + '</td>';
                    html += '<td style="padding:4px 6px;">' + escapeHtml(sp.delivery_fmt) + '</td>';
                    html += '<td style="padding:4px 6px;text-align:right;font-weight:600;">' + parseInt(sp.Qty).toLocaleString() + '</td>';
                    html += '<td style="padding:4px 6px;color:#888;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(sp.Order_ps||'') + '">' + escapeHtml(sp.Order_ps||'-') + '</td>';
                    html += '<td style="padding:4px 6px;text-align:center;">';
                    html += '<button type="button" class="btn btn-xs btn-info" onclick="editSplit(' + sp.Order_id + ',\'' + escapeHtml(sp.delivery_fmt) + '\',' + parseInt(sp.Qty) + ',\'' + escapeHtml(sp.Order_ps||'') + '\')" title="編輯"><i class="fa fa-pencil"></i></button> ';
                    html += '<button type="button" class="btn btn-xs btn-danger" onclick="deleteSplit(' + sp.Order_id + ')" title="刪除"><i class="fa fa-trash"></i></button>';
                    html += '</td></tr>';
                });
                html += '</tbody></table>';
                $c.html(html);

            }, 'json').fail(function() { showToast('載入子批次失敗'); });
        }

        // ── 切換到編輯子批次模式 ──────────────────────────────────────────────
        function editSplit(splitId, date, qty, ps) {
            $('#split-editing-id').val(splitId);
            $('#split-input-date').val(date);
            $('#split-input-qty').val(qty);
            $('#split-input-ps').val(ps);
            $('#split-form-title').text('編輯子批次 #' + splitId);
            $('#split-submit-label').text('儲存修改');
            $('#btn-split-cancel-edit').show();
            $('#split-qty-warn').hide();
            $('#split-input-date').focus();
        }

        function cancelSplitEdit() {
            $('#split-editing-id').val('');
            $('#split-input-date').val('');
            $('#split-input-qty').val('');
            $('#split-input-ps').val('');
            $('#split-form-title').text('新增子批次');
            $('#split-submit-label').text('新增批次');
            $('#btn-split-cancel-edit').hide();
            $('#split-qty-warn').hide();
        }

        // ── 提交子批次（新增或更新）──────────────────────────────────────────
        function submitSplit() {
            var editId = $('#split-editing-id').val();
            var date   = $('#split-input-date').val().trim();
            var qty    = parseInt($('#split-input-qty').val()) || 0;
            var ps     = $('#split-input-ps').val().trim();
            var remain = parseInt($('#split-remaining-qty').text().replace(/,/g,'')) || 0;

            if (!date) { showToast('請選擇交期'); return; }
            if (qty <= 0) { showToast('數量必須大於0'); return; }

            // 前端數量驗證
            var allowQty = editId ? (remain + parseInt($('tr[data-split-id="'+editId+'"] td:eq(2)').text().replace(/,/g,'') || 0)) : remain;
            if (qty > allowQty) {
                $('#split-qty-warn').text('超過可拆數量 (' + allowQty + ')').show();
                return;
            }
            $('#split-qty-warn').hide();

            var action = editId ? 'update_split' : 'add_split';
            var postData = { action: action, split_date: date, split_qty: qty, split_ps: ps };
            if (editId) { postData.split_order_id = editId; }
            else { postData.parent_order_id = _splitParentId; }

            $('#btn-split-submit').prop('disabled', true);
            $.post('../../src/store/_NewOrder_Track.php', postData, function(res) {
                $('#btn-split-submit').prop('disabled', false);
                if (!res.success) { showToast(res.message || '操作失敗'); return; }
                showToast(res.message || '成功');
                cancelSplitEdit();
                loadSplitList();
                // 刷新主列表的子批展開列
                refreshSplitRow(_splitParentId);
            }, 'json').fail(function() {
                $('#btn-split-submit').prop('disabled', false);
                showToast('請求失敗，請檢查網路');
            });
        }

        // ── 刪除單筆子批次 ────────────────────────────────────────────────────
        function deleteSplit(splitId) {
            if (!confirm('確定刪除此子批次？')) return;
            $.post('../../src/store/_NewOrder_Track.php', { action: 'delete_split', split_order_id: splitId }, function(res) {
                if (!res.success) { showToast(res.message || '刪除失敗'); return; }
                showToast('已刪除');
                loadSplitList();
                refreshSplitRow(_splitParentId);
            }, 'json');
        }

        // ── 刪除所有子批次 ────────────────────────────────────────────────────
        function deleteAllSplits() {
            if (!confirm('確定撤銷此訂單的所有拆批？此操作不可復原。')) return;
            $.post('../../src/store/_NewOrder_Track.php', { action: 'delete_all_splits', parent_order_id: _splitParentId }, function(res) {
                if (!res.success) { showToast(res.message || '操作失敗'); return; }
                showToast(res.message);
                cancelSplitEdit();
                loadSplitList();
                // 移除主列表的子批展開列
                $('tr.split-expand-row[data-parent="' + _splitParentId + '"]').remove();
            }, 'json');
        }

        // ── 刷新主列表中對應主訂單的子批展開列 ──────────────────────────────
        function refreshSplitRow(parentId) {
            $.post('../../src/store/_NewOrder_Track.php', { action: 'get_splits', parent_order_id: parentId }, function(res) {
                var $existingRow = $('tr.split-expand-row[data-parent="' + parentId + '"]');
                if (!res.success || !res.splits || res.splits.length === 0) {
                    $existingRow.remove();
                    return;
                }
                // 重建子批展開列
                var colCount = $('table#orderTable thead tr th').length;
                var html = '<div style="display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;">';
                html += '<span style="font-size:10px;color:#888;font-weight:600;white-space:nowrap;"><i class="fa fa-code-fork" style="color:#1ABB9C;"></i> 拆批：</span>';
                res.splits.forEach(function(sp) {
                    html += '<span style="font-size:10px;background:#fff;border:1px solid #d0e8d8;border-radius:4px;padding:2px 6px;color:#333;white-space:nowrap;">';
                    html += '<span style="color:#1ABB9C;font-weight:600;">#' + parseInt(sp.split_seq) + '</span> ';
                    html += '<span style="color:#555;">' + escapeHtml(sp.delivery_fmt) + '</span> ';
                    html += '<strong>' + parseInt(sp.Qty).toLocaleString() + '</strong>件';
                    if (sp.Order_ps) { html += '<span style="color:#999;font-style:italic;"> · ' + escapeHtml(sp.Order_ps.substring(0,20)) + (sp.Order_ps.length>20?'…':'') + '</span>'; }
                    html += '</span>';
                });
                html += '</div>';

                var newRowHtml = '<tr class="split-expand-row" data-parent="' + parentId + '" style="background:#f9fafb;"><td colspan="' + colCount + '" style="padding:2px 8px 4px 30px;border-top:none;"><div class="split-expand-content">' + html + '</div></td></tr>';

                if ($existingRow.length > 0) {
                    $existingRow.replaceWith(newRowHtml);
                } else {
                    // 找主訂單列，在其後插入
                    $('tr[data-orderid="' + parentId + '"]').after(newRowHtml);
                }
            }, 'json');
        }

        // ═══════════════════════════════════════════════════════════════════
        // 設計備註查看（訂單追蹤用）
        // ═══════════════════════════════════════════════════════════════════
        function showOtDesignNotes(partId, custId, custName) {
            var title = partId || '';
            if (custName) title += (title ? ' / ' : '') + custName;
            document.getElementById('ot-dn-modal-title').textContent = title;
            document.getElementById('ot-dn-modal-content').innerHTML = '<i class="fa fa-spinner fa-spin"></i> 載入中…';
            var mdmLink = document.getElementById('ot-dn-mdm-link');
            if (partId && mdmLink) mdmLink.href = '../../views/pages/master_data_management.php#parts';
            $('#ot-design-notes-modal').modal('show');

            $.post('', { action: 'get_design_notes_ot', part_id: partId, cust_id: custId }, function(res) {
                if (!res.success) {
                    document.getElementById('ot-dn-modal-content').innerHTML =
                        '<div class="alert alert-danger">' + escapeHtml(res.message || '載入失敗') + '</div>';
                    return;
                }
                var d = res.data, html = '';
                if (d.nas_url) _otDnNasUrl = d.nas_url;
                if (d.part_notes && d.part_notes.length) {
                    html += '<div style="font-weight:700;color:#c0392b;font-size:12px;margin-bottom:6px;">'
                          + '<i class="fa fa-file-text-o"></i> 料號設計備註（' + escapeHtml(partId) + '）</div>';
                    d.part_notes.forEach(function(n) { html += _renderOtDnNote(n); });
                }
                if (d.cust_notes && d.cust_notes.length) {
                    html += '<div style="font-weight:700;color:#c0392b;font-size:12px;margin:' + (d.part_notes.length ? '12px' : '0') + ' 0 6px;">'
                          + '<i class="fa fa-user"></i> 客戶設計備註（' + escapeHtml(custName || custId) + '）</div>';
                    d.cust_notes.forEach(function(n) { html += _renderOtDnNote(n); });
                }
                if (!html) html = '<div class="alert alert-info" style="margin:0;">暫無設計備註</div>';
                document.getElementById('ot-dn-modal-content').innerHTML = html;
            }, 'json').fail(function() {
                document.getElementById('ot-dn-modal-content').innerHTML =
                    '<div class="alert alert-danger">網路錯誤，請重試</div>';
            });
        }

        var _otDnNasUrl = '../../src/store/NoteImage_API.php?f=';  // 實際值由 get_design_notes_ot 回傳

        // 附件渲染：圖片直接縮圖、PDF/Office 等顯示檔案圖示（仿主檔管理 _noteFileHtml，唯讀）
        function _otDnFileHtml(img, url) {
            var fname = img.file_name || '';
            var ext   = fname.split('.').pop().toLowerCase();
            var imgExts = ['jpg','jpeg','png','gif','webp','bmp','tif','tiff','svg'];
            if (imgExts.indexOf(ext) >= 0) {
                return '<a href="' + url + '" target="_blank">'
                     + '<img src="' + url + '" style="max-width:220px;max-height:180px;width:auto;height:auto;object-fit:contain;border:1px solid #ddd;border-radius:4px;"'
                     + ' onerror="this.parentNode.style.display=\'none\'"></a>';
            }
            var isPdf   = (ext === 'pdf');
            var iconMap = { doc:'fa-file-word-o',docx:'fa-file-word-o',xls:'fa-file-excel-o',xlsx:'fa-file-excel-o',ppt:'fa-file-powerpoint-o',pptx:'fa-file-powerpoint-o',txt:'fa-file-text-o',csv:'fa-file-text-o',zip:'fa-file-zip-o',rar:'fa-file-zip-o','7z':'fa-file-zip-o',mp4:'fa-file-video-o',avi:'fa-file-video-o',mov:'fa-file-video-o',dwg:'fa-file-code-o',dxf:'fa-file-code-o',step:'fa-file-code-o',stp:'fa-file-code-o' };
            var icon    = isPdf ? 'fa-file-pdf-o' : (iconMap[ext] || 'fa-file-o');
            var boxCss  = isPdf ? 'border:1px solid #f5c6c6;background:#fff5f5;color:#c0392b;'
                                : 'border:1px solid #ddd;background:#f8f9fa;color:#555;';
            return '<a href="' + url + '" target="_blank" style="display:inline-flex;flex-direction:column;align-items:center;justify-content:center;width:90px;height:90px;border-radius:4px;text-decoration:none;padding:4px;' + boxCss + '">'
                 + '<i class="fa ' + icon + '" style="font-size:28px;"></i>'
                 + '<span style="font-size:9px;margin-top:5px;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center;">' + escapeHtml(img.original_name || fname) + '</span>'
                 + '</a>';
        }

        function _renderOtDnNote(n) {
            var html = '<div style="background:#fff;border:1px solid #f5c6cb;border-radius:6px;padding:10px;margin-bottom:8px;">';
            html += '<div style="font-size:13px;line-height:1.7;white-space:pre-wrap;word-break:break-word;">'
                  + escapeHtml(n.note_text || '') + '</div>';
            if (n.images && n.images.length) {
                html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">';
                n.images.forEach(function(img) {
                    if (!img.file_name) return;
                    var url = _otDnNasUrl + encodeURIComponent(img.file_name);
                    html += _otDnFileHtml(img, url);
                });
                html += '</div>';
            }
            html += '<div style="font-size:10px;color:#aaa;margin-top:6px;">'
                  + escapeHtml(n.created_at || '') + (n.created_by ? ' · ' + escapeHtml(n.created_by) : '') + '</div>';
            html += '</div>';
            return html;
        }

        // ═══════════════════════════════════════════════════════════════════
        // 客戶篩選 Autocomplete（支援 ID / 名稱模糊搜尋）
        // ═══════════════════════════════════════════════════════════════════
        (function() {
            var $inp = $('#filter-client');
            var $dd  = $('#filter-client-dd');
            var _timer = null;

            $inp.on('input', function() {
                clearTimeout(_timer);
                var kw = $.trim($inp.val());
                if (kw.length === 0) { $dd.hide().empty(); return; }
                _timer = setTimeout(function() {
                    $.post('', { action: 'autocomplete_customers_ot', keyword: kw }, function(res) {
                        if (!res.success || !res.data.length) { $dd.hide().empty(); return; }
                        var html = '';
                        res.data.forEach(function(c) {
                            html += '<div class="ot-cust-ac-item" data-name="' + escapeHtml(c.customer) + '" data-id="' + escapeHtml(c.customer_id) + '"'
                                  + ' style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;">'
                                  + '<span style="color:#337ab7;font-weight:600;">' + escapeHtml(c.customer_id) + '</span>'
                                  + '<span style="color:#555;margin-left:6px;">' + escapeHtml(c.customer) + '</span>'
                                  + '</div>';
                        });
                        $dd.html(html).show();
                    }, 'json');
                }, 280);
            });

            $(document).on('click', '.ot-cust-ac-item', function() {
                $inp.val($(this).data('name'));
                $dd.hide().empty();
                fetchTableData(1);
            });

            $inp.on('keydown', function(e) {
                if (e.key === 'Enter') { $dd.hide().empty(); fetchTableData(1); }
                if (e.key === 'Escape') { $dd.hide().empty(); }
            });

            $(document).on('click', function(e) {
                if (!$(e.target).closest('#filter-client, #filter-client-dd').length) {
                    $dd.hide();
                }
            });
        })();

    </script>

    <!-- ═══ MODAL: 庫存詳情 ═══════════════════════════════════════════════ -->
    <div class="modal fade" id="stock-list-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" style="max-width:520px;width:90%;" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#1a6e3c,#27ae60);padding:10px 15px;">
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:0.9;">&times;</button>
                    <h4 class="modal-title" style="color:#fff;font-size:14px;">
                        <i class="fa fa-archive" style="margin-right:6px;"></i>庫存 — <span id="slm-part-id" style="font-family:Consolas,monospace;"></span>
                    </h4>
                </div>
                <div class="modal-body" style="padding:12px 15px;max-height:65vh;overflow-y:auto;">
                    <div id="slm-body">
                        <div class="text-center" style="padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
                    </div>
                </div>
                <div class="modal-footer" style="padding:8px 15px;">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ MODAL: 訂單追蹤－設計備註查看 ══════════════════════════════════ -->
    <div class="modal fade" id="ot-design-notes-modal" tabindex="-1">
        <div class="modal-dialog" style="max-width:640px;width:90%;"><div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#7f1c1c,#c0392b);">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
                <h4 class="modal-title" style="color:#fff;font-size:15px;">
                    <i class="fa fa-file-text-o"></i> 設計備註 — <span id="ot-dn-modal-title"></span>
                </h4>
            </div>
            <div class="modal-body" style="max-height:70vh;overflow-y:auto;padding:16px;">
                <div id="ot-dn-modal-content"></div>
            </div>
            <div class="modal-footer" style="display:flex;align-items:center;justify-content:space-between;">
                <a id="ot-dn-mdm-link" href="../../views/pages/master_data_management.php" target="_blank"
                    class="btn btn-default btn-sm"><i class="fa fa-external-link"></i> 前往主檔管理</a>
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
            </div>
        </div></div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         訂單變更系統 Modals（變更 / 設定 / 歷史 / 明細）
         ═══════════════════════════════════════════════════════════════════════ -->
    <style>
        #orderChangeModal .oc-input{height:30px;font-size:13px;}
        #orderChangeModal .oc-input::-webkit-outer-spin-button,
        #orderChangeModal .oc-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
        #orderChangeModal input.oc-input[type=number]{-moz-appearance:textfield;appearance:textfield;}
        .oc-orig-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px 12px;}
        .oc-orig-grid .oc-cell{font-size:12px;}
        .oc-orig-grid .oc-cell label{display:block;color:#888;font-size:10px;margin-bottom:1px;}
        .oc-orig-grid .oc-cell .v{color:#1a3a50;font-weight:600;word-break:break-all;}
        .oc-edit-row{display:flex;align-items:center;gap:8px;margin-bottom:7px;}
        .oc-edit-row > label{width:72px;flex-shrink:0;font-size:12px;color:#555;text-align:right;}
        .oc-edit-row .oc-orig-hint{font-size:10px;color:#aaa;white-space:nowrap;}
        .oc-tg-box{border:1px solid #ddd;border-radius:4px;padding:6px 8px;max-height:120px;overflow-y:auto;background:#fafafa;}
        .oc-tg-box label{display:inline-flex;align-items:center;gap:3px;font-weight:400;font-size:12px;margin:0 10px 4px 0;cursor:pointer;}
        .oc-chk{display:inline-flex;align-items:center;gap:3px;font-weight:400;font-size:12px;margin:0 6px 2px 0;cursor:pointer;white-space:nowrap;}
        .oc-dept-group{display:inline-block;vertical-align:top;margin:0 16px 8px 0;}
        .oc-dept-hd{font-size:11px;font-weight:700;color:#37474f;background:#eceff1;border-radius:3px;padding:2px 7px;margin-bottom:4px;display:inline-block;}
        .oc-chk-wrap{display:flex;flex-direction:column;gap:2px;padding-left:4px;}
        #oc-targets .oc-chk-grid{display:flex;flex-wrap:wrap;gap:0 4px;}
        .oc-hist-item{border:1px solid #eee;border-radius:4px;padding:6px 8px;margin-bottom:5px;font-size:12px;background:#fff;}
        .oc-diff-tag{display:inline-block;background:#fff8e1;border:1px solid #ffe082;border-radius:3px;padding:0 5px;margin:1px 3px 1px 0;font-size:11px;color:#795548;}
    </style>

    <!-- 變更跳窗 -->
    <div class="modal fade" id="orderChangeModal" tabindex="-1" role="dialog">
      <div class="modal-dialog" style="width:90%;max-width:920px;" role="document">
        <div class="modal-content">
          <div class="modal-header" style="background:#f0ad4e;color:#fff;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-exchange"></i> 訂單變更 <small id="oc-title-sub" style="color:#fff8e1;"></small></h4>
          </div>
          <div class="modal-body" style="background:#f7f7f7;padding:14px;max-height:74vh;overflow-y:auto;">
            <input type="hidden" id="oc-order-id">
            <!-- 原訂單內容 -->
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;border-bottom:2px solid #5bc0de;padding-bottom:4px;margin-bottom:8px;"><i class="fa fa-file-text-o"></i> 原訂單內容</div>
              <div class="oc-orig-grid">
                <div class="oc-cell"><label>客戶</label><span class="v" id="oc-o-client">—</span></div>
                <div class="oc-cell"><label>料號</label><span class="v" id="oc-o-did">—</span></div>
                <div class="oc-cell"><label>單號</label><span class="v" id="oc-o-no">—</span></div>
                <div class="oc-cell"><label>客戶單號</label><span class="v" id="oc-o-cord">—</span></div>
                <div class="oc-cell"><label>接單日</label><span class="v" id="oc-o-odate">—</span></div>
                <div class="oc-cell"><label>交期</label><span class="v" id="oc-o-del">—</span></div>
                <div class="oc-cell"><label>數量</label><span class="v" id="oc-o-qty">—</span></div>
                <div class="oc-cell"><label>單價</label><span class="v" id="oc-o-price">—</span></div>
                <div class="oc-cell" style="grid-column:1/3;"><label>製程</label><span class="v" id="oc-o-proc">—</span></div>
                <div class="oc-cell" style="grid-column:3/5;"><label>業務備註</label><span class="v" id="oc-o-ps">—</span></div>
              </div>
            </div>
            <!-- 修改部份 -->
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;border-bottom:2px solid #f0ad4e;padding-bottom:4px;margin-bottom:8px;"><i class="fa fa-pencil"></i> 修改部份 <small style="color:#999;font-weight:400;">（僅填寫要變更的欄位，未變更欄位保留原值）</small></div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 18px;">
                <div class="oc-edit-row"><label>交期</label><input type="date" id="oc-e-del" class="form-control input-sm oc-input"><span class="oc-orig-hint" id="oc-h-del"></span></div>
                <div class="oc-edit-row"><label>數量</label><input type="number" id="oc-e-qty" class="form-control input-sm oc-input" step="any"><span class="oc-orig-hint" id="oc-h-qty"></span></div>
                <div class="oc-edit-row"><label>單價</label><input type="number" id="oc-e-price" class="form-control input-sm oc-input" step="any"><span class="oc-orig-hint" id="oc-h-price"></span></div>
                <div class="oc-edit-row"><label>製程</label><input type="text" id="oc-e-proc" class="form-control input-sm oc-input"><span class="oc-orig-hint" id="oc-h-proc"></span></div>
              </div>
              <div class="oc-edit-row" style="align-items:flex-start;"><label style="margin-top:5px;">業務備註</label><textarea id="oc-e-ps" class="form-control input-sm oc-input" rows="2" style="height:auto;"></textarea></div>
            </div>
            <!-- 變更備註 -->
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-commenting-o"></i> 變更備註 <span style="color:#e74c3c;">*</span></div>
              <textarea id="oc-note" class="form-control input-sm" rows="2" placeholder="請說明變更原因 / 內容（必填）"></textarea>
            </div>
            <!-- 通知對象（部門 / 人員 分區）-->
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-bell-o"></i> 通知對象
                <small style="color:#aaa;font-weight:400;">通知顯示於被選對象的通知中心；未選擇則不發送</small></div>
              <div id="oc-targets"><span style="color:#aaa;font-size:12px;">載入中…</span></div>
            </div>
            <!-- 附件 -->
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-paperclip"></i> 附件 <small style="color:#999;font-weight:400;">（儲存於 Z槽）</small></div>
              <input type="file" id="oc-files" multiple style="font-size:12px;">
              <div id="oc-file-preview" style="font-size:11px;color:#666;margin-top:5px;"></div>
            </div>
            <!-- 該訂單變更歷史 -->
            <div class="main-card" style="margin-top:10px;">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-history"></i> 本訂單變更歷史</div>
              <div id="oc-order-history" style="max-height:160px;overflow-y:auto;"><span style="color:#aaa;font-size:12px;">載入中…</span></div>
            </div>
          </div>
          <div class="modal-footer">
            <span id="oc-save-msg" style="float:left;font-size:12px;color:#c0392b;line-height:32px;"></span>
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-warning" id="oc-save-btn" onclick="submitOrderChange()"><i class="fa fa-check"></i> 送出變更</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 設定跳窗（限A權限）-->
    <div class="modal fade" id="changeSettingsModal" tabindex="-1" role="dialog">
      <div class="modal-dialog" style="width:88%;max-width:820px;" role="document">
        <div class="modal-content">
          <div class="modal-header" style="background:#607d8b;color:#fff;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-cog"></i> 訂單變更設定</h4>
          </div>
          <div class="modal-body" style="padding:14px;max-height:74vh;overflow-y:auto;">
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-folder-open-o"></i> 附件儲存路徑（Z槽）</div>
              <input type="text" id="ocs-path" class="form-control input-sm" placeholder="例：Z:\Orders\Changes 或 \\\\server\\share\\Orders\\Changes">
              <div style="font-size:10px;color:#aaa;margin-top:4px;">伺服器須能存取此路徑（依各程序帳號權限）；附件會以 變更單ID 建立子資料夾存放。</div>
            </div>
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-paperclip"></i> 訂單附件儲存路徑</div>
              <div style="font-size:11px;color:#888;margin-bottom:6px;">「新增/編輯訂單」與「OP轉訂單」上傳的附件存放位置；留空則用全站附件根目錄下的「訂單」資料夾（預設值，多數情況不必另外設定）。</div>
              <div class="input-group input-group-sm">
                <input type="text" id="oat-path" class="form-control" placeholder="留空＝使用預設路徑">
                <span class="input-group-btn"><button type="button" class="btn btn-primary" onclick="orderAttachSavePathSetting()">儲存</button></span>
              </div>
              <div id="oat-path-resolved" style="font-size:10px;color:#aaa;margin-top:4px;"></div>
              <div id="oat-path-msg" style="font-size:11px;margin-top:4px;"></div>
            </div>
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-tags"></i> 本頁使用的附件標籤</div>
              <div style="font-size:11px;color:#888;margin-bottom:6px;">類別標籤共用報價單的分類清單；這裡只是挑選「訂單／OP轉訂單上傳附件時」要顯示哪些，避免全部～16個標籤一次列出來很混亂。未曾在此設定過＝預設全部顯示。</div>
              <div id="oat-cats-setting" style="display:flex;flex-wrap:wrap;gap:8px 14px;font-size:12px;margin-bottom:8px;"><span style="color:#aaa;">載入中…</span></div>
              <button type="button" class="btn btn-primary btn-sm" onclick="orderAttachSaveCatsSetting()"><i class="fa fa-save"></i> 儲存</button>
              <span id="oat-cats-msg" style="font-size:11px;margin-left:8px;"></span>
            </div>
            <div class="main-card" style="margin-bottom:10px;">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-print"></i> 訂單變更列印文件（AS 文件綁定）</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;">
                <div>
                  <div style="font-size:12px;color:#666;margin-bottom:4px;"><b>訂單變更單</b>－列印表頭（＝綁定 AS 文件的表單名稱）</div>
                  <input id="ocs-print-header" class="form-control input-sm" readonly disabled
                         style="background:#eee;color:#888;cursor:not-allowed;">
                </div>
                <div>
                  <div style="font-size:12px;color:#666;margin-bottom:4px;"><b>訂單變更單</b>－AS 文件編號（每頁頁尾右下角）</div>
                  <div style="display:flex;gap:6px;">
                    <input id="ocs-print-footer" class="form-control input-sm" readonly disabled
                           style="background:#eee;color:#888;cursor:not-allowed;">
                    <button type="button" class="btn btn-warning btn-sm" style="white-space:nowrap;" onclick="ocsPickAsDoc()">
                      <i class="fa fa-link"></i> 綁定…</button>
                  </div>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                  <div style="font-size:12px;color:#666;margin-bottom:4px;"><b>訂單變更歷史清單</b>－列印表頭（橫式列印）</div>
                  <input id="ocs-hist-header" class="form-control input-sm" readonly disabled
                         style="background:#eee;color:#888;cursor:not-allowed;">
                </div>
                <div>
                  <div style="font-size:12px;color:#666;margin-bottom:4px;"><b>訂單變更歷史清單</b>－AS 文件編號（每頁頁尾右下角）</div>
                  <div style="display:flex;gap:6px;">
                    <input id="ocs-hist-footer" class="form-control input-sm" readonly disabled
                           style="background:#eee;color:#888;cursor:not-allowed;">
                    <button type="button" class="btn btn-warning btn-sm" style="white-space:nowrap;" onclick="ocsPickAsDocHist()">
                      <i class="fa fa-link"></i> 綁定…</button>
                  </div>
                </div>
              </div>
              <div style="font-size:10px;color:#aaa;margin-top:4px;">
                列印大標題固定為本公司全名（發票用全名，取自客戶主檔）；表頭與頁尾編號一律取自綁定的 AS 文件，皆不可手填（全站列印標準 ai-rules/16）。</div>
            </div>
            <div class="main-card">
              <div style="font-weight:700;color:#444;margin-bottom:6px;"><i class="fa fa-users"></i> 可選通知對象設定</div>
              <label style="font-weight:400;font-size:13px;display:inline-flex;align-items:center;gap:5px;margin-bottom:8px;">
                <input type="checkbox" id="ocs-allow-all"> 允許選擇「全體（所有人）」
              </label>
              <div style="margin-bottom:10px;">
                <div style="font-size:12px;color:#666;margin-bottom:4px;">可選部門（勾選）</div>
                <div id="ocs-depts" style="border:1px solid #ddd;border-radius:4px;padding:8px;background:#fafafa;display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:4px 8px;"></div>
              </div>
              <div>
                <div style="font-size:12px;color:#666;margin-bottom:4px;display:flex;align-items:center;gap:8px;">可選人員（依部門分組，搜尋勾選）
                  <input type="text" id="ocs-user-search" class="form-control input-sm" placeholder="搜尋姓名…" style="width:160px;height:26px;" oninput="ocFilterSettingUsers()"></div>
                <div id="ocs-users" style="border:1px solid #ddd;border-radius:4px;padding:8px;background:#fafafa;max-height:260px;overflow-y:auto;"></div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <span id="ocs-msg" style="float:left;font-size:12px;line-height:32px;"></span>
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" onclick="saveChangeSettings()"><i class="fa fa-save"></i> 儲存設定</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 變更歷史跳窗（全部訂單）-->
    <div class="modal fade" id="changeHistoryModal" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-lg" style="width:94%;max-width:1280px;" role="document">
        <div class="modal-content">
          <div class="modal-header" style="background:#5d4037;color:#fff;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-history"></i> 全部訂單變更歷史</h4>
          </div>
          <div class="modal-body" style="padding:12px;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
              <input type="text" id="och-kw" class="form-control input-sm" placeholder="搜尋 變更單號/單號/客戶/料號/內容/備註/變更人" style="width:300px;" onkeydown="if(event.key==='Enter'){och_state.page=1;loadChangeHistory();}">
              <button class="btn btn-sm btn-primary" onclick="och_state.page=1;loadChangeHistory();"><i class="fa fa-search"></i> 搜尋</button>
              <span style="margin-left:auto;display:flex;align-items:center;gap:6px;">
                <label style="font-weight:400;font-size:12px;margin:0;">每頁
                  <select id="och-size" class="input-sm" onchange="och_state.page=1;och_state.size=parseInt(this.value);loadChangeHistory();">
                    <option value="5">5</option><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option>
                  </select> 筆
                </label>
                <button class="btn btn-sm btn-default" onclick="exportChangeHistoryCSV()" title="匯出CSV"><i class="fa fa-file-excel-o"></i> CSV</button>
                <button class="btn btn-sm btn-default" onclick="exportChangeHistoryPDF()" title="匯出PDF"><i class="fa fa-file-pdf-o"></i> PDF</button>
              </span>
            </div>
            <div style="overflow-x:auto;">
              <table class="table table-striped" style="font-size:12px;margin-bottom:6px;">
                <thead><tr style="background:#f0f4f8;">
                  <th>時間</th><th>變更單號</th><th>單號</th><th>客戶</th><th>料號</th><th>變更內容</th><th>備註</th><th>變更人</th><th>通知對象</th><th style="text-align:center;">已閱</th><th style="text-align:center;">附件</th><th style="text-align:center;">明細</th><th style="text-align:center;">操作</th>
                </tr></thead>
                <tbody id="och-tbody"><tr><td colspan="13" class="text-center" style="padding:16px;color:#aaa;">載入中…</td></tr></tbody>
              </table>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <span id="och-info" style="font-size:12px;color:#888;"></span>
              <span id="och-pager" style="display:flex;gap:4px;"></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 變更明細跳窗 -->
    <div class="modal fade" id="changeDetailModal" tabindex="-1" role="dialog" style="z-index:1060;">
      <div class="modal-dialog" style="width:80%;max-width:680px;" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-info-circle"></i> 變更明細</h4>
          </div>
          <div class="modal-body" id="ocd-body" style="padding:14px;max-height:70vh;overflow-y:auto;"></div>
          <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
        </div>
      </div>
    </div>

    <!-- 修改通知對象跳窗（政策B：可新增/刪除對象；全移除=連動刪除通知） -->
    <div class="modal fade" id="ocTargetsModal" tabindex="-1" role="dialog" style="z-index:1070;">
      <div class="modal-dialog" style="width:86%;max-width:640px;" role="document">
        <div class="modal-content">
          <div class="modal-header" style="background:#5d4037;color:#fff;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-bell-o"></i> 修改通知對象 <small id="oct-sub" style="color:#ffe0b2;"></small></h4>
          </div>
          <div class="modal-body" style="padding:12px;">
            <div id="oct-targets" class="oc-tg-box" style="max-height:340px;"><span style="color:#aaa;font-size:12px;">載入中…</span></div>
            <div style="font-size:11px;color:#888;margin-top:6px;">新勾選的對象會收到此變更通知；取消勾選的對象將不再列入簽收統計。全部取消＝移除此變更的通知。</div>
          </div>
          <div class="modal-footer">
            <span id="oct-msg" style="float:left;font-size:12px;"></span>
            <button type="button" class="btn btn-primary" id="oct-save-btn" onclick="ocSaveTargets()"><i class="fa fa-check"></i> 儲存</button>
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ 訂單變更系統 JS ═══════════════════════════════════════════════════ -->
    <!-- AS 文件編號綁定選擇器（全站共用，可輸入編號/名稱即時篩選；見 ai-rules/16） -->
    <script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
    <script>
    // 訂單變更列印綁定的 AS 文件：ocsAsDocId＝變更單、ocsAsDocHistId＝歷史清單（兩者各自綁定）
    var ocsAsDocs = [], ocsAsDocId = 0, ocsAsDocHistId = 0;
    (function(){
        var OC_API = '../../src/store/_OrderChange_API.php';
        function ocEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        function ocToast(m){ if(typeof showToast==='function') showToast(m); else alert(m); }
        // 共用 AJAX（FormData）
        function ocApi(action, params, isFile){
            var fd;
            if (isFile) { fd = params; fd.append('action', action); }
            else { fd = new FormData(); fd.append('action', action); for (var k in params){ var v=params[k];
                if (Array.isArray(v)) v.forEach(function(x){ fd.append(k+'[]', x); }); else fd.append(k, v==null?'':v); } }
            return fetch(OC_API, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.json(); });
        }

        // 快取（供明細查詢）
        var ocRowCache = {};
        function ocCache(rows){ (rows||[]).forEach(function(r){ ocRowCache[r.id]=r; }); }

        // 附件累積清單（可多次挑選、逐一移除，送出時一次全部上傳）
        var ocSelFiles = [];
        function ocRenderFileList(){
            var box = document.getElementById('oc-file-preview');
            if (!ocSelFiles.length) { box.innerHTML = '<span style="color:#aaa;">尚未選擇附件（可多選、可分次加入）</span>'; return; }
            box.innerHTML = ocSelFiles.map(function(f, i){
                return '<div style="display:flex;justify-content:space-between;align-items:center;padding:2px 4px;border-bottom:1px dashed #eee;">'
                    + '<span><i class="fa fa-file-o"></i> ' + ocEsc(f.name) + ' <span style="color:#aaa;">(' + Math.round(f.size/1024) + ' KB)</span></span>'
                    + '<a href="javascript:;" onclick="ocRemoveFile(' + i + ')" style="color:#c0392b;" title="移除"><i class="fa fa-times"></i></a></div>';
            }).join('');
        }
        window.ocRemoveFile = function(i){ ocSelFiles.splice(i, 1); ocRenderFileList(); };
        function ocAddFiles(fileList){
            for (var i = 0; i < fileList.length; i++) {
                var f = fileList[i];
                var dup = ocSelFiles.some(function(x){ return x.name === f.name && x.size === f.size; });
                if (!dup) ocSelFiles.push(f);
            }
            ocRenderFileList();
        }

        // 勾選框
        function ocChk(cls, value, label, checked){
            return '<label class="oc-chk"><input type="checkbox" class="'+cls+'" value="'+value+'"'+(checked?' checked':'')+'> '+ocEsc(label)+'</label>';
        }
        // 將人員依部門分組 + 職稱顯示（含兼職）成 HTML
        function ocGroupUsersHtml(rows, cls, prefix, selSet){
            if(!rows || !rows.length) return '<span style="color:#aaa;font-size:12px;">（無可選人員）</span>';
            var groups=[], idx={};
            rows.forEach(function(r){
                var key=r.dept_id+'|'+r.dept_name;
                if(idx[key]===undefined){ idx[key]=groups.length; groups.push({name:r.dept_name, items:[]}); }
                groups[idx[key]].items.push(r);
            });
            return groups.map(function(g){
                var items=g.items.map(function(r){
                    var pos=r.pos_name||'—';
                    var tag=r.is_main? '' : ' <span style="color:#e67e22;">[兼任]</span>';
                    var checked=selSet && selSet.has(String(r.id));
                    return '<label class="oc-chk" style="width:auto;"><input type="checkbox" class="'+cls+'" value="'+prefix+r.id+'"'+(checked?' checked':'')+'> '+ocEsc(r.user_cname)+'<span style="color:#999;">（'+ocEsc(pos)+'）</span>'+tag+'</label>';
                }).join('');
                return '<div class="oc-dept-group"><div class="oc-dept-hd">'+ocEsc(g.name)+'</div><div class="oc-chk-wrap">'+items+'</div></div>';
            }).join('');
        }
        // 同 value 的勾選框互相同步（同一人出現在多個部門時）
        function ocSyncSameValue(container, cls){
            container.addEventListener('change', function(ev){
                var t=ev.target; if(!t.classList || !t.classList.contains(cls)) return;
                container.querySelectorAll('.'+cls+'[value="'+t.value+'"]').forEach(function(c){ if(c!==t) c.checked=t.checked; });
            });
        }

        // ── 開啟變更跳窗 ───────────────────────────────────────────────────
        window.openOrderChange = function(orderId){
            document.getElementById('oc-order-id').value = orderId;
            document.getElementById('oc-save-msg').textContent='';
            document.getElementById('oc-note').value='';
            document.getElementById('oc-files').value='';
            ocSelFiles = [];
            ocRenderFileList();
            ['oc-o-client','oc-o-did','oc-o-no','oc-o-cord','oc-o-odate','oc-o-del','oc-o-qty','oc-o-price','oc-o-proc','oc-o-ps']
                .forEach(function(id){ document.getElementById(id).textContent='—'; });
            ['oc-e-del','oc-e-qty','oc-e-price','oc-e-proc','oc-e-ps'].forEach(function(id){ document.getElementById(id).value=''; });
            document.getElementById('oc-targets').innerHTML='<span style="color:#aaa;font-size:12px;">載入中…</span>';
            document.getElementById('oc-order-history').innerHTML='<span style="color:#aaa;font-size:12px;">載入中…</span>';
            $('#orderChangeModal').modal('show');

            ocApi('get_order', {order_id:orderId}).then(function(res){
                if(!res.success){ ocToast(res.message||'讀取訂單失敗'); return; }
                var d=res.data;
                document.getElementById('oc-title-sub').textContent = '（單號 '+(d.order_no||orderId)+'）';
                document.getElementById('oc-o-client').textContent = d.client_name||'—';
                document.getElementById('oc-o-did').textContent = d.d_id||'—';
                document.getElementById('oc-o-no').textContent = d.order_no||'—';
                document.getElementById('oc-o-cord').textContent = d.client_order||'—';
                document.getElementById('oc-o-odate').textContent = d.order_date||'—';
                document.getElementById('oc-o-del').textContent = d.Delivery_date||'—';
                document.getElementById('oc-o-qty').textContent = d.Qty||'—';
                document.getElementById('oc-o-price').textContent = d.unit_price||'—';
                document.getElementById('oc-o-proc').textContent = d.Processing_items||'—';
                document.getElementById('oc-o-ps').textContent = d.Order_ps||'—';
                // 修改欄位不預帶原值：只填要變更的欄位，留空＝不變更
                // 欄位內不放淺色原值（避免誤以為已填），原值只顯示在欄位旁提示與上方「原訂單內容」
                ['oc-e-del','oc-e-qty','oc-e-price','oc-e-proc','oc-e-ps'].forEach(function(id){
                    var el=document.getElementById(id); el.value=''; el.placeholder='';
                });
                document.getElementById('oc-h-del').textContent = '原:'+(d.Delivery_date||'空');
                document.getElementById('oc-h-qty').textContent = '原:'+(d.Qty||'空');
                document.getElementById('oc-h-price').textContent = '原:'+(d.unit_price||'空');
                document.getElementById('oc-h-proc').textContent = '原:'+(d.Processing_items||'空');
            });
            // 通知對象（部門 / 人員 分區）
            ocApi('get_notify_targets', {}).then(function(res){
                var box=document.getElementById('oc-targets');
                if(!res.success){ box.innerHTML='<span style="color:#c0392b;font-size:12px;">'+ocEsc(res.message||'讀取失敗')+'</span>'; return; }
                var hasDept=(res.allow_all || (res.depts&&res.depts.length));
                var hasUser=(res.user_rows&&res.user_rows.length);
                if(!hasDept && !hasUser){ box.innerHTML='<span style="color:#aaa;font-size:12px;">尚未設定可選通知對象（請管理者至「設定」設定）</span>'; return; }
                var h='';
                // 部門
                var dh='';
                if(res.allow_all) dh+=ocChk('oc-tg','all','全體（所有人）');
                (res.depts||[]).forEach(function(d){ dh+=ocChk('oc-tg','dept-'+d.id, d.name); });
                h+='<div class="oc-dept-group"><div class="oc-dept-hd" style="background:#e3f2fd;color:#1565c0;">部門</div><div class="oc-chk-grid">'+(dh||'<span style="color:#aaa;font-size:12px;">（無）</span>')+'</div></div>';
                // 人員（依部門分組）
                h+='<div class="oc-dept-hd" style="background:#e8f5e9;color:#2e7d32;margin-top:4px;">人員</div>';
                h+='<div style="margin-top:5px;">'+ocGroupUsersHtml(res.user_rows||[], 'oc-tg', 'user-')+'</div>';
                box.innerHTML=h;
                ocSyncSameValue(box, 'oc-tg');
                // 全體 與 其他互斥
                var allCb=box.querySelector('.oc-tg[value="all"]');
                if(allCb){ allCb.addEventListener('change',function(){ if(allCb.checked) box.querySelectorAll('.oc-tg').forEach(function(c){ if(c!==allCb) c.checked=false; }); });
                    box.querySelectorAll('.oc-tg').forEach(function(c){ if(c!==allCb) c.addEventListener('change',function(){ if(c.checked) allCb.checked=false; }); }); }
            });
            // 本訂單歷史
            ocLoadOrderHistory(orderId);
            // 附件選擇：累積加入（可分次多選），選完清空 input 以便再次挑選同名檔
            document.getElementById('oc-files').onchange=function(){
                ocAddFiles(this.files);
                this.value='';
            };
            // Enter 移至下一欄 + 聚焦全選
            setTimeout(function(){
                var ids=['oc-e-del','oc-e-qty','oc-e-price','oc-e-proc','oc-e-ps','oc-note'];
                ids.forEach(function(id,idx){
                    var el=document.getElementById(id); if(!el) return;
                    el.onfocus=function(){ try{ el.select(); }catch(e){} };
                    el.onkeydown=function(ev){ if(ev.key==='Enter' && el.tagName!=='TEXTAREA'){ ev.preventDefault(); var n=document.getElementById(ids[idx+1]); if(n) n.focus(); } };
                });
            },300);
        };

        function ocLoadOrderHistory(orderId){
            ocApi('history_order', {order_id:orderId}).then(function(res){
                var box=document.getElementById('oc-order-history');
                if(!res.success){ box.innerHTML='<span style="color:#c0392b;font-size:12px;">'+ocEsc(res.message||'讀取失敗')+'</span>'; return; }
                ocCache(res.data);
                if(!res.data.length){ box.innerHTML='<span style="color:#aaa;font-size:12px;">尚無變更紀錄</span>'; return; }
                box.innerHTML = res.data.map(function(r){ return ocHistItem(r); }).join('');
            });
        }
        function ocHistItem(r){
            var diffs=[]; try{ diffs=JSON.parse(r.changes_json||'[]'); }catch(e){}
            var tags=diffs.map(function(d){ return '<span class="oc-diff-tag">'+ocEsc(d.label)+': '+ocEsc(d.old||'空')+'→'+ocEsc(d.new||'空')+'</span>'; }).join('');
            var isVoid=String(r.is_void)==='1';
            var voidTag=isVoid? '<span class="oc-diff-tag" style="background:#fdecea;border-color:#f5b7b1;color:#c0392b;font-weight:700;" title="'+ocEsc((r.voided_at||'')+' '+(r.voided_by||'')+(r.void_reason?('：'+r.void_reason):''))+'">作廢</span> ' : '';
            var ops='';
            if(!isVoid){
                if(window.canUpdate) ops+='<a href="javascript:;" onclick="ocEditChange('+r.id+')" title="修改備註" style="font-size:11px;margin-left:8px;"><i class="fa fa-pencil"></i></a>';
                if(window.canUpdate) ops+='<a href="javascript:;" onclick="ocEditTargets('+r.id+')" title="修改通知對象" style="font-size:11px;margin-left:6px;color:#e67e22;"><i class="fa fa-bell-o"></i></a>';
                if(window.canDelete) ops+='<a href="javascript:;" onclick="ocVoidChange('+r.id+')" title="刪除（作廢）" style="font-size:11px;margin-left:6px;color:#c0392b;"><i class="fa fa-trash"></i></a>';
            }
            return '<div class="oc-hist-item"'+(isVoid?' style="opacity:.55;"':'')+'><div style="display:flex;justify-content:space-between;">'
                + '<span style="color:#888;">'+voidTag+(r.change_no?('<span style="color:#5d4037;font-weight:700;font-family:Consolas,monospace;">'+ocEsc(r.change_no)+'</span> · '):'')+ocEsc(r.created_at)+' · '+ocEsc(r.created_by)+'</span>'
                + '<span><a href="javascript:;" onclick="ocOpenDetail('+r.id+')" style="font-size:11px;">明細'+(parseInt(r.att_count)>0?' <i class=\'fa fa-paperclip\'></i>'+r.att_count:'')+'</a>'+ops+'</span></div>'
                + '<div style="margin-top:3px;'+(isVoid?'text-decoration:line-through;':'')+'">'+(tags||'<span style="color:#aaa;">（僅備註）</span>')+'</div>'
                + (r.note?'<div style="color:#795548;margin-top:2px;">備註：'+ocEsc(r.note)+'</div>':'') + '</div>';
        }

        // ── 送出變更 ───────────────────────────────────────────────────────
        window.submitOrderChange = function(){
            var orderId=document.getElementById('oc-order-id').value;
            var note=document.getElementById('oc-note').value.trim();
            var msg=document.getElementById('oc-save-msg');
            if(!note){ msg.textContent='請填寫變更備註'; document.getElementById('oc-note').focus(); return; }
            // 只帶有填值的欄位：留空＝不變更（空欄若送出會被視為「改為空值」清掉原值）
            var newVals={};
            [['Delivery_date','oc-e-del'],['Qty','oc-e-qty'],['unit_price','oc-e-price'],['Processing_items','oc-e-proc'],['Order_ps','oc-e-ps']]
                .forEach(function(p){
                    var v=document.getElementById(p[1]).value;
                    if(v!=null && String(v).trim()!=='') newVals[p[0]]=v;
                });
            var targets=[]; document.querySelectorAll('#oc-targets .oc-tg:checked').forEach(function(c){ targets.push(c.value); });
            var btn=document.getElementById('oc-save-btn'); btn.disabled=true; msg.style.color='#888'; msg.textContent='處理中…';
            ocApi('save_change', {order_id:orderId, note:note, new_values:JSON.stringify(newVals), targets:targets})
            .then(function(res){
                if(!res.success){ btn.disabled=false; msg.style.color='#c0392b'; msg.textContent=res.message||'儲存失敗'; return; }
                var fileArr=ocSelFiles.slice();
                var failed=[];
                // 逐筆循序上傳（避免多檔同時建立目錄競爭）
                (function uploadNext(idx){
                    if(idx>=fileArr.length){
                        btn.disabled=false;
                        $('#orderChangeModal').modal('hide');
                        var t='變更已儲存'; if(res.changed>0) t+='，更新 '+res.changed+' 欄'; if(res.notified) t+='，已發送通知';
                        if(fileArr.length) t+='，附件 '+(fileArr.length-failed.length)+'/'+fileArr.length;
                        ocToast(t + (failed.length? ('；附件失敗：'+failed.join('、')) : ''));
                        if(typeof refreshOrderTable==='function') refreshOrderTable();
                        return;
                    }
                    msg.style.color='#888'; msg.textContent='上傳附件 '+(idx+1)+'/'+fileArr.length+'…';
                    var fd=new FormData(); fd.append('change_id',res.change_id); fd.append('file',fileArr[idx]);
                    ocApi('upload_attach', fd, true).then(function(r){
                        if(!r||!r.success) failed.push(fileArr[idx].name + (r&&r.message?('('+r.message+')'):''));
                        uploadNext(idx+1);
                    }).catch(function(){ failed.push(fileArr[idx].name); uploadNext(idx+1); });
                })(0);
            }).catch(function(){ btn.disabled=false; msg.style.color='#c0392b'; msg.textContent='連線錯誤'; });
        };

        // ── 變更明細 ───────────────────────────────────────────────────────
        window.ocOpenDetail = function(changeId){
            var r=ocRowCache[changeId];
            var body=document.getElementById('ocd-body');
            var voidBar=(r && String(r.is_void)==='1')? ('<div style="background:#fdecea;border:1px solid #f5b7b1;border-radius:4px;padding:6px 9px;font-size:12px;color:#c0392b;font-weight:700;margin-bottom:8px;"><i class="fa fa-ban"></i> 此變更單已作廢'+ocEsc((r.voided_at?('（'+r.voided_at+' '+(r.voided_by||'')+'）'):''))+(r.void_reason?('，原因：'+ocEsc(r.void_reason)):'')+'</div>') : '';
            var head = (r? ((r.change_no?('<div style="font-size:13px;font-weight:700;color:#5d4037;font-family:Consolas,monospace;margin-bottom:4px;"><i class="fa fa-file-text-o"></i> 變更單號 '+ocEsc(r.change_no)+'</div>'):'')
                + '<div style="font-size:12px;color:#888;margin-bottom:8px;">'+ocEsc(r.created_at||'')+' · '+ocEsc(r.created_by||'')
                + (r.order_no?(' · 單號 '+ocEsc(r.order_no)):'') + (r.client_name?(' · '+ocEsc(r.client_name)):'') + (r.d_id?(' · '+ocEsc(r.d_id)):'') +'</div>') : '');
            var diffs=[]; if(r){ try{ diffs=JSON.parse(r.changes_json||'[]'); }catch(e){} }
            var tbl='';
            if(diffs.length){ tbl='<table class="table table-bordered" style="font-size:12px;"><thead><tr style="background:#f5f5f5;"><th>欄位</th><th>原值</th><th>新值</th></tr></thead><tbody>'
                + diffs.map(function(d){ return '<tr><td>'+ocEsc(d.label)+'</td><td style="color:#999;">'+ocEsc(d.old||'空')+'</td><td style="color:#1e7e34;font-weight:600;">'+ocEsc(d.new||'空')+'</td></tr>'; }).join('')
                + '</tbody></table>'; }
            else tbl='<div style="color:#aaa;margin-bottom:8px;">（本筆無欄位變更，僅備註）</div>';
            var note = (r&&r.note)? ('<div style="background:#fff8f0;border:1px solid #f0c891;border-radius:4px;padding:6px 9px;font-size:12px;color:#795548;margin-bottom:8px;">備註：'+ocEsc(r.note)+'</div>') : '';
            body.innerHTML = voidBar + head + tbl + note
                + '<div style="font-weight:700;color:#444;margin:6px 0;"><i class="fa fa-bell-o"></i> 通知對象</div><div id="ocd-notify" style="font-size:12px;color:#aaa;margin-bottom:8px;">載入中…</div>'
                + '<div style="font-weight:700;color:#444;margin:6px 0;"><i class="fa fa-paperclip"></i> 附件</div><div id="ocd-attach" style="font-size:12px;color:#aaa;">載入中…</div>';
            $('#changeDetailModal').modal('show');
            ocApi('change_notify', {change_id:changeId}).then(function(res){
                var box=document.getElementById('ocd-notify'); if(!box) return;
                if(!res.success){ box.innerHTML='<span style="color:#aaa;">—</span>'; return; }
                var parts=[];
                if(res.all) parts.push('<span class="oc-diff-tag" style="background:#fff3e0;border-color:#ffcc80;color:#e65100;">全體（所有人）</span>');
                (res.depts||[]).forEach(function(n){ parts.push('<span class="oc-diff-tag" style="background:#e3f2fd;border-color:#90caf9;color:#1565c0;">部門：'+ocEsc(n)+'</span>'); });
                (res.users||[]).forEach(function(n){ parts.push('<span class="oc-diff-tag" style="background:#e8f5e9;border-color:#a5d6a7;color:#2e7d32;"><i class="fa fa-user"></i> '+ocEsc(n)+'</span>'); });
                box.innerHTML = parts.length? parts.join(' ') : '<span style="color:#aaa;">未發送通知</span>';
            });
            ocApi('list_attach', {change_id:changeId}).then(function(res){
                var box=document.getElementById('ocd-attach');
                if(!res.success||!res.data.length){ box.innerHTML='<span style="color:#aaa;">無附件</span>'; return; }
                box.innerHTML = res.data.map(function(a){
                    return '<div style="padding:3px 0;border-bottom:1px dashed #eee;"><a href="'+OC_API+'?action=download_attach&id='+a.id+'" target="_blank"><i class="fa fa-file-o"></i> '+ocEsc(a.original_name)+'</a> <span style="color:#aaa;">('+ocEsc(a.file_size||'')+' · '+ocEsc(a.uploaded_by||'')+' · '+ocEsc(a.uploaded_at||'')+')</span></div>';
                }).join('');
            });
        };

        // ── 簽收(已閱)狀態：每位收件人是否已點「已閱」──────────────────────
        window.ocShowReadStatus = function(changeId){
            var body=document.getElementById('ocd-body');
            body.innerHTML='<div style="color:#aaa;">載入中…</div>';
            $('#changeDetailModal').modal('show');
            ocApi('change_read_status', {change_id:changeId}).then(function(res){
                if(!res.success){ body.innerHTML='<span style="color:#c0392b;">'+ocEsc(res.message||'讀取失敗')+'</span>'; return; }
                var c=res.change||{};
                var head='<div style="font-size:12px;color:#888;margin-bottom:8px;">變更單號 <b style="color:#5d4037;">'+ocEsc(c.change_no||'—')+'</b> · 單號 '+ocEsc(c.order_no||'')+' · '+ocEsc(c.client_name||'')+' · '+ocEsc(c.created_at||'')+'</div>';
                if(!res.notified){ body.innerHTML=head+'<div style="color:#aaa;">此筆未發送通知</div>'; return; }
                var pct = res.tgt_cnt? Math.round(res.read_cnt/res.tgt_cnt*100) : 0;
                var prog='<div style="margin-bottom:8px;font-weight:700;">簽收進度：<span style="color:'+(res.read_cnt>=res.tgt_cnt?'#1e7e34':'#e67e22')+';">'+res.read_cnt+' / '+res.tgt_cnt+'（'+pct+'%）</span></div>';
                var rows=(res.list||[]).map(function(p){
                    return '<div style="display:flex;justify-content:space-between;padding:3px 6px;border-bottom:1px dashed #eee;font-size:12px;">'
                      +'<span>'+(p.read?'<i class="fa fa-check-circle" style="color:#1e7e34;"></i> ':'<i class="fa fa-circle-o" style="color:#ccc;"></i> ')+ocEsc(p.name)+'</span>'
                      +'<span style="color:'+(p.read?'#1e7e34':'#e67e22')+';">'+(p.read?('已閱 '+ocEsc(p.read_at||'')):'未閱')+'</span></div>';
                }).join('');
                body.innerHTML=head+prog+rows;
            });
        };

        // ── 修改/刪除變更（政策B：欄位變更為稽核紀錄僅可改備註；刪除=作廢+連動移除通知）──
        function ocRefreshChangeLists(){
            if($('#changeHistoryModal').hasClass('in')) loadChangeHistory();
            var oid=document.getElementById('oc-order-id');
            if(oid && oid.value && $('#orderChangeModal').hasClass('in')) ocLoadOrderHistory(oid.value);
            if(typeof window.ocDecorateOrderBadges==='function') window.ocDecorateOrderBadges();
        }
        window.ocEditChange = function(changeId){
            var r=ocRowCache[changeId]||{};
            var nv=prompt('修改變更備註（欄位變更內容為稽核紀錄，不可修改）：', r.note||'');
            if(nv===null) return;
            nv=nv.trim();
            ocApi('update_change', {change_id:changeId, note:nv}).then(function(res){
                if(!res.success){ ocToast(res.message||'修改失敗'); return; }
                if(ocRowCache[changeId]) ocRowCache[changeId].note=nv;
                ocToast('備註已更新'+(res.synced?'，通知內容已同步':''));
                ocRefreshChangeLists();
            }).catch(function(){ ocToast('連線錯誤'); });
        };
        var octChangeId = null;
        window.ocEditTargets = function(changeId){
            octChangeId = changeId;
            var r=ocRowCache[changeId]||{};
            document.getElementById('oct-sub').textContent = r.change_no? ('（'+r.change_no+'）') : '';
            document.getElementById('oct-msg').textContent = '';
            var box=document.getElementById('oct-targets');
            box.innerHTML='<span style="color:#aaa;font-size:12px;">載入中…</span>';
            $('#ocTargetsModal').modal('show');
            ocApi('get_change_targets', {change_id:changeId}).then(function(res){
                if(!res.success){ box.innerHTML='<span style="color:#c0392b;font-size:12px;">'+ocEsc(res.message||'讀取失敗')+'</span>'; return; }
                var cur=res.current||[];
                var curSet=new Set(cur);
                var curUserSet=new Set(cur.filter(function(c){ return c.indexOf('user-')===0; }).map(function(c){ return c.substring(5); }));
                var hasDept=(res.allow_all || (res.depts&&res.depts.length));
                var hasUser=(res.user_rows&&res.user_rows.length);
                if(!hasDept && !hasUser){ box.innerHTML='<span style="color:#aaa;font-size:12px;">尚未設定可選通知對象（請管理者至「設定」設定）</span>'; return; }
                var h='';
                var dh='';
                if(res.allow_all) dh+=ocChk('oct-tg','all','全體（所有人）', curSet.has('all'));
                (res.depts||[]).forEach(function(d){ dh+=ocChk('oct-tg','dept-'+d.id, d.name, curSet.has('dept-'+d.id)); });
                h+='<div class="oc-dept-group"><div class="oc-dept-hd" style="background:#e3f2fd;color:#1565c0;">部門</div><div class="oc-chk-grid">'+(dh||'<span style="color:#aaa;font-size:12px;">（無）</span>')+'</div></div>';
                h+='<div class="oc-dept-hd" style="background:#e8f5e9;color:#2e7d32;margin-top:4px;">人員</div>';
                h+='<div style="margin-top:5px;">'+ocGroupUsersHtml(res.user_rows||[], 'oct-tg', 'user-', curUserSet)+'</div>';
                box.innerHTML=h;
                ocSyncSameValue(box, 'oct-tg');
                // 全體 與 其他互斥
                var allCb=box.querySelector('.oct-tg[value="all"]');
                if(allCb){ allCb.addEventListener('change',function(){ if(allCb.checked) box.querySelectorAll('.oct-tg').forEach(function(c){ if(c!==allCb) c.checked=false; }); });
                    box.querySelectorAll('.oct-tg').forEach(function(c){ if(c!==allCb) c.addEventListener('change',function(){ if(c.checked) allCb.checked=false; }); }); }
            });
        };
        window.ocSaveTargets = function(){
            if(!octChangeId) return;
            var targets=[]; document.querySelectorAll('#oct-targets .oct-tg:checked').forEach(function(c){ targets.push(c.value); });
            if(!targets.length && !confirm('未勾選任何對象，將移除此變更單的通知（含已閱統計）。確定？')) return;
            var btn=document.getElementById('oct-save-btn'); btn.disabled=true;
            var msg=document.getElementById('oct-msg'); msg.style.color='#888'; msg.textContent='儲存中…';
            ocApi('update_change_targets', {change_id:octChangeId, targets:targets}).then(function(res){
                btn.disabled=false;
                if(!res.success){ msg.style.color='#c0392b'; msg.textContent=res.message||'儲存失敗'; return; }
                $('#ocTargetsModal').modal('hide');
                ocToast(res.notified? '通知對象已更新' : '已移除此變更的通知');
                ocRefreshChangeLists();
            }).catch(function(){ btn.disabled=false; msg.style.color='#c0392b'; msg.textContent='連線錯誤'; });
        };
        window.ocVoidChange = function(changeId){
            var r=ocRowCache[changeId]||{};
            if(!confirm('確定要刪除變更單 '+(r.change_no||('#'+changeId))+' ？\n紀錄將標記為「作廢」保留稽核，關聯通知會一併移除。')) return;
            var reason=prompt('請輸入作廢原因（可留空）：','');
            if(reason===null) return;
            ocApi('delete_change', {change_id:changeId, reason:reason.trim()}).then(function(res){
                if(!res.success){ ocToast(res.message||'刪除失敗'); return; }
                ocToast('變更單已作廢'+(res.event_removed?'，通知已移除':''));
                ocRefreshChangeLists();
            }).catch(function(){ ocToast('連線錯誤'); });
        };

        // ── 訂單列表變更徽章：列表載入後批次查詢，填入每列單號欄 ─────────────
        window.ocDecorateOrderBadges = function(){
            var slots=document.querySelectorAll('#orderTable tbody .oc-chgbadge-slot');
            if(!slots.length) return;
            var ids=[]; slots.forEach(function(s){ var v=s.getAttribute('data-oid'); if(v) ids.push(v); });
            if(!ids.length) return;
            ocApi('orders_change_badge', {order_ids: ids}).then(function(res){
                if(!res.success||!res.data) return;
                slots.forEach(function(s){
                    var info=res.data[s.getAttribute('data-oid')];
                    if(!info){ s.innerHTML=''; return; }
                    var done=(info.tgt_cnt>0 && info.read_cnt>=info.tgt_cnt);
                    var color=done?'#1e7e34':'#e67e22';
                    var readTxt=info.notified?(' · 已閱 '+info.read_cnt+'/'+info.tgt_cnt):'';
                    s.innerHTML='<a href="javascript:;" onclick="ocShowReadStatus('+info.change_id+')" '
                        +'title="'+ocEsc('最新變更單 '+(info.change_no||'')+'，共 '+info.cnt+' 次變更，點擊查看簽收(已閱)狀態')+'" '
                        +'style="color:'+color+';font-weight:600;text-decoration:none;white-space:nowrap;">'
                        +'<i class="fa fa-exchange"></i> 變更×'+info.cnt+readTxt+'</a>';
                });
            }).catch(function(){});
        };

        // ── 設定 ───────────────────────────────────────────────────────────
        var ocsUserRows=[], ocsUserSel=new Set(), ocsWired=false;
        window.openChangeSettings = function(){
            $('#changeSettingsModal').modal('show');
            document.getElementById('ocs-msg').textContent='';
            orderAttachLoadPathSetting();
            orderAttachLoadCatsSetting();
            ocApi('get_settings', {}).then(function(res){
                if(!res.success){ ocToast(res.message||'讀取設定失敗'); return; }
                document.getElementById('ocs-path').value = res.attach_dir||'';
                // 表頭/表尾改由 AS 文件綁定推導（唯讀反灰），綁定用共用選擇器 EGAsDoc（可打編號篩選）
                ocsAsDocs      = res.as_docs || [];
                ocsAsDocId     = res.as_doc      ? parseInt(res.as_doc.id, 10) : 0;
                ocsAsDocHistId = res.as_doc_hist ? parseInt(res.as_doc_hist.id, 10) : 0;
                ocsShowAsDoc(res.as_doc); ocsShowAsDocHist(res.as_doc_hist);
                document.getElementById('ocs-allow-all').checked = !!(res.config&&res.config.allow_all);
                var selD=((res.config&&res.config.depts)||[]).map(String);
                var selU=((res.config&&res.config.users)||[]).map(String);
                document.getElementById('ocs-depts').innerHTML = (res.depts||[]).map(function(d){
                    return ocChk('ocs-d', d.id, d.name, selD.indexOf(String(d.id))>=0);
                }).join('') || '<span style="color:#aaa;font-size:12px;">無部門</span>';
                ocsUserRows = res.user_rows||[];
                ocsUserSel = new Set(selU);
                ocRenderSettingUsers();
                if(!ocsWired){
                    ocsWired=true;
                    var ub=document.getElementById('ocs-users');
                    ub.addEventListener('change', function(ev){ var t=ev.target; if(!t.classList||!t.classList.contains('ocs-u')) return;
                        if(t.checked) ocsUserSel.add(String(t.value)); else ocsUserSel.delete(String(t.value));
                        ub.querySelectorAll('.ocs-u[value="'+t.value+'"]').forEach(function(c){ if(c!==t) c.checked=t.checked; });
                    });
                }
            });
        };
        function ocRenderSettingUsers(){
            var kw=(document.getElementById('ocs-user-search').value||'').trim();
            var rows = kw? ocsUserRows.filter(function(u){ return (u.user_cname||'').indexOf(kw)>=0; }) : ocsUserRows;
            document.getElementById('ocs-users').innerHTML = ocGroupUsersHtml(rows, 'ocs-u', '', ocsUserSel) ;
        }
        window.ocFilterSettingUsers = ocRenderSettingUsers;
        window.saveChangeSettings = function(){
            var depts=[]; document.querySelectorAll('#ocs-depts .ocs-d:checked').forEach(function(c){ depts.push(c.value); });
            var users=Array.from(ocsUserSel);
            var allow= document.getElementById('ocs-allow-all').checked? '1':'0';
            var path=document.getElementById('ocs-path').value.trim();
            var msg=document.getElementById('ocs-msg'); msg.style.color='#888'; msg.textContent='儲存中…';
            ocApi('save_settings', {allow_all:allow, depts:depts, users:users, attach_dir:path,
                                    as_doc_id:ocsAsDocId, as_doc_hist_id:ocsAsDocHistId}).then(function(res){
                if(!res.success){ msg.style.color='#c0392b'; msg.textContent=res.message||'儲存失敗'; return; }
                msg.style.color='#1e7e34'; msg.textContent='已儲存';
                setTimeout(function(){ $('#changeSettingsModal').modal('hide'); }, 700);
            });
        };

        // ── 全部變更歷史 ───────────────────────────────────────────────────
        window.och_state = {page:1, size:10, kw:'', total:0, rows:[]};
        window.openChangeHistory = function(kw){
            och_state.page=1; och_state.size=parseInt(document.getElementById('och-size').value)||10;
            document.getElementById('och-kw').value=(typeof kw==='string')?kw:'';
            $('#changeHistoryModal').modal('show');
            loadChangeHistory();
        };
        window.loadChangeHistory = function(){
            och_state.kw=document.getElementById('och-kw').value.trim();
            var tb=document.getElementById('och-tbody');
            tb.innerHTML='<tr><td colspan="13" class="text-center" style="padding:16px;color:#aaa;">載入中…</td></tr>';
            ocApi('history_all', {page:och_state.page, size:och_state.size, kw:och_state.kw}).then(function(res){
                if(!res.success){ tb.innerHTML='<tr><td colspan="13" class="text-center" style="color:#c0392b;">'+ocEsc(res.message||'讀取失敗')+'</td></tr>'; return; }
                och_state.total=res.total; och_state.rows=res.data; ocCache(res.data);
                och_state.print_header=res.print_header||''; och_state.print_footer=res.print_footer||'';
                och_state.print_header_one=res.print_header_one||''; och_state.print_footer_one=res.print_footer_one||'';
                och_state.company=res.company||'';
                if(!res.data.length){ tb.innerHTML='<tr><td colspan="13" class="text-center" style="padding:16px;color:#aaa;">無資料</td></tr>'; }
                else tb.innerHTML = res.data.map(function(r){
                    var diffs=[]; try{ diffs=JSON.parse(r.changes_json||'[]'); }catch(e){}
                    var summ=diffs.map(function(d){ return d.label+':'+(d.old||'空')+'→'+(d.new||'空'); }).join('；')||'（僅備註）';
                    var nf=r.notify||{all:false,depts:[],users:[]};
                    var ntags=[];
                    if(nf.all) ntags.push('<span class="oc-diff-tag" style="background:#fff3e0;border-color:#ffcc80;color:#e65100;">全體</span>');
                    (nf.depts||[]).forEach(function(n){ ntags.push('<span class="oc-diff-tag" style="background:#e3f2fd;border-color:#90caf9;color:#1565c0;">'+ocEsc(n)+'</span>'); });
                    (nf.users||[]).forEach(function(u){
                        ntags.push('<span class="oc-diff-tag" style="background:'+(u.read?'#e8f5e9':'#f5f5f5')+';border-color:'+(u.read?'#a5d6a7':'#ddd')+';color:'+(u.read?'#2e7d32':'#888')+';" title="'+(u.read?('已閱 '+ocEsc(u.read_at||'')):'未閱')+'">'+(u.read?'<i class="fa fa-check"></i> ':'')+ocEsc(u.name)+'</span>');
                    });
                    var readCell = (r.tgt_cnt>0)
                        ? '<a href="javascript:;" onclick="ocShowReadStatus('+r.id+')" title="點擊查看每人簽收(已閱)狀態" style="font-weight:600;color:'+(r.read_cnt>=r.tgt_cnt?'#1e7e34':'#e67e22')+';">'+r.read_cnt+'/'+r.tgt_cnt+'</a>'
                        : '<span style="color:#bbb;">未通知</span>';
                    var isVoid = String(r.is_void)==='1';
                    var voidTag = isVoid? '<span class="oc-diff-tag" style="background:#fdecea;border-color:#f5b7b1;color:#c0392b;font-weight:700;" title="'+ocEsc((r.voided_at||'')+' '+(r.voided_by||'')+(r.void_reason?('：'+r.void_reason):''))+'">作廢</span> ' : '';
                    var opCell='<a href="javascript:;" onclick="ocPrintOneChange('+r.id+')" title="單獨列印這筆訂單變更單" style="margin-right:8px;color:#5d4037;"><i class="fa fa-print"></i></a>';
                    if(isVoid){ opCell+='<span style="color:#c0392b;font-size:11px;" title="'+ocEsc((r.voided_at||'')+' '+(r.voided_by||'')+(r.void_reason?('：'+r.void_reason):''))+'">已作廢</span>'; }
                    else {
                        if(window.canUpdate) opCell+='<a href="javascript:;" onclick="ocEditChange('+r.id+')" title="修改備註（欄位變更為稽核紀錄不可改）" style="margin-right:8px;"><i class="fa fa-pencil"></i></a>';
                        if(window.canUpdate) opCell+='<a href="javascript:;" onclick="ocEditTargets('+r.id+')" title="修改通知對象（可新增/刪除）" style="margin-right:8px;color:#e67e22;"><i class="fa fa-bell-o"></i></a>';
                        if(window.canDelete) opCell+='<a href="javascript:;" onclick="ocVoidChange('+r.id+')" title="刪除（作廢，連動移除通知）" style="color:#c0392b;"><i class="fa fa-trash"></i></a>';
                    }
                    return '<tr'+(isVoid?' style="opacity:.55;"':'')+'><td style="white-space:nowrap;">'+ocEsc(r.created_at)+'</td>'
                        + '<td style="white-space:nowrap;font-family:Consolas,monospace;color:#5d4037;font-weight:600;">'+voidTag+ocEsc(r.change_no||'—')+'</td>'
                        + '<td>'+ocEsc(r.order_no)+'</td><td>'+ocEsc(r.client_name)+'</td><td>'+ocEsc(r.d_id)+'</td>'
                        + '<td style="max-width:240px;'+(isVoid?'text-decoration:line-through;':'')+'">'+ocEsc(summ)+'</td><td>'+ocEsc(r.note||'')+'</td><td>'+ocEsc(r.created_by)+'</td>'
                        + '<td style="max-width:200px;">'+(ntags.length?ntags.join(' '):'<span style="color:#bbb;">—</span>')+'</td>'
                        + '<td style="text-align:center;white-space:nowrap;">'+readCell+'</td>'
                        + '<td style="text-align:center;">'+(parseInt(r.att_count)>0?('<i class="fa fa-paperclip"></i> '+r.att_count):'-')+'</td>'
                        + '<td style="text-align:center;"><a href="javascript:;" onclick="ocOpenDetail('+r.id+')">明細</a></td>'
                        + '<td style="text-align:center;white-space:nowrap;">'+opCell+'</td></tr>';
                }).join('');
                var pages=Math.max(1, Math.ceil(res.total/och_state.size));
                document.getElementById('och-info').textContent='共 '+res.total+' 筆，第 '+och_state.page+'/'+pages+' 頁';
                var pg=document.getElementById('och-pager'); var h='';
                h+='<button class="btn btn-xs btn-default" '+(och_state.page<=1?'disabled':'')+' onclick="och_state.page--;loadChangeHistory();"><i class="fa fa-chevron-left"></i></button>';
                h+='<button class="btn btn-xs btn-default" '+(och_state.page>=pages?'disabled':'')+' onclick="och_state.page++;loadChangeHistory();"><i class="fa fa-chevron-right"></i></button>';
                pg.innerHTML=h;
            });
        };
        window.exportChangeHistoryCSV = function(){
            ocApi('history_all', {page:1, size:50, kw:document.getElementById('och-kw').value.trim()}).then(function(res){
                if(!res.success) return;
                // 取全部：迴圈分頁
                var total=res.total, size=50, pages=Math.ceil(total/size), all=res.data.slice(), chain=Promise.resolve();
                for(var p=2;p<=pages;p++){ (function(pp){ chain=chain.then(function(){ return ocApi('history_all',{page:pp,size:size,kw:document.getElementById('och-kw').value.trim()}).then(function(r){ if(r.success) all=all.concat(r.data); }); }); })(p); }
                chain.then(function(){
                    var head=['時間','變更單號','狀態','單號','客戶','料號','變更內容','備註','變更人','通知對象','已閱','附件數'];
                    var lines=[head.join(',')];
                    all.forEach(function(r){ var diffs=[]; try{diffs=JSON.parse(r.changes_json||'[]');}catch(e){}
                        var summ=diffs.map(function(d){return d.label+':'+(d.old||'空')+'→'+(d.new||'空');}).join('；');
                        var nf=r.notify||{all:false,depts:[],users:[]};
                        var ntxt=[]; if(nf.all) ntxt.push('全體');
                        (nf.depts||[]).forEach(function(n){ ntxt.push('部門:'+n); });
                        (nf.users||[]).forEach(function(u){ ntxt.push(u.name+(u.read?'(已閱)':'(未閱)')); });
                        var readTxt=(r.tgt_cnt>0)?(r.read_cnt+'/'+r.tgt_cnt):'未通知';
                        var stTxt=(String(r.is_void)==='1')?('作廢'+(r.voided_at?('('+r.voided_at+' '+(r.voided_by||'')+(r.void_reason?('：'+r.void_reason):'')+')'):'')):'有效';
                        var row=[r.created_at,(r.change_no||''),stTxt,r.order_no,r.client_name,r.d_id,summ,(r.note||''),r.created_by,ntxt.join('、'),readTxt,r.att_count];
                        lines.push(row.map(function(c){ c=String(c==null?'':c).replace(/"/g,'""'); return /[",\n]/.test(c)?'"'+c+'"':c; }).join(','));
                    });
                    var blob=new Blob(['﻿'+lines.join('\n')], {type:'text/csv;charset=utf-8;'});
                    var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='訂單變更歷史.csv'; a.click();
                });
            });
        };
        // ── AS 文件編號綁定（列印表頭＝doc_name、頁尾右下＝doc_no；選擇器一律走共用檔 eg_asdoc_picker.js）──
        window.ocsShowAsDoc = function(doc){
            document.getElementById('ocs-print-header').value = doc ? (doc.doc_name||'') : '（尚未綁定 AS 文件）';
            document.getElementById('ocs-print-footer').value = doc ? EGAsDoc.label(doc) : '尚未綁定';
        };
        window.ocsPickAsDoc = function(){
            EGAsDoc.open({docs: ocsAsDocs, current: ocsAsDocId, title:'訂單變更單 — AS 文件編號綁定',
                onSave: function(id, doc){ ocsAsDocId = id; ocsShowAsDoc(doc); }});
        };
        window.ocsShowAsDocHist = function(doc){
            document.getElementById('ocs-hist-header').value = doc ? (doc.doc_name||'') : '（尚未綁定，預設印「訂單變更歷史」）';
            document.getElementById('ocs-hist-footer').value = doc ? EGAsDoc.label(doc) : '尚未綁定';
        };
        window.ocsPickAsDocHist = function(){
            EGAsDoc.open({docs: ocsAsDocs, current: ocsAsDocHistId, title:'訂單變更歷史清單 — AS 文件編號綁定',
                onSave: function(id, doc){ ocsAsDocHistId = id; ocsShowAsDocHist(doc); }});
        };
        // 列印：大標題＝本公司全名／表頭＝綁定 AS 文件的表單名稱／頁尾右下＝doc_no、左下＝頁碼（ai-rules/16）
        window.exportChangeHistoryPDF = function(){
            var tbl=document.querySelector('#changeHistoryModal table').outerHTML;
            var comp=(och_state.company||'').trim();
            var hdr=(och_state.print_header||'').trim() || '訂單變更歷史';
            var asTxt=(och_state.print_footer||'').trim().replace(/['\\]/g,'');
            var w=window.open('','_blank');
            w.document.write('<html><head><meta charset="utf-8"><title>訂單變更歷史</title>'
                +'<style>body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;}'
                +'table{width:100%;border-collapse:collapse;font-size:12px;}'
                +'table thead{display:table-header-group;}'
                +'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;}th{background:#f0f0f0;}'
                +'.p-comp{text-align:center;font-size:22px;font-weight:bold;margin-bottom:1px;}'
                +'.p-title{text-align:center;font-size:17px;font-weight:bold;letter-spacing:6px;margin-bottom:10px;}'
                +'@page{size:A4 landscape;margin:12mm 10mm 18mm;'   // 歷史清單欄位多，預設橫式
                + (asTxt ? " @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
                +'}'
                +'</style></head><body>'
                + (comp? '<div class="p-comp">'+ocEsc(comp)+'</div>':'')
                + '<div class="p-title">'+ocEsc(hdr)+'</div>' + tbl
                +'<scr'+'ipt>window.onload=function(){'          // 超過一頁才加頁碼（counter(pages) 由列印引擎算）
                +'var onePageA4=(210-30)*96/25.4;'               // 橫式：可用高度＝A4 短邊 210mm 扣上下邊界
                +'if(document.body.scrollHeight>onePageA4*0.92){'
                +'var st=document.createElement(\'style\');'
                +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
                +'document.head.appendChild(st);}'
                +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
            w.document.close(); w.focus();
        };

        // 單筆「訂單變更單」列印：大標題＝本公司全名／表頭＝綁定 AS 文件的表單名稱／頁尾右下＝doc_no、左下＝頁碼（ai-rules/16）
        // 與 exportChangeHistoryPDF（歷史清單，橫式表格）分開綁定；直式單頁文件版面。
        window.ocPrintOneChange = function(changeId){
            var r = ocRowCache[changeId];
            if (!r) { ocToast('找不到這筆變更資料，請重新整理列表後再試'); return; }
            var diffs=[]; try{ diffs=JSON.parse(r.changes_json||'[]'); }catch(e){}
            var diffRows = diffs.length
                ? diffs.map(function(d){ return '<tr><td>'+ocEsc(d.label)+'</td><td style="color:#999;">'+ocEsc(d.old||'空')+'</td><td style="color:#1e7e34;font-weight:600;">'+ocEsc(d.new||'空')+'</td></tr>'; }).join('')
                : '<tr><td colspan="3" style="text-align:center;color:#aaa;">（本筆無欄位變更，僅備註）</td></tr>';
            var voidBar = (String(r.is_void)==='1')
                ? '<div style="border:1px solid #c0392b;color:#c0392b;font-weight:700;padding:6px 9px;margin-bottom:10px;">已作廢'+(r.void_reason?('：'+ocEsc(r.void_reason)):'')+'</div>'
                : '';
            var comp=(och_state.company||'').trim();
            var hdr=(och_state.print_header_one||'').trim() || '訂單變更單';
            var asTxt=(och_state.print_footer_one||'').trim().replace(/['\\]/g,'');
            var w=window.open('','_blank');
            w.document.write('<html><head><meta charset="utf-8"><title>訂單變更單 '+ocEsc(r.change_no||'')+'</title>'
                +'<style>body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 10mm;color:#222;}'
                +'table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px;}'
                +'th,td{border:1px solid #ccc;padding:5px 8px;text-align:left;}th{background:#f0f0f0;}'
                +'.p-comp{text-align:center;font-size:22px;font-weight:bold;margin-bottom:1px;}'
                +'.p-title{text-align:center;font-size:17px;font-weight:bold;letter-spacing:6px;margin-bottom:14px;}'
                +'.p-meta{font-size:13px;color:#333;line-height:1.9;border-bottom:1px solid #ccc;padding-bottom:8px;margin-bottom:6px;}'
                +'.p-meta b{display:inline-block;width:80px;color:#555;}'
                +'.p-note{margin-top:10px;font-size:12px;background:#fff8f0;border:1px solid #f0c891;padding:6px 9px;}'
                +'@page{size:A4 portrait;margin:15mm 12mm 18mm;'
                + (asTxt ? " @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
                +'}'
                +'</style></head><body>'
                + (comp? '<div class="p-comp">'+ocEsc(comp)+'</div>':'')
                + '<div class="p-title">'+ocEsc(hdr)+'</div>'
                + voidBar
                + '<div class="p-meta">'
                + '<div><b>變更單號</b>'+ocEsc(r.change_no||'—')+'</div>'
                + '<div><b>訂單編號</b>'+ocEsc(r.order_no||'')+'</div>'
                + '<div><b>客戶</b>'+ocEsc(r.client_name||'')+'</div>'
                + '<div><b>料號</b>'+ocEsc(r.d_id||'')+'</div>'
                + '<div><b>變更人</b>'+ocEsc(r.created_by||'')+'</div>'
                + '<div><b>時間</b>'+ocEsc(r.created_at||'')+'</div>'
                + '</div>'
                + '<table><thead><tr><th>欄位</th><th>原值</th><th>新值</th></tr></thead><tbody>'+diffRows+'</tbody></table>'
                + (r.note? ('<div class="p-note">備註：'+ocEsc(r.note)+'</div>') : '')
                +'<scr'+'ipt>window.onload=function(){'          // 超過一頁才加頁碼（counter(pages) 由列印引擎算）
                +'var onePageA4=(297-33)*96/25.4;'               // 直式：可用高度＝A4 長邊 297mm 扣上下邊界
                +'if(document.body.scrollHeight>onePageA4*0.92){'
                +'var st=document.createElement(\'style\');'
                +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
                +'document.head.appendChild(st);}'
                +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
            w.document.close(); w.focus();
        };
    })();
    </script>

<?php
// ── 齒輪／花鍵計算工具 ──────────────────────────────────────────────────────
// 2026-08-25：原本 CSS＋視窗 HTML＋JS＋gear_*/spline_* API 都內嵌在本頁，已抽成共用檔，
// 讓批圖編輯器（image_editor.php）等頁面 include 同一份（禁止再複製一套＝鐵律4）。
//   畫面：views/Sales/_gear_tool_ui.php｜API：views/Sales/gear_tool_api.php｜邏輯：src/common/gear_tool_lib.php
// 顯示與否沿用本頁上方已算好的 $show_gear_tool／$is_gear_admin。
include __DIR__ . '/_gear_tool_ui.php';
?>

<!-- ═══ 鍵槽計算工具視窗 ═══════════════════════════════════════════════════════ -->
<template id="kw-tool-tpl"><div id="kw-tool-window">
    <div id="kw-tool-hdr">
        <span class="kw-hdr-title"><i class="fa fa-key"></i> 鍵槽計算</span>
        <div class="kw-hdr-btns">
            <button onclick="clearKwTool()">清除</button>
            <button onclick="closeKwTool()">✕ 關閉</button>
        </div>
    </div>
    <div style="display:flex;border-bottom:2px solid #bdc3c7;background:#f5f5f5;padding:0 6px;">
        <button id="kw-tab-shaft" onclick="switchKwTab('shaft')" style="padding:6px 14px;border:none;border-bottom:2px solid #27ae60;background:transparent;color:#27ae60;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:-2px;">軸件</button>
        <button id="kw-tab-plate" onclick="switchKwTab('plate')" style="padding:6px 14px;border:none;border-bottom:2px solid transparent;background:transparent;color:#777;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:-2px;">片狀</button>
    </div>
    <div id="kw-tool-body">
        <div id="kw-pane-shaft">
        <div style="font-size:10.5px;color:#555;margin-bottom:6px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;padding:4px 9px;">
            灰底：填寫；藍底：自動計算。<strong>右上必填</strong>；右下與左上擇一填寫；左下自動計算。
        </div>
        <!-- 3-column: left blocks | CSS diagram | right blocks -->
        <div style="display:flex;gap:8px;align-items:flex-start;">

            <!-- ════ 左欄 ════ -->
            <div style="width:210px;flex-shrink:0;">
                <div class="kw-card">
                    <div class="kw-ch kw-ch-lt">成品尺寸 <span class="kw-mutex-tag">（與右下擇一）</span></div>
                    <div class="kw-dr">
                        <input id="kw-lt-nom" type="number" class="kw-ni" oninput="calcKw()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kw-lt-utol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-lt-ulim">—</span></div>
                            <div class="kw-tr"><input id="kw-lt-ltol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-lt-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-ch kw-ch-mach">加工 / 檢驗尺寸</div>
                    <div class="kw-dr">
                        <span class="kw-no" id="kw-lt-mnom">—</span>
                        <div class="kw-tc">
                            <div class="kw-tr"><span class="kw-to">0</span><span class="kw-lv" id="kw-lt-mulim">—</span><span id="kw-lt-mulim-chk" style="font-size:9px;color:#c0392b;margin-left:3px;font-family:Consolas,monospace;"></span></div>
                            <div class="kw-tr"><span class="kw-to" id="kw-lt-mltol">—</span><span class="kw-lv" id="kw-lt-mllim">—</span><span id="kw-lt-mllim-chk" style="font-size:9px;color:#c0392b;margin-left:3px;font-family:Consolas,monospace;"></span></div>
                        </div>
                    </div>
                </div>
                <div class="kw-card">
                    <div class="kw-ch kw-ch-lb">成品尺寸（左下）</div>
                    <div class="kw-note">原圖有標示則依原圖檢驗</div>
                    <div class="kw-ch kw-ch-mach">加工 / 檢驗尺寸</div>
                    <div class="kw-dr">
                        <span class="kw-no" id="kw-lb-mnom">—</span>
                        <div class="kw-tc">
                            <div class="kw-tr"><span class="kw-to">0</span><span class="kw-lv" id="kw-lb-mulim">—</span></div>
                            <div class="kw-tr"><span class="kw-to" id="kw-lb-mltol">—</span><span class="kw-lv" id="kw-lb-mllim">—</span></div>
                        </div>
                    </div>
                </div>
            </div><!-- /left col -->

            <!-- ════ 中間：CSS 示意圖 (縮小版, pointer-events:none 讓標注線可跨欄) ════ -->
            <div style="position:relative;width:245px;height:155px;flex-shrink:0;overflow:visible;z-index:5;pointer-events:none;">
                <!-- Centerlines (dash-dot) -->
                <div style="position:absolute;top:59px;left:47px;width:148px;height:1px;background:repeating-linear-gradient(to right,#555 0,#555 8px,transparent 8px,transparent 10px,#555 10px,#555 12px,transparent 12px,transparent 14px);"></div>
                <div style="position:absolute;top:12px;left:120px;width:1px;height:102px;background:repeating-linear-gradient(to bottom,#555 0,#555 8px,transparent 8px,transparent 10px,#555 10px,#555 12px,transparent 12px,transparent 14px);"></div>
                <!-- Main circle -->
                <div style="position:absolute;top:20px;left:80px;width:80px;height:80px;border:2px solid black;border-radius:50%;box-sizing:border-box;z-index:1;"></div>
                <!-- Dashed arc (keyway opening) -->
                <div style="position:absolute;top:20px;left:80px;width:80px;height:80px;border:2px dashed black;border-radius:50%;box-sizing:border-box;z-index:0;clip-path:inset(30% 80% 30% 0);"></div>
                <!-- Keyway cutout -->
                <div style="position:absolute;top:48px;left:78px;width:24px;height:24px;background:white;border-top:2px solid black;border-right:2px solid black;border-bottom:2px solid black;box-sizing:border-box;z-index:2;"></div>
                <!-- Center mark -->
                <div style="position:absolute;top:59px;left:116px;width:8px;height:2px;background:#333;z-index:3;"></div>
                <div style="position:absolute;top:55px;left:120px;width:2px;height:9px;background:#333;z-index:3;"></div>
                <!-- RED: OD diagonal double arrow -->
                <div style="position:absolute;top:59px;left:80px;width:80px;height:2px;background:#c0392b;transform:rotate(45deg);transform-origin:50% 50%;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #c0392b;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #c0392b;right:-1px;top:-3px;"></div>
                </div>
                <!-- Red extension RIGHT → 右上（延伸進右欄 50px） -->
                <div style="position:absolute;top:89px;left:149px;width:146px;height:2px;background:#c0392b;z-index:3;"></div>
                <!-- Vertical guide lines -->
                <div style="position:absolute;top:72px;left:82px;width:2px;height:16px;background:#5b2c6f;z-index:2;"></div>
                <div style="position:absolute;top:72px;left:102px;width:2px;height:191px;background:#5b8bb8;z-index:2;"></div>
                <div style="position:absolute;top:113px;left:121px;width:2px;height:13px;z-index:2;background:repeating-linear-gradient(to bottom,#1a5276 0,#1a5276 3px,transparent 3px,transparent 5px);"></div>
                <div style="position:absolute;top:72px;left:161px;width:2px;height:191px;background:#e67e22;z-index:2;"></div>
                <!-- PURPLE (左上): 延伸進左欄 50px + double arrow -->
                <div style="position:absolute;top:86px;left:-50px;width:132px;height:2px;background:#5b2c6f;z-index:3;"></div>
                <div style="position:absolute;top:86px;left:82px;width:21px;height:2px;background:#5b2c6f;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #5b2c6f;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #5b2c6f;right:-1px;top:-3px;"></div>
                </div>
                <!-- BLUE (左下): L-shape 從量測點(y=119)下折到左下卡片位置(y=162)，延伸進左欄 -->
                <div style="position:absolute;top:119px;left:-50px;width:134px;height:43px;border-bottom:2px solid #1a5276;border-right:2px solid #1a5276;box-sizing:border-box;z-index:3;"></div>
                <div style="position:absolute;top:119px;left:84px;width:18px;height:2px;background:#1a5276;z-index:3;"></div>
                <div style="position:absolute;top:119px;left:102px;width:18px;height:2px;background:#1a5276;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #1a5276;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #1a5276;right:-1px;top:-3px;"></div>
                </div>
                <!-- ORANGE (右下): double arrow 對齊右下卡片位置(y≈263)，延伸進右欄 -->
                <div style="position:absolute;top:263px;left:102px;width:59px;height:2px;background:#e67e22;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #e67e22;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #e67e22;right:-1px;top:-3px;"></div>
                </div>
                <div style="position:absolute;top:263px;left:161px;width:134px;height:2px;background:#e67e22;z-index:3;"></div>
            </div><!-- /diagram -->

            <!-- ════ 右欄 ════ -->
            <div style="flex:1;min-width:0;">
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rt">成品尺寸（右上：圓柱外徑，必填）</div>
                    <div class="kw-dr">
                        <input id="kw-rt-nom" type="number" class="kw-ni" oninput="calcKw()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kw-rt-utol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rt-ulim">—</span></div>
                            <div class="kw-tr"><input id="kw-rt-ltol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rt-llim">—</span></div>
                        </div>
                    </div>
                </div>
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rr">實車尺寸（右上）</div>
                    <div class="kw-dr">
                        <input id="kw-rr-nom" type="number" class="kw-ni" oninput="calcKw()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kw-rr-utol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rr-ulim">—</span></div>
                            <div class="kw-tr"><input id="kw-rr-ltol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rr-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-diff">
                        <div class="kw-diff-r"><span class="kw-diff-lbl">差異（上）實車上限−成品下限：</span><span class="kw-diff-v" id="kw-dif-u">—</span></div>
                        <div class="kw-diff-r"><span class="kw-diff-lbl">差異（下）實車下限−成品上限：</span><span class="kw-diff-v" id="kw-dif-l">—</span></div>
                        <div class="kw-diff-r" style="margin-top:2px;padding-top:2px;border-top:1px solid #c5d8f5;"><span class="kw-diff-lbl">研磨量（直徑）上：</span><span class="kw-diff-v" id="kw-grind-u">—</span><span class="kw-diff-lbl" style="margin-left:8px;">下：</span><span class="kw-diff-v" id="kw-grind-l">—</span></div>
                        <div class="kw-diff-r"><span class="kw-diff-lbl">研磨量（單邊）上：</span><span class="kw-diff-v" style="color:#c0392b;" id="kw-grind1-u">—</span><span class="kw-diff-lbl" style="margin-left:8px;">下：</span><span class="kw-diff-v" style="color:#c0392b;" id="kw-grind1-l">—</span></div>
                    </div>
                </div>
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rb">成品尺寸（右下：實心端） <span class="kw-mutex-tag">（與左上擇一）</span></div>
                    <div class="kw-dr">
                        <input id="kw-rb-nom" type="number" class="kw-ni" oninput="calcKw()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kw-rb-utol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rb-ulim">—</span></div>
                            <div class="kw-tr"><input id="kw-rb-ltol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rb-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-ch kw-ch-mach">加工 / 檢驗尺寸</div>
                    <div class="kw-dr">
                        <span class="kw-no" id="kw-rb-mnom">—</span>
                        <div class="kw-tc">
                            <div class="kw-tr"><span class="kw-to">0</span><span class="kw-lv" id="kw-rb-mulim">—</span><span id="kw-rb-mulim-chk" style="font-size:9px;color:#c0392b;margin-left:3px;font-family:Consolas,monospace;"></span></div>
                            <div class="kw-tr"><span class="kw-to" id="kw-rb-mltol">—</span><span class="kw-lv" id="kw-rb-mllim">—</span><span id="kw-rb-mllim-chk" style="font-size:9px;color:#c0392b;margin-left:3px;font-family:Consolas,monospace;"></span></div>
                        </div>
                    </div>
                </div>
            </div><!-- /right col -->

        </div><!-- /3-col -->
        <div id="kw-msg-box"></div>
        </div><!-- /kw-pane-shaft -->

        <!-- ════ 片狀鍵槽計算 ════ -->
        <div id="kw-pane-plate" style="display:none;">
        <div style="font-size:10.5px;color:#555;margin-bottom:6px;background:#e8f0ff;border:1px solid #b3c6f7;border-radius:4px;padding:4px 9px;">
            灰底：填寫；藍底：自動計算。<strong>右上必填</strong>；右下與左上擇一填寫。片狀（內徑研磨）：成品 &gt; 實車。
        </div>
        <div style="display:flex;gap:8px;align-items:flex-start;">

            <!-- ════ 左欄：右下（實心端）════ -->
            <div style="width:210px;flex-shrink:0;">
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rb">成品尺寸（右下：實心端）</div>
                    <div class="kw-dr">
                        <input id="kwp-rb-nom" type="number" class="kw-ni" oninput="calcKwP()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kwp-rb-utol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rb-ulim">—</span></div>
                            <div class="kw-tr"><input id="kwp-rb-ltol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rb-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-ch kw-ch-mach">加工 / 檢驗尺寸</div>
                    <div class="kw-dr">
                        <span class="kw-no" id="kwp-rb-mnom">—</span>
                        <div class="kw-tc">
                            <div class="kw-tr"><span class="kw-to" id="kwp-rb-mutol">—</span><span class="kw-lv" id="kwp-rb-mulim">—</span></div>
                            <div class="kw-tr"><span class="kw-to">0</span><span class="kw-lv" id="kwp-rb-mllim">—</span></div>
                        </div>
                    </div>
                </div>
            </div><!-- /left col -->

            <!-- ════ 中間：示意圖（片狀孔內鍵槽，凸出去）════ -->
            <div style="position:relative;width:245px;height:155px;flex-shrink:0;overflow:visible;z-index:5;pointer-events:none;">
                <!-- 中心線 -->
                <div style="position:absolute;top:59px;left:47px;width:148px;height:1px;background:repeating-linear-gradient(to right,#555 0,#555 8px,transparent 8px,transparent 10px,#555 10px,#555 12px,transparent 12px,transparent 14px);"></div>
                <div style="position:absolute;top:12px;left:120px;width:1px;height:102px;background:repeating-linear-gradient(to bottom,#555 0,#555 8px,transparent 8px,transparent 10px,#555 10px,#555 12px,transparent 12px,transparent 14px);"></div>
                <!-- 孔（bore）圓形 — 淺灰底表示中空 -->
                <div style="position:absolute;top:20px;left:80px;width:80px;height:80px;border:2px solid black;border-radius:50%;box-sizing:border-box;z-index:1;background:#eee;"></div>
                <!-- 凸出的鍵槽（往左突出，代表在板材上切出的槽） -->
                <div style="position:absolute;top:48px;left:55px;width:25px;height:24px;background:white;border-top:2px solid black;border-left:2px solid black;border-bottom:2px solid black;box-sizing:border-box;z-index:2;"></div>
                <!-- 遮蓋孔圓弧在鍵槽開口處 -->
                <div style="position:absolute;top:50px;left:78px;width:5px;height:20px;background:#eee;z-index:3;"></div>
                <!-- 中心標記 -->
                <div style="position:absolute;top:59px;left:116px;width:8px;height:2px;background:#333;z-index:3;"></div>
                <div style="position:absolute;top:55px;left:120px;width:2px;height:9px;background:#333;z-index:3;"></div>
                <!-- RED：內徑對角雙箭頭（右上：圓柱內徑） -->
                <div style="position:absolute;top:59px;left:80px;width:80px;height:2px;background:#c0392b;transform:rotate(45deg);transform-origin:50% 50%;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #c0392b;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #c0392b;right:-1px;top:-3px;"></div>
                </div>
                <!-- RED 往右延伸 → 右欄右上 -->
                <div style="position:absolute;top:89px;left:149px;width:146px;height:2px;background:#c0392b;z-index:3;"></div>
                <!-- 橘色垂直刻度（鍵槽外壁位置標記） -->
                <div style="position:absolute;top:50px;left:57px;width:2px;height:18px;background:#e67e22;z-index:4;"></div>
                <!-- ORANGE 延伸線：往左進左欄 (x=-50 to x=55) -->
                <div style="position:absolute;top:59px;left:-50px;width:107px;height:2px;background:#e67e22;z-index:4;"></div>
                <!-- ORANGE 雙箭頭：鍵槽外壁 (x=55) → 東四分點 (x=160)，寬105px -->
                <div style="position:absolute;top:59px;left:55px;width:105px;height:2px;background:#e67e22;z-index:4;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #e67e22;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #e67e22;right:-1px;top:-3px;"></div>
                </div>
            </div><!-- /diagram -->

            <!-- ════ 右欄 ════ -->
            <div style="flex:1;min-width:0;">
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rt">成品尺寸（右上：圓柱內徑，必填）</div>
                    <div class="kw-dr">
                        <input id="kwp-rt-nom" type="number" class="kw-ni" oninput="calcKwP()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kwp-rt-utol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rt-ulim">—</span></div>
                            <div class="kw-tr"><input id="kwp-rt-ltol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rt-llim">—</span></div>
                        </div>
                    </div>
                </div>
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rr">實車尺寸（右上）</div>
                    <div class="kw-dr">
                        <input id="kwp-rr-nom" type="number" class="kw-ni" oninput="calcKwP()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kwp-rr-utol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rr-ulim">—</span></div>
                            <div class="kw-tr"><input id="kwp-rr-ltol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rr-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-diff">
                        <div class="kw-diff-r"><span class="kw-diff-lbl">差異（上）成品上限−實車下限：</span><span class="kw-diff-v" id="kwp-dif-u">—</span></div>
                        <div class="kw-diff-r"><span class="kw-diff-lbl">差異（下）成品下限−實車上限：</span><span class="kw-diff-v" id="kwp-dif-l">—</span></div>
                        <div class="kw-diff-r" style="margin-top:2px;padding-top:2px;border-top:1px solid #c5d8f5;"><span class="kw-diff-lbl">研磨量（直徑）上：</span><span class="kw-diff-v" id="kwp-grind-u">—</span><span class="kw-diff-lbl" style="margin-left:8px;">下：</span><span class="kw-diff-v" id="kwp-grind-l">—</span></div>
                        <div class="kw-diff-r"><span class="kw-diff-lbl">研磨量（單邊）上：</span><span class="kw-diff-v" style="color:#c0392b;" id="kwp-grind1-u">—</span><span class="kw-diff-lbl" style="margin-left:8px;">下：</span><span class="kw-diff-v" style="color:#c0392b;" id="kwp-grind1-l">—</span></div>
                    </div>
                </div>
            </div><!-- /right col -->
        </div>
        <div id="kwp-msg-box"></div>
        </div><!-- /kw-pane-plate -->
    </div><!-- /kw-tool-body -->
</div><!-- /kw-tool-window --></template>
<div id="kw-tool-container"></div>

<script>
(function(){
    'use strict';
    var _kwDomInited = false;

    function kwRD(v, n) { // ROUNDDOWN toward zero
        var f = Math.pow(10, n);
        return (v >= 0 ? Math.floor(v * f) : Math.ceil(v * f)) / f;
    }
    function kwRU(v, n) { // ROUNDUP away from zero
        var f = Math.pow(10, n);
        return (v >= 0 ? Math.ceil(v * f) : Math.floor(v * f)) / f;
    }
    function kwFmt(v) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        var s = v.toString();
        if (s.indexOf('.') !== -1) s = s.replace(/\.?0+$/, '');
        return s;
    }
    function kwFmtLim(v) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        return '(' + kwFmt(v) + ')';
    }
    function kwVal(id) {
        var el = document.getElementById(id);
        if (!el) return null;
        var v = parseFloat(el.value);
        return isNaN(v) ? null : v;
    }
    function kwSet(id, v) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = (v === null || v === undefined) ? '—' : String(v);
    }

    var _kwFieldOrder = [
        'kw-rt-nom','kw-rt-utol','kw-rt-ltol',
        'kw-rr-nom','kw-rr-utol','kw-rr-ltol',
        'kw-rb-nom','kw-rb-utol','kw-rb-ltol',
        'kw-lt-nom','kw-lt-utol','kw-lt-ltol'
    ];

    function initKwEnterNav() {
        _kwFieldOrder.forEach(function(id, i) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var next = document.getElementById(_kwFieldOrder[(i + 1) % _kwFieldOrder.length]);
                    if (next) next.focus();
                }
            });
        });
    }

    function initKwEnterNavP() {
        var order = ['kwp-rb-nom','kwp-rb-utol','kwp-rb-ltol',
                     'kwp-rt-nom','kwp-rt-utol','kwp-rt-ltol',
                     'kwp-rr-nom','kwp-rr-utol','kwp-rr-ltol'];
        order.forEach(function(id, i) {
            var el = document.getElementById(id); if (!el) return;
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); var next = document.getElementById(order[(i+1)%order.length]); if (next) next.focus(); }
            });
        });
    }

    function initKwSelectOnFocus() {
        var win = document.getElementById('kw-tool-window');
        if (!win) return;
        win.querySelectorAll('input[type="number"]').forEach(function(el) {
            el.addEventListener('focus', function() { this.select(); });
        });
    }

    function ensureKwDom() {
        if (_kwDomInited) return;
        _kwDomInited = true;
        var tpl  = document.getElementById('kw-tool-tpl');
        var cont = document.getElementById('kw-tool-container');
        if (tpl && cont) {
            cont.appendChild(document.importNode(tpl.content, true));
            if (tpl.parentNode) tpl.parentNode.removeChild(tpl);
        }
        initKwDrag();
        initKwEnterNav();
        initKwEnterNavP();
        initKwSelectOnFocus();
        switchKwTab('shaft'); // 初始顯示軸件分頁
    }

    window.openKwTool = function() {
        ensureKwDom();
        var w = document.getElementById('kw-tool-window');
        if (w) w.style.display = 'block';
        setTimeout(function(){ var el = document.getElementById('kw-rt-nom'); if (el) el.focus(); }, 60);
    };
    window.closeKwTool = function() {
        var w = document.getElementById('kw-tool-window');
        if (w) w.style.display = 'none';
    };
    window.clearKwTool = function() {
        var platePane = document.getElementById('kw-pane-plate');
        var isPlate   = platePane && platePane.style.display !== 'none';
        if (isPlate) {
            ['kwp-rb-nom','kwp-rb-utol','kwp-rb-ltol',
             'kwp-rt-nom','kwp-rt-utol','kwp-rt-ltol',
             'kwp-rr-nom','kwp-rr-utol','kwp-rr-ltol'].forEach(function(id){
                var el = document.getElementById(id); if (el) el.value = '';
            });
            calcKwP();
            var el = document.getElementById('kwp-rt-nom'); if (el) el.focus();
        } else {
            ['kw-rt-nom','kw-rt-utol','kw-rt-ltol',
             'kw-rr-nom','kw-rr-utol','kw-rr-ltol',
             'kw-rb-nom','kw-rb-utol','kw-rb-ltol',
             'kw-lt-nom','kw-lt-utol','kw-lt-ltol'].forEach(function(id){
                var el = document.getElementById(id); if (el) el.value = '';
            });
            calcKw();
            var el = document.getElementById('kw-rt-nom'); if (el) el.focus();
        }
    };

    window.switchKwTab = function(tab) {
        var A = 'padding:6px 14px;border:none;border-bottom:2px solid #27ae60;background:transparent;color:#27ae60;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:-2px;';
        var I = 'padding:6px 14px;border:none;border-bottom:2px solid transparent;background:transparent;color:#777;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:-2px;';
        var sp = document.getElementById('kw-pane-shaft');
        var pp = document.getElementById('kw-pane-plate');
        var sb = document.getElementById('kw-tab-shaft');
        var pb = document.getElementById('kw-tab-plate');
        if (tab === 'shaft') {
            if (sp) sp.style.display = ''; if (pp) pp.style.display = 'none';
            if (sb) sb.style.cssText = A;   if (pb) pb.style.cssText = I;
        } else {
            if (sp) sp.style.display = 'none'; if (pp) pp.style.display = '';
            if (sb) sb.style.cssText = I;      if (pb) pb.style.cssText = A;
            setTimeout(function(){ var el = document.getElementById('kwp-rt-nom'); if (el) el.focus(); }, 60);
        }
    };

    window.calcKwP = function() {
        function pV(id){ var el=document.getElementById(id); if(!el) return null; var v=parseFloat(el.value); return isNaN(v)?null:v; }
        function pS(id,v){ var el=document.getElementById(id); if(!el) return; el.textContent=(v===null||v===undefined)?'—':String(v); }

        var rtN=pV('kwp-rt-nom'), rtU=pV('kwp-rt-utol'), rtL=pV('kwp-rt-ltol');
        var rrN=pV('kwp-rr-nom'), rrU=pV('kwp-rr-utol'), rrL=pV('kwp-rr-ltol');
        var rtUlim=(rtN!==null&&rtU!==null)?rtN+rtU:null;
        var rtLlim=(rtN!==null&&rtL!==null)?rtN+rtL:null;
        var rrUlim=(rrN!==null&&rrU!==null)?rrN+rrU:null;
        var rrLlim=(rrN!==null&&rrL!==null)?rrN+rrL:null;
        pS('kwp-rt-ulim', kwFmtLim(rtUlim!==null?parseFloat(rtUlim.toFixed(5)):null));
        pS('kwp-rt-llim', kwFmtLim(rtLlim!==null?parseFloat(rtLlim.toFixed(5)):null));
        pS('kwp-rr-ulim', kwFmtLim(rrUlim!==null?parseFloat(rrUlim.toFixed(5)):null));
        pS('kwp-rr-llim', kwFmtLim(rrLlim!==null?parseFloat(rrLlim.toFixed(5)):null));

        // 差異（片狀方向：成品 > 實車）
        // 注意：研磨量必須「交叉相減」才能取得極端值
        var difU=null,difL=null,grnd1U=null,grnd1L=null;
        if(rtUlim!==null&&rtLlim!==null&&rrUlim!==null&&rrLlim!==null){
            difU   = rtUlim - rrLlim;  // 最大直徑研磨量：成品上限 − 實車下限
            difL   = rtLlim - rrUlim;  // 最小直徑研磨量：成品下限 − 實車上限
            grnd1U = difU / 2;          // 最大單邊研磨量
            grnd1L = difL / 2;          // 最小單邊研磨量
        }
        pS('kwp-dif-u',    difU!==null  ?kwFmt(parseFloat(difU.toFixed(5))):null);
        pS('kwp-dif-l',    difL!==null  ?kwFmt(parseFloat(difL.toFixed(5))):null);
        pS('kwp-grind-u',  difU!==null  ?kwFmt(parseFloat(difU.toFixed(5))):null);
        pS('kwp-grind-l',  difL!==null  ?kwFmt(parseFloat(difL.toFixed(5))):null);
        pS('kwp-grind1-u', grnd1U!==null?kwFmt(parseFloat(grnd1U.toFixed(5))):null);
        pS('kwp-grind1-l', grnd1L!==null?kwFmt(parseFloat(grnd1L.toFixed(5))):null);

        // 右下（已移至左欄）
        var rbN=pV('kwp-rb-nom'),rbU=pV('kwp-rb-utol'),rbL=pV('kwp-rb-ltol');
        var rbUlim=(rbN!==null&&rbU!==null)?rbN+rbU:null;
        var rbLlim=(rbN!==null&&rbL!==null)?rbN+rbL:null;
        pS('kwp-rb-ulim', kwFmtLim(rbUlim!==null?parseFloat(rbUlim.toFixed(5)):null));
        pS('kwp-rb-llim', kwFmtLim(rbLlim!==null?parseFloat(rbLlim.toFixed(5)):null));
        var rbMU=null,rbML=null;
        if(rbUlim!==null&&rbLlim!==null&&grnd1U!==null&&grnd1L!==null){
            // 安全公差：確保不論研磨量落在哪個極端，成品必定在公差內
            // 安全下限：成品下限 − 最小單邊研磨量（往上捨，避免低於成品要求）
            rbML = kwRU(rbLlim - grnd1L, 2);
            // 安全上限：成品上限 − 最大單邊研磨量（往下捨，避免超過成品要求）
            rbMU = kwRD(rbUlim - grnd1U, 2);
        }
        // 加工尺寸：以下限為標稱，上公差為正數，下公差=0
        pS('kwp-rb-mnom',  rbML!==null?kwFmt(rbML):null);
        pS('kwp-rb-mutol', rbMU!==null&&rbML!==null?kwFmt(parseFloat((rbMU-rbML).toFixed(5))):null);
        pS('kwp-rb-mulim', rbMU!==null?kwFmtLim(rbMU):null);
        pS('kwp-rb-mllim', rbML!==null?kwFmtLim(rbML):null);
    };

    window.calcKw = function() {
        // ── 讀取右上 ──────────────────────────────────────────────────────
        var rtN  = kwVal('kw-rt-nom');
        var rtU  = kwVal('kw-rt-utol');
        var rtL  = kwVal('kw-rt-ltol');
        var rrN  = kwVal('kw-rr-nom');
        var rrU  = kwVal('kw-rr-utol');
        var rrL  = kwVal('kw-rr-ltol');

        // 右上 顯示上下限
        var rtUlim = (rtN !== null && rtU !== null) ? rtN + rtU : null;
        var rtLlim = (rtN !== null && rtL !== null) ? rtN + rtL : null;
        var rrUlim = (rrN !== null && rrU !== null) ? rrN + rrU : null;
        var rrLlim = (rrN !== null && rrL !== null) ? rrN + rrL : null;

        kwSet('kw-rt-ulim', kwFmtLim(rtUlim !== null ? parseFloat(rtUlim.toFixed(5)) : null));
        kwSet('kw-rt-llim', kwFmtLim(rtLlim !== null ? parseFloat(rtLlim.toFixed(5)) : null));
        kwSet('kw-rr-ulim', kwFmtLim(rrUlim !== null ? parseFloat(rrUlim.toFixed(5)) : null));
        kwSet('kw-rr-llim', kwFmtLim(rrLlim !== null ? parseFloat(rrLlim.toFixed(5)) : null));

        // ── 差異 & 研磨量 ─────────────────────────────────────────────────
        var difU  = null, difL  = null;
        var grndU = null, grndL = null, grnd1U = null, grnd1L = null;
        if (rtUlim !== null && rtLlim !== null && rrUlim !== null && rrLlim !== null) {
            difU  = rrUlim - rtLlim;   // 實車上限 - 成品下限
            difL  = rrLlim - rtUlim;   // 實車下限 - 成品上限
            grndU = rrUlim - rtUlim;   // 研磨量上（直徑）
            grndL = rrLlim - rtLlim;   // 研磨量下（直徑）
            grnd1U = grndU / 2;
            grnd1L = grndL / 2;
        }
        kwSet('kw-dif-u',    difU  !== null ? kwFmt(parseFloat(difU.toFixed(5)))  : null);
        kwSet('kw-dif-l',    difL  !== null ? kwFmt(parseFloat(difL.toFixed(5)))  : null);
        kwSet('kw-grind-u',  grndU !== null ? kwFmt(parseFloat(grndU.toFixed(5))) : null);
        kwSet('kw-grind-l',  grndL !== null ? kwFmt(parseFloat(grndL.toFixed(5))) : null);
        kwSet('kw-grind1-u', grnd1U !== null ? kwFmt(parseFloat(grnd1U.toFixed(5))) : null);
        kwSet('kw-grind1-l', grnd1L !== null ? kwFmt(parseFloat(grnd1L.toFixed(5))) : null);

        // ── 讀取右下 / 左上 ───────────────────────────────────────────────
        var rbN  = kwVal('kw-rb-nom'),  rbU = kwVal('kw-rb-utol'), rbL = kwVal('kw-rb-ltol');
        var ltN  = kwVal('kw-lt-nom'),  ltU = kwVal('kw-lt-utol'), ltL = kwVal('kw-lt-ltol');
        var rbHas = (rbN !== null);
        var ltHas = (ltN !== null);

        // 互斥警告
        var msgEl = document.getElementById('kw-msg-box');
        if (msgEl) {
            if (rbHas && ltHas) {
                msgEl.className = 'warn';
                msgEl.textContent = '⚠ 右下與左上只能擇一填寫，請清除其中一個。';
            } else {
                msgEl.className = '';
                msgEl.textContent = '';
            }
        }

        // ── 右下 顯示上下限 & 加工/検驗 ───────────────────────────────────
        var rbUlim = (rbN !== null && rbU !== null) ? rbN + rbU : null;
        var rbLlim = (rbN !== null && rbL !== null) ? rbN + rbL : null;
        kwSet('kw-rb-ulim', kwFmtLim(rbUlim !== null ? parseFloat(rbUlim.toFixed(5)) : null));
        kwSet('kw-rb-llim', kwFmtLim(rbLlim !== null ? parseFloat(rbLlim.toFixed(5)) : null));

        var rbMU = null, rbML = null;
        var rbChkU = null, rbChkL = null;
        if (rbUlim !== null && rbLlim !== null && grnd1U !== null && grnd1L !== null) {
            var absG1U = Math.abs(grnd1U);
            var absG1L = Math.abs(grnd1L);
            // 加工上限：成品上限 + 最小單邊研磨量（取絕對值），往下捨
            rbMU = kwRD(rbUlim + absG1L, 2);
            // 加工下限：成品下限 + 最大單邊研磨量（取絕對值），往上捨
            rbML = kwRU(rbLlim + absG1U, 2);
            // 驗算：加工上下限 − 對應研磨量 ≈ 成品上下限
            rbChkU = parseFloat((rbMU - absG1L).toFixed(5));
            rbChkL = parseFloat((rbML - absG1U).toFixed(5));
        }
        kwSet('kw-rb-mnom',  rbMU !== null ? kwFmt(rbMU) : null);
        kwSet('kw-rb-mltol', rbMU !== null && rbML !== null ? kwFmt(parseFloat((rbML - rbMU).toFixed(5))) : null);
        kwSet('kw-rb-mulim', rbMU !== null ? kwFmtLim(rbMU) : null);
        kwSet('kw-rb-mllim', rbML !== null ? kwFmtLim(rbML) : null);
        kwSet('kw-rb-mulim-chk', rbChkU !== null ? '→' + kwFmt(rbChkU) : '');
        kwSet('kw-rb-mllim-chk', rbChkL !== null ? '→' + kwFmt(rbChkL) : '');

        // ── 左上 顯示上下限 & 加工/検驗 ───────────────────────────────────
        var ltUlim = (ltN !== null && ltU !== null) ? ltN + ltU : null;
        var ltLlim = (ltN !== null && ltL !== null) ? ltN + ltL : null;
        kwSet('kw-lt-ulim', kwFmtLim(ltUlim !== null ? parseFloat(ltUlim.toFixed(5)) : null));
        kwSet('kw-lt-llim', kwFmtLim(ltLlim !== null ? parseFloat(ltLlim.toFixed(5)) : null));

        var ltMU = null, ltML = null;
        var ltChkU = null, ltChkL = null;
        if (ltUlim !== null && ltLlim !== null && grnd1U !== null && grnd1L !== null) {
            var absG1U_lt = Math.abs(grnd1U);
            var absG1L_lt = Math.abs(grnd1L);
            ltMU = kwRD(ltUlim + absG1L_lt, 2);
            ltML = kwRU(ltLlim + absG1U_lt, 2);
            ltChkU = parseFloat((ltMU - absG1L_lt).toFixed(5));
            ltChkL = parseFloat((ltML - absG1U_lt).toFixed(5));
        }
        kwSet('kw-lt-mnom',  ltMU !== null ? kwFmt(ltMU) : null);
        kwSet('kw-lt-mltol', ltMU !== null && ltML !== null ? kwFmt(parseFloat((ltML - ltMU).toFixed(5))) : null);
        kwSet('kw-lt-mulim', ltMU !== null ? kwFmtLim(ltMU) : null);
        kwSet('kw-lt-mllim', ltML !== null ? kwFmtLim(ltML) : null);
        kwSet('kw-lt-mulim-chk', ltChkU !== null ? '→' + kwFmt(ltChkU) : '');
        kwSet('kw-lt-mllim-chk', ltChkL !== null ? '→' + kwFmt(ltChkL) : '');

        // ── 左下（圓心到鍵槽底）─────────────────────────────────────────
        // 取有填入的一側（右下優先若兩側都填則以右下為準，互斥警告已提示）
        var filledN = rbHas ? rbN : (ltHas ? ltN : null);
        var filledL = rbHas ? rbLlim : (ltHas ? ltLlim : null);

        var lbMN = null, lbMLtol = null;
        if (filledN !== null && rrLlim !== null && rtLlim !== null && filledL !== null) {
            // E18: ROUNDDOWN(K14 - K6/2, 2)  K6=실차下限, K14=filled 성품 nominal
            lbMN    = kwRD(filledN - rrLlim / 2, 2);
            // F19: ROUNDUP(ABS(K7/2 - K15) - E18, 2)  K7=성품下限, K15=filled 성품下限
            var raw = Math.abs(rtLlim / 2 - filledL) - lbMN;
            lbMLtol = kwRU(raw, 2);
        }
        kwSet('kw-lb-mnom',  lbMN !== null ? kwFmt(lbMN) : null);
        kwSet('kw-lb-mltol', lbMLtol !== null ? kwFmt(lbMLtol) : null);
        kwSet('kw-lb-mulim', lbMN !== null ? kwFmtLim(lbMN) : null);
        kwSet('kw-lb-mllim', (lbMN !== null && lbMLtol !== null) ? kwFmtLim(parseFloat((lbMN + lbMLtol).toFixed(5))) : null);
    };

    function initKwDrag() {
        var hdr = document.getElementById('kw-tool-hdr');
        var win = document.getElementById('kw-tool-window');
        if (!hdr || !win) return;
        var sx, sy, sl, st;
        hdr.addEventListener('mousedown', function(e) {
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'I') return;
            sx = e.clientX; sy = e.clientY;
            sl = parseInt(win.style.left) || win.getBoundingClientRect().left;
            st = parseInt(win.style.top)  || win.getBoundingClientRect().top;
            win.style.transform = 'none';
            win.style.left = sl + 'px'; win.style.top = st + 'px';
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup',   onDrop);
            e.preventDefault();
        });
        function onDrag(e) {
            win.style.left = (sl + e.clientX - sx) + 'px';
            win.style.top  = (st + e.clientY - sy) + 'px';
        }
        function onDrop() {
            document.removeEventListener('mousemove', onDrag);
            document.removeEventListener('mouseup',   onDrop);
        }
    }
})();
</script>

<?php if ($IS_OT_RBAC_ADMIN): ?>
<!-- ═══ 角色設定 Modal（訂單追蹤，僅管理員）═══════════════════════════════ -->
<div class="modal fade" id="otRoleModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" style="width:900px;max-width:96vw;" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:#8a5a2b;color:#fff;padding:12px 18px;">
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;"><span>&times;</span></button>
        <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-key" style="margin-right:7px;"></i>訂單追蹤－角色設定</h4>
      </div>
      <div class="modal-body" style="padding:0;">
        <div style="display:flex;gap:0;height:420px;font-size:13px;">
          <!-- 左：角色清單 -->
          <div style="width:200px;min-width:200px;border-right:1px solid #ddd;display:flex;flex-direction:column;">
            <div style="padding:8px 10px;background:#f8f9fa;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
              <span style="font-weight:600;font-size:12px;">角色清單</span>
              <button class="btn btn-xs btn-success" onclick="otAddRole()"><i class="fa fa-plus"></i> 新增</button>
            </div>
            <div id="ot-roles-list" style="flex:1;overflow-y:auto;padding:4px 0;">
              <div class="text-center text-muted" style="padding:20px;font-size:12px;">載入中...</div>
            </div>
          </div>
          <!-- 右：功能勾選（依功能分組） -->
          <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;">
            <div id="ot-role-feat-header" style="padding:8px 14px;background:#f8f9fa;border-bottom:1px solid #ddd;font-weight:600;font-size:12px;color:#555;">
              ← 請選擇角色
            </div>
            <div id="ot-role-feat-body" style="flex:1;overflow-y:auto;padding:10px 14px;">
              <?php
              $_ot_grps = [];
              foreach ($OT_PAGE_FEATURES as $_ot_f) $_ot_grps[$_ot_f['group']][] = $_ot_f;
              foreach ($_ot_grps as $_ot_gname => $_ot_items): ?>
              <div style="margin-bottom:12px;">
                <div style="font-weight:600;color:#8a5a2b;margin-bottom:6px;font-size:12px;letter-spacing:.5px;border-bottom:1px dashed #e0d5c5;padding-bottom:3px;">
                  <i class="fa fa-folder-o"></i> <?= htmlspecialchars($_ot_gname) ?>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:6px 24px;">
                <?php foreach ($_ot_items as $_ot_feat): ?>
                  <label style="font-weight:normal;cursor:pointer;display:flex;align-items:center;gap:5px;margin:0;">
                    <input type="checkbox" class="ot-role-feat-cb"
                      value="<?= htmlspecialchars($_ot_feat['code']) ?>"
                      data-label="<?= htmlspecialchars($_ot_feat['label']) ?>">
                    <?= htmlspecialchars($_ot_feat['label']) ?>
                  </label>
                <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <div id="ot-role-feat-footer" style="padding:8px 14px;border-top:1px solid #ddd;background:#f8f9fa;display:none;">
              <small class="text-muted" id="ot-role-feat-note" style="float:left;line-height:28px;"></small>
              <div style="display:flex;gap:6px;justify-content:flex-end;">
                <button class="btn btn-default btn-sm" id="ot-btn-check-all" onclick="otToggleAllFeat(true)">
                  <i class="fa fa-check-square-o"></i> 全選
                </button>
                <button class="btn btn-default btn-sm" id="ot-btn-uncheck-all" onclick="otToggleAllFeat(false)">
                  <i class="fa fa-square-o"></i> 取消全選
                </button>
                <button class="btn btn-primary btn-sm" id="ot-btn-save-role-feat" onclick="otSaveRoleFeatures()">
                  <i class="fa fa-save"></i> 儲存角色設定
                </button>
              </div>
            </div>
          </div>
        </div>
        <p style="font-size:11px;color:#aaa;padding:6px 12px;border-top:1px solid #eee;margin:0;">
          <i class="fa fa-info-circle"></i> 使用者指派角色請至 <strong>管理設定 → 使用者權限</strong> 頁面操作。目前本頁權限檢查尚未切換為角色制，勾選設定將於切換後生效。
        </p>
      </div>
    </div>
  </div>
</div>

<script>
// ══ 角色設定（訂單追蹤 module='order_track'，僅管理員）════════════════════
var OT_ROLES_API = '../../src/store/Roles_API.php';
var _otSelRoleId = null;

function otOpenRoleModal() {
    $('#otRoleModal').modal('show');
    otLoadRolesPanel();
}

function otLoadRolesPanel() {
    $('#ot-roles-list').html('<div class="text-center text-muted" style="padding:20px;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>');
    $.get(OT_ROLES_API, { action:'get_roles', module:'order_track' }, function(res) {
        if (!res.success) { $('#ot-roles-list').html('<div class="text-danger" style="padding:10px;">載入失敗</div>'); return; }
        var html = '';
        res.data.forEach(function(r) {
            var isSystem = r.is_system == 1;
            var active = _otSelRoleId == r.role_id ? 'background:#f5ead9;font-weight:600;' : '';
            html += '<div class="ot-role-item" data-id="' + r.role_id + '"'
                 + ' style="padding:7px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;' + active + '"'
                 + ' onclick="otSelectRole(' + r.role_id + ',' + (isSystem ? 1 : 0) + ')">'
                 + '<span class="ot-role-name" style="flex:1;font-size:13px;">' + escapeHtml(r.role_name)
                 + (isSystem ? '<span class="label label-warning" style="font-size:9px;margin-left:5px;vertical-align:middle;">系統</span>' : '') + '</span>'
                 + (!isSystem ? '<button class="btn btn-xs btn-default" style="opacity:.65;padding:1px 5px;margin-right:3px;" title="修改角色名稱"'
                    + ' onclick="event.stopPropagation();otRenameRole(' + r.role_id + ',\'' + escapeHtml(r.role_name).replace(/'/g, "\\'") + '\')">'
                    + '<i class="fa fa-pencil"></i></button>'
                    + '<button class="btn btn-xs btn-danger" style="opacity:.6;padding:1px 5px;" title="刪除角色"'
                    + ' onclick="event.stopPropagation();otDeleteRole(' + r.role_id + ',\'' + escapeHtml(r.role_name).replace(/'/g, "\\'") + '\')">'
                    + '<i class="fa fa-times"></i></button>' : '')
                 + '</div>';
        });
        $('#ot-roles-list').html(html || '<div class="text-muted" style="padding:10px;font-size:12px;">尚無角色，請按右上「新增」建立</div>');
        if (_otSelRoleId) {
            var $cur = $('#ot-roles-list .ot-role-item[data-id="' + _otSelRoleId + '"]');
            if (!$cur.length) { _otSelRoleId = null; $('#ot-role-feat-header').text('← 請選擇角色'); $('#ot-role-feat-footer').hide(); }
        }
    });
}

function otSelectRole(roleId, isSystem) {
    _otSelRoleId = roleId;
    $('#ot-roles-list .ot-role-item').css({'background':'','font-weight':''});
    var $item = $('#ot-roles-list .ot-role-item[data-id="' + roleId + '"]');
    $item.css({'background':'#f5ead9','font-weight':'600'});
    var displayName = $item.find('.ot-role-name').clone().children().remove().end().text().trim();
    $('#ot-role-feat-header').text('設定功能：' + displayName);
    $('.ot-role-feat-cb').prop('checked', false).prop('disabled', !!isSystem);
    $('#ot-role-feat-footer').show();
    if (isSystem) {
        // 管理員系統角色 → 全勾且不可修改
        $('.ot-role-feat-cb').prop('checked', true);
        $('#ot-role-feat-note').text('系統角色不可修改（擁有全部功能）');
        $('#ot-role-feat-footer button').prop('disabled', true);
        return;
    }
    $('#ot-role-feat-note').text('');
    $('#ot-role-feat-footer button').prop('disabled', false);
    $.get(OT_ROLES_API, { action:'get_role_features', role_id: roleId }, function(res) {
        if (res.success && res.data) {
            res.data.forEach(function(code) {
                $('.ot-role-feat-cb[value="' + code + '"]').prop('checked', true);
            });
        }
    });
}

function otToggleAllFeat(checked) {
    $('.ot-role-feat-cb:not(:disabled)').prop('checked', checked);
}

function otSaveRoleFeatures() {
    if (!_otSelRoleId) return;
    var codes = [];
    $('.ot-role-feat-cb:checked').each(function() { codes.push($(this).val()); });
    $.post(OT_ROLES_API, { action:'save_role_features', role_id:_otSelRoleId, features: JSON.stringify(codes) }, function(res) {
        if (res.success) { showToast('角色設定已儲存'); }
        else { alert(res.message || '儲存失敗'); }
    });
}

function otAddRole() {
    var name = prompt('請輸入新角色名稱（例：業務助理、設計人員）');
    if (name === null) return;
    name = name.trim();
    if (!name) { alert('角色名稱不可空白'); return; }
    $.post(OT_ROLES_API, { action:'save_role', role_name: name, module:'order_track' }, function(res) {
        if (!res.success) { alert(res.message || '新增失敗'); return; }
        _otSelRoleId = res.role_id;
        otLoadRolesPanel();
    });
}

function otRenameRole(roleId, oldName) {
    var name = prompt('修改角色名稱', oldName);
    if (name === null) return;
    name = name.trim();
    if (!name) { alert('角色名稱不可空白'); return; }
    if (name === oldName) return;
    // Roles_API save_role 帶 role_id＝改名（不更動所屬模組與功能設定）
    $.post(OT_ROLES_API, { action:'save_role', role_id: roleId, role_name: name }, function(res) {
        if (!res.success) { alert(res.message || '修改失敗'); return; }
        showToast('角色名稱已更新');
        if (_otSelRoleId == roleId) $('#ot-role-feat-header').text('設定功能：' + name);
        otLoadRolesPanel();
    });
}

function otDeleteRole(roleId, roleName) {
    if (!confirm('刪除角色「' + roleName + '」？\n此角色的功能設定將一併刪除，已指派此角色的使用者將失去對應權限。')) return;
    $.post(OT_ROLES_API, { action:'delete_role', role_id: roleId }, function(res) {
        if (!res.success) { alert(res.message || '刪除失敗'); return; }
        if (_otSelRoleId == roleId) { _otSelRoleId = null; $('#ot-role-feat-header').text('← 請選擇角色'); $('#ot-role-feat-footer').hide(); }
        otLoadRolesPanel();
    });
}
</script>
<?php endif; ?>

<script>
// ══ RBAC 功能旗標（供切換角色制後 JS 端判斷；$OT_USE_RBAC=false 時僅供參考）══
window.OT_USE_RBAC = <?= json_encode($OT_USE_RBAC) ?>;
window.OT_IS_RBAC_ADMIN = <?= json_encode($IS_OT_RBAC_ADMIN) ?>;
window.OT_FEAT = <?= json_encode(array_values(array_map('strval', $_ot_features)), JSON_UNESCAPED_UNICODE) ?>;
window.otHasFeat = function(code) {
    return window.OT_FEAT.indexOf('all') !== -1 || window.OT_FEAT.indexOf(code) !== -1;
};

// ── 篩選列自適應：一行放不下時按鈕改只顯示圖示（.fb-compact 隱藏 .fb-txt）──
(function() {
    var fbFitTimer = null;
    function fbFit() {
        var bar = document.querySelector('.filter-bar');
        if (!bar) return;
        bar.classList.remove('fb-compact');
        bar.classList.remove('fb-packed');
        // 單行高度約 46px（input-sm 30 + 上下 padding 16）；超過即代表換行了
        if (bar.offsetHeight > 56) {
            bar.classList.add('fb-compact');           // 先把按鈕文字收成圖示
            if (bar.offsetHeight > 56) {               // 仍放不下 → 分頁不靠右，避免第二行整排空白
                bar.classList.add('fb-packed');
            }
        }
    }
    window.addEventListener('resize', function() {
        clearTimeout(fbFitTimer);
        fbFitTimer = setTimeout(fbFit, 120);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fbFit);
    } else {
        fbFit();
    }
    // 分頁是 AJAX 後才畫入、筆數變動時寬度會變 → 監聽分頁容器重繪後重新偵測
    var pc = document.getElementById('pagination-container');
    if (pc && window.MutationObserver) {
        new MutationObserver(function() {
            clearTimeout(fbFitTimer);
            fbFitTimer = setTimeout(fbFit, 60);
        }).observe(pc, { childList: true, subtree: true });
    }
})();
</script>

</body>
</html>
