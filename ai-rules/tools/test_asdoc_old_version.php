<?php
/**
 * AS 文件管理：補舊版次（add_old_versions_batch）＋替換目前版本檔案（version_replace_file）功能測試。
 * 以偽造 session（超級管理員 id=1）直接跑真 API。測試資料用完即刪。
 * 用法： php ai-rules/tools/test_asdoc_old_version.php
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';

require_once 'C:/MAMP/htdocs/EGsystem/src/common/_config.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$superName = $db->query("SELECT user_uname FROM `user` WHERE id=1")->fetchColumn();
if (!$superName) { exit("找不到 id=1 超級管理員\n"); }

$pass = 0; $fail = 0;
function ck(string $name, bool $ok, string $extra = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   $name\n"; }
    else     { $fail++; echo "  [FAIL] $name  $extra\n"; }
}

/** 以偽造 session 跑一次 API（子行程，避免 API 內 exit 中斷測試） */
function api(string $action, array $post, string $userName): array {
    $runner = sys_get_temp_dir() . '/asdoc_api_runner.php';
    $code = '<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER["DOCUMENT_ROOT"] = "C:/MAMP/htdocs";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SESSION_NAME = $argv[3];
session_start();
$_SESSION["userName"] = $argv[3];
$_SESSION["id"] = 1;
$_POST = json_decode(base64_decode($argv[2]), true) ?: [];
$_GET  = array_merge(["action" => $argv[1]], $_POST); // 部分 action 走 $_GET
$_REQUEST = array_merge($_GET, $_POST);
include "C:/MAMP/htdocs/EGsystem/src/store/AS_Document_API.php";
';
    file_put_contents($runner, $code);
    $cmd = sprintf('"C:/MAMP/bin/php/php8.3.1/php.exe" %s %s %s %s 2>&1',
        escapeshellarg($runner), escapeshellarg($action),
        escapeshellarg(base64_encode(json_encode($post))), escapeshellarg($userName));
    $out = shell_exec($cmd);
    $j = json_decode(trim((string)$out), true);
    return is_array($j) ? $j : ['status' => 'error', 'message' => '非 JSON 回應：' . trim((string)$out)];
}

// ── 建立測試文件：版本 A(2024-01-10) → C(2026-01-10)，中間刻意缺 B ──
$db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,is_deleted,created_by,created_at)
              VALUES (?,?,?,?,0,'TEST',NOW())")
   ->execute(['ZZ-TEST-OLDVER', '補舊版次測試文件', '表單', '四階']);
