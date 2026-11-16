<?php
/**
 * AS 負責人交接與權限設定 API
 * 讀寫一律限「AS 文件管理員」或系統管理員；改動管理員身分（含交接時舊負責人轉管理員）限系統管理員。
 * 寫入動作驗 CSRF，並先預覽再套用（preview → apply），套用時後端重算一次，不採信前端送來的預覽。
 */
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
include_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/asdoc_lib.php';
require_once __DIR__ . '/../common/as_handover_lib.php';
require_once __DIR__ . '/../common/people_lib.php';

function ahOut(array $a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function ahErr(string $m, int $c = 400) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }

try { $db = (new DBConnection())->getPDO(); } catch (Throwable $e) { ahErr('DB連線失敗', 500); }
$uname = $_SESSION['userName'] ?? '';
if ($uname === '') ahErr('未登入', 401);
$st = $db->prepare("SELECT id FROM `user` WHERE user_uname=?");
$st->execute([$uname]);
$uid = (int)$st->fetchColumn();
if (!$uid) ahErr('未登入', 401);

ash_ensure_schema($db);
$sys = ash_is_sys_admin($db, $uid);
if (!$sys && !eg_asdoc_is_admin($db, $uid)) ahErr('只有 AS 管理員或系統管理員可以使用', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$WRITE = ['apply', 'pause', 'resume', 'restore'];
if (in_array($action, $WRITE, true)) {
    $tok = (string)($_SESSION['ash_csrf'] ?? '');
    if ($tok === '' || !hash_equals($tok, (string)($_POST['csrf'] ?? ''))) ahErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403);
}

/** 解析 apply／preview 的參數成「要對誰做什麼」；回傳 [ops, error] */
function ahOps(PDO $db, bool $sys, int $actor): array {
    $mode = (string)($_POST['mode'] ?? '');      // handover｜set_owner｜set_admin｜remove
    $target = (int)($_POST['user_id'] ?? 0);
    if ($target <= 0) return [null, '請先選人'];
    $idx = ash_role_index($db);
    $ops = [];
    if ($mode === 'handover' || $mode === 'set_owner') {
        $ops[] = ['uid' => $target, 'preset' => 'owner'];
        // 負責人只能一人：目前的負責人（不是新人）一律轉成管理員，不是直接拿掉（舊負責人還要教、要能接手）
        foreach (ash_holders($db, 'asdoc_editor', $idx) as $h) {
            if ($h['id'] === $target) continue;
            if (!$sys) return [null, '更換負責人會把舊負責人轉為管理員，這一步限系統管理員操作'];
            $ops[] = ['uid' => $h['id'], 'preset' => 'admin'];
        }
    } elseif ($mode === 'set_admin') {
        if (!$sys) return [null, '指派 AS 管理員限系統管理員操作'];
        $ops[] = ['uid' => $target, 'preset' => 'admin'];
    } elseif ($mode === 'remove') {
        if (!$sys) return [null, '移除 AS 權限限系統管理員操作'];
        $ops[] = ['uid' => $target, 'preset' => 'none'];
    } else return [null, '不支援的動作'];
    return [$ops, null];
}

