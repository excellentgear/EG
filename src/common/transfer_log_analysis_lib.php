<?php
/**
 * 製程移轉一覽表 — 分析用共用函式（2026-10-01 新增）
 *
 * 畫面風格仿 views/Sales/Order_Analysis.php，但本頁的期間篩選本來就是「任意日期區間」
 * （非 order_analysis_lib.php 那種年度＋固定粒度期別索引），兩種期間模型不同，
 * 故這裡另外寫一組輕量的「上一個等長期間／去年同期」比較計算，不勉強共用那一套。
 *
 * 唯一實作：本檔。呼叫端＝views/pm/Transfer_Log_Analysis.php。
 */

/** 比較基準可選項 */
function tla_cmp_bases(): array
{
    return ['prev' => '上一個等長期間', 'yoy' => '去年同期'];
}

/** 依目前期間與比較基準，算出比較期間的起訖日 */
function tla_compare_range(string $start, string $end, string $cmp): array
{
    $days = (int)floor((strtotime($end) - strtotime($start)) / 86400) + 1;
    if ($days < 1) $days = 1;
    if ($cmp === 'yoy') {
        $cs = date('Y-m-d', strtotime($start . ' -1 year'));
        $ce = date('Y-m-d', strtotime($end . ' -1 year'));
        return [$cs, $ce];
    }
    $ce = date('Y-m-d', strtotime($start . ' -1 day'));
    $cs = date('Y-m-d', strtotime($ce . ' -' . ($days - 1) . ' day'));
    return [$cs, $ce];
}

