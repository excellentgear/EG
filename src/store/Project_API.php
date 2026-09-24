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
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
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
    $content = '專案代號：' . $prj['project_no'] . '（' . (prj_types($db)[$prj['project_type']] ?? '') . "型)\n"
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
    // 一人一列，但 dept/position 一律換成「主職務」、其餘職務放進 alt_posts（前端標「（兼 …）」）。
    // eg_people_list() 挑的是職級最高那筆，兼任職級較高的人（主職 技術課 工程師、兼任 生管組 組長）
    // 在下拉裡會只剩兼任身分、主職完全看不到——使用者 2026-09-22 回報的就是這個。
    $people = [];
    try { $people = eg_people_annotate_posts($db, eg_people_list($db, [])); } catch (Throwable $e) {}
    // 專案負責人候選＝兩層限制疊起來（見 prj_owner_people 的註解）：
    //   ① 模組設定的「專案負責人資格」（部門×職稱），未設定＝不限制
    //   ② 非專案管理員只能挑自己所屬部門（含兼任與子部門）內的人，管理員不受限
    // 這裡刻意不額外保留目前登入者——下拉列得出來、後端 prj_owner_allowed() 卻擋下來會很難理解。
    // 既有專案原本的負責人由前端 renderBase() 自己補回下拉（後端存檔時亦放行未變更的負責人）。
    $ownerPeople = [];
    try { $ownerPeople = eg_people_annotate_posts($db, prj_owner_people($db, [], $uid, (bool)$P['canAdmin'])); }
    catch (Throwable $e) { $ownerPeople = $people; }
    // 部門一律依 sort_order 由小到大（＝組織由上而下：董事長室→總經理室→生產部…→文管中心）。
    // 原本寫 DESC，畫面上的部門下拉會從文管中心倒著列，跟 ai-rules/08 鐵則6 的排序方向相反。
    // parent_id／level 是給「先選部門再選人」展開子部門用的（組織是樹狀的，只比單一 id 會漏掉底下的組）
    $depts = $db->query("SELECT id, name, parent_id, COALESCE(level,0) AS level, COALESCE(sort_order,999) AS sort_order
                         FROM department ORDER BY COALESCE(sort_order,999), name")->fetchAll(PDO::FETCH_ASSOC);
    $positions = [];
    try { $positions = $db->query("SELECT id, name FROM position ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    $custs = $db->query("SELECT customer_id, customer FROM customer_list
                         WHERE COALESCE(is_inactive,0)=0 ORDER BY customer")->fetchAll(PDO::FETCH_ASSOC);
    $tpls = [];
    try {
        $tpls = $db->query("SELECT id, tpl_name FROM stamp_template WHERE is_active=1 ORDER BY tpl_name")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
    jout([
        'types'      => prj_types($db, true),
        'types_all'  => prj_types($db),
        'phases'     => PRJ_PHASES,
        'tag_kinds'  => PRJ_TAG_KINDS,
        'phrase_fields' => PRJ_PHRASE_FIELDS,
        'doc_checks' => PRJ_DOC_CHECKS,
        'doc_phase'  => PRJ_DOC_PHASE,
        'fai_results'=> PRJ_FAI_RESULTS,
        'task_status'=> PRJ_TASK_STATUS,
        'tags'       => prj_tags_all($db),
        'people'     => $people,
        // 每人「所有」的部門×職稱：eg_people_list 一人只回一列（職級最高那筆），
        // 兼任的另一個職務在下拉裡會看不到，執行規劃表的「先選部門再選人」要靠這份。
        'people_posts'     => prj_people_posts($db, array_column($people, 'id')),
        'task_owner_depts' => prj_task_owner_depts($db),
        // 工作日行事曆（休假日／補班日）：規劃表的「工作天數」要在畫面上即時算，帶下去給前端用
        'workday'          => prj_workday_sets($db),
        'owner_people' => $ownerPeople,
        'owner_scope'  => prj_owner_scope_labeled($db),
        'owner_default'    => $uid,                    // 新專案／訂單轉專案的負責人預設＝目前使用者
        'owner_restricted' => !$P['canAdmin'],         // 非管理員：只能挑自己部門（含兼任）的人
        'owner_scope_all'  => prj_owner_scope_labeled($db) ? eg_people_annotate_posts($db, prj_owner_people($db)) : null,
        'depts'      => $depts,
        'positions'  => $positions,
        'customers'  => $custs,
        'stamp_tpls' => $tpls,
        'today'      => $NOW['date'],
        'default_cosign_depts' => prj_setting_get($db, 'default_cosign_depts', ''),
        // 挑選器要的完整 AS 文件清單（eg_asdoc_picker 的 opt.docs；沒有它跳窗會是空的、打字永遠「符合 0 筆」）
        'as_docs'    => eg_asdoc_list($db),
        'asdoc'      => [
            'plan' => prj_print_meta($db, PRJ_ASDOC_PLAN, null),
            'card' => prj_print_meta($db, PRJ_ASDOC_CARD, null),
            'plan_id' => eg_asdoc_id($db, PRJ_ASDOC_PLAN),
            'card_id' => eg_asdoc_id($db, PRJ_ASDOC_CARD),
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
    // 「送首件檢驗」是系統固定環節：只要專案已經有目標就要看得到那一列（不必等到存過規劃表）
    try { prj_fai_ensure_task($db, $pid); } catch (Throwable $e) {}
    /* 偵測得到的佐證直接套用（順路觸發）：使用者要求「綁定後自動認定已完成、進度 100%，
       不需另外填回報進度」，所以在開專案的當下就把該補的完成日與負責人補上，
       畫面看到的與資料庫存的才是同一份（只填空的，人工填過的一律不動）。 */
    try { prj_auto_fill_tasks($db, $pid, $prj); } catch (Throwable $e) {}
    jout([
        'project'   => $prj,
        'goals'     => prj_goals($db, $pid),
        'tasks'     => prj_tasks($db, $pid),
        'orders'    => prj_orders($db, $pid),
        'parts'     => prj_parts($db, $pid),
        'processes' => prj_processes($db, $pid, $prj),
        'scope_candidates' => prj_scope_candidates($db, $pid),
        'attach_counts'    => prj_task_attach_counts($db, $pid),
        // 自動偵測到的完成日佐證（實測 40ms）。放進 get 是為了讓畫面**直接看得到**偵測結果，
        // 不必先點開回報跳窗才知道系統有沒有抓到——使用者回報「這些功能有做嗎」就是因為看不到。
        'evidence'         => prj_task_evidence($db, $pid, $prj),
        'auto_kinds'       => PRJ_AUTO_KINDS,
        'auto_sign_range'  => prj_auto_sign_range($db, $prj, $NOW['date']),
        'shipments' => prj_shipments($db, $pid),
        'work_reports' => prj_work_reports($db, $pid),
        'fai'          => prj_fai_list($db, $pid),
        'fai_pass_date'=> prj_fai_pass_date($db, $pid),
        'fai_results'  => PRJ_FAI_RESULTS,
        'doc_phase'    => PRJ_DOC_PHASE,
        'cards'     => prj_cards($db, $pid),
        'cosigns'   => prj_cosigns($db, $pid),
        'alerts'    => prj_bom_alerts($db, $pid),
        'doc_check' => prj_doc_check($db, $pid),
        'can_edit'  => prj_can_edit_project($P, $prj),
        // 實際開始／實際完成＝立案核准後才可填（使用者拍板）；前端反灰，後端 plan_save 同規則再擋一次
        'act_open'  => prj_act_dates_open($prj),
        // 任務狀態＝送簽之後才開放（送簽前一律「未開始」，前端整欄不顯示）
        'status_open' => prj_task_status_open($prj),
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
        // 專案內容只留「專案目的／專案目標」兩項（使用者要求，2026-08-25）；
        // background／contribution／note 三欄保留在資料表但不再由畫面寫入，UPDATE 也刻意不碰，既有值不會被洗掉。
        'purpose'      => trim((string)($_POST['purpose'] ?? '')),
        'goal_desc'    => trim((string)($_POST['goal_desc'] ?? '')),
        'plan_date'    => trim((string)($_POST['plan_date'] ?? '')) ?: null,
        'start_date'   => trim((string)($_POST['start_date'] ?? '')) ?: null,
        'end_date'     => trim((string)($_POST['end_date'] ?? '')) ?: null,
        'tag_ids'      => prj_tag_csv(prj_tag_ids((string)($_POST['tag_ids'] ?? ''))),
        // 專案涵蓋的製程：空＝整張 BOM 所有製程（使用者指定的預設語意）
        'scope_process_no' => prj_tag_csv(prj_tag_ids((string)($_POST['scope_process_no'] ?? ''))),
    ];
    // 客戶：有綁定料號就一律由料號推導、不採信前端送來的值（鐵律8——前端已鎖住輸入框，
    // 這裡是最後防線，避免直打 API 塞進別的客戶代號）。新專案（pid=0）此時通常還沒有綁料號，維持自由填寫。
    if ($pid > 0) {
        $cf = prj_customer_from_parts($db, $pid);
        if ($cf) $data['customer_id'] = $cf['id'];
    }
    $err = prj_validate($data, [], $db);
    // 專案負責人資格（模組設定 → 專案負責人資格）：前端下拉已只列合格的人，後端同規則再擋一次（鐵律8）。
    // 既有專案的負責人維持原值時一律放行——設定改嚴不該讓舊專案變成存不了檔。
    if (!$err && $data['owner_id'] > 0 && !prj_owner_allowed($db, $data['owner_id'], $uid, (bool)$P['canAdmin'])) {
        $oldOwner = 0;
        if ($pid > 0) {
            $st = $db->prepare("SELECT owner_id FROM project WHERE project_id=?");
            $st->execute([$pid]);
            $oldOwner = (int)$st->fetchColumn();
        }
        if ($data['owner_id'] !== $oldOwner) {
            $err['owner_id'] = $P['canAdmin']
                ? '這個人不符合專案負責人資格（部門／職稱不在管理員設定的範圍內）'
                : '只能指派自己所屬部門（含兼任）內、且符合負責人資格的人員；要指派其他部門的人請洽專案管理員';
        }
    }
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
            /* **送簽之後不可以再改專案性質**（使用者指定）——性質是專案代號的第一碼，
               號碼一送簽就跟著會簽通知、執行規劃表與管理卡出去了，改性質等於改號碼。
               連管理員也擋（要改請退回成草稿），否則紙本與系統永遠對不起來。 */
            if ($data['project_type'] !== (string)$prj['project_type']
                && !in_array((string)$prj['status'], ['draft', 'rejected'], true)) {
                throw new RuntimeException('已送簽的專案不可以更改專案性質（性質是專案代號的第一碼，改了代號就要跟著改）。要更改請先退回成草稿。');
            }
            $st = $db->prepare("UPDATE project SET project_type=?, project_name=?, customer_id=?, customer_name=?,
                                    owner_id=?, owner_name=?, dept_id=?, dept_name=?, purpose=?,
                                    goal_desc=?, plan_date=?, start_date=?, end_date=?,
                                    tag_ids=?, scope_process_no=?, modified_by=?, modified_at=?
                                WHERE project_id=?");
            $st->execute([$data['project_type'], $data['project_name'], $data['customer_id'], $custName,
                          $data['owner_id'], $ownerName, $data['dept_id'], $deptName,
                          $data['purpose'], $data['goal_desc'],
                          $data['plan_date'], $data['start_date'], $data['end_date'],
                          $data['tag_ids'], $data['scope_process_no'], $uid, $NOW['dt'], $pid]);
        } else {
            if (!$P['canEdit']) throw new RuntimeException('無新增權限（需「專案登錄」角色）');
            $no = prj_next_no($db, $data['project_type'], $data['start_date'] ?: $NOW['date']);
            /* phase 欄位保留在資料表（舊資料還在用），但一律不再由畫面寫入——
               目前階段改成系統自動判斷（prj_phase_auto）。budget 同理，使用者已指示取消該欄位。 */
            $st = $db->prepare("INSERT INTO project (project_no, project_type, project_name, customer_id, customer_name,
                                    owner_id, owner_name, dept_id, dept_name, phase, purpose,
                                    goal_desc, plan_date, start_date, end_date, tag_ids,
                                    source, created_by, created_by_name, created_at)
                                VALUES (?,?,?,?,?,?,?,?,?,'planning',?,?,?,?,?,?,'manual',?,?,?)");
            $st->execute([$no, $data['project_type'], $data['project_name'], $data['customer_id'], $custName,
                          $data['owner_id'], $ownerName, $data['dept_id'], $deptName,
                          $data['purpose'], $data['goal_desc'],
                          $data['plan_date'], $data['start_date'], $data['end_date'],
                          $data['tag_ids'], $uid, $uname, $NOW['dt']]);
            $pid = (int)$db->lastInsertId();
        }
        /* 性質改掉時把專案代號一起重編（只重編還沒發出去的；已送簽以上在上面就擋掉了） */
        $renum = prj_sync_no($db, $pid, $data['project_type'], $uname);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr($e->getMessage());
    }
    $msg = '已儲存';
    if (!empty($renum['new'])) {
        // 改號一定要講出來——使用者手上可能正拿著舊號碼在找這個專案
        $msg .= '；專案性質改變，<b>專案代號已由 ' . htmlspecialchars($renum['old'])
              . ' 重編為 ' . htmlspecialchars($renum['new']) . '</b>';
    } elseif (!empty($renum['skip'])) {
        $msg .= '（專案已送簽，專案代號維持 ' . htmlspecialchars($renum['old']) . ' 不變）';
    }
    jout(['project_id' => $pid, 'message' => $msg, 'renum' => $renum]);

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
    $rows = prj_order_readiness($db, prj_order_candidates($db, $_GET));
    // 只看第一次下訂（使用者要求的預設）；判不出來的（訂單沒綁料號主檔 id）一律保留，
    // 直接濾掉會讓人以為系統漏了訂單，而且完全看不出原因
    if (!empty($_GET['first_only'])) {
        $rows = array_values(array_filter($rows, static fn($r) => $r['is_first'] !== 0));
    }
    // 「所有資料完整優先，不完整者一樣列出，越完整的列在越上面」（使用者原話）
    usort($rows, static function ($a, $b) {
        return [(int)$b['ready_pct'], (string)$b['Order_date'], (int)$b['Order_id']]
           <=> [(int)$a['ready_pct'], (string)$a['Order_date'], (int)$a['Order_id']];
    });
    jout(['rows' => $rows, 'ready_items' => PRJ_READY_ITEMS]);

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
            $tps = prj_types($db, true);
            if (!isset($tps[$type])) $type = (string)(array_key_first($tps) ?: 'C');
            $name = trim((string)($_POST['project_name'] ?? ''));
            if ($name === '') {
                // 沒填名稱時用「客戶＋料號」自動命名（多料號取第一個並標示還有幾項）
                $first = $orders[0];
                $name = trim((string)$first['Client_name'] . ' ' . (string)$first['d_id']);
                if (count($orders) > 1) $name .= ' 等 ' . count($orders) . ' 項';
            }
            $ownerId = (int)($_POST['owner_id'] ?? 0) ?: $uid;
            // 負責人資格後端再驗一次（前端下拉已只列合格的人）；沒指定而退回建立者本人時不擋，
            // 否則不合資格的人連轉專案都做不了，只是專案會留一個要事後改的負責人。
            if ((int)($_POST['owner_id'] ?? 0) > 0 && !prj_owner_allowed($db, $ownerId, $uid, (bool)$P['canAdmin'])) {
                throw new RuntimeException($P['canAdmin']
                    ? '這個人不符合專案負責人資格（部門／職稱不在管理員設定的範圍內）'
                    : '只能指派自己所屬部門（含兼任）內、且符合負責人資格的人員；要指派其他部門的人請洽專案管理員');
            }
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

/* ══════════════════════════ 專案性質（管理員維護） ══════════════════════════
   代號是**專案代號的第一碼**（例 C260501），所以只能一個英文字母、不可重複。 */
case 'type_list':
    if (!$P['canView']) jerr('無權限', 403);
    $rows = $db->query("SELECT type_code, type_name, sort_order, is_active
                        FROM project_type_opt ORDER BY sort_order, type_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['used'] = prj_type_usage($db, (string)$r['type_code']); }
    unset($r);
    jout(['rows' => $rows]);

case 'type_save':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    $code = strtoupper(trim((string)($_POST['type_code'] ?? '')));
    $name = trim((string)($_POST['type_name'] ?? ''));
    $isNew = (int)($_POST['is_new'] ?? 0) === 1;
    if (!preg_match('/^[A-Z]$/', $code)) jerr('代號只能是一個英文字母（A~Z）——它是專案代號的第一碼');
    if ($name === '') jerr('請填性質名稱');
    if (mb_strlen($name) > 20) jerr('性質名稱最多 20 個字');
    $exists = (int)$db->query("SELECT COUNT(*) FROM project_type_opt WHERE type_code=" . $db->quote($code))->fetchColumn();
    if ($isNew && $exists) jerr('代號 ' . $code . ' 已經存在');
    if ($isNew) {
        $db->prepare("INSERT INTO project_type_opt (type_code, type_name, sort_order, is_active, created_by, created_at)
                      VALUES (?,?,?,?,?,?)")
           ->execute([$code, $name, (int)($_POST['sort_order'] ?? 0),
                      (int)($_POST['is_active'] ?? 1) ? 1 : 0, $uname, $NOW['dt']]);
    } else {
        if (!$exists) jerr('找不到這個性質');
        $db->prepare("UPDATE project_type_opt SET type_name=?, sort_order=?, is_active=?, modified_by=?, modified_at=?
                      WHERE type_code=?")
           ->execute([$name, (int)($_POST['sort_order'] ?? 0), (int)($_POST['is_active'] ?? 1) ? 1 : 0,
                      $uname, $NOW['dt'], $code]);
    }
    jout(['message' => '已儲存']);

/**
 * 刪除專案性質。使用者明確要求：**一定要先確認有沒有專案在用，
 * 全數移轉到其他性質之後才可以刪**。所以沒帶 move_to 時一律先回報用量與可移轉的對象，
 * 不會偷偷刪掉（把專案的性質洗成空值，畫面上那一欄就變空白而且查不出原因）。
 */
case 'type_delete':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    $code = strtoupper(trim((string)($_POST['type_code'] ?? '')));
    if (!preg_match('/^[A-Z]$/', $code)) jerr('參數錯誤');
    $used = prj_type_usage($db, $code);
    $others = [];
    foreach (prj_types($db) as $c => $n) if ($c !== $code) $others[] = ['code' => $c, 'name' => $n];
    if ($used > 0) {
        $moveTo = strtoupper(trim((string)($_POST['move_to'] ?? '')));
        if ($moveTo === '') {
            // 還沒指定要移到哪裡：回報現況讓前端跳出移轉選單，**不刪**
            jout(['need_move' => 1, 'used' => $used, 'others' => $others,
                  'message' => '有 ' . $used . ' 個專案正在用這個性質，要先全部移轉到其他性質才能刪除。']);
        }
        if ($moveTo === $code) jerr('不能移轉到自己');
        if (!isset(prj_types($db)[$moveTo])) jerr('要移轉到的性質不存在');
        $db->beginTransaction();
        try {
            /* 刻意**不改既有的專案代號**：代號在立案當下就發出去了、也印在紙本表單上，
               事後改號會跟紙本對不起來，還可能跟別的專案撞號（uq_no）。
               只改性質欄位，新專案才會用新的代號開頭。 */
            $db->prepare("UPDATE project SET project_type=?, modified_by=?, modified_at=? WHERE project_type=?")
               ->execute([$moveTo, $uid, $NOW['dt'], $code]);
            $db->prepare("DELETE FROM project_type_opt WHERE type_code=?")->execute([$code]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jerr('移轉失敗：' . $e->getMessage());
        }
        jout(['message' => '已把 ' . $used . ' 個專案移轉到「' . prj_types($db)[$moveTo] . '」並刪除這個性質'
                         . '（既有的專案代號不變，那是立案當下就發出去的編號）']);
    }
    // 沒有專案在用：直接刪，但至少要留一種
    if (count($others) === 0) jerr('至少要保留一種專案性質');
    $db->prepare("DELETE FROM project_type_opt WHERE type_code=?")->execute([$code]);
    jout(['message' => '已刪除（沒有任何專案使用這個性質）']);

/* ══════════════════════════ 進度回報（各步驟的負責人自己回報） ══════════════════════════ */

/** 開啟回報跳窗要的資料：這個步驟＋系統自動偵測到的佐證＋已上傳的附件 */
case 'report_get':
    $pid  = (int)($_GET['project_id'] ?? 0);
    $prj  = prj_need($db, $P, $pid);
    $tid  = (int)($_GET['task_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM project_task WHERE task_id=? AND project_id=?");
    $st->execute([$tid, $pid]);
    $task = $st->fetch(PDO::FETCH_ASSOC);
    if (!$task) jerr('找不到這個步驟');
    $kinds = prj_auto_kinds_of((string)$task['task_name'], (string)$task['task_kind']);
    // 佐證是整個專案共用的（同一批 BOM／料號），一次算好再依 kind 取用
    $ev = prj_task_evidence($db, $pid, $prj);
    // 多製程專案：這個步驟指定了對應的製程（FAI）或製程大類（最終檢驗）時，候選只留符合的那幾筆，
    // 避免好幾道製程的首件檢驗混在一起挑錯（使用者 2026-09-23 明確要求）。
    // 篩到一筆都不剩時仍然把全部列出來（比讓人以為「完全沒有資料」安全，畫面上原本就看得到日期與製程名）。
    $linkProc = (int)($task['link_process_no'] ?? 0);
    $linkType = (int)($task['link_process_type_id'] ?? 0);
    if ($linkProc > 0 && !empty($ev['fai']['options'])) {
        $filtered = array_values(array_filter($ev['fai']['options'], static fn($o) => (int)($o['process_no'] ?? 0) === $linkProc));
        if ($filtered) $ev['fai']['options'] = $filtered;
    }
    if ($linkType > 0 && !empty($ev['final_qc']['options'])) {
        $nos = array_values(array_unique(array_map(static fn($o) => (int)($o['process_no'] ?? 0), $ev['final_qc']['options'])));
        $typeIdOf = [];
        if ($nos) {
            $inList = implode(',', array_map('intval', $nos));
            try {
                foreach ($db->query("SELECT ProcessNo, process_type_id FROM process_no WHERE ProcessNo IN ($inList)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $typeIdOf[(int)$r['ProcessNo']] = (int)$r['process_type_id'];
                }
            } catch (Throwable $e) {}
        }
        $filtered = array_values(array_filter($ev['final_qc']['options'], static function ($o) use ($typeIdOf, $linkType) {
            // 報告類佐證（rptfile:）本身就帶著自己的製程大類；沒設定大類的報告視為不限、一律放行
            if (strpos((string)($o['ref'] ?? ''), 'rptfile:') === 0) {
                $t = $o['process_type_id'] ?? null;
                return $t === null || (int)$t === $linkType;
            }
            return ($typeIdOf[(int)($o['process_no'] ?? 0)] ?? 0) === $linkType;
        }));
        if ($filtered) $ev['final_qc']['options'] = $filtered;
    }
    $pick = [];
    foreach ($kinds as $k) $pick[$k] = ['label' => PRJ_AUTO_KINDS[$k] ?? $k] + $ev[$k];
    jout(['task' => $task, 'kinds' => $pick, 'attaches' => prj_task_attaches($db, $tid),
          'can_report' => prj_can_report_task($task, $prj, $P, $uid),
          'act_open'   => prj_act_dates_open($prj),
          'today'      => $NOW['date']]);

/** 回報：實際起迄、進度、狀態、備註。權限＝該步驟負責人本人也可以（使用者指定） */
case 'report_save':
    $pid  = (int)($_POST['project_id'] ?? 0);
    $prj  = prj_need($db, $P, $pid);
    $tid  = (int)($_POST['task_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM project_task WHERE task_id=? AND project_id=?");
    $st->execute([$tid, $pid]);
    $task = $st->fetch(PDO::FETCH_ASSOC);
    if (!$task) jerr('找不到這個步驟');
    if (!prj_can_report_task($task, $prj, $P, $uid)) jerr('只有這個步驟的負責人、專案負責人或有專案登錄權的人可以回報', 403);
    if (!prj_act_dates_open($prj)) jerr('專案還沒核准立案，實際日期要等立案核准後才能填');

    $as = trim((string)($_POST['act_start'] ?? '')) ?: null;
    $ae = trim((string)($_POST['act_end'] ?? ''))   ?: null;
    if ($as && $ae && $ae < $as) jerr('實際完成日不可早於實際開始日');
    if ($ae && $ae > $NOW['date']) jerr('實際完成日不可以填未來日期');
    $pg = (int)($_POST['progress'] ?? 0);
    if ($pg < 0) $pg = 0; if ($pg > 100) $pg = 100;
    // 有實際完成日就一律 100%（避免出現「已完成但進度 60%」這種自相矛盾的列）
    if ($ae) $pg = 100;
    /* 狀態：**進度 100% 一律自動判定為「已完成」**，不接受前端送進來的值（使用者指定）；
       只有進度 <100% 時才由人自己挑進行中／待檢驗／異常。
       否則會出現「進度 100% 但狀態寫異常」這種自相矛盾、而且沒人看得出哪個才算數的列。 */
    $sc = (string)($_POST['status_code'] ?? '');
    if ($sc !== '' && !isset(PRJ_TASK_STATUS[$sc])) $sc = '';
    if ($pg >= 100 || $ae) { $sc = 'done'; }
    elseif ($sc === 'done') { $sc = ''; }          // 沒完成卻送 done＝矛盾，退回「未開始」讓它照進度走

    /* 採用了哪幾筆自動佐證（可多選）。後端**重新查一次佐證**再比對，只留真的存在的那幾筆——
       前端送什麼就存什麼的話，任何人都能塞一筆假佐證進去，而佐證正是「這個日期憑什麼填」的依據。 */
    $evJson = null;
    $pickRaw = json_decode((string)($_POST['evidence'] ?? '[]'), true);
    if (is_array($pickRaw) && $pickRaw) {
        $ev  = prj_task_evidence($db, $pid, $prj);
        $okSet = [];
        foreach ($ev as $k => $v) {
            foreach ($v['options'] as $o) $okSet[$k . '|' . $o['date'] . '|' . (string)$o['ref']] = $o + ['kind' => $k];
        }
        $keep = [];
        foreach ($pickRaw as $p) {
            $sig = (string)($p['kind'] ?? '') . '|' . (string)($p['date'] ?? '') . '|' . (string)($p['ref'] ?? '');
            if (isset($okSet[$sig])) $keep[] = $okSet[$sig];
        }
        if ($keep) $evJson = json_encode($keep, JSON_UNESCAPED_UNICODE);
    }

    // 管理員設定「結案前必須附報告佐證」時，FAI／最終檢驗步驟結案要至少有一筆 rptfile: 佐證
    // （使用者 2026-09-23：非必需也一樣可以連結，這裡只在管理員開了這個開關時才強制）。
    if ($sc === 'done' && prj_setting_get($db, 'require_report_evidence', '0') === '1') {
        $kk = prj_auto_kinds_of((string)$task['task_name'], (string)$task['task_kind']);
        if (array_intersect($kk, ['fai', 'final_qc'])) {
            $hasReport = false;
            foreach (json_decode((string)$evJson, true) ?: [] as $e) {
                if (strpos((string)($e['ref'] ?? ''), 'rptfile:') === 0) { $hasReport = true; break; }
            }
            if (!$hasReport) jerr('這個步驟要結案前，管理員設定必須至少勾選一筆「報告」佐證（見上方自動偵測到的佐證清單）');
        }
    }

    $db->prepare("UPDATE project_task SET act_start=?, act_end=?, progress=?, progress_auto=?, status_code=?,
                         report_note=?, evidence_json=?, reported_by=?, reported_by_name=?, reported_at=?
                  WHERE task_id=? AND project_id=?")
       ->execute([$as, $ae, $pg, $ae ? 1 : 0, $sc,
                  mb_substr(trim((string)($_POST['report_note'] ?? '')), 0, 500), $evJson,
                  $uid, $uname, $NOW['dt'], $tid, $pid]);
    jout(['message' => '已回報', 'progress' => prj_progress($db, $pid)]);

/** 佐證附件：上傳／刪除／下載（鐵律5：DB 只存檔名，路徑即時組） */
case 'report_upload':
    $pid  = (int)($_POST['project_id'] ?? 0);
    $prj  = prj_need($db, $P, $pid);
    $tid  = (int)($_POST['task_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM project_task WHERE task_id=? AND project_id=?");
    $st->execute([$tid, $pid]);
    $task = $st->fetch(PDO::FETCH_ASSOC);
    if (!$task) jerr('找不到這個步驟');
    if (!prj_can_report_task($task, $prj, $P, $uid)) jerr('無權限上傳', 403);
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 9) !== UPLOAD_ERR_OK) jerr('請選擇檔案');
    $orig = (string)$_FILES['file']['name'];
    $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    // 可執行／腳本副檔名一律擋（附件放在 NAS 上，點下去就執行了）
    if (in_array($ext, ['php','phtml','exe','bat','cmd','com','scr','js','vbs','ps1','jar','msi','hta'], true)) {
        jerr('不接受這種檔案類型（可執行或腳本檔）');
    }
    if (($_FILES['file']['size'] ?? 0) > 20 * 1024 * 1024) jerr('單檔上限 20MB');
    $dir  = prj_attach_dir($db);
    // 檔名時間戳一律取 DB 時間（本站 PHP 是 UTC、MySQL 是本地，混用會差 8 小時對不起來）
    $fn   = 'P' . $pid . '_T' . $tid . '_' . str_replace([' ', '-', ':'], '', $NOW['dt'])
          . '_' . bin2hex(random_bytes(3)) . ($ext !== '' ? '.' . $ext : '');
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $fn)) {
        jerr('檔案寫入失敗，請確認附件資料夾設定與 NAS 連線');
    }
    try {
        $db->prepare("INSERT INTO project_task_attach (project_id, task_id, filename, orig_name, file_size,
                             note, uploaded_by, uploaded_by_name, uploaded_at)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$pid, $tid, $fn, mb_substr($orig, 0, 255), (int)$_FILES['file']['size'],
                      mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200), $uid, $uname, $NOW['dt']]);
    } catch (Throwable $e) {
        // 寫不進 DB 就把剛落地的實體檔收掉，不然 NAS 上會留一個沒人認得的孤兒檔
        @unlink(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $fn);
        error_log('[Project_API] report_upload insert failed: ' . $e->getMessage());
        jerr('附件資料寫入失敗（詳細原因已寫入伺服器錯誤紀錄）');
    }
    jout(['message' => '已上傳', 'attaches' => prj_task_attaches($db, $tid)]);

case 'report_attach_del':
    $pid  = (int)($_POST['project_id'] ?? 0);
    $prj  = prj_need($db, $P, $pid);
    $aid  = (int)($_POST['attach_id'] ?? 0);
    $st = $db->prepare("SELECT a.*, t.owner_id FROM project_task_attach a
                        JOIN project_task t ON t.task_id=a.task_id
                        WHERE a.id=? AND a.project_id=?");
    $st->execute([$aid, $pid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jerr('找不到附件');
    if (!prj_can_report_task($row, $prj, $P, $uid)) jerr('無權限刪除', 403);
    $db->prepare("UPDATE project_task_attach SET deleted_at=?, deleted_by=? WHERE id=?")
       ->execute([$NOW['dt'], $uname, $aid]);
    jout(['message' => '已刪除', 'attaches' => prj_task_attaches($db, (int)$row['task_id'])]);

case 'report_attach_dl':
    $pid = (int)($_GET['project_id'] ?? 0);
    prj_need($db, $P, $pid);
    $aid = (int)($_GET['attach_id'] ?? 0);
    $st = $db->prepare("SELECT filename, orig_name FROM project_task_attach
                        WHERE id=? AND project_id=? AND deleted_at IS NULL");
    $st->execute([$aid, $pid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit('not found'); }
    // 只准單純檔名（DB 裡本來就只存檔名），擋掉 .. 與路徑分隔字元
    $fn = basename((string)$row['filename']);
    $fp = rtrim(prj_attach_dir($db), '/\\') . DIRECTORY_SEPARATOR . $fn;
    if ($fn === '' || !is_file($fp)) { http_response_code(404); exit('file missing'); }
    require_once $document_root . '/EGsystem/src/common/attach_lib.php';
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($fp));
    eg_attach_send_disposition((string)$row['orig_name']);
    readfile($fp);
    exit;

/**
 * 重編專案代號（限專案管理員）。代號第一碼＝專案性質，性質改過之後兩者會對不起來。
 * 存檔時的自動重編只動草稿／已退回，**已發出去的要由人明確按這一顆**——
 * 號碼已經印在執行規劃表、管理卡與會簽通知上，不可以在存檔時順手改掉。
 * 管理卡卡號（代號-01）一併同步。
 */
case 'renum':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    $pid = (int)($_POST['project_id'] ?? 0);
    $prj = prj_need($db, $P, $pid);
    $r = prj_sync_no($db, $pid, (string)$prj['project_type'], $uname, true);
    if (!$r || empty($r['new'])) jout(['message' => '代號的第一碼已經跟專案性質一致，不需要重編', 'renum' => null]);
    jout(['message' => '專案代號已由 ' . $r['old'] . ' 重編為 ' . $r['new'] . '（管理卡卡號一併更新）',
          'renum' => $r]);

/** 清單頁「就地展開進度」要的資料：只有目標與任務。
 *  刻意不用 get——那支會順路同步 BOM、算文件檢核、撈報工與出貨，展開一列不需要那些。 */
case 'plan_rows':
    $pid = (int)($_GET['project_id'] ?? 0);
    $prj = prj_need($db, $P, $pid);
    jout(['project' => $prj, 'goals' => prj_goals($db, $pid), 'tasks' => prj_tasks($db, $pid)]);

/** 執行規劃表的檢視方式（甘特／清單）。存在專案上不是只存在瀏覽器——
 *  使用者要求「專案若是設定使用清單式，列印就不該顯示甘特圖」，
 *  而清單那一列的「列印」不會先開專案，只有存進 DB 列印才跟得上。 */
case 'plan_view_save':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    // 檢視方式與刻度都記在專案上——只送其中一個就只改那一個（沒送＝不要動它）
    if (array_key_exists('plan_view', $_POST)) {
        $v = (string)$_POST['plan_view'];
        if (!in_array($v, ['gantt', 'list'], true)) jerr('參數錯誤');
        $db->prepare("UPDATE project SET plan_view=? WHERE project_id=?")->execute([$v, $pid]);
    }
    if (array_key_exists('plan_scale', $_POST)) {
        $s2 = (string)$_POST['plan_scale'];
        if (!in_array($s2, ['day', 'week', 'month'], true)) jerr('參數錯誤');
        $db->prepare("UPDATE project SET plan_scale=? WHERE project_id=?")->execute([$s2, $pid]);
    }
    $p2 = prj_get($db, $pid);
    jout(['message' => '已記住檢視設定',
          'plan_view' => (string)($p2['plan_view'] ?? 'gantt'), 'plan_scale' => (string)($p2['plan_scale'] ?? 'week')]);

/* ══════════════════════════ 出貨單綁定（只作確認資料用） ══════════════════════════ */
case 'ship_bind':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $isId = (int)($_POST['is_id'] ?? 0);
    if (!$isId) jerr('請選擇出貨明細');
    try { $no = prj_ship_bind($db, $pid, $isId, $uname); } catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['message' => '已綁定出貨單 ' . $no]);

case 'ship_unbind':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    prj_ship_unbind($db, $pid, (int)($_POST['is_id'] ?? 0));
    jout(['message' => '已解除綁定']);

case 'ship_search':
    $pid = (int)($_GET['project_id'] ?? 0);
    prj_need($db, $P, $pid);
    jout(['rows' => prj_ship_search($db, $pid, (string)($_GET['kw'] ?? ''))]);

/* ══════════════════════════ 料號（手動補掛） ══════════════════════════ */
case 'part_add':
    $pid = (int)($_POST['project_id'] ?? 0);
    $prj = prj_need($db, $P, $pid, true);
    // 已送簽的專案不可再增減料號綁定（使用者 2026-09-23 要求，與基本資料頁「已送簽不可更改」同一條界線）
    if (prj_submit_locked($prj)) jerr('已送簽的專案不可以再新增料號，要調整請先退回成草稿');
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
    $prj = prj_need($db, $P, $pid, true);
    if (prj_submit_locked($prj)) jerr('已送簽的專案不可以再移除料號，要調整請先退回成草稿');
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
    $prjPlan = prj_need($db, $P, $pid, true);
    // 立案核准前不接受「實際開始／實際完成」（前端已反灰，後端同規則再擋一次＝鐵律8）。
    // 注意是「忽略送上來的值、保留資料庫原本的值」而不是寫成空白——
    // 舊資料在這個規則之前就填過的實際日期不該因為存一次規劃表就被清掉。
    $actOpen = prj_act_dates_open($prjPlan);
    // 送簽前的專案：任務狀態一律「未開始」，不採信前端送上來的值（前端整欄不顯示＝鐵律8）
    $statusOpen = prj_task_status_open($prjPlan);
    $actOld  = [];
    if (!$actOpen) {
        $stA = $db->prepare("SELECT task_id, act_start, act_end FROM project_task WHERE project_id=?");
        $stA->execute([$pid]);
        foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $rA) $actOld[(int)$rA['task_id']] = $rA;
    }
    $goals = json_decode((string)($_POST['goals'] ?? '[]'), true) ?: [];
    $tasks = json_decode((string)($_POST['tasks'] ?? '[]'), true) ?: [];
    // 帶入專案自己的起日，任務的「預計開始不可早於專案起日」才驗得到（前端已即時擋，這裡同規則再擋一次＝鐵律8）
    /* 這裡只是要驗任務日程，專案本身的欄位塞一組一定合法的值就好
       （專案性質改成可維護之後，不可以再寫死 'C'——那一種被管理員刪掉就會誤報「請選擇專案性質」） */
    $err = prj_validate(['project_name' => 'x', 'project_type' => (string)array_key_first(prj_types($db, true)),
                         'owner_id' => 1,
                         'start_date' => (string)($prjPlan['start_date'] ?? ''), 'end_date' => ''], $tasks, $db);
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
            $ownerDept = $ownerId ? ((int)($t['owner_dept_id'] ?? 0) ?: null) : null;
            // 狀態驅動：狀態與實際完成日互相對齊（唯一實作 prj_task_status_sync）
            [$tStatus, $tActEndSync] = prj_task_status_sync((string)($t['status_code'] ?? ''),
                                                            trim((string)($t['act_end'] ?? '')) ?: null, $NOW['date']);
            $actS = trim((string)($t['act_start'] ?? '')) ?: null;
            $actE = trim((string)($t['act_end'] ?? '')) ?: null;
            $actE = $tActEndSync;   // 狀態驅動算出來的為準
            if (!$actOpen) {   // 核准前一律沿用資料庫原值（新任務＝空白）
                $actS = $actOld[$tid]['act_start'] ?? null;
                $actE = $actOld[$tid]['act_end'] ?? null;
                [$tStatus, $actE] = prj_task_status_sync($tStatus, $actE, $NOW['date']);
            }
            // 送簽前一律「未開始」（有實際完成日的舊資料除外，那要維持與日期一致）
            if (!$statusOpen && !$actE) $tStatus = '';
            /* 進度：還跟著自動的就由後端自己算（不採信前端送來的數字＝鐵律8），
               使用者手動改過的（progress_auto=0）才用他填的值。 */
            $pAuto = array_key_exists('progress_auto', $t) ? (!empty($t['progress_auto']) ? 1 : 0) : 1;
            $pVal  = $pAuto ? prj_task_progress_auto(['act_end' => $actE])
                            : max(0, min(100, (int)($t['progress'] ?? 0)));
            // 多製程專案指定 FAI 對應製程／最終檢驗對應製程大類（使用者 2026-09-23 要求）；
            // 不存在的製程編號／大類一律當沒填，避免存進一個查無此製程的髒值。
            $linkProc = (int)($t['link_process_no'] ?? 0);
            if ($linkProc > 0) {
                $chk = $db->prepare("SELECT 1 FROM project_process WHERE project_id=? AND process_no=? LIMIT 1");
                $chk->execute([$pid, $linkProc]);
                if (!$chk->fetchColumn()) $linkProc = 0;
            }
            $linkType = (int)($t['link_process_type_id'] ?? 0);
            if ($linkType > 0) {
                $chk2 = $db->prepare("SELECT 1 FROM process_type WHERE process_type_id=? LIMIT 1");
                $chk2->execute([$linkType]);
                if (!$chk2->fetchColumn()) $linkType = 0;
            }
            $args = [
                (int)($t['goal_id'] ?? 0) ?: null, $name,
                trim((string)($t['plan_start'] ?? '')) ?: null, trim((string)($t['plan_end'] ?? '')) ?: null,
                $actS, $actE,
                $ownerId, $ownerName, $ownerDept,
                $pVal, $pAuto,
                !empty($t['is_milestone']) ? 1 : 0,
                $tStatus,
                prj_tag_csv(prj_tag_ids((string)($t['tag_ids'] ?? ''))),
                trim((string)($t['note'] ?? '')), $j,
                /* 流程相依：只收 par／seq 兩種，第一列一律 seq（沒有「上一列」可以並行） */
                ($j > 0 && (string)($t['dep_mode'] ?? '') === 'par') ? 'par' : 'seq',
                $linkProc ?: null, $linkType ?: null,
            ];
            if ($tid) {
                $db->prepare("UPDATE project_task SET goal_id=?, task_name=?, plan_start=?, plan_end=?, act_start=?,
                                    act_end=?, owner_id=?, owner_name=?, owner_dept_id=?, progress=?, progress_auto=?,
                                    is_milestone=?, status_code=?, tag_ids=?, note=?, sort_order=?, dep_mode=?,
                                    link_process_no=?, link_process_type_id=?
                              WHERE task_id=? AND project_id=?")
                   ->execute(array_merge($args, [$tid, $pid]));
            } else {
                $db->prepare("INSERT INTO project_task (goal_id, task_name, plan_start, plan_end, act_start, act_end,
                                    owner_id, owner_name, owner_dept_id, progress, progress_auto, is_milestone,
                                    status_code, tag_ids, note, sort_order, dep_mode, link_process_no, link_process_type_id, project_id)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
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
    // 「送首件檢驗」是系統固定環節：前端沒送它也不能被上面的刪除掃掉，補回來
    try { prj_fai_ensure_task($db, $pid); } catch (Throwable $e) {}

    // 日程改了，仍為自動的管理卡基準要跟著重算（推導欄位鐵則）
    try {
        $st = $db->prepare("SELECT card_id FROM project_card WHERE project_id=? AND is_deleted=0 AND status='draft'");
        $st->execute([$pid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) prj_card_refresh_baseline($db, (int)$cid);
    } catch (Throwable $e) {}
    jout(['message' => '已儲存執行規劃表', 'goals' => prj_goals($db, $pid), 'tasks' => prj_tasks($db, $pid)]);

/* ══════════════════════════ 首件檢驗（AS9102 FAI） ══════════════════════════ */
case 'fai_save':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $fid    = (int)($_POST['fai_id'] ?? 0);
    $send   = trim((string)($_POST['send_date'] ?? '')) ?: null;
    $result = (string)($_POST['result'] ?? '');
    $rdate  = trim((string)($_POST['result_date'] ?? '')) ?: null;
    $note   = trim((string)($_POST['note'] ?? ''));
    if ($result !== '' && !isset(PRJ_FAI_RESULTS[$result])) jerr('檢驗結果不合法');
    // 判定日沒填就用送件日（現場常是當天送當天判）
    if ($result !== '' && !$rdate) $rdate = $send;
    if ($send && $rdate && $rdate < $send) {
        jerr('判定日不可早於送件日', 400, ['fields' => ['result_date' => '判定日不可早於送件日']]);
    }
    if ($result === 'fail' && $note === '') {
        jerr('未通過一定要填原因', 400, ['fields' => ['note' => '未通過一定要填原因']]);
    }
    if ($fid) {
        $st = $db->prepare("SELECT project_id FROM project_fai WHERE fai_id=?");
        $st->execute([$fid]);
        if ((int)$st->fetchColumn() !== $pid) jerr('這筆首件紀錄不屬於本專案', 404);
        $db->prepare("UPDATE project_fai SET send_date=?, result=?, result_date=?, note=?, modified_by=?, modified_at=?
                      WHERE fai_id=?")->execute([$send, $result, $rdate, $note, $uname, $NOW['dt'], $fid]);
    } else {
        // 重送：只有前一次「已經判定為未通過」才可以再開一次，否則會出現一堆空白的送件紀錄
        $rows = prj_fai_list($db, $pid);
        if ($rows) {
            $last = end($rows);
            if ((string)$last['result'] === '') jerr('上一次送件還沒有判定結果，請先填結果');
            if (prj_fai_is_pass((string)$last['result'])) jerr('首件已經通過，不需要再送件');
        }
        $seq = count($rows) + 1;
        $db->prepare("INSERT INTO project_fai (project_id, seq, send_date, result, result_date, note, created_by, created_at)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$pid, $seq, $send, $result, $rdate, $note, $uname, $NOW['dt']]);
        $fid = (int)$db->lastInsertId();
    }
    // 首件通過就把固定任務列標成完成（實際完成日＝通過日；進度仍走自動規則）
    try {
        $pass = prj_fai_pass_date($db, $pid);
        $tid  = prj_fai_ensure_task($db, $pid);
        if ($tid) {
            $db->prepare("UPDATE project_task SET act_end=?, progress=CASE WHEN progress_auto=1 THEN ? ELSE progress END
                          WHERE task_id=?")->execute([$pass, $pass ? 100 : 0, $tid]);
        }
    } catch (Throwable $e) {}
    // 未通過 → 自動補上 RCA 與 Delta FAI 兩個後續環節（使用者指定，不另做表單）
    $added = 0;
    try {
        $rows = prj_fai_list($db, $pid);
        $last = $rows ? end($rows) : null;
        if ($last && (string)$last['result'] === 'fail') $added = prj_fai_ensure_followup($db, $pid);
    } catch (Throwable $e) {}

    jout(['fai_id' => $fid, 'message' => '已儲存首件檢驗紀錄'
              . ($added ? '；已自動加入 RCA 與差異首件檢驗兩個環節' : ''),
          'followup_added' => $added,
          'tasks' => prj_tasks($db, $pid), 'goals' => prj_goals($db, $pid),
          'fai' => prj_fai_list($db, $pid), 'fai_pass_date' => prj_fai_pass_date($db, $pid),
          'doc_check' => prj_doc_check($db, $pid)]);

case 'fai_delete':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    if (!$P['canAdmin']) jerr('只有專案管理員可以刪除首件紀錄（AS9102 要求可追溯）', 403);
    $fid = (int)($_POST['fai_id'] ?? 0);
    $db->prepare("DELETE FROM project_fai WHERE fai_id=? AND project_id=?")->execute([$fid, $pid]);
    // 序號重排，避免出現第 1、3 次這種看不懂的編號
    $i = 1;
    foreach (prj_fai_list($db, $pid) as $r) {
        $db->prepare("UPDATE project_fai SET seq=? WHERE fai_id=?")->execute([$i++, (int)$r['fai_id']]);
    }
    jout(['message' => '已刪除', 'fai' => prj_fai_list($db, $pid),
          'fai_pass_date' => prj_fai_pass_date($db, $pid), 'doc_check' => prj_doc_check($db, $pid)]);

case 'seed_template':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $db->beginTransaction();
    try {
        $r = prj_seed_apply($db, $pid);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jerr('帶入失敗：' . $e->getMessage());
    }
    jout(['message' => $r['goals']
              ? ('已帶入標準流程：新增 ' . $r['goals'] . ' 個階段、' . $r['tasks'] . ' 個步驟')
              : '標準流程的階段都已經存在，沒有重複建立',
          'added' => $r, 'goals' => prj_goals($db, $pid), 'tasks' => prj_tasks($db, $pid)]);

/* ══════════════════════════ BOM 製程 ══════════════════════════ */
case 'bom_sync':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $r = prj_bom_sync($db, $pid, $uname, false);
    jout(['result' => $r, 'processes' => prj_processes($db, $pid, prj_get($db, $pid)),
          'scope_candidates' => prj_scope_candidates($db, $pid), 'alerts' => prj_bom_alerts($db, $pid),
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

/** 還沒開 BOM 的新專案，負責人先手動建立預計製程順序（使用者 2026-09-23 要求）。
 *  製程查詢直接用打字模糊搜尋（依編號或名稱），不做成一個攤開的下拉（209 筆）。 */
case 'process_search':
    $pid = (int)($_GET['project_id'] ?? 0);
    prj_need($db, $P, $pid);
    require_once __DIR__ . '/../common/sopsip_lib.php';
    jout(['rows' => ss_search_process($db, (string)($_GET['kw'] ?? ''), 40)]);

case 'process_manual_add':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    try {
        $r = prj_process_manual_add($db, $pid, (int)($_POST['process_no'] ?? 0), $uname);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['message' => '已加入製程 ' . $r['process_name'], 'processes' => prj_processes($db, $pid, prj_get($db, $pid))]);

case 'process_manual_remove':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    prj_process_manual_remove($db, $pid, (int)($_POST['id'] ?? 0));
    jout(['message' => '已移除', 'processes' => prj_processes($db, $pid, prj_get($db, $pid))]);

case 'process_note':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $db->prepare("UPDATE project_process SET note=?, is_milestone=? WHERE id=? AND project_id=?")
       ->execute([trim((string)($_POST['note'] ?? '')), !empty($_POST['is_milestone']) ? 1 : 0,
                  (int)($_POST['id'] ?? 0), $pid]);
    jout(['message' => '已儲存']);

/** 管理員手動修改發包日／回廠日（使用者明確要求，不在本專案範圍的製程列也要能改）。
 *  這裡是本模組唯一寫回 bom_ing 的入口，限管理員，見 prj_bom_dates_admin_update() 說明。 */
case 'process_dates':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    $fid = (int)($_POST['bom_ing_fid'] ?? 0);
    if ($fid <= 0) jerr('缺少製程列');
    try {
        $r = prj_bom_dates_admin_update($db, $pid, $fid, $_POST['outsource_date'] ?? null, $_POST['return_date'] ?? null, $u);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['message' => '已儲存', 'row' => $r, 'processes' => prj_processes($db, $pid, prj_get($db, $pid))]);

/* ══════════════════════════ 文件檢核 ══════════════════════════ */
case 'doc_check':
    $pid = (int)($_GET['project_id'] ?? 0);
    prj_need($db, $P, $pid);
    jout(['rows' => prj_doc_check($db, $pid), 'defs' => PRJ_DOC_CHECKS]);

/** 跨專案文件備齊總覽（使用者 2026-09-23 要求，比照 internal_audit.php 總覽） */
case 'doc_check_overview':
    if (!$P['canView']) jerr('無權限', 403);
    $r = prj_doc_check_overview($db, !empty($_GET['include_closed']));
    jout(['rows' => $r['rows'], 'summary' => $r['summary'], 'defs' => PRJ_DOC_CHECKS]);

/**
 * 可以綁的 SOP／SIP 候選文件（通用的、綁到這個料號的、這個料號用到的製程那幾份）。
 * 使用者 2026-09-23：「要可以選定是否綁定通用的 SOP/SIP，各種都不限定綁定一項」。
 */
case 'ss_cand':
    $pid = (int)($_GET['project_id'] ?? 0);
    prj_need($db, $P, $pid);
    $dsPk = (int)($_GET['ds_pk'] ?? 0);
    $kind = (string)($_GET['kind'] ?? 'sop');
    $rows = prj_ss_cands($db, $pid, $kind, $dsPk, (string)($_GET['kw'] ?? ''));
    // 目前已綁的（這個料號自己綁的＋全專案綁的，要分得出來：全專案那幾份不在這裡取消）
    $mine = [];
    $all  = [];
    foreach (prj_ss_binds($db, $pid) as $b) {
        if (prj_ss_kind((string)$b['kind']) !== (($kind === 'sip') ? 'sip' : 'sop')) continue;
        if ((int)$b['ds_pk'] === $dsPk) $mine[(int)$b['doc_id']] = true;
        elseif ((int)$b['ds_pk'] === 0)  $all[(int)$b['doc_id']] = $b;
    }
    foreach ($rows as &$r) {
        $r['bound']     = isset($mine[(int)$r['doc_id']]) ? 1 : 0;
        $r['bound_all'] = isset($all[(int)$r['doc_id']]) ? 1 : 0;
    }
    unset($r);
    jout(['rows' => $rows, 'kind' => (($kind === 'sip') ? 'sip' : 'sop'), 'ds_pk' => $dsPk]);

/** 存綁定（整組取代這個料號、這一種的綁定） */
case 'ss_bind_save':
    $pid = (int)($_POST['project_id'] ?? 0);
    prj_need($db, $P, $pid, true);
    $ids = json_decode((string)($_POST['doc_ids'] ?? '[]'), true);
    if (!is_array($ids)) $ids = [];
    $n = prj_ss_bind_save($db, $pid, (int)($_POST['ds_pk'] ?? 0),
                          (string)($_POST['kind'] ?? 'sop'), $ids, $uname);
    jout(['message' => $n ? ('已綁定 ' . $n . ' 份') : '已清除綁定',
          'doc_check' => prj_doc_check($db, $pid)]);

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
    $err = prj_validate($prj, prj_tasks($db, $pid), $db);
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
    $bizDate = trim((string)($_POST['biz_date'] ?? '')) ?: '';
    $done = 0;
    $db->beginTransaction();
    try {
        foreach ($ids as $pid) {
            $prj = prj_get($db, $pid);
            if (!$prj || in_array((string)$prj['status'], ['approved', 'closed'], true)) continue;
            /* 業務日期一律逐案夾在允許範圍內（前端已擋一次，後端同規則再擋一次＝鐵律8）。
               範圍是「專案起日 ~ 最早製令開立日的前一天」——先立案核准才開製令，
               核准日排在製令之後，印出來的表單就自相矛盾。 */
            $rg = prj_auto_sign_range($db, $prj, $NOW['date']);
            $d0 = $bizDate !== '' ? $bizDate : $rg['default'];
            if ($d0 < $rg['min'] || $d0 > $rg['max']) {
                if (count($ids) === 1) {
                    $db->rollBack();
                    jerr('核准日期只能填 ' . $rg['min'] . ' ~ ' . $rg['max'] . '。' . $rg['note']);
                }
                $d0 = min(max($d0, $rg['min']), $rg['max']);   // 批次時逐案夾住，不要整批失敗
            }
            [$d, $ts] = prj_auto_sign_stamp($d0, $d0 . ' 09:00:00');
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
    /* 管理卡的表身要印「階段 → 作業項目」兩層（使用者指定的欄位是
       項次／專案階段與核心作業項目／主辦·承辦人／預計完成日／實際完成日／交付成果·單號／狀態·簽核），
       所以任務與料號一起帶下去；交付成果取回報時採用的佐證（evidence_json）。 */
    $pid2 = (int)$card['project_id'];
    jout(['card' => $card, 'project' => $prj, 'goals' => prj_goals($db, $pid2),
          'tasks' => prj_tasks_attach_supervisor($db, prj_tasks($db, $pid2)), 'parts' => prj_parts($db, $pid2),
          'task_status' => PRJ_TASK_STATUS,
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

/* ── 常用語句（專案目的／專案目標，可自訂後一鍵帶入） ──
   帶入的是文字複本，刪掉語句不影響任何既有專案，所以維護權限比照「專案登錄」即可，不必到管理員。 */
case 'phrase_list':
    if (!$P['canView']) jerr('無權限', 403);
    $fk = (string)($_GET['field_key'] ?? '');
    jout(['rows' => prj_phrases_all($db, isset(PRJ_PHRASE_FIELDS[$fk]) ? $fk : null)]);

case 'phrase_save':
    if (!$P['canEdit']) jerr('無權限（需「專案登錄」角色）', 403);
    $phId = (int)($_POST['phrase_id'] ?? 0);
    $fk   = (string)($_POST['field_key'] ?? '');
    if (!isset(PRJ_PHRASE_FIELDS[$fk])) jerr('語句欄位不合法');
    $text = trim((string)($_POST['phrase_text'] ?? ''));
    // 前端已即時擋一次，後端同規則再擋一次（鐵律8）
    if ($text === '') jerr('請填語句內容', 400, ['fields' => ['phrase_text' => '請填語句內容']]);
    if (mb_strlen($text) > 500) {
        $msg = '語句最多 500 字（目前 ' . mb_strlen($text) . ' 字）';
        jerr($msg, 400, ['fields' => ['phrase_text' => $msg]]);
    }
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($phId) {
        $st = $db->prepare("SELECT field_key FROM project_phrase WHERE phrase_id=?");
        $st->execute([$phId]);
        if (!$st->fetchColumn()) jerr('這筆語句已不存在（可能已被其他人刪除）', 404);
        $db->prepare("UPDATE project_phrase SET field_key=?, phrase_text=?, sort_order=?, modified_by=?, modified_at=?
                      WHERE phrase_id=?")->execute([$fk, $text, $sort, $uname, $NOW['dt'], $phId]);
    } else {
        $db->prepare("INSERT INTO project_phrase (field_key, phrase_text, sort_order, created_by, created_at)
                      VALUES (?,?,?,?,?)")->execute([$fk, $text, $sort, $uname, $NOW['dt']]);
        $phId = (int)$db->lastInsertId();
    }
    jout(['phrase_id' => $phId, 'message' => '已儲存', 'rows' => prj_phrases_all($db, $fk)]);

case 'phrase_delete':
    if (!$P['canEdit']) jerr('無權限（需「專案登錄」角色）', 403);
    $phId = (int)($_POST['phrase_id'] ?? 0);
    $st = $db->prepare("SELECT field_key FROM project_phrase WHERE phrase_id=?");
    $st->execute([$phId]);
    $fk = (string)$st->fetchColumn();
    if ($fk === '') jerr('這筆語句已不存在', 404);
    $db->prepare("DELETE FROM project_phrase WHERE phrase_id=?")->execute([$phId]);
    jout(['message' => '已刪除', 'rows' => prj_phrases_all($db, $fk)]);

// 設定畫面的「還原內建預設」：把系統內建那一份回給前端（按了還要按儲存才會寫入）
case 'seed_default':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    jout(['rows' => prj_seed_template()]);

case 'setting_get':
    if (!$P['canView']) jerr('無權限', 403);
    jout(['setting' => [
        'approver_dept_id'       => prj_setting_get($db, 'approver_dept_id', '0'),
        'approver_user_id'       => prj_setting_get($db, 'approver_user_id', '0'),
        'default_cosign_depts'   => prj_setting_get($db, 'default_cosign_depts', ''),
        'block_close_on_missing' => prj_setting_get($db, 'block_close_on_missing', '1'),
        'plan_stamp_tpl_id'      => prj_setting_get($db, 'plan_stamp_tpl_id', '0'),
        'card_stamp_tpl_id'      => prj_setting_get($db, 'card_stamp_tpl_id', '0'),
        'owner_scope'            => prj_setting_get($db, 'owner_scope', ''),
        'task_owner_depts'       => implode(',', prj_task_owner_depts($db)),
        // 哪些附件標籤算「加工圖面」（進度佐證用，不寫死標籤名稱＝鐵律4）
        'drawing_attach_cats'    => prj_setting_get($db, 'drawing_attach_cats', ''),
        // 訂單轉專案「料號附件」完整度認哪幾個標籤（不設＝任何附件都算）
        'o2p_attach_cats'        => prj_setting_get($db, 'o2p_attach_cats', ''),
        // 文件檢核：SOP／SIP 要認列哪幾種來源（綁料號／製程／通用），可複選
        'doc_sop_scopes'          => prj_setting_get($db, 'doc_sop_scopes', 'part,process'),
        'doc_sip_scopes'          => prj_setting_get($db, 'doc_sip_scopes', 'part,process'),
        'owner_default_dept_id'   => (string)prj_owner_default_dept($db),
        'owner_order'             => implode(',', prj_owner_order($db)),
        // FAI／最終檢驗是否必須附上報告佐證才能結案（使用者 2026-09-23：非必需也一樣可以連結）
        'require_report_evidence' => prj_setting_get($db, 'require_report_evidence', '0'),
    ], 'owner_scope_rows' => prj_owner_scope_labeled($db),
     'attach_cats' => (function (PDO $db) {
         try {
             return $db->query("SELECT id, category_name FROM quotation_file_categories
                                WHERE COALESCE(is_active,1)=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
         } catch (Throwable $e) { return []; }
     })($db),
     // 標準流程範本：目前實際生效的那一份（沒自訂過就是內建預設），設定畫面直接編輯它
     'seed_template' => prj_seed_template($db),
     'seed_is_custom' => prj_seed_template_rows($db) ? 1 : 0]);

case 'setting_save':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    foreach (['approver_dept_id' => '立案核准綁定部門', 'approver_user_id' => '立案核准綁定人員',
              'default_cosign_depts' => '預設會簽單位', 'block_close_on_missing' => '結案前強制文件檢核',
              'plan_stamp_tpl_id' => '執行規劃表圖章模板', 'card_stamp_tpl_id' => '管理卡圖章模板',
              'drawing_attach_cats' => '算「加工圖面」的附件標籤',
              'o2p_attach_cats' => '訂單轉專案「料號附件」認的標籤',
              'doc_sop_scopes' => '文件檢核 SOP 認列來源', 'doc_sip_scopes' => '文件檢核 SIP 認列來源',
              'require_report_evidence' => 'FAI／最終檢驗是否必須附報告佐證才能結案'] as $k => $desc) {
        if (!array_key_exists($k, $_POST)) continue;
        prj_setting_save($db, $k, trim((string)$_POST[$k]), $desc, $uname);
    }
    // 執行規劃表負責人可挑選的部門（複選）：後端自己再正規化一次，不直接採信前端送來的字串
    if (array_key_exists('task_owner_depts', $_POST)) {
        $ids = [];
        foreach (explode(',', (string)$_POST['task_owner_depts']) as $v) { $v = (int)trim($v); if ($v > 0) $ids[] = $v; }
        $ids = array_values(array_unique($ids));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT id FROM department WHERE id IN ($in)");
            $st->execute($ids);
            // 已刪除的部門直接濾掉；順序以使用者送上來的為準（SELECT 回來的是資料表順序）
            $exists = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $ids = array_values(array_filter($ids, static fn($x) => in_array($x, $exists, true)));
        }
        prj_setting_save($db, 'task_owner_depts', implode(',', $ids), '執行規劃表負責人可挑選的部門', $uname);
    }
    // 標準流程範本：後端用同一支 normalize 再驗一次（階段／步驟名稱必填、首件只能有一列）
    if (array_key_exists('seed_template', $_POST)) {
        $raw = trim((string)$_POST['seed_template']);
        $rows = $raw === '' ? [] : prj_seed_template_normalize(json_decode($raw, true) ?: []);
        if ($raw !== '' && !$rows) jerr('標準流程範本至少要有一個階段、而且每個階段底下至少一個步驟', 400);
        if (count($rows) > 30) jerr('標準流程範本最多 30 個階段', 400);
        prj_setting_save($db, 'seed_template',
                         $rows ? json_encode($rows, JSON_UNESCAPED_UNICODE) : '',
                         '執行規劃表標準流程範本（空＝用內建預設）', $uname);
    }
    // 專案負責人資格（部門×職稱）：後端自己再解析驗證一次，不直接採信前端送來的字串
    if (array_key_exists('owner_scope', $_POST)) {
        $scope = prj_owner_scope_parse((string)$_POST['owner_scope']);
        prj_setting_save($db, 'owner_scope', $scope ? json_encode($scope, JSON_UNESCAPED_UNICODE) : '',
                         '專案負責人資格（部門×職稱）', $uname);
    }
    // 專案負責人預設部門＋該部門底下的顯示順序（使用者 2026-09-23 要求）
    if (array_key_exists('owner_order_dept', $_POST) || array_key_exists('owner_order', $_POST)) {
        $ordIds = [];
        foreach (explode(',', (string)($_POST['owner_order'] ?? '')) as $v) { $v = (int)trim($v); if ($v > 0) $ordIds[] = $v; }
        prj_owner_order_save($db, (int)($_POST['owner_order_dept'] ?? 0), $ordIds, $uname);
    }
    // 回傳兩份：owner_people＝目前這位管理員實際可挑的人；owner_scope_all＝純「資格」命中的全公司名單（設定畫面預覽用）
    jout(['message' => '已儲存設定', 'owner_scope_rows' => prj_owner_scope_labeled($db),
          'seed_template' => prj_seed_template($db), 'seed_is_custom' => prj_seed_template_rows($db) ? 1 : 0,
          'task_owner_depts' => prj_task_owner_depts($db),
          'owner_default_dept_id' => prj_owner_default_dept($db), 'owner_order' => prj_owner_order($db),
          'owner_people'    => eg_people_annotate_posts($db, prj_owner_people($db, [], $uid, (bool)$P['canAdmin'])),
          'owner_scope_all' => prj_owner_scope_labeled($db) ? eg_people_annotate_posts($db, prj_owner_people($db)) : null]);

/** 某個部門底下的人員（給「專案負責人順序」設定挑人用，不受 owner_scope 資格限制——
 *  排序畫面要能看到整個部門的人，資格是另一層判定，兩者本來就是不同的事）。 */
case 'owner_order_cand':
    if (!$P['canAdmin']) jerr('無權限（需「專案管理員」角色）', 403);
    $did = (int)($_GET['dept_id'] ?? 0);
    jout(['rows' => $did > 0 ? eg_people_annotate_posts($db, eg_people_list($db, ['dept_ids' => [$did]])) : []]);

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
        if ($sid <= 0) continue;
        $signers[$sid] = prj_sign_post($db, $sid, $bizDate, $NOW['date']);
        // 執行規劃表的「專案負責人」不是簽章、只是印出他是誰，所以印**主職務**
        // （prj_sign_post 取的是職級最高那筆＝代表身分，那是給圖章用的，兩者刻意分開）
        $one = eg_people_annotate_posts($db, [['id' => $sid, 'dept_name' => $signers[$sid]['dept'],
                                               'position_name' => $signers[$sid]['post']]]);
        $signers[$sid]['main_dept'] = (string)($one[0]['main_dept_name'] ?? '');
        $signers[$sid]['main_post'] = (string)($one[0]['main_position_name'] ?? '');
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
