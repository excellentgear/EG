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
        $proc = []; $ord = []; $o = 0; $stations = []; $labOf = []; $prows = $st->fetchAll(PDO::FETCH_ASSOC);
        $alias = baf_alias_map($db, $bom); // 管理員指定「這個 SN 算哪一關」（ERP 拆單、或非整十的額外 SN）
        $realSet = []; foreach ($prows as $p) $realSet[(int)$p['bom_sn']] = true;
        $isAl = fn(int $k) => isset($alias[$k]) && isset($realSet[$alias[$k]]) && $alias[$k] !== $k;
        foreach ($prows as $p) {
            $sn = (int)$p['bom_sn'];
            if ($isAl($sn)) continue; // 併入別關的 SN 不單獨佔一個關卡編號
            if (!isset($ord[$sn])) { $ord[$sn] = ++$o; $labOf[$sn] = '第' . $o . '關 ' . (trim((string)$p['ProcessName']) !== '' ? $p['ProcessName'] : $p['process_no']); }
        }
        foreach ($prows as $p) {
            $raw = (int)$p['bom_sn'];
            $sn = (isset($alias[$raw]) && isset($ord[$alias[$raw]]) && $alias[$raw] !== $raw) ? $alias[$raw] : $raw;
            $lab = $labOf[$sn] . ($sn !== $raw ? '（SN ' . $raw . '）' : '');
            $proc[(int)$p['bom_ing_fid']] = ['sn' => $sn, 'raw' => $raw, 'label' => $lab];
            $stations[] = ['fid' => (int)$p['bom_ing_fid'], 'sn' => $sn, 'raw_sn' => $raw, 'label' => $lab, 'sqty' => (float)$p['sqty']];
        }
        if (!$proc) return ['rows' => [], 'stations' => [], 'batches' => [], 'warn' => [], 'bom_sqty' => 0];
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
        foreach ($proc as $f => $p) if ($p['raw'] === $p['sn'] && !isset($fidBySn[$p['sn']])) $fidBySn[$p['sn']] = $f;
        foreach ($proc as $f => $p) if (!isset($fidBySn[$p['sn']])) $fidBySn[$p['sn']] = $f;
        $pnq = $db->prepare("SELECT bi.bom_sn, MAX(pn.ProcessName) pname FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no WHERE bi.bom=? GROUP BY bi.bom_sn");
        $pnq->execute([$bom]);
        $pnBySn = [];
        foreach ($pnq->fetchAll(PDO::FETCH_ASSOC) as $x) $pnBySn[(int)$x['bom_sn']] = (string)$x['pname'];
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $rawSn = (int)$t['bom_sn'];
            $sn = (isset($alias[$rawSn]) && isset($fidBySn[$alias[$rawSn]]) && $alias[$rawSn] !== $rawSn) ? $alias[$rawSn] : $rawSn;
            $f = $fidBySn[$sn] ?? 0;
            $d = !empty($t['transfer_date']) ? substr($t['transfer_date'], 0, 10) : substr((string)$t['created_at'], 0, 10);
            $rows[] = ['kind' => 'transfer', 'id' => (int)$t['transfer_id'], 'fid' => $f, 'sn' => $sn, 'raw_sn' => $rawSn, 'aliased' => $rawSn !== $sn, 'orphan' => !$f, 'station' => $f ? ($labOf[$sn] ?? $proc[$f]['label']) . ($rawSn !== $sn ? '（SN ' . $rawSn . '）' : '') : ('SN ' . $rawSn . '（未對應關卡）'), 'date' => $d,
                       'ts' => $d . ' 12:00:00', 'no' => (string)($t['transfer_no'] ?? ''), 'from_no' => (string)($t['maker_from'] ?? ''), 'from' => $mk($t['maker_from']) ?: (string)$t['maker_from'],
                       'to' => $mk($t['maker_to']) ?: (string)$t['maker_to'], 'to_no' => (string)($t['maker_to'] ?? ''), 'proc' => (string)($pnBySn[$rawSn] ?? ''), 'qty' => (int)($t['transfer_qty'] ?: $t['sqty'])];
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
        // ERP匯入／未採用：bom_ing 內「已被拆批併走(is_consumed=1)」或「標記跳過(skip)」的紀錄——製程列表不顯示它們，但資料還在
        $iq = $db->prepare("SELECT bi.*, pn.ProcessName FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no WHERE bi.bom=? AND (bi.is_consumed=1 OR bi.processing_state='skip') ORDER BY bi.bom_sn, bi.bom_ing_fid");
        $iq->execute([$bom]);
        foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $f = (int)$b['bom_ing_fid'];
            $d = substr((string)($b['outsource_date'] ?: ($b['return_date'] ?: ($b['Modified_At'] ?? ''))), 0, 10);
            if ($d === '') $d = '0000-00-00';
            $sn = (int)$b['bom_sn'];
            $rows[] = ['kind' => 'ing', 'id' => $f, 'fid' => $f, 'sn' => $sn, 'station' => '第' . ($ord[$sn] ?? ($ord[$alias[$sn] ?? 0] ?? '?')) . '關 ' . (trim((string)$b['ProcessName']) !== '' ? $b['ProcessName'] : $b['process_no']), 'date' => $d, 'ts' => $d . ' 00:00:01',
                       'maker' => (string)($b['maker_id'] ?? ''), 'qty' => (float)$b['sqty'], 'state' => (string)$b['processing_state'], 'batch_label' => (string)($b['batch_label'] ?? ''),
                       'reason' => (int)$b['is_consumed'] === 1 ? '已被拆批／合批併走（is_consumed）' : '標記為跳過（skip）',
                       'od' => !empty($b['outsource_date']) ? substr($b['outsource_date'], 0, 10) : '', 'rd' => !empty($b['return_date']) ? substr($b['return_date'], 0, 10) : ''];
        }
        // 同一天內：依關卡順序排，且某關的回廠／移轉／檢驗排在該關發單之後、下一關發單之前（sn+0.5）
        $ord = fn($r) => (float)($r['sn'] ?? 0) + (in_array($r['kind'], ['send', 'ing'], true) ? 0 : 0.5);
        usort($rows, fn($a, $b) => strcmp(substr($a['ts'], 0, 10), substr($b['ts'], 0, 10)) ?: ($ord($a) <=> $ord($b)) ?: strcmp($a['ts'], $b['ts']));
        $chk = baf_check($db, $bom);
        return ['rows' => $rows, 'stations' => $stations, 'batches' => $batches, 'warn' => $chk['items'], 'bom_sqty' => $chk['bom_sqty'], 'flow' => baf_flow($db, $bom, $rows), 'sn_map' => baf_sn_map($db, $bom, $labOf, $alias)];
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

