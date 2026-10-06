<?php
/**
 * order_analysis_lib.php — 訂單分析（訂單追蹤頁的分析報表）唯一實作
 * 建立：2026-09-22（使用者交辦）
 *
 * 使用端：views/Sales/Order_Analysis.php（畫面）／src/store/OrderAnalysis_API.php（資料）
 * 入口：views/Sales/NewOrder_Track.php 工具列「訂單分析」按鈕（只多一顆按鈕，不動既有邏輯）
 *
 * ── 這一頁在回答什麼 ──
 *   ① 本期有多少「新訂單」＝該料號在系統裡是第一次出現（沒有更早的出貨／訂單／製令／退貨）
 *   ② 訂單金額與筆數的趨勢（月／季／半年／整年四種粒度）
 *   ③ 全製／單製各佔多少
 *   ④ 各「數量區間」佔多少筆（區間由管理員設定）
 *   ⑤ 多選客戶做比較表
 *   ⑥ 期間內客戶的增減排名、受訂料號排名
 *
 * ══════════════════════════════════════════════════════════════════════
 * 六個一定要先知道、不然數字會被當成「程式壞掉」的資料事實
 * ══════════════════════════════════════════════════════════════════════
 * 1)【訂單狀態的代碼】order_track.Order_status：**6＝暫停/取消、9＝已結案**
 *    （依 NewOrder_Track.php 的 toggleOrderStatus()：按「訂單暫停/取消」寫 6、按「訂單完結」寫 9）。
 *    所以本庫預設**排除 6（那不是實際接到的單）、計入 9（那是正常做完的單）**。
 *    ※ 注意 kpi_as_lib.php／client_quarter_lib.php 把 9 當成「已取消」排除掉，兩邊口徑不同；
 *      那是既有模組的判定，本庫不去動它，但畫面上一定要寫明本頁用的是哪一種。
 *
 * 2)【訂單金額只算得出「有填單價」的訂單】實測 2024 年 2,922 張只有 9 張有單價、
 *    2025 年 3,628 張只有 11 張、2026 年 2,893 張有 1,970 張（68%）。
 *    所以每一個金額數字都要同時回報 amount_orders/orders 的覆蓋率，
 *    舊年度的金額就是一排 0——那是資料沒填，不是沒接單。數量（支數）則是完整的，
 *    要看長期趨勢請看「數量」而不是「金額」。
 *
 * 3)【料號歸戶一律用料號主檔 id】order_track.d_id_ID（2026 年 99.97% 有值）。
 *    同一個料號文字在 d_setting 常常分屬好幾家客戶（全站 1,670 組同名料號），
 *    只比文字會把兩家客戶的料號算成同一支。沒有主檔 id 時才退回「客戶＋料號文字」。
 *
 * 4)【客戶歸戶沿用 client_quarter_lib 的 cqa_client_resolver()】
 *    （綁定的客戶主檔 id → 料號主檔 Customer_Id → 名稱含別名 acc_customer_by_name()）。
 *    不要在這裡再寫一份，否則「高鋒工業」與「高鋒」會被算成兩家。
 *
 * 5)【新料號判定的資料視界】四個來源各自最早的資料日期不同（畫面會即時印出來）：
 *    出貨 is_list 2020 起、退貨 ir_track 2018 起、訂單 order_track 2024 起、製令 bom 依編號回推。
 *    在那之前的歷史這套系統裡沒有，所以「新料號」的意思是**「在系統現有資料裡第一次出現」**，
 *    不等於「公司從來沒做過」。這句話一定要印在畫面上。
 *
 * 6)【製令開立日用編號回推不是 Created_At】bom.Created_At 是 ERP 匯入這套系統的時間。
 *    一律呼叫 data_audit_lib.php 的 dqa_bom_open_date()（全站唯一實作），不要自己再寫一次。
 *
 * ── 進行中的期間 ──
 *   拿「還沒過完的本期」去跟完整的去年同期比，整批客戶都會看起來在衰退。
 *   所以比較基期預設也只算到同樣的天數（align=1，與 client_quarter_lib 的 cap_days 同一個道理）。
 */

require_once __DIR__ . '/client_quarter_lib.php';   // cqa_client_resolver()：客戶歸戶（含別名）
require_once __DIR__ . '/data_audit_lib.php';       // dqa_bom_open_date()：製令開立日（編號回推）
require_once __DIR__ . '/order_as_tag_lib.php';     // ot_astag_for_orders()：稽核製程標籤（AS 認定，唯一依據）

if (!defined('OA_PARAM_GROUP')) define('OA_PARAM_GROUP', 'ORDER_ANALYSIS');

/* ══════════════════════════════════════════════════════════════════
 * 設定值（system_parameters，刻意不建資料表：清單很小，而且 DDL 在交易中會隱式 commit）
 * ══════════════════════════════════════════════════════════════════ */
function oa_param_get(PDO $db, string $key, $default)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([OA_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}
function oa_param_save(PDO $db, string $key, $val, string $by = ''): void
{
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
    $st->execute([OA_PARAM_GROUP, $key]);
    $rid = $st->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$json, $by, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at)
                      VALUES (?,?,?,?,?,NOW())")
           ->execute([OA_PARAM_GROUP, $key, $json, '訂單分析：' . $key, $by]);
    }
}

/* ══════════════════════════════════════════════════════════════════
 * 期間（月／季／半年／整年）
 * ══════════════════════════════════════════════════════════════════ */
function oa_grans(): array
{
    return ['month' => '月', 'quarter' => '季', 'half' => '半年', 'year' => '整年'];
}
function oa_date_bases(): array
{
    return ['order' => '下單日（接單日）', 'delivery' => '交期'];
}
function oa_compares(): array
{
    return ['yoy' => '去年同期', 'prev' => '上一期'];
}
/** 該年度依粒度切出來的全部期別（趨勢圖的 X 軸就是它） */
function oa_period_buckets(int $year, string $gran): array
{
    $out = [];
    $mk = function (int $i, string $label, int $m1, int $m2) use ($year) {
        $start = sprintf('%04d-%02d-01', $year, $m1);
        $end   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $m2)));
        return ['idx' => $i, 'label' => $label, 'start' => $start, 'end' => $end, 'year' => $year];
    };
    switch ($gran) {
        case 'year':
            $out[] = $mk(1, $year . ' 整年', 1, 12);
            break;
        case 'half':
            $out[] = $mk(1, $year . ' 上半年', 1, 6);
            $out[] = $mk(2, $year . ' 下半年', 7, 12);
            break;
        case 'quarter':
            for ($q = 1; $q <= 4; $q++) $out[] = $mk($q, $year . ' Q' . $q, ($q - 1) * 3 + 1, $q * 3);
            break;
        default:
            for ($m = 1; $m <= 12; $m++) $out[] = $mk($m, $year . '/' . $m . '月', $m, $m);
            break;
    }
    return $out;
}
/** 取指定期別；idx 超出範圍時取最後一個 */
function oa_period_pick(int $year, string $gran, int $idx): array
{
    $bs = oa_period_buckets($year, $gran);
    foreach ($bs as $b) if ((int)$b['idx'] === $idx) return $b;
    return $bs[count($bs) - 1];
}
/** 比較基期：去年同期（yoy）或上一期（prev） */
function oa_compare_period(int $year, string $gran, int $idx, string $cmp): array
{
    if ($cmp === 'prev') {
        $n = count(oa_period_buckets($year, $gran));
        if ($idx > 1) return oa_period_pick($year, $gran, $idx - 1);
        return oa_period_pick($year - 1, $gran, $n);          // 本年第一期的上一期＝去年最後一期
    }
    return oa_period_pick($year - 1, $gran, $idx);
}
/**
 * 進行中的期間：回傳這一期「已經過了幾天」（整期都過完則回 null＝不必對齊）。
 * 拿半期去比完整的一期，整批客戶都會看起來在衰退，所以基期也要截到同樣天數。
 */
function oa_elapsed_days(array $period, ?string $today = null): ?int
{
    $today = $today ?: date('Y-m-d');
    if ($today > $period['end'])   return null;                 // 已結束
    if ($today < $period['start']) return 0;                    // 還沒開始
    return (int)floor((strtotime($today) - strtotime($period['start'])) / 86400) + 1;
}
/** 把一個期別截到「從第一天起的前 N 天」 */
function oa_cap_period(array $period, ?int $days): array
{
    if ($days === null) return $period;
    if ($days <= 0) { $period['end'] = date('Y-m-d', strtotime($period['start'] . ' -1 day')); return $period; }
    $end = date('Y-m-d', strtotime($period['start'] . ' +' . ($days - 1) . ' day'));
    if ($end < $period['end']) $period['end'] = $end;
    return $period;
}

/* ══════════════════════════════════════════════════════════════════
 * 數量區間（管理員可設定）
 * ══════════════════════════════════════════════════════════════════ */
function oa_qty_bands_default(): array
{
    // 預設值依 2026 年實際訂單分布歸納（1~5 佔 768 筆最多、1001 以上 101 筆）
    return [
        ['label' => '1~5',       'min' => 1,    'max' => 5],
        ['label' => '6~10',      'min' => 6,    'max' => 10],
        ['label' => '11~30',     'min' => 11,   'max' => 30],
        ['label' => '31~50',     'min' => 31,   'max' => 50],
        ['label' => '51~100',    'min' => 51,   'max' => 100],
        ['label' => '101~300',   'min' => 101,  'max' => 300],
        ['label' => '301~1000',  'min' => 301,  'max' => 1000],
        ['label' => '1001 以上', 'min' => 1001, 'max' => null],
    ];
}
function oa_qty_bands(PDO $db): array
{
    $rows = oa_param_get($db, 'qty_bands', null);
    if (!is_array($rows) || !$rows) return oa_qty_bands_default();
    $n = oa_qty_bands_norm($rows);
    return $n['bands'] ?: oa_qty_bands_default();
}
/**
 * 區間正規化＋驗證（存檔與讀取共用同一份規則，否則兩條路遲早走鐘）
 * 回傳 ['bands'=>..., 'errors'=>[...]]；errors 不為空時呼叫端一律擋下不存。
 */
function oa_qty_bands_norm(array $rows): array
{
    $out = []; $err = [];
    foreach ($rows as $i => $r) {
        $label = trim((string)($r['label'] ?? ''));
        $minIn = $r['min'] ?? null;
        $maxIn = $r['max'] ?? null;
        $min   = ($minIn === '' || $minIn === null) ? 0 : (int)$minIn;
        $max   = ($maxIn === '' || $maxIn === null) ? null : (int)$maxIn;
        if ($label === '' && $minIn === null && $maxIn === null) continue;   // 整列空白＝使用者加了列沒填
        if ($min < 0)                     $err[] = '第 ' . ($i + 1) . ' 列：下限不可小於 0';
        if ($max !== null && $max < $min) $err[] = '第 ' . ($i + 1) . ' 列：上限不可小於下限';
        if ($label === '') $label = $max === null ? ($min . ' 以上') : ($min . '~' . $max);
        $out[] = ['label' => mb_substr($label, 0, 30, 'UTF-8'), 'min' => $min, 'max' => $max];
    }
    if (!$out) { $err[] = '至少要有一個數量區間'; return ['bands' => [], 'errors' => $err]; }
    usort($out, function ($a, $b) { return $a['min'] <=> $b['min']; });
    // 重疊檢查：重疊時同一張訂單會被算進兩個區間，佔比加起來超過 100%
    for ($i = 1; $i < count($out); $i++) {
        $prevMax = $out[$i - 1]['max'];
        if ($prevMax === null) { $err[] = '「' . $out[$i - 1]['label'] . '」沒有上限，後面不可以再有區間'; break; }
        if ((int)$out[$i]['min'] <= (int)$prevMax) {
            $err[] = '「' . $out[$i - 1]['label'] . '」與「' . $out[$i]['label'] . '」的範圍重疊';
        }
    }
    return ['bands' => $out, 'errors' => $err];
}
/** 這個數量落在第幾個區間；都不符合回 -1（畫面歸到「未涵蓋」） */
function oa_band_index(int $qty, array $bands): int
{
    foreach ($bands as $i => $b) {
        if ($qty < (int)$b['min']) continue;
        if ($b['max'] !== null && $qty > (int)$b['max']) continue;
        return (int)$i;
    }
    return -1;
}

/* ══════════════════════════════════════════════════════════════════
 * 全製／單製判定（關鍵字規則，管理員可設定）
 *
 * 為什麼是關鍵字而不是某個欄位：order_track.Processing_items 是**手打的自由文字**
 * （實測 2026 年有 900 多種寫法：代料成品／代料完成／代料到完成/S45C／全製 (齒研)…），
 * 系統裡沒有任何一個欄位記著「這張單是全製還是單製」。報價單那邊雖然有
 * process_group_type，但 2026 年只有 105 張訂單綁得到報價項目，完全不夠用。
 * 所以做成**可設定的關鍵字規則**，並把每條規則命中幾筆印在設定畫面上讓管理員自己校正。
 * ══════════════════════════════════════════════════════════════════ */
function oa_proc_classes(): array
{
    return ['full' => '全製', 'single' => '單製', 'unknown' => '無法判定', 'none' => '未填製程'];
}
function oa_proc_rules_default(): array
{
    // 依序比對、先命中先算。實測 2026 年：全製字 206 筆、代料 907 筆、成品/完成 84 筆，其餘 1,696 筆歸單製
    return [
        ['label' => '寫明全製',       'kw' => '全製',      'cls' => 'full'],
        ['label' => '代料（含材料）', 'kw' => '代料',      'cls' => 'full'],
        ['label' => '做到成品/完成',  'kw' => '成品|完成', 'cls' => 'full'],
    ];
}
function oa_proc_rules(PDO $db): array
{
    $rows = oa_param_get($db, 'proc_rules', null);
    if (!is_array($rows) || !$rows) return oa_proc_rules_default();
    $n = oa_proc_rules_norm($rows);
    return $n['rules'] ?: oa_proc_rules_default();
}
/** 沒有命中任何規則時歸到哪一類（預設單製；管理員可改成「無法判定」不硬歸類） */
function oa_proc_fallback(PDO $db): string
{
    $v = (string)oa_param_get($db, 'proc_fallback', 'single');
    return in_array($v, ['full', 'single', 'unknown'], true) ? $v : 'single';
}
function oa_proc_rules_norm(array $rows): array
{
    $out = []; $err = [];
    foreach ($rows as $i => $r) {
        $kw  = trim((string)($r['kw'] ?? ''));
        $cls = (string)($r['cls'] ?? 'full');
        if ($kw === '') continue;                                  // 空白列直接略過（使用者按了加列又沒填）
        if (!in_array($cls, ['full', 'single'], true)) $err[] = '第 ' . ($i + 1) . ' 列：分類只能是全製或單製';
        $label = trim((string)($r['label'] ?? ''));
        if ($label === '') $label = $kw;
        $out[] = ['label' => mb_substr($label, 0, 30, 'UTF-8'), 'kw' => mb_substr($kw, 0, 120, 'UTF-8'), 'cls' => $cls];
    }
    if (!$out) $err[] = '至少要有一條關鍵字規則';
    return ['rules' => $out, 'errors' => $err];
}
/**
 * 判定一筆訂單的製程分類。
 * 關鍵字語法與 quote_kw_rule_lib 一致：逗號分隔＝全部都要含；單一關鍵字內用「|」＝任一即可。
 * 回傳 ['cls'=>'full|single|none|unknown', 'rule'=>命中的規則名稱或空字串]
 */
function oa_proc_class(string $text, array $rules, string $fallback = 'single'): array
{
    $t = trim($text);
    if ($t === '') return ['cls' => 'none', 'rule' => ''];
    foreach ($rules as $r) {
        $ok = true;
        foreach (explode(',', (string)$r['kw']) as $grp) {
            $grp = trim($grp);
            if ($grp === '') continue;
            $hit = false;
            foreach (explode('|', $grp) as $one) {
                $one = trim($one);
                if ($one !== '' && mb_stripos($t, $one, 0, 'UTF-8') !== false) { $hit = true; break; }
            }
            if (!$hit) { $ok = false; break; }
        }
        if ($ok) return ['cls' => (string)$r['cls'], 'rule' => (string)$r['label']];
    }
    return ['cls' => $fallback, 'rule' => ''];
}

/* ══════════════════════════════════════════════════════════════════
 * 料號「第一次出現」的日期（新訂單判定的唯一依據）
 *
 * 四個來源各取該料號最早的一筆：出貨 is_list／訂單 order_track／製令 bom／退貨 ir_track。
 * 一律以**料號主檔 id** 為鍵（is_list 與 ir_track 100% 有值、order_track 2026 年 99.97%）。
 * 製令有 8,348 筆沒有綁主檔 id，改用料號文字回查主檔；**文字對到好幾筆主檔時一律全部都算**
 * ——這個方向是保守的（會讓「其實有舊製令」的料號不被誤判成新料號），
 * 寧可少報幾筆新料號，也不要報出根本不新的。
 * ══════════════════════════════════════════════════════════════════ */
