<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

$conn = new DBConnection();

// ── 權限查詢 (與 OreadyReply_ForPm_BaseOfTime2.php 相同邏輯) ──
$_sid          = intval($_SESSION['id'] ?? 0);
$_script_path  = $_SERVER['PHP_SELF'];
$permission_code = 'R'; // 預設唯讀

try {
    $_pdo = $conn->getPDO();

    // Step1: 從 URL 找頁面
    $__s = $_pdo->prepare("SELECT page_id, group_id FROM system_module_pages WHERE (:s LIKE CONCAT('%', page_url) AND page_url IS NOT NULL AND page_url != '') OR (:s2 LIKE CONCAT('%', page_url_readonly) AND page_url_readonly IS NOT NULL AND page_url_readonly != '') LIMIT 1");
    $__s->execute([':s' => $_script_path, ':s2' => $_script_path]);
    $__page = $__s->fetch(PDO::FETCH_ASSOC);

    if ($__page) {
        // Step2: 取 group module_code
        $__gmc = null;
        if (!empty($__page['group_id'])) {
            $__g = $_pdo->prepare("SELECT module_code FROM system_modules WHERE group_id = ? LIMIT 1");
            $__g->execute([$__page['group_id']]);
            $__gmc = $__g->fetchColumn();
        }

        // Step3: 找使用者權限 (頁面優先, 再找群組)
        $__perms = [];
        $__sp = $_pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id = ? AND scope = 'page' AND module_code = ?");
        $__sp->execute([$_sid, $__page['page_id']]);
        $__pp = array_filter($__sp->fetchAll(PDO::FETCH_COLUMN));
        if (!empty($__pp)) {
            $__perms = $__pp;
        } elseif (!empty($__gmc)) {
            $__sg = $_pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id = ? AND scope = 'group' AND module_code = ?");
            $__sg->execute([$_sid, $__gmc]);
            $__gp = array_filter($__sg->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($__gp)) $__perms = $__gp;
        }

        // Step4: 整合權限
        $__chars = [];
        foreach ($__perms as $__ps) { $__chars = array_merge($__chars, str_split($__ps)); }
        $__uniq = array_unique($__chars);
        if (in_array('A', $__uniq)) {
            $permission_code = 'A';
        } elseif (!empty($__uniq)) {
            sort($__uniq);
            $permission_code = implode('', $__uniq);
        }
    }
} catch (Exception $__e) {
    error_log('Shipping_Analysis permission error: ' . $__e->getMessage());
}

// 權限旗標
$perm_can_delete = ($permission_code === 'A' || strpos($permission_code, 'D') !== false);
$perm_can_update = ($permission_code === 'A' || strpos($permission_code, 'U') !== false);

// 格式化顯示文字（與 OreadyReply 相同）
$permission_display_text = '';
$permission_tooltip_text = '';
if ($permission_code === 'A') {
    $permission_display_text = 'A 管理者';
    $permission_tooltip_text = '管理者權限，擁有所有功能';
} elseif ($permission_code) {
    $__dp = str_split($permission_code);
    sort($__dp);
    $__dpc = implode('+', $__dp);
    if ($__dpc === 'R') {
        $permission_display_text = 'R 檢視';
        $permission_tooltip_text = '僅查看，不可修改或刪除出貨資料';
    } elseif ($__dpc === 'R+U') {
        $permission_display_text = 'R+U 可修改';
        $permission_tooltip_text = '可修改出貨資料與批次修改，不可刪除';
    } elseif ($__dpc === 'D+R' || $__dpc === 'D+R+U') {
        $permission_display_text = $__dpc . ' 可刪除';
        $permission_tooltip_text = '可修改與刪除出貨資料';
    } else {
        $permission_display_text = $__dpc;
        $permission_tooltip_text = '';
    }
}

