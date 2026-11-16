<?php
/**
 * AS 負責人交接與權限設定（唯一實作）
 *
 * 一頁設定好「AS 管理員」與「AS 負責人」兩種身分各自要有的角色與頁面權限：
 *   AS 管理員＝完整權限，含自動簽核、補登、結構總覽自動核准、各模組管理員角色（可多人）。
 *   AS 負責人＝日常維護文件的權限，**拿不到**任何自動簽核／補登／管理員角色（只能一人）。
 * 預設定義只有 ash_presets() 一份，畫面、預覽、套用、健檢全部讀它，不在別處再寫一份角色對照表。
 *
 * 套用一律：先算出「會增加／移除什麼」讓人確認 → 單一交易寫入 → 前後快照存進 as_handover_log，
 * 所以任何一次變更都能一鍵還原。暫停負責人＝降為唯讀並留快照，恢復時照快照還原。
 */

if (!function_exists('ash_presets')) {
    /** 兩種身分的預設（role_code 一律取資料庫現況，不存 id）。 */
    function ash_presets(): array {
        return [
            'owner' => [
                'label' => 'AS負責人',
                'desc'  => '日常維護 AS 文件（新增、改版、送審、上傳紀錄）。拿不到任何自動簽核、補登、管理員功能，只能一人。',
                'roles' => [
                    'asdoc_editor'          => 'AS文件管理：AS負責人',
                    'doc_apply_edit'        => '文件制修申請單：申請',
                    'asched_view'           => 'AS文件排程：檢閱',
                    'type_id_ctrl_edit'     => '型態識別文件管制表：登錄',
                    'role_1786685892_817'   => '表單簽核設計器：一般使用者',
                    'sopsip_view'           => 'SOP／SIP：檢閱',
                    'ia_view'               => '內部稽核：檢閱',
                ],
                'pages' => ['as_document_management.php', 'as_flow_guide.php', 'as_schedule.php', 'doc_apply.php',
                            'type_id_ctrl_doc.php', 'form_signer.php', 'sop_sip.php', 'internal_audit.php', 'flow_map.php'],
            ],
            'admin' => [
                'label' => 'AS管理員',
                'desc'  => '完整權限，含自動簽核、補登免附件、各模組管理員、本頁設定。可多人（例如負責人交接期間的舊負責人）。',
                'roles' => [
                    'asdoc_page_admin'      => 'AS文件管理：本頁管理員',
                    'asdoc_backfill'        => 'AS文件管理：補登免附件',
                    'doc_apply_admin'       => '文件制修申請單：管理員（含自動簽核）',
                    'asched_admin'          => 'AS文件排程：管理員',
                    'type_id_ctrl_admin'    => '型態識別文件管制表：管理員',
                    'role_1786685881_838'   => '表單簽核設計器：一般管理員（含補案件）',
                    'sopsip_admin'          => 'SOP／SIP：管理員',
                    'ia_admin'              => '內部稽核：管理代表',
                ],
                'pages' => ['as_document_management.php', 'as_flow_guide.php', 'as_schedule.php', 'doc_apply.php',
                            'type_id_ctrl_doc.php', 'form_signer.php', 'sop_sip.php', 'internal_audit.php', 'flow_map.php',
                            'as_handover.php'],
            ],
        ];
    }
}

if (!function_exists('ash_admin_only_codes')) {
    /** 「負責人」絕不可持有的角色（＝管理員預設裡的全部角色）。 */
    function ash_admin_only_codes(): array { return array_keys(ash_presets()['admin']['roles']); }
}

if (!function_exists('ash_ensure_schema')) {
    function ash_ensure_schema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $have = [];
        foreach ($db->query("SHOW TABLES LIKE 'as_handover%'")->fetchAll(PDO::FETCH_NUM) as $r) $have[$r[0]] = 1;
        if (!isset($have['as_handover_log'])) {
            $db->exec("CREATE TABLE as_handover_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(20) NOT NULL COMMENT 'apply/pause/resume/restore',
                target_user_id INT NOT NULL,
                target_name VARCHAR(50) NULL,
                preset VARCHAR(20) NULL,
                before_json MEDIUMTEXT NULL,
                after_json MEDIUMTEXT NULL,
                note VARCHAR(300) NULL,
                actor_id INT NOT NULL,
                actor_name VARCHAR(50) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_target (target_user_id, id)
            ) DEFAULT CHARSET=utf8mb4");
        }
        if (!isset($have['as_handover_state'])) {
            $db->exec("CREATE TABLE as_handover_state (
                id TINYINT PRIMARY KEY,
                paused_user_id INT NULL,
                paused_by INT NULL,
                paused_at DATETIME NULL,
                paused_reason VARCHAR(300) NULL,
                snapshot_json MEDIUMTEXT NULL
            ) DEFAULT CHARSET=utf8mb4");
            $db->exec("INSERT INTO as_handover_state (id) VALUES (1)");
        }
    }
}

