<?php
// c:\MAMP\htdocs\EGsystem\views\pm\packing_schedule.php
// 包裝製程排程與檢驗回報頁（獨立於待加工排程 process_schedule_NOW.php）
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";
require_once __DIR__ . '/../../src/common/role_features_helper.php';
require_once __DIR__ . '/../../src/common/confirm_password_lib.php';
require_once __DIR__ . '/../../src/common/packing_notify.php';
require_once __DIR__ . '/../../src/common/people_lib.php';
require_once __DIR__ . '/../../src/common/org_role_lib.php';
require_once __DIR__ . '/../../src/common/qa_abnormal_lib.php'; // 報廐扣減唯一實作 qab_bom_scrap_qty()（2026-09-24）
require_once __DIR__ . '/../../src/common/packing_process_lib.php'; // 「是不是包裝製程」的唯一實作 pk_packing_process_nos()（2026-09-24）

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

// 補登可指定包裝人員時，供挑選的部門範圍設定（可多選，各部門一律含底下子部門；空＝不限制，全公司皆可選）
$pdo->exec("CREATE TABLE IF NOT EXISTS pm_packing_packer_dept_setting (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
    dept_id INT NOT NULL COMMENT '認定為包裝人員候選範圍的部門，對應 department.id（含子部門）',
    created_by VARCHAR(11) NULL COMMENT '建立人員',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
    UNIQUE KEY uk_dept_id (dept_id)
) COMMENT='包裝排程：補登可指定包裝人員的部門範圍設定（可多選，含子部門）'");

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

// 欄位不存在才新增（重複執行安全）。ADD COLUMN 沒有 IF NOT EXISTS，所有 ALTER 都走這支。
if (!function_exists('pk_ensure_column')) {
    function pk_ensure_column(PDO $pdo, string $table, string $col, string $addSql): void {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col));
            if ($chk && $chk->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN $addSql");
            }
        } catch (Exception $e) { /* 資料表不存在時忽略 */ }
    }
}
if (!function_exists('pk_ensure_index')) {
    function pk_ensure_index(PDO $pdo, string $table, string $idxName, string $addSql): void {
        try {
            $chk = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = " . $pdo->quote($idxName));
            if ($chk && $chk->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `$table` ADD $addSql");
            }
        } catch (Exception $e) { /* 忽略 */ }
    }
}

// qc_packing_inspection 擴充：支援「暫存/結案」狀態機、分批出貨與入庫、管理員補登（2026-09-23）
pk_ensure_column($pdo, 'qc_packing_inspection', 'status',        "status VARCHAR(10) NOT NULL DEFAULT 'closed' COMMENT '包裝紀錄狀態:open=暫存中可續編 closed=已結案鎖定（既有舊資料一律視為已結案）' AFTER judgement");
pk_ensure_column($pdo, 'qc_packing_inspection', 'bom',           "bom VARCHAR(30) NULL COMMENT '快照:製令號碼(對應bom.bom)，供補登搜尋與結案清單篩選' AFTER bom_ing_fid");
pk_ensure_column($pdo, 'qc_packing_inspection', 'part_no',       "part_no VARCHAR(30) NULL COMMENT '快照:料號(對應d_setting.D_Setting_Id)，供結案清單篩選' AFTER customer_name");
pk_ensure_column($pdo, 'qc_packing_inspection', 'bom_total_qty', "bom_total_qty INT NULL COMMENT '快照:BOM總數(bom.sqty)，因BOM可能分批送到包裝，此欄與order_qty意義不同' AFTER order_qty");
pk_ensure_column($pdo, 'qc_packing_inspection', 'ship_now_qty',  "ship_now_qty INT NOT NULL DEFAULT 0 COMMENT '本次直接出貨數量（勾選直接出貨才會有值）' AFTER ok_qty");
pk_ensure_column($pdo, 'qc_packing_inspection', 'warehouse_qty', "warehouse_qty INT NULL COMMENT '本次實際入庫數量' AFTER ship_now_qty");
pk_ensure_column($pdo, 'qc_packing_inspection', 'is_full_shipment', "is_full_shipment TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否勾選直接出貨' AFTER warehouse_qty");
pk_ensure_column($pdo, 'qc_packing_inspection', 'storage_method', "storage_method VARCHAR(20) NULL COMMENT '成品入庫方式:direct/pallet，全部直接出貨(無剩餘入庫量)時可空白' AFTER is_full_shipment");
pk_ensure_column($pdo, 'qc_packing_inspection', 'pallet_qty',    "pallet_qty INT NULL COMMENT '棧板數（storage_method=pallet時）' AFTER storage_method");
pk_ensure_column($pdo, 'qc_packing_inspection', 'packer_id',     "packer_id INT NULL COMMENT '包裝人員 user.id（補登用；一般填寫仍以packer文字快照為準）' AFTER packer");
pk_ensure_column($pdo, 'qc_packing_inspection', 'is_backfill',   "is_backfill TINYINT(1) NOT NULL DEFAULT 0 COMMENT '管理員補登舊資料=1' AFTER remark");
pk_ensure_column($pdo, 'qc_packing_inspection', 'backfill_by',   "backfill_by INT NULL COMMENT '補登操作人 user.id' AFTER is_backfill");
pk_ensure_column($pdo, 'qc_packing_inspection', 'backfill_at',   "backfill_at DATETIME NULL COMMENT '補登操作時間' AFTER backfill_by");
pk_ensure_column($pdo, 'qc_packing_inspection', 'closed_by',     "closed_by INT NULL COMMENT '完成包裝(結案)操作人 user.id' AFTER backfill_at");
pk_ensure_column($pdo, 'qc_packing_inspection', 'closed_at',     "closed_at DATETIME NULL COMMENT '完成包裝(結案)時間' AFTER closed_by");
pk_ensure_column($pdo, 'qc_packing_inspection', 'updated_by',    "updated_by INT NULL COMMENT '最後修改人 user.id' AFTER updated_at");
pk_ensure_column($pdo, 'qc_packing_inspection', 'edit_note',     "edit_note VARCHAR(255) NULL COMMENT '已結案紀錄由管理員解鎖修改時的原因（最後一次）' AFTER updated_by");
pk_ensure_index($pdo, 'qc_packing_inspection', 'idx_pki_status', "INDEX idx_pki_status (bom_ing_fid, status)");
pk_ensure_index($pdo, 'qc_packing_inspection', 'idx_pki_bom',    "INDEX idx_pki_bom (bom)");
pk_ensure_index($pdo, 'qc_packing_inspection', 'idx_pki_insdate', "INDEX idx_pki_insdate (inspection_date)");

// 既有資料（本次改版前寫入的）一律視為已結案：status 預設值已是 'closed'，這裡只需確保欄位不是 NULL（保險）
try { $pdo->exec("UPDATE qc_packing_inspection SET status='closed' WHERE status IS NULL OR status=''"); } catch (Exception $e) {}

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

// 取得目前設定的包裝製程編號——唯一實作在 packing_process_lib.php 的 pk_packing_process_nos()，
// 這裡保留同名薄包裝，本頁其餘程式碼不必逐一改呼叫點。
function get_packing_process_nos(PDO $pdo): array
{
    return pk_packing_process_nos($pdo);
}

