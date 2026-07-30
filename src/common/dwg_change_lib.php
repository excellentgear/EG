<?php
/**
 * dwg_change_lib.php — 圖面變更：schema／判定／建立變更紀錄（唯一實作點）
 *
 * 判準完整說明見 `ai-rules/15-圖面變更判定依據.md`，這裡只講重點：
 * 判斷「這張圖是不是改過」一律用 **發行章日期**，不是版次。
 *   - 很多客戶圖面根本沒有版次，版次不能設必填也不能當判準
 *   - 同一標籤下不同版次可以並存、舊的不一定會被作廢，所以「作廢」不是可靠訊號
 *   - 原圖（客戶圖）更新常常只是報價階段更新、根本還沒接單，不可觸發
 * 因此只有「自家出的圖」標籤（quotation_file_categories.is_own_drawing=1，
 * 預設是 BOSS圖／++圖／單製 ++圖）要填發行章日期並參與判定。
 *
 * 禁止各頁自己寫這段判定或自己 INSERT qc_drawing_change——會像過去的側欄/輸入欄位一樣失守。
 */

require_once __DIR__ . '/dwg_notify.php';

if (!function_exists('dwg_ensure_schema')) {

/** 欄位補建（沿用本專案慣例：ALTER 包 try，重複執行無害）。sql.php 擋 DDL，故走程式面 migration。 */
function dwg_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN issue_stamp_date DATE NULL COMMENT '發行章日期（自家出圖蓋章日；圖面變更新舊依據，預設帶上傳日可改）'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE part_attachments ADD INDEX idx_issue_stamp (d_id, issue_stamp_date)"); } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN is_own_drawing TINYINT NOT NULL DEFAULT 0 COMMENT '1=自家出的圖（需填發行章日期並參與圖面變更判定）'");
        // 只有「剛加上這欄」時才預設勾這三個；之後由使用者在標籤設定自行調整，不再被覆寫
        $pdo->exec("UPDATE quotation_file_categories SET is_own_drawing=1 WHERE category_name IN ('BOSS圖','++圖','單製 ++圖')");
    } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN trigger_attachment_id INT NULL COMMENT '觸發此變更的料號附件 part_attachments.id（由附件上傳自動判定產生時才有）'"); } catch (Throwable $e) {}
}