if (!function_exists('ash_is_sys_admin')) {
    function ash_is_sys_admin(PDO $db, int $uid): bool {
        if ($uid <= 0) return false;
        try {
            $st = $db->prepare("SELECT user_status FROM `user` WHERE id=?");
            $st->execute([$uid]);
            if (in_array((int)$st->fetchColumn(), [9, 90], true)) return true;
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
            $st->execute([$uid]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('ash_role_index')) {
    /** [role_code => ['id','name','module']]，只含 AS 相關（兩種預設＋as_doc 模組全部角色）。 */
    function ash_role_index(PDO $db): array {
        $codes = [];
        foreach (ash_presets() as $p) foreach (array_keys($p['roles']) as $c) $codes[$c] = true;
        $out = [];
        $rows = $db->query("SELECT role_id, role_code, role_name, module FROM roles")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (isset($codes[$r['role_code']]) || $r['module'] === 'as_doc')
                $out[$r['role_code']] = ['id' => (int)$r['role_id'], 'name' => $r['role_name'], 'module' => $r['module']];
        }
        return $out;
    }
}

if (!function_exists('ash_pages')) {
    /** [檔名 => ['page_id','name']]（所有預設用到的頁面） */
    function ash_pages(PDO $db): array {
        $files = [];
        foreach (ash_presets() as $p) foreach ($p['pages'] as $f) $files[$f] = true;
        $out = [];
        foreach (array_keys($files) as $f) {
            $st = $db->prepare("SELECT page_id, page_name FROM system_module_pages WHERE page_url LIKE ? ORDER BY page_id LIMIT 1");
            $st->execute(['%/' . $f]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) $out[$f] = ['page_id' => (int)$r['page_id'], 'name' => $r['page_name']];
        }
        return $out;
    }
}

if (!function_exists('ash_user_roles')) {
    /** 使用者目前持有的 AS 相關角色 code 清單 */
    function ash_user_roles(PDO $db, int $uid, ?array $idx = null): array {
        $idx = $idx ?? ash_role_index($db);
        $ids = [];
        foreach ($idx as $c => $r) $ids[$r['id']] = $c;
        if (!$ids) return [];
        $st = $db->prepare("SELECT role_id FROM user_roles WHERE user_id=?");
        $st->execute([$uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) if (isset($ids[(int)$rid])) $out[] = $ids[(int)$rid];
        sort($out);
        return $out;
    }
}

if (!function_exists('ash_user_pages')) {
    /** [page_id => 權限字母]（AS 預設用到的頁面，頁面權限列） */
    function ash_user_pages(PDO $db, int $uid, ?array $pages = null): array {
        $pages = $pages ?? ash_pages($db);
        $out = [];
        $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        foreach ($pages as $p) {
            $st->execute([$uid, (string)$p['page_id']]);
            $chars = '';
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $chars .= (string)$v;
            if ($chars !== '') $out[$p['page_id']] = implode('', array_unique(str_split($chars)));
        }
        return $out;
    }
}

if (!function_exists('ash_user_name')) {
    function ash_user_name(PDO $db, int $uid): string {
        $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
        $st->execute([$uid]);
        return (string)$st->fetchColumn();
    }
}

if (!function_exists('ash_holders')) {
    /** 持有某角色的人 [[id,name]]（只列未離職者） */
    function ash_holders(PDO $db, string $roleCode, ?array $idx = null): array {
        $idx = $idx ?? ash_role_index($db);
        if (!isset($idx[$roleCode])) return [];
        $st = $db->prepare("SELECT u.id, u.user_cname FROM user_roles ur JOIN `user` u ON u.id=ur.user_id
                            WHERE ur.role_id=? AND COALESCE(u.state,1) NOT IN (0,90) ORDER BY u.user_cname");
        $st->execute([$idx[$roleCode]['id']]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['user_cname']], $st->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('ash_plan')) {
    /**
     * 算出「把這個人設成某身分」會改什麼（不寫入）。
     * @param string $preset owner|admin|none（none＝移除全部 AS 角色，保留頁面權限）
     * @return array ['add'=>[code..], 'remove'=>[code..], 'page_add'=>[file..], 'page_strip_a'=>[file..]]
     */
    function ash_plan(PDO $db, int $uid, string $preset): array {
        $idx   = ash_role_index($db);
        $pages = ash_pages($db);
        $have  = ash_user_roles($db, $uid, $idx);
        $pp    = ash_presets();
        $plan  = ['add' => [], 'remove' => [], 'page_add' => [], 'page_strip_a' => []];
        if ($preset === 'none') {
            $plan['remove'] = $have;
            return $plan;
        }
        if (!isset($pp[$preset])) return $plan;
        $want = array_keys($pp[$preset]['roles']);
        $plan['add'] = array_values(array_filter($want, fn($c) => isset($idx[$c]) && !in_array($c, $have, true)));
        if ($preset === 'owner') {
            // 負責人：管理員預設裡的角色一律移除（含自動簽核、補登）
            $plan['remove'] = array_values(array_intersect($have, ash_admin_only_codes()));
        } else {
            // 管理員：不可同時是「AS負責人」（負責人只能一人，且身分互斥）
            if (in_array('asdoc_editor', $have, true)) $plan['remove'][] = 'asdoc_editor';
        }
        // 頁面：缺的補 R（有頁面或群組權限就不動）；負責人若在 AS 頁面被給了 A 一律拿掉
        $perms = ash_user_pages($db, $uid, $pages);
        foreach ($pp[$preset]['pages'] as $f) {
            if (!isset($pages[$f])) continue;
            $pid = $pages[$f]['page_id'];
            if (!isset($perms[$pid])) $plan['page_add'][] = $f;
            elseif ($preset === 'owner' && strpos($perms[$pid], 'A') !== false) $plan['page_strip_a'][] = $f;
        }
        return $plan;
    }
}

if (!function_exists('ash_apply_plan')) {
    /** 依 ash_plan 結果寫入。呼叫端負責交易。 */
    function ash_apply_plan(PDO $db, int $uid, array $plan): void {
        $idx = ash_role_index($db);
        foreach ($plan['add'] as $c) {
            if (isset($idx[$c])) $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$uid, $idx[$c]['id']]);
        }
        foreach ($plan['remove'] as $c) {
            if (isset($idx[$c])) $db->prepare("DELETE FROM user_roles WHERE user_id=? AND role_id=?")->execute([$uid, $idx[$c]['id']]);
        }
        $pages = ash_pages($db);
        foreach ($plan['page_add'] as $f) {
            if (!isset($pages[$f])) continue;
            $db->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope, created_at, updated_at)
                          VALUES (?,?, 'R','page', NOW(), NOW())")->execute([$uid, (string)$pages[$f]['page_id']]);
        }
        foreach ($plan['page_strip_a'] as $f) {
            if (!isset($pages[$f])) continue;
            $db->prepare("UPDATE user_module_permissions SET permission=REPLACE(permission,'A','')
                          WHERE user_id=? AND scope='page' AND module_code=?")->execute([$uid, (string)$pages[$f]['page_id']]);
            $db->prepare("DELETE FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=? AND permission=''")
               ->execute([$uid, (string)$pages[$f]['page_id']]);
        }
    }
}

if (!function_exists('ash_snapshot')) {
    function ash_snapshot(PDO $db, int $uid): array {
        $pages = ash_pages($db);
        $perm  = ash_user_pages($db, $uid, $pages);
        $byFile = [];
        foreach ($pages as $f => $p) if (isset($perm[$p['page_id']])) $byFile[$f] = $perm[$p['page_id']];
        return ['roles' => ash_user_roles($db, $uid), 'pages' => $byFile];
    }
}

if (!function_exists('ash_log')) {
    function ash_log(PDO $db, string $action, int $target, ?string $preset, array $before, array $after, string $note, int $actor): void {
        $db->prepare("INSERT INTO as_handover_log (action, target_user_id, target_name, preset, before_json, after_json, note, actor_id, actor_name, created_at)
                      VALUES (?,?,?,?,?,?,?,?,?,NOW())")
           ->execute([$action, $target, ash_user_name($db, $target), $preset,
                      json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE),
                      mb_substr($note, 0, 300), $actor, ash_user_name($db, $actor)]);
        // 權限異動同步留在全站稽核紀錄（員工管理／權限設定頁看得到）
        try {
            $db->prepare("INSERT INTO audit_log (action_type,target_type,target_id,target_name,changes,user_id,operator)
                          VALUES (?,?,?,?,?,?,?)")
               ->execute(['as_handover_' . $action, 'rbac_user', (string)$target, ash_user_name($db, $target),
                          json_encode(['preset' => $preset, 'before' => $before['roles'] ?? [], 'after' => $after['roles'] ?? []], JSON_UNESCAPED_UNICODE),
                          $actor, ash_user_name($db, $actor)]);
        } catch (Throwable $e) {}
    }
}

if (!function_exists('ash_execute')) {
    /**
     * 套用身分變更（單一交易）。
     * $ops＝[['uid'=>, 'preset'=>owner|admin|none], …]；交接時＝[新負責人 owner, 舊負責人 admin]。
     * @return array ['ok'=>bool,'msg'=>string,'plans'=>[uid=>plan]]
     */
    function ash_execute(PDO $db, array $ops, int $actor, string $note): array {
        ash_ensure_schema($db);
        require_once __DIR__ . '/role_page_r_lib.php';
        $plans = []; $before = [];
        foreach ($ops as $o) {
            $before[$o['uid']] = ash_snapshot($db, $o['uid']);
            $plans[$o['uid']]  = ash_plan($db, $o['uid'], $o['preset']);
        }
        $db->beginTransaction();
        try {
            foreach ($ops as $o) {
                ash_apply_plan($db, $o['uid'], $plans[$o['uid']]);
                ash_log($db, 'apply', $o['uid'], $o['preset'], $before[$o['uid']], ash_snapshot($db, $o['uid']), $note, $actor);
            }
            // 負責人若原本就是暫停狀態，改派新人後暫停狀態失效
            $db->exec("UPDATE as_handover_state SET paused_user_id=NULL, paused_by=NULL, paused_at=NULL, paused_reason=NULL, snapshot_json=NULL WHERE id=1");
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '寫入失敗，已全部還原：' . $e->getMessage()];
        }
        return ['ok' => true, 'msg' => '已完成', 'plans' => $plans];
    }
}

if (!function_exists('ash_pause')) {
    /** 暫停負責人：拿掉所有負責人角色、只留「唯讀」，快照存進狀態表供恢復 */
    function ash_pause(PDO $db, int $ownerUid, int $actor, string $reason): array {
        ash_ensure_schema($db);
        $idx = ash_role_index($db);
        $snap = ash_snapshot($db, $ownerUid);
        $db->beginTransaction();
        try {
            $plan = ['add' => isset($idx['asdoc_viewer']) ? ['asdoc_viewer'] : [],
                     'remove' => array_values(array_intersect($snap['roles'], array_keys(ash_presets()['owner']['roles']))),
                     'page_add' => [], 'page_strip_a' => []];
            ash_apply_plan($db, $ownerUid, $plan);
            $db->prepare("UPDATE as_handover_state SET paused_user_id=?, paused_by=?, paused_at=NOW(), paused_reason=?, snapshot_json=? WHERE id=1")
               ->execute([$ownerUid, $actor, mb_substr($reason, 0, 300), json_encode($snap, JSON_UNESCAPED_UNICODE)]);
            ash_log($db, 'pause', $ownerUid, 'owner', $snap, ash_snapshot($db, $ownerUid), $reason, $actor);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '暫停失敗，已還原：' . $e->getMessage()];
        }
        return ['ok' => true];
    }
}

if (!function_exists('ash_resume')) {
    function ash_resume(PDO $db, int $actor, string $note): array {
        ash_ensure_schema($db);
        $s = $db->query("SELECT * FROM as_handover_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if (!$s || empty($s['paused_user_id'])) return ['ok' => false, 'msg' => '目前沒有暫停中的負責人'];
        $uid  = (int)$s['paused_user_id'];
        $snap = json_decode((string)$s['snapshot_json'], true) ?: ['roles' => []];
        $cur  = ash_user_roles($db, $uid);
        $want = (array)$snap['roles'];
        $plan = ['add' => array_values(array_diff($want, $cur)), 'remove' => array_values(array_diff($cur, $want)),
                 'page_add' => [], 'page_strip_a' => []];
        $before = ash_snapshot($db, $uid);
        $db->beginTransaction();
        try {
            ash_apply_plan($db, $uid, $plan);
            $db->exec("UPDATE as_handover_state SET paused_user_id=NULL, paused_by=NULL, paused_at=NULL, paused_reason=NULL, snapshot_json=NULL WHERE id=1");
            ash_log($db, 'resume', $uid, 'owner', $before, ash_snapshot($db, $uid), $note, $actor);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '恢復失敗，已還原：' . $e->getMessage()];
        }
        return ['ok' => true];
    }
}

if (!function_exists('ash_restore_log')) {
    /** 把某人還原成某筆紀錄「變更前」的 AS 角色（只動 AS 相關角色，不碰其他模組） */
    function ash_restore_log(PDO $db, int $logId, int $actor): array {
        ash_ensure_schema($db);
        $st = $db->prepare("SELECT * FROM as_handover_log WHERE id=?");
        $st->execute([$logId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'msg' => '找不到這筆紀錄'];
        $uid  = (int)$row['target_user_id'];
        $snap = json_decode((string)$row['before_json'], true) ?: ['roles' => []];
        $cur  = ash_user_roles($db, $uid);
        $want = (array)($snap['roles'] ?? []);
        $plan = ['add' => array_values(array_diff($want, $cur)), 'remove' => array_values(array_diff($cur, $want)),
                 'page_add' => [], 'page_strip_a' => []];
        $before = ash_snapshot($db, $uid);
        $db->beginTransaction();
        try {
            ash_apply_plan($db, $uid, $plan);
            ash_log($db, 'restore', $uid, null, $before, ash_snapshot($db, $uid), '還原到紀錄 #' . $logId . ' 變更前', $actor);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '還原失敗：' . $e->getMessage()];
        }
        return ['ok' => true];
    }
}

if (!function_exists('ash_health')) {
    /**
     * 健檢：某人目前的實際權限跟他該有的身分差在哪。
     * 負責人持有任何管理員角色、或 AS 頁面有 A 權限＝紅燈（等於繞過限制）。
     */
    function ash_health(PDO $db, int $uid, string $preset): array {
        $plan = ash_plan($db, $uid, $preset);
        $bad = [];
        $idx = ash_role_index($db); $pages = ash_pages($db);
        $rn = fn($c) => $idx[$c]['name'] ?? $c;       // 一律顯示中文名稱，不給人看角色代碼
        $pn = fn($f) => $pages[$f]['name'] ?? $f;
        if ($preset === 'owner') {
            foreach ($plan['remove'] as $c) $bad[] = ['kind' => 'role', 'code' => $c, 'name' => $rn($c), 'why' => '負責人不該持有管理員角色'];
            foreach ($plan['page_strip_a'] as $f) $bad[] = ['kind' => 'page', 'code' => $f, 'name' => $pn($f), 'why' => '該頁被給了 A（管理）權限，等於管理員'];
        }
        $missing = array_merge(array_map($rn, $plan['add']), array_map($pn, $plan['page_add']));
        return ['bad' => $bad, 'missing' => $missing, 'ok' => !$bad && !$missing];
    }
}

if (!function_exists('ash_module_labels')) {
    function ash_module_labels(): array {
        return ['as_doc_online' => 'AS文件線上版', 'form_signer' => '表單簽核', 'meeting' => '會議紀錄',
                'review_form' => '審核表單', 'sheet_form' => '表格模板', 'eng_change' => '工程變更',
                'hr_form' => '人事表單', 'business_trip' => '公出單', 'comm_record' => '溝通記錄',
                'training_plan' => '教育訓練計畫', 'training_request' => '教育訓練申請', 'vendor_audit_sign' => '供應商稽核',
                'quotation' => '報價單', 'quotation_attach' => '報價附件', 'memo_request' => '備忘請求'];
    }
}

if (!function_exists('ash_activity')) {
    /**
     * 某人近 N 天的簽核／送審／改版／申請單動作，並標出可疑項目：
     *   self＝自己送出又自己簽；burst＝10 分鐘內連續簽核 5 筆以上；reject_noreason＝退回沒寫原因。
     * 自動簽核產生的紀錄不顯示字樣（ai-rules/23），這裡只列人工動作的欄位。
     */
    function ash_activity(PDO $db, int $uid, int $days = 30): array {
        $days = max(1, min(180, $days));
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $labels = ash_module_labels();
        $st = $db->prepare("SELECT id, module, entity_id, level, status, submitted_by, submitted_by_name, submitted_at,
                                   approver_id, decided_at, note
                            FROM approval_record
                            WHERE approver_id=? AND status IN ('approved','rejected') AND decided_at>=?
                            ORDER BY decided_at DESC LIMIT 400");
        $st->execute([$uid, $since]);
        $dec = $st->fetchAll(PDO::FETCH_ASSOC);

        // 單據名稱（只解析最常見的兩種，其餘顯示編號）
        $nameOf = function (string $mod, int $eid) use ($db): string {
            try {
                if ($mod === 'as_doc_online') {
                    $q = $db->prepare("SELECT CONCAT(d.doc_no,' ',d.doc_name,' ',v.version) FROM as_document_version v
                                       JOIN as_document d ON d.id=v.doc_id WHERE v.id=?");
                    $q->execute([$eid]); $v = $q->fetchColumn(); if ($v) return (string)$v;
                }
                if ($mod === 'form_signer') {
                    $q = $db->prepare("SELECT title FROM fsd_case WHERE id=?");
                    $q->execute([$eid]); $v = $q->fetchColumn(); if ($v) return (string)$v;
                }
            } catch (Throwable $e) {}
            return '#' . $eid;
        };

        $times = array_map(fn($r) => strtotime($r['decided_at']), $dec);
        $rows = [];
        foreach ($dec as $i => $r) {
            $flags = [];
            if ((int)$r['submitted_by'] === $uid) $flags[] = 'self';
            // 前後 10 分鐘內（含自己）共 5 筆以上
            $n = 0; foreach ($times as $t) if (abs($t - $times[$i]) <= 600) $n++;
            if ($n >= 5) $flags[] = 'burst';
            if ($r['status'] === 'rejected' && trim((string)$r['note']) === '') $flags[] = 'reject_noreason';
            $rows[] = ['kind' => 'sign', 'at' => $r['decided_at'],
                       'module' => $labels[$r['module']] ?? $r['module'],
                       'what' => ($r['status'] === 'approved' ? '核准' : '退回') . '：' . $nameOf($r['module'], (int)$r['entity_id']),
                       'submitter' => $r['submitted_by_name'], 'flags' => $flags];
        }
        // 改版／上傳（uploaded_by 存的是姓名）
        $nm = ash_user_name($db, $uid);
        $st = $db->prepare("SELECT d.doc_no, d.doc_name, v.version, v.change_status, v.uploaded_at, v.is_draft
                            FROM as_document_version v JOIN as_document d ON d.id=v.doc_id
                            WHERE v.uploaded_by=? AND v.uploaded_at>=? ORDER BY v.uploaded_at DESC LIMIT 200");
        $st->execute([$nm, $since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['kind' => 'doc', 'at' => $r['uploaded_at'], 'module' => 'AS文件管理',
                       'what' => ($r['change_status'] ?: '改版') . '：' . $r['doc_no'] . ' ' . $r['doc_name'] . ' 版次 ' . $r['version'] . ($r['is_draft'] ? '（草稿）' : ''),
                       'submitter' => '', 'flags' => []];
        }
        // 文件制修申請單（他開的）
        $st = $db->prepare("SELECT apply_no, doc_no, doc_name, status, created_at FROM doc_apply
                            WHERE created_by=? AND is_deleted=0 AND created_at>=? ORDER BY created_at DESC LIMIT 200");
        $st->execute([$uid, $since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['kind' => 'apply', 'at' => $r['created_at'], 'module' => '文件制修申請單',
                       'what' => '建立申請單 ' . $r['apply_no'] . '（' . $r['doc_no'] . ' ' . $r['doc_name'] . '，' . $r['status'] . '）',
                       'submitter' => '', 'flags' => []];
        }
        usort($rows, fn($a, $b) => strcmp($b['at'], $a['at']));
        $sum = ['sign' => 0, 'approved' => 0, 'rejected' => 0, 'self' => 0, 'burst' => 0, 'noreason' => 0, 'days' => []];
        foreach ($dec as $r) { $sum['sign']++; $sum[$r['status'] === 'approved' ? 'approved' : 'rejected']++; }
        foreach ($rows as $r) {
            if (in_array('self', $r['flags'], true)) $sum['self']++;
            if (in_array('burst', $r['flags'], true)) $sum['burst']++;
            if (in_array('reject_noreason', $r['flags'], true)) $sum['noreason']++;
            $d = substr($r['at'], 0, 10);
            $sum['days'][$d] = ($sum['days'][$d] ?? 0) + 1;
        }
        krsort($sum['days']);
        return ['rows' => array_slice($rows, 0, 300), 'summary' => $sum, 'since' => substr($since, 0, 10)];
    }
}
