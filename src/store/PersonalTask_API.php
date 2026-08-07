<?php
// PersonalTask_API.php — 個人工作紀錄後端 API
// 每筆紀錄僅擁有者本人可見/可操作（所有查詢與寫入一律 WHERE user_id = 登入者）。
// 功能：紀錄CRUD、狀態切換(未完成/已完成/暫停)、進度步驟(依序回報/拖移排序/到達時間)、
//       流程範本、個人急件天數設定、綁定搜尋(BOM/料號/客戶/廠商)、
//       附件圖片(存NAS只記檔名，路徑由管理員在 system_settings ptask_nas_dir/ptask_url_dir 統一設定)。
// 前端：views/user/personal_task.php ｜ 提醒發送：src/common/personal_task_notify.php(順路觸發)
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/role_features_helper.php';
require_once __DIR__ . '/../common/personal_task_notify.php';  // 分享通知沿用 personal_task_remind_user（Push＋Telegram，不寫 live_event）

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => '尚未登入']);
    exit;
}
$user_id = (int)$_SESSION['id'];

$conn = new DBConnection();
$db = $conn->getPDO();

// ── 欄位升級（附件暫存機制 2026-07-23）：舊表補欄，已存在時略過 ─────────
try { $db->exec("ALTER TABLE personal_task_image ADD COLUMN user_id INT NULL COMMENT '上傳者/擁有者 FK→user.id（temp列以此判定擁有者）' AFTER task_id"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE personal_task_image ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT 'temp=未存檔暫存 active=正式' AFTER file_size"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE personal_task_image ADD COLUMN expire_at DATETIME NULL COMMENT 'temp 自動清除到期時間，NULL=不清' AFTER status"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE personal_task ADD COLUMN proposer VARCHAR(50) NULL COMMENT '提案人（自由輸入）' AFTER title"); } catch (Exception $e) {}

// ── 資料表升級（分享 + 多重綁定 2026-07-24）：沿用本模組慣例（sql.php 拒絕 DDL，改在 API 內建）─
// 多重綁定：一筆工作可綁多個對象（bom/part/customer/maker/order）
try {
    $db->exec("CREATE TABLE IF NOT EXISTS personal_task_bind (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL COMMENT 'FK→personal_task.id',
        bind_type VARCHAR(10) NOT NULL COMMENT 'bom/part/customer/maker/order',
        bind_id VARCHAR(100) NOT NULL,
        bind_label VARCHAR(200) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='個人工作多重綁定對象'");
} catch (Exception $e) {}
// 分享：view=唯讀看進度、edit=共同進度（協作者只能回報進度）
try {
    $db->exec("CREATE TABLE IF NOT EXISTS personal_task_share (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL COMMENT 'FK→personal_task.id',
        owner_id INT NOT NULL COMMENT '分享當下的擁有者 user.id',
        shared_with_user_id INT NOT NULL COMMENT '被分享者 user.id',
        mode VARCHAR(10) NOT NULL DEFAULT 'view' COMMENT 'view=唯讀 edit=共同進度',
        created_by INT NULL,
        created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_task_user (task_id, shared_with_user_id),
        INDEX idx_shared_with (shared_with_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='個人工作分享對象'");
} catch (Exception $e) {}
// 一次性遷移：把既有單欄綁定灌進 personal_task_bind（旗標防重跑；舊欄保留不刪）
try {
    $mig = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='ptask_bind_migrated'")->fetchColumn();
    if ($mig != '1') {
        $db->exec("INSERT INTO personal_task_bind (task_id, bind_type, bind_id, bind_label, sort_order)
                   SELECT id, bind_type, bind_id, bind_label, 0 FROM personal_task
                   WHERE bind_type IS NOT NULL AND bind_type <> '' AND bind_id IS NOT NULL AND bind_id <> ''");
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('ptask_bind_migrated','1')
                      ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    }
} catch (Exception $e) {}

// 全站二元權限：module='personal_task'（比照 BOM追蹤，不分CRUD）
if (!rf_has_module_role($db, $user_id, 'personal_task')) {
    echo json_encode(['success' => false, 'message' => '請先申請權限', 'no_access' => true]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '未知的 action: ' . $action];

// ── 共用小工具 ──────────────────────────────────────────────────────────

// datetime-local 的 "2026-07-20T14:00" → "2026-07-20 14:00:00"；空字串→NULL
function pt_norm_dt($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    if (strlen($v) === 16) $v .= ':00';
    return $v;
}

// 空字串→NULL 的整數
function pt_norm_int($v) {
    $v = trim((string)$v);
    return $v === '' ? null : (int)$v;
}

// 綁定顯示文字一律以 DB 當下資料為準（前端傳的 label 僅作查無資料時的備援）
function pt_resolve_bind_label(PDO $db, string $type, string $id): ?string {
    switch ($type) {
        case 'bom':
            $st = $db->prepare("SELECT bom FROM bom WHERE bom = ?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            return $v !== false ? $v : null;
        case 'part':
            $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id = ?");
            $st->execute([(int)$id]);
            $v = $st->fetchColumn();
            return $v !== false ? $v : null;
        case 'customer':
            $st = $db->prepare("SELECT customer FROM customer_list WHERE customer_id = ?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            return $v !== false ? $v : null;
        case 'maker':
            $st = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no = ?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            return $v !== false ? $v : null;
        case 'order':
            $st = $db->prepare("SELECT Order_oo, d_id FROM order_track WHERE Order_id = ?");
            $st->execute([(int)$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? ($r['Order_oo'] . '（' . $r['d_id'] . '）') : null;
    }
    return null;
}

// 取得本人的某筆紀錄（不存在或非本人 → null）
function pt_get_own_task(PDO $db, int $userId, int $taskId) {
    $st = $db->prepare("SELECT * FROM personal_task WHERE id = ? AND user_id = ?");
    $st->execute([$taskId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// 取得可存取的紀錄與存取層級：回傳 [紀錄陣列|null, 'owner'|'edit'|'view'|null]
//   owner = 本人擁有；edit = 被分享「共同進度」；view = 被分享「唯讀看進度」
function pt_get_accessible_task(PDO $db, int $userId, int $taskId): array {
    $st = $db->prepare("SELECT * FROM personal_task WHERE id = ?");
    $st->execute([$taskId]);
    $task = $st->fetch(PDO::FETCH_ASSOC);
    if (!$task) return [null, null];
    if ((int)$task['user_id'] === $userId) return [$task, 'owner'];
    $st = $db->prepare("SELECT mode FROM personal_task_share WHERE task_id = ? AND shared_with_user_id = ?");
    $st->execute([$taskId, $userId]);
    $mode = $st->fetchColumn();
    if ($mode === 'edit') return [$task, 'edit'];
    if ($mode === 'view') return [$task, 'view'];
    return [null, null];
}

// 可回報進度（owner 或 edit 協作者）→ 回傳紀錄，否則丟例外
function pt_require_progress_access(PDO $db, int $userId, int $taskId): array {
    list($task, $access) = pt_get_accessible_task($db, $userId, $taskId);
    if (!$task || !in_array($access, ['owner', 'edit'], true)) throw new Exception('找不到紀錄或無權限');
    return $task;
}

// 取多筆紀錄的綁定對象（task_id => 綁定陣列，依 sort_order）
function pt_task_binds(PDO $db, array $taskIds): array {
    $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
    if (!$taskIds) return [];
    $in = implode(',', $taskIds);
    $rows = $db->query("SELECT id, task_id, bind_type, bind_id, bind_label FROM personal_task_bind
                        WHERE task_id IN ({$in}) ORDER BY task_id, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) { $map[(int)$r['task_id']][] = $r; }
    return $map;
}

// 使用者顯示名（找不到回傳空字串）
function pt_user_name(PDO $db, int $userId): string {
    $st = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return $v !== false ? (string)$v : '';
}

// 單一 BOM 的逐關製程進度（唯讀，比照 bom_tracking get_matched_boms 口徑）。查無回 null。
function pt_bom_progress(PDO $db, string $bom): ?array {
    $st = $db->prepare("SELECT bom, d_id, Client_Name, sqty, processing_state, Delivery_date FROM bom WHERE bom = ?");
    $st->execute([$bom]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) return null;
    // 逐關節點（一個 bom_sn 一節點，排除 skip；含外包廠商 maker）
    $st = $db->prepare("SELECT bi.bom_sn, MAX(pn.ProcessName) AS name, MAX(bi.maker_id) AS maker,
            MAX(bi.outsource_date) AS outsource_date, MAX(bi.return_date) AS return_date
        FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        WHERE bi.bom = ? AND bi.processing_state != 'skip'
        GROUP BY bi.bom_sn ORDER BY bi.bom_sn");
    $st->execute([$bom]);
    $nodeRows = $st->fetchAll(PDO::FETCH_ASSOC);
    $processCount = count($nodeRows);
    $st = $db->prepare("SELECT COUNT(DISTINCT bi.bom_sn) FROM bom_ing bi
        WHERE bi.bom = ? AND bi.processing_state != 'skip' AND bi.bom_sn <= (
            SELECT bi2.bom_sn FROM bom_ing bi2 WHERE bi2.bom = ? AND bi2.processing_state != 'skip'
            ORDER BY GREATEST(COALESCE(bi2.outsource_date,'0000-00-00'), COALESCE(bi2.QC_check_date,'0000-00-00')) DESC, bi2.bom_sn DESC LIMIT 1
        )");
    $st->execute([$bom, $bom]);
    $currentStep = (int)$st->fetchColumn();
    $isClosed = ((string)$m['processing_state'] === '1');
    $progressPct = $isClosed ? 100 : ($processCount > 0 ? round($currentStep / $processCount * 100, 1) : null);
    $nodes = [];
    foreach ($nodeRows as $i => $n) {
        $rank = $i + 1;
        $nodes[] = [
            'bom_sn' => $n['bom_sn'], 'name' => $n['name'] ?: ('關卡' . $rank), 'maker' => $n['maker'],
            'outsource_date' => $n['outsource_date'], 'return_date' => $n['return_date'],
            'reached' => ($isClosed || $rank <= $currentStep) ? 1 : 0,
            'current' => (!$isClosed && $rank === $currentStep) ? 1 : 0,
        ];
    }
    $latestName = ($currentStep > 0 && isset($nodeRows[$currentStep - 1])) ? $nodeRows[$currentStep - 1]['name'] : null;
    return [
        'bom' => $bom, 'd_id' => $m['d_id'], 'Client_Name' => $m['Client_Name'], 'sqty' => $m['sqty'],
        'processing_state' => $m['processing_state'], 'is_closed' => $isClosed ? 1 : 0,
        'process_count' => $processCount, 'current_step' => $currentStep,
        'progress_pct' => $progressPct, 'latest_process_name' => $isClosed ? '結案' : $latestName,
        'delivery_date' => $m['Delivery_date'], 'nodes' => $nodes,
    ];
}

// 把小項掛到步驟陣列上（$steps 需含 id 欄；以參考傳遞直接改寫）
function pt_attach_step_items(PDO $db, array &$steps): void {
    if (!$steps) return;
    $sin = implode(',', array_map(function ($s) { return (int)$s['id']; }, $steps));
    $items = $db->query("SELECT * FROM personal_task_step_item WHERE step_id IN ({$sin})
                         ORDER BY step_id, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $byStep = [];
    foreach ($items as $it) { $byStep[$it['step_id']][] = $it; }
    foreach ($steps as &$s) { $s['items'] = $byStep[$s['id']] ?? []; }
    unset($s);
}

// 個人急件預設天數
function pt_get_urgent_default(PDO $db, int $userId): int {
    $st = $db->prepare("SELECT urgent_days FROM personal_task_setting WHERE user_id = ?");
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return $v !== false ? (int)$v : 3;
}

// 是否為系統管理員（RBAC roles.is_system=1；附件路徑為全系統設定，僅管理員可改）
function pt_is_admin(PDO $db, int $userId): bool {
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur
                            JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.is_system = 1 LIMIT 1");
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}

// 附件路徑設定：DB 只存目錄設定值與檔名，完整路徑一律讀取當下組出（鐵律5）
// 回傳 [NAS實體路徑(寫檔用), URL前綴(前端顯示用)]，皆保證以 / 結尾
function pt_attach_dirs(PDO $db): array {
    $nas = 'Z:/BOM/ERP/個人工作/';
    // 附圖改走讀檔 API（不再用 Apache /nas 別名讓瀏覽器直連）：
    // 位置只由 ptask_nas_dir 決定，換 NAS 免改 httpd.conf、也不綁磁碟機代號；
    // 而且個人工作紀錄「僅本人可見」，走 API 才擋得住別人直接用網址看別人的附圖。
    $url = '../../src/store/PtaskImage_API.php?f=';
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM system_settings
                            WHERE setting_key IN ('ptask_nas_dir')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($rows['ptask_nas_dir'])) $nas = trim($rows['ptask_nas_dir']);
    } catch (Exception $e) {}
    if (!preg_match('#[/\\\\]$#', $nas)) $nas .= '/';
    return [$nas, $url];
}

// 取多筆紀錄的附圖（task_id => 附圖陣列，url 即時組出；只取正式 active）
function pt_task_images(PDO $db, array $taskIds, string $urlDir): array {
    $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
    if (!$taskIds) return [];
    $in = implode(',', $taskIds);
    $rows = $db->query("SELECT img_id, task_id, file_name, original_name FROM personal_task_image
                        WHERE task_id IN ({$in}) AND status = 'active'
                        ORDER BY task_id, sort_order, img_id")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $r['url'] = $urlDir . $r['file_name'];
        $map[(int)$r['task_id']][] = $r;
    }
    return $map;
}

// 懶惰清除：永久刪除已到期的暫存(temp)附圖（實體檔＋DB列）。list_tasks 順路呼叫。
function pt_purge_expired_temp_images(PDO $db): void {
    try {
        $rows = $db->query("SELECT img_id, file_name FROM personal_task_image
                            WHERE status = 'temp' AND expire_at IS NOT NULL AND expire_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        list($nasDir) = pt_attach_dirs($db);
        foreach ($rows as $r) {
            $fp = $nasDir . $r['file_name'];
            if (is_file($fp)) @unlink($fp);
        }
        $in = implode(',', array_map(function ($r) { return (int)$r['img_id']; }, $rows));
        $db->exec("DELETE FROM personal_task_image WHERE img_id IN ({$in})");
    } catch (Exception $e) {}
}

try {
    // ══ 個人設定 ══════════════════════════════════════════════════════
    if ($action === 'get_settings') {
        $isAdmin = pt_is_admin($db, $user_id);
        $resp = ['success' => true, 'urgent_days' => pt_get_urgent_default($db, $user_id), 'is_admin' => $isAdmin];
        if ($isAdmin) {
            list($nasDir, $urlDir) = pt_attach_dirs($db);
            $resp['attach_nas_dir'] = $nasDir;
            $resp['attach_url_dir'] = $urlDir;
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══ 附件儲存路徑（全系統設定，僅管理員）════════════════════════════
    if ($action === 'save_attach_path') {
        if (!pt_is_admin($db, $user_id)) throw new Exception('僅管理員可修改附件儲存路徑');
        $nasDir = trim((string)($_POST['nas_dir'] ?? ''));
        if ($nasDir === '') throw new Exception('存放位置不可為空');
        // 存檔前先確認讀得到，設錯當場擋下，不用等使用者發現附圖全破圖才知道
        if (!is_dir(rtrim($nasDir, '/\\'))) throw new Exception('這個位置讀不到，請確認路徑正確且伺服器有權限存取：' . $nasDir);
        $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute(['ptask_nas_dir', $nasDir]);
        // ptask_url_dir 不再寫入（附圖已改走讀檔 API）；舊值保留在 system_settings 供回退
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save_settings') {
        $days = max(0, (int)($_POST['urgent_days'] ?? 3));
        $st = $db->prepare("INSERT INTO personal_task_setting (user_id, urgent_days) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE urgent_days = VALUES(urgent_days)");
        $st->execute([$user_id, $days]);
        echo json_encode(['success' => true, 'urgent_days' => $days]);
        exit;
    }

    // ══ 紀錄列表（後端分頁；統計卡數字對全部符合條件資料計算）══════════
    if ($action === 'list_tasks') {
        $status = $_POST['status'] ?? $_GET['status'] ?? '0';           // 0/1/2
        $urgentOnly = (int)($_POST['urgent_only'] ?? $_GET['urgent_only'] ?? 0);
        $sharedOnly = (int)($_POST['shared_only'] ?? $_GET['shared_only'] ?? 0);  // 只看他人分享給我
        $kw = trim((string)($_POST['kw'] ?? $_GET['kw'] ?? ''));
        $page = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
        $pageSize = (int)($_POST['pageSize'] ?? $_GET['pageSize'] ?? 10);
        if (!in_array($pageSize, [5, 10, 20, 50], true)) $pageSize = 10;
        $exportAll = (int)($_POST['export'] ?? $_GET['export'] ?? 0);   // 匯出時抓全量(仍套目前篩選)

        pt_purge_expired_temp_images($db);   // 順路清除過期的暫存附圖

        $defDays = pt_get_urgent_default($db, $user_id);
        // 急件判定：未完成 + 有期限 + 已進入「期限前N天」(N=每筆自訂，未設則用個人預設)。
        // 期限只記日期(00:00)，故一律用日期比較：到期日當天整天算急件、隔天才算逾期
        $urgentExpr = "(t.status = 0 AND t.deadline IS NOT NULL
                        AND CURDATE() >= DATE_SUB(DATE(t.deadline), INTERVAL COALESCE(t.urgent_days, {$defDays}) DAY))";

        // 可存取範圍：自己擁有 ∪ 別人分享給我（分享是本模組刻意開的例外）
        $accessSql = "(t.user_id = ? OR EXISTS(SELECT 1 FROM personal_task_share sh
                        WHERE sh.task_id = t.id AND sh.shared_with_user_id = ?))";
        $where = $accessSql;
        $params = [$user_id, $user_id];
        if ($kw !== '') {
            $where .= " AND (t.title LIKE ? OR t.note LIKE ?
                        OR EXISTS(SELECT 1 FROM personal_task_bind b WHERE b.task_id = t.id AND b.bind_label LIKE ?))";
            $like = '%' . $kw . '%';
            array_push($params, $like, $like, $like);
        }

        // 統計卡：對全部可存取且符合關鍵字的資料計算（不受狀態/分頁影響）
        // cnt_shared＝別人分享給我的筆數（不含自己擁有）
        $st = $db->prepare("SELECT
                COALESCE(SUM(t.status = 0), 0) AS cnt_open,
                COALESCE(SUM(t.status = 1), 0) AS cnt_done,
                COALESCE(SUM(t.status = 2), 0) AS cnt_paused,
                COALESCE(SUM({$urgentExpr}), 0) AS cnt_urgent,
                COALESCE(SUM(t.user_id <> ?), 0) AS cnt_shared
            FROM personal_task t WHERE {$where}");
        $st->execute(array_merge([$user_id], $params));
        $counts = $st->fetch(PDO::FETCH_ASSOC);

        // 列表條件
        $listWhere = $where;
        $listParams = $params;
        if ($sharedOnly) {
            $listWhere .= " AND t.user_id <> ?";          // 只看他人分享給我（不含自己擁有）
            $listParams[] = $user_id;
        } elseif ($urgentOnly) {
            $listWhere .= " AND {$urgentExpr}";
        } elseif (in_array($status, ['0', '1', '2'], true)) {
            $listWhere .= " AND t.status = " . (int)$status;
        }

        $st = $db->prepare("SELECT COUNT(*) FROM personal_task t WHERE {$listWhere}");
        $st->execute($listParams);
        $total = (int)$st->fetchColumn();

        // 排序：未完成/急件→期限近的在前(無期限最後)；已完成→完成時間新的在前；暫停→最近異動在前
        if ($status === '1' && !$urgentOnly) {
            $orderBy = "t.completed_at DESC, t.id DESC";
        } elseif ($status === '2' && !$urgentOnly) {
            $orderBy = "t.updated_at DESC, t.id DESC";
        } else {
            $orderBy = "(t.deadline IS NULL) ASC, t.deadline ASC, t.id DESC";
        }

        $limitSql = $exportAll ? "" : " LIMIT " . (($page - 1) * $pageSize) . ", " . $pageSize;
        // is_shared/share_mode/owner_name：$user_id 為 (int) session 值，直接內嵌安全（避免 SELECT 子句參數排序糾結）
        $uid = (int)$user_id;
        $st = $db->prepare("SELECT t.*, {$urgentExpr} AS is_urgent,
                (t.status = 0 AND t.deadline IS NOT NULL AND CURDATE() > DATE(t.deadline)) AS is_overdue,
                (t.user_id <> {$uid}) AS is_shared,
                (SELECT sh.mode FROM personal_task_share sh
                   WHERE sh.task_id = t.id AND sh.shared_with_user_id = {$uid}) AS share_mode,
                u.user_cname AS owner_name
            FROM personal_task t LEFT JOIN user u ON u.id = t.user_id
            WHERE {$listWhere} ORDER BY {$orderBy}{$limitSql}");
        $st->execute($listParams);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // 帶入各筆的進度步驟與小項（依排序）＋附圖
        if ($rows) {
            $ids = array_column($rows, 'id');
            $in = implode(',', array_map('intval', $ids));
            $steps = $db->query("SELECT * FROM personal_task_step WHERE task_id IN ({$in})
                                 ORDER BY task_id, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
            pt_attach_step_items($db, $steps);
            $byTask = [];
            foreach ($steps as $s) { $byTask[$s['task_id']][] = $s; }
            list(, $urlDir) = pt_attach_dirs($db);
            $imgMap = pt_task_images($db, $ids, $urlDir);
            $bindMap = pt_task_binds($db, $ids);
            foreach ($rows as &$r) {
                $r['steps'] = $byTask[$r['id']] ?? [];
                $r['images'] = $imgMap[(int)$r['id']] ?? [];
                $r['binds'] = $bindMap[(int)$r['id']] ?? [];
            }
            unset($r);
        }

        echo json_encode(['success' => true, 'data' => $rows, 'total' => $total,
            'counts' => $counts, 'urgent_default' => $defDays], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══ 單筆讀取（編輯用）══════════════════════════════════════════════
    if ($action === 'get_task') {
        list($task, $access) = pt_get_accessible_task($db, $user_id, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
        if (!$task) throw new Exception('找不到紀錄或無權限');
        $st = $db->prepare("SELECT * FROM personal_task_step WHERE task_id = ? ORDER BY sort_order, id");
        $st->execute([$task['id']]);
        $steps = $st->fetchAll(PDO::FETCH_ASSOC);
        pt_attach_step_items($db, $steps);
        $task['steps'] = $steps;
        $task['binds'] = pt_task_binds($db, [$task['id']])[(int)$task['id']] ?? [];
        $task['access'] = $access;
        $task['owner_name'] = pt_user_name($db, (int)$task['user_id']);
        list(, $urlDir) = pt_attach_dirs($db);
        $imgMap = pt_task_images($db, [$task['id']], $urlDir);
        $task['images'] = $imgMap[(int)$task['id']] ?? [];
        echo json_encode(['success' => true, 'data' => $task], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══ 新增/修改（含進度步驟同步，交易保護）══════════════════════════
    if ($action === 'save_task') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') throw new Exception('請輸入標題');

        $receivedAt = trim((string)($_POST['received_at'] ?? ''));
        if ($receivedAt === '') $receivedAt = date('Y-m-d');

        // 多重綁定：前端傳 binds JSON 陣列 [{bind_type,bind_id,bind_label}...]（相容舊單欄）
        $bindsIn = json_decode($_POST['binds'] ?? '', true);
        if (!is_array($bindsIn)) {
            // 相容舊單欄格式
            $bt = trim((string)($_POST['bind_type'] ?? ''));
            $bi = trim((string)($_POST['bind_id'] ?? ''));
            $bindsIn = ($bt !== '' && $bi !== '') ? [['bind_type' => $bt, 'bind_id' => $bi]] : [];
        }
        $binds = [];  // 驗證後的綁定（label 一律以 DB 當下為準）
        $seenBind = [];
        foreach ($bindsIn as $b) {
            $bt = trim((string)($b['bind_type'] ?? ''));
            $bi = trim((string)($b['bind_id'] ?? ''));
            if ($bt === '' || $bi === '') continue;
            if (!in_array($bt, ['bom', 'part', 'customer', 'maker', 'order'], true)) throw new Exception('綁定類型不正確');
            $key = $bt . '|' . $bi;
            if (isset($seenBind[$key])) continue;             // 去重
            $seenBind[$key] = 1;
            $label = pt_resolve_bind_label($db, $bt, $bi);
            if ($label === null || $label === '') throw new Exception('查無綁定對象，請重新搜尋選擇');
            $binds[] = ['bind_type' => $bt, 'bind_id' => $bi, 'bind_label' => $label];
        }
        // 舊單欄寫入第一筆（保留相容：提醒發送等仍讀 personal_task.bind_label）
        $bindType  = $binds ? $binds[0]['bind_type']  : null;
        $bindId    = $binds ? $binds[0]['bind_id']    : null;
        $bindLabel = $binds ? $binds[0]['bind_label'] : null;

        $deadline = pt_norm_dt($_POST['deadline'] ?? '');
        $remindMin = pt_norm_int($_POST['remind_before_minutes'] ?? '');
        if ($remindMin !== null && $deadline === null) throw new Exception('要設定提醒須先設定期限');
        $urgentDays = pt_norm_int($_POST['urgent_days'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));
        $proposer = trim((string)($_POST['proposer'] ?? ''));

        $stepsIn = json_decode($_POST['steps'] ?? '[]', true);
        if (!is_array($stepsIn)) $stepsIn = [];

        $db->beginTransaction();
        try {
            if ($id > 0) {
                $old = pt_get_own_task($db, $user_id, $id);
                if (!$old) throw new Exception('找不到紀錄或無權限');
                // 期限或提醒設定變更 → 重置已發送旗標，讓提醒依新設定重新生效
                $remindReset = ($old['deadline'] !== $deadline
                    || (string)$old['remind_before_minutes'] !== (string)$remindMin);
                $st = $db->prepare("UPDATE personal_task SET title=?, proposer=?, bind_type=?, bind_id=?, bind_label=?,
                        received_at=?, deadline=?, remind_before_minutes=?, urgent_days=?, note=?" .
                        ($remindReset ? ", remind_sent=0" : "") . " WHERE id=? AND user_id=?");
                $st->execute([$title, $proposer, $bindType, $bindId, $bindLabel, $receivedAt, $deadline,
                    $remindMin, $urgentDays, $note, $id, $user_id]);
            } else {
                $st = $db->prepare("INSERT INTO personal_task
                        (user_id, title, proposer, bind_type, bind_id, bind_label, received_at, deadline,
                         remind_before_minutes, urgent_days, note)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$user_id, $title, $proposer, $bindType, $bindId, $bindLabel, $receivedAt,
                    $deadline, $remindMin, $urgentDays, $note]);
                $id = (int)$db->lastInsertId();
            }

            // ── 多重綁定同步：整批覆蓋（先刪後插，依前端順序寫 sort_order）──
            $db->prepare("DELETE FROM personal_task_bind WHERE task_id = ?")->execute([$id]);
            if ($binds) {
                $insB = $db->prepare("INSERT INTO personal_task_bind (task_id, bind_type, bind_id, bind_label, sort_order)
                                      VALUES (?,?,?,?,?)");
                foreach ($binds as $i => $b) {
                    $insB->execute([$id, $b['bind_type'], $b['bind_id'], $b['bind_label'], $i]);
                }
            }

            // ── 進度步驟同步：前端傳完整清單(含既有id)，依陣列順序寫 sort_order ──
            $st = $db->prepare("SELECT id, planned_at, remind_before_minutes, reached_at
                                FROM personal_task_step WHERE task_id = ?");
            $st->execute([$id]);
            $oldSteps = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) { $oldSteps[(int)$s['id']] = $s; }

            // 小項同步（同一步驟內：保留既有id更新名稱/順序，其餘新增，未保留者刪除；done_at 不在此動）
            $syncItems = function (int $stepId, $itemsIn) use ($db) {
                if (!is_array($itemsIn)) $itemsIn = [];
                $st = $db->prepare("SELECT id FROM personal_task_step_item WHERE step_id = ?");
                $st->execute([$stepId]);
                $oldIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                $keep = [];
                $ins = $db->prepare("INSERT INTO personal_task_step_item (step_id, item_name, sort_order) VALUES (?,?,?)");
                $upd = $db->prepare("UPDATE personal_task_step_item SET item_name=?, sort_order=? WHERE id=? AND step_id=?");
                $i = 0;
                foreach ($itemsIn as $it) {
                    $name = trim((string)($it['name'] ?? ''));
                    if ($name === '') continue;
                    $iid = (int)($it['id'] ?? 0);
                    if ($iid > 0 && in_array($iid, $oldIds, true)) { $keep[] = $iid; $upd->execute([$name, $i, $iid, $stepId]); }
                    else { $ins->execute([$stepId, $name, $i]); }
                    $i++;
                }
                $del = array_diff($oldIds, $keep);
                if ($del) {
                    $db->exec("DELETE FROM personal_task_step_item WHERE step_id = {$stepId}
                               AND id IN (" . implode(',', $del) . ")");
                }
            };

            $keepIds = [];
            $insSt = $db->prepare("INSERT INTO personal_task_step
                    (task_id, step_name, sort_order, planned_at, remind_before_minutes) VALUES (?,?,?,?,?)");
            foreach ($stepsIn as $i => $s) {
                $name = trim((string)($s['name'] ?? ''));
                if ($name === '') continue;
                $plannedAt = pt_norm_dt($s['planned_at'] ?? '');
                $sRemind = pt_norm_int($s['remind_before_minutes'] ?? '');
                if ($sRemind !== null && $plannedAt === null) throw new Exception('進度「' . $name . '」要設定提醒須先設定預定日期');
                $sid = (int)($s['id'] ?? 0);
                if ($sid > 0 && isset($oldSteps[$sid])) {
                    $keepIds[] = $sid;
                    $o = $oldSteps[$sid];
                    $remindReset = ($o['planned_at'] !== $plannedAt
                        || (string)$o['remind_before_minutes'] !== (string)$sRemind);
                    $st = $db->prepare("UPDATE personal_task_step SET step_name=?, sort_order=?, planned_at=?,
                            remind_before_minutes=?" . ($remindReset ? ", remind_sent=0" : "") . " WHERE id=? AND task_id=?");
                    $st->execute([$name, $i, $plannedAt, $sRemind, $sid, $id]);
                    $syncItems($sid, $s['items'] ?? []);
                } else {
                    $insSt->execute([$id, $name, $i, $plannedAt, $sRemind]);
                    $syncItems((int)$db->lastInsertId(), $s['items'] ?? []);
                }
            }
            // 刪除此次未保留的既有步驟（小項連動刪除）
            $delIds = array_diff(array_keys($oldSteps), $keepIds);
            if ($delIds) {
                $delIn = implode(',', array_map('intval', $delIds));
                $db->exec("DELETE FROM personal_task_step_item WHERE step_id IN ({$delIn})");
                $db->exec("DELETE FROM personal_task_step WHERE task_id = " . $id . " AND id IN ({$delIn})");
            }

            // 順序完整性：已到達的步驟必須全部排在未到達步驟之前（進度須依序進行）
            $st = $db->prepare("SELECT reached_at FROM personal_task_step WHERE task_id = ? ORDER BY sort_order, id");
            $st->execute([$id]);
            $seenUnreached = false;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $reachedAt) {
                if ($reachedAt === null) { $seenUnreached = true; }
                elseif ($seenUnreached) { throw new Exception('已到達的進度必須排在未到達的進度之前，請調整順序'); }
            }

            // 存檔前上傳的暫存附圖：綁定到本紀錄並轉正式（僅限本人上傳的 temp 列）
            $tempIds = json_decode($_POST['temp_img_ids'] ?? '[]', true);
            if (is_array($tempIds)) {
                $tempIds = array_values(array_filter(array_map('intval', $tempIds)));
                if ($tempIds) {
                    $in = implode(',', $tempIds);
                    $db->prepare("UPDATE personal_task_image SET task_id = ?, status = 'active', expire_at = NULL
                                  WHERE img_id IN ({$in}) AND user_id = ? AND status = 'temp'")
                       ->execute([$id, $user_id]);
                }
            }

            $db->commit();
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        exit;
    }

    // ══ 刪除 ══════════════════════════════════════════════════════════
    if ($action === 'delete_task') {
        $id = (int)($_POST['id'] ?? 0);
        if (!pt_get_own_task($db, $user_id, $id)) throw new Exception('找不到紀錄或無權限');
        // 附圖實體檔先取出檔名，DB 交易成功後才刪檔（刪檔失敗不影響資料一致性）
        $st = $db->prepare("SELECT file_name FROM personal_task_image WHERE task_id = ?");
        $st->execute([$id]);
        $imgFiles = $st->fetchAll(PDO::FETCH_COLUMN);
        $db->beginTransaction();
        try {
            $db->prepare("DELETE i FROM personal_task_step_item i
                          JOIN personal_task_step s ON s.id = i.step_id
                          WHERE s.task_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM personal_task_step WHERE task_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM personal_task_image WHERE task_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM personal_task_bind WHERE task_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM personal_task_share WHERE task_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM personal_task WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if ($imgFiles) {
            list($nasDir) = pt_attach_dirs($db);
            foreach ($imgFiles as $fn) {
                $fp = $nasDir . $fn;
                if (is_file($fp)) @unlink($fp);
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ══ 附件圖片（僅本人的紀錄可操作；縮圖/點擊邏輯比照 Sales_Track）══
    if ($action === 'list_task_images') {
        $taskId = (int)($_POST['task_id'] ?? $_GET['task_id'] ?? 0);
        if (!pt_get_own_task($db, $user_id, $taskId)) throw new Exception('找不到紀錄或無權限');
        list(, $urlDir) = pt_attach_dirs($db);
        $imgMap = pt_task_images($db, [$taskId], $urlDir);
        echo json_encode(['success' => true, 'data' => $imgMap[$taskId] ?? []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upload_task_image') {
        // task_id=0＝新增紀錄尚未存檔：先存暫存(temp，2天到期)，save_task 帶 temp_img_ids 轉正式
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId > 0 && !pt_get_own_task($db, $user_id, $taskId)) throw new Exception('找不到紀錄或無權限');
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) throw new Exception('上傳失敗');
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $orig = basename($_FILES['image']['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) throw new Exception('僅支援圖片格式（jpg/png/gif/webp/bmp）');
        list($nasDir, $urlDir) = pt_attach_dirs($db);
        if (!is_dir($nasDir) && !mkdir($nasDir, 0777, true)) throw new Exception('無法建立附件目錄，請確認路徑設定：' . $nasDir);
        $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $nasDir . $fname)) throw new Exception('檔案寫入失敗');
        if ($taskId > 0) {
            $db->prepare("INSERT INTO personal_task_image (task_id, user_id, file_name, original_name, file_size, status)
                          VALUES (?,?,?,?,?,'active')")
               ->execute([$taskId, $user_id, $fname, $orig, (int)$_FILES['image']['size']]);
        } else {
            $db->prepare("INSERT INTO personal_task_image (task_id, user_id, file_name, original_name, file_size, status, expire_at)
                          VALUES (0,?,?,?,?,'temp', DATE_ADD(NOW(), INTERVAL 2 DAY))")
               ->execute([$user_id, $fname, $orig, (int)$_FILES['image']['size']]);
        }
        echo json_encode(['success' => true, 'img_id' => (int)$db->lastInsertId(),
            'file_name' => $fname, 'original_name' => $orig, 'url' => $urlDir . $fname], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_task_image') {
        // 可刪：自己紀錄上的正式附圖，或自己上傳、尚未綁定的暫存附圖
        $imgId = (int)($_POST['img_id'] ?? 0);
        $st = $db->prepare("SELECT i.file_name FROM personal_task_image i
                            LEFT JOIN personal_task t ON t.id = i.task_id
                            WHERE i.img_id = ? AND (t.user_id = ? OR (i.status = 'temp' AND i.user_id = ?))");
        $st->execute([$imgId, $user_id, $user_id]);
        $fn = $st->fetchColumn();
        if ($fn === false) throw new Exception('找不到附圖或無權限');
        $db->prepare("DELETE FROM personal_task_image WHERE img_id = ?")->execute([$imgId]);
        list($nasDir) = pt_attach_dirs($db);
        $fp = $nasDir . $fn;
        if (is_file($fp)) @unlink($fp);
        echo json_encode(['success' => true]);
        exit;
    }

    // ══ 狀態切換（0=未完成 1=已完成 2=暫停）════════════════════════════
    if ($action === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = (int)($_POST['status'] ?? -1);
        if (!in_array($status, [0, 1, 2], true)) throw new Exception('狀態值不正確');
        pt_require_progress_access($db, $user_id, $id);   // owner 或共同進度協作者可改狀態
        if ($status === 1) {
            $st = $db->prepare("UPDATE personal_task SET status = 1, completed_at = NOW() WHERE id = ? AND user_id = ?");
        } elseif ($status === 0) {
            $st = $db->prepare("UPDATE personal_task SET status = 0, completed_at = NULL WHERE id = ? AND user_id = ?");
        } else {
            $st = $db->prepare("UPDATE personal_task SET status = 2 WHERE id = ? AND user_id = ?");
        }
        $st->execute([$id, $user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ══ 進度回報：點選到達（必須依序，不可跳過）════════════════════════
    if ($action === 'reach_step') {
        $stepId = (int)($_POST['step_id'] ?? 0);
        $st = $db->prepare("SELECT id, task_id FROM personal_task_step WHERE id = ?");
        $st->execute([$stepId]);
        $step = $st->fetch(PDO::FETCH_ASSOC);
        if (!$step) throw new Exception('找不到進度');
        pt_require_progress_access($db, $user_id, (int)$step['task_id']);  // owner 或共同進度協作者

        // 依排序找出第一個尚未到達的步驟，只有它可以被回報（防跳關）
        $st = $db->prepare("SELECT id FROM personal_task_step
                            WHERE task_id = ? AND reached_at IS NULL ORDER BY sort_order, id LIMIT 1");
        $st->execute([$step['task_id']]);
        $firstUnreached = (int)$st->fetchColumn();
        if ($firstUnreached !== $stepId) throw new Exception('進度須依順序回報，不可跳過未到達的進度');

        $db->prepare("UPDATE personal_task_step SET reached_at = NOW() WHERE id = ?")->execute([$stepId]);
        $st = $db->prepare("SELECT reached_at FROM personal_task_step WHERE id = ?");
        $st->execute([$stepId]);
        echo json_encode(['success' => true, 'reached_at' => $st->fetchColumn()]);
        exit;
    }

    // ══ 小項勾選/取消（自由勾選，不受步驟順序限制）══════════════════════
    if ($action === 'toggle_step_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $done = (int)($_POST['done'] ?? 0) ? 1 : 0;
        $st = $db->prepare("SELECT s.task_id FROM personal_task_step_item i
                            JOIN personal_task_step s ON s.id = i.step_id
                            WHERE i.id = ?");
        $st->execute([$itemId]);
        $itemTaskId = $st->fetchColumn();
        if ($itemTaskId === false) throw new Exception('找不到小項');
        pt_require_progress_access($db, $user_id, (int)$itemTaskId);  // owner 或共同進度協作者
        $db->prepare("UPDATE personal_task_step_item SET done_at = " . ($done ? "NOW()" : "NULL") . " WHERE id = ?")
           ->execute([$itemId]);
        $st = $db->prepare("SELECT done_at FROM personal_task_step_item WHERE id = ?");
        $st->execute([$itemId]);
        echo json_encode(['success' => true, 'done_at' => $st->fetchColumn()]);
        exit;
    }

    // ══ 進度回報復原：只能取消「最後一個已到達」的步驟 ══════════════════
    if ($action === 'unreach_step') {
        $stepId = (int)($_POST['step_id'] ?? 0);
        $st = $db->prepare("SELECT id, task_id FROM personal_task_step WHERE id = ?");
        $st->execute([$stepId]);
        $step = $st->fetch(PDO::FETCH_ASSOC);
        if (!$step) throw new Exception('找不到進度');
        pt_require_progress_access($db, $user_id, (int)$step['task_id']);  // owner 或共同進度協作者

        $st = $db->prepare("SELECT id FROM personal_task_step
                            WHERE task_id = ? AND reached_at IS NOT NULL ORDER BY sort_order DESC, id DESC LIMIT 1");
        $st->execute([$step['task_id']]);
        $lastReached = (int)$st->fetchColumn();
        if ($lastReached !== $stepId) throw new Exception('只能取消最後一個已到達的進度');

        $db->prepare("UPDATE personal_task_step SET reached_at = NULL WHERE id = ?")->execute([$stepId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ══ 流程範本 ══════════════════════════════════════════════════════
    if ($action === 'list_templates') {
        $st = $db->prepare("SELECT id, template_name, steps_json FROM personal_task_template
                            WHERE user_id = ? ORDER BY template_name");
        $st->execute([$user_id]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_template') {
        $name = trim((string)($_POST['template_name'] ?? ''));
        if ($name === '') throw new Exception('請輸入範本名稱');
        $steps = json_decode($_POST['steps_json'] ?? '[]', true);
        if (!is_array($steps)) $steps = [];
        $steps = array_values(array_filter(array_map(function ($s) { return trim((string)$s); }, $steps),
            function ($s) { return $s !== ''; }));
        if (!$steps) throw new Exception('範本至少要有一個進度名稱');
        // 同名範本直接覆蓋（同一人）
        $st = $db->prepare("SELECT id FROM personal_task_template WHERE user_id = ? AND template_name = ?");
        $st->execute([$user_id, $name]);
        $exist = (int)$st->fetchColumn();
        if ($exist) {
            $db->prepare("UPDATE personal_task_template SET steps_json = ? WHERE id = ?")
               ->execute([json_encode($steps, JSON_UNESCAPED_UNICODE), $exist]);
        } else {
            $db->prepare("INSERT INTO personal_task_template (user_id, template_name, steps_json) VALUES (?,?,?)")
               ->execute([$user_id, $name, json_encode($steps, JSON_UNESCAPED_UNICODE)]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_template') {
        $db->prepare("DELETE FROM personal_task_template WHERE id = ? AND user_id = ?")
           ->execute([(int)($_POST['id'] ?? 0), $user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ══ 綁定搜尋（比照 BomTrack_API / store_CAR_API 慣例，各回傳前20筆）══
    $kw = trim((string)($_POST['kw'] ?? $_GET['kw'] ?? ''));
    $like = '%' . $kw . '%';

    if ($action === 'search_boms') {
        // 可用 BOM號 / 料號(d_id) / 客戶(Client_Name) 模糊搜尋；建議清單顯示料號、客戶、數量
        $st = $db->prepare("SELECT bom, d_id, Client_Name, sqty, specification FROM bom
                            WHERE bom LIKE ? OR d_id LIKE ? OR Client_Name LIKE ?
                            ORDER BY bom DESC LIMIT 20");
        $st->execute([$like, $like, $like]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'search_parts') {
        $st = $db->prepare("SELECT d_id, D_Setting_Id, Drawing_No FROM d_setting
                            WHERE D_Setting_Id LIKE ? OR Drawing_No LIKE ? LIMIT 20");
        $st->execute([$like, $like]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'search_customers') {
        $st = $db->prepare("SELECT customer_id, customer FROM customer_list
                            WHERE (customer LIKE ? OR customer_id LIKE ?) AND is_inactive = 0
                            ORDER BY customer LIMIT 20");
        $st->execute([$like, $like]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'search_orders') {
        // 綁定訂單追蹤(NewOrder_Track)內資料：以料號關鍵字搜尋訂單（也支援直接輸入訂單編號）
        $st = $db->prepare("SELECT Order_id, Order_oo, d_id, Client_name, Delivery_date, Qty, Order_status
                            FROM order_track
                            WHERE (d_id LIKE ? OR Order_oo LIKE ?)
                            ORDER BY Order_id DESC LIMIT 20");
        $st->execute([$like, $like]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══ 工作天資料（比照 views/pages/calendar.php 的判定：補班日('m')算上班，
    //     週末與休假日('s')不算；供前端「進度間隔N工作天」自動推算日期）══════════
    if ($action === 'get_workday_data') {
        $st = $db->query("SELECT e.start, e.end, e.recurrence_type, e.recurrence_count, ec.day_type
                          FROM evenement e
                          JOIN event_category ec ON e.category_id = ec.id
                          WHERE ec.day_type IN ('s','m')");
        $holidays = []; $makeup = [];
        // 只回傳近一年～未來兩年的日期，避免資料量無限成長
        $winFrom = new DateTime('-370 days'); $winTo = new DateTime('+740 days');
        $addRange = function ($startStr, $endStr, $dayType) use (&$holidays, &$makeup, $winFrom, $winTo) {
            try {
                $cur = new DateTime(substr($startStr, 0, 10));
                $end = $endStr ? new DateTime(substr($endStr, 0, 10)) : clone $cur;
            } catch (Exception $e) { return; }
            $guard = 0;
            while ($cur <= $end && $guard++ < 400) {
                if ($cur >= $winFrom && $cur <= $winTo) {
                    $d = $cur->format('Y-m-d');
                    if ($dayType === 's') $holidays[$d] = 1; else $makeup[$d] = 1;
                }
                $cur->modify('+1 day');
            }
        };
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            $addRange($ev['start'], $ev['end'], $ev['day_type']);
            // 重複事件比照 events.php 展開（daily/weekly/monthly/yearly × recurrence_count）
            $intervalMap = ['daily' => 'P1D', 'weekly' => 'P1W', 'monthly' => 'P1M', 'yearly' => 'P1Y'];
            $cnt = (int)($ev['recurrence_count'] ?? 0);
            if ($cnt > 0 && isset($intervalMap[$ev['recurrence_type']])) {
                try {
                    $iv = new DateInterval($intervalMap[$ev['recurrence_type']]);
                    $s = new DateTime(substr($ev['start'], 0, 10));
                    $e = $ev['end'] ? new DateTime(substr($ev['end'], 0, 10)) : clone $s;
                    for ($i = 0; $i < min($cnt, 400); $i++) {
                        $s->add($iv); $e->add($iv);
                        $addRange($s->format('Y-m-d'), $e->format('Y-m-d'), $ev['day_type']);
                    }
                } catch (Exception $e2) {}
            }
        }
        echo json_encode(['success' => true,
            'holidays' => array_keys($holidays), 'makeup' => array_keys($makeup)]);
        exit;
    }

    if ($action === 'search_makers') {
        $st = $db->prepare("SELECT maker_id_no, maker_id, maker_id_all FROM maker_list
                            WHERE (maker_id LIKE ? OR maker_id_all LIKE ? OR maker_id_no LIKE ?)
                              AND (status IS NULL OR status <> 'X')
                            ORDER BY maker_id LIMIT 20");
        $st->execute([$like, $like, $like]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══ 綁定 BOM 的實際製程進度（唯讀，比照 bom_tracking 由 bom_ing 逐關即時算）══
    if ($action === 'get_bom_progress') {
        $bomsRaw = $_POST['boms'] ?? $_GET['boms'] ?? ($_POST['bom'] ?? $_GET['bom'] ?? '');
        $boms = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$bomsRaw)))));
        $out = [];
        foreach ($boms as $bom) {
            $bd = pt_bom_progress($db, $bom);
            if ($bd) $out[$bom] = $bd;
        }
        echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══ 綁定訂單的生命週期狀態（設計批圖→轉生管→生管綁定BOM→BOM製程；唯讀）══
    //   階段判定比照 views/Sales/NewOrder_Track222.php（pmGet 為批圖/生管分水嶺）
    if ($action === 'get_order_progress') {
        $raw = $_POST['orders'] ?? $_GET['orders'] ?? ($_POST['order'] ?? $_GET['order'] ?? '');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$raw)))));
        $out = [];
        foreach ($ids as $oid) {
            $st = $db->prepare("SELECT o.Order_id, o.Order_oo, o.d_id, o.Qty, o.Order_status,
                    o.ateGet, o.ate, o.ateNote, o.in_review, o.pmGet,
                    (SELECT COALESCE(SUM(m.allocated_qty),0) FROM bom_order_process_map m WHERE m.order_id=o.Order_id) AS bom_alloc_sum,
                    (SELECT COUNT(*) FROM bom_order_process_map m WHERE m.order_id=o.Order_id) AS bom_cnt
                FROM order_track o WHERE o.Order_id = ?");
            $st->execute([$oid]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o) continue;
            $pmDone = !empty($o['pmGet']);              // 已轉生管（＝批圖結束）
            $bomCnt = (int)$o['bom_cnt'];
            $allocSum = (int)$o['bom_alloc_sum'];
            $qty = (int)$o['Qty'];
            // 該訂單綁定的 BOM 逐筆製程進度（重用 pt_bom_progress）
            $boms = [];
            $bst = $db->prepare("SELECT m.bom FROM bom_order_process_map m WHERE m.order_id = ? ORDER BY m.bom");
            $bst->execute([$oid]);
            $bomAllDone = $bomCnt > 0;
            foreach ($bst->fetchAll(PDO::FETCH_COLUMN) as $bomName) {
                $bd = pt_bom_progress($db, $bomName);
                if (!$bd) continue;
                $boms[] = $bd;
                $done = $bd['is_closed'] || ($bd['process_count'] > 0 && $bd['current_step'] >= $bd['process_count']);
                if (!$done) $bomAllDone = false;
            }
            if (!$boms) $bomAllDone = false;
            // 各階段子狀態
            $designSub = null;
            if (!$pmDone) {
                if (!empty($o['in_review'])) $designSub = '審圖中';
                elseif (trim((string)$o['ateNote']) !== '') $designSub = '批圖溝通中';
                else $designSub = '批圖中';
            }
            $bindSub = $bomCnt > 0 ? (($qty > 0 && $allocSum >= $qty) ? '全部綁定' : '部分綁定') : null;
            // 線性四階段完成旗標（pmGet 一到，設計批圖與轉生管同時完成）
            $reached = [$pmDone, $pmDone, $bomCnt > 0, $bomAllDone];
            $stages = [
                ['key' => 'design', 'name' => '設計批圖', 'reached' => $reached[0] ? 1 : 0, 'sub' => $designSub, 'date' => $o['ateGet']],
                ['key' => 'pm', 'name' => '轉生管', 'reached' => $reached[1] ? 1 : 0, 'sub' => null, 'date' => $o['pmGet']],
                ['key' => 'bind', 'name' => '生管綁定BOM', 'reached' => $reached[2] ? 1 : 0, 'sub' => $bindSub, 'date' => null],
                ['key' => 'bom', 'name' => 'BOM製程', 'reached' => $reached[3] ? 1 : 0, 'sub' => null, 'date' => null],
            ];
            $curIdx = -1;
            foreach ($reached as $i => $r) { if (!$r) { $curIdx = $i; break; } }
            foreach ($stages as $i => &$s) { $s['current'] = ($i === $curIdx) ? 1 : 0; }
            unset($s);
            $status = $o['Order_status'];
            $out[(string)$oid] = [
                'order_id' => $oid, 'order_no' => $o['Order_oo'], 'd_id' => $o['d_id'], 'qty' => $qty,
                'order_status' => $status,
                'is_closed' => ($status !== null && (int)$status === 9) ? 1 : 0,
                'is_paused' => ($status !== null && (int)$status === 6) ? 1 : 0,
                'stages' => $stages, 'boms' => $boms,
            ];
        }
        echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══ 分享/複製對象搜尋（限有 personal_task 使用資格者，排除自己）══════════
    if ($action === 'search_users') {
        $st = $db->prepare("SELECT DISTINCT u.id, u.user_cname FROM user u
                            JOIN user_roles ur ON ur.user_id = u.id
                            JOIN roles r ON r.role_id = ur.role_id
                            WHERE r.module = 'personal_task' AND u.id <> ?
                              AND (u.user_cname LIKE ? OR u.user_uname LIKE ?)
                            ORDER BY u.user_cname LIMIT 20");
        $st->execute([$user_id, $like, $like]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══ 分享管理（皆 owner only）══════════════════════════════════════════
    if ($action === 'list_shares') {
        $taskId = (int)($_POST['task_id'] ?? $_GET['task_id'] ?? 0);
        if (!pt_get_own_task($db, $user_id, $taskId)) throw new Exception('找不到紀錄或無權限');
        $st = $db->prepare("SELECT sh.id, sh.shared_with_user_id, sh.mode, u.user_cname
                            FROM personal_task_share sh JOIN user u ON u.id = sh.shared_with_user_id
                            WHERE sh.task_id = ? ORDER BY u.user_cname");
        $st->execute([$taskId]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'add_share') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $target = (int)($_POST['target_user_id'] ?? 0);
        $mode = (($_POST['mode'] ?? 'view') === 'edit') ? 'edit' : 'view';
        $task = pt_get_own_task($db, $user_id, $taskId);
        if (!$task) throw new Exception('找不到紀錄或無權限');
        if ($target <= 0 || $target === $user_id) throw new Exception('分享對象不正確');
        if (!rf_has_module_role($db, $target, 'personal_task')) throw new Exception('該使用者尚無個人工作紀錄權限，無法看到分享');
        $db->prepare("INSERT INTO personal_task_share (task_id, owner_id, shared_with_user_id, mode, created_by)
                      VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE mode = VALUES(mode)")
           ->execute([$taskId, $user_id, $target, $mode, $user_id]);
        try {
            $modeTxt = $mode === 'edit' ? '共同進度' : '看進度';
            personal_task_remind_user($db, $target, '📋 有人分享工作給你',
                pt_user_name($db, $user_id) . '分享了「' . $task['title'] . '」（' . $modeTxt . '）給你');
        } catch (\Throwable $e) {}
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove_share') {
        $shareId = (int)($_POST['share_id'] ?? 0);
        $st = $db->prepare("SELECT sh.id FROM personal_task_share sh
                            JOIN personal_task t ON t.id = sh.task_id
                            WHERE sh.id = ? AND t.user_id = ?");
        $st->execute([$shareId, $user_id]);
        if (!$st->fetchColumn()) throw new Exception('找不到分享或無權限');
        $db->prepare("DELETE FROM personal_task_share WHERE id = ?")->execute([$shareId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ══ 複製給對方：一次性複製成對方獨立的工作，進度歸零（owner only）══════
    if ($action === 'copy_task') {
        $srcId = (int)($_POST['task_id'] ?? 0);
        $target = (int)($_POST['target_user_id'] ?? 0);
        $src = pt_get_own_task($db, $user_id, $srcId);
        if (!$src) throw new Exception('找不到紀錄或無權限');
        if ($target <= 0) throw new Exception('複製對象不正確');
        if (!rf_has_module_role($db, $target, 'personal_task')) throw new Exception('該使用者尚無個人工作紀錄權限');
        $db->beginTransaction();
        try {
            // 主檔（進度歸零：status=0、received_at=今天、完成/提醒旗標清空；期限沿用）
            $ins = $db->prepare("INSERT INTO personal_task
                    (user_id, title, proposer, bind_type, bind_id, bind_label, status, received_at, deadline,
                     remind_before_minutes, remind_sent, urgent_days, note)
                    VALUES (?,?,?,?,?,?,0,CURDATE(),?,?,0,?,?)");
            $ins->execute([$target, $src['title'], $src['proposer'] ?? null, $src['bind_type'], $src['bind_id'], $src['bind_label'],
                $src['deadline'], $src['remind_before_minutes'], $src['urgent_days'], $src['note']]);
            $newId = (int)$db->lastInsertId();
            // 綁定複製
            $selB = $db->prepare("SELECT bind_type, bind_id, bind_label, sort_order FROM personal_task_bind WHERE task_id = ?");
            $selB->execute([$srcId]);
            $insB = $db->prepare("INSERT INTO personal_task_bind (task_id, bind_type, bind_id, bind_label, sort_order) VALUES (?,?,?,?,?)");
            foreach ($selB->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $insB->execute([$newId, $b['bind_type'], $b['bind_id'], $b['bind_label'], $b['sort_order']]);
            }
            // 步驟＋小項複製（進度歸零：reached_at/done_at 清空、remind_sent=0）
            $selS = $db->prepare("SELECT id, step_name, sort_order, planned_at, remind_before_minutes
                                  FROM personal_task_step WHERE task_id = ? ORDER BY sort_order, id");
            $selS->execute([$srcId]);
            $insS = $db->prepare("INSERT INTO personal_task_step
                    (task_id, step_name, sort_order, planned_at, remind_before_minutes, remind_sent) VALUES (?,?,?,?,?,0)");
            $selItems = $db->prepare("SELECT item_name, sort_order FROM personal_task_step_item WHERE step_id = ? ORDER BY sort_order, id");
            $insItem = $db->prepare("INSERT INTO personal_task_step_item (step_id, item_name, sort_order) VALUES (?,?,?)");
            foreach ($selS->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $insS->execute([$newId, $s['step_name'], $s['sort_order'], $s['planned_at'], $s['remind_before_minutes']]);
                $newStepId = (int)$db->lastInsertId();
                $selItems->execute([$s['id']]);
                foreach ($selItems->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $insItem->execute([$newStepId, $it['item_name'], $it['sort_order']]);
                }
            }
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        try {
            personal_task_remind_user($db, $target, '📋 有人複製工作給你',
                pt_user_name($db, $user_id) . '複製了一筆工作「' . $src['title'] . '」給你');
        } catch (\Throwable $e) {}
        echo json_encode(['success' => true, 'id' => $newId]);
        exit;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
