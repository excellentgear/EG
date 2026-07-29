<?php
// 共用帳號管理 API（hr_settings.php 的「共用帳號管理」區塊用）
// 規格：ai-rules/13-共用帳號通知與綁定.md
//   list          → 共用帳號清單 + 可選成員（在職員工）清單
//   members       → 某共用帳號的成員
//   set_shared    → 把某帳號標記/取消標記為共用帳號（標記時預設鎖密碼）
//   set_lock      → 切換 lock_password
//   add_member    → 新增成員（可多筆）
//   set_mode      → 切換成員 attach / notify
//   remove_member → 移除成員
//
// 權限：僅「管理者」等級（比照 hr_settings 的頁面權限 A）；一律 POST + CSRF。
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../common/_config.php';           // session_start + $db
require_once __DIR__ . '/../common/shared_account_lib.php';

function sa_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit(); }

if (!isset($_SESSION['id'])) sa_out(['ok' => false, 'msg' => '尚未登入']);
$me = (int)$_SESSION['id'];

// CSRF（與其他 API 一致：session token）
if (empty($_SESSION['sa_csrf'])) $_SESSION['sa_csrf'] = bin2hex(random_bytes(16));
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'token') sa_out(['ok' => true, 'token' => $_SESSION['sa_csrf']]);

$readOnly = in_array($action, ['list', 'members'], true);
if (!$readOnly) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sa_out(['ok' => false, 'msg' => '需以 POST 呼叫']);
    if (!hash_equals((string)$_SESSION['sa_csrf'], (string)($_POST['csrf'] ?? ''))) sa_out(['ok' => false, 'msg' => 'CSRF 驗證失敗，請重新整理頁面']);
}

// 權限：hr_settings 頁面的 'A'（管理者）才可操作
function sa_is_admin(PDO $db, int $uid): bool
{
    try {
        $st = $db->prepare("SELECT smp.page_id, smp.group_id FROM system_module_pages smp
                            WHERE smp.page_url LIKE '%hr_settings.php' LIMIT 1");
        $st->execute();
        $pg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pg) return false;

        $q = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id = ? AND scope='page' AND module_code = ?");
        $q->execute([$uid, $pg['page_id']]);
        $perms = $q->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms) && !empty($pg['group_id'])) {
            $gm = $db->prepare("SELECT module_code FROM system_modules WHERE group_id = ? LIMIT 1");
            $gm->execute([$pg['group_id']]);
            $code = $gm->fetchColumn();
            if ($code) {
                $q2 = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id = ? AND scope='group' AND module_code = ?");
                $q2->execute([$uid, $code]);
                $perms = $q2->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        foreach ($perms as $p) if (strpos((string)$p, 'A') !== false) return true;
        return false;
    } catch (Throwable $e) {
        error_log('[shared_api] perm check failed: ' . $e->getMessage());
        return false;
    }
}
if (!sa_is_admin($db, $me)) sa_out(['ok' => false, 'msg' => '僅管理者可設定共用帳號']);

if (!eg_shared_ready($db)) sa_out(['ok' => false, 'msg' => '共用帳號資料表尚未建立，請先執行遷移']);

/** 稽核紀錄（比照 audit_log rbac_* 寫法；失敗不阻斷） */
function sa_audit(PDO $db, int $me, string $act, string $targetId, string $changes): void
{
    try {
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES (?, 'shared_account', ?, NULL, ?, ?, ?, NOW())")
           ->execute([$act, $targetId, $changes, $me, ($_SESSION['user_cname'] ?? ('U' . $me))]);
    } catch (Throwable $e) { error_log('[shared_api] audit failed: ' . $e->getMessage()); }
}