if (!function_exists('baf_check')) {
    /** 各製程（同 bom_sn 合計）的 發單／移轉憑單／QC檢驗 總數，超過 BOM 發單數的列出來（管理員在修改視窗的發單數下方看到紅字）。 */
    function baf_check(PDO $db, string $bom): array {
        $q = $db->prepare("SELECT sqty FROM bom WHERE bom=? LIMIT 1");
        $q->execute([$bom]);
        $ref = (float)$q->fetchColumn();
        $st = $db->prepare("SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, pn.ProcessName FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no WHERE bi.bom=? AND bi.is_consumed=0 ORDER BY bi.bom_sn, bi.bom_ing_fid");
        $st->execute([$bom]);
        $sn = []; $ord = []; $o = 0; $fidSn = []; $labOf = []; $crow = $st->fetchAll(PDO::FETCH_ASSOC);
        $alias0 = baf_alias_map($db, $bom);
        $realSet0 = []; foreach ($crow as $p) $realSet0[(int)$p['bom_sn']] = true;
        foreach ($crow as $p) {
            $k = (int)$p['bom_sn'];
            if (isset($alias0[$k]) && isset($realSet0[$alias0[$k]]) && $alias0[$k] !== $k) continue;
            if (!isset($ord[$k])) { $ord[$k] = ++$o; $labOf[$k] = '第' . $o . '關 ' . (trim((string)$p['ProcessName']) !== '' ? $p['ProcessName'] : $p['process_no']); }
        }
        foreach ($crow as $p) {
            $k = (int)$p['bom_sn'];
            if (isset($alias0[$k]) && isset($ord[$alias0[$k]]) && $alias0[$k] !== $k) $k = $alias0[$k];
            if (!isset($sn[$k])) $sn[$k] = ['label' => $labOf[$k], 'send' => 0.0, 'transfer' => 0.0, 'qc' => 0.0];
            $fidSn[(int)$p['bom_ing_fid']] = $k;
        }
        if (!$fidSn || $ref <= 0) return ['bom_sqty' => $ref, 'items' => []];
        $in = implode(',', array_keys($fidSn));
        foreach ($db->query("SELECT bom_ing_fid, SUM(send_qty - COALESCE(withdrawn_qty,0)) s FROM bom_ing_outsource_batch WHERE bom_ing_fid IN ($in) GROUP BY bom_ing_fid")->fetchAll(PDO::FETCH_ASSOC) as $r) $sn[$fidSn[(int)$r['bom_ing_fid']]]['send'] += (float)$r['s'];
        foreach ($db->query("SELECT bom_ing_fid_ref f, SUM(COALESCE(QC_ok_sqty,0)+COALESCE(QC_aod_sqty,0)+COALESCE(QC_QQ_sqty,0)+COALESCE(QC_ng_sqty,0)) s FROM qc_check WHERE bom_ing_fid_ref IN ($in) GROUP BY bom_ing_fid_ref")->fetchAll(PDO::FETCH_ASSOC) as $r) $sn[$fidSn[(int)$r['f']]]['qc'] += (float)$r['s'];
        $tq = $db->prepare("SELECT bom_sn, SUM(transfer_qty) s FROM bom_ing_transfer_log WHERE bom=? GROUP BY bom_sn");
        $tq->execute([$bom]);
        $alias = baf_alias_map($db, $bom);
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = (int)$r['bom_sn']; if (!isset($sn[$k]) && isset($alias[$k])) $k = $alias[$k]; if (isset($sn[$k])) $sn[$k]['transfer'] += (float)$r['s']; }
        $names = ['send' => '發單', 'transfer' => '移轉憑單', 'qc' => 'QC檢驗'];
        $items = [];
        foreach ($sn as $k => $v) {
            $mx = 'send';
            foreach (['transfer', 'qc'] as $kk) if ($v[$kk] > $v[$mx]) $mx = $kk;
            if ($v[$mx] > $ref + 1e-9) $items[] = ['label' => $v['label'], 'send' => $v['send'], 'transfer' => $v['transfer'], 'qc' => $v['qc'], 'max_name' => $names[$mx], 'max' => $v[$mx]];
        }
        return ['bom_sqty' => $ref, 'items' => $items];
    }
}

