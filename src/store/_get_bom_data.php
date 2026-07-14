<?php
/**
 * _get_bom_data.php
 * 根據 BOM 查詢資料，極速版：移除 correlated subquery，改用 JOIN
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => '使用者未登入。']);
    exit;
}

include_once __DIR__ . '/../common/DBConnection.php';
include_once __DIR__ . '/../common/_config.php';

$rawBom = isset($_POST['bom']) ? trim($_POST['bom']) : '';

if (!preg_match('/^B-\d{10}$/', $rawBom)) {
    echo json_encode(['success' => false, 'message' => 'BOM 格式錯誤，應為 B- 後接10位數字']);
    exit;
}

try {
    // ── 1. 查 BOM 基本資料（含結案判斷）──────────────────────
    $stmtBase = $db->prepare("
        SELECT Client_Name, d_id, sqty, processing_state
        FROM bom
        WHERE bom = ? AND d_id <> ''
        LIMIT 1
    ");
    $stmtBase->execute([$rawBom]);
    $base = $stmtBase->fetch(PDO::FETCH_ASSOC);

    if (!$base) {
        echo json_encode(['success' => false, 'message' => '查無此 BOM，請確認單號是否正確']);
        exit;
    }

    // 結案判斷：processing_state = 1 表示已結案
    if ((string)$base['processing_state'] === '1') {
        echo json_encode(['success' => false, 'message' => 'BOM 已結案，請轉生管取消結案後即可報工']);
        exit;
    }

    // ── 2. 查製程列表（極速版，移除 correlated subquery）────
    $sqlProc = "
        SELECT
            b.processing_state  AS b_processing_state,
            b.bom,
            b.d_id,
            b.sqty              AS bom_total_qty,
            DATE_FORMAT(bi.outsource_date,'%m/%d') AS outsource_date,
            pn.process_type_id,
            bi.processing_state,
            DATE_FORMAT(bi.return_date,'%m/%d') AS return_date,
            bi.QC_check,
            DATE_FORMAT(bi.QC_check_date,'%m/%d') AS QC_check_date,
            bi.QC_ps    AS BIQC_ps,
            bi.QC_ps2   AS BIQC_ps2,
            bi.QC_ps2   AS QC_ps_ng,
            bi.QC_ps_aod AS QC_ps_aod_remark,
            bi.single_bet_ps,
            bi.process_no,
            bi.bom_sn,
            pn.ProcessNo,
            pn.ProcessName,
            bi.maker_id,
            bi.sqty,
            b.Client_Name,
            -- QC 彙總
            COALESCE(qc.QC_QQ_sqty, 0)  AS QC_QQ_sqty,
            COALESCE(qc.QC_ng_sqty, 0)  AS QC_ng_sqty,
            COALESCE(qc.QC_aod_sqty, 0) AS QC_aod_sqty,
            COALESCE(qc.QC_ok_sqty, 0)  AS QC_ok_sqty,
            qc.QC_ps_qq,
            qc.all_QC_ps_ok             AS QC_ps_ok,
            qc.max_qc_check_id,
            -- 最新日期（JOIN 版，無 correlated subquery）
            qq_date.latest_QQ_date_formatted,
            ok_date.latest_ok_date_formatted,
            bi.bom_ing_fid,
            bi.ps,
            -- 異常單
            qao.abnormal_order_no,
            qao.id AS qa_abnormal_id
        FROM bom_ing bi
        JOIN bom b ON bi.bom = b.bom
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        -- QC 彙總
        LEFT JOIN (
            SELECT
                bom_ing_fid_ref,
                MAX(CASE WHEN QC_check='QQ' THEN QC_ps ELSE NULL END) AS QC_ps_qq,
                GROUP_CONCAT(DISTINCT CASE WHEN QC_check='ok' THEN QC_ps_ok END SEPARATOR '; ') AS all_QC_ps_ok,
                SUM(CASE WHEN QC_check='QQ'  THEN QC_QQ_sqty  ELSE 0 END) AS QC_QQ_sqty,
                SUM(CASE WHEN QC_check='ng'  THEN QC_ng_sqty   ELSE 0 END) AS QC_ng_sqty,
                SUM(CASE WHEN QC_check='AOD' THEN QC_aod_sqty  ELSE 0 END) AS QC_aod_sqty,
                SUM(CASE WHEN QC_check='ok'  THEN QC_ok_sqty   ELSE 0 END) AS QC_ok_sqty,
                MAX(qc_check_id) AS max_qc_check_id
            FROM qc_check
            GROUP BY bom_ing_fid_ref
        ) qc ON qc.bom_ing_fid_ref = bi.bom_ing_fid
        -- 最新 QQ 日期
        LEFT JOIN (
            SELECT bom_ing_fid_ref,
                   DATE_FORMAT(MAX(QC_check_date),'%m/%d') AS latest_QQ_date_formatted
            FROM qc_check
            WHERE QC_check='QQ' AND QC_check_date IS NOT NULL
            GROUP BY bom_ing_fid_ref
        ) qq_date ON qq_date.bom_ing_fid_ref = bi.bom_ing_fid
        -- 最新 OK 日期
        LEFT JOIN (
            SELECT bom_ing_fid_ref,
                   DATE_FORMAT(MAX(QC_check_date),'%m/%d') AS latest_ok_date_formatted
            FROM qc_check
            WHERE QC_check='ok' AND QC_check_date IS NOT NULL
            GROUP BY bom_ing_fid_ref
        ) ok_date ON ok_date.bom_ing_fid_ref = bi.bom_ing_fid
        -- 異常單
        LEFT JOIN qa_abnormal_order qao ON qao.source_type='QC' AND qao.source_id=qc.max_qc_check_id
        WHERE bi.bom = ?
        ORDER BY bi.bom_sn ASC
    ";

    $stmtProc = $db->prepare($sqlProc);
    $stmtProc->execute([$rawBom]);
    $rows = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode(['success' => false, 'message' => '此 BOM 無製程資料']);
        exit;
    }

    // ── 3. 批次查 individual_qc_entries ──────────────────────
    $fids = array_values(array_unique(array_column($rows, 'bom_ing_fid')));
    $qcMap = [];
    if (!empty($fids)) {
        $ph   = implode(',', array_fill(0, count($fids), '?'));
        $stmt = $db->prepare("
            SELECT qc_check_id, bom_ing_fid_ref, QC_check,
                   QC_QQ_sqty, QC_ok_sqty, QC_ps, QC_ps_ok,
                   DATE_FORMAT(QC_check_date,'%m/%d') AS QC_check_date_formatted
            FROM qc_check
            WHERE bom_ing_fid_ref IN ($ph) AND QC_check IN ('ok','QQ')
            ORDER BY bom_ing_fid_ref, QC_check_date DESC, qc_check_id DESC
        ");
        $stmt->execute($fids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $qcMap[$e['bom_ing_fid_ref']][] = $e;
        }
    }

    // ── 4. 組合回傳 ──────────────────────────────────────────
    $first = $rows[0];
    $processes = [];
    foreach ($rows as $r) {
        $r['individual_qc_entries'] = $qcMap[$r['bom_ing_fid']] ?? [];
        $processes[] = $r;
    }

    echo json_encode([
        'success'     => true,
        'Client_Name' => mb_substr(str_replace(' ', '', $first['Client_Name']), 0, 3),
        'd_id'        => $first['d_id'],
        'Qty'         => (int)$first['bom_total_qty'],
        'ps'          => $first['ps'] ?? '',
        'processes'   => $processes,
    ]);

} catch (PDOException $e) {
    error_log('[_get_bom_data] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()]);
}