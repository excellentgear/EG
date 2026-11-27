<?php
/**
 * 付款傳票（應付側，與收款傳票對稱）— 唯一實作。
 *
 * 一張付款單 → 借：應付帳款（逐採購單列出）、沒沖的餘額借「預付貨款」、匯費借「銀行手續費」；
 *              貸：依付款方式 現金／應付票據（支票）／銀行存款（匯款），匯費另貸銀行存款。
 * 可勾選多張付款單（可不同廠商）合併成「一張」ERP 傳票：每張付款單各留一列、batch_id 相同。
 * 有付款傳票的付款單不可修改／改沖帳／刪除，要先作廢傳票。
 */
require_once __DIR__ . '/acc_receipt_multi_lib.php';

function acc_pv_gl(): array
{
    return ['cash' => ['1101000', '現金'], 'notes' => ['2141000', '應付票據'], 'bank' => ['1102000', '銀行存款'],
            'prepay' => ['1251000', '預付貨款'], 'fee' => ['6250000', '銀行手續費']];
}

function acc_pv_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS acc_pay_voucher (
        pv_id        INT NOT NULL AUTO_INCREMENT,
        payment_id   INT NOT NULL,
        batch_id     INT NULL COMMENT '合併傳票批次（多張付款單共用一張傳票）',
        voucher_no   VARCHAR(20) DEFAULT NULL,
        voucher_date DATE NOT NULL,
        total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        lines_json   MEDIUMTEXT,
        status       VARCHAR(6) NOT NULL DEFAULT 'ok',
        created_by   VARCHAR(30) DEFAULT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (pv_id), KEY idx_pv_pay (payment_id), KEY idx_pv_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-付款傳票'");
}

/** 已開未作廢的付款傳票 → 有就不可改付款單／沖帳／刪除 */
function acc_pv_lock(PDO $db, int $pid): ?string
{
    if ($pid <= 0) return null;
    acc_pv_ensure($db);
    $q = $db->prepare("SELECT voucher_no FROM acc_pay_voucher WHERE payment_id=? AND status='ok' LIMIT 1"); $q->execute([$pid]);
    if (($r = $q->fetch(PDO::FETCH_NUM)) !== false) return '此付款單已開付款傳票' . ($r[0] ? ' ' . $r[0] : '') . '，請先作廢傳票再修改';
    return null;
}

/** 單張付款單分錄（預覽用，不寫入） */
function acc_pv_build(PDO $db, int $pid): array
{
    acc_pv_ensure($db); $G = acc_pv_gl();
    $q = $db->prepare("SELECT * FROM acc_payment WHERE payment_id=?"); $q->execute([$pid]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) return ['success' => false, 'message' => '找不到付款單'];
    $vend = (string)$p['vendor_name']; $amt = round((float)$p['amount'], 2); $fee = round((float)$p['fee'], 2);
    $nm = fn(array $c) => acc_rm_name_of($db, $c[0], $c[1]);
    $ap = acc_vch_acct_cfg($db)['ap_pay'];
    $L = []; $sum = 0.0;
    foreach (acc_payment_allocs($db, $pid) as $a) {
        $x = round((float)$a['amount'], 2); if (abs($x) < 0.005) continue;
        $L[] = ['code' => $ap['code'], 'name' => acc_rm_name_of($db, $ap['code'], $ap['name']), 'side' => $x >= 0 ? 'D' : 'C', 'amount' => abs($x),
                'memo' => '沖' . ($a['req_no'] ?: '採購單') . ' (' . $vend . ')'];
        $sum += $x;
    }
    $hold = round($amt - $sum, 2);   // 沒沖到採購單的餘額＝預付
    if ($hold > 0.004) $L[] = ['code' => $G['prepay'][0], 'name' => $nm($G['prepay']), 'side' => 'D', 'amount' => $hold, 'memo' => $vend . ' 未沖帳餘額（預付）'];
    elseif ($hold < -0.004) return ['success' => false, 'message' => $p['payment_no'] . '：沖帳金額大於付款金額，請先調整沖帳'];
    if ($fee > 0) $L[] = ['code' => $G['fee'][0], 'name' => $nm($G['fee']), 'side' => 'D', 'amount' => $fee, 'memo' => $vend . ' 匯費'];
    $m = (string)$p['method'];
    if ($m === '現金') { $c = $G['cash']; $memo = $vend . ' 現金'; }
    elseif ($m === '支票') { $c = $G['notes']; $memo = $vend . ' 支票 ' . $p['check_no'] . ($p['check_due'] ? ' 票期 ' . $p['check_due'] : ''); }
    else { $c = $G['bank']; $memo = $vend . ' ' . ($m ?: '匯款'); }
    $L[] = ['code' => $c[0], 'name' => $nm($c), 'side' => 'C', 'amount' => $amt, 'memo' => $memo];
    if ($fee > 0) $L[] = ['code' => $G['bank'][0], 'name' => $nm($G['bank']), 'side' => 'C', 'amount' => $fee, 'memo' => $vend . ' 匯費扣款'];
    $d = 0.0; $cr = 0.0; foreach ($L as $l) { if ($l['side'] === 'D') $d += $l['amount']; else $cr += $l['amount']; }
    return ['success' => true, 'lines' => $L, 'debit' => round($d, 2), 'credit' => round($cr, 2), 'balanced' => abs($d - $cr) < 0.01,
            'vendor' => $vend, 'payment_no' => $p['payment_no'], 'pay_date' => $p['pay_date']];
}

