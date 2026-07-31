<?php
/**
 * 請假統計測試（2026-07-31）
 *
 * 重點在「同一批資料的不同切法必須對得起來」——月報／年報／趨勢／部門／人員
 * 都是同一份請假單的不同彙總，任何一個切法的合計對不上 KPI 就是彙總寫歪了。
 * 另驗權限（一般使用者看不到、主管只看得到自己部門）與各項篩選。
 *
 * 測試資料以 reason 前綴 __test_stats__ 標記，只刪自己 lastInsertId 建立的列。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');

require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_stats_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4", "EG-TS2024", "excell30367593",
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ADMIN = 1;              // 最高權限（看全公司）
$PLAIN = 107092601;      // 邱冠宏（生產1廠，非主管）
$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $note = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; }
}
function feq(float $a, float $b, float $eps = 0.011): bool { return abs($a - $b) < $eps; }
function call_api(int $uid, array $req): array {
    $cmd = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe ' . escapeshellarg(__DIR__ . '/_api_runner.php')
         . ' ' . escapeshellarg((string)$uid)
         . ' ' . escapeshellarg(base64_encode(json_encode($req, JSON_UNESCAPED_UNICODE)))
         . ' ' . escapeshellarg(base64_encode('[]'));
    $out = trim((string)shell_exec($cmd));
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['__raw' => $out];
}
if (!is_file(__DIR__ . '/_api_runner.php')) {
    file_put_contents(__DIR__ . '/_api_runner.php', <<<'PHP'
<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
$_SERVER['REQUEST_METHOD'] = 'POST';
session_start();
$_SESSION['id'] = (int)$argv[1];
$_SESSION['userName'] = 'test';
$req  = json_decode(base64_decode($argv[2]), true) ?: [];
$post = json_decode(base64_decode($argv[3]), true) ?: [];
$_GET = $req; $_POST = $post; $_REQUEST = array_merge($req, $post);
ob_start();
include 'C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php';
$o = ob_get_clean();
echo $o;
PHP);
}

$Y  = (int)date('Y');
$Y1 = $Y - 1;
$T_SICK = 1; $T_PERSONAL = 2; $T_ANNUAL = 4;   // 病假 / 事假 / 特休

// 三個部門各挑人，用來驗部門彙總與主管範圍
$U_TECH1 = 109110201;   // 技術部
$U_TECH2 = 112020603;   // 技術部
$U_QC    = 112070301;   // 品管組
$U_PROD  = 107092601;   // 生產1廠（= $PLAIN）

/*  規劃的測試資料（狀態一律 approved，除了刻意放一張 pending 驗「含審核中」）
    今年：技1 事假3天/24h(3月)、技1 病假1天/8h(3月)、技2 特休2天/16h(7月)、
          品管 事假1.5天/12h(7月)、生產 特休4天/32h(11月)
          → 今年 approved 合計 11.5 天 / 92 小時 / 5 張 / 4 人
    去年：技1 事假5天/40h(6月)、品管 病假2天/16h(9月)
          → 去年 approved 合計 7 天 / 56 小時 / 2 張 / 2 人
    另加一張今年 pending：技2 事假10天/80h(8月)                                   */
$plan = [
    [$U_TECH1, $T_PERSONAL, "$Y-03-04",  24, 3,   'approved'],
    [$U_TECH1, $T_SICK,     "$Y-03-18",   8, 1,   'approved'],
    [$U_TECH2, $T_ANNUAL,   "$Y-07-08",  16, 2,   'approved'],
    [$U_QC,    $T_PERSONAL, "$Y-07-15",  12, 1.5, 'approved'],
    [$U_PROD,  $T_ANNUAL,   "$Y-11-03",  32, 4,   'approved'],
    [$U_TECH1, $T_PERSONAL, "$Y1-06-10", 40, 5,   'approved'],
    [$U_QC,    $T_SICK,     "$Y1-09-02", 16, 2,   'approved'],
    [$U_TECH2, $T_PERSONAL, "$Y-08-05",  80, 10,  'pending'],
];
$CUR_DAYS = 11.5; $CUR_HOURS = 92.0; $CUR_REQ = 5; $CUR_PEOPLE = 4;
$PREV_DAYS = 7.0; $PREV_REQ = 2;

