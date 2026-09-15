<?php
/**
 * 料號附件的兩個「逐標籤開關」功能 —— 唯一實作，禁止各頁自刻（鐵律4）
 * ---------------------------------------------------------------------------
 * 2026-09-08 使用者要求，兩件事都是「只有某些附件標籤才需要」，所以一律由
 * quotation_file_categories 上的旗標逐一勾選，不寫死標籤名稱：
 *
 *   1. quote_bindable ＝ 這個標籤跟報價單共用（例：原圖、報價圖）
 *      → 上傳／編輯料號附件時多出「綁定報價單」欄位（可不選）。
 *        起因：本來應該傳在報價單裡的附件，業務補傳到料號附件時，報價單上就看不到了。
 *      **只建關聯不複製檔案**（使用者拍板）：附件本體仍然只有料號附件那一份，
 *        報價單的附件清單另外把綁定過來的料號附件一起列出來，所以事後換檔／旋轉
 *        兩邊自動同步，不會出現「報價單那份還是舊的」。
 *
 *   2. need_maker ＝ 這個標籤的附件會有廠商資訊（例：外包廠商提供的規格書）
 *      → 上傳／編輯時多出「廠商」欄位（選填），來源是 maker_list。
 *
 * 兩個旗標預設都是 0，也就是**不勾就跟現在完全一樣**，既有附件與既有操作不受影響。
 */

