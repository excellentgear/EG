<?php
/**
 * pref_attach_lib.php — 「優選顯示在 BOM 總覽的料號查閱畫面內」附件標籤（唯一實作）
 * =====================================================================
 * 起因（使用者 2026-09-01 交辦）：BOM 總覽（OreadyReply_ForPm_BaseOfTime.php）點料號開出來的
 * 料號查閱頁（views/pm/part_viewer.php），原本只把該料號的「料號附件」照上傳時間列出來；
 * 現場真正想在對圖時一起看的是特定幾種文件（例：BOSS 批製程價格），而它們可能掛在
 * 料號附件、也可能掛在報價附件／訂單附件底下——這正是 bom_viewer.php 用
 * `show_in_other_attach` 把三種來源併成一個分頁在做的事。
 *
 * 所以這裡做的是同一種機制、但另一個獨立旗標：
 *   quotation_file_categories.show_in_part_viewer = 1
 *     → 這個標籤的附件（不論原本上傳在料號／報價／訂單哪一邊）
 *       一律另外收進料號查閱頁最上方的「優選附件」區塊。
 *
 * ★ 與 show_in_other_attach 最大的不同：這一區是「有價格的資料」，
 *   **只有在 BOM 總覽看得到加工單價的人才可以看**（使用者明確要求）。
 *   資格判定唯一入口 = role_features_helper.php 的 oready_resolve_can_view_price()，
 *   而且是**後端不回傳**（不是只把畫面藏起來），否則直接打 API 就繞過去了（鐵律8）。
 *
 * 標籤怎麼勾：料號主檔 → 字典 → 附件類別標籤設定（views/pages/master_data_management.php）。
 * 一律不要在別處寫死「BOSS批製程價格」這種標籤名稱（鐵律4：使用者改名／刪除後那份寫死的
 * 對照會繼續顯示舊內容而且不報錯）。
 */

require_once __DIR__ . '/role_features_helper.php';

/** 補欄位（可重複執行）。舊環境沒有這一欄時自動補上，不必另外跑 migration。 */
if (!function_exists('eg_pref_attach_ensure_schema')) {
    function eg_pref_attach_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $db->exec("ALTER TABLE quotation_file_categories
                       ADD COLUMN show_in_part_viewer TINYINT(1) NOT NULL DEFAULT 0
                       COMMENT '優選顯示在BOM總覽的料號查閱畫面內(需查看加工單價權限)'");
        } catch (PDOException $e) { /* 已存在 */ }
    }
}

/** 被勾選為「優選顯示」的附件類別 id（一次查、靜態快取；停用的標籤不算） */
if (!function_exists('eg_pref_attach_cat_ids')) {
    function eg_pref_attach_cat_ids(PDO $db): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        eg_pref_attach_ensure_schema($db);
        try {
            $ids = $db->query("SELECT id FROM quotation_file_categories
                               WHERE show_in_part_viewer = 1 AND is_active = 1")
                      ->fetchAll(PDO::FETCH_COLUMN);
            $cache = array_values(array_map('intval', $ids));
        } catch (Throwable $e) { $cache = []; }
        return $cache;
    }
}

/**
 * 這筆附件的 category_ids（逗號字串）有沒有命中任何一個優選標籤。
 * 一筆附件可以同時掛好幾個標籤，只要命中其中一個就算優選（＝也就跟著受權限管制）。
 */
if (!function_exists('eg_pref_attach_hit')) {
    function eg_pref_attach_hit(?string $categoryIds, array $prefIds): bool
    {
        if (!$prefIds || $categoryIds === null || $categoryIds === '') return false;
        foreach (explode(',', $categoryIds) as $cid) {
            $cid = (int)trim($cid);
            if ($cid > 0 && in_array($cid, $prefIds, true)) return true;
        }
        return false;
    }
}

/**
 * 這個人可不可以看優選附件（＝BOM 總覽的「查看加工單價」資格）。
 * fail-closed：判不出來一律不給看。
 */
if (!function_exists('eg_pref_attach_can_view')) {
    function eg_pref_attach_can_view(PDO $db, int $uid): bool
    {
        if ($uid <= 0) return false;
        return oready_resolve_can_view_price($db, $uid);
    }
}

/**
 * 取某幾筆料號主檔（d_setting.d_id）的優選附件，三種來源合併：
 *   料號附件 part_attachments／報價附件 quotation_attachments／訂單附件 order_attachments
 *
 * ★ 呼叫端**必須自己先確認權限**（eg_pref_attach_can_view），這支不重複擋，
 *   因為有些呼叫端（對帳頁）用的是自己的一份標籤設定與權限規則。
 *
 * @param array      $dids     d_setting.d_id 陣列
 * @param array|null $catIds   要撈哪些標籤；null＝用 show_in_part_viewer 勾選的那些
 * @param string     $urlBase  附件下載 API 的相對路徑前綴（各頁位置不同，例 '../../'）
 * @return array 每筆：id/display_name/filename/url/ext/type/file_size/note/uploaded_by/uploaded_at/category_names/source
 */
