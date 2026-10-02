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

/**
 * 「新增料號時必填」的標籤清單（唯一實作，2026-10-02）。
 *
 * 使用者交辦「工件總長要是必填項目」。刻意不把標籤名稱寫死在程式裡，
 * 改成 `dict_label.is_required` 旗標，管理員可在主檔管理的標籤字典設定自行勾選
 * （鐵律4：寫死名稱的話，管理員改個名這條規則就靜靜失效）。
 *
 * **只擋新增、不擋修改既有料號**（使用者拍板）：全庫適用這個標籤的料號有 23,969 支，
 * 目前只有 277 支有值；連修改都擋的話，任何人只是要改客戶或備註都會先被擋在外面。
 *
 * @param string $partType 料號種類（G/J/N/CFG…），只回傳適用於該種類的標籤
 *                         （滾刀 H 不在工件總長的 type_code 裡，自然不會被要求）
 * @return array [['label_id'=>int,'label_name'=>string], ...]
 */
function eg_part_required_labels(PDO $db, string $partType): array {
    $t = trim($partType);
    if ($t === '') return [];
    try {
        $st = $db->prepare("SELECT label_id, label_name FROM dict_label
                             WHERE is_active = 1 AND COALESCE(is_required,0) = 1
                               AND FIND_IN_SET(?, type_code)
                             ORDER BY sort_order, label_id");
        $st->execute([$t]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }   // 欄位還沒建出來時一律視為沒有必填標籤，不要讓存檔壞掉
}

/**
 * 判斷「這一列標籤資料算不算有填」。
 * 數字/文字標籤看 input_value；圖面車床型看 draw_dim；範圍型看 value_min/value_max；
 * 計算差異型看 calc_value——只要任何一種放值的欄位有東西就算有填。
 */
function eg_part_label_row_filled(array $row): bool {
    foreach (['input_value', 'draw_dim', 'lathe_dim', 'value_min', 'value_max', 'calc_value', 'qty'] as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') return true;
    }
    return false;
}

/**
 * 檢查一批要存進去的標籤資料有沒有漏掉必填的，回傳漏掉的標籤名稱（空陣列＝都填了）。
 * @param array $labels 與主檔管理 save_part 的 labels 相同格式：[['label_id'=>..,'input_value'=>..], ...]
 */
function eg_part_required_missing(PDO $db, string $partType, array $labels): array {
    $req = eg_part_required_labels($db, $partType);
    if (!$req) return [];
    $filled = [];
    foreach ($labels as $l) {
        $lid = intval($l['label_id'] ?? 0);
        if ($lid > 0 && eg_part_label_row_filled((array)$l)) $filled[$lid] = true;
    }
    $miss = [];
    foreach ($req as $r) { if (empty($filled[(int)$r['label_id']])) $miss[] = (string)$r['label_name']; }
    return $miss;
}

/**
 * 單一標籤的設定（名稱／適用料號種類／是不是新增時必填），給只放得下一個標籤的畫面用
 * （例：報價單管理「新增料號」跳窗只有「工件總長」一欄）。
 * @return array|null ['id'=>int,'name'=>string,'types'=>['G','J',…],'required'=>bool]
 */
function eg_part_label_meta(PDO $db, int $labelId): ?array {
    if ($labelId <= 0) return null;
    try {
        $st = $db->prepare("SELECT label_id, label_name, type_code, COALESCE(is_required,0) AS is_required
                              FROM dict_label WHERE label_id=? AND is_active=1");
        $st->execute([$labelId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'id'       => (int)$r['label_id'],
            'name'     => (string)$r['label_name'],
            'types'    => array_values(array_filter(array_map('trim', explode(',', (string)$r['type_code'])))),
            'required' => ((int)$r['is_required'] === 1),
        ];
    } catch (Throwable $e) { return null; }
}
