<?php
/**
 * 線上檢驗「建議開立異常單」（2026-11-03）
 *
 * 情境：檢驗判定 NG 本來就會自動（或由品管按鈕）開立品質異常單，但異常單之後可能被刪除，
 * 刪除時會把 qc_check_form.abnormal_order_id／ncr_decision 一併清空——結果檢驗紀錄還是 NG，
 * 畫面上卻看不出「這筆曾經開過單、現在沒有了」。這支找出「NG 需要開單、卻沒有任何有效異常單連著」
 * 的檢驗紀錄，供品管勾選後一次補開。
 *
 * 規則（全部在這裡，前端只顯示）：
 *  - 只看已送出(SUBMITTED)且判定 NG 的檢驗紀錄；
 *  - 已選「不開單」(ncr_decision=SKIP) 的舊紀錄不列入（那是品管當時明確的決定）；
 *  - 沒有任何未刪除的異常單指到它（abnormal_order_id 或 src_qc_form_id）；
 *  - 多面分段首件：只有「NG 項目選了開異常單(ABNORMAL)」才算需要開單，選重工的不列；
 *  - 沒連到製令的（開不出單）不列。
 * 補開的日期＝補開當天（DB 的 CURDATE，不用 PHP date()——站上 PHP 是 UTC，凌晨會差一天），不是檢驗日。
 */
require_once __DIR__ . '/qa_abnormal_lib.php';

