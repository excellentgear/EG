<?php
/**
 * data_console_lib.php — 資料急救台共用函式庫（模組 data_console）
 *
 * 職責：安全的 schema 內省、關聯地圖（自動偵測＋DB覆寫）、唯讀欄位判斷、
 *       刪除影響分析、稽核寫入、CSRF。所有識別字（表名/欄名）一律先對照
 *       information_schema 白名單再拼進 SQL，杜絕 injection。
 *
 * 鐵律：DB 寫入走 transaction（呼叫端負責）；本檔只提供工具與稽核落痕。
 */

if (!function_exists('dc_db_name')) {

/** 目前資料庫名稱 */
function dc_db_name(PDO $pdo): string {
    static $n = null;
    if ($n === null) $n = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    return $n;
}

/** 永久唯讀表：紀錄/稽核類，改了會破壞可追溯性，連設定都不給開放編輯 */
function dc_hard_readonly_tables(): array {
    return ['audit_log', 'login_log', 'data_console_table_cfg', 'data_console_refmap'];
}

/** 唯讀欄位名（正規化小寫）：主鍵與建立/修改稽核欄一律不可改 */
function dc_readonly_col_names(): array {
    return [
        'created_at','updated_at','modified_at','created_date','modified_date',
        'created_by','create_by','modified_by','updated_by','created_by_id','updated_by_id',
    ];
}

/** 全部 BASE TABLE 清單（不含 view） */
function dc_all_tables(PDO $pdo): array {
    $st = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema=? AND table_type='BASE TABLE' ORDER BY table_name");
    $st->execute([dc_db_name($pdo)]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** 表是否存在（BASE TABLE）— 白名單驗證用 */
function dc_table_exists(PDO $pdo, string $t): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema=? AND table_name=? AND table_type='BASE TABLE'");
    $st->execute([dc_db_name($pdo), $t]);
    return (bool)$st->fetchColumn();
}

/** 欄位清單（含型別/主鍵/可空/預設/extra） */
function dc_columns(PDO $pdo, string $t): array {
    $st = $pdo->prepare(
        "SELECT column_name, data_type, column_type, is_nullable, column_default, column_key, extra, column_comment
         FROM information_schema.columns
         WHERE table_schema=? AND table_name=? ORDER BY ordinal_position");
    $st->execute([dc_db_name($pdo), $t]);
    // information_schema 在本機 MySQL 回傳大寫欄名，統一轉小寫供全檔存取
    return array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $st->fetchAll(PDO::FETCH_ASSOC));
}

/** 某欄是否存在於某表（白名單驗證） */
function dc_column_exists(PDO $pdo, string $t, string $col): bool {
    foreach (dc_columns($pdo, $t) as $c) if ($c['column_name'] === $col) return true;
    return false;
}

/** 主鍵欄位（陣列；多數是單欄，也可能複合） */
function dc_pk(PDO $pdo, string $t): array {
    $pk = [];
    foreach (dc_columns($pdo, $t) as $c) if ($c['column_key'] === 'PRI') $pk[] = $c['column_name'];
    return $pk;
}

/** 反引號安全引用識別字（僅在已白名單驗證後使用） */
function dc_q(string $ident): string { return '`' . str_replace('`', '``', $ident) . '`'; }

/** 欄位是否唯讀（主鍵、auto_increment、稽核欄一律唯讀） */
function dc_col_readonly(array $col): bool {
    if ($col['column_key'] === 'PRI') return true;
    if (stripos((string)$col['extra'], 'auto_increment') !== false) return true;
    if (in_array(strtolower($col['column_name']), dc_readonly_col_names(), true)) return true;
    return false;
}

// ── 關聯地圖 ───────────────────────────────────────────────────────────────

/**
 * 種子覆寫（欄名 → 參照表）。自動偵測不準或無法由命名推得時的手工對應。
 * display 省略時由 dc_pick_display() 自動挑名稱欄。
 */
function dc_seed_refmap(): array {
    return [
        'user_id'      => ['table' => 'user',        'display' => ['user_cname']],
        'maker_id'     => ['table' => 'user',        'display' => ['user_cname']],
        'd_id'         => ['table' => 'd_setting',   'display' => ['Drawing_No', 'Spec_No']],
        'Order_id'     => ['table' => 'order_track',  'display' => ['Order_oo', 'Client_name']],
        'order_id'     => ['table' => 'order_track',  'display' => ['Order_oo', 'Client_name']],
        'role_id'      => ['table' => 'roles',        'display' => ['role_name']],
        'department_id'=> ['table' => 'department',   'display' => []],
        'dept_id'      => ['table' => 'department',   'display' => []],
        'live_event_id'=> ['table' => 'live_event',   'display' => []],
        'bom_ing_id'   => ['table' => 'bom_ing',      'display' => []],
        'bom_ing_fid'  => ['table' => 'bom_ing',      'display' => []],
    ];
}

/** 讀取 DB 覆寫表（管理員在頁內設定的，優先於種子） */
function dc_db_refmap(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $rows = $pdo->query("SELECT src_table, src_column, ref_table, ref_pk, display_cols FROM data_console_refmap")
                    ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $key = ($r['src_table'] !== null && $r['src_table'] !== '' ? $r['src_table'] . '.' : '') . $r['src_column'];
            $cache[$key] = [
                'table'   => $r['ref_table'],
                'pk'      => $r['ref_pk'] ?: null,
                'display' => $r['display_cols'] ? array_map('trim', explode(',', $r['display_cols'])) : [],
            ];
        }
    } catch (Throwable $e) {}
    return $cache;
}

