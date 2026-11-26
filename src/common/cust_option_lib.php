<?php
/**
 * 客戶主檔的「可維護選項」共用庫（唯一實作）
 *   kind='pay_method'    收款方式（匯款/支票/現金/信用狀/附回郵…）
 *   kind='recon_channel' 客戶可接受的對帳單提供方式（Line/傳真/EMAIL…）
 * 管理員可新增、改名；已被客戶選用者不可改名／刪除。
 * 客戶欄位存的是選項「文字」（逗號分隔），所以改名會讓舊資料失聯，必須擋下已使用者。
 */
if (!function_exists('eg_cust_opt_ensure')) {

function eg_cust_opt_kinds(): array
{
    return [
        'pay_method'    => ['label' => '收款方式',         'seed' => ['匯款', '支票', '現金', '信用狀', '附回郵']],
        'recon_channel' => ['label' => '客戶可接受提供方式', 'seed' => ['Line', '傳真', 'EMAIL']],
    ];
}

function eg_cust_opt_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS cust_option (
            opt_id     INT AUTO_INCREMENT PRIMARY KEY,
            kind       VARCHAR(20) NOT NULL COMMENT 'pay_method / recon_channel',
            opt_name   VARCHAR(30) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by_name VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_kind_name (kind, opt_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客戶主檔可維護選項（收款方式、對帳單提供方式）'");
        foreach (eg_cust_opt_kinds() as $k => $def) {
            $q = $db->prepare("SELECT COUNT(*) FROM cust_option WHERE kind=?");
            $q->execute([$k]);
            if ((int)$q->fetchColumn() === 0) {
                $i = 1;
                foreach ($def['seed'] as $n) {
                    $db->prepare("INSERT IGNORE INTO cust_option (kind,opt_name,sort_order) VALUES (?,?,?)")->execute([$k, $n, $i++]);
                }
            }
        }
        // 欄位：先查再加（本機 MySQL 沒有 ADD COLUMN IF NOT EXISTS）
        $have = [];
        foreach ($db->query("SHOW COLUMNS FROM customer_list")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['Field']] = 1;
        if (!isset($have['recon_channels'])) {
            $db->exec("ALTER TABLE customer_list ADD COLUMN recon_channels VARCHAR(200) NULL DEFAULT NULL COMMENT '客戶可接受的對帳單提供方式（逗號分隔，選項見 cust_option recon_channel）'");
        }
        if (!isset($have['recon_line_contact'])) {
            $db->exec("ALTER TABLE customer_list ADD COLUMN recon_line_contact VARCHAR(50) NULL DEFAULT NULL COMMENT '勾選私Line時指定的聯絡人姓名（來自 customer_contacts）'");
            // 欄位第一次建立時順便補上「私Line」選項（只此一次，之後管理員刪除不會被補回）
            $mx = $db->query("SELECT COALESCE(MAX(sort_order),0) FROM cust_option WHERE kind='recon_channel'")->fetchColumn();
            $db->prepare("INSERT IGNORE INTO cust_option (kind,opt_name,sort_order) VALUES ('recon_channel','私Line',?)")->execute([(int)$mx + 1]);
        }
        if (!isset($have['einv_max_pages'])) {
            $db->exec("ALTER TABLE customer_list ADD COLUMN einv_max_pages INT NULL DEFAULT NULL COMMENT '電子發票頁數上限（NULL=不限；1頁=每張發票筆數上限的1倍，超過時同出貨單同料號同數量金額加總）'");
        }
        if (!isset($have['einv_merge_part'])) {
            $db->exec("ALTER TABLE customer_list ADD COLUMN einv_merge_part TINYINT(1) NOT NULL DEFAULT 0 COMMENT '電子發票同料號合併一筆（1=不分出貨單、區間內同料號數量加總）'");
        }
    } catch (Throwable $e) { error_log('eg_cust_opt_ensure: ' . $e->getMessage()); }
}

/** 某選項被幾位客戶使用 */
function eg_cust_opt_used(PDO $db, string $kind, string $name): int
{
    try {
        if ($kind === 'pay_method') {
            $q = $db->prepare("SELECT COUNT(*) FROM customer_list WHERE FIND_IN_SET(?, payment_methods) OR payment_method=?");
            $q->execute([$name, $name]);
        } else {
            $q = $db->prepare("SELECT COUNT(*) FROM customer_list WHERE FIND_IN_SET(?, recon_channels)");
            $q->execute([$name]);
        }
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** 選項清單（含使用筆數 used；used>0 不可改名／刪除） */
function eg_cust_opt_list(PDO $db, string $kind): array
{
    eg_cust_opt_ensure($db);
    $out = [];
    try {
        $q = $db->prepare("SELECT opt_id, opt_name, sort_order FROM cust_option WHERE kind=? ORDER BY sort_order, opt_id");
        $q->execute([$kind]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['used'] = eg_cust_opt_used($db, $kind, $r['opt_name']);
            $out[] = $r;
        }
    } catch (Throwable $e) {}
    if (!$out) {   // 表建不起來時的保底，不讓畫面空掉
        $k = eg_cust_opt_kinds();
        foreach (($k[$kind]['seed'] ?? []) as $i => $n) $out[] = ['opt_id' => 0, 'opt_name' => $n, 'sort_order' => $i + 1, 'used' => 0];
    }
    return $out;
}

/** 只回選項名稱（供儲存時驗證白名單、畫面渲染） */
function eg_cust_opt_names(PDO $db, string $kind): array
{
    return array_column(eg_cust_opt_list($db, $kind), 'opt_name');
}

function eg_cust_opt_save(PDO $db, string $kind, int $optId, string $name, string $uname): array
{
    eg_cust_opt_ensure($db);
    $kinds = eg_cust_opt_kinds();
    if (!isset($kinds[$kind])) return ['ok' => false, 'msg' => '選項類別不正確'];
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'msg' => '名稱不可為空'];
    if (mb_strlen($name) > 30) return ['ok' => false, 'msg' => '名稱最多 30 字'];
    if (strpos($name, ',') !== false || strpos($name, '，') !== false) return ['ok' => false, 'msg' => '名稱不可含逗號'];
    $dup = $db->prepare("SELECT opt_id FROM cust_option WHERE kind=? AND opt_name=? AND opt_id<>?");
    $dup->execute([$kind, $name, $optId]);
    if ($dup->fetchColumn()) return ['ok' => false, 'msg' => "「{$name}」已存在"];
    if ($optId > 0) {
        $cur = $db->prepare("SELECT opt_name FROM cust_option WHERE opt_id=? AND kind=?");
        $cur->execute([$optId, $kind]);
        $old = $cur->fetchColumn();
        if ($old === false) return ['ok' => false, 'msg' => '找不到該選項，請重新整理'];
        if ($old === $name) return ['ok' => true, 'msg' => '未變更'];
        $u = eg_cust_opt_used($db, $kind, $old);
        if ($u > 0) return ['ok' => false, 'msg' => "「{$old}」已被 {$u} 位客戶選用，不可修改名稱"];
        $db->prepare("UPDATE cust_option SET opt_name=? WHERE opt_id=?")->execute([$name, $optId]);
    } else {
        $mx = $db->prepare("SELECT COALESCE(MAX(sort_order),0) FROM cust_option WHERE kind=?");
        $mx->execute([$kind]);
        $db->prepare("INSERT INTO cust_option (kind,opt_name,sort_order,created_by_name) VALUES (?,?,?,?)")
           ->execute([$kind, $name, (int)$mx->fetchColumn() + 1, $uname]);
    }
    return ['ok' => true, 'msg' => '已儲存'];
}

function eg_cust_opt_delete(PDO $db, string $kind, int $optId): array
{
    eg_cust_opt_ensure($db);
    $cur = $db->prepare("SELECT opt_name FROM cust_option WHERE opt_id=? AND kind=?");
    $cur->execute([$optId, $kind]);
    $old = $cur->fetchColumn();
    if ($old === false) return ['ok' => false, 'msg' => '找不到該選項，請重新整理'];
    $u = eg_cust_opt_used($db, $kind, $old);
    if ($u > 0) return ['ok' => false, 'msg' => "「{$old}」已被 {$u} 位客戶選用，不可刪除"];
    $db->prepare("DELETE FROM cust_option WHERE opt_id=?")->execute([$optId]);
    return ['ok' => true, 'msg' => '已刪除'];
}

/** 以名稱白名單過濾逗號清單，回傳逗號字串 */
function eg_cust_opt_filter_csv(PDO $db, string $kind, string $csv): string
{
    $ok = eg_cust_opt_names($db, $kind);
    $out = [];
    foreach (explode(',', $csv) as $x) {
        $x = trim($x);
        if ($x !== '' && in_array($x, $ok, true) && !in_array($x, $out, true)) $out[] = $x;
    }
    return implode(',', $out);
}

}