$docId = (int)$db->lastInsertId();
$insVer = function(string $ver, string $date, ?string $file = null) use ($db, $docId) {
    $db->prepare("INSERT INTO as_document_version (doc_id,version,change_status,revised_date,file_name,original_name,uploaded_by,uploaded_at)
                  VALUES (?,?,'修正',?,?,?,'TEST',NOW())")
       ->execute([$docId, $ver, $date, $file, $file ? 'orig.xlsx' : null]);
    return (int)$db->lastInsertId();
};
$verA = $insVer('A', '2024-01-10');
$verC = $insVer('C', '2026-01-10', 'dummy_cur.xlsx');
$db->prepare("UPDATE as_document SET current_version='C', current_version_id=? WHERE id=?")->execute([$verC, $docId]);
echo "測試文件 doc_id={$docId}（A 2024-01-10 / C 2026-01-10，目前版本 C）\n\n";

$curVerOf = fn() => $db->query("SELECT current_version FROM as_document WHERE id=$docId")->fetchColumn();
$curVerIdOf = fn() => (int)$db->query("SELECT current_version_id FROM as_document WHERE id=$docId")->fetchColumn();
$rows = fn(array $r) => ['doc_id' => $docId, 'rows' => json_encode($r)];

try {
    echo "── 補舊版次（add_old_versions_batch）──\n";

    $r = api('add_old_versions_batch', $rows([['version' => 'B', 'revised_date' => '2025-05-01', 'revised_summary' => '補歷史']]), $superName);
    ck('補入舊版 B 成功', ($r['status'] ?? '') === 'success', json_encode($r, JSON_UNESCAPED_UNICODE));
    ck('目前版本仍是 C（未被改動）', $curVerOf() === 'C', '實際=' . $curVerOf());
    ck('current_version_id 未被改動', $curVerIdOf() === $verC);
    $hasB = (int)$db->query("SELECT COUNT(*) FROM as_document_version WHERE doc_id=$docId AND version='B'")->fetchColumn();
    ck('B 已寫入版本歷史', $hasB === 1);

    $r = api('add_old_versions_batch', $rows([['version' => 'D', 'revised_date' => '2025-06-01']]), $superName);
    ck('比目前版本新的 D 被擋下', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '不比目前版本'), $r['message'] ?? '');

    $r = api('add_old_versions_batch', $rows([['version' => 'C', 'revised_date' => '2025-06-01']]), $superName);
    ck('與目前版本同號的 C 被擋下', ($r['status'] ?? '') === 'error', $r['message'] ?? '');

    $r = api('add_old_versions_batch', $rows([['version' => 'A', 'revised_date' => '2023-01-01']]), $superName);
    ck('已存在的版本號 A 被擋下', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '已存在'), $r['message'] ?? '');

    $r = api('add_old_versions_batch', $rows([['version' => 'B-1', 'revised_date' => '2026-02-01']]), $superName);
    ck('修訂日期不早於目前版本者被擋下', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '不早於目前版本'), $r['message'] ?? '');

    $r = api('add_old_versions_batch', $rows([['version' => '1.5', 'revised_date' => '2025-07-01']]), $superName);
    ck('數字/字母型混用被擋下', ($r['status'] ?? '') === 'error', $r['message'] ?? '');

    // 倒著填（新→舊）也要能過：系統自動排序，不該卡使用者的填寫順序
    $r = api('add_old_versions_batch', $rows([
        ['version' => 'B-2', 'revised_date' => '2025-10-01'],
        ['version' => 'B-1', 'revised_date' => '2025-09-01'],
    ]), $superName);
    ck('倒著填（新→舊）自動排序後成功', ($r['status'] ?? '') === 'success', json_encode($r, JSON_UNESCAPED_UNICODE));
    ck('回傳的寫入順序已排成由舊到新 B-1→B-2', ($r['versions'] ?? []) === ['B-1', 'B-2'], json_encode($r['versions'] ?? []));
    $ordOk = $db->query("SELECT GROUP_CONCAT(version ORDER BY id) FROM as_document_version WHERE doc_id=$docId AND version LIKE 'B-%'")->fetchColumn();
    ck('DB 內寫入順序也是 B-1,B-2', $ordOk === 'B-1,B-2', '實際=' . $ordOk);
    ck('補完目前版本仍是 C', $curVerOf() === 'C', '實際=' . $curVerOf());

    // 排序後仍矛盾＝日期真的填錯（版本越新日期卻越早），要擋下並整批回滾
    $r = api('add_old_versions_batch', $rows([
        ['version' => 'A-9', 'revised_date' => '2024-03-01'],
        ['version' => 'A-8', 'revised_date' => '2024-08-01'],
    ]), $superName);
    ck('版本與日期矛盾被擋下', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '版本越新日期應該越晚'), $r['message'] ?? '');
    $cnt = (int)$db->query("SELECT COUNT(*) FROM as_document_version WHERE doc_id=$docId AND version IN ('A-8','A-9')")->fetchColumn();
    ck('整批失敗時較舊那列也未寫入（transaction 回滾）', $cnt === 0);

    // 非超級管理員（另找一個一般帳號）
    $other = $db->query("SELECT user_uname FROM `user` WHERE id<>1 AND state=1 AND user_uname<>'' ORDER BY id LIMIT 1")->fetchColumn();
    if ($other) {
        $r = api('add_old_versions_batch', $rows([['version' => 'A-1', 'revised_date' => '2024-06-01']]), $other);
        ck("非超級管理員（{$other}）被擋下", ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '超級管理員'), $r['message'] ?? '');
    }

    echo "\n── 歷史版本排序（依修訂日期）──\n";
    $r = api('get_document', ['id' => $docId], $superName); // get_document 走 $_GET，這裡以 $_REQUEST 相容
    $vs = array_column($r['data']['versions'] ?? [], 'version');
    ck('版本清單由新到舊：C,B-2,B-1,B,A', $vs === ['C', 'B-2', 'B-1', 'B', 'A'], implode(',', $vs));

    echo "\n── 替換目前版本檔案（version_replace_file）──\n";
    $verB = (int)$db->query("SELECT id FROM as_document_version WHERE doc_id=$docId AND version='B'")->fetchColumn();

    $r = api('version_replace_file', ['version_id' => $verB, 'which' => 'file'], $superName);
    ck('歷史版本 B 不可替換', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '只能替換'), $r['message'] ?? '');

    $r = api('version_replace_file', ['version_id' => $verC, 'which' => 'file'], $superName);
    ck('目前版本 C 通過版本守門（僅缺上傳檔案）', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '請選擇檔案'), $r['message'] ?? '');

    $r = api('version_replace_file', ['version_id' => $verC, 'which' => 'apply'], $superName);
    ck('目前版本 C 的申請單無檔時導向「補檔」', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '補檔'), $r['message'] ?? '');

    $r = api('version_replace_file', ['version_id' => 0], $superName);
    ck('缺版本 ID 被擋下', ($r['status'] ?? '') === 'error', $r['message'] ?? '');

    echo "\n── 一般改版（add_version）不受影響 ──\n";
    $r = api('add_version', ['doc_id' => $docId, 'version' => 'B-3', 'revised_date' => '2026-03-01'], $superName);
    ck('改版仍不接受比目前版本舊的版號', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', '往後推進'), $r['message'] ?? '');
} finally {
    // ── 清理：只刪本測試自己建立的 doc_id ──
    $db->prepare("DELETE FROM as_document_version WHERE doc_id=?")->execute([$docId]);
    $db->prepare("DELETE FROM as_document WHERE id=?")->execute([$docId]);
    $db->prepare("DELETE FROM page_change_log WHERE page_name='views/ADM/as_document_management.php' AND detail LIKE ?")
       ->execute(['%ZZ-TEST-OLDVER%']);
    echo "\n（測試資料已刪除 doc_id={$docId}）\n";
}

echo "\n===== 通過 {$pass} / 失敗 {$fail} =====\n";
exit($fail ? 1 : 0);
