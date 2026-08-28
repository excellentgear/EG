<?php
/**
 * quote_customer_lib.php — 「整張報價單變更客戶」與「訂單 ↔ 來源OP單客戶連動」的唯一實作
 * （2026-08-28 使用者交辦）
 *
 * 現場事實（使用者原話）：「常會有用 A 客戶報價，結果接單後客戶要求改成 B 客戶名稱的狀況」。
 * 原本的資料流是單向的——OP 轉訂單時把客戶「快照」寫進 order_track.Client_name/Client_name_ID
 * （見 src/store/_NewOrder_Track.php 的 create_orders_from_quotes），之後報價單那邊改了客戶，
 * 訂單、BOM 完全不會跟著動，畫面上也看不出來已經對不上了。本庫補上兩件事：
 *   ① 報價單端：一次把整張單（報價單表頭 ＋ 各項目綁定料號的 d_setting.Customer_Id）改成新客戶，
 *      並「連動」由這張 OP 轉出的訂單與其 BOM。
 *   ② 訂單端：判定某張訂單的客戶與其來源 OP 目前的客戶是否已經不一致（唯讀提示 ＋ 可一鍵同步）。
 *
 * ── 料號可不可以直接改客戶（使用者拍板的三段式判定）──────────────────────────────
 *   料號主檔（d_setting）是**全站共用**的，它的 Customer_Id 一改，所有引用這個料號ID的地方
 *   都會跟著換客戶。所以改之前一定要先看這個料號ID還被誰用著：
 *     ok      ＝只有本張報價單在用                              → 直接改
 *     confirm ＝另外只有「本張OP轉出的訂單／該訂單的BOM」在用   → 要二次確認才改
 *     block   ＝已經被本張OP以外的單據用到（別張報價單／別的訂單／別的BOM／出貨／退貨）
 *               → 一律禁止直接改，改建議「建立新料號」（qcc_clone_parts_for_customer）
 *
 *   **判定一律以「料號ID」為準，不用料號文字比對**：使用者的原話就是「若現有料號ID已經用在…」，
 *   而且只有用 ID 綁上去的紀錄才會被 d_setting.Customer_Id 的異動影響；純文字寫著同一組料號
 *   （例：bom.d_setting_id 為 NULL、只有 bom.d_id 文字，全庫 11858 筆 BOM 只有 2164 筆有綁ID）
 *   的舊資料不會因為這次修改而跑掉，拿文字去比對只會把根本不受影響的舊資料全部算成
 *   「別人在用」而永遠擋住，讓這個功能完全不能用。
 *
 * 不要各頁自己再寫一份判定或連動 SQL：兩個寫入點＝規則必定走鐘（CLAUDE.md 鐵律4）。
 */

