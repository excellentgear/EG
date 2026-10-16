<?php
/**
 * AsDocContent_API.php — AS 文件「線上版內容」的資料介面（2026-09-22 新增）
 *
 * 做什麼：二階程序書這種整份文件直接在網頁上編輯（富文字＋圖片＋可再編輯的流程圖），
 * 取代「線下 Word 編→上傳→轉 PDF 看」。規則一律放 src/common/as_doc_content_lib.php，
 * 匯入轉檔放 as_doc_import_lib.php，這裡只做「守門＋參數檢查＋呼叫」。
 *
 * 權限刻意沿用 as_doc 模組（不另開角色模組）：
 *   讀＝AS 文件檢視權；寫＝as_doc 頁面 A 權 或 新功能碼 asdoc_edit_content。
 * 鐵律8：前端擋過的每一條這裡一律再擋一次（權限、版次存在、資產屬於這份內容、檔案是真的圖）。
 */
session_start();

require_once __DIR__ . '/../common/api_guard.php';          // 在職狀態守門（離職/留停者一律 403）
require_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/as_doc_content_lib.php';
require_once __DIR__ . '/../common/as_doc_import_lib.php';
require_once __DIR__ . '/../common/as_doc_heading_lib.php';
require_once __DIR__ . '/../common/attach_lib.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
/* 「這是不是寫入」一律看**請求方法**，不可以看 action 放在哪裡（2026-09-22 測試抓到的真漏洞）。
   原本寫的是 isset($_POST['action'])，於是只要把 action 改放在查詢字串
   （POST 到 ?action=save，body 只放資料），CSRF 檢查就整段跳過——
   而 $action 那一行仍然吃得到 $_GET['action']，端點照常執行。
   本 API 的讀取端點全部是 GET，所以「POST 進來就是寫入」這個判定是成立的；
   前端每一支 POST 本來就都有帶 csrf，改嚴不影響既有呼叫端。 */
$isWrite = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

/* 圖檔輸出不是 JSON，要在設 header 之前處理掉 */
function adcApiJsonHeader(): void { header('Content-Type: application/json; charset=utf-8'); }
function jout($ok, $data = []) {
    adcApiJsonHeader();
    echo json_encode(array_merge(['success' => $ok], is_array($data) ? $data : ['message' => $data]), JSON_UNESCAPED_UNICODE);
    exit;
}
function jerr($msg, $code = '') { jout(false, ['message' => $msg, 'code' => $code]); }

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) { http_response_code(401); jerr('尚未登入或登入已逾時，請重新整理頁面後再試', 'LOGIN'); }

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
adc_ensure_schema($db);

$P = adc_perms($db, $uid);
if (!$P['view']) { http_response_code(403); jerr('沒有 AS 文件的檢視權限'); }

/* 權限要**逐文件**再算一次：這份文件的負責課室／所屬部門的人也能編輯它
   （使用者 2026-10-15）。幾乎每個端點都帶 version_id，拿它推出 doc_id。
   ⚠ 一定要在 switch 之前就算好——各 case 是「先 needEdit 再 needVersion」，
     等到 needVersion 才升級的話，needEdit 早就把人擋在外面了。
   設定類端點走 needAdmin()，不受這裡影響。 */
$pvid = (int)($_REQUEST['version_id'] ?? 0);
if ($pvid > 0) {
    $pv = adc_version_info($db, $pvid);
    if ($pv) $P = adc_perms($db, $uid, (int)$pv['doc_id']);
}

/* 寫入一律驗 CSRF。先驗登入再驗 CSRF——token 會在同一個請求裡重新產生，
   順序顛倒的話已登出的人會一直看到「憑證失效」卻永遠存不進去
   （記憶 session_gc_csrf_false_alarm）。 */
if ($isWrite) {
    $tok = $_POST['csrf'] ?? '';
    if (empty($_SESSION['adc_csrf']) || !hash_equals((string)$_SESSION['adc_csrf'], (string)$tok)) {
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 'CSRF');
    }
}
function needEdit(array $P) {
    if (empty($P['edit'])) {
        http_response_code(403);
        jerr('沒有編輯這份文件的權限。可以編輯的人：AS 文件管理員，'
           . '或這份文件在「AS 文件管理」裡登記的負責課室／所屬部門的人員。');
    }
}
function needAdmin(array $P) { if (empty($P['admin'])) { http_response_code(403); jerr('這項設定只有 AS 文件管理員可以改'); } }
/* 送簽用的共用庫；**一定要宣告在 switch 外面**——寫在 case 之間的話，
   前一個 case 以 jout()/exit 結束就永遠執行不到那幾行，函式根本不會被定義。 */
