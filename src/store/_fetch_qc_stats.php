<?php
// src/store/_fetch_qc_stats.php

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

session_start();
if (!isset($_SESSION['userName'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

include_once '../common/DBConnection.php';
include_once '../common/_config.php';

$conn = new DBConnection();
$db   = $conn->getPDO();

// 將 trend 陣列壓縮到最多 $maxBuckets 筆（自動分桶）
function bucketTrend(array $rows, int $maxBuckets): array {
    $n = count($rows);
    if ($n <= $maxBuckets) return $rows;
    $size     = (int)ceil($n / $maxBuckets);
    $bucketed = [];
    for ($i = 0; $i < $n; $i += $size) {
        $chunk = array_slice($rows, $i, $size);
        $first = $chunk[0]['day'] ?? '';
        $last  = end($chunk)['day'] ?? '';
        $bucketed[] = [
            'day'    => $first === $last ? $first : substr($first, 5) . '~' . substr($last, 5),
            'total'  => array_sum(array_column($chunk, 'total')),
            'ok_cnt' => array_sum(array_column($chunk, 'ok_cnt')),
            'ng_cnt' => array_sum(array_column($chunk, 'ng_cnt')),
        ];
    }
    return $bucketed;
}

try {
    // ── 今天 / 本週 / 本月 計數 ──
    $periodStmt = $db->prepare("
        SELECT
            SUM(CASE WHEN DATE(QC_check_date) = CURDATE()                                    THEN 1 ELSE 0 END) AS today_total,
            SUM(CASE WHEN DATE(QC_check_date) = CURDATE() AND QC_check = 'ok'                THEN 1 ELSE 0 END) AS today_ok,
            SUM(CASE WHEN DATE(QC_check_date) = CURDATE() AND QC_check IN ('QQ','ng','AOD')  THEN 1 ELSE 0 END) AS today_ng,

            SUM(CASE WHEN YEARWEEK(QC_check_date,1) = YEARWEEK(CURDATE(),1)                               THEN 1 ELSE 0 END) AS week_total,
            SUM(CASE WHEN YEARWEEK(QC_check_date,1) = YEARWEEK(CURDATE(),1) AND QC_check = 'ok'           THEN 1 ELSE 0 END) AS week_ok,
            SUM(CASE WHEN YEARWEEK(QC_check_date,1) = YEARWEEK(CURDATE(),1) AND QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS week_ng,

            SUM(CASE WHEN DATE_FORMAT(QC_check_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')                        THEN 1 ELSE 0 END) AS month_total,
            SUM(CASE WHEN DATE_FORMAT(QC_check_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m') AND QC_check = 'ok'    THEN 1 ELSE 0 END) AS month_ok,
            SUM(CASE WHEN DATE_FORMAT(QC_check_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m') AND QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS month_ng
        FROM qc_check
        WHERE YEAR(QC_check_date) > 0
    ");
    $periodStmt->execute();
    $periods = $periodStmt->fetch(PDO::FETCH_ASSOC);

    // ── 近 30 天每日趨勢 ──
    $trendStmt = $db->prepare("
        SELECT
            DATE_FORMAT(QC_check_date,'%Y-%m-%d')                          AS day,
            SUM(1)                                                         AS total,
            SUM(CASE WHEN QC_check = 'ok'               THEN 1 ELSE 0 END) AS ok_cnt,
            SUM(CASE WHEN QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS ng_cnt
        FROM qc_check
        WHERE YEAR(QC_check_date) > 0
          AND QC_check_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE_FORMAT(QC_check_date,'%Y-%m-%d')
        ORDER BY DATE_FORMAT(QC_check_date,'%Y-%m-%d') ASC
    ");
    $trendStmt->execute();
    $trend30d = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 本週每日趨勢（週一起）──
    $trendWeekStmt = $db->prepare("
        SELECT
            DATE_FORMAT(QC_check_date,'%Y-%m-%d')                          AS day,
            SUM(1)                                                         AS total,
            SUM(CASE WHEN QC_check = 'ok'               THEN 1 ELSE 0 END) AS ok_cnt,
            SUM(CASE WHEN QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS ng_cnt
        FROM qc_check
        WHERE YEAR(QC_check_date) > 0
          AND QC_check_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
          AND DATE(QC_check_date) <= CURDATE()
        GROUP BY DATE_FORMAT(QC_check_date,'%Y-%m-%d')
        ORDER BY DATE_FORMAT(QC_check_date,'%Y-%m-%d') ASC
    ");
    $trendWeekStmt->execute();
    $trendWeek = $trendWeekStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 本月每日趨勢 ──
    $trendMonthStmt = $db->prepare("
        SELECT
            DATE_FORMAT(QC_check_date,'%Y-%m-%d')                          AS day,
            SUM(1)                                                         AS total,
            SUM(CASE WHEN QC_check = 'ok'               THEN 1 ELSE 0 END) AS ok_cnt,
            SUM(CASE WHEN QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS ng_cnt
        FROM qc_check
        WHERE YEAR(QC_check_date) > 0
          AND QC_check_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
          AND DATE(QC_check_date) <= CURDATE()
        GROUP BY DATE_FORMAT(QC_check_date,'%Y-%m-%d')
        ORDER BY DATE_FORMAT(QC_check_date,'%Y-%m-%d') ASC
    ");
    $trendMonthStmt->execute();
    $trendMonth = $trendMonthStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 本月各製程 OK / NG 明細（前10）──
    $byProcessStmt = $db->prepare("
        SELECT
            bi.process_no,
            COALESCE(pn.ProcessName, bi.process_no, '未設定') AS ProcessName,
            SUM(1)                                             AS total,
            SUM(CASE WHEN qc.QC_check = 'ok'               THEN 1 ELSE 0 END) AS ok_cnt,
            SUM(CASE WHEN qc.QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS ng_cnt
        FROM qc_check qc
        JOIN bom_ing bi ON bi.bom_ing_fid = qc.bom_ing_fid_ref
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        WHERE YEAR(qc.QC_check_date) > 0
          AND DATE_FORMAT(qc.QC_check_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
        GROUP BY bi.process_no, pn.ProcessName
        ORDER BY total DESC
        LIMIT 10
    ");
    $byProcessStmt->execute();
    $byProcess = $byProcessStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 自訂日期區間統計 ──
    $customResult = null;
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to']   ?? '');
    if ($dateFrom !== '' && $dateTo !== '') {
        $customStmt = $db->prepare("
            SELECT
                SUM(1)                                             AS total,
                SUM(CASE WHEN QC_check = 'ok'               THEN 1 ELSE 0 END) AS ok_cnt,
                SUM(CASE WHEN QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS ng_cnt,
                MIN(DATE_FORMAT(QC_check_date,'%Y-%m-%d'))        AS actual_from,
                MAX(DATE_FORMAT(QC_check_date,'%Y-%m-%d'))        AS actual_to
            FROM qc_check
            WHERE YEAR(QC_check_date) > 0
              AND DATE(QC_check_date) BETWEEN ? AND ?
        ");
        $customStmt->execute([$dateFrom, $dateTo]);
        $customResult = $customStmt->fetch(PDO::FETCH_ASSOC);
        if (!$customResult) $customResult = ['total'=>0,'ok_cnt'=>0,'ng_cnt'=>0,'actual_from'=>null,'actual_to'=>null];

        // 自訂區間每日趨勢（自動分桶至最多30筆）
        $customTrendStmt = $db->prepare("
            SELECT
                DATE_FORMAT(QC_check_date,'%Y-%m-%d')                          AS day,
                SUM(1)                                                         AS total,
                SUM(CASE WHEN QC_check = 'ok'               THEN 1 ELSE 0 END) AS ok_cnt,
                SUM(CASE WHEN QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS ng_cnt
            FROM qc_check
            WHERE YEAR(QC_check_date) > 0
              AND DATE(QC_check_date) BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(QC_check_date,'%Y-%m-%d')
            ORDER BY DATE_FORMAT(QC_check_date,'%Y-%m-%d') ASC
        ");
        $customTrendStmt->execute([$dateFrom, $dateTo]);
        $rawTrend = $customTrendStmt->fetchAll(PDO::FETCH_ASSOC);
        $customResult['trend'] = bucketTrend($rawTrend, 30);

        // 若為單日查詢，額外回傳該日前後 30 天趨勢（供日期導航使用）
        if ($dateFrom === $dateTo) {
            $ctxStmt = $db->prepare("
                SELECT
                    DATE_FORMAT(QC_check_date,'%Y-%m-%d')                          AS day,
                    SUM(1)                                                         AS total,
                    SUM(CASE WHEN QC_check = 'ok'               THEN 1 ELSE 0 END) AS ok_cnt,
                    SUM(CASE WHEN QC_check IN ('QQ','ng','AOD') THEN 1 ELSE 0 END) AS ng_cnt
                FROM qc_check
                WHERE YEAR(QC_check_date) > 0
                  AND QC_check_date >= DATE_SUB(?, INTERVAL 29 DAY)
                  AND DATE(QC_check_date) <= ?
                GROUP BY DATE_FORMAT(QC_check_date,'%Y-%m-%d')
                ORDER BY DATE_FORMAT(QC_check_date,'%Y-%m-%d') ASC
            ");
            $ctxStmt->execute([$dateTo, $dateTo]);
            $customResult['trend_context'] = $ctxStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    echo json_encode([
        'success'      => true,
        'today'        => ['total' => (int)($periods['today_total']??0), 'ok' => (int)($periods['today_ok']??0), 'ng' => (int)($periods['today_ng']??0)],
        'week'         => ['total' => (int)($periods['week_total']??0),  'ok' => (int)($periods['week_ok']??0),  'ng' => (int)($periods['week_ng']??0)],
        'month'        => ['total' => (int)($periods['month_total']??0), 'ok' => (int)($periods['month_ok']??0), 'ng' => (int)($periods['month_ng']??0)],
        'trend_30d'    => $trend30d,
        'trend_week'   => $trendWeek,
        'trend_month'  => $trendMonth,
        'by_process'   => $byProcess,
        'custom'       => $customResult,
        'query_date'   => date('Y-m-d'),
    ]);

} catch (PDOException $e) {
    error_log('[_fetch_qc_stats] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[_fetch_qc_stats] Unexpected: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()]);
}
