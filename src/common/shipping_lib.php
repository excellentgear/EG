<?php
/**
 * src/common/shipping_lib.php — 出貨作業共用函式庫
 *
 * 供 views/Sales/Shipping_Quick.php（新版快速出貨）與 src/store/Shipping_API.php 共用。
 *
 * ⚠ 重要：製令「完工可出量」一律呼叫 sq_bom_avail_map()。
 *   舊版 Quick_Shipping.php 用 `SUM(sqty) WHERE processing_state='E'` 是錯的——
 *   bom_ing.bom_sn 是「製程序號」(10/20/30…)，每一列的 sqty 都是同一批的數量，
 *   跨製程加總會把 10 支的製令算成 40 支，且最後一道還在加工中也會被當成可出。
 *   正確：bom.processing_state='1'(ERP結案) → bom.sqty；
 *         否則看最後一道製程(MAX bom_sn)是否為 'E'(生管已移轉) → 取該道 sqty；否則 0。
 */

require_once __DIR__ . '/gear_spec_lib.php';   // 齒輪規格（與報價單/訂單追蹤同一份實作）
require_once __DIR__ . '/date_fmt_lib.php';  // 顯示用日期 YYYY.MM.DD（ai-rules/20 唯一實作）
require_once __DIR__ . '/qa_abnormal_lib.php'; // 報廐扣減唯一實作 qab_bom_scrap_rows()（2026-09-24）

if (!defined('SQ_MODULE')) define('SQ_MODULE', 'shipping');
/** AS 文件編號綁定用的模組代碼（見 ai-rules/16 第一之三節） */
if (!defined('SQ_ASDOC_MODULE')) define('SQ_ASDOC_MODULE', 'shipping_note');
/** 手動改選訂單時，候選清單一次最多列幾張（取離出貨日最近的那些） */
if (!defined('SQ_CAND_LIMIT')) define('SQ_CAND_LIMIT', 300);

/* ============================================================
 * 權限（RBAC，比照 vendor_audit_lib.php）
 * ============================================================ */
function sq_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname = ?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sq_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                        WHERE ur.user_id = ? AND r.module = '" . SQ_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id = m.position_id AND (pr.department_id=0 OR pr.department_id=m.department_id)
                        JOIN roles r ON r.role_id = pr.role_id
                        WHERE m.user_id = ? AND r.module = '" . SQ_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function sq_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canEdit' => false,
                     'canView' => false, 'canDelete' => false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin  || sq_has_role($db, $uid, ['shipping_admin']);
    $canEdit  = $canAdmin || sq_has_role($db, $uid, ['shipping_edit']);
    $canView  = $canEdit  || sq_has_role($db, $uid, ['shipping_view']);
    /* 刪除出貨單是獨立角色、不含在管理員以下的階層裡：刪一張已出的貨會把數量退回訂單、
       還可能把已結案的訂單重新打開，不應該因為「有管理員角色」就順帶取得（使用者要求另開角色）。 */
    $canDelete = $isAdmin || sq_has_role($db, $uid, ['shipping_delete']);
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canEdit' => $canEdit,
            'canView' => $canView, 'canDelete' => $canDelete];
}

/* ============================================================
 * 品名規格：全站唯一組法
 * ============================================================ */

/**
 * 「品名規格」欄位字串＝ 料號規格＋齒輪規格 ／ 製程 ／ 料號備註。
 * 與報價單列印版（views/Sales/quotation_list_NEW.php）同一套規則，
 * 清單／CSV／出貨單明細／列印一律呼叫這一支，不要各自 join 一次（鐵律4）。
 */
function sq_desc_text(?string $specNo, ?string $gearSpec, ?string $process, ?string $remark): string
{
    $left  = implode(' ', array_filter([trim((string)$specNo), trim((string)$gearSpec)], fn($v) => $v !== ''));
    $parts = array_filter([$left, trim((string)$process), trim((string)$remark)], fn($v) => $v !== '');
    return implode(' / ', $parts);
}

/* ============================================================
 * 製令完工可出量
 * ============================================================ */

/**
 * 取得製令的完工／已出／可出量。
 *
 * @param array|null $boms 指定製令號；null = 全部（僅取 done_qty > 0 者）
 * @return array bom => ['done'=>int,'shipped'=>int,'avail'=>int,'closed'=>bool]
 */
function sq_bom_avail_map(PDO $db, ?array $boms = null): array
{
    $params = [];
    $where  = '';
    if ($boms !== null) {
        $boms = array_values(array_unique(array_filter($boms, fn($b) => $b !== '' && $b !== null)));
        if (!$boms) return [];
        $ph     = implode(',', array_fill(0, count($boms), '?'));
        $where  = "WHERE b.bom IN ($ph)";
        $params = $boms;
    }

    // 最後一道製程（同 bom_sn 有重複列時取 bom_ing_fid 最大者）
    $sql = "
        SELECT b.bom,
               CASE WHEN b.processing_state = '1' THEN b.sqty
                    WHEN bl.processing_state = 'E' THEN bl.sqty
                    ELSE 0 END                       AS done_qty,
               COALESCE(sm.shipped, 0)               AS shipped_qty,
               CASE WHEN b.processing_state = '1' THEN 1 ELSE 0 END AS erp_closed
        FROM bom b
        LEFT JOIN (
            SELECT bi.bom, bi.processing_state, bi.sqty
            FROM bom_ing bi
            JOIN (SELECT bom, MAX(bom_sn) AS msn FROM bom_ing GROUP BY bom) mx
              ON mx.bom = bi.bom AND mx.msn = bi.bom_sn
            JOIN (SELECT bom, bom_sn, MAX(bom_ing_fid) AS mf FROM bom_ing GROUP BY bom, bom_sn) dd
              ON dd.bom = bi.bom AND dd.bom_sn = bi.bom_sn AND dd.mf = bi.bom_ing_fid
        ) bl ON bl.bom = b.bom
        LEFT JOIN (SELECT m.bom, SUM(m.shipped_qty) AS shipped
                   FROM is_bom_map m
                   JOIN is_list il ON il.IS_id = m.IS_id      -- 出貨明細被刪掉時，殘留的分配列不可再算成「已出」
                   GROUP BY m.bom) sm
          ON sm.bom = b.bom
        $where";

    $st = $db->prepare($sql);
    $st->execute($params);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // 完工量要扣掉「已結案配發報廐單號」的確認報廐量（2026-09-24 使用者交辦）——不扣的話待包裝／
    // BOM總覽都已經顯示良品數變少了，快速出貨這裡卻還能出到報廐前的滿額，兩邊會對不起來。
    // 整張 BOM 口徑不分站（可出量本來就是整批的概念）；一次批次查完，不逐列各查一次（鐵律8效能考量）。
    $scrapMap = qab_bom_scrap_rows($db, array_column($rows, 'bom'));

    $map = [];
    foreach ($rows as $r) {
        $done    = (int)$r['done_qty'];
        $shipped = (int)$r['shipped_qty'];
        if ($boms === null && $done <= 0) continue;   // 全域查詢時略過未完工，減少記憶體
        $scrap   = qab_bom_scrap_sum_rows($scrapMap[$r['bom']] ?? [], null);
        $done    = max(0, $done - $scrap);
        $map[$r['bom']] = [
            'done'    => $done,
            'shipped' => $shipped,
            'avail'   => max(0, $done - $shipped),
            'closed'  => ((int)$r['erp_closed'] === 1),
        ];
    }
    return $map;
}

/* ============================================================
 * 製令「目前製程」（未完工的製令走到哪一關了）
 * ============================================================ */

/** processing_state 代碼 → 中文（與 views/pm 的製程狀態用語一致） */
function sq_proc_state_text(?string $st): string
{
    return [
        'N'   => '未發包',
        'ing' => '加工中',
        'Q'   => 'QC待驗',
        'P'   => '生管待移轉',
        'E'   => '已移轉',
        '1'   => '已結案',
    ][(string)$st] ?? (string)$st;
}

/**
 * 「目前製程」的顯示文字（唯一實作：清單、製令明細、CSV 匯出全部用這一份，
 * 前端只負責把字印出來，不要在 JS 再組一次，否則三個地方遲早寫出三種說法）。
 *
 * @param array|null $p sq_bom_progress_map() 的一筆；null＝已完工
 * @param bool $long true＝連狀態／廠商／日期一起帶（明細與 CSV 用）
 */
