<?php
/**
 * 齒輪「外形」與「內孔」分類（唯一實作）— 主檔管理 料號(Type=G)
 *
 * 外形：看齒寬與工件總長的關係，4 選 1，沒選＝其他。
 * 內孔：孔型 7 選 1；多階層要再選階數(2~6)，孔徑固定「由大到小」輸入，
 *       兩端大/小中間小/大 只輸入「兩端孔徑」「中間孔徑」兩格（兩端孔徑相同）。
 *
 * 資料表 d_setting_shape（一料號一列）：
 *   outer_shape / bore_type / bore_steps / bore_json / bore_max / bore_min
 * bore_json = [{"d":孔徑,"l":深度或null}, ...]（依輸入順序；多階層＝大→小；兩端型＝[兩端,中間]）
 * bore_max / bore_min 由 bore_json 算出，供「最大/最小孔徑」範圍篩選。
 *
 * 規則只放這一處：前端 resource/js/eg_part_shape.js 讀 eg_shape_defs() 的輸出，不另寫一份。
 */

const EG_SHAPE_STEPS_MIN = 2;
const EG_SHAPE_STEPS_MAX = 6;

function eg_shape_defs(): array {
    return [
        'outer' => [
            'flush'      => '平板型',   // 齒寬≈工件總長，沒有凸出
            'one_side'   => '單邊凸出',
            'both_sides' => '雙邊凸出',
        ],
        // kind: single=一個孔徑 / steps=多階(大→小) / ends=兩端孔徑＋中間孔徑
        'bore' => [
            'through'     => ['name' => '單一通孔',       'kind' => 'single', 'blind' => false],
            'blind'       => ['name' => '單一盲孔',       'kind' => 'single', 'blind' => true],
            'step_through'=> ['name' => '多階層通孔',     'kind' => 'steps',  'blind' => false],
            'step_blind'  => ['name' => '多階層盲孔',     'kind' => 'steps',  'blind' => true],
            'ends_big'    => ['name' => '兩端大孔中間小孔', 'kind' => 'ends', 'blind' => false, 'end_bigger' => true],
            'ends_small'  => ['name' => '兩端小孔中間大孔', 'kind' => 'ends', 'blind' => false, 'end_bigger' => false],
        ],
        'steps_min' => EG_SHAPE_STEPS_MIN,
        'steps_max' => EG_SHAPE_STEPS_MAX,
    ];
}

function eg_shape_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $pdo->query("SHOW TABLES LIKE 'd_setting_shape'")->fetchColumn();
        if ($has) {
            // 補欄：最大凸出外徑（2026-10-21）。DDL 會隱式 commit，交易中不做；先查再 ALTER（本機 MySQL 沒有 ADD COLUMN IF NOT EXISTS）
            if (!$pdo->inTransaction() && !$pdo->query("SHOW COLUMNS FROM d_setting_shape LIKE 'hub_dia'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE d_setting_shape ADD COLUMN hub_dia DECIMAL(10,3) NULL COMMENT '最大凸出外徑（單邊/雙邊凸出才填）' AFTER outer_shape");
            }
            // 補欄：外錐／重點外徑段（2026-11-03，JSON）
            if (!$pdo->inTransaction() && !$pdo->query("SHOW COLUMNS FROM d_setting_shape LIKE 'outer_json'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE d_setting_shape ADD COLUMN outer_json TEXT NULL COMMENT '{otp:[外錐],seg:[重點外徑段]}' AFTER hub_dia");
            }
            return;
        }
        // DDL 會造成隱式 commit：交易中不可建表（建表放在頁面載入的 migration 區，這裡只是保險）
        if ($pdo->inTransaction()) return;
        $pdo->exec("CREATE TABLE d_setting_shape (
            d_id INT NOT NULL COMMENT '料號主檔 d_setting.d_id',
            outer_shape VARCHAR(12) NULL COMMENT '外形代碼，NULL＝其他',
            hub_dia DECIMAL(10,3) NULL COMMENT '最大凸出外徑（單邊/雙邊凸出才填）',
            bore_type VARCHAR(16) NULL COMMENT '內孔孔型代碼，NULL＝其他',
            bore_steps TINYINT NULL COMMENT '多階層的階數',
            bore_json TEXT NULL COMMENT '[{d:孔徑,l:深度}]，多階層由大到小',
            bore_max DECIMAL(10,3) NULL COMMENT '最大孔徑（篩選用，由 bore_json 算出）',
            bore_min DECIMAL(10,3) NULL COMMENT '最小孔徑（篩選用，由 bore_json 算出）',
            modified_by VARCHAR(50) NULL,
            modified_at DATETIME NULL,
            PRIMARY KEY (d_id),
            KEY idx_outer (outer_shape),
            KEY idx_bore (bore_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='齒輪外形/內孔分類'");
    } catch (Throwable $e) { /* 建表失敗不可讓頁面掛掉 */ }
}

