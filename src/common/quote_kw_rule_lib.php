<?php
// quote_kw_rule_lib.php — 報價單快速轉移頁「依規格關鍵字自動建議製程標籤」的唯一實作
//
// 為什麼要有這個：ERP 補建的歷史報價單有 11,000 多筆項目沒有製程，規格文字有 7,900 多種
// （長尾很嚴重，靠「相同規格歸併」幾乎省不到工），但其中六成的規格文字裡本來就寫著製程字
// （齒研／滾齒／全製／冶具／插齒／滾刀／線割／TIN…）。所以改成可設定的關鍵字規則批次建議，
// 再由人工分組確認。**系統只建議、絕不自動套用。**
//
// 三件事寫在這裡，禁止各頁自刻：
//   1) 規則的資料結構與比對規則（qkw_rule_hit）
//   2) 掃描 + 依「建議標籤組合」分組（qkw_scan）
//   3) 套用（qkw_apply）——含「帶入備註」：把規格文字帶進整張報價單的備註欄
//
// 關鍵字語法：include_kw 逗號分隔＝**全部都要含**；單一關鍵字內用「|」＝任一即可（例 冶具|治具）。
//             exclude_kw 逗號分隔＝**任一命中就排除**。比對只看 quotation_item.specification
//             （使用者拍板：料號含大量規格碼，一起比會誤判），不分大小寫。

if (!function_exists('qkw_ensure_schema')) {

// 報價單備註裡由系統管理的區塊標題：這一行以下到結尾都是「帶入備註」自動產生的，
// 每次同步時整段重建，所以使用者自己寫在上面的備註不會被動到。
define('QKW_NOTE_HEAD', '【規格備註】');

function qkw_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_kw_rule (
            rule_id      INT AUTO_INCREMENT PRIMARY KEY,
            rule_name    VARCHAR(60)  NOT NULL COMMENT '規則名稱（確認畫面會顯示是哪一條規則命中）',
            include_kw   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '規格必須包含的關鍵字，逗號分隔＝全部都要含；單一關鍵字內用直線分隔＝任一即可',
            exclude_kw   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '規格不可包含的關鍵字，逗號分隔＝任一命中就排除',
            customer_ids VARCHAR(500) NOT NULL DEFAULT '' COMMENT '只適用這些客戶編號；空白＝通用規則',
            sub_tag_ids  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '命中時建議帶入的製程子標籤 id 清單',
            to_note      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=建議「帶入備註」而不是製程標籤',
            priority     INT          NOT NULL DEFAULT 0,
            is_active    TINYINT(1)   NOT NULL DEFAULT 1,
            created_by   VARCHAR(30)  NULL,
            created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by   VARCHAR(30)  NULL,
            updated_at   TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_active (is_active, priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='報價單快速轉移頁：依規格關鍵字自動建議製程標籤的規則'");
    } catch (PDOException $e) { /* 已存在 */ }
    // include_mode：「包含」的多個關鍵字要 全部都要含(all) 還是 任一即可(any)
    try { $pdo->query("SELECT include_mode FROM quotation_kw_rule LIMIT 1"); }
    catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE quotation_kw_rule ADD COLUMN include_mode ENUM('all','any') NOT NULL DEFAULT 'all' COMMENT '包含關鍵字的比對方式：all=全部都要含、any=任一即可' AFTER include_kw"); }
        catch (PDOException $e2) {}
    }
    try { $pdo->query("SELECT note_only FROM quotation_item LIMIT 1"); }
    catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE quotation_item ADD COLUMN note_only TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=這一筆以備註代替製程（規格文字已帶進整張報價單的備註欄）' AFTER process_notes"); }
        catch (PDOException $e2) {}
    }
}

