<?php
/**
 * PFMEA 參考資料庫（製程代號／潛在失效模式／控制預防控制偵測選項／製程整組樣板）共用庫。
 * 資料來源：3-TD-01-02-潛在失效模式及效應分析.xlsm 的「資料庫」「項目異常」工作表（2026-08-13 匯入）。
 * 使用者明確要求：這些清單可填表人(canEdit)就能新增／自行輸入新值，但僅管理員(canAdmin)可以刪除。
 * 資料表定義見 pfmea_lib.php ensure_schema()。
 */

function pfmea_ref_process_list(PDO $db): array {
    return $db->query("SELECT id, process_code, process_name FROM pfmea_process WHERE is_active=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
}

/** 新增或取得製程代號（代號已存在則直接回傳其id，不重複新增；名稱不同時不覆蓋既有名稱） */
function pfmea_ref_process_get_or_add(PDO $db, string $code, string $name, int $uid, string $uname): int {
    $code = trim($code); $name = trim($name);
    $st = $db->prepare("SELECT id FROM pfmea_process WHERE process_code=? LIMIT 1");
    $st->execute([$code]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_process");
    $st->execute();
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_process (process_code, process_name, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?)");
    $st->execute([$code, $name ?: $code, $sort, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_ref_process_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_process SET is_active=0 WHERE id=?")->execute([$id]);
}

/**
 * 潛在失效模式清單：2026-08-13 使用者要求改進為階層式，優先套用「功能」層級的專屬清單，
 * 該層級還沒有人填過(空清單)才退回「項目」層級，再沒有才退回「製程」層級(舊148筆通用清單，
 * 向下相容既有資料)——逐層退回，不會因為新層級還沒累積資料就讓使用者選不到任何東西。
 */
function pfmea_ref_failure_mode_list(PDO $db, int $processId, int $itemOptionId = 0, int $functionOptionId = 0): array {
    if ($functionOptionId) {
        $st = $db->prepare("SELECT id, failure_mode FROM pfmea_process_failure_mode WHERE function_option_id=? AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$functionOptionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) return $rows;
    }
    if ($itemOptionId) {
        $st = $db->prepare("SELECT id, failure_mode FROM pfmea_process_failure_mode WHERE item_option_id=? AND function_option_id IS NULL AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$itemOptionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) return $rows;
    }
    $st = $db->prepare("SELECT id, failure_mode FROM pfmea_process_failure_mode WHERE process_id=? AND item_option_id IS NULL AND function_option_id IS NULL AND is_active=1 ORDER BY sort_order, id");
    $st->execute([$processId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 新增時比照使用者當下所在的層級(有選到功能就記在功能層級，否則記項目層級，否則記製程層級通用) */
function pfmea_ref_failure_mode_add(PDO $db, int $processId, string $text, int $uid, string $uname, int $itemOptionId = 0, int $functionOptionId = 0): int {
    $text = trim($text);
    $cond = $functionOptionId ? "function_option_id=?" : ($itemOptionId ? "item_option_id=? AND function_option_id IS NULL" : "process_id=? AND item_option_id IS NULL AND function_option_id IS NULL");
    $key = $functionOptionId ?: ($itemOptionId ?: $processId);
    $st = $db->prepare("SELECT id FROM pfmea_process_failure_mode WHERE $cond AND failure_mode=? LIMIT 1");
    $st->execute([$key, $text]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_process_failure_mode WHERE $cond");
    $st->execute([$key]);
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_process_failure_mode (process_id, failure_mode, item_option_id, function_option_id, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$processId, $text, $itemOptionId ?: null, $functionOptionId ?: null, $sort, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_ref_failure_mode_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_process_failure_mode SET is_active=0 WHERE id=?")->execute([$id]);
}

/* ---------- 料號-製程-項目-功能-要求 階層式連動（2026-08-13使用者要求）----------
 * 製程代號→項目(pfmea_item_option)→功能(pfmea_function_option)→要求(pfmea_requirement_option，
 * 依綁定料號再細分)；潛在失效模式改走上面的階層式pfmea_ref_failure_mode_list。
 * 一律可填表人新增(自行輸入新值即註冊)、僅管理員可刪除，與既有製程代號清單同一套權限慣例。 */
function pfmea_ref_item_options(PDO $db, int $processId): array {
    $st = $db->prepare("SELECT id, item_name FROM pfmea_item_option WHERE process_id=? AND is_active=1 ORDER BY sort_order, id");
    $st->execute([$processId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pfmea_ref_item_option_get_or_add(PDO $db, int $processId, string $itemName, int $uid, string $uname): int {
    $itemName = trim($itemName);
    $st = $db->prepare("SELECT id FROM pfmea_item_option WHERE process_id=? AND item_name=? LIMIT 1");
    $st->execute([$processId, $itemName]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_item_option WHERE process_id=?");
    $st->execute([$processId]);
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_item_option (process_id, item_name, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?)");
    $st->execute([$processId, $itemName, $sort, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_ref_item_option_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_item_option SET is_active=0 WHERE id=?")->execute([$id]);
}

function pfmea_ref_function_options(PDO $db, int $itemOptionId): array {
    $st = $db->prepare("SELECT id, function_desc FROM pfmea_function_option WHERE item_option_id=? AND is_active=1 ORDER BY sort_order, id");
    $st->execute([$itemOptionId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pfmea_ref_function_option_get_or_add(PDO $db, int $itemOptionId, string $functionDesc, int $uid, string $uname): int {
    $functionDesc = trim($functionDesc);
    $st = $db->prepare("SELECT id FROM pfmea_function_option WHERE item_option_id=? AND function_desc=? LIMIT 1");
    $st->execute([$itemOptionId, $functionDesc]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_function_option WHERE item_option_id=?");
    $st->execute([$itemOptionId]);
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_function_option (item_option_id, function_desc, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?)");
    $st->execute([$itemOptionId, $functionDesc, $sort, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_ref_function_option_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_function_option SET is_active=0 WHERE id=?")->execute([$id]);
}

/** 要求清單：優先給這個料號在「功能」層級綁定過的專屬要求，沒有才退回該功能通用要求，還是沒有
 * 才退回「製程」層級(較粗，如製作表單.xlsm匯入的舊資料只到製程沒有功能細分)——同樣先試料號專屬
 * 再試通用。$processId 由呼叫端傳目前卡片解析出的製程id，沒有就傳0（略過製程層級查詢）。 */
function pfmea_ref_requirement_options(PDO $db, int $functionOptionId, int $partDId = 0, string $partText = '', int $processId = 0): array {
    if ($functionOptionId) {
        if ($partDId || $partText !== '') {
            $st = $db->prepare("SELECT id, requirement_text FROM pfmea_requirement_option
                                 WHERE function_option_id=? AND is_active=1 AND ((part_d_id=? AND part_d_id IS NOT NULL) OR (part_no_text=? AND part_no_text IS NOT NULL AND part_no_text<>''))
                                 ORDER BY sort_order, id");
            $st->execute([$functionOptionId, $partDId ?: 0, $partText]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) return $rows;
        }
        $st = $db->prepare("SELECT id, requirement_text FROM pfmea_requirement_option WHERE function_option_id=? AND part_d_id IS NULL AND (part_no_text IS NULL OR part_no_text='') AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$functionOptionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) return $rows;
    }
    if ($processId) {
        if ($partDId || $partText !== '') {
            $st = $db->prepare("SELECT id, requirement_text FROM pfmea_requirement_option
                                 WHERE process_id=? AND function_option_id IS NULL AND is_active=1 AND ((part_d_id=? AND part_d_id IS NOT NULL) OR (part_no_text=? AND part_no_text IS NOT NULL AND part_no_text<>''))
                                 ORDER BY sort_order, id");
            $st->execute([$processId, $partDId ?: 0, $partText]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) return $rows;
        }
        $st = $db->prepare("SELECT id, requirement_text FROM pfmea_requirement_option WHERE process_id=? AND function_option_id IS NULL AND part_d_id IS NULL AND (part_no_text IS NULL OR part_no_text='') AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$processId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return [];
}

function pfmea_ref_requirement_option_add(PDO $db, int $functionOptionId, int $partDId, string $partText, string $text, int $uid, string $uname, int $processId = 0): int {
    $text = trim($text);
    $partDId = $partDId ?: null; $partText = $partText !== '' ? $partText : null;
    $partCond = $partDId ? "part_d_id=?" : ($partText ? "part_no_text=?" : "part_d_id IS NULL AND (part_no_text IS NULL OR part_no_text='')");
    $partKey = $partDId ?: $partText;
    $scopeCond = $functionOptionId ? "function_option_id=?" : "process_id=? AND function_option_id IS NULL";
    $scopeKey = $functionOptionId ?: $processId;
    $sql = "SELECT id FROM pfmea_requirement_option WHERE $scopeCond AND $partCond AND requirement_text=? LIMIT 1";
    $params = [$scopeKey]; if ($partKey !== null) $params[] = $partKey; $params[] = $text;
    $st = $db->prepare($sql);
    $st->execute($params);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_requirement_option WHERE $scopeCond");
    $st->execute([$scopeKey]);
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_requirement_option (function_option_id, process_id, part_d_id, part_no_text, requirement_text, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([$functionOptionId ?: null, $functionOptionId ? null : ($processId ?: null), $partDId, $partText, $text, $sort, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_ref_requirement_option_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_requirement_option SET is_active=0 WHERE id=?")->execute([$id]);
}

function pfmea_ref_control_options(PDO $db): array {
    $rows = $db->query("SELECT id, option_type, option_text FROM pfmea_control_option WHERE is_active=1 ORDER BY option_type, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $out = ['prevention'=>[], 'detection'=>[]];
    foreach ($rows as $r) { $out[$r['option_type']][] = $r; }
    return $out;
}

function pfmea_ref_control_option_add(PDO $db, string $type, string $text, int $uid, string $uname): int {
    $type = $type === 'detection' ? 'detection' : 'prevention';
    $text = trim($text);
    $st = $db->prepare("SELECT id FROM pfmea_control_option WHERE option_type=? AND option_text=? LIMIT 1");
    $st->execute([$type, $text]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_control_option WHERE option_type=?");
    $st->execute([$type]);
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_control_option (option_type, option_text, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?)");
    $st->execute([$type, $text, $sort, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_ref_control_option_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_control_option SET is_active=0 WHERE id=?")->execute([$id]);
}

/** 此製程的整組樣板清單（組名＝製程名稱_潛在失效模式，供「整組列表」按鈕點選套用） */
function pfmea_ref_item_templates(PDO $db, int $processId): array {
    $st = $db->prepare("SELECT t.*, p.process_name FROM pfmea_item_template t
                         JOIN pfmea_process p ON p.id=t.process_id
                         WHERE t.process_id=? AND t.is_active=1 ORDER BY t.sort_order, t.id");
    $st->execute([$processId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // 組名＝製程中文名稱_項目名稱（使用者明確指定的命名規則；item_name 才是原始樣板裡的「項目」
    // 欄位值，跟 failure_mode「潛在失效模式」欄位是兩件事，不能混用）
    foreach ($rows as &$r) { $r['group_name'] = $r['process_name'].'_'.($r['item_name'] ?: $r['failure_mode']); }
    unset($r);
    return $rows;
}

function pfmea_ref_item_template_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_item_template SET is_active=0 WHERE id=?")->execute([$id]);
}

/* ---------- 欄位個別設定對應（2026-08-14使用者要求）----------
 * 通用機制：任一欄位值(source)可設定對應到另一欄位的建議值(target)，如潛在失效模式->失效模式潛在
 * 後果/分類/失效潛在原因、產品名稱->規格描述。可填表人新增(自行輸入新值即註冊)，僅管理員可刪除。 */
function pfmea_field_link_list(PDO $db, string $sourceField, string $sourceValue, string $targetField): array {
    if (trim($sourceValue) === '') return [];
    $st = $db->prepare("SELECT id, target_value FROM pfmea_field_link
                         WHERE source_field=? AND source_value=? AND target_field=? AND is_active=1 ORDER BY sort_order, id");
    $st->execute([$sourceField, trim($sourceValue), $targetField]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pfmea_field_link_add(PDO $db, string $sourceField, string $sourceValue, string $targetField, string $targetValue, int $uid, string $uname): int {
    $sourceValue = trim($sourceValue); $targetValue = trim($targetValue);
    if ($sourceValue === '' || $targetValue === '') return 0;
    $st = $db->prepare("SELECT id FROM pfmea_field_link WHERE source_field=? AND source_value=? AND target_field=? AND target_value=? LIMIT 1");
    $st->execute([$sourceField, $sourceValue, $targetField, $targetValue]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_field_link WHERE source_field=? AND source_value=? AND target_field=?");
    $st->execute([$sourceField, $sourceValue, $targetField]);
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_field_link (source_field, source_value, target_field, target_value, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$sourceField, $sourceValue, $targetField, $targetValue, $sort, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_field_link_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_field_link SET is_active=0 WHERE id=?")->execute([$id]);
}

/* ---------- 評價S/O/D建議規則（2026-08-14使用者要求第7段）----------
 * 依「製程+項目+功能+潛在失效模式+失效模式潛在效果+嚴重度+失效潛在原因」完整組合查建議評價值，
 * 只在新增列時自動帶入、存檔後鎖定不回頭覆蓋(前端控管，這裡只負責查/存)。 */
function pfmea_rating_rule_lookup(PDO $db, int $processId, int $itemOptId, int $funcOptId, string $failureMode, string $failureEffect, int $severity, string $failureCause): ?array {
    $st = $db->prepare("SELECT id, new_severity, new_occurrence, new_detection FROM pfmea_rating_rule
        WHERE process_id=? AND (item_option_id<=>?) AND (function_option_id<=>?) AND failure_mode=? AND failure_effect=? AND severity=? AND failure_cause=? AND is_active=1
        ORDER BY id DESC LIMIT 1");
    $st->execute([$processId, $itemOptId ?: null, $funcOptId ?: null, $failureMode, $failureEffect, $severity, $failureCause]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 評價S/O/D須落在1~10(評級對照表整體有效範圍)才允許存成規則；同組合已有規則就不重複建立
 *（第一次出現的組合優先，避免同組合多筆互相覆蓋、規則來源不確定） */
function pfmea_rating_rule_add(PDO $db, int $processId, int $itemOptId, int $funcOptId, string $failureMode, string $failureEffect, int $severity, string $failureCause, int $ns, int $no, int $nd, int $uid, string $uname): int {
    foreach ([$ns, $no, $nd] as $v) { if ($v < 1 || $v > 10) return 0; }
    $st = $db->prepare("SELECT id FROM pfmea_rating_rule WHERE process_id=? AND (item_option_id<=>?) AND (function_option_id<=>?) AND failure_mode=? AND failure_effect=? AND severity=? AND failure_cause=? AND is_active=1 LIMIT 1");
    $st->execute([$processId, $itemOptId ?: null, $funcOptId ?: null, $failureMode, $failureEffect, $severity, $failureCause]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("INSERT INTO pfmea_rating_rule (process_id, item_option_id, function_option_id, failure_mode, failure_effect, severity, failure_cause, new_severity, new_occurrence, new_detection, created_by, created_by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$processId, $itemOptId ?: null, $funcOptId ?: null, $failureMode, $failureEffect, $severity, $failureCause, $ns, $no, $nd, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_rating_rule_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_rating_rule SET is_active=0 WHERE id=?")->execute([$id]);
}
