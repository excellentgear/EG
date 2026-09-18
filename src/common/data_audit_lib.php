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
    return ['qty_pct' => $qty, 'price_pct' => $price, 'quote_valid_days' => $days];
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
    $exempt = dqa_exempt_map($db, 'trace');

    /* ── ① 訂單（稽核主軸：一張訂單一列）───────────────── */
    $w = ["ot.Order_date >= :f", "ot.Order_date <= :t"];
    $p = [':f' => $from, ':t' => $to];
    if ($client !== '') { $w[] = "ot.Client_name = :c"; $p[':c'] = $client; }
    if ($part   !== '') { $w[] = "ot.d_id LIKE :pt";    $p[':pt'] = '%' . $part . '%'; }
    $sql = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.d_id, ot.d_id_ID, ot.Qty, ot.unit_price,
                   ot.Processing_items, ot.pmGet_auto, ot.Order_status, ot.quote_no, ot.quote_item_id,
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

    /* ── ② 報價：綁定（quote_item_id）─────────────────── */
    $qBind = [];
    $qids = [];
    foreach ($orders as $o) if ((int)$o['quote_item_id'] > 0) $qids[] = (int)$o['quote_item_id'];
    foreach (dqa_chunks($qids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT qi.item_id, qi.product_id, qi.d_setting_d_id, qi.quantity, qi.unit_price,
                                  qi.process_notes, ql.quote_no, ql.client_name,
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
                                  qi.process_notes, ql.quote_no, ql.client_name,
                                  DATE_FORMAT(ql.quote_date,'%Y-%m-%d') qdate
                             FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                            WHERE (" . implode(' OR ', $w) . ")
                              AND COALESCE(ql.is_draft,0)=0
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
    $mkBom = function (array $r, string $src) use (&$bomSeen) {
        $no = (string)$r['bom'];
        $bomSeen[$no] = 1;
        return ['bom' => $no, 'qty' => dqa_num($r['sqty']), 'src' => $src,
                'date' => dqa_bom_open_date($no, $r['created'] ?? null),
                'alloc' => isset($r['alloc']) ? dqa_num($r['alloc']) : null,
                '_id' => 'b' . $no, '_sort' => dqa_bom_open_date($no, $r['created'] ?? null)];
    };
    foreach (dqa_chunks($oids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT m.order_id, m.allocated_qty alloc, b.bom, b.sqty,
                                  DATE_FORMAT(b.Created_At,'%Y-%m-%d') created
                             FROM bom_order_process_map m JOIN bom b ON b.bom=m.bom
                            WHERE m.order_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $bomByOrder[(int)$r['order_id']][(string)$r['bom']] = $mkBom($r, 'map');
        $s = $db->prepare("SELECT b.o_order_id, b.bom, b.sqty, DATE_FORMAT(b.Created_At,'%Y-%m-%d') created
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
        $s = $db->prepare("SELECT b.bom, b.sqty, b.d_id, b.d_setting_id, b.Client_Name,
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
                'spec' => (string)($r['Specification'] ?? ''), 'src' => $src,
                'alloc' => isset($r['alloc']) ? dqa_num($r['alloc']) : null,
                '_id' => 's' . (int)$r['IS_id'], '_sort' => $d];
    };
    foreach (dqa_chunks($oids) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $s = $db->prepare("SELECT m.Order_id, m.allocated_qty alloc, il.IS_id, il.IS_number, il.Qty,
                                  il.Unit_price, il.Specification, DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate
                             FROM is_order_map m JOIN is_list il ON il.IS_id=m.IS_id
                            WHERE m.Order_id IN ($in)");
        $s->execute($ck);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $shipByOrder[(int)$r['Order_id']][(int)$r['IS_id']] = $mkShip($r, 'map');
        $s = $db->prepare("SELECT il.Order_id, il.IS_id, il.IS_number, il.Qty, il.Unit_price, il.Specification,
                                  DATE_FORMAT(il.Order_date,'%Y-%m-%d') sdate
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
        $s = $db->prepare("SELECT il.IS_id, il.IS_number, il.Qty, il.Unit_price, il.Specification,
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

    /* ── ⑥ 製令的製程（bom_ing → process_no）───────────── */
    $bomProc = [];
    if ($cmpP && $bomSeen) {
        foreach (dqa_chunks(array_keys($bomSeen)) as $ck) {
            $in = implode(',', array_fill(0, count($ck), '?'));
            $s = $db->prepare("SELECT bi.bom, pn.ProcessName FROM bom_ing bi
                                 LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                                WHERE bi.bom IN ($in) AND pn.ProcessName IS NOT NULL");
            $s->execute($ck);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
                $bomProc[(string)$r['bom']][trim((string)$r['ProcessName'])] = 1;
        }
    }
    /* 報價的製程（process_notes 存子標籤 id 清單） */
    $subTagName = [];
    if ($cmpP) {
        try {
            foreach ($db->query("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag")
                        ->fetchAll(PDO::FETCH_ASSOC) as $r)
                $subTagName[(int)$r['sub_tag_id']] = trim((string)$r['sub_tag_name']);
        } catch (Throwable $e) {}
    }
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

        /* 報價 */
        $q = null; $qSrc = '';
        if (!empty($qBind[(int)$o['quote_item_id']])) { $q = $qBind[(int)$o['quote_item_id']]; $qSrc = 'bind'; }
        else {
            // 推測：取報價日不晚於訂單日之中最接近的一筆；全都晚於訂單日就取最早那筆（好讓「報價晚於訂單」被看見）
            $cands = dqa_pick_guess($qGuess, $pkeys);
            foreach ($cands as $cand) {
                $cd = dqa_d($cand['qdate']);
                if ($cd !== '' && $cd <= $odate) $q = $cand;
            }
            if (!$q && $cands) $q = $cands[0];
            if ($q) $qSrc = 'guess';
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
            if (count($bList) < 12) $bList[] = ['no' => $b['bom'], 'date' => $b['date'], 'qty' => $q1];
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
            if (!isset($sDocs[$k])) $sDocs[$k] = ['no' => $k, 'date' => $s2['date'], 'qty' => 0.0];
            $sDocs[$k]['qty'] += $q1;
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

        // ① 報價日 <= 訂單日
        if ($q && $qdate !== '' && $qdate > $odate)
            $add('q_late', $lv($qSrc === 'bind' ? 'map' : 'guess'),
                 '報價日 ' . $qdate . ' 晚於訂單日 ' . $odate . $sfx($qSrc === 'bind' ? 'map' : 'guess'));
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

        // ④ 數量（只有「綁定」才判不符；推測配對本來就配不準，硬判會滿畫面假警報）
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
        $qprice = $q ? dqa_num($q['unit_price']) : 0.0;
        $qAgeDays = ($q && $qdate !== '') ? (int)round((strtotime($odate) - strtotime($qdate)) / 86400) : -1;
        $qFresh = ($qSrc === 'bind') || ($qAgeDays >= 0 && $qAgeDays <= $validDays);
        if ($q && $qAgeDays > $validDays)
            $add('q_old', 'warn', '最近一次報價是 ' . $qdate . '（距下單 ' . $qAgeDays . ' 天），已逾 ' . $validDays . ' 天未重新報價');
        if ($q && $qFresh && $qprice > 0 && $oprice > 0 && dqa_diff_over($qprice, $oprice, $tol['price_pct']))
            $add('price_q', 'warn', '報價單價 ' . dqa_n($qprice) . ' 與訂單單價 ' . dqa_n($oprice) . ' 不符' . $sfx($qSrc === 'bind' ? 'map' : 'guess'));
        if ($sPrice !== null && $sPrice > 0 && $oprice > 0 && $isBind($shipSrc)
            && dqa_diff_over($oprice, $sPrice, $tol['price_pct']))
            $add('price_s', 'warn', '訂單單價 ' . dqa_n($oprice) . ' 與出貨單價 ' . dqa_n($sPrice) . ' 不符');

        // ⑥ 製程（可關閉）：訂單是手打文字、出貨是與規格混打，所以抽關鍵詞比集合
        $pOrder = $pQuote = $pBom = $pShip = [];
        $pCmp = '';
        if ($cmpP) {
            $pOrder = dqa_proc_extract((string)$o['Processing_items'], $terms);
            if ($q) {
                // process_notes 存的是子標籤 id 清單，但既有資料有純數字、有陣列、也有物件，
                // 直接 foreach 解碼結果會在「單一數字」時炸掉（json_decode 回 int）。
                $pn = json_decode((string)($q['process_notes'] ?? ''), true);
                if (is_numeric($pn)) $pn = [$pn];
                if (!is_array($pn))  $pn = [];
                $names = [];
                foreach ($pn as $x) {
                    $id = is_array($x) ? (int)($x['sub_tag_id'] ?? 0) : (int)$x;
                    if ($id > 0 && isset($subTagName[$id])) $names[] = $subTagName[$id];
                }
                $pQuote = dqa_proc_extract(implode(' ', $names), $terms);
            }
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
            'quote' => $q ? ['no' => (string)$q['quote_no'], 'date' => $qdate,
                             'qty' => dqa_num($q['quantity']), 'price' => $qprice, 'src' => $qSrc] : null,
            'bom'   => ['cnt' => count($boms), 'qty' => $bQty, 'date' => $bMin, 'date_max' => $bMax,
                        'src' => $bomSrc, 'list' => $bList],
            'ship'  => ['cnt' => count($ships), 'doc_cnt' => count($sList), 'qty' => $sQty,
                        'date' => $sMin, 'date_max' => $sMax,
                        'price' => $sPrice, 'src' => $shipSrc, 'list' => $sList],
            'proc'  => ['quote' => $pQuote, 'order' => $pOrder, 'bom' => $pBom, 'ship' => $pShip, 'cmp' => $pCmp],
            'issues' => $iss, 'level' => $level, 'exempt' => $exHit,
        ];
    }

    return ['rows' => $rows, 'total' => count($rows), 'stat' => dqa_trace_stat($rows),
            'truncated' => ($orderTotal > count($orders)), 'scanned' => count($orders),
            'order_total' => $orderTotal, 'limit' => $lim, 'tol' => $tol];
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
        'qty_bom'       => ['製令數量不符',     'warn'],
        'qty_over'      => ['出貨超出訂單量',   'critical'],
        'qty_ship'      => ['出貨數量不符',     'warn'],
        'price_q'       => ['報價與訂單單價不符', 'warn'],
        'price_s'       => ['訂單與出貨單價不符', 'warn'],
        'proc_q'        => ['報價與訂單製程不同', 'warn'],
        'proc_b'        => ['訂單與製令製程不同', 'warn'],
    ];
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
            'rule_text' => dqa_code_rule_text($rule), 'levels' => $levels];
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
