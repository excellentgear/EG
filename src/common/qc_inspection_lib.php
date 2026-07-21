<?php
// =============================================================================
// src/common/qc_inspection_lib.php
// 線上檢驗（views/QC/inspection_combined_prototype.php）後端共用函式
//  - #3：後端以「權威公差」重算數值型 OK/NG（不信任前端 result）
//  - #10：多量具/多次量測——每 (item,sample) 可有多筆讀值(不同 measure_method/tool_id)
//  - #12：存檔(save_inspection) 與更新(update_inspection) 共用同一套解析/寫入/彙總
// 抽成獨立檔以便單元測試與重用；以 function_exists 防重複定義。
// =============================================================================

if (!function_exists('qc_recompute_result')) {
    // 以「權威公差」重算單筆數值型判定。
    // $spec = ['type'=>'NUM'|'OKNG','std'=>基準,'up'=>上公差,'lo'=>下公差,'min'=>絕對下限,'max'=>絕對上限]
    // 回傳 'OK'/'NG'；回傳 null 代表「無法以公差判定」(OK/NG型/非數值/無基準)，由呼叫端回退前端值。
    function qc_recompute_result($spec, $val) {
        if (!$spec) return null;
        if (($spec['type'] ?? 'NUM') === 'OKNG') return null;
        if ($val === '' || $val === null || !is_numeric($val)) return null;
        $v = (float)$val;
        $min = $spec['min'] ?? null; $max = $spec['max'] ?? null;
        if ($min !== null && $max !== null && is_numeric($min) && is_numeric($max)) {
            return ($v < (float)$min || $v > (float)$max) ? 'NG' : 'OK';
        }
        $base = $spec['std'] ?? '';
        if (!is_numeric($base)) return null;
        $up = (isset($spec['up']) && $spec['up'] !== '' && $spec['up'] !== null) ? (float)$spec['up'] : 0.0;
        $lo = (isset($spec['lo']) && $spec['lo'] !== '' && $spec['lo'] !== null) ? (float)$spec['lo'] : 0.0;
        $b = (float)$base;
        return ($v > $b + $up || $v < $b + $lo) ? 'NG' : 'OK';
    }
}