/** 合併預覽：多張付款單（可不同廠商）各自分錄串起來，借方在前、貸方在後 */
function acc_pv_batch_build(PDO $db, array $pids): array
{
    acc_pv_ensure($db);
    $pids = array_values(array_unique(array_filter(array_map('intval', $pids), fn($x) => $x > 0)));
    if (count($pids) < 1) return ['success' => false, 'message' => '請勾選要開傳票的付款單'];
    if (count($pids) > 100) return ['success' => false, 'message' => '一次最多合併 100 張付款單'];
    $D = []; $C = []; $recs = []; $maxDate = '';
    foreach ($pids as $pid) {
        $b = acc_pv_build($db, $pid);
        if (!$b['success']) return $b;
        if ($lk = acc_pv_lock($db, $pid)) return ['success' => false, 'message' => $b['payment_no'] . '：' . $lk];
        if (!$b['balanced']) return ['success' => false, 'message' => $b['payment_no'] . '：借貸不平衡'];
        foreach ($b['lines'] as $l) { if ($l['side'] === 'D') $D[] = $l; else $C[] = $l; }
        $recs[] = ['payment_id' => $pid, 'payment_no' => $b['payment_no'], 'vendor' => $b['vendor'], 'pay_date' => $b['pay_date'], 'amount' => $b['debit']];
        if ($b['pay_date'] > $maxDate) $maxDate = $b['pay_date'];
    }
    $L = array_merge($D, $C);
    $d = 0.0; $c = 0.0; foreach ($L as $l) { if ($l['side'] === 'D') $d += $l['amount']; else $c += $l['amount']; }
    return ['success' => true, 'lines' => $L, 'debit' => round($d, 2), 'credit' => round($c, 2), 'balanced' => abs($d - $c) < 0.01,
            'payments' => $recs, 'pay_date' => $maxDate];
}

