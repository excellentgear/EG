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

/** 要求清單：優先給這個料號綁定過的專屬要求，沒有才退回該功能底下沒綁料號的通用要求 */
function pfmea_ref_requirement_options(PDO $db, int $functionOptionId, int $partDId = 0, string $partText = ''): array {
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
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pfmea_ref_requirement_option_add(PDO $db, int $functionOptionId, int $partDId, string $partText, string $text, int $uid, string $uname): int {
    $text = trim($text);
    $partDId = $partDId ?: null; $partText = $partText !== '' ? $partText : null;
    $cond = $partDId ? "part_d_id=?" : ($partText ? "part_no_text=?" : "part_d_id IS NULL AND (part_no_text IS NULL OR part_no_text='')");
    $key = $partDId ?: $partText;
    $sql = "SELECT id FROM pfmea_requirement_option WHERE function_option_id=? AND $cond AND requirement_text=? LIMIT 1";
    $params = $key !== null ? [$functionOptionId, $key, $text] : [$functionOptionId, $text];
    $st = $db->prepare($sql);
    $st->execute($params);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_requirement_option WHERE function_option_id=?");
    $st->execute([$functionOptionId]);
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_requirement_option (function_option_id, part_d_id, part_no_text, requirement_text, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$functionOptionId, $partDId, $partText, $text, $sort, $uid, $uname]);
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
