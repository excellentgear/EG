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
    if (!isset($have['sign_json']))
        $db->exec("ALTER TABLE acc_voucher ADD COLUMN sign_json TEXT NULL COMMENT '簽章欄位（核准／會計／覆核／出納／登帳）JSON'");
    if (!isset($have['void_items_json']))
        $db->exec("ALTER TABLE acc_voucher ADD COLUMN void_items_json MEDIUMTEXT NULL COMMENT '作廢當下的明細快照（作廢後明細釋放，保留紀錄）'");
    $db->exec("CREATE TABLE IF NOT EXISTS acc_account (
        code   VARCHAR(12) NOT NULL PRIMARY KEY,
        name   VARCHAR(60) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計科目（借貸方向由代號首碼判定）'");
    $db->exec("CREATE TABLE IF NOT EXISTS acc_voucher_line (
        line_id    INT AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT NOT NULL,
        seq        INT NOT NULL DEFAULT 0,
        code       VARCHAR(12) NOT NULL,
        name       VARCHAR(60) NOT NULL,
        side       CHAR(1) NOT NULL COMMENT 'D借方 C貸方',
        amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
        is_auto    TINYINT NOT NULL DEFAULT 1,
        memo       VARCHAR(100) NULL,
        KEY idx_v (voucher_id),
        CONSTRAINT fk_voucher_line FOREIGN KEY (voucher_id) REFERENCES acc_voucher(voucher_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='傳票分錄（會計科目）'");
    // 科目分類（資產負債表／損益表用）：代號前兩碼；1~3 開頭＝資產負債表、4~9 開頭＝損益表
    $db->exec("CREATE TABLE IF NOT EXISTS acc_account_category (
        code VARCHAR(4) NOT NULL PRIMARY KEY,
        name VARCHAR(60) NOT NULL,
        note VARCHAR(200) NULL,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計科目分類（前兩碼）'");
    $acols = [];
    foreach ($db->query("SHOW COLUMNS FROM acc_account")->fetchAll(PDO::FETCH_ASSOC) as $c) $acols[$c['Field']] = 1;
    foreach (['category'    => "VARCHAR(4) NULL COMMENT '類別（acc_account_category.code）'",
              'summary'     => "VARCHAR(200) NULL COMMENT '彙總摘要'",
              'parent_code' => "VARCHAR(12) NULL COMMENT '所屬科目'",
              'note'        => "VARCHAR(200) NULL COMMENT '備註'"] as $col => $def)
        if (!isset($acols[$col])) $db->exec("ALTER TABLE acc_account ADD COLUMN $col $def");
    foreach (acc_vch_acct_defaults() as $d)
        $db->prepare("INSERT IGNORE INTO acc_account (code, name) VALUES (?,?)")->execute([$d[0], $d[1]]);
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
        // 以鎖帳對帳單上的明細為準（對帳區間可能延到次月，帶入依結帳日屬於別月的單，重建的來源憑證不含這些）
        $keep = [];
        foreach ($b['lines'] as $l) $keep[strtoupper($l['src_type']) . '-' . (int)$l['src_id']] = $l;
        $nl = [];
        foreach ($cs['lines'] as $sl) {
            $k = strtoupper((string)$sl['src_type']) . '-' . (int)$sl['src_id'];
            if (!isset($in[$k])) continue;
            $nl[] = $keep[$k] ?? $sl;
            unset($in[$k]);     // 同一憑證在底稿出現多列時只取一次
        }
        $b['lines'] = $nl;
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
                            string $note, array $sel, string $userName, array $extra = [], string $rounding = 'none'): array
{
    acc_voucher_ensure($db);   // DDL 在交易外
    if (!in_array($rounding, ['none', 'round', 'floor'], true)) $rounding = 'none';
    $side = ($side === 'ap') ? 'ap' : 'ar';
    if (!preg_match('/^\d{4}-\d{2}$/', $bm)) return ['success' => false, 'message' => '帳款月份格式錯誤'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['success' => false, 'message' => '傳票日期格式錯誤'];
    $no = acc_vch_no_normalize((string)$no, $date);
    // 開立前一定要先有傳票號碼與日期，號碼格式與日期一致性都要符合
    if ($no === '') return ['success' => false, 'message' => '請先填 ERP 傳票號碼再開立傳票'];
    [$noErr, $noDate] = acc_vch_no_check($no);
    if ($noErr) return ['success' => false, 'message' => $noErr];
    if ($noDate !== $date) return ['success' => false, 'message' => '傳票號碼裡的日期（' . $noDate . '）與傳票日期（' . $date . '）不一致'];
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
    $total = round($total, 2);
    // 小數處理（確認傳票時選）：四捨五入／無條件捨去（負數＝往 0 方向捨去），整數後才算稅額與分錄
    if ($rounding === 'round') $total = round($total, 0, PHP_ROUND_HALF_UP);
    elseif ($rounding === 'floor') $total = $total < 0 ? ceil($total - 1e-9) : floor($total + 1e-9);
    // 分錄：自動分錄（依選取明細金額重算）＋使用者追加的科目（匯費、手續費…），借貸必須平衡
    [$extraLines, $exErr] = acc_vch_clean_extra($db, $extra);
    if ($exErr) return ['success' => false, 'message' => $exErr];
    $lines = array_merge(acc_vch_auto_lines($db, $side, $total), $extraLines);
    [$dSum, $cSum] = acc_vch_lines_balance($lines);
    if (abs($dSum - $cSum) > 0.009) return ['success' => false, 'message' => '借貸不平衡：借方 ' . number_format($dSum, 2) . '、貸方 ' . number_format($cSum, 2) . '，請調整追加的科目'];
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO acc_voucher (voucher_no,voucher_date,side,party_id,party_name,billing_month,total_amount,item_cnt,note,created_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$no === '' ? null : $no, $date, $side, $partyId, $cand['party_name'], $bm, $total, count($pick), $note, $userName]);
        $vid = (int)$db->lastInsertId();
        $insL = $db->prepare("INSERT INTO acc_voucher_line (voucher_id,seq,code,name,side,amount,is_auto,memo) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($lines as $li => $l) {
            $insL->execute([$vid, $li + 1, $l['code'], $l['name'], $l['side'], $l['amount'], $l['auto'], $l['memo'] ?: null]);
            if (!$l['auto']) acc_vch_account_upsert($db, $l['code'], $l['name']);
        }
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
    $q = $db->prepare("SELECT v.*, (SELECT GROUP_CONCAT(DISTINCT inv.invoice_no ORDER BY inv.invoice_no SEPARATOR '、')
                                       FROM acc_voucher_item vi
                                       JOIN acc_invoice_item ii ON ii.src_type=vi.src_type AND ii.src_id=vi.src_id
                                       JOIN acc_invoice inv ON inv.invoice_id=ii.invoice_id AND inv.status<>'void' AND inv.invoice_no IS NOT NULL AND inv.invoice_no<>''
                                       WHERE vi.voucher_id=v.voucher_id) AS invoice_nos
                       FROM acc_voucher v $ws ORDER BY v.voucher_date DESC, v.voucher_id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
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
    $lq = $db->prepare("SELECT code,name,side,amount,is_auto,memo FROM acc_voucher_line WHERE voucher_id=? ORDER BY seq, line_id");
    $lq->execute([$id]);
    $lines = $lq->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) {            // 舊傳票沒有存分錄：以傳票金額即時組出自動分錄（僅顯示）
        $lines = acc_vch_auto_lines($db, (string)$v['side'], (float)$v['total_amount']);
    }
    $signs = json_decode((string)($v['sign_json'] ?? ''), true); if (!is_array($signs)) $signs = [];
    $v['maker_name'] = acc_vch_cname($db, $v['created_by'] ?? '');
    foreach ($signs as &$sg) if (!empty($sg['name'])) $sg['name'] = acc_vch_cname($db, $sg['name']);
    unset($sg);
    if ($v['status'] === 'void' && !empty($v['void_items_json'])) {          // 作廢後明細已釋放，顯示當時的快照
        $snap = json_decode($v['void_items_json'], true);
        return ['voucher' => $v, 'items' => is_array($snap) ? $snap : [], 'lines' => $lines, 'signs' => $signs];
    }
    $q = $db->prepare("SELECT i.*, (SELECT GROUP_CONCAT(DISTINCT inv.invoice_no) FROM acc_invoice_item ii
                                    JOIN acc_invoice inv ON inv.invoice_id=ii.invoice_id AND inv.status<>'void'
                                    WHERE ii.src_type=i.src_type AND ii.src_id=i.src_id) AS invoice_no
                       FROM acc_voucher_item i WHERE i.voucher_id=? ORDER BY i.src_date, i.src_no, i.item_id");
    $q->execute([$id]);
    return ['voucher' => $v, 'items' => $q->fetchAll(PDO::FETCH_ASSOC), 'lines' => $lines, 'signs' => $signs];
}

/** 修改傳票號碼／日期／備註（明細不可改） */
function acc_voucher_update(PDO $db, int $id, ?string $no, string $date, string $note, string $userName): array
{
    acc_voucher_ensure($db);
    $v = acc_voucher_get($db, $id);
    if (!$v) return ['success' => false, 'message' => '找不到傳票'];
    if ($v['voucher']['status'] === 'void') return ['success' => false, 'message' => '已作廢的傳票不可修改'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['success' => false, 'message' => '傳票日期格式錯誤'];
    $no = acc_vch_no_normalize((string)$no, $date);
    if ($no !== '') {
        [$noErr, $noDate] = acc_vch_no_check($no);
        if ($noErr) return ['success' => false, 'message' => $noErr];
        if ($noDate !== $date) return ['success' => false, 'message' => '傳票號碼裡的日期（' . $noDate . '）與傳票日期（' . $date . '）不一致'];
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


/* ══ 會計科目／分錄 ═══════════════════════════════════════════════════ */

/** 預設科目（可在「科目設定」改）：key => [代號, 名稱, 預設借貸] */
function acc_vch_acct_defaults(): array
{
    return [
        'ar_recv' => ['1144000', '應收帳款', 'D'],
        'ar_tax'  => ['2281000', '銷項稅額', 'C'],
        'ar_rev'  => ['4101000', '銷貨收入', 'C'],
        'ap_pay'  => ['2144000', '應付帳款', 'C'],
        'ap_tax'  => ['1281000', '進項稅額', 'D'],
        'ap_exp'  => ['5201000', '進貨', 'D'],
    ];
}

function acc_vch_acct_labels(): array
{
    return ['ar_recv' => '應收：應收帳款（借方）', 'ar_tax' => '應收：銷項稅額（貸方）', 'ar_rev' => '應收：銷貨收入（貸方）',
            'ap_pay' => '應付：應付帳款（貸方）', 'ap_tax' => '應付：進項稅額（借方）', 'ap_exp' => '應付：進貨（借方）'];
}

/** 取得目前設定的科目：['ar_recv' => ['code'=>,'name'=>], …] */
function acc_vch_acct_cfg(PDO $db): array
{
    $out = [];
    foreach (acc_vch_acct_defaults() as $k => $d) $out[$k] = ['code' => $d[0], 'name' => $d[1]];
    try {
        $st = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'acc_vch_acct_%'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = substr($r['setting_key'], strlen('acc_vch_acct_'));
            if (!isset($out[$k])) continue;
            $p = explode('|', (string)$r['setting_value'], 2);
            if (count($p) === 2 && preg_match('/^\d{4,10}$/', trim($p[0])) && trim($p[1]) !== '') $out[$k] = ['code' => trim($p[0]), 'name' => trim($p[1])];
        }
    } catch (Throwable $e) {}
    return $out;
}

function acc_vch_acct_cfg_save(PDO $db, array $in, string $uid, string $uname): array
{
    acc_voucher_ensure($db);
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by) VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)");
    foreach (acc_vch_acct_defaults() as $k => $d) {
        $code = trim((string)($in[$k]['code'] ?? '')); $name = trim((string)($in[$k]['name'] ?? ''));
        if (!preg_match('/^\d{4,10}$/', $code)) return ['success' => false, 'message' => (acc_vch_acct_labels()[$k]) . '：科目代號須為 4~10 位數字'];
        if ($name === '' || mb_strlen($name) > 40) return ['success' => false, 'message' => (acc_vch_acct_labels()[$k]) . '：請填科目名稱（40 字內）'];
        $st->execute(['acc_vch_acct_' . $k, $code . '|' . $name, (int)$uid, $uname]);
        acc_vch_account_upsert($db, $code, $name);
    }
    return ['success' => true, 'message' => '已儲存科目設定'];
}

/** 依科目代號首碼判定借貸：1資產／5成本／6費用＝借，2負債／3權益／4收入＝貸；7、8、9 無法判定（回 null 由使用者選） */
function acc_vch_side_of_code(string $code): ?string
{
    $c = substr(trim($code), 0, 1);
    if (in_array($c, ['1', '5', '6'], true)) return 'D';
    if (in_array($c, ['2', '3', '4'], true)) return 'C';
    return null;
}

function acc_vch_account_upsert(PDO $db, string $code, string $name): void
{
    $db->prepare("INSERT INTO acc_account (code, name) VALUES (?,?) ON DUPLICATE KEY UPDATE name=IF(name='' ,VALUES(name),name)")->execute([$code, $name]);
}

function acc_vch_accounts(PDO $db, bool $all = false): array
{
    acc_voucher_ensure($db);
    $rows = $db->query("SELECT code, name, active, category, summary, parent_code, note,
        EXISTS(SELECT 1 FROM acc_voucher_line l WHERE l.code=acc_account.code) AS used FROM acc_account " . ($all ? '' : 'WHERE active=1 ') . "ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['side'] = acc_vch_side_of_code($r['code']);
    return $rows;
}

/** 科目表維護：新增／改名／停用（科目代號不可改，要換就新增一個） */
function acc_vch_accounts_save(PDO $db, array $rows, array $delete = []): array
{
    acc_voucher_ensure($db);
    // 刪除：已被傳票分錄用過、或是預設科目（開傳票自動帶出）的不能刪，只能停用
    $cfgCodes = array_map(function ($c) { return $c['code']; }, acc_vch_acct_cfg($db));
    foreach ($delete as $code) {
        $code = trim((string)$code);
        if (in_array($code, $cfgCodes, true)) return ['success' => false, 'message' => "科目 {$code} 是預設會計科目，不能刪除"];
        $q = $db->prepare("SELECT COUNT(*) FROM acc_voucher_line WHERE code=?"); $q->execute([$code]);
        if ((int)$q->fetchColumn() > 0) return ['success' => false, 'message' => "科目 {$code} 已有傳票使用，不能刪除（可取消啟用）"];
    }
    $st = $db->prepare("INSERT INTO acc_account (code, name, active, category, summary, parent_code, note) VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE name=VALUES(name), active=VALUES(active), category=VALUES(category), summary=VALUES(summary), parent_code=VALUES(parent_code), note=VALUES(note)");
    foreach ($rows as $i => $r) {
        $code = trim((string)($r['code'] ?? '')); $name = trim((string)($r['name'] ?? ''));
        $n = $i + 1;
        if (!preg_match('/^\d{4,10}$/', $code)) return ['success' => false, 'message' => "第 {$n} 列：科目代號須為 4~10 位數字"];
        if ($name === '' || mb_strlen($name) > 60) return ['success' => false, 'message' => "第 {$n} 列（{$code}）：請填科目名稱（60 字內）"];
        $sum = trim((string)($r['summary'] ?? '')); $note = trim((string)($r['note'] ?? ''));
        if (mb_strlen($sum) > 200 || mb_strlen($note) > 200) return ['success' => false, 'message' => "第 {$n} 列（{$code}）：摘要／備註請在 200 字內"];
        $cat = trim((string)($r['category'] ?? '')); if ($cat === '') $cat = substr($code, 0, 2);
        if (!preg_match('/^\d{2}$/', $cat)) return ['success' => false, 'message' => "第 {$n} 列（{$code}）：類別須為 2 位數字"];
        $par = trim((string)($r['parent_code'] ?? ''));
        if ($par !== '' && !preg_match('/^\d{4,10}$/', $par)) return ['success' => false, 'message' => "第 {$n} 列（{$code}）：所屬科目須為 4~10 位數字"];
        $st->execute([$code, $name, !empty($r['active']) ? 1 : 0, $cat, $sum ?: null, $par ?: null, $note ?: null]);
    }
    $dl = $db->prepare("DELETE FROM acc_account WHERE code=?");
    foreach ($delete as $code) $dl->execute([trim((string)$code)]);
    return ['success' => true, 'message' => '已儲存科目表'];
}

/** 科目分類（前兩碼）清單，附各分類底下的科目數與所屬報表 */
function acc_vch_categories(PDO $db): array
{
    acc_voucher_ensure($db);
    $rows = $db->query("SELECT c.code, c.name, c.note, (SELECT COUNT(*) FROM acc_account a WHERE a.category=c.code) AS n
                        FROM acc_account_category c ORDER BY c.code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['n'] = (int)$r['n']; $r['stmt'] = in_array(substr($r['code'], 0, 1), ['1', '2', '3'], true) ? 'BS' : 'IS'; }
    return $rows;
}

/** 科目分類維護：新增／改名／改備註（代號不可改）；$delete 內的代號要刪除（底下還有科目就擋下） */
function acc_vch_categories_save(PDO $db, array $rows, array $delete = []): array
{
    acc_voucher_ensure($db);
    foreach ($delete as $code) {
        $code = trim((string)$code);
        $q = $db->prepare("SELECT COUNT(*) FROM acc_account WHERE category=?"); $q->execute([$code]);
        if ((int)$q->fetchColumn() > 0) return ['success' => false, 'message' => "分類 {$code} 底下還有科目，不能刪除（可改名）"];
    }
    $db->beginTransaction();
    try {
        $st = $db->prepare("INSERT INTO acc_account_category (code,name,note,sort_order) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), note=VALUES(note)");
        foreach ($rows as $i => $r) {
            $code = trim((string)($r['code'] ?? '')); $name = trim((string)($r['name'] ?? '')); $note = trim((string)($r['note'] ?? ''));
            $n = $i + 1;
            if (!preg_match('/^\d{2}$/', $code)) throw new RuntimeException("第 {$n} 列：分類代號須為 2 位數字");
            if ($name === '' || mb_strlen($name) > 60) throw new RuntimeException("第 {$n} 列（{$code}）：請填分類名稱（60 字內）");
            if (mb_strlen($note) > 200) throw new RuntimeException("第 {$n} 列（{$code}）：備註請在 200 字內");
            $st->execute([$code, $name, $note ?: null, (int)$code]);
        }
        $dl = $db->prepare("DELETE FROM acc_account_category WHERE code=?");
        foreach ($delete as $code) $dl->execute([trim((string)$code)]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
    return ['success' => true, 'message' => '已儲存科目分類'];
}

/** 登入帳號 → 人員姓名（顯示用）；查不到就原樣回傳 */
function acc_vch_cname(PDO $db, ?string $uname): string
{
    $uname = trim((string)$uname);
    if ($uname === '') return '';
    static $c = [];
    if (isset($c[$uname])) return $c[$uname];
    try { $q = $db->prepare("SELECT user_cname FROM user WHERE user_uname=? LIMIT 1"); $q->execute([$uname]); $n = trim((string)$q->fetchColumn()); } catch (Throwable $e) { $n = ''; }
    return $c[$uname] = ($n !== '' ? $n : $uname);
}

/** 簡寫補全：MMDD＋流水3碼（7 位數字）→ 補上傳票日期的民國年與 V-；民國年MMDD流水3碼（10 位數字）→ 補 V-；其餘原樣回傳 */
function acc_vch_no_normalize(string $no, string $date): string
{
    $no = strtoupper(trim($no));
    if (preg_match('/^\d{10}$/', $no)) return 'V-' . $no;
    if (preg_match('/^\d{7}$/', $no) && preg_match('/^(\d{4})-\d{2}-\d{2}$/', $date, $m)) return 'V-' . sprintf('%03d', (int)$m[1] - 1911) . $no;
    return $no;
}

/** 傳票號碼格式：V-民國年3碼＋MMDD＋流水3碼（例 V-1151126001）。回 [錯誤訊息|null, 號碼內含的西元日期|null] */
function acc_vch_no_check(string $no): array
{
    if (!preg_match('/^V-(\d{3})(\d{2})(\d{2})(\d{3})$/', $no, $m))
        return ['傳票號碼格式須為 V-民國年3碼＋MMDD＋流水號3碼（例 V-1151126001）', null];
    $y = (int)$m[1] + 1911;
    if (!checkdate((int)$m[2], (int)$m[3], $y)) return ['傳票號碼裡的日期 ' . $m[1] . $m[2] . $m[3] . ' 不是有效日期', null];
    return [null, sprintf('%04d-%02d-%02d', $y, $m[2], $m[3])];
}

/** 由傳票金額組出自動分錄（借貸一律正數；淨額為負＝退貨大於出貨，借貸對調） */
function acc_vch_auto_lines(PDO $db, string $side, float $net): array
{
    $cfg = acc_vch_acct_cfg($db);
    $rate = acc_tax_rate($db);
    $tax = round(abs($net) * $rate);
    $net = round($net, 2);
    $abs = abs($net);
    $neg = $net < 0;
    $L = [];
    if ($side === 'ar') {
        $L[] = ['code' => $cfg['ar_recv']['code'], 'name' => $cfg['ar_recv']['name'], 'side' => $neg ? 'C' : 'D', 'amount' => round($abs + $tax, 2), 'auto' => 1, 'memo' => ''];
        $L[] = ['code' => $cfg['ar_tax']['code'],  'name' => $cfg['ar_tax']['name'],  'side' => $neg ? 'D' : 'C', 'amount' => (float)$tax, 'auto' => 1, 'memo' => ''];
        $L[] = ['code' => $cfg['ar_rev']['code'],  'name' => $cfg['ar_rev']['name'],  'side' => $neg ? 'D' : 'C', 'amount' => $abs, 'auto' => 1, 'memo' => ''];
    } else {
        $L[] = ['code' => $cfg['ap_exp']['code'], 'name' => $cfg['ap_exp']['name'], 'side' => $neg ? 'C' : 'D', 'amount' => $abs, 'auto' => 1, 'memo' => ''];
        $L[] = ['code' => $cfg['ap_tax']['code'], 'name' => $cfg['ap_tax']['name'], 'side' => $neg ? 'C' : 'D', 'amount' => (float)$tax, 'auto' => 1, 'memo' => ''];
        $L[] = ['code' => $cfg['ap_pay']['code'], 'name' => $cfg['ap_pay']['name'], 'side' => $neg ? 'D' : 'C', 'amount' => round($abs + $tax, 2), 'auto' => 1, 'memo' => ''];
    }
    return array_values(array_filter($L, fn($l) => $l['amount'] > 0.004));
}

/** 檢查使用者另加的科目列；回 [lines, 錯誤訊息|null] */
function acc_vch_clean_extra(PDO $db, array $extra): array
{
    $out = [];
    foreach ($extra as $i => $e) {
        $code = trim((string)($e['code'] ?? '')); $name = trim((string)($e['name'] ?? ''));
        $amt = round((float)($e['amount'] ?? 0), 2);
        $n = $i + 1;
        if (!preg_match('/^\d{4,10}$/', $code)) return [[], "追加科目第 {$n} 列：科目代號須為 4~10 位數字"];
        // 科目名稱一律取自科目表，不接受自填
        $q = $db->prepare("SELECT name FROM acc_account WHERE code=?"); $q->execute([$code]);
        $name = (string)$q->fetchColumn();
        if ($name === '') return [[], "追加科目第 {$n} 列：科目 {$code} 不在科目表，請先到科目表新增"];
        if (mb_strlen($name) > 40) return [[], "追加科目第 {$n} 列：科目名稱過長"];
        if ($amt <= 0) return [[], "追加科目第 {$n} 列：金額須大於 0"];
        $side = acc_vch_side_of_code($code) ?? (in_array($e['side'] ?? '', ['D', 'C'], true) ? $e['side'] : null);
        if ($side === null) return [[], "追加科目第 {$n} 列：科目 {$code} 無法由編號判定借貸，請選擇借方或貸方"];
        $out[] = ['code' => $code, 'name' => $name, 'side' => $side, 'amount' => $amt, 'auto' => 0, 'memo' => mb_substr(trim((string)($e['memo'] ?? '')), 0, 100)];
    }
    return [$out, null];
}

function acc_vch_lines_balance(array $lines): array
{
    $d = 0.0; $c = 0.0;
    foreach ($lines as $l) { if ($l['side'] === 'D') $d += $l['amount']; else $c += $l['amount']; }
    return [round($d, 2), round($c, 2)];
}

/** 簽章欄位（製單＝建立者，不在此列） */
function acc_vch_sign_slots(): array
{
    return ['approve' => '核准', 'accountant' => '會計', 'review' => '覆核', 'cashier' => '出納', 'posting' => '登帳'];
}

function acc_voucher_sign(PDO $db, int $id, string $slot, string $mode, string $userName): array
{
    acc_voucher_ensure($db);
    if (!isset(acc_vch_sign_slots()[$slot])) return ['success' => false, 'message' => '簽章欄位不正確'];
    $q = $db->prepare("SELECT status, sign_json FROM acc_voucher WHERE voucher_id=?"); $q->execute([$id]);
    $v = $q->fetch(PDO::FETCH_ASSOC);
    if (!$v) return ['success' => false, 'message' => '找不到傳票'];
    if ($v['status'] === 'void') return ['success' => false, 'message' => '已作廢的傳票不可簽章'];
    $s = json_decode((string)$v['sign_json'], true); if (!is_array($s)) $s = [];
    if ($mode === 'clear') unset($s[$slot]);
    else {
        if (!empty($s[$slot])) return ['success' => false, 'message' => acc_vch_sign_slots()[$slot] . '欄已有人簽章，請先由管理員取消'];
        $s[$slot] = ['name' => $userName, 'date' => (string)$db->query('SELECT CURDATE()')->fetchColumn()];
    }
    $db->prepare("UPDATE acc_voucher SET sign_json=? WHERE voucher_id=?")->execute([json_encode($s, JSON_UNESCAPED_UNICODE), $id]);
    return ['success' => true, 'signs' => $s];
}
