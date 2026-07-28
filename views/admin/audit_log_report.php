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

// 本頁 JS 為內嵌，改版後不可讓瀏覽器續用舊快取（曾因舊版反查DNS卡死而整頁「載入中」，已修但快取仍會重現舊行為）
if (!$isAjax) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
}

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

/* ══════════════════════ 頁面使用統計（第三分頁；資料量小，後端一次算完全部） ══════════════════════ */

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
    $names = alr_user_names($pdo);
    $uRows = $pdo->query("
        SELECT DISTINCT page_path, user_id
        FROM page_visit_stats
        WHERE visit_date >= CURDATE() - INTERVAL 89 DAY")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($uRows as $u) {
        if (isset($byPath[$u['page_path']])) {
            $byPath[$u['page_path']]['users'][] = $names[(int)$u['user_id']] ?? ('#' . $u['user_id']);
        }
    }

    // 4. 未掛選單頁面的自訂別名（管理員設定，方便搜尋辨識）：套進 menu_name 供顯示與搜尋
    $aliasMap = [];
    try {
        foreach ($pdo->query("SELECT page_path, alias FROM page_visit_alias") as $a) {
            $aliasMap[$a['page_path']] = (string)$a['alias'];
        }
    } catch (Exception $e) {}

    $rows = array_values($byPath);
    foreach ($rows as &$r) {
        $r['dead_menu'] = ($r['on_menu'] === 1 && $r['c90'] === 0) ? 1 : 0;   // 掛選單但90天零使用（核心產出）
        $r['alias'] = $aliasMap[$r['page_path']] ?? '';
        // 未掛選單且有別名 → 以別名當顯示名稱（menu_name 同時供表格顯示、搜尋比對、CSV 匯出）
        if ($r['on_menu'] === 0 && $r['alias'] !== '') $r['menu_name'] = $r['alias'];
        sort($r['users']);
        $r['users_str'] = implode('、', $r['users']);
    }
    unset($r);
    return $rows;
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

        /* ── 頁面使用統計：單一頁面的使用者明細（每人次數＋最初～最後使用區間） ── */
        if ($action === 'pv_detail') {
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
            $names = alr_user_names($pdo);
            foreach ($list as &$l) {
                $l['name'] = $names[(int)$l['user_id']] ?? ('#' . $l['user_id']);
                foreach (['c30','c90','c_all','days_used'] as $k) $l[$k] = (int)$l[$k];
            }
            unset($l);
            echo json_encode(['success'=>true, 'page_path'=>$pp, 'rows'=>$list]);
            exit;
        }

        /* ── 頁面使用統計：設定/清除未掛選單頁面的自訂別名（管理員；寫入走 POST＋transaction） ── */
        if ($action === 'pv_set_alias') {
            header('Content-Type: application/json; charset=utf-8');
            $pp    = mb_substr(trim($_POST['page_path'] ?? ''), 0, 191);
            $alias = mb_substr(trim($_POST['alias'] ?? ''), 0, 191);
            if ($pp === '') { echo json_encode(['success'=>false, 'error'=>'缺頁面路徑']); exit; }
            try {
                $pdo->beginTransaction();
                if ($alias === '') {
                    $pdo->prepare("DELETE FROM page_visit_alias WHERE page_path = ?")->execute([$pp]);
                } else {
                    $pdo->prepare("INSERT INTO page_visit_alias (page_path, alias, Modified_By, Modified_At)
                                   VALUES (?,?,?,NOW())
                                   ON DUPLICATE KEY UPDATE alias = VALUES(alias), Modified_By = VALUES(Modified_By), Modified_At = NOW()")
                        ->execute([$pp, $alias, $my_id]);
                }
                $pdo->commit();
                echo json_encode(['success'=>true, 'alias'=>$alias]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
            }
            exit;
        }

        /* ── 頁面使用統計：清單 / CSV 匯出 ── */
        if ($action === 'pv_list' || $action === 'pv_export') {
            $scope  = $_GET['scope'] ?? 'all';
            if (!in_array($scope, ['all','menu','dead'], true)) $scope = 'all';
            $sort   = $_GET['sort'] ?? 'c90';
            $dir    = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $allowed = ['page_path','menu_name','group_name','c30','c90','users90','users_str','c_all','last_visit','on_menu','dead_menu'];
            if (!in_array($sort, $allowed, true)) $sort = 'c90';

            $all = pvr_build_dataset($pdo);
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

            if ($action === 'pv_export') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="page_visit_report_' . date('Ymd_His') . '.csv"');
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['頁面路徑','選單名稱','選單群組','是否在選單','近30天次數','近90天次數','90天使用人數','使用者（近90天）','累計次數','最後使用時間','選單頁90天零使用']);
                foreach ($rows as $r) {
                    fputcsv($out, [$r['page_path'], $r['menu_name'], $r['group_name'], $r['on_menu'] ? '是' : '否',
                                   $r['c30'], $r['c90'], $r['users90'], $r['users_str'], $r['c_all'],
                                   $r['last_visit'] ?: '（從未使用）', $r['dead_menu'] ? '★' : '']);
                }
                fclose($out);
                exit;
            }

            $page = max(1, intval($_GET['page'] ?? 1));
            $size = intval($_GET['size'] ?? 10);
            if (!in_array($size, [5,10,20,50], true)) $size = 10;
            $total = count($rows);
            $paged = array_slice($rows, ($page - 1) * $size, $size);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>true, 'summary'=>$summary, 'total'=>$total, 'page'=>$page, 'size'=>$size, 'rows'=>$paged]);
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

        /* 頁面使用統計分頁：可點的統計卡（切換篩選範圍）＋狀態徽章（暖色盤，同語意同色） */
        .stat-card[data-scope] { cursor:pointer; transition:transform .1s, box-shadow .1s; }
        .stat-card[data-scope]:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(0,0,0,.1); }
        .stat-card.c-amber.active { box-shadow:0 0 0 3px #F0A24B; }
        .stat-card.c-sand.active  { box-shadow:0 0 0 3px #C89B6D; }
        .stat-card.c-coral.active { box-shadow:0 0 0 3px #DD5138; }
        td.num, th.num { text-align:right; }
        table.alr-table tbody tr.dead-row { background:#FDF0EE; }
        table.alr-table tbody tr.dead-row:hover { background:#FBE5E1; }
        .badge-dead { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#DD5138; color:#fff; white-space:nowrap; }
        .badge-menu { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#F7E0BD; color:#5B4636; white-space:nowrap; }
        .badge-off  { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;
            background:#F0EAE0; color:#8a7a66; white-space:nowrap; }
        .user-link { color:#B25E1F; cursor:pointer; border-bottom:1px dashed #C89B6D; }
        .user-link:hover { color:#8B5E3C; }
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
        <div class="alr-tab" data-tab="pv"><i class="fa fa-bar-chart"></i> 頁面使用統計</div>
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

      <!-- ══════════ 分頁三：頁面使用統計 ══════════ -->
      <div id="tab-pv" style="display:none;">
        <div class="stats-container">
          <div class="stat-card c-brown active" data-scope="all">
            <div class="stat-value" id="vCardPages">–</div>
            <div class="stat-label">追蹤中頁面數（點我看全部）</div>
            <div class="stat-sub" id="vCardSince">統計起算日 –</div>
          </div>
          <div class="stat-card c-sand" data-scope="menu">
            <div class="stat-value" id="vCardMenu">–</div>
            <div class="stat-label">選單頁面數（點我篩選）</div>
            <div class="stat-sub">system_module_pages 掛載中</div>
          </div>
          <div class="stat-card c-coral" data-scope="dead">
            <div class="stat-value" id="vCardDead">–</div>
            <div class="stat-label">選單頁 90 天零使用（點我篩選）</div>
            <div class="stat-sub">下架/改良候選（核心指標）</div>
          </div>
          <div class="stat-card c-amber">
            <div class="stat-value" id="vCardVisits">–</div>
            <div class="stat-label">近 90 天總開啟次數</div>
            <div class="stat-sub">全站合計</div>
          </div>
        </div>

        <div class="filter-bar">
          <input type="text" id="vKw" class="form-control input-sm eg-in eg-v eg-live" placeholder="頁面路徑 / 選單名稱 / 群組 / 使用者（即時篩選）" style="width:270px;">
          <div style="margin-left:auto; display:flex; gap:8px;">
            <button class="btn btn-info btn-sm" id="btnPvCsv"><i class="fa fa-file-excel-o"></i> 轉 CSV</button>
            <button class="btn btn-info btn-sm" id="btnPvPrint"><i class="fa fa-print"></i> 列印 / PDF</button>
          </div>
        </div>

        <div class="main-card">
          <div class="table-toolbar">
            <div style="color:#8a7a66;font-size:12px;">
              預設依<b>近90天次數由大到小</b>排；<span class="badge-dead">選單零使用</span>列標紅，點「選單零使用」卡片可只看該清單。點欄位標題可排序。
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <div id="vPageInfo" style="color:#8a7a66;font-size:12px;"></div>
              <select id="vSize" class="form-control input-sm" style="width:80px;">
                <option value="5">5</option><option value="10" selected>10</option>
                <option value="20">20</option><option value="50">50</option>
              </select>
              <button class="btn btn-default btn-xs" id="vPrev"><i class="fa fa-chevron-left"></i></button>
              <span id="vPageNum">1</span>
              <button class="btn btn-default btn-xs" id="vNext"><i class="fa fa-chevron-right"></i></button>
            </div>
          </div>
          <div style="overflow-x:auto;width:100%;">
            <table class="table alr-table" id="vTable">
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
              <tbody id="vTbody"><tr><td colspan="10" class="text-center text-muted">載入中...</td></tr></tbody>
            </table>
          </div>
          <div class="note-box">
            ．記錄方式：所有走共用側欄的頁面每次「開啟」記一筆（頁 × 日 × 人 彙總）；AJAX 請求不計。統計自起算日起累積，<b>累積 2–3 個月後的近90天數字才有代表性</b>。<br>
            ．「選單零使用」＝掛在 system_module_pages 選單上、但近 90 天沒有任何人開啟過——為下架或改良的頭號候選。<br>
            ．未掛選單但有使用紀錄的頁面（直接輸入網址、由其他頁跳轉，如批圖編輯器）也會列出；可點該列「選單名稱」旁的 <i class="fa fa-pencil" style="color:#B25E1F;"></i> 自訂顯示名稱，方便搜尋辨識。<br>
            ．統計卡與總筆數一律以「全部資料」於後端計算，非僅當前頁。
          </div>
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
</div>

<!-- 使用者明細 Modal（頁面使用統計分頁） -->
<div class="modal fade" id="userModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:680px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-users"></i> 使用者明細 <small id="umSub" style="color:#8a7a66;word-break:break-all;"></small></h4></div>
      <div class="modal-body" id="umBody" style="max-height:70vh;overflow-y:auto;">載入中...</div>
    </div>
  </div>
</div>

<!-- 未掛選單頁面命名 Modal（頁面使用統計分頁） -->
<div class="modal fade" id="aliasModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:520px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-pencil"></i> 設定頁面顯示名稱</h4></div>
      <div class="modal-body" style="font-size:13px;">
        <p style="color:#8a7a66;margin-bottom:6px;">為未掛選單的頁面取一個好記的名稱，方便在此頁搜尋辨識（僅影響本統計頁顯示，不影響實際頁面）。</p>
        <div style="background:#F8F2E8;border-radius:6px;padding:6px 10px;margin-bottom:10px;word-break:break-all;color:#6b5745;">
          <i class="fa fa-file-o"></i> <span id="aliasPath"></span>
        </div>
        <input type="text" id="aliasInput" class="form-control input-sm" maxlength="191"
               placeholder="例如：批圖編輯器" style="width:100%;">
        <div id="aliasErr" class="text-danger" style="font-size:12px;margin-top:6px;display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default btn-sm" id="aliasClear" title="清除名稱，恢復顯示路徑">清除名稱</button>
        <button type="button" class="btn btn-info btn-sm" id="aliasSave"><i class="fa fa-check"></i> 儲存</button>
      </div>
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
        <p><b>管理者</b>（is_system=1 系統管理員角色）：可檢視登入紀錄、權限異動、頁面使用統計三個分頁，並篩選排序、匯出 CSV / 列印。</p>
        <p style="color:#8a7a66;">本頁屬管理者群組頁面，不設獨立角色；非管理者無法開啟。</p>
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

    /* ── 分頁切換（各分頁首次開啟才載入） ── */
    $('.alr-tab').on('click', function(){
        $('.alr-tab').removeClass('active'); $(this).addClass('active');
        var t = $(this).data('tab');
        $('#tab-login').toggle(t === 'login');
        $('#tab-perm').toggle(t === 'perm');
        $('#tab-pv').toggle(t === 'pv');
        if (t === 'perm' && !pState.loaded) loadPerm();
        if (t === 'pv'   && !vState.loaded) loadPv();
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

    /* ══════════ 頁面使用統計 ══════════ */
    var vState = { scope:'all', sort:'c90', dir:'desc', page:1, size:10, loaded:false, lastRows:[] };

    function vParams(){
        return { kw: $('#vKw').val().trim(), scope: vState.scope,
                 sort: vState.sort, dir: vState.dir, page: vState.page, size: vState.size };
    }

    function loadPv(){
        vState.loaded = true;
        var p = vParams(); p.action = 'pv_list';
        $.getJSON('audit_log_report.php', p, function(res){
            if (!res.success) { $('#vTbody').html('<tr><td colspan="10" class="text-center text-danger">' + esc(res.error) + '</td></tr>'); return; }
            $('#vCardPages').text(res.summary.pages);
            $('#vCardMenu').text(res.summary.menu_pages);
            $('#vCardDead').text(res.summary.dead_menu);
            $('#vCardVisits').text(res.summary.visits90);
            $('#vCardSince').text('統計起算日 ' + (res.summary.first_date || '（尚無資料）'));

            var totalPages = Math.max(1, Math.ceil(res.total / vState.size));
            if (vState.page > totalPages) { vState.page = totalPages; loadPv(); return; }
            $('#vPageNum').text(vState.page + ' / ' + totalPages);
            $('#vPageInfo').text('共 ' + res.total + ' 筆');

            if (!res.rows.length) { $('#vTbody').html('<tr><td colspan="10" class="text-center text-muted">無符合資料</td></tr>'); return; }
            var html = '';
            res.rows.forEach(function(r, i){
                var badge = r.dead_menu == 1 ? '<span class="badge-dead">選單零使用</span>'
                          : (r.on_menu == 1 ? '<span class="badge-menu">選單頁</span>' : '<span class="badge-off">未掛選單</span>');
                var users = r.users90 > 0
                          ? '<span class="user-link" data-idx="' + i + '" title="點我看每人使用次數與期間">' + esc(r.users_str) + '</span>'
                          : '<span style="color:#c9bba8;">—</span>';
                var nameCell;
                if (r.on_menu == 1) {
                    nameCell = esc(r.menu_name || '');                       // 選單頁：用真實選單名稱，不可改
                } else {
                    var lbl = r.alias ? esc(r.alias) : '<span style="color:#c9bba8;">（未命名）</span>';
                    nameCell = lbl + ' <i class="fa fa-pencil pv-alias-edit" data-path="' + esc(r.page_path)
                             + '" data-alias="' + esc(r.alias || '') + '" title="設定顯示名稱，方便搜尋辨識"'
                             + ' style="cursor:pointer;color:#B25E1F;margin-left:5px;"></i>';
                }
                html += '<tr' + (r.dead_menu == 1 ? ' class="dead-row"' : '') + '>'
                     +  '<td style="word-break:break-all;">' + esc(r.page_path) + '</td>'
                     +  '<td>' + nameCell + '</td>'
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
            vState.lastRows = res.rows;
            $('#vTbody').html(html);
            $('#vTable thead th .sort-ind').text('');
            $('#vTable thead th[data-sort="' + vState.sort + '"] .sort-ind').text(vState.dir === 'asc' ? '▲' : '▼');
        }).fail(function(xhr){
            $('#vTbody').html('<tr><td colspan="10" class="text-center text-danger">載入失敗（' + xhr.status + '）：請重新整理或稍後再試</td></tr>');
        });
    }

    $('#vTable thead').on('click', 'th[data-sort]', function(){
        var s = $(this).data('sort');
        if (vState.sort === s) vState.dir = (vState.dir === 'asc' ? 'desc' : 'asc');
        else { vState.sort = s; vState.dir = (s === 'page_path' || s === 'menu_name' || s === 'group_name' || s === 'users_str') ? 'asc' : 'desc'; }
        vState.page = 1; loadPv();
    });

    /* 範圍切換（點統計卡篩選） */
    $('#tab-pv .stat-card[data-scope]').on('click', function(){
        vState.scope = $(this).data('scope'); vState.page = 1;
        $('#tab-pv .stat-card[data-scope]').removeClass('active').filter('[data-scope="' + vState.scope + '"]').addClass('active');
        loadPv();
    });

    /* 使用者明細：每人次數＋最初～最後使用區間 */
    $('#vTbody').on('click', '.user-link', function(){
        var r = (vState.lastRows || [])[$(this).data('idx')];
        if (!r) return;
        $('#umSub').text(r.page_path + (r.menu_name ? '（' + r.menu_name + '）' : ''));
        $('#umBody').html('載入中...');
        $('#userModal').modal('show');
        $.getJSON('audit_log_report.php', { action:'pv_detail', page_path: r.page_path }, function(res){
            if (!res.success) { $('#umBody').html('<span class="text-danger">' + esc(res.error) + '</span>'); return; }
            if (!res.rows.length) { $('#umBody').html('<span class="text-muted">尚無使用紀錄</span>'); return; }
            var h = '<table class="table" style="margin-bottom:0;">'
                  + '<thead><tr><th>使用者</th><th style="text-align:right;">近30天</th>'
                  + '<th style="text-align:right;">近90天</th><th style="text-align:right;">累計</th>'
                  + '<th style="text-align:right;">使用天數</th><th>使用期間（最初～最後）</th></tr></thead><tbody>';
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
               + '<div style="font-size:12px;color:#a08e78;margin-top:8px;">使用天數＝有開啟過本頁的不同日數；期間為統計起算日後的最初～最後使用時間。</div>';
            $('#umBody').html(h);
        }).fail(function(xhr){ $('#umBody').html('<span class="text-danger">載入失敗（' + xhr.status + '）</span>'); });
    });

    /* 未掛選單頁面命名：開窗、儲存、清除 */
    $('#vTbody').on('click', '.pv-alias-edit', function(){
        $('#aliasPath').text($(this).data('path'));
        $('#aliasInput').val($(this).data('alias') || '');
        $('#aliasErr').hide().text('');
        $('#aliasModal').modal('show');
        setTimeout(function(){ $('#aliasInput').focus().select(); }, 300);
    });
    function saveAlias(clear){
        var pp = $('#aliasPath').text();
        var alias = clear ? '' : $('#aliasInput').val().trim();
        $('#aliasSave,#aliasClear').prop('disabled', true);
        $.ajax({ url:'audit_log_report.php?action=pv_set_alias', method:'POST',
                 data:{ page_path: pp, alias: alias }, dataType:'json' })
         .done(function(res){
             if (!res || !res.success) { $('#aliasErr').text('儲存失敗：' + (res && res.error ? res.error : '未知錯誤')).show(); return; }
             $('#aliasModal').modal('hide'); loadPv();
         })
         .fail(function(xhr){ $('#aliasErr').text('儲存失敗（' + xhr.status + '）').show(); })
         .always(function(){ $('#aliasSave,#aliasClear').prop('disabled', false); });
    }
    $('#aliasSave').on('click', function(){ saveAlias(false); });
    $('#aliasClear').on('click', function(){ saveAlias(true); });
    $('#aliasInput').on('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); saveAlias(false); } });

    $('#vSize').on('change', function(){ vState.size = parseInt(this.value, 10); vState.page = 1; loadPv(); });
    $('#vPrev').on('click', function(){ if (vState.page > 1) { vState.page--; loadPv(); } });
    $('#vNext').on('click', function(){ vState.page++; loadPv(); });
    $('#btnPvCsv').on('click', function(){
        var p = vParams(); p.action = 'pv_export'; delete p.page; delete p.size;
        location.href = 'audit_log_report.php?' + $.param(p);
    });
    $('#btnPvPrint').on('click', function(){ window.print(); });

    $('#roleHint').on('click', function(){ $('#roleModal').modal('show'); });

    /* 依篩選欄所屬分頁決定重載哪個表 */
    function reloadByScope($el){
        if ($el.hasClass('eg-l')) { lState.page = 1; loadLogin(); }
        else if ($el.hasClass('eg-v')) { vState.page = 1; loadPv(); }
        else { pState.page = 1; loadPerm(); }
    }

    /* 即時篩選：輸入停頓 400ms 自動查詢（依所屬分頁） */
    var liveTimer = null;
    $(document).on('input', '.eg-live', function(){
        var $el = $(this);
        clearTimeout(liveTimer);
        liveTimer = setTimeout(function(){ reloadByScope($el); }, 400);
    });

    /* UI 規範：雙擊清空（篩選欄＝同時解除篩選）/ 聚焦全選 / Enter 逐欄與末欄送出 */
    $(document).on('focus', '.eg-in', function(){ var el = this; setTimeout(function(){ try { el.select(); } catch(e){} }, 0); });
    $(document).on('dblclick', '.eg-in', function(){
        if (this.value !== '') { this.value = ''; reloadByScope($(this)); }
    });
    $(document).on('keydown', '.eg-in', function(e){
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var cls = $(this).hasClass('eg-l') ? '.eg-in.eg-l:visible' : ($(this).hasClass('eg-v') ? '.eg-in.eg-v:visible' : '.eg-in.eg-p:visible');
        var ins = $(cls);
        var idx = ins.index(this);
        if (idx >= 0 && idx < ins.length - 1) ins.eq(idx + 1).focus();
        else reloadByScope($(this));
    });

    loadLogin();

    /* 深層連結：?tab=pv / ?tab=perm 直接開對應分頁（供選單與舊 page_visit_report 轉址落點） */
    var initTab = (new URLSearchParams(location.search)).get('tab');
    if (initTab === 'pv' || initTab === 'perm') $('.alr-tab[data-tab="' + initTab + '"]').trigger('click');
})();
</script>
<?php endif; ?>
</body>
</html>
