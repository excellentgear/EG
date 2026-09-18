<?php
/**
 * 內部稽核（2-GM-06）API
 * 權限：internal_audit_lib.php ia_perms()（roles module='internal_audit'）
 *   ia_admin   內稽管理員（管理代表）：全部
 *   ia_auditor 稽核員：查檢表、開 IA 單、驗證
 *   ia_view    檢閱：唯讀
 *   其餘在職員工：只能回覆自己單位的 IA 單（逐單由 ia_nc_stage_perm() 判定）
 * 前端擋一次、後端同規則再擋一次（鐵律8），不留只擋 UI 的漏洞。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/internal_audit_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function jout($a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    ia_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = ia_current_user($db);
if (!$u) jerr('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = ia_perms($db, $u);
if (!$perms['uid']) jerr('無內部稽核使用權限（帳號非在職狀態）', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$today  = ia_today($db);

/** 到期提醒順路觸發：不另開排程，有人用這個模組就順便檢查一次（內部 static 擋重複、每單每天最多一則） */
ia_nc_remind_tick($db);

function iaReqAdmin(array $perms) { if (!$perms['canAdmin']) jerr('需要內稽管理員權限', 403); }
function iaReqAudit(array $perms) { if (!$perms['canAudit']) jerr('需要稽核員權限', 403); }
function iaReqView(array $perms)  { if (!$perms['canView'])  jerr('無檢視權限', 403); }

/** 日期字串驗證（空字串一律回 null，不寫入 0000-00-00） */
function iaDate($v): ?string
{
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
function iaTime($v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) return sprintf('%02d:%02d', $h, $i);
    }
    if (preg_match('/^(\d{1,2})(\d{2})$/', $v, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) return sprintf('%02d:%02d', $h, $i);
    }
    if (preg_match('/^\d{1,2}$/', $v)) { $h = (int)$v; if ($h >= 0 && $h <= 23) return sprintf('%02d:00', $h); }
    return null;
}
function iaInt($v): ?int { $v = trim((string)$v); return ($v === '' || !ctype_digit(ltrim($v, '-'))) ? null : (int)$v; }

/**
 * 受稽單位那一列的稽核員／陪檢員職務鍵清單（2026-08-27 起可多位）。
 * 新前端送 auditor_keys / escort_keys（陣列或 JSON 字串），
 * 舊呼叫端只送單一 auditor_key / escort_key 時也收得下。
 */
function iaRowPostKeys(array $d, string $kind): array
{
    $ks = $d[$kind . '_keys'] ?? null;
    if (is_string($ks)) { $j = json_decode($ks, true); $ks = is_array($j) ? $j : null; }
    if (!is_array($ks)) {
        $ks = [];
        $one = trim((string)($d[$kind . '_key'] ?? ''));
        if ($one !== '') $ks[] = $one;
    }
    $out = []; $seen = [];
    foreach ($ks as $k) {
        $k = trim((string)$k);
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = 1; $out[] = $k;
    }
    return $out;
}

/**
 * 表單上的「製表人」（2026-09-14 使用者交辦：年度計畫／稽核通知單／稽核報告表都要能事後改）。
 * 原本是建檔當下寫死、事後完全改不了，補歷史資料或換人接手時對不起來。
 * 前端沒有送 maker_id 這個欄位（舊呼叫端）時回 null＝這次不要動製表人。
 * 送了空字串＝清掉。姓名一律由 user 表現查，不採信前端送的名字。
 * 回 ['id'=>?int,'name'=>?string,'date'=>?string]
 */
function iaMakerFromPost(PDO $db, string $curDate = ''): ?array
{
    if (!array_key_exists('maker_id', $_POST)) return null;
    $raw = trim((string)$_POST['maker_id']);
    $date = iaDate($_POST['maker_date'] ?? '') ?: ($curDate ?: null);
    if ($raw === '') return ['id' => null, 'name' => null, 'date' => null];
    $uid = iaInt($raw);
    if ($uid === null) jerr('製表人不正確');
    $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
    $st->execute([$uid]);
    $nm = (string)($st->fetchColumn() ?: '');
    if ($nm === '') jerr('製表人不存在');
    return ['id' => $uid, 'name' => $nm, 'date' => $date];
}

