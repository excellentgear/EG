<?php
/**
 * AS 文件排程 API —— 2026-09-30 建立
 *
 * 權限（asdoc_schedule_lib.php asched_perms()，roles module='as_schedule'）：
 *   asched_admin AS排程管理員：提醒設定、通知對象、代所有課室登記完成
 *   asched_view  檢閱：唯讀看全部課室的排程
 *   另：具 AS 文件檢閱權者視同可檢視（看得到文件卻看不到它的排程沒有意義）
 *   人工登記完成：管理員，或該文件「負責課室」的成員（逐列判定）
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/asdoc_schedule_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function jout($a = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE);
    exit;
}
function jerr($msg, $code = 400, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    asched_ensure($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['asched_csrf'])) $_SESSION['asched_csrf'] = bin2hex(random_bytes(16));

$u = asched_current_user($db);
if (!$u) jerr('未登入', 401);
$P   = asched_perms($db, $u);
$uid = (int)$P['uid'];
if (!$uid)          jerr('無使用權限（帳號非在職狀態）', 403);
if (!$P['canView']) jerr('無 AS 文件排程檢視權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* 寫入類動作先驗登入再驗 CSRF。順序不可顛倒：session 被 GC 掃掉時 token 會在同一個請求裡
   重新產生、比對必定不過，但那其實是「已經被登出」不是 CSRF 攻擊（見 _config.php 的說明）。 */
$WRITE = ['mark_done', 'unmark_done', 'settings_save', 'notify_cfg_save'];
if (in_array($action, $WRITE, true)) {
    if ($uid <= 0) jerr('登入已逾時，請重新登入後再試', 401, ['code'=>'LOGIN']);
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['asched_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code'=>'CSRF']);
}

function aschedReqAdmin(array $P) { if (!$P['canAdmin']) jerr('需要 AS 排程管理員權限', 403); }

/** 年度：只收合理範圍（避免有人送 year=9999 讓推導跑一堆空迴圈） */
function aschedYear($v): int {
    $y = (int)$v;
    $now = (int)date('Y');
    if ($y < 2015 || $y > $now + 3) $y = $now;
    return $y;
}

