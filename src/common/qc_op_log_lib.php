<?php
/**
 * QC 檢驗作業紀錄（2026-08-27 使用者要求）
 *
 * 為什麼是「查詢」而不是「另開一張紀錄表」：
 *   誰按了完工、誰送出／改過檢驗單、誰留了未送出的草稿，這些**資料本來就已經存在**
 *   （bom_ing.qc_completed_by/at、qc_check_form.created_by/at、last_edited_by/at），
 *   只是站上沒有任何畫面看得到。依 ai-rules/23 的鐵則「禁止各模組各自再開一張紀錄表」，
 *   這裡只做讀取與彙總，不新增任何紀錄表、不寫入任何資料。
 *
 * 四種事件（kind）：
 *   complete ── 在 QC 待驗清單按下「完成」（bom_ing）
 *   submit   ── 送出檢驗單（qc_check_form，status<>DRAFT，建立時間）
 *   edit     ── 事後修改已送出的檢驗單（qc_check_form.last_edited_*）
 *   draft    ── 還沒送出的草稿（qc_check_form，status=DRAFT）
 *
 * 消費端：src/store/PrintSignLog_API.php 的 list_qcop / stat_qcop
 *         → views/admin/print_sign_log.php 的「檢驗作業」與「月統計」分頁
 */

if (!function_exists('eg_qcop_kinds')) {
    /** 事件種類登錄表（畫面上的名稱一律由這裡來，不要在別處再寫死一份＝鐵律4） */
    function eg_qcop_kinds(): array {
        return [
            'complete' => ['label' => '按下完成',   'desc' => 'QC 待驗清單按下「完成」，該製程結案並推進生產狀態'],
            'submit'   => ['label' => '送出檢驗單', 'desc' => '線上檢驗記錄表存檔送出，產生正式檢驗紀錄'],
            'edit'     => ['label' => '修改檢驗單', 'desc' => '已送出的檢驗單事後被修改（需填修改原因）'],
            'draft'    => ['label' => '未送出草稿', 'desc' => '填寫中自動保存、但一直沒有送出的草稿'],
        ];
    }
}

if (!function_exists('eg_qcop_union_sql')) {
    /**
     * 四種事件合併成同一個結果集。
     *
     * 兩個型別上的坑：
     *   ① qc_check_form.created_by / last_edited_by 是 char(11)，user.id 是 int，
     *      直接 join 會是字串比對（'1' vs 1 在有些情況下走不到索引也對不上），一律先 CAST。
     *   ② 舊資料的 last_edited_by 可能是空字串而不是 NULL，NULLIF 要一起處理。
     */
    function eg_qcop_union_sql(): string {
        $joinBom = "LEFT JOIN bom b ON b.bom = bi.bom
                    LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no";
        $formCols = "f.bom_ing_fid, f.qc_form_id, bi.bom, b.d_id, b.Client_Name,
                     COALESCE(NULLIF(f.process_name,''), pn.ProcessName), bi.maker_id";
        return "(
            SELECT 'complete' AS kind, bi.qc_completed_at AS ev_at,
                   bi.qc_completed_by AS ev_uid,
                   bi.bom_ing_fid AS fid, 0 AS form_id, bi.bom AS bom, b.d_id AS part_no,
                   b.Client_Name AS client, pn.ProcessName AS process, bi.maker_id AS maker,
                   NULL AS result
            FROM bom_ing bi
            $joinBom
            WHERE bi.qc_completed = 1 AND bi.qc_completed_at IS NOT NULL AND bi.qc_completed_by > 0

            UNION ALL
            SELECT 'submit', f.created_at, CAST(TRIM(f.created_by) AS UNSIGNED),
                   $formCols, f.check_result
            FROM qc_check_form f
            LEFT JOIN bom_ing bi ON bi.bom_ing_fid = f.bom_ing_fid
            $joinBom
            WHERE f.status <> 'DRAFT' AND f.created_at IS NOT NULL AND TRIM(f.created_by) <> ''

            UNION ALL
            SELECT 'edit', f.last_edited_at, CAST(TRIM(f.last_edited_by) AS UNSIGNED),
                   $formCols, f.check_result
            FROM qc_check_form f
            LEFT JOIN bom_ing bi ON bi.bom_ing_fid = f.bom_ing_fid
            $joinBom
            WHERE f.status <> 'DRAFT' AND f.last_edited_at IS NOT NULL
              AND f.last_edited_by IS NOT NULL AND TRIM(f.last_edited_by) <> ''

            UNION ALL
            SELECT 'draft', COALESCE(f.last_edited_at, f.created_at),
                   CAST(TRIM(COALESCE(NULLIF(TRIM(f.last_edited_by),''), f.created_by)) AS UNSIGNED),
                   $formCols, NULL
            FROM qc_check_form f
            LEFT JOIN bom_ing bi ON bi.bom_ing_fid = f.bom_ing_fid
            $joinBom
            WHERE f.status = 'DRAFT'
        )";
    }
}

