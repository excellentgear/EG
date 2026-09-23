<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 *  量具（檢驗設備一覽表 qc_tool）在「其他頁面」的顯示方式 —— 全站唯一實作
 * ══════════════════════════════════════════════════════════════════════════════
 *  使用者 2026-09-23 交辦：
 *    「tool_calibration.php 內要可以依照類別設定顯示在其他選單上是哪些欄位，
 *      或是哪些加哪些欄位一起顯示，像是跨珠分厘卡適合 量具編號＋規格，
 *      類別全部當訂在檢測機的就可以直接顯示機台名稱。
 *      要依照量測儀器頁面內綁定在同一頁的設定，同一種其他頁面的顯示方式，
 *      這規定應該要寫入 MD，避免每個頁面又要重新說一次。」
 *
 *  ── 為什麼一定要收斂成一支共用庫（不要各頁自己拼字串）────────────────────────
 *  ① 量具編號本身看不出是什麼：「QC-003」現場叫它「KAPP齒輪量測機」、
 *     「K-555-P」現場講的是「100-125mm」。各頁自己決定要印哪幾欄，
 *     同一支量具在 SIP 上叫 A、在檢驗表上叫 B，現場會以為是兩支不同的量具。
 *  ② 設定入口只有一個＝量測儀器校驗頁的「類別設定」，改一次全站跟著改。
 *
 *  ── state 的語意（本檔存在的另一個理由，已經害過一次）────────────────────────
 *  **qc_tool.state：1＝停用，0／NULL＝在用**（見 tool_calib_lib 建欄註解
 *  「0/NULL=正常，比照 machine_list.state 語意」）。
 *  sopsip_lib 原本寫成 `(state IS NULL OR state<>0)` ＝ 把「在用」整批當成停用，
 *  於是 QC-001／QC-002（在用）在 SIP 的挑檢具清單裡一支都看不到，
 *  反而是已經停用的 QC-003 一直列出來，**而且完全不報錯**。
 *  往後判斷在不在用一律呼叫 qc_tool_active_cond()／qc_tool_is_active()，不要自己寫條件。
 * ══════════════════════════════════════════════════════════════════════════════
 */

/** 在用的 SQL 條件（$a＝qc_tool 的別名）。1＝停用，其餘都是在用 */
function qc_tool_active_cond(string $a = 't'): string
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $a);
    return "COALESCE($a.state,0)<>1";
}

/** 一列 qc_tool 是不是還在用 */
function qc_tool_is_active(array $row): bool
{
    return (int)($row['state'] ?? 0) !== 1;
}

/**
 * 這支量具在「業務日期那一天」還算不算在用。
 * 補歷史資料時一定要用這一支：使用者 2026-09-23 明講「停用的機台一樣要可以補資料，
 * 表單日期是 2022 年，那時候根本還沒停用」。停用日期沒填時退回「只要停用就不可用」。
 */
function qc_tool_active_asof(array $row, string $date = ''): bool
{
    if (qc_tool_is_active($row)) return true;
    $d = trim((string)($row['disabled_date'] ?? ''));
    $date = trim($date);
    if ($d === '' || $date === '') return false;
    return $date < $d;
}

/* ───────────────────────── 顯示欄位設定 ───────────────────────── */

/**
 * 可以挑來顯示的欄位（唯一登記處；新增欄位只改這裡，所有頁面自動有）。
 * key ＝ qc_tool 的欄位或衍生值，label ＝ 設定畫面上的文字。
 */
function qc_tool_disp_fields(): array
{
    return [
        'tool_no'       => '量具編號',
        'category'      => '類別',
        'machine'       => '機台名稱',
        'spec_desc'     => '規格',
        'machine_model' => '機型',
        'manufacturer'  => '製造商',
        'position'      => '位置（廠別）',
        'note'          => '備註',
    ];
}

const QC_TOOL_DISP_KEY = 'qc_tool_display_cfg';

/**
 * 每個類別要顯示哪幾個欄位。
 *   回傳 [類別id => ['fields'=>[欄位代碼…], 'sep'=>'分隔符號']]
 * 從來沒設定過的類別一律退回預設值（編號＋規格），
 * **設定過的就算存成空陣列也要被尊重**（那是管理員刻意只印編號）。
 */