// 處理儲存出貨性質設定 (顏色/關鍵字) (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sale_type_config') {
    header('Content-Type: application/json');
    try {
        $user_id = $_SESSION['user_id'] ?? $_SESSION['id'];
        $page_code = 'shipping_analysis';
        $setting_key = 'sale_type_config';
        $settings_json = $_POST['settings'];

        // 檢查是否已存在設定
        $check_sql = "SELECT id FROM user_page_settings WHERE user_id = ? AND page_code = ? AND setting_key = ?";
        $stmt_check = $conn->getPDO()->prepare($check_sql);
        $stmt_check->execute([$user_id, $page_code, $setting_key]);
        
        if ($stmt_check->fetch()) {
            $sql = "UPDATE user_page_settings SET setting_value = ?, updated_at = NOW() WHERE user_id = ? AND page_code = ? AND setting_key = ?";
            $stmt = $conn->getPDO()->prepare($sql);
            $stmt->execute([$settings_json, $user_id, $page_code, $setting_key]);
        } else {
            $sql = "INSERT INTO user_page_settings (user_id, page_code, setting_key, setting_value, updated_at) VALUES (?, ?, ?, ?, NOW())";
            $stmt = $conn->getPDO()->prepare($sql);
            $stmt->execute([$user_id, $page_code, $setting_key, $settings_json]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 處理儲存預設出貨性質 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_top10_config') {
    header('Content-Type: application/json');
    try {
        $settings_json = $_POST['settings']; // This will be a JSON string of an array
        $param_group = 'SHIPPING_ANALYSIS';
        $param_key = 'TOP10_CONFIG';

        // Use INSERT ... ON DUPLICATE KEY UPDATE
        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description) 
                VALUES (?, ?, ?, '出貨分析預設出貨性質') 
                ON DUPLICATE KEY UPDATE param_value = VALUES(param_value)";
        $stmt = $conn->getPDO()->prepare($sql);
        $stmt->execute([$param_group, $param_key, $settings_json]);
        
        echo json_encode(['success' => true, 'message' => '預設已儲存']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 處理取得產品圖檔 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_files') {
    header('Content-Type: application/json');
    try {
        $pid = $_POST['product_id'];
        
        // 1. 搜尋關聯的 BOM (由新到舊)
        // 假設 bom.d_id 存的是料號字串
        $stmt = $conn->getPDO()->prepare("SELECT bom, sqty FROM bom WHERE d_id = ? ORDER BY Created_At DESC");
        $stmt->execute([$pid]);
        $bom_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $files = [];
        require_once __DIR__ . '/../../src/common/bom_dir_lib.php';   // 資料夾位置走設定鍵 bom_scan_dir，不再寫死 Z: 磁碟機代號
        $scan_dir = eg_bom_scan_dir_auto(); // 實體路徑 (NAS 映射，供 PHP 掃描檔案用)
        $url_dir = '/nas/';    // 網頁讀取路徑 (Apache Alias，供前端顯示圖片用)

        if (is_dir($scan_dir)) {
            $allFiles = scandir($scan_dir);
            foreach ($bom_rows as $row) {
                $bom = $row['bom'];
                $qty = $row['sqty'];
                foreach ($allFiles as $f) {
                    if ($f === '.' || $f === '..') continue;
                    // 只要是 bom 號碼開頭的圖面都要顯示
                    if (strpos($f, $bom) === 0) {
                        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                            $display_bom = $bom . ' x' . ($qty !== null ? $qty : '?') . 'pcs';
                            $files[] = ['bom' => $display_bom, 'name' => $f, 'path' => $url_dir . $f, 'type' => $ext];
                        }
                    }
                }
            }
        }
        echo json_encode(['success' => true, 'files' => $files]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 批次刪除出貨資料 (需權限 A 或含 D) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_is_records') {
    header('Content-Type: application/json');
    try {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) { $ids = json_decode($ids, true) ?: []; }
        $ids = array_filter(array_map('intval', $ids), function($v){ return $v > 0; });
        if (empty($ids)) {
            echo json_encode(['success' => false, 'message' => '請提供要刪除的 ID 清單']);
            exit;
        }
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->getPDO()->prepare("DELETE FROM is_list WHERE IS_id IN ($ph)");
        $stmt->execute(array_values($ids));
        echo json_encode(['success' => true, 'message' => '已刪除 ' . $stmt->rowCount() . ' 筆資料']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 取得本筆出貨單資訊 + 同料號 BOM 列表 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_bom_list_for_is') {
    header('Content-Type: application/json');
    try {
        $pdo   = $conn->getPDO();
        $is_id = intval($_POST['is_id'] ?? 0);
        if ($is_id <= 0) throw new Exception('無效的出貨記錄ID');

        // 取本筆出貨單完整資訊
        $s = $pdo->prepare(
            'SELECT il.IS_id, il.IS_number, il.Order_date, il.Client_name, il.Product_id,'
            . ' il.Specification, il.Qty, il.Order_id, il.d_setting_id,'
            . ' ds.D_Setting_Id AS d_setting_display,'
            . ' COALESCE(cl.customer, il.Client_name) AS Client_name_display'
            . ' FROM is_list il'
            . ' LEFT JOIN d_setting ds ON il.d_setting_id = ds.d_id'
            . ' LEFT JOIN customer_list cl ON il.Client_id = cl.customer_id'
            . ' WHERE il.IS_id = ? LIMIT 1'
        );
        $s->execute([$is_id]);
        $is_info = $s->fetch(PDO::FETCH_ASSOC);
        if (!$is_info) throw new Exception('找不到出貨單');

        // 找同料號所有 BOM
        $boms = [];
        $d_setting_id = $is_info['d_setting_id'];
        $product_id   = $is_info['Product_id'];

        if ($d_setting_id) {
            $sb = $pdo->prepare(
                'SELECT b.bom, b.d_id, b.sqty, b.Client_Name, b.processing_state,'
                . ' b.bom_ps, b.o_order_id, b.Delivery_date, b.d_setting_id'
                . ' FROM bom b WHERE b.d_setting_id = ? ORDER BY b.bom DESC'
            );
            $sb->execute([$d_setting_id]);
            $boms = $sb->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($boms) && $product_id) {
            $sb2 = $pdo->prepare(
                'SELECT b.bom, b.d_id, b.sqty, b.Client_Name, b.processing_state,'
                . ' b.bom_ps, b.o_order_id, b.Delivery_date, b.d_setting_id'
                . ' FROM bom b WHERE b.d_id = ? ORDER BY b.bom DESC'
            );
            $sb2->execute([$product_id]);
            $boms = $sb2->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($boms)) {
            $bom_ids = array_column($boms, 'bom');
            $ph = implode(',', array_fill(0, count($bom_ids), '?'));

            // 綁定訂單資訊
            $sm = $pdo->prepare(
                'SELECT bopm.bom, bopm.order_id, bopm.allocated_qty,'
                . ' ot.Order_oo, ot.Client_name AS order_client, ot.Qty AS order_qty'
                . ' FROM bom_order_process_map bopm'
                . ' JOIN order_track ot ON bopm.order_id = ot.Order_id'
                . ' WHERE bopm.bom IN (' . $ph . ') ORDER BY bopm.bom'
            );
            $sm->execute($bom_ids);
            $bound_map = [];
            foreach ($sm->fetchAll(PDO::FETCH_ASSOC) as $bm) { $bound_map[$bm['bom']][] = $bm; }

            // 製程資訊
            $sp = $pdo->prepare(
                'SELECT bi.bom, bi.bom_sn, bi.bom_ing_fid, bi.process_no,'
                . ' pn.ProcessName, bi.processing_state AS proc_state,'
                . ' ml.maker_id, bi.sqty AS proc_sqty'
                . ' FROM bom_ing bi'
                . ' LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo'
                . ' LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no'
                . ' WHERE bi.bom IN (' . $ph . ')'
                . ' ORDER BY bi.bom, CAST(bi.bom_sn AS UNSIGNED)'
            );
            $sp->execute($bom_ids);
            $proc_map = [];
            foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $p) { $proc_map[$p['bom']][] = $p; }

            // 已綁定出貨單（透過 bom_order_process_map → order_id → shipment_order_map + is_list）
            // 查詢方式1：透過 shipment_order_map 精確綁定的出貨單
            $se1 = $pdo->prepare(
                'SELECT bopm.bom,'
                . ' il.IS_id, il.IS_number, il.Order_date, il.Client_name, il.Specification,'
                . ' il.Qty, il.Unit_price, som.shipped_qty'
                . ' FROM bom_order_process_map bopm'
                . ' JOIN shipment_order_map som ON som.Order_id = bopm.order_id'
                . ' JOIN is_list il ON il.IS_id = som.IS_id'
                . ' WHERE bopm.bom IN (' . $ph . ')'
                . ' ORDER BY bopm.bom, il.Order_date DESC'
            );
            $se1->execute($bom_ids);
            $is_map = [];
            $seen_is = [];
            foreach ($se1->fetchAll(PDO::FETCH_ASSOC) as $si) {
                $is_map[$si['bom']][] = $si;
                $seen_is[$si['bom']][$si['IS_id']] = true;
            }

            // 查詢方式2：透過 is_list.Order_id 直接關聯（補充沒在方式1的）
            $se2 = $pdo->prepare(
                'SELECT bopm.bom,'
                . ' il.IS_id, il.IS_number, il.Order_date, il.Client_name, il.Specification,'
                . ' il.Qty, il.Unit_price, il.Qty AS shipped_qty'
                . ' FROM bom_order_process_map bopm'
                . ' JOIN is_list il ON il.Order_id = bopm.order_id'
                . ' WHERE bopm.bom IN (' . $ph . ')'
                . ' ORDER BY bopm.bom, il.Order_date DESC'
            );
            $se2->execute($bom_ids);
            foreach ($se2->fetchAll(PDO::FETCH_ASSOC) as $si) {
                if (empty($seen_is[$si['bom']][$si['IS_id']])) {
                    $is_map[$si['bom']][] = $si;
                }
            }

            foreach ($boms as &$b) {
                $b['bound_orders']   = $bound_map[$b['bom']] ?? [];
                $b['is_bound']       = !empty($bound_map[$b['bom']]);
                $b['processes']      = $proc_map[$b['bom']] ?? [];
                $b['bound_shipments']= $is_map[$b['bom']] ?? [];
            }
            unset($b);
        }

        // 自動查找唯一匹配訂單（料號完全相同，客戶 LIKE，只有一筆結果時回傳）
        $auto_order = null;
        if (!$is_info['Order_id'] && $product_id) {
            $client_kw = '%' . ($is_info['Client_name'] ?? '') . '%';
            $stmt_auto = $pdo->prepare(
                'SELECT Order_id, Order_oo, Client_name, Qty, Delivery_date'
                . ' FROM order_track'
                . ' WHERE d_id = ? AND Client_name LIKE ?'
                . ' AND (Order_status IS NULL OR Order_status != 9)'
                . ' ORDER BY Order_date DESC LIMIT 2'
            );
            $stmt_auto->execute([$product_id, $client_kw]);
            $auto_rows = $stmt_auto->fetchAll(PDO::FETCH_ASSOC);
            if (count($auto_rows) === 1) {
                $auto_order = $auto_rows[0];
            }
        }

        echo json_encode(['success' => true, 'is_info' => $is_info, 'boms' => $boms, 'auto_order' => $auto_order]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 儲存 BOM ↔ 訂單綁定 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bom_order_bind') {
    header('Content-Type: application/json');
    try {
        $pdo           = $conn->getPDO();
        $bom           = trim($_POST['bom'] ?? '');
        $is_id         = intval($_POST['is_id'] ?? 0);
        $order_id      = intval($_POST['order_id'] ?? 0);
        $allocated_qty = intval($_POST['allocated_qty'] ?? 0);

        if ($bom === '')         throw new Exception('未提供 BOM');
        if ($allocated_qty <= 0) throw new Exception('請輸入有效數量');

        // 若無 order_id，自動查找
        if ($order_id <= 0) {
            $sb = $pdo->prepare('SELECT o_order_id FROM bom WHERE bom=? LIMIT 1');
            $sb->execute([$bom]);
            $o_oo = $sb->fetchColumn();
            if ($o_oo) {
                $so = $pdo->prepare('SELECT Order_id FROM order_track WHERE Order_oo=? LIMIT 1');
                $so->execute([$o_oo]);
                $f = $so->fetchColumn();
                if ($f) $order_id = intval($f);
            }
            if ($order_id <= 0 && $is_id > 0) {
                $si = $pdo->prepare('SELECT Order_id FROM is_list WHERE IS_id=? LIMIT 1');
                $si->execute([$is_id]);
                $f2 = $si->fetchColumn();
                if ($f2) $order_id = intval($f2);
            }
        }

        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'need_order_search' => true, 'message' => '找不到關聯訂單，請搜尋並選擇訂單']);
            exit;
        }

        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO bom_order_process_map (bom, order_id, allocated_qty, created_at, updated_at)'
            . ' VALUES (?, ?, ?, NOW(), NOW())'
            . ' ON DUPLICATE KEY UPDATE allocated_qty = VALUES(allocated_qty), updated_at = NOW()'
        )->execute([$bom, $order_id, $allocated_qty]);

        if ($is_id > 0) {
            // 確認 order_id 存在於 order_list（shipment_order_map 有外鍵限制）
            $ol_chk = $pdo->prepare('SELECT Order_id FROM order_list WHERE Order_id=? LIMIT 1');
            $ol_chk->execute([$order_id]);
            $in_order_list = $ol_chk->fetchColumn();

            if ($in_order_list) {
                // order_id 在 order_list 才能寫入 shipment_order_map
                $chk = $pdo->prepare('SELECT id FROM shipment_order_map WHERE IS_id=? AND Order_id=? LIMIT 1');
                $chk->execute([$is_id, $order_id]);
                if (!$chk->fetchColumn()) {
                    $pdo->prepare('INSERT INTO shipment_order_map (IS_id, Order_id, shipped_qty, created_at) VALUES (?, ?, ?, NOW())')
                        ->execute([$is_id, $order_id, $allocated_qty]);
                }
            }
            // 無論如何都更新 is_list.Order_id（此欄位無外鍵限制）
            $pdo->prepare('UPDATE is_list SET Order_id=? WHERE IS_id=? AND (Order_id IS NULL OR Order_id=0)')
                ->execute([$order_id, $is_id]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $order_id, 'in_order_list' => isset($in_order_list) && $in_order_list ? true : false]);
    } catch(Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 搜尋訂單（BOM 綁定時手動選擇）──
// 同時查 order_track 和 order_list，並標示是否在 order_list（影響外鍵可否寫入 shipment_order_map）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'search_order_for_bom') {
    header('Content-Type: application/json');
    try {
        $pdo  = $conn->getPDO();
        $kw   = trim($_POST['keyword'] ?? '');
        $d_id = trim($_POST['d_id'] ?? '');
        if ($kw === '' && $d_id === '') { echo json_encode(['success'=>true,'data'=>[]]); exit; }
        $like  = '%' . $kw . '%';

        // 搜尋 order_track（已出貨/歷史訂單）
        $where_ot = $d_id !== '' ? 'AND ot.d_id = ?' : '';
        $params_ot = $d_id !== '' ? [$like, $like, $like, $d_id] : [$like, $like, $like];
        $stmt_ot = $pdo->prepare(
            'SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Client_name,'
            . ' ot.Qty, ot.Order_date, ot.Delivery_date, ot.Specification, "track" AS source'
            . ' FROM order_track ot'
            . ' WHERE (ot.Order_oo LIKE ? OR ot.d_id LIKE ? OR ot.Client_name LIKE ?)'
            . ' ' . $where_ot
            . ' ORDER BY ot.Order_date DESC LIMIT 15'
        );
        $stmt_ot->execute($params_ot);
        $rows_ot = $stmt_ot->fetchAll(PDO::FETCH_ASSOC);

        // 搜尋 order_list（未出貨訂單，可直接寫 shipment_order_map）
        $where_ol = $d_id !== '' ? 'AND ol.d_id = ?' : '';
        $params_ol = $d_id !== '' ? [$like, $like, $like, $d_id] : [$like, $like, $like];
        $stmt_ol = $pdo->prepare(
            'SELECT ol.Order_id, ol.Order_oo, ol.d_id, ol.Client_name,'
            . ' ol.Qty, ol.Order_date, ol.Delivery_date, ol.Specification, "list" AS source'
            . ' FROM order_list ol'
            . ' WHERE (ol.Order_oo LIKE ? OR ol.d_id LIKE ? OR ol.Client_name LIKE ?)'
            . ' ' . $where_ol
            . ' ORDER BY ol.Order_date DESC LIMIT 15'
        );
        $stmt_ol->execute($params_ol);
        $rows_ol = $stmt_ol->fetchAll(PDO::FETCH_ASSOC);

        // 取得所有在 order_list 的 Order_id 集合（用於標示）
        $all_rows = array_merge($rows_ol, $rows_ot);
        // 去重：同 Order_id 優先保留 order_list 版本
        $seen = [];
        $result = [];
        foreach ($all_rows as $r) {
            $oid = $r['Order_id'];
            if (!isset($seen[$oid])) {
                $seen[$oid] = true;
                $r['in_order_list'] = ($r['source'] === 'list');
                $result[] = $r;
            }
        }
        echo json_encode(['success'=>true, 'data'=>$result]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: 解除 BOM ↔ 訂單綁定 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unbind_bom_order') {
    header('Content-Type: application/json');
    try {
        $pdo      = $conn->getPDO();
        $bom      = trim($_POST['bom'] ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);
        if ($bom === '' || $order_id <= 0) throw new Exception('參數不足');
        $pdo->prepare('DELETE FROM bom_order_process_map WHERE bom=? AND order_id=?')
            ->execute([$bom, $order_id]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 搜尋料號 (d_setting) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_d_setting_bind') {
    header('Content-Type: application/json');
    try {
        $kw = '%' . trim($_POST['keyword'] ?? '') . '%';
        $stmt = $conn->getPDO()->prepare(
            "SELECT ds.d_id, ds.D_Setting_Id, ds.Spec_No,
                    cl.customer_id, cl.customer AS customer_name
             FROM d_setting ds
             LEFT JOIN customer_list cl ON ds.Customer_Id = cl.customer_id
             WHERE ds.D_Setting_Id LIKE ? OR ds.Spec_No LIKE ?
             ORDER BY ds.D_Setting_Id LIMIT 20"
        );
        $stmt->execute([$kw, $kw]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 搜尋客戶 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_customer_bind') {
    header('Content-Type: application/json');
    try {
        $kw = '%' . trim($_POST['keyword'] ?? '') . '%';
        $stmt = $conn->getPDO()->prepare(
            "SELECT customer_id, customer FROM customer_list
             WHERE (customer LIKE ? OR customer_id LIKE ?) AND is_inactive = 0
             ORDER BY customer LIMIT 20"
        );
        $stmt->execute([$kw, $kw]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 儲存料號/客戶綁定 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_bind_record') {
    header('Content-Type: application/json');
    try {
        $is_id      = intval($_POST['is_id'] ?? 0);
        $d_id       = !empty($_POST['d_setting_id']) ? intval($_POST['d_setting_id']) : null;
        $client_id  = trim($_POST['client_id'] ?? '');
        $client_name = trim($_POST['client_name'] ?? '');
        if ($is_id <= 0) throw new Exception('無效的出貨記錄 ID');

        $sets = [];
        $params = [];

        // d_setting_id (允許 null 清除)
        $sets[] = 'd_setting_id = ?';
        $params[] = $d_id;

        // Client_id
        if ($client_id !== '') {
            $sets[] = 'Client_id = ?';
            $params[] = $client_id;
        }

        // Client_name：若有 client_name 直接用；否則從 customer_list 查
        if ($client_name !== '') {
            $sets[] = 'Client_name = ?';
            $params[] = $client_name;
        } elseif ($client_id !== '') {
            $r = $conn->getPDO()->prepare("SELECT customer FROM customer_list WHERE customer_id = ? LIMIT 1");
            $r->execute([$client_id]);
            $cn = $r->fetchColumn();
            if ($cn) { $sets[] = 'Client_name = ?'; $params[] = $cn; }
        }

        if (empty($sets)) throw new Exception('沒有要更新的欄位');

        $params[] = $is_id;
        $conn->getPDO()->prepare("UPDATE is_list SET " . implode(', ', $sets) . " WHERE IS_id = ?")->execute($params);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 取得 GET 參數，若無則為空字串
$start_date_param = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date_param = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// 預設日期範圍：若兩者皆空，則預設為當前季度
if ($start_date_param === '' && $end_date_param === '') {
    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    
    if ($current_month >= 1 && $current_month <= 3) { // Q1
        $start_date = $current_year . '-01-01';
        $end_date = $current_year . '-03-31';
    } elseif ($current_month >= 4 && $current_month <= 6) { // Q2
        $start_date = $current_year . '-04-01';
        $end_date = $current_year . '-06-30';
    } elseif ($current_month >= 7 && $current_month <= 9) { // Q3
        $start_date = $current_year . '-07-01';
        $end_date = $current_year . '-09-30';
    } else { // Q4
        $start_date = $current_year . '-10-01';
        $end_date = $current_year . '-12-31';
    }
} else {
    $start_date = $start_date_param ?: date('Y-m-01');
    $end_date = $end_date_param ?: date('Y-m-d');
}
$use_closing = isset($_GET['use_closing']) && $_GET['use_closing'] == '1';

// 取得出貨性質列表
$sale_types_stmt = $conn->getPDO()->query("SELECT * FROM is_sale_type WHERE is_active = 1 ORDER BY sort_order");
$sale_types = $sale_types_stmt->fetchAll(PDO::FETCH_ASSOC);

// 讀取預設出貨性質設定
$default_sale_types = null;
$stmt_default = $conn->getPDO()->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'SHIPPING_ANALYSIS' AND param_key = 'TOP10_CONFIG'");
$stmt_default->execute();
$default_json = $stmt_default->fetchColumn();
if ($default_json) {
    $default_sale_types = json_decode($default_json, true);
}

// 處理出貨性質篩選 (多選)
// 邏輯：如果 GET 參數有值，就用 GET 參數。如果沒有，就用資料庫的預設值。如果都沒有，就為空陣列。
$filter_sale_types = $_GET['filter_sale_types'] ?? $default_sale_types ?? [];

// 構建 SQL 篩選條件
$sql_sale_type_condition = "";
if (!empty($filter_sale_types)) {
    $ids = [];
    $include_null = false;
    foreach ($filter_sale_types as $v) {
        if ($v === 'NULL') $include_null = true;
        else $ids[] = intval($v);
    }
    $parts = [];
    if (!empty($ids)) $parts[] = "isl.sale_type IN (" . implode(',', $ids) . ")";
    if ($include_null) $parts[] = "isl.sale_type IS NULL";
    
    if (!empty($parts)) $sql_sale_type_condition = " AND (" . implode(' OR ', $parts) . ")";
} else {
    // 預設：排除不納入統計的項目 (is_count = 0)
    $sql_sale_type_condition = " AND (ist.is_count != 0 OR ist.is_count IS NULL)";
}

// 查詢資料
// 先確認相關資料表是否存在（避免不存在時報錯）
$_q_has_bom_map = false;
$_q_has_som     = false;
try { $conn->getPDO()->query("SELECT 1 FROM bom_order_process_map LIMIT 1"); $_q_has_bom_map = true; } catch(Exception $_qe) {}
try { $conn->getPDO()->query("SELECT 1 FROM shipment_order_map LIMIT 1"); $_q_has_som     = true; } catch(Exception $_qe) {}

// 依資料表存在狀況決定子查詢
$_q_bom_sub = $_q_has_bom_map
    ? "(SELECT GROUP_CONCAT(DISTINCT bopm.bom ORDER BY bopm.bom SEPARATOR ', ')
         FROM bom_order_process_map bopm WHERE bopm.order_id = isl.Order_id)"
    : "NULL";
$_q_som_sub = $_q_has_som
    ? "(SELECT COUNT(*) FROM shipment_order_map som WHERE som.IS_id = isl.IS_id)"
    : "0";

$sql  = "SELECT isl.IS_id, isl.Order_date, isl.IS_number,";
$sql .= " isl.Client_name, isl.Client_id,";
$sql .= " COALESCE(cl.customer, isl.Client_name) AS Client_name_display,";
$sql .= " isl.Product_id, isl.d_setting_id, ds.D_Setting_Id AS d_setting_display,";
$sql .= " isl.Specification, isl.Qty, isl.Unit_price, isl.Order_id,";
$sql .= " isl.Warehouse, isl.Note, isl.sale_type,";
$sql .= " ist.sale_type_name, ist.is_count, ist.exclude_anomaly,";
$sql .= " ($_q_bom_sub) AS bom_list,";
$sql .= " ($_q_som_sub) AS is_shipment_mapped";
$sql .= " FROM is_list isl";
$sql .= " LEFT JOIN user ON user.id = isl.Created_By";
$sql .= " LEFT JOIN is_sale_type ist ON isl.sale_type = ist.sale_type_id";
$sql .= " LEFT JOIN customer_list cl ON isl.Client_id = cl.customer_id";
$sql .= " LEFT JOIN d_setting ds ON isl.d_setting_id = ds.d_id";
$sql .= " WHERE isl.Order_date BETWEEN :start_date AND :end_date $sql_sale_type_condition";
$sql .= " ORDER BY isl.Order_date DESC, isl.IS_id DESC";

$stmt = $conn->getPDO()->prepare($sql);
$stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 讀取結帳日設定
$stmt_param = $conn->getPDO()->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'SHIPPING_ANALYSIS' AND param_key = 'CLOSING_DATE_RULES'");
$stmt_param->execute();
$closing_rule_json = $stmt_param->fetchColumn();
$closing_rule = $closing_rule_json ? json_decode($closing_rule_json, true) : null;
// 傳遞給前端的設定
$closing_rule_js = $closing_rule_json ? $closing_rule_json : '{}';

// 判斷圖表顯示單位 (日/週/月)
$date_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
$chart_group_by = 'day';
if ($date_diff > 60) {
    $chart_group_by = 'month';
} elseif ($date_diff > 30) {
    $chart_group_by = 'week';
}

// 資料分析變數初始化
$total_qty = 0;
$total_amount = 0;
$valid_shipment_count = 0;
$client_stats = [];
$chart_stats = [];
$sale_type_stats = [];
$product_stats = [];

// 輔助函式：取得帳款月份 (若啟用結帳日)
function get_billing_month($date_str, $rules) {
    // 這裡需實作後端結帳日判斷，為簡化與前端一致，這裡做簡易判斷
    // 若需精確需複製前端 getClosingDate 邏輯到 PHP
    // 暫時以日曆月為主，若需嚴格後端分組需移植邏輯
    // 考慮到效能與複雜度，若 $chart_group_by == 'month' 且 $use_closing，
    // 我們可以依據日期的 '日' 來判斷歸屬月份
    
    $ts = strtotime($date_str);
    $y = intval(date('Y', $ts));
    $m = intval(date('n', $ts));
    $d = intval(date('j', $ts));
    
    // 取得該月結帳日規則，若無則預設月底(31)
    $rule_day = isset($rules[$m]) ? $rules[$m] : 'end';
    
    $cutoff = 31;
    if ($rule_day !== 'end') {
        $cutoff = intval($rule_day);
    }
    
    if ($d > $cutoff) {
        // 超過結帳日，歸下個月
        if ($m == 12) {
            return sprintf("%04d-01", $y + 1);
        } else {
            return sprintf("%04d-%02d", $y, $m + 1);
        }
    } else {
        return date('Y-m', $ts);
    }
}

foreach ($rows as $row) {
    $st_id = $row['sale_type'];
    $st_id_str = is_null($st_id) ? 'NULL' : (string)$st_id;

    // 1. 一般統計有效性 (總額、圖表、客戶) - 受上方篩選影響
    $is_valid_general = false;
    if (!empty($filter_sale_types)) {
        if (in_array($st_id_str, $filter_sale_types)) {
            $is_valid_general = true;
        }
    } else {
        // 若未指定篩選 (預設情況)，則排除不納入統計的項目
        // 注意：sale_type 為 NULL (一般產品) 時 is_count 為 NULL，視為納入
        if (!isset($row['is_count']) || $row['is_count'] != 0) {
            $is_valid_general = true;
        }
    }

    if (!$is_valid_general) continue;

    $qty = floatval($row['Qty']);
    $price = floatval($row['Unit_price']);
    $amount = $qty * $price;

    // 一般統計 (總額、客戶、性質、圖表)
    // if ($is_valid_general) { // Always true here
        if ($price > 0) $valid_shipment_count++;
        $total_qty += $qty;
        $total_amount += $amount;

        $client = $row['Client_name'] ?: '未知客戶';
        if (!isset($client_stats[$client])) $client_stats[$client] = 0;
        $client_stats[$client] += $amount;

        $st_name = $row['sale_type_name'] ?: '一般產品';
        if (!isset($sale_type_stats[$st_name])) $sale_type_stats[$st_name] = 0;
        $sale_type_stats[$st_name] += $amount;

        $date = $row['Order_date'];
        $key = $date;
        if ($chart_group_by == 'month') {
            if ($use_closing && $closing_rule) {
                $key = get_billing_month($date, $closing_rule);
            } else {
                $key = date('Y-m', strtotime($date));
            }
        } elseif ($chart_group_by == 'week') {
            $key = date('Y/m/d', strtotime('monday this week', strtotime($date)));
        }
        if (!isset($chart_stats[$key])) $chart_stats[$key] = 0;
        $chart_stats[$key] += ($amount / 10000);
    // }

    // 熱銷產品統計
    // if ($is_valid_top10) { // Always true here
        $pid = trim($row['Product_id'] ?? '');
        if ($pid == '' || $pid == '0' || $pid == '1') {
            $pid = '未知料號';
        }
        if (!isset($product_stats[$pid])) {
            $product_stats[$pid] = ['amount' => 0, 'qty' => 0, 'count' => 0];
        }
        $product_stats[$pid]['amount'] += $amount;
        $product_stats[$pid]['qty'] += $qty;
        $product_stats[$pid]['count']++;
    // }
}

// 排序並取前5名
arsort($client_stats);
$top_clients = array_slice($client_stats, 0, 5, true);

// 熱銷產品排序
uasort($product_stats, function($a, $b) {
    return $b['amount'] <=> $a['amount'];
});
$top_products = array_slice($product_stats, 0, 10, true);

// 出貨性質圖表數據
$sale_type_chart_data = [];
foreach ($sale_type_stats as $name => $val) {
    $sale_type_chart_data[] = ['name' => $name, 'y' => (float)number_format($val / 10000, 2, '.', '')];
}

ksort($chart_stats);

// 準備圖表數據 (金額)
$chart_dates = array_keys($chart_stats);
$chart_values = array_values($chart_stats);
$shipping_data_json = json_encode($rows);

// 載入出貨性質設定 (顏色/關鍵字)
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'];
$page_code = 'shipping_analysis';
$setting_key = 'sale_type_config';
$stmt_settings = $conn->getPDO()->prepare("SELECT setting_value FROM user_page_settings WHERE user_id = ? AND page_code = ? AND setting_key = ?");
$stmt_settings->execute([$user_id, $page_code, $setting_key]);
$row_settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);
$sale_type_config = $row_settings ? json_decode($row_settings['setting_value'], true) : [];
$sale_type_config_json = json_encode($sale_type_config);

// 產生除錯用的 SQL (模擬熱銷產品統計)
$sale_type_sql_condition = "AND (ist.is_count = 1 OR isl.sale_type IS NULL)"; // 預設：排除不納入統計的

$types_to_use_for_sql = [];
if (!empty($filter_sale_types)) {
    $types_to_use_for_sql = $filter_sale_types;
}

if (!empty($types_to_use_for_sql)) {
    $ids = [];
    $include_null = false;
    foreach ($types_to_use_for_sql as $v) {
        if ($v === 'NULL') $include_null = true;
        else $ids[] = intval($v);
    }
    $parts = [];
    if (!empty($ids)) $parts[] = "isl.sale_type IN (" . implode(',', $ids) . ")";
    if ($include_null) $parts[] = "isl.sale_type IS NULL";
    
    if (!empty($parts)) $sale_type_sql_condition = "AND (" . implode(' OR ', $parts) . ")";
}

$debug_sql = "SELECT 
    isl.Product_id,
    SUM(isl.Qty * isl.Unit_price) AS Total_Amount,
    AVG(isl.Qty) AS Avg_Qty,
    COUNT(*) AS Txn_Count
FROM is_list isl
LEFT JOIN is_sale_type ist ON isl.sale_type = ist.sale_type_id
WHERE isl.Order_date BETWEEN '$start_date' AND '$end_date'
$sale_type_sql_condition
GROUP BY isl.Product_id
ORDER BY Total_Amount DESC
LIMIT 20;";

// 產生除錯用的 SQL (出貨性質分佈)
$pie_debug_sql = "SELECT 
    COALESCE(ist.sale_type_name, '一般產品') AS Sale_Type_Name,
    SUM(isl.Qty * isl.Unit_price) AS Total_Amount
FROM is_list isl
LEFT JOIN is_sale_type ist ON isl.sale_type = ist.sale_type_id
WHERE isl.Order_date BETWEEN '$start_date' AND '$end_date'
$sale_type_sql_condition
GROUP BY COALESCE(ist.sale_type_name, '一般產品')";

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>出貨紀錄分析</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <!-- Datatables -->
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        .tile_count .tile_stats_count {
            margin-bottom: 10px;
            border-bottom: 0;
            padding-bottom: 10px;
        }
        .tile_count .tile_stats_count .count {
            font-size: 30px;
            font-weight: bold;
            line-height: 1.6; /* 調整行高 */
        }
        .x_title h2 {
            font-size: 18px;
            font-weight: bold;
        }
        #analysis-chart {
            height: 350px;
        }
        .filter-section {
            background: #f7f7f7;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #e6e9ed;
        }
        .table-responsive {
            overflow-x: auto;
        }
        /* 調整表格字體 */
        #shippingTable th, #shippingTable td {
            white-space: nowrap;
            vertical-align: middle;
            font-size: 13px;
        }
        .highcharts-figure, .highcharts-data-table table {
            min-width: 310px; 
            max-width: 800px;
            margin: 1em auto;
        }
        /* 將篩選欄位移出表格的樣式 */
        #external-filter-container {
            display: flex;
            flex-wrap: nowrap;
            gap: 5px;
            margin-bottom: 10px;
            padding: 5px;
            background: #f1f1f1;
            border: 1px solid #ddd;
            align-items: center;
            overflow-x: auto;
        }
        #external-filter-container input {
            min-width: 80px;
            max-width: 120px;
            display: inline-block;
        }
        #external-filter-container .select2-container {
            width: 150px !important;
        }
        .closing-date-info {
            float: right;
            font-size: 14px;
            color: #555;
            background: #fff;
            padding: 5px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .month-setting-row {
            max-height: 400px;
            overflow-y: auto;
        }
        .month-setting-item {
            padding: 8px 5px;
            border-bottom: 1px solid #eee;
        }
        .highlight-row {
            background-color: #fff3cd !important;
            transition: background-color 0.5s ease-in-out;
        }

        /* ── Toast 通知 ── */
        #sa-toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
        }
        .sa-toast {
            min-width: 260px;
            max-width: 380px;
            padding: 12px 18px;
            border-radius: 6px;
            color: #fff;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 14px rgba(0,0,0,0.18);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: sa-slidein .25s ease;
        }
        .sa-toast.success { background: #27ae60; }
        .sa-toast.danger  { background: #e74c3c; }
        .sa-toast.info    { background: #2980b9; }
        @keyframes sa-slidein {
            from { opacity: 0; transform: translateX(40px); }
            to   { opacity: 1; transform: translateX(0);    }
        }

        /* ── 篩選列美化 ── */
        #external-filter-container {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 8px 10px;
            gap: 6px;
            flex-wrap: wrap;
        }
        #external-filter-container input.form-control,
        #external-filter-container .select2-container--default .select2-selection--multiple {
            border-radius: 4px;
            font-size: 12px;
        }
        #external-filter-container .btn { font-size: 12px; }

        /* ── 批次操作面板美化 ── */
        #batch-update-panel {
            border-left: 4px solid #3498db !important;
            border-radius: 0 6px 6px 0 !important;
        }

        /* ── 表格美化 ── */
        #shippingTable thead th {
            background: #2c3e50;
            color: #fff;
            font-weight: 600;
            font-size: 12px;
            border-color: #34495e !important;
            white-space: nowrap;
            padding: 8px 10px;
        }
        #shippingTable tbody tr:hover {
            background-color: #eaf4ff !important;
        }
        #shippingTable tbody td {
            font-size: 13px;
            padding: 6px 10px;
            vertical-align: middle;
        }
        #shippingTable tbody tr.selected td {
            background-color: #d6eaf8 !important;
        }

        /* ── 統計磚美化 ── */
        .tile_stats_count {
            border-radius: 8px;
            padding: 16px 20px !important;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            border-left: 4px solid #3498db;
            margin-bottom: 16px !important;
        }
        .tile_stats_count:nth-child(2) { border-left-color: #27ae60; }
        .tile_stats_count:nth-child(3) { border-left-color: #e67e22; }
        .tile_stats_count:nth-child(4) { border-left-color: #9b59b6; }
        .tile_stats_count .count { font-size: 28px !important; }
        .tile_stats_count .count_top { font-size: 13px; color: #7f8c8d; }

        /* ── x_panel 小美化 ── */
        .x_panel {
            border-radius: 8px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.07);
        }
        .x_title {
            border-bottom: 1px solid #eee;
            background: #fafbfc;
            border-radius: 8px 8px 0 0;
        }

        /* ── 出貨單號連結 ── */
        .is-number-link {
            color: #2980b9;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
        }
        .is-number-link:hover { text-decoration: underline; color: #1a6fa8; }
        .is-number-link .fa-link { color: #27ae60; font-size: 11px; margin-left: 3px; }

        /* ── 綁定 Modal ── */
        .bind-section-title {
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            padding: 6px 0 4px;
            border-bottom: 2px solid #3498db;
            margin-bottom: 10px;
        }
        .bind-result-table { margin: 0; }
        .bind-result-table td { font-size: 12px; padding: 4px 6px !important; }
        .bind-current-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <!-- 選單 -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <!-- 頁面內容 -->
            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title_left">
                            <h3>出貨紀錄與分析 <small>Shipping Analysis</small>
                            <?php if (!empty($permission_display_text)): ?>
                                <small style="color: #73879C; font-size: 12px; margin-left: 8px; cursor: pointer;"
                                       data-toggle="popover"
                                       data-trigger="hover"
                                       data-placement="bottom"
                                       data-content="<?= htmlspecialchars($permission_tooltip_text) ?>">
                                    (權限：<?= htmlspecialchars($permission_display_text) ?>)
                                </small>
                            <?php endif; ?>
                            </h3>
                        </div>
                    </div>

                    <div class="clearfix"></div>

                    <!-- 篩選區塊 -->
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-filter"></i> 查詢條件</h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    </ul>
                                    <div style="float: right; margin-top: 5px; display: flex; align-items: center;">
                                        <span id="closing-date-display" class="closing-date-info" style="display:none; float: none; margin: 0 10px 0 0;"></span>
                                        <button type="button" class="btn btn-warning btn-sm" data-toggle="modal" data-target="#closingDateModal" style="margin-bottom: 0; margin-right: 5px;"><i class="fa fa-cog"></i> 設定結帳日</button>
                                        <button type="button" class="btn btn-info btn-sm" onclick="openSaleTypeModal()" style="margin-bottom: 0; margin-right: 5px;"><i class="fa fa-tags"></i> 出貨性質設定</button>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="performLocalAnalysis()" style="margin-bottom: 0;"><i class="fa fa-search"></i> 異常偵測</button>
                                    </div>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <form method="GET" action="" class="form-inline" id="filterForm">
                                        <div class="form-group">
                                            <label for="start_date">日期範圍：</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>" onchange="this.form.submit()">
                                            <label for="end_date"> 至 </label>
                                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>" onchange="this.form.submit()">
                                            
                                            <div class="checkbox" style="display: inline-block; margin-left: 15px;">
                                                <label>
                                                    <input type="checkbox" id="use_closing_date" name="use_closing" value="1" <?= $use_closing ? 'checked' : '' ?> onchange="this.form.submit()"> 依結帳日計算
                                                </label>
                                            </div>
                                            
                                            <div class="form-group" style="margin-left: 15px;">
                                                <label>出貨性質：</label>
                                                <select name="filter_sale_types[]" id="filter_sale_types" class="form-control" multiple="multiple" style="width: 250px; display: inline-block; vertical-align: middle;">
                                                    <option value="NULL" <?php if (in_array('NULL', $filter_sale_types)) echo 'selected'; ?>>一般產品</option>
                                                    <?php foreach ($sale_types as $st): ?>
                                                        <?php 
                                                            $is_count_label = (isset($st['is_count']) && $st['is_count'] == 0) ? ' (不納入統計)' : '';
                                                        ?>
                                                        <option value="<?= $st['sale_type_id'] ?>" <?php if (in_array(strval($st['sale_type_id']), $filter_sale_types, true)) echo 'selected'; ?>>
                                                            <?= htmlspecialchars($st['sale_type_name']) . $is_count_label ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="btn btn-success btn-sm" onclick="saveDefaultSaleTypes()" title="將目前的篩選儲存為預設值"><i class="fa fa-save"></i> 設為預設</button>
                                            </div>
                                        </div>
                                        <!-- 移除查詢按鈕，改為自動送出 -->
                                        
                                        <div style="margin-top: 5px;">
                                        <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('thisMonth')">本月</button>
                                        <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('lastMonth')">上月</button>
                                        <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('thisYear')">今年</button>
                                        <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('lastYear')">去年</button>
                                        <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('h1')">上半年</button>
                                        <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('h2')">下半年</button>
                                        <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q1')">Q1</button>
                                        <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q2')">Q2</button>
                                        <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q3')">Q3</button>
                                        <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q4')">Q4</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 統計數據磚 -->
                    <div class="row tile_count">
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-truck"></i> 總出貨筆數</span>
                            <div class="count" id="stat-count"><?= number_format($valid_shipment_count) ?></div>
                            <span class="count_bottom">筆</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-cubes"></i> 總出貨數量</span>
                            <div class="count green" id="stat-qty"><?= number_format($total_qty) ?></div>
                            <span class="count_bottom">PCS</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <?php
                                $display_amount = $total_amount;
                                $unit = '萬';
                                if ($total_amount >= 100000000) {
                                    $display_amount = $total_amount / 100000000;
                                    $unit = '億';
                                } else {
                                    $display_amount = $total_amount / 10000;
                                }
                            ?>
                            <span class="count_top" title="計算方式：數量 * 單價"><i class="fa fa-money"></i> 總出貨金額 <span id="amount-unit-title">(<?= $unit ?>)</span> <i class="fa fa-info-circle"></i></span>
                            <div class="count blue" id="stat-amount"><?= number_format($display_amount, 2) ?></div>
                            <span class="count_bottom" id="amount-unit-bottom"><?= $unit ?>TWD</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-user"></i> 出貨客戶數</span>
                            <div class="count" id="stat-client-count"><?= count($client_stats) ?></div>
                            <span class="count_bottom">家</span>
                        </div>
                    </div>

                    <!-- 圖表分析 -->
                    <div class="row">
                        <div class="col-md-8 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-bar-chart"></i> 出貨金額趨勢 (<?= $chart_group_by == 'month' ? '月' : ($chart_group_by == 'week' ? '週' : '日') ?>)</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div id="analysis-chart"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-trophy"></i> 前五大出貨客戶</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>客戶</th>
                                                <th class="text-right">金額(萬)</th>
                                            </tr>
                                        </thead>
                                        <tbody id="top-clients-body">
                                            <?php foreach ($top_clients as $client => $amount): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($client) ?></td>
                                                <td class="text-right">$<?= number_format($amount / 10000, 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 進階分析：出貨性質分佈 & 熱銷產品 -->
                    <div class="row">
                        <div class="col-md-6 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-pie-chart"></i> 出貨性質分佈 (金額佔比)</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div id="sale-type-chart" style="height: 350px;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-star"></i> 十大熱銷產品 (金額)</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content" style="height: 350px; overflow-y: auto;">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th width="50">排名</th>
                                                <th>料號</th>
                                                <th class="text-right">金額(萬)</th>
                                                <th class="text-right">平均出貨數</th>
                                                <th class="text-right">出貨次數</th>
                                            </tr>
                                        </thead>
                                        <tbody id="top-products-tbody">
                                            <?php 
                                            $rank = 1;
                                            foreach ($top_products as $pid => $stats): 
                                                $avg_qty = $stats['count'] > 0 ? $stats['qty'] / $stats['count'] : 0;
                                            ?>
                                            <tr>
                                                <td><?= $rank++ ?></td>
                                                <td><a href="javascript:void(0);" onclick="openProductFiles('<?= htmlspecialchars($pid) ?>')" style="text-decoration: underline; color: #337ab7;"><?= htmlspecialchars($pid) ?></a></td>
                                                <td class="text-right">$<?= number_format($stats['amount'] / 10000, 2) ?></td>
                                                <td class="text-right"><?= number_format($avg_qty, 1) ?></td>
                                                <td class="text-right"><?= number_format($stats['count']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 詳細資料表格 -->
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-list"></i> 出貨明細列表</h2>
                                    <div id="buttons-container" style="display: inline-block; margin-left: 20px;"></div>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    </ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div class="table-responsive">
                                        <!-- 外部篩選容器 -->
                                        <div id="external-filter-container">
                                            <input type="text" id="filter-date" class="form-control input-sm" placeholder="日期">
                                            <input type="text" id="filter-is-no" class="form-control input-sm" placeholder="單號">
                                            <select id="filter-client" class="form-control input-sm" multiple="multiple">
                                                <!-- JS Populated -->
                                            </select>
                                            <select id="filter-sale-type" class="form-control input-sm" multiple="multiple">
                                                <option value="NULL">一般產品</option>
                                                <?php foreach ($sale_types as $st): ?>
                                                <option value="<?= $st['sale_type_id'] ?>"><?= htmlspecialchars($st['sale_type_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" id="filter-product" class="form-control input-sm" placeholder="料號">
                                            <input type="text" id="filter-spec" class="form-control input-sm" placeholder="規格">
                                            <input type="text" id="filter-warehouse" class="form-control input-sm" placeholder="倉庫">
                                            <input type="text" id="filter-note" class="form-control input-sm" placeholder="備註">
                                            <input type="text" id="global-search" class="form-control input-sm" placeholder="全域搜索">
                                            <button type="button" class="btn btn-default btn-sm" id="clear-filters" style="margin-bottom: 0;">取消</button>
                                        </div>
                                        <!-- 批次修改面板 (預設隱藏，勾選後顯示) -->
                                        <div id="batch-update-panel" style="display:none; background: #e8f4f8; padding: 10px; border: 1px solid #bce8f1; margin-bottom: 10px; border-radius: 4px;">
                                            <div class="form-inline">
                                                <i class="fa fa-check-square-o"></i> 已選取 <span id="selected-count" style="font-weight:bold; color:#d9534f;">0</span> 筆資料
                                                &nbsp;&nbsp;
                                                <label>將出貨性質改為：</label>
                                                <select class="form-control input-sm" id="main_batch_sale_type_select" style="max-width: 200px;">
                                                    <option value="NULL">一般產品</option>
                                                    <?php foreach ($sale_types as $st): ?>
                                                    <option value="<?= $st['sale_type_id'] ?>" data-is-count="<?= $st['is_count'] ?>" data-exclude-anomaly="<?= $st['exclude_anomaly'] ?>"><?= htmlspecialchars($st['sale_type_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="btn btn-primary btn-sm" onclick="submitMainBatchUpdate()">執行修改</button>
                                                <?php if ($perm_can_delete): ?>
                                                <button type="button" class="btn btn-danger btn-sm" id="btn-batch-delete" onclick="submitBatchDelete()" style="margin-left:10px;">
                                                    <i class="fa fa-trash"></i> 批次刪除
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-default btn-sm" onclick="clearSelection()">取消選取</button>
                                            </div>
                                        </div>
                                        <table id="shippingTable" class="table table-striped table-bordered dt-responsive nowrap" cellspacing="0" width="100%">
                                            <thead>
                                                <tr>
                                                    <th style="display:none;">ID</th>
                                                    <th width="60"><input type="checkbox" id="check-all" style="vertical-align:text-bottom;" title="選取本頁"> 操作</th>
                                                    <th>出貨日期</th>
                                                    <th>出貨單號</th>
                                                    <th>客戶名稱</th>
                                                    <th>出貨性質</th>
                                                    <th>料號</th>
                                                    <th>規格</th>
                                                    <th>數量</th>
                                                    <th>單價</th>
                                                    <th>總價</th>
                                                    <th>倉庫</th>
                                                    <th>備註</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            <!-- /page content -->

            <!-- footer content -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content -->
        </div>
    </div>

    <!-- 結帳日設定 Modal -->
    <div class="modal fade" id="closingDateModal" tabindex="-1" role="dialog" aria-labelledby="closingDateModalLabel">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="closingDateModalLabel">設定每月結帳日</h4>
                </div>
                <div class="modal-body">
                    <form id="closingDateForm">
                        <div class="form-group">
                            <label>設定模式：</label>
                            <label class="radio-inline">
                                <input type="radio" name="settingMode" value="unified" checked onchange="toggleSettingMode()"> 統一設定
                            </label>
                            <label class="radio-inline">
                                <input type="radio" name="settingMode" value="monthly" onchange="toggleSettingMode()"> 分月設定
                            </label>
                        </div>
                        <hr>
                        
                        <!-- 統一設定區塊 -->
                        <div id="unifiedSetting">
                            <div class="form-group form-inline">
                                <label>每月結帳日：</label>
                                <input type="number" class="form-control" id="unifiedDay" min="1" max="31" placeholder="日期 (1-31)"> 日
                                <div class="checkbox">
                                    <label><input type="checkbox" id="unifiedEnd" onchange="toggleDateInput('unifiedDay', this)"> 月底</label>
                                </div>
                            </div>
                        </div>

                        <!-- 分月設定區塊 -->
                        <div id="monthlySetting" style="display:none;">
                            <div class="month-setting-row">
                                <?php for($i=1; $i<=12; $i++): ?>
                                <div class="month-setting-item form-inline">
                                    <label style="width: 50px; text-align: right; margin-right: 5px;"><?= $i ?>月</label>
                                    <input type="number" class="form-control input-sm month-input" style="width: 80px; display: inline-block;" data-month="<?= $i ?>" min="1" max="31">
                                    <label style="font-weight: normal; margin-right: 15px;">日</label>
                                    <label style="font-weight: normal; cursor: pointer;">
                                        <input type="checkbox" class="month-end-check" data-month="<?= $i ?>" onchange="toggleDateInputByMonth(<?= $i ?>, this)"> 月底
                                    </label>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveClosingRules()">儲存設定</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 分析結果 Modal -->
    <div class="modal fade" id="analysisResultModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-search"></i> 異常偵測報告</h4>
                </div>
                <div class="modal-body" style="padding: 0; max-height: 80vh; display: flex; flex-direction: column;">
                    <div id="analysis-result-header" style="padding: 15px 15px 0 15px; background-color: #fff; z-index: 10; border-bottom: 1px solid #eee;"></div>
                    <div id="analysis-result-body" style="overflow-y: auto; padding: 15px; flex: 1;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-info pull-left" onclick="openSaleTypeModal()" style="display:none;"><i class="fa fa-cog"></i> 設定標籤</button>
                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 編輯出貨資料 Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">修改出貨資料</h4>
                </div>
                <div class="modal-body">
                    <form id="editForm">
                        <input type="hidden" id="edit_is_id" name="is_id">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>出貨日期</label>
                                <input type="date" class="form-control" id="edit_order_date" name="Order_date" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>出貨單號</label>
                                <input type="text" class="form-control" id="edit_is_number" name="IS_number">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>客戶名稱</label>
                                <input type="text" class="form-control" id="edit_client_name" name="Client_name">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>出貨性質</label>
                                <select class="form-control" id="edit_sale_type" name="sale_type">
                                    <option value="NULL">一般產品</option>
                                    <?php foreach ($sale_types as $st): ?>
                                    <option value="<?= $st['sale_type_id'] ?>" data-is-count="<?= $st['is_count'] ?>" data-exclude-anomaly="<?= $st['exclude_anomaly'] ?>"><?= htmlspecialchars($st['sale_type_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>料號</label>
                                <input type="text" class="form-control" id="edit_product_id" name="Product_id">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>規格</label>
                                <input type="text" class="form-control" id="edit_specification" name="Specification">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>數量</label>
                                <input type="number" step="any" class="form-control" id="edit_qty" name="Qty">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>單價</label>
                                <input type="number" step="any" class="form-control" id="edit_unit_price" name="Unit_price">
                            </div>
                            <div class="col-md-12 form-group">
                                <label>備註</label>
                                <textarea class="form-control" id="edit_note" name="Note" rows="2"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveEdit()">儲存修改</button>
                </div>
            </div>
        </div>
    </div>

    <!-- BOM 圖檔 Modal -->
    <div class="modal fade" id="bomFileModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" style="width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">產品圖檔: <span id="modal-product-title"></span></h4>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-3"><div class="list-group" id="bom-file-list"></div></div>
                        <div class="col-md-9" id="bom-file-viewer" style="min-height: 500px; text-align: center; background: #eee;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 出貨性質管理 Modal -->
    <div class="modal fade" id="saleTypeModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document" style="width: 85%;">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">出貨性質設定</h4>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- 左側：編輯表單 -->
                        <div class="col-md-2">
                            <div class="panel panel-default">
                                <div class="panel-heading">新增 / 修改</div>
                                <div class="panel-body">
                                    <form id="saleTypeForm">
                                        <input type="hidden" id="st_id" name="sale_type_id">
                                        <input type="hidden" name="action" value="save">
                                        <div class="form-group">
                                            <label>性質名稱 *</label>
                                            <input type="text" class="form-control" id="st_name" name="sale_type_name" required>
                                        </div>
                                        <div class="form-group">
                                            <label>說明</label>
                                            <input type="text" class="form-control" id="st_desc" name="description">
                                        </div>
                                        <div class="form-group">
                                            <label>排序 (數字越小越前)</label>
                                            <input type="number" class="form-control" id="st_sort" name="sort_order" value="0">
                                        </div>
                                        <div class="checkbox">
                                            <label><input type="checkbox" id="st_count" name="is_count" value="1" checked> 納入業績統計</label>
                                        </div>
                                        <div class="checkbox">
                                            <label><input type="checkbox" id="st_exclude_anomaly" name="exclude_anomaly" value="1"> 排除異常檢測</label>
                                        </div>
                                        <div class="checkbox">
                                            <label><input type="checkbox" id="st_active" name="is_active" value="1" checked> 啟用</label>
                                        </div>
                                        <hr>
                                        <div class="form-group">
                                            <label>標籤顏色</label>
                                            <input type="color" class="form-control" id="st_color" style="padding: 0; height: 30px;">
                                        </div>
                                        <div class="form-group">
                                            <label>關鍵字 (逗號分隔)</label>
                                            <textarea class="form-control" id="st_keywords" rows="3" placeholder="例如: NG, 不良, 退貨"></textarea>
                                        </div>
                                        <hr>
                                        <button type="submit" class="btn btn-primary btn-block">儲存</button>
                                        <button type="button" class="btn btn-default btn-block" onclick="resetSaleTypeForm()">重置表單</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- 右側：列表 -->
                        <div class="col-md-10">
                            <table class="table table-bordered table-striped" id="saleTypeTable">
                                <thead>
                                    <tr>
                                        <th>名稱</th>
                                        <th>說明</th>
                                        <th>統計</th>
                                        <th>排除異常</th>
                                        <th>排序</th>
                                        <th>顏色</th>
                                        <th>關鍵字</th>
                                        <th>狀態</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast 通知容器 -->
    <div id="sa-toast-container"></div>

    <!-- 綁定料號 / 客戶 Modal -->
    <div class="modal fade" id="bindModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background:#2c3e50; color:#fff; border-radius:4px 4px 0 0;">
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff; opacity:0.8;"><span>&times;</span></button>
                    <h4 class="modal-title">
                        <i class="fa fa-link"></i> 綁定料號 &amp; 客戶
                        <small style="color:#bdc3c7; font-size:12px; margin-left:8px;">
                            <i class="fa fa-info-circle"></i> 選擇料號後自動帶入客戶，亦可單獨修改客戶
                        </small>
                    </h4>
                </div>
                <div class="modal-body" style="padding:20px;">
                    <input type="hidden" id="bind_is_id">
                    <div class="row">
                        <!-- 左：料號搜尋 -->
                        <div class="col-md-6">
                            <div class="bind-section-title"><i class="fa fa-cube"></i> 料號（d_setting）</div>
                            <div class="form-group" style="margin-bottom:6px;">
                                <span class="text-muted" style="font-size:12px;">目前綁定：</span>
                                <span id="bind_current_d" class="bind-current-badge" style="background:#ecf0f1; color:#555;">未綁定</span>
                            </div>
                            <div class="input-group input-group-sm" style="margin-bottom:6px;">
                                <input type="text" class="form-control" id="bind_d_kw" placeholder="輸入料號關鍵字...">
                                <span class="input-group-btn">
                                    <button class="btn btn-default" type="button" id="btn_clear_d" title="清除料號綁定">
                                        <i class="fa fa-times"></i>
                                    </button>
                                </span>
                            </div>
                            <div id="bind_d_loading" style="display:none; text-align:center; padding:8px; color:#999; font-size:12px;">
                                <i class="fa fa-spinner fa-spin"></i> 搜尋中...
                            </div>
                            <div id="bind_d_results" style="display:none; max-height:220px; overflow-y:auto; border:1px solid #ddd; border-radius:4px;">
                                <table class="table table-condensed table-hover bind-result-table">
                                    <thead style="background:#f5f5f5;">
                                        <tr><th>料號</th><th>規格</th><th>客戶</th><th></th></tr>
                                    </thead>
                                    <tbody id="bind_d_tbody"></tbody>
                                </table>
                            </div>
                            <div style="margin-top:8px;">
                                <span class="text-muted" style="font-size:12px;">已選取：</span>
                                <strong id="bind_d_preview" style="color:#2980b9;">（未選取）</strong>
                                <input type="hidden" id="bind_d_id">
                                <input type="hidden" id="bind_d_display">
                            </div>
                        </div>
                        <!-- 右：客戶搜尋 -->
                        <div class="col-md-6">
                            <div class="bind-section-title"><i class="fa fa-user"></i> 客戶</div>
                            <div class="form-group" style="margin-bottom:6px;">
                                <span class="text-muted" style="font-size:12px;">目前客戶：</span>
                                <span id="bind_current_c" class="bind-current-badge" style="background:#ecf0f1; color:#555;">未設定</span>
                            </div>
                            <div class="input-group input-group-sm" style="margin-bottom:6px;">
                                <input type="text" class="form-control" id="bind_c_kw" placeholder="輸入客戶名稱關鍵字...">
                                <span class="input-group-btn">
                                    <button class="btn btn-default" type="button" id="btn_clear_c" title="清除客戶綁定">
                                        <i class="fa fa-times"></i>
                                    </button>
                                </span>
                            </div>
                            <div id="bind_c_loading" style="display:none; text-align:center; padding:8px; color:#999; font-size:12px;">
                                <i class="fa fa-spinner fa-spin"></i> 搜尋中...
                            </div>
                            <div id="bind_c_results" style="display:none; max-height:220px; overflow-y:auto; border:1px solid #ddd; border-radius:4px;">
                                <table class="table table-condensed table-hover bind-result-table">
                                    <thead style="background:#f5f5f5;">
                                        <tr><th>客戶ID</th><th>客戶名稱</th><th></th></tr>
                                    </thead>
                                    <tbody id="bind_c_tbody"></tbody>
                                </table>
                            </div>
                            <div style="margin-top:8px;">
                                <span class="text-muted" style="font-size:12px;">已選取：</span>
                                <strong id="bind_c_preview" style="color:#27ae60;">（未選取）</strong>
                                <input type="hidden" id="bind_c_id">
                                <input type="hidden" id="bind_c_name">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveBindRecord()">
                        <i class="fa fa-save"></i> 儲存
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- BOM 設定 Modal -->
    <div class="modal fade" id="bomModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document" style="width:92%;">
            <div class="modal-content">
                <div class="modal-header" style="background:#2c3e50; color:#fff; border-radius:4px 4px 0 0; padding:10px 15px;">
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff; opacity:0.8;"><span>&times;</span></button>
                    <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-cubes"></i> BOM 設定</h4>
                </div>
                <div class="modal-body" style="padding:10px 15px;">
                    <input type="hidden" id="bom_is_id">
                    <input type="hidden" id="bom_order_id">
                    <!-- 本筆出貨單資訊 -->
                    <div class="well well-sm" id="bom_is_info_panel" style="margin-bottom:10px; font-size:12px; background:#f8f9fa;">
                        <i class="fa fa-spinner fa-spin"></i> 載入中...
                    </div>
                    <div class="row">
                        <!-- 左：同料號 BOM 列表 -->
                        <div class="col-md-4">
                            <div class="panel panel-default" style="margin-bottom:0;">
                                <div class="panel-heading" style="padding:6px 10px; font-size:12px; font-weight:600;">
                                    <i class="fa fa-list"></i> 同料號 BOM 列表
                                    <small class="text-muted pull-right" style="font-size:10px; line-height:20px;">點擊選取</small>
                                </div>
                                <div class="panel-body" style="padding:0; max-height:450px; overflow-y:auto;">
                                    <table class="table table-hover table-condensed" style="margin:0; font-size:12px;">
                                        <thead style="background:#f5f5f5; position:sticky; top:0;">
                                            <tr><th>BOM</th><th>數量</th><th>狀態</th></tr>
                                        </thead>
                                        <tbody id="bom_list_tbody">
                                            <tr><td colspan="3" class="text-center text-muted" style="padding:16px;"><i class="fa fa-spinner fa-spin"></i></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <!-- 右：詳情 -->
                        <div class="col-md-8">
                            <div id="bom_detail_panel">
                                <div class="text-muted text-center" style="padding:40px; font-size:13px;">
                                    <i class="fa fa-arrow-left"></i> 點擊左側 BOM 查看詳情
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    
    <!-- DataTables -->
    <script src="../../resource/js/jquery.dataTables.min.js"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js"></script>
    <script src="../../resource/js/dataTables.buttons.min.js"></script>
    <script src="../../resource/js/buttons.flash.min.js"></script>
    <script src="../../resource/js/buttons.html5.min.js"></script>
    <script src="../../resource/js/buttons.print.min.js"></script>
    <script src="../../resource/js/jszip.min.js"></script>
    <script src="../../resource/js/pdfmake.min.js"></script>
    <script src="../../resource/js/vfs_fonts.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>

    <!-- Highcharts -->
    <script src="../../code/highcharts.js"></script>
    <script src="../../code/modules/exporting.js"></script>
    <script src="../../code/modules/export-data.js"></script>
    <script src="../../code/modules/accessibility.js"></script>

    <script>
        // 結帳日規則 (從後端載入)
        var closingRules = <?= $closing_rule_js ?>;
        var shippingData = <?= $shipping_data_json ?>;
        var chartGroupBy = '<?= $chart_group_by ?>';
        var useClosing = <?= $use_closing ? 'true' : 'false' ?>;
        var lastChecked = null; // 用於 Shift+Click 多選
        var saleTypeConfig = <?= $sale_type_config_json ?>;
        var allSaleTypes = []; // 儲存所有出貨性質
        var saleTypeChanged = false;
        var currentChartFilter = null; // 用於圖表點擊篩選
        var canDelete = <?= $perm_can_delete ? 'true' : 'false' ?>;
        var canUpdate = <?= $perm_can_update ? 'true' : 'false' ?>;

        // 初始化
        $(document).ready(function() {
            console.log("模擬排行榜統計 SQL (可於資料庫執行驗證):\n", <?= json_encode($debug_sql) ?>);
            console.log("出貨性質分佈 SQL (可於資料庫執行驗證):\n", <?= json_encode($pie_debug_sql) ?>);
            renderClosingDateInfo();
            loadSaleTypes(); // 預先載入出貨性質
            // 如果有規則，預設勾選"依結帳日計算" (可選)
            if (!$.isEmptyObject(closingRules)) {
                // $('#use_closing_date').prop('checked', true); 
            }

            // 初始化 Select2
            $('#filter_sale_types').select2({
                placeholder: "請選擇出貨性質",
                closeOnSelect: false,
                allowClear: true
            }).on('change', function() {
                saleTypeChanged = true;
            }).on('select2:close', function() {
                if (saleTypeChanged) {
                    $('#filterForm').submit();
                }
            });
        });

        // 切換設定模式 (統一/分月)
        function toggleSettingMode() {
            var mode = $('input[name="settingMode"]:checked').val();
            if (mode === 'unified') {
                $('#unifiedSetting').show();
                $('#monthlySetting').hide();
            } else {
                $('#unifiedSetting').hide();
                $('#monthlySetting').show();
            }
        }

        // 切換日期輸入框狀態 (當勾選月底時禁用輸入框)
        function toggleDateInput(inputId, checkbox) {
            $('#' + inputId).prop('disabled', checkbox.checked);
            if (checkbox.checked) $('#' + inputId).val('');
        }

        function toggleDateInputByMonth(month, checkbox) {
            $('.month-input[data-month="'+month+'"]').prop('disabled', checkbox.checked);
            if (checkbox.checked) $('.month-input[data-month="'+month+'"]').val('');
        }

        // 儲存結帳日設定
        function saveClosingRules() {
            var mode = $('input[name="settingMode"]:checked').val();
            var rules = {};

            if (mode === 'unified') {
                var isEnd = $('#unifiedEnd').is(':checked');
                var day = $('#unifiedDay').val();
                if (!isEnd && (!day || day < 1 || day > 31)) {
                    alert('請輸入有效的日期 (1-31)');
                    return;
                }
                // 產生 1-12 月的規則
                for (var i = 1; i <= 12; i++) {
                    rules[i] = isEnd ? 'end' : day;
                }
            } else {
                var valid = true;
                $('.month-input').each(function() {
                    var m = $(this).data('month');
                    var isEnd = $('.month-end-check[data-month="'+m+'"]').is(':checked');
                    var day = $(this).val();
                    if (!isEnd && (!day || day < 1 || day > 31)) {
                        valid = false;
                        return false;
                    }
                    rules[m] = isEnd ? 'end' : day;
                });
                if (!valid) {
                    alert('請檢查所有月份的日期設定');
                    return;
                }
            }

            // AJAX 儲存
            $.post('../../src/store/save_closing_date_rules.php', { rules: JSON.stringify(rules) }, function(res) {
                var data = JSON.parse(res);
                if (data.success) {
                    alert('設定已儲存');
                    closingRules = rules;
                    $('#closingDateModal').modal('hide');
                    renderClosingDateInfo();
                } else {
                    alert('儲存失敗: ' + data.message);
                }
            });
        }

        // 顯示結帳日資訊
        function renderClosingDateInfo() {
            if ($.isEmptyObject(closingRules)) return;
            
            var display = $('#closing-date-display');
            var text = "";
            
            // 檢查是否全部相同
            var first = closingRules[1];
            var allSame = true;
            for (var i = 2; i <= 12; i++) {
                if (closingRules[i] != first) {
                    allSame = false;
                    break;
                }
            }

            if (allSame) {
                text = "每月 " + (first === 'end' ? '月底' : first + '日') + " 結帳";
            } else {
                // 簡易顯示：列出差異或範圍 (這裡做簡化處理，顯示當月規則)
                var currentMonth = new Date().getMonth() + 1;
                var rule = closingRules[currentMonth];
                text = "本月(" + currentMonth + "月) " + (rule === 'end' ? '月底' : rule + '日') + " 結帳 (分月設定)";
            }
            
            display.text(text).show();
        }

        // 快速日期設定
        function setQuickDate(type) {
            var useClosing = $('#use_closing_date').is(':checked');
            var now = new Date();
            var start, end;

            if (useClosing && !$.isEmptyObject(closingRules)) {
                // 依結帳日計算
                // 邏輯：若本月結帳日是 25，今天 10號 -> 本月帳款週期是 上月26 ~ 本月25
                // 若今天 28號 -> 本月帳款週期是 本月26 ~ 下月25 (視為下月帳款，但通常"本月"指當前月份的帳)
                // 這裡定義： "本月" = 結束於本月結帳日的週期
                
                var year = now.getFullYear();
                var month = now.getMonth() + 1; // 1-12

                if (type === 'thisMonth') {
                    end = getClosingDate(year, month);
                    start = getClosingDate(year, month - 1); // 上個月結帳日
                    start.setDate(start.getDate() + 1); // 上個月結帳日 + 1天
                } else if (type === 'lastMonth') {
                    end = getClosingDate(year, month - 1);
                    start = getClosingDate(year, month - 2);
                    start.setDate(start.getDate() + 1);
                } else if (type === 'thisYear') {
                    // 今年：去年12月結帳日+1 ~ 今年12月結帳日
                    end = getClosingDate(year, 12);
                    start = getClosingDate(year - 1, 12);
                    start.setDate(start.getDate() + 1);
                } else if (type === 'lastYear') {
                    end = getClosingDate(year - 1, 12);
                    start = getClosingDate(year - 2, 12);
                    start.setDate(start.getDate() + 1);
                } else if (type === 'h1') { // 上半年 (1-6月)
                    end = getClosingDate(year, 6);
                    start = getClosingDate(year - 1, 12);
                    start.setDate(start.getDate() + 1);
                } else if (type === 'h2') { // 下半年 (7-12月)
                    end = getClosingDate(year, 12);
                    start = getClosingDate(year, 6);
                    start.setDate(start.getDate() + 1);
                } else if (type.startsWith('q')) { // Q1-Q4
                    var q = parseInt(type.substring(1));
                    var endMonth = q * 3;
                    var startMonth = endMonth - 3; // 0, 3, 6, 9
                    
                    end = getClosingDate(year, endMonth);
                    start = getClosingDate(year, startMonth); // 上一季的結束日
                    start.setDate(start.getDate() + 1); // +1天變成本季開始日
                }
            } else {
                // 一般日曆月
                if (type === 'thisMonth') {
                    start = new Date(now.getFullYear(), now.getMonth(), 1);
                    end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                } else if (type === 'lastMonth') {
                    start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                    end = new Date(now.getFullYear(), now.getMonth(), 0);
                } else if (type === 'thisYear') {
                    start = new Date(now.getFullYear(), 0, 1);
                    end = new Date(now.getFullYear(), 11, 31);
                } else if (type === 'lastYear') {
                    start = new Date(now.getFullYear() - 1, 0, 1);
                    end = new Date(now.getFullYear() - 1, 11, 31);
                } else if (type === 'h1') {
                    start = new Date(now.getFullYear(), 0, 1);
                    end = new Date(now.getFullYear(), 5, 30);
                } else if (type === 'h2') {
                    start = new Date(now.getFullYear(), 6, 1);
                    end = new Date(now.getFullYear(), 11, 31);
                } else if (type.startsWith('q')) {
                    var q = parseInt(type.substring(1));
                    var startMonth = (q - 1) * 3;
                    var endMonth = startMonth + 2;
                    start = new Date(now.getFullYear(), startMonth, 1);
                    // 取得該季最後一個月的最後一天
                    end = new Date(now.getFullYear(), endMonth + 1, 0);
                }
            }

            // 格式化 YYYY-MM-DD
            function fmt(d) {
                var m = '' + (d.getMonth() + 1), dy = '' + d.getDate();
                if (m.length < 2) m = '0' + m;
                if (dy.length < 2) dy = '0' + dy;
                return [d.getFullYear(), m, dy].join('-');
            }

            $('#start_date').val(fmt(start));
            $('#end_date').val(fmt(end));
            
            // 自動送出表單
            document.getElementById('filterForm').submit();
        }

        // 取得某年某月的結帳日期物件
        function getClosingDate(year, month) {
            // 處理月份溢位 (例如 month=0 -> 去年12月, month=13 -> 明年1月)
            var d = new Date(year, month - 1, 1); // 設定為該月1號以修正年份月份
            year = d.getFullYear();
            month = d.getMonth() + 1;
            
            // 特殊處理：如果 month 為 0 (代表去年12月)，在上面 Date 建構子會自動處理
            // 但為了取 closingRules，我們需要正確的月份 1-12
            // 這裡的 month 已經是修正後的 1-12

            var rule = closingRules[month] || 'end'; // 預設月底
            var lastDay = new Date(year, month, 0).getDate();
            var day;

            if (rule === 'end') {
                day = lastDay;
            } else {
                day = parseInt(rule);
                if (day > lastDay) day = lastDay; // 若設定31但該月只有30，取30
            }
            return new Date(year, month - 1, day);
        }

        // 自定義日期搜尋函數
        $.fn.dataTable.ext.search.push(
            function(settings, data, dataIndex) {
                var input = $('#filter-date').val();
                if (!input) return true;

                var val = input.trim();
                var op = '=';
                
                // 判斷運算子
                if (val.startsWith('>')) { op = '>'; val = val.substring(1).trim(); }
                else if (val.startsWith('<')) { op = '<'; val = val.substring(1).trim(); }
                else if (val.startsWith('=')) { op = '='; val = val.substring(1).trim(); }

                // 解析輸入日期 (支援 m/d 或 y/m/d)
                var parts = val.split(/[\/\-]/); // 允許 / 或 -
                var year, month, day;
                var now = new Date();
                
                if (parts.length === 2) {
                    // 只有月/日，補上今年
                    year = now.getFullYear();
                    month = parseInt(parts[0], 10);
                    day = parseInt(parts[1], 10);
                } else if (parts.length === 3) {
                    // 年/月/日
                    year = parseInt(parts[0], 10);
                    // 處理 2 位數年份 (如 25 -> 2025)
                    if (year < 100) year += 2000;
                    month = parseInt(parts[1], 10);
                    day = parseInt(parts[2], 10);
                } else {
                    return true; // 格式不符則不篩選
                }

                if (isNaN(year) || isNaN(month) || isNaN(day)) return true;

                var filterDate = new Date(year, month - 1, day);
                filterDate.setHours(0,0,0,0);

                // 解析表格日期 (假設格式為 YYYY-MM-DD)
                var rowDateStr = data[2]; // 第三欄 (因為第一欄是隱藏的 ID, 第二欄是操作)
                var rowDate = new Date(rowDateStr);
                rowDate.setHours(0,0,0,0);

                if (op === '>') return rowDate > filterDate;
                if (op === '<') return rowDate < filterDate;
                return rowDate.getTime() === filterDate.getTime();
            }
        );

        // 數值格式化函數
        function numberFormat(number, decimals, dec_point, thousands_sep) {
            number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
            var n = !isFinite(+number) ? 0 : +number,
                prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
                sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
                dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
                s = '',
                toFixedFix = function (n, prec) {
                    var k = Math.pow(10, prec);
                    return '' + Math.round(n * k) / k;
                };
            s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
            if (s[0].length > 3) {
                s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
            }
            if ((s[1] || '').length < prec) {
                s[1] = s[1] || '';
                s[1] += new Array(prec - s[1].length + 1).join('0');
            }
            return s.join(dec);
        }

        // 取得日期分組 Key (日/週/月)
        function getDateKey(dateStr) {
            var parts = dateStr.split('-');
            var d = new Date(parts[0], parts[1]-1, parts[2]);
            
            if (chartGroupBy === 'month') {
                if (useClosing && !$.isEmptyObject(closingRules)) {
                    var y = d.getFullYear();
                    var m = d.getMonth() + 1;
                    var day = d.getDate();
                    var rule = closingRules[m] || 'end';
                    var cutoff = 31;
                    if (rule !== 'end') cutoff = parseInt(rule);
                    
                    if (day > cutoff) {
                        if (m === 12) { y++; m = 1; }
                        else { m++; }
                    }
                    return y + '-' + (m < 10 ? '0' + m : m);
                } else {
                    var m = d.getMonth() + 1;
                    return d.getFullYear() + '-' + (m < 10 ? '0' + m : m);
                }
            } else if (chartGroupBy === 'week') {
                var day = d.getDay(),
                    diff = d.getDate() - day + (day == 0 ? -6 : 1); 
                var monday = new Date(d.setDate(diff));
                var mm = monday.getMonth() + 1;
                var dd = monday.getDate();
                return monday.getFullYear() + '/' + (mm < 10 ? '0' + mm : mm) + '/' + (dd < 10 ? '0' + dd : dd);
            } else {
                return dateStr;
            }
        }

        // 更新統計數據與圖表
        function updateStatistics() {
            var table = $('#shippingTable').DataTable();
            var data = table.rows({ search: 'applied' }).data().toArray();
            
            var totalQty = 0;
            var totalAmount = 0;
            var validCount = 0;
            var clientStats = {};
            var chartStats = {};
            var saleTypeStats = {};
            var productStats = {}; // 新增：產品統計
            
            data.forEach(function(row) {
                if (row.is_count == 0) return;

                var qty = parseFloat(row.Qty) || 0;
                var price = parseFloat(row.Unit_price) || 0;
                var amount = qty * price;
                
                if (price > 0) validCount++;
                totalQty += qty;
                totalAmount += amount;
                
                var client = row.Client_name || '未知客戶';
                if (!clientStats[client]) clientStats[client] = 0;
                clientStats[client] += amount;
                
                var key = getDateKey(row.Order_date);
                if (!chartStats[key]) chartStats[key] = 0;
                chartStats[key] += (amount / 10000);

                var stName = row.sale_type_name || '一般產品';
                if (!saleTypeStats[stName]) saleTypeStats[stName] = 0;
                saleTypeStats[stName] += amount;

                // 新增：統計熱銷產品
                var pid = row.Product_id || '未知料號';
                if (pid.trim() === '' || pid === '0' || pid === '1') pid = '未知料號';
                if (!productStats[pid]) {
                    productStats[pid] = { amount: 0, qty: 0, count: 0 };
                }
                productStats[pid].amount += amount;
                productStats[pid].qty += qty;
                productStats[pid].count++;
            });
            
            $('#stat-count').text(numberFormat(validCount));
            $('#stat-qty').text(numberFormat(totalQty));
            
            var displayAmount = totalAmount;
            var unit = '萬';
            if (totalAmount >= 100000000) {
                displayAmount = totalAmount / 100000000;
                unit = '億';
            } else {
                displayAmount = totalAmount / 10000;
            }
            $('#stat-amount').text(numberFormat(displayAmount, 2));
            $('#amount-unit-title').text('(' + unit + ')');
            $('#amount-unit-bottom').text(unit + 'TWD');

            $('#stat-client-count').text(Object.keys(clientStats).length);
            
            var sortedClients = Object.keys(clientStats).map(function(key) { return [key, clientStats[key]]; });
            sortedClients.sort(function(a, b) { return b[1] - a[1]; });
            var topClientsHtml = '';
            sortedClients.slice(0, 5).forEach(function(item) {
                topClientsHtml += '<tr><td>' + item[0].replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;") + '</td><td class="text-right">$' + numberFormat(item[1] / 10000, 2) + '</td></tr>';
            });
            $('#top-clients-body').html(topClientsHtml);
            
            // 新增：更新十大熱銷產品
            var sortedProducts = Object.keys(productStats).map(function(key) {
                return { pid: key, ...productStats[key] };
            });
            sortedProducts.sort(function(a, b) { return b.amount - a.amount; });
            var topProductsHtml = '';
            var rank = 1;
            sortedProducts.slice(0, 10).forEach(function(item) {
                var avgQty = item.count > 0 ? item.qty / item.count : 0;
                // 簡單跳脫
                var safePid = item.pid.replace(/'/g, "\\'");
                var displayPid = item.pid.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                
                topProductsHtml += '<tr>' +
                    '<td>' + (rank++) + '</td>' +
                    '<td><a href="javascript:void(0);" onclick="openProductFiles(\'' + safePid + '\')" style="text-decoration: underline; color: #337ab7;">' + displayPid + '</a></td>' +
                    '<td class="text-right">$' + numberFormat(item.amount / 10000, 2) + '</td>' +
                    '<td class="text-right">' + numberFormat(avgQty, 1) + '</td>' +
                    '<td class="text-right">' + numberFormat(item.count) + '</td>' +
                    '</tr>';
            });
            $('#top-products-tbody').html(topProductsHtml);

            // 更新長條圖 (金額趨勢)
            var barChart = Highcharts.charts.find(function(c) { return c && c.renderTo.id === 'analysis-chart'; });
            if (barChart) {
                var categories = Object.keys(chartStats).sort();
                var seriesData = categories.map(function(k) { return chartStats[k]; });
                barChart.xAxis[0].setCategories(categories);
                barChart.series[0].setData(seriesData);
            }

            // 更新圓餅圖 (出貨性質分佈) - 取消註解以啟用連動
            var pieChart = Highcharts.charts.find(function(c) { return c && c.renderTo.id === 'sale-type-chart'; });
            if (pieChart) {
                var pieData = [];
                for (var name in saleTypeStats) {
                    pieData.push({
                        name: name,
                        y: parseFloat((saleTypeStats[name] / 10000).toFixed(2))
                    });
                }
                pieChart.series[0].setData(pieData);
            }
        }

        $(document).ready(function() {
            // 初始化 DataTable
            
            // 填充客戶篩選下拉選單
            var uniqueClients = [...new Set(shippingData.map(item => item.Client_name || ''))].filter(x => x).sort();
            var clientSelect = $('#filter-client');
            uniqueClients.forEach(function(client) {
                clientSelect.append(new Option(client, client));
            });
            
            // 初始化客戶 Select2
            $('#filter-client').select2({
                placeholder: "客戶 (多選)",
                allowClear: true,
                width: '150px'
            });

            var table = $('#shippingTable').DataTable({
                dom: 'Brtip',
                data: shippingData,
                deferRender: true, // 關鍵優化：只渲染當前頁面的 DOM
                createdRow: function(row, data, dataIndex) {
                    $(row).attr('data-is-id', data.IS_id); // 為了 AI 分析功能保留 ID
                },
                columns: [
                    { data: 'IS_id', visible: false },
                    { 
                        data: null, 
                        orderable: false,
                        render: function(data, type, row) {
                            // 加入 checkbox 與編輯按鈕並排
                            return '<input type="checkbox" class="row-check" value="' + row.IS_id + '" style="margin-right:5px; vertical-align:middle;"> ' +
                                   '<button type="button" class="btn btn-xs btn-info btn-edit-is"><i class="fa fa-pencil"></i></button>';
                        }
                    },
                    { data: 'Order_date' },
                    {
                        data: 'IS_number',
                        render: function(data, type, row) {
                            if (type !== 'display') return data || '';
                            var safe = $('<span>').text(data || '').html();
                            var hasBom = row.bom_list && row.bom_list !== '';
                            var bomIcon = hasBom
                                ? ' <i class="fa fa-chain" style="color:#28a745;" title="已關聯BOM"></i>'
                                : ' <i class="fa fa-chain-broken" style="color:#dc3545;" title="無BOM關聯"></i>';
                            return '<a class="btn-open-bom" href="javascript:void(0);" data-is-id="' + row.IS_id + '" style="color:#2980b9; font-weight:500;">'
                                + safe + '</a>' + bomIcon;
                        }
                    },
                    { data: 'Client_name', render: $.fn.dataTable.render.text() },
                    { 
                        data: 'sale_type_name', 
                        render: function(data, type, row) {
                            var val = data ? data : '一般產品';
                            if (type === 'display') {
                                var html = val.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                                if (row.is_count == 0) {
                                    html += ' <span class="label label-warning" style="font-size: 85%;">不納入統計</span>';
                                }
                                return html;
                            }
                            if (type === 'filter') {
                                return val + (row.is_count == 0 ? ' 不納入統計' : '');
                            }
                            return val;
                        },
                        createdCell: function (td, cellData, rowData, row, col) {
                            var val = cellData ? cellData : '一般產品';
                            if (val === '一般產品') {
                                $(td).css('background-color', '');
                                $(td).css('color', '');
                                return;
                            }
                            // 使用設定的顏色，若無則使用預設
                            var stId = rowData.sale_type;
                            if (stId && saleTypeConfig[stId] && saleTypeConfig[stId].color) {
                                $(td).css('background-color', saleTypeConfig[stId].color);
                                $(td).css('color', '#ffffff');
                            } else {
                                $(td).css('background-color', '');
                                $(td).css('color', '');
                            }
                        }
                    },
                    {
                        data: 'Product_id',
                        render: function(data, type, row) {
                            if (type !== 'display') return data || '';
                            var safe = $('<span>').text(data || '').html();
                            if (!data) {
                                return '<i class="fa fa-chain-broken btn-open-bind" style="color:#dc3545; cursor:pointer;" data-is-id="' + row.IS_id + '" title="未綁定料號"></i>';
                            }
                            var pidLink = '<a href="javascript:void(0);" class="btn-pid-files" data-pid="' + safe + '" style="text-decoration:underline; color:#337ab7;">' + safe + '</a>';
                            var hasDsetting = row.d_setting_id && row.d_setting_id !== '';
                            var bindIcon = hasDsetting
                                ? ' <i class="fa fa-chain btn-open-bind" style="color:#28a745; cursor:pointer;" data-is-id="' + row.IS_id + '" title="已綁定料號"></i>'
                                : ' <i class="fa fa-chain-broken btn-open-bind" style="color:#dc3545; cursor:pointer;" data-is-id="' + row.IS_id + '" title="未綁定料號"></i>';
                            return pidLink + bindIcon;
                        }
                    },
                    { data: 'Specification', render: $.fn.dataTable.render.text() },
                    { data: 'Qty', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                    { data: 'Unit_price', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                    { data: null, className: 'text-right', render: function(data, type, row) { return $.fn.dataTable.render.number(',', '.', 0).display(row.Qty * row.Unit_price); } },
                    { data: 'Warehouse', render: $.fn.dataTable.render.text() },
                    { data: 'Note', render: $.fn.dataTable.render.text() }
                ],
                buttons: [
                    { extend: 'copy', className: 'btn btn-default btn-sm' },
                    { extend: 'csv', className: 'btn btn-default btn-sm' },
                    { extend: 'excel', className: 'btn btn-default btn-sm', title: '出貨紀錄' },
                    { extend: 'print', className: 'btn btn-default btn-sm' }
                ],
                pageLength: 20,
                orderCellsTop: true,
                order: [[0, 'desc']], // 預設依日期降序
                language: {
                    "url": "//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json"
                },
                // 處理 checkbox 狀態回填 (確保換頁後選取狀態正確)
                rowCallback: function(row, data) {
                    if (selectedISIds.has(data.IS_id.toString())) {
                        $(row).find('.row-check').prop('checked', true);
                        $(row).addClass('selected highlight-row');
                    } else {
                        $(row).find('.row-check').prop('checked', false);
                        $(row).removeClass('selected highlight-row');
                    }
                }
            });

            // 綁定編輯按鈕事件 (使用事件委派，提升效能)
            $('#shippingTable tbody').on('click', '.btn-edit-is', function() {
                var tr = $(this).closest('tr');
                var row = table.row(tr).data();
                openEditModal(row);
            });

            // 出貨單號點擊 → 開啟 BOM Modal
            $('#shippingTable tbody').on('click', '.btn-open-bom', function() {
                var tr  = $(this).closest('tr');
                var row = table.row(tr).data();
                openBomModal(row);
            });

            // 料號鏈圖示點擊 → 開啟料號/客戶綁定 Modal
            $('#shippingTable tbody').on('click', '.btn-open-bind', function() {
                var tr  = $(this).closest('tr');
                var row = table.row(tr).data();
                openBindModal(row);
            });

            // 料號文字點擊 → 開啟圖檔
            $('#shippingTable tbody').on('click', '.btn-pid-files', function() {
                openProductFiles($(this).data('pid'));
            });

            // 報告點擊事件
            $('#analysisResultModal').on('click', 'li[data-is-id]', function() {
                var isId = $(this).data('is-id');
                if (!isId) return;

                // 清除任何現有的篩選，以便找到行
                table.search('').columns().search('').draw(false);

                // 透過隱藏的 ID 欄位 (index 0) 尋找該資料列的索引
                var indexes = table.rows().eq(0).filter(function (rowIdx) {
                    return table.cell(rowIdx, 0).data() == isId;
                });

                if (indexes.length > 0) {
                    var rowIndex = indexes[0];
                    
                    // 計算該列在目前排序下的頁碼並跳轉
                    var pageInfo = table.page.info();
                    var currentOrder = table.rows({order: 'current'}).indexes();
                    var position = currentOrder.indexOf(rowIndex);
                    var page = Math.floor(position / pageInfo.length);
                    
                    table.page(page).draw(false);

                    // 取得 DOM 節點並執行滾動與高亮
                    var rowNode = table.row(rowIndex).node();
                    $('#analysisResultModal').modal('hide');

                    $('html, body').animate({
                        scrollTop: $(rowNode).offset().top - 150
                    }, 500);

                    $('.highlight-row').removeClass('highlight-row');
                    $(rowNode).addClass('highlight-row');
                    setTimeout(function() {
                        $(rowNode).removeClass('highlight-row');
                    }, 3000);
                }
            });

            // 綁定外部篩選欄位
            table.buttons().container().appendTo('#buttons-container');

            $('#global-search').on('keyup change', function() { table.search(this.value).draw(); });
            $('#filter-date').on('keyup change', function() { table.draw(); });
            // 因新增隱藏欄位，以下索引全部 +1
            $('#filter-is-no').on('keyup change', function() { table.column(3).search(this.value).draw(); });
            $('#filter-client').on('change', function() { table.draw(); });
            $('#filter-product').on('keyup change', function() { table.column(6).search(this.value).draw(); });
            $('#filter-spec').on('keyup change', function() { table.column(7).search(this.value).draw(); });
            $('#filter-warehouse').on('keyup change', function() { table.column(11).search(this.value).draw(); });
            $('#filter-note').on('keyup change', function() { table.column(12).search(this.value).draw(); });

            // 雙擊清除篩選欄位內容
            $('#external-filter-container input').on('dblclick', function() {
                $(this).val('').trigger('change');
            });

            // 表格內容滑鼠左鍵連點兩下自動篩選此值
            $('#shippingTable tbody').on('dblclick', 'td', function() {
                var colIdx = table.cell(this).index().column;
                var cellData = $(this).text().trim();
                var filterId = null;

                switch(colIdx) {
                    // 因新增隱藏欄位和操作欄位，索引調整
                    case 2: filterId = '#filter-date'; break;
                    case 3: filterId = '#filter-is-no'; break;
                    case 4: filterId = '#filter-client'; break;
                    case 6: filterId = '#filter-product'; break;
                    case 7: filterId = '#filter-spec'; break;
                    case 11: filterId = '#filter-warehouse'; break;
                    case 12: filterId = '#filter-note'; break;
                }

                if (filterId) {
                    if (filterId === '#filter-client') {
                        var currentVal = $(filterId).val();
                        // 如果目前只選了這個客戶，則清除；否則設定為只選這個客戶
                        if (currentVal && currentVal.length === 1 && currentVal[0] === cellData) {
                            $(filterId).val(null).trigger('change');
                        } else {
                            $(filterId).val([cellData]).trigger('change');
                        }
                    } else {
                        // 若已篩選該值則清除，否則設定為該值
                        if ($(filterId).val() === cellData) {
                            $(filterId).val('').trigger('change');
                        } else {
                            $(filterId).val(cellData).trigger('change');
                        }
                    }
                }
            });

            // 清除篩選按鈕
            $('#clear-filters').click(function() {
                // 清空文字輸入框
                $('#external-filter-container input[type="text"]').val('');
                // 清空 Select2
                $('#filter-client').val(null).trigger('change');
                $('#filter-sale-type').val(null).trigger('change');
                currentChartFilter = null; // 清除圖表篩選
                // 清除 DataTable 搜尋並重繪
                table.search('').columns().search('').draw();
            });

            // 新增：雙擊熱銷產品料號以篩選主列表
            $('#top-products-tbody').on('dblclick', 'td:nth-child(2)', function() {
                var productId = $(this).text().trim();
                if (productId) {
                    // 設定料號篩選輸入框的值 (不觸發 change 以免執行預設模糊搜尋)
                    $('#filter-product').val(productId);
                    
                    // 使用精確搜尋 (Regex) 以避免部分匹配 (例如 'Z' 匹配到 'Z01')
                    var escapedProductId = $.fn.dataTable.util.escapeRegex(productId);
                    table.column(6).search('^' + escapedProductId + '$', true, false);

                    table.draw();
                    
                    // 滾動到主列表
                    $('html, body').animate({
                        scrollTop: $('#shippingTable_wrapper').offset().top - 100
                    }, 500);
                }
            });

            // 初始化出貨性質多選篩選 (列表用)
            $('#filter-sale-type').select2({
                placeholder: "出貨性質 (多選)",
                allowClear: true,
                width: '150px'
            }).on('change', function() {
                table.draw();
            });

            // 自定義出貨性質篩選邏輯
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var selectedTypes = $('#filter-sale-type').val();
                if (!selectedTypes || selectedTypes.length === 0) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var typeId = rowData.sale_type === null ? 'NULL' : String(rowData.sale_type);
                return selectedTypes.includes(typeId);
            });
            
            // 自定義客戶篩選邏輯
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var selectedClients = $('#filter-client').val();
                if (!selectedClients || selectedClients.length === 0) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var clientName = rowData.Client_name || '';
                return selectedClients.includes(clientName);
            });

            // 自定義圖表點擊篩選邏輯
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (!currentChartFilter) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var dateStr = rowData.Order_date; // YYYY-MM-DD
                var key = getDateKey(dateStr); // 使用現有的 getDateKey 函式取得該日期的分組 Key (月/週/日)
                return key === currentChartFilter;
            });

            // 異常偵測報告 - Shift+Click 多選功能
            $('#analysis-result-body').on('click', '.batch-check', function(e) {
                if (!lastChecked) {
                    lastChecked = this;
                    return;
                }

                if (e.shiftKey) {
                    var $checkboxes = $('#analysis-result-body .batch-check');
                    var start = $checkboxes.index(this);
                    var end = $checkboxes.index(lastChecked);

                    if (start !== -1 && end !== -1) {
                        $checkboxes.slice(Math.min(start, end), Math.max(start, end) + 1).prop('checked', lastChecked.checked);
                    }
                }

                lastChecked = this;
            });

            // 初始化 Highcharts
            Highcharts.chart('analysis-chart', {
                chart: {
                    type: 'column' // 改為柱狀圖更適合每日數量
                },
                title: {
                    text: '出貨金額趨勢'
                },
                xAxis: {
                    categories: <?php echo json_encode($chart_dates); ?>,
                    crosshair: true,
                    title: { text: '日期/區間' }
                },
                yAxis: {
                    min: 0,
                    title: {
                        text: '金額 (萬TWD)'
                    }
                },
                tooltip: {
                    headerFormat: '<span style="font-size:10px">{point.key}</span><table>',
                    pointFormat: '<tr><td style="color:{series.color};padding:0">{series.name}: </td>' +
                        '<td style="padding:0"><b>${point.y:,.2f} 萬</b></td></tr>',
                    footerFormat: '</table>',
                    shared: true,
                    useHTML: true
                },
                plotOptions: {
                    column: {
                        pointPadding: 0.2,
                        borderWidth: 0,
                        cursor: 'pointer',
                        point: {
                            events: {
                                click: function () {
                                    var now = new Date().getTime();
                                    if (this.lastClick && (now - this.lastClick < 300)) {
                                        // 雙擊事件：篩選列表
                                        applyChartFilter(this.category);
                                        this.lastClick = 0;
                                    } else {
                                        this.lastClick = now;
                                    }
                                }
                            }
                        }
                    }
                },
                series: [{
                    name: '出貨金額',
                    data: <?php echo json_encode($chart_values); ?>,
                    color: '#26B99A'
                }],
                credits: { enabled: false }
            });

            // 出貨性質圓餅圖
            Highcharts.chart('sale-type-chart', {
                chart: { type: 'pie' },
                title: { text: null },
                tooltip: {
                    pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b><br>金額: ${point.y} 萬'
                },
                plotOptions: {
                    pie: {
                        allowPointSelect: true,
                        cursor: 'pointer',
                        point: {
                            events: {
                                click: function (e) {
                                    // 模擬雙擊事件 (300ms 內)
                                    var now = new Date().getTime();
                                    if (this.lastClick && (now - this.lastClick < 300)) {
                                        var typeName = this.name;
                                        // 設定篩選並觸發變更
                                        $('#filter-sale-type').val([typeName]).trigger('change');
                                        // 滾動到列表
                                        $('html, body').animate({
                                            scrollTop: $('#shippingTable_wrapper').offset().top - 100
                                        }, 500);
                                        this.lastClick = 0;
                                    } else {
                                        this.lastClick = now;
                                    }
                                }
                            }
                        },
                        dataLabels: {
                            enabled: true,
                            format: '<b>{point.name}</b>: {point.percentage:.1f} %'
                        },
                        showInLegend: true
                    }
                },
                series: [{
                    name: '佔比',
                    colorByPoint: true,
                    data: <?php echo json_encode($sale_type_chart_data); ?>
                }],
                credits: { enabled: false }
            });

            // 綁定重繪事件以更新統計
            table.on('draw', updateStatistics);

            // --- 列表批次選取功能 ---
            var lastCheckedMain = null;

            // 單選 (支援 Shift+Click 多選)
            $('#shippingTable tbody').on('click', '.row-check', function(e) {
                if (!lastCheckedMain) {
                    lastCheckedMain = this;
                    return;
                }

                if (e.shiftKey) {
                    var $checkboxes = $('#shippingTable tbody .row-check');
                    var start = $checkboxes.index(this);
                    var end = $checkboxes.index(lastCheckedMain);

                    if (start !== -1 && end !== -1) {
                        var isChecked = this.checked;
                        var $range = $checkboxes.slice(Math.min(start, end), Math.max(start, end) + 1);
                        $range.not(this).prop('checked', isChecked).trigger('change');
                    }
                }
                lastCheckedMain = this;
            });

            // 處理選取狀態變更
            $('#shippingTable tbody').on('change', '.row-check', function() {
                updateSelectionState($(this), this.checked);
                updateBatchPanel();
            });

            // 全選 (本頁)
            $('#check-all').on('change', function() {
                var isChecked = this.checked;
                $('.row-check').each(function() {
                    var id = $(this).val();
                    $(this).prop('checked', isChecked);
                    updateSelectionState($(this), isChecked);
                });
                updateBatchPanel();
            });

            function updateSelectionState($cb, isChecked) {
                var id = $cb.val();
                if (isChecked) {
                    selectedISIds.add(id);
                    $cb.closest('tr').addClass('selected highlight-row');
                } else {
                    selectedISIds.delete(id);
                    $cb.closest('tr').removeClass('selected highlight-row');
                }
            }
        });

        // 儲存預設出貨性質
        function saveDefaultSaleTypes() {
            var selectedTypes = $('#filter_sale_types').val();
            if (selectedTypes === null) {
                selectedTypes = []; // 確保是陣列
            }

            if (!confirm('確定要將目前的 ' + selectedTypes.length + ' 個選項儲存為預設的出貨性質篩選嗎？')) {
                return;
            }

            $.post('', {
                action: 'save_top10_config',
                settings: JSON.stringify(selectedTypes)
            }, function(res) {
                if (res.success) {
                    // alert('預設值已儲存！下次進入頁面時將會套用。');
                } else {
                    alert('儲存失敗：' + res.message);
                }
            }, 'json').fail(function() {
                alert('與伺服器通訊失敗。');
            });
        }

        // 應用圖表篩選
        function applyChartFilter(category) {
            currentChartFilter = category;
            $('#shippingTable').DataTable().draw();
            // 滾動到列表
            $('html, body').animate({
                scrollTop: $('#shippingTable_wrapper').offset().top - 100
            }, 500);
        }

        // --- 圖檔檢視相關 JS ---
        function openProductFiles(pid) {
            if (!pid || pid === '未知料號') return;
            
            $('#modal-product-title').text(pid);
            $('#bom-file-list').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>');
            $('#bom-file-viewer').empty();
            $('#bomFileModal').modal('show');

            $.post('', { action: 'get_product_files', product_id: pid }, function(res) {
                if (res.success && res.files.length > 0) {
                    var listHtml = '';
                    res.files.forEach(function(f, idx) {
                        var active = idx === 0 ? 'active' : '';
                        listHtml += '<a href="#" class="list-group-item bom-file-item ' + active + '" data-path="' + f.path + '" data-type="' + f.type + '">' + 
                                    '<h5 class="list-group-item-heading">' + f.bom + '</h5>' +
                                    '<p class="list-group-item-text">' + f.name + '</p></a>';
                    });
                    $('#bom-file-list').html(listHtml);
                    // 預設顯示第一個
                    showBomFile(res.files[0].path, res.files[0].type);
                } else {
                    $('#bom-file-list').html('<div class="alert alert-warning">無相關圖檔</div>');
                }
            }, 'json');
        }

        $(document).on('click', '.bom-file-item', function(e) {
            e.preventDefault();
            $('.bom-file-item').removeClass('active');
            $(this).addClass('active');
            showBomFile($(this).data('path'), $(this).data('type'));
        });

        function showBomFile(path, type) {
            var html = '';
            if (type === 'pdf') {
                html = '<iframe src="' + path + '" style="width:100%; height:600px; border:none;"></iframe>';
            } else {
                html = '<img src="' + path + '" style="max-width:100%; max-height:600px; margin-top:10px;">';
            }
            $('#bom-file-viewer').html(html);
        }

        // 切換群組全選/全不選
        function toggleGroupSelection(element) {
            var $container = $(element).parent();
            var $checkboxes = $container.find('.batch-check');
            var allChecked = ($checkboxes.length > 0 && $checkboxes.length === $checkboxes.filter(':checked').length);
            $checkboxes.prop('checked', !allChecked);
        }

        // 本地異常偵測功能 (修改：加入規格顯示與批次修改)
        function performLocalAnalysis() {
            var table = $('#shippingTable').DataTable();
            lastChecked = null; // 重置多選狀態
            var currentData = table.rows({ search: 'applied' }).data().toArray();
            
            if (currentData.length === 0) {
                alert("目前無資料可分析");
                return;
            }

            // 初始化分組 (使用出貨性質)
            var zeroPriceGroups = {};
            
            // 確保 allSaleTypes 已載入
            if (allSaleTypes.length === 0) {
                loadSaleTypes(function() { performLocalAnalysis(); });
                return;
            }

            allSaleTypes.forEach(function(st) {
                var config = saleTypeConfig[st.sale_type_id] || { color: '#ffffff', keywords: [] };
                zeroPriceGroups[st.sale_type_id] = {
                    title: st.sale_type_name,
                    items: [],
                    keywords: config.keywords || [],
                    color: config.color || '#333333',
                    sort: parseInt(st.sort_order)
                };
            });
            
            // 加入 "其他" 分組
            zeroPriceGroups['other'] = {
                title: '其他 / 未分類',
                items: [],
                keywords: [],
                color: '#999999',
                sort: 9999
            };

            var largeAmountItems = [];
            var validItems = [];

            currentData.forEach(function(row) {
                // 排除不納入統計的資料
                if (row.is_count == 0) return;
                // 排除異常檢測的資料
                if (row.exclude_anomaly == 1) return;

                var qty = parseFloat(row.Qty) || 0;
                var price = parseFloat(row.Unit_price) || 0;
                var amount = qty * price;

                if (price === 0) {
                    // 檢查關鍵字進行分類
                    var textToCheck = (row.Note || '') + (row.Specification || '') + (row.Product_id || '');
                    textToCheck = textToCheck.toUpperCase(); // 轉大寫比對
                    
                    var matched = false;
                    
                    // 依序檢查設定的分類 (依排序)
                    var sortedTypes = Object.keys(zeroPriceGroups).filter(k => k !== 'other').sort(function(a, b) {
                        return zeroPriceGroups[a].sort - zeroPriceGroups[b].sort;
                    });

                    for (var i = 0; i < sortedTypes.length; i++) {
                        var typeId = sortedTypes[i];
                        var group = zeroPriceGroups[typeId];
                        if (!group.keywords || group.keywords.length === 0) continue;

                        for (var k = 0; k < group.keywords.length; k++) {
                            if (textToCheck.indexOf(group.keywords[k].toUpperCase()) !== -1) {
                                group.items.push(row);
                                matched = true;
                                break;
                            }
                        }
                        if (matched) break;
                    }
                    
                    // 若無匹配，歸類為其他
                    if (!matched) {
                        if (zeroPriceGroups['other']) {
                            zeroPriceGroups['other'].items.push(row);
                        }
                    }
                }
                
                validItems.push({
                    row: row,
                    amount: amount
                });
            });

            // 依金額排序取前 10 筆
            validItems.sort(function(a, b) { return b.amount - a.amount; });
            largeAmountItems = validItems.slice(0, 10);

            // 產生列表 HTML 的輔助函式
            function generateListHtml(items, type) {
                var listHtml = '<ul class="list-unstyled">';
                items.forEach(function(item) {
                    var row = item.row || item; // 兼容結構差異
                    var spec = row.Specification ? ' (' + row.Specification + ')' : '';
                    var info = type === 'zero' ? ' (Qty: ' + row.Qty + ')' : ' (金額: $' + numberFormat(item.amount, 0) + ')';
                    var note = row.Note ? ' <span style="color: #00008B; font-weight: bold;">[' + row.Note + ']</span>' : '';
                    
                    listHtml += '<li>' +
                        '<div class="checkbox" style="margin: 2px 0;">' +
                        '<label>' +
                        '<input type="checkbox" class="batch-check" value="' + row.IS_id + '"> ' +
                        '<span style="cursor:pointer; text-decoration:underline;" onclick="jumpToRow(' + row.IS_id + ')">' +
                        row.Order_date + ' - ' + (row.Client_name || '無客戶') + ' - ' + (row.Product_id || '無理號') + spec + info + note +
                        '</span>' +
                        '</label>' +
                        '</div>' +
                        '</li>';
                });
                listHtml += '</ul>';
                return listHtml;
            }

            var headerHtml = '';
            var bodyHtml = '';

            // 批次修改區塊 (移至最上方)
            var options = $('#edit_sale_type').html(); // 重用編輯視窗的選項
            headerHtml += '<div class="well well-sm" style="margin-bottom: 0; border-left: 5px solid #337ab7;">' +
                    '<div class="form-inline">' +
                    '<strong><i class="fa fa-pencil-square-o"></i> 批次修改：</strong> 將勾選項目之出貨性質改為 ' +
                    '<select class="form-control input-sm" id="batch_sale_type_select" style="max-width: 200px;">' + options + '</select> ' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="submitBatchUpdate()">執行修改</button>' +
                    '<small class="text-muted" style="margin-left: 10px;"><i class="fa fa-info-circle"></i> 提示：勾選時按住 Shift 鍵可連選</small>' +
                    '</div>' +
                    '</div>';

            // 0 元單價區塊 (分類顯示)
            var totalZero = 0;
            for (var key in zeroPriceGroups) {
                totalZero += zeroPriceGroups[key].items.length;
            }

            if (totalZero > 0) {
                bodyHtml += '<div class="alert alert-danger">' + 
                        '<h4><i class="fa fa-exclamation-circle"></i> 異常：單價為 0 (' + totalZero + ' 筆) ' +
                        '<button class="btn btn-xs btn-default" onclick="$(\'.batch-check\').prop(\'checked\', true)">全選</button></h4>';
                
                // 動態產生各分組 (依排序)
                var allGroupKeys = Object.keys(zeroPriceGroups).sort(function(a, b) {
                    return zeroPriceGroups[a].sort - zeroPriceGroups[b].sort;
                });

                allGroupKeys.forEach(function(key) {
                    var group = zeroPriceGroups[key];
                    if (group && group.items.length > 0) {
                        var color = group.color || '#333';
                        bodyHtml += '<div style="margin-top:10px; padding-left:10px; border-left: 3px solid ' + color + ';">';
                        bodyHtml += '<h5 style="color: ' + color + '; background-color: #fff; padding: 3px 6px; border-radius: 3px; display: inline-block; cursor: pointer;" onclick="toggleGroupSelection(this)" title="點擊全選/取消全選"><strong>' + group.title + ' (' + group.items.length + ')</strong></h5>';
                        bodyHtml += generateListHtml(group.items, 'zero');
                        bodyHtml += '</div>';
                    }
                });
                
                bodyHtml += '</div>';
            } else {
                bodyHtml += '<div class="alert alert-success"><h4><i class="fa fa-check-circle"></i> 無單價為 0 的項目</h4></div>';
            }

            // 大額交易區塊
            if (largeAmountItems.length > 0) {
                bodyHtml += '<div class="alert alert-info"><h4><i class="fa fa-trophy"></i> 大額交易 (前 10 筆)</h4>' +
                        generateListHtml(largeAmountItems, 'large') +
                        '</div>';
            }

            $('#analysis-result-header').html(headerHtml);
            $('#analysis-result-body').html(bodyHtml);
            $('#analysisResultModal').modal('show');
        }

        // 跳轉到指定行
        function jumpToRow(isId) {
            var table = $('#shippingTable').DataTable();
            // 清除篩選以確保能找到該行
            table.search('').columns().search('').draw(false);

            var indexes = table.rows().eq(0).filter(function (rowIdx) {
                return table.cell(rowIdx, 0).data() == isId;
            });

            if (indexes.length > 0) {
                var rowIndex = indexes[0];
                var pageInfo = table.page.info();
                var currentOrder = table.rows({order: 'current'}).indexes();
                var position = currentOrder.indexOf(rowIndex);
                var page = Math.floor(position / pageInfo.length);
                
                table.page(page).draw(false);

                var rowNode = table.row(rowIndex).node();
                $('#analysisResultModal').modal('hide');

                $('html, body').animate({
                    scrollTop: $(rowNode).offset().top - 150
                }, 500);

                $('.highlight-row').removeClass('highlight-row');
                $(rowNode).addClass('highlight-row');
                setTimeout(function() {
                    $(rowNode).removeClass('highlight-row');
                }, 3000);
            }
        }

        // 執行批次修改
        function submitBatchUpdate() {
            var ids = [];
            $('.batch-check:checked').each(function() {
                ids.push($(this).val());
            });
            
            if (ids.length === 0) {
                alert('請先勾選要修改的項目');
                return;
            }
            
            var saleType = $('#batch_sale_type_select').val();
            var $selectedOption = $('#batch_sale_type_select option:selected');
            var saleTypeName = $selectedOption.text();
            var isCount = $selectedOption.attr('data-is-count');
            var excludeAnomaly = $selectedOption.attr('data-exclude-anomaly');
            
            if (!confirm('確定要將選取的 ' + ids.length + ' 筆資料修改為「' + saleTypeName + '」嗎？')) {
                return;
            }
            
            $.post('../../src/store/batch_update_sale_type.php', { ids: ids, sale_type: saleType }, function(res) {
                try {
                    var data = (typeof res === 'object') ? res : JSON.parse(res);
                    
                    if (data.success) {
                        
                        // 更新 DataTable 數據
                        var table = $('#shippingTable').DataTable();
                        var newIsCount = (isCount === undefined || isCount === null) ? 1 : parseInt(isCount);
                        var idSet = {};
                        ids.forEach(function(id) { idSet[id] = true; });

                        table.rows().every(function() {
                            var d = this.data();
                            if (idSet[d.IS_id]) {
                                d.sale_type = (saleType === 'NULL') ? null : saleType;
                                d.sale_type_name = saleTypeName;
                                d.is_count = newIsCount;
                                d.exclude_anomaly = (excludeAnomaly === undefined || excludeAnomaly === null) ? 0 : parseInt(excludeAnomaly);
                                this.data(d).invalidate();
                            }
                        });
                        table.draw(false);

                        // 如果新的出貨性質設定為不納入統計 (is_count=0) 或 排除異常檢測 (exclude_anomaly=1)，則從列表中移除這些項目
                        if (newIsCount === 0 || excludeAnomaly == 1) {
                            var $checked = $('.batch-check:checked');
                            var groupsToUpdate = [];
                            
                            $checked.each(function() {
                                var $li = $(this).closest('li');
                                var $groupDiv = $li.parent().parent(); // ul -> div
                                if (groupsToUpdate.indexOf($groupDiv[0]) === -1) {
                                    groupsToUpdate.push($groupDiv[0]);
                                }
                                $li.remove();
                            });
                            
                            // 更新或移除群組
                            groupsToUpdate.forEach(function(group) {
                                var $group = $(group);
                                var remaining = $group.find('li').length;
                                if (remaining === 0) {
                                    $group.remove();
                                } else {
                                    var $title = $group.find('h5 strong');
                                    var text = $title.text();
                                    $title.text(text.replace(/\(\d+\)/, '(' + remaining + ')'));
                                }
                            });
                            
                            // 更新總數或移除整個區塊
                            var $alert = $('#analysis-result-body .alert-danger');
                            var totalRemaining = $alert.find('li').length;
                            
                            if (totalRemaining === 0) {
                                $alert.replaceWith('<div class="alert alert-success"><h4><i class="fa fa-check-circle"></i> 無單價為 0 的項目</h4></div>');
                            } else {
                                var $h4 = $alert.find('h4');
                                var h4Html = $h4.html();
                                // 更新總數顯示
                                $h4.html(h4Html.replace(/異常：單價為 0 \(\d+ 筆\)/, '異常：單價為 0 (' + totalRemaining + ' 筆)'));
                            }
                        } else {
                            // 否則，只取消勾選，讓使用者可以繼續處理其他項目
                            $('.batch-check:checked').prop('checked', false);
                        }
                    } else {
                        alert('修改失敗: ' + (data.message || '未知錯誤'));
                    }
                } catch(e) {
                    console.error(e);
                    alert('發生錯誤，請檢查回應');
                }
            });
        }

        // 開啟編輯 Modal
        function openEditModal(row) {
            $('#edit_is_id').val(row.IS_id);
            $('#edit_order_date').val(row.Order_date);
            $('#edit_is_number').val(row.IS_number);
            $('#edit_client_name').val(row.Client_name);
            $('#edit_product_id').val(row.Product_id);
            $('#edit_specification').val(row.Specification);
            $('#edit_qty').val(row.Qty);
            $('#edit_unit_price').val(row.Unit_price);
            $('#edit_note').val(row.Note);
            
            // 設定出貨性質，若為 null 則設為 'NULL' (一般產品)
            var saleType = row.sale_type === null ? 'NULL' : row.sale_type;
            $('#edit_sale_type').val(saleType);

            $('#editModal').modal('show');
        }

        // 儲存修改
        function saveEdit() {
            var formData = $('#editForm').serialize();
            var is_id = $('#edit_is_id').val();

            $.post('../../src/store/update_is_record.php', formData, function(res) {
                var data = JSON.parse(res);
                if (data.success) {
                    // alert('修改成功');
                    $('#editModal').modal('hide');
                    
                    // 更新 DataTable
                    var table = $('#shippingTable').DataTable();
                    var indexes = table.rows().eq(0).filter(function (rowIdx) {
                        return table.cell(rowIdx, 0).data() == is_id;
                    });

                    if (indexes.length > 0) {
                        var rowIndex = indexes[0];
                        var rowData = table.row(rowIndex).data();
                        
                        rowData.Order_date = $('#edit_order_date').val();
                        rowData.IS_number = $('#edit_is_number').val();
                        rowData.Client_name = $('#edit_client_name').val();
                        rowData.sale_type = $('#edit_sale_type').val();
                        var selectedOption = $('#edit_sale_type option:selected');
                        rowData.sale_type_name = selectedOption.text();
                        rowData.is_count = selectedOption.data('is-count');
                        rowData.exclude_anomaly = selectedOption.data('exclude-anomaly');
                        rowData.Product_id = $('#edit_product_id').val();
                        rowData.Specification = $('#edit_specification').val();
                        rowData.Qty = $('#edit_qty').val();
                        rowData.Unit_price = $('#edit_unit_price').val();
                        rowData.Note = $('#edit_note').val();
                        
                        table.row(rowIndex).data(rowData).draw(false);
                    }
                } else {
                    alert('修改失敗: ' + data.message);
                }
            });
        }

        // --- 出貨性質管理相關 JS ---
        function openSaleTypeModal() {
            loadSaleTypes();
            $('#saleTypeModal').modal('show');
        }

        function loadSaleTypes(callback) {
            $.post('../../src/store/manage_sale_types.php', { action: 'get' }, function(res) {
                var data = JSON.parse(res);
                if (data.success) {
                    allSaleTypes = data.data; // 更新全域變數
                    var tbody = $('#saleTypeTable tbody');
                    tbody.empty();

                    // 更新編輯視窗的下拉選單
                    var $editSelect = $('#edit_sale_type');
                    var currentVal = $editSelect.val();
                    $editSelect.empty();
                    $editSelect.append('<option value="NULL">一般產品</option>');

                    // 同步更新列表上方的批次修改下拉選單
                    var $mainBatchSelect = $('#main_batch_sale_type_select');
                    $mainBatchSelect.empty();
                    $mainBatchSelect.append('<option value="NULL">一般產品</option>');

                    // 同步更新查詢條件的下拉選單
                    var $filterSelect = $('#filter_sale_types');
                    var currentFilterVal = $filterSelect.val();
                    $filterSelect.empty();
                    $filterSelect.append('<option value="NULL">一般產品</option>');

                    data.data.forEach(function(item) {
                        var config = saleTypeConfig[item.sale_type_id] || { color: '#ffffff', keywords: [] };
                        var row = '<tr>' +
                            '<td>' + item.sale_type_name + '</td>' +
                            '<td>' + (item.description || '') + '</td>' +
                            '<td>' + (item.is_count == 1 ? '<i class="fa fa-check text-success"></i>' : '') + '</td>' +
                            '<td>' + (item.exclude_anomaly == 1 ? '<i class="fa fa-check text-danger"></i>' : '') + '</td>' +
                            '<td>' + item.sort_order + '</td>' +
                            '<td><div style="width:20px;height:20px;background-color:' + (config.color || '#fff') + ';border:1px solid #ccc;"></div></td>' +
                            '<td><small>' + (config.keywords ? config.keywords.join(', ') : '') + '</small></td>' +
                            '<td>' + (item.is_active == 1 ? '<span class="label label-success">啟用</span>' : '<span class="label label-default">停用</span>') + '</td>' +
                            '<td>' +
                                '<button class="btn btn-xs btn-info" onclick=\'editSaleType(' + JSON.stringify(item) + ')\'><i class="fa fa-pencil"></i></button> ' +
                                '<button class="btn btn-xs btn-danger" onclick="deleteSaleType(' + item.sale_type_id + ')"><i class="fa fa-trash"></i></button>' +
                            '</td>' +
                            '</tr>';
                        tbody.append(row);

                        // 加入下拉選單 (只加入啟用的)
                        if (item.is_active == 1) {
                            $editSelect.append('<option value="' + item.sale_type_id + '" data-is-count="' + item.is_count + '" data-exclude-anomaly="' + item.exclude_anomaly + '">' + item.sale_type_name + '</option>');
                            $mainBatchSelect.append('<option value="' + item.sale_type_id + '" data-is-count="' + item.is_count + '" data-exclude-anomaly="' + item.exclude_anomaly + '">' + item.sale_type_name + '</option>');
                            $filterSelect.append('<option value="' + item.sale_type_id + '">' + item.sale_type_name + '</option>');
                        }
                    });

                    // 恢復選取值
                    $editSelect.val(currentVal);
                    $filterSelect.val(currentFilterVal).trigger('change');
                    saleTypeChanged = false; // 重置變更狀態，避免自動送出

                    // 即時更新主表格中的出貨性質設定 (is_count, sale_type_name)
                    var table = $('#shippingTable').DataTable();
                    var typeMap = {};
                    data.data.forEach(function(t) { typeMap[t.sale_type_id] = t; });
                    
                    var needsDraw = false;
                    table.rows().every(function() {
                        var d = this.data();
                        if (d.sale_type && typeMap[d.sale_type]) {
                            var newType = typeMap[d.sale_type];
                            if (d.is_count != newType.is_count || d.sale_type_name != newType.sale_type_name || d.exclude_anomaly != newType.exclude_anomaly) {
                                d.is_count = newType.is_count;
                                d.exclude_anomaly = newType.exclude_anomaly;
                                d.sale_type_name = newType.sale_type_name;
                                this.data(d); // 更新 DataTables 緩存
                                needsDraw = true;
                            }
                        }
                    });
                    
                    if (needsDraw) {
                        table.draw(false); // 重繪表格以更新顯示與統計
                    }
                    
                    if (typeof callback === 'function') callback();
                }
            });
        }

        function resetSaleTypeForm() {
            $('#saleTypeForm')[0].reset();
            $('#st_id').val('');
            $('#st_count').prop('checked', true);
            $('#st_exclude_anomaly').prop('checked', false);
            $('#st_active').prop('checked', true);
            $('#st_color').val('#ffffff');
            $('#st_keywords').val('');
        }

        function editSaleType(item) {
            var config = saleTypeConfig[item.sale_type_id] || { color: '#ffffff', keywords: [] };
            $('#st_id').val(item.sale_type_id);
            $('#st_name').val(item.sale_type_name);
            $('#st_desc').val(item.description);
            $('#st_sort').val(item.sort_order);
            $('#st_count').prop('checked', item.is_count == 1);
            $('#st_exclude_anomaly').prop('checked', item.exclude_anomaly == 1);
            $('#st_active').prop('checked', item.is_active == 1);
            $('#st_color').val(config.color || '#ffffff');
            $('#st_keywords').val(config.keywords ? config.keywords.join(', ') : '');
        }

        function deleteSaleType(id) {
            if (!confirm('確定要刪除此性質嗎？若已有出貨資料使用此性質可能會失敗。')) return;
            $.post('../../src/store/manage_sale_types.php', { action: 'delete', sale_type_id: id }, function(res) {
                var data = JSON.parse(res);
                if (data.success) {
                    loadSaleTypes();
                } else {
                    alert(data.message);
                }
            });
        }

        $('#saleTypeForm').on('submit', function(e) {
            e.preventDefault();
            $.post('../../src/store/manage_sale_types.php', $(this).serialize(), function(res) {
                var data = JSON.parse(res);
                if (data.success) {
                    // 儲存成功後，儲存顏色與關鍵字設定
                    var stId = $('#st_id').val() || data.id; // 假設後端回傳 id
                    
                    if (stId) {
                        var color = $('#st_color').val();
                        var keywordsStr = $('#st_keywords').val();
                        var keywords = keywordsStr.split(',').map(function(k) { return k.trim(); }).filter(function(k) { return k !== ''; });
                        
                        saleTypeConfig[stId] = { color: color, keywords: keywords };
                        
                        // 儲存到 user_page_settings
                        $.post('', { action: 'save_sale_type_config', settings: JSON.stringify(saleTypeConfig) }, function() {
                            resetSaleTypeForm();
                            loadSaleTypes();
                        });
                    } else {
                        resetSaleTypeForm();
                        loadSaleTypes();
                    }
                } else {
                    alert(data.message);
                }
            });
        });

        // --- 列表批次修改相關 ---
        var selectedISIds = new Set();

        function updateBatchPanel() {
            if (selectedISIds.size > 0) {
                $('#batch-update-panel').slideDown();
                $('#selected-count').text(selectedISIds.size);
            } else {
                $('#batch-update-panel').slideUp();
                $('#check-all').prop('checked', false);
            }
        }

        function clearSelection() {
            selectedISIds.clear();
            $('.row-check').prop('checked', false);
            $('#shippingTable tbody tr').removeClass('selected highlight-row');
            updateBatchPanel();
        }

        function submitMainBatchUpdate() {
            var ids = Array.from(selectedISIds);
            if (ids.length === 0) return;

            var saleType = $('#main_batch_sale_type_select').val();
            var $selectedOption = $('#main_batch_sale_type_select option:selected');
            var saleTypeName = $selectedOption.text();
            var isCount = $selectedOption.attr('data-is-count');
            var excludeAnomaly = $selectedOption.attr('data-exclude-anomaly');

            if (!confirm('確定要將選取的 ' + ids.length + ' 筆資料修改為「' + saleTypeName + '」嗎？')) {
                return;
            }

            $.post('../../src/store/batch_update_sale_type.php', { ids: ids, sale_type: saleType }, function(res) {
                var data = (typeof res === 'object') ? res : JSON.parse(res);
                if (data.success) {
                    // 更新 DataTable 數據
                    var table = $('#shippingTable').DataTable();
                    var newIsCount = (isCount === undefined || isCount === null) ? 1 : parseInt(isCount);
                    var idSet = {};
                    ids.forEach(function(id) { idSet[id] = true; });

                    table.rows().every(function() {
                        var d = this.data();
                        if (idSet[d.IS_id]) {
                            d.sale_type = (saleType === 'NULL') ? null : saleType;
                            d.sale_type_name = saleTypeName;
                            d.is_count = newIsCount;
                            d.exclude_anomaly = (excludeAnomaly === undefined || excludeAnomaly === null) ? 0 : parseInt(excludeAnomaly);
                            this.data(d).invalidate();
                        }
                    });
                    table.draw(false);
                    
                    // 清除選取
                    clearSelection();
                    // alert('修改成功');
                } else {
                    alert('修改失敗: ' + (data.message || '未知錯誤'));
                }
            });
        }

        // 批次刪除 (只有 A 或含 D 權限才顯示按鈕，此處再做一次 JS 防護)
        function submitBatchDelete() {
            if (!canDelete) { alert('您沒有刪除權限'); return; }
            var ids = Array.from(selectedISIds);
            if (ids.length === 0) return;
            if (!confirm('確定要永久刪除選取的 ' + ids.length + ' 筆出貨資料嗎？\n此操作無法復原！')) return;

            $.post('', { action: 'delete_is_records', ids: ids }, function(res) {
                var data = (typeof res === 'object') ? res : JSON.parse(res);
                if (data.success) {
                    var table = $('#shippingTable').DataTable();
                    var idSet = {};
                    ids.forEach(function(id) { idSet[id] = true; });
                    var toRemove = [];
                    table.rows().every(function() {
                        if (idSet[this.data().IS_id]) toRemove.push(this.index());
                    });
                    table.rows(toRemove).remove().draw(false);
                    clearSelection();
                    showToast('已成功刪除 ' + ids.length + ' 筆資料', 'success');
                } else {
                    showToast('刪除失敗: ' + (data.message || '未知錯誤'), 'danger');
                }
            });
        }

        // ── Toast 通知 (自動 3 秒消失) ──
        function showToast(msg, type) {
            type = type || 'info';
            var icons = { success: 'fa-check-circle', danger: 'fa-exclamation-circle', info: 'fa-info-circle' };
            var $toast = $('<div class="sa-toast ' + type + '"><i class="fa ' + (icons[type]||'fa-info-circle') + '"></i><span>' + msg + '</span></div>');
            $('#sa-toast-container').append($toast);
            setTimeout(function() {
                $toast.fadeOut(400, function() { $(this).remove(); });
            }, 3000);
        }

        // ── 綁定 Modal ──
        var _bindRow = null;
        var _bindDTimer = null, _bindCTimer = null;

        function openBindModal(row) {
            _bindRow = row;
            $('#bind_is_id').val(row.IS_id);

            // 顯示目前料號
            if (row.d_setting_id) {
                $('#bind_current_d').text(row.d_setting_display || row.d_setting_id)
                    .css({ background: '#d5f5e3', color: '#1e8449' });
                $('#bind_d_id').val(row.d_setting_id);
                $('#bind_d_display').val(row.d_setting_display || '');
                $('#bind_d_preview').text(row.d_setting_display || row.d_setting_id);
            } else {
                $('#bind_current_d').text('未綁定').css({ background: '#ecf0f1', color: '#555' });
                $('#bind_d_id').val('');
                $('#bind_d_display').val('');
                $('#bind_d_preview').text('（未選取）');
            }

            // 顯示目前客戶
            var cName = row.Client_name || '';
            if (cName) {
                $('#bind_current_c').text(cName).css({ background: '#d6eaf8', color: '#1a5276' });
            } else {
                $('#bind_current_c').text('未設定').css({ background: '#ecf0f1', color: '#555' });
            }
            $('#bind_c_id').val(row.Client_id || '');
            $('#bind_c_name').val(cName);
            $('#bind_c_preview').text(cName || '（未選取）');

            // 自動帶入本筆料號搜尋
            $('#bind_c_kw').val('');
            $('#bind_c_results').hide();
            var _autoKw = row.d_setting_display || row.Product_id || '';
            $('#bind_d_kw').val(_autoKw);
            $('#bind_d_results').hide();
            if (_autoKw) { $('#bind_d_kw').trigger('input'); }

            $('#bindModal').modal('show');
        }

        // 清除料號
        $('#btn_clear_d').on('click', function() {
            $('#bind_d_id').val('');
            $('#bind_d_display').val('');
            $('#bind_d_preview').text('（清除綁定）');
            $('#bind_d_kw').val('');
            $('#bind_d_results').hide();
        });

        // 清除客戶
        $('#btn_clear_c').on('click', function() {
            $('#bind_c_id').val('');
            $('#bind_c_name').val('');
            $('#bind_c_preview').text('（清除）');
            $('#bind_c_kw').val('');
            $('#bind_c_results').hide();
        });

        // 搜尋料號
        $('#bind_d_kw').on('input', function() {
            clearTimeout(_bindDTimer);
            var kw = $(this).val().trim();
            if (!kw) { $('#bind_d_results').hide(); return; }
            _bindDTimer = setTimeout(function() {
                $('#bind_d_loading').show();
                $.post('', { action: 'search_d_setting_bind', keyword: kw }, function(res) {
                    $('#bind_d_loading').hide();
                    var data = (typeof res === 'object') ? res : JSON.parse(res);
                    var html = '';
                    if (data.success && data.data.length) {
                        data.data.forEach(function(r) {
                            var did = String(r.d_id), disp = r.D_Setting_Id || did;
                            var spec = r.Spec_No || '';
                            var cname = r.customer_name || '';
                            var cid = r.customer_id || '';
                            html += '<tr>' +
                                '<td>' + $('<span>').text(disp).html() + '</td>' +
                                '<td style="max-width:100px; overflow:hidden; text-overflow:ellipsis;">' + $('<span>').text(spec).html() + '</td>' +
                                '<td>' + $('<span>').text(cname).html() + '</td>' +
                                '<td><button type="button" class="btn btn-xs btn-primary btn-select-d" ' +
                                    'data-did="' + did + '" data-disp="' + $('<span>').text(disp).html() + '" data-cid="' + $('<span>').text(cid).html() + '" data-cname="' + $('<span>').text(cname).html() + '">選</button></td>' +
                                '</tr>';
                        });
                    } else {
                        // 無結果時提示建立料號
                        var kw_safe = $('<span>').text(kw).html();
                        html = '<tr><td colspan="4" class="text-center" style="font-size:12px; padding:10px;">'
                            + '<i class="fa fa-exclamation-circle text-warning"></i> '
                            + '查無「<strong>' + kw_safe + '</strong>」相關料號設定<br>'
                            + '<small class="text-muted">請先至「BOM 管理 → 料號設定」建立料號後再綁定</small>'
                            + '</td></tr>';
                    }
                    $('#bind_d_tbody').html(html);
                    // 若只有一筆結果且料號完全相同，自動選取
                    if (data.success && data.data.length === 1) {
                        var r = data.data[0];
                        var did = String(r.d_id), disp = r.D_Setting_Id || did;
                        var cid = r.customer_id || '', cname = r.customer_name || '';
                        $('#bind_d_id').val(did);
                        $('#bind_d_display').val(disp);
                        $('#bind_d_preview').text(disp);
                        $('#bind_d_results').hide();
                        $('#bind_d_kw').val('');
                        if (cname) {
                            $('#bind_c_id').val(cid);
                            $('#bind_c_name').val(cname);
                            $('#bind_c_preview').text(cname);
                        }
                    } else {
                        $('#bind_d_results').show();
                    }
                }, 'json').fail(function() { $('#bind_d_loading').hide(); });
            }, 350);
        });

        // 料號選取（事件委派，避免 onclick 字串跳脫問題）
        $(document).on('click', '.btn-select-d', function() {
            var dId  = $(this).data('did');
            var disp = $(this).data('disp');
            var cId  = $(this).data('cid');
            var cName= $(this).data('cname');
            $('#bind_d_id').val(dId);
            $('#bind_d_display').val(disp);
            $('#bind_d_preview').text(disp);
            $('#bind_d_results').hide();
            $('#bind_d_kw').val('');
            if (cName) {
                $('#bind_c_id').val(cId);
                $('#bind_c_name').val(cName);
                $('#bind_c_preview').text(cName);
            }
        });

        // 搜尋客戶
        $('#bind_c_kw').on('input', function() {
            clearTimeout(_bindCTimer);
            var kw = $(this).val().trim();
            if (!kw) { $('#bind_c_results').hide(); return; }
            _bindCTimer = setTimeout(function() {
                $('#bind_c_loading').show();
                $.post('', { action: 'search_customer_bind', keyword: kw }, function(res) {
                    $('#bind_c_loading').hide();
                    var data = (typeof res === 'object') ? res : JSON.parse(res);
                    var html = '';
                    if (data.success && data.data.length) {
                        data.data.forEach(function(r) {
                            var cid = String(r.customer_id), cname = r.customer || '';
                            html += '<tr>' +
                                '<td>' + $('<span>').text(cid).html() + '</td>' +
                                '<td>' + $('<span>').text(cname).html() + '</td>' +
                                '<td><button type="button" class="btn btn-xs btn-success btn-select-c" ' +
                                    'data-cid="' + $('<span>').text(cid).html() + '" data-cname="' + $('<span>').text(cname).html() + '">選</button></td>' +
                                '</tr>';
                        });
                    } else {
                        html = '<tr><td colspan="3" class="text-center text-muted" style="font-size:12px; padding:8px;">查無結果</td></tr>';
                    }
                    $('#bind_c_tbody').html(html);
                    $('#bind_c_results').show();
                }, 'json').fail(function() { $('#bind_c_loading').hide(); });
            }, 350);
        });

        // 客戶選取（事件委派）
        $(document).on('click', '.btn-select-c', function() {
            var cId  = $(this).data('cid');
            var cName= $(this).data('cname');
            $('#bind_c_id').val(cId);
            $('#bind_c_name').val(cName);
            $('#bind_c_preview').text(cName);
            $('#bind_c_results').hide();
            $('#bind_c_kw').val('');
        });

        function saveBindRecord() {
            var isId = $('#bind_is_id').val();
            if (!isId) { showToast('找不到出貨記錄 ID', 'danger'); return; }

            $.post('', {
                action:      'save_bind_record',
                is_id:       isId,
                d_setting_id: $('#bind_d_id').val(),
                client_id:   $('#bind_c_id').val(),
                client_name: $('#bind_c_name').val()
            }, function(res) {
                var data = (typeof res === 'object') ? res : JSON.parse(res);
                if (data.success) {
                    // 更新 DataTable row data
                    var table = $('#shippingTable').DataTable();
                    var idxArr = table.rows().eq(0).filter(function(ri) {
                        return table.cell(ri, 0).data() == isId;
                    });
                    if (idxArr.length > 0) {
                        var rd = table.row(idxArr[0]).data();
                        rd.d_setting_id      = $('#bind_d_id').val() || null;
                        rd.d_setting_display = $('#bind_d_display').val() || null;
                        rd.Client_id         = $('#bind_c_id').val() || null;
                        rd.Client_name       = $('#bind_c_name').val() || rd.Client_name;
                        table.row(idxArr[0]).data(rd).draw(false);
                    }
                    $('#bindModal').modal('hide');
                    showToast('綁定已儲存', 'success');
                } else {
                    showToast('儲存失敗：' + (data.message || '未知錯誤'), 'danger');
                }
            }, 'json');
        }

        // ══════════════════════════════════════
        // BOM Modal
        // ══════════════════════════════════════
        var _bomIsInfo   = null;
        var _bomList     = [];
        var _selectedBom = null;

        function openBomModal(row) {
            _bomIsInfo   = null;
            _bomList     = [];
            _selectedBom = null;
            $('#bom_is_id').val(row.IS_id);
            $('#bom_order_id').val(row.Order_id || '');
            $('#bom_is_info_panel').html('<i class="fa fa-spinner fa-spin"></i> 載入中...');
            $('#bom_list_tbody').html('<tr><td colspan="3" class="text-center" style="padding:12px;"><i class="fa fa-spinner fa-spin"></i></td></tr>');
            $('#bom_detail_panel').html('<div class="text-muted text-center" style="padding:40px; font-size:13px;"><i class="fa fa-arrow-left"></i> 點擊左側 BOM 查看詳情</div>');
            $('#bomModal').modal('show');

            $.post('', { action: 'get_bom_list_for_is', is_id: row.IS_id }, function(res) {
                if (!res.success) { showToast('載入失敗：' + res.message, 'danger'); return; }
                _bomIsInfo = res.is_info;
                _bomList   = res.boms || [];
                if (_bomIsInfo && _bomIsInfo.Order_id) { $('#bom_order_id').val(_bomIsInfo.Order_id); }
                renderBomIsInfo();
                // 若有唯一自動匹配訂單，自動設定
                if (res.auto_order && !$('#bom_order_id').val()) {
                    $('#bom_order_id').val(res.auto_order.Order_id);
                    showToast('已自動關聯訂單：' + res.auto_order.Order_oo, 'info');
                }
                renderBomList();
                // 若有已綁定 BOM，直接展開第一個
                var firstBound = null;
                _bomList.forEach(function(b) { if (b.is_bound && !firstBound) firstBound = b; });
                if (firstBound) {
                    _selectedBom = firstBound;
                    // 等 DOM 更新後再加 outline
                    setTimeout(function() {
                        $('.bom-list-row').css('outline', '');
                        $('.bom-list-row[data-bom="' + $('<span>').text(firstBound.bom).html() + '"]').css('outline', '2px solid #3498db');
                    }, 50);
                    renderBomDetail(firstBound);
                }
            }, 'json').fail(function() { showToast('連線失敗', 'danger'); });
        }

        function renderBomIsInfo() {
            if (!_bomIsInfo) return;
            var i = _bomIsInfo;
            var html = '<div class="row" style="margin:0;">'
                + '<div class="col-xs-12 col-sm-2"><strong style="font-size:11px;">出貨日期</strong><br>' + (i.Order_date || '') + '</div>'
                + '<div class="col-xs-12 col-sm-3"><strong style="font-size:11px;">出貨單號</strong><br>' + $('<span>').text(i.IS_number || '').html() + '</div>'
                + '<div class="col-xs-12 col-sm-2"><strong style="font-size:11px;">客戶</strong><br>' + $('<span>').text(i.Client_name_display || i.Client_name || '').html() + '</div>'
                + '<div class="col-xs-12 col-sm-2"><strong style="font-size:11px;">料號</strong><br>' + $('<span>').text(i.Product_id || '').html() + '</div>'
                + '<div class="col-xs-12 col-sm-2"><strong style="font-size:11px;">規格</strong><br><small>' + $('<span>').text(i.Specification || '').html() + '</small></div>'
                + '<div class="col-xs-12 col-sm-1 text-right"><strong style="font-size:11px;">數量</strong><br><span class="text-primary">' + (i.Qty || '') + '</span></div>'
                + '</div>';
            if (i.Order_id) {
                html += '<div style="margin-top:4px; font-size:11px; color:#777;"><i class="fa fa-link"></i> 關聯訂單ID：' + i.Order_id + '</div>';
            }
            $('#bom_is_info_panel').html(html);
        }

        function renderBomList() {
            if (!_bomList.length) {
                $('#bom_list_tbody').html('<tr><td colspan="3" class="text-center text-muted" style="padding:16px; font-size:12px;">此料號尚無 BOM</td></tr>');
                return;
            }
            var html = '';
            _bomList.forEach(function(b) {
                var isBound = b.is_bound;
                var hasBoundShip = b.bound_shipments && b.bound_shipments.length > 0;
                // 三種狀態：
                // 綠色 = 已綁訂單且有出貨單關聯
                // 橘色 = 已綁訂單但無出貨單 (此 BOM 只是有訂單，與本筆出貨單無關)
                // 紅色 = 未綁定任何訂單
                var badge, rowBg;
                if (isBound && hasBoundShip) {
                    badge = '<span class="label label-success" style="font-size:10px;"><i class="fa fa-chain"></i> 已綁訂單+出貨</span>';
                    rowBg = 'background:#f0fff0;';
                } else if (isBound) {
                    badge = '<span class="label label-warning" style="font-size:10px;"><i class="fa fa-chain"></i> 已綁訂單</span>'
                          + '<br><small class="text-muted" style="font-size:10px;">無出貨單對應</small>';
                    rowBg = 'background:#fffde7;';
                } else {
                    badge = '<span class="label label-danger" style="font-size:10px;"><i class="fa fa-chain-broken"></i> 未綁定</span>';
                    rowBg = '';
                }
                html += '<tr class="bom-list-row" data-bom="' + $('<span>').text(b.bom).html() + '" style="cursor:pointer;' + rowBg + '">'
                    + '<td><strong style="font-size:11px;">' + $('<span>').text(b.bom).html() + '</strong>'
                    + (b.bom_ps ? '<br><small class="text-muted" style="font-size:10px;">' + $('<span>').text(b.bom_ps).html() + '</small>' : '')
                    + '</td>'
                    + '<td class="text-right" style="font-size:11px;">' + (b.sqty || '') + '</td>'
                    + '<td>' + badge + '</td>'
                    + '</tr>';
            });
            $('#bom_list_tbody').html(html);
        }

        $(document).on('click', '.bom-list-row', function() {
            $('.bom-list-row').css('outline', '');
            $(this).css('outline', '2px solid #3498db');
            var bomId = $(this).data('bom');
            _selectedBom = null;
            _bomList.forEach(function(b) { if (b.bom === bomId) _selectedBom = b; });
            if (_selectedBom) renderBomDetail(_selectedBom);
        });

        function renderBomDetail(b) {
            var oid    = $('#bom_order_id').val();
            var isInfo = _bomIsInfo;
            var html   = '';

            // BOM 基本資訊
            html += '<div class="panel panel-default" style="margin-bottom:6px;">'
                + '<div class="panel-heading" style="padding:6px 10px; font-size:12px; font-weight:600;">'
                + '<i class="fa fa-cubes"></i> BOM: <strong>' + $('<span>').text(b.bom).html() + '</strong>'
                + ' &nbsp;數量：<span class="text-primary">' + (b.sqty || '') + '</span>'
                + ' &nbsp;客戶：' + $('<span>').text(b.Client_Name || '').html()
                + '</div><div class="panel-body" style="padding:8px;">';

            // 已綁定訂單
            if (b.bound_orders && b.bound_orders.length) {
                html += '<strong style="font-size:12px;"><i class="fa fa-chain text-success"></i> 已綁定訂單</strong>'
                    + '<table class="table table-condensed table-bordered" style="font-size:11px; margin-top:4px; margin-bottom:6px;"><thead style="background:#d5f5e3;"><tr><th>訂單號</th><th>客戶</th><th>訂單數</th><th>分配數</th><th></th></tr></thead><tbody>';
                b.bound_orders.forEach(function(bo) {
                    html += '<tr>'
                        + '<td>' + $('<span>').text(bo.Order_oo || '').html() + '</td>'
                        + '<td>' + $('<span>').text(bo.order_client || '').html() + '</td>'
                        + '<td class="text-right">' + (bo.order_qty || '') + '</td>'
                        + '<td class="text-right">' + (bo.allocated_qty || '') + '</td>'
                        + '<td><button type="button" class="btn btn-xs btn-danger btn-unbind-bom"'
                        + ' data-bom="' + $('<span>').text(b.bom).html() + '"'
                        + ' data-order-id="' + (bo.order_id || '') + '"'
                        + ' data-order-oo="' + $('<span>').text(bo.Order_oo || '').html() + '">'
                        + '<i class="fa fa-chain-broken"></i> 解除</button></td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<div class="text-muted" style="font-size:12px; margin-bottom:4px;"><i class="fa fa-chain-broken text-danger"></i> 尚未綁定任何訂單</div>';
            }

            // 已綁定出貨單
            var bShips = b.bound_shipments || [];
            if (bShips.length) {
                html += '<strong style="font-size:12px;"><i class="fa fa-truck text-primary"></i> 已關聯出貨單</strong>'
                    + '<table class="table table-condensed table-bordered" style="font-size:11px; margin-top:4px; margin-bottom:6px;"><thead style="background:#d6eaf8;"><tr><th>出貨單號</th><th>規格</th><th>出貨數</th><th>單價</th><th>日期</th></tr></thead><tbody>';
                bShips.forEach(function(s) {
                    html += '<tr>'
                        + '<td><strong>' + $('<span>').text(s.IS_number || '').html() + '</strong></td>'
                        + '<td style="font-size:10px;">' + $('<span>').text(s.Specification || '').html() + '</td>'
                        + '<td class="text-right">' + (s.shipped_qty || s.Qty || '') + '</td>'
                        + '<td class="text-right">' + (s.Unit_price > 0 ? 'NT$' + Number(s.Unit_price).toLocaleString() : '-') + '</td>'
                        + '<td>' + (s.Order_date || '') + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<div class="text-muted" style="font-size:11px; margin-bottom:4px; color:#e67e22;"><i class="fa fa-truck"></i> 尚未關聯任何出貨單</div>';
            }
            html += '</div></div>';

            // 綁定操作面板
            // 若出貨單本身無 Order_id，嘗試從此 BOM 已綁訂單自動帶入第一筆
            var effectiveOid = oid;
            if (!effectiveOid && b.bound_orders && b.bound_orders.length > 0) {
                effectiveOid = String(b.bound_orders[0].order_id || '');
                if (effectiveOid) { $('#bom_order_id').val(effectiveOid); }
            }

            html += '<div class="panel panel-success" style="margin-bottom:6px;">'
                + '<div class="panel-heading" style="padding:6px 10px; font-size:12px;">'
                + '<i class="fa fa-plus-circle"></i> 將本筆出貨單綁定到此 BOM 的訂單 &nbsp;';
            if (effectiveOid) {
                html += '<span class="label label-success">訂單ID: ' + effectiveOid + '</span>';
            } else {
                html += '<span class="label label-warning">本筆出貨單無關聯訂單，請搜尋選擇</span>';
            }
            // 說明：避免使用者誤解「已綁定訂單」= 已對應此出貨單
            html += '<br><small class="text-muted" style="font-size:10px; display:block; margin-top:2px;">'
                + '* 左側[已綁訂單]表示此BOM與某訂單的關聯，不代表本筆出貨單已對應此BOM</small>'
                + '</div><div class="panel-body" style="padding:8px;">';
            // 更新 oid 供後續判斷
            oid = effectiveOid;

            // 若無訂單 → 搜尋框
            if (!oid) {
                html += '<div style="margin-bottom:8px;">'
                    + '<div class="input-group input-group-sm">'
                    + '<input type="text" class="form-control" id="order_search_kw" placeholder="輸入訂單號 / 料號 / 客戶搜尋…" value="' + $('<span>').text(isInfo ? (isInfo.Product_id || '') : '').html() + '">'
                    + '<span class="input-group-btn"><button class="btn btn-default btn-sm" id="btn_search_order" type="button"><i class="fa fa-search"></i> 搜尋訂單</button></span>'
                    + '</div>'
                    + '<div id="order_search_results" style="margin-top:6px; max-height:150px; overflow-y:auto; display:none;">'
                    + '<table class="table table-condensed table-hover table-bordered" style="font-size:11px; margin:0;">'
                    + '<thead style="background:#f5f5f5; position:sticky; top:0;"><tr><th>訂單號</th><th>料號</th><th>客戶</th><th>數量</th><th>交期</th><th></th></tr></thead>'
                    + '<tbody id="order_search_tbody"></tbody>'
                    + '</table></div>'
                    + '<div id="order_selected_display" style="margin-top:6px; display:none;">'
                    + '<span class="label label-success" id="order_selected_label"></span>'
                    + ' <a href="javascript:void(0);" id="order_selected_clear" style="font-size:11px; margin-left:6px;">重新選擇</a>'
                    + '</div></div>';
            }

            // 分配數量 + 確認綁定
            html += '<div class="form-inline" style="margin-bottom:6px;">'
                + '<label style="font-size:12px; margin-right:6px;">分配數量：</label>'
                + '<input type="number" class="form-control input-sm" id="bom_bind_qty" value="' + (isInfo ? (isInfo.Qty || b.sqty || '') : (b.sqty || '')) + '" style="width:80px;" min="1">'
                + ' &nbsp;<button type="button" class="btn btn-sm btn-success" id="btn_do_bind_bom">'
                + '<i class="fa fa-chain"></i> 確認綁定</button>'
                + '</div>';

            // 製程選擇
            if (b.processes && b.processes.length) {
                var psMap = { 'N':'新建','P':'待移轉','Q':'QC待驗','ing':'加工中','E':'已移轉','1':'完工' };
                html += '<div style="font-size:12px;">'
                    + '<label><input type="checkbox" id="bom_proc_all" checked> <strong>全選製程</strong></label>'
                    + ' <small class="text-muted">（取消勾選只綁定部分製程）</small>'
                    + '<table class="table table-condensed table-bordered" style="font-size:11px; margin-top:4px; margin-bottom:0;"><thead style="background:#f5f5f5;"><tr><th style="width:26px;"></th><th>序</th><th>製程</th><th>廠商</th><th>數量</th><th>狀態</th></tr></thead><tbody>';
                b.processes.forEach(function(p, idx) {
                    html += '<tr>'
                        + '<td><input type="checkbox" class="bom-proc-chk" value="' + (p.bom_ing_fid || '') + '" checked></td>'
                        + '<td>' + (idx+1) + '</td>'
                        + '<td>' + $('<span>').text(p.ProcessName || '').html() + '</td>'
                        + '<td>' + $('<span>').text(p.maker_id || '').html() + '</td>'
                        + '<td class="text-right">' + (p.proc_sqty || '') + '</td>'
                        + '<td>' + (psMap[p.proc_state] || p.proc_state || '') + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '</div></div>';

            $('#bom_detail_panel').html(html);

            // 全選製程
            $(document).off('change', '#bom_proc_all').on('change', '#bom_proc_all', function() {
                $('.bom-proc-chk').prop('checked', $(this).is(':checked'));
            });

            // 搜尋訂單
            $(document).off('click', '#btn_search_order').on('click', '#btn_search_order', function() {
                var kw = $('#order_search_kw').val().trim();
                if (!kw) return;
                $('#order_search_tbody').html('<tr><td colspan="6" class="text-center" style="padding:6px;"><i class="fa fa-spinner fa-spin"></i></td></tr>');
                $('#order_search_results').show();
                $.post('', {
                    action: 'search_order_for_bom',
                    keyword: kw,
                    d_id: isInfo ? (isInfo.Product_id || '') : ''
                }, function(res) {
                    if (!res.success || !res.data.length) {
                        $('#order_search_tbody').html('<tr><td colspan="6" class="text-center text-muted" style="padding:6px; font-size:11px;">查無結果</td></tr>');
                        return;
                    }
                    var h = '';
                    res.data.forEach(function(o) {
                        h += '<tr>'
                            + '<td>' + $('<span>').text(o.Order_oo || '').html() + '</td>'
                            + '<td>' + $('<span>').text(o.d_id || '').html() + '</td>'
                            + '<td>' + $('<span>').text(o.Client_name || '').html() + '</td>'
                            + '<td class="text-right">' + (o.Qty || '') + '</td>'
                            + '<td>' + (o.Delivery_date || '') + '</td>'
                            + '<td><button type="button" class="btn btn-xs btn-primary btn-select-order"'
                            + ' data-order-id="' + o.Order_id + '"'
                            + ' data-order-oo="' + $('<span>').text(o.Order_oo || '').html() + '">選取</button></td>'
                            + '</tr>';
                    });
                    $('#order_search_tbody').html(h);
                }, 'json');
            });
            $(document).off('keypress', '#order_search_kw').on('keypress', '#order_search_kw', function(e) {
                if (e.which === 13) $('#btn_search_order').click();
            });
        }

        // 選取訂單
        $(document).on('click', '.btn-select-order', function() {
            var oid_val = $(this).data('order-id');
            var oo_val  = $(this).data('order-oo');
            $('#bom_order_id').val(oid_val);
            $('#order_selected_label').text('已選：' + oo_val + ' (ID:' + oid_val + ')');
            $('#order_selected_display').show();
            $('#order_search_results').hide();
            renderBomDetail(_selectedBom);
        });

        // 確認綁定
        $(document).on('click', '#btn_do_bind_bom', function() {
            if (!_selectedBom) return;
            var oid  = $('#bom_order_id').val();
            var isId = $('#bom_is_id').val();
            var qty  = parseInt($('#bom_bind_qty').val() || '0');
            if (qty <= 0) { showToast('請輸入有效數量', 'danger'); return; }
            var procIds = [];
            $('.bom-proc-chk:checked').each(function() { if ($(this).val()) procIds.push($(this).val()); });

            $.post('', {
                action:        'save_bom_order_bind',
                bom:           _selectedBom.bom,
                is_id:         isId,
                order_id:      oid || 0,
                allocated_qty: qty,
                proc_ids:      JSON.stringify(procIds)
            }, function(res) {
                if (res.need_order_search) {
                    showToast('請先搜尋並選擇要綁定的訂單', 'info');
                    $('#bom_order_id').val('');
                    renderBomDetail(_selectedBom);
                    return;
                }
                if (res.success) {
                    if (res.order_id) { $('#bom_order_id').val(res.order_id); }
                    showToast('BOM 綁定成功', 'success');
                    $.post('', { action: 'get_bom_list_for_is', is_id: isId }, function(res2) {
                        if (res2.success) {
                            _bomList = res2.boms || [];
                            if (res2.is_info) _bomIsInfo = res2.is_info;
                            renderBomIsInfo();
                            renderBomList();
                            _bomList.forEach(function(bx) { if (bx.bom === _selectedBom.bom) _selectedBom = bx; });
                            if (_selectedBom) renderBomDetail(_selectedBom);
                            var tbl = $('#shippingTable').DataTable();
                            var idx = tbl.rows().eq(0).filter(function(ri) { return tbl.cell(ri, 0).data() == isId; });
                            if (idx.length > 0) {
                                var rd = tbl.row(idx[0]).data();
                                rd.bom_list = _bomList.filter(function(bx){ return bx.is_bound; }).map(function(bx){ return bx.bom; }).join(', ');
                                if (res.order_id) rd.Order_id = res.order_id;
                                tbl.row(idx[0]).data(rd).draw(false);
                            }
                        }
                    }, 'json');
                } else { showToast('綁定失敗：' + (res.message || ''), 'danger'); }
            }, 'json');
        });

        // 解除綁定
        $(document).on('click', '.btn-unbind-bom', function() {
            var bom      = $(this).data('bom');
            var order_id = $(this).data('order-id');
            var order_oo = $(this).data('order-oo');
            if (!confirm('確定解除 BOM ' + bom + ' 與訂單 ' + order_oo + ' 的綁定？')) return;
            $.post('', { action: 'unbind_bom_order', bom: bom, order_id: order_id }, function(res) {
                if (res.success) {
                    showToast('已解除綁定', 'success');
                    var isId = $('#bom_is_id').val();
                    $.post('', { action: 'get_bom_list_for_is', is_id: isId }, function(res2) {
                        if (res2.success) {
                            _bomList = res2.boms || [];
                            renderBomList();
                            _bomList.forEach(function(bx) { if (bx.bom === bom) _selectedBom = bx; });
                            if (_selectedBom) renderBomDetail(_selectedBom);
                        }
                    }, 'json');
                } else { showToast('解除失敗：' + res.message, 'danger'); }
            }, 'json');
        });

    </script>
</body>
</html>