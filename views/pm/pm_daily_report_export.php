<?php
// pm_daily_report_export.php
// 生管每日報表匯出（OreadyReply_ForPm_BaseOfTime2.php 的附屬端點，由頁面上「生管每日報表」按鈕觸發）
//
// 分頁規則：
//   1. 第一個分頁「QC待驗逾2天」：已回廠 (bom_ing.processing_state='Q') 且回廠日距今 >= 2 天仍未檢驗
//   2. 其餘分頁：未回廠 (bom_ing.processing_state='ing')，依廠商名稱一廠商一分頁
// 欄位：訂單編號、交期x數量、BOM號碼、料號、數量、製程、製程數量、製程狀態、
//       狀態維持天數、BOM/製程備註、報工狀況與備註、製程1..N（完整製程鏈，目前製程淺藍底色）
// 檔名：生管日報表-YYYYMMDD-HHMM.xlsx

session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pm/OreadyReply_ForPm_BaseOfTime.php";
    header("Location:../../index.php");
    exit();
}
session_write_close();

ini_set('memory_limit', '512M');
date_default_timezone_set('Asia/Taipei'); // 檔名時間用本地時區（PHP 預設 UTC 會差 8 小時）

require '../../vendor/autoload.php';
include '../../src/common/DBConnection.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$conn = new DBConnection();
$db   = $conn->getPDO();

// ─────────────────────────────────────────────────────────────
// 1. 撈出所有進行中 BOM 的全部製程（含各狀態，供製程鏈顯示）
//    範圍條件與主頁一致：b.d_id <> '' AND b.processing_state IS NULL
// ─────────────────────────────────────────────────────────────
$sql_main = "SELECT
    b.bom,
    b.d_id,
    COALESCE(ds.D_Setting_Id, b.d_id, '')          AS d_display,
    b.sqty                                         AS bom_qty,
    b.bom_ps,
    b.Client_Name,
    DATE_FORMAT(b.Delivery_date, '%Y/%m/%d')       AS bom_delivery,
    b.o_order_id,
    bi.bom_ing_fid,
    bi.bom_sn,
    bi.batch_label,
    bi.sqty                                        AS process_qty,
    bi.processing_state,
    bi.ps                                          AS process_ps,
    bi.single_bet_ps,
    DATE_FORMAT(bi.outsource_date, '%Y/%m/%d')     AS outsource_date,
    DATE_FORMAT(bi.return_date, '%Y/%m/%d')        AS return_date,
    DATEDIFF(NOW(), bi.outsource_date)             AS ing_days,
    DATEDIFF(NOW(), bi.return_date)                AS return_days,
    COALESCE(pn.ProcessName, CONCAT('製程', bi.process_no)) AS ProcessName,
    COALESCE(NULLIF(TRIM(ml.maker_id), ''), '未指定廠商')     AS maker_name
FROM bom b
JOIN bom_ing bi        ON bi.bom = b.bom AND bi.is_schedule_split = 0 AND bi.is_consumed = 0
LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no
WHERE b.d_id <> '' AND b.processing_state IS NULL
ORDER BY b.bom, bi.bom_sn, bi.batch_label";

$all_rows = $db->query($sql_main)->fetchAll(PDO::FETCH_ASSOC);

// ─────────────────────────────────────────────────────────────
// 2. 綁定訂單資訊（bom_order_process_map → order_track）
// ─────────────────────────────────────────────────────────────
$sql_orders = "SELECT
    bopm.bom,
    ot.Order_id,
    ot.Order_oo,
    DATE_FORMAT(ot.Delivery_date, '%Y/%m/%d') AS dd,
    ot.Qty
FROM bom_order_process_map bopm
JOIN order_track ot ON ot.Order_id = bopm.order_id
JOIN bom b ON b.bom = bopm.bom
WHERE b.d_id <> '' AND b.processing_state IS NULL
ORDER BY bopm.bom, ot.Delivery_date, ot.Order_id";

$orders_map = []; // bom => [ ['oo'=>, 'dd'=>, 'qty'=>], ... ]
foreach ($db->query($sql_orders)->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $orders_map[$o['bom']][] = [
        'oo'  => $o['Order_oo'] !== '' ? $o['Order_oo'] : (string)$o['Order_id'],
        'dd'  => $o['dd'] ?: '',
        'qty' => $o['Qty'],
    ];
}

