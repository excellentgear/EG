<?php
/**
 * BOM 相關紀錄時間軸（唯一實作）——BOM總覽與「已完工BOM查詢列印」點料號欄時共用。
 * 彙整：發單（發去哪一間）／轉發／回廠／取回（撤回）／廠商報廢／品管檢驗／品管開異常單／
 *       移轉憑單／狀態事件／人工結案等，依時間由舊到新排列。純讀取，不寫任何資料。
 */
// 在函式外載入（該檔頂層程式碼若在函式內執行會污染區域變數）
try { require_once __DIR__ . '/qa_abnormal_lib.php'; } catch (Throwable $e0) { }

if (!function_exists('eg_bom_timeline')) {
    function eg_bom_timeline(PDO $db, string $bom): array {
        $bom = trim($bom);
        $ev = [];
        $add = function ($ts, $sn, $kind, $label, $text, $who = '', $tone = '') use (&$ev) {
            if (!$ts) return;
            $ev[] = ['ts' => (string)$ts, 'sn' => (int)$sn, 'kind' => $kind, 'label' => $label, 'text' => $text, 'who' => $who, 'tone' => $tone];
        };
        $n = function ($v) { $v = (float)$v; return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.'); };

        // 製程對照
        $st = $db->prepare("SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, pn.ProcessName, bi.processing_state, ml.maker_id AS mk_name
                              FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                              LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no WHERE bi.bom = ? ORDER BY bi.bom_sn");
        $st->execute([$bom]);
        $proc = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $proc[(int)$r['bom_ing_fid']] = $r;
        if (!$proc) return [];
        $in = implode(',', array_keys($proc));
        // 關卡＝依製程順序數第幾關（不是 bom_sn 的 10/20/30…）
        $ordOf = []; $o = 0;
        foreach ($proc as $p) { $sn0 = (int)$p['bom_sn']; if (!isset($ordOf[$sn0])) $ordOf[$sn0] = ++$o; }
        $pname = function ($fid) use ($proc, $ordOf) {
            if (!isset($proc[$fid])) return '';
            $p = $proc[$fid];
            return '第' . $ordOf[(int)$p['bom_sn']] . '關 ' . (trim((string)($p['ProcessName'] ?? '')) !== '' ? $p['ProcessName'] : ($p['process_no'] ?? ''));
        };
        $mkOf = function ($fid) use ($proc) { return isset($proc[$fid]) ? trim((string)($proc[$fid]['mk_name'] ?? '')) : ''; };
        $snOf = function ($fid) use ($proc) { return isset($proc[$fid]) ? (int)$proc[$fid]['bom_sn'] : 0; };

        // 發單批次（含轉發）
        $hasRet = [];
        foreach ($db->query("SELECT * FROM bom_ing_outsource_batch WHERE bom_ing_fid IN ($in) ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $fid = (int)$b['bom_ing_fid'];
            $ts = !empty($b['created_at']) ? $b['created_at'] : ($b['send_date'] ?? '');
            if (!empty($b['send_date'])) $ts = $b['send_date'] . ' ' . (!empty($b['created_at']) ? substr($b['created_at'], 11) : '00:00:00');
            $isFwd = strpos((string)($b['note'] ?? ''), '分批自批次#') === 0;
            $who = (string)($b['issued_by_name'] ?? '');
            if ($isFwd) {
                $add($ts, $snOf($fid), 'forward', '轉發', $pname($fid) . '：由「' . preg_replace('/^分批自批次#\d+（?|）$/u', '', (string)$b['note']) . '」轉發 ' . $n($b['send_qty']) . ' 件給「' . $b['maker_id'] . '」', $who);
            } else {
                $add($ts, $snOf($fid), 'send', '發單', $pname($fid) . '：發給「' . $b['maker_id'] . '」' . $n($b['send_qty']) . ' 件', $who);
            }
        }
        // 回廠／取回／報廢
        $rq = $db->query("SELECT r.*, b.maker_id FROM bom_ing_outsource_return r LEFT JOIN bom_ing_outsource_batch b ON b.batch_id=r.batch_id WHERE r.bom_ing_fid IN ($in) ORDER BY r.return_id");
        foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fid = (int)$r['bom_ing_fid'];
            $hasRet[$fid] = true;
            $ts = !empty($r['return_date']) ? substr((string)$r['return_date'], 0, 10) . ' ' . (!empty($r['created_at']) ? substr($r['created_at'], 11) : '00:00:00') : ($r['created_at'] ?? '');
            $mk = (string)($r['maker_id'] ?? '');
            $note = trim((string)($r['note'] ?? ''));
            if ((float)$r['return_qty'] > 0) $add($ts, $snOf($fid), 'return', '回廠', $pname($fid) . '：「' . $mk . '」回廠 ' . $n($r['return_qty']) . ' 件', (string)($r['created_by'] ?? ''));
            if ((float)$r['scrap_qty'] > 0) $add($ts, $snOf($fid), 'scrap', '廠商報廢', $pname($fid) . '：「' . $mk . '」提報報廢 ' . $n($r['scrap_qty']) . ' 件' . ($note !== '' ? '（' . $note . '）' : ''), (string)($r['created_by'] ?? ''), 'red');
            if ((float)$r['withdraw_qty'] > 0) $add($ts, $snOf($fid), 'withdraw', '撤回', $pname($fid) . '：從「' . $mk . '」撤回 ' . $n($r['withdraw_qty']) . ' 件' . ($note !== '' ? '（' . $note . '）' : ''), (string)($r['created_by'] ?? ''));
        }
        // 狀態事件
        $evLabels = ['split' => '拆批', 'merge' => '合批', 'continue' => '繼續', 'quick_sync_transfer' => '快速移轉', 'mark_transferred' => '本關已移轉',
                     'clear_outsource_date' => '清除發包日', 'admin_set_dates' => '管理員改日期', 'return' => '回廠'];
        foreach ($db->query("SELECT * FROM bom_ing_event WHERE bom_ing_fid IN ($in) ORDER BY event_id")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $fid = (int)$e['bom_ing_fid'];
            // 管理員修正／補登類紀錄不在此顯示（會讓現場人員看起來很混亂，查證請看稽核紀錄）
            if (in_array($e['event_type'], ['qc_edit', 'transfer_edit', 'send_edit', 'send_backfill', 'return_edit', 'return_backfill', 'batch_meta_edit', 'admin_set_dates'], true)) continue;
            if ($e['event_type'] === 'return' && !empty($hasRet[$fid])) continue; // 已由回廠明細呈現
            $lb = $evLabels[$e['event_type']] ?? $e['event_type'];
            $txt = $pname($fid) . '：' . $lb . (!empty($e['affected_qty']) ? ' ' . $n($e['affected_qty']) . ' 件' : '') . (!empty($e['event_note']) ? '（' . $e['event_note'] . '）' : '');
            $add($e['Created_At'], $snOf($fid), 'event', $lb, $txt, (string)($e['Created_By'] ?? ''));
        }
        // 品管檢驗
        $qcL = ['ok' => '合格', 'ng' => '不合格', 'QQ' => '待確認', 'AOD' => '特採'];
        foreach ($db->query("SELECT * FROM qc_check WHERE bom_ing_fid_ref IN ($in) ORDER BY qc_check_id")->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $fid = (int)$q['bom_ing_fid_ref'];
            $res = $qcL[$q['QC_check']] ?? (string)$q['QC_check'];
            $qty = $q['QC_check'] === 'ok' ? $q['QC_ok_sqty'] : ($q['QC_check'] === 'ng' ? $q['QC_ng_sqty'] : ($q['QC_check'] === 'AOD' ? $q['QC_aod_sqty'] : $q['QC_QQ_sqty']));
            $ts = !empty($q['QC_check_date']) ? $q['QC_check_date'] : $q['created_at'];
            $mkq = $mkOf($fid);
            $add($ts, $snOf($fid), 'qc', '檢驗' . $res, $pname($fid) . ($mkq !== '' ? '（廠商：' . $mkq . '）' : '') . '：品管檢驗' . $res . ($qty ? ' ' . $n($qty) . ' 件' : ''), (string)($q['created_by'] ?? ''), $q['QC_check'] === 'ok' ? 'green' : 'yellow');
        }
        // 品管異常單
        try {
            $aq = $db->prepare("SELECT * FROM qa_abnormal_order WHERE bom_no = ? AND deleted_at IS NULL ORDER BY id");
            $aq->execute([$bom]);
            $arows = $aq->fetchAll(PDO::FETCH_ASSOC);
            $smap = [];
            try {
                if (function_exists('qab_status_map')) $smap = qab_status_map($db, array_column($arows, 'id'));
            } catch (Throwable $e2) { $smap = []; }
            foreach ($arows as $a) {
                $fids = array_filter(array_map('intval', preg_split('/[,\s]+/', (string)($a['bom_process_fids'] ?? ''))));
                $fid0 = $fids ? reset($fids) : 0;
                $where = isset($proc[$fid0]) ? $pname($fid0) : (!empty($a['resp_process_no']) ? '製程 ' . $a['resp_process_no'] : '');
                $ph = trim((string)($a['abnormal_phenomenon'] ?? ''));
                $mka = $mkOf($fid0);
                if ($mka === '' && !empty($a['responsible_vendor_id'])) {
                    $vq = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no = ?"); $vq->execute([$a['responsible_vendor_id']]);
                    $mka = (string)$vq->fetchColumn();
                }
                $sm = $smap[(int)$a['id']] ?? null;
                $fin = $sm ? implode('、', $sm['final']['names'] ?? []) : '';
                $isScrap = $sm ? !empty($sm['final']['is_scrap']) : !empty($a['scrap_no']);
                $verdict = ($sm ? ($sm['status'] ?? '') : '') . ($fin !== '' ? '，判定：' . $fin : '') . (!empty($a['scrap_no']) ? '，報廢單號 ' . $a['scrap_no'] : '');
                $add($a['created_at'], $snOf($fid0), 'abnormal', $isScrap ? '異常單・報廢' : '品管異常單',
                    $where . ($mka !== '' ? '（廠商：' . $mka . '）' : '') . ($where !== '' || $mka !== '' ? '：' : '') . '開立異常單 ' . $a['abnormal_order_no']
                    . ($ph !== '' ? '（' . mb_substr($ph, 0, 40) . '）' : '') . (!empty($a['ng_qty']) ? ' 不良 ' . $n($a['ng_qty']) : '')
                    . '｜' . ($verdict !== '' ? ltrim($verdict, '，') : '待判定'), (string)($a['created_by'] ?? ''), $isScrap ? 'red' : 'yellow');
                if (!empty($a['is_closed']) && !empty($a['closed_at'])) $add($a['closed_at'], $snOf($fid0), 'abnormal', '異常單結案', '異常單 ' . $a['abnormal_order_no'] . ' 結案' . ($fin !== '' ? '，判定：' . $fin : '') . (!empty($a['scrap_no']) ? '，報廢單號 ' . $a['scrap_no'] : ''), '', $isScrap ? 'red' : 'yellow');
            }
        } catch (Throwable $e) { error_log("bom_timeline abnormal: " . $e->getMessage()); }
        // 移轉憑單
        $tq = $db->prepare("SELECT tl.*, a.maker_id AS m_from, c.maker_id AS m_to FROM bom_ing_transfer_log tl
                              LEFT JOIN maker_list a ON a.maker_id_no = tl.maker_from LEFT JOIN maker_list c ON c.maker_id_no = tl.maker_to
                             WHERE tl.bom = ? ORDER BY tl.transfer_id");
        $tq->execute([$bom]);
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $sn = (int)$t['bom_sn'];
            $add(!empty($t['transfer_date']) ? substr((string)$t['transfer_date'], 0, 10) . ' 12:00:00' : $t['created_at'], $sn, 'transfer', '移轉憑單',
                '第' . ($ordOf[$sn] ?? $sn) . '關：移轉憑單 ' . ($t['transfer_no'] ?? '') . '，由「' . ($t['m_from'] ?: $t['maker_from']) . '」移轉' . ($t['m_to'] || $t['maker_to'] ? '至「' . ($t['m_to'] ?: $t['maker_to']) . '」' : '') . ' ' . $n($t['transfer_qty'] ?: $t['sqty']) . ' 件');
        }
        // 包裝自主檢驗（packing_schedule.php）：防鏽／防撞（泡殼 ?入 x ?個）／容器（含編號）等文字資料
        try {
            $pq = $db->prepare("SELECT p.*, d.data_json FROM qc_packing_inspection p
                                  LEFT JOIN qc_packing_inspection_data d ON d.packing_inspection_id = p.packing_inspection_id
                                 WHERE p.bom = ? ORDER BY p.packing_inspection_id");
            $pq->execute([$bom]);
            foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $d = json_decode((string)($p['data_json'] ?? ''), true);
                if (!is_array($d)) $d = [];
                $parts = [];
                $rust = array_values(array_filter((array)($d['rust'] ?? []), 'strlen'));
                if ($rust) {
                    $rs = [];
                    foreach ($rust as $v) $rs[] = ($v === '其他' && trim((string)($d['rust_other'] ?? '')) !== '') ? '其他：' . trim($d['rust_other']) : $v;
                    $parts[] = '有防鏽 ' . implode('、', $rs);
                }
                $col = array_values(array_filter((array)($d['collision'] ?? []), 'strlen'));
                if ($col) {
                    $cs = [];
                    foreach ($col as $v) {
                        if ($v === '泡殼') {
                            $a = trim((string)($d['collision_detail_1'] ?? '')); $b = trim((string)($d['collision_detail_2'] ?? ''));
                            $cs[] = '泡殼 (' . ($a !== '' ? $a : '?') . '入 x ' . ($b !== '' ? $b : '?') . '個)';
                        } elseif ($v === '其他' && trim((string)($d['collision_other'] ?? '')) !== '') {
                            $cs[] = '其他：' . trim($d['collision_other']);
                        } else $cs[] = $v;
                    }
                    $parts[] = '有防撞 ' . implode('、', $cs);
                }
                $cn = [];
                foreach ((array)($d['rows'] ?? []) as $r) {
                    if (!is_array($r)) continue;
                    $no = trim((string)($r['no'] ?? ''));
                    $cn[] = ($r['type'] ?? '') . ($no !== '' ? ' ' . $no . '號' : '') . ' x ' . ($r['qty'] ?? '');
                }
                if ($cn) $parts[] = '容器 ' . implode('、', $cn);
                $closed = ($p['status'] ?? '') === 'closed';
                $ts = ($closed && !empty($p['closed_at'])) ? $p['closed_at'] : ($p['inspection_date'] . ' 12:00:00');
                $fid = (int)($p['bom_ing_fid'] ?? 0);
                $add($ts, $snOf($fid), 'packing', $closed ? '包裝檢驗' : '包裝檢驗（暫存）',
                    ($pname($fid) !== '' ? $pname($fid) . '：' : '') . '包裝自主檢驗' . ($closed ? '完成' : '暫存') . ($parts ? '｜' . implode('；', $parts) : ''),
                    (string)($p['inspector'] ?: ($p['packer'] ?? '')), 'green');
            }
        } catch (Throwable $ePk) { error_log('bom_timeline packing: ' . $ePk->getMessage()); }
        // BOM 操作紀錄
        $opL = ['manual_close' => '人工結案', 'cancel_close' => '取消結案', 'transfer' => '移轉', 'create_bom' => '新增BOM', 'clear_outsource_date' => '清除發包日'];
        $oq = $db->prepare("SELECT bol.*, u.user_cname FROM bom_operation_log bol LEFT JOIN user u ON u.id = bol.operator_id WHERE bol.bom = ? ORDER BY bol.id");
        $oq->execute([$bom]);
        foreach ($oq->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if (!isset($opL[$o['operation_type']])) continue; // 系統自動／管理員類操作（如 auto_close_shipped）不顯示
            $lb = $opL[$o['operation_type']];
            $add($o['operated_at'], 0, 'op', $lb, 'BOM ' . $lb, (string)($o['user_cname'] ?? ''));
        }
        // BOM 檢核（標示異常／回報／異常解決）
        try {
            require_once __DIR__ . '/bom_check_lib.php';
            foreach (eg_bomchk_events($db, $bom) as $x) $add($x['ts'], 0, 'check', $x['label'], $x['text'], $x['who'], $x['tone']);
        } catch (Throwable $eChk) { }
        // 換製程順序：誰在什麼時候申請、誰核准或退回（含備註）
        try {
            require_once __DIR__ . '/bom_reorder_lib.php';
            foreach (bro_records($db, $bom) as $x) {
                $add($x['requested_at'], 0, 'reorder', '申請換順序', $x['desc'] . ($x['reason'] !== '' ? '（' . $x['reason'] . '）' : ''), $x['requested_by'], 'yellow');
                if ($x['status'] !== 'pending') $add($x['decided_at'], 0, 'reorder', $x['status'] === 'approved' ? '核准換順序' : '退回換順序', $x['desc'] . ($x['decide_note'] !== '' ? '｜備註：' . $x['decide_note'] : ''), $x['decided_by'], $x['status'] === 'approved' ? 'green' : 'red');
            }
        } catch (Throwable $eRo) { }
        // 人員姓名補齊（Created_By／issued_by 可能是代號）
        $ids = [];
        foreach ($ev as $e) if ($e['who'] !== '' && ctype_digit($e['who'])) $ids[$e['who']] = 1;
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $us = $db->prepare("SELECT id, user_cname FROM user WHERE id IN ($ph)");
            $us->execute(array_keys($ids));
            $nm = [];
            foreach ($us->fetchAll(PDO::FETCH_ASSOC) as $u) $nm[(string)$u['id']] = $u['user_cname'];
            foreach ($ev as &$e) if (isset($nm[$e['who']])) $e['who'] = $nm[$e['who']];
            unset($e);
        }
        usort($ev, function ($a, $b) { return strcmp($a['ts'], $b['ts']) ?: ($a['sn'] <=> $b['sn']); });
        foreach ($ev as &$e) { $e['date'] = substr($e['ts'], 0, 10); $e['time'] = strlen($e['ts']) >= 16 ? substr($e['ts'], 11, 5) : ''; }
        unset($e);
        return $ev;
    }
}