function sq_progress_label(?array $p, bool $long = false): string
{
    if ($p === null) return '';
    if (empty($p['started'])) {
        // 一關都還沒發包：寫「第 0 關」沒有意義，直接講還沒開工、以及第一關是什麼
        $txt = '尚未開工';
        if ($p['process'] !== '') $txt .= '（第一關：' . $p['process'] . '）';
        return $txt;
    }
    $txt = ($p['process'] !== '' ? $p['process'] : '（未知製程）')
         . ' ' . $p['step'] . '/' . $p['total'];
    if (!$long) return $txt;

    $txt .= ' ' . $p['state_txt'];
    if ($p['next'] !== '') $txt .= '，下一關：' . $p['next'];
    /* 日期一定要標明是「發包」還是「檢驗」：狀態=未發包卻印一個日期（那是 QC 檢驗日，
       廠內製程常常沒有發包日）看起來像自相矛盾。 */
    $sub = [$p['maker']];
    if ($p['out_date'] !== '') $sub[] = '發包 ' . eg_fmt_date($p['out_date']);
    if ($p['qc_date']  !== '') $sub[] = '檢驗 ' . eg_fmt_date($p['qc_date']);
    $sub = array_filter($sub, fn($v) => (string)$v !== '');
    if ($sub) $txt .= '（' . implode('・', $sub) . '）';
    return $txt;
}

/**
 * 取得製令目前走到哪一道製程。
 *
 * 判定沿用製令追蹤頁（views/pm/bom_tracking.php ／ BomTrack_API.php 的 get_matched_boms
 * ／get_bom_process_chain）的規則：排除 processing_state='skip'，取「有實際活動（發包日或
 * QC 檢驗日）」的製程中活動日期最新的那一關；一關都還沒有活動＝尚未開工（step=0），
 * 此時不可誤判成最後一關（GREATEST 全為 0000-00-00 時 bom_sn DESC 的陷阱）。
 *
 * ⚠ 與製令追蹤頁唯一的差別（刻意的）：同一天時本函式取 bom_sn 較大者＝走得比較遠的那一關。
 *   outsource_date 一律是 00:00:00（沒有時間）、QC_check_date 有時分秒，直接比 datetime 會讓
 *   「同一天下一關已經發包」輸給「上一關當天剛驗完」，實測 592 張未完工製令中有 54 張因此
 *   被判成上一關（例 B-1150428012：客供料 04-29 10:15 驗完、粗滾 04-29 發包且狀態=加工中，
 *   比 datetime 會答「客供料」）。製令追蹤頁的欄位未動，那邊的進度%與此處口徑相同、
 *   只有同一天的那幾張會差一關。
 *
 * @param array $boms 製令號
 * @return array bom => ['total'=>關數,'step'=>第幾關,'process'=>製程名,'state'=>代碼,
 *                       'state_txt'=>狀態中文,'maker'=>廠商,'date'=>最近活動日 Y-m-d,
 *                       'out_date'=>發包日,'qc_date'=>QC檢驗日,
 *                       'next'=>下一關製程名（目前這關已移轉／已完工時才有）,'started'=>bool]
 */
function sq_bom_progress_map(PDO $db, array $boms): array
{
    $boms = array_values(array_unique(array_filter($boms, fn($b) => $b !== '' && $b !== null)));
    if (!$boms) return [];

    $ph = implode(',', array_fill(0, count($boms), '?'));
    $st = $db->prepare("
        SELECT bi.bom, bi.bom_sn, bi.processing_state, bi.qc_completed,
               COALESCE(bi.maker_id, '')                            AS maker,
               COALESCE(pn.ProcessName, '')                         AS pname,
               DATE_FORMAT(bi.outsource_date, '%Y-%m-%d')           AS out_date,
               DATE_FORMAT(bi.QC_check_date,  '%Y-%m-%d')           AS qc_date
        FROM bom_ing bi
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        WHERE bi.bom IN ($ph)
          AND (bi.processing_state IS NULL OR bi.processing_state <> 'skip')
        ORDER BY bi.bom, bi.bom_sn, bi.bom_ing_fid");
    $st->execute($boms);

    // 先依製令分組（同一個 bom_sn 可能有重複列，取最後一筆＝bom_ing_fid 最大者）
    $byBom = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byBom[$r['bom']][(int)$r['bom_sn']] = $r;
    }

    $map = [];
    foreach ($byBom as $bom => $steps) {
        ksort($steps, SORT_NUMERIC);
        $sns   = array_keys($steps);
        $total = count($sns);

        // 活動日期最新的那一關（同日取 bom_sn 大者）
        $curSn = null; $curKey = '';
        foreach ($steps as $sn => $r) {
            $act = max((string)$r['out_date'], (string)$r['qc_date']);   // 皆為 Y-m-d，字串比較即可
            if ($act === '') continue;
            $key = $act . '|' . str_pad((string)$sn, 6, '0', STR_PAD_LEFT);
            if ($key >= $curKey) { $curKey = $key; $curSn = $sn; }
        }

        if ($curSn === null) {          // 一關都還沒開始
            $first = $steps[$sns[0]];
            $map[$bom] = [
                'total' => $total, 'step' => 0,
                'process' => $first['pname'], 'state' => (string)$first['processing_state'],
                'state_txt' => sq_proc_state_text($first['processing_state']),
                'maker' => $first['maker'], 'date' => '', 'out_date' => '', 'qc_date' => '',
                'next' => $first['pname'], 'started' => false,
            ];
            continue;
        }

        $cur  = $steps[$curSn];
        $step = 0;
        foreach ($sns as $sn) if ($sn <= $curSn) $step++;

        /* 這一關「已移轉」時貨其實是在等下一關發包——只寫「已移轉」會讓人以為卡在這一關，
           所以把下一關的製程名一起帶回去。
           ⚠ 只有 state='E'（生管已移轉）才算：qc_completed=1 但還在 P／Q 的是卡在
           「生管還沒移轉」，那時候寫下一關會把責任指錯地方。 */
        $next = '';
        if ((string)$cur['processing_state'] === 'E') {
            foreach ($sns as $sn) if ($sn > $curSn) { $next = $steps[$sn]['pname']; break; }
        }

        $map[$bom] = [
            'total' => $total, 'step' => $step,
            'process' => $cur['pname'], 'state' => (string)$cur['processing_state'],
            'state_txt' => sq_proc_state_text($cur['processing_state']),
            'maker' => $cur['maker'],
            'date' => max((string)$cur['out_date'], (string)$cur['qc_date']),
            'out_date' => (string)$cur['out_date'], 'qc_date' => (string)$cur['qc_date'],
            'next' => $next, 'started' => true,
        ];
    }
    return $map;
}

/**
 * 取得指定訂單所綁定的製令（兩種綁定方式合併）。
 * A：bom_order_process_map（有分配量）  B：bom.o_order_id = order_track.Order_oo（全量）
 *
 * @return array order_id => [ ['bom'=>,'allocated'=>,'delivery'=>,'priority'=>,'bom_qty'=>,'bom_ps'=>], ... ]
 */
function sq_boms_for_orders(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_map('intval', array_filter($orderIds))));
    if (!$orderIds) return [];
    $ph = implode(',', array_fill(0, count($orderIds), '?'));

    $sql = "
        SELECT bopm.order_id, b.bom, bopm.allocated_qty AS allocated, b.sqty AS bom_qty,
               DATE_FORMAT(b.Delivery_date,'%Y-%m-%d') AS delivery,
               COALESCE(b.priority_type,'') AS priority, COALESCE(b.bom_ps,'') AS bom_ps
        FROM bom_order_process_map bopm
        JOIN bom b ON b.bom = bopm.bom
        WHERE bopm.order_id IN ($ph)
        UNION
        SELECT ot.Order_id AS order_id, b.bom, b.sqty AS allocated, b.sqty AS bom_qty,
               DATE_FORMAT(b.Delivery_date,'%Y-%m-%d') AS delivery,
               COALESCE(b.priority_type,'') AS priority, COALESCE(b.bom_ps,'') AS bom_ps
        FROM bom b
        JOIN order_track ot ON ot.Order_oo = b.o_order_id
        WHERE ot.Order_id IN ($ph)
          AND NOT EXISTS (SELECT 1 FROM bom_order_process_map x
                          WHERE x.bom = b.bom AND x.order_id = ot.Order_id)";

    $st = $db->prepare($sql);
    $st->execute(array_merge($orderIds, $orderIds));

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['order_id']][] = [
            'bom'       => $r['bom'],
            'allocated' => (int)$r['allocated'],
            'bom_qty'   => (int)$r['bom_qty'],
            'delivery'  => $r['delivery'],
            'priority'  => $r['priority'],
            'bom_ps'    => $r['bom_ps'],
        ];
    }
    return $out;
}

