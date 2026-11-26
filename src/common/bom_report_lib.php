<?php
/**
 * bom_report_lib.php — BOM(製令)「訂單要求檢附的報告」判定（唯一實作）
 *
 * 來源：訂單追蹤「是否檢附報告」(order_report_sel，唯一寫入 order_report_lib.php)；
 *   BOM 綁定的訂單＝bom_order_process_map（多對多），沒有分配列時退回 bom.o_order_id。
 *   一張 BOM 要附的報告＝它綁定的所有訂單所勾報告項目的聯集（同一項目只算一次）。
 * 判定「有沒有報告」：ERP/資材報告資料夾裡有沒有「BOM編號＋該項目綁定的檔名後綴」的檔案
 *   （與 part_viewer 的檔名標籤同一套 eg_bom_tag_seq）。項目沒設定後綴者無法自動判斷，
 *   一律視為「無法判定」＝不擋、不置頂，只在清單上註明。
 * 關卡：項目有綁製程大類時，該 BOM 該大類的任一製程到達「生管待移轉(P)／已移轉(E)」才算「到相關關卡」，
 *   這時還沒有報告就標示（置頂＋淺藍底＋缺哪份）；沒綁大類者沒有對應關卡，只在完工/結案/出貨時看。
 * 完全不碰資料表結構；沒有任何訂單勾報告的 BOM 一律回空（現場畫面零差異）。
 */
require_once __DIR__ . '/order_report_lib.php';
require_once __DIR__ . '/bom_dir_lib.php';

/** 這些 BOM 各自要附哪些報告＋有沒有＋是否到關卡。回傳 [bom => [...]]；沒有要求者不出現 */
function brp_required_map(PDO $db, array $boms): array
{
    $boms = array_values(array_unique(array_filter(array_map('strval', $boms), function ($b) { return $b !== ''; })));
    if (!$boms) return [];
    orp_ensure_schema($db);
    $ph = implode(',', array_fill(0, count($boms), '?'));

    // 1) BOM → 訂單
    $orders = [];   // bom => [order_id…]
    try {
        $st = $db->prepare("SELECT bom, order_id FROM bom_order_process_map WHERE bom IN ($ph)");
        $st->execute($boms);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $orders[$r['bom']][(int)$r['order_id']] = 1;
        $st = $db->prepare("SELECT bom, o_order_id FROM bom WHERE bom IN ($ph) AND o_order_id REGEXP '^[0-9]+$'");
        $st->execute($boms);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { if (empty($orders[$r['bom']])) $orders[$r['bom']][(int)$r['o_order_id']] = 1; }
    } catch (Throwable $e) { return []; }
    $allOrders = [];
    foreach ($orders as $o) foreach ($o as $oid => $_) $allOrders[$oid] = 1;
    if (!$allOrders) return [];

    // 2) 訂單 → 勾選的報告項目（現行設定的後綴／大類；項目已被刪除則用快照名稱、無後綴）
    $sel = [];
    try {
        $oph = implode(',', array_fill(0, count($allOrders), '?'));
        $st = $db->prepare("SELECT s.order_id, s.opt_id, COALESCE(o.opt_name, s.opt_name) AS opt_name, o.tag_suffix, COALESCE(o.process_type_id, s.process_type_id) AS ptype
                            FROM order_report_sel s LEFT JOIN order_report_opt o ON o.opt_id = s.opt_id
                            WHERE s.opt_id > 0 AND s.order_id IN ($oph)");
        $st->execute(array_keys($allOrders));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $sel[(int)$r['order_id']][(int)$r['opt_id']] = $r;
    } catch (Throwable $e) { return []; }

    $need = [];   // bom => opt_id => row
    foreach ($orders as $bom => $oset) {
        foreach ($oset as $oid => $_) foreach (($sel[$oid] ?? []) as $optId => $r) $need[$bom][$optId] = $r;
    }
    if (!$need) return [];

    // 3) 各 BOM 各製程大類是否已到「待移轉/已移轉」
    $reached = [];   // bom => ptype => true
    try {
        $nb = array_keys($need);
        $bph = implode(',', array_fill(0, count($nb), '?'));
        $st = $db->prepare("SELECT bi.bom, pn.process_type_id AS ptype FROM bom_ing bi JOIN process_no pn ON pn.ProcessNo = bi.process_no
                            WHERE bi.bom IN ($bph) AND bi.is_schedule_split = 0 AND bi.processing_state IN ('P','E') AND pn.process_type_id IS NOT NULL");
        $st->execute($nb);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $reached[$r['bom']][(int)$r['ptype']] = true;
    } catch (Throwable $e) {}

    // 4) 檔案判定
    $dirFs = eg_bom_erp_scan_dir_auto();
    $dirOk = is_dir($dirFs);
    $out = [];
    foreach ($need as $bom => $items) {
        $files = [];
        if ($dirOk) {
            foreach (glob($dirFs . $bom . '*') ?: [] as $full) {
                if (is_file($full)) $files[] = ['name' => eg_bom_name_utf8(basename($full)), 'mtime' => (int)@filemtime($full)];
            }
        }
        $reports = []; $flag = []; $miss = [];
        foreach ($items as $optId => $r) {
            $suffix = trim((string)($r['tag_suffix'] ?? ''));
            $ptype = $r['ptype'] === null || $r['ptype'] === '' ? null : (int)$r['ptype'];
            $has = null; $file = null;
            if ($suffix !== '' && $dirOk) {
                $has = false;
                foreach ($files as $f) {
                    if (eg_bom_tag_seq($f['name'], $bom . $suffix) !== null && (!$file || $f['mtime'] > $file['mtime'])) $file = $f;
                }
                if ($file) $has = true;
            }
            $stage = $ptype !== null && !empty($reached[$bom][$ptype]);
            $reports[] = ['opt_id' => (int)$optId, 'name' => (string)$r['opt_name'], 'suffix' => $suffix, 'ptype' => $ptype,
                          'has' => $has, 'file' => $file ? $file['name'] : null, 'stage' => $stage];
            if ($has === false) {
                $miss[] = (string)$r['opt_name'];
                if ($stage) $flag[] = (string)$r['opt_name'];
            }
        }
        usort($reports, function ($a, $b) { return $a['opt_id'] <=> $b['opt_id']; });
        $out[$bom] = ['reports' => $reports, 'flag' => $flag, 'missing' => $miss];
    }
    return $out;
}

/** BOM 總表用：進行中(未結案)BOM 中有勾報告者 */
function brp_open_map(PDO $db): array
{
    orp_ensure_schema($db);
    try {
        $boms = $db->query("SELECT DISTINCT b.bom FROM bom b
              JOIN order_report_sel s ON s.opt_id > 0
              LEFT JOIN bom_order_process_map m ON m.bom = b.bom
              WHERE b.processing_state IS NULL
                AND (s.order_id = m.order_id OR (m.bom IS NULL AND b.o_order_id REGEXP '^[0-9]+$' AND s.order_id = CAST(b.o_order_id AS UNSIGNED)))")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
    return brp_required_map($db, $boms);
}

/** 完工／結案檢查：缺哪些報告名稱（只算可自動判定且確實沒有檔案者） */
function brp_close_missing(PDO $db, string $bom): array
{
    $m = brp_required_map($db, [$bom]);
    return $m[$bom]['missing'] ?? [];
}
