<?php
/**
 * PFMEA 參考資料庫（製程代號／潛在失效模式／控制預防控制偵測選項／製程整組樣板）共用庫。
 * 資料來源：3-TD-01-02-潛在失效模式及效應分析.xlsm 的「資料庫」「項目異常」工作表（2026-08-13 匯入）。
 * 使用者明確要求：這些清單可填表人(canEdit)就能新增／自行輸入新值，但僅管理員(canAdmin)可以刪除。
 * 資料表定義見 pfmea_lib.php ensure_schema()。
 */

/** 「料號＋製程代號」複合鍵的分隔字元，需與 views/TD/pfmea.php 前端的 PART_PROCESS_SEP 完全一致 */
if (!defined('PFMEA_PART_PROCESS_SEP')) define('PFMEA_PART_PROCESS_SEP', '｜');

/** 填表用：只回傳管理員已開放使用(is_enabled=1)的製程，避免全公司205筆製程一次全部塞進下拉選單 */
/** 幾何公差／特殊項目符號清單（2026-08-14使用者要求，設定畫面「對應的目標值」輸入框符號按鈕用）：
 * 直接沿用QC模組既有的 qc_special_characteristic 字典表(views/QC/inspection_standard_setting.php
 * 管理)，不重複建一份——這個表本來就是全站共用的幾何公差/特殊檢驗項目字典，PFMEA只讀取不寫入。 */
function pfmea_qc_special_characteristics(PDO $db): array {
    return $db->query("SELECT characteristic_id, name, symbol, description FROM qc_special_characteristic WHERE is_active=1 ORDER BY characteristic_id")->fetchAll(PDO::FETCH_ASSOC);
}