function qc_tool_disp_cfg(PDO $db): array
{
    $cached = qc_tool_disp_cache();
    if ($cached !== null) return $cached;
    $raw = [];
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
        $st->execute([QC_TOOL_DISP_KEY]);
        $j = (string)($st->fetchColumn() ?: '');
        if ($j !== '') { $d = json_decode($j, true); if (is_array($d)) $raw = $d; }
    } catch (Throwable $e) { /* 表不存在 → 全用預設 */ }

    $valid = qc_tool_disp_fields();
    $out = [];
    foreach ($raw as $cid => $c) {
        $f = [];
        foreach ((array)($c['fields'] ?? []) as $k) if (isset($valid[$k])) $f[] = $k;
        $out[(int)$cid] = ['fields' => array_values(array_unique($f)),
                           'sep'    => (string)($c['sep'] ?? ' ')];
    }
    qc_tool_disp_cache($out);
    return $out;
}

/**
 * 請求內快取的唯一存放處。
 * **一定要能清掉**：同一個 request 內「存完設定再重算一次顯示文字」是很常見的路徑，
 * 吃到舊快取的症狀是「設定存進去了、當下畫面卻完全沒變」（本專案在 dqa_excl_map() 踩過一次）。
 */
function qc_tool_disp_cache(?array $set = null, bool $clear = false): ?array
{
    static $cache = null;
    if ($clear) { $cache = null; return null; }
    if ($set !== null) { $cache = $set; return $cache; }
    return $cache;
}

/** 清掉請求內快取（qc_tool_disp_cfg_set() 之後一定要呼叫） */
function qc_tool_disp_cfg_clear(): void { qc_tool_disp_cache(null, true); }

/** 覆寫設定（唯一寫入點）。$map = [類別id => ['fields'=>[...], 'sep'=>'…']] */
function qc_tool_disp_cfg_set(PDO $db, array $map): void
{
    $valid = qc_tool_disp_fields();
    $clean = [];
    foreach ($map as $cid => $c) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $f = [];
        foreach ((array)($c['fields'] ?? []) as $k) {
            $k = (string)$k;
            if (isset($valid[$k]) && !in_array($k, $f, true)) $f[] = $k;
        }
        $sep = (string)($c['sep'] ?? ' ');
        if (mb_strlen($sep) > 4) $sep = mb_substr($sep, 0, 4);
        $clean[$cid] = ['fields' => $f, 'sep' => $sep];
    }
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
       ->execute([QC_TOOL_DISP_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE)]);
    qc_tool_disp_cfg_clear();
}

/**
 * 沒設定過的類別用這一組：編號＋機台名稱＋規格。
 * 三個一起帶是刻意的——QC-001 這種編號完全看不出是什麼（現場叫它「TTi 齒輪量測機」），
 * 而分厘卡那種編號本身就含量程。**重複的會被下面的「包含即合併」吃掉**，
 * 所以 K-555-P／K-555-P (100-125mm)／100-125mm 只會印出最長的那一個，不會變成一長串重複的字。
 */
function qc_tool_disp_default(): array
{
    return ['fields' => ['tool_no', 'machine', 'spec_desc'], 'sep' => ' '];
}

/**
 * 一支量具在別的頁面上要顯示成什麼字（唯一實作）。
 * $row 至少要有 Tool_No／tool_no、QC_Tool_List_id／tool_type_id，其餘欄位有就用。
 */
function qc_tool_disp_label(PDO $db, array $row): string
{
    $cid = (int)($row['QC_Tool_List_id'] ?? $row['tool_type_id'] ?? 0);
    $cfg = qc_tool_disp_cfg($db);
    $c   = $cfg[$cid] ?? qc_tool_disp_default();

    $get = function (string $k) use ($row) {
        switch ($k) {
            case 'tool_no':  return trim((string)($row['Tool_No'] ?? $row['tool_no'] ?? ''));
            case 'category': return trim((string)($row['QC_Tool'] ?? $row['category_name'] ?? $row['tool_type'] ?? ''));
            default:         return trim((string)($row[$k] ?? ''));
        }
    };
    /* 「包含即合併」：新的一段如果已經被前面某一段包住就不加，反過來包住前面那一段就取代它。
       現場資料常常一模一樣或互相包含（機台名稱直接寫成「K-555-P (100-125mm)」、
       規格又寫一次「100-125mm」），逐欄照印會變成「K-555-P K-555-P (100-125mm) 100-125mm」。 */
    $parts = [];
    foreach ($c['fields'] as $k) {
        $v = $get($k);
        if ($v === '') continue;
        $skip = false;
        foreach ($parts as $i => $old) {
            if (mb_strpos($old, $v) !== false) { $skip = true; break; }       // 已經被包住
            if (mb_strpos($v, $old) !== false) { $parts[$i] = $v; $skip = true; break; } // 反過來包住舊的
        }
        if (!$skip) $parts[] = $v;
    }
    if (!$parts) { $v = $get('tool_no'); if ($v !== '') $parts[] = $v; }   // 至少印得出編號
    $sep = $c['sep'] !== '' ? $c['sep'] : ' ';
    return trim(implode($sep, $parts));
}

