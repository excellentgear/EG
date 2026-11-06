<?php
// BOM 管理員修正面板（2026-11-06 使用者交辦）：把一個 BOM 的 發單／回廠／報廢／取回／QC檢驗／移轉憑單／異常單
// 攤成一份可篩選、可就地修改的清單。寫入一律呼叫 bom_return_edit_lib.php 的 ber_* 函式（force 模式）或本檔的
// baf_qc_save／baf_transfer_save；本檔只負責「列出」與「驗證這筆屬於這個 BOM」。
// 權限：本頁管理員（oready_resolve_is_admin）＋已輸入操作確認密碼（$_SESSION['oready_admin_fix']）。
require_once __DIR__ . '/bom_return_edit_lib.php';

if (!function_exists('baf_list')) {
    function baf_list(PDO $db, string $bom): array {
        eg_bom_outsource_ensure_schema($db);
        $st = $db->prepare("SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, pn.ProcessName, bi.sqty FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no WHERE bi.bom=? ORDER BY bi.bom_sn, bi.bom_ing_fid");
        $st->execute([$bom]);
        $proc = []; $ord = []; $o = 0; $stations = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $sn = (int)$p['bom_sn'];
            if (!isset($ord[$sn])) $ord[$sn] = ++$o;
            $lab = '第' . $ord[$sn] . '關 ' . (trim((string)$p['ProcessName']) !== '' ? $p['ProcessName'] : $p['process_no']);
            $proc[(int)$p['bom_ing_fid']] = ['sn' => $sn, 'label' => $lab];
            $stations[] = ['fid' => (int)$p['bom_ing_fid'], 'label' => $lab, 'sqty' => (float)$p['sqty']];
        }
        if (!$proc) return ['rows' => [], 'stations' => [], 'batches' => []];
        $in = implode(',', array_keys($proc));
        $rows = []; $batches = [];
        $mk = function ($no) use ($db) { static $c = []; if ($no === null || $no === '') return ''; if (!isset($c[$no])) { $q = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no=?"); $q->execute([$no]); $c[$no] = (string)$q->fetchColumn(); } return $c[$no]; };
        foreach ($db->query("SELECT * FROM bom_ing_outsource_batch WHERE bom_ing_fid IN ($in) ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $f = (int)$b['bom_ing_fid'];
            $mname = (string)($b['maker_id'] ?? '');
            $d = !empty($b['send_date']) ? substr($b['send_date'], 0, 10) : substr((string)$b['created_at'], 0, 10);
            $batches[] = ['batch_id' => (int)$b['batch_id'], 'fid' => $f, 'maker' => $mname, 'send_qty' => (float)$b['send_qty']];
            $rows[] = ['kind' => 'send', 'id' => (int)$b['batch_id'], 'fid' => $f, 'sn' => $proc[$f]['sn'], 'station' => $proc[$f]['label'], 'date' => $d,
                       'ts' => $d . ' 00:00:00', 'maker_no' => (string)($b['maker_id_no'] ?? ''), 'maker' => $mname, 'send_qty' => (float)$b['send_qty'],
                       'return_qty' => (float)$b['return_qty'], 'scrap_qty' => (float)$b['scrap_qty'], 'withdraw_qty' => (float)($b['withdrawn_qty'] ?? 0), 'status' => $b['status']];
        }
        $mkb = [];
        foreach ($batches as $b) $mkb[$b['batch_id']] = $b['maker'];
        foreach ($db->query("SELECT * FROM bom_ing_outsource_return WHERE bom_ing_fid IN ($in) ORDER BY return_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $f = (int)$r['bom_ing_fid'];
            $d = !empty($r['return_date']) ? substr($r['return_date'], 0, 10) : substr((string)$r['created_at'], 0, 10);
            $rows[] = ['kind' => 'return', 'id' => (int)$r['return_id'], 'fid' => $f, 'sn' => $proc[$f]['sn'], 'station' => $proc[$f]['label'], 'date' => $d,
                       'ts' => $d . ' 12:00:00', 'batch_id' => (int)$r['batch_id'], 'maker' => $mkb[(int)$r['batch_id']] ?? '',
                       'return_qty' => (float)$r['return_qty'], 'scrap_qty' => (float)$r['scrap_qty'], 'withdraw_qty' => (float)($r['withdraw_qty'] ?? 0), 'note' => (string)($r['note'] ?? '')];
        }
        foreach ($db->query("SELECT * FROM qc_check WHERE bom_ing_fid_ref IN ($in) ORDER BY qc_check_id")->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $f = (int)$q['bom_ing_fid_ref'];
            $d = substr((string)($q['QC_check_date'] ?: $q['created_at']), 0, 10);
            $rows[] = ['kind' => 'qc', 'id' => (int)$q['qc_check_id'], 'fid' => $f, 'sn' => $proc[$f]['sn'], 'station' => $proc[$f]['label'], 'date' => $d,
                       'ts' => substr((string)($q['QC_check_date'] ?: $q['created_at']), 0, 19), 'result' => (string)$q['QC_check'],
                       'ok' => (int)$q['QC_ok_sqty'], 'aod' => (int)$q['QC_aod_sqty'], 'qq' => (int)$q['QC_QQ_sqty'], 'ng' => (int)$q['QC_ng_sqty']];
        }
        $tq = $db->prepare("SELECT * FROM bom_ing_transfer_log WHERE bom=? ORDER BY transfer_id");
        $tq->execute([$bom]);
        $fidBySn = [];
        foreach ($proc as $f => $p) if (!isset($fidBySn[$p['sn']])) $fidBySn[$p['sn']] = $f;
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $sn = (int)$t['bom_sn']; $f = $fidBySn[$sn] ?? 0;
            $d = !empty($t['transfer_date']) ? substr($t['transfer_date'], 0, 10) : substr((string)$t['created_at'], 0, 10);
            $rows[] = ['kind' => 'transfer', 'id' => (int)$t['transfer_id'], 'fid' => $f, 'sn' => $sn, 'station' => $f ? $proc[$f]['label'] : ('SN ' . $sn), 'date' => $d,
                       'ts' => $d . ' 12:00:00', 'no' => (string)($t['transfer_no'] ?? ''), 'from' => $mk($t['maker_from']) ?: (string)$t['maker_from'],
                       'to' => $mk($t['maker_to']) ?: (string)$t['maker_to'], 'qty' => (int)($t['transfer_qty'] ?: $t['sqty'])];
        }
        try {
            $aq = $db->prepare("SELECT id, abnormal_order_no, abnormal_phenomenon, ng_qty, created_at, bom_process_fids FROM qa_abnormal_order WHERE bom_no=? AND deleted_at IS NULL ORDER BY id");
            $aq->execute([$bom]);
            foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $fids = array_filter(array_map('intval', preg_split('/[,\s]+/', (string)$a['bom_process_fids'])));
                $f = $fids ? reset($fids) : 0;
                $d = substr((string)$a['created_at'], 0, 10);
                $rows[] = ['kind' => 'abnormal', 'id' => (int)$a['id'], 'fid' => $f, 'sn' => $f && isset($proc[$f]) ? $proc[$f]['sn'] : 0, 'station' => $f && isset($proc[$f]) ? $proc[$f]['label'] : '', 'date' => $d,
                           'ts' => (string)$a['created_at'], 'no' => (string)$a['abnormal_order_no'], 'text' => mb_substr((string)$a['abnormal_phenomenon'], 0, 40), 'ng' => (float)$a['ng_qty']];
            }
        } catch (Throwable $e) { /* 異常單表缺漏不擋其他資料 */ }
        usort($rows, fn($a, $b) => strcmp($a['ts'], $b['ts']) ?: ($a['sn'] <=> $b['sn']));
        return ['rows' => $rows, 'stations' => $stations, 'batches' => $batches];
    }
}

if (!function_exists('baf_fid_of_bom')) {
    /** 驗證某個 fid 確實屬於這個 BOM（防止拿別的 BOM 的 id 亂改）。 */
    function baf_fid_ok(PDO $db, int $fid, string $bom): bool {
        $q = $db->prepare("SELECT COUNT(*) FROM bom_ing WHERE bom_ing_fid=? AND bom=?");
        $q->execute([$fid, $bom]);
        return (int)$q->fetchColumn() > 0;
    }
}

if (!function_exists('baf_qc_save')) {
    function baf_qc_save(PDO $db, int $fid, int $qcId, int $ok, int $aod, int $qq, int $ng, ?string $date, int $uid): void {
        ber_qc_save($db, $fid, $qcId, $ok, $aod, $qq, $ng, $uid);
        if ($date !== null && $date !== '') {
            $date = ber_check_past_date($date, '檢驗日');
            $db->prepare("UPDATE QC_check SET QC_check_date=CONCAT(?,' ',TIME(COALESCE(QC_check_date,NOW()))) WHERE qc_check_id=? AND bom_ing_fid_ref=?")->execute([$date, $qcId, (string)$fid]);
            ber_log($db, $fid, 'qc_edit', '管理員修正QC檢驗#' . $qcId . ' 檢驗日→' . $date, $uid);
        }
    }
}

if (!function_exists('baf_transfer_save')) {
    function baf_transfer_save(PDO $db, string $bom, int $tid, int $qty, ?string $date, int $uid): void {
        if ($qty <= 0) throw new Exception('移轉數量必須大於 0');
        $date = ber_check_past_date($date, '移轉日');
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT transfer_qty, transfer_date, bom_sn FROM bom_ing_transfer_log WHERE transfer_id=? AND bom=? FOR UPDATE");
            $st->execute([$tid, $bom]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o) throw new Exception('找不到這筆移轉憑單');
            $db->prepare("UPDATE bom_ing_transfer_log SET transfer_qty=?, transfer_date=COALESCE(?,transfer_date), edit_manual=1, edit_by=?, edit_at=NOW() WHERE transfer_id=?")->execute([$qty, $date, $uid, $tid]);
            $f = $db->prepare("SELECT bom_ing_fid FROM bom_ing WHERE bom=? AND bom_sn=? ORDER BY bom_ing_fid LIMIT 1");
            $f->execute([$bom, $o['bom_sn']]);
            $fid = (int)$f->fetchColumn();
            if ($fid) ber_log($db, $fid, 'transfer_edit', '管理員修正移轉憑單#' . $tid . '：數量 ' . $o['transfer_qty'] . '→' . $qty . '、日期 ' . $o['transfer_date'] . '→' . ($date ?? '不變'), $uid);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}