// ──────────────────────────────────────────────────────────────────────────
// 權限
// ──────────────────────────────────────────────────────────────────────────
if (!function_exists('qcc_quotation_features')) {
    /**
     * 取得使用者在「報價單」模組的功能碼清單。
     * 解析順序刻意與 views/Sales/quotation_list_NEW.php 頁首那一段完全相同
     * （該頁自行內嵌解析是因為它同時要算出角色名稱給權限說明用；本函式是 API 端守門用的同規則實作）：
     *   有指派角色     → 以 user_roles + role_features 為準
     *   完全沒指派角色 → 退回舊制 user_module_permissions('quotation_list') 的 A/R/C/U/D
     *   兩者皆無       → ['all']（避免把還沒設定過權限的人整個鎖死，沿用該頁既有原則）
     */
    function qcc_quotation_features(PDO $pdo, int $uid): array {
        $features = [];
        $hasRoles = false;
        try {
            $st = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? LIMIT 1");
            $st->execute([$uid]);
            $hasRoles = (bool)$st->fetchColumn();
            if ($hasRoles) {
                $st2 = $pdo->prepare("SELECT DISTINCT rf.feature_code
                                      FROM user_roles ur
                                      JOIN role_features rf ON rf.role_id = ur.role_id
                                      WHERE ur.user_id = ?");
                $st2->execute([$uid]);
                $features = array_values(array_unique($st2->fetchAll(PDO::FETCH_COLUMN)));
            }
        } catch (Throwable $e) { return ['all']; }
        if ($hasRoles) return $features;

        try {
            $sp = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='quotation_list' LIMIT 1");
            $sp->execute([$uid]);
            $p = (string)$sp->fetchColumn();
            if ($p !== '') {
                if (strpos($p, 'A') !== false) return ['all'];
                if (strpos($p, 'R') !== false) { $features[] = 'quotation_view';   $features[] = 'quotation_print'; }
                if (strpos($p, 'C') !== false) { $features[] = 'quotation_create'; $features[] = 'quotation_clone'; $features[] = 'quotation_batch_add'; }
                if (strpos($p, 'U') !== false) { $features[] = 'quotation_edit';   $features[] = 'quotation_clone'; $features[] = 'quotation_view_history'; }
                if (strpos($p, 'D') !== false) { $features[] = 'quotation_delete'; $features[] = 'quotation_view_deleted'; }
            }
        } catch (Throwable $e) {}
        return $features ?: ['all'];
    }
}

if (!function_exists('qcc_can_change_customer')) {
    /** 是否具備「整張報價單變更客戶」權限（功能碼 quotation_change_customer，或系統管理員 all） */
    function qcc_can_change_customer(PDO $pdo, int $uid): bool {
        if ($uid <= 0) return false;
        $f = qcc_quotation_features($pdo, $uid);
        return in_array('all', $f, true) || in_array('quotation_change_customer', $f, true);
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 基礎查詢
// ──────────────────────────────────────────────────────────────────────────
if (!function_exists('qcc_customer')) {
    /** 取客戶主檔一列（找不到回 null） */
    function qcc_customer(PDO $pdo, string $customerId): ?array {
        $customerId = trim($customerId);
        if ($customerId === '') return null;
        $st = $pdo->prepare("SELECT customer_id, customer FROM customer_list WHERE customer_id=? LIMIT 1");
        $st->execute([$customerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('qcc_quote_head')) {
    /** 取報價單表頭（含 quote_no / client_id / client_name），找不到回 null */
    function qcc_quote_head(PDO $pdo, int $quoteId): ?array {
        $st = $pdo->prepare("SELECT quote_id, quote_no, quote_date, client_id, client_name, approval_status
                             FROM quotation_list WHERE quote_id=? LIMIT 1");
        $st->execute([$quoteId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('qcc_quote_orders')) {
    /**
     * 由這張 OP 轉出／綁定的訂單（order_track.quote_no = 該報價單號）。
     * 同一張 OP 可能轉出多筆（每個報價項目一筆，甚至同項目的追加訂單），一律都算「本張OP的訂單」。
     */
    function qcc_quote_orders(PDO $pdo, string $quoteNo): array {
        $quoteNo = trim($quoteNo);
        if ($quoteNo === '') return [];
        $st = $pdo->prepare("SELECT Order_id, Order_oo, d_id, d_id_ID, Client_name, Client_name_ID, Qty, Order_date, Order_status
                             FROM order_track WHERE quote_no = ? ORDER BY Order_id ASC");
        $st->execute([$quoteNo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 料號使用範圍掃描
// ──────────────────────────────────────────────────────────────────────────
if (!function_exists('qcc_part_usage')) {
    /**
     * 掃描某個料號ID目前被哪些單據用著，並判定可不可以直接改客戶。
     *
     * @param int    $dId       d_setting.d_id
     * @param int    $quoteId   本張報價單 quote_id
     * @param string $quoteNo   本張報價單號
     * @param array  $ownOrders 本張OP轉出的訂單（qcc_quote_orders 的結果，避免每個料號各查一次）
     * @return array [
     *   'inside'  => ['quote_items'=>n, 'orders'=>[...], 'boms'=>[...]],
     *   'outside' => [ ['kind'=>'quote|order|bom|shipment|return', 'label'=>'…', 'detail'=>'…'], … ],
     *   'info'    => ['…'],      // 不影響判定、但值得讓使用者知道的引用
     *   'verdict' => 'ok'|'confirm'|'block',
     * ]
     */
    function qcc_part_usage(PDO $pdo, int $dId, int $quoteId, string $quoteNo, array $ownOrders = []): array {
        $out = [
            'inside'  => ['quote_items' => 0, 'orders' => [], 'boms' => []],
            'outside' => [],
            'info'    => [],
            'verdict' => 'ok',
        ];
        if ($dId <= 0) return $out;

        // 本張OP的訂單 Order_id 集合。
        // **bom.o_order_id 存的是 order_track.Order_id（整數主鍵），不是訂單編號 Order_oo**
        //（資料字典寫「o-oo對應訂單編號」是舊註解，實測 1601 筆全是數字＝Order_id，另有 43 筆 'B'＝備庫、
        //  10214 筆 NULL）。用 Order_oo 去比對的話，本張OP自己的 BOM 會全部被誤判成「別人在用」而永遠擋住。
        $ownOrderIds = [];
        foreach ($ownOrders as $o) {
            $ownOrderIds[(int)$o['Order_id']] = true;
            if ((int)($o['d_id_ID'] ?? 0) === $dId) $out['inside']['orders'][] = $o;
        }

        // ── 本張報價單自己用了幾列 ────────────────────────────────────────
        $st = $pdo->prepare("SELECT COUNT(*) FROM quotation_item WHERE quote_id=? AND d_setting_d_id=?");
        $st->execute([$quoteId, $dId]);
        $out['inside']['quote_items'] = (int)$st->fetchColumn();

        // ── 別張報價單 ───────────────────────────────────────────────────
        $st = $pdo->prepare("SELECT ql.quote_no, ql.quote_date, ql.client_name, COUNT(*) AS n
                             FROM quotation_item qi
                             JOIN quotation_list ql ON ql.quote_id = qi.quote_id
                             WHERE qi.d_setting_d_id = ? AND qi.quote_id <> ?
                             GROUP BY ql.quote_id
                             ORDER BY ql.quote_date DESC, ql.quote_no DESC");
        $st->execute([$dId, $quoteId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['outside'][] = [
                'kind'   => 'quote',
                'label'  => (string)$r['quote_no'],
                'detail' => trim(((string)($r['client_name'] ?? '')) . '　' . (string)$r['quote_date'] . '　' . (int)$r['n'] . ' 項'),
            ];
        }

        // ── 別的訂單（非本張OP轉出）──────────────────────────────────────
        //    算「本張OP的訂單」的條件有兩種：
        //      ⑴ quote_no 就是本張OP
        //      ⑵ quote_no 空白、但訂單編號(Order_oo)與本張OP轉出的某一筆相同
        //         ——同一張客戶訂單編號底下，OP轉單建好之後常再手工補幾列（實測 OO1150730004
        //           就有 2 筆沒帶 quote_no），那些本來就是同一張單同一個客戶，不該算成「別人在用」。
        //      quote_no 有值但不是本張＝真的是別張OP的訂單，一律算 outside。
        $ownOrderOos = [];
        foreach ($ownOrders as $o) if (!empty($o['Order_oo'])) $ownOrderOos[(string)$o['Order_oo']] = true;
        $st = $pdo->prepare("SELECT Order_id, Order_oo, Client_name, Order_date, quote_no
                             FROM order_track WHERE d_id_ID = ? ORDER BY Order_date DESC, Order_id DESC");
        $st->execute([$dId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rq = trim((string)($r['quote_no'] ?? ''));
            if ($quoteNo !== '' && $rq === $quoteNo) continue;                                    // ⑴
            if ($rq === '' && isset($ownOrderOos[(string)$r['Order_oo']])) {                      // ⑵
                $out['inside']['orders'][] = $r;
                $ownOrderIds[(int)$r['Order_id']] = true;
                continue;
            }
            $out['outside'][] = [
                'kind'   => 'order',
                'label'  => (string)$r['Order_oo'],
                'detail' => trim(((string)($r['Client_name'] ?? '')) . '　' . (string)$r['Order_date']
                          . ($rq !== '' ? '　來自 ' . $rq : '　未綁報價單')),
            ];
        }

        // ── BOM（製令單）──────────────────────────────────────────────────
        //    只看有綁料號ID（bom.d_setting_id）的：掛在本張OP訂單底下的算 inside，其餘算 outside。
        //    o_order_id 是 NULL／'B'（備庫）＝無從判定歸屬，一律保守算 outside 並在畫面上寫明原因，
        //    讓使用者自己決定要不要改走「建立新料號」。
        $st = $pdo->prepare("SELECT b.bom, b.o_order_id, b.Client_Name, b.Created_At, ot.Order_oo
                             FROM bom b
                             LEFT JOIN order_track ot ON ot.Order_id = b.o_order_id
                             WHERE b.d_setting_id = ? ORDER BY b.Created_At DESC");
        $st->execute([$dId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oid = (string)($r['o_order_id'] ?? '');
            if ($oid !== '' && ctype_digit($oid) && isset($ownOrderIds[(int)$oid])) {
                $out['inside']['boms'][] = $r;
                continue;
            }
            $why = ($oid === '') ? '未對應訂單（無法判定歸屬）'
                 : (ctype_digit($oid) ? ('訂單 ' . ((string)($r['Order_oo'] ?? '') ?: ('ID ' . $oid)))
                                      : ('備庫／重製（' . $oid . '）'));
            $out['outside'][] = [
                'kind'   => 'bom',
                'label'  => (string)$r['bom'],
                'detail' => trim(((string)($r['Client_Name'] ?? '')) . '　' . $why),
            ];
        }

        // ── 出貨／退貨：出過貨就代表這個料號ID已經以原客戶的身分交付過，不可直接改掛別家 ──
        $st = $pdo->prepare("SELECT COUNT(*) AS n, MIN(Order_date) AS d1, MAX(Order_date) AS d2 FROM is_list WHERE d_setting_id = ?");
        $st->execute([$dId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($r['n'] ?? 0) > 0) {
            $out['outside'][] = [
                'kind'   => 'shipment',
                'label'  => '出貨紀錄 ' . (int)$r['n'] . ' 筆',
                'detail' => trim((string)($r['d1'] ?? '') . ' ~ ' . (string)($r['d2'] ?? '')),
            ];
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM ir_track WHERE d_setting_id = ?");
        $st->execute([$dId]);
        $nIr = (int)$st->fetchColumn();
        if ($nIr > 0) $out['outside'][] = ['kind' => 'return', 'label' => '出貨退回 ' . $nIr . ' 筆', 'detail' => ''];

        // ── 純提示（不影響判定）：組合件結構。改客戶不會弄壞這層關係，但值得讓人知道 ──
        try {
            $st = $pdo->prepare("SELECT
                    (SELECT COUNT(*) FROM d_setting_bom WHERE parent_d_id=?) AS as_parent,
                    (SELECT COUNT(*) FROM d_setting_bom WHERE child_d_id=?)  AS as_child");
            $st->execute([$dId, $dId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int)($r['as_parent'] ?? 0) > 0) $out['info'][] = '此料號是組合件母件，底下有 ' . (int)$r['as_parent'] . ' 個子件';
            if ((int)($r['as_child'] ?? 0) > 0)  $out['info'][] = '此料號被 ' . (int)$r['as_child'] . ' 個組合件當作子件使用';
        } catch (Throwable $e) {}

        if (!empty($out['outside'])) {
            $out['verdict'] = 'block';
        } elseif (!empty($out['inside']['orders']) || !empty($out['inside']['boms'])) {
            $out['verdict'] = 'confirm';
        }
        return $out;
    }
}

if (!function_exists('qcc_scan_quote')) {
    /**
     * 整張報價單的變更客戶前置掃描。
     * @return array [
     *   'quote'   => 表頭,
     *   'target'  => 目標客戶（未指定時為 null＝只是先看現況）,
     *   'orders'  => 本張OP轉出的訂單,
     *   'items'   => [ ['item_id','product_id','d_id','part_no','part_customer_id','part_customer_name',
     *                   'already_target','verdict','usage'], … ],
     *   'unbound' => 尚未綁定料號ID的項目數,
     *   'verdict' => 'ok'|'confirm'|'block'   // 整張單取最嚴格的一項
     * ]
     */
    function qcc_scan_quote(PDO $pdo, int $quoteId, string $targetCustomerId = ''): array {
        $head = qcc_quote_head($pdo, $quoteId);
        if (!$head) throw new Exception('找不到報價單');
        $targetCustomerId = trim($targetCustomerId);
        $orders = qcc_quote_orders($pdo, (string)$head['quote_no']);

        $st = $pdo->prepare("SELECT qi.item_id, qi.product_id, qi.d_setting_d_id, qi.specification, qi.quantity,
                                    ds.D_Setting_Id, ds.Customer_Id AS part_customer_id, c.customer AS part_customer_name
                             FROM quotation_item qi
                             LEFT JOIN d_setting ds ON ds.d_id = qi.d_setting_d_id
                             LEFT JOIN customer_list c ON c.customer_id = ds.Customer_Id
                             WHERE qi.quote_id = ?
                             ORDER BY qi.sort_order ASC, qi.item_id ASC");
        $st->execute([$quoteId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $items      = [];
        $unbound    = 0;
        $rank       = ['ok' => 0, 'confirm' => 1, 'block' => 2];
        $worst      = 'ok';
        $usageCache = [];   // 同一張單常有同料號多列（不同數量級距），只掃一次
        foreach ($rows as $r) {
            $dId = (int)($r['d_setting_d_id'] ?? 0);
            if ($dId <= 0) $unbound++;
            if ($dId > 0 && !isset($usageCache[$dId])) {
                $usageCache[$dId] = qcc_part_usage($pdo, $dId, $quoteId, (string)$head['quote_no'], $orders);
            }
            $usage   = $dId > 0 ? $usageCache[$dId] : null;
            $verdict = $usage ? $usage['verdict'] : 'ok';
            // 料號客戶已經就是目標客戶＝這一列不必動，不參與嚴格度判定
            $already = ($targetCustomerId !== '' && (string)($r['part_customer_id'] ?? '') === $targetCustomerId);
            if ($dId > 0 && !$already && $rank[$verdict] > $rank[$worst]) $worst = $verdict;
            $items[] = [
                'item_id'            => (int)$r['item_id'],
                'product_id'         => (string)($r['product_id'] ?? ''),
                'specification'      => (string)($r['specification'] ?? ''),
                'quantity'           => (int)($r['quantity'] ?? 0),
                'd_id'               => $dId,
                'part_no'            => (string)($r['D_Setting_Id'] ?? ''),
                'part_customer_id'   => (string)($r['part_customer_id'] ?? ''),
                'part_customer_name' => (string)($r['part_customer_name'] ?? ''),
                'already_target'     => $already,
                'verdict'            => $dId > 0 ? $verdict : 'unbound',
                'usage'              => $usage,
            ];
        }

        return [
            'quote'   => $head,
            'target'  => $targetCustomerId !== '' ? qcc_customer($pdo, $targetCustomerId) : null,
            'orders'  => $orders,
            'items'   => $items,
            'unbound' => $unbound,
            'verdict' => $worst,
        ];
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 套用變更
// ──────────────────────────────────────────────────────────────────────────
if (!function_exists('qcc_apply_customer')) {
    /**
     * 把整張報價單改成新客戶，並連動由這張 OP 轉出的訂單／BOM。
     * 呼叫端務必已經做過權限檢查（qcc_can_change_customer）——本函式只管資料規則。
     *
     * @param bool $confirmed 前端已對「confirm 等級」按過二次確認；false 時遇到 confirm 一律擋下
     * @param bool $withParts 是否一併改料號主檔（d_setting.Customer_Id）。
     *                        false＝只改報價單表頭與訂單／BOM（給 qcc_clone_parts_for_customer 用：
     *                        該路徑已經把項目改綁到新料號，舊料號不可以再動）
     * @return array 異動摘要
     */
    function qcc_apply_customer(PDO $pdo, int $quoteId, string $customerId, int $uid, bool $confirmed = false, bool $withParts = true): array {
        $cust = qcc_customer($pdo, $customerId);
        if (!$cust) throw new Exception('找不到此客戶代碼');

        $scan = qcc_scan_quote($pdo, $quoteId, (string)$cust['customer_id']);
        $head = $scan['quote'];

        if ($withParts) {
            if ($scan['verdict'] === 'block') {
                throw new Exception('本張報價單內有料號已經被本張OP以外的單據使用，不可直接變更料號客戶；請改用「建立新料號」。');
            }
            if ($scan['verdict'] === 'confirm' && !$confirmed) {
                throw new Exception('本張報價單內的料號已有本張OP轉出的訂單／BOM在使用，需二次確認後才能變更。');
            }
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $res = [
                'quote_id'       => $quoteId,
                'quote_no'       => (string)$head['quote_no'],
                'from_client_id' => (string)($head['client_id'] ?? ''),
                'from_client'    => (string)($head['client_name'] ?? ''),
                'to_client_id'   => (string)$cust['customer_id'],
                'to_client'      => (string)$cust['customer'],
                'parts_updated'  => [],
                'parts_skipped'  => [],
                'orders_updated' => 0,
                'boms_updated'   => 0,
            ];

            // ① 報價單表頭
            $pdo->prepare("UPDATE quotation_list SET client_id=?, client_name=?, updated_by=?, updated_at=NOW() WHERE quote_id=?")
                ->execute([$cust['customer_id'], $cust['customer'], $uid, $quoteId]);

            // ② 料號主檔：同一個 d_id 只改一次
            if ($withParts) {
                $done = [];
                foreach ($scan['items'] as $it) {
                    $dId = (int)$it['d_id'];
                    if ($dId <= 0 || isset($done[$dId])) continue;
                    $done[$dId] = true;
                    if ($it['already_target']) {
                        $res['parts_skipped'][] = ['d_id' => $dId, 'part_no' => $it['part_no'], 'why' => '已經是此客戶'];
                        continue;
                    }
                    // 料號主檔查重規則（比照 Quotation_API 的 save_part_info）：同料號＋同客戶不可有兩筆
                    $dup = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? AND Customer_Id=? AND d_id<>? LIMIT 1");
                    $dup->execute([$it['part_no'], $cust['customer_id'], $dId]);
                    if ($dupId = $dup->fetchColumn()) {
                        throw new Exception('料號「' . $it['part_no'] . '」在客戶「' . $cust['customer']
                            . '」底下已經有另一筆料號主檔（料號ID ' . (int)$dupId . '），不可改成重複資料；'
                            . '請改用「建立新料號」（會自動沿用那一筆）。');
                    }
                    $pdo->prepare("UPDATE d_setting SET Customer_Id=?, Modified_By=?, Modified_At=NOW() WHERE d_id=?")
                        ->execute([$cust['customer_id'], $uid, $dId]);
                    $res['parts_updated'][] = ['d_id' => $dId, 'part_no' => $it['part_no'], 'from' => $it['part_customer_name']];
                }
            }

            // ③ 連動本張OP轉出的訂單（客戶名稱＋客戶ID），以及掛在這些訂單底下的 BOM
            //    bom.o_order_id 存的是 order_track.Order_id（見 qcc_part_usage 的說明），不是 Order_oo
            $orderIds = [];
            foreach ($scan['orders'] as $o) {
                $pdo->prepare("UPDATE order_track SET Client_name=?, Client_name_ID=?, Modified_By=?, Modified_At=NOW() WHERE Order_id=?")
                    ->execute([$cust['customer'], $cust['customer_id'], $uid, (int)$o['Order_id']]);
                $res['orders_updated']++;
                $orderIds[] = (int)$o['Order_id'];
            }
            if ($orderIds) {
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $st = $pdo->prepare("UPDATE bom SET Client_Name=?, Modified_By=?, Modified_At=NOW() WHERE o_order_id IN ($ph)");
                $st->execute(array_merge([$cust['customer'], $uid], $orderIds));
                $res['boms_updated'] = $st->rowCount();
            }

            // ④ 留紀錄：報價單「修改紀錄」看得到。
            //    withParts=false 是被 qcc_clone_parts_for_customer 呼叫的中繼步驟，
            //    由那一支在最後統一寫一筆完整摘要，這裡不重複寫。
            if ($withParts) qcc_log_change($pdo, $quoteId, $uid, $res);

            if ($ownTx) $pdo->commit();
            return $res;
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('qcc_log_change')) {
    /** 寫進 quotation_change_log（沿用該表既有 diff 格式，報價單頁的「修改紀錄」直接看得到） */
    function qcc_log_change(PDO $pdo, int $quoteId, int $uid, array $res): void {
        try {
            $diff = [
                'client_id'   => ['from' => (string)($res['from_client_id'] ?? ''), 'to' => (string)($res['to_client_id'] ?? '')],
                'client_name' => ['from' => (string)($res['from_client'] ?? ''),    'to' => (string)($res['to_client'] ?? '')],
            ];
            $sum = ['整張單變更客戶'];
            if (!empty($res['parts_created']))   $sum[] = '新建料號 ' . count($res['parts_created']) . ' 筆';
            if (!empty($res['parts_reused']))    $sum[] = '沿用既有料號 ' . count($res['parts_reused']) . ' 筆';
            if (!empty($res['parts_updated']))   $sum[] = '料號主檔 ' . count($res['parts_updated']) . ' 筆';
            if (!empty($res['orders_updated']))  $sum[] = '訂單 ' . (int)$res['orders_updated'] . ' 筆';
            if (!empty($res['boms_updated']))    $sum[] = 'BOM ' . (int)$res['boms_updated'] . ' 筆';
            $pdo->prepare("INSERT INTO quotation_change_log (quote_id,changed_by,changed_at,summary,diff_json) VALUES (?,?,NOW(),?,?)")
                ->execute([$quoteId, $uid, mb_substr(implode('、', $sum), 0, 200), json_encode($diff, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* 紀錄失敗不阻斷主流程 */ }
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 建立新料號（block 等級時的替代路徑）
// ──────────────────────────────────────────────────────────────────────────
if (!function_exists('qcc_clone_parts_for_customer')) {
    /**
     * 「快速建立本張OP單所有料號對應新客戶」：
     * 依本張報價單目前綁定的每一個料號，各建一筆**同料號文字、客戶改成新客戶**的新料號主檔，
     * 把報價項目改綁到新料號，接著把報價單表頭與本張OP轉出的訂單／BOM 一併換成新客戶。
     *
     * 三個刻意的行為：
     *   ① 目標客戶底下已經有同料號文字的料號主檔 → **直接沿用那一筆，不再建重複的**
     *      （料號主檔的查重規則就是「料號＋客戶」相同即重複，硬建一定被擋）。
     *   ② 複製範圍比照主檔管理頁的「複製料號」：基本資料＋齒輪規格＋組合件子件結構＋料號標籤，
     *      **不含實體附件檔**——附件放在 NAS 的 <根目錄>/<d_id>/ 底下，複製 DB 列而不搬檔案
     *      會做出一批指向不存在檔案的附件紀錄。新舊料號會自動互相登記成「舊料號」別名，
     *      之後用舊料號還查得到。
     *   ③ 原本那個料號完全不動（別張報價單／訂單／出貨還在用它）。
     */
    function qcc_clone_parts_for_customer(PDO $pdo, int $quoteId, string $customerId, int $uid): array {
        $cust = qcc_customer($pdo, $customerId);
        if (!$cust) throw new Exception('找不到此客戶代碼');
        $head = qcc_quote_head($pdo, $quoteId);
        if (!$head) throw new Exception('找不到報價單');

        $st = $pdo->prepare("SELECT qi.item_id, qi.d_setting_d_id, ds.D_Setting_Id
                             FROM quotation_item qi
                             JOIN d_setting ds ON ds.d_id = qi.d_setting_d_id
                             WHERE qi.quote_id = ? ORDER BY qi.item_id ASC");
        $st->execute([$quoteId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) throw new Exception('本張報價單沒有任何已綁定料號ID的項目，無法建立新料號');

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $map     = [];   // 舊 d_id => 新 d_id
            $created = [];
            $reused  = [];
            foreach ($rows as $r) {
                $oldId = (int)$r['d_setting_d_id'];
                if ($oldId <= 0 || isset($map[$oldId])) continue;
                $wasReused = false;
                $newId = qcc_clone_one_part($pdo, $oldId, (string)$cust['customer_id'], $uid, $wasReused);
                $map[$oldId] = $newId;
                $info = ['from_d_id' => $oldId, 'd_id' => $newId, 'part_no' => (string)$r['D_Setting_Id']];
                if ($wasReused) $reused[] = $info; else $created[] = $info;
            }

            // 報價項目改綁新料號（product_id 一併帶成新料號的料號文字，與 quick_bind_item_dsetting 同規則）
            $upd = $pdo->prepare("UPDATE quotation_item SET d_setting_d_id=?, product_id=?, updated_at=NOW() WHERE item_id=?");
            $pn  = $pdo->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
            $rebound = 0;
            foreach ($rows as $r) {
                $oldId = (int)$r['d_setting_d_id'];
                if (!isset($map[$oldId]) || $map[$oldId] === $oldId) continue;
                $pn->execute([$map[$oldId]]);
                $upd->execute([$map[$oldId], (string)$pn->fetchColumn(), (int)$r['item_id']]);
                $rebound++;
            }

            // 表頭與本張OP轉出的訂單／BOM 換客戶；此時項目已綁新料號，舊料號主檔一律不動（withParts=false）
            $res = qcc_apply_customer($pdo, $quoteId, (string)$cust['customer_id'], $uid, true, false);
            $res['parts_created'] = $created;
            $res['parts_reused']  = $reused;
            $res['items_rebound'] = $rebound;

            // 訂單的料號ID也要跟著改綁到新料號，否則訂單仍指著舊客戶的那筆料號主檔
            $ordUpd = 0;
            foreach (qcc_quote_orders($pdo, (string)$head['quote_no']) as $o) {
                $oldId = (int)($o['d_id_ID'] ?? 0);
                if ($oldId > 0 && isset($map[$oldId]) && $map[$oldId] !== $oldId) {
                    $pn->execute([$map[$oldId]]);
                    $pdo->prepare("UPDATE order_track SET d_id_ID=?, d_id=?, Modified_By=?, Modified_At=NOW() WHERE Order_id=?")
                        ->execute([$map[$oldId], (string)$pn->fetchColumn(), $uid, (int)$o['Order_id']]);
                    $ordUpd++;
                }
            }
            $res['orders_repointed'] = $ordUpd;

            qcc_log_change($pdo, $quoteId, $uid, $res);

            if ($ownTx) $pdo->commit();
            return $res;
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('qcc_clone_one_part')) {
    /**
     * 複製單一料號主檔成「同料號文字、指定客戶」的新料號。
     * 來源料號本身就是該客戶、或目標客戶底下已存在同料號文字者，一律直接沿用既有那一筆
     * （$wasReused 回 true），不建重複資料。
     */
    function qcc_clone_one_part(PDO $pdo, int $srcDId, string $customerId, int $uid, ?bool &$wasReused = null): int {
        $wasReused = false;
        $st = $pdo->prepare("SELECT * FROM d_setting WHERE d_id=? LIMIT 1");
        $st->execute([$srcDId]);
        $src = $st->fetch(PDO::FETCH_ASSOC);
        if (!$src) throw new Exception('找不到來源料號（料號ID ' . $srcDId . '）');

        if ((string)($src['Customer_Id'] ?? '') === $customerId) { $wasReused = true; return $srcDId; }

        $dup = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? AND Customer_Id=? LIMIT 1");
        $dup->execute([$src['D_Setting_Id'], $customerId]);
        if ($exist = $dup->fetchColumn()) { $wasReused = true; return (int)$exist; }

        // 只複製「料號本身的屬性」；建檔者/時間另外寫，不沿用來源的稽核欄位
        $cols = ['D_Setting_Id', 'Drawing_No', 'E_Drawing_No', 'Spec_No', 'Revision', 'Issue_Date', 'Remark',
                 'Type', 'Is_Assembly', 'workpiece_sub_type_id', 'Weight_Kg', 'weight_source', 'weight_calc_json'];
        $have = [];
        foreach ($cols as $c) if (array_key_exists($c, $src)) $have[] = $c;
        $sql = "INSERT INTO d_setting (" . implode(',', array_map(fn($c) => "`$c`", $have)) . ",Customer_Id,Created_By,Created_At) VALUES ("
             . implode(',', array_fill(0, count($have), '?')) . ",?,?,NOW())";
        $par = [];
        foreach ($have as $c) $par[] = $src[$c];
        $par[] = $customerId;
        $par[] = $uid;
        $pdo->prepare($sql)->execute($par);
        $newId = (int)$pdo->lastInsertId();

        // 齒輪規格
        try {
            $g = $pdo->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id=? ORDER BY gear_id ASC");
            $g->execute([$srcDId]);
            $grows = $g->fetchAll(PDO::FETCH_ASSOC);
            if ($grows) {
                $gcols = array_values(array_filter(array_keys($grows[0]), fn($c) => !in_array($c, ['gear_id', 'd_setting_id'], true)));
                $gins  = $pdo->prepare("INSERT INTO d_setting_gear (d_setting_id," . implode(',', array_map(fn($c) => "`$c`", $gcols)) . ") VALUES (?,"
                                     . implode(',', array_fill(0, count($gcols), '?')) . ")");
                foreach ($grows as $gr) {
                    $p = [$newId];
                    foreach ($gcols as $c) $p[] = $gr[$c];
                    $gins->execute($p);
                }
            }
        } catch (Throwable $e) {}

        // 組合件子件結構（母件關係；子件仍指向原本的子料號）
        try {
            $b = $pdo->prepare("SELECT child_d_id, standard_qty, Remark_Bom FROM d_setting_bom WHERE parent_d_id=? ORDER BY bom_id ASC");
            $b->execute([$srcDId]);
            $bins = $pdo->prepare("INSERT INTO d_setting_bom (parent_d_id,child_d_id,standard_qty,Remark_Bom,Created_By,Created_At) VALUES (?,?,?,?,?,NOW())");
            foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $br) {
                $bins->execute([$newId, (int)$br['child_d_id'], $br['standard_qty'], $br['Remark_Bom'], $uid]);
            }
        } catch (Throwable $e) {}

        // 料號標籤
        try {
            $l = $pdo->prepare("SELECT label_id FROM item_label_map WHERE d_id=?");
            $l->execute([$srcDId]);
            $lins = $pdo->prepare("INSERT IGNORE INTO item_label_map (d_id,label_id) VALUES (?,?)");
            foreach ($l->fetchAll(PDO::FETCH_COLUMN) as $lid) $lins->execute([$newId, $lid]);
        } catch (Throwable $e) {}

        // 舊料號登記成新料號的「舊料號」別名，之後用舊料號查得到新的
        // （別名在同一客戶下唯一，撞到就跳過＝INSERT IGNORE）
        try {
            $pdo->prepare("INSERT IGNORE INTO d_setting_alias (d_id, alias_code, alias_type, customer_id, linked_d_id, note, sort_order, created_by, created_at)
                           VALUES (?,?,?,?,?,?,0,?,NOW())")
                ->execute([$newId, (string)$src['D_Setting_Id'], 'old_part',
                           ((string)($src['Customer_Id'] ?? '') !== '' ? $src['Customer_Id'] : null), $srcDId,
                           '由報價單變更客戶時自動建立（原客戶的料號ID ' . $srcDId . '）', $uid]);
        } catch (Throwable $e) {}

        return $newId;
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 訂單端：與來源 OP 單的客戶是否一致
// ──────────────────────────────────────────────────────────────────────────
if (!function_exists('qcc_customer_differs')) {
    /**
     * 訂單客戶與 OP 客戶是不是不一樣。
     * 兩邊都有客戶ID時**只比ID**（名稱是快照，客戶主檔改過簡稱不算不一致）；
     * 任一邊沒有ID才退回比名稱；OP 那邊根本沒設客戶時一律不算不一致（沒有比較基準）。
     */
    function qcc_customer_differs(array $order, array $quote): bool {
        $oid = trim((string)($order['Client_name_ID'] ?? ''));
        $qid = trim((string)($quote['client_id'] ?? ''));
        $on  = trim((string)($order['Client_name'] ?? ''));
        $qn  = trim((string)($quote['client_name'] ?? ''));
        if ($qid === '' && $qn === '') return false;
        if ($oid !== '' && $qid !== '') return $oid !== $qid;
        if ($on === '' || $qn === '')   return false;
        return $on !== $qn;
    }
}

if (!function_exists('qcc_orders_op_customer_map')) {
    /**
     * 一次算出一批訂單「來源OP單目前的客戶」以及是否已經跟訂單上的客戶對不上。
     * 給 NewOrder_Track.php 的列表用（整頁一次查完，不要每列各打一次）。
     *
     * @param array $orders order_track 的列（需含 Order_id, quote_no, Client_name, Client_name_ID）
     * @return array [Order_id => ['quote_no','op_client_id','op_client_name','mismatch'=>bool]]
     */
    function qcc_orders_op_customer_map(PDO $pdo, array $orders): array {
        $nos = [];
        foreach ($orders as $o) {
            $q = trim((string)($o['quote_no'] ?? ''));
            if ($q !== '') $nos[$q] = true;
        }
        if (!$nos) return [];
        $nos = array_keys($nos);
        $ph  = implode(',', array_fill(0, count($nos), '?'));
        try {
            $st = $pdo->prepare("SELECT quote_no, client_id, client_name FROM quotation_list WHERE quote_no IN ($ph)");
            $st->execute($nos);
        } catch (Throwable $e) { return []; }
        $qmap = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $qmap[(string)$r['quote_no']] = $r;

        $out = [];
        foreach ($orders as $o) {
            $q = trim((string)($o['quote_no'] ?? ''));
            if ($q === '' || !isset($qmap[$q])) continue;
            $out[(int)$o['Order_id']] = [
                'quote_no'       => $q,
                'op_client_id'   => (string)($qmap[$q]['client_id'] ?? ''),
                'op_client_name' => (string)($qmap[$q]['client_name'] ?? ''),
                'mismatch'       => qcc_customer_differs($o, $qmap[$q]),
            ];
        }
        return $out;
    }
}

if (!function_exists('qcc_sync_order_from_quote')) {
    /**
     * 把單張訂單的客戶同步成其來源 OP 單目前的客戶
     * （只動客戶欄位：不動料號、不動金額、不動任何日期），並連動該訂單編號底下的 BOM 客戶名稱。
     */
    function qcc_sync_order_from_quote(PDO $pdo, int $orderId, int $uid): array {
        $st = $pdo->prepare("SELECT Order_id, Order_oo, quote_no, Client_name, Client_name_ID FROM order_track WHERE Order_id=? LIMIT 1");
        $st->execute([$orderId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) throw new Exception('找不到訂單');
        $qno = trim((string)($o['quote_no'] ?? ''));
        if ($qno === '') throw new Exception('此訂單沒有來源報價單(OP)，無法同步');

        $sq = $pdo->prepare("SELECT quote_no, client_id, client_name FROM quotation_list WHERE quote_no=? LIMIT 1");
        $sq->execute([$qno]);
        $q = $sq->fetch(PDO::FETCH_ASSOC);
        if (!$q) throw new Exception('找不到來源報價單 ' . $qno);
        if (!qcc_customer_differs($o, $q)) {
            return ['changed' => false, 'message' => '訂單客戶與來源報價單一致，不需同步'];
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE order_track SET Client_name=?, Client_name_ID=?, Modified_By=?, Modified_At=NOW() WHERE Order_id=?")
                ->execute([(string)$q['client_name'], ((string)$q['client_id'] !== '' ? $q['client_id'] : null), $uid, $orderId]);
            // bom.o_order_id 存的是 order_track.Order_id（見 qcc_part_usage 的說明），不是訂單編號
            $bs = $pdo->prepare("UPDATE bom SET Client_Name=?, Modified_By=?, Modified_At=NOW() WHERE o_order_id=?");
            $bs->execute([(string)$q['client_name'], $uid, (string)$orderId]);
            $boms = $bs->rowCount();
            if ($ownTx) $pdo->commit();
            return [
                'changed'  => true,
                'from'     => (string)($o['Client_name'] ?? ''),
                'to'       => (string)$q['client_name'],
                'boms'     => $boms,
                'quote_no' => $qno,
                'message'  => '已將訂單客戶由「' . (string)($o['Client_name'] ?? '') . '」同步為來源 ' . $qno . ' 的「' . (string)$q['client_name'] . '」',
            ];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
