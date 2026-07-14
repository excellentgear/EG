<?php
// c:\MAMP\htdocs\EGsystem\views\pm\packing_schedule.php
// 包裝製程排程與檢驗回報頁（獨立於待加工排程 process_schedule_NOW.php）
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 登入檢查（相容 AJAX）
if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
        || isset($_POST['action']);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '連線逾時，請重新登入', 'timeout' => true]);
        exit;
    }
    echo "<script>alert('連線逾時，請重新登入'); window.location.href='../../index.php';</script>";
    exit;
}

$db = new DBConnection();
$pdo = $db->getPDO();
$user_id = trim($_SESSION['user_id'] ?? $_SESSION['id'] ?? '');

// 取得使用者中文名
$user_cname = trim($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? '');
if ($user_id && $user_cname === '') {
    try {
        $stmt_u = $pdo->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $stmt_u->execute([$user_id]);
        $r = $stmt_u->fetch(PDO::FETCH_ASSOC);
        if ($r && trim($r['user_cname']) !== '') $user_cname = trim($r['user_cname']);
    } catch (Exception $e) { /* ignore */ }
}
if ($user_cname === '') $user_cname = $user_id ?: '未知';

// =============================================================================
// 資料表初始化（不存在則建立）
// =============================================================================

// 包裝製程編號設定（可多選）
$pdo->exec("CREATE TABLE IF NOT EXISTS pm_packing_process_setting (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
    process_no INT NOT NULL COMMENT '認定為包裝的製程編號，對應 process_no.ProcessNo',
    created_by VARCHAR(11) NULL COMMENT '建立人員',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
    UNIQUE KEY uk_process_no (process_no)
) COMMENT='包裝排程：認定為包裝的製程編號設定（可多選）'");

// 包裝排程手動緊急性與拖曳排序（獨立於待加工 bom_ing.processing_sequence / bom.priority_type）
$pdo->exec("CREATE TABLE IF NOT EXISTS pm_packing_priority (
    bom_ing_fid INT PRIMARY KEY COMMENT '對應 bom_ing.bom_ing_fid',
    priority_type VARCHAR(2) NULL COMMENT 'null=一般 U=急件 E=特急（手動覆寫，僅作用於包裝排程）',
    sort_seq INT NULL COMMENT '手動拖曳排序序號（越小越前面）',
    updated_by VARCHAR(11) NULL COMMENT '最後修改人員',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後修改時間'
) COMMENT='包裝排程：手動緊急性與拖曳排序'");

// 補上 qc_packing_inspection.bom_ing_fid（與 inspection_result_entry.php 預期結構一致）
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_packing_inspection LIKE 'bom_ing_fid'");
    if ($colChk && $colChk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE qc_packing_inspection ADD COLUMN bom_ing_fid INT NULL COMMENT '主要關聯BOM (對應 bom_ing.bom_ing_fid)' AFTER packing_inspection_id");
        $pdo->exec("ALTER TABLE qc_packing_inspection ADD INDEX idx_pki_bom_ing_fid (bom_ing_fid)");
    }
} catch (Exception $e) { /* 忽略：資料表不存在時由 inspection 頁建立 */ }

// 包裝外觀檢驗「預設模板」（全系統一份）
$pdo->exec("CREATE TABLE IF NOT EXISTS pm_packing_appearance_template (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
    item_name VARCHAR(255) NOT NULL COMMENT '檢驗項目',
    standard_text VARCHAR(255) NULL COMMENT '方式/工具/標準',
    sort_order INT DEFAULT 0 COMMENT '排序',
    updated_by VARCHAR(11) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) COMMENT='包裝外觀檢驗預設模板（全系統共用）'");

// 包裝外觀檢驗「料號專用」項目（以 d_setting.d_id 為單位，有專用即覆蓋預設）
$pdo->exec("CREATE TABLE IF NOT EXISTS pm_packing_appearance_item (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
    d_id INT NOT NULL COMMENT '料號版本 (d_setting.d_id)',
    item_name VARCHAR(255) NOT NULL COMMENT '檢驗項目',
    standard_text VARCHAR(255) NULL COMMENT '方式/工具/標準',
    sort_order INT DEFAULT 0 COMMENT '排序',
    created_by VARCHAR(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ppai_d_id (d_id)
) COMMENT='包裝外觀檢驗料號專用項目'");

// 首次建立時，若預設模板為空則放入常用項目（可自行編輯/刪除）
try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM pm_packing_appearance_template")->fetchColumn();
    if ($cnt === 0) {
        $seed = [
            ['外觀(刮傷/碰傷)', '目視'],
            ['毛邊/銳角', '目視/手觸'],
            ['防銹處理', '目視'],
            ['標示(料號/數量)', '目視核對'],
            ['數量', '計數'],
        ];
        $ins = $pdo->prepare("INSERT INTO pm_packing_appearance_template (item_name, standard_text, sort_order, updated_by) VALUES (?, ?, ?, 'System')");
        foreach ($seed as $i => $s) {
            $ins->execute([$s[0], $s[1], $i]);
        }
    }
} catch (Exception $e) { /* ignore */ }

