<?php
/**
 * page_visit_report.php — 頁面使用統計報表（僅管理者）
 *
 * 資料來源：page_visit_stats（由 sideAndTopBarMenu.html 掛載的 page_visit_logger.php 依「頁 × 日 × 人」彙總）。
 * 內容：每個 page_path 的近30天次數、近90天次數、90天使用人數、最後使用時間；
 *       LEFT JOIN system_module_pages 標出「掛在選單上但 90 天零使用」的頁面（核心產出）。
 * 預設排序：近90天次數由小到大（沒人用的排最前面）。
 * 權限：僅管理者（rbac 'all' 功能碼，is_system=1 角色）可見；不建獨立 RBAC 模組（比照「項目控制」等管理者群組頁）。
 * 依 UI 規範：後端算完全部資料才分頁/排序/總計；分頁 5/10/20/50（右上）；CSV 匯出；PDF 用列印視窗。
 */
session_start();

require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/rbac.php';

$isAjax = isset($_GET['action']);

if (!isset($_SESSION['id'])) {
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>'未登入']); exit; }
    $_SESSION['lastpage'] = "../../views/admin/page_visit_report.php";
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$my_id = (int)$_SESSION['id'];

// 僅管理者可見（'all' 功能碼＝is_system=1 系統管理員角色）；無權限分支不設 lastpage、不導回登入頁
$has_access = rbac_has(rbac_user_features($pdo, $my_id), 'all');

/* ══════════════════════ 資料計算（量小，後端一次算完全部） ══════════════════════ */

