<?php
/**
 * data_audit_lib.php — 資料稽核（流程順序稽核 ／ 基本資料稽核）唯一實作
 * 建立：2026-09-18（使用者交辦）
 *
 * 【這頁在稽核什麼】
 * 分頁一「流程順序稽核」：同一支料號的 報價單 → 訂單 → 製令(BOM) → 出貨單 四個節點，
 *   日期順序、數量、單價、製程是不是對得起來。使用者定的四條日期鐵則：
 *     ① 報價日期   <= 訂單日期
 *     ② 製令開立日 >= 訂單日期
 *     ③ 出貨日期   >= 製令開立日
 *     ④ 訂單設定「自動轉生管」(order_track.pmGet_auto=1) 者表示不必開製令，
 *        此時只要求 出貨日期 >= 訂單日期，缺製令不算缺失。
 * 分頁二「基本資料稽核」：客戶／廠商主檔的編號是否符合編碼原則、欄位是否完善。
 *
 * 【配對來源：綁定優先、推測補齊】（使用者拍板）
 *   報價→訂單  order_track.quote_item_id / quote_no
 *   訂單→製令  bom_order_process_map（已有 2,297 筆）→ 退回 bom.o_order_id
 *   訂單→出貨  is_order_map → 退回 is_list.Order_id
 *   都沒有時才用「同客戶＋同料號、日期就近」推測，並在畫面上標成「推測」。
 *
 * ⚠ 推測配對下**不判定數量不符**，只顯示參考值——
 *   一支料號的多張訂單與多張出貨在推測下本來就配不準，硬判會製造滿畫面的假警報。
 *   單價則照判（同客戶同料號的報價單價本來就該一致）。
 *
 * ⚠ 使用者特別提醒的兩件事，判定時已經避開：
 *   ①同料號的多張訂單追溯到同一張報價單是正常的，不算重複、不算異常。
 *   ②製令與訂單／出貨不是一對一，所以數量一律用**合計**比對，不做一對一比。
 *
 * 【AS 表單綁定】
 *   這裡綁的是「這次稽核涵蓋哪幾份 AS 表單」（多選），與 ai-rules/16 的
 *   AS_DOC_BIND（某頁面＝某表單的網頁版，一對一）是兩回事，所以另存
 *   system_parameters('DATA_AUDIT','scope_trace'/'scope_master')，不走 eg_asdoc_save()。
 *   內部稽核（views/ADM/internal_audit.php）要帶資料時呼叫 dqa_summary_for_docs()。
 */
if (!function_exists('dqa_ensure_schema')) {

define('DQA_PARAM_GROUP', 'DATA_AUDIT');

/* 綁定一律走既有的追溯鏈引擎（tc_link／tc_unlink／order_quote_map），這裡不另外刻一份寫入邏輯 */
require_once __DIR__ . '/trace_chain_lib.php';

/* ============================================================
 * Schema
 * ============================================================ */
function dqa_ensure_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS dqa_exempt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(20) NOT NULL COMMENT 'customer/maker/trace',
        target_key VARCHAR(60) NOT NULL COMMENT 'customer_id / maker_id_no / order_id',
        item_code VARCHAR(40) NOT NULL COMMENT '檢核項目代碼；* = 整筆不納入稽核',
        reason VARCHAR(300) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        UNIQUE KEY uk_dqa_ex (scope, target_key, item_code)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='資料稽核：已核可的例外（無統編、編號沿用舊制…）'");
}

/* ============================================================
 * 權限
 * ============================================================ */
function dqa_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_uname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function dqa_perms(PDO $db, ?array $u): array
{
    $none = ['isAdmin' => false, 'canAdmin' => false, 'canView' => false, 'uid' => 0, 'name' => ''];
    if (!$u) return $none;
    $uid = (int)$u['id'];
    if ((int)($u['state'] ?? 0) === 0 || (int)($u['user_status'] ?? 0) === 90) return $none;  // 離職/特殊帳號 fail-closed
    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;
    $has = function (array $codes) use ($db, $uid) {
        $in = implode(',', array_fill(0, count($codes), '?'));
        try {
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                WHERE ur.user_id=? AND r.role_code IN ($in) LIMIT 1");
            $st->execute(array_merge([$uid], $codes));
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    };
    $canAdmin = $isAdmin || $has(['dqa_admin']);
    $canView  = $canAdmin || $has(['dqa_view']);
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canView' => $canView,
            'uid' => $uid, 'name' => (string)($u['user_cname'] ?: $u['user_uname'])];
}

/* ============================================================
 * 設定（system_parameters）
 * ============================================================ */
function dqa_param_get(PDO $db, string $key, $default)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([DQA_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}
function dqa_param_save(PDO $db, string $key, $val, string $by = ''): void
{
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
    $st->execute([DQA_PARAM_GROUP, $key]);
    $rid = $st->fetchColumn();
    if ($rid) $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $by, $rid]);
    else      $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by)
                            VALUES (?,?,?,?,?)")->execute([DQA_PARAM_GROUP, $key, $json, '資料稽核：' . $key, $by]);
}

/**
 * 編碼原則（使用者 2026-09-18 定調）：英文兩碼 ＋ 流水號三碼 ＋ 可再加英文代碼一碼。
 * 做成可設定（鐵律4）：長度與要不要允許尾碼都能改，不寫死。
 */
function dqa_code_rule(PDO $db): array
{
    $d = ['letters' => 2, 'digits' => 3, 'suffix' => 1, 'allow_suffix' => 1, 'upper_only' => 1];
    $v = dqa_param_get($db, 'code_rule', []);
    if (!is_array($v)) $v = [];
    foreach ($d as $k => $dv) if (isset($v[$k]) && is_numeric($v[$k])) $d[$k] = (int)$v[$k];
    if ($d['letters'] < 1 || $d['letters'] > 5) $d['letters'] = 2;
    if ($d['digits']  < 1 || $d['digits']  > 8) $d['digits']  = 3;
    if ($d['suffix']  < 1 || $d['suffix']  > 3) $d['suffix']  = 1;
    return $d;
}
function dqa_code_regex(array $r): string
{
    $L = !empty($r['upper_only']) ? 'A-Z' : 'A-Za-z';
    $re = '/^[' . $L . ']{' . (int)$r['letters'] . '}\d{' . (int)$r['digits'] . '}';
    if (!empty($r['allow_suffix'])) $re .= '[' . $L . ']{0,' . (int)$r['suffix'] . '}';
    return $re . '$/';
}
function dqa_code_rule_text(array $r): string
{
    $t = '英文 ' . (int)$r['letters'] . ' 碼 ＋ 數字 ' . (int)$r['digits'] . ' 碼';
    if (!empty($r['allow_suffix'])) $t .= '（可再加英文 ' . (int)$r['suffix'] . ' 碼）';
    if (!empty($r['upper_only']))   $t .= '，英文限大寫';
    return $t;
}

/** 容差設定：數量與單價各自的容許誤差（%） */
function dqa_tolerance(PDO $db): array
{
    $v = dqa_param_get($db, 'tolerance', []);
    if (!is_array($v)) $v = [];
    $qty   = isset($v['qty_pct'])   && is_numeric($v['qty_pct'])   ? (float)$v['qty_pct']   : 0.0;
    $price = isset($v['price_pct']) && is_numeric($v['price_pct']) ? (float)$v['price_pct'] : 1.0;
    if ($qty   < 0 || $qty   > 100) $qty   = 0.0;
    if ($price < 0 || $price > 100) $price = 1.0;
    $days  = isset($v['quote_valid_days']) && is_numeric($v['quote_valid_days']) ? (int)$v['quote_valid_days'] : 365;
    if ($days < 0 || $days > 3650) $days = 365;
    /* 報價出去多久還沒有任何訂單才算「報價未成案」（分頁三用）。
       這一定要有寬限期——上禮拜才報的價還沒下單完全正常，報出來只是雜訊。 */
    $nod   = isset($v['no_order_days']) && is_numeric($v['no_order_days']) ? (int)$v['no_order_days'] : 30;
    if ($nod < 0 || $nod > 3650) $nod = 30;
    return ['qty_pct' => $qty, 'price_pct' => $price, 'quote_valid_days' => $days,
            'no_order_days' => $nod];
}

/* ============================================================
 * 例外（已核可不列為缺失）
 * ============================================================ */
