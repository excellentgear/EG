<?php
if (!isset($_SESSION)) {
    session_start();
}

// include ("../../src/common/DBConnection.php");
include("../../src/common/_config.php");


/*-------人員--------*/
if(isset($_POST['checkname']) && $_POST['user_uname'] !=""){
    $findname = $_POST["user_uname"];
    // 使用預備語句來安全地查詢
    $checkname = $db->prepare("SELECT `id` FROM user WHERE user_uname = :findname");
    $checkname->bindValue(':findname', $findname, PDO::PARAM_STR);
    $checkname->execute();
    $row = $checkname->fetch();
    
    // 判斷是否查詢到結果
    if ($row) {
        $msgname = "帳號重複，請更改帳號！";
    // if ( $row[1])
    // {
    // echo '帳號重複，請洽管理員';
    // $$_SESSION['msg'] = '111';
    // $msgname = "帳號重複，請更改帳號！";
    }else{
        
    }
}

// 新增user
if (isset($_POST["newUser"]) && $_POST["user_cname"] != "" && $_POST["user_uname"] != "" && $_POST["user_password"] != "" && $_POST['user_status'] != "") {

        // 使用預備語句來防止 SQL 注入
        $sth = $db->prepare("INSERT INTO user(`user_cname`, `user_uname`, `user_password`, `user_status`) VALUES (:cname, :uname, :password, :status)");
        $sth->execute([
            ':cname'    => $_POST['user_cname'],
            ':uname'    => $_POST['user_uname'],
            ':password' => $_POST['user_password'], // 注意：密碼應進行加密處理
            ':status'   => $_POST['user_status']
        ]);

// if (isset($_POST["newUser2"])) {
//         $sth = $db->prepare("INSERT INTO user(`user_cname`,`user_uname`,`user_password`,`byear`,`gender`,`email`,`phone`,`user_address`,`user_status`,`em_name`,`em_phone`,`img`)VALUES
//     ('$_POST[user_cname]','$_POST[user_uname]','$_POST[user_password]','$_POST[byear]','$_POST[gender]','$_POST[email]','$_POST[phone]','$_POST[user_address]','$_POST[user_status]','$_POST[em_name]','$_POST[em_phone]',".$_FILES['file']['name'].")") or die('帳號重複');

//         $sth->execute();

        unset($_POST['user_cname']);
        unset($_POST['user_uname']);
        unset($_POST['user_password']);
        // unset($_POST['byear']);
        // unset($_POST['gender']);
        // unset($_POST['email']);
        // unset($_POST['phone']);
        // unset($_POST['user_address']);
        // unset($_POST['user_status']);
        // unset($_POST['em_name']);
        // unset($_POST['em_phone']);

        $_SESSION['msg'] = '資料新增成功！';
}


// 清除TG更新中項目
if (isset($_POST["resetTG"]) || isset($_POST["userList"])) {

    unset($_SESSION['odate']);
    unset($_SESSION['bom']);
    unset($_SESSION['cs_name']);
    unset($_SESSION['lname']);
    unset($_SESSION['s_mod']);
    unset($_SESSION['qty']);
    unset($_SESSION['machine']);
    unset($_SESSION['s_no']);
    unset($_SESSION['s_id']);
}

/*-------課程--------*/
// 新增課程
if (isset($_POST["newClasses"]) && $_POST["classname"] != "" && $_POST["subjects_id"] != "" && $_POST["teacher_id"] != "" && $_POST["class_weekday"] != "" && $_POST["class_time"] != "" && $_POST["grade"] != "") {

    $sth = $db->prepare("INSERT INTO classes(`name`,`grade`,`subject_id`,`teacher_id`,`class_weekday`,`class_atime_id`)VALUES('$_POST[classname]','$_POST[grade]','$_POST[subjects_id]','$_POST[teacher_id]','$_POST[class_weekday]','$_POST[class_time]')");

    $sth->execute();

    unset($_POST['classname']);
    unset($_POST['grade']);
    unset($_POST['section']);
    unset($_POST['teacher_id']);
    unset($_POST['class_time']);
    unset($_POST['class_weekday']);

    $msg = '新增成功！';
}

