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
            if ($d !== null) $list[] = ['d' => $d, 'l' => null];   // 深度先不記錄
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
                $tmp[] = ['d' => $d, 'l' => null];
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
                $list = [['d' => $e, 'l' => null], ['d' => $m, 'l' => null]];
                $btype = $e > $m ? 'ends_big' : 'ends_small';
            }   // 還沒輸入數據＝無法判定大小，孔型維持空白（不會被當成特定孔型）
        }
        foreach ($list as $r) {
            $max = $max === null ? $r['d'] : max($max, $r['d']);
            $min = $min === null ? $r['d'] : min($min, $r['d']);
        }
    }
    if ($outer === '' && $btype === null) return [null, null];
    return [[
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
    $pdo->prepare("INSERT INTO d_setting_shape (d_id,outer_shape,hub_dia,bore_type,bore_steps,bore_json,bore_max,bore_min,modified_by,modified_at)
                   VALUES (?,?,?,?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE outer_shape=VALUES(outer_shape),hub_dia=VALUES(hub_dia),bore_type=VALUES(bore_type),bore_steps=VALUES(bore_steps),
                   bore_json=VALUES(bore_json),bore_max=VALUES(bore_max),bore_min=VALUES(bore_min),modified_by=VALUES(modified_by),modified_at=NOW()")
        ->execute([$dId, $row['outer_shape'], $row['hub_dia'], $row['bore_type'], $row['bore_steps'], $row['bore_json'], $row['bore_max'], $row['bore_min'], $by !== '' ? $by : null]);
}

/** 讀單一料號（給編輯畫面）。沒有資料回 null。 */
function eg_shape_get(PDO $pdo, int $dId): ?array {
    try {
        $st = $pdo->prepare("SELECT outer_shape,hub_dia,bore_type,bore_steps,bore_json FROM d_setting_shape WHERE d_id=?");
        $st->execute([$dId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['bore'] = $r['bore_json'] ? (json_decode($r['bore_json'], true) ?: []) : [];
        unset($r['bore_json']);
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
        $st = $pdo->prepare("SELECT d_id,outer_shape,hub_dia,bore_type,bore_steps,bore_json FROM d_setting_shape WHERE d_id IN ($ph)");
        $st->execute($dIds);
        $defs = eg_shape_defs();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bore = $r['bore_json'] ? (json_decode($r['bore_json'], true) ?: []) : [];
            $ds = array_map(function ($b) { return rtrim(rtrim(number_format((float)$b['d'], 3, '.', ''), '0'), '.'); }, $bore);
            $out[(int)$r['d_id']] = [
                'outer'     => $r['outer_shape'],
                'outer_nm'  => $r['outer_shape'] ? ($defs['outer'][$r['outer_shape']] ?? '') : '',
                'hub'       => $r['hub_dia'] !== null ? rtrim(rtrim(number_format((float)$r['hub_dia'], 3, '.', ''), '0'), '.') : '',
                'bore_type' => $r['bore_type'],
                'bore_nm'   => $r['bore_type'] ? ($defs['bore'][$r['bore_type']]['name'] ?? '') : '',
                'dias'      => $ds,
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
    foreach ([['filter_shape_outer', 'outer_shape', $defs['outer'], 'fso'], ['filter_shape_bore', 'bore_type', $defs['bore'], 'fsb']] as [$key, $col, $valid, $pre]) {
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
