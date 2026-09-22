<?php
/**
 * gear_save_lib.php —— 存齒輪資料時，不要把「這張表單沒管到的欄位」一起洗掉（唯一實作）
 *
 * 背景（2026-09-22）：站上有 5 支頁面可以編輯料號的齒輪資料，寫法都是
 *     DELETE FROM d_setting_gear WHERE d_setting_id=?   →   再逐列 INSERT
 * 但那幾支的 INSERT 欄位清單只有自己畫面上有的那十幾欄，**整張表 34 欄裡剩下的
 * 一律被 DELETE 掉之後補不回來**，而且完全不報錯。實際會不見的東西：
 *   ⑴ `module_input_type` / `module_display`——徑節(DP)、周節(CP) 的標記。
 *      一旦不見，那支料號就永久變成公制模數 M，全站顯示一起跟著錯；
 *   ⑵ `spec_chain_size` / `spec_pitch` / `spec_roller_dia` / `spec_starts`（鏈輪、蝸桿）；
 *   ⑶ `spec_pulley_profile` / `spec_pld`（皮帶輪）；
 *   ⑷ `spec_spline_*`（花鍵的大徑小徑鍵寬與標準）；
 *   ⑸ `gear_quality_std` / `gear_quality_grade`（齒輪等級）。
 * 只有主檔管理那一支的 INSERT 是 29 欄完整的，其餘 5 支都會造成上述靜默資料遺失。
 *
 * 用法（包住原本的 DELETE + INSERT，前後各一行）：
 *     $snap = eg_gear_keep_snapshot($pdo, $d_id);          // ← DELETE 之前
 *     ... 原本的 DELETE 與 INSERT 一字不動 ...
 *     eg_gear_keep_restore($pdo, $d_id, $snap, [ INSERT 有寫到的欄位 ]);   // ← INSERT 之後
 *
 * 配對規則（刻意保守，寧可少還原也不要還原錯）：
 *   依 **gear_id 順序的第幾列** 對第幾列，並且分成兩層：
 *   ⑴ **齒型(Gear_Type) 相同** → 還原那些與模數無關的欄位（鏈輪規格、花鍵尺寸、
 *      皮帶輪型號、齒輪等級…）。齒型被改掉（例：鏈輪改成直齒）就不還原，
 *      因為那些 spec_* 本來就只屬於原本那個齒型。
 *   ⑵ 齒型相同 **且 模數(Module) 文字也相同** → 才還原 `module_input_type`／`module_display`。
 *      模數被使用者重打過＝這些表單上沒有 DP/CP 選項，它現在就是公制模數，
 *      不可以硬把舊的 DP 標記蓋回去（會變成畫面寫 DP20、實際卻是模數 3）。
 *   ⑶ 使用者增列／刪列時對不上的那幾列就不還原（行為與改版前相同，不會更糟）。
 */

/** 這幾欄是列身分與稽核欄位，永遠不還原 */
const EG_GEAR_KEEP_SKIP = ['gear_id', 'd_setting_id', 'Created_By', 'Created_At', 'Modified_By', 'Modified_At'];

/**
 * DELETE 之前先把現況整列存下來（含全部欄位）。
 * @return array 依 gear_id 由小到大的列陣列；查不到或出錯一律回空陣列（不可因此讓存檔失敗）
 */
function eg_gear_keep_snapshot(PDO $db, int $dsPk): array
{
    if ($dsPk <= 0) return [];
    try {
        $st = $db->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id = ? ORDER BY gear_id ASC");
        $st->execute([$dsPk]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * INSERT 完之後，把「這張表單沒管到的欄位」從快照補回新的那幾列。
 *
 * @param array $snapshot      eg_gear_keep_snapshot() 的回傳值
 * @param array $managedCols   這支 INSERT 自己有寫入的欄位名（大小寫要與資料表一致）
 * @return int  實際還原了幾列
 */
function eg_gear_keep_restore(PDO $db, int $dsPk, array $snapshot, array $managedCols): int
{
    if ($dsPk <= 0 || !$snapshot) return 0;
    try {
        $st = $db->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id = ? ORDER BY gear_id ASC");
        $st->execute([$dsPk]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return 0;

        // 要補回來的欄位＝整張表的欄位 − 這支自己管的 − 身分/稽核欄位
        $skip = array_change_key_case(array_flip(array_merge($managedCols, EG_GEAR_KEEP_SKIP)), CASE_LOWER);
        $keep = [];
        foreach (array_keys($rows[0]) as $col) {
            if (!isset($skip[strtolower($col)])) $keep[] = $col;
        }
        if (!$keep) return 0;

        $same = function ($a, $b) {
            $a = trim((string)($a ?? ''));
            $b = trim((string)($b ?? ''));
            return strcasecmp($a, $b) === 0;
        };

        // 與模數綁在一起的兩欄要多一道「模數沒被改過」的檢查
        $modBound = ['module_input_type' => 1, 'module_display' => 1];

        $done = 0;
        $stmts = [];   // 依實際要寫的欄位組合快取 prepare
        foreach ($rows as $i => $new) {
            $old = $snapshot[$i] ?? null;
            if (!$old) break;                                        // 新增的列沒有對應的舊列
            if (!$same($old['Gear_Type'] ?? '', $new['Gear_Type'] ?? '')) continue;
            $modSame = $same($old['Module'] ?? '', $new['Module'] ?? '');

            $cols = [];
            $par  = [];
            $any  = false;
            foreach ($keep as $c) {
                if (isset($modBound[strtolower($c)]) && !$modSame) continue;
                $v = $old[$c] ?? null;
                $cols[] = $c;
                $par[]  = $v;
                if ($v !== null && $v !== '') $any = true;
            }
            if (!$cols || !$any) continue;                           // 舊列這些欄位本來就全空，不必下 UPDATE
            $key = implode('|', $cols);
            if (!isset($stmts[$key])) {
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
                $stmts[$key] = $db->prepare("UPDATE d_setting_gear SET $set WHERE gear_id = ?");
            }
            $par[] = $new['gear_id'];
            $stmts[$key]->execute($par);
            $done++;
        }
        return $done;
    } catch (Throwable $e) { return 0; }
}
