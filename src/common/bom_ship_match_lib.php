<?php
/**
 * BOM總覽「客戶」欄的出貨紀錄、以及點它開出貨分析（Shipping_Insight.php）時的篩選：
 * 一律只算「同料號、同客戶」的出貨（2026-11-11 使用者要求；同一個料號文字常掛在好幾家客戶底下）。
 * 唯一實作——BOM總覽的按需撈取（get_row_details）、自動更新（_fetch_data.php／_fetch_data2.php）
 * 與出貨分析的深連結篩選都呼叫這裡，規則才不會走鐘。
 *
 * 規則：知道料號主檔 id 就以主檔 id 比對（主檔本身綁定客戶；is_list.d_setting_id 是 NULL 的舊資料才退回比客戶名稱），
 * 不知道主檔 id 時用「料號文字＋客戶名稱」。
 */

if (!function_exists('eg_bom_ship_sid')) {
    /** 製令的料號主檔 id：製令有就用製令的，沒有就以「料號文字＋客戶名稱」到料號主檔找（查不到回 0） */
    function eg_bom_ship_sid(PDO $db, $bomSid, string $partNo, string $client): int {
        $sid = (int)$bomSid;
        if ($sid > 0) return $sid;
        $partNo = trim($partNo); $client = trim($client);
        if ($partNo === '' || $client === '') return 0;
        static $cache = [];
        $k = $partNo . "\x1f" . $client;
        if (!array_key_exists($k, $cache)) {
            $q = $db->prepare("SELECT d.d_id FROM d_setting d JOIN customer_list c ON c.customer_id=d.Customer_Id
                                WHERE d.D_Setting_Id=? AND TRIM(c.customer)=? ORDER BY d.d_id LIMIT 1");
            $q->execute([$partNo, $client]);
            $cache[$k] = (int)$q->fetchColumn();
        }
        return $cache[$k];
    }

    /** 這一筆出貨（需含 d_setting_id 與 Client_name）是不是這張製令的同料號同客戶 */
    function eg_bom_ship_row_match(array $ship, int $sid, string $client): bool {
        $rowSid = (int)($ship['d_setting_id'] ?? 0);
        $rowCn  = trim((string)($ship['Client_name'] ?? ''));
        if ($sid > 0) return $rowSid === $sid || ($rowSid <= 0 && $rowCn === trim($client));
        return $rowCn === trim($client);
    }

    /** 從同料號文字的出貨清單中，挑出這張製令同客戶的那幾筆 */
    function eg_bom_ship_filter(PDO $db, array $ships, $bomSid, string $partNo, string $client): array {
        $sid = eg_bom_ship_sid($db, $bomSid, $partNo, $client);
        return array_values(array_filter($ships, function ($s) use ($sid, $client) { return eg_bom_ship_row_match($s, $sid, $client); }));
    }
}