// 未綁定 map 的 BOM，fallback 用 bom.o_order_id 找 order_track
$fallback_ids = [];
foreach ($all_rows as $r) {
    if (!isset($orders_map[$r['bom']]) && $r['o_order_id'] !== null && $r['o_order_id'] !== ''
        && ctype_digit((string)$r['o_order_id'])) {
        $fallback_ids[(string)$r['o_order_id']] = true;
    }
}
$fallback_orders = []; // Order_id => info
if (!empty($fallback_ids)) {
    $ids_in = implode(',', array_map('intval', array_keys($fallback_ids)));
    $sql_fb = "SELECT Order_id, Order_oo, DATE_FORMAT(Delivery_date, '%Y/%m/%d') AS dd, Qty
               FROM order_track WHERE Order_id IN ($ids_in)";
    foreach ($db->query($sql_fb)->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $fallback_orders[(string)$o['Order_id']] = [
            'oo'  => $o['Order_oo'] !== '' ? $o['Order_oo'] : (string)$o['Order_id'],
            'dd'  => $o['dd'] ?: '',
            'qty' => $o['Qty'],
        ];
    }
}

// ─────────────────────────────────────────────────────────────
// 3. 報工彙總（pm_process_daily_report，以 bom_ing_fid 為單位）
// ─────────────────────────────────────────────────────────────
$sql_report = "SELECT
    r.bom_ing_fid,
    SUM(COALESCE(r.produced_qty, 0)) AS total_qty,
    DATE_FORMAT(MAX(r.report_date), '%Y/%m/%d') AS last_date,
    MAX(r.is_finished) AS any_finished,
    SUBSTRING_INDEX(
        GROUP_CONCAT(NULLIF(TRIM(r.remark), '') ORDER BY r.report_date DESC, r.report_id DESC SEPARATOR '｜'),
        '｜', 3
    ) AS remarks
FROM pm_process_daily_report r
JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
JOIN bom b      ON b.bom = bi.bom
WHERE b.d_id <> '' AND b.processing_state IS NULL
GROUP BY r.bom_ing_fid";

$report_map = []; // bom_ing_fid => info
foreach ($db->query($sql_report)->fetchAll(PDO::FETCH_ASSOC) as $rp) {
    $report_map[$rp['bom_ing_fid']] = $rp;
}

// ─────────────────────────────────────────────────────────────
// 4. 建立每個 BOM 的製程鏈（依 bom_sn 排序；同 sn 多批次取狀態代表）
// ─────────────────────────────────────────────────────────────
$state_priority = ['ing' => 5, 'Q' => 4, 'P' => 3, 'N' => 2, 'E' => 1, 'skip' => 0];
$state_text     = ['ing' => '未回廠(加工中)', 'Q' => 'QC待驗', 'P' => '生管待移轉', 'N' => '新建未發', 'E' => '已移轉', 'skip' => '跳過'];

$chain_map = []; // bom => [bom_sn => ['name'=>, 'state'=>]]
foreach ($all_rows as $r) {
    $b  = $r['bom'];
    $sn = (int)$r['bom_sn'];
    $st = trim((string)$r['processing_state']);
    if (!isset($chain_map[$b][$sn])) {
        $chain_map[$b][$sn] = ['name' => $r['ProcessName'], 'state' => $st];
    } else {
        $cur = $chain_map[$b][$sn]['state'];
        if (($state_priority[$st] ?? 0) > ($state_priority[$cur] ?? 0)) {
            $chain_map[$b][$sn]['state'] = $st;
        }
    }
}
foreach ($chain_map as $b => $list) {
    ksort($chain_map[$b]);
}

// ─────────────────────────────────────────────────────────────
// 5. 分派報表列：QC逾2天 / 各廠商未回廠
//    依網頁「目前製程」邏輯：每個 BOM 只看發單日(outsource_date)最新的那一批製程，
//    用該批的狀態分類（流程不強制每關檢驗完才能發下一關，舊關卡在 Q 不代表目前狀態）
// ─────────────────────────────────────────────────────────────
$max_out_date = []; // bom => 最新發單日 (Y/m/d 字串，零補齊可直接比大小)
foreach ($all_rows as $r) {
    $st = trim((string)$r['processing_state']);
    if (!in_array($st, ['Q', 'P', 'ing', 'E'], true) || empty($r['outsource_date'])) continue;
    $b = $r['bom'];
    if (!isset($max_out_date[$b]) || $r['outsource_date'] > $max_out_date[$b]) {
        $max_out_date[$b] = $r['outsource_date'];
    }
}

