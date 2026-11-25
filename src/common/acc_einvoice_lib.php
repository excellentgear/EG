<?php
/**
 * 電子發票開立網站匯入檔（發票匯入_未稅 範本）產生器 — 唯一實作。
 *
 * 範本欄位（FOR CODEING 說明文件/發票匯入_未稅_20230217-2.xlsx，第 1 列表頭，共 20 欄）：
 *  訂單編號｜發票日期｜發票類型｜載具｜載具顯碼｜載具隱碼｜捐贈對象｜買方統編｜買方名稱｜買方地址｜
 *  買方電話｜買方電子信箱｜品名｜課稅別｜數量｜單位｜單價(未稅)｜小計金額(未稅)｜商品備註｜總備註
 * 規則：同一張發票只有第 1 列填表頭欄位（發票日期…電子信箱），其餘列只填 訂單編號＋明細欄位；
 *       商品備註＝出貨單號（每張出貨單只在其第一筆明細顯示）。
 */
require_once __DIR__ . '/acc_lib.php';
require_once __DIR__ . '/order_as_tag_lib.php';

/** 開立日期可選範圍：今天往前最多 N 天、往後到「今天所在月份」月底 */
const ACC_INV_BACK_DAYS = 6;

function acc_einv_date_range(?string $today = null): array
{
    $t = new DateTime($today ?: date('Y-m-d'));
    $min = (clone $t)->modify('-' . ACC_INV_BACK_DAYS . ' days')->format('Y-m-d');
    $max = $t->format('Y-m-t');
    return ['min' => $min, 'max' => $max, 'today' => $t->format('Y-m-d'), 'back_days' => ACC_INV_BACK_DAYS];
}

/** 驗證開立日期；不合法回錯誤字串，合法回 null */
function acc_einv_date_check(string $d): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) return '開立日期格式錯誤';
    $r = acc_einv_date_range();
    if ($d < $r['min']) return '開立日期最早只能到 ' . $r['min'] . '（往前最多 ' . ACC_INV_BACK_DAYS . ' 天）';
    if ($d > $r['max']) return '開立日期最晚只能到 ' . $r['max'] . '（本月月底）';
    return null;
}

/** 品名說明文字：全製→齒輪、砂輪→砂輪、刀具→刀具，其餘（單製／多製程…）→齒輪加工 */
function acc_einv_desc_of_tag(?array $tag): string
{
    if (!$tag) return '齒輪加工';
    $lab = (string)($tag['proc_name'] ?? '') . (string)($tag['label'] ?? '');
    if (!empty($tag['is_full']) || mb_strpos($lab, '全製') !== false) return '齒輪';
    if (mb_strpos($lab, '砂輪') !== false) return '砂輪';
    if (mb_strpos($lab, '刀具') !== false) return '刀具';
    return '齒輪加工';
}

