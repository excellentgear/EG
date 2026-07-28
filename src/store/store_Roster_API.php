<?php
/**
 * store_Roster_API.php — 通用輪值排班 後端 API
 * 前端：views/pages/roster.php ｜ 共用引擎：src/common/roster_lib.php
 * 回傳統一 {success,message,...}；action 以 POST/GET 帶入。
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_set_cookie_params(43200);
session_start();

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/roster_lib.php';

header('Content-Type: application/json; charset=utf-8');

function jout($a = []) { echo json_encode(array_merge(['success' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jfail($m) { echo json_encode(['success' => false, 'message' => $m], JSON_UNESCAPED_UNICODE); exit; }
function jerr($m, $c = 400) { http_response_code($c); echo json_encode(['success' => false, 'message' => $m], JSON_UNESCAPED_UNICODE); exit; }

if (!isset($_SESSION['id'])) jerr('未登入', 401);

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$me   = roster_current_user($pdo);
$MYID = (int)$me['id'];
$features = rbac_user_features($pdo, $MYID);

$CAN_VIEW   = rbac_has($features, 'roster_view');
$CAN_CREATE = rbac_has($features, 'roster_create');
$CAN_EDIT   = rbac_has($features, 'roster_edit');
$CAN_DELETE = rbac_has($features, 'roster_delete');
$IS_ADMIN   = rbac_has($features, 'roster_admin') || rbac_has($features, 'all');
if (!$CAN_VIEW && !$IS_ADMIN) jerr('無檢閱權限', 403);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** 取 board 並確認可編輯（建立者本人或管理者） */
function load_board_editable(PDO $pdo, int $id, int $myid, bool $isAdmin): array {
    $st = $pdo->prepare("SELECT * FROM roster_board WHERE id=?");
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) jerr('排班表不存在', 404);
    if ((int)$b['owner_id'] !== $myid && !$isAdmin) jerr('只有建立者本人或管理者可異動此表', 403);
    return $b;
}

