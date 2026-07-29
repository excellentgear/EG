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
require_once __DIR__ . '/../common/delegate_lib.php'; // 代理人解析（請假補班用）

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

/** 對調兩格（同欄），交換負責人、清簽核、標記調班、寫紀錄 */
function roster_swap_two(PDO $pdo, array $a, array $t, int $op, string $note) {
    $ua = (int)$a['user_id']; $ut = (int)$t['user_id'];
    $up = $pdo->prepare("UPDATE roster_assignment SET user_id=?,orig_user_id=?,is_adjusted=1,adjust_note=?,pending_swap_id=NULL,sign_status=0,signed_at=NULL,signed_by=NULL WHERE id=?");
    $up->execute([$ut, ($a['orig_user_id'] ?: $a['user_id']), $note, $a['id']]);
    $up->execute([$ua, ($t['orig_user_id'] ?: $t['user_id']), $note, $t['id']]);
    $pdo->prepare("INSERT INTO roster_adjust_log (board_id,lane_id,scope,date_from,date_to,from_user_id,to_user_id,note,operator_id) VALUES (?,?, 'swap',?,?,?,?,?,?)")
        ->execute([$a['board_id'], $a['lane_id'], $a['duty_date'], $t['duty_date'], $ua, $ut, $note, $op]);
}

/** 區間內把 A 與 B 的班對調（同欄或全欄），清簽核、標記、寫紀錄，回傳受影響格數 */
function roster_swap_range(PDO $pdo, int $bid, $laneId, string $df, string $dt, int $ua, int $ub, int $op, string $note) {
    $sql = "SELECT id,user_id,orig_user_id FROM roster_assignment WHERE board_id=? AND duty_date BETWEEN ? AND ? AND user_id IN (?,?)";
    $args = [$bid, $df, $dt, $ua, $ub];
    if ($laneId !== null) { $sql .= " AND lane_id=?"; $args[] = $laneId; }
    $rows = $pdo->prepare($sql); $rows->execute($args); $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    $up = $pdo->prepare("UPDATE roster_assignment SET user_id=?,orig_user_id=?,is_adjusted=1,adjust_note=?,pending_swap_id=NULL,sign_status=0,signed_at=NULL,signed_by=NULL WHERE id=?");
    foreach ($rows as $r) {
        $nu = ((int)$r['user_id'] === $ua) ? $ub : $ua;
        $up->execute([$nu, ($r['orig_user_id'] ?: $r['user_id']), $note, $r['id']]);
    }
    $pdo->prepare("INSERT INTO roster_adjust_log (board_id,lane_id,scope,date_from,date_to,from_user_id,to_user_id,note,operator_id) VALUES (?,?, 'swap_range',?,?,?,?,?,?)")
        ->execute([$bid, $laneId, $df, $dt, $ua, $ub, $note, $op]);
    return count($rows);
}

