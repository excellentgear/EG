<?php
/**
 * part_scope_lib.php — 「這一次要看的是哪一筆料號主檔」的唯一實作點（2026-08-28 建立）
 * ---------------------------------------------------------------------------
 * 為什麼要有這支（使用者 2026-08-28 回報的現場問題）：
 *   圖面查閱（bom_viewer.php／part_viewer.php）一直是用**料號文字**歸戶的：
 *       SELECT d_id FROM d_setting WHERE D_Setting_Id = ?
 *   這一句會把「所有同名的主檔列」整包撈出來，再拿去撈附件／報價／訂單，
 *   於是**不同客戶、不同版次的資料全部混在同一個畫面上**。
 *   實測（2026-08-28）：d_setting 12850 列中，989 個料號文字對到 2240 列主檔，
 *   其中 983 組的客戶是真的不同（不是重複建檔）。
 *   例：TW-1201100 有三筆＝東展/東峰(4550)、登裕(5357)、府毅(15177)。
 *
 * 所以歸戶鍵一律改成 **d_setting.d_id（整數 PK）**，而不是料號文字。
 *
 * 呼叫端只有文字料號時（未綁定料號主檔的訂單／報價品項／BOM，各佔 4~8 成）怎麼辦：
 *   **預設鎖定最新建立的那一筆**，並把其餘候選一起回傳，由畫面頂端長出切換器讓使用者自己換。
 *   （使用者拍板：不可以靜靜地只顯示一筆而不告訴人有別家，也不要繼續全部混在一起。）
 *
 * 圖面（BOM 圖檔）分頁是例外，不能照 PK 硬篩：
 *   `bom.d_setting_id` 有 9694/11858 ＝ **82% 是空的**（且有填的偶爾與 Client_Name 對不起來，
 *   例 B-1150512005 的 d_setting_id=4550 東展/東峰、Client_Name 卻是登裕）。
 *   使用者拍板：**有綁的照 PK 篩，沒綁的仍然列出但標示「未綁主檔」**，
 *   否則 82% 的舊圖面會直接從畫面上消失。判定走 eg_part_scope_bom_rows()。
 *
 * 用法：
 *   $scope = eg_part_scope_resolve($pdo, $pk, $partNoText);
 *   ... WHERE pa.d_id IN ($scope['dids'])            // 附件／報價／訂單一律用這個
 *   $bomRows = eg_part_scope_bom_rows($pdo, $scope); // 圖面用這個
 */

