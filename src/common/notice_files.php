<?php
// notice_files.php — 公告/回覆 附件共用設定與工具
// 限制：常見文件+圖片、單檔 50MB、每批最多 10 個
// 儲存位置：可於「設定」自訂(system_settings.notice_attach_base，建議填 UNC 路徑)；未設定則用專案內 uploads/notice。
// 資料夾結構：{base}\{公告編號}\ (公告附件)；回覆附件：{base}\{公告編號}\回覆附件\{公告編號}-{回覆人}-{流水號}\

if (!defined('EG_NOTICE_MAX_SIZE')) {
    define('EG_NOTICE_UPLOAD_FALLBACK', realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'notice');
    define('EG_NOTICE_MAX_SIZE', 50 * 1024 * 1024); // 50MB
    define('EG_NOTICE_MAX_COUNT', 10);
}

if (!function_exists('eg_notice_allowed_ext')) {
    function eg_notice_allowed_ext() {
        return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', '7z'];
    }
    function eg_notice_is_previewable($ext) {
        return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf'], true);
    }
    function eg_notice_mime($ext) {
        $ext = strtolower($ext);
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'bmp' => 'image/bmp', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    // 取得基礎儲存路徑（設定值優先，否則 fallback）
    function eg_notice_base($db) {
        try {
            $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'notice_attach_base' LIMIT 1");
            $st->execute();
            $v = trim((string)$st->fetchColumn());
            if ($v !== '') return rtrim($v, '\\/');
        } catch (Exception $e) {}
        return EG_NOTICE_UPLOAD_FALLBACK;
    }

    // 產生公告編號：PU + 民國年(3) + 月(2) + 日(2) + 當日流水號(3)，例 PU1150701001
    function eg_gen_event_no($db, $date) {
        $ts = strtotime($date) ?: time();
        $roc = (int)date('Y', $ts) - 1911;
        $prefix = 'PU' . sprintf('%03d', $roc) . date('md', $ts);
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM live_event WHERE event_no LIKE ?");
            $st->execute([$prefix . '%']);
            $n = (int)$st->fetchColumn() + 1;
        } catch (Exception $e) { $n = 1; }
        return $prefix . sprintf('%03d', $n);
    }

    // 移除路徑不安全字元（保留中文）
    function eg_notice_safe_seg($s) {
        $s = str_replace(['\\', '/', '..', ':', '*', '?', '"', '<', '>', '|'], '', (string)$s);
        return trim($s);
    }

    // 公告附件資料夾（絕對路徑）
    function eg_notice_event_dir($db, $eventNo) {
        return eg_notice_base($db) . DIRECTORY_SEPARATOR . eg_notice_safe_seg($eventNo);
    }
    // 回覆附件資料夾（絕對路徑）：{公告}\回覆附件\{公告編號}-{回覆人}-{流水號}
    function eg_notice_reply_dir($db, $eventNo, $replier, $serial) {
        $folder = eg_notice_safe_seg($eventNo) . '-' . eg_notice_safe_seg($replier) . '-' . sprintf('%03d', (int)$serial);
        return eg_notice_event_dir($db, $eventNo) . DIRECTORY_SEPARATOR . '回覆附件' . DIRECTORY_SEPARATOR . $folder;
    }

    // 儲存 $_FILES['x'] 型式的檔案到指定絕對資料夾；回傳 [['name'=>原檔名,'path'=>絕對路徑], ...]
    // 注意：UNC 路徑的 is_dir/is_writable 常不準，故以實際 mkdir/move 結果判斷。
    function eg_notice_save_files($files, $absDir) {
        $saved = [];
        if (empty($files) || !isset($files['name'])) return $saved;
        @mkdir($absDir, 0775, true);
        $allow = eg_notice_allowed_ext();
        $names = (array)$files['name'];
        $count = 0;
        foreach ($names as $i => $orig) {
            if ($count >= EG_NOTICE_MAX_COUNT) break;
            if (!isset($files['error'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($files['size'][$i] <= 0 || $files['size'][$i] > EG_NOTICE_MAX_SIZE) continue;
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allow, true)) continue;
            $safe = uniqid('f', true) . '.' . $ext;
            $dest = rtrim($absDir, '\\/') . DIRECTORY_SEPARATOR . $safe;
            if (@move_uploaded_file($files['tmp_name'][$i], $dest)) {
                $saved[] = ['name' => $orig, 'path' => $dest];
                $count++;
            }
        }
        return $saved;
    }

    // 清理空的公告資料夾：若公告已無任何附件、無回覆附件、且無「需回覆」對象，則刪除該公告的空資料夾(含空的 回覆附件 子夾)
    function eg_notice_cleanup_event_folder($db, $eventId) {
        try {
            $no = $db->query("SELECT event_no FROM live_event WHERE id = " . (int)$eventId)->fetchColumn();
            if (!$no) return;
            $hasFiles = (int)$db->query("SELECT COUNT(*) FROM live_event_file WHERE live_event_id = " . (int)$eventId)->fetchColumn();
            $hasResp  = (int)$db->query("SELECT COUNT(*) FROM live_event_resp_file rf JOIN live_event_response r ON r.id = rf.response_id WHERE r.live_event_id = " . (int)$eventId)->fetchColumn();
            $needReply = (int)$db->query("SELECT COUNT(*) FROM live_event_target WHERE live_event_id = " . (int)$eventId . " AND mode = 'reply'")->fetchColumn();
            if ($hasFiles > 0 || $hasResp > 0 || $needReply > 0) return; // 尚有內容或未來會有回覆附件 → 保留
            $dir = eg_notice_event_dir($db, $no);
            @rmdir($dir . DIRECTORY_SEPARATOR . '回覆附件'); // 空才會成功
            @rmdir($dir);                                   // 空才會成功
        } catch (Exception $e) {}
    }

    // 由 DB 儲存的路徑取回實體路徑（相容舊的 uploads/notice 相對路徑；絕對路徑則直接用）。防目錄穿越。
    function eg_notice_abs_path($stored) {
        $stored = (string)$stored;
        if ($stored === '' || strpos($stored, '..') !== false) return false;
        // 舊格式：uploads/notice/...
        if (strpos($stored, 'uploads/notice/') === 0) {
            return realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored);
        }
        // 絕對路徑(UNC 或本機)：僅接受在基礎路徑或 fallback 底下（字串比對，避免穿越）
        return $stored;
    }
}

