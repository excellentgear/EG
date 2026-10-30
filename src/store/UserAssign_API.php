<?php
/**
 * 人員指派總覽 API（2026-10-30）— 邏輯全在 src/common/user_assign_lib.php
 *   list     ：所有指派（可 only_inactive / user_id / kw）。全體管理員可看。
 *   people   ：可當替代人員的在職者（一人一列，職務合併顯示）。
 *   replace  ：把某一項指派換成另一人（POST + CSRF）。
 *   notify   ：立即盤點並通知（POST + CSRF）。
 * 權限：全站管理員（user_status 9/90 或系統管理員角色）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/user_assign_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function uaOut(array $a) { echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function uaErr(string $m, int $c = 400, array $x = []) { http_response_code($c); echo json_encode(array_merge(['ok'=>false,'error'=>$m], $x), JSON_UNESCAPED_UNICODE); exit; }

try { $db = (new DBConnection())->getPDO(); } catch (Throwable $e) { uaErr('DB連線失敗', 500); }
$u = eg_printlog_current_user($db);
if (!$u) uaErr('未登入', 401);
$perms = eg_printlog_perms($db, $u);
if (empty($perms['isAdmin'])) uaErr('需要全站管理員權限', 403);

if (empty($_SESSION['uas_csrf'])) $_SESSION['uas_csrf'] = bin2hex(random_bytes(16));
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (in_array($action, ['replace', 'notify'], true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['uas_csrf'], $tok))
        uaErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code'=>'CSRF']);
}

switch ($action) {
    case 'list':
        $onlyInactive = !empty($_GET['only_inactive']);
        $rows = uas_scan($db, (int)($_GET['user_id'] ?? 0), $onlyInactive);
        $kw = trim((string)($_GET['kw'] ?? ''));
        if ($kw !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($kw) {
                $h = $r['user_name'] . ' ' . $r['module'] . ' ' . $r['title'] . ' ' . $r['label'];
                foreach (preg_split('/\s+/u', $kw) as $w) if ($w !== '' && mb_stripos($h, $w) === false) return false;
                return true;
            }));
        }
        usort($rows, fn($a, $b) => [$b['inactive'], $a['user_name'], $a['module']] <=> [$a['inactive'], $b['user_name'], $b['module']]);
        uaOut(['rows'=>$rows, 'csrf'=>$_SESSION['uas_csrf'],
               'sources'=>array_values(array_unique(array_column(uas_sources(), 'module')))]);

    case 'people':
        $people = []; $seen = [];
        foreach (eg_people_list($db, ['all_posts'=>true]) as $p) {
            $pid = (int)$p['id'];
            $post = trim((string)($p['dept_name'] ?? '') . ' ' . (string)($p['position_name'] ?? ''));
            if (isset($seen[$pid])) {
                if ($post !== '' && !in_array($post, $people[$seen[$pid]]['posts'], true)) $people[$seen[$pid]]['posts'][] = $post;
                continue;
            }
            $seen[$pid] = count($people);
            $people[] = ['id'=>$pid, 'name'=>(string)$p['user_cname'], 'posts'=>($post !== '' ? [$post] : [])];
        }
        uaOut(['people'=>$people]);

    case 'replace':
        $r = uas_replace($db, (string)($_POST['src'] ?? ''), (string)($_POST['pk'] ?? ''),
                         (int)($_POST['old_uid'] ?? 0), (int)($_POST['new_uid'] ?? 0),
                         (string)($u['user_cname'] ?? ''), (int)$u['id']);
        if (!$r['ok']) uaErr($r['msg']);
        uaOut(['msg'=>$r['msg']]);

    case 'notify':
        uaOut(['sent'=>uas_notify($db)]);

    default:
        uaErr('無效的操作');
}
