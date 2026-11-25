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

/**
 * 本頁可個別指派給角色的功能清單（角色設定跳窗與後端判定共用的唯一登記表）。
 * 角色歸屬 roles.module='shipping'，功能碼存 role_features。
 * 規則：全站管理員、本頁管理員(shipping_admin)自動擁有全部；其他角色要在「角色設定」勾選才有。
 */
function sq_feature_list(): array
{
    return [
        ['group' => '基本',     'code' => 'sq_view',          'label' => '檢視待出貨清單與近期出貨單'],
        ['group' => '基本',     'code' => 'sq_edit',          'label' => '建立出貨單（含修改備註）'],
        ['group' => '基本',     'code' => 'sq_delete',        'label' => '刪除出貨單（會把數量退回訂單）'],
        ['group' => '綁定與回填', 'code' => 'sq_bind_bom',      'label' => '綁製令（訂單還沒有製令時綁定）'],
        ['group' => '綁定與回填', 'code' => 'sq_bind_old_ship', 'label' => '綁定舊出貨單到製令（含報廢歸還設定）'],
        ['group' => '綁定與回填', 'code' => 'sq_match',         'label' => '舊資料訂單回填'],
        ['group' => '綁定與回填', 'code' => 'sq_chain_link',    'label' => '追溯對照：建立／解除對應'],
        ['group' => '價格與設定', 'code' => 'sq_price_batch',   'label' => '批次改單價'],
        ['group' => '價格與設定', 'code' => 'sq_asdoc',         'label' => '設定出貨單列印的 AS 文件編號'],
    ];
}

function sq_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canEdit' => false,
                     'canView' => false, 'canDelete' => false, 'canBindBom' => false,
                     'canBindOldShip' => false, 'canMatch' => false, 'canChainLink' => false,
                     'canPriceBatch' => false, 'canAsdoc' => false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    /* 角色設定勾選的功能碼（自訂角色用）；解析失敗視為沒有，不影響既有四個內建角色 */
    $feat = [];
    try {
        require_once __DIR__ . '/role_features_helper.php';
        $feat = rf_resolve_features($db, $uid, SQ_MODULE);
    } catch (Throwable $e) { $feat = []; }
    $has = function (string $code) use ($feat): bool { return in_array($code, $feat, true); };

    $canAdmin = $isAdmin  || sq_has_role($db, $uid, ['shipping_admin']);
    $canEdit  = $canAdmin || sq_has_role($db, $uid, ['shipping_edit']) || $has('sq_edit');
    $canView  = $canEdit  || sq_has_role($db, $uid, ['shipping_view']) || $has('sq_view')
             || $has('sq_delete') || $has('sq_bind_bom') || $has('sq_bind_old_ship') || $has('sq_match')
             || $has('sq_chain_link') || $has('sq_price_batch') || $has('sq_asdoc');
    /* 刪除出貨單是獨立角色、不含在管理員以下的階層裡：刪一張已出的貨會把數量退回訂單、
       還可能把已結案的訂單重新打開，不應該因為「有管理員角色」就順帶取得（使用者要求另開角色）。 */
    $canDelete = $isAdmin || sq_has_role($db, $uid, ['shipping_delete']) || $has('sq_delete');
    /* 進階功能：全站管理員／本頁管理員自動全有（維持原本行為），其他角色逐項勾選 */
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canEdit' => $canEdit,
            'canView' => $canView, 'canDelete' => $canDelete,
            'canBindBom'     => $canAdmin || $has('sq_bind_bom'),
            'canBindOldShip' => $canAdmin || $has('sq_bind_old_ship'),
            'canMatch'       => $canAdmin || $has('sq_match'),
            'canChainLink'   => $canAdmin || $has('sq_chain_link'),
            'canPriceBatch'  => $canAdmin || $has('sq_price_batch'),
            'canAsdoc'       => $canAdmin || $has('sq_asdoc')];
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

    /* 最後一道製程（同 bom_sn 有重複列時取 bom_ing_fid 最大者）。
       完工量：ERP 結案＝整張 sqty；最後一關已移轉(E)＝該關 sqty；
       否則＝最後一關「已回廠」的數量（與 BOM 總覽「N PCS 待出貨」同一口徑，2026-11-11 使用者要求）——
       包裝等最後一關常常整批回廠了狀態卻還停在加工中，只認 E 會讓可出量一直是 0。 */
    $sql = "
        SELECT b.bom,
               CASE WHEN b.processing_state = '1' THEN b.sqty
                    WHEN bl.processing_state = 'E' THEN bl.sqty
                    ELSE COALESCE(lr.ret, 0) END     AS done_qty,
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
        LEFT JOIN (
            SELECT bi.bom, SUM(ob.return_qty) AS ret
            FROM bom_ing bi
            JOIN (SELECT bom, MAX(bom_sn) AS msn FROM bom_ing GROUP BY bom) mx2
              ON mx2.bom = bi.bom AND mx2.msn = bi.bom_sn
            JOIN bom_ing_outsource_batch ob ON ob.bom_ing_fid = bi.bom_ing_fid
            GROUP BY bi.bom
        ) lr ON lr.bom = b.bom
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

    // 報廢品歸還客戶（2026-11-11 使用者交辦）：第一關不是 170 材料（＝客供料）的製令，報廢品要還給客戶。
    // 只在指定製令清單時才查（全域查詢不需要，省一次查詢）；歸還數量記在 is_bom_scrap_map，與良品出貨的 is_bom_map 分開，
    // 這樣既有讀 is_bom_map 的程式（BOM總覽綁定出貨單、追溯對照、自動結案）完全不受影響。
    $firstNo = []; $scrapRet = [];
    if ($boms !== null && $rows) {
        sq_scrap_ensure($db);
        $ph2 = implode(',', array_fill(0, count($boms), '?'));
        $fs = $db->prepare("SELECT bi.bom, bi.process_no FROM bom_ing bi
                              JOIN (SELECT bom, MIN(bom_sn) AS msn FROM bom_ing WHERE bom IN ($ph2) GROUP BY bom) m
                                ON m.bom = bi.bom AND m.msn = bi.bom_sn");
        $fs->execute($boms);
        foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) $firstNo[$f['bom']] = (int)$f['process_no'];
        $rs = $db->prepare("SELECT m.bom, SUM(m.qty) AS q FROM is_bom_scrap_map m
                              JOIN is_list il ON il.IS_id = m.IS_id
                             WHERE m.bom IN ($ph2) GROUP BY m.bom");
        $rs->execute($boms);
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $f) $scrapRet[$f['bom']] = (int)$f['q'];
    }

    $map = [];
    foreach ($rows as $r) {
        $done    = (int)$r['done_qty'];
        $shipped = (int)$r['shipped_qty'];
        if ($boms === null && $done <= 0) continue;   // 全域查詢時略過未完工，減少記憶體
        $scrap   = qab_bom_scrap_sum_rows($scrapMap[$r['bom']] ?? [], null);
        $done    = max(0, $done - $scrap);
        $elig    = isset($firstNo[$r['bom']]) && $firstNo[$r['bom']] !== 170 && $firstNo[$r['bom']] !== 0;
        $ret     = (int)($scrapRet[$r['bom']] ?? 0);
        $map[$r['bom']] = [
            'done'    => $done,
            'shipped' => $shipped,
            'avail'   => max(0, $done - $shipped),
            'closed'  => ((int)$r['erp_closed'] === 1),
            'scrap'       => (int)$scrap,                                   // 已結案報廢單的報廢件數
            'scrap_ret'   => $ret,                                          // 已歸還客戶的報廢品件數
            'scrap_elig'  => $elig,                                         // 第一關不是 170 材料 → 報廢品要還客戶
            'scrap_avail' => $elig ? max(0, (int)$scrap - $ret) : 0,        // 還可以歸還的報廢品件數
        ];
    }
    return $map;
}

