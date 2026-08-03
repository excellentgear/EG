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
        $st = $db->prepare("SELECT id, doc_no, doc_name, current_version FROM as_document WHERE id=? AND is_deleted=0");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
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