/** 數字清洗：空白＝null，非正數或非數字＝false */
function eg_shape_num($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (!is_numeric($v) || (float)$v <= 0) return false;
    return round((float)$v, 3);
}

/**
 * 驗證並整理前端送來的資料；孔型一律由系統依輸入自動判定（不採信前端送的 bore_type）。
 * 輸入：outer（外形代碼）、blind（0/1 是否盲孔）、mode（single 單一孔／steps 多階／ends 兩端同孔中間不同）、
 *       bore_steps（多階的孔數）、bore:[{d:孔徑,l:深度}]
 *   single：一個孔徑 → through / blind
 *   steps ：孔數 2~6，孔徑「由大到小或由小到大」皆可輸入，系統自動排成由大到小；
 *           孔徑必須單向變化（不可相等、不可忽大忽小）→ step_through / step_blind
 *   ends  ：[兩端孔徑, 中間孔徑]，只限通孔；兩端大於中間＝ends_big、小於＝ends_small，相等視為輸入錯誤
 * @return array [ $row|null, $error|null ]  $row=null 表示「全部都沒選」（會刪除這一列）
 */
/**
 * 錐孔（選填，每一孔各自可標）：raw 有 tp=1 才處理；d 為大徑（沿用原本的 d，排序／判定／篩選都不變），
 * 另記小徑 d2 或錐度 r（1:r 的 r），至少填一個。沒標錐孔＝直孔，與舊資料完全相同。
 * @return array [ 額外欄位陣列, 錯誤訊息|null ]
 */
/**
 * 由已知的大徑 d／小徑 d2／錐長 tl／錐度 1:r／半錐角 ha 算出「有效」大小徑、錐度 1:N、半錐角，供清單篩選用。
 * 優先序：錐度 r → 半錐角 ha → 大小徑＋錐長。算不出錐度時只回大小徑。
 * 回傳鍵：cd 大徑、cs 小徑、cn 錐度 1:N 的 N、ch 半錐角(度)。這些是每次存檔重新算的派生值，前端不送也不讀。
 */
function eg_shape_taper_derive($d, $d2, $tl, $r, $ha): array {
    $C = null;
    if ($r) $C = 1 / $r;
    elseif ($ha && $ha < 90) $C = 2 * tan(deg2rad($ha));
    elseif ($d && $d2 && $tl && $d > $d2) $C = ($d - $d2) / $tl;
    if ($C !== null && $C > 0) {
        if (!$d && $d2 && $tl) $d = $d2 + $tl * $C;
        if (!$d2 && $d && $tl && $d - $tl * $C > 0) $d2 = $d - $tl * $C;
    } else { $C = null; }
    $out = [];
    if ($d)  $out['cd'] = round((float)$d, 3);
    if ($d2) $out['cs'] = round((float)$d2, 3);
    if ($C !== null) { $out['cn'] = round(1 / $C, 3); $out['ch'] = round(rad2deg(atan($C / 2)), 3); }
    return $out;
}

function eg_shape_taper_part($raw, $d, string $lab): array {
    if (!is_array($raw) || empty($raw['tp'])) return [[], null];
    $d2 = eg_shape_num($raw['d2'] ?? '');
    $r  = eg_shape_num($raw['r'] ?? '');
    if ($d2 === false) return [null, $lab . '小徑必須是大於 0 的數字'];
    if ($r === false)  return [null, $lab . '錐度必須是大於 0 的數字'];
    $ha = eg_shape_num($raw['ha'] ?? '');
    if ($ha === false || ($ha !== null && $ha >= 90)) return [null, $lab . '半錐角必須大於 0 且小於 90 度'];
    $tl = eg_shape_num($raw['tl'] ?? '');
    if ($tl === false) return [null, $lab . '錐長必須是大於 0 的數字'];
    if ($d2 === null && $r === null && $ha === null) return [null, $lab . '是錐孔，請填小徑、錐度或半錐角（至少一個）'];
    if ($d2 !== null && $d !== null && $d2 == $d) return [null, $lab . '大徑與小徑相同，不是錐孔'];
    if ($d2 !== null && $d !== null && $d2 > $d) return [null, $lab . '小徑必須小於大徑（請把較大的填在大徑）'];
    $x = ['tp' => 1];
    if ($d2 !== null) $x['d2'] = $d2;
    if ($r !== null)  $x['r']  = $r;
    if ($tl !== null) $x['tl'] = $tl;
    if ($ha !== null) $x['ha'] = $ha;
    $x += eg_shape_taper_derive($d, $d2, $tl, $r, $ha);
    return [$x, null];
}

