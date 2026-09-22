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