try {
switch ($action) {

/* ── 我的/共享 排班表清單 ── */
case 'list_boards': {
    // 順路同步：離職/留停等非在職者自動移出未來、回任者自動復入（過去凍結）
    try { roster_sync_member_status($pdo); } catch (Exception $e) {}
    // 順路觸發：值勤提醒／請假未補位提醒（一天只發一次，roster_notify_log 防重）
    try { roster_daily_reminders($pdo); } catch (Exception $e) {}
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
    if (!is_array($lanesIn) || count($lanesIn) === 0) jfail('至少要有一個輪值項目');
    $startDate = $p['start_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) jfail('起始日格式錯誤');
    $endDate = trim($p['end_date'] ?? '');
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) jfail('終止日格式錯誤');
    if ($endDate !== '' && $endDate < $startDate) jfail('終止日不能早於起始日');
    $endDateSql = $endDate !== '' ? $endDate : null;

    if ($id === 0 && !$CAN_CREATE && !$IS_ADMIN) jerr('無建立權限', 403);
    if ($id !== 0) load_board_editable($pdo, $id, $MYID, $IS_ADMIN);

    $memberMode = in_array($p['member_mode'] ?? '', ['per_lane', 'shared_pool'], true) ? $p['member_mode'] : 'per_lane';
    $cadence    = in_array($p['exec_cadence'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $p['exec_cadence'] : 'daily';
    $policy     = in_array($p['holiday_policy'] ?? '', ['skip', 'postpone', 'advance'], true) ? $p['holiday_policy'] : 'skip';
    $rotate     = in_array($p['rotate_unit'] ?? '', ['each', 'day', 'week', 'month'], true) ? $p['rotate_unit'] : 'each';
    $rotateN    = max(1, (int)($p['rotate_n'] ?? 1));
    // 非管理員只允許基本頻率（每次/每週/每月，N=1）
    if (!$IS_ADMIN) { if (!in_array($rotate, ['each', 'week', 'month'], true)) $rotate = 'each'; $rotateN = 1; }
    $execCount  = max(1, (int)($p['exec_count'] ?? 1));
    $wasCreate  = ($id === 0);
    $execWd     = implode(',', array_filter(array_map('intval', $p['exec_weekdays'] ?? []), fn($x) => $x >= 1 && $x <= 7));
    $execMd     = implode(',', array_filter(array_map('intval', $p['exec_monthdays'] ?? []), fn($x) => $x >= 1 && $x <= 31));
    $signReq    = !empty($p['sign_required']) ? 1 : 0;

    $pdo->beginTransaction();
    try {
        if ($id === 0) {
            $st = $pdo->prepare("INSERT INTO roster_board
                (name,purpose,owner_id,member_mode,exec_cadence,exec_count,exec_weekdays,exec_monthdays,holiday_policy,rotate_unit,rotate_n,start_date,end_date,sign_required)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$name, trim($p['purpose'] ?? ''), $MYID, $memberMode, $cadence, $execCount, $execWd, $execMd, $policy, $rotate, $rotateN, $startDate, $endDateSql, $signReq]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $st = $pdo->prepare("UPDATE roster_board SET name=?,purpose=?,member_mode=?,exec_cadence=?,exec_count=?,exec_weekdays=?,exec_monthdays=?,holiday_policy=?,rotate_unit=?,rotate_n=?,start_date=?,end_date=?,sign_required=? WHERE id=?");
            $st->execute([$name, trim($p['purpose'] ?? ''), $memberMode, $cadence, $execCount, $execWd, $execMd, $policy, $rotate, $rotateN, $startDate, $endDateSql, $signReq, $id]);
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
            if ($lname === '') $lname = '項目' . ($i + 1);
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
        if ($wasCreate) {
            $pdo->prepare("INSERT INTO roster_board_log (board_id,board_name,action,operator_id,operator_name) VALUES (?,?, 'create',?,?)")
                ->execute([$id, $name, $MYID, $me['name']]);
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

/* ── 複製排班表（複製設定/項目/人員/公開對象為自己的新表）── */
case 'copy_board': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無建立權限', 403);
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM roster_board WHERE id=?"); $st->execute([$id]); $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) jerr('排班表不存在', 404);
    if ((int)$b['owner_id'] !== $MYID && !roster_can_view_board($pdo, $b, $MYID, $features)) jerr('無權複製此表', 403);
    $pdo->beginTransaction();
    try {
        $newName = mb_substr($b['name'], 0, 88) . '（複本）';
        $pdo->prepare("INSERT INTO roster_board
            (name,purpose,owner_id,member_mode,exec_cadence,exec_count,exec_weekdays,exec_monthdays,holiday_policy,rotate_unit,rotate_n,start_date,end_date,sign_required,status)
            SELECT ?, purpose, ?, member_mode,exec_cadence,exec_count,exec_weekdays,exec_monthdays,holiday_policy,rotate_unit,rotate_n,start_date,end_date,sign_required,'active'
            FROM roster_board WHERE id=?")->execute([$newName, $MYID, $id]);
        $nid = (int)$pdo->lastInsertId();
        // 項目（lane）對應新 id
        $laneMap = [];
        $lq = $pdo->prepare("SELECT * FROM roster_lane WHERE board_id=? ORDER BY sort_order,id"); $lq->execute([$id]);
        $il = $pdo->prepare("INSERT INTO roster_lane (board_id,lane_name,color,shift_type_id,sort_order) VALUES (?,?,?,?,?)");
        foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $il->execute([$nid, $l['lane_name'], $l['color'], $l['shift_type_id'], $l['sort_order']]);
            $laneMap[(int)$l['id']] = (int)$pdo->lastInsertId();
        }
        // 成員（在職中的）
        $mq = $pdo->prepare("SELECT lane_id,user_id,sort_order FROM roster_member WHERE board_id=? AND active=1 ORDER BY sort_order,id"); $mq->execute([$id]);
        $im = $pdo->prepare("INSERT INTO roster_member (board_id,lane_id,user_id,sort_order,active) VALUES (?,?,?,?,1)");
        foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $lid = $m['lane_id'] === null ? null : ($laneMap[(int)$m['lane_id']] ?? null);
            $im->execute([$nid, $lid, $m['user_id'], $m['sort_order']]);
        }
        // 公開對象
        $vq = $pdo->prepare("SELECT target_type,target_id FROM roster_visibility WHERE board_id=?"); $vq->execute([$id]);
        $iv = $pdo->prepare("INSERT INTO roster_visibility (board_id,target_type,target_id) VALUES (?,?,?)");
        foreach ($vq->fetchAll(PDO::FETCH_ASSOC) as $v) $iv->execute([$nid, $v['target_type'], $v['target_id']]);
        // 建立紀錄
        $pdo->prepare("INSERT INTO roster_board_log (board_id,board_name,action,detail,operator_id,operator_name) VALUES (?,?, 'create',?,?,?)")
            ->execute([$nid, $newName, '複製自 #' . $id . ' ' . $b['name'], $MYID, $me['name']]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('複製失敗：' . $e->getMessage()); }
    roster_regenerate($pdo, $nid);
    jout(['id' => $nid, 'name' => $newName]);
}

/* ── 刪除 / 封存 ── */
case 'delete_board': {
    $id = (int)($_POST['id'] ?? 0);
    $b = load_board_editable($pdo, $id, $MYID, $IS_ADMIN);
    if (!$CAN_DELETE && !$IS_ADMIN) jerr('無刪除權限', 403);
    $pdo->beginTransaction();
    try {
        // 刪除紀錄先寫（board_log 不隨表刪除）
        $pdo->prepare("INSERT INTO roster_board_log (board_id,board_name,action,operator_id,operator_name) VALUES (?,?, 'delete',?,?)")
            ->execute([$id, $b['name'], $MYID, $me['name']]);
        foreach (['roster_assignment','roster_member','roster_lane','roster_visibility','roster_adjust_log','roster_swap_request'] as $t) {
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

    // 排班無終止日：翻到還沒物化的月份就即時補算到該月（凍結過去、只補未來）
    if ($b['status'] === 'active') {
        $maxD = $pdo->query("SELECT MAX(duty_date) FROM roster_assignment WHERE board_id=" . (int)$id)->fetchColumn();
        if (!$maxD || $maxD < $to) { try { roster_regenerate($pdo, (int)$id, null, $to); } catch (Exception $e) {} }
    }

    $lanes = $pdo->prepare("SELECT id,lane_name,color,shift_type_id,sort_order FROM roster_lane WHERE board_id=? ORDER BY sort_order,id");
    $lanes->execute([$id]); $lanes = $lanes->fetchAll(PDO::FETCH_ASSOC);

    // 每欄可對調的同組人員（per_lane=該欄名單；shared_pool=全表共用池，套用到每一欄）
    $laneMembers = [];
    if ($b['member_mode'] === 'shared_pool') {
        $mq = $pdo->prepare("SELECT user_id FROM roster_member WHERE board_id=? AND lane_id IS NULL AND active=1 ORDER BY sort_order,id");
        $mq->execute([$id]); $pool = array_map('intval', $mq->fetchAll(PDO::FETCH_COLUMN));
        $pn = roster_user_name_map($pdo, $pool);
        $poolList = array_map(fn($u) => ['id' => $u, 'name' => $pn[$u]['name'] ?? ('#' . $u)], $pool);
        foreach ($lanes as $ln) $laneMembers[(int)$ln['id']] = $poolList;
    } else {
        $mq = $pdo->prepare("SELECT lane_id,user_id FROM roster_member WHERE board_id=? AND lane_id IS NOT NULL AND active=1 ORDER BY sort_order,id");
        $mq->execute([$id]);
        $tmp = [];
        foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $m) $tmp[(int)$m['lane_id']][] = (int)$m['user_id'];
        $allm = array_merge(...array_values($tmp) ?: [[]]);
        $mn = roster_user_name_map($pdo, $allm);
        foreach ($tmp as $lid => $us) $laneMembers[$lid] = array_map(fn($u) => ['id' => $u, 'name' => $mn[$u]['name'] ?? ('#' . $u)], $us);
    }

    $sql = "SELECT id,lane_id,duty_date,user_id,orig_user_id,sign_status,signed_at,is_adjusted,adjust_note,pending_swap_id FROM roster_assignment WHERE board_id=? AND duty_date BETWEEN ? AND ?";
    $args = [$id, $from, $to];
    if ($filterUser) { $sql .= " AND user_id=?"; $args[] = $filterUser; }
    $ass = $pdo->prepare($sql); $ass->execute($args); $ass = $ass->fetchAll(PDO::FETCH_ASSOC);

    $uids = array_map(fn($r) => (int)$r['user_id'], $ass);
    $names = roster_user_name_map($pdo, $uids);
    $leaveMap = roster_leave_map($pdo, $uids, $from, $to);
    $cells = [];
    foreach ($ass as $r) {
        $uid = (int)$r['user_id'];
        $lv = $leaveMap[$uid . '|' . $r['duty_date']] ?? null;
        $cells[$r['duty_date']][] = [
            'aid' => (int)$r['id'], 'lane_id' => (int)$r['lane_id'], 'user_id' => $uid,
            'name' => $names[$uid]['name'] ?? ('#' . $uid), 'left' => $names[$uid]['left'] ?? false,
            'sign' => (int)$r['sign_status'], 'signed_at' => $r['signed_at'] ? substr($r['signed_at'], 0, 16) : null,
            'adjusted' => (int)$r['is_adjusted'], 'mine' => ($uid === $MYID),
            'pending' => $r['pending_swap_id'] ? (int)$r['pending_swap_id'] : 0,
            'leave' => $lv['label'] ?? null, 'leave_full' => $lv ? (bool)$lv['full'] : false,
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
                    'can_edit' => ($mine || $IS_ADMIN), 'is_admin' => $IS_ADMIN, 'is_owner' => $mine,
                    'swap_bypass' => ($mine || $IS_ADMIN), 'member_mode' => $b['member_mode']],
        'lanes' => $lanes, 'lane_members' => $laneMembers, 'months' => [$ym, $second],
        'cells' => $cells, 'holidays' => array_keys($ctx['holidays']), 'makeup' => array_keys($ctx['makeup']),
        'people' => $people, 'today' => date('Y-m-d'),
    ]);
}

/* ── 多張表疊加同曆（唯讀檢視）── */
case 'get_calendar_multi': {
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = explode(',', $ids);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $ym = $_POST['ym'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    $filterUser = (int)($_POST['filter_user'] ?? 0);

    $from = $ym . '-01';
    $secondObj = (new DateTime($from))->modify('+1 month');
    $second = $secondObj->format('Y-m');
    $to = $secondObj->modify('last day of this month')->format('Y-m-d');

    // 暖色系每表一色，彼此差異大（禁冷暖混雜）
    $palette = ['#C0392B', '#E0592B', '#F0872B', '#E0A400', '#9C6B30', '#C77D4A', '#6E4326', '#D94F70'];
    $cells = []; $boardsMeta = []; $ci = 0; $allUids = [];
    foreach ($ids as $bid) {
        $bq = $pdo->prepare("SELECT * FROM roster_board WHERE id=?"); $bq->execute([$bid]); $b = $bq->fetch(PDO::FETCH_ASSOC);
        if (!$b) continue;
        $mine = ((int)$b['owner_id'] === $MYID);
        if (!$mine && !roster_can_view_board($pdo, $b, $MYID, $features)) continue;
        if ($b['status'] === 'active') {
            $maxD = $pdo->query("SELECT MAX(duty_date) FROM roster_assignment WHERE board_id=" . (int)$bid)->fetchColumn();
            if (!$maxD || $maxD < $to) { try { roster_regenerate($pdo, (int)$bid, null, $to); } catch (Exception $e) {} }
        }
        $color = $palette[$ci % count($palette)]; $ci++;
        $boardsMeta[] = ['id' => (int)$bid, 'name' => $b['name'], 'color' => $color];
        $lq = $pdo->prepare("SELECT id,lane_name FROM roster_lane WHERE board_id=?"); $lq->execute([$bid]);
        $lmap = []; foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $l) $lmap[(int)$l['id']] = $l['lane_name'];
        $sql = "SELECT id,lane_id,duty_date,user_id,sign_status,signed_at,is_adjusted,pending_swap_id FROM roster_assignment WHERE board_id=? AND duty_date BETWEEN ? AND ?";
        $ar = [$bid, $from, $to];
        if ($filterUser) { $sql .= " AND user_id=?"; $ar[] = $filterUser; }
        $aq = $pdo->prepare($sql); $aq->execute($ar);
        $rows = $aq->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $allUids[] = (int)$r['user_id'];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $cells[$r['duty_date']][] = [
                'aid' => (int)$r['id'], 'lane_id' => (int)$r['lane_id'], 'user_id' => $uid,
                'lane_name' => $lmap[(int)$r['lane_id']] ?? '', 'board_name' => $b['name'], 'color' => $color,
                'sign' => (int)$r['sign_status'], 'signed_at' => $r['signed_at'] ? substr($r['signed_at'], 0, 16) : null,
                'adjusted' => (int)$r['is_adjusted'], 'pending' => $r['pending_swap_id'] ? 1 : 0,
                'mine' => ($uid === $MYID), 'can_sign' => false,
            ];
        }
    }
    // 補人名 + 請假
    $nm = roster_user_name_map($pdo, $allUids);
    $leaveMap = roster_leave_map($pdo, $allUids, $from, $to);
    foreach ($cells as $d => &$arr) { foreach ($arr as &$c) {
        $c['name'] = $nm[$c['user_id']]['name'] ?? ('#' . $c['user_id']); $c['left'] = $nm[$c['user_id']]['left'] ?? false;
        $lv = $leaveMap[$c['user_id'] . '|' . $d] ?? null;
        $c['leave'] = $lv['label'] ?? null; $c['leave_full'] = $lv ? (bool)$lv['full'] : false;
    } unset($c); } unset($arr);
    // 排序每日：依表色群組
    foreach ($cells as $d => &$arr) { usort($arr, fn($a, $b) => strcmp($a['board_name'] . $a['lane_name'], $b['board_name'] . $b['lane_name'])); } unset($arr);

    $ctx = roster_workday_context($pdo, $from, $to);
    $people = [];
    $puids = array_values(array_unique($allUids));
    $pnm = roster_user_name_map($pdo, $puids);
    foreach ($puids as $pid) $people[] = ['id' => $pid, 'name' => $pnm[$pid]['name'] ?? ('#' . $pid), 'left' => $pnm[$pid]['left'] ?? false];
    usort($people, fn($a, $c) => strcmp($a['name'], $c['name']));

    jout([
        'board' => ['name' => '疊加檢視（' . count($boardsMeta) . ' 張表）', 'multi' => true, 'sign_required' => 0,
                    'can_edit' => false, 'is_admin' => $IS_ADMIN, 'swap_bypass' => false],
        'boards_meta' => $boardsMeta, 'lanes' => [], 'lane_members' => new stdClass(),
        'months' => [$ym, $second], 'cells' => $cells,
        'holidays' => array_keys($ctx['holidays']), 'makeup' => array_keys($ctx['makeup']),
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

/* ── 申請對調（單次）：選對方某一天，與自己這天對調 ── */
case 'request_swap': {
    $fromAid = (int)($_POST['from_aid'] ?? 0);
    $toAid   = (int)($_POST['to_aid'] ?? 0);
    $note    = trim($_POST['note'] ?? '');
    $q = $pdo->prepare("SELECT a.*, b.owner_id, b.name AS board_name FROM roster_assignment a JOIN roster_board b ON b.id=a.board_id WHERE a.id=?");
    $q->execute([$fromAid]); $a = $q->fetch(PDO::FETCH_ASSOC);
    $q->execute([$toAid]);   $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a || !$t) jerr('排班不存在', 404);
    if ((int)$a['board_id'] !== (int)$t['board_id']) jfail('只能在同一張表內對調');
    if ((int)$a['lane_id'] !== (int)$t['lane_id']) jfail('只能跟同一個輪值項目（同組）對調');
    $bypass = ((int)$a['owner_id'] === $MYID) || $IS_ADMIN;
    if ((int)$a['user_id'] !== $MYID && !$bypass) jerr('只能對調自己負責的班', 403);
    if ((int)$t['user_id'] === (int)$a['user_id']) jfail('對調雙方是同一人');
    $today = date('Y-m-d');
    if ($a['duty_date'] < $today || $t['duty_date'] < $today) jfail('不能調整已過去的班');
    if ($a['pending_swap_id'] || $t['pending_swap_id']) jfail('其中一天已有調班申請進行中');

    if ($bypass) { // 管理員/建立者：免對方同意，直接對調
        $pdo->beginTransaction();
        try { roster_swap_two($pdo, $a, $t, $MYID, $note); $pdo->commit(); }
        catch (Exception $e) { $pdo->rollBack(); jfail('對調失敗：' . $e->getMessage()); }
        try {
            roster_notify($pdo, '【已為你調班】' . $a['board_name'],
                $me['name'] . ' 已將 ' . $a['duty_date'] . ' 與 ' . $t['duty_date'] . ' 的班對調（表：' . $a['board_name'] . '）。'
                . ($note !== '' ? "\n備註：" . $note : '') . "\n請至「輪值排班表」確認你的新班表。",
                [(int)$a['user_id'], (int)$t['user_id']], $MYID);
        } catch (Exception $e) {}
        jout(['mode' => 'done']);
    }
    // 一般使用者：建立待核准申請
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO roster_swap_request (board_id,lane_id,scope,requester_id,counterpart_id,from_aid,to_aid,date_from,date_to,note) VALUES (?,?, 'single',?,?,?,?,?,?,?)")
            ->execute([$a['board_id'], $a['lane_id'], $MYID, (int)$t['user_id'], $fromAid, $toAid, $a['duty_date'], $t['duty_date'], $note]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE roster_assignment SET pending_swap_id=? WHERE id IN (?,?)")->execute([$rid, $fromAid, $toAid]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('申請失敗：' . $e->getMessage()); }
    // 通知對方有調班申請
    try {
        roster_notify($pdo, '【調班申請】' . $me['name'] . ' 想跟你對調班別',
            $me['name'] . ' 申請與你對調：' . "\n"
            . '・對方的班：' . $a['duty_date'] . "\n"
            . '・你的班：' . $t['duty_date'] . "\n"
            . '（表：' . $a['board_name'] . '）' . ($note !== '' ? "\n備註：" . $note : '')
            . "\n\n請至「輪值排班表」上方「調班申請」區按「同意」或「不同意」。",
            [(int)$t['user_id']], $MYID);
    } catch (Exception $e) {}
    jout(['mode' => 'pending']);
}

/* ── 申請對調（整個換手單位/區間）：自己與某位同組人員，在區間內全部對調 ── */
case 'request_swap_range': {
    $bid = (int)($_POST['board_id'] ?? 0);
    $laneId = ($_POST['lane_id'] ?? '') === '' ? null : (int)$_POST['lane_id'];
    $df = $_POST['date_from'] ?? ''; $dt = $_POST['date_to'] ?? '';
    $counterpart = (int)($_POST['counterpart_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) jfail('日期格式錯誤');
    if ($df > $dt) jfail('起訖日期顛倒');
    if ($counterpart <= 0 || $counterpart === $MYID) jfail('請選擇要對調的同組人員');
    if ($df < date('Y-m-d')) jfail('不能調整已過去的班');
    $bq = $pdo->prepare("SELECT * FROM roster_board WHERE id=?"); $bq->execute([$bid]); $bd = $bq->fetch(PDO::FETCH_ASSOC);
    if (!$bd) jerr('排班表不存在', 404);
    $bypass = ((int)$bd['owner_id'] === $MYID) || $IS_ADMIN;
    // 一般使用者：發起者必須是雙方之一（只能調自己的）
    $me_or = $bypass ? $counterpart : $MYID; // 一般人以自己為 A
    $userA = $bypass ? (int)($_POST['from_user_id'] ?? 0) : $MYID;
    if (!$bypass && $userA !== $MYID) jerr('只能對調自己負責的班', 403);
    if ($bypass && $userA <= 0) jfail('請選擇原負責人');
    if ($userA === $counterpart) jfail('原負責人與對調對象不可為同一人');

    // 檢查區間內是否已有進行中的申請
    $chk = "SELECT COUNT(*) FROM roster_assignment WHERE board_id=? AND duty_date BETWEEN ? AND ? AND pending_swap_id IS NOT NULL AND user_id IN (?,?)";
    $ca = [$bid, $df, $dt, $userA, $counterpart];
    if ($laneId !== null) { $chk .= " AND lane_id=?"; $ca[] = $laneId; }
    $cs = $pdo->prepare($chk); $cs->execute($ca);
    if ((int)$cs->fetchColumn() > 0) jfail('區間內已有調班申請進行中');

    if ($bypass) {
        $pdo->beginTransaction();
        try { $n = roster_swap_range($pdo, $bid, $laneId, $df, $dt, $userA, $counterpart, $MYID, $note); $pdo->commit(); }
        catch (Exception $e) { $pdo->rollBack(); jfail('對調失敗：' . $e->getMessage()); }
        jout(['mode' => 'done', 'affected' => $n]);
    }
    // 一般使用者：pending
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO roster_swap_request (board_id,lane_id,scope,requester_id,counterpart_id,date_from,date_to,note) VALUES (?,?, 'range',?,?,?,?,?)")
            ->execute([$bid, $laneId, $MYID, $counterpart, $df, $dt, $note]);
        $rid = (int)$pdo->lastInsertId();
        $mk = "UPDATE roster_assignment SET pending_swap_id=? WHERE board_id=? AND duty_date BETWEEN ? AND ? AND user_id IN (?,?)";
        $ma = [$rid, $bid, $df, $dt, $MYID, $counterpart];
        if ($laneId !== null) { $mk .= " AND lane_id=?"; $ma[] = $laneId; }
        $pdo->prepare($mk)->execute($ma);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('申請失敗：' . $e->getMessage()); }
    try {
        roster_notify($pdo, '【調班申請】' . $me['name'] . ' 想跟你整段對調',
            $me['name'] . ' 申請與你對調 ' . $df . ' ~ ' . $dt . ' 這段期間的班（表：' . $bd['name'] . '）。'
            . ($note !== '' ? "\n備註：" . $note : '')
            . "\n\n請至「輪值排班表」上方「調班申請」區按「同意」或「不同意」。",
            [$counterpart], $MYID);
    } catch (Exception $e) {}
    jout(['mode' => 'pending']);
}

/* ── 回應對調申請（同意/不同意）── */
case 'respond_swap': {
    $rid = (int)($_POST['req_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $rq = $pdo->prepare("SELECT r.*, b.owner_id FROM roster_swap_request r JOIN roster_board b ON b.id=r.board_id WHERE r.id=?");
    $rq->execute([$rid]); $r = $rq->fetch(PDO::FETCH_ASSOC);
    if (!$r) jerr('申請不存在', 404);
    if ($r['status'] !== 'pending') jfail('此申請已處理過');
    $canRespond = ((int)$r['counterpart_id'] === $MYID) || ((int)$r['owner_id'] === $MYID) || $IS_ADMIN;
    if (!$canRespond) jerr('只有被指定對調的人（或管理者）可回應', 403);
    $pdo->beginTransaction();
    try {
        if ($decision === 'agree') {
            if ($r['scope'] === 'single') {
                $q = $pdo->prepare("SELECT * FROM roster_assignment WHERE id=?");
                $q->execute([$r['from_aid']]); $a = $q->fetch(PDO::FETCH_ASSOC);
                $q->execute([$r['to_aid']]);   $t = $q->fetch(PDO::FETCH_ASSOC);
                if ($a && $t) roster_swap_two($pdo, $a, $t, $MYID, $r['note']);
            } else {
                roster_swap_range($pdo, (int)$r['board_id'], $r['lane_id'], $r['date_from'], $r['date_to'], (int)$r['requester_id'], (int)$r['counterpart_id'], $MYID, $r['note']);
            }
            $pdo->prepare("UPDATE roster_swap_request SET status='agreed',responder_id=?,responded_at=NOW() WHERE id=?")->execute([$MYID, $rid]);
        } else {
            // 不同意：清 pending，狀態駁回
            $pdo->prepare("UPDATE roster_assignment SET pending_swap_id=NULL WHERE pending_swap_id=?")->execute([$rid]);
            $pdo->prepare("UPDATE roster_swap_request SET status='rejected',responder_id=?,responded_at=NOW() WHERE id=?")->execute([$MYID, $rid]);
        }
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('處理失敗：' . $e->getMessage()); }
    // 通知申請人結果
    try {
        $agree = ($decision === 'agree');
        $period = ($r['scope'] === 'range')
            ? ($r['date_from'] . ' ~ ' . $r['date_to'])
            : ((string)$r['date_from'] . ($r['date_to'] && $r['date_to'] !== $r['date_from'] ? ' / ' . $r['date_to'] : ''));
        roster_notify($pdo,
            '【調班' . ($agree ? '已同意' : '未同意') . '】' . $me['name'] . ' ' . ($agree ? '同意' : '婉拒') . '了你的調班申請',
            $me['name'] . ' ' . ($agree ? '已同意' : '不同意') . '你申請的調班（' . $period . '）。'
            . ($agree ? "\n班表已更新，請至「輪值排班表」確認。" : "\n原排班維持不變，可再與其他同組人員協調。"),
            [(int)$r['requester_id']], $MYID);
    } catch (Exception $e) {}
    jout(['status' => ($decision === 'agree' ? 'agreed' : 'rejected')]);
}

/* ── 取消自己送出的對調申請 ── */
case 'cancel_swap': {
    $rid = (int)($_POST['req_id'] ?? 0);
    $rq = $pdo->prepare("SELECT r.*, b.owner_id FROM roster_swap_request r JOIN roster_board b ON b.id=r.board_id WHERE r.id=?");
    $rq->execute([$rid]); $r = $rq->fetch(PDO::FETCH_ASSOC);
    if (!$r) jerr('申請不存在', 404);
    if ($r['status'] !== 'pending') jfail('此申請已處理過');
    if ((int)$r['requester_id'] !== $MYID && (int)$r['owner_id'] !== $MYID && !$IS_ADMIN) jerr('只能取消自己送出的申請', 403);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE roster_assignment SET pending_swap_id=NULL WHERE pending_swap_id=?")->execute([$rid]);
        $pdo->prepare("UPDATE roster_swap_request SET status='cancelled',responder_id=?,responded_at=NOW() WHERE id=?")->execute([$MYID, $rid]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('取消失敗：' . $e->getMessage()); }
    jout();
}

/* ── 待我核准 / 我送出的（進行中）── */
case 'list_my_swaps': {
    $rq = $pdo->prepare("
        SELECT r.*, b.name AS board_name, l.lane_name
        FROM roster_swap_request r
        JOIN roster_board b ON b.id=r.board_id
        LEFT JOIN roster_lane l ON l.id=r.lane_id
        WHERE r.status='pending' AND (r.counterpart_id=? OR r.requester_id=?)
        ORDER BY r.created_at DESC");
    $rq->execute([$MYID, $MYID]);
    $rows = $rq->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)$r['requester_id']; $ids[] = (int)$r['counterpart_id']; }
    $nm = roster_user_name_map($pdo, $ids);
    $inbox = []; $sent = [];
    foreach ($rows as $r) {
        $item = [
            'id' => (int)$r['id'], 'board_name' => $r['board_name'], 'lane_name' => $r['lane_name'],
            'scope' => $r['scope'], 'requester' => $nm[(int)$r['requester_id']]['name'] ?? '', 'counterpart' => $nm[(int)$r['counterpart_id']]['name'] ?? '',
            'date_from' => $r['date_from'], 'date_to' => $r['date_to'], 'note' => $r['note'], 'created_at' => substr($r['created_at'], 0, 16),
        ];
        if ((int)$r['counterpart_id'] === $MYID) $inbox[] = $item; else $sent[] = $item;
    }
    jout(['inbox' => $inbox, 'sent' => $sent]);
}

/* ── 建立 / 刪除紀錄 ── */
case 'list_board_log': {
    $sql = "SELECT * FROM roster_board_log " . ($IS_ADMIN ? "" : "WHERE operator_id=" . $MYID) . " ORDER BY created_at DESC LIMIT 300";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    jout(['rows' => $rows]);
}

/* ── 調班紀錄（某表）── */
case 'list_adjust_log': {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM roster_board WHERE id=?"); $st->execute([$id]); $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) jerr('排班表不存在', 404);
    if ((int)$b['owner_id'] !== $MYID && !roster_can_view_board($pdo, $b, $MYID, $features)) jerr('無權檢視', 403);
    $lg = $pdo->prepare("SELECT g.*, l.lane_name FROM roster_adjust_log g LEFT JOIN roster_lane l ON l.id=g.lane_id WHERE g.board_id=? ORDER BY g.created_at DESC LIMIT 300");
    $lg->execute([$id]); $rows = $lg->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)$r['from_user_id']; $ids[] = (int)$r['to_user_id']; $ids[] = (int)$r['operator_id']; }
    $nm = roster_user_name_map($pdo, $ids);
    foreach ($rows as &$r) {
        $r['from_name'] = $nm[(int)$r['from_user_id']]['name'] ?? '';
        $r['to_name']   = $nm[(int)$r['to_user_id']]['name'] ?? '';
        $r['op_name']   = $nm[(int)$r['operator_id']]['name'] ?? '';
    } unset($r);
    jout(['rows' => $rows]);
}

/* ── 手動重算 / 延長 ── */
case 'regenerate': {
    $id = (int)($_POST['id'] ?? 0);
    load_board_editable($pdo, $id, $MYID, $IS_ADMIN);
    $gen = roster_regenerate($pdo, $id);
    jout(['generated' => $gen['generated'] ?? 0]);
}

/* ── 固定班別排班：班別定義 ── */
case 'shift_type_list': {
    $rows = $pdo->query("SELECT * FROM roster_shift_type ORDER BY is_active DESC, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    jout(['rows' => $rows, 'can_edit' => ($CAN_CREATE || $IS_ADMIN)]);
}
case 'shift_type_save': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無建立/管理權限', 403);
    $p = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($p)) jfail('資料格式錯誤');
    $id = (int)($p['id'] ?? 0);
    $name = trim($p['name'] ?? '');
    if ($name === '') jfail('請輸入班別名稱');
    $start = trim($p['start_time'] ?? ''); $end = trim($p['end_time'] ?? '');
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) jfail('請輸入上下班時間 (HH:MM)');
    $overnight = !empty($p['is_overnight']) ? 1 : 0;
    $brk = max(0, (int)($p['break_minutes'] ?? 0));
    $ot  = max(0, (int)($p['overtime_minutes'] ?? 0));
    $color = trim($p['color'] ?? '');
    $sort = (int)($p['sort_order'] ?? 0);
    $active = isset($p['is_active']) ? (!empty($p['is_active']) ? 1 : 0) : 1;
    $code = trim($p['code'] ?? '');
    $notifyOn = isset($p['notify_enabled']) ? (!empty($p['notify_enabled']) ? 1 : 0) : 1;
    $notifyGrp = !empty($p['notify_group']) ? 1 : 0;
    if ($id === 0) {
        $st = $pdo->prepare("INSERT INTO roster_shift_type (name,code,start_time,end_time,is_overnight,break_minutes,overtime_minutes,color,sort_order,is_active,notify_enabled,notify_group,owner_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$name, $code, $start, $end, $overnight, $brk, $ot, $color, $sort, $active, $notifyOn, $notifyGrp, $MYID]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $st = $pdo->prepare("UPDATE roster_shift_type SET name=?,code=?,start_time=?,end_time=?,is_overnight=?,break_minutes=?,overtime_minutes=?,color=?,sort_order=?,is_active=?,notify_enabled=?,notify_group=? WHERE id=?");
        $st->execute([$name, $code, $start, $end, $overnight, $brk, $ot, $color, $sort, $active, $notifyOn, $notifyGrp, $id]);
    }
    jout(['id' => $id]);
}
case 'shift_type_delete': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無權限', 403);
    $id = (int)($_POST['id'] ?? 0);
    $used = (int)$pdo->query("SELECT COUNT(*) FROM roster_shift_assign WHERE shift_type_id=" . $id)->fetchColumn();
    if ($used > 0) {
        $pdo->prepare("UPDATE roster_shift_type SET is_active=0 WHERE id=?")->execute([$id]);
        jout(['softDeleted' => true, 'used' => $used]);
    }
    $pdo->prepare("DELETE FROM roster_shift_type WHERE id=?")->execute([$id]);
    jout(['deleted' => true]);
}

/* ── 固定班別排班：兩個月月曆 ── */
case 'get_shift_calendar': {
    $ym = $_POST['ym'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    $filterUser = (int)($_POST['filter_user'] ?? 0);
    $filterShift = (int)($_POST['filter_shift'] ?? 0);
    $from = $ym . '-01';
    $secondObj = (new DateTime($from))->modify('+1 month');
    $second = $secondObj->format('Y-m');
    $to = $secondObj->modify('last day of this month')->format('Y-m-d');

    $shifts = $pdo->query("SELECT id,name,color,start_time,end_time,is_overnight FROM roster_shift_type WHERE is_active=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
    $shiftMap = []; foreach ($shifts as $s) $shiftMap[(int)$s['id']] = $s;

    $sql = "SELECT sa.id, sa.shift_type_id, sa.user_id, sa.work_date, sa.is_agent, sa.orig_user_id, sa.sign_status, sa.signed_at, sa.pending_swap_id
            FROM roster_shift_assign sa JOIN roster_shift_type st ON st.id=sa.shift_type_id
            WHERE sa.work_date BETWEEN ? AND ?";
    $args = [$from, $to];
    if ($filterUser)  { $sql .= " AND sa.user_id=?"; $args[] = $filterUser; }
    if ($filterShift) { $sql .= " AND sa.shift_type_id=?"; $args[] = $filterShift; }
    $rows = $pdo->prepare($sql); $rows->execute($args); $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

    $uids = array_map(fn($r) => (int)$r['user_id'], $rows);
    $names = roster_user_name_map($pdo, $uids);
    $leaveMap = roster_leave_map($pdo, $uids, $from, $to);
    $cells = [];
    foreach ($rows as $r) {
        $uid = (int)$r['user_id']; $sid = (int)$r['shift_type_id'];
        $lv = $leaveMap[$uid . '|' . $r['work_date']] ?? null;
        $s = $shiftMap[$sid] ?? null;
        $cells[$r['work_date']][] = [
            'aid' => (int)$r['id'], 'lane_id' => $sid, 'shift_id' => $sid, 'user_id' => $uid,
            'name' => $names[$uid]['name'] ?? ('#' . $uid), 'left' => $names[$uid]['left'] ?? false,
            'color' => $s['color'] ?: '#C0762C', 'lane_name' => $s ? $s['name'] : '', 'board_name' => $s ? $s['name'] : '',
            'time' => $s ? (substr($s['start_time'], 0, 5) . '~' . substr($s['end_time'], 0, 5)) : '',
            'sign' => (int)$r['sign_status'], 'signed_at' => $r['signed_at'] ? substr($r['signed_at'], 0, 16) : null,
            'is_agent' => (int)$r['is_agent'], 'adjusted' => ($r['orig_user_id'] ? 1 : 0),
            'pending' => $r['pending_swap_id'] ? (int)$r['pending_swap_id'] : 0,
            'leave' => $lv['label'] ?? null, 'leave_full' => $lv ? (bool)$lv['full'] : false,
            'mine' => ($uid === $MYID), 'can_sign' => ($uid === $MYID),
        ];
    }
    foreach ($cells as $d => &$arr) { usort($arr, fn($a, $b) => ($a['shift_id'] <=> $b['shift_id']) ?: strcmp($a['name'], $b['name'])); } unset($arr);
    $ctx = roster_workday_context($pdo, $from, $to);
    $ppl = array_values(array_unique($uids));
    $pn = roster_user_name_map($pdo, $ppl); $people = [];
    foreach ($ppl as $pid) $people[] = ['id' => $pid, 'name' => $pn[$pid]['name'] ?? ('#' . $pid)];
    usort($people, fn($a, $c) => strcmp($a['name'], $c['name']));

    jout([
        'months' => [$ym, $second], 'cells' => $cells,
        'holidays' => array_keys($ctx['holidays']), 'makeup' => array_keys($ctx['makeup']),
        'shifts' => $shifts, 'people' => $people, 'today' => date('Y-m-d'),
        'can_edit' => ($CAN_CREATE || $IS_ADMIN), 'is_admin' => $IS_ADMIN,
    ]);
}

/* ── 固定班別排班：排入人員（某班別×人員×日期區間，可選星期幾/略過假日）── */
case 'add_shift_assign': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無排班權限', 403);
    $p = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($p)) jfail('資料格式錯誤');
    $sid = (int)($p['shift_type_id'] ?? 0);
    $users = array_values(array_unique(array_filter(array_map('intval', $p['user_ids'] ?? []))));
    $df = $p['date_from'] ?? ''; $dt = $p['date_to'] ?? '';
    $weekdays = array_filter(array_map('intval', $p['weekdays'] ?? []), fn($x) => $x >= 1 && $x <= 7);
    $skipHoliday = !empty($p['skip_holiday']);
    if ($sid <= 0) jfail('請選班別');
    if (empty($users)) jfail('請選人員');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) jfail('日期格式錯誤');
    if ($df > $dt) jfail('起訖顛倒');
    $chk = $pdo->prepare("SELECT 1 FROM roster_shift_type WHERE id=?"); $chk->execute([$sid]);
    if (!$chk->fetchColumn()) jerr('班別不存在', 404);
    $ctx = $skipHoliday ? roster_workday_context($pdo, $df, $dt) : null;

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO roster_shift_assign (shift_type_id,user_id,work_date,created_by) VALUES (?,?,?,?)");
        $n = 0;
        $d = new DateTime($df); $e = new DateTime($dt);
        while ($d <= $e) {
            $ds = $d->format('Y-m-d');
            $ok = true;
            if (!empty($weekdays) && !in_array((int)$d->format('N'), $weekdays, true)) $ok = false;
            if ($ok && $skipHoliday && !roster_is_workday($ds, $ctx)) $ok = false;
            if ($ok) foreach ($users as $u) { $ins->execute([$sid, $u, $ds, $MYID]); $n += $ins->rowCount(); }
            $d->modify('+1 day');
        }
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('排班失敗：' . $e->getMessage()); }
    jout(['inserted' => $n]);
}

/* ── 固定班別排班：編輯一筆（改班別/日期/人員）── */
case 'update_shift_assign': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無權限', 403);
    $aid = (int)($_POST['aid'] ?? 0);
    $sid = (int)($_POST['shift_type_id'] ?? 0);
    $uid = (int)($_POST['user_id'] ?? 0);
    $wd  = $_POST['work_date'] ?? '';
    $st = $pdo->prepare("SELECT * FROM roster_shift_assign WHERE id=?"); $st->execute([$aid]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('排班不存在', 404);
    if ($sid <= 0) $sid = (int)$a['shift_type_id'];
    if ($uid <= 0) $uid = (int)$a['user_id'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wd)) $wd = $a['work_date'];
    $chk = $pdo->prepare("SELECT 1 FROM roster_shift_type WHERE id=?"); $chk->execute([$sid]);
    if (!$chk->fetchColumn()) jerr('班別不存在', 404);
    $dup = $pdo->prepare("SELECT 1 FROM roster_shift_assign WHERE shift_type_id=? AND user_id=? AND work_date=? AND id<>?");
    $dup->execute([$sid, $uid, $wd, $aid]);
    if ($dup->fetchColumn()) jfail('該員當天已在此班別，不可重複');
    $changedUser = ($uid !== (int)$a['user_id']);
    $orig = $changedUser ? ($a['orig_user_id'] ?: $a['user_id']) : $a['orig_user_id'];
    $pdo->prepare("UPDATE roster_shift_assign SET shift_type_id=?, user_id=?, work_date=?, orig_user_id=?, sign_status=0, signed_at=NULL, signed_by=NULL WHERE id=?")
        ->execute([$sid, $uid, $wd, $orig, $aid]);
    jout();
}

/* ── 固定班別排班：排班單清單（把連續排班還原成「一張單」）── */
case 'list_shift_blocks': {
    $from = $_POST['from'] ?? date('Y-m-01');
    $to   = $_POST['to'] ?? (new DateTime('today'))->modify('+6 month')->format('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = (new DateTime('today'))->modify('+6 month')->format('Y-m-d');
    $q = $pdo->prepare("SELECT sa.id, sa.shift_type_id, sa.user_id, sa.work_date, st.name AS shift_name,
                               LEFT(st.start_time,5) AS st_s, LEFT(st.end_time,5) AS st_e, st.color
                        FROM roster_shift_assign sa JOIN roster_shift_type st ON st.id=sa.shift_type_id
                        WHERE sa.work_date BETWEEN ? AND ?
                        ORDER BY sa.shift_type_id, sa.user_id, sa.work_date");
    $q->execute([$from, $to]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $blocks = []; $cur = null;
    foreach ($rows as $r) {
        $key = $r['shift_type_id'] . '|' . $r['user_id'];
        // 連續判定：允許中間跳過非排班日（週末等）→ 以「間隔<=3天」視為同一段，避免每週一段
        if ($cur && $cur['key'] === $key) {
            $gap = (strtotime($r['work_date']) - strtotime($cur['to'])) / 86400;
            if ($gap >= 1 && $gap <= 3) {
                $cur['to'] = $r['work_date']; $cur['days']++; $cur['ids'][] = (int)$r['id'];
                $cur['wd'][(int)date('N', strtotime($r['work_date']))] = 1;
                continue;
            }
        }
        if ($cur) $blocks[] = $cur;
        $cur = ['key' => $key, 'shift_type_id' => (int)$r['shift_type_id'], 'user_id' => (int)$r['user_id'],
                'shift_name' => $r['shift_name'], 'time' => $r['st_s'] . '~' . $r['st_e'], 'color' => $r['color'],
                'from' => $r['work_date'], 'to' => $r['work_date'], 'days' => 1, 'ids' => [(int)$r['id']],
                'wd' => [(int)date('N', strtotime($r['work_date'])) => 1]];
    }
    if ($cur) $blocks[] = $cur;
    $nm = roster_user_name_map($pdo, array_map(fn($b) => $b['user_id'], $blocks));
    $out = [];
    foreach ($blocks as $b) {
        $wd = array_keys($b['wd']); sort($wd);
        $out[] = ['shift_type_id' => $b['shift_type_id'], 'user_id' => $b['user_id'],
                  'user_name' => $nm[$b['user_id']]['name'] ?? ('#' . $b['user_id']),
                  'shift_name' => $b['shift_name'], 'time' => $b['time'], 'color' => $b['color'] ?: '#C0762C',
                  'date_from' => $b['from'], 'date_to' => $b['to'], 'days' => $b['days'],
                  'weekdays' => $wd, 'ids' => $b['ids']];
    }
    usort($out, fn($a, $c) => strcmp($a['date_from'], $c['date_from']) ?: strcmp($a['shift_name'], $c['shift_name']));
    jout(['blocks' => $out, 'today' => date('Y-m-d'), 'can_edit' => ($CAN_CREATE || $IS_ADMIN)]);
}

/* ── 固定班別排班：更新排班單（刪舊段→依新設定重建；不動過去）── */
case 'update_shift_block': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無權限', 403);
    $p = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($p)) jfail('資料格式錯誤');
    $oldIds = array_values(array_unique(array_filter(array_map('intval', $p['old_ids'] ?? []))));
    $sid = (int)($p['shift_type_id'] ?? 0);
    $users = array_values(array_unique(array_filter(array_map('intval', $p['user_ids'] ?? []))));
    $df = $p['date_from'] ?? ''; $dt = $p['date_to'] ?? '';
    $weekdays = array_filter(array_map('intval', $p['weekdays'] ?? []), fn($x) => $x >= 1 && $x <= 7);
    $skipHoliday = !empty($p['skip_holiday']);
    if ($sid <= 0) jfail('請選班別');
    if (empty($users)) jfail('請選人員');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) jfail('日期格式錯誤');
    if ($df > $dt) jfail('起訖顛倒');
    $chk = $pdo->prepare("SELECT 1 FROM roster_shift_type WHERE id=?"); $chk->execute([$sid]);
    if (!$chk->fetchColumn()) jerr('班別不存在', 404);

    $today = date('Y-m-d');
    $includePast = !empty($p['include_past']) && $IS_ADMIN;   // 管理員測試用：允許動到過去日期
    $ctx = $skipHoliday ? roster_workday_context($pdo, $df, $dt) : null;
    $pdo->beginTransaction();
    try {
        // 刪除舊段（預設只刪今天以後，過去凍結保留歷史；管理員可選含過去）
        $delN = 0;
        if ($oldIds) {
            $in = implode(',', array_fill(0, count($oldIds), '?'));
            if ($includePast) {
                $del = $pdo->prepare("DELETE FROM roster_shift_assign WHERE id IN ($in)");
                $del->execute($oldIds);
            } else {
                $del = $pdo->prepare("DELETE FROM roster_shift_assign WHERE id IN ($in) AND work_date >= ?");
                $del->execute(array_merge($oldIds, [$today]));
            }
            $delN = $del->rowCount();
        }
        // 依新設定重建
        $ins = $pdo->prepare("INSERT IGNORE INTO roster_shift_assign (shift_type_id,user_id,work_date,created_by) VALUES (?,?,?,?)");
        $n = 0;
        $d = new DateTime($df); $e = new DateTime($dt);
        while ($d <= $e) {
            $ds = $d->format('Y-m-d');
            $ok = ($includePast || $ds >= $today);
            if ($ok && !empty($weekdays) && !in_array((int)$d->format('N'), $weekdays, true)) $ok = false;
            if ($ok && $skipHoliday && !roster_is_workday($ds, $ctx)) $ok = false;
            if ($ok) foreach ($users as $u) { $ins->execute([$sid, $u, $ds, $MYID]); $n += $ins->rowCount(); }
            $d->modify('+1 day');
        }
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); jfail('更新失敗：' . $e->getMessage()); }
    jout(['deleted' => $delN, 'inserted' => $n, 'include_past' => $includePast ? 1 : 0]);
}

/* ── 固定班別排班：刪除整張排班單（不動過去）── */
case 'delete_shift_block': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無權限', 403);
    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
    $keepPast = !(!empty($_POST['include_past']) && $IS_ADMIN);   // 僅管理員可連過去一起刪
    if (!$ids) jfail('沒有要刪除的排班');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE FROM roster_shift_assign WHERE id IN ($in)";
    $args = $ids;
    if ($keepPast) { $sql .= " AND work_date >= ?"; $args[] = date('Y-m-d'); }
    $st = $pdo->prepare($sql); $st->execute($args);
    jout(['deleted' => $st->rowCount()]);
}

/* ── 固定班別排班：批次預覽（依條件列出將受影響的排班）── */
case 'preview_shift_batch': {
    $sid = (int)($_POST['shift_type_id'] ?? 0);
    $users = array_values(array_unique(array_filter(array_map('intval', $_POST['user_ids'] ?? []))));
    $df = $_POST['date_from'] ?? ''; $dt = $_POST['date_to'] ?? '';
    $weekdays = array_filter(array_map('intval', $_POST['weekdays'] ?? []), fn($x) => $x >= 1 && $x <= 7);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) jfail('日期格式錯誤');
    if ($df > $dt) jfail('起訖顛倒');
    $sql = "SELECT sa.id, sa.work_date, sa.user_id, sa.shift_type_id, st.name AS shift_name
            FROM roster_shift_assign sa JOIN roster_shift_type st ON st.id=sa.shift_type_id
            WHERE sa.work_date BETWEEN ? AND ?";
    $args = [$df, $dt];
    if ($sid > 0) { $sql .= " AND sa.shift_type_id=?"; $args[] = $sid; }
    if ($users)   { $sql .= " AND sa.user_id IN (" . implode(',', array_fill(0, count($users), '?')) . ")"; $args = array_merge($args, $users); }
    if ($weekdays) { $sql .= " AND DAYOFWEEK(sa.work_date) IN (" . implode(',', array_map(fn($d) => ($d % 7) + 1, $weekdays)) . ")"; }
    $sql .= " ORDER BY sa.work_date, sa.user_id";
    $q = $pdo->prepare($sql); $q->execute($args); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $nm = roster_user_name_map($pdo, array_map(fn($r) => (int)$r['user_id'], $rows));
    foreach ($rows as &$r) $r['user_name'] = $nm[(int)$r['user_id']]['name'] ?? ('#' . $r['user_id']);
    unset($r);
    jout(['rows' => $rows, 'count' => count($rows)]);
}

/* ── 固定班別排班：批次套用（改班別／換人／移除）── */
case 'apply_shift_batch': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無權限', 403);
    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
    $op = $_POST['op'] ?? '';
    if (!$ids) jfail('沒有要處理的排班');
    if (count($ids) > 3000) jfail('一次處理上限 3000 筆，請縮小範圍');
    $in = implode(',', array_fill(0, count($ids), '?'));

    if ($op === 'delete') {
        $pdo->prepare("DELETE FROM roster_shift_assign WHERE id IN ($in)")->execute($ids);
        jout(['affected' => count($ids), 'op' => 'delete']);
    }
    if ($op === 'shift') {
        $newSid = (int)($_POST['new_shift_type_id'] ?? 0);
        if ($newSid <= 0) jfail('請選新班別');
        $chk = $pdo->prepare("SELECT 1 FROM roster_shift_type WHERE id=?"); $chk->execute([$newSid]);
        if (!$chk->fetchColumn()) jerr('班別不存在', 404);
        $done = 0; $skip = 0;
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT id,user_id,work_date FROM roster_shift_assign WHERE id IN ($in)");
            $sel->execute($ids);
            $dup = $pdo->prepare("SELECT 1 FROM roster_shift_assign WHERE shift_type_id=? AND user_id=? AND work_date=? AND id<>?");
            $up = $pdo->prepare("UPDATE roster_shift_assign SET shift_type_id=?, sign_status=0, signed_at=NULL, signed_by=NULL WHERE id=?");
            foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dup->execute([$newSid, $r['user_id'], $r['work_date'], $r['id']]);
                if ($dup->fetchColumn()) { $skip++; continue; }   // 已在新班別 → 跳過
                $up->execute([$newSid, $r['id']]); $done++;
            }
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); jfail('批次改班別失敗：' . $e->getMessage()); }
        jout(['affected' => $done, 'skipped' => $skip, 'op' => 'shift']);
    }
    if ($op === 'user') {
        $newUid = (int)($_POST['new_user_id'] ?? 0);
        $isAgent = !empty($_POST['is_agent']) ? 1 : 0;
        if ($newUid <= 0) jfail('請選新人員');
        $done = 0; $skip = 0;
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT id,user_id,orig_user_id,shift_type_id,work_date FROM roster_shift_assign WHERE id IN ($in)");
            $sel->execute($ids);
            $dup = $pdo->prepare("SELECT 1 FROM roster_shift_assign WHERE shift_type_id=? AND user_id=? AND work_date=? AND id<>?");
            $up = $pdo->prepare("UPDATE roster_shift_assign SET user_id=?, orig_user_id=?, is_agent=?, sign_status=0, signed_at=NULL, signed_by=NULL WHERE id=?");
            foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int)$r['user_id'] === $newUid) { $skip++; continue; }
                $dup->execute([$r['shift_type_id'], $newUid, $r['work_date'], $r['id']]);
                if ($dup->fetchColumn()) { $skip++; continue; }   // 該員當天已在此班別
                $orig = $r['orig_user_id'] ?: $r['user_id'];
                $up->execute([$newUid, $orig, $isAgent, $r['id']]); $done++;
            }
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); jfail('批次換人失敗：' . $e->getMessage()); }
        jout(['affected' => $done, 'skipped' => $skip, 'op' => 'user']);
    }
    jfail('未知的批次動作');
}

