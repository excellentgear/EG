<?php
// =============================================================================
// src/common/qa_notify.php
// 品質異常單 ↔ 公告通知系統(live_event) 的共用模組。
// 任何頁面/後端 require 本檔即可：
//   eg_qa_notify_schema($db)                       確保通知相關欄位/資料表存在
//   eg_qa_create_notice($db, $orderId, $opts)      開單後建立通知(回覆部門=回覆回簽、通知/追蹤人員=已閱)並推播
//   eg_qa_notify_reply($db, $eventId, $uid, $act, $content)
//                                                  對象回覆/回簽後 → 通知開單人＋追蹤人員(含回覆內容)
//   eg_qa_notify_flow_return($db, $orderId, $deptName, $uid, $content)
//                                                  部門於異常單頁面回覆歸還(flow_return)時通知開單人＋追蹤人員
//   eg_qa_default_users($db, $key)                 讀取預設名單(qa_system_settings 內 JSON)
//
// 設計要點：
//  - 通知本體仍是 live_event（沿用鈴鐺/推播/回覆回簽/期限機制），
//    以 live_event.ref_type='QA' + ref_id=異常單id 標記來源，
//    前端(鈴鐺/mobile/推播)看到 ref_type='QA' 就導向 views/QA/qa_abnormal_view.php。
//  - 異常單主檔記 notify_event_id 方便反查主通知。
//  - 追蹤人員存 qa_abnormal_follower（上限由前端限制，預設 5 名）。
//  - 所有通知/推播失敗都不可中斷主流程（開單、回覆本身要成功）。
// =============================================================================

require_once __DIR__ . '/notice_files.php'; // eg_gen_event_no()