try {
    switch ($action) {

        case 'list': {
            $shared = $db->query("SELECT u.id, u.user_cname, u.user_uname, u.state, u.lock_password,
                                         (SELECT COUNT(*) FROM shared_account_member m WHERE m.shared_uid = u.id AND m.active = 1) AS member_cnt
                                  FROM `user` u WHERE u.is_shared_account = 1
                                  ORDER BY u.user_cname")->fetchAll(PDO::FETCH_ASSOC);
            // 可標記為共用帳號的候選（含特殊帳號 90，現場共用帳號多半是 90）
            $cand = $db->query("SELECT id, user_cname, user_uname, state FROM `user`
                                WHERE is_shared_account = 0 AND state IN (1,90,99)
                                ORDER BY state DESC, user_cname")->fetchAll(PDO::FETCH_ASSOC);
            // 可加入的員工（在職）
            $emp = $db->query("SELECT id, user_cname, user_uname FROM `user`
                               WHERE state IN (1,99) AND is_shared_account = 0
                               ORDER BY user_cname")->fetchAll(PDO::FETCH_ASSOC);
            sa_out(['ok' => true, 'shared' => $shared, 'candidates' => $cand, 'employees' => $emp, 'token' => $_SESSION['sa_csrf']]);
        }

        case 'members': {
            $sid = (int)($_GET['shared_uid'] ?? $_POST['shared_uid'] ?? 0);
            if ($sid <= 0) sa_out(['ok' => false, 'msg' => '參數錯誤']);
            $st = $db->prepare("SELECT m.id, m.member_uid, m.mode, m.active, u.user_cname, u.user_uname
                                FROM shared_account_member m JOIN `user` u ON u.id = m.member_uid
                                WHERE m.shared_uid = ? ORDER BY u.user_cname");
            $st->execute([$sid]);
            sa_out(['ok' => true, 'members' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'set_shared': {
            $uid = (int)($_POST['uid'] ?? 0);
            $on  = ((int)($_POST['on'] ?? 0) === 1);
            if ($uid <= 0) sa_out(['ok' => false, 'msg' => '參數錯誤']);
            if (!$on) {
                $cnt = $db->prepare("SELECT COUNT(*) FROM shared_account_member WHERE shared_uid = ? AND active = 1");
                $cnt->execute([$uid]);
                if ((int)$cnt->fetchColumn() > 0) sa_out(['ok' => false, 'msg' => '尚有成員綁定中，請先移除所有成員']);
            }
            $db->beginTransaction();
            // 標記為共用帳號時預設鎖密碼（規格 5：避免現場有人隨手改掉害全廠登不進去）
            $db->prepare("UPDATE `user` SET is_shared_account = ?, lock_password = ? WHERE id = ?")
               ->execute([$on ? 1 : 0, $on ? 1 : 0, $uid]);
            $db->commit();
            sa_audit($db, $me, $on ? 'shared_mark' : 'shared_unmark', (string)$uid, $on ? '標記為共用帳號(並預設鎖密碼)' : '取消共用帳號標記');
            sa_out(['ok' => true]);
        }

        case 'set_lock': {
            $uid = (int)($_POST['uid'] ?? 0);
            $on  = ((int)($_POST['on'] ?? 0) === 1);
            if ($uid <= 0) sa_out(['ok' => false, 'msg' => '參數錯誤']);
            $db->prepare("UPDATE `user` SET lock_password = ? WHERE id = ?")->execute([$on ? 1 : 0, $uid]);
            sa_audit($db, $me, $on ? 'shared_lock_pw' : 'shared_unlock_pw', (string)$uid, $on ? 'lock_password=1' : 'lock_password=0');
            sa_out(['ok' => true]);
        }

        case 'add_member': {
            $sid = (int)($_POST['shared_uid'] ?? 0);
            $mode = ($_POST['mode'] ?? 'attach') === 'notify' ? 'notify' : 'attach';
            $uids = $_POST['member_uids'] ?? [];
            if (!is_array($uids)) $uids = array_filter(explode(',', (string)$uids));
            $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
            if ($sid <= 0 || empty($uids)) sa_out(['ok' => false, 'msg' => '請選擇共用帳號與成員']);

            $chk = $db->prepare("SELECT COUNT(*) FROM `user` WHERE id = ? AND is_shared_account = 1");
            $chk->execute([$sid]);
            if (!(int)$chk->fetchColumn()) sa_out(['ok' => false, 'msg' => '該帳號不是共用帳號']);
            if (in_array($sid, $uids, true)) sa_out(['ok' => false, 'msg' => '共用帳號不可綁定自己']);

            $db->beginTransaction();
            $ins = $db->prepare("INSERT INTO shared_account_member (shared_uid, member_uid, mode, active, created_by)
                                 VALUES (?,?,?,1,?)
                                 ON DUPLICATE KEY UPDATE mode = VALUES(mode), active = 1");
            foreach ($uids as $u) $ins->execute([$sid, $u, $mode, $me]);
            $db->commit();
            sa_audit($db, $me, 'shared_add_member', (string)$sid, 'members=' . implode(',', $uids) . ' mode=' . $mode);
            sa_out(['ok' => true, 'added' => count($uids)]);
        }

        case 'set_mode': {
            $rid  = (int)($_POST['id'] ?? 0);
            $mode = ($_POST['mode'] ?? 'attach') === 'notify' ? 'notify' : 'attach';
            if ($rid <= 0) sa_out(['ok' => false, 'msg' => '參數錯誤']);
            $db->prepare("UPDATE shared_account_member SET mode = ? WHERE id = ?")->execute([$mode, $rid]);
            sa_audit($db, $me, 'shared_set_mode', (string)$rid, 'mode=' . $mode);
            sa_out(['ok' => true]);
        }

        case 'set_active': {
            $rid = (int)($_POST['id'] ?? 0);
            $on  = ((int)($_POST['on'] ?? 0) === 1);
            if ($rid <= 0) sa_out(['ok' => false, 'msg' => '參數錯誤']);
            $db->prepare("UPDATE shared_account_member SET active = ? WHERE id = ?")->execute([$on ? 1 : 0, $rid]);
            sa_audit($db, $me, $on ? 'shared_member_on' : 'shared_member_off', (string)$rid, $on ? 'active=1' : 'active=0');
            sa_out(['ok' => true]);
        }

        case 'remove_member': {
            $rid = (int)($_POST['id'] ?? 0);
            if ($rid <= 0) sa_out(['ok' => false, 'msg' => '參數錯誤']);
            $db->prepare("DELETE FROM shared_account_member WHERE id = ?")->execute([$rid]);
            sa_audit($db, $me, 'shared_remove_member', (string)$rid, '刪除綁定');
            sa_out(['ok' => true]);
        }

        default:
            sa_out(['ok' => false, 'msg' => '未知的動作']);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[shared_api] failed: ' . $e->getMessage());
    sa_out(['ok' => false, 'msg' => '處理失敗：' . $e->getMessage()]);
}
