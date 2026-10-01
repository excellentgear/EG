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
 *   2. 不在閘門：**只看被代理人今天有沒有請假／休假**（2026-10-01 使用者定調）——
 *      行事曆上的會議、公出等行程不算「不在」，開會的人還在公司、簽得了單。
 *      真的需要「人必須在場」的呼叫端自行傳 ctx['require_present']=true。
 *   3. BY_PERSON：user_delegate（先「指定職務身分」再「不分身分」，依日期 + active + priority）
 *   4. BY_POSITION：position_delegate → department_position.primary_user_id 解析成人
 *   5. 合格性（限 ctx['scope_is_signer']=true 的呼叫端）：「不分身分」的代理人必須落在
 *      被代理人該簽核身分所屬單位的部門樹內，否則不採用並回 need_setup 請去 HR 設定
 *   6. SoD：候選 == 申請人 → 跳過；候選本人今天也請假 → 跳過；全數被排除 → 直升上一級主管
 *   7. 寫 audit_log，回傳結果
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
            // 全天事件(allday=1)的 end 欄位存的是「結束當天 00:00:00」(非23:59:59)，
            // 若直接拿 e.end>=now 比對，同一天內只要過了 00:00:00(幾乎永遠成立)就會判定「已結束」，
            // 導致單日請假/特休整天都測不到自己在忙——一律改用日期比對(DATE(e.end)>=CURDATE())。
            $st = $db->prepare("SELECT e.title, e.start, e.end, e.allday, ec.category_name
                                FROM evenement e
                                JOIN evenement_actor a ON a.event_id = e.id
                                LEFT JOIN event_category ec ON ec.id = e.category_id
                                WHERE a.user_id = ?
                                  AND (ec.day_type IS NULL OR ec.day_type = '')
                                  AND ec.category_name <> '通知'
                                  AND ec.category_name <> '請假申請中'
                                  AND ((e.allday = 1 AND DATE(e.start) <= CURDATE() AND DATE(e.end) >= CURDATE())
                                    OR (e.allday = 0 AND e.start <= ? AND e.end >= ?))");
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

if (!function_exists('eg_user_on_leave_today')) {
    /**
     * 某人「今天」是否請假／特休整天或涵蓋現在（不含一般會議等單純行程）。
     * 用途：自動簽核（無需真人即時操作的場景）判斷本人是否真的不在（休假/請假），
     *       而不是被 eg_user_busy_today() 的「今天有任何行程(含開會)」全量判定誤擋——
     *       開會不代表人整天不在公司，不該讓自動簽核也轉去找代理人。
     */
    function eg_user_on_leave_today(PDO $db, int $uid): bool {
        foreach (eg_user_busy_today($db, $uid) as $b) {
            if (!empty($b['leave'])) return true;
        }
        return false;
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
     * 某人「目前有效」的候選代理人清單（含姓名與所屬職務身分），供表單下拉選用。
     * 用途：請假單等需要申請人「從已設定的代理人中指定」的場景（唯讀，不解析行程/SoD）。
     *
     * **不指定 scope 時＝列出此人「所有職務身分」的代理**（主職＋每個兼任，再加不分身分的全域規則）。
     * 理由：請假是「整個人都不在」，主職與兼任的代理可能是不同人，只查全域規則會把
     * 綁了職務身分的設定全部漏掉（2026-07-30 實際踩到：葉卿雅三筆代理全綁身分，
     * 畫面卻顯示「未設定代理人」）。指定 scope 時仍只回該身分的代理，行為不變。
     *
     * 回傳 [[
     *   'user_id','user_cname','source'=>'BY_PERSON'|'BY_POSITION',
     *   'scope_department_id','scope_position_id',
     *   'scope_label'   // '管理部 / 會計'，全域規則為 '不分身分'
     *   'is_main'       // 是否為主職身分（供前端標 [主]/[兼]）
     * ], ...]；空陣列＝確實沒有任何代理設定。
     */
    function eg_person_delegate_candidates(PDO $db, int $targetUserId, ?int $scopeDep = null, ?int $scopePos = null): array {
        // 指定了身分 → 維持原行為（只回該身分）
        if ($scopeDep !== null && $scopePos !== null) {
            return eg_person_delegate_cand_one($db, $targetUserId, $scopeDep, $scopePos, '', null);
        }

        // 未指定身分 → 逐一列出此人的每個職務身分，最後補上「不分身分」的全域規則
        $identities = [];
        try {
            $st = $db->prepare("SELECT m.department_id, d.name AS dep_name, m.position_id, p.name AS pos_name, m.is_main
                                FROM user_department_position_map m
                                LEFT JOIN department d ON d.id = m.department_id
                                LEFT JOIN position p ON p.id = m.position_id
                                WHERE m.user_id = ?
                                ORDER BY m.is_main DESC, m.department_id, m.position_id");
            $st->execute([$targetUserId]);
            $identities = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $out = []; $seen = [];
        foreach ($identities as $m) {
            $label = trim(($m['dep_name'] ?? '') . ' / ' . ($m['pos_name'] ?? ''));
            $rows = eg_person_delegate_cand_one($db, $targetUserId, (int)$m['department_id'], (int)$m['position_id'],
                                                $label, (int)$m['is_main'] === 1);
            foreach ($rows as $r) {
                $k = $r['user_id'] . '@' . $r['scope_department_id'] . '-' . $r['scope_position_id'];
                if (isset($seen[$k])) continue;
                $seen[$k] = 1; $out[] = $r;
            }
        }
        // 不分身分（scope 皆 NULL）的規則
        foreach (eg_person_delegate_cand_one($db, $targetUserId, null, null, '不分身分', null) as $r) {
            $k = $r['user_id'] . '@global';
            if (isset($seen[$k])) continue;
            $seen[$k] = 1; $out[] = $r;
        }
        return $out;
    }

    /** 單一職務身分（或全域）的候選代理人；供 eg_person_delegate_candidates() 組裝用。 */
    function eg_person_delegate_cand_one(PDO $db, int $targetUserId, ?int $dep, ?int $pos,
                                        string $scopeLabel, ?bool $isMain): array {
        $ids = [];
        try {
            if ($dep !== null && $pos !== null) {
                // 該身分專屬規則（不退回全域，全域由呼叫端另外補，避免重複）
                $st = $db->prepare("SELECT delegate_id FROM user_delegate
                                    WHERE user_id = ? AND active = 1
                                      AND start_date <= CURDATE() AND end_date >= CURDATE()
                                      AND scope_department_id = ? AND scope_position_id = ?
                                    ORDER BY priority ASC");
                $st->execute([$targetUserId, $dep, $pos]);
            } else {
                $st = $db->prepare("SELECT delegate_id FROM user_delegate
                                    WHERE user_id = ? AND active = 1
                                      AND start_date <= CURDATE() AND end_date >= CURDATE()
                                      AND scope_department_id IS NULL AND scope_position_id IS NULL
                                    ORDER BY priority ASC");
                $st->execute([$targetUserId]);
            }
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { return []; }

        $source = 'BY_PERSON';
        if (empty($ids)) {
            // 人員代理沒設 → 退回職位代理（需該部門×職稱有指定負責人才解得出人）
            $ids = eg_position_delegate_persons($db, $targetUserId, $dep, $pos);
            $source = 'BY_POSITION';
        }
        if (empty($ids)) return [];

        $out = [];
        try {
            $st = $db->prepare("SELECT user_cname FROM user WHERE id = ? AND state = 1");
            foreach ($ids as $id) {
                if ($id === $targetUserId) continue;   // 自己不能當自己的代理
                $st->execute([$id]);
                $name = $st->fetchColumn();
                if ($name === false) continue;         // 離職者不列入
                $out[] = ['user_id' => $id, 'user_cname' => (string)$name, 'source' => $source,
                          'scope_department_id' => $dep, 'scope_position_id' => $pos,
                          'scope_label' => $scopeLabel !== '' ? $scopeLabel : '不分身分',
                          'is_main' => $isMain];
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
     * SoD 直升「上一級主管」＝該員的**單位主管**，一律走共用庫 unit_supervisor_lib.php
     * （ai-rules/24 審核層級規範，唯一實作；本函式只保留簽名與「找不到回 null」的既有契約）：
     *  1. 該員所屬單位的最高主管（不是他本人）
     *  2. 他自己就是該單位最高主管 → 往上一層單位（組→課）找該單位的最高主管
     *  3. 到**課級**為止（不追溯到總經理室／董事長室這種全公司共同上級）→ 找不到回 null，
     *     交由呼叫端回退流程最終裁決者或掛管理員
     *
     * 2026-09-16 改寫的原因（使用者回報「單位主管欄蓋到申請人自己的章」）：
     *  舊版第 2 步只認 `department_position.primary_user_id`（指定負責人），而那個欄位全站一筆都沒有設定，
     *  所以「往上一層找主管」從來沒有成功過——組長以上一律回 null。
     *  另外舊版拿「主職的職級」去跟 $depHint 指定的那個部門比（兼任者的主職可能是工程師＝無職級），
     *  會把同單位的副組長判成自己的上級。兩個問題都在共用庫裡一併解掉。
     */
    function eg_resolve_supervisor(PDO $db, int $targetUserId, ?int $depHint = null): ?int {
        try {
            require_once __DIR__ . '/unit_supervisor_lib.php';
            $r = eg_unit_supervisor($db, $targetUserId, $depHint);
            return !empty($r['id']) ? (int)$r['id'] : null;
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

if (!function_exists('eg_log_full_inherit_grant')) {
    /**
     * 寫 audit_log：留停/育嬰留停等假別（leave_type.full_inherit_permission=1）核准生效，
     * 代理人開始「完整承接」被代理人該職務身分的頁面/設定權限（見 rf_load_full_inherit_delegate_features）。
     * 只在核准當下寫一次（見 leave_lib.php eg_leave_sign() 全過分支），不逐次頁面刷新都寫，避免灌爆稽核表。
     * 提前結束/自然到期不另寫收回事件——leave_request 本身的 end_datetime／orig_end_datetime／
     * early_end_reason 已是該次授權何時、為何提前結束的第一手紀錄。fail-open。
     */
    function eg_log_full_inherit_grant(PDO $db, int $targetUserId, int $agentUserId, array $meta): void {
        try {
            $st = $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                                VALUES ('DELEGATE_FULL_INHERIT', 'user', ?, ?, ?, NULL, 'delegate_lib', NOW())");
            $st->execute([
                (string)$agentUserId,
                (string)($meta['agent_name'] ?? ''),
                json_encode([
                    'target_user_id'    => $targetUserId,
                    'target_name'       => $meta['target_name'] ?? '',
                    'agent_user_id'     => $agentUserId,
                    'scope_label'       => $meta['scope_label'] ?? '',
                    'leave_request_id'  => $meta['leave_request_id'] ?? null,
                    'leave_name'        => $meta['leave_name'] ?? '',
                    'start'             => $meta['start'] ?? '',
                    'end'               => $meta['end'] ?? '',
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
     *    'auto_sign'           => bool  // 保留供呼叫端標示用途；2026-10-01 起閘門一律只看請假，本旗標不再影響判定
     *    'require_present'     => bool  // true=這一關真的需要「人在場」，才改用「今天有任何行程就轉代理」
     *    'scope_is_signer'     => bool  // true=上面的 scope 是**被代理人自己的簽核身分**，才做代理人合格性檢查
     * ]
     * @return array ['signer_id'=>int,'is_delegated'=>bool,'is_sod_escalated'=>bool,'reason'=>string,
     *                'need_setup'=>bool,'setup_msg'=>string]
     */
    function eg_resolve_signer(PDO $db, int $targetUserId, array $ctx = []): array {
        $applicantId = (int)($ctx['applicant_id'] ?? 0);
        $scopeDep    = isset($ctx['scope_department_id']) ? ($ctx['scope_department_id'] === null ? null : (int)$ctx['scope_department_id']) : null;
        $scopePos    = isset($ctx['scope_position_id'])   ? ($ctx['scope_position_id'] === null   ? null : (int)$ctx['scope_position_id'])   : null;
        $flowKey     = (string)($ctx['flow_key'] ?? '');
        $doLog       = $ctx['log'] ?? true;
        /* scope_is_signer：呼叫端保證 scope_department_id/position_id 是「**被代理人自己的簽核身分**」
           （不是申請人的部門）。只有這種呼叫端才做下面的代理人合格性檢查——
           傳申請人部門的舊呼叫端若照做，會把本來正確的代理人誤判成不合格。
           已檢視並開啟的：doc_apply 四格簽章、business_trip 核准人。 */
        $scopeIsSigner = !empty($ctx['scope_is_signer']);

        // $extra：need_setup / setup_msg（代理設定不足時由呼叫端提示去 HR 設定，2026-10-01）
        $ret = function (int $id, bool $del, bool $sod, string $reason, array $extra = []) use ($db, $targetUserId, $flowKey, $ctx, $doLog) {
            if ($doLog && ($del || $sod)) {
                eg_log_delegate_event($db, $sod ? 'SOD_ESCALATE' : 'DELEGATE', $targetUserId, $id, [
                    'reason' => $reason, 'flow_key' => $flowKey, 'doc_id' => $ctx['doc_id'] ?? null,
                ]);
            }
            return array_merge(['signer_id' => $id, 'is_delegated' => $del, 'is_sod_escalated' => $sod,
                                'reason' => $reason, 'need_setup' => false, 'setup_msg' => ''], $extra);
        };

        /* 2. 不在閘門：**一律只看本人當天有沒有請假／休假**。
              2026-10-01 使用者定調：行事曆上的會議等行程不算「不在」——開會的人還在公司、簽得了這張單。
              實例：2026-10-01 整天的「AS稽核-復評」會議有 8 位出席，核准(陳俊宏)、管理代表(林雅婷)、
              申請人(何沐桐) 都在名單上卻照常蓋自己的章（他們沒設代理人），只有設了代理人的
              單位主管(吳佳靜)被判成「不在」而換成代理人並蓋「代」字——同一場會議兩種結果，自相矛盾。
              真正決定要不要換人的其實是「有沒有設代理人」，不是「人在不在」。
              需要「真人必須在場」的呼叫端請自行傳 ctx['require_present']=true（目前站上沒有）。 */
        $requirePresent = !empty($ctx['require_present']);
        $unavailable = $requirePresent ? !empty(eg_user_busy_today($db, $targetUserId))
                                       : eg_user_on_leave_today($db, $targetUserId);
        if (!$unavailable) {
            return $ret($targetUserId, false, false, $requirePresent ? '本人今日無行程，由本人簽核' : '本人今日未請假，由本人簽核');
        }

        // 3. BY_PERSON（先精準身分、再不分身分）→ 4. BY_POSITION
        $tiers  = eg_person_delegates_tiered($db, $targetUserId, $scopeDep, $scopePos);
        $source = 'BY_PERSON';
        if (!$tiers['exact'] && !$tiers['global']) {
            $tiers['global'] = eg_position_delegate_persons($db, $targetUserId, $scopeDep, $scopePos);
            $source = 'BY_POSITION';
        }

        /* 5. 候選過濾
              ‧精準身分的代理（人資在 hr_settings「適用職務身分」明確指定給這個身分的）＝一律採信。
              ‧不分身分的全域代理＝要再過一次**合格性**：代理人必須落在「被代理人這個簽核身分所屬單位」
                的部門樹內（該單位本身、其下轄、或其上層主管鏈）。
                2026-10-01 使用者回報：吳佳靜兼任「資材課 副理」與「業務課 課長」，她的兩筆代理設定都是
                不分身分的全域規則，於是**業務課的組員鍾惠如被拿去代理「資材課副理」那一格的簽核**——
                不同單位的組員代理副理的審核不合理（使用者原話「這太不合理了」）。
              ‧SoD：候選是本人或申請人、或代理人自己當天也請假 → 跳過。 */
        $unfit = [];
        $pick  = null; $pickExact = false;
        foreach ([['exact', true], ['global', false]] as [$k, $isExact]) {
            foreach ($tiers[$k] as $cand) {
                if ($cand === $targetUserId) continue;
                if ($applicantId && $cand === $applicantId) continue;
                if (eg_user_on_leave_today($db, $cand)) continue;
                if (!$isExact && $scopeIsSigner && !eg_delegate_scope_fit($db, $cand, $scopeDep)) { $unfit[] = $cand; continue; }
                $pick = $cand; $pickExact = $isExact; break 2;
            }
        }
        if ($pick) return $ret($pick, true, false, '由代理人代簽（' . $source . ($pickExact ? '／已指定職務身分' : '') . '）');

        /* 6. 有設代理人、但全部不合格（跨單位的全域代理）→ **不可以硬塞一個不相干的人去蓋章**。
              回本人並標記 need_setup，請呼叫端提示去 HR 設定補一筆「指定職務身分」的代理。 */
        if ($unfit) {
            $names = [];
            foreach (array_slice($unfit, 0, 3) as $u) $names[] = eg_delegate_user_label($db, $u);
            $who  = eg_delegate_user_label($db, $targetUserId, $scopeDep);
            return $ret($targetUserId, false, false, '代理人設定不適用本簽核身分，暫由本人處理', [
                'need_setup' => true,
                'setup_msg'  => $who . ' 當日請假，但目前設定的代理人（' . implode('、', $names) . '）'
                              . '不屬於這個簽核身分所在的單位，無法代理這一關。'
                              . '請至「HR 設定 → 使用者代理設定」為該職務身分（適用職務身分選' . eg_delegate_scope_label($db, $scopeDep) . '）指定代理人；'
                              . '若你不是人資，請通知人資處理。',
            ]);
        }

        // 7. 有候選但全被 SoD／代理人也請假排除 → 直升上一級主管（既有行為）
        if ($tiers['exact'] || $tiers['global']) {
            $sup = eg_resolve_supervisor($db, $targetUserId, $scopeDep);
            if ($sup && $sup !== $applicantId) {
                return $ret($sup, false, true, '代理人皆無法代簽（權責迴避或本人也請假），簽核點直升上一級主管');
            }
            return $ret($targetUserId, false, false, '代理人皆無法代簽且無法解析上一級，暫由本人/管理員處理');
        }

        // 8. 完全沒有代理設定 → 仍回本人（不強造代理），但要提示去設定
        return $ret($targetUserId, false, false, '本人今日請假但未設定代理人，暫由本人處理', [
            'need_setup' => true,
            'setup_msg'  => eg_delegate_user_label($db, $targetUserId, $scopeDep)
                          . ' 當日請假，且尚未設定代理人。請至「HR 設定 → 使用者代理設定」指定代理人'
                          . '（適用職務身分請選' . eg_delegate_scope_label($db, $scopeDep) . '）；若你不是人資，請通知人資處理。',
        ]);
    }
}

if (!function_exists('eg_person_delegates_tiered')) {
    /**
     * 個人代理分成兩層回傳（`eg_person_delegates()` 是「精準有就不回全域」的早退版本，
     * 而合格性檢查只對全域那一層做，所以需要分得開）。
     * @return array ['exact'=>int[], 'global'=>int[]]
     */
    function eg_person_delegates_tiered(PDO $db, int $targetUserId, ?int $scopeDep, ?int $scopePos): array {
        $out = ['exact' => [], 'global' => []];
        try {
            if ($scopeDep !== null && $scopePos !== null) {
                $st = $db->prepare("SELECT delegate_id FROM user_delegate
                                    WHERE user_id = ? AND active = 1
                                      AND start_date <= CURDATE() AND end_date >= CURDATE()
                                      AND scope_department_id = ? AND scope_position_id = ?
                                    ORDER BY priority ASC");
                $st->execute([$targetUserId, $scopeDep, $scopePos]);
                $out['exact'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
            $st = $db->prepare("SELECT delegate_id FROM user_delegate
                                WHERE user_id = ? AND active = 1
                                  AND start_date <= CURDATE() AND end_date >= CURDATE()
                                  AND scope_department_id IS NULL AND scope_position_id IS NULL
                                ORDER BY priority ASC");
            $st->execute([$targetUserId]);
            $out['global'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('eg_delegate_scope_depts')) {
    /** 某單位的「合格代理範圍」＝該單位 + 所有下轄 + 整條上層主管鏈（請求內快取） */
    function eg_delegate_scope_depts(PDO $db, int $deptId): array {
        static $cache = [];
        if ($deptId <= 0) return [];
        if (isset($cache[$deptId])) return $cache[$deptId];
        $parent = []; $kids = [];
        try {
            foreach ($db->query("SELECT id, parent_id FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $parent[(int)$d['id']] = $d['parent_id'] !== null ? (int)$d['parent_id'] : 0;
                if ($d['parent_id'] !== null) $kids[(int)$d['parent_id']][] = (int)$d['id'];
            }
        } catch (Throwable $e) { return $cache[$deptId] = [$deptId]; }
        $set = [$deptId => true];
        for ($p = $parent[$deptId] ?? 0, $i = 0; $p && $i < 10; $p = $parent[$p] ?? 0, $i++) $set[$p] = true;  // 往上
        $stack = [$deptId];                                                                                    // 往下
        while ($stack) { $c = array_pop($stack); foreach ($kids[$c] ?? [] as $k) { if (!isset($set[$k])) { $set[$k] = true; $stack[] = $k; } } }
        return $cache[$deptId] = array_keys($set);
    }
}

if (!function_exists('eg_delegate_scope_fit')) {
    /**
     * 「不分身分」的代理人能不能代理這個簽核身分：代理人必須有一個職務落在該單位的部門樹內
     * （該單位／其下轄／其上層主管鏈）。$scopeDep 沒給＝無從判斷，一律放行（維持舊行為）。
     */
    function eg_delegate_scope_fit(PDO $db, int $candUserId, ?int $scopeDep): bool {
        if ($scopeDep === null || $scopeDep <= 0) return true;
        $ok = eg_delegate_scope_depts($db, (int)$scopeDep);
        if (!$ok) return true;
        try {
            $in = implode(',', array_fill(0, count($ok), '?'));
            $st = $db->prepare("SELECT 1 FROM user_department_position_map
                                WHERE user_id=? AND department_id IN ($in) LIMIT 1");
            $st->execute(array_merge([$candUserId], $ok));
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return true; }   // 查不到就不要擋流程
    }
}

if (!function_exists('eg_delegate_user_label')) {
    /** 「部門 職稱 姓名」供提示訊息用；$deptHint 有值時優先取該部門的那個職務 */
    function eg_delegate_user_label(PDO $db, int $uid, ?int $deptHint = null): string {
        try {
            $st = $db->prepare("SELECT u.user_cname, d.name AS dept, p.name AS pos, m.department_id, m.is_main
                                FROM `user` u
                                LEFT JOIN user_department_position_map m ON m.user_id=u.id
                                LEFT JOIN department d ON d.id=m.department_id
                                LEFT JOIN position p ON p.id=m.position_id
                                WHERE u.id=?");
            $st->execute([$uid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return '#' . $uid;
            $pick = null;
            if ($deptHint) foreach ($rows as $r) if ((int)$r['department_id'] === (int)$deptHint) { $pick = $r; break; }
            if (!$pick) foreach ($rows as $r) if ((int)$r['is_main'] === 1) { $pick = $r; break; }
            $pick = $pick ?: $rows[0];
            return trim(($pick['dept'] ?? '') . ' ' . ($pick['pos'] ?? '') . ' ' . ($pick['user_cname'] ?? ''));
        } catch (Throwable $e) { return '#' . $uid; }
    }
}

if (!function_exists('eg_delegate_scope_label')) {
    /** 提示訊息裡的「適用職務身分」要選哪一個部門 */
    function eg_delegate_scope_label(PDO $db, ?int $deptId): string {
        if (!$deptId) return '對應的職務身分';
        try {
            $st = $db->prepare("SELECT name FROM department WHERE id=?");
            $st->execute([(int)$deptId]);
            $n = (string)$st->fetchColumn();
            return $n !== '' ? ('「' . $n . '」底下的那個職務') : '對應的職務身分';
        } catch (Throwable $e) { return '對應的職務身分'; }
    }
}