if (!function_exists('eg_pref_attach_fetch')) {
    function eg_pref_attach_fetch(PDO $db, array $dids, ?array $catIds = null, string $urlBase = '../../'): array
    {
        $dids = array_values(array_unique(array_filter(array_map('intval', $dids))));
        if (!$dids) return [];
        $ids = $catIds === null ? eg_pref_attach_cat_ids($db) : array_values(array_filter(array_map('intval', $catIds)));
        if (!$ids) return [];

        // 標籤名稱對照（顯示用）
        $cats = [];
        try {
            foreach ($db->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cats[(int)$c['id']] = $c['category_name'];
            }
        } catch (Throwable $e) {}
        $catNamesOf = function ($csv) use ($cats) {
            $out = [];
            foreach (array_filter(explode(',', (string)$csv)) as $cid) {
                $cid = (int)trim($cid);
                if (isset($cats[$cid])) $out[] = $cats[$cid];
            }
            return $out;
        };
        $typeOf = function ($ext) {
            return in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true) ? 'image'
                 : ($ext === 'pdf' ? 'pdf' : 'other');
        };

        // FIND_IN_SET 對「1, 22」這種帶空白的 CSV 會比不到，先把空白拿掉再比
        $phD     = implode(',', array_fill(0, count($dids), '?'));
        $catCond = implode(' OR ', array_fill(0, count($ids), "FIND_IN_SET(?, REPLACE(a.category_ids,' ',''))"));
        $out     = [];

        // ── 1. 料號附件 ──────────────────────────────────────────────
        try {
            $st = $db->prepare("SELECT a.id, a.d_id, a.filename, a.original_name, a.category_ids, a.file_size,
                                       a.note, a.uploaded_at, COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by
                                FROM part_attachments a
                                LEFT JOIN user u ON u.id = a.uploaded_by_id
                                WHERE a.d_id IN ($phD) AND a.deleted_at IS NULL AND ($catCond)
                                ORDER BY a.uploaded_at DESC");
            $st->execute(array_merge($dids, $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
                $out[] = [
                    'id'             => (int)$r['id'],
                    'filename'       => $r['filename'],
                    'display_name'   => $r['original_name'] ?: $r['filename'],
                    'url'            => $urlBase . 'src/store/Part_Attachment_API.php?action=download&id=' . (int)$r['id'],
                    'ext'            => $ext,
                    'type'           => $typeOf($ext),
                    'file_size'      => $r['file_size'] ?: '',
                    'note'           => $r['note'] ?: '',
                    'uploaded_by'    => $r['uploaded_by'] ?: '',
                    'uploaded_at'    => substr((string)$r['uploaded_at'], 0, 16),
                    'category_names' => $catNamesOf($r['category_ids']),
                    'source'         => 'part',
                    'source_label'   => '料號附件',
                ];
            }
        } catch (Throwable $e) {}

        // ── 2. 報價附件（該報價單含這幾筆料號主檔；只取正式附件）──────
        try {
            $st = $db->prepare("SELECT DISTINCT a.id, a.filename, a.original_name, a.category_ids, a.file_size,
                                       a.uploaded_at, a.quote_no,
                                       COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by
                                FROM quotation_attachments a
                                LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
                                JOIN quotation_list ql ON ql.quote_no = a.quote_no
                                JOIN quotation_item  qi ON qi.quote_id = ql.quote_id
                                WHERE a.status = 'active' AND qi.d_setting_d_id IN ($phD) AND ($catCond)
                                ORDER BY a.uploaded_at DESC");
            $st->execute(array_merge($dids, $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
                $out[] = [
                    'id'             => 'q' . (int)$r['id'],
                    'filename'       => $r['filename'],
                    'display_name'   => $r['original_name'] ?: $r['filename'],
                    'url'            => $urlBase . 'src/store/Quotation_File_API.php?action=download&quote_no='
                                        . rawurlencode((string)$r['quote_no']) . '&filename=' . rawurlencode((string)$r['filename']),
                    'ext'            => $ext,
                    'type'           => $typeOf($ext),
                    'file_size'      => $r['file_size'] ?: '',
                    'note'           => '來自報價單 ' . $r['quote_no'],
                    'uploaded_by'    => $r['uploaded_by'] ?: '',
                    'uploaded_at'    => substr((string)$r['uploaded_at'], 0, 16),
                    'category_names' => $catNamesOf($r['category_ids']),
                    'source'         => 'quote',
                    'source_label'   => '報價附件',
                ];
            }
        } catch (Throwable $e) {}

        // ── 3. 訂單附件 ──────────────────────────────────────────────
        try {
            $st = $db->prepare("SELECT a.id, a.filename, a.original_name, a.category_ids, a.file_size,
                                       a.uploaded_at, ot.Order_oo,
                                       COALESCE(u.user_cname, a.uploaded_by) AS uploaded_by
                                FROM order_attachments a
                                JOIN order_track ot ON ot.Order_id = a.order_id
                                LEFT JOIN user u ON u.id = a.uploaded_by
                                WHERE a.status = 'active' AND ot.d_id_ID IN ($phD) AND ($catCond)
                                ORDER BY a.uploaded_at DESC");
            $st->execute(array_merge($dids, $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
                $out[] = [
                    'id'             => 'o' . (int)$r['id'],
                    'filename'       => $r['filename'],
                    'display_name'   => $r['original_name'] ?: $r['filename'],
                    'url'            => $urlBase . 'src/store/Order_Attachment_API.php?action=download&id=' . (int)$r['id'],
                    'ext'            => $ext,
                    'type'           => $typeOf($ext),
                    'file_size'      => $r['file_size'] ?: '',
                    'note'           => '來自訂單 ' . $r['Order_oo'],
                    'uploaded_by'    => $r['uploaded_by'] ?: '',
                    'uploaded_at'    => substr((string)$r['uploaded_at'], 0, 16),
                    'category_names' => $catNamesOf($r['category_ids']),
                    'source'         => 'order',
                    'source_label'   => '訂單附件',
                ];
            }
        } catch (Throwable $e) {}

        // 新到舊（三種來源混排，對圖時一律先看最新的）
        usort($out, function ($a, $b) { return strcmp($b['uploaded_at'], $a['uploaded_at']); });
        return $out;
    }
}
