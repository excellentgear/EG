<?php
/**
 * AS 文件編號綁定 —— 全站共用（2026-08-03 建立，使用者明確要求「每個頁面綁定方式都不一樣，要統一」）
 *
 * 解決的問題：列印文件要在頁尾右下印 AS 文件編號、表頭印該文件的表單名稱（見 ai-rules/16），
 * 但過去每個模組各自寫一份「綁定哪一份 AS 文件」的設定與 UI——存放位置不同、篩選方式不同、
 * 有的只有下拉沒有篩選，文件一多就找不到。**一律改用本庫 + resource/js/eg_asdoc_picker.js。**
 *
 * 用法（後端）：
 *   require_once __DIR__.'/asdoc_lib.php';
 *   $doc = eg_asdoc_get($db, 'order_change');     // ['id','doc_no','doc_name','current_version'] 或 null
 *   $all = eg_asdoc_list($db);                    // 給前端挑選用的清單
 *   eg_asdoc_save($db, 'order_change', $docId, $userName);
 *
 * 用法（前端）：載入 resource/js/eg_asdoc_picker.js 後
 *   EGAsDoc.open({docs: 清單, current: 目前id, onSave: function(id, doc){ ...存檔... }});
 *
 * 綁定值只存 `as_document.id`，**禁止把編號/名稱字串存進設定**（改名或換版就對不上，見 ai-rules/16）。
 */