// ── 規則 CRUD ───────────────────────────────────────────────
function qkw_rule_list(PDO $pdo, bool $activeOnly = false): array
{
    qkw_ensure_schema($pdo);
    $sql = "SELECT * FROM quotation_kw_rule" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY priority DESC, rule_id ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// 前後端同一套驗證（鐵律8）：名稱與「包含」關鍵字必填，且一定要指定帶什麼（標籤或備註）
function qkw_rule_validate(array $in): array
{
    $err  = [];
    $name = trim((string)($in['rule_name'] ?? ''));
    if ($name === '') $err[] = '請填規則名稱';
    if (mb_strlen($name) > 60) $err[] = '規則名稱最多 60 字';
    if (qkw_split_kw((string)($in['include_kw'] ?? '')) === []) $err[] = '請至少填一個「包含」關鍵字';
    $tags = qkw_split_ids((string)($in['sub_tag_ids'] ?? ''));
    if (empty($in['to_note']) && !$tags) $err[] = '請選擇要帶入的製程標籤，或改勾「帶入備註」';
    if (!empty($in['to_note']) && $tags) $err[] = '「帶入備註」與製程標籤只能擇一';
    return $err;
}

function qkw_rule_save(PDO $pdo, array $in, string $userId): int
{
    qkw_ensure_schema($pdo);
    $err = qkw_rule_validate($in);
    if ($err) throw new Exception(implode('；', $err));

    // 標籤一律以資料庫現況驗一次（停用/不存在的直接濾掉），不採信前端送的清單
    $tags = qkw_split_ids((string)($in['sub_tag_ids'] ?? ''));
    if ($tags) {
        $ph = implode(',', array_fill(0, count($tags), '?'));
        $st = $pdo->prepare("SELECT sub_tag_id FROM quotation_process_sub_tag WHERE sub_tag_id IN ($ph) AND is_active=1");
        $st->execute($tags);
        $tags = array_values(array_intersect($tags, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
        if (!$tags) throw new Exception('選到的製程標籤不存在或已停用');
    }
    $custs = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($in['customer_ids'] ?? ''))))));

    $row = [
        trim((string)$in['rule_name']),
        qkw_norm_kw((string)($in['include_kw'] ?? '')),
        (($in['include_mode'] ?? 'all') === 'any' ? 'any' : 'all'),
        qkw_norm_kw((string)($in['exclude_kw'] ?? '')),
        implode(',', $custs),
        implode(',', $tags),
        !empty($in['to_note']) ? 1 : 0,
        (int)($in['priority'] ?? 0),
        isset($in['is_active']) ? (int)!empty($in['is_active']) : 1,
    ];
    $ruleId = (int)($in['rule_id'] ?? 0);
    if ($ruleId > 0) {
        $st = $pdo->prepare("UPDATE quotation_kw_rule SET rule_name=?, include_kw=?, include_mode=?, exclude_kw=?, customer_ids=?, sub_tag_ids=?, to_note=?, priority=?, is_active=?, updated_by=? WHERE rule_id=?");
        $st->execute(array_merge($row, [$userId, $ruleId]));
        return $ruleId;
    }
    $st = $pdo->prepare("INSERT INTO quotation_kw_rule (rule_name, include_kw, include_mode, exclude_kw, customer_ids, sub_tag_ids, to_note, priority, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $st->execute(array_merge($row, [$userId]));
    return (int)$pdo->lastInsertId();
}

function qkw_rule_delete(PDO $pdo, int $ruleId): void
{
    qkw_ensure_schema($pdo);
    $pdo->prepare("DELETE FROM quotation_kw_rule WHERE rule_id=?")->execute([$ruleId]);
}

// ── 關鍵字比對 ─────────────────────────────────────────────
function qkw_split_kw(string $s): array
{
    // preg_split 帶 /u 遇到非 UTF-8 的輸入會回 false（不是空陣列），直接丟給 array_map 會是 TypeError 500。
    // 正常前端不會送出這種內容，但擋一下比較保險。
    $parts = preg_split('/[,，]/u', $s);
    if ($parts === false) $parts = explode(',', $s);
    return array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
}
function qkw_norm_kw(string $s): string { return implode(',', qkw_split_kw($s)); }
function qkw_split_ids(string $s): array
{
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $s)), fn($v) => $v > 0)));
}

// 單一關鍵字：內含「|」代表任一即可（例 冶具|治具）；一律不分大小寫
// 回傳「實際命中的那個字」，沒中回 null——確認畫面要講得出「這條為什麼會中」，
// 而且多條同時命中時要靠它比較誰精確（短的字被長的字包住＝那條該讓位）。
function qkw_hit_one_kw(string $hay, string $kw): ?string
{
    foreach (explode('|', $kw) as $alt) {
        $alt = trim($alt);
        if ($alt !== '' && mb_stripos($hay, $alt) !== false) return $alt;
    }
    return null;
}
function qkw_hit_one(string $hay, string $kw): bool { return qkw_hit_one_kw($hay, $kw) !== null; }

// 命中時回傳「這條規則實際命中的關鍵字清單」（all 模式會有多個），沒命中回 null。
function qkw_rule_hit_kw(array $rule, string $spec, string $clientId): ?array
{
    if (!empty($rule['customer_ids'])) {
        $list = array_map('trim', explode(',', (string)$rule['customer_ids']));
        if (!in_array($clientId, $list, true)) return null;    // 有指定客戶＝只在這些客戶身上成立
    }
    $inc = qkw_split_kw((string)$rule['include_kw']);
    if (!$inc) return null;
    // 包含：預設「全部都要中」(all)；設成 any 則任一個中就算命中
    // （這是最容易誤會的地方——使用者常以為逗號＝「或」，所以規則表單上是兩顆並排的選項）
    $hitKw = [];
    if (($rule['include_mode'] ?? 'all') === 'any') {
        foreach ($inc as $kw) { $m = qkw_hit_one_kw($spec, $kw); if ($m !== null) { $hitKw[] = $m; break; } }
        if (!$hitKw) return null;
    } else {
        foreach ($inc as $kw) { $m = qkw_hit_one_kw($spec, $kw); if ($m === null) return null; $hitKw[] = $m; }
    }
    foreach (qkw_split_kw((string)$rule['exclude_kw']) as $kw) if (qkw_hit_one($spec, $kw)) return null;  // 排除＝任一中就出局
    return array_values(array_unique($hitKw));
}

function qkw_rule_hit(array $rule, string $spec, string $clientId): bool
{
    return qkw_rule_hit_kw($rule, $spec, $clientId) !== null;
}

