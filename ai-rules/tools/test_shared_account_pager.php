<?php
/**
 * 共用帳號成員列表分頁：把 hr_settings.php 裡的 saRenderMembers 分頁邏輯抽出來用 PHP 重跑一次，
 * 驗證「>10 才分頁、每頁筆數、頁碼視窗、邊界夾制」與畫面規範一致。
 * 另檢查頁面上分頁元件與 JS 是否都存在。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $note = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; }
}

// --- 與 JS 相同的分頁計算 ---
function pager(int $total, int $per, int $page): array {
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) $page = $pages;
    if ($page < 1) $page = 1;
    $from = (int)max(1, $page - 2);
    $to   = (int)min($pages, $from + 4);
    return [
        'pages'   => $pages,
        'page'    => $page,
        'rows'    => max(0, min($per, $total - ($page - 1) * $per)),   // 該頁列數
        'show'    => $total > $per,                                     // 是否顯示分頁列
        'buttons' => range($from, $to),
    ];
}

echo "== 分頁顯示門檻（預設每頁 10）==\n";
$r = pager(10, 10, 1); ok($r['show'] === false, '剛好 10 人不顯示分頁列');
$r = pager(11, 10, 1); ok($r['show'] === true && $r['pages'] === 2, '11 人顯示分頁、共 2 頁');
$r = pager(3, 10, 1);  ok($r['show'] === false, '3 人不顯示分頁列');

echo "== 每頁筆數 5/10/20/50 ==\n";
foreach ([5 => 3, 10 => 2, 20 => 1, 50 => 1] as $per => $expPages) {
    $r = pager(11, $per, 1);
    ok($r['pages'] === $expPages, "11 人、每頁 {$per} → {$expPages} 頁", '實際 ' . $r['pages']);
}
$r = pager(8, 5, 1); ok($r['show'] === true && $r['pages'] === 2, '每頁改 5 時 8 人也會分頁（總數>每頁即顯示）');

echo "== 每頁列數正確 ==\n";
$r = pager(23, 10, 1); ok($r['rows'] === 10, '23 人第1頁 10 列');
$r = pager(23, 10, 3); ok($r['rows'] === 3,  '23 人第3頁 3 列', (string)$r['rows']);
$r = pager(20, 10, 2); ok($r['rows'] === 10, '整除時最後一頁滿列');

echo "== 邊界夾制 ==\n";
$r = pager(23, 10, 99); ok($r['page'] === 3, '頁碼超出上限被夾到最後一頁', (string)$r['page']);
$r = pager(23, 10, 0);  ok($r['page'] === 1, '頁碼 0 被夾到第 1 頁');
$r = pager(5, 10, 2);   ok($r['page'] === 1, '資料變少後頁碼自動回落（移除成員後不會空白頁）');

echo "== 頁碼視窗最多 5 個 ==\n";
$r = pager(100, 10, 1); ok($r['buttons'] === [1,2,3,4,5], '第1頁顯示 1-5', implode(',', $r['buttons']));
$r = pager(100, 10, 7); ok($r['buttons'] === [5,6,7,8,9], '第7頁顯示 5-9', implode(',', $r['buttons']));
$r = pager(100, 10, 10); ok(count($r['buttons']) <= 5 && in_array(10, $r['buttons'], true), '最後一頁仍在視窗內', implode(',', $r['buttons']));

echo "== 頁面元件與 JS 存在 ==\n";
$src = file_get_contents('C:/MAMP/htdocs/EGsystem/views/ADM/hr_settings.php');
$need = [
    '分頁容器 sa_pager_wrap'   => 'id="sa_pager_wrap"',
    '每頁筆數 sa_per'          => 'id="sa_per"',
    '分頁鈕容器 sa_pager'      => 'id="sa_pager"',
    '總數顯示 sa_total'        => 'id="sa_total"',
    '每頁選項 5/10/20/50'      => '<option>5</option><option selected>10</option><option>20</option><option>50</option>',
    'saRenderMembers 已定義'   => 'function saRenderMembers',
    '分頁狀態變數'             => 'saMembers = [], saMembersFor = 0, saPage = 1',
    '切頁事件'                 => "on('click', '#sa_pager button'",
    '每頁筆數切換事件'         => "on('change', '#sa_per'",
    '取消標記時隱藏分頁'       => "$('#sa_pager_wrap').hide()",
];
foreach ($need as $n => $needle) ok(strpos($src, $needle) !== false, $n);
// 分頁列必須在表格之前（規範：分頁鈕在列表右上）
$pw = strpos($src, 'id="sa_pager_wrap"');
$tb = strpos($src, 'id="sa_members"');
ok($pw !== false && $tb !== false && $pw < $tb, '分頁列位於成員表格之前（右上）');
// 右對齊
ok(strpos($src, 'id="sa_pager_wrap" style="display:none;text-align:right;') !== false, '分頁列靠右對齊且預設隱藏');

printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
