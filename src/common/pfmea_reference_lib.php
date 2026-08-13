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

function pfmea_ref_failure_mode_list(PDO $db, int $processId): array {
    $st = $db->prepare("SELECT id, failure_mode FROM pfmea_process_failure_mode WHERE process_id=? AND is_active=1 ORDER BY sort_order, id");
    $st->execute([$processId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pfmea_ref_failure_mode_add(PDO $db, int $processId, string $text, int $uid, string $uname): int {
    $text = trim($text);
    $st = $db->prepare("SELECT id FROM pfmea_process_failure_mode WHERE process_id=? AND failure_mode=? LIMIT 1");
    $st->execute([$processId, $text]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_process_failure_mode WHERE process_id=?");
    $st->execute([$processId]);
    $sort = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_process_failure_mode (process_id, failure_mode, sort_order, created_by, created_by_name) VALUES (?,?,?,?,?)");
    $st->execute([$processId, $text, $sort, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function pfmea_ref_failure_mode_delete(PDO $db, int $id): void {
    $db->prepare("UPDATE pfmea_process_failure_mode SET is_active=0 WHERE id=?")->execute([$id]);
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