// 誰在讓位：某條規則命中的字「每一個」都被另一條規則命中的字包住（而且比它長）＝這條比較不精確。
// 例：規格「DP10T78PA14.5滾刀」——「粗滾」命中的是「滾」、「刀具-滾齒刀」命中的是「滾刀」，
// 「滾」被「滾刀」包住，所以系統建議只用滾齒刀。**只是建議，最後仍由使用者在確認畫面選。**
function qkw_shadowed(array $hits, int $i): bool
{
    if (!$hits[$i]['kws']) return false;
    foreach ($hits[$i]['kws'] as $kw) {
        $covered = false;
        foreach ($hits as $j => $h) {
            if ($j === $i) continue;
            foreach ($h['kws'] as $kw2) {
                if (mb_strlen($kw2) > mb_strlen($kw) && mb_stripos($kw2, $kw) !== false) { $covered = true; break 2; }
            }
        }
        if (!$covered) return false;   // 只要有一個字沒被包住，這條就不算讓位
    }
    return true;
}

// 規則試算：把「還沒存檔的規則」直接拿去比對目前尚待確認的項目，回命中筆數與幾個範例。
// 為什麼要有：逗號的語意（全部都要含／任一即可）最容易誤會，存完才發現一筆都沒中很浪費時間。
function qkw_rule_preview(PDO $pdo, array $rule, array $quoteIds = []): array
{
    qkw_ensure_schema($pdo);
    $sql = "SELECT qi.specification, qi.note_only, ql.client_id,
                   EXISTS(SELECT 1 FROM quotation_item_process_map m WHERE m.quotation_item_id=qi.item_id) AS has_map,
                   qi.process_notes
            FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
            WHERE ql.pending_review = 1";
    $args = [];
    if ($quoteIds) {
        $quoteIds = array_values(array_unique(array_filter(array_map('intval', $quoteIds))));
        if ($quoteIds) {
            $sql .= " AND qi.quote_id IN (" . implode(',', array_fill(0, count($quoteIds), '?')) . ")";
            $args = $quoteIds;
        }
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $all = 0; $unset = 0; $samples = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $spec = (string)($r['specification'] ?? '');
        if (trim($spec) === '') continue;
        if (!qkw_rule_hit($rule, $spec, (string)($r['client_id'] ?? ''))) continue;
        $all++;
        $isUnset = !((int)$r['has_map']) && trim((string)$r['process_notes']) === '' && !((int)$r['note_only']);
        if ($isUnset) $unset++;
        if (count($samples) < 5) $samples[] = $spec;
    }
    return ['matched' => $all, 'unset' => $unset, 'samples' => $samples];
}

