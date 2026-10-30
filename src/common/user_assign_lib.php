<?php
/**
 * user_assign_lib.php — 「人員指派總覽」唯一實作（2026-10-30 使用者要求）
 * ------------------------------------------------------------------
 * 需求：要有一個頁面記錄「哪些人在哪些頁面／設定裡被指名」，這樣有人離職時
 *       可以通知相關主管或管理員去更新設定，或直接在畫面上換成替代人員。
 *
 * 為什麼要有：各模組把「某個人」存在各自的設定表（組織角色綁定、部門負責人、稽核資格、
 *   通知名單…）。人離職後權限會被封鎖，但這些設定還指著他——功能不報錯，只是單據
 *   永遠送不到對的人手上（AS 文件部門負責人缺口就是這樣被發現的）。
 *
 * 設計：
 *   ‧ 不另存一份指派資料（鐵律4）。這裡只有一張「來源登記表」uas_sources()，
 *     每一列說明「哪張表的哪個欄位存的是人」，總覽與離職盤點都即時查原表。
 *   ‧ 加新模組的指派設定 → 在 uas_sources() 加一列即可，總覽與離職提醒自動涵蓋。
 *   ‧ 換人（uas_replace）只改「那一個欄位」，寫 audit_log；不合法的新人選
 *     （不存在、已離職、與原人相同）一律擋下。後端權限 = 全站管理員。
 *   ‧ 已知沒涵蓋（存在 system_parameters 的 JSON、或簽核流程設計裡的人員步驟）見頁面說明。
 */

if (!defined('UAS_NOTIFY_MIN_DAYS')) define('UAS_NOTIFY_MIN_DAYS', 7);

/** 判定「這個人目前不能用了」的 SQL 片段（u 為 user 別名）：離職／留停／育嬰，或預定離職日已過 */
function uas_inactive_sql(string $u = 'u'): string
{
    return "({$u}.state IN (0,2,3) OR ({$u}.leave_date IS NOT NULL AND {$u}.leave_date < CURDATE()))";
}

/**
 * 來源登記表。
 * 每列：key / module（模組名）/ title（設定項目類別）/ url（設定頁）/ table / col（存人的欄位）/
 *       pk（該列識別欄位，換人用）/ label_sql（說明這一列是什麼，可用別名 t = 本表）/
 *       join（額外 JOIN，選填）/ where（額外條件，選填）/ replace（是否可在本頁直接換人）
 */
