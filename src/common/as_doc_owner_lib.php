<?php
/**
 * as_doc_owner_lib.php — AS 文件的「部門負責人與代理人」（唯一實作）
 * 建立：2026-10-15（使用者交辦）
 *
 * 要解決的事（使用者原話）：
 *   「在 as_document_management.php 內增加管理員指派對應到各部門的 AS 文件內部門負責人與代理人
 *    （皆須為負責課室或是文件負責部門內人員，兼任或主要職位都可）。
 *    特別注意此人員若離職時務必提醒需要設定新 AS 文件部門負責人，且暫時由代理人代理。
 *    若部門負責人或代理人有調離該負責課室或是文件負責部門，則需要提醒要重新設定相關人員。
 *    相關提醒應通知全站管理員與設定的『AS 文件管理 — 角色設定＝AS負責人』這兩者，
 *    並在進入該頁時跳出提示直到設定都正確設定。」
 *
 * 為什麼是「掛在部門」不是「掛在每一份文件」：
 *   文件有 164 份、負責課室只有 12 個，逐份指派等於要維護 164 筆而且每次新增文件都要補一次；
 *   而現場的實際情形本來就是「這個課室的 AS 文件由課室裡的某一位負責」。
 *   文件要找負責人時由「文件 → 它的負責課室／負責部門 → 這張表」推導（ado_doc_owner）。
 *
 * 判定上的三個重點：
 *   ①**在職判定一律用 `user.state`**（0＝離職、90＝特殊帳號；見 CLAUDE.md 人員列表鐵則），
 *     不要用 user_status——那是權限欄位，拿它判在職會把離職者當成在職。
 *   ②**部門歸屬要含兼任**：`user_department_position_map` 一人可能有好幾列，
 *     只看 is_main 的那一列會把兼任該課室的人判成「不在這個部門」而要求重新設定（假警報）。
 *   ③**離職與調離要分開講**：離職＝這個人不在了，要指派新的人；
 *     調離＝人還在但不屬於這個課室了，照規則不能再當這個課室的負責人。
 *     兩種提醒的處理方式不同，混成一句話收件人不知道該做什麼。
 */