// ── 多條規則同時命中時的候選方案 ─────────────────────────
// 使用者拍板：**不自作主張取聯集，也不自作主張只留一條，一律把候選列出來讓人選**。
// 系統只做兩件事：①講清楚每條規則是命中哪個字才成立的 ②標一個建議值（讓位規則見 qkw_shadowed）。
// 回傳 options＝候選清單（第一個是建議值）、rec＝建議值在清單裡的索引、conflict＝要不要讓人選。
function qkw_build_options(array $hits, array $tagName): array
{
    // 沒指定標籤也沒勾「帶入備註」的規則不可能有結果，直接不列（否則畫面上會出現一個選了沒作用的選項）
    $hits = array_values(array_filter($hits, fn($h) => qkw_split_ids((string)$h['rule']['sub_tag_ids']) || !empty($h['rule']['to_note'])));
    if (!$hits) return ['options' => [], 'rec' => 0, 'conflict' => false, 'sig' => '', 'hits' => []];

    $lbl = function (array $ids, bool $note) use ($tagName): string {
        if ($note) return '帶入備註（規格文字帶進整張報價單的備註欄）';
        return implode(' ＋ ', array_map(fn($i) => $tagName[$i] ?? ('#' . $i), $ids));
    };
    // 有標籤就以標籤為準，只有「帶入備註」規則命中才走備註（與既有口徑一致）
    $merge = function (array $idxs) use ($hits, $lbl): array {
        $tags = []; $note = false;
        foreach ($idxs as $i) {
            if (!empty($hits[$i]['rule']['to_note'])) { $note = true; continue; }
            foreach (qkw_split_ids((string)$hits[$i]['rule']['sub_tag_ids']) as $sid) $tags[$sid] = true;
        }
        $ids = array_keys($tags); sort($ids);
        $kind = $ids ? 'tags' : ($note ? 'note' : '');
        return ['kind' => $kind, 'sub_tag_ids' => implode(',', $ids), 'label' => $lbl($ids, $kind === 'note')];
    };

    $meta = [];
    foreach ($hits as $i => $h) {
        $meta[] = ['name' => $h['rule']['rule_name'], 'rule_id' => (int)$h['rule']['rule_id'],
                   'kw' => implode('＋', $h['kws']), 'shadowed' => qkw_shadowed($hits, $i) ? 1 : 0];
    }
    $keep = []; foreach ($meta as $i => $m) if (!$m['shadowed']) $keep[] = $i;
    if (!$keep) $keep = array_keys($hits);           // 互相包住（理論上不會發生）就全留，不要留白

    $best = $merge($keep);
    $all  = $merge(array_keys($hits));

    // 每條規則各自的結果都一樣（例：代料成品／料到成品都帶「全製」）＝其實沒有分歧，不必問使用者
    $distinct = [];
    foreach (array_keys($hits) as $i) { $o = $merge([$i]); $distinct[$o['kind'] . ':' . $o['sub_tag_ids']] = true; }
    $conflict = count($hits) > 1 && count($distinct) > 1;

    if (!$conflict) return ['options' => [$best + ['key' => 'best', 'desc' => '', 'recommend' => 1]],
                            'rec' => 0, 'conflict' => false, 'sig' => '', 'hits' => $meta];

    $shadowNames = [];
    foreach ($meta as $m) if ($m['shadowed']) $shadowNames[] = $m['name'] . '（命中：' . $m['kw'] . '）';
    $keepNames = []; foreach ($keep as $i) $keepNames[] = $meta[$i]['name'];

    $options = [];
    $seen = [];
    $push = function (array $o, string $key, string $desc, int $rec) use (&$options, &$seen) {
        if ($o['kind'] === '') return;
        $sig = $o['kind'] . ':' . $o['sub_tag_ids'];
        if (isset($seen[$sig])) return;               // 結果一樣的候選只留一個，不要給兩顆看起來一樣的按鈕
        $seen[$sig] = true;
        $options[] = $o + ['key' => $key, 'desc' => $desc, 'recommend' => $rec];
    };
    $push($best, 'best',
        count($keep) < count($hits)
            ? ('只用 ' . implode('、', $keepNames) . '（' . implode('、', $shadowNames) . ' 的關鍵字被包在裡面，建議讓位）')
            : ('全部套用（' . implode('、', $keepNames) . '）'),
        1);
    foreach ($meta as $i => $m) $push($merge([$i]), 'r' . $m['rule_id'], '只用「' . $m['name'] . '」（命中：' . $m['kw'] . '）', 0);
    $push($all, 'all', '全部規則都要（' . implode('、', array_column($meta, 'name')) . '）', 0);

    $rids = array_column($meta, 'rule_id'); sort($rids);
    return ['options' => $options, 'rec' => 0, 'conflict' => true,
            'sig' => implode(',', $rids) . '#' . $best['kind'] . ':' . $best['sub_tag_ids'], 'hits' => $meta];
}

// ── 掃描：回傳「依建議標籤組合分組」的結果 ───────────────
// $quoteIds＝要掃描的報價單範圍（畫面上目前篩選出來的那批）；空＝全部尚待確認的報價單
function qkw_scan(PDO $pdo, array $quoteIds = [], bool $onlyUnset = true): array
{
    qkw_ensure_schema($pdo);
    $rules = qkw_rule_list($pdo, true);
    if (!$rules) return ['groups' => [], 'scanned' => 0, 'matched' => 0, 'rules' => 0];

    $sql = "SELECT qi.item_id, qi.quote_id, qi.product_id, qi.d_setting_d_id, qi.specification, qi.note_only,
                   ql.quote_no, ql.client_id, ql.client_name,
                   EXISTS(SELECT 1 FROM quotation_item_process_map m WHERE m.quotation_item_id=qi.item_id) AS has_map
            FROM quotation_item qi
            JOIN quotation_list ql ON ql.quote_id = qi.quote_id
            WHERE ql.pending_review = 1";
    $args = [];
    if ($quoteIds) {
        $quoteIds = array_values(array_unique(array_filter(array_map('intval', $quoteIds))));
        if (!$quoteIds) return ['groups' => [], 'scanned' => 0, 'matched' => 0, 'rules' => count($rules)];
        $sql .= " AND qi.quote_id IN (" . implode(',', array_fill(0, count($quoteIds), '?')) . ")";
        $args = $quoteIds;
    }
    if ($onlyUnset) {
        // 「還沒設定製程」＝沒有 process_no、沒有點過標籤、也不是以備註代替製程
        $sql .= " AND NOT EXISTS(SELECT 1 FROM quotation_item_process_map m WHERE m.quotation_item_id=qi.item_id)
                  AND (qi.process_notes IS NULL OR qi.process_notes='') AND qi.note_only=0";
    }
    $sql .= " ORDER BY ql.quote_date DESC, qi.quote_id DESC, qi.sort_order, qi.item_id";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $tagName = qkw_sub_tag_names($pdo);
    $groups  = [];
    $matched = 0;
    foreach ($rows as $r) {
        $spec = (string)($r['specification'] ?? '');
        if (trim($spec) === '') continue;                       // 規格空白無從判斷，留給人工
        $hits = [];
        foreach ($rules as $ru) {
            $kws = qkw_rule_hit_kw($ru, $spec, (string)($r['client_id'] ?? ''));
            if ($kws === null) continue;
            $hits[] = ['rule' => $ru, 'kws' => $kws];
        }
        if (!$hits) continue;
        $opts = qkw_build_options($hits, $tagName);
        if (!$opts['options']) continue;                        // 命中的規則全都沒指定標籤也沒勾備註
        $matched++;

        $rec = $opts['options'][$opts['rec']];
        $key = $opts['conflict'] ? ('C:' . $opts['sig']) : ($rec['kind'] === 'note' ? 'N' : ('T:' . $rec['sub_tag_ids']));
        if (!isset($groups[$key])) {
            $groups[$key] = ['key' => $key, 'kind' => $rec['kind'], 'label' => $rec['label'],
                             'sub_tag_ids' => $rec['sub_tag_ids'], 'rules' => [], 'items' => [],
                             'conflict' => $opts['conflict'] ? 1 : 0,
                             'options' => $opts['conflict'] ? $opts['options'] : [],
                             'rec' => $opts['rec'], 'hits' => $opts['hits']];
        }
        foreach ($opts['hits'] as $h) $groups[$key]['rules'][$h['name']] = true;
        $groups[$key]['items'][] = [
            'item_id' => (int)$r['item_id'], 'quote_id' => (int)$r['quote_id'], 'quote_no' => $r['quote_no'],
            'client_name' => $r['client_name'], 'product_id' => $r['product_id'],
            'd_setting_d_id' => (int)($r['d_setting_d_id'] ?? 0),   // 確認畫面點料號開圖面時用來精確鎖定主檔
            'spec' => $spec,
            'had' => ((int)$r['has_map'] || (int)$r['note_only']) ? 1 : 0,
        ];
    }
    $out = [];
    foreach ($groups as $g) {
        $g['rules'] = array_keys($g['rules']);
        $g['count'] = count($g['items']);
        $out[] = $g;
    }
    usort($out, fn($a, $b) => $b['count'] <=> $a['count']);
    return ['groups' => $out, 'scanned' => count($rows), 'matched' => $matched, 'rules' => count($rules)];
}

