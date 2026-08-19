<?php
/**
 * AS 文件管理：品質記錄一覽表（qr_get / qr_save_settings / qr_save_items）功能測試。
 * 以偽造 session 直接跑真 API。測試資料用完即刪（含還原原本的設定值與明細）。
 * 用法： php ai-rules/tools/test_asdoc_quality_record.php
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
    $runner = sys_get_temp_dir() . '/asdoc_qr_runner.php';
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

// ── 備份既有設定與明細（測試後還原，避免動到正式資料）──
$bkItems  = $db->query("SELECT * FROM as_quality_record_item ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$bkYears  = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='as_doc_qr_default_years'")->fetchColumn();
$bkDate   = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='as_doc_qr_make_date'")->fetchColumn();
$bkBind   = $db->query("SELECT param_value FROM system_parameters WHERE param_group='AS_DOC_BIND' AND param_key='as_doc_quality_record_list'")->fetchColumn();

// ── 測試文件：兩張四階表單＋一張已刪除的 ──
$mk = function(string $no, string $name, int $del = 0) use ($db) {
    $db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,is_deleted,created_by,created_at)
                  VALUES (?,?,'表單','四階',?,'TEST',NOW())")->execute([$no,$name,$del]);
    return (int)$db->lastInsertId();
};
$f1 = $mk('ZZ-QR-01-01', '品記測試表單A');
$f2 = $mk('ZZ-QR-01-02', '品記測試表單B');
$fx = $mk('ZZ-QR-01-99', '品記測試已刪除表單', 1);
$deptId = (int)$db->query("SELECT id FROM department ORDER BY id LIMIT 1")->fetchColumn();
echo "測試表單 {$f1}, {$f2}（已刪除 {$fx}）\n\n";

try {
    echo "── 設定（qr_save_settings）──\n";
    $r = api('qr_save_settings', ['default_years'=>'5', 'make_date'=>'2026-08-19'], $adminName);
    ck('儲存預設保存年限＋製表日期', ($r['status'] ?? '')==='success', json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = api('qr_save_settings', ['default_years'=>'150'], $adminName);
    ck('預設保存年限超過範圍被擋下', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '0~99'), $r['message'] ?? '');
    $r = api('qr_save_settings', ['default_years'=>'5', 'make_date'=>'2026/08/19'], $adminName);
    ck('製表日期格式錯誤被擋下', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '格式'), $r['message'] ?? '');

    // 綁定 AS 文件（走 asdoc_lib）
    $r = api('qr_save_settings', ['default_years'=>'5', 'make_date'=>'2026-08-19', 'as_doc_id'=>(string)$f1], $adminName);
    ck('AS 文件綁定寫入（走 asdoc_lib）', ($r['status'] ?? '')==='success' && (int)($r['as_doc']['id'] ?? 0)===$f1, json_encode($r, JSON_UNESCAPED_UNICODE));
    $bind = $db->query("SELECT param_value FROM system_parameters WHERE param_group='AS_DOC_BIND' AND param_key='as_doc_quality_record_list'")->fetchColumn();
    ck('綁定只存 id 且落在 AS_DOC_BIND', (int)$bind === $f1, '實際='.$bind);

    echo "\n── 明細（qr_save_items）──\n";
    $r = api('qr_save_items', ['rows'=>json_encode([
        ['doc_id'=>$f1, 'retention_years'=>'', 'keeper_dept_id'=>'', 'note'=>'留空＝套用預設'],
        ['doc_id'=>$f2, 'retention_years'=>'10', 'keeper_dept_id'=>(string)$deptId, 'note'=>'個別覆寫'],
    ])], $adminName);
    ck('存入兩筆明細', ($r['status'] ?? '')==='success' && (int)($r['count'] ?? 0)===2, json_encode($r, JSON_UNESCAPED_UNICODE));

    $r = api('qr_save_items', ['rows'=>json_encode([['doc_id'=>$f1],['doc_id'=>$f1]])], $adminName);
    ck('同一份表單重複列入被擋下', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '重複'), $r['message'] ?? '');
    $r = api('qr_save_items', ['rows'=>json_encode([['doc_id'=>$f1,'retention_years'=>'abc']])], $adminName);
    ck('保存年限非整數被擋下', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '整數'), $r['message'] ?? '');
    $r = api('qr_save_items', ['rows'=>json_encode([['doc_id'=>$fx]])], $adminName);
    ck('已刪除的文件不可列入', ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '已刪除'), $r['message'] ?? '');
    $cnt = (int)$db->query("SELECT COUNT(*) FROM as_quality_record_item")->fetchColumn();
    ck('被擋下時原有明細未被清掉（transaction）', $cnt === 2, '實際='.$cnt);

    echo "\n── 讀取（qr_get）──\n";
    $r = api('qr_get', [], $adminName);
    ck('讀取成功', ($r['status'] ?? '')==='success', json_encode($r['message'] ?? '', JSON_UNESCAPED_UNICODE));
    ck('預設保存年限回傳 5', (int)($r['default_years'] ?? 0)===5);
    ck('明細依 sort_order 排序且帶出編號/名稱', count($r['items'] ?? [])===2
        && $r['items'][0]['doc_no']==='ZZ-QR-01-01' && $r['items'][1]['doc_no']==='ZZ-QR-01-02',
        json_encode(array_column($r['items'] ?? [], 'doc_no')));
    ck('留空的保存年限存成 NULL（由前端套預設）', $r['items'][0]['retention_years']===null);
    ck('個別覆寫的保存年限＝10、保管單位有帶名稱', (int)$r['items'][1]['retention_years']===10
        && !empty($r['items'][1]['keeper_dept_name']), json_encode($r['items'][1]['keeper_dept_name'] ?? null, JSON_UNESCAPED_UNICODE));
    ck('候選清單含測試表單、不含已刪除者',
        in_array('ZZ-QR-01-01', array_column($r['candidates'] ?? [], 'doc_no'), true)
        && !in_array('ZZ-QR-01-99', array_column($r['candidates'] ?? [], 'doc_no'), true));
    ck('回傳公司全名（列印大標題用，禁寫死）', ($r['company_name'] ?? '') !== '');
    ck('回傳綁定文件與依製表日期回推的編號', (int)($r['as_doc']['id'] ?? 0)===$f1 && isset($r['as_doc']['doc_no_asof']),
        json_encode($r['as_doc'] ?? null, JSON_UNESCAPED_UNICODE));

    if ($other) {
        echo "\n── 權限 ──\n";
        $r = api('qr_save_items', ['rows'=>json_encode([['doc_id'=>$f1]])], $other);
        ck("非設定權限者（{$other}）不可改明細", ($r['status'] ?? '')==='error' && str_contains($r['message'] ?? '', '設定權限'), $r['message'] ?? '');
        $r = api('qr_save_settings', ['default_years'=>'9'], $other);
        ck("非設定權限者（{$other}）不可改設定", ($r['status'] ?? '')==='error', $r['message'] ?? '');
    }
} finally {
    // ── 還原：明細、設定值、綁定 ──
    $db->exec("DELETE FROM as_quality_record_item");
    if ($bkItems) {
        $ins = $db->prepare("INSERT INTO as_quality_record_item (doc_id,retention_years,keeper_dept_id,note,sort_order) VALUES (?,?,?,?,?)");
        foreach ($bkItems as $b) $ins->execute([$b['doc_id'],$b['retention_years'],$b['keeper_dept_id'],$b['note'],$b['sort_order']]);
    }
    $set = function(string $k, $v) use ($db) {
        if ($v === false || $v === null) { $db->prepare("DELETE FROM system_settings WHERE setting_key=?")->execute([$k]); return; }
        $db->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]);
    };
    $set('as_doc_qr_default_years', $bkYears);
    $set('as_doc_qr_make_date', $bkDate);
    if ($bkBind === false) $db->prepare("DELETE FROM system_parameters WHERE param_group='AS_DOC_BIND' AND param_key='as_doc_quality_record_list'")->execute();
    else $db->prepare("UPDATE system_parameters SET param_value=? WHERE param_group='AS_DOC_BIND' AND param_key='as_doc_quality_record_list'")->execute([$bkBind]);

    foreach ([$f1,$f2,$fx] as $x) $db->prepare("DELETE FROM as_document WHERE id=?")->execute([$x]);
    echo "\n（測試資料已刪除，設定與明細已還原）\n";
}
echo "\n===== 通過 {$pass} / 失敗 {$fail} =====\n";
exit($fail ? 1 : 0);