/**
 * 「挑量具」的清單（唯一實作，任何頁面要讓人挑量具一律呼叫這一支）。
 *
 * @param array $opt  type_id  只要這個類別
 *                    kw       關鍵字（編號／類別／機台名稱／規格／製造商都比得到）
 *                    asof     業務日期；有給時「那一天還沒停用」的算 usable
 *                    with_off 1＝連停用的也列出來（標 disabled），預設 0
 * @return array [['tool_id','tool_no','label','type_id','type_name','spec_desc','machine',
 *                 'disabled','disabled_date','usable']]
 */
function qc_tool_pick_rows(PDO $db, array $opt = []): array
{
    $typeId  = (int)($opt['type_id'] ?? 0);
    $kw      = trim((string)($opt['kw'] ?? ''));
    $asof    = trim((string)($opt['asof'] ?? ''));
    $withOff = !empty($opt['with_off']) || $asof !== '';
    $limit   = (int)($opt['limit'] ?? 500);
    if ($limit <= 0 || $limit > 2000) $limit = 500;

    $w = []; $p = [];
    if ($typeId > 0) { $w[] = "t.QC_Tool_List_id=?"; $p[] = $typeId; }
    if (!$withOff)   { $w[] = qc_tool_active_cond('t'); }
    if ($kw !== '') {
        $w[] = "(t.Tool_No LIKE ? OR l.QC_Tool LIKE ? OR t.machine LIKE ? OR t.spec_desc LIKE ? OR t.manufacturer LIKE ?)";
        for ($i = 0; $i < 5; $i++) $p[] = '%' . $kw . '%';
    }
    try {
        $st = $db->prepare("SELECT t.Tool_id, t.Tool_No, t.QC_Tool_List_id, t.machine, t.machine_model,
                                   t.spec_desc, t.manufacturer, t.position, t.note, t.state, t.disabled_date,
                                   l.QC_Tool
                            FROM qc_tool t
                            LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id = t.QC_Tool_List_id
                            " . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . "
                            ORDER BY (COALESCE(t.state,0)=1), l.sort_order, t.Tool_No
                            LIMIT $limit");
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($rows as $r) {
        $off = !qc_tool_is_active($r);
        $out[] = [
            'tool_id'       => (int)$r['Tool_id'],
            'tool_no'       => (string)$r['Tool_No'],
            'label'         => qc_tool_disp_label($db, $r),
            'type_id'       => (int)$r['QC_Tool_List_id'],
            'type_name'     => (string)($r['QC_Tool'] ?? ''),
            'spec_desc'     => (string)($r['spec_desc'] ?? ''),
            'machine'       => (string)($r['machine'] ?? ''),
            'disabled'      => $off ? 1 : 0,
            'disabled_date' => (string)($r['disabled_date'] ?? ''),
            // usable＝這份單據挑得挑不得：在用的一律可挑；停用的要「業務日期早於停用日」才可挑
            'usable'        => qc_tool_active_asof($r, $asof) ? 1 : 0,
        ];
    }
    return $out;
}

/** 單支量具（含顯示文字）。找不到回 null */
function qc_tool_pick_one(PDO $db, int $toolId): ?array
{
    if ($toolId <= 0) return null;
    try {
        $st = $db->prepare("SELECT t.Tool_id, t.Tool_No, t.QC_Tool_List_id, t.machine, t.machine_model,
                                   t.spec_desc, t.manufacturer, t.position, t.note, t.state, t.disabled_date,
                                   l.QC_Tool
                            FROM qc_tool t
                            LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id = t.QC_Tool_List_id
                            WHERE t.Tool_id=?");
        $st->execute([$toolId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'tool_id'       => (int)$r['Tool_id'],
            'tool_no'       => (string)$r['Tool_No'],
            'label'         => qc_tool_disp_label($db, $r),
            'type_id'       => (int)$r['QC_Tool_List_id'],
            'type_name'     => (string)($r['QC_Tool'] ?? ''),
            'spec_desc'     => (string)($r['spec_desc'] ?? ''),
            'machine'       => (string)($r['machine'] ?? ''),
            'disabled'      => qc_tool_is_active($r) ? 0 : 1,
            'disabled_date' => (string)($r['disabled_date'] ?? ''),
        ];
    } catch (Throwable $e) { return null; }
}