if (!function_exists('eg_asdoc_list')) {

/** 統一存放位置：system_parameters(param_group='AS_DOC_BIND', param_key=模組代碼) */
define('EG_ASDOC_GROUP', 'AS_DOC_BIND');

/**
 * 既有模組原本各自存放的位置（統一位置尚未寫入時的回退來源，避免既有綁定失效）。
 * 模組代碼 => [param_group, param_key]
 */
define('EG_ASDOC_LEGACY', [
    'external_doc' => ['EXTERNAL_DOC', 'as_doc_id'],
]);

/** 可綁定的 AS 文件清單（未刪除者，依編號排序） */
function eg_asdoc_list(PDO $db): array {
    try {
        return $db->query("SELECT id, doc_no, doc_name FROM as_document WHERE is_deleted=0 ORDER BY doc_no")
                  ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 某模組綁定的 as_document.id（未綁定回 0） */
function eg_asdoc_id(PDO $db, string $module): int {
    $read = function (string $g, string $k) use ($db): int {
        try {
            $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
            $st->execute([$g, $k]);
            $v = $st->fetchColumn();
            if ($v === false) return 0;
            $d = json_decode((string)$v, true);          // 舊資料有的存 json 有的存純數字
            return (int)(is_numeric($d) ? $d : (is_numeric($v) ? $v : 0));
        } catch (Throwable $e) { return 0; }
    };
    $id = $read(EG_ASDOC_GROUP, $module);
    if (!$id && isset(EG_ASDOC_LEGACY[$module])) {
        $lg = EG_ASDOC_LEGACY[$module];
        $id = $read($lg[0], $lg[1]);
    }
    return $id;
}

/** 某模組綁定的 AS 文件（未綁定或文件已刪回 null）；列印表頭取 doc_name、頁尾右下取 doc_no */
function eg_asdoc_get(PDO $db, string $module): ?array {
    $id = eg_asdoc_id($db, $module);
    if (!$id) return null;
    try {
        $st = $db->prepare("SELECT id, doc_no, doc_name, current_version, doc_level FROM as_document WHERE id=? AND is_deleted=0");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/** 列印用文件編號：僅**四階文件**（表單/記錄表）在 doc_no 後方附加 current_version，
 *  一階/二階（手冊/程序書）不附加；無版次時也不附加（例：2-MM-01-11 二階不附加／2-MM-01-11B 四階有版次 B）（見 ai-rules/16 第三節）。
 *  凡是把綁定的 AS 文件編號印在頁尾/表頭的地方，一律用這支組出字串，不要只印 doc_no。
 *  $doc 需含 doc_no、doc_level、current_version（eg_asdoc_get() 已含這些欄位）。 */
function eg_asdoc_no(?array $doc): string {
    if (!$doc || empty($doc['doc_no'])) return '';
    $no = (string)$doc['doc_no'];
    if (($doc['doc_level'] ?? '') === '四階') {
        $no .= (string)($doc['current_version'] ?? '');
    }
    return $no;
}

/** 依「業務日期」回推該文件當時生效的版次（2026-08-06 使用者明確要求：列印舊單據要顯示該單據業務日期當時生效的版次，
 *  不能一律顯示現在最新版——例如會議記錄 AS 表單 A 版 2025.01.01 生效、B 版 2025.12.09 生效，列印 2025.09.08 的
 *  會議紀錄要顯示 A 版，列印 2025.12.09（含）之後的要顯示 B 版，即改版生效日當天就啟用新版）。
 *  用 as_document_version.revised_date（改版生效日）挑出「revised_date <= 業務日期」中最新的一筆版次。
 *  傳回 null＝該文件完全無版本履歷資料（as_document_version 尚未補建），呼叫端應以 as_document.current_version 回退；
 *  傳回字串（可能是空字串，代表當時該文件尚無版次）＝查到的正確答案，不可再退回 current_version。
 *  $bizDate 傳 null／空字串＝視為今天（等同舊行為：印目前最新版）。 */
function eg_asdoc_version_asof(PDO $db, int $docId, ?string $bizDate): ?string {
    if (!$docId) return null;
    try {
        $st = $db->prepare("SELECT version FROM as_document_version WHERE doc_id=? AND revised_date<=? ORDER BY revised_date DESC, id DESC LIMIT 1");
        $st->execute([$docId, $bizDate ?: date('Y-m-d')]);
        $v = $st->fetchColumn();
        if ($v !== false) return (string)$v;
        // 業務日期早於該文件最早一筆改版紀錄（例如單據日期比文件第一次建檔還早）：退回最早一筆版次
        $st2 = $db->prepare("SELECT version FROM as_document_version WHERE doc_id=? ORDER BY revised_date ASC, id ASC LIMIT 1");
        $st2->execute([$docId]);
        $v2 = $st2->fetchColumn();
        if ($v2 !== false) return (string)$v2;
    } catch (Throwable $e) {}
    return null;
}

/** 依 as_document.id 直接組出「該業務日期」應印出的文件編號（含版次，僅四階附加，規則同 eg_asdoc_no()）。
 *  凡是列印「單一筆有自己業務日期的單據」（會議紀錄的會議日期、報價單的報價日期、檢驗記錄的檢驗日期…），
 *  頁尾/表頭的 AS 文件編號一律呼叫這支並帶入該筆單據自己的業務日期，不要再呼叫 eg_asdoc_no() 印「現在最新版」。
 *  業務日期認定：優先用單據本身的日期欄位；單據沒有日期欄位（罕見）才用單據建立日期；
 *  尚未存檔的新單據預覽列印用列印當下（傳 null）。
 *  多筆彙總的清單型列印（如AS文件結構總覽、外來文件清單、訂單變更歷史清單）不適用本函式——那種列印本來
 *  就是「印出當下的現況清單」，本來就該用今天最新版，直接用 eg_asdoc_no() 即可，見 ai-rules/16 第三之二節。 */
function eg_asdoc_no_asof_id(PDO $db, int $docId, ?string $bizDate = null): string {
    if (!$docId) return '';
    try {
        $st = $db->prepare("SELECT doc_no, doc_level, current_version FROM as_document WHERE id=? AND is_deleted=0");
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return '';
    } catch (Throwable $e) { return ''; }
    $v = eg_asdoc_version_asof($db, $docId, $bizDate);
    if ($v !== null) $doc['current_version'] = $v;
    return eg_asdoc_no($doc);
}

/** 依模組代碼＋業務日期組出文件編號，等同 eg_asdoc_no_asof_id(eg_asdoc_id($db,$module), $bizDate)。 */
function eg_asdoc_no_asof(PDO $db, string $module, ?string $bizDate = null): string {
    return eg_asdoc_no_asof_id($db, eg_asdoc_id($db, $module), $bizDate);
}

/**
 * 反查：哪些「模組代碼以 $prefix 開頭」的綁定指向這份 AS 文件，回傳代碼後綴的整數 id 陣列。
 * 例：eg_asdoc_bound_ids($db, 'fsd_tpl_', 26) → [3]（＝表單簽核樣板 id 3 綁了這份文件）
 *
 * 為什麼要有這支：AS 文件管理的「填寫紀錄」要由文件往回找「有哪些樣板／模板是這份表單」，
 * 而綁定值存在 system_parameters(param_group='AS_DOC_BIND')、且**舊資料有的存 json 有的存純數字**
 * （見 eg_asdoc_id()）。各頁自己 parse 一次遲早漏掉 json 那種寫法，故一律走這支。
 * 同一份文件可能被多個樣板綁（實際存在：fsd_tpl_78 與 fsd_tpl_79 都綁 doc 5），所以回傳的是陣列。
 */
function eg_asdoc_bound_ids(PDO $db, string $prefix, int $docId): array {
    if ($docId <= 0 || $prefix === '') return [];
    try {
        $st = $db->prepare("SELECT param_key, param_value FROM system_parameters
                            WHERE param_group=? AND param_key LIKE ?");
        $st->execute([EG_ASDOC_GROUP, $prefix . '%']);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $suffix = substr((string)$r['param_key'], strlen($prefix));
            if ($suffix === '' || !ctype_digit($suffix)) continue;   // 只認純數字後綴，避免撿到別的設定鍵
            $v = (string)$r['param_value'];
            $d = json_decode($v, true);
            $id = (int)(is_numeric($d) ? $d : (is_numeric($v) ? $v : 0));
            if ($id === $docId) $out[] = (int)$suffix;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * AS 文件管理的頁面 ACRUD 權限字串（user_permissions.php 權限矩陣：page scope 優先、group scope 備援）。
 * 唯一實作——AS_Document_API 的 asPagePerm() 直接轉呼叫這支，其他模組要判斷「這個人有沒有 AS 文件檢閱權」
 * 一律走 eg_asdoc_user_can()，不要各自再抄一份查詢。
 */
function eg_asdoc_page_perm(PDO $db, int $uid): string {
    if ($uid <= 0) return '';
    try {
        $st = $db->prepare("SELECT page_id, group_id FROM system_module_pages
                            WHERE page_url LIKE '%views/ADM/as_document_management.php' LIMIT 1");
        $st->execute();
        $pg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pg) return '';
        $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $st->execute([$uid, $pg['page_id']]);
        $perms = $st->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms) && !empty($pg['group_id'])) {
            $st = $db->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $st->execute([$pg['group_id']]);
            $gCode = $st->fetchColumn();
            if ($gCode) {
                $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $st->execute([$uid, $gCode]);
                $perms = $st->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars = [];
        foreach ($perms as $p) { $chars = array_merge($chars, str_split((string)$p)); }
        return implode('', array_unique($chars));
    } catch (Throwable $e) { return ''; }
}

/** 能力判斷本體（不查 DB，供已經算好 features／pagePerm 的呼叫端使用；AS_Document_API 的 asCan() 走這支）。 */
function eg_asdoc_can_with(array $features, bool $isRoleAdmin, string $pagePerm, string $what): bool {
    if ($isRoleAdmin || strpos($pagePerm, 'A') !== false) return true;
    $charMap = ['view'=>'R', 'create'=>'C', 'update'=>'U', 'delete'=>'D'];
    if (isset($charMap[$what]) && strpos($pagePerm, $charMap[$what]) !== false) return true;
    // 上傳紀錄：與「新增文件」分開設定，但相容既有已具新增文件權限者
    if ($what === 'upload_record')
        return in_array('asdoc_upload_record', $features, true)
            || eg_asdoc_can_with($features, $isRoleAdmin, $pagePerm, 'create');
    return in_array('asdoc_' . $what, $features, true);
}

/** 由 user id 直接判斷 AS 文件管理的某項能力（view/create/update/delete/settings/…）。 */
function eg_asdoc_user_can(PDO $db, int $uid, string $what): bool {
    if ($uid <= 0) return false;
    require_once __DIR__ . '/role_features_helper.php';
    try { $features = rf_load_user_features_override($db, $uid, 'as_doc'); }
    catch (Throwable $e) { $features = []; }
    return eg_asdoc_can_with($features, in_array('all', $features, true), eg_asdoc_page_perm($db, $uid), $what);
}

/* ════════════════ AS 文件的「作業項目」（2026-09-11 使用者交辦） ════════════════
 * 是什麼：一份 AS 文件用白話寫出「這份文件實際上在做哪幾件事」，例如
 *   供應商管理程序 → 外包加工、供應商評鑑；品質管制程序 → 品管檢測、進料檢驗。
 * 為什麼要：內稽查檢表的題目是 AS9100 條文原文（「8.4 外部提供的過程、產品和服務的控制」），
 *   光看條文完全不知道實務上對應公司的哪一段作業，建查檢表時只能憑印象亂勾。
 * 為什麼一份文件要能寫好幾個：**同一份文件常常不只做一件事**（使用者原話），
 *   所以是文件底下掛多個作業項目，條文再去挑「這一條對應到哪幾個」。
 * 唯一實作放這裡：AS 文件管理（維護）與內部稽核（挑選、顯示）兩頁共用同一份，
 *   兩邊各寫一份遲早走鐘（鐵律4）。
 */

/** 建表（可重複執行）。⚠ DDL 會隱式 commit，呼叫端一律在 beginTransaction() 之前呼叫。 */
function eg_asdoc_task_ensure(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS as_doc_task (
            task_id    INT AUTO_INCREMENT PRIMARY KEY,
            doc_id     INT NOT NULL,
            task_name  VARCHAR(60) NOT NULL COMMENT '作業項目（白話用途，如 品管檢測／外包加工）',
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL,
            updated_by VARCHAR(60) NULL,
            UNIQUE KEY uq_adt (doc_id, task_name),
            KEY idx_adt_doc (doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS文件的作業項目（一份文件可有多個，見 asdoc_lib.php）'");
    } catch (Throwable $e) { /* 沒權限改結構時不擋主流程，本功能自然不出現 */ }
}

/** 作業項目清單。$docIds 留空＝全部；回傳依文件、sort_order 排序的原始列 */
function eg_asdoc_tasks(PDO $db, array $docIds = []): array {
    eg_asdoc_task_ensure($db);
    try {
        $docIds = array_values(array_unique(array_filter(array_map('intval', $docIds))));
        $sql = "SELECT t.task_id, t.doc_id, t.task_name, t.sort_order,
                       d.doc_no, d.doc_name
                  FROM as_doc_task t
                  JOIN as_document d ON d.id = t.doc_id
                 WHERE d.is_deleted = 0";
        $arg = [];
        if ($docIds) {
            $sql .= " AND t.doc_id IN (" . implode(',', array_fill(0, count($docIds), '?')) . ")";
            $arg = $docIds;
        }
        $sql .= " ORDER BY d.doc_no, t.sort_order, t.task_id";
        $st = $db->prepare($sql);
        $st->execute($arg);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 每份文件有幾個作業項目（清單上標「已設 N」用）。回 [doc_id => 數量] */
function eg_asdoc_task_counts(PDO $db): array {
    eg_asdoc_task_ensure($db);
    $out = [];
    try {
        foreach ($db->query("SELECT doc_id, COUNT(*) c FROM as_doc_task GROUP BY doc_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['doc_id']] = (int)$r['c'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 名稱清洗：去頭尾空白、把全形空白與連續空白收成一個、長度上限 */
function eg_asdoc_task_norm(string $s): string {
    $s = str_replace(["\xe3\x80\x80", "\r", "\n", "\t"], ' ', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, 30);
}

/**
 * 整份重存某文件的作業項目（$names 依畫面順序）。
 * 刻意用「比對後只動有變的」而不是全刪重建：task_id 一換，條文那邊挑好的對應就會整批不見。
 * 回傳 ['added'=>n,'removed'=>n,'kept'=>n,'unlinked'=>被連帶解除的條文對應數]
 */
function eg_asdoc_tasks_save(PDO $db, int $docId, array $names, string $by = ''): array {
    eg_asdoc_task_ensure($db);
    $clean = [];
    foreach ($names as $n) {
        $n = eg_asdoc_task_norm((string)$n);
        if ($n === '' || isset($clean[$n])) continue;
        $clean[$n] = true;
    }
    $clean = array_keys($clean);
    if (count($clean) > 30) throw new RuntimeException('一份文件最多 30 個作業項目');

    $st = $db->prepare("SELECT task_id, task_name FROM as_doc_task WHERE doc_id=?");
    $st->execute([$docId]);
    $cur = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cur[(string)$r['task_name']] = (int)$r['task_id'];

    $del = [];
    foreach ($cur as $name => $tid) if (!in_array($name, $clean, true)) $del[] = $tid;

    $unlinked = 0;
    if ($del) {
        $in = implode(',', array_fill(0, count($del), '?'));
        // 條文那邊挑過的對應要一起解除，否則會留下指向已刪項目的孤兒列
        try {
            $q = $db->prepare("SELECT COUNT(*) FROM ia_as_clause_task WHERE task_id IN ($in)");
            $q->execute($del);
            $unlinked = (int)$q->fetchColumn();
            $db->prepare("DELETE FROM ia_as_clause_task WHERE task_id IN ($in)")->execute($del);
        } catch (Throwable $e) { /* 內稽模組的表還沒建起來時略過 */ }
        $db->prepare("DELETE FROM as_doc_task WHERE task_id IN ($in)")->execute($del);
    }

    $add = 0;
    $ins = $db->prepare("INSERT INTO as_doc_task (doc_id, task_name, sort_order, updated_at, updated_by)
                         VALUES (?,?,?,NOW(),?)");
    $upd = $db->prepare("UPDATE as_doc_task SET sort_order=?, updated_at=NOW(), updated_by=? WHERE task_id=?");
    foreach ($clean as $i => $name) {
        if (isset($cur[$name])) { $upd->execute([($i + 1) * 10, $by, $cur[$name]]); }
        else { $ins->execute([$docId, $name, ($i + 1) * 10, $by]); $add++; }
    }
    return ['added'=>$add, 'removed'=>count($del), 'kept'=>count($clean) - $add, 'unlinked'=>$unlinked];
}

/** 存綁定（$docId=0 代表取消綁定） */
function eg_asdoc_save(PDO $db, string $module, int $docId, string $by = ''): void {
    try {
        $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([EG_ASDOC_GROUP, $module]);
        $rid = $st->fetchColumn();
        if ($rid) {
            $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")
               ->execute([(string)$docId, $by, $rid]);
        } else {
            $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by)
                          VALUES (?,?,?,?,?)")
               ->execute([EG_ASDOC_GROUP, $module, (string)$docId, $module.' 綁定的 AS 文件 id（0=未綁定）', $by]);
        }
    } catch (Throwable $e) {}
}

}