function pfmea_ref_process_list(PDO $db): array {
    return $db->query("SELECT id, process_code, process_name, category_name FROM pfmea_process WHERE is_active=1 AND is_enabled=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- 製程主檔同步（2026-08-14使用者要求）----------
 * 製程代號改從全站製程主檔(process_no/process_type)同步帶入，不再只靠xlsm匯入；同步進來的每一筆
 * 預設不開放使用(is_enabled=0)，管理員在參考資料設定畫面逐一或整個大項分類批次開放。
 * 已存在的pfmea_process.process_code(含舊xlsm匯入的)只補上master_process_no_id/分類關聯，
 * 不覆蓋process_name——避免代號剛好相同但實際意義不同時(如公司製程主檔後來改過用途)靜默改名，
 * 造成已經用該代號填過的分析表看起來對不上；名稱要不要改由管理員自行到設定畫面手動確認調整。 */
function pfmea_process_sync_from_master(PDO $db, int $uid, string $uname): array {
    $rows = $db->query("SELECT pn.ProcessNo, pn.ProcessName, pn.process_type_id, pt.process_type
                         FROM process_no pn LEFT JOIN process_type pt ON pt.process_type_id=pn.process_type_id
                         WHERE pn.ProcessName IS NOT NULL AND pn.ProcessName<>''")->fetchAll(PDO::FETCH_ASSOC);
    $created = 0; $linked = 0;
    foreach ($rows as $r) {
        $code = (string)$r['ProcessNo'];
        $st = $db->prepare("SELECT id, master_process_no_id FROM pfmea_process WHERE process_code=? LIMIT 1");
        $st->execute([$code]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (!$existing['master_process_no_id']) {
                $db->prepare("UPDATE pfmea_process SET master_process_no_id=?, master_type_id=?, category_name=? WHERE id=?")
                   ->execute([(int)$r['ProcessNo'], $r['process_type_id'] ?: null, $r['process_type'] ?: null, $existing['id']]);
                $linked++;
            }
            continue;
        }
        $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_process");
        $st->execute();
        $sort = (int)$st->fetchColumn();
        $db->prepare("INSERT INTO pfmea_process (process_code, process_name, master_process_no_id, master_type_id, category_name, sort_order, is_enabled, created_by, created_by_name)
                      VALUES (?,?,?,?,?,?,0,?,?)")
           ->execute([$code, $r['ProcessName'], (int)$r['ProcessNo'], $r['process_type_id'] ?: null, $r['process_type'] ?: null, $sort, $uid, $uname]);
        $created++;
    }
    return ['created'=>$created, 'linked'=>$linked, 'total_master'=>count($rows)];
}

/** 參考資料設定畫面用：全部製程(不篩is_enabled)，含大項分類+已設定幾個潛在失效模式(製程層級)，
 * 供批次開放/個別勾選介面；筆數提醒使用者這個製程已經有人設定過內容，理應開放使用 */
function pfmea_process_list_all(PDO $db): array {
    return $db->query("SELECT p.id, p.process_code, p.process_name, p.master_type_id, p.category_name, p.is_enabled,
                               (SELECT COUNT(*) FROM pfmea_process_failure_mode f WHERE f.process_id=p.id AND f.item_option_id IS NULL AND f.function_option_id IS NULL AND f.is_active=1) AS fm_count
                        FROM pfmea_process p WHERE p.is_active=1 ORDER BY (p.category_name IS NULL), p.category_name, p.sort_order, p.id")->fetchAll(PDO::FETCH_ASSOC);
}

/** 把已經有配置「潛在失效模式」的製程一併開放使用（2026-08-14使用者要求：有設定內容的製程理應
 * 開放，不該還要管理員逐一手動勾選） */
function pfmea_process_enable_configured(PDO $db): int {
    $st = $db->exec("UPDATE pfmea_process p SET is_enabled=1
                      WHERE is_enabled=0 AND EXISTS(SELECT 1 FROM pfmea_process_failure_mode f WHERE f.process_id=p.id AND f.is_active=1)");
    return (int)$st;
}

function pfmea_process_set_enabled(PDO $db, array $ids, bool $enabled): void {
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE pfmea_process SET is_enabled=? WHERE id IN ($in)")->execute(array_merge([$enabled?1:0], $ids));
}

function pfmea_process_set_enabled_by_type(PDO $db, int $masterTypeId, bool $enabled): void {
    $db->prepare("UPDATE pfmea_process SET is_enabled=? WHERE master_type_id=?")->execute([$enabled?1:0, $masterTypeId]);
}

/** 管理員手動改製程名稱(用於同代號但主檔名稱不同的情況，如何處理由管理員自行確認) */
function pfmea_process_rename(PDO $db, int $id, string $name): void {
    $name = trim($name);
    if ($name === '') return;
    $db->prepare("UPDATE pfmea_process SET process_name=? WHERE id=?")->execute([$name, $id]);
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

/** 參考資料設定畫面用：只回傳「剛好存在這個確切層級」的資料，不像 pfmea_ref_failure_mode_list()
 * 會逐層退回帶入下拉——管理畫面要讓使用者清楚知道自己正在編輯哪一層，不能被退回結果混淆。 */
function pfmea_ref_failure_mode_list_exact(PDO $db, int $processId, int $itemOptionId, int $functionOptionId): array {
    if ($functionOptionId) {
        $st = $db->prepare("SELECT id, failure_mode FROM pfmea_process_failure_mode WHERE function_option_id=? AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$functionOptionId]);
    } elseif ($itemOptionId) {
        $st = $db->prepare("SELECT id, failure_mode FROM pfmea_process_failure_mode WHERE item_option_id=? AND function_option_id IS NULL AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$itemOptionId]);
    } else {
        $st = $db->prepare("SELECT id, failure_mode FROM pfmea_process_failure_mode WHERE process_id=? AND item_option_id IS NULL AND function_option_id IS NULL AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$processId]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
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

/** 參考資料設定畫面用：只回傳「剛好存在這個確切層級」的資料(含料號綁定資訊供顯示)，理由同
 * pfmea_ref_failure_mode_list_exact()。 */
function pfmea_ref_requirement_list_exact(PDO $db, int $functionOptionId, int $processId): array {
    if ($functionOptionId) {
        $st = $db->prepare("SELECT id, requirement_text, part_d_id, part_no_text FROM pfmea_requirement_option WHERE function_option_id=? AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$functionOptionId]);
    } else {
        $st = $db->prepare("SELECT id, requirement_text, part_d_id, part_no_text FROM pfmea_requirement_option WHERE process_id=? AND function_option_id IS NULL AND is_active=1 ORDER BY sort_order, id");
        $st->execute([$processId]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        if ($r['part_d_id']) {
            $p = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?"); $p->execute([$r['part_d_id']]);
            $r['part_label'] = $p->fetchColumn() ?: ('#'.$r['part_d_id']);
        } else { $r['part_label'] = $r['part_no_text'] ?: ''; }
    }
    unset($r);
    return $rows;
}

/** 要求資料總覽（2026-08-14使用者要求）：先前只能逐一選製程才看得到該製程的要求清單，匯入
 * 製作表單.xlsm的112筆資料散落在5個製程底下要一個個點才看得完；改成一次列出全部要求(不分
 * 製程/功能層級)，含每筆的料號綁定狀態，供快速檢視/重新綁定/刪除。 */
function pfmea_requirement_option_list_all(PDO $db): array {
    $rows = $db->query("SELECT r.id, r.requirement_text, r.part_d_id, r.part_no_text, r.function_option_id,
                                p.process_code, p.process_name, f.function_desc
                         FROM pfmea_requirement_option r
                         LEFT JOIN pfmea_process p ON p.id=r.process_id
                         LEFT JOIN pfmea_function_option f ON f.id=r.function_option_id
                         WHERE r.is_active=1
                         ORDER BY (p.sort_order IS NULL), p.sort_order, p.id, r.sort_order, r.id")->fetchAll(PDO::FETCH_ASSOC);
    $partIds = array_values(array_unique(array_filter(array_column($rows, 'part_d_id'))));
    $partLabels = [];
    if ($partIds) {
        $in = implode(',', array_fill(0, count($partIds), '?'));
        $st = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id IN ($in)");
        $st->execute($partIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) { $partLabels[$p['d_id']] = $p['D_Setting_Id']; }
    }
    foreach ($rows as &$r) {
        $r['scope_label'] = $r['function_desc'] ? '功能：'.$r['function_desc'] : (($r['process_code']||$r['process_name']) ? '製程：'.$r['process_code'].' '.$r['process_name'] : '（未知）');
        if ($r['part_d_id']) { $r['part_label'] = $partLabels[$r['part_d_id']] ?? ('#'.$r['part_d_id']); $r['bound'] = true; }
        elseif ($r['part_no_text']) { $r['part_label'] = $r['part_no_text']; $r['bound'] = false; }
        else { $r['part_label'] = '（通用，不限料號）'; $r['bound'] = null; }
    }
    unset($r);
    return $rows;
}

/** 重新綁定既有要求列的料號（2026-08-14使用者要求）：製作表單.xlsm匯入時46筆比對不到現存
 * d_setting主鍵、只存成文字料號，管理員可在此重新綁定到正確的料號ID */
function pfmea_requirement_option_rebind(PDO $db, int $id, int $partDId, string $partText): void {
    $partText = trim($partText);
    $db->prepare("UPDATE pfmea_requirement_option SET part_d_id=?, part_no_text=? WHERE id=?")
       ->execute([$partDId ?: null, $partDId ? null : ($partText !== '' ? $partText : null), $id]);
}

/** 要求總覽可編輯要求文字本身（2026-08-14使用者反映：先前只能重新綁定料號／刪除，完全無法改
 * 文字本身內容，如xlsm匯入時OCR/謄打有誤要能直接修正）。 */
function pfmea_requirement_option_update_text(PDO $db, int $id, string $text): void {
    $text = trim($text);
    if ($text === '') return;
    $db->prepare("UPDATE pfmea_requirement_option SET requirement_text=? WHERE id=?")->execute([$text, $id]);
}

function pfmea_ref_control_options(PDO $db): array {
    $rows = $db->query("SELECT id, option_type, option_text FROM pfmea_control_option WHERE is_active=1 ORDER BY option_type, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $out = ['prevention'=>[], 'detection'=>[], 'action'=>[]];
    foreach ($rows as $r) { $out[$r['option_type']][] = $r; }
    return $out;
}

/** option_type沿用同一張表同一套CRUD：prevention/detection(既有控制預防/控制偵測)、
 * action(2026-08-14使用者要求新增的建議措施樣板句庫) */
function pfmea_ref_control_option_add(PDO $db, string $type, string $text, int $uid, string $uname): int {
    $type = in_array($type, ['detection','action'], true) ? $type : 'prevention';
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

/** 整組樣板新增/編輯（2026-08-14使用者要求：整組設定要能新增+編輯+刪除，不再只能查看/刪除）。
 * $id=0 新增，非0 編輯既有列；$data 用欄位名為鍵，缺的欄位存NULL。 */
function pfmea_ref_item_template_save(PDO $db, int $id, int $processId, array $data, int $uid, string $uname): int {
    $textFields = ['item_name','failure_mode','function_desc','failure_effect','failure_cause','prevention_controls','detection_controls','recommended_actions'];
    $numFields = ['severity','occurrence','detection','new_severity','new_occurrence','new_detection'];
    $fields = array_merge(
        ['item_name','failure_mode','function_desc','failure_effect'],
        ['severity'],
        ['failure_cause'],
        ['occurrence','prevention_controls','detection_controls','detection','recommended_actions'],
        ['new_severity','new_occurrence','new_detection']
    );
    $vals = [];
    foreach ($fields as $f) {
        $v = $data[$f] ?? null;
        if (in_array($f, $numFields, true)) {
            $v = ($v === null || $v === '') ? null : max(1, min(10, (int)$v));
        } else {
            $v = $v !== null ? trim((string)$v) : '';
            if ($v === '') $v = null;
        }
        $vals[] = $v;
    }
    $st = $db->prepare("SELECT process_name FROM pfmea_process WHERE id=?"); $st->execute([$processId]);
    $procName = (string)($st->fetchColumn() ?: '');
    $templateKey = $procName . '_' . ($vals[0] ?: ($vals[1] ?: ''));
    if ($id) {
        $db->prepare("UPDATE pfmea_item_template SET process_id=?, template_key=?, item_name=?, failure_mode=?, function_desc=?, failure_effect=?,
            severity=?, failure_cause=?, occurrence=?, prevention_controls=?, detection_controls=?, detection=?, recommended_actions=?,
            new_severity=?, new_occurrence=?, new_detection=? WHERE id=?")
           ->execute(array_merge([$processId, $templateKey], $vals, [$id]));
        return $id;
    }
    $st = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pfmea_item_template WHERE process_id=?");
    $st->execute([$processId]);
    $sort = (int)$st->fetchColumn();
    $db->prepare("INSERT INTO pfmea_item_template (process_id, template_key, item_name, failure_mode, function_desc, failure_effect,
        severity, failure_cause, occurrence, prevention_controls, detection_controls, detection, recommended_actions,
        new_severity, new_occurrence, new_detection, sort_order, created_by, created_by_name)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute(array_merge([$processId, $templateKey], $vals, [$sort, $uid, $uname]));
    return (int)$db->lastInsertId();
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

/** 參考資料設定畫面用：某組(source_field,target_field)已經設定過的所有來源值清單，供瀏覽/鑽入
 * 查看該來源值對應的目標值（一般填表流程只查已知source_value，管理畫面要能瀏覽全部才找得到）。
 * 附上preview(已設定的目標值預覽，逗號分隔)，讓清單不用點進去就能大概看到已經設定了什麼
 * （2026-08-14使用者要求：料號+製程代號 右側要能直接看到對應的規格描述內容）。 */
function pfmea_field_link_distinct_sources(PDO $db, string $sourceField, string $targetField): array {
    $st = $db->prepare("SELECT source_value AS value, GROUP_CONCAT(target_value ORDER BY sort_order, id SEPARATOR '、') AS preview
                         FROM pfmea_field_link WHERE source_field=? AND target_field=? AND is_active=1
                         GROUP BY source_value ORDER BY source_value");
    $st->execute([$sourceField, $targetField]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($sourceField !== 'part_process') return $rows;
    // 「料號＋製程代號」同時也是「要求」(pfmea_requirement_option)天然的主鍵，使用者明確指出
    // 兩者本來就是同一份資料（同一個料號+製程底下，圖面要求跟要求是同一件事的兩個欄位），這裡
    // 合併兩邊已設定的組合：只要該料號+製程在「要求」有資料，即使還沒設定過圖面要求(field_link)
    // 也要能在這個清單看到並可以點進去補設定，preview一併附上要求內容方便對照
    // （2026-08-14使用者要求：篩選料號時兩邊要顯示同一份資料、有綁定料號要同時列在這個清單內）。
    $byValue = [];
    foreach ($rows as $r) { $byValue[$r['value']] = $r; }
    $reqRows = $db->query("SELECT r.requirement_text, r.part_d_id, r.part_no_text, p.process_code
                            FROM pfmea_requirement_option r JOIN pfmea_process p ON p.id=r.process_id
                            WHERE r.function_option_id IS NULL AND r.is_active=1
                              AND (r.part_d_id IS NOT NULL OR (r.part_no_text IS NOT NULL AND r.part_no_text<>''))")
                  ->fetchAll(PDO::FETCH_ASSOC);
    if ($reqRows) {
        $partIds = array_values(array_unique(array_filter(array_column($reqRows, 'part_d_id'))));
        $partLabels = [];
        if ($partIds) {
            $in = implode(',', array_fill(0, count($partIds), '?'));
            $ps = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id IN ($in)");
            $ps->execute($partIds);
            foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) { $partLabels[$p['d_id']] = $p['D_Setting_Id']; }
        }
        $reqTextsByCombo = [];
        foreach ($reqRows as $r) {
            $partStr = $r['part_d_id'] ? ($partLabels[$r['part_d_id']] ?? ('#'.$r['part_d_id'])) : $r['part_no_text'];
            if ($partStr === null || $partStr === '') continue;
            $combo = $partStr . PFMEA_PART_PROCESS_SEP . $r['process_code'];
            $reqTextsByCombo[$combo][] = $r['requirement_text'];
        }
        foreach ($reqTextsByCombo as $combo => $texts) {
            $reqPreview = '要求：'.implode('、', array_unique($texts));
            if (isset($byValue[$combo])) {
                $byValue[$combo]['preview'] = ($byValue[$combo]['preview'] !== '' ? $byValue[$combo]['preview'].'／' : '').$reqPreview;
            } else {
                $byValue[$combo] = ['value'=>$combo, 'preview'=>$reqPreview];
            }
        }
    }
    ksort($byValue, SORT_STRING | SORT_FLAG_CASE);
    return array_values($byValue);
}

/** 設定畫面「潛在失效模式」來源值候選清單：只看已經設定過對應值的來源會漏掉「已經在製程底下建立，
 * 但還沒設定過任何後果/原因對應」的失效模式，改成系統全部已知的潛在失效模式(不分製程)都能選來
 * 開始設定，不必先繞去別的地方新增一筆對應才看得到（2026-08-13使用者要求）；同樣附上針對目前
 * $targetField 的目標值預覽。 */
function pfmea_field_link_all_failure_modes(PDO $db, string $targetField = 'failure_effect'): array {
    $a = $db->query("SELECT DISTINCT failure_mode FROM pfmea_process_failure_mode WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    $b = $db->query("SELECT DISTINCT source_value FROM pfmea_field_link WHERE source_field='failure_mode' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    $all = array_values(array_unique(array_merge($a, $b)));
    sort($all, SORT_STRING|SORT_FLAG_CASE);
    $st = $db->prepare("SELECT target_value FROM pfmea_field_link WHERE source_field='failure_mode' AND source_value=? AND target_field=? AND is_active=1 ORDER BY sort_order, id");
    $out = [];
    foreach ($all as $v) {
        $st->execute([$v, $targetField]);
        $out[] = ['value'=>$v, 'preview'=>implode('、', $st->fetchAll(PDO::FETCH_COLUMN))];
    }
    return $out;
}

/** 從已匯入的整組樣板(pfmea_item_template，來源3-TD-01-02...xlsm的「項目異常」工作表)回填欄位
 * 個別設定對應：失效模式->失效模式潛在後果、失效模式->失效潛在原因，兩者才是真正同時存在
 * 「潛在失效模式」與「後果/原因」欄位的原始資料（「資料庫」工作表只有單一潛在失效模式清單，
 * 沒有後果欄位，2026-08-14使用者一開始誤會了來源，查證後改用這份）。 */
function pfmea_field_link_backfill_from_templates(PDO $db, int $uid, string $uname): array {
    $rows = $db->query("SELECT DISTINCT failure_mode, failure_effect, failure_cause FROM pfmea_item_template
                         WHERE failure_mode IS NOT NULL AND failure_mode<>'' AND is_active=1")->fetchAll(PDO::FETCH_ASSOC);
    $created = 0;
    foreach ($rows as $r) {
        if (!empty($r['failure_effect'])) { pfmea_field_link_add($db, 'failure_mode', $r['failure_mode'], 'failure_effect', $r['failure_effect'], $uid, $uname); $created++; }
        if (!empty($r['failure_cause'])) { pfmea_field_link_add($db, 'failure_mode', $r['failure_mode'], 'failure_cause', $r['failure_cause'], $uid, $uname); $created++; }
    }
    return ['processed'=>$created];
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
