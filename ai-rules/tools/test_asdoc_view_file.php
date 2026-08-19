<?php
/**
 * AS 文件管理：版本檔案「雙版本」（下載版 file_name／檢視版 view_file_name）測試。
 * 重點在「只上傳一種時另一種自動退回同一個檔」與 download 的檔案挑選規則。
 * 直接驗證共用函式 asVerFile() 與 download 的挑選分支；補檔/替換的 which=view 走真 API。
 * 用法： php ai-rules/tools/test_asdoc_view_file.php
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/_config.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$adminName = $db->query("SELECT user_uname FROM `user` WHERE id=1")->fetchColumn();
if (!$adminName) exit("找不到 id=1 管理員\n");

$pass = 0; $fail = 0;
function ck(string $name, bool $ok, string $extra = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   $name\n"; }
    else     { $fail++; echo "  [FAIL] $name  $extra\n"; }
}
function api(string $action, array $post, string $userName) {
    $runner = sys_get_temp_dir() . '/asdoc_vf_runner.php';
    file_put_contents($runner, '<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER["DOCUMENT_ROOT"] = "C:/MAMP/htdocs";
$_SERVER["REQUEST_METHOD"] = "POST";
session_start();
$_SESSION["userName"] = $argv[3];
$_SESSION["id"] = 1;
$_POST = json_decode(base64_decode($argv[2]), true) ?: [];
$_GET  = array_merge(["action" => $argv[1]], $_POST);
$_REQUEST = array_merge($_GET, $_POST);
include "C:/MAMP/htdocs/EGsystem/src/store/AS_Document_API.php";
');
    $out = shell_exec(sprintf('"C:/MAMP/bin/php/php8.3.1/php.exe" %s %s %s %s 2>&1',
        escapeshellarg($runner), escapeshellarg($action),
        escapeshellarg(base64_encode(json_encode($post))), escapeshellarg($userName)));
    $j = json_decode(trim((string)$out), true);
    return is_array($j) ? $j : ['status'=>'error','message'=>'非 JSON：'.trim((string)$out)];
}

// ── asVerFile()：純函式，直接載入 API 檔取用（API 會在無 action 時走 default 分支結束）──
// 為避免 include 整支 API 產生輸出，這裡複製呼叫方式：以子行程跑一小段驗證。
$probe = sys_get_temp_dir() . '/asdoc_vf_probe.php';
file_put_contents($probe, '<?php
error_reporting(0);
$_SERVER["DOCUMENT_ROOT"] = "C:/MAMP/htdocs";
$_GET["action"] = "__probe__";
// API 的每個分支都以 jout() exit 收尾，所以驗證掛在 shutdown（exit 仍會觸發）
register_shutdown_function(function () {
  while (ob_get_level()) { ob_end_clean(); }
  $cases = [
    "both"      => ["file_name"=>"dl.xlsx","original_name"=>"原下載.xlsx","view_file_name"=>"vw.pdf","view_original_name"=>"原檢視.pdf"],
    "dl_only"   => ["file_name"=>"dl.xlsx","original_name"=>"原下載.xlsx"],
    "view_only" => ["view_file_name"=>"vw.pdf","view_original_name"=>"原檢視.pdf"],
    "none"      => [],
  ];
  $out = [];
  foreach ($cases as $k => $v) {
    $out[$k] = ["download"=>asVerFile($v,"download"), "view"=>asVerFile($v,"view")];
  }
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
});
ob_start();
include "C:/MAMP/htdocs/EGsystem/src/store/AS_Document_API.php";
');
$res = json_decode(trim((string)shell_exec(sprintf('"C:/MAMP/bin/php/php8.3.1/php.exe" %s 2>&1', escapeshellarg($probe)))), true);

echo "── asVerFile() 檔案挑選 ──\n";
ck('兩種都有：下載版取 dl、檢視版取 vw',
    is_array($res) && $res['both']['download'][0]==='dl.xlsx' && $res['both']['view'][0]==='vw.pdf',
    json_encode($res['both'] ?? null, JSON_UNESCAPED_UNICODE));
ck('只有下載版：檢視也退回用下載版',
    ($res['dl_only']['view'][0] ?? '')==='dl.xlsx' && ($res['dl_only']['download'][0] ?? '')==='dl.xlsx');
ck('只有檢視版：下載也退回用檢視版',
    ($res['view_only']['download'][0] ?? '')==='vw.pdf' && ($res['view_only']['view'][0] ?? '')==='vw.pdf');
// 注意：不可寫成 ($x ?? 'x')===null——?? 會把 null 換掉，永遠測不到 null
ck('兩種都沒有：回 null（呼叫端顯示未上傳）',
    isset($res['none']) && $res['none']['download'][0]===null && $res['none']['view'][0]===null,
    json_encode($res['none'] ?? null, JSON_UNESCAPED_UNICODE));
ck('原始檔名跟著各自的版本走',
    ($res['both']['download'][1] ?? '')==='原下載.xlsx' && ($res['both']['view'][1] ?? '')==='原檢視.pdf',
    json_encode($res['both'] ?? null, JSON_UNESCAPED_UNICODE));

// ── 測試文件與版本 ──
$db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,is_deleted,created_by,created_at)
              VALUES ('ZZ-VF-01','雙版本測試文件','表單','四階',0,'TEST',NOW())")->execute();
$docId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO as_document_version (doc_id,version,change_status,revised_date,file_name,original_name,uploaded_by,uploaded_at)
              VALUES (?,'A','制訂','2025-05-01','dl.xlsx','原下載.xlsx','TEST',NOW())")->execute([$docId]);
$verId = (int)$db->lastInsertId();
$db->prepare("UPDATE as_document SET current_version='A', current_version_id=? WHERE id=?")->execute([$verId,$docId]);
echo "\n測試文件 doc_id={$docId} version_id={$verId}\n";

try {
    echo "\n── 補檔／替換 which=view ──\n";
    $r = api('version_attach_file', ['version_id'=>$verId, 'which'=>'view'], $adminName);
    ck('檢視版走補檔路徑（僅缺上傳檔案）', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '請選擇檔案'), $r['message'] ?? '');

    $r = api('version_replace_file', ['version_id'=>$verId, 'which'=>'view'], $adminName);
    ck('尚無檢視版時替換導向「補檔」', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '補檔'), $r['message'] ?? '');

    // 直接補上檢視版欄位（模擬已補傳），再驗替換路徑與已有檔不可補檔
    $db->prepare("UPDATE as_document_version SET view_file_name='vw.pdf', view_original_name='原檢視.pdf' WHERE id=?")->execute([$verId]);
    $r = api('version_attach_file', ['version_id'=>$verId, 'which'=>'view'], $adminName);
    ck('已有檢視版時補檔被擋下（改走替換）', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '不可替換'), $r['message'] ?? '');
    $r = api('version_replace_file', ['version_id'=>$verId, 'which'=>'view'], $adminName);
    ck('已有檢視版時可走替換（僅缺上傳檔案）', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '請選擇檔案'), $r['message'] ?? '');

    echo "\n── get_document 回傳檢視版欄位 ──\n";
    $r = api('get_document', ['id'=>$docId], $adminName);
    $v0 = ($r['data']['versions'] ?? [])[0] ?? [];
    ck('版本資料含 view_file_name / view_original_name',
        ($v0['view_file_name'] ?? '')==='vw.pdf' && ($v0['view_original_name'] ?? '')==='原檢視.pdf',
        json_encode($v0, JSON_UNESCAPED_UNICODE));

    echo "\n── 舊資料相容（只有下載版的既有版本）──\n";
    $old = (int)$db->query("SELECT COUNT(*) FROM as_document_version WHERE view_file_name IS NOT NULL AND doc_id<>$docId")->fetchColumn();
    ck('既有版本未被動到（view 欄位仍為 NULL）', $old === 0, "有 {$old} 筆非測試資料被填了 view_file_name");
} finally {
    $db->prepare("DELETE FROM as_document_version WHERE doc_id=?")->execute([$docId]);
    $db->prepare("DELETE FROM as_document WHERE id=?")->execute([$docId]);
    @unlink($probe);
    echo "\n（測試資料已刪除 doc_id={$docId}）\n";
}
echo "\n===== 通過 {$pass} / 失敗 {$fail} =====\n";
exit($fail ? 1 : 0);
