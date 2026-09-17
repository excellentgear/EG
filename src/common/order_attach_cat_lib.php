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

// ──────────────────────────────────────────────────────────────────────────
// 一份附件可以對應「多個料號」（使用者明確要求，2026-09-11：有些客戶提供的資料是全部放在一起，
// 一張圖同時屬於好幾個料號，原本 linked_part_no 只存得下一個，等於只有「綁一個」跟「全部共用」兩種極端）。
//
// 存法刻意維持向下相容，**不改既有單一料號那 16 列的內容**：
//   沒有綁定      → NULL（＝共用／全部，維持原意）
//   綁定一個料號  → 直接存料號字串（跟改版前一模一樣）
//   綁定多個料號  → 存 JSON 陣列 ["A","B"]（料號本身不會是 [ 開頭，不會誤判）
// 讀取一律走 eg_oa_parts_decode()，寫入一律走 eg_oa_parts_encode()，不要各自 explode。
// ──────────────────────────────────────────────────────────────────────────

if (!function_exists('eg_oa_parts_decode')) {
    /** linked_part_no 欄位值 → 料號陣列（空陣列＝共用／未綁定） */
    function eg_oa_parts_decode($raw): array {
        $raw = trim((string)($raw ?? ''));
        if ($raw === '') return [];
        if ($raw[0] === '[') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $out = [];
                foreach ($j as $p) { $p = trim((string)$p); if ($p !== '' && !in_array($p, $out, true)) $out[] = $p; }
                return $out;
            }
        }
        return [$raw];   // 舊資料：單一料號原樣
    }
}

if (!function_exists('eg_oa_parts_encode')) {
    /** 料號陣列 → linked_part_no 欄位值（null／單一字串／JSON 陣列） */
    function eg_oa_parts_encode(array $parts): ?string {
        $out = [];
        foreach ($parts as $p) { $p = trim((string)$p); if ($p !== '' && !in_array($p, $out, true)) $out[] = $p; }
        if (!$out) return null;
        if (count($out) === 1) return $out[0];
        return json_encode(array_values($out), JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('eg_oa_parts_from_post')) {
    /**
     * 前端送過來的料號（相容三種寫法：陣列 linked_part_nos[]、JSON 字串、單一 linked_part_no）
     * → 料號陣列。空＝共用／未綁定。
     */
    function eg_oa_parts_from_post(array $post): array {
        if (isset($post['linked_part_nos'])) {
            $v = $post['linked_part_nos'];
            if (is_array($v)) return eg_oa_parts_decode(eg_oa_parts_encode($v) ?? '');
            $s = trim((string)$v);
            if ($s === '') return [];
            if ($s[0] === '[') return eg_oa_parts_decode($s);
            // 逗號分隔（料號本身極少含逗號；order_track.d_id 實測 0 筆含逗號）
            return eg_oa_parts_decode(eg_oa_parts_encode(explode(',', $s)) ?? '');
        }
        $one = trim((string)($post['linked_part_no'] ?? ''));
        return $one === '' ? [] : [$one];
    }
}

if (!function_exists('eg_oa_parts_label')) {
    /** 畫面顯示用：綁定的料號文字（空＝共用（全部）） */
    function eg_oa_parts_label(array $parts, int $totalParts = 0): string {
        if (!$parts) return '共用（全部）';
        if ($totalParts > 0 && count($parts) >= $totalParts) return '全部料號（' . count($parts) . '）';
        return implode('、', $parts);
    }
}