if (!function_exists('ado_ensure_schema')) {

require_once __DIR__ . '/people_lib.php';

/** 提醒的最小間隔（天）；同一組問題在這段時間內不重複發 */
if (!defined('ADO_NOTIFY_MIN_DAYS')) define('ADO_NOTIFY_MIN_DAYS', 7);
/** 設定存放位置 */
if (!defined('ADO_PARAM_GROUP')) define('ADO_PARAM_GROUP', 'AS_DOC_OWNER');

/* ════════════════════════════════════════════════════════════════════════
   Schema
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 建表。**先 SHOW TABLES 確認不存在、且不在交易中才下 DDL**——
 * CREATE TABLE 會造成 MySQL 隱式 commit，在交易裡建表會讓外層 commit() 爆
 * 「There is no active transaction」，而且資料其實已經寫進去了、畫面卻顯示失敗
 * （本專案已踩四次，見 CLAUDE.md 2026-08-03／2026-09-21）。
 */
function ado_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $has = (bool)$db->query("SHOW TABLES LIKE 'as_doc_dept_owner'")->fetchColumn();
        if (!$has && !$db->inTransaction()) {
            $db->exec("CREATE TABLE as_doc_dept_owner (
                department_id INT NOT NULL PRIMARY KEY COMMENT '部門(負責課室或文件負責部門)',
                owner_uid     INT NULL COMMENT 'AS文件部門負責人 user.id',
                deputy_uid    INT NULL COMMENT '代理人 user.id',
                note          VARCHAR(255) NULL,
                updated_by    INT NULL,
                updated_at    DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COMMENT='AS 文件的部門負責人與代理人（as_doc_owner_lib.php）'");
        }
        // MySQL 沒有 ADD COLUMN IF NOT EXISTS，先查再加
        if ($db->query("SHOW TABLES LIKE 'as_doc_dept_owner'")->fetchColumn()) {
            $c = $db->query("SHOW COLUMNS FROM as_doc_dept_owner LIKE 'cand_dept_ids'")->fetchColumn();
            if (!$c && !$db->inTransaction()) {
                $db->exec("ALTER TABLE as_doc_dept_owner ADD COLUMN cand_dept_ids VARCHAR(255) NULL
                           COMMENT '額外可挑選負責人的來源部門 id（逗號分隔）'");
            }
        }
    } catch (Throwable $e) { error_log('[ado] schema: ' . $e->getMessage()); }
    $done = true;
}

/* ════════════════════════════════════════════════════════════════════════
   部門清單與指派
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 部門 → 代表部門 id。品管課／品管組這種「看成同一個部門」的，沿用內部稽核已設定的
 * 受稽單位群組（ia_audit_unit；設定入口在內部稽核頁），不另建第二套合併設定（鐵律4）。
 * 沒被收編的部門就是自己。
 */
function ado_key(PDO $db, int $deptId): int
{
    static $loaded = null;
    if ($loaded === null) {
        $loaded = false;
        try { require_once __DIR__ . '/internal_audit_lib.php'; $loaded = function_exists('ia_unit_key_of_dept'); }
        catch (Throwable $e) { error_log('[ado] key: ' . $e->getMessage()); }
    }
    if (!$loaded) return $deptId;
    try { return (int)ia_unit_key_of_dept($db, $deptId) ?: $deptId; } catch (Throwable $e) { return $deptId; }
}

/** 代表部門涵蓋的所有部門 id（群組成員，不含下轄；沒群組＝只有自己） */
function ado_group_ids(PDO $db, int $key): array
{
    $ids = [$key];
    try {
        $st = $db->prepare("SELECT d.dept_id FROM ia_audit_unit u
                            JOIN ia_audit_unit_dept d ON d.unit_id=u.unit_id
                            WHERE u.is_active=1 AND u.main_dept_id=?");
        $st->execute([$key]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[] = (int)$v;
    } catch (Throwable $e) {}
    return array_values(array_unique(array_filter($ids)));
}

/** 管理員為這個部門另外指定的「可挑選人員來源部門」 */
function ado_extra_depts(PDO $db, int $key): array
{
    ado_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT cand_dept_ids FROM as_doc_dept_owner WHERE department_id=?");
        $st->execute([$key]);
        $v = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) { return []; }
    $o = [];
    foreach (explode(',', $v) as $x) { $x = (int)trim($x); if ($x > 0) $o[] = $x; }
    return array_values(array_unique($o));
}

/**
 * 部門 id 去重。**刻意不展開下轄**：組織樹的根是董事長室→總經理室，展開下轄＝全公司，
 * 「總經理室的文件不由總經理處理」這種設定就變成誰都算在範圍內（本專案 org_role 的老坑）。
 * 需要一併看待的子部門（品管組、生產各廠）已由受稽單位群組明列成員。
 */
function ado_expand_subtree(PDO $db, array $ids): array
{
    return array_values(array_unique(array_map('intval', array_filter($ids))));
}

/**
 * 這個部門的負責人／代理人可以從哪些部門挑（含下轄）：
 * 本部門群組成員（品管課＋品管組）＋管理員另外指定的部門。
 */
function ado_scope_ids(PDO $db, int $key): array
{
    return ado_expand_subtree($db, array_merge(ado_group_ids($db, $key), ado_extra_depts($db, $key)));
}

/**
 * 跟 AS 文件有關的部門清單（＝需要指派負責人的部門）。
 * 來源**兩個都要算**：`as_doc_owner_dept`（負責課室，多對多）與
 * `as_document.department_id`（文件負責部門）——實測文件的 department_id 常常是空的，
 * 只取其中一個會漏掉一整批部門。
 *
 * @return array<int,array> 依部門排序，每筆含 dept_id／dept_name／docs（文件數）／
 *                          owner_uid／owner_name／deputy_uid／deputy_name／updated_at
 */
function ado_depts(PDO $db): array
{
    ado_ensure_schema($db);
    $map = [];
    $add = function (int $did, int $docId) use (&$map, $db) {
        if ($did <= 0) return;
        $did = ado_key($db, $did);   // 品管組的文件併入品管課
        if (!isset($map[$did])) $map[$did] = ['dept_id' => $did, 'docs' => [], 'dept_name' => ''];
        if ($docId > 0) $map[$did]['docs'][$docId] = 1;
    };
    try {
        foreach ($db->query("SELECT o.department_id, o.doc_id
                             FROM as_doc_owner_dept o
                             JOIN as_document d ON d.id = o.doc_id
                             WHERE d.is_deleted=0")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add((int)$r['department_id'], (int)$r['doc_id']);
        }
        foreach ($db->query("SELECT id, department_id FROM as_document
                             WHERE is_deleted=0 AND department_id IS NOT NULL AND department_id>0")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add((int)$r['department_id'], (int)$r['id']);
        }
    } catch (Throwable $e) { error_log('[ado] depts: ' . $e->getMessage()); }
    if (!$map) return [];

    $ids = implode(',', array_map('intval', array_keys($map)));
    try {
        foreach ($db->query("SELECT id, name, sort_order, level FROM department WHERE id IN ($ids)")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['id']]['dept_name']  = (string)$r['name'];
            $map[(int)$r['id']]['sort_order'] = (int)($r['sort_order'] ?? 999);
            $map[(int)$r['id']]['level']      = (int)($r['level'] ?? 0);
        }
    } catch (Throwable $e) {}
    try {
        foreach ($db->query("SELECT o.department_id, o.owner_uid, o.deputy_uid, o.updated_at, o.note,
                                    o.cand_dept_ids,
                                    uo.user_cname owner_name, uo.state owner_state,
                                    ud.user_cname deputy_name, ud.state deputy_state
                             FROM as_doc_dept_owner o
                             LEFT JOIN `user` uo ON uo.id = o.owner_uid
                             LEFT JOIN `user` ud ON ud.id = o.deputy_uid
                             WHERE o.department_id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = (int)$r['department_id'];
            if (!isset($map[$d])) continue;
            $map[$d]['owner_uid']    = (int)($r['owner_uid'] ?? 0);
            $map[$d]['owner_name']   = (string)($r['owner_name'] ?? '');
            $map[$d]['owner_state']  = isset($r['owner_state']) ? (int)$r['owner_state'] : null;
            $map[$d]['deputy_uid']   = (int)($r['deputy_uid'] ?? 0);
            $map[$d]['deputy_name']  = (string)($r['deputy_name'] ?? '');
            $map[$d]['deputy_state'] = isset($r['deputy_state']) ? (int)$r['deputy_state'] : null;
            $map[$d]['updated_at']   = (string)($r['updated_at'] ?? '');
            $map[$d]['note']         = (string)($r['note'] ?? '');
            $map[$d]['cand_depts']   = array_values(array_filter(array_map('intval', explode(',', (string)($r['cand_dept_ids'] ?? '')))));
        }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($map as $d => $v) {
        $v['docs'] = count($v['docs']);
        $v += ['dept_name' => '(部門 #' . $d . ')', 'sort_order' => 999, 'level' => 0,
               'owner_uid' => 0, 'owner_name' => '', 'owner_state' => null,
               'deputy_uid' => 0, 'deputy_name' => '', 'deputy_state' => null,
               'updated_at' => '', 'note' => '', 'cand_depts' => []];
        $nm = [];
        foreach (ado_group_ids($db, $d) as $g) {
            if ($g === $d) continue;
            try { $q = $db->prepare("SELECT name FROM department WHERE id=?"); $q->execute([$g]); $n = $q->fetchColumn(); if ($n) $nm[] = (string)$n; } catch (Throwable $e) {}
        }
        $v['group_names'] = $nm;   // 一併看待的其他部門（顯示用）
        $out[] = $v;
    }
    usort($out, function ($a, $b) {
        return [$a['sort_order'], $a['dept_name']] <=> [$b['sort_order'], $b['dept_name']];
    });
    return $out;
}

/** 這個部門目前在職的人員（含兼任；一個職務一列，供下拉挑選＝CLAUDE.md 人員列表鐵則⑥） */
function ado_candidates(PDO $db, int $deptId): array
{
    if ($deptId <= 0) return [];
    try {
        return eg_people_list($db, ['dept_ids' => ado_scope_ids($db, ado_key($db, $deptId)), 'all_posts' => true]);
    } catch (Throwable $e) { error_log('[ado] cand: ' . $e->getMessage()); return []; }
}

/** 每個部門的候選人員（下拉用；一個職務一列＝人員列表鐵則⑥） */
function ado_cands_map(PDO $db, array $depts): array
{
    $cands = [];
    foreach ($depts as $d) {
        $did = (int)$d['dept_id'];
        $cands[$did] = array_map(function ($p) {
            return ['id' => (int)$p['id'], 'name' => (string)$p['user_cname'],
                    'dept' => (string)($p['dept_name'] ?? ''), 'pos' => (string)($p['position_name'] ?? ''),
                    'leave' => (string)($p['leave_label'] ?? '')];
        }, ado_candidates($db, $did));
    }
    return $cands;
}

/**
 * 這個人現在屬不屬於這個部門（含兼任）。
 * ⚠ 一定要含兼任：只看 is_main 的話，兼任該課室的人會被判成「已調離」而每天要求重新設定。
 */
function ado_user_in_dept(PDO $db, int $uid, int $deptId): bool
{
    if ($uid <= 0 || $deptId <= 0) return false;
    try {
        $ids = ado_scope_ids($db, ado_key($db, $deptId));
        $in = implode(',', array_map('intval', $ids ?: [$deptId]));
        $st = $db->prepare("SELECT 1 FROM user_department_position_map
                            WHERE user_id=? AND department_id IN ($in) LIMIT 1");
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** 在職嗎（0＝離職、90＝特殊帳號皆視為不可用） */
function ado_user_active(PDO $db, int $uid): bool
{
    if ($uid <= 0) return false;
    try {
        $st = $db->prepare("SELECT state FROM `user` WHERE id=? LIMIT 1");
        $st->execute([$uid]);
        $v = $st->fetchColumn();
        if ($v === false) return false;
        return !in_array((int)$v, [0, 90], true);
    } catch (Throwable $e) { return false; }
}

/**
 * 指派／更新某部門的負責人與代理人。回傳 [ok, msg]。
 * 驗證（後端把關，前端另有即時提示＝鐵律8）：
 *   - 部門必須真的有 AS 文件
 *   - 負責人與代理人都必須是**該部門內在職人員**（兼任或主要職位皆可）
 *   - 負責人與代理人不可以是同一個人（代理的意義就是本人不在時有別人可以接）
 *   - 兩個都留空＝清除這個部門的設定
 */
function ado_save(PDO $db, int $deptId, int $ownerUid, int $deputyUid, int $uid, string $note = '', ?array $extraDepts = null): array
{
    ado_ensure_schema($db);
    if ($deptId <= 0) return [false, '請選擇部門'];
    $ok = false;
    foreach (ado_depts($db) as $d) if ((int)$d['dept_id'] === $deptId) { $ok = true; break; }
    if (!$ok) return [false, '這個部門底下沒有任何 AS 文件，不需要指派負責人'];

    if ($ownerUid > 0 && $deputyUid > 0 && $ownerUid === $deputyUid)
        return [false, '負責人與代理人不可以是同一個人'];
    // 來源部門：null＝沒送這個欄位，維持原值；陣列＝管理員這次指定的（先驗證存在）
    if ($extraDepts === null) {
        $extraDepts = ado_extra_depts($db, $deptId);
    } else {
        $clean = [];
        foreach ($extraDepts as $x) {
            $x = (int)$x;
            if ($x <= 0 || $x === $deptId) continue;
            $q = $db->prepare("SELECT 1 FROM department WHERE id=?"); $q->execute([$x]);
            if (!$q->fetchColumn()) return [false, '指定的來源部門不存在'];
            $clean[$x] = $x;
        }
        $extraDepts = array_values($clean);
    }
    $extraStr = $extraDepts ? implode(',', $extraDepts) : null;
    // 驗證人員時要吃「這次新指定的來源部門」，所以不能直接呼叫 ado_user_in_dept（它讀的是存檔前的舊值）
    $scopeIds = ado_expand_subtree($db, array_merge(ado_group_ids($db, $deptId), $extraDepts));
    $inScope = function (int $u) use ($db, $scopeIds): bool {
        $in = implode(',', array_map('intval', $scopeIds));
        $q = $db->prepare("SELECT 1 FROM user_department_position_map WHERE user_id=? AND department_id IN ($in) LIMIT 1");
        $q->execute([$u]);
        return (bool)$q->fetchColumn();
    };
    foreach ([['負責人', $ownerUid], ['代理人', $deputyUid]] as [$lab, $u]) {
        if ($u <= 0) continue;
        if (!ado_user_active($db, $u)) return [false, $lab . '不在職，請改指派在職人員'];
        if (!$inScope($u))
            return [false, $lab . '不屬於這個部門或管理員指定的來源部門（兼任也算）'];
    }
    if ($ownerUid <= 0 && $deputyUid > 0)
        return [false, '只設代理人沒有意義，請先指派負責人'];

    try {
        if ($ownerUid <= 0 && $deputyUid <= 0 && !$extraDepts) {
            $db->prepare("DELETE FROM as_doc_dept_owner WHERE department_id=?")->execute([$deptId]);
            return [true, '已清除這個部門的設定'];
        }
        $st = $db->prepare("INSERT INTO as_doc_dept_owner
                            (department_id, owner_uid, deputy_uid, note, cand_dept_ids, updated_by, updated_at)
                            VALUES (?,?,?,?,?,?,NOW())
                            ON DUPLICATE KEY UPDATE owner_uid=VALUES(owner_uid), cand_dept_ids=VALUES(cand_dept_ids),
                              deputy_uid=VALUES(deputy_uid), note=VALUES(note),
                              updated_by=VALUES(updated_by), updated_at=NOW()");
        $st->execute([$deptId, $ownerUid ?: null, $deputyUid ?: null,
                      mb_substr(trim($note), 0, 255), $extraStr, $uid]);
    } catch (Throwable $e) { return [false, '存檔失敗：' . $e->getMessage()]; }
    return [true, '已儲存'];
}

/* ════════════════════════════════════════════════════════════════════════
   問題盤點（畫面橫幅與提醒共用同一支，不可以兩邊各算一次）
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 盤點目前有哪些「設定不正確」的地方。
 * @return array<int,array{dept_id:int,dept_name:string,docs:int,kind:string,level:string,
 *                         who:string,msg:string}>
 *   kind：no_owner／owner_left／owner_moved／no_deputy／deputy_left／deputy_moved
 *   level：block（一定要處理）／warn（建議處理）
 */
function ado_problems(PDO $db): array
{
    $out = [];
    foreach (ado_depts($db) as $d) {
        $did = (int)$d['dept_id'];
        $dn  = (string)$d['dept_name'];
        $docs = (int)$d['docs'];
        $ow = (int)$d['owner_uid'];
        $dp = (int)$d['deputy_uid'];

        if ($ow <= 0) {
            /* 這個部門根本沒有在職人員時一定要講出來（實測文管中心就是這種情況：
               掛著 20 份 AS 文件，但部門裡唯一的人已經離職）。
               只寫「還沒指派」的話，管理員打開設定看到空的下拉選單會不知道為什麼挑不到人。 */
            $noStaff = count(ado_candidates($db, $did)) === 0;
            $out[] = ['dept_id' => $did, 'dept_name' => $dn, 'docs' => $docs,
                      'kind' => $noStaff ? 'no_staff' : 'no_owner',
                      'level' => 'block', 'who' => '',
                      'msg' => $noStaff
                        ? ($dn . '底下有 ' . $docs . ' 份 AS 文件，但這個部門目前沒有任何在職人員，'
                           . '所以指派不了負責人——請先到員工管理把人員掛到這個部門（兼任也可以），'
                           . '或是把這些文件的負責課室改到別的部門')
                        : ($dn . '底下有 ' . $docs . ' 份 AS 文件，還沒指派部門負責人')];
            continue;   // 連負責人都沒有，就不必再報代理人的問題（會變成同一件事報兩次）
        }
        $ownerBad = false;
        if (!ado_user_active($db, $ow)) {
            $ownerBad = true;
            $out[] = ['dept_id' => $did, 'dept_name' => $dn, 'docs' => $docs, 'kind' => 'owner_left',
                      'level' => 'block', 'who' => (string)$d['owner_name'],
                      'msg' => $dn . '的部門負責人「' . $d['owner_name'] . '」已離職，請指派新的負責人'
                             . ($dp > 0 ? '（目前暫由代理人「' . $d['deputy_name'] . '」代理）'
                                        : '（而且沒有設代理人，目前沒有人可以代理）')];
        } elseif (!ado_user_in_dept($db, $ow, $did)) {
            $ownerBad = true;
            $out[] = ['dept_id' => $did, 'dept_name' => $dn, 'docs' => $docs, 'kind' => 'owner_moved',
                      'level' => 'block', 'who' => (string)$d['owner_name'],
                      'msg' => $dn . '的部門負責人「' . $d['owner_name'] . '」已調離這個部門，請重新指派'];
        }
        if ($dp > 0) {
            if (!ado_user_active($db, $dp)) {
                $out[] = ['dept_id' => $did, 'dept_name' => $dn, 'docs' => $docs, 'kind' => 'deputy_left',
                          'level' => $ownerBad ? 'block' : 'warn', 'who' => (string)$d['deputy_name'],
                          'msg' => $dn . '的代理人「' . $d['deputy_name'] . '」已離職，請重新指派代理人'];
            } elseif (!ado_user_in_dept($db, $dp, $did)) {
                $out[] = ['dept_id' => $did, 'dept_name' => $dn, 'docs' => $docs, 'kind' => 'deputy_moved',
                          'level' => $ownerBad ? 'block' : 'warn', 'who' => (string)$d['deputy_name'],
                          'msg' => $dn . '的代理人「' . $d['deputy_name'] . '」已調離這個部門，請重新指派代理人'];
            }
        } elseif (!$ownerBad) {
            $out[] = ['dept_id' => $did, 'dept_name' => $dn, 'docs' => $docs, 'kind' => 'no_deputy',
                      'level' => 'warn', 'who' => '',
                      'msg' => $dn . '還沒設代理人（負責人請假或離職時沒有人可以接手）'];
        }
    }
    return $out;
}

/**
 * 某一份文件的 AS 負責人（含代理）。
 * 推導順序：文件的負責課室（可能多個）→ 文件負責部門；取第一個有設定的。
 * **負責人不在職或已調離時自動改回代理人**（使用者：「暫時由代理人代理」）。
 *
 * @return array{dept_id:int,dept_name:string,uid:int,name:string,is_deputy:bool,problem:string}|null
 */
function ado_doc_owner(PDO $db, int $docId): ?array
{
    ado_ensure_schema($db);
    if ($docId <= 0) return null;
    $depts = [];
    try {
        $st = $db->prepare("SELECT department_id FROM as_doc_owner_dept WHERE doc_id=?");
        $st->execute([$docId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) { $v = (int)$v; if ($v > 0) $depts[] = $v; }
        $st = $db->prepare("SELECT department_id FROM as_document WHERE id=? LIMIT 1");
        $st->execute([$docId]);
        $v = (int)($st->fetchColumn() ?: 0);
        if ($v > 0) $depts[] = $v;
    } catch (Throwable $e) { return null; }
    $depts = array_values(array_unique(array_map(function ($d) use ($db) { return ado_key($db, (int)$d); }, $depts)));
    if (!$depts) return null;

    $in = implode(',', $depts);
    try {
        $rows = $db->query("SELECT o.department_id, o.owner_uid, o.deputy_uid,
                                   dp.name dept_name,
                                   uo.user_cname owner_name, ud.user_cname deputy_name
                            FROM as_doc_dept_owner o
                            LEFT JOIN department dp ON dp.id = o.department_id
                            LEFT JOIN `user` uo ON uo.id = o.owner_uid
                            LEFT JOIN `user` ud ON ud.id = o.deputy_uid
                            WHERE o.department_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if (!$rows) return null;

    // 依 $depts 的順序挑（負責課室優先於文件負責部門）
    $byDept = [];
    foreach ($rows as $r) $byDept[(int)$r['department_id']] = $r;
    foreach ($depts as $d) {
        if (!isset($byDept[$d])) continue;
        $r = $byDept[$d];
        $ow = (int)($r['owner_uid'] ?? 0);
        $dp = (int)($r['deputy_uid'] ?? 0);
        $okOwner = $ow > 0 && ado_user_active($db, $ow) && ado_user_in_dept($db, $ow, $d);
        if ($okOwner) {
            return ['dept_id' => $d, 'dept_name' => (string)$r['dept_name'], 'uid' => $ow,
                    'name' => (string)$r['owner_name'], 'is_deputy' => false, 'problem' => ''];
        }
        if ($dp > 0 && ado_user_active($db, $dp) && ado_user_in_dept($db, $dp, $d)) {
            return ['dept_id' => $d, 'dept_name' => (string)$r['dept_name'], 'uid' => $dp,
                    'name' => (string)$r['deputy_name'], 'is_deputy' => true,
                    'problem' => $ow > 0 ? '負責人目前不可用，暫由代理人代理' : '沒有指派負責人，暫由代理人代理'];
        }
    }
    return null;
}

/* ════════════════════════════════════════════════════════════════════════
   提醒（站內通知＋Web Push＋Telegram）
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 收提醒的人：全站管理員 ＋ AS負責人角色（asdoc_editor）＋ 本頁管理員（asdoc_admin 功能碼）。
 * 使用者指定前兩者；本頁管理員一併收是因為他們就是有權限去改這個設定的人，
 * 不通知他們就會變成「有人能修、卻沒收到通知」。
 */
function ado_notify_uids(PDO $db): array
{
    $uids = [];
    try {
        foreach ($db->query("SELECT id FROM `user`
                             WHERE (user_status IN (9,90) OR id=1)
                               AND (state IS NULL OR state NOT IN (0,90))")
                    ->fetchAll(PDO::FETCH_COLUMN) as $v) $uids[] = (int)$v;
    } catch (Throwable $e) {}
    try {
        foreach ($db->query("SELECT DISTINCT ur.user_id
                             FROM user_roles ur
                             JOIN roles r ON r.role_id = ur.role_id
                             LEFT JOIN role_features f ON f.role_id = r.role_id
                             JOIN `user` u ON u.id = ur.user_id
                             WHERE r.module='as_doc'
                               AND (r.role_code='asdoc_editor' OR f.feature_code='asdoc_admin')
                               AND (u.state IS NULL OR u.state NOT IN (0,90))")
                    ->fetchAll(PDO::FETCH_COLUMN) as $v) $uids[] = (int)$v;
    } catch (Throwable $e) {}
    return array_values(array_unique(array_filter($uids)));
}

/** 這一組問題的指紋（內容一樣就不重複發） */
function ado_problem_sig(array $problems): string
{
    $a = array_map(fn($p) => $p['dept_id'] . ':' . $p['kind'] . ':' . $p['who'], $problems);
    sort($a);
    return md5(implode('|', $a));
}

/**
 * 有問題就發提醒（同一組問題 ADO_NOTIFY_MIN_DAYS 天內只發一次）。
 * @return int 發給幾個人（0＝沒問題或還在冷卻中）
 */
function ado_notify(PDO $db): int
{
    ado_ensure_schema($db);
    $probs = array_values(array_filter(ado_problems($db), fn($p) => $p['level'] === 'block'));
    if (!$probs) return 0;                      // warn 級（只是沒設代理人）不吵人，畫面上提示就夠

    $sig = ado_problem_sig($probs);
    $key = 'notify_state';
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters
                            WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([ADO_PARAM_GROUP, $key]);
        $prev = json_decode((string)($st->fetchColumn() ?: ''), true);
    } catch (Throwable $e) { $prev = null; }
    $today = date('Y-m-d');
    try { $today = (string)$db->query("SELECT CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
    if (is_array($prev) && ($prev['sig'] ?? '') === $sig && !empty($prev['date'])) {
        $days = (strtotime($today) - strtotime((string)$prev['date'])) / 86400;
        if ($days < ADO_NOTIFY_MIN_DAYS) return 0;
    }

    $uids = ado_notify_uids($db);
    if (!$uids) return 0;

    $title = 'AS 文件部門負責人需要重新設定（' . count($probs) . ' 項）';
    $body  = "以下 AS 文件負責課室的部門負責人／代理人設定需要處理：\n";
    foreach ($probs as $i => $p) $body .= ($i + 1) . '. ' . $p['msg'] . "\n";
    $body .= "\n請到「AS 文件管理」→ 工具列「部門負責人」設定。"
           . "\n（負責人離職或調離期間，系統會自動改用該部門的代理人；代理人也不可用時就沒有人可以簽核。）";

    $eid = 0;
    try {
        // 舊的同類提醒先收掉，通知欄才不會一直疊同一件事
        $db->exec("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                   WHERE ref_type='AS_DOC_OWNER' AND (enddate IS NULL OR enddate>=CURDATE())");
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                        show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, 0, 'AS文件管理', 1, 'AS_DOC_OWNER', 0)")
           ->execute([$title, $body]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                             VALUES (?, 'user', ?, 'read')");
        foreach ($uids as $u) $ins->execute([$eid, $u]);
    } catch (Throwable $e) { error_log('[ado] live_event: ' . $e->getMessage()); }

    try {
        require_once __DIR__ . '/../push/push_send.php';
        $to = ($eid && function_exists('eg_push_event_recipients')) ? eg_push_event_recipients($db, $eid) : $uids;
        eg_push_send_to_users($db, $to, [
            'title' => $title,
            'body'  => mb_substr($body, 0, 480),
            'url'   => '/EGsystem/views/ADM/as_document_management.php?dept_owner=1',
            'tag'   => 'as-doc-owner',
        ]);
    } catch (Throwable $e) { error_log('[ado] push: ' . $e->getMessage()); }

    try {
        require_once __DIR__ . '/../../telegram/send_message.php';
        if (function_exists('tg_is_configured') && tg_is_configured()) {
            $in = implode(',', array_map('intval', $uids));
            foreach ($db->query("SELECT chat_id FROM telegram_users WHERE is_active=1 AND user_id IN ($in)")
                        ->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
                tg_send_text($chatId, $title . "\n" . $body, $db);
            }
        }
    } catch (Throwable $e) { error_log('[ado] telegram: ' . $e->getMessage()); }

    try {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,updated_by,updated_at)
                      VALUES (?,?,?,'system',NOW())
                      ON DUPLICATE KEY UPDATE param_value=VALUES(param_value), updated_at=NOW()")
           ->execute([ADO_PARAM_GROUP, $key,
                      json_encode(['sig' => $sig, 'date' => $today, 'n' => count($probs)])]);
    } catch (Throwable $e) {}

    return count($uids);
}

}   // end if (!function_exists('ado_ensure_schema'))
