<?php
/**
 * 齒輪規格顯示字串（比照 views/Sales/NewOrder_Track.php 料號下方顯示的「齒輪規格」邏輯，
 * 2026-08-13 供 PFMEA「規格描述」自動判斷帶入用而獨立抽出單一料號版本）。
 * 刻意不修改 NewOrder_Track.php 改呼叫共用函式——那是一支大型既有正常運作頁面，不做非必要重構，
 * 這裡逐字複製同一套 SQL/樣板替換邏輯；未來若規格樣板規則異動，兩處需一併更新。
 * 查無任何 d_setting_gear 紀錄時回傳 null（呼叫端「無規格則不顯示」）。
 */
function eg_gear_spec_for_part(PDO $db, int $dsPk): ?string {
    if (!$dsPk) return null;
    try {
        $tmplReplacements = [
            '{Module}'               => "COALESCE(NULLIF(g.module_display,''), IF(g.Module IS NOT NULL AND g.Module<>'', IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M',g.Module)), ''))",
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

        $sql = "SELECT GROUP_CONCAT(
                    CASE
                      WHEN dt.display_template IS NOT NULL AND dt.display_template<>'' THEN
                        $tmplExpr
                      WHEN dt.spec_category='spline' AND g.spec_spline_type='矩形' THEN
                        CONCAT(IF(g.Teeth>0, CONCAT(g.Teeth,'鍵 '),''), COALESCE(CAST(g.spec_spline_minor_dia AS CHAR),'?'), ' × ', COALESCE(CAST(g.spec_spline_major_dia AS CHAR),'?'), ' × ', COALESCE(CAST(g.spec_spline_width AS CHAR),'?'))
                      ELSE
                        CONCAT(
                            IF(g.Module IS NOT NULL AND g.Module != '',
                               IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M', g.Module)), ''),
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
                WHERE g.d_setting_id = ?
                GROUP BY g.d_setting_id";
        $st = $db->prepare($sql);
        $st->execute([$dsPk]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? $v : null;
    } catch (Throwable $e) { return null; }
}
