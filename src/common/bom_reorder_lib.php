<?php
/**
 * BOM 換製程順序（唯一實作）：生管申請 → 設計部門任一人核准／退回 → 核准後才真的更換。
 *
 * 兩種模式（管理員在 BOM 總覽「角色功能設定」切換，system_parameters BOM_SETTING/reorder_allow_sn_edit）：
 *  - 固定 SN（預設）：SN 號碼組不變，核准後把原本那幾個 SN 號碼依新順序重新分配給各關
 *  - 可改 SN：被移動的那一關自行輸入新 SN（必須落在新位置的前後兩關之間）
 * SN 是一整套資料的對應鍵，核准時同一個交易內一併改：bom_ing、移轉憑單(bom_ing_transfer_log)、
 * 扣款明細(qa_abnormal_deduct)、SN 對應(bom_sn_alias)。
 * 通知：一則 live_event，收件＝負責設計＋設計／技術部門全部人；任一人決行即把這則通知收回（全員同時消失）。
 */
require_once __DIR__ . '/notice_files.php';

if (!function_exists('bro_ensure_schema')) {
    /** 建表（DDL）——呼叫端務必在 beginTransaction 之前呼叫（DDL 會隱式 commit）。 */
    function bro_ensure_schema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS bom_reorder_req (
            req_id INT AUTO_INCREMENT PRIMARY KEY,
            bom VARCHAR(30) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
            moved_fid INT NOT NULL,
            moved_sn INT NOT NULL,
            target_sn INT NOT NULL,
            pos VARCHAR(10) NOT NULL,
            new_sn INT NULL,
            map_json TEXT NULL,
            brief_json MEDIUMTEXT NULL,
            reason VARCHAR(500) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            requested_by INT NULL,
            requested_at DATETIME NULL,
            decided_by INT NULL,
            decided_at DATETIME NULL,
            decide_note VARCHAR(500) NULL,
            event_id INT NULL,
            KEY idx_bom (bom, status),
            KEY idx_fid (moved_fid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }
}

if (!function_exists('bro_allow_sn_edit')) {
    function bro_allow_sn_edit(PDO $db): bool {
        try {
            $s = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='BOM_SETTING' AND param_key='reorder_allow_sn_edit' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v === false) return false;
            $j = json_decode((string)$v, true);
            return is_array($j) ? !empty($j['enabled']) : ((string)$v === '1');
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('bro_state_text')) {
    function bro_state_text(string $s): string {
        $m = ['N' => '待發包', 'ing' => '加工中', 'Q' => 'QC待驗', 'P' => '待移轉', 'E' => '已移轉', '1' => '已完成', 'skip' => '略過'];
        return $m[$s] ?? ($s === '' ? '待發包' : $s);
    }
}

if (!function_exists('bro_groups')) {
    /** 該 BOM 依 SN 分組、由小到大：[ ['sn','fid','process_no','name','state','sqty','qc_completed','QC_check','exclude_qc'] ] */
    function bro_groups(PDO $db, string $bom): array {
        $q = $db->prepare("SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, pn.ProcessName, bi.processing_state, bi.sqty, bi.qc_completed, bi.QC_check, bi.is_consumed, COALESCE(pn.is_exclude_qc,0) AS ex
                             FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                            WHERE bi.bom = ? ORDER BY bi.bom_sn, bi.bom_ing_fid");
        $q->execute([$bom]);
        $g = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sn = (int)$r['bom_sn'];
            if (isset($g[$sn])) continue;
            $g[$sn] = ['sn' => $sn, 'fid' => (int)$r['bom_ing_fid'], 'process_no' => $r['process_no'], 'name' => (string)($r['ProcessName'] ?? ''),
                       'state' => (string)$r['processing_state'], 'sqty' => (float)$r['sqty'], 'qc_completed' => (int)$r['qc_completed'],
                       'QC_check' => $r['QC_check'], 'exclude_qc' => (int)$r['ex']];
        }
        ksort($g);
        return array_values($g);
    }
}

if (!function_exists('bro_plan')) {
    /**
     * 算出換順序後的 SN 對照。回傳 ['ok'=>bool,'message'=>..,'map'=>[舊SN=>新SN],'order'=>[新順序的舊SN...]]
     * $pos: 'before'|'after'（相對於目標關）；$newSn：可改 SN 模式才使用。
     */
    function bro_plan(array $groups, int $movedSn, int $targetSn, string $pos, bool $allowEdit, ?int $newSn): array {
        $fail = function ($m) { return ['ok' => false, 'message' => $m, 'map' => [], 'order' => []]; };
        if ($movedSn === $targetSn) return $fail('不能選自己當目標');
        if (!in_array($pos, ['before', 'after'], true)) return $fail('請選擇移到目標「之前」或「之後」');
        $sns = array_column($groups, 'sn');
        if (!in_array($movedSn, $sns, true) || !in_array($targetSn, $sns, true)) return $fail('製程資料已變動，請重新整理後再試');
        $rest = array_values(array_diff($sns, [$movedSn]));
        $ti = array_search($targetSn, $rest, true);
        $ins = $pos === 'before' ? $ti : $ti + 1;
        $order = $rest;
        array_splice($order, $ins, 0, [$movedSn]);
        if ($order === $sns) return $fail('這樣移動後順序沒有改變');
        $map = [];
        if ($allowEdit) {
            if (!$newSn || $newSn <= 0) return $fail('請輸入新的 SN 號碼（正整數）');
            if ($newSn !== $movedSn && in_array($newSn, $sns, true)) return $fail('SN ' . $newSn . ' 已被其他製程使用');
            $lo = $ins > 0 ? $order[$ins - 1] : 0;
            $hi = $ins < count($order) - 1 ? $order[$ins + 1] : null;
            if ($newSn <= $lo || ($hi !== null && $newSn >= $hi)) {
                return $fail('新 SN 必須介於 ' . $lo . ' 與 ' . ($hi !== null ? $hi : '更大') . ' 之間，才符合你選的位置');
            }
            if ($newSn !== $movedSn) $map[$movedSn] = $newSn;
            else return $fail('新 SN 與原本相同');
        } else {
            foreach ($order as $i => $old) { if ($old !== $sns[$i]) $map[$old] = $sns[$i]; }
        }
        return ['ok' => true, 'message' => '', 'map' => $map, 'order' => $order];
    }
}

if (!function_exists('bro_bom_info')) {
    /** 通知／審核畫面用：BOM、料號（含主檔 id）、數量、訂單、負責設計 */
    function bro_bom_info(PDO $db, string $bom): array {
        $q = $db->prepare("SELECT b.bom, b.d_id, b.d_setting_id, b.o_order_id FROM bom b WHERE b.bom = ?");
        $q->execute([$bom]);
        $b = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $info = ['bom' => $bom, 'part_no' => (string)($b['d_id'] ?? ''), 'part_pk' => (int)($b['d_setting_id'] ?? 0), 'order_oo' => '', 'designer_id' => 0, 'qty' => 0];
        $oid = $b['o_order_id'] ?? '';
        if (!ctype_digit((string)$oid) || (int)$oid <= 0) {
            try {
                $m = $db->prepare("SELECT order_id FROM bom_order_process_map WHERE bom = ? ORDER BY 1 LIMIT 1");
                $m->execute([$bom]);
                $oid = $m->fetchColumn();
            } catch (Throwable $e) { $oid = 0; }
        }
        if ((int)$oid > 0) {
            $o = $db->prepare("SELECT Order_oo, ate FROM order_track WHERE Order_id = ?");
            $o->execute([(int)$oid]);
            if ($r = $o->fetch(PDO::FETCH_ASSOC)) { $info['order_oo'] = (string)$r['Order_oo']; $info['designer_id'] = (int)$r['ate']; }
        }
        $g = bro_groups($db, $bom);
        foreach ($g as $x) { $info['qty'] = max($info['qty'], $x['sqty']); }
        return $info;
    }
}

if (!function_exists('bro_user_name')) {
    function bro_user_name(PDO $db, $uid): string {
        static $c = [];
        $uid = (int)$uid;
        if ($uid <= 0) return '';
        if (!isset($c[$uid])) {
            $q = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
            $q->execute([$uid]);
            $c[$uid] = (string)$q->fetchColumn();
        }
        return $c[$uid];
    }
}

if (!function_exists('bro_designers')) {
    /** 收件／可決行名單：['primary'=>id,'users'=>[id=>name]]（負責設計一定在內；設計／技術部門全部在職人員） */
    function bro_designers(PDO $db, int $primaryId): array {
        $users = [];
        try {
            require_once __DIR__ . '/people_lib.php';
            require_once __DIR__ . '/org_role_lib.php';
            $dids = eg_org_dept_ids($db, 'rd_dept');
            if ($dids) foreach (eg_people_list($db, ['dept_ids' => $dids]) as $p) $users[(int)$p['id']] = (string)($p['name'] ?? $p['user_cname'] ?? '');
        } catch (Throwable $e) { error_log('[bro] designers: ' . $e->getMessage()); }
        if ($primaryId > 0) $users[$primaryId] = bro_user_name($db, $primaryId);
        return ['primary' => $primaryId, 'users' => $users];
    }
}

if (!function_exists('bro_describe')) {
    function bro_describe(array $groups, int $movedSn, int $targetSn, string $pos, array $map): string {
        $by = []; $i = 0;
        foreach ($groups as $g) { $by[$g['sn']] = $g; }
        $lab = function ($sn) use ($by) { $g = $by[$sn] ?? null; return $g ? ('SN' . $sn . ' ' . ($g['name'] !== '' ? $g['name'] : $g['process_no'])) : ('SN' . $sn); };
        $t = $lab($movedSn) . ' 移到 ' . $lab($targetSn) . ($pos === 'before' ? ' 之前' : ' 之後');
        return $t;
    }
}

if (!function_exists('bro_notify')) {
    function bro_insert_event(PDO $db, string $title, string $content, array $userIds, int $createdBy, int $reqId): int {
        if (!$userIds) return 0;
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, 'BOM換製程順序', 1, 'BOM_REORDER', ?)")
           ->execute([$title, $content, ($createdBy ?: null), $reqId]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([eg_gen_event_no($db, date('Y-m-d')), $eid]);
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?,?,?,?)");
        foreach (array_unique($userIds) as $u) if ((int)$u > 0) $ins->execute([$eid, 'user', (int)$u, 'read']);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), [
                'title' => $title, 'body' => mb_substr($content, 0, 480), 'tag' => 'bro-' . $eid,
                'url' => '/EGsystem/views/pm/bom_reorder_review.php?req=' . $reqId . '&event=' . $eid, 'eventId' => $eid,
            ], ['event_id' => $eid]);
        } catch (Throwable $e) { error_log('[bro] push: ' . $e->getMessage()); }
        try { require_once __DIR__ . '/../../telegram/notify_event.php'; eg_telegram_for_event($db, $eid); } catch (Throwable $e) { error_log('[bro] tg: ' . $e->getMessage()); }
        return $eid;
    }

    /** 送出申請（呼叫端已驗申請權限）。回傳 ['success','message','req_id'] */
    function bro_submit(PDO $db, string $bom, int $movedFid, int $targetSn, string $pos, ?int $newSn, string $reason, int $uid): array {
        bro_ensure_schema($db);
        $groups = bro_groups($db, $bom);
        $moved = null;
        foreach ($groups as $g) { $q = $db->prepare("SELECT bom_sn FROM bom_ing WHERE bom_ing_fid=? AND bom=?"); $q->execute([$movedFid, $bom]); $sn = $q->fetchColumn(); if ($sn !== false && (int)$sn === $g['sn']) { $moved = $g; break; } }
        if (!$moved) return ['success' => false, 'message' => '找不到要移動的製程，請重新整理'];
        $pend = $db->prepare("SELECT req_id FROM bom_reorder_req WHERE bom=? AND status='pending' LIMIT 1");
        $pend->execute([$bom]);
        if ($pid = $pend->fetchColumn()) return ['success' => false, 'message' => '這個 BOM 已有一張待設計審核的換順序申請（#' . $pid . '），請等審核完成'];
        $allow = bro_allow_sn_edit($db);
        $plan = bro_plan($groups, $moved['sn'], $targetSn, $pos, $allow, $newSn);
        if (!$plan['ok']) return ['success' => false, 'message' => $plan['message']];
        $info = bro_bom_info($db, $bom);
        $des = bro_designers($db, $info['designer_id']);
        if (!$des['users']) return ['success' => false, 'message' => '找不到可審核的設計人員（請確認組織角色綁定的「設計／技術部門」與訂單的設計）'];
        $desc = bro_describe($groups, $moved['sn'], $targetSn, $pos, $plan['map']);
        $brief = ['info' => $info, 'desc' => $desc, 'before' => array_map(function ($g) { return ['sn' => $g['sn'], 'label' => ($g['name'] !== '' ? $g['name'] : $g['process_no']), 'state' => $g['state'], 'qty' => $g['sqty']]; }, $groups)];
        $db->prepare("INSERT INTO bom_reorder_req (bom, moved_fid, moved_sn, target_sn, pos, new_sn, map_json, brief_json, reason, status, requested_by, requested_at)
                      VALUES (?,?,?,?,?,?,?,?,?, 'pending', ?, NOW())")
           ->execute([$bom, $movedFid, $moved['sn'], $targetSn, $pos, $allow ? $newSn : null, json_encode($plan['map']), json_encode($brief, JSON_UNESCAPED_UNICODE), mb_substr($reason, 0, 500), $uid ?: null]);
        $rid = (int)$db->lastInsertId();
        $req = bro_user_name($db, $uid);
        $lines = ['BOM：' . $bom, '料號：' . $info['part_no'], '數量：' . rtrim(rtrim(number_format($info['qty'], 3, '.', ''), '0'), '.'), ($info['order_oo'] !== '' ? '訂單：' . $info['order_oo'] : null),
                  '申請：' . $desc, '申請人：' . $req, ($reason !== '' ? '備註：' . $reason : null),
                  '負責設計：' . ($des['primary'] ? bro_user_name($db, $des['primary']) : '（訂單未指定）') . '（設計／技術部門任一人皆可審核，一人決行後其他人的通知自動收回）',
                  '目前製程順序與狀態：'];
        foreach ($groups as $g) $lines[] = '　SN' . $g['sn'] . ' ' . ($g['name'] !== '' ? $g['name'] : $g['process_no']) . '　' . bro_state_text($g['state']) . ($g['qc_completed'] ? '（QC完工）' : '');
        $title = '【換製程順序申請】' . $bom . '｜' . $info['part_no'] . '｜' . $desc;
        $eid = 0;
        try { $eid = bro_insert_event($db, $title, implode("\n", array_filter($lines, function ($x) { return $x !== null; })), array_keys($des['users']), $uid, $rid); } catch (Throwable $e) { error_log('[bro] event: ' . $e->getMessage()); }
        $db->prepare("UPDATE bom_reorder_req SET event_id=? WHERE req_id=?")->execute([$eid ?: null, $rid]);
        return ['success' => true, 'message' => '已送出申請，等待設計部門審核（核准後才會真的更換順序）', 'req_id' => $rid];
    }

    function bro_can_decide(PDO $db, array $req, int $uid, bool $isAdmin): bool {
        if ($isAdmin) return true;
        $brief = json_decode((string)$req['brief_json'], true);
        $des = bro_designers($db, (int)($brief['info']['designer_id'] ?? 0));
        return isset($des['users'][$uid]);
    }

    /** 決行。approve=true 核准並真的更換；false 退回（備註必填）。 */
    function bro_decide(PDO $db, int $reqId, bool $approve, string $note, int $uid, bool $isAdmin): array {
        bro_ensure_schema($db);
        $note = trim($note);
        if (!$approve && $note === '') return ['success' => false, 'message' => '退回必須填寫原因'];
        $q = $db->prepare("SELECT * FROM bom_reorder_req WHERE req_id=?");
        $q->execute([$reqId]);
        $req = $q->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['success' => false, 'message' => '找不到這張申請'];
        if ($req['status'] !== 'pending') return ['success' => false, 'message' => '這張申請已由' . bro_user_name($db, $req['decided_by']) . '決行（' . ($req['status'] === 'approved' ? '核准' : '退回') . '）'];
        if (!bro_can_decide($db, $req, $uid, $isAdmin)) return ['success' => false, 'message' => '您不是這張申請的審核人員'];
        $bom = $req['bom'];
        try {
            $db->beginTransaction();
            $lock = $db->prepare("SELECT status FROM bom_reorder_req WHERE req_id=? FOR UPDATE");
            $lock->execute([$reqId]);
            if ($lock->fetchColumn() !== 'pending') throw new Exception('這張申請剛被其他人決行，請重新整理');
            if ($approve) {
                $groups = bro_groups($db, $bom);
                $plan = bro_plan($groups, (int)$req['moved_sn'], (int)$req['target_sn'], $req['pos'], bro_allow_sn_edit($db) && $req['new_sn'] !== null, $req['new_sn'] !== null ? (int)$req['new_sn'] : null);
                if (!$plan['ok']) throw new Exception('製程資料在申請後已變動，無法套用（' . $plan['message'] . '），請退回並請生管重新申請');
                $map = $plan['map'];
                $case = function ($col) use ($map) { $s = "CASE $col"; foreach ($map as $o => $n) $s .= ' WHEN ' . (int)$o . ' THEN ' . (int)$n; return $s . " ELSE $col END"; };
                $in = implode(',', array_map('intval', array_keys($map)));
                $db->prepare("UPDATE bom_ing SET bom_sn = " . $case('bom_sn') . " WHERE bom = ? AND bom_sn IN ($in)")->execute([$bom]);
                $db->prepare("UPDATE bom_ing_transfer_log SET bom_sn = " . $case('bom_sn') . " WHERE bom = ? AND bom_sn IN ($in)")->execute([$bom]);
                try { $db->prepare("UPDATE qa_abnormal_deduct SET bom_sn = " . $case('bom_sn') . " WHERE bom = ? AND bom_sn IN ($in)")->execute([$bom]); } catch (Throwable $e) {}
                try {
                    $db->prepare("UPDATE bom_sn_alias SET target_sn = " . $case('target_sn') . " WHERE bom = ? AND target_sn IN ($in)")->execute([$bom]);
                } catch (Throwable $e) {}
                $db->prepare("INSERT INTO bom_ing_event (bom_ing_fid, event_type, event_note, Created_By) VALUES (?,?,?,?)")
                   ->execute([(int)$req['moved_fid'], 'reorder', '換製程順序已核准（#' . $reqId . '）：' . (json_decode($req['brief_json'], true)['desc'] ?? ''), $uid]);
            }
            $db->prepare("UPDATE bom_reorder_req SET status=?, decided_by=?, decided_at=NOW(), decide_note=? WHERE req_id=?")
               ->execute([$approve ? 'approved' : 'rejected', $uid, $note, $reqId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        // 收回所有人的待審通知；回發結果給生管
        try {
            if (!empty($req['event_id'])) $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=?")->execute([(int)$req['event_id']]);
            $brief = json_decode((string)$req['brief_json'], true);
            $who = bro_user_name($db, $uid);
            $t = '【換製程順序' . ($approve ? '已核准' : '已退回') . '】' . $bom . '｜' . ($brief['info']['part_no'] ?? '') . '｜' . ($brief['desc'] ?? '');
            $c = "BOM：$bom\n料號：" . ($brief['info']['part_no'] ?? '') . "\n申請：" . ($brief['desc'] ?? '') . "\n結果：" . ($approve ? '核准（順序已更換）' : '退回') . '　審核：' . $who . ($note !== '' ? "\n備註：$note" : '');
            bro_insert_event_result($db, $t, $c, (int)$req['requested_by'], $uid, $reqId);
        } catch (Throwable $e) { error_log('[bro] result notify: ' . $e->getMessage()); }
        return ['success' => true, 'message' => $approve ? '已核准，製程順序已更換' : '已退回'];
    }

    function bro_insert_event_result(PDO $db, string $title, string $content, int $toUid, int $by, int $reqId): void {
        if ($toUid <= 0) return;
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, 'BOM換製程順序', 1, 'BOM_REORDER_RESULT', ?)")->execute([$title, $content, ($by ?: null), $reqId]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("UPDATE live_event SET event_no=? WHERE id=?")->execute([eg_gen_event_no($db, date('Y-m-d')), $eid]);
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?,?,?,'read')")->execute([$eid, 'user', $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title' => $title, 'body' => mb_substr($content, 0, 480), 'tag' => 'bro-r-' . $eid,
                'url' => '/EGsystem/views/pm/bom_reorder_review.php?req=' . $reqId . '&event=' . $eid, 'eventId' => $eid], ['event_id' => $eid]);
        } catch (Throwable $e) {}
    }

    /** 某 BOM 的申請紀錄（新到舊），供製程跳窗與時間軸使用 */
    function bro_records(PDO $db, string $bom, int $fid = 0): array {
        bro_ensure_schema($db);
        $sql = "SELECT r.*, u1.user_cname AS req_name, u2.user_cname AS dec_name FROM bom_reorder_req r
                LEFT JOIN user u1 ON u1.id=r.requested_by LEFT JOIN user u2 ON u2.id=r.decided_by WHERE r.bom=?" . ($fid > 0 ? " AND r.moved_fid=?" : '') . " ORDER BY r.req_id DESC LIMIT 30";
        $q = $db->prepare($sql);
        $q->execute($fid > 0 ? [$bom, $fid] : [$bom]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b = json_decode((string)$r['brief_json'], true);
            $out[] = ['req_id' => (int)$r['req_id'], 'desc' => preg_replace('/（SN 變更：.*）$/u', '', (string)($b['desc'] ?? '')), 'status' => $r['status'], 'reason' => (string)$r['reason'],
                      'requested_by' => (string)$r['req_name'], 'requested_at' => (string)$r['requested_at'],
                      'decided_by' => (string)$r['dec_name'], 'decided_at' => (string)$r['decided_at'], 'decide_note' => (string)$r['decide_note'], 'moved_fid' => (int)$r['moved_fid']];
        }
        return $out;
    }

    /** 全站「被換過順序／申請中」的製程標記（fid => ['st','text']），前端畫圖示用 */
    function bro_marks(PDO $db): array {
        bro_ensure_schema($db);
        $m = [];
        foreach ($db->query("SELECT req_id, moved_fid, status, brief_json, decided_at FROM bom_reorder_req WHERE status IN ('pending','approved') ORDER BY req_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b = json_decode((string)$r['brief_json'], true);
            $m[(int)$r['moved_fid']] = ['st' => $r['status'], 'text' => ($r['status'] === 'pending' ? '換順序申請中：' : '已換順序：') . ($b['desc'] ?? '')];
        }
        return $m;
    }
}

