<?php
/**
 * src/common/trace_chain_lib.php — 追溯鏈：報價單 → 訂單 → 製令(BOM) → 出貨單 → 退貨單 →(重出)出貨單
 *
 * 為什麼要有這支（2026-09-03 使用者交辦）：
 *   同一支料號的訂單、製令、出貨、退貨散在四張表，而且彼此是**多對多且會拆分數量**——
 *   一張製令分給 6 張訂單、一張出貨單分屬多張訂單、多張出貨單對到同一張退貨單都真的存在。
 *   要看得懂就不能只列文字，必須看得到「這張單的量分別分給了誰、各多少、還剩多少沒分配」。
 *
 * ⚠ 儲存位置（唯一真相，禁止另外再開一張對照表）：
 *   報價→訂單   order_track.quote_no / quote_item_id   （綁定一律走 acc_recon_bind_quote()，含料號比對與稽核）
 *   訂單→製令   bom_order_process_map(bom, order_id, allocated_qty)   ← 已有 2,297 筆實際資料
 *   訂單→出貨   is_order_map(IS_id, Order_id, allocated_qty)          ← 本次新增
 *   製令→出貨   is_bom_map(IS_id, bom, shipped_qty)
 *   出貨→退貨   return_order_map(IR_id, Order_id, return_qty, IS_id)
 *   退貨→重出   ir_reship_map(IR_id, IS_id, qty)                      ← 本次新增
 *
 * ⚠ is_list.Order_id 的地位變了：它降為「主要訂單」快取＝分配量最大的那一張，
 *   只由 tc_sync_is_order() 一處同步。沒有拆分時它與分配表完全一致，所以讀它的
 *   20 多支既有程式（對帳、毛利分析、報價…）一行都不必改；只有真的一張出貨分屬多張訂單時才會有差。
 */

/** 每個泳道一次最多載入幾筆（和大單一料號就有 2,220 筆出貨，不能全撈） */
if (!defined('TC_LANE_LIMIT')) define('TC_LANE_LIMIT', 100);

// 報價→訂單的綁定沿用 acc_lib.php 既有的唯一實作 acc_recon_bind_quote()，不另外刻一份
require_once __DIR__ . '/acc_lib.php';

/**
 * 六種連結：type => [顯示名稱, 來源種類, 目標種類]
 * 種類代碼：quote 報價單 / order 訂單 / bom 製令 / ship 出貨單 / ret 退貨單
 */
function tc_link_types(): array
{
    return [
        'quote_order' => ['報價單 → 訂單',     'quote', 'order'],
        'order_bom'   => ['訂單 → 製令',       'order', 'bom'],
        'order_ship'  => ['訂單 → 出貨單',     'order', 'ship'],
        'bom_ship'    => ['製令 → 出貨單',     'bom',   'ship'],
        'ship_return' => ['出貨單 → 退貨單',   'ship',  'ret'],
        'return_ship' => ['退貨單 → 重出出貨', 'ret',   'ship'],
    ];
}

/** 兩個種類之間有沒有可用的連結型別（拖放時判斷用，順向逆向都認） */
function tc_link_type_between(string $a, string $b): string
{
    foreach (tc_link_types() as $t => $d) {
        if ($d[1] === $a && $d[2] === $b) return $t;
        if ($d[1] === $b && $d[2] === $a) return $t;
    }
    return '';
}

/* ============================================================
 * 客戶 → 該客戶有資料的料號（開窗時的第二層挑選）
 * ============================================================ */
function tc_parts(PDO $db, string $client): array
{
    $client = trim($client);
    if ($client === '') return [];
    $sql = "
        SELECT d, part, SUM(c) c FROM (
            SELECT d_id_ID d, MAX(d_id) part, COUNT(*) c FROM order_track
             WHERE Client_name = :c1 AND d_id_ID IS NOT NULL GROUP BY d_id_ID
            UNION ALL
            SELECT d_setting_id d, MAX(Product_id) part, COUNT(*) c FROM is_list
             WHERE Client_name = :c2 AND d_setting_id IS NOT NULL GROUP BY d_setting_id
            UNION ALL
            SELECT d_setting_id d, MAX(d_id) part, COUNT(*) c FROM bom
             WHERE Client_Name = :c3 AND d_setting_id IS NOT NULL GROUP BY d_setting_id
            UNION ALL
            SELECT d_setting_id d, MAX(d_id) part, COUNT(*) c FROM ir_track
             WHERE Client_name = :c4 AND d_setting_id IS NOT NULL GROUP BY d_setting_id
        ) t GROUP BY d, part ORDER BY part";
    $st = $db->prepare($sql);
    $st->execute([':c1' => $client, ':c2' => $client, ':c3' => $client, ':c4' => $client]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['d_id' => (int)$r['d'], 'part_no' => (string)$r['part'], 'cnt' => (int)$r['c']];
    }
    return $out;
}

