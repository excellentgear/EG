<?php
/**
 * 快速已閱：由系統管理員代替特定人員按下「已閱」（公告 / 通知管理頁的已讀人員面板）
 *
 * 使用者拍板的兩條界線：
 *   1. 只有系統管理員（rbac 'all'）可用 —— 比照既有的「改未閱」(_eventReadReset.php)。
 *   2. 通知方式是「回簽」或「回覆 + 回簽」的人一律不適用 —— 那兩種要的是本人的意思表示，
 *      不是「有沒有看到」，代按就失去意義。後端一律再擋一次（鐵律8），不只擋前端。
 *
 * action=list  列出這則通知的所有收件人與目前狀態（誰可以代按、誰不行、為什麼）
 * action=mark  對指定人員寫入已閱紀錄（signed_via 留下是哪位管理員代按的，並寫 audit_log）
 */
header('Content-Type: application/json; charset=utf-8');

include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/notice_mode_lib.php';
require_once __DIR__ . '/../push/push_send.php'; // eg_push_event_recipients()：對象展開成人員的唯一實作

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];
if (!rbac_has(rbac_user_features($db, $uid), 'all')) {
    echo json_encode(['ok' => false, 'msg' => '僅系統管理員可代為標記已閱']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$eid    = (int)($_POST['eventid'] ?? $_GET['eventid'] ?? 0);
if ($eid <= 0) { echo json_encode(['ok' => false, 'msg' => '參數錯誤']); exit(); }

/**
 * 這則通知的收件人清單（含每個人的通知方式與目前狀態）
 * @return array uid => [name, dept, position, mode, status, read_at, eligible, why]
 */
function eqr_recipients(PDO $db, int $eid): array
{
    $uids = eg_push_event_recipients($db, $eid);
    if (empty($uids)) return [];
    $in = implode(',', array_map('intval', $uids));

    // 每個人符合哪些對象列 → 交給共用函式決定實際生效的通知方式
    $targets = $db->prepare("SELECT target_type, target_id, mode FROM live_event_target WHERE live_event_id = ?");
    $targets->execute([$eid]);
    $targets = $targets->fetchAll(PDO::FETCH_ASSOC);

    // 人員基本資料（部門/職稱依 people_lib 的慣例顯示；此處只需名稱故直接查）
    $rows = $db->query(
        "SELECT u.id, u.user_cname, u.user_status, u.user_status2, u.user_status3,
                d.name AS dept_name, p.name AS position_name, p.sort_order AS pos_sort, d.sort_order AS dept_sort
           FROM `user` u
           LEFT JOIN user_department_position_map m ON m.user_id = u.id AND m.is_main = 1
           LEFT JOIN department d ON d.id = m.department_id
           LEFT JOIN position   p ON p.id = m.position_id
          WHERE u.id IN ($in)"
    )->fetchAll(PDO::FETCH_ASSOC);

    // 每個人所屬的部門（含兼任）＝判斷 dept 對象是否命中
    $deptOf = [];
    foreach ($db->query("SELECT user_id, department_id FROM user_department_position_map WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $deptOf[(int)$m['user_id']][] = (int)$m['department_id'];
    }

    // 已閱 / 回簽 / 回覆 現況
    $readAt = [];
    foreach ($db->query("SELECT user_id, read_at FROM live_event_for_user WHERE oready_read = 1 AND live_event_id = $eid")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $readAt[(int)$r['user_id']] = $r['read_at'];
    }
    $resp = [];
    foreach ($db->query("SELECT user_id, read_at, signed_at, replied_at FROM live_event_response WHERE live_event_id = $eid")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $resp[(int)$r['user_id']] = $r;
    }

    $out = [];
    foreach ($rows as $u) {
        $id = (int)$u['id'];
        $myStatus = array_values(array_filter([$u['user_status'], $u['user_status2'], $u['user_status3']], function ($v) { return $v !== null && $v !== ''; }));
        $myStatus = array_map('intval', $myStatus);
        $myDept   = $deptOf[$id] ?? [];

        $modes = [];
        foreach ($targets as $t) {
            $tid = (int)$t['target_id'];
            $hit = ($t['target_type'] === 'all')
                || ($t['target_type'] === 'status' && in_array($tid, $myStatus, true))
                || ($t['target_type'] === 'dept'   && in_array($tid, $myDept, true))
                || ($t['target_type'] === 'user'   && $tid === $id);
            if ($hit) $modes[] = $t['mode'];
        }
        if (empty($modes)) continue; // 理論上不會發生（收件人本來就是對象展開來的）
        $mode = eg_notice_mode_pick($modes);

        $rp   = $resp[$id] ?? null;
        $rAt  = $readAt[$id] ?? ($rp['read_at'] ?? null);
        $status = !empty($rp['replied_at']) ? 'reply'
                : (!empty($rp['signed_at']) ? 'sign'
                : ($rAt ? 'read' : 'none'));

        // 可否代按：只有「已閱 / 開啟自動已閱」且還沒有已閱紀錄的人
        $eligible = true; $why = '';
        if ($mode === 'sign' || $mode === 'reply') {
            $eligible = false;
            $why = '通知方式為「' . eg_notice_mode_label($mode) . '」，需本人回應，不可代為標記';
        } elseif ($rAt) {
            $eligible = false;
            $why = '已於 ' . $rAt . ' 閱讀';
        }

        $out[$id] = [
            'user_id'  => $id,
            'name'     => $u['user_cname'] ?: ('員工#' . $id),
            'dept'     => $u['dept_name'] ?: '',
            'position' => $u['position_name'] ?: '',
            'mode'     => $mode,
            'mode_label' => eg_notice_mode_label($mode),
            'status'   => $status,
            'read_at'  => $rAt,
            'eligible' => $eligible,
            'why'      => $why,
            '_sort'    => [(int)($u['dept_sort'] ?? 9999), (int)($u['pos_sort'] ?? 9999), $u['user_cname'] ?: ''],
        ];
    }

    // 人員列表鐵則：欄位順序部門/職稱/姓名，排序依部門與職稱的 sort_order（不是姓名筆畫）
    uasort($out, function ($a, $b) {
        return [$a['_sort'][0], $a['_sort'][1], $a['_sort'][2]] <=> [$b['_sort'][0], $b['_sort'][1], $b['_sort'][2]];
    });
    foreach ($out as &$o) unset($o['_sort']);
    return $out;
}

try {
    $ev = $db->prepare("SELECT id, title FROM live_event WHERE id = ?");
    $ev->execute([$eid]);
    $ev = $ev->fetch(PDO::FETCH_ASSOC);
    if (!$ev) { echo json_encode(['ok' => false, 'msg' => '找不到此公告 / 通知']); exit(); }

    if ($action === 'list') {
        $list = array_values(eqr_recipients($db, $eid));
        echo json_encode([
            'ok' => true,
            'rows' => $list,
            'eligible_count' => count(array_filter($list, function ($r) { return $r['eligible']; })),
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'mark') {
        $want = json_decode((string)($_POST['uids'] ?? '[]'), true);
        $want = is_array($want) ? array_values(array_unique(array_map('intval', $want))) : [];
        if (empty($want)) { echo json_encode(['ok' => false, 'msg' => '請先勾選要標記的人員']); exit(); }

        // 後端以同一份判定重算一次可否代按（鐵律8：不可只信前端送來的名單）
        $recipients = eqr_recipients($db, $eid);
        $done = []; $skipped = [];
        $db->beginTransaction();
        $ins = $db->prepare("INSERT INTO live_event_for_user (user_id, live_event_id, oready_read, read_at, signed_via) VALUES (?,?,1,NOW(),?)");
        $upd = $db->prepare("UPDATE live_event_for_user SET oready_read = 1, read_at = COALESCE(read_at, NOW()), signed_via = COALESCE(signed_via, ?) WHERE id = ?");
        $chk = $db->prepare("SELECT id FROM live_event_for_user WHERE user_id = ? AND live_event_id = ? LIMIT 1");
        foreach ($want as $tuid) {
            $r = $recipients[$tuid] ?? null;
            if (!$r)              { $skipped[] = ['user_id' => $tuid, 'name' => '員工#' . $tuid, 'why' => '不是這則通知的對象']; continue; }
            if (!$r['eligible'])  { $skipped[] = ['user_id' => $tuid, 'name' => $r['name'], 'why' => $r['why']]; continue; }
            $chk->execute([$tuid, $eid]);
            $exist = $chk->fetchColumn();
            if ($exist) $upd->execute([$uid, (int)$exist]);
            else        $ins->execute([$tuid, $eid, $uid]);
            $done[] = ['user_id' => $tuid, 'name' => $r['name']];
        }
        // 稽核紀錄：代按已閱是「替別人留下已讀證據」，一定要留下是誰在什麼時候代的
        if ($done) {
            try {
                $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                              VALUES ('update','notice_quick_read',?,?,?,?,?,NOW())")
                   ->execute([
                       (string)$eid,
                       mb_substr((string)$ev['title'], 0, 190),
                       json_encode(['marked' => $done, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE),
                       $uid,
                       (string)($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? ('user#' . $uid)),
                   ]);
            } catch (Throwable $e) { error_log('[quickRead] audit failed: ' . $e->getMessage()); }
        }
        $db->commit();
        echo json_encode(['ok' => true, 'marked' => count($done), 'done' => $done, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode(['ok' => false, 'msg' => '無效的操作']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[quickRead] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
