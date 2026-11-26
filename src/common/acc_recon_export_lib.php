<?php
/**
 * 對帳底稿匯出（應收）— 版面比照 ERP 客戶對帳單（FOR CODEING 說明文件/會計/2G001 德士 115.11.xls）：
 * 公司抬頭、客戶資料、送貨地址、明細（日期／單號／品名規格／數量／單位／單價／金額／發票資訊）、合計。
 * 負數金額（退貨）紅字加粗。單號只印在每張單的第一列。
 */

function acc_roc_date(?string $d): string
{
    if (!$d || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return (string)$d;
    return sprintf('%d/%s/%s', (int)$m[1] - 1911, $m[2], $m[3]);
}

/** 客戶送貨地址：先用「送貨地址」類型的地址，沒有就用客戶地址，再沒有用發票地址。回 [地址文字, 電話] */
function acc_recon_party_address(PDO $db, array $cust): array
{
    $cid = trim((string)($cust['customer_id'] ?? ''));
    $tel = trim((string)($cust['customer_tel'] ?? ''));
    $fmt = function (string $zip, string $addr, string $contact): string {
        $t = trim(trim($zip) . ' ' . trim($addr));
        if (trim($contact) !== '') $t .= '  ' . trim($contact) . '收';
        return $t;
    };
    if ($cid !== '') {
        try {
            $q = $db->prepare("SELECT addr_type_id, zip, address, tel, contact FROM party_address
                               WHERE party_type='customer' AND party_id=? AND TRIM(COALESCE(address,''))<>''
                               ORDER BY (addr_type_id=2) DESC, sort_order, addr_id");
            $q->execute([$cid]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) if ((int)$r['addr_type_id'] === 2)
                return [$fmt((string)$r['zip'], (string)$r['address'], (string)$r['contact']), trim((string)$r['tel']) !== '' ? trim((string)$r['tel']) : $tel];
        } catch (Throwable $e) { $rows = []; }
    }
    $addr = trim((string)($cust['customer_address'] ?? ''));
    if ($addr !== '') {
        $zip = '';
        try { $z = $db->prepare("SELECT customer_zip FROM customer_list WHERE customer_id=?"); $z->execute([$cid]); $zip = (string)$z->fetchColumn(); } catch (Throwable $e) {}
        return [$fmt($zip, $addr, ''), $tel];
    }
    foreach ($rows ?? [] as $r) if ((int)$r['addr_type_id'] === 1)
        return [$fmt((string)$r['zip'], (string)$r['address'], (string)$r['contact']), $tel];
    return ['', $tel];
}

/** 產生並輸出 xlsx（直接 exit） */
function acc_sheet_export_xlsx(PDO $db, array $sheet, string $bm): void
{
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/acc_lib.php';

    $own = $db->query("SELECT customer_full, customer, customer_address, customer_tel, customer_fax
                       FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $cust = acc_customer_by_name($db)[trim((string)$sheet['party_id'])] ?? [];
    [$shipAddr, $shipTel] = acc_recon_party_address($db, $cust);
    $inv = acc_invoiced_src_map($db);

    $lines = array_values(array_filter($sheet['lines'], fn($l) => !empty($l['counts_in_total'])));
    $dmin = ''; $dmax = '';
    foreach ($lines as $l) {
        $d = (string)$l['doc_date'];
        if ($d === '') continue;
        if ($dmin === '' || $d < $dmin) $dmin = $d;
        if ($dmax === '' || $d > $dmax) $dmax = $d;
    }

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $ss->getActiveSheet();
    $ws->setTitle('工作表1');
    $ss->getDefaultStyle()->getFont()->setName('新細明體')->setSize(9);

    $put = function (string $a, $v) use ($ws) { $ws->setCellValueExplicit($a, (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); };
    $ctr = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;

    $ws->mergeCells('A2:H2'); $put('A2', $own['customer_full'] ?? '');
    $ws->mergeCells('A3:H3'); $put('A3', '地址：' . ($own['customer_address'] ?? '') . ' 電話：' . ($own['customer_tel'] ?? '') . ' 傳真：' . ($own['customer_fax'] ?? ''));
    $ws->mergeCells('A4:H4'); $put('A4', '客戶對帳單');
    $ws->getStyle('A2:H2')->getFont()->setSize(16);
    $ws->getStyle('A4:H4')->getFont()->setSize(16);
    $ws->getStyle('A2:H4')->getAlignment()->setHorizontal($ctr);

    $put('A5', '印表日期：'); $put('B5', acc_roc_date(date('Y-m-d')));
    $put('D5', '表別： 明細表  日期別：單據日期 客戶別'); $put('H5', '頁次： 1');
    $put('A6', '日期區間：' . acc_roc_date($dmin) . ' ～ ' . acc_roc_date($dmax));
    $put('A7', '客戶編號：'); $put('B7', $cust['customer_id'] ?? ''); $put('C7', $cust['customer_full'] ?? $sheet['party_name']);
    $put('D7', '統一編號：' . ($cust['tax_id'] ?? '')); $put('G7', '傳真號碼：'); $put('H7', $cust['customer_fax'] ?? '');
    $put('A8', '送貨地址：'); $put('B8', $shipAddr); $put('G8', '聯絡電話：'); $put('H8', $shipTel);

    foreach (['日期', '單號', '品名規格', '數量', '單位', '單價', '金額', '發票資訊'] as $i => $h) $put(chr(65 + $i) . '9', $h);
    $ws->getStyle('A9:H9')->getAlignment()->setHorizontal($ctr);

    $r = 10; $lastNo = null; $red = ['font' => ['bold' => true, 'color' => ['rgb' => 'FF0000']]];
    $sumNet = 0.0;
    foreach ($lines as $l) {
        $qty = (float)$l['eff_qty']; $price = (float)$l['eff_price']; $amt = (float)$l['eff_amount'];
        $isRet = strtoupper((string)$l['src_type']) === 'IR';
        if ($isRet && $qty > 0) $qty = -$qty;
        if ($isRet && $amt > 0) $amt = -$amt;
        $sumNet += $amt;
        $pid = trim((string)$l['product_id']); $spec = trim((string)$l['spec']);
        $name = ($pid !== '' && stripos($spec, $pid) !== 0) ? $pid . $spec : ($spec !== '' ? $spec : $pid);
        $no = (string)$l['doc_no'];
        $put('A' . $r, acc_roc_date((string)$l['doc_date']));
        $put('B' . $r, $no !== $lastNo ? $no : '');
        $put('C' . $r, $name);
        $ws->setCellValue('D' . $r, $qty);
        $put('E' . $r, 'PCS');
        $ws->setCellValue('F' . $r, $price);
        $ws->setCellValue('G' . $r, $amt);
        $ik = strtoupper((string)$l['src_type']) . '-' . (int)$l['src_id'];
        $put('H' . $r, $inv[$ik]['invoice_no'] ?? '');
        if ($amt < 0) $ws->getStyle("A$r:H$r")->applyFromArray($red);
        $lastNo = $no;
        $r++;
    }
    $last = $r - 1;
    $ws->getStyle("D10:D$last")->getNumberFormat()->setFormatCode('#,##0');
    $ws->getStyle("F10:G$last")->getNumberFormat()->setFormatCode('#,##0.0###');
    $ws->getStyle("A10:B$last")->getAlignment()->setHorizontal($ctr);
    $ws->getStyle("E10:E$last")->getAlignment()->setHorizontal($ctr);
    $ws->getStyle("G10:G$last")->getAlignment()->setHorizontal($ctr);
    $ws->getStyle("A9:H$last")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

    $tax = (float)$sheet['tax_amount']; $tot = (float)$sheet['total_amount'];
    $our = (float)$sheet['our_total'];
    $f = $last + 1;
    $put("A$f", '累計預收金額：'); $ws->setCellValue("C$f", 0);
    $put("E$f", '本期應收合計：'); $ws->setCellValue("G$f", $our);
    $put('E' . ($f + 1), '(+) 營業稅額：'); $ws->setCellValue('G' . ($f + 1), $tax);
    $put('E' . ($f + 2), '本期應收款金額：'); $ws->setCellValue('G' . ($f + 2), $tot);
    $ws->getStyle("G$f:G" . ($f + 2))->getNumberFormat()->setFormatCode('#,##0');
    $ws->getStyle("A$f:H" . ($f + 2))->getFont()->setSize(10);
    if ($our < 0) $ws->getStyle("G$f")->applyFromArray($red);
    if ($tot < 0) $ws->getStyle('G' . ($f + 2))->applyFromArray($red);

    foreach (['A' => 11, 'B' => 14, 'C' => 46, 'D' => 8, 'E' => 14, 'F' => 9, 'G' => 11, 'H' => 14] as $c => $w) $ws->getColumnDimension($c)->setWidth($w);
    $ws->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
    $ws->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(9, 9);

    $fn = '對帳底稿_' . $sheet['party_name'] . '_' . $bm . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"" . rawurlencode($fn) . "\"; filename*=UTF-8''" . rawurlencode($fn));
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}