if (!function_exists('baf_alias_map')) {
    /** ERP 因為要發給不同廠商而拆出來的額外 SN，管理員指定它算「哪一關」：[alias_sn => target_sn]。表不存在時視為沒有設定。 */
    function baf_alias_map(PDO $db, string $bom): array {
        try {
            $q = $db->prepare("SELECT alias_sn, target_sn FROM bom_sn_alias WHERE bom=?");
            $q->execute([$bom]);
            $m = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(int)$r['alias_sn']] = (int)$r['target_sn'];
            return $m;
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('baf_alias_set')) {
    /** 設定／取消（target=0）某個 SN 對應到哪一關。SN 紀錄本身不改，只是顯示與統計時併入該關。 */
    function baf_alias_set(PDO $db, string $bom, int $aliasSn, int $targetSn, int $uid): void {
        if ($aliasSn <= 0) throw new Exception('SN 不正確');
        $q = $db->prepare("SELECT DISTINCT bom_sn FROM bom_ing WHERE bom=?");
        $q->execute([$bom]);
        $real = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        if (in_array($aliasSn, $real, true) && $aliasSn % 10 === 0) throw new Exception('SN ' . $aliasSn . ' 是整十的正式製程，不能併入其他關卡（只有非整十的 SN 可以設成同一關）');
        if ($targetSn > 0 && !in_array($targetSn, $real, true)) throw new Exception('指定的關卡不屬於這個 BOM');
        if ($targetSn > 0 && $targetSn === $aliasSn) throw new Exception('不能對應到自己');
        if ($targetSn > 0) { $am = baf_alias_map($db, $bom); if (isset($am[$targetSn])) throw new Exception('SN ' . $targetSn . ' 本身已設為算別的關卡，請直接選它最後對應的那一關'); }
        $db->prepare("CREATE TABLE IF NOT EXISTS bom_sn_alias (id INT AUTO_INCREMENT PRIMARY KEY, bom VARCHAR(30) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL, alias_sn INT NOT NULL, target_sn INT NOT NULL, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_bom_alias (bom, alias_sn), KEY idx_target (bom, target_sn)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")->execute();
        if ($targetSn <= 0) {
            $db->prepare("DELETE FROM bom_sn_alias WHERE bom=? AND alias_sn=?")->execute([$bom, $aliasSn]);
        } else {
            $db->prepare("INSERT INTO bom_sn_alias (bom, alias_sn, target_sn, created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE target_sn=VALUES(target_sn), created_by=VALUES(created_by)")->execute([$bom, $aliasSn, $targetSn, $uid]);
        }
        $f = $db->prepare("SELECT bom_ing_fid FROM bom_ing WHERE bom=? AND bom_sn=? ORDER BY bom_ing_fid LIMIT 1");
        $f->execute([$bom, $targetSn > 0 ? $targetSn : $real[0]]);
        $fid = (int)$f->fetchColumn();
        if ($fid) ber_log($db, $fid, 'sn_alias', '管理員設定 SN ' . $aliasSn . ($targetSn > 0 ? ' 對應到 SN ' . $targetSn . ' 那一關' : ' 取消對應'), $uid);
    }
}

if (!function_exists('baf_flow')) {
    /** 管理員手動指定的流向：[transfer_id => ['src'=>null|0|batch_id,'dst'=>null|0|batch_id]]（null＝沒指定，用推測）。 */
    function baf_flow_manual(PDO $db, string $bom): array {
        try {
            $q = $db->prepare("SELECT transfer_id, src_batch_id, dst_batch_id FROM bom_flow_link WHERE bom=?");
            $q->execute([$bom]);
            $m = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(int)$r['transfer_id']] = ['src' => $r['src_batch_id'] === null ? null : (int)$r['src_batch_id'], 'dst' => $r['dst_batch_id'] === null ? null : (int)$r['dst_batch_id']];
            return $m;
        } catch (Throwable $e) { return []; }
    }

    /**
     * 推測每張移轉憑單：①src＝它是從本關哪一個發單批次出來的（同關、同轉出廠商，依日期把移轉量依序填進各批次的發單量）
     * ②dst＝它接到下一個「發給轉入廠商」的哪個發單批次（同樣依日期依序填）。管理員手動指定的優先，且不參與推測的累計。
     * 回傳 [transfer_id => ['src'=>id|0,'src_auto'=>bool,'dst'=>id|0,'dst_auto'=>bool]]，0＝找不到。
     */
    function baf_flow(PDO $db, string $bom, array $rows): array {
        $man = baf_flow_manual($db, $bom);
        $sends = []; $tfs = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'send') $sends[] = $r;
            elseif ($r['kind'] === 'transfer' && $r['fid']) $tfs[] = $r;
        }
        usort($sends, fn($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['id'] <=> $b['id']));
        usort($tfs, fn($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['id'] <=> $b['id']));
        // 修正舊版拖放造成的錯誤指定：「接續發單」指到同一關或更前面的批次是不可能的，改視為「來源發單」
        $sendById = [];
        foreach ($sends as $b) $sendById[$b['id']] = $b;
        foreach ($tfs as $t) {
            $m = $man[$t['id']] ?? null;
            if ($m && $m['dst'] && isset($sendById[$m['dst']]) && $sendById[$m['dst']]['sn'] <= $t['sn']) {
                if (!$m['src']) $man[$t['id']]['src'] = $m['dst'];
                $man[$t['id']]['dst'] = null;
            }
        }
        $out = [];
        foreach ($tfs as $t) $out[$t['id']] = ['src' => 0, 'src_auto' => true, 'dst' => 0, 'dst_auto' => true];
        $fill = function (string $field, callable $groupOf) use (&$out, $man, $tfs) {
            $used = []; // 每個發單批次已被分配掉多少：先把管理員手動指定的扣掉，再讓推測的依日期填「還有空」的批次
            foreach ($tfs as $t) {
                $id = $t['id'];
                if (isset($man[$id]) && $man[$id][$field] !== null) {
                    $out[$id][$field] = $man[$id][$field]; $out[$id][$field . '_auto'] = false;
                    if ($man[$id][$field] > 0) $used[$man[$id][$field]] = ($used[$man[$id][$field]] ?? 0.0) + (float)$t['qty'];
                }
            }
            foreach ($tfs as $t) {
                $id = $t['id'];
                if (isset($man[$id]) && $man[$id][$field] !== null) continue;
                $g = $groupOf($t);
                if (!$g) continue;
                [$key, $pool] = $g;
                $pick = 0;
                // 接續發單的日期不可能早於移轉憑單日期：先找「日期不早於移轉日、還有空」的批次，沒有才放寬
                if ($field === 'dst') {
                    foreach ($pool as $b) {
                        if (strcmp($b['date'], $t['date']) >= 0 && (float)$b['send_qty'] - ($used[$b['id']] ?? 0.0) > 1e-9) { $pick = $b['id']; break; }
                    }
                    if (!$pick) foreach ($pool as $b) {
                        if (strcmp($b['date'], $t['date']) >= 0) { $pick = $b['id']; break; }
                    }
                }
                if (!$pick) foreach ($pool as $b) {
                    if ((float)$b['send_qty'] - ($used[$b['id']] ?? 0.0) > 1e-9) { $pick = $b['id']; break; }
                }
                if (!$pick && $pool) $pick = end($pool)['id']; // 發單量已用完仍有移轉：掛在最後一批（總數異常會另外提示）
                $used[$pick] = ($used[$pick] ?? 0.0) + (float)$t['qty'];
                $out[$id][$field] = $pick;
            }
        };
        $fill('src', function ($t) use ($sends) {
            if ($t['from_no'] === '') return null;
            $pool = array_values(array_filter($sends, fn($b) => $b['sn'] === $t['sn'] && (string)$b['maker_no'] === (string)$t['from_no']));
            return $pool ? [$t['sn'] . '|' . $t['from_no'], $pool] : null;
        });
        $fill('dst', function ($t) use ($sends) {
            if ($t['to_no'] === '') return null;
            $best = null;
            foreach ($sends as $b) {
                if ((string)$b['maker_no'] !== (string)$t['to_no'] || $b['sn'] <= $t['sn']) continue;
                if ($best === null || $b['sn'] < $best) $best = $b['sn'];
            }
            if ($best === null) {
                // 轉入廠商的批次就在同一關（例：SN20／SN25 已認定同一關）：貨是進到下一關，改接下一關最近的發單批次
                foreach ($sends as $b) { if ($b['sn'] > $t['sn'] && ($best === null || $b['sn'] < $best)) $best = $b['sn']; }
                if ($best === null) return null;
                $pool = array_values(array_filter($sends, fn($b) => $b['sn'] === $best));
                return [$best . '|*', $pool];
            }
            $pool = array_values(array_filter($sends, fn($b) => $b['sn'] === $best && (string)$b['maker_no'] === (string)$t['to_no']));
            return [$best . '|' . $t['to_no'], $pool];
        });
        return $out;
    }

    /** 設定某張移轉憑單的流向。$batchId：-1＝改回自動推測、0＝明確指定「無」、>0＝指定批次。 */
    function baf_flow_set(PDO $db, string $bom, int $transferId, string $field, int $batchId, int $uid): void {
        if (!in_array($field, ['src', 'dst'], true)) throw new Exception('欄位不正確');
        $q = $db->prepare("SELECT COUNT(*) FROM bom_ing_transfer_log WHERE transfer_id=? AND bom=?");
        $q->execute([$transferId, $bom]);
        if (!(int)$q->fetchColumn()) throw new Exception('這張移轉憑單不屬於這個 BOM');
        if ($batchId > 0) {
            $q = $db->prepare("SELECT COUNT(*) FROM bom_ing_outsource_batch b JOIN bom_ing bi ON bi.bom_ing_fid=b.bom_ing_fid WHERE b.batch_id=? AND bi.bom=?");
            $q->execute([$batchId, $bom]);
            if (!(int)$q->fetchColumn()) throw new Exception('指定的發單批次不屬於這個 BOM');
        }
        $db->prepare("CREATE TABLE IF NOT EXISTS bom_flow_link (id INT AUTO_INCREMENT PRIMARY KEY, bom VARCHAR(30) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL, transfer_id INT NOT NULL, src_batch_id INT NULL, dst_batch_id INT NULL, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_bom_tf (bom, transfer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")->execute();
        $col = $field === 'src' ? 'src_batch_id' : 'dst_batch_id';
        $val = $batchId < 0 ? null : $batchId;
        $db->prepare("INSERT INTO bom_flow_link (bom, transfer_id, $col, created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE $col=VALUES($col), created_by=VALUES(created_by)")->execute([$bom, $transferId, $val, $uid]);
        $db->prepare("DELETE FROM bom_flow_link WHERE bom=? AND transfer_id=? AND src_batch_id IS NULL AND dst_batch_id IS NULL")->execute([$bom, $transferId]);
        if ($field === 'dst' && $batchId > 0) baf_transfer_sync_to($db, $bom, $transferId, $batchId, $uid);
        $f = $db->prepare("SELECT bom_ing_fid FROM bom_ing WHERE bom=? ORDER BY bom_ing_fid LIMIT 1");
        $f->execute([$bom]);
        $fid = (int)$f->fetchColumn();
        if ($fid) ber_log($db, $fid, 'flow_link', '管理員調整移轉憑單 #' . $transferId . ' 的' . ($field === 'src' ? '來源發單' : '接續發單') . '：' . ($batchId < 0 ? '改回自動推測' : ($batchId === 0 ? '無' : '批次 ' . $batchId)), $uid);
    }
}