function adsLib() { require_once __DIR__ . '/../common/as_doc_sign_lib.php'; }

/** 版次守門：不存在／已刪除一律擋；已廢止的文件只有管理員能碰（比照 AS 文件管理的既有口徑） */
function needVersion(PDO $db, array $P, int $vid): array {
    $v = adc_version_info($db, $vid);
    if (!$v) jerr('找不到這個版次（可能已被刪除）');
    if ((int)$v['is_obsolete'] === 1 && empty($P['admin'])) {
        http_response_code(403); jerr('這份文件已廢止，只有管理員能開啟');
    }
    return $v;
}

/** 資產守門：一定要屬於「這個版次」的內容，否則把編號改一改就能看別份文件的圖 */
function needAsset(PDO $db, array $P, int $assetId, ?int $vid = null): array {
    $a = adc_asset($db, $assetId);
    if (!$a) jerr('找不到這個圖片／流程圖');
    needVersion($db, $P, (int)$a['version_id']);
    if ($vid !== null && (int)$a['version_id'] !== $vid) jerr('這個圖片不屬於目前編輯的版次');
    return $a;
}

try {
switch ($action) {

/* ── 圖檔輸出（inline 顯示／列印用；有裁切就輸出裁切後的） ───────────────── */
case 'asset': {
    $a = needAsset($db, $P, (int)($_GET['id'] ?? 0));
    $p = adc_asset_display_path($db, $a);
    if (!is_file($p)) { http_response_code(404); exit('not found'); }
    $mime = (string)(@getimagesize($p)['mime'] ?? 'application/octet-stream');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($p));
    // 快取刻意只給 60 秒：流程圖改完或重新裁切後，別人不該還看到舊圖一小時
    header('Cache-Control: private, max-age=60');
    if (!empty($_GET['dl'])) {
        eg_attach_send_disposition(($a['orig_name'] ?: $a['file_name']));
    }
    readfile($p);
    exit;
}

/* ── 取內容 ───────────────────────────────────────────────────────────── */
case 'get': {
    $vid = (int)($_GET['version_id'] ?? 0);
    $v = needVersion($db, $P, $vid);
    $c = adc_content_by_version($db, $vid);
    $assets = $c ? adc_assets($db, (int)$c['id']) : [];
    $out = [];
    foreach ($assets as $a) {
        $out[] = ['id' => (int)$a['id'], 'kind' => $a['kind'],
                  'crop' => $a['crop_json'] ? json_decode($a['crop_json'], true) : null,
                  'has_flow' => !empty($a['flow_json']), 'orig_name' => $a['orig_name']];
    }
    // 同一份文件的其他版次（要做「從舊版複製內容」時用得到）
    $others = [];
    try {
        $st = $db->prepare("SELECT v.id, v.version, v.revised_date,
                                   (SELECT COUNT(*) FROM as_doc_content cc WHERE cc.version_id=v.id AND cc.content_html IS NOT NULL) has_online
                            FROM as_document_version v WHERE v.doc_id=? ORDER BY v.revised_date DESC, v.id DESC");
        $st->execute([(int)$v['doc_id']]);
        $others = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    if (empty($_SESSION['adc_csrf'])) $_SESSION['adc_csrf'] = bin2hex(random_bytes(16));
    jout(true, [
        'version' => $v,
        'content' => $c ? [
            'id' => (int)$c['id'],
            'html' => (string)$c['content_html'],
            'is_primary' => (int)$c['is_primary'],
            'page_size' => $c['page_size'], 'orientation' => $c['orientation'],
            'import_src' => $c['import_src'], 'imported_at' => $c['imported_at'],
            'report' => $c['import_report_json'] ? json_decode($c['import_report_json'], true) : null,
            'updated_at' => $c['updated_at'],
        ] : null,
        'assets' => $out,
        'versions' => $others,
        'perms' => $P,
        'csrf' => $_SESSION['adc_csrf'],
    ]);
}

/* ── 版面樣板（封面／制修訂紀錄書／目錄／頁首／頁尾）─────────────────────
   HTML 一律由 as_doc_tpl_lib 產生，編輯器只是把頁首樣板裡的 {{PAGE}} 之類代入，
   **不在 JS 再組一次版面**——兩邊各寫一份版面一定會走鐘（鐵律4）。 */
case 'tpl': {
    require_once __DIR__ . '/../common/as_doc_tpl_lib.php';
    $vid = (int)($_GET['version_id'] ?? 0);
    $v = needVersion($db, $P, $vid);
    $ctx = adt_context($db, $vid);
    if (!$ctx) jerr('找不到這個版次');
    $c = adc_content_by_version($db, $vid);
    $body = $c ? adc_hydrate_html($db, (string)$c['content_html'], (int)$c['id'],
                                  '../../src/store/AsDocContent_API.php?action=asset&id=') : '';
    $conts = adt_split_pages($body);
    $sys   = adt_system_pages($ctx, $conts);
    $pv    = adt_page_versions($ctx['versions'], count($conts), $ctx['version']);

    // 部門清單給「發行單位」挑選用
    $depts = [];
    try {
        $depts = $db->query("SELECT id, name, level FROM department ORDER BY level, sort_order, name")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($depts as &$d2) { $d2['label'] = adt_dept_label($db, (int)$d2['id']); }
        unset($d2);
    } catch (Throwable $e) {}

    jout(true, [
        'sys'       => $sys,
        'sys_count' => count($sys),
        // 頁首是「一頁一個樣」，所以回傳帶佔位符的樣板由前端代入（結構仍只有 PHP 這一份）
        'hdr_tpl'   => adt_header_html($ctx, '{{PAGE}}', '{{TOTAL}}', '{{VER}}'),
        'ftr'       => adt_footer_html($ctx),
        'page_vers' => $pv,
        'doc_ver'   => $ctx['version'],
        'doc_no'    => $ctx['doc_no'],
        'doc_name'  => $ctx['doc_name'],
        'kind'      => $ctx['kind'],
        'level'     => $ctx['doc_level'],
        // doc_level 是中文（一階／二階／四階），前端不要自己比字串
        'is_level1' => adt_is_level1($ctx),
        'cfg'       => $ctx['cfg'],
        // 發行單位：issue_dept 是「實際會印在紙上的那一個」（沒設定時＝文件自己的部門），
        // dept_label 則是文件自己的部門，設定跳窗要靠它說明「留空會變成什麼」
        'issue_dept'   => $ctx['issue_dept'],
        'dept_label'   => $ctx['dept_label'],
        'foot_default' => ADT_FOOT_LEFT_DEFAULT,
        // 公版設定（表格字型／字級／粗細＋框線型式）：CSS 變數覆寫，兩邊都注入同一段
        'style'      => adt_style_get($db),
        'style_css'  => adt_style_css($db),
        'style_opts' => ['fonts' => adt_style_fonts(), 'fonts_en' => adt_style_fonts_en(),
                         'borders' => adt_style_borders(),
                         'widths' => adt_style_widths(), 'colors' => adt_style_colors(),
                         'hdr_sizes' => adt_style_hdr_sizes()],
        // 英數＋中文組好的 font-family，預覽直接套這一串（不要在前端再拼一次）
        'style_stack' => adt_style_font_stack(adt_style_get($db)),
        'depts'     => $depts,
        'can_edit'  => !empty($P['edit']),
    ]);
}

/* ── 版面樣板設定（發行單位／頁尾字樣／封面英文／要不要目錄）───────────── */
case 'tpl_save': {
    needEdit($P);
    require_once __DIR__ . '/../common/as_doc_tpl_lib.php';
    $vid = (int)($_POST['version_id'] ?? 0);
    $v = needVersion($db, $P, $vid);
    $in = [];
    foreach (['issue_dept_id', 'cover_en', 'foot_left', 'toc_on'] as $k) {
        if (array_key_exists($k, $_POST)) $in[$k] = $_POST[$k];
    }
    if (!empty($in['issue_dept_id'])) {
        $st = $db->prepare("SELECT COUNT(*) FROM department WHERE id=?");
        $st->execute([(int)$in['issue_dept_id']]);
        if (!(int)$st->fetchColumn()) jerr('選到的發行單位不存在');
    }
    if (!adt_settings_save($db, (int)$v['doc_id'], $in, $uid)) jerr('設定存檔失敗');
    jout(true, ['message' => '版面設定已存檔']);
}

/* ── 送簽（制修訂／審查／核准）─────────────────────────────────────────
   規則一律在 as_doc_sign_lib，這裡只做守門與呼叫。 */

/** 簽核設定（管理員） */
case 'sign_cfg': {
    adsLib();
    $deptId = (int)($_GET['dept_id'] ?? 0);
    $rows = ads_cfg_rows($db, $deptId);
    // 讓畫面知道這組設定是「這個部門自己的」還是「沿用全站預設」
    $own = $deptId > 0 && (int)($rows[0]['_scope'] ?? 0) === $deptId;
    $depts = $db->query("SELECT id, name FROM department ORDER BY level, sort_order, name")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $poss  = $db->query("SELECT id, name FROM position ORDER BY sort_order, id")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    require_once __DIR__ . '/../common/people_lib.php';
    jout(true, [
        'rows' => $rows, 'scope_is_own' => $own, 'dept_id' => $deptId,
        'stages' => ads_stages(), 'modes' => ads_modes(),
        'depts' => $depts, 'positions' => $poss,
        'people' => array_map(function ($p) {
            return ['id' => (int)$p['id'], 'name' => $p['user_cname'],
                    'dept' => $p['dept_name'] ?? '', 'pos' => $p['position_name'] ?? ''];
        }, eg_people_list($db, [])),
        'can_admin' => !empty($P['admin']),
    ]);
}
case 'sign_cfg_save': {
    needAdmin($P);
    adsLib();
    $deptId = (int)($_POST['dept_id'] ?? 0);
    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($rows)) jerr('設定內容格式不正確');
    $r = ads_cfg_save($db, $deptId, $rows, $uid);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['message' => $r['msg'], 'count' => $r['count'] ?? 0]);
}

/** 公版設定（管理員）：表格字型／字級／粗細與框線型式，全站文件共用 */
case 'tpl_style_save': {
    needAdmin($P);
    require_once __DIR__ . '/../common/as_doc_tpl_lib.php';
    $in = [];
    foreach (['tbl_font','tbl_font_en','tbl_size','tbl_weight','hdr_size','hdr_co_mode','brd_style','brd_w','brd_color','cell_pad',
              'body_frame','body_pad'] as $k) {
        if (array_key_exists($k, $_POST)) $in[$k] = $_POST[$k];
    }
    $r = adt_style_save($db, $in, $uid);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['message' => $r['msg'], 'style' => $r['style'], 'style_css' => adt_style_css($db)]);
}