function qkw_sub_tag_names(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['sub_tag_id']] = $r['sub_tag_name'];
    }
    return $map;
}

// ── 快速套用（不存規則的臨時條件）───────────────────────────
// 使用者要的是「臨時想到一組關鍵字，掃出來、框選幾筆、加上或拿掉某些標籤」，
// 不必為了做一次而在規則表裡留一條垃圾規則。比對規則與正式規則完全相同（同一支 qkw_rule_hit）。
// $filter：all＝全部命中／unset＝只要還沒設定製程的／set＝只要已經設定過的
function qkw_quick_scan(PDO $pdo, array $rule, array $quoteIds = [], string $filter = 'all', int $limit = 5000): array
{
    qkw_ensure_schema($pdo);
    $sql = "SELECT qi.item_id, qi.quote_id, qi.product_id, qi.d_setting_d_id, qi.specification, qi.note_only, qi.process_notes,
                   ql.quote_no, ql.client_id, ql.client_name, ql.quote_date,
                   EXISTS(SELECT 1 FROM quotation_item_process_map m WHERE m.quotation_item_id=qi.item_id) AS has_map
            FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
            WHERE ql.pending_review = 1";
    $args = [];
    if ($quoteIds) {
        $quoteIds = array_values(array_unique(array_filter(array_map('intval', $quoteIds))));
        if (!$quoteIds) return ['items' => [], 'total' => 0, 'truncated' => 0];
        $sql .= " AND qi.quote_id IN (" . implode(',', array_fill(0, count($quoteIds), '?')) . ")";
        $args = $quoteIds;
    }
    $sql .= " ORDER BY ql.quote_date DESC, qi.quote_id DESC, qi.sort_order, qi.item_id";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $tagName = qkw_sub_tag_names($pdo);
    $items = []; $total = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $spec = (string)($r['specification'] ?? '');
        if (trim($spec) === '') continue;
        if (!qkw_rule_hit($rule, $spec, (string)($r['client_id'] ?? ''))) continue;
        $isSet = ((int)$r['has_map']) || trim((string)$r['process_notes']) !== '' || (int)$r['note_only'];
        if ($filter === 'unset' && $isSet) continue;
        if ($filter === 'set'  && !$isSet) continue;
        $total++;
        if (count($items) >= $limit) continue;
        $cur = qkw_split_ids((string)$r['process_notes']);
        $items[] = ['item_id' => (int)$r['item_id'], 'quote_id' => (int)$r['quote_id'], 'quote_no' => $r['quote_no'],
                    'client_name' => $r['client_name'], 'product_id' => $r['product_id'],
                    'd_setting_d_id' => (int)($r['d_setting_d_id'] ?? 0), 'spec' => $spec,
                    'note_only' => (int)$r['note_only'], 'cur_ids' => implode(',', $cur),
                    'cur_names' => implode('、', array_map(fn($i) => $tagName[$i] ?? ('#' . $i), $cur)),
                    'has_set' => $isSet ? 1 : 0];
    }
    return ['items' => $items, 'total' => $total, 'truncated' => $total > count($items) ? 1 : 0];
}

