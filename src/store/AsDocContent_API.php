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
require_once __DIR__ . '/../common/attach_lib.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isWrite = isset($_POST['action']);

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

/* 寫入一律驗 CSRF。先驗登入再驗 CSRF——token 會在同一個請求裡重新產生，
   順序顛倒的話已登出的人會一直看到「憑證失效」卻永遠存不進去
   （記憶 session_gc_csrf_false_alarm）。 */
if ($isWrite) {
    $tok = $_POST['csrf'] ?? '';
    if (empty($_SESSION['adc_csrf']) || !hash_equals((string)$_SESSION['adc_csrf'], (string)$tok)) {
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 'CSRF');
    }
}
function needEdit(array $P) { if (empty($P['edit'])) { http_response_code(403); jerr('沒有編輯線上內容的權限（需要 AS 文件管理的「編輯線上內容」）'); } }

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
        'cfg'       => $ctx['cfg'],
        'foot_default' => ADT_FOOT_LEFT_DEFAULT,
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
