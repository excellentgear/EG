<?php
// =============================================================================
// views/QC/inspection_print_multi.php   本張製令(BOM)所有製程合併列印
// -----------------------------------------------------------------------------
// 為什麼有這一頁：inspection_entry_v2.php 的「列印」只印目前這一個製程；
// 現場想要一張紙(或幾張)看到整批貨從第一個製程到最後一個製程的檢驗狀態，
// 並自動帶入該圖號的最新工程圖，一眼看出檢驗順序與流程（2026-08 使用者需求）。
//
// 圖面判定：沿用 views/pm/bom_viewer.php 既有邏輯——掃描 Z:/BOM/，檔名開頭比對
// BOM 號碼，純 BOM 號碼檔名視為最新版排最前；若同時有多個候選檔，交由使用者選。
// 這條路線刻意不走 src/common/attach_lib.php（那是給一般附件模組用的，圖面本來
// 就不在那套系統裡，全站都是這樣抓圖面，這裡沿用一致）。
//
// 製程順序：bom_ing.bom_sn（不是 process_no，那是製程「種類」代碼，不是順序）。
// 重驗/複驗：qc_check_form 以 (batch_no, round_no) 表示——batch=送驗批次，
// round=同一批次內的重驗次數。摘要模式列出每批每輪的日期+判定當作「流程小標籤」；
// 完整模式在此之外，展開最後一批最後一輪的完整實測表格。
// =============================================================================
include_once '../../src/common/_config.php';
if (empty($_SESSION['id'])) { http_response_code(403); exit('請先登入'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/rbac.php';
    include_once '../../src/common/qc_inspection_lib.php';   // 本單使用量具（qc_form_tool）共用查詢
    include_once '../../src/common/packing_process_lib.php'; // 包裝製程判定＋包裝檢驗紀錄（2026-09-24：合併列印仍要印出包裝檢驗結果）
    $pdo = (new DBConnection())->getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $act = $_POST['action'];
    $uid2 = (int)($_SESSION['id'] ?? 0);
    $feats2 = rbac_user_features($pdo, $uid2);
    $hasF2 = function ($c) use ($feats2) { return in_array('all', $feats2, true) || in_array($c, $feats2, true); };

    try {
        // 包裝製程沒有登記廠商（ERP 不會替內部包裝站建發包資料），全製程合併列印卻要求每一站
        // 都要有廠商名稱才能列印——這裡讓有「列印／主管審核設定」權限的人綁定一個廠商主檔
        // （存 maker_id_no 不存文字，鐵律4：廠商改名不會失效），套用到所有沒登記廠商的包裝站，
        // 與 inspection_entry_v2.php 的 print_cfg_save 共用同一種權限判定（qc_print_approve_setting）。
        // 搜尋沿用 QaAbnormal_API.php 的 search_vendor 同一套規則，不另外發明一套廠商模糊搜尋。
        if ($act === 'vendor_search') {
            $kw = trim($_POST['kw'] ?? '');
            $st = $pdo->prepare("SELECT maker_id_no, maker_id, internal FROM maker_list
                                 WHERE (status IS NULL OR status<>'X') AND (? = '' OR maker_id LIKE ? OR maker_id_no LIKE ?)
                                 ORDER BY internal DESC, maker_id_no LIMIT 30");
            $st->execute([$kw, "%$kw%", "%$kw%"]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['internal'] = (int)($r['internal'] ?? 0);
            echo json_encode(['success' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'pack_vendor_get') {
            $id = trim((string)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='qc_packing_vendor_id' LIMIT 1")->fetchColumn() ?: ''));
            $name = '';
            if ($id !== '') {
                $mv = $pdo->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no=? LIMIT 1");
                $mv->execute([$id]);
                $name = trim((string)($mv->fetchColumn() ?: ''));
            }
            echo json_encode(['success' => true, 'id' => $id, 'name' => $name, 'can_edit' => $hasF2('qc_print_approve_setting')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'pack_vendor_save') {
            if (!$hasF2('qc_print_approve_setting')) throw new Exception('您沒有「列印／主管自動核可設定」權限，請洽管理員於 設定 → 權限設定開通');
            $id = trim($_POST['id'] ?? '');
            if ($id !== '') {
                $mv = $pdo->prepare("SELECT maker_id, status FROM maker_list WHERE maker_id_no=? LIMIT 1");
                $mv->execute([$id]);
                $mr = $mv->fetch(PDO::FETCH_ASSOC);
                if (!$mr) throw new Exception('查無此廠商代號，請重新從清單選取');
                if (($mr['status'] ?? '') === 'X') throw new Exception('此廠商已停用，不可指定為包裝製程的固定廠商');
            }
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id) VALUES ('qc_packing_vendor_id',?,?)
                           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by_id=VALUES(updated_by_id)")
                ->execute([$id, $uid2]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act !== 'get_data') throw new Exception('不支援的操作');

        $bom = trim($_POST['bom'] ?? '');
        if (!preg_match('/^B-\d{10}$/', $bom)) throw new Exception('BOM 格式錯誤，應為 B- 後接10位數字');
        $mode = ($_POST['mode'] ?? 'summary') === 'full' ? 'full' : 'summary';
        $chosenDrawing = trim($_POST['drawing'] ?? '');
        // 前端「不使用圖面」是使用者明確的選擇，要跟「還沒選過（沿用預設自動挑選）」分開，
        // 兩者都會送出空字串就無法分辨——sentinel 值收到才視為明確不印圖面，不落入下面的自動挑選。
        $explicitNoDrawing = ($chosenDrawing === '__NONE__');
        if ($explicitNoDrawing) $chosenDrawing = '';

        $base = $pdo->prepare("SELECT Client_Name, d_id, sqty FROM bom WHERE bom=? LIMIT 1");
        $base->execute([$bom]);
        $baseRow = $base->fetch(PDO::FETCH_ASSOC);
        if (!$baseRow) throw new Exception('查無此 BOM，請確認單號是否正確');

        // ── 製程清單（依 bom_sn 排序＝實際製程順序）──────────────────────
        $procs = $pdo->prepare("
            SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, pn.ProcessName, bi.sqty AS proc_qty, bi.maker_id,
                   COALESCE(pn.is_exclude_qc,0) AS is_exclude_qc
            FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
            WHERE bi.bom = ?
            ORDER BY bi.bom_sn ASC
        ");
        $procs->execute([$bom]);
        $procRows = $procs->fetchAll(PDO::FETCH_ASSOC);

        $fids = array_map('intval', array_column($procRows, 'bom_ing_fid'));
        $formsByFid = [];
        if ($fids) {
            $ph = implode(',', array_fill(0, count($fids), '?'));
            $fs = $pdo->prepare("
                SELECT qc_form_id, bom_ing_fid, batch_no, round_no, incoming_qty, sample_qty, ng_qty,
                       check_result, main_remark, check_date, created_by, inspector_by, insp_kind, created_at
                FROM qc_check_form
                WHERE bom_ing_fid IN ($ph) AND status <> 'DRAFT'
                ORDER BY bom_ing_fid ASC, batch_no ASC, round_no ASC
            ");
            $fs->execute($fids);
            foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $formsByFid[(int)$f['bom_ing_fid']][] = $f;
            }
        }

        // ── 出貨檢驗（insp_kind=SHIP）：不屬於任何一個 bom_ing 製程，改用 ship_bom 撈這張 BOM
        // 的出貨檢驗單，一律只取最新一張（可能重新產生過好幾次），用固定 sentinel fid=-1 接到
        // 下面同一套「批次/完整明細」組裝邏輯，不必另外複製一份 ──
        $SHIP_FID = -1;
        $shipForm = $pdo->prepare("SELECT qc_form_id, bom_ing_fid, batch_no, round_no, incoming_qty, sample_qty, ng_qty,
                                    check_result, main_remark, check_date, created_by, inspector_by, insp_kind, created_at
                                   FROM qc_check_form WHERE ship_bom=? AND insp_kind='SHIP' AND status<>'DRAFT'
                                   ORDER BY qc_form_id DESC LIMIT 1");
        $shipForm->execute([$bom]);
        if ($sf = $shipForm->fetch(PDO::FETCH_ASSOC)) {
            $sf['bom_ing_fid'] = $SHIP_FID;
            $formsByFid[$SHIP_FID] = [$sf];
        }

        // 檢驗人姓名一次查完（people_lib 只列在職會篩掉離職者名字，這裡單純顯示歷史紀錄的人名，不做在職判定）
        // 使用者 2026-09-24 回報「同料號歷次檢驗」的檢驗人顯示錯誤，這裡同一個坑：優先取 inspector_by
        // （補資料指定的實際檢驗人），沒有才退回 created_by（存檔者），不可只看 created_by。
        $creatorOf = function ($f) { return $f['inspector_by'] ?: $f['created_by']; };
        $uidSet = [];
        foreach ($formsByFid as $rows) foreach ($rows as $r) if (!empty($creatorOf($r))) $uidSet[$creatorOf($r)] = true;
        $nameMap = [];
        if ($uidSet) {
            $ph2 = implode(',', array_fill(0, count($uidSet), '?'));
            $un = $pdo->prepare("SELECT id, COALESCE(NULLIF(user_cname,''), user_uname) AS nm FROM user WHERE id IN ($ph2)");
            $un->execute(array_keys($uidSet));
            foreach ($un->fetchAll(PDO::FETCH_ASSOC) as $u) $nameMap[$u['id']] = $u['nm'];
        }

        // ── 完整模式：每個製程「每一批、每一輪」都各自展開完整實測明細 ──────
        // 使用者 2026-09-24 回報：齒研有兩批檢驗（第1批合格→重驗合格），列印卻只印出一批。
        // 原本只取「最後一批、最後一輪」，多批/複驗的舊資料因此在列印上完全不見；
        // 改成 $itemsByFid[$fid] 是一個陣列，每筆表單各自一份明細，前端逐筆各印一段。
        $itemsByFid = [];
        if ($mode === 'full') {
            $fmt = function ($v) { if ($v === null) return ''; $s = rtrim(rtrim((string)$v, '0'), '.'); return ($s === '' || $s === '-') ? '0' : $s; };
            foreach ($formsByFid as $fid => $rows) {
                foreach ($rows as $formRow) {
                    $qid = (int)$formRow['qc_form_id'];
                    $sampleN = max(1, (int)$formRow['sample_qty']);
                    $mq = $pdo->prepare("
                        SELECT m.item_id, m.sample_no, m.measured_value, m.result, m.item_verdict,
                               m.measure_method, m.tool_id, t.Tool_No,
                               i.item_name, i.standard_text, i.min_value, i.max_value, i.plus_tolerance, i.minus_tolerance, i.sort_order,
                               (SELECT tl.QC_Tool FROM qc_inspection_item_tool_type itt JOIN qc_tool_list tl ON itt.QC_Tool_List_id=tl.QC_Tool_List_id WHERE itt.item_id=i.item_id ORDER BY itt.is_primary DESC LIMIT 1) AS tool_name
                        FROM qc_measurement m JOIN qc_inspection_item i ON m.item_id=i.item_id
                        LEFT JOIN qc_tool t ON m.tool_id=t.Tool_id
                        WHERE m.qc_form_id=?
                        ORDER BY i.sort_order ASC, m.item_id ASC, m.measurement_id ASC
                    ");
                    $mq->execute([$qid]);
                    $byItem = [];
                    foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $iid = (int)$r['item_id'];
                        if (!isset($byItem[$iid])) {
                            // 公差輸入模式：DB 有 min_value/max_value 才算 RANGE(直接填絕對上下限)，否則 TOL(標準值±公差)
                            $hasRange = $r['min_value'] !== null && $r['max_value'] !== null;
                            $byItem[$iid] = [
                                'name' => $r['item_name'], 'std' => $r['standard_text'],
                                'mode' => $hasRange ? 'RANGE' : 'TOL', 'min' => $hasRange ? $fmt($r['min_value']) : '', 'max' => $hasRange ? $fmt($r['max_value']) : '',
                                'up' => $fmt($r['plus_tolerance']), 'lo' => $fmt($r['minus_tolerance']),
                                'tool' => $r['tool_name'] ?: ($r['measure_method'] ?: ''),
                                'verdict' => $r['item_verdict'] ?: 'OK',
                                'samples' => array_fill(0, $sampleN, ['v' => '', 'r' => 'OK']),
                            ];
                        }
                        $pos = (int)$r['sample_no'] - 1;
                        if ($pos >= 0 && $pos < $sampleN) $byItem[$iid]['samples'][$pos] = ['v' => $r['measured_value'], 'r' => $r['result']];
                    }
                    // 使用量具：整張檢驗單綁一次（2026-09-16），不再逐項顯示
                    // 使用者 2026-09-24 回報「每張檢驗表都要顯示檢驗人員」——原本這裡完全沒有帶
                    // creator，逐批明細區塊印不出是誰驗的；insp_kind 一併帶出供正確標示首件/末件。
                    $itemsByFid[$fid][] = [
                        'batch_no' => (int)$formRow['batch_no'], 'round_no' => (int)$formRow['round_no'],
                        'insp_kind' => $formRow['insp_kind'] ?: 'NORMAL',
                        'date' => substr((string)($formRow['check_date'] ?: $formRow['created_at']), 0, 10),
                        'check_result' => $formRow['check_result'],
                        'creator' => $nameMap[$creatorOf($formRow)] ?? '',
                        'sample_n' => $sampleN, 'items' => array_values($byItem),
                        'tools' => qc_form_tools_label(qc_form_tools_rows($pdo, $qid)),
                    ];
                }
            }
        }

        // ── 圖面：沿用 bom_viewer.php 的掃描/排序邏輯 ──────────────────────
        require_once __DIR__ . '/../../src/common/bom_dir_lib.php';   // 資料夾位置走設定鍵 bom_scan_dir，不再寫死 Z: 磁碟機代號
        $scanDir = eg_bom_scan_dir_auto(); $urlDir = '/nas/';
        $candidates = [];
        if (is_dir($scanDir)) {
            foreach (scandir($scanDir) as $fn) {
                if ($fn === '.' || $fn === '..') continue;
                if (strpos($fn, $bom) === 0) {
                    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) $candidates[] = $fn; // 列印用先只處理圖片，PDF不易內嵌
                }
            }
            usort($candidates, function ($a, $b) use ($bom, $scanDir) {
                $aPlain = (pathinfo($a, PATHINFO_FILENAME) === $bom) ? 0 : 1;
                $bPlain = (pathinfo($b, PATHINFO_FILENAME) === $bom) ? 0 : 1;
                if ($aPlain !== $bPlain) return $aPlain - $bPlain;
                $ta = @filemtime($scanDir . $a) ?: 0; $tb = @filemtime($scanDir . $b) ?: 0;
                return $tb <=> $ta;
            });
        }
        if ($chosenDrawing !== '' && !in_array($chosenDrawing, $candidates, true)) $chosenDrawing = '';
        // 使用者 2026-09-24 回報「沒有讓我選擇要使用在報告上的圖面」——原本有多張候選圖面時
        // 一律留空、要使用者自己從下拉挑，沒挑就直接印「（無圖面）」，等於預設值是「什麼都不印」；
        // 改成有候選圖面（不論一張或多張）一律先自動挑最新的那張（候選本來就已依「純BOM檔名優先、
        // 其次依修改時間新到舊」排序），前端另外標示「已自動選用，可自行更換」＋縮圖預覽，
        // 讓使用者一眼看得出印的是哪一張、要換再從下拉挑。
        $autoPicked = false;
        if ($chosenDrawing === '' && $candidates && !$explicitNoDrawing) { $chosenDrawing = $candidates[0]; $autoPicked = true; }

        $drawing = ['url' => '', 'orient' => 'landscape', 'ambiguous' => count($candidates) > 1,
            'candidates' => $candidates, 'chosen' => $chosenDrawing, 'auto_picked' => $autoPicked];
        if ($chosenDrawing !== '') {
            $drawing['url'] = $urlDir . rawurlencode($chosenDrawing);
            $size = @getimagesize($scanDir . $chosenDrawing);
            if ($size && $size[0] && $size[1]) $drawing['orient'] = ($size[1] > $size[0]) ? 'portrait' : 'landscape';
        }

        // ── 公司全名／綁定 AS 文件名稱（與單製程列印同一組設定，表頭一致）──
        // 這是「總覽報告」不是單一份 AS 品質紀錄，故只借用文件名稱當標題，
        // 不印 AS 文件編號（編號只在單一製程列印時才印，見 inspection_entry_v2.php；
        // 2026-09-24 使用者明確要求本頁不顯示編號）。
        $company = '';
        $r = $pdo->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) $company = trim((string)($r['customer_full'] ?: $r['customer']));
        $docName = '製程檢驗總覽';
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='qc_inspection_as_doc_id' LIMIT 1");
        $s->execute();
        $docId = (int)($s->fetchColumn() ?: 0);
        if ($docId) {
            $d = $pdo->prepare("SELECT doc_name FROM as_document WHERE id=?");
            $d->execute([$docId]);
            if ($dn = $d->fetchColumn()) $docName = $dn;
        }

        // 包裝製程沒有登記廠商（ERP 不會替內部包裝站建發包資料），全製程合併列印前端會要求
        // 每一站都要有廠商名稱，這裡的固定值只補「製程＝包裝」那一列，其餘製程仍照實際登記的廠商
        // （2026-09-24 使用者交辦；設定入口見上方 pack_vendor_get/pack_vendor_save）。存的是
        // maker_id_no（廠商主檔 id），這裡即時反查目前的廠商名稱，改名或換廠商不必回頭補資料。
        $packVendorId = trim((string)($pdo->query(
            "SELECT setting_value FROM system_settings WHERE setting_key='qc_packing_vendor_id' LIMIT 1")->fetchColumn() ?: ''));
        $packVendorSetting = '';
        if ($packVendorId !== '') {
            $pvn = $pdo->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no=? LIMIT 1");
            $pvn->execute([$packVendorId]);
            $packVendorSetting = trim((string)($pvn->fetchColumn() ?: ''));
        }

        // 逐一組出「一個製程一筆」的資料結構；出貨檢驗(SHIP)是額外插入的一筆(不是真正的 bom_ing 製程，
        // 沒有「廠商」這個概念，$isShip=true 時不列入廠商必填檢查)，沿用同一套 batches/detail 組裝寫法
        // 但要素材源不同，抽成小函式兩處共用，避免複製兩份邏輯。
        $buildProcEntry = function ($fid, $label, $procQty, $makerId, $isExempt, $isShip = false) use ($formsByFid, $itemsByFid, $nameMap, $creatorOf) {
            $forms = $formsByFid[$fid] ?? [];
            $batches = [];
            foreach ($forms as $f) {
                $bn = (int)$f['batch_no'];
                if (!isset($batches[$bn])) $batches[$bn] = ['batch_no' => $bn, 'rounds' => []];
                $batches[$bn]['rounds'][] = [
                    'round_no' => (int)$f['round_no'], 'check_result' => $f['check_result'],
                    'insp_kind' => $f['insp_kind'] ?: 'NORMAL',
                    'ng_qty' => (int)$f['ng_qty'], 'date' => substr((string)($f['check_date'] ?: $f['created_at']), 0, 10),
                    'creator' => $nameMap[$creatorOf($f)] ?? '',
                ];
            }
            // last_form 只給封面總覽表用（判定＋檢驗人），使用者 2026-09-24 回報「上面製程列表不顯示
            // 檢驗人欄位」——原本直接回傳原始 SQL 列（created_by 是數字 id、也沒有解析過姓名），
            // 這裡另外整理成乾淨欄位，不可再回傳原始列。
            $lastF = $forms ? end($forms) : null;
            $lastFormOut = $lastF ? [
                'check_result' => $lastF['check_result'], 'insp_kind' => $lastF['insp_kind'] ?: 'NORMAL',
                'creator' => $nameMap[$creatorOf($lastF)] ?? '',
            ] : null;
            return [
                'bom_ing_fid' => $fid, 'bom_sn' => $label['sn'], 'process_name' => $label['name'],
                'proc_qty' => $procQty, 'maker_id' => $makerId, 'exempt' => $isExempt, 'is_packing' => false,
                'vendor_missing' => (!$isShip && trim((string)$makerId) === ''),
                'batches' => array_values($batches),
                'last_form' => $lastFormOut,
                // 完整模式：每一批每一輪各自一份明細（使用者 2026-09-24 回報只印最後一輪不夠，
                // 多批/複驗的紀錄都要各自印出），摘要模式或沒有紀錄時為空陣列。
                'details' => $itemsByFid[$fid] ?? [],
            ];
        };

        // 包裝製程一律不透過線上檢驗（qc_check_form）建立紀錄——已獨立成自己的檢驗流程
        // （qc_packing_inspection，見 views/pm/packing_schedule.php）——但合併列印仍要把包裝
        // 檢驗結果一併印出來，所以另外組一份、改讀 qc_packing_inspection（2026-09-24）。
        $buildPackingEntry = function ($fid, $label, $procQty, $makerId) use ($pdo, $packVendorSetting) {
            $pkRows = pk_packing_rows_for_fid($pdo, $fid);
            $judgeToResult = function ($j) { return $j === 'FAIL' ? 'NG' : ($j === 'PASS' ? 'OK' : 'HOLD'); };
            $batches = [];
            foreach ($pkRows as $i => $pr) {
                $batches[$i + 1] = ['batch_no' => $i + 1, 'rounds' => [[
                    'round_no' => 1, 'check_result' => $judgeToResult($pr['judgement']),
                    'ng_qty' => (int)$pr['ng_qty'], 'date' => substr((string)$pr['inspection_date'], 0, 10),
                    'creator' => $pr['packer'] ?: '',
                ]]];
            }
            $last = $pkRows ? end($pkRows) : null;
            // 包裝原本沒有登記廠商是常態（ERP 不會替內部包裝站建發包資料），沒有值才套用管理員設定的
            // 固定顯示名稱；真的有登記（少數包裝外包的情形）仍照實際資料顯示，不被設定值覆蓋。
            $effectiveMaker = (trim((string)$makerId) !== '') ? $makerId : $packVendorSetting;
            return [
                'bom_ing_fid' => $fid, 'bom_sn' => $label['sn'], 'process_name' => $label['name'],
                'proc_qty' => $procQty, 'maker_id' => $effectiveMaker, 'exempt' => false, 'is_packing' => true,
                'vendor_missing' => (trim((string)$effectiveMaker) === ''),
                'packing_open' => $last ? ($last['status'] !== 'closed') : false,
                'batches' => array_values($batches),
                'last_form' => $last ? ['check_result' => $judgeToResult($last['judgement']), 'creator' => $last['packer'] ?: ''] : null,
                'detail' => $pkRows ? ['sample_n' => 0, 'items' => [], 'packing_rows' => array_map(function ($pr) {
                    return [
                        'date' => substr((string)$pr['inspection_date'], 0, 10),
                        'order_qty' => (int)$pr['order_qty'], 'ok_qty' => (int)$pr['ok_qty'], 'ng_qty' => (int)$pr['ng_qty'],
                        'ship_now_qty' => (int)$pr['ship_now_qty'],
                        'warehouse_qty' => $pr['warehouse_qty'] !== null ? (int)$pr['warehouse_qty'] : null,
                        'judgement' => $pr['judgement'], 'status' => $pr['status'],
                        'packer' => $pr['packer'], 'inspector' => $pr['inspector'], 'remark' => $pr['remark'],
                    ];
                }, $pkRows)] : null,
            ];
        };

        $packingNos = pk_packing_process_nos($pdo);
        $processes = [];
        $shipInserted = !isset($formsByFid[$SHIP_FID]);   // 沒有出貨檢驗單就不必插入
        foreach ($procRows as $p) {
            $fid = (int)$p['bom_ing_fid'];
            $isPacking = in_array((int)$p['process_no'], $packingNos, true);
            // 出貨檢驗一律排在「包裝」製程之前（使用者拍板：獨立為成品出貨，位置在包裝前面）；
            // 一律以「包裝製程設定」判定是不是包裝，不再用製程名稱猜（比對字串較不可靠）；
            // 找不到包裝製程就排在最後（迴圈結束後補插）
            if (!$shipInserted && $isPacking) {
                $processes[] = $buildProcEntry($SHIP_FID, ['sn' => '', 'name' => '出貨檢驗'], null, null, false, true);
                $shipInserted = true;
            }
            $label = ['sn' => $p['bom_sn'], 'name' => $p['ProcessName'] ?: ('製程' . $p['process_no'])];
            $processes[] = $isPacking
                ? $buildPackingEntry($fid, $label, $p['proc_qty'], $p['maker_id'])
                : $buildProcEntry($fid, $label, $p['proc_qty'], $p['maker_id'], (int)$p['is_exclude_qc'] === 1);
        }
        if (!$shipInserted) $processes[] = $buildProcEntry($SHIP_FID, ['sn' => '', 'name' => '出貨檢驗'], null, null, false, true);

        echo json_encode(['success' => true, 'bom' => $bom, 'client' => $baseRow['Client_Name'], 'd_id' => $baseRow['d_id'],
            'total_qty' => (int)$baseRow['sqty'], 'company' => $company, 'doc_name' => $docName,
            'packing_vendor_id' => $packVendorId, 'packing_vendor_name' => $packVendorSetting,
            'drawing' => $drawing, 'processes' => $processes], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$bomParam = trim($_GET['bom'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>全製程合併列印</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<style>
:root{ --ink:#4A3524; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B; --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; }
body{ background:#F6F1EA; }
.warm-panel{ background:#fff; border:1px solid var(--line); border-radius:8px; padding:14px; margin:16px; }
.btn-warm{ background:var(--amber); border:1px solid var(--amber-d); color:#4A3524; font-weight:bold; }
.btn-warm:hover{ background:var(--amber-d); color:#fff; }
.muted-help{ color:#8a6a45; font-size:12px; }
.batch-chip{ display:inline-block; padding:4px 10px; margin:0 4px 4px 0; border-radius:14px; border:1px solid var(--line); background:#fff; font-size:12px; }
.st-ok{ color:#3c763d; font-weight:bold; } .st-ng{ color:var(--coral); font-weight:bold; }
/* 包裝廠商設定跳窗＋廠商模糊搜尋（照抄 qa_abnormal_form.php 的 m-mask/ac-list 寫法，不另發明一套） */
.m-mask{ position:fixed; inset:0; background:rgba(74,53,36,.45); z-index:10300; display:none; }
.m-box{ position:absolute; left:50%; top:10vh; transform:translateX(-50%); background:#fff; border-radius:8px;
        box-shadow:0 10px 30px rgba(0,0,0,.3); width:380px; }
.m-hd{ padding:10px 14px; border-bottom:1px solid var(--line); font-weight:bold; color:var(--ink); display:flex; align-items:center; gap:10px; }
.m-hd .x{ margin-left:auto; cursor:pointer; color:#8a7560; }
.m-bd{ padding:12px 14px; }
.m-ft{ padding:9px 14px; border-top:1px solid var(--line); text-align:right; }
.ac-wrap{ position:relative; }
.ac-list{ position:fixed; z-index:10400; background:#fff; border:1px solid var(--line); border-radius:4px;
          box-shadow:0 4px 14px rgba(120,90,50,.22); max-height:220px; overflow:auto; display:none; min-width:240px; }
.ac-list div{ padding:5px 10px; font-size:13px; cursor:pointer; border-bottom:1px solid #F3EADC; }
.ac-list div:hover{ background:var(--cream); }
.ac-list .hit{ color:var(--amber-d); font-weight:bold; }
</style>
</head>
<body>
<div class="warm-panel">
    <h3 style="margin-top:0;color:var(--ink);"><i class="fa fa-files-o"></i> 全製程合併列印
        <button class="btn btn-default btn-xs" id="btn-vendor-cfg" style="display:none;float:right;font-weight:normal;"><i class="fa fa-cog"></i> 設定</button></h3>
    <div class="muted-help" style="margin-bottom:10px;">依 BOM 號碼自動產生封面頁（上半圖面／下半各製程檢驗狀態總覽）；「封面＋完整實測數值」會接著印出每個<b>已經有檢驗紀錄</b>的製程明細，尚無紀錄的製程只會列在封面、不會印出空白明細。</div>
    <div class="form-inline" style="margin-bottom:10px;">
        <div class="form-group" style="margin-right:14px;">
            <label>BOM 號碼</label>
            <input type="text" class="form-control input-sm" id="inp-bom" value="<?= htmlspecialchars($bomParam, ENT_QUOTES, 'UTF-8') ?>" placeholder="B-1234567890" style="width:160px;">
            <button class="btn btn-default btn-sm" id="btn-load"><i class="fa fa-search"></i> 查詢</button>
        </div>
    </div>
    <div id="info-area" style="display:none;">
        <div id="info-bar" class="muted-help" style="margin-bottom:8px;"></div>
        <div id="vendor-warn" class="text-danger" style="display:none;margin-bottom:8px;font-size:12px;"></div>
        <div class="form-inline" style="margin-bottom:10px;">
            <div class="form-group" style="margin-right:18px;">
                <label>詳細度</label>
                <label class="radio-inline"><input type="radio" name="mode" value="summary" checked> 僅封面（圖面＋各製程狀態總覽）</label>
                <label class="radio-inline"><input type="radio" name="mode" value="full"> 封面＋完整實測數值（無檢驗紀錄的製程不列印明細）</label>
            </div>
            <div class="form-group" style="margin-right:18px;">
                <label>紙張</label>
                <label class="radio-inline"><input type="radio" name="paper" value="A4" checked> A4</label>
                <label class="radio-inline"><input type="radio" name="paper" value="A3"> A3（圖面較不會被縮太小）</label>
            </div>
            <div class="form-group">
                <label>方向</label>
                <label class="radio-inline"><input type="radio" name="orient" value="portrait" checked> 直式</label>
                <label class="radio-inline"><input type="radio" name="orient" value="landscape"> 橫式</label>
            </div>
        </div>
        <div id="drawing-pick-wrap" style="display:none;margin-bottom:10px;">
            <label>圖面（將印在封面上）：</label>
            <select class="form-control input-sm" id="sel-drawing" style="max-width:320px;display:inline-block;"></select>
            <span class="muted-help" id="drawing-auto-note" style="display:none;margin-left:6px;"><i class="fa fa-info-circle"></i> 已自動選用最新的一張，不是想要的那張請在上方更換</span>
            <div id="drawing-preview-wrap" style="margin-top:8px;display:none;">
                <img id="drawing-preview-img" style="max-width:260px;max-height:180px;border:1px solid #ccc;background:#fff;">
            </div>
        </div>
        <div id="no-drawing-hint" class="text-muted" style="display:none;margin-bottom:10px;"><i class="fa fa-exclamation-circle"></i> 找不到此 BOM 的圖面檔（Z:/BOM/ 內無檔名以此 BOM 號碼開頭的圖片），列印版將不含圖面。</div>
        <button class="btn btn-warm" id="btn-print"><i class="fa fa-print"></i> 列印 / 產生 PDF</button>
    </div>
</div>
<!-- 包裝廠商設定：綁定廠商主檔（存 maker_id_no），套用到所有沒登記廠商的包裝製程 -->
<div class="m-mask" id="vendorCfgMask">
    <div class="m-box">
        <div class="m-hd"><i class="fa fa-cog"></i> 包裝廠商設定<span class="x" id="vc-close">&times;</span></div>
        <div class="m-bd">
            <div class="muted-help" style="margin-bottom:8px;">包裝製程沒有登記廠商時，全製程合併列印固定顯示這裡指定的廠商（其餘製程仍照實際登記的廠商，不受影響）。</div>
            <div class="ac-wrap">
                <input type="text" class="form-control input-sm" id="vc-input" placeholder="輸入廠商代號或名稱搜尋…" autocomplete="off">
            </div>
            <div class="muted-help" style="margin-top:8px;" id="vc-current"></div>
        </div>
        <div class="m-ft">
            <button class="btn btn-default btn-sm" id="vc-clear"><i class="fa fa-eraser"></i> 清除設定</button>
            <button class="btn btn-default btn-sm" id="vc-close2">關閉</button>
        </div>
    </div>
</div>
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script>
var esc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
// 封面右下角「主管確認」圖章（使用者 2026-09-24 要求）：沿用線上檢驗單張列印同一套
// 主管自動核可設定（qc_auto_approve／qc_auto_approve_user，含代理解析），不另開一套判定——
// 直接呼叫同目錄 inspection_entry_v2.php 既有的 print_cfg_get（唯一實作，ai-rules/18）。
var APPROVER={ auto:0, name:'', deputy:0 };
function loadApprover(cb){
    $.post('inspection_entry_v2.php', { v2action:'print_cfg_get' }, function(res){
        if(res && res.success){
            APPROVER = { auto: !!res.auto_approve, name:(res.approver&&res.approver.name)||'', deputy: !!(res.approver&&res.approver.deputy) };
        }
        if(cb) cb();
    }, 'json').fail(function(){ if(cb) cb(); });
}
// 包裝製程沒有登記廠商是常態，管理員可在這裡綁定一個廠商主檔（存 maker_id_no，不存文字，
// 廠商改名或換掉都不必回來補資料；2026-09-24 使用者交辦「利用模糊搜尋ID或名稱列表給我選」）；
// 沒有「列印／主管審核設定」權限的人看不到這顆按鈕（後端 pack_vendor_save 同規則再擋一次）。
var PACK_VENDOR_ID='', PACK_VENDOR_NAME='';
function loadPackVendorCfg(){
    $.post('', { action:'pack_vendor_get' }, function(res){
        if(res && res.success){
            PACK_VENDOR_ID = res.id||''; PACK_VENDOR_NAME = res.name||'';
            $('#btn-vendor-cfg').toggle(!!res.can_edit);
        }
    }, 'json');
}
function renderVendorCfgCurrent(){
    $('#vc-current').html(PACK_VENDOR_ID
        ? ('目前綁定：<b>'+esc(PACK_VENDOR_ID)+'</b>　'+esc(PACK_VENDOR_NAME))
        : '目前未綁定，包裝製程缺廠商時仍會擋下列印。');
}
$('#btn-vendor-cfg').on('click', function(){
    $('#vc-input').val('');
    renderVendorCfgCurrent();
    $('#vendorCfgMask').show();
});
$('#vc-close,#vc-close2').on('click', function(){ $('#vendorCfgMask').hide(); });
$('#vc-clear').on('click', function(){
    $.post('', { action:'pack_vendor_save', id:'' }, function(res){
        if(!res.success){ alert(res.message||'儲存失敗'); return; }
        PACK_VENDOR_ID=''; PACK_VENDOR_NAME='';
        renderVendorCfgCurrent();
        if(DATA) loadData($('input[name=mode]:checked').val(), currentDrawingParam());
    }, 'json').fail(function(){ alert('伺服器錯誤，請稍後再試'); });
});
function saveVendorCfg(id, name){
    $.post('', { action:'pack_vendor_save', id:id }, function(res){
        if(!res.success){ alert(res.message||'儲存失敗'); return; }
        PACK_VENDOR_ID=id; PACK_VENDOR_NAME=name;
        renderVendorCfgCurrent();
        $('#vc-input').val('');
        if(DATA) loadData($('input[name=mode]:checked').val(), currentDrawingParam());
    }, 'json').fail(function(){ alert('伺服器錯誤，請稍後再試'); });
}
// 廠商模糊搜尋（照抄 QaAbnormal_API.php 的 search_vendor／qa_abnormal_form.php 的 acSetup 同一套寫法）
(function(){
    var $in=$('#vc-input'), tmr=null, $list=$('<div class="ac-list"></div>').appendTo('body');
    function place(){ var r=$in[0].getBoundingClientRect(); $list.css({ left:r.left+'px', top:(r.bottom+2)+'px', width:Math.max(r.width,240)+'px' }); }
    $in.on('input focus', function(){
        var kw=$in.val().trim();
        clearTimeout(tmr);
        tmr=setTimeout(function(){
            $.post('', { action:'vendor_search', kw:kw }, function(res){
                if(!res||!res.success||!res.rows.length){ $list.hide(); return; }
                $list.html(res.rows.map(function(r,i){
                    return '<div data-i="'+i+'"><span class="hit">'+esc(r.maker_id_no)+'</span>　'+esc(r.maker_id)
                        + (Number(r.internal)===1?' <span style="color:#C77C1A;">[廠內]</span>':'')+'</div>';
                }).join(''));
                $list.data('rows', res.rows); place(); $list.show();
            }, 'json');
        }, 220);
    });
    $list.on('mousedown', 'div', function(){
        var rows=$list.data('rows')||[], r=rows[$(this).data('i')];
        $list.hide();
        if(r) saveVendorCfg(r.maker_id_no, r.maker_id);
    });
    $in.on('blur', function(){ setTimeout(function(){ $list.hide(); }, 180); });
    $(window).on('scroll resize', function(){ if($list.is(':visible')) place(); });
})();
function trimNum(v){ // 小數尾 0 省略（3.50→3.5），比照全站慣例
    if(v===''||v==null) return '';
    var s=String(v); if(s.indexOf('.')<0) return s;
    s=s.replace(/0+$/,'').replace(/\.$/,''); return s===''||s==='-' ? '0' : s;
}
// 圖章日期：這是「這次列印」當下的確認，不是任何一筆檢驗自己的業務日期（多製程彙總報告沒有單一
// 業務日期可用），一律用列印當天，YYYY.MM.DD（ai-rules/20）。
function printTodayStr(){
    var n=new Date(), p=function(x){ return ('0'+x).slice(-2); };
    return n.getFullYear()+'.'+p(n.getMonth()+1)+'.'+p(n.getDate());
}
var DATA=null;

function loadData(mode, drawing, cb){
    var bom=$('#inp-bom').val().trim();
    if(!/^B-\d{10}$/.test(bom)){ alert('請輸入格式正確的 BOM：B-後接10位數字'); return; }
    $.post('', { action:'get_data', bom:bom, mode:(mode||'summary'), drawing:(drawing||'') }, function(res){
        if(!res.success){ alert(res.message||'查詢失敗'); return; }
        DATA=res;
        window.__ownCompany = res.company || '';   // eg_stamp.js 的印章公司名靠這個全域變數
        renderInfoBar();
        renderVendorWarn();
        renderDrawingPicker();
        $('#info-area').show();
        if(cb) cb();
    }, 'json').fail(function(){ alert('伺服器錯誤，請稍後再試'); });
}
// 出貨檢驗(SHIP)不是真正的 bom_ing 製程，用固定 sentinel bom_ing_fid=-1 識別，
// 統計「製程是否齊全」與封面上的免檢/尚無紀錄提示都要把它排除在外。
function isShipRow(p){ return p.bom_ing_fid===-1; }
// 廠商必填檢查（2026-09-24 使用者交辦「有無廠商名稱時要跳通知，要求補齊才能列印」）：
// 出貨檢驗不是真正的製程沒有廠商概念，後端 vendor_missing 已排除；包裝製程若管理員設了
// 固定顯示名稱，後端也已經套用過，這裡只是單純把仍缺廠商的那幾筆挑出來。
function vendorMissingList(){
    return (DATA.processes||[]).filter(function(p){ return p.vendor_missing; });
}
function renderVendorWarn(){
    var miss = vendorMissingList();
    if(!miss.length){ $('#vendor-warn').hide(); return; }
    $('#vendor-warn').html('<i class="fa fa-exclamation-triangle"></i> 下列製程尚未登記廠商名稱，全製程合併列印前必須補齊，否則無法列印：<br>'
        + miss.map(function(p){
            return '・'+esc(p.process_name)+(p.is_packing ? '　（按右上角「設定」填一個固定顯示的包裝廠商名稱）' : '　（請至該製程的發包資料補登廠商）');
        }).join('<br>')).show();
}
function renderInfoBar(){
    var okN=0, ngN=0, waitN=0, exemptN=0;
    DATA.processes.forEach(function(p){
        if(isShipRow(p)) return;
        if(p.exempt){ exemptN++; return; }
        if(!p.last_form){ waitN++; return; }
        if(p.last_form.check_result==='NG') ngN++; else okN++;
    });
    $('#info-bar').html('料號 <b>'+esc(DATA.d_id)+'</b>　客戶 <b>'+esc(DATA.client)+'</b>　BOM <b>'+esc(DATA.bom)+'</b>　總數 '+DATA.total_qty
        +'　共 '+DATA.processes.length+' 個製程（<span class="st-ok">合格 '+okN+'</span>　<span class="st-ng">不良 '+ngN+'</span>　尚未檢驗 '+waitN
        +(exemptN?('　已設定免檢 '+exemptN):'')+'）');
}
// 使用者 2026-09-24 回報「沒有讓我選擇要使用在報告上的圖面」：原本只有候選圖面 >1 張時才顯示
// 下拉、且沒有預設值時印出來就是「（無圖面）」——改成只要找得到候選圖面（含只有一張）就秀出
// 下拉＋縮圖預覽，讓使用者一眼看到目前要印的是哪一張；自動選到的（沒有明確點過下拉）額外標註，
// 並保留「不使用圖面」選項讓使用者可以明確選擇不印圖面（不是靠沒選到而已）。
function renderDrawingPicker(){
    var d=DATA.drawing;
    $('#no-drawing-hint').toggle(!d.candidates.length);
    if(d.candidates.length){
        var $sel=$('#sel-drawing').empty();
        $sel.append($('<option>').val('__NONE__').text('（不使用圖面）'));
        d.candidates.forEach(function(fn){ $sel.append($('<option>').val(fn).text(fn)); });
        $sel.val(d.chosen||'__NONE__');
        $('#drawing-auto-note').toggle(!!d.auto_picked);
        if(d.url){ $('#drawing-preview-img').attr('src', d.url); $('#drawing-preview-wrap').show(); }
        else { $('#drawing-preview-wrap').hide(); }
        $('#drawing-pick-wrap').show();
    } else {
        $('#drawing-pick-wrap').hide();
    }
}
// 已經查過一次之後，「圖面」欄位再送出要保留使用者的選擇（含明確選的「不使用圖面」）；
// chosen==='' 且有候選圖面時一定是使用者選了「不使用圖面」（自動挑選一律會把 chosen 填上），
// 要送 sentinel __NONE__ 讓後端不要又自動挑回去。
function currentDrawingParam(){
    if(!DATA) return '';
    var d=DATA.drawing;
    if(d.chosen) return d.chosen;
    return d.candidates.length ? '__NONE__' : '';
}
$('#btn-load').on('click', function(){ loadData('summary',''); });
$(document).on('keydown', '#inp-bom', function(e){ if(e.which===13){ e.preventDefault(); $('#btn-load').click(); } });
$(document).on('change', '#sel-drawing', function(){
    var mode=$('input[name=mode]:checked').val();
    loadData(mode, $(this).val());
});
$(document).on('change', 'input[name=mode]', function(){
    if(!DATA) return;
    loadData($(this).val(), currentDrawingParam());
});
$(function(){ loadApprover(); loadPackVendorCfg(); });
<?php if ($bomParam !== ''): ?>
$(function(){ loadData('summary',''); });
<?php endif; ?>

// ===================== 組列印 HTML（沿用 external_doc_list.php 的作法：開新視窗寫入，交瀏覽器原生分頁）=====================
// 使用者 2026-09-24 回報：同一批次第二筆（次數>1）原本一律標「重驗」，但那一筆若是首件/末件，
// 或前一筆根本不是不良（沒有 NG、沒有重工重送），標成「重驗」等於暗示有不良發生，是錯的；
// 首件/末件一律照 insp_kind 顯示，只有「前一筆判定是不良」時才算真正的重驗。
function roundTag(b, r, ri){
    if(r.insp_kind==='FIRST') return '首件';
    if(r.insp_kind==='LAST') return '末件';
    if(ri===0) return '第'+b.batch_no+'批';
    var prev = b.rounds[ri-1];
    return (prev && prev.check_result==='NG') ? '重驗' : ('第'+b.batch_no+'批續驗');
}
function batchTrailHtml(p){
    if(!p.batches.length) return '<span class="muted-help">'+(p.exempt?'（免檢）':'尚未檢驗')+'</span>';
    return p.batches.map(function(b){
        return b.rounds.map(function(r,ri){
            var cls=(r.check_result==='NG')?'pm-ng':'pm-ok';
            var lbl=(r.check_result==='NG')?'不良':(r.check_result==='HOLD'?'審核中':'合格');
            return '<span class="pm-chip '+cls+'">'+roundTag(b,r,ri)+' '+esc(r.date)+' '+lbl+'</span>';
        }).join('<span class="pm-arrow">→</span>');
    }).join('　');
}
function buildProcessSummaryRow(p, idx){
    var last=p.last_form;
    var judge = p.exempt ? '<span class="muted-help">已設定免檢</span>'
              : !last ? '<span class="pm-ng">✘ 尚無檢驗紀錄</span>'
              : (last.check_result==='NG' ? '<span class="pm-ng">✘ 不良</span>' : '<span class="pm-ok">✔ 合格</span>');
    // 包裝檢驗尚未結案時，在判定欄額外標一句提醒（列印仍照常進行，不阻擋）——2026-09-24
    if(p.is_packing && p.packing_open) judge += '<br><span class="pm-ng" style="font-size:9px;">（包裝尚未結案）</span>';
    var nameTxt = isShipRow(p) ? ('<b>'+esc(p.process_name)+'</b>') : esc(p.process_name);
    if(p.is_packing) nameTxt = esc(p.process_name)+'<span class="pm-pack-tag">包裝</span>';
    // 使用者 2026-09-24 要求：封面總覽表不顯示檢驗人（明細頁的每批每輪仍會各自標示）
    return '<tr'+(isShipRow(p)?' class="pm-ship-row"':'')+'><td>'+(idx+1)+'</td><td class="tl">'+nameTxt+'</td>'
        + '<td>'+esc(p.proc_qty||'')+'</td><td>'+esc(p.maker_id||'')+'</td>'
        + '<td class="tl">'+batchTrailHtml(p)+'</td>'
        + '<td>'+judge+'</td></tr>';
}
function buildProcessFullBlock(p, idx){
    var head='<div class="pm-proc-head">['+(idx+1)+'] '+esc(p.process_name)
        + (p.is_packing?'<span class="pm-pack-tag">包裝</span>':'')
        + '　送驗:'+esc(p.proc_qty||'')+'　廠商:'+esc(p.maker_id||'')+'</div>'
        + '<div class="pm-trail">'+batchTrailHtml(p)+'</div>';
    if(p.is_packing){
        var rows=(p.detail&&p.detail.packing_rows)||[];
        if(!rows.length) return head + '<div class="muted-help" style="margin:4px 0 14px;">尚無包裝檢驗紀錄</div>';
        var pbody='<table class="pm-items"><thead><tr><th>日期</th><th>訂單數</th><th>合格數</th><th>不良數</th>'
            + '<th>本次出貨</th><th>入庫</th><th>判定</th><th>結案狀態</th><th>包裝人員</th><th>品檢人員</th><th>備註</th></tr></thead><tbody>';
        rows.forEach(function(r){
            var judge2 = r.judgement==='FAIL' ? '<span class="pm-ng">不良</span>' : (r.judgement==='PASS' ? '合格' : '待判定');
            var stTxt = r.status==='closed' ? '已結案' : '<span class="pm-ng">未結案</span>';
            pbody += '<tr><td>'+esc(r.date)+'</td><td>'+r.order_qty+'</td><td>'+r.ok_qty+'</td><td>'+r.ng_qty+'</td>'
                + '<td>'+r.ship_now_qty+'</td><td>'+(r.warehouse_qty!=null?r.warehouse_qty:'')+'</td>'
                + '<td>'+judge2+'</td><td>'+stTxt+'</td><td>'+esc(r.packer||'')+'</td><td>'+esc(r.inspector||'')+'</td>'
                + '<td class="tl">'+esc(r.remark||'')+'</td></tr>';
        });
        pbody += '</tbody></table>';
        return head + pbody;
    }
    // 完整模式：每一批每一輪各自展開一份明細（使用者 2026-09-24 回報：齒研兩批檢驗只印出一批，
    // 原本只取「最後一批最後一輪」，改成 p.details 是陣列，逐筆各印一段，各自標出第幾批/第幾輪）。
    var blocks=p.details||[];
    if(!blocks.length){
        return head + '<div class="muted-help" style="margin:4px 0 14px;">尚無實測資料</div>';
    }
    var out='';
    blocks.forEach(function(d, bi){
        var n=d.sample_n;
        var pcsHead=''; for(var i=1;i<=n;i++) pcsHead+='<th>'+i+'</th>';
        // 同一批次內、緊接在前一筆的才算「同批次的上一輪」；tag 邏輯跟封面總覽同一套規則，
        // 不再看 round_no>1 就一律標「重驗」（使用者 2026-09-24 回報：首件接正式批不該叫重驗）。
        var prevInBatch = (bi>0 && blocks[bi-1].batch_no===d.batch_no) ? blocks[bi-1] : null;
        var tag = d.insp_kind==='FIRST' ? '首件' : d.insp_kind==='LAST' ? '末件'
            : !prevInBatch ? ('第'+d.batch_no+'批')
            : (prevInBatch.check_result==='NG' ? '重驗' : ('第'+d.batch_no+'批續驗'));
        // 使用者 2026-09-24 回報「每張檢驗表都要顯示檢驗人員」
        var roundTag = tag+'　'+esc(d.date||'')+'　'+(d.check_result==='NG'?'<span class="pm-ng">不良</span>':'合格')+
            '　<b>檢驗人：</b>'+esc(d.creator||'—');
        // 使用量具改成整個批次區塊印一行（量具是綁在整張檢驗單上，不是逐項）
        var toolLine = d.tools ? ('　<b>使用量具：</b>'+esc(d.tools)) : '';
        out += '<div class="pm-round-tag">'+roundTag+toolLine+'</div>';
        if(!d.items || !d.items.length){
            out += '<div class="muted-help" style="margin:2px 0 8px;">此批次尚無實測項目</div>';
            return;
        }
        var body='<table class="pm-items"><thead><tr><th class="c-no">項次</th><th>檢驗項目</th><th>標準</th><th class="c-tol">公差</th>'+pcsHead+'<th>判定</th></tr></thead><tbody>';
        d.items.forEach(function(it,i2){
            var code=String.fromCharCode(65+(i2%26));
            var cells=''; (it.samples||[]).forEach(function(sv){
                var v=(sv&&sv.v!=null&&sv.v!=='')?sv.v:'';
                cells+='<td'+((sv&&sv.r==='NG'&&v!=='')?' class="pm-ng-cell"':'')+'>'+esc(v)+'</td>';
            });
            // 公差輸入模式=RANGE(直接填絕對上下限)：標準欄改印「下限~上限」，公差欄留空，
            // 不然照舊印 it.std/it.up/it.lo 會是空的（RANGE 模式根本沒有這三個值）
            var isRange = it.mode==='RANGE';
            var stdTd = isRange ? (trimNum(it.min)+' ~ '+trimNum(it.max)) : (it.std||'');
            // 公差上下差同一欄，上差在上、下差另起一行（2026-09-24 使用者要求比照單製程檢驗記錄表
            // 的寫法，.c-tol .lo 靠下面 CSS display:block 換行，不是兩個獨立欄位）
            var tolTd = isRange ? '' : (esc(it.up||'')+(it.lo?('<span class="lo">'+esc(it.lo)+'</span>'):''));
            body+='<tr><td>'+code+'</td><td class="tl">'+esc(it.name)+'</td><td>'+esc(stdTd)+'</td>'
                + '<td class="c-tol">'+tolTd+'</td>'
                + cells + '<td>'+(it.verdict==='NG'?'<span class="pm-ng">NG</span>':(it.verdict==='AOD'?'特採':'OK'))+'</td></tr>';
        });
        body+='</tbody></table>';
        out += body;
    });
    return head+out;
}
$('#btn-print').on('click', function(){
    if(!DATA){ alert('請先查詢'); return; }
    var mode=$('input[name=mode]:checked').val();
    var paper=$('input[name=paper]:checked').val();
    var orient=$('input[name=orient]:checked').val();
    loadData(mode, currentDrawingParam(), function(){ doPrint(mode, paper, orient); });
});
function doPrint(mode, paper, orient){
    orient = (orient==='landscape') ? 'landscape' : 'portrait';
    // 有製程沒有登記廠商名稱一律擋下列印（2026-09-24 使用者交辦），不是只提醒——這是正式的
    // 品質紀錄，缺廠商就直接印出去會讓紙本永遠少這一欄；擋下的說明與畫面上的 #vendor-warn 同一套。
    var vmiss = vendorMissingList();
    if(vmiss.length){
        alert('尚有製程未登記廠商名稱，無法列印，請先補齊：\n'
            + vmiss.map(function(p){ return '・'+p.process_name+(p.is_packing?'（可到右上角「設定」填一個固定顯示的包裝廠商名稱）':''); }).join('\n'));
        return;
    }
    // 包裝檢驗尚未結案（可能還會再變動）時先提醒一次，但不阻擋列印——內容照現有資料照印
    // （使用者 2026-09-24 明確要求：先判定包裝檢驗紀錄是否結案，未結案跳提醒但不阻擋列印）。
    var openPacking=(DATA.processes||[]).filter(function(p){ return p.is_packing && p.packing_open; });
    if(openPacking.length){
        alert('提醒：以下包裝檢驗紀錄尚未結案（內容之後可能還會變動），仍會依目前內容列印：\n'
            + openPacking.map(function(p){ return '・'+p.process_name; }).join('\n'));
    }
    // 圖章要等掃描實體章對照表載完才產生，不然沒對照到的人會被存成預設 SVG 章、跟畫面上看到的不一樣
    // （eg_stamp.js 頂部註解的既有坑；這裡輸出到全新的彈出視窗，寫進去之後不會再自動升級）。
    EGStamp.whenReady(function(){ doPrintImpl(mode, paper, orient); });
}
function doPrintImpl(mode, paper, orient){
    var d=DATA.drawing;
    var drawingHtml = d.url ? '<img class="pm-drawing-img" src="'+esc(d.url)+'">' : '<div class="pm-no-drawing">（無圖面）</div>';

    var head = '<div class="pm-co">'+esc(DATA.company)+'</div>'
        + '<div class="pm-title">'+esc(DATA.doc_name)+'　封面</div>'
        + '<table class="pm-meta"><tr><td class="k">料號</td><td>'+esc(DATA.d_id)+'</td><td class="k">客戶</td><td>'+esc(DATA.client)+'</td>'
        + '<td class="k">BOM</td><td>'+esc(DATA.bom)+'</td><td class="k">總數</td><td>'+DATA.total_qty+'</td></tr></table>';

    // ===== 封面頁：A4 直式，上半是圖面（可由使用者從候選圖面挑選）、下半是本 BOM 全部製程的檢驗狀態總覽 =====
    // 無檢驗紀錄的製程不會印出明細，但一定要在這裡列出來，讓看的人知道「這個製程還沒驗」不是系統漏印。
    var sumTable = '<table class="pm-sumtable"><thead><tr><th>#</th><th>製程</th><th>數量</th><th>廠商</th><th>批次/重驗歷程</th><th>檢驗狀態</th></tr></thead><tbody>';
    DATA.processes.forEach(function(p,idx){ sumTable += buildProcessSummaryRow(p, idx); });
    sumTable += '</tbody></table>';
    // 主管確認圖章（使用者 2026-09-24 要求，右下角）：沿用「主管自動核可設定」，沒開啟或沒指定人時不印，
    // 空白留給現場手簽，不可硬造一個假的確認人（ai-rules/18）。
    var signBlock = (APPROVER.auto && APPROVER.name)
        ? '<div class="pm-sign">'+EGStamp.stamp(APPROVER.name, printTodayStr(), APPROVER.deputy)+'<div class="pm-sign-lbl">主管確認 Approved</div></div>'
        : '';
    var cover = '<div class="pm-cover'+(mode==='full'?' pm-cover-break':'')+'">'
        + head
        + '<div class="pm-cover-top"><div class="pm-drawing">'+drawingHtml+'</div></div>'
        + '<div class="pm-cover-bottom">'+sumTable+'</div>'
        + signBlock
        + '</div>';

    // ===== 明細頁：只印「已經有送出的檢驗紀錄」的製程／出貨檢驗，沒有紀錄的一律不印（使用者明確要求） =====
    var body='';
    if(mode==='full'){
        DATA.processes.forEach(function(p,idx){
            if(!p.last_form) return;
            body += '<div class="pm-proc-block">'+buildProcessFullBlock(p, idx)+'</div>';
        });
    }

    // 這是合併總覽報告不是單一份 AS 品質紀錄，故不印 AS 文件編號（2026-09-24 使用者明確要求）。
    var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:11px;line-height:1.2;}'
        + '.pm-co{font-size:20px;font-weight:bold;text-align:center;}'
        + '.pm-title{font-size:15px;font-weight:bold;text-align:center;margin:2px 0 6px;}'
        + '.pm-meta{width:100%;border-collapse:collapse;margin-bottom:4px;}'
        + '.pm-meta td{border:1px solid #000;padding:3px 6px;}'
        + '.pm-meta .k{background:#f0f0f0;font-weight:bold;white-space:nowrap;}'
        // 封面：A4 直式，上半圖面／下半製程狀態總覽（使用者明確要求的版面）
        + '.pm-cover{display:flex;flex-direction:column;min-height:0;}'
        + '.pm-cover-break{page-break-after:always;}'
        + '.pm-cover-top{text-align:center;margin:4mm 0 6mm;}'
        + '.pm-cover-top .pm-drawing-img{max-width:100%;max-height:130mm;}'
        + '.pm-no-drawing{color:#999;border:1px dashed #ccc;padding:20px;text-align:center;}'
        + '.pm-cover-bottom{flex:1 1 auto;}'
        + '.pm-sign{margin-top:6mm;text-align:right;}'
        // 圖章列印尺寸直接抄 ai-rules/18 第6條定案寫法，不自己重新推導、不加不必要的 !important
        + '.stamp-wrap svg,svg.car-stamp{width:91px;height:91px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.pm-sign-lbl{font-size:10px;color:#555;margin-top:2px;}'
        + '.pm-ship-row td{background:#FFF3E2;}'
        + 'table.pm-sumtable{width:100%;border-collapse:collapse;margin-top:6px;}'
        + 'table.pm-sumtable th,table.pm-sumtable td{border:1px solid #666;padding:4px 6px;text-align:center;}'
        + 'table.pm-sumtable thead th{background:#f3ead6;}'
        + 'table.pm-sumtable td.tl{text-align:left;}'
        + 'table.pm-sumtable thead{display:table-header-group;}'
        + 'table.pm-sumtable tr{break-inside:avoid;}'
        + '.pm-proc-block{break-inside:avoid-page;margin-top:10px;}'
        + '.pm-proc-head{font-size:13px;font-weight:bold;border-left:4px solid #F0A24B;padding-left:6px;margin-bottom:2px;}'
        + '.pm-trail{margin-bottom:4px;}'
        + '.pm-round-tag{font-size:11px;font-weight:bold;color:#4A3524;background:#FBF3E6;border-left:3px solid #E4D3BC;padding:2px 6px;margin:6px 0 2px;}'
        + '.pm-chip{display:inline-block;border:1px solid #ccc;border-radius:10px;padding:1px 8px;font-size:10px;margin-right:2px;}'
        + '.pm-arrow{margin:0 3px;color:#999;}'
        + '.pm-ok{color:#3c763d;font-weight:bold;} .pm-ng{color:#b9401f;font-weight:bold;}'
        + 'table.pm-items{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:4px;}'
        + 'table.pm-items th,table.pm-items td{border:1px solid #666;padding:2px 4px;text-align:center;}'
        + 'table.pm-items thead th{background:#eee;}'
        + 'table.pm-items thead{display:table-header-group;}'
        + 'table.pm-items td.tl{text-align:left;}'
        + 'table.pm-items th.c-tol{width:56px;} table.pm-items .c-tol .lo{display:block;}'
        + '.pm-ng-cell{color:#000;font-weight:bold;text-decoration:underline;}'
        + '.pm-pack-tag{display:inline-block;margin-left:6px;background:#F0A24B;color:#4A3524;border-radius:8px;padding:0 6px;font-size:9px;font-weight:bold;vertical-align:middle;}'
        + '@page{size:'+paper+' '+orient+';margin:12mm 10mm 18mm;}';

    var w=window.open('','_blank');
    w.document.write('<html><head><meta charset="utf-8"><title>全製程合併列印 - '+esc(DATA.bom)+'</title><style>'+css+'</style></head><body>'
        + cover + body
        + '<scr'+'ipt>window.onload=function(){'
        + 'var onePage=(297-30)*96/25.4;'
        + 'if(document.body.scrollHeight>onePage*0.9){'
        + 'var st=document.createElement(\'style\');'
        + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
        + 'document.head.appendChild(st);}'
        + 'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
    w.document.close();
}
</script>
</body>
</html>
