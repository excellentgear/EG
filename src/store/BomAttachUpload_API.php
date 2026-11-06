<?php
/**
 * BOM 附件批次上傳（Upload_List.php「ERP 匯入」分頁的「BOM 附件上傳」區塊用）
 *
 * 存放位置＝part_viewer.php「ERP/資材」區讀取的同一個資料夾（eg_bom_erp_scan_dir_auto()），
 * 檔名以「BOM 編號＋標籤後綴」開頭，part_viewer 就會依「設定標籤」自動標上標籤名稱。
 * 標籤判定唯一實作＝bom_dir_lib.php 的 eg_bom_tag_seq()／eg_bom_file_tags_all()，本檔不另寫規則。
 *
 * action=tags     回傳標籤清單（手動挑選用）
 * action=analyze  POST names=JSON 檔名陣列 → 每個檔名判定 ok／need_manual（缺 BOM 或缺標籤）
 * action=save     POST file + (bom, suffix 手動指定時) → 存檔；一次一個檔案，前端逐檔呼叫
 *
 * 不覆蓋既有檔案：同名時自動加時間戳。純新增，不動任何既有資料或程式。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
if (!isset($_SESSION['userName'])) { http_response_code(401); echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit; }

require_once __DIR__ . '/../common/DBConnection.php';
$db = (new DBConnection())->getPDO();
require_once __DIR__ . '/../common/bom_dir_lib.php';

const BAU_EXTS = ['pdf', 'jpg', 'jpeg', 'png'];
const BAU_MAX_BYTES = 50 * 1024 * 1024;

function bau_out(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/** 檔名 → [bom|null, 去掉 BOM 之後的其餘部分]。容許 "B -1140826002-M.jpg"、"B1140826002" 這種手打誤差 */
function bau_split_bom(string $name): array {
    if (preg_match('/^\s*B\s*-?\s*(\d{10})(.*)$/isu', $name, $m)) return ['B-' . $m[1], $m[2]];
    return [null, $name];
}

function bau_bom_exists(PDO $db, string $bom): bool {
    $st = $db->prepare('SELECT 1 FROM bom WHERE bom=? LIMIT 1');
    $st->execute([$bom]);
    return (bool)$st->fetchColumn();
}

/** 依標籤設定（後綴長的優先）找出這個「BOM+其餘」命中的標籤；回 [suffix,label,seq] 或 null */
function bau_match_tag(array $tags, string $bom, string $rest): ?array {
    usort($tags, static function ($a, $b) { return strlen($b['suffix']) - strlen($a['suffix']); });
    $full = $bom . $rest;
    foreach ($tags as $t) {
        $n = eg_bom_tag_seq($full, $bom . $t['suffix']);
        if ($n !== null) return ['suffix' => $t['suffix'], 'label' => eg_bom_tag_label($t['label'], $n), 'seq' => $n];
    }
    return null;
}

function bau_clean_rest(string $s): string {
    $s = preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '', $s);
    return trim($s ?? '');
}

$action = $_REQUEST['action'] ?? '';
$tags = eg_bom_file_tags_all($db);

if ($action === 'tags') {
    bau_out(['ok' => true, 'tags' => array_map(static function ($t) {
        return ['suffix' => $t['suffix'], 'label' => $t['label']];
    }, $tags)]);
}

if ($action === 'analyze') {
    $names = json_decode((string)($_POST['names'] ?? '[]'), true);
    if (!is_array($names)) bau_out(['ok' => false, 'msg' => '參數錯誤']);
    $res = [];
    foreach ($names as $i => $nm) {
        $nm = (string)$nm;
        $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
        $r = ['i' => $i, 'name' => $nm, 'ok' => false, 'bom' => '', 'suffix' => '', 'label' => '', 'reason' => ''];
        if (!in_array($ext, BAU_EXTS, true)) { $r['reason'] = '只接受 PDF／JPG／PNG'; $res[] = $r; continue; }
        [$bom, $rest] = bau_split_bom($nm);
        if ($bom !== null && !bau_bom_exists($db, $bom)) { $r['reason'] = "系統查無製令 $bom"; $r['bom'] = $bom; $bom = null; }
        if ($bom === null) {
            if ($r['reason'] === '') $r['reason'] = '檔名解析不出 BOM 號碼';
            $res[] = $r; continue;
        }
        $r['bom'] = $bom;
        $m = bau_match_tag($tags, $bom, $rest);
        if (!$m) { $r['reason'] = '檔名解析不出標籤'; $res[] = $r; continue; }
        $r['ok'] = true; $r['suffix'] = $m['suffix']; $r['label'] = $m['label'];
        $res[] = $r;
    }
    bau_out(['ok' => true, 'results' => $res]);
}

