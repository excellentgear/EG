<?php
/**
 * src/common/acc_erp_batch_lib.php — ERP 對帳單「批次」匯入：多檔上傳 → 批次帶到對帳底稿 → 批次鎖帳 → 批次開發票 → 批次轉傳票
 *
 * 每一步都只是呼叫既有的唯一實作（acc_erp_stmt_*／acc_sheet_save／acc_sheet_confirm／acc_create_invoices／acc_voucher_create），
 * 這裡只負責串接，不另寫金額或鎖帳規則。
 *
 * 比對結果（entries）一份可能數百列，多檔同時上傳放 session 會把每個請求都拖慢，
 * 所以批次上傳的比對結果存成暫存檔（與登入者綁定），單檔匯入仍沿用 session。
 */
require_once __DIR__ . '/acc_erp_stmt_lib.php';
require_once __DIR__ . '/acc_voucher_lib.php';
require_once __DIR__ . '/acc_einvoice_lib.php';

function acc_erp_stash_dir(): string
{
    $d = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'eg_acc_erp_stash';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}

function acc_erp_stash_put(string $token, array $stash, int $uid): void
{
    $stash['_uid'] = $uid;
    @file_put_contents(acc_erp_stash_dir() . DIRECTORY_SEPARATOR . $token . '.json', json_encode($stash, JSON_UNESCAPED_UNICODE));
    // 順手清掉 2 天前的舊暫存
    foreach (glob(acc_erp_stash_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f)
        if (@filemtime($f) < time() - 172800) @unlink($f);
}

/** 取比對結果：先看 session（單檔匯入），再看批次暫存檔（必須是同一位登入者） */
function acc_erp_stash_get(string $token, int $uid): ?array
{
    if (!preg_match('/^[0-9a-f]{20}$/', $token)) return null;
    if (isset($_SESSION['acc_erp_stmt'][$token])) return $_SESSION['acc_erp_stmt'][$token];
    $f = acc_erp_stash_dir() . DIRECTORY_SEPARATOR . $token . '.json';
    if (!is_file($f)) return null;
    $s = json_decode((string)file_get_contents($f), true);
    if (!is_array($s) || (int)($s['_uid'] ?? 0) !== $uid) return null;
    return $s;
}

/** 某客戶＋帳款月份目前的作業狀態：底稿（含已勾選明細的未稅／稅金／含稅）、發票、傳票 */
function acc_erp_batch_status(PDO $db, string $party, string $bm): array
{
    $out = ['sheet_id' => null, 'sheet_status' => 'none', 'net' => 0.0, 'tax' => 0.0, 'total' => 0.0,
            'their_total' => null, 'checked_cnt' => 0, 'unchecked_cnt' => 0, 'invoices' => [], 'vouchers' => []];
    $sh = acc_sheet_get($db, 'ar', $party, $bm);
    if ($sh) {
        $out['sheet_id'] = (int)$sh['sheet_id'];
        $out['sheet_status'] = (string)$sh['status'];
        $out['their_total'] = $sh['their_total'] === null ? null : (float)$sh['their_total'];
        $net = 0.0;
        foreach ($sh['lines'] as $l) {
            if (empty($l['counts_in_total'])) continue;
            if (!empty($l['checked'])) { $out['checked_cnt']++; $net += (float)$l['eff_amount']; } else $out['unchecked_cnt']++;
        }
        $out['net'] = $net; $out['tax'] = round($net * acc_tax_rate($db)); $out['total'] = $net + $out['tax'];
    }
    try {
        $q = $db->prepare("SELECT invoice_id, status, invoice_no, DATE_FORMAT(invoice_date,'%Y-%m-%d') AS invoice_date, total_amount
                           FROM acc_invoice WHERE customer_name=? AND billing_month=? AND status<>'void' ORDER BY invoice_id");
        $q->execute([$party, $bm]);
        $out['invoices'] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try {
        $q = $db->prepare("SELECT voucher_id, voucher_no, DATE_FORMAT(voucher_date,'%Y-%m-%d') AS voucher_date, total_amount
                           FROM acc_voucher WHERE side='ar' AND party_id=? AND billing_month=? AND status<>'void' ORDER BY voucher_id");
        $q->execute([$party, $bm]);
        $out['vouchers'] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    return $out;
}

/** 開啟對帳作業核對的網址 */
function acc_erp_batch_link(array $t): string
{
    return 'reconcile.php?side=ar&party_id=' . rawurlencode((string)$t['party_id']) . '&bm=' . rawurlencode((string)$t['bm'])
         . ($t['their_total'] === null ? '' : '&their=' . rawurlencode((string)$t['their_total']))
         . '&erp=' . rawurlencode((string)$t['token'])
         . ($t['date_from'] && $t['date_to'] ? '&rg_from=' . rawurlencode($t['date_from']) . '&rg_to=' . rawurlencode($t['date_to']) : '');
}

/** 單一客戶：同步單價／補漏 → 推出客戶與帳款月份 → 建立（或更新）對帳底稿，已與 ERP 一致的明細標成「已對到」後暫存 */
function acc_erp_batch_prepare(PDO $db, array $stash, ?array $user, string $userName): array
{
    $sy = acc_erp_stmt_sync($db, $stash, $userName);
    if (empty($sy['success'])) return ['success' => false, 'message' => $sy['message'] ?? '同步失敗'];
    $t = acc_erp_stmt_recon_target($db, $stash);
    if (empty($t['success'])) return ['success' => false, 'message' => $t['message']];
    $party = (string)$t['party_id']; $bm = (string)$t['bm'];

    $cur = acc_sheet_get($db, 'ar', $party, $bm);
    if ($cur && $cur['status'] === 'confirmed')
        return ['success' => true, 'party_id' => $party, 'bm' => $bm, 'already' => true, 'multi_party' => $t['multi_party'],
                'multi_month' => $t['multi_month'], 'synced' => (int)($sy['updated'] ?? 0), 'created' => (int)($sy['created'] ?? 0),
                'skipped' => $sy['skipped'] ?? [], 'status' => acc_erp_batch_status($db, $party, $bm)];

    $keys = array_flip(acc_erp_stmt_checked_keys($db, $stash));
    $built = acc_sheet_build($db, 'ar', $party, $bm, ['date_from' => $t['date_from'], 'date_to' => $t['date_to'], 'with_prior' => true]);
    $lines = [];
    if ($cur) {                                   // 既有草稿：原樣保留，只補來源新增的列
        $have = [];
        foreach ($cur['lines'] as $l) {
            $l['client_key'] = 'L' . $l['line_id'];
            $l['split_parent_key'] = $l['split_parent'] !== null ? 'L' . $l['split_parent'] : null;
            if ($l['src_type'] !== 'SPLIT') $have[$l['src_type'] . '-' . (int)$l['src_id']] = true;
            $lines[] = $l;
        }
        $excl = acc_sheet_excl_keys($db, (int)$cur['sheet_id']);
        foreach ($built['lines'] as $l) {
            $k = $l['src_type'] . '-' . (int)$l['src_id'];
            if (isset($have[$k]) || isset($excl[$k])) continue;
            $lines[] = $l;
        }
    } else $lines = $built['lines'];
    foreach ($lines as &$l) {
        if (in_array($l['src_type'], ['SPLIT', 'MANUAL'], true) || !empty($l['split_parent_key'])) continue;
        if (isset($keys[$l['src_type'] . '-' . (int)$l['src_id']])) $l['checked'] = 1;
    }
    unset($l);
    if (!$lines) return ['success' => false, 'message' => '查不到 ' . $party . ' ' . $bm . ' 的出貨明細'];

    $r = acc_sheet_save($db, ['side' => 'ar', 'party_id' => $party, 'party_name' => $built['party_name'], 'billing_month' => $bm,
                              'their_total' => $t['their_total'], 'memo' => $cur['memo'] ?? null], $lines, $user);
    if (empty($r['success'])) return ['success' => false, 'message' => $r['message']];
    return ['success' => true, 'party_id' => $party, 'bm' => $bm, 'already' => false, 'multi_party' => $t['multi_party'],
            'multi_month' => $t['multi_month'], 'synced' => (int)($sy['updated'] ?? 0), 'created' => (int)($sy['created'] ?? 0),
            'skipped' => $sy['skipped'] ?? [], 'status' => acc_erp_batch_status($db, $party, $bm),
            'link' => acc_erp_batch_link($t)];
}

/** 批次鎖帳：沿用 acc_sheet_confirm（只鎖已勾選的明細） */
function acc_erp_batch_lock(PDO $db, string $party, string $bm, ?array $user): array
{
    $sh = acc_sheet_get($db, 'ar', $party, $bm);
    if (!$sh) return ['success' => false, 'message' => '找不到對帳底稿，請先「帶到對帳作業」'];
    if ($sh['status'] === 'confirmed') return ['success' => true, 'message' => '已是鎖帳狀態'];
    $chk = 0; foreach ($sh['lines'] as $l) if (!empty($l['checked'])) $chk++;
    if (!$chk) return ['success' => false, 'message' => '沒有任何已對到的明細，請先開啟核對'];
    return acc_sheet_confirm($db, (int)$sh['sheet_id'], $user);
}

/** 批次開發票：沿用 acc_create_invoices，建完設定開立日期（先驗日期才建，免得建了又失敗） */
function acc_erp_batch_invoice(PDO $db, string $party, string $bm, string $invDate, string $userId): array
{
    if ($e = acc_einv_date_check($db, $invDate)) return ['success' => false, 'message' => $e];
    $r = acc_create_invoices($db, [['customer' => $party, 'billing_month' => $bm]], $userId);
    if (empty($r['success']) || empty($r['invoices'])) {
        $m = $r['message'] ?? '';
        if (!empty($r['errors'])) $m = implode('；', $r['errors']);
        return ['success' => false, 'message' => $m ?: '沒有可開立的發票'];
    }
    $ids = array_column($r['invoices'], 'invoice_id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE acc_invoice SET invoice_date=?, Modified_By=?, Modified_At=NOW() WHERE invoice_id IN ($ph) AND status IN ('draft','exported')")
       ->execute(array_merge([$invDate, $userId], $ids));
    return ['success' => true, 'message' => $r['message'], 'invoice_ids' => $ids];
}

/** 批次轉傳票：該客戶該月份尚未有傳票的明細全部轉入（自動分錄，不加追加科目） */
function acc_erp_batch_voucher(PDO $db, string $party, string $bm, string $vDate, string $vNo, string $userName): array
{
    $cand = acc_voucher_candidates($db, 'ar', $party, $bm);
    $sel = [];
    foreach ($cand['items'] as $it) if (empty($it['locked'])) $sel[] = ['src_type' => $it['src_type'], 'src_id' => $it['src_id']];
    if (!$sel) return ['success' => false, 'message' => '沒有可轉傳票的明細（可能已全部開過傳票）'];
    return acc_voucher_create($db, 'ar', $party, $bm, $vDate, $vNo, '', $sel, $userName);
}