/** 報廢品歸還明細表（出貨單明細 ↔ 製令 ↔ 歸還件數）。DDL 不可在交易中執行，所以交易內呼叫時略過。 */
function sq_scrap_ensure(PDO $db): void
{
    static $done = false;
    if ($done || $db->inTransaction()) return;
    $ex = $db->query("SHOW TABLES LIKE 'is_bom_scrap_map'")->fetchColumn();
    if (!$ex) {
        $db->exec("CREATE TABLE IF NOT EXISTS is_bom_scrap_map (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            IS_id INT NOT NULL COMMENT '對應 is_list.IS_id（報廢品歸還的出貨明細）',
            bom VARCHAR(30) NOT NULL COMMENT '對應 bom.bom',
            qty INT NOT NULL DEFAULT 0 COMMENT '歸還客戶的報廢品件數',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_is_bom (IS_id, bom), KEY idx_bom (bom)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='報廢品歸還客戶（第一關非170材料的製令）'");
    }
    $done = true;
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
                'next' => $first['pname'], 'started' => false, 'cur_sn' => 0,
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
            'next' => $next, 'started' => true, 'cur_sn' => (int)$curSn,
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
        JOIN order_track ot ON ot.Order_id = b.o_order_id   -- bom.o_order_id 是 order_track.Order_id（整數主鍵），不是訂單編號
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
        LEFT JOIN (" . sq_shipped_by_order_sql() . ") sh ON sh.Order_id = ot.Order_id
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

    // 刀具詢價「預留待出貨」：貨已到、已預留給這張訂單、還沒開出貨單的數量（tool_inq_alloc kind='hold'）。算進可出量並在畫面標出。
    $toolHold = [];
    try {
        if ($orderIds) {
            $hq = $db->query("SELECT order_id, SUM(qty) q FROM tool_inq_alloc WHERE kind='hold' AND order_id IN (" . implode(',', array_map('intval', $orderIds)) . ") GROUP BY order_id");
            foreach ($hq->fetchAll(PDO::FETCH_ASSOC) as $h) $toolHold[(int)$h['order_id']] = (int)$h['q'];
        }
    } catch (Throwable $e) { $toolHold = []; }   // 刀具詢價模組沒啟用（沒有這張表）時完全不影響

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
                'scrap'       => (int)($av['scrap'] ?? 0),
                'scrap_ret'   => (int)($av['scrap_ret'] ?? 0),
                'scrap_elig'  => !empty($av['scrap_elig']),
                'scrap_avail' => (int)($av['scrap_avail'] ?? 0),
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
        $hold  = $toolHold[$oid] ?? 0;
        $ready = min($remain, $readyTotal + $hold);

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
            'tool_hold'        => $hold,
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

    // 料號底下還沒綁任何訂單的舊出貨單筆數（整頁一次批次查；清單在料號右側顯示單據圖示，點了開「綁定舊出貨單」）
    $dids = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['d_setting_id'], $out))));
    $unb = [];
    if ($dids) {
        try {
            $qs = $db->query("SELECT il.d_setting_id, COUNT(DISTINCT il.IS_id) n FROM is_list il
                WHERE il.d_setting_id IN (" . implode(',', $dids) . ") AND il.Order_id IS NULL
                  AND NOT EXISTS (SELECT 1 FROM shipment_order_map so WHERE so.IS_id = il.IS_id)
                  AND NOT EXISTS (SELECT 1 FROM is_bom_scrap_map sm WHERE sm.IS_id = il.IS_id)
                GROUP BY il.d_setting_id");
            foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $u) $unb[(int)$u['d_setting_id']] = (int)$u['n'];
        } catch (Throwable $e) { $unb = []; }
    }
    foreach ($out as &$r) $r['unbound_ship_cnt'] = $unb[(int)$r['d_setting_id']] ?? 0;
    unset($r);

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
        if (!empty($it['scrap_return'])) {   // 報廢品歸還客戶：不掛訂單、單價 0，數量一律不可超過該製令「報廢品可退」
            sq_scrap_ensure($db);
            $alloc = array_values(array_filter((array)($it['boms'] ?? []), fn($a) => !empty($a['bom']) && (int)($a['qty'] ?? 0) > 0));
            if (!$alloc) { $errors[] = "第" . ($i + 1) . "列報廢品歸還沒有指定製令，已略過"; continue; }
            $am = sq_bom_avail_map($db, array_column($alloc, 'bom'));
            $sumA = 0; $bad = '';
            foreach ($alloc as $a) {
                $sumA += (int)$a['qty'];
                if ((int)$a['qty'] > (int)($am[$a['bom']]['scrap_avail'] ?? 0)) { $bad = $a['bom'] . ' 報廢品可退只剩 ' . (int)($am[$a['bom']]['scrap_avail'] ?? 0) . ' 件'; break; }
            }
            if ($bad !== '') return ['success' => false, 'message' => '報廢品歸還數量超過上限：' . $bad];
            $it['qty'] = $sumA; $qty = $sumA; $it['boms'] = $alloc;
            $it['order_id'] = null; $it['unit_price'] = 0;
            if (trim((string)($it['note'] ?? '')) === '') $it['note'] = '報廢品歸還';
        }
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

        $insScrap = $db->prepare(
            "INSERT INTO is_bom_scrap_map (IS_id, bom, qty) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)"
        );
        $shipments   = [];
        $ordersUsed  = [];
        $holdConsume = [];

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
                $newIsIds[] = $isId;

                // 製令扣帳：依前端帶回的分配結果；未帶則自動 FIFO 分配
                $alloc = $it['boms'] ?? [];
                if (!$alloc && $orderId && empty($it['scrap_return'])) $alloc = sq_auto_allocate($db, $orderId, $it['qty']);
                foreach ($alloc as $a) {
                    $bq = (int)($a['qty'] ?? 0);
                    if ($bq <= 0 || empty($a['bom'])) continue;
                    if (!empty($it['scrap_return'])) $insScrap->execute([$isId, $a['bom'], $bq]);   // 報廢品歸還：記在獨立的表，不算良品出貨
                    else $insMap->execute([$isId, $a['bom'], $bq]);
                }
                if ($orderId) $ordersUsed[$orderId] = true;
                if ($orderId && empty($it['scrap_return'])) $holdConsume[] = [$orderId, (int)$it['qty'], $isNumber];
                $cnt++;
            }
            $shipments[$isNumber] = $cnt;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立失敗：' . $e->getMessage()];
    }
    // 快速出貨建立的出貨單本來就綁了訂單／製令：標上「已綁定確認」，ERP 匯入不會覆蓋（is_bind_lock_lib.php）
    if (!empty($newIsIds)) { require_once __DIR__ . '/is_bind_lock_lib.php'; isbl_sync($db, $newIsIds, (int)$userId); }
    // 刀具詢價「預留待出貨」：這張訂單開了出貨單（不論從哪個入口），把預留依順序扣掉（刀具詢價自己開的出貨單另有處理，不走這裡）
    if (!empty($holdConsume) && empty($GLOBALS['TI_SHIP_INTERNAL'])) {
        try { require_once __DIR__ . '/tool_inq_lib.php'; foreach ($holdConsume as [$ho, $hq2, $hn]) ti_hold_consume($db, $ho, $hq2, $hn, (int)$userId); }
        catch (Throwable $e) { error_log('[shipping] tool hold consume failed: ' . $e->getMessage()); }
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
/**
 * 出貨／訂單「同一個客戶」的比對鍵：客戶簡稱先經客戶主檔（含會計對帳設定的別名）歸戶，
 * 例：出貨寫「如陽科技」、訂單是「如陽」→ 同一家（別名綁定在對帳頁的「對應到客戶主檔」）。對不到主檔的退回原名稱。
 */
function sq_client_key(array $known, string $name, $cidFallback = ''): string
{
    $name = trim($name);
    if ($name !== '' && isset($known[$name])) return 'C' . trim((string)$known[$name]['customer_id']);
    $c = trim((string)$cidFallback);
    if ($name === '' && $c !== '' && $c !== '0') return 'C' . $c;
    return 'N:' . $name;
}

function sq_match_preview(PDO $db, string $from, string $to, array $opt = []): array
{
    require_once __DIR__ . '/acc_lib.php';
    /* 篩選：客戶簡稱（精確）／料號 id。這兩個鍵都是「整組 (客戶,料號) 保留或整組排除」，
       不會把同一組的出貨切成兩半，所以群組內 FIFO 的先後順序不受影響（日期區間才會）。 */
    $client = trim((string)($opt['client'] ?? ''));
    $didF   = (int)($opt['d_id'] ?? 0);
    $withNo = !empty($opt['with_unmatched']);   // 連「推不出對應」的也回傳，供人工指定

    sq_scrap_ensure($db);
    $w   = ["il.Order_id IS NULL", "il.d_setting_id IS NOT NULL", "il.Order_date BETWEEN ? AND ?",
            "NOT EXISTS (SELECT 1 FROM is_bom_scrap_map sm WHERE sm.IS_id = il.IS_id)",   // 已登記為報廢品歸還的不再列入待回填
            // 已經綁了製令（is_bom_map 分配量已涵蓋整筆）或已有訂單分配（is_order_map／shipment_order_map）的，算已綁定，不再列入
            "COALESCE((SELECT SUM(m.shipped_qty) FROM is_bom_map m WHERE m.IS_id = il.IS_id),0) < il.Qty",
            "NOT EXISTS (SELECT 1 FROM is_order_map om WHERE om.IS_id = il.IS_id)",
            "NOT EXISTS (SELECT 1 FROM shipment_order_map so2 WHERE so2.IS_id = il.IS_id)"];
    $par = [$from, $to];
    if ($client !== '') { $w[] = "il.Client_name = ?";  $par[] = $client; }
    if ($didF   >  0)   { $w[] = "il.d_setting_id = ?"; $par[] = $didF; }

    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
               il.Client_name, il.Client_id, il.Product_id, il.d_setting_id, il.Qty, il.Unit_price
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
    // 同一個料號文字常有重複的料號主檔（匯入出貨時另建一筆沒有客戶的）→ 料號以「料號文字」為準，主檔 id 不同也算同料號
    $parts = array_values(array_unique(array_filter(array_map(function ($x) { return trim((string)$x['Product_id']); }, $ships), 'strlen')));
    $pph   = $parts ? implode(',', array_fill(0, count($parts), '?')) : "''";
    $known = acc_customer_by_name($db);
    $so   = $db->prepare("
        SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.Client_name_ID, ot.d_id, ot.d_id_ID, ot.Qty,
               ot.unit_price, ot.Order_status,
               DATE_FORMAT(ot.Order_date,'%Y-%m-%d')    AS order_date,
               DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS delivery_date,
               COALESCE(sh.sq, 0) AS used_qty
        FROM order_track ot
        LEFT JOIN (" . sq_shipped_by_order_sql() . ") sh ON sh.Order_id = ot.Order_id
        WHERE (ot.d_id_ID IN ($ph)" . ($parts ? " OR TRIM(ot.d_id) IN ($pph)" : '') . ")
          AND (ot.Order_status IS NULL OR ot.Order_status <> 6)   -- 暫停／取消的訂單不可當回填對象（9＝已結案仍保留，回填要涵蓋歷史）
        ORDER BY ot.Order_date, ot.Order_id");
    $so->execute(array_merge($dids, $parts));

    // 依 (客戶歸戶, 料號文字) 分組
    $ordersByKey = [];
    foreach ($so->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $key = sq_client_key($known, (string)$o['Client_name'], $o['Client_name_ID']) . '|' . trim((string)$o['d_id']);
        $o['left'] = max(0, (int)$o['Qty'] - (int)$o['used_qty']);
        $ordersByKey[$key][] = $o;
    }

    $pairs = [];
    foreach ($ships as $s) {
        $key  = sq_client_key($known, (string)$s['Client_name'], $s['Client_id'] ?? '') . '|' . trim((string)$s['Product_id']);
        $cand = $ordersByKey[$key] ?? [];
        $hit  = null;

        /* 挑訂單（2026-11-11 使用者回報「自動帶入的訂單根本是錯的」）：
           單純 FIFO 會先吃到多年前「出貨從沒回填過、所以看起來還有剩」的舊訂單
           （例：2026 年單價 135 的出貨被推到 2019 年單價 300、只剩 40 支的訂單）。
           改成在「下單日不晚於出貨日、還有剩餘量」的候選中依序比：
           ①單價相同 ②剩餘量放得下整筆出貨 ③下單日早者（FIFO）。 */
        $bestIdx = null; $bestRank = null;
        foreach ($cand as $idx => $o) {
            $o = $ordersByKey[$key][$idx];
            if ($o['left'] <= 0) continue;
            if ($o['order_date'] > $s['ship_date']) continue;   // 不可出在下單之前
            $pm   = (abs((float)$o['unit_price'] - (float)$s['Unit_price']) < 0.01) ? 0 : 1;
            $fits = ((int)$o['left'] >= (int)$s['Qty']) ? 0 : 1;
            $rank = [$pm, $fits];
            if ($bestRank === null || $rank < $bestRank) { $bestRank = $rank; $bestIdx = $idx; }
        }
        if ($bestIdx !== null) {
            $hit = $ordersByKey[$key][$bestIdx];
            $ordersByKey[$key][$bestIdx]['left'] = $hit['left'] - (int)$s['Qty'];
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
    sq_scrap_ensure($db);
    $st = $db->prepare("
        SELECT il.Client_name, il.d_setting_id, MAX(il.Product_id) AS product_id, COUNT(*) AS cnt
        FROM is_list il
        WHERE il.Order_id IS NULL AND il.d_setting_id IS NOT NULL
          AND il.Order_date BETWEEN ? AND ?
          AND NOT EXISTS (SELECT 1 FROM is_bom_scrap_map sm WHERE sm.IS_id = il.IS_id)
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

    require_once __DIR__ . '/acc_lib.php';
    $did = (int)$s['d_setting_id'];
    // 同一家客戶：出貨上的客戶簡稱經客戶主檔（含別名）歸戶後，列出該客戶所有寫法（如陽／如陽科技）
    $known = acc_customer_by_name($db);
    $ck = sq_client_key($known, (string)$s['Client_name']);
    $names = [trim((string)$s['Client_name'])];
    foreach ($known as $nm => $c) if ('C' . trim((string)$c['customer_id']) === $ck) $names[] = (string)$nm;
    $names = array_values(array_unique(array_filter($names, 'strlen')));
    $nph = []; $par = [];
    foreach ($names as $i => $nm) { $nph[] = ':cn' . $i; $par[':cn' . $i] = $nm; }
    $w = [$nph ? "ot.Client_name IN (" . implode(',', $nph) . ")" : "ot.Client_name = ''",
          "(ot.Order_status IS NULL OR ot.Order_status <> 6)"];   // 暫停／取消的訂單不列入
    if ($samePart) {
        $pt = trim((string)$s['Product_id']);
        if ($did <= 0 && $pt === '') return ['ship' => $s, 'orders' => [], 'error' => '這筆出貨沒有料號，無法用「相同料號」篩選'];
        $pw = [];
        if ($did > 0)  { $pw[] = "ot.d_id_ID = :did"; $par[':did'] = $did; }
        if ($pt !== '') { $pw[] = "TRIM(ot.d_id) = :pt"; $par[':pt'] = $pt; }   // 料號主檔重複時，以料號文字為準
        $w[] = '(' . implode(' OR ', $pw) . ')';
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
        LEFT JOIN (" . sq_shipped_by_order_sql() . ") sh ON sh.Order_id = ot.Order_id
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
            // 已被其他出貨吃完＝不可選（照樣列出供確認）；但出貨單價為 0（報廢品／補貨／贈品）不受剩餘量限制，仍可綁訂單
            'selectable'  => ($left > 0 || (float)$s['Unit_price'] == 0.0),
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

/**
 * 某筆出貨明細可以「歸還報廢品」的製令：同客戶＋同料號的訂單所綁的製令，且有已結案報廢單的報廢數。
 * 回傳每張製令的第一關製程（138 客供料、170 材料…）與報廢品可退件數；第一關是 170 材料的不給選（報廢品不退客戶）。
 */
function sq_match_scrap_boms(PDO $db, int $isId): array
{
    sq_scrap_ensure($db);
    $st = $db->prepare("SELECT Client_name, d_setting_id, Qty FROM is_list WHERE IS_id=?");
    $st->execute([$isId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return ['boms' => [], 'error' => '找不到這筆出貨明細'];
    $did = (int)$s['d_setting_id'];
    $q = $db->prepare("SELECT DISTINCT bom FROM (
            SELECT m.bom FROM bom_order_process_map m JOIN order_track ot ON ot.Order_id=m.order_id
             WHERE ot.Client_name=:c1 AND ot.d_id_ID=:d1
            UNION
            SELECT b.bom FROM bom b JOIN order_track ot ON ot.Order_id=b.o_order_id
             WHERE ot.Client_name=:c2 AND ot.d_id_ID=:d2) x LIMIT 60");
    $q->execute([':c1' => $s['Client_name'], ':d1' => $did, ':c2' => $s['Client_name'], ':d2' => $did]);
    $boms = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$boms) return ['boms' => [], 'ship_qty' => (int)$s['Qty']];
    $am = sq_bom_avail_map($db, $boms);
    $ph = implode(',', array_fill(0, count($boms), '?'));
    $fp = $db->prepare("SELECT bi.bom, bi.process_no, pn.ProcessName FROM bom_ing bi
                          JOIN (SELECT bom, MIN(bom_sn) AS msn FROM bom_ing WHERE bom IN ($ph) GROUP BY bom) m ON m.bom=bi.bom AND m.msn=bi.bom_sn
                          LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no");
    $fp->execute($boms);
    $first = [];
    foreach ($fp->fetchAll(PDO::FETCH_ASSOC) as $f) $first[$f['bom']] = $f;
    $out = [];
    foreach ($boms as $b) {
        $a = $am[$b] ?? null;
        if (!$a || (int)$a['scrap'] <= 0) continue;
        $out[] = ['bom' => $b, 'scrap' => (int)$a['scrap'], 'scrap_ret' => (int)$a['scrap_ret'], 'scrap_avail' => (int)$a['scrap_avail'],
                  'first_no' => (int)($first[$b]['process_no'] ?? 0), 'first_name' => (string)($first[$b]['ProcessName'] ?? ''),
                  'elig' => !empty($a['scrap_elig'])];
    }
    return ['boms' => $out, 'ship_qty' => (int)$s['Qty']];
}

/** 把一筆（尚未綁訂單、尚未登記）的出貨明細登記為某製令的報廢品歸還。成功回 ''，失敗回原因。 */
function sq_scrap_bind_one(PDO $db, int $isId, string $bom): string
{
    $st = $db->prepare("SELECT IS_id, Qty, Client_name, Order_id, d_setting_id FROM is_list WHERE IS_id=? FOR UPDATE");
    $st->execute([$isId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return '找不到出貨明細';
    if ($s['Order_id'] !== null) return '已綁訂單';
    $ex = $db->prepare("SELECT 1 FROM is_bom_scrap_map WHERE IS_id=?");
    $ex->execute([$isId]);
    if ($ex->fetchColumn()) return '已登記';
    $cand = sq_match_scrap_boms($db, $isId);   // 同客戶＋同料號的訂單所綁製令才可選
    $hit = null;
    foreach ($cand['boms'] ?? [] as $c) if ($c['bom'] === $bom) $hit = $c;
    if (!$hit || empty($hit['elig'])) return '製令不符或第一關是 170 材料';
    $qty = (int)$s['Qty'];
    if ($qty <= 0 || $qty > (int)$hit['scrap_avail']) return '數量超過報廢品可退';
    $db->prepare("INSERT INTO is_bom_scrap_map (IS_id, bom, qty) VALUES (?,?,?)")->execute([$isId, $bom, $qty]);
    return '';
}

/** 寫入回填結果（僅覆寫 Order_id 仍為 NULL 的列，避免蓋掉已正確的資料） */
function sq_match_apply(PDO $db, array $pairs, string $userId): array
{
    sq_scrap_ensure($db);
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
             WHERE il.IS_id = :isid AND il.Order_id IS NULL");
        require_once __DIR__ . '/acc_lib.php';
        $known = acc_customer_by_name($db);   // 客戶比對一律先歸戶（含別名）：出貨「如陽科技」與訂單「如陽」是同一家
        $qIs = $db->prepare("SELECT Client_name, Client_id FROM is_list WHERE IS_id = ?");
        $qOt = $db->prepare("SELECT Client_name, Client_name_ID FROM order_track WHERE Order_id = ?");
        foreach ($pairs as $p) {
            $isId = (int)($p['is_id'] ?? 0);
            $oid  = (int)($p['order_id'] ?? 0);
            $sb   = trim((string)($p['scrap_bom'] ?? ''));
            if ($isId > 0 && $sb !== '') {   // 報廢品歸還：不綁訂單，記在 is_bom_scrap_map（第一關非 170 材料、數量不超過報廢品可退）
                $r = sq_scrap_bind_one($db, $isId, $sb);
                if ($r === '') $applied++; else $skipped++;
                continue;
            }
            if ($isId <= 0 || $oid <= 0) { $skipped++; continue; }
            $qIs->execute([$isId]); $ri = $qIs->fetch(PDO::FETCH_ASSOC);
            $qOt->execute([$oid]);  $ro = $qOt->fetch(PDO::FETCH_ASSOC);
            if (!$ri || !$ro || sq_client_key($known, (string)$ri['Client_name'], $ri['Client_id']) !== sq_client_key($known, (string)$ro['Client_name'], $ro['Client_name_ID'])) { $skipped++; continue; }
            $up->execute([':oid' => $oid, ':isid' => $isId]);
            if ($up->rowCount() > 0) { $applied++; $appliedIs[] = $isId; } else $skipped++;
        }
        $db->commit();
        if (!empty($appliedIs)) { require_once __DIR__ . '/is_bind_lock_lib.php'; isbl_sync($db, $appliedIs, (int)$userId); }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '回填失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'applied' => $applied, 'skipped' => $skipped,
            'message' => "已回填 {$applied} 筆" . ($skipped ? "，略過 {$skipped} 筆（已有訂單編號、客戶簡稱不符或資料已異動）" : '')];
}

/**
 * 「綁定舊出貨單」候選（BOM總覽／完工BOM 點待出貨進來，製令已有訂單編號時用）：
 * 製令綁定的訂單 → 同客戶、(已綁這些訂單 或 尚未綁訂單且同料號) 的出貨明細，
 * 排除報廢歸還、已全數分配給製令的出貨，並標出這張製令已綁的量。
 */
/** 綁定舊出貨單視窗可標記的出貨性質（種類代碼 → 出貨性質名稱）；標記只是順便設定出貨性質，這筆仍可照常勾選綁定 */
function sq_mark_kinds(): array
{
    return ['return' => '備註/歸還', 'ng' => '歸還(NG/來料不良)', 'jig' => '治具'];
}

/** 標記種類在畫面上的短名稱 */
function sq_mark_label(string $kind): string
{
    return ['return' => '歸還', 'ng' => 'NG歸還', 'jig' => '治具'][$kind] ?? $kind;
}

/** 出貨性質名稱 → id（依名稱找，管理員改了 id 也不失效；找不到回 0） */
function sq_sale_type_id_by_name(PDO $db, string $name): int
{
    try {
        $st = $db->prepare("SELECT sale_type_id FROM is_sale_type WHERE sale_type_name = ? ORDER BY is_active DESC, sale_type_id LIMIT 1");
        $st->execute([$name]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * 把一筆出貨明細設為「歸還」「NG歸還」或「治具」（改出貨性質）；$on=false 取消（只還原原本就是這兩種標記的）。
 * 已綁定任何製令分配的不給設，避免製令已出貨量與出貨性質互相矛盾。
 */
function sq_ship_set_return(PDO $db, int $isId, bool $on, string $userId, string $kind = 'return'): array
{
    $kinds = sq_mark_kinds();
    if (!isset($kinds[$kind])) return ['success' => false, 'message' => '不支援的標記種類'];
    $typeId = sq_sale_type_id_by_name($db, $kinds[$kind]);
    if ($typeId <= 0) return ['success' => false, 'message' => '找不到出貨性質「' . $kinds[$kind] . '」，請先在出貨性質設定建立'];
    $all = array_values(array_filter(array_map(function ($n) use ($db) { return sq_sale_type_id_by_name($db, $n); }, $kinds)));
    $st = $db->prepare("SELECT sale_type, (SELECT COUNT(*) FROM is_bom_map m WHERE m.IS_id = il.IS_id) AS maps FROM is_list il WHERE il.IS_id = ?");
    $st->execute([$isId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['success' => false, 'message' => '找不到這筆出貨明細'];
    if ($on) {
        if ((int)$r['maps'] > 0) return ['success' => false, 'message' => '這筆已綁定製令，不能設為' . sq_mark_label($kind) . '（請先解除綁定）'];
        $db->prepare("UPDATE is_list SET sale_type = ? WHERE IS_id = ?")->execute([$typeId, $isId]);
    } else {
        $db->prepare("UPDATE is_list SET sale_type = NULL WHERE IS_id = ? AND sale_type IN (" . implode(',', array_map('intval', $all)) . ")")->execute([$isId]);
    }
    $label = sq_mark_label($kind);
    try {
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, created_at) VALUES (?,?,?,?,?,?,NOW())")
           ->execute(['update', 'is_list', (string)$isId, '出貨性質', '綁定舊出貨單視窗：' . ($on ? "設為{$label}（{$kinds[$kind]}）" : '取消標記'), (int)$userId]);
    } catch (Throwable $e) { /* 稽核失敗不阻斷 */ }
    return ['success' => true, 'kind' => $on ? $kind : '', 'message' => $on ? "已設為{$label}（出貨性質：{$kinds[$kind]}）" : '已取消標記'];
}

function sq_bom_bind_candidates(PDO $db, string $bom): array
{
    $bom = trim($bom);
    if ($bom === '') return ['error' => '缺少製令編號'];
    $st = $db->prepare("SELECT order_id FROM bom_order_process_map WHERE bom=? UNION SELECT CAST(o_order_id AS UNSIGNED) FROM bom WHERE bom=? AND o_order_id REGEXP '^[0-9]+\$'");
    $st->execute([$bom, $bom]);
    $oids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    if (!$oids) return ['error' => '這張製令尚未綁定訂單，請先到 BOM總覽綁訂單'];
    $in = implode(',', $oids);
    // 訂單的 Client_name 常是空字串（只有 Client_name_ID），要用客戶編號對回主檔，否則「同客戶」條件什麼都比對不到
    $os = $db->query("SELECT ot.Order_id, ot.Order_oo, COALESCE(NULLIF(TRIM(ot.Client_name),''), cl.customer, '') AS Client_name, ot.d_id_ID, ot.unit_price
                      FROM order_track ot LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID WHERE ot.Order_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    $clients = array_values(array_unique(array_filter(array_column($os, 'Client_name'), 'strlen')));
    $dids    = array_values(array_unique(array_filter(array_map('intval', array_column($os, 'd_id_ID')))));
    $oo = []; $op = [];
    foreach ($os as $o) { $oo[(int)$o['Order_id']] = (string)$o['Order_oo']; $op[(int)$o['Order_id']] = (float)$o['unit_price']; }
    if (!$clients) return ['error' => '找不到綁定訂單的客戶'];
    $cph = implode(',', array_fill(0, count($clients), '?'));
    // 同一個料號文字常有重複的料號主檔（匯入出貨時另建一筆沒有客戶的）→ 尚未綁訂單的出貨單，料號主檔 id 或料號文字符合都算；
    // 客戶名稱 ERP 常寫得比主檔長（如陽科技／如陽），所以出貨客戶「以訂單客戶簡稱開頭」也算同客戶
    $specs = [];
    if ($dids) {
        $sq = $db->query("SELECT DISTINCT TRIM(D_Setting_Id) FROM d_setting WHERE d_id IN (" . implode(',', $dids) . ") AND TRIM(D_Setting_Id) <> ''");
        $specs = array_values(array_filter($sq->fetchAll(PDO::FETCH_COLUMN), 'strlen'));
    }
    $cond = "il.Order_id IN ($in)";
    $pparams = [];
    $pcond = [];
    if ($dids)  $pcond[] = "il.d_setting_id IN (" . implode(',', $dids) . ")";
    if ($specs) { $pcond[] = "il.Product_id IN (" . implode(',', array_fill(0, count($specs), '?')) . ")"; $pparams = $specs; }
    if ($pcond) $cond .= " OR (il.Order_id IS NULL AND (" . implode(' OR ', $pcond) . "))";
    $clikes = [];
    foreach ($clients as $c) $clikes[] = "il.Client_name LIKE ?";
    $q = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') d, il.Client_name, il.Product_id, il.Specification, il.Content, il.Note, il.Qty, il.Unit_price, il.Order_id, il.sale_type,
               COALESCE((SELECT SUM(m.shipped_qty) FROM is_bom_map m WHERE m.IS_id = il.IS_id), 0) AS alloc,
               COALESCE((SELECT SUM(m.shipped_qty) FROM is_bom_map m WHERE m.IS_id = il.IS_id AND m.bom = ?), 0) AS this_bom
        FROM is_list il
        WHERE (il.Client_name IN ($cph) OR " . implode(' OR ', $clikes) . ") AND ($cond)
          AND NOT EXISTS (SELECT 1 FROM is_bom_scrap_map sm WHERE sm.IS_id = il.IS_id)
        ORDER BY il.Order_date DESC, il.IS_id DESC LIMIT 200");
    $q->execute(array_merge([$bom], $clients, array_map(function ($c) { return $c . '%'; }, $clients), $pparams));
    $bd = preg_match('~^B-(\d{3})(\d{2})(\d{2})~', $bom, $m) ? sprintf('%04d-%s-%s', (int)$m[1] + 1911, $m[2], $m[3]) : '';
    $out = [];
    $markIds = [];
    foreach (sq_mark_kinds() as $mk => $mn) { $mid = sq_sale_type_id_by_name($db, $mn); if ($mid > 0) $markIds[$mid] = $mk; }
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($bd !== '' && $r['d'] < $bd) continue;   // 出貨早於製令開立＝不可能是這張製令出的
        $left = (int)$r['Qty'] - (int)$r['alloc'];
        // 單價相符＝出貨單價等於它綁的訂單單價（尚未綁訂單者，與這張製令任一綁定訂單的單價相同）
        $refs = $r['Order_id'] ? [$op[(int)$r['Order_id']] ?? 0.0] : array_values($op);
        $pm = false; foreach ($refs as $rp) if ($rp > 0 && abs($rp - (float)$r['Unit_price']) < 0.01) $pm = true;
        $out[] = ['price_match' => $pm, 'order_price' => $refs[0] ?? 0, 'is_id' => (int)$r['IS_id'], 'no' => (string)$r['IS_number'], 'date' => (string)$r['d'],
                  'part' => (string)$r['Product_id'], 'spec' => trim((string)$r['Specification'] . ' ' . (string)$r['Content']), 'note' => (string)$r['Note'], 'qty' => (int)$r['Qty'], 'price' => (float)$r['Unit_price'],
                  'order_oo' => $r['Order_id'] ? ($oo[(int)$r['Order_id']] ?? '') : '',
                  'has_order' => $r['Order_id'] !== null, 'alloc' => (int)$r['alloc'], 'this_bom' => (int)$r['this_bom'],
                  'left' => max(0, $left), 'sale_kind' => ($markIds[(int)$r['sale_type']] ?? ''), 'returned' => isset($markIds[(int)$r['sale_type']]),
                  'selectable' => $left > 0 && (int)$r['this_bom'] === 0];
    }
    /* 建議（fifo）：單價相符、還可分配的出貨明細，依出貨日由舊到新（先出先綁）累加到這張製令還沒分配的量為止，
       超過的不再標建議——不可以因為單價相符就把很晚才出的貨排在前面，早於它的出貨紀錄要先看。 */
    $cap = 0;
    try {
        $cq = $db->prepare("SELECT sqty - COALESCE((SELECT SUM(shipped_qty) FROM is_bom_map WHERE bom = ?),0) FROM bom WHERE bom = ?");
        $cq->execute([$bom, $bom]);
        $cap = max(0, (int)$cq->fetchColumn());
    } catch (Throwable $e) { $cap = 0; }
    $chron = array_keys($out);
    usort($chron, function ($x, $y) use ($out) { return [$out[$x]['date'], $out[$x]['no'], $out[$x]['is_id']] <=> [$out[$y]['date'], $out[$y]['no'], $out[$y]['is_id']]; });
    foreach ($out as $k => $_) $out[$k]['fifo'] = false;
    foreach ($chron as $k) {
        if ($cap <= 0) break;
        if (!$out[$k]['selectable'] || !$out[$k]['price_match']) continue;
        $out[$k]['fifo'] = true;
        $cap -= $out[$k]['left'];
    }
    // 排序：出貨日、出貨單號由新到舊，從上而下（不置頂、不分組）
    usort($out, function ($a, $b) {
        return [$b['date'], $b['no'], $b['is_id']] <=> [$a['date'], $a['no'], $a['is_id']];
    });
    // 製令綁定的訂單資訊（顯示在跳窗最上方供比對；品名規格與待出貨清單同一套 sq_desc_text）
    $oi = [];
    $oRows = $db->query("SELECT ot.Order_id, ot.Order_oo, COALESCE(NULLIF(TRIM(ot.Client_name),''), cl2.customer, '') AS Client_name, ot.d_id, ot.d_id_ID, ot.Specification, ot.Qty, ot.unit_price,
                                COALESCE(ot.Processing_items,'') AS proc, COALESCE(ds.Spec_No,'') AS part_spec,
                                DATE_FORMAT(ot.Order_date,'%Y-%m-%d') od, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') dd,
                                COALESCE((SELECT SUM(x.q) FROM (" . sq_shipped_rows_sql() . ") x WHERE x.Order_id = ot.Order_id),0) used
                         FROM order_track ot LEFT JOIN d_setting ds ON ds.d_id = ot.d_id_ID
                         LEFT JOIN customer_list cl2 ON cl2.customer_id = ot.Client_name_ID
                         WHERE ot.Order_id IN ($in) ORDER BY ot.Order_date")->fetchAll(PDO::FETCH_ASSOC);
    $gm = eg_gear_spec_map($db, array_column($oRows, 'd_id_ID'));
    foreach ($oRows as $o) {
        $gear = $o['d_id_ID'] !== null ? ($gm[(int)$o['d_id_ID']] ?? '') : '';
        $oi[] = ['oo' => (string)$o['Order_oo'], 'client' => (string)$o['Client_name'], 'part' => (string)$o['d_id'],
                 'spec' => sq_desc_text($o['part_spec'], $gear, $o['proc'], $o['Specification']),
                 'qty' => (int)$o['Qty'], 'used' => (int)$o['used'], 'price' => (float)$o['unit_price'], 'order_date' => (string)$o['od'], 'delivery' => (string)$o['dd']];
    }
    return ['bom' => $bom, 'order_info' => $oi, 'orders' => array_values($oo), 'order_prices' => array_values($op), 'ships' => $out];
}

/**
 * 訂單還沒有製令時，列出可以綁給這張訂單的製令（同客戶、同料號——料號主檔 id 或料號文字相同），
 * 並依「製令開立日不早於下單日、離下單日最近、還有未分配量」排出建議順序。
 * 寫入一律走 trace_chain_lib 的 tc_link('order_bom')，這裡只負責挑候選。
 */
function sq_order_bom_candidates(PDO $db, int $orderId): array
{
    require_once __DIR__ . '/acc_lib.php';
    $st = $db->prepare("SELECT ot.Order_id, ot.Order_oo, ot.Qty, ot.d_id, ot.d_id_ID, ot.Client_name, ot.Client_name_ID,
                               DATE_FORMAT(ot.Order_date,'%Y-%m-%d') od
                        FROM order_track ot WHERE ot.Order_id = ?");
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) return ['error' => '找不到這張訂單'];
    $known = acc_customer_by_name($db);
    $oc = sq_client_key($known, (string)$o['Client_name'], $o['Client_name_ID']);
    $pt = trim((string)$o['d_id']);
    $did = (int)$o['d_id_ID'];
    if ($pt === '' && $did <= 0) return ['error' => '這張訂單沒有料號，無法找製令'];
    $w = []; $par = [];
    if ($did > 0) { $w[] = 'b.d_setting_id = ?'; $par[] = $did; }
    if ($pt !== '') { $w[] = 'TRIM(b.d_id) = ?'; $par[] = $pt; }
    $q = $db->prepare("SELECT b.bom, b.d_id, b.d_setting_id, b.Client_Name, b.sqty, b.o_order_id, b.processing_state,
                              COALESCE((SELECT SUM(m.allocated_qty) FROM bom_order_process_map m WHERE m.bom = b.bom),0) AS alloc,
                              (SELECT COUNT(*) FROM bom_order_process_map m WHERE m.bom = b.bom) AS maps,
                              (SELECT GROUP_CONCAT(DISTINCT ot2.Order_oo ORDER BY ot2.Order_oo SEPARATOR '、')
                                 FROM bom_order_process_map m JOIN order_track ot2 ON ot2.Order_id = m.order_id WHERE m.bom = b.bom) AS bound_oo,
                              (SELECT Order_oo FROM order_track WHERE Order_id = CAST(b.o_order_id AS UNSIGNED)) AS legacy_oo
                       FROM bom b WHERE (" . implode(' OR ', $w) . ")
                       ORDER BY ABS(DATEDIFF(STR_TO_DATE(CONCAT(CAST(SUBSTRING(b.bom,3,3) AS UNSIGNED)+1911, SUBSTRING(b.bom,6,4)),'%Y%m%d'), ?)) , b.bom DESC LIMIT 300");   // 取離下單日最近的 300 張（只取最新 300 張會把舊訂單的製令整批切掉）
    $par[] = ($o['od'] !== '' && $o['od'] !== null) ? $o['od'] : date('Y-m-d');
    $q->execute($par);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if (sq_client_key($known, (string)$b['Client_Name']) !== $oc) continue;   // 客戶不同一律不列（tc_link 也會再擋）
        $sq = (int)$b['sqty'];
        $alloc = (int)$b['alloc'];
        if ($alloc <= 0 && (int)$b['maps'] === 0 && (string)$b['o_order_id'] !== '' && (int)$b['o_order_id'] > 0) $alloc = $sq;   // 舊欄位上的綁定視同整張分出去
        $left = max(0, $sq - $alloc);
        $bd = preg_match('~^B-(\d{3})(\d{2})(\d{2})~', (string)$b['bom'], $m) ? sprintf('%04d-%s-%s', (int)$m[1] + 1911, $m[2], $m[3]) : '';
        $gap = ($bd !== '' && $o['od'] !== '') ? (int)round((strtotime($bd) - strtotime($o['od'])) / 86400) : null;
        $bound = trim((string)($b['bound_oo'] ?: ($b['legacy_oo'] ?? '')));
        $out[] = ['bom' => (string)$b['bom'], 'bom_date' => $bd, 'sqty' => $sq, 'alloc' => $alloc, 'left' => $left,
                  'part' => (string)$b['d_id'], 'bound_oo' => $bound, 'gap_days' => $gap,
                  'before_order' => ($gap !== null && $gap < 0), 'done' => ((string)$b['processing_state'] === 'E' || (string)$b['processing_state'] === 'F')];
    }
    // 建議順序：還有未分配量 → 製令開立日不早於下單日 → 離下單日最近
    usort($out, function ($a, $b) {
        $ka = [$a['left'] > 0 ? 0 : 1, $a['before_order'] ? 1 : 0, $a['gap_days'] === null ? 99999 : abs($a['gap_days'])];
        $kb = [$b['left'] > 0 ? 0 : 1, $b['before_order'] ? 1 : 0, $b['gap_days'] === null ? 99999 : abs($b['gap_days'])];
        return $ka <=> $kb ?: strcmp($b['bom'], $a['bom']);
    });
    $oalloc = (int)$db->query("SELECT COALESCE(SUM(allocated_qty),0) FROM bom_order_process_map WHERE order_id = " . (int)$orderId)->fetchColumn();
    $bs = $db->prepare("SELECT bom, allocated_qty FROM bom_order_process_map WHERE order_id = ? ORDER BY bom");
    $bs->execute([$orderId]);
    $bound = array_map(function ($r) { return ['bom' => (string)$r['bom'], 'qty' => (int)$r['allocated_qty']]; }, $bs->fetchAll(PDO::FETCH_ASSOC));
    $first = true;
    foreach ($out as &$r) { $r['suggest'] = ($first && $r['left'] > 0); if ($r['suggest']) $first = false; }
    unset($r);
    return ['order' => ['id' => (int)$o['Order_id'], 'oo' => (string)$o['Order_oo'], 'qty' => (int)$o['Qty'], 'part' => $pt,
                        'client' => (string)$o['Client_name'], 'order_date' => (string)$o['od'], 'alloc' => $oalloc,
                        'free' => max(0, (int)$o['Qty'] - $oalloc)],
            'boms' => $out, 'bound' => $bound];
}

/**
 * 一次替多張「還沒有製令」的訂單挑一對一的建議製令（只挑，不寫入）。
 * 每張訂單沿用 sq_order_bom_candidates 的排序，取第一張「還有未分配量、且這次批次裡沒被別張訂單先拿走」的製令；
 * 製令數量等於訂單數量＝exact（預設勾選），未分配量不足以涵蓋訂單＝partial（預設不勾，要綁請改用單筆視窗拆量）。
 */
function sq_order_bom_auto_preview(PDO $db, array $orderIds): array
{
    $orderIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $orderIds)))), 0, 150);
    $used = []; $rows = [];
    foreach ($orderIds as $oid) {
        $c = sq_order_bom_candidates($db, $oid);
        if (!empty($c['error'])) { $rows[] = ['order_id' => $oid, 'error' => $c['error']]; continue; }
        $o = $c['order'];
        $row = ['order_id' => $oid, 'oo' => $o['oo'], 'client' => $o['client'], 'part' => $o['part'], 'qty' => $o['qty'],
                'free' => $o['free'], 'order_date' => $o['order_date'], 'bom' => '', 'status' => 'none'];
        if ($o['free'] <= 0) { $row['status'] = 'full'; $rows[] = $row; continue; }
        foreach ($c['boms'] as $b) {
            if ($b['left'] <= 0 || isset($used[$b['bom']])) continue;
            $take = min($b['left'], $o['free']);
            $row += ['bom_date' => $b['bom_date'], 'bom_qty' => $b['sqty'], 'bom_left' => $b['left'], 'gap_days' => $b['gap_days'],
                     'before_order' => $b['before_order'], 'done' => $b['done']];
            $row['bom'] = $b['bom']; $row['take'] = $take;
            $row['status'] = ($b['left'] >= $o['free']) ? ($b['sqty'] === $o['qty'] ? 'exact' : 'cover') : 'partial';
            $used[$b['bom']] = true;
            break;
        }
        $rows[] = $row;
    }
    return ['rows' => $rows];
}

/** 批次綁定：items＝[{order_id,bom,qty}]，每筆各自走 sq_order_bom_bind（tc_link 驗證），某一筆失敗不影響其他筆。 */
function sq_order_bom_bind_batch(PDO $db, array $items, array $user): array
{
    $ok = 0; $errs = [];
    foreach (array_slice($items, 0, 150) as $it) {
        $oid = (int)($it['order_id'] ?? 0);
        $r = sq_order_bom_bind($db, $oid, [['bom' => $it['bom'] ?? '', 'qty' => (int)($it['qty'] ?? 0)]], $user);
        if (!empty($r['success'])) $ok++; else $errs[] = '訂單 #' . $oid . '：' . implode('；', $r['errors'] ?: [$r['message']]);
    }
    return ['success' => $ok > 0, 'applied' => $ok, 'errors' => $errs,
            'message' => ($ok ? "已綁定 {$ok} 張訂單的製令" : '沒有綁定任何訂單') . ($errs ? '；' . count($errs) . ' 筆失敗：' . implode('；', array_slice($errs, 0, 3)) : '')];
}

/** 把製令綁到訂單（訂單→製令，多對多拆量）：逐筆走 tc_link，客戶／數量規則都在那裡驗。 */
function sq_order_bom_bind(PDO $db, int $orderId, array $pairs, array $user): array
{
    require_once __DIR__ . '/trace_chain_lib.php';
    $ok = 0; $errs = [];
    foreach ($pairs as $p) {
        $bom = trim((string)($p['bom'] ?? '')); $qty = (int)($p['qty'] ?? 0);
        if ($bom === '' || $qty <= 0) { $errs[] = ($bom ?: '（空）') . '：數量必須大於 0'; continue; }
        $r = tc_link($db, 'order_bom', $orderId, $bom, $qty, $user);
        if (!empty($r['success'])) $ok++; else $errs[] = $bom . '：' . ($r['message'] ?? '綁定失敗');
    }
    return ['success' => $ok > 0, 'applied' => $ok, 'errors' => $errs,
            'message' => ($ok ? "已綁定 {$ok} 張製令" : '沒有綁定任何製令') . ($errs ? '；' . implode('；', $errs) : '')];
}

/** 把舊出貨明細綁到這張製令（is_bom_map）；尚未綁訂單者一併補上製令綁定的訂單（同客戶＋同料號才補）。 */
function sq_bom_bind_apply(PDO $db, string $bom, array $pairs, string $userId, bool $force = false): array
{
    $bom = trim($bom);
    $c = sq_bom_bind_candidates($db, $bom);
    if (!empty($c['error'])) return ['success' => false, 'message' => $c['error']];
    $cand = []; foreach ($c['ships'] as $s) $cand[$s['is_id']] = $s;
    $applied = 0; $skipped = 0; $ids = [];
    try {
        $db->beginTransaction();
        $ins = $db->prepare("INSERT INTO is_bom_map (IS_id, bom, shipped_qty) VALUES (?,?,?)");
        $stO = $db->prepare("SELECT ot.Order_id FROM order_track ot
            JOIN (SELECT order_id FROM bom_order_process_map WHERE bom=:b1 UNION SELECT CAST(o_order_id AS UNSIGNED) FROM bom WHERE bom=:b2 AND o_order_id REGEXP '^[0-9]+\$') x ON x.order_id = ot.Order_id
            LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
            LEFT JOIN d_setting ds ON ds.d_id = ot.d_id_ID
            JOIN is_list il ON il.IS_id = :i AND il.Client_name LIKE CONCAT(COALESCE(NULLIF(TRIM(ot.Client_name),''), cl.customer), '%')
                 AND (il.d_setting_id = ot.d_id_ID OR (TRIM(ds.D_Setting_Id) <> '' AND il.Product_id = TRIM(ds.D_Setting_Id)))
            ORDER BY ot.Order_date LIMIT 1");
        $upO = $db->prepare("UPDATE is_list SET Order_id = ? WHERE IS_id = ? AND Order_id IS NULL");
        foreach ($pairs as $p) {
            $id = (int)($p['is_id'] ?? 0); $qty = (int)($p['qty'] ?? 0);
            $s = $cand[$id] ?? null;
            /* 強制模式（整批加工價格的出貨被誤打成 1 批、數量永遠對不上時用）：略過「分配量不可超過出貨本身數量」，
               只擋已綁過這張製令的；之後再把製令視為良品全數出完＋訂單結案。 */
            if (!$s || $qty <= 0 || ($force ? (int)$s['this_bom'] > 0 : (!$s['selectable'] || $qty > $s['left']))) { $skipped++; continue; }
            if (!$s['has_order']) {
                $stO->execute([':b1' => $bom, ':b2' => $bom, ':i' => $id]);
                $oid = (int)$stO->fetchColumn();
                if ($oid > 0) $upO->execute([$oid, $id]);   // 找不到同料號訂單就只綁製令，不亂掛訂單
            }
            $ins->execute([$id, $bom, $qty]);
            $applied++; $ids[] = $id;
        }
        $forceNote = '';
        if ($force && $applied > 0) {
            // 製令良品數（扣掉已確認報廢）還沒被綁定量涵蓋的差額，補在這次最後綁的一筆上＝認定良品全數出完
            require_once __DIR__ . '/qa_abnormal_lib.php';
            $bq = $db->prepare("SELECT sqty FROM bom WHERE bom = ?"); $bq->execute([$bom]);
            $sqty = (int)$bq->fetchColumn();
            $scrap = function_exists('qab_bom_scrap_qty') ? (int)qab_bom_scrap_qty($db, $bom, null) : 0;
            $good = max(0, $sqty - $scrap);
            $tq = $db->prepare("SELECT COALESCE(SUM(shipped_qty),0) FROM is_bom_map WHERE bom = ?"); $tq->execute([$bom]);
            $gap = $good - (int)$tq->fetchColumn();
            if ($gap > 0) {
                $db->prepare("UPDATE is_bom_map SET shipped_qty = shipped_qty + ? WHERE bom = ? AND IS_id = ?")->execute([$gap, $bom, end($ids)]);
                $forceNote = "（良品 {$good} 與綁定量差 {$gap}，已補在最後一筆）";
            }
            // 這張製令綁的訂單一併結案
            $oq = $db->prepare("SELECT order_id FROM bom_order_process_map WHERE bom = ? UNION SELECT CAST(o_order_id AS UNSIGNED) FROM bom WHERE bom = ? AND o_order_id REGEXP '^[0-9]+\$'");
            $oq->execute([$bom, $bom]);
            $oids = array_values(array_filter(array_map('intval', $oq->fetchAll(PDO::FETCH_COLUMN))));
            if ($oids) {
                $db->prepare("UPDATE order_track SET Order_status = 9, Modified_At = NOW(), Modified_By = ?
                              WHERE Order_id IN (" . implode(',', $oids) . ") AND (Order_status IS NULL OR Order_status NOT IN (6, 9))")->execute([$userId]);
                $forceNote .= '；訂單已結案（' . count($oids) . ' 張）';
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '綁定失敗：' . $e->getMessage()];
    }
    if ($ids) { require_once __DIR__ . '/is_bind_lock_lib.php'; isbl_sync($db, $ids, (int)$userId); }
    // 沒勾「強制綁定並結案」時：綁完之後出貨數已 >= 訂購數的訂單照樣自動認定結案（判定與建立出貨單後的自動結案同一條界線）
    $autoClosedOo = [];
    if (!$force && $ids) {
        try {
            $oset = [];
            $q1 = $db->query("SELECT Order_id FROM is_list WHERE Order_id IS NOT NULL AND IS_id IN (" . implode(',', array_map('intval', $ids)) . ")");
            foreach ($q1->fetchAll(PDO::FETCH_COLUMN) as $v) $oset[(int)$v] = 1;
            $q2 = $db->query("SELECT Order_id FROM is_order_map WHERE IS_id IN (" . implode(',', array_map('intval', $ids)) . ")");
            foreach ($q2->fetchAll(PDO::FETCH_COLUMN) as $v) $oset[(int)$v] = 1;
            $q3 = $db->prepare("SELECT order_id FROM bom_order_process_map WHERE bom = ? UNION SELECT CAST(o_order_id AS UNSIGNED) FROM bom WHERE bom = ? AND o_order_id REGEXP '^[0-9]+\$'");
            $q3->execute([$bom, $bom]);
            foreach ($q3->fetchAll(PDO::FETCH_COLUMN) as $v) if ((int)$v > 0) $oset[(int)$v] = 1;
            $autoClosedOo = sq_auto_close_orders($db, array_keys($oset), (string)$userId);
        } catch (Throwable $e) { error_log('bind auto close: ' . $e->getMessage()); }
    }
    if ($force && $ids) {   // 良品已全數涵蓋 → 製令自動結案（與一般綁定後的自動結案同一支）
        try { require_once __DIR__ . '/bom_bound_ship_lib.php'; eg_bom_auto_close_shipped($db, [$bom], null, (int)$userId); } catch (Throwable $e) { error_log('force close bom: ' . $e->getMessage()); }
    }
    return ['success' => true, 'applied' => $applied, 'skipped' => $skipped,
            'message' => "已綁定 {$applied} 筆出貨明細到 {$bom}" . ($force ? '（強制綁定' . $forceNote . '）' : '') . ($autoClosedOo ? '，訂單已出滿自動結案：' . implode('、', $autoClosedOo) : '') . ($skipped ? "，略過 {$skipped} 筆（已綁定、數量超過可分配量或資料已異動）" : '')];
}

/**
 * 出貨量 >= 訂購量的訂單自動結案（Order_status=9）。已出量依各訂單實際分到的量算（見 sq_shipped_rows_sql），
 * 出貨性質設為「不計入」的（is_count=0）不算；暫停／取消／已結案的不動。
 * @return array 這次被結案的訂單編號（Order_oo）
 */
function sq_auto_close_orders(PDO $db, array $orderIds, string $userId): array
{
    $done = [];
    foreach (array_values(array_unique(array_map('intval', array_filter($orderIds)))) as $oid) {
        try {
            $st = $db->prepare("SELECT Order_oo, Qty FROM order_track WHERE Order_id = ? AND (Order_status IS NULL OR Order_status NOT IN (6, 9))");
            $st->execute([$oid]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o || (int)$o['Qty'] <= 0) continue;
            $cnt = "(ist.is_count IS NULL OR ist.is_count <> 0)";
            $a = $db->prepare("SELECT COALESCE(SUM(m.allocated_qty),0) FROM is_order_map m
                                 JOIN is_list il ON il.IS_id = m.IS_id
                                 LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
                                WHERE m.Order_id = ? AND $cnt");
            $a->execute([$oid]);
            $b = $db->prepare("SELECT COALESCE(SUM(il.Qty),0) FROM is_list il
                                 LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
                                WHERE il.Order_id = ? AND $cnt
                                  AND NOT EXISTS (SELECT 1 FROM is_order_map m2 WHERE m2.IS_id = il.IS_id)");
            $b->execute([$oid]);
            if ((int)$a->fetchColumn() + (int)$b->fetchColumn() < (int)$o['Qty']) continue;
            $db->prepare("UPDATE order_track SET Order_status = 9, Modified_At = NOW(), Modified_By = ? WHERE Order_id = ?")
               ->execute([$userId, $oid]);
            $done[] = (string)$o['Order_oo'];
        } catch (Throwable $e) { /* 單筆失敗不阻斷其他訂單 */ }
    }
    return $done;
}

/**
 * 每張訂單「已出貨量」的唯一算法（2026-11-20）：
 * 一張出貨單分給好幾張訂單時（追溯對照／出貨單綁定的分配表 is_order_map），已出量要依「各訂單分到的量」算，
 * 不可以把整張出貨量記到 is_list.Order_id 那個「主要訂單」上——否則 382 支分給 181＋201 兩張訂單時，
 * 201 那張會顯示已出 382、181 那張顯示已出 0（舊算法）。
 * 沒有分配列的出貨單（只靠舊欄位 is_list.Order_id 綁定）仍整張算給那張訂單，所以沒用過分配表的資料結果不變。
 */
function sq_shipped_rows_sql(): string
{
    return "SELECT m.Order_id AS Order_id, m.allocated_qty AS q FROM is_order_map m
            UNION ALL
            SELECT il.Order_id, il.Qty FROM is_list il
             WHERE il.Order_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM is_order_map m2 WHERE m2.IS_id = il.IS_id)";
}
function sq_shipped_by_order_sql(): string
{
    return "SELECT Order_id, SUM(q) AS sq FROM (" . sq_shipped_rows_sql() . ") t GROUP BY Order_id";
}

/**
 * 綁了這張製令（完全相符）的出貨單 IS_id：製令→綁定的訂單（bom_order_process_map＋bom.o_order_id）→
 * 綁到這些訂單的出貨單（shipment_order_map、is_list.Order_id 兩種來源，見 ship_order_bind_lib.php）＋is_bom_map 直接綁定。
 */
function sq_is_ids_by_bom(PDO $db, string $bom): array
{
    $bom = trim($bom);
    if ($bom === '') return [];
    $st = $db->prepare("SELECT order_id FROM bom_order_process_map WHERE bom=? UNION SELECT o_order_id FROM bom WHERE bom=? AND o_order_id IS NOT NULL");
    $st->execute([$bom, $bom]);
    $oids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    $ids = [];
    $s2 = $db->prepare("SELECT IS_id FROM is_bom_map WHERE bom=?");
    $s2->execute([$bom]);
    foreach ($s2->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[(int)$v] = 1;
    if ($oids) {
        $in = implode(',', $oids);
        foreach ($db->query("SELECT IS_id FROM shipment_order_map WHERE Order_id IN ($in) UNION SELECT IS_id FROM is_list WHERE Order_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[(int)$v] = 1;
    }
    return array_keys($ids);
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
        // 也可打製令號（完全相符）：列出綁了這張製令的出貨單（BOM總覽「待出貨」深連結用）。
        // 先在 PHP 端解析出出貨單 id 再用 IN，不在 WHERE 裡放 OR 子查詢（實測會由毫秒級變十幾秒）
        $bomIs = sq_is_ids_by_bom($db, $kw);
        $where[] = "(il.IS_number LIKE :kw OR il.Client_name LIKE :kw OR il.Product_id LIKE :kw"
                 . ($bomIs ? " OR il.IS_id IN (" . implode(',', $bomIs) . ")" : '') . ")";
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
        foreach (['is_bom_map', 'is_bom_scrap_map', 'is_order_map', 'ir_reship_map', 'return_order_map', 'shipment_order_map'] as $t) {
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

/* ============================================================
 * 批次修改出貨單價（料號＋期間內，把單價 A 改成 B；非 A 的完全不動）
 * 2026-11-12 使用者交辦：ERP 單價欄位原為整數，匯入的小數單價被四捨五入，需要事後更正。
 * 只 UPDATE is_list.Unit_price（IS_id 不變，綁定不受影響），每筆留稽核紀錄。
 * ============================================================ */
function sq_price_batch_rows(PDO $db, int $dId, string $from, string $to, float $oldP): array
{
    // 以料號主檔 id 歸戶（同一個料號文字常掛在好幾家客戶底下）；d_setting_id 還是空的舊資料，退回「料號文字＋客戶名稱」
    $dq = $db->prepare("SELECT d.D_Setting_Id, TRIM(c.customer) cn FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id WHERE d.d_id=?");
    $dq->execute([$dId]); $dm = $dq->fetch(PDO::FETCH_ASSOC);
    if (!$dm) return ['hit' => [], 'other' => 0, 'scope' => 0];
    $sql = "SELECT IS_id, IS_number, DATE_FORMAT(Order_date,'%Y-%m-%d') d, Client_name, Product_id, Qty, Unit_price, bind_locked
            FROM is_list WHERE Order_date BETWEEN ? AND ?
            AND (d_setting_id = ? OR (d_setting_id IS NULL AND Product_id = ? AND TRIM(Client_name) = ?))";
    $args = [$from, $to, $dId, (string)$dm['D_Setting_Id'], (string)$dm['cn']];
    $sql .= " ORDER BY Order_date, IS_id";
    $st = $db->prepare($sql); $st->execute($args);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    $hit = []; $other = 0;
    foreach ($all as $r) {
        if (abs((float)$r['Unit_price'] - $oldP) < 0.00005) $hit[] = $r; else $other++;
    }
    return ['hit' => $hit, 'other' => $other, 'scope' => count($all)];
}

function sq_price_batch(PDO $db, array $in, bool $apply, array $user): array
{
    $dId  = (int)($in['d_id'] ?? 0);
    $from = trim((string)($in['date_from'] ?? ''));
    $to   = trim((string)($in['date_to'] ?? ''));
    if ($dId <= 0) return ['success' => false, 'message' => '請先從清單選擇要修改的料號'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to)
        return ['success' => false, 'message' => '請選擇正確的期間（起日不可晚於迄日）'];
    if (!is_numeric($in['old_price'] ?? null) || !is_numeric($in['new_price'] ?? null))
        return ['success' => false, 'message' => '原單價與新單價都必須是數字'];
    $oldP = round((float)$in['old_price'], 4); $newP = round((float)$in['new_price'], 4);
    if ($oldP < 0 || $newP < 0) return ['success' => false, 'message' => '單價不可為負數'];
    if (abs($oldP - $newP) < 0.00005) return ['success' => false, 'message' => '新單價與原單價相同，不需要修改'];

    $res = sq_price_batch_rows($db, $dId, $from, $to, $oldP);
    $rows = $res['hit'];
    $out = ['success' => true, 'rows' => $rows, 'count' => count($rows), 'other' => $res['other'], 'scope' => $res['scope'],
            'old_price' => $oldP, 'new_price' => $newP];
    if (!$apply) return $out;
    if (!$rows) return ['success' => false, 'message' => '期間內找不到單價為 ' . $oldP . ' 的出貨明細'];

    require_once __DIR__ . '/acc_voucher_lib.php';
    $vmap = acc_voucher_src_map($db);        // 內含 DDL，必須在交易外先跑
    $db->beginTransaction();
    try {
        $up = $db->prepare("UPDATE is_list SET Unit_price = ? WHERE IS_id = ? AND ABS(Unit_price - ?) < 0.00005");
        $n = 0;
        foreach ($rows as $r) {
            if (isset($vmap['IS-' . (int)$r['IS_id']])) continue;   // 已開傳票（已做帳）：單價鎖定
            $up->execute([$newP, (int)$r['IS_id'], $oldP]);
            if ($up->rowCount() > 0) {
                $n++;
                sq_audit($db, 'update', 'is_list_price', (string)$r['IS_id'], (string)$r['IS_number'],
                         ['Unit_price' => ['before' => $oldP, 'after' => $newP], 'reason' => '批次修改出貨單價'], $user);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '修改失敗：' . $e->getMessage()];
    }
    $out['updated'] = $n;
    $out['message'] = "已將 {$n} 筆出貨明細的單價由 {$oldP} 改為 {$newP}（其他單價的 {$res['other']} 筆不受影響）";
    return $out;
}