// 目前設定的「可選包裝人員」部門（未展開子部門，設定畫面用）
function get_packing_packer_dept_ids(PDO $pdo): array
{
    $rows = $pdo->query("SELECT dept_id FROM pm_packing_packer_dept_setting ORDER BY dept_id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

// 展開含子部門後、實際可選為包裝人員的部門 id（空陣列＝未設定限制，全公司皆可選）
function get_packing_packer_allowed_dept_ids(PDO $pdo): array
{
    $depts = get_packing_packer_dept_ids($pdo);
    if (!$depts) return [];
    $out = [];
    foreach ($depts as $d) $out = array_merge($out, eg_dept_subtree_ids($pdo, $d));
    return array_values(array_unique(array_map('intval', $out)));
}

// =============================================================================
// 權限（module='packing_schedule'）：一般包裝填寫維持既有開放（不因本次新增角色而鎖死既有使用者）
// 僅新增的管理性功能（補登舊資料／解鎖修改已結案紀錄／角色與功能設定）才需要下列功能碼；
// 角色的建立與功能碼勾選在本頁「角色與功能設定」跳窗操作（呼叫共用 Roles_API.php），
// 角色與使用者的對應仍統一在 user_permissions.php 指派（鐵律4：不另開第二套指派介面）
// =============================================================================
$pk_uid = (int)$user_id;
$pk_features = rf_load_user_features($pdo, $pk_uid);
$PK_CAN_BACKFILL = rf_has_feature($pk_features, 'pk_backfill');
$PK_CAN_BACKFILL_PACKER = rf_has_feature($pk_features, 'pk_backfill_change_packer');
$PK_CAN_ADMIN = rf_has_feature($pk_features, 'pk_admin');

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

        // 3a. 取得全部部門（供「可選包裝人員部門」設定的多選清單）
        if ($action === 'list_departments') {
            $rows = $pdo->query("SELECT id, name, level FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        // 3b. 取得目前「補登可指定包裝人員」的部門設定
        if ($action === 'get_packer_dept_setting') {
            echo json_encode(['success' => true, 'dept_ids' => get_packing_packer_dept_ids($pdo)]);
            exit;
        }

        // 3c. 儲存「補登可指定包裝人員」的部門設定（可多選，含子部門；空＝不限制，僅管理員可設）
        if ($action === 'save_packer_dept_setting') {
            if (!$PK_CAN_ADMIN) throw new Exception('無權限，僅管理員可設定');
            $ids = $_POST['dept_ids'] ?? [];
            if (!is_array($ids)) $ids = [];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM pm_packing_packer_dept_setting");
            if (!empty($ids)) {
                $ins = $pdo->prepare("INSERT INTO pm_packing_packer_dept_setting (dept_id, created_by) VALUES (?, ?)");
                foreach ($ids as $d) {
                    $ins->execute([$d, $user_id]);
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
                        bi.bom_sn,
                        bi.sqty,
                        pn.ProcessName,
                        b.d_id,
                        b.sqty                 AS bom_total_qty,
                        b.Client_Name,
                        b.priority_type        AS bom_priority,
                        COALESCE(bopm_agg.min_delivery, b.Delivery_date, ol.Delivery_date) AS delivery_date,
                        bopm_agg.min_delivery  AS order_bound_delivery,
                        bopm_agg.order_cnt,
                        COALESCE(d.D_Setting_Id, b.d_id) AS part_no,
                        d.Revision,
                        pp.priority_type       AS pack_priority,
                        pp.sort_seq,
                        qpi_open.packing_inspection_id AS draft_id
                    FROM bom_ing bi
                    JOIN bom b               ON bi.bom = b.bom
                    LEFT JOIN order_list ol  ON b.o_order_id = ol.Order_id
                    LEFT JOIN process_no pn  ON bi.process_no = pn.ProcessNo
                    LEFT JOIN d_setting d    ON b.d_setting_id = d.d_id
                    LEFT JOIN pm_packing_priority pp ON bi.bom_ing_fid = pp.bom_ing_fid
                    LEFT JOIN (
                        SELECT bopm.bom, MIN(ot.Delivery_date) AS min_delivery, COUNT(*) AS order_cnt
                        FROM bom_order_process_map bopm
                        JOIN order_track ot ON ot.Order_id = bopm.order_id
                        GROUP BY bopm.bom
                    ) bopm_agg ON bopm_agg.bom = bi.bom
                    LEFT JOIN qc_packing_inspection qpi_open
                           ON qpi_open.bom_ing_fid = bi.bom_ing_fid AND qpi_open.status = 'open'
                    WHERE bi.process_no IN ($inQuery)
                      AND bi.processing_state = 'ing'
                      AND (b.processing_state <> 1 OR b.processing_state IS NULL)
                      AND NOT EXISTS (
                          SELECT 1 FROM qc_packing_inspection qpi
                          WHERE qpi.bom_ing_fid = bi.bom_ing_fid AND qpi.status = 'closed'
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
                $r['has_draft'] = !empty($r['draft_id']) ? 1 : 0;
                // 良品數＝BOM總數扣掉「這一站（含）之前已結案配發報廐單號」的確認報廐量（2026-09-24 使用者交辦），
                // 唯一計算 qab_bom_scrap_qty()；沒有報廐時等於原本的 bom_total_qty
                $totalQty = $r['bom_total_qty'] !== null ? (int)$r['bom_total_qty'] : (int)$r['sqty'];
                $r['good_qty'] = $totalQty;
                try {
                    $r['good_qty'] = max(0, $totalQty - qab_bom_scrap_qty($pdo, (string)$r['bom'], (int)$r['bom_sn']));
                } catch (Throwable $e) { /* 算不出來就先當作沒有報廐，不擋畫面 */ }
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

        // 5. 取得單筆包裝檢驗表單資料（外觀檢驗項目：先料號專用、無則預設模板；
        //    另帶回 BOM 總數／訂單綁定交期／若已有暫存中紀錄一併回傳供續編）
        if ($action === 'get_form') {
            $bom = $_POST['bom'];
            $bomIngFid = (int)($_POST['bom_ing_fid'] ?? 0);

            // 取得料號版本 d_id：優先用 b.d_setting_id（精確版次），沒有則以料號字串對應最新版本
            $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(b.d_setting_id, 0),
                                          (SELECT MAX(d.d_id) FROM d_setting d WHERE d.D_Setting_Id = b.d_id)) AS d_id,
                                          b.sqty AS bom_total_qty
                                   FROM bom b WHERE b.bom = ? LIMIT 1");
            $stmt->execute([$bom]);
            $bomRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $dId = $bomRow ? ($bomRow['d_id'] ? (int)$bomRow['d_id'] : null) : null;
            $bomTotalQty = $bomRow ? (int)$bomRow['bom_total_qty'] : null;

            // 良品數＝BOM總數扣掉「這一站（含）之前已結案配發報廐單號」的確認報廐量（2026-09-24 使用者交辦），
            // 唯一計算 qab_bom_scrap_qty()；沒有報廐時等於 bomTotalQty
            $goodQty = $bomTotalQty;
            if ($bomTotalQty !== null && $bomIngFid) {
                $stSn = $pdo->prepare("SELECT bom_sn FROM bom_ing WHERE bom_ing_fid=?");
                $stSn->execute([$bomIngFid]);
                $bomSn = (int)$stSn->fetchColumn();
                try {
                    $goodQty = max(0, $bomTotalQty - qab_bom_scrap_qty($pdo, (string)$bom, $bomSn));
                } catch (Throwable $e) { /* 算不出來就先當作沒有報廐，不擋畫面 */ }
            }

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

            // 訂單綁定交期（bom_order_process_map → order_track，支援多對多）
            $orderBind = [];
            $ob = $pdo->prepare("SELECT ot.Order_id, ot.Order_oo, ot.C_order, ot.Client_name, ot.Delivery_date, ot.Qty AS order_qty, bopm.allocated_qty
                                  FROM bom_order_process_map bopm
                                  JOIN order_track ot ON ot.Order_id = bopm.order_id
                                  WHERE bopm.bom = ? ORDER BY ot.Delivery_date ASC");
            $ob->execute([$bom]);
            $orderBind = $ob->fetchAll(PDO::FETCH_ASSOC);

            // 暫存中的既有紀錄（同一 bom_ing_fid 續編用）
            $draft = null;
            if ($bomIngFid) {
                $dr = $pdo->prepare("SELECT * FROM qc_packing_inspection WHERE bom_ing_fid = ? AND status = 'open' ORDER BY packing_inspection_id DESC LIMIT 1");
                $dr->execute([$bomIngFid]);
                $draft = $dr->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($draft) {
                    $dj = $pdo->prepare("SELECT data_json FROM qc_packing_inspection_data WHERE packing_inspection_id = ? ORDER BY data_id DESC LIMIT 1");
                    $dj->execute([$draft['packing_inspection_id']]);
                    $draft['packaging_data'] = $dj->fetchColumn() ?: null;
                }
            }

            echo json_encode(['success' => true, 'items' => $items, 'source' => $source, 'd_id' => $dId,
                'bom_total_qty' => $bomTotalQty, 'good_qty' => $goodQty, 'order_bind' => $orderBind, 'draft' => $draft]);
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
        //    支援：暫存(status=open，續編同一列不新增)／完成包裝(status=closed，鎖定+通知生管)／
        //    管理員補登舊資料(is_backfill，可指定日期與包裝人員)／已結案紀錄由管理員以操作密碼解鎖修改
        if ($action === 'save_result') {
            $bomIngFid = (int)($_POST['bom_ing_fid'] ?? 0);
            if (!$bomIngFid) throw new Exception('缺少製程資料(bom_ing_fid)');
            $orderQty = intval($_POST['order_qty'] ?? 0);
            $ngQty = intval($_POST['ng_qty'] ?? 0);
            $shipNowQty = intval($_POST['ship_now_qty'] ?? 0);
            $isFullShip = !empty($_POST['is_full_shipment']) ? 1 : 0;
            $storageMethod = trim($_POST['storage_method'] ?? '');
            $palletQty = (($_POST['pallet_qty'] ?? '') !== '') ? intval($_POST['pallet_qty']) : null;
            $packagingData = $_POST['packaging_data'] ?? null;
            $remark = $_POST['remark'] ?? '';
            $complete = !empty($_POST['complete']) ? 1 : 0;
            $isBackfill = !empty($_POST['is_backfill']) ? 1 : 0;
            $judgementIn = trim($_POST['judgement'] ?? '');
            if ($judgementIn !== '' && !in_array($judgementIn, ['PASS', 'FAIL', 'PENDING'], true)) {
                throw new Exception('判定結果不合法');
            }

            $okQty = $orderQty - $ngQty;
            if ($okQty < 0) throw new Exception('NG數量不可大於數量');

            // 直接出貨／成品入庫方式檢核（前端已擋一次，這裡同規則再擋一次＝鐵律8）
            if ($isFullShip) {
                if ($shipNowQty <= 0) throw new Exception('請輸入本次出貨數量');
                if ($shipNowQty > $okQty) throw new Exception('本次出貨數量不可大於可出/入庫數量(' . $okQty . ')');
                $warehouseQty = $okQty - $shipNowQty;
                if ($warehouseQty > 0 && $storageMethod === '') throw new Exception('尚有 ' . $warehouseQty . ' 個需要入庫，請選擇成品入庫方式');
                if ($warehouseQty === 0) { $storageMethod = ''; $palletQty = null; }
            } else {
                $shipNowQty = 0;
                $warehouseQty = (($_POST['warehouse_qty'] ?? '') !== '') ? intval($_POST['warehouse_qty']) : $okQty;
            }

            // BOM／料號／客戶快照（結案清單篩選、通知內文用；一律以資料庫現況為準，不採信前端送來的名稱）
            $biStmt = $pdo->prepare("SELECT bi.bom, bi.process_no, b.sqty AS bom_total_qty, b.Client_Name,
                                            COALESCE(d.D_Setting_Id, b.d_id) AS part_no
                                     FROM bom_ing bi JOIN bom b ON bi.bom = b.bom
                                     LEFT JOIN d_setting d ON b.d_setting_id = d.d_id
                                     WHERE bi.bom_ing_fid = ? LIMIT 1");
            $biStmt->execute([$bomIngFid]);
            $bi = $biStmt->fetch(PDO::FETCH_ASSOC);
            if (!$bi) throw new Exception('查無此製程資料');
            $bomTotalQty = $bi['bom_total_qty'] !== null ? (int)$bi['bom_total_qty'] : null;

            // 判定結果（合格/不合格/待判定，擇一）：未手動勾選時，僅在「良品數＝BOM總數」（全數完成且零NG）
            // 才自動認定合格，其餘情況（有NG、尚未收齊整張BOM數量、查無BOM總數可比對）一律留待判定，
            // 不再像舊版那樣只要有NG就自動判不合格——不合格是需要人明確確認的判斷，不由系統代勞。
            if ($judgementIn !== '') {
                $judgement = $judgementIn;
            } else {
                $judgement = ($bomTotalQty !== null && $okQty === $bomTotalQty) ? 'PASS' : 'PENDING';
            }

            // 找出這個 bom_ing_fid 目前是否已有紀錄可以續編（explicit id 優先，否則找暫存中的那一列）
            $editId = intval($_POST['packing_inspection_id'] ?? 0);
            $cur = null;
            if ($editId) {
                $cs = $pdo->prepare("SELECT * FROM qc_packing_inspection WHERE packing_inspection_id = ?");
                $cs->execute([$editId]);
                $cur = $cs->fetch(PDO::FETCH_ASSOC);
                if (!$cur) throw new Exception('查無此包裝紀錄，可能已被刪除，請重新整理');
                if ((int)$cur['bom_ing_fid'] !== $bomIngFid) throw new Exception('資料不一致，請重新整理後再試');
            } else {
                $os = $pdo->prepare("SELECT * FROM qc_packing_inspection WHERE bom_ing_fid = ? AND status = 'open' ORDER BY packing_inspection_id DESC LIMIT 1");
                $os->execute([$bomIngFid]);
                $cur = $os->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            // 管理員補登：需要補登權限；只用來「新建缺漏的舊紀錄」，已存在任何紀錄(暫存或結案)一律
            // 改請到已結案清單解鎖修改，不重複補一筆——避免同一個製程冒出兩筆包裝紀錄講不同的事實
            $recordDate = null; // null=沿用既有值或今天
            $packerName = $user_cname;
            $packerId = null;
            if ($isBackfill) {
                if (!$PK_CAN_BACKFILL) throw new Exception('無補登權限，請洽管理員於「角色與功能設定」授權');
                if (!$cur) {
                    $chkAny = $pdo->prepare("SELECT COUNT(*) FROM qc_packing_inspection WHERE bom_ing_fid = ?");
                    $chkAny->execute([$bomIngFid]);
                    if ((int)$chkAny->fetchColumn() > 0) throw new Exception('此製程已有包裝紀錄，請至已結案清單解鎖後修改，不要重複補登');
                }
                $procNos = get_packing_process_nos($pdo);
                if (!in_array((int)$bi['process_no'], $procNos, true)) throw new Exception('此製程不是目前設定的包裝製程，無法補登');
                $rd = trim($_POST['record_date'] ?? '');
                if ($rd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd)) throw new Exception('請選擇補登日期');
                if (strtotime($rd) > strtotime(date('Y-m-d'))) throw new Exception('補登日期不可為未來');
                $recordDate = $rd;
                if ($PK_CAN_BACKFILL_PACKER) {
                    $pid = intval($_POST['packer_id'] ?? 0);
                    if ($pid) {
                        $pu = $pdo->prepare("SELECT id, user_cname FROM `user` WHERE id = ? LIMIT 1");
                        $pu->execute([$pid]);
                        $pr = $pu->fetch(PDO::FETCH_ASSOC);
                        if (!$pr) throw new Exception('指定的包裝人員不存在');
                        $packerId = (int)$pr['id'];
                        $packerName = trim((string)$pr['user_cname']) !== '' ? $pr['user_cname'] : $packerName;
                    }
                }
            }

            // 已結案紀錄要改：僅管理員可操作，且要輸入操作確認密碼才算解鎖（鐵律8：不可只靠前端擋）
            $needPasswordNote = false;
            if ($cur && $cur['status'] === 'closed') {
                if (!$PK_CAN_ADMIN) throw new Exception('此紀錄已結案鎖定，需管理員權限才能修改');
                $pw = (string)($_POST['confirm_password'] ?? '');
                if ($pw === '') throw new Exception('此紀錄已結案鎖定，請輸入操作確認密碼才能修改');
                $vr = eg_confirm_password_verify_scoped($pdo, $pk_uid, $pw, 'pk_edit_closed');
                if (empty($vr['ok'])) throw new Exception($vr['msg']);
                $needPasswordNote = true;
            }

            $insDate = $recordDate !== null ? $recordDate : ($cur ? $cur['inspection_date'] : date('Y-m-d'));
            $newStatus = $complete ? 'closed' : 'open';
            $now = date('Y-m-d H:i:s');

            $pdo->beginTransaction();
            if ($cur) {
                $pkgId = (int)$cur['packing_inspection_id'];
                $sql = "UPDATE qc_packing_inspection SET
                            inspection_date = ?, bom = ?, part_no = ?, customer_name = ?,
                            order_qty = ?, bom_total_qty = ?, inspected_qty = ?, ok_qty = ?, ng_qty = ?,
                            ship_now_qty = ?, warehouse_qty = ?, is_full_shipment = ?, storage_method = ?, pallet_qty = ?,
                            judgement = ?, inspector = ?, packer = ?, packer_id = ?, remark = ?, status = ?,
                            is_backfill = GREATEST(is_backfill, ?), backfill_by = COALESCE(backfill_by, ?), backfill_at = COALESCE(backfill_at, ?),
                            closed_by = " . ($complete ? "COALESCE(closed_by, ?)" : "closed_by") . ",
                            closed_at = " . ($complete ? "COALESCE(closed_at, ?)" : "closed_at") . ",
                            updated_by = ?" . ($needPasswordNote ? ", edit_note = ?" : "") . "
                        WHERE packing_inspection_id = ?";
                $params = [
                    $insDate, $bi['bom'], $bi['part_no'], $bi['Client_Name'],
                    $orderQty, $bomTotalQty, $orderQty, $okQty, $ngQty,
                    $shipNowQty, $warehouseQty, $isFullShip, ($storageMethod ?: null), $palletQty,
                    $judgement, $user_cname, $packerName, $packerId, $remark, $newStatus,
                    $isBackfill, ($isBackfill ? $pk_uid : null), ($isBackfill ? $now : null),
                ];
                if ($complete) $params[] = $pk_uid;
                if ($complete) $params[] = $now;
                $params[] = $pk_uid;
                if ($needPasswordNote) $params[] = ('管理員解鎖修改：' . $user_cname . ' ' . date('Y-m-d H:i'));
                $params[] = $pkgId;
                $pdo->prepare($sql)->execute($params);
            } else {
                $sql = "INSERT INTO qc_packing_inspection
                        (bom_ing_fid, bom, part_no, inspection_date, customer_name, order_qty, bom_total_qty,
                         inspected_qty, ok_qty, ng_qty, ship_now_qty, warehouse_qty, is_full_shipment, storage_method, pallet_qty,
                         judgement, inspector, packer, packer_id, remark, status,
                         is_backfill, backfill_by, backfill_at, closed_by, closed_at, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([
                    $bomIngFid, $bi['bom'], $bi['part_no'], $insDate, $bi['Client_Name'], $orderQty, $bomTotalQty,
                    $orderQty, $okQty, $ngQty, $shipNowQty, $warehouseQty, $isFullShip, ($storageMethod ?: null), $palletQty,
                    $judgement, $user_cname, $packerName, $packerId, $remark, $newStatus,
                    $isBackfill, ($isBackfill ? $pk_uid : null), ($isBackfill ? $now : null),
                    ($complete ? $pk_uid : null), ($complete ? $now : null), $pk_uid,
                ]);
                $pkgId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM qc_packing_inspection_data WHERE packing_inspection_id = ?")->execute([$pkgId]);
            $pdo->prepare("INSERT INTO qc_packing_inspection_data (packing_inspection_id, data_json) VALUES (?, ?)")
                ->execute([$pkgId, json_encode($packagingData)]);
            $pdo->commit();

            if ($complete && !$isBackfill) {
                // 通知生管可安排出貨（補登舊資料是補歷史紀錄，不重新觸發即時通知）
                try { pk_packing_notify_closed($pdo, (string)$bi['bom'], (string)$bi['part_no'], $orderQty, $pk_uid); } catch (Throwable $e) {}
            }

            echo json_encode(['success' => true, 'message' => $complete ? '包裝已完成並結案' : '已暫存，可稍後繼續填寫',
                'pkg_id' => $pkgId, 'status' => $newStatus]);
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

        // 8a. 補登可指定包裝人員時，供挑選的在職人員清單（人員列表鐵則：走共用 eg_people_list，不自寫SQL）
        if ($action === 'people_list') {
            if (!$PK_CAN_BACKFILL) { echo json_encode(['success' => false, 'message' => '無補登權限']); exit; }
            $allowDeptIds = get_packing_packer_allowed_dept_ids($pdo);
            $rows = eg_people_list($pdo, $allowDeptIds ? ['dept_ids' => $allowDeptIds] : []);
            $out = array_map(function ($r) {
                return ['id' => $r['id'], 'name' => $r['user_cname'], 'dept_name' => $r['dept_name'], 'position_name' => $r['position_name']];
            }, $rows);
            echo json_encode(['success' => true, 'data' => $out]);
            exit;
        }

        // 9. 管理員補登：搜尋「尚未有任何包裝紀錄」的 BOM 製程（不限 processing_state，含已完工/已結案的舊資料）
        if ($action === 'backfill_search') {
            if (!$PK_CAN_BACKFILL) { echo json_encode(['success' => false, 'message' => '無補登權限']); exit; }
            $procNos = get_packing_process_nos($pdo);
            if (empty($procNos)) { echo json_encode(['success' => true, 'data' => [], 'need_setting' => true]); exit; }
            $kw = trim($_POST['kw'] ?? '');
            $inQuery = implode(',', array_fill(0, count($procNos), '?'));
            $sql = "SELECT
                        bi.bom_ing_fid, bi.bom, bi.process_no, bi.sqty,
                        pn.ProcessName, b.d_id, b.sqty AS bom_total_qty, b.Client_Name,
                        COALESCE(d.D_Setting_Id, b.d_id) AS part_no, d.Revision,
                        COALESCE(b.Delivery_date, ol.Delivery_date) AS delivery_date
                    FROM bom_ing bi
                    JOIN bom b               ON bi.bom = b.bom
                    LEFT JOIN order_list ol  ON b.o_order_id = ol.Order_id
                    LEFT JOIN process_no pn  ON bi.process_no = pn.ProcessNo
                    LEFT JOIN d_setting d    ON b.d_setting_id = d.d_id
                    WHERE bi.process_no IN ($inQuery)
                      AND NOT EXISTS (SELECT 1 FROM qc_packing_inspection qpi WHERE qpi.bom_ing_fid = bi.bom_ing_fid)";
            $params = $procNos;
            if ($kw !== '') {
                $sql .= " AND (bi.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ? OR d.D_Setting_Id LIKE ?)";
                $like = '%' . $kw . '%';
                array_push($params, $like, $like, $like, $like);
            }
            $sql .= " ORDER BY bi.bom DESC LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // 10. 已結案清單（分頁＋篩選：BOM／料號關鍵字，日期區間預設本月，不限定年份）
        if ($action === 'list_closed') {
            $bomKw = trim($_POST['bom'] ?? '');
            $partKw = trim($_POST['part_no'] ?? '');
            $dateFrom = trim($_POST['date_from'] ?? '');
            $dateTo = trim($_POST['date_to'] ?? '');
            $judgeFilter = trim($_POST['judgement'] ?? '');
            $page = max(1, intval($_POST['page'] ?? 1));
            $per = intval($_POST['per'] ?? 20);
            if (!in_array($per, [10, 20, 50, 100], true)) $per = 20;

            $where = ["qpi.status = 'closed'"];
            $params = [];
            if ($bomKw !== '') { $where[] = 'qpi.bom LIKE ?'; $params[] = '%' . $bomKw . '%'; }
            if ($partKw !== '') { $where[] = 'qpi.part_no LIKE ?'; $params[] = '%' . $partKw . '%'; }
            if ($dateFrom !== '') { $where[] = 'qpi.inspection_date >= ?'; $params[] = $dateFrom; }
            if ($dateTo !== '') { $where[] = 'qpi.inspection_date <= ?'; $params[] = $dateTo; }
            $whereSqlNoJudge = implode(' AND ', $where);   // 給判定結果卡片計數用：不含判定篩選本身，才能同時看到各判定的筆數

            // 判定結果卡片計數（依目前 BOM／料號／日期篩選，逐判定各算一次，供快速篩選卡片顯示；
            // 鐵律「總計要看過全部符合條件的資料才能算」，故用 SQL 對全部命中列彙總，不是只算這一頁）
            $cntSql = "SELECT qpi.judgement, COUNT(*) AS n FROM qc_packing_inspection qpi WHERE $whereSqlNoJudge GROUP BY qpi.judgement";
            $cntStmt = $pdo->prepare($cntSql);
            $cntStmt->execute($params);
            $counts = ['ALL' => 0, 'PASS' => 0, 'FAIL' => 0, 'PENDING' => 0];
            foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                $counts['ALL'] += (int)$cr['n'];
                if (isset($counts[$cr['judgement']])) $counts[$cr['judgement']] = (int)$cr['n'];
            }

            if ($judgeFilter !== '' && in_array($judgeFilter, ['PASS', 'FAIL', 'PENDING'], true)) {
                $where[] = 'qpi.judgement = ?';
                $params[] = $judgeFilter;
            }
            $whereSql = implode(' AND ', $where);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM qc_packing_inspection qpi WHERE $whereSql");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();

            $sql = "SELECT qpi.packing_inspection_id, qpi.bom_ing_fid, qpi.bom, qpi.part_no, qpi.customer_name,
                           qpi.inspection_date, qpi.order_qty, qpi.bom_total_qty, qpi.ok_qty, qpi.ng_qty, qpi.judgement,
                           qpi.ship_now_qty, qpi.warehouse_qty, qpi.is_full_shipment, qpi.storage_method,
                           qpi.packer, qpi.inspector, qpi.is_backfill, qpi.closed_by, qpi.closed_at, qpi.remark,
                           uc.user_cname AS closed_by_name
                    FROM qc_packing_inspection qpi
                    LEFT JOIN `user` uc ON uc.id = qpi.closed_by
                    WHERE $whereSql
                    ORDER BY qpi.inspection_date DESC, qpi.packing_inspection_id DESC
                    LIMIT $per OFFSET " . (($page - 1) * $per);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $counts]);
            exit;
        }

        // 10a. 已結案清單：不分頁，全部符合條件的資料（列印用，鐵律「要看過全部資料才能算出結果」）
        if ($action === 'list_closed_all') {
            $bomKw = trim($_POST['bom'] ?? '');
            $partKw = trim($_POST['part_no'] ?? '');
            $dateFrom = trim($_POST['date_from'] ?? '');
            $dateTo = trim($_POST['date_to'] ?? '');
            $judgeFilter = trim($_POST['judgement'] ?? '');
            $where = ["qpi.status = 'closed'"];
            $params = [];
            if ($bomKw !== '') { $where[] = 'qpi.bom LIKE ?'; $params[] = '%' . $bomKw . '%'; }
            if ($partKw !== '') { $where[] = 'qpi.part_no LIKE ?'; $params[] = '%' . $partKw . '%'; }
            if ($dateFrom !== '') { $where[] = 'qpi.inspection_date >= ?'; $params[] = $dateFrom; }
            if ($dateTo !== '') { $where[] = 'qpi.inspection_date <= ?'; $params[] = $dateTo; }
            if ($judgeFilter !== '' && in_array($judgeFilter, ['PASS', 'FAIL', 'PENDING'], true)) {
                $where[] = 'qpi.judgement = ?';
                $params[] = $judgeFilter;
            }
            $whereSql = implode(' AND ', $where);
            $sql = "SELECT qpi.bom, qpi.part_no, qpi.customer_name, qpi.inspection_date, qpi.order_qty, qpi.bom_total_qty,
                           qpi.ok_qty, qpi.ng_qty, qpi.judgement, qpi.ship_now_qty, qpi.warehouse_qty, qpi.packer, qpi.remark
                    FROM qc_packing_inspection qpi
                    WHERE $whereSql
                    ORDER BY qpi.inspection_date ASC, qpi.packing_inspection_id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // 11. 已結案紀錄明細（開啟編輯／檢視用；含外觀檢驗項目、訂單綁定交期）
        if ($action === 'get_closed_detail') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM qc_packing_inspection WHERE packing_inspection_id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false, 'message' => '查無此紀錄']); exit; }
            $dj = $pdo->prepare("SELECT data_json FROM qc_packing_inspection_data WHERE packing_inspection_id = ? ORDER BY data_id DESC LIMIT 1");
            $dj->execute([$id]);
            $row['packaging_data'] = $dj->fetchColumn() ?: null;

            // 補上目前的製程/客戶/料號基本資料（給編輯視窗表頭顯示用，bom_ing 可能已不在待包裝清單裡）
            $hdr = $pdo->prepare("SELECT bi.process_no, pn.ProcessName, b.d_id, COALESCE(d.D_Setting_Id, b.d_id) AS part_no, d.Revision
                                  FROM bom_ing bi
                                  LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                                  LEFT JOIN bom b ON bi.bom = b.bom
                                  LEFT JOIN d_setting d ON b.d_setting_id = d.d_id
                                  WHERE bi.bom_ing_fid = ? LIMIT 1");
            $hdr->execute([(int)$row['bom_ing_fid']]);
            $row['header'] = $hdr->fetch(PDO::FETCH_ASSOC) ?: null;

            echo json_encode(['success' => true, 'row' => $row, 'can_edit' => (bool)$PK_CAN_ADMIN]);
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

        /* 使用說明按鈕（ai-rules/08 鐵律7 全站統一樣式） */
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F7E0BD; color:#8A5A2B; }
        .page-help-btn:hover { background:#d98a33; color:#fff; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#3D4B5C; line-height:1.75; }
        .help-doc p { margin:6px 0; }

        /* 分頁（待包裝／已結案） */
        #pk-main-tabs li { cursor:pointer; }
        #pk-main-tabs .badge { background:#F0A24B; }

        /* 已結案清單狀態小籤 */
        .pk-status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; margin-right:6px; }
        .pk-badge-open   { background:#eaf4fd; color:#2980b9; }
        .pk-badge-closed { background:#eafaf1; color:#27ae60; }
        .pk-badge-backfill { background:#fff4e5; color:#e8920c; }

        /* 判定結果（合格/不合格/待判定）：全站暖色系固定三色，見 ai-rules/10 */
        .pk-judge-pass    { color:#8a5a0a; font-weight:600; }
        .pk-judge-fail    { color:#DD5138; font-weight:600; }
        .pk-judge-pending { color:#8a6a45; font-weight:600; }
        .pk-judge-tag { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
        .pk-judge-tag-pass    { background:#F7E0BD; color:#4A3524; }
        .pk-judge-tag-fail    { background:#DD5138; color:#fff; }
        .pk-judge-tag-pending { background:#EDE3D3; color:#8a6a45; }

        /* 判定結果快速篩選卡片 */
        .pk-judge-cards { display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap; }
        .pk-judge-card {
            flex:1 1 120px; min-width:110px; cursor:pointer; border:2px solid #E4D3BC; border-radius:6px;
            padding:8px 10px; background:#fff; text-align:center; user-select:none;
        }
        .pk-judge-card .n { display:block; font-size:1.4em; font-weight:bold; }
        .pk-judge-card .t { font-size:12px; color:#8a6a45; }
        .pk-judge-card.active { border-color:#C77C1A; background:#FCF7F0; }
        .pk-judge-card.jc-all.active    { border-color:#C77C1A; }
        .pk-judge-card.jc-pass.active   { border-color:#C77C1A; background:#F7E0BD; }
        .pk-judge-card.jc-fail.active   { border-color:#DD5138; background:#fbe3df; }
        .pk-judge-card.jc-pending.active{ border-color:#8a6a45; background:#EDE3D3; }
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
                            <button class="page-help-btn pull-right" id="btn-page-help" title="使用說明" style="margin-right:8px;"><i class="fa fa-question-circle"></i> 使用說明</button>
                            <?php if ($PK_CAN_ADMIN): ?>
                            <button class="btn btn-default pull-right" id="btn-role-setting" style="margin-right:8px;"><i class="fa fa-key"></i> 角色與功能設定</button>
                            <?php endif; ?>
                            <?php if ($PK_CAN_BACKFILL): ?>
                            <button class="btn btn-warning pull-right" id="btn-backfill" style="margin-right:8px;"><i class="fa fa-history"></i> 補登包裝紀錄</button>
                            <?php endif; ?>
                            <button class="btn btn-default pull-right" id="btn-setting" style="margin-right:8px;"><i class="fa fa-cog"></i> 包裝製程設定</button>
                            <button class="btn btn-default pull-right" id="btn-template" style="margin-right:8px;"><i class="fa fa-list-alt"></i> 外觀檢驗模板</button>
                            <button class="btn btn-default pull-right" id="btn-refresh" style="margin-right:8px;"><i class="fa fa-refresh"></i> 重新整理</button>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <ul class="nav nav-tabs" id="pk-main-tabs" style="margin-bottom:0;">
                        <li class="active" data-tab="pending"><a href="#">待包裝 <span class="badge" id="tab-pending-count"></span></a></li>
                        <li data-tab="closed"><a href="#">已結案清單</a></li>
                    </ul>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="x_panel" id="pk-tab-pending" style="border-top:0;">
                                <div class="x_content">
                                    <p class="text-muted" style="margin-bottom:14px;">
                                        <i class="fa fa-info-circle"></i>
                                        依訂單交期由近到遠排序（<span class="text-danger">逾期排最上面</span>）；可調整急件等級或拖曳
                                        <i class="fa fa-bars"></i> 手把調整順序。點擊任一列開啟包裝檢驗填寫視窗；
                                        <span class="label label-info">暫存中</span> 表示先前已暫存過、尚未完成包裝。
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
                                                <th width="90" class="text-right">BOM總數</th>
                                            </tr>
                                        </thead>
                                        <tbody id="bom-list">
                                            <tr><td colspan="8" class="text-center text-muted">載入中...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="x_panel" id="pk-tab-closed" style="border-top:0;display:none;">
                                <div class="x_content">
                                    <div class="pk-judge-cards" id="cl-judge-cards">
                                        <div class="pk-judge-card jc-all active" data-judge=""><span class="n" id="jc-n-all">0</span><span class="t">全部</span></div>
                                        <div class="pk-judge-card jc-pass" data-judge="PASS"><span class="n" id="jc-n-pass">0</span><span class="t">合格</span></div>
                                        <div class="pk-judge-card jc-fail" data-judge="FAIL"><span class="n" id="jc-n-fail">0</span><span class="t">不合格</span></div>
                                        <div class="pk-judge-card jc-pending" data-judge="PENDING"><span class="n" id="jc-n-pending">0</span><span class="t">待判定</span></div>
                                    </div>
                                    <div class="row" style="margin-bottom:10px;">
                                        <div class="col-md-2">
                                            <input type="text" id="cl-f-bom" class="form-control input-sm" placeholder="BOM 關鍵字">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" id="cl-f-part" class="form-control input-sm" placeholder="料號關鍵字">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="date" id="cl-f-from" class="form-control input-sm">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="date" id="cl-f-to" class="form-control input-sm">
                                        </div>
                                        <div class="col-md-4 text-right">
                                            <button class="btn btn-default btn-sm" id="btn-cl-search"><i class="fa fa-search"></i> 查詢</button>
                                            <button class="btn btn-default btn-sm" id="btn-cl-reset">清除篩選(本月)</button>
                                            <button class="btn btn-default btn-sm" id="btn-cl-print"><i class="fa fa-print"></i> 列印已包裝明細</button>
                                        </div>
                                    </div>
                                    <p class="text-muted" style="margin-bottom:10px;">
                                        <i class="fa fa-info-circle"></i> 預設顯示本月資料；篩選 BOM／料號不限定年月份。
                                        已結案紀錄鎖定不可修改，<?= $PK_CAN_ADMIN ? '管理員可點列表右側「解鎖修改」以操作確認密碼開鎖。' : '如需修改請洽管理員以操作確認密碼開鎖。' ?>
                                        <span class="pk-count-badge pull-right" id="cl-count"></span>
                                    </p>
                                    <table class="table pk-table">
                                        <thead>
                                            <tr>
                                                <th width="110">結案日期</th>
                                                <th>BOM</th>
                                                <th>料號</th>
                                                <th>客戶</th>
                                                <th width="90" class="text-right">數量</th>
                                                <th width="70" class="text-right">NG</th>
                                                <th width="90">判定</th>
                                                <th width="90">出貨/入庫</th>
                                                <th>包裝人員</th>
                                                <th width="70">補登</th>
                                                <th width="90"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="cl-list">
                                            <tr><td colspan="11" class="text-center text-muted">請點「已結案清單」分頁載入</td></tr>
                                        </tbody>
                                    </table>
                                    <div class="text-right" id="cl-pager" style="margin-top:8px;"></div>
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
                    <?php if ($PK_CAN_ADMIN): ?>
                    <hr>
                    <p class="text-muted">補登舊資料時可指定的「包裝人員」範圍（僅管理員可設定）：選擇部門，可多選，<strong>各部門一律含底下所有子部門</strong>；不選任何部門＝不限制，全公司在職人員皆可挑選。</p>
                    <select id="setting-packer-dept" class="form-control" multiple style="width:100%;"></select>
                    <?php endif; ?>
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
                <div id="f-status-badges" style="margin-bottom:6px;"></div>
                <div class="row">
                    <div class="col-md-3"><strong>BOM：</strong><span id="f-bom"></span></div>
                    <div class="col-md-3"><strong>料號：</strong><span id="f-part"></span></div>
                    <div class="col-md-3"><strong>版次：</strong><span id="f-rev"></span></div>
                    <div class="col-md-3"><strong>客戶：</strong><span id="f-client"></span></div>
                </div>
                <div class="row" style="margin-top:6px;">
                    <div class="col-md-3"><strong>製程：</strong><span id="f-proc"></span></div>
                    <div class="col-md-3">
                        <strong>良品數：</strong>
                        <input type="number" id="f-order-qty" class="form-control input-sm" readonly
                            style="display:inline-block;width:100px;background:#F3ECDF;color:#8a6d45;cursor:default;"
                            title="自動帶入＝BOM總數扣掉已結案配發報廐單號的確認報廐量，反灰不可手改（避免不小心蓋掉報廐扣減）">
                        <span class="text-muted small">原總數 <span id="f-order-qty-total">-</span>（因BOM可能分批送包裝，此為整張BOM的總數，非本次數量）</span>
                    </div>
                    <div class="col-md-3"><strong>系統交期：</strong><span id="f-delivery"></span></div>
                </div>
                <div class="row" id="f-order-bind-wrap" style="margin-top:8px;">
                    <div class="col-md-12">
                        <strong>訂單綁定交期與數量：</strong>
                        <table class="table table-condensed table-bordered" id="f-order-bind-table" style="background:#fff;margin:6px 0 0;font-size:12px;">
                            <thead><tr><th>訂單編號</th><th>客戶單號</th><th>客戶</th><th>交期</th><th class="text-right">訂單數量</th><th class="text-right">分配數量</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="row" id="f-backfill-fields" style="margin-top:8px;display:none;">
                    <div class="col-md-4">
                        <label>補登日期：<span class="text-danger">*</span></label>
                        <input type="date" id="f-record-date" class="form-control input-sm">
                    </div>
                    <div class="col-md-4" id="f-packer-wrap">
                        <label>包裝人員：</label>
                        <select id="f-packer-select" class="form-control input-sm" data-eg-filter="輸入姓名篩選..."></select>
                    </div>
                </div>
                <div class="row" id="f-unlock-fields" style="margin-top:8px;display:none;">
                    <div class="col-md-6">
                        <label class="text-danger">此紀錄已結案鎖定，輸入操作確認密碼才能存檔修改：</label>
                        <input type="password" id="f-confirm-password" class="form-control input-sm" placeholder="操作確認密碼" autocomplete="new-password">
                    </div>
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

            <!-- 判定結果（合格/不合格/待判定，擇一） -->
            <div class="pk-section-title">判定結果</div>
            <div class="well well-sm" style="background:#f9f9f9;margin-bottom:15px;">
                <label class="radio-inline"><input type="radio" name="pkg-judgement" value="" checked> 依系統自動判定</label>
                <label class="radio-inline"><input type="radio" name="pkg-judgement" value="PASS"> <span class="pk-judge-pass">合格</span></label>
                <label class="radio-inline"><input type="radio" name="pkg-judgement" value="FAIL"> <span class="pk-judge-fail">不合格</span></label>
                <label class="radio-inline"><input type="radio" name="pkg-judgement" value="PENDING"> <span class="pk-judge-pending">待判定</span></label>
                <div class="text-muted small" id="pkg-judge-hint" style="margin-top:6px;"></div>
            </div>

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
                    <label>包裝說明：</label>
                    <input type="text" id="pkg-shipment-desc" class="form-control input-sm" placeholder="例如: 100 x 5 桶 + 20 = 520">
                    <div style="margin-top:8px;">
                        <label><input type="checkbox" id="pkg-direct-ship"> <strong>直接出貨</strong>（本批有數量不入庫、直接出給客戶）</label>
                        <div class="form-inline" id="pkg-ship-now-wrap" style="display:none;margin-top:4px;">
                            <label>本次出貨數量：</label>
                            <input type="number" id="pkg-ship-now-qty" class="form-control input-sm" style="width:100px;" min="1">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div id="f-storage-wrap">
                        <label>成品入庫方式：<span id="f-storage-required" class="text-danger" style="display:none;">*</span></label>
                        <div class="form-inline">
                            <label class="radio-inline"><input type="radio" name="pkg-storage-method" value="direct" checked> 直接入庫</label>
                            <label class="radio-inline"><input type="radio" name="pkg-storage-method" value="pallet"> 棧板+膠膜</label>
                            <input type="number" id="pkg-pallet-qty" class="form-control input-sm" style="width:80px;display:inline-block;" placeholder="棧板數">
                        </div>
                        <div class="form-inline" style="margin-top:5px;">
                            <label>實際入庫數：</label>
                            <input type="number" id="pkg-actual-qty" class="form-control input-sm" style="width:100px;" placeholder="實際數量">
                            <span class="text-muted small">(預設: BOM總數 - NG數)</span>
                        </div>
                    </div>
                    <div class="well well-sm" style="margin-top:8px;margin-bottom:0;padding:8px;">
                        本次數量（出貨＋入庫）：<strong id="pkg-total-now" style="font-size:1.1em;">0</strong>
                        <span class="text-muted small" id="pkg-total-now-hint"></span>
                    </div>
                </div>
            </div>

            <!-- 備註 -->
            <div class="pk-section-title">4. 備註</div>
            <textarea id="pkg-remark" class="form-control" rows="2" placeholder="輸入此包裝檢驗的相關備註..."></textarea>
        </div>
        <div class="pk-float-footer">
            <button type="button" class="btn btn-default" id="pkWindowCancel">取消</button>
            <button type="button" class="btn btn-default" id="btn-save-draft"><i class="fa fa-clock-o"></i> 暫存</button>
            <button type="button" class="btn btn-success" id="btn-save-pkg"><i class="fa fa-check"></i> 完成包裝</button>
        </div>
    </div>

    <?php if ($PK_CAN_BACKFILL): ?>
    <!-- 補登包裝紀錄：搜尋尚未有任何包裝紀錄的 BOM 製程 -->
    <div class="modal fade" id="backfillModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title"><i class="fa fa-history"></i> 補登包裝紀錄（僅限補舊資料用）</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted">搜尋<strong>尚未有任何包裝紀錄</strong>的 BOM 製程（不限狀態，含已完工／已結案的舊資料）。若該製程已有紀錄，請至「已結案清單」解鎖修改，不要重複補登。</p>
                    <div class="input-group input-group-sm" style="margin-bottom:10px;">
                        <span class="input-group-addon"><i class="fa fa-search"></i></span>
                        <input type="text" id="bf-kw" class="form-control" placeholder="輸入 BOM / 料號 / 客戶關鍵字...">
                        <span class="input-group-btn"><button class="btn btn-primary" id="bf-search-btn">搜尋</button></span>
                    </div>
                    <table class="table table-hover pk-table" style="font-size:13px;">
                        <thead><tr><th>BOM</th><th>製程</th><th>料號/版次</th><th>客戶</th><th class="text-right">BOM總數</th><th width="70"></th></tr></thead>
                        <tbody id="bf-result"><tr><td colspan="6" class="text-center text-muted">請輸入關鍵字搜尋</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($PK_CAN_ADMIN): ?>
    <!-- 角色與功能設定（module=packing_schedule）；使用者與角色的對應請至 user_permissions.php 指派 -->
    <div class="modal fade" id="roleModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-key"></i> 包裝製程排程 — 角色與功能設定</h4></div>
        <div class="modal-body">
            <p class="text-muted" style="font-size:12px;">在此建立/命名角色並勾選其功能；使用者與角色的對應請至 <b>人員權限設定（user_permissions）</b>。系統管理員角色固定擁有全部權限，不可修改。一般包裝填寫（暫存/完成包裝）本頁維持全體登入者皆可使用，此處僅管理「補登舊資料」相關的進階權限。</p>
            <div class="row">
                <div class="col-md-5">
                    <div class="input-group input-group-sm" style="margin-bottom:6px;">
                        <input type="text" id="new-role-name" class="form-control" placeholder="新角色名稱…">
                        <span class="input-group-btn"><button class="btn btn-success" id="btn-add-role">新增</button></span>
                    </div>
                    <div class="list-group" id="role-list" style="max-height:320px;overflow:auto;"></div>
                </div>
                <div class="col-md-7">
                    <div id="role-feat-area" style="display:none;">
                        <h5>角色「<span id="rf-role-name"></span>」的功能</h5>
                        <div id="rf-checks"></div>
                        <div style="margin-top:10px;">
                            <button class="btn btn-primary btn-sm" id="btn-save-feats"><i class="fa fa-check"></i> 儲存功能</button>
                            <button class="btn btn-default btn-sm" id="btn-rename-role">改名</button>
                            <button class="btn btn-danger btn-sm pull-right" id="btn-del-role"><i class="fa fa-trash"></i> 刪除角色</button>
                            <span class="text-muted" id="rf-msg" style="margin-left:8px;"></span>
                        </div>
                    </div>
                    <div id="role-feat-empty" class="text-muted">← 請於左側選擇一個角色</div>
                </div>
            </div>
        </div>
    </div></div></div>
    <?php endif; ?>

    <!-- 使用說明 -->
    <div class="modal fade" id="helpUseMask" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document"><div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">包裝製程排程 — 使用說明</h4></div>
            <div class="modal-body help-doc">
                <p><strong>功能說明：</strong>依訂單交期排序待包裝的製程，可填寫外觀檢驗、防護、容器與出貨資訊，完成後自動通知生管安排出貨。</p>
                <p><strong>操作步驟：</strong></p>
                <ol>
                    <li>點擊清單中任一列開啟填寫視窗。</li>
                    <li>填寫外觀檢驗項目、防護與容器資訊。</li>
                    <li>若本批數量有一部分要<strong>直接出貨</strong>，勾選「直接出貨」並填入本次出貨數量；若還有剩餘數量，需再選擇成品入庫方式。</li>
                    <li>若「判定結果」不手動勾選，存檔時系統會自動判定：良品數＝BOM總數（全數完成且零NG）才自動判為<strong>合格</strong>，其餘一律列為<strong>待判定</strong>，需人工確認後手動改成合格或不合格。</li>
                    <li>尚未填完可按「<strong>暫存</strong>」，資料會保留、BOM 仍留在待包裝清單（標示「暫存中」），可稍後回來繼續填寫。</li>
                    <li>填完按「<strong>完成包裝</strong>」即結案：紀錄鎖定不可再修改、BOM 從待包裝清單移除並列入「已結案清單」、同時通知生管可安排出貨。</li>
                </ol>
                <p><strong>重要行為 / 常見疑問：</strong></p>
                <ul>
                    <li>「BOM總數」是整張 BOM 的總數量，因為同一張 BOM 可能分批送到包裝，這裡顯示的不是本次的數量。</li>
                    <li>「訂單綁定交期與數量」列出這張 BOM 目前綁定的訂單資料；若查無綁定，會退回顯示系統交期。</li>
                    <li>「判定結果」分合格／不合格／待判定三種，擇一：不合格一律要人工手動勾選，系統不會自動判不合格；「已結案清單」上方的卡片可依判定結果快速篩選（全部／合格／不合格／待判定），數字是該篩選條件下符合的筆數。</li>
                    <li>已結案的紀錄無法直接修改，需由管理員在「已結案清單」點「解鎖修改」並輸入操作確認密碼。</li>
                    <?php if ($PK_CAN_BACKFILL): ?><li>「補登包裝紀錄」僅能用於<strong>完全沒有包裝紀錄</strong>的舊 BOM，已有紀錄的請改用「已結案清單」解鎖修改。<?= $PK_CAN_BACKFILL_PACKER ? '你目前有權限可指定其他人為包裝人員；管理員可在「包裝製程設定」限制可挑選的部門範圍（含子部門），未設定則全公司在職人員皆可選。' : '你目前只能以自己的身分補登，如需指定他人請洽管理員授權。' ?></li><?php endif; ?>
                </ul>
                <p><strong>設定入口：</strong>包裝製程設定／外觀檢驗模板（頁首按鈕）；補登可指定包裝人員的部門範圍在「包裝製程設定」跳窗內（僅管理員看得到）。</p>
                <p><strong>權限角色：</strong>一般包裝填寫（暫存/完成包裝、勾選判定結果）全體登入者皆可使用；「補登舊資料」與「指定補登包裝人員」「解鎖修改已結案紀錄／角色與功能設定」「設定包裝人員部門範圍」由管理員於本頁「角色與功能設定」指派角色，再到人員權限設定指派給人員。</p>
            </div>
        </div></div>
    </div>

    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
    <script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
    <script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
    <script>
    $(function () {
        var API = 'packing_schedule.php';
        var currentRow = null;       // 目前開啟視窗的列資料
        var currentItems = [];       // 目前檢驗項目
        var currentDId = null;       // 目前料號 d_id
        var currentSource = 'none';  // custom / template / none
        var sortableInstance = null;
        var itemEditMode = 'template'; // template / custom
        var currentMode = 'normal';  // normal / backfill / editClosed / view
        var currentPkgId = null;     // 續編/補登/解鎖修改中的 qc_packing_inspection.packing_inspection_id
        var currentBomTotalQty = null; // 這張 BOM 真正的總數量（來自 bom.sqty，非可手改的 f-order-qty），判定結果自動認定合格要比對這個
        var currentIsClosed = false; // 目前這筆是否為已結案（解鎖修改中）

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
                $('#tab-pending-count').text(res.data.length);
                var html = '';
                res.data.forEach(function (r) {
                    var rev = r.Revision ? ('<span class="rev-badge">版 ' + r.Revision + '</span>') : '';
                    var draftTag = r.has_draft ? ' <span class="label label-info">暫存中</span>' : '';
                    html += '<tr class="pk-row ' + rowPriClass(r.eff_priority) + '" data-fid="' + r.bom_ing_fid + '">' +
                        '<td class="text-center"><i class="fa fa-bars pk-drag-handle" title="拖曳調整順序"></i></td>' +
                        '<td>' + dueCell(r.delivery_date) + (r.order_cnt ? ' <span class="text-muted small">(綁定' + r.order_cnt + '張訂單)</span>' : '') + '</td>' +
                        '<td>' +
                          '<select class="form-control input-sm pk-pri-select" onclick="event.stopPropagation();">' +
                            '<option value="" ' + (r.eff_priority !== 'E' && r.eff_priority !== 'U' ? 'selected' : '') + '>一般</option>' +
                            '<option value="U" ' + (r.eff_priority === 'U' ? 'selected' : '') + '>急件</option>' +
                            '<option value="E" ' + (r.eff_priority === 'E' ? 'selected' : '') + '>特急</option>' +
                          '</select>' +
                        '</td>' +
                        '<td><span class="label label-info" style="font-size:12px;">' + (r.ProcessName || ('製程' + r.process_no)) + '</span>' + draftTag + '</td>' +
                        '<td>' + (r.Client_Name || '') + '</td>' +
                        '<td><span class="bom-code">' + r.bom + '</span></td>' +
                        '<td>' + (r.part_no || '') + ' ' + rev + '</td>' +
                        '<td class="text-right"><strong>' + (function(){
                            var total = r.bom_total_qty != null ? r.bom_total_qty : r.sqty;
                            var good = r.good_qty != null ? r.good_qty : total;
                            // 沒有報廐（良品數＝總數）時只印一個數字，免得每一列都多印一次一樣的東西；
                            // 有報廐才印「良品 / 原總數」讓現場一眼看出這批已經少了幾件
                            return good == total ? fmtNum(total) : (fmtNum(good) + ' <span class="text-muted small">/ ' + fmtNum(total) + '</span>');
                        })() + '</strong></td>' +
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
            openWindow(r, 'normal');
        });

        var PK_CAN_BACKFILL = <?= $PK_CAN_BACKFILL ? 'true' : 'false' ?>;
        var PK_CAN_BACKFILL_PACKER = <?= $PK_CAN_BACKFILL_PACKER ? 'true' : 'false' ?>;
        var PK_CAN_ADMIN = <?= $PK_CAN_ADMIN ? 'true' : 'false' ?>;

        function todayStr() {
            var d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        // mode: 'normal'=一般填寫／續編暫存 'backfill'=管理員補登 'editClosed'=已結案解鎖修改 'view'=唯讀檢視
        function openWindow(r, mode) {
            mode = mode || 'normal';
            currentMode = mode;
            currentRow = r;
            currentPkgId = null;
            currentIsClosed = false;
            $('#f-bom').text(r.bom);
            $('#f-part').text(r.part_no || '');
            $('#f-rev').text(r.Revision || '');
            $('#f-client').text(r.Client_Name || '');
            $('#f-proc').text(r.ProcessName || ('製程' + r.process_no));
            $('#f-delivery').text(r.delivery_date || '無');
            var rTotalQty0 = (r.bom_total_qty != null ? r.bom_total_qty : r.sqty) || 0;
            $('#f-order-qty').val(r.good_qty != null ? r.good_qty : rTotalQty0);
            $('#f-order-qty-total').text(fmtNum(rTotalQty0));
            $('#pk-win-sub').text('- ' + r.bom);
            currentBomTotalQty = (r.bom_total_qty != null) ? parseFloat(r.bom_total_qty) : (r.sqty != null ? parseFloat(r.sqty) : null);

            // 重置表單
            $('#pkg-appearance-tbody').empty();
            $('#pkg-appearance-tfoot').empty();
            $('input[name="pkg-judgement"][value=""]').prop('checked', true);
            $('#pkg-judge-hint').text('');
            $('.pkg-rust, .pkg-collision').prop('checked', false).closest('label').removeClass('active');
            $('.pkg-rust-other, .pkg-collision-other, .pkg-collision-detail, .pkg-collision-detail-2').val('').hide();
            $('#pkg-return-jig, #pkg-return-sample, #pkg-shipment-desc, #pkg-pallet-qty, #pkg-actual-qty, #pkg-remark').val('');
            $('input[name="pkg-storage-method"][value="direct"]').prop('checked', true);
            $('#pkg-direct-ship').prop('checked', false);
            $('#pkg-ship-now-wrap').hide();
            $('#pkg-ship-now-qty').val('');
            $('#f-storage-wrap').show();
            $('#pkg-rows-container').empty();
            $('#f-confirm-password').val('');
            $('#f-unlock-fields').hide();
            $('#f-backfill-fields').toggle(mode === 'backfill');
            setFormReadOnly(mode === 'view');

            if (mode === 'backfill') {
                $('#f-record-date').val(todayStr()).attr('max', todayStr());
                loadPackerOptions();
            }

            // 載入外觀檢驗項目（先料號專用、無則預設模板）＋訂單綁定交期＋既有暫存紀錄
            $.post(API, { action: 'get_form', bom: r.bom, bom_ing_fid: r.bom_ing_fid }, function (res) {
                if (!res.success) { alert('載入失敗: ' + res.message); return; }
                currentItems = res.items || [];
                currentDId = res.d_id || null;
                currentSource = res.source || 'none';
                renderSourceBadge();
                renderAppearance();
                renderOrderBind(res.order_bind || []);
                if (res.bom_total_qty != null && res.bom_total_qty !== '') {
                    $('#f-order-qty').val(res.good_qty != null ? res.good_qty : res.bom_total_qty);
                    $('#f-order-qty-total').text(fmtNum(res.bom_total_qty));
                    currentBomTotalQty = parseFloat(res.bom_total_qty);
                }
                addPkgRow();
                if (mode === 'normal' && res.draft) {
                    fillFromRecord(res.draft, false);
                } else {
                    calcActualQty();
                }
                renderStatusBadges();
                showWindow();
            }, 'json');
        }

        // 開啟已結案紀錄（檢視／解鎖修改）
        function openClosedRecord(id, unlock) {
            $.post(API, { action: 'get_closed_detail', id: id }, function (res) {
                if (!res.success) { alert('載入失敗: ' + (res.message || '')); return; }
                var row = res.row, hdr = row.header || {};
                var r = {
                    bom_ing_fid: row.bom_ing_fid, bom: row.bom, part_no: row.part_no || (hdr.part_no || ''),
                    Revision: hdr.Revision || '', Client_Name: row.customer_name,
                    process_no: hdr.process_no, ProcessName: hdr.ProcessName,
                    delivery_date: null, bom_total_qty: row.bom_total_qty, sqty: row.order_qty
                };
                openWindow(r, unlock ? 'editClosed' : 'view');
                // openWindow 是非同步載入題庫，等它做完再覆蓋成這筆紀錄的內容
                var waitFill = setInterval(function () {
                    if (!$('#pkWindow').is(':visible')) return;
                    clearInterval(waitFill);
                    fillFromRecord(row, unlock);
                    if (!unlock) setFormReadOnly(true);
                }, 120);
            }, 'json');
        }

        function setFormReadOnly(ro) {
            var $scope = $('#pkWindow');
            $scope.find('input, select, textarea, button.pkg-remove, .pk-drag-handle').not('#f-confirm-password, #pkWindowClose, #pkWindowCancel').prop('disabled', ro);
            $('#btn-save-draft, #btn-save-pkg, #btn-add-pkg-row').toggle(!ro);
        }

        function renderOrderBind(rows) {
            var $tb = $('#f-order-bind-table tbody').empty();
            if (!rows.length) {
                $('#f-order-bind-wrap').hide();
                return;
            }
            $('#f-order-bind-wrap').show();
            rows.forEach(function (o) {
                $tb.append('<tr><td>' + (o.Order_oo || o.Order_id) + '</td><td>' + (o.C_order || '') + '</td><td>' +
                    (o.Client_name || '') + '</td><td>' + (o.Delivery_date || '') + '</td><td class="text-right">' +
                    fmtNum(o.order_qty) + '</td><td class="text-right">' + fmtNum(o.allocated_qty) + '</td></tr>');
            });
        }

        function renderStatusBadges() {
            var html = '';
            if (currentIsClosed) html += '<span class="pk-status-badge pk-badge-closed"><i class="fa fa-lock"></i> 已結案鎖定';
            else if (currentPkgId) html += '<span class="pk-status-badge pk-badge-open"><i class="fa fa-clock-o"></i> 暫存中，尚未完成';
            if (html) html += '</span>';
            if (currentMode === 'backfill') html += '<span class="pk-status-badge pk-badge-backfill"><i class="fa fa-history"></i> 補登模式</span>';
            $('#f-status-badges').html(html);
        }

        function loadPackerOptions() {
            var $sel = $('#f-packer-select');
            if (!PK_CAN_BACKFILL_PACKER) {
                $('#f-packer-wrap').hide();
                return;
            }
            $('#f-packer-wrap').show();
            if ($sel.data('loaded')) return;
            $.post(API, { action: 'people_list' }, function (res) {
                var rows = (res && res.success) ? res.data : [];
                var h = '<option value="">（本人）</option>';
                rows.forEach(function (u) {
                    h += '<option value="' + u.id + '">' + (u.dept_name ? (u.dept_name + ' ') : '') +
                        (u.position_name ? (u.position_name + ' ') : '') + u.name + '</option>';
                });
                $sel.html(h).data('loaded', 1);
            }, 'json');
        }

        // 把既有紀錄（暫存續編／補登草稿續編／已結案解鎖修改／檢視）填回表單
        function fillFromRecord(rec, isClosedEdit) {
            currentPkgId = rec.packing_inspection_id;
            currentIsClosed = (rec.status === 'closed');
            if (rec.order_qty != null) $('#f-order-qty').val(rec.order_qty);
            if (rec.bom_total_qty != null) currentBomTotalQty = parseFloat(rec.bom_total_qty);
            // 既有紀錄的判定結果就照當時存的值勾選（不管當初是自動還是手動判定的），
            // 讓填表人看得到目前狀態、要改再改；不預先猜是自動還手動
            $('input[name="pkg-judgement"][value="' + (rec.judgement || '') + '"]').prop('checked', true);
            var pd = {};
            try { pd = rec.packaging_data ? JSON.parse(rec.packaging_data) : {}; } catch (e) { pd = {}; }

            $('#pkg-appearance-tbody tr[data-pkg-id]').each(function () {
                var $tr = $(this);
                var id = $tr.data('pkg-id');
                var a = pd.appearance ? pd.appearance[id] : null;
                if (!a) return;
                $tr.find('.pkg-ng-qty').val(a.ng_qty || '');
                (a.disposition || []).forEach(function (v) {
                    $tr.find('input[type=checkbox][value="' + v + '"]').prop('checked', true).closest('label').addClass('active');
                });
                if (a.other_text) $tr.find('.pkg-other-input').val(a.other_text).show();
            });
            (pd.rust || []).forEach(function (v) { $('.pkg-rust[value="' + v + '"]').prop('checked', true); });
            if (pd.rust_other) $('.pkg-rust-other').val(pd.rust_other).show();
            (pd.collision || []).forEach(function (v) { $('.pkg-collision[value="' + v + '"]').prop('checked', true); });
            if (pd.collision_other) $('.pkg-collision-other').val(pd.collision_other).show();
            $('.pkg-collision-detail').val(pd.collision_detail_1 || '');
            $('.pkg-collision-detail-2').val(pd.collision_detail_2 || '');
            $('#pkg-return-jig').val(pd.return_jig || '');
            $('#pkg-return-sample').val(pd.return_sample || '');
            $('#pkg-shipment-desc').val(pd.shipment_desc || '');

            $('#pkg-rows-container').empty();
            if (pd.rows && pd.rows.length) pd.rows.forEach(function (row) { addPkgRow(row); });
            else addPkgRow();

            var shipNow = parseFloat(rec.ship_now_qty) || 0;
            $('#pkg-direct-ship').prop('checked', !!(rec.is_full_shipment * 1)).trigger('change');
            $('#pkg-ship-now-qty').val(shipNow || '');
            if (rec.storage_method) $('input[name="pkg-storage-method"][value="' + rec.storage_method + '"]').prop('checked', true);
            $('#pkg-pallet-qty').val(rec.pallet_qty || '');
            $('#pkg-actual-qty').val(rec.warehouse_qty != null ? rec.warehouse_qty : '');
            $('#pkg-remark').val(rec.remark || '');

            if (isClosedEdit) {
                $('#f-unlock-fields').show();
            }
            calcActualQty();
            renderStatusBadges();
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
            var okQty = orderQty - totalNg;
            var isFull = $('#pkg-direct-ship').is(':checked');
            var shipNow = isFull ? (parseFloat($('#pkg-ship-now-qty').val()) || 0) : 0;
            var warehouseQty;
            if (isFull) {
                warehouseQty = Math.max(0, okQty - shipNow);
                $('#pkg-actual-qty').val(warehouseQty);
                var needStorage = warehouseQty > 0;
                $('#f-storage-wrap').toggle(needStorage);
                $('#f-storage-required').toggle(needStorage);
            } else {
                warehouseQty = parseFloat($('#pkg-actual-qty').val());
                if (isNaN(warehouseQty)) { warehouseQty = okQty; $('#pkg-actual-qty').val(warehouseQty); }
                $('#f-storage-wrap').show();
                $('#f-storage-required').hide();
            }
            var totalNow = shipNow + (parseFloat(warehouseQty) || 0);
            $('#pkg-total-now').text(totalNow);
            var hint = '';
            if (totalNow !== okQty) hint = '（與 BOM總數-NG=' + okQty + ' 不同，請確認是否為分批中的一部分）';
            $('#pkg-total-now-hint').text(hint);
            $('#pkg-appearance-tfoot').html(
                '<tr><td colspan="4" class="text-right" style="font-size:1.05em;">' +
                'BOM總數: <span class="text-primary">' + orderQty + '</span> - ' +
                'NG總數: <span class="text-danger">' + totalNg + '</span> = ' +
                '<span class="text-success">小計(OK): ' + okQty + '</span></td></tr>'
            );
            updateJudgeHint(okQty);
        }

        // 判定結果自動判定的即時提示：跟著「良品數」「BOM總數」變動更新，讓填表人知道沒手動勾選時會存成什麼
        function updateJudgeHint(okQty) {
            var manual = $('input[name="pkg-judgement"]:checked').val();
            if (manual) { $('#pkg-judge-hint').text(''); return; }
            var bomTotal = currentBomTotalQty;
            if (bomTotal !== null && okQty === bomTotal) {
                $('#pkg-judge-hint').html('目前未勾選，良品數 ' + okQty + ' ＝ BOM總數 ' + bomTotal + '，存檔時將自動判定為<span class="pk-judge-pass">合格</span>。');
            } else {
                $('#pkg-judge-hint').html('目前未勾選，良品數 ' + okQty + (bomTotal !== null ? '　BOM總數 ' + bomTotal : '') + '，存檔時將自動列為<span class="pk-judge-pending">待判定</span>，請確認後手動選擇判定結果。');
            }
        }
        $(document).on('change', 'input[name="pkg-judgement"]', function () {
            updateJudgeHint((parseFloat($('#f-order-qty').val()) || 0) - (function () {
                var n = 0; $('.pkg-ng-qty').each(function () { n += (parseFloat($(this).val()) || 0); }); return n;
            })());
        });

        $(document).on('input', '.pkg-ng-qty', function () {
            if ((parseFloat($(this).val()) || 0) > 0) $(this).addClass('ng-value'); else $(this).removeClass('ng-value');
            calcActualQty();
        });
        $('#f-order-qty').on('input', calcActualQty);
        $('#pkg-actual-qty').on('input', calcActualQty);
        $('#pkg-direct-ship').on('change', function () {
            $('#pkg-ship-now-wrap').toggle($(this).is(':checked'));
            if (!$(this).is(':checked')) $('#pkg-ship-now-qty').val('');
            calcActualQty();
        });
        $('#pkg-ship-now-qty').on('input', calcActualQty);

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
        function doSave(complete) {
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

            var isFullShip = $('#pkg-direct-ship').is(':checked');
            var shipNowQty = isFullShip ? (parseInt($('#pkg-ship-now-qty').val(), 10) || 0) : 0;
            var okQty = (parseFloat($('#f-order-qty').val()) || 0) - totalNg;
            var storageMethod = $('input[name="pkg-storage-method"]:checked').val();
            var warehouseQty = parseFloat($('#pkg-actual-qty').val());
            if (isNaN(warehouseQty)) warehouseQty = 0;

            // 前端先擋一次（後端 save_result 同規則再擋一次＝鐵律8）
            if (isFullShip) {
                if (!shipNowQty || shipNowQty <= 0) { alert('請輸入本次出貨數量'); $('#pkg-ship-now-qty').focus(); return; }
                if (shipNowQty > okQty) { alert('本次出貨數量不可大於可出/入庫數量(' + okQty + ')'); $('#pkg-ship-now-qty').focus(); return; }
                if (warehouseQty > 0 && !storageMethod) { alert('尚有 ' + warehouseQty + ' 個需要入庫，請選擇成品入庫方式'); return; }
            }
            if (currentMode === 'backfill') {
                if (!$('#f-record-date').val()) { alert('請選擇補登日期'); $('#f-record-date').focus(); return; }
            }
            if (currentIsClosed && !$('#f-confirm-password').val()) {
                alert('此紀錄已結案鎖定，請輸入操作確認密碼才能存檔');
                $('#f-confirm-password').focus();
                return;
            }

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
                storage_method: storageMethod,
                pallet_qty: $('#pkg-pallet-qty').val(),
                actual_qty: $('#pkg-actual-qty').val(),
                ship_now_qty: shipNowQty
            };

            var payload = {
                action: 'save_result',
                bom_ing_fid: currentRow.bom_ing_fid,
                packing_inspection_id: currentPkgId || '',
                order_qty: $('#f-order-qty').val(),
                ng_qty: totalNg,
                ship_now_qty: shipNowQty,
                warehouse_qty: warehouseQty,
                is_full_shipment: isFullShip ? 1 : 0,
                storage_method: storageMethod || '',
                pallet_qty: $('#pkg-pallet-qty').val(),
                packaging_data: packagingData,
                remark: $('#pkg-remark').val(),
                complete: complete ? 1 : 0,
                judgement: $('input[name="pkg-judgement"]:checked').val() || ''
            };
            if (currentMode === 'backfill') {
                payload.is_backfill = 1;
                payload.record_date = $('#f-record-date').val();
                if (PK_CAN_BACKFILL_PACKER) payload.packer_id = $('#f-packer-select').val() || '';
            }
            if (currentIsClosed) payload.confirm_password = $('#f-confirm-password').val();

            var $btns = $('#btn-save-draft, #btn-save-pkg').prop('disabled', true);
            $.post(API, payload, function (res) {
                $btns.prop('disabled', false);
                if (res.success) {
                    hideWindow();
                    if (currentMode === 'editClosed') { loadClosedList(); }
                    else { loadList(); }
                } else {
                    alert('儲存失敗: ' + res.message);
                }
            }, 'json').fail(function (xhr) {
                $btns.prop('disabled', false);
                alert('連線失敗');
                console.error(xhr.responseText);
            });
        }
        $('#btn-save-pkg').click(function () { doSave(true); });
        $('#btn-save-draft').click(function () { doSave(false); });

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
                    if (PK_CAN_ADMIN && $('#setting-packer-dept').length) {
                        $.post(API, { action: 'list_departments' }, function (dres) {
                            var $dsel = $('#setting-packer-dept').empty();
                            if ($dsel.hasClass('select2-hidden-accessible')) $dsel.select2('destroy');
                            (dres.data || []).forEach(function (d) {
                                $dsel.append('<option value="' + d.id + '">' + d.name + '</option>');
                            });
                            $.post(API, { action: 'get_packer_dept_setting' }, function (dr2) {
                                $dsel.val((dr2.dept_ids || []).map(String));
                                $dsel.select2({ dropdownParent: $('#settingModal'), placeholder: '不限制（全公司皆可選）', width: '100%' });
                                $('#settingModal').modal('show');
                            }, 'json');
                        }, 'json');
                    } else {
                        $('#settingModal').modal('show');
                    }
                }, 'json');
            }, 'json');
        });
        $('#btn-save-setting').click(function () {
            var vals = $('#setting-process').val() || [];
            $.post(API, { action: 'save_packing_setting', process_nos: vals }, function (res) {
                if (!res.success) { alert('儲存失敗: ' + res.message); return; }
                if (PK_CAN_ADMIN && $('#setting-packer-dept').length) {
                    var deptVals = $('#setting-packer-dept').val() || [];
                    $.post(API, { action: 'save_packer_dept_setting', dept_ids: deptVals }, function (dres) {
                        if (!dres.success) { alert('包裝人員部門設定儲存失敗: ' + dres.message); return; }
                        $('#settingModal').modal('hide'); loadList();
                    }, 'json');
                } else {
                    $('#settingModal').modal('hide'); loadList();
                }
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

        $('#btn-refresh').click(function () {
            if ($('#pk-main-tabs li[data-tab="closed"]').hasClass('active')) loadClosedList(); else loadList();
        });
        $('#btn-page-help').click(function () { $('#helpUseMask').modal('show'); });

        // ---------- 分頁（待包裝／已結案） ----------
        $('#pk-main-tabs').on('click', 'li', function () {
            var tab = $(this).data('tab');
            $('#pk-main-tabs li').removeClass('active');
            $(this).addClass('active');
            $('#pk-tab-pending').toggle(tab === 'pending');
            $('#pk-tab-closed').toggle(tab === 'closed');
            if (tab === 'closed') loadClosedList();
        });

        // ---------- 已結案清單 ----------
        var clPage = 1;
        var clJudgeFilter = '';   // ''=全部 PASS/FAIL/PENDING＝判定結果快速篩選卡片
        var JUDGE_TAG = {
            PASS: '<span class="pk-judge-tag pk-judge-tag-pass">合格</span>',
            FAIL: '<span class="pk-judge-tag pk-judge-tag-fail">不合格</span>',
            PENDING: '<span class="pk-judge-tag pk-judge-tag-pending">待判定</span>'
        };
        function clDefaultRange() {
            var now = new Date();
            var first = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-01';
            $('#cl-f-from').val(first);
            $('#cl-f-to').val(todayStr());
        }
        function loadClosedList(page) {
            clPage = page || 1;
            var params = {
                action: 'list_closed', page: clPage, per: 20,
                bom: $('#cl-f-bom').val(), part_no: $('#cl-f-part').val(),
                date_from: $('#cl-f-from').val(), date_to: $('#cl-f-to').val(),
                judgement: clJudgeFilter
            };
            $('#cl-list').html('<tr><td colspan="11" class="text-center text-muted">載入中...</td></tr>');
            $.post(API, params, function (res) {
                if (!res.success) { $('#cl-list').html('<tr><td colspan="11" class="text-danger">' + (res.message || '載入失敗') + '</td></tr>'); return; }
                $('#cl-count').html('共 <strong>' + res.total + '</strong> 筆已結案');
                var c = res.counts || {};
                $('#jc-n-all').text(c.ALL || 0);
                $('#jc-n-pass').text(c.PASS || 0);
                $('#jc-n-fail').text(c.FAIL || 0);
                $('#jc-n-pending').text(c.PENDING || 0);
                if (!res.data.length) {
                    $('#cl-list').html('<tr><td colspan="11" class="text-center text-muted" style="padding:20px;">查無資料</td></tr>');
                    $('#cl-pager').html('');
                    return;
                }
                var html = '';
                res.data.forEach(function (r) {
                    var shipTxt = '';
                    if (r.is_full_shipment * 1 === 1) shipTxt = '出' + fmtNum(r.ship_now_qty) + (r.warehouse_qty > 0 ? ' / 入' + fmtNum(r.warehouse_qty) : '（全出貨）');
                    else shipTxt = '入' + fmtNum(r.warehouse_qty);
                    html += '<tr>' +
                        '<td>' + egFmtDate(r.inspection_date) + '</td>' +
                        '<td><span class="bom-code">' + r.bom + '</span></td>' +
                        '<td>' + (r.part_no || '') + '</td>' +
                        '<td>' + (r.customer_name || '') + '</td>' +
                        '<td class="text-right">' + fmtNum(r.order_qty) + '</td>' +
                        '<td class="text-right">' + (r.ng_qty > 0 ? ('<span class="text-danger">' + fmtNum(r.ng_qty) + '</span>') : '0') + '</td>' +
                        '<td>' + (JUDGE_TAG[r.judgement] || '') + '</td>' +
                        '<td>' + shipTxt + '</td>' +
                        '<td>' + (r.packer || '') + '</td>' +
                        '<td>' + (r.is_backfill * 1 === 1 ? '<span class="label label-warning">補登</span>' : '') + '</td>' +
                        '<td class="text-right">' +
                            '<button class="btn btn-xs btn-default cl-view" data-id="' + r.packing_inspection_id + '" title="檢視"><i class="fa fa-eye"></i></button> ' +
                            (PK_CAN_ADMIN ? '<button class="btn btn-xs btn-warning cl-unlock" data-id="' + r.packing_inspection_id + '" title="解鎖修改"><i class="fa fa-unlock"></i></button>' : '') +
                        '</td>' +
                        '</tr>';
                });
                $('#cl-list').html(html);
                var totalPages = Math.ceil(res.total / res.per) || 1;
                var pg = '';
                if (totalPages > 1) {
                    for (var p = 1; p <= totalPages; p++) {
                        pg += '<button class="btn btn-xs ' + (p === clPage ? 'btn-primary' : 'btn-default') + ' cl-page" data-p="' + p + '" style="margin-left:2px;">' + p + '</button>';
                    }
                }
                $('#cl-pager').html(pg);
            }, 'json');
        }
        $(document).on('click', '.cl-page', function () { loadClosedList($(this).data('p')); });
        $('#btn-cl-search').click(function () { loadClosedList(1); });
        $('#btn-cl-reset').click(function () { $('#cl-f-bom, #cl-f-part').val(''); clDefaultRange(); loadClosedList(1); });
        $(document).on('click', '.cl-view', function () { openClosedRecord($(this).data('id'), false); });
        $(document).on('click', '.cl-unlock', function () { openClosedRecord($(this).data('id'), true); });
        $(document).on('click', '.pk-judge-card', function () {
            $('.pk-judge-card').removeClass('active');
            $(this).addClass('active');
            clJudgeFilter = $(this).data('judge') || '';
            loadClosedList(1);
        });

        $('#btn-cl-print').click(function () {
            var params = { action: 'list_closed_all', bom: $('#cl-f-bom').val(), part_no: $('#cl-f-part').val(), date_from: $('#cl-f-from').val(), date_to: $('#cl-f-to').val(), judgement: clJudgeFilter };
            $.post(API, params, function (res) {
                if (!res.success) { alert('取得資料失敗'); return; }
                printClosedList(res.data, params);
            }, 'json');
        });
        function printClosedList(rows, params) {
            var win = window.open('', '_blank');
            var rangeTxt = (params.date_from || '（不限）') + ' ~ ' + (params.date_to || '（不限）');
            var filterTxt = [];
            if (params.bom) filterTxt.push('BOM: ' + params.bom);
            if (params.part_no) filterTxt.push('料號: ' + params.part_no);
            var judgeName = { PASS: '合格', FAIL: '不合格', PENDING: '待判定' };
            if (params.judgement) filterTxt.push('判定: ' + (judgeName[params.judgement] || params.judgement));
            var body = '<h3>已包裝明細</h3><div>期間：' + rangeTxt + (filterTxt.length ? '　篩選：' + filterTxt.join('、') : '') + '　共 ' + rows.length + ' 筆</div>' +
                '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;width:100%;font-size:12px;margin-top:8px;">' +
                '<thead><tr style="background:#eee;"><th>結案日期</th><th>BOM</th><th>料號</th><th>客戶</th><th>數量</th><th>NG</th><th>判定</th><th>出貨數</th><th>入庫數</th><th>包裝人員</th><th>備註</th></tr></thead><tbody>';
            rows.forEach(function (r) {
                body += '<tr><td>' + egFmtDate(r.inspection_date) + '</td><td>' + r.bom + '</td><td>' + (r.part_no || '') + '</td><td>' +
                    (r.customer_name || '') + '</td><td>' + fmtNum(r.order_qty) + '</td><td>' + fmtNum(r.ng_qty) + '</td><td>' +
                    (judgeName[r.judgement] || r.judgement || '') + '</td><td>' +
                    fmtNum(r.ship_now_qty) + '</td><td>' + fmtNum(r.warehouse_qty) + '</td><td>' + (r.packer || '') + '</td><td>' + (r.remark || '') + '</td></tr>';
            });
            body += '</tbody></table>';
            win.document.write('<html><head><meta charset="UTF-8"><title>已包裝明細</title></head><body>' + body +
                '<script>window.onload=function(){window.print();};<' + '/script></body></html>');
            win.document.close();
        }

        // ---------- 補登包裝紀錄 ----------
        <?php if ($PK_CAN_BACKFILL): ?>
        $('#btn-backfill').click(function () {
            $('#bf-kw').val('');
            $('#bf-result').html('<tr><td colspan="6" class="text-center text-muted">請輸入關鍵字搜尋</td></tr>');
            $('#backfillModal').modal('show');
        });
        function bfSearch() {
            $.post(API, { action: 'backfill_search', kw: $('#bf-kw').val() }, function (res) {
                if (!res.success) { $('#bf-result').html('<tr><td colspan="6" class="text-danger">' + (res.message || '搜尋失敗') + '</td></tr>'); return; }
                if (!res.data.length) { $('#bf-result').html('<tr><td colspan="6" class="text-center text-muted">查無符合的 BOM（或已有包裝紀錄）</td></tr>'); return; }
                var html = '';
                res.data.forEach(function (r, i) {
                    html += '<tr><td>' + r.bom + '</td><td>' + (r.ProcessName || ('製程' + r.process_no)) + '</td><td>' +
                        (r.part_no || '') + (r.Revision ? (' <span class="rev-badge">版' + r.Revision + '</span>') : '') + '</td><td>' +
                        (r.Client_Name || '') + '</td><td class="text-right">' + fmtNum(r.bom_total_qty != null ? r.bom_total_qty : r.sqty) + '</td>' +
                        '<td><button class="btn btn-xs btn-primary bf-pick" data-i="' + i + '">選擇</button></td></tr>';
                });
                $('#bf-result').html(html);
                $('#bf-result').data('rows', res.data);
            }, 'json');
        }
        $('#bf-search-btn').click(bfSearch);
        $('#bf-kw').on('keydown', function (e) { if (e.key === 'Enter' || e.keyCode === 13) { e.preventDefault(); bfSearch(); } });
        $(document).on('click', '.bf-pick', function () {
            var rows = $('#bf-result').data('rows') || [];
            var r = rows[$(this).data('i')];
            if (!r) return;
            $('#backfillModal').modal('hide');
            openWindow(r, 'backfill');
        });
        <?php endif; ?>

        // ---------- 角色與功能設定 ----------
        <?php if ($PK_CAN_ADMIN): ?>
        var ROLES_API = '../../src/store/Roles_API.php';
        var PK_FEATURES = [
            ['pk_backfill', '補登舊資料（開啟「補登包裝紀錄」搜尋與填寫）'],
            ['pk_backfill_change_packer', '補登時可指定其他人為包裝人員（否則只能填寫自己）'],
            ['pk_admin', '管理員（解鎖修改已結案紀錄、管理角色與功能設定）']
        ];
        var curRole = null;
        function pkEsc(s) { return $('<div>').text(s == null ? '' : s).html(); }
        function loadRoles() {
            $.get(ROLES_API, { action: 'get_roles', module: 'packing_schedule' }, function (r) {
                if (!r || !r.success) { $('#role-list').html('<div class="text-danger">' + pkEsc(r && r.message || '載入失敗') + '</div>'); return; }
                var h = '';
                r.data.forEach(function (ro) {
                    var sys = parseInt(ro.is_system, 10) === 1;
                    h += '<a href="#" class="list-group-item role-item" data-id="' + ro.role_id + '" data-name="' + pkEsc(ro.role_name) + '" data-sys="' + (sys ? 1 : 0) + '">'
                       + pkEsc(ro.role_name) + (sys ? ' <span class="label label-info pull-right">系統(全權)</span>' : '') + '</a>';
                });
                $('#role-list').html(h || '<div class="text-muted">尚無角色</div>');
                $('.role-item').on('click', function (e) {
                    e.preventDefault();
                    $('.role-item').removeClass('active'); $(this).addClass('active');
                    selectRole($(this).data('id'), $(this).data('name'), $(this).data('sys') == 1);
                });
            }, 'json');
        }
        function selectRole(rid, rname, isSys) {
            curRole = { id: rid, name: rname, sys: isSys };
            $('#role-feat-empty').hide(); $('#role-feat-area').show(); $('#rf-role-name').text(rname); $('#rf-msg').text('');
            $.get(ROLES_API, { action: 'get_role_features', role_id: rid }, function (r) {
                var have = (r && r.success) ? r.data : [];
                if (isSys) have = PK_FEATURES.map(function (f) { return f[0]; });
                var h = '';
                PK_FEATURES.forEach(function (f) {
                    h += '<div class="checkbox"><label><input type="checkbox" class="rf-chk" value="' + f[0] + '" '
                       + (have.indexOf(f[0]) >= 0 ? 'checked' : '') + (isSys ? ' disabled' : '') + '> ' + pkEsc(f[1]) + ' <code>' + f[0] + '</code></label></div>';
                });
                $('#rf-checks').html(h);
                $('#btn-save-feats,#btn-del-role,#btn-rename-role').prop('disabled', isSys);
            }, 'json');
        }
        $('#btn-role-setting').on('click', function (e) { e.preventDefault(); $('#role-feat-area').hide(); $('#role-feat-empty').show(); loadRoles(); $('#roleModal').modal('show'); });
        $('#btn-add-role').on('click', function () {
            var n = $('#new-role-name').val().trim();
            if (!n) { alert('請輸入角色名稱'); return; }
            $.post(ROLES_API, { action: 'save_role', role_name: n, module: 'packing_schedule' }, function (r) {
                if (r && r.success) { $('#new-role-name').val(''); loadRoles(); } else alert(r && r.message || '新增失敗');
            }, 'json');
        });
        $('#btn-rename-role').on('click', function () {
            if (!curRole || curRole.sys) return;
            var n = prompt('新角色名稱', curRole.name);
            if (!n) return;
            $.post(ROLES_API, { action: 'save_role', role_id: curRole.id, role_name: n.trim(), module: 'packing_schedule' }, function (r) {
                if (r && r.success) loadRoles(); else alert(r && r.message || '改名失敗');
            }, 'json');
        });
        $('#btn-del-role').on('click', function () {
            if (!curRole || curRole.sys) return;
            if (!confirm('確定刪除角色「' + curRole.name + '」？此角色的功能與使用者指派都會移除。')) return;
            $.post(ROLES_API, { action: 'delete_role', role_id: curRole.id }, function (r) {
                if (r && r.success) { $('#role-feat-area').hide(); $('#role-feat-empty').show(); loadRoles(); } else alert(r && r.message || '刪除失敗');
            }, 'json');
        });
        $('#btn-save-feats').on('click', function () {
            if (!curRole || curRole.sys) return;
            var feats = [];
            $('.rf-chk:checked').each(function () { feats.push($(this).val()); });
            $.post(ROLES_API, { action: 'save_role_features', role_id: curRole.id, features: JSON.stringify(feats) }, function (r) {
                $('#rf-msg').text(r && r.success ? '已儲存' : (r && r.message || '儲存失敗'));
            }, 'json');
        });
        <?php endif; ?>

        // 初始
        clDefaultRange();
        loadList();
    });
    </script>
</body>
</html>