if (!function_exists('baf_sn_map')) {
    /** 面板上「SN 對應」區塊的資料：所有非整十的真實 SN ＋ 移轉憑單裡出現但這個 BOM 沒有的 SN；targets 是可選的關卡（真實 SN）。 */
    function baf_sn_map(PDO $db, string $bom, array $labOf, array $alias): array {
        $real = array_keys($labOf);
        $q = $db->prepare("SELECT DISTINCT bom_sn FROM bom_ing_transfer_log WHERE bom=?");
        $q->execute([$bom]);
        $tsn = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $items = [];
        foreach (array_unique(array_merge($real, $tsn)) as $sn) {
            $isReal = in_array($sn, $real, true);
            if ($isReal && $sn % 10 === 0) continue;
            $items[] = ['sn' => $sn, 'real' => $isReal, 'label' => $isReal ? $labOf[$sn] : 'SN ' . $sn . '（ERP 拆單，這個 BOM 沒有此製程）', 'target' => (int)($alias[$sn] ?? 0)];
        }
        usort($items, fn($a, $b) => $a['sn'] <=> $b['sn']);
        $targets = [];
        foreach ($real as $sn) $targets[] = ['sn' => $sn, 'label' => $labOf[$sn]];
        return ['items' => $items, 'targets' => $targets];
    }
}