/** 這個版次目前的送簽狀態＋可挑的人 */
case 'sign_state': {
    adsLib();
    $vid = (int)($_GET['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    // 帶目前使用者：最後修改人是系統帳號或已離職時，制修訂那一關退回用「現在這個人」
    $plan = ads_plan($db, $vid, $uid);
    jout(true, [
        'state' => ads_state($db, $vid),
        'plan'  => $plan,
        'me'    => $uid,
        'perms' => $P,
    ]);
}
case 'sign_submit': {
    needEdit($P);
    adsLib();
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    $picks = json_decode((string)($_POST['picks'] ?? '{}'), true);
    if (!is_array($picks)) $picks = [];
    $name = (string)($_SESSION['userCname'] ?? $_SESSION['userName'] ?? '');
    if ($name === '') {
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$uid]); $name = (string)$st->fetchColumn();
    }
    $r = ads_submit($db, $vid, $picks, $uid, $name);
    if (empty($r['ok'])) jerr($r['msg'], !empty($r['need_cfg']) ? 'NEED_CFG' : '');
    jout(true, $r);
}
case 'sign_decide': {
    adsLib();
    // 簽核不需要編輯權（被指派的簽核人多半不是編輯者），
    // 但一定要是「這一關指定的那個人」——判定在 lib 裡再擋一次
    $stepId = (int)($_POST['step_id'] ?? 0);
    $ok = !empty($_POST['ok']);
    $note = (string)($_POST['note'] ?? '');
    $r = ads_decide($db, $stepId, $uid, $ok, $note, !empty($P['admin']));
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, $r);
}
case 'sign_cancel': {
    needEdit($P);
    adsLib();
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    $r = ads_cancel($db, $vid, $uid);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, $r);
}
case 'sign_release': {
    needEdit($P);
    adsLib();
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    $name = (string)($_SESSION['userCname'] ?? $_SESSION['userName'] ?? '');
    if ($name === '') {
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$uid]); $name = (string)$st->fetchColumn();
    }
    $r = ads_release($db, $vid, $uid, $name);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, $r);
}

