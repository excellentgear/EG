<?php
/**
 * 收款單「混合收款」＋本公司入帳帳戶＋收款傳票／支票兌現入帳傳票 — 唯一實作。
 *
 * 一張收款單 acc_receipt 底下有多筆收款明細 acc_receipt_line（現金／支票／匯款…可混合）：
 *   - 匯款：必選「本公司入帳帳戶」（acc_company_acct：設定編號／名稱／銀行／帳號／科目／備註，可設預設）
 *   - 支票：票號、票期必填；開票銀行、客戶銀行帳號選填；收票日＝收款單的「收款日」（預設今日、可改）
 *   - 支票兌現入帳是另一個時點：會計確認入帳（入帳日、入帳帳戶）後，才可另開「入帳傳票」（借銀行存款／貸應收票據）
 * 收款單表頭 amount＝各明細合計（實收）；method／bank／check_no／check_due／remit_account 只是第一筆明細的摘要（相容舊畫面）。
 * 收款傳票（沖帳時的傳票）：借方依各收款明細（現金／應收票據／銀行存款）＋客戶扣款（銷貨折讓）＋匯費；
 *   貸方「應收帳款」依沖帳的發票明細逐出貨單／退貨單號列出（部分沖帳依比例攤到單號），沒沖的餘額貸「暫收款」。
 */
require_once __DIR__ . '/acc_receipt_ext_lib.php';

const ACC_RCPT_DEDUCT_MAX = 5;   // 客戶付款時扣%的上限

function acc_rm_gl(): array
{
    return ['cash' => ['1101000', '現金'], 'notes' => ['1141000', '應收票據'], 'bank' => ['1102000', '銀行存款'],
            'ar' => ['1144000', '應收帳款'], 'disc' => ['4103000', '銷貨折讓'], 'fee' => ['6250000', '銀行手續費'],
            'hold' => ['2825000', '暫收款']];
}