// 清除課程
if (isset($_POST["resetClaesses"])) {

    unset($_SESSION['userid']);
    unset($_SESSION['grade']);
    unset($_SESSION['classname']);
    unset($_SESSION['class_weekday']);
    unset($_SESSION['class_time']);
    unset($_SESSION['subjects_id']);
    unset($_SESSION['teacher_id']);
}



/*-------科目--------*/
// 清除科目
// if (isset($_POST["btn_reset"])) {

//     unset($_SESSION['subject_code']);
//     unset($_SESSION['subject_title']);
//     unset($_SESSION['subject_id']);
// }

// 更改科目
// if (isset($_POST["newSubject"]) && $_POST['subject_id'] != "" && $_POST["subject_title"] != "" && $_POST["subject_code"] != "") {

//     $sth = $db->prepare("UPDATE subjects SET subject='$_POST[subject_title]',code='$_POST[subject_code]' WHERE subject_id =$_SESSION[subject_id]") or die("存入資料庫失敗");

//     $sth->execute();

//     unset($_POST['subject_id']);
//     unset($_POST['subject_code']);
//     unset($_POST['subject_title']);
//     unset($_SESSION['subject_code']);
//     unset($_SESSION['subject_title']);
//     unset($_SESSION['subject_id']);

//     $msg = '更改成功！';
// }

// 新增科目
if (isset($_POST["newSubject"])  && $_POST["subject_title"] != "" && $_POST["subject_code"] != "") {

    $sth = $db->prepare("INSERT INTO `subjects`(`subject`,`code`)VALUES('$_POST[subject_title]','$_POST[subject_code]')");

    $sth->execute();

    unset($_POST['subject_code']);
    unset($_POST['subject_title']);
    unset($_POST['subject_id']);

    $msg = '新增成功！';
}

/*-------行事曆--------*/
// 新增行程
if (isset($_POST["newSchdule"]) && empty($_POST["userid"]) && !empty($_POST["schdule_start"]) && !empty($_POST["schdule_title"]) && !empty($_POST['schdule_end'])) {

    $is_all_day = isset($_POST['schdule_all_day']);
    $start_datetime = $_POST['schdule_start'];
    $end_datetime = $_POST['schdule_end'];

    // 如果不是全天事件，則組合日期和時間
    if (!$is_all_day) {
        $start_datetime .= ' ' . $_POST['start_hour'] . ':' . $_POST['start_minute'] . ':00';
        $end_datetime .= ' ' . $_POST['end_hour'] . ':' . $_POST['end_minute'] . ':00';
    }

    // 使用預備語句來防止 SQL 注入
    $sth = $db->prepare("INSERT INTO `evenement`(`title`, `start`, `end`) VALUES (:title, :start, :end)");
    
    // 綁定參數並執行
    $sth->execute([
        ':title' => $_POST['schdule_title'],
        ':start' => $start_datetime,
        ':end'   => $end_datetime
    ]);

    unset($_POST['schdule_title']);
    unset($_POST['schdule_start']);
    unset($_POST['schdule_end']);

    $msg = '新增成功！';
} else if (isset($_POST["newSchdule"]) && !empty($_POST["userid"]) && !empty($_POST["schdule_start"]) && !empty($_POST["schdule_title"]) && !empty($_POST['schdule_end'])) {

    $is_all_day = isset($_POST['schdule_all_day']);
    $start_datetime = $_POST['schdule_start'];
    $end_datetime = $_POST['schdule_end'];

    // 如果不是全天事件，則組合日期和時間
    if (!$is_all_day) {
        $start_datetime .= ' ' . $_POST['start_hour'] . ':' . $_POST['start_minute'] . ':00';
        $end_datetime .= ' ' . $_POST['end_hour'] . ':' . $_POST['end_minute'] . ':00';
    }

    // 使用預備語句來防止 SQL 注入
    $sth = $db->prepare("UPDATE evenement SET title = :title, start = :start, end = :end WHERE id = :id");
    $sth->execute([
        ':title' => $_POST['schdule_title'],
        ':start' => $start_datetime, // Missing comma was here
        ':end'   => $end_datetime,
        // 注意：更新時的 ID 應該來自表單提交，而不是 session
        ':id'    => $_POST['userid']
    ]);

    unset($_POST['schdule_title']);
    unset($_POST['schdule_start']);
    unset($_POST['schdule_end']);
    unset($_SESSION['schdule_title']);
    unset($_SESSION['schdule_start']);
    unset($_SESSION['schdule_end']);
    unset($_SESSION['userid']);

    $msg = '更改成功！';
}