switch ($action) {
    case 'status': {
        $idx = ash_role_index($db);
        $pages = ash_pages($db);
        $pp = ash_presets();
        $presets = [];
        foreach ($pp as $k => $p) {
            $presets[$k] = ['label' => $p['label'], 'desc' => $p['desc'],
                'roles' => array_map(fn($c, $n) => ['code' => $c, 'name' => $n, 'exists' => isset($idx[$c])], array_keys($p['roles']), array_values($p['roles'])),
                'pages' => array_values(array_map(fn($f) => ['file' => $f, 'name' => $pages[$f]['name'] ?? '（頁面未登記）'], $p['pages']))];
        }
        $owners = ash_holders($db, 'asdoc_editor', $idx);
        $admins = ash_holders($db, 'asdoc_page_admin', $idx);
        $health = [];
        foreach ($owners as $o) $health['o' . $o['id']] = ash_health($db, $o['id'], 'owner') + ['name' => $o['name']];
        // 其他有 AS 管理員類角色、卻不是管理員身分的人（例如單獨被給了 doc_apply_admin）
        $strays = [];
        foreach (['doc_apply_admin', 'asched_admin', 'asdoc_backfill', 'ia_admin', 'type_id_ctrl_admin', 'sopsip_admin'] as $c) {
            foreach (ash_holders($db, $c, $idx) as $h) {
                $isAdm = false; foreach ($admins as $a) if ($a['id'] === $h['id']) $isAdm = true;
                $isOwn = false; foreach ($owners as $a) if ($a['id'] === $h['id']) $isOwn = true;
                if (!$isAdm || $isOwn) $strays[$h['id'] . ':' . $c] = ['id' => $h['id'], 'name' => $h['name'], 'role' => $idx[$c]['name'],
                                                                      'is_owner' => $isOwn];
            }
        }
        $state = $db->query("SELECT s.paused_user_id, s.paused_at, s.paused_reason, u.user_cname paused_name
                             FROM as_handover_state s LEFT JOIN `user` u ON u.id=s.paused_user_id WHERE s.id=1")->fetch(PDO::FETCH_ASSOC);
        $people = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['user_cname'],
                                       'dept' => $p['dept_name'] ?? '', 'pos' => $p['position_name'] ?? ''],
                            eg_people_list($db, []));
        $_SESSION['ash_csrf'] = $_SESSION['ash_csrf'] ?? bin2hex(random_bytes(16));
        ahOut(['presets' => $presets, 'owners' => $owners, 'admins' => $admins, 'health' => $health,
               'strays' => array_values($strays), 'state' => $state, 'people' => $people,
               'is_sys' => $sys, 'csrf' => $_SESSION['ash_csrf']]);
    }

    case 'preview': {
        [$ops, $err] = ahOps($db, $sys, $uid);
        if ($err) ahErr($err, 403);
        $idx = ash_role_index($db);
        $pages = ash_pages($db);
        $out = [];
        foreach ($ops as $o) {
            $plan = ash_plan($db, $o['uid'], $o['preset']);
            $out[] = ['uid' => $o['uid'], 'name' => ash_user_name($db, $o['uid']), 'preset' => $o['preset'],
                      'add' => array_map(fn($c) => $idx[$c]['name'] ?? $c, $plan['add']),
                      'remove' => array_map(fn($c) => $idx[$c]['name'] ?? $c, $plan['remove']),
                      'page_add' => array_map(fn($f) => $pages[$f]['name'] ?? $f, $plan['page_add']),
                      'page_strip_a' => array_map(fn($f) => $pages[$f]['name'] ?? $f, $plan['page_strip_a'])];
        }
        ahOut(['plans' => $out]);
    }

    case 'apply': {
        [$ops, $err] = ahOps($db, $sys, $uid);
        if ($err) ahErr($err, 403);
        $note = trim((string)($_POST['note'] ?? ''));
        $r = ash_execute($db, $ops, $uid, $note !== '' ? $note : '交接頁設定');
        if (empty($r['ok'])) ahErr($r['msg'], 500);
        ahOut(['msg' => '已完成，共調整 ' . count($ops) . ' 位人員']);
    }

    case 'pause': {
        $owners = ash_holders($db, 'asdoc_editor');
        if (count($owners) !== 1) ahErr('目前沒有唯一的 AS 負責人可暫停（現有 ' . count($owners) . ' 位）');
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') ahErr('請填暫停原因');
        $r = ash_pause($db, $owners[0]['id'], $uid, $reason);
        if (empty($r['ok'])) ahErr($r['msg'], 500);
        ahOut(['msg' => '已暫停，負責人降為唯讀']);
    }

    case 'resume': {
        $r = ash_resume($db, $uid, trim((string)($_POST['note'] ?? '')) ?: '恢復負責人權限');
        if (empty($r['ok'])) ahErr($r['msg']);
        ahOut(['msg' => '已恢復負責人權限']);
    }

    case 'restore': {
        if (!$sys) ahErr('還原限系統管理員操作', 403);
        $r = ash_restore_log($db, (int)($_POST['log_id'] ?? 0), $uid);
        if (empty($r['ok'])) ahErr($r['msg']);
        ahOut(['msg' => '已還原']);
    }

    case 'activity': {
        $target = (int)($_GET['user_id'] ?? 0);
        if ($target <= 0) ahErr('請先選人');
        ahOut(['name' => ash_user_name($db, $target)] + ash_activity($db, $target, (int)($_GET['days'] ?? 30)));
    }

    case 'log': {
        $rows = $db->query("SELECT id, action, target_user_id, target_name, preset, note, actor_name, created_at,
                                   before_json, after_json
                            FROM as_handover_log ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $b = json_decode((string)$r['before_json'], true)['roles'] ?? [];
            $a = json_decode((string)$r['after_json'], true)['roles'] ?? [];
            $r['added']   = array_values(array_diff($a, $b));
            $r['removed'] = array_values(array_diff($b, $a));
            unset($r['before_json'], $r['after_json']);
        }
        unset($r);
        ahOut(['rows' => $rows, 'role_names' => array_map(fn($x) => $x['name'], ash_role_index($db))]);
    }

    default: ahErr('不支援的動作', 404);
}
