<?php
/**
 * delegate_lib.php — 全系統唯一的「代理人 / 簽核人」解析庫
 * 規範文件：ai-rules/11-代理系統設計.md
 *
 * 鐵律：任何頁面要「找某人的實際代理／簽核人」，一律呼叫本檔 eg_resolve_signer()，
 *       禁止自己寫 SELECT ... FROM user_delegate。請假系統／AS表單／CAR／報價／QA 一律沿用。
 *
 * 解析優先順序（見 §4）：
 *   1. 判定任務身分（主職 or 指定兼任 scope）
 *   2. 行程閘門：被代理人今日無行程 → 回本人（代理不啟用）
 *   3. BY_PERSON：user_delegate（依 scope + 日期 + active + priority）
 *   4. BY_POSITION：position_delegate → department_position.primary_user_id 解析成人
 *   5. SoD：候選 == 申請人 → 跳過；全數被排除 → 直升上一級主管
 *   6. 寫 audit_log，回傳結果
 *
 * 所有函式皆 fail-open（查詢失敗不擋流程，退回本人），並以 function_exists 包覆避免重複載入衝突。
 */

if (!function_exists('eg_user_busy_today')) {
    /**
     * 某人「現在起到今日結束」的行程清單（行事曆個人事件 + 已核准請假單）。
     * 移植自 qa_notify.php 的 eg_qa_user_busy_today()，為全系統共用版。
     * 回傳 [['start'=>'HH:MM'|'整天','end'=>'HH:MM'|'','label'=>..,'leave'=>bool], ...]；空陣列=今日無行程。
     */
    function eg_user_busy_today(PDO $db, int $uid): array {
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
                                  AND ec.category_name <> '請假申請中'
                                  AND e.start <= ? AND e.end >= ?");
            $st->execute([$uid, $dayEnd, $now]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $busy[] = [
                    'start' => $e['allday'] ? '整天' : date('H:i', strtotime($e['start'])),
                    'end'   => $e['allday'] ? '' : date('H:i', strtotime($e['end'])),
                    'label' => ($e['category_name'] ?: '行程') . ($e['title'] ? '〈' . $e['title'] . '〉' : ''),
                    'leave' => (strpos((string)$e['category_name'], '休假') !== false),
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

if (!function_exists('eg_user_main_identity')) {
    /** 某人的主職身分 ['department_id'=>int,'position_id'=>int,'level'=>?int]；找不到回 null。level 取自 position_level。 */
    function eg_user_main_identity(PDO $db, int $uid): ?array {
        try {
            $st = $db->prepare("SELECT m.department_id, m.position_id, pl.level
                                FROM user_department_position_map m
                                LEFT JOIN position_level pl ON pl.position_id = m.position_id
                                WHERE m.user_id = ? AND m.is_main = 1 LIMIT 1");
            $st->execute([$uid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return [
                'department_id' => (int)$r['department_id'],
                'position_id'   => (int)$r['position_id'],
                'level'         => $r['level'] === null ? null : (int)$r['level'],
            ];
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('eg_person_delegates')) {
    /**
     * BY_PERSON 候選代理人 id 清單（依 priority）。
     * scope 比對：先取「精準符合此職務身分(dep,pos)」的規則；若無，退回「全域(scope 皆 NULL)」規則。
     * 僅取 active=1 且日期涵蓋今天者。
     */
    function eg_person_delegates(PDO $db, int $targetUserId, ?int $scopeDep, ?int $scopePos): array {
        try {
            // 第一層：精準職務身分（scope 有值且完全對應）
            if ($scopeDep !== null && $scopePos !== null) {
                $st = $db->prepare("SELECT delegate_id FROM user_delegate
                                    WHERE user_id = ? AND active = 1
                                      AND start_date <= CURDATE() AND end_date >= CURDATE()
                                      AND scope_department_id = ? AND scope_position_id = ?
                                    ORDER BY priority ASC");
                $st->execute([$targetUserId, $scopeDep, $scopePos]);
                $rows = $st->fetchAll(PDO::FETCH_COLUMN);
                if ($rows) return array_map('intval', $rows);
            }
            // 第二層：全域代理（scope 皆 NULL）
            $st = $db->prepare("SELECT delegate_id FROM user_delegate
                                WHERE user_id = ? AND active = 1
                                  AND start_date <= CURDATE() AND end_date >= CURDATE()
                                  AND scope_department_id IS NULL AND scope_position_id IS NULL
                                ORDER BY priority ASC");
            $st->execute([$targetUserId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_person_delegate_candidates')) {
    /**
     * 某人「目前有效」的候選代理人清單（含姓名，依 priority），供表單下拉選用。
     * 用途：請假單等需要申請人「從已設定的代理人中指定一位」的場景（唯讀，不解析行程/SoD）。
     * 與 eg_person_delegates() 同一套 scope 規則（精準職務身分優先，無則退全域），
     * 若 BY_PERSON 無設定，退回 BY_POSITION（position_delegate → 指定負責人）。
     * 回傳 [['user_id'=>int,'user_cname'=>string,'source'=>'BY_PERSON'|'BY_POSITION'], ...]；空陣列=無任何代理設定。
     */
    function eg_person_delegate_candidates(PDO $db, int $targetUserId, ?int $scopeDep = null, ?int $scopePos = null): array {
        $ids = eg_person_delegates($db, $targetUserId, $scopeDep, $scopePos);
        $source = 'BY_PERSON';
        if (empty($ids)) {
            $ids = eg_position_delegate_persons($db, $targetUserId, $scopeDep, $scopePos);
            $source = 'BY_POSITION';
        }
        if (empty($ids)) return [];
        $out = [];
        try {
            $st = $db->prepare("SELECT user_cname FROM user WHERE id = ? AND state = 1");
            foreach ($ids as $id) {
                if ($id === $targetUserId) continue; // 自己不能當自己的代理
                $st->execute([$id]);
                $name = $st->fetchColumn();
                if ($name === false) continue; // 離職者不列入
                $out[] = ['user_id' => $id, 'user_cname' => (string)$name, 'source' => $source];
            }
        } catch (Throwable $e) { return []; }
        return $out;
    }
}

if (!function_exists('eg_position_delegate_persons')) {
    /**
     * BY_POSITION 候選：position_delegate（主職稱→代理職稱，依 priority）
     *   → 用 department_position.primary_user_id 在「被代理人所屬部門」解析成實際的人。
     * 回傳在職 user id 清單（依代理職稱 priority）。
     */
    function eg_position_delegate_persons(PDO $db, int $targetUserId, ?int $scopeDep, ?int $scopePos): array {
        try {
            $dep = $scopeDep; $pos = $scopePos;
            if ($dep === null || $pos === null) {
                $main = eg_user_main_identity($db, $targetUserId);
                if (!$main) return [];
                $dep = $dep ?? $main['department_id'];
                $pos = $pos ?? $main['position_id'];
            }
            $st = $db->prepare("SELECT pd.delegate_position_id
                                FROM position_delegate pd
                                WHERE pd.position_id = ? ORDER BY pd.priority ASC");
            $st->execute([$pos]);
            $delegatePositions = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $persons = [];
            $puStmt = $db->prepare("SELECT primary_user_id FROM department_position
                                    WHERE department_id = ? AND position_id = ? AND primary_user_id IS NOT NULL LIMIT 1");
            foreach ($delegatePositions as $dpos) {
                $puStmt->execute([$dep, $dpos]);
                $pu = $puStmt->fetchColumn();
                if ($pu && !in_array((int)$pu, $persons, true)) {
                    // 僅取在職者
                    $chk = $db->prepare("SELECT 1 FROM user WHERE id = ? AND state = 1");
                    $chk->execute([(int)$pu]);
                    if ($chk->fetchColumn()) $persons[] = (int)$pu;
                }
            }
            return $persons;
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_resolve_supervisor')) {
    /**
     * SoD 直升「上一級主管」。無全域主管鏈，規則：
     *  1. 同部門內，職稱階級 < 被代理人階級（數字小=高）且最接近者（優先取該部門×該職稱的指定負責人）
     *  2. 同部門無 → 沿 department.parent_id 上溯，取祖先部門的指定負責人（任一 primary_user_id）
     *  3. 都無 → 回 null（交由呼叫端回退流程最終裁決者或掛管理員）
     * 回傳 user id 或 null。
     */
    function eg_resolve_supervisor(PDO $db, int $targetUserId, ?int $depHint = null): ?int {
        try {
            $main = eg_user_main_identity($db, $targetUserId);
            $dep = $depHint ?? ($main['department_id'] ?? null);
            if ($dep === null) return null;
            $targetLevel = $main['level'] ?? 99; // 非主管視為最低

            // 1. 同部門更高階（level < targetLevel），最接近者（level 越大越接近）；優先指定負責人
            $st = $db->prepare("SELECT u.id, pl.level,
                                       (SELECT dp.primary_user_id FROM department_position dp
                                         WHERE dp.department_id = m.department_id AND dp.position_id = m.position_id
                                         LIMIT 1) AS primary_uid
                                FROM user_department_position_map m
                                JOIN user u ON u.id = m.user_id AND u.state = 1
                                JOIN position_level pl ON pl.position_id = m.position_id
                                WHERE m.department_id = ? AND u.id <> ?
                                  AND pl.level IS NOT NULL AND pl.level < ?
                                ORDER BY pl.level DESC, (primary_uid = u.id) DESC
                                LIMIT 1");
            $st->execute([$dep, $targetUserId, $targetLevel]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return (int)($r['primary_uid'] ?: $r['id']);

            // 2. 上溯父部門的指定負責人
            $depCursor = $dep;
            for ($hop = 0; $hop < 6; $hop++) { // 防無限迴圈
                $pst = $db->prepare("SELECT parent_id FROM department WHERE id = ?");
                $pst->execute([$depCursor]);
                $parent = $pst->fetchColumn();
                if (!$parent) break;
                $sup = $db->prepare("SELECT primary_user_id FROM department_position dp
                                     JOIN user u ON u.id = dp.primary_user_id AND u.state = 1
                                     WHERE dp.department_id = ? AND dp.primary_user_id IS NOT NULL
                                     ORDER BY (SELECT level FROM position_level pl WHERE pl.position_id = dp.position_id) ASC
                                     LIMIT 1");
                $sup->execute([$parent]);
                $supId = $sup->fetchColumn();
                if ($supId) return (int)$supId;
                $depCursor = $parent;
            }
            return null;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('eg_log_delegate_event')) {
    /** 寫 audit_log（代理/SoD 事件可追溯）。fail-open，寫入失敗不擋流程。 */
    function eg_log_delegate_event(PDO $db, string $action, int $targetUserId, int $signerId, array $meta): void {
        try {
            $st = $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                                VALUES (?, 'approval', ?, ?, ?, NULL, 'delegate_lib', NOW())");
            $st->execute([
                $action,
                (string)($meta['doc_id'] ?? ''),
                (string)($meta['flow_key'] ?? ''),
                json_encode([
                    'target_user_id' => $targetUserId,
                    'signer_id'      => $signerId,
                    'reason'         => $meta['reason'] ?? '',
                    'flow_key'       => $meta['flow_key'] ?? '',
                    'doc_id'         => $meta['doc_id'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) { /* 稽核寫入失敗不擋流程 */ }
    }
}

if (!function_exists('eg_resolve_signer')) {
    /**
     * 全系統唯一入口：解析某人（target）在特定情境下實際該簽核／收待辦的人。
     *
     * @param array $ctx [
     *    'applicant_id'        => int   // 表單申請人/經手人（SoD 用），0=無
     *    'scope_department_id' => ?int  // 任務對應的部門身分；null=主職/一般
     *    'scope_position_id'   => ?int  // 任務對應的職稱身分；null=主職/一般
     *    'flow_key'            => string// 'quotation'|'car'|'leave'|'as_form'|'qa'...
     *    'doc_id'              => mixed // 單據識別（稽核用，可省略）
     *    'log'                 => bool  // 是否寫 audit_log（預設 true）
     * ]
     * @return array ['signer_id'=>int,'is_delegated'=>bool,'is_sod_escalated'=>bool,'reason'=>string]
     */
    function eg_resolve_signer(PDO $db, int $targetUserId, array $ctx = []): array {
        $applicantId = (int)($ctx['applicant_id'] ?? 0);
        $scopeDep    = isset($ctx['scope_department_id']) ? ($ctx['scope_department_id'] === null ? null : (int)$ctx['scope_department_id']) : null;
        $scopePos    = isset($ctx['scope_position_id'])   ? ($ctx['scope_position_id'] === null   ? null : (int)$ctx['scope_position_id'])   : null;
        $flowKey     = (string)($ctx['flow_key'] ?? '');
        $doLog       = $ctx['log'] ?? true;

        $ret = function (int $id, bool $del, bool $sod, string $reason) use ($db, $targetUserId, $flowKey, $ctx, $doLog) {
            if ($doLog && ($del || $sod)) {
                eg_log_delegate_event($db, $sod ? 'SOD_ESCALATE' : 'DELEGATE', $targetUserId, $id, [
                    'reason' => $reason, 'flow_key' => $flowKey, 'doc_id' => $ctx['doc_id'] ?? null,
                ]);
            }
            return ['signer_id' => $id, 'is_delegated' => $del, 'is_sod_escalated' => $sod, 'reason' => $reason];
        };

        // 2. 行程閘門：本人今日無行程 → 由本人簽核（代理不啟用）
        $busy = eg_user_busy_today($db, $targetUserId);
        if (empty($busy)) {
            return $ret($targetUserId, false, false, '本人今日無行程，由本人簽核');
        }

        // 3. BY_PERSON → 4. BY_POSITION
        $candidates = eg_person_delegates($db, $targetUserId, $scopeDep, $scopePos);
        $source = 'BY_PERSON';
        if (empty($candidates)) {
            $candidates = eg_position_delegate_persons($db, $targetUserId, $scopeDep, $scopePos);
            $source = 'BY_POSITION';
        }

        // 5. SoD 過濾：候選 == 申請人（或就是本人）→ 跳過
        foreach ($candidates as $cand) {
            if ($cand === $targetUserId) continue;
            if ($applicantId && $cand === $applicantId) continue;
            return $ret($cand, true, false, "由代理人代簽（{$source}）");
        }

        // 全部候選被 SoD 排除（或無候選）→ 直升上一級主管
        if (!empty($candidates)) {
            $sup = eg_resolve_supervisor($db, $targetUserId, $scopeDep);
            if ($sup && $sup !== $applicantId) {
                return $ret($sup, false, true, '因權責分離迴避（代理人即申請人），簽核點直升上一級主管');
            }
            // 上一級也等於申請人或無法解析 → 兜底回本人並標記
            return $ret($targetUserId, false, false, '代理人觸發權責迴避且無法解析上一級，暫由本人/管理員處理');
        }

        // 本人有行程但完全無代理設定 → 仍回本人（維持現狀，不強造代理）
        return $ret($targetUserId, false, false, '本人今日有行程但未設定代理人，仍由本人簽核');
    }
}