// 清除行程
if (isset($_POST["resetCalendar"])) {

    unset($_SESSION['schdule_title']);
    unset($_SESSION['schdule_start']);
    unset($_SESSION['schdule_end']);
}

/*-------公告--------*/
// 新增公告
if (isset($_POST["newNews"]) && $_POST["userid"] == "" && $_POST["title"] != "" && $_POST["content"] != "") {

    // 使用預備語句
    $sth = $db->prepare("INSERT INTO `news`(`newsdate`, `title`, `content`) VALUES (:newsdate, :title, :content)");
    $sth->execute([
        ':newsdate' => $_POST['newsdate'],
        ':title'    => $_POST['title'],
        ':content'  => $_POST['content']
    ]);

    unset($_POST['title']);
    unset($_POST['content']);

    $msg = '新增成功！';

} else if (isset($_POST["newNews"]) && $_SESSION["userid"] != "" && $_POST["title"] != "" && $_POST["content"] != ""&& $_POST["newsdate"] != "") {
    // 使用預備語句
    $sth = $db->prepare("UPDATE news SET title = :title, content = :content, newsdate = :newsdate WHERE id = :id");
    $sth->execute([
        ':title'    => $_POST['title'],
        ':content'  => $_POST['content'],
        ':newsdate' => $_POST['newsdate'],
        ':id'       => $_SESSION['userid']
    ]);

    unset($_POST['title']);
    unset($_POST['content']);
    unset($_POST['newsdate']);
    unset($_SESSION['title']);
    unset($_SESSION['content']);
    unset($_SESSION['newsdate']);
    unset($_SESSION['userid']);

    $msg = '更改成功！';
}


// 清除公告
if (isset($_POST["resetNews"])) {

    unset($_SESSION['userid']);
    unset($_SESSION['newsdate']);
    unset($_SESSION['title']);
    unset($_SESSION['content']);
}

/*-------通知--------*/
// 將前端 targets[] (all / dept-N / status-N / user-N) 寫入 live_event_target，並帶各對象通知方式
// $modes：{ code => read/sign/reply }，code 例 'all','dept-1','status-9','user-5'
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
            $m = $modes[$code] ?? 'read';
            return in_array($m, ['read', 'sign', 'reply'], true) ? $m : 'read';
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

// 公告/通知 角色權限（RBAC，伺服器端把關，避免略過前端直接 POST）
// 僅在實際送出公告/通知時才計算，避免影響其他共用 _setting.php 的頁面
$__notice_features = [];
if (isset($_POST["newEvent"]) || isset($_POST["upDateEvent"])) {
    require_once __DIR__ . '/../common/rbac.php';
    $__notice_features = rbac_user_features($db, (int)($_SESSION['id'] ?? 0));
}

// 鎖定：來源『訂單變更』的通知禁止在公告/通知頁修改（單一真相來源為 order_change_log，請至訂單頁操作變更單）
if (isset($_POST["upDateEvent"]) && !empty($_POST["eventid"])) {
    $__lkq = $db->prepare("SELECT source FROM live_event WHERE id = ?");
    $__lkq->execute([(int)$_POST['eventid']]);
    if ($__lkq->fetchColumn() === '訂單變更') {
        unset($_POST['upDateEvent']); // 使下方更新分支不成立
        $msg = '此通知由「訂單變更」產生已鎖定，不可在此修改。';
    }
}