/** 以製令號關鍵字反查對應的訂單 Order_id（兩種綁定方式合併，最多 2000 筆） */
function sq_order_ids_by_bom_kw(PDO $db, string $kw): array
{
    $like = '%' . $kw . '%';
    $st = $db->prepare("
        SELECT bopm.order_id AS oid FROM bom_order_process_map bopm WHERE bopm.bom LIKE ?
        UNION
        SELECT ot.Order_id AS oid FROM bom b
        JOIN order_track ot ON ot.Order_oo = b.o_order_id
        WHERE b.bom LIKE ?
        LIMIT 2000");
    $st->execute([$like, $like]);
    return array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'oid'));
}

/* ============================================================
 * 待出貨清單（一列一訂單，製令為附屬資訊）
 * ============================================================ */

/**
 * @param array $f 篩選：kw, client_id, date_from, date_to, only_ready(bool),
 *                       include_paused(bool), page(int), per_page(int), sort
 * @return array ['rows'=>分頁後資料, 'total'=>符合筆數, 'summary'=>全部符合條件的合計]
 */
function sq_pending_orders(PDO $db, array $f): array
{
    // 排除無訂單號／訂單號 NA 的列：多半是廠內治具製作，不是要出給客戶的貨
    $where  = ["(ot.Order_status IS NULL OR ot.Order_status <> 9)",
               "ot.Order_oo IS NOT NULL",
               "TRIM(ot.Order_oo) <> ''",
               "UPPER(REPLACE(TRIM(ot.Order_oo), '.', '')) NOT IN ('NA', 'N/A')"];
    $params = [];

    if (empty($f['include_paused'])) {
        $where[] = "(ot.Order_status IS NULL OR ot.Order_status <> 6)";
    }
    if (!empty($f['client_id'])) {
        $where[] = "ot.Client_name_ID = :client_id";
        $params[':client_id'] = $f['client_id'];
    }
    if (!empty($f['date_from'])) {
        $where[] = "ot.Delivery_date >= :date_from";
        $params[':date_from'] = $f['date_from'];
    }
    if (!empty($f['date_to'])) {
        $where[] = "ot.Delivery_date <= :date_to";
        $params[':date_to'] = $f['date_to'];
    }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        // 單一搜尋框通吃：訂單號／料號／客戶／品名規格／製令號
        // 製令號不用 correlated EXISTS（7800 筆訂單逐列子查詢要 20 秒以上），
        // 改成先一次解析出對應的 Order_id 再用 IN。
        $conds = ["ot.Order_oo LIKE :kw", "ot.d_id LIKE :kw",
                  "ot.Client_name LIKE :kw", "ot.Specification LIKE :kw"];
        $bomOids = sq_order_ids_by_bom_kw($db, $kw);
        if ($bomOids) $conds[] = "ot.Order_id IN (" . implode(',', $bomOids) . ")";
        $where[] = '(' . implode(' OR ', $conds) . ')';
        $params[':kw'] = '%' . $kw . '%';
    }

    $sql = "
        SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.d_id_ID, ot.Specification, ot.Order_ps,
               COALESCE(ot.C_order,'')                   AS c_order,
               ot.Client_name, ot.Client_name_ID, ot.Qty, ot.unit_price, ot.Order_status,
               COALESCE(ot.Processing_items,'')          AS processing_items,
               DATE_FORMAT(ot.Order_date,'%Y-%m-%d')     AS order_date,
               DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d')  AS delivery_date,
               COALESCE(cl.customer, ot.Client_name)     AS client_display,
               cl.customer_id,
               COALESCE(ds.Spec_No,'')                   AS part_spec,
               COALESCE(sh.sq, 0)                        AS shipped_qty
        FROM order_track ot
        LEFT JOIN (SELECT Order_id, SUM(Qty) AS sq FROM is_list
                   WHERE Order_id IS NOT NULL GROUP BY Order_id) sh ON sh.Order_id = ot.Order_id
        LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
        LEFT JOIN d_setting   ds ON ds.d_id = ot.d_id_ID
        WHERE " . implode(' AND ', $where) . "
        HAVING ot.Qty - shipped_qty > 0";

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['rows' => [], 'total' => 0,
                'summary' => ['orders' => 0, 'remain' => 0, 'ready' => 0, 'amount' => 0]];
    }

    // 齒輪規格（一次撈完，不要逐列查）
    $gearMap = eg_gear_spec_map($db, array_column($rows, 'd_id_ID'));

    // 附加製令與可出量
    $orderIds = array_column($rows, 'Order_id');
    $bomsByOrder = sq_boms_for_orders($db, $orderIds);
    $allBoms = [];
    foreach ($bomsByOrder as $list) foreach ($list as $b) $allBoms[] = $b['bom'];
    $availMap = sq_bom_avail_map($db, $allBoms);
    /* 未完工的製令要標出「目前製程」方便判斷還要等多久（使用者要求 2026-09-18）。
       只查未完工的那些（實測全部待出貨訂單 2,289 張製令裡只有 592 張未完工，0.04 秒），
       已完工的製令查它走到哪一關沒有意義。 */
    $undoneBoms = [];
    foreach (array_unique($allBoms) as $bm) {
        if ((int)($availMap[$bm]['done'] ?? 0) <= 0) $undoneBoms[] = $bm;
    }
    $progMap = sq_bom_progress_map($db, $undoneBoms);

    $out = [];
    foreach ($rows as $r) {
        $oid    = (int)$r['Order_id'];
        $remain = (int)$r['Qty'] - (int)$r['shipped_qty'];
        $boms   = $bomsByOrder[$oid] ?? [];

        // 依交期排序製令（FIFO：先到期的先出）
        usort($boms, fn($a, $b) => strcmp($a['delivery'] ?: '9999', $b['delivery'] ?: '9999')
                                   ?: strcmp($a['bom'], $b['bom']));

        $readyTotal = 0;
        $bomView    = [];
        foreach ($boms as $b) {
            $av    = $availMap[$b['bom']] ?? ['done' => 0, 'shipped' => 0, 'avail' => 0, 'closed' => false];
            $canUse = min($av['avail'], $b['allocated'] > 0 ? $b['allocated'] : $av['avail']);
            // 未完工才查目前製程（已完工的製令 progress 一律 null，畫面顯示「已完工」）
            $pg    = ($av['done'] <= 0) ? ($progMap[$b['bom']] ?? null) : null;
            $readyTotal += max(0, $canUse);
            $bomView[] = [
                'bom'       => $b['bom'],
                'bom_qty'   => $b['bom_qty'],
                'allocated' => $b['allocated'],
                'done'      => $av['done'],
                'shipped'   => $av['shipped'],
                'avail'     => max(0, $canUse),
                'closed'    => $av['closed'],
                'delivery'  => $b['delivery'],
                'priority'  => $b['priority'],
                'bom_ps'    => $b['bom_ps'],
                /* undone＝這張製令還沒完工（畫面才要標目前製程）；progress 只有未完工才查。
                   兩個旗標要分開：undone 但查不到製程（bom_ing 一列都沒有）不可顯示成「已完工」。 */
                'undone'    => ($av['done'] <= 0),
                'progress'  => $pg,
                'proc_txt'  => sq_progress_label($pg),          // 「滾齒 9/12」
                'proc_full' => sq_progress_label($pg, true),    // 連狀態廠商日期（提示與 CSV 用）
            ];
        }
        $ready = min($remain, $readyTotal);

        /* 可出量為 0 的原因要講清楚：製令明明已完工、只是被別張出貨吃完了，
           畫面卻寫「無完工」＝使用者一定會以為系統壞掉（2026-09-17 使用者回報）。 */
        $doneSum = array_sum(array_column($bomView, 'done'));
        $readyNote = '';
        if ($ready <= 0) {
            if (!$bomView)          $readyNote = '無製令';
            elseif ($doneSum <= 0)  $readyNote = '無完工';
            else                    $readyNote = '製令已出完';
        }

        /* 清單不展開製令明細也要看得到目前製程（使用者：方便判斷）。
           $bomView 已依交期 FIFO 排序，所以取第一張未完工的那一張＝最該關心的那一張。 */
        $procBrief = ''; $undoneCnt = 0;
        foreach ($bomView as $bv) if ($bv['progress'] !== null) {
            $undoneCnt++;
            if ($procBrief === '') $procBrief = $bv['proc_txt'];
        }
        if ($procBrief !== '' && $undoneCnt > 1) $procBrief .= ' 等 ' . $undoneCnt . ' 張';

        $gear = $r['d_id_ID'] !== null ? ($gearMap[(int)$r['d_id_ID']] ?? '') : '';

        $out[] = [
            'order_id'         => $oid,
            'order_oo'         => $r['Order_oo'],
            'd_id'             => $r['d_id'],
            'd_setting_id'     => $r['d_id_ID'] !== null ? (int)$r['d_id_ID'] : null,
            'specification'    => $r['Specification'],
            'part_spec'        => $r['part_spec'],
            'gear_spec'        => $gear,
            'desc_full'        => sq_desc_text($r['part_spec'], $gear, $r['processing_items'], $r['Specification']),
            'c_order'          => $r['c_order'],
            'order_ps'         => $r['Order_ps'],
            'processing_items' => $r['processing_items'],
            'client_id'        => $r['customer_id'] ?: ($r['Client_name_ID'] ?: null),
            'client_name'      => $r['Client_name'],
            'client_display'   => $r['client_display'],
            'order_qty'        => (int)$r['Qty'],
            'shipped_qty'      => (int)$r['shipped_qty'],
            'remain_qty'       => $remain,
            'ready_qty'        => $ready,
            'unit_price'       => (float)$r['unit_price'],
            'order_date'       => $r['order_date'],
            'delivery_date'    => $r['delivery_date'],
            'order_status'     => $r['Order_status'] !== null ? (int)$r['Order_status'] : null,
            'boms'             => $bomView,
            'bom_count'        => count($bomView),
            'done_qty'         => $doneSum,
            'ready_note'       => $readyNote,
            'proc_brief'       => $procBrief,
        ];
    }

    if (!empty($f['only_ready'])) {
        $out = array_values(array_filter($out, fn($r) => $r['ready_qty'] > 0));
    }

    // 全部符合條件才算合計（不可只用當頁）
    $summary = [
        'orders' => count($out),
        'remain' => array_sum(array_column($out, 'remain_qty')),
        'ready'  => array_sum(array_column($out, 'ready_qty')),
        'amount' => 0,
    ];
    foreach ($out as $r) $summary['amount'] += $r['ready_qty'] * $r['unit_price'];

    // 排序（sort 欄位 + dir 方向；預設可立即出貨者優先、再依交期）
    $sort = $f['sort'] ?? 'delivery';
    $dir  = (($f['dir'] ?? 'asc') === 'desc') ? -1 : 1;
    usort($out, function ($a, $b) use ($sort, $dir) {
        switch ($sort) {
            case 'order_oo': return $dir * strnatcasecmp($a['order_oo'], $b['order_oo']);
            case 'd_id':     return $dir * strnatcasecmp($a['d_id'], $b['d_id']);
            case 'client':   return $dir * strnatcasecmp($a['client_display'], $b['client_display']);
            case 'ready':    return $dir * ($a['ready_qty'] <=> $b['ready_qty']);
            case 'remain':   return $dir * ($a['remain_qty'] <=> $b['remain_qty']);
            case 'delivery': return $dir * strcmp($a['delivery_date'] ?: '9999-12-31',
                                                  $b['delivery_date'] ?: '9999-12-31');
            default:
                // 預設：可立即出貨的排前面，再依交期
                if (($a['ready_qty'] > 0) !== ($b['ready_qty'] > 0)) return $b['ready_qty'] > 0 ? 1 : -1;
                return strcmp($a['delivery_date'] ?: '9999-12-31', $b['delivery_date'] ?: '9999-12-31');
        }
    });

    $total   = count($out);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) {                       // 0 = 取全部（匯出用）
        return ['rows' => $out, 'total' => $total, 'page' => 1,
                'per_page' => 0, 'summary' => $summary];
    }
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page   = max(1, (int)($f['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    return [
        'rows'     => array_slice($out, $offset, $perPage),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'summary'  => $summary,
    ];
}

/* ============================================================
 * 建立出貨單（一張單多個料號明細，比照 ERP 現行結構）
 * ============================================================ */

/** 取得指定日期的下一個出貨單號：IS + 民國年(3) + MMDD + 序號(3) */
function sq_next_is_number(PDO $db, string $shipDate): string
{
    $y      = (int)substr($shipDate, 0, 4) - 1911;
    $prefix = 'IS' . str_pad((string)$y, 3, '0', STR_PAD_LEFT) . substr($shipDate, 5, 2) . substr($shipDate, 8, 2);
    $st = $db->prepare("SELECT MAX(CAST(SUBSTRING(IS_number, 10) AS UNSIGNED)) FROM is_list WHERE IS_number LIKE ?");
    $st->execute([$prefix . '%']);
    $seq = (int)$st->fetchColumn() + 1;
    return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/**
 * 建立出貨單。同一客戶 + 同一出貨日 → 共用一個 IS_number（一單多明細）。
 *
 * @param array $items 每筆：order_id, d_setting_id, product_id, specification,
 *                          client_id, client_name, qty, unit_price, note, warehouse, boms[]
 * @return array ['success'=>bool,'shipments'=>[單號=>明細數],'closed_orders'=>[],'errors'=>[],'message'=>]
 */
function sq_create_shipment(PDO $db, string $shipDate, array $items, string $userId): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shipDate)) {
        return ['success' => false, 'message' => '出貨日期格式錯誤'];
    }
    $clean = [];
    $errors = [];
    foreach ($items as $i => $it) {
        $qty = (int)($it['qty'] ?? 0);
        if ($qty <= 0) continue;
        $name = trim((string)($it['client_name'] ?? ''));
        $pid  = trim((string)($it['product_id'] ?? ''));
        if ($name === '') { $errors[] = "第" . ($i + 1) . "列缺少客戶，已略過"; continue; }
        if ($pid  === '') { $errors[] = "第" . ($i + 1) . "列缺少料號，已略過"; continue; }
        $clean[] = $it + ['qty' => $qty, 'client_name' => $name, 'product_id' => $pid];
    }
    if (!$clean) {
        return ['success' => false, 'message' => '沒有有效的出貨資料（數量需大於 0）', 'errors' => $errors];
    }

    // 依客戶分組 → 一組一張出貨單
    $groups = [];
    foreach ($clean as $it) {
        $key = ($it['client_id'] ?? '') . '|' . $it['client_name'];
        $groups[$key][] = $it;
    }

    try {
        $db->beginTransaction();

        $insIs = $db->prepare(
            "INSERT INTO is_list
             (Order_date, IS_number, Client_id, Client_name, d_setting_id, Product_id,
              Specification, Qty, Unit_price, Order_id, Warehouse, Note, Created_By, Created_At)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
        );
        $insMap = $db->prepare(
            "INSERT INTO is_bom_map (IS_id, bom, shipped_qty) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE shipped_qty = shipped_qty + VALUES(shipped_qty)"
        );

        $shipments   = [];
        $ordersUsed  = [];

        foreach ($groups as $rows) {
            $isNumber = sq_next_is_number($db, $shipDate);
            $cnt = 0;
            foreach ($rows as $it) {
                $orderId = !empty($it['order_id']) ? (int)$it['order_id'] : null;
                $insIs->execute([
                    $shipDate,
                    $isNumber,
                    ($it['client_id'] ?? '') !== '' ? $it['client_id'] : null,
                    $it['client_name'],
                    !empty($it['d_setting_id']) ? (int)$it['d_setting_id'] : null,
                    $it['product_id'],
                    trim((string)($it['specification'] ?? '')),
                    $it['qty'],
                    (float)($it['unit_price'] ?? 0),
                    $orderId,
                    trim((string)($it['warehouse'] ?? '')) ?: null,
                    trim((string)($it['note'] ?? '')),
                    $userId,
                ]);
                $isId = (int)$db->lastInsertId();

                // 製令扣帳：依前端帶回的分配結果；未帶則自動 FIFO 分配
                $alloc = $it['boms'] ?? [];
                if (!$alloc && $orderId) $alloc = sq_auto_allocate($db, $orderId, $it['qty']);
                foreach ($alloc as $a) {
                    $bq = (int)($a['qty'] ?? 0);
                    if ($bq > 0 && !empty($a['bom'])) $insMap->execute([$isId, $a['bom'], $bq]);
                }
                if ($orderId) $ordersUsed[$orderId] = true;
                $cnt++;
            }
            $shipments[$isNumber] = $cnt;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立失敗：' . $e->getMessage()];
    }

    // 自動結案（交易外，失敗不影響已建立的出貨單）
    $closed = [];
    foreach (array_keys($ordersUsed) as $oid) {
        try {
            $st = $db->prepare("
                SELECT ot.Qty,
                       COALESCE(SUM(CASE WHEN ist.is_count IS NULL OR ist.is_count <> 0 THEN il.Qty ELSE 0 END), 0) AS shipped
                FROM order_track ot
                LEFT JOIN is_list il ON il.Order_id = ot.Order_id
                LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
                WHERE ot.Order_id = ? AND (ot.Order_status IS NULL OR ot.Order_status NOT IN (6, 9))
                GROUP BY ot.Order_id, ot.Qty");
            $st->execute([$oid]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if ($c && (int)$c['shipped'] >= (int)$c['Qty']) {
                $db->prepare("UPDATE order_track SET Order_status = 9, Modified_At = NOW(), Modified_By = ?
                              WHERE Order_id = ?")->execute([$userId, $oid]);
                $closed[] = $oid;
            }
        } catch (Throwable $e) { /* 結案失敗不阻斷出貨 */ }
    }

    $msg = '已建立 ' . count($shipments) . ' 張出貨單（共 ' . array_sum($shipments) . ' 筆明細）';
    if ($closed) $msg .= '，' . count($closed) . ' 筆訂單自動結案';

    return ['success' => true, 'shipments' => $shipments, 'closed_orders' => $closed,
            'errors' => $errors, 'message' => $msg];
}

/** 依訂單的製令可出量做 FIFO 分配（交期早的先出） */
function sq_auto_allocate(PDO $db, int $orderId, int $qty): array
{
    $boms = sq_boms_for_orders($db, [$orderId])[$orderId] ?? [];
    if (!$boms) return [];
    usort($boms, fn($a, $b) => strcmp($a['delivery'] ?: '9999', $b['delivery'] ?: '9999')
                               ?: strcmp($a['bom'], $b['bom']));
    $avail = sq_bom_avail_map($db, array_column($boms, 'bom'));

    $out = [];
    $left = $qty;
    foreach ($boms as $b) {
        if ($left <= 0) break;
        $can = $avail[$b['bom']]['avail'] ?? 0;
        if ($b['allocated'] > 0) $can = min($can, $b['allocated']);
        if ($can <= 0) continue;
        $take = min($can, $left);
        $out[] = ['bom' => $b['bom'], 'qty' => $take];
        $left -= $take;
    }
    return $out;
}

/* ============================================================
 * 舊資料回填：is_list.Order_id 幾乎全空（42071 筆僅 1 筆有值），
 * 導致訂單未出量算不出來。用「客戶簡稱 + 料號id(d_setting_id) + 日期先後」
 * 比對出候選，交由人工確認後才寫入（不自動寫）。
 * 料號一律用 d_setting_id 歸戶，不可用料號字串 join（有 159 個重複料號會灌水）。
 * ============================================================ */

/**
 * 產生回填候選。同一 (客戶, 料號) 群組內，出貨依日期 FIFO 吃訂單剩餘量。
 * @return array ['pairs'=>[...], 'summary'=>[...]]
 */
function sq_match_preview(PDO $db, string $from, string $to, array $opt = []): array
{
    /* 篩選：客戶簡稱（精確）／料號 id。這兩個鍵都是「整組 (客戶,料號) 保留或整組排除」，
       不會把同一組的出貨切成兩半，所以群組內 FIFO 的先後順序不受影響（日期區間才會）。 */
    $client = trim((string)($opt['client'] ?? ''));
    $didF   = (int)($opt['d_id'] ?? 0);
    $withNo = !empty($opt['with_unmatched']);   // 連「推不出對應」的也回傳，供人工指定

    $w   = ["il.Order_id IS NULL", "il.d_setting_id IS NOT NULL", "il.Order_date BETWEEN ? AND ?"];
    $par = [$from, $to];
    if ($client !== '') { $w[] = "il.Client_name = ?";  $par[] = $client; }
    if ($didF   >  0)   { $w[] = "il.d_setting_id = ?"; $par[] = $didF; }

    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
               il.Client_name, il.Product_id, il.d_setting_id, il.Qty, il.Unit_price
        FROM is_list il
        WHERE " . implode(' AND ', $w) . "
        ORDER BY il.Order_date, il.IS_id");
    $st->execute($par);
    $ships = $st->fetchAll(PDO::FETCH_ASSOC);

    $summary = ['ship_rows' => count($ships), 'matched' => 0, 'unmatched' => 0, 'qty_matched' => 0];
    if (!$ships) return ['pairs' => [], 'summary' => $summary];

    // 取這批料號相關的訂單（含已結案，回填要涵蓋歷史）
    $dids = array_values(array_unique(array_map('intval', array_column($ships, 'd_setting_id'))));
    $ph   = implode(',', array_fill(0, count($dids), '?'));
    $so   = $db->prepare("
        SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.d_id, ot.d_id_ID, ot.Qty,
               ot.unit_price, ot.Order_status,
               DATE_FORMAT(ot.Order_date,'%Y-%m-%d')    AS order_date,
               DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS delivery_date,
               COALESCE(sh.sq, 0) AS used_qty
        FROM order_track ot
        LEFT JOIN (SELECT Order_id, SUM(Qty) AS sq FROM is_list
                   WHERE Order_id IS NOT NULL GROUP BY Order_id) sh ON sh.Order_id = ot.Order_id
        WHERE ot.d_id_ID IN ($ph)
        ORDER BY ot.Order_date, ot.Order_id");
    $so->execute($dids);

    // 依 (客戶簡稱, 料號id) 分組
    $ordersByKey = [];
    foreach ($so->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $key = $o['Client_name'] . '|' . (int)$o['d_id_ID'];
        $o['left'] = max(0, (int)$o['Qty'] - (int)$o['used_qty']);
        $ordersByKey[$key][] = $o;
    }

    $pairs = [];
    foreach ($ships as $s) {
        $key  = $s['Client_name'] . '|' . (int)$s['d_setting_id'];
        $cand = $ordersByKey[$key] ?? [];
        $hit  = null;

        foreach ($cand as $idx => $o) {
            if ($o['left'] <= 0) continue;
            if ($o['order_date'] > $s['ship_date']) continue;   // 不可出在下單之前
            $ordersByKey[$key][$idx]['left'] = $o['left'] - (int)$s['Qty'];
            $hit = $o;
            break;
        }

        if (!$hit) {
            $summary['unmatched']++;
            if ($withNo) {
                // 講明為什麼推不出來，使用者才知道這一列該不該手動指定訂單
                $reason = !$cand ? 'no_order'
                        : (max(array_column($ordersByKey[$key], 'left')) <= 0 ? 'used_up' : 'later');
                $pairs[] = [
                    'is_id'       => (int)$s['IS_id'],
                    'is_number'   => $s['IS_number'],
                    'ship_date'   => $s['ship_date'],
                    'client_name' => $s['Client_name'],
                    'product_id'  => $s['Product_id'],
                    'ship_qty'    => (int)$s['Qty'],
                    'ship_price'  => (float)$s['Unit_price'],
                    'order_id'    => 0,  'order_oo'   => '', 'order_date' => '',
                    'order_qty'   => 0,  'order_left' => 0,  'order_price' => 0.0,
                    'confidence'  => 'none',
                    'price_match' => true,
                    'over_qty'    => false,
                    'no_reason'   => $reason,
                ];
            }
            continue;
        }

        $exact      = ((int)$hit['left'] === (int)$s['Qty']);
        $over       = ((int)$s['Qty'] > (int)$hit['left']);   // 出貨量超過訂單剩餘量
        $priceMatch = (abs((float)$hit['unit_price'] - (float)$s['Unit_price']) < 0.01);
        $pairs[] = [
            'is_id'        => (int)$s['IS_id'],
            'is_number'    => $s['IS_number'],
            'ship_date'    => $s['ship_date'],
            'client_name'  => $s['Client_name'],
            'product_id'   => $s['Product_id'],
            'ship_qty'     => (int)$s['Qty'],
            'ship_price'   => (float)$s['Unit_price'],
            'order_id'     => (int)$hit['Order_id'],
            'order_oo'     => $hit['Order_oo'],
            'order_date'   => $hit['order_date'],
            'order_qty'    => (int)$hit['Qty'],
            'order_left'   => (int)$hit['left'],
            'order_price'  => (float)$hit['unit_price'],
            // 信心：high=數量剛好吃完且單價相符；low=出貨量超過訂單剩餘量（需人工判斷）；其餘 mid
            'confidence'   => $over ? 'low' : (($exact && $priceMatch) ? 'high' : 'mid'),
            'price_match'  => $priceMatch,
            'over_qty'     => $over,
        ];
        $summary['matched']++;
        $summary['qty_matched'] += (int)$s['Qty'];
    }

    return ['pairs' => $pairs, 'summary' => $summary];
}

/**
 * 回填工具的篩選來源：該日期區間內「待回填」出貨明細出現過的客戶與料號。
 * 料號一併帶客戶，前端選了客戶後只列該客戶底下的料號。
 */
function sq_match_filters(PDO $db, string $from, string $to): array
{
    $st = $db->prepare("
        SELECT il.Client_name, il.d_setting_id, MAX(il.Product_id) AS product_id, COUNT(*) AS cnt
        FROM is_list il
        WHERE il.Order_id IS NULL AND il.d_setting_id IS NOT NULL
          AND il.Order_date BETWEEN ? AND ?
        GROUP BY il.Client_name, il.d_setting_id
        ORDER BY il.Client_name, product_id");
    $st->execute([$from, $to]);

    $clients = []; $parts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cn = (string)$r['Client_name'];
        $clients[$cn] = ($clients[$cn] ?? 0) + (int)$r['cnt'];
        $parts[] = [
            'client'     => $cn,
            'd_id'       => (int)$r['d_setting_id'],
            'product_id' => (string)$r['product_id'],
            'cnt'        => (int)$r['cnt'],
        ];
    }
    $cl = [];
    foreach ($clients as $name => $cnt) $cl[] = ['name' => $name, 'cnt' => $cnt];
    usort($cl, function ($a, $b) { return strcmp($a['name'], $b['name']); });

    return ['clients' => $cl, 'parts' => $parts];
}

/**
 * 某一筆出貨明細「可以手動綁哪些訂單」。
 * 候選一律限定同一個客戶簡稱（跨客戶綁一定是錯的，後端寫入時也再擋一次）；
 * 料號則可放寬——訂單常下組合件名稱、製作時才拆成子件料號，強制同料號會一筆都選不到。
 * 剩餘量已為 0（被其他出貨吃完）的訂單「照樣列出但不可選」，讓使用者看得到「這張已出完，所以才輪到下一張」。
 */
function sq_match_candidates(PDO $db, int $isId, bool $samePart = true, string $kw = ''): array
{
    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
               il.Client_name, il.Product_id, il.d_setting_id, il.Qty, il.Unit_price, il.Order_id
        FROM is_list il WHERE il.IS_id = ?");
    $st->execute([$isId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return ['ship' => null, 'orders' => [], 'error' => '找不到這筆出貨明細'];

    $did = (int)$s['d_setting_id'];
    $w   = ["ot.Client_name = :cn"];
    $par = [':cn' => $s['Client_name']];
    if ($samePart) {
        if ($did <= 0) return ['ship' => $s, 'orders' => [], 'error' => '這筆出貨沒有綁料號id，無法用「相同料號」篩選'];
        $w[] = "ot.d_id_ID = :did";
        $par[':did'] = $did;
    }
    $kw = trim($kw);
    if ($kw !== '') {
        $w[] = "(ot.Order_oo LIKE :kw OR ot.d_id LIKE :kw OR ot.Specification LIKE :kw)";
        $par[':kw'] = '%' . $kw . '%';
    }

    // 先數總筆數，畫面才講得出「共幾張、顯示了幾張」
    $cs = $db->prepare("SELECT COUNT(*) FROM order_track ot WHERE " . implode(' AND ', $w));
    $cs->execute($par);
    $total = (int)$cs->fetchColumn();

    /* 超過上限時取「離出貨日最近」的那些，不是最舊的那些——像和大這種客戶光是同一個料號
       就有上千張訂單，取最舊的 300 張會把出貨日附近真正該綁的那幾張整批切掉。
       撈出來之後仍依訂單日期排序顯示，方便一路看下來確認哪張先被出完。 */
    $so = $db->prepare("
        SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.d_id, ot.d_id_ID, ot.Specification,
               ot.Qty, ot.unit_price, ot.Order_status,
               DATE_FORMAT(ot.Order_date,'%Y-%m-%d')    AS order_date,
               DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS delivery_date,
               COALESCE(sh.sq, 0) AS used_qty
        FROM order_track ot
        LEFT JOIN (SELECT Order_id, SUM(Qty) AS sq FROM is_list
                   WHERE Order_id IS NOT NULL GROUP BY Order_id) sh ON sh.Order_id = ot.Order_id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY ABS(DATEDIFF(ot.Order_date, :sd)), ot.Order_id
        LIMIT :lim");
    $par[':sd'] = $s['ship_date'];
    $so->bindValue(':lim', SQ_CAND_LIMIT, PDO::PARAM_INT);
    foreach ($par as $k => $v) { if ($k !== ':lim') $so->bindValue($k, $v); }
    $so->execute();

    $rows = $so->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, function ($a, $b) {
        return [$a['order_date'], (int)$a['Order_id']] <=> [$b['order_date'], (int)$b['Order_id']];
    });

    $orders = [];
    foreach ($rows as $o) {
        $left = (int)$o['Qty'] - (int)$o['used_qty'];
        $orders[] = [
            'order_id'    => (int)$o['Order_id'],
            'order_oo'    => (string)$o['Order_oo'],
            'order_date'  => (string)$o['order_date'],
            'delivery'    => (string)$o['delivery_date'],
            'part_no'     => (string)$o['d_id'],
            'spec'        => (string)$o['Specification'],
            'order_qty'   => (int)$o['Qty'],
            'used_qty'    => (int)$o['used_qty'],
            'order_left'  => $left,
            'order_price' => (float)$o['unit_price'],
            'same_part'   => ($did > 0 && (int)$o['d_id_ID'] === $did),
            'price_match' => (abs((float)$o['unit_price'] - (float)$s['Unit_price']) < 0.01),
            'over_qty'    => ((int)$s['Qty'] > $left),          // 這筆出貨量超過剩餘量
            'late'        => ($o['order_date'] > $s['ship_date']), // 下單日晚於出貨日
            'closed'      => ((string)$o['Order_status'] === '9'),
            // 已被其他出貨吃完＝不可選（照樣列出供確認）
            'selectable'  => ($left > 0),
        ];
    }

    return ['total' => $total, 'shown' => count($orders), 'limit' => SQ_CAND_LIMIT, 'ship' => [
        'is_id'       => (int)$s['IS_id'],
        'is_number'   => (string)$s['IS_number'],
        'ship_date'   => (string)$s['ship_date'],
        'client_name' => (string)$s['Client_name'],
        'product_id'  => (string)$s['Product_id'],
        'ship_qty'    => (int)$s['Qty'],
        'ship_price'  => (float)$s['Unit_price'],
    ], 'orders' => $orders];
}

/** 寫入回填結果（僅覆寫 Order_id 仍為 NULL 的列，避免蓋掉已正確的資料） */
function sq_match_apply(PDO $db, array $pairs, string $userId): array
{
    $applied = 0; $skipped = 0;
    try {
        $db->beginTransaction();
        /* 客戶必須相符才寫得進去（鐵律8：前端候選清單已限定同客戶，後端同規則再擋一次，
           避免有人直打 API 把出貨綁到別家的訂單）。料號刻意不擋——訂單常下組合件名稱，
           製作時才拆成子件料號，使用者可自行選擇是否限定相同料號。 */
        $up = $db->prepare("
            UPDATE is_list il
              JOIN order_track ot ON ot.Order_id = :oid
               SET il.Order_id = ot.Order_id
             WHERE il.IS_id = :isid AND il.Order_id IS NULL
               AND ot.Client_name = il.Client_name");
        foreach ($pairs as $p) {
            $isId = (int)($p['is_id'] ?? 0);
            $oid  = (int)($p['order_id'] ?? 0);
            if ($isId <= 0 || $oid <= 0) { $skipped++; continue; }
            $up->execute([':oid' => $oid, ':isid' => $isId]);
            if ($up->rowCount() > 0) $applied++; else $skipped++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '回填失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'applied' => $applied, 'skipped' => $skipped,
            'message' => "已回填 {$applied} 筆" . ($skipped ? "，略過 {$skipped} 筆（已有訂單編號、客戶簡稱不符或資料已異動）" : '')];
}

/* ============================================================
 * 已出貨單查詢（當日／近期，供出貨後檢視與列印送貨單）
 * ============================================================ */
function sq_recent_shipments(PDO $db, array $f): array
{
    $where  = [];
    $params = [];
    if (!empty($f['date_from'])) { $where[] = "il.Order_date >= :df"; $params[':df'] = $f['date_from']; }
    if (!empty($f['date_to']))   { $where[] = "il.Order_date <= :dt"; $params[':dt'] = $f['date_to']; }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(il.IS_number LIKE :kw OR il.Client_name LIKE :kw OR il.Product_id LIKE :kw)";
        $params[':kw'] = '%' . $kw . '%';
    }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT il.IS_number,
                   DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
                   il.Client_name, MAX(il.Client_id) AS client_id,
                   COUNT(*) AS item_count, SUM(il.Qty) AS total_qty,
                   SUM(il.Qty * il.Unit_price) AS total_amount,
                   MAX(il.Created_By) AS created_by, MAX(il.Created_At) AS created_at
            FROM is_list il
            $ws
            GROUP BY il.IS_number, il.Order_date, il.Client_name
            ORDER BY il.Order_date DESC, il.IS_number DESC
            LIMIT 200";
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 取單一出貨單的所有明細（檢視與列印出貨單用） */
function sq_shipment_detail(PDO $db, string $isNumber): array
{
    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
               il.Client_id, il.Client_name, il.Product_id, il.d_setting_id, il.Specification,
               il.Qty, il.Unit_price, il.Order_id, il.Warehouse, il.Note,
               il.Created_By, DATE_FORMAT(il.Created_At,'%Y-%m-%d %H:%i') AS created_at,
               ot.Order_oo, COALESCE(ot.C_order,'') AS c_order,
               COALESCE(ot.Processing_items,'')     AS processing_items,
               COALESCE(ot.Specification,'')        AS order_spec,
               COALESCE(ds.Spec_No,'')              AS part_spec,
               COALESCE(cl.customer, il.Client_name) AS client_display,
               cl.customer_full, cl.tax_id, cl.customer_address, cl.customer_tel, cl.customer_fax,
               GROUP_CONCAT(ibm.bom ORDER BY ibm.bom SEPARATOR ',') AS boms
        FROM is_list il
        LEFT JOIN order_track   ot  ON ot.Order_id    = il.Order_id
        LEFT JOIN customer_list cl  ON cl.customer_id = il.Client_id
        LEFT JOIN d_setting     ds  ON ds.d_id        = il.d_setting_id
        LEFT JOIN is_bom_map    ibm ON ibm.IS_id      = il.IS_id
        WHERE il.IS_number = ?
        GROUP BY il.IS_id
        ORDER BY il.IS_id");
    $st->execute([$isNumber]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    /* is_list.Client_id 全表都是空的（ERP 匯入從來沒帶），所以上面那個 JOIN 一定 miss，
       統編／電話／傳真／地址會整片空白。歸戶一律走會計模組那支「含別名」的唯一入口
       acc_customer_by_name()（ERP 寫「高鋒工業」、主檔是「高鋒」這種只有它對得起來），
       不要在這裡自己再寫一套比對（鐵律4）。 */
    $needCust = false;
    foreach ($rows as $r) if (trim((string)$r['Client_id']) === '') { $needCust = true; break; }
    if ($needCust) {
        $cmap = [];
        try {
            require_once __DIR__ . '/acc_lib.php';
            if (function_exists('acc_customer_by_name')) $cmap = acc_customer_by_name($db);
        } catch (Throwable $e) { }
        foreach ($rows as &$r0) {
            if (trim((string)$r0['Client_id']) !== '') continue;
            $c = $cmap[trim((string)$r0['Client_name'])] ?? null;
            if (!$c) continue;
            $r0['Client_id']        = $c['customer_id'];
            $r0['client_display']   = $c['customer'] ?: $r0['client_display'];
            $r0['customer_full']    = $c['customer_full'];
            $r0['tax_id']           = $c['tax_id'];
            $r0['customer_address'] = $c['customer_address'];
            $r0['customer_tel']     = $c['customer_tel'] ?? '';
            $r0['customer_fax']     = $c['customer_fax'] ?? '';
        }
        unset($r0);
    }

    // 建立者姓名：Created_By 存的是 user.id 字串，但舊 ERP 資料可能是帳號，
    // 直接在 SQL 裡 JOIN 會踩到 user 表的 latin1 欄位定序衝突，故在 PHP 端解析。
    $uids = array_values(array_unique(array_filter(array_column($rows, 'Created_By'), 'is_numeric')));
    $uname = [];
    if ($uids) {
        try {
            $q = $db->prepare("SELECT id, user_cname FROM user WHERE id IN ("
                              . implode(',', array_fill(0, count($uids), '?')) . ")");
            $q->execute($uids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $uname[(string)$x['id']] = $x['user_cname'];
        } catch (Throwable $e) { }
    }

    // 齒輪規格一次撈完，再組出與報價單相同格式的「品名規格」
    $gearMap = eg_gear_spec_map($db, array_column($rows, 'd_setting_id'));
    foreach ($rows as &$r) {
        $gear = $r['d_setting_id'] !== null ? ($gearMap[(int)$r['d_setting_id']] ?? '') : '';
        $r['gear_spec'] = $gear;
        // 料號備註優先用訂單上的（報價單就是印這個），沒綁訂單才退回出貨明細自己存的規格文字
        $rmk = $r['order_spec'] !== '' ? $r['order_spec'] : (string)$r['Specification'];
        $r['desc_full'] = sq_desc_text($r['part_spec'], $gear, $r['processing_items'], $rmk);
        $r['created_by_name'] = $uname[(string)$r['Created_By']] ?? (string)$r['Created_By'];
    }
    unset($r);
    return $rows;
}

/* ============================================================
 * 出貨單：備註修改 ／ 刪除（2026-09-17 使用者交辦）
 * ============================================================ */

/**
 * 刪除／修改前的擋門：這張出貨明細已經被會計端用掉了就不可以再動。
 * 已進對帳單或已開發票的列若被刪掉，帳面金額會對不起來而且完全看不出原因。
 * @param array $isIds is_list.IS_id
 * @return array 阻擋原因（空陣列＝可以刪）
 */
function sq_shipment_guard(PDO $db, array $isIds): array
{
    $isIds = array_values(array_unique(array_map('intval', array_filter($isIds))));
    if (!$isIds) return ['找不到要處理的出貨明細'];
    $ph  = implode(',', array_fill(0, count($isIds), '?'));
    $out = [];
    try {
        $st = $db->prepare("SELECT COUNT(DISTINCT sheet_id) FROM acc_recon_line
                            WHERE src_type = 'IS' AND src_id IN ($ph)");
        $st->execute($isIds);
        $n = (int)$st->fetchColumn();
        if ($n > 0) $out[] = "已被 {$n} 份對帳底稿引用，請先到「對帳作業」把該列移除後再刪";
    } catch (Throwable $e) { /* 表不存在＝沒有這層限制 */ }
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM acc_invoice_item
                            WHERE UPPER(src_type) = 'IS' AND src_id IN ($ph)");
        $st->execute($isIds);
        $n = (int)$st->fetchColumn();
        if ($n > 0) $out[] = "已開立發票（{$n} 筆發票明細），不可刪除";
    } catch (Throwable $e) { }
    return $out;
}

/**
 * 修改出貨明細的備註。
 * @return array ['success'=>bool,'message'=>string]
 */
function sq_shipment_note_save(PDO $db, int $isId, string $note, array $user): array
{
    if ($isId <= 0) return ['success' => false, 'message' => '缺少出貨明細 id'];
    $note = mb_substr(trim($note), 0, 100);      // is_list.Note 是 varchar(100)

    $st = $db->prepare("SELECT IS_id, IS_number, Product_id, COALESCE(Note,'') AS Note FROM is_list WHERE IS_id = ?");
    $st->execute([$isId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['success' => false, 'message' => '查無此出貨明細（可能已被刪除，請重新整理）'];
    if ($row['Note'] === $note) return ['success' => true, 'message' => '備註未變更', 'note' => $note];

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE is_list SET Note = ? WHERE IS_id = ?")->execute([$note, $isId]);
        sq_audit($db, 'update', 'shipment_note', (string)$isId,
                 $row['IS_number'] . ' ' . $row['Product_id'],
                 ['before' => $row['Note'], 'after' => $note], $user);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '儲存失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'message' => '備註已更新', 'note' => $note];
}

/**
 * 刪除出貨單（整張）或其中幾筆明細。
 *
 * 一併處理的四件事（少做任何一件都會留下對不起來的資料）：
 *  1. 製令扣帳 is_bom_map 一起刪掉——不刪的話製令的「已出」永遠掛著，可出量算不回來。
 *  2. 追溯對照的分配表（is_order_map／ir_reship_map／return_order_map）與
 *     shipment_order_map 一併清掉，否則追溯圖上會出現指向不存在單據的線。
 *  3. 出貨量回到原訂單＝刪掉 is_list 列本身（訂單未出量是即時由 is_list 加總算出來的，
 *     沒有另一份數量要回寫）。
 *  4. 原訂單若因為這次出貨而自動結案（Order_status=9），刪完不足量就自動取消結案。
 *
 * @param array|null $isIds 只刪這幾筆明細；null＝整張出貨單
 * @return array ['success'=>bool,'message'=>,'deleted'=>int,'reopened'=>[訂單編號,...],'blocked'=>[]]
 */
function sq_delete_shipment(PDO $db, string $isNumber, ?array $isIds, array $user): array
{
    $isNumber = trim($isNumber);
    if ($isNumber === '') return ['success' => false, 'message' => '缺少出貨單號'];

    $sql    = "SELECT IS_id, IS_number, Order_id, Product_id, Qty, Unit_price, Client_name,
                      DATE_FORMAT(Order_date,'%Y-%m-%d') AS ship_date
               FROM is_list WHERE IS_number = ?";
    $params = [$isNumber];
    if ($isIds !== null) {
        $isIds = array_values(array_unique(array_map('intval', array_filter($isIds))));
        if (!$isIds) return ['success' => false, 'message' => '沒有指定要刪除的明細'];
        $sql .= " AND IS_id IN (" . implode(',', array_fill(0, count($isIds), '?')) . ")";
        $params = array_merge($params, $isIds);
    }
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['success' => false, 'message' => '查無此出貨單（可能已被刪除，請重新整理）'];

    $ids     = array_map('intval', array_column($rows, 'IS_id'));
    $blocked = sq_shipment_guard($db, $ids);
    if ($blocked) {
        return ['success' => false, 'message' => implode('；', $blocked), 'blocked' => $blocked];
    }

    $orderIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'Order_id')))));
    $ph       = implode(',', array_fill(0, count($ids), '?'));

    try {
        $db->beginTransaction();
        // 先清掉所有掛在這些明細底下的對應（外鍵不一定有，一律自己清）
        foreach (['is_bom_map', 'is_order_map', 'ir_reship_map', 'return_order_map', 'shipment_order_map'] as $t) {
            try { $db->prepare("DELETE FROM $t WHERE IS_id IN ($ph)")->execute($ids); }
            catch (Throwable $e) { /* 該表不存在就略過 */ }
        }
        $db->prepare("DELETE FROM is_list WHERE IS_id IN ($ph)")->execute($ids);

        foreach ($rows as $r) {
            sq_audit($db, 'delete', 'shipment', (string)$r['IS_id'],
                     $r['IS_number'] . ' ' . $r['Product_id'],
                     ['ship_date' => $r['ship_date'], 'client' => $r['Client_name'],
                      'qty' => (int)$r['Qty'], 'unit_price' => (float)$r['Unit_price'],
                      'order_id' => $r['Order_id']], $user);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '刪除失敗：' . $e->getMessage()];
    }

    $reopened = sq_reopen_orders($db, $orderIds, $user);

    $msg = '已刪除 ' . count($rows) . ' 筆出貨明細，數量已回到原訂單';
    if ($reopened) $msg .= '；' . count($reopened) . ' 筆訂單自動取消結案（' . implode('、', $reopened) . '）';
    return ['success' => true, 'message' => $msg, 'deleted' => count($rows),
            'reopened' => $reopened, 'blocked' => []];
}

/**
 * 出貨被刪掉之後，原本因「出滿了」而自動結案的訂單要自動取消結案。
 * 判定與 sq_create_shipment() 的自動結案完全相同（同一條界線，不可各寫一套）。
 * @return array 被取消結案的訂單編號（Order_oo）
 */
function sq_reopen_orders(PDO $db, array $orderIds, array $user): array
{
    $orderIds = array_values(array_unique(array_map('intval', array_filter($orderIds))));
    $done = [];
    foreach ($orderIds as $oid) {
        try {
            $st = $db->prepare("
                SELECT ot.Order_oo, ot.Qty, ot.Order_status,
                       COALESCE(SUM(CASE WHEN ist.is_count IS NULL OR ist.is_count <> 0 THEN il.Qty ELSE 0 END), 0) AS shipped
                FROM order_track ot
                LEFT JOIN is_list il ON il.Order_id = ot.Order_id
                LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
                WHERE ot.Order_id = ?
                GROUP BY ot.Order_id, ot.Order_oo, ot.Qty, ot.Order_status");
            $st->execute([$oid]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c || (int)$c['Order_status'] !== 9) continue;      // 只動「已結案」的
            if ((int)$c['shipped'] >= (int)$c['Qty']) continue;      // 還是出滿的就維持結案
            $db->prepare("UPDATE order_track SET Order_status = NULL, Modified_At = NOW(), Modified_By = ?
                          WHERE Order_id = ? AND Order_status = 9")
               ->execute([(string)($user['id'] ?? ''), $oid]);
            sq_audit($db, 'update', 'order_reopen', (string)$oid, (string)$c['Order_oo'],
                     ['before' => 9, 'after' => null, 'reason' => '出貨單被刪除，出貨量不足訂購量'], $user);
            $done[] = (string)$c['Order_oo'];
        } catch (Throwable $e) { /* 單筆失敗不阻斷其他訂單 */ }
    }
    return $done;
}

/** 稽核紀錄（寫不進去不可以阻斷主要動作） */
function sq_audit(PDO $db, string $action, string $targetType, string $targetId,
                  string $targetName, array $changes, array $user): void
{
    try {
        $db->prepare("INSERT INTO audit_log
                      (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES (?,?,?,?,?,?,?,NOW())")
           ->execute([$action, $targetType, mb_substr($targetId, 0, 200), mb_substr($targetName, 0, 200),
                      json_encode($changes, JSON_UNESCAPED_UNICODE),
                      (int)($user['id'] ?? 0), mb_substr((string)($user['name'] ?? ''), 0, 100)]);
    } catch (Throwable $e) { }
}

/* ============================================================
 * 出貨單列印用的表頭資料
 * ============================================================ */

/**
 * 列印出貨單需要的固定資訊：本公司抬頭（ai-rules/16：一律動態取，禁寫死）＋綁定的 AS 文件。
 * 本公司＝customer_list.is_own_company = 1 的那一筆。
 */
function sq_print_meta(PDO $db, ?string $bizDate = null): array
{
    $own = [];
    try {
        $own = $db->query("SELECT customer_full, customer, customer_address, customer_tel, customer_fax, tax_id
                           FROM customer_list WHERE is_own_company = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { }

    $doc = null; $docNo = '';
    if (function_exists('eg_asdoc_get')) {
        $doc = eg_asdoc_get($db, SQ_ASDOC_MODULE);
        if ($doc) $docNo = eg_asdoc_no_asof($db, SQ_ASDOC_MODULE, $bizDate);
    }
    return [
        'company'  => [
            'full'    => (string)($own['customer_full'] ?? ($own['customer'] ?? '')),
            'address' => (string)($own['customer_address'] ?? ''),
            'tel'     => (string)($own['customer_tel'] ?? ''),
            'fax'     => (string)($own['customer_fax'] ?? ''),
            'tax_id'  => (string)($own['tax_id'] ?? ''),
        ],
        'asdoc'    => $doc ? ['id' => (int)$doc['id'], 'doc_no' => $doc['doc_no'], 'doc_name' => $doc['doc_name']] : null,
        'asdoc_no' => $docNo,
    ];
}
