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
    $sql = "SELECT ot.Order_id, ot.Order_oo, ot.C_order, ot.`$dateCol` AS dt, ot.Order_date, ot.Delivery_date,
                   ot.Client_name, ot.Client_name_ID, ot.d_id, ot.d_id_ID, ot.Qty, ot.unit_price,
                   ot.Processing_items, ot.Order_status, ds.Customer_Id AS part_cust
              FROM order_track ot
              LEFT JOIN d_setting ds ON ds.d_id = ot.d_id_ID
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

    /* ── 全製／單製：本期彙總 ＋ 每條規則命中幾筆（設定畫面要拿來校正用） ── */
    $ruleHits = [];
    foreach ($rules as $i => $rr) $ruleHits[$i] = ['label' => $rr['label'], 'kw' => $rr['kw'], 'cls' => $rr['cls'], 'orders' => 0];
    $procSamples = ['full' => [], 'single' => [], 'unknown' => [], 'none' => []];
    foreach ($rows as $r) {
        if (!$inSel($r) || !$inRange($r, $curE)) continue;
        if ($r['rule'] !== '') {
            foreach ($ruleHits as $i => $h) if ($h['label'] === $r['rule']) { $ruleHits[$i]['orders']++; break; }
        }
        $c = $r['cls'];
        if (isset($procSamples[$c]) && count($procSamples[$c]) < 12 && $r['proc'] !== ''
            && !in_array($r['proc'], $procSamples[$c], true)) $procSamples[$c][] = $r['proc'];
    }

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
        $c['flag']     = ($c['cmp']['orders'] == 0 && $c['cur']['orders'] > 0) ? 'new'
                       : (($c['cur']['orders'] == 0 && $c['cmp']['orders'] > 0) ? 'lost' : '');
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
            if (!isset($byPart[$k])) $byPart[$k] = ['key' => $k, 'pno' => $r['pno'], 'cname' => $r['cname'],
                                                    'first' => $r['first'], 'fsrc' => $r['fsrc'],
                                                    'cur' => oa_blank(), 'cmp' => oa_blank()];
            oa_add($byPart[$k][$slot], $r, false);
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
        $newList[] = ['key' => $p['key'], 'pno' => $p['pno'], 'cname' => $p['cname'],
                      'first' => $p['first'], 'fsrc' => $p['fsrc'],
                      'orders' => $p['cur']['orders'], 'qty' => $p['cur']['qty'],
                      'amount' => $p['cur']['amount'], 'px' => $p['cur']['px_orders']];
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
        'proc'        => ['rule_hits' => array_values($ruleHits), 'samples' => $procSamples],
        'clients'     => $clientRows,
        'client_cmp'  => ['keys' => $cmpKeys, 'series' => $cmpSeries],
        'rank_clients' => $rankClients,
        'rank_parts'   => array_slice($rankParts, 0, $topN),
        'rank_parts_delta' => array_slice($rankPartsDelta, 0, $topN),
        'rank_parts_drop'  => array_slice(array_reverse($rankPartsDelta), 0, $topN),
        'new_list'     => $newList,
    ];
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