// 新增通知
if (isset($_POST["newEvent"]) && rbac_has($__notice_features, 'notice_create') && $_POST["eventid"] == "" && $_POST["title"] != "" && $_POST["content"] != "" && !empty($_POST['targets'])) {

    $enddate = !empty($_POST['enddate']) ? $_POST['enddate'] : null;
    $replyDeadline = !empty($_POST['reply_deadline']) ? $_POST['reply_deadline'] : null;
    $showStatus = !empty($_POST['show_status_to_others']) ? 1 : 0;
    $modes = json_decode($_POST['target_modes'] ?? '{}', true) ?: [];
    $__uid   = (int)($_SESSION['id'] ?? 0);
    // 來源：有頁面/動作傳入則用之(如『訂單變更』)，否則用建立者部門名稱
    $__source = !empty($_POST['source']) ? trim($_POST['source']) : eg_user_main_dept($db, $__uid);

    $sth = $db->prepare("INSERT INTO `live_event`(`eventdate`,`enddate`,`title`,`content`,`status`,`created_by`,`source`,`reply_deadline`,`show_status_to_others`) VALUES (:eventdate, :enddate, :title, :content, 0, :cb, :src, :rd, :ss)");
    $sth->execute([
        ':eventdate' => $_POST['eventdate'],
        ':enddate' => $enddate,
        ':title' => $_POST['title'],
        ':content' => $_POST['content'],
        ':cb' => ($__uid ?: null),
        ':src' => $__source,
        ':rd' => $replyDeadline,
        ':ss' => $showStatus
    ]);
    $newEventId = $db->lastInsertId();

    // 產生公告編號（PU+民國年+月日+當日流水號）
    require_once __DIR__ . '/../common/notice_files.php';
    $eventNo = eg_gen_event_no($db, $_POST['eventdate']);
    $db->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([$eventNo, $newEventId]);

    // 寫入對象(含通知方式)，並回填 status 相容欄位
    $primaryStatus = eg_save_event_targets($db, $newEventId, $_POST['targets'], $modes);
    $db->prepare("UPDATE live_event SET status=? WHERE id=?")->execute([$primaryStatus, $newEventId]);

    // 共同編輯者（部門或最多 5 位人員）
    if (isset($_POST['co_editors'])) {
        try { eg_save_event_editors($db, $newEventId, $_POST['co_editors']); }
        catch (Throwable $e) { error_log('[notice] save co_editors failed: ' . $e->getMessage()); }
    }

    // 公告附件（存到 {設定基礎路徑}\{公告編號}\）
    if (!empty($_FILES['notice_files']['name'][0])) {
        foreach (eg_notice_save_files($_FILES['notice_files'], eg_notice_event_dir($db, $eventNo)) as $sf) {
            $db->prepare("INSERT INTO live_event_file (live_event_id, file_name, file_path) VALUES (?,?,?)")->execute([$newEventId, $sf['name'], $sf['path']]);
        }
    }

    // 新版附件（AJAX 暫存 → 綁定入庫；含標籤/說明；Excel/Word 已轉 PDF）＋「直接顯示附件」勾選
    try {
        require_once __DIR__ . '/../common/attachment_lib.php';
        eg_att_ensure_schema($db);
        eg_notice_bind_att_items($db, (int)$newEventId, $eventNo, $_POST['att_items'] ?? '', $__uid);
        $db->prepare("UPDATE live_event SET show_attach_inline=? WHERE id=?")
           ->execute([!empty($_POST['show_attach_inline']) ? 1 : 0, (int)$newEventId]);
    } catch (Throwable $e) { error_log('[notice] bind att_items on create failed: ' . $e->getMessage()); }

    // 修改歷史：新增
    eg_log_event_history($db, $newEventId, 'create', $__uid, null, eg_event_snapshot($db, $newEventId));

    // 發布公告時自動 Web Push 推播給所有對象（失敗不影響發布流程）
    try {
        require_once __DIR__ . '/../push/push_send.php';
        eg_push_for_event($db, (int)$newEventId);
    } catch (\Throwable $e) {
        error_log('[push] on create event failed: ' . $e->getMessage());
    }

    // Telegram 推播（2026-07-07 恢復啟用；未設 Token 或未綁定 chat_id 者自動跳過）
    try {
        require_once __DIR__ . '/../../telegram/notify_event.php';
        eg_telegram_for_event($db, (int)$newEventId);
    } catch (\Throwable $e) {
        error_log('[telegram] on create event failed: ' . $e->getMessage());
    }

    unset($_POST['title']);
    unset($_POST['content']);
    unset($_POST['targets']);
    unset($_POST['enddate']);

    $msg = '新增成功！';

} else if (isset($_POST["upDateEvent"]) && $_POST["eventid"] != "" && $_POST["title"] != "" && $_POST["content"] != "" && $_POST["eventdate"] != "" && !empty($_POST['targets'])
    // 編輯權限：系統管理員(all)可改任何公告；其他人須有 notice_edit 且為本人建立，或本人為共同編輯者
    && (function () use ($db) {
        $__uid = (int)($_SESSION['id'] ?? 0);
        $__f = rbac_user_features($db, $__uid);
        if (rbac_has($__f, 'all')) return true;
        $__cb = $db->prepare("SELECT created_by FROM live_event WHERE id = ?");
        $__cb->execute([(int)$_POST['eventid']]);
        if ((int)$__cb->fetchColumn() === $__uid && rbac_has($__f, 'notice_edit')) return true;
        return eg_user_is_event_editor($db, (int)$_POST['eventid'], $__uid);
    })()) {

    $enddate = !empty($_POST['enddate']) ? $_POST['enddate'] : null;
    $replyDeadline = !empty($_POST['reply_deadline']) ? $_POST['reply_deadline'] : null;
    $showStatus = !empty($_POST['show_status_to_others']) ? 1 : 0;
    $modes = json_decode($_POST['target_modes'] ?? '{}', true) ?: [];
    $eventId = (int)$_POST['eventid'];

    // 先取修改前快照（在改動對象/內容之前）
    $__before = eg_event_snapshot($db, $eventId);

    // 記錄修改前的收件人（用於：新增對象→發新通知；既有對象→內容有改才發更新通知）
    require_once __DIR__ . '/../push/push_send.php';
    $__oldRecipients = eg_push_event_recipients($db, $eventId);

    $primaryStatus = eg_save_event_targets($db, $eventId, $_POST['targets'], $modes);

    // 共同編輯者（部門或最多 5 位人員）
    if (isset($_POST['co_editors'])) {
        try { eg_save_event_editors($db, $eventId, $_POST['co_editors']); }
        catch (Throwable $e) { error_log('[notice] save co_editors failed: ' . $e->getMessage()); }
    }

    $sth = $db->prepare("UPDATE live_event SET title=:title, content=:content, eventdate=:eventdate, enddate=:enddate, status=:status, reply_deadline=:rd, show_status_to_others=:ss WHERE id =:eventid");
    $sth->execute([
        ':title' => $_POST['title'],
        ':content' => $_POST['content'],
        ':eventdate' => $_POST['eventdate'],
        ':enddate' => $enddate,
        ':status' => $primaryStatus,
        ':rd' => $replyDeadline,
        ':ss' => $showStatus,
        ':eventid' => $eventId
    ]) or die("存入資料庫失敗");

    // 公告附件：刪除勾選的、加入新上傳的
    require_once __DIR__ . '/../common/notice_files.php';
    if (!empty($_POST['del_files']) && is_array($_POST['del_files'])) {
        $delIds = array_map('intval', $_POST['del_files']);
        $inQ = implode(',', array_fill(0, count($delIds), '?'));
        $q = $db->prepare("SELECT id, file_path FROM live_event_file WHERE live_event_id = ? AND id IN ($inQ)");
        $q->execute(array_merge([$eventId], $delIds));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $df) {
            $abs = eg_notice_abs_path($df['file_path']);
            if ($abs && is_file($abs)) @unlink($abs);
            $db->prepare("DELETE FROM live_event_file WHERE id = ?")->execute([$df['id']]);
        }
        eg_notice_cleanup_event_folder($db, $eventId);
    }
    if (!empty($_FILES['notice_files']['name'][0])) {
        // 取公告編號(舊資料若無則補產生)
        $eventNo = $db->query("SELECT event_no FROM live_event WHERE id=" . (int)$eventId)->fetchColumn();
        if (!$eventNo) {
            $eventNo = eg_gen_event_no($db, $_POST['eventdate']);
            $db->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([$eventNo, $eventId]);
        }
        foreach (eg_notice_save_files($_FILES['notice_files'], eg_notice_event_dir($db, $eventNo)) as $sf) {
            $db->prepare("INSERT INTO live_event_file (live_event_id, file_name, file_path) VALUES (?,?,?)")->execute([$eventId, $sf['name'], $sf['path']]);
        }
    }

    // 新版附件（AJAX 暫存 → 綁定入庫；含標籤/說明；Excel/Word 已轉 PDF）＋「直接顯示附件」勾選
    try {
        require_once __DIR__ . '/../common/attachment_lib.php';
        eg_att_ensure_schema($db);
        $eventNo2 = $db->query("SELECT event_no FROM live_event WHERE id=" . (int)$eventId)->fetchColumn();
        if (!$eventNo2) {
            $eventNo2 = eg_gen_event_no($db, $_POST['eventdate']);
            $db->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([$eventNo2, $eventId]);
        }
        eg_notice_bind_att_items($db, (int)$eventId, $eventNo2, $_POST['att_items'] ?? '', (int)($_SESSION['id'] ?? 0));
        $db->prepare("UPDATE live_event SET show_attach_inline=? WHERE id=?")
           ->execute([!empty($_POST['show_attach_inline']) ? 1 : 0, (int)$eventId]);
    } catch (Throwable $e) { error_log('[notice] bind att_items on update failed: ' . $e->getMessage()); }

    // 修改歷史：更新（前後快照）
    eg_log_event_history($db, $eventId, 'update', (int)($_SESSION['id'] ?? 0), $__before, eg_event_snapshot($db, $eventId));

    // 推播：新增的收件人→發「新公告」通知；既有收件人→標題或內容有變才發「公告更新」通知（避免小改洗版）
    try {
        $__newRecipients = eg_push_event_recipients($db, $eventId);
        $__added    = array_values(array_diff($__newRecipients, $__oldRecipients));
        $__existing = array_values(array_intersect($__newRecipients, $__oldRecipients));
        if (!empty($__added)) eg_push_event_notify($db, $eventId, $__added, false);
        $__contentChanged = !$__before
            || trim((string)($__before['title'] ?? '')) !== trim((string)$_POST['title'])
            || trim((string)($__before['content'] ?? '')) !== trim((string)$_POST['content']);
        if ($__contentChanged && !empty($__existing)) eg_push_event_notify($db, $eventId, $__existing, true);
    } catch (\Throwable $e) {
        error_log('[push] on update event failed: ' . $e->getMessage());
    }

    // Telegram 推播（2026-07-07 恢復啟用；與 Web Push 同規則並行：新增對象→新公告通知；既有對象→內容有改才發更新通知）
    // 內容有改時先「收回」先前發出的 Telegram 訊息（改寫為作廢提示、移除按鈕），再發新訊息
    try {
        require_once __DIR__ . '/../../telegram/notify_event.php';
        $__tgNew      = eg_push_event_recipients($db, $eventId);
        $__tgAdded    = array_values(array_diff($__tgNew, $__oldRecipients));
        $__tgExisting = array_values(array_intersect($__tgNew, $__oldRecipients));
        $__tgChanged = !$__before
            || trim((string)($__before['title'] ?? '')) !== trim((string)$_POST['title'])
            || trim((string)($__before['content'] ?? '')) !== trim((string)$_POST['content']);
        if ($__tgChanged) eg_telegram_retract_event($db, $eventId, false);
        if (!empty($__tgAdded)) eg_telegram_event_notify($db, $eventId, $__tgAdded, false);
        if ($__tgChanged && !empty($__tgExisting)) eg_telegram_event_notify($db, $eventId, $__tgExisting, true);
    } catch (\Throwable $e) {
        error_log('[telegram] on update event failed: ' . $e->getMessage());
    }

    unset($_POST['title']);
    unset($_POST['content']);
    unset($_POST['eventdate']);
    unset($_POST['targets']);
    unset($_SESSION['title']);
    unset($_SESSION['content']);
    unset($_SESSION['eventdate']);
    unset($_SESSION['eventid']);
    unset($_SESSION['eventstatus']);
    unset($_SESSION['enddate']);

    $msg = '更改成功！';
}



