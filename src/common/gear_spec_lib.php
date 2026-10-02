<?php
/**
 * 齒輪規格顯示字串（比照 views/Sales/NewOrder_Track.php 料號下方顯示的「齒輪規格」邏輯，
 * 2026-08-13 供 PFMEA「規格描述」自動判斷帶入用而獨立抽出單一料號版本）。
 * 刻意不修改 NewOrder_Track.php 改呼叫共用函式——那是一支大型既有正常運作頁面，不做非必要重構，
 * 這裡逐字複製同一套 SQL/樣板替換邏輯；未來若規格樣板規則異動，兩處需一併更新。
 * 查無任何 d_setting_gear 紀錄時回傳 null（呼叫端「無規格則不顯示」）。
 */
/**
 * 「模數」要印成什麼字——**全站唯一實作**（2026-09-22 新增）。
 *
 * 踩過的坑：`d_setting_gear.Module` 存的是**加了 M 前綴的歷史值**（例：徑節 DP20 的那一筆，
 * Module 欄位實際上存著字串 'M20'），真正的輸入型態在 `module_input_type`（M／DP／CP），
 * 顯示值在 `module_display`（'DP20'）。所以只要直接拿 `Module` 來印、或再補一次 'M' 前綴，
 * **徑節(DP)與周節(CP)的料號一律會被印成公制模數 M**（實測 ST900-10：主檔是 DP20、
 * 報價單卻印 M20），而且完全不報錯——看起來只是「規格怪怪的」不像程式壞掉。
 * 一律：module_display 有值就用它，沒有才退回舊的 M 前綴邏輯（舊資料行為完全不變）。
 */
function eg_gear_module_sql(string $alias = 'g'): string {
    $a = $alias;
    return "COALESCE(NULLIF({$a}.module_display,''), "
         . "IF({$a}.Module IS NOT NULL AND {$a}.Module<>'', "
         . "IF(LEFT(UPPER({$a}.Module),1)='M', {$a}.Module, CONCAT('M',{$a}.Module)), ''))";
}

/**
 * PHP 陣列版（給已經把 d_setting_gear 撈成陣列的呼叫端用）。
 * 規則與 eg_gear_module_sql() 相同，不要再自己寫一份。
 *
 * @param bool $normalize 舊頁面有些是先 `floatval(preg_replace('/[^0-9.]/',''))` 再補 'M' 前綴
 *                        （所以 'M2.50' 會印成 'M2.5'）。傳 true＝沿用那套數字正規化，
 *                        這樣那些頁面除了 DP/CP 之外的每一列輸出都與改版前一模一樣。
 *                        實測：全庫只有 module_input_type='DP' 的 25 列會因此改變顯示。
 */
function eg_gear_module_text(array $gear, bool $normalize = false): string {
    $disp = trim((string)($gear['module_display'] ?? ''));
    if ($disp !== '') return $disp;
    $mod = trim((string)($gear['Module'] ?? ''));
    if ($mod === '') return '';
    if ($normalize) return 'M' . floatval(preg_replace('/[^0-9.]/', '', $mod));
    return preg_match('/^m/i', $mod) ? $mod : 'M' . $mod;
}

function eg_gear_spec_for_part(PDO $db, int $dsPk): ?string {
    if (!$dsPk) return null;
    $m = eg_gear_spec_map($db, [$dsPk]);
    return $m[$dsPk] ?? null;
}

/**
 * 批次版（2026-09-17 新增，供清單頁一次取多支料號的齒輪規格用）。
 * 單筆版 eg_gear_spec_for_part() 已改為呼叫本函式——**規則只有這一份**，
 * 不要再另外複製一段同樣的樣板替換 SQL（鐵律4）。
 * @return array d_setting.d_id => 齒輪規格字串（查無規格的料號不會出現在回傳陣列裡）
 */
