<?php
// imgedit_visibility.php — 批圖編輯器檔案在「附件清單」的可見性過濾（共用）
//
// 批圖編輯器（views/Sales/image_editor.php）存檔有兩條路徑：
//   ①「只存圖片，不建立工作檔」＝正式成品圖：只寫一筆 egdraw_<stamp>.png，全公司都看得到、
//      要填發行章日期、會參與圖面變更判定 —— 這條路徑產生的圖**不受本檔任何過濾**。
//   ② 一般存檔＝成對兩筆：egdraw_<stamp>.png（輸出圖）＋ egdraw_<stamp>.egwork.json（工作檔），
//      工作檔的分享範圍存在 imgedit_workfile_meta（private=私人／dept=部門共用／custom=指定人員）。
//
// ★2026-08-25 重要變更（使用者明確要求，推翻 2026-08-21 的「PNG 一律不過濾」）：
//   **②「有建立工作檔」的存檔，連同它產生的輸出 PNG 一律只在批圖編輯器裡看得到，其他頁面一概不列。**
//   原因：那是「還在編、隨時會被下一版蓋掉」的暫存圖（見 image_editor.php save_workfile 註解），
//   卻帶著 BOSS圖／原圖等附件標籤混在圖面查閱清單裡，會被當成正式圖面＝造成誤會。
//   **判定與登入者無關**（使用者明確要求）：不是「私人的別人看不到」，而是「有工作檔的大家都看不到」，
//   否則外來文件清單、型態識別文件管制表這種 AS9100 管制清單會因登入者不同而內容不同。
//   要正式發行請在存檔跳窗勾「只存圖片，不建立工作檔」（＝路徑①）。
//   工作檔本身的分享範圍（私人／部門／指定人員）仍然有效，那是在批圖編輯器的工作檔清單裡判定的
//   （image_editor.php 的 list_workfiles／load_workfile／delete_workfile 各自檢查，不經過本檔）。

// ── 找出「工作檔存檔產生的輸出 PNG」 ───────────────────────────────────
// 回傳 [小寫png檔名 => true]。配對鍵只用檔名不用 d_id：檔名是 egdraw_<時間>_<6碼亂數>，
// 同一次存檔的 PNG 與工作檔必然同 stem，跨料號撞名機率為零，少一個 d_id 就能涵蓋
// 「呼叫端 SELECT 沒帶 d_id」的清單（例如外來文件清單只 SELECT 了 ds.d_id 別名）。
// **刻意不濾 deleted_at**：工作檔被「保留上限」輪替軟刪除後，那張圖仍然是暫存圖，
// 不該因為工作檔被輪替掉就突然變成全公司看得到。
function imgedit_draft_png_names(PDO $pdo, array $rows): array {
    $want = [];
    foreach ($rows as $r) {
        $fn = (string)($r['filename'] ?? '');
        if (preg_match('/^(egdraw_.+)\.png$/i', $fn, $m)) $want[$m[1] . '.egwork.json'] = true;
    }
    if (!$want) return [];
    $out = [];
    foreach (array_chunk(array_keys($want), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $st = $pdo->prepare("SELECT filename FROM part_attachments WHERE filename IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
                $out[strtolower(preg_replace('/\.egwork\.json$/i', '.png', (string)$f))] = true;
            }
        } catch (Exception $_e) { return []; }   // 表/欄位異常＝不過濾，寧可多列也不要整張清單掛掉
    }
    return $out;
}

// ── SQL 版：給「不是把整批列出來、而是在 SQL 裡算數量／存在旗標」的呼叫端用 ──
// 回傳可直接串進 WHERE 的條件字串（不需綁定參數），語意同 imgedit_strip_workfiles()。
function imgedit_sql_not_draft(string $alias = 'pa'): string {
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'pa';
    return "($a.filename NOT LIKE 'egdraw\_%.egwork.json'"
         . " AND NOT ($a.filename LIKE 'egdraw\_%.png' AND EXISTS ("
         . "   SELECT 1 FROM part_attachments w2"
         . "   WHERE w2.filename = CONCAT(LEFT($a.filename, CHAR_LENGTH($a.filename) - 4), '.egwork.json')"
         . " )))";
}

// ── 主要過濾：濾掉工作檔（*.egwork.json）與它配對的輸出 PNG ───────────────
// 給所有「唯讀檢視」與「附件 CRUD 清單」端點用（管理／刪除都在批圖編輯器的工作檔清單裡完成）。
// $pdo 省略＝只濾工作檔本身（舊行為，保留給沒有 PDO 可用的呼叫端；新呼叫端請一律傳）。
function imgedit_strip_workfiles(array $rows, ?PDO $pdo = null): array {
    $draft = $pdo ? imgedit_draft_png_names($pdo, $rows) : [];
    $out = [];
    foreach ($rows as $r) {
        $fn = (string)($r['filename'] ?? '');
        if (preg_match('/\.egwork\.json$/i', $fn)) continue;
        if ($draft && isset($draft[strtolower($fn)])) continue;
        $out[] = $r;
    }
    return $out;
}