if (!function_exists('baf_transfer_sync_to')) {
    /** 移轉憑單接到哪一批發單，「→」後面的廠商（maker_to）就以那批發單的廠商為準（寫進資料庫，標記為手動修改，留紀錄）。 */
    function baf_transfer_sync_to(PDO $db, string $bom, int $transferId, int $batchId, int $uid): bool {
        $q = $db->prepare("SELECT maker_id_no FROM bom_ing_outsource_batch WHERE batch_id=?");
        $q->execute([$batchId]);
        $mk = trim((string)$q->fetchColumn());
        if ($mk === '') return false;
        $c = $db->prepare("SELECT maker_to FROM bom_ing_transfer_log WHERE transfer_id=? AND bom=?");
        $c->execute([$transferId, $bom]);
        $cur = $c->fetchColumn();
        if ($cur === false || (string)$cur === $mk) return false;
        $db->prepare("UPDATE bom_ing_transfer_log SET maker_to=?, edit_manual=1, edit_by=?, edit_at=NOW() WHERE transfer_id=? AND bom=?")->execute([$mk, $uid, $transferId, $bom]);
        $f = $db->prepare("SELECT bom_ing_fid FROM bom_ing WHERE bom=? ORDER BY bom_ing_fid LIMIT 1");
        $f->execute([$bom]);
        $fid = (int)$f->fetchColumn();
        if ($fid) ber_log($db, $fid, 'transfer_edit', '管理員依流向修正移轉憑單#' . $transferId . '的轉入廠商：' . $cur . '→' . $mk, $uid);
        return true;
    }
}
