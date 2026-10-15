<?php
/**
 * quote_attach_scope_lib.php — 「這一份報價附件屬不屬於這個料號」的唯一實作點（2026-10-15 建立）
 * ---------------------------------------------------------------------------
 * 為什麼要有這支（使用者 2026-10-15 回報的現場問題）：
 *   圖面查閱「其他附件」分頁上，3004012550 的頁面列出了 3004012560／3004012570 的原圖。
 *   根因是那段併入 SQL 只判「**這張報價單裡有沒有這個料號的品項**」：
 *       JOIN quotation_list ql ON ql.quote_no = a.quote_no
 *       JOIN quotation_item  qi ON qi.quote_id = ql.quote_id
 *       WHERE qi.d_setting_d_id IN (本料號)
 *   **完全沒看附件自己的 linked_parts（這份檔案當初是綁給哪一個料號的）**，
 *   於是同一張報價單上所有料號的圖，會一起出現在每一個料號的頁面上。
 *   實測全站 24 個報價附件錯帶到 25 個料號頁、共 79 組錯誤顯示；
 *   其中 D1A-61017-0B-00 的頁面上列著 D1B-61017-0B-00 的原圖＝會真的拿錯圖去加工。
 *
 * 判定規則（沿用 bom_viewer「報價資料」分頁本來就正確的那一套，一字未改）：
 *   ① linked_parts 有值 → 必須 JSON_CONTAINS 命中本料號（含別名綁定帶進來的料號）才算
 *   ② linked_parts IS NULL → 才當成「整張報價單共用的附件」，靠該報價單含此料號帶出
 *
 * 為什麼收斂成一支：原本同一個判定散在三個地方各寫一次（鐵律4）——
 *   bom_viewer 其他附件併入段／bom_viewer 報價資料分頁／pref_attach_lib 優選附件，
 *   其中兩處漏了 ①，只有報價資料分頁是對的。各寫一份必定走鐘。
 *
 * 用法：
 *   require_once __DIR__ . '/quote_attach_scope_lib.php';
 *   $bel = eg_quote_attach_scope_cond($scope['part_nos'], $scope['dids']);
 *   $sql = "SELECT ... FROM quotation_attachments a
 *           WHERE a.status='active' AND {$bel['sql']} ORDER BY a.uploaded_at DESC";
 *   $stmt->execute($bel['params']);
 *   （有其他條件時，params 要照 SQL 裡 placeholder 的先後順序 array_merge）
 */

if (!function_exists('eg_quote_attach_scope_cond')) {
    /**
     * 組出「這份報價附件屬於這幾筆料號」的 WHERE 條件片段。
     *
     * @param string[] $partNos 料號文字（＝part_scope 的 part_nos，含別名綁定帶入的）
     * @param int[]    $dids    d_setting.d_id（＝part_scope 的 dids）
     * @param string   $alias   quotation_attachments 在該 SQL 裡的別名
     * @return array{sql:string, params:array} sql 已自帶外層括號，可直接接在 AND 後面
     */
    function eg_quote_attach_scope_cond(array $partNos, array $dids, string $alias = 'a'): array
    {
        // 正規化：去空、去重；別名只允許英數與底線（一律由程式碼給，不吃外部輸入）
        $partNos = array_values(array_unique(array_filter(array_map('strval', $partNos), function ($s) {
            return trim($s) !== '';
        })));
        $dids  = array_values(array_unique(array_filter(array_map('intval', $dids))));
        $alias = preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~', $alias) ? $alias : 'a';

        $branches = [];
        $params   = [];

        // ① 有綁定料號：必須命中其中一個
        if ($partNos) {
            $jsonConds = implode(' OR ', array_fill(0, count($partNos), "JSON_CONTAINS($alias.linked_parts, ?)"));
            $branches[] = "($alias.linked_parts IS NOT NULL AND ($jsonConds))";
            foreach ($partNos as $p) $params[] = json_encode($p);
        }

        // ② 沒綁定料號＝整張報價單共用：該報價單含這幾筆料號主檔就帶出
        if ($dids) {
            $ph = implode(',', array_fill(0, count($dids), '?'));
            $branches[] = "($alias.linked_parts IS NULL AND $alias.quote_no IN ("
                        . "SELECT ql.quote_no FROM quotation_item qi "
                        . "JOIN quotation_list ql ON ql.quote_id = qi.quote_id "
                        . "WHERE qi.d_setting_d_id IN ($ph)))";
            foreach ($dids as $d) $params[] = $d;
        }

        // 兩邊都空＝這次根本沒有可比對的料號，一律不列（呼叫端多半已先 early return）
        if (!$branches) return ['sql' => '(0)', 'params' => []];

        return ['sql' => '(' . implode(' OR ', $branches) . ')', 'params' => $params];
    }
}

if (!function_exists('eg_quote_attach_part_nos')) {
    /**
     * 由 d_setting.d_id 反查料號文字——給「手上只有 dids、沒有 part_scope」的呼叫端用
     * （例：pref_attach_lib 的優選附件、對帳頁）。查不到就回空陣列，呼叫端照樣跑得動。
     *
     * @param int[] $dids
     * @return string[]
     */
    function eg_quote_attach_part_nos(PDO $db, array $dids): array
    {
        $dids = array_values(array_unique(array_filter(array_map('intval', $dids))));
        if (!$dids) return [];
        try {
            $ph = implode(',', array_fill(0, count($dids), '?'));
            $st = $db->prepare("SELECT DISTINCT D_Setting_Id FROM d_setting WHERE d_id IN ($ph)");
            $st->execute($dids);
            return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), function ($s) {
                return trim((string)$s) !== '';
            }));
        } catch (Throwable $e) {
            error_log('eg_quote_attach_part_nos: ' . $e->getMessage());
            return [];
        }
    }
}
