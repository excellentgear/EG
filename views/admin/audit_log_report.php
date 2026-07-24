<?php
/**
 * audit_log_report.php — 系統稽核紀錄（僅管理者）
 *
 * 分頁一「登入紀錄」：login_log（由 src/common/login_log.php 於登入成功/失敗時寫入）；
 *   顯示 誰、何時、來源 IP、電腦名稱（ip_hostname_cache 反查快取）、瀏覽器；失敗含原因。保留一年。
 * 分頁二「權限異動」：audit_log 中 target_type = rbac_role / rbac_user / rbac_position
 *   （由 src/store/Roles_API.php 各寫入 action 記錄），含 old/new 異動內容。
 * 權限：僅管理者（rbac 'all'，is_system=1 角色）可見；比照 page_visit_report 不建獨立 RBAC 模組。
 * 依 UI 規範：後端算完全部資料才分頁/排序/總計；分頁 5/10/20/50（右上）；CSV 匯出；PDF 用列印視窗。
 */
session_start();

require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/rbac.php';

$isAjax = isset($_GET['action']);

if (!isset($_SESSION['id'])) {
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>'未登入']); exit; }
    $_SESSION['lastpage'] = "../../views/admin/audit_log_report.php";
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$my_id = (int)$_SESSION['id'];

// 僅管理者可見；無權限分支不設 lastpage、不導回登入頁
$has_access = rbac_has(rbac_user_features($pdo, $my_id), 'all');

/* ══════════════════════ 共用小工具 ══════════════════════ */

function alr_user_names(PDO $pdo): array {
    // user_id → 顯示名稱（中文名優先，其次帳號）；user 表 latin1 欄位，抓回 PHP 組名字
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

function alr_hostnames(PDO $pdo, array $ips, int $budget = 0): array {
    // ip → 電腦名稱（或 null）。走 ip_hostname_cache（7 天有效）。
    // 反查 gethostbyaddr 對「同網段但無回應」的死 IP 在 Windows 會卡到 ~9 秒/次（NetBIOS 逾時），
    // 故列表請求一律 $budget=0＝只讀快取、絕不即時反查（避免整頁卡在載入中）；
    // 未命中的 IP 由前端另發 resolve_hosts 非阻塞請求補齊，CSV 匯出才給少量即時反查額度。
    $out = [];
    $ips = array_values(array_unique(array_filter($ips)));
    if (!$ips) return $out;
    $cached = [];
    try {
        $in = implode(',', array_fill(0, count($ips), '?'));
        $st = $pdo->prepare("SELECT ip, hostname, resolved_at FROM ip_hostname_cache WHERE ip IN ($in)");
        $st->execute($ips);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cached[$r['ip']] = $r;
    } catch (Exception $e) {}
    foreach ($ips as $ip) {
        $c = $cached[$ip] ?? null;
        if ($c && strtotime((string)$c['resolved_at']) > time() - 7 * 86400) { $out[$ip] = $c['hostname']; continue; }
        if ($budget <= 0) { $out[$ip] = $c ? $c['hostname'] : null; continue; }
        $budget--;
        $h = null;
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $r = @gethostbyaddr($ip);
            if (is_string($r) && $r !== '' && $r !== $ip) $h = mb_substr($r, 0, 100);
        }
        try {
            $pdo->prepare("INSERT INTO ip_hostname_cache (ip, hostname, resolved_at) VALUES (?,?,NOW())
                           ON DUPLICATE KEY UPDATE hostname = VALUES(hostname), resolved_at = NOW()")->execute([$ip, $h]);
        } catch (Exception $e) {}
        $out[$ip] = $h;
    }
    return $out;
}

function alr_browser(string $ua): string {
    if ($ua === '') return '';
    if (stripos($ua, 'Edg/') !== false)    return 'Edge';
    if (stripos($ua, 'Chrome') !== false)  return 'Chrome';
    if (stripos($ua, 'Firefox') !== false) return 'Firefox';
    if (stripos($ua, 'Safari') !== false)  return 'Safari';
    return '其他';
}

function alr_date_where(string $col, string $from, string $to, array &$where, array &$args): void {
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = "$col >= ?"; $args[] = $from . ' 00:00:00'; }
    if ($to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = "$col <= ?"; $args[] = $to . ' 23:59:59'; }
}