switch ($action) {

/* ============================ meta ============================ */
case 'meta': {
    $set   = ia_settings($db);
    $depts = [];
    try { $depts = $db->query("SELECT id, name, parent_id, level FROM department ORDER BY sort_order, id")
                      ->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    $people = [];
    // posts＝這個人「所有」的職務（含主職與兼任）。eg_people_list() 一人只回一列、
    // 而且挑的是「職級最高」那一筆＝兼任常常蓋掉主職，製表人下拉就會看不到主職務
    // （2026-09-14 使用者回報）。補上 posts 之後由前端自己決定要印哪一個。
    try { $people = ia_annotate_posts($db, eg_people_list($db, [])); } catch (Throwable $e) {}

    // 七份表單各自的 AS 綁定（表頭名稱與頁尾編號都由綁定推導，不寫死）
    $asDocs = [];
    foreach (IA_ASDOC_MODULES as $k => $m) {
        $doc = eg_asdoc_get($db, $m['module']);
        $asDocs[$k] = [
            'label'    => $m['label'],
            'module'   => $m['module'],
            'doc_id'   => (int)($doc['id'] ?? 0),
            'doc_no'   => $doc ? eg_asdoc_no($doc) : $m['fallback'],
            'doc_name' => $doc['doc_name'] ?? $m['label'],
            'bound'    => (bool)$doc,
        ];
    }
    $stampTpls = [];
    if ($perms['canAdmin']) {
        try {
            $stampTpls = $db->query("SELECT p.id, p.tpl_name, t.type_name FROM stamp_template p
                                     LEFT JOIN stamp_type t ON t.id=p.type_id
                                     WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
    // 年度選單含近十年（管理員要補以前年度的資料，選單裡就得選得到）
    $years = ia_year_options($db);
    $cy    = (int)substr($today, 0, 4);

    jout([
        'me'        => ['id' => $uid, 'name' => $uname] + ia_identity_asof($db, $uid, $today),
        'perms'     => $perms,
        'role_label'=> ia_role_label($perms),
        'settings'  => $set,
        'case_remark_default' => IA_CASE_REMARK_DEFAULT,   // 設定跳窗的「還原內建預設文字」用
        'sign_sources' => IA_SIGN_SOURCES,
        'nc_types'  => IA_NC_TYPES,
        'nc_stages' => IA_NC_STAGES,
        'check_kinds' => IA_CHECK_KINDS,
        'as_docs'   => $asDocs,
        'as_doc_list' => $perms['canAdmin'] ? eg_asdoc_list($db) : [],
        'company'   => eg_company_full_name($db),
        'depts'     => $depts,
        'people'    => $people,
        // 受稽單位＝群組（如 生產部＋生產1/2/3廠）＋沒被群組收編的單一部門
        'units'     => ia_audit_units($db),
        // 稽核員／陪檢員只列有資格的人；名單沒設定時回全體在職員工。
        // 補上 posts 讓下拉標籤能顯示兼任的其他職務（一個人仍只有一個選項，值是 user_id）
        'auditors'  => ia_qualified_posts($db, 'auditor'),
        'escorts'   => ia_qualified_posts($db, 'escort'),
        'qualify_kinds' => IA_QUALIFY_KINDS,
        // 稽核小組：哪些年度已經建過（建通知單前先提醒，會議紀錄也靠它帶與會人員）
        'team_years'  => ia_team_years($db),
        'team_roles'  => IA_TEAM_ROLES,
        // 稽核範本：填通知單時一列一列帶入（含已算好的候選人員）
        'templates' => ia_process_templates($db),
        // 範本組合：填通知單時選一次就整批帶入好幾列受稽單位
        'tpl_sets'  => ia_tpl_sets($db),
        'stamp_tpls'=> $stampTpls,
        'years'     => $years,
        // 每個年度做到哪了（畫面在年度選單上顯示 ✔／進行中，未建立的不顯示圖示）
        'year_status' => ia_year_status($db),
        'this_year' => $cy,
        'today'     => $today,
        /* 這個人有沒有「操作確認密碼」可用（取消完成要輸入它）。
           沒有的話畫面就不該讓他按下去——按了也只會白試密碼，連錯三次還會把功能鎖七天
           （2026-09-18 使用者回報：沒有這個權限的人照樣按得下取消完成）。 */
        'can_confirm_pw' => (function () use ($db, $uid) {
            try {
                require_once $GLOBALS['document_root'] . '/EGsystem/src/common/confirm_password_lib.php';
                return eg_confirm_password_allowed($db, $uid) ? 1 : 0;
            } catch (Throwable $e) { return 0; }
        })(),
        // 職位清單（稽核報告表的通知對象設定＝部門 × 職位）
        'positions' => (function () use ($db) {
            try { return $db->query("SELECT id, name FROM position ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC); }
            catch (Throwable $e) { return []; }
        })(),
    ]);
}

/* ============================ 設定 ============================ */
case 'save_setting': {
    iaReqAdmin($perms);
    $k = (string)($_POST['key'] ?? '');
    if (!in_array($k, IA_SETTING_KEYS, true)) jerr('不支援的設定項目');
    $v = (string)($_POST['value'] ?? '');
    if ($k === 'ia_remind_days') {
        if ($v !== '' && (!ctype_digit($v) || (int)$v > 365)) jerr('提醒天數請填 0~365 的整數');
    }
    if ($k === 'ia_case_remark_tpl') {
        $v = str_replace("\r\n", "\n", $v);
        if (mb_strlen($v) > 2000) jerr('備註預設文字最多 2000 字（目前 ' . mb_strlen($v) . ' 字）');
    }
    if (in_array($k, ['ia_sign_approve', 'ia_sign_review'], true) && !array_key_exists($v, IA_SIGN_SOURCES)) {
        jerr('不支援的簽章來源');
    }
    if (in_array($k, ['ia_auto_sign', 'ia_auto_sign_case'], true) && !in_array($v, ['', '0', '1'], true)) jerr('自動簽核設定值不正確');
    if ($k === 'ia_stamp_tpl_id' && $v !== '') {
        $st = $db->prepare("SELECT 1 FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([(int)$v]);
        if (!$st->fetchColumn()) jerr('圖章模板不存在或已停用');
    }
    // 存不進去一定要讓使用者看到；回報成功卻沒存＝使用者會一直重存卻永遠是空的
    try { ia_setting_save($db, $k, $v, $uname); }
    catch (Throwable $e) { jerr('設定儲存失敗：' . $e->getMessage(), 500); }
    // 立刻讀回來確認真的寫進去了（寫入成功但值被資料庫改寫的情況也擋得住）
    $back = ia_settings($db);
    if (($back[$k] ?? null) !== $v) jerr('設定寫入後讀回的值不一致，請回報系統管理者', 500);
    /* 開啟「稽核通知單自動簽核」時，把**已經完成但還沒有章**的單一次補簽（2026-09-18 使用者要求）：
       「完成的時候還沒開，事後才開」的那些單不必一張張重開重按。
       已經有人簽過的一律不動（不可以覆蓋真人簽的章）。 */
    $extra = [];
    if ($k === 'ia_auto_sign_case' && $v === '1') {
        try { $extra['backfill'] = ia_case_autosign_backfill($db, $uid, $uname); }
        catch (Throwable $e) { $extra['backfill'] = ['filled' => 0, 'cases' => [], 'error' => $e->getMessage()]; }
    }
    jout(array_merge(['saved' => true, 'value' => $back[$k]], $extra));
}

case 'save_asdoc': {
    iaReqAdmin($perms);
    $key = (string)($_POST['key'] ?? '');
    if (!isset(IA_ASDOC_MODULES[$key])) jerr('不支援的表單代碼');
    $docId = (int)($_POST['doc_id'] ?? 0);
    if ($docId) {
        $st = $db->prepare("SELECT 1 FROM as_document WHERE id=?");
        $st->execute([$docId]);
        if (!$st->fetchColumn()) jerr('AS 文件不存在');
    }
    eg_asdoc_save($db, IA_ASDOC_MODULES[$key]['module'], $docId, $uname);
    jout(['saved' => true]);
}

/* ============================ 年度計劃表 2-GM-06-01 ============================ */
case 'plan_get': {
    iaReqView($perms);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    $plan = ia_plan_get($db, $year);
    if (!$plan) jout(['plan' => null, 'year' => $year]);
    // ★ cells／actual 一定要轉成物件再送出去：PHP 的**空**關聯陣列 json_encode 出來是 `[]`（JS 的陣列）
    //   不是 `{}`；前端在陣列上加字串鍵（'6-11'）之後，用 $.each 之類的走訪拿不到那些鍵，
    //   結果就是「點了格子、按了儲存，送出的卻是空清單」——存進去是空的而且完全不報錯
    //   （2026-08-25 使用者回報「很多儲存按鈕按了沒真的存」的根因之一）。
    //   轉型只在輸出這一步做，內部（dashboard 會 count()／foreach）仍是陣列。
    $plan['cells']  = (object)$plan['cells'];
    $plan['actual'] = (object)$plan['actual'];
    jout(['plan' => $plan, 'year' => $year]);
}

case 'plan_create': {
    iaReqAdmin($perms);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000 || $year > 2200) jerr('年度不正確');
    $st = $db->prepare("SELECT plan_id FROM ia_plan WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    if ($st->fetchColumn()) jerr('該年度的稽核計劃表已存在');

    $deptIds = json_decode((string)($_POST['dept_ids'] ?? '[]'), true);
    if (!is_array($deptIds) || !$deptIds) jerr('請至少選一個受稽單位');

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO ia_plan (year, status, created_by, created_by_name, created_at, updated_at,
                          maker_id, maker_name, maker_date)
                      VALUES (?, 'draft', ?, ?, NOW(), NOW(), ?, ?, ?)")
           ->execute([$year, $uid, $uname, $uid, $uname, $today]);
        $pid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO ia_plan_dept (plan_id, dept_id, dept_name, sort_order) VALUES (?,?,?,?)");
        $nameSt = $db->prepare("SELECT name FROM department WHERE id=?");
        $i = 0;
        foreach ($deptIds as $d) {
            $d = (int)$d; if (!$d) continue;
            $nameSt->execute([$d]);
            $ins->execute([$pid, $d, (string)($nameSt->fetchColumn() ?: ''), ++$i * 10]);
        }
        $db->commit();
        jout(['plan_id' => $pid]);
    } catch (Throwable $e) { $db->rollBack(); jerr('建立失敗：' . $e->getMessage(), 500); }
}

case 'plan_save_cells': {
    iaReqAdmin($perms);
    $pid = (int)($_POST['plan_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_plan WHERE plan_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$pid]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) jerr('找不到這張計劃表');
    if ($plan['status'] === 'approved' && !$perms['isAdmin']) jerr('已核准的計劃表不可修改，請洽系統管理者');

    $cells = json_decode((string)($_POST['cells'] ?? '[]'), true);
    if (!is_array($cells)) jerr('格式錯誤');
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM ia_plan_cell WHERE plan_id=?")->execute([$pid]);
        $ins = $db->prepare("INSERT INTO ia_plan_cell (plan_id, dept_id, month, planned, note) VALUES (?,?,?,1,?)");
        foreach ($cells as $c) {
            $d = (int)($c['dept_id'] ?? 0); $m = (int)($c['month'] ?? 0);
            if (!$d || $m < 1 || $m > 12) continue;
            $ins->execute([$pid, $d, $m, isset($c['note']) && $c['note'] !== '' ? mb_substr((string)$c['note'], 0, 200) : null]);
        }
        $db->prepare("UPDATE ia_plan SET remark=?, updated_at=NOW() WHERE plan_id=?")
           ->execute([mb_substr(trim((string)($_POST['remark'] ?? '')), 0, 500) ?: null, $pid]);
        // 製表人可事後修改（2026-09-14）——原本只有建檔當下寫得進去
        if (($mk = iaMakerFromPost($db, (string)($plan['maker_date'] ?? ''))) !== null) {
            $db->prepare("UPDATE ia_plan SET maker_id=?, maker_name=?, maker_date=? WHERE plan_id=?")
               ->execute([$mk['id'], $mk['name'], $mk['date'], $pid]);
        }
        $db->commit();
        jout(['saved' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'plan_set_depts': {
    iaReqAdmin($perms);
    $pid = (int)($_POST['plan_id'] ?? 0);
    $st = $db->prepare("SELECT plan_id, status FROM ia_plan WHERE plan_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$pid]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) jerr('找不到這張計劃表');
    $deptIds = json_decode((string)($_POST['dept_ids'] ?? '[]'), true);
    if (!is_array($deptIds) || !$deptIds) jerr('請至少選一個受稽單位');
    $keep = array_values(array_filter(array_map('intval', $deptIds)));
    $db->beginTransaction();
    try {
        $in = implode(',', array_fill(0, count($keep), '?'));
        // 移除的單位連同它的格子一起清掉，才不會留下看不到卻仍在算的資料
        $db->prepare("DELETE FROM ia_plan_dept WHERE plan_id=? AND dept_id NOT IN ($in)")
           ->execute(array_merge([$pid], $keep));
        $db->prepare("DELETE FROM ia_plan_cell WHERE plan_id=? AND dept_id NOT IN ($in)")
           ->execute(array_merge([$pid], $keep));
        $ins = $db->prepare("INSERT IGNORE INTO ia_plan_dept (plan_id, dept_id, dept_name, sort_order) VALUES (?,?,?,?)");
        $upd = $db->prepare("UPDATE ia_plan_dept SET sort_order=? WHERE plan_id=? AND dept_id=?");
        $nameSt = $db->prepare("SELECT name FROM department WHERE id=?");
        $i = 0;
        foreach ($keep as $d) {
            $i += 10;
            $nameSt->execute([$d]);
            $ins->execute([$pid, $d, (string)($nameSt->fetchColumn() ?: ''), $i]);
            $upd->execute([$i, $pid, $d]);
        }
        $db->prepare("UPDATE ia_plan SET updated_at=NOW() WHERE plan_id=?")->execute([$pid]);
        $db->commit();
        jout(['saved' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'plan_decide': {
    iaReqAdmin($perms);
    $pid = (int)($_POST['plan_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_plan WHERE plan_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$pid]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) jerr('找不到這張計劃表');
    $to = (string)($_POST['status'] ?? '');
    if (!in_array($to, ['draft', 'submitted', 'approved'], true)) jerr('狀態不正確');
    $d = iaDate($_POST['biz_date'] ?? '') ?: $today;
    /* 審查／核准欄寫進去的人＝「設定→列印簽章」設的那一格的人，不是按下按鈕的人
       （2026-09-16 使用者回報：畫面顯示的審查人跟設定好的不同——因為列印是依設定解析、
        畫面卻記操作者，兩邊本來就對不起來）。設定留白或解析不到人時才退回操作者。 */
    $ctx = ['leader_id' => 0, 'leader_name' => '',
            'maker_id' => (int)($plan['maker_id'] ?? 0), 'maker_name' => (string)($plan['maker_name'] ?? ''),
            'biz_date' => $d];
    $me  = ['id' => $uid, 'name' => $uname];
    if ($to === 'submitted') {
        $rv = ia_sign_slot_person($db, 'review', $ctx, $me);
        $db->prepare("UPDATE ia_plan SET status='submitted', submit_date=?, submitted_at=NOW(),
                          reviewer_id=?, reviewer_name=?, reviewer_date=?, updated_at=NOW() WHERE plan_id=?")
           ->execute([$d, $rv['id'] ?: null, $rv['name'] ?: null, $d, $pid]);
        /* 自動簽核（ai-rules/21）：管理員在設定裡開啟後，送審當下一併完成核准。
           業務日期＝送出日；精確時間戳以送出時間為基準往後隨機 5~180 分鐘、不跨日。 */
        if (ia_auto_sign_on($db)) {
            $q = $db->prepare("SELECT submitted_at FROM ia_plan WHERE plan_id=?"); $q->execute([$pid]);
            $subAt = (string)($q->fetchColumn() ?: date('Y-m-d H:i:s'));
            $ap = ia_sign_slot_person($db, 'approve', $ctx, $me);
            $db->prepare("UPDATE ia_plan SET status='approved', approved_date=?, approved_at=?,
                              approver_id=?, approver_name=?, approver_date=?, updated_at=NOW() WHERE plan_id=?")
               ->execute([$d, ia_auto_sign_at($subAt, $d), $ap['id'] ?: null, $ap['name'] ?: null, $d, $pid]);
            jout(['saved' => true, 'auto_signed' => true,
                  'reviewer' => $rv['name'], 'approver' => $ap['name']]);
        }
        jout(['saved' => true, 'reviewer' => $rv['name']]);
    } elseif ($to === 'approved') {
        $ap = ia_sign_slot_person($db, 'approve', $ctx, $me);
        $db->prepare("UPDATE ia_plan SET status='approved', approved_date=?, approved_at=NOW(),
                          approver_id=?, approver_name=?, approver_date=?, decide_note=?, updated_at=NOW() WHERE plan_id=?")
           ->execute([$d, $ap['id'] ?: null, $ap['name'] ?: null, $d,
                      mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null, $pid]);
        jout(['saved' => true, 'approver' => $ap['name']]);
    } else {
        $db->prepare("UPDATE ia_plan SET status='draft', updated_at=NOW() WHERE plan_id=?")->execute([$pid]);
    }
    jout(['saved' => true]);
}

case 'plan_delete': {
    iaReqAdmin($perms);
    $pid = (int)($_POST['plan_id'] ?? 0);
    $db->prepare("UPDATE ia_plan SET is_deleted=1, updated_at=NOW() WHERE plan_id=?")->execute([$pid]);
    jout(['deleted' => true]);
}

/* ============================ 稽核通知單 2-GM-06-02 ============================ */
case 'case_list': {
    iaReqView($perms);
    $w = ['COALESCE(c.is_deleted,0)=0']; $p = [];
    $year = iaInt($_GET['year'] ?? '');
    if ($year) { $w[] = 'c.year=?'; $p[] = $year; }
    $status = (string)($_GET['status'] ?? '');
    if ($status !== '') { $w[] = 'c.status=?'; $p[] = $status; }
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = "(c.case_no LIKE ? OR c.leader_name LIKE ? OR c.remark LIKE ? OR c.end_meet_place LIKE ?
                     OR EXISTS (SELECT 1 FROM ia_case_dept x WHERE x.case_id=c.case_id
                        AND (x.dept_name LIKE ? OR x.auditor_name LIKE ? OR x.escort_name LIKE ? OR x.start_process LIKE ?))
                     OR EXISTS (SELECT 1 FROM ia_case_dept_person pr WHERE pr.case_id=c.case_id AND pr.user_name LIKE ?))";
            for ($i = 0; $i < 9; $i++) $p[] = '%' . $k . '%';
        }
    }
    $sql = "SELECT c.*,
                   (SELECT GROUP_CONCAT(x.dept_name ORDER BY x.sort_order SEPARATOR '、')
                      FROM ia_case_dept x WHERE x.case_id=c.case_id) AS dept_list,
                   (SELECT COUNT(*) FROM ia_nc n WHERE n.case_id=c.case_id AND COALESCE(n.is_deleted,0)=0) AS nc_cnt,
                   (SELECT COUNT(*) FROM ia_check k WHERE k.case_id=c.case_id AND COALESCE(k.is_deleted,0)=0) AS check_cnt
              FROM ia_case c WHERE " . implode(' AND ', $w) . " ORDER BY c.year DESC, c.seq_no DESC, c.case_id DESC";
    $st = $db->prepare($sql); $st->execute($p);
    jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'case_get': {
    iaReqView($perms);
    $cid = (int)($_GET['case_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到這張稽核通知單', 404);
    $st = $db->prepare("SELECT * FROM ia_case_dept WHERE case_id=? ORDER BY sort_order, cd_id");
    $st->execute([$cid]);
    $c['depts'] = $st->fetchAll(PDO::FETCH_ASSOC);
    // 稽核員／陪檢員可多位，人員一律由 ia_case_dept_person 帶出（舊資料會自動回退）
    $pmap = ia_cd_people_map($db, array_map(function ($d) { return (int)$d['cd_id']; }, $c['depts']), $c['depts']);
    foreach ($c['depts'] as &$_d) {
        $pp = $pmap[(int)$_d['cd_id']] ?? ['auditor' => [], 'escort' => []];
        $_d['auditors']     = $pp['auditor'];
        $_d['escorts']      = $pp['escort'];
        $_d['auditor_name'] = ia_cd_names($pp['auditor']);
        $_d['escort_name']  = ia_cd_names($pp['escort']);
    }
    unset($_d);
    $st = $db->prepare("SELECT check_id, kind, half, title, auditor_name, check_date, status
                          FROM ia_check WHERE case_id=? AND COALESCE(is_deleted,0)=0 ORDER BY check_id");
    $st->execute([$cid]);
    $c['checks'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT nc_id, nc_no, dept_name, nc_type, stage, due_date, fact
                          FROM ia_nc WHERE case_id=? AND COALESCE(is_deleted,0)=0 ORDER BY nc_no");
    $st->execute([$cid]);
    $c['ncs'] = $st->fetchAll(PDO::FETCH_ASSOC);
    // 會議紀錄狀態（不重複建立，只顯示連結）
    foreach ([['pre_meeting_id', 'pre'], ['end_meeting_id', 'end']] as $mm) {
        $mid = (int)($c[$mm[0]] ?? 0);
        $c[$mm[1] . '_meeting'] = null;
        if ($mid) {
            try {
                $q = $db->prepare("SELECT meeting_id, subject, meeting_date, status FROM meeting_record WHERE meeting_id=?");
                $q->execute([$mid]);
                $c[$mm[1] . '_meeting'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
        }
    }
    jout(['row' => $c]);
}

case 'case_save': {
    iaReqAdmin($perms);
    $cid  = (int)($_POST['case_id'] ?? 0);
    $nd   = iaDate($_POST['notify_date'] ?? '');
    if (!$nd) jerr('請填通知日期');
    $af = iaDate($_POST['audit_from'] ?? '');
    $at = iaDate($_POST['audit_to'] ?? '');
    if ($af && $at && $at < $af) jerr('稽核結束日期不可早於開始日期');
    $ems = iaTime($_POST['end_meet_start'] ?? '');
    $eme = iaTime($_POST['end_meet_end'] ?? '');
    if ($ems && $eme && $eme < $ems) jerr('結束會議的結束時間不可早於開始時間');

    /* 已完成的通知單一律不可修改（2026-09-18 使用者要求）：前端會把欄位鎖起來，
       這裡同規則再擋一次（鐵律8）。要改請先由內稽管理員輸入操作確認密碼取消完成。 */
    if ($cid) {
        $q0 = $db->prepare("SELECT status FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
        $q0->execute([$cid]);
        $st0 = (string)($q0->fetchColumn() ?: '');
        if (in_array($st0, ['issued', 'executing', 'closed'], true)) {
            jerr('這張通知單已經完成，不可修改。請先由內稽管理員按「取消完成」（需輸入操作確認密碼）。');
        }
    }

    /* 製表日期不可晚於通知日期（2026-09-18 使用者要求；簽章與製表印在同一張紙上，規則一致）。
       前端把欄位的 max 設成通知日期，這裡同規則再擋一次（鐵律8）。 */
    $mkd = iaDate($_POST['maker_date'] ?? '');
    if ($mkd && $mkd > $nd) jerr('製表日期不可晚於通知日期（' . $nd . '）');

    $year   = (int)substr($nd, 0, 4);
    /* 這張單的業務日期＝稽核起日（沒填就退回通知日期）。人員的在職狀態、部門職稱與資格任期
       一律以它為準（ai-rules/22）——否則補 2025 年的歷史單據時，當時在職現已離職的人一律
       解析不到，畫面上明明選得到、按存檔卻被擋下來。前端也是用同一個日期取清單（鐵律8 同規則）。 */
    $caseAsof = $af ?: $nd;
    // 稽核組長／稽核員／陪檢員都是挑「職務」（uid:deptId:posId），資格認到人員＋部門＋職稱。
    // 後端一定要再驗一次資格，不能只擋前端下拉（鐵律8）。
    $leader = null; $leaderName = null; $leaderDept = null; $leaderPos = null;
    $leaderKey = trim((string)($_POST['leader_key'] ?? ''));
    if ($leaderKey !== '') {
        $lp = ia_resolve_post($db, $leaderKey, 'auditor', $caseAsof);
        if (!$lp) jerr('稽核組長的職務不存在或沒有稽核員資格');
        $leader = $lp['user_id']; $leaderName = $lp['user_name'];
        $leaderDept = $lp['dept_id']; $leaderPos = $lp['position_id'];
    } elseif (($lid = iaInt($_POST['leader_id'] ?? '')) !== null) {
        // 舊版前端／既有資料只送 user_id 時仍收得下
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$lid]);
        $leaderName = (string)($q->fetchColumn() ?: '');
        if ($leaderName === '') jerr('稽核組長不存在');
        $leader = $lid;
    }
    $depts = json_decode((string)($_POST['depts'] ?? '[]'), true);
    if (!is_array($depts)) $depts = [];

    // 使用者指定的規則，前端擋一次、後端同規則再擋一次（鐵律8）
    // 2026-08-27 起稽核員／陪檢員都可以多位，驗證改成對整份名單做。
    $seenProc = [];
    foreach ($depts as $ri => $d) {
        $proc = trim((string)($d['start_process'] ?? ''));
        $aks  = iaRowPostKeys($d, 'auditor');
        $eks  = iaRowPostKeys($d, 'escort');
        // 整列全空的（末列常常是按 ↓ 加出來還沒填的）不參與驗證
        $any = ($aks || $eks);
        if (!$any) foreach ((array)$d as $v) { if (!is_array($v) && trim((string)$v) !== '') { $any = true; break; } }
        if (!$any) continue;

        // ①同一張通知單裡，相同的稽核起始主過程不可重複
        if ($proc !== '') {
            $k = mb_strtolower($proc);
            if (isset($seenProc[$k])) {
                jerr('「' . $proc . '」在同一次稽核裡重複了（第 ' . ($seenProc[$k] + 1) . ' 列與第 ' . ($ri + 1) . ' 列）');
            }
            $seenProc[$k] = $ri;
        }
        // ②每一種名單裡不可有重複的人（同一人的不同職務也算重複）
        foreach ([['auditor', $aks, '稽核員'], ['escort', $eks, '陪檢員']] as $kk) {
            if (count($kk[1]) > IA_CD_PERSON_MAX) {
                jerr('第 ' . ($ri + 1) . ' 列的' . $kk[2] . '最多 ' . IA_CD_PERSON_MAX . ' 位');
            }
            $seenU = [];
            foreach ($kk[1] as $key) {
                list($u) = ia_post_parse($key);
                if (!$u) continue;
                if (isset($seenU[$u])) jerr('第 ' . ($ri + 1) . ' 列的' . $kk[2] . '有同一個人被選了兩次');
                $seenU[$u] = 1;
            }
        }
        // ③陪檢員不可與稽核員是同一個人（不同職務也不行，因為是同一個人）
        $au = [];
        foreach ($aks as $key) { list($u) = ia_post_parse($key); if ($u) $au[$u] = 1; }
        foreach ($eks as $key) {
            list($u) = ia_post_parse($key);
            if ($u && isset($au[$u])) jerr('第 ' . ($ri + 1) . ' 列的陪檢員不可與稽核員是同一個人');
        }
        /* ④**受稽日期必須落在稽核期間內**（2026-09-18 使用者指正：這要在存檔當下就擋，
           不可以讓它存進去、事後才在別的畫面提醒）。
           實際發生過：某張單的稽核期間是 12/03，五個受稽單位卻都填 12/04，
           於是「建立查檢表」抓到的日期與通知單對不起來。 */
        $ad = iaDate($d['audited_date'] ?? '');
        if ($ad && $af) {
            $end = $at ?: $af;
            if ($ad < $af || $ad > $end) {
                jerr('第 ' . ($ri + 1) . ' 列的受稽日期 ' . $ad . ' 不在稽核期間內（'
                     . $af . ($end !== $af ? (' ～ ' . $end) : '') . '）。'
                     . '請改受稽日期，或把上方的稽核期間調整成涵蓋這一天。');
            }
        }
    }

    $db->beginTransaction();
    try {
        if ($cid) {
            $q = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
            $q->execute([$cid]);
            $old = $q->fetch(PDO::FETCH_ASSOC);
            if (!$old) { $db->rollBack(); jerr('找不到這張稽核通知單', 404); }
            $db->prepare("UPDATE ia_case SET year=?, notify_date=?, audit_from=?, audit_to=?,
                              leader_id=?, leader_name=?, leader_dept_id=?, leader_position_id=?,
                              end_meet_date=?, end_meet_start=?, end_meet_end=?,
                              end_meet_place=?, remark=?, updated_at=NOW() WHERE case_id=?")
               ->execute([$year, $nd, $af, $at, $leader, $leaderName ?: null, $leaderDept, $leaderPos,
                          iaDate($_POST['end_meet_date'] ?? ''), $ems, $eme,
                          mb_substr(trim((string)($_POST['end_meet_place'] ?? '')), 0, 150) ?: null,
                          trim((string)($_POST['remark'] ?? '')) ?: null, $cid]);
            // 製表人可事後修改（2026-09-14）
            if (($mk = iaMakerFromPost($db, (string)($old['maker_date'] ?? ''))) !== null) {
                $db->prepare("UPDATE ia_case SET maker_id=?, maker_name=?, maker_date=? WHERE case_id=?")
                   ->execute([$mk['id'], $mk['name'], $mk['date'], $cid]);
            }
        } else {
            $q = $db->prepare("SELECT COALESCE(MAX(seq_no),0)+1 FROM ia_case WHERE year=? AND COALESCE(is_deleted,0)=0");
            $q->execute([$year]);
            $seq = (int)$q->fetchColumn();
            // 件號依「稽核日期（稽核起）」產生，沒填才退回通知日期（2026-09-14 使用者拍板，
            // 與 2024 兩張紙本 241115001／241216001 的編法一致）
            $caseNo = ia_next_case_no($db, $af ?: $nd);
            // 製表人：前端有指定就用指定的，沒指定＝建檔者本人（原本的行為）
            $mkNew = iaMakerFromPost($db, $nd) ?: ['id' => $uid, 'name' => $uname, 'date' => $nd];
            if ($mkNew['id'] === null) $mkNew['date'] = null;
            $db->prepare("INSERT INTO ia_case (year, seq_no, case_no, notify_date, audit_from, audit_to,
                              leader_id, leader_name, leader_dept_id, leader_position_id,
                              end_meet_date, end_meet_start, end_meet_end, end_meet_place,
                              remark, status, maker_id, maker_name, maker_date, created_by, created_by_name,
                              created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'draft', ?,?,?,?,?, NOW(), NOW())")
               ->execute([$year, $seq, $caseNo, $nd, $af, $at, $leader, $leaderName ?: null, $leaderDept, $leaderPos,
                          iaDate($_POST['end_meet_date'] ?? ''), $ems, $eme,
                          mb_substr(trim((string)($_POST['end_meet_place'] ?? '')), 0, 150) ?: null,
                          trim((string)($_POST['remark'] ?? '')) ?: null,
                          $mkNew['id'], $mkNew['name'], $mkNew['date'], $uid, $uname]);
            $cid = (int)$db->lastInsertId();
        }

        // 這張通知單原本已經掛著的人：離職者（例：2024 的稽核員林國棟）不會出現在合格職務清單裡，
        // 但補歷史紀錄時只是改個地點就被擋住存不了檔，所以「原本就在上面的人」一律放行。
        $wasOn = [];
        try {
            $q = $db->prepare("SELECT kind, user_id FROM ia_case_dept_person WHERE case_id=? AND user_id IS NOT NULL");
            $q->execute([$cid]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $wasOn[$r['kind'] . '-' . (int)$r['user_id']] = 1;
            $q = $db->prepare("SELECT auditor_id, escort_id FROM ia_case_dept WHERE case_id=?");
            $q->execute([$cid]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int)$r['auditor_id']) $wasOn['auditor-' . (int)$r['auditor_id']] = 1;
                if ((int)$r['escort_id'])  $wasOn['escort-'  . (int)$r['escort_id']]  = 1;
            }
        } catch (Throwable $e) {}

        $db->prepare("DELETE FROM ia_case_dept_person WHERE case_id=?")->execute([$cid]);
        $db->prepare("DELETE FROM ia_case_dept WHERE case_id=?")->execute([$cid]);
        $ins = $db->prepare("INSERT INTO ia_case_dept (case_id, sort_order, start_process, dept_id, dept_name,
                                 audited_date, audited_time, improve_due)
                             VALUES (?,?,?,?,?,?,?,?)");
        $nameSt = $db->prepare("SELECT name FROM department WHERE id=?");
        $userSt = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
        $i = 0;
        foreach ($depts as $d) {
            $did = iaInt($d['dept_id'] ?? '');
            $dn  = trim((string)($d['dept_name'] ?? ''));
            if ($did) { $nameSt->execute([$did]); $dn = (string)($nameSt->fetchColumn() ?: $dn); }
            if (!$did && $dn === '' && trim((string)($d['start_process'] ?? '')) === '') continue;
            $i++;
            // 稽核員／陪檢員挑的是職務且可多位，後端逐一再驗一次資格（鐵律8）。
            // 只送單一 auditor_key／auditor_id 的舊呼叫端（與既有資料）仍收得下。
            $people = [];
            foreach ([['auditor', '稽核員'], ['escort', '陪檢員']] as $kk) {
                list($kind, $label) = $kk;
                $list = [];
                foreach (iaRowPostKeys($d, $kind) as $key) {
                    $rp = ia_resolve_post($db, $key, $kind, $caseAsof);
                    if (!$rp) {
                        list($ku, $kd, $kp) = ia_post_parse($key);
                        if (!$ku || !isset($wasOn[$kind . '-' . $ku])) {
                            jerr('第 ' . $i . ' 列的' . $label . '沒有該職務的' . $label . '資格');
                        }
                        $userSt->execute([$ku]);
                        $kn = (string)($userSt->fetchColumn() ?: '');
                        if ($kn === '') jerr('第 ' . $i . ' 列的' . $label . '不存在');
                        $rp = ['user_id' => $ku, 'user_name' => $kn,
                               'dept_id' => $kd ?: null, 'position_id' => $kp ?: null];
                    }
                    $list[] = ['user_id' => $rp['user_id'], 'user_name' => $rp['user_name'],
                               'dept_id' => $rp['dept_id'], 'position_id' => $rp['position_id']];
                }
                if (!$list && ($legacy = iaInt($d[$kind . '_id'] ?? '')) !== null) {
                    $userSt->execute([$legacy]);
                    $ln = (string)($userSt->fetchColumn() ?: '');
                    if ($ln !== '') $list[] = ['user_id' => $legacy, 'user_name' => $ln,
                                               'dept_id' => null, 'position_id' => null];
                }
                $people[$kind] = $list;
            }
            $ins->execute([$cid, $i * 10, mb_substr(trim((string)($d['start_process'] ?? '')), 0, 150) ?: null,
                           $did, $dn ?: null,
                           iaDate($d['audited_date'] ?? ''), iaTime($d['audited_time'] ?? ''),
                           iaDate($d['improve_due'] ?? '')]);
            $cdId = (int)$db->lastInsertId();
            // 人員的唯一寫入點（順便同步 ia_case_dept 上的顯示快取）
            ia_cd_people_set($db, $cdId, $cid, 'auditor', $people['auditor']);
            ia_cd_people_set($db, $cdId, $cid, 'escort',  $people['escort']);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }

    // 稽核日期被改過的話件號要跟著重編（只重編還是草稿且未執行的；已發出的紙本印著舊號不動）
    $sync = ia_case_sync_no($db, $cid);
    jout(['case_id' => $cid, 'case_no' => $sync['new'], 'no_changed' => $sync['changed'], 'no_old' => $sync['old']]);
}

/* 完成稽核通知單（2026-09-18 使用者要求）：填完按一次，之後不可修改；
   **完成之後才會送審核**——開了自動簽核就在這一刻把核准／審查兩格簽完。 */
case 'case_complete': {
    iaReqAdmin($perms);
    $cid = (int)($_POST['case_id'] ?? 0);
    $sd  = iaDate($_POST['sign_date'] ?? '') ?: '';   // 簽章／製表日期，不可晚於通知日期（lib 內再驗一次）
    try { $r = ia_case_complete($db, $cid, $uid, $uname, $sd); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout($r);
}

/* 取消完成（改回草稿）：**只有內稽管理員、而且要輸入操作確認密碼**（使用者指定）。
   自動簽核寫進去的章會一併清掉——單據要回去改，那兩個章就不成立了。 */
case 'case_reopen': {
    iaReqAdmin($perms);
    require_once $document_root . '/EGsystem/src/common/confirm_password_lib.php';
    $chk = eg_confirm_password_verify_scoped($db, $uid, (string)($_POST['password'] ?? ''), 'ia_case_reopen');
    if (empty($chk['ok'])) jerr($chk['msg'] ?: '操作確認密碼不正確', 403);
    $cid = (int)($_POST['case_id'] ?? 0);
    try { $r = ia_case_reopen($db, $cid, $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout($r);
}

case 'case_status': {
    iaReqAdmin($perms);
    $cid = (int)($_POST['case_id'] ?? 0);
    $to  = (string)($_POST['status'] ?? '');
    if (!in_array($to, ['draft', 'issued', 'executing', 'closed'], true)) jerr('狀態不正確');
    $q = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $q->execute([$cid]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到這張稽核通知單', 404);
    // 「實際實施」＝執行中或已結案；年度計劃表的◎就是看這個旗標
    $executed = in_array($to, ['executing', 'closed'], true) ? 1 : 0;
    $edate = $executed ? (iaDate($_POST['executed_date'] ?? '') ?: ($c['audit_from'] ?: $c['notify_date'])) : null;
    $db->prepare("UPDATE ia_case SET status=?, executed=?, executed_date=?, updated_at=NOW() WHERE case_id=?")
       ->execute([$to, $executed, $edate, $cid]);
    jout(['saved' => true]);
}

case 'case_delete': {
    // 管理員可刪「已建立但尚未結案」的通知單（2026-09-11 使用者交辦）。
    // 前端擋一次、這裡同規則再擋一次（鐵律8）：已結案的一律不給刪，
    // 底下還有不符合通知單的也不給刪（刪了那些 IA 單會變孤兒、仍留在清單與稽核報告表裡）。
    iaReqAdmin($perms);
    $cid = (int)($_POST['case_id'] ?? 0);
    $q = $db->prepare("SELECT case_id, case_no, year, seq_no, status FROM ia_case
                        WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $q->execute([$cid]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到這張稽核通知單（可能已被刪除，請重新整理清單）', 404);
    if ($c['status'] === 'closed') jerr('這張通知單已結案，不可刪除。要刪請先把狀態改回「執行中」。');

    $q = $db->prepare("SELECT COUNT(*) FROM ia_nc WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $q->execute([$cid]);
    $ncCnt = (int)$q->fetchColumn();
    if ($ncCnt > 0) jerr('這張通知單底下還有 ' . $ncCnt . ' 張不符合通知單，請先到「不符合通知單」分頁處理或刪除。');

    $q = $db->prepare("SELECT COUNT(*) FROM ia_check WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $q->execute([$cid]);
    $chkCnt = (int)$q->fetchColumn();

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE ia_case SET is_deleted=1, updated_at=NOW() WHERE case_id=?")->execute([$cid]);
        $db->prepare("UPDATE ia_check SET is_deleted=1 WHERE case_id=?")->execute([$cid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('刪除失敗：' . $e->getMessage(), 500);
    }

    // 刪除是不可逆的管理動作（雖然是軟刪除），留一筆稽核軌跡
    try {
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES ('delete','ia_case',?,?,?,?,?,NOW())")
           ->execute([(string)$cid,
                      (string)($c['case_no'] ?: ($c['year'] . ' 第' . $c['seq_no'] . '次')),
                      json_encode(['status' => $c['status'], 'checks_deleted' => $chkCnt], JSON_UNESCAPED_UNICODE),
                      $uid, $uname]);
    } catch (Throwable $e) {}

    jout(['deleted' => true, 'check_cnt' => $chkCnt]);
}

/* ============================ 查檢表（AS／系統／績效） ============================ */
case 'check_bank': {
    // 建立查檢表前先看題庫，讓使用者勾要查哪幾項
    iaReqAudit($perms);
    $kind = (string)($_GET['kind'] ?? '');
    if (!isset(IA_CHECK_KINDS[$kind])) jerr('查檢表種類不正確');
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    if ($kind === 'as') {
        // 條文原文看不出實務上在查什麼，故一併帶出作業項目（品管檢測／外包加工…）供挑選與快速勾選
        $rows = ia_as_clauses($db);
        $map  = ia_clause_task_map($db, array_column($rows, 'clause_id'));
        foreach ($rows as &$r) { $r['tasks'] = $map[(int)$r['clause_id']] ?? []; }
        unset($r);
        // 作業項目標籤要分類（2026-09-14）：依 AS 文件編號第二段的部門代碼歸到部門底下
        jout(['kind' => $kind, 'rows' => $rows, 'dept_codes' => ia_as_dept_code_names($db)]);
    }
    elseif ($kind === 'system') {
        // 2026-09-15 使用者要求：要能「從部門挑到我這次要稽核的表單」，
        // 所以每一份表單一併帶出它的部門（編號的部門代碼推導）與對應的品質管理系統要求。
        jout(['kind' => $kind, 'rows' => ia_system_forms_full($db), 'dept_codes' => ia_as_dept_code_names($db)]);
    }
    else {
        // 績效執行稽核查檢表稽核的是「去年整年度」，且達成／沒達成與受稽人（擔當者）全自動帶
        $ay = ia_kpi_audit_year((string)($_GET['check_date'] ?? ''));
        jout(['kind' => $kind, 'rows' => ia_kpi_audit_rows($db, $ay), 'audit_year' => $ay]);
    }
}

case 'check_list': {
    iaReqView($perms);
    $w = ['COALESCE(k.is_deleted,0)=0']; $p = [];
    $year = iaInt($_GET['year'] ?? '');
    if ($year) { $w[] = 'k.year=?'; $p[] = $year; }
    $kind = (string)($_GET['kind'] ?? '');
    if ($kind !== '' && isset(IA_CHECK_KINDS[$kind])) { $w[] = 'k.kind=?'; $p[] = $kind; }
    $cid = iaInt($_GET['case_id'] ?? '');
    if ($cid) { $w[] = 'k.case_id=?'; $p[] = $cid; }
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = "(k.title LIKE ? OR k.auditor_name LIKE ?
                     OR EXISTS (SELECT 1 FROM ia_check_item x WHERE x.check_id=k.check_id
                        AND (x.col_a LIKE ? OR x.col_b LIKE ? OR x.col_c LIKE ? OR x.evidence LIKE ? OR x.remark LIKE ?)))";
            for ($i = 0; $i < 7; $i++) $p[] = '%' . $k . '%';
        }
    }
    $sql = "SELECT k.*, c.case_no,
                   (SELECT COUNT(*) FROM ia_check_item i WHERE i.check_id=k.check_id AND i.is_header=0) AS item_cnt,
                   (SELECT COUNT(*) FROM ia_check_item i WHERE i.check_id=k.check_id AND i.result='ng')    AS ng_cnt,
                   (SELECT COUNT(*) FROM ia_check_item i WHERE i.check_id=k.check_id AND i.is_header=0
                                                           AND (i.result IS NULL OR i.result='')) AS todo_cnt
              FROM ia_check k LEFT JOIN ia_case c ON c.case_id=k.case_id
             WHERE " . implode(' AND ', $w) . " ORDER BY k.year DESC, k.check_date DESC, k.check_id DESC";
    $st = $db->prepare($sql); $st->execute($p);
    jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'check_create': {
    iaReqAudit($perms);
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(IA_CHECK_KINDS[$kind])) jerr('查檢表種類不正確');
    $cd = iaDate($_POST['check_date'] ?? '');
    if (!$cd) jerr('請填稽核日期');
    $year = (int)substr($cd, 0, 4);
    // 績效執行稽核查檢表稽核的是**去年整年度**（2026 年建立＝稽核 2025），所以不分上／下半年
    // （2026-09-15 使用者拍板，取代原本必選 H1/H2 的作法）。
    // 注意 ia_check.year 仍然是「這張表自己的年度＝建立年」——清單、年度篩選、稽核報告表都吃它；
    // 「要稽核哪一年的 KPI」只用在抓題庫，由 check_date 即時推導（存兩份遲早對不起來）。
    $half = '';
    $bankYear = ($kind === 'kpi') ? ia_kpi_audit_year($cd) : $year;
    $caseId = iaInt($_POST['case_id'] ?? '');
    if ($caseId) {
        $q = $db->prepare("SELECT 1 FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
        $q->execute([$caseId]);
        if (!$q->fetchColumn()) jerr('稽核案件不存在');
    }
    // 注意：ia_check_build_items() 把「空的 pick」當成不篩選＝整份題庫全帶。
    // 所以空陣列一定要在這裡先擋掉，否則直打 API 送 pick=[] 會安靜地建出一張 71 題的全表（前端擋得住、後端也要擋＝鐵律8）。
    $pick = json_decode((string)($_POST['pick'] ?? '[]'), true);
    $pick = is_array($pick) ? array_values(array_filter(array_map('intval', $pick))) : [];
    if (!$pick) jerr('請至少勾選一個要查核的項目');
    $items = ia_check_build_items($db, $kind, $bankYear, $pick);
    // 只勾到章節標題列也算沒勾（會建出一張只有標題沒有題目的空表）
    $real = 0; foreach ($items as $it) { if (!$it['is_header']) $real++; }
    if ($real === 0) jerr('請至少勾選一個要查核的項目（目前只勾到章節標題列）');

    // 稽核人挑的是職務（uid:deptId:posId），後端再驗一次稽核員資格
    $auditorKey = trim((string)($_POST['auditor_key'] ?? ''));
    $auditorDept = null; $auditorPos = null;
    if ($auditorKey !== '') {
        $ap = ia_resolve_post($db, $auditorKey, 'auditor', $cd);
        if (!$ap) jerr('稽核人沒有該職務的稽核員資格');
        $auditorId = $ap['user_id']; $auditorName = $ap['user_name'];
        $auditorDept = $ap['dept_id']; $auditorPos = $ap['position_id'];
    } else {
        $auditorId = iaInt($_POST['auditor_id'] ?? '') ?: $uid;
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$auditorId]);
        $auditorName = (string)($q->fetchColumn() ?: $uname);
    }

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO ia_check (case_id, year, kind, half, title, auditor_id, auditor_name,
                          auditor_dept_id, auditor_position_id,
                          check_date, status, created_by, created_by_name, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?, 'draft', ?,?, NOW(), NOW())")
           ->execute([$caseId, $year, $kind, $kind === 'kpi' ? $half : null,
                      mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150) ?: null,
                      $auditorId, $auditorName, $auditorDept, $auditorPos, $cd, $uid, $uname]);
        $kid = (int)$db->lastInsertId();
        // result／evidence：績效查檢表建立當下就由 KPI 資料自動判定好（2026-09-15），
        // 其他兩種維持空白由稽核員填。
        $ins = $db->prepare("INSERT INTO ia_check_item (check_id, sort_order, is_header, col_a, col_b, col_c, col_d,
                                 ref_kind, ref_id, result, evidence) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $ins->execute([$kid, $it['sort_order'], $it['is_header'], $it['col_a'], $it['col_b'],
                           $it['col_c'], $it['col_d'], $it['ref_kind'], $it['ref_id'],
                           ($it['result'] ?? '') ?: null, ($it['evidence'] ?? '') ?: null]);
        }
        // AS稽核查檢表可以直接沿用「系統稽核紀錄表」的結果自動判定（使用者 2026-09-15 交辦）
        $srcId = iaInt($_POST['src_check_id'] ?? '');
        $applied = null;
        if ($kind === 'as' && $srcId) {
            $q = $db->prepare("SELECT kind FROM ia_check WHERE check_id=? AND COALESCE(is_deleted,0)=0");
            $q->execute([$srcId]);
            if ((string)$q->fetchColumn() !== 'system') jerr('來源必須是系統稽核紀錄表');
            $applied = ia_as_apply_system_result($db, $kid, $srcId);
        }
        $db->commit();
        jout(['check_id' => $kid, 'items' => count($items), 'applied' => $applied]);
    } catch (Throwable $e) { $db->rollBack(); jerr('建立失敗：' . $e->getMessage(), 500); }
}

case 'check_get': {
    iaReqView($perms);
    $kid = (int)($_GET['check_id'] ?? 0);
    $st = $db->prepare("SELECT k.*, c.case_no FROM ia_check k LEFT JOIN ia_case c ON c.case_id=k.case_id
                         WHERE k.check_id=? AND COALESCE(k.is_deleted,0)=0");
    $st->execute([$kid]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) jerr('找不到這張查檢表', 404);
    $st = $db->prepare("SELECT i.*, n.nc_no FROM ia_check_item i LEFT JOIN ia_nc n ON n.nc_id=i.nc_id
                         WHERE i.check_id=? ORDER BY i.sort_order, i.item_id");
    $st->execute([$kid]);
    $k['items'] = $st->fetchAll(PDO::FETCH_ASSOC);
    // AS 查檢表：每一列補上該條文目前的作業項目（即時查，不隨查檢表存一份快照＝鐵律4，
    // 文件的作業項目改了，舊表打開也會跟著是最新的說法）
    if ($k['kind'] === 'as') {
        $cids = [];
        foreach ($k['items'] as $it) if (($it['ref_kind'] ?? '') === 'as_clause') $cids[] = (int)$it['ref_id'];
        $map = $cids ? ia_clause_task_map($db, $cids) : [];
        foreach ($k['items'] as &$it) {
            $it['tasks'] = (($it['ref_kind'] ?? '') === 'as_clause') ? ($map[(int)$it['ref_id']] ?? []) : [];
        }
        unset($it);
    }
    // 系統稽核紀錄表：每一列補上「這份表單對應到哪幾條品質管理系統要求」（即時查條文題庫＝鐵律4）。
    // 開不符合通知單時的「違反條文」就是從這裡帶的（2026-09-15 使用者交辦）。
    if ($k['kind'] === 'system') {
        $cmap  = ia_clause_map_by_doc_no($db);
        $codes = ia_as_dept_code_names($db);
        foreach ($k['items'] as &$it) {
            $cl = $cmap[trim((string)$it['col_a'])] ?? [];
            $it['clauses'] = $cl;
            $it['clause_ref'] = ia_clause_ref_text($cl);
            $code = ia_doc_dept_code((string)$it['col_a']);
            $it['dept_name'] = $codes[$code] ?? '';   // 開不符合通知單時的受稽核單位預設值
        }
        unset($it);

        /* 受稽人（2026-09-18 使用者要求）：預設＝**這份文件所屬部門的陪檢員**，
           而且要能改成別的部門的陪檢員，清單上一律顯示「部門　職稱　姓名（兼任）」。
           候選一律取自這張通知單的受稽單位列（陪檢員本來就是那邊指定的），
           另外把每個受稽單位的「預定完成改善」帶出來，開 IA 單時直接當要求完成期限。 */
        $escorts = []; $dueByDept = []; $deptIdByName = [];
        if ((int)($k['case_id'] ?? 0)) {
            $q = $db->prepare("SELECT * FROM ia_case_dept WHERE case_id=? ORDER BY sort_order");
            $q->execute([(int)$k['case_id']]);
            $cdRows = $q->fetchAll(PDO::FETCH_ASSOC);
            $pmap = ia_cd_people_map($db, array_map(function ($r) { return (int)$r['cd_id']; }, $cdRows), $cdRows);
            foreach ($cdRows as $r) {
                $dn = (string)($r['dept_name'] ?? '');
                if ($dn !== '') {
                    $deptIdByName[$dn] = (int)($r['dept_id'] ?? 0);
                    if (!empty($r['improve_due'])) $dueByDept[$dn] = (string)$r['improve_due'];
                }
                foreach (($pmap[(int)$r['cd_id']]['escort'] ?? []) as $p) {
                    $escorts[] = [
                        'user_id'       => (int)$p['user_id'],
                        'user_name'     => (string)$p['user_name'],
                        'dept_id'       => (int)($p['dept_id'] ?? 0),
                        'dept_name'     => (string)($p['dept_name'] ?? ''),
                        'position_id'   => (int)($p['position_id'] ?? 0),
                        'position_name' => (string)($p['position_name'] ?? ''),
                        'is_main'       => (int)($p['is_main'] ?? 1),
                        'unit_name'     => $dn,          // 他是哪一個受稽單位的陪檢員
                    ];
                }
            }
        }
        $k['escorts']      = $escorts;
        $k['due_by_dept']  = $dueByDept;
        $k['dept_id_by_name'] = $deptIdByName;
    }
    // 績效查檢表：已經轉成異常矯正處理單的列要顯示單號（備註欄就是印這個）
    if ($k['kind'] === 'kpi') {
        $ids = [];
        foreach ($k['items'] as $it) if (!empty($it['car_id'])) $ids[] = (int)$it['car_id'];
        $map = [];
        if ($ids) {
            try {
                foreach ($db->query("SELECT id, car_no, status FROM car_order WHERE id IN (" . implode(',', $ids) . ")")
                            ->fetchAll(PDO::FETCH_ASSOC) as $c) $map[(int)$c['id']] = $c;
            } catch (Throwable $e) {}
        }
        foreach ($k['items'] as &$it) {
            $c = $map[(int)($it['car_id'] ?? 0)] ?? null;
            $it['car_no'] = $c['car_no'] ?? null;
        }
        unset($it);
        $k['audit_year'] = ia_kpi_audit_year((string)$k['check_date']);
    }
    // 標題自動化（2026-09-18 使用者要求：系統稽核紀錄表不另外取標題，用對應通知單的日期與次別）
    if ((int)($k['case_id'] ?? 0)) {
        try {
            $q = $db->prepare("SELECT seq_no, notify_date, audit_from FROM ia_case WHERE case_id=?");
            $q->execute([(int)$k['case_id']]);
            $cc = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            $k['case_seq_no']     = (int)($cc['seq_no'] ?? 0);
            $k['case_notify_date']= (string)($cc['notify_date'] ?? '');
            $k['case_audit_from'] = (string)($cc['audit_from'] ?? '');
        } catch (Throwable $e) {}
    }
    $k['kind_label'] = IA_CHECK_KINDS[$k['kind']]['label'] ?? $k['kind'];
    $k['can_edit'] = ($perms['canAudit'] && $k['status'] !== 'done') || $perms['canAdmin'];
    jout(['row' => $k]);
}

case 'check_save_items': {
    iaReqAudit($perms);
    $kid = (int)($_POST['check_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_check WHERE check_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$kid]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) jerr('找不到這張查檢表', 404);
    if ($k['status'] === 'done' && !$perms['canAdmin']) jerr('這張查檢表已結案，需內稽管理員才能修改');

    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('格式錯誤');

    /* 稽核人事後可改（2026-09-17 使用者要求；製表人 2026-09-14 就開放了，稽核人一直只有建檔當下決定，
       補歷史紙本或換人接手時只能眼睜睜看著印錯人）。
       ①**沒送這個欄位＝舊呼叫端，一個字都不要動**；送了空字串才是「真的要清掉」（與製表人同語意）。
       ②資格一律用**這張表的稽核日期**回推（ia_resolve_post 的第四個參數＝ai-rules/22），
         用今天判定的話，補舊表時當時有資格、現在已離職或調職的人一律存不進去。
       ③跟原本一模一樣＝沒有更動，不重新驗資格（否則舊表連改個標題都存不回去）。
       ④前端擋一次、這裡用同一支 ia_resolve_post() 再擋一次（鐵律8）。
       **解析一定要在 beginTransaction 之前**：ia_resolve_post() 會一路走到「AS 文件負責人自動具備稽核員
       資格」那段，裡面有 CREATE TABLE IF NOT EXISTS＝DDL，在交易中執行會造成隱式 commit，
       接著那句 $db->commit() 就會拋「There is no active transaction」——
       症狀是**資料其實寫進去了、畫面卻收到 HTTP 500 空回應**（2026-09-17 實測踩到）。 */
    $ad = iaDate($_POST['check_date'] ?? '');
    $auditorSet = null;                       // null＝不動；['clear'=>1]＝清掉；否則＝要換成這一位
    if (array_key_exists('auditor_key', $_POST)) {
        $akey = trim((string)$_POST['auditor_key']);
        $asOf = $ad ?: (string)$k['check_date'];
        if ($akey === '') {
            $auditorSet = ['clear' => 1];
        } elseif ($akey !== ia_post_key((int)$k['auditor_id'], (int)$k['auditor_dept_id'], (int)$k['auditor_position_id'])) {
            $ap = ia_resolve_post($db, $akey, 'auditor', $asOf);
            if (!$ap) jerr('稽核人在稽核日期（' . $asOf . '）當時沒有該職務的稽核員資格');
            $auditorSet = $ap;
        }
    }

    $db->beginTransaction();
    try {
        /* col_c／col_d 用 COALESCE：**沒送這個欄位就沿用原值**（傳 null＝保留），
           送了空字串才是真的清空——否則「只改判定」的呼叫端會把受稽人姓名安靜洗掉。 */
        $upd = $db->prepare("UPDATE ia_check_item SET result=?, evidence=?, remark=?,
                                 col_c=COALESCE(?, col_c), col_d=COALESCE(?, col_d)
                              WHERE item_id=? AND check_id=?");
        /* 受稽人存「是誰」而不只是姓名（2026-09-18 使用者要求）：開 IA 單時直接帶人，
           不必再用姓名去猜（同名同姓、離職重號都會猜錯）。 */
        $updWho = $db->prepare("UPDATE ia_check_item SET auditee_id=?, auditee_dept_id=?, auditee_position_id=?
                                 WHERE item_id=? AND check_id=?");
        foreach ($items as $it) {
            $iid = (int)($it['item_id'] ?? 0);
            if (!$iid) continue;
            if (array_key_exists('auditee_key', $it)) {
                $ak = trim((string)$it['auditee_key']);
                if ($ak === '') $updWho->execute([null, null, null, $iid, $kid]);
                else { list($u, $d2, $p2) = ia_post_parse($ak); $updWho->execute([$u ?: null, $d2 ?: null, $p2 ?: null, $iid, $kid]); }
            }
            $res = (string)($it['result'] ?? '');
            if (!in_array($res, ['', 'ok', 'ng'], true)) $res = '';
            /* col_c／col_d 沒送就沿用原值（舊呼叫端與只改判定的情況都不該把姓名洗掉）。
               COALESCE 在 SQL 端做，避免「沒送＝清空」這種安靜的資料流失。 */
            $upd->execute([$res ?: null,
                           trim((string)($it['evidence'] ?? '')) ?: null,
                           mb_substr(trim((string)($it['remark'] ?? '')), 0, 255) ?: null,
                           array_key_exists('col_c', $it) ? mb_substr(trim((string)$it['col_c']), 0, 255) : null,
                           array_key_exists('col_d', $it) ? mb_substr(trim((string)$it['col_d']), 0, 255) : null,
                           $iid, $kid]);
        }
        $db->prepare("UPDATE ia_check SET title=?, check_date=COALESCE(?, check_date), updated_at=NOW() WHERE check_id=?")
           ->execute([mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150) ?: null, $ad, $kid]);
        if ($auditorSet !== null) {
            if (!empty($auditorSet['clear'])) {
                $db->prepare("UPDATE ia_check SET auditor_id=NULL, auditor_name=NULL,
                                  auditor_dept_id=NULL, auditor_position_id=NULL WHERE check_id=?")->execute([$kid]);
            } else {
                $db->prepare("UPDATE ia_check SET auditor_id=?, auditor_name=?, auditor_dept_id=?, auditor_position_id=?
                               WHERE check_id=?")
                   ->execute([$auditorSet['user_id'], $auditorSet['user_name'],
                              $auditorSet['dept_id'], $auditorSet['position_id'], $kid]);
            }
        }
        $db->commit();
        jout(['saved' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'check_done': {
    iaReqAudit($perms);
    $kid = (int)($_POST['check_id'] ?? 0);
    $to  = (string)($_POST['status'] ?? 'done');
    if (!in_array($to, ['draft', 'done'], true)) jerr('狀態不正確');
    $st = $db->prepare("SELECT * FROM ia_check WHERE check_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$kid]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) jerr('找不到這張查檢表', 404);
    if ($to === 'done') {
        $q = $db->prepare("SELECT COUNT(*) FROM ia_check_item WHERE check_id=? AND is_header=0
                            AND (result IS NULL OR result='')");
        $q->execute([$kid]);
        $todo = (int)$q->fetchColumn();
        if ($todo > 0) jerr('還有 ' . $todo . ' 個項目沒有判定合格／不合格，不能結案');
    }
    if ($to === 'draft' && !$perms['canAdmin']) jerr('取消結案需內稽管理員權限', 403);
    $db->prepare("UPDATE ia_check SET status=?, updated_at=NOW() WHERE check_id=?")->execute([$to, $kid]);
    jout(['saved' => true]);
}

case 'car_from_item': {
    // 績效執行稽核查檢表「沒達成」→ 自動開立異常矯正處理單（views/QA/correction_order.php）
    // 並把單號寫回該列備註（紙本那一欄本來就叫「備註(異常矯正處理單編號)」）。
    iaReqAudit($perms);
    $iid = (int)($_POST['item_id'] ?? 0);
    $st = $db->prepare("SELECT i.*, k.kind, k.check_date, k.year, k.status AS check_status
                          FROM ia_check_item i JOIN ia_check k ON k.check_id=i.check_id
                         WHERE i.item_id=? AND COALESCE(k.is_deleted,0)=0");
    $st->execute([$iid]);
    $it = $st->fetch(PDO::FETCH_ASSOC);
    if (!$it) jerr('找不到這個項目', 404);
    if ($it['kind'] !== 'kpi') jerr('只有績效執行稽核查檢表才開異常矯正處理單');
    if ((string)$it['result'] !== 'ng') jerr('這個項目不是「沒達成」，不需要開矯正單');
    if (!empty($it['car_id'])) {
        $q = $db->prepare("SELECT car_no FROM car_order WHERE id=?"); $q->execute([(int)$it['car_id']]);
        jerr('這個項目已經開過矯正單 ' . (string)($q->fetchColumn() ?: ''));
    }
    $ck = ['check_date' => $it['check_date']];
    try {
        $res = ia_car_create_from_kpi($db, $ck, $it, $uid, $uname);
    } catch (Throwable $e) { jerr('開立矯正單失敗：' . $e->getMessage(), 500); }
    jout($res);
}

case 'check_delete': {
    iaReqAdmin($perms);
    $kid = (int)($_POST['check_id'] ?? 0);
    $q = $db->prepare("SELECT COUNT(*) FROM ia_check_item WHERE check_id=? AND nc_id IS NOT NULL");
    $q->execute([$kid]);
    if ((int)$q->fetchColumn() > 0) jerr('這張查檢表已經開過不符合通知單，不可刪除');
    $db->prepare("UPDATE ia_check SET is_deleted=1, updated_at=NOW() WHERE check_id=?")->execute([$kid]);
    jout(['deleted' => true]);
}

/* ---- AS 條文題庫維護 ---- */
case 'clause_list': {
    iaReqView($perms);
    $rows = ia_as_clauses($db, false);
    // 每一條帶上「可挑的作業項目（依它列的文件）」與「已挑的」——題庫畫面要一次畫得出來
    $picked = ia_clause_task_map($db, array_column($rows, 'clause_id'));
    foreach ($rows as &$r) {
        $r['doc_tasks'] = ia_clause_doc_tasks($db, $r['doc_ref'] ?? '');
        $r['tasks']     = $picked[(int)$r['clause_id']] ?? [];
    }
    unset($r);
    jout(['rows' => $rows]);
}
/* AS 文件挑選清單（2026-08-26 使用者要求：條文題庫的「建立的文件、表單」與 IA 單的「相關表單編號」
   都要能打編號或名稱模糊篩選後挑選，不要手打）。回全部未廢止的 AS 文件，前端自己過濾即可，
   資料量小（一百多筆）不必做伺服器端搜尋。 */
/* 圖章要印的「部門／職稱」（2026-08-27 使用者回報：列印版的章跟圖章模板設計的格式不同、部門不見了）。
   原因：圖章模板 schema 是「{部門} {姓名}／{日期}」兩列，但列印端多數呼叫只給了姓名，
   模板取不到部門就整段空著。這裡一次把要蓋章的人解析成「該單據業務日期當時」的部門與職稱
   （ai-rules/22：不是現況），前端拿到後再交給 eg_stamp.js。
   收在後端做的理由：職務回推規則只有一份（ia_identity_asof），前端不該自己再猜一次。 */
case 'identity_asof': {
    iaReqView($perms);
    $req = json_decode((string)($_GET['people'] ?? $_POST['people'] ?? '[]'), true);
    if (!is_array($req)) $req = [];
    $out = [];
    foreach ($req as $r) {
        if (!is_array($r)) continue;
        $uid = (int)($r['id'] ?? 0);
        if ($uid <= 0) continue;
        $d = iaDate($r['date'] ?? '');
        $key = $uid . '@' . ($d ?: '');
        if (isset($out[$key])) continue;
        $out[$key] = ia_identity_asof($db, $uid, $d ?: null);
    }
    jout(['map' => $out]);
}

case 'asdoc_pick_list': {
    iaReqView($perms);
    $rows = [];
    try {
        $rows = $db->query("SELECT id, doc_no, doc_name, doc_type FROM as_document
                             WHERE COALESCE(is_obsolete,0)=0 ORDER BY doc_no")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try { $rows = $db->query("SELECT id, doc_no, doc_name, doc_type FROM as_document
                                   ORDER BY doc_no")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e2) {}
    }
    jout(['rows' => $rows]);
}

/* 條文題庫拖曳排序（2026-08-26 使用者要求：拖移後自動更新順序，不要手動輸入）。
   一律重新編號成 10,20,30…（留間隔，日後單筆插入才不用整批重排）。 */
case 'clause_reorder': {
    iaReqAdmin($perms);
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids) || !$ids) jerr('沒有收到排序內容');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) jerr('沒有收到排序內容');
    // 只接受真的存在的條文 id（鐵律8：不能只信前端送什麼就寫什麼）
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT COUNT(*) FROM ia_as_clause WHERE clause_id IN ($in)");
    $st->execute($ids);
    if ((int)$st->fetchColumn() !== count($ids)) jerr('有條文已被刪除，請重新整理題庫後再排序');
    $db->beginTransaction();
    try {
        $up = $db->prepare("UPDATE ia_as_clause SET sort_order=?, updated_at=NOW(), updated_by=? WHERE clause_id=?");
        $n = 0;
        foreach ($ids as $id) $up->execute([($n += 10), $uname, $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('排序儲存失敗：' . $e->getMessage(), 500); }
    jout(['count' => count($ids)]);
}

case 'clause_save': {
    iaReqAdmin($perms);
    $id   = iaInt($_POST['clause_id'] ?? '');
    $text = trim((string)($_POST['clause_text'] ?? ''));
    if ($text === '') jerr('請填品質管理系統要求');
    if (mb_strlen($text) > 1000) jerr('內容過長（上限 1000 字）');
    $ref  = trim((string)($_POST['doc_ref'] ?? ''));
    $hdr  = !empty($_POST['is_header']) ? 1 : 0;
    $act  = isset($_POST['is_active']) ? (!empty($_POST['is_active']) ? 1 : 0) : 1;
    $sort = iaInt($_POST['sort_order'] ?? '');
    if ($id) {
        $db->prepare("UPDATE ia_as_clause SET clause_text=?, doc_ref=?, is_header=?, is_active=?,
                          sort_order=COALESCE(?, sort_order), updated_at=NOW(), updated_by=? WHERE clause_id=?")
           ->execute([$text, $ref ?: null, $hdr, $act, $sort, $uname, $id]);
    } else {
        if ($sort === null) {
            $sort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+10 FROM ia_as_clause")->fetchColumn();
        }
        $db->prepare("INSERT INTO ia_as_clause (sort_order, is_header, clause_text, doc_ref, is_active, updated_at, updated_by)
                      VALUES (?,?,?,?,?,NOW(),?)")->execute([$sort, $hdr, $text, $ref ?: null, $act, $uname]);
        $id = (int)$db->lastInsertId();
    }
    // 作業項目（有送才動；沒送＝這次不碰，避免別的呼叫端把已挑好的整組洗掉）
    if (array_key_exists('task_ids', $_POST)) {
        $tids = json_decode((string)$_POST['task_ids'], true);
        ia_clause_tasks_save($db, $id, is_array($tids) ? $tids : [], $ref);
    }
    $tasks = ia_clause_task_map($db, [$id])[$id] ?? [];
    jout(['clause_id' => $id, 'tasks' => $tasks, 'doc_tasks' => ia_clause_doc_tasks($db, $ref)]);
}
case 'clause_delete': {
    iaReqAdmin($perms);
    $id = (int)($_POST['clause_id'] ?? 0);
    // 已經被查檢表引用的條文不刪除，改停用（刪掉會讓舊查檢表的來源對不上）
    $q = $db->prepare("SELECT COUNT(*) FROM ia_check_item WHERE ref_kind='as_clause' AND ref_id=?");
    $q->execute([$id]);
    if ((int)$q->fetchColumn() > 0) {
        $db->prepare("UPDATE ia_as_clause SET is_active=0, updated_at=NOW(), updated_by=? WHERE clause_id=?")
           ->execute([$uname, $id]);
        jout(['deactivated' => true, 'note' => '此條文已被既有查檢表引用，已改為停用（不再出現在新表）而非刪除']);
    }
    $db->prepare("DELETE FROM ia_as_clause WHERE clause_id=?")->execute([$id]);
    try { $db->prepare("DELETE FROM ia_as_clause_task WHERE clause_id=?")->execute([$id]); } catch (Throwable $e) {}
    jout(['deleted' => true]);
}

/* ============================ 不符合通知單 2-GM-06-07 ============================ */
case 'nc_list': {
    $w = ['COALESCE(n.is_deleted,0)=0']; $p = [];
    $year = iaInt($_GET['year'] ?? '');
    if ($year) { $w[] = 'n.year=?'; $p[] = $year; }
    $stage = (string)($_GET['stage'] ?? '');
    if ($stage !== '' && isset(IA_NC_STAGES[$stage])) { $w[] = 'n.stage=?'; $p[] = $stage; }
    $cid = iaInt($_GET['case_id'] ?? '');
    if ($cid) { $w[] = 'n.case_id=?'; $p[] = $cid; }
    if ((string)($_GET['overdue'] ?? '') === '1') { $w[] = "n.stage<>'closed' AND n.due_date IS NOT NULL AND n.due_date < ?"; $p[] = $today; }
    // 沒有檢閱權限的人只看得到跟自己有關的（後端強制綁定，不是只擋前端）
    if (!$perms['canView']) {
        $deptIds = [];
        try {
            $q = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id=?");
            $q->execute([$uid]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) {
                foreach (eg_dept_subtree_ids($db, (int)$d) ?: [(int)$d] as $x) $deptIds[] = (int)$x;
            }
        } catch (Throwable $e) {}
        $deptIds = array_values(array_unique($deptIds));
        if ($deptIds) {
            $in = implode(',', array_fill(0, count($deptIds), '?'));
            $w[] = "(n.auditee_id=? OR n.head_id=? OR n.resp_id=? OR n.auditor_id=? OR n.leader_id=?
                      OR n.case_id IN (SELECT pr.case_id FROM ia_case_dept_person pr
                                        WHERE pr.user_id=? AND pr.kind='auditor') OR n.dept_id IN ($in))";
            array_push($p, $uid, $uid, $uid, $uid, $uid, $uid);
            foreach ($deptIds as $d) $p[] = $d;
        } else {
            $w[] = "(n.auditee_id=? OR n.head_id=? OR n.resp_id=? OR n.auditor_id=? OR n.leader_id=?
                      OR n.case_id IN (SELECT pr.case_id FROM ia_case_dept_person pr
                                        WHERE pr.user_id=? AND pr.kind='auditor'))";
            array_push($p, $uid, $uid, $uid, $uid, $uid, $uid);
        }
    }
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = "(n.nc_no LIKE ? OR n.dept_name LIKE ? OR n.auditee_name LIKE ? OR n.fact LIKE ?
                     OR n.clause_ref LIKE ? OR n.ref_form_no LIKE ? OR n.cause LIKE ? OR n.corrective LIKE ?
                     OR n.preventive LIKE ? OR n.auditor_name LIKE ? OR n.head_note LIKE ?)";
            for ($i = 0; $i < 11; $i++) $p[] = '%' . $k . '%';
        }
    }
    $sql = "SELECT n.*, c.case_no FROM ia_nc n LEFT JOIN ia_case c ON c.case_id=n.case_id
             WHERE " . implode(' AND ', $w) . " ORDER BY n.year DESC, n.nc_no DESC, n.nc_id DESC";
    $st = $db->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['type_label']  = IA_NC_TYPES[(string)($r['nc_type'] ?? '')] ?? '';
        $r['stage_label'] = IA_NC_STAGES[(string)$r['stage']] ?? (string)$r['stage'];
        $r['overdue']     = ($r['stage'] !== 'closed' && $r['due_date'] && $r['due_date'] < $today) ? 1 : 0;
    }
    unset($r);
    jout(['rows' => $rows]);
}

case 'nc_get': {
    $id = (int)($_GET['nc_id'] ?? 0);
    $st = $db->prepare("SELECT n.*, c.case_no FROM ia_nc n LEFT JOIN ia_case c ON c.case_id=n.case_id
                         WHERE n.nc_id=? AND COALESCE(n.is_deleted,0)=0");
    $st->execute([$id]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    $sp = ia_nc_stage_perm($db, $n, $perms, $uid);
    if (!$sp['view']) jerr('無權檢視這張不符合通知單', 403);
    $n['perm']        = $sp;
    $n['type_label']  = IA_NC_TYPES[(string)($n['nc_type'] ?? '')] ?? '';
    $n['stage_label'] = IA_NC_STAGES[(string)$n['stage']] ?? (string)$n['stage'];
    $n['overdue']     = ($n['stage'] !== 'closed' && $n['due_date'] && $n['due_date'] < $today) ? 1 : 0;
    // 建議的單位主管（依業務日期回推當時職務，ai-rules/22）
    $sug = ia_dept_head_asof($db, (int)($n['dept_id'] ?? 0), (string)($n['audit_date'] ?? ''));
    $n['suggest_head'] = $sug;
    $st = $db->prepare("SELECT * FROM ia_nc_log WHERE nc_id=? ORDER BY log_id");
    $st->execute([$id]);
    $n['logs'] = $st->fetchAll(PDO::FETCH_ASSOC);
    jout(['row' => $n]);
}

case 'nc_create': {
    iaReqAudit($perms);
    $ad = iaDate($_POST['audit_date'] ?? '');
    if (!$ad) jerr('請填稽核日期');
    $fact = trim((string)($_POST['fact'] ?? ''));
    if ($fact === '') jerr('請填不合格事實描述');
    $type = (string)($_POST['nc_type'] ?? '');
    if (!isset(IA_NC_TYPES[$type])) jerr('請選擇不合格類型');
    $deptId = iaInt($_POST['dept_id'] ?? '');
    $deptName = trim((string)($_POST['dept_name'] ?? ''));
    if ($deptId) {
        $q = $db->prepare("SELECT name FROM department WHERE id=?"); $q->execute([$deptId]);
        $deptName = (string)($q->fetchColumn() ?: $deptName);
    }
    if ($deptName === '') jerr('請選擇受稽核單位');
    $due = iaDate($_POST['due_date'] ?? '');
    if ($due && $due < $ad) jerr('要求完成期限不可早於稽核日期');

    $auditeeId = iaInt($_POST['auditee_id'] ?? ''); $auditeeName = '';
    if ($auditeeId) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$auditeeId]);
        $auditeeName = (string)($q->fetchColumn() ?: '');
        if ($auditeeName === '') jerr('受審核人不存在');
    }
    $caseId = iaInt($_POST['case_id'] ?? '');
    $c = [];                                   // 沒綁案件時仍要有值，否則下方取 leader_id 會噴 undefined
    if ($caseId) {
        $q = $db->prepare("SELECT leader_id, leader_name FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
        $q->execute([$caseId]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        if (!$c) jerr('稽核案件不存在');
    }
    $srcItem = iaInt($_POST['src_item_id'] ?? '');

    $db->beginTransaction();
    try {
        $head = ia_dept_head_asof($db, $deptId, $ad);
        $ncNo = ia_next_nc_no($db, $ad);
        $db->prepare("INSERT INTO ia_nc (nc_no, case_id, year, dept_id, dept_name, auditee_id, auditee_name,
                          audit_date, src_kind, src_item_id, ref_form_no, fact, nc_type, clause_ref, due_date,
                          auditor_id, auditor_name, auditor_date, head_id, head_name,
                          leader_id, leader_name, stage, created_by, created_by_name, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'issued', ?,?, NOW(), NOW())")
           ->execute([$ncNo, $caseId, (int)substr($ad, 0, 4), $deptId, $deptName, $auditeeId, $auditeeName ?: null,
                      $ad, (string)($_POST['src_kind'] ?? '') ?: null, $srcItem,
                      mb_substr(trim((string)($_POST['ref_form_no'] ?? '')), 0, 60) ?: null,
                      $fact, $type, mb_substr(trim((string)($_POST['clause_ref'] ?? '')), 0, 300) ?: null, $due,
                      $uid, $uname, $ad,
                      $head['id'] ?? null, $head['name'] ?? null,
                      $c['leader_id'] ?? null, $c['leader_name'] ?? null,
                      $uid, $uname]);
        $ncId = (int)$db->lastInsertId();
        /* 表單名稱快照（2026-09-18 使用者要求）：日後改編號／改名／廢止，這張已開的單仍印得出當時的名稱。
           前端沒送就即時由編號回查一次，補歷史單據也有名字。 */
        $fname = trim((string)($_POST['ref_form_name'] ?? ''));
        if ($fname === '') $fname = ia_asdoc_name_by_no($db, (string)($_POST['ref_form_no'] ?? ''));
        if ($fname !== '') {
            $db->prepare("UPDATE ia_nc SET ref_form_name=? WHERE nc_id=?")
               ->execute([mb_substr($fname, 0, 150), $ncId]);
        }
        if ($srcItem) {
            $db->prepare("UPDATE ia_check_item SET nc_id=?, remark=COALESCE(NULLIF(remark,''), ?) WHERE item_id=?")
               ->execute([$ncId, $ncNo, $srcItem]);
        }
        ia_nc_log_add($db, $ncId, 'issued', 'create', $uid, $uname, '開立不符合通知單 ' . $ncNo);
        // 開了不符合通知單就順手把該年度的稽核報告表建起來（2026-09-18 使用者要求）
        ia_report_ensure($db, (int)substr($ad, 0, 4), $uid, $uname);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('建立失敗：' . $e->getMessage(), 500); }

    $q = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=?"); $q->execute([$ncId]);
    $nc = $q->fetch(PDO::FETCH_ASSOC);
    ia_notify_nc_issued($db, $nc, $uid);
    jout(['nc_id' => $ncId, 'nc_no' => $ncNo]);
}

case 'nc_save_sec1': {
    // 稽核員段：不合格事實／類型／違反條文／期限／受審核人
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    $sp = ia_nc_stage_perm($db, $n, $perms, $uid);
    if (!$sp['sec1']) jerr('您沒有修改稽核員填寫區的權限（或本單已結案）', 403);

    $fact = trim((string)($_POST['fact'] ?? ''));
    if ($fact === '') jerr('請填不合格事實描述');
    $type = (string)($_POST['nc_type'] ?? '');
    if (!isset(IA_NC_TYPES[$type])) jerr('請選擇不合格類型');
    $due = iaDate($_POST['due_date'] ?? '');
    if ($due && $n['audit_date'] && $due < $n['audit_date']) jerr('要求完成期限不可早於稽核日期');
    $auditeeId = iaInt($_POST['auditee_id'] ?? ''); $auditeeName = null;
    if ($auditeeId) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$auditeeId]);
        $auditeeName = (string)($q->fetchColumn() ?: '');
        if ($auditeeName === '') jerr('受審核人不存在');
    }
    $db->prepare("UPDATE ia_nc SET fact=?, nc_type=?, clause_ref=?, due_date=?, ref_form_no=?,
                      auditee_id=?, auditee_name=?, updated_at=NOW() WHERE nc_id=?")
       ->execute([$fact, $type, mb_substr(trim((string)($_POST['clause_ref'] ?? '')), 0, 300) ?: null, $due,
                  mb_substr(trim((string)($_POST['ref_form_no'] ?? '')), 0, 60) ?: null,
                  $auditeeId, $auditeeName, $id]);
    ia_nc_log_add($db, $id, (string)$n['stage'], 'edit', $uid, $uname, '修改稽核員填寫區');
    jout(['saved' => true]);
}

case 'nc_save_sec2': {
    // 受稽單位段：單位主管核示／原因分析／糾正措施／預防措施／責任主管
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    $sp = ia_nc_stage_perm($db, $n, $perms, $uid);
    if (!$sp['sec2']) jerr('您沒有填寫受稽單位回覆區的權限（或本單已結案）', 403);

    $submit = !empty($_POST['submit']);
    $cause  = trim((string)($_POST['cause'] ?? ''));
    $corr   = trim((string)($_POST['corrective'] ?? ''));
    $prev   = trim((string)($_POST['preventive'] ?? ''));
    if ($submit) {
        // 送出才驗必填；只是暫存不擋（讓人分次填）
        if ($cause === '') jerr('請填原因分析');
        if ($corr === '')  jerr('請填糾正措施及完成時間');
        if ($prev === '')  jerr('請填預防措施及完成時間');
    }
    $headId = iaInt($_POST['head_id'] ?? ''); $headName = null;
    if ($headId) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$headId]);
        $headName = (string)($q->fetchColumn() ?: '');
        if ($headName === '') jerr('受審查單位主管不存在');
    }
    $respId = iaInt($_POST['resp_id'] ?? ''); $respName = null;
    if ($respId) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$respId]);
        $respName = (string)($q->fetchColumn() ?: '');
        if ($respName === '') jerr('責任主管不存在');
    }
    // 「單位主管核示」2026-08-27 起紙本與畫面都沒有這一格了（使用者：完全不需要這行）。
    // 欄位保留在 DB 不刪，沒送這個參數時就沿用原值，不要把既有內容洗成空的。
    $headNote = array_key_exists('head_note', $_POST)
              ? (trim((string)$_POST['head_note']) ?: null)
              : ($n['head_note'] ?? null);
    $headDate = iaDate($_POST['head_date'] ?? '') ?: $today;
    $respDate = iaDate($_POST['resp_date'] ?? '') ?: $today;

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE ia_nc SET cause=?, corrective=?, preventive=?, head_id=?, head_name=?,
                          head_note=?, head_date=?, resp_id=?, resp_name=?, resp_date=?, updated_at=NOW()
                       WHERE nc_id=?")
           ->execute([$cause ?: null, $corr ?: null, $prev ?: null, $headId, $headName,
                      $headNote, $headId ? $headDate : null,
                      $respId, $respName, $respId ? $respDate : null, $id]);
        if ($submit && $n['stage'] === 'issued') {
            $db->prepare("UPDATE ia_nc SET stage='replied', updated_at=NOW() WHERE nc_id=?")->execute([$id]);
        }
        ia_nc_log_add($db, $id, $submit ? 'replied' : (string)$n['stage'],
                      $submit ? 'reply' : 'edit', $uid, $uname,
                      $submit ? '受稽單位送出回覆' : '暫存受稽單位回覆',
                      $sp['proxy'] ? 1 : 0, $sp['proxy'] ? (string)($headName ?: $n['dept_name']) : '');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }

    if ($submit) {
        $q = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=?"); $q->execute([$id]);
        $nc = $q->fetch(PDO::FETCH_ASSOC);
        ia_nc_close_notice($db, $id, 'IA_NC_REPLY');
        ia_notify_nc_replied($db, $nc, $uid, $uname);
    }
    jout(['saved' => true, 'submitted' => $submit, 'proxy' => (bool)$sp['proxy']]);
}

case 'nc_save_sec3': {
    // 驗證段：稽核組長驗證描述／結束
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    $sp = ia_nc_stage_perm($db, $n, $perms, $uid);
    if (!$sp['sec3']) jerr('尚未收到受稽單位回覆，或您沒有驗證權限', 403);

    $desc = trim((string)($_POST['verify_desc'] ?? ''));
    $res  = (string)($_POST['verify_result'] ?? '');
    if (!in_array($res, ['', 'pass', 'fail'], true)) jerr('驗證結果不正確');
    $submit = !empty($_POST['submit']);
    if ($submit) {
        if ($desc === '') jerr('請填糾正和預防措施執行狀況驗證描述');
        if ($res === '')  jerr('請選擇驗證結果（通過／不通過）');
    }
    $ld = iaDate($_POST['leader_date'] ?? '') ?: $today;
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE ia_nc SET verify_desc=?, verify_result=?, close_note=?,
                          leader_id=?, leader_name=?, leader_date=?, updated_at=NOW() WHERE nc_id=?")
           ->execute([$desc ?: null, $res ?: null,
                      mb_substr(trim((string)($_POST['close_note'] ?? '')), 0, 300) ?: null,
                      $uid, $uname, $ld, $id]);
        if ($submit) {
            if ($res === 'fail') {
                // 驗證不通過＝退回受稽單位重填
                $db->prepare("UPDATE ia_nc SET stage='issued', updated_at=NOW() WHERE nc_id=?")->execute([$id]);
                ia_nc_log_add($db, $id, 'issued', 'verify', $uid, $uname, '驗證不通過，退回受稽單位重新提出措施');
            } else {
                $db->prepare("UPDATE ia_nc SET stage='verified', updated_at=NOW() WHERE nc_id=?")->execute([$id]);
                ia_nc_log_add($db, $id, 'verified', 'verify', $uid, $uname, '驗證通過，待管理代表意見');
            }
        } else {
            ia_nc_log_add($db, $id, (string)$n['stage'], 'edit', $uid, $uname, '暫存驗證內容');
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }

    if ($submit && $res === 'fail') {
        $q = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=?"); $q->execute([$id]);
        ia_notify_nc_issued($db, $q->fetch(PDO::FETCH_ASSOC), $uid);
    }
    jout(['saved' => true, 'submitted' => $submit]);
}

case 'nc_save_sec4': {
    // 管理代表意見＋結案
    iaReqAdmin($perms);
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    if ($n['stage'] === 'closed') jerr('本單已結案');
    $close = !empty($_POST['close']);
    if ($close && $n['stage'] !== 'verified') jerr('要先由稽核組長完成驗證才能結案');
    $note = trim((string)($_POST['mgr_note'] ?? ''));
    $md   = iaDate($_POST['mgr_date'] ?? '') ?: $today;
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE ia_nc SET mgr_note=?, mgr_id=?, mgr_name=?, mgr_date=?,
                          stage=IF(?=1,'closed',stage), updated_at=NOW() WHERE nc_id=?")
           ->execute([$note ?: null, $uid, $uname, $md, $close ? 1 : 0, $id]);
        ia_nc_log_add($db, $id, $close ? 'closed' : (string)$n['stage'],
                      $close ? 'close' : 'edit', $uid, $uname, $close ? '管理代表結案' : '填寫管理代表意見');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    if ($close) {
        $q = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=?"); $q->execute([$id]);
        ia_notify_nc_closed($db, $q->fetch(PDO::FETCH_ASSOC), $uid, $uname);
    }
    jout(['saved' => true, 'closed' => $close]);
}

case 'nc_delete': {
    iaReqAdmin($perms);
    $id = (int)($_POST['nc_id'] ?? 0);
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE ia_nc SET is_deleted=1, updated_at=NOW() WHERE nc_id=?")->execute([$id]);
        $db->prepare("UPDATE ia_check_item SET nc_id=NULL WHERE nc_id=?")->execute([$id]);
        foreach (['IA_NC_REPLY', 'IA_NC_VERIFY', 'IA_NC_DUE'] as $t) ia_nc_close_notice($db, $id, $t);
        ia_nc_log_add($db, $id, 'deleted', 'delete', $uid, $uname, '刪除');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：' . $e->getMessage(), 500); }
    jout(['deleted' => true]);
}

case 'nc_resend': {
    iaReqAudit($perms);
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    if ($n['stage'] === 'closed') jerr('已結案的單不需再通知');
    $eid = ia_notify_nc_issued($db, $n, $uid);
    jout(['sent' => (bool)$eid]);
}

/* ============================ 稽核報告表 2-GM-06-08 ============================ */
case 'report_get': {
    iaReqView($perms);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    $st = $db->prepare("SELECT * FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    $rep = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $data = ia_report_data($db, $year);          // 缺點數與缺點記錄一律即時算，不存快照
    jout(['year' => $year, 'report' => $rep, 'rows' => $data['rows'], 'records' => $data['records']]);
}

case 'report_save': {
    iaReqAdmin($perms);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000 || $year > 2200) jerr('年度不正確');
    $note = trim((string)($_POST['extra_note'] ?? ''));
    $st = $db->prepare("SELECT report_id FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    $rid = (int)($st->fetchColumn() ?: 0);
    if ($rid) {
        $db->prepare("UPDATE ia_report SET extra_note=?, updated_at=NOW() WHERE report_id=?")
           ->execute([$note ?: null, $rid]);
        // 製表人可事後修改（2026-09-14）
        $q = $db->prepare("SELECT maker_date FROM ia_report WHERE report_id=?"); $q->execute([$rid]);
        if (($mk = iaMakerFromPost($db, (string)($q->fetchColumn() ?: ''))) !== null) {
            $db->prepare("UPDATE ia_report SET maker_id=?, maker_name=?, maker_date=? WHERE report_id=?")
               ->execute([$mk['id'], $mk['name'], $mk['date'], $rid]);
        }
    } else {
        $mkNew = iaMakerFromPost($db, $today) ?: ['id' => $uid, 'name' => $uname, 'date' => $today];
        $db->prepare("INSERT INTO ia_report (year, extra_note, status, maker_id, maker_name, maker_date,
                          created_by, created_at, updated_at) VALUES (?,?, 'draft', ?,?,?,?, NOW(), NOW())")
           ->execute([$year, $note ?: null, $mkNew['id'], $mkNew['name'], $mkNew['date'], $uid]);
        $rid = (int)$db->lastInsertId();
    }
    // 預定完成改善時間是存在 ia_case_dept.improve_due（那才是「受稽單位」層級的欄位）
    $dues = json_decode((string)($_POST['dues'] ?? '[]'), true);
    if (is_array($dues) && $dues) {
        $upd = $db->prepare("UPDATE ia_case_dept cd JOIN ia_case c ON c.case_id=cd.case_id
                             SET cd.improve_due=? WHERE c.year=? AND cd.dept_name=?");
        foreach ($dues as $d) {
            $dn = trim((string)($d['dept_name'] ?? ''));
            if ($dn === '') continue;
            $upd->execute([iaDate($d['improve_due'] ?? ''), $year, $dn]);
        }
    }
    jout(['report_id' => $rid]);
}

case 'report_delete': {
    // 2026-09-14 使用者要求：稽核內建立完的單據，管理員一律要刪得掉。
    // 報告表上的缺點數是由不符合通知單即時算的，這裡刪掉的只有這張表本身（補充文字＋製表／核准）。
    iaReqAdmin($perms);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000 || $year > 2200) jerr('年度不正確');
    $st = $db->prepare("SELECT report_id FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    $rid = (int)($st->fetchColumn() ?: 0);
    if (!$rid) jerr('這個年度還沒有稽核報告表', 404);
    $db->prepare("UPDATE ia_report SET is_deleted=1, updated_at=NOW() WHERE report_id=?")->execute([$rid]);
    jout(['deleted' => true]);
}

case 'report_approve': {
    iaReqAdmin($perms);
    $year = (int)($_POST['year'] ?? 0);
    $d = iaDate($_POST['biz_date'] ?? '') ?: $today;
    $st = $db->prepare("SELECT report_id FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    $rid = (int)($st->fetchColumn() ?: 0);
    if (!$rid) jerr('請先儲存稽核報告表');
    // 核准欄的人同樣依「設定→列印簽章→核准格」解析，與列印版一致（2026-09-16）
    $st2 = $db->prepare("SELECT maker_id, maker_name FROM ia_report WHERE report_id=?");
    $st2->execute([$rid]); $rr = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
    $ap = ia_sign_slot_person($db, 'approve',
            ['leader_id' => 0, 'leader_name' => '', 'maker_id' => (int)($rr['maker_id'] ?? 0),
             'maker_name' => (string)($rr['maker_name'] ?? ''), 'biz_date' => $d],
            ['id' => $uid, 'name' => $uname]);
    $db->prepare("UPDATE ia_report SET status='approved', approver_id=?, approver_name=?, approver_date=?, updated_at=NOW()
                   WHERE report_id=?")->execute([$ap['id'] ?: null, $ap['name'] ?: null, $d, $rid]);
    jout(['saved' => true, 'approver' => $ap['name']]);
}

/* 稽核報告表「送出」（2026-09-17 使用者要求取代核准）：
   送出後自動通知「管理員設定好的那些部門的那些職位」的人。
   沒設定通知對象也照樣送得出去，只是回報通知 0 人——不要因為沒設定就把流程擋住。 */
case 'report_submit': {
    iaReqAdmin($perms);
    $year = (int)($_POST['year'] ?? 0);
    $d = iaDate($_POST['biz_date'] ?? '') ?: $today;
    try {
        $r = ia_report_submit($db, $year, $d, $uid, $uname);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout($r);
}

/* 通知對象設定：一條是「部門 × 職位」，兩個都空的列後端一律丟掉（否則等於全公司廣播） */
case 'report_notify_get': {
    iaReqView($perms);
    $rules = ia_report_notify_rules($db);
    jout(['rules' => $rules, 'preview' => array_values(ia_report_notify_users($db, $rules))]);
}
case 'report_notify_save': {
    iaReqAdmin($perms);
    $rules = json_decode((string)($_POST['rules'] ?? '[]'), true);
    if (!is_array($rules)) jerr('格式錯誤');
    $n = ia_report_notify_save($db, $rules, $uname);
    jout(['saved' => true, 'count' => $n, 'preview' => array_values(ia_report_notify_users($db))]);
}
/* 通知對象的即時試算（還沒存就看得到會通知到誰；設定跳窗每改一次就重算一次） */
case 'report_notify_preview': {
    iaReqView($perms);
    $rules = json_decode((string)($_GET['rules'] ?? $_POST['rules'] ?? '[]'), true);
    if (!is_array($rules)) $rules = [];
    jout(['preview' => array_values(ia_report_notify_users($db, $rules))]);
}

/* 管理員補舊年度：年度選單只列「有資料的年度＋今年明年」，要補更舊的資料得先把年度加進來 */
case 'year_add': {
    iaReqAdmin($perms);
    $y = (int)($_POST['year'] ?? 0);
    try { $r = ia_extra_year_add($db, $y, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['years' => ia_year_options($db), 'added' => $r['added']]);
}
case 'year_del': {
    iaReqAdmin($perms);
    $y = (int)($_POST['year'] ?? 0);
    // 已經有資料的年度不給移除（移掉會變成有資料卻選不到）
    $st = $db->prepare("SELECT (SELECT COUNT(*) FROM ia_plan WHERE year=? AND COALESCE(is_deleted,0)=0)
                             + (SELECT COUNT(*) FROM ia_case WHERE year=? AND COALESCE(is_deleted,0)=0)
                             + (SELECT COUNT(*) FROM ia_check WHERE year=? AND COALESCE(is_deleted,0)=0)
                             + (SELECT COUNT(*) FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0)
                             + (SELECT COUNT(*) FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0)");
    $st->execute([$y, $y, $y, $y, $y]);
    if ((int)$st->fetchColumn() > 0) jerr('這個年度已經有內稽資料，不可以從選單移除');
    ia_extra_year_del($db, $y, $uname);
    jout(['years' => ia_year_options($db)]);
}

/* ============================ 會議紀錄串接（不重複建立，走既有模組） ============================ */
case 'meeting_create': {
    iaReqAdmin($perms);
    /* meeting_attendee.alt_posts（兼任職務顯示欄）由會議模組的 ensure 建立＝唯一實作。
       **一定要在 beginTransaction 之前呼叫**：裡面是 DDL，在交易中執行會造成隱式 commit。 */
    require_once $document_root . '/EGsystem/src/common/meeting_lib.php';
    meeting_ensure_schema($db);
    $cid  = (int)($_POST['case_id'] ?? 0);
    $kind = (string)($_POST['kind'] ?? '');           // pre=事前會議 / end=結束會議
    if (!in_array($kind, ['pre', 'end'], true)) jerr('會議種類不正確');
    $st = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$cid]); $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到這張稽核通知單', 404);
    $col = $kind === 'pre' ? 'pre_meeting_id' : 'end_meeting_id';
    if ((int)($c[$col] ?? 0)) {
        // 已建過就直接回傳既有的，不重複建立（點開即刷新鐵則：同一顆按鈕按兩次不該長出兩筆會議）
        $q = $db->prepare("SELECT meeting_id FROM meeting_record WHERE meeting_id=?");
        $q->execute([(int)$c[$col]]);
        if ($q->fetchColumn()) jout(['meeting_id' => (int)$c[$col], 'existed' => true]);
    }

    $set = ia_settings($db);
    $year = (int)$c['year'];
    $subject = $kind === 'pre'
        ? (trim((string)$set['ia_meeting_pre_subject']) ?: ($year . '年度 內稽事前會議'))
        : (trim((string)$set['ia_meeting_end_subject']) ?: ($year . '年度 內稽結束會議'));
    // 主題不附稽核件號（2026-09-17 使用者要求）：紙本會議紀錄的主題就只有主題，
    // 要對回是哪一場稽核，通知單上本來就有「開啟會議紀錄」的連結。

    $mdate = $kind === 'pre'
        ? ($c['notify_date'] ?: $c['audit_from'] ?: $today)
        : ($c['end_meet_date'] ?: $c['audit_to'] ?: $c['audit_from'] ?: $today);
    $stime = $kind === 'end' ? ($c['end_meet_start'] ?: null) : null;
    $etime = $kind === 'end' ? ($c['end_meet_end'] ?: null)   : null;
    $place = $kind === 'end' ? ($c['end_meet_place'] ?: null) : null;

    /* 主席固定＝稽核組長、出席人員一律走共用的 ia_meeting_attendees_apply()
       （建立會議與「依目前小組重新帶入」共用同一份規則＝鐵律4）。 */
    $team  = ia_team_get($db, $year);
    $chair = ia_meeting_chair($db, $c, $team, $uid, $uname);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO meeting_record (subject, meeting_date, start_time, end_time, location,
                          chair_user_id, chair_name, recorder_user_id, recorder_name, status,
                          created_at, created_by, created_by_name)
                      VALUES (?,?,?,?,?,?,?,?,?, 'draft', NOW(), ?, ?)")
           ->execute([$subject, $mdate, $stime, $etime, $place,
                      $chair['id'], $chair['name'], $uid, $uname, $uid, $uname]);
        $mid = (int)$db->lastInsertId();

        $r = ia_meeting_attendees_apply($db, $mid, $cid, $team, $chair, $mdate);

        $db->prepare("UPDATE ia_case SET `$col`=?, updated_at=NOW() WHERE case_id=?")->execute([$mid, $cid]);
        $db->commit();
        jout(['meeting_id' => $mid, 'existed' => false, 'attendees' => $r['count'],
              'from_team' => $r['from_team'], 'shifted' => $r['shifted'], 'dropped' => $r['dropped'],
              'meeting_date' => $mdate]);
    } catch (Throwable $e) { $db->rollBack(); jerr('建立會議紀錄失敗：' . $e->getMessage(), 500); }
}

/* 依目前的稽核小組「重新帶入與會人員」（2026-09-17 使用者要求）：
   會議建立之後才改小組時，原本只能「解除連結→再建一次」，會多出一筆用不到的會議紀錄。
   這裡只重寫 meeting_attendee，**不動會議本身**（主題／日期／地點／會議要項都保留），
   已經簽到的人保留簽到狀態；被移出名單而且簽到過的人會回報給前端講明。 */
case 'meeting_sync_att': {
    iaReqAdmin($perms);
    /* meeting_attendee.alt_posts（兼任職務顯示欄）由會議模組的 ensure 建立＝唯一實作。
       **一定要在 beginTransaction 之前呼叫**：裡面是 DDL，在交易中執行會造成隱式 commit。 */
    require_once $document_root . '/EGsystem/src/common/meeting_lib.php';
    meeting_ensure_schema($db);
    $cid  = (int)($_POST['case_id'] ?? 0);
    $kind = (string)($_POST['kind'] ?? '');
    if (!in_array($kind, ['pre', 'end'], true)) jerr('會議種類不正確');
    $st = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$cid]); $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到這張稽核通知單', 404);
    $col = $kind === 'pre' ? 'pre_meeting_id' : 'end_meeting_id';
    $mid = (int)($c[$col] ?? 0);
    if (!$mid) jerr('這張通知單還沒有連結會議紀錄');
    $mq = $db->prepare("SELECT meeting_id, meeting_date, status FROM meeting_record WHERE meeting_id=?");
    $mq->execute([$mid]); $m = $mq->fetch(PDO::FETCH_ASSOC);
    if (!$m) jerr('找不到該筆會議紀錄，請先解除連結');
    // 已送簽核／已完成的會議不給改名單（那是已經在跑簽核的正式文件）
    if (!in_array((string)$m['status'], ['draft', 'rejected'], true)) {
        jerr('這筆會議紀錄已經送出簽核（狀態：' . $m['status'] . '），出席名單已鎖定，無法重新帶入');
    }

    $year  = (int)$c['year'];
    $team  = ia_team_get($db, $year);
    $chair = ia_meeting_chair($db, $c, $team, $uid, $uname);
    $mdate = (string)$m['meeting_date'] ?: $today;

    $db->beginTransaction();
    try {
        $r = ia_meeting_attendees_apply($db, $mid, $cid, $team, $chair, $mdate);
        $db->prepare("UPDATE meeting_record SET chair_user_id=?, chair_name=?, updated_at=NOW() WHERE meeting_id=?")
           ->execute([$chair['id'], $chair['name'], $mid]);
        $db->commit();
        jout(['meeting_id' => $mid, 'attendees' => $r['count'], 'from_team' => $r['from_team'],
              'shifted' => $r['shifted'], 'dropped' => $r['dropped'], 'removed' => $r['removed'],
              'meeting_date' => $mdate]);
    } catch (Throwable $e) { $db->rollBack(); jerr('重新帶入與會人員失敗：' . $e->getMessage(), 500); }
}

case 'meeting_link': {
    // 改綁到既有的會議紀錄（使用者自己先建好的情況）
    iaReqAdmin($perms);
    $cid  = (int)($_POST['case_id'] ?? 0);
    $kind = (string)($_POST['kind'] ?? '');
    if (!in_array($kind, ['pre', 'end'], true)) jerr('會議種類不正確');
    $mid = iaInt($_POST['meeting_id'] ?? '');
    if ($mid) {
        $q = $db->prepare("SELECT 1 FROM meeting_record WHERE meeting_id=?"); $q->execute([$mid]);
        if (!$q->fetchColumn()) jerr('找不到這筆會議紀錄');
    }
    $col = $kind === 'pre' ? 'pre_meeting_id' : 'end_meeting_id';
    $db->prepare("UPDATE ia_case SET `$col`=?, updated_at=NOW() WHERE case_id=?")->execute([$mid, $cid]);
    jout(['saved' => true]);
}

case 'meeting_options': {
    iaReqView($perms);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    try {
        $st = $db->prepare("SELECT meeting_id, subject, meeting_date, status FROM meeting_record
                             WHERE YEAR(meeting_date)=? ORDER BY meeting_date DESC, meeting_id DESC LIMIT 200");
        $st->execute([$year]);
        jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) { jout(['rows' => []]); }
}

/* ============================ 列印中繼資料 ============================ */
case 'print_meta': {
    // 讀取不卡管理員（ai-rules/18 鐵則9：卡了一般人列印永遠拿不到圖章模板）
    $key = (string)($_GET['key'] ?? '');
    if (!isset(IA_ASDOC_MODULES[$key])) jerr('表單代碼不正確');
    $biz = iaDate($_GET['biz_date'] ?? '');
    $mod = IA_ASDOC_MODULES[$key];
    $doc = eg_asdoc_get($db, $mod['module']);
    $docId = (int)($doc['id'] ?? 0);
    // 版次依業務日期回推（ai-rules/16 第三之四節），不是一律印現在最新版
    $docNo = $docId ? eg_asdoc_no_asof_id($db, $docId, $biz) : $mod['fallback'];

    $set = ia_settings($db);
    $tpl = ia_stamp_template($db);          // 含 schema，前端 eg_stamp.js 要吃它才畫得出模板章
    $ctx = ['leader_id' => iaInt($_GET['leader_id'] ?? ''), 'leader_name' => (string)($_GET['leader_name'] ?? ''),
            'maker_id'  => iaInt($_GET['maker_id'] ?? ''),  'maker_name'  => (string)($_GET['maker_name'] ?? ''),
            'biz_date'  => $biz ?: $today];
    jout([
        'doc_no'    => $docNo,
        'doc_name'  => $doc['doc_name'] ?? $mod['label'],
        'company'   => eg_company_full_name($db),
        'stamp_tpl' => $tpl,
        'sign_approve' => ia_sign_person($db, (string)$set['ia_sign_approve'], $ctx),
        'sign_review'  => ia_sign_person($db, (string)$set['ia_sign_review'],  $ctx),
    ]);
}

/* 列印紀錄不在這裡自己寫一支：一律走共用的 EGPrintLog.record() → PrintSignLog_API，
   來源代碼 'internal_audit' 已登錄在 print_log_lib.php 的 eg_print_sources()（ai-rules/23）。
   兩條寫入路徑＝同一次列印可能記兩筆，故刻意不留。 */

/* ============================ 儀表板（首頁分頁用） ============================ */
case 'dashboard': {
    iaReqView($perms);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    $out  = ['year' => $year];
    try {
        $q = $db->prepare("SELECT COUNT(*) FROM ia_case WHERE year=? AND COALESCE(is_deleted,0)=0");
        $q->execute([$year]); $out['case_cnt'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT COUNT(*) FROM ia_case WHERE year=? AND COALESCE(is_deleted,0)=0 AND executed=1");
        $q->execute([$year]); $out['case_done'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT COUNT(*) FROM ia_check WHERE year=? AND COALESCE(is_deleted,0)=0");
        $q->execute([$year]); $out['check_cnt'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT stage, COUNT(*) c FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0 GROUP BY stage");
        $q->execute([$year]);
        $byStage = array_fill_keys(array_keys(IA_NC_STAGES), 0);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $byStage[(string)$r['stage']] = (int)$r['c'];
        $out['nc_by_stage'] = $byStage;
        $q = $db->prepare("SELECT nc_type, COUNT(*) c FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0 GROUP BY nc_type");
        $q->execute([$year]);
        $byType = array_fill_keys(array_keys(IA_NC_TYPES), 0);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($byType[(string)$r['nc_type']])) $byType[(string)$r['nc_type']] = (int)$r['c'];
        }
        $out['nc_by_type'] = $byType;
        $q = $db->prepare("SELECT COUNT(*) FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0
                            AND stage<>'closed' AND due_date IS NOT NULL AND due_date < ?");
        $q->execute([$year, $today]); $out['nc_overdue'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT nc_id, nc_no, dept_name, due_date, stage FROM ia_nc
                            WHERE year=? AND COALESCE(is_deleted,0)=0 AND stage<>'closed' AND due_date IS NOT NULL
                            ORDER BY due_date LIMIT 10");
        $q->execute([$year]); $out['nc_soon'] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $plan = ia_plan_get($db, $year);
    $out['has_plan'] = (bool)$plan;
    if ($plan) {
        $planned = count($plan['cells']); $actual = 0;
        foreach ($plan['cells'] as $k => $v) { if (isset($plan['actual'][$k])) $actual++; }
        $out['plan_planned'] = $planned;
        $out['plan_actual']  = $actual;
        $out['plan_extra']   = max(0, count($plan['actual']) - $actual);   // 沒排卻做了的
    }
    jout($out);
}


/* ============================ 受稽單位群組 ============================ */
case 'unit_list': {
    iaReqView($perms);
    jout(['units' => ia_audit_units($db), 'kinds' => IA_QUALIFY_KINDS]);
}

case 'unit_save': {
    iaReqAdmin($perms);
    $unitId  = (int)($_POST['unit_id'] ?? 0);
    $name    = trim((string)($_POST['unit_name'] ?? ''));
    $mainId  = (int)($_POST['main_dept_id'] ?? 0);
    $deptIds = json_decode((string)($_POST['dept_ids'] ?? '[]'), true);
    if (!is_array($deptIds)) $deptIds = [];
    $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds))));
    if (!$mainId && $deptIds) $mainId = $deptIds[0];
    // 前端擋一次、後端同規則再擋一次（鐵律8）
    $err = ia_unit_validate($db, $unitId, $name, $mainId, $deptIds);
    if ($err !== '') jerr($err);

    $db->beginTransaction();
    try {
        if ($unitId) {
            $db->prepare("UPDATE ia_audit_unit SET unit_name=?, main_dept_id=?, is_active=1,
                              updated_at=NOW(), updated_by=? WHERE unit_id=?")
               ->execute([$name, $mainId, $uname, $unitId]);
        } else {
            $db->prepare("INSERT INTO ia_audit_unit (unit_name, main_dept_id, sort_order, is_active, updated_at, updated_by)
                          VALUES (?,?,(SELECT COALESCE(MAX(s.sort_order),0)+10 FROM (SELECT sort_order FROM ia_audit_unit) s),1,NOW(),?)")
               ->execute([$name, $mainId, $uname]);
            $unitId = (int)$db->lastInsertId();
        }
        $db->prepare("DELETE FROM ia_audit_unit_dept WHERE unit_id=?")->execute([$unitId]);
        $ins = $db->prepare("INSERT INTO ia_audit_unit_dept (unit_id, dept_id) VALUES (?,?)");
        foreach ($deptIds as $d) $ins->execute([$unitId, $d]);
        // 已建立的計畫表／通知單上，原本各自成欄的成員部門要併回代表部門，否則畫面會出現重複的欄
        $db->prepare("UPDATE ia_plan_dept SET dept_id=?, dept_name=? WHERE dept_id IN
                      (SELECT dept_id FROM ia_audit_unit_dept WHERE unit_id=?) AND dept_id<>?")
           ->execute([$mainId, $name, $unitId, $mainId]);
        $db->prepare("UPDATE ia_plan_dept SET dept_name=? WHERE dept_id=?")->execute([$name, $mainId]);
        $db->commit();
        jout(['unit_id' => $unitId]);
    } catch (Throwable $e) {
        $db->rollBack();
        // 同一個計畫表裡兩個成員部門都被列成欄時，併欄會撞 UNIQUE(plan_id,dept_id)
        if (strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
            jerr('有年度計畫表同時列了這個群組裡的兩個以上部門當欄位，請先到該年度計畫表的「受稽單位」把多餘的欄取消勾選，再建立群組');
        }
        jerr('儲存失敗：' . $e->getMessage(), 500);
    }
}

case 'unit_delete': {
    iaReqAdmin($perms);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $db->beginTransaction();
    try {
        // 解散群組：成員部門各自變回獨立的受稽單位，既有資料仍掛在代表部門上不動
        $db->prepare("DELETE FROM ia_audit_unit_dept WHERE unit_id=?")->execute([$unitId]);
        $db->prepare("DELETE FROM ia_audit_unit WHERE unit_id=?")->execute([$unitId]);
        $db->commit();
        jout(['deleted' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：' . $e->getMessage(), 500); }
}

/* ============================ 稽核員／陪檢員資格名單 ============================ */
case 'qualify_get': {
    iaReqView($perms);
    // jobs＝可以挑的「部門＋職稱」一列一個（職位規則：該職務上的人都有資格）
    // users＝「職位＋指定人員」規則（可帶任期）；posts＝指定人員時可以挑的職務清單
    $map = ia_qualify_map($db);
    $sel = [];
    foreach ($map as $ks) foreach ($ks as $k) $sel[$k] = 1;
    $posts = [];
    try {
        // 指定人員通常是「代理某位請假／離職的人」，所以候選要含**當時在職、現已離職**的人；
        // 沒有單據日期可依，這裡用今天＋近三年當範圍，一律走共用的 asof 清單（ai-rules/22）
        $seen = [];
        foreach ([$today, substr($today, 0, 4) . '-01-01',
                  ((int)substr($today, 0, 4) - 1) . '-07-01',
                  ((int)substr($today, 0, 4) - 2) . '-07-01'] as $d) {
            foreach (eg_people_posts_asof($db, [], $d) as $p) {
                $k = ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id']);
                if (isset($seen[$k])) continue;
                $seen[$k] = 1;
                $p['post_key3'] = $k;
                $posts[] = $p;
            }
        }
        usort($posts, fn($a, $b) => [$a['dept_sort'], (int)$a['dept_id'], $a['position_sort'], $a['user_cname']]
                                <=> [$b['dept_sort'], (int)$b['dept_id'], $b['position_sort'], $b['user_cname']]);
    } catch (Throwable $e) {}
    // AS 文件負責人自動具備稽核員資格（期間＝as_document_management 的任期設定）——唯讀顯示用
    $asTerms = [];
    try {
        require_once __DIR__ . '/../common/asdoc_editor_lib.php';
        $asTerms = eg_asdoc_editor_terms($db);
    } catch (Throwable $e) {}
    jout(['kinds' => IA_QUALIFY_KINDS, 'map' => $map, 'jobs' => ia_job_options($db, array_keys($sel)),
          'users' => ia_qualify_users($db), 'posts' => $posts, 'as_terms' => $asTerms]);
}

case 'qualify_save': {
    iaReqAdmin($perms);
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(IA_QUALIFY_KINDS[$kind])) jerr('身分別不正確');
    // job_keys＝'deptId:posId'（現制）；post_keys/user_ids 是舊參數名，留著相容
    $ids = json_decode((string)($_POST['job_keys'] ?? $_POST['post_keys'] ?? $_POST['user_ids'] ?? '[]'), true);
    if (!is_array($ids)) jerr('格式錯誤');
    // user_rules：沒送＝這次不動指定人員那一段（與製表人同一套「有沒有送」的判別法）
    $userRules = null;
    if (array_key_exists('user_rules', $_POST)) {
        $userRules = json_decode((string)$_POST['user_rules'], true);
        if (!is_array($userRules)) jerr('指定人員格式錯誤');
    }
    $db->beginTransaction();
    $dropped = [];
    try {
        $dropped = ia_qualify_save($db, $kind, $ids, $uname, $userRules);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    // 存完讀回來確認（存不進去卻回成功，使用者只會一直重存）
    $back  = ia_qualify_map($db);
    $backU = ia_qualify_users($db);
    jout(['saved' => true, 'count' => count($back[$kind] ?? []),
          'user_count' => count($backU[$kind] ?? []), 'dropped' => count($dropped)]);
}

/* ============================ 稽核小組（年度） ============================ */
case 'team_get': {
    iaReqView($perms);
    $y = (int)($_GET['year'] ?? $_POST['year'] ?? substr($today, 0, 4));
    /* 基準日：使用者在畫面上改日期時會帶 base_date 進來「試算」（還沒存也看得到結果）；
       沒帶就用已存的設定值，再沒有才用系統推算值。候選人員、在職判定與部門職稱全部依它。 */
    $preview  = iaDate($_GET['base_date'] ?? $_POST['base_date'] ?? '');
    $saved    = ia_team_base_date($db, $y, true);            // 使用者設過的值（沒設＝空）
    $asof     = $preview ?: ia_team_base_date($db, $y);      // 實際採用的基準日
    $default  = ia_team_base_date($db, $y, false);
    // 挑成員的候選＝該年度**有稽核員或陪檢員資格**的職務（資格名單留空時就是全體）
    $cands = []; $seen = [];
    foreach (['auditor', 'escort'] as $k) {
        foreach (ia_qualified_posts($db, $k, $asof) as $p) {
            if (isset($seen[$p['post_key3']])) continue;
            $seen[$p['post_key3']] = 1;
            $cands[] = $p;
        }
    }
    usort($cands, fn($a, $b) => [$a['dept_sort'], (int)$a['dept_id'], $a['position_sort'], $a['user_cname']]
                            <=> [$b['dept_sort'], (int)$b['dept_id'], $b['position_sort'], $b['user_cname']]);
    jout(['year' => $y, 'asof' => $asof, 'roles' => IA_TEAM_ROLES,
          'base_date' => $saved,                 // 使用者設過的基準日（空＝沿用系統推算）
          'base_default' => $default,            // 系統推算值（過去年度＝該年年底／當年以後＝今天）
          'members' => ia_team_get($db, $y, $asof), 'candidates' => $cands,
          'years' => ia_team_years($db)]);
}

case 'team_save': {
    iaReqAdmin($perms);
    $y = (int)($_POST['year'] ?? 0);
    $ms = json_decode((string)($_POST['members'] ?? '[]'), true);
    if (!is_array($ms)) jerr('格式錯誤');
    // 基準日跟名單一起存（畫面上是同一張表單，分兩次存會出現「名單存了、日期沒存」）。
    // 有送這個欄位才動它：沒送＝舊呼叫端，不要把既有設定清掉（與製表人同一套判別法）。
    $bd = null;
    if (array_key_exists('base_date', $_POST)) {
        $raw = trim((string)$_POST['base_date']);
        if ($raw !== '' && !iaDate($raw)) jerr('基準日格式不正確');
        $bd = $raw;
    }
    $db->beginTransaction();
    try {
        if ($bd !== null) ia_team_set_base_date($db, $y, $bd, $uname);
        $n = ia_team_save($db, $y, $ms, $uname, (string)($bd ?? ''));
        $db->commit();
        jout(['saved' => true, 'count' => $n, 'members' => ia_team_get($db, $y),
              'base_date' => ia_team_base_date($db, $y, true), 'asof' => ia_team_base_date($db, $y)]);
    } catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
}

case 'team_copy': {
    iaReqAdmin($perms);
    $from = (int)($_POST['from_year'] ?? 0);
    $to   = (int)($_POST['to_year'] ?? 0);
    if ($from === $to) jerr('來源年度與目標年度相同');
    $db->beginTransaction();
    try {
        list($n, $skipped) = ia_team_copy($db, $from, $to, $uname);
        $db->commit();
        jout(['saved' => true, 'count' => $n, 'skipped' => $skipped, 'members' => ia_team_get($db, $to)]);
    } catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
}

/* 依業務日期回推的人員清單（ai-rules/22）——補歷史單據時要挑得到「當時在職、現已離職」的人，
   職稱也要是當時的職稱。前端在開單／改日期時呼叫，把 META.people／auditors／escorts 換成該日期版本。 */
case 'people_asof': {
    iaReqView($perms);
    $d = iaDate($_GET['date'] ?? $_POST['date'] ?? '') ?: $today;
    jout([
        'date'      => $d,
        'people'    => ia_annotate_posts($db, eg_people_list_asof($db, [], $d), $d),
        'auditors'  => ia_qualified_posts($db, 'auditor', $d),
        'escorts'   => ia_qualified_posts($db, 'escort',  $d),
        'templates' => ia_process_templates($db, true, $d),
    ]);
}


/* ============================ 稽核範本 ============================ */
case 'tpl_list': {
    iaReqView($perms);
    jout(['rows' => ia_process_templates($db, false), 'units' => ia_audit_units($db)]);
}

case 'tpl_save': {
    iaReqAdmin($perms);
    $tplId  = (int)($_POST['tpl_id'] ?? 0);
    $name   = trim((string)($_POST['process_name'] ?? ''));
    $unitId = (int)($_POST['unit_dept_id'] ?? 0);
    $aDepts = json_decode((string)($_POST['auditor_dept_ids'] ?? '[]'), true);
    $eDepts = json_decode((string)($_POST['escort_dept_ids'] ?? '[]'), true);
    $aDepts = is_array($aDepts) ? array_values(array_unique(array_filter(array_map('intval', $aDepts)))) : [];
    $eDepts = is_array($eDepts) ? array_values(array_unique(array_filter(array_map('intval', $eDepts)))) : [];

    $err = ia_tpl_validate($db, $tplId, $name, $unitId, $aDepts, $eDepts);
    if ($err !== '') jerr($err);

    $db->beginTransaction();
    try {
        if ($tplId) {
            $db->prepare("UPDATE ia_process_template SET process_name=?, unit_dept_id=?, note=?,
                              is_active=?, updated_at=NOW(), updated_by=? WHERE tpl_id=?")
               ->execute([$name, $unitId, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null,
                          empty($_POST['is_active']) ? 0 : 1, $uname, $tplId]);
        } else {
            $db->prepare("INSERT INTO ia_process_template (process_name, unit_dept_id, note, sort_order,
                              is_active, updated_at, updated_by)
                          VALUES (?,?,?,(SELECT COALESCE(MAX(s.sort_order),0)+10
                                         FROM (SELECT sort_order FROM ia_process_template) s),1,NOW(),?)")
               ->execute([$name, $unitId, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null, $uname]);
            $tplId = (int)$db->lastInsertId();
        }
        $db->prepare("DELETE FROM ia_process_tpl_dept WHERE tpl_id=?")->execute([$tplId]);
        $ins = $db->prepare("INSERT INTO ia_process_tpl_dept (tpl_id, kind, dept_id) VALUES (?,?,?)");
        foreach ($aDepts as $d) $ins->execute([$tplId, 'auditor', $d]);
        foreach ($eDepts as $d) $ins->execute([$tplId, 'escort',  $d]);
        $db->commit();
        jout(['tpl_id' => $tplId]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'tpl_delete': {
    iaReqAdmin($perms);
    $tplId = (int)($_POST['tpl_id'] ?? 0);
    $db->beginTransaction();
    try {
        // 範本只是「填表時的帶入來源」，已經填進通知單的內容是快照、不受影響，所以可以真的刪
        $db->prepare("DELETE FROM ia_process_tpl_dept WHERE tpl_id=?")->execute([$tplId]);
        // 被刪掉的範本要從所有組合裡一併移除，不然組合會留一列「（範本已刪除）」
        $db->prepare("DELETE FROM ia_process_tpl_set_item WHERE tpl_id=?")->execute([$tplId]);
        $db->prepare("DELETE FROM ia_process_template WHERE tpl_id=?")->execute([$tplId]);
        $db->commit();
        jout(['deleted' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：' . $e->getMessage(), 500); }
}

/* ============================ 稽核範本組合（一次帶入多列） ============================ */
case 'tplset_list': {
    iaReqView($perms);
    jout(['rows' => ia_tpl_sets($db, false), 'templates' => ia_process_templates($db, false)]);
}

case 'tplset_save': {
    iaReqAdmin($perms);
    $setId = (int)($_POST['set_id'] ?? 0);
    $name  = trim((string)($_POST['set_name'] ?? ''));
    $ids   = json_decode((string)($_POST['tpl_ids'] ?? '[]'), true);
    $ids   = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];

    $err = ia_tplset_validate($db, $setId, $name, $ids);
    if ($err !== '') jerr($err);

    $db->beginTransaction();
    try {
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
        if ($setId) {
            $db->prepare("UPDATE ia_process_tpl_set SET set_name=?, note=?, is_active=?,
                              updated_at=NOW(), updated_by=? WHERE set_id=?")
               ->execute([$name, $note, empty($_POST['is_active']) ? 0 : 1, $uname, $setId]);
        } else {
            $db->prepare("INSERT INTO ia_process_tpl_set (set_name, note, sort_order, is_active, updated_at, updated_by)
                          VALUES (?,?,(SELECT COALESCE(MAX(s.sort_order),0)+10
                                       FROM (SELECT sort_order FROM ia_process_tpl_set) s),1,NOW(),?)")
               ->execute([$name, $note, $uname]);
            $setId = (int)$db->lastInsertId();
        }
        $db->prepare("DELETE FROM ia_process_tpl_set_item WHERE set_id=?")->execute([$setId]);
        $ins = $db->prepare("INSERT INTO ia_process_tpl_set_item (set_id, tpl_id, sort_order) VALUES (?,?,?)");
        $i = 0;
        foreach ($ids as $t) $ins->execute([$setId, $t, $i += 10]);   // 勾選順序＝帶入通知單的列順序
        $db->commit();
        jout(['set_id' => $setId]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'tplset_delete': {
    iaReqAdmin($perms);
    $setId = (int)($_POST['set_id'] ?? 0);
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM ia_process_tpl_set_item WHERE set_id=?")->execute([$setId]);
        $db->prepare("DELETE FROM ia_process_tpl_set WHERE set_id=?")->execute([$setId]);
        $db->commit();
        jout(['deleted' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：' . $e->getMessage(), 500); }
}

default:
    jerr('無效的操作：' . $action);
}
