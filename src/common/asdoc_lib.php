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