// 清除通知
if (isset($_POST["resetEvent"])) {

    unset($_SESSION['eventid']);
    unset($_SESSION['eventdate']);
    unset($_SESSION['enddate']);
    unset($_SESSION['title']);
    unset($_SESSION['content']);
    unset($_SESSION['eventstatus']);
}

/*-------學生選課--------*/
// 新增選課
if (isset($_POST["newClassselect"]) && $_POST["classselect"] != "" && $_POST["user"] != "") {

    $sth = $db->prepare("INSERT INTO `classselect`(`user_id`,`class_id`)VALUES('$_POST[user]','$_POST[classselect]')");

    $sth->execute();

    unset($_POST['classselect']);
    unset($_POST['user']);

    $msg = '新增成功！';

} 

/*-------test--------*/
// 新增test
if (isset($_POST["newComment"]) && $_POST["title"] != "" && $_POST["content"] != "") {

    // 使用預備語句
    $sth = $db->prepare("INSERT INTO `comment`(`stuid`, `title`, `content`) VALUES (:stuid, :title, :content)");
    $sth->execute([
        ':stuid'   => $_POST['stuid'],
        ':title'   => $_POST['title'],
        ':content' => $_POST['content']
    ]);
    unset($_POST['title']);
    unset($_POST['content']);

    $msg = '新增成功！';

} 