if (!function_exists('qab_qc_suggest_list')) {
    function qab_qc_suggest_list(PDO $db, int $days = 90, int $limit = 200): array
    {
        $days = max(1, min(730, $days));
        $limit = max(1, min(500, $limit));
        $sql = "SELECT f.qc_form_id, f.insp_no, f.bom_ing_fid, f.insp_kind, f.ng_qty, f.incoming_qty, f.sample_qty,
                       f.process_name, f.face_code, f.piece_no, f.created_by,
                       COALESCE(f.check_date, DATE(f.created_at)) AS check_date,
                       COALESCE(NULLIF(bi.bom,''), f.ship_bom) AS bom, d.D_Setting_Id AS part_no,
                       u.user_cname AS inspector
                FROM qc_check_form f
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid = f.bom_ing_fid
                LEFT JOIN d_setting d ON d.d_id = f.d_id
                LEFT JOIN user u ON u.id = f.created_by
                WHERE f.check_result = 'NG' AND f.status = 'SUBMITTED'
                  AND (f.ncr_decision IS NULL OR f.ncr_decision <> 'SKIP')
                  AND COALESCE(f.check_date, DATE(f.created_at)) >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
                  AND COALESCE(NULLIF(bi.bom,''), f.ship_bom) IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM qa_abnormal_order o
                                  WHERE o.deleted_at IS NULL AND (o.id = f.abnormal_order_id OR o.src_qc_form_id = f.qc_form_id))
                  AND (f.face_code IS NULL OR f.face_code = ''
                       OR EXISTS (SELECT 1 FROM qc_form_item_ng g WHERE g.qc_form_id = f.qc_form_id AND g.ng_action = 'ABNORMAL'))
                ORDER BY COALESCE(f.check_date, DATE(f.created_at)) DESC, f.qc_form_id DESC
                LIMIT $limit";
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('qab_qc_suggest_measures')) {
    /** 由已存檔的量測讀值組出異常單「量測尺寸與實測值」（只帶判定 NG 的項目；多面首件只帶選了開異常單的）。 */
    function qab_qc_suggest_measures(PDO $db, int $qcFormId): array
    {
        $st = $db->prepare("SELECT m.item_id, m.sample_no, m.measured_value, m.result, m.item_verdict, m.reading_seq,
                                   i.item_name, i.standard_text, i.min_value, i.max_value, i.plus_tolerance, i.minus_tolerance,
                                   i.result_type, i.sort_order, f.face_code
                            FROM qc_measurement m
                            JOIN qc_inspection_item i ON i.item_id = m.item_id
                            JOIN qc_check_form f ON f.qc_form_id = m.qc_form_id
                            WHERE m.qc_form_id = ?
                            ORDER BY i.sort_order, m.item_id, m.reading_seq, m.sample_no");
        $st->execute([$qcFormId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [[], []];

        $faceNg = [];
        if (!empty($rows[0]['face_code'])) {
            $g = $db->prepare("SELECT item_id FROM qc_form_item_ng WHERE qc_form_id=? AND ng_action='ABNORMAL'");
            $g->execute([$qcFormId]);
            $faceNg = array_flip(array_map('intval', $g->fetchAll(PDO::FETCH_COLUMN)));
        }
        $order = [];                       // item_id => 在這張檢驗單上的順序（編號 A,B,C…）
        foreach ($rows as $r) if (!isset($order[(int)$r['item_id']])) $order[(int)$r['item_id']] = count($order);
        $letter = function (int $i): string { $s = ''; $n = $i + 1; while ($n > 0) { $r = ($n - 1) % 26; $s = chr(65 + $r) . $s; $n = intdiv($n - 1, 26); } return $s; };

        $byItem = [];
        foreach ($rows as $r) {
            $iid = (int)$r['item_id'];
            $byItem[$iid]['info'] = $r;
            $byItem[$iid]['ng'] = ($byItem[$iid]['ng'] ?? false) || $r['item_verdict'] === 'NG' || $r['result'] === 'NG';
            $byItem[$iid]['reads'][(int)$r['reading_seq']][(int)$r['sample_no']] = (string)$r['measured_value'];
        }
        $measures = []; $sel = [];
        foreach ($byItem as $iid => $b) {
            if (!$b['ng']) continue;
            if ($faceNg && !isset($faceNg[$iid])) continue;
            $r = $b['info'];
            $isRange = $r['min_value'] !== null && $r['max_value'] !== null && is_numeric($r['min_value']) && is_numeric($r['max_value']);
            $spec = ['mode' => $isRange ? 'RANGE' : 'TOL', 'type' => $r['result_type'] === 'OKNG' ? 'OKNG' : 'NUM',
                     'std' => (string)$r['standard_text'], 'up' => (string)($r['plus_tolerance'] ?? ''), 'lo' => (string)($r['minus_tolerance'] ?? ''),
                     'min' => (string)($r['min_value'] ?? ''), 'max' => (string)($r['max_value'] ?? '')];
            ksort($b['reads']);
            $k = 0;
            foreach ($b['reads'] as $samples) {
                ksort($samples);
                $vals = array_slice(array_values($samples), 0, 12);
                $name = (string)$r['item_name'] . ($k > 0 ? '（加量測' . $k . '）' : '');
                $measures[] = ['code' => $letter($order[$iid]), 'dim_name' => $name, 'vals' => $vals, 'spec' => $spec];
                $k++;
            }
            $sel[] = $order[$iid];
        }
        return [$measures, $sel];
    }
}

if (!function_exists('qab_qc_suggest_open')) {
    /**
     * 補開一筆。回傳 ['ok'=>bool, 'no'=>異常單號, 'id'=>, 'message'=>失敗原因]。
     * 重複保護：已有有效異常單連著就直接回既有的（qab_auto_open_from_qc_ng 內也擋一次）。
     */
    function qab_qc_suggest_open(PDO $db, int $qcFormId, int $uid): array
    {
        try {
            [$measures, $sel] = qab_qc_suggest_measures($db, $qcFormId);
            if (!$measures) return ['ok' => false, 'message' => '找不到判定 NG 的量測項目'];
            $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
            $r = qab_auto_open_from_qc_ng($db, $qcFormId, $uid, $measures, $sel, null, null, $today);
            $db->prepare("UPDATE qc_check_form SET ncr_decision='OPEN', abnormal_order_id=? WHERE qc_form_id=?")
               ->execute([(int)$r['id'], $qcFormId]);
            return ['ok' => true, 'id' => (int)$r['id'], 'no' => (string)$r['no'], 'existed' => !empty($r['existed'])];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