/* ── 存內容 ───────────────────────────────────────────────────────────── */
case 'save': {
    needEdit($P);
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    $opt = [];
    // array_key_exists 判「有沒有送這個欄位」：沒送＝不要動它，送空的才是真的改
    //（本專案在 save_head／row_save／列印設定都踩過「沒送的欄位被寫成 NULL」）
    foreach (['is_primary', 'page_size', 'orientation'] as $k) {
        if (array_key_exists($k, $_POST)) $opt[$k] = $_POST[$k];
    }
    $r = adc_content_save($db, $vid, (string)($_POST['html'] ?? ''), $opt, $uid);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['message' => $r['msg'], 'gc' => $r['gc'] ?? 0, 'content_id' => $r['content_id'] ?? 0]);
}

/* ── 上傳圖片 ─────────────────────────────────────────────────────────── */
case 'upload_image': {
    needEdit($P);
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    $c = adc_content_ensure($db, $vid, $uid);
    if (!$c) jerr('找不到這個版次');
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        jerr('沒有收到檔案（或檔案太大被伺服器擋掉）');
    }
    $tmp = $_FILES['file']['tmp_name'];
    $name = (string)($_FILES['file']['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $bytes = (string)@file_get_contents($tmp);
    $r = adc_asset_store($db, (int)$c['id'], 'image', $bytes, $ext, $name, null, $uid);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['id' => $r['id'], 'w' => $r['w'], 'h' => $r['h']]);
}

