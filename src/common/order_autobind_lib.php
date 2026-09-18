<?php
/**
 * order_autobind_lib.php — 訂單追蹤「快速綁定」的候選查詢與「批次自動綁定」判定（唯一實作）
 *
 * 【為什麼要有這支】
 *   快速綁定跳窗（NewOrder_Track.php 的 quick_bind_lookup）與批次自動綁定用的是
 *   同一套「這個客戶名稱／料號文字對應到哪幾筆主檔」的判定。兩邊各寫一份 SQL 遲早走鐘，
 *   走鐘的後果是「跳窗上看到兩個候選、批次卻自動綁了其中一個」——那是最不該發生的事。
 *   故候選查詢一律走本檔，禁止在頁面裡再寫一份（鐵律4）。
 *
 * 【自動綁定的判定（2026-09-18 使用者交辦）】
 *   使用者要的是「快速綁定裡只有一個項目的，直接幫我綁完」。但實測證明
 *   「候選只有一筆」不等於「那一筆就是對的」——抽樣 500 張未綁定訂單中有 1 張是：
 *   訂單客戶「立翔」、料號文字 RT18，模糊比對只命中「全宏」的 RT18-2201-00_C。
 *   所以自動綁定在「候選各只有一筆」之外，另外要求兩件事：
 *     ① 料號文字與主檔【完全相同】（D_Setting_Id／Drawing_No／別名任一完全相同），只是模糊命中的一律不綁
 *     ② 料號主檔的客戶【沒有衝突】（主檔沒綁客戶、或就是這一家）
 *   以此規則重跑抽樣 500 張：可綁 331 張、誤綁 0 張。
 *
 * 【絕不做的事】（使用者交代「嚴禁影響現有使用者」）
 *   - 只補 Client_name_ID／d_id_ID 兩個綁定欄位，不動 quote_no／unit_price／數量／交期
 *   - 不動 Modified_By／Modified_At（那是訂單變更比對的基準，全站慣例，見 kpi_as_lib.php 同段註解）
 *   - 寫入時 WHERE 再檢查一次「這張單現在還是未綁定」，別人剛手動綁好的一律不覆蓋
 *   - 已綁定的訂單一張都不碰，所以完全不會走到「更改已建立訂單的客戶」那套解鎖流程
 */