if (!function_exists('qc_persist_readings')) {
    // 共用「解析 items 讀值 → 後端重算 → 寫 qc_measurement(含多量具/多次量測) → 彙總」。
    // 每項目支援：主讀值(it.samples) + 加量測(it.extra[].samples)，每筆各有 tool_id(量具實例)。
    // measure_method 由量具實例的類型(qc_tool_list.QC_Tool)自動帶入；同尺寸任一讀值 NG ⇒ 該項 NG。
    // 呼叫端須在 transaction 內、且已解析好 $itemIds(與 $items 同索引)。
    // 回傳 ['ng_qty'=>int,'aod_qty'=>int,'check_result'=>'OK'|'NG']。
    function qc_persist_readings($pdo, $qc_form_id, $items, $itemIds, $pcs, $user_id) {
        // 量具實例 → 量測方法(類型名)
        $toolMethod = [];
        foreach ($pdo->query("SELECT t.Tool_id, tl.QC_Tool FROM qc_tool t LEFT JOIN qc_tool_list tl ON tl.QC_Tool_List_id=t.QC_Tool_List_id")->fetchAll(PDO::FETCH_ASSOC) as $tr) {
            $toolMethod[(int)$tr['Tool_id']] = $tr['QC_Tool'];
        }
        // 權威公差：由 qc_inspection_item 依 item_id 讀回（不採信前端 up/lo）
        $specMap = [];
        $iids = array_values(array_filter(array_map('intval', $itemIds)));
        if ($iids) {
            $ph = implode(',', array_fill(0, count($iids), '?'));
            $st = $pdo->prepare("SELECT item_id, result_type, standard_text, plus_tolerance, minus_tolerance, min_value, max_value FROM qc_inspection_item WHERE item_id IN ($ph)");
            $st->execute($iids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $specMap[(int)$r['item_id']] = [
                    'type' => $r['result_type'] === 'OKNG' ? 'OKNG' : 'NUM',
                    'std'  => $r['standard_text'], 'up' => $r['plus_tolerance'], 'lo' => $r['minus_tolerance'],
                    'min'  => $r['min_value'], 'max' => $r['max_value'],
                ];
            }
        }

        $pdo->prepare("DELETE FROM qc_measurement WHERE qc_form_id=?")->execute([$qc_form_id]);
        $insMeas = $pdo->prepare(
            "INSERT INTO qc_measurement
             (qc_form_id, item_id, sample_no, measured_value, result, item_verdict, measure_method, reading_seq, tool_id, remark, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        $itemNGBySample = []; // sample_no => 該 PCS 是否有任一項 NG（後端彙總）
        $aod_qty = 0;
        foreach ($items as $idx => $it) {
            $iid = $itemIds[$idx] ?? null;
            if (!$iid) continue;
            $iid = (int)$iid;
            $manualVerdict = $it['verdict'] ?? 'OK'; // 僅認 AOD 手動特採
            $remark = (isset($it['remark']) && $it['remark'] !== '') ? mb_substr((string)$it['remark'], 0, 255) : null;
            // 權威公差(DB) 優先；DB 無則回退前端顯示值
            $spec = $specMap[$iid] ?? ['type'=>($it['type'] ?? 'NUM')==='OKNG'?'OKNG':'NUM','std'=>($it['std'] ?? ''),'up'=>($it['up'] ?? ''),'lo'=>($it['lo'] ?? ''),'min'=>null,'max'=>null];

            // 組讀值：主讀值 + 加量測
            $readings = [['tool_id'=>(isset($it['tool_id']) && $it['tool_id'] !== '' ? (int)$it['tool_id'] : null),
                         'samples'=>(is_array($it['samples'] ?? null) ? $it['samples'] : [])]];
            if (isset($it['extra']) && is_array($it['extra'])) {
                foreach ($it['extra'] as $ex) {
                    if (!is_array($ex)) continue;
                    $readings[] = ['tool_id'=>(isset($ex['tool_id']) && $ex['tool_id'] !== '' ? (int)$ex['tool_id'] : null),
                                   'samples'=>(is_array($ex['samples'] ?? null) ? $ex['samples'] : [])];
                }
            }

            $rows = []; $itemAnyNG = false; $seqMap = [];
            foreach ($readings as $rd) {
                $tid = $rd['tool_id'];
                $method = ($tid !== null && isset($toolMethod[$tid])) ? $toolMethod[$tid]
                        : (($tid === null && isset($it['tool']) && $it['tool'] !== '') ? $it['tool'] : null);
                foreach ($rd['samples'] as $sIdx => $sv) {
                    $val    = is_array($sv) ? ($sv['v'] ?? '') : $sv;
                    $frontR = is_array($sv) ? ($sv['r'] ?? 'OK') : ((string)$sv === 'NG' ? 'NG' : 'OK');
                    if ($val === '' || $val === null) continue; // 空值(未量測) → 不寫、不列入彙總
                    $res = qc_recompute_result($spec, $val);
                    if ($res === null) $res = ($frontR === 'NG') ? 'NG' : 'OK'; // OK/NG型或無法判定 → 採前端
                    $sampleNo = (int)$sIdx + 1;
                    $key = $sampleNo . '|' . ($method ?? '') . '|' . ($tid ?? '');
                    $seqMap[$key] = ($seqMap[$key] ?? 0) + 1;
                    $rows[] = [$sampleNo, (string)$val, $res, $method, $seqMap[$key], $tid];
                    if ($res === 'NG') { $itemAnyNG = true; $itemNGBySample[$sampleNo] = true; }
                }
            }
            $itemVerdict = ($manualVerdict === 'AOD') ? 'AOD' : ($itemAnyNG ? 'NG' : 'OK');
            if ($itemVerdict === 'AOD') $aod_qty++;

            if (!$rows) { // 無任何讀值 → 留一筆判定列(相容)
                $insMeas->execute([$qc_form_id, $iid, 0, '', $manualVerdict === 'NG' ? 'NG' : 'OK', $itemVerdict, null, 1, null, $remark, $user_id]);
                continue;
            }
            foreach ($rows as $r) {
                $insMeas->execute([$qc_form_id, $iid, $r[0], $r[1], $r[2], $itemVerdict, $r[3], $r[4], $r[5], $remark, $user_id]);
            }
        }

        // PCS 判定 + 不良數：手動覆寫(m=1)照舊，其餘用後端彙總
        $ng_qty = 0;
        if (is_array($pcs) && count($pcs)) {
            foreach ($pcs as $i => $p) {
                if (is_array($p) && !empty($p['m'])) { if (($p['v'] ?? '') === 'NG') $ng_qty++; }
                else if (!empty($itemNGBySample[$i + 1])) $ng_qty++;
            }
        } else {
            $ng_qty = count(array_filter($itemNGBySample));
        }
        return ['ng_qty'=>$ng_qty, 'aod_qty'=>$aod_qty, 'check_result'=>$ng_qty > 0 ? 'NG' : 'OK'];
    }
}