/**
 * 外錐（可多筆）與重點外徑段（可多筆，軸件只記想記的幾段）。兩者皆選填；沒送這兩個欄位＝舊呼叫端，不動原值。
 * @return array [ 已送欄位?, json字串|null, 錯誤|null ]
 */
function eg_shape_outer_extra($in): array {
    if (!array_key_exists('otp', $in) && !array_key_exists('seg', $in)) return [false, null, null];
    $otp = []; $seg = [];
    $rawO = is_array($in['otp'] ?? null) ? array_values($in['otp']) : [];
    if (count($rawO) > 6) return [true, null, '外錐最多 6 筆'];
    foreach ($rawO as $i => $o) {
        if (!is_array($o)) continue;
        $any = false; foreach (['d', 'd2', 'tl', 'r', 'ha'] as $k) if (trim((string)($o[$k] ?? '')) !== '') $any = true;
        if (!$any) continue;
        $lab = '第 ' . ($i + 1) . ' 個外錐';
        $d = eg_shape_num($o['d'] ?? '');
        if ($d === null) {   // 大徑沒填：由 小徑＋錐長＋錐度（或半錐角）算出
            $d2x = eg_shape_num($o['d2'] ?? ''); $tlx = eg_shape_num($o['tl'] ?? '');
            $rx = eg_shape_num($o['r'] ?? ''); $hax = eg_shape_num($o['ha'] ?? '');
            $Cx = $rx ? 1 / $rx : (($hax && $hax < 90) ? 2 * tan(deg2rad($hax)) : null);
            if ($d2x && $tlx && $Cx) $d = round($d2x + $tlx * $Cx, 3);
        }
        if ($d === false || $d === null) return [true, null, $lab . '請填大徑，或填「小徑＋錐長＋錐度（或半錐角）」讓系統算出大徑'];
        [$tx, $te] = eg_shape_taper_part(['tp' => 1] + $o, $d, $lab);
        if ($te) return [true, null, $te];
        $otp[] = ['d' => $d] + $tx;
    }
    $rawS = is_array($in['seg'] ?? null) ? array_values($in['seg']) : [];
    if (count($rawS) > 12) return [true, null, '重點外徑段最多 12 筆'];
    foreach ($rawS as $i => $s) {
        if (!is_array($s)) continue;
        $nm = mb_substr(trim((string)($s['n'] ?? '')), 0, 30);
        if (trim((string)($s['d'] ?? '')) === '' && trim((string)($s['l'] ?? '')) === '' && $nm === '') continue;
        $lab = '第 ' . ($i + 1) . ' 段外徑';
        $d = eg_shape_num($s['d'] ?? ''); $l = eg_shape_num($s['l'] ?? '');
        if ($d === false || $d === null) return [true, null, $lab . '請填外徑（大於 0 的數字）'];
        if ($l === false) return [true, null, $lab . '長度必須是大於 0 的數字'];
        $x = ['d' => $d]; if ($l !== null) $x['l'] = $l; if ($nm !== '') $x['n'] = $nm;
        $seg[] = $x;
    }
    $j = ($otp || $seg) ? json_encode(['otp' => $otp, 'seg' => $seg], JSON_UNESCAPED_UNICODE) : null;
    return [true, $j, null];
}

