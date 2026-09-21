<?php
/**
 * bom_part_setting_lib.php —— 「這張製令(bom)對應到哪一筆料號主檔(d_setting)」的唯一判定
 *
 * 背景（2026-09-21）：全站很多查詢都是這樣把製令接到料號主檔的——
 *     LEFT JOIN d_setting ds ON ds.D_Setting_Id = b.d_id
 * 但 **同一個料號文字在 d_setting 常常有好幾筆（不同客戶）**（實測 1,738 個料號文字共 3,946 筆），
 * 例：GF170N3023B01 → #734（2T002）、#22510（RU001）。
 * 於是這個 LEFT JOIN 會把**同一筆報工複製成兩列**，症狀是：
 *   ⑴ 畫面上同一筆報工重複顯示（KPI 人員報工明細實測 2 筆變 4 筆）
 *   ⑵ 筆數/分頁對不起來（COUNT 沒有 join d_setting，所以「共 2 筆」卻列出 4 列）
 *   ⑶ **金額、標準工時這類「逐列加總」的數字直接翻倍**（實測生產金額 1,586 → 3,171）
 * 而且完全不報錯。`d_setting_gear` 同樣有一個 d_setting_id 對到多列的情形（實測 15 組）。
 *
 * 判定口徑（與 bom_client_lib.php 的客戶判定同一條）：
 *   1) 料號文字必須相同（`d_setting.D_Setting_Id = bom.d_id`）——與改版前的比對條件完全一樣，
 *      所以不會憑空多接到別的料號；
 *   2) 其中有一筆就是製令自己綁定的料號主檔（`bom.d_setting_id`）時優先取它（那才是對的客戶）；
 *   3) 沒有綁定（`bom.d_setting_id` 實測八成是 NULL）時取 d_id 最小的那一筆，
 *      與出貨統計「回退取 MIN(d_id)」的既有規則一致（記憶 ship_stats_by_dsetting_id）。
 * 齒輪規格一律取 gear_id 最小的那一筆（與 gear_spec_lib 的 ORDER BY gear_id 同序）。
 *
 * 用法：
 *   require_once __DIR__.'/bom_part_setting_lib.php';
 *   $sql = "SELECT ds.d_id AS d_setting_id, ds.Type AS part_type, dsg.Module, dsg.Teeth, dsg.Face_Width
 *           FROM pm_process_daily_report pdr
 *           LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
 *           LEFT JOIN bom b ON b.bom=bi.bom
 *           ".eg_bom_ds_join('b','ds','dsg')."
 *           WHERE ...";
 *
 * 注意：這兩段 JOIN 保證「一列製令只會接出一列 d_setting、一列 d_setting_gear」，
 * 所以呼叫端的 COUNT(*) 與逐列加總才會跟明細列數一致；不要再自己寫 ds.D_Setting_Id=b.d_id。
 */

/**
 * 產生「製令 → 料號主檔（＋齒輪規格）」的 LEFT JOIN 片段，保證不會讓列數變多。
 *
 * @param string      $bomAlias   查詢裡 bom 表的別名
 * @param string      $dsAlias    要給 d_setting 的別名
 * @param string|null $gearAlias  要給 d_setting_gear 的別名；傳 null＝這支查詢用不到齒輪規格
 */
function eg_bom_ds_join(string $bomAlias = 'b', string $dsAlias = 'ds', ?string $gearAlias = 'dsg'): string
{
    $b  = $bomAlias;
    $ds = $dsAlias;
    $sql = " LEFT JOIN d_setting {$ds} ON {$ds}.d_id = ("
         . "SELECT {$ds}_pk.d_id FROM d_setting {$ds}_pk"
         . " WHERE {$ds}_pk.D_Setting_Id = {$b}.d_id"
         . " ORDER BY (CASE WHEN {$ds}_pk.d_id = {$b}.d_setting_id THEN 0 ELSE 1 END), {$ds}_pk.d_id"
         . " LIMIT 1) ";
    if ($gearAlias !== null && $gearAlias !== '') {
        $g = $gearAlias;
        $sql .= " LEFT JOIN d_setting_gear {$g} ON {$g}.gear_id = ("
              . "SELECT {$g}_pk.gear_id FROM d_setting_gear {$g}_pk"
              . " WHERE {$g}_pk.d_setting_id = {$ds}.d_id"
              . " ORDER BY {$g}_pk.gear_id LIMIT 1) ";
    }
    return $sql;
}