/* 權限異動：動作/對象/欄位 中文對照與異動內容組字 */
function alr_perm_labels(): array {
    return [
        'action' => ['create'=>'新增', 'update'=>'修改', 'delete'=>'刪除', 'assign'=>'指派', 'remove'=>'移除'],
        'type'   => ['rbac_role'=>'角色', 'rbac_user'=>'使用者', 'rbac_position'=>'職稱'],
        'field'  => ['role_name'=>'角色名稱', 'features'=>'功能碼', 'role'=>'角色', 'module'=>'模組'],
    ];
}
function alr_perm_changes(?string $json): string {
    if ($json === null || $json === '') return '';
    $arr = json_decode($json, true);
    if (!is_array($arr)) return '';
    $lab = alr_perm_labels();
    $parts = [];
    foreach ($arr as $c) {
        if (!is_array($c)) continue;
        $f = $lab['field'][$c['field'] ?? ''] ?? (string)($c['field'] ?? '');
        $old = ($c['old'] ?? null); $new = ($c['new'] ?? null);
        $old = ($old === null || $old === '') ? '（無）' : (string)$old;
        $new = ($new === null || $new === '') ? '（無）' : (string)$new;
        $parts[] = $f . '：' . $old . ' → ' . $new;
    }
    return implode('；', $parts);
}

/* ══════════════════════ AJAX ══════════════════════ */