// 取得目前設定的包裝製程編號
function get_packing_process_nos(PDO $pdo): array
{
    $rows = $pdo->query("SELECT process_no FROM pm_packing_process_setting ORDER BY process_no")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

// =============================================================================
// 後端 API
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        $action = $_POST['action'];

        // 1. 取得所有製程編號（供多選設定）
        if ($action === 'list_processes') {
            $rows = $pdo->query("SELECT ProcessNo, ProcessName FROM process_no ORDER BY ProcessNo")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        // 2. 取得目前包裝製程設定
        if ($action === 'get_packing_setting') {
            echo json_encode(['success' => true, 'process_nos' => get_packing_process_nos($pdo)]);
            exit;
        }

        // 3. 儲存包裝製程設定（多選）
        if ($action === 'save_packing_setting') {
            $nos = $_POST['process_nos'] ?? [];
            if (!is_array($nos)) $nos = [];
            $nos = array_values(array_unique(array_map('intval', $nos)));
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM pm_packing_process_setting");
            if (!empty($nos)) {
                $ins = $pdo->prepare("INSERT INTO pm_packing_process_setting (process_no, created_by) VALUES (?, ?)");
                foreach ($nos as $n) {
                    $ins->execute([$n, $user_id]);
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        // 4. 取得包裝排程清單
        if ($action === 'list_boms') {
            $procNos = get_packing_process_nos($pdo);
            if (empty($procNos)) {
                echo json_encode(['success' => true, 'data' => [], 'need_setting' => true]);
                exit;
            }
            $inQuery = implode(',', array_fill(0, count($procNos), '?'));

            $sql = "SELECT
                        bi.bom_ing_fid,
                        bi.bom,
                        bi.process_no,
                        bi.sqty,
                        pn.ProcessName,
                        b.d_id,
                        b.Client_Name,
                        b.priority_type        AS bom_priority,
                        COALESCE(b.Delivery_date, ol.Delivery_date) AS delivery_date,
                        COALESCE(d.D_Setting_Id, b.d_id) AS part_no,
                        d.Revision,
                        pp.priority_type       AS pack_priority,
                        pp.sort_seq
                    FROM bom_ing bi
                    JOIN bom b               ON bi.bom = b.bom
                    LEFT JOIN order_list ol  ON b.o_order_id = ol.Order_id
                    LEFT JOIN process_no pn  ON bi.process_no = pn.ProcessNo
                    LEFT JOIN d_setting d    ON b.d_setting_id = d.d_id
                    LEFT JOIN pm_packing_priority pp ON bi.bom_ing_fid = pp.bom_ing_fid
                    WHERE bi.process_no IN ($inQuery)
                      AND bi.processing_state = 'ing'
                      AND (b.processing_state <> 1 OR b.processing_state IS NULL)
                      AND NOT EXISTS (
                          SELECT 1 FROM qc_packing_inspection qpi
                          WHERE qpi.bom_ing_fid = bi.bom_ing_fid
                      )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($procNos);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $today = strtotime(date('Y-m-d'));
            $priMap = ['E' => 1, 'U' => 2];

            foreach ($rows as &$r) {
                // 有效緊急性：手動覆寫優先，否則沿用訂單(bom)的急件設定
                $r['eff_priority'] = ($r['pack_priority'] !== null && $r['pack_priority'] !== '')
                    ? $r['pack_priority'] : $r['bom_priority'];
                $r['is_overdue'] = (!empty($r['delivery_date']) && strtotime($r['delivery_date']) < $today) ? 1 : 0;
            }
            unset($r);

            // 排序：1.手動序號 2.急件等級 3.交期(近→遠，過期在前) 4.BOM
            usort($rows, function ($a, $b) use ($priMap) {
                $sa = (int)($a['sort_seq'] ?? 0);
                $sb = (int)($b['sort_seq'] ?? 0);
                if ($sa > 0 && $sb > 0) { if ($sa != $sb) return $sa <=> $sb; }
                elseif ($sa > 0) return -1;
                elseif ($sb > 0) return 1;

                $pa = $priMap[$a['eff_priority']] ?? 3;
                $pb = $priMap[$b['eff_priority']] ?? 3;
                if ($pa != $pb) return $pa <=> $pb;

                $da = !empty($a['delivery_date']) ? strtotime($a['delivery_date']) : false;
                $db = !empty($b['delivery_date']) ? strtotime($b['delivery_date']) : false;
                if ($da && $db) { if ($da != $db) return $da <=> $db; }
                elseif ($da) return -1;
                elseif ($db) return 1;

                return strnatcmp($a['bom'], $b['bom']);
            });

            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        // 5. 取得單筆包裝檢驗表單資料（外觀檢驗項目：先料號專用、無則預設模板）
        if ($action === 'get_form') {
            $bom = $_POST['bom'];

            // 取得料號版本 d_id：優先用 b.d_setting_id（精確版次），沒有則以料號字串對應最新版本
            $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(b.d_setting_id, 0),
                                          (SELECT MAX(d.d_id) FROM d_setting d WHERE d.D_Setting_Id = b.d_id)) AS d_id
                                   FROM bom b WHERE b.bom = ? LIMIT 1");
            $stmt->execute([$bom]);
            $dId = $stmt->fetchColumn();
            $dId = $dId ? (int)$dId : null;

            $items = [];
            $source = 'none'; // custom=料號專用 / template=預設模板 / none=皆無
            if ($dId) {
                $st = $pdo->prepare("SELECT id AS item_id, item_name, standard_text FROM pm_packing_appearance_item WHERE d_id = ? ORDER BY sort_order ASC, id ASC");
                $st->execute([$dId]);
                $items = $st->fetchAll(PDO::FETCH_ASSOC);
                if ($items) $source = 'custom';
            }
            if (!$items) {
                $st = $pdo->query("SELECT id AS item_id, item_name, standard_text FROM pm_packing_appearance_template ORDER BY sort_order ASC, id ASC");
                $items = $st->fetchAll(PDO::FETCH_ASSOC);
                if ($items) $source = 'template';
            }

            echo json_encode(['success' => true, 'items' => $items, 'source' => $source, 'd_id' => $dId]);
            exit;
        }

        // 5a. 取得預設模板項目
        if ($action === 'get_template') {
            $rows = $pdo->query("SELECT id, item_name, standard_text, sort_order FROM pm_packing_appearance_template ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'items' => $rows]);
            exit;
        }

        // 5b. 儲存預設模板項目（整批覆寫）
        if ($action === 'save_template') {
            $items = $_POST['items'] ?? [];
            if (!is_array($items)) $items = [];
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM pm_packing_appearance_template");
            $ins = $pdo->prepare("INSERT INTO pm_packing_appearance_template (item_name, standard_text, sort_order, updated_by) VALUES (?, ?, ?, ?)");
            foreach ($items as $i => $it) {
                $name = trim($it['name'] ?? '');
                if ($name === '') continue;
                $ins->execute([$name, trim($it['standard'] ?? ''), $i, $user_id]);
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        // 5c. 取得料號專用項目
        if ($action === 'get_custom_items') {
            $dId = (int)$_POST['d_id'];
            $rows = $pdo->prepare("SELECT id, item_name, standard_text, sort_order FROM pm_packing_appearance_item WHERE d_id = ? ORDER BY sort_order ASC, id ASC");
            $rows->execute([$dId]);
            echo json_encode(['success' => true, 'items' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // 5d. 由預設模板複製成料號專用
        if ($action === 'copy_template_to_custom') {
            $dId = (int)$_POST['d_id'];
            if (!$dId) { echo json_encode(['success' => false, 'message' => '無法取得料號']); exit; }
            $tpl = $pdo->query("SELECT item_name, standard_text, sort_order FROM pm_packing_appearance_template ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            if (!$tpl) { echo json_encode(['success' => false, 'message' => '預設模板尚無項目']); exit; }
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM pm_packing_appearance_item WHERE d_id = ?")->execute([$dId]);
            $ins = $pdo->prepare("INSERT INTO pm_packing_appearance_item (d_id, item_name, standard_text, sort_order, created_by) VALUES (?, ?, ?, ?, ?)");
            foreach ($tpl as $i => $t) {
                $ins->execute([$dId, $t['item_name'], $t['standard_text'], $i, $user_id]);
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        // 5e. 儲存料號專用項目（整批覆寫）
        if ($action === 'save_custom_items') {
            $dId = (int)$_POST['d_id'];
            $items = $_POST['items'] ?? [];
            if (!is_array($items)) $items = [];
            if (!$dId) { echo json_encode(['success' => false, 'message' => '無法取得料號']); exit; }
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM pm_packing_appearance_item WHERE d_id = ?")->execute([$dId]);
            $ins = $pdo->prepare("INSERT INTO pm_packing_appearance_item (d_id, item_name, standard_text, sort_order, created_by) VALUES (?, ?, ?, ?, ?)");
            foreach ($items as $i => $it) {
                $name = trim($it['name'] ?? '');
                if ($name === '') continue;
                $ins->execute([$dId, $name, trim($it['standard'] ?? ''), $i, $user_id]);
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        // 5f. 刪除料號專用（還原為使用預設模板）
        if ($action === 'delete_custom') {
            $dId = (int)$_POST['d_id'];
            $pdo->prepare("DELETE FROM pm_packing_appearance_item WHERE d_id = ?")->execute([$dId]);
            echo json_encode(['success' => true]);
            exit;
        }

        // 6. 儲存包裝檢驗結果（寫入 qc_packing_inspection + qc_packing_inspection_data）
        if ($action === 'save_result') {
            $bomIngFid = (int)$_POST['bom_ing_fid'];
            $orderQty = intval($_POST['order_qty'] ?? 0);
            $ngQty = intval($_POST['ng_qty'] ?? 0);
            $packagingData = $_POST['packaging_data'] ?? null;
            $remark = $_POST['remark'] ?? '';
            $okQty = $orderQty - $ngQty;
            $judgement = ($ngQty > 0) ? 'FAIL' : 'PASS';

            $pdo->beginTransaction();
            $sql = "INSERT INTO qc_packing_inspection
                    (bom_ing_fid, inspection_date, order_qty, inspected_qty, ok_qty, ng_qty, judgement, inspector, packer, remark)
                    VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $bomIngFid, $orderQty, $orderQty, $okQty, $ngQty, $judgement,
                $user_cname, $user_cname, $remark
            ]);
            $pkgId = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO qc_packing_inspection_data (packing_inspection_id, data_json) VALUES (?, ?)")
                ->execute([$pkgId, json_encode($packagingData)]);
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => '包裝檢驗紀錄已儲存', 'pkg_id' => $pkgId]);
            exit;
        }

        // 7. 設定手動緊急性
        if ($action === 'set_priority') {
            $fid = (int)$_POST['bom_ing_fid'];
            $pri = $_POST['priority_type'] ?? '';
            // 'E'特急 / 'U'急件 / 'N'明確設為一般（'N' 用來壓過訂單繼承的急件等級）
            $pri = in_array($pri, ['E', 'U'], true) ? $pri : 'N';
            $sql = "INSERT INTO pm_packing_priority (bom_ing_fid, priority_type, updated_by)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE priority_type = VALUES(priority_type), updated_by = VALUES(updated_by)";
            $pdo->prepare($sql)->execute([$fid, $pri, $user_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        // 8. 儲存拖曳排序
        if ($action === 'save_sort') {
            $order = $_POST['order'] ?? []; // bom_ing_fid 陣列，依顯示順序
            if (!is_array($order)) $order = [];
            $pdo->beginTransaction();
            $sql = "INSERT INTO pm_packing_priority (bom_ing_fid, sort_seq, updated_by)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE sort_seq = VALUES(sort_seq), updated_by = VALUES(updated_by)";
            $stmt = $pdo->prepare($sql);
            $seq = 1;
            foreach ($order as $fid) {
                $stmt->execute([(int)$fid, $seq++, $user_id]);
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => '未知的動作']);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>包裝製程排程</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet">
    <style>
        /* 隱藏數字輸入框上下箭頭 */
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; appearance: textfield; }

        /* ===== 緊湊清單表格（仿 QC_check_list_test.php）===== */
        .pk-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 0; }
        .pk-table thead th {
            background: #F3F6FA; color: #5A6A7E; font-weight: 700; font-size: 12px;
            letter-spacing: .3px; padding: 9px 8px; border-bottom: 2px solid #E4E9F0;
            white-space: nowrap; vertical-align: middle;
        }
        .pk-table tbody td {
            padding: 6px 8px; border-bottom: 1px solid #F0F4F8;
            vertical-align: middle; color: #3D4B5C; white-space: nowrap;
        }
        .pk-row { cursor: pointer; }
        .pk-row:hover td { background-color: #F7FBFF; }
        .pk-drag-handle { cursor: grab; color: #c0c0c0; font-size: 14px; }
        .pk-drag-handle:hover { color: #337ab7; }
        .pk-drag-handle:active { cursor: grabbing; }

        /* 左側緊急色條（細） */
        .pk-row.row-pri-E td:first-child { box-shadow: inset 4px 0 0 #E74C3C; }
        .pk-row.row-pri-U td:first-child { box-shadow: inset 4px 0 0 #F5A623; }
        .pk-row.row-pri-normal td:first-child { box-shadow: inset 4px 0 0 transparent; }

        /* 交期欄（單行） */
        .due-date { font-weight: 700; color: #34495e; }
        .due-tag { display: inline-block; margin-left: 6px; padding: 0 7px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .due-overdue { background: #fdecea; color: #d9534f; }
        .due-soon    { background: #fff4e5; color: #e8920c; }
        .due-ok      { background: #eafaf1; color: #27ae60; }
        .due-none    { color: #aaa; font-style: italic; }

        .bom-code { font-weight: 700; color: #2c3e50; }
        .rev-badge { display:inline-block; background:#eef1f4; color:#667; border-radius:4px; padding:0 5px; font-size:11px; margin-left:4px; }
        .pk-pri-select { font-weight: 600; height: 28px; padding: 2px 6px; }

        .sortable-ghost { opacity: 0.35; }
        .sortable-ghost td { background: #d9edf7 !important; }
        .sortable-chosen td { background: #fffbe6 !important; }

        .pk-count-badge { font-size: 13px; color:#888; font-weight: normal; }

        /* 可移動視窗 */
        .pk-float-window {
            position: fixed;
            top: 80px; left: 50%;
            transform: translateX(-50%);
            width: 900px; max-width: 95vw;
            max-height: 88vh;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.3);
            z-index: 10060;
            display: none;
            flex-direction: column;
        }
        .pk-float-header {
            padding: 10px 15px;
            background: #337ab7;
            color: #fff;
            border-radius: 6px 6px 0 0;
            cursor: move;
            user-select: none;
            flex: 0 0 auto;
        }
        .pk-float-header .close { color: #fff; opacity: 0.9; }
        .pk-float-body {
            padding: 15px;
            overflow-y: auto;
            flex: 1 1 auto;
        }
        .pk-float-footer {
            padding: 10px 15px;
            border-top: 1px solid #eee;
            text-align: right;
            flex: 0 0 auto;
        }
        .pk-section-title { background: #eee; padding: 5px 10px; font-weight: bold; margin-top: 15px; margin-bottom: 10px; border-left: 3px solid #337ab7; }
        .pkg-table td { vertical-align: middle !important; }
        .pkg-checkbox-group label { margin-right: 10px; cursor: pointer; }
        .pkg-other-input { display: inline-block; width: auto; margin-left: 5px; }
        .pkg-row { margin-bottom: 5px; }
        .pkg-remove { color: #d9534f; cursor: pointer; margin-left: 5px; }
        .ng-value { background-color: #f2dede !important; color: #a94442; font-weight: bold; }
        #pk-window-overlay {
            position: fixed; top:0; left:0; right:0; bottom:0;
            background: rgba(0,0,0,0.25); z-index: 10055; display:none;
        }
        /* 項目編輯 Modal 需高於浮動視窗(10060) */
        #itemEditModal { z-index: 10070; }
        #pk-src-badge .label { font-size: 12px; vertical-align: middle; }
    </style>
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title_left">
                            <h3>包裝製程排程 <small>Packing Schedule</small></h3>
                        </div>
                        <div class="title_right">
                            <button class="btn btn-default pull-right" id="btn-setting"><i class="fa fa-cog"></i> 包裝製程設定</button>
                            <button class="btn btn-default pull-right" id="btn-template" style="margin-right:8px;"><i class="fa fa-list-alt"></i> 外觀檢驗模板</button>
                            <button class="btn btn-default pull-right" id="btn-refresh" style="margin-right:8px;"><i class="fa fa-refresh"></i> 重新整理</button>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_content">
                                    <p class="text-muted" style="margin-bottom:14px;">
                                        <i class="fa fa-info-circle"></i>
                                        依訂單交期由近到遠排序（<span class="text-danger">逾期排最上面</span>）；可調整急件等級或拖曳
                                        <i class="fa fa-bars"></i> 手把調整順序。點擊任一列開啟包裝檢驗填寫視窗。
                                        <span class="pk-count-badge pull-right" id="list-count"></span>
                                    </p>
                                    <div id="list-msg"></div>
                                    <table class="table pk-table">
                                        <thead>
                                            <tr>
                                                <th width="36"></th>
                                                <th width="160">交期</th>
                                                <th width="120">緊急性</th>
                                                <th>製程</th>
                                                <th>客戶</th>
                                                <th>BOM</th>
                                                <th>料號 / 版次</th>
                                                <th width="80" class="text-right">數量</th>
                                            </tr>
                                        </thead>
                                        <tbody id="bom-list">
                                            <tr><td colspan="8" class="text-center text-muted">載入中...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 包裝製程設定 Modal -->
    <div class="modal fade" id="settingModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title"><i class="fa fa-cog"></i> 包裝製程設定（可多選）</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted">選擇要視為「包裝製程」的製程編號，可多選。設定後此頁僅顯示這些製程的 BOM。</p>
                    <select id="setting-process" class="form-control" multiple style="width:100%;"></select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="btn-save-setting">儲存設定</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 外觀檢驗項目編輯 Modal（模板 / 料號專用 共用） -->
    <div class="modal fade" id="itemEditModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title"><i class="fa fa-list-alt"></i> <span id="item-edit-title">外觀檢驗項目</span></h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted" id="item-edit-hint"></p>
                    <table class="table table-bordered" style="margin-bottom:8px;">
                        <thead>
                            <tr style="background:#f5f5f5;">
                                <th width="40">#</th>
                                <th>檢驗項目</th>
                                <th width="220">方式 / 工具</th>
                                <th width="50"></th>
                            </tr>
                        </thead>
                        <tbody id="item-edit-tbody"></tbody>
                    </table>
                    <button type="button" class="btn btn-default btn-sm" id="btn-item-add-row"><i class="fa fa-plus"></i> 新增項目</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="btn-item-save">儲存</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 可移動的包裝檢驗填寫視窗 -->
    <div id="pk-window-overlay"></div>
    <div class="pk-float-window" id="pkWindow">
        <div class="pk-float-header" id="pkWindowHeader">
            <button type="button" class="close" id="pkWindowClose">&times;</button>
            <h4 style="margin:0;"><i class="fa fa-cube"></i> 包裝檢驗回報 <small id="pk-win-sub" style="color:#cde6ff;"></small></h4>
        </div>
        <div class="pk-float-body">
            <!-- 表頭資訊 -->
            <div class="well well-sm" style="background:#f9f9f9;">
                <div class="row">
                    <div class="col-md-3"><strong>BOM：</strong><span id="f-bom"></span></div>
                    <div class="col-md-3"><strong>料號：</strong><span id="f-part"></span></div>
                    <div class="col-md-3"><strong>版次：</strong><span id="f-rev"></span></div>
                    <div class="col-md-3"><strong>客戶：</strong><span id="f-client"></span></div>
                </div>
                <div class="row" style="margin-top:6px;">
                    <div class="col-md-3"><strong>製程：</strong><span id="f-proc"></span></div>
                    <div class="col-md-3">
                        <strong>訂單數量：</strong>
                        <input type="number" id="f-order-qty" class="form-control input-sm" style="display:inline-block;width:100px;">
                    </div>
                    <div class="col-md-3"><strong>交期：</strong><span id="f-delivery"></span></div>
                </div>
            </div>

            <!-- 1. 外觀檢驗 -->
            <div class="pk-section-title" style="display:flex; align-items:center; justify-content:space-between;">
                <span>1. 外觀檢驗 <span id="pk-src-badge"></span></span>
                <span id="pk-appearance-actions" style="font-weight:normal;"></span>
            </div>
            <table class="table table-bordered pkg-table">
                <thead>
                    <tr>
                        <th width="20%">項目</th>
                        <th width="15%">方式/工具</th>
                        <th width="15%">異常數量</th>
                        <th>處置狀況 / 備註</th>
                    </tr>
                </thead>
                <tbody id="pkg-appearance-tbody"></tbody>
                <tfoot id="pkg-appearance-tfoot" style="background:#f9f9f9; font-weight:bold;"></tfoot>
            </table>

            <!-- 2. 防護與備註 -->
            <div class="pk-section-title">2. 防護與備註</div>
            <div class="row">
                <div class="col-md-6">
                    <label>加強防銹：</label>
                    <div class="pkg-checkbox-group">
                        <label><input type="checkbox" class="pkg-rust" value="防銹袋"> 防銹袋</label>
                        <label><input type="checkbox" class="pkg-rust" value="防銹油"> 防銹油</label>
                        <span style="display:inline-block;">
                            <label><input type="checkbox" class="pkg-rust" value="其他"> 其他</label>
                            <input type="text" class="form-control input-sm pkg-other-input pkg-rust-other" placeholder="說明" style="display:none;">
                        </span>
                    </div>
                </div>
                <div class="col-md-6">
                    <label>確認防撞：</label>
                    <div class="pkg-checkbox-group">
                        <div style="margin-bottom:5px;">
                            <label><input type="checkbox" class="pkg-collision" value="泡殼"> 泡殼</label>
                            (<input type="number" class="form-control input-sm pkg-collision-detail" style="width:50px;display:inline-block;" placeholder="入"> 入 x
                            <input type="number" class="form-control input-sm pkg-collision-detail-2" style="width:50px;display:inline-block;" placeholder="個"> 個)
                        </div>
                        <label><input type="checkbox" class="pkg-collision" value="隔板"> 隔板</label>
                        <label><input type="checkbox" class="pkg-collision" value="氣泡紙"> 氣泡紙</label>
                        <label><input type="checkbox" class="pkg-collision" value="報紙"> 報紙</label>
                        <span style="display:inline-block;">
                            <label><input type="checkbox" class="pkg-collision" value="其他"> 其他</label>
                            <input type="text" class="form-control input-sm pkg-other-input pkg-collision-other" placeholder="說明" style="display:none;">
                        </span>
                    </div>
                </div>
            </div>
            <div class="row" style="margin-top:10px; background:#f9f9f9; padding:10px; border-radius:4px;">
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-addon">治具/模具/量具 歸還</span>
                        <input type="number" id="pkg-return-jig" class="form-control" placeholder="數量">
                        <span class="input-group-addon">個</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-addon">樣品 歸還</span>
                        <input type="number" id="pkg-return-sample" class="form-control" placeholder="數量">
                        <span class="input-group-addon">個</span>
                    </div>
                </div>
            </div>

            <!-- 3. 容器與出貨 -->
            <div class="pk-section-title">3. 容器與出貨</div>
            <div id="pkg-rows-container"></div>
            <button class="btn btn-default btn-sm" id="btn-add-pkg-row"><i class="fa fa-plus"></i> 新增容器</button>
            <div class="row" style="margin-top:10px;">
                <div class="col-md-6">
                    <label>實際出貨數量說明：</label>
                    <input type="text" id="pkg-shipment-desc" class="form-control input-sm" placeholder="例如: 100 x 5 桶 + 20 = 520">
                </div>
                <div class="col-md-6">
                    <label>成品入庫方式：</label>
                    <div class="form-inline">
                        <label class="radio-inline"><input type="radio" name="pkg-storage-method" value="direct" checked> 直接入庫</label>
                        <label class="radio-inline"><input type="radio" name="pkg-storage-method" value="pallet"> 棧板+膠膜</label>
                        <input type="number" id="pkg-pallet-qty" class="form-control input-sm" style="width:80px;display:inline-block;" placeholder="棧板數">
                    </div>
                    <div class="form-inline" style="margin-top:5px;">
                        <label>實際入庫數：</label>
                        <input type="number" id="pkg-actual-qty" class="form-control input-sm" style="width:100px;" placeholder="實際數量">
                        <span class="text-muted small">(預設: 訂單數 - NG數)</span>
                    </div>
                </div>
            </div>

            <!-- 備註 -->
            <div class="pk-section-title">4. 備註</div>
            <textarea id="pkg-remark" class="form-control" rows="2" placeholder="輸入此包裝檢驗的相關備註..."></textarea>
        </div>
        <div class="pk-float-footer">
            <button type="button" class="btn btn-default" id="pkWindowCancel">取消</button>
            <button type="button" class="btn btn-success" id="btn-save-pkg"><i class="fa fa-save"></i> 儲存包裝檢驗</button>
        </div>
    </div>

    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
    <script>
    $(function () {
        var API = 'packing_schedule.php';
        var currentRow = null;       // 目前開啟視窗的列資料
        var currentItems = [];       // 目前檢驗項目
        var currentDId = null;       // 目前料號 d_id
        var currentSource = 'none';  // custom / template / none
        var sortableInstance = null;
        var itemEditMode = 'template'; // template / custom

        // 數字格式：小數點後皆為0則省略
        function fmtNum(v) {
            if (v === null || v === undefined || v === '') return '';
            var n = parseFloat(v);
            if (isNaN(n)) return v;
            return (n % 1 === 0) ? n.toString() : n.toString();
        }

        function rowPriClass(p) {
            if (p === 'E') return 'row-pri-E';
            if (p === 'U') return 'row-pri-U';
            return 'row-pri-normal';
        }
        // 交期欄位 HTML（含逾期/倒數天數）
        function dueCell(dateStr) {
            if (!dateStr) return '<span class="due-none">無交期</span>';
            var d = new Date(dateStr + 'T00:00:00');
            var today = new Date(); today.setHours(0, 0, 0, 0);
            var diff = Math.round((d - today) / 86400000);
            var tag, cls;
            if (diff < 0)      { cls = 'due-overdue'; tag = '逾期 ' + (-diff) + ' 天'; }
            else if (diff === 0) { cls = 'due-overdue'; tag = '今天到期'; }
            else if (diff <= 7) { cls = 'due-soon'; tag = '剩 ' + diff + ' 天'; }
            else               { cls = 'due-ok'; tag = '剩 ' + diff + ' 天'; }
            return '<span class="due-date">' + dateStr + '</span><span class="due-tag ' + cls + '">' + tag + '</span>';
        }

        // ---------- 載入清單 ----------
        function loadList() {
            $('#bom-list').html('<tr><td colspan="9" class="text-center text-muted">載入中...</td></tr>');
            $.post(API, { action: 'list_boms' }, function (res) {
                if (!res.success) { alert('載入失敗: ' + res.message); return; }
                if (res.need_setting) {
                    $('#list-msg').html('<div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> 尚未設定包裝製程編號，請點右上角「包裝製程設定」。</div>');
                    $('#bom-list').html('<tr><td colspan="8" class="text-center text-muted">—</td></tr>');
                    $('#list-count').text('');
                    return;
                }
                $('#list-msg').html('');
                if (!res.data.length) {
                    $('#bom-list').html('<tr><td colspan="8" class="text-center text-muted" style="padding:30px;"><i class="fa fa-check-circle text-success" style="font-size:22px;"></i><br>目前沒有待包裝檢驗的 BOM</td></tr>');
                    $('#list-count').text('');
                    return;
                }
                $('#list-count').html('共 <strong>' + res.data.length + '</strong> 筆待包裝');
                var html = '';
                res.data.forEach(function (r) {
                    var rev = r.Revision ? ('<span class="rev-badge">版 ' + r.Revision + '</span>') : '';
                    html += '<tr class="pk-row ' + rowPriClass(r.eff_priority) + '" data-fid="' + r.bom_ing_fid + '">' +
                        '<td class="text-center"><i class="fa fa-bars pk-drag-handle" title="拖曳調整順序"></i></td>' +
                        '<td>' + dueCell(r.delivery_date) + '</td>' +
                        '<td>' +
                          '<select class="form-control input-sm pk-pri-select" onclick="event.stopPropagation();">' +
                            '<option value="" ' + (r.eff_priority !== 'E' && r.eff_priority !== 'U' ? 'selected' : '') + '>一般</option>' +
                            '<option value="U" ' + (r.eff_priority === 'U' ? 'selected' : '') + '>急件</option>' +
                            '<option value="E" ' + (r.eff_priority === 'E' ? 'selected' : '') + '>特急</option>' +
                          '</select>' +
                        '</td>' +
                        '<td><span class="label label-info" style="font-size:12px;">' + (r.ProcessName || ('製程' + r.process_no)) + '</span></td>' +
                        '<td>' + (r.Client_Name || '') + '</td>' +
                        '<td><span class="bom-code">' + r.bom + '</span></td>' +
                        '<td>' + (r.part_no || '') + ' ' + rev + '</td>' +
                        '<td class="text-right"><strong>' + fmtNum(r.sqty) + '</strong></td>' +
                        '</tr>';
                });
                $('#bom-list').html(html);
                // 把列資料存到 DOM
                $('#bom-list tr').each(function (i) {
                    $(this).data('row', res.data[i]);
                });
                initSortable();
            }, 'json').fail(function () { alert('連線失敗'); });
        }

        // ---------- 拖曳排序 ----------
        function initSortable() {
            var el = document.getElementById('bom-list');
            if (sortableInstance) { sortableInstance.destroy(); sortableInstance = null; }
            sortableInstance = new Sortable(el, {
                handle: '.pk-drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function () {
                    var order = [];
                    $('#bom-list tr').each(function () {
                        var fid = $(this).data('fid');
                        if (fid) order.push(fid);
                    });
                    $.post(API, { action: 'save_sort', order: order }, function (res) {
                        if (!res.success) { alert('排序儲存失敗: ' + res.message); }
                    }, 'json');
                }
            });
        }

        // ---------- 緊急性下拉 ----------
        $(document).on('change', '.pk-pri-select', function (e) {
            e.stopPropagation();
            var $tr = $(this).closest('tr');
            var fid = $tr.data('fid');
            var pri = $(this).val();
            $.post(API, { action: 'set_priority', bom_ing_fid: fid, priority_type: pri }, function (res) {
                if (res.success) { loadList(); } // 重新排序
                else alert('設定失敗: ' + res.message);
            }, 'json');
        });

        // ---------- 點列開啟視窗 ----------
        $(document).on('click', '.pk-row', function () {
            var r = $(this).data('row');
            if (!r) return;
            openWindow(r);
        });

        function openWindow(r) {
            currentRow = r;
            $('#f-bom').text(r.bom);
            $('#f-part').text(r.part_no || '');
            $('#f-rev').text(r.Revision || '');
            $('#f-client').text(r.Client_Name || '');
            $('#f-proc').text(r.ProcessName || ('製程' + r.process_no));
            $('#f-delivery').text(r.delivery_date || '無');
            $('#f-order-qty').val(r.sqty || 0);
            $('#pk-win-sub').text('- ' + r.bom);

            // 重置表單
            $('#pkg-appearance-tbody').empty();
            $('#pkg-appearance-tfoot').empty();
            $('.pkg-rust, .pkg-collision').prop('checked', false);
            $('.pkg-rust-other, .pkg-collision-other, .pkg-collision-detail, .pkg-collision-detail-2').val('');
            $('#pkg-return-jig, #pkg-return-sample, #pkg-shipment-desc, #pkg-pallet-qty, #pkg-actual-qty, #pkg-remark').val('');
            $('input[name="pkg-storage-method"][value="direct"]').prop('checked', true);
            $('#pkg-rows-container').empty();

            // 載入外觀檢驗項目（先料號專用、無則預設模板）
            $.post(API, { action: 'get_form', bom: r.bom }, function (res) {
                if (!res.success) { alert('載入失敗: ' + res.message); return; }
                currentItems = res.items || [];
                currentDId = res.d_id || null;
                currentSource = res.source || 'none';
                renderSourceBadge();
                renderAppearance();
                addPkgRow();
                calcActualQty();
                showWindow();
            }, 'json');
        }

        function renderSourceBadge() {
            var badge = '', actions = '';
            if (currentSource === 'custom') {
                badge = '<span class="label label-success">本料號專用</span>';
                actions = '<button type="button" class="btn btn-xs btn-default" id="btn-edit-custom"><i class="fa fa-pencil"></i> 編輯專用項目</button> ' +
                          '<button type="button" class="btn btn-xs btn-link text-danger" id="btn-revert-custom">還原為預設</button>';
            } else if (currentSource === 'template') {
                badge = '<span class="label label-default">預設模板</span>';
                actions = '<button type="button" class="btn btn-xs btn-warning" id="btn-copy-custom"><i class="fa fa-copy"></i> 複製成此料號專用</button>';
            } else {
                badge = '<span class="label label-warning">尚無項目</span>';
                actions = '<button type="button" class="btn btn-xs btn-primary" id="btn-open-template2"><i class="fa fa-list-alt"></i> 去設定預設模板</button>';
            }
            if (!currentDId && currentSource === 'template') {
                actions = '<span class="text-muted small">此 BOM 無對應料號，無法建立專用</span>';
            }
            $('#pk-src-badge').html(badge);
            $('#pk-appearance-actions').html(actions);
        }

        function renderAppearance() {
            var $tbody = $('#pkg-appearance-tbody').empty();
            if (currentItems.length === 0) {
                $tbody.html('<tr><td colspan="4" class="text-center text-muted">無檢驗項目</td></tr>');
                return;
            }
            currentItems.forEach(function (item) {
                var disp = ['無', '已處理', '其他'];
                var dispHtml = '<div class="btn-group" data-toggle="buttons">';
                disp.forEach(function (d) {
                    dispHtml += '<label class="btn btn-default btn-xs"><input type="checkbox" value="' + d + '"> ' + d + '</label>';
                });
                dispHtml += '</div><input type="text" class="form-control input-sm pkg-other-input" placeholder="說明" style="display:none;margin-top:5px;">';
                $tbody.append(
                    '<tr data-pkg-id="' + item.item_id + '">' +
                    '<td>' + item.item_name + '</td>' +
                    '<td>' + (item.standard_text || '目視') + '</td>' +
                    '<td><input type="number" class="form-control input-sm pkg-ng-qty" style="width:80px;" placeholder="0" min="0"></td>' +
                    '<td>' + dispHtml + '</td>' +
                    '</tr>'
                );
            });
        }

        function calcActualQty() {
            var orderQty = parseFloat($('#f-order-qty').val()) || 0;
            var totalNg = 0;
            $('.pkg-ng-qty').each(function () { totalNg += (parseFloat($(this).val()) || 0); });
            $('#pkg-actual-qty').val(orderQty - totalNg);
            $('#pkg-appearance-tfoot').html(
                '<tr><td colspan="4" class="text-right" style="font-size:1.05em;">' +
                '訂單數量: <span class="text-primary">' + orderQty + '</span> - ' +
                'NG總數: <span class="text-danger">' + totalNg + '</span> = ' +
                '<span class="text-success">小計(OK): ' + (orderQty - totalNg) + '</span></td></tr>'
            );
        }

        $(document).on('input', '.pkg-ng-qty', function () {
            if ((parseFloat($(this).val()) || 0) > 0) $(this).addClass('ng-value'); else $(this).removeClass('ng-value');
            calcActualQty();
        });
        $('#f-order-qty').on('input', calcActualQty);

        // "其他" 輸入框顯示 + 無/已處理 互斥
        $(document).on('change', '.pkg-rust, .pkg-collision, #pkg-appearance-tbody input[type="checkbox"]', function () {
            var val = $(this).val();
            var checked = $(this).prop('checked');
            if ($(this).closest('#pkg-appearance-tbody').length > 0 && checked) {
                var $g = $(this).closest('.btn-group');
                if (val === '無') $g.find('input[value="已處理"]').prop('checked', false).parent().removeClass('active');
                else if (val === '已處理') $g.find('input[value="無"]').prop('checked', false).parent().removeClass('active');
            }
            if (val === '其他') {
                var $td = $(this).closest('td');
                var $input = $td.length ? $td.find('.pkg-other-input') : $(this).closest('span').find('.pkg-other-input');
                if (checked) $input.show(); else $input.hide();
            }
        });

        // 容器列
        function addPkgRow(data) {
            data = data || {};
            var type = data.type || '';
            var owner = data.owner || 'customer';
            var qty = data.qty || '';
            var known = ['鐵桶', '塑膠桶', '紙箱', '蝴蝶籠', '鐵架', '木箱'];
            var isOther = (type !== '' && known.indexOf(type) === -1);
            var name = 'owner_' + Math.floor(performance.now() * 1000);
            var html = '<div class="pkg-row form-inline">' +
                '<label>容器:</label> ' +
                '<select class="form-control input-sm pkg-type">' +
                known.map(function (k) { return '<option value="' + k + '" ' + (type === k ? 'selected' : '') + '>' + k + '</option>'; }).join('') +
                '<option value="其他" ' + (isOther ? 'selected' : '') + '>其他</option>' +
                '</select> ' +
                (isOther ? '<input type="text" class="form-control input-sm pkg-type-other" value="' + type + '" placeholder="輸入容器名稱"> ' : '') +
                '<label style="margin-left:10px;">來源:</label> ' +
                '<label class="radio-inline"><input type="radio" name="' + name + '" value="customer" ' + (owner === 'customer' ? 'checked' : '') + '> 客供</label> ' +
                '<label class="radio-inline"><input type="radio" name="' + name + '" value="internal" ' + (owner === 'internal' ? 'checked' : '') + '> 超正</label> ' +
                '<label class="radio-inline"><input type="radio" name="' + name + '" value="noprint" ' + (owner === 'noprint' ? 'checked' : '') + '> 無印刷</label> ' +
                '<label style="margin-left:10px;">數量:</label> ' +
                '<input type="number" class="form-control input-sm pkg-qty" value="' + qty + '" style="width:80px;"> ' +
                '<i class="fa fa-times pkg-remove"></i>' +
                '</div>';
            $('#pkg-rows-container').append(html);
        }
        $('#btn-add-pkg-row').click(function () { addPkgRow(); });
        $(document).on('click', '.pkg-remove', function () { $(this).closest('.pkg-row').remove(); });
        $(document).on('input', '.pkg-qty', calcActualQty);
        $(document).on('change', '.pkg-type', function () {
            if ($(this).val() === '其他') {
                if ($(this).next('.pkg-type-other').length === 0)
                    $('<input type="text" class="form-control input-sm pkg-type-other" placeholder="輸入容器名稱">').insertAfter($(this));
            } else {
                $(this).next('.pkg-type-other').remove();
            }
        });

        // ---------- 視窗顯示/移動 ----------
        function showWindow() {
            $('#pk-window-overlay').show();
            $('#pkWindow').css('display', 'flex');
        }
        function hideWindow() {
            $('#pkWindow').hide();
            $('#pk-window-overlay').hide();
        }
        $('#pkWindowClose, #pkWindowCancel').click(hideWindow);
        $('#pk-window-overlay').click(hideWindow);
        // 使視窗可拖曳移動
        $('#pkWindow').draggable({ handle: '#pkWindowHeader', cancel: '.close' });

        // ---------- 儲存 ----------
        $('#btn-save-pkg').click(function () {
            if (!currentRow) return;

            // 外觀資料
            var appearance = {};
            var totalNg = 0;
            $('#pkg-appearance-tbody tr[data-pkg-id]').each(function () {
                var id = $(this).data('pkg-id');
                var ngQty = $(this).find('.pkg-ng-qty').val();
                totalNg += (parseFloat(ngQty) || 0);
                var disp = [];
                $(this).find('input[type="checkbox"]:checked').each(function () { disp.push($(this).val()); });
                appearance[id] = {
                    ng_qty: ngQty,
                    disposition: disp,
                    other_text: $(this).find('.pkg-other-input').val(),
                    tool: $(this).find('td:eq(1)').text()
                };
            });

            var rows = [];
            $('#pkg-rows-container .pkg-row').each(function () {
                var type = $(this).find('.pkg-type').val();
                if (type === '其他') type = $(this).find('.pkg-type-other').val();
                var owner = $(this).find('input[type=radio]:checked').val();
                var qty = $(this).find('.pkg-qty').val();
                if (type && qty) rows.push({ type: type, owner: owner, qty: qty });
            });

            var rust = []; $('.pkg-rust:checked').each(function () { rust.push($(this).val()); });
            var collision = []; $('.pkg-collision:checked').each(function () { collision.push($(this).val()); });

            var packagingData = {
                appearance: appearance,
                rows: rows,
                rust: rust,
                rust_other: $('.pkg-rust-other').val(),
                collision: collision,
                collision_detail_1: $('.pkg-collision-detail').val(),
                collision_detail_2: $('.pkg-collision-detail-2').val(),
                collision_other: $('.pkg-collision-other').val(),
                return_jig: $('#pkg-return-jig').val(),
                return_sample: $('#pkg-return-sample').val(),
                shipment_desc: $('#pkg-shipment-desc').val(),
                storage_method: $('input[name="pkg-storage-method"]:checked').val(),
                pallet_qty: $('#pkg-pallet-qty').val(),
                actual_qty: $('#pkg-actual-qty').val()
            };

            var $btn = $(this).prop('disabled', true);
            $.post(API, {
                action: 'save_result',
                bom_ing_fid: currentRow.bom_ing_fid,
                order_qty: $('#f-order-qty').val(),
                ng_qty: totalNg,
                packaging_data: packagingData,
                remark: $('#pkg-remark').val()
            }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    alert('儲存成功');
                    hideWindow();
                    loadList(); // 已完成的會自動從清單移除
                } else {
                    alert('儲存失敗: ' + res.message);
                }
            }, 'json').fail(function (xhr) {
                $btn.prop('disabled', false);
                alert('連線失敗');
                console.error(xhr.responseText);
            });
        });

        // ---------- 設定 ----------
        $('#btn-setting').click(function () {
            $.post(API, { action: 'list_processes' }, function (res) {
                var $sel = $('#setting-process').empty();
                if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
                res.data.forEach(function (p) {
                    $sel.append('<option value="' + p.ProcessNo + '">' + p.ProcessNo + ' - ' + (p.ProcessName || '') + '</option>');
                });
                $.post(API, { action: 'get_packing_setting' }, function (r2) {
                    $sel.val((r2.process_nos || []).map(String));
                    $sel.select2({ dropdownParent: $('#settingModal'), placeholder: '選擇包裝製程...', width: '100%' });
                    $('#settingModal').modal('show');
                }, 'json');
            }, 'json');
        });
        $('#btn-save-setting').click(function () {
            var vals = $('#setting-process').val() || [];
            $.post(API, { action: 'save_packing_setting', process_nos: vals }, function (res) {
                if (res.success) { $('#settingModal').modal('hide'); loadList(); }
                else alert('儲存失敗: ' + res.message);
            }, 'json');
        });

        // ---------- 外觀檢驗項目編輯（模板 / 專用）----------
        function openItemEditor(mode) {
            itemEditMode = mode;
            if (mode === 'template') {
                $('#item-edit-title').text('外觀檢驗預設模板（全系統共用）');
                $('#item-edit-hint').text('此模板會套用到所有未建立專用項目的料號。');
                $.post(API, { action: 'get_template' }, function (res) {
                    renderItemEditorRows(res.items || []);
                    $('#itemEditModal').modal('show');
                }, 'json');
            } else {
                if (!currentDId) { alert('此 BOM 無對應料號，無法建立專用'); return; }
                $('#item-edit-title').text('本料號專用外觀檢驗項目');
                $('#item-edit-hint').text('儲存後此料號將使用專用項目（覆蓋預設模板）。');
                $.post(API, { action: 'get_custom_items', d_id: currentDId }, function (res) {
                    renderItemEditorRows(res.items || []);
                    $('#itemEditModal').modal('show');
                }, 'json');
            }
        }
        function renderItemEditorRows(items) {
            $('#item-edit-tbody').empty();
            if (!items.length) { addItemRow('', ''); return; }
            items.forEach(function (it) { addItemRow(it.item_name || '', it.standard_text || ''); });
        }
        function addItemRow(name, std) {
            var tr = '<tr>' +
                '<td class="text-center item-seq"></td>' +
                '<td><input type="text" class="form-control input-sm it-name" value="' + (name || '').replace(/"/g, '&quot;') + '" placeholder="例如: 外觀/毛邊/標示"></td>' +
                '<td><input type="text" class="form-control input-sm it-std" value="' + (std || '').replace(/"/g, '&quot;') + '" placeholder="例如: 目視"></td>' +
                '<td class="text-center"><i class="fa fa-times text-danger it-del" style="cursor:pointer;"></i></td>' +
                '</tr>';
            $('#item-edit-tbody').append(tr);
            renumberItemRows();
        }
        function renumberItemRows() {
            $('#item-edit-tbody tr').each(function (i) { $(this).find('.item-seq').text(i + 1); });
        }
        $('#btn-item-add-row').click(function () { addItemRow('', ''); });
        $(document).on('click', '.it-del', function () { $(this).closest('tr').remove(); renumberItemRows(); });
        $('#btn-item-save').click(function () {
            var items = [];
            $('#item-edit-tbody tr').each(function () {
                var name = $(this).find('.it-name').val().trim();
                var std = $(this).find('.it-std').val().trim();
                if (name) items.push({ name: name, standard: std });
            });
            var act = (itemEditMode === 'template') ? 'save_template' : 'save_custom_items';
            var data = { action: act, items: items };
            if (itemEditMode === 'custom') data.d_id = currentDId;
            $.post(API, data, function (res) {
                if (res.success) {
                    $('#itemEditModal').modal('hide');
                    if (currentRow) reloadFormItems(); // 視窗開著就刷新外觀項目
                } else { alert('儲存失敗: ' + res.message); }
            }, 'json');
        });

        // 重新載入目前視窗的外觀檢驗項目
        function reloadFormItems() {
            if (!currentRow) return;
            $.post(API, { action: 'get_form', bom: currentRow.bom }, function (res) {
                if (!res.success) return;
                currentItems = res.items || [];
                currentDId = res.d_id || null;
                currentSource = res.source || 'none';
                renderSourceBadge();
                renderAppearance();
                calcActualQty();
            }, 'json');
        }

        // 頁首：管理預設模板
        $('#btn-template').click(function () { openItemEditor('template'); });

        // 視窗內動作按鈕（委派）
        $(document).on('click', '#btn-edit-custom', function () { openItemEditor('custom'); });
        $(document).on('click', '#btn-open-template2', function () { openItemEditor('template'); });
        $(document).on('click', '#btn-copy-custom', function () {
            if (!currentDId) { alert('此 BOM 無對應料號'); return; }
            $.post(API, { action: 'copy_template_to_custom', d_id: currentDId }, function (res) {
                if (res.success) {
                    currentSource = 'custom';
                    openItemEditor('custom'); // 複製後直接開啟編輯
                } else { alert('複製失敗: ' + res.message); }
            }, 'json');
        });
        $(document).on('click', '#btn-revert-custom', function () {
            if (!confirm('確定刪除此料號的專用項目，還原為使用預設模板？')) return;
            $.post(API, { action: 'delete_custom', d_id: currentDId }, function (res) {
                if (res.success) reloadFormItems();
                else alert('還原失敗: ' + res.message);
            }, 'json');
        });

        // ---------- 全頁輸入體驗：Enter 跳下一欄、聚焦自動全選 ----------
        function pkFocusable($scope) {
            return $scope.find('input[type=text], input[type=number], textarea, select')
                .filter(':visible:enabled')
                .filter(function () {
                    return !$(this).attr('readonly') && !$(this).hasClass('select2-search__field');
                });
        }
        // Enter：移到下一個輸入欄位（textarea 不攔截，保留換行）
        $(document).on('keydown', 'input[type=text], input[type=number]', function (e) {
            if (e.key !== 'Enter' && e.keyCode !== 13) return;
            if ($(this).hasClass('select2-search__field')) return;
            e.preventDefault();
            var $scope = $(this).closest('.pk-float-window, .modal-content, form');
            if (!$scope.length) $scope = $(document.body);
            var $f = pkFocusable($scope);
            var idx = $f.index(this);
            if (idx > -1 && idx < $f.length - 1) $f.eq(idx + 1).focus();
            else $(this).blur();
        });
        // 聚焦時若有值自動全選（方便直接取代）
        $(document).on('focus', 'input[type=text], input[type=number]', function () {
            if ($(this).hasClass('select2-search__field')) return;
            var el = this;
            if (el.value !== '') setTimeout(function () { try { el.select(); } catch (err) {} }, 0);
        });

        $('#btn-refresh').click(loadList);

        // 初始
        loadList();
    });
    </script>
</body>
</html>
