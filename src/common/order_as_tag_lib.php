<?php
/**
 * order_as_tag_lib.php — 訂單追蹤「稽核製程標籤」唯一實作
 * 建立：2026-10-02（使用者交辦）
 *
 * 使用端：views/Sales/NewOrder_Track.php（畫面）／src/store/OrderAsTag_API.php（設定與批次補設定）
 *        ／src/store/_NewOrder_Track.php（新增／編輯訂單存檔時寫入）
 *
 * ── 這個標籤在回答什麼 ──
 *   一張訂單在 AS9100 的認定上屬於哪一種：
 *     ①「單製○○」＝客戶把料送來，我們只做這一道（稽核）製程
 *     ②「全製含○○」＝從頭做到成品，而且過程中有這一道（稽核）製程
 *     ③「全製」＝從頭做到成品，沒有任何一道被列為稽核製程
 *     ④「單製非AS認證」＝只做一道，而且那一道不在 AS 認證範圍內
 *     ⑤「廠內治具」＝自己做給自己用的治具（客戶就是本公司）
 *   後續所有 AS 相關的資料認定都讀這個欄位，不要再去猜 Processing_items 的自由文字。
 *
 * ══════════════════════════════════════════════════════════════════════
 * 八個一定要先知道、不然會做錯或改壞的事
 * ══════════════════════════════════════════════════════════════════════
 * 1)【為什麼非做這個欄位不可】order_track.Processing_items 是**手打的自由文字**
 *    （實測 9,466 張主訂單共有 738 種寫法：代料成品／代料完成／齒研+齒部外研／全製 (齒研)…）。
 *    order_analysis_lib.php 只能用關鍵字規則去猜「全製還是單製」，那對報表夠用、
 *    但**對 AS 稽核不夠**——稽核要的是「這張單的認定是人確認過的」，不是程式猜的。
 *    所以本庫存的是「人選的結果」，oa_proc_class() 只在**批次補設定的建議值**那裡借用一下。
 *
 * 2)【一張訂單只有一個標籤】2026-10-02 使用者拍板單選。所以存在 order_track 上兩個欄位
 *    （as_tag_id ＋ as_tag_scope）而不是另開關聯表——單選用關聯表只會讓每個讀取端都多 JOIN 一次。
 *
 * 3)【scope 在「定義」與「訂單」上的值域刻意不同】
 *    定義（ot_as_proc_tag.scope）：稽核製程可為 single／full／**both**（both＝兩種都提供）。
 *    訂單（order_track.as_tag_scope）：只會是 single／full／**none**（none＝廠內治具這種與全製單製無關的）。
 *    一個 scope=both 的稽核製程，在畫面上會長出**兩顆**按鈕（使用者 2026-10-02 拍板）：
 *    「單製○○」與「全製含○○」，存下來才分得出是哪一種。
 *
 * 4)【固定三個選項是真的固定】全製／單製非AS認證／廠內治具 由 ot_astag_ensure_schema() 種進資料表
 *    （靠 UNIQUE(fixed_code) 保證只有一份），**不可刪除、不可改名**，設定畫面只顯示不給編輯。
 *    種子一律用 ON DUPLICATE KEY UPDATE tag_id=tag_id（真正的 no-op），
 *    否則管理員把它停用之後，下一次開頁面就會被種子語句自動復活。
 *
 * 5)【廠內治具只有「客戶＝本公司」才出現】本公司＝customer_list.is_own_company=1 那一筆
 *    （禁寫死公司 ID，見 ai-rules/16）。前端換客戶時會重算選項，**後端 ot_astag_validate() 一定要再擋一次**
 *    （鐵律8）——只擋前端等於直接打 API 就能在別家客戶的訂單上掛廠內治具。
 *
 * 6)【「不得移除已被設定的標籤」怎麼實作】使用者 2026-10-02 拍板的是「**已被使用的標籤定義不可刪除**」：
 *    ot_astag_save_defs() 刪除前先查 ot_astag_usage()，有訂單在用就擋下並回報是哪一個、幾筆，
 *    請管理員改用「停用」（停用＝新訂單不再出現這個選項，**舊訂單照樣顯示得出標籤名稱**，
 *    所以 ot_astag_label_map() 一律連停用的定義一起查，不可以只查 is_active=1）。
 *    把 scope 從 both 收窄成單一種，也等於移除了一個已經被設定的標籤，所以同一條規則一起擋。
 *
 * 7)【必選是管理員開關，預設關】使用者同時要求「嚴禁影響現有人員操作」，兩件事會牴觸。
 *    2026-10-02 拍板：程式上線當天完全不影響任何人（require_on_save 預設 0），
 *    等管理員設定好稽核製程、也把舊資料補設定完，再自己到設定頁開啟「存檔必選」。
 *    開關一律走 ot_astag_require_save()，**前端後端讀同一支**，不要各自判斷。
 *
 * 8)【DDL 不可以在交易裡跑】MySQL 的 CREATE TABLE／ALTER TABLE 會造成隱式 commit，
 *    外層 commit() 就會爆「There is no active transaction」（本專案 2026-08-03／2026-09-21 各踩過一次）。
 *    所以 ot_astag_ensure_schema() 開頭就檢查 inTransaction()，在交易中一律直接返回。
 *    另外 MySQL 的 ADD INDEX **不支援 IF NOT EXISTS**（那只有 ADD COLUMN／DROP INDEX 有），
 *    要先 SHOW INDEX 查過再決定要不要 ALTER。
 */

if (!defined('OT_ASTAG_PARAM_GROUP')) define('OT_ASTAG_PARAM_GROUP', 'ORDER_AS_TAG');