if ($isAjax) {
    if (!$has_access) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>'無權限']); exit; }
    $action = $_GET['action'];
    $from = trim($_GET['from'] ?? '');
    $to   = trim($_GET['to'] ?? '');
    $kw   = trim($_GET['kw'] ?? '');

    try {
        /* ── 反查電腦名稱（前端渲染後非阻塞補齊；與列表分離避免卡住整頁） ── */
        if ($action === 'resolve_hosts') {
            header('Content-Type: application/json; charset=utf-8');
            $ipsRaw = explode(',', (string)($_GET['ips'] ?? ''));
            $ips = array_slice(array_values(array_unique(array_filter(array_map('trim', $ipsRaw)))), 0, 5); // 一次最多 5 個
            $hosts = alr_hostnames($pdo, $ips, count($ips));
            $out = [];
            foreach ($ips as $ip) $out[$ip] = $hosts[$ip] ?? null;
            echo json_encode(['success'=>true, 'hosts'=>$out]);
            exit;
        }

        /* ── 登入紀錄 ── */
        if ($action === 'login_list' || $action === 'export_login_csv') {
            $status = $_GET['status'] ?? 'all';                       // all / ok / fail
            $sort   = $_GET['sort'] ?? 'created_at';
            $dir    = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
            if (!in_array($sort, ['created_at','user_uname','success','ip'], true)) $sort = 'created_at';

            $names = alr_user_names($pdo);
            $where = []; $args = [];
            alr_date_where('created_at', $from, $to, $where, $args);
            if ($status === 'ok')   $where[] = "success = 1";
            if ($status === 'fail') $where[] = "success = 0";
            if ($kw !== '') {
                // 帳號 / IP 模糊比對；中文姓名比對走 names 對照（user 表 latin1 欄位不宜直接 LIKE 中文）
                $ids = [];
                foreach ($names as $uid => $n) { if (stripos($n, $kw) !== false) $ids[] = (int)$uid; }
                $cond = "(user_uname LIKE ? OR ip LIKE ?" . ($ids ? " OR user_id IN (" . implode(',', $ids) . ")" : "") . ")";
                $where[] = $cond; $args[] = "%$kw%"; $args[] = "%$kw%";
            }
            $w = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

            // 統計卡一律以全部資料計（非僅篩選/當前頁）
            $summary = ['today_users'=>0, 'today_logins'=>0, 'fail7'=>0, 'first_date'=>null];
            try {
                $summary['today_users']  = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM login_log WHERE success=1 AND created_at >= CURDATE()")->fetchColumn();
                $summary['today_logins'] = (int)$pdo->query("SELECT COUNT(*) FROM login_log WHERE success=1 AND created_at >= CURDATE()")->fetchColumn();
                $summary['fail7']        = (int)$pdo->query("SELECT COUNT(*) FROM login_log WHERE success=0 AND created_at >= CURDATE() - INTERVAL 6 DAY")->fetchColumn();
                $summary['first_date']   = $pdo->query("SELECT DATE(MIN(created_at)) FROM login_log")->fetchColumn() ?: null;
            } catch (Exception $e) {}

            $stc = $pdo->prepare("SELECT COUNT(*) FROM login_log$w");
            $stc->execute($args);
            $total = (int)$stc->fetchColumn();

            if ($action === 'export_login_csv') {
                $st = $pdo->prepare("SELECT * FROM login_log$w ORDER BY $sort $dir, id DESC");
                $st->execute($args);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                $hosts = alr_hostnames($pdo, array_column($rows, 'ip'), 8); // 匯出可接受少量即時反查
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="login_log_' . date('Ymd_His') . '.csv"');
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['時間','帳號','姓名','結果','失敗原因','IP','電腦名稱','瀏覽器']);
                foreach ($rows as $r) {
                    fputcsv($out, [$r['created_at'], $r['user_uname'],
                                   $names[(int)$r['user_id']] ?? '',
                                   $r['success'] ? '成功' : '失敗', $r['fail_reason'] ?: '',
                                   $r['ip'], $hosts[$r['ip']] ?? '', alr_browser((string)$r['user_agent'])]);
                }
                fclose($out);
                exit;
            }

            $page = max(1, intval($_GET['page'] ?? 1));
            $size = intval($_GET['size'] ?? 10);
            if (!in_array($size, [5,10,20,50], true)) $size = 10;
            $off = ($page - 1) * $size;
            $st = $pdo->prepare("SELECT * FROM login_log$w ORDER BY $sort $dir, id DESC LIMIT $size OFFSET $off");
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $hosts = alr_hostnames($pdo, array_column($rows, 'ip'));
            foreach ($rows as &$r) {
                $r['name']     = $names[(int)$r['user_id']] ?? '';
                $r['hostname'] = $hosts[$r['ip']] ?? null;
                $r['browser']  = alr_browser((string)$r['user_agent']);
                $r['success']  = (int)$r['success'];
                unset($r['user_agent']);
            }
            unset($r);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>true, 'summary'=>$summary, 'total'=>$total, 'page'=>$page, 'size'=>$size, 'rows'=>$rows]);
            exit;
        }

        /* ── 權限異動 ── */
        if ($action === 'perm_list' || $action === 'export_perm_csv') {
            $sort = $_GET['sort'] ?? 'created_at';
            $dir  = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
            if (!in_array($sort, ['created_at','operator','action_type','target_type'], true)) $sort = 'created_at';

            $where = ["target_type IN ('rbac_role','rbac_user','rbac_position')"]; $args = [];
            alr_date_where('created_at', $from, $to, $where, $args);
            if ($kw !== '') {
                $where[] = "(operator LIKE ? OR target_name LIKE ? OR changes LIKE ?)";
                $args[] = "%$kw%"; $args[] = "%$kw%"; $args[] = "%$kw%";
            }
            $w = ' WHERE ' . implode(' AND ', $where);
            $lab = alr_perm_labels();

            $stc = $pdo->prepare("SELECT COUNT(*) FROM audit_log$w");
            $stc->execute($args);
            $total = (int)$stc->fetchColumn();

            if ($action === 'export_perm_csv') {
                $st = $pdo->prepare("SELECT * FROM audit_log$w ORDER BY $sort $dir, id DESC");
                $st->execute($args);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="permission_change_log_' . date('Ymd_His') . '.csv"');
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['時間','操作者','動作','對象類型','對象','異動內容']);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    fputcsv($out, [$r['created_at'], $r['operator'],
                                   $lab['action'][$r['action_type']] ?? $r['action_type'],
                                   $lab['type'][$r['target_type']] ?? $r['target_type'],
                                   $r['target_name'] ?: $r['target_id'], alr_perm_changes($r['changes'])]);
                }
                fclose($out);
                exit;
            }

            $page = max(1, intval($_GET['page'] ?? 1));
            $size = intval($_GET['size'] ?? 10);
            if (!in_array($size, [5,10,20,50], true)) $size = 10;
            $off = ($page - 1) * $size;
            $st = $pdo->prepare("SELECT * FROM audit_log$w ORDER BY $sort $dir, id DESC LIMIT $size OFFSET $off");
            $st->execute($args);
            $rows = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'created_at'  => $r['created_at'],
                    'operator'    => $r['operator'],
                    'action'      => $lab['action'][$r['action_type']] ?? $r['action_type'],
                    'type'        => $lab['type'][$r['target_type']] ?? $r['target_type'],
                    'target'      => ($r['target_name'] !== null && $r['target_name'] !== '') ? $r['target_name'] : ('#' . $r['target_id']),
                    'changes_str' => alr_perm_changes($r['changes']),
                ];
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>true, 'total'=>$total, 'page'=>$page, 'size'=>$size, 'rows'=>$rows]);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
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
    <title>系統稽核紀錄</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        :root { --primary-color:#5B4636; --bg-color:#FAF6F0; --card-bg:#FFF; }
        body { background-color:var(--bg-color); font-family:"Segoe UI","Roboto",Arial,sans-serif; color:#4A3F35; }
        .right_col { background-color:var(--bg-color) !important; }

        .alr-tabs { margin-bottom:15px; border-bottom:2px solid #E8DCCB; display:flex; gap:4px; }
        .alr-tab { padding:9px 22px; font-size:14px; font-weight:700; color:#8a7a66; cursor:pointer;
            border:1px solid transparent; border-bottom:none; border-radius:8px 8px 0 0; user-select:none; }
        .alr-tab:hover { background:#F5EBDD; }
        .alr-tab.active { background:#fff; color:#B25E1F; border-color:#E8DCCB; border-bottom:2px solid #fff; margin-bottom:-2px; }

        .stats-container { display:flex; gap:12px; margin-bottom:15px; flex-wrap:wrap; }
        .stat-card { flex:1; min-width:150px; background:var(--card-bg); border-radius:8px; padding:13px 15px;
            box-shadow:0 2px 5px rgba(0,0,0,.05); border-left:4px solid transparent; }
        .stat-card .stat-value { font-size:21px; font-weight:800; color:var(--primary-color); white-space:nowrap; }
        .stat-card .stat-label { font-size:12px; color:#8a7a66; font-weight:600; }
        .stat-card .stat-sub { font-size:11px; color:#b3a590; }
        .stat-card.c-amber { border-left-color:#F0A24B; }
        .stat-card.c-sand  { border-left-color:#C89B6D; }
        .stat-card.c-coral { border-left-color:#DD5138; }
        .stat-card.c-brown { border-left-color:#8B5E3C; }

        .filter-bar { background:#fff; padding:10px; border-radius:8px; margin-bottom:15px;
            display:flex; gap:8px; align-items:center; flex-wrap:wrap; box-shadow:0 2px 5px rgba(0,0,0,.05); }
        .main-card { background:var(--card-bg); border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.05); padding:15px; }
        .table-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }

        table.alr-table thead th { background:#FBF4E9; color:#6b5745; font-weight:700; border-bottom:2px solid #EADFCE;
            padding:9px 6px; font-size:13px; white-space:nowrap; user-select:none; }
        table.alr-table thead th[data-sort] { cursor:pointer; }
        table.alr-table thead th .sort-ind { color:#B25E1F; margin-left:2px; }
        table.alr-table tbody td { padding:7px 6px; vertical-align:middle; border-bottom:1px solid #F5EDE2; font-size:13px; }
        table.alr-table tbody tr:hover { background:#FDF9F2; }
        table.alr-table tbody tr.fail-row { background:#FDF0EE; }
        table.alr-table tbody tr.fail-row:hover { background:#FBE5E1; }

        .badge-ok   { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#F7E0BD; color:#5B4636; white-space:nowrap; }
        .badge-fail { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#DD5138; color:#fff; white-space:nowrap; }
        .badge-act  { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#F5EBDD; color:#8B5E3C; white-space:nowrap; }
        .role-hint { color:#8a7a66; font-size:12px; cursor:pointer; }
        .no-access-box { background:#fff; border-radius:8px; padding:60px 20px; text-align:center; margin-top:40px; }
        .note-box { font-size:12px; color:#a08e78; margin-top:8px; line-height:1.7; }

        @media print {
            .left_col, .top_nav, .filter-bar, .stats-container, .table-toolbar, .note-box, .role-hint, .alr-tabs { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; background:#fff !important; }
            .main-card { box-shadow:none; padding:0; }
            table.alr-table thead th { cursor:default; }
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
          <i class="fa fa-lock" style="font-size:40px;color:#DD5138;"></i>
          <h3 style="margin-top:15px;">僅限管理者</h3>
          <p style="color:#8a7a66;">「系統稽核紀錄」僅系統管理員可檢視，如有需要請聯絡管理者。</p>
      </div>
<?php else: ?>
      <div class="page-title">
        <div class="title_left"><h3>系統稽核紀錄</h3></div>
        <div class="title_right" style="text-align:right; padding-top:12px;">
          <span class="role-hint" id="roleHint">
            <i class="fa fa-user"></i> 管理者
            <i class="fa fa-question-circle" style="margin-left:4px;"></i>
          </span>
        </div>
      </div>
      <div class="clearfix"></div>

      <div class="alr-tabs">
        <div class="alr-tab active" data-tab="login"><i class="fa fa-sign-in"></i> 登入紀錄</div>
        <div class="alr-tab" data-tab="perm"><i class="fa fa-key"></i> 權限異動</div>
      </div>

      <!-- ══════════ 分頁一：登入紀錄 ══════════ -->
      <div id="tab-login">
        <div class="stats-container">
          <div class="stat-card c-amber">
            <div class="stat-value" id="cardTodayUsers">–</div>
            <div class="stat-label">今日登入人數</div>
            <div class="stat-sub">成功登入的不同帳號數</div>
          </div>
          <div class="stat-card c-sand">
            <div class="stat-value" id="cardTodayLogins">–</div>
            <div class="stat-label">今日登入次數</div>
            <div class="stat-sub">成功登入合計</div>
          </div>
          <div class="stat-card c-coral">
            <div class="stat-value" id="cardFail7">–</div>
            <div class="stat-label">近 7 天失敗次數</div>
            <div class="stat-sub">連續多次失敗＝可能有人猜密碼</div>
          </div>
          <div class="stat-card c-brown">
            <div class="stat-value" id="cardSince" style="font-size:16px;">–</div>
            <div class="stat-label">紀錄起算日</div>
            <div class="stat-sub">保留一年，逾期自動清除</div>
          </div>
        </div>

        <div class="filter-bar">
          <label style="margin:0;font-size:12px;color:#8a7a66;">日期</label>
          <input type="date" id="lFrom" class="form-control input-sm eg-in eg-l" style="width:140px;">
          <span style="color:#b3a590;">～</span>
          <input type="date" id="lTo" class="form-control input-sm eg-in eg-l" style="width:140px;">
          <select id="lStatus" class="form-control input-sm eg-l" style="width:110px;">
            <option value="all">全部結果</option>
            <option value="ok">成功</option>
            <option value="fail">失敗</option>
          </select>
          <input type="text" id="lKw" class="form-control input-sm eg-in eg-l eg-live" placeholder="帳號 / 姓名 / IP（即時篩選）" style="width:220px;">
          <div style="margin-left:auto; display:flex; gap:8px;">
            <button class="btn btn-info btn-sm" id="btnLoginCsv"><i class="fa fa-file-excel-o"></i> 轉 CSV</button>
            <button class="btn btn-info btn-sm" id="btnLoginPrint"><i class="fa fa-print"></i> 列印 / PDF</button>
          </div>
        </div>

        <div class="main-card">
          <div class="table-toolbar">
            <div style="color:#8a7a66;font-size:12px;">
              每次登入（含<b>失敗</b>）記一筆；<span class="badge-fail">失敗</span>列標紅。點欄位標題可排序。
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <div id="lPageInfo" style="color:#8a7a66;font-size:12px;"></div>
              <select id="lSize" class="form-control input-sm" style="width:80px;">
                <option value="5">5</option><option value="10" selected>10</option>
                <option value="20">20</option><option value="50">50</option>
              </select>
              <button class="btn btn-default btn-xs" id="lPrev"><i class="fa fa-chevron-left"></i></button>
              <span id="lPageNum">1</span>
              <button class="btn btn-default btn-xs" id="lNext"><i class="fa fa-chevron-right"></i></button>
            </div>
          </div>
          <div style="overflow-x:auto;width:100%;">
            <table class="table alr-table" id="lTable">
              <thead>
                <tr>
                  <th data-sort="created_at">時間<span class="sort-ind"></span></th>
                  <th data-sort="user_uname">帳號<span class="sort-ind"></span></th>
                  <th>姓名</th>
                  <th data-sort="success">結果<span class="sort-ind"></span></th>
                  <th data-sort="ip">來源 IP<span class="sort-ind"></span></th>
                  <th>電腦名稱</th>
                  <th>瀏覽器</th>
                </tr>
              </thead>
              <tbody id="lTbody"><tr><td colspan="7" class="text-center text-muted">載入中...</td></tr></tbody>
            </table>
          </div>
          <div class="note-box">
            ．紀錄自本功能上線（<span id="noteSince">–</span>）起累積，上線前的登入無資料。<br>
            ．「電腦名稱」由來源 IP 於內網反查（快取 7 天）；查不到名稱時顯示「—」，仍可用 IP 對照是哪台電腦（內網 IP 固定）。<br>
            ．統計卡與總筆數一律以全部資料於後端計算，非僅當前頁。
          </div>
        </div>
      </div>

      <!-- ══════════ 分頁二：權限異動 ══════════ -->
      <div id="tab-perm" style="display:none;">
        <div class="filter-bar">
          <label style="margin:0;font-size:12px;color:#8a7a66;">日期</label>
          <input type="date" id="pFrom" class="form-control input-sm eg-in eg-p" style="width:140px;">
          <span style="color:#b3a590;">～</span>
          <input type="date" id="pTo" class="form-control input-sm eg-in eg-p" style="width:140px;">
          <input type="text" id="pKw" class="form-control input-sm eg-in eg-p eg-live" placeholder="操作者 / 對象 / 內容（即時篩選）" style="width:220px;">
          <div style="margin-left:auto; display:flex; gap:8px;">
            <button class="btn btn-info btn-sm" id="btnPermCsv"><i class="fa fa-file-excel-o"></i> 轉 CSV</button>
            <button class="btn btn-info btn-sm" id="btnPermPrint"><i class="fa fa-print"></i> 列印 / PDF</button>
          </div>
        </div>

        <div class="main-card">
          <div class="table-toolbar">
            <div style="color:#8a7a66;font-size:12px;">
              角色新增/改名/刪除、功能碼調整、使用者與職稱的角色指派/移除，皆含異動前後內容。點欄位標題可排序。
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <div id="pPageInfo" style="color:#8a7a66;font-size:12px;"></div>
              <select id="pSize" class="form-control input-sm" style="width:80px;">
                <option value="5">5</option><option value="10" selected>10</option>
                <option value="20">20</option><option value="50">50</option>
              </select>
              <button class="btn btn-default btn-xs" id="pPrev"><i class="fa fa-chevron-left"></i></button>
              <span id="pPageNum">1</span>
              <button class="btn btn-default btn-xs" id="pNext"><i class="fa fa-chevron-right"></i></button>
            </div>
          </div>
          <div style="overflow-x:auto;width:100%;">
            <table class="table alr-table" id="pTable">
              <thead>
                <tr>
                  <th data-sort="created_at">時間<span class="sort-ind"></span></th>
                  <th data-sort="operator">操作者<span class="sort-ind"></span></th>
                  <th data-sort="action_type">動作<span class="sort-ind"></span></th>
                  <th data-sort="target_type">對象類型<span class="sort-ind"></span></th>
                  <th>對象</th>
                  <th>異動內容（舊 → 新）</th>
                </tr>
              </thead>
              <tbody id="pTbody"><tr><td colspan="6" class="text-center text-muted">載入中...</td></tr></tbody>
            </table>
          </div>
          <div class="note-box">
            ．紀錄自本功能上線起累積；來源為 audit_log（與主檔管理共用的操作歷史表）。<br>
            ．「功能碼」異動列出的是代碼清單差異；對照各代碼意義請至「使用者權限」頁查看。
          </div>
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
</div>

<!-- 權限說明 Modal -->
<div class="modal fade" id="roleModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:520px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-question-circle"></i> 系統稽核紀錄 — 角色權限說明</h4></div>
      <div class="modal-body" style="font-size:13px;line-height:1.9;">
        <p><b>管理者</b>（is_system=1 系統管理員角色）：可檢視登入紀錄與權限異動、篩選排序、匯出 CSV / 列印。</p>
        <p style="color:#8a7a66;">本頁屬管理者群組頁面（比照「頁面使用統計」），不設獨立角色；非管理者無法開啟。</p>
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
    function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }

    /* ── 分頁切換 ── */
    $('.alr-tab').on('click', function(){
        $('.alr-tab').removeClass('active'); $(this).addClass('active');
        var t = $(this).data('tab');
        $('#tab-login').toggle(t === 'login');
        $('#tab-perm').toggle(t === 'perm');
        if (t === 'perm' && !pState.loaded) loadPerm();
    });

    /* ══════════ 登入紀錄 ══════════ */
    var lState = { sort:'created_at', dir:'desc', page:1, size:10 };

    function lParams(){
        return { from: $('#lFrom').val(), to: $('#lTo').val(), status: $('#lStatus').val(),
                 kw: $('#lKw').val().trim(), sort: lState.sort, dir: lState.dir,
                 page: lState.page, size: lState.size };
    }

    function loadLogin(){
        var p = lParams(); p.action = 'login_list';
        $.getJSON('audit_log_report.php', p, function(res){
            if (!res.success) { $('#lTbody').html('<tr><td colspan="7" class="text-center text-danger">' + esc(res.error) + '</td></tr>'); return; }
            $('#cardTodayUsers').text(res.summary.today_users);
            $('#cardTodayLogins').text(res.summary.today_logins);
            $('#cardFail7').text(res.summary.fail7);
            $('#cardSince').text(res.summary.first_date || '（尚無資料）');
            $('#noteSince').text(res.summary.first_date || '尚無資料');

            var totalPages = Math.max(1, Math.ceil(res.total / lState.size));
            if (lState.page > totalPages) { lState.page = totalPages; loadLogin(); return; }
            $('#lPageNum').text(lState.page + ' / ' + totalPages);
            $('#lPageInfo').text('共 ' + res.total + ' 筆');

            if (!res.rows.length) { $('#lTbody').html('<tr><td colspan="7" class="text-center text-muted">無符合資料</td></tr>'); return; }
            var html = '';
            res.rows.forEach(function(r){
                var badge = r.success == 1 ? '<span class="badge-ok">成功</span>'
                          : '<span class="badge-fail">失敗' + (r.fail_reason ? '：' + esc(r.fail_reason) : '') + '</span>';
                html += '<tr' + (r.success == 1 ? '' : ' class="fail-row"') + '>'
                     +  '<td>' + esc(r.created_at) + '</td>'
                     +  '<td>' + esc(r.user_uname) + '</td>'
                     +  '<td>' + esc(r.name || '') + '</td>'
                     +  '<td>' + badge + '</td>'
                     +  '<td>' + esc(r.ip) + '</td>'
                     +  '<td class="host-cell" data-ip="' + esc(r.ip) + '">'
                     +      (r.hostname ? esc(r.hostname) : '<span class="host-pending" style="color:#c9bba8;">—</span>') + '</td>'
                     +  '<td>' + esc(r.browser || '') + '</td>'
                     +  '</tr>';
            });
            $('#lTbody').html(html);
            $('#lTable thead th .sort-ind').text('');
            $('#lTable thead th[data-sort="' + lState.sort + '"] .sort-ind').text(lState.dir === 'asc' ? '▲' : '▼');
            resolvePendingHosts();
        }).fail(function(xhr){
            $('#lTbody').html('<tr><td colspan="7" class="text-center text-danger">載入失敗（' + xhr.status + '）：請重新整理或稍後再試</td></tr>');
        });
    }

    /* 反查電腦名稱：列表渲染後才發、非阻塞、每次最多 5 個；死 IP 反查慢也不會拖住表格顯示 */
    function resolvePendingHosts(){
        var ips = [];
        $('#lTbody .host-cell').has('.host-pending').each(function(){
            var ip = $(this).data('ip');
            if (ip && ips.indexOf(ip) < 0) ips.push(ip);
        });
        if (!ips.length) return;
        $.getJSON('audit_log_report.php', { action:'resolve_hosts', ips: ips.slice(0, 5).join(',') }, function(res){
            if (!res || !res.success || !res.hosts) return;
            $('#lTbody .host-cell').each(function(){
                var ip = $(this).data('ip');
                if (ip in res.hosts && res.hosts[ip]) $(this).text(res.hosts[ip]);
            });
        }); // 反查失敗保持「—」，不打擾使用者
    }

    $('#lTable thead').on('click', 'th[data-sort]', function(){
        var s = $(this).data('sort');
        if (lState.sort === s) lState.dir = (lState.dir === 'asc' ? 'desc' : 'asc');
        else { lState.sort = s; lState.dir = (s === 'user_uname' || s === 'ip') ? 'asc' : 'desc'; }
        lState.page = 1; loadLogin();
    });
    $('#lSize').on('change', function(){ lState.size = parseInt(this.value, 10); lState.page = 1; loadLogin(); });
    $('#lPrev').on('click', function(){ if (lState.page > 1) { lState.page--; loadLogin(); } });
    $('#lNext').on('click', function(){ lState.page++; loadLogin(); });
    $('#lFrom,#lTo,#lStatus').on('change', function(){ lState.page = 1; loadLogin(); });
    $('#btnLoginCsv').on('click', function(){
        var p = lParams(); p.action = 'export_login_csv'; delete p.page; delete p.size;
        location.href = 'audit_log_report.php?' + $.param(p);
    });
    $('#btnLoginPrint').on('click', function(){ window.print(); });

    /* ══════════ 權限異動 ══════════ */
    var pState = { sort:'created_at', dir:'desc', page:1, size:10, loaded:false };

    function pParams(){
        return { from: $('#pFrom').val(), to: $('#pTo').val(), kw: $('#pKw').val().trim(),
                 sort: pState.sort, dir: pState.dir, page: pState.page, size: pState.size };
    }

    function loadPerm(){
        pState.loaded = true;
        var p = pParams(); p.action = 'perm_list';
        $.getJSON('audit_log_report.php', p, function(res){
            if (!res.success) { $('#pTbody').html('<tr><td colspan="6" class="text-center text-danger">' + esc(res.error) + '</td></tr>'); return; }
            var totalPages = Math.max(1, Math.ceil(res.total / pState.size));
            if (pState.page > totalPages) { pState.page = totalPages; loadPerm(); return; }
            $('#pPageNum').text(pState.page + ' / ' + totalPages);
            $('#pPageInfo').text('共 ' + res.total + ' 筆');

            if (!res.rows.length) { $('#pTbody').html('<tr><td colspan="6" class="text-center text-muted">無符合資料</td></tr>'); return; }
            var html = '';
            res.rows.forEach(function(r){
                html += '<tr>'
                     +  '<td>' + esc(r.created_at) + '</td>'
                     +  '<td>' + esc(r.operator || '') + '</td>'
                     +  '<td><span class="badge-act">' + esc(r.action) + '</span></td>'
                     +  '<td>' + esc(r.type) + '</td>'
                     +  '<td>' + esc(r.target) + '</td>'
                     +  '<td style="word-break:break-all;">' + esc(r.changes_str || '') + '</td>'
                     +  '</tr>';
            });
            $('#pTbody').html(html);
            $('#pTable thead th .sort-ind').text('');
            $('#pTable thead th[data-sort="' + pState.sort + '"] .sort-ind').text(pState.dir === 'asc' ? '▲' : '▼');
        }).fail(function(xhr){
            $('#pTbody').html('<tr><td colspan="6" class="text-center text-danger">載入失敗（' + xhr.status + '）：請重新整理或稍後再試</td></tr>');
        });
    }

    $('#pTable thead').on('click', 'th[data-sort]', function(){
        var s = $(this).data('sort');
        if (pState.sort === s) pState.dir = (pState.dir === 'asc' ? 'desc' : 'asc');
        else { pState.sort = s; pState.dir = (s === 'operator') ? 'asc' : 'desc'; }
        pState.page = 1; loadPerm();
    });
    $('#pSize').on('change', function(){ pState.size = parseInt(this.value, 10); pState.page = 1; loadPerm(); });
    $('#pPrev').on('click', function(){ if (pState.page > 1) { pState.page--; loadPerm(); } });
    $('#pNext').on('click', function(){ pState.page++; loadPerm(); });
    $('#pFrom,#pTo').on('change', function(){ pState.page = 1; loadPerm(); });
    $('#btnPermCsv').on('click', function(){
        var p = pParams(); p.action = 'export_perm_csv'; delete p.page; delete p.size;
        location.href = 'audit_log_report.php?' + $.param(p);
    });
    $('#btnPermPrint').on('click', function(){ window.print(); });

    $('#roleHint').on('click', function(){ $('#roleModal').modal('show'); });

    /* 即時篩選：輸入停頓 400ms 自動查詢（依所屬分頁） */
    var liveTimer = null;
    $(document).on('input', '.eg-live', function(){
        var isL = $(this).hasClass('eg-l');
        clearTimeout(liveTimer);
        liveTimer = setTimeout(function(){
            if (isL) { lState.page = 1; loadLogin(); } else { pState.page = 1; loadPerm(); }
        }, 400);
    });

    /* UI 規範：雙擊清空（篩選欄＝同時解除篩選）/ 聚焦全選 / Enter 逐欄與末欄送出 */
    $(document).on('focus', '.eg-in', function(){ var el = this; setTimeout(function(){ try { el.select(); } catch(e){} }, 0); });
    $(document).on('dblclick', '.eg-in', function(){
        if (this.value !== '') {
            this.value = '';
            if ($(this).hasClass('eg-l')) { lState.page = 1; loadLogin(); } else { pState.page = 1; loadPerm(); }
        }
    });
    $(document).on('keydown', '.eg-in', function(e){
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var scope = $(this).hasClass('eg-l') ? '.eg-in.eg-l:visible' : '.eg-in.eg-p:visible';
        var ins = $(scope);
        var idx = ins.index(this);
        if (idx >= 0 && idx < ins.length - 1) ins.eq(idx + 1).focus();
        else if ($(this).hasClass('eg-l')) { lState.page = 1; loadLogin(); }
        else { pState.page = 1; loadPerm(); }
    });

    loadLogin();
})();
</script>
<?php endif; ?>
</body>
</html>