$qc_rows     = [];
$vendor_rows = []; // maker_name => rows

foreach ($all_rows as $r) {
    $b = $r['bom'];
    // 只取「目前製程」＝發單日等於該 BOM 最新發單日的製程
    if (!isset($max_out_date[$b]) || empty($r['outsource_date']) || $r['outsource_date'] !== $max_out_date[$b]) {
        continue;
    }
    $st = trim((string)$r['processing_state']);
    if ($st === 'Q' && $r['return_days'] !== null && (int)$r['return_days'] >= 2) {
        $r['hold_days'] = (int)$r['return_days']; // 回廠後放置天數
        $qc_rows[] = $r;
    } elseif ($st === 'ing') {
        $r['hold_days'] = ($r['ing_days'] !== null) ? (int)$r['ing_days'] : null; // 發廠商至今天數
        $vendor_rows[$r['maker_name']][] = $r;
    }
}
ksort($vendor_rows);

// 放置/維持越久排越前面
usort($qc_rows, function ($a, $b) { return ($b['hold_days'] ?? 0) <=> ($a['hold_days'] ?? 0); });
foreach ($vendor_rows as $mk => &$rows_ref) {
    usort($rows_ref, function ($a, $b) { return ($b['hold_days'] ?? 0) <=> ($a['hold_days'] ?? 0); });
}
unset($rows_ref);

// ─────────────────────────────────────────────────────────────
// 6. 產生 Excel
// ─────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('EGsystem')
    ->setTitle('生管日報表');

$fixed_headers = ['訂單編號', '交期x數量', 'BOM號碼', '料號', '數量', '製程', '製程數量',
                  '製程狀態', '狀態維持天數', 'BOM/製程備註', '報工狀況與備註'];
$fixed_count   = count($fixed_headers);
$light_blue    = 'FFADD8E6'; // 目前製程淺藍底色
$header_fill   = 'FFD9D9D9';

// 訂單編號 / 交期x數量 兩欄的顯示內容
$order_display = function ($r) use ($orders_map, $fallback_orders) {
    $b = $r['bom'];
    if (isset($orders_map[$b])) {
        $oos = [];
        $dds = [];
        foreach ($orders_map[$b] as $o) {
            $oos[] = $o['oo'];
            $dds[] = $o['dd'] . ' x ' . $o['qty'];
        }
        return [implode("\n", array_unique($oos)), implode("\n", $dds)];
    }
    $oid = (string)($r['o_order_id'] ?? '');
    if ($oid !== '' && isset($fallback_orders[$oid])) {
        $o = $fallback_orders[$oid];
        return [$o['oo'], $o['dd'] . ' x ' . $o['qty']];
    }
    if ($oid === 'B') $oid = '備庫';
    if ($oid === 'R') $oid = '訂單重製';
    // 無訂單資料時 fallback 用 BOM 交期 x BOM 數量
    $dd = $r['bom_delivery'] ? ($r['bom_delivery'] . ' x ' . $r['bom_qty']) : '';
    return [$oid, $dd];
};

// 報工狀況與備註
$report_display = function ($r) use ($report_map) {
    $fid = $r['bom_ing_fid'];
    if (!isset($report_map[$fid])) return ''; // 無報工留空
    $rp    = $report_map[$fid];
    $parts = ['已報工 ' . (int)$rp['total_qty'] . ' / ' . (int)$r['process_qty']];
    if (!empty($rp['last_date']))    $parts[] = '最後 ' . $rp['last_date'];
    if ((int)$rp['any_finished'] >= 1) $parts[] = '已完工';
    $txt = implode('，', $parts);
    if (!empty($rp['remarks'])) $txt .= "\n備註：" . $rp['remarks'];
    return $txt;
};

// BOM/製程備註
$ps_display = function ($r) {
    $parts = [];
    if (trim((string)$r['bom_ps']) !== '')        $parts[] = 'BOM：' . trim($r['bom_ps']);
    if (trim((string)$r['process_ps']) !== '')    $parts[] = '製程：' . trim($r['process_ps']);
    if (trim((string)$r['single_bet_ps']) !== '') $parts[] = '單關：' . trim($r['single_bet_ps']);
    return implode("\n", $parts);
};