/* ============================================================
 * 載入一支料號的完整追溯鏈
 * $f: d_id(必填,d_setting_id) / client / date_from / date_to
 *     order: 'new'(預設,新→舊) | 'old'(舊→新)
 *     limit: 每個泳道筆數上限（預設 TC_LANE_LIMIT）
 *     all_client: 1=不限客戶（各表客戶簡稱寫法不一致時用）
 * ============================================================ */
function tc_chain(PDO $db, array $f): array
{
    $did    = (int)($f['d_id'] ?? 0);
    if ($did <= 0) return ['error' => '請指定料號'];
    $client = trim((string)($f['client'] ?? ''));
    $allCli = !empty($f['all_client']) || $client === '';
    $asc    = (($f['order'] ?? 'new') === 'old');
    $lim    = (int)($f['limit'] ?? TC_LANE_LIMIT);
    if ($lim < 1 || $lim > 500) $lim = TC_LANE_LIMIT;
    $df = trim((string)($f['date_from'] ?? ''));
    $dt = trim((string)($f['date_to'] ?? ''));
    $dir = $asc ? 'ASC' : 'DESC';

    /** 組出各泳道共用的 where 片段 */
    $mk = function (string $cliCol, string $dateCol, string $didCol) use ($allCli, $client, $df, $dt, $did) {
        $w = ["$didCol = :did"]; $p = [':did' => $did];
        if (!$allCli) { $w[] = "$cliCol = :cli"; $p[':cli'] = $client; }
        if ($df !== '') { $w[] = "$dateCol >= :df"; $p[':df'] = $df; }
        if ($dt !== '') { $w[] = "$dateCol <= :dt"; $p[':dt'] = $dt; }
        return [implode(' AND ', $w), $p];
    };
    /** 撈一個泳道：先數總筆數，再取前 $lim 筆 */
    $lane = function (string $sql, string $cntSql, array $p) use ($db, $lim) {
        $c = $db->prepare($cntSql); $c->execute($p); $total = (int)$c->fetchColumn();
        $s = $db->prepare($sql);
        foreach ($p as $k => $v) $s->bindValue($k, $v);
        $s->bindValue(':lim', $lim, PDO::PARAM_INT);
        $s->execute();
        return [$s->fetchAll(PDO::FETCH_ASSOC), $total];
    };

    /* ── 訂單 ─────────────────────────────────────────── */
    [$w, $p] = $mk('ot.Client_name', 'ot.Order_date', 'ot.d_id_ID');
    [$rows, $totOrder] = $lane(
        "SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Client_name, ot.Qty, ot.unit_price,
                ot.Order_status, ot.quote_no, ot.quote_item_id,
                DATE_FORMAT(ot.Order_date,'%Y-%m-%d') d1,
                DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') d2
           FROM order_track ot WHERE $w ORDER BY ot.Order_date $dir, ot.Order_id $dir LIMIT :lim",
        "SELECT COUNT(*) FROM order_track ot WHERE $w", $p);
    $orders = [];
    foreach ($rows as $r) $orders[] = [
        'kind' => 'order', 'id' => (int)$r['Order_id'], 'no' => (string)$r['Order_oo'],
        'date' => (string)$r['d1'], 'date2' => (string)$r['d2'], 'qty' => (int)$r['Qty'],
        'price' => (float)$r['unit_price'], 'part' => (string)$r['d_id'],
        'client' => (string)$r['Client_name'],
        'closed' => ((string)$r['Order_status'] === '9'),
        'quote_no' => (string)$r['quote_no'], 'quote_item_id' => (int)$r['quote_item_id'],
    ];

    /* ── 製令 BOM（沒有日期欄位，用 Created_At）───────── */
    [$w, $p] = $mk('b.Client_Name', 'DATE(b.Created_At)', 'b.d_setting_id');
    [$rows, $totBom] = $lane(
        "SELECT b.bom, b.d_id, b.Client_Name, b.sqty, b.o_order_id, b.processing_state, b.state,
                DATE_FORMAT(b.Created_At,'%Y-%m-%d') d1,
                DATE_FORMAT(b.Delivery_date,'%Y-%m-%d') d2
           FROM bom b WHERE $w ORDER BY b.Created_At $dir, b.bom $dir LIMIT :lim",
        "SELECT COUNT(*) FROM bom b WHERE $w", $p);
    $boms = [];
    foreach ($rows as $r) $boms[] = [
        'kind' => 'bom', 'id' => (string)$r['bom'], 'no' => (string)$r['bom'],
        'date' => (string)$r['d1'], 'date2' => (string)$r['d2'], 'qty' => (int)$r['sqty'],
        'price' => 0.0, 'part' => (string)$r['d_id'], 'client' => (string)$r['Client_Name'],
        'closed' => ((string)$r['processing_state'] === '1'),
        'legacy_order' => (int)$r['o_order_id'],
    ];

    /* ── 出貨單 ───────────────────────────────────────── */
    [$w, $p] = $mk('il.Client_name', 'il.Order_date', 'il.d_setting_id');
    [$rows, $totShip] = $lane(
        "SELECT il.IS_id, il.IS_number, il.Product_id, il.Client_name, il.Qty, il.Unit_price, il.Order_id,
                DATE_FORMAT(il.Order_date,'%Y-%m-%d') d1
           FROM is_list il WHERE $w ORDER BY il.Order_date $dir, il.IS_id $dir LIMIT :lim",
        "SELECT COUNT(*) FROM is_list il WHERE $w", $p);
    $ships = [];
    foreach ($rows as $r) $ships[] = [
        'kind' => 'ship', 'id' => (int)$r['IS_id'], 'no' => (string)$r['IS_number'],
        'date' => (string)$r['d1'], 'date2' => '', 'qty' => (int)$r['Qty'],
        'price' => (float)$r['Unit_price'], 'part' => (string)$r['Product_id'],
        'client' => (string)$r['Client_name'], 'closed' => false,
        'legacy_order' => (int)$r['Order_id'],
    ];

    /* ── 退貨單 ───────────────────────────────────────── */
    [$w, $p] = $mk('ir.Client_name', 'ir.IR_date', 'ir.d_setting_id');
    [$rows, $totRet] = $lane(
        "SELECT ir.IR_id, ir.IR_no, ir.d_id, ir.Client_name, ir.Qty, ir.Unit_price, ir.IR_ps, ir.IR_status,
                DATE_FORMAT(ir.IR_date,'%Y-%m-%d') d1
           FROM ir_track ir WHERE $w ORDER BY ir.IR_date $dir, ir.IR_id $dir LIMIT :lim",
        "SELECT COUNT(*) FROM ir_track ir WHERE $w", $p);
    $rets = [];
    foreach ($rows as $r) $rets[] = [
        'kind' => 'ret', 'id' => (int)$r['IR_id'], 'no' => (string)$r['IR_no'],
        'date' => (string)$r['d1'], 'date2' => '', 'qty' => (int)$r['Qty'],
        'price' => (float)$r['Unit_price'], 'part' => (string)$r['d_id'],
        'client' => (string)$r['Client_name'], 'closed' => ((string)$r['IR_status'] === '9'),
        'note' => (string)$r['IR_ps'],
    ];

    /* ── 報價單：由訂單身上的綁定反查（報價本身不掛客戶料號篩選，
     *    因為一份報價可能對應非常多訂單，只列真的被用到的那幾筆）── */
    $quotes = []; $totQuote = 0;
    $qids = [];
    foreach ($orders as $o) if ($o['quote_item_id'] > 0) $qids[$o['quote_item_id']] = 1;
    if ($qids) {
        $ph = implode(',', array_fill(0, count($qids), '?'));
        $sq = $db->prepare("
            SELECT qi.item_id, qi.product_id, qi.quantity, qi.unit_price, qi.d_setting_d_id,
                   ql.quote_no, ql.client_name, DATE_FORMAT(ql.quote_date,'%Y-%m-%d') d1
              FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id = qi.quote_id
             WHERE qi.item_id IN ($ph)");
        $sq->execute(array_keys($qids));
        foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $r) $quotes[] = [
            'kind' => 'quote', 'id' => (int)$r['item_id'], 'no' => (string)$r['quote_no'],
            'date' => (string)$r['d1'], 'date2' => '', 'qty' => (int)$r['quantity'],
            'price' => (float)$r['unit_price'], 'part' => (string)$r['product_id'],
            'client' => (string)$r['client_name'], 'closed' => false,
        ];
        usort($quotes, function ($a, $b) use ($asc) {
            $c = strcmp($a['date'], $b['date']); return $asc ? $c : -$c;
        });
        $totQuote = count($quotes);
    }

    /* ── 連結 ─────────────────────────────────────────── */
    $oid = array_column($orders, 'id');
    $bid = array_column($boms, 'id');
    $sid = array_column($ships, 'id');
    $rid = array_column($rets, 'id');
    $links = [];
    $add = function ($type, $a, $b, $qty, $src = '') use (&$links) {
        $links[] = ['type' => $type, 'a' => $a, 'b' => $b, 'qty' => (int)$qty, 'src' => $src];
    };
    $inSql = function (array $ids) { return implode(',', array_fill(0, count($ids), '?')); };

    // 報價 → 訂單
    foreach ($orders as $o) if ($o['quote_item_id'] > 0)
        $add('quote_order', 'quote:' . $o['quote_item_id'], 'order:' . $o['id'], $o['qty'], 'order_track');

    // 訂單 → 製令（分配表為主，bom.o_order_id 為沒有分配列時的回退）
    $bomLinked = [];
    if ($bid) {
        $st = $db->prepare("SELECT bom, order_id, allocated_qty FROM bom_order_process_map
                             WHERE bom IN (" . $inSql($bid) . ")");
        $st->execute($bid);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add('order_bom', 'order:' . (int)$r['order_id'], 'bom:' . $r['bom'], (int)$r['allocated_qty'], 'map');
            $bomLinked[$r['bom']] = 1;
        }
    }
    foreach ($boms as $b)
        if (empty($bomLinked[$b['id']]) && $b['legacy_order'] > 0)
            $add('order_bom', 'order:' . $b['legacy_order'], 'bom:' . $b['id'], $b['qty'], 'legacy');

    // 訂單 → 出貨（分配表為主，is_list.Order_id 為回退）
    $shipLinked = [];
    if ($sid) {
        $st = $db->prepare("SELECT IS_id, Order_id, allocated_qty FROM is_order_map
                             WHERE IS_id IN (" . $inSql($sid) . ")");
        $st->execute($sid);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add('order_ship', 'order:' . (int)$r['Order_id'], 'ship:' . (int)$r['IS_id'], (int)$r['allocated_qty'], 'map');
            $shipLinked[(int)$r['IS_id']] = 1;
        }
        // 製令 → 出貨
        $st = $db->prepare("SELECT IS_id, bom, shipped_qty FROM is_bom_map
                             WHERE IS_id IN (" . $inSql($sid) . ")");
        $st->execute($sid);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $add('bom_ship', 'bom:' . $r['bom'], 'ship:' . (int)$r['IS_id'], (int)$r['shipped_qty'], 'map');
    }
    foreach ($ships as $s)
        if (empty($shipLinked[$s['id']]) && $s['legacy_order'] > 0)
            $add('order_ship', 'order:' . $s['legacy_order'], 'ship:' . $s['id'], $s['qty'], 'legacy');

    // 出貨/訂單 → 退貨
    if ($rid) {
        $st = $db->prepare("SELECT IR_id, Order_id, return_qty, IS_id FROM return_order_map
                             WHERE IR_id IN (" . $inSql($rid) . ")");
        $st->execute($rid);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['IS_id'] > 0)
                $add('ship_return', 'ship:' . (int)$r['IS_id'], 'ret:' . (int)$r['IR_id'], (int)$r['return_qty'], 'map');
            elseif ((int)$r['Order_id'] > 0)
                $add('ship_return', 'order:' . (int)$r['Order_id'], 'ret:' . (int)$r['IR_id'], (int)$r['return_qty'], 'order');
        }
        // 退貨 → 重出出貨
        $st = $db->prepare("SELECT IR_id, IS_id, qty FROM ir_reship_map WHERE IR_id IN (" . $inSql($rid) . ")");
        $st->execute($rid);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $add('return_ship', 'ret:' . (int)$r['IR_id'], 'ship:' . (int)$r['IS_id'], (int)$r['qty'], 'map');
    }

    return [
        'part'   => ['d_id' => $did, 'part_no' => $orders[0]['part'] ?? ($ships[0]['part'] ?? ''), 'client' => $client],
        'lanes'  => [
            'quote' => ['name' => '報價單', 'rows' => $quotes, 'total' => $totQuote],
            'order' => ['name' => '訂單',   'rows' => $orders, 'total' => $totOrder],
            'bom'   => ['name' => '製令',   'rows' => $boms,   'total' => $totBom],
            'ship'  => ['name' => '出貨單', 'rows' => $ships,  'total' => $totShip],
            'ret'   => ['name' => '退貨單', 'rows' => $rets,   'total' => $totRet],
        ],
        'links'  => $links,
        'limit'  => $lim,
    ];
}