// ── 工作檔的分享範圍過濾（私人／部門／指定人員）─────────────────────────
// 2026-08-25 起 imgedit_strip_workfiles() 已經把工作檔與其輸出圖一併濾掉，故各檢視端點在
// 兩者併用時本函式等同第二道保險；獨立使用（只想依範圍過濾、仍要列出工作檔）時仍然有效。
function imgedit_filter_attachment_rows(PDO $pdo, array $rows, int $uid, int $dIdFallback = 0): array {
    $dIds = [];
    foreach ($rows as $r) {
        if (strpos((string)($r['filename'] ?? ''), 'egdraw_') !== 0) continue;
        $dId = (int)($r['d_id'] ?? $dIdFallback);
        if ($dId) $dIds[$dId] = true;
    }
    if (!$dIds) return $rows;
    $dIds = array_keys($dIds);
    $ph = implode(',', array_fill(0, count($dIds), '?'));
    $metaByKey = [];
    try {
        // 含已軟刪除的工作檔：配對 PNG 可能還存活，範圍仍應沿用
        $st = $pdo->prepare("SELECT wf.id, wf.d_id, wf.filename, wf.uploaded_by_id, m.owner_type, m.owner_dept_id
                             FROM part_attachments wf
                             JOIN imgedit_workfile_meta m ON m.attachment_id = wf.id
                             WHERE wf.d_id IN ($ph) AND wf.filename LIKE 'egdraw\_%.egwork.json'");
        $st->execute($dIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $stem = preg_replace('/\.egwork\.json$/', '', $w['filename']);
            $metaByKey[$w['d_id'] . '|' . $stem] = $w;
        }
    } catch (Exception $_e) { return $rows; }   // meta 表不存在（舊環境）＝不過濾
    if (!$metaByKey) return $rows;
    $hasScoped = false;
    foreach ($metaByKey as $w) {
        if (($w['owner_type'] ?: 'company') !== 'company') { $hasScoped = true; break; }
    }
    if (!$hasScoped) return $rows;
    // 身分：管理者全可見；否則需要部門與指定名單
    $isMgr = false; $myDeptIds = [];
    try {
        $st = $pdo->prepare("SELECT user_status FROM user WHERE id = ?");
        $st->execute([$uid]);
        $isMgr = in_array((int)$st->fetchColumn(), [9, 90], true);
        if (!$isMgr) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                                 WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1");
            $st->execute([$uid]);
            $isMgr = (int)$st->fetchColumn() > 0;
        }
        if (!$isMgr) {
            $st = $pdo->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id = ?");
            $st->execute([$uid]);
            $myDeptIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (Exception $_e) {}
    if ($isMgr) return $rows;
    $shareChk = null;
    $out = [];
    foreach ($rows as $r) {
        $fn = (string)($r['filename'] ?? '');
        // 只有批圖「工作檔」受分享範圍限制；輸出 PNG 由 imgedit_strip_workfiles() 一律濾掉（見檔頭 2026-08-25）
        if (!preg_match('/^egdraw_.+\.egwork\.json$/i', $fn)) { $out[] = $r; continue; }
        $dId = (int)($r['d_id'] ?? $dIdFallback);
        $stem = preg_replace('/\.egwork\.json$/i', '', $fn);
        $w = $metaByKey[$dId . '|' . $stem] ?? null;
        if (!$w) { $out[] = $r; continue; }   // 無 meta＝改版前舊資料，維持可見
        $type = $w['owner_type'] ?: 'company';
        $visible = $type === 'company' || (int)$w['uploaded_by_id'] === $uid;
        if (!$visible && $type === 'dept') $visible = in_array((int)$w['owner_dept_id'], $myDeptIds, true);
        if (!$visible && $type === 'custom') {
            if ($shareChk === null) {
                $shareChk = $pdo->prepare("SELECT COUNT(*) FROM imgedit_workfile_share WHERE attachment_id = ? AND user_id = ?");
            }
            try { $shareChk->execute([(int)$w['id'], $uid]); $visible = (int)$shareChk->fetchColumn() > 0; }
            catch (Exception $_e) { $visible = false; }
        }
        if ($visible) $out[] = $r;
    }
    return $out;
}