function uas_sources(): array
{
    $dp = "CONCAT(IFNULL(d.name,'?'),' ',IFNULL(p.name,'?'))";
    return [
        ['key'=>'org_role', 'module'=>'組織角色綁定', 'title'=>'全站組織角色', 'url'=>'/EGsystem/views/admin/org_role_setting.php',
         'table'=>'org_role_binding', 'col'=>'user_id', 'pk'=>'role_key', 'label_sql'=>"t.role_key",
         'where'=>'t.user_id IS NOT NULL', 'replace'=>true, 'label_map'=>'org_roles'],
        ['key'=>'dept_primary', 'module'=>'部門設定', 'title'=>'部門×職稱指定負責人', 'url'=>'/EGsystem/views/ADM/department_job_title_settings.php',
         'table'=>'department_position', 'col'=>'primary_user_id', 'pk'=>'id', 'label_sql'=>$dp,
         'join'=>'LEFT JOIN department d ON d.id=t.department_id LEFT JOIN position p ON p.id=t.position_id',
         'where'=>'t.primary_user_id IS NOT NULL', 'replace'=>true],
        ['key'=>'delegate', 'module'=>'代理設定', 'title'=>'他是誰的職務代理人', 'url'=>'/EGsystem/views/ADM/hr_settings.php',
         'table'=>'user_delegate', 'col'=>'delegate_id', 'pk'=>'id',
         'label_sql'=>"CONCAT('代理 ', IFNULL((SELECT user_cname FROM `user` WHERE id=t.user_id),'?'))",
         'where'=>"t.active=1 AND (t.end_date IS NULL OR t.end_date>=CURDATE())", 'replace'=>true],
        ['key'=>'asdoc_owner', 'module'=>'AS 文件管理', 'title'=>'部門負責人', 'url'=>'/EGsystem/views/ADM/as_document_management.php?dept_owner=1',
         'table'=>'as_doc_dept_owner', 'col'=>'owner_uid', 'pk'=>'department_id',
         'label_sql'=>"CONCAT(IFNULL(d.name,'?'),'（負責人）')",
         'join'=>'LEFT JOIN department d ON d.id=t.department_id', 'where'=>'t.owner_uid IS NOT NULL AND t.owner_uid>0', 'replace'=>true],
        ['key'=>'asdoc_deputy', 'module'=>'AS 文件管理', 'title'=>'部門代理人', 'url'=>'/EGsystem/views/ADM/as_document_management.php?dept_owner=1',
         'table'=>'as_doc_dept_owner', 'col'=>'deputy_uid', 'pk'=>'department_id',
         'label_sql'=>"CONCAT(IFNULL(d.name,'?'),'（代理人）')",
         'join'=>'LEFT JOIN department d ON d.id=t.department_id', 'where'=>'t.deputy_uid IS NOT NULL AND t.deputy_uid>0', 'replace'=>true],
        ['key'=>'asdoc_sign', 'module'=>'AS 文件管理', 'title'=>'簽核設定（指定人員）', 'url'=>'/EGsystem/views/ADM/as_document_management.php',
         'table'=>'as_doc_sign_cfg', 'col'=>'user_id', 'pk'=>'id',
         'label_sql'=>"CONCAT(IFNULL(d.name,'?'),' 第',t.stage,'關')",
         'join'=>'LEFT JOIN department d ON d.id=t.dept_id', 'where'=>'t.user_id IS NOT NULL AND t.user_id>0', 'replace'=>true],
        ['key'=>'ia_qual', 'module'=>'內部稽核', 'title'=>'稽核員／陪檢員資格（指定人員）', 'url'=>'/EGsystem/views/ADM/internal_audit.php',
         'table'=>'ia_qualified_person', 'col'=>'user_id', 'pk'=>'qp_id',
         'label_sql'=>"IF(t.kind='escort','陪檢員資格','稽核員資格')",
         'where'=>"t.user_id IS NOT NULL AND t.user_id>0 AND (t.end_date IS NULL OR t.end_date>=CURDATE())", 'replace'=>true],
        ['key'=>'ia_team', 'module'=>'內部稽核', 'title'=>'年度稽核小組', 'url'=>'/EGsystem/views/ADM/internal_audit.php',
         'table'=>'ia_team_member', 'col'=>'user_id', 'pk'=>'tm_id',
         'label_sql'=>"CONCAT(t.year,' 年 ',IFNULL(t.role,''))",
         'where'=>"t.user_id IS NOT NULL AND t.user_id>0 AND t.year>=YEAR(CURDATE())", 'replace'=>true],
        ['key'=>'qab_notify', 'module'=>'品質異常處理單', 'title'=>'自動開立單的品管通知名單', 'url'=>'/EGsystem/views/QA/qa_abnormal_list.php',
         'table'=>'qab_auto_qc_notify_cfg', 'col'=>'user_id', 'pk'=>'id', 'label_sql'=>"'品管確認通知名單'", 'replace'=>true],
        ['key'=>'acc_track', 'module'=>'對帳作業', 'title'=>'追蹤負責人候選名單', 'url'=>'/EGsystem/views/ACC/reconcile.php',
         'table'=>'acc_recon_track_owner_pool', 'col'=>'user_id', 'pk'=>'id', 'label_sql'=>"'對帳追蹤負責人候選'", 'replace'=>true],
        ['key'=>'roster_member', 'module'=>'輪值排班表', 'title'=>'輪值成員', 'url'=>'/EGsystem/views/pages/roster.php',
         'table'=>'roster_member', 'col'=>'user_id', 'pk'=>'id',
         'label_sql'=>"IFNULL((SELECT name FROM roster_board WHERE id=t.board_id),'?')",
         'where'=>'t.active=1', 'replace'=>false],   // 輪值有已排好的班表，換人要在排班頁處理
        ['key'=>'roster_owner', 'module'=>'輪值排班表', 'title'=>'排班表負責人', 'url'=>'/EGsystem/views/pages/roster.php',
         'table'=>'roster_board', 'col'=>'owner_id', 'pk'=>'id', 'label_sql'=>"t.name",
         'where'=>"t.owner_id IS NOT NULL AND t.owner_id>0 AND (t.end_date IS NULL OR t.end_date>=CURDATE())", 'replace'=>true],
        ['key'=>'equip', 'module'=>'機台／檢驗設備一覽表', 'title'=>'保養人／保管人（現任）', 'url'=>'/EGsystem/views/pm/equipment_machine_list.php',
         'table'=>'equip_assignee_history', 'col'=>'user_id', 'pk'=>'hist_id',
         'label_sql'=>"CONCAT(IF(t.equip_type='machine','機台','檢驗設備'),' #',t.equip_ref_id)",
         'where'=>"t.end_date IS NULL OR t.end_date>=CURDATE()", 'replace'=>false],   // 保管人有日期區間歷程，要在原頁面接續
        ['key'=>'bom_watch', 'module'=>'BOM 追蹤', 'title'=>'關注群組擁有者', 'url'=>'/EGsystem/views/pm/bom_tracking.php',
         'table'=>'bom_watch_group', 'col'=>'owner_user_id', 'pk'=>'group_id', 'label_sql'=>"t.group_name",
         'where'=>'t.owner_user_id IS NOT NULL AND t.owner_user_id>0', 'replace'=>false],
        ['key'=>'shared_acc', 'module'=>'共用帳號', 'title'=>'共用帳號成員綁定', 'url'=>'/EGsystem/views/ADM/hr_settings.php',
         'table'=>'shared_account_member', 'col'=>'member_uid', 'pk'=>'id',
         'label_sql'=>"CONCAT('共用帳號 ',IFNULL((SELECT user_cname FROM `user` WHERE id=t.shared_uid),'?'))",
         'where'=>'t.active=1', 'replace'=>false],
    ];
}

