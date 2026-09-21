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
    ]);

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
    jout(true, $full);
}

case 'signer_candidates': {
    $formDate = (string)($_GET['form_date'] ?? date('Y-m-d'));
    $signDate = (string)($_GET['sign_date'] ?? '');
    jout(true, ['rows' => ss_signer_candidates($db, $formDate, $signDate)]);
}

case 'search_part':
    jout(true, ['rows' => ss_search_part($db, (string)($_GET['kw'] ?? ''))]);

case 'search_machine':
    jout(true, ['rows' => ss_search_machine($db, (string)($_GET['kw'] ?? ''))]);

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
    $db->beginTransaction();
    try {
        $newDoc = $docId <= 0;
        $docId  = ss_doc_save($db, $_POST, $uid, (string)$P['name']);
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
    } catch (Throwable $e) { $db->rollBack(); jerr($e->getMessage()); }
    jout(true, ['status' => (string)(ss_ver_get($db, $verId)['status'] ?? ''), 'next_slot' => ss_next_slot($db, $verId)]);
}

case 'sign_clear': {
    $verId = (int)($_POST['ver_id'] ?? 0);
    $slot  = (string)($_POST['slot'] ?? '');
    [$kind] = $kindOfVer($verId);
    $needAdmin();
    if (!isset(ss_slots()[$slot])) jerr('簽核關卡代碼不正確');
    $db->prepare("DELETE FROM ss_sign WHERE ver_id=? AND slot=?")->execute([$verId, $slot]);
    $db->prepare("UPDATE ss_ver SET status='submitted' WHERE ver_id=? AND status='approved'")->execute([$verId]);
    $v = ss_ver_get($db, $verId);
    if ($v) ss_refresh_cur_ver($db, (int)$v['doc_id']);
    jout(true, ['next_slot' => ss_next_slot($db, $verId)]);
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
    $needAdmin();
    $db->prepare("UPDATE ss_doc SET is_deleted=1, modified_at=NOW(), modified_by=? WHERE doc_id=?")->execute([$uid, $docId]);
    jout(true, []);
}

/* ─────────────── 檔案 ─────────────── */

case 'file_upload': {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $d = ss_doc_get($db, $docId);
    if (!$d) jerr('找不到這份文件');
    $needEdit((string)$d['kind']);
    $usage = (string)($_POST['usage'] ?? 'other');
    if (!in_array($usage, ['draw', 'step', 'scan', 'other'], true)) jerr('檔案用途代碼不正確');
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
        $st = $db->prepare("INSERT INTO ss_file (doc_id, ver_id, usage_kind, src, file_name, orig_name, mime, file_size, uploaded_at, uploaded_by)
                            VALUES (?,?,?,'upload',?,?,?,?,NOW(),?)");
        $st->execute([$docId, (int)($_POST['ver_id'] ?? 0) ?: null, $usage, $name, $orig,
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
        $p = ss_file_path($db, $f);
        if ($p && is_file($p)) @unlink($p);
    }
    $db->prepare("DELETE FROM ss_file WHERE file_id=?")->execute([$fid]);
    $db->prepare("UPDATE ss_ver SET draw_file_id=NULL WHERE draw_file_id=?")->execute([$fid]);
    $db->prepare("UPDATE ss_step SET img_file_id=NULL WHERE img_file_id=?")->execute([$fid]);
    jout(true, []);
}

/* ─────────────── 設定（管理員） ─────────────── */

case 'settings_get': {
    $out = ['work_start' => ss_work_window($db)[0], 'work_end' => ss_work_window($db)[1], 'kinds' => []];
    foreach (ss_kinds() as $k => $def) {
        $row = ['auto_sign' => ss_auto_sign_on($db, $k) ? 1 : 0, 'signers' => []];
        foreach (array_keys(ss_slots()) as $slot) $row['signers'][$slot] = ss_default_signer($db, $k, $slot);
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
    foreach (array_keys(ss_slots()) as $slot) {
        $f = 'stamp_' . $slot;
        if (!array_key_exists($f, $_POST)) continue;
        $tid = (int)$_POST[$f];
        if ($tid > 0) {
            $st = $db->prepare("SELECT 1 FROM stamp_template WHERE id=? AND is_active=1");
            $st->execute([$tid]);
            if (!$st->fetchColumn()) jerr('指定的圖章模板不存在或已停用');
        }
        ss_setting_set($db, 'stamp_' . $slot, $tid);
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
