<?php
/**
 * 表格模板 — 簽核流程／維護人員／主檔搜尋（sheet_form_lib.php 的延伸，由它載入）— 2026-10-14
 *
 * 流程設定存在模板 flow_json：[{label, mode, dept_id?, user_id?, auto?}…]（最多 4 關，依序簽）。
 *   mode：dept＝指定部門任一主管簽（OR-gate）／user＝指定人員／supervisor＝送出者的單位主管（ai-rules/24）／top＝全站最高核准人員。
 *   auto＝1：不等真人，送出後系統立即自動簽核（ai-rules/21：時間隨機錯開 5~180 分、不跨天；簽核人歸屬走該關合格簽核池第一位，池空退回最高核准人員）。
 * 建立單據時把當下的流程複製進單據（tpl_flow_json），之後改模板不影響已送出的單據。
 * 簽核紀錄走全站共用 approval_record（module='sheet_form', entity_id=單據id, level='step1'..）；
 * 簽核人若是「代理人」另記在 sf_instance.sign_meta_json（approval_record 沒有代理欄位），列印時圖章右下角加「代」。
 * 一律強制 SoD：送出者本人不可簽自己送出的單據（池裡剔除；剔除後空了就退回最高核准人員，再不行才報錯）。
 */

require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/print_log_lib.php';        // eg_sign_note_public()
require_once __DIR__ . '/review_form_lib.php';      // 重用 rvf_dept_managers() 等，不另寫一份主管解析
require_once __DIR__ . '/unit_supervisor_lib.php';

const SF_FLOW_MODES = ['dept', 'user', 'supervisor', 'top'];
const SF_FLOW_MAX_STEPS = 4;

/* ============================================================ 流程設定 ============================================================ */

/** 驗證並正規化流程設定 JSON；空字串／空陣列＝不需簽核。不合法丟 InvalidArgumentException。 */
function sf_flow_normalize(PDO $db, string $json): array {
    $json = trim($json);
    if ($json === '') return [];
    $arr = json_decode($json, true);
    if (!is_array($arr)) throw new InvalidArgumentException('簽核流程設定格式不正確');
    if (count($arr) > SF_FLOW_MAX_STEPS) throw new InvalidArgumentException('簽核關卡最多 ' . SF_FLOW_MAX_STEPS . ' 關');
    $out = [];
    foreach (array_values($arr) as $i => $s) {
        $n = $i + 1;
        $label = mb_substr(trim((string)($s['label'] ?? '')), 0, 10);
        if ($label === '') throw new InvalidArgumentException("第 {$n} 關請輸入關卡名稱（如：審核、核准）");
        $mode = (string)($s['mode'] ?? '');
        if (!in_array($mode, SF_FLOW_MODES, true)) throw new InvalidArgumentException("第 {$n} 關的簽核人方式不正確");
        $step = ['label' => $label, 'mode' => $mode, 'auto' => !empty($s['auto']) ? 1 : 0];
        if ($mode === 'dept') {
            $d = (int)($s['dept_id'] ?? 0);
            $ok = $d > 0 && $db->query("SELECT COUNT(*) FROM department WHERE id=" . $d)->fetchColumn();
            if (!$ok) throw new InvalidArgumentException("第 {$n} 關請選擇簽核部門");
            $step['dept_id'] = $d;
            if (!empty($s['all'])) $step['all'] = 1;       // 會簽：該部門每一位主管都要簽
        } elseif ($mode === 'user') {
            $u = (int)($s['user_id'] ?? 0);
            $ok = $u > 0 && $db->query("SELECT COUNT(*) FROM `user` WHERE id=" . $u . " AND COALESCE(state,1) NOT IN (0,90)")->fetchColumn();
            if (!$ok) throw new InvalidArgumentException("第 {$n} 關請選擇在職的簽核人員");
            $step['user_id'] = $u;
        }
        $out[] = $step;
    }
    return $out;
}

