<?php
/**
 * AS 文件管理：文件「廢止」功能測試（doc_obsolete / doc_unobsolete＋清單篩選＋檔案存取守門）。
 * 以偽造 session 直接跑真 API。測試資料用完即刪。
 * 用法： php ai-rules/tools/test_asdoc_obsolete.php
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/_config.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$adminName = $db->query("SELECT user_uname FROM `user` WHERE id=1")->fetchColumn();
$other     = $db->query("SELECT user_uname FROM `user` WHERE id<>1 AND state=1 AND user_uname<>'' ORDER BY id LIMIT 1")->fetchColumn();
if (!$adminName) exit("找不到 id=1 管理員\n");

$pass = 0; $fail = 0;
function ck(string $name, bool $ok, string $extra = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   $name\n"; }
    else     { $fail++; echo "  [FAIL] $name  $extra\n"; }
}
function api(string $action, array $post, string $userName) {
    $runner = sys_get_temp_dir() . '/asdoc_ob_runner.php';
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
    return is_array($j) ? $j : ['status'=>'error','message'=>'非 JSON：'.trim((string)$out), '_raw'=>trim((string)$out)];
}

// ── 測試資料：母文件（程序書）＋兩張子表單 ──
$mk = function(string $no, string $name, string $type, string $lv, ?int $parent) use ($db) {
    $db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,parent_doc_id,is_deleted,created_by,created_at)
                  VALUES (?,?,?,?,?,0,'TEST',NOW())")->execute([$no,$name,$type,$lv,$parent]);
    return (int)$db->lastInsertId();
};
$pid  = $mk('ZZ-OB-01',    '廢止測試程序書', '程序', '二階', null);
$kid1 = $mk('ZZ-OB-01-01', '廢止測試表單A', '表單', '四階', $pid);
$kid2 = $mk('ZZ-OB-01-02', '廢止測試表單B', '表單', '四階', $pid);
$db->prepare("INSERT INTO as_document_version (doc_id,version,change_status,revised_date,file_name,original_name,uploaded_by,uploaded_at)
              VALUES (?,'A','制訂','2025-03-01','dummy.xlsx','orig.xlsx','TEST',NOW())")->execute([$pid]);
$verId = (int)$db->lastInsertId();
$db->prepare("UPDATE as_document SET current_version='A', current_version_id=? WHERE id=?")->execute([$verId, $pid]);
echo "測試文件 母={$pid} 子={$kid1},{$kid2}\n\n";

$obOf = fn(int $id) => $db->query("SELECT CONCAT(is_obsolete,'|',COALESCE(obsolete_date,'')) FROM as_document WHERE id=$id")->fetchColumn();
$inList = function(array $r, int $id) { foreach ($r['data'] ?? [] as $d) if ((int)$d['id'] === $id) return $d; return null; };

try {
    echo "── 廢止（doc_obsolete）──\n";
    $r = api('doc_obsolete', ['id'=>$pid, 'obsolete_date'=>'2026-08-19', 'reason'=>'改由 ZZ-OB-02 取代', 'with_children'=>'1'], $adminName);
    ck('管理員廢止（含子文件）成功', ($r['status'] ?? '')==='success' && (int)($r['children'] ?? 0)===2, json_encode($r, JSON_UNESCAPED_UNICODE));
    ck('母文件已標記廢止＋日期', $obOf($pid)==='1|2026-08-19', '實際='.$obOf($pid));
    ck('子文件也一併廢止', $obOf($kid1)==='1|2026-08-19' && $obOf($kid2)==='1|2026-08-19');

    $r = api('doc_obsolete', ['id'=>$pid, 'obsolete_date'=>'2026-08-19'], $adminName);
    ck('重複廢止被擋下（點開即刷新）', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '已經是廢止'), $r['message'] ?? '');

    $r = api('doc_obsolete', ['id'=>$kid1, 'obsolete_date'=>'2030-01-01'], $adminName);
    ck('廢止日期晚於今天被擋下', ($r['status'] ?? '')==='error', $r['message'] ?? '');
    $r = api('doc_obsolete', ['id'=>$kid1, 'obsolete_date'=>'2026/08/19'], $adminName);
    ck('廢止日期格式錯誤被擋下', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '格式'), $r['message'] ?? '');

    if ($other) {
        $r = api('doc_obsolete', ['id'=>$kid1, 'obsolete_date'=>'2026-08-19'], $other);
        ck("非管理員（{$other}）不可廢止", ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '管理員'), $r['message'] ?? '');
        $r = api('doc_unobsolete', ['id'=>$pid], $other);
        ck("非管理員（{$other}）不可取消廢止", ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '管理員'), $r['message'] ?? '');
    }

    echo "\n── 清單篩選（list_documents）──\n";
    $r = api('list_documents', ['keyword'=>'ZZ-OB-01'], $adminName);
    $row = $inList($r, $pid);
    ck('主清單預設仍列出廢止文件', $row !== null);
    ck('回傳含 is_obsolete / obsolete_date / 原因', $row && (int)$row['is_obsolete']===1
        && $row['obsolete_date']==='2026-08-19' && $row['obsolete_reason']==='改由 ZZ-OB-02 取代',
        json_encode([$row['is_obsolete'] ?? null, $row['obsolete_date'] ?? null], JSON_UNESCAPED_UNICODE));

    $r = api('list_documents', ['keyword'=>'ZZ-OB-01', 'include_obsolete'=>'0'], $adminName);
    ck('結構總覽模式（include_obsolete=0）不列廢止文件', $inList($r, $pid) === null && $inList($r, $kid1) === null);

    echo "\n── 廢止文件的檔案存取 ──\n";
    $out = shell_exec(sprintf('"C:/MAMP/bin/php/php8.3.1/php.exe" %s open_online %s %s 2>&1',
        escapeshellarg(sys_get_temp_dir().'/asdoc_ob_runner.php'),
        escapeshellarg(base64_encode(json_encode(['version_id'=>$verId]))), escapeshellarg($other ?: 'x')));
    ck('非管理員線上開啟廢止文件被擋下', str_contains((string)$out, '已廢止'), trim((string)$out));
    $r = api('open_online', ['version_id'=>$verId], $adminName);
    ck('管理員不被廢止守門擋（僅因測試檔不存在而失敗）',
        ($r['status'] ?? '')==='error' && !str_contains($r['message'] ?? '', '已廢止'), $r['message'] ?? '');

    echo "\n── 取消廢止（doc_unobsolete）──\n";
    $r = api('doc_unobsolete', ['id'=>$pid], $adminName);
    ck('取消廢止成功', ($r['status'] ?? '')==='success', json_encode($r, JSON_UNESCAPED_UNICODE));
    ck('母文件欄位已清空', $obOf($pid)==='0|', '實際='.$obOf($pid));
    ck('子文件不受連動（取消廢止不連動子文件）', $obOf($kid1)==='1|2026-08-19', '實際='.$obOf($kid1));
} finally {
    foreach ([$pid,$kid1,$kid2] as $x) {
        $db->prepare("DELETE FROM as_document_version WHERE doc_id=?")->execute([$x]);
        $db->prepare("DELETE FROM as_document WHERE id=?")->execute([$x]);
    }
    $db->prepare("DELETE FROM page_change_log WHERE page_name='views/ADM/as_document_management.php' AND detail LIKE ?")->execute(['%ZZ-OB-01%']);
    echo "\n（測試資料已刪除）\n";
}
echo "\n===== 通過 {$pass} / 失敗 {$fail} =====\n";
exit($fail ? 1 : 0);
