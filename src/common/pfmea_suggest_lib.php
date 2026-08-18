<?php
/**
 * PFMEA(3-TD-01-02) — 建議建立清單 共用庫（2026-08-13 使用者明確要求）。
 * 跟產品開發評估表(td_dev_eval)的建議建立清單不同：來源不是掃描訂單/報工/BOM/出貨原始表，
 * 而是直接抓「已經有建立 td_dev_eval 紀錄的料號」當候選（已經做過產品開發評估的料號，理論上
 * 都該有對應的 PFMEA 分析），排除已存在 pfmea_doc 紀錄的部分即可，不需要客戶名單設定或日期區間。
 */

/** 候選清單：td_dev_eval 已有紀錄、pfmea_doc 還沒有紀錄的客戶+料號組合 */
function pfmea_suggest_candidates(PDO $db): array {
    // biz_date：轉入的PFMEA沿用該筆td_dev_eval的「填表日期」(fill_date，即紙本表單上那個日期欄位)。
    // 2026-08-18 使用者更正：原本抓的是 created_at（資料列被建進資料庫的時間戳），那是「什麼時候
    // 被登錄進系統」不是「表單上寫的日期」，補登歷史單據時兩者可能差很多。fill_date 為空的舊資料
    // 才退回 created_at，避免完全沒有日期可用。同一料號有多筆紀錄時取最早的那張最有參考價值。
    $rows = $db->query("SELECT customer_name, part_d_id, part_no_text, MIN(product_name) AS product_name,
                                MIN(COALESCE(fill_date, DATE(created_at))) AS td_dev_eval_fill_date
                         FROM td_dev_eval WHERE is_deleted=0
                           AND (part_d_id IS NOT NULL OR (part_no_text IS NOT NULL AND part_no_text<>''))
                         GROUP BY customer_name, part_d_id, part_no_text
                         ORDER BY customer_name, part_no_text")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    $exist = $db->query("SELECT part_d_id, part_no_text FROM pfmea_doc WHERE is_deleted=0")->fetchAll(PDO::FETCH_ASSOC);
    $existSet = [];
    foreach ($exist as $e) {
        if ($e['part_d_id']) $existSet['D'.$e['part_d_id']] = true;
        if ($e['part_no_text']) $existSet['T'.$e['part_no_text']] = true;
    }

    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $key = $r['part_d_id'] ? 'D'.$r['part_d_id'] : 'T'.$r['part_no_text'];
        if (isset($existSet[$key]) || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $r;
    }
    return $out;
}

/**
 * 批次建立：$rows 每筆 [customer_name, part_d_id, part_no_text, product_name]，只建立表頭殼
 * （比照 td_dev_eval_suggest_bulk_create 慣例，分析項目仍需逐一填寫，不代填）；
 * 分類(零件/組合件)自動從料號 d_setting.Is_Assembly 帶入，查無料號時預設「零件」。
 * 2026-08-18 使用者要求補兩件事：
 *  ①自動綁定料號——來源 td_dev_eval 有些列只有純文字料號沒有 d_setting 主鍵，照抄過來的 PFMEA
 *    就變成沒綁定的孤兒（開不了圖、客戶名稱空白、料號+製程的要求也對不上），改成建立當下用料號
 *    文字回查 d_setting 主鍵，查得到就綁定，查不到才退回純文字。
 *  ②自動帶入預設相關部門——手動新建走的是前端預設勾選，批次建立先前完全沒帶，開起來整排沒勾。
 */
function pfmea_suggest_bulk_create(PDO $db, array $rows, int $uid, string $uname): array {
    $created = 0; $errors = [];
    $deptDefaults = implode(',', pfmea_dept_defaults_get($db));
    foreach ($rows as $row) {
        $partDId = !empty($row['part_d_id']) ? (int)$row['part_d_id'] : null;
        $partText = trim((string)($row['part_no_text'] ?? ''));
        if (!$partDId && $partText === '') { $errors[] = '缺少料號，略過'; continue; }
        // 純文字料號回查主檔，查得到就自動綁定（同一料號號碼在 d_setting 可能有多筆，取最小 d_id
        // ——比照全站「重複料號一律歸戶到 MIN(d_id)」的既有慣例）
        if (!$partDId && $partText !== '') {
            $st = $db->prepare("SELECT MIN(d_id) FROM d_setting WHERE D_Setting_Id=?");
            $st->execute([$partText]);
            $found = (int)$st->fetchColumn();
            if ($found) $partDId = $found;
        }
        try {
            $itemType = 'part';
            if ($partDId) {
                $st = $db->prepare("SELECT Is_Assembly FROM d_setting WHERE d_id=?");
                $st->execute([$partDId]);
                if ((int)$st->fetchColumn() === 1) $itemType = 'assembly';
            }
            $docNo = pfmea_next_doc_no($db);
            $bizDate = trim((string)($row['td_dev_eval_fill_date'] ?? '')) ?: null;
            $st = $db->prepare("INSERT INTO pfmea_doc (doc_no, part_d_id, part_no_text, item_type, product_name, related_depts, biz_date, created_by, created_by_name)
                                 VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute([$docNo, $partDId, $partDId ? null : $partText, $itemType, $row['product_name'] ?? null, $deptDefaults ?: null, $bizDate, $uid, $uname]);
            pfmea_revision_add($db, (int)$db->lastInsertId(), '新增文件', $uname, $bizDate);
            $created++;
        } catch (Throwable $e) { $errors[] = ($partText ?: '(無料號)').'：'.$e->getMessage(); }
    }
    return ['created'=>$created, 'errors'=>$errors];
}

/** pfmea.php 頁首提醒用：候選筆數 */
function pfmea_suggest_pending_count(PDO $db): int {
    try { return count(pfmea_suggest_candidates($db)); } catch (Throwable $e) { return 0; }
}
