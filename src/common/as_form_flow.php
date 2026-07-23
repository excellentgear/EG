<?php
// as_form_flow.php — AS 線上表單：簽核者解析 + 通知（比照 quotation_approval.php / car_lib.php 慣例）
// 事實紀錄存專用表 as_form_approval（多簽核區，section_key+step_no）；本檔只放規則解析與通知。
// 簽核區規則（schema.sections[].rule）：
//   {type:'submitter'}                                → 填表本人（送出即自動完成該區）
//   {type:'position', position_id|position, dept_id?, scope?:'apply_dept'} → 指定職稱（可限定部門；scope=apply_dept 用填表人主部門）
//   {type:'level', min_level, dept_id?, scope?}       → N階主管以上（position_level.level<=N；預設限填表人主部門）

if (!function_exists('asf_user_main_dept')) {
    /** 使用者主要部門 id（is_main 優先），查無回 0 */
    function asf_user_main_dept(PDO $pdo, int $uid): int {
        try {
            $st = $pdo->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=? ORDER BY is_main DESC LIMIT 1");
            $st->execute([$uid]);
            return (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('asf_user_title')) {
    /** 使用者主要「部門/職稱」字串（簽章顯示用） */
    function asf_user_title(PDO $pdo, int $uid): string {
        try {
            $st = $pdo->prepare("SELECT d.name dn, p.name pn FROM user_department_position_map m
                JOIN department d ON d.id=m.department_id JOIN position p ON p.id=m.position_id
                WHERE m.user_id=? ORDER BY m.is_main DESC LIMIT 1");
            $st->execute([$uid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? ($r['dn'].'/'.$r['pn']) : '';
        } catch (Throwable $e) { return ''; }
    }
}

if (!function_exists('asf_resolve_approvers')) {
    /**
     * 規則 → 有資格簽核的在職使用者 id 清單。
     * $submitterUid 供 submitter 與 scope='apply_dept' 解析；$data＝填寫值（dept_manager 依表上部門欄位解析用）。
     * 空清單＝規則解不出人（呼叫端要擋）。
     */
    function asf_resolve_approvers(PDO $pdo, array $rule, int $submitterUid, array $data = []): array {
        $type = $rule['type'] ?? '';
        try {
            if ($type === 'submitter') return $submitterUid ? [$submitterUid] : [];

            if ($type === 'dept_manager') {
                // 單位主管：依「表上選定的部門欄位值」決定單位——申請人兼職時以表上所選為準
                $src  = (string)($rule['dept_source'] ?? '');
                $dval = trim((string)($data[$src] ?? ''));
                if ($dval === '') return [];
                $st = $pdo->prepare("SELECT id FROM department WHERE name=? LIMIT 1");
                $st->execute([$dval]);
                $deptId = (int)($st->fetchColumn() ?: 0);
                if (!$deptId && ctype_digit($dval)) $deptId = (int)$dval;   // 欄位存的是 id 也通
                if (!$deptId) return [];
                // 門檻：mode=position（指定職稱以上→取該職稱層級當門檻）/ level（N階以上）
                $min = null;
                if (($rule['mode'] ?? 'level') === 'position') {
                    $pid = (int)($rule['position_id'] ?? 0);
                    if ($pid) {
                        $st = $pdo->prepare("SELECT level FROM position_level WHERE position_id=? LIMIT 1");
                        $st->execute([$pid]);
                        $lv = $st->fetchColumn();
                        if ($lv !== false && $lv !== null) $min = (int)$lv;
                    }
                } else {
                    $min = max(1, (int)($rule['min_level'] ?? 2));
                }
                if ($min === null) return [];
                $st = $pdo->prepare("SELECT DISTINCT u.id FROM user_department_position_map m
                        JOIN user u ON u.id=m.user_id
                        JOIN position_level pl ON pl.position_id=m.position_id
                        WHERE pl.level IS NOT NULL AND pl.level<=? AND u.state IN (1,99) AND m.department_id=?");
                $st->execute([$min, $deptId]);
                return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }

            // 部門限定：明給 dept_id > scope=apply_dept（填表人主部門）> 不限
            $deptId = 0;
            if (!empty($rule['dept_id'])) $deptId = (int)$rule['dept_id'];
            elseif (($rule['scope'] ?? '') === 'apply_dept') $deptId = asf_user_main_dept($pdo, $submitterUid);

            if ($type === 'position') {
                // 職稱：id 優先，否則用名稱反查
                $pid = (int)($rule['position_id'] ?? 0);
                if (!$pid && !empty($rule['position'])) {
                    $st = $pdo->prepare("SELECT id FROM position WHERE name=? LIMIT 1");
                    $st->execute([trim((string)$rule['position'])]);
                    $pid = (int)($st->fetchColumn() ?: 0);
                }
                if (!$pid) return [];
                $sql = "SELECT DISTINCT u.id FROM user_department_position_map m JOIN user u ON u.id=m.user_id
                        WHERE m.position_id=? AND u.state IN (1,99)";
                $args = [$pid];
                if ($deptId) { $sql .= " AND m.department_id=?"; $args[] = $deptId; }
                $st = $pdo->prepare($sql); $st->execute($args);
                return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }

            if ($type === 'level') {
                // N階主管以上：position_level.level<=N（1=最高階）。預設限填表人主部門（跨部門主管簽核請明給 dept_id）
                $min = max(1, (int)($rule['min_level'] ?? 1));
                if (!$deptId) $deptId = asf_user_main_dept($pdo, $submitterUid);
                $sql = "SELECT DISTINCT u.id FROM user_department_position_map m
                        JOIN user u ON u.id=m.user_id
                        JOIN position_level pl ON pl.position_id=m.position_id
                        WHERE pl.level IS NOT NULL AND pl.level<=? AND u.state IN (1,99)";
                $args = [$min];
                if ($deptId) { $sql .= " AND m.department_id=?"; $args[] = $deptId; }
                $st = $pdo->prepare($sql); $st->execute($args);
                return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
        } catch (Throwable $e) { error_log('[as_form_flow] resolve failed: '.$e->getMessage()); }
        return [];
    }
}

if (!function_exists('asf_notify_sign')) {
    /** 建立「待簽核」通知（mode=sign，動作完成前不消失）。回傳 live_event id（失敗 0）。 */
    function asf_notify_sign(PDO $pdo, int $instanceId, string $formName, string $sectionLabel, array $approverUids, int $submitterUid, string $submitterName): int {
        if (empty($approverUids)) return 0;
        try {
            $title   = '表單待簽核：'.$formName.'（'.$sectionLabel.'）';
            $content = $submitterName.' 送出「'.$formName.'」，請進行「'.$sectionLabel.'」簽核。';
            $pdo->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, ?, 'AS表單簽核', 1, 'AS_FORM', ?)")
                ->execute([$title, $content, $submitterUid ?: null, $instanceId]);
            $eventId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')");
            foreach ($approverUids as $uid2) $ins->execute([$eventId, (int)$uid2]);
            try {
                require_once __DIR__ . '/../push/push_send.php';
                $recipients = eg_push_event_recipients($pdo, $eventId);
                eg_push_send_to_users($pdo, $recipients, ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
            } catch (Throwable $e) { /* 推播失敗不影響簽核流程 */ }
            return $eventId;
        } catch (Throwable $e) { error_log('[as_form_flow] notify_sign failed: '.$e->getMessage()); return 0; }
    }
}

if (!function_exists('asf_close_sign_notice')) {
    /** 某簽核區完成 → 結束該區待簽通知（比照 quotation eg_quotation_close_approval_notice） */
    function asf_close_sign_notice(PDO $pdo, ?int $liveEventId, int $deciderUid): void {
        if (!$liveEventId) return;
        try {
            $pdo->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=?")->execute([$liveEventId]);
            $rs = $pdo->prepare("SELECT id FROM live_event_response WHERE live_event_id=? AND user_id=?");
            $rs->execute([$liveEventId, $deciderUid]);
            if ($rid = $rs->fetchColumn()) {
                $pdo->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()), signed_at=COALESCE(signed_at,NOW()) WHERE id=?")->execute([$rid]);
            } else {
                $pdo->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at) VALUES (?,?,NOW(),NOW())")->execute([$liveEventId, $deciderUid]);
            }
        } catch (Throwable $e) { error_log('[as_form_flow] close_notice failed: '.$e->getMessage()); }
    }
}

if (!function_exists('asf_notify_result')) {
    /** 全部完成/駁回 → 通知原填表人（mode=read） */
    function asf_notify_result(PDO $pdo, int $instanceId, string $formName, int $submitterUid, string $deciderName, string $decision, ?string $note): void {
        if (!$submitterUid) return;
        try {
            if ($decision === 'approved') {
                $title = '表單簽核完成：'.$formName;
                $content = '「'.$formName.'」已完成全部簽核。';
            } else {
                $title = '表單被駁回：'.$formName;
                $content = $deciderName.' 駁回了「'.$formName.'」，原因：'.($note ?: '（未填寫原因）').'，請修改後重新送出。';
            }
            $pdo->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, NULL, 'AS表單簽核', 1, 'AS_FORM_RESULT', ?)")
                ->execute([$title, $content, $instanceId]);
            $eventId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
                ->execute([$eventId, $submitterUid]);
            try {
                require_once __DIR__ . '/../push/push_send.php';
                $recipients = eg_push_event_recipients($pdo, $eventId);
                eg_push_send_to_users($pdo, $recipients, ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
            } catch (Throwable $e) {}
        } catch (Throwable $e) { error_log('[as_form_flow] notify_result failed: '.$e->getMessage()); }
    }
}