/* ── 固定班別排班：移除一筆 ── */
case 'del_shift_assign': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無權限', 403);
    $aid = (int)($_POST['aid'] ?? 0);
    $pdo->prepare("DELETE FROM roster_shift_assign WHERE id=?")->execute([$aid]);
    jout();
}

/* ── 固定班別排班：調班（換人；記錄原負責人）── */
case 'shift_change_user': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無調班權限', 403);
    $aid = (int)($_POST['aid'] ?? 0);
    $newUser = (int)($_POST['new_user_id'] ?? 0);
    $isAgent = !empty($_POST['is_agent']) ? 1 : 0;
    $note = trim($_POST['note'] ?? '');
    $st = $pdo->prepare("SELECT * FROM roster_shift_assign WHERE id=?"); $st->execute([$aid]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('排班不存在', 404);
    if ($newUser <= 0) jfail('請選擇人員');
    if ($newUser === (int)$a['user_id']) jfail('已是同一人');
    // 防重：同班別同日不可重複同一人
    $dup = $pdo->prepare("SELECT 1 FROM roster_shift_assign WHERE shift_type_id=? AND user_id=? AND work_date=? AND id<>?");
    $dup->execute([$a['shift_type_id'], $newUser, $a['work_date'], $aid]);
    if ($dup->fetchColumn()) jfail('該員當天已在此班別');
    $orig = $a['orig_user_id'] ?: $a['user_id'];
    $pdo->prepare("UPDATE roster_shift_assign SET user_id=?, orig_user_id=?, is_agent=?, note=?, sign_status=0, signed_at=NULL, signed_by=NULL WHERE id=?")
        ->execute([$newUser, $orig, $isAgent, $note, $aid]);
    jout();
}

