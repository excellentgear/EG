<?php
/**
 * 既有料號的錐孔／外錐補算派生值（cd 大徑、cs 小徑、cn 錐度 1:N、ch 半錐角），讓清單的錐度篩選找得到舊資料。
 * 用法：php 本檔 [--run]   （預設只試算；可重複執行）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../../../src/common/part_shape_lib.php';
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$run = in_array('--run', $argv, true);
$fix = function (array $e) {
    $n = fn($k) => isset($e[$k]) && is_numeric($e[$k]) ? (float)$e[$k] : null;
    return array_merge($e, eg_shape_taper_derive($n('d'), $n('d2'), $n('tl'), $n('r'), $n('ha')));
};
$rows = $pdo->query("SELECT d_id,bore_json,outer_json FROM d_setting_shape WHERE bore_json LIKE '%\"tp\":1%' OR outer_json LIKE '%\"otp\":[{%'")->fetchAll(PDO::FETCH_ASSOC);
$cnt = 0;
foreach ($rows as $r) {
    $b = $r['bore_json'] ? json_decode($r['bore_json'], true) : null;
    if (is_array($b)) foreach ($b as $i => $e) if (!empty($e['tp'])) $b[$i] = $fix($e);
    $o = $r['outer_json'] ? json_decode($r['outer_json'], true) : null;
    if (is_array($o) && !empty($o['otp'])) foreach ($o['otp'] as $i => $e) $o['otp'][$i] = $fix($e);
    $nb = is_array($b) ? json_encode($b, JSON_UNESCAPED_UNICODE) : $r['bore_json'];
    $no = is_array($o) ? json_encode($o, JSON_UNESCAPED_UNICODE) : $r['outer_json'];
    if ($nb === $r['bore_json'] && $no === $r['outer_json']) continue;
    $cnt++;
    echo "d_id={$r['d_id']}\n";
    if ($run) $pdo->prepare("UPDATE d_setting_shape SET bore_json=?, outer_json=? WHERE d_id=?")->execute([$nb, $no, $r['d_id']]);
}
echo ($run ? '已更新 ' : '試算：需更新 ') . $cnt . " 筆（共掃描 " . count($rows) . " 筆有錐孔／外錐的料號）\n";
