<?php
/**
 * 退貨（客戶退貨單 ir_track）統計 —— 唯一實作（2026-10-15）
 *  - 退貨追蹤頁的「退貨統計」分頁、管理審查會議範本的「客戶抱怨（退貨）統計」共用這一份，不各寫一份（鐵律4）
 *  - 「退貨性質」標成備註(is_note=1)的單據只是備註、不是真的退貨，一律不計
 *  - 退貨原因：系統裡沒有結構化的原因欄（IR_ps 是自由文字），所以「原因」取自該退貨單已開的
 *    品質異常處理單所選的「異常原因分類」（ir_id 或 source_id 綁定；軟刪除的不算）；
 *    沒開異常單的歸為「尚未開異常單／未分類」，畫面上要明講，不可當成沒有原因。
 */

/** 退貨單的「有效退貨」條件（排除備註性質） */
function irs_valid_cond(string $t = 't'): string {
    return "($t.return_type_id IS NULL OR $t.return_type_id NOT IN (SELECT type_id FROM ir_return_type WHERE is_note=1))";
}

function irs_ensure(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $r = $db->query("SHOW TABLES LIKE 'ir_cause'")->fetchColumn();
    if ($r === false && !$db->inTransaction())
        $db->exec("CREATE TABLE IF NOT EXISTS ir_cause (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ir_id INT NOT NULL COMMENT 'ir_track.IR_id',
            cat_id INT NOT NULL COMMENT 'qa_cause_cat.cat_id（與品質異常單共用同一棵原因分類樹）',
            created_by INT NULL, created_at DATETIME NULL,
            UNIQUE KEY uk_ir_cat (ir_id, cat_id), KEY idx_cat (cat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退貨單的退貨原因分類（可複選）'");
}

function irs_cat_path_map(PDO $db): array {
    $rows = $db->query("SELECT cat_id, parent_id, name FROM qa_cause_cat")->fetchAll(PDO::FETCH_ASSOC);
    $by = []; foreach ($rows as $r) $by[(int)$r['cat_id']] = $r;
    $out = [];
    foreach ($by as $id => $r) {
        $p = [$r['name']]; $g = 0; $pid = (int)$r['parent_id'];
        while ($pid && isset($by[$pid]) && $g++ < 5) { array_unshift($p, $by[$pid]['name']); $pid = (int)$by[$pid]['parent_id']; }
        $out[$id] = implode('／', $p);
    }
    return $out;
}

/**
 * @return array{total:int,qty:float,prev_total:int,prev_qty:float,months:array,clients:array,types:array,
 *               causes:array,qa_opened:int,qa_none:int,from:string,to:string}
 */
function irs_stats(PDO $db, string $from, string $to): array {
    $valid = irs_valid_cond('t');
    $out = ['from'=>$from, 'to'=>$to, 'total'=>0, 'qty'=>0.0, 'prev_total'=>0, 'prev_qty'=>0.0,
            'months'=>[], 'clients'=>[], 'types'=>[], 'causes'=>[], 'qa_opened'=>0, 'qa_none'=>0, 'self_classified'=>0];

    $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(t.Qty),0) q FROM ir_track t WHERE $valid AND t.IR_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $out['total'] = (int)$r['n']; $out['qty'] = (float)$r['q'];

    // 去年同期
    $pf = date('Y-m-d', strtotime($from . ' -1 year')); $pt = date('Y-m-d', strtotime($to . ' -1 year'));
    $st->execute([$pf, $pt]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $out['prev_total'] = (int)$r['n']; $out['prev_qty'] = (float)$r['q'];

    // 逐月
    $st = $db->prepare("SELECT DATE_FORMAT(t.IR_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(t.Qty),0) q
                          FROM ir_track t WHERE $valid AND t.IR_date BETWEEN ? AND ? GROUP BY ym ORDER BY ym");
    $st->execute([$from, $to]);
    $out['months'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // 客戶（與清單同一套歸戶：料號主檔客戶優先，退回單據上的客戶文字）
    $st = $db->prepare("SELECT COALESCE(cl.customer, NULLIF(TRIM(t.Client_name),''), '（未填客戶）') c, COUNT(*) n, COALESCE(SUM(t.Qty),0) q
                          FROM ir_track t
                          LEFT JOIN d_setting ds ON ds.d_id = COALESCE(t.d_setting_id, IF(t.d_id REGEXP '^[0-9]+$', CAST(t.d_id AS UNSIGNED), NULL))
                          LEFT JOIN customer_list cl ON ds.Customer_Id = cl.customer_id
                         WHERE $valid AND t.IR_date BETWEEN ? AND ?
                         GROUP BY c ORDER BY n DESC, q DESC");
    $st->execute([$from, $to]);
    $out['clients'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // 退貨性質
    $st = $db->prepare("SELECT COALESCE(irt.type_name,'（未分類）') c, COUNT(*) n, COALESCE(SUM(t.Qty),0) q
                          FROM ir_track t LEFT JOIN ir_return_type irt ON irt.type_id=t.return_type_id
                         WHERE $valid AND t.IR_date BETWEEN ? AND ? GROUP BY c ORDER BY n DESC");
    $st->execute([$from, $to]);
    $out['types'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // 原因分類：①退貨單自己挑的（ir_cause，統計頁的「原因分類」作業）優先
    //          ②沒挑的，退回看它已開的品質異常處理單所選的原因分類 ③都沒有＝未分類（畫面要講明）
    irs_ensure($db);
    $ids = $db->prepare("SELECT t.IR_id, t.Qty FROM ir_track t WHERE $valid AND t.IR_date BETWEEN ? AND ?");
    $ids->execute([$from, $to]);
    $irQty = []; foreach ($ids->fetchAll(PDO::FETCH_ASSOC) as $r) $irQty[(int)$r['IR_id']] = (float)$r['Qty'];
    if ($irQty) {
        $in = implode(',', array_keys($irQty));
        $catsOf = []; // ir_id => [cat ids]
        foreach ($db->query("SELECT ir_id, cat_id FROM ir_cause WHERE ir_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $catsOf[(int)$r['ir_id']][] = (int)$r['cat_id'];
        $out['self_classified'] = count($catsOf);
        $q = $db->query("SELECT o.id, COALESCE(NULLIF(o.ir_id,0), IF(o.source_type='IR', o.source_id, NULL)) ir_key
                           FROM qa_abnormal_order o
                          WHERE o.deleted_at IS NULL
                            AND COALESCE(NULLIF(o.ir_id,0), IF(o.source_type='IR', o.source_id, NULL)) IN ($in)");
        $ord = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $ord[(int)$r['ir_key']][] = (int)$r['id'];
        $out['qa_opened'] = count($ord);
        $allOrd = []; foreach ($ord as $a) foreach ($a as $o) $allOrd[$o] = true;
        $qaCat = [];
        if ($allOrd) {
            $oin = implode(',', array_keys($allOrd));
            foreach ($db->query("SELECT order_id, cat_id FROM qa_abnormal_cause WHERE order_id IN ($oin)")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $qaCat[(int)$r['order_id']][] = (int)$r['cat_id'];
        }
        $paths = irs_cat_path_map($db);
        $cnt = []; $qty = []; $none = 0;
        foreach ($irQty as $irId => $qv) {
            $cats = [];
            foreach (($catsOf[$irId] ?? []) as $c) $cats[$c] = true;
            if (!$cats) foreach (($ord[$irId] ?? []) as $o) foreach (($qaCat[$o] ?? []) as $c) $cats[$c] = true;
            if (!$cats) { $none++; continue; }
            foreach (array_keys($cats) as $c) {
                $k = $paths[$c] ?? ('分類#' . $c);
                $cnt[$k] = ($cnt[$k] ?? 0) + 1; $qty[$k] = ($qty[$k] ?? 0) + $qv;
            }
        }
        $out['qa_none'] = $none;   // 仍未分類的退貨單數
        arsort($cnt);
        foreach ($cnt as $k => $n) $out['causes'][] = ['c'=>$k, 'n'=>$n, 'q'=>$qty[$k]];
        if ($none > 0) {
            $nq = 0; foreach ($irQty as $id => $qv) if (empty($catsOf[$id]) && empty($ord[$id])) $nq += $qv; // 近似：未分類數量
            $out['causes'][] = ['c'=>'（尚未分類）', 'n'=>$none, 'q'=>$nq];
        }
    }
    return $out;
}

/** 會議用的文字摘要 */
function irs_text(PDO $db, string $from, string $to): string {
    $s = irs_stats($db, $from, $to);
    $d = function ($cur, $prev) { return $prev > 0 ? sprintf('（去年同期 %s，%s%s）', (0 + $prev), $cur >= $prev ? '增加' : '減少', (0 + abs($cur - $prev))) : '（去年同期無資料）'; };
    $L = ["【系統資料】{$from} ～ {$to} 客戶退貨：共 {$s['total']} 件{$d($s['total'], $s['prev_total'])}，退貨數量 " . (0 + $s['qty']) . ' 件' . $d($s['qty'], $s['prev_qty']) . '。'];
    if ($s['clients']) {
        $top = array_slice($s['clients'], 0, 5); $p = [];
        foreach ($top as $c) $p[] = "{$c['c']} {$c['n']}件/" . (0 + $c['q']) . '數量';
        $L[] = '・客戶別（前5）：' . implode('、', $p);
    }
    if ($s['types']) { $p = []; foreach ($s['types'] as $c) $p[] = "{$c['c']} {$c['n']}件"; $L[] = '・退貨性質：' . implode('、', $p); }
    if ($s['causes']) {
        $p = []; foreach (array_slice($s['causes'], 0, 6) as $c) $p[] = "{$c['c']} {$c['n']}件";
        $L[] = "・退貨原因分類（尚未分類 {$s['qa_none']} 件）：" . implode('、', $p);
    }
    return implode("\n", $L);
}
