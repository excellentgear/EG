<?php
// =============================================================================
// ps_plan_lib.php — 待加工排程「生管預排／排序紀錄／是否依序加工」唯一實作（2026-10-29）
//
//  資料（migration：views/pm/migrations/2026-10-29_ps_plan.php）
//   bom_ing.plan_seq     生管排定的加工順序。與 processing_sequence（機台上的排序）分開存放，
//                        現場人員把卡片拉到機台／拉回未指派都不會動到它。
//   bom_ing.plan_urgent  生管手動設定的急件（原有急件設定來自 bom.priority_type／訂單，不動）。
//   bom_ing.pre_plan     預排：BOM 還沒移轉到這一關，生管先把這道製程排進未指派清單。
//   ps_plan_log          每次「實際有變動」的存檔留一筆快照：誰、何時、製程大類、依序的 BOM＋製程編號。
//
//  為什麼是「快照」不是逐筆異動：要判斷現場有沒有依序加工，需要知道「某個時間點當下生效的整份順序」，
//  逐筆異動得重播才還原得出來；一份清單最多幾十筆，快照很小。
//  「實際有變動」才記：順序、急件、預排名單與上一份快照完全相同就不記（只是開了視窗又關掉）。
//  同一天多次修改每次都記（每次都是一個新的有效版本，依序分析要用到）。
// =============================================================================

if (!function_exists('ps_plan_user')) {
    /** 目前登入者 [id, 顯示名稱]。 */
    function ps_plan_user(PDO $db): array {
        $id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
        $name = (string)($_SESSION['userName'] ?? '');
        try {
            $st = $db->prepare("SELECT COALESCE(NULLIF(user_cname,''), user_uname) FROM user WHERE id = ?");
            $st->execute([$id]);
            $n = $st->fetchColumn();
            if ($n) $name = (string)$n;
        } catch (Throwable $e) {}
        return [$id, $name];
    }
}

