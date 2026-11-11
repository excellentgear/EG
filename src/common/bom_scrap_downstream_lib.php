<?php
// 廠商提報報廢後，「此後製程」數量自動修正（2026-11-11 使用者交辦）。
// 規則（使用者定案）：
//  ①「此後」＝發單日期比這一批晚的批次（不是依製程順序，製程可能跳過）。
//  ②只適用：全部都沒分批，或有分批但已手動設定好分支（移轉憑單手動接續／拆分／批次手動掛上一關）。
//  ③修正項目＝發單數＋回廠數＋QC檢驗數；只在「後面發單數超過剩餘良品」時才扣；
//    受影響的關卡只要有 NG 就整個不能自動修正（避免 OK／NG 數量失真）。
//  ④不動 ERP 移轉憑單、不動 bom_ing.sqty。每個動作寫 bom_ing_event。
// 唯一實作；預覽（eg_scrap_ds_plan）與套用（eg_scrap_ds_apply）共用同一份計畫，兩邊不會算出不同結果。
require_once __DIR__ . '/bom_admin_fix_lib.php';

if (!function_exists('eg_scrap_ds_plan')) {
    /**
     * @param float $scrapAdd 預覽時：這次打算新增的報廢量（尚未寫入）；套用時（報廢已寫入）傳 0
     * @return array ['applicable'=>bool,'reason'=>string,'show'=>bool,'items'=>[...]]
     *   show＝要不要在畫面上顯示這個選項（後面根本沒有發單者不顯示）
     */
    function eg_scrap_ds_plan(PDO $db, int $fid, int $batchId, float $scrapAdd = 0.0): array {
        $none = ['applicable' => false, 'show' => false, 'reason' => '', 'items' => []];
        $st = $db->prepare("SELECT bom FROM bom_ing WHERE bom_ing_fid=?");
        $st->execute([$fid]);
        $bom = (string)$st->fetchColumn();
        if ($bom === '') return $none;
        $L = baf_list($db, $bom);
        $B = [];   // batch_id => row
        foreach ($L['rows'] as $r) if ($r['kind'] === 'send') $B[(int)$r['id']] = $r;
        if (!isset($B[$batchId])) return $none;
        $S = $B[$batchId];
        $key = fn($b) => $b['date'] . '|' . str_pad((string)$b['id'], 10, '0', STR_PAD_LEFT);
        $downIds = [];
        foreach ($B as $id => $b) if ((int)$b['fid'] !== (int)$S['fid'] && strcmp($key($b), $key($S)) > 0) $downIds[] = $id;
        if (!$downIds) return $none;                       // 後面沒有發單：不需要這個選項
        $none['show'] = true;

        // 是否有任何一關分批（同一關多個批次）
        $cnt = [];
        foreach ($B as $b) $cnt[$b['fid']] = ($cnt[$b['fid']] ?? 0) + 1;
        $involved = array_merge([(int)$S['fid']], array_map(fn($i) => (int)$B[$i]['fid'], $downIds));
        $batched = false;
        foreach (array_unique($involved) as $f) if (($cnt[$f] ?? 0) > 1) $batched = true;

        $chain = [];   // 依序要檢查的批次（每個的上游 good 由前一個決定）
        if (!$batched) {
            usort($downIds, fn($a, $b) => strcmp($key($B[$a]), $key($B[$b])));
            $chain = $downIds;
        } else {
            // 只走「手動設定好」的分支：移轉憑單的手動接續／拆分、批次手動掛上一關
            $kids = [];
            foreach ($L['rows'] as $r) {
                if ($r['kind'] !== 'transfer') continue;
                $fl = $L['flow'][$r['id']] ?? null;
                if (!$fl || empty($fl['src'])) continue;
                if (!empty($fl['alloc'])) { foreach ($fl['alloc'] as $a) $kids[(int)$fl['src']][(int)$a['batch_id']] = 1; }
                elseif (!empty($fl['dst']) && empty($fl['dst_auto'])) $kids[(int)$fl['src']][(int)$fl['dst']] = 1;
            }
            foreach ($L['bparent'] as $child => $parent) $kids[(int)$parent][(int)$child] = 1;
            $cur = $batchId; $seen = [$batchId => 1];
            while (!empty($kids[$cur])) {
                if (count($kids[$cur]) > 1) { $none['reason'] = '已分批，且下一關分成多條分支，無法自動判斷數量，請到管理員修正面板手動調整。'; return $none; }
                $nx = (int)key($kids[$cur]);
                if (isset($seen[$nx]) || !isset($B[$nx])) break;
                $seen[$nx] = 1; $chain[] = $nx; $cur = $nx;
            }
            if (!$chain) { $none['reason'] = '這個 BOM 有分批，但還沒有設定好分支（移轉憑單接續／拆分），無法自動修正。請先到管理員修正面板設定分支。'; return $none; }
        }

        // 沿著鏈逐關計算：下一關的發單數不可超過上一關剩餘良品
        $good = (float)$S['send_qty'] - ((float)$S['scrap_qty'] + $scrapAdd) - (float)$S['withdraw_qty'];
        $items = [];
        foreach ($chain as $bid) {
            $x = $B[$bid];
            $send = (float)$x['send_qty'];
            $newSend = min($send, max(0.0, $good));
            $newRet = min((float)$x['return_qty'], max(0.0, $newSend - (float)$x['scrap_qty'] - (float)$x['withdraw_qty']));
            if ($newSend < $send - 1e-9 || $newRet < (float)$x['return_qty'] - 1e-9) {
                $items[] = ['batch_id' => $bid, 'fid' => (int)$x['fid'], 'station' => $x['station'], 'maker' => $x['maker'],
                            'send_old' => $send, 'send_new' => $newSend, 'ret_old' => (float)$x['return_qty'], 'ret_new' => $newRet,
                            'qc' => []];
            }
            $good = $newSend - (float)$x['scrap_qty'] - (float)$x['withdraw_qty'];
        }
        if (!$items) { $none['reason'] = '後面各關的發單數都在可用範圍內，不需要修正。'; return $none; }

        // QC：受影響的關卡只要有 NG 就不可自動修正；沒有 NG 時，檢驗數超過新回廠量的部分從最後一筆的 OK 扣
        $qcRows = [];
        foreach ($L['rows'] as $r) if ($r['kind'] === 'qc') $qcRows[(int)$r['fid']][] = $r;
        foreach ($items as &$it) {
            $rows = $qcRows[$it['fid']] ?? [];
            foreach ($rows as $q) {
                if ((int)$q['ng'] > 0) { $none['reason'] = '「' . $it['station'] . '」的 QC 檢驗有 NG 數量，為避免 OK／NG 數量失真，不做自動修正。請到管理員修正面板手動調整。'; return $none; }
            }
            if ($rows) {
                $tot = 0; foreach ($rows as $q) $tot += (int)$q['ok'] + (int)$q['aod'] + (int)$q['qq'];
                $excess = (int)round($tot - $it['ret_new']);
                if ($excess > 0) {
                    $rev = array_reverse($rows);
                    foreach ($rev as $q) {
                        if ($excess <= 0) break;
                        $take = min($excess, (int)$q['ok']);
                        if ($take > 0) { $it['qc'][] = ['qc_check_id' => (int)$q['id'], 'ok_old' => (int)$q['ok'], 'ok_new' => (int)$q['ok'] - $take]; $excess -= $take; }
                    }
                    if ($excess > 0) { $none['reason'] = '「' . $it['station'] . '」的 QC 合格數不足以扣除，不做自動修正。請到管理員修正面板手動調整。'; return $none; }
                }
            }
        }
        unset($it);
        return ['applicable' => true, 'show' => true, 'reason' => '', 'items' => $items];
    }
}