if (!function_exists('eg_qcop_where')) {
    /**
     * 共用的篩選條件（清單與統計吃同一份，兩邊條件走鐘就會出現
     * 「清單 12 筆、統計卻是 9 筆」這種對不起來的狀況）。
     * 回傳 [whereSql, args]
     */
    function eg_qcop_where(array $f): array {
        $w = []; $a = [];
        $kinds = eg_qcop_kinds();
        if (!empty($f['kind']) && isset($kinds[$f['kind']])) { $w[] = 'x.kind = ?'; $a[] = $f['kind']; }
        if (!empty($f['user_id']))   { $w[] = 'x.ev_uid = ?';  $a[] = (int)$f['user_id']; }
        if (!empty($f['date_from'])) { $w[] = 'x.ev_at >= ?';  $a[] = $f['date_from'] . ' 00:00:00'; }
        if (!empty($f['date_to']))   { $w[] = 'x.ev_at <= ?';  $a[] = $f['date_to']   . ' 23:59:59'; }
        if (!empty($f['year']))      { $w[] = 'YEAR(x.ev_at) = ?'; $a[] = (int)$f['year']; }
        if (!empty($f['process']))   { $w[] = 'x.process = ?'; $a[] = $f['process']; }
        if (!empty($f['maker']))     { $w[] = 'x.maker = ?';   $a[] = $f['maker']; }
        if (!empty($f['kw'])) {
            // 全表搜尋：掃過畫面上看得到的所有欄位，多個關鍵字每個都要命中（可分散在不同欄位）
            foreach (preg_split('/\s+/', trim((string)$f['kw'])) as $word) {
                if ($word === '') continue;
                $w[] = '(x.bom LIKE ? OR x.part_no LIKE ? OR x.client LIKE ? OR x.process LIKE ?
                         OR x.maker LIKE ? OR u.user_cname LIKE ?)';
                for ($i = 0; $i < 6; $i++) $a[] = '%' . $word . '%';
            }
        }
        return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $a];
    }
}

