<?php
// imgedit_visibility.php — 批圖編輯器檔案在「附件清單」的可見性過濾（共用）
//
// 批圖編輯器（views/Sales/image_editor.php）每次儲存會在 part_attachments 寫入成對兩筆：
//   egdraw_<stamp>.png（輸出圖）＋ egdraw_<stamp>.egwork.json（工作檔），
// 工作檔的分享範圍存在 imgedit_workfile_meta（private=私人／dept=部門共用／custom=指定人員；
// 無 meta＝改版前舊資料，視為全員可見）。
// 本函式讓任何列出 part_attachments 的端點，依同一套規則過濾掉目前使用者無權看的批圖檔。
// 管理者（user_status 9/90 或系統 admin 角色）全可見。
//
// ★2026-08-21 重要變更（使用者明確要求）：**分享範圍只管工作檔（.egwork.json），不管壓平輸出的 PNG**。
//   原本成對的 PNG 會跟隨工作檔的範圍一起被藏起來，導致「存成私人」時別人連那張圖都看不到；
//   使用者要的是「圖大家都要看得到，只有可再編輯的工作檔才私人」，故改為 PNG 一律不過濾。
//   實務意義：私人保護的是「編輯中的半成品與可改的原始檔」，不是成品圖面——成品圖存進料號附件
//   本來就是要給大家看的（外來文件清單、型態識別文件管制表等也才不會因人而異）。
// 濾掉 Fabric.js 工作檔（*.egwork.json）——給「唯讀檢視」端點用。
// 工作檔只有批圖編輯器打得開，在圖面檢視跳窗裡既不能看也不能印，列出來只是干擾；
// 批圖編輯器自己的工作檔清單走 image_editor.php 的獨立查詢，不經過這裡，故不受影響。
// ★2026-08-21 起連附件的 CRUD 清單（master_data_management 的附件跳窗）也套用：工作檔只在批圖編輯器
// 裡看得到，管理／刪除也在編輯器的「料號附件」跳窗內完成（delete_workfile 自帶同一套範圍檢查）。
function imgedit_strip_workfiles(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        if (preg_match('/\.egwork\.json$/i', (string)($r['filename'] ?? ''))) continue;
        $out[] = $r;
    }
    return $out;
}

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
                             WHERE wf.d_id IN ($ph) AND wf.filename LIKE 'egdraw\\_%.egwork.json'");
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
        // 只有批圖「工作檔」受分享範圍限制；壓平輸出的 PNG 與其他附件一律放行（見檔頭 2026-08-21 變更）
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