try {
    switch ($action) {

    /* ─────────────── 排程本體 ─────────────── */
    case 'plan': {
        $year = aschedYear($_GET['year'] ?? $_POST['year'] ?? date('Y'));
        $depts = [];
        foreach (explode(',', (string)($_GET['dept_ids'] ?? '')) as $d) {
            $d = (int)trim($d); if ($d > 0) $depts[] = $d;
        }
        $plan = asched_plan($db, $year, ['dept_ids' => $depts]);

        // 逐列附上「這個人能不能對這一列登記完成」——前端據此決定顯不顯示登記鈕，
        // 後端 mark_done 會用同一條規則再判一次（鐵律8）
        foreach ($plan['rows'] as &$r) {
            $r['can_mark'] = $P['canAdmin'] || asched_in_owner_dept($db, $uid, (array)$r['depts']);
            $r['state_text']     = asched_state_text((string)$r['state'], $r['days'] ?? null);
            $r['month_src_text'] = asched_month_src_text((string)$r['month_src']);
            $r['src_kind_text']  = asched_src_kind_text((string)$r['src_kind']);
        }
        unset($r);
        foreach ($plan['routine'] as &$q) {
            $q['src_kind_text'] = asched_src_kind_text((string)$q['src_kind']);
        }
        unset($q);

        jout([
            'year'    => $year,
            'rows'    => $plan['rows'],
            'routine' => $plan['routine'],
            'summary' => asched_summary($plan),
            'gaps'    => asched_gaps($db, $year, $plan),
            'settings'=> $plan['settings'],
            'perm'    => ['admin'=>$P['canAdmin'], 'view'=>$P['canView'], 'label'=>asched_role_label($P)],
        ]);
    }

    /* 給行事曆圖層用的 FullCalendar 事件格式（B 做完之後 A 階段會接這一支）。
       只回有排定日期的，且一律唯讀（沒有 editable、沒有 id 可回寫）。 */
    case 'calendar': {
        $start = (string)($_GET['start'] ?? '');
        $end   = (string)($_GET['end'] ?? '');
        $year  = preg_match('~^(\d{4})-~', $start, $m) ? aschedYear($m[1]) : (int)date('Y');
        // FullCalendar 的視窗可能橫跨兩個年度，兩年都算再依範圍濾
        $rows = [];
        foreach ([$year, $year + 1] as $y) {
            $p = asched_plan($db, $y, ['calendar_only' => true]);
            foreach ($p['rows'] as $r) $rows[] = $r;
        }
        $COLOR = [   // ai-rules/10 的暖色系；語意色維持全站通用（紅=需處理、綠=完成）
            'overdue'  => '#DD5138',
            'due'      => '#F0A24B',
            'upcoming' => '#C9A66B',
            'done'     => '#7A9A6B',
        ];
        $out = [];
        foreach ($rows as $r) {
            if ($r['due_date'] === '') continue;
            if ($start !== '' && $r['due_date'] < substr($start, 0, 10)) continue;
            if ($end   !== '' && $r['due_date'] > substr($end,   0, 10)) continue;
            if (!isset($COLOR[$r['state']])) continue;     // unknown/nomonth 不進日曆
            $out[] = [
                'title' => '【AS】' . $r['doc_no'] . ' ' . $r['doc_name'],
                'start' => $r['due_date'],
                'allDay'=> true,
                'color' => $COLOR[$r['state']],
                'url'   => '/EGsystem/views/ADM/as_schedule.php?year=' . (int)substr($r['due_date'], 0, 4),
                'asched'=> ['doc_no'=>$r['doc_no'], 'state'=>$r['state'],
                            'state_text'=>asched_state_text((string)$r['state'], $r['days'] ?? null)],
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ─────────────── 人工完成登記 ─────────────── */
    case 'mark_done': {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $pk    = trim((string)($_POST['period_key'] ?? ''));
        $date  = trim((string)($_POST['done_date'] ?? ''));
        $note  = (string)($_POST['note'] ?? '');

        // 權限：管理員，或該文件負責課室的成員（與前端 can_mark 同一條規則）
        if (!$P['canAdmin']) {
            $docs = asched_docs($db);
            $depts = null;
            foreach ($docs as $d) if ((int)$d['id'] === $docId) { $depts = $d['depts']; break; }
            if ($depts === null) jerr('文件不存在或未設定更新頻率', 400);
            if (!asched_in_owner_dept($db, $uid, $depts))
                jerr('只有該文件負責課室的人員或 AS 排程管理員可以登記完成', 403);
        }
        asched_mark_done($db, $docId, $pk, $date, $note, $uid, (string)$P['name']);
        jout(['msg' => '已登記完成']);
    }

    case 'unmark_done': {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $pk    = trim((string)($_POST['period_key'] ?? ''));
        if (!$P['canAdmin']) {
            $docs = asched_docs($db);
            $depts = null;
            foreach ($docs as $d) if ((int)$d['id'] === $docId) { $depts = $d['depts']; break; }
            if ($depts === null) jerr('文件不存在', 400);
            if (!asched_in_owner_dept($db, $uid, $depts))
                jerr('只有該文件負責課室的人員或 AS 排程管理員可以取消登記', 403);
        }
        asched_unmark_done($db, $docId, $pk);
        jout(['msg' => '已取消登記']);
    }

    /* ─────────────── 設定 ─────────────── */
    case 'settings_get':
        jout(['settings' => asched_settings($db)]);

    case 'settings_save': {
        aschedReqAdmin($P);
        $in = [
            'lead_days'      => $_POST['lead_days']      ?? null,
            'overdue_repeat' => $_POST['overdue_repeat'] ?? null,
            'infer_month'    => !empty($_POST['infer_month'])    ? 1 : 0,
            'notify_enabled' => !empty($_POST['notify_enabled']) ? 1 : 0,
        ];
        foreach (['lead_days','overdue_repeat'] as $k) if ($in[$k] === null) unset($in[$k]);
        jout(['settings' => asched_settings_save($db, $in), 'msg' => '設定已儲存']);
    }

    /* ─────────────── 通知對象 ─────────────── */
    case 'notify_cfg_get': {
        $cfg = asched_notify_cfg($db);
        // 候選：只列「實際有 AS 週期文件掛在上面」的課室，不要把全公司部門都攤出來
        $plan  = asched_plan($db, (int)date('Y'));
        $depts = [];
        foreach ($plan['rows'] as $r) foreach ($r['depts'] as $dp) $depts[(int)$dp['id']] = (string)$dp['name'];
        // 已經設過通知對象的部門也要列出來（可能那份文件的負責課室後來被改掉了）
        foreach (array_keys($cfg) as $did) {
            if (isset($depts[$did])) continue;
            try {
                $st = $db->prepare("SELECT name FROM department WHERE id=?");
                $st->execute([$did]);
                $nm = $st->fetchColumn();
                if ($nm) $depts[$did] = (string)$nm . '（目前無週期文件）';
            } catch (Throwable $e) {}
        }
        asort($depts);

        $positions = [];
        try {
            foreach ($db->query("SELECT id, name FROM position ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $p)
                $positions[] = ['id'=>(int)$p['id'], 'name'=>(string)$p['name']];
        } catch (Throwable $e) {}

        /* 指定人員的候選：全體在職（走共用庫，禁止各頁自己寫人員 SQL＝CLAUDE.md 人員列表鐵則）。
           用 all_posts 取「一個職務一列」才能讓兼任者在任一部門底下都找得到（鐵則⑥：
           預設一人一列會挑職級最高那筆，何沐桐主職技術課工程師就會被兼任的生管組組長蓋掉）；
           但這裡選的是「收件人」不是「代表身分」，value 是 user.id，同一人多列會變成重複選項，
           所以在這裡依 user 去重、把全部職務合併成一行顯示。 */
        $people = [];
        try {
            $seen = [];
            foreach (eg_people_list($db, ['all_posts' => true]) as $p) {
                $pid = (int)$p['id'];
                $post = trim((string)($p['dept_name'] ?? '') . ' ' . (string)($p['position_name'] ?? ''));
                if (isset($seen[$pid])) {
                    if ($post !== '' && !in_array($post, $people[$seen[$pid]]['posts'], true))
                        $people[$seen[$pid]]['posts'][] = $post;
                    continue;
                }
                $seen[$pid] = count($people);
                $people[] = ['id'=>$pid, 'name'=>(string)$p['user_cname'],
                             'posts'=>($post !== '' ? [$post] : []),
                             'leave_note'=>(string)($p['leave_label'] ?? '')];
            }
        } catch (Throwable $e) { error_log('[asched] people_list failed: ' . $e->getMessage()); }

        jout(['cfg'=>$cfg, 'depts'=>$depts, 'positions'=>$positions, 'people'=>$people]);
    }

    case 'notify_cfg_save': {
        aschedReqAdmin($P);
        $deptId = (int)($_POST['dept_id'] ?? 0);
        $pos = json_decode((string)($_POST['position_ids'] ?? '[]'), true);
        $usr = json_decode((string)($_POST['user_ids'] ?? '[]'), true);
        if (!is_array($pos)) $pos = [];
        if (!is_array($usr)) $usr = [];
        asched_notify_cfg_save($db, $deptId, $pos, $usr);
        // 存完即時回報「這樣會發給誰」——只存不告知的話，管理員永遠不知道設定有沒有效
        $uids = asched_notify_targets($db, [$deptId]);
        $names = [];
        if ($uids) {
            $ph = implode(',', array_fill(0, count($uids), '?'));
            $st = $db->prepare("SELECT user_cname FROM `user` WHERE id IN ($ph)");
            $st->execute($uids);
            $names = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        jout(['msg'=>'已儲存', 'targets'=>$names]);
    }

    /* 試算：這個部門目前設定會發給誰（設定跳窗按一下就看得到，不必等到真的發信） */
    case 'notify_preview': {
        $deptId = (int)($_GET['dept_id'] ?? 0);
        if ($deptId <= 0) jerr('請選擇部門');
        $uids = asched_notify_targets($db, [$deptId]);
        $rows = [];
        if ($uids) {
            $ph = implode(',', array_fill(0, count($uids), '?'));
            $st = $db->prepare("SELECT u.id, u.user_cname FROM `user` u WHERE u.id IN ($ph)");
            $st->execute($uids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $cfg = asched_notify_cfg($db);
        jout(['targets'=>$rows, 'fallback'=>!isset($cfg[$deptId])]);
    }

    /* ─────────────── 列印紀錄（ai-rules/23）─────────────── */
    case 'print_log': {
        $year = aschedYear($_POST['year'] ?? date('Y'));
        try {
            eg_print_log_add($db, [
                'source'    => 'as_schedule',
                // doc_kind 一定要明確傳 'form'：eg_print_log_add() 的預設值是 'attachment'，
                // 不傳的話這份報表會在「列印與簽核紀錄」頁被分類成「附件檔案」（實測踩到）
                'doc_kind'  => 'form',
                'doc_name'  => 'AS 文件年度排程表 ' . $year,
                'ref_table' => 'as_document',
                'ref_id'    => 0,
            ]);
        } catch (Throwable $e) { error_log('[asched] print log failed: ' . $e->getMessage()); }
        jout();
    }

    default:
        jerr('無效的操作：' . $action, 400);
    }
} catch (Throwable $e) {
    jerr($e->getMessage(), 400);
}