/**
 * 掃出所有指派。
 * @param int  $onlyUser  >0 只看這個人
 * @param bool $onlyInactive 只留「目前不能用了」的人（離職／留停／育嬰／預定離職日已過）
 * @return array 每列 ['src','module','title','label','url','user_id','user_name','state','leave_date','inactive','pk','replace']
 */
function uas_scan(PDO $db, int $onlyUser = 0, bool $onlyInactive = false): array
{
    $orgRoles = defined('EG_ORG_ROLES') ? EG_ORG_ROLES : [];
    $out = [];
    foreach (uas_sources() as $s) {
        $w = ['t.`' . $s['col'] . '` IS NOT NULL', 't.`' . $s['col'] . '`>0'];
        if (!empty($s['where'])) $w[] = '(' . $s['where'] . ')';
        $args = [];
        if ($onlyUser > 0) { $w[] = 't.`' . $s['col'] . '`=?'; $args[] = $onlyUser; }
        if ($onlyInactive) $w[] = uas_inactive_sql('u');
        $sql = "SELECT t.`{$s['pk']}` AS pk, t.`{$s['col']}` AS uid, ({$s['label_sql']}) AS label,
                       u.user_cname, u.state, u.leave_date, " . uas_inactive_sql('u') . " AS inactive
                  FROM `{$s['table']}` t "
             . ($s['join'] ?? '') . "
                  JOIN `user` u ON u.id = t.`{$s['col']}`
                 WHERE " . implode(' AND ', $w);
        try {
            $st = $db->prepare($sql);
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $label = (string)$r['label'];
                if (($s['label_map'] ?? '') === 'org_roles' && isset($orgRoles[$label]['label'])) $label = $orgRoles[$label]['label'];
                $out[] = [
                    'src' => $s['key'], 'module' => $s['module'], 'title' => $s['title'],
                    'label' => $label, 'url' => $s['url'],
                    'user_id' => (int)$r['uid'], 'user_name' => (string)$r['user_cname'],
                    'state' => $r['state'] === null ? null : (int)$r['state'],
                    'leave_date' => $r['leave_date'],
                    'inactive' => (int)$r['inactive'] === 1,
                    'pk' => (string)$r['pk'], 'replace' => !empty($s['replace']),
                ];
            }
        } catch (Throwable $e) {
            error_log('[user_assign] ' . $s['key'] . ': ' . $e->getMessage());   // 單一來源壞了不讓整份總覽消失
        }
    }
    return $out;
}

