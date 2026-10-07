<?php
/**
 * 機台「兩層挑選器」資料來源（2026-10-08 建立）
 *
 * 分類一律＝機台綁定的製程大類（machine_list.machine_type_id → process_type），挑選互動
 * 比照 views/QA/sop_sip_ui.js 的 ssPick()／src/common/sopsip_lib.php 的 ss_search_machine()／
 * ss_pick_groups()（使用者 2026-10-07 明確指定「專用機台的挑選方式要跟 sop_sip.php 一樣」）。
 *
 * 這支獨立建給 master_data_management.php 的「專用機台／專用機台種類／專用機型」共用，
 * 刻意不去改 sopsip_lib.php 本身——那支是 AS9100 文件模組的核心查詢、被大量既有功能
 * 依賴，牽動它去抽共用的風險遠大於目前這兩段邏輯（約60行、純讀取無副作用）重複一次的成本；
 * 往後若兩邊的分組規則真的要同步演進，再評估讓 sopsip_lib.php 改呼叫這支。
 */

/** 機台在用狀態條件（state='1' 才是停用；asof 有值時，那天之前還沒停用的也算在用，補舊資料用） */
function eg_machine_active_cond(string $asof, array &$p, string $alias = ''): string
{
    $a = $alias !== '' ? "$alias." : '';
    if ($asof !== '') {
        $p[] = $asof;
        return "({$a}state IS NULL OR {$a}state!='1' OR {$a}disabled_date IS NULL OR {$a}disabled_date>=?)";
    }
    return "({$a}state IS NULL OR {$a}state!='1')";
}

/** 機台模糊搜尋（單筆版），一併帶回所屬製程大類供分組用 */
function eg_search_machine_rows(PDO $db, string $kw, int $limit = 30, string $asof = ''): array
{
    $kw = trim($kw);
    $p  = [];
    $w  = [eg_machine_active_cond($asof, $p, 'm')];
    if ($kw !== '') {
        $w[] = "(m.asset_no LIKE ? OR m.field_no LIKE ? OR m.machine LIKE ? OR m.machine_model LIKE ?)";
        for ($i = 0; $i < 4; $i++) $p[] = '%' . $kw . '%';
    }
    $st = $db->prepare("SELECT m.machine_id, m.machine, m.field_no, m.asset_no, m.machine_model,
                               m.manufacturer, m.spec, m.machine_type_id, m.state, m.disabled_date,
                               pt.process_type AS proc_type_name, pt.process_type_id AS proc_type_id,
                               COALESCE(pt.sort_order, 9999) AS proc_sort
                        FROM machine_list m
                        LEFT JOIN process_type pt ON pt.process_type_id = m.machine_type_id
                        WHERE " . implode(' AND ', $w) . "
                        ORDER BY (m.state='1'), proc_sort, pt.process_type, m.asset_no, m.field_no LIMIT $limit");
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['off']      = ((string)($r['state'] ?? '') === '1') ? 1 : 0;
        $r['off_date'] = (string)($r['disabled_date'] ?? '');
    }
    return $rows;
}

/**
 * 「先點分類、再點項目」兩層挑選器資料。
 * @param string $mode machine＝個別機台／model＝機台型號／category＝製程大類本身
 *                     （category 模式沒有第二層：被挑選的對象就是分類自己，呼叫端畫面
 *                      不必再往下鑽一層，一步到位列出全部有機台掛著的大類）
 * @return array [['group'=>組名,'rows'=>[['value','id','no','name','sub']]]]
 */
function eg_machine_pick_groups(PDO $db, string $mode, string $kw, string $asof = ''): array
{
    if ($mode === 'category') {
        $kwTrim = trim($kw);
        $rows = $db->query("SELECT pt.process_type_id, pt.process_type, pt.sort_order,
                                    COUNT(m.machine_id) AS machine_cnt
                             FROM process_type pt
                             JOIN machine_list m ON m.machine_type_id = pt.process_type_id
                             GROUP BY pt.process_type_id, pt.process_type, pt.sort_order
                             HAVING machine_cnt > 0
                             ORDER BY pt.sort_order, pt.process_type")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if ($kwTrim !== '' && mb_stripos((string)$r['process_type'], $kwTrim) === false
                && mb_stripos((string)$r['process_type_id'], $kwTrim) === false) continue;
            $out[] = ['value' => (string)$r['process_type_id'], 'id' => (int)$r['process_type_id'],
                      'no' => (string)$r['process_type'], 'name' => $r['machine_cnt'] . ' 台機器'];
        }
        return [['group' => '製程大類', 'rows' => $out]];
    }
    if ($mode === 'model') {
        $g = [];
        foreach (eg_search_machine_rows($db, $kw, 500, $asof) as $m) {
            $key   = trim((string)($m['proc_type_name'] ?? '')) ?: '未分類';
            $model = trim((string)$m['machine_model']);
            if ($model === '') continue;
            $g[$key]['sort'] = (int)($m['proc_sort'] ?? 9999);
            if (!isset($g[$key]['rows'][$model])) {
                $g[$key]['rows'][$model] = ['value' => $model, 'id' => 0, 'no' => $model,
                                            'name' => trim((string)$m['machine']), 'cnt' => 0, 'nos' => []];
            }
            $g[$key]['rows'][$model]['cnt']++;
            $g[$key]['rows'][$model]['nos'][] = (string)($m['asset_no'] ?: $m['field_no']);
        }
        uasort($g, fn($a, $b) => [$a['sort']] <=> [$b['sort']]);
        $out = [];
        foreach ($g as $name => $x) {
            $rows = [];
            foreach ($x['rows'] as $r) {
                $r['sub'] = $r['cnt'] . ' 台：' . implode('、', array_slice(array_filter($r['nos']), 0, 6));
                unset($r['cnt'], $r['nos']);
                $rows[] = $r;
            }
            $out[] = ['group' => $name, 'rows' => $rows];
        }
        return $out;
    }
    // machine：個別機台，依製程大類分組
    $g = [];
    foreach (eg_search_machine_rows($db, $kw, 500, $asof) as $m) {
        $key = trim((string)($m['proc_type_name'] ?? '')) ?: '未分類';
        $g[$key]['sort'] = (int)($m['proc_sort'] ?? 9999);
        $g[$key]['rows'][] = [
            'value' => (string)($m['asset_no'] ?: ($m['field_no'] ?: $m['machine'])),
            'id'    => (int)$m['machine_id'],
            'no'    => (string)($m['asset_no'] ?: '(未編號)'),
            'name'  => trim((string)($m['field_no'] ?: $m['machine'])),
            'sub'   => trim((string)$m['machine_model']),
        ];
    }
    uasort($g, fn($a, $b) => [$a['sort']] <=> [$b['sort']]);
    $out = [];
    foreach ($g as $name => $x) $out[] = ['group' => $name, 'rows' => $x['rows']];
    return $out;
}
