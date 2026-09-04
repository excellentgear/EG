<?php
// src/store/_fetch_qc_data.php

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

// ── QC完工紀錄查詢模式 ───────────────────────────────────────
if (($_GET['mode'] ?? '') === 'completed') {
    $page    = max(1, (int)($_GET['page']    ?? 1));
    $perPage = max(1, min(100, (int)($_GET['perPage'] ?? 10)));
    $offset  = ($page - 1) * $perPage;
    $search  = trim($_GET['search'] ?? '');

    // include_pending=1：連「已經報工、但還沒有人按【完成】」的也一起列出來。
    // 預設 0＝維持原本只列已完工，既有使用者看到的畫面與筆數一個字都不會變。
    $incPending = (($_GET['include_pending'] ?? '') === '1');

    // 有沒有報工紀錄，兩種模式都要用到（未完工的判定、排序的時間來源）
    $qcJoin = "
            LEFT JOIN (
                SELECT bom_ing_fid_ref,
                    SUM(CASE WHEN QC_check='QQ' THEN QC_QQ_sqty ELSE 0 END) AS QC_QQ_sqty,
                    SUM(CASE WHEN QC_check='ok' THEN QC_ok_sqty  ELSE 0 END) AS QC_ok_sqty,
                    SUM(CASE WHEN QC_check='ng' THEN QC_ng_sqty  ELSE 0 END) AS QC_ng_sqty,
                    SUM(CASE WHEN QC_check='AOD' THEN QC_aod_sqty ELSE 0 END) AS QC_aod_sqty,
                    MAX(qc_check_id)   AS max_qc_check_id,
                    MAX(QC_check_date) AS last_check_at
                FROM qc_check
                GROUP BY bom_ing_fid_ref
            ) qc ON qc.bom_ing_fid_ref = bi.bom_ing_fid";

    try {
        $whereStr = $incPending
            ? "WHERE (bi.qc_completed = 1 OR qc.bom_ing_fid_ref IS NOT NULL)"
            : "WHERE bi.qc_completed = 1";
        $binds    = [];
        if ($search !== '') {
            $like = '%' . $search . '%';
            $whereStr .= " AND (bi.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ? OR bi.maker_id LIKE ?)";
            $binds = [$like, $like, $like, $like];
        }

        // 計數也要掛同一個 qc join，否則 include_pending 時筆數與清單對不起來
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM bom_ing bi JOIN bom b ON bi.bom=b.bom $qcJoin $whereStr");
        $cntStmt->execute($binds);
        $totalRecords = (int)$cntStmt->fetchColumn();

        // LIMIT/OFFSET 直接用 intval 插入 SQL，避免混用命名與位置參數
        $lim = (int)$perPage;
        $off = (int)$offset;

        // 排序：預設模式一個字都不改（有 1 筆 qc_completed=1 但 qc_completed_at 是 NULL，
        // 換成 COALESCE 會讓它換位置）。只有勾了「含未完工」時才改用
        // 「完工時間，沒有就用最後一次報工時間」，否則未完工的會全部沉到最底下。
        $orderBy = $incPending
            ? "ORDER BY COALESCE(bi.qc_completed_at, qc.last_check_at) DESC"
            : "ORDER BY bi.qc_completed_at DESC";

        $dataStmt = $db->prepare("
            SELECT
                bi.bom_ing_fid, bi.bom, b.d_id, b.Client_Name,
                pn.ProcessName, bi.maker_id, bi.sqty,
                DATE_FORMAT(bi.qc_completed_at,'%Y-%m-%d %H:%i') AS qc_completed_at,
                u.user_cname AS qc_completed_by_name,
                bi.qc_completed,
                bi.processing_state,
                DATE_FORMAT(qc.last_check_at,'%Y-%m-%d %H:%i') AS last_check_at,
                bi.QC_ps AS biqc_ps,
                -- QC 檢驗結果彙總
                COALESCE(qc.QC_QQ_sqty,0) AS QC_QQ_sqty,
                COALESCE(qc.QC_ok_sqty,0) AS QC_ok_sqty,
                COALESCE(qc.QC_ng_sqty,0) AS QC_ng_sqty,
                COALESCE(qc.QC_aod_sqty,0) AS QC_aod_sqty,
                -- 異常單
                qao.abnormal_order_no,
                qao.id AS qa_abnormal_id,
                qao.responsible_unit AS qa_responsible_unit,
                qao.is_closed AS qa_is_closed
            FROM bom_ing bi
            JOIN bom b ON bi.bom=b.bom
            LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
            LEFT JOIN user u ON u.id=bi.qc_completed_by
            $qcJoin
            LEFT JOIN qa_abnormal_order qao
                ON qao.source_type='QC' AND qao.source_id=qc.max_qc_check_id
            $whereStr
            $orderBy
            LIMIT $lim OFFSET $off
        ");
        $dataStmt->execute($binds);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'      => true,
            'data'         => $rows,
            'totalRecords' => $totalRecords,
            'totalPages'   => max(1, (int)ceil($totalRecords / $perPage)),
            'page'         => $page,
            'perPage'      => $perPage,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── 待驗清單查詢 ─────────────────────────────────────────────
$page    = max(1, (int)($_GET['page']    ?? 1));
$perPage = max(1, (int)($_GET['perPage'] ?? 30));
$offset  = ($page - 1) * $perPage;
// all=1：不分頁，一次取出符合目前篩選的全部資料。
// 只給「一鍵完成」按下去的當下用（要對全部符合條件的資料判定，不能只看這一頁），
// 沒帶這個參數時分頁行為完全不變。
if (($_GET['all'] ?? '') === '1') { $page = 1; $perPage = 100000; $offset = 0; }

$filterPTI    = trim($_GET['pti']    ?? '');
$filterQC     = trim($_GET['qc']     ?? '');
$filterSearch = trim($_GET['search'] ?? '');

$extraParts = [];
$extraBinds = [];

if ($filterPTI !== '') {
    // 與 master_data_management.php 相同的 UNION 邏輯：
    // 比對 process_type_process_map（額外對應）OR process_no.process_type_id（主類型）
    $extraParts[] = "(bi.process_no IN (SELECT process_no_id FROM process_type_process_map WHERE process_type_id = ?) OR pn.process_type_id = ?)";
    $extraBinds[] = $filterPTI;
    $extraBinds[] = $filterPTI;
}
if ($filterSearch !== '') {
    $like = '%' . $filterSearch . '%';
    $extraParts[] = "(bi.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ? OR bi.maker_id LIKE ? OR pn.ProcessName LIKE ?)";
    $extraBinds   = array_merge($extraBinds, [$like, $like, $like, $like, $like]);
}
// 已檢驗數量的唯一算法：允收＋異常＋驗退＋特採。前端的徽章與「一鍵完成」
// 用同一個算法（qcInspectedQty()），兩邊要一致，不可各寫一份。
const QC_DONE_SUM = "(COALESCE(qc.QC_ok_sqty,0)+COALESCE(qc.QC_QQ_sqty,0)+COALESCE(qc.QC_ng_sqty,0)+COALESCE(qc.QC_aod_sqty,0))";
// 後站已開工：同一個 BOM 裡 bom_sn 更後面的站已經發過單且在進行中／已回廠。
// 用來標出「其實早就跑到下一關、只是前站補按回廠」的那些列。
const QC_NEXT_STARTED_SQL = "EXISTS (
        SELECT 1 FROM bom_ing nb
        WHERE nb.bom = bi.bom
          AND nb.bom_sn > bi.bom_sn
          AND nb.is_consumed = 0
          AND nb.outsource_date IS NOT NULL
          AND nb.processing_state IN ('ing','Q','P','E')
    )";

if ($filterQC === 'gray') {
    $extraParts[] = "(bi.QC_check IS NULL OR bi.QC_check='') AND COALESCE(qc.QC_QQ_sqty,0)=0 AND COALESCE(qc.QC_ok_sqty,0)=0";
} elseif ($filterQC === 'qq') {
    $extraParts[] = "COALESCE(qc.QC_QQ_sqty,0) > 0";
} elseif ($filterQC === 'green') {
    $extraParts[] = "COALESCE(qc.QC_ok_sqty,0) > 0";
} elseif ($filterQC === 'full') {
    // 已經報工驗滿、只差沒人按「完成」
    $extraParts[] = "bi.sqty > 0 AND " . QC_DONE_SUM . " >= bi.sqty";
} elseif ($filterQC === 'part') {
    // 報了一部分（例：1500 只驗了 130）
    $extraParts[] = QC_DONE_SUM . " > 0 AND (bi.sqty <= 0 OR " . QC_DONE_SUM . " < bi.sqty)";
} elseif ($filterQC === 'nextstarted') {
    $extraParts[] = QC_NEXT_STARTED_SQL;
}

$extraWhere = empty($extraParts) ? '' : ' AND ' . implode(' AND ', $extraParts);

// 關鍵：用 YEAR() > 0 過濾無效 TIMESTAMP，不直接比較 '0000-00-00 00:00:00'
$sql = "
SELECT SQL_CALC_FOUND_ROWS
    bi.bom_ing_fid,
    bi.bom,
    b.d_id,
    b.Client_Name,
    b.processing_state          AS b_processing_state,
    bi.processing_state,
    DATE_FORMAT(bi.outsource_date,'%m/%d') AS outsource_date,
    DATE_FORMAT(bi.return_date,'%m/%d')    AS return_date,
    bi.QC_check,
    IF(YEAR(bi.QC_check_date) > 0, DATE_FORMAT(bi.QC_check_date,'%m/%d'), NULL) AS QC_check_date,
    bi.QC_ps    AS BIQC_ps,
    bi.QC_ps2   AS BIQC_ps2,
    bi.pm_ps    AS BIPM_ps,
    bi.pm_ps2   AS BIPM_ps2,
    bi.QC_ps2   AS QC_ps_ng,
    bi.QC_ps_aod AS QC_ps_aod_remark,
    bi.single_bet_ps,
    bi.ps,
    bi.sqty,
    pn.ProcessNo,
    pn.ProcessName,
    pn.process_type_id,
    bi.maker_id,
    COALESCE(qc.QC_check_count, 0)  AS QC_check_count,
    qc.QC_ps_qq,
    qc.all_QC_ps_ok                 AS QC_ps_ok,
    COALESCE(qc.QC_QQ_sqty,  0)     AS QC_QQ_sqty,
    COALESCE(qc.QC_ng_sqty,  0)     AS QC_ng_sqty,
    COALESCE(qc.QC_aod_sqty, 0)     AS QC_aod_sqty,
    COALESCE(qc.QC_ok_sqty,  0)     AS QC_ok_sqty,
    qc.max_qc_check_id,
    qq_date.latest_QQ_date_formatted,
    ok_date.latest_ok_date_formatted,
    qao.abnormal_order_no,
    qao.id AS qa_abnormal_id,
    -- 後站已開工（1/0）與是哪一站、哪天發的單，供清單標示「補按回廠」用
    " . QC_NEXT_STARTED_SQL . " AS next_started,
    (SELECT CONCAT(COALESCE(pnn.ProcessName,''), '|', COALESCE(DATE_FORMAT(nb2.outsource_date,'%m/%d'),''))
       FROM bom_ing nb2
       LEFT JOIN process_no pnn ON pnn.ProcessNo = nb2.process_no
      WHERE nb2.bom = bi.bom
        AND nb2.bom_sn > bi.bom_sn
        AND nb2.is_consumed = 0
        AND nb2.outsource_date IS NOT NULL
        AND nb2.processing_state IN ('ing','Q','P','E')
      ORDER BY nb2.bom_sn ASC LIMIT 1) AS next_started_info
FROM bom_ing bi
JOIN (
    SELECT bom, COALESCE(bom_sn, -1) AS sn, COALESCE(batch_label, '') AS bl, MAX(outsource_date) AS max_date
    FROM bom_ing
    WHERE processing_state IN ('Q','P')
      AND is_consumed = 0
    GROUP BY bom, COALESCE(bom_sn, -1), COALESCE(batch_label, '')
) latest ON bi.bom = latest.bom
        AND COALESCE(bi.bom_sn, -1) = latest.sn
        AND bi.outsource_date = latest.max_date
        AND COALESCE(bi.batch_label, '') = latest.bl
LEFT JOIN bom_ing newer ON
    newer.bom = bi.bom
    AND COALESCE(newer.bom_sn, -1) = COALESCE(bi.bom_sn, -1)
    AND newer.outsource_date > bi.outsource_date
    AND newer.processing_state IN ('ing','E','P')
    AND COALESCE(newer.batch_label, '') = COALESCE(bi.batch_label, '')
    AND newer.is_consumed = 0
JOIN bom b ON bi.bom = b.bom
LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
LEFT JOIN (
    SELECT
        bom_ing_fid_ref,
        COUNT(*)                                                               AS QC_check_count,
        MAX(CASE WHEN QC_check='QQ' THEN QC_ps   ELSE NULL END)               AS QC_ps_qq,
        GROUP_CONCAT(DISTINCT CASE WHEN QC_check='ok' THEN QC_ps_ok END SEPARATOR '; ') AS all_QC_ps_ok,
        SUM(CASE WHEN QC_check='QQ'  THEN QC_QQ_sqty  ELSE 0 END)             AS QC_QQ_sqty,
        SUM(CASE WHEN QC_check='ng'  THEN QC_ng_sqty   ELSE 0 END)             AS QC_ng_sqty,
        SUM(CASE WHEN QC_check='AOD' THEN QC_aod_sqty  ELSE 0 END)             AS QC_aod_sqty,
        SUM(CASE WHEN QC_check='ok'  THEN QC_ok_sqty   ELSE 0 END)             AS QC_ok_sqty,
        MAX(qc_check_id)                                                        AS max_qc_check_id
    FROM qc_check
    GROUP BY bom_ing_fid_ref
) qc ON qc.bom_ing_fid_ref = bi.bom_ing_fid
LEFT JOIN (
    SELECT bom_ing_fid_ref,
           DATE_FORMAT(MAX(QC_check_date),'%m/%d') AS latest_QQ_date_formatted
    FROM qc_check
    WHERE QC_check='QQ' AND YEAR(QC_check_date) > 0
    GROUP BY bom_ing_fid_ref
) qq_date ON qq_date.bom_ing_fid_ref = bi.bom_ing_fid
LEFT JOIN (
    SELECT bom_ing_fid_ref,
           DATE_FORMAT(MAX(QC_check_date),'%m/%d') AS latest_ok_date_formatted
    FROM qc_check
    WHERE QC_check='ok' AND YEAR(QC_check_date) > 0
    GROUP BY bom_ing_fid_ref
) ok_date ON ok_date.bom_ing_fid_ref = bi.bom_ing_fid
LEFT JOIN qa_abnormal_order qao
    ON qao.source_type='QC' AND qao.source_id=qc.max_qc_check_id
WHERE
    b.processing_state IS NULL
    AND NOT b.processing_state <=> 1
    AND bi.processing_state IN ('Q','P')
    AND bi.qc_completed = 0
    AND bi.is_consumed = 0
    AND newer.bom_ing_fid IS NULL
    AND (bi.ps IS NULL OR bi.ps NOT LIKE '%(拆分工單)%')
    -- ★ 2026-06-23 只取「目前製程」：每個 bom 取最新發外日(outsource_date)的工序，
    --   參照 views/pm/OreadyReply_ForPm_BaseOfTime2.php 的目前製程邏輯。
    --   目的：不再把已跳過的較舊工序全部當待驗列出。
    --   若要還原成「每個工序各列一筆」的舊行為，移除下面這段 AND 條件即可
    --   （或直接以 _fetch_qc_data.php.bak_20260623_before_currentprocess 覆蓋回去）。
    -- ★ 2026-07-16 例外：已檢驗完成(QC_check有值)但尚未按「確認完成」的 P 狀態資料一律顯示，
    --   不受「目前製程」限制——否則廠內製程(outsource_date為NULL)允收後會從待驗清單消失，
    --   永遠沒人能補按完工（完工紀錄又只列 qc_completed=1，兩邊都看不到）。
    AND (
        (bi.processing_state = 'P' AND bi.QC_check IS NOT NULL)
        OR DATE(bi.outsource_date) = (
            SELECT MAX(DATE(cur.outsource_date))
            FROM bom_ing cur
            WHERE cur.bom = bi.bom
              AND cur.processing_state IN ('Q','P','ing','E')
              AND cur.outsource_date IS NOT NULL
              AND cur.is_schedule_split = 0
        )
    )
    $extraWhere
ORDER BY bi.outsource_date
LIMIT $perPage OFFSET $offset
";

try {
    $stmt = $db->prepare($sql);
    foreach ($extraBinds as $i => $v) {
        $stmt->bindValue($i + 1, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRecords = (int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();

    $presentPTI = [];
    if ($page === 1 && $filterPTI === '' && ($filterQC === '' || $filterQC === 'all') && $filterSearch === '') {
        // 與 master_data_management.php 相同的 UNION 邏輯：包含主類型及額外對應的製程大類
        $ptiStmt = $db->query("
            SELECT DISTINCT pt_id FROM (
                SELECT pn.process_type_id AS pt_id
                FROM bom_ing bi
                JOIN bom b ON bi.bom = b.bom
                LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                WHERE b.processing_state IS NULL
                  AND bi.processing_state IN ('Q','P')
                  AND bi.qc_completed = 0
                  AND bi.is_consumed = 0
                  AND (bi.ps IS NULL OR bi.ps NOT LIKE '%(拆分工單)%')
                  AND pn.process_type_id IS NOT NULL
                  AND ((bi.processing_state = 'P' AND bi.QC_check IS NOT NULL)   -- ★ 2026-07-16 例外同步主查詢：已檢驗未完工一律顯示
                       OR DATE(bi.outsource_date) = (   -- ★ 2026-06-23 同步「目前製程」過濾，使篩選按鈕與清單一致
                        SELECT MAX(DATE(cur.outsource_date)) FROM bom_ing cur
                        WHERE cur.bom = bi.bom AND cur.processing_state IN ('Q','P','ing','E')
                          AND cur.outsource_date IS NOT NULL AND cur.is_schedule_split = 0))
                UNION
                SELECT pm.process_type_id AS pt_id
                FROM bom_ing bi
                JOIN bom b ON bi.bom = b.bom
                JOIN process_type_process_map pm ON pm.process_no_id = bi.process_no
                WHERE b.processing_state IS NULL
                  AND bi.processing_state IN ('Q','P')
                  AND bi.qc_completed = 0
                  AND bi.is_consumed = 0
                  AND (bi.ps IS NULL OR bi.ps NOT LIKE '%(拆分工單)%')
                  AND ((bi.processing_state = 'P' AND bi.QC_check IS NOT NULL)   -- ★ 2026-07-16 例外同步主查詢：已檢驗未完工一律顯示
                       OR DATE(bi.outsource_date) = (   -- ★ 2026-06-23 同步「目前製程」過濾，使篩選按鈕與清單一致
                        SELECT MAX(DATE(cur.outsource_date)) FROM bom_ing cur
                        WHERE cur.bom = bi.bom AND cur.processing_state IN ('Q','P','ing','E')
                          AND cur.outsource_date IS NOT NULL AND cur.is_schedule_split = 0))
            ) t
            WHERE pt_id IS NOT NULL
        ");
        $presentPTI = $ptiStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $fids  = array_values(array_unique(array_filter(array_column($rows, 'bom_ing_fid'))));
    $qcMap = [];
    if (!empty($fids)) {
        $ph  = implode(',', array_fill(0, count($fids), '?'));
        $qcS = $db->prepare("
            SELECT qc_check_id, bom_ing_fid_ref, QC_check,
                   QC_QQ_sqty, QC_ok_sqty, QC_ps, QC_ps_ok,
                   IF(YEAR(QC_check_date) > 0, DATE_FORMAT(QC_check_date,'%m/%d'), NULL) AS QC_check_date_formatted
            FROM qc_check
            WHERE bom_ing_fid_ref IN ($ph) AND QC_check IN ('ok','QQ')
            ORDER BY bom_ing_fid_ref, QC_check_date DESC, qc_check_id DESC
        ");
        $qcS->execute($fids);
        foreach ($qcS->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $qcMap[$e['bom_ing_fid_ref']][] = $e;
        }
    }

    foreach ($rows as &$row) {
        $row['individual_qc_entries'] = $qcMap[$row['bom_ing_fid']] ?? [];
    }
    unset($row);

    if (ob_get_level()) ob_end_clean();
    echo json_encode([
        'success'      => true,
        'data'         => $rows,
        'totalRecords' => $totalRecords,
        'page'         => $page,
        'perPage'      => $perPage,
        'totalPages'   => $totalRecords > 0 ? (int)ceil($totalRecords / $perPage) : 1,
        'presentPTI'   => $presentPTI,
    ]);

} catch (PDOException $e) {
    error_log('[_fetch_qc_data2] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}