// 在既有標籤上「增加」或「移除」幾個標籤（既有的 qkw_apply 是整組取代，不能拿來加減）
//   add    ：不刪既有的 process_no 對照，只補上新標籤帶來的——舊資料有「有 process_no 卻沒有
//            process_notes」的情況，整組重建會把原本的製程默默清掉
//   remove ：一定要重建，所以只作用在「真的有 process_notes」的項目，其餘略過並回報
function qkw_adjust_tags(PDO $pdo, array $itemIds, array $tagIds, string $mode, string $userId): array
{
    qkw_ensure_schema($pdo);
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    $tagIds  = qkw_split_ids(implode(',', $tagIds));
    if (!$itemIds) throw new Exception('沒有選擇任何項目');
    if (!$tagIds)  throw new Exception('沒有選擇任何製程標籤');
    if (count($itemIds) > 20000) throw new Exception('一次最多處理 20000 筆項目，請分批');
    if (!in_array($mode, ['add', 'remove'], true)) throw new Exception('動作不正確');

    $tph = implode(',', array_fill(0, count($tagIds), '?'));
    $vt = $pdo->prepare("SELECT sub_tag_id FROM quotation_process_sub_tag WHERE sub_tag_id IN ($tph) AND is_active=1");
    $vt->execute($tagIds);
    $tagIds = array_values(array_intersect($tagIds, array_map('intval', $vt->fetchAll(PDO::FETCH_COLUMN))));
    if (!$tagIds) throw new Exception('選到的製程標籤不存在或已停用');

    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    $st = $pdo->prepare("SELECT qi.item_id, qi.process_notes, qi.note_only
                         FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                         WHERE qi.item_id IN ($ph) AND ql.pending_review=1");
    $st->execute($itemIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) throw new Exception('選到的項目都不是尚待確認的報價單');

    $byNew = []; $skipped = 0; $addOnly = [];
    foreach ($rows as $r) {
        $cur = qkw_split_ids((string)$r['process_notes']);
        if ($mode === 'remove') {
            if (!$cur) { $skipped++; continue; }                          // 沒有標籤可拿掉
            $new = array_values(array_diff($cur, $tagIds));
            if (count($new) === count($cur)) { $skipped++; continue; }    // 本來就沒有這些標籤
        } else {
            $new = array_values(array_unique(array_merge($cur, $tagIds)));
            if (count($new) === count($cur)) { $skipped++; continue; }    // 全都已經有了
            $addOnly[(int)$r['item_id']] = array_values(array_diff($tagIds, $cur));
        }
        sort($new);
        $byNew[implode(',', $new)][] = (int)$r['item_id'];
    }
    if (!$byNew) return ['items' => 0, 'skipped' => $skipped];

    $done = 0;
    $pdo->beginTransaction();
    try {
        foreach ($byNew as $key => $ids) {
            $new = qkw_split_ids((string)$key);
            $vph = implode(',', array_fill(0, count($ids), '?'));
            $pnos = []; $groupType = 'single_process';
            if ($new) {
                $npo = implode(',', array_fill(0, count($new), '?'));
                $pq  = $pdo->prepare("SELECT DISTINCT process_no FROM quotation_process_tag_map WHERE sub_tag_id IN ($npo)");
                $pq->execute($new);
                $pnos = array_map('intval', $pq->fetchAll(PDO::FETCH_COLUMN));
                $gq = $pdo->prepare("SELECT g.group_type FROM quotation_process_sub_tag s JOIN quotation_process_tag_group g ON g.group_id=s.group_id WHERE s.sub_tag_id=? LIMIT 1");
                $gq->execute([$new[0]]);
                $groupType = $gq->fetchColumn() ?: 'single_process';
            }

            if ($mode === 'remove') {
                $pdo->prepare("DELETE FROM quotation_item_process_map WHERE quotation_item_id IN ($vph)")->execute($ids);
                if ($pnos) {
                    $ins = $pdo->prepare("INSERT INTO quotation_item_process_map (quotation_item_id,process_no) VALUES (?,?)");
                    foreach ($ids as $iid) foreach ($pnos as $pn) $ins->execute([$iid, $pn]);
                }
            } else {
                // 只補新增標籤帶來的 process_no，且先確認沒有才插入（該表沒有唯一索引）
                $chk = $pdo->prepare("SELECT 1 FROM quotation_item_process_map WHERE quotation_item_id=? AND process_no=? LIMIT 1");
                $ins = $pdo->prepare("INSERT INTO quotation_item_process_map (quotation_item_id,process_no) VALUES (?,?)");
                foreach ($ids as $iid) {
                    $addIds = $addOnly[$iid] ?? [];
                    if (!$addIds) continue;
                    $aph = implode(',', array_fill(0, count($addIds), '?'));
                    $aq  = $pdo->prepare("SELECT DISTINCT process_no FROM quotation_process_tag_map WHERE sub_tag_id IN ($aph)");
                    $aq->execute($addIds);
                    foreach (array_map('intval', $aq->fetchAll(PDO::FETCH_COLUMN)) as $pn) {
                        $chk->execute([$iid, $pn]);
                        if (!$chk->fetchColumn()) $ins->execute([$iid, $pn]);
                    }
                }
            }
            $pdo->prepare("UPDATE quotation_item SET process_group_type=?, process_notes=?, note_only=0, updated_at=NOW() WHERE item_id IN ($vph)")
                ->execute(array_merge([$groupType, $new ? implode(',', $new) : null], $ids));
            $done += count($ids);
        }
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
    return ['items' => $done, 'skipped' => $skipped];
}

// ── 套用 ───────────────────────────────────────────────────
// $batches = [ ['sub_tag_ids'=>'12,13','item_ids'=>[...]], ['to_note'=>1,'item_ids'=>[...]] ]
// 寫入規則與 quick_set_process_bulk 完全一致（process_notes 一定要一起寫），只是來源改成確認過的建議。
function qkw_apply(PDO $pdo, array $batches, string $userId): array
{
    qkw_ensure_schema($pdo);
    $doneItems = 0; $noteQuotes = [];
    $pdo->beginTransaction();
    try {
        foreach ($batches as $b) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $b['item_ids'] ?? []))));
            if (!$ids) continue;
            if (count($ids) > 20000) throw new Exception('一次最多套用 20000 筆項目，請分批確認');
            // 只作用在尚待確認的報價單（與其他 quick_* 動作一致）
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT qi.item_id, qi.quote_id FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id WHERE qi.item_id IN ($ph) AND ql.pending_review=1");
            $st->execute($ids);
            $valid = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$valid) continue;
            $vids = array_map(fn($r) => (int)$r['item_id'], $valid);
            $vph  = implode(',', array_fill(0, count($vids), '?'));

            if (!empty($b['to_note'])) {
                // 帶入備註：這一筆改以備註表示，原本的製程設定一併清掉（畫面上該列直接顯示為備註）
                $pdo->prepare("DELETE FROM quotation_item_process_map WHERE quotation_item_id IN ($vph)")->execute($vids);
                $pdo->prepare("UPDATE quotation_item SET note_only=1, process_notes=NULL, updated_at=NOW() WHERE item_id IN ($vph)")->execute($vids);
                foreach ($valid as $r) $noteQuotes[(int)$r['quote_id']] = true;
                $doneItems += count($vids);
                continue;
            }

            $tags = qkw_split_ids((string)($b['sub_tag_ids'] ?? ''));
            if (!$tags) continue;
            $tph = implode(',', array_fill(0, count($tags), '?'));
            $vt  = $pdo->prepare("SELECT sub_tag_id FROM quotation_process_sub_tag WHERE sub_tag_id IN ($tph) AND is_active=1");
            $vt->execute($tags);
            $tags = array_values(array_intersect($tags, array_map('intval', $vt->fetchAll(PDO::FETCH_COLUMN))));
            if (!$tags) throw new Exception('建議的製程標籤不存在或已停用，請重新偵測');

            // process_no 一律由子標籤即時展開，不採信前端（鐵律8）
            $tph2 = implode(',', array_fill(0, count($tags), '?'));
            $pq = $pdo->prepare("SELECT DISTINCT process_no FROM quotation_process_tag_map WHERE sub_tag_id IN ($tph2)");
            $pq->execute($tags);
            $pnos = array_map('intval', $pq->fetchAll(PDO::FETCH_COLUMN));
            $gq = $pdo->prepare("SELECT g.group_type FROM quotation_process_sub_tag s JOIN quotation_process_tag_group g ON g.group_id=s.group_id WHERE s.sub_tag_id=? LIMIT 1");
            $gq->execute([$tags[0]]);
            $groupType = $gq->fetchColumn() ?: 'single_process';

            $pdo->prepare("DELETE FROM quotation_item_process_map WHERE quotation_item_id IN ($vph)")->execute($vids);
            if ($pnos) {
                $ins = $pdo->prepare("INSERT INTO quotation_item_process_map (quotation_item_id,process_no) VALUES (?,?)");
                foreach ($vids as $iid) foreach ($pnos as $pn) $ins->execute([$iid, $pn]);
            }
            $pdo->prepare("UPDATE quotation_item SET process_group_type=?, process_notes=?, note_only=0, updated_at=NOW() WHERE item_id IN ($vph)")
                ->execute(array_merge([$groupType, implode(',', $tags)], $vids));
            $doneItems += count($vids);
        }
        foreach (array_keys($noteQuotes) as $qid) qkw_sync_quote_note($pdo, $qid);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
    return ['items' => $doneItems, 'note_quotes' => count($noteQuotes)];
}

