<?php
// Quotation_API.php
session_start();
header('Content-Type: application/json');

include '../common/DBConnection.php';
require_once '../common/quotation_approval.php';

$db  = new DBConnection();
$pdo = $db->getPDO();
$response = ['success' => false, 'message' => 'An unknown error occurred.'];

if (!isset($_SESSION['id'])) {
    $response['message'] = 'User not authenticated.';
    echo json_encode($response);
    exit;
}
$user_id = $_SESSION['id'];

// 相容舊表：quotation_item.sort_order（列印/顯示排序號碼）首次執行自動補欄，
// 並依「舊版列印時的自動排序規則」回填既有資料，讓舊報價單的列印順序維持不變
try { $pdo->query("SELECT sort_order FROM quotation_item LIMIT 1"); }
catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE quotation_item ADD COLUMN sort_order INT NOT NULL DEFAULT 0 COMMENT '列印/顯示排序號碼(依存檔順序;0=未排依item_id)' AFTER quote_id");
        $pdo->exec("UPDATE quotation_item qi JOIN (SELECT item_id, ROW_NUMBER() OVER (PARTITION BY quote_id ORDER BY product_id ASC, process_notes ASC, specification ASC, quantity ASC, item_id ASC) AS rn FROM quotation_item) t ON t.item_id = qi.item_id SET qi.sort_order = t.rn");
    } catch (PDOException $e2) {}
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ──────────────────────────────────────────────────────────────
// 共用：取得單一報價單的 items（含 tiers）
// ──────────────────────────────────────────────────────────────
function fetchItemsWithTiers(PDO $pdo, int $quote_id): array
{
    $stmt = $pdo->prepare("
        SELECT
            qi.*,
            GROUP_CONCAT(DISTINCT qipm.process_no ORDER BY qipm.process_no) AS processes
        FROM quotation_item qi
        LEFT JOIN quotation_item_process_map qipm ON qi.item_id = qipm.quotation_item_id
        WHERE qi.quote_id = ?
        GROUP BY qi.item_id
        ORDER BY qi.sort_order ASC, qi.item_id ASC
    ");
    $stmt->execute([$quote_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($items)) {
        $item_ids = array_column($items, 'item_id');
        $ph       = implode(',', array_fill(0, count($item_ids), '?'));
        $stmt_t   = $pdo->prepare("
            SELECT * FROM quotation_item_tier
            WHERE item_id IN ($ph)
            ORDER BY item_id ASC, sort_order ASC, qty_min ASC
        ");
        $stmt_t->execute($item_ids);
        $all_tiers = $stmt_t->fetchAll(PDO::FETCH_ASSOC);

        $tier_map = [];
        foreach ($all_tiers as $t) {
            $tier_map[$t['item_id']][] = $t;
        }
        foreach ($items as &$item) {
            $item['tiers'] = $tier_map[$item['item_id']] ?? [];
        }
        unset($item);
    }
    return $items;
}

// ──────────────────────────────────────────────────────────────
// 共用：為 get_list / get_list_all 批次組裝 item_summary（PHP 層，避免複雜 SQL）
// 格式：料號 [規格] 數量:500 單價:$300 ｜ 料號2 數量:100~500 單價:$10~$20 (階梯)
// ──────────────────────────────────────────────────────────────
function buildItemSummary(PDO $pdo, array &$rows): void
{
    if (empty($rows)) return;

    $quote_ids = array_column($rows, 'quote_id');
    $ph        = implode(',', array_fill(0, count($quote_ids), '?'));

    // 批次撈所有 items
    $stmt = $pdo->prepare("
        SELECT quote_id, item_id, product_id, specification,
               quantity, unit_price, is_tiered
        FROM quotation_item
        WHERE quote_id IN ($ph)
        ORDER BY quote_id ASC, item_id ASC
    ");
    $stmt->execute($quote_ids);
    $all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 批次撈有 tier 的 items 的階梯資料
    $tiered_item_ids = array_column(
        array_filter($all_items, fn($i) => $i['is_tiered']),
        'item_id'
    );
    $tier_map = [];
    if (!empty($tiered_item_ids)) {
        $ph2     = implode(',', array_fill(0, count($tiered_item_ids), '?'));
        $stmt_t  = $pdo->prepare("
            SELECT item_id, qty_min, qty_max, unit_price
            FROM quotation_item_tier
            WHERE item_id IN ($ph2)
            ORDER BY item_id ASC, sort_order ASC, qty_min ASC
        ");
        $stmt_t->execute($tiered_item_ids);
        foreach ($stmt_t->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tier_map[$t['item_id']][] = $t;
        }
    }

    // 按 quote_id 分組
    $items_by_quote = [];
    foreach ($all_items as $item) {
        $items_by_quote[$item['quote_id']][] = $item;
    }

    // 輔助：去尾隨零的數字格式
    $fmt = function($n) {
        if ($n === null || $n === '') return '';
        $f = (float)$n;
        // 去掉尾隨零（最多4位小數）
        return rtrim(rtrim(number_format($f, 4, '.', ','), '0'), '.');
    };

    // 組裝每張報價單的 item_summary
    // 格式：各料號以 " ｜ " 分隔；階梯料號內各區間以 "\n" 分隔
    foreach ($rows as &$row) {
        $qid   = $row['quote_id'];
        $items = $items_by_quote[$qid] ?? [];
        $parts = [];
        foreach ($items as $item) {
            $pid  = $item['product_id'];
            $spec = !empty($item['specification']) ? ' [' . $item['specification'] . ']' : '';

            if ($item['is_tiered'] && !empty($tier_map[$item['item_id']])) {
                // ★ 階梯：每個區間獨立一行，以 \n 分隔
                $tier_lines = [];
                foreach ($tier_map[$item['item_id']] as $t) {
                    $qmin = (int)$t['qty_min'];
                    $qmax = ($t['qty_max'] !== null && $t['qty_max'] !== '')
                              ? (int)$t['qty_max'] : null;
                    $qty_str   = $qmin . ($qmax !== null ? '~' . $qmax : '+');
                    $price_str = '$' . $fmt($t['unit_price']);
                    $tier_lines[] = $pid . $spec . ' 數量:' . $qty_str . ' 單價:' . $price_str . ' (階梯)';
                }
                $parts[] = implode('||', $tier_lines);
            } elseif ((float)$item['unit_price'] > 0) {
                $line = $pid . $spec;
                if ((int)$item['quantity'] > 0) {
                    $line .= ' 數量:' . (int)$item['quantity'];
                }
                $line .= ' 單價:$' . $fmt($item['unit_price']);
                $parts[] = $line;
            } else {
                $parts[] = $pid . $spec;
            }
        }
        $row['item_summary'] = implode(' ｜ ', $parts);
    }
    unset($row);
}

// 自動為 quotation_item 補欄位（首次執行時建立）
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM quotation_item")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('unit', $cols)) {
        $pdo->exec("ALTER TABLE quotation_item ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'PCS' AFTER quantity");
    }
    if (!in_array('d_setting_d_id', $cols)) {
        $pdo->exec("ALTER TABLE quotation_item ADD COLUMN d_setting_d_id INT NULL COMMENT '對應 d_setting.d_id' AFTER product_id");
    }
} catch (Exception $_e) {}

// 預先建立列印日誌表與 RBAC 資料表
try { $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_print_log (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, quote_id INT NOT NULL, quote_no VARCHAR(30) NOT NULL, printed_by INT NOT NULL, printed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_qid(quote_id), INDEX idx_printed_at(printed_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $_e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS roles (role_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, role_code VARCHAR(30) NOT NULL UNIQUE, role_name VARCHAR(50) NOT NULL, is_system TINYINT NOT NULL DEFAULT 0, note VARCHAR(200), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $_e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS role_features (role_id INT NOT NULL, feature_code VARCHAR(60) NOT NULL, PRIMARY KEY(role_id,feature_code), INDEX idx_rf_role(role_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $_e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id INT NOT NULL, role_id INT NOT NULL, PRIMARY KEY(user_id,role_id), INDEX idx_ur_user(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $_e){}
try {
    $pdo->exec("INSERT IGNORE INTO roles (role_code,role_name,is_system) VALUES ('admin','管理員',1)");
    $_adminRId = $pdo->query("SELECT role_id FROM roles WHERE role_code='admin' LIMIT 1")->fetchColumn();
    if ($_adminRId) $pdo->prepare("INSERT IGNORE INTO role_features (role_id,feature_code) VALUES (?,?)")->execute([$_adminRId,'all']);
} catch(Exception $_e){}

// 預先建立輔助資料表（DDL 需在 transaction 外執行）
try { $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_edit_lock (quote_id INT NOT NULL, locked_by INT NOT NULL, locked_name VARCHAR(60) NOT NULL DEFAULT '', locked_at DATETIME NOT NULL, session_id VARCHAR(128) NOT NULL DEFAULT '', PRIMARY KEY(quote_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $_e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_delete_log (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, quote_id INT NOT NULL, quote_no VARCHAR(30) NOT NULL, quote_date DATE, client_name VARCHAR(100), total_amount DECIMAL(15,4), deleted_by INT, deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP, delete_reason VARCHAR(500), snapshot LONGTEXT, INDEX idx_del_at(deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $_e){}
try { $pdo->exec("ALTER TABLE quotation_delete_log ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_at"); } catch(Exception $_e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_change_log (log_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, quote_id INT NOT NULL, changed_by INT NOT NULL, changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, summary VARCHAR(200) NOT NULL DEFAULT '', diff_json MEDIUMTEXT NOT NULL, INDEX idx_qcl(quote_id,log_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $_e){}

try {
    switch ($action) {

        case 'get_units':
            $stmtu = $pdo->query("SELECT unit_id, unit_name, unit_symbol FROM stock_units WHERE is_active=1 ORDER BY sort_order ASC, unit_id ASC");
            echo json_encode(['success'=>true, 'units'=>$stmtu->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        case 'acquire_lock': {
            $qid   = intval($_POST['quote_id'] ?? 0);
            $force = !empty($_POST['force']);
            if (!$qid) { $response = ['success'=>false,'message'=>'缺少 quote_id']; break; }
            $ttl   = 15 * 60; // 15 分鐘
            $uname = $_SESSION['userName'] ?? strval($user_id);
            $sid   = session_id();
            $pdo->beginTransaction();
            $row = $pdo->prepare("SELECT * FROM quotation_edit_lock WHERE quote_id=? FOR UPDATE");
            $row->execute([$qid]);
            $lock = $row->fetch(PDO::FETCH_ASSOC);
            if ($lock) {
                $elapsed = time() - strtotime($lock['locked_at']);
                $isOwner = ($lock['locked_by'] == $user_id || $lock['session_id'] === $sid);
                if ($isOwner || $elapsed > $ttl || $force) {
                    $pdo->prepare("UPDATE quotation_edit_lock SET locked_by=?,locked_name=?,locked_at=NOW(),session_id=? WHERE quote_id=?")->execute([$user_id,$uname,$sid,$qid]);
                    $pdo->commit();
                    $response = ['success'=>true,'acquired'=>true];
                } else {
                    $pdo->commit();
                    $response = ['success'=>false,'acquired'=>false,
                        'locked_name'=>$lock['locked_name'],
                        'elapsed_min'=>intval($elapsed/60),
                        'message'=>"{$lock['locked_name']} 正在編輯此報價單（".intval($elapsed/60)."分鐘前）"];
                }
            } else {
                $pdo->prepare("INSERT INTO quotation_edit_lock (quote_id,locked_by,locked_name,locked_at,session_id) VALUES (?,?,?,NOW(),?)")->execute([$qid,$user_id,$uname,$sid]);
                $pdo->commit();
                $response = ['success'=>true,'acquired'=>true];
            }
            break;
        }

        case 'release_lock': {
            $qid = intval($_POST['quote_id'] ?? 0);
            if ($qid) { try { $pdo->prepare("DELETE FROM quotation_edit_lock WHERE quote_id=? AND (locked_by=? OR session_id=?)")->execute([$qid,$user_id,session_id()]); } catch(Exception $_e){} }
            $response = ['success'=>true];
            break;
        }

        case 'heartbeat_lock': {
            $qid = intval($_POST['quote_id'] ?? 0);
            if ($qid) { try { $pdo->prepare("UPDATE quotation_edit_lock SET locked_at=NOW() WHERE quote_id=? AND (locked_by=? OR session_id=?)")->execute([$qid,$user_id,session_id()]); } catch(Exception $_e){} }
            $response = ['success'=>true];
            break;
        }

        case 'get_list':
            $year = $_GET['year'] ?? date('Y');
            $stmt = $pdo->prepare("
                SELECT
                    ql.*,
                    COALESCE(u.user_cname, ql.created_by) AS created_by_name,
                    src.quote_no AS source_quote_no,
                    (SELECT COUNT(*) FROM quotation_item WHERE quote_id = ql.quote_id) AS item_count,
                    (SELECT COUNT(*) FROM quotation_attachments WHERE quote_no = ql.quote_no AND status = 'active') AS attach_count,
                    (SELECT GROUP_CONCAT(CONCAT_WS(' ', product_id, IFNULL(specification,'')) SEPARATOR ' ')
                     FROM quotation_item WHERE quote_id = ql.quote_id) AS search_keywords
                FROM quotation_list ql
                LEFT JOIN user u ON ql.created_by = u.id
                LEFT JOIN quotation_list src ON ql.source_quote_id = src.quote_id
                WHERE YEAR(ql.quote_date) = ?
                ORDER BY ql.quote_date DESC, ql.quote_no DESC
            ");
            $stmt->execute([$year]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            buildItemSummary($pdo, $rows);
            $response = ['success' => true, 'data' => $rows];
            break;

        case 'get_list_all':
            $stmt = $pdo->prepare("
                SELECT
                    ql.*,
                    COALESCE(u.user_cname, ql.created_by) AS created_by_name,
                    src.quote_no AS source_quote_no,
                    (SELECT COUNT(*) FROM quotation_item WHERE quote_id = ql.quote_id) AS item_count,
                    (SELECT COUNT(*) FROM quotation_attachments WHERE quote_no = ql.quote_no AND status = 'active') AS attach_count,
                    (SELECT GROUP_CONCAT(CONCAT_WS(' ', product_id, IFNULL(specification,'')) SEPARATOR ' ')
                     FROM quotation_item WHERE quote_id = ql.quote_id) AS search_keywords
                FROM quotation_list ql
                LEFT JOIN user u ON ql.created_by = u.id
                LEFT JOIN quotation_list src ON ql.source_quote_id = src.quote_id
                ORDER BY ql.quote_date DESC, ql.quote_no DESC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            buildItemSummary($pdo, $rows);
            $response = ['success' => true, 'data' => $rows];
            break;

        case 'get_detail':
            $quote_id = intval($_GET['quote_id'] ?? 0);
            if (!$quote_id) throw new Exception('Invalid ID.');
            $stmt = $pdo->prepare("
                SELECT ql.*,
                    COALESCE(u1.user_cname, ql.created_by) AS created_by_name,
                    COALESCE(u2.user_cname, ql.updated_by) AS updated_by_name
                FROM quotation_list ql
                LEFT JOIN user u1 ON u1.id = ql.created_by
                LEFT JOIN user u2 ON u2.id = ql.updated_by
                WHERE ql.quote_id = ?
            ");
            $stmt->execute([$quote_id]);
            $main = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$main) throw new Exception('Quotation not found.');
            $main['items'] = fetchItemsWithTiers($pdo, $quote_id);
            // 最新一筆簽核紀錄（審核意見／駁回原因等只在檢視/編輯顯示，不放進 get_print_data）
            $main['latest_approval'] = eg_approval_latest($pdo, 'quotation', $quote_id);
            $response = ['success' => true, 'data' => $main];
            break;

        // ── 自動排序按鈕：依前端排好的順序直接改資料庫排序號碼（只動 quotation_item.sort_order，
        //    不碰 quotation_list.updated_at，避免觸發樂觀鎖誤判衝突）──
        case 'save_item_sort': {
            $quote_id = intval($_POST['quote_id'] ?? 0);
            if (!$quote_id) throw new Exception('缺少 quote_id');
            $item_ids = json_decode($_POST['item_ids'] ?? '[]', true);
            if (!is_array($item_ids) || empty($item_ids)) throw new Exception('缺少排序項目');

            // 只接受屬於該報價單的 item_id
            $chk = $pdo->prepare("SELECT item_id FROM quotation_item WHERE quote_id=?");
            $chk->execute([$quote_id]);
            $own_ids = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));

            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE quotation_item SET sort_order=? WHERE item_id=? AND quote_id=?");
            $pos = 0;
            foreach ($item_ids as $iid) {
                $iid = intval($iid);
                if (!in_array($iid, $own_ids)) continue;
                $pos++;
                $upd->execute([$pos, $iid, $quote_id]);
            }
            $pdo->commit();
            $response = ['success' => true, 'updated' => $pos];
            break;
        }

        case 'save':
            $data = json_decode($_POST['data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid data format.');

            // 相容舊表：補加欄位（失敗代表已存在，忽略）
            try { $pdo->exec("ALTER TABLE quotation_list ADD COLUMN inquiry_no VARCHAR(50) NULL COMMENT '客戶詢價編號' AFTER client_name"); } catch(PDOException $e){}
            try { $pdo->exec("ALTER TABLE quotation_list ADD COLUMN client_id CHAR(11) NULL COMMENT '客戶編號' AFTER client_name"); } catch(PDOException $e){}
            try { $pdo->exec("ALTER TABLE quotation_list ADD COLUMN contact_id INT NULL COMMENT '聯絡人主鍵' AFTER client_id"); } catch(PDOException $e){}
            try { $pdo->exec("ALTER TABLE quotation_list ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 COMMENT '草稿：必備附件缺漏仍執意儲存=1，補齊後儲存自動歸0'"); } catch(PDOException $e){}

            $quote_id = intval($data['quote_id'] ?? 0) ?: null;
            $items    = $data['items'] ?? [];

            // ── 報價項目上限（與前端 MAX_QUOTE_ITEMS 一致）──
            $validItemCnt = 0;
            foreach ($items as $it0) { if (!empty($it0['product_id'])) $validItemCnt++; }
            if ($validItemCnt > 30) throw new Exception('報價項目最多 30 筆料號，目前 ' . $validItemCnt . ' 筆。');

            // ── 必備附件完整性僅供資訊回報，不覆蓋使用者的存草稿/存正式選擇 ──
            // 「缺附件時選草稿或正式」本身就是使用者在前端對話框已做的明確選擇，伺服器不應該再自動打回草稿
            // （這點與草稿本身仍禁止列印、正式單缺附件一樣可送審的既有政策一致）。
            // 議價單豁免必備附件檢查（既有政策，見 quotation_binding 相關定案），一律視為完整
            $isNegotiation = !empty($data['is_negotiation']) ? 1 : 0;
            $submittedProductIds = array_values(array_filter(array_map(function($it) { return $it['product_id'] ?? ''; }, $items)));
            $missingAttach = $isNegotiation ? [] : eg_quotation_missing_required_attach($pdo, (string)($data['quote_no'] ?? ''), $submittedProductIds);
            $forcedDraft = false;
            $finalIsDraft = !empty($data['is_draft']) ? 1 : 0;

            // ── 主管簽核狀態轉場（比照本次定案規則）：草稿一律 none；有簽核權限者自己送等於自己核准免審；
            //    非草稿且原本是 approved → 內容變了自動打回 pending 重審；原本 rejected → 維持不自動重送；
            //    原本 none/pending → 送審或維持等待中，不重複通知 ──
            $oldApprovalStatus = 'none';
            if ($quote_id) {
                $oaSt = $pdo->prepare("SELECT approval_status FROM quotation_list WHERE quote_id=?");
                $oaSt->execute([$quote_id]);
                $oldApprovalStatus = $oaSt->fetchColumn() ?: 'none';
            }
            $signerName   = eg_quotation_current_user_name($pdo, $user_id);
            $userCanSign  = eg_quotation_user_can_sign($pdo, $user_id);
            $newApprovalStatus = 'none';
            $newApprovedBy = null; $newApprovedByName = null; $newApprovedAt = null;
            $needSubmitPending = false;
            if ($finalIsDraft) {
                $newApprovalStatus = 'none';
            } elseif ($userCanSign) {
                $newApprovalStatus = 'approved';
                $newApprovedBy = $user_id; $newApprovedByName = $signerName; $newApprovedAt = date('Y-m-d H:i:s');
            } elseif ($oldApprovalStatus === 'rejected') {
                $newApprovalStatus = 'rejected';
            } elseif ($oldApprovalStatus === 'pending') {
                $newApprovalStatus = 'pending';
            } else {
                $newApprovalStatus = 'pending';
                $needSubmitPending = true;
            }

            // ── 新增時：取得 MySQL 層級的 Advisory Lock 序列化流水號，防多人碰撞 ──
            $pfx9     = null;
            $lockName = null;
            if (!$quote_id) {
                $qno = $data['quote_no'] ?? '';
                if (strlen($qno) >= 9 && substr($qno, 0, 2) === 'OP') {
                    $pfx9     = substr($qno, 0, 9);
                    $lockName = 'qno_' . preg_replace('/[^A-Za-z0-9]/', '', $pfx9);
                    $pdo->query("SELECT GET_LOCK('{$lockName}', 30)");
                }
            }

            // 自動建立鎖定資料表（首次執行）
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_edit_lock (
                quote_id     INT NOT NULL,
                locked_by    INT NOT NULL,
                locked_name  VARCHAR(60) NOT NULL DEFAULT '',
                locked_at    DATETIME NOT NULL,
                session_id   VARCHAR(128) NOT NULL DEFAULT '',
                PRIMARY KEY (quote_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $_e){}

            $pdo->beginTransaction();

            // 1. 主單
            $mf = [
                'quote_no'       => $data['quote_no'],
                'quote_date'     => $data['quote_date'],
                'valid_until'    => empty($data['valid_until']) ? null : $data['valid_until'],
                'client_name'    => $data['client_name'],
                'client_id'      => trim($data['client_id'] ?? '') ?: null,
                'contact_id'     => intval($data['contact_id'] ?? 0) ?: null,
                'inquiry_no'     => trim($data['inquiry_no'] ?? '') ?: null,
                'currency'       => $data['currency'],
                'exchange_rate'  => $data['exchange_rate'],
                'total_amount'   => $data['total_amount'],
                'note'           => $data['note'],
                'is_negotiation' => $isNegotiation,
                'is_draft'       => $finalIsDraft,
                'approval_status'  => $newApprovalStatus,
                'approved_by'      => $newApprovedBy,
                'approved_by_name' => $newApprovedByName,
                'approved_at'      => $newApprovedAt,
            ];
            if ($quote_id) {
                // ── 樂觀鎖：如果資料在讀取後被其他人修改，提示衝突並回傳差異 ──
                $last_ua = trim($data['last_updated_at'] ?? '');
                if ($last_ua) {
                    $cur_ua = $pdo->prepare("SELECT ql.*, u.user_cname AS updated_by_name FROM quotation_list ql LEFT JOIN user u ON u.id=ql.updated_by WHERE ql.quote_id=? FOR UPDATE");
                    $cur_ua->execute([$quote_id]);
                    $db_row = $cur_ua->fetch(PDO::FETCH_ASSOC) ?: [];
                    $db_ua  = trim((string)($db_row['updated_at'] ?? ''));
                    if ($db_ua && $db_ua !== $last_ua) {
                        $pdo->rollBack();
                        $fieldMap = ['quote_date'=>'報價日期','client_name'=>'客戶','note'=>'備註','is_negotiation'=>'議價','currency'=>'幣別','exchange_rate'=>'匯率','valid_until'=>'有效日期','is_draft'=>'草稿'];
                        $diffs = [];
                        foreach ($fieldMap as $fk => $flabel) {
                            $ov = (string)($db_row[$fk] ?? '');
                            $nv = (string)($mf[$fk] ?? '');
                            if ($ov !== $nv) $diffs[] = "【{$flabel}】{$ov} → {$nv}";
                        }
                        if ($lockName) @$pdo->query("SELECT RELEASE_LOCK('{$lockName}')");
                        $response = ['success'=>false, 'code'=>'CONFLICT',
                            'message'=>'此報價單已被他人修改，是否仍要覆蓋儲存？',
                            'modifier'=>$db_row['updated_by_name'] ?? '',
                            'diffs'=>$diffs];
                        break;
                    }
                }
                // ── 記錄修改日誌（只記錄有變化的欄位，保留最近 100 筆）──
                try {
                    $oldQ = $pdo->prepare("SELECT * FROM quotation_list WHERE quote_id=?");
                    $oldQ->execute([$quote_id]);
                    $oldRow = $oldQ->fetch(PDO::FETCH_ASSOC) ?: [];
                    $trackFields = ['quote_date','valid_until','client_name','client_id','inquiry_no','currency','exchange_rate','total_amount','note','is_negotiation','is_draft'];
                    $diffArr = []; $summaryParts = [];
                    foreach ($trackFields as $fk) {
                        $ov = (string)($oldRow[$fk] ?? ''); $nv = (string)($mf[$fk] ?? '');
                        if ($ov !== $nv) { $diffArr[$fk] = ['from'=>$ov,'to'=>$nv]; $summaryParts[] = $fk; }
                    }
                    if ($diffArr) {
                        $pdo->prepare("INSERT INTO quotation_change_log (quote_id,changed_by,changed_at,summary,diff_json) VALUES (?,?,NOW(),?,?)")
                            ->execute([$quote_id, $user_id, implode('、',$summaryParts), json_encode($diffArr,JSON_UNESCAPED_UNICODE)]);
                        // 保留最近 100 筆，刪除超出的舊紀錄
                        $pdo->prepare("DELETE FROM quotation_change_log WHERE quote_id=? AND log_id NOT IN (SELECT lid FROM (SELECT log_id AS lid FROM quotation_change_log WHERE quote_id=? ORDER BY log_id DESC LIMIT 100) t)")
                            ->execute([$quote_id, $quote_id]);
                    }
                } catch(Exception $_cle){}
                $mf['updated_by'] = $user_id;
                $mf['quote_id']   = $quote_id;
                $pdo->prepare("UPDATE quotation_list SET quote_no=:quote_no,quote_date=:quote_date,valid_until=:valid_until,client_name=:client_name,client_id=:client_id,contact_id=:contact_id,inquiry_no=:inquiry_no,currency=:currency,exchange_rate=:exchange_rate,total_amount=:total_amount,note=:note,is_negotiation=:is_negotiation,is_draft=:is_draft,approval_status=:approval_status,approved_by=:approved_by,approved_by_name=:approved_by_name,approved_at=:approved_at,updated_by=:updated_by,updated_at=NOW() WHERE quote_id=:quote_id")->execute($mf);
            } else {
                // ── INSERT: Advisory Lock 已持有，直接取最新流水號（不需 FOR UPDATE）──
                if ($pfx9) {
                    $sl = $pdo->prepare("SELECT quote_no FROM quotation_list WHERE quote_no LIKE ? ORDER BY quote_no DESC LIMIT 1");
                    $sl->execute([$pfx9 . '%']);
                    $last_no = $sl->fetchColumn();
                    $serial  = $last_no ? ((int)substr($last_no, -3) + 1) : 1;
                    $mf['quote_no'] = $pfx9 . str_pad($serial, 3, '0', STR_PAD_LEFT);
                }
                $mf['created_by']      = $user_id;
                $mf['source_quote_id'] = intval($data['source_quote_id'] ?? 0) ?: null;
                $pdo->prepare("INSERT INTO quotation_list (quote_no,quote_date,valid_until,client_name,client_id,contact_id,inquiry_no,currency,exchange_rate,total_amount,note,is_negotiation,is_draft,approval_status,approved_by,approved_by_name,approved_at,created_by,source_quote_id,created_at) VALUES (:quote_no,:quote_date,:valid_until,:client_name,:client_id,:contact_id,:inquiry_no,:currency,:exchange_rate,:total_amount,:note,:is_negotiation,:is_draft,:approval_status,:approved_by,:approved_by_name,:approved_at,:created_by,:source_quote_id,NOW())")->execute($mf);
                $quote_id = (int)$pdo->lastInsertId();
            }

            // 2. 取現有 item_ids
            $stmt_eid = $pdo->prepare("SELECT item_id FROM quotation_item WHERE quote_id=?");
            $stmt_eid->execute([$quote_id]);
            $existing_ids  = $stmt_eid->fetchAll(PDO::FETCH_COLUMN);
            $submitted_ids = [];
            // 本次提交中明確帶 item_id 的集合（防止無 item_id 的新列搶走其他列的既有明細）
            $explicit_ids = [];
            foreach ($items as $it0) {
                $eid0 = intval($it0['item_id'] ?? 0);
                if ($eid0) $explicit_ids[] = $eid0;
            }

            $sort_pos = 0; // 依前端送出的列順序寫入排序號碼（存檔順序=列印順序）
            foreach ($items as $item) {
                if (empty($item['product_id'])) continue;
                $sort_pos++;

                $item_id   = intval($item['item_id'] ?? 0) ?: null;
                $is_tiered = !empty($item['is_tiered']) ? 1 : 0;

                if (!$item_id) {
                    // 同料號可有多列（不同製程）：只認「未被本次其他列占用」的既有明細；
                    // 舊寫法直接抓第一筆同料號明細來 UPDATE，會把新列蓋到既有列上，
                    // 造成儲存後筆數不增、舊列資料被覆蓋（2026-07-14 修正）
                    $sf = $pdo->prepare("SELECT item_id FROM quotation_item WHERE quote_id=? AND product_id=? ORDER BY item_id");
                    $sf->execute([$quote_id, $item['product_id']]);
                    foreach ($sf->fetchAll(PDO::FETCH_COLUMN) as $cand) {
                        $cand = intval($cand);
                        if (!in_array($cand, $explicit_ids) && !in_array($cand, $submitted_ids)) {
                            $item_id = $cand;
                            break;
                        }
                    }
                }

                $if = [
                    'quote_id'           => $quote_id,
                    'product_id'         => $item['product_id'],
                    'd_setting_d_id'     => !empty($item['d_setting_d_id']) ? intval($item['d_setting_d_id']) : null,
                    'specification'      => $item['specification']      ?? '',
                    'quantity'           => $item['quantity']           ?? 0,
                    'unit'               => $item['unit']               ?? 'PCS',
                    'unit_price'         => $item['unit_price']         ?? 0,
                    'amount'             => $item['amount']             ?? 0,
                    'process_group_type' => $item['process_group_type'] ?? 'single_process',
                    'process_notes'      => $item['process_notes']      ?? '',
                    'is_tiered'          => $is_tiered,
                    'show_bom'           => !empty($item['show_bom']) ? 1 : 0,
                    // 階層式規則：未勾「顯示」時「列印」一律歸零
                    'print_bom'          => (!empty($item['show_bom']) && !empty($item['print_bom'])) ? 1 : 0,
                    'sort_order'         => $sort_pos,
                ];

                if ($item_id && in_array($item_id, $existing_ids)) {
                    $submitted_ids[] = $item_id;
                    $if['item_id'] = $item_id;
                    $pdo->prepare("UPDATE quotation_item SET quote_id=:quote_id,product_id=:product_id,d_setting_d_id=:d_setting_d_id,specification=:specification,quantity=:quantity,unit=:unit,unit_price=:unit_price,amount=:amount,process_group_type=:process_group_type,process_notes=:process_notes,is_tiered=:is_tiered,show_bom=:show_bom,print_bom=:print_bom,sort_order=:sort_order,updated_at=NOW() WHERE item_id=:item_id")->execute($if);
                } else {
                    $pdo->prepare("INSERT INTO quotation_item (quote_id,product_id,d_setting_d_id,specification,quantity,unit,unit_price,amount,process_group_type,process_notes,is_tiered,show_bom,print_bom,sort_order) VALUES (:quote_id,:product_id,:d_setting_d_id,:specification,:quantity,:unit,:unit_price,:amount,:process_group_type,:process_notes,:is_tiered,:show_bom,:print_bom,:sort_order)")->execute($if);
                    $item_id = (int)$pdo->lastInsertId();
                }
                $submitted_ids[] = $item_id;

                // 3. 製程 map
                $pdo->prepare("DELETE FROM quotation_item_process_map WHERE quotation_item_id=?")->execute([$item_id]);
                if (!empty($item['processes'])) {
                    $ins_map = $pdo->prepare("INSERT INTO quotation_item_process_map (quotation_item_id,process_no) VALUES (?,?)");
                    foreach (explode(',', $item['processes']) as $pno) {
                        if (is_numeric(trim($pno))) $ins_map->execute([$item_id, trim($pno)]);
                    }
                }

                // 4. Tiers：先全刪再整批 insert
                $pdo->prepare("DELETE FROM quotation_item_tier WHERE item_id=?")->execute([$item_id]);
                if ($is_tiered && !empty($item['tiers'])) {
                    $ins_t = $pdo->prepare("INSERT INTO quotation_item_tier (item_id,qty_min,qty_max,unit_price,amount,tolerance_value,tolerance_unit,tolerance_note,sort_order) VALUES (?,?,?,?,?,?,?,?,?)");
                    foreach ($item['tiers'] as $idx => $tier) {
                        $qmin   = intval(round(floatval($tier['qty_min'] ?? 1))); // ★ 整數
                        $qmax   = (isset($tier['qty_max']) && $tier['qty_max'] !== '' && $tier['qty_max'] !== null)
                                    ? intval(round(floatval($tier['qty_max']))) : null;      // ★ 整數
                        $tprice = floatval($tier['unit_price'] ?? 0);
                        $tamount= $qmin * $tprice;
                        $tv     = (isset($tier['tolerance_value']) && $tier['tolerance_value'] !== '') ? floatval($tier['tolerance_value']) : null;
                        $tu     = in_array($tier['tolerance_unit'] ?? '', ['%','PCS']) ? $tier['tolerance_unit'] : null;
                        $tn     = trim($tier['tolerance_note'] ?? '') ?: null;
                        $ins_t->execute([$item_id, $qmin, $qmax, $tprice, $tamount, $tv, $tu, $tn, $idx]);
                    }
                }
            }

            // 5. 刪除已移除的 items（CASCADE 自動清 tiers/process_map）
            $to_del = array_diff($existing_ids, $submitted_ids);
            if ($to_del) {
                $ph = implode(',', array_fill(0, count($to_del), '?'));
                $pdo->prepare("DELETE FROM quotation_item WHERE item_id IN ($ph)")->execute(array_values($to_del));
            }

            // 6. 簽核紀錄（approval_record）留痕：送審通知 或 自己簽自己免審的稽核紀錄
            if ($needSubmitPending) {
                $newApprovalId = eg_approval_submit($pdo, 'quotation', $quote_id, 'manager', $user_id, $signerName);
                $leId = eg_quotation_notify_approval($pdo, $quote_id, $mf['quote_no'], $user_id, $signerName);
                if ($leId) eg_approval_set_live_event($pdo, $newApprovalId, $leId);
            } elseif ($newApprovalStatus === 'approved' && $newApprovedBy === $user_id) {
                $selfApprovalId = eg_approval_submit($pdo, 'quotation', $quote_id, 'manager', $user_id, $signerName);
                eg_approval_decide($pdo, $selfApprovalId, $user_id, $signerName, 'approved', null);
            }

            // 7. 附件轉正式：存檔/存草稿後，把此報價單的暫存(temp)附件一律轉為正式(active)並解除自動清除。
            //    存檔前上傳的附件只是暫存、對外不可見；存檔才「正式上傳」。補件重審是獨立流程，不走這裡。
            try {
                $pdo->prepare("UPDATE quotation_attachments SET status='active', expire_at=NULL WHERE quote_no=? AND status='temp'")
                    ->execute([$mf['quote_no']]);
            } catch (Exception $_e) {}

            $pdo->commit();
            if ($lockName) @$pdo->query("SELECT RELEASE_LOCK('{$lockName}')");

            // 存檔後若已不是待審（自己簽自己→自動核准、或退回草稿→none），必須結束該單既有的待簽核通知——
            // 否則其他簽核者的置頂欄會永遠掛著已審完的通知（漏洞：先前只有核准/駁回按鈕那條路才關通知）
            if ($newApprovalStatus !== 'pending') {
                eg_quotation_close_approval_notice($pdo, $quote_id, $user_id);
            }

            $response = ['success' => true, 'message' => '報價單儲存成功。',
                         'new_id' => $quote_id, 'quote_no' => $mf['quote_no'],
                         'forced_draft' => $forcedDraft,
                         'missing_attach_count' => count($missingAttach),
                         'approval_status' => $newApprovalStatus];
            break;

        // ── 主管簽核：核准／駁回 ──
        case 'quotation_approval_decide': {
            $quote_id = intval($_POST['quote_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $note     = trim($_POST['note'] ?? '');
            if (!$quote_id) throw new Exception('缺少報價單ID');
            if (!in_array($decision, ['approved', 'rejected'], true)) throw new Exception('無效的簽核決定');
            if ($decision === 'rejected' && $note === '') throw new Exception('駁回必須填寫原因');
            if (!eg_quotation_user_can_sign($pdo, $user_id)) throw new Exception('您沒有簽核報價單的權限');

            $rec = eg_approval_latest($pdo, 'quotation', $quote_id);
            if (!$rec || $rec['status'] !== 'pending') {
                throw new Exception($rec ? ('此單目前狀態非待審核（' . $rec['status'] . '），無法簽核') : '找不到此單的待審核紀錄');
            }

            $signerName = eg_quotation_current_user_name($pdo, $user_id);
            $pdo->beginTransaction();
            $result = eg_approval_decide($pdo, (int)$rec['id'], $user_id, $signerName, $decision, $note ?: null);
            if (!$result['success']) {
                $pdo->rollBack();
                $response = ['success' => false, 'message' => $result['message']];
                break;
            }

            $quoteRow = $pdo->prepare("SELECT quote_no FROM quotation_list WHERE quote_id=?");
            $quoteRow->execute([$quote_id]);
            $quoteNo = (string)$quoteRow->fetchColumn();

            if ($decision === 'approved') {
                $pdo->prepare("UPDATE quotation_list SET approval_status='approved', approved_by=?, approved_by_name=?, approved_at=NOW() WHERE quote_id=?")
                    ->execute([$user_id, $signerName, $quote_id]);
            } else {
                $pdo->prepare("UPDATE quotation_list SET approval_status='rejected', approved_by=NULL, approved_by_name=NULL, approved_at=NULL WHERE quote_id=?")
                    ->execute([$quote_id]);
            }
            $pdo->commit();

            // 通知：結束原本的待簽核通知、通知原送審人結果（失敗不影響本次操作結果）
            eg_quotation_close_approval_notice($pdo, $quote_id, $user_id);
            eg_quotation_notify_result($pdo, $quote_id, $quoteNo, (int)$rec['submitted_by'], $signerName, $decision, $note ?: null);

            $response = ['success' => true, 'message' => $decision === 'approved' ? '已核准' : '已駁回', 'approval_status' => $decision];
            break;
        }

        // ── 主管簽核：駁回後手動重新送出審核 ──
        case 'quotation_resubmit_approval': {
            $quote_id = intval($_POST['quote_id'] ?? 0);
            if (!$quote_id) throw new Exception('缺少報價單ID');

            $rec = eg_approval_latest($pdo, 'quotation', $quote_id);
            if (!$rec || $rec['status'] !== 'rejected') {
                throw new Exception('此單目前不是「已駁回」狀態，無法重新送出審核');
            }

            $quoteRow = $pdo->prepare("SELECT quote_no, is_draft FROM quotation_list WHERE quote_id=?");
            $quoteRow->execute([$quote_id]);
            $quoteRow2 = $quoteRow->fetch(PDO::FETCH_ASSOC);
            if (!$quoteRow2) throw new Exception('找不到此報價單');
            if (!empty($quoteRow2['is_draft'])) throw new Exception('草稿不可送審，請先存為正式報價單');

            $signerName = eg_quotation_current_user_name($pdo, $user_id);
            $pdo->beginTransaction();
            $newApprovalId = eg_approval_submit($pdo, 'quotation', $quote_id, 'manager', $user_id, $signerName);
            $pdo->prepare("UPDATE quotation_list SET approval_status='pending', approved_by=NULL, approved_by_name=NULL, approved_at=NULL WHERE quote_id=?")
                ->execute([$quote_id]);
            $pdo->commit();

            $leId = eg_quotation_notify_approval($pdo, $quote_id, $quoteRow2['quote_no'], $user_id, $signerName);
            if ($leId) eg_approval_set_live_event($pdo, $newApprovalId, $leId);

            $response = ['success' => true, 'message' => '已重新送出審核'];
            break;
        }

        // ── 複製報價單（完整深層複製，含 items / process_map / tiers）──
        case 'clone':
            $source_id = intval($_POST['source_quote_id'] ?? 0);
            if (!$source_id) throw new Exception('Invalid source ID.');

            $pdo->beginTransaction();

            // 1. 取來源主單
            $src = $pdo->prepare("SELECT * FROM quotation_list WHERE quote_id=?");
            $src->execute([$source_id]);
            $src_main = $src->fetch(PDO::FETCH_ASSOC);
            if (!$src_main) throw new Exception('來源報價單不存在。');

            // 2. 產生新單號
            $roc_year = date('Y') - 1911;
            $prefix   = 'OP' . $roc_year . date('md');
            $stmt_no  = $pdo->prepare("SELECT quote_no FROM quotation_list WHERE quote_no LIKE ? ORDER BY quote_no DESC LIMIT 1");
            $stmt_no->execute(["$prefix%"]);
            $last_no  = $stmt_no->fetchColumn();
            $serial   = $last_no ? ((int)substr($last_no, -3) + 1) : 1;
            $new_no   = $prefix . str_pad($serial, 3, '0', STR_PAD_LEFT);

            // 3. 插入新主單（今天日期，來源記錄）
            $pdo->prepare("
                INSERT INTO quotation_list
                    (quote_no, quote_date, valid_until, client_name, currency, exchange_rate,
                     total_amount, note, created_by, source_quote_id, created_at)
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $new_no,
                $src_main['valid_until'],
                $src_main['client_name'],
                $src_main['currency'],
                $src_main['exchange_rate'],
                $src_main['total_amount'],
                $src_main['note'],
                $user_id,
                $source_id,
            ]);
            $new_quote_id = (int)$pdo->lastInsertId();

            // 4. 複製所有 items（含 process_map 和 tiers）
            $src_items = fetchItemsWithTiers($pdo, $source_id);
            $ins_item  = $pdo->prepare("
                INSERT INTO quotation_item
                    (quote_id,product_id,specification,quantity,unit_price,amount,
                     process_group_type,process_notes,is_tiered,show_bom,print_bom,sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins_map  = $pdo->prepare("INSERT INTO quotation_item_process_map (quotation_item_id,process_no) VALUES (?,?)");
            $ins_tier = $pdo->prepare("
                INSERT INTO quotation_item_tier
                    (item_id,qty_min,qty_max,unit_price,amount,
                     tolerance_value,tolerance_unit,tolerance_note,sort_order)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");

            foreach ($src_items as $item) {
                $ins_item->execute([
                    $new_quote_id,
                    $item['product_id'],
                    $item['specification'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['amount'],
                    $item['process_group_type'],
                    $item['process_notes'],
                    $item['is_tiered'],
                    $item['show_bom'] ?? 0,
                    $item['print_bom'] ?? 0,
                    $item['sort_order'] ?? 0,
                ]);
                $new_item_id = (int)$pdo->lastInsertId();

                // 複製製程
                if (!empty($item['processes'])) {
                    foreach (explode(',', $item['processes']) as $pno) {
                        if (is_numeric(trim($pno))) $ins_map->execute([$new_item_id, trim($pno)]);
                    }
                }

                // 複製階梯
                foreach ($item['tiers'] as $idx => $tier) {
                    $ins_tier->execute([
                        $new_item_id,
                        $tier['qty_min'],
                        $tier['qty_max'],
                        $tier['unit_price'],
                        $tier['amount'],
                        $tier['tolerance_value'],
                        $tier['tolerance_unit'],
                        $tier['tolerance_note'],
                        $idx,
                    ]);
                }
            }

            $pdo->commit();
            $response = ['success' => true, 'message' => '複製成功。', 'new_id' => $new_quote_id, 'new_quote_no' => $new_no];
            break;

        // ── 刪除前查詢是否有綁定訂單（供前端確認用）──
        case 'check_quote_orders':
            $quote_id = intval($_GET['quote_id'] ?? 0);
            if (!$quote_id) throw new Exception('Invalid ID.');
            // 先取得報價單號
            $stmt_qno = $pdo->prepare("SELECT quote_no FROM quotation_list WHERE quote_id=?");
            $stmt_qno->execute([$quote_id]);
            $quote_no = $stmt_qno->fetchColumn();
            if (!$quote_no) throw new Exception('找不到此報價單。');
            // 查 order_track 中有無綁定此報價單號的訂單
            // 正確欄位名：Order_id, Order_oo(訂單號), Client_name, Order_date, Order_status
            $stmt_ord = $pdo->prepare("
                SELECT Order_id AS order_id,
                       Order_oo AS order_no,
                       Client_name AS customer_name,
                       Order_date AS order_date,
                       d_id,
                       Qty AS qty,
                       Order_ps AS order_remark,
                       CASE Order_status
                           WHEN 9 THEN '訂單結束'
                           ELSE '進行中'
                       END AS status
                FROM order_track
                WHERE quote_no = ?
                ORDER BY Order_date DESC
            ");
            $stmt_ord->execute([$quote_no]);
            $orders = $stmt_ord->fetchAll(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'quote_no' => $quote_no, 'orders' => $orders];
            break;

        case 'delete':
            $quote_id      = intval($_POST['quote_id'] ?? 0);
            $force         = !empty($_POST['force_delete']);
            $delete_reason = trim($_POST['delete_reason'] ?? '');
            if (!$quote_id) throw new Exception('Invalid ID.');

            // 自動建立刪除紀錄表
            $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_delete_log (
                id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                quote_id      INT NOT NULL,
                quote_no      VARCHAR(30) NOT NULL,
                quote_date    DATE,
                client_name   VARCHAR(100),
                total_amount  DECIMAL(15,4),
                deleted_by    INT,
                deleted_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                delete_reason VARCHAR(500),
                snapshot      LONGTEXT,
                INDEX idx_qno (quote_no),
                INDEX idx_del_at (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 舊資料表補欄位
            try { $pdo->exec("ALTER TABLE quotation_delete_log ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_at"); } catch(Exception $_ae) {}

            // 取報價單完整資料（含子項）做快照
            $stmt_qno = $pdo->prepare("SELECT ql.*, COALESCE(u.user_cname, ql.created_by) AS created_by_name
                FROM quotation_list ql LEFT JOIN user u ON ql.created_by = u.id WHERE ql.quote_id=?");
            $stmt_qno->execute([$quote_id]);
            $ql_row = $stmt_qno->fetch(PDO::FETCH_ASSOC);
            if (!$ql_row) throw new Exception('找不到該報價單或無法刪除。');
            $quote_no = $ql_row['quote_no'];

            // 確認是否有綁定訂單
            $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM order_track WHERE quote_no=?");
            $stmt_cnt->execute([$quote_no]);
            $bound_count = (int)$stmt_cnt->fetchColumn();

            if ($bound_count > 0 && !$force) {
                $response = [
                    'success'     => false,
                    'code'        => 'HAS_ORDERS',
                    'message'     => "此報價單已綁定 {$bound_count} 筆訂單，確定仍要刪除嗎？",
                    'bound_count' => $bound_count,
                ];
                break;
            }

            // 組裝快照（含所有 items）
            $ql_row['items'] = fetchItemsWithTiers($pdo, $quote_id);
            $snapshot_json   = json_encode($ql_row, JSON_UNESCAPED_UNICODE);

            $pdo->beginTransaction();
            // 解除訂單綁定
            if ($bound_count > 0 && $force) {
                $pdo->prepare("UPDATE order_track SET quote_no = NULL WHERE quote_no=?")
                    ->execute([$quote_no]);
            }
            // 刪除子表（process_map → tier → item → list）
            $item_ids = $pdo->prepare("SELECT item_id FROM quotation_item WHERE quote_id=?");
            $item_ids->execute([$quote_id]);
            $ids = $item_ids->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM quotation_item_process_map WHERE quotation_item_id IN ($ph)")->execute($ids);
                $pdo->prepare("DELETE FROM quotation_item_tier WHERE item_id IN ($ph)")->execute($ids);
            }
            $pdo->prepare("DELETE FROM quotation_item WHERE quote_id=?")->execute([$quote_id]);
            $pdo->prepare("DELETE FROM quotation_list WHERE quote_id=?")->execute([$quote_id]);
            // 寫入刪除紀錄
            $pdo->prepare("INSERT INTO quotation_delete_log (quote_id,quote_no,quote_date,client_name,total_amount,deleted_by,deleted_at,delete_reason,snapshot)
                VALUES (?,?,?,?,?,?,NOW(),?,?)")
                ->execute([$quote_id, $quote_no, $ql_row['quote_date'], $ql_row['client_name'],
                           $ql_row['total_amount'], $user_id, $delete_reason, $snapshot_json]);
            $pdo->commit();
            $response = ['success' => true, 'message' => '報價單已刪除。' . ($bound_count > 0 ? "（已解除 {$bound_count} 筆訂單的報價單綁定）" : '')];
            break;

        case 'get_delete_log':
            $limit  = min(intval($_GET['limit'] ?? 50), 200);
            $offset = intval($_GET['offset'] ?? 0);
            // LIMIT/OFFSET 直接內嵌（已做 intval 驗證，安全）
            $stmt = $pdo->prepare("
                SELECT dl.id, dl.quote_id, dl.quote_no, dl.quote_date, dl.client_name, dl.total_amount, dl.deleted_by, dl.deleted_at,
                       COALESCE(u.user_cname, CAST(dl.deleted_by AS CHAR)) AS deleted_by_name,
                       dl.delete_reason, dl.snapshot
                FROM quotation_delete_log dl
                LEFT JOIN user u ON u.id = dl.deleted_by
                ORDER BY dl.deleted_at DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute();
            $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$pdo->query("SELECT COUNT(*) FROM quotation_delete_log")->fetchColumn();
            $response = ['success' => true, 'data' => $rows, 'total' => $total];
            break;

        case 'get_change_log':
            $qid   = intval($_GET['quote_id'] ?? 0);
            $limit = min(intval($_GET['limit'] ?? 20), 100);
            if (!$qid) { $response = ['success'=>false,'message'=>'缺少 quote_id']; break; }
            try {
                $stmt = $pdo->prepare("
                    SELECT cl.log_id, cl.changed_at, cl.summary, cl.diff_json,
                           COALESCE(u.user_cname, CAST(cl.changed_by AS CHAR)) AS changed_by_name
                    FROM quotation_change_log cl
                    LEFT JOIN user u ON u.id = cl.changed_by
                    WHERE cl.quote_id = ?
                    ORDER BY cl.log_id DESC
                    LIMIT {$limit}
                ");
                $stmt->execute([$qid]);
                $response = ['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
            } catch(Exception $_e) { $response = ['success'=>true,'data'=>[]]; }
            break;

        case 'log_print': {
            $qid = intval($_POST['quote_id'] ?? 0);
            $qno = trim($_POST['quote_no'] ?? '');
            if (!$qid || !$qno) { $response = ['success'=>false,'message'=>'缺少參數']; break; }
            try {
                $pdo->prepare("INSERT INTO quotation_print_log (quote_id,quote_no,printed_by,printed_at) VALUES (?,?,?,NOW())")
                    ->execute([$qid, $qno, $user_id]);
                $response = ['success'=>true];
            } catch(Exception $_e) { $response = ['success'=>true]; }
            break;
        }

        case 'get_print_log': {
            $limit  = min(intval($_GET['limit'] ?? 100), 500);
            $qid    = intval($_GET['quote_id'] ?? 0);
            try {
                $where = $qid ? "WHERE pl.quote_id = {$qid}" : '';
                $stmt  = $pdo->prepare("
                    SELECT pl.id, pl.quote_id, pl.quote_no, pl.printed_at,
                           COALESCE(u.user_cname, CAST(pl.printed_by AS CHAR)) AS printed_by_name,
                           ql.client_name
                    FROM quotation_print_log pl
                    LEFT JOIN user u ON u.id = pl.printed_by
                    LEFT JOIN quotation_list ql ON ql.quote_id = pl.quote_id
                    {$where}
                    ORDER BY pl.printed_at DESC
                    LIMIT {$limit}
                ");
                $stmt->execute();
                $response = ['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
            } catch(Exception $_e) { $response = ['success'=>true,'data'=>[]]; }
            break;
        }

        case 'restore_deleted_quote': {
            // 後端權限驗證：需有 quotation_restore 或 all feature
            try {
                $anyRoles = (int)$pdo->query("SELECT COUNT(*) FROM user_roles")->fetchColumn();
                if ($anyRoles > 0) {
                    $chkR = $pdo->prepare("
                        SELECT 1 FROM user_roles ur
                        JOIN role_features rf ON rf.role_id=ur.role_id
                        WHERE ur.user_id=? AND rf.feature_code IN ('all','quotation_restore') LIMIT 1
                    ");
                    $chkR->execute([$user_id]);
                    if (!$chkR->fetchColumn()) {
                        $response = ['success'=>false,'message'=>'您沒有還原報價單的權限'];
                        break;
                    }
                }
            } catch(Exception $_ep) {}

            $log_id = intval($_POST['log_id'] ?? 0);
            if (!$log_id) { $response = ['success'=>false,'message'=>'缺少 log_id']; break; }
            try {
                $stmt = $pdo->prepare("SELECT snapshot FROM quotation_delete_log WHERE id=?");
                $stmt->execute([$log_id]);
                $snap_json = $stmt->fetchColumn();
                if (!$snap_json) throw new Exception('找不到快照資料');
                $snap = json_decode($snap_json, true);
                if (!$snap) throw new Exception('快照資料無效');
                $response = ['success'=>true, 'data'=>$snap];
            } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
            break;
        }

        case 'get_page_users_permissions': {
            // 取得所有使用者對本頁面的權限（管理員用）
            $module = 'quotation_list';
            // 若此模組尚無任何權限記錄（初始狀態），視為全員管理員
            $totalRecs = (int)$pdo->query("SELECT COUNT(*) FROM user_module_permissions WHERE module_code='quotation_list'")->fetchColumn();
            $ck = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code=? LIMIT 1");
            $ck->execute([$user_id, $module]);
            $ckp = $ck->fetchColumn();
            if ($totalRecs > 0 && $ckp !== 'A') { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
            try {
                $users = $pdo->query("SELECT id, user_cname, user_uname AS account FROM user WHERE state != 0 ORDER BY user_cname ASC")->fetchAll(PDO::FETCH_ASSOC);
                $perms = $pdo->prepare("SELECT user_id, permission FROM user_module_permissions WHERE module_code=?");
                $perms->execute([$module]);
                $permMap = [];
                foreach ($perms->fetchAll(PDO::FETCH_ASSOC) as $p) $permMap[$p['user_id']] = $p['permission'];
                foreach ($users as &$u) $u['permission'] = $permMap[$u['id']] ?? '';
                unset($u);
                $response = ['success'=>true, 'data'=>$users];
            } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
            break;
        }

        case 'save_user_permission': {
            // 管理員設定使用者權限（若模組尚無任何記錄視為初始狀態，允許設定）
            $totalRecs2 = (int)$pdo->query("SELECT COUNT(*) FROM user_module_permissions WHERE module_code='quotation_list'")->fetchColumn();
            $ck2 = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='quotation_list' LIMIT 1");
            $ck2->execute([$user_id]);
            if ($totalRecs2 > 0 && $ck2->fetchColumn() !== 'A') { $response = ['success'=>false,'message'=>'無管理員權限']; break; }
            $target_uid = intval($_POST['target_user_id'] ?? 0);
            $perm       = strtoupper(trim($_POST['permission'] ?? ''));
            $module     = 'quotation_list';
            // 僅允許合法字元
            $perm = preg_replace('/[^ACRDU]/', '', $perm);
            if (!$target_uid) { $response = ['success'=>false,'message'=>'缺少使用者']; break; }
            try {
                if ($perm === '') {
                    $pdo->prepare("DELETE FROM user_module_permissions WHERE user_id=? AND module_code=?")->execute([$target_uid, $module]);
                } else {
                    $pdo->prepare("INSERT INTO user_module_permissions (user_id,module_code,permission,scope) VALUES (?,?,?,'page')
                        ON DUPLICATE KEY UPDATE permission=VALUES(permission)")
                        ->execute([$target_uid, $module, $perm]);
                }
                $response = ['success'=>true];
            } catch(Exception $_e) { $response = ['success'=>false,'message'=>$_e->getMessage()]; }
            break;
        }

        case 'get_new_quote_no':
            $roc_year = date('Y') - 1911;
            $prefix   = 'OP' . $roc_year . date('md');
            $stmt = $pdo->prepare("SELECT quote_no FROM quotation_list WHERE quote_no LIKE ? ORDER BY quote_no DESC LIMIT 1");
            $stmt->execute(["$prefix%"]);
            $last_no = $stmt->fetchColumn();
            $serial  = $last_no ? ((int)substr($last_no, -3) + 1) : 1;
            $response = ['success' => true, 'quote_no' => $prefix . str_pad($serial, 3, '0', STR_PAD_LEFT)];
            break;

        case 'get_quote_no_for_date':
            // 傳入 YYYY-MM-DD，回傳該日期的下一個流水號
            $dateStr = $_GET['date'] ?? date('Y-m-d');
            $dt = new DateTime($dateStr);
            $roc = (int)$dt->format('Y') - 1911;
            $pfx = 'OP' . str_pad($roc,3,'0',STR_PAD_LEFT) . $dt->format('md');
            $stmt = $pdo->prepare("SELECT quote_no FROM quotation_list WHERE quote_no LIKE ? ORDER BY quote_no DESC LIMIT 1");
            $stmt->execute(["$pfx%"]);
            $last = $stmt->fetchColumn();
            $ser  = $last ? ((int)substr($last, -3) + 1) : 1;
            $response = ['success' => true, 'quote_no' => $pfx . str_pad($ser, 3, '0', STR_PAD_LEFT)];
            break;

        case 'get_own_company':
            $stmt = $pdo->query("SELECT * FROM customer_list WHERE is_own_company=1 LIMIT 1");
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $response = $row ? ['success' => true, 'company' => $row] : ['success' => false, 'message' => '找不到本公司資料'];
            break;

        case 'get_print_data':
            // 取報價單完整資料 + 客戶資料 + 聯絡人 + 本公司
            $quote_id = intval($_GET['quote_id'] ?? 0);
            if (!$quote_id) throw new Exception('缺少 quote_id');
            $stmt = $pdo->prepare("SELECT ql.*, COALESCE(u.user_cname, ql.created_by) AS created_by_name,
                    COALESCE(u2.user_cname, ql.updated_by) AS updated_by_name
                FROM quotation_list ql
                LEFT JOIN user u ON ql.created_by = u.id
                LEFT JOIN user u2 ON ql.updated_by = u2.id
                WHERE ql.quote_id=?");
            $stmt->execute([$quote_id]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$quote) throw new Exception('找不到報價單');
            $quote['items'] = fetchItemsWithTiers($pdo, $quote_id);
            // 每個項目補齒輪規格文字
            if (!empty($quote['items'])) {
                // 以 product_id → d_setting.D_Setting_Id 取 d_id、Spec_No
                $gs_did = $pdo->prepare(
                    "SELECT d_id, Spec_No FROM d_setting WHERE D_Setting_Id=? ORDER BY d_id DESC LIMIT 1"
                );
                $gs_fallback = $pdo->prepare(
                    "SELECT DISTINCT d_setting_id FROM order_list WHERE d_id=? AND d_setting_id IS NOT NULL ORDER BY d_setting_id DESC LIMIT 1"
                );
                $gs_fallback_spec = $pdo->prepare(
                    "SELECT d_id, Spec_No FROM d_setting WHERE d_id=? LIMIT 1"
                );
                $gs_rows = $pdo->prepare(
                    "SELECT * FROM d_setting_gear WHERE d_setting_id=? ORDER BY gear_id ASC"
                );
                foreach ($quote['items'] as &$item) {
                    $item['gear_spec'] = '';
                    $item['spec_no']   = '';
                    $pid = $item['product_id'] ?? '';
                    if ($pid === '') continue;

                    // ★ 優先使用報價單儲存的 d_setting_d_id（避免同料號多版本時找到錯誤版本）
                    $did    = intval($item['d_setting_d_id'] ?? 0);
                    $specNo = '';

                    if ($did) {
                        // 直接用儲存的 d_id 取 Spec_No
                        $gs_fallback_spec->execute([$did]);
                        $fr = $gs_fallback_spec->fetch(PDO::FETCH_ASSOC) ?: [];
                        $specNo = trim($fr['Spec_No'] ?? '');
                    } else {
                        // 舊資料（未儲存 d_id）：用 D_Setting_Id 查，優先找有齒輪規格的版本
                        $gs_did->execute([$pid]);
                        $ds_row = $gs_did->fetch(PDO::FETCH_ASSOC) ?: [];
                        $did = intval($ds_row['d_id'] ?? 0);
                        $specNo = trim($ds_row['Spec_No'] ?? '');
                        if (!$did) {
                            $gs_fallback->execute([$pid]);
                            $did = intval($gs_fallback->fetchColumn() ?: 0);
                            if ($did) {
                                $gs_fallback_spec->execute([$did]);
                                $fr = $gs_fallback_spec->fetch(PDO::FETCH_ASSOC) ?: [];
                                $specNo = trim($fr['Spec_No'] ?? '');
                            }
                        }
                    }

                    // 將 Spec_No 獨立欄位帶出，不覆蓋 specification（料號備註）
                    $item['spec_no'] = $specNo;
                    if (!$did) continue;
                    $gs_rows->execute([$did]);
                    $gears = $gs_rows->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($gears)) continue;
                    $texts = [];
                    foreach ($gears as $g) {
                        $p = [];
                        $mod = trim($g['Module'] ?? '');
                        if ($mod !== '') $p[] = preg_match('/^m/i', $mod) ? $mod : 'M'.$mod;
                        if (!empty($g['Teeth']) && (int)$g['Teeth'] > 0) $p[] = 'T'.$g['Teeth'];
                        if (!empty($g['Face_Width']) && (float)$g['Face_Width'] > 0)
                            $p[] = 'W'.rtrim(rtrim(sprintf('%.4f',(float)$g['Face_Width']),'0'),'.');
                        $hdir = trim($g['Helix_Direction'] ?? '');
                        if ($hdir !== '' && $hdir !== 'N/A')
                            $p[] = $hdir.trim($g['Helix_Angle_Str'] ?? '');
                        if ($p) $texts[] = implode(' ', $p);
                    }
                    $item['gear_spec'] = implode(' / ', $texts);
                }
                unset($item);

                // ── 組合件子件清單（畫面顯示或列印勾選時帶出）──
                $bc_did = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? ORDER BY d_id DESC LIMIT 1");
                $bc_rows = $pdo->prepare(
                    "SELECT c.D_Setting_Id AS part_no, c.Spec_No AS spec_no,
                            b.standard_qty, b.Remark_Bom
                     FROM d_setting_bom b
                     JOIN d_setting c ON c.d_id = b.child_d_id
                     WHERE b.parent_d_id = ? ORDER BY b.bom_id ASC"
                );
                foreach ($quote['items'] as &$item) {
                    $item['bom_children'] = [];
                    if (empty($item['show_bom']) && empty($item['print_bom'])) continue;
                    $did = intval($item['d_setting_d_id'] ?? 0);
                    if (!$did && !empty($item['product_id'])) {
                        $bc_did->execute([$item['product_id']]);
                        $did = intval($bc_did->fetchColumn() ?: 0);
                    }
                    if (!$did) continue;
                    $bc_rows->execute([$did]);
                    $item['bom_children'] = $bc_rows->fetchAll(PDO::FETCH_ASSOC);
                }
                unset($item);
            }
            // 客戶資料
            $cust = null;
            if (!empty($quote['client_id'])) {
                $s = $pdo->prepare("SELECT * FROM customer_list WHERE customer_id=?");
                $s->execute([$quote['client_id']]);
                $cust = $s->fetch(PDO::FETCH_ASSOC);
            }
            // 聯絡人
            $contact = null;
            if (!empty($quote['contact_id'])) {
                $s = $pdo->prepare("SELECT * FROM customer_contacts WHERE contact_id=?");
                $s->execute([$quote['contact_id']]);
                $contact = $s->fetch(PDO::FETCH_ASSOC);
            } elseif (!empty($quote['client_id'])) {
                $s = $pdo->prepare("SELECT * FROM customer_contacts WHERE customer_id=? AND is_primary=1 LIMIT 1");
                $s->execute([$quote['client_id']]);
                $contact = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            // 本公司
            $company_stmt = $pdo->query("SELECT * FROM customer_list WHERE is_own_company=1 LIMIT 1");
            $company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            // 表單編號
            $fn_stmt = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='QUOTATION' AND param_key='form_number' LIMIT 1");
            $fn_stmt->execute();
            $form_number = json_decode($fn_stmt->fetchColumn() ?: '""', true) ?: '';
            $response = ['success' => true, 'quote' => $quote, 'customer' => $cust, 'contact' => $contact, 'company' => $company, 'form_number' => $form_number];
            break;

        case 'get_customer_contacts':
            $cid = trim($_GET['customer_id'] ?? '');
            if (!$cid) { $response = ['success' => true, 'contacts' => []]; break; }
            $stmt = $pdo->prepare(
                "SELECT contact_id, name, department, title, phone_ext, mobile, is_primary
                 FROM customer_contacts WHERE customer_id=? ORDER BY is_primary DESC, sort_order, contact_id"
            );
            $stmt->execute([$cid]);
            $response = ['success' => true, 'contacts' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            break;

        case 'get_customer_info':
            $cid = trim($_GET['customer_id'] ?? '');
            if (!$cid) { $response = ['success' => false]; break; }
            $stmt = $pdo->prepare(
                "SELECT customer_id, customer, customer_full, customer_tel, customer_fax, customer_address
                 FROM customer_list WHERE customer_id=?"
            );
            $stmt->execute([$cid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $response = $row ? ['success' => true, 'customer' => $row] : ['success' => false];
            break;

        case 'search_data':
            $type = $_GET['type'] ?? '';
            $term = $_GET['term'] ?? '';
            $data = [];
            if ($type === 'customer') {
                $stmt = $pdo->prepare("SELECT customer_id, customer FROM customer_list WHERE (customer LIKE ? OR customer_id LIKE ?) AND is_inactive=0 LIMIT 15");
                $stmt->execute(["%$term%", "%$term%"]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($type === 'part') {
                $t      = "%$term%";
                $custId = $_GET['customer_id'] ?? '';
                if ($custId !== '') {
                    // 有客戶篩選：只搜尋此客戶的料號（料號 or 規格模糊）
                    $stmt = $pdo->prepare("
                        SELECT d.d_id, d.D_Setting_Id, d.Spec_No, d.Type, d.Revision,
                               c.customer AS Client_Name, c.customer_id
                        FROM d_setting d
                        LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                        WHERE (d.D_Setting_Id LIKE :t OR d.Spec_No LIKE :t)
                          AND d.Customer_Id = :cid
                        ORDER BY d.D_Setting_Id ASC LIMIT 30
                    ");
                    $stmt->execute(['t' => $t, 'cid' => $custId]);
                } else {
                    // 無客戶篩選：原行為（料號 or 規格 or 客戶名稱）
                    $stmt = $pdo->prepare("
                        SELECT d.d_id, d.D_Setting_Id, d.Spec_No, d.Type, d.Revision,
                               c.customer AS Client_Name, c.customer_id
                        FROM d_setting d
                        LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                        WHERE d.D_Setting_Id LIKE :t OR d.Spec_No LIKE :t OR c.customer LIKE :t
                        ORDER BY d.D_Setting_Id ASC LIMIT 30
                    ");
                    $stmt->execute(['t' => $t]);
                }
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $response = ['success' => true, 'data' => $data];
            break;

        case 'get_processes':
            $stmt = $pdo->prepare("SELECT ProcessNo, ProcessName FROM process_no ORDER BY ProcessNo");
            $stmt->execute();
            $response = ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            break;

        case 'get_all_customers':
            $stmt = $pdo->prepare("SELECT * FROM customer_list WHERE is_inactive=0 ORDER BY customer_id ASC");
            $stmt->execute();
            $response = ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            break;

        case 'get_all_parts':
            $stmt = $pdo->prepare("SELECT d_id, D_Setting_Id, Spec_No, Revision FROM d_setting ORDER BY D_Setting_Id ASC");
            $stmt->execute();
            $response = ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            break;

        case 'save_customer':
            $id      = $_POST['customer_id_modal'] ?? '';
            $name    = $_POST['customer_name_modal'] ?? '';
            $addr    = $_POST['customer_address_modal'] ?? '';
            $tel     = $_POST['customer_tel_modal'] ?? '';
            $fax     = $_POST['customer_fax_modal'] ?? '';
            $taxId   = $_POST['customer_taxid_modal'] ?? '';
            $contact = trim($_POST['customer_contact_modal'] ?? '');
            if (empty($name)) throw new Exception('客戶名稱不可為空');

            $pdo->beginTransaction();
            try {
                if (empty($id)) {
                    if (empty($_POST['customer_id_new'])) throw new Exception('新增客戶時，客戶代碼不可為空');
                    $id = $_POST['customer_id_new'];
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?");
                    $chk->execute([$id]);
                    if ($chk->fetchColumn() > 0) throw new Exception('客戶代碼已存在');
                    $pdo->prepare("INSERT INTO customer_list (customer_id,customer,customer_address,customer_tel,customer_fax,tax_id,is_inactive,Created_By) VALUES (?,?,?,?,?,?,0,?)")->execute([$id,$name,$addr,$tel,$fax,$taxId,$user_id]);
                } else {
                    $pdo->prepare("UPDATE customer_list SET customer=?,customer_address=?,customer_tel=?,customer_fax=?,tax_id=?,Modified_By=?,Modified_At=NOW() WHERE customer_id=?")->execute([$name,$addr,$tel,$fax,$taxId,$user_id,$id]);
                }
                if ($contact !== '') {
                    $chkC = $pdo->prepare("SELECT contact_id FROM customer_contacts WHERE customer_id=? AND is_primary=1 LIMIT 1");
                    $chkC->execute([$id]);
                    $existC = $chkC->fetchColumn();
                    if ($existC) {
                        $pdo->prepare("UPDATE customer_contacts SET name=? WHERE contact_id=?")->execute([$contact, $existC]);
                    } else {
                        $pdo->prepare("INSERT INTO customer_contacts (customer_id,name,is_primary,sort_order) VALUES (?,?,1,0)")->execute([$id, $contact]);
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            $response = ['success' => true, 'message' => '客戶資料已儲存', 'customer_id' => $id];
            break;

        case 'save_part_info':
            $d_id      = $_POST['d_id']        ?? null;
            $part_no   = trim($_POST['part_no'] ?? '');
            $type      = $_POST['type']         ?? 'N';
            $cust_id   = $_POST['customer_id']  ?? null;
            $revision  = $_POST['revision']     ?? '';
            $issue_date= !empty($_POST['issue_date']) ? $_POST['issue_date'] : null;
            $remark    = $_POST['remark']       ?? '';
            $gears_raw = $_POST['gears']        ?? '[]';
            $gears     = json_decode($gears_raw, true) ?: [];
            if (empty($part_no)) throw new Exception('料號不可為空');
            $cust_id = ($cust_id !== '' && $cust_id !== null) ? $cust_id : null;

            $pdo->beginTransaction();

            // ── 查重：料號 + 客戶 相同即視為重複（版次/發行日不參與判斷）──
            // 客戶 NULL 視為「無客戶」，兩筆都 NULL 也算相同
            if (!empty($d_id)) {
                // 更新模式：排除自身
                $dup_stmt = $pdo->prepare("
                    SELECT d_id FROM d_setting
                    WHERE D_Setting_Id = ?
                      AND (
                            (Customer_Id = ? AND Customer_Id IS NOT NULL)
                         OR (Customer_Id IS NULL AND ? IS NULL)
                      )
                      AND d_id <> ?
                    LIMIT 1
                ");
                $dup_stmt->execute([$part_no, $cust_id, $cust_id, $d_id]);
            } else {
                // 新增模式
                $dup_stmt = $pdo->prepare("
                    SELECT d_id FROM d_setting
                    WHERE D_Setting_Id = ?
                      AND (
                            (Customer_Id = ? AND Customer_Id IS NOT NULL)
                         OR (Customer_Id IS NULL AND ? IS NULL)
                      )
                    LIMIT 1
                ");
                $dup_stmt->execute([$part_no, $cust_id, $cust_id]);
            }
            $exist_id = $dup_stmt->fetchColumn();
            if ($exist_id) {
                $pdo->rollBack();
                // 查出既有資料的版次讓錯誤訊息更友善
                $exist_info = $pdo->prepare("SELECT D_Setting_Id, Revision, Issue_Date FROM d_setting WHERE d_id=?");
                $exist_info->execute([$exist_id]);
                $ei = $exist_info->fetch(PDO::FETCH_ASSOC);
                $hint = '';
                if (!empty($ei['Revision']))   $hint .= ' 版次：' . $ei['Revision'];
                if (!empty($ei['Issue_Date'])) $hint .= ' 發行日：' . $ei['Issue_Date'];
                throw new Exception("已存在相同料號與客戶的資料（d_id={$exist_id}{$hint}），不可重複建立。若要新版本請修改版次或發行日後再儲存。");
            }

            if (!empty($d_id)) {
                $pdo->prepare("UPDATE d_setting SET D_Setting_Id=?,Type=?,Customer_Id=?,Revision=?,Issue_Date=?,Remark=?,Modified_By=?,Modified_At=NOW() WHERE d_id=?")
                    ->execute([$part_no,$type,$cust_id,$revision,$issue_date,$remark,$user_id,$d_id]);
            } else {
                $pdo->prepare("INSERT INTO d_setting (D_Setting_Id,Type,Customer_Id,Revision,Issue_Date,Remark,Created_By,Created_At) VALUES (?,?,?,?,?,?,?,NOW())")
                    ->execute([$part_no,$type,$cust_id,$revision,$issue_date,$remark,$user_id]);
                $d_id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("DELETE FROM d_setting_gear WHERE d_setting_id=?")->execute([$d_id]);
            if ($type === 'G' && !empty($gears)) {
                $ins_g = $pdo->prepare("INSERT INTO d_setting_gear (d_setting_id,Gear_Type,Module,Teeth,Pressure_Angle,Face_Width,Workpiece_Length,Profile_Shift_X,Helix_Angle,Helix_Angle_Str,Helix_Direction,Remark_Gear) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($gears as $g) {
                    $ins_g->execute([$d_id,$g['Gear_Type']??null,$g['Module']??null,$g['Teeth']??null,$g['Pressure_Angle']??null,$g['Face_Width']??null,$g['Workpiece_Length']??null,(isset($g['Profile_Shift_X'])&&$g['Profile_Shift_X']!=='')?$g['Profile_Shift_X']:null,(isset($g['Helix_Angle'])&&$g['Helix_Angle']!=='')?$g['Helix_Angle']:null,$g['Helix_Angle_Str']??null,$g['Helix_Direction']??null,$g['Remark_Gear']??null]);
                }
            }
            $pdo->commit();
            $response = ['success' => true, 'message' => '料號資料儲存成功', 'd_id' => (int)$d_id];
            break;

        // ── 取單筆料號詳細資料（含齒輪） ──
        case 'get_part_detail':
            $d_id = intval($_GET['d_id'] ?? 0);
            if (!$d_id) throw new Exception('無效的料號 ID');
            $stmt = $pdo->prepare("SELECT d.*, c.customer AS Client_Name FROM d_setting d LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id WHERE d.d_id = ?");
            $stmt->execute([$d_id]);
            $part = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$part) throw new Exception('找不到此料號');
            if ($part['Type'] === 'G') {
                $stmt_g = $pdo->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id = ? ORDER BY gear_id ASC");
                $stmt_g->execute([$d_id]);
                $part['gears'] = $stmt_g->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $part['gears'] = [];
            }
            $response = ['success' => true, 'data' => $part];
            break;

        // ── 刪除料號 ──
        case 'delete_part':
            $d_id = intval($_POST['d_id'] ?? 0);
            if (!$d_id) throw new Exception('無效的料號 ID');
            $pdo->prepare("DELETE FROM d_setting WHERE d_id=?")->execute([$d_id]);
            $response = ['success' => true, 'message' => '料號已刪除'];
            break;

        // ── 料號標籤字典 ──
        case 'get_part_labels':
            $rows = $pdo->query(
                "SELECT label_id, label_name, input_type FROM dict_label WHERE is_active=1 ORDER BY sort_order, label_id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'labels' => $rows];
            break;

        case 'get_part_label_subs':
            $lid = intval($_GET['label_id'] ?? 0);
            if (!$lid) { $response = ['success'=>true,'subs'=>[]]; break; }
            $stmt = $pdo->prepare(
                "SELECT sub_id, sub_name, is_enum FROM dict_label_sub WHERE label_id=? AND is_active=1 ORDER BY sort_order, sub_id"
            );
            $stmt->execute([$lid]);
            $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // 列舉型子標籤一併帶入孫子選項
            $opts_q = $pdo->prepare("SELECT option_value FROM dict_label_sub_option WHERE sub_id=? AND is_active=1 ORDER BY sort_order, option_id");
            foreach ($subs as &$s) {
                if (!empty($s['is_enum'])) {
                    $opts_q->execute([$s['sub_id']]);
                    $s['options'] = $opts_q->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $s['options'] = [];
                }
            }
            unset($s);
            $response = ['success' => true, 'subs' => $subs];
            break;

        // ── 齒輪規格查詢（by d_id 或 D_Setting_Id）──
        // ── 組合件資訊：是否組合件 + 子件清單 + 反查所屬母件（同子件可能屬多個組合件）──
        case 'get_bom_info':
            $did    = intval($_GET['d_id'] ?? 0);
            $partId = trim($_GET['part_id'] ?? '');
            if (!$did && $partId) {
                $s = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? ORDER BY d_id DESC LIMIT 1");
                $s->execute([$partId]);
                $did = intval($s->fetchColumn() ?: 0);
            }
            if (!$did) { $response = ['success'=>true,'is_assembly'=>0,'children'=>[],'parents'=>[]]; break; }
            $s = $pdo->prepare("SELECT Is_Assembly FROM d_setting WHERE d_id=?");
            $s->execute([$did]);
            $isAsm = intval($s->fetchColumn() ?: 0);
            $children = [];
            if ($isAsm) {
                $s = $pdo->prepare(
                    "SELECT c.d_id AS child_d_id, c.D_Setting_Id AS part_no, c.Spec_No AS spec_no,
                            b.standard_qty, b.Remark_Bom
                     FROM d_setting_bom b
                     JOIN d_setting c ON c.d_id = b.child_d_id
                     WHERE b.parent_d_id = ? ORDER BY b.bom_id ASC"
                );
                $s->execute([$did]);
                $children = $s->fetchAll(PDO::FETCH_ASSOC);
            }
            $s = $pdo->prepare(
                "SELECT DISTINCT p.D_Setting_Id AS part_no
                 FROM d_setting_bom b
                 JOIN d_setting p ON p.d_id = b.parent_d_id
                 WHERE b.child_d_id = ? ORDER BY p.D_Setting_Id ASC"
            );
            $s->execute([$did]);
            $parents = $s->fetchAll(PDO::FETCH_COLUMN);
            $response = ['success'=>true, 'is_assembly'=>$isAsm, 'children'=>$children, 'parents'=>$parents];
            break;

        case 'get_gear_specs':
            $did    = intval($_GET['d_id'] ?? 0);
            $partId = trim($_GET['part_id'] ?? '');
            // 若無 d_id，用 D_Setting_Id 查
            // 優先找「同 D_Setting_Id 中有齒輪資料的最新 d_id」，避免多版本料號找到無齒輪規格的版本
            if (!$did && $partId) {
                $s = $pdo->prepare("
                    SELECT d.d_id FROM d_setting d
                    INNER JOIN d_setting_gear g ON g.d_setting_id = d.d_id
                    WHERE d.D_Setting_Id = ?
                    ORDER BY d.d_id DESC LIMIT 1
                ");
                $s->execute([$partId]);
                $did = intval($s->fetchColumn() ?: 0);
                // 備援：取最新 d_id（即使無齒輪資料）
                if (!$did) {
                    $s2 = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? ORDER BY d_id DESC LIMIT 1");
                    $s2->execute([$partId]);
                    $did = intval($s2->fetchColumn() ?: 0);
                }
            }
            if (!$did) { $response = ['success'=>true,'gears'=>[]]; break; }
            $stmt = $pdo->prepare(
                "SELECT * FROM d_setting_gear WHERE d_setting_id = ? ORDER BY gear_id ASC"
            );
            $stmt->execute([$did]);
            $response = ['success'=>true, 'gears' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            break;

        // ── 歷史報價查詢：同客戶 + 同料號 ──
        case 'get_price_history':
            $client  = $_GET['client_name'] ?? '';
            $product = $_GET['product_id']  ?? '';
            if (!$client || !$product) throw new Exception('缺少客戶或料號參數');
            $stmt = $pdo->prepare("
                SELECT
                    ql.quote_id, ql.quote_no, ql.quote_date, ql.currency, ql.is_negotiation,
                    qi.item_id, qi.quantity, qi.unit_price, qi.amount, qi.is_tiered,
                    qi.specification,
                    GROUP_CONCAT(DISTINCT qipm.process_no ORDER BY qipm.process_no) AS processes
                FROM quotation_item qi
                JOIN quotation_list ql ON qi.quote_id = ql.quote_id
                LEFT JOIN quotation_item_process_map qipm ON qi.item_id = qipm.quotation_item_id
                WHERE ql.client_name = ? AND qi.product_id = ?
                GROUP BY qi.item_id
                ORDER BY ql.quote_date DESC, ql.quote_id DESC
                LIMIT 20
            ");
            $stmt->execute([$client, $product]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // 附上階梯資料
            foreach ($rows as &$row) {
                if ($row['is_tiered']) {
                    $st = $pdo->prepare("SELECT * FROM quotation_item_tier WHERE item_id=? ORDER BY sort_order,qty_min");
                    $st->execute([$row['item_id']]);
                    $row['tiers'] = $st->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $row['tiers'] = [];
                }
            }
            unset($row);
            $response = ['success' => true, 'data' => $rows];
            break;

        // ── 取得/儲存系統參數 ──
        case 'get_param':
            $pg  = $_GET['param_group'] ?? '';
            $pk  = $_GET['param_key']   ?? '';
            if (!$pg || !$pk) throw new Exception('缺少參數');
            $stmt = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=?");
            $stmt->execute([$pg, $pk]);
            $val = $stmt->fetchColumn();
            $response = ['success' => true, 'value' => $val ? json_decode($val, true) : null];
            break;

        case 'save_param':
            $pg   = $_POST['param_group'] ?? '';
            $pk   = $_POST['param_key']   ?? '';
            $pval = $_POST['param_value'] ?? '';
            $desc = $_POST['description'] ?? '';
            if (!$pg || !$pk) throw new Exception('缺少參數');
            // 驗證是合法 JSON
            $decoded = json_decode($pval, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('param_value 必須是合法 JSON');
            $stmt = $pdo->prepare("
                INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at)
                VALUES (?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE param_value=VALUES(param_value), description=VALUES(description),
                                        updated_by=VALUES(updated_by), updated_at=NOW()
            ");
            $stmt->execute([$pg, $pk, $pval, $desc, $user_id]);
            $response = ['success' => true, 'message' => '參數已儲存'];
            break;

        // ── 依料號查詢歷史報價單（含階梯、製程中文名，供訂單綁定選擇）──
        case 'get_quotes_by_part':
            $part_text = trim($_GET['part_text'] ?? '');
            if (empty($part_text)) throw new Exception('請提供料號');

            // Step1: 查報價單主體（含 is_negotiation、is_tiered）
            $stmt_q = $pdo->prepare("
                SELECT ql.quote_id, ql.quote_no, ql.quote_date, ql.client_name,
                       ql.note AS quote_note, ql.is_negotiation,
                       qi.item_id, qi.product_id, qi.specification,
                       qi.quantity, qi.unit_price, qi.process_notes, qi.is_tiered,
                       GROUP_CONCAT(qipm.process_no ORDER BY qipm.process_no SEPARATOR ',') AS process_nos
                FROM quotation_list ql
                JOIN quotation_item qi ON ql.quote_id = qi.quote_id
                LEFT JOIN quotation_item_process_map qipm ON qi.item_id = qipm.quotation_item_id
                WHERE qi.product_id LIKE :term
                GROUP BY qi.item_id
                ORDER BY ql.quote_date DESC, ql.quote_id DESC
                LIMIT 30
            ");
            $stmt_q->execute([':term' => "%$part_text%"]);
            $qrows = $stmt_q->fetchAll(PDO::FETCH_ASSOC);

            // Step2: 批次查製程中文名
            $allPnos = [];
            foreach ($qrows as $r) {
                if (!empty($r['process_nos'])) {
                    foreach (explode(',', $r['process_nos']) as $pno) {
                        $pno = trim($pno);
                        if ($pno !== '') $allPnos[intval($pno)] = true;
                    }
                }
            }
            $pnMap = [];
            if (!empty($allPnos)) {
                $pnosArr = array_keys($allPnos);
                $ph2 = implode(',', array_fill(0, count($pnosArr), '?'));
                $spn = $pdo->prepare("SELECT ProcessNo, ProcessName FROM process_no WHERE ProcessNo IN ($ph2)");
                $spn->execute($pnosArr);
                foreach ($spn->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                    $pnMap[intval($pr['ProcessNo'])] = $pr['ProcessName'];
                }
            }

            // Step3: 組裝製程文字 + 撈階梯
            $tiered_item_ids = array_column(
                array_filter($qrows, fn($r) => $r['is_tiered']),
                'item_id'
            );
            $tier_map2 = [];
            if (!empty($tiered_item_ids)) {
                $ph3 = implode(',', array_fill(0, count($tiered_item_ids), '?'));
                $st2 = $pdo->prepare("SELECT item_id, qty_min, qty_max, unit_price FROM quotation_item_tier WHERE item_id IN ($ph3) ORDER BY item_id, sort_order, qty_min");
                $st2->execute($tiered_item_ids);
                foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $tier_map2[$t['item_id']][] = $t;
                }
            }

            foreach ($qrows as &$r) {
                if (!empty($r['process_nos'])) {
                    $parts2 = array_map(function($pno) use ($pnMap) {
                        $pno = intval(trim($pno));
                        return $pnMap[$pno] ?? $pno;
                    }, explode(',', $r['process_nos']));
                    $r['processes'] = implode(' / ', $parts2);
                } else {
                    $r['processes'] = $r['process_notes'] ?? '';
                }
                $r['tiers'] = $r['is_tiered'] ? ($tier_map2[$r['item_id']] ?? []) : [];
            }
            unset($r);

            $response = ['success' => true, 'data' => $qrows];
            break;

        // ── 報價單號模糊搜尋（供訂單綁定管理用）──
        case 'search_quote_no':
            $term = $_GET['term'] ?? '';
            $stmt = $pdo->prepare("
                SELECT quote_no, client_name, quote_date
                FROM quotation_list
                WHERE quote_no LIKE ?
                ORDER BY quote_date DESC, quote_no DESC
                LIMIT 10
            ");
            $stmt->execute(["%$term%"]);
            $response = ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            break;

        // ── 更新訂單的報價單綁定（改 quote_no，可選帶入 unit_price）──
        case 'update_order_quote_bind':
            $order_id   = intval($_POST['order_id'] ?? 0);
            $new_qno    = trim($_POST['quote_no'] ?? '');
            $unit_price = isset($_POST['unit_price']) && $_POST['unit_price'] !== '' ? floatval($_POST['unit_price']) : null;
            if (!$order_id) throw new Exception('無效的訂單 ID');

            if ($new_qno !== '') {
                // 驗證報價單號是否存在
                $chk = $pdo->prepare("SELECT quote_no FROM quotation_list WHERE quote_no=? LIMIT 1");
                $chk->execute([$new_qno]);
                if (!$chk->fetchColumn()) {
                    throw new Exception("找不到報價單號「{$new_qno}」，請確認後再試。");
                }
                if ($unit_price !== null && $unit_price > 0) {
                    $pdo->prepare("UPDATE order_track SET quote_no=?, unit_price=?, Modified_By=?, Modified_At=NOW() WHERE Order_id=?")
                        ->execute([$new_qno, $unit_price, $user_id, $order_id]);
                } else {
                    $pdo->prepare("UPDATE order_track SET quote_no=?, Modified_By=?, Modified_At=NOW() WHERE Order_id=?")
                        ->execute([$new_qno, $user_id, $order_id]);
                }
                $response = ['success' => true, 'message' => '已更新綁定報價單'];
            } else {
                // 清除綁定
                $pdo->prepare("UPDATE order_track SET quote_no=NULL, Modified_By=?, Modified_At=NOW() WHERE Order_id=?")
                    ->execute([$user_id, $order_id]);
                $response = ['success' => true, 'message' => '已清除報價單綁定'];
            }
            break;

        // ════════════════════════════════════════════════════
        // 製程標籤 CRUD（自建標籤群組 + 子標籤 + 連結 process_no）
        // ════════════════════════════════════════════════════

        // ════════════════════════════════════════════════════
        // 備註模板 CRUD
        // ════════════════════════════════════════════════════
        case 'init_note_templates':
            $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_note_templates (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                label           VARCHAR(30)   NOT NULL COMMENT '按鈕標籤（短名稱）',
                note_text       VARCHAR(500)  NOT NULL COMMENT '追加到備註欄的文字',
                variables       TEXT          NULL     COMMENT '變數定義 JSON',
                auto_for_full   TINYINT NOT NULL DEFAULT 0 COMMENT '全製程時自動帶入',
                auto_for_single TINYINT NOT NULL DEFAULT 0 COMMENT '單一製程時自動帶入',
                sort_order      INT NOT NULL DEFAULT 0,
                is_active       TINYINT NOT NULL DEFAULT 1,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 相容舊表：補加欄位
            try { $pdo->exec("ALTER TABLE quotation_note_templates ADD COLUMN variables TEXT NULL AFTER note_text"); } catch(PDOException $e){}
            try { $pdo->exec("ALTER TABLE quotation_note_templates ADD COLUMN auto_for_full TINYINT NOT NULL DEFAULT 0 AFTER variables"); } catch(PDOException $e){}
            try { $pdo->exec("ALTER TABLE quotation_note_templates ADD COLUMN auto_for_single TINYINT NOT NULL DEFAULT 0 AFTER auto_for_full"); } catch(PDOException $e){}
            $response = ['success' => true];
            break;

        case 'get_note_templates':
            $rows = $pdo->query(
                "SELECT id, label, note_text, variables, auto_for_full, auto_for_single, sort_order FROM quotation_note_templates
                 WHERE is_active=1 ORDER BY sort_order, id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'templates' => $rows];
            break;

        case 'get_all_note_templates':
            $rows = $pdo->query(
                "SELECT id, label, note_text, variables, auto_for_full, auto_for_single, sort_order, is_active FROM quotation_note_templates
                 ORDER BY sort_order, id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'templates' => $rows];
            break;

        case 'save_note_template':
            $tid    = intval($_POST['tmpl_id'] ?? 0);
            $label  = trim($_POST['label'] ?? '');
            $text   = trim($_POST['note_text'] ?? '');
            $vars   = trim($_POST['variables'] ?? '[]');
            $ord    = intval($_POST['sort_order'] ?? 0);
            $aFull  = intval($_POST['auto_for_full']   ?? 0) ? 1 : 0;
            $aSingle= intval($_POST['auto_for_single'] ?? 0) ? 1 : 0;
            if (!$label || !$text) throw new Exception('標籤名稱與備註文字均不可為空');
            if (json_decode($vars) === null) $vars = '[]';
            if ($tid) {
                $pdo->prepare("UPDATE quotation_note_templates SET label=?,note_text=?,variables=?,auto_for_full=?,auto_for_single=?,sort_order=? WHERE id=?")
                    ->execute([$label, $text, $vars, $aFull, $aSingle, $ord, $tid]);
                $response = ['success' => true, 'message' => '已更新', 'tmpl_id' => $tid];
            } else {
                $pdo->prepare("INSERT INTO quotation_note_templates (label,note_text,variables,auto_for_full,auto_for_single,sort_order) VALUES (?,?,?,?,?,?)")
                    ->execute([$label, $text, $vars, $aFull, $aSingle, $ord]);
                $response = ['success' => true, 'message' => '已新增', 'tmpl_id' => (int)$pdo->lastInsertId()];
            }
            break;

        case 'reorder_note_templates':
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!is_array($ids)) throw new Exception('格式錯誤');
            $stmt = $pdo->prepare("UPDATE quotation_note_templates SET sort_order=? WHERE id=?");
            foreach ($ids as $i => $id) $stmt->execute([$i, intval($id)]);
            $response = ['success' => true];
            break;

        case 'toggle_note_template':
            $tid = intval($_POST['tmpl_id'] ?? 0);
            if (!$tid) throw new Exception('缺少 tmpl_id');
            $pdo->prepare("UPDATE quotation_note_templates SET is_active = 1 - is_active WHERE id=?")
                ->execute([$tid]);
            $response = ['success' => true, 'message' => '已切換'];
            break;

        case 'init_process_tags':
            // 自動建立三張製程標籤表（首次使用時呼叫）
            $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_process_tag_group (
                group_id   INT AUTO_INCREMENT PRIMARY KEY,
                group_name VARCHAR(50) NOT NULL,
                group_type VARCHAR(20) NOT NULL DEFAULT 'single_process' COMMENT '全製:full_process 單一:single_process',
                sort_order INT NOT NULL DEFAULT 0,
                is_active  TINYINT NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 相容舊表：補加 group_type 欄
            try { $pdo->exec("ALTER TABLE quotation_process_tag_group ADD COLUMN group_type VARCHAR(20) NOT NULL DEFAULT 'single_process' AFTER group_name"); } catch(PDOException $e){}
            $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_process_sub_tag (
                sub_tag_id   INT AUTO_INCREMENT PRIMARY KEY,
                group_id     INT NOT NULL,
                sub_tag_name VARCHAR(50) NOT NULL,
                sort_order   INT NOT NULL DEFAULT 0,
                is_active    TINYINT NOT NULL DEFAULT 1,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_group (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_process_tag_map (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                sub_tag_id INT NOT NULL,
                process_no INT NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                UNIQUE KEY uk_map (sub_tag_id, process_no),
                INDEX idx_sub (sub_tag_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $response = ['success' => true];
            break;

        case 'get_process_tag_tree':
            // 回傳完整三層樹：群組 → 子標籤 → 製程清單
            $groups = $pdo->query("
                SELECT group_id, group_name, group_type, sort_order
                FROM quotation_process_tag_group
                WHERE is_active=1 ORDER BY sort_order, group_id
            ")->fetchAll(PDO::FETCH_ASSOC);

            $subTags = $pdo->query("
                SELECT s.sub_tag_id, s.group_id, s.sub_tag_name, s.sort_order,
                       GROUP_CONCAT(m.process_no ORDER BY m.sort_order) AS process_nos
                FROM quotation_process_sub_tag s
                LEFT JOIN quotation_process_tag_map m ON m.sub_tag_id = s.sub_tag_id
                WHERE s.is_active=1
                GROUP BY s.sub_tag_id
                ORDER BY s.group_id, s.sort_order
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 組合成巢狀結構
            $subByGroup = [];
            foreach ($subTags as $st) {
                $st['process_nos'] = $st['process_nos']
                    ? array_map('intval', explode(',', $st['process_nos'])) : [];
                $subByGroup[$st['group_id']][] = $st;
            }
            foreach ($groups as &$g) {
                $g['sub_tags'] = $subByGroup[$g['group_id']] ?? [];
            }
            $response = ['success' => true, 'tree' => $groups];
            break;

        case 'save_process_tag_group':
            $gid   = intval($_POST['group_id'] ?? 0);
            $name  = trim($_POST['group_name'] ?? '');
            $ord   = intval($_POST['sort_order'] ?? 0);
            $gtype = in_array($_POST['group_type'] ?? '', ['full_process','single_process'])
                     ? $_POST['group_type'] : 'single_process';
            if (!$name) throw new Exception('標籤群組名稱不可為空');
            if ($gid) {
                $pdo->prepare("UPDATE quotation_process_tag_group SET group_name=?,group_type=?,sort_order=? WHERE group_id=?")
                    ->execute([$name, $gtype, $ord, $gid]);
                $response = ['success' => true, 'message' => '已更新', 'group_id' => $gid];
            } else {
                $pdo->prepare("INSERT INTO quotation_process_tag_group (group_name,group_type,sort_order) VALUES (?,?,?)")
                    ->execute([$name, $gtype, $ord]);
                $response = ['success' => true, 'message' => '已新增', 'group_id' => (int)$pdo->lastInsertId()];
            }
            break;

        case 'reorder_process_tag_groups':
            // ids = JSON array of group_id 依新順序排列
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!is_array($ids)) throw new Exception('格式錯誤');
            $stmt = $pdo->prepare("UPDATE quotation_process_tag_group SET sort_order=? WHERE group_id=?");
            foreach ($ids as $i => $id) $stmt->execute([$i, intval($id)]);
            $response = ['success' => true];
            break;

        case 'reorder_process_sub_tags':
            // ids = JSON array of sub_tag_id 依新順序排列
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!is_array($ids)) throw new Exception('格式錯誤');
            $stmt = $pdo->prepare("UPDATE quotation_process_sub_tag SET sort_order=? WHERE sub_tag_id=?");
            foreach ($ids as $i => $id) $stmt->execute([$i, intval($id)]);
            $response = ['success' => true];
            break;

        case 'delete_process_tag_group':
            $gid = intval($_POST['group_id'] ?? 0);
            if (!$gid) throw new Exception('缺少 group_id');
            // 取得子標籤 IDs
            $sids = $pdo->prepare("SELECT sub_tag_id FROM quotation_process_sub_tag WHERE group_id=?");
            $sids->execute([$gid]);
            $sidList = $sids->fetchAll(PDO::FETCH_COLUMN);
            if ($sidList) {
                $pl = implode(',', array_map('intval', $sidList));
                $pdo->exec("DELETE FROM quotation_process_tag_map WHERE sub_tag_id IN ($pl)");
            }
            $pdo->prepare("DELETE FROM quotation_process_sub_tag WHERE group_id=?")->execute([$gid]);
            $pdo->prepare("DELETE FROM quotation_process_tag_group WHERE group_id=?")->execute([$gid]);
            $response = ['success' => true, 'message' => '已刪除'];
            break;

        case 'save_process_sub_tag':
            $sid  = intval($_POST['sub_tag_id'] ?? 0);
            $gid  = intval($_POST['group_id'] ?? 0);
            $name = trim($_POST['sub_tag_name'] ?? '');
            $ord  = intval($_POST['sort_order'] ?? 0);
            if (!$name || !$gid) throw new Exception('參數不完整');
            if ($sid) {
                $pdo->prepare("UPDATE quotation_process_sub_tag SET sub_tag_name=?,sort_order=? WHERE sub_tag_id=?")
                    ->execute([$name, $ord, $sid]);
                $response = ['success' => true, 'message' => '已更新', 'sub_tag_id' => $sid];
            } else {
                $pdo->prepare("INSERT INTO quotation_process_sub_tag (group_id,sub_tag_name,sort_order) VALUES (?,?,?)")
                    ->execute([$gid, $name, $ord]);
                $response = ['success' => true, 'message' => '已新增', 'sub_tag_id' => (int)$pdo->lastInsertId()];
            }
            break;

        case 'delete_process_sub_tag':
            $sid = intval($_POST['sub_tag_id'] ?? 0);
            if (!$sid) throw new Exception('缺少 sub_tag_id');
            $pdo->prepare("DELETE FROM quotation_process_tag_map WHERE sub_tag_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM quotation_process_sub_tag WHERE sub_tag_id=?")->execute([$sid]);
            $response = ['success' => true, 'message' => '已刪除'];
            break;

        case 'save_process_tag_processes':
            // 更新子標籤下的製程對應（全量替換）
            $sid  = intval($_POST['sub_tag_id'] ?? 0);
            $pnos = json_decode($_POST['process_nos'] ?? '[]', true) ?: [];
            if (!$sid) throw new Exception('缺少 sub_tag_id');
            $pdo->prepare("DELETE FROM quotation_process_tag_map WHERE sub_tag_id=?")->execute([$sid]);
            if ($pnos) {
                $ins = $pdo->prepare("INSERT IGNORE INTO quotation_process_tag_map (sub_tag_id, process_no, sort_order) VALUES (?,?,?)");
                foreach (array_values($pnos) as $i => $pno) {
                    $ins->execute([$sid, intval($pno), $i]);
                }
            }
            $response = ['success' => true, 'message' => '已儲存'];
            break;

        default:
            $response['message'] = 'Invalid action.';
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>