if (!function_exists('eg_part_scope_resolve')) {
    /**
     * 決定這次要看的是哪一筆料號主檔，並算出要拿去查資料的 d_id 集合。
     *
     * @param PDO        $db
     * @param int|string $pk     d_setting.d_id（整數 PK）。0/空＝呼叫端只有文字料號
     * @param string     $partNo 料號文字（$pk 有給時仍可留空，會自己回查）
     * @return array {
     *   pk         int      選定的 d_setting.d_id（0＝該料號沒有建主檔）
     *   part_no    string   選定那筆的料號文字
     *   exact      bool     true＝由呼叫端指名 PK；false＝只有文字、由本庫挑了最新一筆
     *   candidates array    同名料號的全部主檔列（供畫面長切換器；只有 1 筆時不必顯示）
     *   dids       int[]    要拿去撈附件／報價／訂單的 d_id（＝選定那筆＋別名綁定帶入的）
     *   part_nos   string[] 要拿去做文字比對的料號（＝選定那筆＋別名綁定帶入的）
     *   bind_label_by_did     array d_id    => '料號／客戶'（別名帶入才有，供畫面標來源）
     *   bind_label_by_part_no array part_no => '料號／客戶'
     * }
     */
    function eg_part_scope_resolve(PDO $db, $pk, string $partNo = ''): array {
        require_once __DIR__ . '/part_alias_lib.php';
        $pk     = (int)$pk;
        $partNo = trim($partNo);

        $out = [
            'pk' => 0, 'part_no' => $partNo, 'exact' => false,
            'candidates' => [], 'dids' => [], 'part_nos' => [],
            'bind_label_by_did' => [], 'bind_label_by_part_no' => [],
        ];

        // ── 1. 指名 PK：直接用它，並回查料號文字 ───────────────────────────
        if ($pk > 0) {
            try {
                $st = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id = ?");
                $st->execute([$pk]);
                if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $out['pk']      = (int)$r['d_id'];
                    $out['part_no'] = (string)$r['D_Setting_Id'];
                    $out['exact']   = true;
                } else {
                    $pk = 0;   // 傳了不存在的 PK（資料被刪）→ 退回文字模式，不要整頁空白
                }
            } catch (Exception $e) { $pk = 0; }
        }
        $partNo = $out['part_no'];

        // ── 2. 同名料號的全部候選（含指名 PK 的情況，畫面才知道還有別家）──────
        if ($partNo !== '') {
            try {
                $st = $db->prepare(
                    "SELECT d.d_id, d.D_Setting_Id, d.Customer_Id, d.Revision, d.Remark, d.Created_At,
                            c.customer AS customer_name
                       FROM d_setting d
                       LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                      WHERE d.D_Setting_Id = ?
                      ORDER BY d.Created_At DESC, d.d_id DESC");
                $st->execute([$partNo]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out['candidates'][] = [
                        'd_id'          => (int)$r['d_id'],
                        'part_no'       => (string)$r['D_Setting_Id'],
                        'customer_id'   => (string)($r['Customer_Id'] ?? ''),
                        'customer_name' => (string)($r['customer_name'] ?? ''),
                        'revision'      => (string)($r['Revision'] ?? ''),
                        'remark'        => (string)($r['Remark'] ?? ''),
                        'created_at'    => (string)($r['Created_At'] ?? ''),
                    ];
                }
            } catch (Exception $e) { /* 候選查不到不影響主流程 */ }
        }

        // ── 3. 沒指名 PK：挑最新建立的那一筆當預設（畫面會長切換器讓人改）─────
        if ($out['pk'] <= 0 && $out['candidates']) {
            $out['pk'] = $out['candidates'][0]['d_id'];
        }

        // ── 4. 別名（客戶代號／等同料號）帶入：一律只從「選定的那一筆」展開 ────
        //     不可以再用文字去展開，否則等於把別家的別名也一起撈進來＝白修
        $dids    = $out['pk'] > 0 ? [$out['pk']] : [];
        $partNos = $partNo !== '' ? [$partNo] : [];
        if ($out['pk'] > 0) {
            try {
                $related = eg_part_alias_related_dids($db, $out['pk']);
                $extra   = array_values(array_diff($related, [$out['pk']]));
                if ($extra) {
                    $ph = implode(',', array_fill(0, count($extra), '?'));
                    $q  = $db->prepare("SELECT d.d_id, d.D_Setting_Id, c.customer AS customer_name
                                          FROM d_setting d
                                          LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                                         WHERE d.d_id IN ($ph)");
                    $q->execute($extra);
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $label = $r['D_Setting_Id'] . ($r['customer_name'] ? '／' . $r['customer_name'] : '');
                        $out['bind_label_by_did'][(int)$r['d_id']]        = $label;
                        $out['bind_label_by_part_no'][$r['D_Setting_Id']] = $label;
                        $dids[]    = (int)$r['d_id'];
                        $partNos[] = (string)$r['D_Setting_Id'];
                    }
                }
            } catch (Exception $e) { /* 別名查不到不影響主流程 */ }
        }

        $out['dids'] = array_values(array_unique(array_filter($dids)));
        $pnList = [];
        foreach (array_unique($partNos) as $pn) { if ($pn !== '') $pnList[] = $pn; }
        $out['part_nos'] = $pnList;
        return $out;
    }
}