/* ── 流程圖：存新的或覆寫既有的 ───────────────────────────────────────── */
case 'save_flow': {
    needEdit($P);
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    $c = adc_content_ensure($db, $vid, $uid);
    if (!$c) jerr('找不到這個版次');

    $png = (string)($_POST['png'] ?? '');
    if (strpos($png, 'base64,') !== false) $png = substr($png, strpos($png, 'base64,') + 7);
    $bytes = base64_decode($png, true);
    if ($bytes === false || $bytes === '') jerr('流程圖圖檔資料不正確');
    $json = (string)($_POST['flow_json'] ?? '');
    if ($json === '' || json_decode($json) === null) jerr('流程圖的編輯資料不正確');
    if (strlen($json) > 8 * 1024 * 1024) jerr('流程圖太複雜（編輯資料超過 8MB），請拆成兩張');

    $assetId = (int)($_POST['asset_id'] ?? 0);
    if ($assetId > 0) {
        needAsset($db, $P, $assetId, $vid);
        $r = adc_asset_update_file($db, $assetId, $bytes, $json);
        if (empty($r['ok'])) jerr($r['msg']);
        jout(true, ['id' => $assetId, 'updated' => 1]);
    }
    $r = adc_asset_store($db, (int)$c['id'], 'flow', $bytes, 'png', null, $json, $uid);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['id' => $r['id'], 'w' => $r['w'], 'h' => $r['h']]);
}

/* ── 流程圖：取回 fabric 編輯資料（所以之後還能再改，不是只剩一張圖）───── */
case 'get_flow': {
    $vid = (int)($_GET['version_id'] ?? 0);
    $a = needAsset($db, $P, (int)($_GET['id'] ?? 0), $vid > 0 ? $vid : null);
    if ($a['kind'] !== 'flow') jerr('這不是流程圖，沒有可編輯的內容');
    $p = adc_flow_path($db, $a);
    if (!$p || !is_file($p)) jerr('找不到這張流程圖的編輯資料（可能是舊版匯入的圖片）', 'NOFLOW');
    jout(true, ['id' => (int)$a['id'], 'flow_json' => (string)file_get_contents($p)]);
}

/* ── 圖片裁切（只存裁切框，不動原檔，所以隨時可還原）───────────────────── */
case 'crop': {
    needEdit($P);
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    $a = needAsset($db, $P, (int)($_POST['id'] ?? 0), $vid);
    $reset = !empty($_POST['reset']);
    $crop = null;
    if (!$reset) {
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (!isset($_POST[$k]) || !is_numeric($_POST[$k])) jerr('裁切範圍不正確');
        }
        $crop = ['x' => (float)$_POST['x'], 'y' => (float)$_POST['y'],
                 'w' => (float)$_POST['w'], 'h' => (float)$_POST['h']];
    }
    $r = adc_asset_crop($db, (int)$a['id'], $crop);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['crop' => $r['crop']]);
}

/* ── 從該版次的 Word 匯入 ─────────────────────────────────────────────── */
case 'import_word': {
    needEdit($P);
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    @set_time_limit(300);   // LibreOffice 轉一份程序書實測約 8~10 秒，大檔給寬一點
    /* 轉檔要跑很久，期間若一直握著 session 檔鎖，同一個人在別的分頁開任何頁面都會排隊等到轉完
       （PHP 預設一個 session 同時只允許一個請求）。權限與參數都驗完了，匯入函式本身不讀 $_SESSION，
       先釋放鎖。 */
    session_write_close();
    $r = adi_import_version($db, $vid, $uid, !empty($_POST['overwrite']));
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['message' => $r['msg'], 'report' => $r['report']]);
}

