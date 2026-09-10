<?php
/**
 * 公告 / 通知：對象、共同編輯者、快照與修改歷史的共用實作（唯一實作，禁止各檔各寫一份）
 *
 * 原本這些函式寫在 src/store/_setting.php 裡，但那支檔案同時是一大包 $_POST 處理器
 * （新增使用者、課程、行事曆…），別的 API 只為了用一個函式而 include 它會連帶跑到
 * 不相干的寫入流程，所以先前 notice_attachment_API.php 只好自己複製一份共同編輯者判定
 * （eg_user_is_event_editor2）。2026-09-10 抽成本檔，_setting.php 改為 require_once，
 * 全部函式仍以 function_exists 包住，既有呼叫端一行都不用改。
 */

/*-------通知--------*/
// 將前端 targets[] (all / dept-N / status-N / user-N) 寫入 live_event_target，並帶各對象通知方式
// $modes：{ code => autoread/read/sign/reply }，code 例 'all','dept-1','status-9','user-5'
// 合法值的唯一判定在 notice_mode_lib.php 的 eg_notice_mode_valid()，勿在此另寫一份白名單
require_once __DIR__ . '/notice_mode_lib.php';
if (!function_exists('eg_save_event_targets')) {
    function eg_save_event_targets($db, $eventId, $targets, $modes = []) {
        $targets = is_array($targets) ? $targets : [];
        $modes = is_array($modes) ? $modes : [];
        $rows = []; $hasAll = false;
        foreach ($targets as $tv) {
            if ($tv === 'all') { $hasAll = true; }
            elseif (strpos($tv, 'dept-') === 0)   { $rows[] = ['dept',   (int)substr($tv, 5)]; }
            elseif (strpos($tv, 'status-') === 0) { $rows[] = ['status', (int)substr($tv, 7)]; }
            elseif (strpos($tv, 'user-') === 0)   { $rows[] = ['user',   (int)substr($tv, 5)]; }
        }
        if ($hasAll || empty($rows)) { $rows = [['all', 0]]; } // 含全體或未選任何項，視為全體
        $modeOf = function ($code) use ($modes) {
            return eg_notice_mode_valid($modes[$code] ?? 'read');
        };
        // 先清空舊對象再寫入（更新時用）
        $db->prepare("DELETE FROM live_event_target WHERE live_event_id = ?")->execute([$eventId]);
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?,?,?,?)");
        $seen = [];
        foreach ($rows as $r) {
            $code = ($r[0] === 'all') ? 'all' : ($r[0] . '-' . $r[1]);
            if (isset($seen[$code])) continue;
            $seen[$code] = 1;
            $ins->execute([$eventId, $r[0], $r[1], $modeOf($code)]);
        }
        // 回傳第一個身分作為 live_event.status 的相容值（無則 0）
        foreach ($rows as $r) { if ($r[0] === 'status') return $r[1]; }
        return 0;
    }
}

// 共同編輯者：前端傳 JSON [{type:'dept'|'user', id:N}]，寫入 live_event_editor（人員上限 5）
if (!function_exists('eg_save_event_editors')) {
    function eg_save_event_editors($db, $eventId, $editorsJson) {
        $list = json_decode((string)$editorsJson, true);
        if (!is_array($list)) $list = [];
        $db->prepare("DELETE FROM live_event_editor WHERE live_event_id = ?")->execute([$eventId]);
        $ins = $db->prepare("INSERT IGNORE INTO live_event_editor (live_event_id, editor_type, editor_id) VALUES (?,?,?)");
        $userCount = 0;
        foreach ($list as $e) {
            $type = ($e['type'] ?? '') === 'dept' ? 'dept' : 'user';
            $id = (int)($e['id'] ?? 0);
            if ($id <= 0) continue;
            if ($type === 'user') {
                if ($userCount >= 5) continue; // 人員最多 5 位
                $userCount++;
            }
            $ins->execute([$eventId, $type, $id]);
        }
    }
}
// 本人是否為某公告的共同編輯者（直接指定，或本人部門(含兼任)被指定）
if (!function_exists('eg_user_is_event_editor')) {
    function eg_user_is_event_editor($db, $eventId, $uid) {
        try {
            $deptIds = array_map('intval', $db->query("SELECT department_id FROM user_department_position_map WHERE user_id = " . (int)$uid)->fetchAll(PDO::FETCH_COLUMN));
            $deptIn = $deptIds ? implode(',', $deptIds) : '-1';
            $st = $db->prepare("SELECT 1 FROM live_event_editor WHERE live_event_id = ? AND ((editor_type='user' AND editor_id = ?) OR (editor_type='dept' AND editor_id IN ($deptIn))) LIMIT 1");
            $st->execute([(int)$eventId, (int)$uid]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

// 公告快照（含對象），供修改歷史使用
if (!function_exists('eg_event_snapshot')) {
    function eg_event_snapshot($db, $eventId) {
        $e = $db->prepare("SELECT eventdate, enddate, title, content, status FROM live_event WHERE id = ?");
        $e->execute([$eventId]);
        $row = $e->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $t = $db->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id = ? ORDER BY id");
        $t->execute([$eventId]);
        $targets = [];
        foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $tr) {
            $targets[] = $tr['target_type'] . ($tr['target_type'] === 'all' ? '' : '-' . $tr['target_id']);
        }
        $row['targets'] = $targets;
        // 共同編輯者也納入快照（多人可編輯的公告須有完整編輯記錄）
        try {
            $ed = $db->prepare("SELECT editor_type, editor_id FROM live_event_editor WHERE live_event_id = ? ORDER BY id");
            $ed->execute([$eventId]);
            $editors = [];
            foreach ($ed->fetchAll(PDO::FETCH_ASSOC) as $er) { $editors[] = $er['editor_type'] . '-' . $er['editor_id']; }
            $row['editors'] = $editors;
        } catch (Throwable $e) { $row['editors'] = []; }
        return $row;
    }
}
// 寫入一筆修改歷史
if (!function_exists('eg_log_event_history')) {
    function eg_log_event_history($db, $eventId, $action, $changedBy, $before, $after) {
        $st = $db->prepare("INSERT INTO live_event_history (live_event_id, action, changed_by, changed_at, before_data, after_data) VALUES (?,?,?,NOW(),?,?)");
        $st->execute([
            $eventId, $action, ($changedBy ?: null),
            ($before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null),
            ($after  !== null ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null),
        ]);
    }
}
// 取得使用者主要部門名稱（公告來源預設用）
if (!function_exists('eg_user_main_dept')) {
    function eg_user_main_dept($db, $uid) {
        try {
            $st = $db->prepare("SELECT d.name FROM user_department_position_map m JOIN department d ON d.id = m.department_id WHERE m.user_id = ? AND m.is_main = 1 LIMIT 1");
            $st->execute([$uid]);
            $n = $st->fetchColumn();
            return $n ?: '公告通知';
        } catch (Exception $e) { return '公告通知'; }
    }
}
