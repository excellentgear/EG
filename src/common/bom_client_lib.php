<?php
/**
 * bom_client_lib.php — 製令（bom）客戶名稱的唯一判定
 *
 * 背景（2026-09-16）：`bom.Client_Name` 是「當下寫進去的文字快取」，不是即時查出來的，
 * 而**同一個料號文字在 `d_setting` 常常有好幾筆（不同客戶）**，
 * 例：OB321500060 → #765 旻成、#19804 松田；OB321500200 → #440 旻成、#19799 松田。
 * 只要有任何一支程式「用料號文字去查客戶」，就會撿到不是這張製令的那一家，
 * 而且完全不報錯（實測 B-1150915004：訂單與 `bom.d_setting_id` 都是旻成，
 * `Client_Name` 卻被寫成松田）。
 *
 * 判定口徑（與 `views/pm/OreadyReply_ForPm_BaseOfTime.php` 既有顯示規則相同）：
 *   有綁定料號主檔（`bom.d_setting_id`）→ 以該筆主檔的客戶為準；
 *   沒有綁定 → 才退回 `bom.Client_Name` 這個文字快取。
 *
 * 用法（SQL 端，查詢裡的 bom 表別名傳進來即可）：
 *   $sql = "SELECT ".eg_bom_client_expr('b')." AS Client_Name
 *           FROM bom b ".eg_bom_client_join('b')." WHERE ...";
 * 別名固定為 ds_bc / cl_bc，不要在同一支查詢裡重複 join 第二次。
 */

/** 取客戶名稱的 SQL 運算式（搭配 eg_bom_client_join 使用） */
function eg_bom_client_expr(string $bomAlias = 'b'): string
{
    return "COALESCE(NULLIF(cl_bc.customer,''), {$bomAlias}.Client_Name)";
}

/** 取客戶名稱所需的 JOIN（LEFT JOIN，兩邊都是主鍵對應，不會讓列數變多） */
function eg_bom_client_join(string $bomAlias = 'b'): string
{
    return " LEFT JOIN d_setting ds_bc ON ds_bc.d_id = {$bomAlias}.d_setting_id"
         . " LEFT JOIN customer_list cl_bc ON cl_bc.customer_id = ds_bc.Customer_Id ";
}

/**
 * PHP 端批次判定：一批製令各自的客戶名稱。
 * 回傳 [bom => 客戶名稱或 null]，判定順序：
 *   1) bom.d_setting_id 綁定的料號主檔客戶
 *   2) bom.o_order_id 指到的訂單（order_track.d_id_ID 的主檔客戶，退而求其次用訂單客戶）
 *   3) 料號文字在 d_setting 只對應到唯一一家客戶時才採用
 *   4) 以上都不成立（料號文字對應到多家）→ null，代表「無法判定，不要亂猜」
 */
function eg_bom_client_resolve(PDO $db, array $bomNos): array
{
    $bomNos = array_values(array_unique(array_filter($bomNos, fn($v) => (string)$v !== '')));
    if (!$bomNos) return [];

    $out = [];
    $needByPart = [];   // 料號文字 => true（走第 3 步）
    $partOfBom  = [];   // bom => 料號文字

    foreach (array_chunk($bomNos, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare(
            "SELECT b.bom, b.d_id, b.d_setting_id, b.o_order_id,
                    cl_bind.customer AS bind_customer,
                    cl_ord.customer  AS ord_customer
             FROM bom b
             LEFT JOIN d_setting ds_bind ON ds_bind.d_id = b.d_setting_id
             LEFT JOIN customer_list cl_bind ON cl_bind.customer_id = ds_bind.Customer_Id
             LEFT JOIN order_track ot ON ot.Order_id = b.o_order_id AND b.o_order_id REGEXP '^[0-9]+$'
             LEFT JOIN d_setting ds_ord ON ds_ord.d_id = ot.d_id_ID
             LEFT JOIN customer_list cl_ord ON cl_ord.customer_id = COALESCE(ds_ord.Customer_Id, ot.Client_name_ID)
             WHERE b.bom IN ($ph)"
        );
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bom = $r['bom'];
            $partOfBom[$bom] = (string)$r['d_id'];
            if (!empty($r['bind_customer']))     { $out[$bom] = $r['bind_customer']; continue; }
            if (!empty($r['ord_customer']))      { $out[$bom] = $r['ord_customer'];  continue; }
            $out[$bom] = null;
            if ((string)$r['d_id'] !== '') $needByPart[(string)$r['d_id']] = true;
        }
    }

    if ($needByPart) {
        $byPart = eg_bom_client_by_part($db, array_keys($needByPart));
        foreach ($out as $bom => $v) {
            if ($v !== null) continue;
            $p = $partOfBom[$bom] ?? '';
            if ($p !== '' && array_key_exists($p, $byPart)) $out[$bom] = $byPart[$p];
        }
    }
    return $out;
}

/**
 * 料號文字 => 客戶名稱，**只回「唯一一家」的那些料號**。
 * 同一個料號文字掛在多家客戶底下時刻意不回傳（等於「無法判定」），
 * 由呼叫端決定要留空還是維持原值——絕對不可以隨便挑一筆。
 */
function eg_bom_client_by_part(PDO $db, array $partNos): array
{
    $partNos = array_values(array_unique(array_filter($partNos, fn($v) => (string)$v !== '')));
    if (!$partNos) return [];

    $out = [];
    foreach (array_chunk($partNos, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare(
            "SELECT ds.D_Setting_Id,
                    COUNT(DISTINCT cl.customer) AS cust_cnt,
                    MIN(cl.customer)            AS one_customer
             FROM d_setting ds
             JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
             WHERE ds.D_Setting_Id IN ($ph)
             GROUP BY ds.D_Setting_Id"
        );
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['cust_cnt'] === 1) $out[$r['D_Setting_Id']] = $r['one_customer'];
        }
    }
    return $out;
}

/**
 * 找出「同一個料號文字掛在兩家以上客戶底下」的料號（匯入預覽要提醒使用者的就是這些）。
 * 與「主檔查無這個料號」是兩件事，不要混在同一個警告裡。
 */
function eg_bom_client_ambiguous_parts(PDO $db, array $partNos): array
{
    $partNos = array_values(array_unique(array_filter($partNos, fn($v) => (string)$v !== '')));
    if (!$partNos) return [];

    $out = [];
    foreach (array_chunk($partNos, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare(
            "SELECT ds.D_Setting_Id
             FROM d_setting ds
             JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
             WHERE ds.D_Setting_Id IN ($ph)
             GROUP BY ds.D_Setting_Id
             HAVING COUNT(DISTINCT cl.customer) > 1"
        );
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $out[] = $v;
    }
    return $out;
}

/**
 * 料號主檔（d_setting.d_id）對應的客戶名稱；沒綁客戶或查無主檔時回 null。
 * 「把製令綁到某一筆料號主檔」的所有入口都用這支取客戶名稱，
 * **回 null 時一律保留原本的 Client_Name，不可以寫空字串把 ERP 帶進來的名稱洗掉**。
 */
function eg_bom_client_of_dsetting(PDO $db, $dSettingId): ?string
{
    $dSettingId = (int)$dSettingId;
    if ($dSettingId <= 0) return null;
    $st = $db->prepare(
        "SELECT cl.customer
         FROM d_setting ds
         JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
         WHERE ds.d_id = ? LIMIT 1"
    );
    $st->execute([$dSettingId]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null || trim((string)$v) === '') ? null : (string)$v;
}