function dqa_exempt_map(PDO $db, string $scope): array
{
    $out = [];
    try {
        $st = $db->prepare("SELECT target_key, item_code, reason, created_by_name,
                                   DATE_FORMAT(created_at,'%Y-%m-%d') d
                              FROM dqa_exempt WHERE scope=?");
        $st->execute([$scope]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $out[(string)$r['target_key']][(string)$r['item_code']] =
                ['reason' => (string)$r['reason'], 'by' => (string)$r['created_by_name'], 'at' => (string)$r['d']];
    } catch (Throwable $e) {}
    return $out;
}
function dqa_exempt_set(PDO $db, string $scope, string $key, string $item, string $reason, int $uid, string $uname): void
{
    $db->prepare("INSERT INTO dqa_exempt (scope,target_key,item_code,reason,created_by,created_by_name)
                  VALUES (?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE reason=VALUES(reason), created_by=VALUES(created_by),
                                          created_by_name=VALUES(created_by_name), created_at=NOW()")
       ->execute([$scope, $key, $item, mb_substr($reason, 0, 300), $uid, $uname]);
}
function dqa_exempt_del(PDO $db, string $scope, string $key, string $item): void
{
    $db->prepare("DELETE FROM dqa_exempt WHERE scope=? AND target_key=? AND item_code=?")->execute([$scope, $key, $item]);
}

/* ============================================================
 * 排除設定（2026-09-21 使用者交辦）
 *
 * 【為什麼要有】使用者回報：某個客戶都會先下未來單，所以**製令先開立、訂單事後才來綁**，
 * 流程順序稽核就會一直報「製令早於訂單」——那是這家客戶的正常作業方式，不是缺失。
 *
 * 【一條規則長什麼樣】維度（客戶／廠商／料號）＋值 ＋ 套用到哪幾個分頁 ＋（選填）只排除哪幾個檢核項目。
 *   ①維度決定它「能」套到哪些分頁（使用者要求「每個分頁適用的排除不同」）：
 *       客戶 → 流程順序稽核、基本資料稽核（客戶）
 *       廠商 → 基本資料稽核（廠商）        ※流程順序稽核不看廠商，所以不給選
 *       料號 → 流程順序稽核                ※基本資料稽核查的是客戶廠商主檔，沒有料號
 *     `dqa_excl_dims()` 是這張對照表的唯一登記處，畫面與後端驗證都讀它。
 *   ②項目留空＝整筆不納入稽核；有指定就只把那幾個檢核項目拿掉，其餘照驗
 *     （上面那個案例只要排掉「製令早於訂單」就好，不必整個客戶都不稽核）。
 *
 * 【與 dqa_exempt 的分工】dqa_exempt 是「這一筆的這一項已核可」的逐筆例外；
 * 這裡是「往後凡是這個客戶／廠商／料號都不要再報」的常設規則，兩者互補、不互相取代。
 * ============================================================ */
function dqa_excl_dims(): array
{
    return [
        'client' => ['label' => '客戶', 'tabs' => ['trace', 'customer'], 'master' => 'customer'],
        'maker'  => ['label' => '廠商', 'tabs' => ['maker'],             'master' => 'maker'],
        'part'   => ['label' => '料號', 'tabs' => ['trace'],             'master' => ''],
    ];
}
/** 分頁代碼 → 顯示名稱（trace＝分頁一；customer／maker＝分頁二的兩種對象） */
function dqa_excl_tabs(): array
{
    return ['trace' => '流程順序稽核', 'customer' => '基本資料稽核（客戶）', 'maker' => '基本資料稽核（廠商）'];
}

function dqa_excl_ensure(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS dqa_exclude (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dim VARCHAR(10) NOT NULL COMMENT 'client/maker/part，見 dqa_excl_dims()',
        val VARCHAR(120) NOT NULL COMMENT '客戶簡稱／廠商簡稱／料號',
        tabs VARCHAR(80) NOT NULL DEFAULT '' COMMENT '套用分頁，逗號分隔（trace/customer/maker）',
        items TEXT NULL COMMENT '只排除哪幾個檢核項目（JSON 陣列）；空＝整筆不稽核',
        reason VARCHAR(300) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        UNIQUE KEY uk_dqa_excl (dim, val)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='資料稽核：排除設定（整個客戶／廠商／料號不列為缺失）'");
}

/** 全部排除規則（畫面用；含停用的） */
function dqa_excl_list(PDO $db): array
{
    dqa_excl_ensure($db);
    $rows = $db->query("SELECT id, dim, val, tabs, items, reason, is_active, created_by_name,
                               DATE_FORMAT(created_at,'%Y-%m-%d') d
                          FROM dqa_exclude ORDER BY dim, val")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['tab_list']  = array_values(array_filter(explode(',', (string)$r['tabs'])));
        $r['item_list'] = json_decode((string)$r['items'], true) ?: [];
        $r['is_active'] = (int)$r['is_active'];
    }
    return $rows;
}

/**
 * 某個分頁要套用的排除規則，整理成好查的形式：
 *   [ 維度 => [ 正規化後的值 => ['all'=>bool, 'items'=>[代碼=>1], 'reason'=>, 'id'=>] ] ]
 * 值一律用 dqa_excl_norm() 正規化後比對（去空白、轉小寫），避免「和大 」比不中「和大」。
 */
function dqa_excl_map(PDO $db, string $tab): array
{
    // 同一個 request 內改過規則就要重讀：存檔後若還吃舊快取，會出現
    // 「規則存進去了、當下重算卻完全沒作用」這種看不出原因的情形（實測抓到）。
    if (dqa_excl_cache(null) === null) dqa_excl_cache([]);
    $cache = dqa_excl_cache(null);
    if (isset($cache[$tab])) return $cache[$tab];
    $out = [];
    foreach (dqa_excl_list($db) as $r) {
        if (!$r['is_active']) continue;
        if (!in_array($tab, $r['tab_list'], true)) continue;
        $items = [];
        foreach ($r['item_list'] as $c) $items[(string)$c] = 1;
        $out[(string)$r['dim']][dqa_excl_norm((string)$r['val'])] = [
            'all' => !$items, 'items' => $items,
            'reason' => (string)$r['reason'], 'id' => (int)$r['id'], 'val' => (string)$r['val'],
        ];
    }
    $cache[$tab] = $out;
    dqa_excl_cache($cache);
    return $out;
}
/** 排除規則的請求內快取；傳 null 取值、傳陣列設值、呼叫 dqa_excl_cache_clear() 清掉 */
function dqa_excl_cache($set = null)
{
    static $cache = null;
    if ($set !== null) $cache = $set;
    return $cache;
}
function dqa_excl_cache_clear(): void { dqa_excl_cache([]); }
function dqa_excl_norm(string $v): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', '', $v)));
}

/** 這一列命中了哪些排除規則（$vals: dim => 值） */
function dqa_excl_hit(array $map, array $vals): array
{
    $hit = [];
    foreach ($vals as $dim => $v) {
        $k = dqa_excl_norm((string)$v);
        if ($k !== '' && isset($map[$dim][$k])) $hit[] = $map[$dim][$k] + ['dim' => $dim];
    }
    return $hit;
}

function dqa_excl_save(PDO $db, array $d, int $uid, string $uname): array
{
    dqa_excl_ensure($db);
    $dims = dqa_excl_dims();
    $dim  = (string)($d['dim'] ?? '');
    if (!isset($dims[$dim])) throw new RuntimeException('不支援的排除維度');
    $val = trim((string)($d['val'] ?? ''));
    if ($val === '') throw new RuntimeException('請指定要排除的客戶／廠商／料號');
    if (mb_strlen($val) > 120) throw new RuntimeException('排除的值太長');

    // 分頁只能是這個維度本來就適用的那幾個（鐵律8：前端擋一次、後端同規則再擋一次）
    $tabs = [];
    foreach ((array)($d['tabs'] ?? []) as $t) {
        $t = (string)$t;
        if (in_array($t, $dims[$dim]['tabs'], true) && !in_array($t, $tabs, true)) $tabs[] = $t;
    }
    if (!$tabs) throw new RuntimeException('請至少勾選一個要套用的分頁');

    // 檢核項目：流程順序稽核才有項目可挑；基本資料稽核的欄位缺失不分項（整筆排除）
    $allowItems = array_keys(dqa_trace_items());
    $items = [];
    foreach ((array)($d['items'] ?? []) as $c) {
        $c = (string)$c;
        if (in_array($c, $allowItems, true) && !in_array($c, $items, true)) $items[] = $c;
    }
    if ($items && !in_array('trace', $tabs, true)) $items = [];   // 沒套到分頁一就沒有項目可言

    $st = $db->prepare("INSERT INTO dqa_exclude (dim,val,tabs,items,reason,is_active,created_by,created_by_name)
                        VALUES (?,?,?,?,?,1,?,?)
                        ON DUPLICATE KEY UPDATE tabs=VALUES(tabs), items=VALUES(items),
                                                reason=VALUES(reason), is_active=1,
                                                created_by=VALUES(created_by),
                                                created_by_name=VALUES(created_by_name), created_at=NOW()");
    $st->execute([$dim, $val, implode(',', $tabs), $items ? json_encode($items) : null,
                  mb_substr((string)($d['reason'] ?? ''), 0, 300), $uid, $uname]);
    dqa_excl_cache_clear();
    return ['dim' => $dim, 'val' => $val, 'tabs' => $tabs, 'items' => $items];
}

function dqa_excl_set_active(PDO $db, int $id, bool $on): void
{
    dqa_excl_ensure($db);
    $db->prepare("UPDATE dqa_exclude SET is_active=? WHERE id=?")->execute([$on ? 1 : 0, $id]);
    dqa_excl_cache_clear();
}
function dqa_excl_del(PDO $db, int $id): void
{
    dqa_excl_ensure($db);
    $db->prepare("DELETE FROM dqa_exclude WHERE id=?")->execute([$id]);
    dqa_excl_cache_clear();
}

/**
 * 排除設定的值要讓人用挑的，不能只讓人打字（打錯一個字這條規則就永遠不會命中，
 * 而且完全不報錯）。客戶／廠商查主檔，料號查 d_setting。
 */
function dqa_excl_search(PDO $db, string $dim, string $kw, int $limit = 30): array
{
    $kw = trim($kw);
    if ($kw === '') return [];
    $like = '%' . $kw . '%';
    $out = [];
    try {
        if ($dim === 'client') {
            $st = $db->prepare("SELECT customer AS val, customer_id AS code FROM customer_list
                                 WHERE COALESCE(is_inactive,0)=0 AND (customer LIKE ? OR customer_id LIKE ?)
                                 ORDER BY customer LIMIT $limit");
            $st->execute([$like, $like]);
        } elseif ($dim === 'maker') {
            $st = $db->prepare("SELECT maker_id AS val, maker_id_no AS code FROM maker_list
                                 WHERE COALESCE(status,'')<>'X' AND (maker_id LIKE ? OR maker_id_no LIKE ?)
                                 ORDER BY maker_id LIMIT $limit");
            $st->execute([$like, $like]);
        } elseif ($dim === 'part') {
            $st = $db->prepare("SELECT DISTINCT D_Setting_Id AS val, '' AS code FROM d_setting
                                 WHERE D_Setting_Id LIKE ? ORDER BY D_Setting_Id LIMIT $limit");
            $st->execute([$like]);
        } else {
            return [];
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = trim((string)$r['val']);
            if ($v === '') continue;
            $out[] = ['val' => $v, 'code' => trim((string)$r['code'])];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* ============================================================
 * 稽核對象：綁定的 AS 表單編號（多選）
 * ============================================================ */
function dqa_scope_tabs(): array
{
    return ['trace' => '流程順序稽核', 'master' => '基本資料稽核'];
}
function dqa_scope_docs(PDO $db, string $tab): array
{
    $v = dqa_param_get($db, 'scope_' . $tab, []);
    $out = [];
    foreach (is_array($v) ? $v : [] as $x) { $i = (int)$x; if ($i > 0) $out[] = $i; }
    return array_values(array_unique($out));
}
function dqa_scope_docs_info(PDO $db, string $tab): array
{
    $ids = dqa_scope_docs($db, $tab);
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $db->prepare("SELECT id, doc_no, doc_name FROM as_document WHERE id IN ($in) ORDER BY doc_no");
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
function dqa_scope_save(PDO $db, string $tab, array $ids, string $by = ''): array
{
    $clean = [];
    foreach ($ids as $x) { $i = (int)$x; if ($i > 0) $clean[$i] = 1; }
    $clean = array_keys($clean);
    if ($clean) {   // 後端再驗一次：文件必須真的存在（鐵律8）
        $in = implode(',', array_fill(0, count($clean), '?'));
        $st = $db->prepare("SELECT id FROM as_document WHERE id IN ($in) AND is_deleted=0");
        $st->execute($clean);
        $clean = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    dqa_param_save($db, 'scope_' . $tab, $clean, $by);
    return $clean;
}

/* ============================================================
 * 製程正規化
 *   四個節點的製程寫法完全不同，所以一律拆成「製程關鍵詞集合」再比交集：
 *     報價單  quotation_item.process_notes（存子標籤 id）→ sub_tag_name
 *     訂單    order_track.Processing_items（**手打自由文字**，如「齒研+雷刻」「代料完成」）
 *     製令    bom_ing.process_no → process_no.ProcessName
 *     出貨單  is_list.Specification（ERP 轉出時製程與規格混打在同一欄）
 *   詞庫一律從 process_no 主檔與報價子標籤即時取得，不寫死清單（鐵律4）。
 * ============================================================ */
function dqa_process_terms(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $terms = [];
    try {
        foreach ($db->query("SELECT ProcessName FROM process_no WHERE ProcessName IS NOT NULL AND ProcessName<>''")
                    ->fetchAll(PDO::FETCH_COLUMN) as $n) $terms[trim((string)$n)] = 1;
    } catch (Throwable $e) {}
    try {
        foreach ($db->query("SELECT sub_tag_name FROM quotation_process_sub_tag WHERE is_active=1")
                    ->fetchAll(PDO::FETCH_COLUMN) as $n) {
            // 子標籤常寫成「全製 (齒研)」，括號內外都當成詞
            foreach (preg_split('/[()（）\s]+/u', (string)$n) as $p) {
                $p = trim($p);
                if ($p !== '' && mb_strlen($p) >= 2) $terms[$p] = 1;
            }
        }
    } catch (Throwable $e) {}
    $out = array_keys($terms);
    // 長詞優先比對，避免「滾齒」先吃掉「精滾齒」
    usort($out, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    return $cache = $out;
}

/** 從一段文字抽出製程詞（回傳去重後的陣列） */
function dqa_proc_extract(?string $text, array $terms): array
{
    $t = trim((string)$text);
    if ($t === '') return [];
    $hit = [];
    $rest = $t;
    foreach ($terms as $term) {
        if ($term === '') continue;
        if (mb_strpos($rest, $term) !== false) {
            $hit[$term] = 1;
            $rest = str_replace($term, '　', $rest);   // 比中的詞挖掉，長詞才不會被短詞重複命中
        }
    }
    return array_keys($hit);
}

/** 報價項目的製程名稱（quotation_item.process_notes 存的是子標籤 id 清單，不是文字）
 *  既有資料有純數字、有陣列、也有物件三種寫法，直接 foreach 解碼結果會在「單一數字」時炸掉
 *  （json_decode 回 int），所以一律先正規化成陣列。唯一實作，顯示與比對共用。 */
function dqa_quote_proc_names(?string $processNotes, array $subTagName): array
{
    $pn = json_decode((string)$processNotes, true);
    if (is_numeric($pn)) $pn = [$pn];
    if (!is_array($pn))  $pn = [];
    $out = [];
    foreach ($pn as $x) {
        $id = is_array($x) ? (int)($x['sub_tag_id'] ?? 0) : (int)$x;
        if ($id > 0 && isset($subTagName[$id]) && $subTagName[$id] !== '') $out[$subTagName[$id]] = 1;
    }
    return array_keys($out);
}

/** 製程集合比對：回傳 same / partial / diff / unknown（任一邊沒抽出詞＝unknown，不判不符） */
function dqa_proc_compare(array $a, array $b): string
{
    if (!$a || !$b) return 'unknown';
    sort($a); sort($b);
    if ($a === $b) return 'same';
    return array_intersect($a, $b) ? 'partial' : 'diff';
}

/* ============================================================
 * 小工具
 * ============================================================ */
function dqa_d(?string $v): string
{
    $s = substr(trim((string)$v), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && $s !== '0000-00-00' ? $s : '';
}
function dqa_num($v): float { return is_numeric($v) ? (float)$v : 0.0; }
/** 兩個數值差異是否超過容許百分比（基準為 0 時，另一邊不是 0 就算不符） */
function dqa_diff_over(float $a, float $b, float $pct): bool
{
    if ($a == 0.0 && $b == 0.0) return false;
    $base = max(abs($a), abs($b));
    if ($base == 0.0) return false;
    return (abs($a - $b) / $base) * 100.0 > $pct + 1e-9;
}
/**
 * 製令的「開立日期」：優先用 BOM 編號回推（B-民國年3碼+MM+DD+流水），回推不到才用 Created_At。
 * 為什麼不直接用 Created_At：BOM 多半由 ERP 匯入，Created_At 是「匯入這台系統的時間」，
 * 不是現場實際開立製令的日期，拿它跟訂單日比會出現一整批假的「製令早於訂單」。
 */
function dqa_bom_open_date(string $bomNo, ?string $createdAt): string
{
    if (preg_match('/^[A-Za-z]-(\d{3})(\d{2})(\d{2})\d*$/', trim($bomNo), $m)) {
        $y = (int)$m[1] + 1911; $mo = (int)$m[2]; $d = (int)$m[3];
        if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && checkdate($mo, $d, $y))
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    return dqa_d($createdAt);
}

/**
 * 推測配對的鍵：**料號主鍵優先，客戶＋料號文字只是回退**。
 *
 * 為什麼不能只用「客戶名稱＋料號文字」（實測數字）：
 *   ①報價與訂單的客戶名稱寫法不一致——報價寫「崴立(后里)」訂單寫「崴立」，
 *     814 個報價客戶名稱只有 214 個對得上訂單（26%）。
 *   ②料號文字會重複（全站 1,670 組同名料號分屬不同客戶），只比文字會跨客戶亂配。
 * 而料號主鍵（d_setting）本來就綁定了客戶，所以主鍵對得上時根本不必比客戶名稱。
 * 填充率：quotation_item 99.4%、is_list 100%、bom 2026 年 84%、order_track 44%。
 */
function dqa_pair_keys(int $did, string $client, string $part): array
{
    $k = [];
    if ($did > 0) $k[] = 'D:' . $did;
    $p = trim($part);
    if ($p !== '') $k[] = 'N:' . trim($client) . '|' . $p;
    return $k;
}

/** 依多個鍵取推測候選並去重（同一列可能同時登記在主鍵與名稱兩個鍵下），依日期排序 */
function dqa_pick_guess(array $map, array $keys): array
{
    $out = []; $seen = [];
    foreach ($keys as $k) foreach ($map[$k] ?? [] as $r) {
        $id = (string)($r['_id'] ?? '');
        if ($id !== '') { if (isset($seen[$id])) continue; $seen[$id] = 1; }
        $out[] = $r;
    }
    usort($out, function ($a, $b) { return strcmp((string)($a['_sort'] ?? ''), (string)($b['_sort'] ?? '')); });
    return $out;
}

/** IN 子句用的分批（一次塞太多參數會超過 MySQL 上限） */
function dqa_chunks(array $ids, int $size = 800): array
{
    return $ids ? array_chunk(array_values(array_unique($ids)), $size) : [];
}

/* ============================================================
 * 分頁一：流程順序稽核（報價 → 訂單 → 製令 → 出貨）
 *
 * $f: from / to（訂單日期區間，必填）、client、part、cmp_process（1=比對製程）、
 *     only_bad（1=只留有異常的）、limit（保護上限，預設 3000）
 * ============================================================ */
function dqa_trace_rows(PDO $db, array $f): array
{
    $from = dqa_d($f['from'] ?? '') ?: date('Y-01-01');
    $to   = dqa_d($f['to']   ?? '') ?: date('Y-12-31');
    $client = trim((string)($f['client'] ?? ''));
    $part   = trim((string)($f['part'] ?? ''));
    $cmpP   = !empty($f['cmp_process']);
    $onlyBad = !empty($f['only_bad']);
    $lim    = (int)($f['limit'] ?? 3000);
    if ($lim < 1 || $lim > 20000) $lim = 3000;
    $tol    = dqa_tolerance($db);
    $validDays = (int)$tol['quote_valid_days'];
    $itemLv = dqa_trace_levels($db);
    $exclMap = dqa_excl_map($db, 'trace');      // 排除設定（客戶／料號）
    $exclStat = [];                             // 被排除掉的統計，畫面要講出來
    $exempt = dqa_exempt_map($db, 'trace');

    /* ── ① 訂單（稽核主軸：一張訂單一列）─────────────────
     * order_ids 是「綁定之後只重算這幾列」用的（見 dqa_trace_one()）：
     * 指定之後日期與客戶料號篩選一律不套用，不然剛綁完的那張訂單可能因為
     * 不在目前的篩選範圍內而整列消失，畫面上看起來像綁定把資料弄丟了。 */
    $only = [];
    foreach ((array)($f['order_ids'] ?? []) as $v) { $v = (int)$v; if ($v > 0) $only[] = $v; }
    $only = array_slice(array_values(array_unique($only)), 0, 200);
    if ($only) {
        $ph = []; $p = [];
        foreach ($only as $i => $v) { $ph[] = ':o' . $i; $p[':o' . $i] = $v; }
        $w = ["ot.Order_id IN (" . implode(',', $ph) . ")"];
    } else {
    $w = ["ot.Order_date >= :f", "ot.Order_date <= :t"];
    $p = [':f' => $from, ':t' => $to];
    if ($client !== '') { $w[] = "ot.Client_name = :c"; $p[':c'] = $client; }
    if ($part   !== '') { $w[] = "ot.d_id LIKE :pt";    $p[':pt'] = '%' . $part . '%'; }
    }
    $sql = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.d_id, ot.d_id_ID, ot.Qty, ot.unit_price,
                   ot.Processing_items, ot.Order_ps, ot.Specification AS ospec,
                   ot.pmGet_auto, ot.Order_status, ot.quote_no, ot.quote_item_id,
                   ot.parent_order_id, ot.assembly_parent_order_id,
                   DATE_FORMAT(ot.Order_date,'%Y-%m-%d') odate,
                   DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') ddate
              FROM order_track ot
             WHERE " . implode(' AND ', $w) . "
             ORDER BY ot.Order_date DESC, ot.Order_id DESC
             LIMIT :lim";
    $cnt = $db->prepare("SELECT COUNT(*) FROM order_track ot WHERE " . implode(' AND ', $w));
    foreach ($p as $k => $v) $cnt->bindValue($k, $v);
    $cnt->execute();
    $orderTotal = (int)$cnt->fetchColumn();

    $st = $db->prepare($sql);
    foreach ($p as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $lim, PDO::PARAM_INT);
    $st->execute();
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$orders) return ['rows' => [], 'total' => 0, 'stat' => dqa_trace_stat([]),
                          'truncated' => false, 'scanned' => 0, 'order_total' => 0, 'limit' => $lim];

    $oids = array_map(function ($o) { return (int)$o['Order_id']; }, $orders);
    /* 推測配對的兩個鍵（見 dqa_pair_keys 的說明：料號主鍵優先、名稱只是回退） */
    $dids = []; $parts = []; $needGuess = [];
    foreach ($orders as $o) {
        if ((int)$o['d_id_ID'] > 0) $dids[] = (int)$o['d_id_ID'];
        $pt = trim((string)$o['d_id']);
        if ($pt !== '') $parts[] = $pt;
    }
    /* 推測用的日期窗：只用於「推測」，綁定的資料不受影響。
       放寬 180 天是為了讓「報價晚於訂單」「製令早於訂單」這類異常也查得到。 */
    $wideFrom = date('Y-m-d', strtotime($from . ' -180 day'));
    $wideTo   = date('Y-m-d', strtotime($to   . ' +180 day'));

    /* ── ② 報價：綁定（order_quote_map，多對多）──────────
     * 2026-09-21 起一張訂單可以綁多個報價項目（本體一列、治具／刀具一列），
     * 所以這裡要整批讀分配表；order_track.quote_item_id 只是「主要報價」快取，
     * tc_order_quote_map() 已經處理「還沒搬進分配表的舊資料」的回退。 */
    $qLinks = tc_order_quote_map($db, $oids);          // order_id => [ [item_id, tier_id, alloc, src], ... ]
    /* 綁到哪一階（階梯報價一列有好幾個價格，整列綁下去核對不出是依哪一階下的） */
    $tierIds = [];
    foreach ($qLinks as $rowsQ) foreach ($rowsQ as $lk) if ((int)($lk['tier_id'] ?? 0) > 0) $tierIds[] = (int)$lk['tier_id'];
    $tierInfo = [];
    foreach (dqa_chunks(array_values(array_unique($tierIds))) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT tier_id, qty_min, qty_max, unit_price FROM quotation_item_tier
                            WHERE tier_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mn = dqa_num($r['qty_min']); $mx = $r['qty_max'] === null ? null : dqa_num($r['qty_max']);
            $tierInfo[(int)$r['tier_id']] = ['min' => $mn, 'max' => $mx, 'price' => dqa_num($r['unit_price']),
                'range' => dqa_n($mn) . ' ~ ' . ($mx === null ? '以上' : dqa_n($mx))];
        }
    }
    $qBind = [];
    $qids = [];
    foreach ($qLinks as $rowsQ) foreach ($rowsQ as $lk) $qids[] = (int)$lk['item_id'];
    foreach (dqa_chunks($qids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT qi.item_id, qi.product_id, qi.d_setting_d_id, qi.quantity, qi.unit_price,
                                  qi.amount, qi.specification, COALESCE(qi.note_only,0) AS note_only,
                                  qi.process_notes, COALESCE(qi.is_tiered,0) AS is_tiered,
                                  ql.quote_no, ql.client_name,
                                  DATE_FORMAT(ql.quote_date,'%Y-%m-%d') qdate
                             FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                            WHERE qi.item_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $qBind[(int)$r['item_id']] = $r;
    }

    /* ── ③ 報價：推測 ─────────────────────────────────
     * ⚠ 不可以用「客戶名稱相等」當主要配對鍵：報價寫「崴立(后里)」、訂單寫「崴立」，
     *   實測 814 個報價客戶名稱只有 214 個能對上訂單（26%），用名稱會有一大半查不到報價。
     *   所以主要鍵是**料號主鍵**（quotation_item.d_setting_d_id 填充率 99.4%），
     *   料號主鍵本來就綁定了客戶，不必再比客戶名稱。訂單沒有 d_id_ID 時才退回名稱比對。
     */
    $qGuess = [];
    $pTot = count(array_unique($parts));
    if ($dids || $parts) {
        $w = []; $bind = [];
        if ($dids) { $u = array_values(array_unique($dids));
            $w[] = "qi.d_setting_d_id IN (" . implode(',', array_fill(0, count($u), '?')) . ")";
            $bind = array_merge($bind, $u); }
        if ($parts) { $u = array_values(array_unique($parts));
            $w[] = "qi.product_id IN (" . implode(',', array_fill(0, count($u), '?')) . ")";
            $bind = array_merge($bind, $u); }
        $s = $db->prepare("SELECT qi.item_id, qi.product_id, qi.d_setting_d_id, qi.quantity, qi.unit_price,
                                  qi.amount, qi.specification, COALESCE(qi.note_only,0) AS note_only,
                                  qi.process_notes, COALESCE(qi.is_tiered,0) AS is_tiered,
                                  ql.quote_no, ql.client_name,
                                  DATE_FORMAT(ql.quote_date,'%Y-%m-%d') qdate
                             FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                            WHERE (" . implode(' OR ', $w) . ")
                              AND COALESCE(ql.is_draft,0)=0
                              AND COALESCE(qi.note_only,0)=0
                              AND ql.quote_date <= ?
                            ORDER BY ql.quote_date");
        $bind[] = $wideTo;
        $s->execute($bind);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['_id'] = 'q' . (int)$r['item_id'];
            $r['_sort'] = dqa_d($r['qdate']);
            foreach (dqa_pair_keys((int)$r['d_setting_d_id'], (string)$r['client_name'], (string)$r['product_id']) as $k)
                $qGuess[$k][] = $r;
        }
    }

    /* ── ④ 製令：綁定（分配表）→ legacy（bom.o_order_id）→ 推測 ── */
    $bomByOrder = [];   // order_id → [bom 列]
    $bomSeen    = [];   // bom 編號 → 1（製程要用）
    /* 一定要帶「這張製令自己的」客戶、料號與完工狀態：畫面上的連結是用節點自己的值去別頁篩選，
       拿訂單的值去篩會篩出 0 筆；完工狀態則決定要連到哪一頁——
       實測 BOM 總表只列未完工的製令，已完工的在那裡一列都查不到。 */
    $mkBom = function (array $r, string $src) use (&$bomSeen) {
        $no = (string)$r['bom'];
        $bomSeen[$no] = 1;
        $d = dqa_bom_open_date($no, $r['created'] ?? null);
        return ['bom' => $no, 'qty' => dqa_num($r['sqty']), 'src' => $src, 'date' => $d,
                'alloc' => isset($r['alloc']) ? dqa_num($r['alloc']) : null,
                'client' => trim((string)($r['Client_Name'] ?? '')),
                'part'   => trim((string)($r['d_id'] ?? '')),
                'done'   => ((string)($r['processing_state'] ?? '') === '1'),
                '_id' => 'b' . $no, '_sort' => $d];
    };
    foreach (dqa_chunks($oids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT m.order_id, m.allocated_qty alloc, b.bom, b.sqty, b.d_id,
                                  b.Client_Name, b.processing_state,
                                  DATE_FORMAT(b.Created_At,'%Y-%m-%d') created
                             FROM bom_order_process_map m JOIN bom b ON b.bom=m.bom
                            WHERE m.order_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $bomByOrder[(int)$r['order_id']][(string)$r['bom']] = $mkBom($r, 'map');
        $s = $db->prepare("SELECT b.o_order_id, b.bom, b.sqty, b.d_id, b.Client_Name, b.processing_state,
                                  DATE_FORMAT(b.Created_At,'%Y-%m-%d') created
                             FROM bom b WHERE b.o_order_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oid = (int)$r['o_order_id'];
            if (isset($bomByOrder[$oid][(string)$r['bom']])) continue;   // 已有分配列就不重複
            $bomByOrder[$oid][(string)$r['bom']] = $mkBom($r, 'legacy');
        }
    }
    $bomGuess = [];
    if ($dids || $parts) {
        $w = []; $bind = [];
        if ($dids) { $u = array_values(array_unique($dids));
            $w[] = "b.d_setting_id IN (" . implode(',', array_fill(0, count($u), '?')) . ")";
            $bind = array_merge($bind, $u); }
        if ($parts) { $u = array_values(array_unique($parts));
            $w[] = "b.d_id IN (" . implode(',', array_fill(0, count($u), '?')) . ")";
            $bind = array_merge($bind, $u); }
        $s = $db->prepare("SELECT b.bom, b.sqty, b.d_id, b.d_setting_id, b.Client_Name, b.processing_state,
                                  DATE_FORMAT(b.Created_At,'%Y-%m-%d') created
                             FROM bom b WHERE (" . implode(' OR ', $w) . ")
                              AND b.Created_At >= ? ORDER BY b.bom");
        $bind[] = $wideFrom;
        $s->execute($bind);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = $mkBom($r, 'guess');
            foreach (dqa_pair_keys((int)$r['d_setting_id'], (string)$r['Client_Name'], (string)$r['d_id']) as $k)
                $bomGuess[$k][] = $row;
        }
    }

    /* ── ⑤ 出貨：綁定（is_order_map）→ legacy（is_list.Order_id）→ 推測 ── */
    $shipByOrder = [];
    $mkShip = function (array $r, string $src) {
        $d = dqa_d($r['sdate'] ?? null);
        return ['id' => (int)$r['IS_id'], 'no' => (string)$r['IS_number'], 'date' => $d,
                'qty' => dqa_num($r['Qty']), 'price' => dqa_num($r['Unit_price']),
                'ok_flag' => ((int)($r['anomaly_confirmed'] ?? 0) === 1),
                'spec' => (string)($r['Specification'] ?? ''), 'src' => $src,
                'content' => (string)($r['Content'] ?? ''), 'note' => (string)($r['Note'] ?? ''),
                'alloc' => isset($r['alloc']) ? dqa_num($r['alloc']) : null,
                'client' => trim((string)($r['Client_name'] ?? '')),
                'part'   => trim((string)($r['Product_id'] ?? '')),
                '_id' => 's' . (int)$r['IS_id'], '_sort' => $d];
    };
    foreach (dqa_chunks($oids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT m.Order_id, m.allocated_qty alloc, il.IS_id, il.IS_number, il.Qty,
                                  il.Unit_price, il.Specification, il.Content, il.Note,
                                  il.anomaly_confirmed, il.Client_name, il.Product_id,
                                  DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate
                             FROM is_order_map m JOIN is_list il ON il.IS_id=m.IS_id
                            WHERE m.Order_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $shipByOrder[(int)$r['Order_id']][(int)$r['IS_id']] = $mkShip($r, 'map');
        $s = $db->prepare("SELECT il.Order_id, il.IS_id, il.IS_number, il.Qty, il.Unit_price,
                                  il.Specification, il.Content, il.Note, il.anomaly_confirmed,
                                  il.Client_name, il.Product_id, DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate
                             FROM is_list il WHERE il.Order_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oid = (int)$r['Order_id'];
            if (isset($shipByOrder[$oid][(int)$r['IS_id']])) continue;
            $shipByOrder[$oid][(int)$r['IS_id']] = $mkShip($r, 'legacy');
        }
    }
    $shipGuess = [];
    if ($dids || $parts) {
        $w = []; $bind = [];
        if ($dids) { $u = array_values(array_unique($dids));
            $w[] = "il.d_setting_id IN (" . implode(',', array_fill(0, count($u), '?')) . ")";
            $bind = array_merge($bind, $u); }
        if ($parts) { $u = array_values(array_unique($parts));
            $w[] = "il.Product_id IN (" . implode(',', array_fill(0, count($u), '?')) . ")";
            $bind = array_merge($bind, $u); }
        $s = $db->prepare("SELECT il.IS_id, il.IS_number, il.Qty, il.Unit_price,
                                  il.Specification, il.Content, il.Note, il.anomaly_confirmed,
                                  il.Client_name, il.Product_id, il.d_setting_id,
                                  DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate
                             FROM is_list il WHERE (" . implode(' OR ', $w) . ")
                              AND il.Order_date >= ? ORDER BY il.Order_date");
        $bind[] = $wideFrom;
        $s->execute($bind);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = $mkShip($r, 'guess');
            foreach (dqa_pair_keys((int)$r['d_setting_id'], (string)$r['Client_name'], (string)$r['Product_id']) as $k)
                $shipGuess[$k][] = $row;
        }
    }

    /* ── ⑤-2 出貨有沒有進對帳／開發票（2026-09-21 使用者交辦）──
     * 這一項預設是關的：會計模組目前發票明細 0 筆、對帳底稿只有 84 筆，全開會整片報未收款。
     * 所以只有管理員在「設定」把它打開時才真的去查，關著的時候一次查詢都不會發生。 */
    $shipRecon = [];
    if (($itemLv['ship_norecon'] ?? 'off') !== 'off') {
        $allIs = [];
        foreach ($shipByOrder as $m) foreach ($m as $s2) $allIs[] = (int)$s2['id'];
        foreach (dqa_chunks($allIs) as $ck) {
            $in = implode(',', array_fill(0, count($ck), '?'));
            foreach (["SELECT src_id FROM acc_recon_line WHERE src_type='IS' AND src_id IN ($in)",
                      "SELECT src_id FROM acc_invoice_item WHERE src_type IN ('IS','ship') AND src_id IN ($in)"] as $q2) {
                try {
                    $s = $db->prepare($q2); $s->execute($ck);
                    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $v) $shipRecon[(int)$v] = 1;
                } catch (Throwable $e) {}   // 會計模組的表還沒建起來時不要讓整份稽核掛掉
            }
        }
    }

    /* ── ⑥ 製令的製程（bom_ing → process_no）─────────────
     * 2026-09-21 使用者回報：四個節點都看不到製程，沒辦法確認「是不是同一種製程」。
     * 所以製程一律載入**給畫面看**，不再只在「製程比對」打開時才查——
     * 那個比對預設是關的（訂單手打、出貨與規格混打，自動比對 31% 對不起來會淹沒真正的缺失），
     * 但「把四個節點的製程並排印出來讓人自己核對」本來就是這一頁的用途。
     * 順序一律用 bom_sn（記憶 bom_process_order_is_bom_sn：processing_sequence 多數是 NULL，
     * 拿它排序會把有值的那一站排到最前面）。 */
    $bomProc = [];
    if ($bomSeen) {
        foreach (dqa_chunks(array_keys($bomSeen)) as $ck) {
            $in = implode(',', array_fill(0, count($ck), '?'));
            $s = $db->prepare("SELECT bi.bom, pn.ProcessName FROM bom_ing bi
                                 LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                                WHERE bi.bom IN ($in) AND pn.ProcessName IS NOT NULL
                                ORDER BY bi.bom, bi.bom_sn");
            $s->execute($ck);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nm = trim((string)$r['ProcessName']);
                if ($nm === '') continue;
                $bomProc[(string)$r['bom']][$nm] = 1;   // 同一站重複發包只留一個
            }
        }
    }
    /* 報價的製程（process_notes 存子標籤 id 清單，不是文字＝記憶 quotation_process_tags） */
    $subTagName = [];
    try {
        foreach ($db->query("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r)
            $subTagName[(int)$r['sub_tag_id']] = trim((string)$r['sub_tag_name']);
    } catch (Throwable $e) {}
    $terms = $cmpP ? dqa_process_terms($db) : [];

    /* ── ⑦ 逐張訂單判定 ───────────────────────────────── */
    $rows = [];
    foreach ($orders as $o) {
        $oid   = (int)$o['Order_id'];
        $pkeys = dqa_pair_keys((int)$o['d_id_ID'], (string)$o['Client_name'], (string)$o['d_id']);
        $odate = (string)$o['odate'];
        $oqty  = dqa_num($o['Qty']);
        $oprice = dqa_num($o['unit_price']);
        $autoPm = ((int)$o['pmGet_auto'] === 1);
        $closed = ((string)$o['Order_status'] === '9');

        /* 報價
         * $qAll ＝這張訂單綁到的全部報價項目（本體＋治具…），$q ＝其中的「主要報價」
         * （料號相同者優先，與 tc_order_quote_main() 同一條規則；日期、單價、製程都以它為準）。
         * 沒有任何綁定時才退回推測，推測一律只取一筆。 */
        $q = null; $qSrc = ''; $qAll = []; $qAlloc = 0.0;
        foreach ($qLinks[$oid] ?? [] as $lk) {
            $it = $qBind[(int)$lk['item_id']] ?? null;
            if (!$it) continue;                       // 報價項目已被刪除（報價單改版）
            $it['_alloc'] = (float)$lk['alloc'];
            $it['_link_src'] = $lk['src'];
            $it['_tier'] = $tierInfo[(int)($lk['tier_id'] ?? 0)] ?? null;
            $qAll[] = $it;
            $qAlloc += (float)$lk['alloc'];
        }
        if ($qAll) {
            $pt = strtolower(trim((string)$o['d_id']));
            usort($qAll, function ($x, $y) use ($pt) {
                $mx = (strtolower(trim((string)$x['product_id'])) === $pt) ? 0 : 1;
                $my = (strtolower(trim((string)$y['product_id'])) === $pt) ? 0 : 1;
                if ($mx !== $my) return $mx - $my;
                if ($x['_alloc'] != $y['_alloc']) return ($y['_alloc'] <=> $x['_alloc']);
                return ((int)$x['item_id'] <=> (int)$y['item_id']);
            });
            $q = $qAll[0]; $qSrc = 'bind';
        }
        if (!$q) {
            // 推測：取報價日不晚於訂單日之中最接近的一筆；全都晚於訂單日就取最早那筆（好讓「報價晚於訂單」被看見）
            $cands = dqa_pick_guess($qGuess, $pkeys);
            foreach ($cands as $cand) {
                $cd = dqa_d($cand['qdate']);
                if ($cd !== '' && $cd <= $odate) $q = $cand;
            }
            if (!$q && $cands) $q = $cands[0];
            if ($q) {
                $qSrc = 'guess';
                $q['_alloc'] = 0.0; $q['_link_src'] = 'guess';
                $qAll = [$q];
            }
        }

        /* 製令
         * 推測時**只取訂單日之後最接近的那一張**。
         * 實測教訓：同一支料號長期重複下單，把歷年製令全抓進來會變成
         * 「製令 9 張、合計 7,922 支 vs 訂單 600 支、首張還早於訂單一年」——
         * 那不是這張訂單的製令，是同料號別張訂單的，硬報就是滿畫面假警報。 */
        $boms = [];
        if (!empty($bomByOrder[$oid])) { $boms = array_values($bomByOrder[$oid]); }
        else {
            foreach (dqa_pick_guess($bomGuess, $pkeys) as $cand)
                if ($cand['date'] !== '' && $cand['date'] >= $odate) { $boms[] = $cand; break; }
        }
        $bomSrc = $boms ? $boms[0]['src'] : '';
        $bQty = 0.0; $bMin = ''; $bMax = ''; $bList = [];
        foreach ($boms as $b) {
            $q1 = ($b['alloc'] !== null ? $b['alloc'] : $b['qty']);
            $bQty += $q1;
            if ($b['date'] !== '') {
                if ($bMin === '' || $b['date'] < $bMin) $bMin = $b['date'];
                if ($bMax === '' || $b['date'] > $bMax) $bMax = $b['date'];
            }
            if (count($bList) < 12) $bList[] = ['no' => $b['bom'], 'date' => $b['date'], 'qty' => $q1,
                                                'client' => (string)($b['client'] ?? ''),
                                                'part' => (string)($b['part'] ?? ''),
                                                'procs' => array_keys($bomProc[(string)$b['bom']] ?? []),
                                                'done' => !empty($b['done'])];
        }

        /* 出貨 */
        $ships = [];
        if (!empty($shipByOrder[$oid])) { $ships = array_values($shipByOrder[$oid]); }
        else {
            // 推測只取「訂單日之後」的出貨，而且累加到滿足訂單數量就停——
            // 再往後的出貨是同料號下一張訂單的貨，抓進來數量與單價都會對不起來
            $acc = 0.0;
            foreach (dqa_pick_guess($shipGuess, $pkeys) as $cand) {
                if ($cand['date'] === '' || $cand['date'] < $odate) continue;
                $ships[] = $cand;
                $acc += $cand['qty'];
                if ($acc >= $oqty && $oqty > 0) break;
                if (count($ships) >= 8) break;
            }
        }
        $shipSrc = $ships ? $ships[0]['src'] : '';
        $sQty = 0.0; $sMin = ''; $sMax = ''; $sPrice = null; $sSpec = ''; $sDocs = [];
        foreach ($ships as $s2) {
            $q1 = ($s2['alloc'] !== null ? $s2['alloc'] : $s2['qty']);
            $sQty += $q1;
            if ($s2['date'] !== '') {
                if ($sMin === '' || $s2['date'] < $sMin) $sMin = $s2['date'];
                if ($sMax === '' || $s2['date'] > $sMax) $sMax = $s2['date'];
            }
            if ($sPrice === null && $s2['price'] > 0) $sPrice = $s2['price'];
            if ($sSpec === '' && $s2['spec'] !== '')  $sSpec = $s2['spec'];
            // 一張出貨單在 is_list 是好幾個明細列（IS_id 不同、IS_number 相同），
            // 畫面上要給人點的是「單號」，所以依單號合併、數量加總，不然會印出三個一樣的單號
            $k = (string)$s2['no'];
            if ($k === '') continue;
            if (!isset($sDocs[$k])) $sDocs[$k] = ['no' => $k, 'date' => $s2['date'], 'qty' => 0.0,
                                                  'client' => (string)($s2['client'] ?? ''),
                                                  'part' => (string)($s2['part'] ?? ''),
                                                  // ERP 把「製程」打在 Content、規格在 Specification、備註在 Note，
                                                  // 三欄都要印出來才核對得了（實測 Content 才是製程那一欄）
                                                  'spec' => (string)($s2['spec'] ?? ''),
                                                  'content' => (string)($s2['content'] ?? ''),
                                                  'note' => (string)($s2['note'] ?? ''),
                                                  'price' => $s2['price'], 'ids' => [], 'src' => $s2['src']];
            $sDocs[$k]['qty'] += $q1;
            // 一張出貨單在 is_list 是好幾列，解除綁定時要逐列解，所以 id 全都留著
            $sDocs[$k]['ids'][] = (int)$s2['id'];
            if ($sDocs[$k]['price'] <= 0 && $s2['price'] > 0) $sDocs[$k]['price'] = $s2['price'];
            foreach (['spec', 'content', 'note'] as $fk)
                if ($sDocs[$k][$fk] === '' && ($s2[$fk] ?? '') !== '') $sDocs[$k][$fk] = (string)$s2[$fk];
            if ($s2['date'] !== '' && ($sDocs[$k]['date'] === '' || $s2['date'] < $sDocs[$k]['date']))
                $sDocs[$k]['date'] = $s2['date'];
        }
        $sList = array_slice(array_values($sDocs), 0, 12);

        /* ── 判定 ── */
        $iss = [];
        // 逐項開關：管理員可把某個檢核項目關掉或降級（設定值是「上限」，
        // 所以推測配對算出來的降級仍然有效，不會被設定值升回 critical）
        $add = function (string $code, string $level, string $text) use (&$iss, $itemLv) {
            $cap = $itemLv[$code] ?? $level;
            if ($cap === 'off') return;
            if ($cap === 'warn' && $level === 'critical') $level = 'warn';
            $iss[] = ['code' => $code, 'level' => $level, 'text' => $text];
        };
        $qdate = $q ? dqa_d($q['qdate']) : '';
        /* 只有「綁定」來的配對才判成嚴重：推測配對本來就是猜的，
           猜到一張早於訂單的製令，多半是別張訂單的製令，不是這張訂單開早了。 */
        $isBind = function (string $src) { return $src === 'map' || $src === 'legacy'; };
        $lv  = function (string $src) use ($isBind) { return $isBind($src) ? 'critical' : 'warn'; };
        $sfx = function (string $src) use ($isBind) { return $isBind($src) ? '' : '（推測配對，僅供參考）'; };

        // ① 報價日 <= 訂單日（綁了好幾列報價時逐列都要看，不是只看主要報價那一列）
        $qSrcKey0 = ($qSrc === 'bind') ? 'map' : 'guess';
        foreach ($qAll as $qi1) {
            $d1 = dqa_d($qi1['qdate']);
            if ($d1 !== '' && $d1 > $odate) {
                $add('q_late', $lv($qSrcKey0),
                     '報價單 ' . $qi1['quote_no'] . ' 的報價日 ' . $d1 . ' 晚於訂單日 ' . $odate . $sfx($qSrcKey0));
                break;
            }
        }
        if (!$q) $add('q_none', 'warn', '查不到對應的報價單（先比料號主檔、再比同客戶同料號）');

        // ② 製令開立日 >= 訂單日
        foreach ($boms as $b)
            if ($b['date'] !== '' && $b['date'] < $odate) {
                $add('b_early', $lv($bomSrc), '製令 ' . $b['bom'] . ' 開立日 ' . $b['date'] .
                     ' 早於訂單日 ' . $odate . $sfx($bomSrc));
                break;
            }
        if (!$boms && !$autoPm) $add('b_none', 'warn', '查不到製令，且這張訂單沒有設定自動轉生管');

        // ③ 出貨日 >= 製令開立日；自動轉生管（不必開製令）時只比訂單日
        if ($ships) {
            // 只有出貨與製令「兩邊都是綁定」時比才有意義：任一邊是推測的，
            // 比出來的先後多半是配錯對象造成的（實測 2025 年會多出 331 筆假警報）
            if ($boms && $bMin !== '' && $sMin !== '' && $sMin < $bMin && $isBind($shipSrc) && $isBind($bomSrc))
                $add('s_early_bom', 'critical', '出貨日 ' . $sMin . ' 早於製令開立日 ' . $bMin);
            if ($sMin !== '' && $sMin < $odate)
                $add('s_early_order', $lv($shipSrc), '出貨日 ' . $sMin . ' 早於訂單日 ' . $odate . $sfx($shipSrc));
        } elseif ($closed) {
            $add('s_none', 'warn', '訂單已結案卻查不到出貨單');
        }

        // 報價的單價／新鮮度先算好：④數量 與 ⑤單價 都要用到（$qFresh 判「這張報價還算不算數」）
        // 綁到某一階時一律以那一階的單價為準——整列的 unit_price 在階梯報價上常常是 0
        $qTier    = $q ? ($q['_tier'] ?? null) : null;
        $qprice   = $qTier ? dqa_num($qTier['price']) : ($q ? dqa_num($q['unit_price']) : 0.0);
        $qAgeDays = ($q && $qdate !== '') ? (int)round((strtotime($odate) - strtotime($qdate)) / 86400) : -1;
        $qFresh   = ($qSrc === 'bind') || ($qAgeDays >= 0 && $qAgeDays <= $validDays);

        // ④ 數量（只有「綁定」才判不符；推測配對本來就配不準，硬判會滿畫面假警報）
        //
        // 報價數量 vs 訂單數量（使用者指定要列嚴重，2026-09-21）。
        // **這一項刻意不照 $lv() 依來源降級**（與日期先後那幾條不同）：日期先後在配錯對象時
        // 很容易誤判，但「數量差了一個量級」不論配到哪一張報價都值得看一眼，所以推測配對也
        // 一樣判嚴重，只在訊息後面標註是推測來的。覺得太吵可以在「設定」把這一項降級或關閉
        //（$add() 會拿設定值當上限）。
        // 兩種情況刻意完全不判：
        //   ⑴ 階梯報價＝本來就是不同數量不同價，拿單一數量去比沒有意義
        //   ⑵ 報價已過期（$qFresh 為假）＝那是 q_old 要講的事，同一件事不報兩次
        // ⚠ ERP 報價匯入在 2026-09-21 之前把「4,000」讀成「4」（千分位逗號被截斷，已修
        //   _upload_For_List.php 的 parseERPQty_erp）。**在報價單重新匯入之前，舊資料會讓
        //   這一項冒出大量假的「數量不符」**；要暫時關掉請到本頁「設定」把它改成不檢查。
        //   ⑶ 綁了兩列以上的報價（本體＋治具／刀具）也不判——本體的數量與治具的數量
        //      本來就不是同一件事，加起來或挑一列去比都沒有意義。
        $qqty = $q ? dqa_num($q['quantity']) : 0.0;
        $qSrcKey = ($qSrc === 'bind') ? 'map' : 'guess';
        if ($q && count($qAll) <= 1 && $qFresh && empty($q['is_tiered'])
            && $qqty > 0 && $oqty > 0 && dqa_diff_over($qqty, $oqty, $tol['qty_pct']))
            $add('qty_quote', 'critical', '報價數量 ' . dqa_n($qqty) . ' 與訂單數量 '
                 . dqa_n($oqty) . ' 不符' . $sfx($qSrcKey));

        if ($boms && in_array($bomSrc, ['map', 'legacy'], true) && $bQty > 0
            && dqa_diff_over($oqty, $bQty, $tol['qty_pct']))
            $add('qty_bom', 'warn', '製令數量合計 ' . dqa_n($bQty) . ' 與訂單數量 ' . dqa_n($oqty) . ' 不符');
        if ($ships && in_array($shipSrc, ['map', 'legacy'], true) && $sQty > 0) {
            if ($sQty > $oqty && dqa_diff_over($oqty, $sQty, $tol['qty_pct']))
                $add('qty_over', 'critical', '出貨數量合計 ' . dqa_n($sQty) . ' 超出訂單數量 ' . dqa_n($oqty));
            elseif ($closed && dqa_diff_over($oqty, $sQty, $tol['qty_pct']))
                $add('qty_ship', 'warn', '訂單已結案，出貨數量合計 ' . dqa_n($sQty) . ' 與訂單數量 ' . dqa_n($oqty) . ' 不符');
        }

        // ⑤ 單價
        //   報價單價：報價太舊時不判「不符」而是判「報價過期未重報」——
        //   實測抓到 2021 年的報價（883）拿來比 2026 的訂單（583），那是五年來調過價，
        //   報「單價不符」沒有意義，真正的缺失是「這支料號這麼久沒有重新報價」。
        //   出貨單價：只有綁定才判，推測配對可能配到同料號別張訂單的出貨。
        // （$qprice／$qAgeDays／$qFresh 已在 ④ 之前算好，④ 的數量比對也要用）
        if ($q && $qAgeDays > $validDays)
            $add('q_old', 'warn', '最近一次報價是 ' . $qdate . '（距下單 ' . $qAgeDays . ' 天），已逾 ' . $validDays . ' 天未重新報價');
        if ($q && $qFresh && $qprice > 0 && $oprice > 0
            && (empty($q['is_tiered']) || $qTier)          // 階梯報價要綁到其中一階才比得出單價
            && dqa_diff_over($qprice, $oprice, $tol['price_pct']))
            $add('price_q', 'warn', '報價單價 ' . dqa_n($qprice)
                 . ($qTier ? ('（' . $qTier['range'] . ' 這一階）') : '')
                 . ' 與訂單單價 ' . dqa_n($oprice) . ' 不符' . $sfx($qSrc === 'bind' ? 'map' : 'guess'));
        if ($sPrice !== null && $sPrice > 0 && $oprice > 0 && $isBind($shipSrc)
            && dqa_diff_over($oprice, $sPrice, $tol['price_pct']))
            $add('price_s', 'warn', '訂單單價 ' . dqa_n($oprice) . ' 與出貨單價 ' . dqa_n($sPrice) . ' 不符');

        /* ⑤-2 金額為 0（2026-09-21 使用者交辦：報價／訂單／出貨都要抓）
         *   刻意排除「被設定為備註」的那種列——ERP 匯入的資料人工確認後會標成備註，
         *   那種列本來就沒有金額，報出來只是雜訊：
         *     報價 note_only=1／出貨 anomaly_confirmed=1（出貨分析頁既有的「確認非異常」旗標）。
         *   訂單沒有這種旗標，確認是備註性質時請用這一列的「標為例外」。
         *   階梯報價的 unit_price 本來就可能是 0（價格在階梯裡），所以也不判。 */
        if ($q && empty($q['note_only']) && empty($q['is_tiered'])
            && dqa_num($q['unit_price']) <= 0 && dqa_num($q['amount'] ?? 0) <= 0)
            $add('amt_zero_quote', 'warn', '報價單 ' . $q['quote_no'] . ' 這一列的單價與金額都是 0'
                 . $sfx($qSrcKey));
        if ($oqty <= 0 || $oprice <= 0)
            $add('amt_zero_order', 'warn', '訂單金額為 0（數量 ' . dqa_n($oqty) . '、單價 ' . dqa_n($oprice) . '）');
        foreach ($ships as $s3) {
            if (!$isBind($s3['src']) || !empty($s3['ok_flag'])) continue;
            if ($s3['price'] <= 0) {
                $add('amt_zero_ship', 'critical', '出貨單 ' . $s3['no'] . '（' . $s3['date']
                     . '）沒有打單價，這批貨收不到款');
                break;
            }
        }
        if (($itemLv['ship_norecon'] ?? 'off') !== 'off') {
            foreach ($ships as $s3) {
                if (!$isBind($s3['src']) || !empty($s3['ok_flag'])) continue;
                if (empty($shipRecon[(int)$s3['id']])) {
                    $add('ship_norecon', 'warn', '出貨單 ' . $s3['no'] . '（' . $s3['date']
                         . '）沒有進對帳底稿、也沒有開立發票');
                    break;
                }
            }
        }

        // ⑥ 製程（可關閉）：訂單是手打文字、出貨是與規格混打，所以抽關鍵詞比集合
        $pOrder = $pQuote = $pBom = $pShip = [];
        $pCmp = '';
        if ($cmpP) {
            $pOrder = dqa_proc_extract((string)$o['Processing_items'], $terms);
            if ($q) $pQuote = dqa_proc_extract(
                implode(' ', dqa_quote_proc_names($q['process_notes'] ?? '', $subTagName)), $terms);
            foreach ($boms as $b) foreach (array_keys($bomProc[$b['bom']] ?? []) as $n) $pBom[$n] = 1;
            $pBom = array_keys($pBom);
            $pShip = dqa_proc_extract($sSpec, $terms);

            $c1 = dqa_proc_compare($pQuote, $pOrder);
            $c2 = dqa_proc_compare($pOrder, $pBom);
            $pCmp = ($c1 === 'diff' || $c2 === 'diff') ? 'diff'
                  : (($c1 === 'partial' || $c2 === 'partial') ? 'partial'
                  : (($c1 === 'same' || $c2 === 'same') ? 'same' : 'unknown'));
            if ($c1 === 'diff') $add('proc_q', 'warn', '報價製程「' . implode('、', $pQuote) . '」與訂單製程「' . implode('、', $pOrder) . '」完全不同');
            if ($c2 === 'diff') $add('proc_b', 'warn', '訂單製程「' . implode('、', $pOrder) . '」與製令製程「' . implode('、', $pBom) . '」完全不同');
        }

        /* 排除設定：整個客戶／料號不稽核，或只拿掉指定的檢核項目
           （使用者案例：某客戶都下未來單，製令必定早於訂單，那不是缺失） */
        $exclHit = dqa_excl_hit($exclMap, ['client' => (string)$o['Client_name'],
                                           'part'   => (string)$o['d_id']]);
        $skipRow = false; $exclNote = [];
        foreach ($exclHit as $h) {
            $exclStat[$h['id']] = ($exclStat[$h['id']] ?? 0) + 1;
            if ($h['all']) { $skipRow = true; $exclNote[] = $h; continue; }
            $keep = [];
            foreach ($iss as $it) { if (isset($h['items'][$it['code']])) continue; $keep[] = $it; }
            if (count($keep) !== count($iss)) $exclNote[] = $h;
            $iss = $keep;
        }
        if ($skipRow) continue;                 // 整筆排除：這張訂單完全不進稽核結果

        /* 已核可的例外一律扣掉（整筆 * 或逐項） */
        $ex = $exempt[(string)$oid] ?? [];
        $exHit = [];
        if (isset($ex['*'])) { $exHit = ['*' => $ex['*']]; $iss = []; }
        else {
            $keep = [];
            foreach ($iss as $it) {
                if (isset($ex[$it['code']])) { $exHit[$it['code']] = $ex[$it['code']]; continue; }
                $keep[] = $it;
            }
            $iss = $keep;
        }

        $level = 'ok';
        foreach ($iss as $it) { if ($it['level'] === 'critical') { $level = 'critical'; break; } $level = 'warn'; }
        if ($onlyBad && $level === 'ok') continue;

        $rows[] = [
            'order_id' => $oid, 'order_no' => (string)$o['Order_oo'],
            'client' => (string)$o['Client_name'], 'part' => (string)$o['d_id'],
            'odate' => $odate, 'ddate' => (string)$o['ddate'],
            'oqty' => $oqty, 'oprice' => $oprice, 'closed' => $closed, 'auto_pm' => $autoPm,
            'oproc' => (string)$o['Processing_items'],
            'ops'   => trim((string)($o['Order_ps'] ?? '')),
            'ospec' => trim((string)($o['ospec'] ?? '')),
            'quote' => $q ? ['no' => (string)$q['quote_no'], 'date' => $qdate,
                             'qty' => dqa_num($q['quantity']), 'price' => $qprice, 'src' => $qSrc,
                             'item_id' => (int)$q['item_id'], 'tiered' => !empty($q['is_tiered']),
                             'tier' => $qTier,
                             'cnt' => count($qAll),
                             'client' => trim((string)($q['client_name'] ?? '')),
                             'spec'   => trim((string)($q['specification'] ?? '')),
                             'procs'  => dqa_quote_proc_names($q['process_notes'] ?? '', $subTagName),
                             'part'   => trim((string)($q['product_id'] ?? ''))] : null,
            // 綁到的全部報價項目（本體＋治具／刀具…）；畫面上主要報價印在最前面
            'quote_list' => array_map(function ($x) use ($subTagName) {
                return ['item_id' => (int)$x['item_id'], 'no' => (string)$x['quote_no'],
                        'date' => dqa_d($x['qdate']), 'qty' => dqa_num($x['quantity']),
                        'price' => dqa_num($x['unit_price']), 'part' => trim((string)$x['product_id']),
                        'spec' => (string)($x['specification'] ?? ''),
                        'procs' => dqa_quote_proc_names($x['process_notes'] ?? '', $subTagName),
                        'tiered' => !empty($x['is_tiered']), 'tier' => ($x['_tier'] ?? null),
                        'alloc' => dqa_num($x['_alloc'] ?? 0),
                        'src' => (string)($x['_link_src'] ?? '')];
            }, $qAll),
            'bom'   => ['cnt' => count($boms), 'qty' => $bQty, 'date' => $bMin, 'date_max' => $bMax,
                        'src' => $bomSrc, 'list' => $bList],
            'ship'  => ['cnt' => count($ships), 'doc_cnt' => count($sList), 'qty' => $sQty,
                        'date' => $sMin, 'date_max' => $sMax,
                        'price' => $sPrice, 'src' => $shipSrc, 'list' => $sList],
            'proc'  => ['quote' => $pQuote, 'order' => $pOrder, 'bom' => $pBom, 'ship' => $pShip, 'cmp' => $pCmp],
            'issues' => $iss, 'level' => $level, 'exempt' => $exHit,
            'excl' => array_map(function ($h) {
                return ['dim' => $h['dim'], 'val' => $h['val'], 'reason' => $h['reason']];
            }, $exclNote),
        ];
    }

    return ['rows' => $rows, 'total' => count($rows), 'stat' => dqa_trace_stat($rows),
            'truncated' => ($orderTotal > count($orders)), 'scanned' => count($orders),
            'order_total' => $orderTotal, 'limit' => $lim, 'tol' => $tol,
            'excl_rules' => dqa_excl_applied($db, 'trace', $exclStat)];
}

/** 這次稽核實際套用到的排除規則＋各排掉幾筆（畫面一定要講出來，不然看不出有排除在作用） */
function dqa_excl_applied(PDO $db, string $tab, array $stat): array
{
    $out = [];
    foreach (dqa_excl_list($db) as $r) {
        if (!$r['is_active'] || !in_array($tab, $r['tab_list'], true)) continue;
        $out[] = ['id' => (int)$r['id'], 'dim' => (string)$r['dim'], 'val' => (string)$r['val'],
                  'items' => $r['item_list'], 'reason' => (string)$r['reason'],
                  'hit' => (int)($stat[(int)$r['id']] ?? 0)];
    }
    return $out;
}

/** 數字顯示：小數尾 0 省略（UI 規則） */
function dqa_n(float $v): string
{
    $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}

function dqa_trace_stat(array $rows): array
{
    $st = ['total' => count($rows), 'critical' => 0, 'warn' => 0, 'ok' => 0, 'by_code' => []];
    foreach ($rows as $r) {
        $st[$r['level']] = ($st[$r['level']] ?? 0) + 1;
        foreach ($r['issues'] as $it) $st['by_code'][$it['code']] = ($st['by_code'][$it['code']] ?? 0) + 1;
    }
    return $st;
}

/** 目前生效的檢核項目等級（預設值 ＋ 管理員調整過的；off＝不檢查） */
function dqa_trace_levels(PDO $db): array
{
    $def = [];
    foreach (dqa_trace_items() as $k => $d) $def[$k] = $d[1];
    $v = dqa_param_get($db, 'trace_items', []);
    if (is_array($v)) foreach ($v as $k => $lv)
        if (isset($def[$k]) && in_array($lv, ['critical', 'warn', 'off'], true)) $def[$k] = $lv;
    return $def;
}

/** 流程稽核的檢核項目一覽（畫面上的篩選與說明都從這裡長出來，不寫死兩份） */
function dqa_trace_items(): array
{
    return [
        'q_late'        => ['報價日晚於訂單日', 'critical'],
        'q_none'        => ['查不到報價單',     'warn'],
        'q_old'         => ['報價過期未重新報價', 'warn'],
        'b_early'       => ['製令早於訂單',     'critical'],
        'b_none'        => ['查不到製令',       'warn'],
        's_early_bom'   => ['出貨早於製令',     'critical'],
        's_early_order' => ['出貨早於訂單',     'critical'],
        's_none'        => ['已結案未出貨',     'warn'],
        'qty_quote'     => ['報價與訂單數量不符', 'critical'],
        'qty_bom'       => ['製令數量不符',     'warn'],
        'qty_over'      => ['出貨超出訂單量',   'critical'],
        'qty_ship'      => ['出貨數量不符',     'warn'],
        'price_q'       => ['報價與訂單單價不符', 'warn'],
        'price_s'       => ['訂單與出貨單價不符', 'warn'],
        'proc_q'        => ['報價與訂單製程不同', 'warn'],
        'proc_b'        => ['訂單與製令製程不同', 'warn'],
        /* 2026-09-21 使用者交辦：金額為 0 的單據也要抓出來。
           「被設定為備註的那種列」不算缺失（ERP 匯入的資料人工確認後會標成備註）：
             報價 → quotation_item.note_only=1
             出貨 → is_list.anomaly_confirmed=1（「確認非異常」，出貨分析頁既有的旗標，不另外發明一個）
             訂單 → 沒有這種旗標，請用這一列的「標為例外」處理 */
        'amt_zero_quote' => ['報價金額為 0',     'warn'],
        'amt_zero_order' => ['訂單金額為 0',     'warn'],
        'amt_zero_ship'  => ['出貨未開價（收不到款）', 'critical'],
        /* 會計模組（發票明細／對帳底稿）目前幾乎沒有資料，全開會整片報未收款，
           所以預設關閉；等會計上線後到「設定」把它打開即可。 */
        'ship_norecon'   => ['出貨未進對帳／未開發票', 'off'],
    ];
}
/* ============================================================
 * 綁定（2026-09-21 使用者交辦）
 *
 * 【為什麼綁定要做在稽核頁】使用者原話：「因為確認無誤就可以直接綁定」。
 * 缺失的成因十之八九就是「沒有做綁定」，查出來之後還要換到另外三個頁面各綁一次，
 * 實務上就是不會有人去綁，缺失永遠掛在那裡。
 *
 * 【寫入一律走 trace_chain_lib】tc_link()／tc_unlink() 是全站唯一的綁定引擎，
 * 它負責數量上限、客戶比對、舊欄位補列（seed）與主要單據快取同步。
 * 這裡只負責「把候選單據找出來給人挑」，一行寫入邏輯都不自己刻。
 *
 * 【一對多／多對一／多對多】四種節點的關係各自不同，候選清單也因此不一樣：
 *   報價→訂單  多張訂單對一個報價項目；一張訂單也可以對多個報價項目（本體＋治具）→ 不拆量
 *   訂單→製令  多對多且拆量（bom_order_process_map）
 *   訂單→出貨  多對多且拆量（is_order_map）
 * 所以拆量的那兩種一律要顯示「本身數量／已分配／還剩多少」，不然使用者按下去才發現超量。
 * ============================================================ */

/** 報價項目的製程名稱（process_notes 存子標籤 id 清單；既有資料有純數字、有陣列、也有物件） */
function dqa_quote_procs(PDO $db, array $itemIds): array
{
    $ids = array_values(array_unique(array_map('intval', $itemIds)));
    $ids = array_values(array_filter($ids, function ($v) { return $v > 0; }));
    if (!$ids) return [];
    static $names = null;
    if ($names === null) {
        $names = [];
        try {
            foreach ($db->query("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag")
                        ->fetchAll(PDO::FETCH_ASSOC) as $r)
                $names[(int)$r['sub_tag_id']] = trim((string)$r['sub_tag_name']);
        } catch (Throwable $e) {}
    }
    $out = [];
    foreach (dqa_chunks($ids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT item_id, process_notes FROM quotation_item WHERE item_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pn = json_decode((string)($r['process_notes'] ?? ''), true);
            if (is_numeric($pn)) $pn = [$pn];
            if (!is_array($pn))  $pn = [];
            $ns = [];
            foreach ($pn as $x) {
                $id = is_array($x) ? (int)($x['sub_tag_id'] ?? 0) : (int)$x;
                if ($id > 0 && isset($names[$id])) $ns[] = $names[$id];
            }
            $out[(int)$r['item_id']] = $ns;
        }
    }
    return $out;
}

/** 報價項目的階梯區間（使用者要求「相關報價數量區間也要明確」） */
function dqa_quote_tiers(PDO $db, array $itemIds): array
{
    $ids = array_values(array_unique(array_map('intval', $itemIds)));
    $ids = array_values(array_filter($ids, function ($v) { return $v > 0; }));
    if (!$ids) return [];
    $out = [];
    foreach (dqa_chunks($ids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT tier_id, item_id, qty_min, qty_max, unit_price, tolerance_value, tolerance_unit,
                                  tolerance_note
                             FROM quotation_item_tier WHERE item_id IN ($in)
                            ORDER BY item_id, sort_order, qty_min");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mn = dqa_num($r['qty_min']); $mx = $r['qty_max'] === null ? null : dqa_num($r['qty_max']);
            $out[(int)$r['item_id']][] = [
                'tier_id' => (int)$r['tier_id'],
                'min' => $mn, 'max' => $mx, 'price' => dqa_num($r['unit_price']),
                'range' => dqa_n($mn) . ' ~ ' . ($mx === null ? '以上' : dqa_n($mx)),
                'tol' => ($r['tolerance_value'] !== null && dqa_num($r['tolerance_value']) > 0)
                         ? (dqa_n(dqa_num($r['tolerance_value'])) . (string)$r['tolerance_unit']) : '',
                'tol_note' => (string)($r['tolerance_note'] ?? ''),
            ];
        }
    }
    return $out;
}

/**
 * 一整張報價單的全部項目，每一列附上「有沒有建立訂單／有沒有出貨／出貨有沒有開價」。
 *
 * 使用者原話：「報價內常有其他治具、刀具...報價，稽核也需要檢查是否有報價但訂單沒有建立，
 * 還是出貨沒有打上去收款，所以報價單要可以顯示完整報價單內容方便確認」。
 * 所以這裡一定要列**整張**報價單（含備註列），不是只列跟目前這張訂單有關的那一列。
 */
function dqa_quote_detail(PDO $db, int $quoteId = 0, int $itemId = 0): array
{
    if ($quoteId <= 0 && $itemId > 0) {
        $s = $db->prepare("SELECT quote_id FROM quotation_item WHERE item_id=?");
        $s->execute([$itemId]);
        $quoteId = (int)$s->fetchColumn();
    }
    if ($quoteId <= 0) return ['error' => '找不到這張報價單'];

    $s = $db->prepare("SELECT quote_id, quote_no, DATE_FORMAT(quote_date,'%Y-%m-%d') quote_date,
                              DATE_FORMAT(valid_until,'%Y-%m-%d') valid_until, client_name, client_id,
                              inquiry_no, currency, total_amount, note,
                              COALESCE(is_draft,0) is_draft, approval_status
                         FROM quotation_list WHERE quote_id=?");
    $s->execute([$quoteId]);
    $head = $s->fetch(PDO::FETCH_ASSOC);
    if (!$head) return ['error' => '找不到這張報價單'];

    $s = $db->prepare("SELECT item_id, sort_order, product_id, d_setting_d_id, specification, quantity,
                              unit, unit_price, amount, COALESCE(is_tiered,0) is_tiered,
                              COALESCE(note_only,0) note_only
                         FROM quotation_item WHERE quote_id=? ORDER BY sort_order, item_id");
    $s->execute([$quoteId]);
    $items = $s->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(function ($r) { return (int)$r['item_id']; }, $items);
    $procs = dqa_quote_procs($db, $ids);
    $tiers = dqa_quote_tiers($db, $ids);

    /* 這幾列各自被哪些訂單引用（綁定優先，沒綁定才用同客戶同料號推測） */
    $bound = [];
    if ($ids) {
        tc_order_quote_ensure($db);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $db->prepare("SELECT m.item_id, ot.Order_id, ot.Order_oo, ot.Qty, ot.unit_price,
                                  ot.Order_status, DATE_FORMAT(ot.Order_date,'%Y-%m-%d') odate
                             FROM order_quote_map m JOIN order_track ot ON ot.Order_id=m.Order_id
                            WHERE m.item_id IN ($in) ORDER BY ot.Order_date");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $bound[(int)$r['item_id']][] = $r;
        // 還沒搬進分配表的舊綁定
        $q = $db->prepare("SELECT ot.quote_item_id item_id, ot.Order_id, ot.Order_oo, ot.Qty, ot.unit_price,
                                  ot.Order_status, DATE_FORMAT(ot.Order_date,'%Y-%m-%d') odate
                             FROM order_track ot WHERE ot.quote_item_id IN ($in) ORDER BY ot.Order_date");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (int)$r['item_id'];
            foreach ($bound[$k] ?? [] as $x) if ((int)$x['Order_id'] === (int)$r['Order_id']) continue 2;
            $bound[$k][] = $r;
        }
    }
    $oids = [];
    foreach ($bound as $rows) foreach ($rows as $r) $oids[] = (int)$r['Order_id'];

    /* 這些訂單各自出了哪些貨（綁定為主，退回 is_list.Order_id） */
    $shipOf = [];
    foreach (dqa_chunks($oids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $q = $db->prepare("SELECT m.Order_id, il.IS_id, il.IS_number, il.Qty, il.Unit_price,
                                  COALESCE(il.anomaly_confirmed,0) ok_flag,
                                  DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate
                             FROM is_order_map m JOIN is_list il ON il.IS_id=m.IS_id
                            WHERE m.Order_id IN ($in)
                            UNION
                           SELECT il.Order_id, il.IS_id, il.IS_number, il.Qty, il.Unit_price,
                                  COALESCE(il.anomaly_confirmed,0) ok_flag,
                                  DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate
                             FROM is_list il WHERE il.Order_id IN ($in)");
        $q->execute(array_merge($ck, $ck));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $shipOf[(int)$r['Order_id']][(int)$r['IS_id']] = $r;
    }

    $out = [];
    foreach ($items as $it) {
        $iid = (int)$it['item_id'];
        $ords = []; $ships = []; $noPrice = false;
        foreach ($bound[$iid] ?? [] as $r) {
            $ords[] = ['order_id' => (int)$r['Order_id'], 'no' => (string)$r['Order_oo'],
                       'date' => (string)$r['odate'], 'qty' => dqa_num($r['Qty']),
                       'price' => dqa_num($r['unit_price']),
                       'closed' => ((string)$r['Order_status'] === '9')];
            foreach ($shipOf[(int)$r['Order_id']] ?? [] as $sr) {
                $ships[(string)$sr['IS_number']] = [
                    'no' => (string)$sr['IS_number'], 'date' => (string)$sr['sdate'],
                    'qty' => dqa_num($sr['Qty']), 'price' => dqa_num($sr['Unit_price'])];
                if (dqa_num($sr['Unit_price']) <= 0 && (int)$sr['ok_flag'] !== 1) $noPrice = true;
            }
        }
        $out[] = [
            'item_id' => $iid, 'part' => (string)$it['product_id'],
            'd_id' => (int)($it['d_setting_d_id'] ?? 0),
            'spec' => (string)($it['specification'] ?? ''),
            'qty' => dqa_num($it['quantity']), 'unit' => (string)($it['unit'] ?? ''),
            'price' => dqa_num($it['unit_price']), 'amount' => dqa_num($it['amount']),
            'tiered' => !empty($it['is_tiered']), 'note_only' => !empty($it['note_only']),
            'procs' => $procs[$iid] ?? [], 'tiers' => $tiers[$iid] ?? [],
            'orders' => $ords, 'ships' => array_values($ships),
            'ship_noprice' => $noPrice,
        ];
    }
    return ['head' => $head, 'items' => $out];
}

/**
 * 某張訂單在某個節點的候選單據（給人點選後綁定）。
 * $kind: quote｜bom｜ship
 * $opt : kw（關鍵字，打了就不限日期與客戶）、all_client（1＝不限客戶）、same_part（1＝只列同料號）
 *
 * 候選一律分成三種來源並在畫面上標出來：
 *   bound 已經綁在這張訂單上（可解除）／other 綁在別張訂單上（要小心）／free 還沒被綁的
 */
function dqa_node_candidates(PDO $db, int $orderId, string $kind, array $opt = []): array
{
    $s = $db->prepare("SELECT Order_id, Order_oo, Client_name, d_id, d_id_ID, Qty, unit_price,
                              Order_status, pmGet_auto, Processing_items,
                              DATE_FORMAT(Order_date,'%Y-%m-%d') odate,
                              DATE_FORMAT(Delivery_date,'%Y-%m-%d') ddate
                         FROM order_track WHERE Order_id=?");
    $s->execute([$orderId]);
    $o = $s->fetch(PDO::FETCH_ASSOC);
    if (!$o) return ['error' => '找不到這張訂單'];

    $kw      = trim((string)($opt['kw'] ?? ''));
    $allCli  = !empty($opt['all_client']) || $kw !== '';
    $samePt  = !array_key_exists('same_part', $opt) || !empty($opt['same_part']);   // 預設開
    $did     = (int)$o['d_id_ID'];
    $part    = trim((string)$o['d_id']);
    $client  = trim((string)$o['Client_name']);
    $odate   = (string)$o['odate'];
    $lim     = 120;

    /* 料號條件（same_part 預設開）：主鍵或料號文字相同（與稽核配對同一條規則，見 dqa_pair_keys）。
       關掉之後不比料號、只靠客戶與關鍵字篩——**治具／刀具那一列的料號本來就與訂單不同**，
       不關掉的話永遠挑不到它（使用者 2026-09-21 指名要能綁那種列）。 */
    $mkPart = function (string $didCol, string $ptCol) use ($did, $part, $samePt) {
        if (!$samePt) return ['1=1', []];
        $w = []; $b = [];
        if ($did > 0)      { $w[] = "$didCol = ?"; $b[] = $did; }
        if ($part !== '')  { $w[] = "$ptCol = ?";  $b[] = $part; }
        if (!$w) return ['1=1', []];
        return ['(' . implode(' OR ', $w) . ')', $b];
    };

    $rows = [];
    if ($kind === 'quote') {
        tc_order_quote_ensure($db);
        $boundIds = [];
        foreach (tc_order_quote_map($db, [$orderId])[$orderId] ?? [] as $lk) $boundIds[(int)$lk['item_id']] = $lk;
        $boundTier = [];
        foreach ($boundIds as $iid2 => $lk2) $boundTier[$iid2] = (int)($lk2['tier_id'] ?? 0);
        [$pw, $pb] = $mkPart('qi.d_setting_d_id', 'qi.product_id');
        $w = []; $b = [];
        if ($kw !== '') {
            $w[] = "(ql.quote_no LIKE ? OR qi.product_id LIKE ? OR qi.specification LIKE ?)";
            $b = array_merge($b, ['%' . $kw . '%', '%' . $kw . '%', '%' . $kw . '%']);
        } else {
            $w[] = $pw; $b = array_merge($b, $pb);
            if (!$allCli && $client !== '') { $w[] = "ql.client_name LIKE ?"; $b[] = '%' . $client . '%'; }
        }
        $w[] = "COALESCE(ql.is_draft,0)=0";
        $sql = "SELECT qi.item_id, qi.quote_id, qi.product_id, qi.specification, qi.quantity, qi.unit,
                       qi.unit_price, qi.amount, COALESCE(qi.is_tiered,0) is_tiered,
                       COALESCE(qi.note_only,0) note_only,
                       ql.quote_no, ql.client_name, DATE_FORMAT(ql.quote_date,'%Y-%m-%d') qdate
                  FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                 WHERE " . implode(' AND ', $w) . "
                 ORDER BY ABS(DATEDIFF(ql.quote_date, ?)), ql.quote_date DESC LIMIT $lim";
        $b[] = $odate !== '' ? $odate : date('Y-m-d');
        $st = $db->prepare($sql); $st->execute($b);
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
        // 已綁在這張訂單上的一定要列出來（即使不符合目前的篩選條件），否則解除不了
        $have = [];
        foreach ($list as $r) $have[(int)$r['item_id']] = 1;
        $missing = array_values(array_diff(array_keys($boundIds), array_keys($have)));
        if ($missing) {
            $in = implode(',', array_fill(0, count($missing), '?'));
            $st = $db->prepare("SELECT qi.item_id, qi.quote_id, qi.product_id, qi.specification, qi.quantity,
                                       qi.unit, qi.unit_price, qi.amount, COALESCE(qi.is_tiered,0) is_tiered,
                                       COALESCE(qi.note_only,0) note_only,
                                       ql.quote_no, ql.client_name, DATE_FORMAT(ql.quote_date,'%Y-%m-%d') qdate
                                  FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                                 WHERE qi.item_id IN ($in)");
            $st->execute($missing);
            $list = array_merge($st->fetchAll(PDO::FETCH_ASSOC), $list);
        }
        $ids = array_map(function ($r) { return (int)$r['item_id']; }, $list);
        $procs = dqa_quote_procs($db, $ids);
        $tiers = dqa_quote_tiers($db, $ids);
        // 這些報價項目各自被幾張訂單引用（多對一是正常的，但要讓人看得到）
        $used = [];
        foreach (dqa_chunks($ids) as $ck) {
            $in = implode(',', array_fill(0, count($ck), '?'));
            $st = $db->prepare("SELECT item_id, COUNT(*) c FROM order_quote_map
                                 WHERE item_id IN ($in) GROUP BY item_id");
            $st->execute($ck);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $used[(int)$r['item_id']] = (int)$r['c'];
        }
        foreach ($list as $r) {
            $iid = (int)$r['item_id'];
            $rows[] = [
                'id' => $iid, 'no' => (string)$r['quote_no'], 'date' => (string)$r['qdate'],
                'part' => (string)$r['product_id'], 'spec' => (string)($r['specification'] ?? ''),
                'client' => (string)$r['client_name'],
                'qty' => dqa_num($r['quantity']), 'unit' => (string)($r['unit'] ?? ''),
                'price' => dqa_num($r['unit_price']), 'amount' => dqa_num($r['amount']),
                'tiered' => !empty($r['is_tiered']), 'note_only' => !empty($r['note_only']),
                'procs' => $procs[$iid] ?? [], 'tiers' => $tiers[$iid] ?? [],
                'quote_id' => (int)$r['quote_id'],
                'bound' => isset($boundIds[$iid]), 'bound_tier' => $boundTier[$iid] ?? 0,
                'used_by' => $used[$iid] ?? 0,
                'late' => ($r['qdate'] && $odate && $r['qdate'] > $odate),
            ];
        }
    } elseif ($kind === 'bom') {
        $boundIds = [];
        $st = $db->prepare("SELECT bom, allocated_qty FROM bom_order_process_map WHERE order_id=?");
        $st->execute([$orderId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $boundIds[(string)$r['bom']] = (int)$r['allocated_qty'];
        $st = $db->prepare("SELECT bom, sqty FROM bom WHERE o_order_id=?");
        $st->execute([$orderId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            if (!isset($boundIds[(string)$r['bom']])) $boundIds[(string)$r['bom']] = (int)$r['sqty'];
        [$pw, $pb] = $mkPart('b.d_setting_id', 'b.d_id');
        $w = []; $b = [];
        if ($kw !== '') { $w[] = "(b.bom LIKE ? OR b.d_id LIKE ?)"; $b = ['%' . $kw . '%', '%' . $kw . '%']; }
        else {
            $w[] = $pw; $b = $pb;
            if (!$allCli && $client !== '') { $w[] = "b.Client_Name = ?"; $b[] = $client; }
        }
        $sql = "SELECT b.bom, b.sqty, b.d_id, b.Client_Name, b.processing_state,
                       DATE_FORMAT(b.Created_At,'%Y-%m-%d') created,
                       (SELECT COALESCE(SUM(m2.allocated_qty),0) FROM bom_order_process_map m2 WHERE m2.bom=b.bom) alloc,
                       (SELECT COUNT(*) FROM bom_order_process_map m3 WHERE m3.bom=b.bom) ocnt
                  FROM bom b WHERE " . implode(' AND ', $w) . "
                 ORDER BY b.bom DESC LIMIT $lim";
        $st = $db->prepare($sql); $st->execute($b);
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
        $have = [];
        foreach ($list as $r) $have[(string)$r['bom']] = 1;
        $missing = array_values(array_diff(array_keys($boundIds), array_keys($have)));
        if ($missing) {
            $in = implode(',', array_fill(0, count($missing), '?'));
            $st = $db->prepare("SELECT b.bom, b.sqty, b.d_id, b.Client_Name, b.processing_state,
                                       DATE_FORMAT(b.Created_At,'%Y-%m-%d') created,
                                       (SELECT COALESCE(SUM(m2.allocated_qty),0) FROM bom_order_process_map m2 WHERE m2.bom=b.bom) alloc,
                                       (SELECT COUNT(*) FROM bom_order_process_map m3 WHERE m3.bom=b.bom) ocnt
                                  FROM bom b WHERE b.bom IN ($in)");
            $st->execute($missing);
            $list = array_merge($st->fetchAll(PDO::FETCH_ASSOC), $list);
        }
        foreach ($list as $r) {
            $no = (string)$r['bom'];
            $self = dqa_num($r['sqty']);
            $rows[] = [
                'id' => $no, 'no' => $no, 'date' => dqa_bom_open_date($no, $r['created'] ?? null),
                'part' => (string)$r['d_id'], 'client' => (string)$r['Client_Name'],
                'qty' => $self, 'alloc' => dqa_num($r['alloc']),
                'free' => max(0, $self - dqa_num($r['alloc'])),
                'done' => ((string)$r['processing_state'] === '1'),
                'bound' => isset($boundIds[$no]), 'mine' => $boundIds[$no] ?? 0,
                'used_by' => (int)$r['ocnt'],
                'early' => (dqa_bom_open_date($no, $r['created'] ?? null) !== '' && $odate !== ''
                            && dqa_bom_open_date($no, $r['created'] ?? null) < $odate),
            ];
        }
    } else {   // ship
        $boundIds = [];
        $st = $db->prepare("SELECT IS_id, allocated_qty FROM is_order_map WHERE Order_id=?");
        $st->execute([$orderId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $boundIds[(int)$r['IS_id']] = (int)$r['allocated_qty'];
        $st = $db->prepare("SELECT IS_id, Qty FROM is_list WHERE Order_id=?");
        $st->execute([$orderId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            if (!isset($boundIds[(int)$r['IS_id']])) $boundIds[(int)$r['IS_id']] = (int)$r['Qty'];
        [$pw, $pb] = $mkPart('il.d_setting_id', 'il.Product_id');
        $w = []; $b = [];
        if ($kw !== '') { $w[] = "(il.IS_number LIKE ? OR il.Product_id LIKE ?)"; $b = ['%' . $kw . '%', '%' . $kw . '%']; }
        else {
            $w[] = $pw; $b = $pb;
            if (!$allCli && $client !== '') { $w[] = "il.Client_name = ?"; $b[] = $client; }
        }
        $sql = "SELECT il.IS_id, il.IS_number, il.Qty, il.Unit_price, il.Specification, il.Product_id,
                       il.Client_name, COALESCE(il.anomaly_confirmed,0) ok_flag,
                       DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate,
                       (SELECT COALESCE(SUM(m2.allocated_qty),0) FROM is_order_map m2 WHERE m2.IS_id=il.IS_id) alloc,
                       (SELECT COUNT(*) FROM is_order_map m3 WHERE m3.IS_id=il.IS_id) ocnt,
                       il.Order_id legacy_order
                  FROM is_list il WHERE " . implode(' AND ', $w) . "
                 ORDER BY il.Order_date DESC, il.IS_id DESC LIMIT $lim";
        $st = $db->prepare($sql); $st->execute($b);
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
        $have = [];
        foreach ($list as $r) $have[(int)$r['IS_id']] = 1;
        $missing = array_values(array_diff(array_keys($boundIds), array_keys($have)));
        if ($missing) {
            $in = implode(',', array_fill(0, count($missing), '?'));
            $st = $db->prepare("SELECT il.IS_id, il.IS_number, il.Qty, il.Unit_price, il.Specification,
                                       il.Product_id, il.Client_name, COALESCE(il.anomaly_confirmed,0) ok_flag,
                                       DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate,
                                       (SELECT COALESCE(SUM(m2.allocated_qty),0) FROM is_order_map m2 WHERE m2.IS_id=il.IS_id) alloc,
                                       (SELECT COUNT(*) FROM is_order_map m3 WHERE m3.IS_id=il.IS_id) ocnt,
                                       il.Order_id legacy_order
                                  FROM is_list il WHERE il.IS_id IN ($in)");
            $st->execute($missing);
            $list = array_merge($st->fetchAll(PDO::FETCH_ASSOC), $list);
        }
        foreach ($list as $r) {
            $id = (int)$r['IS_id']; $self = dqa_num($r['Qty']);
            $rows[] = [
                'id' => $id, 'no' => (string)$r['IS_number'], 'date' => (string)$r['sdate'],
                'part' => (string)$r['Product_id'], 'spec' => (string)($r['Specification'] ?? ''),
                'client' => (string)$r['Client_name'],
                'qty' => $self, 'price' => dqa_num($r['Unit_price']),
                'alloc' => dqa_num($r['alloc']), 'free' => max(0, $self - dqa_num($r['alloc'])),
                'bound' => isset($boundIds[$id]), 'mine' => $boundIds[$id] ?? 0,
                'used_by' => (int)$r['ocnt'],
                'other_order' => ((int)$r['legacy_order'] > 0 && (int)$r['legacy_order'] !== $orderId
                                  && !isset($boundIds[$id])) ? (int)$r['legacy_order'] : 0,
                'noprice' => (dqa_num($r['Unit_price']) <= 0 && (int)$r['ok_flag'] !== 1),
                'early' => ($r['sdate'] && $odate && $r['sdate'] < $odate),
            ];
        }
    }

    return ['order' => [
                'order_id' => (int)$o['Order_id'], 'no' => (string)$o['Order_oo'],
                'client' => $client, 'part' => $part, 'd_id' => $did,
                'qty' => dqa_num($o['Qty']), 'price' => dqa_num($o['unit_price']),
                'odate' => $odate, 'ddate' => (string)$o['ddate'],
                'proc' => (string)($o['Processing_items'] ?? ''),
                'closed' => ((string)$o['Order_status'] === '9'),
                'auto_pm' => ((int)$o['pmGet_auto'] === 1),
            ],
            'kind' => $kind, 'rows' => $rows];
}

/* ============================================================
 * 分頁三：報價項目追蹤（報價了有沒有下單／有沒有出貨／有沒有收款）
 *
 * 【為什麼要另外一個分頁】分頁一是**以訂單為主軸**（一張訂單一列），
 * 而「報價了但訂單根本沒建立」的那一列訂單不存在，在分頁一永遠不會出現。
 * 使用者原話：「報價內常有其他治具、刀具...報價，稽核也需要檢查是否有報價但訂單沒有建立，
 * 還是出貨沒有打上去收款」——所以主軸必須換成報價項目。
 *
 * 【判定】
 *   q_no_order   報價超過 no_order_days 天（預設 30）還沒有任何訂單
 *   q_no_ship    有訂單、訂單也結案了，卻查不到出貨
 *   ship_noprice 出貨了卻沒有打單價（收不到款）
 *   amt_zero_quote 報價單價與金額都是 0
 *   備註列（note_only=1）一律不判，只標示。
 * ============================================================ */
function dqa_quote_items(): array
{
    return [
        /* 這一項才是使用者真正要抓的東西：整張報價單**已經成案**（別的列都有訂單了），
           偏偏治具／刀具那一列沒有人去開訂單，於是做了卻收不到錢。 */
        'q_line_no_order' => ['報價已成案，這一列卻沒有訂單', 'critical'],
        /* 整張報價單都沒有訂單＝多半只是沒接到這個案子，不是缺失，所以預設關閉。
           要拿來當「報價未成案清單」時再到「設定」打開。 */
        'q_no_order'      => ['整張報價單都沒有訂單（未成案）', 'off'],
        'q_no_ship'       => ['訂單已結案卻沒有出貨',   'warn'],
        'ship_noprice'    => ['出貨未開價（收不到款）', 'critical'],
        'amt_zero_quote'  => ['報價金額為 0',           'warn'],
    ];
}

function dqa_quote_levels(PDO $db): array
{
    $def = [];
    foreach (dqa_quote_items() as $k => $d) $def[$k] = $d[1];
    $v = dqa_param_get($db, 'quote_items', []);
    if (is_array($v)) foreach ($v as $k => $lv)
        if (isset($def[$k]) && in_array($lv, ['critical', 'warn', 'off'], true)) $def[$k] = $lv;
    return $def;
}

function dqa_quote_rows(PDO $db, array $f): array
{
    $from = dqa_d($f['from'] ?? '') ?: date('Y-01-01');
    $to   = dqa_d($f['to']   ?? '') ?: date('Y-12-31');
    $client = trim((string)($f['client'] ?? ''));
    $part   = trim((string)($f['part'] ?? ''));
    $onlyBad = !empty($f['only_bad']);
    $lim = (int)($f['limit'] ?? 3000);
    if ($lim < 1 || $lim > 20000) $lim = 3000;
    $tol = dqa_tolerance($db);
    $itemLv = dqa_quote_levels($db);
    $exempt = dqa_exempt_map($db, 'quote');
    $exclMap = dqa_excl_map($db, 'trace');      // 排除設定沿用流程稽核那一份（客戶／料號）
    $exclStat = [];
    $today = date('Y-m-d');

    $w = ["ql.quote_date >= :f", "ql.quote_date <= :t", "COALESCE(ql.is_draft,0)=0"];
    $p = [':f' => $from, ':t' => $to];
    if ($client !== '') { $w[] = "ql.client_name LIKE :c"; $p[':c'] = '%' . $client . '%'; }
    if ($part   !== '') { $w[] = "qi.product_id LIKE :pt"; $p[':pt'] = '%' . $part . '%'; }
    $sql = "SELECT qi.item_id, qi.quote_id, qi.product_id, qi.d_setting_d_id, qi.specification,
                   qi.quantity, qi.unit, qi.unit_price, qi.amount,
                   COALESCE(qi.is_tiered,0) is_tiered, COALESCE(qi.note_only,0) note_only,
                   ql.quote_no, ql.client_name, DATE_FORMAT(ql.quote_date,'%Y-%m-%d') qdate
              FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY ql.quote_date DESC, qi.quote_id DESC, qi.sort_order, qi.item_id
             LIMIT :lim";
    $cnt = $db->prepare("SELECT COUNT(*) FROM quotation_item qi
                           JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                          WHERE " . implode(' AND ', $w));
    foreach ($p as $k => $v) $cnt->bindValue($k, $v);
    $cnt->execute();
    $total = (int)$cnt->fetchColumn();

    $st = $db->prepare($sql);
    foreach ($p as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $lim, PDO::PARAM_INT);
    $st->execute();
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return ['rows' => [], 'total' => 0, 'stat' => dqa_trace_stat([]),
                         'scanned' => 0, 'item_total' => 0, 'limit' => $lim, 'tol' => $tol,
                         'items' => dqa_quote_items(), 'levels' => $itemLv, 'excl_rules' => []];

    $ids = array_map(function ($r) { return (int)$r['item_id']; }, $items);
    $procs = dqa_quote_procs($db, $ids);
    $tiers = dqa_quote_tiers($db, $ids);

    /* 這幾列被哪些訂單引用（分配表＋舊欄位） */
    tc_order_quote_ensure($db);
    $ordOf = [];
    foreach (dqa_chunks($ids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $q = $db->prepare("SELECT m.item_id, ot.Order_id, ot.Order_oo, ot.Qty, ot.unit_price, ot.Order_status,
                                  DATE_FORMAT(ot.Order_date,'%Y-%m-%d') odate
                             FROM order_quote_map m JOIN order_track ot ON ot.Order_id=m.Order_id
                            WHERE m.item_id IN ($in)
                            UNION
                           SELECT ot.quote_item_id, ot.Order_id, ot.Order_oo, ot.Qty, ot.unit_price, ot.Order_status,
                                  DATE_FORMAT(ot.Order_date,'%Y-%m-%d')
                             FROM order_track ot WHERE ot.quote_item_id IN ($in)");
        $q->execute(array_merge($ck, $ck));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r)
            $ordOf[(int)$r['item_id']][(int)$r['Order_id']] = $r;
    }
    $oids = [];
    foreach ($ordOf as $m) foreach ($m as $r) $oids[] = (int)$r['Order_id'];

    /* 「整張報價單有沒有成案」要以**整張單**為準，不能只看撈回來這一頁的那幾列——
       同一張報價單的項目可能被 LIMIT 切到下一頁去，只看本頁會誤判成沒成案。 */
    $quoteHasOrder = [];
    $qids2 = array_values(array_unique(array_map(function ($r) { return (int)$r['quote_id']; }, $items)));
    foreach (dqa_chunks($qids2) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        /* 刻意用 JOIN 而不是兩個 EXISTS：EXISTS 那寫法在 order_track 上會逐列重算，
           實測 3,000 列要 12 秒；改成 JOIN（配合 idx_ot_quote_item）之後是毫秒等級。 */
        $q = $db->prepare("SELECT DISTINCT qi.quote_id FROM quotation_item qi
                             JOIN order_quote_map m ON m.item_id = qi.item_id
                            WHERE qi.quote_id IN ($in)
                            UNION
                           SELECT DISTINCT qi.quote_id FROM quotation_item qi
                             JOIN order_track ot ON ot.quote_item_id = qi.item_id
                            WHERE qi.quote_id IN ($in)");
        $q->execute(array_merge($ck, $ck));
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $v) $quoteHasOrder[(int)$v] = 1;
    }

    $shipOf = [];
    foreach (dqa_chunks($oids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $q = $db->prepare("SELECT m.Order_id, il.IS_id, il.IS_number, il.Qty, il.Unit_price,
                                  COALESCE(il.anomaly_confirmed,0) ok_flag,
                                  DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate
                             FROM is_order_map m JOIN is_list il ON il.IS_id=m.IS_id
                            WHERE m.Order_id IN ($in)
                            UNION
                           SELECT il.Order_id, il.IS_id, il.IS_number, il.Qty, il.Unit_price,
                                  COALESCE(il.anomaly_confirmed,0), DATE_FORMAT(il.Order_date,'%Y-%m-%d')
                             FROM is_list il WHERE il.Order_id IN ($in)");
        $q->execute(array_merge($ck, $ck));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $shipOf[(int)$r['Order_id']][(int)$r['IS_id']] = $r;
    }

    $rows = [];
    foreach ($items as $it) {
        $iid = (int)$it['item_id'];
        $qdate = (string)$it['qdate'];
        $noteOnly = !empty($it['note_only']);
        $ords = []; $ships = []; $sQty = 0.0; $noPrice = false; $anyClosed = false;
        foreach ($ordOf[$iid] ?? [] as $r) {
            $closed = ((string)$r['Order_status'] === '9');
            if ($closed) $anyClosed = true;
            $ords[] = ['order_id' => (int)$r['Order_id'], 'no' => (string)$r['Order_oo'],
                       'date' => (string)$r['odate'], 'qty' => dqa_num($r['Qty']),
                       'price' => dqa_num($r['unit_price']), 'closed' => $closed];
            foreach ($shipOf[(int)$r['Order_id']] ?? [] as $sr) {
                $k = (string)$sr['IS_number'];
                if (!isset($ships[$k])) $ships[$k] = ['no' => $k, 'date' => (string)$sr['sdate'],
                                                      'qty' => 0.0, 'price' => dqa_num($sr['Unit_price'])];
                $ships[$k]['qty'] += dqa_num($sr['Qty']);
                $sQty += dqa_num($sr['Qty']);
                if (dqa_num($sr['Unit_price']) <= 0 && (int)$sr['ok_flag'] !== 1) $noPrice = true;
            }
        }
        $ships = array_values($ships);

        $iss = [];
        $add = function (string $code, string $level, string $text) use (&$iss, $itemLv) {
            $cap = $itemLv[$code] ?? $level;
            if ($cap === 'off') return;
            if ($cap === 'warn' && $level === 'critical') $level = 'warn';
            $iss[] = ['code' => $code, 'level' => $level, 'text' => $text];
        };
        if (!$noteOnly) {
            $age = ($qdate !== '') ? (int)round((strtotime($today) - strtotime($qdate)) / 86400) : 0;
            $dealDone = !empty($quoteHasOrder[(int)$it['quote_id']]);
            if (!$ords && $age >= (int)$tol['no_order_days']) {
                if ($dealDone)
                    $add('q_line_no_order', 'critical',
                         '報價單 ' . $it['quote_no'] . ' 其他項目都已經開立訂單，'
                         . '只有這一列（' . $it['product_id'] . '）沒有——治具、刀具這類項目最常漏開，'
                         . '漏了就做了卻收不到錢');
                else
                    $add('q_no_order', 'warn', '報價 ' . $qdate . ' 已 ' . $age
                         . ' 天，整張報價單都查不到訂單（超過 ' . (int)$tol['no_order_days']
                         . ' 天才列出來，可在「設定」調整）');
            }
            if ($ords && !$ships && $anyClosed)
                $add('q_no_ship', 'warn', '訂單已結案卻查不到出貨單');
            if ($noPrice)
                $add('ship_noprice', 'critical', '出貨單沒有打單價，這批貨收不到款');
            if (empty($it['is_tiered']) && dqa_num($it['unit_price']) <= 0 && dqa_num($it['amount']) <= 0)
                $add('amt_zero_quote', 'warn', '這一列的報價單價與金額都是 0');
        }

        /* 排除設定（客戶／料號）與逐筆例外，規則與分頁一完全相同 */
        $hit = dqa_excl_hit($exclMap, ['client' => (string)$it['client_name'],
                                       'part'   => (string)$it['product_id']]);
        $skip = false; $exclNote = [];
        foreach ($hit as $h) {
            $exclStat[$h['id']] = ($exclStat[$h['id']] ?? 0) + 1;
            if ($h['all']) { $skip = true; $exclNote[] = $h; continue; }
            $keep = [];
            foreach ($iss as $x) { if (isset($h['items'][$x['code']])) continue; $keep[] = $x; }
            if (count($keep) !== count($iss)) $exclNote[] = $h;
            $iss = $keep;
        }
        if ($skip) continue;

        $ex = $exempt[(string)$iid] ?? [];
        $exHit = [];
        if (isset($ex['*'])) { $exHit = ['*' => $ex['*']]; $iss = []; }
        else {
            $keep = [];
            foreach ($iss as $x) {
                if (isset($ex[$x['code']])) { $exHit[$x['code']] = $ex[$x['code']]; continue; }
                $keep[] = $x;
            }
            $iss = $keep;
        }

        $level = 'ok';
        foreach ($iss as $x) { if ($x['level'] === 'critical') { $level = 'critical'; break; } $level = 'warn'; }
        if ($onlyBad && $level === 'ok') continue;

        $rows[] = [
            'item_id' => $iid, 'quote_id' => (int)$it['quote_id'],
            'quote_no' => (string)$it['quote_no'], 'qdate' => $qdate,
            'client' => (string)$it['client_name'], 'part' => (string)$it['product_id'],
            'spec' => (string)($it['specification'] ?? ''),
            'qty' => dqa_num($it['quantity']), 'unit' => (string)($it['unit'] ?? ''),
            'price' => dqa_num($it['unit_price']), 'amount' => dqa_num($it['amount']),
            'tiered' => !empty($it['is_tiered']), 'note_only' => $noteOnly,
            'procs' => $procs[$iid] ?? [], 'tiers' => $tiers[$iid] ?? [],
            'orders' => $ords, 'ships' => $ships, 'ship_qty' => $sQty,
            'issues' => $iss, 'level' => $level, 'exempt' => $exHit,
            'excl' => array_map(function ($h) {
                return ['dim' => $h['dim'], 'val' => $h['val'], 'reason' => $h['reason']];
            }, $exclNote),
        ];
    }

    return ['rows' => $rows, 'total' => count($rows), 'stat' => dqa_trace_stat($rows),
            'truncated' => ($total > count($items)), 'scanned' => count($items),
            'item_total' => $total, 'limit' => $lim, 'tol' => $tol,
            'items' => dqa_quote_items(), 'levels' => $itemLv,
            'excl_rules' => dqa_excl_applied($db, 'trace', $exclStat)];
}

/* ============================================================
 * 分頁二：基本資料稽核（客戶／廠商主檔）
 *
 * 【欄位分級】使用者定調：
 *   重要缺失(critical)＝影響聯絡與開立發票、以及「帳務相關結帳資訊一定要有」
 *   一般缺失(major)   ＝該有但不影響日常作業
 *   建議補齊(minor)   ＝有更好
 *   不檢查(off)       ＝傳真、EMAIL 這種「不是每間都會有」的
 * 等級一律可由管理員逐欄調整（鐵律4：不寫死在說明文字裡），這裡只是預設值。
 * ============================================================ */
function dqa_master_types(): array
{
    return ['customer' => '客戶基本資料表', 'maker' => '廠商基本資料表'];
}

/**
 * 欄位定義：code => [顯示名稱, 資料欄位（陣列＝擇一有值即可）, 預設等級, 補充說明]
 * code 同時是「例外」的 item_code，所以改名會讓既有例外失效，不要改。
 */
function dqa_master_fields(string $type): array
{
    if ($type === 'maker') {
        return [
            'code_rule' => ['編號符合編碼原則', [],                     'major',   '編碼原則可在「設定」調整'],
            'name'      => ['廠商簡稱',         ['maker_id'],            'critical', ''],
            'full'      => ['廠商全名',         ['maker_id_all'],        'major',   '開立發票／合約用'],
            'tax'       => ['統一編號',         ['tax_id'],              'critical', '確定不需開發票者可標為例外'],
            'tel'       => ['電話',             ['m_tel', 'm_tel2'],     'critical', '影響聯絡'],
            'addr'      => ['地址',             ['invoice_address', 'factory_address', 'billing_address'], 'critical', '三種地址有其一即可'],
            'contact'   => ['聯絡人',           ['contact_person'],      'major',   ''],
            'settle_mode' => ['結帳方式',       ['settlement_mode'],     'critical', '帳務必要'],
            'settle_day'  => ['結帳日',         ['settlement_day'],      'critical', '帳務必要'],
            'pay_method'  => ['付款方式',       ['payment_method'],      'critical', '帳務必要'],
            'net_days'    => ['付款天數',       ['net_days'],            'major',   ''],
            'category'    => ['廠商類別',       ['m_category', 'main_category_id'], 'major', '影響供應商評鑑分類'],
            'confirmed'   => ['結帳／付款條件已確認', ['confirmed_settlement', 'confirmed_payment'], 'major', '兩項都要打勾才算確認'],
            'email'       => ['EMAIL',          ['email'],               'off',     '不是每間廠商都有'],
            'fax'         => ['傳真',           ['m_fax'],               'off',     '不是每間廠商都有'],
        ];
    }
    return [
        'code_rule' => ['編號符合編碼原則', [],                          'major',   '編碼原則可在「設定」調整'],
        'name'      => ['客戶簡稱',         ['customer'],                 'critical', ''],
        'full'      => ['發票抬頭全名',     ['customer_full'],            'major',   '開立發票用'],
        'tax'       => ['統一編號',         ['tax_id'],                   'critical', '現金交易等確定不需開發票者可標為例外'],
        'tel'       => ['電話',             ['customer_tel'],             'critical', '影響聯絡'],
        'addr'      => ['地址',             ['customer_address'],         'critical', ''],
        'settle_mode' => ['結帳方式',       ['settlement_mode'],          'critical', '帳務必要'],
        'settle_day'  => ['結帳日',         ['settlement_day'],           'critical', '帳務必要'],
        'pay_method'  => ['付款方式',       ['payment_method'],           'critical', '帳務必要'],
        'net_days'    => ['付款天數',       ['net_days'],                 'major',   ''],
        'billing_contact' => ['帳務聯絡人', ['billing_contact'],          'minor',   ''],
        'confirmed'   => ['結帳／付款條件已確認', ['confirmed_settlement', 'confirmed_payment'], 'major', '兩項都要打勾才算確認'],
        'invoice_email' => ['發票寄送EMAIL', ['invoice_email'],           'off',     '不是每間客戶都有'],
        'fax'         => ['傳真',           ['customer_fax'],             'off',     '不是每間客戶都有'],
    ];
}

/** 目前生效的等級設定（預設值 ＋ 管理員調整過的） */
function dqa_master_levels(PDO $db, string $type): array
{
    $def = [];
    foreach (dqa_master_fields($type) as $k => $d) $def[$k] = $d[2];
    $v = dqa_param_get($db, 'fields_' . $type, []);
    if (is_array($v)) foreach ($v as $k => $lv)
        if (isset($def[$k]) && in_array($lv, ['critical', 'major', 'minor', 'off'], true)) $def[$k] = $lv;
    return $def;
}
function dqa_level_label(string $lv): string
{
    return ['critical' => '重要缺失', 'major' => '一般缺失', 'minor' => '建議補齊', 'off' => '不檢查'][$lv] ?? $lv;
}

/**
 * 客戶／廠商逐筆檢核。
 * 停用者一律排除在稽核之外（使用者要求）：客戶 is_inactive=1、廠商 status='X'。
 */
function dqa_master_rows(PDO $db, string $type, array $opt = []): array
{
    $type = ($type === 'maker') ? 'maker' : 'customer';
    $q        = trim((string)($opt['q'] ?? ''));
    $onlyBad  = !empty($opt['only_bad']);
    $incOff   = !empty($opt['include_inactive']);   // 連停用的也看（預設不看）
    $levels   = dqa_master_levels($db, $type);
    $fields   = dqa_master_fields($type);
    $rule     = dqa_code_rule($db);
    $re       = dqa_code_regex($rule);
    $exempt   = dqa_exempt_map($db, $type);
    $exclMap  = dqa_excl_map($db, $type === 'maker' ? 'maker' : 'customer');
    $exclDim  = $type === 'maker' ? 'maker' : 'client';
    $exclStat = [];

    /* ⚠ 欄位不要取別名：檢核是拿 dqa_master_fields() 裡登記的**真實欄位名**去讀這一列，
       取了別名（customer AS nm）就會變成「每一筆都說客戶簡稱未填」而且完全不報錯。 */
    if ($type === 'maker') {
        $keyCol = 'maker_id_no'; $nameCol = 'maker_id';
        $sql = "SELECT maker_id_no, maker_id, maker_id_all, tax_id, m_tel, m_tel2, m_fax,
                       invoice_address, factory_address, billing_address, contact_person, email,
                       settlement_mode, settlement_day, payment_method, net_days,
                       confirmed_settlement, confirmed_payment, m_category, main_category_id,
                       status, internal
                  FROM maker_list";
        $inactiveExpr = function ($r) { return strtoupper(trim((string)($r['status'] ?? ''))) === 'X'; };
    } else {
        $keyCol = 'customer_id'; $nameCol = 'customer';
        $sql = "SELECT customer_id, customer, customer_full, tax_id, customer_tel, customer_fax,
                       customer_address, settlement_mode, settlement_day, payment_method, net_days,
                       billing_contact, invoice_email, confirmed_settlement, confirmed_payment,
                       is_inactive, is_own_company
                  FROM customer_list";
        $inactiveExpr = function ($r) { return (int)($r['is_inactive'] ?? 0) === 1; };
    }
    $all = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // 同名偵測（同一個名稱掛在兩個以上編號）
    $nameCnt = [];
    foreach ($all as $r) {
        if ($inactiveExpr($r)) continue;
        $n = trim((string)$r[$nameCol]);
        if ($n !== '') $nameCnt[$n] = ($nameCnt[$n] ?? 0) + 1;
    }

    $rows = [];
    foreach ($all as $r) {
        $inactive = $inactiveExpr($r);
        if ($inactive && !$incOff) continue;                       // 已停用者不稽核（使用者要求）
        $key = trim((string)$r[$keyCol]);
        $nm  = trim((string)$r[$nameCol]);
        if ($q !== '' && mb_stripos($key . ' ' . $nm, $q) === false) continue;

        // 排除設定：整個客戶／廠商不納入基本資料稽核
        $exclHit = dqa_excl_hit($exclMap, [$exclDim => $nm]);
        if ($exclHit) {
            foreach ($exclHit as $h) $exclStat[$h['id']] = ($exclStat[$h['id']] ?? 0) + 1;
            continue;
        }
        $ex = $exempt[$key] ?? [];
        $iss = [];
        foreach ($fields as $code => $d) {
            $lv = $levels[$code] ?? 'off';
            if ($lv === 'off') continue;
            $ok = true;
            if ($code === 'code_rule') {
                $ok = ($key !== '' && preg_match($re, $key) === 1);
            } elseif ($code === 'confirmed') {
                $ok = true;
                foreach ($d[1] as $c) if ((int)($r[$c] ?? 0) !== 1) { $ok = false; break; }
            } else {
                $ok = false;
                foreach ($d[1] as $c) {
                    $v = $r[$c] ?? null;
                    if ($v !== null && trim((string)$v) !== '' && trim((string)$v) !== '0') { $ok = true; break; }
                }
            }
            if ($ok) continue;
            if (isset($ex[$code]) || isset($ex['*'])) continue;    // 已核可的例外
            $iss[] = ['code' => $code, 'level' => $lv, 'text' => $d[0] . ($code === 'code_rule' ? '不符（' . dqa_code_rule_text($rule) . '）' : '未填')];
        }
        if ($nm !== '' && ($nameCnt[$nm] ?? 0) > 1 && !isset($ex['dup_name']) && !isset($ex['*']))
            $iss[] = ['code' => 'dup_name', 'level' => 'major', 'text' => '名稱「' . $nm . '」有 ' . $nameCnt[$nm] . ' 筆不同編號，請確認是否重複建檔'];

        $level = 'ok';
        foreach ($iss as $it) {
            if ($it['level'] === 'critical') { $level = 'critical'; break; }
            if ($it['level'] === 'major')  $level = 'major';
            elseif ($level === 'ok')       $level = 'minor';
        }
        if ($onlyBad && $level === 'ok') continue;

        $rows[] = ['key' => $key, 'name' => $nm, 'inactive' => $inactive,
                   'issues' => $iss, 'level' => $level, 'exempt' => $ex,
                   'code_ok' => ($key !== '' && preg_match($re, $key) === 1)];
    }
    usort($rows, function ($a, $b) {
        $o = ['critical' => 0, 'major' => 1, 'minor' => 2, 'ok' => 3];
        $c = ($o[$a['level']] ?? 9) - ($o[$b['level']] ?? 9);
        return $c !== 0 ? $c : strcmp($a['key'], $b['key']);
    });

    $stat = ['total' => count($rows), 'critical' => 0, 'major' => 0, 'minor' => 0, 'ok' => 0, 'by_code' => []];
    foreach ($rows as $r) {
        $stat[$r['level']] = ($stat[$r['level']] ?? 0) + 1;
        foreach ($r['issues'] as $it) $stat['by_code'][$it['code']] = ($stat['by_code'][$it['code']] ?? 0) + 1;
    }
    return ['rows' => $rows, 'total' => count($rows), 'stat' => $stat,
            'rule_text' => dqa_code_rule_text($rule), 'levels' => $levels,
            'excl_rules' => dqa_excl_applied($db, $type === 'maker' ? 'maker' : 'customer', $exclStat)];
}

/* ============================================================
 * 稽核結果留存 → 供內部稽核（views/ADM/internal_audit.php）帶出
 *
 * 為什麼要留存而不是讓內部稽核即時重算：重算一次要掃幾千張訂單，
 * 而內部稽核的系統稽核紀錄表一次會列幾十份表單；而且稽核紀錄本來就該是
 * 「那一天查出來的結果」，事後資料被補齊了也不該回頭改寫當時的稽核發現。
 * ============================================================ */
function dqa_run_ensure(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS dqa_run (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tab VARCHAR(20) NOT NULL COMMENT 'trace/master',
        sub_type VARCHAR(20) NULL COMMENT 'master 時為 customer/maker',
        period_from DATE NULL, period_to DATE NULL,
        total INT NOT NULL DEFAULT 0,
        critical_cnt INT NOT NULL DEFAULT 0,
        warn_cnt INT NOT NULL DEFAULT 0,
        scope_json TEXT NULL COMMENT '當次涵蓋的 AS 文件 id',
        stat_json TEXT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        KEY idx_dqa_run (tab, created_at)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='資料稽核：留存的稽核結果（供內部稽核引用）'");
}

function dqa_run_save(PDO $db, array $d, int $uid, string $uname): int
{
    dqa_run_ensure($db);
    $st = $db->prepare("INSERT INTO dqa_run (tab, sub_type, period_from, period_to, total, critical_cnt, warn_cnt,
                                             scope_json, stat_json, note, created_by, created_by_name)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        (string)$d['tab'], (string)($d['sub_type'] ?? ''), dqa_d($d['from'] ?? '') ?: null, dqa_d($d['to'] ?? '') ?: null,
        (int)($d['total'] ?? 0), (int)($d['critical'] ?? 0), (int)($d['warn'] ?? 0),
        json_encode($d['scope'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($d['stat'] ?? [], JSON_UNESCAPED_UNICODE),
        mb_substr((string)($d['note'] ?? ''), 0, 500), $uid, $uname,
    ]);
    return (int)$db->lastInsertId();
}

function dqa_run_list(PDO $db, int $limit = 30): array
{
    dqa_run_ensure($db);
    $st = $db->prepare("SELECT id, tab, sub_type, period_from, period_to, total, critical_cnt, warn_cnt,
                               scope_json, note, created_by_name,
                               DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') created
                          FROM dqa_run ORDER BY id DESC LIMIT :l");
    $st->bindValue(':l', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['scope'] = json_decode((string)$r['scope_json'], true) ?: [];
    return $rows;
}

/**
 * 給內部稽核用：輸入 AS 文件 id，回傳「這份表單最近一次資料稽核的結果」。
 * 沒有留存過就回 null，呼叫端自己決定要不要顯示「尚未稽核」。
 * @return array doc_id => ['tab'=>,'total'=>,'critical'=>,'warn'=>,'at'=>,'by'=>,'period'=>]
 */
function dqa_summary_for_docs(PDO $db, array $docIds): array
{
    $ids = [];
    foreach ($docIds as $x) { $i = (int)$x; if ($i > 0) $ids[$i] = 1; }
    if (!$ids) return [];
    $out = [];
    foreach (dqa_run_list($db, 200) as $r) {
        foreach ($r['scope'] as $d) {
            $d = (int)$d;
            if (!isset($ids[$d]) || isset($out[$d])) continue;      // 已有較新的就不覆蓋
            $out[$d] = ['tab' => (string)$r['tab'], 'sub_type' => (string)$r['sub_type'],
                        'total' => (int)$r['total'], 'critical' => (int)$r['critical_cnt'],
                        'warn' => (int)$r['warn_cnt'], 'at' => (string)$r['created'],
                        'by' => (string)$r['created_by_name'],
                        'period' => trim((string)$r['period_from'] . ' ~ ' . (string)$r['period_to'], ' ~')];
        }
    }
    return $out;
}
}   // if (!function_exists('dqa_ensure_schema'))