// ---------------------------------------------------------------------------
// 新版附件（附件標籤/浮水印規格 2026-07-07）：
// 前端以 notice_attachment_API.php 先將檔案暫存（Excel/Word 已轉 PDF 並經上傳者確認），
// 表單送出時由本函式把暫存檔搬入公告資料夾、寫入 live_event_file（含標籤/說明），
// 並產生「角落標註」檢視快取版。
// ---------------------------------------------------------------------------
if (!function_exists('eg_notice_bind_att_items')) {
    function eg_notice_bind_att_items($db, $eventId, $eventNo, $itemsJson, $uid) {
        $items = json_decode((string)$itemsJson, true);
        if (!is_array($items) || !$items) return;
        require_once __DIR__ . '/attachment_lib.php';
        eg_att_ensure_schema($db);
        $tempRoot = eg_notice_base($db);
        $destDir  = eg_notice_event_dir($db, $eventNo);
        $defaultTag = eg_att_default_tag_id($db, 'announcement');
        foreach ($items as $it) {
            $upId = (string)($it['upload_id'] ?? '');
            $meta = eg_att_pending_get($tempRoot, $upId);
            if (!$meta) continue;
            $isOffice = in_array($meta['ext'], eg_att_office_ext(), true);
            if ($isOffice && (empty($meta['pdf']) || empty($meta['confirmed']))) {
                // office 檔未完成「轉檔＋預覽確認」→ 視同未完成上傳
                eg_att_pending_discard($tempRoot, $upId);
                continue;
            }
            $res = eg_att_pending_commit($tempRoot, $upId, $destDir);
            if (!$res) { error_log('[notice] bind att commit failed: ' . $upId); continue; }
            // 標籤：無效或未選 → 套用預設標籤
            $tagId = null;
            if (!empty($it['tag_id'])) {
                $t = eg_att_tag_row($db, (int)$it['tag_id']);
                if ($t && $t['scope'] === 'announcement' && (int)$t['is_active'] === 1) $tagId = (int)$t['id'];
            }
            if ($tagId === null) $tagId = $defaultTag;
            $desc = mb_substr(trim((string)($it['description'] ?? '')), 0, 255, 'UTF-8');
            // 顯示名稱：office 轉檔後顯示為 .pdf；原始檔名另存 original_filename
            $displayName = $isOffice ? (pathinfo($meta['orig_name'], PATHINFO_FILENAME) . '.pdf') : $res['orig_name'];
            $db->prepare("INSERT INTO live_event_file (live_event_id, file_name, file_path, tag_id, description, original_filename, file_size, uploaded_by)
                          VALUES (?,?,?,?,?,?,?,?)")
               ->execute([(int)$eventId, $displayName, $res['path'], $tagId, ($desc !== '' ? $desc : null), $res['orig_name'], $res['size'], (int)$uid]);
            $newId = (int)$db->lastInsertId();
            eg_att_log($db, (int)$uid, 'event_file', $newId, 'tag_id', null, $tagId);
            // 角落標註檢視快取版（失敗降級，不影響上傳）
            eg_att_make_preview($db, 'announcement', [
                'id' => $newId, 'file_path' => $res['path'], 'tag_id' => $tagId,
                'description' => $desc, 'uploaded_by' => (int)$uid, 'uploaded_at' => eg_att_now(),
            ]);
        }
    }
}