if (!function_exists('ot_ab_find_customers')) {
    /**
     * 依客戶名稱找客戶主檔候選（與快速綁定跳窗完全同一套查法）
     */
    function ot_ab_find_customers(PDO $pdo, string $clientName): array {
        $clientName = trim($clientName);
        if ($clientName === '') return [];
        $st = $pdo->prepare("SELECT customer_id, customer FROM customer_list
                             WHERE customer LIKE ? AND is_inactive = 0
                             ORDER BY customer_id LIMIT 10");
        $st->execute(["%$clientName%"]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('ot_ab_find_parts')) {
    /**
     * 依料號文字找料號主檔候選（與快速綁定跳窗完全同一套查法）
     *
     * @param string|null $boundCustomerId 訂單若已綁客戶，候選只列該客戶底下的料號（跳窗原本的行為）
     *
     * 別名（d_setting_alias）原本寫成 WHERE ... OR EXISTS(子查詢)，那個相關子查詢會對
     * d_setting 全表 23,803 列各跑一次，單次查詢 38.9ms；改成先查這張只有數十列的小表、
     * 再把命中的 d_id 併進 IN 清單後為 9.7ms。命中集合完全相同（已對真實資料逐筆比對驗證），
     * 批次要跑幾千次，這個差別是 2 分鐘與 8 分鐘的差別。
     */
    function ot_ab_find_parts(PDO $pdo, string $partText, $boundCustomerId = null): array {
        $partText = trim($partText);
        if ($partText === '') return [];
        $like = "%$partText%";

        // 別名命中的 d_id（小表，直接撈出來併進主查詢）
        $aliasIds = [];
        try {
            $as = $pdo->prepare("SELECT DISTINCT d_id FROM d_setting_alias WHERE alias_code LIKE ?");
            $as->execute([$like]);
            foreach ($as->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                $aid = (int)$aid; if ($aid > 0) $aliasIds[$aid] = true;
            }
        } catch (Exception $e) { /* 別名表有問題時退回只比料號與圖號 */ }

        $aliasSql = $aliasIds ? (' OR d.d_id IN (' . implode(',', array_keys($aliasIds)) . ')') : '';
        $custSql  = ($boundCustomerId !== null && $boundCustomerId !== '') ? ' AND d.Customer_Id = :cid' : '';

        $sql = "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No,
                       d.Customer_Id AS customer_id, c.customer AS client_name,
                       d.Is_Assembly,
                       (SELECT a.alias_code FROM d_setting_alias a WHERE a.d_id=d.d_id AND a.alias_code LIKE :al LIMIT 1) AS alias_hit,
                       EXISTS(SELECT 1 FROM d_setting_bom bb WHERE bb.child_d_id = d.d_id) AS Is_Bom_Child
                FROM d_setting d
                LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
                WHERE (d.D_Setting_Id LIKE :p1 OR d.Drawing_No LIKE :p2{$aliasSql}){$custSql}
                ORDER BY CASE WHEN d.D_Setting_Id = :exact THEN 0 ELSE 1 END, d.D_Setting_Id
                LIMIT 50";
        $st = $pdo->prepare($sql);
        $st->bindValue(':al', $like);
        $st->bindValue(':p1', $like);
        $st->bindValue(':p2', $like);
        $st->bindValue(':exact', $partText);
        if ($custSql !== '') $st->bindValue(':cid', $boundCustomerId);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('ot_ab_is_exact_part')) {
    /** 料號文字是否與這筆主檔【完全相同】（料號／圖號／別名任一完全相同即可；不分大小寫、去頭尾空白） */
    function ot_ab_is_exact_part(array $part, string $text): bool {
        $t = trim($text);
        if ($t === '') return false;
        foreach (['D_Setting_Id', 'Drawing_No', 'alias_hit'] as $f) {
            $v = trim((string)($part[$f] ?? ''));
            if ($v !== '' && strcasecmp($v, $t) === 0) return true;
        }
        return false;
    }
}

if (!function_exists('ot_ab_reason_labels')) {
    /**
     * 判定結果代碼 → 畫面文字（唯一實作）
     * 前端的統計徽章直接用 API 回傳的這份對照，不要在 JS 再抄一份中文（抄了就會有兩種說法）。
     */
    function ot_ab_reason_labels(): array {
        return [
            'ok'              => '可自動綁定',
            'no_client_text'  => '訂單沒有客戶名稱，無法比對',
            'no_part_text'    => '訂單沒有料號文字，無法比對',
            'cust_none'       => '客戶主檔找不到這個名稱',
            'cust_many'       => '客戶有多個候選，需人工選擇',
            'part_none'       => '料號主檔找不到這個料號',
            'part_many'       => '料號有多個候選，需人工選擇',
            'part_fuzzy'      => '料號只是部分相符（非完全相同），需人工確認',
            'part_other_cust' => '料號屬於別的客戶，需人工確認',
            'raced'           => '寫入前已被其他人綁定，已略過',
        ];
    }
}

if (!function_exists('ot_ab_reason_label')) {
    function ot_ab_reason_label(string $code): string {
        $m = ot_ab_reason_labels();
        return $m[$code] ?? $code;
    }
}

if (!function_exists('ot_ab_judge')) {
    /**
     * 判定單一訂單能不能自動綁定
     *
     * @param array $order  需含 Order_id/Order_oo/Client_name/Client_name_ID/d_id/d_id_ID
     * @param array $cache  同一批次內重複的「客戶名稱／料號文字」查詢結果快取（傳址）
     * @return array ['ok'=>bool,'reason'=>code,'customer'=>?array,'part'=>?array,
     *                'need_client'=>bool,'need_part'=>bool,'fill_part_customer'=>bool]
     */
    function ot_ab_judge(PDO $pdo, array $order, array &$cache): array {
        $out = ['ok' => false, 'reason' => '', 'customer' => null, 'part' => null,
                'need_client' => false, 'need_part' => false, 'fill_part_customer' => false];

        $curCid = trim((string)($order['Client_name_ID'] ?? ''));
        $curPid = (int)($order['d_id_ID'] ?? 0);
        $out['need_client'] = ($curCid === '');
        $out['need_part']   = ($curPid <= 0);

        $clientText = trim((string)($order['Client_name'] ?? ''));
        $partText   = trim((string)($order['d_id'] ?? ''));

        if (!$out['need_client'] && !$out['need_part']) { $out['reason'] = 'raced'; return $out; }

        // ── 先決定客戶 ───────────────────────────────────────────────────
        $customer = null;
        if (!$out['need_client']) {
            $customer = ['customer_id' => $curCid, 'customer' => ''];
        } else {
            // 料號已綁、且該料號主檔有客戶 → 客戶由料號決定（與本頁存檔規則一致）
            if (!$out['need_part']) {
                $ck = 'P#' . $curPid;
                if (!array_key_exists($ck, $cache)) {
                    $s = $pdo->prepare("SELECT d.Customer_Id, c.customer FROM d_setting d
                                        LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                                        WHERE d.d_id = ? LIMIT 1");
                    $s->execute([$curPid]);
                    $cache[$ck] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $pr = $cache[$ck];
                if ($pr && trim((string)$pr['Customer_Id']) !== '') {
                    $customer = ['customer_id' => trim((string)$pr['Customer_Id']), 'customer' => (string)$pr['customer']];
                }
            }
            if ($customer === null) {
                if ($clientText === '') { $out['reason'] = 'no_client_text'; return $out; }
                $ck = 'C#' . $clientText;
                if (!array_key_exists($ck, $cache)) $cache[$ck] = ot_ab_find_customers($pdo, $clientText);
                $cands = $cache[$ck];
                if (count($cands) === 0) { $out['reason'] = 'cust_none'; return $out; }
                if (count($cands) > 1)  { $out['reason'] = 'cust_many'; return $out; }
                $customer = $cands[0];
            }
        }
        $out['customer'] = $customer;

        // ── 再決定料號 ───────────────────────────────────────────────────
        if ($out['need_part']) {
            if ($partText === '') { $out['reason'] = 'no_part_text'; return $out; }
            // 跳窗的行為：訂單「本來就已綁客戶」時，料號候選只列該客戶底下的；
            // 本來沒綁客戶時是全範圍搜尋。這裡完全比照，不因為我們剛推出客戶就縮小範圍。
            $boundCid = (!$out['need_client']) ? $curCid : null;
            $pk = 'D#' . ($boundCid ?? '') . '#' . $partText;
            if (!array_key_exists($pk, $cache)) $cache[$pk] = ot_ab_find_parts($pdo, $partText, $boundCid);
            $parts = $cache[$pk];
            if (count($parts) === 0) { $out['reason'] = 'part_none'; return $out; }
            if (count($parts) > 1)  { $out['reason'] = 'part_many'; return $out; }
            $part = $parts[0];
            if (!ot_ab_is_exact_part($part, $partText)) { $out['reason'] = 'part_fuzzy'; return $out; }
            $pcid = trim((string)($part['customer_id'] ?? ''));
            if ($pcid !== '' && $customer && $pcid !== trim((string)$customer['customer_id'])) {
                $out['reason'] = 'part_other_cust'; return $out;
            }
            $out['part'] = $part;
            $out['fill_part_customer'] = ($pcid === '');
        }

        $out['ok'] = true; $out['reason'] = 'ok';
        return $out;
    }
}

if (!function_exists('ot_ab_apply_one')) {
    /** 真正寫入一張訂單（含「寫入前這張單還是未綁定」的再檢查與稽核紀錄） */
    function ot_ab_apply_one(PDO $pdo, array $o, array $j, bool $fillPC, int $uid, string $operator): array {
        $orderId = (int)$o['Order_id'];
        $sets = []; $params = [':oid' => $orderId];
        $guards = [];

        if ($j['need_client'] && $j['customer']) {
            $sets[] = 'Client_name_ID = :cid';
            $params[':cid'] = $j['customer']['customer_id'];
            // 綁定客戶後清掉手打的暫存客戶名稱（與快速綁定存檔同一套規則）
            $sets[] = "Client_name = ''";
            $guards[] = "(Client_name_ID IS NULL OR Client_name_ID = '')";
        }
        if ($j['need_part'] && $j['part']) {
            $sets[] = 'd_id_ID = :pid';
            $params[':pid'] = (int)$j['part']['d_id'];
            $guards[] = "(d_id_ID IS NULL OR d_id_ID = 0)";
        }
        if (!$sets) return ['applied' => false, 'part_customer_filled' => false];

        // 刻意不寫 Modified_By/Modified_At：那是訂單變更比對的基準（全站慣例）。
        // 這是系統批次補綁定，不是有人在改這張訂單的內容。
        $sql = "UPDATE order_track SET " . implode(', ', $sets)
             . " WHERE Order_id = :oid AND " . implode(' AND ', $guards);
        $up = $pdo->prepare($sql);
        $up->execute($params);
        if ($up->rowCount() < 1) return ['applied' => false, 'part_customer_filled' => false];

        // 料號主檔沒綁客戶時一併補上（與快速綁定的 part_customer_fixed 同一套；預設不做，由使用者勾選）
        $filled = false;
        if ($fillPC && $j['fill_part_customer'] && $j['part'] && $j['customer']) {
            $pu = $pdo->prepare("UPDATE d_setting SET Customer_Id = ?, Modified_By = ?, Modified_At = NOW()
                                 WHERE d_id = ? AND (Customer_Id IS NULL OR Customer_Id = '')");
            $pu->execute([$j['customer']['customer_id'], $uid, (int)$j['part']['d_id']]);
            $filled = ($pu->rowCount() > 0);
        }

        // 稽核：一張訂單一列，事後要查是誰在什麼時候自動綁的、綁成什麼，查得回來也還原得回去
        try {
            $chg = [];
            if ($j['need_client'] && $j['customer']) $chg[] = ['field' => '客戶ID', 'old' => '', 'new' => (string)$j['customer']['customer_id']];
            if ($j['need_part']   && $j['part'])     $chg[] = ['field' => '料號ID', 'old' => '', 'new' => (string)$j['part']['d_id']];
            if ($filled) $chg[] = ['field' => '料號主檔客戶', 'old' => '', 'new' => (string)$j['customer']['customer_id']];
            $pdo->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                           VALUES ('update','order_autobind',?,?,?,?,?,NOW())")
                ->execute([(string)$orderId, (string)($o['Order_oo'] ?? ''),
                           json_encode($chg, JSON_UNESCAPED_UNICODE), $uid, $operator]);
        } catch (Exception $e) { /* 稽核寫入失敗不擋主要作業 */ }

        return ['applied' => true, 'part_customer_filled' => $filled];
    }
}

if (!function_exists('ot_ab_run')) {
    /**
     * 批次掃描／寫入（一次一段，由前端以游標分段呼叫，避免長請求逾時）
     *
     * @param array $opt  after_id：游標（只處理 Order_id 大於此值的）
     *                    limit：這一段處理幾張
     *                    apply：true=真的寫入，false=只試算
     *                    fill_part_customer：料號主檔沒綁客戶時要不要一併補上
     *                    uid：操作者
     *                    sample_limit：回傳幾筆明細給畫面預覽
     */
    function ot_ab_run(PDO $pdo, array $opt): array {
        $after = (int)($opt['after_id'] ?? 0);
        $limit = max(1, min(500, (int)($opt['limit'] ?? 100)));
        $apply = !empty($opt['apply']);
        $fillPC = !empty($opt['fill_part_customer']);
        $uid   = (int)($opt['uid'] ?? 0);
        $sampleLimit = max(0, (int)($opt['sample_limit'] ?? 0));

        $st = $pdo->prepare("SELECT Order_id, Order_oo, Order_date, Client_name, Client_name_ID, d_id, d_id_ID, Order_status
                             FROM order_track
                             WHERE (Client_name_ID IS NULL OR Client_name_ID = '' OR d_id_ID IS NULL OR d_id_ID = 0)
                               AND Order_id > :after
                             ORDER BY Order_id
                             LIMIT $limit");
        $st->bindValue(':after', $after, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $res = ['processed' => 0, 'ok' => 0, 'applied' => 0, 'part_customer_filled' => 0,
                'reasons' => [], 'samples' => [], 'last_id' => $after, 'done' => (count($rows) < $limit)];
        if (!$rows) return $res;

        $cache = [];
        $operator = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? (string)$uid);

        foreach ($rows as $o) {
            $res['processed']++;
            $res['last_id'] = (int)$o['Order_id'];
            $j = ot_ab_judge($pdo, $o, $cache);

            if ($j['ok'] && $apply) {
                $done = ot_ab_apply_one($pdo, $o, $j, $fillPC, $uid, $operator);
                if ($done['applied']) {
                    $res['applied']++;
                    if ($done['part_customer_filled']) $res['part_customer_filled']++;
                } else {
                    $j['ok'] = false; $j['reason'] = 'raced';
                }
            }
            if ($j['ok']) $res['ok']++;

            $rc = $j['reason'];
            $res['reasons'][$rc] = ($res['reasons'][$rc] ?? 0) + 1;

            if (count($res['samples']) < $sampleLimit) {
                $res['samples'][] = [
                    'order_id'   => (int)$o['Order_id'],
                    'order_no'   => (string)$o['Order_oo'],
                    'order_date' => (string)$o['Order_date'],
                    'client_txt' => (string)$o['Client_name'],
                    'part_txt'   => (string)$o['d_id'],
                    'closed'     => ((int)($o['Order_status'] ?? 0) === 9),
                    'ok'         => (bool)$j['ok'],
                    'reason'     => $rc,
                    'reason_txt' => ot_ab_reason_label($rc),
                    'customer'   => $j['customer'] ? trim($j['customer']['customer_id'] . ' ' . ($j['customer']['customer'] ?? '')) : '',
                    'part'       => $j['part'] ? (string)$j['part']['D_Setting_Id'] : '',
                    'fill_pc'    => (bool)$j['fill_part_customer'],
                ];
            }
        }
        return $res;
    }
}
