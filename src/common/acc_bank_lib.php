<?php
/**
 * 銀行代號（7 碼數字文字，開頭 0 不可丟）— 唯一實作。
 * 來源：FOR CODEING 說明文件/會計/銀行代碼.xlsx（編號／分類／名稱／電話／地址／備註），可在「會計設定→銀行代號」重新匯入或維護。
 * 用途：收款支票的「開票銀行」輸入 7 碼代號後即時顯示銀行名稱（Acc_API action=bank_lookup）。
 */
require_once __DIR__ . '/acc_lib.php';

function acc_bank_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS acc_bank_code (
        code     CHAR(7)      NOT NULL COMMENT '銀行代號（7碼數字，文字儲存保留開頭0）',
        category VARCHAR(30)  DEFAULT NULL,
        name     VARCHAR(80)  NOT NULL,
        phone    VARCHAR(40)  DEFAULT NULL,
        address  VARCHAR(150) DEFAULT NULL,
        note     VARCHAR(200) DEFAULT NULL,
        PRIMARY KEY (code), KEY idx_bank_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-銀行代號'");
}

/** 電話整理：全形／長破折號轉「-」，連續「-」併成一個，頭尾的「-」去掉 */
function acc_bank_phone_clean($p): string
{
    $p = trim((string)$p);
    $p = str_replace(['－', '–', '—', '‐', '−', '﹣'], '-', $p);
    $p = preg_replace('/-{2,}/', '-', $p);
    $p = preg_replace('/\s*-\s*/', '-', $p);
    return trim($p, " -\t");
}

/** 代號正規化：只留數字；不足 7 碼且全是數字時左補 0（試算表若把 0 吃掉）。回 7 碼或 '' */
function acc_bank_code_norm($c): string
{
    $c = preg_replace('/\D/', '', (string)$c);
    if ($c === '' || strlen($c) > 7) return '';
    return str_pad($c, 7, '0', STR_PAD_LEFT);
}

function acc_bank_lookup(PDO $db, string $code): ?array
{
    acc_bank_ensure($db);
    $code = acc_bank_code_norm($code);
    if ($code === '') return null;
    $q = $db->prepare("SELECT code, category, name, phone, address FROM acc_bank_code WHERE code=?");
    $q->execute([$code]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function acc_bank_list(PDO $db, array $f): array
{
    acc_bank_ensure($db);
    $w = []; $p = [];
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') { $w[] = "(code LIKE ? OR name LIKE ? OR category LIKE ? OR phone LIKE ? OR address LIKE ?)"; for ($i = 0; $i < 5; $i++) $p[] = '%' . $kw . '%'; }
    $cat = trim((string)($f['category'] ?? ''));
    if ($cat !== '') { $w[] = "category=?"; $p[] = $cat; }
    $ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $c = $db->prepare("SELECT COUNT(*) FROM acc_bank_code $ws"); $c->execute($p);
    $total = (int)$c->fetchColumn();
    $per = (int)($f['per_page'] ?? 20); if (!in_array($per, [5, 10, 20, 50], true)) $per = 20;
    $pg = max(1, (int)($f['page'] ?? 1));
    $q = $db->prepare("SELECT * FROM acc_bank_code $ws ORDER BY code LIMIT $per OFFSET " . (($pg - 1) * $per));
    $q->execute($p);
    $cats = $db->query("SELECT DISTINCT category FROM acc_bank_code WHERE category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    return ['rows' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $pg, 'per_page' => $per, 'categories' => $cats];
}

function acc_bank_save(PDO $db, array $d): array
{
    acc_bank_ensure($db);
    $raw = trim((string)($d['code'] ?? ''));
    if (!preg_match('/^\d{7}$/', $raw)) return ['success' => false, 'message' => '銀行代號須為 7 碼數字（開頭的 0 要保留）'];
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) return ['success' => false, 'message' => '請填銀行名稱（80 字內）'];
    $cat = mb_substr(trim((string)($d['category'] ?? '')), 0, 30);
    $addr = mb_substr(trim((string)($d['address'] ?? '')), 0, 150);
    $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 200);
    $phone = acc_bank_phone_clean($d['phone'] ?? '');
    $db->prepare("INSERT INTO acc_bank_code (code,category,name,phone,address,note) VALUES (?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE category=VALUES(category), name=VALUES(name), phone=VALUES(phone), address=VALUES(address), note=VALUES(note)")
       ->execute([$raw, $cat ?: null, $name, $phone ?: null, $addr ?: null, $note ?: null]);
    return ['success' => true, 'message' => '已儲存'];
}

function acc_bank_delete(PDO $db, string $code): array
{
    acc_bank_ensure($db);
    $db->prepare("DELETE FROM acc_bank_code WHERE code=?")->execute([acc_bank_code_norm($code)]);
    return ['success' => true, 'message' => '已刪除'];
}

/** 由 xlsx 匯入（第一列表頭：編號／分類／名稱／電話／地址／備註）；同代號覆蓋。回 [新增,更新,略過,略過原因[]] */
function acc_bank_import(PDO $db, string $path): array
{
    acc_bank_ensure($db);
    require_once __DIR__ . '/../../vendor/autoload.php';
    try { $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheet(0); }
    catch (Throwable $e) { return ['success' => false, 'message' => '無法讀取檔案：' . $e->getMessage()]; }
    $rows = $ws->toArray(null, true, true, false);
    if (!$rows) return ['success' => false, 'message' => '檔案是空的'];
    $hdr = array_map(fn($h) => trim((string)$h), array_shift($rows));
    $ix = []; foreach (['編號' => 'code', '分類' => 'category', '名稱' => 'name', '電話' => 'phone', '地址' => 'address', '備註' => 'note'] as $h => $k) { $i = array_search($h, $hdr, true); if ($i !== false) $ix[$k] = $i; }
    if (!isset($ix['code'], $ix['name'])) return ['success' => false, 'message' => '第一列表頭須含「編號」「名稱」'];
    $have = array_flip($db->query("SELECT code FROM acc_bank_code")->fetchAll(PDO::FETCH_COLUMN));
    $ins = $db->prepare("INSERT INTO acc_bank_code (code,category,name,phone,address,note) VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE category=VALUES(category), name=VALUES(name), phone=VALUES(phone), address=VALUES(address), note=VALUES(note)");
    $new = 0; $upd = 0; $skip = []; $seen = [];
    $db->beginTransaction();
    try {
        foreach ($rows as $n => $r) {
            $g = fn($k) => isset($ix[$k]) ? trim((string)($r[$ix[$k]] ?? '')) : '';
            if ($g('code') === '' && $g('name') === '') continue;
            $code = acc_bank_code_norm($g('code'));
            if ($code === '' || $g('name') === '') { if (count($skip) < 20) $skip[] = '第 ' . ($n + 2) . ' 列：代號或名稱不正確'; continue; }
            $ins->execute([$code, mb_substr($g('category'), 0, 30) ?: null, mb_substr($g('name'), 0, 80), acc_bank_phone_clean($g('phone')) ?: null,
                           mb_substr($g('address'), 0, 150) ?: null, mb_substr($g('note'), 0, 200) ?: null]);
            if (isset($have[$code]) || isset($seen[$code])) $upd++; else $new++;
            $seen[$code] = 1;
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); return ['success' => false, 'message' => '匯入失敗：' . $e->getMessage()]; }
    return ['success' => true, 'added' => $new, 'updated' => $upd, 'skipped' => $skip,
            'message' => "匯入完成：新增 {$new}、更新 {$upd}" . ($skip ? '、略過 ' . count($skip) . '（見明細）' : '')];
}