if (!function_exists('eg_qcop_query')) {
    /**
     * 清單查詢。$f: kind / user_id / date_from / date_to / kw / process / maker / page / per
     * per=0 ＝ 不分頁（列印全部／匯出用）
     */
    function eg_qcop_query(PDO $db, array $f): array {
        [$w, $args] = eg_qcop_where($f);
        $base = eg_qcop_union_sql() . ' x LEFT JOIN `user` u ON u.id = x.ev_uid';

        $cnt = $db->prepare("SELECT COUNT(*) FROM $base$w");
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();

        $sql = "SELECT x.*, u.user_cname AS ev_user_name FROM $base$w ORDER BY x.ev_at DESC, x.fid DESC";
        $per = (int)($f['per'] ?? 20);
        if ($per > 0) {
            $page = max(1, (int)($f['page'] ?? 1));
            $sql .= ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);
        }
        $st = $db->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $kinds = eg_qcop_kinds();
        foreach ($rows as &$r) {
            $r['kind_label'] = $kinds[$r['kind']]['label'] ?? $r['kind'];
            $r['ev_uid']     = (int)$r['ev_uid'];
            $r['fid']        = (int)$r['fid'];
            $r['form_id']    = (int)$r['form_id'];
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('eg_qcop_metrics')) {
    /** 月統計可選的指標（新增指標只要加在這裡，UI 會自己長出來＝鐵律4） */
    function eg_qcop_metrics(): array {
        return [
            'complete' => ['label' => '完工筆數',        'kind' => 'complete', 'rate' => false,
                           'hint'  => '在 QC 待驗清單按下「完成」的筆數'],
            'submit'   => ['label' => '檢驗單送出筆數',  'kind' => 'submit',   'rate' => false,
                           'hint'  => '送出的正式檢驗單筆數'],
            'pass'     => ['label' => '檢驗合格率',      'kind' => 'submit',   'rate' => true,
                           'hint'  => '送出的檢驗單中判定合格(OK)的比率；格內顯示「合格數／總數」'],
            'draft'    => ['label' => '未送出草稿筆數',  'kind' => 'draft',    'rate' => false,
                           'hint'  => '填到一半自動保存、一直沒有送出的草稿筆數'],
        ];
    }
}

if (!function_exists('eg_qcop_dims')) {
    /** 展開維度。expr 是 SQL 片段，全部由程式自己給、不吃使用者輸入 */
    function eg_qcop_dims(): array {
        return [
            'none'    => ['label' => '不展開（只看合計）', 'expr' => "'合計'"],
            'user'    => ['label' => '依人員',
                          'expr'  => "COALESCE(NULLIF(u.user_cname,''), CONCAT('（帳號 ', x.ev_uid, '）'))"],
            'process' => ['label' => '依製程', 'expr' => "COALESCE(NULLIF(x.process,''), '（未指定製程）')"],
            'maker'   => ['label' => '依廠商', 'expr' => "COALESCE(NULLIF(x.maker,''), '（廠內）')"],
        ];
    }
}

if (!function_exists('eg_qcop_stat')) {
    /**
     * 月統計矩陣：列＝展開維度，欄＝1~12 月。
     * $f: year / metric / dim ＋ 與清單相同的 user_id / kw / process / maker 篩選
     *
     * 回傳每一格都帶 n（筆數）與 ok（合格數），前端要顯示筆數或合格率都用同一份資料，
     * 不必為了換指標再打一次 API。
     */
    function eg_qcop_stat(PDO $db, array $f): array {
        $metrics = eg_qcop_metrics();
        $dims    = eg_qcop_dims();
        $mk  = isset($metrics[$f['metric'] ?? '']) ? $f['metric'] : 'complete';
        $dk  = isset($dims[$f['dim'] ?? ''])       ? $f['dim']    : 'none';
        $year = (int)($f['year'] ?? 0) ?: (int)date('Y');

        // 統計一律以「該指標對應的事件種類」為準，不吃外面傳進來的 kind
        $ff = $f;
        $ff['kind'] = $metrics[$mk]['kind'];
        $ff['year'] = $year;
        unset($ff['date_from'], $ff['date_to']);   // 統計以年度為單位，日期區間不適用
        [$w, $args] = eg_qcop_where($ff);

        $sql = "SELECT MONTH(x.ev_at) AS m, {$dims[$dk]['expr']} AS dim_val,
                       COUNT(*) AS n,
                       SUM(CASE WHEN x.result = 'OK' THEN 1 ELSE 0 END) AS ok_n
                FROM " . eg_qcop_union_sql() . " x LEFT JOIN `user` u ON u.id = x.ev_uid
                $w
                GROUP BY m, dim_val
                ORDER BY dim_val ASC, m ASC";
        $st = $db->prepare($sql);
        $st->execute($args);

        $cells = [];      // dim => [1..12 => ['n'=>,'ok'=>]]
        $order = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = (string)$r['dim_val'];
            $m = (int)$r['m'];
            if (!isset($cells[$d])) { $cells[$d] = []; $order[] = $d; }
            $cells[$d][$m] = ['n' => (int)$r['n'], 'ok' => (int)$r['ok_n']];
        }

        // 依「全年合計」由多到少排，一眼看得出誰／哪個製程量最大
        usort($order, function ($a, $b) use ($cells) {
            $sa = 0; $sb = 0;
            foreach ($cells[$a] as $c) $sa += $c['n'];
            foreach ($cells[$b] as $c) $sb += $c['n'];
            if ($sa === $sb) return strcmp($a, $b);
            return $sb <=> $sa;
        });

        $rows = [];
        $foot = ['n' => array_fill(1, 12, 0), 'ok' => array_fill(1, 12, 0), 'sum_n' => 0, 'sum_ok' => 0];
        foreach ($order as $d) {
            $months = []; $sumN = 0; $sumOk = 0;
            for ($m = 1; $m <= 12; $m++) {
                $c = $cells[$d][$m] ?? ['n' => 0, 'ok' => 0];
                $months[$m] = $c;
                $sumN += $c['n']; $sumOk += $c['ok'];
                $foot['n'][$m]  += $c['n'];
                $foot['ok'][$m] += $c['ok'];
            }
            $foot['sum_n'] += $sumN; $foot['sum_ok'] += $sumOk;
            $rows[] = ['dim' => $d, 'months' => $months, 'sum_n' => $sumN, 'sum_ok' => $sumOk];
        }

        return [
            'year'    => $year,
            'metric'  => $mk,
            'dim'     => $dk,
            'is_rate' => (bool)$metrics[$mk]['rate'],
            'rows'    => $rows,
            'foot'    => $foot,
            'metrics' => array_map(fn($k, $v) => ['code' => $k, 'label' => $v['label'], 'hint' => $v['hint'], 'rate' => $v['rate']],
                                   array_keys($metrics), $metrics),
            'dims'    => array_map(fn($k, $v) => ['code' => $k, 'label' => $v['label']],
                                   array_keys($dims), $dims),
        ];
    }
}

