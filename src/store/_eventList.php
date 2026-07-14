<?php
// 公告/通知 列表 API（伺服器分頁、搜尋、來源篩選、CSV 匯出）
// 參數：page, size(5/10/20/50), kw, source, export(csv)
include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';

if (!isset($_SESSION['id'])) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];
$features = rbac_user_features($db, $uid);
if (!rbac_has($features, 'notice_view')) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'msg' => '無權限']); exit(); }
$can_manage = rbac_has($features, 'notice_edit') || rbac_has($features, 'notice_delete');
$is_admin   = rbac_has($features, 'all'); // 系統角色（管理員）

$export = $_GET['export'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$size   = (int)($_GET['size'] ?? 10);
if (!in_array($size, [5, 10, 20, 50], true)) $size = 10;
$kw     = trim($_GET['kw'] ?? '');
$source = trim($_GET['source'] ?? '');

// live_event.created_at（建立時間，供列表區分同日多筆公告先後）：首次使用自動補欄並以修改歷史回填
try {
    $cols = $db->query("SHOW COLUMNS FROM live_event LIKE 'created_at'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$cols) {
        $db->exec("ALTER TABLE live_event ADD COLUMN `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間'");
        // 舊資料：以修改歷史的 create 紀錄回填。
        // 注意：ALTER 的 DEFAULT CURRENT_TIMESTAMP 會把既有列填成「加欄當下」，故不可用 IS NULL 篩選，需無條件覆寫有歷史者
        $db->exec("UPDATE live_event le JOIN (
                       SELECT live_event_id, MIN(changed_at) t FROM live_event_history WHERE action='create' GROUP BY live_event_id
                   ) h ON h.live_event_id = le.id
                   SET le.created_at = h.t");
        // 品質異常單自動產生的通知無 create 歷史 → 以異常單建立時間回填
        $db->exec("UPDATE live_event le JOIN qa_abnormal_order q ON le.ref_type='QA' AND le.ref_id = q.id
                   SET le.created_at = q.created_at
                   WHERE NOT EXISTS (SELECT 1 FROM live_event_history hh WHERE hh.live_event_id = le.id AND hh.action='create')");
    }
} catch (Throwable $e) { error_log('[eventList] ensure created_at failed: ' . $e->getMessage()); }

// 隱藏來源設定（system_settings.notice_hidden_sources，JSON 陣列）：
// 「所有來源」檢視時排除；使用者於下拉指定該來源時仍可查看。推播通知完全不受此設定影響。
$hiddenSources = [];
try {
    $hs = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='notice_hidden_sources' LIMIT 1")->fetchColumn();
    $hiddenSources = array_values(array_filter((array)json_decode((string)$hs, true), 'is_string'));
} catch (Throwable $e) {}

// WHERE 條件
$where = '1=1';
$bind = [];
if ($kw !== '') {
    $where .= " AND (le.title LIKE :kw OR le.content LIKE :kw OR le.source LIKE :kw OR u.user_cname LIKE :kw)";
    $bind[':kw'] = '%' . $kw . '%';
}
if ($source !== '') {
    $where .= " AND le.source = :src"; $bind[':src'] = $source;
} elseif ($hiddenSources) {
    // 「所有來源」檢視：排除設定為隱藏的來源（僅影響列表顯示，推播照發）
    $i = 0;
    $ph = [];
    foreach ($hiddenSources as $hsrc) { $k = ':hs' . ($i++); $ph[] = $k; $bind[$k] = $hsrc; }
    $where .= " AND (le.source IS NULL OR le.source NOT IN (" . implode(',', $ph) . "))";
}

// 只有「系統角色（管理員，rbac all）」看得到全部公告；
// 其他人（含有 notice_edit/notice_delete 的主管）只能看到「與自己相關」的——
// 全體 / 自己身分(含兼任) / 自己部門(含兼任) / 本人為對象，或自己建立的 / 本人為共同編輯者(含本人部門)。
// 判定方式與側邊欄鈴鐺、手機頁(_myNotices.php)一致。
$myDeptIn = '-1';
if (!$is_admin) {
    $statusIds = [-1];
    $urow = $db->query("SELECT user_status, user_status2, user_status3 FROM `user` WHERE id = $uid")->fetch(PDO::FETCH_ASSOC);
    if ($urow) {
        foreach ($urow as $v) { if ($v !== null && $v !== '') $statusIds[] = (int)$v; }
    }
    $statusIn = implode(',', array_unique($statusIds));

    $deptIds = [-1];
    foreach ($db->query("SELECT department_id FROM user_department_position_map WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        if ($d !== null && $d !== '') $deptIds[] = (int)$d;
    }
    $deptIn = implode(',', array_unique($deptIds));
    $myDeptIn = $deptIn;

    $where .= " AND (le.created_by = $uid OR EXISTS (
        SELECT 1 FROM live_event_target t
        WHERE t.live_event_id = le.id AND (
            t.target_type = 'all'
            OR (t.target_type = 'status' AND t.target_id IN ($statusIn))
            OR (t.target_type = 'dept'   AND t.target_id IN ($deptIn))
            OR (t.target_type = 'user'   AND t.target_id = $uid)
        )
    ) OR EXISTS (
        SELECT 1 FROM live_event_editor ed
        WHERE ed.live_event_id = le.id AND (
            (ed.editor_type = 'user' AND ed.editor_id = $uid)
            OR (ed.editor_type = 'dept' AND ed.editor_id IN ($deptIn))
        )
    ))";
}

$baseFrom = "FROM live_event le LEFT JOIN user u ON u.id = le.created_by WHERE $where";

// 名稱對應（對象標籤）
$deptMap = []; foreach ($db->query("SELECT id,name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $r) $deptMap[$r['id']] = $r['name'];
$statMap = []; foreach ($db->query("SELECT id,title FROM user_status")->fetchAll(PDO::FETCH_ASSOC) as $r) $statMap[$r['id']] = $r['title'];
$userMap = []; foreach ($db->query("SELECT id,user_cname FROM user")->fetchAll(PDO::FETCH_ASSOC) as $r) $userMap[$r['id']] = $r['user_cname'];

function eg_targets_for($db, $ids, $deptMap, $statMap, $userMap) {
    if (empty($ids)) return [];
    $in = implode(',', array_map('intval', $ids));
    $rows = $db->query("SELECT live_event_id, target_type, target_id FROM live_event_target WHERE live_event_id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $t) {
        switch ($t['target_type']) {
            case 'all':    $o = ['cls' => 'eg-pill-all', 'label' => '全體']; break;
            case 'dept':   $o = ['cls' => 'eg-pill-dept', 'label' => ($deptMap[$t['target_id']] ?? $t['target_id'])]; break;
            case 'status': $o = ['cls' => 'eg-pill-status', 'label' => ($statMap[$t['target_id']] ?? $t['target_id'])]; break;
            case 'user':   $o = ['cls' => 'eg-pill-user', 'label' => ($userMap[$t['target_id']] ?? $t['target_id'])]; break;
            default: continue 2;
        }
        $out[$t['live_event_id']][] = $o;
    }
    return $out;
}

// 共同編輯者（live_event_editor）：回傳每筆公告的共同編輯者原始列與顯示名
function eg_editors_for($db, $ids, $deptMap, $userMap) {
    if (empty($ids)) return [];
    $in = implode(',', array_map('intval', $ids));
    $out = [];
    try {
        foreach ($db->query("SELECT live_event_id, editor_type, editor_id FROM live_event_editor WHERE live_event_id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $label = $e['editor_type'] === 'dept'
                ? (($deptMap[$e['editor_id']] ?? $e['editor_id']) . '(部門)')
                : ($userMap[$e['editor_id']] ?? $e['editor_id']);
            $out[$e['live_event_id']][] = ['type' => $e['editor_type'], 'id' => (int)$e['editor_id'], 'label' => $label];
        }
    } catch (Throwable $e) { /* 表不存在時忽略 */ }
    return $out;
}

// 已讀數（合併兩來源：純已閱 live_event_for_user + 回簽/回覆 live_event_response，去重人員）
function eg_reads_for($db, $ids) {
    if (empty($ids)) return [];
    $in = implode(',', array_map('intval', $ids));
    $m = [];
    $sql = "SELECT live_event_id, COUNT(DISTINCT user_id) c FROM (
                SELECT live_event_id, user_id FROM live_event_for_user WHERE oready_read=1 AND live_event_id IN ($in)
                UNION
                SELECT live_event_id, user_id FROM live_event_response WHERE read_at IS NOT NULL AND live_event_id IN ($in)
            ) t GROUP BY live_event_id";
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) $m[$r['live_event_id']] = (int)$r['c'];
    return $m;
}

// ── CSV 匯出（全部符合條件，不分頁）──────────────────────────────
if ($export === 'csv') {
    $stmt = $db->prepare("SELECT le.*, u.user_cname AS creator_name $baseFrom ORDER BY le.eventdate DESC, le.id DESC");
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($rows, 'id');
    $tg = eg_targets_for($db, $ids, $deptMap, $statMap, $userMap);
    $rd = eg_reads_for($db, $ids);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="notice_list_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['發布日期', '結束日期', '來源', '公告者', '對象', '標題', '內容', '已讀數']);
    foreach ($rows as $r) {
        $labels = array_map(function ($x) { return $x['label']; }, $tg[$r['id']] ?? []);
        fputcsv($out, [
            $r['eventdate'] . (!empty($r['created_at']) ? ' (建立 ' . substr($r['created_at'], 5, 11) . ')' : ''),
            $r['enddate'] ?: '', $r['source'] ?: '', $r['creator_name'] ?: '',
            implode('、', $labels), $r['title'], $r['content'], $rd[$r['id']] ?? 0,
        ]);
    }
    fclose($out);
    exit();
}

// ── 列印 / PDF（全部符合條件，開新視窗自動列印）──────────────────
if ($export === 'print') {
    $stmt = $db->prepare("SELECT le.*, u.user_cname AS creator_name $baseFrom ORDER BY le.eventdate DESC, le.id DESC");
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($rows, 'id');
    $tg = eg_targets_for($db, $ids, $deptMap, $statMap, $userMap);
    $rd = eg_reads_for($db, $ids);
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="utf-8"><title>公告 / 通知列表</title><style>'
        . 'body{font-family:"Microsoft JhengHei",sans-serif;margin:18px;color:#333;}'
        . 'h2{margin:0 0 4px;} .sub{color:#888;font-size:12px;margin-bottom:12px;}'
        . 'table{width:100%;border-collapse:collapse;font-size:12px;} th,td{border:1px solid #999;padding:5px 7px;text-align:left;vertical-align:top;}'
        . 'th{background:#eee;} .c{white-space:pre-line;}'
        . '@media print{.noprint{display:none;}}'
        . '</style></head><body>';
    echo '<button class="noprint" onclick="window.print()" style="float:right;padding:6px 14px;">列印 / 存成 PDF</button>';
    echo '<h2>公告 / 通知列表</h2><div class="sub">匯出時間：' . date('Y-m-d H:i') . '　共 ' . count($rows) . ' 筆'
        . ($kw !== '' ? '　搜尋：' . $h($kw) : '') . ($source !== '' ? '　來源：' . $h($source) : '') . '</div>';
    echo '<table><thead><tr><th>發布 / 結束</th><th>來源</th><th>公告者</th><th>對象</th><th>標題</th><th>內容</th><th>已讀</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $labels = array_map(function ($x) { return $x['label']; }, $tg[$r['id']] ?? []);
        echo '<tr>'
            . '<td>' . $h($r['eventdate'])
                . (!empty($r['created_at']) ? '<br><small style="color:#888;">建立 ' . $h(substr($r['created_at'], 5, 11)) . '</small>' : '')
                . ($r['enddate'] ? '<br>~ ' . $h($r['enddate']) : '') . '</td>'
            . '<td>' . $h($r['source']) . '</td>'
            . '<td>' . $h($r['creator_name']) . '</td>'
            . '<td>' . $h(implode('、', $labels)) . '</td>'
            . '<td>' . $h($r['title']) . '</td>'
            . '<td class="c">' . $h($r['content']) . '</td>'
            . '<td style="text-align:center;">' . ($rd[$r['id']] ?? 0) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table><script>window.onload=function(){setTimeout(function(){window.print();},300);};</script></body></html>';
    exit();
}

// ── 分頁 JSON ────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
try {
    $cnt = $db->prepare("SELECT COUNT(*) $baseFrom");
    $cnt->execute($bind);
    $total = (int)$cnt->fetchColumn();

    $off = ($page - 1) * $size;
    $stmt = $db->prepare("SELECT le.*, u.user_cname AS creator_name $baseFrom ORDER BY le.eventdate DESC, le.id DESC LIMIT $size OFFSET $off");
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($rows, 'id');
    $tg = eg_targets_for($db, $ids, $deptMap, $statMap, $userMap);
    $rd = eg_reads_for($db, $ids);
    $ed = eg_editors_for($db, $ids, $deptMap, $userMap);

    $myDeptIds = array_map('intval', explode(',', $myDeptIn));
    $data = [];
    foreach ($rows as $r) {
        // 本人是否為此公告的共同編輯者（直接指定或本人部門被指定）
        $isCoEditor = false;
        foreach (($ed[$r['id']] ?? []) as $e) {
            if (($e['type'] === 'user' && $e['id'] === $uid)
                || ($e['type'] === 'dept' && in_array($e['id'], $myDeptIds, true))) { $isCoEditor = true; break; }
        }
        $isCreator = ((int)$r['created_by'] === $uid);
        // 逐列權限：只有系統管理員可改/刪任何公告；其他人僅能改本人建立或本人為共同編輯者的公告
        $rowCanEdit   = $is_admin || ($isCreator && rbac_has($features, 'notice_edit')) || $isCoEditor;
        $rowCanDelete = $is_admin || ($isCreator && rbac_has($features, 'notice_delete'));
        // 公告者與共同編輯者分開回傳（前端分行顯示，避免與對象欄重疊）
        $edLabels = array_map(function ($x) { return $x['label']; }, $ed[$r['id']] ?? []);

        $data[] = [
            'id'        => (int)$r['id'],
            'eventdate' => $r['eventdate'],
            'enddate'   => $r['enddate'],
            'created_at'=> $r['created_at'] ?? null,
            'source'    => $r['source'] ?: '',
            'creator'   => $r['creator_name'] ?: '',
            'editors'   => $edLabels,
            'targets'   => $tg[$r['id']] ?? [],
            'title'     => $r['title'],
            'content'   => $r['content'],
            'reads'     => $rd[$r['id']] ?? 0,
            'ref_type'  => $r['ref_type'] ?? '',
            'ref_id'    => (int)($r['ref_id'] ?? 0),
            'can_edit'   => $rowCanEdit,
            'can_delete' => $rowCanDelete,
        ];
    }

    // 來源清單（給篩選下拉，僅首頁帶回即可；標記隱藏中的來源）
    $sources = [];
    if ($page === 1) {
        foreach ($db->query("SELECT DISTINCT source FROM live_event WHERE source IS NOT NULL AND source <> '' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $sources[] = ['name' => $s, 'hidden' => in_array($s, $hiddenSources, true)];
        }
    }

    echo json_encode([
        'ok' => true, 'total' => $total, 'page' => $page, 'size' => $size,
        'pages' => max(1, (int)ceil($total / $size)),
        'rows' => $data, 'can_manage' => $can_manage, 'sources' => $sources,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