// 單筆切換「帶入備註」（項目列上那顆按鈕）
function qkw_set_note_only(PDO $pdo, int $itemId, bool $on): array
{
    qkw_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT qi.quote_id, ql.pending_review FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id WHERE qi.item_id=?");
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('找不到報價項目');
    if (!$row['pending_review']) throw new Exception('此報價單已是正式資料，請至報價單管理頁編輯');
    $pdo->beginTransaction();
    try {
        if ($on) {
            $pdo->prepare("DELETE FROM quotation_item_process_map WHERE quotation_item_id=?")->execute([$itemId]);
            $pdo->prepare("UPDATE quotation_item SET note_only=1, process_notes=NULL, updated_at=NOW() WHERE item_id=?")->execute([$itemId]);
        } else {
            $pdo->prepare("UPDATE quotation_item SET note_only=0, updated_at=NOW() WHERE item_id=?")->execute([$itemId]);
        }
        $note = qkw_sync_quote_note($pdo, (int)$row['quote_id']);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
    return ['quote_id' => (int)$row['quote_id'], 'note' => $note];
}

// ── 建議規則範本 ───────────────────────────────────────────
// 使用者按「載入建議規則範本」時才寫入，系統不會自己偷偷建規則。
// 名稱相同的規則視為已存在、不重複建立（可重複按）。
// 排除字很重要：規格「齒研冶具」同時含「齒研」與「冶具」，不排掉就會同時建議「齒研」與「齒研治具」兩個標籤。
function qkw_seed_rules(): array
{
    // [規則名稱, 包含, 不包含, 子標籤id, 排序]
    return [
        ['治具-齒研', '齒研,冶具|治具',   '',                  '10', 90],
        ['治具-滾齒', '滾齒,冶具|治具',   '',                  '11', 90],
        ['治具-插齒', '插齒,冶具|治具',   '',                  '16', 90],
        ['治具-線割', '線割,冶具|治具',   '',                  '12', 90],
        ['刀具-滾齒刀', '滾齒刀|滾刀',    '',                  '21', 80],
        ['刀具-插齒刀', '插齒刀',         '',                  '22', 80],
        ['刀具-銑刀',   '銑刀',           '',                  '23', 80],
        ['刀具-拉刀',   '拉刀',           '',                  '24', 80],
        ['全製',        '全製',           '',                  '9',  70],
        ['齒研',        '齒研',           '冶具,治具,刀,全製', '8',  50],
        ['插齒',        '插齒',           '冶具,治具,刀',      '25', 50],
        ['線割',        '線割',           '冶具,治具,刀,內齒', '41', 50],
        ['線割內齒',    '線割,內齒',      '冶具,治具',         '15', 60],
        ['鍍TIN',       'TIN',            '',                  '44', 50],
        ['雷刻',        '雷刻',           '全製',              '26', 50],
        ['車床',        '車床',           '',                  '27', 50],
        ['銑床',        '銑床',           '',                  '28', 50],
        ['探傷',        '探傷',           '',                  '39', 50],
        ['外研',        '外研',           '',                  '17', 50],
        ['孔平研',      '孔平研',         '',                  '48', 50],
        ['磨銳',        '磨銳',           '',                  '43', 50],
        ['修齒',        '修齒',           '',                  '45', 50],
        ['染黑',        '染黑',           '',                  '32', 50],
        ['高週波',      '高週波',         '',                  '31', 50],
    ];
}

function qkw_seed_apply(PDO $pdo, string $userId): int
{
    qkw_ensure_schema($pdo);
    $exist = [];
    foreach ($pdo->query("SELECT rule_name FROM quotation_kw_rule")->fetchAll(PDO::FETCH_COLUMN) as $n) $exist[$n] = true;
    $n = 0;
    foreach (qkw_seed_rules() as $r) {
        if (isset($exist[$r[0]])) continue;
        // 標籤已被停用或刪掉的範本就跳過（不硬塞進去，否則掃描時會建議一個不存在的標籤）
        try {
            qkw_rule_save($pdo, ['rule_name' => $r[0], 'include_kw' => $r[1], 'include_mode' => 'all',
                                 'exclude_kw' => $r[2], 'sub_tag_ids' => $r[3], 'priority' => $r[4],
                                 'is_active' => 1], $userId);
            $n++;
        } catch (Exception $e) { /* 該標籤不存在就略過這條 */ }
    }
    return $n;
}

// 依該報價單目前「以備註代替製程」的項目重建備註區塊。
// 使用者自己寫在區塊標題以上的備註完全不動；取消時那一行自然消失（整段重建，不必逐行刪）。
function qkw_sync_quote_note(PDO $pdo, int $quoteId): string
{
    $st = $pdo->prepare("SELECT note FROM quotation_list WHERE quote_id=?");
    $st->execute([$quoteId]);
    $note = (string)($st->fetchColumn() ?: '');
    $nl   = PHP_EOL;
    $pos  = mb_strpos($note, QKW_NOTE_HEAD);
    $base = rtrim($pos === false ? $note : mb_substr($note, 0, $pos));

    $q = $pdo->prepare("SELECT product_id, specification FROM quotation_item WHERE quote_id=? AND note_only=1 ORDER BY sort_order, item_id");
    $q->execute([$quoteId]);
    $lines = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $spec = trim((string)$r['specification']);
        if ($spec === '') continue;
        $line = trim((string)$r['product_id']) !== '' ? ($r['product_id'] . '：' . $spec) : $spec;
        if (!in_array($line, $lines, true)) $lines[] = $line;   // 同料號同規格只留一行
    }
    $new = $lines ? (($base !== '' ? $base . $nl : '') . QKW_NOTE_HEAD . $nl . implode($nl, $lines)) : $base;
    $pdo->prepare("UPDATE quotation_list SET note=?, updated_at=NOW() WHERE quote_id=?")->execute([$new, $quoteId]);
    return $new;
}

} // function_exists
