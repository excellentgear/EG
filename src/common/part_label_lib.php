<?php
/**
 * part_label_lib.php —— 讀寫「料號標籤」上的單一數值（全站唯一實作）
 *
 * 背景（2026-10-02）：主檔管理把「軸件›總長」與「片狀›總厚」合併成單一數字標籤
 * 「工件總長」之後，使用者要求報價單管理「新增料號」跳窗裡的工件總長
 * **跟主檔管理寫進同一個地方**（原本那邊是寫 `d_setting_gear.Workpiece_Length`，
 * 主檔管理是寫標籤，兩份各存各的，實測已經有料號兩邊數字對不起來）。
 *
 * 「哪一個標籤是工件總長」不寫死 label_id，也不每次用名稱去比對：
 *   第一次用名稱找到之後**把 id 記進 `system_parameters('PART_LABEL_BIND', key)`**，
 *   之後一律用記下來的 id。這樣管理員把標籤改名也不會突然失聯
 *   （只用名稱比對＝改名當天這個功能就靜靜地壞掉，而且完全不報錯）。
 */

/**
 * 取得某個用途對應的料號標籤 id（找不到時用名稱找一次並記起來）。
 * @param  string $key          用途代碼（例：workpiece_length）
 * @param  string $defaultName  第一次解析時要找的標籤名稱
 * @return int    0＝真的找不到（呼叫端自行決定要不要略過）
 */
function eg_part_label_id(PDO $db, string $key, string $defaultName): int {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $id = 0;
    // 這裡**刻意不下任何 DDL**（system_parameters 是全站早就有的表）：
    // `CREATE TABLE IF NOT EXISTS` 在 MySQL 會造成隱式 commit，而這支會在
    // 報價單存檔的交易中被呼叫，外層 commit() 會直接爆 "There is no active transaction"
    // ——而且資料其實已經寫進去了，畫面卻顯示失敗，是最難查的那種症狀（本專案已踩過三次）。
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='PART_LABEL_BIND' AND param_key=?");
        $st->execute([$key]);
        $id = intval($st->fetchColumn());
        // 記下來的 id 必須還存在且啟用，否則重新用名稱解析一次
        if ($id > 0) {
            $ok = $db->prepare("SELECT COUNT(*) FROM dict_label WHERE label_id=? AND is_active=1");
            $ok->execute([$id]);
            if (!$ok->fetchColumn()) $id = 0;
        }
        if ($id <= 0) {
            $f = $db->prepare("SELECT label_id FROM dict_label WHERE label_name=? AND is_active=1 ORDER BY label_id LIMIT 1");
            $f->execute([$defaultName]);
            $id = intval($f->fetchColumn());
            if ($id > 0) {
                $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value) VALUES ('PART_LABEL_BIND',?,?)
                              ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)")->execute([$key, (string)$id]);
            }
        }
    } catch (Throwable $e) { $id = 0; }
    return $cache[$key] = $id;
}

/** 「工件總長」標籤的 id */
function eg_workpiece_length_label_id(PDO $db): int {
    return eg_part_label_id($db, 'workpiece_length', '工件總長');
}

/** 讀某個料號在某個標籤上填的值（沒掛這個標籤回 null） */
function eg_part_label_value(PDO $db, int $dId, int $labelId): ?string {
    if ($dId <= 0 || $labelId <= 0) return null;
    try {
        $st = $db->prepare("SELECT input_value FROM item_label_map WHERE d_id=? AND label_id=? ORDER BY map_id LIMIT 1");
        $st->execute([$dId, $labelId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $r['input_value'] : null;
    } catch (Throwable $e) { return null; }
}

/**
 * 寫某個料號在某個標籤上的值。
 * 傳 null 或空字串＝把這個標籤從料號上拿掉（數字標籤沒有數字就沒有意義）。
 * 只動這一個標籤，料號上其他標籤一律不碰。
 */
function eg_part_label_set(PDO $db, int $dId, int $labelId, ?string $value): bool {
    if ($dId <= 0 || $labelId <= 0) return false;
    $v = trim((string)($value ?? ''));
    try {
        $st = $db->prepare("SELECT map_id FROM item_label_map WHERE d_id=? AND label_id=? ORDER BY map_id LIMIT 1");
        $st->execute([$dId, $labelId]);
        $mapId = intval($st->fetchColumn());
        if ($v === '') {
            if ($mapId > 0) {
                $db->prepare("DELETE FROM item_sub_label_map WHERE parent_map_id=?")->execute([$mapId]);
                $db->prepare("DELETE FROM item_label_map WHERE map_id=?")->execute([$mapId]);
            }
            return true;
        }
        if ($mapId > 0) $db->prepare("UPDATE item_label_map SET input_value=? WHERE map_id=?")->execute([$v, $mapId]);
        else            $db->prepare("INSERT INTO item_label_map (d_id,label_id,input_value) VALUES (?,?,?)")->execute([$dId, $labelId, $v]);
        return true;
    } catch (Throwable $e) { return false; }
}