/** 「自家出的圖」標籤 id 清單 */
function dwg_own_drawing_cat_ids(PDO $pdo): array {
    dwg_ensure_schema($pdo);
    try {
        $r = $pdo->query("SELECT id FROM quotation_file_categories WHERE is_own_drawing=1 AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $r);
    } catch (Throwable $e) { return []; }
}

/** 傳入的標籤裡有沒有「自家出的圖」——有就代表這次上傳要填發行章日期 */
function dwg_needs_issue_date(PDO $pdo, array $catIds): bool {
    $own = dwg_own_drawing_cat_ids($pdo);
    if (!$own) return false;
    return (bool)array_intersect(array_map('intval', $catIds), $own);
}

/**
 * 判定這次上傳是「首次發行」還是「圖面變更」。
 *
 * 比對範圍＝同一料號、標籤同樣是「自家出的圖」的既有附件（不分是哪一種圖，
 * 因為 BOSS圖與 ++圖 是同一次出圖的不同呈現，分開比會把一次變更算成兩次）。
 *
 * @return array{kind:string, prev_date:?string, prev_name:?string, message:string, needs_date:bool}
 *   kind: none=不是自家出圖標籤不判定／first=首次發行／change=變更／same=補件或重掃／older=補登舊版
 */
function dwg_classify_upload(PDO $pdo, int $dId, array $catIds, ?string $issueDate): array {
    $out = ['kind' => 'none', 'prev_date' => null, 'prev_name' => null, 'message' => '',
            'needs_date' => false, 'issue_date' => $issueDate];
    if (!dwg_needs_issue_date($pdo, $catIds)) return $out;
    $out['needs_date'] = true;
    if (!$issueDate) { $out['message'] = '此標籤屬於「自家出的圖」，必須填發行章日期'; return $out; }

    $own = dwg_own_drawing_cat_ids($pdo);
    $prev = null;
    try {
        // FIND_IN_SET 逐一比對（category_ids 是逗號字串），取發行章日期最新的一筆
        $ors = implode(' OR ', array_fill(0, count($own), 'FIND_IN_SET(?, pa.category_ids)'));
        $st = $pdo->prepare("SELECT pa.id, pa.original_name, pa.issue_stamp_date
                             FROM part_attachments pa
                             WHERE pa.d_id=? AND pa.deleted_at IS NULL
                               AND pa.issue_stamp_date IS NOT NULL AND ($ors)
                             ORDER BY pa.issue_stamp_date DESC, pa.id DESC LIMIT 1");
        $st->execute(array_merge([$dId], $own));
        $prev = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return $out; }

    if (!$prev) {
        $out['kind'] = 'first';
        $out['message'] = '此料號第一次有帶發行章日期的自家圖面＝首次發行，不需要登錄圖面變更。';
        return $out;
    }
    $out['prev_date'] = (string)$prev['issue_stamp_date'];
    $out['prev_name'] = (string)($prev['original_name'] ?? '');
    $cmp = strcmp($issueDate, $out['prev_date']);
    if ($cmp > 0) {
        $out['kind'] = 'change';
        $out['message'] = '發行章日期比前一版（' . $out['prev_date'] . '）新＝這是一次圖面變更，請填寫變更內容。';
    } elseif ($cmp === 0) {
        $out['kind'] = 'same';
        $out['message'] = '發行章日期與前一版相同（' . $out['prev_date'] . '）＝視為補件／重掃，不另開變更紀錄。';
    } else {
        $out['kind'] = 'older';
        $out['message'] = '發行章日期比現有最新版（' . $out['prev_date'] . '）舊＝視為補登舊版，不另開變更紀錄。';
    }
    return $out;
}

/**
 * 建立一筆圖面變更紀錄，並做三件事（與 views/QC/drawing_change_log.php 手動登錄同一套）：
 *   ① 把該料號目前生效的檢驗標準整組複製成新版次，舊版停用但保留（舊檢驗紀錄仍追溯得到當時標準）
 *   ② 寫入簽收名單
 *   ③ 通知尚未簽收的人（行動型，沒簽會一直留在置頂未讀）
 *
 * 呼叫端負責權限檢查與 CSRF；本函式自行開 transaction（呼叫端不要先開）。
 *
 * @param array $p d_id, summary 必填；old_revision,new_revision,change_date,source,customer_doc_no,
 *                 from_process_no,detail,ack_users[],created_by,trigger_attachment_id 選填
 * @return array{id:int, change_no:string, new_version_id:?int, old_version_id:?int}
 */
function dwg_create_change(PDO $pdo, array $p): array {
    dwg_ensure_schema($pdo);
    $dId = (int)($p['d_id'] ?? 0);
    $summary = trim((string)($p['summary'] ?? ''));
    if ($dId <= 0)      throw new Exception('請選擇料號');
    if ($summary === '') throw new Exception('請填寫變更摘要');
    $oldRev = trim((string)($p['old_revision'] ?? ''));
    $newRev = trim((string)($p['new_revision'] ?? ''));
    $fromP  = (($p['from_process_no'] ?? '') === '' || $p['from_process_no'] === null) ? null : (int)$p['from_process_no'];
    $uid    = (int)($p['created_by'] ?? 0);
    $ackIds = array_values(array_unique(array_filter(array_map('intval', (array)($p['ack_users'] ?? [])))));
    $trigId = ($p['trigger_attachment_id'] ?? null) ? (int)$p['trigger_attachment_id'] : null;

    $pdo->beginTransaction();
    try {
        // 變更單號 DWG-YYYYMM-nnn（同月流水）
        $ym = date('Ym');
        $n  = (int)$pdo->query("SELECT COUNT(*) FROM qc_drawing_change WHERE change_no LIKE 'DWG-$ym-%'")->fetchColumn() + 1;
        $changeNo = sprintf('DWG-%s-%03d', $ym, $n);

        // ── 檢驗標準：目前生效版本整組複製成新版本，舊版停用但保留 ──
        $oldVerId = $pdo->query("SELECT version_id FROM qc_inspection_version WHERE d_id=" . $dId . " AND is_active=1 ORDER BY version_id DESC LIMIT 1")->fetchColumn();
        $oldVerId = $oldVerId ? (int)$oldVerId : null;
        $newVerId = null;
        if ($oldVerId) {
            $label = $newRev !== '' ? mb_substr($newRev, 0, 30) : ('變更 ' . date('Y-m-d'));
            $pdo->prepare("INSERT INTO qc_inspection_version (d_id, version_label, source_type, is_active) VALUES (?,?, 'REVISION', 1)")
                ->execute([$dId, $label]);
            $newVerId = (int)$pdo->lastInsertId();

            $src = $pdo->prepare("SELECT * FROM qc_inspection_item WHERE version_id=?");
            $src->execute([$oldVerId]);
            $insI = $pdo->prepare("INSERT INTO qc_inspection_item
                (version_id, form_type_id, process_name, item_code, item_name, standard_text,
                 min_value, max_value, plus_tolerance, minus_tolerance, result_type, sort_order, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $insT = $pdo->prepare("INSERT INTO qc_inspection_item_tool_type (item_id, QC_Tool_List_id, is_primary) VALUES (?,?,?)");
            $getT = $pdo->prepare("SELECT QC_Tool_List_id, is_primary FROM qc_inspection_item_tool_type WHERE item_id=?");
            foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $insI->execute([$newVerId, $it['form_type_id'], $it['process_name'], $it['item_code'], $it['item_name'],
                                $it['standard_text'], $it['min_value'], $it['max_value'], $it['plus_tolerance'],
                                $it['minus_tolerance'], $it['result_type'], $it['sort_order'], $it['is_active']]);
                $nid = (int)$pdo->lastInsertId();
                $getT->execute([$it['item_id']]);
                foreach ($getT->fetchAll(PDO::FETCH_ASSOC) as $t) { try { $insT->execute([$nid, $t['QC_Tool_List_id'], $t['is_primary']]); } catch (Exception $e) {} }
            }
            $pdo->prepare("UPDATE qc_inspection_version SET is_active=0 WHERE d_id=? AND version_id<>?")->execute([$dId, $newVerId]);
        }

        $pdo->prepare("INSERT INTO qc_drawing_change
            (change_no, as_doc_no, d_id, old_revision, new_revision, change_date, source, customer_doc_no,
             from_process_no, summary, detail, old_version_id, new_version_id, trigger_attachment_id, status, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'OPEN', ?, NOW())")
            ->execute([$changeNo, '2-PD-01-07', $dId, $oldRev, $newRev, (($p['change_date'] ?? '') ?: null),
                       trim((string)($p['source'] ?? '')), trim((string)($p['customer_doc_no'] ?? '')), $fromP,
                       $summary, trim((string)($p['detail'] ?? '')), $oldVerId, $newVerId, $trigId, $uid]);
        $id = (int)$pdo->lastInsertId();

        $insA = $pdo->prepare("INSERT INTO qc_drawing_change_ack (change_id, user_id, acked_at) VALUES (?,?,NULL)");
        foreach ($ackIds as $u) { $insA->execute([$id, $u]); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // 通知（失敗不影響已寫入的變更紀錄）
    if ($ackIds) {
        $partNo = '';
        try { $pn = $pdo->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?"); $pn->execute([$dId]); $partNo = (string)$pn->fetchColumn(); } catch (Throwable $e) {}
        dwg_notify($pdo, $id,
            '【圖面變更】料號 ' . $partNo . '　請簽收確認',
            '圖面變更單 ' . $changeNo . '（AS 2-PD-01-07）' . "\n" .
            '版次：' . ($oldRev ?: '—') . ' → ' . ($newRev ?: '—') . "\n" .
            '摘要：' . $summary . "\n" . '請點入確認並簽收。',
            $ackIds, $uid, 'reply');
    }
    return ['id' => $id, 'change_no' => $changeNo, 'new_version_id' => $newVerId, 'old_version_id' => $oldVerId];
}

}   // function_exists
