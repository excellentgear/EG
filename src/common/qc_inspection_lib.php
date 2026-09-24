<?php
// =============================================================================
// src/common/qc_inspection_lib.php
// 線上檢驗（views/QC/inspection_combined_prototype.php）後端共用函式
//  - #3：後端以「權威公差」重算數值型 OK/NG（不信任前端 result）
//  - #10：多量具/多次量測——每 (item,sample) 可有多筆讀值(不同 measure_method/tool_id)
//  - #12：存檔(save_inspection) 與更新(update_inspection) 共用同一套解析/寫入/彙總
// 抽成獨立檔以便單元測試與重用；以 function_exists 防重複定義。
// =============================================================================

require_once __DIR__ . '/qc_tool_display_lib.php'; // 量具顯示名稱統一格式（ai-rules/25，qc_form_tools_rows() 要用）

if (!function_exists('qc_suggest_sample_qty')) {
    /**
     * 依抽樣規則（qc_sampling_rule，設定入口＝線上檢驗的「抽樣規則設定」）算出建議抽驗數。
     * **全站唯一實作**：線上檢驗與品質異常處理單都呼叫這一支，不要各自再寫一次 SQL——
     * 兩份規則遲早會走鐘，而且同一批量在兩張表上算出不同的抽驗數沒有人查得出來。
     * 規則區間可重疊（現況同時有 1~15 與 1~52），沿用既有口徑：取 min_qty 最大的那一條。
     */
    function qc_suggest_sample_qty($pdo, $batchQty) {
        $q = (int)$batchQty;
        if ($q < 1) return 0;
        $sample = 0;
        try {
            $sr = $pdo->prepare("SELECT sample_qty FROM qc_sampling_rule
                                 WHERE ? BETWEEN min_qty AND max_qty AND (is_active IS NULL OR is_active=1)
                                 ORDER BY min_qty DESC LIMIT 1");
            $sr->execute([$q]);
            $sample = (int)$sr->fetchColumn();
        } catch (Exception $e) {}
        if (!$sample) $sample = $q >= 500 ? 8 : ($q >= 100 ? 5 : 3);   // 沒有規則時的簡易推估（沿用既有）
        if ($sample > $q) $sample = $q;
        if ($sample < 1) $sample = 1;
        return $sample;
    }
}

if (!function_exists('qc_backfill_extract')) {
    /**
     * 補資料（管理員）：首次建立檢驗單時就能一併指定「檢驗日期／檢驗人員／主管審核人員與日期」，
     * 不必等存檔完再打開修改一次（使用者明確要求避免做兩次事）。
     * **全站唯一實作**：save_inspection／save_adhoc／save_ship 三個「首次建立」寫入點共用，
     * 與既有的 backfill_save（改既有紀錄用）走同一套驗證規則，兩邊不會走鐘。
     * 沒有權限或前端沒送 bf_check_date（一般填寫者的正常存檔）時，四個回傳值全部是 null，
     * 呼叫端接原本的預設值（check_date=CURDATE()、inspector_by/approved_by/approved_at=NULL），
     * 行為與補資料功能上線之前完全相同。
     * @return array [check_date, inspector_by, approved_by, approved_at]，皆可能為 null
     */
    function qc_backfill_extract(PDO $pdo, bool $canBackfill, string $today): array {
        if (!$canBackfill) return [null, null, null, null];
        $checkDate = trim($_POST['bf_check_date'] ?? '');
        if ($checkDate === '') return [null, null, null, null];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate)) throw new Exception('補資料：檢驗日期格式錯誤');
        if ($checkDate > $today) throw new Exception('補資料：檢驗日期不可以是未來日期');
        $inspectorId = (int)($_POST['bf_inspector_id'] ?? 0);
        if (!$inspectorId) throw new Exception('補資料：請選擇檢驗人員');
        $ids = array_column(qc_backfill_people($pdo, $checkDate, false), 'id');
        if (!in_array($inspectorId, $ids, false)) throw new Exception('補資料：檢驗人員在檢驗日期當天不在職，請重新選擇');

        $approvedBy = null; $approvedAt = null;
        if (($_POST['bf_approved'] ?? '0') === '1') {
            $approvedAt = trim($_POST['bf_approved_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $approvedAt)) throw new Exception('補資料：主管審核日期格式錯誤');
            if ($approvedAt > $today) throw new Exception('補資料：主管審核日期不可以是未來日期');
            if ($approvedAt < $checkDate) throw new Exception('補資料：主管審核日期不可以早於檢驗日期');
            $approverId = (int)($_POST['bf_approver_id'] ?? 0);
            if (!$approverId) throw new Exception('補資料：請選擇主管審核人員');
            $aids = array_column(qc_backfill_people($pdo, $approvedAt, true), 'id');
            if (!in_array($approverId, $aids, false)) throw new Exception('補資料：審核人員在審核日期當天不在職，請重新選擇');
            $approvedBy = $approverId;
        }
        return [$checkDate, $inspectorId, $approvedBy, $approvedAt];
    }
}

if (!function_exists('qc_backfill_people')) {
    /**
     * 補資料「檢驗人員」／「審核人員」的候選名單：一律限「品管部門（含子部門）」，
     * 不是全公司——原本沒有部門篩選，長清單裡混進了不相干部門的所有在職人員
     * （使用者 2026-09-24 回報：畫面上的名單應該跟「主管審核自動核可設定」的核可主管
     * 一樣是品管部門的人）。品管部門是哪一個部門一律取自 org_role_setting 的綁定
     * （qc_dept，禁止寫死部門 id），與核可主管同一份設定來源。
     * $mgrOnly=true 時只留品管部門底下的主管（職稱在 position_level 有登記職級者）——
     * 「審核人員」代表主管審核，候選池比照「主管審核自動核可設定」的核可主管清單。
     * 品管部門尚未設定時退回未過濾的全公司名單，不擋流程（相容尚未設定的環境）。
     */
    function qc_backfill_people(PDO $pdo, string $date, bool $mgrOnly = false): array {
        require_once __DIR__ . '/org_role_lib.php';
        require_once __DIR__ . '/people_lib.php';
        $deptIds = eg_org_dept_ids($pdo, 'qc_dept');
        $rows = eg_people_list_asof($pdo, $deptIds ? ['dept_ids' => $deptIds] : [], $date);
        if ($mgrOnly && $deptIds) {
            static $mgrPos = null;
            if ($mgrPos === null) {
                try {
                    $mgrPos = array_map('intval', array_column(
                        $pdo->query("SELECT DISTINCT position_id FROM position_level WHERE level IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC),
                        'position_id'));
                } catch (Throwable $e) { $mgrPos = []; }
            }
            if ($mgrPos) {
                $rows = array_values(array_filter($rows, function ($r) use ($mgrPos) {
                    return in_array((int)($r['position_id'] ?? 0), $mgrPos, true);
                }));
            }
        }
        return $rows;
    }
}

if (!function_exists('qc_item_tolerance_params')) {
    /**
     * 由前端送來的單一項目($it，items[idx])，解出要寫進 qc_inspection_item 的公差欄位組合。
     * **全站唯一實作**：新增檢驗項目的寫入點有 save_inspection／update_inspection／save_adhoc／
     * save_ship 四處，公差輸入模式(TOL 標準值±公差 vs RANGE 直接填絕對上下限)的判斷邏輯
     * 只能有一份，四處各自判斷遲早會有一處漏改、變成「這裡存得對、那裡存不對」。
     * 回傳 [type, std, min_value, max_value, plus_tolerance, minus_tolerance]。
     */
    function qc_item_tolerance_params($it) {
        $type = (($it['type'] ?? 'NUM') === 'OKNG') ? 'OKNG' : 'NUMERIC';
        $std  = $it['std'] ?? '';
        $isRange = ($it['mode'] ?? '') === 'RANGE';
        $plus  = (!$isRange && ($it['up'] ?? '') !== '') ? $it['up'] : null;
        $minus = (!$isRange && ($it['lo'] ?? '') !== '') ? $it['lo'] : null;
        $minV  = ($isRange && ($it['min'] ?? '') !== '') ? $it['min'] : null;
        $maxV  = ($isRange && ($it['max'] ?? '') !== '') ? $it['max'] : null;
        return [$type, $std, $minV, $maxV, $plus, $minus];
    }
}

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
        $formToolIds = [];    // 讀值上帶到的量具（舊頁面用）→ 收斂成整張單的量具
        foreach ($items as $idx => $it) {
            $iid = $itemIds[$idx] ?? null;
            if (!$iid) continue;
            $iid = (int)$iid;
            $manualVerdict = $it['verdict'] ?? 'OK'; // 僅認 AOD 手動特採
            $remark = (isset($it['remark']) && $it['remark'] !== '') ? mb_substr((string)$it['remark'], 0, 255) : null;
            // 權威公差(DB) 優先；DB 無則回退前端顯示值——回退組合與寫入 qc_inspection_item
            // 用同一支 qc_item_tolerance_params()，避免「新項目第一次存檔時(DB尚無資料)」
            // 被 std+up/lo 誤判成 TOL 模式(公差輸入模式=RANGE 時本該用 min/max)。
            if (isset($specMap[$iid])) {
                $spec = $specMap[$iid];
            } else {
                [$fType, $fStd, $fMin, $fMax, $fUp, $fLo] = qc_item_tolerance_params($it);
                $spec = ['type'=>$fType==='OKNG'?'OKNG':'NUM', 'std'=>$fStd, 'up'=>$fUp, 'lo'=>$fLo, 'min'=>$fMin, 'max'=>$fMax];
            }

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
                if ($r[5] !== null) $formToolIds[(int)$r[5]] = true;   // 舊頁面仍逐項送量具 → 同步到表單層級
            }
        }

        // 舊頁面（inspection_result_entry / inspection_combined_prototype）仍把量具存在每一筆讀值上；
        // 量具追溯已改以「整張檢驗單」為單位，這裡把它們同步成表單層級的量具，
        // 否則從舊頁面存的檢驗單在量具使用紀錄與新版畫面上會查不到量具。
        // 讀值完全沒帶量具（＝新版 v2 的路徑）時不動，表單層級的量具由呼叫端自己寫。
        if ($formToolIds) qc_form_tools_save($pdo, $qc_form_id, array_keys($formToolIds));

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

// =============================================================================
// 檢驗單使用的量具（2026-09-16 使用者定案：量具綁在「整張檢驗單」，不綁到個別檢驗項目）
//   資料表 qc_form_tool（見 views/QC/migrations/2026-09-16_qc_form_tool.php）。
//   追溯口徑：只需要查得到「這支量具用在哪幾張檢驗單」，不需要查到是哪一個檢驗項目，
//   所以 qc_measurement.tool_id 新資料一律不再寫入（舊資料保留、已回填到本表）。
//   唯一實作放這裡，save_inspection／update_inspection／save_adhoc 三個寫入點共用。
// =============================================================================
if (!function_exists('qc_form_tools_parse')) {
    // 前端送來的 tool_ids（JSON 字串或陣列）→ 去重後的正整數陣列（維持挑選順序）
    function qc_form_tools_parse($raw) {
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0 && !in_array($id, $out, true)) $out[] = $id;
        }
        return $out;
    }
}

