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
    foreach ($db->query("SELECT indicator_id, block, name FROM kpi_scheme_indicator WHERE is_active=1
                         ORDER BY sort_order, indicator_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[(string)$r['block']][] = ['id' => 'k' . $r['indicator_id'], 'name' => (string)$r['name']];
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
        // id 有兩種：'k123'＝單一指標；'COP01'＝整個區塊（舊版綁定，等於區塊底下全部指標）
        $byCode = []; $byItem = [];
        foreach (afb_kpi_blocks($db) as $b) {
            $byCode[$b['code']] = $b;
            foreach ($b['items'] as $it) $byItem[$it['id']] = $it['name'];
        }
        foreach ($ids as $id) {
            if (isset($byItem[$id])) $out[$id] = [$byItem[$id]];
            elseif (isset($byCode[$id])) $out[$id] = array_column($byCode[$id]['items'], 'name');
        }
    }
    return $out;
}

/** 與前端 bindText() 同一套：標題＋編號清單 */
function afb_text(string $head, array $ids, array $map): string {
    $lines = [];
    foreach ($ids as $id) foreach (($map[(string)$id] ?? []) as $l) $lines[] = $l;
    $rows = [];
    foreach ($lines as $i => $l) $rows[] = ($i + 1) . '. ' . $l;
    $body = implode("\n", $rows);
    return $head !== '' ? ($body !== '' ? $head . "\n" . $body : $head) : $body;
}

/**
 * 一張流程圖工作檔(fabric JSON)裡，有幾個綁定框的來源已經改了（且框內文字沒被手動改過）。
 * 流程圖輸出的是 PNG，後端沒有瀏覽器重畫不出來，所以這裡只「偵測」，更新要開流程圖。
 */
function afb_stale_count(PDO $db, string $json): int {
    $j = json_decode($json, true);
    if (!is_array($j) || empty($j['objects'])) return 0;
    $n = 0;
    $cache = [];
    foreach ($j['objects'] as $o) {
        $b = $o['egBind'] ?? null;
        if (!is_array($b) || empty($b['ids']) || empty($b['kind'])) continue;
        if ((string)($o['text'] ?? '') !== (string)($b['txt'] ?? '')) continue;
        $k = $b['kind'] . '|' . implode(',', $b['ids']);
        if (!isset($cache[$k])) $cache[$k] = afb_resolve($db, (string)$b['kind'], $b['ids']);
        $live = array_values(array_filter($b['ids'], fn($id) => isset($cache[$k][(string)$id])));
        if (afb_text((string)$b['head'], $live, $cache[$k]) !== (string)$b['txt']) $n++;
    }
    return $n;
}


/**
 * KPI 指標被哪些文件的流程圖用了：['k123' => [{doc_id,doc_no,doc_name}…]]
 * 只看每份文件「最新版次」的流程圖工作檔（舊版次不算）；舊版綁整個區塊的，視為用了區塊底下全部指標。
 */
function afb_kpi_use(PDO $db): array {
    require_once __DIR__ . '/as_doc_content_lib.php';
    $blocks = [];
    foreach (afb_kpi_blocks($db) as $b) $blocks[$b['code']] = array_column($b['items'], 'id');
    $use = [];
    try {
        $rows = $db->query("SELECT a.*, c.doc_id, d.doc_no, d.doc_name
                            FROM as_doc_asset a
                            JOIN as_doc_content c ON c.id = a.content_id
                            JOIN as_document d ON d.id = c.doc_id AND d.is_deleted=0
                            WHERE a.kind='flow'
                              AND c.version_id = (SELECT MAX(c2.version_id) FROM as_doc_content c2 WHERE c2.doc_id = c.doc_id)")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    foreach ($rows as $a) {
        $path = adc_flow_path($db, $a);
        if (!$path || !is_file($path)) continue;
        $j = json_decode((string)file_get_contents($path), true);
        foreach (($j['objects'] ?? []) as $o) {
            $b = $o['egBind'] ?? null;
            if (!is_array($b) || ($b['kind'] ?? '') !== 'kpi') continue;
            foreach (($b['ids'] ?? []) as $id) {
                $id = (string)$id;
                foreach (($blocks[$id] ?? [$id]) as $kid) {
                    $use[$kid][(int)$a['doc_id']] = ['doc_id' => (int)$a['doc_id'],
                        'doc_no' => (string)$a['doc_no'], 'doc_name' => (string)$a['doc_name']];
                }
            }
        }
    }
    foreach ($use as $k => $v) $use[$k] = array_values($v);
    return $use;
}
