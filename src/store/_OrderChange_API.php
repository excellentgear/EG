<?php
// c:\MAMP\htdocs\EGsystem\src\store\_OrderChange_API.php
// 訂單變更（NewOrder_Track222）後端 API：變更紀錄、通知(live_event)、附件(Z槽)、歷史、設定
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => '未登入或登入已逾時']);
    exit;
}

require_once __DIR__ . '/../common/DBConnection.php';

$db   = new DBConnection();
$pdo  = $db->getPDO();
$uid  = intval($_SESSION['id'] ?? 0);
// 顯示名稱優先用中文姓名（避免顯示登入帳號）
$uname = $_SESSION['user_cname'] ?? '';
if ($uname === '') {
    try { $q = $pdo->prepare("SELECT user_cname FROM user WHERE id=?"); $q->execute([$uid]); $cn = $q->fetchColumn(); if ($cn) $uname = $cn; } catch (Exception $e) {}
}
if ($uname === '') $uname = $_SESSION['userName'] ?? 'system';

// ── 權限：沿用 NewOrder_Track222 的 RBAC 結果（簡化：以 session 內 permission 快取）──
// 這裡只做基本判斷；A 權限視為管理者。設定相關動作需 A。
function oc_perm_code(PDO $pdo, int $uid): string {
    // 沿用 NewOrder_Track222 的權限 session 快取鍵
    $key = 'perm_code_newordertrack_' . $uid;
    if (isset($_SESSION[$key]) && is_string($_SESSION[$key])) return $_SESSION[$key];
    return '';
}
$perm_code = oc_perm_code($pdo, $uid);
$is_admin  = ($perm_code === 'A') || in_array(intval($_SESSION['status'] ?? 0), [9, 90]);
// 沿用頁面 RBAC：U=修改、D=刪除（A 視為全有）
$can_update = $is_admin || strpos($perm_code, 'U') !== false;
$can_delete = $is_admin || strpos($perm_code, 'D') !== false;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── 建表（第一次自動建立）──────────────────────────────────────────────────
function oc_ensure_tables(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_change_log (
          id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id     INT NOT NULL COMMENT 'order_track.Order_id',
          order_no     VARCHAR(100) NULL COMMENT 'Order_oo 快照',
          client_name  VARCHAR(190) NULL COMMENT '客戶名稱快照',
          d_id         VARCHAR(190) NULL COMMENT '料號快照',
          changes_json TEXT NULL COMMENT '[{field,label,old,new}]',
          note         TEXT NULL COMMENT '備註',
          live_event_id INT NULL COMMENT '連結 live_event.id',
          created_by    VARCHAR(100) NULL,
          created_by_id INT NULL,
          created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_order (order_id),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='訂單變更紀錄';
    ");
    // 變更單號欄位（COO+民國年3碼+月日各2碼+流水號3碼），第一次自動加欄並回填舊資料
    $col = $pdo->query("SHOW COLUMNS FROM order_change_log LIKE 'change_no'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE order_change_log
            ADD COLUMN change_no VARCHAR(20) NULL COMMENT '變更單號 COO+民國年月日+流水號' AFTER order_id,
            ADD UNIQUE KEY uk_change_no (change_no)");
        // 舊資料回填：依建立日期 + id 順序給流水號
        $rows = $pdo->query("SELECT id, DATE(created_at) AS d FROM order_change_log WHERE change_no IS NULL ORDER BY created_at, id")->fetchAll(PDO::FETCH_ASSOC);
        $byDate = [];
        $upd = $pdo->prepare("UPDATE order_change_log SET change_no=? WHERE id=?");
        foreach ($rows as $r) {
            $d = $r['d'];
            $byDate[$d] = ($byDate[$d] ?? 0) + 1;
            $ts = strtotime($d);
            $no = 'COO' . str_pad((string)((int)date('Y', $ts) - 1911), 3, '0', STR_PAD_LEFT)
                . date('md', $ts) . str_pad((string)$byDate[$d], 3, '0', STR_PAD_LEFT);
            $upd->execute([$no, $r['id']]);
        }
    }
    // 軟刪除(作廢)與備註修改追蹤欄位，第一次自動加欄
    $col = $pdo->query("SHOW COLUMNS FROM order_change_log LIKE 'is_void'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE order_change_log
            ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0 COMMENT '作廢(軟刪除)：0=有效 1=作廢' AFTER live_event_id,
            ADD COLUMN void_reason VARCHAR(255) NULL COMMENT '作廢原因' AFTER is_void,
            ADD COLUMN voided_by VARCHAR(100) NULL AFTER void_reason,
            ADD COLUMN voided_by_id INT NULL AFTER voided_by,
            ADD COLUMN voided_at DATETIME NULL AFTER voided_by_id,
            ADD COLUMN updated_by VARCHAR(100) NULL COMMENT '最後修改備註者' AFTER voided_at,
            ADD COLUMN updated_at DATETIME NULL AFTER updated_by");
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_change_attachment (
          id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
          change_id     INT UNSIGNED NOT NULL,
          filename      VARCHAR(255) NOT NULL COMMENT '磁碟雜湊檔名',
          original_name VARCHAR(255) NULL,
          file_size     VARCHAR(20) NULL,
          uploaded_by   VARCHAR(100) NULL,
          uploaded_by_id INT NULL,
          uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_change (change_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='訂單變更附件';
    ");
}
oc_ensure_tables($pdo);

// ── 設定讀取 ───────────────────────────────────────────────────────────────
function oc_setting(PDO $pdo, string $key, $default = '') {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v !== false && $v !== null) ? $v : $default;
    } catch (Exception $e) { return $default; }
}
function oc_save_setting(PDO $pdo, string $key, string $val, int $uid, string $uname) {
    $st = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                         VALUES (?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                            updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by), updated_at=NOW()");
    $st->execute([$key, $val, $uid, $uname]);
}

// 取得人員「部門/職稱」歸屬（含兼職，依部門→職稱階級→職稱排序→姓名）
// $userIds = null 代表全部在職人員；否則只取指定 user id
function oc_user_memberships(PDO $pdo, $userIds = null) {
    $where = "u.state = 1";
    $bind = [];
    if (is_array($userIds)) {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) return [];
        $where .= " AND u.id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")";
        $bind = $userIds;
    }
    $sql = "SELECT u.id, u.user_cname,
                   d.id AS dept_id, d.name AS dept_name,
                   p.name AS pos_name, udpm.is_main,
                   COALESCE(pl.level, 9999) AS lvl,
                   COALESCE(p.sort_order, 9999) AS pos_sort,
                   COALESCE(d.sort_order, 9999) AS dept_sort
            FROM user u
            JOIN user_department_position_map udpm ON u.id = udpm.user_id
            LEFT JOIN department d ON udpm.department_id = d.id
            LEFT JOIN position p ON udpm.position_id = p.id
            LEFT JOIN position_level pl ON p.id = pl.position_id
            WHERE $where
            ORDER BY dept_sort, d.id, lvl, pos_sort, u.user_cname";
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'        => (int)$r['id'],
            'user_cname'=> $r['user_cname'],
            'dept_id'   => $r['dept_id'] !== null ? (int)$r['dept_id'] : 0,
            'dept_name' => $r['dept_name'] ?? '（未分部門）',
            'pos_name'  => $r['pos_name'] ?? '',
            'is_main'   => (int)$r['is_main'],
        ];
    }
    return $rows;
}