if (!function_exists('pal_ensure_schema')) {

/** 補建欄位（可重複執行；舊環境沒有欄位時自動長出來） */
function pal_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // 標籤上的兩個開關
    try { $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN quote_bindable TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=此標籤與報價單共用，料號附件可綁定報價單'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN need_maker TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=此標籤的附件要填廠商（選填欄位，來源 maker_list）'"); } catch (Exception $e) {}
    // 附件上的兩個值
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN quote_no VARCHAR(30) NULL COMMENT '綁定的報價單號（只建關聯不複製檔案；NULL=未綁定）'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN maker_no VARCHAR(11) NULL COMMENT '廠商 maker_list.maker_id_no（選填）'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE part_attachments ADD INDEX idx_quote_no (quote_no)"); } catch (Exception $e) {}
}

/** 有勾「可綁定報價單」的標籤 id（字串陣列，給前端比對用） */
function pal_quote_bind_cat_ids(PDO $pdo): array {
    pal_ensure_schema($pdo);
    try {
        return array_map('strval', $pdo->query("SELECT id FROM quotation_file_categories WHERE COALESCE(quote_bindable,0)=1")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { return []; }
}

/** 有勾「要填廠商」的標籤 id */
function pal_maker_cat_ids(PDO $pdo): array {
    pal_ensure_schema($pdo);
    try {
        return array_map('strval', $pdo->query("SELECT id FROM quotation_file_categories WHERE COALESCE(need_maker,0)=1")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { return []; }
}

/* ── 報價單附件也要有廠商欄位（2026-09-15 使用者要求）───────────────────────
   `need_maker` 這個標籤旗標本來只有料號附件在吃（master_data_management），
   報價單附件那邊完全沒接，所以「加工廠報價」這種標籤明明早就勾了要填廠商，
   在報價單裡卻連欄位都看不到。這裡把同一個旗標接到報價單附件，**不另建旗標**。

   必填則是另一個旗標 `maker_required_quote`：使用者拍板**只在報價單附件生效**
   （料號附件維持選填，不受影響），預設 0＝跟現在完全一樣。 */

/** 補建報價單附件這邊需要的欄位（可重複執行） */
function pal_ensure_quote_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    pal_ensure_schema($pdo);
    try { $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN maker_required_quote TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=報價單附件掛此標籤時廠商必填（料號附件不受影響）'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE quotation_attachments ADD COLUMN maker_no VARCHAR(11) NULL COMMENT '廠商 maker_list.maker_id_no（標籤有勾 need_maker 才會用到）'"); } catch (Exception $e) {}
}

/** 報價單附件「廠商必填」的標籤 id（要同時勾 need_maker 才算數：沒有欄位就談不上必填） */
function pal_maker_required_quote_cat_ids(PDO $pdo): array {
    pal_ensure_quote_schema($pdo);
    try {
        return array_map('strval', $pdo->query(
            "SELECT id FROM quotation_file_categories
              WHERE COALESCE(need_maker,0)=1 AND COALESCE(maker_required_quote,0)=1")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { return []; }
}

/**
 * 廠商自動完成的查詢（報價單附件的廠商欄位用；料號主檔那邊有自己更早的一份，
 * 欄位與輸出格式相同，不為了統一去動那支 1.5MB 的巨檔）。
 * 已停用的廠商（status='X'）不列。
 */
function pal_maker_search(PDO $pdo, string $kw, int $limit = 15): array {
    $kw = trim($kw);
    if ($kw === '') return [];
    $limit = max(1, min(50, $limit));
    try {
        $st = $pdo->prepare(
            "SELECT maker_id_no, COALESCE(maker_id,'') AS maker_short, COALESCE(maker_id_all,'') AS maker_full
               FROM maker_list
              WHERE (maker_id_no LIKE :kw OR maker_id LIKE :kw OR maker_id_all LIKE :kw)
                AND (status IS NULL OR status <> 'X')
              ORDER BY maker_id_no LIMIT $limit");
        $st->execute([':kw' => '%' . $kw . '%']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/** 這組標籤裡有沒有「可綁定報價單」的（決定要不要顯示欄位） */
function pal_cats_hit(array $catIds, array $flagIds): bool {
    foreach ($catIds as $c) {
        if (in_array((string)trim((string)$c), $flagIds, true)) return true;
    }
    return false;
}

/**
 * 這個料號可以綁定的報價單清單（新到舊）。
 * 只列「這張報價單真的有這個料號」的，避免綁到不相干的報價單。
 */
function pal_quote_candidates(PDO $pdo, int $dId, int $limit = 50): array {
    if ($dId <= 0) return [];
    $sql = "SELECT ql.quote_no, ql.quote_date, COALESCE(ql.client_name,'') AS client_name,
                   COALESCE(ql.is_draft,0) AS is_draft
              FROM quotation_item qi
              JOIN quotation_list ql ON ql.quote_id = qi.quote_id
             WHERE qi.d_setting_d_id = ?
             GROUP BY ql.quote_no, ql.quote_date, ql.client_name, ql.is_draft
             ORDER BY ql.quote_date DESC, ql.quote_no DESC
             LIMIT " . max(1, (int)$limit);
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$dId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/**
 * 存檔前驗證報價單號（前端擋一次、後端同規則再擋一次＝鐵律8）。
 * 空字串＝不綁定（合法）。回傳正規化後的單號；不合法時丟例外。
 */
function pal_check_quote_no(PDO $pdo, int $dId, string $quoteNo): ?string {
    $quoteNo = trim($quoteNo);
    if ($quoteNo === '') return null;
    if (mb_strlen($quoteNo, 'UTF-8') > 30) throw new Exception('報價單號過長');
    foreach (pal_quote_candidates($pdo, $dId, 500) as $q) {
        if ((string)$q['quote_no'] === $quoteNo) return $quoteNo;
    }
    throw new Exception('這張報價單沒有這個料號，不能綁定（請改選這個料號有出現過的報價單）');
}

/** 存檔前驗證廠商代號。空字串＝不填（合法）。 */
function pal_check_maker_no(PDO $pdo, string $makerNo): ?string {
    $makerNo = trim($makerNo);
    if ($makerNo === '') return null;
    if (mb_strlen($makerNo, 'UTF-8') > 11) throw new Exception('廠商代號過長');
    $st = $pdo->prepare("SELECT maker_id_no FROM maker_list WHERE maker_id_no=? LIMIT 1");
    $st->execute([$makerNo]);
    $hit = $st->fetchColumn();
    if (!$hit) throw new Exception('查無此廠商代號');
    return (string)$hit;
}

/** 廠商代號 → 顯示名稱（給清單顯示用；查不到就回代號本身） */
function pal_maker_names(PDO $pdo, array $makerNos): array {
    $makerNos = array_values(array_unique(array_filter(array_map('strval', $makerNos), function ($x) { return $x !== ''; })));
    if (!$makerNos) return [];
    $ph = implode(',', array_fill(0, count($makerNos), '?'));
    try {
        $st = $pdo->prepare("SELECT maker_id_no, COALESCE(NULLIF(maker_id,''), maker_id_all) AS nm FROM maker_list WHERE maker_id_no IN ($ph)");
        $st->execute($makerNos);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(string)$r['maker_id_no']] = (string)$r['nm'];
        return $map;
    } catch (Exception $e) { return []; }
}

/**
 * 某些料號「綁了報價單」的料號附件（給各種以報價單分組的檢視畫面用）。
 * 2026-09-08 使用者回報：綁定之後在 bom_viewer 的報價單分組裡看不到——那些畫面
 * 只讀 quotation_attachments，所以這裡把「同一批料號底下、已綁報價單」的料號附件
 * 一起撈出來，讓呼叫端併進對應的報價單群組。
 * 回傳的是原始列（含 quote_no），過濾（批圖工作檔、價格類標籤）由呼叫端比照它
 * 原本對料號附件的規則處理——因為這些本來就是料號附件，規則必須一致。
 */
function pal_bound_part_attachments_for_dids(PDO $pdo, array $dids): array {
    pal_ensure_schema($pdo);
    $dids = array_values(array_unique(array_filter(array_map('intval', $dids))));
    if (!$dids) return [];
    $ph = implode(',', array_fill(0, count($dids), '?'));
    try {
        $st = $pdo->prepare(
            "SELECT pa.id, pa.d_id, pa.filename, pa.original_name, pa.category_ids,
                    pa.file_size, pa.note, pa.revision, pa.issue_stamp_date, pa.album_id,
                    pa.quote_no, COALESCE(pa.maker_no,'') AS maker_no,
                    COALESCE(NULLIF(ml.maker_id,''), ml.maker_id_all, '') AS maker_name,
                    COALESCE(u.user_cname, pa.uploaded_by) AS uploaded_by, pa.uploaded_at
               FROM part_attachments pa
               LEFT JOIN user u ON u.id = pa.uploaded_by_id
               LEFT JOIN maker_list ml ON ml.maker_id_no = pa.maker_no
              WHERE pa.d_id IN ($ph) AND pa.deleted_at IS NULL
                AND pa.quote_no IS NOT NULL AND pa.quote_no <> ''
              ORDER BY pa.uploaded_at DESC");
        $st->execute($dids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/**
 * 某張報價單「由料號附件綁進來」的附件（給報價單附件清單一起列出來用）。
 * 回傳欄位刻意排成跟 quotation_attachments 的清單一致，呼叫端只要接上去就好；
 * from_part=1 讓呼叫端知道這是唯讀的外來列（不可在報價單那邊刪改）。
 */
function pal_quote_linked_part_attachments(PDO $pdo, string $quoteNo): array {
    pal_ensure_schema($pdo);
    $quoteNo = trim($quoteNo);
    if ($quoteNo === '') return [];
    try {
        $st = $pdo->prepare(
            "SELECT pa.id, pa.d_id, pa.filename, pa.original_name, pa.category_ids,
                    pa.file_size, pa.note, pa.revision, pa.uploaded_at,
                    COALESCE(u.user_cname, pa.uploaded_by) AS uploaded_by,
                    COALESCE(ds.D_Setting_Id,'') AS part_no,
                    COALESCE(pa.maker_no,'') AS maker_no,
                    COALESCE(NULLIF(ml.maker_id,''), ml.maker_id_all, '') AS maker_name
               FROM part_attachments pa
               LEFT JOIN user u ON u.id = pa.uploaded_by_id
               LEFT JOIN maker_list ml ON ml.maker_id_no = pa.maker_no
               LEFT JOIN d_setting ds ON ds.d_id = pa.d_id
              WHERE pa.quote_no = ? AND pa.deleted_at IS NULL
              ORDER BY pa.uploaded_at DESC, pa.id DESC");
        $st->execute([$quoteNo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['from_part'] = 1; }
        return $rows;
    } catch (Exception $e) { return []; }
}

}
