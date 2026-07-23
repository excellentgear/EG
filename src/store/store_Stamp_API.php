<?php
/**
 * 圖章管理 API — 清冊登記(stamp_register) + 掃描實體章資產(stamp_asset)
 * 權限：檢閱=所有登入者（印章樣式本就出現在單據上）；登記/上傳/停用/刪除=管理者 or stamp 模組角色（圖章管理員）。
 * 掃描章：上傳 jpg/png → GD 紅色抽取去背 → PNG(alpha) 存 {stamp_attach_base}/{user_id}.png（DB 只存檔名，鐵律5）。
 * asset_map / asset_img 供 resource/js/eg_stamp.js 於各簽核顯示端（報價單/CAR/AS表單）自動替換真章。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';
include_once $document_root . '/EGsystem/src/common/stamp_lib.php';

$db = (new DBConnection())->getPDO();

function jout($arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// ── 使用者 ──
$uname = $_SESSION['userName'] ?? '';
if ($uname === '') jerr('未登入', 401);
$st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname = ?");
$st->execute([$uname]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) jerr('使用者不存在', 401);
$uid   = (int)$u['id'];
$cname = (string)($u['user_cname'] ?: $uname);

// 管理者：user_status 9/90 或系統 admin 角色（沿用 imgedit_permission 慣例）
$isAdmin = in_array((int)$u['user_status'], [9, 90], true);
if (!$isAdmin) {
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Exception $e) {}
}
$canManage = $isAdmin || rf_has_module_role_all($db, $uid, 'stamp');
function needManage(bool $canManage){ if (!$canManage) jerr('無圖章管理權限（需管理者或「圖章管理員」角色）', 403); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
switch ($action) {

// ── 下拉/權限 meta ──
case 'meta': {
    $users = $db->query("SELECT id, user_cname FROM user WHERE (state IS NULL OR state <> 0)
                         ORDER BY CONVERT(user_cname USING utf8mb4) COLLATE utf8mb4_unicode_ci")->fetchAll(PDO::FETCH_ASSOC);
    $base = '';
    if ($canManage) $base = eg_stamp_base($db);
    jout(['ok'=>true, 'canManage'=>$canManage, 'isAdmin'=>$isAdmin, 'me'=>$cname,
          'users'=>$users, 'base'=>$base, 'base_ok'=>$canManage ? is_dir($base) : null]);
}

// ── 儲存路徑設定（僅管理者）──
case 'set_base': {
    if (!$isAdmin) jerr('僅管理者可修改儲存路徑', 403);
    $dir = rtrim(trim((string)($_POST['base'] ?? '')), '\\/');
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                        VALUES ('stamp_attach_base', ?, ?, ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)");
    $st->execute([$dir, $uid, $cname]);
    jout(['ok'=>true, 'base'=>eg_stamp_base($db), 'base_ok'=>is_dir(eg_stamp_base($db))]);
}

// ── 清冊列表（後端分頁＋彙總，匯出/列印用 all=1 回全部）──
case 'list': {
    $q      = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $per    = (int)($_GET['per'] ?? 10); if (!in_array($per, [5,10,20,50], true)) $per = 10;
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $all    = ($_GET['all'] ?? '') === '1';

    $where = []; $args = [];
    if ($q !== '')      { $where[] = "CONVERT(u.user_cname USING utf8mb4) LIKE ?"; $args[] = "%$q%"; }
    if ($status !== '') { $where[] = "r.status = ?"; $args[] = $status; }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stc = $db->prepare("SELECT COUNT(*) FROM stamp_register r JOIN user u ON u.id=r.user_id $w");
    $stc->execute($args); $total = (int)$stc->fetchColumn();

    $sts = $db->prepare("SELECT
                           SUM(CASE WHEN r.status='active' THEN 1 ELSE 0 END) AS active_cnt,
                           SUM(CASE WHEN r.status='revoked' THEN 1 ELSE 0 END) AS revoked_cnt
                         FROM stamp_register r JOIN user u ON u.id=r.user_id $w");
    $sts->execute($args); $sum = $sts->fetch(PDO::FETCH_ASSOC) ?: ['active_cnt'=>0,'revoked_cnt'=>0];

    $limit = $all ? '' : ('LIMIT ' . (($page-1)*$per) . ',' . $per);
    $st = $db->prepare("SELECT r.id, r.user_id, u.user_cname, r.issue_date, r.revoke_date, r.status, r.note,
                               r.created_by, r.created_at,
                               a.file_name IS NOT NULL AS has_asset, a.band_top, a.band_bottom
                        FROM stamp_register r
                        JOIN user u ON u.id = r.user_id
                        LEFT JOIN stamp_asset a ON a.user_id = r.user_id
                        $w ORDER BY r.status='active' DESC, r.issue_date DESC, r.id DESC $limit");
    $st->execute($args);
    jout(['ok'=>true, 'rows'=>$st->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total,
          'summary'=>['active'=>(int)$sum['active_cnt'], 'revoked'=>(int)$sum['revoked_cnt']],
          'canManage'=>$canManage]);
}

// ── 新增登記 ──
case 'add': {
    needManage($canManage);
    $tuid = (int)($_POST['user_id'] ?? 0);
    $issue = trim((string)($_POST['issue_date'] ?? ''));
    $note  = trim((string)($_POST['note'] ?? ''));
    if ($tuid <= 0) jerr('請選擇人員');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) jerr('核發日期格式錯誤');
    $db->beginTransaction();
    $st = $db->prepare("SELECT 1 FROM stamp_register WHERE user_id=? AND status='active' LIMIT 1 FOR UPDATE");
    $st->execute([$tuid]);
    if ($st->fetchColumn()) { $db->rollBack(); jerr('該人員已有一筆「使用中」圖章，請先停用舊登記再核發'); }
    $st = $db->prepare("INSERT INTO stamp_register (user_id, issue_date, status, note, created_by) VALUES (?,?,'active',?,?)");
    $st->execute([$tuid, $issue, $note, $cname]);
    $newId = (int)$db->lastInsertId();
    $db->commit();
    jout(['ok'=>true, 'id'=>$newId]);
}

// ── 修改登記（核發日/備註）──
case 'update': {
    needManage($canManage);
    $id = (int)($_POST['id'] ?? 0);
    $issue = trim((string)($_POST['issue_date'] ?? ''));
    $note  = trim((string)($_POST['note'] ?? ''));
    if ($id <= 0) jerr('參數錯誤');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) jerr('核發日期格式錯誤');
    $st = $db->prepare("UPDATE stamp_register SET issue_date=?, note=?, modified_by=?, modified_at=NOW() WHERE id=?");
    $st->execute([$issue, $note, $cname, $id]);
    jout(['ok'=>true]);
}

// ── 停用/繳回 ──
case 'revoke': {
    needManage($canManage);
    $id = (int)($_POST['id'] ?? 0);
    $rd = trim((string)($_POST['revoke_date'] ?? ''));
    if ($id <= 0) jerr('參數錯誤');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd)) jerr('停用日期格式錯誤');
    $st = $db->prepare("UPDATE stamp_register SET status='revoked', revoke_date=?, modified_by=?, modified_at=NOW() WHERE id=? AND status='active'");
    $st->execute([$rd, $cname, $id]);
    if (!$st->rowCount()) jerr('此筆不是使用中狀態，無法停用');
    jout(['ok'=>true]);
}

// ── 刪除登記（誤登記時使用）──
case 'delete': {
    needManage($canManage);
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) jerr('參數錯誤');
    $st = $db->prepare("DELETE FROM stamp_register WHERE id=?");
    $st->execute([$id]);
    jout(['ok'=>true]);
}

// ── CSV 匯出（目前篩選條件下全部資料，後端重查）──
case 'csv': {
    $q      = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $where = []; $args = [];
    if ($q !== '')      { $where[] = "CONVERT(u.user_cname USING utf8mb4) LIKE ?"; $args[] = "%$q%"; }
    if ($status !== '') { $where[] = "r.status = ?"; $args[] = $status; }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = $db->prepare("SELECT u.user_cname, r.issue_date, r.revoke_date, r.status, r.note, r.created_by, r.created_at,
                               a.file_name IS NOT NULL AS has_asset
                        FROM stamp_register r JOIN user u ON u.id=r.user_id
                        LEFT JOIN stamp_asset a ON a.user_id=r.user_id
                        $w ORDER BY r.status='active' DESC, r.issue_date DESC, r.id DESC");
    $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stamp_register_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['持有人','核發日期','停用/繳回日期','狀態','備註','掃描章','登記人','登記時間']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, [$r['user_cname'], $r['issue_date'], $r['revoke_date'] ?: '',
                       $r['status']==='active' ? '使用中' : '已停用', $r['note'] ?: '',
                       $r['has_asset'] ? '已上傳' : '—', $r['created_by'] ?: '', $r['created_at'] ?: '']);
    }
    fclose($out);
    exit;
}

// ── 掃描章上傳（jpg/png → 紅色抽取去背 → PNG alpha）──
case 'asset_upload': {
    needManage($canManage);
    $tuid = (int)($_POST['user_id'] ?? 0);
    if ($tuid <= 0) jerr('請選擇人員');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('檔案上傳失敗');
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) jerr('檔案超過 20MB');
    $tmp = $_FILES['file']['tmp_name'];
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) jerr('僅接受 JPG / PNG 圖檔');
    $src = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($tmp) : @imagecreatefrompng($tmp);
    if (!$src) jerr('圖檔讀取失敗');

    // 等比縮至最長邊 800（掃描件建議 300dpi，縮小後仍足夠列印顯示）
    $w = imagesx($src); $h = imagesy($src);
    $maxSide = 800;
    if (max($w, $h) > $maxSide) {
        $sc = $maxSide / max($w, $h);
        $nw = (int)round($w * $sc); $nh = (int)round($h * $sc);
        $rs = imagecreatetruecolor($nw, $nh);
        imagealphablending($rs, false); imagesavealpha($rs, true);
        imagecopyresampled($rs, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src); $src = $rs; $w = $nw; $h = $nh;
    }

    // 紅色抽取去背：保留 R 通道明顯高於 G、B 的像素，其餘透明（圖章系統說明.md 定案演算法）
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    $kept = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($src, $x, $y);
            $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
            if ($r > 150 && ($r - $g) > 40 && ($r - $b) > 40) {
                imagesetpixel($dst, $x, $y, imagecolorallocatealpha($dst, $r, $g, $b, 0));
                $kept++;
            }
        }
    }
    imagedestroy($src);
    if ($kept < ($w * $h) * 0.002) { imagedestroy($dst); jerr('偵測不到紅色印泥（陰影或藍黑墨的掃描件去背品質差），請用紅色印泥重新掃描上傳'); }

    $base = eg_stamp_base($db);
    if (!is_dir($base) && !@mkdir($base, 0777, true)) jerr('儲存資料夾不存在且無法建立：' . $base);
    $fn = $tuid . '.png';
    if (!imagepng($dst, $base . DIRECTORY_SEPARATOR . $fn)) { imagedestroy($dst); jerr('檔案寫入失敗，請確認儲存路徑可寫入'); }
    imagedestroy($dst);

    $st = $db->prepare("INSERT INTO stamp_asset (user_id, file_name, created_by)
                        VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE file_name=VALUES(file_name), modified_at=NOW()");
    $st->execute([$tuid, $fn, $cname]);
    $a = eg_stamp_asset($db, $tuid);
    jout(['ok'=>true, 'asset'=>$a, 't'=>time()]);
}

// ── 日期帶位置儲存 ──
case 'asset_band': {
    needManage($canManage);
    $tuid = (int)($_POST['user_id'] ?? 0);
    $top = (float)($_POST['band_top'] ?? 0);
    $bot = (float)($_POST['band_bottom'] ?? 0);
    if ($tuid <= 0) jerr('參數錯誤');
    if ($top < 0 || $bot > 100 || $top >= $bot) jerr('日期帶範圍錯誤（上緣需小於下緣，0~100%）');
    $st = $db->prepare("UPDATE stamp_asset SET band_top=?, band_bottom=?, modified_at=NOW() WHERE user_id=?");
    $st->execute([$top, $bot, $tuid]);
    if (!$st->rowCount() && !eg_stamp_asset($db, $tuid)) jerr('尚未上傳掃描章');
    jout(['ok'=>true]);
}

// ── 刪除掃描章（回退純 SVG 章）──
case 'asset_delete': {
    needManage($canManage);
    $tuid = (int)($_POST['user_id'] ?? 0);
    if ($tuid <= 0) jerr('參數錯誤');
    $a = eg_stamp_asset($db, $tuid);
    if ($a) {
        $p = eg_stamp_file_path($db, $a);
        if ($p) @unlink($p);
        $db->prepare("DELETE FROM stamp_asset WHERE user_id=?")->execute([$tuid]);
    }
    jout(['ok'=>true]);
}

// ── 掃描章圖檔（所有登入者可讀；t= 參數配 mtime 做快取破壞）──
case 'asset_img': {
    $tuid = (int)($_GET['user_id'] ?? 0);
    $a = $tuid > 0 ? eg_stamp_asset($db, $tuid) : null;
    $p = $a ? eg_stamp_file_path($db, $a) : null;
    if (!$p) { http_response_code(404); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=604800');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

// ── 姓名→掃描章對照表（eg_stamp.js 每頁載入時自動抓）──
case 'asset_map': {
    jout(['ok'=>true, 'map'=>eg_stamp_asset_map($db)]);
}

default: jerr('未知的 action');
}
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    jerr('系統錯誤：' . $e->getMessage(), 500);
}