/* ── 從別的版次複製內容過來（改版時用；含資產與實體檔）───────────────── */
case 'fork': {
    needEdit($P);
    $to   = (int)($_POST['version_id'] ?? 0);
    $from = (int)($_POST['from_version_id'] ?? 0);
    $tv = needVersion($db, $P, $to);
    $fv = needVersion($db, $P, $from);
    if ($to === $from) jerr('來源與目標是同一個版次');
    if ((int)$tv['doc_id'] !== (int)$fv['doc_id']) jerr('只能從同一份文件的其他版次複製');
    $r = adc_version_fork($db, $from, $to, $uid);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['message' => '已複製 ' . $fv['version'] . ' 版的內容（含 ' . $r['assets'] . ' 張圖）', 'assets' => $r['assets']]);
}

/* ══════════════ 固定標題範本（新建文件沒有舊版可複製時，自動帶入一階/二階
   固定大標題與小標題；規則一律在 as_doc_heading_lib.php）══════════════ */

/** 取某一階的範本（設定跳窗編輯用），順便帶回組好的骨架 HTML（編輯器新開空白版次
 *  要自動塞內容時用得到，兩邊共用同一份「範本→內容」的組法，不在 JS 再組一次） */
case 'heading_tpl_get': {
    $level = (string)($_GET['doc_level'] ?? '');
    $tree = adh_tree($db, $level);
    jout(true, [
        'levels' => adh_levels(),
        'doc_level' => $level,
        'tree' => $tree,
        'count' => count($tree),
        'skeleton_html' => $tree ? adh_skeleton_html($tree) : '',
    ]);
}

/** 整層存檔（管理員）：新增/改名/刪除/搬移順序一次到位 */
case 'heading_tpl_save': {
    needAdmin($P);
    $level = (string)($_POST['doc_level'] ?? '');
    $tops = json_decode((string)($_POST['tree'] ?? '[]'), true);
    if (!is_array($tops)) jerr('範本內容格式不正確');
    $r = adh_save_tree($db, $level, $tops, $uid);
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, ['message' => '已儲存（' . $level . ' 共 ' . $r['count'] . ' 個大標題）', 'count' => $r['count']]);
}

