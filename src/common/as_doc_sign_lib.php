<?php
/**
 * as_doc_sign_lib.php — AS 文件線上版的「制修訂／審查／核准」送簽流程（2026-09-22 新增）
 *
 * 使用者交辦（原話重點）：
 *   ①「右側的制修訂、審查、核准 可以仿照 form_signer.php 讓管理員設定自動簽核嗎?(要自動產生簽核紀錄)」
 *   ②「一樣可以由建立者選定管理員已經依照 制修訂部門 設定好的 審查、核准 部門人員名單中選取
 *      (這邊應該是設定部門與職稱，人員自動判定送審當下在職之人員通知審核)」
 *   ③「注意管理員要可以設定審核順序」
 *   ④「制修訂則固定顯示表單修改人(管理員可以更改)」
 *   ⑤「正式發行前要自動檢查是否是較新版本，正式發行後(需要經過送簽或是自動簽核完成)
 *      自動更新前端 as_document_management.php 的文件改版相關資料」
 *
 * 設計重點（動手前想清楚的幾件事）：
 *  - **設定存「部門＋職稱」不存人**：人員異動、離職、調部門都不必回來改設定，
 *    送審當下才用 eg_people_list() 解析「現在在職的是誰」（ai-rules/22 第5坑的同一條）。
 *  - **簽核事實一律寫 approval_record**（走共用 approval_lib，ai-rules/23）；
 *    本模組自己的表只記「這一關要誰簽、跑到哪裡」這種流程狀態，不另存一份簽核事實。
 *  - **自動簽核的時間戳依 ai-rules/21**：業務日期＝送出日，時間隨機錯開 5~30 分且不跨日，
 *    否則會印出「核准早於制修訂」。
 *  - **發行是獨立一步、而且會擋**：全部簽完才可發行，發行前一定要確認沒有更新的版次
 *    （舊版次的線上內容若能把 current_version_id 指回自己，等於把已發行的新版蓋掉）。
 */

require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/as_doc_content_lib.php';
// eg_dept_subtree_ids()：部門一律含下轄（品管部→品管組），
// 只比單一 id 會把子部門的人判成「不是這個部門的」（CLAUDE.md 組織綁定那條）
require_once __DIR__ . '/org_role_lib.php';

/** 三個關卡的固定代碼（與制修訂紀錄書上 data-sign 的值一致，不可各處自己取名） */
function ads_stages(): array
{
    return [
        'draft'   => '制修訂',
        'review'  => '審查',
        'approve' => '核准',
    ];
}

/** 簽核人來源 */
function ads_modes(): array
{
    return [
        'editor'   => '內容的最後修改人（表單修改人）',
        'dept_pos' => '指定部門＋職稱（送審當下在職者）',
        'user'     => '指定某一個人',
    ];
}