// 分頁名稱清洗（Excel 限制：<=31字、不可含 [ ] : * ? / \）
$used_titles    = [];
$sanitize_title = function ($t) use (&$used_titles) {
    $t = preg_replace('/[\[\]\:\*\?\/\\\\]/u', '', (string)$t);
    $t = trim($t) === '' ? '未命名' : trim($t);
    if (mb_strlen($t, 'UTF-8') > 25) $t = mb_substr($t, 0, 25, 'UTF-8');
    $base = $t;
    $i = 2;
    while (isset($used_titles[$t])) {
        $t = $base . '(' . $i . ')';
        $i++;
    }
    $used_titles[$t] = true;
    return $t;
};

// 填一個分頁
$fill_sheet = function ($sheet, $rows, $days_header_note) use (
    $fixed_headers, $fixed_count, $light_blue, $header_fill, $chain_map,
    $state_text, $order_display, $report_display, $ps_display
) {
    // 此分頁製程鏈最大長度
    $max_chain = 0;
    foreach ($rows as $r) {
        $max_chain = max($max_chain, count($chain_map[$r['bom']] ?? []));
    }

    // 表頭
    $headers = $fixed_headers;
    $headers[8] = $days_header_note; // 狀態維持天數欄位標題（依分頁性質標註）
    for ($i = 1; $i <= $max_chain; $i++) $headers[] = '製程' . $i;
    $total_cols = count($headers);

    foreach ($headers as $idx => $h) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($idx + 1) . '1', $h);
    }
    $last_col = Coordinate::stringFromColumnIndex(max($total_cols, 1));
    $sheet->getStyle('A1:' . $last_col . '1')->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $header_fill]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->freezePane('A2');

    if (empty($rows)) {
        $sheet->setCellValue('A2', '（無符合資料）');
        return;
    }

    $row_no = 2;
    foreach ($rows as $r) {
        list($oo_txt, $dd_txt) = $order_display($r);
        $st    = trim((string)$r['processing_state']);
        $chain = $chain_map[$r['bom']] ?? [];

        $values = [
            $oo_txt,
            $dd_txt,
            $r['bom'],
            $r['d_display'],
            $r['bom_qty'],
            $r['ProcessName'] . ($r['batch_label'] ? '(' . $r['batch_label'] . '批)' : ''),
            $r['process_qty'],
            // 製程狀態附上廠商名稱，方便生管直接截圖給廠商
            ($state_text[$st] ?? $st) . (($r['maker_name'] !== '' && $r['maker_name'] !== '未指定廠商') ? '-' . $r['maker_name'] : ''),
            ($r['hold_days'] !== null) ? ($r['hold_days'] . '天') : '',
            $ps_display($r),
            $report_display($r),
        ];

        foreach ($values as $idx => $v) {
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($idx + 1) . $row_no,
                (string)$v,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        }

        // 製程鏈：之前、目前、剩餘製程全部列出（只顯示製程名稱）；本列所屬製程 → 淺藍底色
        $col = $fixed_count + 1;
        $current_sn = (int)$r['bom_sn'];
        foreach ($chain as $sn => $p) {
            $cell = Coordinate::stringFromColumnIndex($col) . $row_no;
            $sheet->setCellValue($cell, $p['name']);
            if ((int)$sn === $current_sn) {
                $sheet->getStyle($cell)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB($light_blue);
            }
            $col++;
        }
        $row_no++;
    }

    // 第一列做成篩選功能
    $sheet->setAutoFilter('A1:' . $last_col . ($row_no - 1));

    // 樣式：框線 + 換行 + 垂直置中
    $range = 'A1:' . $last_col . ($row_no - 1);
    $sheet->getStyle($range)->applyFromArray([
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBBBBBB']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);

    // 欄寬
    $widths = [14, 16, 13, 16, 8, 14, 9, 14, 12, 28, 30];
    foreach ($widths as $idx => $w) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($idx + 1))->setWidth($w);
    }
    for ($i = $fixed_count + 1; $i <= $total_cols; $i++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(13);
    }
};

// 第一分頁：QC 待驗逾 2 天
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle($sanitize_title('QC待驗逾2天'));
$fill_sheet($sheet, $qc_rows, '放置天數(回廠起)');

// 各廠商分頁：未回廠 (ing)
foreach ($vendor_rows as $maker => $rows) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($sanitize_title($maker));
    $fill_sheet($sheet, $rows, '發單至今天數(含假日)');
}

$spreadsheet->setActiveSheetIndex(0);

// ─────────────────────────────────────────────────────────────
// 7. 輸出下載
// ─────────────────────────────────────────────────────────────
$filename = '生管日報表-' . date('Ymd') . '-' . date('Hi') . '.xlsx';

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="pm_daily_report.xlsx"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