/** 從某個版次的內容挑出真正標記過「標題1/標題2」的段落，給「從既有文件挑選標題」用 */
case 'heading_extract': {
    needAdmin($P);
    $vid = (int)($_GET['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    $c = adc_content_by_version($db, $vid);
    $items = $c ? adh_extract_headings((string)$c['content_html']) : [];
    jout(true, ['items' => $items]);
}

/** 搜尋「已經有線上內容」的文件版次，給挑選來源用 */
case 'heading_doc_search': {
    needAdmin($P);
    $kw = (string)($_GET['kw'] ?? '');
    jout(true, ['rows' => adh_doc_search($db, $kw, 30)]);
}

/* ══════════════ 插入 AS 文件編號／名稱（2026-10-15 使用者交辦）══════════════
   挑選器要的資料一次給完（文件清單＋可用樣式＋部門與階別），前端自己做即時篩選——
   164 份文件一次撈回來只有幾十 KB，比每打一個字就打一支 API 省得多。 */

case 'asref_meta': {
    require_once __DIR__ . '/../common/asdoc_lib.php';
    $docs = eg_asdoc_list_full($db);
    // 部門清單由文件實際掛到的部門長出來，不另外寫一份（部門改名也不會對不起來）
    $depts = [];
    $levels = [];
    foreach ($docs as $d) {
        if (!empty($d['dept_name'])) $depts[(int)$d['department_id']] = (string)$d['dept_name'];
        foreach ((array)$d['owner_depts'] as $o) {
            if (!empty($o['name'])) $depts[(int)$o['id']] = (string)$o['name'];
        }
        if (!empty($d['doc_level'])) $levels[(string)$d['doc_level']] = 1;
    }
    $dl = [];
    foreach ($depts as $id => $nm) $dl[] = ['id' => $id, 'name' => $nm];
    usort($dl, fn($a, $b) => strcmp($a['name'], $b['name']));
    jout(true, [
        'docs'   => $docs,
        'styles' => eg_asdoc_insert_styles($db),
        'depts'  => $dl,
        'levels' => array_keys($levels),
        'can_admin' => !empty($P['admin']),
    ]);
}

/** 樣式設定（只有管理員可改；一般使用者只是照設定好的樣式插入） */
case 'asref_styles_save': {
    needAdmin($P);
    require_once __DIR__ . '/../common/asdoc_lib.php';
    $rows = json_decode((string)($_POST['styles'] ?? '[]'), true);
    if (!is_array($rows)) jerr('樣式格式不正確');
    [$ok, $msg] = eg_asdoc_insert_styles_save($db, $rows, $uid);
    if (!$ok) jerr($msg);
    jout(true, ['message' => $msg, 'styles' => eg_asdoc_insert_styles($db)]);
}

/**
 * 伺服器端組出要插入的 HTML（前端只送「哪一份文件、哪一種帶入方式」）。
 * 為什麼不讓前端自己拼：文件名稱與編號一律以**資料庫現況**為準，
 * 前端送過來的名稱可能是幾分鐘前載入的舊值，插進去就是一段對不上的文字（鐵律8 同一個精神）。
 */
case 'asref_build': {
    require_once __DIR__ . '/../common/asdoc_lib.php';
    $docId = (int)($_POST['doc_id'] ?? $_GET['doc_id'] ?? 0);
    $mode  = (string)($_POST['mode'] ?? $_GET['mode'] ?? 'both');
    if (!in_array($mode, ['both', 'name', 'no'], true)) $mode = 'both';
    $styleId = (string)($_POST['style'] ?? $_GET['style'] ?? '');
    $pre  = mb_substr(trim((string)($_POST['pre'] ?? $_GET['pre'] ?? '')), 0, 20);
    $post = mb_substr(trim((string)($_POST['post'] ?? $_GET['post'] ?? '')), 0, 20);
    if (preg_match('/[<>]/u', $pre . $post)) jerr('前後綴不可以含 < 或 >');
    $st = $db->prepare("SELECT id, doc_no, doc_name, COALESCE(is_obsolete,0) obs
                        FROM as_document WHERE id=? AND is_deleted=0 LIMIT 1");
    $st->execute([$docId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) jerr('找不到這份 AS 文件（可能已被刪除）');
    $tpl = '';
    if ($mode === 'both') {
        foreach (eg_asdoc_insert_styles($db) as $x) {
            if ($x['id'] === $styleId) { $tpl = $x['tpl']; break; }
        }
        if ($tpl === '') {
            $all = eg_asdoc_insert_styles($db);
            $tpl = $all[0]['tpl'];
        }
    }
    $span = eg_asdoc_ref_span((int)$d['id'], (string)$d['doc_no'], (string)$d['doc_name'], $mode, $tpl);
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    jout(true, [
        'html' => $esc($pre) . $span . $esc($post),
        'text' => $pre . eg_asdoc_insert_text($mode, (string)$d['doc_no'], (string)$d['doc_name'], $tpl) . $post,
        'obsolete' => (int)$d['obs'],
    ]);
}

/**
 * 一批 AS 文件現在的編號與名稱（流程圖打開時用來比對「引用的文件改了沒」）。
 * 回 {doc_id: {no, name, obsolete}}；查不到的那一個 key 就不會出現（代表已被刪除）。
 */
case 'asref_resolve': {
    $ids = array_values(array_filter(array_map('intval',
        explode(',', (string)($_GET['ids'] ?? $_POST['ids'] ?? '')))));
    $ids = array_slice(array_unique($ids), 0, 200);
    if (!$ids) jout(true, ['docs' => []]);
    $in = implode(',', $ids);
    $out = [];
    foreach ($db->query("SELECT id, doc_no, doc_name, COALESCE(is_obsolete,0) obs
                         FROM as_document WHERE is_deleted=0 AND id IN ($in)")
                ->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['id']] = ['no' => (string)$r['doc_no'], 'name' => (string)$r['doc_name'],
                                  'obsolete' => (int)$r['obs']];
    }
    jout(true, ['docs' => $out]);
}

/* ══════════════ 內文引用的文件編號：待處理／預覽／套用／略過 ══════════════
   規則一律在 as_doc_ref_lib.php，這裡只守門與轉呼叫（鐵律4）。 */

/** 這個版次有哪些待確認的引用變更（編輯器一開就問，有才跳提示條） */
case 'ref_pending': {
    require_once __DIR__ . '/../common/as_doc_ref_lib.php';
    $vid = (int)($_GET['version_id'] ?? 0);
    $v   = needVersion($db, $P, $vid);
    $rows = adr_pending_for_version($db, $vid);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'kind' => $r['src_kind'],
            'old_no' => $r['src_old_no'], 'new_no' => $r['src_new_no'],
            'date' => $r['src_date'], 'note' => $r['src_note'],
            'hits' => (int)$r['hits'], 'pages' => $r['pages'],
            'hits_list' => $r['hits_list'],
            // 改名（2026-10-15）：舊/新名稱與「另有幾張流程圖引用它」要帶給畫面，
            // 流程圖那幾張一律只提示、不自動改（改字要重新產生 PNG，後端做不到）
            'old_name' => $r['src_old_name'] ?? null, 'new_name' => $r['src_new_name'] ?? null,
            'flows' => (int)($r['flows'] ?? 0),
            'flows_list' => $r['flows_json'] ? json_decode((string)$r['flows_json'], true) : [],
        ];
    }
    // 套用之後版別會變成這個，先算好讓確認畫面直接寫出來（使用者才知道會變 2.1 還是 A.1）
    jout(true, ['rows' => $out, 'next_version' => adr_next_version((string)$v['version']),
                'cur_version' => (string)$v['version'], 'can_edit' => !empty($P['edit'])]);
}

/** 標示過的內文預覽：舊編號畫刪除線、新編號綠底；廢止的只標黃底不給新編號。
 *  **標示永遠不寫進 as_doc_content**，只在這支即時產生（存進正本就會印出一堆刪除線）。 */
case 'ref_preview': {
    require_once __DIR__ . '/../common/as_doc_ref_lib.php';
    $vid = (int)($_GET['version_id'] ?? 0);
    $v   = needVersion($db, $P, $vid);
    $c   = adc_content_by_version($db, $vid);
    if (!$c) jerr('這個版次還沒有線上版內容');
    $ids  = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
    $rows = adr_pending_for_version($db, $vid);
    if ($ids) $rows = array_values(array_filter($rows, fn($r) => in_array((int)$r['id'], $ids, true)));
    if (!$rows) jerr('沒有待處理的項目');
    // 圖片要看得到才能判斷改的位置對不對，所以跟編輯器一樣先把資產編號組回網址
    $html = adc_hydrate_html($db, (string)$c['content_html'], (int)$c['id'],
                             'AsDocContent_API.php?action=asset&id=');
    $mk = adr_mark_html($html, $rows);
    jout(true, ['html' => $mk['html'], 'marks' => $mk['marks']]);
}

/** 套用：改內容＋在制修訂紀錄書補一列（版別小數點+1／日期／頁次／摘要） */
case 'ref_apply': {
    require_once __DIR__ . '/../common/as_doc_ref_lib.php';
    $vid = (int)($_POST['version_id'] ?? 0);
    $v   = needVersion($db, $P, $vid);
    if (empty($P['edit'])) { http_response_code(403); jerr('沒有修改線上版內容的權限'); }
    if ((int)$v['is_obsolete'] === 1 && empty($P['admin'])) jerr('這份文件已廢止，只有管理員能改');
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    $r = adr_apply($db, $vid, is_array($ids) ? $ids : [], $uid, (string)($_SESSION['user_cname'] ?? ''));
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, $r);
}

/** 略過（這一處確認過不需要改）——留紀錄，不是直接刪掉 */
case 'ref_dismiss': {
    require_once __DIR__ . '/../common/as_doc_ref_lib.php';
    $vid = (int)($_POST['version_id'] ?? 0);
    needVersion($db, $P, $vid);
    if (empty($P['edit'])) { http_response_code(403); jerr('沒有修改線上版內容的權限'); }
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    $r = adr_dismiss($db, $vid, is_array($ids) ? $ids : [], $uid, (string)($_SESSION['user_cname'] ?? ''));
    if (empty($r['ok'])) jerr($r['msg']);
    jout(true, $r);
}

default:
    jerr('無效的操作：' . htmlspecialchars((string)$action, ENT_QUOTES));
}
} catch (Throwable $e) {
    // 未捕捉的例外一律回 JSON，不要變成空白 500——那在畫面上是「按了完全沒反應、
    // 也沒有錯誤訊息」，最難查（資料稽核 2026-09-21 踩過）
    error_log('[AsDocContent_API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    jerr('伺服器發生錯誤：' . $e->getMessage(), 'EXCEPTION');
}
