<?php
/**
 * 傳票（請款）— 唯一實作。
 *
 * 一張傳票 ＝ 選定的一批出貨單／退貨單明細（應付側＝加工移轉憑單明細）。
 * 傳票號碼是 ERP 傳票號碼（可建立時先空著、事後補填，補填後不可重複）。
 * 有傳票的明細視為「已做帳（請款）」：金額、數量、料號一律鎖定，
 * 其他功能（ERP 對帳單匯入、對帳底稿調整…）要改之前先用 acc_voucher_src_map() 檢查。
 * 作廢傳票會釋放其明細（可重新開立）。
 */
require_once __DIR__ . '/acc_lib.php';

function acc_voucher_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS acc_voucher (
        voucher_id   INT AUTO_INCREMENT PRIMARY KEY,
        voucher_no   VARCHAR(40) NULL COMMENT 'ERP傳票號碼（可事後補填）',
        voucher_date DATE NOT NULL,
        side         VARCHAR(2) NOT NULL DEFAULT 'ar' COMMENT 'ar=應收(客戶) ap=應付(廠商)',
        party_id     VARCHAR(60) NULL,
        party_name   VARCHAR(100) NOT NULL,
        billing_month CHAR(7) NULL,
        total_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
        item_cnt     INT NOT NULL DEFAULT 0,
        note         VARCHAR(300) NULL,
        status       VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open/void',
        void_reason  VARCHAR(200) NULL,
        created_by   VARCHAR(30) NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        modified_by  VARCHAR(30) NULL,
        modified_at  DATETIME NULL,
        UNIQUE KEY uk_vno (voucher_no),
        KEY idx_party (party_name), KEY idx_bm (billing_month), KEY idx_date (voucher_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='傳票（請款）'");
    $have = [];
    foreach ($db->query("SHOW COLUMNS FROM acc_voucher")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = 1;
    if (!isset($have['void_items_json']))
        $db->exec("ALTER TABLE acc_voucher ADD COLUMN void_items_json MEDIUMTEXT NULL COMMENT '作廢當下的明細快照（作廢後明細釋放，保留紀錄）'");
    $db->exec("CREATE TABLE IF NOT EXISTS acc_voucher_item (
        item_id    INT AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT NOT NULL,
        src_type   VARCHAR(8) NOT NULL COMMENT 'IS/IR/TLOG…',
        src_id     INT NOT NULL,
        src_no     VARCHAR(40) NULL,
        src_date   DATE NULL,
        product_id VARCHAR(80) NULL,
        spec       VARCHAR(200) NULL,
        qty        DECIMAL(14,4) NOT NULL DEFAULT 0,
        unit_price DECIMAL(14,4) NOT NULL DEFAULT 0,
        amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uk_src (src_type, src_id),
        KEY idx_v (voucher_id), KEY idx_no (src_no),
        CONSTRAINT fk_voucher_item FOREIGN KEY (voucher_id) REFERENCES acc_voucher(voucher_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='傳票明細'");
}

/** 已被「未作廢傳票」佔用的來源：'IS-123' => [voucher_id, voucher_no, voucher_date] */
function acc_voucher_src_map(PDO $db): array
{
    acc_voucher_ensure($db);
    $m = [];
    $st = $db->query("SELECT i.src_type, i.src_id, v.voucher_id, v.voucher_no, v.voucher_date
                      FROM acc_voucher_item i JOIN acc_voucher v ON v.voucher_id=i.voucher_id
                      WHERE v.status<>'void'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
        $m[strtoupper($r['src_type']) . '-' . (int)$r['src_id']] = [
            'voucher_id' => (int)$r['voucher_id'], 'voucher_no' => $r['voucher_no'], 'voucher_date' => $r['voucher_date']];
    return $m;
}

/** 某一筆來源有沒有被傳票鎖住；有就回傳票資訊 */
function acc_voucher_locked(PDO $db, string $srcType, int $srcId): ?array
{
    acc_voucher_ensure($db);
    $st = $db->prepare("SELECT v.voucher_id, v.voucher_no, v.voucher_date
                        FROM acc_voucher_item i JOIN acc_voucher v ON v.voucher_id=i.voucher_id
                        WHERE i.src_type=? AND i.src_id=? AND v.status<>'void' LIMIT 1");
    $st->execute([strtoupper($srcType), $srcId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 某客戶／廠商某帳款月份可開傳票的明細（已被傳票佔用的標 locked，仍列出供查看） */
function acc_voucher_candidates(PDO $db, string $side, string $partyId, string $bm): array
{
    $side = ($side === 'ap') ? 'ap' : 'ar';
    $b = acc_sheet_build($db, $side, $partyId, $bm);
    /* 這個對象這個月若有「已確認鎖帳」的對帳單，只列對帳單裡有的憑證（出貨＋退貨），
       與發票開立所用的資料一致；沒有鎖帳的對帳單才用來源憑證。金額 0 的不開傳票。 */
    $cs = acc_sheet_get($db, $side, $partyId, $bm);
    if ($cs && ($cs['status'] ?? '') === 'confirmed') {
        $in = [];
        foreach ($cs['lines'] as $sl) if ((int)$sl['src_id'] > 0) $in[strtoupper((string)$sl['src_type']) . '-' . (int)$sl['src_id']] = true;
        $b['lines'] = array_values(array_filter($b['lines'], fn($l) => isset($in[strtoupper($l['src_type']) . '-' . (int)$l['src_id']])));
    }
    $b['lines'] = array_values(array_filter($b['lines'], fn($l) => abs((float)$l['orig_amount']) >= 0.0001));
    $vmap = acc_voucher_src_map($db);
    $imap = ($side === 'ar') ? acc_invoiced_src_map($db) : [];
    $items = [];
    foreach ($b['lines'] as $l) {
        $k = strtoupper($l['src_type']) . '-' . (int)$l['src_id'];
        $items[] = [
            'src_type' => $l['src_type'], 'src_id' => (int)$l['src_id'], 'src_no' => $l['doc_no'], 'src_date' => $l['doc_date'],
            'product_id' => $l['product_id'], 'spec' => $l['spec'], 'qty' => (float)$l['orig_qty'],
            'unit_price' => (float)$l['orig_price'], 'amount' => (float)$l['orig_amount'],
            'voucher_no' => isset($vmap[$k]) ? (string)$vmap[$k]['voucher_no'] : null,
            'voucher_id' => $vmap[$k]['voucher_id'] ?? null,
            'locked' => isset($vmap[$k]),
            'invoice_no' => $imap[$k]['invoice_no'] ?? null,
            'invoiced' => isset($imap[$k]),
        ];
    }
    return ['party_name' => $b['party_name'], 'items' => $items];
}

/** 建立傳票。$sel＝[{src_type,src_id}]；明細內容一律以當下資料庫重算，不信前端金額 */
function acc_voucher_create(PDO $db, string $side, string $partyId, string $bm, string $date, ?string $no,
                            string $note, array $sel, string $userName): array
{
    acc_voucher_ensure($db);   // DDL 在交易外
    $side = ($side === 'ap') ? 'ap' : 'ar';
    if (!preg_match('/^\d{4}-\d{2}$/', $bm)) return ['success' => false, 'message' => '帳款月份格式錯誤'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['success' => false, 'message' => '傳票日期格式錯誤'];
    $no = trim((string)$no);
    if ($no !== '' && mb_strlen($no) > 40) return ['success' => false, 'message' => '傳票號碼過長'];
    if (!$sel) return ['success' => false, 'message' => '沒有選取明細'];

    $cand = acc_voucher_candidates($db, $side, $partyId, $bm);
    $by = [];
    foreach ($cand['items'] as $it) $by[strtoupper($it['src_type']) . '-' . $it['src_id']] = $it;
    $pick = []; $skip = [];
    foreach ($sel as $s) {
        $k = strtoupper((string)($s['src_type'] ?? '')) . '-' . (int)($s['src_id'] ?? 0);
        if (!isset($by[$k])) { $skip[] = $k . '：不在此帳款月份'; continue; }
        if ($by[$k]['locked']) { $skip[] = ($by[$k]['src_no']) . '：已有傳票 ' . $by[$k]['voucher_no']; continue; }
        $pick[$k] = $by[$k];
    }
    if (!$pick) return ['success' => false, 'message' => '沒有可開傳票的明細' . ($skip ? '（' . implode('；', $skip) . '）' : '')];
    if ($no !== '') {
        $q = $db->prepare("SELECT voucher_id FROM acc_voucher WHERE voucher_no=? LIMIT 1");
        $q->execute([$no]);
        if ($q->fetchColumn()) return ['success' => false, 'message' => '傳票號碼 ' . $no . ' 已存在'];
    }
    $total = 0.0;
    foreach ($pick as $it) $total += $it['amount'];
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO acc_voucher (voucher_no,voucher_date,side,party_id,party_name,billing_month,total_amount,item_cnt,note,created_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$no === '' ? null : $no, $date, $side, $partyId, $cand['party_name'], $bm, $total, count($pick), $note, $userName]);
        $vid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO acc_voucher_item (voucher_id,src_type,src_id,src_no,src_date,product_id,spec,qty,unit_price,amount)
                             VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($pick as $it)
            $ins->execute([$vid, strtoupper($it['src_type']), $it['src_id'], $it['src_no'], $it['src_date'] ?: null,
                           $it['product_id'], mb_substr((string)$it['spec'], 0, 200), $it['qty'], $it['unit_price'], $it['amount']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立傳票失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'voucher_id' => $vid, 'items' => count($pick), 'total' => $total, 'skipped' => $skip,
            'message' => '已建立傳票，' . count($pick) . ' 筆、合計 ' . number_format($total)];
}

function acc_voucher_list(PDO $db, array $f): array
{
    acc_voucher_ensure($db);
    $w = []; $p = [];
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $w[] = "(v.voucher_no LIKE ? OR v.party_name LIKE ? OR v.note LIKE ?
                 OR EXISTS (SELECT 1 FROM acc_voucher_item i WHERE i.voucher_id=v.voucher_id AND (i.src_no LIKE ? OR i.product_id LIKE ?))
                 OR EXISTS (SELECT 1 FROM acc_invoice_item ii JOIN acc_invoice inv ON inv.invoice_id=ii.invoice_id
                            JOIN acc_voucher_item i2 ON i2.src_type=ii.src_type AND i2.src_id=ii.src_id
                            WHERE i2.voucher_id=v.voucher_id AND inv.invoice_no LIKE ?))";
        for ($i = 0; $i < 6; $i++) $p[] = '%' . $kw . '%';
    }
    foreach (['bm_from' => 'v.billing_month >= ?', 'bm_to' => 'v.billing_month <= ?'] as $k => $c)
        if (preg_match('/^\d{4}-\d{2}$/', (string)($f[$k] ?? ''))) { $w[] = $c; $p[] = $f[$k]; }
    $st = trim((string)($f['status'] ?? ''));
    if ($st === 'open' || $st === 'void') { $w[] = 'v.status=?'; $p[] = $st; }
    if (($f['no_number'] ?? '') === '1') $w[] = "(v.voucher_no IS NULL OR v.voucher_no='')";
    $side = trim((string)($f['side'] ?? ''));
    if ($side === 'ar' || $side === 'ap') { $w[] = 'v.side=?'; $p[] = $side; }
    $ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $per = (int)($f['per_page'] ?? 20);
    if (!in_array($per, [5, 10, 20, 50], true)) $per = 20;
    $page = max(1, (int)($f['page'] ?? 1));
    $c = $db->prepare("SELECT COUNT(*) FROM acc_voucher v $ws"); $c->execute($p);
    $total = (int)$c->fetchColumn();
    $q = $db->prepare("SELECT v.* FROM acc_voucher v $ws ORDER BY v.voucher_date DESC, v.voucher_id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $q->execute($p);
    return ['rows' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per_page' => $per];
}

function acc_voucher_get(PDO $db, int $id): ?array
{
    acc_voucher_ensure($db);
    $q = $db->prepare("SELECT * FROM acc_voucher WHERE voucher_id=?");
    $q->execute([$id]);
    $v = $q->fetch(PDO::FETCH_ASSOC);
    if (!$v) return null;
    if ($v['status'] === 'void' && !empty($v['void_items_json'])) {          // 作廢後明細已釋放，顯示當時的快照
        $snap = json_decode($v['void_items_json'], true);
        return ['voucher' => $v, 'items' => is_array($snap) ? $snap : []];
    }
    $q = $db->prepare("SELECT i.*, (SELECT GROUP_CONCAT(DISTINCT inv.invoice_no) FROM acc_invoice_item ii
                                    JOIN acc_invoice inv ON inv.invoice_id=ii.invoice_id AND inv.status<>'void'
                                    WHERE ii.src_type=i.src_type AND ii.src_id=i.src_id) AS invoice_no
                       FROM acc_voucher_item i WHERE i.voucher_id=? ORDER BY i.src_date, i.src_no, i.item_id");
    $q->execute([$id]);
    return ['voucher' => $v, 'items' => $q->fetchAll(PDO::FETCH_ASSOC)];
}

/** 修改傳票號碼／日期／備註（明細不可改） */
function acc_voucher_update(PDO $db, int $id, ?string $no, string $date, string $note, string $userName): array
{
    acc_voucher_ensure($db);
    $v = acc_voucher_get($db, $id);
    if (!$v) return ['success' => false, 'message' => '找不到傳票'];
    if ($v['voucher']['status'] === 'void') return ['success' => false, 'message' => '已作廢的傳票不可修改'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['success' => false, 'message' => '傳票日期格式錯誤'];
    $no = trim((string)$no);
    if (mb_strlen($no) > 40) return ['success' => false, 'message' => '傳票號碼過長'];
    if ($no !== '') {
        $q = $db->prepare("SELECT voucher_id FROM acc_voucher WHERE voucher_no=? AND voucher_id<>? LIMIT 1");
        $q->execute([$no, $id]);
        if ($q->fetchColumn()) return ['success' => false, 'message' => '傳票號碼 ' . $no . ' 已被其他傳票使用'];
    }
    $db->prepare("UPDATE acc_voucher SET voucher_no=?, voucher_date=?, note=?, modified_by=?, modified_at=NOW() WHERE voucher_id=?")
       ->execute([$no === '' ? null : $no, $date, $note, $userName, $id]);
    return ['success' => true, 'message' => '已儲存'];
}

function acc_voucher_void(PDO $db, int $id, string $reason, string $userName): array
{
    acc_voucher_ensure($db);
    $reason = trim($reason);
    if ($reason === '') return ['success' => false, 'message' => '請填寫作廢原因'];
    $v = acc_voucher_get($db, $id);
    if (!$v) return ['success' => false, 'message' => '找不到傳票'];
    if ($v['voucher']['status'] === 'void') return ['success' => false, 'message' => '此傳票已作廢'];
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE acc_voucher SET status='void', void_reason=?, void_items_json=?, modified_by=?, modified_at=NOW() WHERE voucher_id=?")
           ->execute([mb_substr($reason, 0, 200), json_encode($v['items'], JSON_UNESCAPED_UNICODE), $userName, $id]);
        // 釋放明細：唯一鍵是 (來源類型, 來源id)，不釋放就沒辦法重新開傳票
        $db->prepare("DELETE FROM acc_voucher_item WHERE voucher_id=?")->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '作廢失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'message' => '已作廢，明細已釋放'];
}
