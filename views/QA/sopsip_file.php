<?php
/**
 * sopsip_file.php — SOP／SIP 的圖面、步驟圖、掃描檔取檔端點
 * 建立：2026-09-21
 *
 * 路徑一律在讀取當下由設定值現場組出（鐵律5），DB 只存檔名；帶入的料號附件轉呼叫
 * bom_view_file_lib 的既有解析，不在這裡再寫一份料號附件的路徑組法。
 */
session_start();
require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/sopsip_lib.php';
require_once __DIR__ . '/../../src/common/attach_lib.php';

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) { http_response_code(401); exit('請先登入'); }

$db = (new DBConnection())->getPDO();
ss_ensure_schema($db);
$P = ss_perms($db, $uid);
if (empty($P['canView'])) { http_response_code(403); exit('沒有檢視權限'); }

$fid = (int)($_GET['id'] ?? 0);
$st  = $db->prepare("SELECT * FROM ss_file WHERE file_id=?");
$st->execute([$fid]);
$f = $st->fetch(PDO::FETCH_ASSOC);
if (!$f) { http_response_code(404); exit('找不到檔案'); }

$doc = ss_doc_get($db, (int)$f['doc_id']);
if (!$doc || !ss_perm_for_kind($P, (string)$doc['kind'], 'view')) { http_response_code(403); exit('沒有檢視權限'); }

$path = ss_file_path($db, $f);
if (!$path || !is_file($path)) { http_response_code(404); exit('檔案不存在（可能已被移動或 NAS 未連線）'); }

$name = (string)($f['orig_name'] ?: basename($path));
$ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
$mime = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
    'bmp' => 'image/bmp', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
][$ext] ?? 'application/octet-stream';

// 檔名一律由伺服器指定（Chrome 會讓 Content-Disposition 蓋掉 <a download>）；
// 這支共用函式自己會依 ?dl= 決定 inline 或 attachment，並把 inline 的快取壓到 60 秒
// ——圖面改版後不該讓人還看到一小時前的舊圖。
eg_attach_send_disposition($name);
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
readfile($path);