function eg_shape_normalize($in): array {
    if (!is_array($in)) return [null, null];
    $defs  = eg_shape_defs();
    $outer = trim((string)($in['outer'] ?? ''));
    if ($outer !== '' && !isset($defs['outer'][$outer])) return [null, '外形代碼不正確'];
    // 最大凸出外徑：只有單邊／雙邊凸出才有意義，其他外形一律清除
    $hub = null;
    if ($outer === 'one_side' || $outer === 'both_sides') {
        $hub = eg_shape_num($in['hub_dia'] ?? '');
        if ($hub === false) return [null, '最大凸出外徑必須是大於 0 的數字'];
    }
    $mode  = trim((string)($in['mode'] ?? ''));
    if ($mode !== '' && !in_array($mode, ['single', 'steps', 'ends'], true)) return [null, '內孔型態不正確'];
    $blind = !empty($in['blind']);

    $btype = null; $steps = null; $list = []; $max = null; $min = null;
    if ($mode !== '') {
        $raw = is_array($in['bore'] ?? null) ? array_values($in['bore']) : [];
        if ($mode === 'single') {
            $btype = $blind ? 'blind' : 'through';
            $d = eg_shape_num($raw[0]['d'] ?? '');
            if ($d === false) return [null, '孔徑必須是大於 0 的數字'];
            [$tx, $te] = eg_shape_taper_part($raw[0] ?? null, $d, '');
            if ($te) return [null, $te];
            if ($d !== null) $list[] = ['d' => $d, 'l' => null] + $tx;   // 深度先不記錄
        } elseif ($mode === 'steps') {
            $btype = $blind ? 'step_blind' : 'step_through';
            $steps = (int)($in['bore_steps'] ?? 0);
            if ($steps < EG_SHAPE_STEPS_MIN || $steps > EG_SHAPE_STEPS_MAX)
                return [null, '請先輸入孔數（' . EG_SHAPE_STEPS_MIN . '～' . EG_SHAPE_STEPS_MAX . '）'];
            $any = false; $tmp = [];
            for ($i = 0; $i < $steps; $i++) {
                $d = eg_shape_num($raw[$i]['d'] ?? '');
                if ($d === false) return [null, '第 ' . ($i + 1) . ' 個孔徑必須是大於 0 的數字'];
                if ($d !== null) $any = true;
                [$tx, $te] = eg_shape_taper_part($raw[$i] ?? null, $d, '第 ' . ($i + 1) . ' 孔');
                if ($te) return [null, $te];
                $tmp[] = ['d' => $d, 'l' => null] + $tx;
            }
            if ($any) {
                foreach ($tmp as $i => $r) if ($r['d'] === null) return [null, '第 ' . ($i + 1) . ' 個孔徑還沒填'];
                $up = true; $down = true;
                for ($i = 1; $i < $steps; $i++) {
                    if ($tmp[$i]['d'] == $tmp[$i - 1]['d'])
                        return [null, '第 ' . $i . '、' . ($i + 1) . ' 個孔徑相同（' . $tmp[$i]['d'] . '），不是不同的階；相同請合併成一階'];
                    if ($tmp[$i]['d'] < $tmp[$i - 1]['d']) $up = false; else $down = false;
                }
                if (!$up && !$down)
                    return [null, '孔徑順序忽大忽小，無法判定為多階；若是「兩端孔徑相同、中間不同」請改選該型態'];
                usort($tmp, function ($x, $y) { return $y['d'] <=> $x['d']; });   // 自動排成由大到小（深度跟著各自的孔徑）
                $list = $tmp;
            }
        } else { // ends
            if ($blind) return [null, '「兩端同孔、中間不同」只適用通孔'];
            $e = eg_shape_num($raw[0]['d'] ?? '');
            $m = eg_shape_num($raw[1]['d'] ?? '');
            if ($e === false || $m === false) return [null, '孔徑必須是大於 0 的數字'];
            if ($e !== null || $m !== null) {
                if ($e === null) return [null, '請輸入兩端孔徑'];
                if ($m === null) return [null, '請輸入中間孔徑'];
                if ($e == $m) return [null, '兩端與中間孔徑相同，這是單一通孔，請改選「單一孔」'];
                [$tx0, $te0] = eg_shape_taper_part($raw[0] ?? null, $e, '兩端');
                if ($te0) return [null, $te0];
                [$tx1, $te1] = eg_shape_taper_part($raw[1] ?? null, $m, '中間');
                if ($te1) return [null, $te1];
                $list = [['d' => $e, 'l' => null] + $tx0, ['d' => $m, 'l' => null] + $tx1];
                $btype = $e > $m ? 'ends_big' : 'ends_small';
            }   // 還沒輸入數據＝無法判定大小，孔型維持空白（不會被當成特定孔型）
        }
        foreach ($list as $r) {
            $max = $max === null ? $r['d'] : max($max, $r['d']);
            $min = $min === null ? $r['d'] : min($min, $r['d']);
        }
    }
    [$exSent, $exJson, $exErr] = eg_shape_outer_extra($in);
    if ($exErr) return [null, $exErr];
    if ($outer === '' && $btype === null && $exJson === null) return [null, null];
    $extra = $exSent ? ['outer_json' => $exJson] : [];
    return [$extra + [
        'outer_shape' => $outer !== '' ? $outer : null,
        'hub_dia'     => $hub,
        'bore_type'   => $btype,
        'bore_steps'  => $steps,
        'bore_json'   => $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null,
        'bore_max'    => $max,
        'bore_min'    => $min,
    ], null];
}

