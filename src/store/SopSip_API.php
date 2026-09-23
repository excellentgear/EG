<?php
/**
 * SopSip_API.php — 作業標準書(SOP)／標準檢驗指導書(SIP) 的資料介面
 * 建立：2026-09-21
 *
 * 規則一律放 src/common/sopsip_lib.php，本檔只負責「守門＋參數整理＋回傳」，不在這裡再寫一份判定。
 * 鐵律8：前端擋過的每一條（權限、必填、人員當時在職、簽章日當天沒請整天假、綁定對象存在）
 *        這裡一律用同一支函式再擋一次。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/sopsip_lib.php';
require_once __DIR__ . '/../common/asdoc_lib.php';

/* 未捕捉的例外一律轉成 JSON——不轉的話會回一片空白的 500，畫面上就是「按了完全沒反應」 */
set_exception_handler(function (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

function jout($ok, $data = []) {
    echo json_encode(array_merge(['success' => $ok], is_array($data) ? $data : ['message' => $data]), JSON_UNESCAPED_UNICODE);
    exit;
}
function jerr($msg, $code = '') { jout(false, ['message' => $msg, 'code' => $code]); }

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) { http_response_code(401); jerr('尚未登入或登入已逾時，請重新整理頁面後再試', 'LOGIN'); }

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);
$P = ss_perms($db, $uid);
if (empty($P['canView'])) { http_response_code(403); jerr('沒有 SOP／SIP 的檢視權限'); }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (isset($_POST['action'])) {
    $tok = $_POST['csrf'] ?? '';
    if (empty($_SESSION['ss_csrf']) || !hash_equals((string)$_SESSION['ss_csrf'], (string)$tok)) {
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 'CSRF');
    }
}

/** 這個版面的編輯／簽核權限（SOP 與 SIP 是不同課室，分開授權） */
$needEdit = function (string $kind) use ($P) {
    if (!ss_perm_for_kind($P, $kind, 'edit')) { http_response_code(403); jerr('沒有修改這份文件的權限'); }
};
$needSign = function (string $kind) use ($P) {
    if (!ss_perm_for_kind($P, $kind, 'sign')) { http_response_code(403); jerr('沒有簽核這份文件的權限'); }
};
$needAdmin = function () use ($P) {
    if (empty($P['canAdmin'])) { http_response_code(403); jerr('只有 SOP／SIP 管理員可以做這個動作'); }
};
/** 由版次或文件 id 取回 kind，順便確認東西存在 */
$kindOfVer = function (int $verId) use ($db) {
    $v = ss_ver_get($db, $verId);
    if (!$v) jerr('找不到這個版次，請重新整理頁面');
    $d = ss_doc_get($db, (int)$v['doc_id']);
    if (!$d) jerr('找不到這份文件，請重新整理頁面');
    return [(string)$d['kind'], $v, $d];
};
$rows = function ($key) {
    $j = $_POST[$key] ?? '';
    if ($j === '') return [];
    $a = json_decode((string)$j, true);
    return is_array($a) ? $a : [];
};
/* 挑機台／量具一律帶「表單日期」：停用的機台與量具在**那一天之前**建立的文件上照樣要挑得到
   （使用者 2026-09-23：「停用的機台一樣要可以補資料，我的表單日期是 2022，那時候根本還沒停用」）。
   沒帶 asof＝只列現在在用的，行為與改版前完全相同。
   **一定要宣告在 switch 之外**——寫在某個 case 裡面時，switch 會直接跳到命中的那個 case，
   前面那幾行根本不會執行，其他 case 呼叫它就是「未定義的函式」。 */