if (!function_exists('ps_plan_candidates')) {
    /**
     * 可手動加入預排的製程：此製程大類、自廠加工、BOM 未結案、這道製程還沒開始（state=N）也沒完工，
     * 而且目前不在待加工看板上。每筆附「這張 BOM 現在走到哪一關＋狀態」供參考。
     * $f: client / tag / part / bom（皆為模糊比對，空白＝不篩）
     */
    function ps_plan_candidates(PDO $db, int $typeId, array $f = [], int $limit = 500): array {
        if (!function_exists('ot_bom_filter_tag_sql')) @include_once __DIR__ . '/order_bom_filter_tag_lib.php';
        $tagSql = function_exists('ot_bom_filter_tag_sql') ? ot_bom_filter_tag_sql('b') : "NULL AS bom_filter_tag";
        $w = []; $p = [$typeId];
        // 客戶：名稱、客戶編號（ID）、公司全名都能模糊比對
        $cv = trim((string)($f['client'] ?? ''));
        if ($cv !== '') {
            $w[] = "(b.Client_Name LIKE ? OR b.Client_Name IN (SELECT cl.customer FROM customer_list cl WHERE cl.customer_id LIKE ? OR cl.customer_full LIKE ?))";
            $p[] = "%$cv%"; $p[] = "%$cv%"; $p[] = "%$cv%";
        }
        foreach (['part' => 'b.d_id', 'bom' => 'bi.bom'] as $k => $col) {
            $v = trim((string)($f[$k] ?? ''));
            if ($v !== '') { $w[] = "$col LIKE ?"; $p[] = '%' . $v . '%'; }
        }
        $tag = trim((string)($f['tag'] ?? ''));   // BOM篩選標籤：下拉選一個（與 BOM總覽同一個標示），完全相同才符合

        $sql = "SELECT bi.bom_ing_fid, bi.bom, bi.bom_sn, bi.process_no, pn.ProcessName, bi.sqty,
                       COALESCE(b.Delivery_date, (SELECT MIN(otm.Delivery_date) FROM bom_order_process_map bm JOIN order_track otm ON otm.Order_id = bm.order_id WHERE bm.bom = b.bom), otd.Delivery_date) AS ship_date, b.priority_type AS pri, " . ot_bom_order_urgent_sql('b') . ", b.Client_Name AS client, (SELECT cl2.customer_id FROM customer_list cl2 WHERE cl2.customer = b.Client_Name LIMIT 1) AS client_id, b.d_id AS part_no, $tagSql
                  FROM bom_ing bi
                  JOIN process_no pn ON pn.ProcessNo = bi.process_no AND pn.process_type_id = ?
                  LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no
                  JOIN bom b ON b.bom = bi.bom
                  LEFT JOIN order_track otd ON otd.Order_id = b.o_order_id
                 WHERE (b.processing_state <> 1 OR b.processing_state IS NULL)
                   AND (ml.internal IS NULL OR ml.internal = 1)   -- 外包廠商的不列（沒指定廠商的後面關卡照列）
                   AND (bi.processing_state IS NULL OR bi.processing_state IN ('N','','ing'))   -- Q(QC待驗)／P(生管待移轉)／E(已移轉)＝這道製程已經做完，不列
                   AND (bi.qc_completed IS NULL OR bi.qc_completed = 0)
                   AND bi.pre_plan = 0
                   AND bi.is_schedule_split = 0
                   AND NOT EXISTS (SELECT 1 FROM pm_process_daily_report r WHERE r.bom_ing_fid = bi.bom_ing_fid AND r.is_finished = 1)
                   AND bi.bom_ing_fid NOT IN (SELECT bom_ing_fid FROM vw_bom_ing WHERE processing_state = 'ing')"
              . ($w ? ' AND ' . implode(' AND ', $w) : '')
              . " ORDER BY bi.bom DESC, bi.bom_sn ASC";
        $st = $db->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // 下拉選項：套用其他篩選後還看得到的標籤（選了標籤也不會只剩自己）
        $tags = [];
        foreach ($rows as $r) { $t = (string)($r['bom_filter_tag'] ?? ''); if ($t !== '') $tags[$t] = 1; }
        $tags = array_keys($tags); sort($tags);
        if ($tag !== '') $rows = array_values(array_filter($rows, function ($r) use ($tag) { return (string)($r['bom_filter_tag'] ?? '') === $tag; }));

        // 這張 BOM 目前走到哪一關（沿用快速出貨的「目前製程」唯一實作）
        $prog = [];
        try {
            if (!function_exists('sq_bom_progress_map')) @include_once __DIR__ . '/shipping_lib.php';
            if (function_exists('sq_bom_progress_map')) $prog = sq_bom_progress_map($db, array_column($rows, 'bom'));
        } catch (Throwable $e) { error_log('[ps_plan_candidates] ' . $e->getMessage()); }
        // 每張 BOM 的關卡 bom_sn 清單（排除 skip），算「目前所在關卡到這道製程之間還有幾關」
        $snList = [];
        $bomsAll = array_values(array_unique(array_column($rows, 'bom')));
        if ($bomsAll) {
            $ph = implode(',', array_fill(0, count($bomsAll), '?'));
            $q = $db->prepare("SELECT bom, bom_sn FROM bom_ing WHERE bom IN ($ph) AND (processing_state IS NULL OR processing_state <> 'skip')");
            $q->execute($bomsAll);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $snList[$x['bom']][(int)$x['bom_sn']] = 1;
        }
        foreach ($rows as &$r) {
            $pg = $prog[$r['bom']] ?? null;
            $cur = (int)($pg['cur_sn'] ?? 0); $cs = (int)$r['bom_sn'];
            $n = 0;
            foreach (array_keys($snList[$r['bom']] ?? []) as $sn) if ($sn > $cur && $sn <= $cs) $n++;
            $r['steps_to'] = $n;   // 0＝已在這一關或已經過了；1＝下一關就是這道製程
            // 距離＝這道製程的 bom_sn（越後面的製程數字越大）減去這張 BOM 目前所在關卡的 bom_sn。
            // 離得越近（差距越小的正數）越上面；已經過了的（差距 ≤0，例如同一關或更早的關卡）排最後。
            $gap = (int)$r['bom_sn'] - (int)($pg['cur_sn'] ?? 0);
            $r['gap'] = $gap;
            $r['urg_base'] = ($r['pri'] ?? '') === 'E' ? 'E' : ((($r['pri'] ?? '') === 'U' || !empty($r['bom_order_urgent'])) ? 'U' : '');
            $r['cur_process'] = $pg ? ($pg['started'] ? $pg['process'] : '尚未開工') : '';
            $r['cur_state']   = $pg ? ($pg['started'] ? $pg['state_txt'] : '') : '';
            $r['stage']       = ps_plan_stage_text($pg);
        }
        unset($r);
        usort($rows, function ($a, $b) {
            $ga = $a['gap'] > 0 ? $a['gap'] : 100000 - $a['gap'];
            $gb = $b['gap'] > 0 ? $b['gap'] : 100000 - $b['gap'];
            return $ga <=> $gb ?: strcmp($b['bom'], $a['bom']) ?: ((int)$a['bom_sn'] <=> (int)$b['bom_sn']);
        });
        $rows = array_slice($rows, 0, (int)$limit);
        return ['rows' => $rows, 'tags' => $tags];
    }
}