if (!function_exists('eg_part_scope_bom_rows')) {
    /**
     * 取這次要顯示的 BOM 列（圖面分頁用）。
     *
     * 為什麼不能直接 `WHERE d_setting_id = pk`：
     *   bom.d_setting_id 有 82%（9694/11858）是空的，硬篩會讓大部分舊圖面消失。
     * 所以規則是（使用者 2026-08-28 拍板）：
     *   ① 有填 d_setting_id 的 → 只留等於選定 PK（或別名帶入的 d_id）那些
     *   ② 沒填 d_setting_id 的 → 仍然列出，但回傳 unbound=1 讓畫面標「未綁主檔」
     *
     * @return array 每列 bom 欄位＋ unbound(0/1) ＋ bind_from（別名帶入時的來源標示）
     */
    function eg_part_scope_bom_rows(PDO $db, array $scope, array $extraCols = []): array {
        $partNos = $scope['part_nos'] ?? [];
        if (!$partNos) return [];
        $cols = array_merge(['bom', 'sqty', 'd_id', 'd_setting_id', 'Client_Name'], $extraCols);
        $cols = array_values(array_unique($cols));
        $ph   = implode(',', array_fill(0, count($partNos), '?'));
        $sql  = "SELECT " . implode(', ', $cols) . " FROM bom WHERE d_id IN ($ph) ORDER BY Created_At DESC";
        try {
            $st = $db->prepare($sql);
            $st->execute(array_values($partNos));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return []; }

        $allow = array_flip($scope['dids'] ?? []);
        $out   = [];
        foreach ($rows as $r) {
            $dsid = (int)($r['d_setting_id'] ?? 0);
            if ($dsid > 0 && !isset($allow[$dsid])) continue;   // 明確屬於別筆主檔 → 不列
            $r['unbound']   = $dsid > 0 ? 0 : 1;                // 未綁主檔 → 列出但標示
            $r['bind_from'] = $scope['bind_label_by_did'][$dsid] ?? null;
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('eg_part_scope_counts')) {
    /**
     * 每一筆候選主檔各自有多少資料（附件數／BOM 數），供切換器標示。
     *
     * 為什麼一定要有：預設是選「最新建立」的那一筆，而最新建立的那筆常常是空的
     * （例：插齒刀 6 筆主檔，附件掛在最舊的兩筆上）。不標示的話，使用者從舊呼叫端點進來
     * 會看到空白畫面，又不知道要去下拉裡換哪一筆＝比原本全部混在一起還難用。
     *
     * @return array d_id => ['attach'=>int, 'bom'=>int]
     */
    function eg_part_scope_counts(PDO $db, array $candidates): array {
        $out = [];
        $dids = [];
        foreach ($candidates as $c) { $dids[] = (int)$c['d_id']; }
        $dids = array_values(array_unique(array_filter($dids)));
        if (!$dids) return $out;
        foreach ($dids as $d) $out[$d] = ['attach' => 0, 'bom' => 0];
        $ph = implode(',', array_fill(0, count($dids), '?'));
        try {
            $st = $db->prepare("SELECT d_id, COUNT(*) c FROM part_attachments
                                 WHERE d_id IN ($ph) AND deleted_at IS NULL GROUP BY d_id");
            $st->execute($dids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['d_id']]['attach'] = (int)$r['c'];
        } catch (Exception $e) { /* 統計失敗不影響主流程 */ }
        try {
            $st = $db->prepare("SELECT d_setting_id, COUNT(*) c FROM bom
                                 WHERE d_setting_id IN ($ph) GROUP BY d_setting_id");
            $st->execute($dids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['d_setting_id']]['bom'] = (int)$r['c'];
        } catch (Exception $e) { /* 統計失敗不影響主流程 */ }
        return $out;
    }
}

if (!function_exists('eg_part_scope_label')) {
    /** 候選列的顯示文字：客戶／版次／備註，供切換器與提示條共用（前後端同一份口徑）。 */
    function eg_part_scope_label(array $cand, ?array $cnt = null): string {
        $bits = [];
        if (($cand['customer_name'] ?? '') !== '')   $bits[] = $cand['customer_name'];
        elseif (($cand['customer_id'] ?? '') !== '') $bits[] = $cand['customer_id'];
        if (($cand['revision'] ?? '') !== '') $bits[] = '版次 ' . $cand['revision'];
        if (($cand['remark'] ?? '')   !== '') $bits[] = mb_substr($cand['remark'], 0, 20);
        if (!$bits) $bits[] = '（無客戶）';
        $s = implode('｜', $bits);
        if ($cnt !== null) {
            $parts = [];
            if (!empty($cnt['bom']))    $parts[] = 'BOM ' . $cnt['bom'];
            if (!empty($cnt['attach'])) $parts[] = '附件 ' . $cnt['attach'];
            $s .= $parts ? '（' . implode('、', $parts) . '）' : '（無資料）';
        }
        return $s;
    }
}