/* ══════════════════════════════════════════════════════════════════
 * 固定三個選項（種子資料；fixed_code 是程式認得的鍵，名稱只是顯示用）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('ot_astag_fixed_seed')) {
function ot_astag_fixed_seed(): array
{
    // scope 這裡填的是「訂單存下來會是哪一種」：
    //   全製→full、多製程→multi（2026-10-06 使用者拍板獨立一類，見下）、
    //   單製其他→single、廠內治具→none（與全製單製無關）
    // 序號刻意留空檔（910 / 930 / 990），讓管理員自己加的固定選項插得進來。
    // 例：「多製程」給 920 就會排成 全製 → 多製程 → 單製其他 → 廠內治具。
    // 2026-10-02 使用者拍板：四個固定選項排列 全製→多製程→單製其他→廠內治具。
    // 2026-10-06 使用者拍板：「多製程」改獨立成自己的 scope='multi'（原本借用 scope='full'、
    // 只靠 fixed_code='multi_proc' 跟「全製」區分，下游各自要記得比對 fixed_code 才不會混進
    // 全製那一類，order_analysis_lib.php 就因此疊了一層 fixed_code 特判——改成獨立 scope 之後
    // 那層特判可以拿掉，下游只要看 scope 值就對了）。scope 值本身只是程式代碼，VARCHAR(8)
    // 隨便加新值都合法（不是 DB enum），其他三個維持原值不動。
    return [
        ['fixed_code' => 'full_plain',    'proc_name' => '全製',     'scope' => 'full',   'own' => 0, 'sort' => 910],
        ['fixed_code' => 'multi_proc',    'proc_name' => '多製程',   'scope' => 'multi',  'own' => 0, 'sort' => 920],
        ['fixed_code' => 'single_non_as', 'proc_name' => '單製其他', 'scope' => 'single', 'own' => 0, 'sort' => 930],
        ['fixed_code' => 'inhouse_jig',   'proc_name' => '廠內治具', 'scope' => 'none',   'own' => 1, 'sort' => 990],
    ];
}
}

/* ══════════════════════════════════════════════════════════════════
 * 資料表與欄位（可重複執行；任何失敗都不可以害正常頁面掛掉）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('ot_astag_ensure_schema')) {
function ot_astag_ensure_schema(PDO $db): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    // 在交易中一律不碰 DDL（見檔頭第8點）；不要把 $ok 定下來，下次（非交易中）再試
    if ($db->inTransaction()) return false;
    $ok = false;
    try {
        // 便宜的探針：欄位在就代表整組都建好了，不要每個請求都跑 SHOW TABLES
        $probe = true;
        try { $db->query("SELECT as_tag_id FROM order_track LIMIT 1"); }
        catch (Throwable $e) { $probe = false; }

        if (!$probe) {
            $db->exec("CREATE TABLE IF NOT EXISTS ot_as_proc_tag (
                tag_id INT AUTO_INCREMENT PRIMARY KEY,
                kind VARCHAR(10) NOT NULL DEFAULT 'process' COMMENT 'process=稽核製程（管理員自訂）／fixed=固定三選項',
                fixed_code VARCHAR(20) DEFAULT NULL COMMENT 'kind=fixed 時的程式鍵：full_plain／single_non_as／inhouse_jig',
                process_type_id INT DEFAULT NULL COMMENT 'kind=process：製程大類 process_type.process_type_id',
                proc_name VARCHAR(40) DEFAULT NULL COMMENT '顯示用製程短名（預設取大類名稱，例：齒研）',
                sub_no_json TEXT COMMENT '指定到哪幾個製程小類（process_no.ProcessNo 的 JSON 陣列）；留空＝整個大類都算',
                scope VARCHAR(8) NOT NULL DEFAULT 'both' COMMENT 'process：single／full／both（both＝畫面長兩顆）；fixed：full／single／none',
                own_company_only TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1＝只有客戶是本公司的訂單才出現這個選項',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0＝停用（新訂單不再出現，舊訂單照樣顯示得出名稱）',
                created_by INT DEFAULT NULL, created_by_name VARCHAR(50) DEFAULT NULL, created_at DATETIME DEFAULT NULL,
                updated_by INT DEFAULT NULL, updated_by_name VARCHAR(50) DEFAULT NULL, updated_at DATETIME DEFAULT NULL,
                UNIQUE KEY uk_fixed (fixed_code),
                KEY idx_active (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單追蹤：稽核製程標籤定義（AS 認定用，唯一來源）'");

            $db->exec("CREATE TABLE IF NOT EXISTS ot_as_proc_tag_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                batch_id VARCHAR(32) NOT NULL COMMENT '同一次儲存的多筆異動共用',
                action VARCHAR(10) NOT NULL COMMENT 'add／update／delete',
                tag_id INT DEFAULT NULL,
                tag_label VARCHAR(80) DEFAULT NULL,
                detail TEXT COMMENT '異動內容（JSON）',
                created_by INT DEFAULT NULL, created_by_name VARCHAR(50) DEFAULT NULL, created_at DATETIME DEFAULT NULL,
                KEY idx_tag (tag_id), KEY idx_batch (batch_id), KEY idx_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單追蹤：稽核製程標籤定義的異動歷程'");

            $db->exec("CREATE TABLE IF NOT EXISTS ot_as_tag_order_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                old_tag_id INT DEFAULT NULL, old_scope VARCHAR(8) DEFAULT NULL, old_label VARCHAR(80) DEFAULT NULL,
                new_tag_id INT DEFAULT NULL, new_scope VARCHAR(8) DEFAULT NULL, new_label VARCHAR(80) DEFAULT NULL,
                source VARCHAR(10) NOT NULL DEFAULT 'form' COMMENT 'form=新增/編輯訂單畫面／batch=批次補設定／split=拆批沿用母訂單',
                note VARCHAR(255) DEFAULT NULL,
                created_by INT DEFAULT NULL, created_by_name VARCHAR(50) DEFAULT NULL, created_at DATETIME DEFAULT NULL,
                KEY idx_order (order_id), KEY idx_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單追蹤：訂單稽核製程標籤的「變更」歷程（第一次設定看 order_track.as_tag_at/by/src）'");

            // order_track 五個欄位，一律 nullable 不動既有資料
            $cols = [
                'as_tag_id'    => "ADD COLUMN as_tag_id INT NULL DEFAULT NULL COMMENT '稽核製程標籤定義 ot_as_proc_tag.tag_id（NULL＝尚未設定）'",
                'as_tag_scope' => "ADD COLUMN as_tag_scope VARCHAR(8) NULL DEFAULT NULL COMMENT '標籤變體：single=單製○○／full=全製含○○／none=廠內治具'",
                'as_tag_at'    => "ADD COLUMN as_tag_at DATETIME NULL DEFAULT NULL COMMENT '標籤設定時間'",
                'as_tag_by'    => "ADD COLUMN as_tag_by INT NULL DEFAULT NULL COMMENT '標籤設定人 user.id'",
                'as_tag_src'   => "ADD COLUMN as_tag_src VARCHAR(10) NULL DEFAULT NULL COMMENT '來源：form／batch／split'",
            ];
            foreach ($cols as $c => $sql) {
                try { $db->query("SELECT `$c` FROM order_track LIMIT 1"); }
                catch (Throwable $e) { try { $db->exec("ALTER TABLE order_track $sql"); } catch (Throwable $e2) {} }
            }
            // 索引：ADD INDEX 不支援 IF NOT EXISTS，先查再加
            try {
                $has = $db->query("SHOW INDEX FROM order_track WHERE Key_name='idx_as_tag'")->fetch();
                if (!$has) $db->exec("ALTER TABLE order_track ADD INDEX idx_as_tag (as_tag_id, as_tag_scope)");
            } catch (Throwable $e) {}
        }

        // 固定三選項種子（ON DUPLICATE 為 no-op，不會把管理員停用的那筆復活）
        $ins = $db->prepare("INSERT INTO ot_as_proc_tag
                (kind, fixed_code, proc_name, scope, own_company_only, sort_order, is_active, created_at, created_by_name)
                VALUES ('fixed', ?, ?, ?, ?, ?, 1, NOW(), 'system')
                ON DUPLICATE KEY UPDATE tag_id = tag_id");
        foreach (ot_astag_fixed_seed() as $f) {
            try { $ins->execute([$f['fixed_code'], $f['proc_name'], $f['scope'], $f['own'], $f['sort']]); } catch (Throwable $e) {}
        }
        // 2026-10-02 使用者要求：「單製非AS認證」改叫「單製其他」，並把內建三個的排序重新排開。
        // 只在「還是舊值」時才動（冪等）；標籤 id 沒變，所以已經綁定的訂單直接顯示新名稱。
        try {
            $db->prepare("UPDATE ot_as_proc_tag SET proc_name=? WHERE fixed_code='single_non_as' AND proc_name=?")
               ->execute(['單製其他', '單製非AS認證']);
            foreach (ot_astag_fixed_seed() as $f) {
                $db->prepare("UPDATE ot_as_proc_tag SET sort_order=? WHERE fixed_code=? AND sort_order<>?")
                   ->execute([$f['sort'], $f['fixed_code'], $f['sort']]);
            }
        } catch (Throwable $e) {}
        // 2026-10-06：「多製程」scope 由 full 改成獨立的 multi（見上方種子註解）。
        // 只在「還是舊值」時才動（冪等）：①定義本身 ②已經設定過這個標籤的訂單一併改，
        // 否則舊訂單存著 as_tag_scope='full'，分析頁會因為看不到 multi 又掉回全製那一類
        // （fixed 標籤的顯示文字本來就不看 scope，只看 proc_name，所以改這個值不影響任何
        // 畫面上看得到的文字，純粹是內部分類代碼更正，故不寫 ot_as_tag_order_log 歷程）。
        try {
            $db->exec("UPDATE ot_as_proc_tag SET scope='multi' WHERE fixed_code='multi_proc' AND scope='full'");
            $db->exec("UPDATE order_track SET as_tag_scope='multi'
                       WHERE as_tag_scope='full'
                         AND as_tag_id = (SELECT tag_id FROM ot_as_proc_tag WHERE fixed_code='multi_proc')");
        } catch (Throwable $e) {}
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}
}

/* ══════════════════════════════════════════════════════════════════
 * 設定值（system_parameters；清單很小，刻意不另建設定表）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('ot_astag_param_get')) {
function ot_astag_param_get(PDO $db, string $key, $default)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([OT_ASTAG_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return ($d === null) ? $default : $d;
    } catch (Throwable $e) { return $default; }
}
}
if (!function_exists('ot_astag_param_set')) {
function ot_astag_param_set(PDO $db, string $key, $val): bool
{
    try {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value)
                      VALUES (?,?,?) ON DUPLICATE KEY UPDATE param_value = VALUES(param_value)")
           ->execute([OT_ASTAG_PARAM_GROUP, $key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
        return true;
    } catch (Throwable $e) { return false; }
}
}
/** 存檔時是否強制必選（預設 false＝上線當天完全不影響任何人，見檔頭第7點） */
if (!function_exists('ot_astag_require_save')) {
function ot_astag_require_save(PDO $db): bool
{
    return (int)ot_astag_param_get($db, 'require_on_save', 0) === 1;
}
}

/* ══════════════════════════════════════════════════════════════════
 * 本公司判定（唯一來源 customer_list.is_own_company=1，禁寫死公司ID）
 * ══════════════════════════════════════════════════════════════════ */