/** 把某一列指派換成另一個人。回 ['ok'=>bool,'msg'=>string] */
function uas_replace(PDO $db, string $srcKey, string $pk, int $oldUid, int $newUid, string $operator, int $operatorId): array
{
    $src = null;
    foreach (uas_sources() as $s) if ($s['key'] === $srcKey) { $src = $s; break; }
    if (!$src)               return ['ok'=>false,'msg'=>'不認得的設定來源'];
    if (empty($src['replace'])) return ['ok'=>false,'msg'=>'這一項要到原設定頁處理（有日期歷程或已排好的班表，不能直接換）'];
    if ($newUid <= 0 || $newUid === $oldUid) return ['ok'=>false,'msg'=>'請選擇不同的替代人員'];

    $st = $db->prepare("SELECT user_cname, state, leave_date FROM `user` WHERE id=?");
    $st->execute([$newUid]);
    $nu = $st->fetch(PDO::FETCH_ASSOC);
    if (!$nu) return ['ok'=>false,'msg'=>'替代人員不存在'];
    if (in_array((int)$nu['state'], [0,2,3,90,99], true) || (!empty($nu['leave_date']) && $nu['leave_date'] < date('Y-m-d')))
        return ['ok'=>false,'msg'=>'替代人員目前不是在職狀態'];

    $tbl = $src['table']; $col = $src['col']; $pkc = $src['pk'];
    try {
        $db->beginTransaction();
        $sel = $db->prepare("SELECT `{$col}` FROM `{$tbl}` WHERE `{$pkc}`=? FOR UPDATE");
        $sel->execute([$pk]);
        $cur = $sel->fetchColumn();
        if ($cur === false || (int)$cur !== $oldUid) {         // 畫面是舊的（別人剛改過）
            $db->rollBack();
            return ['ok'=>false,'msg'=>'這一項的設定剛被其他人改過，請重新整理後再處理'];
        }
        $db->prepare("UPDATE `{$tbl}` SET `{$col}`=? WHERE `{$pkc}`=? AND `{$col}`=?")->execute([$newUid, $pk, $oldUid]);
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, operator, user_id, created_at)
                      VALUES ('ASSIGN_REPLACE','user_assign',?,?,?,?,?,NOW())")
           ->execute([$newUid, $src['module'] . '／' . $src['title'],
                      json_encode(['src'=>$srcKey,'pk'=>$pk,'from'=>$oldUid,'to'=>$newUid], JSON_UNESCAPED_UNICODE),
                      $operator, $operatorId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[user_assign] replace: ' . $e->getMessage());
        return ['ok'=>false,'msg'=>'更換失敗：' . $e->getMessage()];
    }
    return ['ok'=>true,'msg'=>'已更換為 ' . $nu['user_cname']];
}

/** 收通知的人：全站管理員 + 該離職者各部門的最高主管 */
function uas_notify_uids(PDO $db, array $leaverIds): array
{
    @include_once __DIR__ . '/unit_supervisor_lib.php';
    $uids = [];
    try {
        foreach ($db->query("SELECT id FROM `user` WHERE (user_status IN (9,90) OR id=1) AND (state IS NULL OR state NOT IN (0,90))")
                    ->fetchAll(PDO::FETCH_COLUMN) as $v) $uids[] = (int)$v;
    } catch (Throwable $e) {}
    if ($leaverIds && function_exists('eg_unit_supervisor')) {
        foreach ($leaverIds as $lid) {
            try {
                $h = eg_unit_supervisor($db, (int)$lid, 0);
                if (!empty($h['id'])) $uids[] = (int)$h['id'];
            } catch (Throwable $e) {}
        }
    }
    return array_values(array_unique(array_filter($uids)));
}

/**
 * 有離職者仍被指派 → 通知管理員與其主管。同一組內容 UAS_NOTIFY_MIN_DAYS 天內只發一次。
 * @return int 發給幾個人
 */
function uas_notify(PDO $db): int
{
    $rows = uas_scan($db, 0, true);
    if (!$rows) return 0;
    $sigParts = array_map(fn($r) => $r['src'] . ':' . $r['pk'] . ':' . $r['user_id'], $rows);
    sort($sigParts);
    $sig = md5(implode('|', $sigParts));
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='USER_ASSIGN' AND param_key='notify_state' LIMIT 1");
        $st->execute();
        $prev = json_decode((string)($st->fetchColumn() ?: ''), true);
    } catch (Throwable $e) { $prev = null; }
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    if (is_array($prev) && ($prev['sig'] ?? '') === $sig && !empty($prev['date'])
        && (strtotime($today) - strtotime((string)$prev['date'])) / 86400 < UAS_NOTIFY_MIN_DAYS) return 0;

    $leavers = array_values(array_unique(array_column($rows, 'user_id')));
    $uids = uas_notify_uids($db, $leavers);
    if (!$uids) return 0;

    $by = [];
    foreach ($rows as $r) $by[$r['user_name']][] = $r['module'] . '／' . $r['label'];
    $title = '離職／停用人員仍被指派於系統設定（' . count($leavers) . ' 人、' . count($rows) . ' 項）';
    $body = "以下人員已不能使用系統，但設定裡還指定他們，請更換替代人員：\n";
    foreach ($by as $name => $items) $body .= '‧' . $name . '：' . implode('、', array_slice($items, 0, 6)) . (count($items) > 6 ? '…' : '') . "\n";
    $body .= "\n請到「人員指派總覽」直接換成替代人員。";

    $eid = 0;
    try {
        $db->exec("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE ref_type='USER_ASSIGN' AND (enddate IS NULL OR enddate>=CURDATE())");
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, 0, '人員指派總覽', 1, 'USER_ASSIGN', 0)")->execute([$title, $body]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')");
        foreach ($uids as $u) $ins->execute([$eid, $u]);
    } catch (Throwable $e) { error_log('[user_assign] live_event: ' . $e->getMessage()); }

    try {
        require_once __DIR__ . '/../push/push_send.php';
        $to = ($eid && function_exists('eg_push_event_recipients')) ? eg_push_event_recipients($db, $eid) : $uids;
        eg_push_send_to_users($db, $to, ['title'=>$title, 'body'=>mb_substr($body, 0, 480),
            'url'=>'/EGsystem/views/admin/user_assignments.php?inactive=1', 'tag'=>'user-assign']);
    } catch (Throwable $e) { error_log('[user_assign] push: ' . $e->getMessage()); }

    try {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,updated_by,updated_at)
                      VALUES ('USER_ASSIGN','notify_state',?,'system',NOW())
                      ON DUPLICATE KEY UPDATE param_value=VALUES(param_value), updated_at=NOW()")
           ->execute([json_encode(['sig'=>$sig,'date'=>$today,'n'=>count($rows)])]);
    } catch (Throwable $e) {}
    return count($uids);
}