/** 製程大項篩選選項：只列啟用中的大項（供下拉使用） */
function tla_process_types(PDO $db): array
{
    return $db->query(
        "SELECT process_type_id, process_type FROM process_type WHERE is_active=1 ORDER BY sort_order, process_type_id"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** 比較期間用的精簡查詢（只取聚合需要的欄位，不含明細表格才需要的欄位） */
function tla_fetch_rows_lite(PDO $db, string $start, string $end, array $ptIds = []): array
{
    $sql = "SELECT t.transfer_date, t.transfer_qty, t.loss_qty, t.price, t.process_amount,
                   t.maker_from, m.maker_id AS maker_from_name, t.product_id,
                   pn.process_type_id, pt.process_type AS process_type_name
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list m ON t.maker_from = m.maker_id_no
            LEFT JOIN bom_ing bi ON t.bom = bi.bom AND t.bom_sn = bi.bom_sn
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN process_type pt ON pn.process_type_id = pt.process_type_id
            WHERE t.transfer_date BETWEEN :s AND :e";
    $params = [':s' => $start, ':e' => $end];
    if ($ptIds) {
        $ph = [];
        foreach (array_values($ptIds) as $i => $id) { $k = ":pt{$i}"; $ph[] = $k; $params[$k] = (int)$id; }
        $sql .= " AND pn.process_type_id IN (" . implode(',', $ph) . ")";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 把一批移轉紀錄彙總成：總計／依製程大項／依廠商／依料號，
 * 並順便標出單價為 0、NG 率 >10% 的筆數。
 * 供「本期」與「比較期間」共用同一套邏輯，兩邊算出來的結構一定一致才能直接相減比較。
 */
function tla_aggregate(array $rows): array
{
    $agg = [
        'count' => 0, 'qty' => 0.0, 'loss' => 0.0, 'amount' => 0.0,
        'zero_price' => 0, 'high_loss' => 0,
        'by_type'  => [],
        'by_maker' => [],
        'by_part'  => [],
    ];
    foreach ($rows as $r) {
        $qty   = (float)($r['transfer_qty'] ?? 0);
        $loss  = (float)($r['loss_qty'] ?? 0);
        $price = (float)($r['price'] ?? 0);
        $amt   = (float)($r['process_amount'] ?? 0);
        if ($amt == 0.0 && $qty > 0 && $price > 0) $amt = $qty * $price;

        $agg['count']++;
        $agg['qty']    += $qty;
        $agg['loss']   += $loss;
        $agg['amount'] += $amt;
        if ($price == 0.0) $agg['zero_price']++;
        if ($qty > 0 && ($loss / $qty) > 0.1) $agg['high_loss']++;

        $typeName = trim((string)($r['process_type_name'] ?? ''));
        if ($typeName === '') $typeName = '未分類';
        if (!isset($agg['by_type'][$typeName])) $agg['by_type'][$typeName] = ['qty' => 0.0, 'amount' => 0.0, 'count' => 0, 'loss' => 0.0];
        $agg['by_type'][$typeName]['qty']    += $qty;
        $agg['by_type'][$typeName]['amount'] += $amt;
        $agg['by_type'][$typeName]['count']++;
        $agg['by_type'][$typeName]['loss']   += $loss;

        $makerName = trim((string)($r['maker_from_name'] ?? ''));
        if ($makerName === '') $makerName = trim((string)($r['maker_from'] ?? '')) ?: '未知廠商';
        if (!isset($agg['by_maker'][$makerName])) $agg['by_maker'][$makerName] = ['qty' => 0.0, 'amount' => 0.0, 'count' => 0, 'loss' => 0.0];
        $agg['by_maker'][$makerName]['qty']    += $qty;
        $agg['by_maker'][$makerName]['amount'] += $amt;
        $agg['by_maker'][$makerName]['count']++;
        $agg['by_maker'][$makerName]['loss']   += $loss;

        $pid = trim((string)($r['product_id'] ?? '')) ?: '未知料號';
        if (!isset($agg['by_part'][$pid])) $agg['by_part'][$pid] = ['qty' => 0.0, 'amount' => 0.0, 'count' => 0, 'loss' => 0.0];
        $agg['by_part'][$pid]['qty']    += $qty;
        $agg['by_part'][$pid]['amount'] += $amt;
        $agg['by_part'][$pid]['count']++;
        $agg['by_part'][$pid]['loss']   += $loss;
    }
    return $agg;
}

/** 依金額排序並算出「前 N 名合計佔比」，用於集中度判斷 */
function tla_top_share(array $byX, int $n = 3): array
{
    $items = [];
    foreach ($byX as $name => $v) $items[] = ['name' => $name, 'amount' => (float)$v['amount'], 'qty' => (float)($v['qty'] ?? 0), 'count' => (int)($v['count'] ?? 0)];
    usort($items, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
    $total = array_sum(array_column($items, 'amount'));
    $top = array_slice($items, 0, $n);
    $topSum = array_sum(array_column($top, 'amount'));
    $share = $total > 0 ? ($topSum / $total * 100) : 0.0;
    return ['items' => $items, 'top' => $top, 'share' => $share, 'total' => $total];
}

/**
 * 本期才第一次出現加工紀錄的廠商（比對全表最早一筆移轉日期是否落在本期）。
 * 回傳 [['name'=>廠商名稱,'first_date'=>YYYY-MM-DD], ...]，供自動分析卡片附上日期用。
 */
function tla_new_makers(PDO $db, string $start, string $end, array $ptIds = []): array
{
    $sql = "SELECT t.maker_from, MIN(t.transfer_date) AS first_date, MAX(m.maker_id) AS maker_name
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list m ON t.maker_from = m.maker_id_no";
    $params = [];
    if ($ptIds) {
        $sql .= " LEFT JOIN bom_ing bi ON t.bom = bi.bom AND t.bom_sn = bi.bom_sn
                  LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo";
        $ph = [];
        foreach (array_values($ptIds) as $i => $id) { $k = ":pt{$i}"; $ph[] = $k; $params[$k] = (int)$id; }
        $sql .= " WHERE pn.process_type_id IN (" . implode(',', $ph) . ")";
    }
    $sql .= " GROUP BY t.maker_from HAVING first_date BETWEEN :s AND :e ORDER BY first_date";
    $params[':s'] = $start;
    $params[':e'] = $end;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['name' => $r['maker_name'] ?: $r['maker_from'], 'first_date' => $r['first_date']];
    }
    return $out;
}

/**
 * 每家廠商「有史以來第一次」出現在製程移轉紀錄裡的日期（不受畫面上的日期區間／製程大項篩選影響，
 * 回答的是「這家廠商什麼時候開始跟我們有加工往來」這件事，與 tla_new_makers() 判定「本期才首次出現」
 * 是不同用途）。回傳 [廠商顯示名稱 => YYYY-MM-DD]，同一個顯示名稱若對到好幾個廠商代號取最早的一筆。
 */
function tla_maker_first_dates(PDO $db): array
{
    $sql = "SELECT t.maker_from, MIN(t.transfer_date) AS first_date, MAX(m.maker_id) AS maker_name
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list m ON t.maker_from = m.maker_id_no
            GROUP BY t.maker_from";
    $out = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = trim((string)($r['maker_name'] ?: $r['maker_from']));
        if ($name === '') continue;
        if (!isset($out[$name]) || $r['first_date'] < $out[$name]) $out[$name] = $r['first_date'];
    }
    return $out;
}

/**
 * 時間趨勢改依製程大項堆疊：先算出每個期別×大項的金額／數量，
 * 再依整段期間總金額取前 $topN 大項，其餘併成「其他」，避免圖例爆版。
 */
function tla_trend_by_type(array $rows, string $groupBy, int $topN = 8): array
{
    $totalsByType = [];
    $cells = [];
    $keys  = [];
    foreach ($rows as $r) {
        $date = $r['transfer_date'];
        if ($groupBy === 'month') {
            $key = date('Y-m', strtotime($date));
        } elseif ($groupBy === 'week') {
            $key = date('Y/m/d', strtotime('monday this week', strtotime($date)));
        } else {
            $key = $date;
        }
        $keys[$key] = true;

        $qty   = (float)($r['transfer_qty'] ?? 0);
        $price = (float)($r['price'] ?? 0);
        $amt   = (float)($r['process_amount'] ?? 0);
        if ($amt == 0.0 && $qty > 0 && $price > 0) $amt = $qty * $price;

        $typeName = trim((string)($r['process_type_name'] ?? ''));
        if ($typeName === '') $typeName = '未分類';
        if (!isset($totalsByType[$typeName])) $totalsByType[$typeName] = 0.0;
        $totalsByType[$typeName] += $amt;

        if (!isset($cells[$key][$typeName])) $cells[$key][$typeName] = ['amount' => 0.0, 'qty' => 0.0];
        $cells[$key][$typeName]['amount'] += $amt;
        $cells[$key][$typeName]['qty']    += $qty;
    }
    arsort($totalsByType);
    $topTypes = array_slice(array_keys($totalsByType), 0, $topN);
    $hasOther = count($totalsByType) > $topN;
    $typeList = $topTypes;
    if ($hasOther) $typeList[] = '其他';

    $categories = array_keys($keys);
    sort($categories);

    $series = [];
    foreach ($typeList as $tn) {
        $series[$tn] = ['amount' => array_fill(0, count($categories), 0.0), 'qty' => array_fill(0, count($categories), 0.0)];
    }
    foreach ($categories as $ci => $cat) {
        if (!isset($cells[$cat])) continue;
        foreach ($cells[$cat] as $tn => $v) {
            $target = in_array($tn, $topTypes, true) ? $tn : '其他';
            if (!isset($series[$target])) continue;
            $series[$target]['amount'][$ci] += $v['amount'];
            $series[$target]['qty'][$ci]    += $v['qty'];
        }
    }

    return ['categories' => $categories, 'types' => $typeList, 'series' => $series];
}

/**
 * 自動分析：把「要自己盯著圖表才看得出來」的事直接寫成結論，每一條都附具體數字。
 * $cur／$prev 皆為 tla_aggregate() 的回傳結構；$newMakers 為 tla_new_makers() 的回傳。
 */
function tla_insights(array $cur, array $prev, array $newMakers, string $cmpLabel): array
{
    $ins = [];
    $push = function ($level, $icon, $title, $detail, $metric = null) use (&$ins) {
        $ins[] = ['level' => $level, 'icon' => $icon, 'title' => $title, 'detail' => $detail, 'metric' => $metric];
    };

    // 1. 加工金額變化
    $curAmt = $cur['amount']; $prevAmt = $prev['amount'];
    $delta = $curAmt - $prevAmt;
    $pctTxt = '';
    if ($prevAmt > 0) {
        $pct = $delta / $prevAmt * 100;
        if (abs($pct) < 1000) $pctTxt = sprintf('%+.1f%%', $pct);
    }
    $lvl = 'info';
    if ($delta > 0) $lvl = 'good';
    elseif ($delta < 0) $lvl = ($prevAmt > 0 && ($delta / $prevAmt) <= -0.2) ? 'bad' : 'warn';
    $icon = $delta > 0 ? 'fa-arrow-up' : ($delta < 0 ? 'fa-arrow-down' : 'fa-minus');
    $push($lvl, $icon, '加工金額' . ($delta >= 0 ? '成長' : '下滑'),
        sprintf('本期加工金額 %s 萬，較%s（%s 萬）%s %s 萬%s',
            number_format($curAmt / 10000, 2), $cmpLabel, number_format($prevAmt / 10000, 2),
            $delta >= 0 ? '增加' : '減少', number_format(abs($delta) / 10000, 2), $pctTxt ? '（' . $pctTxt . '）' : ''),
        $pctTxt ?: null);

    // 2. 加工數量變化
    $curQty = $cur['qty']; $prevQty = $prev['qty'];
    $dq = $curQty - $prevQty;
    if ($curQty > 0 || $prevQty > 0) {
        $qpct = '';
        if ($prevQty > 0) {
            $p = $dq / $prevQty * 100;
            if (abs($p) < 1000) $qpct = sprintf('%+.1f%%', $p);
        }
        $push($dq >= 0 ? 'info' : 'warn', $dq >= 0 ? 'fa-arrow-up' : 'fa-arrow-down', '加工數量' . ($dq >= 0 ? '成長' : '下滑'),
            sprintf('本期加工數量 %s PCS，較%s（%s PCS）%s %s PCS%s',
                number_format($curQty), $cmpLabel, number_format($prevQty),
                $dq >= 0 ? '增加' : '減少', number_format(abs($dq)), $qpct ? '（' . $qpct . '）' : ''));
    }

    // 3. 製程大項集中度
    $ts = tla_top_share($cur['by_type'], 3);
    if ($ts['total'] > 0 && $ts['share'] >= 50) {
        $names = implode('、', array_map(function ($x) { return $x['name']; }, $ts['top']));
        $push('warn', 'fa-pie-chart', '製程大項集中',
            sprintf('前三大製程大項（%s）合計佔本期加工金額 %.1f%%，集中度偏高。', $names, $ts['share']));
    }

    // 4. 加工廠商集中度
    $ms = tla_top_share($cur['by_maker'], 3);
    if ($ms['total'] > 0 && $ms['share'] >= 50) {
        $names = implode('、', array_map(function ($x) { return $x['name']; }, $ms['top']));
        $push('warn', 'fa-building', '加工廠商集中',
            sprintf('前三大加工廠商（%s）合計佔本期加工金額 %.1f%%，集中度偏高。', $names, $ms['share']));
    }

    // 5. 單價為 0
    if ($cur['zero_price'] > 0) {
        $push($cur['zero_price'] >= 10 ? 'bad' : 'warn', 'fa-exclamation-circle', '單價為 0',
            sprintf('本期有 %d 筆移轉單價為 0，加工金額可能漏填或尚未匯入，建議用下方「異常偵測」查看明細。', $cur['zero_price']));
    }

    // 6. 高損耗率
    if ($cur['high_loss'] > 0) {
        $push($cur['high_loss'] >= 10 ? 'bad' : 'warn', 'fa-exclamation-triangle', '高損耗率（NG率 > 10%）',
            sprintf('本期有 %d 筆移轉的 NG 率超過 10%%，建議留意該站製程良率。', $cur['high_loss']));
    }

    // 7. 新增加工廠商（附第一次交易日期）
    if (!empty($newMakers)) {
        $labels = array_map(function ($m) {
            $d = $m['first_date'] ? date('Y.m.d', strtotime($m['first_date'])) : '';
            return $m['name'] . ($d ? '（' . $d . '）' : '');
        }, array_slice($newMakers, 0, 5));
        $names = implode('、', $labels);
        $more = count($newMakers) > 5 ? '等共 ' . count($newMakers) . ' 家' : '';
        $push('info', 'fa-handshake-o', '新增加工廠商',
            sprintf('本期首次出現加工紀錄的廠商：%s%s。', $names, $more));
    }

    if (empty($ins)) {
        $push('good', 'fa-check-circle', '資料正常', '本期沒有偵測到明顯的異常或集中度過高的情況。');
    }
    return $ins;
}