/*-------成績--------*/
// 新增成績
if (isset($_POST["jhs_marks"]) && $_POST['marks_id'] == "" && $_POST["year"] != "" && $_POST["jhs"] != "" && $_POST["Mocktest"] != "" && $_POST["chinese"] != "" && $_POST["mathematics"] != "" && $_POST["english"] != "" && $_POST["natural"] != "" && $_POST["society"] != "") {

    $sth = $db->prepare("INSERT INTO `jhs_marks`(`student_id`,`year`,`jhs`,`Mocktest`,`chinese`,`mathematics`,`english`,`natural`,`society`,`create_by_admin`)
                        VALUES('$_POST[student_id]','$_POST[year]','$_POST[jhs]','$_POST[Mocktest]','$_POST[chinese]','$_POST[mathematics]','$_POST[english]','$_POST[natural]','$_POST[society]','$_GET[id]')");

    $sth->execute();

    $msg = '新增成功！';
}

// 更改成績


if (isset($_POST["jhs_marks"]) && $_POST['marks_id'] != "" && $_POST["year"] != "" && $_POST["jhs"] != "" && $_POST["student_name"] != "" && $_POST["Mocktest"] != "" && $_POST["chinese"] != "" && $_POST["mathematics"] != "" && $_POST["english"] != "" && $_POST["natural"] != "" && $_POST["society"] != "") {

    $sth = $db->prepare("UPDATE jhs_marks SET jhs='$_POST[jhs]',year='$_POST[year]',student_name='$_POST[student_name]',Mocktest='$_POST[Mocktest]',chinese='$_POST[chinese]',mathematics='$_POST[mathematics]',english='$_POST[english]',natural='$_POST[natural]',society='$_POST[society]', WHERE id =$_SESSION[marks_id]") or die("存入資料庫失敗");

    $sth->execute();

    unset($_SESSION['marks_id']);
    unset($_SESSION['year']);
    unset($_SESSION['jhs']);
    unset($_SESSION['student_name']);
    unset($_SESSION['Mocktest']);
    unset($_SESSION['chinese']);
    unset($_SESSION['mathematics']);
    unset($_SESSION['english']);
    unset($_SESSION['natural']);
    unset($_SESSION['society']);
    unset($_SESSION['create_by_admin']);
}


// 清除科目
if (isset($_POST["jhs_marks"])) {

    unset($_SESSION['userid']);
    unset($_SESSION['year']);
    unset($_SESSION['jhs']);
    unset($_SESSION['student_name']);
    unset($_SESSION['Mocktest']);
    unset($_SESSION['chinese']);
    unset($_SESSION['mathematics']);
    unset($_SESSION['english']);
    unset($_SESSION['natural']);
    unset($_SESSION['society']);
    unset($_SESSION['create_by_admin']);
}


// 清除
if (isset($_POST["resetUser"])) {

    unset($_SESSION['user_uname']);
    unset($_SESSION['user_password']);
    unset($_SESSION['user_cname']);
    unset($_SESSION['id']);
    
}