if (!function_exists('qc_form_tools_save')) {
    // 覆寫某張檢驗單的量具清單。$toolIds 允許傳 JSON 字串。
    // 不存在於 qc_tool 的 id 一律略過（前端擋一次，後端同規則再擋一次）。
    // 呼叫端可在 transaction 內呼叫（純 DML，無 DDL）。回傳實際寫入的 id 陣列。
    function qc_form_tools_save($pdo, $qc_form_id, $toolIds) {
        $qc_form_id = (int)$qc_form_id;
        if ($qc_form_id <= 0) return [];
        $ids = qc_form_tools_parse($toolIds);
        $valid = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT Tool_id FROM qc_tool WHERE Tool_id IN ($ph)");
            $st->execute($ids);
            $ok = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            foreach ($ids as $id) { if (in_array($id, $ok, true)) $valid[] = $id; }   // 維持前端順序
        }
        $pdo->prepare("DELETE FROM qc_form_tool WHERE qc_form_id=?")->execute([$qc_form_id]);
        if ($valid) {
            $ins = $pdo->prepare("INSERT INTO qc_form_tool (qc_form_id, tool_id, sort_order, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
            foreach ($valid as $i => $id) $ins->execute([$qc_form_id, $id, $i, null]);
        }
        return $valid;
    }
}

