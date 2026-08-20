<?php
/**
 * 專案管理（2-GM-02）API
 * 權限：project_lib.php prj_perms()（roles module='project'）
 *       檢閱=唯讀／登錄=建立編輯、訂單轉專案、同步BOM、開管理卡／管理員=全部（刪除、標籤、設定、AS綁定）
 *       另：專案負責人（project.owner_id）即使只有檢閱角色，也能編輯自己負責的專案。
 *       會簽權不看角色：被指派為某一列會簽人者即可會簽（比照 doc_apply）。
 * 送出必填檢查：後端一律再跑一次 prj_validate()（前端已擋，不可只做半套＝鐵律8）。
 * 時間戳：一律取 DB 時間（PHP date() 是 UTC、MySQL NOW() 是本地，混用會差 8 小時）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/project_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';

function jout($a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new DBConnection())->getPDO();
    prj_ensure_schema($db);
} catch (Throwable $e) {
    jerr('DB連線失敗：' . $e->getMessage(), 500);
}

$u = prj_current_user($db);
if (!$u) jerr('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$P     = prj_perms($db, $u);
$NOW   = prj_db_now($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 會簽人不看角色也要進得來（比照 doc_apply） */
function prj_is_cosigner(PDO $db, int $projectId, int $uid): bool
{
    $st = $db->prepare("SELECT 1 FROM project_cosign WHERE project_id=? AND user_id=? LIMIT 1");
    $st->execute([$projectId, $uid]);
    return (bool)$st->fetchColumn();
}

/**
 * 會簽通知（ai-rules/17：通知上要有核准/退回鈕、內容完整可看）。
 * ref_type='PROJECT_COSIGN'，側欄選單路由會依這個 ref_type 直接開會簽跳窗。
 */
function prj_notify_cosign(PDO $db, array $prj, array $n, int $fromUid): int
{
    $title = '專案立案待會簽：' . $prj['project_no'] . '　' . $prj['project_name'];
    $content = '專案代號：' . $prj['project_no'] . '（' . (PRJ_TYPES[$prj['project_type']] ?? '') . "型)\n"
             . '專案名稱：' . $prj['project_name'] . "\n"
             . '客戶：' . ($prj['customer_name'] ?: '－') . '　負責人：' . ($prj['owner_name'] ?: '－') . "\n"
             . '專案期間：' . eg_fmt_date($prj['start_date']) . ' ~ ' . eg_fmt_date($prj['end_date']) . "\n"
             . '專案目的：' . mb_substr((string)$prj['purpose'], 0, 200) . "\n"
             . '會簽單位：' . $n['dept_name'] . "\n"
             . '點此開啟會簽，請先選擇同意／不同意，再填寫審查意見（非必填）後簽名。';
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '專案管理', 1, 'PROJECT_COSIGN', ?)")
           ->execute([$title, $content, $fromUid, (int)$n['cos_id']]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
           ->execute([$eid, (int)$n['uid']]);
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/EGsystem/src/push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid),
                                  ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) {
        return 0;
    }
}

/** 核准／退回結果通知給專案負責人與建立者 */
function prj_notify_result(PDO $db, array $prj, bool $ok, string $note, int $fromUid): void
{
    $title = '專案' . ($ok ? '已核准' : '被退回') . '：' . $prj['project_no'] . '　' . $prj['project_name'];
    $content = '專案代號：' . $prj['project_no'] . "\n" . '專案名稱：' . $prj['project_name'] . "\n"
             . '結果：' . ($ok ? '核准' : '退回') . ($note !== '' ? ("\n說明：" . $note) : '');
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '專案管理', 1, 'PROJECT_RESULT', ?)")
           ->execute([$title, $content, $fromUid, (int)$prj['project_id']]);
        $eid = (int)$db->lastInsertId();
        $to = array_unique(array_filter([(int)$prj['owner_id'], (int)$prj['created_by']]));
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')");
        foreach ($to as $t) $ins->execute([$eid, $t]);
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/EGsystem/src/push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid),
                                  ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
    }
}

/** 取專案並檢查看得到；$needEdit=true 時另檢查編輯權 */
function prj_need(PDO $db, array $P, int $projectId, bool $needEdit = false): array
{
    $prj = prj_get($db, $projectId);
    if (!$prj) jerr('專案不存在或已刪除', 404);
    $canSee = $P['canView'] || prj_is_cosigner($db, $projectId, (int)$P['uid']);
    if (!$canSee) jerr('無檢視權限', 403);
    if ($needEdit && !prj_can_edit_project($P, $prj)) jerr('無編輯權限（需「專案登錄」角色，或你是本專案的負責人）', 403);
    return $prj;
}