/** 自動挑一個表的「名稱欄」（給關聯顯示用） */
function dc_pick_display(PDO $pdo, string $refTable): array {
    if (!dc_table_exists($pdo, $refTable)) return [];
    $cols = array_column(dc_columns($pdo, $refTable), 'column_name');
    // 偏好含這些關鍵字的欄位（依序）
    $prefer = ['cname','name','title','no','oo','drawing','spec','subject','label','code'];
    foreach ($prefer as $kw) {
        foreach ($cols as $c) {
            $lc = strtolower($c);
            if (strpos($lc, $kw) !== false && !in_array($lc, dc_readonly_col_names(), true) && $lc !== 'id')
                return [$c];
        }
    }
    // 退而求其次：第一個非主鍵、非稽核的欄
    $pk = dc_pk($pdo, $refTable);
    foreach ($cols as $c)
        if (!in_array($c, $pk, true) && !in_array(strtolower($c), dc_readonly_col_names(), true)) return [$c];
    return $pk;
}

/**
 * 解析某欄位參照到哪張表。回傳 null 或
 * ['table'=>目標表,'pk'=>目標主鍵,'display'=>[顯示欄...]]
 * 優先序：DB覆寫 > 種子 > 命名慣例（<name>_id → 表 <name> 或 <name>s）。
 */
function dc_resolve_ref(PDO $pdo, string $table, string $column): ?array {
    $db = dc_db_refmap($pdo);
    $seed = dc_seed_refmap();
    $hit = null;
    if (isset($db["$table.$column"]))   $hit = $db["$table.$column"];
    elseif (isset($db[$column]))         $hit = $db[$column];
    elseif (isset($seed[$column]))       $hit = $seed[$column];
    else {
        // 命名慣例：foo_id → foo / foos
        if (preg_match('/^(.+)_id$/i', $column, $m)) {
            $base = strtolower($m[1]);
            foreach ([$base, $base . 's'] as $cand) {
                if ($cand === '' || $cand === strtolower($table)) continue;
                if (dc_table_exists($pdo, $cand)) { $hit = ['table' => $cand, 'display' => []]; break; }
            }
        }
    }
    if (!$hit || empty($hit['table']) || !dc_table_exists($pdo, $hit['table'])) return null;
    $refPk = $hit['pk'] ?? null;
    if (!$refPk) { $pks = dc_pk($pdo, $hit['table']); $refPk = $pks[0] ?? null; }
    if (!$refPk) return null;
    $display = $hit['display'] ?? [];
    // 過濾不存在的顯示欄；空則自動挑
    $display = array_values(array_filter($display, fn($c) => dc_column_exists($pdo, $hit['table'], $c)));
    if (!$display) $display = dc_pick_display($pdo, $hit['table']);
    return ['table' => $hit['table'], 'pk' => $refPk, 'display' => $display];
}