$asofParam = function (): string {
    $d = trim((string)($_GET['asof'] ?? $_POST['asof'] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
};

switch ($action) {

/* ─────────────── 讀取 ─────────────── */

case 'meta':
    jout(true, [
        'kinds'    => ss_kinds(),
        'scopes'   => ss_scopes(),
        'slots'    => ss_slots(),
        'statuses' => ss_statuses(),
        'perms'    => $P,
        'today'    => date('Y-m-d'),
        'owner_depts' => array_values(array_filter(ss_owner_depts($db), fn($d) => !empty($d['on']))),
        'methods'     => ss_method_options($db)['list'],
        'tool_types'  => ss_tool_types($db),
    ]);

/* ── 綁定對象的搜尋（一律從主檔挑，打錯一個字就永遠比不中而且不報錯） ── */

case 'search_process':
    jout(true, ['rows' => ss_search_process($db, (string)($_GET['kw'] ?? ''))]);

case 'search_customer':
    jout(true, ['rows' => ss_search_customer($db, (string)($_GET['kw'] ?? ''))]);

case 'machine_models':
    jout(true, ['rows' => ss_machine_models($db, (string)($_GET['kw'] ?? ''), 60, $asofParam())]);

case 'machines_by_model':
    jout(true, ['rows' => ss_machines_by_model($db, (string)($_GET['model'] ?? ''), $asofParam())]);

/** 量具編號：先選類型再選編號（使用者要求的兩段式） */
case 'tools_by_type':
    jout(true, ['rows' => ss_tools_by_type($db, (int)($_GET['type_id'] ?? 0), $asofParam()),
                'types' => ss_tool_types($db)]);

/** 綁了料號就由料號主檔決定客戶，前端只負責顯示 */
case 'customer_of_part':
    jout(true, ss_customer_of_part($db, (int)($_GET['part_d_id'] ?? 0)));

/**
 * 建立前的重複檢查＋文件名稱自動產生。
 * 兩件事放同一支是刻意的：前端在「挑完綁定對象」的那一刻就要同時知道
 * 「會不會撞到既有文件」與「自動名稱長什麼樣」，分兩支會送兩次一樣的參數。
 */
case 'bind_probe': {
    $kind  = (string)($_GET['kind'] ?? '');
    $scope = (string)($_GET['scope'] ?? '');
    if (!isset(ss_kinds()[$kind])) jerr('表單版面代碼不正確');
    $asof = $asofParam();
    $out  = [];
    $mids = $_GET['machine_ids'] ?? '';
    $cusIn = trim((string)($_GET['customer_id'] ?? ''));
    $in = [
        'machine_model' => (string)($_GET['machine_model'] ?? ''),
        'machine_id'    => (int)($_GET['machine_id'] ?? 0),
        'part_d_id'     => (int)($_GET['part_d_id'] ?? 0),
        'tool_id'       => (int)($_GET['tool_id'] ?? 0),
        'process_no'    => (int)($_GET['process_no'] ?? 0),
        'machine_ids'   => $mids,
        'customer_id'   => $cusIn,
        'variant'       => (string)($_GET['variant'] ?? ''),
    ];
    // 綁料號時客戶由料號主檔決定，不採信前端送的（與 ss_doc_save 同一條規則）
    if ($scope === 'part') {
        $c = ss_customer_of_part($db, $in['part_d_id']);
        $in['customer_id'] = (string)($c['id'] ?? '');
        $out['customer']   = $c;
    }
    $scan = ss_dup_scan($db, $kind, $scope, $in, (int)($_GET['doc_id'] ?? 0));
    $out['dups']     = array_values(array_filter($scan, fn($r) => !empty($r['is_dup'])));
    $out['siblings'] = array_values(array_filter($scan, fn($r) => empty($r['is_dup'])));
    $out['title']    = ss_auto_title($db, $kind, $scope, $in);
    // 這個對象底下已經用掉哪幾種型式（含「未分型式」），畫面要講得出「還剩幾種可以用」
    $vs = [];
    foreach ($scan as $r) $vs[ss_variant_norm((string)($r['variant'] ?? ''))] = 1;
    $out['variants_used'] = array_values(array_keys($vs));
    $out['variant_max']   = SS_VARIANT_MAX;
    if ($scope === 'machine')   $out['machines'] = ss_machines_by_model($db, $in['machine_model'], $asof);
    if ($kind === 'sip') {
        $cfg = ss_proc_cfg($db, $in['process_no']);
        $out['proc_cfg']      = $cfg;
        $out['default_items'] = ss_default_items($db, $in['process_no'], null);
        $out['notice_auto']   = ss_notice_auto($db, (string)$in['customer_id']);
    }
    jout(true, $out);
}

/** 檢驗項目「代入預設值」（製程專屬＋標準項目），代入後使用者仍可逐列刪 */
case 'default_items': {
    $pno = (int)($_GET['process_no'] ?? 0);
    $std = array_key_exists('with_std', $_GET) ? ((int)$_GET['with_std'] === 1) : null;
    jout(true, ['rows' => ss_default_items($db, $pno, $std), 'cfg' => ss_proc_cfg($db, $pno)]);
}

case 'list': {
    $tab = ($_GET['tab'] ?? 'sop') === 'sip' ? 'sip' : 'sop';
    if ($tab === 'sip' && empty($P['canViewSip'])) jout(true, ['rows' => []]);
    if ($tab === 'sop' && empty($P['canViewSop'])) jout(true, ['rows' => []]);
    $list = ss_list($db, [
        'tab' => $tab, 'kind' => $_GET['kind'] ?? '', 'scope' => $_GET['scope'] ?? '',
        'status' => $_GET['status'] ?? '', 'keyword' => $_GET['kw'] ?? '',
        'machine_id' => (int)($_GET['machine_id'] ?? 0), 'part_d_id' => (int)($_GET['part_d_id'] ?? 0),
        'customer' => $_GET['customer'] ?? '', 'year' => (int)($_GET['year'] ?? 0),
    ]);
    jout(true, ['rows' => $list]);
}

case 'detail': {
    $verId = (int)($_GET['ver_id'] ?? 0);
    if ($verId <= 0) {
        $docId = (int)($_GET['doc_id'] ?? 0);
        $d = ss_doc_get($db, $docId);
        if (!$d) jerr('找不到這份文件');
        $cur = ss_current_ver($db, $docId);
        if (!$cur) jerr('這份文件還沒有任何版次');
        $verId = (int)$cur['ver_id'];
    }
    $full = ss_ver_full($db, $verId);
    if (!$full) jerr('找不到這個版次');
    $kind = $full['kind'];
    if (!ss_perm_for_kind($P, $kind, 'view')) { http_response_code(403); jerr('沒有檢視這份文件的權限'); }
    $full['vers']     = ss_ver_rows($db, (int)$full['doc']['doc_id']);
    $full['as_no']    = ss_as_no($db, $kind, (int)($full['ver']['as_doc_id'] ?? 0), (string)($full['ver']['form_date'] ?? ''));
    $full['as_title'] = ss_as_title($db, $kind, (int)($full['ver']['as_doc_id'] ?? 0));
    $full['can_edit'] = ss_perm_for_kind($P, $kind, 'edit') && (string)$full['ver']['status'] === 'draft';
    $full['can_sign'] = ss_perm_for_kind($P, $kind, 'sign');
    $full['next_slot'] = ss_next_slot($db, $verId);
    $full['draw_candidates'] = ss_part_draw_candidates($db, (int)($full['doc']['part_d_id'] ?? 0));
    /* 內容裡寫到的 AS 文件編號＝自動綁定那份文件，畫面要看得到綁到了什麼（使用者 2026-09-22）。
       掃的是「所有會印出來的文字欄位」，不是只有某一欄。 */
    $asTxt = implode("\n", array_filter([
        (string)($full['ver']['op_method'] ?? ''), (string)($full['ver']['cautions'] ?? ''),
        (string)($full['ver']['maintain'] ?? ''),  (string)($full['ver']['notice'] ?? ''),
        (string)($full['ver']['use_equip'] ?? ''),
        implode("\n", array_map(fn($s) => (string)($s['step_text'] ?? '') . "\n" . (string)($s['step_name'] ?? '')
                                        . "\n" . (string)($s['note'] ?? ''), $full['steps'] ?? [])),
        implode("\n", array_map(fn($i) => (string)($i['note'] ?? ''), $full['items'] ?? [])),
    ]));
    $full['as_refs'] = ss_asdoc_scan($db, $asTxt);
    // 管理員可以在核准之後補附件（使用者 2026-09-22 要求），但仍然不可以改內容
    $full['can_attach'] = (ss_perm_for_kind($P, $kind, 'edit')
                           && ((string)$full['ver']['status'] === 'draft' || !empty($P['canAdmin']))) ? 1 : 0;
    /* 送簽之後的版次一律讓管理員整版退回草稿（使用者 2026-09-22 指定）。
       原本限「整份都是自動簽核」，結果管理員清掉其中一格之後這個條件就不成立、按鈕跟著消失，
       版次卡在「簽核中、零個章」而且再也沒有任何路徑救得回來。 */
    $full['can_unsubmit'] = (!empty($P['canAdmin'])
                             && !in_array((string)$full['ver']['status'], ['draft', 'obsolete'], true)) ? 1 : 0;
    $full['paper']   = ss_paper($db, $full['doc'], $full['ver']);
    $full['papers']  = ss_papers();
    $full['orients'] = ss_orients();
    [$delOk, $delWhy] = ss_can_delete_doc($db, $full['doc'], $uid, $P);
    $full['can_delete'] = $delOk ? 1 : 0;
    $full['del_why']    = $delWhy;
    $full['owner_depts'] = array_values(array_filter(ss_owner_depts($db), fn($d) => !empty($d['on'])));
    $full['methods']     = ss_method_options($db)['list'];
    $full['tool_types']  = ss_tool_types($db);
    $full['variant_options'] = ss_variant_options($db);
    $full['variant_max']     = SS_VARIANT_MAX;
    if ($kind === 'sip') {
        $pno = (int)($full['doc']['process_no'] ?? 0);
        $full['proc_cfg']  = ss_proc_cfg($db, $pno);
        $full['tpl_count'] = count(ss_tpl_rows($db, 'proc', $pno)) + count(ss_tpl_rows($db, 'std'));
        $full['freq_options'] = ss_freq_options($db);
        $full['symbols']      = ss_symbols($db);
        // 注意事項範本：綁這份文件客戶的排前面，沒綁客戶的通用範本接在後面
        $full['notice_tpls']  = ss_notice_tpls($db, (string)($full['doc']['customer_id'] ?? ''));
    }
    jout(true, $full);
}

/**
 * 送簽視窗要的東西：①那一天可以簽的人（含請假標示）②勾了自動簽核時每一關會蓋到誰。
 * **人員與解析一律以「表單日期／簽章日期」當時的職務為準**（ai-rules/22），
 * 所以改日期要重打這一支，不可以沿用開視窗當下那一份名單。
 */
case 'signer_candidates': {
    $formDate = (string)($_GET['form_date'] ?? date('Y-m-d'));
    $signDate = (string)($_GET['sign_date'] ?? '');
    $kind     = (string)($_GET['kind'] ?? '');
    $out = ['rows' => ss_signer_candidates($db, $formDate, $signDate)];
    if (isset(ss_kinds()[$kind])) {
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $signDate) ? $signDate : $formDate;
        $out['auto_on'] = ss_auto_sign_on($db, $kind) ? 1 : 0;
        $out['resolve'] = [];
        foreach (array_keys(ss_slots()) as $slot) {
            if ($slot === 'maker') continue;            // 製表＝送出的人，不是設定解析出來的
            [$who, $why] = ss_resolve_signer($db, $kind, $slot, $d);
            // 名字一定要一起回：解析到的人在那一天還沒到職時，他不會出現在候選名單裡，
            // 前端就印不出「是誰」，訊息會變成「找不到人」而看不出真正的原因。
            $nm = '';
            if ($who > 0) {
                $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
                $q->execute([$who]);
                $nm = (string)($q->fetchColumn() ?: '');
            }
            $out['resolve'][$slot] = ['id' => $who, 'why' => $why, 'name' => $nm];
        }
    }
    jout(true, $out);
}

case 'search_part':
    jout(true, ['rows' => ss_search_part($db, (string)($_GET['kw'] ?? ''))]);

case 'search_machine':
    jout(true, ['rows' => ss_search_machine($db, (string)($_GET['kw'] ?? ''), 30, $asofParam())]);

/** 兩層挑選器的資料：mode＝machine（個別機台）／model（機台型號）／tool（量具）／空＝機台＋量具
 *  分組規則一律在 lib（機台依綁定的製程、量具依種類），畫面只負責排版 */
case 'equip_pick': {
    $mode = (string)($_GET['mode'] ?? '');
    $kw   = (string)($_GET['kw'] ?? '');
    $asof = $asofParam();
    jout(true, ['groups' => in_array($mode, ['machine', 'model', 'tool'], true)
                            ? ss_pick_groups($db, $mode, $kw, $asof)
                            : ss_equip_pick_groups($db, $kw, $asof)]);
}

/** 量具（檢驗設備一覽表）——設備操作說明書除了機台也能綁它 */
case 'search_tool':
    jout(true, ['rows' => ss_search_tool($db, (string)($_GET['kw'] ?? ''), 40, $asofParam())]);

case 'draw_candidates':
    jout(true, ['rows' => ss_part_draw_candidates($db, (int)($_GET['part_d_id'] ?? 0))]);

/* ─────────────── 寫入 ─────────────── */

case 'doc_save': {
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(ss_kinds()[$kind])) jerr('表單版面代碼不正確');
    $needEdit($kind);
    $docId = (int)($_POST['doc_id'] ?? 0);
    if ($docId > 0) {
        $old = ss_doc_get($db, $docId);
        if (!$old) jerr('找不到這份文件');
        if ((string)$old['kind'] !== $kind) jerr('不可以更換表單版面，請另建一份文件');
    }
    // 重複一律擋下（使用者要求「不可建立有兩份一樣料號／機台的資料」）。
    // 只有管理員能硬蓋過去，而且要明確送 dup_ok=1——這是給「紙本本來就有兩份要補進來」用的，
    // 畫面上不提供這個選項。
    $in = $_POST;
    $in['_dup_ok'] = (!empty($P['canAdmin']) && !empty($_POST['dup_ok'])) ? 1 : 0;

    $db->beginTransaction();
    try {
        $newDoc = $docId <= 0;
        $docId  = ss_doc_save($db, $in, $uid, (string)$P['name']);
        $verId  = (int)($_POST['ver_id'] ?? 0);
        if ($newDoc) $verId = ss_ver_create($db, $docId, $_POST, $uid);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
    jout(true, ['doc_id' => $docId, 'ver_id' => $verId]);
}

case 'ver_save': {
    $verId = (int)($_POST['ver_id'] ?? 0);
    [$kind, $v, $d] = $kindOfVer($verId);
    $needEdit($kind);
    $db->beginTransaction();
    try {
        ss_ver_save($db, $verId, $_POST, $uid);
        if (array_key_exists('steps', $_POST)) ss_steps_replace($db, $verId, $rows('steps'));
        if (array_key_exists('items', $_POST)) ss_items_replace($db, $verId, $rows('items'));
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
    jout(true, ['ver_id' => $verId]);
}

case 'ver_new': {
    $fromId = (int)($_POST['from_ver_id'] ?? 0);
    [$kind, $v, $d] = $kindOfVer($fromId);
    $needEdit($kind);
    $db->beginTransaction();
    try {
        $newId = ss_ver_clone($db, $fromId, $_POST, $uid);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
    jout(true, ['ver_id' => $newId]);
}

case 'submit': {
    $verId = (int)($_POST['ver_id'] ?? 0);
    [$kind, $v, $d] = $kindOfVer($verId);
    $needEdit($kind);
    // 指定填表人與簽核人、調整日期、強制自動簽核：只有管理員可以（一般人送出就是自己製表）
    $opt = [];
    if (!empty($P['canAdmin'])) {
        foreach (['maker_id', 'review_id', 'approve_id'] as $k) if (!empty($_POST[$k])) $opt[$k] = (int)$_POST[$k];
        if (!empty($_POST['sign_date'])) $opt['sign_date'] = (string)$_POST['sign_date'];
        if (!empty($_POST['auto'])) $opt['auto'] = true;
    }
    $db->beginTransaction();
    try {
        $res = ss_submit($db, $verId, $uid, $opt);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
    jout(true, $res);
}

case 'sign': {
    $verId = (int)($_POST['ver_id'] ?? 0);
    $slot  = (string)($_POST['slot'] ?? '');
    [$kind, $v, $d] = $kindOfVer($verId);
    $needSign($kind);
    if (!isset(ss_slots()[$slot])) jerr('簽核關卡代碼不正確');
    if ((string)$v['status'] === 'draft') jerr('這個版次還沒送簽');
    if ((string)$v['status'] === 'obsolete') jerr('已作廢的版次不可簽核');
    // 依序簽：前一關還沒簽就不給簽下一關（點開即刷新，畫面上會同步提示）
    $next = ss_next_slot($db, $verId);
    if ($next !== '' && $next !== $slot && empty($P['canAdmin'])) {
        jerr('請先完成「' . (ss_slots()[$next]['label'] ?? $next) . '」這一關');
    }
    // 指定別人簽章一律限管理員（補歷史文件用）；一般人只能簽自己
    $signer = (int)($_POST['user_id'] ?? 0) ?: $uid;
    if ($signer !== $uid && empty($P['canAdmin'])) jerr('只能簽自己的那一格');
    $isAuto = $signer !== $uid;               // 管理員代簽＝補登，畫面與列印一律不顯示此字樣
    $db->beginTransaction();
    try {
        ss_sign_set($db, $verId, $slot, $signer, (string)($_POST['sign_date'] ?? ''), $isAuto,
                    (string)($_POST['note'] ?? ''), 0, (int)($_POST['dept_id'] ?? 0));
        ss_maybe_approve($db, $verId);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        /* 最常見的是「用超級管理員（特殊帳號）按蓋這一格」——它不是真的員工，蓋不上去。
           原訊息只說「當時不在職」，看不出該怎麼辦，所以在這裡講清楚下一步（比照 ss_submit）。 */
        if ($signer === $uid && !empty($P['canAdmin'])) {
            jerr('你的帳號不能列為簽核人（' . $e->getMessage() . '）。'
               . '請用頁尾的「取消送簽（退回草稿）」把這一版退回，再重新送出簽核並指定簽核人員。');
        }
        jerr($e->getMessage());
    }
    jout(true, ['status' => (string)(ss_ver_get($db, $verId)['status'] ?? ''), 'next_slot' => ss_next_slot($db, $verId)]);
}

/**
 * 取消送簽，整版退回「尚未送審」（管理員限定）。使用者 2026-09-22 指定：
 * **不做「只取消其中一格」**——單格清掉之後這一版會停在「簽核中、卻一個章都沒有」，
 * 而重蓋是以登入者本人的身分蓋（超級管理員這種特殊帳號蓋不上去），等於整份文件卡死。
 * 要重來一律整版退回草稿，再重新送簽（管理員可勾自動簽核一次蓋滿）。
 */
case 'unsubmit': {
    $verId = (int)($_POST['ver_id'] ?? 0);
    [$kind, $v, $d] = $kindOfVer($verId);
    $needAdmin();
    if ((string)$v['status'] === 'draft') jerr('這個版次本來就是草稿');
    $db->beginTransaction();
    try { ss_unsubmit($db, $verId, $uid); $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
    jout(true, []);
}

case 'ver_obsolete': {
    $verId = (int)($_POST['ver_id'] ?? 0);
    [$kind] = $kindOfVer($verId);
    $needEdit($kind);
    ss_ver_obsolete($db, $verId, $uid);
    jout(true, []);
}

case 'doc_delete': {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $d = ss_doc_get($db, $docId);
    if (!$d) jerr('找不到這份文件');
    // 管理員一律可刪；一般使用者只能刪「自己建立、而且一個版次都還沒核准」的（使用者要求）
    [$ok, $why] = ss_can_delete_doc($db, $d, $uid, $P);
    if (!$ok) { http_response_code(403); jerr($why ?: '沒有刪除這份文件的權限'); }
    $db->prepare("UPDATE ss_doc SET is_deleted=1, modified_at=NOW(), modified_by=? WHERE doc_id=?")->execute([$uid, $docId]);
    jout(true, []);
}

/* ─────────────── 檔案 ─────────────── */

case 'file_upload': {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $d = ss_doc_get($db, $docId);
    if (!$d) jerr('找不到這份文件');
    $needEdit((string)$d['kind']);
    /* 核准之後只有管理員可以補附件（使用者 2026-09-22 要求）。
       內容仍然不可以改——ss_ver_save() 對非草稿一律擋下，這裡放行的只有「加檔案」。 */
    $vv = ss_ver_get($db, (int)($_POST['ver_id'] ?? 0));
    if ($vv && (string)$vv['status'] !== 'draft' && empty($P['canAdmin'])) {
        http_response_code(403);
        jerr('這一版已經送簽或核准了，只有管理員可以補附件');
    }
    $usage = (string)($_POST['usage'] ?? 'other');
    if (!in_array($usage, ['draw', 'step', 'scan', 'other', 'sec'], true)) jerr('檔案用途代碼不正確');
    // sec＝掛在某一個段落（操作方法／使用注意事項…）底下的說明圖，段落代碼要在登記表上
    $secKey = (string)($_POST['sec_key'] ?? '');
    if ($usage === 'sec') {
        if (!isset(ss_sections((string)$d['kind'])[$secKey])) jerr('段落代碼不正確');
    } else $secKey = '';
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) jerr('沒有收到檔案');
    if ((int)$_FILES['file']['size'] > 20 * 1024 * 1024) jerr('單一檔案上限 20MB');

    $orig = (string)$_FILES['file']['name'];
    $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    // 可執行與腳本副檔名一律擋下（附件目錄在 NAS 上，點下去就執行了）
    if (in_array($ext, ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'bat', 'cmd', 'com', 'sh', 'vbs', 'js', 'jar', 'msi'], true)) {
        jerr('這種副檔名不允許上傳');
    }
    if ($ext === '') jerr('檔案沒有副檔名');
    // 檔名帶 DB 的時間戳（**不可用 PHP 的 date()**：本站 PHP 是 UTC、MySQL 是本地，混用會差 8 小時）
    $now  = (string)$db->query("SELECT NOW()")->fetchColumn();
    $name = 'ss' . $docId . '_' . preg_replace('/\D/', '', $now) . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dir  = ss_attach_dir($db);
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $name)) {
        jerr('檔案寫入失敗，請確認附件資料夾設定與 NAS 連線');
    }
    try {
        $st = $db->prepare("INSERT INTO ss_file (doc_id, ver_id, usage_kind, sec_key, src, file_name, orig_name, mime, file_size, uploaded_at, uploaded_by)
                            VALUES (?,?,?,?,'upload',?,?,?,?,NOW(),?)");
        $st->execute([$docId, (int)($_POST['ver_id'] ?? 0) ?: null, $usage, $secKey ?: null, $name, $orig,
                      (string)($_FILES['file']['type'] ?? ''), (int)$_FILES['file']['size'], $uid]);
        $fileId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        // DB 寫不進去時要把剛落地的實體檔刪掉，否則 NAS 上會留一個沒人認得的孤兒檔
        @unlink(rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $name);
        jerr('檔案已上傳但寫入資料庫失敗：' . $e->getMessage());
    }
    jout(true, ['file_id' => $fileId, 'name' => $orig, 'is_image' => ss_is_image($orig) ? 1 : 0]);
}

/** 把料號附件「帶入」成這一版的圖面或步驟圖：只建關聯不複製檔（鐵律5） */
case 'file_link_part': {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $d = ss_doc_get($db, $docId);
    if (!$d) jerr('找不到這份文件');
    $needEdit((string)$d['kind']);
    $paId = (int)($_POST['part_attach_id'] ?? 0);
    $usage = (string)($_POST['usage'] ?? 'draw');
    if (!in_array($usage, ['draw', 'step'], true)) jerr('檔案用途代碼不正確');
    // 只准帶入「這份文件綁的那個料號」的附件，換個 id 就拿得到別的料號的圖是不行的
    $ok = false;
    foreach (ss_part_draw_candidates($db, (int)($d['part_d_id'] ?? 0)) as $c) if ((int)$c['id'] === $paId) { $ok = true; $nm = $c['name']; break; }
    if (!$ok) jerr('這個附件不屬於本文件綁定的料號');
    $st = $db->prepare("SELECT file_id FROM ss_file WHERE doc_id=? AND src='part' AND part_attach_id=? AND usage_kind=? LIMIT 1");
    $st->execute([$docId, $paId, $usage]);
    $fid = (int)$st->fetchColumn();
    if ($fid <= 0) {
        $st = $db->prepare("INSERT INTO ss_file (doc_id, ver_id, usage_kind, src, part_attach_id, orig_name, uploaded_at, uploaded_by)
                            VALUES (?,?,?, 'part', ?,?, NOW(), ?)");
        $st->execute([$docId, (int)($_POST['ver_id'] ?? 0) ?: null, $usage, $paId, $nm, $uid]);
        $fid = (int)$db->lastInsertId();
    }
    jout(true, ['file_id' => $fid, 'name' => $nm, 'is_image' => ss_is_image($nm) ? 1 : 0]);
}

case 'file_delete': {
    $fid = (int)($_POST['file_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ss_file WHERE file_id=?");
    $st->execute([$fid]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) jerr('找不到這個檔案');
    $d = ss_doc_get($db, (int)$f['doc_id']);
    if (!$d) jerr('找不到這份文件');
    $needEdit((string)$d['kind']);
    // 帶入的料號附件只解除關聯，**絕不可刪到料號主檔那個檔案**
    if ((string)$f['src'] === 'upload') {
        // 改版會把段落圖與圖面複製一列指到同一個實體檔，所以還有別列指著它就只刪資料列，
        // 不然刪掉新版的圖會讓舊版印出破圖（而且完全看不出原因）
        $st = $db->prepare("SELECT COUNT(*) FROM ss_file WHERE file_name=? AND file_id<>?");
        $st->execute([(string)$f['file_name'], $fid]);
        if ((int)$st->fetchColumn() === 0) {
            $p = ss_file_path($db, $f);
            if ($p && is_file($p)) @unlink($p);
        }
    }
    $db->prepare("DELETE FROM ss_file WHERE file_id=?")->execute([$fid]);
    $db->prepare("UPDATE ss_ver SET draw_file_id=NULL WHERE draw_file_id=?")->execute([$fid]);
    $db->prepare("UPDATE ss_step SET img_file_id=NULL WHERE img_file_id=?")->execute([$fid]);
    jout(true, []);
}

/**
 * 圖面旋轉。使用者拍板：**只轉這份文件，不動原檔**——SIP 的圖多半是從料號附件帶入的，
 * 轉原檔等於把料號主檔、圖面查閱那邊的圖一起轉掉。這裡只存角度，實際的旋轉結果由
 * ss_file_view_path() 產生一份快取檔。
 */
case 'file_rotate': {
    $fid = (int)($_POST['file_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ss_file WHERE file_id=?");
    $st->execute([$fid]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) jerr('找不到這個檔案');
    $d = ss_doc_get($db, (int)$f['doc_id']);
    if (!$d) jerr('找不到這份文件');
    $needEdit((string)$d['kind']);
    $step = (int)($_POST['deg'] ?? 90);                       // 相對旋轉：每按一次 ±90
    if (!in_array((($step % 360) + 360) % 360, [0, 90, 180, 270], true)) jerr('旋轉角度只能是 90 的倍數');
    $rot = ss_rot_norm((int)($f['rot'] ?? 0) + $step);
    $db->prepare("UPDATE ss_file SET rot=? WHERE file_id=?")->execute([$rot, $fid]);
    jout(true, ['rot' => $rot]);
}

/* ─────────────── 檢驗項目預設值（管理員） ─────────────── */

case 'tpl_get': {
    $k   = (string)($_GET['tpl_kind'] ?? 'std');
    $pno = (int)($_GET['process_no'] ?? 0);
    jout(true, [
        'rows'      => ss_tpl_rows($db, $k, $pno),
        'processes' => ss_tpl_processes($db),
        'cfg'       => ss_proc_cfg($db, $pno),
        'owner_depts' => array_values(array_filter(ss_owner_depts($db), fn($d) => !empty($d['on']))),
        'methods'     => ss_method_options($db)['list'],
        'tool_types'  => ss_tool_types($db),
        'freq_options'=> ss_freq_options($db),
        'symbols'     => ss_symbols($db),
    ]);
}

/** 從既有文件統計出「建議的預設項目」——只回建議，要不要存還是按儲存才算 */
case 'tpl_suggest': {
    $needAdmin();
    jout(true, ['rows' => ss_tpl_suggest($db, (string)($_GET['tpl_kind'] ?? 'std'), (int)($_GET['process_no'] ?? 0))]);
}

case 'tpl_save': {
    $needAdmin();
    $k   = (string)($_POST['tpl_kind'] ?? 'std');
    $pno = (int)($_POST['process_no'] ?? 0);
    $db->beginTransaction();
    try {
        ss_tpl_replace($db, $k, $pno, $rows('rows'), $uid);
        if ($k === 'proc') {
            // with_std 預設 0＝「這個製程有自己的項目時就不另外再帶全站共用」（2026-09-23 改的口徑）
            ss_proc_cfg_set($db, $pno, (int)($_POST['auto_apply'] ?? 1), (int)($_POST['with_std'] ?? 0), $uid);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
    jout(true, ['rows' => ss_tpl_rows($db, $k, $pno), 'processes' => ss_tpl_processes($db)]);
}

/* ─────────────── 設定（管理員） ─────────────── */

case 'settings_get': {
    $out = ['work_start' => ss_work_window($db)[0], 'work_end' => ss_work_window($db)[1], 'kinds' => []];
    foreach (ss_kinds() as $k => $def) {
        $row = ['auto_sign' => ss_auto_sign_on($db, $k) ? 1 : 0, 'signers' => [], 'signer_cfg' => []];
        foreach (array_keys(ss_slots()) as $slot) {
            $row['signers'][$slot] = ss_default_signer($db, $k, $slot);
            // 簽核人改成設「部門＋職稱」＋一位代理（使用者 2026-09-22 指定，不再設固定人員）
            $c = ss_signer_cfg($db, $k, $slot);
            [$who, $why] = ss_resolve_signer($db, $k, $slot, date('Y-m-d'));
            $c['preview_id']  = $who;
            $c['preview_why'] = $why;
            $c['preview_name'] = '';
            if ($who > 0) {
                $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
                $q->execute([$who]);
                $c['preview_name'] = (string)($q->fetchColumn() ?: '');
            }
            $row['signer_cfg'][$slot] = $c;
        }
        $row['paper'] = ss_setting_get($db, 'paper_' . $k, null) ?: ss_paper($db, ['kind' => $k, 'scope' => 'general']);
        $doc = eg_asdoc_get($db, $def['module']);
        $row['as_doc_id'] = $doc ? (int)$doc['id'] : 0;
        $row['as_no']     = $doc ? eg_asdoc_no($doc) : '';
        $out['kinds'][$k] = $row;
    }
    foreach (array_keys(ss_slots()) as $slot) {
        $t = ss_stamp_tpl($db, $slot);
        $out['stamp'][$slot] = $t ? (int)$t['id'] : 0;
    }
    try {
        $out['stamp_templates'] = $db->query("SELECT id, tpl_name FROM stamp_template WHERE is_active=1 ORDER BY id")
                                     ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $out['stamp_templates'] = []; }
    $out['people'] = ss_signer_candidates($db, date('Y-m-d'));
    // 擔當者部門（顯示文字可改）與檢驗方法選項（由量具類型混合＋自建項目）
    $out['owner_depts'] = ss_owner_depts($db);
    /* 部門一律**整棵樹依組織順序**排（董事長室→總經理室→各課→各組），
       而且**不可以再依 level 過濾掉上層**——總經理與董事長就掛在 level 1/2 的那兩個單位底下，
       過濾掉之後「審核／核准」就選不到總經理室（使用者 2026-09-22 回報）。 */
    $out['departments']    = ss_dept_tree_rows($db);
    $out['dept_positions'] = ss_dept_position_map($db);
    $out['methods']    = ss_method_options($db);
    $out['tool_types'] = ss_tool_types($db);
    try {
        $out['positions'] = $db->query("SELECT id, name FROM position ORDER BY COALESCE(sort_order,999), id")
                               ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $out['positions'] = []; }
    $out['papers']  = ss_papers();
    $out['orients'] = ss_orients();
    // 標準檢驗指導書左下角那塊固定的「注意事項」（每一份都一樣，所以放設定不是逐份打）
    $out['sip_notice_default'] = (string)ss_setting_get($db, 'sip_notice_default', '');
    // 2026-09-23：檢驗頻率下拉選項／注意事項可存範本（可綁客戶）／型式建議選項
    $out['freq_options']    = ss_freq_options($db);
    $out['notice_tpls']     = ss_notice_tpls($db, '');
    $out['variant_options'] = ss_variant_options($db);
    $out['variant_max']     = SS_VARIANT_MAX;
    $out['symbols']         = ss_symbols($db);
    jout(true, $out);
}

case 'settings_save': {
    $needAdmin();
    foreach (['work_start', 'work_end'] as $k) {
        if (!array_key_exists($k, $_POST)) continue;
        $v = trim((string)$_POST[$k]);
        if ($v !== '' && !preg_match('/^\d{2}:\d{2}$/', $v)) jerr('上班時段格式要像 08:00');
        ss_setting_set($db, $k, $v);
    }
    foreach (ss_kinds() as $k => $def) {
        if (array_key_exists('auto_' . $k, $_POST)) ss_setting_set($db, 'auto_sign_' . $k, (int)$_POST['auto_' . $k] ? 1 : 0);
        foreach (array_keys(ss_slots()) as $slot) {
            $f = 'signer_' . $k . '_' . $slot;
            if (!array_key_exists($f, $_POST)) continue;
            $sid = (int)$_POST[$f];
            if ($sid > 0) {
                $st = $db->prepare("SELECT 1 FROM `user` WHERE id=? AND state<>0");
                $st->execute([$sid]);
                if (!$st->fetchColumn()) jerr('指定的簽核人員不存在或已離職');
            }
            ss_setting_set($db, $f, $sid);
        }
    }
    // 簽核人：部門＋職稱＋一位代理（不存 user_id）
    foreach (ss_kinds() as $k => $def) {
        foreach (array_keys(ss_slots()) as $slot) {
            $f = 'signercfg_' . $k . '_' . $slot;
            if (!array_key_exists($f, $_POST)) continue;
            $c = json_decode((string)$_POST[$f], true);
            if (!is_array($c)) jerr('簽核人設定格式不正確');
            foreach (['dept_id' => '部門', 'dep_dept_id' => '代理部門'] as $key => $lab) {
                $v = (int)($c[$key] ?? 0);
                if ($v <= 0) continue;
                $st = $db->prepare("SELECT 1 FROM department WHERE id=?");
                $st->execute([$v]);
                if (!$st->fetchColumn()) jerr($lab . '不存在（id ' . $v . '）');
            }
            foreach (['position_id' => '職稱', 'dep_position_id' => '代理職稱'] as $key => $lab) {
                $v = (int)($c[$key] ?? 0);
                if ($v <= 0) continue;
                $st = $db->prepare("SELECT 1 FROM position WHERE id=?");
                $st->execute([$v]);
                if (!$st->fetchColumn()) jerr($lab . '不存在（id ' . $v . '）');
            }
            ss_signer_cfg_set($db, $k, $slot, $c);
        }
        // 列印紙張與方向（逐版面）
        $f = 'paper_' . $k;
        if (array_key_exists($f, $_POST)) {
            $c = json_decode((string)$_POST[$f], true);
            $size = strtoupper((string)($c['size'] ?? ''));
            $ori  = strtolower((string)($c['orient'] ?? ''));
            if (!isset(ss_papers()[$size]) || !isset(ss_orients()[$ori])) jerr('紙張大小或方向不正確');
            ss_setting_set($db, $f, ['size' => $size, 'orient' => $ori]);
        }
    }
    foreach (array_keys(ss_slots()) as $slot) {
        $f = 'stamp_' . $slot;
        if (!array_key_exists($f, $_POST)) continue;
        $tid = (int)$_POST[$f];
        if ($tid > 0) {
            $st = $db->prepare("SELECT 1 FROM stamp_template WHERE id=? AND is_active=1");
            $st->execute([$tid]);
            if (!$st->fetchColumn()) jerr('指定的圖章模板不存在或已停用');
        }
        // 設定鍵一定要跟 ss_stamp_tpl() 讀的那個一樣（stamp_tpl_<slot>）。
        // 原本寫成 stamp_<slot>，於是「存得進去、卻永遠讀不回來」，畫面上就是
        // 每次存完又變回「預設回墨印」，而且完全不報錯。
        ss_setting_set($db, 'stamp_tpl_' . $slot, $tid);
    }

    // 擔當者部門：只存 dept_id 與顯示文字；部門名稱一律即時查，不在這裡存第二份（鐵律4）
    if (array_key_exists('owner_depts', $_POST)) {
        $out = [];
        foreach ($rows('owner_depts') as $r) {
            $id = (int)($r['dept_id'] ?? 0);
            if ($id <= 0) continue;
            $st = $db->prepare("SELECT 1 FROM department WHERE id=?");
            $st->execute([$id]);
            if (!$st->fetchColumn()) jerr('指定的部門不存在（id ' . $id . '）');
            $lab = trim((string)($r['label'] ?? ''));
            if (mb_strlen($lab) > 20) jerr('擔當者顯示文字最多 20 個字');
            $out[] = ['dept_id' => $id, 'label' => $lab, 'on' => empty($r['on']) ? 0 : 1];
        }
        ss_setting_set($db, 'owner_depts', $out);
    }

    // 檢驗方法：挑哪幾個量具類型 ＋ 自建的文字項目
    if (array_key_exists('method_tool_types', $_POST)) {
        $valid = [];
        foreach (ss_tool_types($db) as $t) $valid[(int)$t['id']] = 1;
        $ids = [];
        foreach ($rows('method_tool_types') as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            if (empty($valid[$id])) jerr('量具類型不存在（id ' . $id . '）');
            $ids[] = $id;
        }
        ss_setting_set($db, 'method_tool_types', array_values(array_unique($ids)));
    }
    if (array_key_exists('sip_notice_default', $_POST)) {
        $t = trim((string)$_POST['sip_notice_default']);
        if (mb_strlen($t) > 2000) jerr('注意事項最多 2000 個字');
        ss_setting_set($db, 'sip_notice_default', $t);
    }
    /* 檢驗頻率的下拉選項（使用者 2026-09-23）。空陣列也要被尊重＝管理員刻意不給選項、一律自行輸入。 */
    if (array_key_exists('freq_options', $_POST)) {
        $fo = [];
        foreach ($rows('freq_options') as $s) {
            $s = trim((string)$s);
            if ($s === '') continue;
            if (mb_strlen($s) > 40) jerr('檢驗頻率選項最多 40 個字');
            if (!in_array($s, $fo, true)) $fo[] = $s;
        }
        ss_setting_set($db, 'freq_options', $fo);
    }
    /* 型式的建議選項 */
    if (array_key_exists('variant_options', $_POST)) {
        $vo = [];
        foreach ($rows('variant_options') as $s) {
            $s = trim((string)$s);
            if ($s === '') continue;
            if (mb_strlen($s) > 30) jerr('型式最多 30 個字');
            if (!in_array($s, $vo, true)) $vo[] = $s;
        }
        ss_setting_set($db, 'variant_options', $vo);
    }
    /* 注意事項範本（可綁客戶）。**客戶編號是 char(11) 文字不可 intval**，
       而且一律回主檔確認存在——打錯一個字那筆範本永遠不會被帶出來，還完全不報錯。 */
    if (array_key_exists('notice_tpls', $_POST)) {
        $nt = [];
        foreach ($rows('notice_tpls') as $r) {
            $name = trim((string)($r['name'] ?? ''));
            $body = trim((string)($r['body'] ?? ''));
            $cid  = trim((string)($r['customer_id'] ?? ''));
            if ($name === '' && $body === '') continue;
            if ($name === '') jerr('注意事項範本要有名稱');
            if (mb_strlen($name) > 40)   jerr('注意事項範本名稱最多 40 個字');
            if (mb_strlen($body) > 2000) jerr('注意事項範本內容最多 2000 個字');
            $cname = '';
            if ($cid !== '') {
                $st = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=?");
                $st->execute([$cid]);
                $cname = (string)($st->fetchColumn() ?: '');
                if ($cname === '') jerr('注意事項範本「' . $name . '」綁的客戶不存在（' . $cid . '），請從清單挑');
            }
            $nt[] = ['name' => $name, 'body' => $body, 'customer_id' => $cid, 'customer_name' => $cname];
        }
        ss_setting_set($db, 'notice_tpls', $nt);
    }
    if (array_key_exists('method_extra', $_POST)) {
        $ex = [];
        foreach ($rows('method_extra') as $s) {
            $s = trim((string)$s);
            if ($s === '') continue;
            if (mb_strlen($s) > 60) jerr('自建的檢驗方法最多 60 個字');
            $ex[] = $s;
        }
        ss_setting_set($db, 'method_extra', array_values(array_unique($ex)));
    }
    jout(true, []);
}

case 'asdoc_list': {
    $needAdmin();
    $kind = (string)($_GET['kind'] ?? $_POST['kind'] ?? '');
    if (!isset(ss_kinds()[$kind])) jerr('表單版面代碼不正確');
    $cur = eg_asdoc_get($db, ss_kinds()[$kind]['module']);
    jout(true, ['docs' => eg_asdoc_list($db), 'current' => $cur ? (int)$cur['id'] : 0]);
}

case 'asdoc_save': {
    $needAdmin();
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(ss_kinds()[$kind])) jerr('表單版面代碼不正確');
    eg_asdoc_save($db, ss_kinds()[$kind]['module'], (int)($_POST['doc_id'] ?? 0), (string)$P['name']);
    $doc = eg_asdoc_get($db, ss_kinds()[$kind]['module']);
    jout(true, ['doc_no' => $doc ? eg_asdoc_no($doc) : '']);
}

default:
    jerr('無效的操作：' . $action);
}