function eg_gear_spec_map(PDO $db, array $dsPks): array {
    $dsPks = array_values(array_unique(array_filter(array_map('intval', $dsPks))));
    if (!$dsPks) return [];
    try {
        $tmplReplacements = [
            '{Module}'               => eg_gear_module_sql('g'),
            '{Teeth}'                => "COALESCE(CAST(NULLIF(g.Teeth,0) AS CHAR),'')",
            '{Face_Width}'           => "IF(g.Face_Width IS NOT NULL AND g.Face_Width>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR))), '')",
            '{Pressure_Angle}'       => "COALESCE(NULLIF(TRIM(TRAILING '°' FROM TRIM(COALESCE(g.Pressure_Angle,''))),''),'20')",
            '{Helix_Direction}'      => "COALESCE(NULLIF(g.Helix_Direction,''),'')",
            '{Helix_Angle_Str}'      => "COALESCE(NULLIF(g.Helix_Angle_Str,''), IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR))), ''))",
            '{spec_starts}'          => "COALESCE(CAST(NULLIF(g.spec_starts,0) AS CHAR),'')",
            '{X_PART}'               => "IF(g.Profile_Shift_X IS NOT NULL AND g.Profile_Shift_X<>0, CONCAT('X',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Profile_Shift_X AS CHAR)))), '')",
            '{GRADE}'                => "IF(g.gear_quality_std IS NOT NULL AND g.gear_quality_std<>'', CONCAT(g.gear_quality_std,COALESCE(CAST(g.gear_quality_grade AS CHAR),'')), '')",
            '{spec_chain_size}'      => "COALESCE(g.spec_chain_size,'')",
            '{spec_pitch}'           => "IF(g.spec_pitch IS NOT NULL AND g.spec_pitch>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_pitch AS CHAR))), '')",
            '{spec_roller_dia}'      => "IF(g.spec_roller_dia IS NOT NULL AND g.spec_roller_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_roller_dia AS CHAR))), '')",
            '{spec_spline_type}'     => "COALESCE(g.spec_spline_type,'')",
            '{spec_spline_major_dia}'=> "IF(g.spec_spline_major_dia IS NOT NULL AND g.spec_spline_major_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_major_dia AS CHAR))), '')",
            '{spec_spline_minor_dia}'=> "IF(g.spec_spline_minor_dia IS NOT NULL AND g.spec_spline_minor_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_minor_dia AS CHAR))), '')",
            '{spec_spline_width}'    => "IF(g.spec_spline_width IS NOT NULL AND g.spec_spline_width>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_width AS CHAR))), '')",
            '{spec_pulley_profile}'  => "COALESCE(g.spec_pulley_profile,'')",
            '{Remark_Gear}'          => "COALESCE(NULLIF(g.Remark_Gear,''),'')",
        ];
        $tmplExpr = 'dt.display_template';
        foreach ($tmplReplacements as $token => $expr) { $tmplExpr = "REPLACE($tmplExpr, '$token', $expr)"; }

        $modExpr = eg_gear_module_sql('g');   // 樣板與無樣板兩條路都要用同一份（否則 DP/CP 只修好一半）
        $ph  = implode(',', array_fill(0, count($dsPks), '?'));
        $sql = "SELECT g.d_setting_id, GROUP_CONCAT(
                    CASE
                      WHEN dt.display_template IS NOT NULL AND dt.display_template<>'' THEN
                        $tmplExpr
                      WHEN dt.spec_category='spline' AND g.spec_spline_type='矩形' THEN
                        CONCAT(IF(g.Teeth>0, CONCAT(g.Teeth,'鍵 '),''), COALESCE(CAST(g.spec_spline_minor_dia AS CHAR),'?'), ' × ', COALESCE(CAST(g.spec_spline_major_dia AS CHAR),'?'), ' × ', COALESCE(CAST(g.spec_spline_width AS CHAR),'?'))
                      ELSE
                        CONCAT(
                            $modExpr,
                            IF(dt.spec_category='worm_gear' AND g.spec_starts IS NOT NULL AND g.spec_starts > 0,
                               CONCAT('×', g.spec_starts, '條'),
                               IF(g.Teeth IS NOT NULL AND g.Teeth > 0, CONCAT('×', g.Teeth, 'T'), '')),
                            IF(g.Face_Width IS NOT NULL AND g.Face_Width > 0,
                               CONCAT(' W', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR)))), ''),
                            IF(g.Pressure_Angle IS NOT NULL AND g.Pressure_Angle != '',
                               CONCAT(' PA', g.Pressure_Angle, '°'), ''),
                            IF(g.Helix_Direction IS NOT NULL AND g.Helix_Direction != '',
                               CONCAT(' ', g.Helix_Direction), ''),
                            IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle > 0,
                               CONCAT(' ', COALESCE(NULLIF(g.Helix_Angle_Str,''),
                               TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR)))), '°'), ''),
                            IF(g.Profile_Shift_X IS NOT NULL AND g.Profile_Shift_X != 0,
                               CONCAT(' X', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Profile_Shift_X AS CHAR)))), '')
                        )
                    END
                    ORDER BY g.gear_id SEPARATOR ' / '
                ) AS gear_str
                FROM d_setting_gear g
                LEFT JOIN dict_gear_type dt ON dt.gear_type_id = g.Gear_Type
                WHERE g.d_setting_id IN ($ph)
                GROUP BY g.d_setting_id";
        $st = $db->prepare($sql);
        $st->execute($dsPks);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = eg_gear_spec_tidy((string)$r['gear_str']);
            if ($v !== '') $out[(int)$r['d_setting_id']] = $v;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * 把樣板組出來的字串收乾淨（2026-09-22 新增）。
 *
 * 齒型樣板（dict_gear_type.display_template）是「標籤＋值」直接串起來的，欄位沒填時
 * **標籤會單獨留在字串上**：齒寬沒填就印出一個孤零零的 'W'（實測 `M2.5 T27 W PA20`），
 * 鏈輪沒填鏈條規格／節距就印出 `' x T10'`（前面還多一個空格）。
 * 這裡只做兩件很保守的事，不改任何有值的內容：
 *   ⑴ 把「只剩單位標籤、後面沒有數字」的 token 丟掉（W/PA/T/L/X 與乘號 x/×）——
 *      刻意用白名單，所以 'custom'、'RH'、'LH'、'8YU' 這種有意義的字不會被誤殺；
 *   ⑵ 收掉重複空白與頭尾空白。
 * 多齒型的 ' / ' 分隔要逐段處理，不可以整串一起切。
 */
