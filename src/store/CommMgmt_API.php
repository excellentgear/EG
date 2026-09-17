<?php
/**
 * 溝通管理（3-GM-01）API —— 2026-09-14 建立
 *
 * 權限（comm_mgmt_lib.php cm_perms()，roles module='comm_mgmt'）：
 *   cm_admin 溝通管理員：模組設定、管制表/追蹤表維護、刪除、代其他人建單、看全部
 *   cm_view  檢閱：唯讀看全部溝通記錄
 *   其餘在職員工：建立/編輯自己的溝通記錄、確認指派給自己的那一關
 * 前端擋一次、後端同規則再擋一次（鐵律8），不留只擋 UI 的漏洞。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/comm_mgmt_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function jout($a = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    cm_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['cm_csrf'])) $_SESSION['cm_csrf'] = bin2hex(random_bytes(16));

$u = cm_current_user($db);
if (!$u) jerr('未登入', 401);
$P     = cm_perms($db, $u);
$uid   = (int)$P['uid'];
$uname = (string)$P['name'];
if (!$uid) jerr('無溝通管理使用權限（帳號非在職狀態）', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* 寫入類動作先驗登入再驗 CSRF。順序不可顛倒：session 被 GC 掃掉時 token 會在同一個請求裡重新
   產生、比對必定不過，但那其實是「已經被登出」不是 CSRF 攻擊，訊息講錯使用者只會一直重整卻
   永遠存不進去（見 src/common/_config.php 的防護說明）。 */
$WRITE = ['rec_save','rec_delete','rec_submit','rec_decide','rec_to_track',
          'att_upload','att_delete','track_save','track_delete','ctrl_save','ctrl_delete',
          'setting_save','asdoc_save'];
if (in_array($action, $WRITE, true)) {
    if ($uid <= 0) jerr('登入已逾時，請重新登入後再儲存（您填的內容還在，重新登入後再按一次即可）', 401, ['code'=>'LOGIN']);
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['cm_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code'=>'CSRF']);
}

/* ---------------------------------------------------------------- 小工具 */