if ($action === 'save') {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) bau_out(['ok' => false, 'msg' => '檔案上傳失敗（可能超過伺服器大小限制）']);
    $f = $_FILES['file'];
    $orig = (string)$f['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, BAU_EXTS, true)) bau_out(['ok' => false, 'msg' => '只接受 PDF／JPG／PNG']);
    if ($f['size'] > BAU_MAX_BYTES) bau_out(['ok' => false, 'msg' => '檔案超過 50MB']);

    $manualBom = trim((string)($_POST['bom'] ?? ''));
    $manualSuffix = (string)($_POST['suffix'] ?? '');
    $base = pathinfo($orig, PATHINFO_FILENAME);
    [$pBom, $pRest] = bau_split_bom($orig);
    $pRest = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $pRest);

    $bom = ''; $suffix = '';
    if ($manualBom === '' && $manualSuffix === '') {
        // 自動判定路徑：後端重新判定一次，不採信前端
        if ($pBom === null || !bau_bom_exists($db, $pBom)) bau_out(['ok' => false, 'msg' => '檔名解析不出 BOM 號碼，請手動輸入']);
        $m = bau_match_tag($tags, $pBom, $pRest);
        if (!$m) bau_out(['ok' => false, 'msg' => '檔名解析不出標籤，請手動選擇']);
        $bom = $pBom; $suffix = $m['suffix'];
        $stored = $bom . bau_clean_rest($pRest) . '.' . $ext;   // 已正規化 BOM 前綴（修掉 "B -" 這類手打誤差）
    } else {
        $bom = strtoupper(preg_replace('/\s+/', '', $manualBom));
        if (!preg_match('/^B-\d{10}$/', $bom)) bau_out(['ok' => false, 'msg' => 'BOM 號碼格式應為 B-1xxxxxxxxx']);
        if (!bau_bom_exists($db, $bom)) bau_out(['ok' => false, 'msg' => "系統查無製令 $bom"]);
        $valid = array_column($tags, 'suffix');
        if (!in_array($manualSuffix, $valid, true)) bau_out(['ok' => false, 'msg' => '請選擇標籤']);
        $suffix = $manualSuffix;
        // 檔名一律改成「BOM＋後綴＋_原檔名」，part_viewer 才認得到標籤，原檔名保留供辨識
        $restOrig = ($pBom !== null) ? $pRest : ' ' . $base;
        $restOrig = bau_clean_rest(preg_replace('/^[\s\-_]+/', '', $restOrig) ?? '');
        $stored = $bom . $suffix . ($restOrig !== '' ? '_' . $restOrig : '') . '.' . $ext;
    }

    $dirFs = eg_bom_erp_scan_dir_auto();
    if (!is_dir($dirFs)) bau_out(['ok' => false, 'msg' => '讀不到 BOM 檔案資料夾，請通知管理員']);
    $dirUtf8 = eg_bom_erp_scan_dir($db);
    $useBig5 = (rtrim($dirFs, '/\\') !== rtrim($dirUtf8, '/\\')) && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $toFs = static function (string $n) use ($useBig5): string {
        if (!$useBig5) return $n;
        $c = @mb_convert_encoding($n, 'Big5', 'UTF-8');
        return ($c !== false && $c !== '') ? $c : $n;
    };
    $dest = rtrim($dirFs, '/\\') . DIRECTORY_SEPARATOR . $toFs($stored);
    if (file_exists($dest)) {   // 絕不覆蓋既有檔
        $stored = preg_replace('/\.' . preg_quote($ext, '/') . '$/', '', $stored) . '_' . date('YmdHis') . '.' . $ext;
        $dest = rtrim($dirFs, '/\\') . DIRECTORY_SEPARATOR . $toFs($stored);
        if (file_exists($dest)) bau_out(['ok' => false, 'msg' => '檔名衝突，請稍後再試']);
    }
    if (!@move_uploaded_file($f['tmp_name'], $dest)) bau_out(['ok' => false, 'msg' => '寫入 NAS 失敗，請通知管理員']);

    $label = '';
    foreach ($tags as $t) if ($t['suffix'] === $suffix) { $label = $t['label']; break; }
    try {
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at) VALUES ('bom_attach_upload','bom',0,?,?,?,?,NOW())")
           ->execute([$bom, json_encode(['file' => $stored, 'orig' => $orig, 'tag' => $label], JSON_UNESCAPED_UNICODE), (int)($_SESSION['id'] ?? 0), (string)($_SESSION['user_cname'] ?? '')]);
    } catch (Throwable $e) { /* 稽核欄位不符時不影響上傳 */ }
    bau_out(['ok' => true, 'bom' => $bom, 'file' => $stored, 'label' => $label]);
}

bau_out(['ok' => false, 'msg' => '無效的操作']);