function ads_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // DDL 在交易中會造成 MySQL 隱式 commit（本專案已踩過兩次），
    // 所以一律先確認表不存在、而且目前不在交易中，才下 DDL
    $need = [];
    foreach (['as_doc_sign_cfg', 'as_doc_sign_case', 'as_doc_sign_step'] as $t) {
        $st = $db->prepare("SHOW TABLES LIKE ?");
        $st->execute([$t]);
        if (!$st->fetchColumn()) $need[] = $t;
    }
    if (!$need || $db->inTransaction()) return;

    if (in_array('as_doc_sign_cfg', $need, true)) {
        $db->exec("CREATE TABLE IF NOT EXISTS as_doc_sign_cfg (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dept_id INT NOT NULL DEFAULT 0 COMMENT '這組設定適用哪個制修訂部門；0＝全站預設',
            stage VARCHAR(16) NOT NULL COMMENT 'draft／review／approve',
            seq INT NOT NULL DEFAULT 1 COMMENT '簽核順序（跨關卡共用同一組序號，管理員可排）',
            mode VARCHAR(16) NOT NULL DEFAULT 'dept_pos' COMMENT 'editor／dept_pos／user',
            pick_dept_id INT NULL COMMENT 'mode=dept_pos 時的部門',
            position_id INT NULL COMMENT 'mode=dept_pos 時的職稱；留空＝不限職稱',
            user_id INT NULL COMMENT 'mode=user 時的人',
            auto_sign TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1＝送審時自動簽掉這一關',
            updated_by INT NULL, updated_at DATETIME NULL,
            KEY k_scope (dept_id, stage, seq)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS 文件線上版簽核設定'");
    }
    if (in_array('as_doc_sign_case', $need, true)) {
        $db->exec("CREATE TABLE IF NOT EXISTS as_doc_sign_case (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version_id INT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending／approved／rejected／canceled',
            is_auto TINYINT(1) NOT NULL DEFAULT 0,
            submit_date DATE NULL COMMENT '業務日期（送出日）；與精確時間戳分開存，ai-rules/21',
            submitted_by INT NULL, submitted_by_name VARCHAR(60) NULL, submitted_at DATETIME NULL,
            finished_at DATETIME NULL,
            released_at DATETIME NULL, released_by INT NULL, released_by_name VARCHAR(60) NULL,
            reject_note VARCHAR(500) NULL,
            KEY k_ver (version_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS 文件線上版送簽案件'");
    }
    if (in_array('as_doc_sign_step', $need, true)) {
        $db->exec("CREATE TABLE IF NOT EXISTS as_doc_sign_step (
            id INT AUTO_INCREMENT PRIMARY KEY,
            case_id INT NOT NULL,
            stage VARCHAR(16) NOT NULL,
            seq INT NOT NULL DEFAULT 1,
            signer_user_id INT NULL, signer_name VARCHAR(60) NULL,
            dept_name VARCHAR(80) NULL, position_name VARCHAR(80) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'wait' COMMENT 'wait／ok／reject',
            is_auto TINYINT(1) NOT NULL DEFAULT 0,
            sign_date DATE NULL COMMENT '蓋在圖章上的日期（業務日期）',
            decided_at DATETIME NULL,
            note VARCHAR(500) NULL,
            approval_id INT NULL, live_event_id INT NULL,
            KEY k_case (case_id, seq, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS 文件線上版送簽關卡'");
    }
}

/* ══════════════════════════════════════════════════════════════════════
   管理員設定
   ══════════════════════════════════════════════════════════════════════ */

/** 這個部門實際生效的設定：有自己的就用自己的，沒有就用全站預設（dept_id=0） */
function ads_cfg_rows(PDO $db, int $deptId): array
{
    ads_ensure_schema($db);
    $fetch = function (int $d) use ($db) {
        $st = $db->prepare("SELECT * FROM as_doc_sign_cfg WHERE dept_id=? ORDER BY seq, id");
        $st->execute([$d]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };
    $rows = $deptId > 0 ? $fetch($deptId) : [];
    $scope = $deptId;
    if (!$rows) { $rows = $fetch(0); $scope = 0; }
    foreach ($rows as &$r) { $r['_scope'] = $scope; }
    unset($r);
    return $rows;
}

/** 整組覆寫某一個範圍的設定（管理員存檔）。$rows 是已排好順序的陣列 */
function ads_cfg_save(PDO $db, int $deptId, array $rows, int $uid): array
{
    ads_ensure_schema($db);
    $stages = ads_stages();
    $modes  = ads_modes();
    $clean = [];
    $seq = 0;
    foreach ($rows as $r) {
        $stage = (string)($r['stage'] ?? '');
        $mode  = (string)($r['mode'] ?? '');
        if (!isset($stages[$stage])) return ['ok' => false, 'msg' => '關卡代碼不正確'];
        if (!isset($modes[$mode]))   return ['ok' => false, 'msg' => '簽核人來源不正確'];
        $pd = (int)($r['pick_dept_id'] ?? 0);
        $pp = (int)($r['position_id'] ?? 0);
        $uu = (int)($r['user_id'] ?? 0);
        if ($mode === 'dept_pos') {
            if ($pd <= 0) return ['ok' => false, 'msg' => '選了「指定部門＋職稱」就一定要挑部門'];
            $st = $db->prepare("SELECT COUNT(*) FROM department WHERE id=?");
            $st->execute([$pd]);
            if (!(int)$st->fetchColumn()) return ['ok' => false, 'msg' => '選到的部門不存在'];
            if ($pp > 0) {
                $st = $db->prepare("SELECT COUNT(*) FROM position WHERE id=?");
                $st->execute([$pp]);
                if (!(int)$st->fetchColumn()) return ['ok' => false, 'msg' => '選到的職稱不存在'];
            }
        } elseif ($mode === 'user') {
            if ($uu <= 0) return ['ok' => false, 'msg' => '選了「指定某一個人」就一定要挑人'];
            $st = $db->prepare("SELECT COUNT(*) FROM user WHERE id=?");
            $st->execute([$uu]);
            if (!(int)$st->fetchColumn()) return ['ok' => false, 'msg' => '選到的人員不存在'];
        }
        $clean[] = [
            'stage' => $stage, 'seq' => ++$seq, 'mode' => $mode,
            'pick_dept_id' => $mode === 'dept_pos' ? $pd : null,
            'position_id'  => ($mode === 'dept_pos' && $pp > 0) ? $pp : null,
            'user_id'      => $mode === 'user' ? $uu : null,
            'auto_sign'    => !empty($r['auto_sign']) ? 1 : 0,
        ];
    }
    // 三個關卡至少各要有一列，否則制修訂紀錄書上會有空的簽章格而且沒人知道是誰要簽
    foreach (array_keys($stages) as $s) {
        $has = false;
        foreach ($clean as $c) if ($c['stage'] === $s) { $has = true; break; }
        if (!$has) return ['ok' => false, 'msg' => '「' . $stages[$s] . '」這一關至少要設定一位簽核人'];
    }

    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM as_doc_sign_cfg WHERE dept_id=?")->execute([$deptId]);
        $ins = $db->prepare("INSERT INTO as_doc_sign_cfg
            (dept_id, stage, seq, mode, pick_dept_id, position_id, user_id, auto_sign, updated_by, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW())");
        foreach ($clean as $c) {
            $ins->execute([$deptId, $c['stage'], $c['seq'], $c['mode'],
                           $c['pick_dept_id'], $c['position_id'], $c['user_id'], $c['auto_sign'], $uid]);
        }
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '設定存檔失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'msg' => '簽核設定已存檔', 'count' => count($clean)];
}

/* ══════════════════════════════════════════════════════════════════════
   候選人解析
   ══════════════════════════════════════════════════════════════════════ */

/**
 * 這一列設定「現在」有哪些人可以簽。
 * 一律即時解析：設定存的是部門與職稱，人員異動不必回來改設定。
 */
function ads_candidates(PDO $db, array $cfg, ?int $editorUid = null): array
{
    $mode = (string)$cfg['mode'];
    if ($mode === 'editor') {
        if (!$editorUid) return [];
        $p = eg_people_list($db, ['user_ids' => [$editorUid]]);
        return $p;
    }
    if ($mode === 'user') {
        $uid = (int)($cfg['user_id'] ?? 0);
        return $uid > 0 ? eg_people_list($db, ['user_ids' => [$uid]]) : [];
    }
    // dept_pos：該部門（含下轄）的在職者，再依職稱篩
    $deptId = (int)($cfg['pick_dept_id'] ?? 0);
    if ($deptId <= 0) return [];
    $ids = function_exists('eg_dept_subtree_ids') ? eg_dept_subtree_ids($db, $deptId) : [$deptId];
    if (!$ids) $ids = [$deptId];
    $people = eg_people_list($db, ['dept_ids' => $ids, 'all_posts' => true]);
    $pos = (int)($cfg['position_id'] ?? 0);
    if ($pos > 0) {
        $people = array_values(array_filter($people, function ($p) use ($pos) {
            return (int)($p['position_id'] ?? 0) === $pos;
        }));
    }
    return $people;
}

/** 這個版次的「內容最後修改人」＝制修訂那一關預設要顯示的人 */
function ads_editor_uid(PDO $db, int $versionId): int
{
    $c = adc_content_by_version($db, $versionId);
    return $c ? (int)$c['updated_by'] : 0;
}

/**
 * 依設定算出「這次送簽要跑哪幾關、每一關有哪些人可選」。
 * 畫面與送出時的驗證共用同一份，兩邊各算一次一定會走鐘。
 */
function ads_plan(PDO $db, int $versionId, int $fallbackUid = 0): array
{
    ads_ensure_schema($db);
    $v = adc_version_info($db, $versionId);
    if (!$v) return ['ok' => false, 'msg' => '找不到這個版次'];
    $deptId = (int)($v['department_id'] ?? 0);
    $cfg = ads_cfg_rows($db, $deptId);
    if (!$cfg) return ['ok' => false, 'msg' => '管理員還沒有設定這份文件的簽核關卡', 'need_cfg' => true];

    $editorUid = ads_editor_uid($db, $versionId);
    // 最後修改人可能查不到人（系統帳號 state=99、或已離職）——人員清單本來就不列這些人。
    // 這種情況退回「現在操作的這個人」，否則制修訂那一關會永遠找不到人可簽而送不出去。
    if ($editorUid > 0 && !eg_people_list($db, ['user_ids' => [$editorUid]])) $editorUid = 0;
    if ($editorUid <= 0 && $fallbackUid > 0 && eg_people_list($db, ['user_ids' => [$fallbackUid]])) {
        $editorUid = $fallbackUid;
    }
    $stages = ads_stages();
    $out = [];
    foreach ($cfg as $c) {
        $cand = ads_candidates($db, $c, $editorUid);
        $out[] = [
            'cfg_id'    => (int)$c['id'],
            'stage'     => $c['stage'],
            'stage_name' => $stages[$c['stage']] ?? $c['stage'],
            'seq'       => (int)$c['seq'],
            'mode'      => $c['mode'],
            'auto_sign' => (int)$c['auto_sign'],
            'fixed'     => in_array($c['mode'], ['editor', 'user'], true),
            'candidates' => array_map(function ($p) {
                return ['id' => (int)$p['id'], 'name' => (string)$p['user_cname'],
                        'dept' => (string)($p['dept_name'] ?? ''), 'pos' => (string)($p['position_name'] ?? '')];
            }, $cand),
        ];
    }
    return ['ok' => true, 'steps' => $out, 'scope_dept_id' => $cfg[0]['_scope'] ?? 0,
            'dept_id' => $deptId, 'editor_uid' => $editorUid];
}

/* ══════════════════════════════════════════════════════════════════════
   案件
   ══════════════════════════════════════════════════════════════════════ */

function ads_case(PDO $db, int $versionId): ?array
{
    ads_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM as_doc_sign_case WHERE version_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$versionId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function ads_steps(PDO $db, int $caseId): array
{
    $st = $db->prepare("SELECT * FROM as_doc_sign_step WHERE case_id=? ORDER BY seq, id");
    $st->execute([$caseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** 目前輪到的那一關（還在等人簽的第一關）；全簽完回 null */
function ads_current_step(array $steps): ?array
{
    foreach ($steps as $s) if ($s['status'] === 'wait') return $s;
    return null;
}

/**
 * 送審。$picks = [cfg_id => user_id]（建立者從候選名單挑的人）。
 * auto_sign 的關卡不必挑，系統自動取候選名單第一位（ai-rules/21：池空才退回最高核准人員）。
 */
function ads_submit(PDO $db, int $versionId, array $picks, int $uid, string $uname): array
{
    ads_ensure_schema($db);
    $cur = ads_case($db, $versionId);
    if ($cur && $cur['status'] === 'pending') return ['ok' => false, 'msg' => '這個版次已經在送簽中了，請先取消或等簽核完成'];
    if ($cur && $cur['status'] === 'approved') return ['ok' => false, 'msg' => '這個版次已經簽核完成'];

    $c = adc_content_by_version($db, $versionId);
    if (!$c || trim(strip_tags((string)$c['content_html'])) === '') {
        return ['ok' => false, 'msg' => '這個版次還沒有線上內容，沒有東西可以送簽'];
    }
    $plan = ads_plan($db, $versionId, $uid);
    if (empty($plan['ok'])) return ['ok' => false, 'msg' => $plan['msg'], 'need_cfg' => !empty($plan['need_cfg'])];

    // 先把每一關的簽核人決定好（含後端再驗一次前端挑的人真的在候選名單內＝鐵律8）
    $rows = [];
    foreach ($plan['steps'] as $s) {
        $cand = $s['candidates'];
        $pickId = (int)($picks[$s['cfg_id']] ?? 0);
        $chosen = null;
        if ($s['auto_sign'] || $s['fixed'] || $pickId <= 0) {
            $chosen = $cand[0] ?? null;               // 自動簽核／固定人選：取候選第一位
            if ($pickId > 0) {
                foreach ($cand as $p) if ($p['id'] === $pickId) { $chosen = $p; break; }
            }
        } else {
            foreach ($cand as $p) if ($p['id'] === $pickId) { $chosen = $p; break; }
            if (!$chosen) {
                return ['ok' => false, 'msg' => '「' . $s['stage_name'] . '」選到的人不在管理員設定的名單內（或他現在不在職）'];
            }
        }
        if (!$chosen) {
            return ['ok' => false, 'msg' => '「' . $s['stage_name'] . '」找不到可以簽的人：管理員設定的部門與職稱目前沒有在職人員'];
        }
        $rows[] = ['s' => $s, 'p' => $chosen];
    }

    $today = $db->query("SELECT CURDATE()")->fetchColumn();
    $allAuto = true;
    foreach ($rows as $r) if (empty($r['s']['auto_sign'])) { $allAuto = false; break; }

    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO as_doc_sign_case
            (version_id, status, is_auto, submit_date, submitted_by, submitted_by_name, submitted_at)
            VALUES (?, 'pending', ?, ?, ?, ?, NOW())")
           ->execute([$versionId, $allAuto ? 1 : 0, $today, $uid, $uname]);
        $caseId = (int)$db->lastInsertId();

        $ins = $db->prepare("INSERT INTO as_doc_sign_step
            (case_id, stage, seq, signer_user_id, signer_name, dept_name, position_name, status, is_auto)
            VALUES (?,?,?,?,?,?,?, 'wait', ?)");
        foreach ($rows as $r) {
            $ins->execute([$caseId, $r['s']['stage'], $r['s']['seq'], $r['p']['id'], $r['p']['name'],
                           $r['p']['dept'], $r['p']['pos'], $r['s']['auto_sign'] ? 1 : 0]);
        }
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '送簽失敗：' . $e->getMessage()];
    }

    // 自動簽核的關卡當場簽掉；剩下的通知第一位待簽者
    ads_run_auto($db, $caseId, $uid);
    ads_notify_next($db, $caseId, $uid);
    $case = ads_case($db, $versionId);
    return ['ok' => true, 'msg' => ($case && $case['status'] === 'approved')
        ? '已自動簽核完成' : '已送出簽核', 'case_id' => $caseId,
        'status' => $case['status'] ?? 'pending'];
}

/**
 * 把開頭連續的「自動簽核」關卡簽掉。
 * 時間戳依 ai-rules/21：業務日期＝送出日，時間接在前一關之後隨機錯開 5~30 分、不跨日，
 * 否則制修訂紀錄書上會印出「核准比制修訂還早」。
 */
function ads_run_auto(PDO $db, int $caseId, int $byUid): void
{
    $st = $db->prepare("SELECT * FROM as_doc_sign_case WHERE id=?");
    $st->execute([$caseId]);
    $case = $st->fetch(PDO::FETCH_ASSOC);
    if (!$case || $case['status'] !== 'pending') return;

    $date = (string)$case['submit_date'];
    $t = strtotime($date . ' ' . date('H:i:s', strtotime((string)$case['submitted_at'])));
    if (!$t) $t = strtotime($date . ' 09:00:00');

    foreach (ads_steps($db, $caseId) as $s) {
        if ($s['status'] !== 'wait') continue;
        if (empty($s['is_auto'])) break;             // 碰到要人簽的就停
        $t += random_int(5 * 60, 30 * 60);
        if (date('Y-m-d', $t) !== $date) $t = strtotime($date . ' 23:59:00');   // 不可跨日
        ads_mark($db, (int)$s['id'], 'ok', '系統自動簽核', $byUid, date('Y-m-d H:i:s', $t), $date, true);
    }
    ads_finish_if_done($db, $caseId);
}

/** 寫一筆簽核結果（同時寫 approval_record＝全站可追溯，ai-rules/23） */
function ads_mark(PDO $db, int $stepId, string $status, string $note, int $byUid,
                  ?string $at = null, ?string $signDate = null, bool $isAuto = false): void
{
    $st = $db->prepare("SELECT s.*, c.version_id FROM as_doc_sign_step s
                        JOIN as_doc_sign_case c ON c.id=s.case_id WHERE s.id=?");
    $st->execute([$stepId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $at = $at ?: $db->query("SELECT NOW()")->fetchColumn();
    $signDate = $signDate ?: substr((string)$at, 0, 10);

    // approval_record 記的是「實際按下的人」，不是「這一關掛誰的名字」——
    // 管理員代簽時兩者不同，記成掛名的那位等於留下一筆不實的簽核紀錄。
    $actUid = $byUid; $actName = '';
    try {
        $q = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $q->execute([$actUid]); $actName = (string)$q->fetchColumn();
    } catch (Throwable $e) {}
    if ($actName === '') $actName = (string)$row['signer_name'];

    $apId = 0;
    try {
        $apId = eg_approval_submit($db, 'as_doc_online', (int)$row['version_id'],
                                   (string)$row['stage'], $actUid, (string)$row['signer_name']);
        eg_approval_decide($db, $apId, $status === 'ok' ? 'approved' : 'rejected',
                           $actUid, $actName, $note);
    } catch (Throwable $e) { $apId = 0; }

    $db->prepare("UPDATE as_doc_sign_step
                  SET status=?, note=?, decided_at=?, sign_date=?, is_auto=?, approval_id=?
                  WHERE id=?")
       ->execute([$status, mb_substr($note, 0, 500), $at, $signDate, $isAuto ? 1 : 0, $apId ?: null, $stepId]);
}

/** 全部簽完就把案件結掉 */
function ads_finish_if_done(PDO $db, int $caseId): void
{
    $steps = ads_steps($db, $caseId);
    if (!$steps) return;
    foreach ($steps as $s) {
        if ($s['status'] === 'reject') {
            $db->prepare("UPDATE as_doc_sign_case SET status='rejected', finished_at=NOW() WHERE id=? AND status='pending'")
               ->execute([$caseId]);
            return;
        }
        if ($s['status'] !== 'ok') return;
    }
    $db->prepare("UPDATE as_doc_sign_case SET status='approved', finished_at=NOW() WHERE id=? AND status='pending'")
       ->execute([$caseId]);
}

/** 由簽核人做決定 */
function ads_decide(PDO $db, int $stepId, int $uid, bool $ok, string $note, bool $isAdmin = false): array
{
    ads_ensure_schema($db);
    $st = $db->prepare("SELECT s.*, c.status cstatus, c.id cid, c.version_id
                        FROM as_doc_sign_step s JOIN as_doc_sign_case c ON c.id=s.case_id WHERE s.id=?");
    $st->execute([$stepId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'msg' => '找不到這一關'];
    if ($row['cstatus'] !== 'pending') return ['ok' => false, 'msg' => '這件已經結束了，請重新整理頁面'];
    if ($row['status'] !== 'wait')     return ['ok' => false, 'msg' => '這一關已經簽過了，請重新整理頁面'];

    $steps = ads_steps($db, (int)$row['cid']);
    $cur = ads_current_step($steps);
    if (!$cur || (int)$cur['id'] !== $stepId) {
        return ['ok' => false, 'msg' => '還沒輪到這一關（要依管理員設定的順序簽）'];
    }
    $proxy = ((int)$row['signer_user_id'] !== $uid);
    if ($proxy && !$isAdmin) {
        return ['ok' => false, 'msg' => '這一關指定的簽核人不是你'];
    }
    if (!$ok && trim($note) === '') return ['ok' => false, 'msg' => '退回一定要填原因'];

    // 管理員代別人簽一定要留下痕跡：章上還是掛指定簽核人（那是設定好的簽核身分），
    // 但紀錄要看得出來實際是誰按的，否則就是一筆不實的簽核紀錄（ai-rules/18・23）
    if ($proxy) $note = '【管理員代簽】' . $note;
    ads_mark($db, $stepId, $ok ? 'ok' : 'reject', $note, $uid);
    if (!$ok) {
        $db->prepare("UPDATE as_doc_sign_case SET reject_note=? WHERE id=?")
           ->execute([mb_substr($note, 0, 500), (int)$row['cid']]);
    }
    ads_run_auto($db, (int)$row['cid'], $uid);
    ads_finish_if_done($db, (int)$row['cid']);
    ads_notify_next($db, (int)$row['cid'], $uid);

    $st2 = $db->prepare("SELECT status FROM as_doc_sign_case WHERE id=?");
    $st2->execute([(int)$row['cid']]);
    $sNow = (string)$st2->fetchColumn();
    return ['ok' => true, 'msg' => $ok ? ($sNow === 'approved' ? '已簽核，本件全部完成' : '已簽核，已通知下一位')
                                       : '已退回', 'status' => $sNow];
}

function ads_cancel(PDO $db, int $versionId, int $uid): array
{
    ads_ensure_schema($db);
    $case = ads_case($db, $versionId);
    if (!$case) return ['ok' => false, 'msg' => '這個版次沒有送簽紀錄'];
    if ($case['status'] !== 'pending') return ['ok' => false, 'msg' => '只有「簽核中」的案件可以取消'];
    $db->prepare("UPDATE as_doc_sign_case SET status='canceled', finished_at=NOW() WHERE id=?")
       ->execute([(int)$case['id']]);
    ads_close_notice($db, (int)$case['id']);
    return ['ok' => true, 'msg' => '已取消送簽'];
}

/* ══════════════════════════════════════════════════════════════════════
   通知
   ══════════════════════════════════════════════════════════════════════ */

function ads_notify_next(PDO $db, int $caseId, int $fromUid): void
{
    $st = $db->prepare("SELECT c.*, v.doc_id FROM as_doc_sign_case c
                        LEFT JOIN as_document_version v ON v.id=c.version_id WHERE c.id=?");
    $st->execute([$caseId]);
    $case = $st->fetch(PDO::FETCH_ASSOC);
    if (!$case) return;
    ads_close_notice($db, $caseId);
    if ($case['status'] !== 'pending') {
        ads_notify_result($db, $case, $fromUid);
        return;
    }
    $cur = ads_current_step(ads_steps($db, $caseId));
    if (!$cur || !$cur['signer_user_id']) return;

    $v = adc_version_info($db, (int)$case['version_id']);
    $stages = ads_stages();
    $title = 'AS 文件線上版待簽核（' . ($stages[$cur['stage']] ?? $cur['stage']) . '）：'
           . ($v['doc_no'] ?? '') . '　' . ($v['doc_name'] ?? '');
    $content = '版次：' . ($v['version'] ?? '') . "\n"
             . '送審人：' . ($case['submitted_by_name'] ?? '') . "\n"
             . '關卡：' . ($stages[$cur['stage']] ?? $cur['stage']) . "\n"
             . '點此開啟線上版內容，確認後按「簽核」或「退回」（退回要填原因）。';
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, 'AS文件線上版', 1, 'AS_DOC_SIGN', ?)")
           ->execute([$title, $content, $fromUid, (int)$cur['id']]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
           ->execute([$eid, (int)$cur['signer_user_id']]);
        $db->prepare("UPDATE as_doc_sign_step SET live_event_id=? WHERE id=?")->execute([$eid, (int)$cur['id']]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

function ads_notify_result(PDO $db, array $case, int $fromUid): void
{
    $to = (int)($case['submitted_by'] ?? 0);
    if (!$to) return;
    $v = adc_version_info($db, (int)$case['version_id']);
    $okTxt = $case['status'] === 'approved' ? '簽核完成' : ($case['status'] === 'rejected' ? '被退回' : '已結束');
    $title = 'AS 文件線上版' . $okTxt . '：' . ($v['doc_no'] ?? '') . '　' . ($v['doc_name'] ?? '');
    $body  = '版次：' . ($v['version'] ?? '') . "\n"
           . ($case['status'] === 'rejected' ? ('退回原因：' . (string)$case['reject_note'] . "\n") : '')
           . ($case['status'] === 'approved' ? "可以回到編輯器按「正式發行」把這一版設為正本。\n" : '');
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, 'AS文件線上版', 1, 'AS_DOC_SIGN_RESULT', ?)")
           ->execute([$title, $body, $fromUid, (int)$case['id']]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
           ->execute([$eid, $to]);
    } catch (Throwable $e) {}
}

function ads_close_notice(PDO $db, int $caseId): void
{
    try {
        $db->prepare("UPDATE live_event le
                      JOIN as_doc_sign_step s ON s.live_event_id = le.id
                      SET le.enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE s.case_id=? AND s.status<>'wait'
                        AND (le.enddate IS NULL OR le.enddate>=CURDATE())")
           ->execute([$caseId]);
    } catch (Throwable $e) {}
}

/* ══════════════════════════════════════════════════════════════════════
   正式發行
   ══════════════════════════════════════════════════════════════════════ */

/**
 * 有沒有「比這一版更新」的版次。
 * 使用者要求「正式發行前要自動檢查是否是較新版本」——舊版次的線上內容若能把
 * current_version_id 指回自己，等於把已經發行的新版整個蓋掉，而且畫面上看不出來。
 */
function ads_newer_versions(PDO $db, int $versionId): array
{
    $v = adc_version_info($db, $versionId);
    if (!$v) return [];
    $st = $db->prepare("SELECT id, version, revised_date FROM as_document_version
                        WHERE doc_id=? AND id<>?
                          AND (revised_date > ? OR (revised_date = ? AND id > ?))
                        ORDER BY revised_date DESC, id DESC");
    $st->execute([(int)$v['doc_id'], $versionId, $v['revised_date'], $v['revised_date'], $versionId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** 發行前的檢查結果（畫面與後端共用同一份判定） */
function ads_release_check(PDO $db, int $versionId): array
{
    ads_ensure_schema($db);
    $v = adc_version_info($db, $versionId);
    if (!$v) return ['ok' => false, 'msg' => '找不到這個版次'];
    $c = adc_content_by_version($db, $versionId);
    if (!$c) return ['ok' => false, 'msg' => '這個版次還沒有線上內容'];
    $case = ads_case($db, $versionId);
    if (!$case || $case['status'] !== 'approved') {
        return ['ok' => false, 'msg' => '要先完成送簽（或自動簽核）才能正式發行',
                'need_sign' => true];
    }
    $newer = ads_newer_versions($db, $versionId);
    if ($newer) {
        $t = [];
        foreach (array_slice($newer, 0, 3) as $n) $t[] = $n['version'] . '（' . $n['revised_date'] . '）';
        return ['ok' => false, 'newer' => $newer,
                'msg' => '這不是最新的版次，不可以發行：已經有更新的版次 ' . implode('、', $t)
                       . '。發行舊版會把目前生效的版次蓋掉。'];
    }
    return ['ok' => true, 'msg' => '可以發行', 'already' => (int)$c['is_primary'] === 1];
}

/**
 * 正式發行：把線上內容設為這個版次的正本，並同步 AS 文件管理的「目前版次」。
 * 使用者要求「其他可以自動代入的資料都要全部自動帶入，避免過多人工會失誤」。
 * 刻意**不去改** as_document_version 的版次／修訂日／制修訂頁次／摘要——
 * 那是 AS 文件管理那邊維護的欄位，這裡只負責「把哪一版設為生效」。
 */
function ads_release(PDO $db, int $versionId, int $uid, string $uname): array
{
    $chk = ads_release_check($db, $versionId);
    if (empty($chk['ok'])) return ['ok' => false, 'msg' => $chk['msg'], 'newer' => $chk['newer'] ?? null];

    $v = adc_version_info($db, $versionId);
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $c = adc_content_by_version($db, $versionId);
        // 同一份文件的其他版次一律退回草稿，正本永遠只有一個
        $db->prepare("UPDATE as_doc_content c
                      JOIN as_document_version v ON v.id=c.version_id
                      SET c.is_primary=0
                      WHERE v.doc_id=? AND c.version_id<>?")
           ->execute([(int)$v['doc_id'], $versionId]);
        $db->prepare("UPDATE as_doc_content SET is_primary=1, updated_at=NOW() WHERE id=?")
           ->execute([(int)$c['id']]);
        // AS 文件管理的「目前版次」跟著指過來（已經是這一版時等於不動）
        $db->prepare("UPDATE as_document SET current_version=?, current_version_id=?, updated_at=NOW() WHERE id=?")
           ->execute([(string)$v['version'], $versionId, (int)$v['doc_id']]);
        $db->prepare("UPDATE as_doc_sign_case SET released_at=NOW(), released_by=?, released_by_name=?
                      WHERE version_id=? AND status='approved'")
           ->execute([$uid, $uname, $versionId]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '發行失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'msg' => '已正式發行：' . $v['doc_no'] . ' 版次 ' . $v['version']
                                 . ' 的線上版已設為正本，AS 文件管理的目前版次也一起更新了'];
}

/* ══════════════════════════════════════════════════════════════════════
   制修訂紀錄書上的簽章
   ══════════════════════════════════════════════════════════════════════ */

/**
 * 每一關要蓋誰的章、日期是哪一天。
 * 回傳 ['draft'=>[['name'=>,'dept'=>,'pos'=>,'date'=>,'auto'=>], ...], 'review'=>…, 'approve'=>…]
 */
function ads_stamp_map(PDO $db, int $versionId): array
{
    ads_ensure_schema($db);
    $out = ['draft' => [], 'review' => [], 'approve' => []];
    $case = ads_case($db, $versionId);
    if (!$case) return $out;
    foreach (ads_steps($db, (int)$case['id']) as $s) {
        if ($s['status'] !== 'ok') continue;
        if (!isset($out[$s['stage']])) continue;
        $out[$s['stage']][] = [
            'name' => (string)$s['signer_name'],
            'dept' => (string)$s['dept_name'],
            'pos'  => (string)$s['position_name'],
            'date' => (string)$s['sign_date'],
            'auto' => (int)$s['is_auto'],
        ];
    }
    return $out;
}

/** 給畫面用的整包狀態 */
function ads_state(PDO $db, int $versionId): array
{
    ads_ensure_schema($db);
    $case = ads_case($db, $versionId);
    $steps = $case ? ads_steps($db, (int)$case['id']) : [];
    $cur = ads_current_step($steps);
    $stages = ads_stages();
    return [
        'case' => $case ? [
            'id' => (int)$case['id'], 'status' => $case['status'],
            'is_auto' => (int)$case['is_auto'],
            'submit_date' => $case['submit_date'],
            'submitted_by_name' => $case['submitted_by_name'],
            'released_at' => $case['released_at'],
            'released_by_name' => $case['released_by_name'],
            'reject_note' => $case['reject_note'],
        ] : null,
        'steps' => array_map(function ($s) use ($stages) {
            return ['id' => (int)$s['id'], 'stage' => $s['stage'],
                    'stage_name' => $stages[$s['stage']] ?? $s['stage'],
                    'seq' => (int)$s['seq'],
                    'signer_user_id' => (int)$s['signer_user_id'],
                    'signer_name' => $s['signer_name'],
                    'dept_name' => $s['dept_name'], 'position_name' => $s['position_name'],
                    'status' => $s['status'], 'is_auto' => (int)$s['is_auto'],
                    'sign_date' => $s['sign_date'], 'note' => $s['note']];
        }, $steps),
        'current_step_id' => $cur ? (int)$cur['id'] : 0,
        'release' => ads_release_check($db, $versionId),
    ];
}