function cmDate($v): ?string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
function cmS($v, int $max = 500): string { return mb_substr(trim((string)$v), 0, $max); }
function cmReqAdmin(array $P) { if (!$P['canAdmin']) jerr('需要溝通管理員權限', 403); }
/** DB 時間（PHP date() 是 UTC、MySQL NOW() 是本地，混用會差 8 小時——時間戳一律取 DB 的） */
function cmNow(PDO $db): array {
    try { $r = $db->query("SELECT NOW() AS n, CURDATE() AS d")->fetch(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return ['n'=>date('Y-m-d H:i:s'), 'd'=>date('Y-m-d')]; }
    return ['n'=>(string)$r['n'], 'd'=>(string)$r['d']];
}

/** 一張單的目前簽核關卡（'' ＝ 不在待簽狀態） */
function cmStage(array $r): string {
    $s = (string)$r['status'];
    if ($s === 'mgr_wait') return CM_LEVEL_MGR;
    if ($s === 'gm_wait')  return CM_LEVEL_GM;
    return '';
}

/** 該關卡的合格簽核池 */
function cmPoolOf(PDO $db, array $r, string $level): array {
    $biz = (string)($r['comm_date'] ?: date('Y-m-d'));
    if ($level === CM_LEVEL_GM) return cm_gm_pool($db, $biz);
    $res = cm_dept_manager_pool($db, (int)$r['maker_id'], (int)$r['maker_dept_id'], (int)$r['maker_pos_sort'], $biz);
    return $res['pool'];
}

/** 這個人看不看得到這一張單 */
function cmCanSee(PDO $db, array $r, array $P, int $uid): bool {
    if ($P['canView']) return true;
    if ((int)$r['maker_id'] === $uid || (int)$r['created_by'] === $uid) return true;
    // 待你簽的那一關即使沒有任何角色也要看得到，否則通知點進來是空白頁
    $stage = cmStage($r);
    if ($stage !== '' && cm_can_sign($db, cmPoolOf($db, $r, $stage), $uid)['ok']) return true;
    // 簽過的人回頭查得到自己簽過什麼
    return (int)$r['mgr_user_id'] === $uid || (int)$r['gm_user_id'] === $uid;
}

/** 這個人改不改得動這一張單（草稿階段的本人／管理員） */
function cmCanEdit(array $r, array $P, int $uid): bool {
    if ($P['canAdmin']) return true;
    return (string)$r['status'] === 'draft' && ((int)$r['maker_id'] === $uid || (int)$r['created_by'] === $uid);
}

function cmRecGet(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM comm_record WHERE rec_id=? AND is_deleted=0");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function cmItems(PDO $db, int $recId): array {
    $st = $db->prepare("SELECT * FROM comm_record_item WHERE rec_id=? ORDER BY seq, item_id");
    $st->execute([$recId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function cmAttaches(PDO $db, int $recId, string $tempKey = ''): array {
    if ($recId > 0) {
        $st = $db->prepare("SELECT * FROM comm_record_attach WHERE rec_id=? AND status='active' ORDER BY att_id");
        $st->execute([$recId]);
    } else {
        if ($tempKey === '') return [];
        $st = $db->prepare("SELECT * FROM comm_record_attach WHERE temp_key=? AND rec_id IS NULL ORDER BY att_id");
        $st->execute([$tempKey]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------------------------------------------------------------- 通知（ai-rules/17） */

function cmCloseNotice(PDO $db, int $recId): void {
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='COMM_REC_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$recId]);
    } catch (Throwable $e) {}
}

function cmNotifySign(PDO $db, array $r, array $pool, string $level, int $fromUid): void {
    if (!$pool) return;
    $what  = $level === CM_LEVEL_GM ? '總經理確認' : '部門主管確認';
    $title = '利害關係者溝通記錄待' . $what . '：' . ($r['party_name'] ?: '') . '　' . str_replace('-', '.', (string)$r['comm_date']);
    $body  = '單號：' . ($r['rec_no'] ?: ('#' . $r['rec_id'])) . "\n"
           . '填表人：' . $r['maker_name'] . '（' . $r['maker_dept_name'] . '　' . $r['maker_pos_name'] . '）' . "\n"
           . '利害關係者：' . ($r['party_name'] ?: '（未填）') . "\n"
           . '溝通日期：' . str_replace('-', '.', (string)$r['comm_date']) . "\n"
           . '點此開啟溝通記錄表，可直接確認或退回（退回須填原因）。';
    try {
        cmCloseNotice($db, (int)$r['rec_id']);
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '溝通管理', 1, 'COMM_REC_APPROVAL', ?)")
           ->execute([$title, $body, $fromUid, (int)$r['rec_id']]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')");
        $seen = [];
        foreach ($pool as $p) {
            $tid = (int)$p['id'];
            if ($tid <= 0 || isset($seen[$tid])) continue;
            $seen[$tid] = 1;
            $ins->execute([$eid, $tid]);
        }
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($body, 0, 480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

function cmNotifyResult(PDO $db, array $r, int $toUid, string $decision, string $note, int $fromUid, string $byName): void {
    if ($toUid <= 0) return;
    $ok    = ($decision === 'approved');
    $title = '溝通記錄表' . ($ok ? '已完成確認' : '被退回') . '：' . ($r['rec_no'] ?: ('#' . $r['rec_id']));
    $body  = $byName . ($ok ? ' 已確認您的利害關係者溝通記錄表。' . ($note ? '意見：' . $note : '')
                            : ' 退回您的利害關係者溝通記錄表。退回原因：' . $note);
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '溝通管理', 1, 'COMM_REC_RESULT', ?)")
           ->execute([$title, $body, $fromUid, (int)$r['rec_id']]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
           ->execute([$eid, $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($body, 0, 480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

/* ================================================================ 動作 */

/* ---- meta：頁面載入抓一次 ---- */
if ($action === 'meta') {
    $set = cm_settings($db);
    jout([
        'csrf'      => (string)$_SESSION['cm_csrf'],
        'perms'     => $P,
        'role_label'=> cm_role_label($P),
        'me'        => ['id'=>$uid, 'name'=>$uname],
        'today'     => cmNow($db)['d'],
        'company'   => eg_company_full_name($db),
        'settings'  => $set,
        'asdoc'     => cm_asdoc_all($db),
        'asdoc_list'=> $P['canAdmin'] ? eg_asdoc_list($db) : [],
        'stamp_tpl' => cm_stamp_template($db),
        'stamp_list'=> $P['canAdmin'] ? cm_stamp_template_list($db) : [],
        'ranks'     => cm_rank_list($db),
        'types'     => CM_TYPES, 'kinds' => CM_PARTY_KINDS, 'channels' => CM_CHANNELS, 'status' => CM_STATUS,
        'freq_units'=> CM_FREQ_UNITS,
        'need_sign' => ((string)($set['cm_need_sign'] ?? '1')) !== '0',
        'depts'     => cm_dept_list($db),
        'people'    => eg_people_list($db, []),
    ]);
}

/* ---- 依類別連動挑對象：客戶／供應商模糊搜尋（打名稱或編號都找得到） ---- */
if ($action === 'party_search') {
    $kind = cmS($_GET['kind'] ?? '', 12);
    if (!in_array($kind, ['customer', 'supplier'], true)) jerr('只有客戶與供應商可以搜尋');
    jout(['rows' => cm_party_search($db, $kind, cmS($_GET['kw'] ?? '', 60))]);
}

/* ---- 某客戶／供應商底下的聯絡人（代表人下拉；沒登錄聯絡人時前端仍可手動輸入） ---- */
if ($action === 'party_contacts') {
    $kind = cmS($_GET['kind'] ?? '', 12);
    if (!in_array($kind, ['customer', 'supplier'], true)) jout(['rows' => []]);
    jout(['rows' => cm_party_contacts($db, $kind, cmS($_GET['ref_id'] ?? '', 40))]);
}

/* ---- 某部門底下的人員（依業務日期回推當時職務，主職與兼任都列＝ai-rules/22 + 08 第五節） ---- */
if ($action === 'dept_people') {
    $d = cmDate($_GET['date'] ?? '') ?: cmNow($db)['d'];
    jout(['rows' => cm_dept_people($db, (int)($_GET['dept_id'] ?? 0), $d, !empty($_GET['include_sub'])), 'date' => $d]);
}

/* ---- 填表身分＋主管解析預覽（建單時就讓使用者看到會送給誰簽） ---- */
if ($action === 'identities') {
    $target = (int)($_GET['user_id'] ?? 0);
    if ($target <= 0 || (!$P['canAdmin'] && $target !== $uid)) $target = $uid;
    $biz  = cmDate($_GET['date'] ?? '') ?: cmNow($db)['d'];
    $list = cm_identities($db, $target, $biz);
    foreach ($list as $i => $idn) {
        $res = cm_dept_manager_pool($db, $target, (int)$idn['department_id'], (int)$idn['pos_sort'], $biz);
        $list[$i]['mgr_pool'] = $res['pool'];
        $list[$i]['mgr_skip'] = $res['skip'];
    }
    jout(['identities'=>$list, 'gm_pool'=>cm_gm_pool($db, $biz), 'date'=>$biz]);
}

/* ---- 溝通記錄表：清單 ---- */
if ($action === 'rec_list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per'] ?? 20);
    if (!in_array($per, [5,10,20,50], true)) $per = 20;
    $w = ['r.is_deleted=0']; $a = [];
    if (($s = cmS($_GET['status'] ?? '', 20)) !== '' && isset(CM_STATUS[$s])) { $w[] = 'r.status=?'; $a[] = $s; }
    if (($k = cmS($_GET['kind'] ?? '', 20)) !== '' && isset(CM_PARTY_KINDS[$k])) { $w[] = 'r.party_kind=?'; $a[] = $k; }
    if (($d1 = cmDate($_GET['from'] ?? '')))  { $w[] = 'r.comm_date>=?'; $a[] = $d1; }
    if (($d2 = cmDate($_GET['to'] ?? '')))    { $w[] = 'r.comm_date<=?'; $a[] = $d2; }
    if (!empty($_GET['mine']))                { $w[] = 'r.maker_id=?';   $a[] = $uid; }
    $kw = cmS($_GET['kw'] ?? '', 60);
    if ($kw !== '') {
        // 全表搜尋鐵則：LIKE 掃過畫面上看得到的欄位，多關鍵字＝每個都要命中（可分散在不同欄位）
        foreach (preg_split('/\s+/', $kw, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $w[] = "(r.rec_no LIKE ? OR r.party_name LIKE ? OR r.party_kind_other LIKE ? OR r.maker_name LIKE ?
                     OR r.maker_dept_name LIKE ? OR r.ch_other_text LIKE ? OR r.remark LIKE ?
                     OR EXISTS(SELECT 1 FROM comm_record_item i WHERE i.rec_id=r.rec_id AND (i.question LIKE ? OR i.reply LIKE ?)))";
            for ($i = 0; $i < 9; $i++) $a[] = '%' . $t . '%';
        }
    }
    // 沒有檢閱權的人只看得到自己的單＋要自己簽的單（後端強制，鐵律8）
    if (!$P['canView']) {
        $w[] = '(r.maker_id=? OR r.created_by=? OR r.mgr_user_id=? OR r.gm_user_id=? OR r.status IN (\'mgr_wait\',\'gm_wait\'))';
        array_push($a, $uid, $uid, $uid, $uid);
    }
    $where = implode(' AND ', $w);

    $st = $db->prepare("SELECT r.* FROM comm_record r WHERE $where ORDER BY r.comm_date DESC, r.rec_id DESC");
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // 「待簽」那段是粗篩，逐筆用同一套判定精算（避免把別人待簽的單也列給沒權限的人看）
    if (!$P['canView']) {
        $rows = array_values(array_filter($rows, function ($r) use ($db, $P, $uid) { return cmCanSee($db, $r, $P, $uid); }));
    }
    $total = count($rows);
    $rows  = array_slice($rows, ($page - 1) * $per, $per);
    foreach ($rows as $i => $r) {
        $rows[$i]['items']    = cmItems($db, (int)$r['rec_id']);
        // 清單上就要看得到附件數量並能直接點開（使用者要求），所以連附件一起帶回來
        $rows[$i]['attaches'] = cmAttaches($db, (int)$r['rec_id']);
        $stage = cmStage($r);
        $rows[$i]['can_sign'] = $stage !== '' && cm_can_sign($db, cmPoolOf($db, $r, $stage), $uid)['ok'];
        $rows[$i]['can_edit'] = cmCanEdit($r, $P, $uid);
    }
    jout(['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per]);
}

/* ---- 溝通記錄表：單筆 ---- */
if ($action === 'rec_get') {
    $r = cmRecGet($db, (int)($_GET['id'] ?? 0));
    if (!$r) jerr('查無此溝通記錄表', 404);
    if (!cmCanSee($db, $r, $P, $uid)) jerr('無權檢視此單', 403);
    $stage = cmStage($r);
    $sign  = $stage !== '' ? cm_can_sign($db, cmPoolOf($db, $r, $stage), $uid) : ['ok'=>false,'deputy'=>false,'for_name'=>''];
    $biz   = (string)($r['comm_date'] ?: cmNow($db)['d']);
    $mgr   = cm_dept_manager_pool($db, (int)$r['maker_id'], (int)$r['maker_dept_id'], (int)$r['maker_pos_sort'], $biz);
    jout([
        'rec'      => $r,
        'items'    => cmItems($db, (int)$r['rec_id']),
        'attaches' => cmAttaches($db, (int)$r['rec_id']),
        'stage'    => $stage,
        'can_sign' => $sign['ok'], 'sign_deputy' => $sign['deputy'], 'sign_for' => $sign['for_name'],
        'can_edit' => cmCanEdit($r, $P, $uid),
        'mgr_pool' => $mgr['pool'], 'gm_pool' => cm_gm_pool($db, $biz),
        'print'    => cm_print_meta($db, 'record', $biz),
    ]);
}

/* ---- 溝通記錄表：新增/編輯 ---- */
if ($action === 'rec_save') {
    $id  = (int)($_POST['rec_id'] ?? 0);
    $old = $id ? cmRecGet($db, $id) : null;
    if ($id && !$old) jerr('查無此溝通記錄表', 404);
    if ($old && !cmCanEdit($old, $P, $uid)) jerr('此單已送出，無法修改（如需修改請退回草稿或洽管理員）', 403);

    $commDate = cmDate($_POST['comm_date'] ?? '');
    if (!$commDate) jerr('請填寫溝通日期');
    $type = cmS($_POST['comm_type'] ?? '', 12);
    if (!isset(CM_TYPES[$type])) jerr('請選擇型態（定期／不定期）');
    $kind = cmS($_POST['party_kind'] ?? '', 12);
    if (!isset(CM_PARTY_KINDS[$kind])) jerr('請選擇類別');
    $kindOther = cmS($_POST['party_kind_other'] ?? '', 100);
    if ($kind === 'other' && $kindOther === '') jerr('類別選「其他」時請填寫說明');
    $party = cmS($_POST['party_name'] ?? '', 200);
    if ($party === '') jerr('請填寫利害關係者公司/代表人');

    /* 依類別驗證挑到的對象（前端連動選完後，後端用同一批資料來源再核對一次＝鐵律8：
       不可只擋 UI，直打 API 就能塞一個根本不存在的客戶編號或別部門的人進來） */
    $partyRef     = cmS($_POST['party_ref_id'] ?? '', 40);
    $partyUser    = (int)($_POST['party_user_id'] ?? 0);
    $partyCtcId   = (int)($_POST['party_contact_id'] ?? 0);
    $partyCtcName = cmS($_POST['party_contact_name'] ?? '', 100);
    if ($kind === 'customer' || $kind === 'supplier') {
        if ($partyRef === '') jerr($kind === 'customer' ? '請從清單選擇客戶' : '請從清單選擇供應商');
        $hit = null;
        foreach (cm_party_search($db, $kind, $partyRef, 50) as $x) if ((string)$x['id'] === $partyRef) { $hit = $x; break; }
        if (!$hit) jerr(($kind === 'customer' ? '客戶' : '供應商') . '編號不存在：' . $partyRef);
        $party = (string)($hit['full_name'] ?: $hit['name']);
        if ($partyCtcId > 0) {
            $ok = null;
            foreach (cm_party_contacts($db, $kind, $partyRef) as $ct) if ((int)$ct['contact_id'] === $partyCtcId) { $ok = $ct; break; }
            if (!$ok) jerr('這位聯絡人不屬於所選的對象，請重新選擇');
            $partyCtcName = (string)$ok['name'];
        }
        $partyUser = 0;
    } elseif ($kind === 'employee') {
        $deptRef = (int)$partyRef;
        if ($deptRef <= 0) jerr('請選擇員工所屬部門');
        if ($partyUser <= 0) jerr('請選擇員工');
        $ok = null;
        foreach (cm_dept_people($db, $deptRef, $commDate) as $p) if ((int)$p['id'] === $partyUser) { $ok = $p; break; }
        if (!$ok) jerr('這位員工不在所選部門（溝通日期當時），請重新選擇');
        $party        = $ok['dept_name'] . '　' . $ok['name'];
        $partyCtcName = $ok['name'] . ($ok['pos_name'] ? '（' . $ok['pos_name'] . '）' : '');
        $partyCtcId   = 0;
    } else {                       // other：完全手填，不綁任何主檔
        $partyRef = ''; $partyUser = 0; $partyCtcId = 0;
    }

    $ch = [];
    foreach (array_keys(CM_CHANNELS) as $c) $ch[$c] = !empty($_POST['ch_' . $c]) ? 1 : 0;
    if (!array_sum($ch)) jerr('請至少勾選一種溝通管道');
    $chOther = cmS($_POST['ch_other_text'] ?? '', 100);
    if ($ch['other'] && $chOther === '') jerr('管道勾選「其他」時請填寫說明');

    // 填表身分（兼任者自己選，使用者拍板③）：一律回後端重新驗一次是不是這個人真的有的身分
    $makerId = (int)($_POST['maker_id'] ?? 0);
    if ($makerId <= 0) $makerId = $uid;
    if ($makerId !== $uid && !$P['canAdmin']) jerr('只有溝通管理員可以代其他人建立溝通記錄', 403);
    $deptId = (int)($_POST['maker_dept_id'] ?? 0);
    $posId  = (int)($_POST['maker_pos_id'] ?? 0);
    $idn = null;
    foreach (cm_identities($db, $makerId, $commDate) as $x) {
        if ((int)$x['department_id'] === $deptId && (int)$x['position_id'] === $posId) { $idn = $x; break; }
    }
    if (!$idn) jerr('填表身分不正確（請重新選擇部門／職稱；溝通日期改過時身分清單會跟著變）');

    // 明細：至少要有一列填了溝通問題，否則這張表等於沒有內容
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) $items = [];
    $clean = [];
    foreach ($items as $it) {
        $q = cmS($it['question'] ?? '', 4000);
        $a = cmS($it['reply'] ?? '', 4000);
        if ($q === '' && $a === '') continue;
        $clean[] = ['question'=>$q, 'reply'=>$a, 'track_id'=>(int)($it['track_id'] ?? 0) ?: null,
                    'item_id'=>(int)($it['item_id'] ?? 0)];
    }
    if (!$clean) jerr('請至少填寫一項溝通問題');

    $now = cmNow($db);
    try {
        $db->beginTransaction();
        if ($id) {
            // 溝通日期改了就要重編單號（單號依表單上的日期，不是建檔日）
            $recNo = (string)$old['rec_no'];
            if ($recNo === '' || substr($recNo, 0, 8) !== str_replace('-', '', $commDate)) $recNo = cm_next_rec_no($db, $commDate);
            $db->prepare("UPDATE comm_record SET rec_no=?, comm_type=?, comm_date=?, party_kind=?, party_kind_other=?,
                            party_name=?, party_ref_id=?, party_user_id=?, party_contact_id=?, party_contact_name=?,
                            ch_phone=?, ch_face=?, ch_email=?, ch_meeting=?, ch_other=?, ch_other_text=?,
                            maker_id=?, maker_name=?, maker_dept_id=?, maker_dept_name=?, maker_pos_id=?, maker_pos_name=?,
                            maker_pos_sort=?, remark=?, updated_at=? WHERE rec_id=?")
               ->execute([$recNo, $type, $commDate, $kind, $kindOther, $party,
                          $partyRef ?: null, $partyUser ?: null, $partyCtcId ?: null, $partyCtcName ?: null,
                          $ch['phone'], $ch['face'], $ch['email'], $ch['meeting'], $ch['other'], $chOther,
                          $makerId, cm_user_name($db, $makerId), $idn['department_id'], $idn['department_name'],
                          $idn['position_id'], $idn['position_name'], $idn['pos_sort'],
                          cmS($_POST['remark'] ?? '', 500), $now['n'], $id]);
            $db->prepare("DELETE FROM comm_record_item WHERE rec_id=?")->execute([$id]);
        } else {
            $recNo = cm_next_rec_no($db, $commDate);
            $db->prepare("INSERT INTO comm_record (rec_no, comm_type, comm_date, party_kind, party_kind_other, party_name,
                            party_ref_id, party_user_id, party_contact_id, party_contact_name,
                            ch_phone, ch_face, ch_email, ch_meeting, ch_other, ch_other_text,
                            maker_id, maker_name, maker_dept_id, maker_dept_name, maker_pos_id, maker_pos_name, maker_pos_sort,
                            status, remark, created_by, created_by_name, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,?,?,?,?)")
               ->execute([$recNo, $type, $commDate, $kind, $kindOther, $party,
                          $partyRef ?: null, $partyUser ?: null, $partyCtcId ?: null, $partyCtcName ?: null,
                          $ch['phone'], $ch['face'], $ch['email'], $ch['meeting'], $ch['other'], $chOther,
                          $makerId, cm_user_name($db, $makerId), $idn['department_id'], $idn['department_name'],
                          $idn['position_id'], $idn['position_name'], $idn['pos_sort'],
                          cmS($_POST['remark'] ?? '', 500), $uid, $uname, $now['n'], $now['n']]);
            $id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare("INSERT INTO comm_record_item (rec_id, seq, question, reply, track_id) VALUES (?,?,?,?,?)");
        foreach ($clean as $i => $it) $ins->execute([$id, $i + 1, $it['question'], $it['reply'], $it['track_id']]);

        // 附件暫存轉正（鐵律5 的 temp/active）：新增中先傳的檔在這一刻認領給這張單
        $tk = cmS($_POST['temp_key'] ?? '', 40);
        if ($tk !== '') {
            $db->prepare("UPDATE comm_record_attach SET rec_id=?, status='active', temp_key=NULL
                          WHERE temp_key=? AND rec_id IS NULL")->execute([$id, $tk]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage(), 500);
    }
    jout(['rec_id'=>$id, 'rec_no'=>$recNo]);
}

/* ---- 溝通記錄表：刪除（軟刪） ---- */
if ($action === 'rec_delete') {
    $r = cmRecGet($db, (int)($_POST['rec_id'] ?? 0));
    if (!$r) jerr('查無此溝通記錄表', 404);
    if (!$P['canAdmin'] && !((string)$r['status'] === 'draft' && (int)$r['maker_id'] === $uid))
        jerr('只有草稿階段的填表人本人或溝通管理員可以刪除', 403);
    $db->prepare("UPDATE comm_record SET is_deleted=1, updated_at=? WHERE rec_id=?")->execute([cmNow($db)['n'], (int)$r['rec_id']]);
    cmCloseNotice($db, (int)$r['rec_id']);
    jout();
}

/* ---- 溝通記錄表：送出（拍板②的序列流程起點） ---- */
if ($action === 'rec_submit') {
    $r = cmRecGet($db, (int)($_POST['rec_id'] ?? 0));
    if (!$r) jerr('查無此溝通記錄表', 404);
    // 點開即刷新鐵則（ai-rules/08 第六節）：送出前先比對畫面看到的狀態
    if ((string)($_POST['status_seen'] ?? 'draft') !== (string)$r['status'])
        jerr('這張單的狀態已經被別人變更（目前：' . (CM_STATUS[$r['status']] ?? $r['status']) . '），請重新整理後再送出', 409, ['code'=>'CONFLICT']);
    if ((string)$r['status'] !== 'draft') jerr('此單不在草稿狀態，無法送出');
    if (!$P['canAdmin'] && (int)$r['maker_id'] !== $uid && (int)$r['created_by'] !== $uid) jerr('只有填表人本人可以送出', 403);
    if (!cmItems($db, (int)$r['rec_id'])) jerr('請至少填寫一項溝通問題再送出');

    $now = cmNow($db);
    $biz = (string)$r['comm_date'];
    $mgr = cm_dept_manager_pool($db, (int)$r['maker_id'], (int)$r['maker_dept_id'], (int)$r['maker_pos_sort'], $biz);

    /* 模組設定關掉簽核時：送出當下自動簽核完成、直接結案，不發任何待簽通知。
       依 ai-rules/21 自動簽核三鐵則——業務日期（簽章日期）＝該單溝通日期、與精確時間戳分離存放，
       兩關的時間刻意錯開 5~30 分鐘且不跨日，簽核人取各關卡原本的合格簽核池第一位、池空才退回最高核准人員。 */
    if ((string)(cm_settings($db)['cm_need_sign'] ?? '1') === '0') {
        $gmPool  = cm_gm_pool($db, $biz);
        $mgrOne  = $mgr['pool'][0] ?? null;
        $gmOne   = $gmPool[0] ?? (eg_org_user($db, 'top_approver') ?: null);
        $t1 = date('Y-m-d H:i:s', strtotime($now['n']) + random_int(300, 1800));
        if (substr($t1, 0, 10) !== substr($now['n'], 0, 10)) $t1 = substr($now['n'], 0, 10) . ' 23:59:00';
        $t2 = date('Y-m-d H:i:s', strtotime($t1) + random_int(300, 1800));
        if (substr($t2, 0, 10) !== substr($now['n'], 0, 10)) $t2 = substr($now['n'], 0, 10) . ' 23:59:30';

        $db->prepare("UPDATE comm_record SET status='closed', mgr_skip=?, submit_date=?, submitted_at=?,
                        mgr_user_id=?, mgr_name=?, mgr_dept_name=?, mgr_pos_name=?, mgr_date=?, mgr_at=?,
                        gm_user_id=?, gm_name=?, gm_date=?, gm_at=?,
                        reject_by=NULL, reject_at=NULL, reject_note=NULL, updated_at=? WHERE rec_id=?")
           ->execute([$mgr['skip'] ? 1 : 0, $now['d'], $now['n'],
                      $mgrOne['id'] ?? null, $mgrOne['name'] ?? null, $mgrOne['dept_name'] ?? null, $mgrOne['pos_name'] ?? null,
                      $mgrOne ? $biz : null, $mgrOne ? $t1 : null,
                      $gmOne['id'] ?? null, $gmOne['name'] ?? ($gmOne['user_cname'] ?? null), $gmOne ? $biz : null, $gmOne ? $t2 : null,
                      $now['n'], (int)$r['rec_id']]);
        // 紀錄仍要進共用的 approval_record，否則「列印與簽核紀錄」查不到＝這個模組沒有可追溯性（ai-rules/23 鐵則二）
        foreach ([[CM_LEVEL_MGR, $mgrOne, $t1], [CM_LEVEL_GM, $gmOne, $t2]] as $lv) {
            if (!$lv[1]) continue;
            $aid = eg_approval_submit($db, 'comm_record', (int)$r['rec_id'], $lv[0], $uid, $uname);
            $db->prepare("UPDATE approval_record SET status='approved', approver_id=?, approver_name=?, decided_at=?, note=?
                          WHERE id=?")
               ->execute([(int)$lv[1]['id'], (string)($lv[1]['name'] ?? $lv[1]['user_cname'] ?? ''), $lv[2],
                          '（系統自動簽核：模組設定為免簽核）', $aid]);
        }
        cmNotifyResult($db, $r, (int)$r['maker_id'], 'approved', '模組設定為免簽核，送出後已自動完成', $uid, '系統');
        jout(['status'=>'closed', 'mgr_skip'=>$mgr['skip'] ? 1 : 0, 'pool'=>[],
              'msg'=>'本模組目前設定為免簽核，已自動完成確認並結案。']);
    }

    // 免簽＝往上找到最上層都沒有合格主管（紙本「若由主管填寫則此格免簽」），直接進總經理確認
    $skip   = $mgr['skip'] ? 1 : 0;
    $status = $skip ? 'gm_wait' : 'mgr_wait';
    $db->prepare("UPDATE comm_record SET status=?, mgr_skip=?, submit_date=?, submitted_at=?,
                    reject_by=NULL, reject_at=NULL, reject_note=NULL, updated_at=? WHERE rec_id=?")
       ->execute([$status, $skip, $now['d'], $now['n'], $now['n'], (int)$r['rec_id']]);

    $level = $skip ? CM_LEVEL_GM : CM_LEVEL_MGR;
    $pool  = $skip ? cm_gm_pool($db, $biz) : $mgr['pool'];
    eg_approval_submit($db, 'comm_record', (int)$r['rec_id'], $level, $uid, $uname);
    $r['status'] = $status;
    cmNotifySign($db, $r, $pool, $level, $uid);
    jout(['status'=>$status, 'mgr_skip'=>$skip, 'pool'=>$pool,
          'msg'=>$skip ? '您所屬單位（往上追溯到課級為止）已沒有職位編號比您小的合格主管，部門主管確認欄免簽，已直接送總經理確認。'
                       : '已送出，等待部門主管確認。']);
}

/* ---- 溝通記錄表：確認／退回 ---- */
if ($action === 'rec_decide') {
    $r = cmRecGet($db, (int)($_POST['rec_id'] ?? 0));
    if (!$r) jerr('查無此溝通記錄表', 404);
    $stage = cmStage($r);
    if ($stage === '') jerr('此單目前不在待確認狀態（可能已被其他人處理），請重新整理', 409, ['code'=>'CONFLICT']);
    if ((string)($_POST['status_seen'] ?? '') !== '' && (string)$_POST['status_seen'] !== (string)$r['status'])
        jerr('這張單的狀態已經被別人變更，請重新整理後再確認', 409, ['code'=>'CONFLICT']);

    $pool = cmPoolOf($db, $r, $stage);
    $sign = cm_can_sign($db, $pool, $uid);
    if (!$sign['ok']) jerr('您不在這一關的簽核名單內', 403);

    $decision = (string)($_POST['decision'] ?? '');
    if (!in_array($decision, ['approved', 'rejected'], true)) jerr('決定不正確');
    $note = cmS($_POST['note'] ?? '', 500);
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因（ai-rules/17）');

    $now  = cmNow($db);
    $appr = eg_approval_latest($db, 'comm_record', (int)$r['rec_id'], $stage);
    if ($appr && (string)$appr['status'] === 'pending') {
        try { eg_approval_decide($db, (int)$appr['id'], $uid, $uname, $decision, $note); } catch (Throwable $e) {}
    }
    cmCloseNotice($db, (int)$r['rec_id']);

    if ($decision === 'rejected') {
        $db->prepare("UPDATE comm_record SET status='draft', reject_by=?, reject_at=?, reject_note=?, updated_at=? WHERE rec_id=?")
           ->execute([$uname, $now['n'], $note, $now['n'], (int)$r['rec_id']]);
        cmNotifyResult($db, $r, (int)$r['maker_id'], 'rejected', $note, $uid, $uname);
        jout(['status'=>'draft']);
    }

    // 簽章日期＝該單業務日期（溝通日期），不是按下去的那一天（ai-rules/18 鐵則4）
    $signDate = (string)($r['comm_date'] ?: $now['d']);
    $as       = $sign['as'] ?: ['dept_name'=>'', 'pos_name'=>''];
    if ($stage === CM_LEVEL_MGR) {
        $db->prepare("UPDATE comm_record SET status='gm_wait', mgr_user_id=?, mgr_name=?, mgr_dept_name=?, mgr_pos_name=?,
                        mgr_date=?, mgr_at=?, mgr_note=?, mgr_deputy=?, mgr_for_name=?, updated_at=? WHERE rec_id=?")
           ->execute([$uid, $uname, (string)$as['dept_name'], (string)$as['pos_name'], $signDate, $now['n'], $note,
                      $sign['deputy'] ? 1 : 0, $sign['for_name'], $now['n'], (int)$r['rec_id']]);
        $r['status'] = 'gm_wait';
        $gm = cm_gm_pool($db, $signDate);
        eg_approval_submit($db, 'comm_record', (int)$r['rec_id'], CM_LEVEL_GM, $uid, $uname);
        cmNotifySign($db, $r, $gm, CM_LEVEL_GM, $uid);
        jout(['status'=>'gm_wait', 'msg'=>'已確認，轉送總經理確認。']);
    }

    $db->prepare("UPDATE comm_record SET status='closed', gm_user_id=?, gm_name=?, gm_date=?, gm_at=?, gm_note=?,
                    gm_deputy=?, gm_for_name=?, updated_at=? WHERE rec_id=?")
       ->execute([$uid, $uname, $signDate, $now['n'], $note, $sign['deputy'] ? 1 : 0, $sign['for_name'], $now['n'], (int)$r['rec_id']]);
    cmNotifyResult($db, $r, (int)$r['maker_id'], 'approved', $note, $uid, $uname);
    jout(['status'=>'closed', 'msg'=>'已完成確認，本單結案。']);
}

/* ---- 把某一列溝通問題轉入措施追蹤表（漏斗的第二層→第三層） ---- */
if ($action === 'rec_to_track') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $st = $db->prepare("SELECT i.*, r.party_name, r.comm_date, r.maker_id, r.created_by, r.status, r.rec_no
                        FROM comm_record_item i JOIN comm_record r ON r.rec_id=i.rec_id
                        WHERE i.item_id=? AND r.is_deleted=0");
    $st->execute([$itemId]);
    $it = $st->fetch(PDO::FETCH_ASSOC);
    if (!$it) jerr('查無此溝通問題', 404);
    if (!$P['canAdmin'] && (int)$it['maker_id'] !== $uid && (int)$it['created_by'] !== $uid) jerr('只有填表人或溝通管理員可以轉入追蹤表', 403);
    if ((int)$it['track_id'] > 0) {
        // 點開即刷新鐵則：已經轉過的不再重複建立（否則追蹤表會長出兩筆一樣的）
        $c = $db->prepare("SELECT track_id FROM comm_track WHERE track_id=? AND is_deleted=0");
        $c->execute([(int)$it['track_id']]);
        if ($c->fetchColumn()) jerr('這一項已經轉入追蹤表了，請重新整理後查看', 409, ['code'=>'CONFLICT']);
    }
    $now     = cmNow($db);
    $ownerId = (int)($_POST['owner_id'] ?? 0);
    $due     = cmDate($_POST['due_date'] ?? '');
    $db->prepare("INSERT INTO comm_track (party, content, react_date, action, owner_id, owner_name, due_date,
                    src_rec_id, src_item_id, created_by, created_by_name, created_at, updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$it['party_name'], $it['question'], $it['comm_date'], cmS($_POST['action_text'] ?? $it['reply'], 4000),
                  $ownerId ?: null, $ownerId ? cm_user_name($db, $ownerId) : cmS($_POST['owner_name'] ?? '', 60), $due,
                  (int)$it['rec_id'], $itemId, $uid, $uname, $now['n'], $now['n']]);
    $tid = (int)$db->lastInsertId();
    $db->prepare("UPDATE comm_record_item SET track_id=? WHERE item_id=?")->execute([$tid, $itemId]);
    jout(['track_id'=>$tid]);
}

/* ---- 措施追蹤表 ---- */
if ($action === 'track_list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per'] ?? 20);
    if (!in_array($per, [5,10,20,50], true)) $per = 20;
    $w = ['t.is_deleted=0']; $a = [];
    $show = cmS($_GET['show'] ?? 'open', 10);          // open=只看未結案（預設，追蹤表的本意）／all=全部／closed
    if ($show === 'open')   $w[] = 't.is_closed=0';
    if ($show === 'closed') $w[] = 't.is_closed=1';
    $kw = cmS($_GET['kw'] ?? '', 60);
    if ($kw !== '') {
        foreach (preg_split('/\s+/', $kw, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $w[] = "(t.party LIKE ? OR t.content LIKE ? OR t.action LIKE ? OR t.owner_name LIKE ? OR t.close_note LIKE ?)";
            for ($i = 0; $i < 5; $i++) $a[] = '%' . $t . '%';
        }
    }
    $where = implode(' AND ', $w);
    $st = $db->prepare("SELECT COUNT(*) FROM comm_track t WHERE $where");
    $st->execute($a);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT t.*, r.rec_no AS src_rec_no FROM comm_track t
                        LEFT JOIN comm_record r ON r.rec_id=t.src_rec_id
                        WHERE $where ORDER BY t.is_closed, t.due_date IS NULL, t.due_date, t.track_id
                        LIMIT " . (int)$per . " OFFSET " . (($page - 1) * $per));
    $st->execute($a);
    jout(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total, 'page'=>$page, 'per'=>$per,
          'print'=>cm_print_meta($db, 'track')]);
}

if ($action === 'track_save') {
    $id  = (int)($_POST['track_id'] ?? 0);
    $party = cmS($_POST['party'] ?? '', 200);
    if ($party === '') jerr('請填寫利害關係者');
    $content = cmS($_POST['content'] ?? '', 4000);
    if ($content === '') jerr('請填寫反應內容');
    $closed  = !empty($_POST['is_closed']) ? 1 : 0;
    $due     = cmDate($_POST['due_date'] ?? '');
    if (!$closed && !$due) jerr('尚未結案的項目必須填寫預計完成日（追蹤表只列有預計完成日的項目）');
    $ownerId = (int)($_POST['owner_id'] ?? 0);
    $owner   = $ownerId ? cm_user_name($db, $ownerId) : cmS($_POST['owner_name'] ?? '', 60);
    if ($owner === '') jerr('請指定負責人');
    $now = cmNow($db);
    $closedDate = $closed ? (cmDate($_POST['closed_date'] ?? '') ?: $now['d']) : null;
    /* 追蹤項目一律由溝通記錄表的某一列問題「轉追蹤」建立（使用者指定），這裡不開放憑空新建——
       來源可追溯才有意義。畫面上已經沒有「新增追蹤項目」按鈕，後端同規則再擋一次（鐵律8），
       否則直打 API 還是建得出一筆沒有來源的孤兒。轉入用的是 rec_to_track，不走這支。 */
    if (!$id) jerr('追蹤項目必須從「利害關係者溝通記錄表」的該列問題按「轉追蹤」建立，不能直接新增', 400);
    if ($id) {
        $st = $db->prepare("SELECT created_by FROM comm_track WHERE track_id=? AND is_deleted=0");
        $st->execute([$id]);
        $own = $st->fetch(PDO::FETCH_ASSOC);
        if (!$own) jerr('查無此追蹤項目', 404);
        if (!$P['canAdmin'] && (int)$own['created_by'] !== $uid && $ownerId !== $uid)
            jerr('只有建立者、負責人或溝通管理員可以修改', 403);
        $db->prepare("UPDATE comm_track SET party=?, content=?, react_date=?, action=?, owner_id=?, owner_name=?,
                        due_date=?, is_closed=?, closed_date=?, close_note=?, updated_at=? WHERE track_id=?")
           ->execute([$party, $content, cmDate($_POST['react_date'] ?? ''), cmS($_POST['action_text'] ?? '', 4000),
                      $ownerId ?: null, $owner, $due, $closed, $closedDate, cmS($_POST['close_note'] ?? '', 500), $now['n'], $id]);
    } else {
        $db->prepare("INSERT INTO comm_track (party, content, react_date, action, owner_id, owner_name, due_date,
                        is_closed, closed_date, close_note, created_by, created_by_name, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$party, $content, cmDate($_POST['react_date'] ?? ''), cmS($_POST['action_text'] ?? '', 4000),
                      $ownerId ?: null, $owner, $due, $closed, $closedDate, cmS($_POST['close_note'] ?? '', 500),
                      $uid, $uname, $now['n'], $now['n']]);
        $id = (int)$db->lastInsertId();
    }
    jout(['track_id'=>$id]);
}

if ($action === 'track_delete') {
    cmReqAdmin($P);
    $id = (int)($_POST['track_id'] ?? 0);
    $db->prepare("UPDATE comm_track SET is_deleted=1, updated_at=? WHERE track_id=?")->execute([cmNow($db)['n'], $id]);
    $db->prepare("UPDATE comm_record_item SET track_id=NULL WHERE track_id=?")->execute([$id]);
    jout();
}

/* ---- 溝通管制表 ---- */
if ($action === 'ctrl_list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per'] ?? 20);
    if (!in_array($per, [5,10,20,50], true)) $per = 20;
    $w = ['is_deleted=0']; $a = [];
    $kw = cmS($_GET['kw'] ?? '', 60);
    if ($kw !== '') {
        foreach (preg_split('/\s+/', $kw, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $w[] = "(maker_name LIKE ? OR party LIKE ? OR content LIKE ? OR channel LIKE ? OR freq LIKE ? OR remark LIKE ?)";
            for ($i = 0; $i < 6; $i++) $a[] = '%' . $t . '%';
        }
    }
    $where = implode(' AND ', $w);
    $st = $db->prepare("SELECT COUNT(*) FROM comm_ctrl WHERE $where");
    $st->execute($a);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT * FROM comm_ctrl WHERE $where ORDER BY sort_order, ctrl_id
                        LIMIT " . (int)$per . " OFFSET " . (($page - 1) * $per));
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $i => $r) {
        // 頻率顯示字串一律由 cm_freq_text() 組（鐵律4：不要在前端再拼一次「每 N 單位 M 次」）
        $rows[$i]['freq_text']     = cm_freq_text((int)$r['freq_n'], (string)$r['freq_unit'], (int)$r['freq_times']);
        $rows[$i]['targets']       = cm_ctrl_targets($db, (int)$r['ctrl_id']);
        $rows[$i]['target_labels'] = cm_ctrl_target_labels($db, (int)$r['ctrl_id']);
    }
    jout(['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per, 'print'=>cm_print_meta($db, 'ctrl')]);
}

if ($action === 'ctrl_save') {
    $id  = (int)($_POST['ctrl_id'] ?? 0);
    $now = cmNow($db);
    $today = $now['d'];

    /* ---- 填表人：比照溝通記錄，先選部門再選人（管理員可代填，一般人固定自己） ---- */
    $makerId = (int)($_POST['maker_id'] ?? 0);
    if ($makerId <= 0 || !$P['canAdmin']) $makerId = $uid;
    $makerDept = (int)($_POST['maker_dept_id'] ?? 0);
    $mkIdn = null;
    foreach (cm_identities($db, $makerId, $today) as $x) {
        if (!$makerDept || (int)$x['department_id'] === $makerDept) { $mkIdn = $x; break; }
    }
    $maker = cm_user_name($db, $makerId) ?: $uname;

    /* ---- 利害關係人：比照溝通記錄的類別連動，但**不必填到聯絡人是哪位**（開記錄表才填） ---- */
    $kind = cmS($_POST['party_kind'] ?? '', 12);
    if (!isset(CM_PARTY_KINDS[$kind])) jerr('請選擇利害關係人的類別');
    $kindOther = cmS($_POST['party_kind_other'] ?? '', 100);
    if ($kind === 'other' && $kindOther === '') jerr('類別選「其他」時請填寫說明');
    $partyRef  = cmS($_POST['party_ref_id'] ?? '', 40);
    $partyUser = (int)($_POST['party_user_id'] ?? 0);
    $party     = cmS($_POST['party'] ?? '', 200);
    if ($kind === 'customer' || $kind === 'supplier') {
        if ($partyRef === '') jerr($kind === 'customer' ? '請從清單選擇客戶' : '請從清單選擇供應商');
        $hit = null;
        foreach (cm_party_search($db, $kind, $partyRef, 50) as $x) if ((string)$x['id'] === $partyRef) { $hit = $x; break; }
        if (!$hit) jerr(($kind === 'customer' ? '客戶' : '供應商') . '編號不存在：' . $partyRef);
        $party = (string)($hit['full_name'] ?: $hit['name']);
        $partyUser = 0;
    } elseif ($kind === 'employee') {
        $deptRef = (int)$partyRef;
        if ($deptRef <= 0) jerr('請選擇員工所屬部門');
        if ($partyUser <= 0) jerr('請選擇員工');
        $ok = null;
        foreach (cm_dept_people($db, $deptRef, $today) as $p) if ((int)$p['id'] === $partyUser) { $ok = $p; break; }
        if (!$ok) jerr('這位員工不在所選部門，請重新選擇');
        $party = $ok['dept_name'] . '　' . $ok['name'] . ($ok['pos_name'] ? '（' . $ok['pos_name'] . '）' : '');
    } else {
        if ($party === '') jerr('請填寫利害關係人');
        $partyRef = ''; $partyUser = 0;
    }

    $content = cmS($_POST['content'] ?? '', 4000);
    if ($content === '') jerr('請填寫溝通內容');

    /* ---- 溝通管道：比照溝通記錄的勾選（可複選＋其他可填），存成顯示字串 ---- */
    $chSel = [];
    foreach (array_keys(CM_CHANNELS) as $c) if (!empty($_POST['ch_' . $c])) $chSel[] = $c;
    if (!$chSel) jerr('請至少勾選一種溝通管道');
    $chOther = cmS($_POST['ch_other_text'] ?? '', 100);
    if (in_array('other', $chSel, true) && $chOther === '') jerr('管道勾選「其他」時請填寫說明');
    $channel = implode('、', array_map(function ($c) use ($chOther) {
        return $c === 'other' ? $chOther : CM_CHANNELS[$c];
    }, $chSel));

    /* ---- 頻率：固定「每 N 單位 M 次」 ---- */
    $fn = max(1, (int)($_POST['freq_n'] ?? 1));
    $fu = cmS($_POST['freq_unit'] ?? 'month', 10);
    if (!isset(CM_FREQ_UNITS[$fu])) jerr('頻率單位不正確');
    $ft = max(1, (int)($_POST['freq_times'] ?? 1));
    $freq = cm_freq_text($fn, $fu, $ft);

    /* ---- 提醒 ---- */
    $rEnabled = !empty($_POST['remind_enabled']) ? 1 : 0;
    $nextDue  = cmDate($_POST['next_due_date'] ?? '');
    $rLead    = max(0, min(365, (int)($_POST['remind_lead_days'] ?? 0)));
    $rTime    = cmS($_POST['remind_time'] ?? '', 8);
    if ($rTime !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $rTime)) $rTime = '';
    $targets  = json_decode((string)($_POST['targets'] ?? '[]'), true);
    if (!is_array($targets)) $targets = [];
    if ($rEnabled) {
        if (!$nextDue) jerr('要自動提醒就必須填「下次應溝通日」，提醒時間是由它往前推算的');
        if ($rTime === '') $rTime = '09:00';
        if (!$targets) jerr('要自動提醒就必須至少指定一位提醒對象（人員或部門）');
    }

    if ($id) {
        $st = $db->prepare("SELECT created_by, remind_sent_for, next_due_date FROM comm_ctrl WHERE ctrl_id=? AND is_deleted=0");
        $st->execute([$id]);
        $own = $st->fetch(PDO::FETCH_ASSOC);
        if (!$own) jerr('查無此管制項目', 404);
        if (!$P['canAdmin'] && (int)$own['created_by'] !== $uid) jerr('只有建立者或溝通管理員可以修改', 403);
        // 改了下次應溝通日＝新的一期，之前發過的提醒記號要清掉，否則新這期永遠不會提醒
        $sentFor = ((string)$own['next_due_date'] !== (string)$nextDue) ? null : $own['remind_sent_for'];
        $db->prepare("UPDATE comm_ctrl SET maker_id=?, maker_name=?, maker_dept_id=?, maker_dept_name=?, maker_pos_name=?,
                        party_kind=?, party_kind_other=?, party=?, party_ref_id=?, party_user_id=?,
                        content=?, channel=?, freq=?, freq_n=?, freq_unit=?, freq_times=?, next_due_date=?,
                        remind_enabled=?, remind_lead_days=?, remind_time=?, remind_sent_for=?,
                        remark=?, sort_order=?, updated_at=? WHERE ctrl_id=?")
           ->execute([$makerId, $maker, $mkIdn['department_id'] ?? null, $mkIdn['department_name'] ?? null,
                      $mkIdn['position_name'] ?? null, $kind, $kindOther, $party, $partyRef ?: null, $partyUser ?: null,
                      $content, $channel, $freq, $fn, $fu, $ft, $nextDue,
                      $rEnabled, $rLead, $rTime ?: null, $sentFor,
                      cmS($_POST['remark'] ?? '', 500), (int)($_POST['sort_order'] ?? 0), $now['n'], $id]);
    } else {
        $db->prepare("INSERT INTO comm_ctrl (maker_id, maker_name, maker_dept_id, maker_dept_name, maker_pos_name,
                        party_kind, party_kind_other, party, party_ref_id, party_user_id, content, channel,
                        freq, freq_n, freq_unit, freq_times, next_due_date,
                        remind_enabled, remind_lead_days, remind_time, remark, sort_order,
                        created_by, created_by_name, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$makerId, $maker, $mkIdn['department_id'] ?? null, $mkIdn['department_name'] ?? null,
                      $mkIdn['position_name'] ?? null, $kind, $kindOther, $party, $partyRef ?: null, $partyUser ?: null,
                      $content, $channel, $freq, $fn, $fu, $ft, $nextDue,
                      $rEnabled, $rLead, $rTime ?: null, cmS($_POST['remark'] ?? '', 500),
                      (int)($_POST['sort_order'] ?? 0), $uid, $uname, $now['n'], $now['n']]);
        $id = (int)$db->lastInsertId();
    }

    // 提醒對象：整組重寫（人員／部門皆驗證存在，避免直打 API 塞不存在的 id）
    $db->prepare("DELETE FROM comm_ctrl_remind_target WHERE ctrl_id=?")->execute([$id]);
    $ins = $db->prepare("INSERT IGNORE INTO comm_ctrl_remind_target (ctrl_id, target_type, target_id) VALUES (?,?,?)");
    foreach ($targets as $t) {
        $tt = (string)($t['type'] ?? '');
        $ti = (int)($t['id'] ?? 0);
        if (!in_array($tt, ['user', 'dept'], true) || $ti <= 0) continue;
        if ($tt === 'user' && cm_user_name($db, $ti) === '') continue;
        if ($tt === 'dept' && cm_dept_name($db, $ti) === '') continue;
        $ins->execute([$id, $tt, $ti]);
    }
    jout(['ctrl_id'=>$id, 'freq_text'=>$freq]);
}

/* ---- 由管制項目「快速建立」一張溝通記錄表（同一筆可多次轉出，只是省去重打） ---- */
if ($action === 'ctrl_to_record') {
    $st = $db->prepare("SELECT * FROM comm_ctrl WHERE ctrl_id=? AND is_deleted=0");
    $st->execute([(int)($_POST['ctrl_id'] ?? 0)]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('查無此管制項目', 404);

    $now  = cmNow($db);
    $date = $now['d'];                                  // 溝通日期自動帶入轉出那天（使用者指定）

    // 對象：管制項目已綁定的一律沿用、不可改；只有「其他」允許在轉出時手改（使用者指定）
    $kind = (string)$c['party_kind'] ?: 'other';
    $party = (string)$c['party'];
    $kindOther = (string)$c['party_kind_other'];
    if ($kind === 'other') {
        $party = cmS($_POST['party_name'] ?? $party, 200);
        $kindOther = cmS($_POST['party_kind_other'] ?? $kindOther, 100);
        if ($party === '') jerr('請填寫利害關係者');
    }
    // 管道可改（使用者指定）：沒帶就沿用管制項目原本勾的那幾個
    $ch = [];
    $any = false;
    foreach (array_keys(CM_CHANNELS) as $k) { $ch[$k] = !empty($_POST['ch_' . $k]) ? 1 : 0; $any = $any || $ch[$k]; }
    $chOther = cmS($_POST['ch_other_text'] ?? '', 100);
    if (!$any) {
        foreach (CM_CHANNELS as $k => $v) if ($k !== 'other' && mb_strpos((string)$c['channel'], $v) !== false) { $ch[$k] = 1; $any = true; }
        if (!$any) { $ch['other'] = 1; $chOther = (string)$c['channel']; }
    }
    if ($ch['other'] && $chOther === '') jerr('管道勾選「其他」時請填寫說明');

    // 填表身分：轉出的人就是填表人（沿用他在管制項目上的部門，沒有就取主職）
    $idn = null;
    foreach (cm_identities($db, $uid, $date) as $x) {
        if ((int)$x['department_id'] === (int)$c['maker_dept_id']) { $idn = $x; break; }
    }
    if (!$idn) { $all = cm_identities($db, $uid, $date); $idn = $all[0] ?? null; }
    if (!$idn) jerr('查無您在今天的部門／職稱資料，請洽人事確認員工部門職稱設定');

    $recNo = cm_next_rec_no($db, $date);
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO comm_record (rec_no, comm_type, comm_date, party_kind, party_kind_other, party_name,
                        party_ref_id, party_user_id, ch_phone, ch_face, ch_email, ch_meeting, ch_other, ch_other_text,
                        maker_id, maker_name, maker_dept_id, maker_dept_name, maker_pos_id, maker_pos_name, maker_pos_sort,
                        status, remark, created_by, created_by_name, created_at, updated_at)
                      VALUES (?,'regular',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,?,?,?,?)")
           ->execute([$recNo, $date, $kind, $kindOther, $party, $c['party_ref_id'], $c['party_user_id'],
                      $ch['phone'], $ch['face'], $ch['email'], $ch['meeting'], $ch['other'], $chOther,
                      $uid, $uname, $idn['department_id'], $idn['department_name'], $idn['position_id'],
                      $idn['position_name'], $idn['pos_sort'],
                      '由溝通管制表項目 #' . $c['ctrl_id'] . ' 建立（' . $c['freq'] . '）', $uid, $uname, $now['n'], $now['n']]);
        $recId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO comm_record_item (rec_id, seq, question, reply) VALUES (?,1,?,'')")
           ->execute([$recId, (string)$c['content']]);

        // 下次應溝通日往後推一個週期（前端預設勾選，會在跳窗明白告知推到哪一天）
        if (!empty($_POST['advance_due'])) {
            $base = (string)($c['next_due_date'] ?: $date);
            $next = cm_freq_advance($base, (int)$c['freq_n'], (string)$c['freq_unit']);
            $db->prepare("UPDATE comm_ctrl SET next_due_date=?, remind_sent_for=NULL, updated_at=? WHERE ctrl_id=?")
               ->execute([$next, $now['n'], (int)$c['ctrl_id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('建立失敗：' . $e->getMessage(), 500);
    }
    jout(['rec_id'=>$recId, 'rec_no'=>$recNo]);
}

if ($action === 'ctrl_delete') {
    cmReqAdmin($P);
    $db->prepare("UPDATE comm_ctrl SET is_deleted=1, updated_at=? WHERE ctrl_id=?")
       ->execute([cmNow($db)['n'], (int)($_POST['ctrl_id'] ?? 0)]);
    jout();
}

/* ---- 附件（鐵律5：DB 只存檔名，路徑讀取當下現場組） ---- */
if ($action === 'att_list') {
    $recId = (int)($_GET['rec_id'] ?? 0);
    if ($recId > 0) {
        $r = cmRecGet($db, $recId);
        if (!$r) jerr('查無此溝通記錄表', 404);
        if (!cmCanSee($db, $r, $P, $uid)) jerr('無權檢視此單', 403);
    }
    jout(['rows'=>cmAttaches($db, $recId, cmS($_GET['temp_key'] ?? '', 40))]);
}

if ($action === 'att_upload') {
    $recId = (int)($_POST['rec_id'] ?? 0);
    $tk    = cmS($_POST['temp_key'] ?? '', 40);
    if ($recId > 0) {
        $r = cmRecGet($db, $recId);
        if (!$r) jerr('查無此溝通記錄表', 404);
        if (!cmCanEdit($r, $P, $uid)) jerr('此單已送出，無法再新增附件', 403);
    } elseif ($tk === '') {
        jerr('缺少暫存識別碼');
    }
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 9) !== UPLOAD_ERR_OK) jerr('沒有收到檔案（或檔案過大）');
    $orig = (string)$_FILES['file']['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' || !preg_match('/^[a-z0-9]{1,8}$/', $ext)) jerr('檔案類型不正確');
    if (in_array($ext, ['php','phtml','exe','bat','cmd','com','scr','js','vbs','msi','dll'], true)) jerr('不允許上傳此類型檔案');
    if ((int)$_FILES['file']['size'] > 50 * 1024 * 1024) jerr('單一檔案請勿超過 50MB');

    $dir = cm_attach_dir($db);
    if (!eg_attach_ensure_dir($dir)) jerr('附件資料夾無法建立，請洽管理員確認 NAS 設定', 500);
    $fn = 'cm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fn)) jerr('檔案寫入失敗（NAS 可能未連線）', 500);

    $now = cmNow($db);
    $db->prepare("INSERT INTO comm_record_attach (rec_id, temp_key, file_name, orig_name, file_size, mime, note, status,
                    uploaded_by, uploaded_by_name, uploaded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$recId ?: null, $recId ? null : $tk, $fn, mb_substr($orig, 0, 200), (int)$_FILES['file']['size'],
                  mb_substr((string)($_FILES['file']['type'] ?? ''), 0, 100), cmS($_POST['note'] ?? '', 200),
                  $recId ? 'active' : 'temp', $uid, $uname, $now['n']]);
    jout(['att_id'=>(int)$db->lastInsertId(), 'file_name'=>$fn]);
}

/* ---- 附件說明（選填）：上傳後仍可修改。有填說明時清單改顯示說明、不顯示原始檔名（使用者要求）。 ---- */
if ($action === 'att_note') {
    $id = (int)($_POST['att_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM comm_record_attach WHERE att_id=?");
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('查無此附件', 404);
    // 權限與刪除同一套：單據還能編輯時該單的人可以改，暫存中的檔只有上傳者或管理員能改
    if ((int)$a['rec_id'] > 0) {
        $r = cmRecGet($db, (int)$a['rec_id']);
        if (!$r || !cmCanEdit($r, $P, $uid)) jerr('此單已送出，無法修改附件說明', 403);
    } elseif ((int)$a['uploaded_by'] !== $uid && !$P['canAdmin']) {
        jerr('無權修改此附件', 403);
    }
    $db->prepare("UPDATE comm_record_attach SET note=? WHERE att_id=?")->execute([cmS($_POST['note'] ?? '', 200), $id]);
    jout();
}

if ($action === 'att_delete') {
    $id = (int)($_POST['att_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM comm_record_attach WHERE att_id=?");
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('查無此附件', 404);
    if ((int)$a['rec_id'] > 0) {
        $r = cmRecGet($db, (int)$a['rec_id']);
        if (!$r || !cmCanEdit($r, $P, $uid)) jerr('此單已送出，無法刪除附件', 403);
    } elseif ((int)$a['uploaded_by'] !== $uid && !$P['canAdmin']) {
        jerr('無權刪除此附件', 403);
    }
    $db->prepare("DELETE FROM comm_record_attach WHERE att_id=?")->execute([$id]);
    @unlink(cm_attach_dir($db) . basename((string)$a['file_name']));
    jout();
}

if ($action === 'att_download') {
    $id = (int)($_GET['att_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM comm_record_attach WHERE att_id=?");
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('查無此附件', 404);
    if ((int)$a['rec_id'] > 0) {
        $r = cmRecGet($db, (int)$a['rec_id']);
        if (!$r || !cmCanSee($db, $r, $P, $uid)) jerr('無權下載此附件', 403);
    } elseif ((int)$a['uploaded_by'] !== $uid && !$P['canAdmin']) {
        jerr('無權下載此附件', 403);
    }
    $path = cm_attach_dir($db) . basename((string)$a['file_name']);   // 鐵律5：路徑現場組，不存 DB
    if (!is_file($path)) jerr('檔案不存在（可能已被移除或 NAS 未連線）', 404);
    header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    eg_attach_send_disposition((string)($a['orig_name'] ?: $a['file_name']));
    readfile($path);
    exit;
}

/* ---- 模組設定 ---- */
if ($action === 'setting_save') {
    cmReqAdmin($P);
    $set = $_POST['settings'] ?? '';
    $arr = json_decode((string)$set, true);
    if (!is_array($arr)) jerr('設定內容不正確');
    foreach ($arr as $k => $v) {
        if (!array_key_exists($k, CM_SETTINGS_DEFAULT)) continue;
        if (is_array($v)) $v = json_encode(array_values(array_map('intval', $v)));
        if ($k === 'cm_mgr_rank_max' || $k === 'cm_gm_rank_max') {
            $v = (string)max(0, min(89, (int)$v));
        }
        if ($k === 'cm_mgr_source' && !in_array($v, ['auto','users'], true)) continue;
        if ($k === 'cm_gm_source'  && !in_array($v, ['top','users','rank'], true)) continue;
        if ($k === 'cm_need_sign') $v = $v ? '1' : '0';
        cm_setting_save($db, $k, (string)$v, $uname);
    }
    jout(['settings'=>cm_settings($db)]);
}

if ($action === 'asdoc_save') {
    cmReqAdmin($P);
    $which = cmS($_POST['which'] ?? '', 20);
    if (!isset(CM_ASDOC_MODULES[$which])) jerr('表單代碼不正確');
    eg_asdoc_save($db, CM_ASDOC_MODULES[$which]['module'], (int)($_POST['doc_id'] ?? 0), $uname);
    jout(['asdoc'=>cm_asdoc_all($db)]);
}

/* 列印紀錄（ai-rules/23 鐵則一）刻意不在本 API 另開動作：
   前端一律走共用的 EGPrintLog.record()（打 PrintSignLog_API.php），
   兩邊都收就會變成「一次列印記兩筆」——那正是 ai-rules/23 明列的三個易錯點之一。 */

jerr('無效的操作：' . $action, 400);