function pvr_build_dataset(PDO $pdo): array {
    // 1. 統計彙總（近30/90天以「今天含當日往前推」計）
    $stats = $pdo->query("
        SELECT page_path,
               SUM(CASE WHEN visit_date >= CURDATE() - INTERVAL 29 DAY THEN visit_count ELSE 0 END) AS c30,
               SUM(CASE WHEN visit_date >= CURDATE() - INTERVAL 89 DAY THEN visit_count ELSE 0 END) AS c90,
               COUNT(DISTINCT CASE WHEN visit_date >= CURDATE() - INTERVAL 89 DAY THEN user_id END) AS users90,
               SUM(visit_count) AS c_all,
               MAX(last_visit_at) AS last_visit
        FROM page_visit_stats
        GROUP BY page_path")->fetchAll(PDO::FETCH_ASSOC);

    $byPath = [];
    foreach ($stats as $s) {
        $byPath[$s['page_path']] = [
            'page_path' => $s['page_path'],
            'menu_name' => null, 'group_name' => null, 'on_menu' => 0,
            'c30' => (int)$s['c30'], 'c90' => (int)$s['c90'],
            'users90' => (int)$s['users90'], 'c_all' => (int)$s['c_all'],
            'last_visit' => $s['last_visit'], 'users' => [],
        ];
    }

    // 2. 選單頁對照：page_url 去掉 /EGsystem 前綴後與 page_path 比對；選單上沒統計的頁補零列
    $menu = $pdo->query("
        SELECT p.page_name, p.page_url, g.group_name
        FROM system_module_pages p
        LEFT JOIN system_module_groups g ON g.group_id = p.group_id
        WHERE p.page_url IS NOT NULL AND p.page_url <> ''")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($menu as $m) {
        $url = str_replace('\\', '/', trim($m['page_url']));
        $url = preg_replace('/[?#].*$/', '', $url);              // 去 query string
        if (stripos($url, '/EGsystem/') === 0) $url = substr($url, strlen('/EGsystem'));
        if ($url === '' || substr($url, -4) !== '.php') continue; // 外部連結等不比對
        if (!isset($byPath[$url])) {
            $byPath[$url] = [
                'page_path' => $url,
                'menu_name' => $m['page_name'], 'group_name' => $m['group_name'], 'on_menu' => 1,
                'c30' => 0, 'c90' => 0, 'users90' => 0, 'c_all' => 0, 'last_visit' => null, 'users' => [],
            ];
        } else {
            $byPath[$url]['on_menu']    = 1;
            $byPath[$url]['menu_name']  = $m['page_name'];
            $byPath[$url]['group_name'] = $m['group_name'];
        }
    }

    // 3. 近90天各頁使用者名單（量小，抓回 PHP 組名字；user 表為 latin1 欄位，避免在 SQL 內混用定序）
    $names = pvr_user_names($pdo);
    $uRows = $pdo->query("
        SELECT DISTINCT page_path, user_id
        FROM page_visit_stats
        WHERE visit_date >= CURDATE() - INTERVAL 89 DAY")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($uRows as $u) {
        if (isset($byPath[$u['page_path']])) {
            $byPath[$u['page_path']]['users'][] = $names[(int)$u['user_id']] ?? ('#' . $u['user_id']);
        }
    }

    $rows = array_values($byPath);
    foreach ($rows as &$r) {
        $r['dead_menu'] = ($r['on_menu'] === 1 && $r['c90'] === 0) ? 1 : 0;   // 掛選單但90天零使用（核心產出）
        sort($r['users']);
        $r['users_str'] = implode('、', $r['users']);
    }
    unset($r);
    return $rows;
}

function pvr_user_names(PDO $pdo): array {
    // user_id → 顯示名稱（中文名優先，其次帳號）
    $names = [];
    try {
        foreach ($pdo->query("SELECT id, user_cname, user_uname FROM `user`") as $u) {
            $n = trim((string)$u['user_cname']);
            if ($n === '') $n = trim((string)$u['user_uname']);
            if ($n !== '') $names[(int)$u['id']] = $n;
        }
    } catch (Exception $e) {}
    return $names;
}

function pvr_filter(array $rows, string $kw, string $scope): array {
    if ($kw !== '') {
        $rows = array_values(array_filter($rows, function ($r) use ($kw) {
            return stripos($r['page_path'], $kw) !== false
                || ($r['menu_name'] !== null && stripos($r['menu_name'], $kw) !== false)
                || ($r['group_name'] !== null && stripos($r['group_name'], $kw) !== false)
                || ($r['users_str'] !== '' && stripos($r['users_str'], $kw) !== false);
        }));
    }
    if ($scope === 'menu')      $rows = array_values(array_filter($rows, fn($r) => $r['on_menu'] === 1));
    elseif ($scope === 'dead')  $rows = array_values(array_filter($rows, fn($r) => $r['dead_menu'] === 1));
    return $rows;
}

function pvr_sort(array &$rows, string $sort, string $dir): void {
    $desc = ($dir === 'desc');
    usort($rows, function ($a, $b) use ($sort, $desc) {
        $va = $a[$sort] ?? null; $vb = $b[$sort] ?? null;
        if ($va === null && $vb === null) $c = 0;
        elseif ($va === null) return 1;          // null 一律排最後
        elseif ($vb === null) return -1;
        else $c = (is_numeric($va) && is_numeric($vb)) ? ($va <=> $vb) : strcmp(strval($va), strval($vb));
        if ($c === 0) $c = strcmp($a['page_path'], $b['page_path']);  // 穩定排序
        return $desc ? -$c : $c;
    });
}

if ($isAjax) {
    if (!$has_access) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>'無權限']); exit; }

    $action = $_GET['action'];
    $kw     = trim($_GET['kw'] ?? '');
    $scope  = $_GET['scope'] ?? 'all';
    if (!in_array($scope, ['all','menu','dead'], true)) $scope = 'all';
    $sort   = $_GET['sort'] ?? 'c90';
    $dir    = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $allowed = ['page_path','menu_name','group_name','c30','c90','users90','users_str','c_all','last_visit','on_menu','dead_menu'];
    if (!in_array($sort, $allowed, true)) $sort = 'c90';

    try {
        /* ── 單一頁面的使用者明細（每人次數＋最初～最後使用區間） ── */
        if ($action === 'user_detail') {
            header('Content-Type: application/json; charset=utf-8');
            $pp = trim($_GET['page_path'] ?? '');
            $st = $pdo->prepare("
                SELECT user_id,
                       SUM(CASE WHEN visit_date >= CURDATE() - INTERVAL 29 DAY THEN visit_count ELSE 0 END) AS c30,
                       SUM(CASE WHEN visit_date >= CURDATE() - INTERVAL 89 DAY THEN visit_count ELSE 0 END) AS c90,
                       SUM(visit_count) AS c_all,
                       MIN(visit_date) AS first_date,
                       MAX(last_visit_at) AS last_visit,
                       COUNT(DISTINCT visit_date) AS days_used
                FROM page_visit_stats
                WHERE page_path = ?
                GROUP BY user_id
                ORDER BY c90 DESC, c_all DESC");
            $st->execute([$pp]);
            $list = $st->fetchAll(PDO::FETCH_ASSOC);
            $names = pvr_user_names($pdo);
            foreach ($list as &$l) {
                $l['name'] = $names[(int)$l['user_id']] ?? ('#' . $l['user_id']);
                foreach (['c30','c90','c_all','days_used'] as $k) $l[$k] = (int)$l[$k];
            }
            unset($l);
            echo json_encode(['success'=>true, 'page_path'=>$pp, 'rows'=>$list]);
            exit;
        }

        $all  = pvr_build_dataset($pdo);

        // 統計卡：一律以「全部資料」計，非僅當前頁/篩選
        $summary = [
            'pages'      => count($all),
            'menu_pages' => count(array_filter($all, fn($r) => $r['on_menu'] === 1)),
            'dead_menu'  => count(array_filter($all, fn($r) => $r['dead_menu'] === 1)),
            'visits90'   => array_sum(array_column($all, 'c90')),
            'first_date' => null,
        ];
        try { $summary['first_date'] = $pdo->query("SELECT MIN(visit_date) FROM page_visit_stats")->fetchColumn() ?: null; } catch (Exception $e) {}

        $rows = pvr_filter($all, $kw, $scope);
        pvr_sort($rows, $sort, $dir);

        if ($action === 'export_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="page_visit_report_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM 讓 Excel 正確顯示中文
            fputcsv($out, ['頁面路徑','選單名稱','選單群組','是否在選單','近30天次數','近90天次數','90天使用人數','使用者（近90天）','累計次數','最後使用時間','選單頁90天零使用']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['page_path'], $r['menu_name'], $r['group_name'], $r['on_menu'] ? '是' : '否',
                               $r['c30'], $r['c90'], $r['users90'], $r['users_str'], $r['c_all'],
                               $r['last_visit'] ?: '（從未使用）', $r['dead_menu'] ? '★' : '']);
            }
            fclose($out);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        if ($action === 'list') {
            // 分頁（後端切頁；統計已於上方以全量算完）
            $page = max(1, intval($_GET['page'] ?? 1));
            $size = intval($_GET['size'] ?? 10);
            if (!in_array($size, [5, 10, 20, 50], true)) $size = 10;
            $total = count($rows);
            $paged = array_slice($rows, ($page - 1) * $size, $size);
            echo json_encode(['success'=>true, 'summary'=>$summary, 'total'=>$total, 'page'=>$page, 'size'=>$size, 'rows'=>$paged]);
            exit;
        }

        echo json_encode(['success'=>false, 'error'=>'未知動作']);
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>頁面使用統計</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        :root { --primary-color:#2A3F54; --bg-color:#F4F7FC; --card-bg:#FFF; }
        body { background-color:var(--bg-color); font-family:"Segoe UI","Roboto",Arial,sans-serif; color:#495057; }
        .right_col { background-color:var(--bg-color) !important; }

        .stats-container { display:flex; gap:12px; margin-bottom:15px; flex-wrap:wrap; }
        .stat-card { flex:1; min-width:150px; background:var(--card-bg); border-radius:8px; padding:13px 15px;
            box-shadow:0 2px 5px rgba(0,0,0,.05); border-left:4px solid transparent; }
        .stat-card[data-scope] { cursor:pointer; transition:transform .1s, box-shadow .1s; }
        .stat-card[data-scope]:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(0,0,0,.1); }
        .stat-card.c-pages.active { box-shadow:0 0 0 3px #5B8DEF; }
        .stat-card.c-menu.active  { box-shadow:0 0 0 3px #1ABB9C; }
        .stat-card.c-dead.active  { box-shadow:0 0 0 3px #E74C3C; }
        .stat-card .stat-value { font-size:21px; font-weight:800; color:var(--primary-color); white-space:nowrap; }
        .stat-card .stat-label { font-size:12px; color:#888; font-weight:600; }
        .stat-card .stat-sub { font-size:11px; color:#aaa; }
        .stat-card.c-pages { border-left-color:#5B8DEF; }
        .stat-card.c-menu  { border-left-color:#1ABB9C; }
        .stat-card.c-dead  { border-left-color:#E74C3C; }
        .stat-card.c-vis   { border-left-color:#8e44ad; }

        .filter-bar { background:#fff; padding:10px; border-radius:8px; margin-bottom:15px;
            display:flex; gap:8px; align-items:center; flex-wrap:wrap; box-shadow:0 2px 5px rgba(0,0,0,.05); }
        .main-card { background:var(--card-bg); border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.05); padding:15px; }
        .table-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }

        table.pvr-table thead th { background:#F8F9FA; color:#555; font-weight:700; border-bottom:2px solid #E9ECEF;
            padding:9px 6px; font-size:13px; white-space:nowrap; cursor:pointer; user-select:none; }
        table.pvr-table thead th .sort-ind { color:#5B8DEF; margin-left:2px; }
        table.pvr-table tbody td { padding:7px 6px; vertical-align:middle; border-bottom:1px solid #F1F3F5; font-size:13px; }
        table.pvr-table tbody tr:hover { background:#FAFBFE; }
        table.pvr-table tbody tr.dead-row { background:#FDF3F2; }
        table.pvr-table tbody tr.dead-row:hover { background:#FBE9E7; }
        td.num, th.num { text-align:right; }

        .badge-dead { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#FDEDEC; color:#c0392b; white-space:nowrap; }
        .badge-menu { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#E8F8F3; color:#0e8c73; white-space:nowrap; }
        .badge-off  { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#F0F2F4; color:#8a94a0; white-space:nowrap; }
        .scope-toggle .btn.active { background:var(--primary-color); color:#fff; }
        .user-link { color:#1a7abf; cursor:pointer; border-bottom:1px dashed #5B8DEF; }
        .user-link:hover { color:#5B8DEF; }
        .role-hint { color:#888; font-size:12px; cursor:pointer; }
        .no-access-box { background:#fff; border-radius:8px; padding:60px 20px; text-align:center; margin-top:40px; }
        .note-box { font-size:12px; color:#8a94a0; margin-top:8px; line-height:1.7; }

        @media print {
            .left_col, .top_nav, .filter-bar, .stats-container, .table-toolbar, .note-box, .role-hint { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; background:#fff !important; }
            .main-card { box-shadow:none; padding:0; }
            table.pvr-table thead th { cursor:default; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
<?php if (!$has_access): ?>
      <div class="no-access-box">
          <i class="fa fa-lock" style="font-size:40px;color:#e74c3c;"></i>
          <h3 style="margin-top:15px;">僅限管理者</h3>
          <p style="color:#888;">「頁面使用統計」僅系統管理員可檢視，如有需要請聯絡管理者。</p>
      </div>
<?php else: ?>
      <div class="page-title">
        <div class="title_left"><h3>頁面使用統計</h3></div>
        <div class="title_right" style="text-align:right; padding-top:12px;">
          <span class="role-hint" id="roleHint">
            <i class="fa fa-user"></i> 管理者
            <i class="fa fa-question-circle" style="margin-left:4px;"></i>
          </span>
        </div>
      </div>
      <div class="clearfix"></div>

      <div class="stats-container">
        <div class="stat-card c-pages active" data-scope="all">
          <div class="stat-value" id="cardPages">–</div>
          <div class="stat-label">追蹤中頁面數（點我看全部）</div>
          <div class="stat-sub" id="cardSince">統計起算日 –</div>
        </div>
        <div class="stat-card c-menu" data-scope="menu">
          <div class="stat-value" id="cardMenu">–</div>
          <div class="stat-label">選單頁面數（點我篩選）</div>
          <div class="stat-sub">system_module_pages 掛載中</div>
        </div>
        <div class="stat-card c-dead" data-scope="dead">
          <div class="stat-value" id="cardDead">–</div>
          <div class="stat-label">選單頁 90 天零使用（點我篩選）</div>
          <div class="stat-sub">下架/改良候選（核心指標）</div>
        </div>
        <div class="stat-card c-vis">
          <div class="stat-value" id="cardVisits">–</div>
          <div class="stat-label">近 90 天總開啟次數</div>
          <div class="stat-sub">全站合計</div>
        </div>
      </div>

      <div class="filter-bar">
        <input type="text" id="fKw" class="form-control input-sm eg-in eg-live" placeholder="頁面路徑 / 選單名稱 / 群組 / 使用者（即時篩選）" style="width:270px;">
        <div class="btn-group scope-toggle" style="margin-left:6px;">
          <button class="btn btn-default btn-sm active" data-scope="all">全部頁面</button>
          <button class="btn btn-default btn-sm" data-scope="menu">僅選單頁</button>
          <button class="btn btn-default btn-sm" data-scope="dead">選單零使用</button>
        </div>
        <div style="margin-left:auto; display:flex; gap:8px;">
          <button class="btn btn-info btn-sm" id="btnExportCsv"><i class="fa fa-file-excel-o"></i> 轉 CSV</button>
          <button class="btn btn-info btn-sm" id="btnPrint"><i class="fa fa-print"></i> 列印 / PDF</button>
        </div>
      </div>

      <div class="main-card">
        <div class="table-toolbar">
          <div style="color:#888;font-size:12px;">
            預設依<b>近90天次數由小到大</b>排——沒人用的排最前面；<span class="badge-dead">選單零使用</span>列標紅。點欄位標題可排序。
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <div id="pageInfo" style="color:#888;font-size:12px;"></div>
            <select id="pageSizeSelect" class="form-control input-sm" style="width:80px;">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="20">20</option>
              <option value="50">50</option>
            </select>
            <button class="btn btn-default btn-xs" id="btnPrevPage"><i class="fa fa-chevron-left"></i></button>
            <span id="pageNum">1</span>
            <button class="btn btn-default btn-xs" id="btnNextPage"><i class="fa fa-chevron-right"></i></button>
          </div>
        </div>
        <div style="overflow-x:auto;width:100%;">
          <table class="table pvr-table" id="pvrTable">
            <thead>
              <tr>
                <th data-sort="page_path">頁面路徑<span class="sort-ind"></span></th>
                <th data-sort="menu_name">選單名稱<span class="sort-ind"></span></th>
                <th data-sort="group_name">選單群組<span class="sort-ind"></span></th>
                <th class="num" data-sort="c30">近30天<span class="sort-ind"></span></th>
                <th class="num" data-sort="c90">近90天<span class="sort-ind"></span></th>
                <th class="num" data-sort="users90">90天人數<span class="sort-ind"></span></th>
                <th data-sort="users_str">使用者（近90天）<span class="sort-ind"></span></th>
                <th class="num" data-sort="c_all">累計<span class="sort-ind"></span></th>
                <th data-sort="last_visit">最後使用<span class="sort-ind"></span></th>
                <th data-sort="dead_menu">狀態<span class="sort-ind"></span></th>
              </tr>
            </thead>
            <tbody id="pvrTbody"><tr><td colspan="10" class="text-center text-muted">載入中...</td></tr></tbody>
          </table>
        </div>
        <div class="note-box">
          ．記錄方式：所有走共用側欄的頁面每次「開啟」記一筆（頁 × 日 × 人 彙總）；AJAX 請求不計。統計自起算日起累積，<b>累積 2–3 個月後的近90天數字才有代表性</b>。<br>
          ．「選單零使用」＝掛在 system_module_pages 選單上、但近 90 天沒有任何人開啟過——為下架或改良的頭號候選。<br>
          ．未掛選單但有使用紀錄的頁面（直接輸入網址、由其他頁跳轉）也會列出，選單名稱為空。<br>
          ．統計卡與總筆數一律以「全部資料」於後端計算，非僅當前頁。
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
</div>

<!-- 使用者明細 Modal -->
<div class="modal fade" id="userModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:680px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-users"></i> 使用者明細 <small id="umSub" style="color:#888;word-break:break-all;"></small></h4></div>
      <div class="modal-body" id="umBody" style="max-height:70vh;overflow-y:auto;">載入中...</div>
    </div>
  </div>
</div>

<!-- 權限說明 Modal -->
<div class="modal fade" id="roleModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:520px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-question-circle"></i> 頁面使用統計 — 角色權限說明</h4></div>
      <div class="modal-body" style="font-size:13px;line-height:1.9;">
        <p><b>管理者</b>（is_system=1 系統管理員角色）：可檢視本頁、排序篩選、匯出 CSV / 列印。</p>
        <p style="color:#888;">本頁屬管理者群組頁面（比照「項目控制」），不設獨立角色；非管理者無法開啟。</p>
      </div>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<?php if ($has_access): ?>
<script>
(function(){
    var state = { scope:'all', sort:'c90', dir:'asc', page:1, size:10 };

    function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }

    function params(){
        return { kw: $('#fKw').val().trim(), scope: state.scope,
                 sort: state.sort, dir: state.dir, page: state.page, size: state.size };
    }

    function load(){
        var p = params(); p.action = 'list';
        $.getJSON('page_visit_report.php', p, function(res){
            if (!res.success) { $('#pvrTbody').html('<tr><td colspan="10" class="text-center text-danger">' + esc(res.error) + '</td></tr>'); return; }

            $('#cardPages').text(res.summary.pages);
            $('#cardMenu').text(res.summary.menu_pages);
            $('#cardDead').text(res.summary.dead_menu);
            $('#cardVisits').text(res.summary.visits90);
            $('#cardSince').text('統計起算日 ' + (res.summary.first_date || '（尚無資料）'));

            var totalPages = Math.max(1, Math.ceil(res.total / state.size));
            if (state.page > totalPages) { state.page = totalPages; load(); return; }
            $('#pageNum').text(state.page + ' / ' + totalPages);
            $('#pageInfo').text('共 ' + res.total + ' 筆');

            if (!res.rows.length) { $('#pvrTbody').html('<tr><td colspan="10" class="text-center text-muted">無符合資料</td></tr>'); return; }
            var html = '';
            res.rows.forEach(function(r, i){
                var badge = r.dead_menu == 1 ? '<span class="badge-dead">選單零使用</span>'
                          : (r.on_menu == 1 ? '<span class="badge-menu">選單頁</span>' : '<span class="badge-off">未掛選單</span>');
                var users = r.users90 > 0
                          ? '<span class="user-link" data-idx="' + i + '" title="點我看每人使用次數與期間">' + esc(r.users_str) + '</span>'
                          : '<span style="color:#c5ccd3;">—</span>';
                html += '<tr' + (r.dead_menu == 1 ? ' class="dead-row"' : '') + '>'
                     +  '<td style="word-break:break-all;">' + esc(r.page_path) + '</td>'
                     +  '<td>' + esc(r.menu_name || '') + '</td>'
                     +  '<td>' + esc(r.group_name || '') + '</td>'
                     +  '<td class="num">' + r.c30 + '</td>'
                     +  '<td class="num"><b>' + r.c90 + '</b></td>'
                     +  '<td class="num">' + r.users90 + '</td>'
                     +  '<td style="max-width:220px;">' + users + '</td>'
                     +  '<td class="num">' + r.c_all + '</td>'
                     +  '<td>' + esc(r.last_visit || '（從未使用）') + '</td>'
                     +  '<td>' + badge + '</td>'
                     +  '</tr>';
            });
            state.lastRows = res.rows;
            $('#pvrTbody').html(html);

            $('#pvrTable thead th .sort-ind').text('');
            $('#pvrTable thead th[data-sort="' + state.sort + '"] .sort-ind').text(state.dir === 'asc' ? '▲' : '▼');
        });
    }

    /* 排序 */
    $('#pvrTable thead').on('click', 'th[data-sort]', function(){
        var s = $(this).data('sort');
        if (state.sort === s) state.dir = (state.dir === 'asc' ? 'desc' : 'asc');
        else { state.sort = s; state.dir = (s === 'page_path' || s === 'menu_name' || s === 'group_name' || s === 'users_str') ? 'asc' : 'desc'; }
        state.page = 1; load();
    });

    /* 範圍切換（按鈕與統計卡雙向連動） */
    function setScope(s){
        state.scope = s; state.page = 1;
        $('.scope-toggle .btn').removeClass('active').filter('[data-scope="' + s + '"]').addClass('active');
        $('.stat-card[data-scope]').removeClass('active').filter('[data-scope="' + s + '"]').addClass('active');
        load();
    }
    $('.scope-toggle .btn').on('click', function(){ setScope($(this).data('scope')); });
    $('.stat-card[data-scope]').on('click', function(){ setScope($(this).data('scope')); });

    /* 使用者明細：每人次數＋最初～最後使用區間 */
    $('#pvrTbody').on('click', '.user-link', function(){
        var r = (state.lastRows || [])[$(this).data('idx')];
        if (!r) return;
        $('#umSub').text(r.page_path + (r.menu_name ? '（' + r.menu_name + '）' : ''));
        $('#umBody').html('載入中...');
        $('#userModal').modal('show');
        $.getJSON('page_visit_report.php', { action:'user_detail', page_path: r.page_path }, function(res){
            if (!res.success) { $('#umBody').html('<span class="text-danger">' + esc(res.error) + '</span>'); return; }
            if (!res.rows.length) { $('#umBody').html('<span class="text-muted">尚無使用紀錄</span>'); return; }
            var h = '<table class="table" style="margin-bottom:0;">'
                  + '<thead><tr><th>使用者</th><th class="num" style="text-align:right;">近30天</th>'
                  + '<th class="num" style="text-align:right;">近90天</th><th class="num" style="text-align:right;">累計</th>'
                  + '<th class="num" style="text-align:right;">使用天數</th><th>使用期間（最初～最後）</th></tr></thead><tbody>';
            res.rows.forEach(function(u){
                var range = (u.first_date || '?') + ' ～ ' + (u.last_visit ? String(u.last_visit).slice(0, 16) : '?');
                h += '<tr><td>' + esc(u.name) + '</td>'
                   + '<td style="text-align:right;">' + u.c30 + '</td>'
                   + '<td style="text-align:right;"><b>' + u.c90 + '</b></td>'
                   + '<td style="text-align:right;">' + u.c_all + '</td>'
                   + '<td style="text-align:right;">' + u.days_used + '</td>'
                   + '<td>' + esc(range) + '</td></tr>';
            });
            h += '</tbody></table>'
               + '<div style="font-size:12px;color:#8a94a0;margin-top:8px;">使用天數＝有開啟過本頁的不同日數；期間為統計起算日後的最初～最後使用時間。</div>';
            $('#umBody').html(h);
        });
    });

    /* 分頁 */
    $('#pageSizeSelect').on('change', function(){ state.size = parseInt(this.value, 10); state.page = 1; load(); });
    $('#btnPrevPage').on('click', function(){ if (state.page > 1) { state.page--; load(); } });
    $('#btnNextPage').on('click', function(){ state.page++; load(); });   // 超頁由 load() 內收斂

    /* 匯出 / 列印 */
    $('#btnExportCsv').on('click', function(){
        var p = params(); p.action = 'export_csv'; delete p.page; delete p.size;
        location.href = 'page_visit_report.php?' + $.param(p);
    });
    $('#btnPrint').on('click', function(){ window.print(); });

    $('#roleHint').on('click', function(){ $('#roleModal').modal('show'); });

    /* 即時篩選：輸入停頓 400ms 自動查詢 */
    var liveTimer = null;
    $(document).on('input', '.eg-live', function(){
        clearTimeout(liveTimer);
        liveTimer = setTimeout(function(){ state.page = 1; load(); }, 400);
    });

    /* UI 規範：雙擊清空（篩選欄＝同時解除篩選）/ 聚焦全選 / Enter 逐欄與末欄送出 */
    $(document).on('focus', '.eg-in', function(){ var el = this; setTimeout(function(){ try { el.select(); } catch(e){} }, 0); });
    $(document).on('dblclick', '.eg-in', function(){
        if (this.value !== '') { this.value = ''; state.page = 1; load(); }
    });
    $(document).on('keydown', '.eg-in', function(e){
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var ins = $('.eg-in:visible');
        var idx = ins.index(this);
        if (idx >= 0 && idx < ins.length - 1) ins.eq(idx + 1).focus();
        else { state.page = 1; load(); }
    });

    load();
})();
</script>
<?php endif; ?>
</body>
</html>