switch ($action) {

/* ══════════════════════════ 共用選項 ══════════════════════════ */
case 'perms':
    jout(['perms' => $P]);

case 'meta':
    if (!$P['canView']) jerr('無權限', 403);
    // 人員清單一律走 people_lib（只列未離職、標長期請假、依職稱排序並顯示職稱＝ai-rules/08 第五節）
    $people = [];
    try { $people = eg_people_list($db, []); } catch (Throwable $e) {}
    $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    $custs = $db->query("SELECT customer_id, customer FROM customer_list
                         WHERE COALESCE(is_inactive,0)=0 ORDER BY customer")->fetchAll(PDO::FETCH_ASSOC);
    $tpls = [];
    try {
        $tpls = $db->query("SELECT id, tpl_name FROM stamp_template WHERE is_active=1 ORDER BY tpl_name")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
    jout([
        'types'      => PRJ_TYPES,
        'phases'     => PRJ_PHASES,
        'tag_kinds'  => PRJ_TAG_KINDS,
        'doc_checks' => PRJ_DOC_CHECKS,
        'tags'       => prj_tags_all($db),
        'people'     => $people,
        'depts'      => $depts,
        'customers'  => $custs,
        'stamp_tpls' => $tpls,
        'today'      => $NOW['date'],
        'default_cosign_depts' => prj_setting_get($db, 'default_cosign_depts', ''),
        'asdoc'      => [
            'plan' => prj_print_meta($db, PRJ_ASDOC_PLAN, null),
            'card' => prj_print_meta($db, PRJ_ASDOC_CARD, null),
        ],
    ]);

/* ══════════════════════════ 專案清單／明細 ══════════════════════════ */
case 'list':
    if (!$P['canView']) jerr('無權限', 403);
    jout(['rows' => prj_list($db, $_GET)]);

case 'get':
    $pid = (int)($_GET['project_id'] ?? 0);
    $prj = prj_need($db, $P, $pid);
    // 開啟詳情時背景同步一次 BOM（silent＝第一次帶入不洗出一堆變更提示）
    try { prj_bom_sync($db, $pid, $uname, true); } catch (Throwable $e) {}
    jout([
        'project'   => $prj,
        'goals'     => prj_goals($db, $pid),
        'tasks'     => prj_tasks($db, $pid),
        'orders'    => prj_orders($db, $pid),
        'parts'     => prj_parts($db, $pid),
        'processes' => prj_processes($db, $pid),
        'cards'     => prj_cards($db, $pid),
        'cosigns'   => prj_cosigns($db, $pid),
        'alerts'    => prj_bom_alerts($db, $pid),
        'doc_check' => prj_doc_check($db, $pid),
        'can_edit'  => prj_can_edit_project($P, $prj),
        'can_approve' => prj_can_approve($db, $prj, $P),
    ]);

case 'save':
    if (!$P['canEdit'] && !$P['canView']) jerr('無權限', 403);
    $pid  = (int)($_POST['project_id'] ?? 0);
    $data = [
        'project_type' => strtoupper(trim((string)($_POST['project_type'] ?? 'C'))),
        'project_name' => trim((string)($_POST['project_name'] ?? '')),
        'customer_id'  => trim((string)($_POST['customer_id'] ?? '')) ?: null,
        'owner_id'     => (int)($_POST['owner_id'] ?? 0),
        'dept_id'      => (int)($_POST['dept_id'] ?? 0) ?: null,
        'phase'        => (string)($_POST['phase'] ?? 'initiating'),
        'purpose'      => trim((string)($_POST['purpose'] ?? '')),
        'background'   => trim((string)($_POST['background'] ?? '')),
        'contribution' => trim((string)($_POST['contribution'] ?? '')),
        'goal_desc'    => trim((string)($_POST['goal_desc'] ?? '')),
        'plan_date'    => trim((string)($_POST['plan_date'] ?? '')) ?: null,
        'start_date'   => trim((string)($_POST['start_date'] ?? '')) ?: null,
        'end_date'     => trim((string)($_POST['end_date'] ?? '')) ?: null,
        'budget'       => trim((string)($_POST['budget'] ?? '')) === '' ? null : (float)$_POST['budget'],
        'tag_ids'      => prj_tag_csv(prj_tag_ids((string)($_POST['tag_ids'] ?? ''))),
        'note'         => trim((string)($_POST['note'] ?? '')),
    ];
    if (!isset(PRJ_PHASES[$data['phase']])) $data['phase'] = 'initiating';
    $err = prj_validate($data);
    if ($err) jerr('資料未填齊', 400, ['fields' => $err]);

    $ownerName = '';
    if ($data['owner_id']) {
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$data['owner_id']]);
        $ownerName = (string)$st->fetchColumn();
    }
    $custName = '';
    if ($data['customer_id']) {
        $st = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=?");
        $st->execute([$data['customer_id']]);
        $custName = (string)$st->fetchColumn();
    }
    $deptName = '';
    if ($data['dept_id']) {
        $st = $db->prepare("SELECT name FROM department WHERE id=?");
        $st->execute([$data['dept_id']]);
        $deptName = (string)$st->fetchColumn();
    }

    $db->beginTransaction();
    try {
        if ($pid) {
            $prj = prj_get($db, $pid);
            if (!$prj) throw new RuntimeException('專案不存在');
            if (!prj_can_edit_project($P, $prj)) throw new RuntimeException('無編輯權限');
            if (in_array((string)$prj['status'], ['submitted', 'approved'], true) && !$P['canAdmin']) {
                throw new RuntimeException('已送簽／已核准的專案只有管理員可以改內容');
            }
            $st = $db->prepare("UPDATE project SET project_type=?, project_name=?, customer_id=?, customer_name=?,
                                    owner_id=?, owner_name=?, dept_id=?, dept_name=?, phase=?, purpose=?, background=?,
                                    contribution=?, goal_desc=?, plan_date=?, start_date=?, end_date=?, budget=?,
                                    tag_ids=?, note=?, modified_by=?, modified_at=?
                                WHERE project_id=?");
            $st->execute([$data['project_type'], $data['project_name'], $data['customer_id'], $custName,
                          $data['owner_id'], $ownerName, $data['dept_id'], $deptName, $data['phase'],
                          $data['purpose'], $data['background'], $data['contribution'], $data['goal_desc'],
                          $data['plan_date'], $data['start_date'], $data['end_date'], $data['budget'],
                          $data['tag_ids'], $data['note'], $uid, $NOW['dt'], $pid]);
        } else {
            if (!$P['canEdit']) throw new RuntimeException('無新增權限（需「專案登錄」角色）');
            $no = prj_next_no($db, $data['project_type'], $data['start_date'] ?: $NOW['date']);
            $st = $db->prepare("INSERT INTO project (project_no, project_type, project_name, customer_id, customer_name,
                                    owner_id, owner_name, dept_id, dept_name, phase, purpose, background, contribution,
                                    goal_desc, plan_date, start_date, end_date, budget, tag_ids, note,
                                    source, created_by, created_by_name, created_at)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'manual',?,?,?)");
            $st->execute([$no, $data['project_type'], $data['project_name'], $data['customer_id'], $custName,
                          $data['owner_id'], $ownerName, $data['dept_id'], $deptName, $data['phase'],
                          $data['purpose'], $data['background'], $data['contribution'], $data['goal_desc'],
                          $data['plan_date'], $data['start_date'], $data['end_date'], $data['budget'],
                          $data['tag_ids'], $data['note'], $uid, $uname, $NOW['dt']]);
            $pid = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr($e->getMessage());
    }
    jout(['project_id' => $pid, 'message' => '已儲存']);

case 'delete':
    if (!$P['canAdmin']) jerr('無刪除權限（需「專案管理員」角色）', 403);
    $pid = (int)($_POST['project_id'] ?? 0);
    if (!$pid) jerr('參數錯誤');
    $db->prepare("UPDATE project SET is_deleted=1, modified_by=?, modified_at=? WHERE project_id=?")
       ->execute([$uid, $NOW['dt'], $pid]);
    jout(['message' => '已刪除（訂單綁定一併釋出）']);

/* ══════════════════════════ 訂單轉專案 ══════════════════════════ */
case 'order_candidates':
    if (!$P['canEdit']) jerr('無權限', 403);
    jout(['rows' => prj_order_candidates($db, $_GET)]);

/**
 * 三種粒度：
 *   mode=new    多選訂單（可只勾一張）→ 建立新專案
 *   mode=append 多選訂單 → 加入既有專案（帶 project_id）
 * 重複綁定一律擋下並回報已在哪個專案（點開即刷新鐵則：以送出當下的實際狀態再算一次）
 */
case 'order_to_project':
    if (!$P['canEdit']) jerr('無權限（需「專案登錄」角色）', 403);
    $mode = (string)($_POST['mode'] ?? 'new');
    $ids  = $_POST['order_ids'] ?? [];
    if (!is_array($ids)) $ids = array_filter(explode(',', (string)$ids));
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) jerr('請至少勾選一張訂單');

    $taken = prj_orders_taken($db, $ids);
    if ($taken) {
        $msg = [];
        foreach ($taken as $oid => $t) $msg[] = '訂單 #' . $oid . ' 已屬於專案 ' . $t['project_no'] . '（' . $t['project_name'] . '）';
        jerr('有訂單已被其他專案綁定，請重新整理後再試：' . implode('；', array_slice($msg, 0, 5)), 409,
             ['taken' => $taken]);
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT Order_id, Order_oo, d_id, d_id_ID, Client_name, Client_name_ID,
                               Qty, Order_date, Delivery_date, Processing_items
                        FROM order_track WHERE Order_id IN ($in) ORDER BY Order_date, Order_id");
    $st->execute($ids);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$orders) jerr('找不到這些訂單');

    $db->beginTransaction();
    try {
        if ($mode === 'append') {
            $pid = (int)($_POST['project_id'] ?? 0);
            $prj = prj_get($db, $pid);
            if (!$prj) throw new RuntimeException('目標專案不存在');
            if (!prj_can_edit_project($P, $prj)) throw new RuntimeException('無權編輯目標專案');
        } else {
            $type = strtoupper(trim((string)($_POST['project_type'] ?? 'C')));
            if (!isset(PRJ_TYPES[$type])) $type = 'C';
            $name = trim((string)($_POST['project_name'] ?? ''));
            if ($name === '') {
                // 沒填名稱時用「客戶＋料號」自動命名（多料號取第一個並標示還有幾項）
                $first = $orders[0];
                $name = trim((string)$first['Client_name'] . ' ' . (string)$first['d_id']);
                if (count($orders) > 1) $name .= ' 等 ' . count($orders) . ' 項';
            }
            $ownerId = (int)($_POST['owner_id'] ?? 0) ?: $uid;
            $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
            $st->execute([$ownerId]);
            $ownerName = (string)$st->fetchColumn();
            // 客戶：全部訂單同一客戶才帶入，不同客戶時留空（不猜）
            $custIds = array_unique(array_filter(array_map(static fn($o) => (string)$o['Client_name_ID'], $orders)));
            $custId  = count($custIds) === 1 ? reset($custIds) : null;
            $custName = '';
            if ($custId) {
                $st = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=?");
                $st->execute([$custId]);
                $custName = (string)$st->fetchColumn();
            }
            // 專案起迄：起＝最早接單日、迄＝最晚交期（都可事後改）
            $dates = array_filter(array_map(static fn($o) => (string)$o['Order_date'], $orders));
            $dlvs  = array_filter(array_map(static fn($o) => (string)$o['Delivery_date'], $orders));
            $start = $dates ? min($dates) : $NOW['date'];
            $end   = $dlvs ? max($dlvs) : null;

            $no = prj_next_no($db, $type, $start);
            $st = $db->prepare("INSERT INTO project (project_no, project_type, project_name, customer_id, customer_name,
                                    owner_id, owner_name, phase, start_date, end_date, plan_date, tag_ids,
                                    source, created_by, created_by_name, created_at)
                                VALUES (?,?,?,?,?,?,?,'planning',?,?,?,?,'order',?,?,?)");
            $st->execute([$no, $type, $name, $custId, $custName, $ownerId, $ownerName,
                          $start, $end, $NOW['date'], prj_tag_csv(prj_tag_ids((string)($_POST['tag_ids'] ?? ''))),
                          $uid, $uname, $NOW['dt']]);
            $pid = (int)$db->lastInsertId();
        }

        $ins = $db->prepare("INSERT INTO project_order (project_id, order_id, added_by, added_at) VALUES (?,?,?,?)");
        foreach ($orders as $o) $ins->execute([$pid, (int)$o['Order_id'], $uname, $NOW['dt']]);
        prj_sync_parts_from_orders($db, $pid, $uname);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr('轉專案失敗：' . $e->getMessage());
    }
    // 綁完訂單立刻帶入這些訂單已開立的 BOM 製程（silent＝不把既有製程當成「變更」）
    try { prj_bom_sync($db, $pid, $uname, true); } catch (Throwable $e) {}
    jout(['project_id' => $pid, 'count' => count($orders),
          'message' => '已轉入 ' . count($orders) . ' 張訂單']);

case 'order_unlink':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $oid = (int)($_POST['order_id'] ?? 0);
    $db->prepare("DELETE FROM project_order WHERE project_id=? AND order_id=?")->execute([$pid, $oid]);
    prj_sync_parts_from_orders($db, $pid, $uname);
    jout(['message' => '已移出專案']);

/* ══════════════════════════ 料號（手動補掛） ══════════════════════════ */
case 'part_add':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $dsPk = (int)($_POST['ds_pk'] ?? 0);
    if (!$dsPk) jerr('請選擇料號');
    $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
    $st->execute([$dsPk]);
    $partNo = (string)$st->fetchColumn();
    if ($partNo === '') jerr('料號不存在');
    $db->prepare("INSERT INTO project_part (project_id, ds_pk, part_no, source, note, added_by, added_at)
                  VALUES (?,?,?,'manual',?,?,?)
                  ON DUPLICATE KEY UPDATE note=VALUES(note)")
       ->execute([$pid, $dsPk, $partNo, trim((string)($_POST['note'] ?? '')), $uname, $NOW['dt']]);
    jout(['message' => '已加入料號 ' . $partNo]);

case 'part_remove':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $dsPk = (int)($_POST['ds_pk'] ?? 0);
    // 由訂單帶出的料號不給手動刪（要刪請移除訂單），否則同步一跑就又長回來
    $st = $db->prepare("SELECT source FROM project_part WHERE project_id=? AND ds_pk=?");
    $st->execute([$pid, $dsPk]);
    if ((string)$st->fetchColumn() === 'order') jerr('這個料號是由訂單帶入的，請改為移除對應訂單');
    $db->prepare("DELETE FROM project_part WHERE project_id=? AND ds_pk=? AND source='manual'")->execute([$pid, $dsPk]);
    jout(['message' => '已移除']);

case 'part_search':
    if (!$P['canView']) jerr('無權限', 403);
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw === '') jout(['rows' => []]);
    $st = $db->prepare("SELECT ds.d_id AS ds_pk, ds.D_Setting_Id AS part_no, ds.Spec_No,
                               COALESCE(c.customer,'') AS customer_name
                        FROM d_setting ds LEFT JOIN customer_list c ON c.customer_id=ds.Customer_Id
                        WHERE ds.D_Setting_Id LIKE ? OR ds.Drawing_No LIKE ?
                        ORDER BY ds.D_Setting_Id LIMIT 50");
    $st->execute(['%' . $kw . '%', '%' . $kw . '%']);
    jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);

/* ══════════════════════════ 目標與任務（執行規劃表） ══════════════════════════ */
case 'plan_save':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $goals = json_decode((string)($_POST['goals'] ?? '[]'), true) ?: [];
    $tasks = json_decode((string)($_POST['tasks'] ?? '[]'), true) ?: [];
    $err = prj_validate(['project_name' => 'x', 'project_type' => 'C', 'owner_id' => 1], $tasks);
    if ($err) jerr('日程有誤', 400, ['fields' => $err]);

    $db->beginTransaction();
    try {
        // 目標：以送上來的清單為準，前端會帶既有 goal_id，沒帶的視為新增，沒出現的刪除
        $keepG = [];
        $i = 0;
        foreach ($goals as $g) {
            $name = trim((string)($g['goal_name'] ?? ''));
            if ($name === '') continue;
            $gid = (int)($g['goal_id'] ?? 0);
            $deptId = (int)($g['dept_id'] ?? 0) ?: null;
            $deptName = '';
            if ($deptId) {
                $st = $db->prepare("SELECT name FROM department WHERE id=?");
                $st->execute([$deptId]);
                $deptName = (string)$st->fetchColumn();
            }
            $tagCsv = prj_tag_csv(prj_tag_ids((string)($g['tag_ids'] ?? '')));
            if ($gid) {
                $db->prepare("UPDATE project_goal SET goal_name=?, dept_id=?, dept_name=?, tag_ids=?, sort_order=?
                              WHERE goal_id=? AND project_id=?")
                   ->execute([$name, $deptId, $deptName, $tagCsv, $i, $gid, $pid]);
            } else {
                $db->prepare("INSERT INTO project_goal (project_id, goal_name, dept_id, dept_name, tag_ids, sort_order)
                              VALUES (?,?,?,?,?,?)")->execute([$pid, $name, $deptId, $deptName, $tagCsv, $i]);
                $gid = (int)$db->lastInsertId();
            }
            $keepG[] = $gid;
            // 前端用暫時 key 對應新目標底下的任務
            foreach ($tasks as &$t) {
                if ((string)($t['goal_key'] ?? '') !== '' && (string)$t['goal_key'] === (string)($g['goal_key'] ?? '__none__')) {
                    $t['goal_id'] = $gid;
                }
            }
            unset($t);
            $i++;
        }
        if ($keepG) {
            $in = implode(',', array_fill(0, count($keepG), '?'));
            $st = $db->prepare("DELETE FROM project_goal WHERE project_id=? AND goal_id NOT IN ($in)");
            $st->execute(array_merge([$pid], $keepG));
        } else {
            $db->prepare("DELETE FROM project_goal WHERE project_id=?")->execute([$pid]);
        }

        // 任務
        $keepT = [];
        $j = 0;
        foreach ($tasks as $t) {
            $name = trim((string)($t['task_name'] ?? ''));
            if ($name === '') continue;
            $tid = (int)($t['task_id'] ?? 0);
            $ownerId = (int)($t['owner_id'] ?? 0) ?: null;
            $ownerName = '';
            if ($ownerId) {
                $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
                $st->execute([$ownerId]);
                $ownerName = (string)$st->fetchColumn();
            }
            $args = [
                (int)($t['goal_id'] ?? 0) ?: null, $name,
                trim((string)($t['plan_start'] ?? '')) ?: null, trim((string)($t['plan_end'] ?? '')) ?: null,
                trim((string)($t['act_start'] ?? '')) ?: null, trim((string)($t['act_end'] ?? '')) ?: null,
                $ownerId, $ownerName,
                max(0, min(100, (int)($t['progress'] ?? 0))),
                !empty($t['is_milestone']) ? 1 : 0,
                prj_tag_csv(prj_tag_ids((string)($t['tag_ids'] ?? ''))),
                trim((string)($t['note'] ?? '')), $j,
            ];
            if ($tid) {
                $db->prepare("UPDATE project_task SET goal_id=?, task_name=?, plan_start=?, plan_end=?, act_start=?,
                                    act_end=?, owner_id=?, owner_name=?, progress=?, is_milestone=?, tag_ids=?,
                                    note=?, sort_order=?
                              WHERE task_id=? AND project_id=?")
                   ->execute(array_merge($args, [$tid, $pid]));
            } else {
                $db->prepare("INSERT INTO project_task (goal_id, task_name, plan_start, plan_end, act_start, act_end,
                                    owner_id, owner_name, progress, is_milestone, tag_ids, note, sort_order, project_id)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute(array_merge($args, [$pid]));
                $tid = (int)$db->lastInsertId();
            }
            $keepT[] = $tid;
            $j++;
        }
        if ($keepT) {
            $in = implode(',', array_fill(0, count($keepT), '?'));
            $st = $db->prepare("DELETE FROM project_task WHERE project_id=? AND task_id NOT IN ($in)");
            $st->execute(array_merge([$pid], $keepT));
        } else {
            $db->prepare("DELETE FROM project_task WHERE project_id=?")->execute([$pid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage());
    }
    // 日程改了，仍為自動的管理卡基準要跟著重算（推導欄位鐵則）
    try {
        $st = $db->prepare("SELECT card_id FROM project_card WHERE project_id=? AND is_deleted=0 AND status='draft'");
        $st->execute([$pid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) prj_card_refresh_baseline($db, (int)$cid);
    } catch (Throwable $e) {}
    jout(['message' => '已儲存執行規劃表', 'goals' => prj_goals($db, $pid), 'tasks' => prj_tasks($db, $pid)]);

/* ══════════════════════════ BOM 製程 ══════════════════════════ */
case 'bom_sync':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $r = prj_bom_sync($db, $pid, $uname, false);
    jout(['result' => $r, 'processes' => prj_processes($db, $pid), 'alerts' => prj_bom_alerts($db, $pid),
          'message' => '同步完成：新增 ' . $r['added'] . '、異動 ' . $r['changed'] . '、移除 ' . $r['removed'] . ' 道製程']);

case 'bom_alert_ack':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $aid = (int)($_POST['alert_id'] ?? 0);
    if ($aid) {
        $db->prepare("UPDATE project_bom_change SET acked_by=?, acked_at=? WHERE id=? AND project_id=?")
           ->execute([$uname, $NOW['dt'], $aid, $pid]);
    } else {
        $db->prepare("UPDATE project_bom_change SET acked_by=?, acked_at=? WHERE project_id=? AND acked_at IS NULL")
           ->execute([$uname, $NOW['dt'], $pid]);
    }
    jout(['message' => '已標記知悉', 'alerts' => prj_bom_alerts($db, $pid)]);

case 'process_note':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $db->prepare("UPDATE project_process SET note=?, is_milestone=? WHERE id=? AND project_id=?")
       ->execute([trim((string)($_POST['note'] ?? '')), !empty($_POST['is_milestone']) ? 1 : 0,
                  (int)($_POST['id'] ?? 0), $pid]);
    jout(['message' => '已儲存']);

/* ══════════════════════════ 文件檢核 ══════════════════════════ */
case 'doc_check':
    $pid = (int)($_GET['project_id'] ?? 0);
    prj_need($db, $P, $pid);
    jout(['rows' => prj_doc_check($db, $pid), 'defs' => PRJ_DOC_CHECKS]);

/** 給四個頁面的偵測鈕呼叫：有專案但該頁未建立的料號 */
case 'missing_for':
    if (!$P['canView'] && !$P['isAdmin']) jerr('無權限', 403);
    $target = (string)($_GET['target'] ?? '');
    if (!isset(PRJ_DOC_CHECKS[$target])) jerr('參數錯誤');
    jout(['rows' => prj_missing_for($db, $target, !empty($_GET['include_closed']))]);

/* ══════════════════════════ 立案送簽／會簽／核准 ══════════════════════════ */

/**
 * 送簽：把勾選的會簽單位展開成 project_cosign 逐列，並解析各單位實際會簽人（含代理）。
 * 業務日期（submit_date）與精確時間戳（submitted_at）分離存放＝ai-rules/21。
 */
case 'submit':
    $pid = (int)($_POST['project_id'] ?? 0);
    $prj = prj_need($db, $P, $pid, true);
    if ((string)$prj['status'] !== 'draft' && (string)$prj['status'] !== 'rejected') {
        jerr('這筆專案目前狀態是「' . $prj['status'] . '」，不能再送簽（請重新整理）', 409);
    }
    $err = prj_validate($prj, prj_tasks($db, $pid));
    if ($err) jerr('資料未填齊，無法送簽', 400, ['fields' => $err]);

    $depts = $_POST['cosign_depts'] ?? [];
    if (!is_array($depts)) $depts = array_filter(explode(',', (string)$depts));
    $depts = array_values(array_unique(array_filter(array_map('intval', $depts))));

    // 僅超級管理員可回改送出日（ai-rules/21 鐵則2）
    $subDate = trim((string)($_POST['submit_date'] ?? ''));
    if ($subDate === '' || !$P['isAdmin']) $subDate = $NOW['date'];

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM project_cosign WHERE project_id=? AND signed_at IS NULL")->execute([$pid]);
        $ins = $db->prepare("INSERT INTO project_cosign (project_id, dept_id, dept_name, user_id, user_name,
                                    item_text, is_delegate, sort_order)
                             VALUES (?,?,?,?,?,?,?,?)");
        $i = 0;
        $notify = [];
        foreach ($depts as $did) {
            $st = $db->prepare("SELECT name FROM department WHERE id=?");
            $st->execute([$did]);
            $dName = (string)$st->fetchColumn();
            // 會簽人＝該部門主管，再經代理解析（禁各頁自己猜代理＝ai-rules/11）
            $mgr = null;
            try { $mgr = eg_org_dept_manager($db, $did); } catch (Throwable $e) {}
            $sid = $mgr ? (int)($mgr['id'] ?? 0) : 0;
            $isDel = 0;
            $sName = $mgr ? (string)($mgr['user_cname'] ?? '') : '';
            if ($sid) {
                try {
                    $rr = eg_resolve_signer($db, $sid, ['date' => $subDate]);
                    if (!empty($rr['user_id']) && (int)$rr['user_id'] !== $sid) {
                        $sid = (int)$rr['user_id'];
                        $sName = (string)($rr['user_cname'] ?? $rr['name'] ?? $sName);
                        $isDel = 1;
                    }
                } catch (Throwable $e) {}
            }
            $ins->execute([$pid, $did, $dName, $sid ?: null, $sName,
                           trim((string)($_POST['cosign_item_' . $did] ?? '')), $isDel, $i++]);
            $cosId = (int)$db->lastInsertId();
            if ($sid) $notify[] = ['cos_id' => $cosId, 'uid' => $sid, 'dept_name' => $dName];
        }
        $db->prepare("UPDATE project SET status='submitted', submit_date=?, submitted_at=?, decide_note=NULL,
                             modified_by=?, modified_at=? WHERE project_id=?")
           ->execute([$subDate, $NOW['dt'], $uid, $NOW['dt'], $pid]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr('送簽失敗：' . $e->getMessage());
    }

    foreach ($notify as $n) prj_notify_cosign($db, $prj, $n, $uid);
    jout(['message' => '已送簽，會簽通知已發出（' . count($notify) . ' 個單位）']);

/** 通知點進來時只帶 cosign_id，用來問出它屬於哪個專案（會簽人不看角色，故這支不擋 canView） */
case 'cosign_owner':
    $cid = (int)($_GET['cosign_id'] ?? 0);
    $st = $db->prepare("SELECT c.project_id, c.user_id FROM project_cosign c
                        JOIN project p ON p.project_id=c.project_id AND p.is_deleted=0
                        WHERE c.id=?");
    $st->execute([$cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jout(['project_id' => 0]);
    if (!$P['canView'] && (int)$row['user_id'] !== $uid) jerr('無權限', 403);
    jout(['project_id' => (int)$row['project_id']]);

/** 會簽：一定要先選同意／不同意才能填意見（意見非必填），比照 doc_apply 的口徑 */
case 'cosign_save':
    $cid = (int)($_POST['cosign_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM project_cosign WHERE id=?");
    $st->execute([$cid]);
    $cos = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cos) jerr('會簽項目不存在', 404);
    if ((int)$cos['user_id'] !== $uid && !$P['isAdmin']) jerr('這一列不是指派給你會簽的', 403);
    if ($cos['signed_at']) jerr('這一列已經簽過了（請重新整理）', 409);

    $result = (string)($_POST['result'] ?? '');
    if (!in_array($result, ['agree', 'disagree'], true)) jerr('請先選擇同意或不同意', 400, ['fields' => ['result' => '請先選擇同意或不同意']]);
    $prj = prj_get($db, (int)$cos['project_id']);
    if (!$prj) jerr('專案不存在', 404);
    $signDate = (string)($prj['submit_date'] ?: $NOW['date']);

    $db->prepare("UPDATE project_cosign SET result=?, opinion=?, signed_date=?, signed_at=? WHERE id=?")
       ->execute([$result, trim((string)($_POST['opinion'] ?? '')), $signDate, $NOW['dt'], $cid]);
    jout(['message' => '已完成會簽']);

/** 核准／退回（核准人可自行輸入核准日期，比照供應商稽核計劃） */
case 'decide':
    $pid = (int)($_POST['project_id'] ?? 0);
    $prj = prj_need($db, $P, $pid);
    if (!prj_can_approve($db, $prj, $P)) jerr('你不是本專案的核准人', 403);
    $ok = (string)($_POST['decision'] ?? '') === 'approve';
    $note = trim((string)($_POST['note'] ?? ''));
    if (!$ok && $note === '') jerr('退回一定要填原因', 400, ['fields' => ['note' => '請填寫退回原因']]);
    $apDate = trim((string)($_POST['approved_date'] ?? '')) ?: $NOW['date'];

    // 還有人沒會簽完就不能核准（會簽是核准的前置，比照 doc_apply）
    if ($ok) {
        $st = $db->prepare("SELECT COUNT(*) FROM project_cosign WHERE project_id=? AND signed_at IS NULL");
        $st->execute([$pid]);
        if ((int)$st->fetchColumn() > 0 && empty($_POST['force'])) {
            jerr('還有會簽單位尚未完成，確定要直接核准嗎？', 409, ['need_force' => true]);
        }
    }
    $db->prepare("UPDATE project SET status=?, approved_date=?, approved_at=?, approver_id=?, approver_name=?,
                         decide_note=?, phase=CASE WHEN ?='approved' AND phase='initiating' THEN 'planning' ELSE phase END,
                         modified_by=?, modified_at=? WHERE project_id=?")
       ->execute([$ok ? 'approved' : 'rejected', $ok ? $apDate : null, $ok ? $NOW['dt'] : null,
                  $ok ? $uid : null, $ok ? $uname : null, $note, $ok ? 'approved' : 'rejected',
                  $uid, $NOW['dt'], $pid]);
    prj_notify_result($db, $prj, $ok, $note, $uid);
    jout(['message' => $ok ? '已核准' : '已退回']);

/**
 * 管理員批次自動簽核（補歷史紙本專案）：業務日期與時間戳分離、時間錯開 5~30 分不跨日（ai-rules/21）。
 *
 * ⚠ 已知限制（ai-rules/22）：核准人「是誰」是用 prj_approver_pool() 依**目前**組織解析的，
 *   補很舊的專案時可能挑到當時還沒上任的人。圖章上印的**部門與職稱**已經依業務日期回推
 *   （prj_sign_post()），但「人選本身」還沒有 as-of 版本——eg_resolve_supervisor() 不支援指定日期。
 *   補歷史專案時請在跳窗確認核准人是否為當時的權責主管，必要時事後由管理員改。
 */
case 'auto_sign':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    $ids = $_POST['project_ids'] ?? [];
    if (!is_array($ids)) $ids = array_filter(explode(',', (string)$ids));
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) jerr('請至少勾選一筆專案');
    $bizDate = trim((string)($_POST['biz_date'] ?? '')) ?: $NOW['date'];
    $done = 0;
    $db->beginTransaction();
    try {
        foreach ($ids as $pid) {
            $prj = prj_get($db, $pid);
            if (!$prj || in_array((string)$prj['status'], ['approved', 'closed'], true)) continue;
            [$d, $ts] = prj_auto_sign_stamp($bizDate, $bizDate . ' 09:00:00');
            $pool = prj_approver_pool($db, (int)$prj['created_by']);
            $apId = $pool[0] ?? 0;
            $apName = '';
            if ($apId) {
                $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
                $st->execute([$apId]);
                $apName = (string)$st->fetchColumn();
            }
            $db->prepare("UPDATE project SET status='approved', submit_date=COALESCE(submit_date,?), submitted_at=COALESCE(submitted_at,?),
                                 approved_date=?, approved_at=?, approver_id=?, approver_name=?, is_auto=1,
                                 modified_by=?, modified_at=? WHERE project_id=?")
               ->execute([$d, $ts, $d, $ts, $apId ?: null, $apName, $uid, $NOW['dt'], $pid]);
            $db->prepare("UPDATE project_cosign SET result=COALESCE(result,'agree'), signed_date=COALESCE(signed_date,?),
                                 signed_at=COALESCE(signed_at,?), is_auto=1 WHERE project_id=? AND signed_at IS NULL")
               ->execute([$d, $ts, $pid]);
            $done++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr('自動簽核失敗：' . $e->getMessage());
    }
    jout(['message' => '已自動簽核 ' . $done . ' 筆']);

/** 結案（程序書 §6.11：總結報告呈總經理、管理審查會議提報） */
case 'close':
    $pid = (int)($_POST['project_id'] ?? 0);
    $prj = prj_need($db, $P, $pid, true);
    $summary = trim((string)($_POST['close_summary'] ?? ''));
    if ($summary === '') jerr('請填寫專案總結報告', 400, ['fields' => ['close_summary' => '請填寫專案總結報告']]);
    // 階段推進強制檢核：缺件時擋下並列出缺什麼（可由管理員設定關閉）
    if (prj_setting_get($db, 'block_close_on_missing', '1') === '1') {
        $miss = [];
        foreach (prj_doc_check($db, $pid) as $r) {
            if ((int)$r['missing'] === 0) continue;
            $lack = [];
            foreach (PRJ_DOC_CHECKS as $k => $def) if (!(int)$r[$k]) $lack[] = $def[0];
            $miss[] = $r['part_no'] . '：缺 ' . implode('、', $lack);
        }
        if ($miss && empty($_POST['force'])) {
            jerr('以下料號還有文件未建立，不能結案：' . "\n" . implode("\n", array_slice($miss, 0, 10))
                 . (count($miss) > 10 ? "\n…共 " . count($miss) . ' 筆' : ''), 409,
                 ['need_force' => $P['canAdmin'], 'missing' => $miss]);
        }
    }
    $db->prepare("UPDATE project SET status='closed', phase='closing', close_date=?, close_summary=?,
                         modified_by=?, modified_at=? WHERE project_id=?")
       ->execute([trim((string)($_POST['close_date'] ?? '')) ?: $NOW['date'], $summary, $uid, $NOW['dt'], $pid]);
    jout(['message' => '專案已結案']);

/* ══════════════════════════ 專案管理卡（2-GM-02-03） ══════════════════════════ */
case 'card_create':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $rDate = trim((string)($_POST['review_date'] ?? '')) ?: $NOW['date'];
    $goalIds = $_POST['goal_ids'] ?? [];
    if (!is_array($goalIds)) $goalIds = array_filter(explode(',', (string)$goalIds));
    $db->beginTransaction();
    try {
        $cid = prj_card_create($db, $pid, $rDate, $goalIds, ['uid' => $uid, 'uname' => $uname]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr('建立失敗：' . $e->getMessage());
    }
    jout(['card_id' => $cid, 'message' => '已建立管理卡（目標與承辦人已自動帶入，只需填問題與後續辦法）']);

case 'card_get':
    $cid = (int)($_GET['card_id'] ?? 0);
    $card = prj_card_get($db, $cid);
    if (!$card) jerr('管理卡不存在', 404);
    $prj = prj_need($db, $P, (int)$card['project_id']);
    jout(['card' => $card, 'project' => $prj, 'goals' => prj_goals($db, (int)$card['project_id']),
          'can_edit' => prj_can_edit_project($P, $prj) && (string)$card['status'] !== 'approved']);

case 'card_save':
    $cid = (int)($_POST['card_id'] ?? 0);
    $card = prj_card_get($db, $cid);
    if (!$card) jerr('管理卡不存在', 404);
    $prj = prj_need($db, $P, (int)$card['project_id'], true);
    if ((string)$card['status'] === 'approved' && !$P['canAdmin']) jerr('已核准的管理卡只有管理員可以改', 403);

    $rDate = trim((string)($_POST['review_date'] ?? '')) ?: (string)$card['review_date'];
    $items = json_decode((string)($_POST['items'] ?? '[]'), true) ?: [];
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE project_card SET review_date=?, modified_by=?, modified_at=? WHERE card_id=?")
           ->execute([$rDate, $uid, $NOW['dt'], $cid]);
        $up = $db->prepare("UPDATE project_card_item SET goal_name=?, dept_name=?, owner_name=?, baseline=?,
                                   baseline_auto=?, issue_text=?, follow_text=?, note=?, on_track=?, sort_order=?
                            WHERE item_id=? AND card_id=?");
        $i = 0;
        foreach ($items as $it) {
            $auto = !empty($it['baseline_auto']) ? 1 : 0;
            $up->execute([
                trim((string)($it['goal_name'] ?? '')), trim((string)($it['dept_name'] ?? '')),
                trim((string)($it['owner_name'] ?? '')), trim((string)($it['baseline'] ?? '')), $auto,
                trim((string)($it['issue_text'] ?? '')), trim((string)($it['follow_text'] ?? '')),
                trim((string)($it['note'] ?? '')), !empty($it['on_track']) ? 1 : 0, $i++,
                (int)($it['item_id'] ?? 0), $cid,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage());
    }
    // 檢討日期可能被改，自動列的基準要重算（推導欄位鐵則：來源一改就重算）
    prj_card_refresh_baseline($db, $cid);
    jout(['message' => '已儲存', 'card' => prj_card_get($db, $cid)]);

case 'card_submit':
    $cid = (int)($_POST['card_id'] ?? 0);
    $card = prj_card_get($db, $cid);
    if (!$card) jerr('管理卡不存在', 404);
    $prj = prj_need($db, $P, (int)$card['project_id'], true);
    if ((string)$card['status'] !== 'draft') jerr('這張管理卡已經送出（請重新整理）', 409);
    // 每一列都要有交代：標了「依計畫進行」或填了現階段問題，兩者至少其一
    $bad = [];
    foreach ($card['items'] as $n => $it) {
        if ((int)$it['on_track']) continue;
        if (trim((string)$it['issue_text']) === '') $bad[] = '第 ' . ($n + 1) . ' 項';
    }
    if ($bad) jerr('這些項次沒有交代現況：' . implode('、', $bad) . '（沒問題請勾「依計畫進行」）', 400);

    $rDate = (string)$card['review_date'];
    // 三格簽章：製表＝送出者、審查＝專案負責人、核准＝專案核准人（都可事後由管理員調整）
    $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
    $st->execute([(int)$prj['owner_id']]);
    $ownerName = (string)$st->fetchColumn();
    $pool = prj_approver_pool($db, (int)$prj['owner_id']);
    $apId = $pool[0] ?? 0;
    $apName = '';
    if ($apId) { $st->execute([$apId]); $apName = (string)$st->fetchColumn(); }

    $db->prepare("UPDATE project_card SET status='submitted', submit_date=?, submitted_at=?,
                         sign_maker_id=?, sign_maker_name=?, sign_maker_date=?,
                         sign_review_id=?, sign_review_name=?, sign_review_date=?,
                         sign_approve_id=?, sign_approve_name=?, sign_approve_date=?,
                         modified_by=?, modified_at=? WHERE card_id=?")
       ->execute([$rDate, $NOW['dt'], $uid, $uname, $rDate,
                  (int)$prj['owner_id'] ?: null, $ownerName, $rDate,
                  $apId ?: null, $apName, $rDate, $uid, $NOW['dt'], $cid]);
    jout(['message' => '已送出管理卡']);

case 'card_delete':
    if (!$P['canAdmin']) jerr('無刪除權限', 403);
    $cid = (int)($_POST['card_id'] ?? 0);
    $db->prepare("UPDATE project_card SET is_deleted=1, modified_by=?, modified_at=? WHERE card_id=?")
       ->execute([$uid, $NOW['dt'], $cid]);
    jout(['message' => '已刪除']);

/* ══════════════════════════ 標籤／設定／AS 綁定 ══════════════════════════ */
case 'tag_list':
    if (!$P['canView']) jerr('無權限', 403);
    jout(['rows' => prj_tags_all($db, $_GET['kind'] ?? null, false)]);

case 'tag_save':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    $tid  = (int)($_POST['tag_id'] ?? 0);
    $kind = (string)($_POST['tag_kind'] ?? 'project');
    if (!isset(PRJ_TAG_KINDS[$kind])) jerr('標籤種類不合法');
    $name = trim((string)($_POST['tag_name'] ?? ''));
    if ($name === '') jerr('請填標籤名稱', 400, ['fields' => ['tag_name' => '請填標籤名稱']]);
    $color = trim((string)($_POST['color'] ?? ''));
    $active = !empty($_POST['is_active']) ? 1 : 0;
    $sort = (int)($_POST['sort_order'] ?? 0);
    try {
        if ($tid) {
            $db->prepare("UPDATE project_tag SET tag_kind=?, tag_name=?, color=?, sort_order=?, is_active=? WHERE tag_id=?")
               ->execute([$kind, $name, $color, $sort, $active, $tid]);
        } else {
            $db->prepare("INSERT INTO project_tag (tag_kind, tag_name, color, sort_order, is_active) VALUES (?,?,?,?,?)")
               ->execute([$kind, $name, $color, $sort, $active]);
            $tid = (int)$db->lastInsertId();
        }
    } catch (Throwable $e) {
        jerr('同一種類下標籤名稱不可重複');
    }
    jout(['tag_id' => $tid, 'message' => '已儲存', 'rows' => prj_tags_all($db, null, false)]);

case 'tag_delete':
    if (!$P['canAdmin']) jerr('無權限', 403);
    $tid = (int)($_POST['tag_id'] ?? 0);
    // 已被專案/目標/任務用到的標籤只停用不刪除（刪掉會讓既有資料的標籤變成孤兒 id）
    $used = 0;
    foreach ([['project', 'tag_ids'], ['project_goal', 'tag_ids'], ['project_task', 'tag_ids']] as [$t, $c]) {
        $st = $db->prepare("SELECT COUNT(*) FROM $t WHERE FIND_IN_SET(?, $c)");
        $st->execute([$tid]);
        $used += (int)$st->fetchColumn();
    }
    if ($used > 0) {
        $db->prepare("UPDATE project_tag SET is_active=0 WHERE tag_id=?")->execute([$tid]);
        jout(['message' => '這個標籤已被 ' . $used . ' 筆資料使用，改為停用（不再出現在挑選清單，既有資料保留）']);
    }
    $db->prepare("DELETE FROM project_tag WHERE tag_id=?")->execute([$tid]);
    jout(['message' => '已刪除', 'rows' => prj_tags_all($db, null, false)]);

case 'setting_get':
    if (!$P['canView']) jerr('無權限', 403);
    jout(['setting' => [
        'approver_dept_id'       => prj_setting_get($db, 'approver_dept_id', '0'),
        'approver_user_id'       => prj_setting_get($db, 'approver_user_id', '0'),
        'default_cosign_depts'   => prj_setting_get($db, 'default_cosign_depts', ''),
        'block_close_on_missing' => prj_setting_get($db, 'block_close_on_missing', '1'),
        'plan_stamp_tpl_id'      => prj_setting_get($db, 'plan_stamp_tpl_id', '0'),
        'card_stamp_tpl_id'      => prj_setting_get($db, 'card_stamp_tpl_id', '0'),
    ]]);

case 'setting_save':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    foreach (['approver_dept_id' => '立案核准綁定部門', 'approver_user_id' => '立案核准綁定人員',
              'default_cosign_depts' => '預設會簽單位', 'block_close_on_missing' => '結案前強制文件檢核',
              'plan_stamp_tpl_id' => '執行規劃表圖章模板', 'card_stamp_tpl_id' => '管理卡圖章模板'] as $k => $desc) {
        if (!array_key_exists($k, $_POST)) continue;
        prj_setting_save($db, $k, trim((string)$_POST[$k]), $desc, $uname);
    }
    jout(['message' => '已儲存設定']);

case 'asdoc_save':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    $module = (string)($_POST['module'] ?? '');
    if (!in_array($module, [PRJ_ASDOC_PLAN, PRJ_ASDOC_CARD], true)) jerr('參數錯誤');
    eg_asdoc_save($db, $module, (int)($_POST['doc_id'] ?? 0), $uname);
    jout(['message' => '已綁定', 'meta' => prj_print_meta($db, $module, null)]);

/** 列印中繼資料：版次依該單據自己的業務日期回推（ai-rules/16 第三之四節） */
case 'print_meta':
    if (!$P['canView']) jerr('無權限', 403);
    $module = (string)($_GET['module'] ?? PRJ_ASDOC_PLAN);
    if (!in_array($module, [PRJ_ASDOC_PLAN, PRJ_ASDOC_CARD], true)) jerr('參數錯誤');
    $bizDate = trim((string)($_GET['biz_date'] ?? '')) ?: null;
    $meta = prj_print_meta($db, $module, $bizDate);
    // 圖章的部門職稱依業務日期回推當時職務（ai-rules/22）
    $signers = [];
    foreach (explode(',', (string)($_GET['signer_ids'] ?? '')) as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) $signers[$sid] = prj_sign_post($db, $sid, $bizDate, $NOW['date']);
    }
    jout(['meta' => $meta, 'signers' => $signers,
          'stamp_tpl_id' => (int)prj_setting_get($db, $module === PRJ_ASDOC_CARD ? 'card_stamp_tpl_id' : 'plan_stamp_tpl_id', '0')]);

/** CSV：條件送後端，對全部符合條件的資料組檔（不可只用前端這一頁算＝ai-rules/08） */
case 'export_csv':
    if (!$P['canView']) jerr('無權限', 403);
    $rows = prj_list($db, $_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="project_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['專案代號', '類型', '專案名稱', '客戶', '負責人', '階段', '狀態',
                   '起日', '迄日', '進度%', '訂單數', '料號數', '任務數', '管理卡數']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['project_no'], $r['type_label'], $r['project_name'], $r['customer_name'],
                       $r['owner_name'], $r['phase_label'], $r['status'],
                       eg_fmt_date($r['start_date']), eg_fmt_date($r['end_date']), $r['progress'],
                       $r['order_cnt'], $r['part_cnt'], $r['task_cnt'], $r['card_cnt']]);
    }
    fclose($out);
    exit;

default:
    jerr('未知的動作：' . $action, 404);
}