/** 會計聯絡人 email（客戶聯絡人設為會計者），沒有則退回客戶發票 email */
function acc_einv_email(PDO $db, string $customerId, array $cust): string
{
    try {
        $st = $db->prepare("SELECT email FROM customer_contacts
                            WHERE customer_id=? AND is_accounting=1 AND email<>''
                            ORDER BY is_primary DESC, sort_order, contact_id LIMIT 1");
        $st->execute([$customerId]);
        $e = trim((string)$st->fetchColumn());
        if ($e !== '') return $e;
    } catch (Throwable $e) {}
    foreach (['invoice_email', 'customer_email'] as $k) {
        $v = trim((string)($cust[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return '';
}

/**
 * 產生匯入列。$ids＝acc_invoice.invoice_id 陣列；$invDate＝YYYY-MM-DD。
 * @return array ['header'=>[...20欄], 'rows'=>[[...20欄]...], 'invoices'=>[摘要...]]
 */
function acc_einv_build(PDO $db, array $ids, string $invDate): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    $header = ['訂單編號', '發票日期', '發票類型', '載具', '載具顯碼', '載具隱碼', '捐贈對象', '買方統編',
               '買方名稱', '買方地址', '買方電話', '買方電子信箱', '品名', '課稅別', '數量', '單位',
               '單價(未稅)', '小計金額(未稅)', '商品備註(最多40個字)', '總備註(最多200個字)'];
    if (!$ids) return ['header' => $header, 'rows' => [], 'invoices' => []];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT * FROM acc_invoice WHERE invoice_id IN ($ph) AND status<>'void'
                        ORDER BY customer_name, invoice_id");
    $st->execute($ids);
    $invs = $st->fetchAll(PDO::FETCH_ASSOC);

    $sti = $db->prepare("SELECT * FROM acc_invoice_item WHERE invoice_id IN ($ph) ORDER BY invoice_id, src_date, src_no, item_id");
    $sti->execute($ids);
    $itemsBy = [];
    $isIds = [];
    foreach ($sti->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $itemsBy[(int)$it['invoice_id']][] = $it;
        if ($it['src_type'] === 'IS') $isIds[(int)$it['src_id']] = 1;
    }

    // 出貨單 → 訂單 → 製程標籤
    $isOrder = [];
    if ($isIds) {
        $k = array_keys($isIds); $p2 = implode(',', array_fill(0, count($k), '?'));
        $q = $db->prepare("SELECT IS_id, Order_id FROM is_list WHERE IS_id IN ($p2)");
        $q->execute($k);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if ((int)$r['Order_id'] > 0) $isOrder[(int)$r['IS_id']] = (int)$r['Order_id'];
        $miss = array_values(array_diff($k, array_keys($isOrder)));
        if ($miss) {
            try {
                $p3 = implode(',', array_fill(0, count($miss), '?'));
                $q = $db->prepare("SELECT IS_id, Order_id FROM shipment_order_map WHERE IS_id IN ($p3) ORDER BY shipped_qty DESC");
                $q->execute($miss);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($isOrder[(int)$r['IS_id']])) $isOrder[(int)$r['IS_id']] = (int)$r['Order_id'];
            } catch (Throwable $e) {}
        }
    }
    $tags = $isOrder ? ot_astag_for_orders($db, array_values(array_unique($isOrder))) : [];

    // IR 的 product_id 是料號主檔 d_id，要換成料號文字
    $dids = [];
    foreach ($itemsBy as $list) foreach ($list as $it)
        if ($it['src_type'] === 'IR' && ctype_digit((string)$it['product_id'])) $dids[(int)$it['product_id']] = 1;
    $dName = [];
    if ($dids) {
        $k = array_keys($dids); $p4 = implode(',', array_fill(0, count($k), '?'));
        $q = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id IN ($p4)");
        $q->execute($k);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $dName[(int)$r['d_id']] = $r['D_Setting_Id'];
    }

    $cust = acc_customer_by_name($db);
    $dateTxt = str_replace('-', '/', $invDate) . ' ' . ($invDate === date('Y-m-d') ? date('H:i') : '12:00');
    $rows = []; $sum = []; $seen = [];

    foreach ($invs as $v) {
        $iid  = (int)$v['invoice_id'];
        $c    = $cust[$v['customer_name']] ?? [];
        $cid  = trim((string)$v['customer_id']) !== '' ? trim((string)$v['customer_id']) : (string)($c['customer_id'] ?? '');
        $base = $cid . '-' . str_replace('-', '', (string)$v['billing_month']);
        $seen[$base] = ($seen[$base] ?? 0) + 1;
        $orderNo = $seen[$base] > 1 ? $base . '-' . $seen[$base] : $base;
        $email = acc_einv_email($db, $cid, $c);

        $first = true; $lastNo = null; $cnt = 0;
        foreach ($itemsBy[$iid] ?? [] as $it) {
            $isRet = ($it['src_type'] === 'IR');
            $part = (string)$it['product_id'];
            if ($isRet && ctype_digit($part)) $part = (string)($dName[(int)$part] ?? $part);
            $tag  = (!$isRet && isset($isOrder[(int)$it['src_id']])) ? ($tags[$isOrder[(int)$it['src_id']]] ?? null) : null;
            $desc = acc_einv_desc_of_tag($tag);
            $name = trim($part . ' ' . $desc);
            $qty  = (float)$it['qty'];
            $price = (float)$it['unit_price'];
            $amt  = round($qty * $price);                       // 數量*單價（退貨 qty 為負）
            $note = ($it['src_no'] !== $lastNo) ? (string)$it['src_no'] : '';
            $lastNo = $it['src_no'];

            $rows[] = [
                $orderNo,
                $first ? $dateTxt : null,
                $first ? 'B2B存證' : null,
                $first ? '公司戶' : null,
                null, null, null,
                $first ? (string)$v['tax_id'] : null,
                null, null, null,
                $first ? $email : null,
                $name, '應稅', $qty, null, $price, $amt,
                $note !== '' ? mb_substr($note, 0, 40) : null,
                null,
            ];
            $first = false; $cnt++;
        }
        $sum[] = ['invoice_id' => $iid, 'customer' => $v['customer_name'], 'order_no' => $orderNo,
                  'email' => $email, 'tax_id' => $v['tax_id'], 'rows' => $cnt,
                  'sales' => (float)$v['sales_amount'], 'tax' => (float)$v['tax_amount'],
                  'total' => (float)$v['total_amount'], 'no_email' => ($email === '')];
    }
    return ['header' => $header, 'rows' => $rows, 'invoices' => $sum];
}

/** 輸出 xlsx 並結束（含 header） */
function acc_einv_send_xlsx(array $built, string $fileName): void
{
    require_once __DIR__ . '/../../vendor/autoload.php';
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $ss->getActiveSheet();
    $ws->setTitle('工作表1');
    $ws->fromArray($built['header'], null, 'A1');
    $r = 2;
    foreach ($built['rows'] as $row) {
        foreach ($row as $i => $val) {
            if ($val === null) continue;
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $r;
            // 統編、訂單編號等一律當文字，避免被轉成數字
            if (in_array($i, [0, 7, 11, 12, 18], true)) {
                $ws->setCellValueExplicit($coord, (string)$val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            } else {
                $ws->setCellValue($coord, $val);
            }
        }
        $r++;
    }
    foreach (range('A', 'T') as $col) $ws->getColumnDimension($col)->setAutoSize(true);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}