// 產生變更單號：COO + 民國年3碼 + 月日各2碼 + 當日流水號3碼（需在交易內呼叫以鎖定流水號）
function oc_gen_change_no(PDO $pdo): string {
    $ts = time();
    $prefix = 'COO' . str_pad((string)((int)date('Y', $ts) - 1911), 3, '0', STR_PAD_LEFT) . date('md', $ts);
    $st = $pdo->prepare("SELECT change_no FROM order_change_log WHERE change_no LIKE ? ORDER BY change_no DESC LIMIT 1 FOR UPDATE");
    $st->execute([$prefix . '%']);
    $last = $st->fetchColumn();
    $seq = $last ? (intval(substr($last, -3)) + 1) : 1;
    return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

// 讀取多筆 live_event 的通知對象 leid => [['target_type'=>,'target_id'=>], ...]
function oc_targets_map(PDO $pdo, array $leids): array {
    $out = [];
    $leids = array_values(array_unique(array_filter(array_map('intval', $leids))));
    if (empty($leids)) return $out;
    $in = implode(',', $leids);
    foreach ($pdo->query("SELECT live_event_id, target_type, target_id FROM live_event_target WHERE live_event_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['live_event_id']][] = $r;
    }
    return $out;
}

// 讀取多筆 live_event 的已閱名單 leid => [user_id => read_at]（合併純已閱與回簽/回覆）
function oc_readers_map(PDO $pdo, array $leids): array {
    $out = [];
    $leids = array_values(array_unique(array_filter(array_map('intval', $leids))));
    if (empty($leids)) return $out;
    $in = implode(',', $leids);
    foreach ($pdo->query("SELECT live_event_id, user_id, read_at FROM live_event_for_user WHERE live_event_id IN ($in) AND oready_read=1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['live_event_id']][(int)$r['user_id']] = $r['read_at'];
    }
    foreach ($pdo->query("SELECT live_event_id, user_id, COALESCE(read_at, signed_at, replied_at) AS ra FROM live_event_response WHERE live_event_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['ra'] === null) continue;
        $leid = (int)$r['live_event_id']; $u = (int)$r['user_id'];
        if (!isset($out[$leid][$u])) $out[$leid][$u] = $r['ra'];
    }
    return $out;
}

// 將一組通知對象展開為實際收件人 user_id => user_cname
// 傳入預先備好的 $allUsers（全體在職）與 $deptUsers（dept_id => [uid=>name]）以避免重複查詢
function oc_expand_recipients(array $tgRows, array $allUsers, array $deptUsers, array $userNames): array {
    $users = [];
    foreach ($tgRows as $r) {
        if ($r['target_type'] === 'all') return $allUsers;
    }
    foreach ($tgRows as $r) {
        if ($r['target_type'] === 'dept') {
            foreach (($deptUsers[(int)$r['target_id']] ?? []) as $uid => $nm) $users[$uid] = $nm;
        } elseif ($r['target_type'] === 'user') {
            $uid = (int)$r['target_id'];
            $users[$uid] = $userNames[$uid] ?? ('#' . $uid);
        }
    }
    return $users;
}

// 依 targets 需求批次備妥 全體/部門/人員 名單（回傳 [allUsers, deptUsers, userNames]）
function oc_prepare_user_pools(PDO $pdo, array $tgMap): array {
    $needAll = false; $deptIds = []; $userIds = [];
    foreach ($tgMap as $rows) {
        foreach ($rows as $r) {
            if ($r['target_type'] === 'all') $needAll = true;
            elseif ($r['target_type'] === 'dept') $deptIds[(int)$r['target_id']] = 1;
            elseif ($r['target_type'] === 'user') $userIds[(int)$r['target_id']] = 1;
        }
    }
    $allUsers = []; $deptUsers = []; $userNames = [];
    if ($needAll) {
        foreach ($pdo->query("SELECT id, user_cname FROM user WHERE state=1")->fetchAll(PDO::FETCH_ASSOC) as $u)
            $allUsers[(int)$u['id']] = $u['user_cname'];
    }
    if (!empty($deptIds)) {
        $in = implode(',', array_keys($deptIds));
        foreach ($pdo->query("SELECT DISTINCT m.department_id, u.id, u.user_cname
                              FROM user u JOIN user_department_position_map m ON m.user_id = u.id
                              WHERE u.state=1 AND m.department_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $u)
            $deptUsers[(int)$u['department_id']][(int)$u['id']] = $u['user_cname'];
    }
    if (!empty($userIds)) {
        $in = implode(',', array_keys($userIds));
        foreach ($pdo->query("SELECT id, user_cname FROM user WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $u)
            $userNames[(int)$u['id']] = $u['user_cname'];
    }
    return [$allUsers, $deptUsers, $userNames];
}

// 可變更欄位定義（資料庫欄位 → 顯示標籤、型別）
$CHANGE_FIELDS = [
    'Delivery_date'    => ['label' => '交期',   'type' => 'date'],
    'Qty'              => ['label' => '數量',   'type' => 'num'],
    'unit_price'       => ['label' => '單價',   'type' => 'num'],
    'Processing_items' => ['label' => '製程',   'type' => 'text'],
    'Order_ps'         => ['label' => '業務備註', 'type' => 'text'],
];

function oc_norm($v, $type) {
    $v = trim((string)($v ?? ''));
    if ($type === 'num') {
        if ($v === '') return '';
        // 去掉千分位逗號
        $v = str_replace(',', '', $v);
        if (!is_numeric($v)) return $v;
        return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
    }
    if ($type === 'date') {
        if ($v === '') return '';
        $v = str_replace('/', '-', $v);
        return substr($v, 0, 10);
    }
    return $v;
}

try {
    // ════════════════════════════════════════════════════════════════════════
    // 取得訂單明細（變更跳窗用）
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'get_order') {
        $oid = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
        if (!$oid) throw new Exception('未指定訂單ID');
        $stmt = $pdo->prepare("SELECT ot.*,
            DATE_FORMAT(ot.Order_date, '%Y-%m-%d') AS order_date_f,
            DATE_FORMAT(ot.Delivery_date, '%Y-%m-%d') AS delivery_date_f,
            cl.customer AS cl_customer_name
            FROM order_track ot
            LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
            WHERE ot.Order_id = ?");
        $stmt->execute([$oid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('找不到訂單');
        $client = !empty($row['cl_customer_name']) ? $row['cl_customer_name'] : ($row['Client_name'] ?? '');
        $trimNum = function($v) { if ($v === null || $v === '') return ''; if (!is_numeric($v)) return $v;
            return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.'); };
        echo json_encode(['success' => true, 'data' => [
            'Order_id'      => $row['Order_id'],
            'order_no'      => $row['Order_oo'] ?? '',
            'client_name'   => $client,
            'client_order'  => $row['C_order'] ?? '',
            'd_id'          => $row['d_id'] ?? '',
            'order_date'    => $row['order_date_f'] ?? '',
            'Delivery_date'    => $row['delivery_date_f'] ?? '',
            'Qty'              => $trimNum($row['Qty'] ?? ''),
            'unit_price'       => $trimNum($row['unit_price'] ?? ''),
            'Processing_items' => $row['Processing_items'] ?? '',
            'Order_ps'         => $row['Order_ps'] ?? '',
        ]]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 取得可選通知對象（依設定過濾）— 變更跳窗用
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'get_notify_targets') {
        $cfg = json_decode(oc_setting($pdo, 'order_change_notify_targets', ''), true);
        $deptIds = $cfg['depts'] ?? [];
        $userIds = $cfg['users'] ?? [];
        $allowAll = !empty($cfg['allow_all']);
        $depts = [];
        $userRows = [];
        if (!empty($deptIds)) {
            $ph = implode(',', array_fill(0, count($deptIds), '?'));
            $st = $pdo->prepare("SELECT id, name FROM department WHERE id IN ($ph) ORDER BY sort_order, id");
            $st->execute(array_map('intval', $deptIds));
            $depts = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if (!empty($userIds)) {
            $userRows = oc_user_memberships($pdo, $userIds);
        }
        echo json_encode(['success' => true, 'allow_all' => $allowAll, 'depts' => $depts, 'user_rows' => $userRows]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 儲存變更：更新訂單 + 寫變更紀錄 + 建立通知(live_event) ; 回傳 change_id
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'save_change') {
        global $CHANGE_FIELDS;
        $oid = intval($_POST['order_id'] ?? 0);
        if (!$oid) throw new Exception('未指定訂單ID');
        $note = trim($_POST['note'] ?? '');
        $newVals = json_decode($_POST['new_values'] ?? '{}', true);
        if (!is_array($newVals)) $newVals = [];
        $targets = $_POST['targets'] ?? [];
        if (!is_array($targets)) $targets = array_filter(array_map('trim', explode(',', (string)$targets)));

        $pdo->beginTransaction();

        $cur = $pdo->prepare("SELECT ot.*, cl.customer AS cl_customer_name
            FROM order_track ot LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
            WHERE ot.Order_id = ? FOR UPDATE");
        $cur->execute([$oid]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->rollBack(); throw new Exception('找不到訂單'); }

        // 計算差異
        $diffs = [];
        $setParts = [];
        $setBind  = [];
        foreach ($CHANGE_FIELDS as $f => $meta) {
            if (!array_key_exists($f, $newVals)) continue;
            $oldN = oc_norm($row[$f] ?? '', $meta['type']);
            $newN = oc_norm($newVals[$f] ?? '', $meta['type']);
            if ($oldN === $newN) continue;
            $diffs[] = ['field' => $f, 'label' => $meta['label'], 'old' => $oldN, 'new' => $newN];
            // 寫回值（日期空值轉 NULL）
            $writeVal = $newN;
            if ($meta['type'] === 'date' && $newN === '') $writeVal = null;
            $setParts[] = "`$f` = ?";
            $setBind[]  = $writeVal;
        }

        if (empty($diffs) && $note === '') {
            $pdo->rollBack();
            throw new Exception('沒有任何變更內容，也未填寫備註');
        }

        // 更新訂單
        if (!empty($setParts)) {
            $setBind[] = $oid;
            $up = $pdo->prepare("UPDATE order_track SET " . implode(', ', $setParts) . " WHERE Order_id = ?");
            $up->execute($setBind);
        }

        $client = !empty($row['cl_customer_name']) ? $row['cl_customer_name'] : ($row['Client_name'] ?? '');

        // 產生變更單號（交易內鎖定當日流水號）
        $changeNo = oc_gen_change_no($pdo);

        // 建立通知 live_event
        $liveId = null;
        // 標題與內容
        $title = '【訂單變更】' . $client . ' / ' . ($row['d_id'] ?? '') . '（單號 ' . ($row['Order_oo'] ?? $oid) . '）';
        $lines = [];
        $lines[] = '變更單號：' . $changeNo;
        foreach ($diffs as $d) {
            $lines[] = $d['label'] . '：' . ($d['old'] === '' ? '（空）' : $d['old']) . ' → ' . ($d['new'] === '' ? '（空）' : $d['new']);
        }
        if ($note !== '') $lines[] = '備註：' . $note;
        $lines[] = '變更人：' . $uname;
        $content = implode("\n", $lines);

        // 解析 targets：all / dept-N / user-N / status-N
        $tgRows = []; $hasAll = false;
        foreach ($targets as $tv) {
            if ($tv === 'all') $hasAll = true;
            elseif (strpos($tv, 'dept-') === 0)   $tgRows[] = ['dept',   (int)substr($tv, 5)];
            elseif (strpos($tv, 'status-') === 0) $tgRows[] = ['status', (int)substr($tv, 7)];
            elseif (strpos($tv, 'user-') === 0)   $tgRows[] = ['user',   (int)substr($tv, 5)];
        }
        if ($hasAll) $tgRows = [['all', 0]];

        if (!empty($tgRows)) {
            // 來源固定為『訂單變更』，並記錄建立者
            $ev = $pdo->prepare("INSERT INTO `live_event`(`eventdate`,`enddate`,`title`,`content`,`status`,`created_by`,`source`) VALUES (CURDATE(), NULL, ?, ?, 0, ?, '訂單變更')");
            $ev->execute([$title, $content, ($uid ?: null)]);
            $liveId = (int)$pdo->lastInsertId();
            // 產生公告編號
            require_once __DIR__ . '/../common/notice_files.php';
            $evNo = eg_gen_event_no($pdo, date('Y-m-d'));
            $pdo->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([$evNo, $liveId]);
            $ins = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id) VALUES (?,?,?)");
            $seen = []; $histTargets = [];
            foreach ($tgRows as $r) {
                $k = $r[0] . '-' . $r[1];
                if (isset($seen[$k])) continue;
                $seen[$k] = 1;
                $ins->execute([$liveId, $r[0], $r[1]]);
                $histTargets[] = $r[0] . ($r[0] === 'all' ? '' : '-' . $r[1]);
            }
            // 修改歷史（新增，來源：訂單變更）
            $snap = ['eventdate' => date('Y-m-d'), 'enddate' => null, 'title' => $title, 'content' => $content, 'status' => 0, 'targets' => $histTargets];
            $pdo->prepare("INSERT INTO live_event_history (live_event_id, action, changed_by, changed_at, after_data) VALUES (?,?,?,NOW(),?)")
                ->execute([$liveId, 'create', ($uid ?: null), json_encode($snap, JSON_UNESCAPED_UNICODE)]);
        }

        // 寫變更紀錄
        $log = $pdo->prepare("INSERT INTO order_change_log
            (order_id, change_no, order_no, client_name, d_id, changes_json, note, live_event_id, created_by, created_by_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $log->execute([
            $oid, $changeNo, $row['Order_oo'] ?? '', $client, $row['d_id'] ?? '',
            json_encode($diffs, JSON_UNESCAPED_UNICODE), ($note !== '' ? $note : null),
            $liveId, $uname, $uid
        ]);
        $changeId = (int)$pdo->lastInsertId();

        $pdo->commit();
        echo json_encode(['success' => true, 'change_id' => $changeId, 'change_no' => $changeNo, 'live_event_id' => $liveId,
                          'changed' => count($diffs), 'notified' => !empty($tgRows)]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 修改變更：僅可改備註（欄位變更內容為稽核紀錄不可改），並同步 live_event 內容
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'update_change') {
        if (!$can_update) throw new Exception('權限不足（需修改權限）');
        $changeId = intval($_POST['change_id'] ?? 0);
        if (!$changeId) throw new Exception('缺少變更單ID');
        $note = trim($_POST['note'] ?? '');

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM order_change_log WHERE id=? FOR UPDATE");
        $st->execute([$changeId]);
        $chg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chg) { $pdo->rollBack(); throw new Exception('找不到變更紀錄'); }
        if ((int)$chg['is_void'] === 1) { $pdo->rollBack(); throw new Exception('此變更單已作廢，不可修改'); }
        $diffs = json_decode($chg['changes_json'] ?? '[]', true) ?: [];
        if (empty($diffs) && $note === '') { $pdo->rollBack(); throw new Exception('此筆無欄位變更，備註不可為空'); }

        $pdo->prepare("UPDATE order_change_log SET note=?, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([($note !== '' ? $note : null), $uname, $changeId]);

        // 同步衍生通知內容（重建與 save_change 相同格式）
        $synced = false;
        $leid = (int)($chg['live_event_id'] ?? 0);
        if ($leid) {
            $ev = $pdo->prepare("SELECT id, title, content FROM live_event WHERE id=?");
            $ev->execute([$leid]);
            $evRow = $ev->fetch(PDO::FETCH_ASSOC);
            if ($evRow) {
                $lines = [];
                $lines[] = '變更單號：' . ($chg['change_no'] ?? '');
                foreach ($diffs as $d) {
                    $lines[] = ($d['label'] ?? $d['field'] ?? '') . '：' . (($d['old'] ?? '') === '' ? '（空）' : $d['old']) . ' → ' . (($d['new'] ?? '') === '' ? '（空）' : $d['new']);
                }
                if ($note !== '') $lines[] = '備註：' . $note;
                $lines[] = '變更人：' . ($chg['created_by'] ?? '');
                $lines[] = '備註修改：' . $uname . '（' . date('Y-m-d H:i') . '）';
                $newContent = implode("\n", $lines);
                $pdo->prepare("UPDATE live_event SET content=? WHERE id=?")->execute([$newContent, $leid]);
                $pdo->prepare("INSERT INTO live_event_history (live_event_id, action, changed_by, changed_at, before_data, after_data) VALUES (?,?,?,NOW(),?,?)")
                    ->execute([$leid, 'update', ($uid ?: null),
                        json_encode(['content' => $evRow['content']], JSON_UNESCAPED_UNICODE),
                        json_encode(['content' => $newContent], JSON_UNESCAPED_UNICODE)]);
                $synced = true;
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'synced' => $synced]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 取得某筆變更的通知對象（編輯用）：目前勾選 + 可選清單（含目前對象即使已不在設定內）
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'get_change_targets') {
        if (!$can_update) throw new Exception('權限不足（需修改權限）');
        $changeId = intval($_POST['change_id'] ?? $_GET['change_id'] ?? 0);
        if (!$changeId) throw new Exception('缺少變更單ID');
        $st = $pdo->prepare("SELECT id, change_no, live_event_id, is_void FROM order_change_log WHERE id=?");
        $st->execute([$changeId]);
        $chg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chg) throw new Exception('找不到變更紀錄');
        if ((int)$chg['is_void'] === 1) throw new Exception('此變更單已作廢，不可修改通知對象');

        // 目前對象
        $current = []; $curDeptIds = []; $curUserIds = []; $curAll = false;
        $leid = (int)($chg['live_event_id'] ?? 0);
        if ($leid) {
            $t = $pdo->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id=?");
            $t->execute([$leid]);
            foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['target_type'] === 'all') { $current[] = 'all'; $curAll = true; }
                elseif ($r['target_type'] === 'dept') { $current[] = 'dept-' . (int)$r['target_id']; $curDeptIds[] = (int)$r['target_id']; }
                elseif ($r['target_type'] === 'user') { $current[] = 'user-' . (int)$r['target_id']; $curUserIds[] = (int)$r['target_id']; }
            }
        }

        // 可選清單 = 設定的對象 ∪ 目前對象（避免設定變更後看不到既有對象而被誤刪）
        $cfg = json_decode(oc_setting($pdo, 'order_change_notify_targets', ''), true) ?: [];
        $deptIds  = array_values(array_unique(array_merge(array_map('intval', $cfg['depts'] ?? []), $curDeptIds)));
        $userIds  = array_values(array_unique(array_merge(array_map('intval', $cfg['users'] ?? []), $curUserIds)));
        $allowAll = !empty($cfg['allow_all']) || $curAll;
        $depts = []; $userRows = [];
        if (!empty($deptIds)) {
            $ph = implode(',', array_fill(0, count($deptIds), '?'));
            $q = $pdo->prepare("SELECT id, name FROM department WHERE id IN ($ph) ORDER BY sort_order, id");
            $q->execute($deptIds);
            $depts = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        if (!empty($userIds)) $userRows = oc_user_memberships($pdo, $userIds);
        echo json_encode(['success' => true, 'change_no' => $chg['change_no'],
            'current' => $current, 'allow_all' => $allowAll, 'depts' => $depts, 'user_rows' => $userRows]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 修改通知對象：可新增/刪除；原無通知可補建 live_event；全移除則連動刪除通知
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'update_change_targets') {
        if (!$can_update) throw new Exception('權限不足（需修改權限）');
        $changeId = intval($_POST['change_id'] ?? 0);
        if (!$changeId) throw new Exception('缺少變更單ID');
        $targets = $_POST['targets'] ?? [];
        if (!is_array($targets)) $targets = array_filter(array_map('trim', explode(',', (string)$targets)));

        // 解析 targets（同 save_change）
        $tgRows = []; $hasAll = false;
        foreach ($targets as $tv) {
            if ($tv === 'all') $hasAll = true;
            elseif (strpos($tv, 'dept-') === 0)   $tgRows[] = ['dept',   (int)substr($tv, 5)];
            elseif (strpos($tv, 'status-') === 0) $tgRows[] = ['status', (int)substr($tv, 7)];
            elseif (strpos($tv, 'user-') === 0)   $tgRows[] = ['user',   (int)substr($tv, 5)];
        }
        if ($hasAll) $tgRows = [['all', 0]];

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM order_change_log WHERE id=? FOR UPDATE");
        $st->execute([$changeId]);
        $chg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chg) { $pdo->rollBack(); throw new Exception('找不到變更紀錄'); }
        if ((int)$chg['is_void'] === 1) { $pdo->rollBack(); throw new Exception('此變更單已作廢，不可修改通知對象'); }
        $leid = (int)($chg['live_event_id'] ?? 0);

        $notified = false;
        if (!empty($tgRows) && !$leid) {
            // 原無通知 → 補建 live_event（同 save_change 格式）
            $diffs = json_decode($chg['changes_json'] ?? '[]', true) ?: [];
            $title = '【訂單變更】' . ($chg['client_name'] ?? '') . ' / ' . ($chg['d_id'] ?? '') . '（單號 ' . ($chg['order_no'] ?? $chg['order_id']) . '）';
            $lines = [];
            $lines[] = '變更單號：' . ($chg['change_no'] ?? '');
            foreach ($diffs as $d) {
                $lines[] = ($d['label'] ?? $d['field'] ?? '') . '：' . (($d['old'] ?? '') === '' ? '（空）' : $d['old']) . ' → ' . (($d['new'] ?? '') === '' ? '（空）' : $d['new']);
            }
            if (($chg['note'] ?? '') !== '' && $chg['note'] !== null) $lines[] = '備註：' . $chg['note'];
            $lines[] = '變更人：' . ($chg['created_by'] ?? '');
            $content = implode("\n", $lines);
            $ev = $pdo->prepare("INSERT INTO `live_event`(`eventdate`,`enddate`,`title`,`content`,`status`,`created_by`,`source`) VALUES (CURDATE(), NULL, ?, ?, 0, ?, '訂單變更')");
            $ev->execute([$title, $content, ($uid ?: null)]);
            $leid = (int)$pdo->lastInsertId();
            require_once __DIR__ . '/../common/notice_files.php';
            $evNo = eg_gen_event_no($pdo, date('Y-m-d'));
            $pdo->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([$evNo, $leid]);
            $ins = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id) VALUES (?,?,?)");
            $seen = []; $histTargets = [];
            foreach ($tgRows as $r) {
                $k = $r[0] . '-' . $r[1];
                if (isset($seen[$k])) continue;
                $seen[$k] = 1;
                $ins->execute([$leid, $r[0], $r[1]]);
                $histTargets[] = $r[0] . ($r[0] === 'all' ? '' : '-' . $r[1]);
            }
            $snap = ['eventdate' => date('Y-m-d'), 'enddate' => null, 'title' => $title, 'content' => $content, 'status' => 0, 'targets' => $histTargets];
            $pdo->prepare("INSERT INTO live_event_history (live_event_id, action, changed_by, changed_at, after_data) VALUES (?,?,?,NOW(),?)")
                ->execute([$leid, 'create', ($uid ?: null), json_encode($snap, JSON_UNESCAPED_UNICODE)]);
            $pdo->prepare("UPDATE order_change_log SET live_event_id=?, updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$leid, $uname, $changeId]);
            $notified = true;
        } elseif (!empty($tgRows) && $leid) {
            // 既有通知 → 覆寫對象（已閱紀錄保留，讀取統計以新對象為準）
            $bt = $pdo->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id=? ORDER BY id");
            $bt->execute([$leid]);
            $before = [];
            foreach ($bt->fetchAll(PDO::FETCH_ASSOC) as $r) $before[] = $r['target_type'] . ($r['target_type'] === 'all' ? '' : '-' . $r['target_id']);
            $pdo->prepare("DELETE FROM live_event_target WHERE live_event_id=?")->execute([$leid]);
            $ins = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id) VALUES (?,?,?)");
            $seen = []; $after = [];
            foreach ($tgRows as $r) {
                $k = $r[0] . '-' . $r[1];
                if (isset($seen[$k])) continue;
                $seen[$k] = 1;
                $ins->execute([$leid, $r[0], $r[1]]);
                $after[] = $r[0] . ($r[0] === 'all' ? '' : '-' . $r[1]);
            }
            $pdo->prepare("INSERT INTO live_event_history (live_event_id, action, changed_by, changed_at, before_data, after_data) VALUES (?,?,?,NOW(),?,?)")
                ->execute([$leid, 'update', ($uid ?: null),
                    json_encode(['targets' => $before], JSON_UNESCAPED_UNICODE),
                    json_encode(['targets' => $after], JSON_UNESCAPED_UNICODE)]);
            $pdo->prepare("UPDATE order_change_log SET updated_by=?, updated_at=NOW() WHERE id=?")->execute([$uname, $changeId]);
            $notified = true;
        } elseif (empty($tgRows) && $leid) {
            // 全部移除 → 連動刪除通知（同 delete_change 的清理）
            require_once __DIR__ . '/../common/notice_files.php';
            $fq = $pdo->prepare("SELECT file_path FROM live_event_file WHERE live_event_id=?");
            $fq->execute([$leid]);
            $paths = $fq->fetchAll(PDO::FETCH_COLUMN);
            $rq = $pdo->prepare("SELECT rf.file_path FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id=?");
            $rq->execute([$leid]);
            $paths = array_merge($paths, $rq->fetchAll(PDO::FETCH_COLUMN));
            foreach ($paths as $p) { $abs = eg_notice_abs_path($p); if ($abs && is_file($abs)) @unlink($abs); }
            $pdo->prepare("DELETE rf FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id=?")->execute([$leid]);
            $pdo->prepare("DELETE FROM live_event_response WHERE live_event_id=?")->execute([$leid]);
            $pdo->prepare("DELETE FROM live_event_for_user WHERE live_event_id=?")->execute([$leid]);
            $pdo->prepare("DELETE FROM live_event_file WHERE live_event_id=?")->execute([$leid]);
            $pdo->prepare("DELETE FROM live_event_target WHERE live_event_id=?")->execute([$leid]);
            $pdo->prepare("INSERT INTO live_event_history (live_event_id, action, changed_by, changed_at, before_data) VALUES (?,?,?,NOW(),?)")
                ->execute([$leid, 'delete', ($uid ?: null),
                    json_encode(['reason' => '訂單變更通知對象全移除', 'change_no' => $chg['change_no'] ?? ''], JSON_UNESCAPED_UNICODE)]);
            $pdo->prepare("DELETE FROM live_event WHERE id=?")->execute([$leid]);
            $pdo->prepare("UPDATE order_change_log SET live_event_id=NULL, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$uname, $changeId]);
        }
        // （原本就沒通知且未勾選 → 無事可做，直接成功）
        $pdo->commit();
        echo json_encode(['success' => true, 'notified' => $notified]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 刪除變更：軟刪除（標記作廢保留稽核），連動刪除衍生通知 live_event 避免孤兒
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_change') {
        if (!$can_delete) throw new Exception('權限不足（需刪除權限）');
        $changeId = intval($_POST['change_id'] ?? 0);
        if (!$changeId) throw new Exception('缺少變更單ID');
        $reason = trim($_POST['reason'] ?? '');

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM order_change_log WHERE id=? FOR UPDATE");
        $st->execute([$changeId]);
        $chg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chg) { $pdo->rollBack(); throw new Exception('找不到變更紀錄'); }
        if ((int)$chg['is_void'] === 1) { $pdo->rollBack(); echo json_encode(['success' => true, 'already' => true]); exit; }

        $pdo->prepare("UPDATE order_change_log SET is_void=1, void_reason=?, voided_by=?, voided_by_id=?, voided_at=NOW() WHERE id=?")
            ->execute([($reason !== '' ? $reason : null), $uname, $uid, $changeId]);

        // 連動刪除衍生通知（含目標/已閱/回覆/附件），避免孤兒通知
        $eventRemoved = false;
        $leid = (int)($chg['live_event_id'] ?? 0);
        if ($leid) {
            require_once __DIR__ . '/../common/notice_files.php';
            $paths = [];
            $fq = $pdo->prepare("SELECT file_path FROM live_event_file WHERE live_event_id=?");
            $fq->execute([$leid]);
            $paths = $fq->fetchAll(PDO::FETCH_COLUMN);
            $rq = $pdo->prepare("SELECT rf.file_path FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id=?");
            $rq->execute([$leid]);
            $paths = array_merge($paths, $rq->fetchAll(PDO::FETCH_COLUMN));
            foreach ($paths as $p) { $abs = eg_notice_abs_path($p); if ($abs && is_file($abs)) @unlink($abs); }

            $pdo->prepare("DELETE rf FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id=?")->execute([$leid]);
            $pdo->prepare("DELETE FROM live_event_response WHERE live_event_id=?")->execute([$leid]);
            $pdo->prepare("DELETE FROM live_event_for_user WHERE live_event_id=?")->execute([$leid]);
            $pdo->prepare("DELETE FROM live_event_file WHERE live_event_id=?")->execute([$leid]);
            $pdo->prepare("DELETE FROM live_event_target WHERE live_event_id=?")->execute([$leid]);
            // 修改歷史留下刪除紀錄後再刪本體
            $pdo->prepare("INSERT INTO live_event_history (live_event_id, action, changed_by, changed_at, before_data) VALUES (?,?,?,NOW(),?)")
                ->execute([$leid, 'delete', ($uid ?: null),
                    json_encode(['reason' => '訂單變更單作廢連動刪除', 'change_no' => $chg['change_no'] ?? ''], JSON_UNESCAPED_UNICODE)]);
            $del = $pdo->prepare("DELETE FROM live_event WHERE id=?");
            $del->execute([$leid]);
            $eventRemoved = $del->rowCount() > 0;
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'event_removed' => $eventRemoved]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 上傳附件（Z槽）
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'upload_attach') {
        $changeId = intval($_POST['change_id'] ?? 0);
        if (!$changeId) throw new Exception('缺少變更單ID');
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('上傳失敗（檔案錯誤）');
        }
        $base = trim(oc_setting($pdo, 'order_change_attach_dir', ''));
        if ($base === '') throw new Exception('尚未設定附件儲存路徑（請至設定）');
        $dir = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . $changeId . DIRECTORY_SEPARATOR;
        // 容錯：多檔同時上傳時可能同時 mkdir，建立後再次確認即可
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        if (!is_dir($dir)) throw new Exception('無法建立目錄：' . $dir);

        $orig = basename($_FILES['file']['name']);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $blocked = ['php','php3','php4','php5','phtml','phar','exe','bat','sh','cmd','asp','aspx','jsp','py','rb','htaccess'];
        if ($ext === '' || in_array($ext, $blocked)) throw new Exception('不允許此檔案類型');
        $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) throw new Exception('檔案寫入失敗');

        $sz = (int)$_FILES['file']['size'];
        $szStr = $sz >= 1048576 ? round($sz/1048576, 1) . ' MB' : ($sz >= 1024 ? round($sz/1024, 1) . ' KB' : $sz . ' B');
        $pdo->prepare("INSERT INTO order_change_attachment
            (change_id, filename, original_name, file_size, uploaded_by, uploaded_by_id)
            VALUES (?,?,?,?,?,?)")
            ->execute([$changeId, $fname, $orig, $szStr, $uname, $uid]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'original_name' => $orig, 'file_size' => $szStr]);
        exit;
    }

    // 列出附件
    if ($action === 'list_attach') {
        $changeId = intval($_POST['change_id'] ?? $_GET['change_id'] ?? 0);
        $st = $pdo->prepare("SELECT id, original_name, file_size, uploaded_by,
            DATE_FORMAT(uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at
            FROM order_change_attachment WHERE change_id=? ORDER BY id");
        $st->execute([$changeId]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // 取得某筆變更的通知對象（部門 / 人員 名稱）
    if ($action === 'change_notify') {
        $changeId = intval($_POST['change_id'] ?? $_GET['change_id'] ?? 0);
        $depts = []; $users = []; $all = false;
        $r = $pdo->prepare("SELECT live_event_id FROM order_change_log WHERE id=?");
        $r->execute([$changeId]);
        $leid = $r->fetchColumn();
        if ($leid) {
            $t = $pdo->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id=?");
            $t->execute([$leid]);
            foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['target_type'] === 'all') { $all = true; }
                elseif ($row['target_type'] === 'dept') {
                    $d = $pdo->prepare("SELECT name FROM department WHERE id=?"); $d->execute([(int)$row['target_id']]);
                    $nm = $d->fetchColumn(); if ($nm !== false) $depts[] = $nm;
                } elseif ($row['target_type'] === 'user') {
                    $u = $pdo->prepare("SELECT user_cname FROM user WHERE id=?"); $u->execute([(int)$row['target_id']]);
                    $nm = $u->fetchColumn(); if ($nm !== false) $users[] = $nm;
                }
            }
        }
        echo json_encode(['success' => true, 'all' => $all, 'depts' => $depts, 'users' => $users]);
        exit;
    }

    // 下載/預覽附件（串流，因 Z槽 非 web 路徑）
    if ($action === 'download_attach') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { http_response_code(404); exit; }
        $st = $pdo->prepare("SELECT a.filename, a.original_name, a.change_id FROM order_change_attachment a WHERE a.id=?");
        $st->execute([$id]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rec) { http_response_code(404); exit; }
        $base = trim(oc_setting($pdo, 'order_change_attach_dir', ''));
        $fp = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . $rec['change_id'] . DIRECTORY_SEPARATOR . $rec['filename'];
        if (!file_exists($fp)) { http_response_code(404); exit; }
        $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf' => 'application/pdf', 'jpg','jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'txt' => 'text/plain; charset=utf-8',
            default => 'application/octet-stream',
        };
        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        $disp = (in_array($ext, ['pdf','jpg','jpeg','png','gif','txt'])) ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($rec['original_name'] ?: $rec['filename']) . '"');
        header('Content-Length: ' . filesize($fp));
        readfile($fp);
        exit;
    }

    // 刪除附件（同時刪磁碟檔）
    if ($action === 'delete_attach') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) throw new Exception('缺少ID');
        $st = $pdo->prepare("SELECT filename, change_id FROM order_change_attachment WHERE id=?");
        $st->execute([$id]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        if ($rec) {
            $base = trim(oc_setting($pdo, 'order_change_attach_dir', ''));
            $fp = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . $rec['change_id'] . DIRECTORY_SEPARATOR . $rec['filename'];
            if (file_exists($fp)) @unlink($fp);
            $pdo->prepare("DELETE FROM order_change_attachment WHERE id=?")->execute([$id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 歷史：單筆訂單
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'history_order') {
        $oid = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
        if (!$oid) throw new Exception('未指定訂單ID');
        $st = $pdo->prepare("SELECT cl.id, cl.change_no, cl.changes_json, cl.note,
            cl.is_void, cl.void_reason, cl.voided_by,
            DATE_FORMAT(cl.voided_at,'%Y-%m-%d %H:%i') AS voided_at,
            cl.updated_by, DATE_FORMAT(cl.updated_at,'%Y-%m-%d %H:%i') AS updated_at,
            COALESCE(u.user_cname, cl.created_by) AS created_by,
            DATE_FORMAT(cl.created_at,'%Y-%m-%d %H:%i') AS created_at,
            (SELECT COUNT(*) FROM order_change_attachment a WHERE a.change_id=cl.id) AS att_count
            FROM order_change_log cl
            LEFT JOIN user u ON u.id = cl.created_by_id
            WHERE cl.order_id=? ORDER BY cl.id DESC");
        $st->execute([$oid]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 歷史：全部訂單（分頁 + 搜尋）
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'history_all') {
        $page = max(1, intval($_POST['page'] ?? 1));
        $size = intval($_POST['size'] ?? 10);
        if (!in_array($size, [5,10,20,50])) $size = 10;
        $kw = trim($_POST['kw'] ?? '');
        $where = '1=1'; $bind = [];
        if ($kw !== '') {
            $where .= " AND (cl.change_no LIKE ? OR cl.order_no LIKE ? OR cl.client_name LIKE ? OR cl.d_id LIKE ? OR cl.changes_json LIKE ? OR cl.note LIKE ? OR cl.created_by LIKE ?)";
            $like = '%' . $kw . '%';
            $bind = array_fill(0, 7, $like);
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM order_change_log cl WHERE $where");
        $cnt->execute($bind);
        $total = (int)$cnt->fetchColumn();
        $off = ($page - 1) * $size;
        $sql = "SELECT cl.id, cl.change_no, cl.order_id, cl.order_no, cl.client_name, cl.d_id, cl.changes_json, cl.note, cl.live_event_id,
            cl.is_void, cl.void_reason, cl.voided_by,
            DATE_FORMAT(cl.voided_at,'%Y-%m-%d %H:%i') AS voided_at,
            cl.updated_by, DATE_FORMAT(cl.updated_at,'%Y-%m-%d %H:%i') AS updated_at,
            COALESCE(u.user_cname, cl.created_by) AS created_by,
            DATE_FORMAT(cl.created_at,'%Y-%m-%d %H:%i') AS created_at,
            (SELECT COUNT(*) FROM order_change_attachment a WHERE a.change_id=cl.id) AS att_count
            FROM order_change_log cl
            LEFT JOIN user u ON u.id = cl.created_by_id
            WHERE $where ORDER BY cl.id DESC LIMIT $size OFFSET $off";
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        $data = $st->fetchAll(PDO::FETCH_ASSOC);

        // 批次帶出每筆的通知對象與已閱統計（直接顯示在列表，不需點明細）
        $leids = array_column($data, 'live_event_id');
        $tgMap = oc_targets_map($pdo, $leids);
        $readMap = oc_readers_map($pdo, $leids);
        list($allUsers, $deptUsers, $userNames) = oc_prepare_user_pools($pdo, $tgMap);
        // 部門名稱
        $deptNames = [];
        $needDeptIds = [];
        foreach ($tgMap as $rows) foreach ($rows as $r) if ($r['target_type'] === 'dept') $needDeptIds[(int)$r['target_id']] = 1;
        if (!empty($needDeptIds)) {
            $in = implode(',', array_keys($needDeptIds));
            foreach ($pdo->query("SELECT id, name FROM department WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $d)
                $deptNames[(int)$d['id']] = $d['name'];
        }
        foreach ($data as &$row) {
            $leid = (int)($row['live_event_id'] ?? 0);
            $tg = $tgMap[$leid] ?? [];
            $notify = ['all' => false, 'depts' => [], 'users' => []];
            $readers = $readMap[$leid] ?? [];
            $recips = oc_expand_recipients($tg, $allUsers, $deptUsers, $userNames);
            foreach ($tg as $r) {
                if ($r['target_type'] === 'all') $notify['all'] = true;
                elseif ($r['target_type'] === 'dept') $notify['depts'][] = $deptNames[(int)$r['target_id']] ?? ('#' . $r['target_id']);
                elseif ($r['target_type'] === 'user') {
                    $tuid = (int)$r['target_id'];
                    $notify['users'][] = ['name' => $userNames[$tuid] ?? ('#' . $tuid),
                                          'read' => isset($readers[$tuid]),
                                          'read_at' => $readers[$tuid] ?? null];
                }
            }
            $readCnt = 0;
            foreach (array_keys($recips) as $ruid) if (isset($readers[$ruid])) $readCnt++;
            $row['notify'] = $notify;
            $row['read_cnt'] = $readCnt;
            $row['tgt_cnt'] = count($recips);
        }
        unset($row);
        // 列印三固定元素一律動態取（ai-rules/16）：大標題＝本公司全名、表頭＝綁定 AS 文件的表單名稱、頁尾右下＝doc_no
        require_once __DIR__ . '/../common/asdoc_lib.php';
        require_once __DIR__ . '/../common/org_role_lib.php';
        $ocDoc = eg_asdoc_get($pdo, 'order_change');
        echo json_encode(['success' => true, 'data' => $data,
                          'total' => $total, 'page' => $page, 'size' => $size,
                          'company'  => eg_company_full_name($pdo),
                          'as_doc'   => $ocDoc,
                          'print_header' => $ocDoc ? $ocDoc['doc_name'] : '',
                          'print_footer' => $ocDoc ? $ocDoc['doc_no'] : '']);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 單筆變更的簽收（已閱）狀態：每位收件人是否已點「已閱」
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'change_read_status') {
        $changeId = intval($_POST['change_id'] ?? $_GET['change_id'] ?? 0);
        if (!$changeId) throw new Exception('缺少變更單ID');
        $st = $pdo->prepare("SELECT id, change_no, order_no, client_name, d_id, live_event_id,
            DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at
            FROM order_change_log WHERE id=?");
        $st->execute([$changeId]);
        $chg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chg) throw new Exception('找不到變更紀錄');
        $leid = (int)($chg['live_event_id'] ?? 0);
        $list = [];
        if ($leid) {
            $tgMap = oc_targets_map($pdo, [$leid]);
            $tg = $tgMap[$leid] ?? [];
            list($allUsers, $deptUsers, $userNames) = oc_prepare_user_pools($pdo, $tgMap);
            $recips = oc_expand_recipients($tg, $allUsers, $deptUsers, $userNames);
            $readers = oc_readers_map($pdo, [$leid])[$leid] ?? [];
            foreach ($recips as $ruid => $nm) {
                $list[] = ['name' => $nm, 'read' => isset($readers[$ruid]),
                           'read_at' => isset($readers[$ruid]) ? substr((string)$readers[$ruid], 0, 16) : null];
            }
            // 已閱在前（依時間新→舊），未閱在後（依姓名）
            usort($list, function ($a, $b) {
                if ($a['read'] !== $b['read']) return $a['read'] ? -1 : 1;
                if ($a['read']) return strcmp((string)$b['read_at'], (string)$a['read_at']);
                return strcmp((string)$a['name'], (string)$b['name']);
            });
        }
        $readCnt = count(array_filter($list, function ($r) { return $r['read']; }));
        echo json_encode(['success' => true, 'change' => $chg, 'notified' => ($leid > 0),
                          'list' => $list, 'read_cnt' => $readCnt, 'tgt_cnt' => count($list)]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 訂單列表徽章：多筆訂單的變更次數 + 最新變更的已閱進度（列表載入後批次呼叫）
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'orders_change_badge') {
        $ids = $_POST['order_ids'] ?? [];
        if (!is_array($ids)) $ids = array_filter(explode(',', (string)$ids));
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) { echo json_encode(['success' => true, 'data' => new stdClass()]); exit; }
        $in = implode(',', $ids);
        // 每筆訂單的變更次數與最新一筆變更
        // 排除已作廢的變更（作廢不列入次數與最新變更）
        $rows = $pdo->query("SELECT cl.order_id, cl.id, cl.change_no, cl.live_event_id, agg.cnt
            FROM order_change_log cl
            JOIN (SELECT order_id, COUNT(*) AS cnt, MAX(id) AS last_id
                  FROM order_change_log WHERE order_id IN ($in) AND is_void=0 GROUP BY order_id) agg
              ON agg.last_id = cl.id")->fetchAll(PDO::FETCH_ASSOC);
        $leids = array_column($rows, 'live_event_id');
        $tgMap = oc_targets_map($pdo, $leids);
        $readMap = oc_readers_map($pdo, $leids);
        list($allUsers, $deptUsers, $userNames) = oc_prepare_user_pools($pdo, $tgMap);
        $out = [];
        foreach ($rows as $r) {
            $leid = (int)($r['live_event_id'] ?? 0);
            $recips = oc_expand_recipients($tgMap[$leid] ?? [], $allUsers, $deptUsers, $userNames);
            $readers = $readMap[$leid] ?? [];
            $readCnt = 0;
            foreach (array_keys($recips) as $ruid) if (isset($readers[$ruid])) $readCnt++;
            $out[(string)$r['order_id']] = [
                'cnt' => (int)$r['cnt'], 'change_id' => (int)$r['id'], 'change_no' => $r['change_no'],
                'notified' => ($leid > 0), 'read_cnt' => $readCnt, 'tgt_cnt' => count($recips),
            ];
        }
        echo json_encode(['success' => true, 'data' => $out ?: new stdClass()]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 設定：讀取（含可選部門/人員清單供勾選）
    // ════════════════════════════════════════════════════════════════════════
    if ($action === 'get_settings') {
        if (!$is_admin) throw new Exception('權限不足（限管理者）');
        $cfg = json_decode(oc_setting($pdo, 'order_change_notify_targets', ''), true) ?: ['allow_all'=>false,'depts'=>[],'users'=>[]];
        $path = oc_setting($pdo, 'order_change_attach_dir', '');
        $depts = $pdo->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $userRows = oc_user_memberships($pdo, null);
        // 列印表頭/表尾不再手填：表頭＝綁定 AS 文件的表單名稱、表尾（頁尾右下）＝該文件編號（ai-rules/16）
        require_once __DIR__ . '/../common/asdoc_lib.php';
        $ocDoc = eg_asdoc_get($pdo, 'order_change');
        echo json_encode(['success' => true, 'config' => $cfg, 'attach_dir' => $path,
            'as_doc'   => $ocDoc,
            'as_docs'  => eg_asdoc_list($pdo),
            'print_header' => $ocDoc ? $ocDoc['doc_name'] : '',
            'print_footer' => $ocDoc ? $ocDoc['doc_no'] : '',
            'depts' => $depts, 'user_rows' => $userRows]);
        exit;
    }

    // 設定：儲存
    if ($action === 'save_settings') {
        if (!$is_admin) throw new Exception('權限不足（限管理者）');
        $allowAll = !empty($_POST['allow_all']) && $_POST['allow_all'] !== '0' && $_POST['allow_all'] !== 'false';
        $depts = $_POST['depts'] ?? [];
        $users = $_POST['users'] ?? [];
        if (!is_array($depts)) $depts = array_filter(explode(',', (string)$depts));
        if (!is_array($users)) $users = array_filter(explode(',', (string)$users));
        $cfg = ['allow_all' => $allowAll,
                'depts' => array_values(array_unique(array_map('intval', $depts))),
                'users' => array_values(array_unique(array_map('intval', $users)))];
        oc_save_setting($pdo, 'order_change_notify_targets', json_encode($cfg, JSON_UNESCAPED_UNICODE), $uid, $uname);
        $path = trim($_POST['attach_dir'] ?? '');
        oc_save_setting($pdo, 'order_change_attach_dir', $path, $uid, $uname);
        // 表頭/表尾不再手填（改由 AS 文件綁定推導）；這裡只存綁定的 as_document.id
        require_once __DIR__ . '/../common/asdoc_lib.php';
        if (isset($_POST['as_doc_id'])) eg_asdoc_save($pdo, 'order_change', (int)$_POST['as_doc_id'], (string)$uname);
        $ocDoc = eg_asdoc_get($pdo, 'order_change');
        echo json_encode(['success' => true, 'as_doc' => $ocDoc,
                          'print_header' => $ocDoc ? $ocDoc['doc_name'] : '',
                          'print_footer' => $ocDoc ? $ocDoc['doc_no'] : '']);
        exit;
    }

    throw new Exception('未知的動作：' . $action);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
