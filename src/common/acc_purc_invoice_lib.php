<?php
/**
 * acc_purc_invoice_lib.php — 採購單登錄廠商發票後，自動接進會計的應付對帳
 *
 * 使用者要求（2026-11-26）：採購單設定發票後，自動「鎖定此筆帳（認定已對帳）」、自動建立發票相關資料，
 * 並提供「開傳票」入口給會計快速開立。
 *
 * 做法（全部走會計模組既有函式，不另寫第二套規則）：
 *   1. 這張採購單在應付裡是 src_type='PURC'；帳款月份取發票日期的月份，對象是 purchase_request.vendor_id（廠商編號）。
 *   2. 該廠商該月「還沒有」對帳單 → 只放這一張採購單建一份底稿（已勾選），確認鎖帳
 *      （acc_sheet_confirm 會把這筆憑證標為已對完），再把廠商發票號碼／日期登錄在對帳單上。
 *   3. 該廠商該月「已經有」對帳單：
 *        已鎖帳且含這張單 → 只補登廠商發票（對帳單上已有不同號碼就不覆蓋，回報請會計確認）
 *        已鎖帳但不含這張單、或還是草稿 → 一律不動別人的對帳單，回報請會計處理
 *      （自動動別人正在對的草稿，比多一道人工確認危險得多。）
 *
 * 之後會計要對「同一廠商同一月份」的整月對帳單時，這份單張對帳單已鎖帳，需先「退回重對」再把其他憑證帶進來——
 * 這是自動鎖帳的必然代價，已在回報訊息講明。
 *
 * 付款：本檔完全不碰 pay_status。付款狀態的唯一真相是會計的付款沖帳（acc_purc_sync_pay_status）。
 */
require_once __DIR__ . '/acc_lib.php';

/** 廠商發票號碼正規化：大寫、去空白與連字號，回傳 AB-12345678；格式不對回 null；空字串回 '' */
function acc_purc_invoice_norm(string $raw): ?string
{
    $v = strtoupper(preg_replace('/[\s-]+/u', '', $raw));
    if ($v === '') return '';
    if (!preg_match('/^[A-Z]{2}\d{8}$/', $v)) return null;
    return substr($v, 0, 2) . '-' . substr($v, 2);
}

/**
 * @return array ['posted'=>bool, 'message'=>string, 'sheet_id'=>?int, 'voucher_url'=>?string, 'warn'=>bool]
 */