function eg_gear_spec_tidy(string $s): string {
    if ($s === '') return '';
    $drop = ['W' => 1, 'PA' => 1, 'T' => 1, 'L' => 1, 'X' => 1, 'x' => 1, '×' => 1];
    $segs = [];
    foreach (explode(' / ', $s) as $seg) {
        $keep = [];
        foreach (preg_split('/\s+/u', trim($seg), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (isset($drop[$tok])) continue;
            $keep[] = $tok;
        }
        $seg = implode(' ', $keep);
        if ($seg !== '') $segs[] = $seg;
    }
    return implode(' / ', $segs);
}

/**
 * 「齒輪類型」下拉的選項——**全站唯一實作**（2026-10-02 新增）。
 *
 * 踩過的坑：`d_setting_gear.Gear_Type` 是 **int，外鍵指向 `dict_gear_type.gear_type_id`**，
 * 不是中文名稱。報價單管理的「新增料號」跳窗把選項寫死成 `<option value="直齒">`，
 * 於是只要工件種類選齒輪並填了齒輪規格，存檔就一定炸
 * `1366 Incorrect integer value: '直齒' for column 'Gear_Type'`（整個交易回滾，連料號本身也存不進去）；
 * 而「修改既有齒輪料號」更糟——DB 存的是 id，寫死的中文選項一個都對不上，
 * 下拉會退回「請選擇」，一按儲存就把 Gear_Type 寫成 NULL，
 * 連帶讓 `eg_gear_keep_restore()` 的配對失敗（它要求齒型相同才還原），
 * 鏈輪規格／花鍵尺寸／齒輪等級一起靜默消失。
 *
 * 所以任何頁面要讓人挑齒輪類型，一律呼叫這支拿選項、`value` 一律存 gear_type_id，
 * **不要再在頁面裡寫死一份中文清單**（鐵律4：管理員在主檔管理改名或新增類型，寫死的那份不會跟著變也不報錯）。
 *
 * @return array [ ['value'=>'1','label'=>'直齒','hasHelix'=>false,'specCategory'=>'standard'], ... ]
 *               只回啟用中的，排序與主檔管理的齒輪類型字典完全相同
 */
function eg_gear_type_options(PDO $db): array {
    try {
        $out = [];
        foreach (eg_gear_type_rows($db) as $r) {
            $out[] = [
                'value'        => (string)$r['gear_type_id'],
                'label'        => (string)$r['type_name'],
                'hasHelix'     => ((int)$r['has_helix_angle'] === 1),
                'specCategory' => ((string)($r['spec_category'] ?? '') !== '') ? (string)$r['spec_category'] : 'standard',
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * 壓力角沒填時要補的預設值——**與主檔管理（views/pages/master_data_management.php）同一條規則**：
 * 花鍵 30 度、其餘（直齒／螺旋齒…）20 度。
 * `Pressure_Angle` 是 varchar，所以回傳字串。
 */
function eg_gear_default_pressure_angle(PDO $db, $gearTypeId): string {
    static $cat = null;
    if ($cat === null) {
        $cat = [];
        try {
            foreach (($db->query("SELECT gear_type_id, spec_category FROM dict_gear_type")->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                $cat[(int)$r['gear_type_id']] = (string)$r['spec_category'];
            }
        } catch (Throwable $e) { $cat = []; }
    }
    return (($cat[(int)$gearTypeId] ?? 'standard') === 'spline') ? '30' : '20';
}

/**
 * 把前端送來的「齒輪類型」正規化成 `d_setting_gear.Gear_Type` 要存的 int。
 * 收 id（正常情況）也收類型名稱——後者是給「使用者的分頁在改版前就開著、送來的還是舊的中文值」
 * 這種情況用的退路，讓它自己對回 id 而不是整筆存檔失敗。
 * @return int|null  空值回 null；對不到任何啟用中的類型回 false（呼叫端自己決定要不要擋）
 */
function eg_gear_type_id(PDO $db, $v) {
    $s = trim((string)($v ?? ''));
    if ($s === '') return null;
    if (ctype_digit($s)) return (int)$s;
    try {
        $st = $db->prepare("SELECT gear_type_id FROM dict_gear_type WHERE type_name = ? ORDER BY is_active DESC, sort_order, gear_type_id LIMIT 1");
        $st->execute([$s]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : false;
    } catch (Throwable $e) { return false; }
}

/**
 * 齒輪類型字典的原始列（啟用中）——SQL 只有這一份。
 * 欄位與主檔管理原本的 `manage_gear_types op=list` **一字不差**，
 * 所以把那支改成呼叫這裡不會改變任何既有行為。
 */
function eg_gear_type_rows(PDO $db): array {
    try {
        return $db->query("SELECT gear_type_id, type_name, has_helix_angle, sort_order, is_active,
                                  COALESCE(spec_category,'standard') AS spec_category,
                                  display_template
                             FROM dict_gear_type WHERE is_active = 1
                            ORDER BY sort_order, gear_type_id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 齒輪規格設定元件要用的四份字典，一次取齊（唯一實作）。
 * 呼叫端：views/pages/gear_spec_api.php（唯一端點）。
 *   types   齒輪類型（決定齒型下拉、有沒有螺旋角、是不是鏈輪/皮帶輪/花鍵）
 *   chains  鏈條規格（鏈輪的節距與滾子外徑）
 *   belts   皮帶齒型（皮帶輪的節距與 PLD）
 *   quality 齒輪等級對照（JIS/ISO/DIN/AGMA）
 */
function eg_gear_spec_dicts(PDO $db): array {
    $q = function (string $sql) use ($db): array {
        try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    };
    return [
        'types'   => eg_gear_type_rows($db),
        'chains'  => $q("SELECT chain_size, pitch_mm, roller_dia_mm, chain_std FROM dict_chain_size ORDER BY sort_order, chain_size"),
        'belts'   => $q("SELECT profile_code, pitch_mm, pld_mm, belt_standard FROM dict_timing_belt_profile ORDER BY sort_order, profile_code"),
        'quality' => $q("SELECT * FROM dict_gear_quality_ref ORDER BY sort_order, quality_ref_id"),
    ];
}

/**
 * 把一支料號的齒輪規格整批寫回 `d_setting_gear`（**全站唯一實作**，2026-10-02）。
 *
 * 由 views/pages/master_data_management.php 原封不動抽出，給它與報價單管理
 * 「新增料號」共用——使用者要求「在報價單建立的齒輪規格資料要跟在主檔管理建立的一樣、
 * 記錄到同一個資料表」。報價單那邊原本只寫得了 12 欄（模數的 DP/CP 標記、齒輪等級、
 * 鏈輪與花鍵的尺寸都存不進去），抽成同一支之後兩邊寫進去的東西完全相同。
 *
 * 做法沿用原本的「整批刪掉再逐列寫回」，因為這支 INSERT 寫滿 30 欄
 * （整張表除了 gear_id／Created_At／Modified_* 之外全部），不會有欄位被洗掉的問題。
 *
 * 三條規則（與主檔管理完全相同）：
 *   ⑴ 模數：module_input_type=M 時補 'M' 前綴；CP/DP 前端已換算成 M 值，一樣補前綴；
 *   ⑵ 壓力角沒填：花鍵 30 度、其餘 20 度；
 *   ⑶ 齒輪等級標準只收 JIS/ISO/DIN/AGMA，其餘一律視為沒填。
 * 另外兩條是這次新增的防呆（抽出來之前只有報價單那條路會踩到）：
 *   ⑷ 整列全空的齒型不寫入（工件種類切到齒輪時畫面會自動長一列空白列）；
 *   ⑸ Gear_Type 收 id，也接受舊畫面送來的中文類型名稱並自動對回 id，
 *      對不到才丟例外——直接讓它撞 `1366 Incorrect integer value` 會把整筆存檔一起回滾。
 *
 * @param array $gears 逐列的齒輪資料（欄位名與 d_setting_gear 相同，
 *                     外加 module_input_type／module_display／Gear_Quality_Std／Gear_Quality_Grade）
 * @return int 實際寫入幾列
 * @throws Exception 齒輪類型無法辨識時
 */
function eg_gear_rows_save(PDO $db, int $dId, array $gears, string $uid): int {
    $db->prepare("DELETE FROM d_setting_gear WHERE d_setting_id=?")->execute([$dId]);
    if (!$gears) return 0;

    // 預先撈 gear_type spec_category，供壓力角預設值判斷
    $gearTypeCatMap = [];
    try {
        foreach (($db->query("SELECT gear_type_id, spec_category FROM dict_gear_type")->fetchAll(PDO::FETCH_ASSOC) ?: []) as $gt) {
            $gearTypeCatMap[intval($gt['gear_type_id'])] = $gt['spec_category'];
        }
    } catch (Throwable $_ge) {}

    $sg = $db->prepare("INSERT INTO d_setting_gear (
        d_setting_id,Module,Teeth,Face_Width,Helix_Angle,Helix_Angle_Str,Helix_Direction,
        Pressure_Angle,Profile_Shift_X,Workpiece_Length,Gear_Type,Spec_No,Remark_Gear,
        gear_quality_std,gear_quality_grade,module_input_type,module_display,
        spec_chain_size,spec_pitch,spec_roller_dia,spec_starts,
        spec_pulley_profile,spec_pld,
        spec_spline_type,spec_spline_major_dia,spec_spline_minor_dia,spec_spline_width,
        spec_spline_std,spec_spline_nominal_dia,
        Created_By
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $n = 0; $idx = 0;
    foreach ($gears as $g) {
        $idx++;
        $v  = function($k) use ($g) { return (isset($g[$k]) && $g[$k] !== '') ? $g[$k] : null; };
        $vf = function($k) use ($g) { $x = $g[$k] ?? null; return ($x !== null && $x !== '') ? floatval($x) : null; };

        // ⑷ 整列全空就不要建一筆空的齒型紀錄
        $filled = false;
        foreach ($g as $gv) { if (trim((string)($gv ?? '')) !== '') { $filled = true; break; } }
        if (!$filled) continue;

        // ⑸ Gear_Type 一律正規化成 dict_gear_type.gear_type_id
        $gt = eg_gear_type_id($db, $g['Gear_Type'] ?? null);
        if ($gt === false) throw new Exception("第 {$idx} 組齒輪的「齒輪類型」無法辨識，請重新整理頁面後再選一次。");

        $mod = $v('Module');
        if ($mod !== null) {
            $mit = strtoupper($v('module_input_type') ?? 'M');
            if ($mit === 'M') { $mod = 'M' . ltrim(ltrim($mod, 'm'), 'M'); }
            // CP/DP已由前端換算為M值，直接加M前綴
            else { $num = floatval(preg_replace('/[^\d.]/','',$mod)); $mod = ($num > 0) ? 'M'.rtrim(rtrim(sprintf('%.4f',$num),'0'),'.') : null; }
        }
        $qstd = $v('Gear_Quality_Std');
        if ($qstd !== null && !in_array($qstd, ['JIS','ISO','DIN','AGMA'])) $qstd = null;
        $qgrade = $v('Gear_Quality_Grade');
        if ($qgrade !== null) $qgrade = intval($qgrade);
        $pa = $v('Pressure_Angle');
        if ($pa === null || trim($pa) === '') {
            // 花鍵預設30度，其他齒輪預設20度
            $specCat = $gearTypeCatMap[intval($gt ?? 0)] ?? 'standard';
            $pa = ($specCat === 'spline') ? '30' : '20';
        }
        $sg->execute([$dId,$mod,$v('Teeth'),$vf('Face_Width'),$v('Helix_Angle'),$v('Helix_Angle_Str'),$v('Helix_Direction'),
            $pa,$vf('Profile_Shift_X'),$vf('Workpiece_Length'),$gt,$v('Spec_No'),$v('Remark_Gear'),
            $qstd,$qgrade,$v('module_input_type'),$v('module_display') ?: null,
            $v('spec_chain_size'),$vf('spec_pitch'),$vf('spec_roller_dia'),$v('spec_starts') ? intval($v('spec_starts')) : null,
            $v('spec_pulley_profile'),$vf('spec_pld'),
            $v('spec_spline_type'),$vf('spec_spline_major_dia'),$vf('spec_spline_minor_dia'),$vf('spec_spline_width'),
            $v('spec_spline_std'),$vf('spec_spline_nominal_dia'),
            $uid]);
        $n++;
    }
    return $n;
}
