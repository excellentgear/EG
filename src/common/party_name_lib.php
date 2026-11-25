<?php
/**
 * 客戶／廠商「別名與更名歷史」＋「額外地址」共用庫（唯一實作，客戶與廠商共用同一套）
 *
 * 原則：編號（customer_id / maker_id_no）一經建立永不改變；公司改名只改名稱，
 * 舊名稱自動寫入 party_name_alias（kind='history'，含誰、何時改、用到哪一天），
 * 另可手動加別名（kind='manual'，例如 ERP 慣用寫法）。搜尋、ERP 匯入比對都同時認得現名與所有別名。
 * 歷史單據要印「當時名稱」→ 呼叫 eg_party_name_asof()。
 *
 * 注意：maker_aliases 是「廠商舊『編號』合併」用的另一張表，與本庫無關。
 */

function eg_party_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS party_name_alias (
            alias_id        INT AUTO_INCREMENT PRIMARY KEY,
            party_type      VARCHAR(10) NOT NULL COMMENT 'customer / maker',
            party_id        VARCHAR(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL COMMENT '客戶編號／廠商編號',
            alias_name      VARCHAR(100) NOT NULL COMMENT '別名／舊簡稱',
            alias_full      VARCHAR(150) NULL COMMENT '舊全名（發票用）',
            kind            VARCHAR(10) NOT NULL DEFAULT 'manual' COMMENT 'history=更名時自動留存 manual=手動別名',
            used_until      DATE NULL COMMENT '舊名稱使用到哪一天（更名日前一天或更名當日）',
            note            VARCHAR(200) NULL,
            created_by      INT NULL,
            created_by_name VARCHAR(50) NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_party (party_type, party_id),
            KEY idx_name (alias_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客戶／廠商別名與更名歷史'");
        $db->exec("CREATE TABLE IF NOT EXISTS party_address (
            addr_id    INT AUTO_INCREMENT PRIMARY KEY,
            party_type VARCHAR(10) NOT NULL,
            party_id   VARCHAR(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
            addr_label VARCHAR(60) NULL COMMENT '地址類別說明，例：第二廠區',
            zip        VARCHAR(20) NULL,
            address    VARCHAR(255) NULL,
            tel        VARCHAR(50) NULL,
            fax        VARCHAR(50) NULL,
            contact    VARCHAR(60) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY idx_party (party_type, party_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客戶／廠商額外地址（多廠區、多送貨地址）'");
        $db->exec("CREATE TABLE IF NOT EXISTS party_addr_type (
            type_id    INT AUTO_INCREMENT PRIMARY KEY,
            type_name  VARCHAR(30) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by_name VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_name (type_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客戶／廠商「其他地址」的地址類別（設定後通用；被使用後不可改名、刪除）'");
        if ((int)$db->query("SELECT COUNT(*) FROM party_addr_type")->fetchColumn() === 0) {
            $db->exec("INSERT IGNORE INTO party_addr_type (type_name,sort_order) VALUES ('發票地址',1),('送貨地址',2),('工廠地址',3)");
        }
    } catch (Throwable $e) { error_log('eg_party_ensure_schema: ' . $e->getMessage()); }
    // 欄位：先查再加（本機 MySQL 沒有 ADD COLUMN IF NOT EXISTS）
    $want = [
        'customer_list' => ['customer_fax2' => "VARCHAR(30) NULL COMMENT '傳真2'", 'customer_zip' => "VARCHAR(20) NULL COMMENT '郵遞區號'",
                            'payment_methods'   => "VARCHAR(100) NULL COMMENT '收款方式（多選，逗號分隔，含附回郵）；payment_method 仍存第一個實際收款方式供舊程式使用'",
                            'net_unit'          => "VARCHAR(10) NULL COMMENT '月結方式 days=依天數 months=次月結N個月'",
                            'net_months'        => "SMALLINT NULL COMMENT '次月結幾個月（net_unit=months 時有值，net_days 同步存 月數*30）'",
                            'customer_email'    => "VARCHAR(255) NULL COMMENT '公司電子信箱（可多個，分號或空白分隔）'",
                            'invoice_recipient' => "VARCHAR(100) NULL COMMENT '發票收件人'",
                            'invoice_dept'      => "VARCHAR(100) NULL COMMENT '發票收件部門'",
                            'shipping_req_none' => "TINYINT NOT NULL DEFAULT 0 COMMENT '出貨要求：1=已確認無特殊要求'"],
        'customer_contacts' => ['is_accounting' => "TINYINT NOT NULL DEFAULT 0 COMMENT '會計聯絡人'",
                                'is_invoice_recv' => "TINYINT NOT NULL DEFAULT 0 COMMENT '發票（電子發票）收件人'"],
        'party_address' => ['addr_type_id' => "INT NULL COMMENT '地址類別（party_addr_type）'"],
        'maker_list'    => ['m_fax2' => "VARCHAR(50) NULL COMMENT '傳真2'",
                            'factory_zip' => "VARCHAR(20) NULL COMMENT '工廠地址郵遞區號'",
                            'billing_zip' => "VARCHAR(20) NULL COMMENT '帳單地址郵遞區號'"],
    ];
    foreach ($want as $tbl => $cols) {
        try {
            $have = [];
            foreach ($db->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['Field']] = 1;
            foreach ($cols as $c => $def) {
                if (!isset($have[$c])) { try { $db->exec("ALTER TABLE `$tbl` ADD COLUMN `$c` $def"); } catch (Throwable $e) { error_log("party ALTER $tbl.$c: " . $e->getMessage()); } }
            }
        } catch (Throwable $e) {}
    }
}

/** 讀別名（含更名歷史）與額外地址，供 get_customer / get_maker 回傳 */
function eg_party_load(PDO $db, string $type, string $id): array
{
    eg_party_ensure_schema($db);
    $out = ['name_aliases' => [], 'extra_addrs' => [], 'addr_types' => eg_party_addr_types($db)];
    try {
        $q = $db->prepare("SELECT alias_id, alias_name, alias_full, kind, used_until, note, created_by_name,
                                  DATE_FORMAT(created_at,'%Y.%m.%d %H:%i') AS created_at_txt
                           FROM party_name_alias WHERE party_type=? AND party_id=?
                           ORDER BY (kind='history') DESC, used_until DESC, alias_id DESC");
        $q->execute([$type, $id]);
        $out['name_aliases'] = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $db->prepare("SELECT addr_id, addr_type_id, addr_label, zip, address, tel, fax, contact FROM party_address WHERE party_type=? AND party_id=? ORDER BY sort_order, addr_id");
        $q->execute([$type, $id]);
        $out['extra_addrs'] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 更名：簡稱或全名有變就把「舊的」留成一筆 history 別名（誰、何時改的、用到哪天）。
 * $renameDate＝更名日（YYYY-MM-DD，空＝今天）；舊名稱使用到「更名日前一天」。
 * 回傳是否有新增歷史。必須在呼叫端的 transaction 內呼叫（本函式不開 transaction、不做 DDL）。
 */
function eg_party_record_rename(PDO $db, string $type, string $id, ?string $oldShort, ?string $oldFull,
                                ?string $newShort, ?string $newFull, string $renameDate, int $uid, string $uname): bool
{
    $oldShort = trim((string)$oldShort); $oldFull = trim((string)$oldFull);
    $newShort = trim((string)$newShort); $newFull = trim((string)$newFull);
    if ($oldShort === '' && $oldFull === '') return false;
    if ($oldShort === $newShort && $oldFull === $newFull) return false;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $renameDate)) $renameDate = date('Y-m-d');
    $until = date('Y-m-d', strtotime($renameDate . ' -1 day'));
    $parts = [];
    if ($oldShort !== $newShort) $parts[] = "簡稱「{$oldShort}」→「{$newShort}」";
    if ($oldFull  !== $newFull)  $parts[] = "全名「{$oldFull}」→「{$newFull}」";
    $db->prepare("INSERT INTO party_name_alias (party_type,party_id,alias_name,alias_full,kind,used_until,note,created_by,created_by_name)
                  VALUES (?,?,?,?, 'history', ?, ?, ?, ?)")
       ->execute([$type, $id, $oldShort !== '' ? $oldShort : $oldFull, $oldFull !== '' ? $oldFull : null, $until,
                  mb_substr('更名日 ' . $renameDate . '：' . implode('；', $parts), 0, 200), $uid ?: null, $uname]);
    return true;
}

/** 手動別名（kind='manual'）整批同步：有 alias_id 的更新、沒有的新增、不在清單內的刪除（history 永不刪除） */
function eg_party_save_manual_aliases(PDO $db, string $type, string $id, array $rows, int $uid, string $uname): void
{
    $keep = [];
    foreach ($rows as $r) {
        $name = trim((string)($r['alias_name'] ?? ''));
        if ($name === '') continue;
        $name = mb_substr($name, 0, 100);
        $full = trim((string)($r['alias_full'] ?? '')); $full = $full !== '' ? mb_substr($full, 0, 150) : null;
        $note = trim((string)($r['note'] ?? ''));      $note = $note !== '' ? mb_substr($note, 0, 200) : null;
        $aid  = intval($r['alias_id'] ?? 0);
        if ($aid > 0) {
            $u = $db->prepare("UPDATE party_name_alias SET alias_name=?, alias_full=?, note=? WHERE alias_id=? AND party_type=? AND party_id=? AND kind='manual'");
            $u->execute([$name, $full, $note, $aid, $type, $id]);
            $keep[] = $aid;
        } else {
            $db->prepare("INSERT INTO party_name_alias (party_type,party_id,alias_name,alias_full,kind,note,created_by,created_by_name) VALUES (?,?,?,?, 'manual', ?, ?, ?)")
               ->execute([$type, $id, $name, $full, $note, $uid ?: null, $uname]);
            $keep[] = (int)$db->lastInsertId();
        }
    }
    $sql = "DELETE FROM party_name_alias WHERE party_type=? AND party_id=? AND kind='manual'";
    $args = [$type, $id];
    if ($keep) { $sql .= ' AND alias_id NOT IN (' . implode(',', array_map('intval', $keep)) . ')'; }
    $db->prepare($sql)->execute($args);
}

/** 額外地址整批取代（delete-then-insert） */
function eg_party_save_addrs(PDO $db, string $type, string $id, array $rows): void
{
    // 注意：不可在此呼叫 eg_party_ensure_schema（CREATE TABLE 會讓呼叫端 transaction 隱式 commit）；
    // 呼叫端須在 beginTransaction() 之前先呼叫。
    $validTypes = [];
    foreach ($db->query("SELECT type_id FROM party_addr_type")->fetchAll(PDO::FETCH_COLUMN) as $t) $validTypes[(int)$t] = 1;
    $db->prepare("DELETE FROM party_address WHERE party_type=? AND party_id=?")->execute([$type, $id]);
    $ins = $db->prepare("INSERT INTO party_address (party_type,party_id,addr_type_id,addr_label,zip,address,tel,fax,contact,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $k = 0;
    foreach ($rows as $r) {
        $label = trim((string)($r['addr_label'] ?? '')); $addr = trim((string)($r['address'] ?? ''));
        $zip = trim((string)($r['zip'] ?? ''));          $tel = trim((string)($r['tel'] ?? ''));
        $fax = trim((string)($r['fax'] ?? ''));          $con = trim((string)($r['contact'] ?? ''));
        $tid = intval($r['addr_type_id'] ?? 0); $tid = isset($validTypes[$tid]) ? $tid : null;   // 不存在的類別一律當未選
        if ($label === '' && $addr === '' && $zip === '' && $tel === '' && $fax === '' && $con === '' && $tid === null) continue;
        $ins->execute([$type, $id, $tid, mb_substr($label, 0, 60), mb_substr($zip, 0, 20), mb_substr($addr, 0, 255),
                       mb_substr($tel, 0, 50), mb_substr($fax, 0, 50), mb_substr($con, 0, 60), $k++]);
    }
}

/** 地址類別清單（含各類別被使用的筆數；used>0 者不可改名、不可刪除） */
function eg_party_addr_types(PDO $db): array
{
    try {
        return $db->query("SELECT t.type_id, t.type_name, t.sort_order,
                                  (SELECT COUNT(*) FROM party_address a WHERE a.addr_type_id=t.type_id) AS used
                           FROM party_addr_type t ORDER BY t.sort_order, t.type_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 新增／改名地址類別。$typeId=0 新增；改名時已被使用者擋下。回傳 ['ok'=>bool,'msg'=>string] */
function eg_party_addr_type_save(PDO $db, int $typeId, string $name, string $uname): array
{
    eg_party_ensure_schema($db);
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'msg' => '類別名稱不可為空'];
    if (mb_strlen($name) > 30) return ['ok' => false, 'msg' => '類別名稱最多 30 字'];
    $dup = $db->prepare("SELECT type_id FROM party_addr_type WHERE type_name=? AND type_id<>?");
    $dup->execute([$name, $typeId]);
    if ($dup->fetchColumn()) return ['ok' => false, 'msg' => "類別「{$name}」已存在"];
    if ($typeId > 0) {
        $cur = $db->prepare("SELECT type_name, (SELECT COUNT(*) FROM party_address a WHERE a.addr_type_id=party_addr_type.type_id) AS used FROM party_addr_type WHERE type_id=?");
        $cur->execute([$typeId]); $c = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'msg' => '找不到該類別，請重新整理'];
        if ($c['type_name'] === $name) return ['ok' => true, 'msg' => '未變更'];
        if ((int)$c['used'] > 0) return ['ok' => false, 'msg' => "「{$c['type_name']}」已被 {$c['used']} 筆地址使用，不可修改名稱"];
        $db->prepare("UPDATE party_addr_type SET type_name=? WHERE type_id=?")->execute([$name, $typeId]);
    } else {
        $mx = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM party_addr_type")->fetchColumn();
        $db->prepare("INSERT INTO party_addr_type (type_name,sort_order,created_by_name) VALUES (?,?,?)")->execute([$name, $mx + 1, $uname]);
    }
    return ['ok' => true, 'msg' => '已儲存'];
}

/** 刪除地址類別（被使用中不可刪） */
function eg_party_addr_type_delete(PDO $db, int $typeId): array
{
    $cur = $db->prepare("SELECT type_name, (SELECT COUNT(*) FROM party_address a WHERE a.addr_type_id=party_addr_type.type_id) AS used FROM party_addr_type WHERE type_id=?");
    $cur->execute([$typeId]); $c = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['ok' => false, 'msg' => '找不到該類別，請重新整理'];
    if ((int)$c['used'] > 0) return ['ok' => false, 'msg' => "「{$c['type_name']}」已被 {$c['used']} 筆地址使用，不可刪除"];
    $db->prepare("DELETE FROM party_addr_type WHERE type_id=?")->execute([$typeId]);
    return ['ok' => true, 'msg' => '已刪除'];
}

/** 編號被合併／移轉時，別名與額外地址一併搬到目標編號 */
function eg_party_move(PDO $db, string $type, string $src, string $tgt): void
{
    eg_party_ensure_schema($db);
    foreach (['party_name_alias', 'party_address'] as $t) {
        $db->prepare("UPDATE `$t` SET party_id=? WHERE party_type=? AND party_id=?")->execute([$tgt, $type, $src]);
    }
}

/** 刪除主檔時清掉別名與額外地址 */
function eg_party_delete(PDO $db, string $type, string $id): void
{
    foreach (['party_name_alias', 'party_address'] as $t) {
        try { $db->prepare("DELETE FROM `$t` WHERE party_type=? AND party_id=?")->execute([$type, $id]); } catch (Throwable $e) {}
    }
}

/**
 * 某一天當時的名稱（歷史單據列印用）。回傳 ['short'=>..,'full'=>..]；查不到主檔回 null。
 * 規則：更名歷史裡「used_until >= 該日」且最早的那一筆＝當天還在用的舊名稱；沒有就是現名。
 */
function eg_party_name_asof(PDO $db, string $type, string $id, ?string $date): ?array
{
    eg_party_ensure_schema($db);
    if ($type === 'customer') $q = $db->prepare("SELECT customer AS s, customer_full AS f FROM customer_list WHERE customer_id=?");
    else                      $q = $db->prepare("SELECT maker_id AS s, maker_id_all AS f FROM maker_list WHERE maker_id_no=?");
    $q->execute([$id]);
    $cur = $q->fetch(PDO::FETCH_ASSOC);
    if (!$cur) return null;
    $res = ['short' => (string)$cur['s'], 'full' => (string)($cur['f'] ?? '')];
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) return $res;
    $h = $db->prepare("SELECT alias_name, alias_full FROM party_name_alias
                       WHERE party_type=? AND party_id=? AND kind='history' AND used_until >= ?
                       ORDER BY used_until ASC, alias_id ASC LIMIT 1");
    $h->execute([$type, $id, substr($date, 0, 10)]);
    if ($r = $h->fetch(PDO::FETCH_ASSOC)) {
        $res['short'] = (string)$r['alias_name'];
        if ($r['alias_full'] !== null && $r['alias_full'] !== '') $res['full'] = (string)$r['alias_full'];
    }
    return $res;
}

/** 關鍵字（已含 % 的 LIKE 參數佔位名）→ 比對別名的 EXISTS 子句，供主檔搜尋共用 */
function eg_party_alias_exists_sql(string $type, string $idExpr, string $param): string
{
    return "EXISTS (SELECT 1 FROM party_name_alias pna WHERE pna.party_type='" . ($type === 'maker' ? 'maker' : 'customer')
         . "' AND pna.party_id=$idExpr AND (pna.alias_name LIKE $param OR pna.alias_full LIKE $param))";
}

/**
 * 對帳／請款用的聯絡提示：會計聯絡人、發票收件人（聯絡人勾選）＋電子發票 Email／發票收件人部門／帳務備註。
 * 對帳頁選到客戶時顯示。查不到或都沒設定回 null。唯讀。
 */
function eg_party_contact_hint(PDO $db, string $customerId): ?array
{
    if ($customerId === '') return null;
    try {
        $c = $db->prepare("SELECT customer, invoice_email, customer_email, invoice_recipient, invoice_dept, billing_note FROM customer_list WHERE customer_id=?");
        $c->execute([$customerId]); $row = $c->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $acct = []; $inv = [];
        $q = $db->prepare("SELECT name, department, title, phone_ext, mobile, email, is_accounting, is_invoice_recv FROM customer_contacts WHERE customer_id=? AND (is_accounting=1 OR is_invoice_recv=1) ORDER BY sort_order, contact_id");
        $q->execute([$customerId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $one = ['name' => $r['name'], 'dept' => trim($r['department'] . ' ' . $r['title']), 'tel' => trim($r['mobile'] . ' ' . ($r['phone_ext'] !== '' ? '#' . $r['phone_ext'] : '')), 'email' => $r['email']];
            if ((int)$r['is_accounting'])  $acct[] = $one;
            if ((int)$r['is_invoice_recv']) $inv[] = $one;
        }
        $out = ['customer' => $row['customer'], 'accounting' => $acct, 'invoice_recv' => $inv,
                'invoice_email' => (string)$row['invoice_email'], 'invoice_recipient' => (string)$row['invoice_recipient'],
                'invoice_dept' => (string)$row['invoice_dept'], 'billing_note' => (string)$row['billing_note']];
        $any = $acct || $inv || $out['invoice_email'] !== '' || $out['invoice_recipient'] !== '' || $out['invoice_dept'] !== '' || trim($out['billing_note']) !== '';
        return $any ? $out : null;
    } catch (Throwable $e) { return null; }
}