if (!function_exists('ot_astag_own_company_id')) {
function ot_astag_own_company_id(PDO $db): string
{
    static $cid = null;
    if ($cid !== null) return $cid;
    $cid = '';
    try {
        $v = $db->query("SELECT customer_id FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn();
        if ($v !== false && $v !== null) $cid = trim((string)$v);
    } catch (Throwable $e) {}
    return $cid;
}
}
if (!function_exists('ot_astag_is_own_company')) {
function ot_astag_is_own_company(PDO $db, $clientId): bool
{
    $own = ot_astag_own_company_id($db);
    if ($own === '') return false;
    $c = trim((string)$clientId);
    return $c !== '' && $c === $own;
}
}

/* ══════════════════════════════════════════════════════════════════
 * 標籤定義的讀取
 * ══════════════════════════════════════════════════════════════════ */
/** sub_no_json → int[]（容錯：壞掉的 JSON 一律視為「整個大類」） */
if (!function_exists('ot_astag_sub_nos')) {
function ot_astag_sub_nos($json): array
{
    $s = trim((string)$json);
    if ($s === '') return [];
    $d = json_decode($s, true);
    if (!is_array($d)) return [];
    $out = [];
    foreach ($d as $v) { $n = (int)$v; if ($n > 0) $out[$n] = $n; }
    return array_values($out);
}
}

/**
 * 全部標籤定義（含製程大類名稱與指定小類的名稱）。
 * $onlyActive=false 時連停用的也回傳——顯示舊訂單的標籤名稱一定要用這種（見檔頭第6點）。
 */
if (!function_exists('ot_astag_defs')) {
function ot_astag_defs(PDO $db, bool $onlyActive = true): array
{
    if (!ot_astag_ensure_schema($db)) return [];
    try {
        $sql = "SELECT t.*, pt.process_type AS type_name
                FROM ot_as_proc_tag t
                LEFT JOIN process_type pt ON pt.process_type_id = t.process_type_id
                " . ($onlyActive ? "WHERE t.is_active = 1 " : "") . "
                ORDER BY t.sort_order ASC, t.tag_id ASC";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }

    // 指定小類的名稱一次查完（避免逐筆查）
    $allNos = [];
    foreach ($rows as $r) {
        foreach (ot_astag_sub_nos($r['sub_no_json'] ?? '') as $n) $allNos[$n] = true;
    }
    $nameMap = [];
    if ($allNos) {
        try {
            $ph = implode(',', array_fill(0, count($allNos), '?'));
            $st = $db->prepare("SELECT ProcessNo, ProcessName FROM process_no WHERE ProcessNo IN ($ph)");
            $st->execute(array_keys($allNos));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $nameMap[(int)$p['ProcessNo']] = (string)($p['ProcessName'] ?? '');
            }
        } catch (Throwable $e) {}
    }

    $out = [];
    foreach ($rows as $r) {
        $subs = ot_astag_sub_nos($r['sub_no_json'] ?? '');
        $subNames = [];
        foreach ($subs as $n) {
            $nm = $nameMap[$n] ?? '';
            $subNames[] = ($nm !== '' ? $nm : ('#' . $n));
        }
        $out[] = [
            'tag_id'           => (int)$r['tag_id'],
            'kind'             => (string)$r['kind'],
            'fixed_code'       => (string)($r['fixed_code'] ?? ''),
            'process_type_id'  => ($r['process_type_id'] !== null) ? (int)$r['process_type_id'] : null,
            'type_name'        => (string)($r['type_name'] ?? ''),
            'proc_name'        => (string)($r['proc_name'] ?? ''),
            'sub_nos'          => $subs,
            'sub_names'        => $subNames,
            'scope'            => (string)$r['scope'],
            'own_company_only' => (int)$r['own_company_only'],
            'sort_order'       => (int)$r['sort_order'],
            'is_active'        => (int)$r['is_active'],
            'updated_by_name'  => (string)($r['updated_by_name'] ?? ($r['created_by_name'] ?? '')),
            'updated_at'       => (string)($r['updated_at'] ?? ($r['created_at'] ?? '')),
        ];
    }
    return $out;
}
}

/** 一個定義會長出哪幾個選項變體（scope=both 的稽核製程會長兩個） */
if (!function_exists('ot_astag_variants')) {
function ot_astag_variants(array $def): array
{
    // fixed（內建三個）與 other（管理員自己加的固定選項）都只有一個變體，
    // 只有 kind=process 的稽核製程才會因為 scope=both 長出兩個
    if (in_array((string)($def['kind'] ?? ''), ['fixed', 'other'], true)) return [(string)$def['scope']];
    return ((string)$def['scope'] === 'both') ? ['single', 'full'] : [(string)$def['scope']];
}
}

/** 標籤顯示文字（唯一實作；畫面、清單、列印、批次補設定一律用這支） */
if (!function_exists('ot_astag_make_label')) {
function ot_astag_make_label(array $def, string $scope): string
{
    $name = trim((string)($def['proc_name'] ?? ''));
    // fixed／other 的標籤名稱就是 proc_name 本身，不加「單製」「全製含」前綴
    if (in_array((string)($def['kind'] ?? ''), ['fixed', 'other'], true)) return $name;
    if ($scope === 'single') return '單製' . $name;
    if ($scope === 'full')   return '全製含' . $name;
    return $name;
}
}

/** 選項的滑鼠提示：講清楚這個標籤涵蓋哪些製程小類，避免選錯 */
if (!function_exists('ot_astag_option_hint')) {
function ot_astag_option_hint(array $d, string $scope): string
{
    if (($d['kind'] ?? '') === 'fixed') {
        switch ((string)$d['fixed_code']) {
            case 'full_plain':    return '從頭做到成品，而且沒有任何一道製程被列為稽核製程';
            case 'multi_proc':    return '客戶來料，工廠做了好幾道非稽核製程，但不是做到成品也不是只做一道';
            case 'single_non_as': return '只做單一道製程，而且那一道不在 AS 認證範圍內';
            case 'inhouse_jig':   return '自己做給自己用的治具（客戶＝本公司才會出現這個選項）';
        }
        return '';
    }
    if (($d['kind'] ?? '') === 'other') {
        $m = ['full' => '歸在「全製」這一類', 'single' => '歸在「單製」這一類', 'none' => '不分全製／單製'];
        $out = ($m[(string)$scope] ?? '');
        if ($d['sub_names'])            $out .= '｜涵蓋小類：' . implode('、', $d['sub_names']);
        elseif ($d['type_name'] !== '') $out .= '｜涵蓋整個製程大類「' . $d['type_name'] . '」';
        if ($d['own_company_only'])     $out .= '｜只有客戶是本公司的訂單才會出現';
        return $out;
    }
    $base = ($scope === 'single')
        ? '客戶把料送來，我們只做這一道製程'
        : '從頭做到成品，過程中包含這一道製程';
    $cov = $d['sub_names']
        ? ('涵蓋小類：' . implode('、', $d['sub_names']))
        : ('涵蓋整個製程大類「' . $d['type_name'] . '」');
    return $base . '｜' . $cov;
}
}

/**
 * 「這張訂單可以選哪些標籤」。
 * $isOwnCompany=false 時 own_company_only 的選項（廠內治具）不出現——後端 validate 會再擋一次。
 */
if (!function_exists('ot_astag_options')) {
function ot_astag_options(PDO $db, bool $isOwnCompany): array
{
    $out = [];
    foreach (ot_astag_defs($db, true) as $d) {
        if ($d['own_company_only'] && !$isOwnCompany) continue;
        foreach (ot_astag_variants($d) as $sc) {
            $out[] = [
                'key'        => $d['tag_id'] . ':' . $sc,
                'tag_id'     => $d['tag_id'],
                'scope'      => $sc,
                'label'      => ot_astag_make_label($d, $sc),
                'kind'       => $d['kind'],
                'fixed_code' => $d['fixed_code'],
                'own_only'   => $d['own_company_only'],
                'hint'       => ot_astag_option_hint($d, $sc),
            ];
        }
    }
    return $out;
}
}

/**
 * 顯示用對照表：'tagid:scope' => label，另含 'def:tagid' => 定義本身。
 * **一定要連停用的定義一起查**，否則停用之後舊訂單的標籤名稱會變空白（檔頭第6點）。
 */
if (!function_exists('ot_astag_label_map')) {
function ot_astag_label_map(PDO $db): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (ot_astag_defs($db, false) as $d) {
        // 停用／收窄過 scope 的定義，舊訂單可能存著「現在已經不提供」的變體，四種都先備好
        // （'multi' 是 2026-10-06 起「多製程」固定標籤專用的獨立 scope，漏了這個值會讓
        // 所有已設定「多製程」的訂單在這張對照表查無標籤文字、顯示成空白分類）。
        foreach (['single', 'full', 'multi', 'none'] as $sc) {
            $map[$d['tag_id'] . ':' . $sc] = ot_astag_make_label($d, $sc);
        }
        $map['def:' . $d['tag_id']] = $d;
    }
    return $map;
}
}
/** 單一筆的顯示文字（查不到定義時回空字串，呼叫端自行決定要不要顯示） */
if (!function_exists('ot_astag_label')) {
function ot_astag_label(PDO $db, $tagId, $scope): string
{
    $tagId = (int)$tagId;
    if ($tagId <= 0) return '';
    $m = ot_astag_label_map($db);
    return (string)($m[$tagId . ':' . (string)$scope] ?? '');
}
}

/* ══════════════════════════════════════════════════════════════════
 * 給後續 AS 相關模組讀的介面（不要自己去 JOIN 這幾張表）
 * ══════════════════════════════════════════════════════════════════ */
/**
 * 一張訂單的標籤認定結果。回傳 null＝這張訂單還沒設定標籤。
 *   ['tag_id','scope','label','kind','fixed_code','proc_name','process_type_id','sub_nos',
 *    'is_full','is_single','is_jig','is_as_process','set_at','set_by','src']
 *   is_as_process：這張單是不是「含稽核製程」（固定三選項一律 false）
 */
if (!function_exists('ot_astag_for_orders')) {
function ot_astag_for_orders(PDO $db, array $orderIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!$ids) return [];
    if (!ot_astag_ensure_schema($db)) return [];
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT Order_id, as_tag_id, as_tag_scope, as_tag_at, as_tag_by, as_tag_src
                            FROM order_track WHERE Order_id IN ($ph)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }

    $map = ot_astag_label_map($db);
    $out = [];
    foreach ($rows as $r) {
        $tid = (int)($r['as_tag_id'] ?? 0);
        if ($tid <= 0) continue;
        $sc  = (string)($r['as_tag_scope'] ?? '');
        $def = $map['def:' . $tid] ?? null;
        if (!$def) continue;
        $out[(int)$r['Order_id']] = [
            'tag_id'          => $tid,
            'scope'           => $sc,
            'label'           => (string)($map[$tid . ':' . $sc] ?? ''),
            'kind'            => $def['kind'],
            'fixed_code'      => $def['fixed_code'],
            'proc_name'       => $def['proc_name'],
            'process_type_id' => $def['process_type_id'],
            'sub_nos'         => $def['sub_nos'],
            'is_full'         => ($sc === 'full'),
            'is_single'       => ($sc === 'single'),
            'is_jig'          => ($def['fixed_code'] === 'inhouse_jig'),
            'is_as_process'   => ($def['kind'] === 'process'),
            'set_at'          => (string)($r['as_tag_at'] ?? ''),
            'set_by'          => (int)($r['as_tag_by'] ?? 0),
            'src'             => (string)($r['as_tag_src'] ?? ''),
        ];
    }
    return $out;
}
}
if (!function_exists('ot_astag_for_order')) {
function ot_astag_for_order(PDO $db, int $orderId): ?array
{
    $r = ot_astag_for_orders($db, [$orderId]);
    return $r[$orderId] ?? null;
}
}

/* ══════════════════════════════════════════════════════════════════
 * 存檔與驗證
 * ══════════════════════════════════════════════════════════════════ */
/**
 * 後端守門（鐵律8：前端擋過一次，這裡用同一套規則再擋一次）。
 * @return array ['ok'=>bool,'msg'=>string,'tag_id'=>int,'scope'=>string]
 */
if (!function_exists('ot_astag_validate')) {
function ot_astag_validate(PDO $db, $tagId, $scope, $clientId, bool $allowEmpty): array
{
    $tagId = (int)$tagId;
    $scope = trim((string)$scope);
    if ($tagId <= 0) {
        if ($allowEmpty) return ['ok' => true, 'msg' => '', 'tag_id' => 0, 'scope' => ''];
        return ['ok' => false, 'msg' => '請先選擇製程標籤（管理員已開啟「存檔必選」）。', 'tag_id' => 0, 'scope' => ''];
    }
    $own = ot_astag_is_own_company($db, $clientId);
    foreach (ot_astag_options($db, $own) as $o) {
        if ((int)$o['tag_id'] === $tagId && (string)$o['scope'] === $scope) {
            return ['ok' => true, 'msg' => '', 'tag_id' => $tagId, 'scope' => $scope];
        }
    }
    // 不在可選清單內：分辨「只是客戶不對」還是「這個標籤根本不存在／已停用」
    foreach (ot_astag_options($db, true) as $o) {
        if ((int)$o['tag_id'] === $tagId && (string)$o['scope'] === $scope && $o['own_only']) {
            return ['ok' => false, 'tag_id' => 0, 'scope' => '',
                    'msg' => '「' . $o['label'] . '」只能用在客戶是本公司的訂單上，這張訂單的客戶不符。'];
        }
    }
    return ['ok' => false, 'tag_id' => 0, 'scope' => '',
            'msg' => '選到的製程標籤不存在或已停用，請重新整理頁面後再選一次。'];
}
}