function acc_rm_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    acc_rcpt_ext_ensure($db);
    $db->exec("CREATE TABLE IF NOT EXISTS acc_company_acct (
        acct_id    INT NOT NULL AUTO_INCREMENT,
        code       VARCHAR(20)  NOT NULL COMMENT '設定編號',
        name       VARCHAR(60)  NOT NULL COMMENT '名稱',
        bank       VARCHAR(60)  DEFAULT NULL COMMENT '銀行',
        account_no VARCHAR(40)  NOT NULL COMMENT '帳號',
        gl_code    VARCHAR(10)  NOT NULL DEFAULT '1102000' COMMENT '對應會計科目（銀行存款）',
        note       VARCHAR(200) DEFAULT NULL,
        is_default TINYINT(1)   NOT NULL DEFAULT 0,
        active     TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (acct_id), UNIQUE KEY uq_cacct_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-本公司入帳帳戶'");
    $db->exec("CREATE TABLE IF NOT EXISTS acc_receipt_line (
        line_id    INT NOT NULL AUTO_INCREMENT,
        receipt_id INT NOT NULL,
        seq        INT NOT NULL DEFAULT 1,
        method     VARCHAR(20) NOT NULL,
        amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
        acct_id    INT DEFAULT NULL COMMENT '匯款：本公司入帳帳戶',
        check_no   VARCHAR(30) DEFAULT NULL,
        check_due  DATE DEFAULT NULL COMMENT '票期（到期日）',
        check_bank VARCHAR(50) DEFAULT NULL COMMENT '開票銀行',
        check_acct VARCHAR(40) DEFAULT NULL COMMENT '支票上客戶的銀行帳號',
        deposit_date    DATE DEFAULT NULL COMMENT '支票兌現入帳日（會計確認）',
        deposit_acct_id INT DEFAULT NULL,
        deposit_by VARCHAR(11) DEFAULT NULL,
        deposit_at DATETIME DEFAULT NULL,
        PRIMARY KEY (line_id), KEY idx_rl_rcpt (receipt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-收款明細（混合收款）'");
    $db->exec("CREATE TABLE IF NOT EXISTS acc_rcpt_voucher (
        rv_id        INT NOT NULL AUTO_INCREMENT,
        kind         VARCHAR(8) NOT NULL COMMENT 'RCPT=收款傳票 DEPOSIT=支票入帳傳票',
        receipt_id   INT NOT NULL,
        line_id      INT NOT NULL DEFAULT 0,
        voucher_no   VARCHAR(20) DEFAULT NULL,
        voucher_date DATE NOT NULL,
        total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        lines_json   MEDIUMTEXT,
        status       VARCHAR(6) NOT NULL DEFAULT 'ok',
        created_by   VARCHAR(30) DEFAULT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (rv_id), KEY idx_rv_rcpt (receipt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-收款傳票／支票入帳傳票'");
}

/* ── 本公司入帳帳戶 ─────────────────────────────────────────── */
function acc_rm_accts(PDO $db, bool $all = false): array
{
    acc_rm_ensure($db);
    $rows = $db->query("SELECT * FROM acc_company_acct " . ($all ? '' : 'WHERE active=1 ') . "ORDER BY is_default DESC, code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['acct_id'] = (int)$r['acct_id']; $r['is_default'] = (int)$r['is_default']; $r['active'] = (int)$r['active']; }
    return $rows;
}

function acc_rm_acct_save(PDO $db, array $d): array
{
    acc_rm_ensure($db);
    $id = (int)($d['acct_id'] ?? 0);
    $code = trim((string)($d['code'] ?? '')); $name = trim((string)($d['name'] ?? ''));
    $bank = trim((string)($d['bank'] ?? '')); $no = trim((string)($d['account_no'] ?? ''));
    $gl = trim((string)($d['gl_code'] ?? '')) ?: '1102000'; $note = trim((string)($d['note'] ?? ''));
    $def = !empty($d['is_default']) ? 1 : 0; $act = array_key_exists('active', $d) ? (!empty($d['active']) ? 1 : 0) : 1;
    if ($code === '' || mb_strlen($code) > 20) return ['success' => false, 'message' => '請填設定編號（20 字內）'];
    if ($name === '' || mb_strlen($name) > 60) return ['success' => false, 'message' => '請填名稱'];
    if ($no === '' || mb_strlen($no) > 40) return ['success' => false, 'message' => '請填帳號'];
    if (!preg_match('/^\d{4,10}$/', $gl)) return ['success' => false, 'message' => '會計科目代號須為 4~10 位數字'];
    $q = $db->prepare("SELECT acct_id FROM acc_company_acct WHERE code=? AND acct_id<>?"); $q->execute([$code, $id]);
    if ($q->fetchColumn()) return ['success' => false, 'message' => '設定編號 ' . $code . ' 已被使用'];
    try {
        $db->beginTransaction();
        if ($def) $db->exec("UPDATE acc_company_acct SET is_default=0");
        if ($id > 0) $db->prepare("UPDATE acc_company_acct SET code=?,name=?,bank=?,account_no=?,gl_code=?,note=?,is_default=?,active=? WHERE acct_id=?")
                        ->execute([$code, $name, $bank ?: null, $no, $gl, $note ?: null, $def, $act, $id]);
        else { $db->prepare("INSERT INTO acc_company_acct (code,name,bank,account_no,gl_code,note,is_default,active) VALUES (?,?,?,?,?,?,?,?)")
                  ->execute([$code, $name, $bank ?: null, $no, $gl, $note ?: null, $def, $act]); $id = (int)$db->lastInsertId(); }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); return ['success' => false, 'message' => '儲存失敗：' . $e->getMessage()]; }
    return ['success' => true, 'acct_id' => $id, 'message' => '已儲存'];
}

function acc_rm_acct_delete(PDO $db, int $id): array
{
    acc_rm_ensure($db);
    $q = $db->prepare("SELECT COUNT(*) FROM acc_receipt_line WHERE acct_id=? OR deposit_acct_id=?"); $q->execute([$id, $id]);
    if ((int)$q->fetchColumn() > 0) return ['success' => false, 'message' => '此帳戶已被收款單使用，不可刪除（可改為停用）'];
    $db->prepare("DELETE FROM acc_company_acct WHERE acct_id=?")->execute([$id]);
    return ['success' => true, 'message' => '已刪除'];
}

function acc_rm_acct_map(PDO $db): array
{
    $m = []; foreach (acc_rm_accts($db, true) as $a) $m[$a['acct_id']] = $a; return $m;
}

/* ── 收款明細 ─────────────────────────────────────────────── */
/** 某收款單的明細；舊收款單（沒有明細列）由表頭合成一列 */
function acc_rm_lines_of(PDO $db, int $rid): array
{
    acc_rm_ensure($db);
    $q = $db->prepare("SELECT * FROM acc_receipt_line WHERE receipt_id=? ORDER BY seq, line_id"); $q->execute([$rid]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $h = $db->prepare("SELECT method, amount, bank, check_no, check_due FROM acc_receipt WHERE receipt_id=?"); $h->execute([$rid]);
        if ($r = $h->fetch(PDO::FETCH_ASSOC))
            $rows[] = ['line_id' => 0, 'receipt_id' => $rid, 'seq' => 1, 'method' => $r['method'], 'amount' => $r['amount'], 'acct_id' => null,
                       'check_no' => $r['check_no'], 'check_due' => $r['check_due'], 'check_bank' => $r['bank'], 'check_acct' => null,
                       'deposit_date' => null, 'deposit_acct_id' => null, 'legacy' => 1];
    }
    foreach ($rows as &$r) { $r['line_id'] = (int)$r['line_id']; $r['amount'] = (float)$r['amount']; $r['acct_id'] = $r['acct_id'] !== null ? (int)$r['acct_id'] : null; }
    return $rows;
}

/** 檢核輸入的收款明細；回 ['ok','message','lines','total'] */
function acc_rm_lines_check(PDO $db, array $lines): array
{
    $methods = acc_rcpt_methods($db); $accts = acc_rm_acct_map($db);
    $out = []; $total = 0.0; $i = 0;
    foreach ($lines as $l) {
        $i++;
        $m = trim((string)($l['method'] ?? '')); $amt = round((float)($l['amount'] ?? 0), 2);
        if (!in_array($m, $methods, true)) return ['ok' => false, 'message' => "第 {$i} 列：請選擇收款方式"];
        if ($amt <= 0) return ['ok' => false, 'message' => "第 {$i} 列（{$m}）：金額須大於 0"];
        $acct = (int)($l['acct_id'] ?? 0); $ckNo = trim((string)($l['check_no'] ?? '')); $ckDue = trim((string)($l['check_due'] ?? ''));
        if ($m === '匯款') {
            if ($acct <= 0 || empty($accts[$acct]) || !$accts[$acct]['active']) return ['ok' => false, 'message' => "第 {$i} 列（匯款）：請選擇本公司入帳帳戶（尚未設定請先到「傳票管理→科目設定」新增）"];
        } else $acct = 0;
        if ($m === '支票') {
            if ($ckNo === '') return ['ok' => false, 'message' => "第 {$i} 列（支票）：請填支票號碼"];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ckDue)) return ['ok' => false, 'message' => "第 {$i} 列（支票）：請填票期（到期日）"];
        } else { $ckNo = ''; $ckDue = ''; }
        $ckBank = $m === '支票' ? trim((string)($l['check_bank'] ?? '')) : '';
        if ($ckBank !== '') {   // 開票銀行＝7 碼銀行代號（文字儲存，必須在銀行代號表內）
            require_once __DIR__ . '/acc_bank_lib.php';
            if (!preg_match('/^\d{7}$/', $ckBank)) return ['ok' => false, 'message' => "第 {$i} 列（支票）：開票銀行代號須為 7 碼數字"];
            if (!acc_bank_lookup($db, $ckBank)) return ['ok' => false, 'message' => "第 {$i} 列（支票）：查無開票銀行代號 {$ckBank}"];
        }
        $out[] = ['line_id' => (int)($l['line_id'] ?? 0), 'method' => $m, 'amount' => $amt, 'acct_id' => $acct ?: null,
                  'check_no' => $ckNo ?: null, 'check_due' => $ckDue ?: null,
                  'check_bank' => $ckBank !== '' ? $ckBank : null,
                  'check_acct' => $m === '支票' ? (trim((string)($l['check_acct'] ?? '')) ?: null) : null];
        $total += $amt;
    }
    if (!$out) return ['ok' => false, 'message' => '請至少填一筆收款明細'];
    return ['ok' => true, 'lines' => $out, 'total' => round($total, 2)];
}

/** 由明細組表頭摘要欄位 */
function acc_rm_header(PDO $db, array $lines): array
{
    $ms = array_values(array_unique(array_column($lines, 'method'))); $accts = acc_rm_acct_map($db);
    $h = ['method' => count($ms) === 1 ? $ms[0] : '混合', 'bank' => '', 'check_no' => '', 'check_due' => '', 'remit_account' => ''];
    foreach ($lines as $l) {
        if ($l['method'] === '支票') {
            if ($h['check_no'] === '') {
                $h['check_no'] = (string)$l['check_no'];
                $bk = (string)($l['check_bank'] ?? '');
                if ($bk !== '') { require_once __DIR__ . '/acc_bank_lib.php'; $b = acc_bank_lookup($db, $bk); $h['bank'] = $b ? $b['name'] : $bk; }
            }
            if ($h['check_due'] === '' || $l['check_due'] < $h['check_due']) $h['check_due'] = (string)$l['check_due'];
        }
        if ($l['method'] === '匯款' && $h['remit_account'] === '' && isset($accts[$l['acct_id']])) {
            $h['remit_account'] = (string)$accts[$l['acct_id']]['account_no'];
            if ($h['bank'] === '') $h['bank'] = (string)$accts[$l['acct_id']]['bank'];
        }
    }
    return $h;
}

/** 已開未作廢的收款傳票 → 有就不可改收款單／沖帳 */
function acc_rm_voucher_lock(PDO $db, int $rid): ?string
{
    if ($rid <= 0) return null;
    acc_rm_ensure($db);
    $q = $db->prepare("SELECT voucher_no FROM acc_rcpt_voucher WHERE receipt_id=? AND kind='RCPT' AND status='ok' LIMIT 1"); $q->execute([$rid]);
    if (($r = $q->fetch(PDO::FETCH_NUM)) !== false) return '此收款單已開收款傳票' . ($r[0] ? ' ' . $r[0] : '') . '，請先作廢傳票再修改';
    return null;
}

/** 混合收款存檔（表頭＋明細）；$d 與舊 rcpt_save 相同，另帶 lines[] */
function acc_rm_receipt_save(PDO $db, array $d, string $uid): array
{
    acc_rm_ensure($db);
    $id = (int)($d['receipt_id'] ?? 0);
    $legacy = false;
    if ($id > 0) {
        if ($lk = acc_rm_voucher_lock($db, $id)) return ['success' => false, 'message' => $lk];
        $q = $db->prepare("SELECT settle_extra FROM acc_receipt WHERE receipt_id=?"); $q->execute([$id]);
        $legacy = ((int)$q->fetchColumn() === 0);
    }
    $lc = acc_rm_lines_check($db, is_array($d['lines'] ?? null) ? $d['lines'] : []);
    if (!$lc['ok']) return ['success' => false, 'message' => $lc['message']];
    $hd = acc_rm_header($db, $lc['lines']);
    $due = ($d['due_amount'] ?? '') === '' ? null : round((float)$d['due_amount'], 2);
    $pct = ($d['deduct_pct'] ?? '') === '' ? null : round((float)$d['deduct_pct'], 3);
    $ded = round((float)($d['deduct_amt'] ?? 0), 2); $fee = round((float)($d['fee'] ?? 0), 2);
    if ($ded < 0 || $fee < 0) return ['success' => false, 'message' => '扣款與手續費不可為負數'];
    if ($pct !== null && ($pct < 0 || $pct > ACC_RCPT_DEDUCT_MAX)) return ['success' => false, 'message' => '扣%最多 ' . ACC_RCPT_DEDUCT_MAX . '%'];
    if (!$legacy && $due !== null && abs(($due - $ded - $fee) - $lc['total']) > 0.5)
        return ['success' => false, 'message' => '金額不符：應收 ' . number_format($due) . ' － 扣款 ' . number_format($ded) . ' － 匯費 ' . number_format($fee)
                . ' ＝ ' . number_format($due - $ded - $fee) . '，與各筆收款合計 ' . number_format($lc['total']) . ' 不同'];
    $warn = [];
    if ($ded > 0) { $t = acc_rcpt_cust_terms($db, trim((string)($d['customer_name'] ?? ''))); if (!(int)$t['allow_deduct']) $warn[] = '此客戶在主檔未勾選「接受扣%」，請確認扣款是否已與客戶約定'; }

    $core = ['receipt_id' => $id, 'customer_name' => $d['customer_name'] ?? '', 'customer_id' => $d['customer_id'] ?? '',
             'receipt_date' => $d['receipt_date'] ?? '', 'method' => $hd['method'], 'amount' => $lc['total'], 'fee' => $fee,
             'bank' => $hd['bank'], 'check_no' => $hd['check_no'], 'check_due' => $hd['check_due'], 'note' => $d['note'] ?? ''];
    $r = acc_receipt_save($db, $core, $uid);
    if (!$r['success']) return $r;
    $rid = (int)$r['receipt_id'];
    try {
        $db->beginTransaction();
        $ex = []; $q = $db->prepare("SELECT * FROM acc_receipt_line WHERE receipt_id=?"); $q->execute([$rid]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $ex[(int)$x['line_id']] = $x;
        $keep = [];
        foreach ($lc['lines'] as $seq => $l) {
            $lid = $l['line_id'];
            if ($lid > 0 && isset($ex[$lid])) {
                if ($ex[$lid]['deposit_date'] && ($ex[$lid]['method'] !== $l['method'] || abs((float)$ex[$lid]['amount'] - $l['amount']) > 0.005))
                    throw new Exception('支票 ' . $ex[$lid]['check_no'] . ' 已確認入帳，不可改方式或金額（請先取消入帳確認）');
                $db->prepare("UPDATE acc_receipt_line SET seq=?,method=?,amount=?,acct_id=?,check_no=?,check_due=?,check_bank=?,check_acct=? WHERE line_id=?")
                   ->execute([$seq + 1, $l['method'], $l['amount'], $l['acct_id'], $l['check_no'], $l['check_due'], $l['check_bank'], $l['check_acct'], $lid]);
                $keep[$lid] = 1;
            } else {
                $db->prepare("INSERT INTO acc_receipt_line (receipt_id,seq,method,amount,acct_id,check_no,check_due,check_bank,check_acct) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$rid, $seq + 1, $l['method'], $l['amount'], $l['acct_id'], $l['check_no'], $l['check_due'], $l['check_bank'], $l['check_acct']]);
                $keep[(int)$db->lastInsertId()] = 1;
            }
        }
        foreach ($ex as $lid => $x) if (!isset($keep[$lid])) {
            if ($x['deposit_date']) throw new Exception('支票 ' . $x['check_no'] . ' 已確認入帳，不可刪除這筆明細');
            $db->prepare("DELETE FROM acc_receipt_line WHERE line_id=?")->execute([$lid]);
        }
        if (!$legacy) $db->prepare("UPDATE acc_receipt SET remit_account=?, deduct_pct=?, deduct_amt=?, due_amount=?, settle_extra=1 WHERE receipt_id=?")
                         ->execute([$hd['remit_account'] ?: null, $pct, $ded, $due, $rid]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); return ['success' => false, 'message' => '收款明細儲存失敗：' . $e->getMessage()]; }
    if ($warn) $r['message'] .= '（' . implode('；', $warn) . '）';
    return $r;
}

/** 清單附加：每張收款單的明細摘要、待確認入帳的支票數、傳票號碼 */
function acc_rm_attach(PDO $db, array &$rows): void
{
    if (!$rows) return;
    acc_rm_ensure($db);
    $ids = array_map(fn($r) => (int)$r['receipt_id'], $rows); $ph = implode(',', array_fill(0, count($ids), '?'));
    $by = [];
    $q = $db->prepare("SELECT * FROM acc_receipt_line WHERE receipt_id IN ($ph) ORDER BY receipt_id, seq, line_id"); $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) $by[(int)$l['receipt_id']][] = $l;
    $vv = [];
    $q = $db->prepare("SELECT receipt_id, kind, voucher_no FROM acc_rcpt_voucher WHERE receipt_id IN ($ph) AND status='ok'"); $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) $vv[(int)$v['receipt_id']][$v['kind']][] = $v['voucher_no'];
    foreach ($rows as &$r) {
        $ls = $by[(int)$r['receipt_id']] ?? [];
        $txt = []; $pend = 0;
        foreach ($ls as $l) {
            $txt[] = $l['method'] . ' ' . number_format((float)$l['amount']);
            if ($l['method'] === '支票' && !$l['deposit_date']) $pend++;
        }
        $r['lines_txt'] = $txt ? implode('＋', $txt) : (string)$r['method'];
        $r['line_cnt'] = count($ls);
        $r['pending_deposit'] = $pend;
        $r['has_rcpt_vch'] = !empty($vv[(int)$r['receipt_id']]['RCPT']) ? 1 : 0;
        $r['rcpt_vch_no'] = implode('、', array_filter($vv[(int)$r['receipt_id']]['RCPT'] ?? []));
    }
    unset($r);
}

/* ── 支票兌現入帳確認 ───────────────────────────────────────── */
function acc_rm_deposit_set(PDO $db, int $lineId, ?string $date, int $acctId, string $uid): array
{
    acc_rm_ensure($db);
    $q = $db->prepare("SELECT * FROM acc_receipt_line WHERE line_id=?"); $q->execute([$lineId]);
    $l = $q->fetch(PDO::FETCH_ASSOC);
    if (!$l || $l['method'] !== '支票') return ['success' => false, 'message' => '只有支票需要確認入帳'];
    if ($date === null) {   // 取消入帳確認
        $v = $db->prepare("SELECT voucher_no FROM acc_rcpt_voucher WHERE line_id=? AND kind='DEPOSIT' AND status='ok' LIMIT 1"); $v->execute([$lineId]);
        if (($x = $v->fetch(PDO::FETCH_NUM)) !== false) return ['success' => false, 'message' => '已開入帳傳票' . ($x[0] ? ' ' . $x[0] : '') . '，請先作廢傳票'];
        $db->prepare("UPDATE acc_receipt_line SET deposit_date=NULL, deposit_acct_id=NULL, deposit_by=NULL, deposit_at=NULL WHERE line_id=?")->execute([$lineId]);
        return ['success' => true, 'message' => '已取消入帳確認'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['success' => false, 'message' => '入帳日格式錯誤'];
    if ($date > date('Y-m-d')) return ['success' => false, 'message' => '入帳日不可是未來日期'];
    $accts = acc_rm_acct_map($db);
    if ($acctId <= 0 || empty($accts[$acctId])) return ['success' => false, 'message' => '請選擇兌現存入的本公司帳戶'];
    $db->prepare("UPDATE acc_receipt_line SET deposit_date=?, deposit_acct_id=?, deposit_by=?, deposit_at=NOW() WHERE line_id=?")->execute([$date, $acctId, $uid, $lineId]);
    return ['success' => true, 'message' => '已確認入帳'];
}

/* ── 傳票 ─────────────────────────────────────────────────── */
function acc_rm_name_of(PDO $db, string $code, string $dflt): string
{
    $q = $db->prepare("SELECT name FROM acc_account WHERE code=?"); $q->execute([$code]);
    $n = (string)$q->fetchColumn(); return $n !== '' ? $n : $dflt;
}

/** 應收帳款貸方：沖帳的發票明細逐單號列出（部分沖帳依比例攤到單號，最後一列吃尾差） */
function acc_rm_ar_lines(PDO $db, int $rid, string $cust): array
{
    $out = []; $sum = 0.0;
    foreach (acc_receipt_allocs($db, $rid) as $a) {
        $alloc = round((float)$a['amount'], 2); if (abs($alloc) < 0.005) continue;
        $q = $db->prepare("SELECT src_type, src_no, spec, amount FROM acc_invoice_item WHERE invoice_id=? ORDER BY (src_type='DISC'), src_date, item_id");
        $q->execute([(int)$a['invoice_id']]);
        $items = $q->fetchAll(PDO::FETCH_ASSOC);
        $base = 0.0; foreach ($items as $it) $base += (float)$it['amount'];
        if (!$items || abs($base) < 0.005) { $out[] = ['amt' => $alloc, 'memo' => '沖發票 ' . $a['invoice_no'] . ' (' . $cust . ')']; $sum += $alloc; continue; }
        $left = $alloc; $n = count($items);
        foreach ($items as $k => $it) {
            $part = ($k === $n - 1) ? round($left, 2) : round($alloc * (float)$it['amount'] / $base, 2);
            $left = round($left - $part, 2);
            if (abs($part) < 0.005) continue;
            $label = $it['src_type'] === 'DISC' ? '沖' . $it['spec'] : '沖' . $it['src_no'];
            $out[] = ['amt' => $part, 'memo' => $label . ' (' . $cust . ') ' . $a['invoice_no']];
        }
        $sum += $alloc;
    }
    return [$out, round($sum, 2)];
}

/** 收款傳票分錄（預覽用，不寫入） */
function acc_rm_voucher_build(PDO $db, int $rid): array
{
    acc_rm_ensure($db); $G = acc_rm_gl();
    $q = $db->prepare("SELECT * FROM acc_receipt WHERE receipt_id=?"); $q->execute([$rid]);
    $rc = $q->fetch(PDO::FETCH_ASSOC);
    if (!$rc) return ['success' => false, 'message' => '找不到收款單'];
    $cust = (string)$rc['customer_name']; $accts = acc_rm_acct_map($db);
    $L = [];
    foreach (acc_rm_lines_of($db, $rid) as $l) {
        if ($l['method'] === '現金') { $c = $G['cash']; $memo = $cust . ' 現金'; }
        elseif ($l['method'] === '支票') { $c = $G['notes']; $memo = $cust . ' 支票 ' . $l['check_no'] . ' 票期 ' . $l['check_due']; }
        elseif ($l['method'] === '匯款' && isset($accts[$l['acct_id']])) { $a = $accts[$l['acct_id']]; $c = [$a['gl_code'], acc_rm_name_of($db, $a['gl_code'], $a['name'])]; $memo = $cust . ' 匯入 ' . $a['name']; }
        else { $c = $G['bank']; $memo = $cust . ' ' . $l['method']; }
        $L[] = ['code' => $c[0], 'name' => acc_rm_name_of($db, $c[0], $c[1]), 'side' => 'D', 'amount' => round((float)$l['amount'], 2), 'memo' => $memo];
    }
    $ded = round((float)($rc['deduct_amt'] ?? 0), 2); $fee = round((float)$rc['fee'], 2);
    if ($ded > 0) $L[] = ['code' => $G['disc'][0], 'name' => acc_rm_name_of($db, $G['disc'][0], $G['disc'][1]), 'side' => 'D', 'amount' => $ded,
                          'memo' => $cust . ' 客戶扣款' . ((float)$rc['deduct_pct'] > 0 ? ' ' . acc_disc_pct_txt($rc['deduct_pct']) . '%' : '')];
    if ($fee > 0) $L[] = ['code' => $G['fee'][0], 'name' => acc_rm_name_of($db, $G['fee'][0], $G['fee'][1]), 'side' => 'D', 'amount' => $fee, 'memo' => $cust . ' 匯費'];
    [$ar, $arSum] = acc_rm_ar_lines($db, $rid, $cust);
    foreach ($ar as $x) $L[] = ['code' => $G['ar'][0], 'name' => acc_rm_name_of($db, $G['ar'][0], $G['ar'][1]),
                               'side' => $x['amt'] >= 0 ? 'C' : 'D', 'amount' => abs($x['amt']), 'memo' => $x['memo']];
    $d = 0.0; $c = 0.0; foreach ($L as $l) { if ($l['side'] === 'D') $d += $l['amount']; else $c += $l['amount']; }
    $hold = round($d - $c, 2);
    if ($hold > 0.004) $L[] = ['code' => $G['hold'][0], 'name' => acc_rm_name_of($db, $G['hold'][0], $G['hold'][1]), 'side' => 'C', 'amount' => $hold, 'memo' => $cust . ' 未沖帳餘額（暫收款）'];
    $d2 = 0.0; $c2 = 0.0; foreach ($L as $l) { if ($l['side'] === 'D') $d2 += $l['amount']; else $c2 += $l['amount']; }
    return ['success' => true, 'lines' => $L, 'debit' => round($d2, 2), 'credit' => round($c2, 2), 'balanced' => abs($d2 - $c2) < 0.01,
            'customer' => $cust, 'receipt_no' => $rc['receipt_no'], 'receipt_date' => $rc['receipt_date']];
}

/** 支票兌現入帳傳票分錄 */
function acc_rm_deposit_build(PDO $db, int $lineId): array
{
    acc_rm_ensure($db); $G = acc_rm_gl();
    $q = $db->prepare("SELECT l.*, r.customer_name, r.receipt_no FROM acc_receipt_line l JOIN acc_receipt r ON r.receipt_id=l.receipt_id WHERE l.line_id=?"); $q->execute([$lineId]);
    $l = $q->fetch(PDO::FETCH_ASSOC);
    if (!$l || $l['method'] !== '支票') return ['success' => false, 'message' => '找不到支票明細'];
    if (!$l['deposit_date']) return ['success' => false, 'message' => '請先確認入帳（入帳日與存入帳戶）'];
    $accts = acc_rm_acct_map($db); $a = $accts[(int)$l['deposit_acct_id']] ?? null;
    if (!$a) return ['success' => false, 'message' => '入帳帳戶已不存在，請重新確認入帳'];
    $amt = round((float)$l['amount'], 2); $memo = $l['customer_name'] . ' 支票 ' . $l['check_no'] . ' 兌現';
    $L = [['code' => $a['gl_code'], 'name' => acc_rm_name_of($db, $a['gl_code'], $a['name']), 'side' => 'D', 'amount' => $amt, 'memo' => $memo . '（' . $a['name'] . '）'],
          ['code' => $G['notes'][0], 'name' => acc_rm_name_of($db, $G['notes'][0], $G['notes'][1]), 'side' => 'C', 'amount' => $amt, 'memo' => $memo]];
    return ['success' => true, 'lines' => $L, 'debit' => $amt, 'credit' => $amt, 'balanced' => true, 'customer' => $l['customer_name'],
            'receipt_no' => $l['receipt_no'], 'receipt_date' => $l['deposit_date'], 'check_no' => $l['check_no']];
}

function acc_rm_vouchers(PDO $db, int $rid): array
{
    acc_rm_ensure($db);
    $q = $db->prepare("SELECT rv_id, kind, line_id, voucher_no, voucher_date, total_amount, status, lines_json FROM acc_rcpt_voucher WHERE receipt_id=? ORDER BY rv_id"); $q->execute([$rid]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['lines'] = json_decode((string)$r['lines_json'], true) ?: []; unset($r['lines_json']); }
    return $rows;
}

/** 以當下資料重算分錄後存成傳票（不信前端分錄）；ERP 傳票號碼規則與銷貨傳票相同 */
function acc_rm_voucher_save(PDO $db, string $kind, int $rid, int $lineId, string $no, string $date, string $uname): array
{
    acc_rm_ensure($db);
    require_once __DIR__ . '/acc_voucher_lib.php';
    acc_voucher_ensure($db);
    if (!in_array($kind, ['RCPT', 'DEPOSIT'], true)) return ['success' => false, 'message' => '傳票種類錯誤'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['success' => false, 'message' => '傳票日期格式錯誤'];
    $no = acc_vch_no_normalize($no, $date);
    if ($no === '') return ['success' => false, 'message' => '請先填 ERP 傳票號碼'];
    [$er, $nd] = acc_vch_no_check($no);
    if ($er) return ['success' => false, 'message' => $er];
    if ($nd !== $date) return ['success' => false, 'message' => '傳票號碼裡的日期（' . $nd . '）與傳票日期（' . $date . '）不一致'];
    $q = $db->prepare("SELECT 1 FROM acc_voucher WHERE voucher_no=? UNION SELECT 1 FROM acc_rcpt_voucher WHERE voucher_no=? AND status='ok' LIMIT 1"); $q->execute([$no, $no]);
    if ($q->fetchColumn()) return ['success' => false, 'message' => '傳票號碼 ' . $no . ' 已存在'];
    $b = $kind === 'RCPT' ? acc_rm_voucher_build($db, $rid) : acc_rm_deposit_build($db, $lineId);
    if (!$b['success']) return $b;
    if (!$b['balanced']) return ['success' => false, 'message' => '借貸不平衡：借 ' . number_format($b['debit'], 2) . '／貸 ' . number_format($b['credit'], 2)];
    if ($kind === 'DEPOSIT') {
        $q = $db->prepare("SELECT receipt_id FROM acc_receipt_line WHERE line_id=?"); $q->execute([$lineId]); $rid = (int)$q->fetchColumn();
    } else $lineId = 0;
    $q = $db->prepare("SELECT voucher_no FROM acc_rcpt_voucher WHERE kind=? AND receipt_id=? AND line_id=? AND status='ok' LIMIT 1"); $q->execute([$kind, $rid, $lineId]);
    if (($x = $q->fetch(PDO::FETCH_NUM)) !== false) return ['success' => false, 'message' => '已開過傳票 ' . $x[0] . '，要重開請先作廢'];
    foreach ($b['lines'] as $l) acc_vch_account_upsert($db, $l['code'], $l['name']);
    $db->prepare("INSERT INTO acc_rcpt_voucher (kind,receipt_id,line_id,voucher_no,voucher_date,total_amount,lines_json,created_by) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$kind, $rid, $lineId, $no, $date, $b['debit'], json_encode($b['lines'], JSON_UNESCAPED_UNICODE), $uname]);
    return ['success' => true, 'message' => '已建立' . ($kind === 'RCPT' ? '收款傳票 ' : '入帳傳票 ') . $no];
}

function acc_rm_voucher_void(PDO $db, int $rvId): array
{
    acc_rm_ensure($db);
    $db->prepare("UPDATE acc_rcpt_voucher SET status='void', voucher_no=LEFT(CONCAT(voucher_no,'~廢',rv_id),20) WHERE rv_id=? AND status='ok'")->execute([$rvId]);
    return ['success' => true, 'message' => '已作廢傳票，可重新開立'];
}