function acc_purc_invoice_post(PDO $db, int $reqId, ?array $user): array
{
    $res = ['posted' => false, 'warn' => false, 'sheet_id' => null, 'voucher_url' => null, 'message' => ''];
    acc_ensure_schema($db);
    $rows = acc_purc_rows($db, ['req_id' => $reqId, 'include_cash' => true]);
    $r = $rows[0] ?? null;
    if (!$r) { $res['warn'] = true; $res['message'] = '找不到這張採購單的應付資料，未建立對帳。'; return $res; }

    $inv  = acc_purc_invoice_norm((string)$r['invoice_no']);
    $date = (string)$r['invoice_date'];
    if ($inv === null || $inv === '') { $res['message'] = '沒有發票號碼，未建立對帳。'; return $res; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $res['warn'] = true; $res['message'] = '缺發票日期，未建立對帳。'; return $res; }
    $vendor = trim((string)$r['vendor_id']);
    if ($vendor === '') { $res['warn'] = true; $res['message'] = '這張單還沒綁定廠商，未建立對帳（到貨時會要求綁定）。'; return $res; }
    if ($r['settle_mode'] === 'CASH') {
        $res['message'] = '這張單被判定為現金／零用金（' . $r['mode_reason'] . '），不經過應付，未建立對帳；若判定有誤，請會計在應付頁改判成月結。';
        $res['warn'] = true; return $res;
    }
    if (!$r['payable_stage']) {
        $res['warn'] = true; $res['message'] = '這張單還沒到貨（或金額為 0），尚未成立應付，未建立對帳。到貨後再存一次發票即可。';
        return $res;
    }

    $bm = substr($date, 0, 7);
    $res['voucher_url'] = '../ACC/voucher.php?side=ap&bm=' . rawurlencode($bm) . '&party=' . rawurlencode($vendor)
                        . '&inv=' . rawurlencode($inv) . '&idate=' . rawurlencode($date);

    $core = acc_sheet_build_core($db, 'ap', $vendor, $bm);
    $line = null;
    foreach ($core['lines'] as $l) {
        if (strtoupper((string)$l['src_type']) === 'PURC' && (int)$l['src_id'] === $reqId) { $line = $l; break; }
    }
    if (!$line) {
        $res['warn'] = true;
        $res['message'] = '這張單在 ' . $bm . ' 的應付明細裡找不到（帳款月份依發票日期判定），請會計到對帳作業確認。';
        return $res;
    }

    $cur = acc_sheet_get($db, 'ap', $vendor, $bm);
    if ($cur) {
        $has = false;
        foreach ($cur['lines'] as $cl) {
            if (strtoupper((string)$cl['src_type']) === 'PURC' && (int)$cl['src_id'] === $reqId) { $has = true; break; }
        }
        $res['sheet_id'] = (int)$cur['sheet_id'];
        if ($cur['status'] === 'confirmed' && $has) {
            $old = trim((string)($cur['vendor_inv_no'] ?? ''));
            if ($old === '' || $old === $inv) {
                $v = acc_sheet_vendor_inv_save($db, (int)$cur['sheet_id'], $inv, $date);
                $res['posted'] = !empty($v['success']);
                $res['message'] = $res['posted'] ? '該廠商 ' . $bm . ' 的對帳單已鎖帳，已補登廠商發票 ' . $inv . '。' : (string)$v['message'];
                $res['warn'] = !$res['posted'];
            } else {
                $res['warn'] = true;
                $res['message'] = '該月對帳單已登錄廠商發票 ' . $old . '，與這張單的 ' . $inv . ' 不同，未覆蓋；請會計確認。';
            }
            return $res;
        }
        $res['warn'] = true;
        $res['message'] = '該廠商 ' . $bm . ' 已經有對帳單（' . ($cur['status'] === 'confirmed' ? '已鎖帳，但不含這張單' : '草稿進行中')
                        . '），為免動到會計正在對的帳，未自動建立；請會計在對帳作業處理，發票資料已記在採購單上。';
        return $res;
    }

    $line['checked']    = 1;
    $line['client_key'] = 'p' . $reqId;
    $sheet = ['side' => 'ap', 'party_id' => $vendor, 'party_name' => $core['party_name'], 'billing_month' => $bm,
              'their_total' => $r['grand_total'], 'memo' => '採購單 ' . $r['req_no'] . ' 登錄發票後自動建立並鎖帳'];
    $sv = acc_sheet_save($db, $sheet, [$line], $user);
    if (empty($sv['success'])) { $res['warn'] = true; $res['message'] = '建立對帳底稿失敗：' . $sv['message']; return $res; }
    $sid = (int)$sv['sheet_id'];
    $res['sheet_id'] = $sid;
    $cf = acc_sheet_confirm($db, $sid, $user);
    if (empty($cf['success'])) { $res['warn'] = true; $res['message'] = '已建立對帳底稿，但鎖帳失敗：' . $cf['message']; return $res; }
    $vi = acc_sheet_vendor_inv_save($db, $sid, $inv, $date);
    $res['posted']  = true;
    $res['message'] = '已自動建立 ' . $bm . ' 應付對帳並鎖帳（認定已對帳），廠商發票 ' . $inv
                    . (empty($vi['success']) ? '（發票登錄失敗：' . $vi['message'] . '）' : ' 已登錄')
                    . '。會計可直接開傳票。';
    return $res;
}
