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

/** 管理員設定（system_settings）：每張電子發票筆數上限、開立日期可往前幾天 */
function acc_einv_settings(PDO $db): array
{
    $out = ['max_rows' => 36, 'back_days' => 6, 'time' => '18:30', 'backfill' => false];
    try {
        $st = $db->query("SELECT setting_key, setting_value FROM system_settings
                          WHERE setting_key IN ('acc_einv_max_rows','acc_einv_back_days','acc_einv_time','acc_einv_backfill_date')");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = trim((string)$r['setting_value']);
            // 補帳模式：只在「設定當天」有效，隔天自動失效（需重新勾選）
            if ($r['setting_key'] === 'acc_einv_backfill_date') { $out['backfill'] = ($v === date('Y-m-d')); continue; }
            if ($r['setting_key'] === 'acc_einv_time') { if (acc_einv_time_ok($v)) $out['time'] = $v; continue; }
            if (!ctype_digit($v)) continue;
            if ($r['setting_key'] === 'acc_einv_max_rows' && (int)$v >= 1)  $out['max_rows']  = min(500, (int)$v);
            if ($r['setting_key'] === 'acc_einv_back_days' && (int)$v >= 0) $out['back_days'] = min(60, (int)$v);
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 發票時間 HH:MM（00:00~23:59） */
function acc_einv_time_ok(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

function acc_einv_settings_save(PDO $db, $maxRows, $backDays, string $uid, string $uname, $time = null, $backfill = null): array
{
    $maxRows = trim((string)$maxRows); $backDays = trim((string)$backDays);
    if (!ctype_digit($maxRows) || (int)$maxRows < 1 || (int)$maxRows > 500) return ['success' => false, 'message' => '每張發票筆數上限須為 1~500 的整數'];
    if (!ctype_digit($backDays) || (int)$backDays > 60) return ['success' => false, 'message' => '可往前開立天數須為 0~60 的整數'];
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                        updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)");
    $st->execute(['acc_einv_max_rows',  $maxRows,  (int)$uid, $uname]);
    $st->execute(['acc_einv_back_days', $backDays, (int)$uid, $uname]);
    if ($time !== null && trim((string)$time) !== '') {
        if (!acc_einv_time_ok(trim((string)$time))) return ['success' => false, 'message' => '發票時間格式須為 HH:MM（例 18:30）'];
        $st->execute(['acc_einv_time', trim((string)$time), (int)$uid, $uname]);
    }
    if ($backfill !== null) {
        $st->execute(['acc_einv_backfill_date', ((string)$backfill === '1') ? date('Y-m-d') : '', (int)$uid, $uname]);
    }
    return ['success' => true, 'message' => '已儲存', 'settings' => acc_einv_settings($db)];
}

/** 開立日期可選範圍：今天往前最多 N 天（管理員設定）、往後到「今天所在月份」月底 */
function acc_einv_date_range(?PDO $db = null, ?string $today = null): array
{
    $cfg = $db ? acc_einv_settings($db) : ['back_days' => 6, 'backfill' => false];
    $back = $cfg['back_days'];
    $t = new DateTime($today ?: date('Y-m-d'));
    $min = $cfg['backfill'] ? '2000-01-01' : (clone $t)->modify('-' . $back . ' days')->format('Y-m-d');
    $max = $t->format('Y-m-t');
    return ['min' => $min, 'max' => $max, 'today' => $t->format('Y-m-d'), 'back_days' => $back, 'backfill' => (bool)$cfg['backfill']];
}

/** 驗證開立日期；不合法回錯誤字串，合法回 null */
function acc_einv_date_check(PDO $db, string $d): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) return '開立日期格式錯誤';
    $r = acc_einv_date_range($db);
    if ($d < $r['min']) return '開立日期最早只能到 ' . $r['min'] . '（往前最多 ' . $r['back_days'] . ' 天）';
    if ($d > $r['max']) return '開立日期最晚只能到 ' . $r['max'] . '（本月月底）';
    return null;
}

/** 品名說明文字：全製→齒輪、治具→治具、砂輪→砂輪、刀具→刀具，其餘（單製／多製程…）→齒輪加工 */
function acc_einv_desc_of_tag(?array $tag): string
{
    if (!$tag) return '齒輪加工';
    $lab = (string)($tag['proc_name'] ?? '') . (string)($tag['label'] ?? '');
    if (!empty($tag['is_full']) || mb_strpos($lab, '全製') !== false) return '齒輪';
    if (mb_strpos($lab, '治具') !== false) return '治具';
    if (mb_strpos($lab, '砂輪') !== false) return '砂輪';
    if (mb_strpos($lab, '刀具') !== false) return '刀具';
    return '齒輪加工';
}

/** 會計聯絡人 email（客戶聯絡人設為會計者），沒有則退回客戶發票 email */
function acc_einv_email(PDO $db, string $customerId, array $cust): string
{
    // 買方電子信箱固定＝客戶主檔標記為「本公司」那一筆的「電子發票信箱」（不帶入各客戶自己的信箱）
    static $own = null;
    if ($own === null) {
        $own = '';
        try { $own = trim((string)$db->query("SELECT invoice_email FROM customer_list WHERE is_own_company=1 AND invoice_email<>'' ORDER BY customer_id LIMIT 1")->fetchColumn()); } catch (Throwable $e) {}
    }
    return $own;
}

/**
 * 產生匯入列。$ids＝acc_invoice.invoice_id 陣列；$invDate＝YYYY-MM-DD。
 * @return array ['header'=>[...20欄], 'rows'=>[[...20欄]...], 'invoices'=>[摘要...]]
 */
function acc_einv_build(PDO $db, array $ids, string $invDate, string $invTime = ''): array
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

    $sti = $db->prepare("SELECT * FROM acc_invoice_item WHERE invoice_id IN ($ph) ORDER BY invoice_id, (src_type='DISC'), src_date, src_no, item_id");
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
    $cfg  = acc_einv_settings($db);
    $maxRows = $cfg['max_rows'];
    // 發票日期的時間：預設 18:30（管理員可在電子發票設定改固定時間），匯出預覽時也可臨時修改
    $tm = acc_einv_time_ok($invTime) ? $invTime : $cfg['time'];
    $dateTxt = str_replace('-', '/', $invDate) . ' ' . $tm;
    $rows = []; $sum = []; $seen = [];

    foreach ($invs as $v) {
        $iid  = (int)$v['invoice_id'];
        $c    = $cust[$v['customer_name']] ?? [];
        $cid  = trim((string)$v['customer_id']) !== '' ? trim((string)$v['customer_id']) : (string)($c['customer_id'] ?? '');
        $base = $cid . '-' . str_replace('-', '', (string)$v['billing_month']);
        $seen[$base] = ($seen[$base] ?? 0) + 1;
        $orderBase = $seen[$base] > 1 ? $base . '-' . $seen[$base] : $base;
        $email = acc_einv_email($db, $cid, $c);
        $pages = (isset($c['einv_max_pages']) && (int)$c['einv_max_pages'] > 0) ? (int)$c['einv_max_pages'] : 0;
        $mergePart = !empty($c['einv_merge_part']);

        // 1) 明細轉成中性資料列
        $lines = [];
        foreach ($itemsBy[$iid] ?? [] as $it) {
            $isRet = ($it['src_type'] === 'IR');
            if (abs((float)$it['amount']) < 0.0001 || abs((float)$it['unit_price']) < 0.0001) continue;   // 單價／金額為 0 的不匯出到電子發票
            $part = (string)$it['product_id'];
            if ($isRet && ctype_digit($part)) $part = (string)($dName[(int)$part] ?? $part);
            $tag  = (!$isRet && isset($isOrder[(int)$it['src_id']])) ? ($tags[$isOrder[(int)$it['src_id']]] ?? null) : null;
            if ($it['src_type'] === 'DISC') { $part = ''; $tag = null; }   // 客戶折扣列：品名＝「?%折扣」
            $lines[] = ['part' => $part, 'desc' => $it['src_type'] === 'DISC' ? (string)$it['spec'] : acc_einv_desc_of_tag($tag), 'qty' => (float)$it['qty'],
                        'amt' => (float)$it['amount'], 'no' => (string)$it['src_no'], 'merged' => 1, 'ret' => $isRet];
        }
        $rawCnt = count($lines);
        $mode = '';

        // 2) 壓縮：勾「同料號合併一筆」→ 不分出貨單、同料號（同說明）數量與金額加總
        //    退貨不與出貨混在一起：發票上仍列出退貨，同一退貨單號＋同料號合併成一筆，備註註明退貨單號
        if ($mergePart && $lines) {
            $g = [];
            foreach ($lines as $l) {
                $k = !empty($l['ret']) ? 'RET|' . $l['no'] . '|' . $l['part'] . '|' . $l['desc'] : $l['part'] . '|' . $l['desc'];
                if (!isset($g[$k])) { $g[$k] = $l; $g[$k]['nos'] = []; $g[$k]['merged'] = 0; }
                else { $g[$k]['qty'] += $l['qty']; $g[$k]['amt'] += $l['amt']; }
                $g[$k]['merged']++;
                $g[$k]['nos'][$l['no']] = 1;
            }
            foreach ($g as &$x) { if (empty($x['ret'])) $x['no'] = ''; } unset($x);   // 退貨保留退貨單號供備註
            $lines = array_values($g); $mode = 'merge_part';
        }
        // 3) 有頁數上限且仍超出 → 同出貨單同料號同數量的金額加總（數量不加總）
        elseif ($pages > 0 && $rawCnt > $pages * $maxRows) {
            $g = [];
            foreach ($lines as $l) {
                $k = $l['no'] . '|' . $l['part'] . '|' . $l['desc'] . '|' . $l['qty'];
                if (!isset($g[$k])) { $g[$k] = $l; $g[$k]['merged'] = 1; }
                else { $g[$k]['amt'] += $l['amt']; $g[$k]['merged']++; }
            }
            $lines = array_values($g); $mode = 'same_qty';
        }

        // 4) 依每張筆數上限切成多張（各自一個訂單編號）
        $chunks = array_chunk($lines, $maxRows);
        $over = ($pages > 0 && count($chunks) > $pages);
        $multi = count($chunks) > 1;
        foreach ($chunks as $ci => $chunk) {
            $orderNo = $multi ? $orderBase . '-' . ($ci + 1) : $orderBase;
            $first = true; $lastNo = null;
            foreach ($chunk as $l) {
                $qty = $l['qty'];
                $amt = round($l['amt']);
                $price = ($l['merged'] > 1 || $mode !== '') ? ($qty != 0 ? round($l['amt'] / $qty, 4) : 0) : ($qty != 0 ? round($l['amt'] / $qty, 4) : 0);
                if (!empty($l['ret'])) { $qty = abs($qty); $price = -abs($price); $amt = -abs($amt); }   // 退貨：數量為正，單價與金額為負
                $note = '';
                if (!empty($l['ret']) && $l['no'] !== '') $note = $l['no'];   // 退貨列每筆都註明退貨單號
                elseif ($l['no'] !== '' && $l['no'] !== $lastNo) $note = $l['no'];
                $lastNo = $l['no'];
                $rows[] = [
                    $orderNo,
                    $first ? $dateTxt : null,
                    $first ? 'B2B存證' : null,
                    $first ? '公司戶' : null,
                    null, null, null,
                    $first ? (string)$v['tax_id'] : null,
                    null, null, null,
                    $first ? $email : null,
                    trim($l['part'] . ' ' . $l['desc']), '應稅', $qty, null, $price, $amt,
                    $note !== '' ? mb_substr($note, 0, 40) : null,
                    null,
                ];
                $first = false;
            }
        }
        $sum[] = ['invoice_id' => $iid, 'customer' => $v['customer_name'], 'customer_id' => $cid, 'billing_month' => (string)$v['billing_month'], 'order_no' => $orderBase,
                  'email' => $email, 'tax_id' => $v['tax_id'], 'rows' => count($lines), 'raw_rows' => $rawCnt,
                  'invoices_out' => count($chunks), 'mode' => $mode, 'pages' => $pages, 'over_pages' => $over,
                  'sales' => (float)$v['sales_amount'], 'tax' => (float)$v['tax_amount'],
                  'total' => (float)$v['total_amount'], 'no_email' => ($email === '')];
    }
    return ['header' => $header, 'rows' => $rows, 'invoices' => $sum];
}

/** 輸出 xlsx 並結束（含 header） */
/** 下載檔名：客戶中文名稱-客戶ID-西元年月（YYYYMM）；一次轉多家時以「○○等N家」表示 */
function acc_einv_file_name(array $built): string
{
    $inv = $built['invoices'] ?? [];
    if (!$inv) return '電子發票匯入_' . date('Ymd') . '.xlsx';
    $first = $inv[0];
    $names = array_values(array_unique(array_map(fn($x) => (string)$x['customer'], $inv)));
    $name = $names[0] . (count($names) > 1 ? '等' . count($names) . '家' : '');
    $cid = count($names) > 1 ? '' : (string)$first['customer_id'];
    $ym = str_replace('-', '', (string)$first['billing_month']);
    $base = implode('-', array_filter([$name, $cid, $ym], fn($t) => $t !== ''));
    $base = preg_replace('~[\\/:*?"<>|]+~u', '', $base);
    return ($base !== '' ? $base : '電子發票匯入') . '.xlsx';
}

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