/**
 * 寫入一張訂單的標籤（**唯一寫入點**；訂單存檔、批次補設定、拆批沿用都走這支）。
 * $tagId<=0 ＝清空標籤。只有內容真的改變才寫 log（第一次設定看 order_track.as_tag_at/by/src）。
 */
if (!function_exists('ot_astag_apply_to_order')) {
function ot_astag_apply_to_order(PDO $db, int $orderId, $tagId, $scope, int $uid, string $source, ?string $uname = null, ?string $note = null): bool
{
    if ($orderId <= 0) return false;
    if (!ot_astag_ensure_schema($db)) return false;
    $tagId = (int)$tagId;
    $scope = trim((string)$scope);
    try {
        $st = $db->prepare("SELECT as_tag_id, as_tag_scope FROM order_track WHERE Order_id=?");
        $st->execute([$orderId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return false;
        $oldId = (int)($cur['as_tag_id'] ?? 0);
        $oldSc = (string)($cur['as_tag_scope'] ?? '');
        if ($oldId === $tagId && $oldSc === $scope) return true;   // 沒變就什麼都不做

        if ($tagId > 0) {
            $db->prepare("UPDATE order_track SET as_tag_id=?, as_tag_scope=?, as_tag_at=NOW(), as_tag_by=?, as_tag_src=? WHERE Order_id=?")
               ->execute([$tagId, $scope, ($uid > 0 ? $uid : null), $source, $orderId]);
        } else {
            $db->prepare("UPDATE order_track SET as_tag_id=NULL, as_tag_scope=NULL, as_tag_at=NULL, as_tag_by=NULL, as_tag_src=NULL WHERE Order_id=?")
               ->execute([$orderId]);
        }
        // 只有「本來就有標籤、現在被換掉或清掉」才留逐筆歷程
        if ($oldId > 0) {
            if ($uname === null) $uname = ot_astag_uname($db, $uid);
            $map = ot_astag_label_map($db);
            $db->prepare("INSERT INTO ot_as_tag_order_log
                    (order_id, old_tag_id, old_scope, old_label, new_tag_id, new_scope, new_label, source, note, created_by, created_by_name, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$orderId, $oldId, $oldSc, (string)($map[$oldId . ':' . $oldSc] ?? ''),
                          ($tagId > 0 ? $tagId : null), ($tagId > 0 ? $scope : null),
                          ($tagId > 0 ? (string)($map[$tagId . ':' . $scope] ?? '') : null),
                          $source, $note, ($uid > 0 ? $uid : null), $uname]);
        }
        return true;
    } catch (Throwable $e) { return false; }
}
}
/** 顯示用姓名（優先中文名） */
if (!function_exists('ot_astag_uname')) {
function ot_astag_uname(PDO $db, int $uid): string
{
    if ($uid <= 0) return '';
    static $c = [];
    if (isset($c[$uid])) return $c[$uid];
    $c[$uid] = '';
    try {
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$uid]);
        $c[$uid] = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    return $c[$uid];
}
}

/** 拆批時沿用母訂單的標籤（同一張單只是拆交期，AS 認定本來就一樣） */
if (!function_exists('ot_astag_inherit')) {
function ot_astag_inherit(PDO $db, int $parentOrderId, int $childOrderId, int $uid): bool
{
    if ($parentOrderId <= 0 || $childOrderId <= 0) return false;
    try {
        $st = $db->prepare("SELECT as_tag_id, as_tag_scope FROM order_track WHERE Order_id=?");
        $st->execute([$parentOrderId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)($p['as_tag_id'] ?? 0) <= 0) return false;
        return ot_astag_apply_to_order($db, $childOrderId, (int)$p['as_tag_id'], (string)$p['as_tag_scope'], $uid, 'split');
    } catch (Throwable $e) { return false; }
}
}

/* ══════════════════════════════════════════════════════════════════
 * 標籤定義的維護（設定頁用）
 * ══════════════════════════════════════════════════════════════════ */
/** 每個標籤定義目前被幾張訂單使用（含停用的定義；逐變體與合計都回傳） */
if (!function_exists('ot_astag_usage')) {
function ot_astag_usage(PDO $db): array
{
    $out = [];
    if (!ot_astag_ensure_schema($db)) return $out;
    try {
        $rows = $db->query("SELECT as_tag_id, as_tag_scope, COUNT(*) n
                            FROM order_track WHERE as_tag_id IS NOT NULL
                            GROUP BY as_tag_id, as_tag_scope")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $t = (int)$r['as_tag_id'];
            $s = (string)($r['as_tag_scope'] ?? '');
            if (!isset($out[$t])) $out[$t] = ['total' => 0, 'by_scope' => []];
            $out[$t]['total'] += (int)$r['n'];
            $out[$t]['by_scope'][$s] = (int)$r['n'];
        }
    } catch (Throwable $e) {}
    return $out;
}
}

/**
 * 清掉「被設成某一個標籤」的全部訂單綁定（2026-10-02 使用者要求：設錯了要能重來）。
 * 那些訂單會回到「尚未設定標籤」，可以重新補設定。
 *
 * 三件刻意這樣做的事：
 *  1. 標籤定義本身**不動**（不是刪標籤，是解除訂單跟它的綁定）。
 *  2. 每一張被清掉的都**逐筆留歷程**（舊標籤 → 清空，誰、什麼時候）——
 *     這是 AS 認定的資料，被拿掉了必須查得出來是誰拿的。
 *  3. 可以只清某一個變體（scope）；不給 scope 就是這個定義底下全部。
 *
 * @return array ['ok'=>bool,'cleared'=>int,'msg'=>string]
 */
if (!function_exists('ot_astag_clear_tag')) {
function ot_astag_clear_tag(PDO $db, $tagId, $scope, int $uid, string $uname = ''): array
{
    if (!ot_astag_ensure_schema($db)) return ['ok' => false, 'cleared' => 0, 'msg' => '資料表尚未建立'];
    $tagId = (int)$tagId;
    $scope = trim((string)$scope);
    if ($tagId <= 0) return ['ok' => false, 'cleared' => 0, 'msg' => '缺少標籤'];

    $map = ot_astag_label_map($db);
    $def = $map['def:' . $tagId] ?? null;
    if (!$def) return ['ok' => false, 'cleared' => 0, 'msg' => '找不到這個標籤'];
    $name = ($def['kind'] === 'process') ? ('稽核製程「' . $def['proc_name'] . '」') : ('「' . $def['proc_name'] . '」');

    $where = "as_tag_id = ?"; $args = [$tagId];
    if ($scope !== '') { $where .= " AND as_tag_scope = ?"; $args[] = $scope; $name = '「' . (string)($map[$tagId . ':' . $scope] ?? '') . '」'; }

    try {
        $q = $db->prepare("SELECT Order_id, as_tag_id, as_tag_scope FROM order_track WHERE $where");
        $q->execute($args);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return ['ok' => false, 'cleared' => 0, 'msg' => '查詢失敗：' . $e->getMessage()]; }
    if (!$rows) return ['ok' => true, 'cleared' => 0, 'msg' => $name . ' 目前沒有任何訂單在用'];

    try {
        $u = $db->prepare("UPDATE order_track SET as_tag_id=NULL, as_tag_scope=NULL, as_tag_at=NULL, as_tag_by=NULL, as_tag_src=NULL WHERE $where");
        $u->execute($args);
        $n = $u->rowCount();
    } catch (Throwable $e) { return ['ok' => false, 'cleared' => 0, 'msg' => '清除失敗：' . $e->getMessage()]; }

    if ($uname === '') $uname = ot_astag_uname($db, $uid);
    foreach (array_chunk($rows, 300) as $chunk) {
        $vals = []; $args2 = [];
        foreach ($chunk as $o) {
            $oi = (int)($o['as_tag_id'] ?? 0); $os = (string)($o['as_tag_scope'] ?? '');
            $vals[] = '(?,?,?,?,NULL,NULL,NULL,?,?,?,?,NOW())';
            array_push($args2, (int)$o['Order_id'], $oi, $os, (string)($map[$oi . ':' . $os] ?? ''),
                       'clear', '管理員清除該標籤的全部綁定', ($uid > 0 ? $uid : null), $uname);
        }
        try {
            $db->prepare("INSERT INTO ot_as_tag_order_log
                (order_id, old_tag_id, old_scope, old_label, new_tag_id, new_scope, new_label, source, note, created_by, created_by_name, created_at)
                VALUES " . implode(',', $vals))->execute($args2);
        } catch (Throwable $e) { /* 歷程寫不進去不擋主要作業 */ }
    }
    try {
        $db->prepare("INSERT INTO ot_as_proc_tag_log (batch_id, action, tag_id, tag_label, detail, created_by, created_by_name, created_at)
                      VALUES (?,'clear',?,?,?,?,?,NOW())")
           ->execute([bin2hex(random_bytes(8)), $tagId, $name,
                      json_encode(['cleared' => $n, 'scope' => ($scope !== '' ? $scope : 'all'),
                                   'order_ids' => array_slice(array_column($rows, 'Order_id'), 0, 200)], JSON_UNESCAPED_UNICODE),
                      ($uid > 0 ? $uid : null), $uname]);
    } catch (Throwable $e) {}

    return ['ok' => true, 'cleared' => $n,
            'msg' => '已清除 ' . $n . ' 張訂單與 ' . $name . ' 的綁定，那些訂單回到「尚未設定標籤」可以重新設。'];
}
}

/** 某個標籤目前被「客戶不是本公司」的訂單用了幾筆（勾「限本公司」前要先檢查） */
if (!function_exists('ot_astag_usage_non_own')) {
function ot_astag_usage_non_own(PDO $db, int $tagId): int
{
    $own = ot_astag_own_company_id($db);
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM order_track
                            WHERE as_tag_id = ? AND (Client_name_ID IS NULL OR TRIM(Client_name_ID) <> ?)");
        $st->execute([$tagId, $own]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
}

/** 製程大類＋小類（設定頁的挑選器用；只列啟用的大類，但小類全列） */
if (!function_exists('ot_astag_process_tree')) {
function ot_astag_process_tree(PDO $db): array
{
    $out = [];
    try {
        $types = $db->query("SELECT process_type_id, process_type FROM process_type
                             WHERE is_active=1 ORDER BY sort_order ASC, process_type_id ASC")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $subs = $db->query("SELECT ProcessNo, ProcessName, process_type_id FROM process_no
                            WHERE process_type_id IS NOT NULL ORDER BY process_type_id ASC, ProcessNo ASC")
                   ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byType = [];
        foreach ($subs as $s) {
            $nm = trim((string)($s['ProcessName'] ?? ''));
            if ($nm === '') continue;   // 沒有名稱的小類（實測 4 筆）選了也看不出是什麼，不列
            $byType[(int)$s['process_type_id']][] = ['no' => (int)$s['ProcessNo'], 'name' => $nm];
        }
        foreach ($types as $t) {
            $id = (int)$t['process_type_id'];
            $out[] = [
                'process_type_id' => $id,
                'process_type'    => (string)($t['process_type'] ?? ''),
                'subs'            => $byType[$id] ?? [],
            ];
        }
    } catch (Throwable $e) { return []; }
    return $out;
}
}

/**
 * 儲存稽核製程標籤定義（整份送上來，後端自己 diff 出新增／修改／刪除）。
 * 固定三選項不在 $rows 裡，也永遠不會被這支動到。
 *
 * @param array $rows 每筆：['tag_id'=>int|0, 'process_type_id'=>int, 'proc_name'=>string,
 *                           'sub_nos'=>int[], 'scope'=>'single|full|both', 'is_active'=>0|1, 'sort_order'=>int]
 * @return array ['ok'=>bool, 'errors'=>string[], 'added'=>int,'updated'=>int,'deleted'=>int]
 */
if (!function_exists('ot_astag_save_defs')) {
function ot_astag_save_defs(PDO $db, array $rows, int $uid, string $uname = ''): array
{
    if (!ot_astag_ensure_schema($db)) return ['ok' => false, 'errors' => ['資料表尚未建立，請重新整理頁面再試。']];
    if ($uname === '') $uname = ot_astag_uname($db, $uid);

    $tree = ot_astag_process_tree($db);
    $typeOk = []; $typeName = []; $subOk = [];
    foreach ($tree as $t) {
        $typeOk[$t['process_type_id']] = true;
        $typeName[$t['process_type_id']] = $t['process_type'];
        foreach ($t['subs'] as $s) $subOk[$t['process_type_id']][(int)$s['no']] = true;
    }

    // 本支管得到的是 kind=process（稽核製程）與 kind=other（管理員自己加的固定選項）；
    // kind=fixed 的內建三個永遠不會被這裡動到（也不在 $rows 裡）。
    $existing = [];      // tag_id => def
    $builtinLabels = []; // 內建三個的標籤名稱，用來檔下「自己加了一個也叫全製」
    foreach (ot_astag_defs($db, false) as $d) {
        if ($d['kind'] === 'process' || $d['kind'] === 'other') { $existing[$d['tag_id']] = $d; continue; }
        if ($d['kind'] === 'fixed' && $d['is_active']) $builtinLabels[$d['proc_name']] = true;
    }
    $usage = ot_astag_usage($db);

    // ── 驗證 ─────────────────────────────────────────────────────────
    $errors = []; $clean = []; $labelSeen = [];
    foreach ($rows as $i => $r) {
        $n    = $i + 1;
        $kind = ((string)($r['kind'] ?? 'process') === 'other') ? 'other' : 'process';
        $tid  = (int)($r['tag_id'] ?? 0);
        $pt   = (int)($r['process_type_id'] ?? 0);
        $sc   = (string)($r['scope'] ?? ($kind === 'other' ? 'none' : 'both'));
        $act  = !empty($r['is_active']) ? 1 : 0;
        $nm   = trim((string)($r['proc_name'] ?? ''));
        $own  = 0;
        $subs = [];
        $where = ($kind === 'other') ? '其他固定選項第 ' . $n . ' 列' : '稽核製程第 ' . $n . ' 列';

        if ($kind === 'other') {
            // 管理員自己加的固定選項（2026-10-02 使用者要求）：
            // 跟稽核製程一樣可以綁製程大類／小類（供後續 AS 認定知道它涵蓋哪些製程），
            // 差別只在標籤怎麼顯示：這裡**直接用短名本身**（比照內建的全製／廠內治具），
            // 不加「單製」「全製含」前缀。製程大類**可以不綁**（比照廠內治具那種根本不對應製程的）。
            if ($nm === '') { $errors[] = "{$where}：請填寫顯示用的短名"; continue; }
            if (!in_array($sc, ['single', 'full', 'none'], true)) { $errors[] = "{$where}：適用範圍只能是全製類／單製類／不分"; continue; }
            $own = !empty($r['own_company_only']) ? 1 : 0;
            if ($pt > 0) {
                if (empty($typeOk[$pt])) { $errors[] = "{$where}：所選的製程大類不存在或已停用"; continue; }
                foreach ((array)($r['sub_nos'] ?? []) as $v) { $x = (int)$v; if ($x > 0) $subs[$x] = $x; }
                $subs = array_values($subs);
                foreach ($subs as $x) {
                    if (empty($subOk[$pt][$x])) { $errors[] = "{$where}：製程小類 #{$x} 不屬於所選的製程大類"; continue 2; }
                }
            } else {
                $pt = 0; $subs = [];   // 沒綁大類就不可能有小類
            }
        } else {
            foreach ((array)($r['sub_nos'] ?? []) as $v) { $x = (int)$v; if ($x > 0) $subs[$x] = $x; }
            $subs = array_values($subs);
            if ($pt <= 0 || empty($typeOk[$pt])) { $errors[] = "{$where}：請選擇一個有效的製程大類"; continue; }
            if ($nm === '') $nm = (string)($typeName[$pt] ?? '');
            if ($nm === '') { $errors[] = "{$where}：請填寫顯示用的製程短名"; continue; }
            if (!in_array($sc, ['single', 'full', 'both'], true)) { $errors[] = "{$where}：適用範圍只能是單製／全製／兩者皆可"; continue; }
            foreach ($subs as $x) {
                if (empty($subOk[$pt][$x])) { $errors[] = "{$where}：製程小類 #{$x} 不屬於所選的製程大類"; continue 2; }
            }
        }
        $nm = mb_substr($nm, 0, 20, 'UTF-8');

        // 啟用中的標籤「最後顯示出來的名稱」不可重複（否則畫面上會出現兩顆一模一樣的
        // 按鈕、根本選不對）；連內建三個一起比，不然自己加一個也叫「全製」就分不出來。
        if ($act) {
            $defForLabel = ['kind' => $kind, 'proc_name' => $nm, 'scope' => $sc];
            foreach (ot_astag_variants($defForLabel) as $vv) {
                $lb = ot_astag_make_label($defForLabel, $vv);
                if (isset($builtinLabels[$lb])) { $errors[] = "{$where}：「{$lb}」跟內建的固定選項同名，請改用別的名稱"; continue 2; }
                if (isset($labelSeen[$lb])) { $errors[] = "{$where}：「{$lb}」與{$labelSeen[$lb]}重複"; continue 2; }
                $labelSeen[$lb] = $where;
            }
        }

        if ($tid > 0 && isset($existing[$tid])) {
            $old = $existing[$tid];
            $usedTotal = (int)($usage[$tid]['total'] ?? 0);
            // 已經被訂單使用的變體不可以被收窄掉（＝等於移除了一個已設定的標籤，見檔頭第6點）
            foreach (array_diff(ot_astag_variants($old), ot_astag_variants(['kind' => $kind, 'scope' => $sc])) as $gone) {
                $cnt = (int)($usage[$tid]['by_scope'][$gone] ?? 0);
                if ($cnt > 0) {
                    $lbl = ot_astag_make_label($old, $gone);
                    $errors[] = "{$where}：「{$lbl}」已經有 {$cnt} 張訂單在用，不可以改掉適用範圍把它移除；"
                              . "要停止使用請改用「停用」。";
                }
            }
            // 把「限本公司」打開但已經有別家客戶的訂單在用，那些訂單會變成不合法的組合
            if ($own && !$old['own_company_only'] && $usedTotal > 0) {
                $bad = ot_astag_usage_non_own($db, $tid);
                if ($bad > 0) {
                    $errors[] = "{$where}：勾了「限本公司」，但目前有 {$bad} 張「客戶不是本公司」的訂單在用這個標籤，"
                              . "請先把那些訂單改成別的標籤。";
                }
            }
        }
        $clean[] = ['kind' => $kind, 'tag_id' => $tid, 'process_type_id' => ($pt > 0 ? $pt : null),
                    'proc_name' => $nm, 'sub_nos' => $subs, 'scope' => $sc,
                    'own_company_only' => $own, 'is_active' => $act,
                    'sort_order' => max(0, min(9999, (int)($r['sort_order'] ?? 0)))];
    }

    // 不在送上來的清單裡＝要刪除；有訂單在用一律擋下
    $keep = [];
    foreach ($clean as $c) if ($c['tag_id'] > 0) $keep[$c['tag_id']] = true;
    $toDelete = [];
    foreach ($existing as $tid => $d) {
        if (isset($keep[$tid])) continue;
        $cnt = (int)($usage[$tid]['total'] ?? 0);
        if ($cnt > 0) {
            // 訊息裡只講名稱，不要套某一個變體的標籤——both 的標籤若寫成「單製齒研」，
            // 而實際在用的是「全製含齒研」，管理員會以為系統抓錯
            $kindWord = ($d['kind'] === 'other') ? '固定選項' : '稽核製程標籤';
            $errors[] = "{$kindWord}「" . $d['proc_name'] . "」已經有 {$cnt} 張訂單在用，不可以刪除；"
                      . "要停止使用請把它改成「停用」（舊訂單的標籤名稱照樣顯示得出來）。";
        } else {
            $toDelete[] = $tid;
        }
    }

    if ($errors) return ['ok' => false, 'errors' => $errors, 'added' => 0, 'updated' => 0, 'deleted' => 0];

    // ── 寫入（整批一個交易；DDL 已在前面做完，這裡不會有隱式 commit）────
    $batch = bin2hex(random_bytes(8));
    $added = 0; $updated = 0; $deleted = 0;
    $committed = false;
    try {
        $db->beginTransaction();
        $logSt = $db->prepare("INSERT INTO ot_as_proc_tag_log (batch_id, action, tag_id, tag_label, detail, created_by, created_by_name, created_at)
                               VALUES (?,?,?,?,?,?,?,NOW())");
        // 排序：稽核製程排前面（10,20,…），管理員自己加的固定選項排到內建三個（900~920）
        // 後面（1000 起），讓畮面上「稽核製程 → 內建三個 → 自訂選項」的順序固定不變。
        $seqP = 0; $seqO = 0;
        foreach ($clean as $c) {
            $isOther = ($c['kind'] === 'other');
            $subJson = $c['sub_nos'] ? json_encode(array_values($c['sub_nos'])) : null;
            // 稽核製程：依畫面順序 10,20,…；其他固定選項：用管理員自己填的「順序」
            // （內建是 910/930/990，所以填 920 就會插在全製與單製其他之間）。沒填或填 0 就排最後。
            $sort    = $isOther ? ((int)$c['sort_order'] > 0 ? (int)$c['sort_order'] : (995 + (++$seqO))) : ((++$seqP) * 10);
            $label   = ot_astag_make_label($c, ot_astag_variants($c)[0]);
            if ($c['tag_id'] > 0 && isset($existing[$c['tag_id']])) {
                $db->prepare("UPDATE ot_as_proc_tag SET kind=?, process_type_id=?, proc_name=?, sub_no_json=?, scope=?,
                                     own_company_only=?, is_active=?, sort_order=?, updated_by=?, updated_by_name=?, updated_at=NOW()
                              WHERE tag_id=? AND kind IN ('process','other')")
                   ->execute([$c['kind'], $c['process_type_id'], $c['proc_name'], $subJson, $c['scope'],
                              $c['own_company_only'], $c['is_active'], $sort, ($uid > 0 ? $uid : null), $uname, $c['tag_id']]);
                $updated++;
                $logSt->execute([$batch, 'update', $c['tag_id'], $label,
                                 json_encode(['old' => $existing[$c['tag_id']], 'new' => $c], JSON_UNESCAPED_UNICODE),
                                 ($uid > 0 ? $uid : null), $uname]);
            } else {
                $db->prepare("INSERT INTO ot_as_proc_tag (kind, process_type_id, proc_name, sub_no_json, scope,
                                     own_company_only, sort_order, is_active, created_by, created_by_name, created_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
                   ->execute([$c['kind'], $c['process_type_id'], $c['proc_name'], $subJson, $c['scope'],
                              $c['own_company_only'], $sort, $c['is_active'], ($uid > 0 ? $uid : null), $uname]);
                $newId = (int)$db->lastInsertId();
                $added++;
                $logSt->execute([$batch, 'add', $newId, $label, json_encode($c, JSON_UNESCAPED_UNICODE),
                                 ($uid > 0 ? $uid : null), $uname]);
            }
        }
        foreach ($toDelete as $tid) {
            $d = $existing[$tid];
            // kind 限定 process/other：內建的 fixed 三個永遠刪不掉
            $db->prepare("DELETE FROM ot_as_proc_tag WHERE tag_id=? AND kind IN ('process','other')")->execute([$tid]);
            $deleted++;
            $logSt->execute([$batch, 'delete', $tid, ot_astag_make_label($d, ot_astag_variants($d)[0]),
                             json_encode($d, JSON_UNESCAPED_UNICODE), ($uid > 0 ? $uid : null), $uname]);
        }
        $db->commit();
        $committed = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $e2) {} }
        return ['ok' => false, 'errors' => ['儲存失敗：' . $e->getMessage()], 'added' => 0, 'updated' => 0, 'deleted' => 0];
    }
    return ['ok' => $committed, 'errors' => [], 'added' => $added, 'updated' => $updated, 'deleted' => $deleted];
}
}

/* ══════════════════════════════════════════════════════════════════
 * 批次補設定（舊資料；使用者原話「全部設定完之後就不需要使用」）
 * ══════════════════════════════════════════════════════════════════ */
/** 補設定的共用 WHERE（只處理「還沒有標籤」的，永不覆蓋已設定的——這是使用者明確要求的） */
if (!function_exists('ot_astag_backfill_where')) {
function ot_astag_backfill_where(array $f, array &$params): string
{
    $w = ['1=1'];
    // 預設只處理「還沒有標籤」的；要改已經綁定好的（使用者 2026-10-02 要求）
    // 才帶 include_tagged，而且寫入那邊還要再帶一次 overwrite 才真的會覆蓋——
    // 兩個旗標分開，光是「看得到」不會不小心改到東西。
    if (empty($f['include_tagged'])) $w[] = "ot.as_tag_id IS NULL";
    // 只看某一個標籤（'tagid:scope'），管理員要改「被設成⑨⑨的那些單」時用
    $onlyTag = trim((string)($f['only_tag'] ?? ''));
    if ($onlyTag !== '' && preg_match('/^(\d+):(single|full|none|multi)$/', $onlyTag, $m)) {
        $w[] = "ot.as_tag_id = :bf_only_tag AND ot.as_tag_scope = :bf_only_scope";
        $params[':bf_only_tag']   = (int)$m[1];
        $params[':bf_only_scope'] = $m[2];
    }
    // 已取消的訂單預設不列（那不是實際接到的單，硬要補標籤只是製造雜訊）
    if (empty($f['include_cancelled'])) $w[] = "(ot.Order_status IS NULL OR ot.Order_status <> 6)";
    $y = (string)($f['year'] ?? '');
    if ($y !== '' && $y !== 'ALL' && ctype_digit($y)) {
        $w[] = "ot.Order_date >= :bf_y1 AND ot.Order_date < :bf_y2";
        $params[':bf_y1'] = $y . '-01-01';
        $params[':bf_y2'] = ((int)$y + 1) . '-01-01';
    }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $w[] = "(ot.Processing_items LIKE :bf_kw OR ot.d_id LIKE :bf_kw OR ot.Client_name LIKE :bf_kw OR ot.Order_oo LIKE :bf_kw)";
        $params[':bf_kw'] = '%' . $kw . '%';
    }
    return implode(' AND ', $w);
}
}

/** 還沒設定標籤的訂單總數（含已取消的另計，讓畫面講清楚） */
if (!function_exists('ot_astag_backfill_summary')) {
function ot_astag_backfill_summary(PDO $db): array
{
    $out = ['untagged' => 0, 'untagged_cancelled' => 0, 'tagged' => 0, 'total' => 0];
    if (!ot_astag_ensure_schema($db)) return $out;
    try {
        $r = $db->query("SELECT
                COUNT(*) total,
                SUM(CASE WHEN as_tag_id IS NOT NULL THEN 1 ELSE 0 END) tagged,
                SUM(CASE WHEN as_tag_id IS NULL AND (Order_status IS NULL OR Order_status <> 6) THEN 1 ELSE 0 END) untagged,
                SUM(CASE WHEN as_tag_id IS NULL AND Order_status = 6 THEN 1 ELSE 0 END) untagged_cancelled
                FROM order_track")->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (array_keys($out) as $k) $out[$k] = (int)($r[$k] ?? 0);
    } catch (Throwable $e) {}
    return $out;
}
}

/**
 * 依「製程文字」把還沒設定標籤的訂單分組（738 種寫法，一組一組設最省事）。
 * 每組附一個**建議標籤**：全製／單製的判定直接借 order_analysis_lib.php 的關鍵字規則
 * （不要在這裡另寫一套，否則同一張單在訂單分析與這裡會得到兩種答案＝鐵律4）。
 */
if (!function_exists('ot_astag_backfill_groups')) {
function ot_astag_backfill_groups(PDO $db, array $f = [], int $limit = 200): array
{
    if (!ot_astag_ensure_schema($db)) return ['rows' => [], 'total_groups' => 0];
    $params = [];
    $where = ot_astag_backfill_where($f, $params);
    try {
        $sql = "SELECT TRIM(COALESCE(ot.Processing_items,'')) pi, COUNT(*) n,
                       MIN(ot.Order_date) d1, MAX(ot.Order_date) d2,
                       SUM(CASE WHEN ot.Client_name_ID = :bf_own THEN 1 ELSE 0 END) own_n
                FROM order_track ot
                WHERE $where
                GROUP BY pi
                ORDER BY n DESC, pi ASC
                LIMIT " . max(1, min(1000, $limit));
        $params[':bf_own'] = ot_astag_own_company_id($db);
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cntSql = "SELECT COUNT(*) FROM (SELECT TRIM(COALESCE(ot.Processing_items,'')) pi FROM order_track ot WHERE $where GROUP BY pi) x";
        $cst = $db->prepare($cntSql);
        unset($params[':bf_own']);
        $cst->execute($params);
        $totalGroups = (int)$cst->fetchColumn();
    } catch (Throwable $e) { return ['rows' => [], 'total_groups' => 0]; }

    // 2026-10-06 使用者交辦：批次補設定要能看到「已經設定過的」再用關鍵字＋已設定標籤篩選——
    // 「顯示：全部（含已設定）」切過去時，這裡也要讓管理員看得出每一組目前掛著什麼標籤，
    // 不然會誤以為「套用這組」把整組都蓋過去了（實際上套用一律只補空白，見 ot_astag_backfill_apply()
    // 的 $guard：沒有 overwrite 就一定只動 as_tag_id IS NULL 的那幾筆，這裡只是把現況攤開來看）。
    // 只在真的有要看「含已設定」時才多查一次，預設路徑（只看未設定）不多付這個成本。
    $breakdown = [];
    if (!empty($f['include_tagged']) && $rows) {
        try {
            // $where 本身是 ot_astag_backfill_where() 組出來的具名參數（:bf_kw 等），PDO 不允許
            // 具名／匿名參數混用在同一句，所以 pi 清單也要用具名佔位符，不能用 IN (?,?,...)
            $pis = array_values(array_unique(array_map(fn($r) => (string)$r['pi'], $rows)));
            $phKeys = []; $bParams = $params;
            foreach ($pis as $i => $v) { $k = ":bf_pi$i"; $phKeys[] = $k; $bParams[$k] = $v; }
            $bSql = "SELECT TRIM(COALESCE(ot.Processing_items,'')) pi, ot.as_tag_id, ot.as_tag_scope, COUNT(*) c
                     FROM order_track ot
                     WHERE $where AND ot.as_tag_id IS NOT NULL
                       AND TRIM(COALESCE(ot.Processing_items,'')) IN (" . implode(',', $phKeys) . ")
                     GROUP BY pi, ot.as_tag_id, ot.as_tag_scope";
            $bSt = $db->prepare($bSql);
            $bSt->execute($bParams);
            $map = ot_astag_label_map($db);
            foreach ($bSt->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $tid = (int)$b['as_tag_id']; $sc = (string)$b['as_tag_scope'];
                $breakdown[(string)$b['pi']][] = ['label' => (string)($map[$tid . ':' . $sc] ?? ($tid . ':' . $sc)), 'count' => (int)$b['c']];
            }
        } catch (Throwable $e) { $breakdown = []; }
    }

    $sug = ot_astag_suggester($db);
    $out = [];
    foreach ($rows as $r) {
        $pi  = (string)$r['pi'];
        $s   = $sug($pi, (int)$r['own_n'] > 0);
        $tb  = $breakdown[$pi] ?? [];
        $taggedN = 0; foreach ($tb as $x) $taggedN += $x['count'];
        $out[] = [
            'pi'         => $pi,
            'n'          => (int)$r['n'],
            'own_n'      => (int)$r['own_n'],
            'date_from'  => (string)($r['d1'] ?? ''),
            'date_to'    => (string)($r['d2'] ?? ''),
            'suggest'    => $s['key'],
            'suggest_label' => $s['label'],
            'suggest_why'   => $s['why'],
            'tag_breakdown' => $tb,                 // 這一組目前已經掛著哪些標籤（含筆數），空陣列＝這一組全部還沒設定
            'tagged_n'      => $taggedN,
            'untagged_n'    => max(0, (int)$r['n'] - $taggedN),
        ];
    }
    return ['rows' => $out, 'total_groups' => $totalGroups];
}
}

/**
 * 建議標籤的產生器（回傳一個 closure，整批共用同一份定義與規則，不要逐筆重查）。
 * 回傳 ['key'=>'12:full'|'', 'label'=>'', 'why'=>'']。建議只是建議，一律要人按下確認才會寫入。
 */
if (!function_exists('ot_astag_suggester')) {
function ot_astag_suggester(PDO $db): callable
{
    $defs = ot_astag_defs($db, true);
    // 全製／單製沿用訂單分析那一套關鍵字規則（唯一實作在 order_analysis_lib.php）。
    // 刻意在這裡才 require：那支會連帶載入 client_quarter_lib／data_audit_lib，
    // 不該讓「只是開個訂單清單」的頁面也付這個成本。
    $rules = null; $fallback = 'single';
    try {
        require_once __DIR__ . '/order_analysis_lib.php';
        if (function_exists('oa_proc_rules')) {
            $rules    = oa_proc_rules($db);
            $fallback = oa_proc_fallback($db);
        }
    } catch (Throwable $e) { $rules = null; }

    // 比對用的詞表：製程短名、製程大類名稱、以及該標籤涵蓋的小類名稱。
    // **涵蓋整個大類（沒有指定小類）時，要把那個大類底下全部小類的名稱都算進來**——
    // 不然「2GG1磨齒+刻印」這種寫法會漏掉（磨齒本來就在齒研大類底下，只是字面上沒有「齒研」兩個字）。
    // 長詞優先（免得「滾齒」先吃掉「精滾齒」）；命中的詞會原樣寫在建議理由裡，
    // 所以萬一某個小類名稱太通用（例如孔研底下的「加工」）造成誤判，管理員按下確認之前看得出來。
    $subsByType = [];
    foreach (ot_astag_process_tree($db) as $t) {
        foreach ($t['subs'] as $s) $subsByType[(int)$t['process_type_id']][] = (string)$s['name'];
    }
    $words = [];
    foreach ($defs as $d) {
        if ($d['kind'] !== 'process') continue;
        $cand = [];
        if ($d['proc_name'] !== '') $cand[] = $d['proc_name'];
        if ($d['type_name'] !== '') $cand[] = $d['type_name'];
        if ($d['sub_nos']) {
            foreach ($d['sub_names'] as $sn) if ($sn !== '' && $sn[0] !== '#') $cand[] = $sn;
        } else {
            foreach (($subsByType[(int)$d['process_type_id']] ?? []) as $sn) $cand[] = $sn;
        }
        foreach (array_unique($cand) as $w) {
            if (mb_strlen($w, 'UTF-8') < 2) continue;   // 一個字的詞太容易亂命中
            $words[] = ['w' => $w, 'len' => mb_strlen($w, 'UTF-8'), 'def' => $d];
        }
    }
    usort($words, function ($a, $b) {
        if ($a['len'] !== $b['len']) return $b['len'] - $a['len'];
        return $a['def']['sort_order'] - $b['def']['sort_order'];
    });
    $fixed = [];
    foreach ($defs as $d) if ($d['kind'] === 'fixed') $fixed[$d['fixed_code']] = $d;

    return function (string $text, bool $hasOwnCompany = false) use ($words, $fixed, $rules, $fallback) {
        $none = ['key' => '', 'label' => '', 'why' => ''];
        $t = trim($text);
        if ($t === '') return ['key' => '', 'label' => '', 'why' => '沒有填製程，無法建議'];

        // 全製還是單製
        $cls = $fallback;
        $ruleName = '';
        if (is_array($rules) && function_exists('oa_proc_class')) {
            $c = oa_proc_class($t, $rules, $fallback);
            $cls = (string)($c['cls'] ?? $fallback);
            $ruleName = (string)($c['rule'] ?? '');
        }
        if (!in_array($cls, ['full', 'single'], true)) return ['key' => '', 'label' => '', 'why' => '製程文字判不出全製或單製'];

        // 有沒有命中某個稽核製程
        foreach ($words as $x) {
            if (mb_stripos($t, $x['w'], 0, 'UTF-8') === false) continue;
            $d = $x['def'];
            $vars = ot_astag_variants($d);
            if (!in_array($cls, $vars, true)) {
                // 命中了這個製程，但管理員沒有為這一種（單製/全製）開放選項
                return ['key' => '', 'label' => '',
                        'why' => '製程文字含「' . $x['w'] . '」，但「' . ($cls === 'full' ? '全製' : '單製') . '」沒有開放這個標籤'];
            }
            return ['key' => $d['tag_id'] . ':' . $cls,
                    'label' => ot_astag_make_label($d, $cls),
                    'why' => '製程文字含「' . $x['w'] . '」'
                           . ($ruleName !== '' ? ('，並命中全製規則「' . $ruleName . '」') : '，未命中全製關鍵字故視為單製')];
        }
        // 沒有命中任何稽核製程 → 退回固定選項
        $fc = ($cls === 'full') ? 'full_plain' : 'single_non_as';
        if (!isset($fixed[$fc])) return $none;
        return ['key' => $fixed[$fc]['tag_id'] . ':' . $fixed[$fc]['scope'],
                'label' => $fixed[$fc]['proc_name'],
                'why' => '沒有命中任何稽核製程'
                       . ($ruleName !== '' ? ('，並命中全製規則「' . $ruleName . '」') : '，也沒有全製關鍵字')];
    };
}
}

/** 補設定：還沒設定標籤的訂單逐筆清單（分頁） */
if (!function_exists('ot_astag_backfill_orders')) {
function ot_astag_backfill_orders(PDO $db, array $f = [], int $page = 1, int $per = 20): array
{
    if (!ot_astag_ensure_schema($db)) return ['rows' => [], 'total' => 0];
    $params = [];
    $where = ot_astag_backfill_where($f, $params);
    $pi = ($f['pi_exact'] ?? null);
    if ($pi !== null) {
        $where .= " AND TRIM(COALESCE(ot.Processing_items,'')) = :bf_pi";
        $params[':bf_pi'] = (string)$pi;
    }
    $per  = max(5, min(100, $per));
    $page = max(1, $page);
    $off  = ($page - 1) * $per;
    try {
        $cst = $db->prepare("SELECT COUNT(*) FROM order_track ot WHERE $where");
        $cst->execute($params);
        $total = (int)$cst->fetchColumn();

        $st = $db->prepare("SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Client_name, ot.Client_name_ID,
                                   ot.Processing_items, ot.Qty, ot.Order_date, ot.Order_status,
                                   ot.as_tag_id, ot.as_tag_scope, ot.as_tag_src, ot.as_tag_at,
                                   cl.customer AS cl_name
                            FROM order_track ot
                            LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                            WHERE $where
                            ORDER BY ot.Order_date DESC, ot.Order_id DESC
                            LIMIT $per OFFSET $off");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return ['rows' => [], 'total' => 0]; }

    $sug = ot_astag_suggester($db);
    $own = ot_astag_own_company_id($db);
    $lblMap = ot_astag_label_map($db);
    $out = [];
    foreach ($rows as $r) {
        $isOwn = ($own !== '' && trim((string)($r['Client_name_ID'] ?? '')) === $own);
        $s = $sug((string)($r['Processing_items'] ?? ''), $isOwn);
        $curId  = (int)($r['as_tag_id'] ?? 0);
        $curKey = $curId > 0 ? ($curId . ':' . (string)($r['as_tag_scope'] ?? '')) : '';
        $out[] = [
            'cur_key'   => $curKey,
            'cur_label' => $curKey !== '' ? (string)($lblMap[$curKey] ?? '') : '',
            'cur_src'   => (string)($r['as_tag_src'] ?? ''),
            'cur_at'    => (string)($r['as_tag_at'] ?? ''),
            'order_id'   => (int)$r['Order_id'],
            'order_no'   => (string)($r['Order_oo'] ?? ''),
            'part_no'    => (string)($r['d_id'] ?? ''),
            'client'     => (string)($r['cl_name'] ?? ($r['Client_name'] ?? '')),
            'is_own'     => $isOwn ? 1 : 0,
            'process'    => (string)($r['Processing_items'] ?? ''),
            'qty'        => (int)($r['Qty'] ?? 0),
            'order_date' => (string)($r['Order_date'] ?? ''),
            'suggest'    => $s['key'],
            'suggest_label' => $s['label'],
            'suggest_why'   => $s['why'],
        ];
    }
    return ['rows' => $out, 'total' => $total, 'page' => $page, 'per' => $per];
}
}

/**
 * 批次補設定：把一個標籤套到符合條件的訂單上。
 * 兩種用法：
 *   ① 整組（同一個製程文字）：$f['pi_exact'] 給那組文字
 *   ② 逐筆勾選：$orderIds 給訂單 id 清單
 * 預設**只填空白、不覆蓋已設定的**，靠 WHERE as_tag_id IS NULL 保證；$f['overwrite']=1 時才會
 * 覆蓋已經設定好的（2026-10-06 使用者交辦放寬，見下方 overwrite 守門的註解），這種情況下一律
 * 強制視同 include_tagged=1（不然 ot_astag_backfill_where() 預設加的 as_tag_id IS NULL 會把
 * 已設定的那幾筆連同 WHERE 本身就濾掉，overwrite 傳了也沒用——呼叫端忘記一起帶 include_tagged
 * 不該讓這支函式悄悄變成 0 筆，一定要在這裡自己把兩者綁在一起，不要靠呼叫端自己記得）。
 * @return array ['ok'=>bool,'applied'=>int,'msg'=>string]
 */
if (!function_exists('ot_astag_backfill_apply')) {
function ot_astag_backfill_apply(PDO $db, $tagId, $scope, array $f = [], array $orderIds = [], int $uid = 0, string $uname = ''): array
{
    if (!ot_astag_ensure_schema($db)) return ['ok' => false, 'applied' => 0, 'msg' => '資料表尚未建立'];
    $tagId = (int)$tagId;
    $scope = trim((string)$scope);
    if ($tagId <= 0) return ['ok' => false, 'applied' => 0, 'msg' => '請選擇要套用的標籤'];
    if (!empty($f['overwrite'])) $f['include_tagged'] = 1;

    // 標籤本身要存在且啟用、變體要真的提供（不檢查客戶，下面依訂單逐批判定）
    $okVariant = false; $isOwnOnly = false; $label = '';
    foreach (ot_astag_defs($db, true) as $d) {
        if ($d['tag_id'] !== $tagId) continue;
        if (in_array($scope, ot_astag_variants($d), true)) {
            $okVariant = true; $isOwnOnly = (bool)$d['own_company_only']; $label = ot_astag_make_label($d, $scope);
        }
    }
    if (!$okVariant) return ['ok' => false, 'applied' => 0, 'msg' => '選到的標籤不存在、已停用，或這個適用範圍沒有開放'];

    $params = [];
    $where  = ot_astag_backfill_where($f, $params);
    if (isset($f['pi_exact'])) {
        $where .= " AND TRIM(COALESCE(ot.Processing_items,'')) = :bf_pi";
        $params[':bf_pi'] = (string)$f['pi_exact'];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if ($ids) {
        $where .= " AND ot.Order_id IN (" . implode(',', $ids) . ")";
    } elseif (!isset($f['pi_exact'])) {
        return ['ok' => false, 'applied' => 0, 'msg' => '請先選定一組製程文字或勾選要設定的訂單（避免整批誤套）'];
    }
    // 覆蓋模式原本一律只能「勾選的那幾張」，2026-10-06 使用者交辦放寬：
    // 「依製程文字分組」畫面上管理員看得到這一組目前掛著什麼標籤（目前標籤欄）、幾張，
    // 選定單一組（pi_exact，精確比對，不是模糊的 kw）也視同「已經審視過範圍」一併放行；
    // 真正要擋的是**只靠關鍵字模糊比對**就整批覆蓋（那才是「一個關鍵字掃掉幾百張」的原風險），
    // 所以這裡只要有 pi_exact 或 $ids 任一個就放行，純粹只有 kw/year 條件、兩者都沒有才擋下。
    if (!empty($f['overwrite']) && !$ids && !isset($f['pi_exact'])) {
        return ['ok' => false, 'applied' => 0, 'msg' => '改綁定（覆蓋已設定）只能逐筆勾選，或指定單一組製程文字整組套用，不開放用關鍵字模糊整批覆蓋。'];
    }
    // 廠內治具只能套在客戶＝本公司的訂單上（與單筆存檔同一條規則，鐵律8）
    if ($isOwnOnly) {
        $where .= " AND ot.Client_name_ID = :bf_own2";
        $params[':bf_own2'] = ot_astag_own_company_id($db);
    }

    // 覆蓋模式（改已經綁定好的）：寫之前先把「舉況會被改掉的舊標籤」抓下來，
    // 否則 UPDATE 完就再也追不回來原本是什麼——這是 AS 認定的資料，改過什麼必須留得下來。
    $overwrite = !empty($f['overwrite']);
    $oldRows = [];
    if ($overwrite) {
        try {
            $q = $db->prepare("SELECT Order_id, as_tag_id, as_tag_scope FROM order_track ot WHERE $where AND ot.as_tag_id IS NOT NULL");
            $q->execute($params);
            $oldRows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $oldRows = []; }
    }
    try {
        // 不是覆蓋模式時再加一道保險：只填空白。
        // （where 裡本來就有 as_tag_id IS NULL，這裡是第二道——覆蓋是不可逆的動作，值得寫兩次）
        $guard = $overwrite ? '' : ' AND ot.as_tag_id IS NULL';
        $sql = "UPDATE order_track ot
                   SET ot.as_tag_id = :bf_tag, ot.as_tag_scope = :bf_scope,
                       ot.as_tag_at = NOW(), ot.as_tag_by = :bf_uid, ot.as_tag_src = :bf_src
                 WHERE $where$guard";
        $params[':bf_tag']   = $tagId;
        $params[':bf_scope'] = $scope;
        $params[':bf_uid']   = ($uid > 0 ? $uid : null);
        $params[':bf_src']   = $overwrite ? 'rebind' : 'batch';
        $st = $db->prepare($sql);
        $st->execute($params);
        $n = $st->rowCount();
    } catch (Throwable $e) { return ['ok' => false, 'applied' => 0, 'msg' => '寫入失敗：' . $e->getMessage()]; }

    // 覆蓋掉的舊綁定逐筆留歷程（分批多列 INSERT，不要一筆一次打 DB）
    if ($overwrite && $oldRows) {
        if ($uname === '') $uname = ot_astag_uname($db, $uid);
        $map = ot_astag_label_map($db);
        $newLbl = (string)($map[$tagId . ':' . $scope] ?? '');
        foreach (array_chunk($oldRows, 300) as $chunk) {
            $vals = []; $args = [];
            foreach ($chunk as $o) {
                $oid = (int)$o['Order_id'];
                $oi  = (int)($o['as_tag_id'] ?? 0);
                $os  = (string)($o['as_tag_scope'] ?? '');
                if ($oi === $tagId && $os === $scope) continue;   // 沒改到就不必記
                $vals[] = '(?,?,?,?,?,?,?,?,?,?,?,NOW())';
                array_push($args, $oid, $oi, $os, (string)($map[$oi . ':' . $os] ?? ''),
                           $tagId, $scope, $newLbl, 'rebind',
                           '批次改綁定', ($uid > 0 ? $uid : null), $uname);
            }
            if (!$vals) continue;
            try {
                $db->prepare("INSERT INTO ot_as_tag_order_log
                    (order_id, old_tag_id, old_scope, old_label, new_tag_id, new_scope, new_label, source, note, created_by, created_by_name, created_at)
                    VALUES " . implode(',', $vals))->execute($args);
            } catch (Throwable $e) { /* 歷程寫不進去不擋主要作業 */ }
        }
    }

    // 批次的第一次設定留一筆彙總歷程（逐筆的「誰、何時」已經在 order_track.as_tag_at/by/src 上）
    if ($n > 0) {
        if ($uname === '') $uname = ot_astag_uname($db, $uid);
        try {
            $db->prepare("INSERT INTO ot_as_proc_tag_log (batch_id, action, tag_id, tag_label, detail, created_by, created_by_name, created_at)
                          VALUES (?,'backfill',?,?,?,?,?,NOW())")
               ->execute([bin2hex(random_bytes(8)), $tagId, $label,
                          json_encode(['applied' => $n, 'scope' => $scope, 'filter' => $f,
                                       'order_ids' => array_slice($ids, 0, 200)], JSON_UNESCAPED_UNICODE),
                          ($uid > 0 ? $uid : null), $uname]);
        } catch (Throwable $e) {}
    }
    $msg = ($overwrite ? '已改綁定 ' : '已補設定 ') . $n . ' 張訂單為「' . $label . '」';
    if ($isOwnOnly) $msg .= '（只套用在客戶是本公司的訂單）';
    return ['ok' => true, 'applied' => $n, 'msg' => $msg];
}
}