/** 一次解析整張表所有欄位的參照（給前端顯示/下拉用） */
function dc_table_refs(PDO $pdo, string $table): array {
    $out = [];
    foreach (dc_columns($pdo, $table) as $c) {
        $r = dc_resolve_ref($pdo, $table, $c['column_name']);
        if ($r) $out[$c['column_name']] = $r;
    }
    return $out;
}

/**
 * 找出「哪些表的哪些欄位會參照到目標表」（刪除影響分析用）。
 * 訊號：目標表主鍵若非泛用 id（如 Order_id、d_id），同名欄即視為外參；
 *       另加種子/DB覆寫中指向本表者，以及命名慣例。全部再回頭驗證確認。
 * 回傳 [['table'=>t,'column'=>c], ...]
 */
function dc_referencing_columns(PDO $pdo, string $target): array {
    $names = [];
    $pk = dc_pk($pdo, $target);
    // (1) 非泛用主鍵：同名欄
    foreach ($pk as $p) if (strtolower($p) !== 'id') $names[strtolower($p)] = $p;
    // (2) 命名慣例：<target 去複數>_id
    $base = rtrim(strtolower($target), 's');
    $names[$base . '_id'] = null;
    $names[strtolower($target) . '_id'] = null;
    // (3) 種子/DB覆寫中指向本表的欄名
    foreach (dc_seed_refmap() as $col => $def) if (strtolower($def['table']) === strtolower($target)) $names[strtolower($col)] = $col;
    foreach (dc_db_refmap($pdo) as $key => $def) {
        if (strtolower($def['table']) === strtolower($target)) {
            $col = strpos($key, '.') !== false ? substr($key, strpos($key, '.') + 1) : $key;
            $names[strtolower($col)] = $col;
        }
    }
    if (!$names) return [];
    // 找出實際擁有這些欄名的表
    $ph = implode(',', array_fill(0, count($names), '?'));
    $params = array_merge([dc_db_name($pdo)], array_keys($names));
    $st = $pdo->prepare(
        "SELECT table_name AS table_name, column_name AS column_name FROM information_schema.columns
         WHERE table_schema=? AND LOWER(column_name) IN ($ph)");
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        if ($r['table_name'] === $target) continue;              // 不含自己
        if (!dc_table_exists($pdo, $r['table_name'])) continue;   // 排除 view
        // 回頭驗證：這欄確實解析回目標表才算數
        $rr = dc_resolve_ref($pdo, $r['table_name'], $r['column_name']);
        if ($rr && strtolower($rr['table']) === strtolower($target)) {
            $out[] = ['table' => $r['table_name'], 'column' => $r['column_name']];
        }
    }
    return $out;
}

// ── 稽核 ───────────────────────────────────────────────────────────────────

/**
 * 寫入 audit_log。$changes 例：
 *   ['reason'=>'補檢驗旗標','fields'=>['qc_check'=>['old'=>0,'new'=>1]]]
 */
function dc_audit(PDO $pdo, string $action, string $table, string $pkVal, ?string $name, array $changes, int $uid, string $operator): void {
    try {
        $st = $pdo->prepare(
            "INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $st->execute([
            $action, 'DC:' . $table, $pkVal, $name,
            json_encode($changes, JSON_UNESCAPED_UNICODE), $uid, $operator,
        ]);
    } catch (Throwable $e) { /* 稽核失敗不阻斷主流程，但已在 transaction 內 */ }
}

// ── CSRF ───────────────────────────────────────────────────────────────────

function dc_csrf_token(): string {
    if (empty($_SESSION['dc_csrf'])) $_SESSION['dc_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['dc_csrf'];
}
function dc_csrf_ok(?string $t): bool {
    return !empty($_SESSION['dc_csrf']) && is_string($t) && hash_equals($_SESSION['dc_csrf'], $t);
}

} // function_exists guard