function eg_shape_save(PDO $pdo, int $dId, ?array $row, string $by = ''): void {
    if ($row === null) {
        $pdo->prepare("DELETE FROM d_setting_shape WHERE d_id=?")->execute([$dId]);
        return;
    }
    // outer_json 只有前端有送才寫（舊分頁沒送＝不動，免得把外錐／外徑段洗掉）
    $hasEx = array_key_exists('outer_json', $row);
    $pdo->prepare("INSERT INTO d_setting_shape (d_id,outer_shape,hub_dia," . ($hasEx ? "outer_json," : "") . "bore_type,bore_steps,bore_json,bore_max,bore_min,modified_by,modified_at)
                   VALUES (?,?,?," . ($hasEx ? "?," : "") . "?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE outer_shape=VALUES(outer_shape),hub_dia=VALUES(hub_dia)," . ($hasEx ? "outer_json=VALUES(outer_json)," : "") . "bore_type=VALUES(bore_type),bore_steps=VALUES(bore_steps),
                   bore_json=VALUES(bore_json),bore_max=VALUES(bore_max),bore_min=VALUES(bore_min),modified_by=VALUES(modified_by),modified_at=NOW()")
        ->execute(array_merge([$dId, $row['outer_shape'], $row['hub_dia']], $hasEx ? [$row['outer_json']] : [], [$row['bore_type'], $row['bore_steps'], $row['bore_json'], $row['bore_max'], $row['bore_min'], $by !== '' ? $by : null]));
}

/** 讀單一料號（給編輯畫面）。沒有資料回 null。 */
function eg_shape_get(PDO $pdo, int $dId): ?array {
    try {
        $st = $pdo->prepare("SELECT outer_shape,hub_dia,outer_json,bore_type,bore_steps,bore_json FROM d_setting_shape WHERE d_id=?");
        $st->execute([$dId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['bore'] = $r['bore_json'] ? (json_decode($r['bore_json'], true) ?: []) : [];
        $ex = $r['outer_json'] ? (json_decode($r['outer_json'], true) ?: []) : [];
        $r['otp'] = $ex['otp'] ?? [];
        $r['seg'] = $ex['seg'] ?? [];
        unset($r['bore_json'], $r['outer_json']);
        return $r;
    } catch (Throwable $e) { return null; }
}

/** 給清單：一次查多個料號 → [d_id => {outer,bore_type,text}]，text 為「單凸型｜多階層通孔 Ø30→Ø20」這種簡短摘要 */
function eg_shape_summary_map(PDO $pdo, array $dIds): array {
    $dIds = array_values(array_unique(array_filter(array_map('intval', $dIds))));
    if (!$dIds) return [];
    $out = [];
    try {
        $ph = implode(',', array_fill(0, count($dIds), '?'));
        $st = $pdo->prepare("SELECT d_id,outer_shape,hub_dia,outer_json,bore_type,bore_steps,bore_json FROM d_setting_shape WHERE d_id IN ($ph)");
        $st->execute($dIds);
        $defs = eg_shape_defs();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bore = $r['bore_json'] ? (json_decode($r['bore_json'], true) ?: []) : [];
            $ds = array_map(function ($b) { return rtrim(rtrim(number_format((float)$b['d'], 3, '.', ''), '0'), '.') . (!empty($b['tp']) ? '(錐)' : ''); }, $bore);
            $nf = function ($v) { return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.'); };
            $ex = $r['outer_json'] ? (json_decode($r['outer_json'], true) ?: []) : [];
            $tail = [];
            foreach (($ex['otp'] ?? []) as $o) {
                $tail[] = '外錐Ø' . $nf($o['d']) . (isset($o['d2']) ? '→Ø' . $nf($o['d2']) : '')
                        . (isset($o['ch']) ? ' 半錐' . $nf($o['ch']) . '°' : (isset($o['ha']) ? ' 半錐' . $nf($o['ha']) . '°' : (isset($o['r']) ? ' 1:' . $nf($o['r']) : '')));
            }
            foreach (($ex['seg'] ?? []) as $g) {
                $tail[] = '段Ø' . $nf($g['d']) . (isset($g['l']) ? '×' . $nf($g['l']) : '') . (!empty($g['n']) ? '(' . $g['n'] . ')' : '');
            }
            $out[(int)$r['d_id']] = [
                'outer'     => $r['outer_shape'],
                'outer_nm'  => $r['outer_shape'] ? ($defs['outer'][$r['outer_shape']] ?? '') : '',
                'hub'       => $r['hub_dia'] !== null ? rtrim(rtrim(number_format((float)$r['hub_dia'], 3, '.', ''), '0'), '.') : '',
                'bore_type' => $r['bore_type'],
                'bore_nm'   => $r['bore_type'] ? ($defs['bore'][$r['bore_type']]['name'] ?? '') : '',
                'dias'      => $ds,
                'tail'      => implode('、', $tail),
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 清單篩選條件（接在 d_setting 別名 d 之後）。回傳 [SQL片段, 參數]。
 * outer / bore_type 可複選（逗號分隔），『other』＝沒有選（含沒有資料列的）。
 */
function eg_shape_filter_sql(array $post, string $alias = 'd'): array {
    $defs = eg_shape_defs();
    $sql = ''; $prm = [];
    $pick = function (string $key, array $valid): array {
        $raw = trim((string)($_POST[$key] ?? ''));
        if ($raw === '') return [];
        $a = array_filter(array_map('trim', explode(',', $raw)), function ($v) use ($valid) { return $v === 'other' || $v === 'none' || isset($valid[$v]); });
        return array_values(array_unique($a));
    };
    // 旗標條件（有外錐／有外徑段／含錐孔）：與同列的其他選項是「且」，不是「或」
    $flagSql = ['has_otp' => ['outer_json', '%"otp":[{%'], 'has_seg' => ['outer_json', '%"seg":[{%'], 'has_taper' => ['bore_json', '%"tp":1%']];
    $fi = 0;
    foreach ([['filter_shape_outer', 'outer_shape', $defs['outer'], 'fso'], ['filter_shape_bore', 'bore_type', $defs['bore'], 'fsb']] as [$key, $col, $valid, $pre]) {
        $raw0 = trim((string)($_POST[$key] ?? ''));
        foreach (array_filter(array_map('trim', explode(',', $raw0))) as $fl) {
            if (!isset($flagSql[$fl])) continue;
            $ph = ':fsflag' . ($fi++);
            $sql .= " AND EXISTS (SELECT 1 FROM d_setting_shape s_fl$fi WHERE s_fl$fi.d_id={$alias}.d_id AND s_fl$fi.{$flagSql[$fl][0]} LIKE $ph)";
            $prm[$ph] = $flagSql[$fl][1];
        }
        $vals = $pick($key, $valid);
        if (!$vals) continue;
        $conds = []; $i = 0;
        $in = array_values(array_filter($vals, function ($v) { return $v !== 'other' && $v !== 'none'; }));
        if ($in) {
            $phs = [];
            foreach ($in as $v) { $p = ":{$pre}{$i}"; $phs[] = $p; $prm[$p] = $v; $i++; }
            $conds[] = "EXISTS (SELECT 1 FROM d_setting_shape s_$pre WHERE s_$pre.d_id={$alias}.d_id AND s_$pre.$col IN (" . implode(',', $phs) . "))";
        }
        // 'other'（外形其他）／'none'（內孔無孔）＝這個欄位沒有選任何值（含根本沒有資料列的料號）
        if (in_array('other', $vals, true) || in_array('none', $vals, true)) {
            $conds[] = "NOT EXISTS (SELECT 1 FROM d_setting_shape s_{$pre}o WHERE s_{$pre}o.d_id={$alias}.d_id AND s_{$pre}o.$col IS NOT NULL)";
        }
        $sql .= ' AND (' . implode(' OR ', $conds) . ')';
    }
    // 錐度數值範圍：{"b":{"n":[最小,最大],"h":[..],"d":[..],"s":[..]},"o":{...}}（b＝錐孔、o＝外錐；n 錐度 1:N、h 半錐角、d 大徑、s 小徑）
    // 同一料號任一個錐孔（外錐）同時符合所填的全部範圍即命中
    $tpj = json_decode((string)($_POST['filter_shape_tp'] ?? ''), true);
    if (is_array($tpj)) {
        $col = ['n' => 'jt.cn', 'h' => 'jt.ch', 'd' => 'COALESCE(jt.cd, jt.d)', 's' => 'jt.cs'];
        $ti = 0;
        foreach (['b', 'o'] as $g) {
            if (empty($tpj[$g]) || !is_array($tpj[$g])) continue;
            $cc = [];
            foreach ($col as $k => $expr) {
                $rg = $tpj[$g][$k] ?? null;
                if (!is_array($rg)) continue;
                foreach ([0 => '>=', 1 => '<='] as $ix => $op) {
                    if (isset($rg[$ix]) && is_numeric($rg[$ix])) { $p = ':fstp' . ($ti++); $cc[] = "$expr $op $p"; $prm[$p] = $rg[$ix]; }
                }
            }
            if (!$cc) continue;
            $src = $g === 'b' ? 'bore_json' : 'outer_json';
            $path = $g === 'b' ? '$[*]' : '$.otp[*]';
            $tpc = $g === 'b' ? ' AND jt.tp = 1' : '';
            $sql .= " AND EXISTS (SELECT 1 FROM d_setting_shape s_tp$g, JSON_TABLE(s_tp$g.$src, '$path' COLUMNS("
                  . "tp INT PATH '\$.tp' DEFAULT '0' ON EMPTY, d DECIMAL(10,3) PATH '\$.d' NULL ON EMPTY NULL ON ERROR, "
                  . "cd DECIMAL(10,3) PATH '\$.cd' NULL ON EMPTY NULL ON ERROR, cs DECIMAL(10,3) PATH '\$.cs' NULL ON EMPTY NULL ON ERROR, "
                  . "cn DECIMAL(10,3) PATH '\$.cn' NULL ON EMPTY NULL ON ERROR, ch DECIMAL(10,3) PATH '\$.ch' NULL ON EMPTY NULL ON ERROR)) jt "
                  . "WHERE s_tp$g.d_id={$alias}.d_id$tpc AND " . implode(' AND ', $cc) . ")";
        }
    }
    $hmn = trim((string)($_POST['filter_shape_hub_min'] ?? ''));
    $hmx = trim((string)($_POST['filter_shape_hub_max'] ?? ''));
    if (is_numeric($hmn) || is_numeric($hmx)) {
        $c = "s_hb.d_id={$alias}.d_id AND s_hb.hub_dia IS NOT NULL";
        if (is_numeric($hmn)) { $c .= " AND s_hb.hub_dia >= :fshmin"; $prm[':fshmin'] = $hmn; }
        if (is_numeric($hmx)) { $c .= " AND s_hb.hub_dia <= :fshmax"; $prm[':fshmax'] = $hmx; }
        $sql .= " AND EXISTS (SELECT 1 FROM d_setting_shape s_hb WHERE $c)";
    }
    $mn = trim((string)($_POST['filter_shape_bore_min'] ?? ''));
    $mx = trim((string)($_POST['filter_shape_bore_max'] ?? ''));
    // 孔徑範圍：只要這支料號「有任何一階孔徑落在區間內」就算命中，所以用 bore_max/bore_min 的交集判斷
    if (is_numeric($mn) || is_numeric($mx)) {
        $c = "s_bd.d_id={$alias}.d_id AND s_bd.bore_max IS NOT NULL";
        if (is_numeric($mn)) { $c .= " AND s_bd.bore_max >= :fsbmin"; $prm[':fsbmin'] = $mn; }
        if (is_numeric($mx)) { $c .= " AND s_bd.bore_min <= :fsbmax"; $prm[':fsbmax'] = $mx; }
        $sql .= " AND EXISTS (SELECT 1 FROM d_setting_shape s_bd WHERE $c)";
    }
    return [$sql, $prm];
}