if (!function_exists('eg_scrap_ds_apply')) {
    /** 套用計畫（報廢已寫入之後呼叫，scrapAdd=0）。回傳實際修正的關卡摘要文字陣列。包在交易裡。 */
    function eg_scrap_ds_apply(PDO $db, int $fid, int $batchId, int $uid): array {
        $plan = eg_scrap_ds_plan($db, $fid, $batchId, 0.0);
        if (empty($plan['applicable'])) return ['ok' => false, 'message' => $plan['reason'] ?: '不需要修正', 'lines' => []];
        $lines = [];
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            foreach ($plan['items'] as $it) {
                $bq = $db->prepare("SELECT * FROM bom_ing_outsource_batch WHERE batch_id=? FOR UPDATE");
                $bq->execute([$it['batch_id']]);
                $b = $bq->fetch(PDO::FETCH_ASSOC);
                if (!$b || abs((float)$b['send_qty'] - $it['send_old']) > 1e-9 || abs((float)$b['return_qty'] - $it['ret_old']) > 1e-9) throw new Exception('「' . $it['station'] . '」的數量剛剛被別人改過，請重新整理後再試');
                $closed = ($it['ret_new'] + (float)$b['scrap_qty'] + (float)($b['withdrawn_qty'] ?? 0)) >= $it['send_new'] - 1e-9;
                $db->prepare("UPDATE bom_ing_outsource_batch SET send_qty=?, return_qty=?, status=?, updated_at=NOW() WHERE batch_id=?")
                   ->execute([$it['send_new'], $it['ret_new'], $closed ? 'closed' : 'open', $it['batch_id']]);
                $cut = $it['ret_old'] - $it['ret_new'];
                if ($cut > 1e-9) {
                    $rq = $db->prepare("SELECT return_id, return_qty FROM bom_ing_outsource_return WHERE batch_id=? AND return_qty>0 ORDER BY return_id DESC");
                    $rq->execute([$it['batch_id']]);
                    foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if ($cut <= 1e-9) break;
                        $t = min($cut, (float)$r['return_qty']);
                        $db->prepare("UPDATE bom_ing_outsource_return SET return_qty=return_qty-? WHERE return_id=?")->execute([$t, $r['return_id']]);
                        $cut -= $t;
                    }
                }
                foreach ($it['qc'] as $q) {
                    $db->prepare("UPDATE qc_check SET QC_ok_sqty=? WHERE qc_check_id=? AND bom_ing_fid_ref=?")->execute([$q['ok_new'], $q['qc_check_id'], (string)$it['fid']]);
                }
                $txt = $it['station'] . '：發單 ' . $it['send_old'] . '→' . $it['send_new'] . '，回廠 ' . $it['ret_old'] . '→' . $it['ret_new'] . ($it['qc'] ? '，QC合格已同步扣除' : '');
                $lines[] = $txt;
                ber_log($db, $it['fid'], 'scrap_downstream', '上游（fid ' . $fid . ' 批次 ' . $batchId . '）廠商提報報廢，自動修正此後數量：' . $txt, $uid);
            }
            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'message' => $e->getMessage(), 'lines' => []];
        }
        return ['ok' => true, 'message' => '已自動修正此後 ' . count($lines) . ' 關的數量', 'lines' => $lines];
    }
}