function oa_first_seen(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $map = [];      // pid => ['d'=>'YYYY-MM-DD', 's'=>來源代碼]
    $horizon = [];  // 來源代碼 => 該來源最早的資料日期（畫面要印出「資料視界」）
    $put = function ($pid, $d, $s) use (&$map, &$horizon) {
        $pid = (int)$pid;
        if ($pid <= 0) return;
        $d = substr((string)$d, 0, 10);
        if ($d === '' || $d < '1990-01-01' || $d > '2100-12-31') return;
        if (!isset($map[$pid]) || $d < $map[$pid]['d']) $map[$pid] = ['d' => $d, 's' => $s];
        if (!isset($horizon[$s]) || $d < $horizon[$s]) $horizon[$s] = $d;
    };

    // 出貨（is_list.Order_date 就是出貨日期）
    try {
        foreach ($db->query("SELECT d_setting_id pid, MIN(DATE(Order_date)) d FROM is_list
                             WHERE d_setting_id IS NOT NULL AND d_setting_id>0 GROUP BY d_setting_id") as $r) {
            $put($r['pid'], $r['d'], 'ship');
        }
    } catch (Throwable $e) {}

    // 訂單（含已取消／已結案：那些單照樣證明這支料號以前就做過）
    try {
        foreach ($db->query("SELECT d_id_ID pid, MIN(Order_date) d FROM order_track
                             WHERE d_id_ID IS NOT NULL AND d_id_ID>0 GROUP BY d_id_ID") as $r) {
            $put($r['pid'], $r['d'], 'order');
        }
    } catch (Throwable $e) {}

    // 退貨
    try {
        foreach ($db->query("SELECT d_setting_id pid, MIN(IR_date) d FROM ir_track
                             WHERE d_setting_id IS NOT NULL AND d_setting_id>0 GROUP BY d_setting_id") as $r) {
            $put($r['pid'], $r['d'], 'ir');
        }
    } catch (Throwable $e) {}

    // 製令：開立日一律用編號回推（Created_At 是 ERP 匯入這台系統的時間，不是現場開立日）
    try {
        $sql = "SELECT b.bom, b.Created_At, COALESCE(NULLIF(b.d_setting_id,0), ds.d_id) AS pid
                  FROM bom b
                  LEFT JOIN d_setting ds
                         ON (b.d_setting_id IS NULL OR b.d_setting_id=0) AND ds.D_Setting_Id = b.d_id";
        foreach ($db->query($sql) as $r) {
            if (!$r['pid']) continue;
            $put($r['pid'], dqa_bom_open_date((string)$r['bom'], $r['Created_At']), 'bom');
        }
    } catch (Throwable $e) {}

    // 料號主檔「同一支料號被建了兩筆」的補救：同樣的料號文字、同一家客戶、不同主檔 id，
    // 其中一筆有 2021 年的出貨、另一筆卻是今年才建的——只比主檔 id 會把它判成新料號。
    // 實測 2026 年 905 支新料號裡有 14 支是這種情況（1.5%）。
    // **料號文字掛在兩家以上客戶底下時刻意不併**（那本來就是不同客戶的不同料號，見 bom_client_lib 同一條）。
    $txt = [];
    try {
        $grp = [];   // 料號文字 => ['cust'=>[客戶編號=>1], 'pids'=>[...]]
        foreach ($db->query("SELECT d_id, D_Setting_Id, Customer_Id FROM d_setting") as $r) {
            $k = trim((string)$r['D_Setting_Id']);
            if ($k === '') continue;
            if (!isset($grp[$k])) $grp[$k] = ['cust' => [], 'pids' => []];
            $c = trim((string)($r['Customer_Id'] ?? ''));
            if ($c !== '' && $c !== '0') $grp[$k]['cust'][$c] = 1;
            $grp[$k]['pids'][] = (int)$r['d_id'];
        }
        foreach ($grp as $k => $g) {
            if (count($g['cust']) > 1) continue;              // 同名料號分屬多家客戶 → 不可併
            if (count($g['pids']) < 2) continue;              // 只有一筆主檔 → 併不併都一樣
            $best = null;
            foreach ($g['pids'] as $pid) {
                if (!isset($map[$pid])) continue;
                if ($best === null || $map[$pid]['d'] < $best['d']) $best = $map[$pid];
            }
            if ($best !== null) $txt[$k] = $best;
        }
    } catch (Throwable $e) {}

    $cache = ['map' => $map, 'txt' => $txt, 'horizon' => $horizon];
    return $cache;
}
/**
 * 這一筆訂單的料號「第一次出現」是什麼時候（找不到回 null＝這支料號查不到任何歷史）。
 * 先看料號主檔 id，再看「同名同客戶的另一筆主檔」（料號被重複建立時的補救）。
 */
function oa_part_first(array $fs, int $pid, string $pno): ?array
{
    $a = ($pid > 0 && isset($fs['map'][$pid])) ? $fs['map'][$pid] : null;
    $b = ($pno !== '' && isset($fs['txt'][$pno])) ? $fs['txt'][$pno] : null;
    if ($a === null) return $b;
    if ($b === null) return $a;
    return ($b['d'] < $a['d']) ? $b : $a;
}
function oa_source_labels(): array
{
    return ['ship' => '出貨', 'order' => '訂單', 'bom' => '製令', 'ir' => '退貨'];
}

/**
 * 客戶「第一次出現」的日期（真正的新客戶判定唯一依據，2026-09-24 新增）。
 *
 * 背景：原本客戶比較表把「基期 0、本期有」直接標成「新客戶」，但那只是跟**比較基期**比，
 * 不是跟客戶在系統裡的完整歷史比——實測 2026 Q3 有 41 家被標新客戶，其中只有 10 家是真的
 * 系統裡第一次出現，另外 31 家（如倉佑 2024-01-04、錡夆 2024-02-27 就下過單）只是**去年同期
 * 剛好沒下單、這期又回來**，那應該叫「回流客戶」不是「新客戶」，兩者對業務的意義完全不同
 * （新客戶要問「怎麼開發到的」，回流客戶要問「之前為什麼停了、現在為什麼又回來」）。
 *
 * 來源刻意只取**出貨／訂單／退貨**三個——客戶關係的起點一定是報價或下單，不會是製令
 * （製令的客戶還要透過料號或訂單反推，作為「這家客戶何時開始往來」的依據不夠直接，
 * 且 bom_client_lib.php 已經記過料號文字辨識客戶本身就不可靠，不重複踩那個坑）。
 */
function oa_client_first_seen(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $resolve = cqa_client_resolver($db);
    $map = [];   // ckey => ['d'=>'YYYY-MM-DD', 's'=>來源代碼]
    $put = function ($cid, $name, $d, $s) use (&$map, $resolve) {
        $d = substr((string)$d, 0, 10);
        if ($d === '' || $d < '1990-01-01' || $d > '2100-12-31') return;
        $c = $resolve($cid, $name);
        $k = $c['key'];
        if (!isset($map[$k]) || $d < $map[$k]['d']) $map[$k] = ['d' => $d, 's' => $s];
    };

    try {
        foreach ($db->query("SELECT Client_id, Client_name, MIN(DATE(Order_date)) d FROM is_list
                             GROUP BY Client_id, Client_name") as $r) {
            $put($r['Client_id'], $r['Client_name'], $r['d'], 'ship');
        }
    } catch (Throwable $e) {}

    try {
        foreach ($db->query("SELECT Client_name_ID, Client_name, MIN(Order_date) d FROM order_track
                             GROUP BY Client_name_ID, Client_name") as $r) {
            $put($r['Client_name_ID'], $r['Client_name'], $r['d'], 'order');
        }
    } catch (Throwable $e) {}

    try {
        // ir_track 沒有客戶編號欄位（見 client_quarter_lib.php 的說明），只能傳名稱
        foreach ($db->query("SELECT Client_name, MIN(IR_date) d FROM ir_track GROUP BY Client_name") as $r) {
            $put('', $r['Client_name'], $r['d'], 'ir');
        }
    } catch (Throwable $e) {}

    $cache = $map;
    return $cache;
}

/* ══════════════════════════════════════════════════════════════════
 * 取訂單並正規化（客戶歸戶、料號歸戶、金額、全製／單製）
 * ══════════════════════════════════════════════════════════════════ */
function oa_fetch_orders(PDO $db, string $from, string $to, array $opt = []): array
{
    $dateCol  = (($opt['basis'] ?? 'order') === 'delivery') ? 'Delivery_date' : 'Order_date';
    $incPause = !empty($opt['include_paused']);
    $rules    = $opt['rules']    ?? oa_proc_rules($db);
    $fallback = $opt['fallback'] ?? oa_proc_fallback($db);
    $resolve  = $opt['resolver'] ?? cqa_client_resolver($db);

    // Order_status：6＝暫停/取消（預設排除）、9＝已結案（一律計入，那是正常做完的單）
    // Created_By 雖然欄位型態是 varchar(11)，實際存的是 user.id（比照 NewOrder_Track.php 既有
    // 「LEFT JOIN user AS creator ON creator.id = ot.Created_By」同一種接法，不另發明對照方式）。
    $sql = "SELECT ot.Order_id, ot.Order_oo, ot.C_order, ot.`$dateCol` AS dt, ot.Order_date, ot.Delivery_date,
                   ot.Client_name, ot.Client_name_ID, ot.d_id, ot.d_id_ID, ot.Qty, ot.unit_price,
                   ot.Processing_items, ot.Order_status, ds.Customer_Id AS part_cust,
                   ot.Created_By, ot.Created_At, creator.user_cname AS creator_name
              FROM order_track ot
              LEFT JOIN d_setting ds ON ds.d_id = ot.d_id_ID
              LEFT JOIN user creator ON creator.id = ot.Created_By
             WHERE ot.`$dateCol` BETWEEN ? AND ?";
    if (!$incPause) $sql .= " AND (ot.Order_status IS NULL OR ot.Order_status <> 6)";
    $st = $db->prepare($sql);
    $st->execute([$from, $to]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty   = (int)$r['Qty'];
        $price = (float)($r['unit_price'] ?? 0);
        $pid   = (int)($r['d_id_ID'] ?? 0);
        $pno   = trim((string)$r['d_id']);

        // 客戶歸戶：綁定的客戶編號 → 料號主檔的客戶 → 客戶名稱（含別名），與 client_quarter_lib 同一套
        $cid = trim((string)($r['Client_name_ID'] ?? ''));
        if ($cid === '' || $cid === '0') $cid = trim((string)($r['part_cust'] ?? ''));
        $c = $resolve($cid, (string)$r['Client_name']);

        $pc = oa_proc_class((string)($r['Processing_items'] ?? ''), $rules, $fallback);

        $out[] = [
            'id'      => (int)$r['Order_id'],
            'no'      => (string)$r['Order_oo'],
            'c_order' => (string)($r['C_order'] ?? ''),
            'dt'      => substr((string)$r['dt'], 0, 10),
            'odate'   => substr((string)$r['Order_date'], 0, 10),
            'ddate'   => substr((string)$r['Delivery_date'], 0, 10),
            'qty'     => $qty,
            'price'   => $price,
            'amount'  => $price > 0 ? $qty * $price : 0.0,
            'haspx'   => $price > 0 ? 1 : 0,
            'ckey'    => $c['key'],
            'cname'   => $c['name'],
            'cid'     => (string)$c['cid'],
            'cbad'    => $c['unmatched'] ? 1 : 0,
            'pid'     => $pid,
            'pno'     => $pno !== '' ? $pno : '（未填料號）',
            'pkey'    => $pid > 0 ? ('D' . $pid) : ('N:' . $c['key'] . '|' . $pno),
            'proc'    => trim((string)($r['Processing_items'] ?? '')),
            'cls'     => $pc['cls'],
            'rule'    => $pc['rule'],
            'status'  => $r['Order_status'] === null ? '' : (string)$r['Order_status'],
            'created_by_name' => trim((string)($r['creator_name'] ?? '')) !== '' ? (string)$r['creator_name'] : '',
            'created_at'      => $r['Created_At'] ? substr((string)$r['Created_At'], 0, 10) : '',
        ];
    }
    return $out;
}

/** 空的彙總格（所有加總都從這裡長出來，欄位一處定義） */
function oa_blank(): array
{
    return ['orders' => 0, 'qty' => 0, 'amount' => 0.0, 'px_orders' => 0,
            'new_parts' => 0, 'new_orders' => 0, 'new_qty' => 0, 'new_amount' => 0.0,
            'full' => 0, 'single' => 0, 'unknown' => 0, 'none' => 0,
            'full_amount' => 0.0, 'single_amount' => 0.0];
}
function oa_add(array &$a, array $r, bool $isNew): void
{
    $a['orders']++;
    $a['qty']    += $r['qty'];
    $a['amount'] += $r['amount'];
    if ($r['haspx']) $a['px_orders']++;
    $cls = $r['cls'];
    if (!isset($a[$cls])) $a[$cls] = 0;
    $a[$cls]++;
    if ($cls === 'full')   $a['full_amount']   += $r['amount'];
    if ($cls === 'single') $a['single_amount'] += $r['amount'];
    if ($isNew) { $a['new_orders']++; $a['new_qty'] += $r['qty']; $a['new_amount'] += $r['amount']; }
}

/* ══════════════════════════════════════════════════════════════════
 * 主分析
 *
 * 一次把畫面要的全部算完回傳（資料量小：兩個年度的訂單約 6,500 筆、實測 0.3 秒），
 * 不做快照也不落庫——訂單隨時在改，存起來的數字只會永遠停在存檔那天而且看不出它是舊的。
 * ══════════════════════════════════════════════════════════════════ */
function oa_analyze(PDO $db, array $opt = []): array
{
    $t0    = microtime(true);
    $year  = max(2000, min(2100, (int)($opt['year'] ?? date('Y'))));
    $gran  = isset(oa_grans()[$opt['gran'] ?? '']) ? (string)$opt['gran'] : 'quarter';
    $idx   = max(1, (int)($opt['idx'] ?? 1));
    $basis = (($opt['basis'] ?? 'order') === 'delivery') ? 'delivery' : 'order';
    $cmpK  = isset(oa_compares()[$opt['cmp'] ?? '']) ? (string)$opt['cmp'] : 'yoy';
    $align = !array_key_exists('align', $opt) || !empty($opt['align']);
    $incP  = !empty($opt['include_paused']);
    $topN  = max(5, min(200, (int)($opt['top'] ?? 20)));
    $sel   = array_values(array_filter(array_map('strval', (array)($opt['clients'] ?? []))));
    $selMap = $sel ? array_flip($sel) : [];

    $bands    = oa_qty_bands($db);
    $rules    = oa_proc_rules($db);
    $fallback = oa_proc_fallback($db);
    $fs       = oa_first_seen($db);
    $clFirst  = oa_client_first_seen($db);

    $cur   = oa_period_pick($year, $gran, $idx);
    $cmpP  = oa_compare_period($year, $gran, $idx, $cmpK);
    $elap  = oa_elapsed_days($cur);
    $curE  = $cur;                                     // 本期本來就只有資料到今天，不必再截
    $cmpE  = $align ? oa_cap_period($cmpP, $elap) : $cmpP;

    // 一次撈足：趨勢要本年＋去年整年，比較期可能落在更早
    $from = min(($year - 1) . '-01-01', $cmpP['start']);
    $to   = max($year . '-12-31', $cur['end']);
    $rows = oa_fetch_orders($db, $from, $to, [
        'basis' => $basis, 'include_paused' => $incP, 'rules' => $rules, 'fallback' => $fallback,
    ]);

    // ── 逐筆標上「這支料號第一次出現是什麼時候」 ──
    foreach ($rows as &$r) {
        $f = oa_part_first($fs, (int)$r['pid'], (string)$r['pno']);
        $r['first'] = $f ? $f['d'] : '';
        $r['fsrc']  = $f ? $f['s'] : '';
    }
    unset($r);

    // ── 逐筆標上「稽核製程標籤（AS 認定）」──
    // 唯一依據 order_as_tag_lib.php 的人工設定結果，不是這裡再猜一次（鐵律4，見檔頭第1點）。
    // 還沒設定的一律歸進 'unset'（尚未設定標籤），不可以漏報成「沒有這個分類」。
    //
    // 2026-10-06 使用者交辦（兩輪）：「全製／單製分析」①先改為優先採用這個人工確認過的標籤、
    // 沒設定的才退回關鍵字猜 ②再交辦改成**直接依標籤判定，完全不依關鍵字判定**——
    // 沒設定標籤的訂單一律歸 'unknown'（尚未設定標籤），不再退回 oa_proc_class() 的關鍵字猜測；
    // 關鍵字規則本身不刪（order_as_tag_lib.php 的 ot_astag_suggester() 批次補設定頁的「建議標籤」
    // 還在用，那是給人工審核用的建議、跟這裡「自動算出全製/單製統計」是不同用途，鐵律4不是指
    // 兩處都要用同一個結果，是指同一套規則只能有一份實作——oa_proc_class() 本身沒有重複）。
    // scope='full'→full、'multi'→多製程、'single'→single、'none'（不分單製全製，如廠內治具／
    // 其他非加工）整筆排除在全製/單製這個維度外（cls 記成 'excluded'，但 orders/qty/amount 等
    // 其他總計完全不受影響，只是不落進全製/單製/多製程的分子分母——這些總計本來就跟
    // 「算不算全製單製」無關）。
    $astagMap = ot_astag_for_orders($db, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $info = $astagMap[$r['id']] ?? null;
        $r['as_key']   = $info ? ($info['tag_id'] . ':' . $info['scope']) : 'unset';
        $r['as_label'] = $info ? $info['label'] : '尚未設定標籤';
        $r['as_kind']  = $info ? $info['kind']  : '';
        $r['as_proc']  = ($info && $info['is_as_process']) ? 1 : 0;

        $r['rule'] = '';   // 不再用關鍵字猜，這欄保留給 oa_proc_rules() 設定頁校正用途顯示命中數
        if ($info && $info['scope'] === 'full') {
            $r['cls'] = 'full'; $r['cls_src'] = 'astag';
        } elseif ($info && $info['scope'] === 'multi') {
            $r['cls'] = 'multi'; $r['cls_src'] = 'astag';
        } elseif ($info && $info['scope'] === 'single') {
            $r['cls'] = 'single'; $r['cls_src'] = 'astag';
        } elseif ($info && $info['scope'] === 'none') {
            $r['cls'] = 'excluded'; $r['cls_src'] = 'astag_excluded';
        } else {
            // 還沒設定標籤：'none'＝製程欄本身是空的（純資料品質提示，跟猜測無關）、
            // 'unknown'＝製程欄有字但沒設標籤，一律歸「尚未設定標籤」，不再猜全製還是單製
            $r['cls'] = (trim((string)$r['proc']) === '') ? 'none' : 'unknown';
            $r['cls_src'] = 'astag_unset';
        }
    }
    unset($r);

    $inSel = function (array $r) use ($selMap) { return !$selMap || isset($selMap[$r['ckey']]); };
    $inRange = function (array $r, array $p) { return $r['dt'] >= $p['start'] && $r['dt'] <= $p['end']; };
    // 「新料號」＝這支料號在系統裡的第一次出現就落在這個期間內
    $isNewIn = function (array $r, array $p) { return $r['first'] !== '' && $r['first'] >= $p['start'] && $r['first'] <= $p['end']; };

    /* ── KPI：本期 vs 比較期 ─────────────────────────────────── */
    $mkAgg = function (array $p) use ($rows, $inSel, $inRange, $isNewIn) {
        $a = oa_blank(); $cs = []; $ps = []; $np = [];
        foreach ($rows as $r) {
            if (!$inSel($r) || !$inRange($r, $p)) continue;
            $new = $isNewIn($r, $p);
            oa_add($a, $r, $new);
            $cs[$r['ckey']] = 1; $ps[$r['pkey']] = 1;
            if ($new) $np[$r['pkey']] = 1;
        }
        $a['clients']   = count($cs);
        $a['parts']     = count($ps);
        $a['new_parts'] = count($np);
        return $a;
    };
    $kpiCur = $mkAgg($curE);
    $kpiCmp = $mkAgg($cmpE);

    /* ── 趨勢：本年度全部期別 ＋ 去年同粒度（虛線對照） ───────── */
    $mkSeries = function (int $y) use ($gran, $rows, $inSel, $inRange, $isNewIn) {
        $out = [];
        foreach (oa_period_buckets($y, $gran) as $b) {
            $a = oa_blank(); $np = []; $cs = [];
            foreach ($rows as $r) {
                if (!$inSel($r) || !$inRange($r, $b)) continue;
                $new = $isNewIn($r, $b);
                oa_add($a, $r, $new);
                if ($new) $np[$r['pkey']] = 1;
                $cs[$r['ckey']] = 1;
            }
            $a['new_parts'] = count($np);
            $a['clients']   = count($cs);
            $a['idx']       = $b['idx'];
            $a['label']     = $b['label'];
            $a['start']     = $b['start'];
            $a['end']       = $b['end'];
            $out[] = $a;
        }
        return $out;
    };
    $trendCur  = $mkSeries($year);
    $trendPrev = $mkSeries($year - 1);

    /* ── 數量區間 ────────────────────────────────────────────── */
    $bandAgg = [];
    foreach ($bands as $b) $bandAgg[] = ['label' => $b['label'], 'min' => $b['min'], 'max' => $b['max'],
                                         'orders' => 0, 'qty' => 0, 'amount' => 0.0];
    $bandNA = ['label' => '未涵蓋', 'min' => null, 'max' => null, 'orders' => 0, 'qty' => 0, 'amount' => 0.0];
    foreach ($rows as $r) {
        if (!$inSel($r) || !$inRange($r, $curE)) continue;
        $i = oa_band_index((int)$r['qty'], $bands);
        if ($i >= 0) {
            $bandAgg[$i]['orders']++; $bandAgg[$i]['qty'] += $r['qty']; $bandAgg[$i]['amount'] += $r['amount'];
        } else {
            $bandNA['orders']++;      $bandNA['qty']      += $r['qty']; $bandNA['amount']      += $r['amount'];
        }
    }
    if ($bandNA['orders'] > 0) $bandAgg[] = $bandNA;
    $bTotO = 0; $bTotQ = 0; $bTotA = 0.0;
    foreach ($bandAgg as $b) { $bTotO += $b['orders']; $bTotQ += $b['qty']; $bTotA += $b['amount']; }
    foreach ($bandAgg as &$b) {
        $b['pct_orders'] = $bTotO ? round($b['orders'] * 100 / $bTotO, 1) : 0;
        $b['pct_qty']    = $bTotQ ? round($b['qty']    * 100 / $bTotQ, 1) : 0;
        $b['pct_amount'] = $bTotA ? round($b['amount'] * 100 / $bTotA, 1) : 0;
    }
    unset($b);

    /* ── 全製／單製／多製程：本期彙總（2026-10-06 使用者交辦：直接依「稽核製程標籤（AS 認定）」
       判定，不再退回關鍵字規則猜——$rules／oa_proc_class() 不刪，order_as_tag_lib.php 的
       ot_astag_suggester() 批次補設定頁的「建議標籤」還在用它，只是這個分析結果不再採用。
       2026-10-06（同日再交辦）：「單製」要再拆成「AS單製」（kind=process，如單製齒研/單製
       插齒，是管理員設定的稽核製程）與「單製」（kind=fixed/other，如單製其他——同一道製程
       但不在 AS 認證範圍內，意義不同，不可混算）；「全製」比照（目前系統裡還沒有人設定
       scope=full/both 的稽核製程，所以 full_as 恆為 0，保留這個分支只是避免日後管理員真的
       設了卻被吃掉）。 */
    $procSamples = ['full' => [], 'single' => [], 'multi' => [], 'unknown' => [], 'none' => []];
    $astagFullAs = 0; $astagFullOther = 0; $astagSingleAs = 0; $astagSingleOther = 0;
    $astagMulti = 0; $astagExcluded = 0; $astagUnsetCnt = 0;
    foreach ($rows as $r) {
        if (!$inSel($r) || !$inRange($r, $curE)) continue;
        if ($r['cls_src'] === 'astag') {
            $isProc = ($r['as_kind'] === 'process');
            if ($r['cls'] === 'full')        { if ($isProc) $astagFullAs++;   else $astagFullOther++; }
            elseif ($r['cls'] === 'multi')   { $astagMulti++; }
            else                             { if ($isProc) $astagSingleAs++; else $astagSingleOther++; }
        } elseif ($r['cls_src'] === 'astag_excluded') {
            $astagExcluded++;
        } else {
            $astagUnsetCnt++;   // 還沒設定標籤（cls 為 unknown 或 none，不猜）
        }
        $c = $r['cls'];
        if (isset($procSamples[$c]) && count($procSamples[$c]) < 12 && $r['proc'] !== ''
            && !in_array($r['proc'], $procSamples[$c], true)) $procSamples[$c][] = $r['proc'];
    }

    /* ── AS 稽核分類（依訂單追蹤「稽核製程標籤」設定，人確認過的結果，不是關鍵字猜的）──
       與上面「全製／單製」是兩回事：那個是程式用製程文字猜的粗略分類，這裡是每張單
       自己選過的稽核認定（全製／單製○○／多製程／廠內治具…）。同一張單只會落在一個分類，
       還沒設定的歸進 'unset'（尚未設定標籤），刻意不漏報，否則使用者會以為系統漏算。
       2026-10-06 使用者回報「有新增項目但分析表上沒顯示」——原本只有「本期或基期真的有
       訂單」的分類才會出現，管理員剛新增的分類在還沒有任何訂單掛上去之前永遠看不到，
       沒辦法確認設定有沒有生效。改成**先用目前全部啟用中的定義把每一個分類（含每個變體）
       都種一列 0**，再疊上真實資料；'is_proc'＝這個分類算不算「AS」（kind=process，即管理員
       設定的稽核製程），非 AS＝固定選項／管理員自訂的其他固定選項，前端用這個旗標加
       「AS／非AS」籤，分組規則與 NewOrder_Track.php 的下拉分組同一套（kind==='process'）。 */
    $byAstag = [];
    foreach (ot_astag_defs($db, true) as $d) {
        foreach (ot_astag_variants($d) as $sc) {
            $k = $d['tag_id'] . ':' . $sc;
            $byAstag[$k] = ['key' => $k, 'label' => ot_astag_make_label($d, $sc), 'kind' => $d['kind'],
                            'is_proc' => ($d['kind'] === 'process') ? 1 : 0, 'sort_order' => (int)$d['sort_order'],
                            'cur' => oa_blank(), 'cmp' => oa_blank()];
        }
    }
    // 2026-10-06 使用者要求：AS 集中在上面、非AS 在下面，組內順序照訂單追蹤設定的排序——
    // 「尚未設定標籤」不是真正的認定分類，sort_order 給一個比任何定義都大的值，固定排最後。
    $byAstag['unset'] = ['key' => 'unset', 'label' => '尚未設定標籤', 'kind' => '', 'is_proc' => 0, 'sort_order' => PHP_INT_MAX,
                          'cur' => oa_blank(), 'cmp' => oa_blank()];
    $accumAs = function (array $p, string $slot) use ($rows, &$byAstag, $inSel, $inRange) {
        foreach ($rows as $r) {
            if (!$inSel($r) || !$inRange($r, $p)) continue;
            $k = $r['as_key'];
            // 保險退路：訂單存著的標籤已經被停用（ot_astag_defs(true) 查不到），上面種不到，
            // 真的遇到才現場補一列（排序值給一個很大但比 unset 小的數，落在各自 AS/非AS 組的尾端）
            // ——不然那張單的資料會憑空消失。
            if (!isset($byAstag[$k])) $byAstag[$k] = ['key' => $k, 'label' => $r['as_label'], 'kind' => $r['as_kind'],
                                                       'is_proc' => $r['as_proc'], 'sort_order' => 99999,
                                                       'cur' => oa_blank(), 'cmp' => oa_blank()];
            oa_add($byAstag[$k][$slot], $r, false);
        }
    };
    $accumAs($curE, 'cur');
    $accumAs($cmpE, 'cmp');
    $astagRows = [];
    foreach ($byAstag as $t) {
        $t['d_amount'] = $t['cur']['amount'] - $t['cmp']['amount'];
        $t['d_orders'] = $t['cur']['orders'] - $t['cmp']['orders'];
        $t['d_qty']    = $t['cur']['qty']    - $t['cmp']['qty'];
        $astagRows[] = $t;
    }
    // 顯示順序：AS 在前、非AS 在後，組內依訂單追蹤「稽核製程標籤」設定頁的排序（sort_order）。
    usort($astagRows, function ($a, $b) {
        if ($a['is_proc'] !== $b['is_proc']) return $b['is_proc'] <=> $a['is_proc'];
        return $a['sort_order'] <=> $b['sort_order'];
    });
    // 趨勢圖另外依「本期金額」挑最重要的 12 類（避免圖表塞爆；不跟著上面的顯示順序走，
    // 否則分類一多，金額最大的那幾類反而可能被排序擠出趨勢圖）。
    $astagByAmount = $astagRows;
    usort($astagByAmount, function ($a, $b) {
        $d = $b['cur']['amount'] <=> $a['cur']['amount'];
        return $d !== 0 ? $d : ($b['cur']['orders'] <=> $a['cur']['orders']);
    });
    $astagTopKeys = array_slice(array_map(function ($t) { return $t['key']; }, $astagByAmount), 0, 12);
    $astagTrend = [];
    foreach ($astagTopKeys as $k) {
        $row = ['key' => $k, 'label' => $byAstag[$k]['label'], 'orders' => [], 'qty' => [], 'amount' => []];
        foreach (oa_period_buckets($year, $gran) as $b) {
            $a = oa_blank();
            foreach ($rows as $r) { if ($inSel($r) && $r['as_key'] === $k && $inRange($r, $b)) oa_add($a, $r, false); }
            $row['orders'][] = $a['orders']; $row['qty'][] = $a['qty']; $row['amount'][] = round($a['amount']);
        }
        $astagTrend[] = $row;
    }
    $astagUnset     = $byAstag['unset'] ?? null;
    $astagUnsetOrd  = (int)($astagUnset['cur']['orders'] ?? 0);
    $astagTaggedPct = $kpiCur['orders'] ? round(100 - ($astagUnsetOrd * 100 / $kpiCur['orders']), 1) : 0.0;

    /* ── 數量區間交叉表（2026-10-06 使用者交辦：數量區間分析要分「全部」／「依訂單標籤分類」／
       「依全製/多製程/單製」三種）。沿用上面已經算好的 $bandAgg（含「未涵蓋」那一列，若有）、
       $byAstag（AS 認定分類清單與排序，不重新列一次——鐵律4）。「全部」就是上面原本的 $bandAgg，
       這裡只需要再算兩個交叉表：band_by_cls（cls 只取全製/多製程/單製/尚未設定/不列入五種，
       跟使用者這次講的「全製/多製程/單製」同一個口徑，unknown 與 none 合併成「尚未設定標籤」
       避免跟上面「判定依據」表又拆出 AS單製/單製 搞混——這裡要的是粗分類不是細分類）、
       band_by_astag（逐一 AS 認定分類，給「依訂單標籤分類」用）。 */
    $bandN  = count($bandAgg);
    $naIdx  = ($bandNA['orders'] > 0) ? ($bandN - 1) : -1;
    $clsGroupOf = function (string $cls): string {
        if ($cls === 'unknown' || $cls === 'none') return 'unknown';
        return $cls;
    };
    $clsOrder  = ['full', 'multi', 'single', 'unknown', 'excluded'];
    $clsLabel  = ['full' => '全製', 'multi' => '多製程', 'single' => '單製',
                  'unknown' => '尚未設定標籤', 'excluded' => '不分單製全製（不列入）'];
    $bandByCls = [];
    foreach ($clsOrder as $ck) $bandByCls[$ck] = ['key' => $ck, 'label' => $clsLabel[$ck], 'bands' => array_fill(0, $bandN, 0)];
    $bandByAstag = [];
    foreach ($byAstag as $k => $meta) {
        $bandByAstag[$k] = ['key' => $k, 'label' => $meta['label'], 'is_proc' => $meta['is_proc'],
                             'sort_order' => $meta['sort_order'], 'bands' => array_fill(0, $bandN, 0)];
    }
    foreach ($rows as $r) {
        if (!$inSel($r) || !$inRange($r, $curE)) continue;
        $i  = oa_band_index((int)$r['qty'], $bands);
        $bi = ($i >= 0) ? $i : $naIdx;
        if ($bi < 0) continue;   // 理論上不會發生：走到這裡代表至少有一筆落在「未涵蓋」
        $bandByCls[$clsGroupOf($r['cls'])]['bands'][$bi]++;
        $k = $r['as_key'];
        if (!isset($bandByAstag[$k])) {
            $bandByAstag[$k] = ['key' => $k, 'label' => $r['as_label'], 'is_proc' => $r['as_proc'],
                                 'sort_order' => 99999, 'bands' => array_fill(0, $bandN, 0)];
        }
        $bandByAstag[$k]['bands'][$bi]++;
    }
    $bandByCls = array_values(array_filter($bandByCls, function ($x) { return array_sum($x['bands']) > 0; }));
    $bandByAstag = array_values(array_filter($bandByAstag, function ($x) { return array_sum($x['bands']) > 0; }));
    usort($bandByAstag, function ($a, $b) {
        if ($a['is_proc'] !== $b['is_proc']) return $b['is_proc'] <=> $a['is_proc'];
        return $a['sort_order'] <=> $b['sort_order'];
    });

    /* ── 客戶比較：選了客戶就比那幾家，沒選就自動取本期金額（無金額時用筆數）前 8 名 ── */
    $byClient = [];
    $accum = function (array $p, string $slot) use ($rows, &$byClient, $inSel, $isNewIn, $inRange) {
        foreach ($rows as $r) {
            if (!$inSel($r) || !$inRange($r, $p)) continue;
            $k = $r['ckey'];
            if (!isset($byClient[$k])) $byClient[$k] = ['key' => $k, 'name' => $r['cname'], 'cid' => $r['cid'],
                                                        'bad' => $r['cbad'], 'cur' => oa_blank(), 'cmp' => oa_blank(),
                                                        'cur_parts' => [], 'cur_new' => []];
            $new = $isNewIn($r, $p);
            oa_add($byClient[$k][$slot], $r, $new);
            if ($slot === 'cur') {
                $byClient[$k]['cur_parts'][$r['pkey']] = 1;
                if ($new) $byClient[$k]['cur_new'][$r['pkey']] = 1;
            }
        }
    };
    $accum($curE, 'cur');
    $accum($cmpE, 'cmp');
    $clientRows = [];
    foreach ($byClient as $k => $c) {
        $c['parts']     = count($c['cur_parts']);
        $c['new_parts'] = count($c['cur_new']);
        unset($c['cur_parts'], $c['cur_new']);
        $c['d_amount'] = $c['cur']['amount'] - $c['cmp']['amount'];
        $c['d_orders'] = $c['cur']['orders'] - $c['cmp']['orders'];
        $c['d_qty']    = $c['cur']['qty']    - $c['cmp']['qty'];
        // 「新客戶」＝這家客戶在系統整段歷史裡第一次出現就落在本期；
        // 基期 0、本期有，但系統裡早就查得到更早的出貨/訂單/退貨 → 是「回流客戶」不是新客戶
        // （2026-09-24 修正：原本只跟比較基期比，41 家裡有 31 家其實以前就下過單）。
        $cf = $clFirst[$k] ?? null;
        $c['first'] = $cf ? $cf['d'] : '';
        $c['fsrc']  = $cf ? $cf['s'] : '';
        if ($c['cmp']['orders'] == 0 && $c['cur']['orders'] > 0) {
            $c['flag'] = ($cf && $cf['d'] >= $curE['start'] && $cf['d'] <= $curE['end']) ? 'new' : 'return';
        } elseif ($c['cur']['orders'] == 0 && $c['cmp']['orders'] > 0) {
            $c['flag'] = 'lost';
        } else {
            $c['flag'] = '';
        }
        $clientRows[] = $c;
    }

    // 比較表要列哪幾家
    $cmpKeys = $sel;
    if (!$cmpKeys) {
        $tmp = $clientRows;
        usort($tmp, function ($a, $b) {
            $d = $b['cur']['amount'] <=> $a['cur']['amount'];
            return $d !== 0 ? $d : ($b['cur']['orders'] <=> $a['cur']['orders']);
        });
        foreach (array_slice($tmp, 0, 8) as $c) $cmpKeys[] = $c['key'];
    }
    $cmpSeries = [];
    foreach ($cmpKeys as $k) {
        if (!isset($byClient[$k])) continue;
        $row = ['key' => $k, 'name' => $byClient[$k]['name'], 'orders' => [], 'qty' => [], 'amount' => []];
        foreach (oa_period_buckets($year, $gran) as $b) {
            $a = oa_blank();
            foreach ($rows as $r) { if ($r['ckey'] === $k && $inRange($r, $b)) oa_add($a, $r, false); }
            $row['orders'][] = $a['orders']; $row['qty'][] = $a['qty']; $row['amount'][] = round($a['amount']);
        }
        $cmpSeries[] = $row;
    }

    /* ── 客戶比較：標籤分類交叉表（2026-10-06 使用者交辦，跟數量區間那組同一套口徑）──
       X 軸沿用上面已經選定要比較的那幾家客戶（$cmpKeys，使用者自選或自動前8名），
       不是全部客戶——跟上面時間趨勢圖看的是同一組公司，只是換一個角度交叉統計。
       $clsGroupOf／$clsOrder／$clsLabel／$byAstag 是數量區間那段已經算好的共用素材，
       這裡直接沿用不重新定義一次（鐵律4）。 */
    $cmpN = count($cmpKeys);
    $clientByCls = [];
    foreach ($clsOrder as $ck) $clientByCls[$ck] = ['key' => $ck, 'label' => $clsLabel[$ck], 'bands' => array_fill(0, $cmpN, 0)];
    $clientByAstag = [];
    foreach ($byAstag as $k => $meta) {
        $clientByAstag[$k] = ['key' => $k, 'label' => $meta['label'], 'is_proc' => $meta['is_proc'],
                               'sort_order' => $meta['sort_order'], 'bands' => array_fill(0, $cmpN, 0)];
    }
    $cmpKeyIdx = array_flip($cmpKeys);
    foreach ($rows as $r) {
        if (!$inSel($r) || !$inRange($r, $curE)) continue;
        if (!isset($cmpKeyIdx[$r['ckey']])) continue;   // 只算有被選進比較表的那幾家
        $ci = $cmpKeyIdx[$r['ckey']];
        $clientByCls[$clsGroupOf($r['cls'])]['bands'][$ci]++;
        $k = $r['as_key'];
        if (!isset($clientByAstag[$k])) {
            $clientByAstag[$k] = ['key' => $k, 'label' => $r['as_label'], 'is_proc' => $r['as_proc'],
                                   'sort_order' => 99999, 'bands' => array_fill(0, $cmpN, 0)];
        }
        $clientByAstag[$k]['bands'][$ci]++;
    }
    $clientByCls = array_values(array_filter($clientByCls, function ($x) { return array_sum($x['bands']) > 0; }));
    $clientByAstag = array_values(array_filter($clientByAstag, function ($x) { return array_sum($x['bands']) > 0; }));
    usort($clientByAstag, function ($a, $b) {
        if ($a['is_proc'] !== $b['is_proc']) return $b['is_proc'] <=> $a['is_proc'];
        return $a['sort_order'] <=> $b['sort_order'];
    });
    $cmpKeyNames = array_map(function ($k) use ($byClient) { return $byClient[$k]['name'] ?? $k; }, $cmpKeys);

    /* ── 客戶增減排名：依「增減金額」排序，不是依百分比 ──
       只看 % 的話，1 萬變 2 萬的小客戶會永遠排在 500 萬掉到 400 萬的大客戶前面。 */
    // 排序口徑：預設金額，但**基期幾乎沒人填單價時自動改用數量**。
    // 2025 年 3,628 張訂單只有 11 張有單價，拿金額去跟 2025 比，每一家客戶都會是「無限成長」，
    // 那不是業績變好、是基期根本沒有金額可以比。自動換過來時一定要在畫面上講明原因。
    $covCur = $kpiCur['orders'] ? round($kpiCur['px_orders'] * 100 / $kpiCur['orders'], 1) : 0.0;
    $covCmp = $kpiCmp['orders'] ? round($kpiCmp['px_orders'] * 100 / $kpiCmp['orders'], 1) : 0.0;
    $rankAuto = '';
    $rankMetric = (string)($opt['rank_metric'] ?? '');
    if (!in_array($rankMetric, ['amount', 'qty', 'orders'], true)) {
        if ($covCmp < 30 || $covCur < 30) {
            $rankMetric = 'qty';
            $rankAuto   = '本期有單價的訂單佔 ' . $covCur . '%、基期只有 ' . $covCmp . '%，'
                        . '用金額比會失真，已自動改用「數量」排序（可在上方自行切回金額）。';
        } else {
            $rankMetric = 'amount';
        }
    }
    $mk = 'd_' . $rankMetric;
    $rankClients = $clientRows;
    usort($rankClients, function ($a, $b) use ($mk) { return $b[$mk] <=> $a[$mk]; });

    /* ── 受訂料號排名 ─────────────────────────────────────────── */
    $byPart = [];
    $accumP = function (array $p, string $slot) use ($rows, &$byPart, $inSel, $inRange) {
        foreach ($rows as $r) {
            if (!$inSel($r) || !$inRange($r, $p)) continue;
            $k = $r['pkey'];
            // pid＝d_setting.d_id（料號主檔整數 PK）：畫面上點料號要用它開圖面檢視
            // （同名料號可能有好幾筆主檔、分屬不同客戶，不指名會開到別家的圖）
            if (!isset($byPart[$k])) $byPart[$k] = ['key' => $k, 'pno' => $r['pno'], 'cname' => $r['cname'],
                                                    'pid' => (int)$r['pid'],
                                                    'first' => $r['first'], 'fsrc' => $r['fsrc'],
                                                    'cur' => oa_blank(), 'cmp' => oa_blank(), 'cur_tags' => []];
            oa_add($byPart[$k][$slot], $r, false);
            // 2026-10-06 使用者交辦：受訂料號排名要顯示這支料號掛的稽核製程標籤——同一支料號
            // 本期可能分散在好幾張訂單、各自的 AS 認定不一定相同，所以收集成一個集合（去重），
            // 有幾種就顯示幾種；還沒設定標籤的訂單不算進來（不是一種「標籤」）。
            if ($slot === 'cur' && $r['as_key'] !== 'unset') $byPart[$k]['cur_tags'][$r['as_key']] = $r['as_label'];
        }
    };
    $accumP($curE, 'cur');
    $accumP($cmpE, 'cmp');
    $partRows = [];
    foreach ($byPart as $p) {
        $p['d_amount'] = $p['cur']['amount'] - $p['cmp']['amount'];
        $p['d_orders'] = $p['cur']['orders'] - $p['cmp']['orders'];
        $p['d_qty']    = $p['cur']['qty']    - $p['cmp']['qty'];
        $p['is_new']   = ($p['first'] !== '' && $p['first'] >= $curE['start'] && $p['first'] <= $curE['end']) ? 1 : 0;
        $p['tags']     = array_values($p['cur_tags']);
        unset($p['cur_tags']);
        $partRows[] = $p;
    }
    $rankParts = $partRows;
    usort($rankParts, function ($a, $b) use ($rankMetric) {
        $d = $b['cur'][$rankMetric] <=> $a['cur'][$rankMetric];
        return $d !== 0 ? $d : ($b['cur']['orders'] <=> $a['cur']['orders']);
    });
    $rankPartsDelta = $partRows;
    usort($rankPartsDelta, function ($a, $b) use ($mk) { return $b[$mk] <=> $a[$mk]; });

    /* ── 新料號明細 ───────────────────────────────────────────── */
    $newList = [];
    foreach ($partRows as $p) {
        if (!$p['is_new']) continue;
        $newList[] = ['key' => $p['key'], 'pno' => $p['pno'], 'cname' => $p['cname'], 'pid' => (int)$p['pid'],
                      'first' => $p['first'], 'fsrc' => $p['fsrc'],
                      'orders' => $p['cur']['orders'], 'qty' => $p['cur']['qty'],
                      'amount' => $p['cur']['amount'], 'px' => $p['cur']['px_orders'], 'tags' => $p['tags']];
    }
    usort($newList, function ($a, $b) {
        $d = $b['amount'] <=> $a['amount'];
        return $d !== 0 ? $d : ($b['qty'] <=> $a['qty']);
    });

    /* ── 未歸戶／未綁料號主檔的提醒（不講出來就會被當成程式壞掉）──── */
    $warnNoPart = 0; $warnNoClient = 0; $warnNoFirst = 0;
    foreach ($rows as $r) {
        if (!$inSel($r) || !$inRange($r, $curE)) continue;
        if ((int)$r['pid'] <= 0) $warnNoPart++;
        if ($r['cbad'])          $warnNoClient++;
        if ($r['first'] === '')  $warnNoFirst++;
    }

    return [
        'meta' => [
            'year' => $year, 'gran' => $gran, 'gran_label' => oa_grans()[$gran], 'idx' => $idx,
            'basis' => $basis, 'basis_label' => oa_date_bases()[$basis],
            'cmp' => $cmpK, 'cmp_label' => oa_compares()[$cmpK],
            'period' => $cur, 'period_eff' => $curE,
            'cmp_period' => $cmpP, 'cmp_period_eff' => $cmpE,
            'elapsed_days' => $elap, 'align' => $align ? 1 : 0,
            'include_paused' => $incP ? 1 : 0,
            'rank_metric' => $rankMetric, 'rank_metric_auto' => $rankAuto,
            'px_cov_cur' => $covCur, 'px_cov_cmp' => $covCmp,
            'buckets' => array_map(function ($b) { return $b['label']; }, oa_period_buckets($year, $gran)),
            'horizon' => $fs['horizon'], 'source_labels' => oa_source_labels(),
            'bands' => $bands, 'rules' => $rules, 'fallback' => $fallback,
            'clients_selected' => $sel,
            'warn' => ['no_part' => $warnNoPart, 'no_client' => $warnNoClient, 'no_first' => $warnNoFirst],
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
            'today' => date('Y-m-d'),
        ],
        'kpi'         => ['cur' => $kpiCur, 'cmp' => $kpiCmp],
        'trend'       => ['cur' => $trendCur, 'prev' => $trendPrev, 'prev_year' => $year - 1],
        'bands'       => $bandAgg,
        'band_by_cls'   => $bandByCls,
        'band_by_astag' => $bandByAstag,
        'proc'        => ['samples' => $procSamples,
                           'astag_full_as' => $astagFullAs, 'astag_full_other' => $astagFullOther,
                           'astag_single_as' => $astagSingleAs, 'astag_single_other' => $astagSingleOther,
                           'astag_multi' => $astagMulti,
                           'astag_excluded' => $astagExcluded, 'astag_unset' => $astagUnsetCnt],
        'astag'       => ['rows' => $astagRows, 'trend' => $astagTrend, 'buckets' => array_map(function ($b) { return $b['label']; }, oa_period_buckets($year, $gran)),
                           'unset' => $astagUnset, 'tagged_pct' => $astagTaggedPct],
        'clients'     => $clientRows,
        'client_cmp'  => ['keys' => $cmpKeys, 'names' => $cmpKeyNames, 'series' => $cmpSeries],
        'client_by_cls'   => $clientByCls,
        'client_by_astag' => $clientByAstag,
        'rank_clients' => $rankClients,
        'rank_parts'   => array_slice($rankParts, 0, $topN),
        'rank_parts_delta' => array_slice($rankPartsDelta, 0, $topN),
        'rank_parts_drop'  => array_slice(array_reverse($rankPartsDelta), 0, $topN),
        'new_list'     => $newList,
    ];
}

/* ══════════════════════════════════════════════════════════════════
 * 訂單 KPI 連動（views/news/KPI.php 的「月份受訂目標達成金額」）
 *
 * 指標 id 刻意不寫死：管理員可以在設定裡指定，沒指定時自動找
 * calculator_key='order_target_amount' 的那一項（目前是 item_no 2）。
 * 達標與否一律呼叫 KPI 模組自己的 kpi_as_display_value()／kpi_as_below_target()，
 * 不在這裡另寫一套判定——兩套遲早算出不一樣的結果而且看不出誰對。
 * ══════════════════════════════════════════════════════════════════ */
function oa_settings_default(): array
{
    return [
        'kpi_indicator_id' => 0,        // 0＝自動找 order_target_amount
        'kpi_alert_months' => 3,        // 看最近幾個「已結束的月份」
        'ma_enabled'       => 0,
        'ma_months'        => 3,        // 移動平均取前幾個月
        'ma_consecutive'   => 2,        // 連續幾個月低於安全水平才通知
        'ma_threshold_mode' => 'kpi',   // kpi＝用該年度訂單 KPI 的月目標金額／manual＝自訂
        'ma_threshold_value' => 0,
        'ma_min_coverage'  => 60,       // 該月「有填單價」的訂單佔比低於此值 → 該月金額不可信，不納入評估
        'ma_notify_users'  => [],
    ];
}
/**
 * 上下限夾範圍——oa_settings()（讀）與 oa_settings_save()（存）都呼叫這一支，
 * 不各自處理一次：兩邊各寫一次上下限，遲早會夾出不一樣的結果而且看不出誰對。
 * 一定要「就地正規化傳入的陣列」，不可以在存檔那邊重新去讀資料庫再合併——
 * 那樣做等於用「存檔前的舊值」蓋掉「這次要存的新值」（本次就是這樣踩到的）。
 */
function oa_settings_clamp(array $out): array
{
    $d = oa_settings_default();
    foreach ($d as $k => $v) if (!array_key_exists($k, $out)) $out[$k] = $v;
    $out['kpi_alert_months']   = max(1, min(12, (int)$out['kpi_alert_months']));
    $out['ma_months']          = max(2, min(12, (int)$out['ma_months']));
    $out['ma_consecutive']     = max(1, min(6,  (int)$out['ma_consecutive']));
    $out['ma_min_coverage']    = max(0, min(100, (int)$out['ma_min_coverage']));
    $out['ma_threshold_value'] = max(0, (int)$out['ma_threshold_value']);
    $out['ma_enabled']         = !empty($out['ma_enabled']) ? 1 : 0;
    $out['kpi_indicator_id']   = max(0, (int)$out['kpi_indicator_id']);
    if (!in_array($out['ma_threshold_mode'], ['kpi', 'manual'], true)) $out['ma_threshold_mode'] = 'kpi';
    $out['ma_notify_users'] = array_values(array_unique(array_map('intval', (array)$out['ma_notify_users'])));
    return $out;
}
function oa_settings(PDO $db): array
{
    $d = oa_settings_default();
    $s = oa_param_get($db, 'alert_settings', null);
    if (!is_array($s)) return $d;
    $out = $d;
    foreach ($d as $k => $v) {
        if (!array_key_exists($k, $s)) continue;
        if (is_array($v)) $out[$k] = is_array($s[$k]) ? array_values(array_map('intval', $s[$k])) : [];
        elseif (is_int($v)) $out[$k] = (int)$s[$k];
        else $out[$k] = (string)$s[$k];
    }
    return oa_settings_clamp($out);
}
function oa_settings_save(PDO $db, array $in, string $by): array
{
    $cur = oa_settings($db);
    foreach (oa_settings_default() as $k => $v) {
        if (!array_key_exists($k, $in)) continue;
        if (is_array($v))      $cur[$k] = array_values(array_unique(array_map('intval', (array)$in[$k])));
        elseif (is_int($v))    $cur[$k] = (int)$in[$k];
        else                   $cur[$k] = (string)$in[$k];
    }
    $cur = oa_settings_clamp($cur);

    $err = [];
    if ($cur['ma_threshold_mode'] === 'manual' && (int)$cur['ma_threshold_value'] <= 0) $err[] = '選「自訂金額」時，安全水平金額必須大於 0';
    if (!empty($cur['ma_enabled']) && !$cur['ma_notify_users']) $err[] = '啟用移動平均監控時，一定要指定至少一位收通知的人員（不然算出來沒有人會知道）';
    if ($err) return ['ok' => false, 'errors' => $err];

    oa_param_save($db, 'alert_settings', $cur, $by);
    return ['ok' => true, 'settings' => $cur];
}

/** 訂單 KPI 的指標與該年度設定（找不到回 null；畫面要據此說明「沒有設定就不會有提醒」） */
function oa_kpi_iy(PDO $db, int $year): ?array
{
    $sid = (int)oa_settings($db)['kpi_indicator_id'];
    try {
        if ($sid > 0) {
            $st = $db->prepare("SELECT iy.*, i.name, i.value_type FROM kpi_as_indicator_year iy
                                JOIN kpi_as_indicator i ON i.indicator_id=iy.indicator_id
                                WHERE iy.indicator_id=? AND iy.year=? LIMIT 1");
            $st->execute([$sid, $year]);
        } else {
            $st = $db->prepare("SELECT iy.*, i.name, i.value_type FROM kpi_as_indicator_year iy
                                JOIN kpi_as_indicator i ON i.indicator_id=iy.indicator_id
                                WHERE iy.calculator_key='order_target_amount' AND iy.year=? LIMIT 1");
            $st->execute([$year]);
        }
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
/** 該年度逐月的受訂目標金額（params_json 的 monthly_targets） */
function oa_kpi_monthly_targets(?array $iy): array
{
    if (!$iy) return [];
    $p = json_decode((string)($iy['params_json'] ?? ''), true);
    $v = $p['monthly_targets']['v'] ?? null;
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $m => $amt) { $m = (int)$m; if ($m >= 1 && $m <= 12) $out[$m] = (float)$amt; }
    return $out;
}
/**
 * 「最近 N 個已結束的月份」訂單 KPI 有沒有達標。
 * 刻意不看本月——本月還沒過完，拿半個月的數字去判未達標一定是錯的。
 */
function oa_kpi_recent(PDO $db, int $n, ?string $today = null): array
{
    require_once __DIR__ . '/kpi_as_lib.php';
    $today = $today ?: date('Y-m-d');
    $y = (int)date('Y', strtotime($today));
    $m = (int)date('n', strtotime($today));
    $rows = []; $iyCache = [];
    for ($i = 1; $i <= $n; $i++) {
        $mm = $m - $i; $yy = $y;
        while ($mm <= 0) { $mm += 12; $yy--; }
        if (!isset($iyCache[$yy])) $iyCache[$yy] = oa_kpi_iy($db, $yy);
        $iy = $iyCache[$yy];
        if (!$iy) { $rows[] = ['year' => $yy, 'month' => $mm, 'has' => 0]; continue; }
        $mv = null;
        try {
            $st = $db->prepare("SELECT * FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month=? LIMIT 1");
            $st->execute([(int)$iy['indicator_id'], $yy, $mm]);
            $mv = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
        $val   = kpi_as_display_value($mv);
        $below = kpi_as_below_target($val, $iy);
        $tg    = oa_kpi_monthly_targets($iy);
        $rows[] = [
            'year' => $yy, 'month' => $mm, 'has' => $val === null ? 0 : 1,
            'value' => $val, 'below' => $below ? 1 : 0,
            'target' => $iy['target_value'] === null ? null : (float)$iy['target_value'],
            'target_text' => (string)($iy['target_text'] ?? ''),
            'unit' => (string)($iy['target_unit'] ?? ''),
            'num' => $mv && $mv['numerator'] !== null ? (float)$mv['numerator'] : null,
            'den' => $mv && $mv['denominator'] !== null ? (float)$mv['denominator'] : null,
            'month_target' => $tg[$mm] ?? null,
            'indicator' => (string)$iy['name'],
        ];
    }
    return array_reverse($rows);   // 由舊到新
}
/**
 * 「最近 N 個月的訂單 KPI 未達標 → 本月要衝刺」的提醒內容。
 * 使用者要的是「提醒本月需要衝刺出貨量」，所以除了未達標的月份，
 * 還要算出**本月到目前為止離月目標還差多少**，不然只講「未達標」沒有行動可言。
 */
function oa_kpi_alert(PDO $db, ?string $today = null): ?array
{
    $s = oa_settings($db);
    $n = (int)$s['kpi_alert_months'];
    $rows = oa_kpi_recent($db, $n, $today);
    $have = array_values(array_filter($rows, function ($r) { return !empty($r['has']); }));
    if (!$have) return ['enabled' => 1, 'no_data' => 1, 'months' => $rows, 'n' => $n];

    $bad = array_values(array_filter($have, function ($r) { return !empty($r['below']); }));
    if (!$bad) return ['enabled' => 1, 'ok' => 1, 'months' => $rows, 'n' => $n];

    // 本月進度：目標金額 vs 目前已接到的訂單金額（只算得出有填單價的）
    $today = $today ?: date('Y-m-d');
    $y = (int)date('Y', strtotime($today)); $m = (int)date('n', strtotime($today));
    $iy = oa_kpi_iy($db, $y);
    $tg = oa_kpi_monthly_targets($iy);
    $mt = $tg[$m] ?? null;
    $cur = oa_month_amounts($db, sprintf('%04d-%02d', $y, $m), sprintf('%04d-%02d', $y, $m));
    $k  = sprintf('%04d-%02d', $y, $m);
    $got = $cur[$k]['amount'] ?? 0.0;
    $days = (int)date('t', strtotime($today));
    $left = max(0, $days - (int)date('j', strtotime($today)) + 1);
    // 「只剩幾天」若原封不動顯示日曆天，使用者看不出裡面含不含假日；
    // 補一個工作天版本（今天起算到月底，含假日補班），沿用 KPI 模組既有的行事曆，不另寫一套。
    $monthEnd = date('Y-m-t', strtotime($today));
    $wdLeft = $left;
    try { $wdLeft = kpi_as_workdays_inclusive($db, $today, $monthEnd); } catch (Throwable $e) {}
    return [
        'enabled' => 1, 'below' => 1, 'n' => $n, 'months' => $rows,
        'bad_count' => count($bad),
        'bad_list'  => array_map(function ($r) { return $r['year'] . '/' . $r['month'] . '月'; }, $bad),
        'this_year' => $y, 'this_month' => $m,
        'month_target' => $mt, 'month_got' => $got,
        'month_gap' => ($mt === null) ? null : max(0, $mt - $got),
        'days_left' => $left, 'workdays_left' => $wdLeft,
        'coverage' => $cur[$k]['cov'] ?? 0,
        'orders' => $cur[$k]['orders'] ?? 0, 'px_orders' => $cur[$k]['px'] ?? 0,
        'indicator' => $have[0]['indicator'] ?? '月份受訂目標達成金額',
    ];
}

/* ══════════════════════════════════════════════════════════════════
 * 逐月訂單金額 ＆ 移動平均監控
 * ══════════════════════════════════════════════════════════════════ */
/** 逐月訂單金額（口徑與本頁其他數字一致：排除暫停/取消，金額只算得出有填單價的） */
function oa_month_amounts(PDO $db, string $fromYm, string $toYm): array
{
    $from = $fromYm . '-01';
    $to   = date('Y-m-t', strtotime($toYm . '-01'));
    $sql = "SELECT DATE_FORMAT(Order_date,'%Y-%m') ym, COUNT(*) orders,
                   SUM(unit_price>0) px, SUM(Qty) qty,
                   SUM(CASE WHEN unit_price>0 THEN Qty*unit_price ELSE 0 END) amount
              FROM order_track
             WHERE Order_date BETWEEN ? AND ?
               AND (Order_status IS NULL OR Order_status <> 6)
             GROUP BY ym";
    $st = $db->prepare($sql);
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o = (int)$r['orders']; $p = (int)$r['px'];
        $out[(string)$r['ym']] = ['ym' => (string)$r['ym'], 'orders' => $o, 'px' => $p, 'qty' => (int)$r['qty'],
                                  'amount' => (float)$r['amount'], 'cov' => $o ? round($p * 100 / $o, 1) : 0.0];
    }
    // 沒有訂單的月份也要有一格（不然移動平均會把沒資料的月份直接跳過而算錯）
    $cur = $fromYm;
    while ($cur <= $toYm) {
        if (!isset($out[$cur])) $out[$cur] = ['ym' => $cur, 'orders' => 0, 'px' => 0, 'qty' => 0, 'amount' => 0.0, 'cov' => 0.0];
        $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
    }
    ksort($out);
    return $out;
}
/**
 * 移動平均監控。
 *
 * 一個一定要處理的資料事實：**2026-03 以前幾乎沒有人填單價**（2025-11 整個月 0 張），
 * 那幾個月的「訂單金額」是 0，直接拿去算移動平均一定會低於安全水平而發出假警報。
 * 所以每個月都要先看「有填單價的訂單佔比」（ma_min_coverage，預設 60%），
 * 不到門檻的月份一律標成「資料不足」**不納入評估、也不觸發通知**，並在畫面與通知裡講明是哪幾個月。
 */
function oa_moving_avg(PDO $db, array $opt = []): array
{
    $s      = oa_settings($db);
    $n      = (int)($opt['months'] ?? $s['ma_months']);
    $need   = (int)($opt['consecutive'] ?? $s['ma_consecutive']);
    $minCov = (int)($opt['min_coverage'] ?? $s['ma_min_coverage']);
    $endYm  = (string)($opt['end_ym'] ?? date('Y-m', strtotime('first day of last month')));
    $show   = max($need + 1, (int)($opt['show'] ?? 12));

    $startYm = date('Y-m', strtotime($endYm . '-01 -' . ($show + $n) . ' month'));
    $mon = oa_month_amounts($db, $startYm, $endYm);
    $keys = array_keys($mon);

    // 門檻：kpi＝該月的訂單 KPI 月目標金額；manual＝固定金額
    $thrOf = function (string $ym) use ($db, $s) {
        if ($s['ma_threshold_mode'] === 'manual') return (float)$s['ma_threshold_value'];
        static $cache = [];
        $y = (int)substr($ym, 0, 4); $m = (int)substr($ym, 5, 2);
        if (!array_key_exists($y, $cache)) $cache[$y] = oa_kpi_monthly_targets(oa_kpi_iy($db, $y));
        return $cache[$y][$m] ?? null;
    };

    $series = [];
    foreach ($keys as $i => $ym) {
        if ($i < $n - 1) continue;                       // 前面不足 n 個月，算不出移動平均
        $win = array_slice($keys, $i - $n + 1, $n);
        $sum = 0.0; $bad = [];
        foreach ($win as $w) {
            $sum += $mon[$w]['amount'];
            if ($mon[$w]['cov'] < $minCov) $bad[] = $w;
        }
        $avg = $sum / $n;
        $thr = $thrOf($ym);
        $series[] = [
            'ym' => $ym, 'avg' => $avg, 'amount' => $mon[$ym]['amount'],
            'orders' => $mon[$ym]['orders'], 'cov' => $mon[$ym]['cov'],
            'threshold' => $thr,
            'window' => $win,
            'unreliable' => $bad ? 1 : 0, 'unreliable_months' => $bad,
            'below' => ($thr !== null && !$bad && $avg < $thr) ? 1 : 0,
        ];
    }
    $series = array_slice($series, -$show);

    // 連續低於安全水平幾個月（只看最新那幾筆；資料不足的月份一律中斷連續判定）
    $streak = 0;
    for ($i = count($series) - 1; $i >= 0; $i--) {
        if (!empty($series[$i]['unreliable'])) break;
        if (empty($series[$i]['below'])) break;
        $streak++;
    }
    $last = $series ? $series[count($series) - 1] : null;
    return [
        'enabled' => (int)$s['ma_enabled'], 'months' => $n, 'need' => $need, 'min_coverage' => $minCov,
        'mode' => $s['ma_threshold_mode'], 'manual_value' => (float)$s['ma_threshold_value'],
        'series' => $series, 'streak' => $streak, 'hit' => ($streak >= $need) ? 1 : 0,
        'end_ym' => $endYm, 'last' => $last,
        'notify_users' => $s['ma_notify_users'],
    ];
}

/* ══════════════════════════════════════════════════════════════════
 * 自動分析（把「要自己盯著圖表看才發現得了」的事直接寫成句子）
 *
 * 每一條都要附數字，不可以只講結論——沒有數字的結論沒有人敢拿去做決定。
 * level：bad＝要處理／warn＝要注意／good＝正面／info＝說明
 * ══════════════════════════════════════════════════════════════════ */
function oa_insights(PDO $db, array $res, ?array $kpiAlert = null, ?array $ma = null): array
{
    $out = [];
    $add = function ($level, $title, $detail, $metric = '', $extra = []) use (&$out) {
        $out[] = array_merge(['level' => $level, 'title' => $title, 'detail' => $detail, 'metric' => $metric], $extra);
    };
    $m   = $res['meta'];
    $cur = $res['kpi']['cur'];
    $cmp = $res['kpi']['cmp'];
    $cl  = $m['cmp_label'];
    $useAmt = ($m['px_cov_cur'] >= 30 && $m['px_cov_cmp'] >= 30);
    $fmt = function ($v) { return number_format((float)$v); };
    $rate = function ($a, $b) { $b = (float)$b; if (!$b) return null; return round((($a - $b) / abs($b)) * 100, 1); };

    /* ① 整體走勢 */
    $qd = $rate($cur['qty'], $cmp['qty']);
    $od = $rate($cur['orders'], $cmp['orders']);
    if ($useAmt) {
        $ad = $rate($cur['amount'], $cmp['amount']);
        if ($ad !== null && $ad <= -10) {
            $add('bad', '整體訂單金額衰退', '本期 ' . $fmt($cur['amount']) . ' 元，較' . $cl . '的 ' . $fmt($cmp['amount'])
                 . ' 元減少 ' . $fmt($cmp['amount'] - $cur['amount']) . ' 元。', $ad . '%');
        } elseif ($ad !== null && $ad >= 10) {
            $add('good', '整體訂單金額成長', '本期 ' . $fmt($cur['amount']) . ' 元，較' . $cl . '增加 '
                 . $fmt($cur['amount'] - $cmp['amount']) . ' 元。', '+' . $ad . '%');
        }
    } else {
        $add('info', '金額無法比較，已改看數量', '本期有填單價的訂單佔 ' . $m['px_cov_cur'] . '%、'
             . $cl . '只有 ' . $m['px_cov_cmp'] . '%，金額比較沒有意義，以下結論一律以「數量／筆數」為準。');
    }
    if ($qd !== null && $qd <= -10) {
        $add('bad', '訂單數量衰退', '本期 ' . $fmt($cur['qty']) . ' 支，較' . $cl . '的 ' . $fmt($cmp['qty'])
             . ' 支減少 ' . $fmt($cmp['qty'] - $cur['qty']) . ' 支。', $qd . '%');
    } elseif ($qd !== null && $qd >= 10) {
        $add('good', '訂單數量成長', '本期 ' . $fmt($cur['qty']) . ' 支，較' . $cl . '增加 '
             . $fmt($cur['qty'] - $cmp['qty']) . ' 支。', '+' . $qd . '%');
    }
    if ($od !== null && abs($od) >= 10) {
        $add($od < 0 ? 'warn' : 'good', '訂單筆數' . ($od < 0 ? '減少' : '增加'),
             '本期 ' . $fmt($cur['orders']) . ' 筆，' . $cl . ' ' . $fmt($cmp['orders']) . ' 筆。', ($od > 0 ? '+' : '') . $od . '%');
    }

    /* ② 連續下滑（單期比較看不出來的趨勢型警訊） */
    $tr = $res['trend']['cur'];
    $idx = -1;
    foreach ($tr as $i => $b) if ((int)$b['idx'] === (int)$m['idx']) { $idx = $i; break; }
    if ($idx >= 2) {
        $k = $useAmt ? 'amount' : 'qty';
        $a0 = (float)$tr[$idx][$k]; $a1 = (float)$tr[$idx - 1][$k]; $a2 = (float)$tr[$idx - 2][$k];
        if ($a0 < $a1 && $a1 < $a2 && $a2 > 0) {
            $add('bad', '連續兩期下滑', '「' . $tr[$idx - 2]['label'] . '」→「' . $tr[$idx - 1]['label'] . '」→「'
                 . $tr[$idx]['label'] . '」的' . ($useAmt ? '訂單金額' : '訂單數量') . '一路往下（'
                 . $fmt($a2) . ' → ' . $fmt($a1) . ' → ' . $fmt($a0) . '），這種趨勢單看一期比較是看不出來的。',
                 round(($a0 - $a2) / $a2 * 100, 1) . '%');
        }
    }

    /* ③ 客戶集中度 */
    $mk = $useAmt ? 'amount' : 'qty';
    $tot = 0.0; $vals = [];
    foreach ($res['clients'] as $c) { $v = (float)$c['cur'][$mk]; if ($v > 0) { $vals[] = ['n' => $c['name'], 'v' => $v]; $tot += $v; } }
    usort($vals, function ($a, $b) { return $b['v'] <=> $a['v']; });
    if ($tot > 0 && count($vals) >= 3) {
        $t3 = $vals[0]['v'] + $vals[1]['v'] + $vals[2]['v'];
        $p3 = round($t3 * 100 / $tot, 1);
        if ($p3 >= 50) {
            $add('warn', '客戶集中度偏高', '前三大客戶（' . $vals[0]['n'] . '、' . $vals[1]['n'] . '、' . $vals[2]['n']
                 . '）就佔了本期' . ($useAmt ? '金額' : '數量') . ' ' . $p3 . '%，其中任何一家減單都會直接反映在總量上。', $p3 . '%');
        }
    }

    /* ④ 流失客戶（基期有下單、本期完全沒有）——附完整名單供前端展開點選，
     * 點客戶名稱可另開新跳窗到 master_data_management.php 唯讀檢視該客戶主檔（基本資料／結帳付款…）。
     * 未建主檔的客戶（bad=1）給不出 customer_id，前端一律不做成連結。 */
    $lost = array_values(array_filter($res['clients'], function ($c) { return $c['flag'] === 'lost'; }));
    if ($lost) {
        usort($lost, function ($a, $b) use ($mk) { return $b['cmp'][$mk] <=> $a['cmp'][$mk]; });
        $sum = 0.0; foreach ($lost as $c) $sum += (float)$c['cmp'][$mk];
        $names = array_slice(array_map(function ($c) { return $c['name']; }, $lost), 0, 6);
        $lostList = array_map(function ($c) use ($mk) {
            return ['cid' => (string)$c['cid'], 'name' => $c['name'], 'amount' => (float)$c['cmp'][$mk],
                    'bad' => !empty($c['bad'])];
        }, $lost);
        $add('bad', '有 ' . count($lost) . ' 家客戶本期完全沒有下單',
             $cl . '合計 ' . $fmt($sum) . ($useAmt ? ' 元' : ' 支') . '，'
             . implode('、', $names) . (count($lost) > 6 ? ' 等' : '') . '。建議業務逐一聯繫確認原因。',
             count($lost) . ' 家',
             ['clients' => $lostList, 'unit' => $useAmt ? '元' : '支', 'cmp_label' => $cl]);
    }

    /* ④a／④b 新客戶／回流客戶——同樣附完整名單供前端展開點選；amount 一律用**本期**數字
     * （這兩種客戶本期都有實際下單，跟流失客戶「本期沒有、要看基期」剛好相反）。 */
    $newCli = array_values(array_filter($res['clients'], function ($c) { return $c['flag'] === 'new'; }));
    $retCli = array_values(array_filter($res['clients'], function ($c) { return $c['flag'] === 'return'; }));
    $mkClientList = function (array $rows) use ($mk) {
        usort($rows, function ($a, $b) use ($mk) { return $b['cur'][$mk] <=> $a['cur'][$mk]; });
        return array_map(function ($c) use ($mk) {
            return ['cid' => (string)$c['cid'], 'name' => $c['name'], 'amount' => (float)$c['cur'][$mk], 'bad' => !empty($c['bad'])];
        }, $rows);
    };
    if ($newCli) {
        $sum = 0.0; foreach ($newCli as $c) $sum += (float)$c['cur'][$mk];
        $names = array_slice(array_map(function ($c) { return $c['name']; }, $newCli), 0, 6);
        $add('good', '本期新增 ' . count($newCli) . ' 家新客戶',
             '系統裡本期才第一次出現，合計' . ($useAmt ? '金額' : '數量') . ' ' . $fmt($sum) . ($useAmt ? ' 元' : ' 支') . '，'
             . implode('、', $names) . (count($newCli) > 6 ? ' 等' : '') . '。',
             count($newCli) . ' 家',
             ['clients' => $mkClientList($newCli), 'unit' => $useAmt ? '元' : '支', 'cmp_label' => '本期']);
    }
    if ($retCli) {
        $sum = 0.0; foreach ($retCli as $c) $sum += (float)$c['cur'][$mk];
        $names = array_slice(array_map(function ($c) { return $c['name']; }, $retCli), 0, 6);
        $add('info', '本期 ' . count($retCli) . ' 家回流客戶',
             '以前就有往來，只是' . $cl . '剛好沒下單、這期又回來，合計' . ($useAmt ? '金額' : '數量') . ' ' . $fmt($sum)
             . ($useAmt ? ' 元' : ' 支') . '，' . implode('、', $names) . (count($retCli) > 6 ? ' 等' : '')
             . '（不是新開發的客源，要問的是「之前為什麼停了」）。',
             count($retCli) . ' 家',
             ['clients' => $mkClientList($retCli), 'unit' => $useAmt ? '元' : '支', 'cmp_label' => '本期']);
    }

    /* ④c 客戶對不到客戶主檔——本期實際還在下單、但名稱在客戶主檔比不到的那幾家，
     * 附本期金額方便判斷影響大小，同樣可展開點名單（未建主檔者不給開唯讀檢視）。 */
    $badCli = array_values(array_filter($res['clients'], function ($c) { return !empty($c['bad']) && (float)$c['cur']['orders'] > 0; }));
    if ($badCli) {
        usort($badCli, function ($a, $b) use ($mk) { return $b['cur'][$mk] <=> $a['cur'][$mk]; });
        $sum = 0.0; foreach ($badCli as $c) $sum += (float)$c['cur'][$mk];
        $names = array_slice(array_map(function ($c) { return $c['name']; }, $badCli), 0, 6);
        $add('warn', '有 ' . count($badCli) . ' 個客戶名稱對不到客戶主檔',
             '本期合計' . ($useAmt ? '金額' : '數量') . ' ' . $fmt($sum) . ($useAmt ? ' 元' : ' 支') . '，'
             . implode('、', $names) . (count($badCli) > 6 ? ' 等' : '') . '——這些訂單被各自當成獨立客戶處理，'
             . '請到會計的對帳作業建別名歸戶。', count($badCli) . ' 個',
             ['clients' => $mkClientList($badCli), 'unit' => $useAmt ? '元' : '支', 'cmp_label' => '本期']);
    }

    /* ⑤ 新料號貢獻 */
    if ($cur['parts'] > 0) {
        $np = round($cur['new_parts'] * 100 / $cur['parts'], 1);
        $no = $cur['orders'] ? round($cur['new_orders'] * 100 / $cur['orders'], 1) : 0;
        $lv = $np >= 30 ? 'good' : ($np <= 10 ? 'warn' : 'info');
        $add($lv, '新料號佔本期料號 ' . $np . '%',
             '本期 ' . $fmt($cur['parts']) . ' 支料號裡有 ' . $fmt($cur['new_parts']) . ' 支是系統裡第一次出現，'
             . '帶來 ' . $fmt($cur['new_orders']) . ' 筆訂單（佔 ' . $no . '%）'
             . ($useAmt ? ('、金額 ' . $fmt($cur['new_amount']) . ' 元') : '') . '。'
             . ($np <= 10 ? '新案源偏少，營收會越來越依賴既有料號的重複下單。' : ''), $np . '%');
    }

    /* ⑥ 全製／單製結構
     * 分母刻意排除「AS 認定為不分單製全製」的訂單（excluded，如廠內治具／其他非加工）——
     * 那些訂單不屬於這個維度，算進分母只會把比例稀釋掉，見本函式上方 cls_src 的設計說明。 */
    $curBase = $cur['orders'] - (int)($cur['excluded'] ?? 0);
    $cmpBase = $cmp['orders'] - (int)($cmp['excluded'] ?? 0);
    if ($curBase > 0 && $cmpBase > 0) {
        $f0 = round($cur['full'] * 100 / $curBase, 1);
        $f1 = round($cmp['full'] * 100 / $cmpBase, 1);
        $d  = round($f0 - $f1, 1);
        if (abs($d) >= 5) {
            $add($d < 0 ? 'warn' : 'good', '全製比例' . ($d < 0 ? '下降' : '上升'),
                 '本期全製佔 ' . $f0 . '%（' . $fmt($cur['full']) . ' 筆／' . $fmt($curBase) . ' 筆適用），' . $cl . ' ' . $f1 . '%。'
                 . ($d < 0 ? '全製單通常單價與毛利較高，比例下降會直接稀釋整體金額。' : ''), ($d > 0 ? '+' : '') . $d . '個百分點');
        }
    }

    /* ⑥a AS 稽核分類（依訂單追蹤「稽核製程標籤」人工認定，不是關鍵字猜的）──
     * 兩件事：提醒還有多少訂單沒設定（會直接影響上面 ⑥ 的可信度），
     * 以及已設定的那些裡面，認定結果是不是集中在某一類。 */
    $astagInfo = $res['astag'] ?? null;
    if ($astagInfo) {
        $astagUnsetRow   = $astagInfo['unset'] ?? null;
        $astagUnsetOrders = (int)($astagUnsetRow['cur']['orders'] ?? 0);
        if ($cur['orders'] > 0 && $astagUnsetOrders > 0) {
            $up = round($astagUnsetOrders * 100 / $cur['orders'], 1);
            $add($up >= 50 ? 'warn' : 'info', '本期有 ' . $fmt($astagUnsetOrders) . ' 筆訂單尚未設定稽核製程標籤',
                 '佔本期訂單 ' . $up . '%，這些訂單在 AS 稽核分類（全製／單製○○／多製程／廠內治具…）裡查不到認定結果，'
                 . '建議到訂單追蹤逐筆補設定，上面「全製比例」的計算也只看得到已設定的那一部分。', $up . '%');
        }
        $astagVals = [];
        foreach (($astagInfo['rows'] ?? []) as $t) {
            if ($t['key'] === 'unset') continue;
            $v = (float)$t['cur'][$mk];
            if ($v > 0) $astagVals[] = ['n' => $t['label'], 'v' => $v];
        }
        $astagTot = 0.0; foreach ($astagVals as $v) $astagTot += $v['v'];
        if ($astagTot > 0 && $astagVals) {
            usort($astagVals, function ($a, $b) { return $b['v'] <=> $a['v']; });
            $atop = $astagVals[0];
            $ap = round($atop['v'] * 100 / $astagTot, 1);
            if ($ap >= 40) {
                $add('info', 'AS 認定分類集中在「' . $atop['n'] . '」',
                     '已設定標籤的訂單裡，「' . $atop['n'] . '」佔已分類' . ($useAmt ? '金額' : '數量') . ' 的 ' . $ap . '%。', $ap . '%');
            }
        }
    }

    /* ⑦ 數量區間結構：小量單變多＝換線與管理成本上升 */
    $bands = $res['bands'];
    if ($bands && $cur['orders'] > 0) {
        $b0 = $bands[0];
        if ((float)$b0['pct_orders'] >= 30) {
            $add('warn', '小量訂單佔比偏高', '數量在「' . $b0['label'] . '」的訂單有 ' . $fmt($b0['orders'])
                 . ' 筆、佔 ' . $b0['pct_orders'] . '%，但只貢獻 ' . $b0['pct_qty'] . '% 的數量'
                 . ($useAmt ? ('、' . $b0['pct_amount'] . '% 的金額') : '') . '。換線與管理成本會被這一段吃掉。',
                 $b0['pct_orders'] . '%');
        }
    }

    /* ⑧ 未開價訂單（這是資料品質，不是業績） */
    $noPx = (int)$cur['orders'] - (int)$cur['px_orders'];
    if ($noPx > 0) {
        $add($m['px_cov_cur'] < 80 ? 'warn' : 'info', '本期有 ' . $fmt($noPx) . ' 筆訂單沒有填單價',
             '這些訂單的金額一律以 0 計，所有金額類的數字都會被低估（本期覆蓋率 ' . $m['px_cov_cur'] . '%）。'
             . '要讓金額分析可信，請補上單價。', $m['px_cov_cur'] . '%');
    }

    /* ⑨ 訂單 KPI 未達標 → 本月要衝刺 */
    if ($kpiAlert && !empty($kpiAlert['below'])) {
        $g = $kpiAlert['month_gap'];
        $add('bad', '最近 ' . $kpiAlert['n'] .' 個月有 ' . $kpiAlert['bad_count'] . ' 個月訂單 KPI 未達標',
             '未達標月份：' . implode('、', $kpiAlert['bad_list']) . '。'
             . ($g === null ? '本年度沒有設定每月受訂目標金額，算不出本月還差多少。'
                            : ('本月目標 ' . $fmt($kpiAlert['month_target']) . ' 元，目前已接 ' . $fmt($kpiAlert['month_got'])
                               . ' 元，' . ($g > 0 ? ('還差 ' . $fmt($g) . ' 元、剩 ' . $kpiAlert['days_left'] . ' 天')
                                                  : '已達標'))) . '。',
             $kpiAlert['bad_count'] . '/' . $kpiAlert['n'] . ' 個月');
    }

    /* ⑩ 移動平均 */
    if ($ma && !empty($ma['series'])) {
        $last = $ma['last'];
        if (!empty($ma['hit'])) {
            $add('bad', '訂單金額移動平均已連續 ' . $ma['streak'] . ' 個月低於安全水平',
                 '最近一期（' . $last['ym'] . '）前 ' . $ma['months'] . ' 個月移動平均 ' . $fmt($last['avg'])
                 . ' 元，低於安全水平 ' . $fmt($last['threshold']) . ' 元。', $ma['streak'] . ' 個月');
        } elseif (!empty($last['unreliable'])) {
            $add('info', '移動平均暫時無法評估',
                 '「' . implode('、', $last['unreliable_months']) . '」這幾個月有填單價的訂單不到 '
                 . $ma['min_coverage'] . '%，金額不可信，已排除在評估之外（避免發出假警報）。');
        }
    }

    return $out;
}

/**
 * 綜合 oa_insights() 算出的各項自動分析結論，給業務具體的「建議採取」行動
 * （2026-10-06 使用者交辦：「這邊要綜合自動分析各種結果建議業務要採取什麼樣的行為」）。
 * 刻意不重新查一次資料庫——純粹依「哪些結論出現了」對應出行動建議，資料只來自
 * $insights（oa_insights() 的回傳），避免同一件事判斷兩次、兩邊結論對不起來（鐵律4）。
 * @return array 每筆 ['level','title','actions'=>[逐條具體行動字串]]，依嚴重度排序
 */
function oa_recommend(array $insights): array
{
    $out = [];
    $find = function (string $needle) use ($insights) {
        foreach ($insights as $i) if (mb_strpos((string)($i['title'] ?? ''), $needle, 0, 'UTF-8') !== false) return $i;
        return null;
    };
    $add = function ($level, $title, array $actions) use (&$out) {
        $out[] = ['level' => $level, 'title' => $title, 'actions' => $actions];
    };

    if ($find('金額衰退') || $find('數量衰退') || $find('連續兩期下滑')) {
        $add('bad', '業績下滑，建議優先處理', [
            '安排拜訪或致電前三大客戶，確認後續訂單狀況與排程',
            '檢視「流失客戶」清單，逐一聯繫確認停止下單的原因',
            '檢討近期報價轉換率，加快新案開發速度補上缺口',
        ]);
    }
    if ($find('客戶集中度偏高')) {
        $add('warn', '降低客戶集中度風險', [
            '安排業務開發新客戶，分散對前三大客戶的依賴',
            '與集中度最高的那幾家客戶保持更密集聯繫，及早掌握訂單變化',
        ]);
    }
    if ($find('本期完全沒有下單')) {
        $add('bad', '逐一聯繫流失客戶', [
            '依「流失客戶」清單，優先聯繫金額最大的前幾家',
            '了解停止下單的原因（轉單同業／價格／交期／品質），記錄下來供下次報價參考',
        ]);
    }
    if ($find('對不到客戶主檔')) {
        $add('warn', '補齊客戶主檔歸戶', [
            '請會計到「對帳作業」建立客戶別名歸戶，讓同一家客戶的訂單能正確合併統計',
        ]);
    }
    $npIns = $find('新料號佔本期料號');
    if ($npIns && mb_strpos((string)($npIns['detail'] ?? ''), '新案源偏少', 0, 'UTF-8') !== false) {
        $add('warn', '加強新案開發', [
            '新案源偏少，建議業務加強報價開發力道，避免營收過度依賴既有料號的重複下單',
        ]);
    }
    if ($find('全製比例下降')) {
        $add('warn', '檢討全製案件比例', [
            '全製單通常毛利較高，建議檢討報價策略，爭取更多全製案件或調整單製報價',
        ]);
    }
    if ($find('尚未設定稽核製程標籤')) {
        $add('info', '補齊稽核製程標籤', [
            '儘速到訂單追蹤逐筆補設定稽核製程標籤（AS 認定），否則全製/單製與 AS 稽核分類的統計都不準確',
        ]);
    }
    if ($find('小量訂單佔比偏高')) {
        $add('info', '檢討小量訂單處理方式', [
            '評估是否能合併生產排程、或調整報價門檻反映換線與管理成本',
        ]);
    }
    if ($find('沒有填單價')) {
        $add('warn', '補齊訂單單價', [
            '儘速補上未填單價的訂單，否則金額類分析（成長率、客戶排名、全製比例金額等）都會被低估',
        ]);
    }
    if ($find('訂單 KPI 未達標')) {
        $add('bad', '加強本月衝刺', [
            '鎖定目標客戶加速下單轉換，優先跟進已報價但尚未轉單的案件',
        ]);
    }
    if ($find('移動平均已連續')) {
        $add('bad', '加碼業務開發力道', [
            '近期接單量持續低於安全水平，建議加強開發力道並檢視報價中案件的進度',
        ]);
    }
    if (!$out) {
        $add('good', '本期沒有特別需要處理的異常', ['維持目前的業務節奏即可，持續關注下方自動分析的各項指標。']);
    }
    $pri = ['bad' => 0, 'warn' => 1, 'good' => 2, 'info' => 3];
    usort($out, function ($a, $b) use ($pri) { return ($pri[$a['level']] ?? 9) <=> ($pri[$b['level']] ?? 9); });
    return $out;
}

/**
 * 畫面／列印／通知一律呼叫這一支：分析結果＋KPI 提醒＋移動平均＋自動分析。
 * 三個附掛區塊刻意不寫進 oa_analyze()——那支是純計算，而這三個會去讀 KPI 模組與設定。
 */
function oa_report(PDO $db, array $opt = []): array
{
    $res = oa_analyze($db, $opt);
    $kpi = null; $ma = null;
    try { $kpi = oa_kpi_alert($db); }   catch (Throwable $e) { $kpi = ['enabled' => 0, 'error' => $e->getMessage()]; }
    try { $ma  = oa_moving_avg($db); }  catch (Throwable $e) { $ma  = ['enabled' => 0, 'error' => $e->getMessage()]; }
    $res['kpi_alert'] = $kpi;
    $res['ma']        = $ma;
    try { $res['insights'] = oa_insights($db, $res, $kpi, $ma); }
    catch (Throwable $e) { $res['insights'] = []; }
    try { $res['recommend'] = oa_recommend($res['insights']); }
    catch (Throwable $e) { $res['recommend'] = []; }
    return $res;
}

/** 有訂單資料的年度（下拉用） */
function oa_years(PDO $db): array
{
    $ys = [];
    try {
        foreach ($db->query("SELECT DISTINCT YEAR(Order_date) y FROM order_track ORDER BY y DESC") as $r) {
            $y = (int)$r['y'];
            if ($y >= 2000 && $y <= 2100) $ys[] = $y;
        }
    } catch (Throwable $e) {}
    if (!$ys) $ys[] = (int)date('Y');
    return $ys;
}

/* ══════════════════════════════════════════════════════════════════
 * 交期工作天數分析 ＋ 急件判定（2026-10-06 使用者交辦）
 *
 * 「交期工作天數」＝下單日到交期之間扣掉假日的工作天數（不含下單當天本身，
 * 同一天下單同一天交＝0 個工作天，是最緊急的情況）。假日／補班日沿用 KPI 模組
 * 既有的行事曆（kpi_as_workdays_inclusive，car_holiday_sets 靜態快取，效能無虞）。
 *
 * 「急件」＝依 全製／多製程／單製 三個類別**各自**統計交期工作天數的分布，
 * 取最短的前 N%（百分位門檻由管理員設定，逐類別可不同）當門檻，交期工作天數
 * ≦ 門檻者即視為急件。門檻永遠用「全部客戶、同一期間」的分布去算（不受客戶
 * 篩選影響，否則篩出一家客戶之後百分位會失真、不同客戶看到的「急件」定義不一樣）。
 *
 * 只有 full／multi／single 三類納入急件判定（excluded／none／unknown 不是真正的
 * 製程分類，沒有「交期承諾」的可比基礎，不列入）。
 * ══════════════════════════════════════════════════════════════════ */

/** 交期工作天數：缺日期或交期早於下單日（資料異常）回 null，不計入任何統計 */
function oa_leadtime_workdays(PDO $db, string $orderDate, string $deliveryDate): ?int
{
    $orderDate = substr($orderDate, 0, 10); $deliveryDate = substr($deliveryDate, 0, 10);
    if ($orderDate === '' || $deliveryDate === '' || $orderDate < '2000-01-01' || $deliveryDate < '2000-01-01') return null;
    if ($deliveryDate < $orderDate) return null;
    require_once __DIR__ . '/kpi_as_lib.php';
    return max(0, kpi_as_workdays_inclusive($db, $orderDate, $deliveryDate) - 1);
}

/** 稽核製程標籤 scope → 本頁通用的 cls 鍵，與 oa_analyze() 同一套對照，抽成共用避免兩處各自維護 */
function oa_scope_to_cls(?array $astagInfo): string
{
    if (!$astagInfo) return 'unknown';
    switch ((string)($astagInfo['scope'] ?? '')) {
        case 'full':   return 'full';
        case 'multi':  return 'multi';
        case 'single': return 'single';
        case 'none':   return 'excluded';
        default:       return 'unknown';
    }
}
/** cls 鍵 → 顯示文字（涵蓋 oa_proc_classes() 沒有的 multi/excluded） */
function oa_cls_label(string $cls): string
{
    static $m = ['full' => '全製', 'multi' => '多製程', 'single' => '單製',
                 'excluded' => '不分單製全製（治具等）', 'none' => '未填製程', 'unknown' => '尚未設定標籤'];
    return $m[$cls] ?? $cls;
}
/** 急件判定納入的三個類別（與排除在外的 excluded/none/unknown 分開） */
function oa_urgent_classes(): array { return ['full', 'multi', 'single']; }

/** 線性內插百分位數（$sortedAsc 須已由小到大排序）；$pct 0~100 */
function oa_percentile(array $sortedAsc, float $pct): ?float
{
    $n = count($sortedAsc);
    if ($n === 0) return null;
    if ($n === 1) return (float)$sortedAsc[0];
    $pct = max(0.0, min(100.0, $pct));
    $idx = ($pct / 100) * ($n - 1);
    $lo = (int)floor($idx); $hi = (int)ceil($idx);
    if ($lo === $hi) return (float)$sortedAsc[$lo];
    $frac = $idx - $lo;
    return (float)$sortedAsc[$lo] + ((float)$sortedAsc[$hi] - (float)$sortedAsc[$lo]) * $frac;
}

/* ── 急件判定設定：逐類別百分位，管理員可調（獨立一把 key，概念上與 alert_settings 不同） ── */
function oa_urgent_settings_default(): array
{
    return ['percentile' => ['full' => 20, 'multi' => 20, 'single' => 20]];
}
function oa_urgent_settings(PDO $db): array
{
    $d = oa_urgent_settings_default();
    $s = oa_param_get($db, 'urgent_settings', null);
    if (!is_array($s) || !isset($s['percentile']) || !is_array($s['percentile'])) return $d;
    $out = $d;
    foreach ($d['percentile'] as $k => $v) {
        if (isset($s['percentile'][$k]) && is_numeric($s['percentile'][$k])) {
            $out['percentile'][$k] = max(1, min(100, (int)$s['percentile'][$k]));
        }
    }
    return $out;
}
function oa_urgent_settings_save(PDO $db, array $in, string $by): array
{
    $cur = oa_urgent_settings_default();
    $errs = [];
    if (isset($in['percentile']) && is_array($in['percentile'])) {
        foreach ($cur['percentile'] as $k => $v) {
            if (!isset($in['percentile'][$k])) continue;
            if (!is_numeric($in['percentile'][$k])) { $errs[] = '「' . oa_cls_label($k) . '」的急件百分比必須是數字'; continue; }
            $cur['percentile'][$k] = max(1, min(100, (int)$in['percentile'][$k]));
        }
    }
    if ($errs) return ['ok' => false, 'errors' => $errs];
    oa_param_save($db, 'urgent_settings', $cur, $by);
    return ['ok' => true, 'settings' => $cur];
}

/**
 * 交期工作天數 ＋ 急件分析主體。
 * 一律以「下單日」當期間歸屬（問的是「這一期接到的訂單裡，交期短的有多少」，
 * 與主畫面可切換的日期基準是不同問題，這裡固定用下單日，比照新訂單／KPI 等既有判斷）。
 */
function oa_leadtime_report(PDO $db, array $opt = []): array
{
    $year  = max(2000, min(2100, (int)($opt['year'] ?? date('Y'))));
    $gran  = isset(oa_grans()[$opt['gran'] ?? '']) ? (string)$opt['gran'] : 'quarter';
    $idx   = max(1, (int)($opt['idx'] ?? 1));
    $cmpK  = isset(oa_compares()[$opt['cmp'] ?? '']) ? (string)$opt['cmp'] : 'yoy';
    $align = !array_key_exists('align', $opt) || !empty($opt['align']);
    $incP  = !empty($opt['include_paused']);
    $sel   = array_values(array_filter(array_map('strval', (array)($opt['clients'] ?? []))));
    $selMap = $sel ? array_flip($sel) : [];

    $cur  = oa_period_pick($year, $gran, $idx);
    $cmpP = oa_compare_period($year, $gran, $idx, $cmpK);
    $elap = oa_elapsed_days($cur);
    $cmpE = $align ? oa_cap_period($cmpP, $elap) : $cmpP;

    $from = min($cur['start'], $cmpP['start']);
    $to   = max($cur['end'], $cmpP['end']);
    $rows = oa_fetch_orders($db, $from, $to, ['basis' => 'order', 'include_paused' => $incP]);

    $astagMap = ot_astag_for_orders($db, array_column($rows, 'id'));
    $bands = oa_qty_bands($db);
    foreach ($rows as &$r) {
        $r['cls']  = oa_scope_to_cls($astagMap[$r['id']] ?? null);
        $r['lt']   = oa_leadtime_workdays($db, $r['odate'], $r['ddate']);
        $r['band'] = oa_band_index((int)$r['qty'], $bands);
    }
    unset($r);

    // 急件判定百分位：管理員預設值一律先算出來，使用者可在畫面上改成「僅本次計算」的覆寫值
    // ——兩者都要原樣回傳，畫面／報告上才能同時清楚印出「這次用的值」與「管理員預設值」，
    // 覆寫值刻意不寫回 oa_urgent_settings()，不影響管理員設定。
    $usDefault = oa_urgent_settings($db);
    $us = $usDefault;
    $override = $opt['urgent_pct'] ?? null;
    if (is_array($override)) {
        foreach ($us['percentile'] as $k => $v) {
            if (isset($override[$k]) && is_numeric($override[$k])) {
                $us['percentile'][$k] = max(1, min(100, (int)$override[$k]));
            }
        }
    }
    $isOverride = $us['percentile'] !== $usDefault['percentile'];
    $urgentClasses = array_flip(oa_urgent_classes());

    $build = function (array $p) use ($rows, $selMap, $us, $urgentClasses, $bands) {
        $inRange = function (array $r) use ($p) { return $r['dt'] >= $p['start'] && $r['dt'] <= $p['end']; };
        $inSel   = function (array $r) use ($selMap) { return !$selMap || isset($selMap[$r['ckey']]); };
        $all = array_values(array_filter($rows, $inRange));                       // 全部客戶（門檻計算基礎）

        // 門檻只用「有交期工作天數資料」的全部客戶分布算，不受客戶篩選影響
        $byClassLt = [];
        foreach ($all as $r) { if ($r['lt'] !== null && isset($urgentClasses[$r['cls']])) $byClassLt[$r['cls']][] = $r['lt']; }
        $thr = [];
        foreach ($byClassLt as $cls => $arr) { sort($arr); $thr[$cls] = oa_percentile($arr, (float)($us['percentile'][$cls] ?? 20)); }

        $selAll = array_values(array_filter($all, $inSel));
        $noLt   = count(array_filter($selAll, function ($r) { return $r['lt'] === null; }));
        $set    = array_values(array_filter($selAll, function ($r) { return $r['lt'] !== null; }));

        $byCls = []; $bandAgg = []; $clientAgg = []; $urgentList = [];
        $amountAll = 0.0; $ordersAll = 0; $urgentAmount = 0.0; $urgentN = 0;

        foreach ($set as $r) {
            $cls = $r['cls']; $lt = (int)$r['lt'];
            $ordersAll++; $amountAll += $r['amount'];
            if (!isset($byCls[$cls])) {
                $byCls[$cls] = ['cls' => $cls, 'label' => oa_cls_label($cls), 'n' => 0, 'sum_lt' => 0,
                                'min' => null, 'max' => null, 'vals' => [], 'amount' => 0.0,
                                'threshold' => $thr[$cls] ?? null, 'urgent_n' => 0, 'urgent_amount' => 0.0,
                                'is_urgent_class' => isset($urgentClasses[$cls]) ? 1 : 0];
            }
            $b = &$byCls[$cls];
            $b['n']++; $b['sum_lt'] += $lt; $b['vals'][] = $lt; $b['amount'] += $r['amount'];
            $b['min'] = $b['min'] === null ? $lt : min($b['min'], $lt);
            $b['max'] = $b['max'] === null ? $lt : max($b['max'], $lt);

            $t = $thr[$cls] ?? null;
            $isUrgent = isset($urgentClasses[$cls]) && $t !== null && $lt <= $t;
            if ($isUrgent) {
                $b['urgent_n']++; $b['urgent_amount'] += $r['amount'];
                $urgentN++; $urgentAmount += $r['amount'];
                if (!isset($clientAgg[$r['ckey']])) {
                    $clientAgg[$r['ckey']] = ['ckey' => $r['ckey'], 'name' => $r['cname'], 'cid' => $r['cid'],
                                              'bad' => $r['cbad'], 'n' => 0, 'amount' => 0.0];
                }
                $clientAgg[$r['ckey']]['n']++; $clientAgg[$r['ckey']]['amount'] += $r['amount'];
                $urgentList[] = ['no' => $r['no'], 'c_order' => $r['c_order'], 'cname' => $r['cname'],
                                 'pno' => $r['pno'], 'pid' => $r['pid'], 'odate' => $r['odate'], 'ddate' => $r['ddate'],
                                 'lt' => $lt, 'cls' => $cls, 'label' => oa_cls_label($cls),
                                 'amount' => $r['amount'], 'qty' => $r['qty'],
                                 'created_by_name' => $r['created_by_name'], 'created_at' => $r['created_at']];
            }
            $bk = (int)$r['band'];
            if (!isset($bandAgg[$bk])) $bandAgg[$bk] = ['band' => $bk, 'label' => $bk >= 0 ? ($bands[$bk]['label'] ?? '') : '未涵蓋', 'n' => 0, 'urgent_n' => 0];
            $bandAgg[$bk]['n']++;
            if ($isUrgent) $bandAgg[$bk]['urgent_n']++;
        }
        unset($b);
        foreach ($byCls as &$b) {
            sort($b['vals']);
            $b['avg']          = $b['n'] ? round($b['sum_lt'] / $b['n'], 1) : null;
            $b['median']       = oa_percentile($b['vals'], 50);
            $b['urgent_ratio'] = $b['n'] ? round($b['urgent_n'] * 100 / $b['n'], 1) : 0.0;
            unset($b['vals']);
        }
        unset($b);
        ksort($bandAgg);
        $clientList = array_values($clientAgg);
        usort($clientList, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
        usort($urgentList, function ($a, $b) { return $a['lt'] <=> $b['lt']; });

        return [
            'orders' => $ordersAll, 'amount' => $amountAll, 'no_leadtime' => $noLt,
            'urgent_orders' => $urgentN, 'urgent_amount' => $urgentAmount,
            'urgent_order_ratio'  => $ordersAll ? round($urgentN * 100 / $ordersAll, 1) : 0.0,
            'urgent_amount_ratio' => $amountAll > 0 ? round($urgentAmount * 100 / $amountAll, 1) : 0.0,
            'urgent_clients' => count($clientList),
            'client_list' => array_slice($clientList, 0, 50),
            'by_cls' => array_values($byCls),
            'by_band' => array_values($bandAgg),
            'urgent_list' => array_slice($urgentList, 0, 200),
            'threshold' => $thr,
        ];
    };

    $curRes = $build($cur);
    $cmpRes = $build($cmpE);

    // 急件客戶排行每一列再補兩個數字：①佔本期急件總額的比例（區間內總體佔比）
    // ②跟基期同一家客戶的急件金額比較之增減比例——兩者都是「使用者看了名單會立刻想問」的問題，
    // 不補的話名單只是一串數字，看不出誰在變多、誰佔比特別高。
    $cmpByClient = [];
    foreach ($cmpRes['client_list'] as $c) { $cmpByClient[$c['ckey']] = $c; }
    foreach ($curRes['client_list'] as &$c) {
        $c['pct_of_total'] = $curRes['urgent_amount'] > 0 ? round($c['amount'] * 100 / $curRes['urgent_amount'], 1) : 0.0;
        $prev = $cmpByClient[$c['ckey']] ?? null;
        $c['cmp_amount'] = $prev ? $prev['amount'] : 0.0;
        $c['cmp_n']      = $prev ? $prev['n']      : 0;
        if ($c['cmp_amount'] > 0) {
            $c['delta_pct'] = round(($c['amount'] - $c['cmp_amount']) * 100 / $c['cmp_amount'], 1);
        } else {
            $c['delta_pct'] = null;   // 基期 0（含基期沒有這家客戶的急件）：算不出有意義的百分比，前端顯示「新增」
        }
    }
    unset($c);

    return [
        'period' => $cur, 'cmp_period' => $cmpE, 'cmp_mode' => $cmpK,
        'settings' => $us, 'settings_default' => $usDefault, 'is_override' => $isOverride ? 1 : 0,
        'cur' => $curRes, 'cmp' => $cmpRes,
    ];
}

/** 急件自動分析（每一條都附具體數字） */
function oa_urgent_insights(array $rep): array
{
    $out = [];
    // $clients 有值時畫面會顯示「展開名單」（與總覽分頁流失客戶等既有結論共用同一套前端元件
    // oaRenderInsClientGrid()／oaToggleInsList()，不再各自刻一份）；$unit 決定欄位顯示「元」或「支」。
    $add = function ($level, $title, $detail, $metric = null, $clients = null, $unit = '元', $cmpLabel = '急件') use (&$out) {
        $item = ['level' => $level, 'title' => $title, 'detail' => $detail, 'metric' => $metric];
        if ($clients) { $item['clients'] = $clients; $item['unit'] = $unit; $item['cmp_label'] = $cmpLabel; }
        $out[] = $item;
    };
    $cur = $rep['cur']; $cmp = $rep['cmp'];

    if (!$cur['orders']) {
        $add('info', '本期沒有可供判定的交期資料', '這一期沒有訂單，或訂單缺下單日／交期。');
        return $out;
    }
    if ($cur['no_leadtime'] > 0) {
        $add('info', '有訂單缺交期資料未納入急件判定',
             '本期 ' . $cur['no_leadtime'] . ' 張訂單缺下單日或交期（或交期早於下單日），這幾張不計入急件統計。');
    }

    $d = $cur['urgent_order_ratio'] - $cmp['urgent_order_ratio'];
    $lvl = abs($d) < 1 ? 'info' : ($d > 0 ? 'warn' : 'good');
    $add($lvl, '急件比例' . ($d > 0 ? '上升' : ($d < 0 ? '下降' : '持平')),
         '本期急件 ' . $cur['urgent_orders'] . ' 張／共 ' . $cur['orders'] . ' 張（' . $cur['urgent_order_ratio'] . '%），'
        . '基期 ' . $cmp['urgent_order_ratio'] . '%，' . ($d >= 0 ? '增加 ' : '減少 ') . number_format(abs($d), 1) . ' 個百分點。',
         ($d >= 0 ? '+' : '') . number_format($d, 1) . 'pp');

    if ($cur['urgent_orders'] > 0) {
        $add('info', '急件金額佔比', '本期急件訂單金額佔整體 ' . $cur['urgent_amount_ratio'] . '%'
            . '（急件 ' . number_format($cur['urgent_amount']) . ' 元／整體 ' . number_format($cur['amount']) . ' 元）。');

        $top3 = array_slice($cur['client_list'], 0, 3);
        $top3amt = array_sum(array_column($top3, 'amount'));
        $conc = $cur['urgent_amount'] > 0 ? round($top3amt * 100 / $cur['urgent_amount'], 1) : 0;
        if ($top3 && $conc >= 50) {
            $names = implode('、', array_column($top3, 'name'));
            $add('warn', '急件集中在少數客戶', '急件金額前 3 大客戶（' . $names . '）就佔了急件總金額的 ' . $conc . '%，'
                . '這幾家客戶的交期安排值得優先關注（可點「展開名單」看完整清單）。',
                null, $cur['client_list']);
        }

        $byCls = $cur['by_cls'];
        usort($byCls, function ($a, $b) { return $b['urgent_ratio'] <=> $a['urgent_ratio']; });
        $top = null;
        foreach ($byCls as $b) { if ($b['is_urgent_class'] && $b['n'] >= 3) { $top = $b; break; } }
        if ($top) {
            $add('info', '「' . $top['label'] . '」急件比例最高',
                 $top['label'] . ' 類別本期 ' . $top['n'] . ' 張訂單中有 ' . $top['urgent_n'] . ' 張是急件（' . $top['urgent_ratio'] . '%），'
                . '平均交期工作天數 ' . $top['avg'] . ' 天、門檻 '
                . (($top['threshold'] === null) ? '尚未算出' : number_format($top['threshold'], 1) . ' 天') . '。');
        }
    } else {
        $add('good', '本期沒有符合急件門檻的訂單', '依目前設定的百分位門檻，本期交期工作天數都高於急件門檻。');
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * 客戶佔比報告：單一客戶在整體（全部客戶）裡的佔比，供畫面檢視與列印 A4 報告。
 * ══════════════════════════════════════════════════════════════════ */
/**
 * 客戶佔比報告（2026-10-06 使用者交辦，多輪追加）：可一次選多家客戶，每家各自顯示在整體裡的
 * 訂單/金額/數量佔比、排名、急件與交期工作天資料、製程類別分布、受訂料號排名（含標籤/製程/
 * 接單人員/平均工作天），並附逐月趨勢與自動分析。
 *
 * 多選時「整體」的分母只算一次（同一批 $rows、同一組門檻），每家客戶各自代入分子——
 * 不是對每家客戶各自重新抓一次資料，否則同一個期間、同一份整體數字理論上要一樣卻可能因為
 * 併發查詢時間差而兜不起來。
 */
function oa_client_share(PDO $db, array $opt = []): array
{
    $year  = max(2000, min(2100, (int)($opt['year'] ?? date('Y'))));
    $gran  = isset(oa_grans()[$opt['gran'] ?? '']) ? (string)$opt['gran'] : 'quarter';
    $idx   = max(1, (int)($opt['idx'] ?? 1));
    $basis = (($opt['basis'] ?? 'order') === 'delivery') ? 'delivery' : 'order';
    $cmpK  = isset(oa_compares()[$opt['cmp'] ?? '']) ? (string)$opt['cmp'] : 'yoy';
    $align = !array_key_exists('align', $opt) || !empty($opt['align']);
    $incP  = !empty($opt['include_paused']);
    $ckeys = array_values(array_filter(array_map('strval', (array)($opt['clients'] ?? []))));
    if (!$ckeys && !empty($opt['client'])) $ckeys = [(string)$opt['client']];   // 相容單一客戶的舊呼叫方式
    $ckeys = array_values(array_unique($ckeys));

    $cur  = oa_period_pick($year, $gran, $idx);
    $cmpP = oa_compare_period($year, $gran, $idx, $cmpK);
    $elap = oa_elapsed_days($cur);
    $cmpE = $align ? oa_cap_period($cmpP, $elap) : $cmpP;

    // 趨勢固定抓「選定年度整年」逐月，跟上面選的期間粒度脫鉤——這份報告要看的是這家客戶整年的
    // 走勢，不是只看目前選到的那一期；抓取範圍因此要涵蓋 本期／基期／選定年度 三者的聯集。
    $from = min($cur['start'], $cmpP['start'], $year . '-01-01');
    $to   = max($cur['end'],   $cmpP['end'],   $year . '-12-31');
    $rows = oa_fetch_orders($db, $from, $to, ['basis' => $basis, 'include_paused' => $incP]);
    $astagMap = ot_astag_for_orders($db, array_column($rows, 'id'));
    $bands = oa_qty_bands($db);
    $us = oa_urgent_settings($db);
    $urgentClasses = array_flip(oa_urgent_classes());
    foreach ($rows as &$r) {
        $info = $astagMap[$r['id']] ?? null;
        $r['cls']      = oa_scope_to_cls($info);
        $r['as_label'] = $info ? (string)$info['label'] : '';
        $r['lt']       = oa_leadtime_workdays($db, $r['odate'], $r['ddate']);
        $r['band']     = oa_band_index((int)$r['qty'], $bands);
    }
    unset($r);

    $nameMap = [];
    foreach ($rows as $r) { if (!isset($nameMap[$r['ckey']])) $nameMap[$r['ckey']] = $r['cname']; }

    $build = function (array $p) use ($rows, $ckeys, $urgentClasses, $us) {
        $inRange = function (array $r) use ($p) { return $r['dt'] >= $p['start'] && $r['dt'] <= $p['end']; };
        $all = array_values(array_filter($rows, $inRange));

        // 急件門檻只用「全部客戶、同一期間」的分布算，跟 oa_leadtime_report() 同一個道理
        $byClassLt = [];
        foreach ($all as $r) { if ($r['lt'] !== null && isset($urgentClasses[$r['cls']])) $byClassLt[$r['cls']][] = $r['lt']; }
        $thr = [];
        foreach ($byClassLt as $cls => $arr) { sort($arr); $thr[$cls] = oa_percentile($arr, (float)($us['percentile'][$cls] ?? 20)); }

        $sum = function (array $set) use ($thr, $urgentClasses) {
            $o = count($set); $amt = 0.0; $qty = 0; $px = 0; $byCls = [];
            $urgentN = 0; $urgentAmt = 0.0; $ltSum = 0; $ltN = 0; $parts = [];
            foreach ($set as $r) {
                $amt += $r['amount']; $qty += $r['qty']; if ($r['haspx']) $px++;
                if (!isset($byCls[$r['cls']])) $byCls[$r['cls']] = ['cls' => $r['cls'], 'label' => oa_cls_label($r['cls']), 'n' => 0, 'amount' => 0.0];
                $byCls[$r['cls']]['n']++; $byCls[$r['cls']]['amount'] += $r['amount'];
                if ($r['lt'] !== null) { $ltSum += $r['lt']; $ltN++; }
                $isUrgent = $r['lt'] !== null && isset($urgentClasses[$r['cls']]) && isset($thr[$r['cls']]) && $thr[$r['cls']] !== null && $r['lt'] <= $thr[$r['cls']];
                if ($isUrgent) { $urgentN++; $urgentAmt += $r['amount']; }
                $k = $r['pkey'];
                if (!isset($parts[$k])) {
                    $parts[$k] = ['pno' => $r['pno'], 'pid' => $r['pid'], 'n' => 0, 'amount' => 0.0, 'qty' => 0,
                                  'tags' => [], 'procs' => [], 'creators' => [], 'lt_sum' => 0, 'lt_n' => 0];
                }
                $pp = &$parts[$k];
                $pp['n']++; $pp['amount'] += $r['amount']; $pp['qty'] += $r['qty'];
                if ($r['as_label'] !== '') $pp['tags'][$r['as_label']] = 1;
                if (trim($r['proc']) !== '') $pp['procs'][trim($r['proc'])] = 1;
                if ($r['created_by_name'] !== '') $pp['creators'][$r['created_by_name']] = 1;
                if ($r['lt'] !== null) { $pp['lt_sum'] += $r['lt']; $pp['lt_n']++; }
                unset($pp);
            }
            $partList = array_values($parts);
            foreach ($partList as &$pp2) {
                $pp2['tags']     = array_values(array_keys($pp2['tags']));
                $pp2['procs']    = array_values(array_keys($pp2['procs']));
                $pp2['creators'] = array_values(array_keys($pp2['creators']));
                $pp2['avg_leadtime'] = $pp2['lt_n'] ? round($pp2['lt_sum'] / $pp2['lt_n'], 1) : null;
                unset($pp2['lt_sum'], $pp2['lt_n']);
            }
            unset($pp2);
            usort($partList, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
            return ['orders' => $o, 'amount' => $amt, 'qty' => $qty, 'px_orders' => $px,
                    'urgent_orders' => $urgentN, 'urgent_amount' => $urgentAmt,
                    'urgent_ratio' => $o ? round($urgentN * 100 / $o, 1) : 0.0,
                    'avg_leadtime' => $ltN ? round($ltSum / $ltN, 1) : null,
                    'by_cls' => array_values($byCls), 'top_parts' => array_slice($partList, 0, 10)];
        };

        $totalAgg = $sum($all);

        $byClientAmt = [];
        foreach ($all as $r) { $byClientAmt[$r['ckey']] = ($byClientAmt[$r['ckey']] ?? 0) + $r['amount']; }
        arsort($byClientAmt);
        $rankMap = []; $i = 0;
        foreach ($byClientAmt as $k => $v) { $i++; $rankMap[$k] = $i; }

        $share = function ($num, $den) { return $den > 0 ? round($num * 100 / $den, 1) : 0.0; };

        $perClient = [];
        foreach ($ckeys as $ck) {
            $mine = array_values(array_filter($all, function ($r) use ($ck) { return $r['ckey'] === $ck; }));
            $mineAgg = $sum($mine);
            $perClient[$ck] = [
                'agg' => $mineAgg,
                'share_orders' => $share($mineAgg['orders'], $totalAgg['orders']),
                'share_amount' => $share($mineAgg['amount'], $totalAgg['amount']),
                'share_qty'    => $share($mineAgg['qty'],    $totalAgg['qty']),
                'rank' => $rankMap[$ck] ?? 0,
            ];
        }
        return ['total' => $totalAgg, 'total_clients' => count($byClientAmt), 'per_client' => $perClient];
    };

    $curRes = $build($cur);
    $cmpRes = $build($cmpE);

    // 逐月趨勢：固定整年，每個選定客戶各自一條序列，另加全體合計序列供對照／算佔比走勢。
    $months = oa_period_buckets($year, 'month');
    $trendTotal = []; $trendByClient = []; foreach ($ckeys as $ck) $trendByClient[$ck] = [];
    foreach ($months as $mb) {
        $mrows = array_values(array_filter($rows, function (array $r) use ($mb) { return $r['dt'] >= $mb['start'] && $r['dt'] <= $mb['end']; }));
        $tAmt = 0.0; $tOrd = 0;
        foreach ($mrows as $r) { $tAmt += $r['amount']; $tOrd++; }
        // 'done'＝這個月已經完整過完（本月還沒過完／未來月份都算未完成，拿去比較會失真）
        $done = $mb['end'] < date('Y-m-d') ? 1 : 0;
        $trendTotal[] = ['label' => $mb['label'], 'amount' => $tAmt, 'orders' => $tOrd, 'done' => $done];
        foreach ($ckeys as $ck) {
            $camt = 0.0; $cord = 0;
            foreach ($mrows as $r) { if ($r['ckey'] === $ck) { $camt += $r['amount']; $cord++; } }
            $trendByClient[$ck][] = ['label' => $mb['label'], 'amount' => $camt, 'orders' => $cord, 'done' => $done,
                                      'share' => $tAmt > 0 ? round($camt * 100 / $tAmt, 1) : 0.0];
        }
    }

    $clientsOut = [];
    foreach ($ckeys as $ck) {
        $pc = $curRes['per_client'][$ck]; $pp = $cmpRes['per_client'][$ck];
        $clientsOut[] = [
            'client' => ['key' => $ck, 'name' => $nameMap[$ck] ?? $ck],
            'cur' => ['agg' => $pc['agg'], 'share_orders' => $pc['share_orders'], 'share_amount' => $pc['share_amount'],
                      'share_qty' => $pc['share_qty'], 'rank' => $pc['rank']],
            'cmp' => ['agg' => $pp['agg'], 'share_orders' => $pp['share_orders'], 'share_amount' => $pp['share_amount'],
                      'share_qty' => $pp['share_qty'], 'rank' => $pp['rank']],
            'trend' => $trendByClient[$ck],
        ];
    }

    return [
        'period' => $cur, 'cmp_period' => $cmpE, 'cmp_mode' => $cmpK, 'year' => $year,
        'total_cur' => $curRes['total'], 'total_cmp' => $cmpRes['total'],
        'total_clients_cur' => $curRes['total_clients'], 'total_clients_cmp' => $cmpRes['total_clients'],
        'trend_months' => array_column($months, 'label'), 'trend_total' => $trendTotal,
        'clients' => $clientsOut,
    ];
}

/** 客戶佔比報告自動分析：逐家客戶各自判斷，每一條都附具體數字 */
function oa_client_share_insights(array $rep): array
{
    $out = [];
    $add = function ($level, $title, $detail, $metric = null) use (&$out) {
        $out[] = ['level' => $level, 'title' => $title, 'detail' => $detail, 'metric' => $metric];
    };
    $totalUrgentRatio = (float)($rep['total_cur']['urgent_ratio'] ?? 0);

    foreach ($rep['clients'] as $c) {
        $name = (string)$c['client']['name']; $cur = $c['cur']; $cmp = $c['cmp'];

        if (!$cur['agg']['orders'] && !$cmp['agg']['orders']) {
            $add('info', $name . '：這段期間沒有任何訂單', '本期與基期皆查無訂單資料。');
            continue;
        }
        if (!$cur['agg']['orders'] && $cmp['agg']['orders'] > 0) {
            $add('warn', $name . '：本期零下單', '基期曾有 ' . $cmp['agg']['orders'] . ' 張訂單（'
                . number_format($cmp['agg']['amount']) . ' 元），本期完全沒有下單，建議確認狀況。');
            continue;
        }

        $dAmt = $cur['share_amount'] - $cmp['share_amount'];
        if (abs($dAmt) >= 0.5) {
            $add($dAmt > 0 ? 'good' : 'warn', $name . '：佔整體金額比例' . ($dAmt > 0 ? '上升' : '下降'),
                 '本期佔整體金額 ' . $cur['share_amount'] . '%，基期 ' . $cmp['share_amount'] . '%，'
                . ($dAmt >= 0 ? '增加 ' : '減少 ') . number_format(abs($dAmt), 1) . ' 個百分點。',
                 ($dAmt >= 0 ? '+' : '') . number_format($dAmt, 1) . 'pp');
        }
        if ($cur['rank'] && $cmp['rank'] && $cur['rank'] != $cmp['rank']) {
            $add($cur['rank'] < $cmp['rank'] ? 'good' : 'warn', $name . '：客戶排名' . ($cur['rank'] < $cmp['rank'] ? '上升' : '下降'),
                 '本期排第 ' . $cur['rank'] . ' 名，基期第 ' . $cmp['rank'] . ' 名（共 ' . $rep['total_clients_cur'] . ' 家）。');
        }
        if ($cur['share_amount'] >= 15) {
            $add('warn', $name . '：佔整體金額比例偏高，集中度風險',
                 '本期佔整體訂單金額 ' . $cur['share_amount'] . '%，單一客戶佔比過高時交期與產能都容易被牽動，建議留意對該客戶的依賴程度。');
        }
        if ($cur['agg']['urgent_ratio'] > 0) {
            $d2 = $cur['agg']['urgent_ratio'] - $totalUrgentRatio;
            if ($d2 >= 10) {
                $add('warn', $name . '：急件比例高於整體平均',
                     '本客戶本期急件比例 ' . $cur['agg']['urgent_ratio'] . '%，高於整體平均 ' . $totalUrgentRatio . '%'
                    . '（高出 ' . number_format($d2, 1) . ' 個百分點），交期安排建議優先留意這家客戶。');
            }
        }
        // 連續下滑只看「已經完整過完」的月份——本月還沒過完／未來月份（含預先建立的遠期訂單）
        // 金額天生偏低，混進來比較會把「月份還沒過完」誤判成「業績下滑」。
        $trend = $c['trend'];
        $nz = array_values(array_filter($trend, function ($t) { return $t['orders'] > 0 && !empty($t['done']); }));
        if (count($nz) >= 3) {
            $l3 = array_slice($nz, -3);
            if ($l3[0]['amount'] > $l3[1]['amount'] && $l3[1]['amount'] > $l3[2]['amount']) {
                $add('warn', $name . '：近幾個月金額連續下滑',
                     $l3[0]['label'] . ' ' . number_format($l3[0]['amount']) . ' 元 → '
                    . $l3[1]['label'] . ' ' . number_format($l3[1]['amount']) . ' 元 → '
                    . $l3[2]['label'] . ' ' . number_format($l3[2]['amount']) . ' 元，連續下滑。');
            }
        }
    }
    if (!$out) $add('good', '沒有特別需要留意的變化', '所選客戶本期表現平穩，沒有異常起伏。');
    return $out;
}