function acc_pv_batch_save(PDO $db, array $pids, string $no, string $date, string $uname): array
{
    acc_pv_ensure($db);
    require_once __DIR__ . '/acc_voucher_lib.php';
    acc_voucher_ensure($db);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['success' => false, 'message' => '傳票日期格式錯誤'];
    $no = acc_vch_no_normalize($no, $date);
    if ($no === '') return ['success' => false, 'message' => '請先填 ERP 傳票號碼'];
    [$er, $nd] = acc_vch_no_check($no);
    if ($er) return ['success' => false, 'message' => $er];
    if ($nd !== $date) return ['success' => false, 'message' => '傳票號碼裡的日期（' . $nd . '）與傳票日期（' . $date . '）不一致'];
    if (acc_vch_no_taken($db, $no)) return ['success' => false, 'message' => '傳票號碼 ' . $no . ' 已存在'];
    $pids = array_values(array_unique(array_filter(array_map('intval', $pids), fn($x) => $x > 0)));
    $items = [];
    foreach ($pids as $pid) {
        $b = acc_pv_build($db, $pid);
        if (!$b['success']) return $b;
        if (!$b['balanced']) return ['success' => false, 'message' => $b['payment_no'] . '：借貸不平衡'];
        if ($lk = acc_pv_lock($db, $pid)) return ['success' => false, 'message' => $b['payment_no'] . '：' . $lk];
        $items[$pid] = $b;
    }
    if (!$items) return ['success' => false, 'message' => '請勾選要開傳票的付款單'];
    try {
        $db->beginTransaction();
        $ins = $db->prepare("INSERT INTO acc_pay_voucher (payment_id,voucher_no,voucher_date,total_amount,lines_json,created_by) VALUES (?,?,?,?,?,?)");
        $first = 0;
        foreach ($items as $pid => $b) {
            foreach ($b['lines'] as $l) acc_vch_account_upsert($db, $l['code'], $l['name']);
            $ins->execute([$pid, $no, $date, $b['debit'], json_encode($b['lines'], JSON_UNESCAPED_UNICODE), $uname]);
            if (!$first) $first = (int)$db->lastInsertId();
        }
        $db->prepare("UPDATE acc_pay_voucher SET batch_id=? WHERE voucher_no=? AND status='ok' AND batch_id IS NULL AND payment_id IN (" . implode(',', array_keys($items)) . ")")
           ->execute([$first, $no]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); return ['success' => false, 'message' => '建立失敗：' . $e->getMessage()]; }
    return ['success' => true, 'message' => '已合併 ' . count($items) . ' 張付款單，建立傳票 ' . $no];
}

/** 某付款單的傳票（含合併批次的張數與整張分錄） */
function acc_pv_of(PDO $db, int $pid): array
{
    acc_pv_ensure($db);
    $q = $db->prepare("SELECT pv_id, batch_id, voucher_no, voucher_date, total_amount, status, lines_json,
                              (SELECT COUNT(*) FROM acc_pay_voucher b WHERE b.batch_id=v.batch_id AND b.status='ok') AS batch_cnt
                       FROM acc_pay_voucher v WHERE payment_id=? ORDER BY pv_id"); $q->execute([$pid]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['lines'] = json_decode((string)$r['lines_json'], true) ?: []; unset($r['lines_json']); $r['batch_cnt'] = (int)$r['batch_cnt']; }
    return $rows;
}

function acc_pv_void(PDO $db, int $pvId): array
{
    acc_pv_ensure($db);
    $q = $db->prepare("SELECT batch_id FROM acc_pay_voucher WHERE pv_id=?"); $q->execute([$pvId]);
    $bid = (int)$q->fetchColumn();
    if ($bid > 0)
        $db->prepare("UPDATE acc_pay_voucher SET status='void', voucher_no=LEFT(CONCAT(voucher_no,'~廢',pv_id),20) WHERE batch_id=? AND status='ok'")->execute([$bid]);
    else
        $db->prepare("UPDATE acc_pay_voucher SET status='void', voucher_no=LEFT(CONCAT(voucher_no,'~廢',pv_id),20) WHERE pv_id=? AND status='ok'")->execute([$pvId]);
    return ['success' => true, 'message' => $bid > 0 ? '已作廢整張合併傳票，各付款單可重新開立' : '已作廢傳票，可重新開立'];
}

/** 清單附加：每張付款單的傳票號碼 */
function acc_pv_attach(PDO $db, array &$rows): void
{
    if (!$rows) return;
    acc_pv_ensure($db);
    $ids = array_map(fn($r) => (int)$r['payment_id'], $rows); $ph = implode(',', array_fill(0, count($ids), '?'));
    $q = $db->prepare("SELECT payment_id, voucher_no FROM acc_pay_voucher WHERE payment_id IN ($ph) AND status='ok'"); $q->execute($ids);
    $m = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) $m[(int)$v['payment_id']] = $v['voucher_no'];
    foreach ($rows as &$r) { $r['pay_vch_no'] = $m[(int)$r['payment_id']] ?? ''; $r['has_pay_vch'] = isset($m[(int)$r['payment_id']]) ? 1 : 0; }
    unset($r);
}