function sf_flow_of_template(array $tpl): array {
    $f = json_decode((string)($tpl['flow_json'] ?? ''), true);
    return is_array($f) ? $f : [];
}
function sf_flow_of_instance(array $inst): array {
    $f = json_decode((string)($inst['tpl_flow_json'] ?? ''), true);
    return is_array($f) ? $f : [];
}

/** 這一關的關卡名稱（給畫面與通知用）：第 n 關（1 起算） */
function sf_step_label(array $flow, int $n): string { return (string)($flow[$n - 1]['label'] ?? ('第' . $n . '關')); }

/* ============================================================ 簽核人解析 ============================================================ */

function sf_user_row(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT id, user_cname FROM `user` WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 某一關的合格簽核池。回傳 [['id','user_cname','deleg_for'=>null|被代理人id], …]（已剔除送出者本人）。
 * 本人當天請假且設了代理人時，代理人會一併進池（標 deleg_for），本人仍在池裡（人回來了照樣簽得了）。
 */
function sf_step_pool(PDO $db, array $step, int $submitterUid, int $instId = 0): array {
    $base = [];
    switch ($step['mode'] ?? '') {
        case 'dept':  $base = rvf_dept_managers($db, (int)($step['dept_id'] ?? 0)); break;
        case 'user':  $u = sf_user_row($db, (int)($step['user_id'] ?? 0)); $base = $u ? [$u] : []; break;
        case 'supervisor':
            $sid = eg_unit_supervisor_id($db, $submitterUid, null, date('Y-m-d'));
            $u = $sid ? sf_user_row($db, (int)$sid) : null; $base = $u ? [$u] : []; break;
        case 'top': $base = rvf_approver_pool_top_approver($db); break;
    }
    $base = array_values(array_filter($base, fn($p) => (int)$p['id'] !== $submitterUid));
    if (!$base && ($step['mode'] ?? '') !== 'top') {                         // 池空（含送出者自己就是那位主管）→ 退回最高核准人員
        $base = array_values(array_filter(rvf_approver_pool_top_approver($db), fn($p) => (int)$p['id'] !== $submitterUid));
    }
    $pool = [];
    foreach ($base as $p) {
        $pool[(int)$p['id']] = ['id' => (int)$p['id'], 'user_cname' => $p['user_cname'], 'deleg_for' => null];
        try {
            $r = eg_resolve_signer($db, (int)$p['id'], ['applicant_id' => $submitterUid, 'flow_key' => 'sheet_form', 'doc_id' => $instId, 'log' => false]);
            $sid = (int)($r['signer_id'] ?? 0);
            if (!empty($r['is_delegated']) && $sid && $sid !== (int)$p['id'] && $sid !== $submitterUid && !isset($pool[$sid])) {
                $d = sf_user_row($db, $sid);
                if ($d) $pool[$sid] = ['id' => $sid, 'user_cname' => $d['user_cname'], 'deleg_for' => (int)$p['id']];
            }
        } catch (Throwable $e) {}
    }
    return array_values($pool);
}

/* ============================================================ 通知 ============================================================ */

/** 站內通知（live_event）＋ Web Push；ref_type：SHEET_FORM_APPROVAL(待簽,mode=sign)／SHEET_FORM_RESULT(結果,mode=read)。 */
function sf_notify(PDO $db, int $instId, array $toUids, string $title, string $content, int $fromUid, string $refType, string $mode = 'sign'): int {
    $toUids = array_values(array_unique(array_filter(array_map('intval', $toUids))));
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '表格模板', 1, ?, ?)")
           ->execute([$title, $content, $fromUid, $refType, $instId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
        foreach ($toUids as $t) $ins->execute([$eid, $t, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 該單據上還沒處理完的「待簽」通知一律收掉（enddate＝今天），避免鈴鐺累積舊的 */
function sf_notify_close(PDO $db, int $instId): void {
    try { $db->prepare("UPDATE live_event SET enddate=CURDATE() WHERE ref_type='SHEET_FORM_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate>CURDATE())")->execute([$instId]); } catch (Throwable $e) {}
}

/* ============================================================ 簽核紀錄輔助 ============================================================ */

function sf_meta_get(array $inst): array { $m = json_decode((string)($inst['sign_meta_json'] ?? ''), true); return is_array($m) ? $m : []; }
function sf_meta_set(PDO $db, int $instId, array $meta): void {
    $db->prepare("UPDATE sf_instance SET sign_meta_json=? WHERE id=?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $instId]);
}
function sf_level(int $n): string { return 'step' . $n; }

/** 自動簽核（ai-rules/21）：立刻建立一筆已核准紀錄，時間從 $after 起隨機 5~180 分、不跨送出日。回傳 [簽核人名, decided_at] */
function sf_auto_sign(PDO $db, int $instId, int $n, array $step, int $submitterUid, string $submitterName, string $submitDate, string $after, array &$meta): array {
    $pool = sf_step_pool($db, $step, $submitterUid, $instId);
    $signerId = $pool ? (int)$pool[0]['id'] : 0; $signerName = $pool ? $pool[0]['user_cname'] : '';
    if (!$signerName) { $top = eg_org_user($db, 'top_approver'); if ($top) { $signerId = (int)$top['id']; $signerName = $top['user_cname']; } }
    if (!$signerName) { $signerId = $submitterUid; $signerName = $submitterName; }
    $aid = eg_approval_submit($db, 'sheet_form', $instId, sf_level($n), $submitterUid, $submitterName);
    eg_approval_decide($db, $aid, $signerId, $signerName, 'approved', '（系統自動簽核）');
    $off = random_int(5, 180);
    $db->prepare("UPDATE approval_record SET decided_at = LEAST(DATE_ADD(?, INTERVAL ? MINUTE), CONCAT(?, ' 23:59:59')) WHERE id=?")->execute([$after, $off, $submitDate, $aid]);
    $meta['a' . $aid] = ['auto' => 1];
    return [$signerName, (string)$db->query("SELECT decided_at FROM approval_record WHERE id=" . (int)$aid)->fetchColumn()];
}

/* ============================================================ 送出／簽核 ============================================================ */

/** 送出前檢查：每個人工關卡都要解析得到簽核人（否則整張單卡住沒人處理）。回傳錯誤訊息或空字串。 */
function sf_flow_precheck(PDO $db, array $flow, int $submitterUid): string {
    foreach ($flow as $i => $s) {
        if (!empty($s['auto'])) continue;
        if (!sf_step_pool($db, $s, $submitterUid)) return '第 ' . ($i + 1) . ' 關「' . $s['label'] . '」找不到合格的簽核人，請聯絡管理員檢查簽核流程設定';
    }
    return '';
}

/** 從第 $from 關開始往下跑：自動關直接簽、遇到人工關建立待簽並通知後停下；全部完成則 done。 */
function sf_flow_run(PDO $db, int $instId, int $from, string $after): array {
    $inst = sf_instance_get($db, $instId);
    $flow = sf_flow_of_instance($inst);
    $meta = sf_meta_get($inst);
    $uid = (int)$inst['created_by']; $uname = (string)$inst['creator_name'];
    $submitDate = (string)($inst['submit_date'] ?: date('Y-m-d'));
    $title = ($inst['title'] !== '' ? $inst['title'] : '表格模板單據') . '（#' . $instId . '）';
    for ($n = $from; $n <= count($flow); $n++) {
        $step = $flow[$n - 1];
        if (!empty($step['auto'])) {
            [$sn, $at] = sf_auto_sign($db, $instId, $n, $step, $uid, $uname, $submitDate, $after, $meta);
            sf_notify($db, $instId, [$uid], '「' . $title . '」' . $step['label'] . '已由系統自動簽核',
                '「' . $title . '」' . $step['label'] . '已自動簽核完成（簽核時間：' . $at . '，簽核人：' . $sn . '，系統自動簽核，非本人親自處理）。', $uid, 'SHEET_FORM_RESULT', 'read');
            $after = $at;
            continue;
        }
        $pool = sf_step_pool($db, $step, $uid, $instId);
        if (!$pool) { sf_meta_set($db, $instId, $meta); return ['ok' => false, 'msg' => '第 ' . $n . ' 關「' . $step['label'] . '」找不到合格的簽核人']; }
        $aid = eg_approval_submit($db, 'sheet_form', $instId, sf_level($n), $uid, $uname);
        $db->prepare("UPDATE sf_instance SET status='pending', cur_step=? WHERE id=?")->execute([$n, $instId]);
        if (!empty($step['all'])) $meta['n' . $n] = ['need' => array_values(array_map(fn($p) => (int)$p['id'], array_filter($pool, fn($p) => $p['deleg_for'] === null))), 'done' => []];
        else unset($meta['n' . $n]);
        sf_meta_set($db, $instId, $meta);
        $eid = sf_notify($db, $instId, array_column($pool, 'id'), '「' . $title . '」待您' . $step['label'],
            $uname . ' 送出的「' . $title . '」（' . ($inst['template_name'] ?? '') . '）待您' . $step['label'] . '，請開啟查看完整內容後核准或退回。', $uid, 'SHEET_FORM_APPROVAL', 'sign');
        if ($eid) eg_approval_set_live_event($db, $aid, $eid);
        return ['ok' => true, 'status' => 'pending', 'step' => $n];
    }
    // 全部關卡完成
    $db->prepare("UPDATE sf_instance SET status='done', cur_step=?, modified_at=NOW() WHERE id=?")->execute([count($flow), $instId]);
    sf_meta_set($db, $instId, $meta);
    sf_notify_close($db, $instId);
    return ['ok' => true, 'status' => 'done'];
}

/** 送出一張單據進入簽核（呼叫端已驗證值並存好）。沒有流程設定的由呼叫端直接設 done。 */
function sf_flow_submit(PDO $db, array $inst, int $uid, string $uname): array {
    $flow = sf_flow_of_instance($inst);
    if (!$flow) return ['ok' => false, 'msg' => '此單據沒有簽核流程'];
    $err = sf_flow_precheck($db, $flow, $uid);
    if ($err) return ['ok' => false, 'msg' => $err];
    $meta = sf_meta_get($inst);
    $meta['round_from'] = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM approval_record WHERE module='sheet_form' AND entity_id=" . (int)$inst['id'])->fetchColumn();
    sf_meta_set($db, (int)$inst['id'], $meta);
    $now = (string)$db->query("SELECT NOW()")->fetchColumn();
    $db->prepare("UPDATE sf_instance SET submit_date=CURDATE(), submitted_at=NOW(), status='pending', cur_step=1 WHERE id=?")->execute([(int)$inst['id']]);
    sf_notify_close($db, (int)$inst['id']);
    return sf_flow_run($db, (int)$inst['id'], 1, $now);
}

/** 目前這一關的待簽紀錄（沒有回 null） */
function sf_flow_current_record(PDO $db, array $inst): ?array {
    if ($inst['status'] !== 'pending') return null;
    $st = $db->prepare("SELECT * FROM approval_record WHERE module='sheet_form' AND entity_id=? AND level=? AND status='pending' ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$inst['id'], sf_level((int)$inst['cur_step'])]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 這個人現在能不能簽這張單（回傳 [bool, 代理的被代理人id|null]） */
function sf_flow_can_decide(PDO $db, array $inst, int $uid): array {
    $rec = sf_flow_current_record($db, $inst);
    if (!$rec) return [false, null];
    $flow = sf_flow_of_instance($inst);
    $step = $flow[(int)$inst['cur_step'] - 1] ?? null;
    if (!$step) return [false, null];
    $done = [];
    if (!empty($step['all'])) { $m = sf_meta_get($inst); $done = array_map('intval', $m['n' . (int)$inst['cur_step']]['done'] ?? []); }
    foreach (sf_step_pool($db, $step, (int)$inst['created_by'], (int)$inst['id']) as $p) {
        if ((int)$p['id'] !== $uid) continue;
        $member = $p['deleg_for'] !== null ? (int)$p['deleg_for'] : $uid;
        if (!empty($step['all']) && in_array($member, $done, true)) return [false, null];
        return [true, $p['deleg_for']];
    }
    return [false, null];
}

function sf_flow_decide(PDO $db, int $instId, int $uid, string $uname, string $decision, string $note): array {
    $inst = sf_instance_get($db, $instId);
    if (!$inst) return ['ok' => false, 'msg' => '找不到此單據'];
    $rec = sf_flow_current_record($db, $inst);
    if (!$rec) return ['ok' => false, 'msg' => '目前沒有待簽核的關卡（可能已被其他人處理，請重新整理）'];
    [$can, $delegFor] = sf_flow_can_decide($db, $inst, $uid);
    if (!$can) return ['ok' => false, 'msg' => '您不是這一關的簽核人'];
    $note = trim($note);
    if ($decision === 'rejected' && $note === '') return ['ok' => false, 'msg' => '退回請填寫原因'];
    $flowCfg = sf_flow_of_instance($inst); $curN = (int)$inst['cur_step']; $stepCfg = $flowCfg[$curN - 1] ?? [];
    if (!empty($stepCfg['all']) && $decision !== 'rejected') {
        $m0 = sf_meta_get($inst); $key = 'n' . $curN;
        $need = array_map('intval', $m0[$key]['need'] ?? []); $done = array_map('intval', $m0[$key]['done'] ?? []);
        $member = $delegFor ? (int)$delegFor : $uid;
        if (!in_array($member, $done, true)) $done[] = $member;
        if (array_diff($need, $done)) {            // 還有人沒簽：記這一位的核准，關卡留著
            $aid2 = eg_approval_submit($db, 'sheet_form', $instId, sf_level($curN), (int)$inst['created_by'], (string)$inst['creator_name']);
            $r2 = eg_approval_decide($db, $aid2, $uid, $uname, 'approved', $note !== '' ? $note : null);
            if (!$r2['success']) return ['ok' => false, 'msg' => $r2['message']];
            if ($delegFor) { $o = sf_user_row($db, (int)$delegFor); $m0['a' . $aid2] = ['deleg' => $o ? $o['user_cname'] : '', 'deleg_id' => (int)$delegFor]; }
            $m0[$key]['done'] = $done; sf_meta_set($db, $instId, $m0);
            return ['ok' => true, 'status' => 'pending', 'partial' => true, 'left' => count(array_diff($need, $done))];
        }
    }
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision === 'rejected' ? 'rejected' : 'approved', $note !== '' ? $note : null);
    if (!$r['success']) return ['ok' => false, 'msg' => $r['message']];
    $meta = sf_meta_get($inst);
    if ($delegFor) {
        $o = sf_user_row($db, (int)$delegFor);
        $meta['a' . $rec['id']] = ['deleg' => $o ? $o['user_cname'] : '', 'deleg_id' => (int)$delegFor];
        sf_meta_set($db, $instId, $meta);
    }
    $flow = sf_flow_of_instance($inst); $n = (int)$inst['cur_step'];
    $title = ($inst['title'] !== '' ? $inst['title'] : '表格模板單據') . '（#' . $instId . '）';
    sf_notify_close($db, $instId);
    if ($decision === 'rejected') {
        $db->prepare("UPDATE sf_instance SET status='rejected' WHERE id=?")->execute([$instId]);
        sf_notify($db, $instId, [(int)$inst['created_by']], '「' . $title . '」被退回',
            $uname . ' 在「' . sf_step_label($flow, $n) . '」關退回了「' . $title . '」。退回原因：' . $note . '。請修改後重新送出。', $uid, 'SHEET_FORM_RESULT', 'read');
        return ['ok' => true, 'status' => 'rejected'];
    }
    $res = sf_flow_run($db, $instId, $n + 1, (string)$db->query("SELECT NOW()")->fetchColumn());
    if ($res['ok'] && $res['status'] === 'done')
        sf_notify($db, $instId, [(int)$inst['created_by']], '「' . $title . '」已簽核完成', '「' . $title . '」已全部簽核完成。', $uid, 'SHEET_FORM_RESULT', 'read');
    return $res;
}

/** 某人在某日當時的部門／職稱（圖章用；兼任取職級最高那筆），查不到回空字串 */
function sf_identity_asof(PDO $db, int $uid, string $date): array {
    if ($uid <= 0) return ['dept' => '', 'position' => ''];
    try { require_once __DIR__ . '/internal_audit_lib.php'; return ia_identity_asof($db, $uid, $date ?: null); }
    catch (Throwable $e) { return ['dept' => '', 'position' => '']; }
}

/**
 * 畫面／列印用的簽核進度：每一關目前狀態與簽核人（只看最近一輪送出的紀錄）。
 * 意見欄一律過 eg_sign_note_public()（自動簽核與補簽核字樣不可在畫面出現，ai-rules/23）。
 */
function sf_flow_steps_view(PDO $db, array $inst): array {
    $flow = sf_flow_of_instance($inst);
    if (!$flow) return [];
    $meta = sf_meta_get($inst);
    $from = (int)($meta['round_from'] ?? 0);
    $out = [];
    foreach ($flow as $i => $s) {
        $n = $i + 1;
        $st = $db->prepare("SELECT * FROM approval_record WHERE module='sheet_form' AND entity_id=? AND level=? AND id>? ORDER BY id");
        $st->execute([(int)$inst['id'], sf_level($n), $from]);
        $recs = $st->fetchAll(PDO::FETCH_ASSOC);
        $hasPending = false; $approved = []; $rejected = null;
        foreach ($recs as $r) {
            if ($r['status'] === 'pending') $hasPending = true;
            elseif ($r['status'] === 'approved') $approved[] = $r;
            else $rejected = $r;
        }
        $all = !empty($s['all']);
        $state = 'wait'; $note = '';
        if ($rejected && !$hasPending) { $state = 'rej'; $note = eg_sign_note_public((string)$rejected['note']); }
        elseif ($hasPending) $state = 'cur';
        elseif ($approved) $state = 'done';
        $signers = [];
        $list = $state === 'rej' ? [$rejected] : $approved;
        foreach ($list as $r) {
            $m = $meta['a' . $r['id']] ?? [];
            $idn = sf_identity_asof($db, (int)$r['approver_id'], substr((string)$r['decided_at'], 0, 10));
            $signers[] = ['by' => $r['approver_name'], 'by_id' => (int)$r['approver_id'], 'at' => $r['decided_at'], 'deleg' => (string)($m['deleg'] ?? ''),
                          'dept' => $idn['dept'] ?? '', 'position' => $idn['position'] ?? ''];
        }
        $first = $signers[0] ?? ['by' => '', 'by_id' => 0, 'at' => '', 'deleg' => '', 'dept' => '', 'position' => ''];
        $names = ''; $progress = '';
        if ($all && $state === 'cur') {
            $need = array_map('intval', $meta['n' . $n]['need'] ?? []); $done = array_map('intval', $meta['n' . $n]['done'] ?? []);
            $left = array_diff($need, $done);
            $names = implode('、', array_filter(array_map(function ($id) use ($db) { $u = sf_user_row($db, (int)$id); return $u ? $u['user_cname'] : ''; }, $left)));
            $progress = '已簽 ' . count($done) . '／' . count($need);
        } elseif ($state === 'cur' || ($state === 'wait' && $inst['status'] === 'pending')) {
            $names = implode('、', array_map(fn($p) => $p['user_cname'], sf_step_pool($db, $s, (int)$inst['created_by'], (int)$inst['id'])));
        }
        $out[] = ['no' => $n, 'label' => $s['label'], 'state' => $state, 'by' => $first['by'], 'by_id' => $first['by_id'], 'at' => $first['at'], 'note' => $note,
                  'deleg' => $first['deleg'], 'dept' => $first['dept'], 'position' => $first['position'], 'waiting_for' => $names,
                  'all' => $all ? 1 : 0, 'progress' => $progress, 'signers' => $signers];
    }
    return $out;
}

/**
 * 撤回（填表人或管理員）：只能在「簽核中且還沒有任何真人簽過」時撤回（自動簽核的不算）。
 * 待簽紀錄記為「填表人撤回」留在簽核歷程，單據回到草稿可修改後重送。
 */
function sf_flow_withdraw(PDO $db, int $instId, int $uid, string $uname, bool $isAdmin): array {
    $inst = sf_instance_get($db, $instId);
    if (!$inst) return ['ok' => false, 'msg' => '找不到此單據'];
    if ($inst['status'] !== 'pending') return ['ok' => false, 'msg' => '只有簽核中的單據可以撤回'];
    if ((int)$inst['created_by'] !== $uid && !$isAdmin) return ['ok' => false, 'msg' => '只有填表人本人（或管理員）可以撤回'];
    $meta = sf_meta_get($inst);
    $st = $db->prepare("SELECT id, status FROM approval_record WHERE module='sheet_form' AND entity_id=? AND id>?");
    $st->execute([$instId, (int)($meta['round_from'] ?? 0)]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['status'] === 'approved' && empty($meta['a' . $r['id']]['auto'])) return ['ok' => false, 'msg' => '已經有人簽核過，不能撤回；請洽簽核人退回'];
    }
    $db->prepare("UPDATE approval_record SET status='rejected', approver_id=?, approver_name=?, note='（填表人撤回）', decided_at=NOW()
                  WHERE module='sheet_form' AND entity_id=? AND id>? AND status='pending'")->execute([$uid, $uname, $instId, (int)($meta['round_from'] ?? 0)]);
    $db->prepare("UPDATE sf_instance SET status='draft', cur_step=0, modified_at=NOW() WHERE id=?")->execute([$instId]);
    sf_notify_close($db, $instId);
    return ['ok' => true, 'status' => 'draft'];
}

/* ============================================================ 維護人員（只能改「預設明細內容」） ============================================================ */

function sf_can_maintain(PDO $db, array $perms, array $tpl, int $uid): bool {
    if (!empty($perms['canAdmin'])) return true;
    $m = json_decode((string)($tpl['maintainers_json'] ?? ''), true);
    if (is_array($m) && in_array($uid, array_map('intval', $m), true)) return true;
    $d = (int)($tpl['maintain_dept_id'] ?? 0);
    return $d > 0 && in_array($uid, array_map(fn($p) => (int)$p['id'], rvf_dept_managers($db, $d)), true);
}

/** 目前使用者可維護的模板 id 清單（非管理員用；管理員回 null＝全部） */
function sf_maintainable_ids(PDO $db, array $perms, int $uid): ?array {
    if (!empty($perms['canAdmin'])) return null;
    $ids = [];
    foreach ($db->query("SELECT id, maintain_dept_id, maintainers_json FROM sf_template WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (sf_can_maintain($db, $perms, $t, $uid)) $ids[] = (int)$t['id'];
    }
    return $ids;
}

/* ============================================================ 主檔搜尋（料號／客戶） ============================================================ */

function sf_lookup(PDO $db, string $kind, string $q): array {
    $q = trim($q);
    if ($q === '') return [];
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    if ($kind === 'customer') {
        $st = $db->prepare("SELECT customer AS value, customer_full AS sub FROM customer_list
                            WHERE COALESCE(is_inactive,0)=0 AND (customer LIKE ? OR customer_full LIKE ? OR customer_id LIKE ?) ORDER BY customer LIMIT 15");
        $st->execute([$like, $like, $like]);
    } else {
        $st = $db->prepare("SELECT d.D_Setting_Id AS value, CONCAT_WS(' ', NULLIF(d.Drawing_No,''), c.customer) AS sub
                            FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                            WHERE d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ? OR d.Spec_No LIKE ? ORDER BY d.d_id DESC LIMIT 15");
        $st->execute([$like, $like, $like]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 值是否存在於主檔（送出時檢查用） */
function sf_lookup_exists(PDO $db, string $kind, string $v): bool {
    if ($kind === 'customer') $st = $db->prepare("SELECT 1 FROM customer_list WHERE customer=? OR customer_full=? LIMIT 1");
    else $st = $db->prepare("SELECT 1 FROM d_setting WHERE D_Setting_Id=? OR Drawing_No=? LIMIT 1");
    $st->execute([$v, $v]);
    return (bool)$st->fetchColumn();
}
