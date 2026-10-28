<?php
/**
 * AS 文件流程圖（烏龜圖）的「綁定」：程序書／表單／部門／KPI 區塊（唯一實作）。
 *
 * 流程圖的方框文字是畫布物件不是 HTML，所以綁定記在物件自己的 egBind={kind,head,ids}，
 * 方框裡顯示的字一律由這裡依「資料庫現況」組出（改名、換部門名稱後，下次打開自動跟著換）。
 *  - proc ：AS 程序書（doc_type=程序）　顯示文件名稱，**不顯示編號**
 *  - form ：AS 表單（doc_type=表單）　　顯示文件名稱，**不顯示編號**
 *  - dept ：部門　綁定下層部門（課以下）時，上面那個「課」也一併寫出（資材課 生管組）
 *  - kpi  ：KPI 方案（views/news/KPI_new.php）的區塊，顯示該區塊底下啟用中的指標名稱
 */

/** 全部部門（含上層，前端挑選與組顯示字用） */
function afb_depts(PDO $db): array {
    $rows = $db->query("SELECT id, name, parent_id, level FROM department ORDER BY sort_order, id")
               ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['parent_id'] = $r['parent_id'] === null ? null : (int)$r['parent_id'];
        $r['level'] = (int)$r['level'];
    }
    unset($r);
    return $rows;
}

/** 部門顯示字：上層是「課」(level>=3) 才一併寫出，總經理室／董事長室不寫（那是全公司共同的上層） */
function afb_dept_line(array $d, array $byId): string {
    $p = $d['parent_id'] !== null ? ($byId[$d['parent_id']] ?? null) : null;
    if ($p && (int)$p['level'] >= 3) return $p['name'] . ' ' . $d['name'];
    return (string)$d['name'];
}

/** KPI 區塊：[{code,name,items:[指標名稱…]}]（只列有啟用指標的區塊） */
function afb_kpi_blocks(PDO $db): array {
    require_once __DIR__ . '/kpi_scheme_lib.php';
    $items = [];
    foreach ($db->query("SELECT block, name FROM kpi_scheme_indicator WHERE is_active=1
                         ORDER BY sort_order, indicator_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[(string)$r['block']][] = (string)$r['name'];
    }
    $out = [];
    foreach (kpi_scheme_blocks() as $code => $b) {
        $out[] = ['code' => $code, 'name' => (string)$b['name'], 'items' => $items[$code] ?? []];
    }
    return $out;
}

/**
 * 依資料庫現況組出每個綁定對象要顯示的文字行。
 * @return array<string,string[]>  id => 行陣列（查不到的 id 不會出現＝已被刪除）
 */
function afb_resolve(PDO $db, string $kind, array $ids): array {
    $ids = array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 100);
    $out = [];
    if (!$ids) return $out;
    if ($kind === 'proc' || $kind === 'form') {
        $want = $kind === 'proc' ? '程序' : '表單';
        $in = implode(',', array_map('intval', $ids));
        foreach ($db->query("SELECT id, doc_name FROM as_document
                             WHERE is_deleted=0 AND doc_type='$want' AND id IN ($in)")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['id']] = [(string)$r['doc_name']];
        }
    } elseif ($kind === 'dept') {
        $all = afb_depts($db);
        $by = [];
        foreach ($all as $d) $by[$d['id']] = $d;
        foreach ($ids as $id) {
            if (isset($by[(int)$id])) $out[$id] = [afb_dept_line($by[(int)$id], $by)];
        }
    } elseif ($kind === 'kpi') {
        foreach (afb_kpi_blocks($db) as $b) {
            if (in_array($b['code'], $ids, true)) $out[$b['code']] = $b['items'];
        }
    }
    return $out;
}