$created = [];
$ins = $db->prepare(
    "INSERT INTO leave_request (employee_id, leave_type_id, start_datetime, end_datetime, reason,
                                status, total_hours, total_days, submit_time)
     VALUES (?, ?, ?, ?, '__test_stats__ 統計測試', ?, ?, ?, NOW())");
try {
    foreach ($plan as $p) {
        $ins->execute([$p[0], $p[1], $p[2] . ' 09:00:00', $p[2] . ' 17:00:00', $p[5], $p[3], $p[4]]);
        $created[] = (int)$db->lastInsertId();
    }
} catch (Throwable $e) { echo "建立測試資料失敗：" . $e->getMessage() . "\n"; exit(1); }
echo "建立測試單 " . count($created) . " 筆：#" . implode(' #', $created) . "\n";

// 這個資料庫裡可能已有其他請假單，因此驗證一律用「差額」：先取基準，再比對加入測試資料後的增量
function base_of(PDO $db, int $y, string $status = 'approved'): array {
    $st = $db->prepare("SELECT COALESCE(SUM(total_days),0) d, COALESCE(SUM(total_hours),0) h, COUNT(*) c
                        FROM leave_request WHERE status = ? AND YEAR(start_datetime) = ?
                          AND (reason IS NULL OR reason NOT LIKE '__test_stats__%')");
    $st->execute([$status, $y]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['d' => (float)$r['d'], 'h' => (float)$r['h'], 'c' => (int)$r['c']];
}
$baseCur = base_of($db, $Y);
$basePrev = base_of($db, $Y1);
echo "既有資料基準：今年 {$baseCur['d']} 天/{$baseCur['c']} 張、去年 {$basePrev['d']} 天/{$basePrev['c']} 張\n";

try {
    echo "== 權限 ==\n";
    $r = call_api($PLAIN, ['action' => 'stats', 'year' => $Y]);
    ok(empty($r['success']), '一般使用者查 stats 被擋', json_encode($r));
    $r = call_api($PLAIN, ['action' => 'stats_options']);
    ok(empty($r['success']), '一般使用者查 stats_options 被擋', json_encode($r));
    $r = call_api($ADMIN, ['action' => 'stats', 'year' => $Y]);
    ok(!empty($r['success']) && ($r['scope'] ?? '') === 'all', '管理員 scope=all', json_encode(array_slice($r, 0, 2)));

    $d = $r['data'];
    echo "== KPI（含既有資料，用差額驗）==\n";
    ok(feq((float)$d['kpi']['total_days'] - $baseCur['d'], $CUR_DAYS),
       "今年總天數增量 = $CUR_DAYS", $d['kpi']['total_days'] . ' - ' . $baseCur['d']);
    ok(feq((float)$d['kpi']['total_hours'] - $baseCur['h'], $CUR_HOURS),
       "今年總時數增量 = $CUR_HOURS", $d['kpi']['total_hours'] . ' - ' . $baseCur['h']);
    ok((int)$d['kpi']['req_count'] - $baseCur['c'] === $CUR_REQ,
       "今年單數增量 = $CUR_REQ", $d['kpi']['req_count'] . ' - ' . $baseCur['c']);
    ok((int)$d['kpi']['people_count'] >= $CUR_PEOPLE, "今年人數 >= $CUR_PEOPLE", (string)$d['kpi']['people_count']);
    $needKpi = ['total_days','total_hours','req_count','people_count','avg_days','top_type','top_type_days',
                'busiest_month','busiest_month_days'];
    ok(!array_diff($needKpi, array_keys($d['kpi'])), 'KPI 欄位齊全', json_encode(array_keys($d['kpi'])));
    $needTop = ['year','years','types','kpi','by_month','by_type','by_year','trend','by_dept','by_person'];
    ok(!array_diff($needTop, array_keys($d)), '回傳結構齊全', json_encode(array_keys($d)));

    echo "== 各切法必須對得起來（同一批資料的不同彙總）==\n";
    $sumMonth  = array_sum(array_column($d['by_month'], 'total_days'));
    $sumType   = array_sum(array_column($d['by_type'], 'days'));
    $sumDept   = array_sum(array_column($d['by_dept'], 'days'));
    $sumPerson = array_sum(array_column($d['by_person'], 'days'));
    $kpiDays   = (float)$d['kpi']['total_days'];
    ok(feq($sumMonth, $kpiDays),  '月報合計 == KPI 總天數',  "$sumMonth vs $kpiDays");
    ok(feq($sumType, $kpiDays),   '假別合計 == KPI 總天數',  "$sumType vs $kpiDays");
    ok(feq($sumDept, $kpiDays),   '部門合計 == KPI 總天數',  "$sumDept vs $kpiDays");
    ok(feq($sumPerson, $kpiDays), '人員合計 == KPI 總天數',  "$sumPerson vs $kpiDays");
    // 單數也要一致
    ok(array_sum(array_column($d['by_month'], 'req_count')) === (int)$d['kpi']['req_count'], '月報單數 == KPI 單數');
    ok(array_sum(array_column($d['by_person'], 'req_count')) === (int)$d['kpi']['req_count'], '人員單數 == KPI 單數');

    echo "== 年報／趨勢（跨年度，不受年度篩選影響）==\n";
    $byYear = array_column($d['by_year'], null, 'year');
    ok(isset($byYear[$Y]) && isset($byYear[$Y1]), "by_year 同時含 $Y 與 $Y1", json_encode(array_keys($byYear)));
    ok(feq((float)$byYear[$Y]['total_days'], $kpiDays), "by_year[$Y] == 選定年度 KPI");
    ok(feq((float)$byYear[$Y1]['total_days'] - $basePrev['d'], $PREV_DAYS),
       "by_year[$Y1] 增量 = $PREV_DAYS", $byYear[$Y1]['total_days'] . ' - ' . $basePrev['d']);
    $trendCur = 0.0;
    foreach ($d['trend'] as $t) if ((int)substr($t['ym'], 0, 4) === $Y) $trendCur += (float)$t['total_days'];
    ok(feq($trendCur, $kpiDays), '趨勢中屬於選定年度的月份合計 == KPI', "$trendCur vs $kpiDays");
    // 補零：起訖之間的每個月都要有點
    $yms = array_column($d['trend'], 'ym');
    $gapOk = true;
    for ($i = 1; $i < count($yms); $i++) {
        if ($yms[$i] !== date('Y-m', strtotime($yms[$i - 1] . '-01 +1 month'))) $gapOk = false;
    }
    ok($gapOk, '趨勢逐月連續（中間月份有補零）', json_encode(array_slice($yms, 0, 8)));

    echo "== 月份歸屬（以起日計）==\n";
    $mIdx = array_column($d['by_month'], null, 'month');
    ok(($mIdx[3]['by_type'][$T_PERSONAL] ?? 0) >= 3, '3 月事假 >= 3 天（技術部那張）', json_encode($mIdx[3]));
    ok(($mIdx[11]['by_type'][$T_ANNUAL] ?? 0) >= 4, '11 月特休 >= 4 天', json_encode($mIdx[11]));
    ok(count($d['by_month']) === 12, '月報固定 12 列（沒資料的月份也在）');

    echo "== 假別篩選 ==\n";
    $r2 = call_api($ADMIN, ['action' => 'stats', 'year' => $Y, 'type_ids' => (string)$T_ANNUAL]);
    $d2 = $r2['data'];
    $onlyAnnual = true;
    foreach ($d2['by_type'] as $t) if ((int)$t['leave_type_id'] !== $T_ANNUAL) $onlyAnnual = false;
    ok($onlyAnnual, '只選特休時 by_type 只有特休', json_encode(array_column($d2['by_type'], 'leave_name')));
    ok((float)$d2['kpi']['total_days'] < $kpiDays, '篩選後總天數小於未篩選',
       $d2['kpi']['total_days'] . ' vs ' . $kpiDays);
    ok(feq(array_sum(array_column($d2['by_month'], 'total_days')), (float)$d2['kpi']['total_days']),
       '篩選後月報合計仍等於 KPI');

    echo "== 部門／人員篩選 ==\n";
    $r3 = call_api($ADMIN, ['action' => 'stats', 'year' => $Y, 'dept_id' => 1]);   // 技術部
    $d3 = $r3['data'];
    ok(count($d3['by_dept']) <= 1, '指定部門後 by_dept 只剩該部門', json_encode(array_column($d3['by_dept'], 'dept_name')));
    ok(feq((float)$d3['kpi']['total_days'], array_sum(array_column($d3['by_person'], 'days'))), '部門篩選後人員合計 == KPI');
    $r4 = call_api($ADMIN, ['action' => 'stats', 'year' => $Y, 'user_id' => $U_TECH1]);
    $d4 = $r4['data'];
    ok(count($d4['by_person']) === 1 && (int)$d4['by_person'][0]['user_id'] === $U_TECH1,
       '指定人員後只剩該人', json_encode(array_column($d4['by_person'], 'user_id')));
    ok(feq((float)$d4['kpi']['total_days'], 4.0), '該人今年 4 天（事假3+病假1）', (string)$d4['kpi']['total_days']);
    $p0 = $d4['by_person'][0];
    ok(array_key_exists('position_name', $p0) && array_key_exists('dept_name', $p0)
       && array_key_exists('state_label', $p0) && array_key_exists('left_company', $p0),
       '人員列帶職稱／部門／在職狀態欄位', json_encode(array_keys($p0)));
    ok((string)$p0['dept_name'] === '技術部', '人員的部門正確帶出', (string)$p0['dept_name']);

    echo "== 主管：只看得到自己部門 ==\n";
    // 蔣騏竹（品管組組長，職稱有階級）→ scope=dept
    $SUP = 113092501; $SUP_DEPT = 3;
    $rs = call_api($SUP, ['action' => 'stats', 'year' => $Y]);
    ok(!empty($rs['success']) && ($rs['scope'] ?? '') === 'dept', '主管 scope=dept', json_encode(array_slice($rs, 0, 2)));
    $ds = $rs['data'];
    $supDepts = array_column($ds['by_dept'], 'dept_name');
    ok(!in_array('技術部', $supDepts, true), '主管看不到其他部門（技術部沒出現）', json_encode($supDepts));
    $supIds = array_column($ds['by_person'], 'user_id');
    ok(in_array($U_QC, $supIds, true), '主管看得到自己部門的人', json_encode($supIds));
    ok(!in_array($U_TECH1, $supIds, true), '主管看不到別部門的人', json_encode($supIds));
    // 主管硬帶別的部門 id 也不能穿透
    $rs2 = call_api($SUP, ['action' => 'stats', 'year' => $Y, 'dept_id' => 1]);
    $ids2 = array_column($rs2['data']['by_person'], 'user_id');
    ok(!in_array($U_TECH1, $ids2, true), '主管硬帶 dept_id=技術部 也看不到該部門的人', json_encode($ids2));

    echo "== 含審核中 ==\n";
    $r5 = call_api($ADMIN, ['action' => 'stats', 'year' => $Y, 'with_pending' => 1, 'user_id' => $U_TECH2]);
    $d5 = $r5['data'];
    ok(!empty($r5['with_pending']), '回傳 with_pending 旗標');
    ok(feq((float)$d5['kpi']['total_days'], 12.0), '技2 含審核中 = 2(特休)+10(事假pending)', (string)$d5['kpi']['total_days']);
    $r5b = call_api($ADMIN, ['action' => 'stats', 'year' => $Y, 'user_id' => $U_TECH2]);
    ok(feq((float)$r5b['data']['kpi']['total_days'], 2.0), '技2 只算已核准 = 2', (string)$r5b['data']['kpi']['total_days']);

    echo "== 全部年度 ==\n";
    $r6 = call_api($ADMIN, ['action' => 'stats', 'year' => 'all']);
    $d6 = $r6['data'];
    ok((string)$d6['year'] === 'all', 'year=all 原樣回傳');
    ok((float)$d6['kpi']['total_days'] > $kpiDays, 'year=all 總天數大於單一年度',
       $d6['kpi']['total_days'] . ' vs ' . $kpiDays);
    ok(feq(array_sum(array_column($d6['by_year'], 'total_days')), (float)$d6['kpi']['total_days']),
       'year=all 時年報合計 == KPI');

    echo "== 假別固定色盤（暖色系，同 id 同色）==\n";
    $colors = array_column($d['types'], 'color', 'id');
    ok(count($colors) === count(array_unique(array_values(array_slice($colors, 0, 10)))),
       '前 10 個假別顏色不重複', json_encode($colors));
    $warm = true;
    foreach ($colors as $c) {
        if (!preg_match('/^#([0-9A-F]{2})([0-9A-F]{2})([0-9A-F]{2})$/i', (string)$c, $mm)) { $warm = false; continue; }
        // 暖色判定：紅分量必須 >= 藍分量（冷色如藍/紫/藍綠會反過來）
        if (hexdec($mm[1]) < hexdec($mm[3])) $warm = false;
    }
    ok($warm, '全部假別色皆為暖色（R >= B）', json_encode($colors));
    $again = call_api($ADMIN, ['action' => 'stats', 'year' => $Y1]);
    ok(array_column($again['data']['types'], 'color', 'id') === $colors, '換年度時同一假別顏色不變');

    echo "== 空範圍不得退化成看全部 ==\n";
    $empty = eg_leave_stats($db, ['scope_user_ids' => [], 'year' => $Y]);
    ok((float)$empty['kpi']['total_days'] == 0.0 && $empty['by_person'] === [],
       '可視範圍為空 → 統計為 0（不是全公司）', json_encode($empty['kpi']));
    ok(count($empty['by_month']) === 12, '空結果仍有 12 個月的結構');

    echo "== stats_options 人員清單走 people_lib ==\n";
    $o = call_api($ADMIN, ['action' => 'stats_options']);
    ok(!empty($o['success']) && count($o['people']) > 0, 'stats_options 回傳人員', (string)count($o['people'] ?? []));
    $hasPos = false;
    foreach ($o['people'] as $p) if (strpos($p['label'], '（') !== false) { $hasPos = true; break; }
    ok($hasPos, '人員標籤含職稱（people_lib 的 display）', json_encode(array_slice(array_column($o['people'], 'label'), 0, 2)));
    $leftIn = false;
    $offIds = $db->query("SELECT id FROM `user` WHERE state = 0")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($o['people'] as $p) if (in_array((string)$p['id'], array_map('strval', $offIds), true)) $leftIn = true;
    ok(!$leftIn, '人員下拉不含離職者');
} finally {
    $del = $db->prepare("DELETE FROM leave_request WHERE id = ? AND reason LIKE '__test_stats__%'");
    foreach ($created as $cid) $del->execute([$cid]);
    $left = 0;
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $left = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE id IN ($in)")->fetchColumn();
    }
    ok($left === 0, '測試單已清除', "殘留 $left 筆");
}

echo "\n結果：PASS $pass / FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