try {
switch ($action) {

/* ── 我的/共享 排班表清單 ── */
case 'list_boards': {
    $scope = $_POST['scope'] ?? 'all'; // mine|shared|all
    $rows = $pdo->query("SELECT * FROM roster_board WHERE status IN ('active','archived') ORDER BY status ASC, updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $b) {
        $mine = ((int)$b['owner_id'] === $MYID);
        if (!$mine && !roster_can_view_board($pdo, $b, $MYID, $features)) continue;
        if ($scope === 'mine' && !$mine) continue;
        if ($scope === 'shared' && $mine) continue;
        $laneCnt = (int)$pdo->query("SELECT COUNT(*) FROM roster_lane WHERE board_id=" . (int)$b['id'])->fetchColumn();
        $memCnt  = (int)$pdo->query("SELECT COUNT(*) FROM roster_member WHERE board_id=" . (int)$b['id'] . " AND active=1")->fetchColumn();
        // 我的下一次值勤
        $nq = $pdo->prepare("SELECT MIN(duty_date) FROM roster_assignment WHERE board_id=? AND user_id=? AND duty_date>=CURDATE()");
        $nq->execute([$b['id'], $MYID]);
        $nextMy = $nq->fetchColumn();
        $ownerName = $pdo->prepare("SELECT user_cname FROM user WHERE id=?"); $ownerName->execute([$b['owner_id']]);
        $out[] = [
            'id' => (int)$b['id'], 'name' => $b['name'], 'purpose' => $b['purpose'],
            'is_mine' => $mine, 'owner_name' => (string)$ownerName->fetchColumn(),
            'lane_count' => $laneCnt, 'member_count' => $memCnt,
            'status' => $b['status'], 'next_my_duty' => $nextMy ?: null,
            'can_edit' => ($mine || $IS_ADMIN),
        ];
    }
    jout(['boards' => $out]);
}

/* ── 單張表完整設定（供編輯器） ── */
case 'get_board': {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM roster_board WHERE id=?");
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) jerr('排班表不存在', 404);
    if ((int)$b['owner_id'] !== $MYID && !roster_can_view_board($pdo, $b, $MYID, $features)) jerr('無權檢視', 403);

    $lanes = $pdo->prepare("SELECT * FROM roster_lane WHERE board_id=? ORDER BY sort_order, id");
    $lanes->execute([$id]); $lanes = $lanes->fetchAll(PDO::FETCH_ASSOC);

    // 成員（依 scope）
    $mem = $pdo->prepare("SELECT lane_id, user_id, sort_order FROM roster_member WHERE board_id=? AND active=1 ORDER BY sort_order, id");
    $mem->execute([$id]); $memRows = $mem->fetchAll(PDO::FETCH_ASSOC);
    $shared = []; $byLane = [];
    foreach ($memRows as $m) {
        if ($m['lane_id'] === null) $shared[] = (int)$m['user_id'];
        else $byLane[(int)$m['lane_id']][] = (int)$m['user_id'];
    }
    foreach ($lanes as &$ln) { $ln['members'] = $byLane[(int)$ln['id']] ?? []; } unset($ln);

    $vis = $pdo->prepare("SELECT target_type, target_id FROM roster_visibility WHERE board_id=?");
    $vis->execute([$id]);
    $visArr = [];
    foreach ($vis->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $visArr[] = $t['target_type'] === 'all' ? 'all' : $t['target_type'] . '-' . $t['target_id'];
    }
    // 名稱對照
    $allIds = array_merge($shared, array_merge(...array_values($byLane) ?: [[]]));
    jout(['board' => $b, 'lanes' => $lanes, 'shared_members' => $shared, 'visibility' => $visArr,
          'names' => roster_user_name_map($pdo, $allIds), 'can_edit' => ((int)$b['owner_id'] === $MYID || $IS_ADMIN)]);
}

/* ── 建立 / 更新 ── */
case 'save_board': {
    $p = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($p)) jfail('資料格式錯誤');
    $id = (int)($p['id'] ?? 0);
    $name = trim($p['name'] ?? '');
    if ($name === '') jfail('請輸入表名稱');
    $lanesIn = $p['lanes'] ?? [];
    if (!is_array($lanesIn) || count($lanesIn) === 0) jfail('至少要有一個職務欄');
    $startDate = $p['start_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) jfail('起始日格式錯誤');

    if ($id === 0 && !$CAN_CREATE && !$IS_ADMIN) jerr('無建立權限', 403);
    if ($id !== 0) load_board_editable($pdo, $id, $MYID, $IS_ADMIN);

    $memberMode = in_array($p['member_mode'] ?? '', ['per_lane', 'shared_pool'], true) ? $p['member_mode'] : 'per_lane';
    $cadence    = in_array($p['exec_cadence'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $p['exec_cadence'] : 'daily';
    $policy     = in_array($p['holiday_policy'] ?? '', ['skip', 'postpone', 'advance'], true) ? $p['holiday_policy'] : 'skip';
    $rotate     = in_array($p['rotate_unit'] ?? '', ['each', 'weekly', 'monthly'], true) ? $p['rotate_unit'] : 'each';
    $execCount  = max(1, (int)($p['exec_count'] ?? 1));
    $execWd     = implode(',', array_filter(array_map('intval', $p['exec_weekdays'] ?? []), fn($x) => $x >= 1 && $x <= 7));
    $execMd     = implode(',', array_filter(array_map('intval', $p['exec_monthdays'] ?? []), fn($x) => $x >= 1 && $x <= 31));
    $signReq    = !empty($p['sign_required']) ? 1 : 0;

    $pdo->beginTransaction();
    try {
        if ($id === 0) {
            $st = $pdo->prepare("INSERT INTO roster_board
                (name,purpose,owner_id,member_mode,exec_cadence,exec_count,exec_weekdays,exec_monthdays,holiday_policy,rotate_unit,start_date,sign_required)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$name, trim($p['purpose'] ?? ''), $MYID, $memberMode, $cadence, $execCount, $execWd, $execMd, $policy, $rotate, $startDate, $signReq]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $st = $pdo->prepare("UPDATE roster_board SET name=?,purpose=?,member_mode=?,exec_cadence=?,exec_count=?,exec_weekdays=?,exec_monthdays=?,holiday_policy=?,rotate_unit=?,start_date=?,sign_required=? WHERE id=?");
            $st->execute([$name, trim($p['purpose'] ?? ''), $memberMode, $cadence, $execCount, $execWd, $execMd, $policy, $rotate, $startDate, $signReq, $id]);
        }

        // lanes：以 id 對應更新、無 id 新增、DB 有而 payload 無者刪除(連同其排班)
        $exLanes = $pdo->prepare("SELECT id FROM roster_lane WHERE board_id=?");
        $exLanes->execute([$id]);
        $exLaneIds = array_map('intval', $exLanes->fetchAll(PDO::FETCH_COLUMN));
        $keptLaneIds = [];
        $laneIdByIndex = [];
        $upL = $pdo->prepare("UPDATE roster_lane SET lane_name=?,color=?,shift_type_id=?,sort_order=? WHERE id=? AND board_id=?");
        $inL = $pdo->prepare("INSERT INTO roster_lane (board_id,lane_name,color,shift_type_id,sort_order) VALUES (?,?,?,?,?)");
        foreach ($lanesIn as $i => $ln) {
            $lname = trim($ln['lane_name'] ?? '');
            if ($lname === '') $lname = '職務' . ($i + 1);
            $color = trim($ln['color'] ?? '');
            $shift = isset($ln['shift_type_id']) && $ln['shift_type_id'] !== '' ? (int)$ln['shift_type_id'] : null;
            $lid = (int)($ln['id'] ?? 0);
            if ($lid && in_array($lid, $exLaneIds, true)) {
                $upL->execute([$lname, $color, $shift, $i, $lid, $id]);
            } else {
                $inL->execute([$id, $lname, $color, $shift, $i]);
                $lid = (int)$pdo->lastInsertId();
            }
            $keptLaneIds[] = $lid;
            $laneIdByIndex[$i] = $lid;
        }
        $toDelLanes = array_diff($exLaneIds, $keptLaneIds);
        foreach ($toDelLanes as $dl) {
            $pdo->prepare("DELETE FROM roster_assignment WHERE lane_id=?")->execute([$dl]);
            $pdo->prepare("DELETE FROM roster_member WHERE lane_id=?")->execute([$dl]);
            $pdo->prepare("DELETE FROM roster_lane WHERE id=? AND board_id=?")->execute([$dl, $id]);
        }

        // 成員 reconcile（保留離職/移出的稽核；重覆上架則復用）
        $reconcile = function ($laneId, array $uids) use ($pdo, $id) {
            $scope = $laneId === null ? "lane_id IS NULL" : "lane_id = " . (int)$laneId;
            $ex = $pdo->query("SELECT id,user_id,active FROM roster_member WHERE board_id=$id AND $scope")->fetchAll(PDO::FETCH_ASSOC);
            $byUser = [];
            foreach ($ex as $r) $byUser[(int)$r['user_id']] = $r;
            $order = 0; $seen = [];
            foreach ($uids as $uid) {
                $uid = (int)$uid; if ($uid <= 0 || isset($seen[$uid])) continue; $seen[$uid] = 1;
                if (isset($byUser[$uid])) {
                    $pdo->prepare("UPDATE roster_member SET active=1,sort_order=?,removed_at=NULL,removed_reason='' WHERE id=?")
                        ->execute([$order, $byUser[$uid]['id']]);
                } else {
                    $pdo->prepare("INSERT INTO roster_member (board_id,lane_id,user_id,sort_order,active) VALUES (?,?,?,?,1)")
                        ->execute([$id, $laneId, $uid, $order]);
                }
                $order++;
            }
            foreach ($byUser as $uid => $r) {
                if (!isset($seen[$uid]) && (int)$r['active'] === 1) {
                    $pdo->prepare("UPDATE roster_member SET active=0,removed_at=NOW(),removed_reason='manual' WHERE id=?")->execute([$r['id']]);
                }
            }
        };
        if ($memberMode === 'shared_pool') {
            $reconcile(null, array_map('intval', $p['shared_members'] ?? []));
            // 清掉 per_lane 殘留（模式切換）
            $pdo->prepare("DELETE FROM roster_member WHERE board_id=? AND lane_id IS NOT NULL")->execute([$id]);
        } else {
            foreach ($lanesIn as $i => $ln) {
                $reconcile($laneIdByIndex[$i], array_map('intval', $ln['members'] ?? []));
            }
            $pdo->prepare("DELETE FROM roster_member WHERE board_id=? AND lane_id IS NULL")->execute([$id]);
        }

        // 公開對象
        $pdo->prepare("DELETE FROM roster_visibility WHERE board_id=?")->execute([$id]);
        $visIn = $p['visibility'] ?? [];
        $insV = $pdo->prepare("INSERT INTO roster_visibility (board_id,target_type,target_id) VALUES (?,?,?)");
        foreach ($visIn as $v) {
            if ($v === 'all') { $insV->execute([$id, 'all', 0]); continue; }
            if (strpos($v, 'dept-') === 0)   $insV->execute([$id, 'dept', (int)substr($v, 5)]);
            elseif (strpos($v, 'status-') === 0) $insV->execute([$id, 'status', (int)substr($v, 7)]);
            elseif (strpos($v, 'user-') === 0)   $insV->execute([$id, 'user', (int)substr($v, 5)]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jfail('儲存失敗：' . $e->getMessage());
    }

    // 重算未來排班（過去凍結）
    $gen = roster_regenerate($pdo, $id);
    jout(['id' => $id, 'generated' => $gen['generated'] ?? 0]);
}

/* ── 刪除 / 封存 ── */
case 'delete_board': {
    $id = (int)($_POST['id'] ?? 0);
    load_board_editable($pdo, $id, $MYID, $IS_ADMIN);
    if (!$CAN_DELETE && !$IS_ADMIN) jerr('無刪除權限', 403);
    $pdo->beginTransaction();
    try {
        foreach (['roster_assignment','roster_member','roster_lane','roster_visibility','roster_adjust_log'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE board_id=?")->execute([$id]);
        }
        $pdo->prepare("DELETE FROM roster_board WHERE id=?")->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('刪除失敗：' . $e->getMessage()); }
    jout();
}
case 'archive_board': {
    $id = (int)($_POST['id'] ?? 0);
    load_board_editable($pdo, $id, $MYID, $IS_ADMIN);
    $to = ($_POST['to'] ?? 'archived') === 'active' ? 'active' : 'archived';
    $pdo->prepare("UPDATE roster_board SET status=? WHERE id=?")->execute([$to, $id]);
    if ($to === 'active') roster_regenerate($pdo, $id);
    jout(['status' => $to]);
}

/* ── 兩個月月曆 ── */
case 'get_calendar': {
    $id = (int)($_POST['id'] ?? 0);
    $ym = $_POST['ym'] ?? date('Y-m'); // 起始月
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    $filterUser = (int)($_POST['filter_user'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM roster_board WHERE id=?");
    $st->execute([$id]); $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) jerr('排班表不存在', 404);
    $mine = ((int)$b['owner_id'] === $MYID);
    if (!$mine && !roster_can_view_board($pdo, $b, $MYID, $features)) jerr('無權檢視', 403);

    $from = $ym . '-01';
    $secondObj = (new DateTime($from))->modify('+1 month');
    $second = $secondObj->format('Y-m');
    $to = $secondObj->modify('last day of this month')->format('Y-m-d');

    $lanes = $pdo->prepare("SELECT id,lane_name,color,shift_type_id,sort_order FROM roster_lane WHERE board_id=? ORDER BY sort_order,id");
    $lanes->execute([$id]); $lanes = $lanes->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT id,lane_id,duty_date,user_id,orig_user_id,sign_status,signed_at,is_adjusted,adjust_note FROM roster_assignment WHERE board_id=? AND duty_date BETWEEN ? AND ?";
    $args = [$id, $from, $to];
    if ($filterUser) { $sql .= " AND user_id=?"; $args[] = $filterUser; }
    $ass = $pdo->prepare($sql); $ass->execute($args); $ass = $ass->fetchAll(PDO::FETCH_ASSOC);

    $uids = array_map(fn($r) => (int)$r['user_id'], $ass);
    $names = roster_user_name_map($pdo, $uids);
    $cells = [];
    foreach ($ass as $r) {
        $uid = (int)$r['user_id'];
        $cells[$r['duty_date']][] = [
            'aid' => (int)$r['id'], 'lane_id' => (int)$r['lane_id'], 'user_id' => $uid,
            'name' => $names[$uid]['name'] ?? ('#' . $uid), 'left' => $names[$uid]['left'] ?? false,
            'sign' => (int)$r['sign_status'], 'signed_at' => $r['signed_at'] ? substr($r['signed_at'], 0, 16) : null,
            'adjusted' => (int)$r['is_adjusted'], 'mine' => ($uid === $MYID),
            'can_sign' => ($uid === $MYID), 'note' => $r['adjust_note'],
        ];
    }
    $ctx = roster_workday_context($pdo, $from, $to);

    // 篩選用人員清單（此表所有曾/現排班者）
    $ppl = $pdo->prepare("SELECT DISTINCT user_id FROM roster_assignment WHERE board_id=?");
    $ppl->execute([$id]);
    $pplIds = array_map('intval', $ppl->fetchAll(PDO::FETCH_COLUMN));
    $pplNames = roster_user_name_map($pdo, $pplIds);
    $people = [];
    foreach ($pplIds as $pid) $people[] = ['id' => $pid, 'name' => $pplNames[$pid]['name'] ?? ('#' . $pid), 'left' => $pplNames[$pid]['left'] ?? false];
    usort($people, fn($a, $c) => strcmp($a['name'], $c['name']));

    jout([
        'board' => ['id' => (int)$b['id'], 'name' => $b['name'], 'sign_required' => (int)$b['sign_required'],
                    'can_edit' => ($mine || $IS_ADMIN), 'is_admin' => $IS_ADMIN],
        'lanes' => $lanes, 'months' => [$ym, $second],
        'cells' => $cells, 'holidays' => array_keys($ctx['holidays']), 'makeup' => array_keys($ctx['makeup']),
        'people' => $people, 'today' => date('Y-m-d'),
    ]);
}

/* ── 簽核 / 取消 ── */
case 'sign': case 'unsign': {
    $aid = (int)($_POST['aid'] ?? 0);
    $st = $pdo->prepare("SELECT a.*, b.owner_id FROM roster_assignment a JOIN roster_board b ON b.id=a.board_id WHERE a.id=?");
    $st->execute([$aid]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('排班不存在', 404);
    $isOwnerOrAdmin = ((int)$a['owner_id'] === $MYID) || $IS_ADMIN;
    if ((int)$a['user_id'] !== $MYID && !$isOwnerOrAdmin) jerr('只有值勤本人或管理者可簽核', 403);
    if ($action === 'sign') {
        $pdo->prepare("UPDATE roster_assignment SET sign_status=1,signed_at=NOW(),signed_by=? WHERE id=?")->execute([$MYID, $aid]);
    } else {
        if (!$isOwnerOrAdmin && (int)$a['signed_by'] !== $MYID) jerr('無法取消他人簽核', 403);
        $pdo->prepare("UPDATE roster_assignment SET sign_status=0,signed_at=NULL,signed_by=NULL WHERE id=?")->execute([$aid]);
    }
    jout();
}

/* ── 單次調班 ── */
case 'adjust_single': {
    $aid = (int)($_POST['aid'] ?? 0);
    $newUser = (int)($_POST['new_user_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $st = $pdo->prepare("SELECT a.*, b.owner_id FROM roster_assignment a JOIN roster_board b ON b.id=a.board_id WHERE a.id=?");
    $st->execute([$aid]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('排班不存在', 404);
    if ((int)$a['owner_id'] !== $MYID && !$IS_ADMIN) jerr('只有建立者或管理者可調班', 403);
    if ($newUser <= 0) jfail('請選擇代班人員');
    $orig = $a['orig_user_id'] ?: $a['user_id'];
    $pdo->prepare("UPDATE roster_assignment SET user_id=?,orig_user_id=?,is_adjusted=1,adjust_note=?,sign_status=0,signed_at=NULL,signed_by=NULL WHERE id=?")
        ->execute([$newUser, $orig, $note, $aid]);
    $pdo->prepare("INSERT INTO roster_adjust_log (board_id,lane_id,scope,date_from,date_to,from_user_id,to_user_id,note,operator_id) VALUES (?,?, 'single',?,?,?,?,?,?)")
        ->execute([$a['board_id'], $a['lane_id'], $a['duty_date'], $a['duty_date'], $a['user_id'], $newUser, $note, $MYID]);
    jout();
}

/* ── 區間調班 ── */
case 'adjust_range': {
    $bid = (int)($_POST['board_id'] ?? 0);
    $laneId = ($_POST['lane_id'] ?? '') === '' ? null : (int)$_POST['lane_id'];
    $df = $_POST['date_from'] ?? ''; $dt = $_POST['date_to'] ?? '';
    $fromUser = (int)($_POST['from_user_id'] ?? 0); // 0=不限原負責人
    $toUser = (int)($_POST['to_user_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) jfail('日期格式錯誤');
    if ($df > $dt) jfail('起訖日期顛倒');
    if ($toUser <= 0) jfail('請選擇代班人員');
    load_board_editable($pdo, $bid, $MYID, $IS_ADMIN);

    $sql = "SELECT id,user_id,orig_user_id FROM roster_assignment WHERE board_id=? AND duty_date BETWEEN ? AND ?";
    $args = [$bid, $df, $dt];
    if ($laneId !== null) { $sql .= " AND lane_id=?"; $args[] = $laneId; }
    if ($fromUser > 0)    { $sql .= " AND user_id=?"; $args[] = $fromUser; }
    $rows = $pdo->prepare($sql); $rows->execute($args); $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("UPDATE roster_assignment SET user_id=?,orig_user_id=?,is_adjusted=1,adjust_note=?,sign_status=0,signed_at=NULL,signed_by=NULL WHERE id=?");
        foreach ($rows as $r) {
            $orig = $r['orig_user_id'] ?: $r['user_id'];
            $up->execute([$toUser, $orig, $note, $r['id']]);
        }
        $pdo->prepare("INSERT INTO roster_adjust_log (board_id,lane_id,scope,date_from,date_to,from_user_id,to_user_id,note,operator_id) VALUES (?,?, 'range',?,?,?,?,?,?)")
            ->execute([$bid, $laneId, $df, $dt, ($fromUser ?: null), $toUser, $note, $MYID]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('調班失敗：' . $e->getMessage()); }
    jout(['affected' => count($rows)]);
}

/* ── 手動重算 / 延長 ── */
case 'regenerate': {
    $id = (int)($_POST['id'] ?? 0);
    load_board_editable($pdo, $id, $MYID, $IS_ADMIN);
    $gen = roster_regenerate($pdo, $id);
    jout(['generated' => $gen['generated'] ?? 0]);
}

default:
    jfail('未知動作：' . $action);
}
} catch (Exception $e) {
    jerr('伺服器錯誤：' . $e->getMessage(), 500);
}