if (!function_exists('ps_plan_stage_text')) {
    /** 「目前在：銑床 加工中」 */
    function ps_plan_stage_text(?array $pg): string {
        if (!$pg) return '';
        if (empty($pg['started'])) return '尚未開工';
        return trim(($pg['process'] !== '' ? $pg['process'] : '（未知製程）') . ' ' . $pg['state_txt']);
    }
}

if (!function_exists('ps_plan_stage_map')) {
    /** bom => 「銑床 加工中」，供看板上預排卡片顯示。 */
    function ps_plan_stage_map(PDO $db, array $boms): array {
        $out = [];
        try {
            if (!function_exists('sq_bom_progress_map')) @include_once __DIR__ . '/shipping_lib.php';
            if (!function_exists('sq_bom_progress_map')) return [];
            foreach (sq_bom_progress_map($db, $boms) as $bom => $pg) $out[$bom] = ps_plan_stage_text($pg);
        } catch (Throwable $e) { error_log('[ps_plan_stage_map] ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('ps_plan_preplan_fids')) {
    /** 目前有效的預排製程 fid（BOM 未結案、這道製程沒完工）。 */
    function ps_plan_preplan_fids(PDO $db): array {
        $st = $db->query("SELECT bi.bom_ing_fid FROM bom_ing bi JOIN bom b ON b.bom = bi.bom
                           WHERE bi.pre_plan = 1 AND (b.processing_state <> 1 OR b.processing_state IS NULL)
                             AND NOT EXISTS (SELECT 1 FROM pm_process_daily_report r WHERE r.bom_ing_fid = bi.bom_ing_fid AND r.is_finished = 1)");
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('ps_plan_add')) {
    /** 加入預排：只收 ps_plan_candidates 條件內的製程。回傳成功加入的 fid。 */
    function ps_plan_add(PDO $db, int $typeId, array $fids): array {
        $fids = array_values(array_unique(array_filter(array_map('intval', $fids))));
        if (!$fids) return [];
        $ok = [];
        $chk = $db->prepare("SELECT COUNT(*) FROM bom_ing bi JOIN process_no pn ON pn.ProcessNo = bi.process_no AND pn.process_type_id = ?
                              WHERE bi.bom_ing_fid = ? AND bi.pre_plan = 0 AND (bi.processing_state IS NULL OR bi.processing_state IN ('N','','ing'))");
        $max = (int)$db->query("SELECT COALESCE(MAX(bi.plan_seq),0) FROM bom_ing bi JOIN process_no pn ON pn.ProcessNo = bi.process_no
                                 WHERE pn.process_type_id = " . (int)$typeId . " AND bi.machine_id IS NULL AND bi.plan_seq IS NOT NULL")->fetchColumn();
        $up = $db->prepare("UPDATE bom_ing SET pre_plan = 1, machine_id = NULL, plan_seq = ?, processing_sequence = ? WHERE bom_ing_fid = ?");
        foreach ($fids as $fid) {
            $chk->execute([$typeId, $fid]);
            if (!(int)$chk->fetchColumn()) continue;
            $max++;
            $up->execute([$max, $max, $fid]);
            $ok[] = $fid;
        }
        return $ok;
    }
}

if (!function_exists('ps_plan_remove')) {
    /** 取消預排（已有報工的不可取消，因為那已經是真的在做了）。 */
    function ps_plan_remove(PDO $db, int $fid): bool {
        $c = $db->prepare("SELECT COUNT(*) FROM pm_process_daily_report WHERE bom_ing_fid = ?");
        $c->execute([$fid]);
        if ((int)$c->fetchColumn() > 0) return false;
        $st = $db->prepare("UPDATE bom_ing SET pre_plan = 0, plan_seq = NULL, plan_urgent = 0 WHERE bom_ing_fid = ? AND pre_plan = 1");
        $st->execute([$fid]);
        return $st->rowCount() > 0;
    }
}

if (!function_exists('ps_plan_save')) {
    /**
     * 儲存生管在「調整順序-未指派」排好的順序（含急件與預排名單），必要時留一筆紀錄。
     * @param int   $typeId  製程大類
     * @param array $order   bom_ing_fid 依排定順序
     * @param array $urgent  fid => 0/1（生管手動急件）
     * @param bool  $changed 前端判定使用者這次有沒有動過（沒動過就不記）
     * @return array ['logged'=>bool,'log_id'=>int|null]
     */
    function ps_plan_save(PDO $db, int $typeId, array $order, array $urgent, bool $changed): array {
        $order = array_values(array_unique(array_filter(array_map('intval', $order))));
        if (!$order) return ['logged' => false, 'log_id' => null];

        $in = implode(',', $order);
        // 只處理「確實屬於這個製程大類」的製程，擋掉亂送的 fid
        $rows = $db->query("SELECT bi.bom_ing_fid, bi.bom, bi.process_no, bi.pre_plan FROM bom_ing bi
                              JOIN process_no pn ON pn.ProcessNo = bi.process_no AND pn.process_type_id = " . (int)$typeId . "
                             WHERE bi.bom_ing_fid IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        $info = [];
        foreach ($rows as $r) $info[(int)$r['bom_ing_fid']] = $r;

        $items = [];
        $seq = 0;
        $upd = $db->prepare("UPDATE bom_ing SET plan_seq = ?, plan_urgent = ? WHERE bom_ing_fid = ?");
        $db->beginTransaction();
        try {
            foreach ($order as $fid) {
                if (!isset($info[$fid])) continue;
                $seq++;
                $u = !empty($urgent[$fid]) ? 1 : 0;
                $upd->execute([$seq, $u, $fid]);
                $items[] = [$fid, $info[$fid]['bom'], $info[$fid]['process_no'], $u, (int)$info[$fid]['pre_plan'] ? 1 : 0];
            }
            $logged = false; $logId = null;
            if ($changed && $items) {
                $json = json_encode($items, JSON_UNESCAPED_UNICODE);
                $last = $db->prepare("SELECT items FROM ps_plan_log WHERE process_type_id = ? ORDER BY log_id DESC LIMIT 1");
                $last->execute([$typeId]);
                $prev = $last->fetchColumn();
                if ($prev === false || $prev !== $json) {
                    [$uid, $uname] = ps_plan_user($db);
                    $ins = $db->prepare("INSERT INTO ps_plan_log (process_type_id, saved_at, saved_by, saved_by_name, n_items, items) VALUES (?, NOW(), ?, ?, ?, ?)");
                    $ins->execute([$typeId, $uid ?: null, $uname, count($items), $json]);
                    $logId = (int)$db->lastInsertId();
                    $logged = true;
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['logged' => $logged, 'log_id' => $logId];
    }
}

if (!function_exists('ps_plan_log_list')) {
    /** 排序紀錄清單（新→舊）。 */
    function ps_plan_log_list(PDO $db, string $from, string $to, int $typeId = 0, int $limit = 200): array {
        $w = ["l.saved_at >= ?", "l.saved_at < DATE_ADD(?, INTERVAL 1 DAY)"]; $p = [$from . ' 00:00:00', $to];
        if ($typeId > 0) { $w[] = "l.process_type_id = ?"; $p[] = $typeId; }
        $st = $db->prepare("SELECT l.log_id, l.process_type_id, pt.process_type AS type_name, l.saved_at, l.saved_by_name, l.n_items, l.items
                              FROM ps_plan_log l LEFT JOIN process_type pt ON pt.process_type_id = l.process_type_id
                             WHERE " . implode(' AND ', $w) . " ORDER BY l.log_id DESC LIMIT " . (int)$limit);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['items'] = json_decode($r['items'], true) ?: [];
        return $rows;
    }
}

if (!function_exists('ps_plan_analyze')) {
    /**
     * 現場是否依生管排定的順序加工。
     *
     * 判定（每一筆「開工」都對照「開工當下生效的那份順序」）：
     *  開工時間＝這道製程第一筆報工的 架機開始時間（沒有就用生產開始時間）。
     *  開工當下生效的順序＝這個製程大類在開工時間之前最近一次存的快照。
     *  跳過幾筆＝快照中排在它前面、但到它開工時還沒有開工（也沒完工）、而且在快照當時已經在未指派／預排的製程。
     *  跳過 0 筆＝依序；跳過 ≥1 筆＝未依序（插單／跳單）。
     *  開工當下不在快照裡＝「未納入排序」（生管沒排到的新製程，不判定對錯）。
     * 注意：生管排在前面的製程可能缺料或缺機台而無法開工，所以「未依序」是提示要去了解原因，不是定罪。
     */
    function ps_plan_analyze(PDO $db, string $from, string $to, int $typeId = 0): array {
        $w = ["l.saved_at < DATE_ADD(?, INTERVAL 1 DAY)"]; $p = [$to];
        if ($typeId > 0) { $w[] = "l.process_type_id = ?"; $p[] = $typeId; }
        // 取到區間結束為止的全部快照（區間開始前的也要，因為區間一開始生效的是更早那一份）
        $st = $db->prepare("SELECT l.log_id, l.process_type_id, l.saved_at, l.saved_by_name, l.items FROM ps_plan_log l
                             WHERE " . implode(' AND ', $w) . " ORDER BY l.process_type_id, l.saved_at, l.log_id");
        $st->execute($p);
        $logs = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['items'] = json_decode($r['items'], true) ?: [];
            $logs[(int)$r['process_type_id']][] = $r;
        }
        if (!$logs) return ['rows' => [], 'summary' => ['total' => 0, 'ordered' => 0, 'skipped' => 0, 'unlisted' => 0]];

        // 所有出現在快照裡的 fid 的開工時間（第一筆報工）
        $fids = [];
        foreach ($logs as $arr) foreach ($arr as $l) foreach ($l['items'] as $it) $fids[(int)$it[0]] = 1;
        $start = [];
        if ($fids) {
            $in = implode(',', array_keys($fids));
            $q = $db->query("SELECT bom_ing_fid,
                                    MIN(COALESCE(setup_start_time, production_start_time, CONCAT(report_date,' 00:00:00'))) AS t0,
                                    MIN(CASE WHEN is_finished = 1 THEN COALESCE(production_end_time, setup_end_time, CONCAT(report_date,' 23:59:59')) END) AS t_fin,
                                    MIN(report_id) AS rid
                               FROM pm_process_daily_report WHERE bom_ing_fid IN ($in) GROUP BY bom_ing_fid");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $start[(int)$r['bom_ing_fid']] = $r;
        }
        // 實際開工的製程（含不在任何快照裡的，用來標示「未納入排序」）：區間內開工、屬於這些大類
        $types = implode(',', array_map('intval', array_keys($logs)));
        $actual = $db->prepare("SELECT r.bom_ing_fid, bi.bom, bi.process_no, pn.process_type_id,
                                       MIN(COALESCE(r.setup_start_time, r.production_start_time, CONCAT(r.report_date,' 00:00:00'))) AS t0
                                  FROM pm_process_daily_report r
                                  JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
                                  JOIN process_no pn ON pn.ProcessNo = bi.process_no
                                 WHERE pn.process_type_id IN ($types)
                                 GROUP BY r.bom_ing_fid, bi.bom, bi.process_no, pn.process_type_id
                                HAVING t0 >= ? AND t0 < DATE_ADD(?, INTERVAL 1 DAY)
                                 ORDER BY t0");
        $actual->execute([$from . ' 00:00:00', $to]);

        $typeNames = [];
        foreach ($db->query("SELECT process_type_id, process_type FROM process_type")->fetchAll(PDO::FETCH_ASSOC) as $r) $typeNames[(int)$r["process_type_id"]] = $r["process_type"];

        $rows = []; $sum = ['total' => 0, 'ordered' => 0, 'skipped' => 0, 'unlisted' => 0];
        foreach ($actual->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $tid = (int)$a['process_type_id']; $fid = (int)$a['bom_ing_fid']; $t0 = $a['t0'];
            // 開工當下生效的快照
            $snap = null;
            foreach (($logs[$tid] ?? []) as $l) { if ($l['saved_at'] <= $t0) $snap = $l; else break; }
            $row = ['fid' => $fid, 'bom' => $a['bom'], 'process_no' => $a['process_no'], 'type_id' => $tid, 'type_name' => $typeNames[$tid] ?? '',
                    'start' => $t0, 'plan_at' => $snap['saved_at'] ?? null, 'plan_by' => $snap['saved_by_name'] ?? null,
                    'rank' => null, 'skipped' => null, 'skipped_list' => [], 'result' => 'nolog'];
            $sum['total']++;
            if (!$snap) { $row['result'] = 'nolog'; $sum['unlisted']++; $rows[] = $row; continue; }
            $rank = null;
            foreach ($snap['items'] as $i => $it) if ((int)$it[0] === $fid) { $rank = $i + 1; break; }
            if ($rank === null) { $row['result'] = 'unlisted'; $sum['unlisted']++; $rows[] = $row; continue; }
            $row['rank'] = $rank;
            foreach ($snap['items'] as $i => $it) {
                if ($i + 1 >= $rank) break;
                $f2 = (int)$it[0];
                $s2 = $start[$f2] ?? null;
                $startedBefore = $s2 && $s2['t0'] !== null && $s2['t0'] <= $t0;     // 排在前面的，已經開工過了
                $finishedBefore = $s2 && $s2['t_fin'] !== null && $s2['t_fin'] <= $t0;
                if (!$startedBefore && !$finishedBefore) $row['skipped_list'][] = $it[1];
            }
            $row['skipped'] = count($row['skipped_list']);
            if ($row['skipped'] === 0) { $row['result'] = 'ok'; $sum['ordered']++; }
            else { $row['result'] = 'skip'; $sum['skipped']++; }
            $rows[] = $row;
        }
        return ['rows' => $rows, 'summary' => $sum];
    }
}