/* ============================================================
 * is_list.Order_id 同步：一張出貨分屬多張訂單時，取分配量最大的那一張當「主要訂單」。
 * 這是唯一寫入點，其他地方一律不要自己去改 is_list.Order_id。
 * ============================================================ */
function tc_sync_is_order(PDO $db, int $isId): void
{
    if ($isId <= 0) return;
    $st = $db->prepare("SELECT Order_id FROM is_order_map WHERE IS_id = ?
                        ORDER BY allocated_qty DESC, Order_id ASC LIMIT 1");
    $st->execute([$isId]);
    $main = $st->fetchColumn();
    $up = $db->prepare("UPDATE is_list SET Order_id = ? WHERE IS_id = ?");
    $up->execute([$main !== false ? (int)$main : null, $isId]);
}

/* ============================================================
 * 舊欄位的既有綁定要先搬進分配表，否則會安靜掉資料。
 *
 * 情境：某張出貨單靠「舊資料訂單回填」寫了 is_list.Order_id=A（分配表裡一列都沒有，
 * 畫面是靠回退邏輯把它畫出來的）。使用者在追溯視窗把它再分一部分給訂單 B，
 * 分配表就只會有 B 一列，接著 tc_sync_is_order() 把 Order_id 改成 B——
 * **原本的 A 就這樣不見了，而且不會報錯**。所以第一次寫分配表之前先把舊值補成一列。
 * 製令的 bom.o_order_id 同理。
 * ============================================================ */
function tc_seed_is_order(PDO $db, int $isId): void
{
    if ($isId <= 0) return;
    $c = $db->prepare("SELECT COUNT(*) FROM is_order_map WHERE IS_id=?");
    $c->execute([$isId]);
    if ((int)$c->fetchColumn() > 0) return;                 // 已經有分配列就不必補
    $s = $db->prepare("SELECT Order_id, Qty FROM is_list WHERE IS_id=?");
    $s->execute([$isId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int)$r['Order_id'] <= 0) return;            // 本來就沒綁
    $db->prepare("INSERT INTO is_order_map (IS_id, Order_id, allocated_qty, created_by)
                  VALUES (?,?,?,'legacy')")
       ->execute([$isId, (int)$r['Order_id'], (int)$r['Qty']]);
}
function tc_seed_bom_order(PDO $db, string $bom): void
{
    if ($bom === '') return;
    $c = $db->prepare("SELECT COUNT(*) FROM bom_order_process_map WHERE bom=?");
    $c->execute([$bom]);
    if ((int)$c->fetchColumn() > 0) return;
    $s = $db->prepare("SELECT o_order_id, sqty FROM bom WHERE bom=?");
    $s->execute([$bom]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int)$r['o_order_id'] <= 0) return;
    $db->prepare("INSERT INTO bom_order_process_map (bom, order_id, allocated_qty) VALUES (?,?,?)")
       ->execute([$bom, (int)$r['o_order_id'], (int)$r['sqty']]);
}

/* ============================================================
 * 取得單一節點（驗證與剩餘量計算用）
 * ============================================================ */
function tc_node(PDO $db, string $kind, $id): ?array
{
    switch ($kind) {
        case 'quote':
            $st = $db->prepare("SELECT qi.item_id id, ql.quote_no no, qi.quantity qty, qi.product_id part,
                                       ql.client_name client
                                  FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                                 WHERE qi.item_id=?"); break;
        case 'order':
            $st = $db->prepare("SELECT Order_id id, Order_oo no, Qty qty, d_id part, Client_name client
                                  FROM order_track WHERE Order_id=?"); break;
        case 'bom':
            $st = $db->prepare("SELECT bom id, bom no, sqty qty, d_id part, Client_Name client
                                  FROM bom WHERE bom=?"); break;
        case 'ship':
            $st = $db->prepare("SELECT IS_id id, IS_number no, Qty qty, Product_id part, Client_name client
                                  FROM is_list WHERE IS_id=?"); break;
        case 'ret':
            $st = $db->prepare("SELECT IR_id id, IR_no no, Qty qty, d_id part, Client_name client
                                  FROM ir_track WHERE IR_id=?"); break;
        default: return null;
    }
    $st->execute([$kind === 'bom' ? (string)$id : (int)$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** 這個節點在某個連結型別上已經分配掉多少（用來算剩餘可分配量） */
function tc_allocated(PDO $db, string $type, string $kind, $id, $exceptOther = null): int
{
    $q = null; $par = [];
    switch ($type) {
        case 'order_bom':
            if ($kind === 'bom')   { $q = "SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE bom=?"; $par = [(string)$id]; }
            else                   { $q = "SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id=?"; $par = [(int)$id]; }
            break;
        case 'order_ship':
            if ($kind === 'ship')  { $q = "SELECT COALESCE(SUM(allocated_qty),0) FROM is_order_map WHERE IS_id=?"; $par = [(int)$id]; }
            else                   { $q = "SELECT COALESCE(SUM(allocated_qty),0) FROM is_order_map WHERE Order_id=?"; $par = [(int)$id]; }
            break;
        case 'bom_ship':
            if ($kind === 'ship')  { $q = "SELECT COALESCE(SUM(shipped_qty),0) FROM is_bom_map WHERE IS_id=?"; $par = [(int)$id]; }
            else                   { $q = "SELECT COALESCE(SUM(shipped_qty),0) FROM is_bom_map WHERE bom=?"; $par = [(string)$id]; }
            break;
        case 'ship_return':
            if ($kind === 'ret')   { $q = "SELECT COALESCE(SUM(return_qty),0) FROM return_order_map WHERE IR_id=?"; $par = [(int)$id]; }
            elseif ($kind === 'ship') { $q = "SELECT COALESCE(SUM(return_qty),0) FROM return_order_map WHERE IS_id=?"; $par = [(int)$id]; }
            else                   { $q = "SELECT COALESCE(SUM(return_qty),0) FROM return_order_map WHERE Order_id=? AND (IS_id IS NULL OR IS_id=0)"; $par = [(int)$id]; }
            break;
        case 'return_ship':
            if ($kind === 'ret')   { $q = "SELECT COALESCE(SUM(qty),0) FROM ir_reship_map WHERE IR_id=?"; $par = [(int)$id]; }
            else                   { $q = "SELECT COALESCE(SUM(qty),0) FROM ir_reship_map WHERE IS_id=?"; $par = [(int)$id]; }
            break;
        default: return 0;
    }
    $st = $db->prepare($q); $st->execute($par);
    return (int)$st->fetchColumn();
}

/* ============================================================
 * 建立／更新一條連結（拖放綁定）
 * 規則（鐵律8：前端擋一次、這裡同規則再擋一次）：
 *   - 型別必須合法、兩端節點都必須存在
 *   - 分配量 > 0，且不可超過「這張單自己的數量」（源與標的兩邊都檢查）
 *   - 客戶簡稱不同一律擋下（把和大的出貨算進旭陽的訂單一定是錯的）
 *   - 料號不同只警示不擋（訂單常下組合件名稱、製作時才拆成子件料號）
 * ============================================================ */
function tc_link(PDO $db, string $type, $fromId, $toId, int $qty, ?array $user = null, string $srcKind = ''): array
{
    $types = tc_link_types();
    if (!isset($types[$type])) return ['success' => false, 'message' => '不支援的連結類型'];
    [$label, $ka, $kb] = $types[$type];

    // ship_return 的來源可以是出貨單，也可以是訂單（退貨追不到是哪張出貨退回時）
    if ($srcKind !== 'order' || $type !== 'ship_return') $srcKind = $ka;

    $a = tc_node($db, $srcKind, $fromId);
    $b = tc_node($db, $kb, $toId);
    if (!$a) return ['success' => false, 'message' => '找不到來源單據'];
    if (!$b) return ['success' => false, 'message' => '找不到目標單據'];

    // 先把舊欄位上的既有綁定補成分配列，剩餘量才算得對，也才不會被後續同步洗掉
    if ($type === 'order_ship') tc_seed_is_order($db, (int)$toId);
    if ($type === 'order_bom')  tc_seed_bom_order($db, (string)$toId);

    $warn = [];
    /* 報價→訂單不套數量與客戶檢查：一份報價本來就會對應到非常多張訂單（不是把量拆掉），
       而報價單存的客戶欄位與出貨用的客戶簡稱格式不同，硬比會全部擋掉。
       這條一律交給既有唯一實作 acc_recon_bind_quote()，它會比對料號並留稽核。 */
    if ($type !== 'quote_order') {
        if ($qty <= 0) return ['success' => false, 'message' => '分配數量必須大於 0'];

        $ca = trim((string)$a['client']); $cb = trim((string)$b['client']);
        if ($ca !== '' && $cb !== '' && $ca !== $cb)
            return ['success' => false, 'message' => "客戶不同不可對應：{$a['no']}（{$ca}）↔ {$b['no']}（{$cb}）"];

        // 分配量不可超過單據本身的數量（要先扣掉這一條既有分配，否則改量會被自己擋住）
        $curA = tc_allocated($db, $type, $srcKind, $fromId);
        $curB = tc_allocated($db, $type, $kb, $toId);
        $old  = tc_link_qty($db, $type, $srcKind, $fromId, $toId);
        $freeA = (int)$a['qty'] - ($curA - $old);
        $freeB = (int)$b['qty'] - ($curB - $old);
        if ($qty > $freeA) return ['success' => false,
            'message' => "{$a['no']} 只剩 {$freeA} 可分配（本身 {$a['qty']}，已分配 " . ($curA - $old) . "）"];
        if ($qty > $freeB) return ['success' => false,
            'message' => "{$b['no']} 只剩 {$freeB} 可分配（本身 {$b['qty']}，已分配 " . ($curB - $old) . "）"];

        // 料號不同只警示不擋：訂單常下組合件名稱、製作時才拆成子件料號
        if (trim((string)$a['part']) !== '' && trim((string)$b['part']) !== ''
            && strcasecmp(trim((string)$a['part']), trim((string)$b['part'])) !== 0)
            $warn[] = "料號不同（{$a['part']} ↔ {$b['part']}），若是組合件拆件請確認無誤";
    }

    $uid = $user ? (string)($user['id'] ?? '') : null;
    try {
        $db->beginTransaction();
        switch ($type) {
            case 'quote_order':
                // 走既有唯一實作（含料號比對與稽核），它自己不開 transaction
                $r = acc_recon_bind_quote($db, (int)$toId, (int)$fromId, $user);
                if (empty($r['success'])) { $db->rollBack(); return $r; }
                break;
            case 'order_bom':
                tc_upsert($db, 'bom_order_process_map', ['bom' => (string)$toId, 'order_id' => (int)$fromId],
                          ['allocated_qty' => $qty]);
                break;
            case 'order_ship':
                tc_upsert($db, 'is_order_map', ['IS_id' => (int)$toId, 'Order_id' => (int)$fromId],
                          ['allocated_qty' => $qty, 'created_by' => $uid]);
                tc_sync_is_order($db, (int)$toId);
                break;
            case 'bom_ship':
                tc_upsert($db, 'is_bom_map', ['IS_id' => (int)$toId, 'bom' => (string)$fromId],
                          ['shipped_qty' => $qty]);
                break;
            case 'ship_return':
                if ($srcKind === 'order')
                    tc_upsert($db, 'return_order_map', ['IR_id' => (int)$toId, 'Order_id' => (int)$fromId, 'IS_id' => null],
                              ['return_qty' => $qty]);
                else {
                    // 出貨單→退貨單：Order_id 是 NOT NULL，帶入該出貨目前的主要訂單（沒有就 0）
                    $mo = $db->prepare("SELECT COALESCE(Order_id,0) FROM is_list WHERE IS_id=?");
                    $mo->execute([(int)$fromId]);
                    tc_upsert($db, 'return_order_map',
                              ['IR_id' => (int)$toId, 'IS_id' => (int)$fromId],
                              ['return_qty' => $qty, 'Order_id' => (int)$mo->fetchColumn()]);
                }
                break;
            case 'return_ship':
                tc_upsert($db, 'ir_reship_map', ['IR_id' => (int)$fromId, 'IS_id' => (int)$toId],
                          ['qty' => $qty, 'created_by' => $uid]);
                break;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '寫入失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'message' => "已建立對應：{$a['no']} → {$b['no']}（{$qty}）",
            'warn' => $warn];
}

/** 這一條連結目前已經分配了多少（不存在回 0） */
function tc_link_qty(PDO $db, string $type, string $srcKind, $fromId, $toId): int
{
    switch ($type) {
        case 'order_bom':
            $st = $db->prepare("SELECT COALESCE(allocated_qty,0) FROM bom_order_process_map WHERE bom=? AND order_id=?");
            $st->execute([(string)$toId, (int)$fromId]); break;
        case 'order_ship':
            $st = $db->prepare("SELECT COALESCE(allocated_qty,0) FROM is_order_map WHERE IS_id=? AND Order_id=?");
            $st->execute([(int)$toId, (int)$fromId]); break;
        case 'bom_ship':
            $st = $db->prepare("SELECT COALESCE(shipped_qty,0) FROM is_bom_map WHERE IS_id=? AND bom=?");
            $st->execute([(int)$toId, (string)$fromId]); break;
        case 'ship_return':
            if ($srcKind === 'order') {
                $st = $db->prepare("SELECT COALESCE(return_qty,0) FROM return_order_map
                                     WHERE IR_id=? AND Order_id=? AND (IS_id IS NULL OR IS_id=0)");
                $st->execute([(int)$toId, (int)$fromId]);
            } else {
                $st = $db->prepare("SELECT COALESCE(return_qty,0) FROM return_order_map WHERE IR_id=? AND IS_id=?");
                $st->execute([(int)$toId, (int)$fromId]);
            }
            break;
        case 'return_ship':
            $st = $db->prepare("SELECT COALESCE(qty,0) FROM ir_reship_map WHERE IR_id=? AND IS_id=?");
            $st->execute([(int)$fromId, (int)$toId]); break;
        default: return 0;
    }
    $v = $st->fetchColumn();
    return $v === false ? 0 : (int)$v;
}

/** 依鍵欄位 upsert（這幾張表的唯一鍵不一致，統一在這裡處理，呼叫端不必各自寫） */
function tc_upsert(PDO $db, string $table, array $keys, array $vals): void
{
    $w = []; $p = [];
    foreach ($keys as $k => $v) {
        if ($v === null) { $w[] = "($k IS NULL OR $k = 0)"; }
        else { $w[] = "$k = ?"; $p[] = $v; }
    }
    $st = $db->prepare("SELECT id FROM $table WHERE " . implode(' AND ', $w) . " LIMIT 1");
    $st->execute($p);
    $id = $st->fetchColumn();
    if ($id !== false) {
        $set = []; $sp = [];
        foreach ($vals as $k => $v) { $set[] = "$k = ?"; $sp[] = $v; }
        $sp[] = (int)$id;
        $db->prepare("UPDATE $table SET " . implode(',', $set) . " WHERE id = ?")->execute($sp);
        return;
    }
    $ins = array_merge(array_filter($keys, function ($v) { return $v !== null; }), $vals);
    $cols = implode(',', array_keys($ins));
    $ph   = implode(',', array_fill(0, count($ins), '?'));
    $db->prepare("INSERT INTO $table ($cols) VALUES ($ph)")->execute(array_values($ins));
}

/* ============================================================
 * 解除一條連結
 * ============================================================ */
function tc_unlink(PDO $db, string $type, $fromId, $toId, ?array $user = null, string $srcKind = ''): array
{
    $types = tc_link_types();
    if (!isset($types[$type])) return ['success' => false, 'message' => '不支援的連結類型'];
    if ($srcKind !== 'order' || $type !== 'ship_return') $srcKind = $types[$type][1];
    try {
        $db->beginTransaction();
        switch ($type) {
            case 'quote_order':
                $r = acc_recon_bind_quote($db, (int)$toId, 0, $user);
                if (empty($r['success'])) { $db->rollBack(); return $r; }
                break;
            case 'order_bom':
                $db->prepare("DELETE FROM bom_order_process_map WHERE bom=? AND order_id=?")
                   ->execute([(string)$toId, (int)$fromId]);
                // 舊欄位若指向這張訂單，一併清掉，否則回退邏輯會把它又畫出來
                $db->prepare("UPDATE bom SET o_order_id=NULL WHERE bom=? AND o_order_id=?")
                   ->execute([(string)$toId, (int)$fromId]);
                break;
            case 'order_ship':
                $db->prepare("DELETE FROM is_order_map WHERE IS_id=? AND Order_id=?")
                   ->execute([(int)$toId, (int)$fromId]);
                tc_sync_is_order($db, (int)$toId);
                break;
            case 'bom_ship':
                $db->prepare("DELETE FROM is_bom_map WHERE IS_id=? AND bom=?")
                   ->execute([(int)$toId, (string)$fromId]);
                break;
            case 'ship_return':
                if ($srcKind === 'order')
                    $db->prepare("DELETE FROM return_order_map WHERE IR_id=? AND Order_id=? AND (IS_id IS NULL OR IS_id=0)")
                       ->execute([(int)$toId, (int)$fromId]);
                else
                    $db->prepare("DELETE FROM return_order_map WHERE IR_id=? AND IS_id=?")
                       ->execute([(int)$toId, (int)$fromId]);
                break;
            case 'return_ship':
                $db->prepare("DELETE FROM ir_reship_map WHERE IR_id=? AND IS_id=?")
                   ->execute([(int)$fromId, (int)$toId]);
                break;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '解除失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'message' => '已解除對應'];
}
