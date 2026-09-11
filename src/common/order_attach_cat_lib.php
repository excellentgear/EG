<?php
// order_attach_cat_lib.php — 訂單附件「需綁定料號」的類別判定（唯一實作，禁止各頁自己再讀一次設定）
//
// 背景（使用者交辦，2026-09-11）：
//   訂單頁（views/Sales/NewOrder_Track.php）的附件標籤沿用報價單的 quotation_file_categories，
//   而「哪些標籤的附件必須連結單一料號」原本只有報價單自己那一份設定
//   （system_parameters QUOTATION/required_attach_cats，由 quotation_list_NEW.php 維護）。
//   實務上這兩張表單的需求不同：像「原圖」在 OP 轉訂單時是整批一起上傳，但一張原圖其實只屬於
//   某一個料號，沒有綁定就會被當成共用附件掛到同一張訂單編號底下的每個料號，
//   之後在 bom_viewer 圖面查閱頁就會看到不屬於該料號的原圖。
//
// 作法：另存一份「訂單頁專用」的清單 system_settings.order_attach_require_part_cats。
//   **尚未設定過（這一列不存在）＝完全沿用報價單那份**，所以不改設定的既有使用者行為一個字都不會變；
//   管理員在「訂單變更設定 → 本頁使用的附件標籤」逐一勾選之後，訂單頁才改用自己這一份（取代，不是聯集，
//   否則使用者取消勾選卻還是被擋，會變成「設定了沒有用」）。
//
// 這份清單有三個消費端，一律呼叫本檔，不要各自再查一次設定：
//   1. src/store/Order_Attachment_API.php  get_categories → 前端標紅色 * 並拿掉「共用（全部）」選項
//   2. src/store/_NewOrder_Track.php       eg_order_attach_check_required_part（存檔前後端再擋一次＝鐵律8）
//   3. src/store/_NewOrder_Track.php       eg_order_attach_sync_shared_by_orderno（判定哪些附件要跨料號連動）

if (!function_exists('eg_oa_quotation_required_cat_ids')) {
    /** 報價單那份「必備類別，需連結單一料號」設定（訂單頁未客製化時的預設值） */
    function eg_oa_quotation_required_cat_ids(PDO $db): array {
        try {
            $v = $db->query("SELECT param_value FROM system_parameters
                             WHERE param_group='QUOTATION' AND param_key='required_attach_cats'")->fetchColumn();
            if (!$v) return [];
            return array_values(array_unique(array_map('intval', (json_decode($v, true) ?: []))));
        } catch (Exception $e) { return []; }
    }
}

if (!function_exists('eg_oa_require_part_setting')) {
    /**
     * 訂單頁自己那份設定的「原始值」。
     * @return array|null null＝從來沒設定過（要沿用報價單那份）；陣列＝已客製化（可以是空陣列＝這頁全部都不強制）
     */
    function eg_oa_require_part_setting(PDO $db): ?array {
        try {
            $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='order_attach_require_part_cats'");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v === false || $v === null) return null;   // 沒有這一列＝未客製化
            $v = trim((string)$v);
            if ($v === '') return [];                        // 空字串＝客製化成「一個都不強制」
            return array_values(array_unique(array_filter(array_map('intval', explode(',', $v)))));
        } catch (Exception $e) { return null; }
    }
}

if (!function_exists('eg_oa_require_part_cat_ids')) {
    /** 訂單頁實際生效的「需綁定料號」類別 id 清單 */
    function eg_oa_require_part_cat_ids(PDO $db): array {
        $own = eg_oa_require_part_setting($db);
        return $own !== null ? $own : eg_oa_quotation_required_cat_ids($db);
    }
}

if (!function_exists('eg_oa_cats_need_part')) {
    /**
     * 這個附件所勾的類別字串（逗號分隔）裡，有沒有任何一個是「需綁定料號」的。
     * 沒有勾任何類別時回 false（另有「附件標籤鐵則」在別處擋，不在這裡重複報錯）。
     */
    function eg_oa_cats_need_part(?string $categoryIds, array $requireIds): bool {
        if (!$requireIds) return false;
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$categoryIds))));
        return $ids && (bool)array_intersect($ids, $requireIds);
    }
}