if (!function_exists('eg_qcop_facets')) {
    /**
     * 這段區間內實際出現過的人員／製程／廠商／年度，給篩選下拉用。
     * 篩選選項一律「跟著目前區間走」，才不會選下去是 0 筆（比照本頁列印分頁既有作法）。
     */
    function eg_qcop_facets(PDO $db, array $f): array {
        $ff = $f; unset($ff['user_id'], $ff['process'], $ff['maker'], $ff['kw']);
        if (!empty($f['only_uid'])) { $ff['user_id'] = (int)$f['only_uid']; }
        [$w, $args] = eg_qcop_where($ff);
        $base = eg_qcop_union_sql() . ' x LEFT JOIN `user` u ON u.id = x.ev_uid';

        $out = ['users' => [], 'processes' => [], 'makers' => [], 'years' => []];
        try {
            $st = $db->prepare("SELECT DISTINCT x.ev_uid, x.process, x.maker, YEAR(x.ev_at) AS y FROM $base$w");
            $st->execute($args);
            $uids = []; $pr = []; $mk = []; $yr = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int)$r['ev_uid'] > 0) $uids[(int)$r['ev_uid']] = true;
                if (($r['process'] ?? '') !== '') $pr[(string)$r['process']] = true;
                if (($r['maker']   ?? '') !== '') $mk[(string)$r['maker']]   = true;
                if ((int)$r['y'] > 0) $yr[(int)$r['y']] = true;
            }
            $out['users'] = array_keys($uids);
            $out['processes'] = array_keys($pr); sort($out['processes']);
            $out['makers']    = array_keys($mk); sort($out['makers']);
            $out['years']     = array_keys($yr); rsort($out['years']);
        } catch (Throwable $e) {}
        return $out;
    }
}