if (!function_exists('qc_form_tools_rows')) {
    // 取某張檢驗單的量具清單（含顯示用的種類／編號／規格／統一格式的顯示名稱）。
    // label 一律走 qc_tool_disp_label()（ai-rules/25，唯一實作）——同一支量具在這裡跟
    // sop_sip.php／tool_calibration.php 顯示成同一種格式，不要各自拼字串。
    // 舊欄位 cat／spec 保留給既有呼叫端相容（未改用 label 的地方行為不變）。
    function qc_form_tools_rows($pdo, $qc_form_id) {
        $qc_form_id = (int)$qc_form_id;
        if ($qc_form_id <= 0) return [];
        static $hasSpec = null, $hasBrand = false;
        if ($hasSpec === null) {
            try {
                $hasSpec = (bool)$pdo->query("SHOW COLUMNS FROM qc_tool LIKE 'purchase_spec_id'")->fetchColumn()
                        && (bool)$pdo->query("SHOW TABLES LIKE 'purchase_spec'")->fetchColumn();
                if ($hasSpec) $hasBrand = (bool)$pdo->query("SHOW COLUMNS FROM purchase_spec LIKE 'brand'")->fetchColumn();
            } catch (Exception $e) { $hasSpec = false; }
        }
        $sel  = $hasSpec ? (", ps.spec_text AS spec_text" . ($hasBrand ? ", ps.brand AS spec_brand" : "")) : "";
        $join = $hasSpec ? " LEFT JOIN purchase_spec ps ON ps.spec_id = t.purchase_spec_id" : "";
        // t.* 一併帶出 machine／spec_desc／manufacturer／position／note／state／disabled_date，
        // qc_tool_disp_label() 才組得出跟其他頁面一致的顯示名稱。
        $st = $pdo->prepare("SELECT t.*, tl.QC_Tool AS cat_name$sel
                             FROM qc_form_tool ft
                             JOIN qc_tool t ON t.Tool_id = ft.tool_id
                             LEFT JOIN qc_tool_list tl ON tl.QC_Tool_List_id = t.QC_Tool_List_id
                             $join
                             WHERE ft.qc_form_id=? ORDER BY ft.sort_order ASC, ft.tool_id ASC");
        $st->execute([$qc_form_id]);
        if (!$st->rowCount()) {
            // 退路：舊頁面（inspection_result_entry）自己寫 qc_measurement、沒有經過本庫，
            // 這種單子在 qc_form_tool 沒有資料 → 即時由讀值上的量具推回來，畫面才不會空白。
            $st = $pdo->prepare("SELECT DISTINCT t.*, tl.QC_Tool AS cat_name$sel
                                 FROM qc_measurement m
                                 JOIN qc_tool t ON t.Tool_id = m.tool_id
                                 LEFT JOIN qc_tool_list tl ON tl.QC_Tool_List_id = t.QC_Tool_List_id
                                 $join
                                 WHERE m.qc_form_id=? AND m.tool_id IS NOT NULL ORDER BY t.Tool_id ASC");
            $st->execute([$qc_form_id]);
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $spec = trim(preg_replace('/\s+/', ' ', ($r['spec_brand'] ?? '') . ' ' . ($r['spec_text'] ?? '')));
            $r['QC_Tool'] = (string)($r['cat_name'] ?? '');
            $out[] = ['id' => (int)$r['Tool_id'], 'no' => (string)$r['Tool_No'],
                      'cat' => (string)($r['cat_name'] ?? ''), 'spec' => $spec,
                      'label' => qc_tool_disp_label($pdo, $r)];
        }
        return $out;
    }
}

if (!function_exists('qc_form_tools_label')) {
    // 顯示用一行字：逐支量具以「、」串接——列印與清單共用同一種寫法。
    // 每支量具的顯示文字優先用 qc_form_tools_rows() 帶回的 label（qc_tool_disp_label()，ai-rules/25）；
    // 沒有 label（呼叫端自己組的舊格式 rows）才退回「類型 編號(規格)」這套舊寫法。
    function qc_form_tools_label($rows) {
        $parts = [];
        foreach (($rows ?: []) as $t) {
            $label = trim((string)($t['label'] ?? ''));
            if ($label !== '') { $parts[] = $label; continue; }
            $no = trim((string)($t['no'] ?? ''));
            $spec = trim((string)($t['spec'] ?? ''));
            // 舊資料的規格常被人工寫進編號括號裡（例 A-002-Q (25-50mm)）→ 不要再重複附加。
            // 逐詞比對（與前端 toolNoSpec() 同一套規則）：「H-003-Q 三點式(20-25mm)」配規格
            // 「20-25mm 三點式」時，只比整串會比不到而印成兩次。
            if ($spec !== '') {
                $flat = str_replace(' ', '', $no);
                $allIn = true;
                foreach (preg_split('/\s+/u', $spec, -1, PREG_SPLIT_NO_EMPTY) as $w) {
                    if (mb_strpos($flat, $w) === false) { $allIn = false; break; }
                }
                if (!$allIn) $no .= '(' . $spec . ')';
            }
            $cat = trim((string)($t['cat'] ?? ''));
            $parts[] = ($cat !== '' ? $cat . ' ' : '') . $no;
        }
        return implode('、', $parts);
    }
}