if (!function_exists('eg_qa_notify_schema')) {
    function eg_qa_notify_schema(PDO $db): void {
        // live_event 加來源參照欄位
        $cols = $db->query("SHOW COLUMNS FROM live_event")->fetchAll(PDO::FETCH_COLUMN);
        $alters = [];
        if (!in_array('ref_type', $cols)) $alters[] = "ADD COLUMN `ref_type` VARCHAR(20) NULL COMMENT '來源模組(QA=品質異常單)'";
        if (!in_array('ref_id',   $cols)) $alters[] = "ADD COLUMN `ref_id` INT NULL COMMENT '來源主鍵(如 qa_abnormal_order.id)'";
        if ($alters) $db->exec("ALTER TABLE live_event " . implode(', ', $alters));

        // live_event 若仍為 utf8mb3 → 通知標題含 emoji(📋/🔔) 會 INSERT 失敗，整包通知靜默消失 → 轉 utf8mb4
        try {
            $cs = $db->query("SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='live_event' AND COLUMN_NAME='title'")->fetchColumn();
            if ($cs && $cs !== 'utf8mb4') {
                $db->exec("ALTER TABLE live_event CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            }
        } catch (Throwable $e) { /* 轉換失敗不擋主流程 */ }

        // 異常單主檔記主通知 id
        $qcols = $db->query("SHOW COLUMNS FROM qa_abnormal_order")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('notify_event_id', $qcols)) {
            $db->exec("ALTER TABLE qa_abnormal_order ADD COLUMN `notify_event_id` INT NULL COMMENT '開單主通知 live_event.id'");
        }

        // 追蹤人員（對象回覆時會收到通知的人）
        $db->exec("CREATE TABLE IF NOT EXISTS `qa_abnormal_follower` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `abnormal_order_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_order_user` (`abnormal_order_id`, `user_id`)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='品質異常單追蹤人員'");
    }
}

if (!function_exists('eg_qa_default_users')) {
    /** 讀取 qa_system_settings 內的預設名單(JSON 陣列 user id)，key 例：qa_notify_default_users / qa_follower_default_users */
    function eg_qa_default_users(PDO $db, string $key): array {
        try {
            $st = $db->prepare("SELECT setting_value FROM qa_system_settings WHERE setting_key=?");
            $st->execute([$key]);
            $v = json_decode((string)$st->fetchColumn(), true);
            return is_array($v) ? array_values(array_unique(array_filter(array_map('intval', $v)))) : [];
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_qa_order_brief')) {
    /** 取異常單摘要(組通知內容用)。回傳 null 表示查無。 */
    function eg_qa_order_brief(PDO $db, int $orderId): ?array {
        $st = $db->prepare("
            SELECT o.*, at.type_name AS abnormal_type_name, u.user_cname AS created_by_name,
                   ml.maker_id AS vendor_name, dept.name AS resp_dept_name, pu.user_cname AS resp_person_name
            FROM qa_abnormal_order o
            LEFT JOIN qa_abnormal_type at ON at.type_id = o.abnormal_type_id
            LEFT JOIN user u ON u.id = o.created_by
            LEFT JOIN maker_list ml ON ml.maker_id_no = o.responsible_vendor_id
            LEFT JOIN department dept ON dept.id = o.responsible_dept_id
            LEFT JOIN user pu ON pu.id = o.responsible_person_id
            WHERE o.id = ?");
        $st->execute([$orderId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) return null;

        // 來源補充：QC 檢驗 → 帶料號/BOM；IR → 帶退貨單號
        $srcDesc = '';
        try {
            if ($o['source_type'] === 'QC') {
                $s = $db->prepare("SELECT b.d_id AS part_no, bi.bom FROM qc_check_form f
                                   LEFT JOIN bom_ing bi ON bi.bom_ing_fid = f.bom_ing_fid
                                   LEFT JOIN bom b ON b.bom = bi.bom WHERE f.qc_form_id = ?");
                $s->execute([(int)$o['source_id']]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                    $srcDesc = '品管檢驗 NG' . ($r['part_no'] ? '｜料號 ' . $r['part_no'] : '') . ($r['bom'] ? '｜BOM ' . $r['bom'] : '');
                }
            } elseif ($o['source_type'] === 'IR') {
                $s = $db->prepare("SELECT IR_no FROM ir_track WHERE IR_id = ?");
                $s->execute([(int)$o['source_id']]);
                if ($no = $s->fetchColumn()) $srcDesc = '退貨單 ' . $no;
            }
        } catch (Throwable $e) { /* 摘要非必要 */ }
        $o['source_desc'] = $srcDesc;

        $resp = '';
        if ($o['responsible_type'] === 'vendor')    $resp = '廠商：' . ($o['vendor_name'] ?: $o['responsible_vendor_id']);
        elseif ($o['responsible_type'] === 'dept')  $resp = '廠內：' . ($o['resp_dept_name'] ?: '') . ($o['resp_person_name'] ? '／' . $o['resp_person_name'] : '');
        $o['resp_desc'] = $resp;
        return $o;
    }
}

if (!function_exists('eg_qa_insert_event')) {
    /**
     * 建立一則掛在異常單上的 live_event 通知並推播。
     * $targets: [ ['type'=>'dept'|'user', 'id'=>N, 'mode'=>'read'|'sign'|'reply'], ... ]
     * $opts: ['ref_type'=>'QA'(預設), 'ref_id'=>覆寫 ref_id(預設=orderId), 'url'=>推播點開網址(預設異常單檢視頁)]
     *   ref_type：QA=異常單主/回覆通知、QA_EDIT_REQ=修改請求(給主管)、QA_EDIT_OK=已開放修改(給請求者)
     * 回傳 live_event.id（失敗回傳 0）。
     */
    function eg_qa_insert_event(PDO $db, int $orderId, string $title, string $content, array $targets, ?string $replyDeadline, int $createdBy, array $opts = []): int {
        if (empty($targets)) return 0;
        $refType = $opts['ref_type'] ?? 'QA';
        $refId   = (int)($opts['ref_id'] ?? $orderId);
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, reply_deadline, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '品質異常單', ?, 1, ?, ?)")
           ->execute([$title, $content, ($createdBy ?: null), ($replyDeadline ?: null), $refType, $refId]);
        $eventId = (int)$db->lastInsertId();

        $eventNo = eg_gen_event_no($db, date('Y-m-d'));
        $db->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([$eventNo, $eventId]);

        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?,?,?,?)");
        $seen = [];
        foreach ($targets as $t) {
            $type = $t['type'] === 'dept' ? 'dept' : 'user';
            $id   = (int)($t['id'] ?? 0);
            $mode = in_array(($t['mode'] ?? 'read'), ['read','sign','reply'], true) ? $t['mode'] : 'read';
            if ($id <= 0) continue;
            $key = $type . '-' . $id;
            if (isset($seen[$key])) continue; // 先出現者優先（呼叫端請把 reply 模式排前面）
            $seen[$key] = 1;
            $ins->execute([$eventId, $type, $id, $mode]);
        }

        // 推播（失敗不影響）：點開直接進異常單檢視頁（或 $opts['url'] 指定頁）
        try {
            require_once __DIR__ . '/../push/push_send.php';
            $recipients = eg_push_event_recipients($db, $eventId);
            $body = mb_substr(trim($content) === '' ? '（無內容）' : trim($content), 0, 480);
            eg_push_send_to_users($db, $recipients, [
                'title'   => $title,
                'body'    => $body,
                'tag'     => 'qa-event-' . $eventId,
                'url'     => $opts['url'] ?? ('/EGsystem/views/QA/qa_abnormal_view.php?event=' . $eventId),
                'eventId' => $eventId,
            // 帶 event_id：異常單可對「部門」發通知，部門廣播不逐人轉送到共用帳號（見 ai-rules/13）
            ], ['event_id' => $eventId]);
        } catch (Throwable $e) {
            error_log('[qa_notify] push failed: ' . $e->getMessage());
        }

        // Telegram 推播（2026-07-07 恢復啟用；與 Web Push 並行；涵蓋開單、他人回覆/回簽、部門歸還等所有 QA 通知，
        // 未綁定者自動跳過，失敗不影響）
        try {
            require_once __DIR__ . '/../../telegram/notify_event.php';
            eg_telegram_for_event($db, $eventId);
        } catch (Throwable $e) {
            error_log('[qa_notify] telegram push failed: ' . $e->getMessage());
        }
        return $eventId;
    }
}

if (!function_exists('eg_qa_order_editors')) {
    /** 取異常單的共同編輯者 [{type:'dept'|'user', id, name}]（表不存在回傳空陣列） */
    function eg_qa_order_editors(PDO $db, int $orderId): array {
        try {
            $st = $db->prepare("SELECT e.editor_type, e.editor_id,
                                       CASE WHEN e.editor_type='dept' THEN d.name ELSE u.user_cname END AS name
                                FROM qa_abnormal_editor e
                                LEFT JOIN department d ON e.editor_type='dept' AND d.id = e.editor_id
                                LEFT JOIN user u ON e.editor_type='user' AND u.id = e.editor_id
                                WHERE e.abnormal_order_id = ? ORDER BY e.id");
            $st->execute([$orderId]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['type' => $r['editor_type'], 'id' => (int)$r['editor_id'], 'name' => (string)($r['name'] ?? ('#' . $r['editor_id']))];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_qa_user_is_order_editor')) {
    /** 本人是否為異常單的共同編輯者（直接指定，或本人部門(含兼任)被指定） */
    function eg_qa_user_is_order_editor(PDO $db, int $orderId, int $uid): bool {
        try {
            $ds = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=?");
            $ds->execute([$uid]);
            $deptIds = array_map('intval', $ds->fetchAll(PDO::FETCH_COLUMN));
            foreach (eg_qa_order_editors($db, $orderId) as $e) {
                if ($e['type'] === 'user' && $e['id'] === $uid) return true;
                if ($e['type'] === 'dept' && in_array($e['id'], $deptIds, true)) return true;
            }
            return false;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('eg_qa_create_notice')) {
    /**
     * 開單後建立主通知。
     * $opts = [
     *   'flows'         => [ ['dept_id'=>N,'user_id'=>N|null], ... ]  回覆部門(義務=回覆+回簽)
     *   'notify_users'  => [uid,...]   通知人員(義務=已閱)；空陣列時套預設名單
     *   'follower_users'=> [uid,...]   追蹤人員(義務=已閱、回覆時被通知)；空陣列時套預設名單
     *   'reply_deadline'=> 'Y-m-d'|null
     *   'created_by'    => uid
     * ]
     * 回傳 ['event_id'=>N, 'followers'=>[...]]；失敗回傳 ['event_id'=>0, 'followers'=>[]]。
     */
    function eg_qa_create_notice(PDO $db, int $orderId, array $opts = []): array {
        try {
            eg_qa_notify_schema($db);
            $o = eg_qa_order_brief($db, $orderId);
            if (!$o) return ['event_id' => 0, 'followers' => []];

            $flows = is_array($opts['flows'] ?? null) ? $opts['flows'] : [];
            // 有明確傳入陣列(含空陣列)就照用；完全未傳(null)才套預設名單
            $notify    = is_array($opts['notify_users'] ?? null)   ? array_map('intval', $opts['notify_users'])   : eg_qa_default_users($db, 'qa_notify_default_users');
            $followers = is_array($opts['follower_users'] ?? null) ? array_map('intval', $opts['follower_users']) : eg_qa_default_users($db, 'qa_follower_default_users');
            $followers = array_slice(array_values(array_unique(array_filter($followers))), 0, 5);

            // 記錄追蹤人員
            $db->prepare("DELETE FROM qa_abnormal_follower WHERE abnormal_order_id=?")->execute([$orderId]);
            $insF = $db->prepare("INSERT IGNORE INTO qa_abnormal_follower (abnormal_order_id, user_id) VALUES (?,?)");
            foreach ($followers as $f) $insF->execute([$orderId, $f]);

            // 對象：回覆部門(指定人員→人，否則整個部門)＝回覆回簽；責任部門/人員也需回覆
            $targets = [];
            foreach ($flows as $f) {
                if (!empty($f['user_id'])) $targets[] = ['type'=>'user', 'id'=>(int)$f['user_id'], 'mode'=>'reply'];
                elseif (!empty($f['dept_id'])) $targets[] = ['type'=>'dept', 'id'=>(int)$f['dept_id'], 'mode'=>'reply'];
            }
            if ($o['responsible_type'] === 'dept') {
                if (!empty($o['responsible_person_id'])) $targets[] = ['type'=>'user', 'id'=>(int)$o['responsible_person_id'], 'mode'=>'reply'];
                elseif (!empty($o['responsible_dept_id'])) $targets[] = ['type'=>'dept', 'id'=>(int)$o['responsible_dept_id'], 'mode'=>'reply'];
            }
            foreach ($notify as $u)    $targets[] = ['type'=>'user', 'id'=>$u, 'mode'=>'read'];
            foreach ($followers as $u) $targets[] = ['type'=>'user', 'id'=>$u, 'mode'=>'read'];
            if (empty($targets)) return ['event_id' => 0, 'followers' => $followers];

            $title = '📋 品質異常單 ' . $o['abnormal_order_no'] . ' 請處理';
            $lines = [];
            $lines[] = '異常單號：' . $o['abnormal_order_no'];
            if ($o['source_desc'])          $lines[] = '來　　源：' . $o['source_desc'];
            if ($o['abnormal_type_name'])   $lines[] = '異常種類：' . $o['abnormal_type_name'];
            if ($o['occurrence_date'])      $lines[] = '發生日期：' . $o['occurrence_date'];
            if ($o['sqty'] !== null && $o['sqty'] !== '') $lines[] = '異常數量：' . $o['sqty'];
            if ($o['resp_desc'])            $lines[] = '責任單位：' . $o['resp_desc'];
            if ($o['abnormal_phenomenon'])  $lines[] = '異常現象：' . $o['abnormal_phenomenon'];
            if ($o['disposition'])          $lines[] = '處置方式：' . $o['disposition'];
            // 公告者：開單人與共同編輯者並列
            $editors = eg_qa_order_editors($db, $orderId);
            $editorNames = array_map(function ($e) { return $e['name'] . ($e['type'] === 'dept' ? '(部門)' : ''); }, $editors);
            if (!empty($editorNames)) {
                $lines[] = '公 告 者：' . ($o['created_by_name'] ?: '') . '、' . implode('、', $editorNames);
            } else {
                $lines[] = '開 單 人：' . ($o['created_by_name'] ?: '');
            }
            $lines[] = '（請開啟異常單查看詳情並回覆）';

            $eventId = eg_qa_insert_event($db, $orderId, $title, implode("\n", $lines), $targets,
                                          ($opts['reply_deadline'] ?? null), (int)($opts['created_by'] ?? $o['created_by'] ?? 0));
            if ($eventId) {
                $db->prepare("UPDATE qa_abnormal_order SET notify_event_id=? WHERE id=?")->execute([$eventId, $orderId]);
                // 共同編輯者同步掛到通知本身（live_event_editor），使其可在公告/通知頁修改此通知
                try {
                    $insE = $db->prepare("INSERT IGNORE INTO live_event_editor (live_event_id, editor_type, editor_id) VALUES (?,?,?)");
                    foreach ($editors as $e) $insE->execute([$eventId, $e['type'], $e['id']]);
                } catch (Throwable $e) { /* 表不存在時忽略 */ }
            }
            return ['event_id' => $eventId, 'followers' => $followers];
        } catch (Throwable $e) {
            error_log('[qa_notify] create notice failed: ' . $e->getMessage());
            return ['event_id' => 0, 'followers' => []];
        }
    }
}

if (!function_exists('eg_qa_reply_recipients')) {
    /** 開單人＋追蹤人員（排除觸發者本人） */
    function eg_qa_reply_recipients(PDO $db, array $order, int $actorUid): array {
        $ids = [];
        if (!empty($order['created_by'])) $ids[] = (int)$order['created_by'];
        try {
            $st = $db->prepare("SELECT user_id FROM qa_abnormal_follower WHERE abnormal_order_id=?");
            $st->execute([(int)$order['id']]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[] = (int)$u;
        } catch (Throwable $e) {}
        return array_values(array_diff(array_unique(array_filter($ids)), [$actorUid]));
    }
}

if (!function_exists('eg_qa_notify_reply')) {
    /**
     * 通知系統內對異常單通知「回簽/回覆」後 → 建立含回覆內容的已閱通知給開單人＋追蹤人員。
     * 由 _eventRespond.php 在 ref_type='QA' 時呼叫。失敗不影響回覆本身。
     */
    function eg_qa_notify_reply(PDO $db, int $orderId, int $actorUid, string $action, string $content): void {
        try {
            eg_qa_notify_schema($db);
            $o = eg_qa_order_brief($db, $orderId);
            if (!$o) return;
            $recipients = eg_qa_reply_recipients($db, $o, $actorUid);
            if (empty($recipients)) return;

            $nm = $db->prepare("SELECT user_cname FROM user WHERE id=?");
            $nm->execute([$actorUid]);
            $actorName = $nm->fetchColumn() ?: ('#' . $actorUid);

            $verb  = $action === 'reply' ? '回覆' : '回簽';
            $title = '🔔 異常單 ' . $o['abnormal_order_no'] . ' 已' . $verb . '（' . $actorName . '）';
            $lines = ['異常單號：' . $o['abnormal_order_no'], $actorName . ' 已於 ' . date('Y-m-d H:i') . ' ' . $verb . '。'];
            if (trim($content) !== '') $lines[] = '回覆內容：' . "\n" . trim($content);

            $targets = array_map(fn($u) => ['type'=>'user', 'id'=>$u, 'mode'=>'read'], $recipients);
            eg_qa_insert_event($db, $orderId, $title, implode("\n", $lines), $targets, null, $actorUid);
        } catch (Throwable $e) {
            error_log('[qa_notify] notify reply failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_qa_sync_flow_reply')) {
    /**
     * 通知系統的回簽/回覆 → 同步回寫異常單流程(qa_abnormal_order_flow)，
     * 讓回覆資料保存在異常單內（IR/異常單頁面看到的流程狀態一致）。
     * 比對規則：flow 指定人員=本人，或 flow 未指定人員且部門為本人所屬部門(含兼任)。
     */
    function eg_qa_sync_flow_reply(PDO $db, int $orderId, int $uid, ?string $content): void {
        try {
            $ds = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=?");
            $ds->execute([$uid]);
            $myDepts = array_map('intval', $ds->fetchAll(PDO::FETCH_COLUMN));

            $fs = $db->prepare("SELECT flow_id, dept_id, user_id, status FROM qa_abnormal_order_flow WHERE abnormal_order_id=?");
            $fs->execute([$orderId]);
            $upd = $db->prepare("UPDATE qa_abnormal_order_flow
                                 SET status='Returned', user_id=COALESCE(user_id, ?),
                                     receive_date=COALESCE(receive_date, NOW()), return_date=NOW(),
                                     reply_content=COALESCE(?, reply_content), updated_at=NOW()
                                 WHERE flow_id=?");
            foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
                if ($f['status'] === 'Returned') continue;
                $mine = ((int)$f['user_id'] === $uid)
                     || (empty($f['user_id']) && in_array((int)$f['dept_id'], $myDepts, true));
                if ($mine) $upd->execute([$uid, ($content !== null && trim($content) !== '' ? trim($content) : null), $f['flow_id']]);
            }
        } catch (Throwable $e) {
            error_log('[qa_notify] sync flow reply failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_qa_supervisors')) {
    /** 主管清單：角色明確勾選「認定為主管」(qc_supervisor) 的使用者 id。
     *  注意：主管≠管理員——管理員(all)不自動列入通知對象，需另指派主管角色功能。 */
    function eg_qa_supervisors(PDO $db): array {
        try {
            $rows = $db->query("SELECT DISTINCT ur.user_id FROM user_roles ur
                                JOIN role_features rf ON rf.role_id = ur.role_id
                                WHERE rf.feature_code = 'qc_supervisor'")->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_unique(array_map('intval', $rows)));
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_qa_notify_edit_request')) {
    /**
     * 使用者提出異常單修改請求 → 通知所有 QC 主管（ref_type='QA_EDIT_REQ', ref_id=請求id）。
     * 主管點通知即開品管合併檢驗頁的核准畫面（含快速「開放修改」按鈕）。
     */
    function eg_qa_notify_edit_request(PDO $db, int $requestId): void {
        try {
            eg_qa_notify_schema($db);
            $st = $db->prepare("SELECT r.*, o.abnormal_order_no, u.user_cname AS requester_name
                                FROM qa_abnormal_edit_request r
                                JOIN qa_abnormal_order o ON o.id = r.abnormal_order_id
                                LEFT JOIN user u ON u.id = r.requested_by
                                WHERE r.id = ?");
            $st->execute([$requestId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return;
            $sups = array_values(array_diff(eg_qa_supervisors($db), [(int)$r['requested_by']]));
            if (empty($sups)) return;

            $title = '🔓 異常單 ' . $r['abnormal_order_no'] . ' 修改請求（' . ($r['requester_name'] ?: '#' . $r['requested_by']) . '）';
            $lines = [
                '異常單號：' . $r['abnormal_order_no'],
                '請 求 人：' . ($r['requester_name'] ?: '#' . $r['requested_by']),
                '修改原因：' . $r['reason'],
                '（點開此通知可直接檢視並「開放修改」；開放後僅提出請求者本人可修改此異常單）',
            ];
            $targets = array_map(fn($u) => ['type'=>'user', 'id'=>$u, 'mode'=>'read'], $sups);
            eg_qa_insert_event($db, (int)$r['abnormal_order_id'], $title, implode("\n", $lines), $targets, null, (int)$r['requested_by'], [
                'ref_type' => 'QA_EDIT_REQ',
                'ref_id'   => $requestId,
                'url'      => '/EGsystem/views/QC/inspection_combined_prototype.php?edit_request=' . $requestId,
            ]);
        } catch (Throwable $e) {
            error_log('[qa_notify] notify edit request failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_qa_notify_edit_granted')) {
    /** 主管開放修改後 → 通知提出請求者（ref_type='QA_EDIT_OK', ref_id=異常單id，點開直接進修改畫面） */
    function eg_qa_notify_edit_granted(PDO $db, int $requestId, int $approverUid): void {
        try {
            eg_qa_notify_schema($db);
            $st = $db->prepare("SELECT r.*, o.abnormal_order_no, u.user_cname AS approver_name
                                FROM qa_abnormal_edit_request r
                                JOIN qa_abnormal_order o ON o.id = r.abnormal_order_id
                                LEFT JOIN user u ON u.id = " . (int)$approverUid . "
                                WHERE r.id = ?");
            $st->execute([$requestId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return;

            $title = '✅ 異常單 ' . $r['abnormal_order_no'] . ' 已開放您修改';
            $lines = [
                '異常單號：' . $r['abnormal_order_no'],
                '核准主管：' . ($r['approver_name'] ?: '#' . $approverUid),
                '（僅您本人可修改此異常單，點開此通知直接進入修改畫面）',
            ];
            $targets = [['type'=>'user', 'id'=>(int)$r['requested_by'], 'mode'=>'read']];
            eg_qa_insert_event($db, (int)$r['abnormal_order_id'], $title, implode("\n", $lines), $targets, null, $approverUid, [
                'ref_type' => 'QA_EDIT_OK',
                'ref_id'   => (int)$r['abnormal_order_id'],
                'url'      => '/EGsystem/views/QC/inspection_combined_prototype.php?edit_abnormal=' . (int)$r['abnormal_order_id'],
            ]);
        } catch (Throwable $e) {
            error_log('[qa_notify] notify edit granted failed: ' . $e->getMessage());
        }
    }
}

// =============================================================================
// 處置決策流程（2026-07-07）：首要決策者 → (需要時) 最終決策者，各自搭配 HR 代理人
//  - 設定存 qa_system_settings：qa_qc_dept_ids(品管單位部門JSON) / qa_primary_decider / qa_final_decider
//  - 代理人沿用 hr_settings 的 user_delegate（依日期區間與 priority）
//  - 決策者「當下或今日稍後」有行程 → 首要與代理人都通知，通知附行程時段，任一人判定後其他人要求自動消失
//  - 通知 ref_type：QA_DECIDE=待處置判定(首要階段)、QA_DECIDE_F=待最終裁決
// =============================================================================

if (!function_exists('eg_qa_decision_setting')) {
    /** 讀決策設定，回傳 ['qc_dept_ids'=>[], 'primary'=>uid|0, 'final'=>uid|0] */
    function eg_qa_decision_setting(PDO $db): array {
        $get = function ($key) use ($db) {
            try {
                $st = $db->prepare("SELECT setting_value FROM qa_system_settings WHERE setting_key=?");
                $st->execute([$key]);
                return $st->fetchColumn();
            } catch (Throwable $e) { return null; }
        };
        // 品管部門一律讀全站「組織角色綁定設定」的 qc_dept（含子部門），本模組不再自設一份（2026-08-03）；
        // 舊 qa_system_settings.qa_qc_dept_ids 只在統一設定尚未綁定時當回退值，避免切換當下出現空窗。
        require_once __DIR__ . '/org_role_lib.php';
        $deptIds = eg_org_dept_ids($db, 'qc_dept');
        if (!$deptIds) $deptIds = json_decode((string)$get('qa_qc_dept_ids'), true);
        $secondary = json_decode((string)$get('qa_secondary_deciders'), true);
        return [
            'qc_dept_ids' => is_array($deptIds) ? array_values(array_map('intval', $deptIds)) : [],
            'primary'     => (int)$get('qa_primary_decider'),
            'final'       => (int)$get('qa_final_decider'),
            'secondary'   => is_array($secondary) ? array_values(array_map('intval', $secondary)) : [], // 次要決策者（有序）
        ];
    }
}

if (!function_exists('eg_qa_active_deputies')) {
    /** 某人今日生效的代理人（user_delegate，active=1 且日期涵蓋今天，依 priority），回傳 [['id'=>uid,'name'=>..],..] */
    function eg_qa_active_deputies(PDO $db, int $uid): array {
        try {
            $st = $db->prepare("SELECT ud.delegate_id AS id, u.user_cname AS name
                                FROM user_delegate ud JOIN user u ON u.id = ud.delegate_id
                                WHERE ud.user_id = ? AND ud.active = 1
                                  AND ud.start_date <= CURDATE() AND ud.end_date >= CURDATE()
                                ORDER BY ud.priority ASC");
            $st->execute([$uid]);
            return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_qa_user_busy_today')) {
    /**
     * 某人「現在起到今日結束」的行程清單（行事曆個人事件 + 已核准請假單）。
     * 回傳 [['start'=>'HH:MM','end'=>'HH:MM','label'=>'會議〈品質會議〉'], ...]；空陣列=今日剩餘時間無行程。
     * 行程類別：day_type 為空的個人事件（休假/會議/公出/外訓/來訪…），排除「通知」類；全天假日(s/m)不算個人行程。
     */
    function eg_qa_user_busy_today(PDO $db, int $uid): array {
        $busy = [];
        $now = date('Y-m-d H:i:s');
        $dayEnd = date('Y-m-d') . ' 23:59:59';
        try {
            $st = $db->prepare("SELECT e.title, e.start, e.end, e.allday, ec.category_name
                                FROM evenement e
                                JOIN evenement_actor a ON a.event_id = e.id
                                LEFT JOIN event_category ec ON ec.id = e.category_id
                                WHERE a.user_id = ?
                                  AND (ec.day_type IS NULL OR ec.day_type = '')
                                  AND ec.category_name <> '通知'
                                  AND e.start <= ? AND e.end >= ?");
            $st->execute([$uid, $dayEnd, $now]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $busy[] = [
                    'start' => $e['allday'] ? '整天' : date('H:i', strtotime($e['start'])),
                    'end'   => $e['allday'] ? '' : date('H:i', strtotime($e['end'])),
                    'label' => ($e['category_name'] ?: '行程') . ($e['title'] ? '〈' . $e['title'] . '〉' : ''),
                    'leave' => (strpos((string)$e['category_name'], '休假') !== false), // 行事曆「休假」類=請假
                ];
            }
        } catch (Throwable $e) { /* 行事曆查詢失敗不擋流程 */ }
        try {
            $st = $db->prepare("SELECT lr.start_datetime, lr.end_datetime, lt.leave_name
                                FROM leave_request lr LEFT JOIN leave_type lt ON lt.id = lr.leave_type_id
                                WHERE lr.employee_id = ? AND lr.status IN ('approved','核准')
                                  AND lr.start_datetime <= ? AND lr.end_datetime >= ?");
            $st->execute([$uid, $dayEnd, $now]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $busy[] = [
                    'start' => date('H:i', strtotime(max($r['start_datetime'], date('Y-m-d') . ' 00:00:00'))),
                    'end'   => date('H:i', strtotime(min($r['end_datetime'], $dayEnd))),
                    'label' => '請假' . ($r['leave_name'] ? '（' . $r['leave_name'] . '）' : ''),
                    'leave' => true,
                ];
            }
        } catch (Throwable $e) { /* 請假單查詢失敗不擋流程 */ }
        return $busy;
    }
}

if (!function_exists('eg_qa_busy_split')) {
    /** 行程清單拆成 [請假清單, 一般行程清單]（請假=行事曆休假類事件或已核准請假單） */
    function eg_qa_busy_split(array $busy): array {
        $leave = []; $sched = [];
        foreach ($busy as $b) { if (!empty($b['leave'])) $leave[] = $b; else $sched[] = $b; }
        return [$leave, $sched];
    }
}

if (!function_exists('eg_qa_busy_text')) {
    /** 行程清單轉為通知文字，例：「10:00–12:00 會議〈品質會議〉、整天 請假（特休）」 */
    function eg_qa_busy_text(array $busy): string {
        return implode('、', array_map(function ($b) {
            $t = $b['start'] === '整天' ? '整天' : ($b['start'] . ($b['end'] ? '–' . $b['end'] : ''));
            return $t . ' ' . $b['label'];
        }, $busy));
    }
}

if (!function_exists('eg_qa_notify_decision')) {
    /**
     * 發出處置決策通知（$stage：'primary'=首要判定 / 'final'=最終裁決）。
     * 規則（使用者定案）：
     *  - 決策者未請假：通知本人；本人今日尚有(非請假)行程 → 一併通知其未請假的代理人（附行程時段）。
     *  - 決策者請假：不通知本人，改通知「其代理人＋（首要階段）次要決策者」；
     *      候選人中請假者不通知（直接由未請假的一方），有行程者比照上述一併通知其代理人；
     *      多人同時可判定時，任一人完成判定其他人即無須處理（見 eg_qa_close_decision_events）。
     *  - 全部候選人都請假 → 仍通知決策者與所有候選人（避免無人收到）。
     * 回傳 live_event id（未設定決策者或失敗回傳 0）。
     */
    function eg_qa_notify_decision(PDO $db, int $orderId, string $stage, int $actorUid = 0): int {
        try {
            eg_qa_notify_schema($db);
            $o = eg_qa_order_brief($db, $orderId);
            if (!$o) return 0;
            $cfg = eg_qa_decision_setting($db);
            $decider = $stage === 'final' ? $cfg['final'] : $cfg['primary'];
            if ($decider <= 0) return 0; // 尚未設定決策者 → 不發（由呼叫端提示）

            $nameOf = function (int $uid) use ($db) {
                $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
                $st->execute([$uid]);
                return $st->fetchColumn() ?: ('#' . $uid);
            };
            $roleName = $stage === 'final' ? '最終決策者' : '首要決策者';
            $deciderName = $nameOf($decider);

            $recipients = []; // uid => 1（通知對象，去重）
            $notes = [];      // 情境說明行
            // 加入某人；若其今日尚有(非請假)行程 → 附行程時段，且（$expandDeputies=true 時）一併通知其「未請假」的代理人。
            // 代理不遞延：以「代理人」身分被通知者 $expandDeputies=false——其自己的代理人不遞補其所代理的職位
            // （否則互為代理時會把已請假的原決策者拉回名單，權責鏈也會無限延伸）。
            // $excludeUids：展開代理人時明確排除的對象（原決策者已請假時，即使邊界判定不一致也絕不重新通知）。
            $addWithDeputies = function (int $uid, string $desc, bool $expandDeputies = true, array $excludeUids = []) use (&$recipients, &$notes, $db, $nameOf) {
                $recipients[$uid] = 1;
                [, $sched] = eg_qa_busy_split(eg_qa_user_busy_today($db, $uid));
                if (!empty($sched)) {
                    $notes[] = '⚠ ' . $nameOf($uid) . ($desc ? '（' . $desc . '）' : '') . ' 今日行程：' . eg_qa_busy_text($sched);
                    if (!$expandDeputies) return;
                    $depNames = [];
                    foreach (eg_qa_active_deputies($db, $uid) as $d) {
                        $did = (int)$d['id'];
                        if (in_array($did, $excludeUids, true)) continue; // 明確排除（如已請假的原決策者）
                        [$dl, ] = eg_qa_busy_split(eg_qa_user_busy_today($db, $did));
                        if (!empty($dl)) continue; // 代理人本身請假 → 不通知
                        if (!isset($recipients[$did])) { $recipients[$did] = 1; $depNames[] = $d['name']; }
                    }
                    if (!empty($depNames)) $notes[] = '　已同步通知其代理人（' . implode('、', $depNames) . '）——可視上述行程自行決定是否代為判定。';
                }
            };

            [$mainLeave, ] = eg_qa_busy_split(eg_qa_user_busy_today($db, $decider));
            if (empty($mainLeave)) {
                // 決策者未請假：通知本人（有行程時連帶其代理人）
                $addWithDeputies($decider, $roleName);
            } else {
                // 決策者請假：不通知本人，改通知 代理人＋（首要階段）次要決策者
                $notes[] = '⚠ ' . $roleName . ' ' . $deciderName . ' 今日請假（' . eg_qa_busy_text($mainLeave) . '），由下列人員代為判定：';
                $candidates = []; // [uid, 角色說明]
                foreach (eg_qa_active_deputies($db, $decider) as $d) $candidates[] = [(int)$d['id'], $roleName . '代理人'];
                if ($stage !== 'final') {
                    foreach ($cfg['secondary'] as $sid) {
                        $sid = (int)$sid;
                        if ($sid > 0 && $sid !== $decider && $sid !== (int)$cfg['final']) $candidates[] = [$sid, '次要決策者'];
                    }
                }
                $added = 0; $skipped = []; $seen = [];
                foreach ($candidates as [$cid, $desc]) {
                    if ($cid <= 0 || isset($seen[$cid])) continue;
                    $seen[$cid] = 1;
                    [$cl, ] = eg_qa_busy_split(eg_qa_user_busy_today($db, $cid));
                    if (!empty($cl)) { $skipped[] = $nameOf($cid) . '（' . $desc . '）'; continue; } // 請假的一方不通知
                    $notes[] = '・' . $nameOf($cid) . '（' . $desc . '）';
                    // 代理人身分（如首要決策者代理人）→ 代理不遞延，其自己的代理人不再展開；
                    // 次要決策者是自己的職位 → 有行程時照規則連帶其代理人（但排除已請假的原決策者，避免互為代理繞回）
                    $isDeputyRole = (strpos($desc, '代理人') !== false);
                    $addWithDeputies($cid, $desc, !$isDeputyRole, [$decider]);
                    $added++;
                }
                if (!empty($skipped)) $notes[] = '（今日請假未通知：' . implode('、', $skipped) . '）';
                if ($added === 0) {
                    if ($stage !== 'final' && (int)$cfg['final'] > 0) {
                        // 首要決策者與其代理人/次要決策者全數請假 → 直接通知最終決策者代為判定
                        //（無論最終決策者請假與否都發送，確保一定有人收到；內容已列出前端決策者請假狀態）
                        $finalUid = (int)$cfg['final'];
                        $notes[] = '⚠ 首要決策者與其代理人／次要決策者今日皆請假，已直接通知最終決策者代為判定。';
                        [$fl, ] = eg_qa_busy_split(eg_qa_user_busy_today($db, $finalUid));
                        if (!empty($fl)) $notes[] = '（最終決策者 ' . $nameOf($finalUid) . ' 今日亦請假：' . eg_qa_busy_text($fl) . '，仍發送通知避免無人收到）';
                        $recipients[$finalUid] = 1;
                    } else {
                        // 最終階段全員請假（或未設定最終決策者可承接）→ 全數通知，避免無人收到
                        $notes[] = '⚠ 決策者與可代理人選今日皆請假，已全數通知，請擇一儘速處理。';
                        $recipients[$decider] = 1;
                        foreach ($candidates as [$cid, ]) { if ($cid > 0) $recipients[$cid] = 1; }
                    }
                }
            }
            if (count($recipients) > 1) $notes[] = '（以上任一人完成判定後，其他人即無須處理）';

            $title = ($stage === 'final' ? '⚖️ 品質異常單 ' . $o['abnormal_order_no'] . ' 待最終裁決'
                                          : '🖋 品質異常單 ' . $o['abnormal_order_no'] . ' 待處置判定');
            $lines = ['異常單號：' . $o['abnormal_order_no']];
            if ($o['source_desc'])         $lines[] = '來　　源：' . $o['source_desc'];
            if ($o['abnormal_phenomenon']) $lines[] = '異常現象：' . $o['abnormal_phenomenon'];
            if ($stage === 'final' && $o['disposition']) $lines[] = '首要判定：' . $o['disposition'] . ($o['disposition_note'] ? '｜' . $o['disposition_note'] : '');
            $lines[] = $roleName . '：' . $deciderName;
            foreach ($notes as $n) $lines[] = $n;
            $lines[] = '（點開此通知即可進行' . ($stage === 'final' ? '最終裁決' : '處置判定') . '）';

            $targets = [];
            foreach (array_keys($recipients) as $uid) $targets[] = ['type' => 'user', 'id' => (int)$uid, 'mode' => 'sign'];

            return eg_qa_insert_event($db, $orderId, $title, implode("\n", $lines), $targets, null,
                ($actorUid ?: (int)($o['created_by'] ?? 0)), [
                    'ref_type' => $stage === 'final' ? 'QA_DECIDE_F' : 'QA_DECIDE',
                    'ref_id'   => $orderId,
                    'url'      => '/EGsystem/views/QC/inspection_combined_prototype.php?decide_abnormal=' . $orderId,
                ]);
        } catch (Throwable $e) {
            error_log('[qa_notify] notify decision failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('eg_qa_close_decision_events')) {
    /**
     * 任一人完成判定 → 結束該異常單此階段的決策通知：
     * 將事件 enddate 設為昨天（置頂欄/鈴鐺只列未過期通知，全部對象的未讀要求立即消失），
     * 並為判定者寫入回應（已閱+回簽），保留誰判定的紀錄。
     */
    function eg_qa_close_decision_events(PDO $db, int $orderId, string $stage, int $deciderUid): void {
        try {
            $refType = $stage === 'final' ? 'QA_DECIDE_F' : 'QA_DECIDE';
            $st = $db->prepare("SELECT id FROM live_event WHERE ref_type = ? AND ref_id = ? AND (enddate IS NULL OR enddate >= CURDATE())");
            $st->execute([$refType, $orderId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $eid = (int)$eid;
                $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?")->execute([$eid]);
                $rs = $db->prepare("SELECT id FROM live_event_response WHERE live_event_id=? AND user_id=?");
                $rs->execute([$eid, $deciderUid]);
                if ($rid = $rs->fetchColumn()) {
                    $db->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()), signed_at=COALESCE(signed_at,NOW()) WHERE id=?")->execute([$rid]);
                } else {
                    $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at) VALUES (?,?,NOW(),NOW())")->execute([$eid, $deciderUid]);
                }
            }
        } catch (Throwable $e) {
            error_log('[qa_notify] close decision events failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_qa_decision_allowed_users')) {
    /** 目前有效決策通知的對象（決策者+代理人）user id 清單（授權檢查用） */
    function eg_qa_decision_allowed_users(PDO $db, int $orderId, string $stage): array {
        try {
            $refType = $stage === 'final' ? 'QA_DECIDE_F' : 'QA_DECIDE';
            $st = $db->prepare("SELECT DISTINCT t.target_id FROM live_event le
                                JOIN live_event_target t ON t.live_event_id = le.id AND t.target_type='user'
                                WHERE le.ref_type = ? AND le.ref_id = ? AND (le.enddate IS NULL OR le.enddate >= CURDATE())");
            $st->execute([$refType, $orderId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_qa_notify_flow_return')) {
    /** 部門於異常單流程回覆歸還(flow_return)時 → 通知開單人＋追蹤人員 */
    function eg_qa_notify_flow_return(PDO $db, int $orderId, string $deptName, int $actorUid, string $content): void {
        try {
            eg_qa_notify_schema($db);
            $o = eg_qa_order_brief($db, $orderId);
            if (!$o) return;
            $recipients = eg_qa_reply_recipients($db, $o, $actorUid);
            if (empty($recipients)) return;

            $title = '🔔 異常單 ' . $o['abnormal_order_no'] . ' 部門已回覆' . ($deptName ? '（' . $deptName . '）' : '');
            $lines = ['異常單號：' . $o['abnormal_order_no'], ($deptName ?: '部門') . ' 已於 ' . date('Y-m-d H:i') . ' 回覆歸還。'];
            if (trim($content) !== '') $lines[] = '回覆內容：' . "\n" . trim($content);

            $targets = array_map(fn($u) => ['type'=>'user', 'id'=>$u, 'mode'=>'read'], $recipients);
            eg_qa_insert_event($db, $orderId, $title, implode("\n", $lines), $targets, null, $actorUid);
        } catch (Throwable $e) {
            error_log('[qa_notify] notify flow_return failed: ' . $e->getMessage());
        }
    }
}
