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

// ── AJAX: 切換異常確認狀態 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_anomaly_confirmed') {
    header('Content-Type: application/json');
    try {
        $is_id = intval($_POST['is_id'] ?? 0);
        if (!$is_id) throw new Exception('Missing ID');
        $pdo = $conn->getPDO();
        $sel = $pdo->prepare("SELECT anomaly_confirmed FROM is_list WHERE IS_id = ?");
        $sel->execute([$is_id]);
        $cur = intval($sel->fetchColumn());
        $new_val = $cur ? 0 : 1;
        $pdo->prepare("UPDATE is_list SET anomaly_confirmed = ? WHERE IS_id = ?")->execute([$new_val, $is_id]);
        echo json_encode(['success' => true, 'confirmed' => $new_val]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 年度出貨比較（輕量聚合，不回傳明細）──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_yearly_comparison') {
    header('Content-Type: application/json');
    try {
        $pdo       = $conn->getPDO();
        $num_years = max(1, min(10, intval($_POST['years'] ?? 3)));
        // 直接在此讀取截止日（此 handler 提前 exit，$global_cutoff_day 尚未初始化）
        $_cutoff_row = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'billing_cutoff_day' LIMIT 1")->fetchColumn();
        $cutoff = ($_cutoff_row !== false) ? intval($_cutoff_row) : 0;

        // 以當年為基準，往前 num_years 年
        $year_now   = intval(date('Y'));
        $year_start = $year_now - $num_years + 1;

        // helper：帳款月計算（MySQL 版）
        // cutoff=0 → 原月；cutoff>0 → day>cutoff 則歸入下月
        $bm_expr = $cutoff > 0
            ? "CASE WHEN DAY(`{col}`) > $cutoff THEN IF(MONTH(`{col}`)=12,1,MONTH(`{col}`)+1) ELSE MONTH(`{col}`) END"
            : "MONTH(`{col}`)";
        $by_expr = $cutoff > 0
            ? "CASE WHEN DAY(`{col}`) > $cutoff THEN IF(MONTH(`{col}`)=12,YEAR(`{col}`)+1,YEAR(`{col}`)) ELSE YEAR(`{col}`) END"
            : "YEAR(`{col}`)";

        // 出貨：is_list
        $is_mo  = str_replace('{col}', 'Order_date', $bm_expr);
        $is_yr  = str_replace('{col}', 'Order_date', $by_expr);
        $stmt_is = $pdo->prepare(
            "SELECT ($is_yr) AS yr, ($is_mo) AS mo,
                    SUM(Qty * Unit_price) AS ship_amount,
                    COUNT(CASE WHEN Unit_price > 0 THEN 1 END) AS ship_count
             FROM is_list
             WHERE Order_date >= :ys AND Order_date <= :ye
             GROUP BY yr, mo ORDER BY yr, mo"
        );
        $stmt_is->execute([':ys' => "$year_start-01-01", ':ye' => "$year_now-12-31"]);
        $is_rows = $stmt_is->fetchAll(PDO::FETCH_ASSOC);

        // 接單：order_track（Delivery_date）
        $ot_mo = str_replace('{col}', 'Delivery_date', $bm_expr);
        $ot_yr = str_replace('{col}', 'Delivery_date', $by_expr);
        $stmt_ot = $pdo->prepare(
            "SELECT ($ot_yr) AS yr, ($ot_mo) AS mo,
                    SUM(Qty * unit_price) AS order_amount
             FROM order_track
             WHERE Delivery_date >= :ys AND Delivery_date <= :ye
               AND (Order_status IS NULL OR Order_status != 9)
               AND unit_price IS NOT NULL AND unit_price > 0
             GROUP BY yr, mo ORDER BY yr, mo"
        );
        $stmt_ot->execute([':ys' => "$year_start-01-01", ':ye' => "$year_now-12-31"]);
        $ot_rows = $stmt_ot->fetchAll(PDO::FETCH_ASSOC);

        // 組合結構：years[] + months(1-12) + data{ship,order,count}[year][month]
        $years_list = range($year_start, $year_now);
        $result = ['ship' => [], 'order' => [], 'count' => []];
        foreach ($years_list as $y) {
            $result['ship'][$y]  = array_fill(1, 12, 0);
            $result['order'][$y] = array_fill(1, 12, 0);
            $result['count'][$y] = array_fill(1, 12, 0);
        }
        foreach ($is_rows as $r) {
            $y = intval($r['yr']); $m = intval($r['mo']);
            if ($m >= 1 && $m <= 12 && isset($result['ship'][$y])) {
                $result['ship'][$y][$m]  = round(floatval($r['ship_amount'])  / 10000, 2);
                $result['count'][$y][$m] = intval($r['ship_count']);
            }
        }
        foreach ($ot_rows as $r) {
            $y = intval($r['yr']); $m = intval($r['mo']);
            if ($m >= 1 && $m <= 12 && isset($result['order'][$y])) {
                $result['order'][$y][$m] = round(floatval($r['order_amount']) / 10000, 2);
            }
        }
        // 轉為陣列格式
        foreach (['ship','order','count'] as $k) {
            foreach ($result[$k] as $y => &$arr) { $arr = array_values($arr); }
            unset($arr);
        }
        echo json_encode(['success' => true, 'years' => $years_list, 'data' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 儲存帳款月份截止日 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_billing_cutoff') {
    header('Content-Type: application/json');
    try {
        $day = intval($_POST['cutoff_day'] ?? 0);
        if ($day < 0 || $day > 31) throw new Exception('截止日必須介於 0~31');
        $by = $_SESSION['user_cname'] ?? $_SESSION['userName'] ?? 'system';
        $conn->getPDO()->prepare(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES ('billing_cutoff_day', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                 updated_by = VALUES(updated_by), updated_at = NOW()"
        )->execute([$day, $by]);
        echo json_encode(['success' => true, 'message' => $day > 0 ? "已設為每月 {$day} 日截止" : '已取消截止日設定']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── 讀取全域帳款月份截止日 ──
$global_cutoff_day = 0;
try {
    $_sc = $conn->getPDO()->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'billing_cutoff_day' LIMIT 1");
    $_sc->execute();
    $_scr = $_sc->fetchColumn();
    if ($_scr !== false) $global_cutoff_day = intval($_scr);
} catch (Exception $_sce) {}

// AJAX：取得指定帳款月份的客戶統計（供前期比較用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_prev_bm_stats') {
    header('Content-Type: application/json');
    $bm = $_POST['billing_month'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $bm)) { echo json_encode(['success'=>false]); exit; }
    [$_bmy, $_bmm] = explode('-', $bm);
    $_bmy = (int)$_bmy; $_bmm = (int)$_bmm;
    if ($global_cutoff_day <= 0) {
        $_bs = sprintf('%04d-%02d-01', $_bmy, $_bmm);
        $_be = (new DateTime(sprintf('%04d-%02d-01', $_bmy, $_bmm)))->format('Y-m-t');
    } else {
        $_bpm = $_bmm === 1 ? 12 : $_bmm - 1;
        $_bpy = $_bmm === 1 ? $_bmy - 1 : $_bmy;
        $_bs = sprintf('%04d-%02d-%02d', $_bpy, $_bpm, $global_cutoff_day + 1);
        $_be = sprintf('%04d-%02d-%02d', $_bmy, $_bmm, $global_cutoff_day);
    }
    try {
        $_pdo = $conn->getPDO();
        $_st = [];
        // 出貨
        $_r = $_pdo->prepare("SELECT COALESCE(isl.Client_name,'未知客戶') cn, SUM(isl.Qty*isl.Unit_price) ship FROM is_list isl LEFT JOIN is_sale_type ist ON isl.sale_type=ist.sale_type_id WHERE isl.Order_date BETWEEN ? AND ? AND (ist.is_count IS NULL OR ist.is_count=1) AND isl.Unit_price>0 GROUP BY isl.Client_name");
        $_r->execute([$_bs, $_be]);
        foreach ($_r->fetchAll(PDO::FETCH_ASSOC) as $_row) { $cn=trim($_row['cn']); if(!isset($_st[$cn])) $_st[$cn]=['ship'=>0,'ret'=>0,'ord'=>0]; $_st[$cn]['ship']+=floatval($_row['ship']); }
        // 退貨
        $_r2 = $_pdo->prepare("SELECT COALESCE(it.Client_name,'未知客戶') cn, SUM(COALESCE(it.Qty*it.Unit_price,0)) ret FROM ir_track it WHERE it.IR_date BETWEEN ? AND ? GROUP BY it.Client_name");
        $_r2->execute([$_bs, $_be]);
        foreach ($_r2->fetchAll(PDO::FETCH_ASSOC) as $_row) { $cn=trim($_row['cn']); if(!isset($_st[$cn])) $_st[$cn]=['ship'=>0,'ret'=>0,'ord'=>0]; $_st[$cn]['ret']+=floatval($_row['ret']); }
        // 訂單
        $_r3 = $_pdo->prepare("SELECT COALESCE(ot.Client_name,'未知客戶') cn, SUM(COALESCE(ot.Qty*ot.unit_price,0)) ord FROM order_track ot WHERE ot.Delivery_date BETWEEN ? AND ? AND (ot.Order_status IS NULL OR ot.Order_status!=9) AND ot.unit_price>0 GROUP BY ot.Client_name");
        $_r3->execute([$_bs, $_be]);
        foreach ($_r3->fetchAll(PDO::FETCH_ASSOC) as $_row) { $cn=trim($_row['cn']); if(!isset($_st[$cn])) $_st[$cn]=['ship'=>0,'ret'=>0,'ord'=>0]; $_st[$cn]['ord']+=floatval($_row['ord']); }
        echo json_encode(['success'=>true,'data'=>$_st,'range'=>$_bs.' ~ '.$_be]);
    } catch (Exception $_e2) { echo json_encode(['success'=>false,'message'=>$_e2->getMessage()]); }
    exit;
}

// 取得 GET 參數，若無則為空字串
$start_date_param = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date_param = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// 預設日期範圍：若兩者皆空，則預設為本帳款月（依截止日判斷）
if ($start_date_param === '' && $end_date_param === '') {
    $_td = (int)date('j');
    $_tm = (int)date('n');
    $_ty = (int)date('Y');

    // 判斷當前帳款月份
    if ($global_cutoff_day > 0 && $_td > $global_cutoff_day) {
        // 今日已超過截止日：帳款月為下個月
        $_bm = $_tm === 12 ? 1 : $_tm + 1;
        $_by = $_tm === 12 ? $_ty + 1 : $_ty;
    } else {
        $_bm = $_tm;
        $_by = $_ty;
    }

    // 帳款月起始日：上個帳款月的截止日+1
    $_pm = $_bm === 1 ? 12 : $_bm - 1;
    $_py = $_bm === 1 ? $_by - 1 : $_by;
    $_sd = $global_cutoff_day > 0 ? $global_cutoff_day + 1 : 1;
    $_pm_days = (int)(new DateTime(sprintf('%04d-%02d-01', $_py, $_pm)))->format('t');
    $start_date = sprintf('%04d-%02d-%02d', $_py, $_pm, min($_sd, $_pm_days));

    // 帳款月結束日：當帳款月的截止日（無截止日則用月底）
    $_bm_days = (int)(new DateTime(sprintf('%04d-%02d-01', $_by, $_bm)))->format('t');
    $_ed = $global_cutoff_day > 0 ? min($global_cutoff_day, $_bm_days) : $_bm_days;
    $end_date = sprintf('%04d-%02d-%02d', $_by, $_bm, $_ed);
} else {
    $start_date = $start_date_param ?: date('Y-m-01');
    $end_date = $end_date_param ?: date('Y-m-d');
}
// 確保 exclude_top10 欄位存在（首次執行時自動建立）
try { $conn->getPDO()->query("SELECT exclude_top10 FROM is_sale_type LIMIT 1"); } catch (Exception $_ce) {
    try { $conn->getPDO()->exec("ALTER TABLE is_sale_type ADD COLUMN exclude_top10 tinyint(1) NOT NULL DEFAULT 0 COMMENT '排除十大熱銷產品統計'"); } catch (Exception $_ae) {}
}

// kpi_monthly_targets 資料表自動建立已收斂進 src/common/kpi_lib.php 的 kpi_ensure_schema()（get_kpi_data/save_kpi_target 都會呼叫到），不在此重複維護

// ── AJAX: 取得 KPI 週報資料 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_kpi_data') {
    header('Content-Type: application/json');
    require_once '../../src/common/kpi_lib.php';
    try {
        $pdo   = $conn->getPDO();
        $year  = intval($_POST['year']  ?? date('Y'));
        $month = intval($_POST['month'] ?? date('n'));

        $report = kpi_weekly_report($pdo, $year, $month);

        // 載入表尾設定（本頁專屬 UI 設定，不屬於 KPI 計算本身，不放進共用函式）
        $kpi_footer = ['left' => '', 'center' => '', 'right' => ''];
        try {
            $uid = $_SESSION['user_id'] ?? $_SESSION['id'];
            $fst = $pdo->prepare("SELECT setting_value FROM user_page_settings WHERE user_id=? AND page_code='shipping_analysis' AND setting_key='kpi_footer'");
            $fst->execute([$uid]);
            $fv = $fst->fetchColumn();
            if ($fv) $kpi_footer = json_decode($fv, true) ?: $kpi_footer;
        } catch(Exception $_fe) {}

        echo json_encode(array_merge(['success' => true], $report, ['footer' => $kpi_footer]));
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 儲存 KPI 月份目標（共用 kpi_lib.php 的 kpi_target_save，Shipping_Analysis_new.php/會議紀錄/AS9100 KPI 設定頁都走同一支寫入邏輯）──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_kpi_target') {
    header('Content-Type: application/json');
    require_once '../../src/common/kpi_lib.php';
    try {
        $pdo = $conn->getPDO();
        kpi_target_save($pdo, intval($_POST['year']), intval($_POST['month']),
            floatval($_POST['target_amount'] ?? 0), intval($_POST['start_day'] ?? 1));
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: 儲存 KPI 表尾設定 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_kpi_footer') {
    header('Content-Type: application/json');
    try {
        $pdo = $conn->getPDO();
        $uid = $_SESSION['user_id'] ?? $_SESSION['id'];
        $val = json_encode([
            'left'   => trim($_POST['footer_left']   ?? ''),
            'center' => trim($_POST['footer_center'] ?? ''),
            'right'  => trim($_POST['footer_right']  ?? ''),
        ]);
        $chk = $pdo->prepare("SELECT id FROM user_page_settings WHERE user_id=? AND page_code='shipping_analysis' AND setting_key='kpi_footer'");
        $chk->execute([$uid]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE user_page_settings SET setting_value=?, updated_at=NOW() WHERE user_id=? AND page_code='shipping_analysis' AND setting_key='kpi_footer'")->execute([$val, $uid]);
        } else {
            $pdo->prepare("INSERT INTO user_page_settings (user_id, page_code, setting_key, setting_value, updated_at) VALUES (?,?,?,?,NOW())")->execute([$uid, 'shipping_analysis', 'kpi_footer', $val]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

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
$sql .= " isl.Specification, isl.Content, isl.Qty, isl.Unit_price, isl.Order_id,";
$sql .= " isl.Warehouse, isl.Note, isl.sale_type, isl.billing_month_override, isl.anomaly_confirmed,";
$sql .= " ds.Spec_No AS d_spec_no,";
$sql .= " ist.sale_type_name, ist.is_count, ist.exclude_anomaly, ist.exclude_top10,";
$sql .= " ($_q_bom_sub) AS bom_list,";
$sql .= " ($_q_som_sub) AS is_shipment_mapped,";
$sql .= " cl.settlement_mode, cl.settlement_day";
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

// 判斷圖表顯示單位 (日/週/月)
$date_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
$chart_group_by = 'day';
if ($date_diff > 60) {
    $chart_group_by = 'month';
} elseif ($date_diff > 28) {
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

// 金額格式化：>= 10000 顯示萬，否則顯示元
function fmt_amt(float $v): string {
    $v = round($v);
    if (abs($v) >= 10000) {
        $n = $v / 10000;
        return '$' . (fmod($n, 1) == 0 ? intval($n) : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.')) . '萬';
    }
    return '$' . number_format((int)$v, 0) . '元';
}

// 全域帳款月份 / 接單月份計算（依系統截止日）
// cutoff_day=0 表示整月歸同月；1~31 則「> cutoff_day」歸下月
function compute_billing_month_global(string $date_str, int $cutoff_day): string {
    $ts = strtotime($date_str);
    $y  = intval(date('Y', $ts));
    $m  = intval(date('n', $ts));
    $d  = intval(date('j', $ts));
    if ($cutoff_day > 0 && $d > $cutoff_day) {
        if ($m == 12) return sprintf('%04d-01', $y + 1);
        return sprintf('%04d-%02d', $y, $m + 1);
    }
    return sprintf('%04d-%02d', $y, $m);
}

foreach ($rows as &$row) {
    $row['billing_month'] = !empty($row['billing_month_override'])
        ? $row['billing_month_override']
        : compute_billing_month_global($row['Order_date'], $global_cutoff_day);
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
            $key = $row['billing_month'];
        } elseif ($chart_group_by == 'week') {
            $key = date('Y/m/d', strtotime('monday this week', strtotime($date)));
        }
        if (!isset($chart_stats[$key])) $chart_stats[$key] = 0;
        $chart_stats[$key] += ($amount / 10000);
    // }

    // 熱銷產品統計（排除 exclude_top10=1 的出貨性質）
    if (empty($row['exclude_top10'])) {
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
    }
}

unset($row);

// 排序並取前5名
arsort($client_stats);
$top_clients = array_slice($client_stats, 0, 5, true);

// 熱銷產品排序
uasort($product_stats, function($a, $b) {
    return $b['amount'] <=> $a['amount'];
});
$top_products = array_slice($product_stats, 0, 10, true);

// 十大熱銷已排除的出貨性質名稱
$top10_excluded_names = [];
foreach ($sale_types as $st) {
    if (!empty($st['exclude_top10'])) {
        $top10_excluded_names[] = htmlspecialchars($st['sale_type_name']);
    }
}

// 出貨性質圖表數據
$sale_type_chart_data = [];
foreach ($sale_type_stats as $name => $val) {
    $sale_type_chart_data[] = ['name' => $name, 'y' => (float)number_format($val / 10000, 2, '.', '')];
}

ksort($chart_stats);

// ── 查詢 order_track 接單月份金額 ──
$order_chart_stats = [];
$total_order_amount = 0;
$total_order_qty    = 0;
$order_count        = 0;
try {
    $stmt_ot = $conn->getPDO()->prepare(
        "SELECT Delivery_date, Qty, unit_price
         FROM order_track
         WHERE Delivery_date BETWEEN :s AND :e
           AND (Order_status IS NULL OR Order_status != 9)
           AND unit_price IS NOT NULL AND unit_price > 0"
    );
    $stmt_ot->execute([':s' => $start_date, ':e' => $end_date]);
    foreach ($stmt_ot->fetchAll(PDO::FETCH_ASSOC) as $otr) {
        $otr_month = compute_billing_month_global($otr['Delivery_date'], $global_cutoff_day);
        $odate = $otr['Delivery_date'];
        if ($chart_group_by == 'month') {
            $okey = $otr_month;
        } elseif ($chart_group_by == 'week') {
            $ots  = strtotime($odate);
            $oday = date('N', $ots);
            $okey = date('Y/m/d', strtotime('-' . ($oday - 1) . ' days', $ots));
        } else {
            $okey = $odate;
        }
        $oamt = floatval($otr['Qty']) * floatval($otr['unit_price']);
        $total_order_amount += $oamt;
        $total_order_qty    += floatval($otr['Qty']);
        $order_count++;
        if (!isset($order_chart_stats[$okey])) $order_chart_stats[$okey] = 0;
        $order_chart_stats[$okey] += ($oamt / 10000);
    }
    ksort($order_chart_stats);
} catch (Exception $_ote) {}

// ── 查詢 ir_track 退貨月份金額 ──
$return_chart_stats = [];
$total_return_amount = 0;
$total_return_qty    = 0;
$return_count        = 0;
try {
    $stmt_ir = $conn->getPDO()->prepare(
        "SELECT IR_date, Qty, Unit_price
         FROM ir_track
         WHERE IR_date BETWEEN :s AND :e
           AND Unit_price IS NOT NULL AND Unit_price > 0"
    );
    $stmt_ir->execute([':s' => $start_date, ':e' => $end_date]);
    foreach ($stmt_ir->fetchAll(PDO::FETCH_ASSOC) as $ir) {
        $ir_bm  = compute_billing_month_global($ir['IR_date'], $global_cutoff_day);
        $irdate = $ir['IR_date'];
        if ($chart_group_by == 'month') {
            $irkey = $ir_bm;
        } elseif ($chart_group_by == 'week') {
            $irts  = strtotime($irdate);
            $irday = date('N', $irts);
            $irkey = date('Y/m/d', strtotime('-' . ($irday - 1) . ' days', $irts));
        } else {
            $irkey = $irdate;
        }
        $iramt = floatval($ir['Qty']) * floatval($ir['Unit_price']);
        $total_return_amount += $iramt;
        $total_return_qty    += floatval($ir['Qty']);
        $return_count++;
        if (!isset($return_chart_stats[$irkey])) $return_chart_stats[$irkey] = 0;
        $return_chart_stats[$irkey] += ($iramt / 10000);
    }
    ksort($return_chart_stats);
} catch (Exception $_ire) {}

// 合併兩個 series 的 x 軸日期
// 月視圖：補全日期範圍內的所有月份（無資料補0），避免空月份顯示為數字索引
if ($chart_group_by === 'month') {
    $all_chart_dates = [];
    $_cy = (int)date('Y', strtotime($start_date));
    $_cm = (int)date('n', strtotime($start_date));
    $_ey = (int)date('Y', strtotime($end_date));
    $_em = (int)date('n', strtotime($end_date));
    while ($_cy < $_ey || ($_cy === $_ey && $_cm <= $_em)) {
        $all_chart_dates[] = sprintf('%04d-%02d', $_cy, $_cm);
        if (++$_cm > 12) { $_cm = 1; ++$_cy; }
    }
} else {
    $all_chart_dates = array_values(array_unique(array_merge(
        array_keys($chart_stats), array_keys($order_chart_stats), array_keys($return_chart_stats)
    )));
    sort($all_chart_dates);
}

// 準備圖表數據 (對齊後)
$chart_dates          = $all_chart_dates;
$chart_values         = array_map(function($k) use($chart_stats)       { return round($chart_stats[$k]        ?? 0, 2); }, $all_chart_dates);
$order_chart_values   = array_map(function($k) use($order_chart_stats) { return round($order_chart_stats[$k]  ?? 0, 2); }, $all_chart_dates);
$return_chart_values  = array_map(function($k) use($return_chart_stats){ return round($return_chart_stats[$k] ?? 0, 2); }, $all_chart_dates);

// ── 批次預查哪些料號有圖檔，標記到每列 has_files ──
try {
    require_once __DIR__ . '/../../src/common/bom_dir_lib.php';   // 資料夾位置走設定鍵 bom_scan_dir，不再寫死 Z: 磁碟機代號
    $_scan_dir = eg_bom_scan_dir_auto();
    $_pids_ok  = [];
    $_all_pids = array_values(array_unique(array_filter(
        array_column($rows, 'Product_id'),
        function($p){ $p = trim((string)$p); return $p !== '' && $p !== '0' && $p !== '1'; }
    )));
    if (!empty($_all_pids)) {
        $_ph  = implode(',', array_fill(0, count($_all_pids), '?'));
        $_bst = $conn->getPDO()->prepare("SELECT DISTINCT d_id, bom FROM bom WHERE d_id IN ($_ph)");
        $_bst->execute($_all_pids);
        $_bom_map = [];
        foreach ($_bst->fetchAll(PDO::FETCH_ASSOC) as $_b) {
            $_bom_map[$_b['d_id']][] = $_b['bom'];
        }
        if (!empty($_bom_map)) {
            if (is_dir($_scan_dir)) {
                $_img_exts  = ['jpg','jpeg','png','pdf'];
                $_img_files = array_filter(scandir($_scan_dir), function($f) use($_img_exts){
                    return !in_array($f, ['.','..']) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $_img_exts);
                });
                foreach ($_bom_map as $_pid => $_boms) {
                    foreach ($_boms as $_bom) {
                        foreach ($_img_files as $_fname) {
                            if (strpos($_fname, $_bom) === 0) { $_pids_ok[$_pid] = true; break 2; }
                        }
                    }
                }
            } else {
                // NAS 不可存取：有 BOM 就視為可能有圖
                foreach (array_keys($_bom_map) as $_pid) { $_pids_ok[$_pid] = true; }
            }
        }
    }
    foreach ($rows as &$_r) {
        $_pid = trim((string)($_r['Product_id'] ?? ''));
        $_r['has_files'] = isset($_pids_ok[$_pid]) ? 1 : 0;
    }
    unset($_r);
} catch (Exception $_fe) {
    foreach ($rows as &$_r) { $_r['has_files'] = 0; }
    unset($_r);
}

// ── 批次查詢 d_setting_gear 齒輪規格 ──
try {
    $_gear_map = [];
    $_dsids = array_values(array_unique(array_filter(
        array_column($rows, 'd_setting_id'),
        function($v){ return !empty($v) && intval($v) > 0; }
    )));
    if (!empty($_dsids)) {
        $_gph = implode(',', array_fill(0, count($_dsids), '?'));
        $_gsql =
            "SELECT g.d_setting_id,
                GROUP_CONCAT(
                    TRIM(CONCAT(
                        IF(g.Module IS NOT NULL AND g.Module<>'',
                           IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M',g.Module)), ''),
                        IF(g.Teeth IS NOT NULL AND g.Teeth>0, CONCAT(' T',g.Teeth), ''),
                        IF(g.Face_Width IS NOT NULL AND g.Face_Width>0,
                           CONCAT(' W',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR)))), ''),
                        IF(g.Helix_Direction IS NOT NULL AND g.Helix_Direction<>'',
                           CONCAT(' ', g.Helix_Direction,
                                  COALESCE(IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle>0,
                                     COALESCE(NULLIF(g.Helix_Angle_Str,''),
                                        TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR)))),
                                     NULL), '')),
                           IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle>0,
                              CONCAT(' ', COALESCE(NULLIF(g.Helix_Angle_Str,''),
                                 TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR))))), ''))
                    ))
                    ORDER BY g.gear_id SEPARATOR ' / '
                ) AS gear_spec
             FROM d_setting_gear g
             WHERE g.d_setting_id IN ($_gph)
             GROUP BY g.d_setting_id";
        $_gst = $conn->getPDO()->prepare($_gsql);
        $_gst->execute(array_map('intval', $_dsids));
        foreach ($_gst->fetchAll(PDO::FETCH_ASSOC) as $_gr) {
            $_gear_map[intval($_gr['d_setting_id'])] = $_gr['gear_spec'];
        }
    }
    foreach ($rows as &$_r) {
        $_dsid = intval($_r['d_setting_id'] ?? 0);
        $_r['gear_spec'] = $_dsid ? ($_gear_map[$_dsid] ?? '') : '';
    }
    unset($_r);
} catch (Exception $_ge) {
    foreach ($rows as &$_r) { $_r['gear_spec'] = ''; }
    unset($_r);
}

$shipping_data_json = json_encode($rows);

// ── 查詢退貨單列表（ir_track）──
$ir_rows = [];
try {
    $ir_stmt = $conn->getPDO()->prepare(
        "SELECT it.IR_id, it.IR_no, it.IR_date, it.Client_name,
                COALESCE(ds.D_Setting_Id, it.d_id) AS Product_id,
                it.d_id, it.d_setting_id, it.Specification, it.Qty,
                COALESCE(it.Unit_price, 0) AS Unit_price,
                COALESCE(it.Qty * it.Unit_price, 0) AS amount,
                it.IR_ps, it.IR_status, it.billing_month_override,
                COALESCE(cl.customer, it.Client_name) AS Client_name_display
         FROM ir_track it
         LEFT JOIN d_setting ds ON it.d_setting_id = ds.d_id
         LEFT JOIN customer_list cl ON ds.Customer_Id = cl.customer_id
         WHERE it.IR_date BETWEEN :s AND :e
         ORDER BY it.IR_date DESC, it.IR_id DESC"
    );
    $ir_stmt->execute([':s' => $start_date, ':e' => $end_date]);
    foreach ($ir_stmt->fetchAll(PDO::FETCH_ASSOC) as $ir_r) {
        $ir_r['billing_month'] = !empty($ir_r['billing_month_override'])
            ? $ir_r['billing_month_override']
            : compute_billing_month_global($ir_r['IR_date'], $global_cutoff_day);
        $ir_rows[] = $ir_r;
    }
} catch (Exception $_ire2) {}
$ir_data_json = json_encode($ir_rows);

// ── 查詢訂單列表（order_track）──
$order_rows = [];
try {
    $ord_stmt = $conn->getPDO()->prepare(
        "SELECT ot.Order_id, ot.Order_oo, ot.Delivery_date, ot.Order_date,
                ot.Client_name, ot.d_id AS Product_id,
                ot.Specification, ot.Processing_items, ot.Order_ps, ot.Qty,
                COALESCE(ot.unit_price, 0) AS Unit_price,
                COALESCE(ot.Qty * ot.unit_price, 0) AS amount,
                ot.Order_status,
                COALESCE(cl.customer, ot.Client_name) AS Client_name_display
         FROM order_track ot
         LEFT JOIN customer_list cl ON ot.Client_name_ID = cl.customer_id
         WHERE ot.Delivery_date BETWEEN :s AND :e
           AND (ot.Order_status IS NULL OR ot.Order_status != 9)
           AND ot.unit_price IS NOT NULL AND ot.unit_price > 0
         ORDER BY ot.Delivery_date DESC, ot.Order_id DESC"
    );
    $ord_stmt->execute([':s' => $start_date, ':e' => $end_date]);
    $order_rows_raw = $ord_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($order_rows_raw as $ord_r) {
        $ord_r['billing_month'] = compute_billing_month_global($ord_r['Delivery_date'], $global_cutoff_day);
        $order_rows[] = $ord_r;
    }
} catch (Exception $_ore) {}
$order_data_json = json_encode($order_rows);

// ── 查詢前五大客戶的產業別 ──
// 邏輯：$top_clients key = is_list.Client_name；但 customer_list 用 customer 欄位才能串 mapping
// 先從 $rows 建立「customer_list.customer → is_list.Client_name」對照表，再查產業別
$client_industry_map = []; // [is_list.Client_name => '產業A、產業B']
try {
    // 建立 display_name（customer_list.customer）→ is_list.Client_name 的對照
    $_display_to_orig = []; // customer_list.customer → is_list.Client_name
    foreach ($rows as $_r) {
        $orig = $_r['Client_name'] ?? '';
        $disp = $_r['Client_name_display'] ?? $orig;
        if ($orig !== '') {
            $_display_to_orig[$disp] = $orig; // 同一 display 可能有多個 orig，保留最後一個即可
        }
    }

    // 只查前五大客戶（取 display_name 版本）
    $_top_orig = array_keys($top_clients); // is_list.Client_name
    $_query_names = [];
    foreach ($_display_to_orig as $_disp => $_orig) {
        if (in_array($_orig, $_top_orig)) {
            $_query_names[] = $_disp;
        }
    }
    // 同時也直接用 is_list.Client_name 查（以防 display=orig）
    $_query_names = array_values(array_unique(array_merge($_query_names, $_top_orig)));

    if (!empty($_query_names)) {
        $_ph = implode(',', array_fill(0, count($_query_names), '?'));
        $_si = $conn->getPDO()->prepare(
            "SELECT cl.customer,
                    GROUP_CONCAT(dit.industry_name ORDER BY dit.sort_order SEPARATOR '、') AS industries,
                    GROUP_CONCAT(DISTINCT NULLIF(cim.note,'') ORDER BY dit.sort_order SEPARATOR '；') AS industry_notes
             FROM customer_list cl
             JOIN customer_industry_mapping cim ON cl.customer_id = cim.customer_id
             JOIN dict_industry_type dit ON cim.industry_id = dit.industry_id
             WHERE cl.customer IN ($_ph) AND (dit.is_active IS NULL OR dit.is_active = 1)
             GROUP BY cl.customer_id, cl.customer"
        );
        $_si->execute($_query_names);
        foreach ($_si->fetchAll(PDO::FETCH_ASSOC) as $_ir) {
            $cl_name = $_ir['customer'];
            $_is_name = $_display_to_orig[$cl_name] ?? $cl_name;
            $client_industry_map[$_is_name] = [
                'industries' => $_ir['industries'],
                'notes'      => $_ir['industry_notes'] ?? ''
            ];
        }
    }
} catch (Exception $_cie) {}
$client_industry_map_json = json_encode($client_industry_map, JSON_UNESCAPED_UNICODE);

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
    <title>出貨紀錄分析（帳款月份）</title>

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
        /* ── 全域微調 ── */
        .right_col { background: #f0f2f5; padding: 4px 14px 20px !important; }
        .highlight-row { background-color: #fff3cd !important; transition: background-color .5s; }

        /* ── Toast ── */
        #sa-toast-container { position:fixed; top:20px; right:20px; z-index:99999; }
        .sa-toast {
            min-width:240px; max-width:360px; padding:10px 16px; border-radius:6px;
            color:#fff; font-size:13px; font-weight:500;
            box-shadow:0 4px 14px rgba(0,0,0,.18); margin-bottom:6px;
            display:flex; align-items:center; gap:10px; animation:sa-slidein .22s ease;
        }
        .sa-toast.success { background:#27ae60; }
        .sa-toast.danger  { background:#e74c3c; }
        .sa-toast.info    { background:#2980b9; }
        @keyframes sa-slidein { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:none} }

        /* ── 頁面標題列 ── */
        .san-header {
            display:flex; align-items:center; justify-content:space-between;
            padding:10px 16px;
            background:linear-gradient(135deg,#1a2634 0%,#2c3e50 100%);
            border-radius:8px; margin-bottom:8px;
            box-shadow:0 2px 8px rgba(0,0,0,.18);
        }
        .san-header h3 { margin:0; font-size:15px; font-weight:700; color:#ecf0f1; }
        .san-header small { font-size:11px; color:#8fa0b4; margin-left:6px; }
        .san-header .perm-tag {
            font-size:10px; color:#bdc3c7; margin-left:8px;
            background:rgba(255,255,255,.12); padding:2px 8px; border-radius:10px; cursor:pointer;
            border:1px solid rgba(255,255,255,.18);
        }
        .san-header .btn-group-header { display:flex; gap:5px; }
        .san-header .btn-group-header .btn-default {
            background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.22); color:#ecf0f1;
        }
        .san-header .btn-group-header .btn-default:hover { background:rgba(255,255,255,.22); }
        .san-header .btn-group-header .btn-warning { background:#e67e22; border-color:#d35400; color:#fff; }
        .san-header .btn-group-header .btn-danger  { background:#c0392b; border-color:#a93226; color:#fff; }

        /* ── 查詢 Bar ── */
        .san-filter-bar {
            background:#fff; border:1px solid #dce1e7; border-radius:8px;
            padding:7px 14px; margin-bottom:8px;
            box-shadow:0 1px 4px rgba(0,0,0,.05);
        }
        .san-filter-bar .filter-row {
            display:flex; align-items:center; flex-wrap:wrap; gap:6px 10px;
        }
        .san-filter-bar label { font-size:11px; color:#5d6d7e; margin:0; white-space:nowrap; }
        .san-filter-bar .form-control { height:28px; font-size:12px; border-radius:4px; }
        .san-filter-bar .quick-btns { display:flex; flex-wrap:wrap; gap:3px; }
        .san-filter-bar .quick-btns .btn { font-size:11px; padding:2px 7px; border-radius:12px; }
        .san-filter-bar .quick-btns .btn-quickdate.active-quick {
            background:#1a5276 !important; border-color:#1a5276 !important;
            color:#fff !important; font-weight:700;
            box-shadow:0 0 0 2px rgba(26,82,118,.25);
        }
        .san-filter-bar .select2-container--default .select2-selection--multiple {
            border-radius:4px; font-size:12px; min-height:28px; max-height:52px;
            overflow-y:auto; border-color:#3498db; background:#f0f7ff;
        }
        .san-filter-bar .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color:#2980b9; box-shadow:0 0 0 2px rgba(52,152,219,.18);
        }
        /* 選取後的 pill 標籤 */
        .san-filter-bar .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background:#3498db; border-color:#2471a3;
            color:#fff; border-radius:10px; padding:1px 8px; font-size:11px; margin:2px 2px;
        }
        .san-filter-bar .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color:rgba(255,255,255,.75); margin-right:4px; font-size:13px;
        }
        .san-filter-bar .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
            color:#fff;
        }
        .san-filter-divider { border-left:1px solid #dce1e7; height:24px; margin:0 3px; }

        /* ── 帳款月份概覽列 ── */
        .bm-section { margin-bottom:8px; }
        .bm-section-title {
            font-size:11px; color:#7f8c8d; font-weight:600;
            text-transform:uppercase; letter-spacing:.5px;
            margin-bottom:5px; display:flex; align-items:center; gap:6px;
        }
        .bm-cards-wrap {
            display:flex; gap:6px; overflow-x:auto;
            padding-bottom:3px;
        }
        .bm-cards-wrap::-webkit-scrollbar { height:3px; }
        .bm-cards-wrap::-webkit-scrollbar-thumb { background:#ccc; border-radius:2px; }
        .bm-card {
            flex:0 0 auto; min-width:92px;
            background:#fff; border:1px solid #dce1e7; border-radius:6px;
            padding:7px 10px; cursor:pointer;
            box-shadow:0 1px 3px rgba(0,0,0,.05);
            transition:all .18s;
            border-top:3px solid #3498db;
        }
        .bm-card:hover { box-shadow:0 2px 8px rgba(52,152,219,.2); border-top-color:#2980b9; }
        .bm-card.active { background:#ebf5fb; border-top-color:#1a5276; box-shadow:0 2px 6px rgba(26,82,118,.18); }
        .bm-card .bm-label { font-size:10px; color:#95a5a6; margin-bottom:1px; }
        .bm-card .bm-month { font-size:13px; font-weight:700; color:#2c3e50; }
        .bm-card .bm-amount { font-size:11px; color:#2980b9; margin-top:2px; }
        .bm-card .bm-count { font-size:10px; color:#95a5a6; }
        .bm-card-all {
            flex:0 0 auto; min-width:68px;
            background:#f8f9fa; border:1px dashed #ced4da; border-radius:6px;
            padding:7px 10px; cursor:pointer; display:flex;
            align-items:center; justify-content:center;
            font-size:11px; color:#5d6d7e; font-weight:600;
            transition:all .18s;
        }
        .bm-card-all.active, .bm-card-all:hover { background:#fff; border-color:#3498db; color:#2980b9; }
        .bm-override-btn {
            flex:0 0 auto; background:#f8f0ff; border:1px dashed #8e44ad; border-radius:6px;
            padding:5px 10px; cursor:pointer; display:none; flex-direction:column;
            align-items:center; justify-content:center; font-size:10px; color:#6c3483;
            transition:all .18s; gap:1px;
        }
        .bm-override-btn:hover, .bm-override-btn.active { background:#fff; border-color:#6c3483; border-style:solid; }
        .bm-override-btn .bm-ov-label { font-weight:700; font-size:11px; }
        .bm-override-btn .bm-ov-sub { color:#8e44ad; font-size:9px; }

        /* ── 統計磚 ── */
        .san-stats-row { display:flex; gap:8px; margin-bottom:8px; flex-wrap:wrap; }
        .san-stat {
            flex:1; min-width:130px;
            background:#fff; border-radius:8px; padding:10px 14px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            border-left:4px solid #3498db;
        }
        .san-stat:nth-child(2) { border-left-color:#27ae60; }
        .san-stat:nth-child(3) { border-left-color:#e67e22; }
        .san-stat:nth-child(4) { border-left-color:#9b59b6; }
        .san-stat { cursor:pointer; transition:box-shadow .15s, transform .1s, border .15s; border:2px solid transparent; border-left-width:4px !important; }
        .san-stat:hover { box-shadow:0 3px 10px rgba(0,0,0,.12); transform:translateY(-1px); }
        .san-stat.active-tab { transform:translateY(-2px); border-width:2px !important; border-left-width:4px !important; border-style:solid !important; }
        /* 退貨/訂單表頭顏色 */
        #returnTable thead th, #orderListTable thead th {
            background:#1a2634; color:#ecf0f1; white-space:nowrap; padding:7px 8px; font-size:12px;
        }
        .san-stat .s-label { font-size:10px; color:#95a5a6; text-transform:uppercase; letter-spacing:.3px; margin-bottom:3px; }
        .san-stat .s-value { font-size:20px; font-weight:700; color:#2c3e50; line-height:1.2; display:flex; align-items:baseline; gap:4px; flex-wrap:wrap; }
        .san-stat .s-unit-inline { font-size:11px; font-weight:400; color:#aab; }
        .san-stat .s-sub   { font-size:10px; color:#b0b8c4; margin-top:3px; line-height:1.4; }
        /* Tab 切換按鈕 */
        .list-tabs { display:flex; border-bottom:2px solid #eef0f2; margin-bottom:0; }
        .list-tab-btn { padding:7px 16px; font-size:13px; font-weight:600; color:#7f8c8d; cursor:pointer; border:none; background:none; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .18s; }
        .list-tab-btn:hover { color:#2c3e50; }
        .list-tab-btn.active { color:#2980b9; border-bottom-color:#2980b9; }

        /* ── 圖表面板 ── */
        .san-panel {
            background:#fff; border-radius:8px; border:1px solid #e8ecf0;
            box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:8px; overflow:hidden;
        }
        .san-panel-head {
            padding:7px 12px; background:#fff; border-bottom:1px solid #eef0f2;
            display:flex; align-items:center; justify-content:space-between;
        }
        .san-panel-head h4 {
            margin:0; font-size:13px; font-weight:600; color:#2c3e50;
            display:flex; align-items:center; gap:6px;
        }
        .san-panel-head h4 i { color:#3498db; }
        .san-panel-body { padding:8px 10px; }
        #analysis-chart { height:215px; }
        #sale-type-chart { height:215px; }

        /* ── 排名表格 ── */
        .rank-table { width:100%; border-collapse:collapse; font-size:12px; }
        .rank-table thead th {
            background:#f8f9fa; color:#5d6d7e; font-weight:600;
            padding:6px 8px; border-bottom:2px solid #eee; white-space:nowrap;
        }
        .rank-table tbody tr:hover { background:#f5f8ff; }
        .rank-table tbody td { padding:6px 8px; border-bottom:1px solid #f0f0f0; }
        .rank-table .rank-no {
            width:28px; height:28px; line-height:28px; text-align:center;
            border-radius:50%; font-weight:700; font-size:11px; display:inline-block;
        }
        .rank-no.r1 { background:#f39c12; color:#fff; }
        .rank-no.r2 { background:#7f8c8d; color:#fff; }
        .rank-no.r3 { background:#cd7f32; color:#fff; }
        .rank-no.rn { background:#ecf0f1; color:#5d6d7e; }

        /* ── 表格容器 ── */
        .san-table-wrap { overflow-x:auto; }
        #shippingTable thead th {
            background:#1a2634; color:#ecf0f1; font-weight:600;
            font-size:12px; border-color:#283747 !important;
            white-space:nowrap; padding:8px 10px;
        }
        #shippingTable tbody tr:hover { background:#f0f7ff !important; }
        #shippingTable tbody td { font-size:12px; padding:6px 10px; vertical-align:middle; }
        #shippingTable tbody tr.selected td { background:#d6eaf8 !important; }

        /* 帳款月份欄特殊樣式 */
        #shippingTable td.col-bm {
            font-weight:600; font-size:12px;
            background:#ebf5fb !important;
            color:#1a5276;
            text-align:center;
            padding:4px 8px;
        }
        #shippingTable tr:hover td.col-bm { background:#d6eaf8 !important; }
        .bm-badge {
            display:inline-block; padding:2px 8px; border-radius:10px;
            background:#2980b9; color:#fff; font-size:11px; font-weight:600;
        }

        /* ── 表格上方篩選列 ── */
        #external-filter-container {
            background:#fff; border:1px solid #e0e4ea; border-radius:8px;
            padding:8px 12px; margin-bottom:8px;
            box-shadow:0 1px 3px rgba(0,0,0,.06);
        }
        .ef-row { display:flex; align-items:center; flex-wrap:wrap; gap:5px 10px; }
        .ef-row + .ef-row { margin-top:6px; padding-top:6px; border-top:1px dashed #ecf0f1; }
        .ef-group { display:flex; align-items:center; gap:4px; flex-shrink:0; }
        .ef-label {
            font-size:10px; color:#7f8c8d; white-space:nowrap;
            font-weight:700; text-transform:uppercase; letter-spacing:.3px;
        }
        .ef-group input.form-control, .ef-group select.form-control {
            height:26px; font-size:11.5px; border-radius:4px; padding:2px 7px; border-color:#dce1e7;
        }
        .ef-group input.form-control:focus, .ef-group select.form-control:focus {
            border-color:#3498db; box-shadow:0 0 0 2px rgba(52,152,219,.12);
        }
        .ef-sep { color:#dce1e7; font-size:16px; margin:0 2px; align-self:center; }
        /* 每頁筆數控制 */
        .page-len-wrap { display:flex; align-items:center; gap:5px; font-size:12px; color:#5d6d7e; white-space:nowrap; }
        .page-len-wrap select.form-control {
            width:60px !important; height:26px; font-size:12px; padding:2px 4px; display:inline-block;
        }
        /* 篩選狀態列 */
        .fsb-tag { display:inline-block; background:#fff8e1; border:1px solid #ffcc80; border-radius:10px; padding:1px 8px; font-size:11px; color:#7c4d00; }
        /* 客戶自動完成 */
        #client-autocomplete .ac-item { padding:5px 10px; cursor:pointer; white-space:nowrap; color:#2c3e50; }
        #client-autocomplete .ac-item:hover, #client-autocomplete .ac-item.ac-active { background:#ebf5fb; }
        #client-autocomplete .ac-item .ac-id { font-size:10px; color:#999; margin-left:5px; }
        /* 翻頁按鈕緊湊化 */
        #dt-pagination-holder .dataTables_paginate { margin:0; padding:0; }
        #dt-pagination-holder .pagination { margin:0; font-size:12px; }
        #dt-pagination-holder .pagination > li > a,
        #dt-pagination-holder .pagination > li > span { padding:3px 8px; line-height:1.4; }
        /* 出貨性質 Select2 in filter */
        #external-filter-container .select2-container { min-width:120px !important; max-width:180px !important; }
        #external-filter-container .select2-container--default .select2-selection--multiple {
            min-height:26px; max-height:26px; overflow:hidden; font-size:11.5px; border-color:#dce1e7; border-radius:4px;
        }
        #external-filter-container .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background:#3498db; border-color:#2471a3; color:#fff; border-radius:8px;
            padding:0 6px; font-size:10px; margin:2px 2px; line-height:18px;
        }

        /* ── 批次面板 ── */
        #batch-update-panel {
            background:#e8f4f8; border-left:4px solid #3498db;
            border-radius:0 6px 6px 0; padding:8px 12px; margin-bottom:8px;
        }

        /* ── 綁定 Modal ── */
        .bind-section-title {
            font-size:13px; font-weight:600; color:#2c3e50;
            padding:6px 0 4px; border-bottom:2px solid #3498db; margin-bottom:10px;
        }
        .bind-result-table { margin:0; }
        .bind-result-table td { font-size:12px; padding:4px 6px !important; }
        .bind-current-badge {
            display:inline-block; padding:3px 10px;
            border-radius:4px; font-size:12px; font-weight:600;
        }
        .x_panel { border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,.07); }
        .x_title { border-bottom:1px solid #eee; background:#fafbfc; border-radius:8px 8px 0 0; }
    </style>
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <!-- 選單 -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <!-- 頁面內容 -->
            <div class="right_col" role="main">
                <div style="padding:2px 6px 20px;">

                    <!-- ① 標題列 -->
                    <div class="san-header">
                        <div>
                            <h3><i class="fa fa-calendar-check-o" style="color:#3498db;"></i> 出貨紀錄分析
                                <small>— 帳款月份版</small>
                                <?php if (!empty($permission_display_text)): ?>
                                <span class="perm-tag"
                                      data-toggle="popover" data-trigger="hover" data-placement="bottom"
                                      data-content="<?= htmlspecialchars($permission_tooltip_text) ?>">
                                    <?= htmlspecialchars($permission_display_text) ?>
                                </span>
                                <?php endif; ?>
                            </h3>
                        </div>
                        <div class="btn-group-header">
                            <button type="button" class="btn btn-default btn-sm" onclick="openCutoffModal()" title="設定帳款/接單月份截止日">
                                <i class="fa fa-calendar-check-o"></i> 月份截止日
                                <?php if ($global_cutoff_day > 0): ?>
                                <span style="background:#2980b9; color:#fff; border-radius:8px; font-size:9px; padding:1px 5px; margin-left:2px;"><?= $global_cutoff_day ?>日</span>
                                <?php endif; ?>
                            </button>
                            <button type="button" class="btn btn-default btn-sm" onclick="openSaleTypeModal()">
                                <i class="fa fa-tags"></i> 出貨性質設定
                            </button>
                            <button type="button" class="btn btn-warning btn-sm" onclick="performLocalAnalysis()">
                                <i class="fa fa-exclamation-triangle"></i> 異常偵測
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="printAnalysisReport(false)">
                                <i class="fa fa-file-pdf-o"></i> 列印PDF報表
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="printAnalysisReport(true)" title="含出貨明細列表">
                                <i class="fa fa-file-pdf-o"></i> 含明細
                            </button>
                            <button type="button" class="btn btn-default btn-sm" id="btn-kpi-toggle" onclick="toggleKpiPanel()">
                                <i class="fa fa-table"></i> KPI週報
                            </button>
                        </div>
                    </div>

                    <!-- ② 查詢 Bar -->
                    <div class="san-filter-bar">
                        <form method="GET" action="" id="filterForm">
                            <div class="filter-row">
                                <label><i class="fa fa-calendar"></i> 出貨日期</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>" onchange="this.form.submit()" style="width:130px;">
                                <span style="color:#aaa; font-size:12px;">─</span>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>" onchange="this.form.submit()" style="width:130px;">

                                <div class="san-filter-divider"></div>

                                <label><i class="fa fa-tag"></i> 出貨性質</label>
                                <select name="filter_sale_types[]" id="filter_sale_types" multiple="multiple" style="width:200px;">
                                    <option value="NULL" <?php if (in_array('NULL', $filter_sale_types)) echo 'selected'; ?>>一般產品</option>
                                    <?php foreach ($sale_types as $st):
                                        $is_count_label = (isset($st['is_count']) && $st['is_count'] == 0) ? ' (不統計)' : '';
                                    ?>
                                    <option value="<?= $st['sale_type_id'] ?>" <?php if (in_array(strval($st['sale_type_id']), $filter_sale_types, true)) echo 'selected'; ?>>
                                        <?= htmlspecialchars($st['sale_type_name']) . $is_count_label ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-default btn-xs" onclick="saveDefaultSaleTypes()" title="設為預設">
                                    <i class="fa fa-save"></i>
                                </button>
                                <button type="button" class="btn btn-primary btn-xs" id="btn-reload-saletype" onclick="$('#filterForm').submit()" title="重新從資料庫載入">
                                    <i class="fa fa-refresh"></i> 套用
                                </button>

                                <div class="san-filter-divider"></div>

                                <div class="quick-btns">
                                    <div class="btn-group btn-group-xs">
                                        <button type="button" class="btn btn-default btn-xs" onclick="shiftPeriod('month',-1)" title="前一個帳款月">&#9664;</button>
                                        <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="lastMonth" onclick="setQuickDate('lastMonth')">上月</button>
                                        <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="thisMonth" onclick="setQuickDate('thisMonth')">本月</button>
                                        <button type="button" class="btn btn-default btn-xs" onclick="shiftPeriod('month',1)" title="後一個帳款月">&#9654;</button>
                                    </div>
                                    <div class="btn-group btn-group-xs">
                                        <button type="button" class="btn btn-default btn-xs" onclick="shiftPeriod('year',-1)" title="前一年">&#9664;</button>
                                        <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="lastYear" onclick="setQuickDate('lastYear')">去年</button>
                                        <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="thisYear" onclick="setQuickDate('thisYear')">今年</button>
                                        <button type="button" class="btn btn-default btn-xs" onclick="shiftPeriod('year',1)" title="後一年">&#9654;</button>
                                    </div>
                                    <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="h1" onclick="setQuickDate('h1')">上半年</button>
                                    <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="h2" onclick="setQuickDate('h2')">下半年</button>
                                    <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="q1" onclick="setQuickDate('q1')">Q1</button>
                                    <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="q2" onclick="setQuickDate('q2')">Q2</button>
                                    <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="q3" onclick="setQuickDate('q3')">Q3</button>
                                    <button type="button" class="btn btn-default btn-xs btn-quickdate" data-quick="q4" onclick="setQuickDate('q4')">Q4</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- ③ 帳款月份概覽卡片列 -->
                    <div class="bm-section">
                        <div class="bm-section-title">
                            <i class="fa fa-calendar-o" style="color:#3498db;"></i> 帳款月份概覽
                            <span id="bm-filter-hint" style="font-size:11px; color:#e67e22; display:none;">
                                <i class="fa fa-filter"></i> 已篩選
                            </span>
                        </div>
                        <div class="bm-cards-wrap" id="bm-cards-wrap">
                            <div class="bm-card-all active" id="bm-card-all" onclick="clearBmFilter()">
                                <i class="fa fa-list"></i> 全部
                            </div>
                            <div class="bm-override-btn" id="bm-override-btn" onclick="toggleOverrideFilter()" title="僅顯示已手動調整帳款月份的出貨記錄">
                                <div class="bm-ov-label"><i class="fa fa-pencil-square-o"></i> 已調整</div>
                                <div class="bm-ov-sub" id="bm-override-sub">0 筆</div>
                            </div>
                            <!-- JS 動態填入 -->
                        </div>
                    </div>

                    <!-- ④ 統計磚 -->
                    <?php
                        // 接單金額顯示
                        $ord_amt_disp = $total_order_amount >= 100000000
                            ? number_format($total_order_amount / 100000000, 2) : number_format($total_order_amount / 10000, 2);
                        $ord_unit = $total_order_amount >= 100000000 ? '億' : '萬';
                        // 出貨金額顯示
                        $ship_amt_disp = $total_amount >= 100000000
                            ? number_format($total_amount / 100000000, 2) : number_format($total_amount / 10000, 2);
                        $ship_unit = $total_amount >= 100000000 ? '億' : '萬';
                        // 退貨金額顯示
                        $ret_amt_disp = $total_return_amount >= 100000000
                            ? number_format($total_return_amount / 100000000, 2) : number_format($total_return_amount / 10000, 2);
                        $ret_unit = $total_return_amount >= 100000000 ? '億' : '萬';
                        // 總結款項
                        $net_amount = $total_amount - $total_return_amount;
                        $net_amt_disp = $net_amount >= 100000000
                            ? number_format($net_amount / 100000000, 2) : number_format($net_amount / 10000, 2);
                        $net_unit = $net_amount >= 100000000 ? '億' : '萬';
                    ?>
                    <div class="san-stats-row">
                        <!-- 接單 -->
                        <div class="san-stat" id="stat-card-order" style="border-left-color:#27ae60;" onclick="switchListTab('order')" title="點選切換顯示接單列表">
                            <div class="s-label"><i class="fa fa-file-text-o"></i> 接單</div>
                            <div class="s-value"><span id="stat-order-amount">$<?= $ord_amt_disp ?></span><span class="s-unit-inline" id="stat-order-unit"><?= $ord_unit ?> TWD</span></div>
                            <div class="s-sub" id="stat-order-sub"><?= number_format($total_order_qty) ?> pcs ／ <?= number_format($order_count) ?> 筆</div>
                        </div>
                        <!-- 出貨 -->
                        <div class="san-stat active-tab" id="stat-card-ship" style="border-left-color:#3498db; color:#3498db;" onclick="switchListTab('ship')" title="點選切換顯示出貨列表">
                            <div class="s-label"><i class="fa fa-truck"></i> 出貨</div>
                            <div class="s-value"><span id="stat-ship-amount">$<?= $ship_amt_disp ?></span><span class="s-unit-inline" id="stat-ship-unit"><?= $ship_unit ?> TWD</span></div>
                            <div class="s-sub" id="stat-ship-sub"><?= number_format($total_qty) ?> pcs ／ <?= number_format($valid_shipment_count) ?> 筆</div>
                        </div>
                        <!-- 退貨 -->
                        <div class="san-stat" id="stat-card-return" style="border-left-color:#e74c3c;" onclick="switchListTab('return')" title="點選切換顯示退貨單列表">
                            <div class="s-label"><i class="fa fa-undo"></i> 退貨</div>
                            <div class="s-value"><span id="stat-return-amount">$<?= $ret_amt_disp ?></span><span class="s-unit-inline" id="stat-return-unit"><?= $ret_unit ?> TWD</span></div>
                            <div class="s-sub" id="stat-return-sub"><?= number_format($total_return_qty) ?> pcs ／ <?= number_format($return_count) ?> 筆</div>
                        </div>
                        <!-- 總結款項 -->
                        <div class="san-stat" id="stat-card-net" style="border-left-color:#9b59b6;">
                            <div class="s-label"><i class="fa fa-calculator"></i> 總結款項 <small style="font-size:9px;">(出貨－退貨)</small></div>
                            <div class="s-value"><span id="stat-net-amount">$<?= $net_amt_disp ?></span><span class="s-unit-inline" id="stat-net-unit"><?= $net_unit ?> TWD</span></div>
                            <div class="s-sub">&nbsp;</div>
                        </div>
                    </div>

                    <!-- ⑤-0 篩選狀態列（有篩選時顯示，方便列印確認） -->
                    <div id="filter-status-bar" style="display:none; margin-bottom:6px; padding:5px 10px; background:#fff3e0; border:1px solid #ffcc80; border-radius:6px; font-size:11px; color:#7c4d00; display:none;">
                        <i class="fa fa-filter" style="margin-right:5px; color:#e67e22;"></i>
                        <strong>出貨明細篩選中：</strong>
                        <span id="filter-status-content"></span>
                    </div>

                    <!-- ⑤ 趨勢圖 (全寬) + 出貨性質圓餅 -->
                    <div class="row" style="margin-bottom:0; margin-left:-6px; margin-right:-6px;">
                        <div class="col-md-8 col-sm-12" style="padding-left:6px; padding-right:6px;">
                            <div class="san-panel">
                                <div class="san-panel-head">
                                    <h4><i class="fa fa-bar-chart"></i>
                                        帳款月份 相關金額趨勢
                                        <small style="font-weight:400; color:#95a5a6; font-size:11px;">(<?= $chart_group_by == 'month' ? '月' : ($chart_group_by == 'week' ? '週' : '日') ?>)</small>
                                    </h4>
                                </div>
                                <div class="san-panel-body">
                                    <div id="analysis-chart"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-12" style="padding-left:6px; padding-right:6px;">
                            <div class="san-panel">
                                <div class="san-panel-head">
                                    <h4><i class="fa fa-pie-chart"></i> 出貨性質佔比</h4>
                                </div>
                                <div class="san-panel-body">
                                    <div id="sale-type-chart"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ⑥ 前五大客戶 + 十大熱銷 -->
                    <div class="row" style="margin-bottom:0; margin-left:-6px; margin-right:-6px;">
                        <div class="col-md-4 col-sm-12" style="padding-left:6px; padding-right:6px;">
                            <div class="san-panel">
                                <div class="san-panel-head">
                                    <h4 id="top-clients-title"><i class="fa fa-trophy"></i> 前五大出貨客戶</h4>
                                </div>
                                <div class="san-panel-body" style="padding:0 0 4px;">
                                    <table class="rank-table">
                                        <thead>
                                            <tr>
                                                <th width="36">排名</th>
                                                <th>客戶</th>
                                                <th>產業別</th>
                                                <th class="text-right">金額</th>
                                            </tr>
                                        </thead>
                                        <tbody id="top-clients-body">
                                            <?php $rc = 1; foreach ($top_clients as $client => $amount):
                                                $_ci = $client_industry_map[$client] ?? null;
                                                $ind  = is_array($_ci) ? htmlspecialchars($_ci['industries'] ?? '') : htmlspecialchars($_ci ?? '');
                                                $note = is_array($_ci) ? htmlspecialchars($_ci['notes'] ?? '') : ''; ?>
                                            <tr>
                                                <td><span class="rank-no <?= $rc<=3?'r'.$rc:'rn' ?>"><?= $rc++ ?></span></td>
                                                <td><?= htmlspecialchars($client) ?></td>
                                                <td style="font-size:11px; color:#5d6d7e;"><?= $ind ?: '<span style="color:#ccc;">—</span>' ?><?= $note ? ' <span style="font-size:10px;color:#aaa;">(' . $note . ')</span>' : '' ?></td>
                                                <td class="text-right" style="color:#2980b9; font-weight:600;"><?= fmt_amt($amount) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8 col-sm-12" style="padding-left:6px; padding-right:6px;">
                            <div class="san-panel">
                                <div class="san-panel-head">
                                    <h4 id="top-products-title"><i class="fa fa-star"></i> 十大熱銷產品<?php if (!empty($top10_excluded_names)): ?> <small style="font-size:10px; color:#e67e22; font-weight:400;">(已排除 <?= implode('、', $top10_excluded_names) ?>)</small><?php endif; ?></h4>
                                </div>
                                <div class="san-panel-body" style="padding:0 0 4px; max-height:220px; overflow-y:auto;">
                                    <table class="rank-table">
                                        <thead>
                                            <tr>
                                                <th width="36">排名</th>
                                                <th>料號</th>
                                                <th class="text-right">金額</th>
                                                <th class="text-right" id="top-products-avg-th">平均出貨量(pcs)</th>
                                                <th class="text-right" id="top-products-count-th">出貨次數</th>
                                            </tr>
                                        </thead>
                                        <tbody id="top-products-tbody">
                                            <?php $rank = 1; foreach ($top_products as $pid => $stats):
                                                $avg_qty = $stats['count'] > 0 ? $stats['qty'] / $stats['count'] : 0; ?>
                                            <tr>
                                                <td><span class="rank-no <?= $rank<=3?'r'.$rank:'rn' ?>"><?= $rank++ ?></span></td>
                                                <td><?php if (!empty($_pids_ok[$pid])): ?><a href="javascript:void(0);" onclick="openProductFiles('<?= htmlspecialchars($pid) ?>')" style="color:#337ab7;"><?= htmlspecialchars($pid) ?></a><?php else: ?><?= htmlspecialchars($pid) ?><?php endif; ?></td>
                                                <td class="text-right" style="color:#2980b9; font-weight:600;"><?= fmt_amt($stats['amount']) ?></td>
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

                    <!-- ⑥.5 KPI 週報面板（可折疊） -->
                    <div id="kpi-panel" style="display:none; margin-top:8px;">
                        <div class="san-panel" style="border:2px solid #2980b9;">
                            <div class="san-panel-head" style="background:linear-gradient(135deg,#1a2634,#2c3e50);">
                                <h4 style="margin:0; color:#ecf0f1;"><i class="fa fa-table" style="color:#3498db;"></i> 月份出貨 KPI 週報</h4>
                                <div style="display:flex; align-items:center; gap:6px; flex-shrink:0; flex-wrap:wrap;">
                                    <select class="form-control" id="kpi-year" style="width:80px; height:26px; font-size:12px; padding:2px 4px; color:#2c3e50;"></select>
                                    <select class="form-control" id="kpi-month" style="width:60px; height:26px; font-size:12px; padding:2px 4px; color:#2c3e50;">
                                        <?php for ($i=1;$i<=12;$i++): ?><option value="<?=$i?>"><?=$i?>月</option><?php endfor; ?>
                                    </select>
                                    <span style="color:#aaa; font-size:11px;">目標(萬)</span>
                                    <input type="number" id="kpi-target" class="form-control" style="width:90px; height:26px; font-size:12px; padding:2px 6px; color:#2c3e50;" placeholder="月目標(萬)">
                                    <span style="color:#aaa; font-size:11px;">起始日</span>
                                    <input type="number" id="kpi-start-day" class="form-control" style="width:50px; height:26px; font-size:12px; padding:2px 4px; color:#2c3e50;" min="1" max="31" placeholder="<?= $global_cutoff_day > 0 ? $global_cutoff_day + 1 : 1 ?>">
                                    <button class="btn btn-primary btn-xs" onclick="saveAndLoadKpi()"><i class="fa fa-save"></i> 儲存</button>
                                    <button class="btn btn-default btn-xs" onclick="loadKpiData()"><i class="fa fa-refresh"></i></button>
                                    <button class="btn btn-danger btn-xs" onclick="printKpiReport()"><i class="fa fa-print"></i> 列印</button>
                                    <button class="btn btn-default btn-xs" onclick="toggleKpiPanel()"><i class="fa fa-times"></i></button>
                                </div>
                            </div>
                            <div class="san-panel-body" style="padding:10px;" id="kpi-body">
                                <!-- 表尾設定列 -->
                                <div style="display:flex; align-items:center; gap:6px; margin-bottom:8px; padding:6px 10px; background:#f8f9fa; border-radius:4px; flex-wrap:wrap;">
                                    <span style="font-size:11px; color:#7f8c8d; font-weight:600; flex-shrink:0;"><i class="fa fa-stamp"></i> 表尾設定</span>
                                    <span style="font-size:11px; color:#aaa;">左</span>
                                    <input type="text" id="kpi-footer-left" class="form-control" style="width:130px; height:24px; font-size:11px; padding:2px 6px;" placeholder="左下蓋章欄位說明">
                                    <span style="font-size:11px; color:#aaa;">中</span>
                                    <input type="text" id="kpi-footer-center" class="form-control" style="width:130px; height:24px; font-size:11px; padding:2px 6px;" placeholder="中下蓋章欄位說明">
                                    <span style="font-size:11px; color:#aaa;">右</span>
                                    <input type="text" id="kpi-footer-right" class="form-control" style="width:130px; height:24px; font-size:11px; padding:2px 6px;" placeholder="右下蓋章欄位說明">
                                    <button class="btn btn-primary btn-xs" onclick="saveKpiFooter()" title="儲存表尾設定"><i class="fa fa-save"></i></button>
                                </div>
                                <div id="kpi-loading" style="text-align:center; padding:30px; color:#999;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>
                                <div id="kpi-content" style="display:none;">
                                    <!-- 摘要列 -->
                                    <div id="kpi-summary-row" style="display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;"></div>
                                    <!-- 表格 + 圖表 -->
                                    <div style="display:grid; grid-template-columns:1fr 360px; gap:10px; align-items:start;">
                                        <div>
                                            <table class="table table-bordered table-condensed" id="kpi-table" style="font-size:12px; margin:0;">
                                                <thead id="kpi-thead"></thead>
                                                <tbody id="kpi-tbody"></tbody>
                                                <tfoot id="kpi-tfoot"></tfoot>
                                            </table>
                                        </div>
                                        <div>
                                            <div id="kpi-chart" style="height:260px;"></div>
                                        </div>
                                    </div>
                                    <!-- 大額前三名 -->
                                    <div id="kpi-large-section" style="margin-top:14px;"></div>
                                    <!-- 表尾蓋章區 -->
                                    <div id="kpi-footer-display" style="display:none;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ⑦ 明細表格 -->
                    <div class="san-panel" style="margin-top:8px;">
                        <div id="detail-panel-head" class="san-panel-head" style="padding:0 10px 0 0; align-items:stretch; transition:background .25s;">
                            <div class="list-tabs" style="flex:1;">
                                <button class="list-tab-btn active" id="tab-btn-ship" onclick="switchListTab('ship')"><i class="fa fa-truck"></i> 出貨明細</button>
                                <button class="list-tab-btn" id="tab-btn-return" onclick="switchListTab('return')"><i class="fa fa-undo"></i> 退貨單</button>
                                <button class="list-tab-btn" id="tab-btn-order" onclick="switchListTab('order')"><i class="fa fa-file-text-o"></i> 訂單</button>
                                <button class="list-tab-btn" id="tab-btn-summary" onclick="switchListTab('summary')"><i class="fa fa-users"></i> 客戶統計</button>
                            </div>
                            <div id="list-tab-tools" style="display:flex; align-items:center; gap:4px; padding:4px 0;">
                                <button type="button" class="btn btn-default btn-xs" id="btn-dt-copy">Copy</button>
                                <button type="button" class="btn btn-default btn-xs" id="btn-dt-csv">CSV</button>
                            </div>
                        </div>
                        <div class="san-panel-body" style="padding:8px 10px;">
                            <!-- 篩選列 -->
                            <div id="external-filter-container">
                                <!-- 唯一篩選列 -->
                                <div class="ef-row">
                                    <div class="ef-group">
                                        <span class="ef-label">日期</span>
                                        <input type="text" id="filter-date" class="form-control" placeholder=">2026-01" style="width:92px;" title="支援 > < = 運算子">
                                    </div>
                                    <span class="ef-sep">│</span>
                                    <div class="ef-group" style="position:relative;">
                                        <span class="ef-label">客戶/ID</span>
                                        <input type="text" id="filter-client" class="form-control" placeholder="名稱或客戶編號" style="width:130px;" autocomplete="off" title="模糊搜尋客戶名稱或ID">
                                        <div id="client-autocomplete" style="display:none; position:absolute; top:100%; left:0; z-index:999; background:#fff; border:1px solid #dce1e7; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,.12); max-height:200px; overflow-y:auto; min-width:180px; font-size:12px;"></div>
                                    </div>
                                    <span class="ef-sep">│</span>
                                    <div class="ef-group">
                                        <span class="ef-label">出貨性質</span>
                                        <select id="filter-sale-type" class="form-control" multiple="multiple">
                                            <option value="NULL">一般產品</option>
                                            <?php foreach ($sale_types as $st): ?>
                                            <option value="<?= $st['sale_type_id'] ?>"><?= htmlspecialchars($st['sale_type_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <span class="ef-sep">│</span>
                                    <div class="ef-group">
                                        <span class="ef-label">料號</span>
                                        <input type="text" id="filter-product" class="form-control" placeholder="料號" style="width:100px;">
                                    </div>
                                    <span class="ef-sep">│</span>
                                    <div class="ef-group">
                                        <span class="ef-label">規格</span>
                                        <input type="text" id="filter-spec" class="form-control" placeholder="規格" style="width:90px;">
                                    </div>
                                    <span class="ef-sep">│</span>
                                    <div class="ef-group" style="flex:1; min-width:160px;">
                                        <span class="ef-label" title="搜尋：出貨單號、料號、備註、加工內容"><i class="fa fa-search" style="color:#3498db;"></i> 全域</span>
                                        <input type="text" id="global-search" class="form-control" placeholder="單號/料號/備註/內容" style="width:160px;">
                                    </div>
                                    <button type="button" class="btn btn-warning btn-sm" id="btn-filter-zero-price" onclick="toggleZeroPriceFilter(this)" style="height:26px; padding:2px 8px; font-size:12px; flex-shrink:0;" title="快速篩選：單價=0 且 出貨性質為「納入統計」的資料">
                                        <i class="fa fa-exclamation-circle"></i> 單價=0（統計中）
                                    </button>
                                    <button type="button" class="btn btn-default btn-sm" id="clear-filters" style="margin-left:auto; height:26px; padding:2px 10px; font-size:12px; flex-shrink:0;">
                                        <i class="fa fa-times"></i> 清除
                                    </button>
                                </div>
                            </div>
                            <!-- 工具列：Copy/CSV/Excel 左側，每頁＋翻頁右側 -->
                            <div id="dt-toolbar" style="display:flex; justify-content:space-between; align-items:center; gap:6px; padding:4px 0 3px; border-bottom:1px solid #f0f2f5; margin-bottom:4px;">
                                <div style="display:flex; align-items:center; gap:4px;">
                                    <button type="button" class="btn btn-default btn-xs" id="btn-dt-copy">Copy</button>
                                    <button type="button" class="btn btn-default btn-xs" id="btn-dt-csv">CSV</button>
                                </div>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <div class="page-len-wrap">
                                        每頁
                                        <select class="form-control" id="page-length-select">
                                            <option value="15" selected>15</option>
                                            <option value="20">20</option>
                                            <option value="50">50</option>
                                        </select>
                                        筆
                                    </div>
                                    <div id="dt-pagination-holder"></div>
                                </div>
                            </div>
                            <!-- 批次面板 -->
                            <div id="batch-update-panel" style="display:none;">
                                <div class="form-inline" style="font-size:13px;">
                                    <i class="fa fa-check-square-o"></i>
                                    已選取 <span id="selected-count" style="font-weight:bold; color:#d9534f;">0</span> 筆 &nbsp;
                                    <label style="margin:0;">改出貨性質為：</label>
                                    <select class="form-control input-sm" id="main_batch_sale_type_select" style="max-width:180px; display:inline-block;">
                                        <option value="NULL">一般產品</option>
                                        <?php foreach ($sale_types as $st): ?>
                                        <option value="<?= $st['sale_type_id'] ?>" data-is-count="<?= $st['is_count'] ?>" data-exclude-anomaly="<?= $st['exclude_anomaly'] ?>"><?= htmlspecialchars($st['sale_type_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="submitMainBatchUpdate()">執行</button>
                                    <?php if ($perm_can_delete): ?>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="submitBatchDelete()"><i class="fa fa-trash"></i> 刪除</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-default btn-sm" onclick="clearSelection()">取消選取</button>
                                </div>
                            </div>
                            <!-- 主表格區域（Tab 切換） -->
                            <!-- Tab: 出貨明細 -->
                            <div id="tab-content-ship" class="tab-content-pane">
                            <div class="san-table-wrap">
                                <table id="shippingTable" class="table table-bordered dt-responsive nowrap" cellspacing="0" width="100%">
                                    <thead>
                                        <tr>
                                            <th style="display:none;">ID</th>
                                            <th width="55"><input type="checkbox" id="check-all" title="全選本頁"> 操作</th>
                                            <th>帳款月份</th>
                                            <th>出貨日期</th>
                                            <th>出貨單號</th>
                                            <th>客戶名稱</th>
                                            <th>出貨性質</th>
                                            <th>料號</th>
                                            <th>規格</th>
                                            <th>數量</th>
                                            <th>單價</th>
                                            <th>總價</th>
                                            <th style="display:none;">倉庫</th>
                                            <th>備註</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            </div><!-- /tab-content-ship -->

                            <!-- Tab: 退貨單 -->
                            <div id="tab-content-return" class="tab-content-pane" style="display:none;">
                                <table id="returnTable" class="table table-bordered dt-responsive nowrap" cellspacing="0" width="100%">
                                    <thead><tr>
                                        <th width="40">操作</th><th>帳款月份</th><th>退貨日期</th><th>退貨單號</th><th>客戶名稱</th>
                                        <th>料號</th><th>規格</th>
                                        <th class="text-right">數量</th><th class="text-right">單價</th><th class="text-right">金額</th>
                                        <th>原因</th>
                                    </tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>

                            <!-- Tab: 訂單 -->
                            <div id="tab-content-order" class="tab-content-pane" style="display:none;">
                                <table id="orderListTable" class="table table-bordered dt-responsive nowrap" cellspacing="0" width="100%">
                                    <thead><tr>
                                        <th>帳款月份</th><th>交貨日期</th><th>訂單號</th><th>客戶名稱</th>
                                        <th>料號</th><th>製程</th>
                                        <th class="text-right">數量</th><th class="text-right">單價</th><th class="text-right">金額</th>
                                        <th>備註</th>
                                    </tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>

                            <!-- Tab: 客戶統計 -->
                            <div id="tab-content-summary" class="tab-content-pane" style="display:none;">
                                <div style="margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                                    <span id="summary-period-label" style="font-size:12px;color:#5d6d7e;"></span>
                                </div>
                                <div style="overflow-x:auto;">
                                    <table id="clientSummaryTable" class="table table-bordered table-hover table-condensed" style="margin:0;font-size:12px;">
                                        <thead style="background:#1a2634;color:#ecf0f1;">
                                            <tr>
                                                <th>客戶名稱</th>
                                                <th class="text-right">出貨金額</th>
                                                <th class="text-right">退貨金額</th>
                                                <th class="text-right">訂單金額</th>
                                                <th class="text-right">淨額（出貨－退貨）</th>
                                                <th class="text-right" id="summary-prev-col">前期增減</th>
                                            </tr>
                                        </thead>
                                        <tbody id="client-summary-tbody"></tbody>
                                        <tfoot id="client-summary-tfoot"></tfoot>
                                    </table>
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

    <!-- 分析結果 Modal -->
    <style>
        #analysisResultModal .modal-dialog { max-width: 960px; width: 96vw; }
        #analysis-nav-sidebar { width: 82px; min-width: 82px; overflow-y: auto; border-right: 1px solid #e0e4ea; background: #f4f6f9; padding: 8px 4px; flex-shrink: 0; }
        .anav-btn { display: block; width: 100%; margin-bottom: 5px; padding: 7px 4px; border: none; border-radius: 5px; background: #fff; color: #2c3e50; font-size: 11px; text-align: center; cursor: pointer; line-height: 1.3; box-shadow: 0 1px 3px rgba(0,0,0,.08); transition: background .15s; }
        .anav-btn:hover { background: #e8f4f8; color: #1a73e8; }
        .anav-btn.anav-has-issue { border-left: 3px solid #e74c3c; }
        .anav-btn.anav-ok { border-left: 3px solid #27ae60; }
        .anav-btn .anav-count { display: block; font-size: 13px; font-weight: 700; margin-bottom: 2px; }
    </style>
    <div class="modal fade" id="analysisResultModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-search"></i> 異常偵測報告</h4>
                </div>
                <div class="modal-body" style="padding: 0; max-height: 82vh; display: flex; flex-direction: column;">
                    <div id="analysis-result-header" style="padding: 12px 15px 8px 15px; background-color: #fff; z-index: 10; border-bottom: 1px solid #eee; flex-shrink:0;"></div>
                    <div style="display:flex; flex:1; min-height:0; overflow:hidden;">
                        <div id="analysis-nav-sidebar"></div>
                        <div id="analysis-result-body" style="overflow-y: auto; padding: 14px; flex: 1;"></div>
                    </div>
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
                                <label>帳款月份 <small class="text-muted">（留空則依出貨日期自動計算）</small></label>
                                <div class="input-group">
                                    <input type="month" class="form-control" id="edit_billing_month" name="billing_month_override">
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn-default" onclick="$('#edit_billing_month').val('')" title="清除（恢復自動計算）"><i class="fa fa-times"></i></button>
                                    </span>
                                </div>
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
                                <label>內容 <small class="text-muted">(Content，品名規格後半)</small></label>
                                <input type="text" class="form-control" id="edit_content" name="Content">
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

    <!-- 退貨帳款月份編輯 Modal -->
    <div class="modal fade" id="irBillingMonthModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">修改退貨帳款月份</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="ir_bm_ir_id">
                    <div class="form-group">
                        <label>帳款月份 <small class="text-muted">（留空則依退貨日期自動計算）</small></label>
                        <div class="input-group">
                            <input type="month" class="form-control" id="ir_bm_value">
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default" onclick="$('#ir_bm_value').val('')" title="清除（恢復自動計算）"><i class="fa fa-times"></i></button>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveIrBillingMonth()">儲存</button>
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
                                            <label><input type="checkbox" id="st_exclude_anomaly" name="exclude_anomaly" value="1"> 排除異常檢測（全部排除）</label>
                                        </div>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" id="st_exclude_when_nonzero" name="exclude_when_nonzero" value="1">
                                                <span style="color:#c0392b; font-weight:600;">金額 &gt; 0 視為異常</span>
                                                <small class="text-muted" style="display:block; margin-left:20px; line-height:1.3;">
                                                    勾選後：此性質若金額=0 → 正常排除；金額&gt;0 → 列入異常偵測
                                                </small>
                                            </label>
                                        </div>
                                        <div class="checkbox">
                                            <label><input type="checkbox" id="st_exclude_top10" name="exclude_top10" value="1"> 排除十大熱銷產品</label>
                                        </div>
                                        <div class="checkbox">
                                            <label><input type="checkbox" id="st_active" name="is_active" value="1" checked> 啟用</label>
                                        </div>
                                        <hr>
                                        <div class="form-group">
                                            <label>標籤顏色</label>
                                            <input type="hidden" id="st_color" value="#ffffff">
                                            <div id="color-palette" style="display:flex; flex-wrap:wrap; gap:4px; margin-top:4px;">
                                                <?php
                                                $warmColors = ['#c0392b','#e74c3c','#ff6b6b','#e91e63','#f06292',
                                                               '#ff8a65','#e67e22','#ff9800','#ffb300','#f1c40f',
                                                               '#fdd835','#d4ac0d','#a93226','#8d6e63','#795548'];
                                                foreach ($warmColors as $wc): ?>
                                                <div class="color-swatch" data-color="<?= $wc ?>"
                                                     style="width:22px;height:22px;border-radius:4px;background:<?= $wc ?>;cursor:pointer;border:2px solid transparent;box-sizing:border-box;"
                                                     title="<?= $wc ?>"></div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div style="margin-top:4px; display:flex; align-items:center; gap:6px;">
                                                <div id="color-preview" style="width:20px;height:20px;border-radius:3px;border:1px solid #ccc;background:#ffffff;"></div>
                                                <span id="color-hex" style="font-size:11px;color:#666;">#ffffff</span>
                                            </div>
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
                                        <th>排除熱銷</th>
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

    <!-- 月份截止日設定 Modal -->
    <div class="modal fade" id="cutoffModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background:#1a2634; color:#ecf0f1; border-radius:4px 4px 0 0; padding:10px 16px;">
                    <button type="button" class="close" data-dismiss="modal" style="color:#ecf0f1; opacity:.8;"><span>&times;</span></button>
                    <h4 class="modal-title" style="font-size:14px;">
                        <i class="fa fa-calendar-check-o"></i> 帳款 / 接單月份截止日
                    </h4>
                </div>
                <div class="modal-body" style="padding:16px 20px;">
                    <p class="text-muted" style="font-size:12px; margin-bottom:12px; line-height:1.6;">
                        每月截止日決定出貨日期與訂單交期的帳款月份歸屬：<br>
                        <b>≤ 截止日</b>（含當天）→ 本月 &nbsp;|&nbsp; <b>&gt; 截止日</b> → 下月<br>
                        <span style="color:#e67e22;">設為 0 = 不啟用，整月歸同月</span>
                    </p>
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="font-size:12px; color:#5d6d7e;">截止日（0 ~ 31）</label>
                        <input type="number" class="form-control" id="cutoff_day_input"
                               min="0" max="31" value="<?= $global_cutoff_day ?>"
                               style="-webkit-appearance:none; -moz-appearance:textfield; appearance:textfield; font-size:16px; font-weight:700; text-align:center;"
                               oninput="updateCutoffPreview()">
                    </div>
                    <div id="cutoff-preview" style="font-size:12px; color:#2980b9; background:#f0f7ff; border-radius:4px; padding:8px 10px; min-height:36px;"></div>
                </div>
                <div class="modal-footer" style="padding:8px 16px;">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="saveCutoffDay()">
                        <i class="fa fa-save"></i> 儲存設定
                    </button>
                </div>
            </div>
        </div>
    </div>

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
        var shippingData = <?= $shipping_data_json ?>;
        var irData       = <?= $ir_data_json ?>;
        var orderData    = <?= $order_data_json ?>;
        var chartGroupBy = '<?= $chart_group_by ?>';
        var clientIndustryMap = <?= $client_industry_map_json ?>;
        var phpReturnAmount = <?= round($total_return_amount, 2) ?>;
        var currentBmFilter = null; // 目前選取的帳款月份篩選
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
            loadSaleTypes();
            renderBmCards(shippingData); // 初始化帳款月份概覽卡片
            detectActiveQuickDate();     // 高亮對應的快速日期按鈕

            // 初始化 Select2
            $('#filter_sale_types').select2({
                placeholder: "請選擇出貨性質",
                closeOnSelect: false,
                allowClear: true
            }).on('select2:close', function() {
                // 同步到 DataTable 篩選（不重新載入頁面）
                var selected = $('#filter_sale_types').val() || [];
                $('#filter-sale-type').val(selected.length ? selected : null).trigger('change');
            });
        });

        // ── 帳款月份概覽卡片 ──
        var currentOverrideFilter = false;

        function renderBmCards(data) {
            var bmStats = {}, ovCount = 0, ovAmt = 0;
            data.forEach(function(row) {
                if (row.is_count == 0) return;
                var bm = row.billing_month || '';
                if (!bm) return;
                var qty = parseFloat(row.Qty) || 0, price = parseFloat(row.Unit_price) || 0;
                var amt = qty * price;

                // 有手動覆蓋的資料 → 只計入「已調整」按鈕，不建立月份卡片
                if (row.billing_month_override && row.billing_month_override !== '') {
                    ovCount++;
                    if (price > 0) ovAmt += amt;
                    return;
                }

                // 未調整的資料 → 依自然帳款月份分組到月份卡片
                if (!bmStats[bm]) bmStats[bm] = { amount: 0, count: 0 };
                bmStats[bm].amount += amt;
                bmStats[bm].count++;
            });

            // 更新「已調整」按鈕
            if (ovCount > 0) {
                var ovAmtW = ovAmt >= 10000 ? (ovAmt/10000).toFixed(1)+'萬' : Math.round(ovAmt)+'元';
                $('#bm-override-sub').text(ovCount+' 筆 / '+ovAmtW);
                $('#bm-override-btn').css('display','flex');
            } else {
                $('#bm-override-btn').hide().removeClass('active');
                if (currentOverrideFilter) { currentOverrideFilter = false; }
            }

            var sorted = Object.keys(bmStats).sort();
            var wrap = $('#bm-cards-wrap');
            wrap.find('.bm-card').remove();
            sorted.forEach(function(bm) {
                var s = bmStats[bm];
                var amtW = s.amount >= 100000000 ? (s.amount/100000000).toFixed(2)+'億' :
                           s.amount >= 10000 ? (s.amount/10000).toFixed(2)+'萬' :
                           Math.round(s.amount)+'元';
                var card = $('<div class="bm-card" data-bm="'+bm+'">' +
                    '<div class="bm-month">'+bm+'</div>' +
                    '<div class="bm-amount">'+amtW+' TWD</div>' +
                    '<div class="bm-count">'+s.count+' 筆</div>' +
                '</div>');
                card.on('click', function() { applyBmFilter(bm); });
                wrap.append(card);
            });
        }

        function toggleOverrideFilter() {
            if (currentOverrideFilter) {
                // 取消篩選
                currentOverrideFilter = false;
                $('#bm-override-btn').removeClass('active');
                $('#bm-card-all').addClass('active');
                $('#bm-filter-hint').hide();
            } else {
                // 套用篩選
                currentOverrideFilter = true;
                currentBmFilter = null;
                $('#bm-override-btn').addClass('active');
                $('#bm-card-all').removeClass('active');
                $('.bm-card').removeClass('active');
                $('#shippingTable').DataTable().column(2).search('').draw();
                if (_returnTableInited) $('#returnTable').DataTable().column(1).search('').draw();
                if (_orderTableInited) $('#orderListTable').DataTable().column(0).search('').draw();
                $('#bm-filter-hint').show();
                $('html,body').animate({ scrollTop: $('#shippingTable').offset().top - 120 }, 400);
            }
            $('#shippingTable').DataTable().draw();
        }

        function applyBmFilter(bm) {
            currentBmFilter = bm;
            $('.bm-card').removeClass('active');
            $('.bm-card[data-bm="'+bm+'"]').addClass('active');
            $('#bm-card-all').removeClass('active');
            $('#bm-filter-hint').show();
            $('#shippingTable').DataTable().column(2).search('^' + bm + '$', true, false).draw();
            if (_returnTableInited)  $('#returnTable').DataTable().column(1).search('^' + bm + '$', true, false).draw();
            if (_orderTableInited)   $('#orderListTable').DataTable().column(0).search('^' + bm + '$', true, false).draw();
            $('html,body').animate({ scrollTop: $('#shippingTable').offset().top - 120 }, 400);
        }

        function clearBmFilter() {
            currentBmFilter = null;
            currentOverrideFilter = false;
            $('#bm-card-all').addClass('active');
            $('.bm-card').removeClass('active');
            $('#bm-override-btn').removeClass('active');
            $('#bm-filter-hint').hide();
            $('#shippingTable').DataTable().column(2).search('').draw();
            if (_returnTableInited)  $('#returnTable').DataTable().column(1).search('').draw();
            if (_orderTableInited)   $('#orderListTable').DataTable().column(0).search('').draw();
        }

        // ── 帳款期間計算 Helper（模組級，供多處共用）──
        function bmStart(y, m) {
            var c = globalCutoffDay || 0;
            if (c <= 0) return new Date(y, m, 1);
            var pm = m - 1, py = y;
            if (pm < 0) { pm = 11; py--; }
            return new Date(py, pm, c + 1);
        }
        function bmEnd(y, m) {
            var c = globalCutoffDay || 0;
            if (c <= 0) return new Date(y, m + 1, 0);
            return new Date(y, m, c);
        }
        function currentBillingYM() {
            var now = new Date(), c = globalCutoffDay || 0;
            var d = now.getDate(), m = now.getMonth(), y = now.getFullYear();
            if (c > 0 && d > c) { return m === 11 ? {y:y+1, m:0} : {y:y, m:m+1}; }
            return {y:y, m:m};
        }
        function fmtDate(d) {
            var m = '' + (d.getMonth()+1), dy = '' + d.getDate();
            if (m.length < 2) m = '0'+m;
            if (dy.length < 2) dy = '0'+dy;
            return [d.getFullYear(), m, dy].join('-');
        }

        // 快速日期設定（依帳款截止日計算帳款月份）
        function setQuickDate(type) {
            var now = new Date();
            var cur = currentBillingYM();
            var start, end;

            if (type === 'thisMonth') {
                start = bmStart(cur.y, cur.m);
                end   = bmEnd(cur.y, cur.m);
            } else if (type === 'lastMonth') {
                var lm = cur.m - 1, ly = cur.y;
                if (lm < 0) { lm = 11; ly--; }
                start = bmStart(ly, lm);
                end   = bmEnd(ly, lm);
            } else if (type === 'thisYear') {
                start = new Date(now.getFullYear(), 0, 1);
                end   = new Date(now.getFullYear(), 11, 31);
            } else if (type === 'lastYear') {
                start = new Date(now.getFullYear()-1, 0, 1);
                end   = new Date(now.getFullYear()-1, 11, 31);
            } else if (type === 'h1') {
                start = bmStart(now.getFullYear(), 0);
                end   = bmEnd(now.getFullYear(), 5);
            } else if (type === 'h2') {
                start = bmStart(now.getFullYear(), 6);
                end   = bmEnd(now.getFullYear(), 11);
            } else if (type.startsWith('q')) {
                var q = parseInt(type.substring(1));
                var sm = (q-1)*3, em = sm+2;
                start = bmStart(now.getFullYear(), sm);
                end   = bmEnd(now.getFullYear(), em);
            }

            $('#start_date').val(fmtDate(start));
            $('#end_date').val(fmtDate(end));
            document.getElementById('filterForm').submit();
        }

        // 帳款月份前後移動（◀▶ 按鈕）
        function shiftPeriod(unit, dir) {
            var startVal = $('#start_date').val();
            var endVal   = $('#end_date').val();
            if (!startVal) return;
            var s = new Date(startVal + 'T00:00:00');

            if (unit === 'month') {
                // 由 start_date 推算目前帳款月，再位移
                var c = globalCutoffDay || 0;
                var d = s.getDate(), m = s.getMonth(), y = s.getFullYear();
                // 若 start_date 是帳款月起始（cutoff>0 時為前月 cutoff+1），往後推算月份
                var curBmM = m, curBmY = y;
                if (c > 0) {
                    // bmStart(y,m) = Date(prevY, prevM, c+1) → 目前 start 對應帳款月 [y,m]
                    // start 月份加一即為帳款月份（cutoff模式下，bmStart 屬於前月）
                    curBmM = m + 1; curBmY = y;
                    if (curBmM > 11) { curBmM = 0; curBmY++; }
                }
                curBmM += dir; curBmY += Math.floor(curBmM / 12);
                curBmM = ((curBmM % 12) + 12) % 12;
                $('#start_date').val(fmtDate(bmStart(curBmY, curBmM)));
                $('#end_date').val(fmtDate(bmEnd(curBmY, curBmM)));
            } else if (unit === 'year') {
                var y = s.getFullYear() + dir;
                // 判斷目前是否為半年/季或整年
                var e = new Date(endVal + 'T00:00:00');
                var eM = e.getMonth();
                if (eM === 11 && s.getMonth() === 0) {
                    // 整年
                    $('#start_date').val(y + '-01-01');
                    $('#end_date').val(y + '-12-31');
                } else {
                    // 保持相同月份，只換年
                    $('#start_date').val(fmtDate(new Date(y, s.getMonth(), s.getDate())));
                    $('#end_date').val(fmtDate(new Date(y, e.getMonth(), e.getDate())));
                }
            }
            document.getElementById('filterForm').submit();
        }

        // ── 篩選狀態列更新 ──
        function updateFilterStatusBar() {
            var tags = [];
            var date = $('#filter-date').val().trim();
            var client = $('#filter-client').val().trim();
            var product = $('#filter-product').val().trim();
            var spec = $('#filter-spec').val().trim();
            var global = $('#global-search').val().trim();
            var saleTypes = $('#filter-sale-type').val() || [];

            if (date)    tags.push('<span class="fsb-tag">日期: ' + $('<b>').text(date).html() + '</span>');
            if (client)  tags.push('<span class="fsb-tag">客戶: ' + $('<b>').text(client).html() + '</span>');
            if (product) tags.push('<span class="fsb-tag">料號: ' + $('<b>').text(product).html() + '</span>');
            if (spec)    tags.push('<span class="fsb-tag">規格: ' + $('<b>').text(spec).html() + '</span>');
            if (global)  tags.push('<span class="fsb-tag">全域: ' + $('<b>').text(global).html() + '</span>');
            if (saleTypes.length) {
                var stNames = saleTypes.map(function(v) {
                    if (v === 'NULL') return '一般產品';
                    var opt = $('#filter-sale-type option[value="'+v+'"]').text();
                    return opt || v;
                });
                tags.push('<span class="fsb-tag">出貨性質: <b>' + stNames.join('、') + '</b></span>');
            }

            var bar = $('#filter-status-bar');
            if (tags.length) {
                $('#filter-status-content').html(tags.join('&nbsp; '));
                bar.css('display', 'flex').css('align-items', 'center').css('gap', '6px').css('flex-wrap', 'wrap');
            } else {
                bar.hide();
            }
        }

        // 偵測並高亮對應的快速日期按鈕（使用模組級 bmStart/bmEnd）
        function detectActiveQuickDate() {
            var curStart = $('#start_date').val();
            var curEnd   = $('#end_date').val();
            if (!curStart || !curEnd) return;

            var now = new Date();
            var bm = currentBillingYM();
            var lm = bm.m === 0 ? {y:bm.y-1, m:11} : {y:bm.y, m:bm.m-1};
            var ranges = {
                'thisMonth': [fmtDate(bmStart(bm.y, bm.m)),  fmtDate(bmEnd(bm.y, bm.m))],
                'lastMonth': [fmtDate(bmStart(lm.y, lm.m)),  fmtDate(bmEnd(lm.y, lm.m))],
                'thisYear':  [now.getFullYear()+'-01-01', now.getFullYear()+'-12-31'],
                'lastYear':  [(now.getFullYear()-1)+'-01-01', (now.getFullYear()-1)+'-12-31'],
                'h1':        [fmtDate(bmStart(now.getFullYear(), 0)),  fmtDate(bmEnd(now.getFullYear(), 5))],
                'h2':        [fmtDate(bmStart(now.getFullYear(), 6)),  fmtDate(bmEnd(now.getFullYear(), 11))],
                'q1':        [fmtDate(bmStart(now.getFullYear(), 0)),  fmtDate(bmEnd(now.getFullYear(), 2))],
                'q2':        [fmtDate(bmStart(now.getFullYear(), 3)),  fmtDate(bmEnd(now.getFullYear(), 5))],
                'q3':        [fmtDate(bmStart(now.getFullYear(), 6)),  fmtDate(bmEnd(now.getFullYear(), 8))],
                'q4':        [fmtDate(bmStart(now.getFullYear(), 9)),  fmtDate(bmEnd(now.getFullYear(), 11))]
            };
            $('.btn-quickdate').removeClass('active-quick');
            for (var key in ranges) {
                if (ranges[key][0] === curStart && ranges[key][1] === curEnd) {
                    $('.btn-quickdate[data-quick="'+key+'"]').addClass('active-quick');
                    break;
                }
            }
        }

        // 自定義日期搜尋函數
        $.fn.dataTable.ext.search.push(
            function(settings, data, dataIndex) {
                if (['shippingTable','returnTable','orderListTable'].indexOf(settings.sTableId) === -1) return true;
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

                // 出貨 col3(id+ops+bm+date)、退貨 col2(ops+bm+date)、訂單 col1(bm+date)
                var tid = settings.sTableId;
                var rowDateStr = tid === 'returnTable' ? data[2] : (tid === 'orderListTable' ? data[1] : data[3]);
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

        // 取得圖表日期分組 Key (日/週/月)
        // row: DataTable 的完整行資料 (含 billing_month)；dateStr: 只含日期字串
        function getDateKey(dateStr, row) {
            var parts = dateStr.split('-');
            var d = new Date(parts[0], parts[1]-1, parts[2]);
            if (chartGroupBy === 'month') {
                // 月分組使用每行已計算好的帳款月份
                return (row && row.billing_month) ? row.billing_month : (d.getFullYear() + '-' + (d.getMonth()+1 < 10 ? '0' : '') + (d.getMonth()+1));
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
            var productStats = {};
            var pidsWithFiles = {}; // 記錄有圖檔的料號

            data.forEach(function(row) {
                if (row.Product_id && row.has_files == 1) pidsWithFiles[row.Product_id] = true;
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
                
                var key = getDateKey(row.Order_date, row);
                if (!chartStats[key]) chartStats[key] = 0;
                chartStats[key] += (amount / 10000);

                var stName = row.sale_type_name || '一般產品';
                if (!saleTypeStats[stName]) saleTypeStats[stName] = 0;
                saleTypeStats[stName] += amount;

                // 統計熱銷產品（排除 exclude_top10=1 的出貨性質）
                if (row.exclude_top10 != 1) {
                    var pid = row.Product_id || '未知料號';
                    if (pid.trim() === '' || pid === '0' || pid === '1') pid = '未知料號';
                    if (!productStats[pid]) {
                        productStats[pid] = { amount: 0, qty: 0, count: 0 };
                    }
                    productStats[pid].amount += amount;
                    productStats[pid].qty += qty;
                    productStats[pid].count++;
                }
            });
            
            // 出貨卡片
            var shipAmt = totalAmount >= 100000000 ? totalAmount / 100000000 : totalAmount / 10000;
            var shipUnit = totalAmount >= 100000000 ? '億' : '萬';
            $('#stat-ship-amount').text('$' + numberFormat(shipAmt, 2));
            $('#stat-ship-sub').text(numberFormat(totalQty) + ' pcs ／ ' + numberFormat(validCount) + ' 筆');
            $('#stat-ship-unit').text(shipUnit + ' TWD');

            // 退貨/訂單卡片：依當前篩選條件（帳款月份、客戶、料號、全域搜尋）動態計算
            var _ckw = ($('#filter-client').val()  || '').trim().toLowerCase();
            var _pkw = ($('#filter-product').val() || '').trim().toLowerCase();
            var _gkw = ($('#global-search').val()  || '').trim().toLowerCase();

            var returnTotal = 0, returnQty = 0, returnCount = 0;
            irData.forEach(function(r) {
                if (currentBmFilter && r.billing_month !== currentBmFilter) return;
                if (_ckw && (r.Client_name || '').toLowerCase().indexOf(_ckw) === -1) return;
                if (_pkw && (r.Product_id  || '').toLowerCase().indexOf(_pkw) === -1) return;
                if (_gkw) {
                    var _m = (r.IR_no      || '').toLowerCase().indexOf(_gkw) !== -1 ||
                             (r.Product_id || '').toLowerCase().indexOf(_gkw) !== -1 ||
                             (r.IR_ps      || '').toLowerCase().indexOf(_gkw) !== -1;
                    if (!_m) return;
                }
                returnTotal += parseFloat(r.amount) || 0;
                returnQty   += parseFloat(r.Qty)    || 0;
                returnCount++;
            });

            var orderTotal = 0, orderQty = 0, orderCount = 0;
            orderData.forEach(function(r) {
                if (currentBmFilter && r.billing_month !== currentBmFilter) return;
                if (_ckw && (r.Client_name || '').toLowerCase().indexOf(_ckw) === -1) return;
                if (_pkw && (r.Product_id  || '').toLowerCase().indexOf(_pkw) === -1) return;
                if (_gkw) {
                    var _m = (r.Order_oo   || '').toLowerCase().indexOf(_gkw) !== -1 ||
                             (r.Product_id || '').toLowerCase().indexOf(_gkw) !== -1 ||
                             (r.Order_ps   || '').toLowerCase().indexOf(_gkw) !== -1;
                    if (!_m) return;
                }
                orderTotal += parseFloat(r.amount) || 0;
                orderQty   += parseFloat(r.Qty)    || 0;
                orderCount++;
            });

            var retAmt = returnTotal >= 100000000 ? returnTotal / 100000000 : returnTotal / 10000;
            $('#stat-return-amount').text('$' + numberFormat(retAmt, 2));
            $('#stat-return-unit').text((returnTotal >= 100000000 ? '億' : '萬') + ' TWD');
            $('#stat-return-sub').text(numberFormat(returnQty, 0) + ' pcs ／ ' + returnCount + ' 筆');

            var ordAmt = orderTotal >= 100000000 ? orderTotal / 100000000 : orderTotal / 10000;
            $('#stat-order-amount').text('$' + numberFormat(ordAmt, 2));
            $('#stat-order-unit').text((orderTotal >= 100000000 ? '億' : '萬') + ' TWD');
            $('#stat-order-sub').text(numberFormat(orderQty, 0) + ' pcs ／ ' + orderCount + ' 筆');

            // 總結款項（出貨 - 退貨，均為當前篩選後動態值）
            var netAmt = totalAmount - returnTotal;
            var netDisp = netAmt >= 100000000 ? netAmt / 100000000 : netAmt / 10000;
            var netUnit = netAmt >= 100000000 ? '億' : '萬';
            $('#stat-net-amount').text('$' + numberFormat(netDisp, 2));
            $('#stat-net-unit').text(netUnit + ' TWD');
            
            var sortedClients = Object.keys(clientStats).map(function(key) { return [key, clientStats[key]]; });
            sortedClients.sort(function(a, b) { return b[1] - a[1]; });
            var topClientsHtml = '';
            sortedClients.slice(0, 5).forEach(function(item, idx) {
                var rk = idx + 1;
                var rkClass = rk <= 3 ? 'r' + rk : 'rn';
                var safeName = item[0].replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                var indData  = clientIndustryMap[item[0]] || '';
                var indName  = typeof indData === 'object' ? (indData.industries || '') : indData;
                var indNote  = typeof indData === 'object' ? (indData.notes || '') : '';
                var indCell  = indName
                    ? indName.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') +
                      (indNote ? ' <span style="font-size:10px;color:#aaa;">(' + indNote.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + ')</span>' : '')
                    : '<span style="color:#ccc;">—</span>';
                topClientsHtml += '<tr>' +
                    '<td><span class="rank-no ' + rkClass + '">' + rk + '</span></td>' +
                    '<td>' + safeName + '</td>' +
                    '<td style="font-size:11px;color:#5d6d7e;">' + indCell + '</td>' +
                    '<td class="text-right" style="color:#2980b9;font-weight:600;">' + fmtAmt(item[1]) + '</td>' +
                    '</tr>';
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
                
                var pidCell = pidsWithFiles[item.pid]
                    ? '<a href="javascript:void(0);" onclick="openProductFiles(\'' + safePid + '\')" style="text-decoration:underline; color:#337ab7;">' + displayPid + '</a>'
                    : displayPid;
                topProductsHtml += '<tr>' +
                    '<td>' + (rank++) + '</td>' +
                    '<td>' + pidCell + '</td>' +
                    '<td class="text-right">' + fmtAmt(item.amount) + '</td>' +
                    '<td class="text-right">' + numberFormat(avgQty, 1) + '</td>' +
                    '<td class="text-right">' + numberFormat(item.count) + '</td>' +
                    '</tr>';
            });
            $('#top-products-tbody').html(topProductsHtml);

            // 計算退貨/接單趨勢資料（依相同篩選條件）
            var irChartStats = {}, orderChartStats = {};
            irData.forEach(function(r) {
                if (currentBmFilter && r.billing_month !== currentBmFilter) return;
                if (_ckw && (r.Client_name || '').toLowerCase().indexOf(_ckw) === -1) return;
                if (_pkw && (r.Product_id  || '').toLowerCase().indexOf(_pkw) === -1) return;
                if (_gkw) {
                    var _gm = (r.IR_no||'').toLowerCase().indexOf(_gkw) !== -1 ||
                              (r.Product_id||'').toLowerCase().indexOf(_gkw) !== -1 ||
                              (r.IR_ps||'').toLowerCase().indexOf(_gkw) !== -1;
                    if (!_gm) return;
                }
                var _k = getDateKey(r.IR_date, r);
                irChartStats[_k] = (irChartStats[_k] || 0) + (parseFloat(r.amount) || 0) / 10000;
            });
            orderData.forEach(function(r) {
                if (currentBmFilter && r.billing_month !== currentBmFilter) return;
                if (_ckw && (r.Client_name || '').toLowerCase().indexOf(_ckw) === -1) return;
                if (_pkw && (r.Product_id  || '').toLowerCase().indexOf(_pkw) === -1) return;
                if (_gkw) {
                    var _gm2 = (r.Order_oo||'').toLowerCase().indexOf(_gkw) !== -1 ||
                               (r.Product_id||'').toLowerCase().indexOf(_gkw) !== -1 ||
                               (r.Order_ps||'').toLowerCase().indexOf(_gkw) !== -1;
                    if (!_gm2) return;
                }
                var _k2 = getDateKey(r.Delivery_date, r);
                orderChartStats[_k2] = (orderChartStats[_k2] || 0) + (parseFloat(r.amount) || 0) / 10000;
            });

            // 更新趨勢圖三條 series（出貨/接單/退貨，均依篩選更新）
            var trendChart = Highcharts.charts.find(function(c) { return c && c.renderTo && c.renderTo.id === 'analysis-chart'; });
            if (trendChart) {
                var origCats = trendChart.xAxis[0].categories || [];
                trendChart.series[0].setData(origCats.map(function(c) { return parseFloat((chartStats[c]    || 0).toFixed(2)); }), false);
                trendChart.series[1].setData(origCats.map(function(c) { return parseFloat((orderChartStats[c] || 0).toFixed(2)); }), false);
                trendChart.series[2].setData(origCats.map(function(c) { return parseFloat((irChartStats[c]   || 0).toFixed(2)); }), true, { duration: 300 });
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
            // 初始化 DataTable（客戶篩選已改為文字輸入，無需建立選項）

            var table = $('#shippingTable').DataTable({
                dom: 'rtip',
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
                            return '<input type="checkbox" class="row-check" value="' + row.IS_id + '" style="margin-right:5px; vertical-align:middle;"> ' +
                                   '<button type="button" class="btn btn-xs btn-info btn-edit-is"><i class="fa fa-pencil"></i></button>';
                        }
                    },
                    {
                        data: 'billing_month',
                        visible: true,
                        render: function(data, type, row) {
                            if (type !== 'display') return data || '';
                            var hasOverride = row.billing_month_override && row.billing_month_override !== '';
                            return hasOverride
                                ? '<span style="color:#8e44ad;font-weight:600;" title="手動設定">' + (data||'') + ' <i class="fa fa-pencil" style="font-size:9px;"></i></span>'
                                : (data || '');
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
                            var pidDisplay = (row.has_files == 1)
                                ? '<a href="javascript:void(0);" class="btn-pid-files" data-pid="' + safe + '" style="text-decoration:underline; color:#337ab7;">' + safe + '</a>'
                                : '<span>' + safe + '</span>';
                            // 齒輪規格顯示於料號下方
                            var gearSpec = row.gear_spec ? '<div style="font-size:10px; color:#7f8c8d; margin-top:2px; line-height:1.3;" title="' + $('<span>').text(row.gear_spec).html() + '">' + $('<span>').text(row.gear_spec).html() + '</div>' : '';
                            var hasDsetting = row.d_setting_id && row.d_setting_id !== '';
                            var bindIcon = hasDsetting
                                ? ' <i class="fa fa-chain btn-open-bind" style="color:#28a745; cursor:pointer;" data-is-id="' + row.IS_id + '" title="已綁定料號"></i>'
                                : ' <i class="fa fa-chain-broken btn-open-bind" style="color:#dc3545; cursor:pointer;" data-is-id="' + row.IS_id + '" title="未綁定料號"></i>';
                            return pidDisplay + bindIcon + gearSpec;
                        }
                    },
                    {
                        data: 'Specification',
                        render: function(data, type, row) {
                            if (type !== 'display') return (row.d_spec_no || data || '');
                            // 優先顯示 d_setting.Spec_No，其次 is_list.Specification
                            var specText = row.d_spec_no || data || '';
                            var safeSpec = $('<span>').text(specText).html();
                            var contentText = row.Content || '';
                            var safeContent = contentText ? $('<span>').text(contentText).html() : '';
                            var html = safeSpec;
                            if (safeContent) {
                                html += ' <span style="color:#7f8c8d; font-size:10px; border-left:1px solid #ddd; padding-left:5px; margin-left:3px;">' + safeContent + '</span>';
                            }
                            return html || '—';
                        }
                    },
                    { data: 'Qty', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                    { data: 'Unit_price', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                    { data: null, className: 'text-right', render: function(data, type, row) { return $.fn.dataTable.render.number(',', '.', 0).display(row.Qty * row.Unit_price); } },
                    { data: 'Warehouse', visible: false, render: $.fn.dataTable.render.text() },
                    { data: 'Note', render: $.fn.dataTable.render.text() }
                ],
                buttons: [
                    { extend: 'copy', className: 'btn btn-default btn-sm' },
                    { extend: 'csv', className: 'btn btn-default btn-sm' }
                ],
                pageLength: 15,
                lengthChange: false,
                orderCellsTop: true,
                order: [[2, 'asc']], // 預設依出貨日期升序（舊→新）
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

            // 手動按鈕觸發 DataTable 匯出
            $('#btn-dt-copy').on('click', function() { table.button('.buttons-copy').trigger(); });
            $('#btn-dt-csv').on('click', function() { table.button('.buttons-csv').trigger(); });

            // 翻頁控制移到右上角
            $('#shippingTable_paginate').appendTo('#dt-pagination-holder');

            // 每頁筆數控制（右上角）
            $('#page-length-select').on('change', function() {
                table.page.len(parseInt(this.value)).draw();
                // 重新移動翻頁到右上角（draw 後會重建）
                setTimeout(function() {
                    $('#shippingTable_paginate').appendTo('#dt-pagination-holder');
                }, 10);
            });

            // 出貨: col0=id(hidden) col1=操作 col2=帳款月份 col3=日期 col4=單號 col5=客戶 col6=性質 col7=料號 col8=規格 col9=數量 col10=單價 col11=總價 col12=倉庫(hidden) col13=備註
            // 退貨: col0=操作 col1=帳款月份 col2=退貨日期 col3=退貨單號 col4=客戶 col5=料號 col6=規格 col7=數量 col8=單價 col9=金額 col10=原因
            // 訂單: col0=帳款月份 col1=交貨日期 col2=訂單號 col3=客戶 col4=料號 col5=製程 col6=數量 col7=單價 col8=金額 col9=備註
            function _drawOtherTabs() {
                if (_returnTableInited)  $('#returnTable').DataTable().draw();
                if (_orderTableInited)   $('#orderListTable').DataTable().draw();
                if (_currentListTab !== 'ship') updateRankingPanels(_currentListTab);
            }
            $('#filter-date').on('keyup change', function() { table.draw(); _drawOtherTabs(); updateFilterStatusBar(); });
            $('#filter-client').on('keyup change', function() { table.draw(); _drawOtherTabs(); updateFilterStatusBar(); });
            $('#filter-product').on('keyup change', function() {
                var v = this.value;
                table.column(7).search(v).draw();
                if (_returnTableInited)  $('#returnTable').DataTable().column(5).search(v).draw();
                if (_orderTableInited)   $('#orderListTable').DataTable().column(4).search(v).draw();
                if (_currentListTab !== 'ship') updateRankingPanels(_currentListTab);
                updateFilterStatusBar();
            });
            $('#filter-spec').on('keyup change', function() {
                var v = this.value;
                table.column(8).search(v).draw();
                if (_returnTableInited)  $('#returnTable').DataTable().column(6).search(v).draw();
                if (_orderTableInited)   $('#orderListTable').DataTable().column(5).search(v).draw();
                if (_currentListTab !== 'ship') updateRankingPanels(_currentListTab);
                updateFilterStatusBar();
            });
            $('#global-search').on('keyup change', function() { table.draw(); _drawOtherTabs(); updateFilterStatusBar(); });
            $('#filter-sale-type').on('change', function() { updateFilterStatusBar(); });

            // ── 客戶自動完成（查詢 customer_list 資料表）──
            var _acTimer = null;

            function renderClientAC(list) {
                if (!list.length) { $('#client-autocomplete').hide(); return; }
                var html = list.map(function(c) {
                    var safeName = $('<span>').text(c.customer).html();
                    var safeId   = $('<span>').text(c.customer_id).html();
                    return '<div class="ac-item" data-val="' + $('<div>').text(c.customer).html() + '">'
                        + safeName
                        + '<span class="ac-id">' + safeId + '</span>'
                        + '</div>';
                }).join('');
                $('#client-autocomplete').html(html).show();
            }

            function searchClientAC(kw) {
                if (!kw) { $('#client-autocomplete').hide(); return; }
                clearTimeout(_acTimer);
                _acTimer = setTimeout(function() {
                    $.post('', { action: 'search_customer_bind', keyword: kw }, function(res) {
                        var r = typeof res === 'string' ? JSON.parse(res) : res;
                        if (r.success) renderClientAC(r.data);
                        else $('#client-autocomplete').hide();
                    });
                }, 200);
            }

            $('#filter-client').on('keyup', function(e) {
                if (e.key === 'Escape') { $('#client-autocomplete').hide(); return; }
                if (e.key === 'Enter')  { $('#client-autocomplete').hide(); return; }
                searchClientAC($(this).val().trim());
            }).on('focus', function() {
                if ($(this).val().trim()) searchClientAC($(this).val().trim());
            });

            $(document).on('click', '#client-autocomplete .ac-item', function() {
                $('#filter-client').val($(this).data('val')).trigger('change');
                $('#client-autocomplete').hide();
            });

            $(document).on('click', function(e) {
                if (!$(e.target).closest('#filter-client, #client-autocomplete').length) {
                    $('#client-autocomplete').hide();
                }
            });

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
                    case 3: filterId = '#filter-date';    break;
                    case 5: filterId = '#filter-client';  break;
                    case 7: filterId = '#filter-product'; break;
                    case 8: filterId = '#filter-spec';    break;
                }

                if (filterId) {
                    // 統一文字切換邏輯：若已是此值則清除，否則填入
                    if ($(filterId).val() === cellData) {
                        $(filterId).val('').trigger('change');
                    } else {
                        $(filterId).val(cellData).trigger('change');
                    }
                }
            });

            // 清除篩選按鈕
            $('#clear-filters').click(function() {
                $('#external-filter-container input[type="text"]').val('');
                $('#filter-sale-type').val(null).trigger('change');
                $('#client-autocomplete').hide();
                currentChartFilter = null;
                _zeroPriceFilterActive = false;
                $('#btn-filter-zero-price').removeClass('active').css('opacity', '1');
                $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) { return fn._zeroPriceFilter !== true; });
                table.search('').columns().search('').draw();
                updateFilterStatusBar();
            });

            // 快速篩選：單價=0 且 is_count=1（納入統計）
            var _zeroPriceFilterActive = false;
            function zeroPriceFilterFn(settings, data, dataIndex) {
                if (settings.sTableId !== 'shippingTable') return true;
                var row = settings.aoData[dataIndex]._aData;
                // is_count=NULL（一般產品無對應性質）視為納入統計；is_count=0 才排除
                return (parseFloat(row.Unit_price) === 0) && (row.is_count == null || row.is_count != 0);
            }
            zeroPriceFilterFn._zeroPriceFilter = true;

            window.toggleZeroPriceFilter = function(btn) {
                _zeroPriceFilterActive = !_zeroPriceFilterActive;
                if (_zeroPriceFilterActive) {
                    $(btn).addClass('active').css('opacity', '1').css('box-shadow', 'inset 0 2px 4px rgba(0,0,0,.2)');
                    $.fn.dataTable.ext.search.push(zeroPriceFilterFn);
                } else {
                    $(btn).removeClass('active').css('opacity', '1').css('box-shadow', '');
                    $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) { return fn._zeroPriceFilter !== true; });
                }
                table.draw();
            };

            // 新增：雙擊熱銷產品料號以篩選主列表
            $('#top-products-tbody').on('dblclick', 'td:nth-child(2)', function() {
                var productId = $(this).text().trim();
                if (productId) {
                    // 設定料號篩選輸入框的值 (不觸發 change 以免執行預設模糊搜尋)
                    $('#filter-product').val(productId);
                    
                    // 使用精確搜尋 (Regex) 以避免部分匹配 (例如 'Z' 匹配到 'Z01')
                    var escapedProductId = $.fn.dataTable.util.escapeRegex(productId);
                    table.column(7).search('^' + escapedProductId + '$', true, false);

                    table.draw();
                    
                    // 滾動到主列表
                    $('html, body').animate({
                        scrollTop: $('#shippingTable_wrapper').offset().top - 100
                    }, 500);
                }
            });

            // 初始化出貨性質多選篩選 (列表用)
            $('#filter-sale-type').select2({
                placeholder: "全部性質",
                allowClear: true,
                width: 'resolve'
            }).on('change', function() {
                table.draw();
            });

            // 主要資料表白名單（ext.search 僅套用到這三張表，其他如客戶統計表不受影響）
            var _mainTbls = ['shippingTable', 'returnTable', 'orderListTable'];

            // 自定義出貨性質篩選邏輯
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (_mainTbls.indexOf(settings.sTableId) === -1) return true;
                var selectedTypes = $('#filter-sale-type').val();
                if (!selectedTypes || selectedTypes.length === 0) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var typeId = rowData.sale_type === null ? 'NULL' : String(rowData.sale_type);
                return selectedTypes.includes(typeId);
            });

            // 自定義客戶篩選邏輯（文字模糊搜尋：同時比對客戶名稱與客戶ID）
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (_mainTbls.indexOf(settings.sTableId) === -1) return true;
                var kw = ($('#filter-client').val() || '').trim().toLowerCase();
                if (!kw) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var clientName = (rowData.Client_name || '').toLowerCase();
                var clientId   = (rowData.Client_id   || '').toLowerCase();
                return clientName.indexOf(kw) !== -1 || clientId.indexOf(kw) !== -1;
            });

            // 全域搜尋（依表格搜尋對應欄位）
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (_mainTbls.indexOf(settings.sTableId) === -1) return true;
                var kw = ($('#global-search').val() || '').trim().toLowerCase();
                if (!kw) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var tid = settings.sTableId;
                if (tid === 'returnTable') {
                    return (rowData.IR_no      || '').toLowerCase().indexOf(kw) !== -1 ||
                           (rowData.Product_id || '').toLowerCase().indexOf(kw) !== -1 ||
                           (rowData.IR_ps      || '').toLowerCase().indexOf(kw) !== -1;
                }
                if (tid === 'orderListTable') {
                    return (rowData.Order_oo   || '').toLowerCase().indexOf(kw) !== -1 ||
                           (rowData.Product_id || '').toLowerCase().indexOf(kw) !== -1 ||
                           (rowData.Order_ps   || '').toLowerCase().indexOf(kw) !== -1;
                }
                return (rowData.IS_number  || '').toLowerCase().indexOf(kw) !== -1 ||
                       (rowData.Product_id || '').toLowerCase().indexOf(kw) !== -1 ||
                       (rowData.Note       || '').toLowerCase().indexOf(kw) !== -1 ||
                       (rowData.Content    || '').toLowerCase().indexOf(kw) !== -1;
            });

            // 自定義圖表點擊篩選邏輯
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (_mainTbls.indexOf(settings.sTableId) === -1) return true;
                if (!currentChartFilter) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var dateStr = rowData.Order_date;
                var key = getDateKey(dateStr, rowData);
                return key === currentChartFilter;
            });

            // 已調整帳款月份資料的顯示控制：
            // - 點「已調整」按鈕(currentOverrideFilter=true) → 只顯示 override 行
            // - 其他任何情況 → 隱藏所有 override 行（避免干擾月份篩選與計算）
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.sTableId !== 'shippingTable') return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var hasOverride = !!(rowData.billing_month_override && rowData.billing_month_override !== '');
                return currentOverrideFilter ? hasOverride : !hasOverride;
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
                    type: 'areaspline',
                    animation: { duration: 500 },
                    backgroundColor: 'transparent',
                    style: { fontFamily: '"Microsoft JhengHei","PingFang TC",sans-serif' },
                    marginTop: 45,
                },
                title: { text: null },
                xAxis: {
                    categories: <?php echo json_encode($chart_dates); ?>,
                    crosshair: { width: 1, color: 'rgba(52,152,219,.3)', dashStyle: 'ShortDash' },
                    labels: { style: { fontSize: '10px', color: '#5d6d7e' } },
                    lineColor: '#e8ecf0', tickColor: '#e8ecf0'
                },
                yAxis: {
                    min: 0,
                    title: { text: null },
                    gridLineDashStyle: 'ShortDash', gridLineColor: '#f0f2f5',
                    labels: {
                        formatter: function() {
                            return this.value > 0 ? Highcharts.numberFormat(this.value, 0) + '萬' : '0';
                        },
                        style: { fontSize: '10px', color: '#95a5a6' }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(26,38,52,.9)',
                    borderColor: 'transparent', borderRadius: 8,
                    style: { color: '#fff', fontSize: '12px' },
                    useHTML: true, shared: true,
                    formatter: function() {
                        var s = '<div style="font-size:11px;opacity:.75;margin-bottom:4px">' + this.x + '</div>';
                        this.points.forEach(function(p) {
                            if (p.y > 0) {
                                var d = p.y < 1 ? '$'+Math.round(p.y*10000)+'元' : '$'+Highcharts.numberFormat(p.y, 2)+'萬';
                                s += '<span style="color:' + p.series.color + '">●</span> '
                                   + p.series.name + '：<b>' + d + '</b><br>';
                            }
                        });
                        return s;
                    }
                },
                plotOptions: {
                    areaspline: {
                        lineWidth: 2.5,
                        color: '#2980b9',
                        fillColor: {
                            linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
                            stops: [ [0, 'rgba(41,128,185,.30)'], [1, 'rgba(41,128,185,.02)'] ]
                        },
                        marker: {
                            radius: 4, symbol: 'circle',
                            fillColor: '#fff', lineWidth: 2, lineColor: '#2980b9',
                            states: { hover: { radius: 6 } }
                        },
                        dataLabels: {
                            enabled: true,
                            allowOverlap: false,
                            crop: true,
                            overflow: 'justify',
                            formatter: function() {
                                if (!this.y || this.y <= 0) return null;
                                return this.y < 1 ? '$'+Math.round(this.y*10000)+'元' : '$'+Highcharts.numberFormat(this.y, 1)+'萬';
                            },
                            style: { fontSize: '10px', fontWeight: '700', color: '#1a5276', textOutline: '2px white' },
                            y: -8
                        },
                        cursor: 'pointer',
                        point: {
                            events: {
                                click: function() {
                                    var now = new Date().getTime();
                                    if (this.lastClick && (now - this.lastClick < 300)) {
                                        applyChartFilter(this.category);
                                        this.lastClick = 0;
                                    } else { this.lastClick = now; }
                                }
                            }
                        }
                    }
                },
                series: [
                    {
                        name: '出貨金額',
                        type: 'areaspline',
                        data: <?php echo json_encode($chart_values); ?>,
                        color: '#2980b9',
                        fillColor: {
                            linearGradient: { x1:0, y1:0, x2:0, y2:1 },
                            stops: [ [0,'rgba(41,128,185,.28)'], [1,'rgba(41,128,185,.02)'] ]
                        },
                        zIndex: 2
                    },
                    {
                        name: '接單金額',
                        type: 'spline',
                        data: <?php echo json_encode($order_chart_values); ?>,
                        color: '#e67e22',
                        dashStyle: 'ShortDash',
                        lineWidth: 2,
                        marker: { radius:4, symbol:'circle', fillColor:'#fff', lineWidth:2, lineColor:'#e67e22' },
                        dataLabels: { enabled: false },
                        zIndex: 1
                    },
                    {
                        name: '退貨金額',
                        type: 'spline',
                        data: <?php echo json_encode($return_chart_values); ?>,
                        color: '#e74c3c',
                        dashStyle: 'ShortDot',
                        lineWidth: 2,
                        marker: { radius:4, symbol:'diamond', fillColor:'#fff', lineWidth:2, lineColor:'#e74c3c' },
                        dataLabels: { enabled: false },
                        zIndex: 0
                    }
                ],
                credits: { enabled: false },
                exporting: { enabled: false },
                legend: {
                    enabled: true,
                    align: 'right', verticalAlign: 'top', layout: 'horizontal',
                    itemStyle: { fontSize:'11px', fontWeight:'600' },
                    symbolRadius: 4, symbolHeight: 8
                }
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
                credits: { enabled: false },
                exporting: { enabled: false }
            });

            // 綁定重繪事件以更新統計
            table.on('draw', function() {
                updateStatistics();
                // 以篩選後的資料更新帳款月份卡片
                var filtered = table.rows({ search: 'applied' }).data().toArray();
                renderBmCards(filtered);
                // 重新將翻頁按鈕移到右上角（draw 後 DataTable 會重建）
                $('#shippingTable_paginate').appendTo('#dt-pagination-holder');
            });

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

            // 建立「金額>0視為異常」的性質 map
            var nonzeroAnomalyTypes = {};
            allSaleTypes.forEach(function(st) {
                var config = saleTypeConfig[st.sale_type_id] || { color: '#ffffff', keywords: [] };
                zeroPriceGroups[st.sale_type_id] = {
                    title: st.sale_type_name,
                    items: [],
                    keywords: config.keywords || [],
                    color: config.color || '#333333',
                    sort: parseInt(st.sort_order),
                    excludeWhenNonzero: st.exclude_when_nonzero == 1
                };
                if (st.exclude_when_nonzero == 1) {
                    nonzeroAnomalyTypes[st.sale_type_id] = true;
                }
            });

            // 「金額>0異常」獨立分組（集中顯示）
            // 加入 "其他" 分組
            zeroPriceGroups['other'] = {
                title: '其他 / 未分類',
                items: [],
                keywords: [],
                color: '#999999',
                sort: 9999
            };

            // 預先排序好的「有關鍵字且勾選排除非零」的性質
            var nonzeroExcludeTypes = Object.keys(zeroPriceGroups)
                .filter(function(k){ return k !== 'other' && zeroPriceGroups[k].excludeWhenNonzero && zeroPriceGroups[k].keywords && zeroPriceGroups[k].keywords.length > 0; })
                .sort(function(a,b){ return zeroPriceGroups[a].sort - zeroPriceGroups[b].sort; });

            var largeAmountItems = [];
            var validItems = [];
            var dupGroups = {}; // key = 比對字串, value = [row, ...]

            currentData.forEach(function(row) {
                if (row.is_count == 0) return;
                if (row.exclude_anomaly == 1) return;

                var qty = parseFloat(row.Qty) || 0;
                var price = parseFloat(row.Unit_price) || 0;
                var amount = qty * price;
                var textToCheck = ((row.Note || '') + (row.Content || '') + (row.Specification || '') + (row.Product_id || '')).toUpperCase();

                // 「排除金額>0」規則：若符合此性質關鍵字且金額>0 → 排除不偵測
                if (price > 0 && nonzeroExcludeTypes.length > 0) {
                    var excludedByRule = false;
                    for (var ni = 0; ni < nonzeroExcludeTypes.length; ni++) {
                        var ntKws = zeroPriceGroups[nonzeroExcludeTypes[ni]].keywords;
                        for (var nk = 0; nk < ntKws.length; nk++) {
                            if (textToCheck.indexOf(ntKws[nk].toUpperCase()) !== -1) {
                                excludedByRule = true;
                                break;
                            }
                        }
                        if (excludedByRule) break;
                    }
                    if (excludedByRule) return; // 排除：不加入大額交易偵測
                }

                if (price === 0) {
                    // 零單價偵測（所有有關鍵字的性質都納入）
                    var matched = false;
                    var sortedTypes = Object.keys(zeroPriceGroups).filter(function(k){ return k !== 'other'; })
                        .sort(function(a, b) {
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

                // 完全相同出貨偵測（不受 exclude_anomaly 限制，所有 is_count=1 的資料都比對）
                var dupKey = [
                    row.billing_month || '',
                    row.Order_date || '',
                    row.IS_number || '',
                    row.Client_name || '',
                    (row.sale_type === null || row.sale_type === undefined) ? 'NULL' : String(row.sale_type),
                    row.Product_id || '',
                    row.Specification || '',
                    String(parseFloat(row.Qty) || 0),
                    String(parseFloat(row.Unit_price) || 0),
                    String((parseFloat(row.Qty) || 0) * (parseFloat(row.Unit_price) || 0)),
                    row.Note || ''
                ].join('\x00');
                if (!dupGroups[dupKey]) dupGroups[dupKey] = [];
                dupGroups[dupKey].push(row);
            });

            // 過濾出有 2 筆以上的群組（真正重複）
            var dupSets = Object.values(dupGroups).filter(function(g) { return g.length >= 2; });

            // 依金額排序取前 10 筆
            validItems.sort(function(a, b) { return b.amount - a.amount; });
            largeAmountItems = validItems.slice(0, 10);

            // 產生列表 HTML 的輔助函式
            function generateListHtml(items, type) {
                var listHtml = '<ul class="list-unstyled">';
                items.forEach(function(item) {
                    var row = item.row || item; // 兼容結構差異
                    var spec = row.Specification ? ' (' + row.Specification + ')' : '';
                    var content = row.Content ? ' <span style="color:#555;">' + row.Content + '</span>' : '';
                    var info = type === 'zero' ? ' (Qty: ' + row.Qty + ')' :
                               type === 'dup'  ? ' (數量:' + row.Qty + ' 單價:' + row.Unit_price + ')' :
                               ' (金額: $' + numberFormat(item.amount, 0) + ')';
                    var note = row.Note ? ' <span style="color: #00008B; font-weight: bold;">[' + row.Note + ']</span>' : '';
                    var isConfirmed = row.anomaly_confirmed == 1;
                    if (isConfirmed) return; // 已確認者不顯示在異常清單
                    var confirmBtnStyle = 'btn btn-xs btn-default anomaly-confirm-btn';
                    var confirmBtnText = '確認非異常';
                    var liStyle = '';

                    listHtml += '<li style="' + liStyle + '">' +
                        '<div style="display:flex; align-items:center; gap:6px; margin:2px 0;">' +
                        '<input type="checkbox" class="batch-check" value="' + row.IS_id + '">' +
                        '<span style="cursor:pointer; text-decoration:underline; flex:1;" onclick="jumpToRow(' + row.IS_id + ')">' +
                        row.Order_date + ' - ' + (row.Client_name || '無客戶') + ' - ' + (row.Product_id || '無料號') + spec + content + info + note +
                        '</span>' +
                        '<button type="button" class="' + confirmBtnStyle + '" data-is-id="' + row.IS_id + '" onclick="toggleAnomalyConfirmed(' + row.IS_id + ', this)">' + confirmBtnText + '</button>' +
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

            var sidebarHtml = '';

            // ── 單價=0 區塊 ──
            bodyHtml += '<div id="anomaly-sec-zero">';
            if (totalZero > 0) {
                sidebarHtml += '<button class="anav-btn anav-has-issue" onclick="scrollAnomalyTo(\'anomaly-sec-zero\')">' +
                    '<span class="anav-count" style="color:#e74c3c;">' + totalZero + '</span>單價=0</button>';
                bodyHtml += '<div class="alert alert-danger">' +
                        '<h4><i class="fa fa-exclamation-circle"></i> 異常：單價為 0 (' + totalZero + ' 筆) ' +
                        '<button class="btn btn-xs btn-default" onclick="$(\'.batch-check\').prop(\'checked\', true)">全選</button></h4>';
                var allGroupKeys = Object.keys(zeroPriceGroups).sort(function(a, b) {
                    return zeroPriceGroups[a].sort - zeroPriceGroups[b].sort;
                });
                allGroupKeys.forEach(function(key) {
                    var group = zeroPriceGroups[key];
                    if (group && group.items.length > 0) {
                        var color = group.color || '#333';
                        bodyHtml += '<div style="margin-top:10px; padding-left:10px; border-left: 3px solid ' + color + ';">';
                        bodyHtml += '<h5 style="color:' + color + '; background-color:#fff; padding:3px 6px; border-radius:3px; display:inline-block; cursor:pointer;" onclick="toggleGroupSelection(this)" title="點擊全選/取消全選"><strong>' + group.title + ' (' + group.items.length + ')</strong></h5>';
                        bodyHtml += generateListHtml(group.items, 'zero');
                        bodyHtml += '</div>';
                    }
                });
                bodyHtml += '</div>';
            } else {
                sidebarHtml += '<button class="anav-btn anav-ok" onclick="scrollAnomalyTo(\'anomaly-sec-zero\')">' +
                    '<span class="anav-count" style="color:#27ae60;"><i class="fa fa-check"></i></span>單價=0</button>';
                bodyHtml += '<div class="alert alert-success"><h4><i class="fa fa-check-circle"></i> 無單價為 0 的項目</h4></div>';
            }
            bodyHtml += '</div>';

            // ── 完全相同出貨區塊 ──
            bodyHtml += '<div id="anomaly-sec-dup">';
            if (dupSets.length > 0) {
                var totalDupRows = dupSets.reduce(function(s, g) { return s + g.length; }, 0);
                sidebarHtml += '<button class="anav-btn anav-has-issue" onclick="scrollAnomalyTo(\'anomaly-sec-dup\')">' +
                    '<span class="anav-count" style="color:#e67e22;">' + dupSets.length + '組</span>完全相同</button>';
                bodyHtml += '<div style="border:2px solid #e67e22; border-radius:6px; margin-bottom:12px;">' +
                    '<div style="background:#e67e22; color:#fff; padding:8px 12px; border-radius:4px 4px 0 0;">' +
                    '<strong><i class="fa fa-clone"></i> 完全相同的出貨資料</strong>' +
                    ' &nbsp;<span style="background:rgba(255,255,255,.25); border-radius:10px; padding:1px 8px; font-size:12px;">' + dupSets.length + ' 組，共 ' + totalDupRows + ' 筆</span>' +
                    '<div style="font-size:11px; margin-top:3px; opacity:.85;">比對欄位：帳款月份、出貨日期、出貨單號、客戶名稱、出貨性質、料號、規格、數量、單價、總價、備註</div>' +
                    '</div>' +
                    '<div style="padding:10px 12px;">';
                dupSets.forEach(function(group, idx) {
                    var r0 = group[0];
                    var saleTypeLabel = r0.sale_type_name ? r0.sale_type_name : '一般產品';
                    var total = (parseFloat(r0.Qty) || 0) * (parseFloat(r0.Unit_price) || 0);
                    bodyHtml += '<div style="margin-bottom:10px; border:1px solid #f0c070; border-radius:4px; overflow:hidden;">';
                    bodyHtml += '<div style="background:#fff3cd; padding:5px 10px; font-size:12px; color:#5d4037; border-bottom:1px solid #f0c070;">' +
                        '<strong style="color:#c0392b;">第 ' + (idx+1) + ' 組</strong>　' +
                        '<span style="color:#2c3e50;">帳款月：<b>' + (r0.billing_month || '—') + '</b></span>　' +
                        '<span style="color:#2c3e50;">出貨日：<b>' + (r0.Order_date || '—') + '</b></span>　' +
                        '<span style="color:#2c3e50;">客戶：<b>' + (r0.Client_name || '—') + '</b></span>　' +
                        '<span style="color:#2c3e50;">料號：<b>' + (r0.Product_id || '—') + '</b></span>' +
                        (r0.Specification ? '　<span style="color:#555;">規格：' + r0.Specification + '</span>' : '') +
                        '　<span style="color:#2c3e50;">數量：<b>' + (r0.Qty || 0) + '</b>　單價：<b>' + (r0.Unit_price || 0) + '</b>　總價：<b>' + total + '</b></span>' +
                        (r0.Note ? '　<span style="color:#8e44ad;">備註：' + r0.Note + '</span>' : '') +
                        '　<span style="color:#777;">性質：' + saleTypeLabel + '</span>' +
                        '</div>';
                    group.forEach(function(row) {
                        if (row.anomaly_confirmed == 1) return;
                        bodyHtml += '<div class="anomaly-dup-row" style="display:flex; align-items:center; gap:8px; padding:6px 10px; background:#fff; border-top:1px solid #f5e9c8; color:#2c3e50; font-size:13px;">' +
                            '<input type="checkbox" class="batch-check" value="' + row.IS_id + '" style="flex-shrink:0;">' +
                            '<span style="flex:1; cursor:pointer; color:#1a73e8; text-decoration:underline;" onclick="jumpToRow(' + row.IS_id + ')" title="跳至此筆記錄">' +
                            '<b>出貨單號：' + (row.IS_number || '（無）') + '</b>' +
                            '　<span style="color:#555; font-size:11px;">IS_id=' + row.IS_id + '</span>' +
                            '</span>' +
                            '<button type="button" class="btn btn-xs btn-default anomaly-confirm-btn" onclick="toggleAnomalyConfirmed(' + row.IS_id + ', this)" style="flex-shrink:0; white-space:nowrap;">確認非異常</button>' +
                            '</div>';
                    });
                    bodyHtml += '</div>';
                });
                bodyHtml += '</div></div>';
            } else {
                sidebarHtml += '<button class="anav-btn anav-ok" onclick="scrollAnomalyTo(\'anomaly-sec-dup\')">' +
                    '<span class="anav-count" style="color:#27ae60;"><i class="fa fa-check"></i></span>完全相同</button>';
                bodyHtml += '<div class="alert alert-success"><h4><i class="fa fa-check-circle"></i> 無完全相同的出貨資料</h4></div>';
            }
            bodyHtml += '</div>';

            // ── 大額交易區塊 ──
            bodyHtml += '<div id="anomaly-sec-large">';
            if (largeAmountItems.length > 0) {
                sidebarHtml += '<button class="anav-btn" onclick="scrollAnomalyTo(\'anomaly-sec-large\')" style="border-left:3px solid #3498db;">' +
                    '<span class="anav-count" style="color:#3498db;">TOP10</span>大額</button>';
                bodyHtml += '<div class="alert alert-info"><h4><i class="fa fa-trophy"></i> 大額交易 (前 10 筆)</h4>' +
                        generateListHtml(largeAmountItems, 'large') +
                        '</div>';
            }
            bodyHtml += '</div>';

            $('#analysis-result-header').html(headerHtml);
            $('#analysis-nav-sidebar').html(sidebarHtml);
            $('#analysis-result-body').html(bodyHtml);
            $('#analysisResultModal').modal('show');
        }

        // 快速捲動到異常報告某個區塊
        window.scrollAnomalyTo = function(sectionId) {
            var body = document.getElementById('analysis-result-body');
            var target = document.getElementById(sectionId);
            if (body && target) {
                body.scrollTo({ top: target.offsetTop - 8, behavior: 'smooth' });
            }
        };

        // 確認非異常（全域，從異常列表移除此筆並更新 DataTables 快取）
        window.toggleAnomalyConfirmed = function(isId, btn) {
            $(btn).prop('disabled', true).text('處理中…');
            $.post('', { action: 'toggle_anomaly_confirmed', is_id: isId }, function(res) {
                var r = typeof res === 'string' ? JSON.parse(res) : res;
                if (r.success) {
                    // 從異常列表中移除此筆（li 或 div.anomaly-dup-row）
                    var $row = $(btn).closest('li, .anomaly-dup-row');
                    $row.fadeOut(300, function() { $(this).remove(); });
                    // 同步更新 DataTables 快取
                    var table = $('#shippingTable').DataTable();
                    table.rows().every(function() {
                        var d = this.data();
                        if (d.IS_id == isId) {
                            d.anomaly_confirmed = r.confirmed;
                            this.data(d);
                        }
                    });
                } else {
                    alert('儲存失敗');
                    $(btn).prop('disabled', false).text('確認非異常');
                }
            }).fail(function() {
                alert('連線失敗');
                $(btn).prop('disabled', false).text('確認非異常');
            });
        };

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
            $('#edit_billing_month').val(row.billing_month_override || '');
            $('#edit_is_number').val(row.IS_number);
            $('#edit_client_name').val(row.Client_name);
            $('#edit_product_id').val(row.Product_id);
            $('#edit_specification').val(row.Specification);
            $('#edit_content').val(row.Content || '');
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
                        var _bmo = $('#edit_billing_month').val();
                        rowData.billing_month_override = _bmo || '';
                        rowData.billing_month = _bmo || (function() {
                            var p = rowData.Order_date.split('-');
                            var y = parseInt(p[0]), m = parseInt(p[1]), d = parseInt(p[2]);
                            if (globalCutoffDay > 0 && d > globalCutoffDay) { m === 12 ? (y++, m=1) : m++; }
                            return y + '-' + (m < 10 ? '0'+m : m);
                        })();
                        rowData.IS_number = $('#edit_is_number').val();
                        rowData.Client_name = $('#edit_client_name').val();
                        rowData.sale_type = $('#edit_sale_type').val();
                        var selectedOption = $('#edit_sale_type option:selected');
                        rowData.sale_type_name = selectedOption.text();
                        rowData.is_count = selectedOption.data('is-count');
                        rowData.exclude_anomaly = selectedOption.data('exclude-anomaly');
                        rowData.Product_id = $('#edit_product_id').val();
                        rowData.Specification = $('#edit_specification').val();
                        rowData.Content = $('#edit_content').val();
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

        // 退貨帳款月份編輯
        $(document).on('click', '.btn-ir-bm-edit', function() {
            $('#ir_bm_ir_id').val($(this).data('ir-id'));
            $('#ir_bm_value').val($(this).data('bmo') || '');
            $('#irBillingMonthModal').modal('show');
        });

        function saveIrBillingMonth() {
            var irId = $('#ir_bm_ir_id').val();
            var bmo  = $('#ir_bm_value').val();
            $.post('../../src/store/update_ir_billing_month.php',
                { ir_id: irId, billing_month_override: bmo },
                function(res) {
                    var r = JSON.parse(res);
                    if (!r.success) { alert('儲存失敗: ' + r.message); return; }
                    $('#irBillingMonthModal').modal('hide');
                    // 更新 irData 陣列及 DataTable
                    var rtDT = $('#returnTable').DataTable();
                    rtDT.rows().every(function() {
                        var d = this.data();
                        if (d.IR_id == irId) {
                            d.billing_month_override = bmo || '';
                            d.billing_month = bmo || (function() {
                                var p = d.IR_date.split('-');
                                var y = parseInt(p[0]), m = parseInt(p[1]), dd = parseInt(p[2]);
                                if (globalCutoffDay > 0 && dd > globalCutoffDay) { m===12?(y++,m=1):m++; }
                                return y + '-' + (m<10?'0'+m:m);
                            })();
                            // 同步更新 irData
                            for (var i = 0; i < irData.length; i++) {
                                if (irData[i].IR_id == irId) {
                                    irData[i].billing_month_override = d.billing_month_override;
                                    irData[i].billing_month = d.billing_month;
                                    break;
                                }
                            }
                            this.data(d);
                        }
                    });
                    rtDT.draw(false);
                }
            );
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
                            '<td>' + (item.exclude_top10 == 1 ? '<i class="fa fa-check text-warning"></i>' : '') + '</td>' +
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

        // ── 色票選色邏輯 ──
        function setSwatchColor(color) {
            $('#st_color').val(color);
            $('#color-preview').css('background', color);
            $('#color-hex').text(color);
            $('#color-palette .color-swatch').css('border-color', 'transparent');
            $('#color-palette .color-swatch[data-color="' + color + '"]').css('border-color', '#333');
        }
        $(document).on('click', '#color-palette .color-swatch', function() {
            setSwatchColor($(this).data('color'));
        });

        function resetSaleTypeForm() {
            $('#saleTypeForm')[0].reset();
            $('#st_id').val('');
            $('#st_count').prop('checked', true);
            $('#st_exclude_anomaly').prop('checked', false);
            $('#st_exclude_when_nonzero').prop('checked', false);
            $('#st_exclude_top10').prop('checked', false);
            $('#st_active').prop('checked', true);
            setSwatchColor('#ffffff');
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
            $('#st_exclude_when_nonzero').prop('checked', item.exclude_when_nonzero == 1);
            $('#st_exclude_top10').prop('checked', item.exclude_top10 == 1);
            $('#st_active').prop('checked', item.is_active == 1);
            setSwatchColor(config.color || '#ffffff');
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

        // ── 列印 PDF 報表 ──
        function printAnalysisReport(includeDetail) {
            if (includeDetail === undefined) includeDetail = false;
            var table = $('#shippingTable').DataTable();
            var visibleData = table.rows({ search: 'applied' }).data().toArray();

            // 重算統計數據（依目前篩選可見列）
            var totalQty = 0, totalAmount = 0, validCount = 0;
            var clientStats = {}, productStats = {}, saleTypeStats = {};

            visibleData.forEach(function(row) {
                if (row.is_count == 0) return;
                var qty   = parseFloat(row.Qty)        || 0;
                var price = parseFloat(row.Unit_price) || 0;
                var amt   = qty * price;
                if (price > 0) validCount++;
                totalQty    += qty;
                totalAmount += amt;

                var client = (row.Client_name || '未知客戶').trim();
                clientStats[client] = (clientStats[client] || 0) + amt;

                var st = row.sale_type_name || '一般產品';
                saleTypeStats[st] = (saleTypeStats[st] || 0) + amt;

                var pid = (row.Product_id || '').trim();
                if (!pid || pid === '0' || pid === '1') pid = '未知料號';
                if (!productStats[pid]) productStats[pid] = { amount: 0, qty: 0, count: 0 };
                productStats[pid].amount += amt;
                productStats[pid].qty    += qty;
                productStats[pid].count++;
            });

            var topClients  = Object.keys(clientStats).map(function(k){ return [k, clientStats[k]]; })
                               .sort(function(a,b){ return b[1]-a[1]; }).slice(0,5);
            var topProducts = Object.keys(productStats).map(function(k){ return [k, productStats[k]]; })
                               .sort(function(a,b){ return b[1].amount-a[1].amount; }).slice(0,10);
            var totalSt     = Object.keys(saleTypeStats).reduce(function(s,k){ return s+saleTypeStats[k]; }, 0);

            var displayAmount = totalAmount >= 100000000
                ? (totalAmount/100000000).toFixed(2) + ' 億'
                : (totalAmount/10000).toFixed(2) + ' 萬';

            var startDate = $('#start_date').val();
            var endDate   = $('#end_date').val();
            var printTime = new Date().toLocaleString('zh-TW');

            // ── Highcharts SVG（縮小尺寸以符合單頁）──
            var barChart = Highcharts.charts.find(function(c){ return c && c.renderTo && c.renderTo.id === 'analysis-chart'; });
            var pieChart = Highcharts.charts.find(function(c){ return c && c.renderTo && c.renderTo.id === 'sale-type-chart'; });
            var barSvg = '', pieSvg = '';
            try { if (barChart) barSvg = barChart.getSVG({ chart: { width: 740, height: 175 } }); } catch(e) {}
            try { if (pieChart) pieSvg = pieChart.getSVG({ chart: { width: 290, height: 175 } }); } catch(e) {}

            // ── 輔助 ──
            function esc(s) {
                return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            function fmt(n, d) {
                var x = parseFloat(n)||0;
                return x.toLocaleString('zh-TW',{minimumFractionDigits:d||0,maximumFractionDigits:d||0});
            }
            function fmtA(v) {
                if (Math.abs(v) >= 10000) return '$'+(v/10000).toFixed(1)+'萬';
                return '$'+Math.round(v).toLocaleString()+'元';
            }

            // ── 前五大客戶 ──
            var clientRowsHtml = '';
            topClients.forEach(function(item, i) {
                clientRowsHtml += '<tr><td class="tc fw">'+(i+1)+'</td><td>'+esc(item[0])+'</td>'+
                    '<td class="tr c1">'+fmtA(item[1])+'</td></tr>';
            });

            // ── 十大熱銷產品 ──
            var productRowsHtml = '';
            topProducts.forEach(function(item, i) {
                var s = item[1], avg = s.count > 0 ? s.qty/s.count : 0;
                productRowsHtml += '<tr><td class="tc fw">'+(i+1)+'</td><td>'+esc(item[0])+'</td>'+
                    '<td class="tr c1">'+fmtA(s.amount)+'</td>'+
                    '<td class="tr">'+fmt(avg,1)+'</td>'+
                    '<td class="tr">'+s.count+'</td></tr>';
            });

            // ── 出貨性質分佈 ──
            var stRowsHtml = '';
            Object.keys(saleTypeStats).sort(function(a,b){return saleTypeStats[b]-saleTypeStats[a];}).forEach(function(k){
                var v = saleTypeStats[k], pct = totalSt>0?(v/totalSt*100).toFixed(1):'0.0';
                stRowsHtml += '<tr><td>'+esc(k)+'</td><td class="tr">'+pct+'%</td><td class="tr c1">'+fmtA(v)+'</td></tr>';
            });

            // ── 明細表格 ──
            var detailRowsHtml = '';
            visibleData.forEach(function(row){
                var qty=parseFloat(row.Qty)||0, price=parseFloat(row.Unit_price)||0, amt=qty*price;
                detailRowsHtml += '<tr><td>'+esc(row.Order_date)+'</td><td>'+esc(row.IS_number)+'</td>'+
                    '<td>'+esc(row.Client_name)+'</td><td>'+esc(row.sale_type_name||'一般產品')+'</td>'+
                    '<td>'+esc(row.Product_id)+'</td><td>'+esc(row.Specification)+'</td>'+
                    '<td class="tr">'+fmt(qty,0)+'</td><td class="tr">'+(price>0?fmt(price,0):'-')+'</td>'+
                    '<td class="tr">'+(amt>0?fmt(Math.round(amt),0):'-')+'</td>'+
                    '<td>'+esc(row.Warehouse)+'</td><td>'+esc(row.Note)+'</td></tr>';
            });

            // ── 組合報表 HTML ──
            var css =
                '*{box-sizing:border-box;margin:0;padding:0;}'+
                'body{font-family:"Microsoft JhengHei","PingFang TC",sans-serif;font-size:11px;color:#222;}'+
                '.rpt-header{background:#1a2634;color:#fff;padding:7px 14px;}'+
                '.rpt-header h1{font-size:22px;font-weight:700;letter-spacing:1px;}'+
                '.rpt-header .sub{font-size:10px;opacity:.75;margin-top:3px;display:flex;gap:16px;}'+
                '.rpt-body{padding:7px 12px;}'+
                '.sec-title{font-size:13px;font-weight:700;color:#1a2634;border-left:3px solid #3498db;padding-left:7px;margin:8px 0 5px;}'+
                '.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:8px;}'+
                '.sc{background:#f5f7fa;border:1px solid #e2e8f0;border-radius:3px;padding:5px 8px;text-align:center;}'+
                '.sc .lbl{font-size:10px;color:#8a9ab0;margin-bottom:1px;}'+
                '.sc .val{font-size:17px;font-weight:700;color:#1a2634;line-height:1.2;}'+
                '.sc .unt{font-size:10px;color:#aaa;}'+
                '.charts-row{display:grid;grid-template-columns:72% 28%;gap:8px;margin-bottom:8px;}'+
                '.chart-box{border:1px solid #e2e8f0;border-radius:3px;padding:5px 8px;overflow:hidden;}'+
                '.chart-box h4{font-size:11px;color:#1a2634;font-weight:700;margin-bottom:4px;}'+
                '.chart-box svg{display:block;width:100%;height:auto;}'+
                '.rank-row{display:grid;grid-template-columns:25% 50% 25%;gap:8px;margin-bottom:8px;}'+
                '.rh{font-size:12px;font-weight:700;color:#1a2634;margin-bottom:3px;}'+
                'table{width:100%;border-collapse:collapse;table-layout:fixed;}'+
                'th{background:#1a2634;color:#ecf0f1;padding:3px 5px;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'+
                'td{padding:2.5px 5px;border-bottom:1px solid #eee;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'+
                'tr:nth-child(even) td{background:#f9f9f9;}'+
                '.tc{text-align:center;}.tr{text-align:right;}.fw{font-weight:700;}'+
                '.c1{color:#1a6daa;font-weight:600;}'+
                'tr.tot td{font-weight:700;background:#e8f4fd!important;border-top:2px solid #3498db;}'+
                '.footer{margin-top:6px;padding-top:5px;border-top:1px solid #e2e8f0;font-size:9px;color:#aaa;text-align:center;}'+
                '@page{size:A4 landscape;margin:7mm 8mm;}'+
                '@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}';

            var html = '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="utf-8">'+
                '<title>出貨紀錄分析報表 '+startDate+'～'+endDate+'</title>'+
                '<style>'+css+'</style></head><body>'+

                '<div class="rpt-header"><h1>出貨紀錄分析報表</h1>'+
                '<div class="sub"><span>出貨日期：'+esc(startDate)+' ～ '+esc(endDate)+'</span>'+
                '<span>共篩選 '+visibleData.length+' 筆</span>'+
                '<span>列印時間：'+esc(printTime)+'</span></div></div>'+

                '<div class="rpt-body">'+

                '<div class="sec-title">統計摘要</div>'+
                '<div class="stats-grid">'+
                '<div class="sc"><div class="lbl">出貨筆數</div><div class="val">'+fmt(validCount,0)+'</div><div class="unt">筆</div></div>'+
                '<div class="sc"><div class="lbl">出貨數量</div><div class="val">'+fmt(totalQty,0)+'</div><div class="unt">PCS</div></div>'+
                '<div class="sc"><div class="lbl">出貨金額</div><div class="val">'+displayAmount+'</div><div class="unt">TWD</div></div>'+
                '<div class="sc"><div class="lbl">出貨客戶數</div><div class="val">'+Object.keys(clientStats).length+'</div><div class="unt">家</div></div>'+
                '</div>'+

                '<div class="sec-title">趨勢分析</div>'+
                '<div class="charts-row">'+
                '<div class="chart-box"><div class="rh">出貨金額趨勢</div>'+(barSvg||'<p style="color:#aaa;text-align:center;padding:10px 0;">圖表無法載入</p>')+'</div>'+
                '<div class="chart-box"><div class="rh">出貨性質佔比</div>'+(pieSvg||'<p style="color:#aaa;text-align:center;padding:10px 0;">無資料</p>')+'</div>'+
                '</div>'+

                '<div class="sec-title">排名分析</div>'+
                '<div class="rank-row">'+
                '<div><div class="rh">前五大出貨客戶</div>'+
                '<table><colgroup><col style="width:16%"><col><col style="width:35%"></colgroup>'+
                '<thead><tr><th>排名</th><th>客戶</th><th class="tr">金額</th></tr></thead>'+
                '<tbody>'+(clientRowsHtml||'<tr><td colspan="3" style="text-align:center;color:#aaa;">無資料</td></tr>')+'</tbody></table></div>'+
                '<div><div class="rh">十大熱銷產品</div>'+
                '<table><colgroup><col style="width:8%"><col style="width:35%"><col style="width:22%"><col style="width:22%"><col style="width:13%"></colgroup>'+
                '<thead><tr><th>排名</th><th>料號</th><th class="tr">金額</th><th class="tr">均出貨量</th><th class="tr">次數</th></tr></thead>'+
                '<tbody>'+(productRowsHtml||'<tr><td colspan="5" style="text-align:center;color:#aaa;">無資料</td></tr>')+'</tbody></table></div>'+
                '<div><div class="rh">出貨性質分佈</div>'+
                '<table><thead><tr><th>出貨性質</th><th class="tr">佔比</th><th class="tr">金額</th></tr></thead>'+
                '<tbody>'+(stRowsHtml||'<tr><td colspan="3" style="text-align:center;color:#aaa;">無資料</td></tr>')+'</tbody></table></div>'+
                '</div>'+

                (includeDetail?
                '<div class="sec-title" style="page-break-before:always;">出貨明細（共 '+visibleData.length+' 筆）</div>'+
                '<table><thead><tr><th>出貨日期</th><th>出貨單號</th><th>客戶名稱</th><th>出貨性質</th>'+
                '<th>料號</th><th>規格</th><th class="tr">數量</th><th class="tr">單價</th><th class="tr">總價</th><th>倉庫</th><th>備註</th></tr></thead>'+
                '<tbody>'+detailRowsHtml+
                '<tr class="tot"><td colspan="6" class="tr">合計</td>'+
                '<td class="tr">'+fmt(totalQty,0)+'</td><td></td>'+
                '<td class="tr">'+fmt(Math.round(totalAmount),0)+'</td><td colspan="2"></td></tr>'+
                '</tbody></table>':'')+

                '<div class="footer">本報表由 EGsystem 自動產生 ｜ '+esc(printTime)+'</div>'+
                '</div></body></html>';

            var printWin = window.open('', '_blank', 'width=1280,height=900,scrollbars=yes,resizable=yes');
            if (!printWin) {
                alert('請允許瀏覽器彈出視窗以開啟列印報表。');
                return;
            }
            printWin.document.write(html);
            printWin.document.close();
            printWin.focus();
            // 等待圖片/SVG 渲染後再列印
            setTimeout(function() { printWin.print(); }, 1400);
        }

        // ── Tab 切換邏輯 ──
        var _returnTableInited = false, _orderTableInited = false;
        var _currentListTab = 'ship';

        // ── 客戶統計 Tab ──
        var _csDT = null; // DataTable instance

        function _csAmtFmt(v) {
            if (v === 0) return '';
            var s = v < 0 ? '-$' : '$';
            return s + numberFormat(Math.abs(Math.round(v)), 0);
        }

        function _buildClientSummaryTable(curr, prev, prevBm) {
            // 只顯示當期有資料的客戶；前期資料僅作比較用，不額外補列
            var allNames = Object.keys(curr);

            // 更新前期欄標題
            $('#summary-prev-col').html(prevBm
                ? ('較 ' + prevBm + ' <small style="font-size:9px;color:#aaa;">↑紅↓綠</small>')
                : '前期增減');

            // 建立資料列（6欄：客戶名稱, 出貨, 退貨, 訂單, 淨額, 前期增減%）
            var tableData = [], totShip=0, totRet=0, totOrd=0;
            allNames.forEach(function(cn) {
                var c = curr[cn]||{ship:0,ret:0,ord:0}, net = c.ship - c.ret;
                totShip += c.ship; totRet += c.ret; totOrd += c.ord;
                var changePct = null;
                if (prev) {
                    var p = prev[cn]||{ship:0,ret:0}, prevNet = p.ship - p.ret;
                    if (prevNet !== 0) {
                        changePct = (net - prevNet) / Math.abs(prevNet) * 100;
                    } else if (net > 0) {
                        changePct = 999;  // 新增客戶 sentinel
                    } else {
                        changePct = 0;
                    }
                }
                tableData.push([cn, c.ship, c.ret, c.ord, net, changePct]);
            });

            // 建立/重建 DataTable（6欄，與 thead 的 6 個 th 對應）
            if (_csDT) { _csDT.destroy(); _csDT = null; }
            $('#clientSummaryTable tbody, #clientSummaryTable tfoot').empty();
            _csDT = $('#clientSummaryTable').DataTable({
                dom: 'rtip',
                data: tableData,
                pageLength: 15,
                order: [[4, 'desc']],
                language: { url: '//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json', zeroRecords: '無資料' },
                columns: [
                    { title: '客戶名稱', width: '160px',
                      render: function(d, t) { return t==='display' ? $('<span>').text(d).html() : d; }
                    },
                    { title: '出貨金額', className: 'text-right',
                      render: function(d, t) { return t==='display' ? (d?'<span style="color:#1a6daa;">'+_csAmtFmt(d)+'</span>':'<span style="color:#bbb;">—</span>') : d; }
                    },
                    { title: '退貨金額', className: 'text-right',
                      render: function(d, t) { return t==='display' ? (d?'<span style="color:#e74c3c;">'+_csAmtFmt(d)+'</span>':'<span style="color:#bbb;">—</span>') : d; }
                    },
                    { title: '訂單金額', className: 'text-right',
                      render: function(d, t) { return t==='display' ? (d?'<span style="color:#27ae60;">'+_csAmtFmt(d)+'</span>':'<span style="color:#bbb;">—</span>') : d; }
                    },
                    { title: '淨額（出貨－退貨）', className: 'text-right',
                      render: function(d, t) {
                          if (t !== 'display') return d;
                          var col = d > 0 ? '#1a6daa' : (d < 0 ? '#e74c3c' : '#bbb');
                          return '<span style="color:'+col+';font-weight:700;">'+_csAmtFmt(d)+'</span>';
                      }
                    },
                    { title: '前期增減', className: 'text-right',
                      render: function(d, t) {
                          if (t !== 'display') return d === null ? -99999 : d;
                          if (d === null) return '<span style="color:#aaa;">—</span>';
                          if (d === 999) return '<span style="color:#e74c3c;font-weight:600;">▲ 新增</span>';
                          if (d === 0)   return '<span style="color:#aaa;">—</span>';
                          var up = d > 0;
                          return '<span style="color:'+(up?'#e74c3c':'#27ae60')+';font-weight:600;">'+(up?'▲':'▼')+' '+Math.abs(d).toFixed(1)+'%</span>';
                      }
                    }
                ],
                footerCallback: function() {
                    var api = this.api();
                    var ship = api.column(1,{page:'all'}).data().reduce(function(a,b){return a+(b||0);},0);
                    var ret  = api.column(2,{page:'all'}).data().reduce(function(a,b){return a+(b||0);},0);
                    var ord  = api.column(3,{page:'all'}).data().reduce(function(a,b){return a+(b||0);},0);
                    var net  = ship - ret;
                    var nc = net > 0 ? '#1a6daa' : '#e74c3c';
                    $(api.table().footer()).html(
                        '<tr style="background:#e8f4fd;font-weight:700;border-top:2px solid #3498db;">' +
                        '<td>合計（'+api.rows().count()+' 家）</td>' +
                        '<td class="text-right" style="color:#1a6daa;">'+_csAmtFmt(ship)+'</td>' +
                        '<td class="text-right" style="color:#e74c3c;">'+_csAmtFmt(ret)+'</td>' +
                        '<td class="text-right" style="color:#27ae60;">'+_csAmtFmt(ord)+'</td>' +
                        '<td class="text-right" style="color:'+nc+';font-weight:700;">'+_csAmtFmt(net)+'</td>' +
                        '<td></td></tr>'
                    );
                }
            });

            // 分頁移到共用 dt-pagination-holder（與其他表格一致）
            setTimeout(function() {
                $('#clientSummaryTable_paginate').appendTo('#dt-pagination-holder');
                $('#dt-pagination-holder > div').hide();
                if (_currentListTab === 'summary') $('#clientSummaryTable_paginate').show();
            }, 20);

            // 雙擊客戶名稱 → 填入客戶篩選
            $('#clientSummaryTable tbody').off('dblclick').on('dblclick', 'td:first-child', function() {
                var cn = $(this).text().trim();
                if (cn) { $('#filter-client').val(cn).trigger('change'); switchListTab('ship'); }
            });
        }

        function renderClientSummaryTab() {
            var ckw = ($('#filter-client').val()  || '').trim().toLowerCase();
            var pkw = ($('#filter-product').val() || '').trim().toLowerCase();
            var gkw = ($('#global-search').val()  || '').trim().toLowerCase();

            // 有效帳款月份：優先用卡片篩選，否則自動偵測（排除 override 行，避免誤判成多月份）
            var effectiveBm = currentBmFilter;
            if (!effectiveBm) {
                var _bmSet = {};
                shippingData.forEach(function(r) {
                    if (r.is_count != 0 && r.billing_month && !(r.billing_month_override && r.billing_month_override !== ''))
                        _bmSet[r.billing_month] = 1;
                });
                var _bmKeys = Object.keys(_bmSet);
                if (_bmKeys.length === 1) effectiveBm = _bmKeys[0];
            }

            // 計算前一個帳款月份
            var prevBm = null;
            if (effectiveBm) {
                var _p = effectiveBm.split('-'), _py = parseInt(_p[0]), _pm = parseInt(_p[1]);
                _pm--; if (_pm <= 0) { _pm = 12; _py--; }
                prevBm = _py + '-' + (_pm < 10 ? '0'+_pm : _pm);
            }

            // 更新說明文字
            var periodLabel = effectiveBm
                ? '帳款月份：' + effectiveBm + (prevBm ? '　前期比較：' + prevBm : '')
                : '（顯示全部已載入資料；前期增減需選擇帳款月份卡片）';
            $('#summary-period-label').text(periodLabel);

            // 過濾函式
            function passFilter(r, bm, gFields) {
                if (bm && r.billing_month !== bm) return false;
                if (ckw && (r.Client_name||'').toLowerCase().indexOf(ckw) === -1) return false;
                if (pkw && (r.Product_id ||'').toLowerCase().indexOf(pkw) === -1) return false;
                if (gkw && !gFields.some(function(f){ return (f||'').toLowerCase().indexOf(gkw) !== -1; })) return false;
                return true;
            }

            // 聚合函式（從已載入資料，排除 override 行以確保與列表一致）
            function aggregate(bm) {
                var cs = {};
                function add(name, field, val) {
                    name = (name||'未知客戶').trim();
                    if (!cs[name]) cs[name] = {ship:0,ret:0,ord:0};
                    cs[name][field] += parseFloat(val)||0;
                }
                shippingData.forEach(function(r) {
                    if (r.is_count==0) return;
                    if (r.billing_month_override && r.billing_month_override !== '') return; // 排除 override 行
                    if (!passFilter(r, bm, [r.IS_number,r.Product_id,r.Note,r.Content])) return;
                    var qty=parseFloat(r.Qty)||0, price=parseFloat(r.Unit_price)||0;
                    if (price>0) add(r.Client_name,'ship',qty*price);
                });
                irData.forEach(function(r) {
                    if (!passFilter(r, bm, [r.IR_no,r.Product_id,r.IR_ps])) return;
                    add(r.Client_name,'ret',r.amount);
                });
                orderData.forEach(function(r) {
                    if (!passFilter(r, bm, [r.Order_oo,r.Product_id,r.Order_ps])) return;
                    add(r.Client_name,'ord',r.amount);
                });
                return cs;
            }

            var curr = aggregate(effectiveBm);

            if (!prevBm) {
                _buildClientSummaryTable(curr, null, null);
                return;
            }

            // 先試從已載入資料取前期
            var prevFromLoaded = aggregate(prevBm);
            var hasPrevData = Object.keys(prevFromLoaded).length > 0;

            if (hasPrevData) {
                _buildClientSummaryTable(curr, prevFromLoaded, prevBm);
            } else {
                // 已載入資料無前期 → AJAX 取得
                $('#summary-period-label').text(periodLabel + '　正在載入前期資料…');
                $.post('', {action:'get_prev_bm_stats', billing_month: prevBm}, function(res) {
                    var r = typeof res==='string' ? JSON.parse(res) : res;
                    if (r.success && r.data) {
                        // 轉成與 aggregate() 相同格式
                        var prevAjax = {};
                        Object.keys(r.data).forEach(function(cn) {
                            prevAjax[cn] = {ship: r.data[cn].ship||0, ret: r.data[cn].ret||0, ord: r.data[cn].ord||0};
                        });
                        $('#summary-period-label').text(periodLabel + '　（前期範圍：' + r.range + '）');
                        _buildClientSummaryTable(curr, prevAjax, prevBm);
                    } else {
                        $('#summary-period-label').text(periodLabel + '　（前期資料取得失敗）');
                        _buildClientSummaryTable(curr, {}, prevBm);
                    }
                });
            }
        }

        window.switchListTab = function(type) {
            _currentListTab = type;
            // 更新卡片高亮（外框顏色跟左側顏色相同）
            var colorMap = { ship: '#3498db', return: '#e74c3c', order: '#27ae60' };
            $('#stat-card-ship, #stat-card-return, #stat-card-order, #stat-card-net')
                .removeClass('active-tab')
                .css({ 'border-color': 'transparent', 'color': '' });
            var cardId = { ship: '#stat-card-ship', return: '#stat-card-return', order: '#stat-card-order' }[type];
            if (cardId) {
                var c = colorMap[type];
                $(cardId).addClass('active-tab').css({
                    'border-color': c,
                    'border-left-color': c,
                    'color': c,
                    'box-shadow': '0 4px 14px ' + c + '40'
                });
            }

            // 更新 Tab 按鈕與標題底色（與上方卡片左側顏色相同的淡色背景）
            $('.list-tab-btn').removeClass('active').css({'color': '', 'border-bottom-color': ''});
            $('#tab-btn-' + type).addClass('active');
            var panelColors = { ship: { bg: 'rgba(52,152,219,.12)', border: '#3498db', active: '#2980b9' },
                                return: { bg: 'rgba(231,76,60,.12)', border: '#e74c3c', active: '#c0392b' },
                                order: { bg: 'rgba(39,174,96,.12)', border: '#27ae60', active: '#219a52' },
                                summary: { bg: 'rgba(142,68,173,.12)', border: '#8e44ad', active: '#7d3c98' } };
            var pc = panelColors[type];
            if (pc) {
                $('#detail-panel-head').css({ 'background': pc.bg, 'border-bottom': '2px solid ' + pc.border });
                $('#detail-panel-head .list-tab-btn.active').css({ 'color': pc.active, 'border-bottom-color': pc.active });
            }

            // 顯示對應內容
            $('.tab-content-pane').hide();
            $('#tab-content-' + type).show();

            // 客戶統計 tab：渲染資料，分頁與其他表格共用 holder
            if (type === 'summary') {
                $('#dt-pagination-holder > div').hide();
                $('#clientSummaryTable_paginate').show();
                renderClientSummaryTab();
                return;
            }

            // 隱藏所有分頁，只顯示當前表格的分頁
            $('#dt-pagination-holder > div').hide();
            var tblId = type==='ship'?'shippingTable':type==='return'?'returnTable':'orderListTable';
            $('#' + tblId + '_paginate').show();

            // 初始化子 DataTable（懶初始化）
            if (type === 'return' && !_returnTableInited) {
                _returnTableInited = true;
                var rtDT = $('#returnTable').DataTable({
                    dom: 'rtip', data: irData, pageLength: 15, lengthChange: false,
                    order: [[0, 'desc']],
                    language: { url: '//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json',
                                emptyTable: '此期間無退貨紀錄', zeroRecords: '無符合的退貨紀錄' },
                    columns: [
                        { data: null, orderable: false, className: 'text-center',
                          render: function(data, type, row) {
                              return '<button type="button" class="btn btn-xs btn-default btn-ir-bm-edit" data-ir-id="' + row.IR_id + '" data-bmo="' + (row.billing_month_override||'') + '" title="修改帳款月份"><i class="fa fa-pencil-square-o"></i></button>';
                          }
                        },
                        { data: 'billing_month', defaultContent: '',
                          render: function(data, type, row) {
                              if (type !== 'display') return data || '';
                              var hasOverride = row.billing_month_override && row.billing_month_override !== '';
                              return hasOverride
                                  ? '<span style="color:#8e44ad;font-weight:600;" title="手動設定">' + (data||'') + ' <i class="fa fa-pencil" style="font-size:9px;"></i></span>'
                                  : (data || '');
                          }
                        },
                        { data: 'IR_date' },
                        { data: 'IR_no' },
                        { data: 'Client_name_display', render: $.fn.dataTable.render.text() },
                        { data: 'Product_id', render: $.fn.dataTable.render.text() },
                        { data: 'Specification', defaultContent: '', render: $.fn.dataTable.render.text() },
                        { data: 'Qty', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                        { data: 'Unit_price', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                        { data: 'amount', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                        { data: 'IR_ps', defaultContent: '', render: $.fn.dataTable.render.text() }
                    ]
                });
                if (currentBmFilter) rtDT.column(1).search('^' + currentBmFilter + '$', true, false);
                var _rp = $('#filter-product').val(); if (_rp) rtDT.column(5).search(_rp);
                var _rs = $('#filter-spec').val();    if (_rs) rtDT.column(6).search(_rs);
                rtDT.draw();
                setTimeout(function() {
                    $('#returnTable_paginate').appendTo('#dt-pagination-holder').hide();
                    if (_currentListTab === 'return') $('#returnTable_paginate').show();
                }, 20);
            } else if (type === 'order' && !_orderTableInited) {
                _orderTableInited = true;
                var olDT = $('#orderListTable').DataTable({
                    dom: 'rtip', data: orderData, pageLength: 15, lengthChange: false,
                    order: [[0, 'desc']],
                    language: { url: '//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json',
                                emptyTable: '此期間無訂單資料', zeroRecords: '無符合的訂單' },
                    columns: [
                        { data: 'billing_month', defaultContent: '',
                          render: function(data, type, row) {
                              if (type !== 'display') return data || '';
                              var hasOverride = row.billing_month_override && row.billing_month_override !== '';
                              return hasOverride
                                  ? '<span style="color:#8e44ad;font-weight:600;" title="手動設定">' + (data||'') + ' <i class="fa fa-pencil" style="font-size:9px;"></i></span>'
                                  : (data || '');
                          }
                        },
                        { data: 'Delivery_date' },
                        { data: 'Order_oo', defaultContent: '' },
                        { data: 'Client_name_display', render: $.fn.dataTable.render.text() },
                        { data: 'Product_id', render: $.fn.dataTable.render.text() },
                        { data: 'Processing_items', defaultContent: '', render: $.fn.dataTable.render.text() },
                        { data: 'Qty', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                        { data: 'Unit_price', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                        { data: 'amount', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                        { data: 'Order_ps', defaultContent: '', render: $.fn.dataTable.render.text() }
                    ]
                });
                if (currentBmFilter) olDT.column(0).search('^' + currentBmFilter + '$', true, false);
                var _op = $('#filter-product').val(); if (_op) olDT.column(4).search(_op);
                var _os = $('#filter-spec').val();    if (_os) olDT.column(5).search(_os);
                olDT.draw();
                setTimeout(function() {
                    $('#orderListTable_paginate').appendTo('#dt-pagination-holder').hide();
                    if (_currentListTab === 'order') $('#orderListTable_paginate').show();
                }, 20);
            }

            // 翻頁移回容器（重新顯示 ship tab 時）
            if (type === 'ship') {
                setTimeout(function() { $('#shippingTable_paginate').appendTo('#dt-pagination-holder'); }, 20);
            }

            // 更新排行榜
            updateRankingPanels(type);
        };

        function updateRankingPanels(type) {
            var data, dateField, amtField, pidField, clientField, titleClients, titleProducts;
            if (type === 'ship') {
                data = null; // ship 使用 updateStatistics() 已計算的結果，直接還原 PHP 初始值
                $('#top-clients-title').html('<i class="fa fa-trophy"></i> 前五大出貨客戶');
                $('#top-products-title').html('<i class="fa fa-star"></i> 十大熱銷產品');
                $('#top-products-avg-th').text('平均出貨量(pcs)');
                $('#top-products-count-th').text('出貨次數');
                // 觸發 DataTable draw 以重算出貨排行榜
                var tbl = $('#shippingTable').DataTable();
                tbl.draw(false);
                return;
            } else if (type === 'return') {
                data = irData; amtField = 'amount'; pidField = 'Product_id'; clientField = 'Client_name';
                titleClients = '<i class="fa fa-trophy"></i> 前五大退貨客戶';
                titleProducts = '<i class="fa fa-star"></i> 十大退貨產品';
                $('#top-products-avg-th').text('平均退貨量(pcs)');
                $('#top-products-count-th').text('退貨次數');
            } else if (type === 'order') {
                data = orderData; amtField = 'amount'; pidField = 'Product_id'; clientField = 'Client_name';
                titleClients = '<i class="fa fa-trophy"></i> 前五大接單客戶';
                titleProducts = '<i class="fa fa-star"></i> 十大熱銷接單產品';
                $('#top-products-avg-th').text('平均接單量(pcs)');
                $('#top-products-count-th').text('接單次數');
            }
            $('#top-clients-title').html(titleClients);
            $('#top-products-title').html(titleProducts);

            // 套用當前篩選條件過濾資料
            var _ckw = ($('#filter-client').val()  || '').trim().toLowerCase();
            var _pkw = ($('#filter-product').val() || '').trim().toLowerCase();
            var _gkw = ($('#global-search').val()  || '').trim().toLowerCase();
            var filteredData = data.filter(function(r) {
                if (currentBmFilter && r.billing_month !== currentBmFilter) return false;
                if (_ckw && (r.Client_name || '').toLowerCase().indexOf(_ckw) === -1) return false;
                if (_pkw && (r.Product_id  || '').toLowerCase().indexOf(_pkw) === -1) return false;
                if (_gkw) {
                    var _fields = type === 'return'
                        ? [(r.IR_no||''), (r.Product_id||''), (r.IR_ps||'')]
                        : [(r.Order_oo||''), (r.Product_id||''), (r.Order_ps||'')];
                    if (!_fields.some(function(f){ return f.toLowerCase().indexOf(_gkw) !== -1; })) return false;
                }
                return true;
            });

            // 計算排行
            var clientStats = {}, productStats = {};
            filteredData.forEach(function(r) {
                var amt = parseFloat(r[amtField]) || 0;
                if (amt <= 0) return;
                var c = (r[clientField] || '未知客戶').trim();
                clientStats[c] = (clientStats[c] || 0) + amt;
                var pid = (r[pidField] || '').trim() || '未知料號';
                if (!productStats[pid]) productStats[pid] = { amount: 0, qty: 0, count: 0 };
                productStats[pid].amount += amt;
                productStats[pid].qty += parseFloat(r.Qty) || 0;
                productStats[pid].count++;
            });

            // 前五大客戶
            var topClients = Object.keys(clientStats).map(function(k){ return [k, clientStats[k]]; }).sort(function(a,b){ return b[1]-a[1]; }).slice(0,5);
            var html = '';
            topClients.forEach(function(item, i) {
                var rk = i+1, cls = rk<=3?'r'+rk:'rn';
                html += '<tr><td><span class="rank-no '+cls+'">'+rk+'</span></td>' +
                    '<td>'+$('<span>').text(item[0]).html()+'</td>' +
                    '<td style="color:#ccc;">—</td>' +
                    '<td class="text-right" style="color:#2980b9;font-weight:600;">'+fmtAmt(item[1])+'</td></tr>';
            });
            $('#top-clients-body').html(html || '<tr><td colspan="4" style="text-align:center;color:#aaa;">無資料</td></tr>');

            // 十大熱銷
            var topProducts = Object.keys(productStats).map(function(k){ return Object.assign({pid:k}, productStats[k]); }).sort(function(a,b){ return b.amount-a.amount; }).slice(0,10);
            var phtml = '';
            topProducts.forEach(function(item, i) {
                var rk=i+1, cls=rk<=3?'r'+rk:'rn';
                var avg = item.count > 0 ? item.qty/item.count : 0;
                phtml += '<tr><td><span class="rank-no '+cls+'">'+rk+'</span></td>' +
                    '<td>'+$('<span>').text(item.pid).html()+'</td>' +
                    '<td class="text-right" style="color:#2980b9;font-weight:600;">'+fmtAmt(item.amount)+'</td>' +
                    '<td class="text-right">'+avg.toFixed(1)+'</td>' +
                    '<td class="text-right">'+item.count+'</td></tr>';
            });
            $('#top-products-tbody').html(phtml || '<tr><td colspan="5" style="text-align:center;color:#aaa;">無資料</td></tr>');
        }

        // ── 月份截止日設定 ──
        var globalCutoffDay = <?= intval($global_cutoff_day) ?>;

        // ── KPI 週報 ──
        (function() {
            var now = new Date();
            var $year = $('#kpi-year'), $month = $('#kpi-month');
            for (var y = now.getFullYear() - 2; y <= now.getFullYear() + 1; y++) {
                $year.append('<option value="'+y+'"'+(y===now.getFullYear()?' selected':'')+'>'+y+'</option>');
            }

            // 從 start_date 推算帳款月（考慮截止日：當天日期 > 截止日則屬於下一個帳款月）
            function calcBillingMonth(startVal) {
                if (!startVal) return null;
                var p = startVal.split('-');
                var sY = parseInt(p[0]), sM = parseInt(p[1]), sD = parseInt(p[2]);
                if (globalCutoffDay > 0 && sD > globalCutoffDay) {
                    return sM === 12 ? { y: sY + 1, m: 1 } : { y: sY, m: sM + 1 };
                }
                return { y: sY, m: sM };
            }

            var bm = calcBillingMonth($('#start_date').val());
            if (!bm) {
                // 無篩選日期時，依今日推算帳款月
                var d = now.getDate(), m = now.getMonth() + 1, yr = now.getFullYear();
                bm = (globalCutoffDay > 0 && d > globalCutoffDay)
                    ? (m === 12 ? { y: yr+1, m: 1 } : { y: yr, m: m+1 })
                    : { y: yr, m: m };
            }

            $year.val(bm.y);
            $month.val(bm.m);
            $('#kpi-start-day').val(globalCutoffDay > 0 ? globalCutoffDay + 1 : 1);
        })();

        window.toggleKpiPanel = function() {
            var $p = $('#kpi-panel');
            if ($p.is(':hidden')) {
                $p.slideDown(250, function() {
                    $p[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                $('#btn-kpi-toggle').addClass('active').css('background','#2980b9').css('color','#fff');
                loadKpiData();
            } else {
                $p.slideUp(200);
                $('#btn-kpi-toggle').removeClass('active').css('background','').css('color','');
            }
        };

        window.saveKpiFooter = function() {
            $.post('', {
                action: 'save_kpi_footer',
                footer_left:   $('#kpi-footer-left').val(),
                footer_center: $('#kpi-footer-center').val(),
                footer_right:  $('#kpi-footer-right').val()
            }, function(res) {
                var r = typeof res === 'string' ? JSON.parse(res) : res;
                if (r.success) {
                    var footer = { left: $('#kpi-footer-left').val(), center: $('#kpi-footer-center').val(), right: $('#kpi-footer-right').val() };
                    renderKpiFooter(footer);
                } else { alert('儲存失敗：' + r.message); }
            });
        };

        window.saveAndLoadKpi = function() {
            var year = parseInt($('#kpi-year').val());
            var month = parseInt($('#kpi-month').val());
            var target = (parseFloat($('#kpi-target').val()) || 0) * 10000; // input in 萬, store as raw
            var startDay = parseInt($('#kpi-start-day').val()) || (globalCutoffDay + 1);
            $.post('', { action:'save_kpi_target', year:year, month:month, target_amount:target, start_day:startDay }, function(res) {
                var r = typeof res === 'string' ? JSON.parse(res) : res;
                if (r.success) loadKpiData();
                else alert('儲存失敗：' + r.message);
            });
        };

        var _lastKpiData = null;

        window.loadKpiData = function() {
            var year = parseInt($('#kpi-year').val());
            var month = parseInt($('#kpi-month').val());
            $('#kpi-loading').show();
            $('#kpi-content').hide();
            $.post('', { action:'get_kpi_data', year:year, month:month }, function(res) {
                var r = typeof res === 'string' ? JSON.parse(res) : res;
                $('#kpi-loading').hide();
                if (!r.success) { alert('載入失敗：' + r.message); return; }
                _lastKpiData = r;
                $('#kpi-target').val(r.settings.target_amount > 0 ? parseFloat((r.settings.target_amount / 10000).toFixed(4)) : '');
                $('#kpi-start-day').val(r.settings.start_day);
                renderKpiTable(r);
                renderKpiChart(r);
                $('#kpi-content').show();
            });
        };

        // 當年月變更時重新載入
        $('#kpi-year, #kpi-month').on('change', function() {
            if ($('#kpi-panel').is(':visible')) loadKpiData();
        });

        function fmtAmt(v) {
            var n = Math.round(v);
            if (Math.abs(v) >= 10000) return '$' + (v/10000).toFixed(1) + '萬';
            return '$' + n.toLocaleString() + (n !== 0 ? '元' : '');
        }
        function fmtAmtTarget(v) {
            if (!v) return '$0萬';
            var n = v / 10000;
            return '$' + (n % 1 === 0 ? n : parseFloat(n.toFixed(2))) + '萬';
        }
        // 上升=紅色，下降=綠色
        function fmtN(v) { return v == null ? '-' : (v >= 0 ? '<span style="color:#e74c3c;">▲'+v.toFixed(2)+'%</span>' : '<span style="color:#27ae60;">▼'+Math.abs(v).toFixed(2)+'%</span>'); }
        function fmtPct(v) {
            var cls = v >= 100 ? 'color:#27ae60;font-weight:700' : (v >= 80 ? 'color:#e67e22' : 'color:#e74c3c');
            return '<span style="'+cls+'">'+v.toFixed(2)+'%</span>';
        }

        function renderKpiTable(r) {
            var weeks = r.weeks, totals = r.totals;
            var monthLabel = r.settings.year + '年' + r.settings.month + '月';

            // 摘要磚
            var kpi_order = totals.order_rate, kpi_rev = totals.revenue_rate;
            $('#kpi-summary-row').html(
                '<div style="background:#ebf5fb;border:1px solid #aed6f1;border-radius:6px;padding:8px 14px;min-width:120px;">' +
                  '<div style="font-size:10px;color:#5d6d7e;">月份</div>' +
                  '<div style="font-size:16px;font-weight:700;color:#2c3e50;">'+monthLabel+'</div>' +
                  '<div style="font-size:10px;color:#999;">'+r.billing_start+' ～ '+r.billing_end+'</div>' +
                '</div>' +
                '<div style="background:#eafaf1;border:1px solid #a9dfbf;border-radius:6px;padding:8px 14px;min-width:120px;">' +
                  '<div style="font-size:10px;color:#5d6d7e;">月目標金額</div>' +
                  '<div style="font-size:16px;font-weight:700;color:#27ae60;">'+fmtAmtTarget(r.settings.target_amount)+'</div>' +
                '</div>' +
                '<div style="background:#fef9e7;border:1px solid #f9e79f;border-radius:6px;padding:8px 14px;min-width:120px;">' +
                  '<div style="font-size:10px;color:#5d6d7e;">月份受訂 KPI</div>' +
                  '<div style="font-size:16px;font-weight:700;">'+fmtPct(kpi_order)+'</div>' +
                '</div>' +
                '<div style="background:#fef5fb;border:1px solid #d7bde2;border-radius:6px;padding:8px 14px;min-width:120px;">' +
                  '<div style="font-size:10px;color:#5d6d7e;">月銷貨額 KPI</div>' +
                  '<div style="font-size:16px;font-weight:700;">'+fmtPct(kpi_rev)+'</div>' +
                '</div>'
            );

            var thead = '<tr style="background:#1a2634;color:#ecf0f1;text-align:center;">' +
                ['日期','周目標','接單金額','接單達成率','銷售額','退貨金額','當週營收','累進營收','目標達成率','與上月同週'].map(function(t){return '<th style="padding:5px 6px;white-space:nowrap;font-size:11px;">'+t+'</th>';}).join('') + '</tr>';
            $('#kpi-thead').html(thead);

            var today = new Date(); today.setHours(0,0,0,0);
            var tbody = '';
            weeks.forEach(function(w) {
                var bg = w.no % 2 === 0 ? '#f8f9fa' : '#fff';
                var ws = new Date(w.start + 'T00:00:00'), we = new Date(w.end + 'T00:00:00');
                var isCurWeek = today >= ws && today <= we;
                var rowStyle = 'background:'+bg+';text-align:right;' + (isCurWeek ? 'outline:2px solid #e67e22;outline-offset:-1px;' : '');
                var tdBorder = isCurWeek ? 'border-top:2px solid #e67e22;border-bottom:2px solid #e67e22;' : '';
                tbody += '<tr style="'+rowStyle+'">' +
                    '<td style="text-align:center;white-space:nowrap;font-size:11px;'+tdBorder+(isCurWeek?'border-left:2px solid #e67e22;font-weight:700;':'')+'">W'+w.no+'&nbsp;<span style="color:#999;font-size:10px;">'+w.start.slice(5)+'～'+w.end.slice(5)+'</span></td>' +
                    '<td style="'+tdBorder+'">'+fmtAmt(w.week_target)+'</td>' +
                    '<td style="'+tdBorder+'">'+fmtAmt(w.order_amount)+'</td>' +
                    '<td style="text-align:center;'+tdBorder+'">'+fmtPct(w.order_rate)+'</td>' +
                    '<td style="'+tdBorder+'">'+fmtAmt(w.ship_amount)+'</td>' +
                    '<td style="color:#e74c3c;'+tdBorder+'">'+(w.return_amount===0?'—':(w.return_amount>0?'−':'+')+ fmtAmt(Math.abs(w.return_amount)))+'</td>' +
                    '<td style="font-weight:600;'+tdBorder+'">'+fmtAmt(w.revenue)+'</td>' +
                    '<td style="'+tdBorder+'">'+fmtAmt(w.cum_revenue)+'</td>' +
                    '<td style="text-align:center;'+tdBorder+'">'+fmtPct(w.revenue_rate)+'</td>' +
                    '<td style="text-align:center;'+(isCurWeek?'border-right:2px solid #e67e22;':'')+tdBorder+'">'+fmtN(w.change_rate)+'</td>' +
                    '</tr>';
            });
            $('#kpi-tbody').html(tbody);

            var tfoot = '<tr style="background:#d5d8dc;font-weight:700;text-align:right;">' +
                '<td style="text-align:center;">合計</td>' +
                '<td>-</td>' +
                '<td>'+fmtAmt(totals.order_amount)+'</td>' +
                '<td style="text-align:center;">'+fmtPct(totals.order_rate)+'</td>' +
                '<td>'+fmtAmt(totals.ship_amount)+'</td>' +
                '<td style="color:#e74c3c;">'+(totals.return_amount===0?'—':(totals.return_amount>0?'−':'+')+fmtAmt(Math.abs(totals.return_amount)))+'</td>' +
                '<td style="font-weight:700;">'+fmtAmt(totals.revenue)+'</td>' +
                '<td>'+fmtAmt(totals.revenue)+'</td>' +
                '<td style="text-align:center;">'+fmtPct(totals.revenue_rate)+'</td>' +
                '<td>-</td></tr>';
            $('#kpi-tfoot').html(tfoot);

            // 大額前三名
            function renderTop3(items) {
                if (!items || items.length === 0) return '<div style="color:#aaa;font-size:11px;padding:6px 0;">無資料</div>';
                var h = '';
                items.forEach(function(it, i) {
                    var medals = ['🥇','🥈','🥉'];
                    var amt = parseInt(it.amount) || 0;
                    h += '<div style="display:flex;align-items:center;gap:6px;padding:5px 0;border-bottom:1px solid #f0f2f5;">' +
                        '<span style="font-size:14px;">'+medals[i]+'</span>' +
                        '<div style="flex:1;min-width:0;">' +
                          '<div style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' +
                            $('<span>').text(it.Client_name || '').html() +
                            ' <span style="color:#888;font-weight:400;">' + $('<span>').text(it.Product_id || '').html() + '</span>' +
                          '</div>' +
                          (it.sale_type_name ? '<div style="font-size:10px;color:#e67e22;">'+$('<span>').text(it.sale_type_name).html()+'</div>' : '') +
                        '</div>' +
                        '<div style="font-size:13px;font-weight:700;color:#2980b9;white-space:nowrap;">'+fmtAmt(amt)+'</div>' +
                    '</div>';
                });
                return h;
            }

            var topShip = r.top_ship || [], topShipExcl = r.top_ship_excl || [];
            var topOrder = r.top_order || [], topReturn = r.top_return || [];

            var largeHtml = '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">';

            // 大額出貨
            largeHtml += '<div style="background:#f0f8ff;border:1px solid #aed6f1;border-radius:6px;padding:10px;">' +
                '<div style="font-size:12px;font-weight:700;color:#2980b9;margin-bottom:6px;"><i class="fa fa-truck"></i> 大額出貨前三名</div>' +
                renderTop3(topShip) +
                (topShipExcl.length > 0 ?
                    '<div style="margin-top:8px;padding-top:6px;border-top:1px dashed #e0e0e0;">' +
                    '<div style="font-size:10px;color:#e67e22;margin-bottom:4px;"><i class="fa fa-info-circle"></i> 不列入計算之出貨</div>' +
                    renderTop3(topShipExcl) +
                    '</div>' : '') +
                '</div>';

            // 大額訂單
            largeHtml += '<div style="background:#f0fff4;border:1px solid #a9dfbf;border-radius:6px;padding:10px;">' +
                '<div style="font-size:12px;font-weight:700;color:#27ae60;margin-bottom:6px;"><i class="fa fa-file-text-o"></i> 大額訂單前三名</div>' +
                renderTop3(topOrder) +
                '</div>';

            // 大額退貨
            largeHtml += '<div style="background:#fff5f5;border:1px solid #f1948a;border-radius:6px;padding:10px;">' +
                '<div style="font-size:12px;font-weight:700;color:#e74c3c;margin-bottom:6px;"><i class="fa fa-undo"></i> 大額退貨前三名</div>' +
                renderTop3(topReturn) +
                '</div>';

            largeHtml += '</div>';
            $('#kpi-large-section').html(largeHtml);

            // 表尾蓋章區
            var footer = r.footer || { left: '', center: '', right: '' };
            $('#kpi-footer-left').val(footer.left);
            $('#kpi-footer-center').val(footer.center);
            $('#kpi-footer-right').val(footer.right);
            renderKpiFooter(footer);
        }

        function renderKpiFooter(footer) {
            var cols = [
                { key: 'left',   label: footer.left   || '' },
                { key: 'center', label: footer.center || '' },
                { key: 'right',  label: footer.right  || '' },
            ];
            var html = '';
            cols.forEach(function(col, i) {
                var borderLeft = i > 0 ? 'border-left:1px solid #bdc3c7;' : '';
                html += '<div style="flex:1;'+borderLeft+'padding:10px 14px;min-height:80px;display:flex;flex-direction:column;">' +
                    '<div style="font-size:11px;font-weight:600;color:#5d6d7e;text-align:left;">' +
                        $('<span>').text(col.label).html() +
                    '</div>' +
                '</div>';
            });
            $('#kpi-footer-display').html(html);
        }

        function renderKpiChart(r) {
            var cats = r.weeks.map(function(w){ return 'W'+w.no; });
            var shipData  = r.weeks.map(function(w){ return Math.round(w.ship_amount/10000*10)/10; });
            var revData   = r.weeks.map(function(w){ return Math.round(w.revenue/10000*10)/10; });
            var lmRevData = r.weeks.map(function(w){ return Math.round(w.lm_revenue/10000*10)/10; });
            var targetData= r.weeks.map(function(w){ return Math.round(w.week_target/10000*10)/10; });

            var chartDiv = document.getElementById('kpi-chart');
            if (!chartDiv) return;
            Highcharts.chart('kpi-chart', {
                chart: {
                    type: 'column',
                    backgroundColor: '#fafbfc',
                    borderRadius: 6,
                    style: { fontFamily: '"Microsoft JhengHei",sans-serif' },
                    animation: { duration: 500 },
                    spacing: [10, 8, 8, 8]
                },
                title: { text: null },
                xAxis: {
                    categories: cats,
                    lineColor: '#dde1e7',
                    tickColor: '#dde1e7',
                    labels: { style: { fontSize: '11px', color: '#5d6d7e' } }
                },
                yAxis: {
                    title: { text: null },
                    gridLineColor: '#eef0f3',
                    labels: { style: { fontSize: '10px', color: '#7f8c8d' }, formatter: function(){ return this.value+'萬'; } }
                },
                tooltip: {
                    shared: true,
                    backgroundColor: 'rgba(255,255,255,.96)',
                    borderColor: '#dde1e7',
                    borderRadius: 8,
                    shadow: true,
                    style: { fontSize: '11px' },
                    formatter: function() {
                        var s = '<b style="font-size:12px;">'+this.x+'</b><br/>';
                        this.points.forEach(function(p){
                            s += '<span style="color:'+p.series.color+'">●</span> '+p.series.name+'：<b>$'+p.y+'萬</b><br/>';
                        });
                        return s;
                    }
                },
                plotOptions: {
                    column: { grouping: true, borderRadius: 4, pointPadding: 0.1, groupPadding: 0.12 },
                    line: { marker: { symbol: 'circle', lineWidth: 1, lineColor: '#fff' } }
                },
                series: [
                    { name: '本月銷售額', type: 'column', data: shipData, color: '#5dade2', opacity: 0.85 },
                    { name: '本月營收',   type: 'column', data: revData,  color: '#58d68d', opacity: 0.85 },
                    { name: '上月同週',   type: 'line',   data: lmRevData,  color: '#f0a500', dashStyle: 'ShortDash', lineWidth: 2, marker: { radius: 4 } },
                    { name: '週目標',     type: 'line',   data: targetData, color: '#e74c3c', dashStyle: 'Dot',       lineWidth: 2, marker: { radius: 4 } }
                ],
                legend: {
                    enabled: true,
                    itemStyle: { fontSize: '10px', fontWeight: '400', color: '#5d6d7e' },
                    symbolRadius: 3
                },
                credits: { enabled: false },
                exporting: { enabled: false }
            });
        }

        window.printKpiReport = function() {
            if (!$('#kpi-content').is(':visible') || !_lastKpiData) { alert('請先載入 KPI 資料'); return; }
            var r = _lastKpiData;
            var monthLabel = r.settings.year + '年' + r.settings.month + '月';

            // 圖表 SVG（配合右側三欄高度）
            var chartSvg = '';
            try {
                var hc = Highcharts.charts.find(function(c){ return c && c.renderTo && c.renderTo.id === 'kpi-chart'; });
                if (hc) chartSvg = hc.getSVG({ chart:{ width: 600, height: 240 } });
            } catch(e) {}

            // 格式化
            function pa(v) {
                if (!v && v !== 0) return '$0';
                var n = Math.round(v);
                if (Math.abs(v) >= 10000) return '$' + (v/10000).toFixed(1) + '萬';
                return '$' + n.toLocaleString() + (n !== 0 ? '元' : '');
            }
            function pat(v) {
                if (!v) return '$0萬';
                var n = v/10000;
                return '$' + (n%1===0 ? n : parseFloat(n.toFixed(2))) + '萬';
            }
            function pp(v) { return (v||0).toFixed(2)+'%'; }
            function pn(v) {
                if (v==null) return '-';
                var col = v>=0 ? '#c0392b' : '#27ae60';
                return '<span style="color:'+col+';">'+(v>=0?'▲':'▼')+Math.abs(v).toFixed(2)+'%</span>';
            }
            function esc(s) { return $('<span>').text(s||'').html(); }

            // ── 摘要磚（標題列右側）──
            var totals = r.totals;
            var summaryHtml =
                '<div class="sc"><div class="scl">月份</div>'+
                  '<div class="scv">'+monthLabel+'</div>'+
                  '<div class="scs">'+r.billing_start+'～'+r.billing_end+'</div>'+
                '</div>'+
                '<div class="sc"><div class="scl">月目標金額</div>'+
                  '<div class="scv" style="color:#27ae60;">'+pat(r.settings.target_amount)+'</div>'+
                '</div>'+
                '<div class="sc"><div class="scl">月份受訂 KPI</div>'+
                  '<div class="scv">'+pp(totals.order_rate)+'</div>'+
                '</div>'+
                '<div class="sc"><div class="scl">月銷貨額 KPI</div>'+
                  '<div class="scv">'+pp(totals.revenue_rate)+'</div>'+
                '</div>';

            // ── 週報表格（全寬）──
            var today = new Date(); today.setHours(0,0,0,0);
            var tHtml = '<table><thead><tr>'+
                ['日期','周目標','接單金額','接單達成率','銷售額','退貨金額','當週營收','累進營收','目標達成率','與上月同週']
                .map(function(t){ return '<th>'+t+'</th>'; }).join('')+
                '</tr></thead><tbody>';
            r.weeks.forEach(function(w) {
                var ws = new Date(w.start+'T00:00:00'), we = new Date(w.end+'T00:00:00');
                var cur = today>=ws && today<=we;
                tHtml += '<tr'+(cur?' class="cw"':'')+'>'+
                    '<td class="tc">W'+w.no+' <span class="dt">'+w.start.slice(5)+'～'+w.end.slice(5)+'</span></td>'+
                    '<td>'+pa(w.week_target)+'</td>'+
                    '<td>'+pa(w.order_amount)+'</td>'+
                    '<td class="tc">'+pp(w.order_rate)+'</td>'+
                    '<td>'+pa(w.ship_amount)+'</td>'+
                    '<td style="color:#c0392b;">'+(w.return_amount===0?'—':(w.return_amount>0?'−':'+')+pa(Math.abs(w.return_amount)))+'</td>'+
                    '<td class="bold">'+pa(w.revenue)+'</td>'+
                    '<td>'+pa(w.cum_revenue)+'</td>'+
                    '<td class="tc">'+pp(w.revenue_rate)+'</td>'+
                    '<td class="tc">'+pn(w.change_rate)+'</td>'+
                    '</tr>';
            });
            tHtml += '</tbody><tfoot><tr>'+
                '<td class="tc bold">合計</td><td>—</td>'+
                '<td>'+pa(totals.order_amount)+'</td>'+
                '<td class="tc">'+pp(totals.order_rate)+'</td>'+
                '<td>'+pa(totals.ship_amount)+'</td>'+
                '<td style="color:#c0392b;">'+(totals.return_amount===0?'—':(totals.return_amount>0?'−':'+')+pa(Math.abs(totals.return_amount)))+'</td>'+
                '<td class="bold">'+pa(totals.revenue)+'</td>'+
                '<td>'+pa(totals.revenue)+'</td>'+
                '<td class="tc">'+pp(totals.revenue_rate)+'</td>'+
                '<td>—</td></tr></tfoot></table>';

            // ── 大額前三名（簡潔版：編號 客戶 料號 金額）──
            function pTop3(items, accent) {
                if (!items||!items.length) return '<div class="nd">—</div>';
                return '<table class="top3tbl">'+
                    items.map(function(it, i) {
                        var amt = parseInt(it.amount)||0;
                        return '<tr>'+
                            '<td class="t3r" style="color:'+accent+';">'+(i+1)+'</td>'+
                            '<td class="t3c">'+esc(it.Client_name||'')+'</td>'+
                            '<td class="t3p">'+esc(it.Product_id||'')+'</td>'+
                            '<td class="t3a">'+pa(amt)+'</td>'+
                            '</tr>';
                    }).join('')+
                    '</table>';
            }
            var ts=r.top_ship||[], te=r.top_ship_excl||[], to=r.top_order||[], tr2=r.top_return||[];

            // 右欄：三個大額區塊上下疊放
            var largeColHtml =
                '<div class="lb">'+
                  '<div class="lt" style="border-left-color:#3498db;"><span style="color:#3498db;">出貨</span> 大額前三名</div>'+
                  pTop3(ts, '#3498db')+
                  (te.length ? '<div class="et">不列入計算項目</div>'+pTop3(te,'#e67e22') : '')+
                '</div>'+
                '<div class="lb">'+
                  '<div class="lt" style="border-left-color:#27ae60;"><span style="color:#27ae60;">訂單</span> 大額前三名</div>'+
                  pTop3(to, '#27ae60')+
                '</div>'+
                '<div class="lb">'+
                  '<div class="lt" style="border-left-color:#e74c3c;"><span style="color:#e74c3c;">退貨</span> 大額前三名</div>'+
                  pTop3(tr2, '#e74c3c')+
                '</div>';

            // ── 表尾 ──
            var fL=$('#kpi-footer-left').val().trim(), fC=$('#kpi-footer-center').val().trim(), fR=$('#kpi-footer-right').val().trim();
            var footerHtml = (fL||fC||fR) ?
                '<div class="ft"><div class="fc">'+esc(fL)+'</div><div class="fc">'+esc(fC)+'</div><div class="fc">'+esc(fR)+'</div></div>' : '';

            // ── CSS ──
            var css =
                '*{box-sizing:border-box;margin:0;padding:0;}'+
                'body{font-family:"Microsoft JhengHei",sans-serif;font-size:12px;padding:8mm;color:#1a1a1a;}'+

                /* 標題（獨立一行，置頂） */
                '.ptitle{font-size:20px;font-weight:700;margin-bottom:7px;color:#1a2634;}'+
                '.sum{display:flex;gap:6px;margin-bottom:8px;}'+
                '.sc{border:1px solid #bdc3c7;border-radius:3px;padding:4px 8px;flex:1;}'+
                '.scl{font-size:10px;color:#888;margin-bottom:2px;}'+
                '.scv{font-size:15px;font-weight:700;}'+
                '.scs{font-size:9.5px;color:#aaa;}'+

                /* 週報表格 */
                'table{width:100%;border-collapse:collapse;}'+
                'th{background:#1a2634;color:#fff;padding:5px 9px;text-align:center;font-size:13px;white-space:nowrap;}'+
                'td{padding:5px 9px;border:1px solid #dde1e7;font-size:13px;text-align:right;}'+
                '.tc{text-align:center !important;}'+
                '.bold{font-weight:700;}'+
                '.dt{color:#999;font-size:11px;}'+
                'tfoot tr td{background:#d5d8dc;font-weight:700;}'+
                '.cw td{border-top:2px solid #e67e22 !important;border-bottom:2px solid #e67e22 !important;font-weight:700;}'+
                '.cw td:first-child{border-left:2px solid #e67e22 !important;}'+
                '.cw td:last-child{border-right:2px solid #e67e22 !important;}'+

                /* 下半部：圖左 + 大額右 */
                '.lower{display:grid;grid-template-columns:1fr 370px;gap:12px;margin-top:20px;align-items:start;}'+
                '.large-col{display:flex;flex-direction:column;gap:6px;}'+
                '.lb{border:1px solid #dde1e7;border-radius:3px;padding:6px 10px;}'+
                '.lt{font-size:12px;font-weight:700;border-left:3px solid #ccc;padding-left:7px;margin-bottom:5px;}'+

                /* 大額表格 */
                '.top3tbl{width:100%;border-collapse:collapse;}'+
                '.top3tbl tr{border-bottom:1px solid #f0f0f0;}'+
                '.top3tbl tr:last-child{border-bottom:none;}'+
                '.t3r{width:20px;text-align:center !important;font-weight:700;font-size:12px;padding:3px 4px;}'+
                '.t3c{text-align:left !important;padding:3px 6px;font-size:12px;max-width:135px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'+
                '.t3p{text-align:left !important;padding:3px 6px;font-size:11px;color:#555;max-width:125px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'+
                '.t3a{text-align:right !important;font-weight:700;color:#2980b9;white-space:nowrap;padding:3px 4px;font-size:12px;}'+
                '.et{font-size:11px;color:#e67e22;border-top:1px dashed #e0e0e0;padding-top:4px;margin:4px 0 3px;}'+
                '.nd{font-size:11px;color:#aaa;padding:4px 0;}'+

                /* 表尾（無框線，三欄靠左顯示） */
                '.ft{display:grid;grid-template-columns:repeat(3,1fr);margin-top:12px;}'+
                '.fc{padding:6px 0;min-height:62px;font-size:12px;font-weight:600;color:#333;}'+

                '@page{size:A4 landscape;margin:0;}'+
                '@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}';

            // ── 組裝 HTML ──
            var docHtml = '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="utf-8">'+
                '<title>'+monthLabel+' 出貨目標達成率 週報</title><style>'+css+'</style></head><body>'+
                '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:7px;">'+
                  '<div class="ptitle" style="margin:0;">'+monthLabel+' 出貨目標達成率 週報</div>'+
                  '<div style="font-size:10px;color:#aaa;">列印時間：'+new Date().toLocaleString('zh-TW')+'</div>'+
                '</div>'+
                '<div class="sum">'+summaryHtml+'</div>'+
                tHtml+
                '<div class="lower">'+
                  '<div>'+(chartSvg||'')+'</div>'+
                  '<div class="large-col">'+largeColHtml+'</div>'+
                '</div>'+
                footerHtml+
                '</body></html>';

            var w = window.open('', '_blank', 'width=1400,height=900');
            if (!w) { alert('請允許彈出視窗'); return; }
            w.document.write(docHtml); w.document.close(); w.focus();
            setTimeout(function(){ w.print(); }, 1000);
        };

        function openCutoffModal() {
            $('#cutoff_day_input').val(globalCutoffDay);
            updateCutoffPreview();
            $('#cutoffModal').modal('show');
        }

        function updateCutoffPreview() {
            var day = parseInt($('#cutoff_day_input').val()) || 0;
            var html = '';
            if (day <= 0) {
                html = '<i class="fa fa-info-circle"></i> 未設定截止日，整月出貨均歸入同一月份';
            } else {
                html = '<i class="fa fa-check-circle" style="color:#27ae60;"></i> '
                     + '月 1 日 ～ ' + day + ' 日（含）出貨 &rarr; <b>本月</b>帳款<br>'
                     + '<i class="fa fa-arrow-right" style="color:#e67e22;"></i> '
                     + (day + 1) + ' 日 ～ 月底出貨 &rarr; <b>下月</b>帳款';
            }
            $('#cutoff-preview').html(html);
        }

        function saveCutoffDay() {
            var day = parseInt($('#cutoff_day_input').val());
            if (isNaN(day) || day < 0 || day > 31) {
                showToast('截止日必須介於 0 ~ 31', 'danger'); return;
            }
            $.post('', { action: 'save_billing_cutoff', cutoff_day: day }, function(res) {
                if (res.success) {
                    globalCutoffDay = day;
                    showToast(res.message, 'success');
                    $('#cutoffModal').modal('hide');
                    setTimeout(function() {
                        if (confirm('截止日設定已儲存。\n需要重新載入頁面以套用新的帳款月份計算，是否立即重新整理？')) {
                            location.reload();
                        }
                    }, 600);
                } else {
                    showToast(res.message || '儲存失敗', 'danger');
                }
            }, 'json').fail(function() { showToast('網路錯誤，請重試', 'danger'); });
        }

    </script>
</body>
</html>