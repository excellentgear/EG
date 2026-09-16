<?php
/**
 * AS 文件「負責人（製表／修改簽章人員）任期」共用庫 —— **唯一實作**
 *
 * 原本只寫在 `src/store/AS_Document_API.php` 裡（asEditorTerms()／asEditorOfDate()），
 * 那是一支 API 端點、include 進來會直接執行驗證與輸出，別的模組要用就只能自己再查一次
 * `as_doc_editor_term`——兩份查法遲早走鐘（鐵律4）。2026-09-16 內部稽核要「AS 負責人自動
 * 具備稽核員資格、期間比照這裡設定的任期」，所以把讀取的部分抽出來共用。
 *
 * 任期語意（與 as_document_management.php→系統設定→結構總覽列印 的畫面一致）：
 *   start_date 空＝最早（不限）、end_date 空＝至今；任期之間不可重疊（寫入端 AS_Document_API 驗證）。
 *   某個日期沒有任何任期涵蓋＝當天沒有負責人（回 null），**不可以退回「現在的負責人」**
 *   ——那正好抵銷掉依業務日期回推的意義（ai-rules/22 第一坑）。
 */

if (!function_exists('eg_asdoc_editor_ensure')) {
    function eg_asdoc_editor_ensure(PDO $db): void {
        static $done = false;
        if ($done) return;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS as_doc_editor_term (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                start_date DATE NULL COMMENT '空=最早（不限）',
                end_date DATE NULL COMMENT '空=至今',
                note VARCHAR(100) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_range (start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS文件管制總覽表－修改(製表)簽章人員任期'");
        } catch (Throwable $e) { /* 沒建表權限時不擋流程，下面查不到就回空清單 */ }
        $done = true;
    }
}

if (!function_exists('eg_asdoc_editor_terms')) {
    /** 任期清單（依起日排序；起日空者最前）。每列：id/user_id/name/start_date/end_date/note/resigned */
    function eg_asdoc_editor_terms(PDO $db): array {
        eg_asdoc_editor_ensure($db);
        try {
            $rows = $db->query("SELECT t.id, t.user_id, t.start_date, t.end_date, t.note, u.user_cname, u.state
                                FROM as_doc_editor_term t LEFT JOIN `user` u ON u.id = t.user_id
                                ORDER BY COALESCE(t.start_date,'0001-01-01') ASC, t.id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        return array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'user_id'    => (int)$r['user_id'],
            'name'       => (string)($r['user_cname'] ?? ''),
            'start_date' => $r['start_date'],
            'end_date'   => $r['end_date'],
            'note'       => (string)($r['note'] ?? ''),
            'resigned'   => ((int)($r['state'] ?? 1) === 0),
        ], $rows);
    }
}

if (!function_exists('eg_asdoc_editor_at')) {
    /**
     * 某日期當時的 AS 文件負責人；沒有任期涵蓋該日＝回 null（**不退回現況**）。
     * @param array|null $terms 先取好的任期清單（不給就自己查一次）
     */
    function eg_asdoc_editor_at(PDO $db, string $date, ?array $terms = null): ?array {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', trim($date))) return null;
        $terms = $terms ?? eg_asdoc_editor_terms($db);
        foreach ($terms as $t) {
            if ($t['start_date'] && $date < $t['start_date']) continue;
            if ($t['end_date']   && $date > $t['end_date'])   continue;
            if ($t['name'] === '') continue;
            return ['id' => $t['user_id'], 'name' => $t['name'], 'term_id' => $t['id']];
        }
        return null;
    }
}