if (!function_exists('bro_prev_qc_block')) {
    /**
     * 發單／移轉到這一關之前，上一關的 QC 必須「已檢驗且按過完工」。
     * 回傳 null＝可以；否則回傳 ['message'=>…,'prev_label'=>…]。免驗製程(is_exclude_qc)、略過(skip)、沒有上一關不受限。
     */
    function bro_prev_qc_block(PDO $db, int $fid): ?array {
        $q = $db->prepare("SELECT bom, bom_sn FROM bom_ing WHERE bom_ing_fid=?");
        $q->execute([$fid]);
        $cur = $q->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return null;
        $prev = null; $ord = 0;
        foreach (bro_groups($db, (string)$cur['bom']) as $i => $g) {
            if ($g['sn'] < (int)$cur['bom_sn'] && $g['state'] !== 'skip') { $prev = $g; $ord = $i + 1; }
        }
        if (!$prev || $prev['exclude_qc']) return null;
        if ($prev['qc_completed'] == 1) return null;
        $lab = '第 ' . $ord . ' 關 ' . ($prev['name'] !== '' ? $prev['name'] : $prev['process_no']);
        $qc = $db->prepare("SELECT QC_check FROM bom_ing WHERE bom=? AND bom_sn=? AND QC_check IS NOT NULL AND QC_check<>'' LIMIT 1");
        $qc->execute([$cur['bom'], $prev['sn']]);
        $res = $qc->fetchColumn();
        if ($res === false) {
            $ex = $db->prepare("SELECT 1 FROM qc_check WHERE bom_ing_fid_ref=? LIMIT 1");
            $ex->execute([$prev['fid']]);
            if ($ex->fetchColumn()) $res = '已有檢驗紀錄';
        }
        if ($res === false) return ['message' => $lab . ' QC尚未檢驗，QC 檢驗並按完工後才能移轉到這一關', 'prev_label' => $lab];
        $mp = ['ok' => '允收', 'ng' => '驗退', 'QQ' => '異常', 'AOD' => '特採'];
        return ['message' => $lab . ' QC檢驗狀態：' . ($mp[$res] ?? $res) . '，但尚未完工，QC 按完工後才能移轉到這一關', 'prev_label' => $lab];
    }
}