/* ── 固定班別排班：某排班者的代理人候選（請假補班用，走 delegate_lib）── */
case 'shift_agent_candidates': {
    $aid = (int)($_POST['aid'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM roster_shift_assign WHERE id=?"); $st->execute([$aid]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('排班不存在', 404);
    $cands = eg_person_delegate_candidates($pdo, (int)$a['user_id']);
    jout(['candidates' => $cands]);
}

/* ── 固定班別排班：請假一鍵代理補班 ── */
case 'shift_fill_agent': {
    if (!$CAN_CREATE && !$IS_ADMIN) jerr('無權限', 403);
    $aid = (int)($_POST['aid'] ?? 0);
    $agent = (int)($_POST['agent_id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM roster_shift_assign WHERE id=?"); $st->execute([$aid]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('排班不存在', 404);
    if ($agent <= 0) jfail('請選擇代理人');
    if ($agent === (int)$a['user_id']) jfail('代理人不可是本人');
    $dup = $pdo->prepare("SELECT 1 FROM roster_shift_assign WHERE shift_type_id=? AND user_id=? AND work_date=? AND id<>?");
    $dup->execute([$a['shift_type_id'], $agent, $a['work_date'], $aid]);
    if ($dup->fetchColumn()) jfail('代理人當天已在此班別');
    $orig = $a['orig_user_id'] ?: $a['user_id'];
    $pdo->prepare("UPDATE roster_shift_assign SET user_id=?, orig_user_id=?, is_agent=1, note=?, sign_status=0, signed_at=NULL, signed_by=NULL WHERE id=?")
        ->execute([$agent, $orig, '請假代理補班', $aid]);
    jout();
}

/* ── 固定班別排班：簽核 ── */
case 'shift_sign': case 'shift_unsign': {
    $aid = (int)($_POST['aid'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM roster_shift_assign WHERE id=?"); $st->execute([$aid]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('排班不存在', 404);
    if ((int)$a['user_id'] !== $MYID && !$IS_ADMIN) jerr('只有本人或管理者可簽核', 403);
    if ($action === 'shift_sign') $pdo->prepare("UPDATE roster_shift_assign SET sign_status=1,signed_at=NOW(),signed_by=? WHERE id=?")->execute([$MYID, $aid]);
    else $pdo->prepare("UPDATE roster_shift_assign SET sign_status=0,signed_at=NULL,signed_by=NULL WHERE id=?")->execute([$aid]);
    jout();
}

default:
    jfail('未知動作：' . $action);
}
} catch (Exception $e) {
    jerr('伺服器錯誤：' . $e->getMessage(), 500);
}
