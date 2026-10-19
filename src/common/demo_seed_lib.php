<?php
/**
 * 「溝通管理／風險與機會管理／會議紀錄（內稽、管審）」三個模組的關聯假資料產生器 —— 2026-10-16 建立
 *
 * 原則（與 ollama_lib.php 一致）：
 *   ① 骨架全部由系統真實資料決定：客戶／廠商＝稽核製程（齒研、插齒）真的有出貨／委外的對象；
 *      負責人與部門＝組織主檔；日期、數字＝系統資料與規則引擎（rm_suggest_context／rm_rule_make_row／rm_rule_evidence）。
 *   ② Ollama 只寫「文字」（溝通內容、回覆、措施說明、會議要項），失敗或不合格時退回固定範本，所以離線也能產生。
 *   ③ 所有資料標「【假資料】」（會議紀錄標在主題），可一鍵清除；只有全站管理員能執行（AiDraft_API 擋）。
 *   ④ 該年度已有「真實」的處境分析表／風險表時拒絕產生（一個年度一張，不可再塞第二張）。
 *   ⑤ 成效確認：規則算出「無效」或「未確認」的列不寫入確認結果（留待人工），不產生要開矯正單的資料。
 */
require_once __DIR__ . '/risk_mgmt_rules_lib.php';
require_once __DIR__ . '/ollama_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/org_role_lib.php';

const EG_DEMO_TAG = '【假資料】';

function eg_demo_clamp(string $date, string $today): string { return $date > $today ? $today : $date; }
function eg_demo_q1(PDO $db, string $sql, array $a = []): array { $st = $db->prepare($sql); $st->execute($a); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
function eg_demo_qa(PDO $db, string $sql, array $a = []): array { $st = $db->prepare($sql); $st->execute($a); return $st->fetchAll(PDO::FETCH_ASSOC); }

/** 部門名稱 → id（同名取最小 id） */
function eg_demo_dept_id(PDO $db, string $name): int
{
    return (int)(eg_demo_q1($db, "SELECT id FROM department WHERE name=? ORDER BY id LIMIT 1", [$name])['id'] ?? 0);
}

/** 該部門的代表負責人：職位編號最小（職級最高）的在職者；生管組依使用者要求取副組長 */
function eg_demo_owner(PDO $db, string $dept): array
{
    $did = eg_demo_dept_id($db, $dept);
    if (!$did) return [];
    $rows = array_values(array_filter(eg_people_list($db, ['all_posts' => true, 'dept_ids' => [$did]]), function ($r) use ($did) { return (int)($r['dept_id'] ?? 0) === $did; }));
    if (!$rows) return [];
    usort($rows, function ($a, $b) { return ((int)($a['position_sort'] ?? 999)) <=> ((int)($b['position_sort'] ?? 999)); });
    $pick = $rows[0];
    if ($dept === '生管組') foreach ($rows as $r) if (($r['position_name'] ?? '') === '副組長') { $pick = $r; break; }
    return ['id' => (int)$pick['id'], 'name' => (string)$pick['user_cname'], 'dept_id' => $did, 'dept' => $dept, 'pos' => (string)($pick['position_name'] ?? '')];
}

/** 文字：開了 AI 且還有額度就問 Ollama，回傳不合格（太短太長、沒有必要的關鍵字）就退回範本 */
function eg_demo_text(PDO $db, array &$st, string $kind, array $ctx, string $fallback, string $needKw = ''): string
{
    if (empty($st['ai']) || $st['calls'] >= $st['cap']) return $fallback;
    try {
        $st['calls']++;
        $t = trim(preg_replace('/\s+/u', ' ', eg_ai_draft($db, $kind, $ctx)));
        if (mb_strlen($t) < 8 || mb_strlen($t) > 200) { $st['fallback']++; return $fallback; }
        if ($needKw !== '' && mb_strpos($t, $needKw) === false) { $st['fallback']++; return $fallback; }
        $st['ai_ok']++;
        return $t;
    } catch (Throwable $e) { $st['ai'] = false; $st['ai_err'] = $e->getMessage(); return $fallback; }
}

/** 會議要項用：保留換行、一行一項（每行 8～200 字），失敗回空陣列 */
function eg_demo_lines(PDO $db, array &$st, string $kind, array $ctx): array
{
    if (empty($st['ai']) || $st['calls'] >= $st['cap']) return [];
    try {
        $st['calls']++;
        $out = [];
        foreach (preg_split('/\R/u', (string)eg_ai_draft($db, $kind, $ctx)) as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', $l));
            if (mb_strlen($l) >= 8 && mb_strlen($l) <= 200 && preg_match('/[，。；]/u', $l)) $out[] = $l;   // 沒有標點的多半是單獨的標題行
        }
        if ($out) $st['ai_ok']++; else $st['fallback']++;
        return $out;
    } catch (Throwable $e) { $st['ai'] = false; $st['ai_err'] = $e->getMessage(); return []; }
}

/** 清除【假資料】（三個模組）；$year 有給就只清那個年度（依表單日期／溝通日期／會議日期），不給＝全部；回傳各表刪除筆數 */
function eg_demo_clear(PDO $db, ?int $year = null, bool $keepCtx = false): array
{
    $n = ['rm_doc' => 0, 'comm_record' => 0, 'comm_ctrl' => 0, 'meeting' => 0];
    $db->beginTransaction();
    try {
        $ids = array_column(eg_demo_qa($db, "SELECT doc_id FROM rm_doc WHERE remark LIKE ?" . ($year ? " AND YEAR(fill_date)=" . (int)$year : '') . ($keepCtx ? " AND kind<>'context'" : ''), [EG_DEMO_TAG . '%']), 'doc_id');
        if ($ids) { $in = implode(',', array_map('intval', $ids)); $db->exec("DELETE FROM rm_item WHERE doc_id IN ($in)"); $n['rm_doc'] = $db->exec("DELETE FROM rm_doc WHERE doc_id IN ($in)"); }
        $ids = array_column(eg_demo_qa($db, "SELECT rec_id FROM comm_record WHERE remark LIKE ?" . ($year ? " AND YEAR(comm_date)=" . (int)$year : ''), [EG_DEMO_TAG . '%']), 'rec_id');
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $db->exec("DELETE FROM comm_track WHERE src_rec_id IN ($in)"); $db->exec("DELETE FROM comm_record_item WHERE rec_id IN ($in)");
            $n['comm_record'] = $db->exec("DELETE FROM comm_record WHERE rec_id IN ($in)");
        }
        $n['comm_ctrl'] = $db->exec("DELETE FROM comm_ctrl WHERE remark LIKE " . $db->quote(EG_DEMO_TAG . ($year ? $year . ' 年' : '') . '%'));
        $ids = array_column(eg_demo_qa($db, "SELECT meeting_id FROM meeting_record WHERE subject LIKE ?" . ($year ? " AND YEAR(meeting_date)=" . (int)$year : ''), [EG_DEMO_TAG . '%']), 'meeting_id');
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $db->exec("DELETE FROM meeting_item WHERE meeting_id IN ($in)"); $db->exec("DELETE FROM meeting_attendee WHERE meeting_id IN ($in)");
            $n['meeting'] = $db->exec("DELETE FROM meeting_record WHERE meeting_id IN ($in)");
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $n;
}

/** 只清除「還沒確認」的草稿（is_draft=1）：風險表、溝通記錄（連同明細與追蹤）。已確認成正式資料的不動 */
function eg_draft_clear(PDO $db, int $year): array
{
    $n = ['rm_doc' => 0, 'comm_record' => 0];
    $db->beginTransaction();
    try {
        $ids = array_column(eg_demo_qa($db, "SELECT doc_id FROM rm_doc WHERE is_draft=1 AND YEAR(fill_date)=?", [$year]), 'doc_id');
        if ($ids) { $in = implode(',', array_map('intval', $ids)); $db->exec("DELETE FROM rm_item WHERE doc_id IN ($in)"); $n['rm_doc'] = $db->exec("DELETE FROM rm_doc WHERE doc_id IN ($in)"); }
        $ids = array_column(eg_demo_qa($db, "SELECT rec_id FROM comm_record WHERE is_draft=1 AND YEAR(comm_date)=?", [$year]), 'rec_id');
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $db->exec("DELETE FROM comm_track WHERE src_rec_id IN ($in)"); $db->exec("DELETE FROM comm_record_item WHERE rec_id IN ($in)");
            $n['comm_record'] = $db->exec("DELETE FROM comm_record WHERE rec_id IN ($in)");
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $n;
}

/** 草稿狀態：有沒有已產生的草稿、各有幾筆（畫面按鈕旁顯示用） */
function eg_draft_status(PDO $db, int $year): array
{
    $r = eg_demo_q1($db, "SELECT COUNT(*) n, MAX(draft_at) at FROM rm_doc WHERE is_draft=1 AND is_deleted=0 AND YEAR(fill_date)=?", [$year]);
    $c = eg_demo_q1($db, "SELECT COUNT(*) n FROM comm_record WHERE is_draft=1 AND is_deleted=0 AND YEAR(comm_date)=?", [$year]);
    $t = eg_demo_q1($db, "SELECT COUNT(*) n FROM comm_track WHERE is_draft=1 AND is_deleted=0 AND YEAR(react_date)=?", [$year]);
    $ri = 0; $d = eg_demo_q1($db, "SELECT doc_id FROM rm_doc WHERE is_draft=1 AND is_deleted=0 AND YEAR(fill_date)=? LIMIT 1", [$year]);
    if ($d) $ri = (int)eg_demo_q1($db, "SELECT COUNT(*) n FROM rm_item WHERE doc_id=?", [(int)$d['doc_id']])['n'];
    $at = $r['at'] ?? null;
    if (!$at && (int)$c['n'] > 0) $at = eg_demo_q1($db, "SELECT MAX(created_at) a FROM comm_record WHERE is_draft=1 AND YEAR(comm_date)=?", [$year])['a'] ?? null;
    $rc = (int)eg_demo_q1($db, "SELECT COUNT(*) n FROM rm_doc WHERE kind='risk' AND is_draft=0 AND is_deleted=0 AND YEAR(fill_date)=?", [$year])['n'];
    return ['risk_confirmed' => $rc, 'risk_doc' => (int)$r['n'], 'risk_items' => $ri, 'records' => (int)$c['n'], 'tracks' => (int)$t['n'], 'at' => $at, 'has' => ((int)$r['n'] + (int)$c['n']) > 0];
}

/** 溝通記錄＋追蹤項目（比照人工「建立→轉追蹤」的欄位；追蹤編號走 cm_track_no_next） */
function eg_demo_record(PDO $db, array $cats, array $o): array
{
    $date = $o['date']; $maker = $o['maker'];
    $ids = cm_identities($db, (int)$maker['id'], $date); $mk = null;
    foreach ($ids as $i) if ((int)$i['department_id'] === (int)$maker['dept_id']) $mk = $i;
    if (!$mk) foreach ($ids as $i) if ($i['is_main']) $mk = $i;
    $mk = $mk ?: ($ids[0] ?? ['department_id' => 0, 'department_name' => '', 'position_id' => 0, 'position_name' => '', 'pos_sort' => 0]);
    $cat = $cats[$o['cat']];
    $kind = $cat['kind'] === 'government' ? 'other' : $cat['kind'];
    $no = cm_next_rec_no($db, $date);
    $mp = cm_dept_manager_pool($db, (int)$maker['id'], (int)$mk['department_id'], (int)$mk['pos_sort'], $date);
    $mgr = $mp['pool'][0] ?? null; $gm = cm_gm_pool($db, $date)[0] ?? null;
    $ts = function ($h, $m = 0) use ($date) { return $date . ' ' . sprintf('%02d:%02d:00', $h, $m); };
    $draft = !empty($GLOBALS['EG_DRAFT_MODE']); $du = $GLOBALS['EG_DRAFT_USER'] ?? ['id' => 1, 'name' => '超級管理員'];
    if ($draft) {
        // 草稿：不簽核、不蓋任何章（簽核走正常流程），remark 留空；is_draft=1 讓畫面標示「草稿・待確認」、列印不顯示
        $db->prepare("INSERT INTO comm_record (rec_no, comm_type, comm_date, party_kind, party_cat_id, party_kind_other, party_name, party_ref_id, ch_phone, ch_face, ch_email, ch_meeting, ch_other, ch_other_text,
                maker_id, maker_name, maker_dept_id, maker_dept_name, maker_pos_id, maker_pos_name, maker_pos_sort, status, is_draft, created_by, created_by_name, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'',?,?,?,?,?,?,?,'draft',1,?,?,NOW(),NOW())")
           ->execute([$no, $o['type'] ?? 'irregular', $date, $kind, (int)$cat['cat_id'], $kind === 'other' ? ($o['kind_other'] ?? $o['cat']) : '', $o['party'], $o['ref'] ?? null,
                      (int)in_array('phone', $o['ch']), (int)in_array('face', $o['ch']), (int)in_array('email', $o['ch']), (int)in_array('meeting', $o['ch']),
                      $maker['id'], $maker['name'], $mk['department_id'], $mk['department_name'], $mk['position_id'], $mk['position_name'], $mk['pos_sort'], $du['id'], $du['name']]);
    } else
    $db->prepare("INSERT INTO comm_record (rec_no, comm_type, comm_date, party_kind, party_cat_id, party_kind_other, party_name, party_ref_id, party_user_id,
            party_contact_id, party_contact_name, ch_phone, ch_face, ch_email, ch_meeting, ch_other, ch_other_text,
            maker_id, maker_name, maker_dept_id, maker_dept_name, maker_pos_id, maker_pos_name, maker_pos_sort,
            status, submit_date, submitted_at, mgr_skip, mgr_user_id, mgr_name, mgr_dept_name, mgr_pos_name, mgr_date, mgr_at,
            gm_user_id, gm_name, gm_date, gm_at, remark, created_by, created_by_name, created_at, updated_at)
          VALUES (?,?,?,?,?,?,?,?,NULL,NULL,NULL,?,?,?,?,0,'',?,?,?,?,?,?,?,'closed',?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,'超級管理員',?,?)")
       ->execute([$no, $o['type'] ?? 'irregular', $date, $kind, (int)$cat['cat_id'], $kind === 'other' ? ($o['kind_other'] ?? $o['cat']) : '', $o['party'], $o['ref'] ?? null,
                  (int)in_array('phone', $o['ch']), (int)in_array('face', $o['ch']), (int)in_array('email', $o['ch']), (int)in_array('meeting', $o['ch']),
                  $maker['id'], $maker['name'], $mk['department_id'], $mk['department_name'], $mk['position_id'], $mk['position_name'], $mk['pos_sort'],
                  $date, $ts(9, 10), $mgr ? 0 : 1, $mgr['id'] ?? null, $mgr['name'] ?? null, $mgr['dept_name'] ?? null, $mgr['pos_name'] ?? null, $mgr ? $date : null, $mgr ? $ts(10, 20) : null,
                  $gm['id'] ?? null, $gm['name'] ?? null, $gm ? $date : null, $gm ? $ts(11, 5) : null, EG_DEMO_TAG . '關聯假資料（可一鍵清除）', $ts(9, 0), $ts(11, 5)]);
    $rid = (int)$db->lastInsertId(); $tracks = [];
    foreach ($o['items'] as $i => $it) {
        $db->prepare("INSERT INTO comm_record_item (rec_id, seq, question, reply) VALUES (?,?,?,?)")->execute([$rid, $i + 1, $it['q'], $it['a']]);
        $iid = (int)$db->lastInsertId();
        if (!empty($it['track'])) {
            $t = $it['track']; $ow = $t['owner'];
            $db->prepare("INSERT INTO comm_track (track_no, party, cat_id, content, react_date, action, owner_id, owner_name, owner_dept_id, owner_dept_name, due_date, is_closed, closed_date, close_note,
                            src_rec_id, src_item_id, is_draft, created_by, created_by_name, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([cm_track_no_next($db, $date), $o['party'], (int)$cat['cat_id'], $it['q'], $date, $t['action'], $ow['id'], $ow['name'], $ow['dept_id'], $ow['dept'], $t['due'],
                          (!$draft && !empty($t['closed'])) ? 1 : 0, $draft ? null : ($t['closed'] ?? null), $draft ? null : ($t['note'] ?? null), $rid, $iid, $draft ? 1 : 0,
                          $draft ? $du['id'] : 1, $draft ? $du['name'] : '超級管理員', $ts(11, 30), $ts(11, 30)]);
            $tid = (int)$db->lastInsertId();
            $db->prepare("UPDATE comm_record_item SET track_id=? WHERE item_id=?")->execute([$tid, $iid]);
            $tracks[] = $tid;
        }
    }
    return ['rec_id' => $rid, 'rec_no' => $no, 'tracks' => $tracks];
}

/** 處境分析表／風險表的表頭（沿用人工建立的欄位；單號依表單日期配號） */
function eg_demo_doc(PDO $db, string $kind, string $date, array $deptIds, array $deptNames): int
{
    if (!empty($GLOBALS['EG_DRAFT_MODE'])) {       // 草稿：不標【假資料】，用 is_draft 標示
        $du = $GLOBALS['EG_DRAFT_USER'] ?? ['id' => 1, 'name' => '超級管理員'];
        $db->prepare("INSERT INTO rm_doc (doc_no, kind, dept_ids, dept_names, fill_date, remark, is_draft, draft_at, created_by, created_by_name, created_at, updated_at) VALUES (?,?,?,?,?,NULL,1,NOW(),?,?,NOW(),NOW())")
           ->execute([rm_next_doc_no($db, $date), $kind, json_encode($deptIds), implode('、', $deptNames) ?: null, $date, $du['id'], $du['name']]);
        return (int)$db->lastInsertId();
    }
    $db->prepare("INSERT INTO rm_doc (doc_no, kind, dept_ids, dept_names, fill_date, remark, created_by, created_by_name, created_at, updated_at) VALUES (?,?,?,?,?,?,1,'超級管理員',?,?)")
       ->execute([rm_next_doc_no($db, $date), $kind, json_encode($deptIds), implode('、', $deptNames) ?: null, $date, EG_DEMO_TAG . '關聯假資料（可一鍵清除）', $date . ' 10:00:00', $date . ' 10:00:00']);
    return (int)$db->lastInsertId();
}

/* ============ 日期：由使用者一次設定，並檢查前後關係合理 ============
 * 會影響的是 SWOT（處境分析表）、風險與機會管制表、溝通管理、內部稽核事前／結束會議、管理審查會議，
 * 所以不再寫死月日，改成「一組關鍵日期」，其餘日期（溝通記錄、措施追蹤的預計／結案日）都依這組日期推算。
 * 溝通記錄期間不用設定：就是「風險管制表填表日 ～ 成效確認日」（都在當年度內，措施要先建立、成效確認前要有執行紀錄）。 */

/** 日期欄位（key => 名稱、是否可留白＝留白就不建立該筆會議、建議說明）。只有一份定義，前端畫面也由 API 取用 */
function eg_demo_date_fields(): array
{
    return [
        'ctx'    => ['label' => '組織處境分析表 填表日', 'opt' => false, 'tip' => ''],
        'risk'   => ['label' => '風險與機會因應措施管制表 填表日', 'opt' => false, 'tip' => ''],
        'effect' => ['label' => '成效確認日', 'opt' => false,
                     'tip' => '建議：排在管理審查會議之前（管理審查要看風險與機會的成效確認結果），預設為管理審查前一個工作日；此日之前措施要已有執行紀錄。溝通記錄與措施追蹤的日期，會自動落在「風險管制表填表日～成效確認日」之間。'],
        'mr'     => ['label' => '管理審查會議', 'opt' => true, 'tip' => ''],
    ];
}

/** 更新頻率備註裡提到的月份（使用者定調：「年底」＝12 月、「年初」＝1 月；也認「3 月」「三月」這種寫法）。唯一解析點 */
function eg_demo_note_months(string $note): array
{
    $m = [];
    if (mb_strpos($note, '年底') !== false) $m[12] = 12;
    if (mb_strpos($note, '年初') !== false) $m[1] = 1;
    if (preg_match_all('/([0-9]{1,2})\s*月/u', $note, $x)) foreach ($x[1] as $v) { $v = (int)$v; if ($v >= 1 && $v <= 12) $m[$v] = $v; }
    $cn = ['一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10, '十一' => 11, '十二' => 12];
    if (preg_match_all('/(十二|十一|十|一|二|三|四|五|六|七|八|九)月/u', $note, $x)) foreach ($x[1] as $v) if (isset($cn[$v])) $m[$cn[$v]] = $cn[$v];
    ksort($m);
    return array_values($m);
}

/**
 * 每個日期對應的 AS 文件（編號、名稱、更新頻率、負責課室），由 AS 文件管理的設定即時取得，不寫死。
 * 對應方式：優先用「AS 文件綁定」的模組代碼（處境表、風險表、內稽的計劃表／通知單／報告表）；管理審查審核表沒有綁定模組，改用名稱關鍵字找。
 */
function eg_demo_date_docs(PDO $db): array
{
    if (!function_exists('eg_asdoc_get')) require_once __DIR__ . '/asdoc_lib.php';
    $map = [
        'ctx'    => ['mods' => ['rm_context']],
        'risk'   => ['mods' => ['rm_risk']],
        'effect' => ['mods' => ['rm_risk']],
        'mr'     => ['mods' => [], 'name' => '管理審查審核表'],
    ];
    $units = asFreqUnits(); $out = [];
    foreach ($map as $k => $m) {
        $docs = [];
        foreach ($m['mods'] as $mod) { $d = eg_asdoc_get($db, $mod); if ($d && !empty($d['id'])) $docs[(int)$d['id']] = (int)$d['id']; }
        if (!empty($m['name'])) foreach (eg_demo_qa($db, "SELECT id FROM as_document WHERE doc_name LIKE ? ORDER BY id LIMIT 1", ['%' . $m['name'] . '%']) as $r) $docs[(int)$r['id']] = (int)$r['id'];
        $list = [];
        foreach ($docs as $id) {
            $r = eg_demo_q1($db, "SELECT id, doc_no, doc_name, freq_type, freq_n, freq_months, freq_note FROM as_document WHERE id=?", [$id]);
            if (!$r) continue;
            $own = array_column(eg_demo_qa($db, "SELECT d.name FROM as_doc_owner_dept o JOIN department d ON d.id=o.department_id WHERE o.doc_id=? ORDER BY d.id", [$id]), 'name');
            $months = asFreqParseMonths($r['freq_months'] ?? '');
            $noteMonths = eg_demo_note_months((string)($r['freq_note'] ?? ''));
            $t = (string)($r['freq_type'] ?? '');
            if ($t === '') $ft = '未設定更新頻率';
            elseif ($t === 'irregular') $ft = '不定時';
            else { $n = max(1, (int)$r['freq_n']); $ft = '每' . ($n > 1 ? $n : '') . ($units[$t] ?? $t) . ($months ? '（指定 ' . implode('、', $months) . ' 月）' : ''); }
            $list[] = ['no' => (string)$r['doc_no'], 'name' => (string)$r['doc_name'], 'freq' => $ft, 'note' => trim((string)($r['freq_note'] ?? '')),
                       'owners' => $own, 'months' => array_values(array_unique(array_merge($months, $noteMonths))), 'note_months' => array_values(array_diff($noteMonths, $months)), 'type' => $t];
        }
        $out[$k] = $list;
    }
    return $out;
}

/** 某個日期欄位被 AS 文件管理「指定月份」限制時，回傳允許的月份（沒有指定月份＝空陣列＝不限）。唯一解析點，預設值／檢查／備註共用 */
function eg_demo_months_for(array $docs, string $k): array
{
    // 成效確認日只是借用風險管制表的備註當參考，那份文件的月份是講「制訂表單」的時間，不限制成效確認
    if ($k === 'effect') return [];
    $m = [];
    foreach ($docs[$k] ?? [] as $d) foreach ($d['months'] as $x) $m[(int)$x] = (int)$x;
    ksort($m);
    return array_values($m);
}

/** 依 AS 文件管理的設定產生備註：更新頻率／負責課室＋必須落在哪幾個月（前端畫面顯示；實際阻擋由 eg_demo_dates_check 做） */
function eg_demo_date_hints(PDO $db, array $dates): array
{
    $docs = eg_demo_date_docs($db); $H = [];
    foreach ($docs as $k => $list) {
        $lines = [];
        foreach ($list as $d) {
            $lines[] = $d['no'] . ' ' . $d['name'] . '：' . $d['freq'] . ($d['note'] !== '' ? '，' . mb_substr($d['note'], 0, 40) . (mb_strlen($d['note']) > 40 ? '…' : '') : '')
                     . ($d['owners'] ? '；負責課室 ' . implode('、', $d['owners']) : '')
                     . ($d['note_months'] ? '　【備註提到的時間視為 ' . implode('、', $d['note_months']) . ' 月（年底＝12 月、年初＝1 月）】' : '');
        }
        $ms = eg_demo_months_for($docs, $k);
        if ($ms) $lines[] = '必須落在 ' . implode('、', $ms) . ' 月（依 AS 文件管理設定，月份不符不能產生）';
        if ($k === 'mr' && $ms) $lines[] = '管理審查指定月份時，審查的是前一年度的稽核與成效，所以不要求晚於本年度的成效確認日與稽核結束會議';
        $H[$k] = ['lines' => $lines, 'warn' => []];
    }
    return $H;
}

function eg_demo_wd(PDO $db, string $date, int $dir): string
{
    if (!function_exists('eg_leave_is_workday')) require_once __DIR__ . '/leave_lib.php';
    for ($i = 0; $i < 20; $i++) {
        if (eg_leave_is_workday($db, $date)) return $date;
        $date = date('Y-m-d', strtotime($date . ($dir < 0 ? ' -1 day' : ' +1 day')));
    }
    return $date;
}

/** 預設值：有 AS 指定月份的就落在該月份，其餘貼近一般作業順序；不超過今天，排不下的會議留白（不建立） */
function eg_demo_default_dates(PDO $db, int $Y): array
{
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $cap = function (string $d) use ($today) { return $d > $today ? $today : $d; };
    $docs = eg_demo_date_docs($db);
    $D = [];
    $D['ctx']  = eg_demo_wd($db, $cap($Y . '-01-12'), -1);
    $D['risk'] = eg_demo_wd($db, $cap($Y . '-01-15'), -1);
    if ($D['risk'] < $D['ctx']) $D['risk'] = $D['ctx'];
    $D['effect'] = eg_demo_wd($db, $cap($Y . '-10-09'), -1);
    if ($D['effect'] < $D['risk']) $D['effect'] = $D['risk'];
    // 管理審查：AS 有指定月份就取該月；沒指定才排在成效確認日之後、稽核結束之後
    $mm = eg_demo_months_for($docs, 'mr');
    if ($mm) $D['mr'] = eg_demo_wd($db, sprintf('%04d-%02d-05', $Y, $mm[0]), 1);
    else {
        $D['mr'] = eg_demo_wd($db, date('Y-m-d', strtotime($D['effect'] . ' +1 day')), 1);
    }
    if ($D['mr'] > $today) $D['mr'] = '';
    return $D;
}

/**
 * 檢查日期是否合理。回傳 ['errors' => [欄位 => 原因], 'dates' => 正規化後的日期]。
 * 規則（前後端共用這一份，畫面只是顯示原因）：
 *   ①都在該年度內、不可晚於今天（未來日期的文件與會議不合理）②表單與會議日必須是工作日，且月份符合 AS 文件管理的指定月份
 *   ③處境分析表 ≤ 風險管制表 ≤ 成效確認日 ④稽核事前會議在風險表之後、事前 < 結束 ⑤管理審查在成效確認與稽核結束會議之後
 */
function eg_demo_dates_check(PDO $db, int $Y, array $in): array
{
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $F = eg_demo_date_fields(); $E = []; $D = [];
    foreach ($F as $k => $f) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v === '') { if (!$f['opt']) $E[$k] = '必填'; $D[$k] = ''; continue; }
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $v) || !strtotime($v)) { $E[$k] = '日期格式不正確'; $D[$k] = ''; continue; }
        $D[$k] = $v;
        if ((int)substr($v, 0, 4) !== $Y) { $E[$k] = '必須在 ' . $Y . ' 年內（清除功能依年度，跨年的資料會清不掉）'; continue; }
        if ($v > $today) { $E[$k] = '不可晚於今天（' . $today . '）'; continue; }
        if (!eg_leave_is_workday_safe($db, $v)) $E[$k] = '不是工作日（假日或週末），請改選工作日';
    }
    $le = function (string $a, string $b, string $msgKey, string $msg, bool $strict = false) use (&$E, &$D) {
        if ($D[$a] === '' || $D[$b] === '' || isset($E[$msgKey])) return;
        if ($strict ? $D[$a] >= $D[$b] : $D[$a] > $D[$b]) $E[$msgKey] = $msg;
    };
    // AS 文件管理有「指定月份」的文件，日期的月份必須在指定月份內（使用者要求：非正確月份要阻擋產生）
    $docs = eg_demo_date_docs($db);
    foreach ($F as $k => $f) {
        $ms = eg_demo_months_for($docs, $k);
        if (!$ms || ($D[$k] ?? '') === '' || isset($E[$k])) continue;
        if (!in_array((int)substr($D[$k], 5, 2), $ms, true)) {
            $names = []; foreach ($docs[$k] as $d) if ($d['months']) $names[] = '「' . $d['name'] . '」';
            $E[$k] = 'AS 文件管理設定' . implode('、', $names) . '是每年 ' . implode('、', $ms) . ' 月，請改成該月份的日期' . (!empty($f['opt']) ? '（該月份還沒到的話，請留白＝不建立）' : '');
        }
    }
    $mrByAs = (bool)eg_demo_months_for($docs, 'mr');
    $le('ctx', 'risk', 'risk', '不可早於處境分析表填表日（' . $D['ctx'] . '），風險表是依處境分析表產生的');
    $le('risk', 'effect', 'effect', '不可早於風險管制表填表日（' . $D['risk'] . '），措施建立之後才談得到成效');
    if (!$mrByAs) {          // AS 指定了管理審查的月份＝審查的是前一年度，不和本年度的成效確認、稽核結束比先後
        $le('effect', 'mr', 'mr', '管理審查要看成效確認結果，不可早於成效確認日（' . $D['effect'] . '）');
    }
    return ['errors' => $E, 'dates' => $D];
}

function eg_leave_is_workday_safe(PDO $db, string $d): bool
{
    if (!function_exists('eg_leave_is_workday')) require_once __DIR__ . '/leave_lib.php';
    return eg_leave_is_workday($db, $d);
}

/**
 * 產生一整套關聯假資料。
 * @param array $opt year（預設今年）、dates（關鍵日期，見 eg_demo_date_fields；沒給就用預設值）、ai（是否讓 Ollama 寫文字）、cap（最多呼叫 Ollama 幾次，預設 24）
 * @return array 報告：各模組筆數、Ollama 呼叫與退回次數、警告
 */
function eg_demo_seed(PDO $db, array $opt = []): array
{
    @set_time_limit(900);
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $Y = (int)($opt['year'] ?? substr($today, 0, 4));
    $st = ['ai' => !empty($opt['ai']), 'calls' => 0, 'cap' => (int)($opt['cap'] ?? 24), 'fallback' => 0, 'ai_ok' => 0, 'ai_err' => ''];
    $warn = [];
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    rm_ensure_schema($db); cm_ensure_schema($db);
    $dchk = eg_demo_dates_check($db, $Y, isset($opt['dates']) && is_array($opt['dates']) ? $opt['dates'] : eg_demo_default_dates($db, $Y));
    if ($dchk['errors']) {
        $m = []; foreach ($dchk['errors'] as $k => $msg) $m[] = (eg_demo_date_fields()[$k]['label'] ?? $k) . '：' . $msg;
        throw new RuntimeException('日期不合理，沒有產生任何資料：' . implode('；', $m));
    }
    $DT = $dchk['dates'];

    // 該年度已有「真實」表單就不產生
    $draft = !empty($opt['draft']);                 // 草稿模式：使用者自己的 SWOT 為準，產生「待確認」的風險表／溝通記錄／措施追蹤
    $GLOBALS['EG_DRAFT_MODE'] = $draft; $GLOBALS['EG_DRAFT_USER'] = $opt['user'] ?? ['id' => 1, 'name' => '超級管理員'];
    if ($draft) $opt['from_context'] = true;
    $real = $draft ? 0 : (int)eg_demo_q1($db, "SELECT COUNT(*) n FROM rm_doc WHERE is_deleted=0 AND YEAR(fill_date)=? AND (remark IS NULL OR remark NOT LIKE ?)", [$Y, EG_DEMO_TAG . '%'])['n'];
    if ($real > 0) throw new RuntimeException($Y . ' 年已經有實際建立的處境分析表／風險表，一個年度只能有一張；請改選沒有表單的年度，或先刪除該年度的表單。');
    $fromCtx = !empty($opt['from_context']) || !empty($opt['draft']);       // 沿用現有的處境分析表（使用者改過的 SWOT），只重建風險表、溝通管理與會議
    $clear = $draft ? ['demo' => eg_demo_clear($db, $Y, true), 'draft' => eg_draft_clear($db, $Y)] : eg_demo_clear($db, $Y, $fromCtx);   // 草稿取代同年度的示範假資料（處境表保留）與舊草稿

    $in = rm_audit_in($db, 'd_setting_id');
    $set = rm_settings($db);
    $cats = []; foreach (eg_demo_qa($db, "SELECT cat_id, name, kind FROM comm_track_cat") as $c) $cats[$c['name']] = $c;
    foreach (['客戶', '供應商', '員工', '主管機關'] as $need) if (empty($cats[$need])) throw new RuntimeException('溝通管理缺少類別「' . $need . '」，請先到模組設定建立');

    /* ---------- 1. 稽核製程的真實對象 ---------- */
    $dataYear = $Y - 1;
    $top = eg_demo_qa($db, "SELECT Client_name, SUM(Qty*Unit_price) a FROM is_list WHERE $in AND Client_name<>'' AND YEAR(Order_date)=? GROUP BY Client_name ORDER BY a DESC LIMIT 3", [$Y]);
    if (count($top) < 3) $top = eg_demo_qa($db, "SELECT Client_name, SUM(Qty*Unit_price) a FROM is_list WHERE $in AND Client_name<>'' AND YEAR(Order_date)=? GROUP BY Client_name ORDER BY a DESC LIMIT 3", [$dataYear]);
    if (count($top) < 2) throw new RuntimeException('稽核製程在 ' . $Y . ' 與 ' . $dataYear . ' 年的出貨客戶不足，無法產生關聯資料');
    $cust = function (string $short) use ($db) {
        $c = eg_demo_q1($db, "SELECT customer_id, customer, customer_full FROM customer_list WHERE customer=? ORDER BY customer_id LIMIT 1", [$short]);
        return ['name' => $short, 'id' => $c['customer_id'] ?? null];
    };
    $c1 = $cust($top[0]['Client_name']); $c2 = $cust($top[1]['Client_name']);
    // 委外廠商：只取「對外宣稱委外的稽核製程」（模組設定），且廠商主檔「加工項目」綁定到該製程大類／小類者；製程名稱取自被宣稱的製程
    $outNos = rm_outsource_proc_nos($db);
    $vend = [];
    if ($outNos) {
        $vend = eg_demo_q1($db, "SELECT b.maker_id_no mid, ml.maker_id_all mall, ml.maker_id mshort, COUNT(*) n FROM bom_ing b JOIN maker_list ml ON ml.maker_id_no=b.maker_id_no AND COALESCE(ml.internal,0)=0
                                  WHERE b.maker_id_no<>'' AND b.process_no IN (" . implode(',', array_map('intval', array_keys($outNos))) . ") AND b.maker_id_no IN (" . rm_bound_makers_sql($db, array_keys($outNos)) . ") AND b.outsource_date>=?
                                  GROUP BY b.maker_id_no ORDER BY n DESC LIMIT 1", [$dataYear . '-01-01']);
    }
    $procLabel = $outNos ? (string)reset($outNos) : '加工';
    $newC = eg_demo_qa($db, "SELECT Client_name, MIN(Order_date) fd, SUM(Qty*Unit_price) a FROM is_list WHERE $in AND Client_name<>'' GROUP BY Client_name HAVING MIN(Order_date) BETWEEN ? AND ? ORDER BY a DESC LIMIT 6", [$Y . '-01-01', min($today, $Y . '-12-31')]);

    $own = [];
    foreach (['業務課', '生管組', '生產課', '品管組', '品管課', '技術課', '管理課', '倉管組', '採購組'] as $dn) $own[$dn] = eg_demo_owner($db, $dn);
    foreach (['業務課', '生管組', '生產課', '品管組', '管理課', '倉管組', '技術課', '品管課'] as $must) if (!$own[$must]) throw new RuntimeException('找不到部門「' . $must . '」的在職人員，無法指定負責人');
    // 溝通記錄與措施追蹤的日期：原本寫死的月日（名義日期）依「風險管制表填表日～成效確認日」等比例換算並挪到工作日，順序（記錄日→結案日→預計完成日）不變
    $cf = $DT['risk']; $ct = $DT['effect'];
    $cSpan = max(0, (int)round((strtotime($ct) - strtotime($cf)) / 86400)); $y0 = strtotime($Y . '-01-01'); $y1 = strtotime($Y . '-12-31');
    $d = function ($m, $day) use ($Y, $db, $cf, $ct, $cSpan, $y0, $y1) {
        $nom = strtotime(sprintf('%04d-%02d-%02d', $Y, $m, $day));
        $x = date('Y-m-d', strtotime($cf . ' +' . (int)round(($nom - $y0) / ($y1 - $y0) * $cSpan) . ' day'));
        if ($x > $ct) $x = $ct;
        $w = eg_demo_wd($db, $x, -1); if ($w < $cf) $w = eg_demo_wd($db, $x, 1); return $w > $ct ? $ct : $w;
    };
    $eoy = $Y . '-12-31';

    /* ---------- 2. 溝通管理：記錄＋追蹤（關鍵字對應風險表各類別，所以風險列自動連到這些追蹤） ---------- */
    // 沿用現有處境分析表時：只產生「SWOT 的劣勢／機會／威脅真的有提到的類別」的溝通記錄（沒提到的類別不憑空產生）
    $catSet = null; $ctxDocId = 0; $ctxLoaded = []; $gateCat = null;
    if ($fromCtx) {
        $cd = eg_demo_q1($db, "SELECT doc_id FROM rm_doc WHERE kind='context' AND is_deleted=0 AND YEAR(fill_date)=? ORDER BY doc_id LIMIT 1", [$Y]);
        if (!$cd) throw new RuntimeException($Y . ' 年沒有組織處境分析表，無法沿用');
        $ctxDocId = (int)$cd['doc_id']; $catSet = [];
        foreach (eg_demo_qa($db, "SELECT item_id, dimension, stakeholder, needs, issue FROM rm_item WHERE doc_id=? ORDER BY seq, item_id", [$ctxDocId]) as $ci) {
            $ctxLoaded[] = ['id' => (int)$ci['item_id'], 'dimension' => (string)$ci['dimension'], 'stakeholder' => (string)$ci['stakeholder'], 'needs' => (string)$ci['needs'], 'issue' => (string)$ci['issue']];
            if (in_array($ci['dimension'], ['W', 'O', 'T'], true)) $catSet[rm_rule_classify(trim($ci['stakeholder'] . ' ' . $ci['needs'] . ' ' . $ci['issue']), (string)$ci['dimension'])[0]] = true;
        }
    }
    $R = []; $rec = function (array $o) use ($db, $cats, &$R, &$gateCat, &$catSet) {
        if ($catSet !== null && $gateCat !== null && !isset($catSet[$gateCat])) return null;
        $r = eg_demo_record($db, $cats, $o); $R[] = $r; return $r; };
    $mk = $own['業務課'];
    $gateCat = 'delivery';
    // 交期
    $q1 = '客戶希望關鍵品項的交期縮短，並要求說明目前排程與製程負荷。';
    $a1 = eg_demo_text($db, $st, 'comm_reply', ['party' => $c1['name'], 'party_kind' => '客戶', 'question' => $q1], '生管組盤點稽核製程（插齒、齒研）負荷後回覆可行交期，並由業務課回覆客戶。');
    $q2 = '客戶希望每週了解訂單的出貨進度與交期狀況。';
    $a2 = eg_demo_text($db, $st, 'comm_reply', ['party' => $c1['name'], 'party_kind' => '客戶', 'question' => $q2], '業務課每週回報客戶出貨進度與交期狀況。');
    $rec(['date' => $d(3, 24), 'type' => 'regular', 'cat' => '客戶', 'party' => $c1['name'], 'ref' => $c1['id'], 'ch' => ['face'], 'maker' => $mk, 'items' => [
        ['q' => $q1, 'a' => $a1, 'track' => ['owner' => $own['生管組'], 'due' => $d(4, 7), 'closed' => $d(4, 3), 'action' => '生管組盤點稽核製程（插齒、齒研）負荷並回覆交期', 'note' => '已盤點完成並回覆客戶。']],
        ['q' => $q2, 'a' => $a2, 'track' => ['owner' => $own['業務課'], 'due' => $eoy, 'action' => eg_demo_text($db, $st, 'comm_track_action', ['party' => $c1['name'], 'content' => $q2, 'reply' => $a2], '業務課每週回報客戶準時出貨進度與交期狀況', '交期')]],
    ]]);
    $gateCat = 'concentration';
    // 客戶集中度
    $q = '客戶希望每季提供一次訂單預測，雙方才能提早備料與預留產能。';
    $a = eg_demo_text($db, $st, 'comm_reply', ['party' => $c2['name'], 'party_kind' => '客戶', 'question' => $q], '同意每季溝通一次訂單預測，由業務課負責並提供生管組參考。');
    $rec(['date' => $d(1, 20), 'type' => 'regular', 'cat' => '客戶', 'party' => $c2['name'], 'ref' => $c2['id'], 'ch' => ['face'], 'maker' => $mk, 'items' => [
        ['q' => $q, 'a' => $a, 'track' => ['owner' => $own['業務課'], 'due' => $eoy, 'action' => '每季與前三大客戶溝通訂單預測與產能預留，並提供生管組參考；持續開拓客源以分散風險']],
    ]]);
    $gateCat = 'quality';
    // 品質／客訴
    $q = '近期客訴集中在加工尺寸與外觀，客戶要求提出矯正與預防措施。';
    $a = eg_demo_text($db, $st, 'comm_reply', ['party' => $c1['name'], 'party_kind' => '客戶', 'question' => $q], '品管組逐件開立異常矯正處理單，並回報客戶處理結果。');
    $rec(['date' => $d(8, 12), 'type' => 'irregular', 'cat' => '客戶', 'party' => $c1['name'], 'ref' => $c1['id'], 'ch' => ['phone'], 'maker' => $mk, 'items' => [
        ['q' => $q, 'a' => $a, 'track' => ['owner' => $own['品管組'], 'due' => $eoy, 'action' => '逐件開立異常矯正處理單；重複發生的原因列管追蹤；每月回報客戶處理結果']],
    ]]);
    $gateCat = 'supplier';
    // 委外
    if ($vend) {
        $q = '部分訂單因委外' . $procLabel . '交期延後，需要加強交期與進度跟催。';
        $a = eg_demo_text($db, $st, 'comm_reply', ['party' => $vend['mshort'] ?: $vend['mall'], 'party_kind' => '供應商', 'question' => $q], '生管組加強跟催主要委外廠商的交期與進度，每週回報生管組長。');
        $rec(['date' => $d(8, 20), 'type' => 'irregular', 'cat' => '供應商', 'party' => $vend['mshort'] ?: $vend['mall'], 'ref' => $vend['mid'], 'ch' => ['phone'], 'maker' => $own['生管組'], 'items' => [
            ['q' => $q, 'a' => $a, 'track' => ['owner' => $own['生管組'], 'due' => $eoy, 'action' => '加強跟催主要委外廠商（' . ($vend['mshort'] ?: '委外廠商') . '等）的交期與進度，每週回報生管組長']],
        ]]);
    } elseif ($catSet === null || isset($catSet['supplier'])) $warn[] = '沒有「加工項目」綁定到對外宣稱委外製程的廠商（或模組設定沒有勾選對外宣稱委外的稽核製程），略過供應商溝通';
    $gateCat = 'climate';
    // 氣候
    $rec(['date' => $d(4, 28), 'type' => 'regular', 'cat' => '員工', 'party' => '業務課、倉管組、生產課　防颱防汛作業確認', 'ch' => ['meeting'], 'maker' => $own['管理課'], 'items' => [
        ['q' => '颱風警報發布當日，在途品項的預計交期要通知客戶。', 'a' => '業務課於警報發布當日通知客戶在途品項預計交期。',
         'track' => ['owner' => $own['業務課'], 'due' => $d(6, 30), 'closed' => $d(6, 18), 'action' => '訂定颱風警報當日通知客戶在途品項預計交期的作法，並通知相關客戶窗口', 'note' => '已完成並通知客戶窗口。']],
        ['q' => '汛期前庫房屋頂、排水與門口防水需要檢查。', 'a' => '倉管組於汛期前檢查庫房防水。',
         'track' => ['owner' => $own['倉管組'], 'due' => $d(5, 31), 'closed' => $d(5, 22), 'action' => '汛期前檢查庫房屋頂、排水與門口防水並改善', 'note' => '已完成檢查，缺失已改善。']],
        ['q' => '汛期前廠區排水與防水需要檢查。', 'a' => '生產課於汛期前檢查廠區防水。',
         'track' => ['owner' => $own['生產課'], 'due' => $d(5, 31), 'closed' => $d(5, 27), 'action' => '汛期前檢查廠區排水溝與防水並改善', 'note' => '已完成檢查，缺失已改善。']],
        ['q' => '夏季高溫或電力吃緊（限電警示）時，現場加工與排程要如何因應。', 'a' => '生管組於高溫或限電警示時與生產課調整排程。',
         'track' => ['owner' => $own['生管組'], 'due' => $d(7, 31), 'closed' => $d(7, 18), 'action' => '高溫或限電警示時，生管組與生產課調整生產排程並通知業務課', 'note' => '已訂定作法並通知相關人員。']],
    ]]);
    $gateCat = 'geopolitics';
    // 國際局勢：原物料與外購件價格、交期（客戶要價格條件、採購要供應商報價）
    $q = '國際局勢（關稅、能源、匯率）使客戶成本壓力變大，詢問加工單價是否能調降，並希望確認報價的有效期限。';
    $a = eg_demo_text($db, $st, 'comm_reply', ['party' => $c1['name'], 'party_kind' => '客戶', 'question' => $q], '業務課與客戶確認訂單預測與加工單價條件，營運成本明顯上升時再與客戶協議調整。');
    $rec(['date' => $d(5, 12), 'type' => 'irregular', 'cat' => '客戶', 'party' => $c1['name'], 'ref' => $c1['id'], 'ch' => ['phone'], 'maker' => $mk, 'items' => [
        ['q' => $q, 'a' => $a, 'track' => ['owner' => $own['業務課'], 'due' => $eoy, 'action' => '與主要客戶確認訂單預測與加工單價條件；營運成本明顯上升時與客戶協議調整加工單價']],
    ]]);
    $q = '能源與耗材價格上漲，刀具與砂輪的報價與交貨時間需要提早確認，避免加工停滯。';
    $a = '採購組每月向刀具、砂輪供應商確認報價與交貨時間，必要時提前採購。';
    $rec(['date' => $d(5, 20), 'type' => 'regular', 'cat' => '供應商', 'party' => '刀具、砂輪等加工工具供應商', 'ch' => ['phone'], 'maker' => $own['採購組'] ?: $own['生管組'], 'items' => [
        ['q' => $q, 'a' => $a, 'track' => ['owner' => $own['採購組'] ?: $own['生管組'], 'due' => $eoy, 'action' => '每月留意電力與刀具砂輪等營運成本的變動，刀具砂輪報價與交貨時間需提早確認，異常上升時回報總經理室']],
    ]]);
    $gateCat = 'environment';
    // 環境議題：減碳與環保法規、客戶的環境要求
    $rec(['date' => $d(8, 25), 'type' => 'regular', 'cat' => '主管機關', 'kind_other' => '環保局', 'party' => '環保局　環保與減碳法規諮詢窗口', 'ch' => ['phone'], 'maker' => $own['管理課'], 'items' => [
        ['q' => '環保與減碳相關法規近期的異動，以及本公司適用的申報與管制要求。', 'a' => '管理課盤點適用的法規與客戶環境要求，指定負責部門並列入溝通管制表。',
         'track' => ['owner' => $own['管理課'], 'due' => $d(9, 30), 'closed' => $d(9, 18), 'action' => '盤點適用的環保與減碳法規及客戶環境要求，指定負責部門並列入溝通管制表', 'note' => '已完成盤點並指定負責部門。']],
    ]]);
    $gateCat = 'legal';
    // 法規（政府單位）
    foreach ([['環保局', '環保局　事業廢棄物申報窗口', '廢切削油與廢棄刀具的清運及網路申報方式、期限與聯單保存要求。', '依規定完成本期事業廢棄物申報，聯單歸檔', $d(5, 14), $d(6, 30), $d(6, 20)],
              ['消防局', '消防局　消防安全設備檢修申報窗口', '消防安全設備定期檢修申報的時程與應備文件。', '委託合格檢修業者辦理檢修並申報', $d(4, 22), $d(5, 29), $d(5, 22)],
              ['人事行政機關', '人事行政機關　勞工健康與職安衛申報窗口', '年度勞工健康檢查與職安衛教育訓練的申報及紀錄保存要求。', '完成健康檢查與職安衛教育訓練申報並留存紀錄', $d(3, 3), $d(4, 15), $d(4, 8)]] as $g) {
        $rec(['date' => $g[4], 'type' => 'regular', 'cat' => '主管機關', 'kind_other' => $g[0], 'party' => $g[1], 'ch' => ['phone'], 'maker' => $own['管理課'], 'items' => [
            ['q' => $g[2], 'a' => '依規定於期限內完成申報，並保存紀錄備查。', 'track' => ['owner' => $own['管理課'], 'due' => $g[5], 'closed' => $g[6], 'action' => $g[3], 'note' => '已完成並歸檔。']]]]);
    }
    $gateCat = 'inquiry';
    // 接單評估（五個部門各一筆）
    $ordItems = [];
    foreach ([['技術課', '技術課評估製程可行性與治具刀具需求', '技術課於接單時評估製程可行性。'], ['生產課', '生產課評估現場產能與加工條件', '生產課於接單時評估現場產能與加工條件。'],
              ['生管組', '生管組評估交期與製程負荷並回覆業務課', '生管組於接單時評估交期與製程負荷。'], ['品管課', '品管課評估品質要求與檢驗能力', '品管課於接單時評估品質要求與檢驗能力。']] as $x)
        $ordItems[] = ['q' => '接到訂單時，' . mb_substr($x[1], 0, mb_strpos($x[1], '評估')) . '要先評估。', 'a' => $x[2],
                       'track' => ['owner' => $own[$x[0]], 'due' => $d(3, 31), 'closed' => $d(3, 20), 'action' => '接單評估：' . $x[1], 'note' => '已訂定評估作法。']];
    $ordItems[] = ['q' => '各部門評估結果要由業務課彙整，並於客戶要求的時效內回覆客戶交期。', 'a' => '業務課彙整各部門評估，並於客戶要求的時效內回覆客戶交期。',
                   'track' => ['owner' => $own['業務課'], 'due' => $eoy, 'action' => '接單評估：業務課彙整各部門評估，並於客戶要求的時效內回覆客戶交期']];
    $rec(['date' => $d(2, 10), 'type' => 'regular', 'cat' => '員工', 'party' => '技術課、生產課、生管組、品管課、業務課　接單評估作業確認', 'ch' => ['meeting'], 'maker' => $mk, 'items' => $ordItems]);
    $gateCat = 'new_customer';
    // 新客戶：首次出貨當下或 30 天內溝通
    $ncQ = [['首次出貨後的使用情形，以及產品尺寸、外觀是否符合需求。', '業務課於首次出貨後 30 天內向客戶確認使用情形（尺寸、外觀是否符合需求）並留存溝通記錄；客戶的實際回覆待溝通後補記。'], ['後續是否還有相同或類似料號的加工需求，預計的數量與時程。', '業務課向客戶詢問後續加工需求與預計時程；客戶的實際回覆待溝通後補記。'], ['交期與包裝方式是否需要調整。', '業務課向客戶確認交期與包裝方式是否需要調整；客戶的實際回覆待溝通後補記。']];
    foreach ($newC as $i => $nc) {
        $cc = $cust($nc['Client_name']);
        $dt = eg_demo_clamp(date('Y-m-d', strtotime($nc['fd'] . ' +' . ($i % 3 === 0 ? 0 : 3 + $i * 4) . ' days')), $today);
        if ($dt < $cf || $dt > $ct) { $warn[] = '新客戶「' . $nc['Client_name'] . '」首次出貨 ' . $nc['fd'] . '，溝通日期 ' . $dt . ' 不在「風險管制表填表日～成效確認日」之內，已跳過'; continue; }
        $rec(['date' => $dt, 'type' => 'irregular', 'cat' => '客戶', 'party' => $cc['name'], 'ref' => $cc['id'], 'ch' => [$i % 2 ? 'phone' : 'face'], 'maker' => $mk, 'items' => [['q' => $ncQ[$i % 3][0], 'a' => $ncQ[$i % 3][1]]]]);
    }
    $gateCat = null;
    // SWOT 提到、但上面沒有固定情境的類別（業績、量測…）：依該類別的因應措施各建一筆措施追蹤，負責部門取措施開頭的部門名
    if ($fromCtx) {
        $covered = ['delivery', 'concentration', 'quality', 'supplier', 'climate', 'geopolitics', 'environment', 'legal', 'inquiry', 'new_customer'];
        $gi = 0;
        foreach (array_keys($catSet) as $ck) {
            if (in_array($ck, $covered, true) || empty(RM_RULE_CATS[$ck]['measures'])) continue;
            $cc = RM_RULE_CATS[$ck]; $its = []; $gi++;
            foreach ($cc['measures'] as $mt) {
                $ow = null; foreach ($own as $dn => $o) if ($o && mb_strpos($mt, $dn) === 0) { $ow = $o; break; }
                $its[] = ['q' => '「' . $cc['label'] . '」議題：針對「' . $mt . '」，需確認執行方式、負責單位與完成期限。', 'a' => '由' . (($ow ?: $own['管理課'])['dept'] ?? '管理課') . '負責，預計 ' . $eoy . ' 前完成，並以措施追蹤追蹤執行情形。', 'track' => ['owner' => $ow ?: $own['管理課'], 'due' => $eoy, 'action' => $mt]];
            }
            $rec(['date' => $d(6, 8 + $gi * 3), 'type' => 'regular', 'cat' => '員工', 'party' => $cc['label'] . '　議題追蹤', 'ch' => ['meeting'], 'maker' => $own['管理課'], 'items' => $its]);
        }
    }
    // 溝通管制表（常態機制）：稽核製程出貨前三大客戶
    foreach ((!$draft && ($catSet === null || isset($catSet['concentration']))) ? $top : [] as $i => $tc) {
        $cc = $cust($tc['Client_name']);
        $mkr = $own['業務課']; $mkIds = cm_identities($db, (int)$mkr['id'], $today); $mi = $mkIds[0] ?? ['department_id' => 0, 'department_name' => '', 'position_name' => ''];
        $db->prepare("INSERT INTO comm_ctrl (maker_id, maker_name, maker_dept_id, maker_dept_name, maker_pos_name, party_kind, party_kind_other, party, party_ref_id, party_user_id,
                content, channel, freq, freq_n, freq_unit, freq_times, next_due_date, remind_enabled, remark, sort_order, created_by, created_by_name, created_at, updated_at)
              VALUES (?,?,?,?,?,'customer','',?,?,NULL,?,?,?,?,?,?,?,0,?,?,1,'超級管理員',NOW(),NOW())")
           ->execute([$mkr['id'], $mkr['name'], $mi['department_id'], $mi['department_name'], $mi['position_name'], $cc['name'], $cc['id'], '訂單預測、交期與產能預留', '面談、電話',
                      cm_freq_text(3, 'month', 1), 3, 'month', 1, $d(12, 15) > $today ? $d(12, 15) : date('Y-m-d', strtotime($today . ' +3 months')),
                      EG_DEMO_TAG . $Y . ' 年稽核製程出貨前三大客戶之一', ($i + 1) * 10]);
    }

    /* ---------- 3. 組織處境分析表（依前一年資料）＋風險與機會因應措施管制表 ---------- */
    $ctx = [];
    if ($fromCtx) { $ctx = $ctxLoaded; $ctxId = $ctxDocId; $ctxIds = $ctxLoaded; $seq = 0; }
    else {
    foreach (rm_suggest_context($db, $dataYear) as $r) {
        if (mb_strpos((string)$r['needs'], '標準作業書') !== false || mb_strpos((string)$r['issue'], '新進') !== false) continue;     // 新進人員／訓練類議題不產生
        $ctx[] = ['dimension' => $r['key'], 'stakeholder' => $r['stakeholder'], 'needs' => $r['needs'], 'issue' => $r['issue']];
    }
    $has = function ($needs) use (&$ctx) { foreach ($ctx as $c) if ($c['needs'] === $needs) return true; return false; };
    $extra = [
        ['S', '客戶', '穩定的稽核製程加工品質', '稽核製程（齒研、插齒）的訂單與加工製程皆有 BOM 製程紀錄可追溯'],
        ['S', '管理階層、客戶', '品質系統有效運作、產品符合要求、品質績效可追溯', '品質系統於認證範圍（齒研、插齒）內運作，內部稽核、管理審查與 KPI 皆有紀錄可追溯'],
        ['O', '客戶', '更多稽核製程加工訂單', '稽核製程（齒研、插齒）已有穩定訂單，可向既有客戶爭取更多同類齒輪加工訂單'],
        ['O', '客戶', '接到訂單時快速評估並回覆交期', '新客戶陸續詢價，產品開發評估表與 PFMEA 已建立參考資料，可加速評估與承接'],
        ['T', '政府單位（環保局、消防局、人事行政機關）', '依法申報、保存紀錄', '事業廢棄物、消防設備檢修、勞工健康檢查與職安衛訓練的申報皆有法定期限，逾期會受處分'],
        ['W', '客戶、員工', '進料點收正確、產品識別與追溯不中斷、客供料妥善保管', '收貨（外購品、客供料、委外回廠品）點收數量、規格或識別標示不當，可能造成混料、短缺或追溯中斷'],
        ['T', '供應商', '刀具、砂輪等加工工具穩定供貨、規格符合需求', '刀具、砂輪等加工工具供貨延遲或規格不符，會使製程停滯並影響加工品質'],
    ];
    foreach ($extra as $e) if (!$has($e[2])) $ctx[] = ['dimension' => $e[0], 'stakeholder' => $e[1], 'needs' => $e[2], 'issue' => $e[3]];
    foreach (['S', 'W', 'O', 'T'] as $q) { $n = 0; foreach ($ctx as $c) if ($c['dimension'] === $q) $n++; if ($n < 2) $warn[] = '處境分析表「' . $q . '」象限只有 ' . $n . ' 筆（每個象限至少要兩筆），請人工補充'; }
    $ctxDate = $DT['ctx'];
    $ctxId = eg_demo_doc($db, 'context', $ctxDate, [], []);
    $ctxIds = []; $seq = 0;
    foreach ($ctx as $c) {
        $seq++;
        $db->prepare("INSERT INTO rm_item (doc_id, seq, scope, dimension, stakeholder, needs, issue) VALUES (?,?,?,?,?,?,?)")
           ->execute([$ctxId, $seq, RM_SWOT[$c['dimension']]['scope'], $c['dimension'], $c['stakeholder'], $c['needs'], $c['issue']]);
        $ctxIds[] = ['id' => (int)$db->lastInsertId()] + $c;
    }
    }
    $skipRisk = false;
    if ($draft) {         // 該年度已經有一張風險表（正式或示範）：一個年度只能有一張，不另外產生草稿，只產生溝通記錄與措施追蹤草稿
        $ex = eg_demo_q1($db, "SELECT COUNT(*) n FROM rm_doc WHERE kind='risk' AND is_deleted=0 AND is_draft=0 AND YEAR(fill_date)=?", [$Y]);
        if ((int)$ex['n'] > 0) { $skipRisk = true; $warn[] = $Y . ' 年已經有一張風險與機會因應措施管制表（一個年度只能有一張），所以只產生溝通記錄與措施追蹤草稿；要產生風險表草稿請先刪除該年度現有的風險表'; }
    }
    $riskDate = $DT['risk'];
    $deptNames = ['總經理室', '業務課', '生管組', '品管課', '技術課', '管理課'];
    $deptIds = array_values(array_filter(array_map(function ($n) use ($db) { return eg_demo_dept_id($db, $n); }, $deptNames)));
    $riskId = $skipRisk ? 0 : eg_demo_doc($db, 'risk', $riskDate, $deptIds, $deptNames);
    $seq = 0;
    foreach ($skipRisk ? [] : $ctxIds as $cx) {
        if (!in_array($cx['dimension'], ['W', 'O', 'T'], true)) continue;
        $seq++;
        $r = rm_rule_make_row($db, $set, ['stakeholder' => $cx['stakeholder'], 'needs' => $cx['needs'], 'issue' => $cx['issue'], 'dimension' => $cx['dimension']], $dataYear, $Y);
        $lv = rm_level_calc($set, $r['severity'], $r['frequency']);
        $confirm = ($lv && $lv['action']) ? rm_next_confirm_no($db, rm_prefix_for($set, (string)$r['ro_kind']), $riskDate) : null;
        $db->prepare("INSERT INTO rm_item (doc_id, seq, scope, stakeholder, needs, issue, ro_kind, ro_text, severity, frequency, confirm_no, measure, effect_check, ctx_item_id, rule_ids, resources) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$riskId, $seq, RM_SWOT[$cx['dimension']]['scope'], $cx['stakeholder'], $cx['needs'], $cx['issue'], $r['ro_kind'], $r['ro_text'], $r['severity'], $r['frequency'], $confirm, $r['measure'], $r['effect_check'], $cx['id'], $r['rule_ids'], $r['resources']]);
    }
    // 成效確認：與因應措施一併自動建立（rm_effect_autofill：結果、日期、裁示建議、佐證快照）。
    // 假資料維持「無效／未確認的留空」（不產生要開矯正單的示範資料）；草稿則全部建立，無法判定的只寫佐證與原因，待使用者確認
    $effDate = $DT['effect']; $effN = 0; $skipped = 0;
    foreach ($skipRisk ? [] : eg_demo_qa($db, "SELECT * FROM rm_item WHERE doc_id=? ORDER BY seq", [$riskId]) as $row) {
        $fa = rm_effect_autofill($db, $set, $row, $Y, $effDate);
        if ($fa['effect_status'] === null || (!$draft && $fa['effect_status'] === 'ineffective')) {
            $skipped++;
            if ($draft && $fa['effect_evidence'] !== '') $db->prepare("UPDATE rm_item SET effect_evidence=? WHERE item_id=?")->execute([$fa['effect_evidence'], (int)$row['item_id']]);
            continue;
        }
        $db->prepare("UPDATE rm_item SET effect_status=?, effect_date=?, effect_ruling=?, effect_evidence=? WHERE item_id=?")->execute([$fa['effect_status'], $fa['effect_date'], $fa['effect_ruling'], $fa['effect_evidence'], (int)$row['item_id']]);
        $effN++;
    }
    if ($skipped) $warn[] = $skipped . ($draft ? ' 列的成效目前規則無法判定（資料不足或找不到措施追蹤），只寫入佐證與原因，請確認時人工判斷' : ' 列的成效規則判定為「無效」或「未確認」，沒有寫入確認結果（請人工確認）');

    /* ---------- 4. 會議紀錄：內部稽核（事前／結束）與管理審查 ---------- */
    $topU = eg_org_user($db, 'top_approver');
    $chair = $topU ? ['id' => (int)$topU['id'], 'name' => (string)($topU['user_cname'] ?? '')] : ['id' => (int)$own['管理課']['id'], 'name' => $own['管理課']['name']];
    $attDepts = ['業務課', '生管組', '生產課', '品管課', '技術課', '管理課', '倉管組', '採購組'];
    $meetings = [];
    // 該年度已經有「真實」的管理審查會議紀錄就不再產生假的（同一天兩場一模一樣的會議只會混淆）
    $realMr = (int)eg_demo_q1($db, "SELECT COUNT(*) n FROM meeting_record WHERE subject LIKE '%管理審查%' AND subject NOT LIKE ? AND YEAR(meeting_date)=?", [EG_DEMO_TAG . '%', $Y])['n'];
    if (!$draft && $DT['mr'] !== '' && $realMr > 0) { $warn[] = '管理審查會議：' . $Y . ' 年已有實際建立的管理審查會議紀錄，不產生假資料'; $DT['mr'] = ''; }
    if ($DT['mr'] !== '')     $meetings[] = ['subj' => '管理審查會議', 'mk' => 'mr', 'date' => $DT['mr'], 'loc' => '大會議室'];
    if ($draft) $meetings = [];
    foreach ($draft ? [] : ['mr' => '管理審查會議'] as $k => $nm) if ($DT[$k] === '') $warn[] = $nm . '：日期留白，已略過不建立';
    $deptList = eg_demo_qa($db, "SELECT id, name FROM department ORDER BY CHAR_LENGTH(name) DESC");
    $mCount = 0;
    foreach ($meetings as $m) {
        // 管理審查排在年初（早於本年度成效確認日）＝審查的是前一年度，內容要引用前一年度的風險表，不能引用還沒發生的本年度成效
        $mYear = $Y; $mRisk = $riskId;
        if ($m['mk'] === 'mr' && $m['date'] < $DT['effect']) {
            $pr = eg_demo_q1($db, "SELECT doc_id FROM rm_doc WHERE kind='risk' AND is_deleted=0 AND YEAR(fill_date)=? ORDER BY doc_id DESC LIMIT 1", [$Y - 1]);
            if ($pr) { $mYear = $Y - 1; $mRisk = (int)$pr['doc_id']; }
        }
        // 每場會議各自一個交易：任何一步建立不了就整場跳過（不留半套資料），並在報告裡說明原因
        try {
            $att = [$chair['id'] => [$chair['name'], '總經理室', '總經理', 1]];
            foreach ($attDepts as $dn) { $o = $own[$dn] ?? []; if ($o && !isset($att[$o['id']])) $att[$o['id']] = [$o['name'], $dn, $o['pos'], 0]; }
            if (!$chair['id'] || count($att) < 3) throw new RuntimeException('找不到足夠的出席人員（主席與各部門代表），無法建立');
            $db->beginTransaction();
            $db->prepare("INSERT INTO meeting_record (subject, meeting_date, start_time, end_time, location, chair_user_id, chair_name, recorder_user_id, recorder_name, status, created_at, created_by, created_by_name, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,'done',?,1,'超級管理員',?)")
               ->execute([EG_DEMO_TAG . $m['subj'], $m['date'], '09:00', '10:30', $m['loc'], $chair['id'], $chair['name'], $own['管理課']['id'], $own['管理課']['name'], $m['date'] . ' 08:30:00', $m['date'] . ' 11:00:00']);
            $mid = (int)$db->lastInsertId();
            foreach ($att as $uid => $a)
                $db->prepare("INSERT INTO meeting_attendee (meeting_id, user_id, user_name, dept_name, position_name, is_chair, signed, signed_at) VALUES (?,?,?,?,?,?,1,?)")
                   ->execute([$mid, $uid, $a[0], $a[1], $a[2], $a[3], $m['date'] . ' 09:05:00']);
            $itemN = 0;
            foreach (['general' => 4, 'directive' => 2] as $kind => $want) {
                $lines = eg_demo_lines($db, $st, 'meeting_items', ['meeting_kind' => $m['mk'], 'subject' => $m['subj'], 'year' => $mYear, 'item_kind' => $kind]);
                if (!$lines) $lines = eg_demo_meeting_fallback($db, $m['mk'], $kind, $mYear, $mRisk);
                $so = 0;
                foreach (array_slice($lines, 0, $want + 2) as $ln) {
                    $so++; $ids = []; $nm = [];
                    foreach ($deptList as $dp) if (mb_strpos($ln, $dp['name']) !== false && count($ids) < 2 && !in_array((int)$dp['id'], $ids, true)) { $ids[] = (int)$dp['id']; $nm[] = $dp['name']; }
                    if (!$ids) { $ids = [eg_demo_dept_id($db, '管理課')]; $nm = ['管理課']; }
                    // 應完成日期：會議後 30 天，但不超過年底、也不晚於今天以後太多（會議日期本身已 ≤ 今天）
                    $due = eg_demo_clamp(date('Y-m-d', strtotime($m['date'] . ' +30 days')), $eoy);
                    $db->prepare("INSERT INTO meeting_item (meeting_id, kind, sort_order, content, due_date, owner_depts, owner_dept_names, confirm_mode, due_na) VALUES (?,?,?,?,?,?,?,'onsite',0)")
                       ->execute([$mid, $kind, $so, mb_substr($ln, 0, 400), $due, implode(',', $ids), implode('、', $nm)]);
                    $itemN++;
                }
            }
            if ($itemN === 0) throw new RuntimeException('產生不出會議要項，略過這場會議');
            $db->commit();
            $mCount++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $warn[] = $m['subj'] . '（' . $m['date'] . '）無法建立，已跳過：' . $e->getMessage();
        }
    }
    return ['year' => $Y, 'cleared' => $clear, 'comm_records' => count($R), 'comm_tracks' => array_sum(array_map(function ($r) { return count($r['tracks']); }, $R)),
            'dates' => $DT, 'context_items' => count($ctx), 'risk_items' => $seq, 'effect_filled' => $effN, 'meetings' => $mCount, 'from_context' => $fromCtx, 'draft' => $draft,
            'ai' => ['enabled' => !empty($opt['ai']), 'calls' => $st['calls'], 'ok' => $st['ai_ok'], 'fallback' => $st['fallback'], 'error' => $st['ai_err']], 'warn' => $warn];
}

/** 沒用 AI 或 AI 失敗時的會議要項：由系統事實直接組句（每項一行） */
function eg_demo_meeting_fallback(PDO $db, string $mk, string $kind, int $Y, int $riskDocId): array
{
    $c = eg_demo_qa($db, "SELECT COALESCE(effect_status,'none') s, COUNT(*) n FROM rm_item WHERE doc_id=? GROUP BY s", [$riskDocId]);
    $m = ['effective' => '有效', 'partial' => '部分有效', 'doing' => '進行中', 'none' => '尚未確認', 'ineffective' => '無效']; $p = [];
    foreach ($c as $r) $p[] = ($m[$r['s']] ?? $r['s']) . ' ' . $r['n'] . ' 項';
    $od = (int)eg_demo_q1($db, "SELECT COUNT(*) n FROM comm_track WHERE is_deleted=0 AND is_closed=0 AND due_date<CURDATE()")['n'];
    if ($kind === 'directive') return ['請各部門依風險與機會管制表的因應措施如期完成，並於下次會議報告進度。', '客戶與供應商的溝通，一律在溝通管理留下記錄並建立措施追蹤。'];
    if ($mk === 'mr') return [
        $Y . ' 年風險與機會因應措施管制表成效確認結果：' . implode('、', $p) . '；管理課彙整，持續追蹤。',
        '溝通管理措施追蹤' . ($od > 0 ? '有 ' . $od . ' 筆逾期未結案，各負責部門於期限前完成。' : '目前沒有逾期未結案，各部門持續依期限執行。'),
        'KPI 未達標項目由生管組、業務課、品管課依各自的措施追蹤項目執行並如期結案。',
        '內部稽核發現事項由品管課追蹤改善，下次會議報告完成情形。'];
    return [
        '本年度內部稽核範圍涵蓋稽核製程（齒研、插齒）相關單位，管理課負責彙整稽核計畫與紀錄。',
        '稽核發現的不符合事項由受稽單位提出矯正措施，品管課追蹤改善結果。',
        '風險與機會因應措施的執行情形列入稽核查檢，由管理課確認措施追蹤是否如期結案。',
        '稽核員與受稽單位對稽核結果有共識，後續改善依通知單期限辦理。'];
}

/**
 * 「風險與機會因應措施管制表缺失檢討會議」這類實際建立的會議：把目前風險表中「需要因應措施」的項目整理成會議要項。
 * 只動內容以【依處境分析表更新】開頭的要項（重複執行會取代上一次的），原本人工寫的要項一律不動。
 * @return int 寫入的要項數
 */
function eg_demo_sync_review_meeting(PDO $db, int $meetingId, int $riskDocId): int
{
    $mt = eg_demo_q1($db, "SELECT meeting_id FROM meeting_record WHERE meeting_id=?", [$meetingId]);
    if (!$mt) throw new RuntimeException('找不到會議 ' . $meetingId);
    $rd = eg_demo_q1($db, "SELECT fill_date FROM rm_doc WHERE doc_id=?", [$riskDocId]);
    $eoy = substr((string)($rd['fill_date'] ?? date('Y-m-d')), 0, 4) . '-12-31';
    $TAG = '【依處境分析表更新】';
    $deptList = eg_demo_qa($db, "SELECT id, name FROM department ORDER BY CHAR_LENGTH(name) DESC");
    $rows = eg_demo_qa($db, "SELECT seq, issue, ro_kind, measure, confirm_no FROM rm_item WHERE doc_id=? AND confirm_no IS NOT NULL AND confirm_no<>'' ORDER BY seq", [$riskDocId]);
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM meeting_item WHERE meeting_id=? AND content LIKE ?")->execute([$meetingId, $TAG . '%']);
        $so = (int)eg_demo_q1($db, "SELECT COALESCE(MAX(sort_order),0) m FROM meeting_item WHERE meeting_id=?", [$meetingId])['m'];
        $ins = $db->prepare("INSERT INTO meeting_item (meeting_id, kind, sort_order, content, due_date, owner_depts, owner_dept_names, confirm_mode, due_na) VALUES (?,?,?,?,?,?,?,'onsite',0)");
        $n = 0;
        $lines = [$TAG . '組織處境分析表（SWOT）已依現況修訂（含國際局勢、氣候變異、環境議題），並據以重新產生風險與機會因應措施管制表，其中 ' . count($rows) . ' 項需要因應措施，請各權責單位確認。'];
        foreach ($rows as $r) {
            $iss = mb_substr(trim((string)$r['issue']), 0, 60) . (mb_strlen(trim((string)$r['issue'])) > 60 ? '…' : '');
            $lines[] = $TAG . $r['confirm_no'] . '（' . ($r['ro_kind'] === 'opportunity' ? '機會' : '風險') . '）' . $iss . '；因應措施：' . trim((string)$r['measure']);
        }
        foreach ($lines as $i => $ln) {
            $ids = []; $nm = [];
            if ($i > 0) foreach ($deptList as $dp) if (mb_strpos($ln, $dp['name']) !== false && count($ids) < 3 && !in_array((int)$dp['id'], $ids, true)) { $ids[] = (int)$dp['id']; $nm[] = $dp['name']; }
            $so++; $n++;
            $ins->execute([$meetingId, 'general', $so, mb_substr($ln, 0, 400), $eoy, $ids ? implode(',', $ids) : null, $nm ? implode('、', $nm) : null]);
        }
        $db->commit();
        return $n;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/* ============================================================================
 * SWOT → 風險表草稿 → （風險表確認後）→ 溝通記錄＋措施追蹤草稿 —— 2026-10-19
 *   ① eg_draft_risk_generate：只依已儲存的處境分析表產生「風險與機會因應措施管制表」草稿
 *   ② eg_draft_comm_from_risk：風險表確認後，依風險表每一列「因應措施」拆句，一句一條措施追蹤（負責部門取句中的部門名），
 *      同一列的措施放在同一張溝通記錄；溝通記錄與追蹤也是草稿，要人實際看過才確認（溝通是實際發生的事，系統只能擬稿）
 *   溝通記錄的文字完全來自風險表的措施，不再有寫死在產生器裡的範本
 * ========================================================================== */
function eg_draft_risk_generate(PDO $db, int $Y, array $user): array
{
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    rm_ensure_schema($db);
    $ex = (int)eg_demo_q1($db, "SELECT COUNT(*) n FROM rm_doc WHERE kind='risk' AND is_deleted=0 AND is_draft=0 AND YEAR(fill_date)=?", [$Y])['n'];
    if ($ex > 0) throw new RuntimeException($Y . ' 年已經有正式的風險與機會因應措施管制表（一個年度只能有一張）；要重做請先刪除該年度的表單');
    $cd = eg_demo_q1($db, "SELECT doc_id FROM rm_doc WHERE kind='context' AND is_deleted=0 AND YEAR(fill_date)=? ORDER BY doc_id LIMIT 1", [$Y]);
    if (!$cd) throw new RuntimeException($Y . ' 年沒有組織處境分析表');
    $GLOBALS['EG_DRAFT_MODE'] = true; $GLOBALS['EG_DRAFT_USER'] = $user;
    $DT = eg_demo_default_dates($db, $Y);
    $set = rm_settings($db); $dataYear = $Y - 1; $warn = [];
    $deptNames = ['總經理室', '業務課', '生管組', '品管課', '技術課', '管理課'];
    $deptIds = array_values(array_filter(array_map(function ($n) use ($db) { return eg_demo_dept_id($db, $n); }, $deptNames)));
    $db->beginTransaction();
    try {
        eg_draft_clear($db, $Y);
        $riskId = eg_demo_doc($db, 'risk', $DT['risk'], $deptIds, $deptNames);
        $seq = 0; $effN = 0;
        foreach (eg_demo_qa($db, "SELECT item_id, dimension, stakeholder, needs, issue FROM rm_item WHERE doc_id=? ORDER BY seq, item_id", [(int)$cd['doc_id']]) as $cx) {
            if (!in_array($cx['dimension'], ['W', 'O', 'T'], true)) continue;
            $seq++;
            $r = rm_rule_make_row($db, $set, ['stakeholder' => $cx['stakeholder'], 'needs' => $cx['needs'], 'issue' => $cx['issue'], 'dimension' => $cx['dimension']], $dataYear, $Y);
            $lv = rm_level_calc($set, $r['severity'], $r['frequency']);
            $confirm = ($lv && $lv['action']) ? rm_next_confirm_no($db, rm_prefix_for($set, (string)$r['ro_kind']), $DT['risk']) : null;
            $db->prepare("INSERT INTO rm_item (doc_id, seq, scope, stakeholder, needs, issue, ro_kind, ro_text, severity, frequency, confirm_no, measure, effect_check, ctx_item_id, rule_ids, resources) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$riskId, $seq, RM_SWOT[$cx['dimension']]['scope'], $cx['stakeholder'], $cx['needs'], $cx['issue'], $r['ro_kind'], $r['ro_text'], $r['severity'], $r['frequency'], $confirm, $r['measure'], $r['effect_check'], (int)$cx['item_id'], $r['rule_ids'], $r['resources']]);
        }
        foreach (eg_demo_qa($db, "SELECT * FROM rm_item WHERE doc_id=? ORDER BY seq", [$riskId]) as $row) {
            $fa = rm_effect_autofill($db, $set, $row, $Y, $DT['effect']);
            if ($fa['effect_status'] === null) { if ($fa['effect_evidence'] !== '') $db->prepare("UPDATE rm_item SET effect_evidence=? WHERE item_id=?")->execute([$fa['effect_evidence'], (int)$row['item_id']]); continue; }
            $db->prepare("UPDATE rm_item SET effect_status=?, effect_date=?, effect_ruling=?, effect_evidence=? WHERE item_id=?")->execute([$fa['effect_status'], $fa['effect_date'], $fa['effect_ruling'], $fa['effect_evidence'], (int)$row['item_id']]);
            $effN++;
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['year' => $Y, 'doc_id' => $riskId, 'risk_items' => $seq, 'effect_filled' => $effN, 'warn' => $warn];
}

/** 措施句 → 負責部門：句中最早出現的部門名（或簡稱）；都沒有就用管理課 */
function eg_draft_clause_dept(string $clause, array $depts, string $fallback = '管理課'): string
{
    $alias = ['倉管' => '倉管組', '生管' => '生管組', '品管' => '品管課', '業務' => '業務課', '採購' => '採購組', '技術' => '技術課', '生產' => '生產課'];
    $best = ''; $pos = PHP_INT_MAX;
    foreach (array_merge(array_combine($depts, $depts), $alias) as $k => $dn) {
        $p = mb_strpos($clause, (string)$k);
        if ($p !== false && $p < $pos && in_array($dn, $depts, true)) { $pos = $p; $best = $dn; }
    }
    return $best !== '' ? $best : $fallback;
}

function eg_draft_comm_from_risk(PDO $db, int $riskDocId, array $user): array
{
    $doc = eg_demo_q1($db, "SELECT * FROM rm_doc WHERE doc_id=? AND kind='risk' AND is_deleted=0", [$riskDocId]);
    if (!$doc) throw new RuntimeException('查無風險與機會因應措施管制表');
    if ((int)$doc['is_draft'] === 1) throw new RuntimeException('風險表還是草稿，請先確認風險表');
    $Y = (int)substr((string)$doc['fill_date'], 0, 4);
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    cm_ensure_schema($db);
    $GLOBALS['EG_DRAFT_MODE'] = true; $GLOBALS['EG_DRAFT_USER'] = $user;
    $cats = []; foreach (eg_demo_qa($db, "SELECT cat_id, name, kind FROM comm_track_cat") as $c) $cats[$c['name']] = $c;
    if (empty($cats['員工'])) throw new RuntimeException('溝通管理缺少類別「員工」，請先到模組設定建立');
    $depts = ['業務課', '生管組', '生產課', '品管組', '品管課', '技術課', '管理課', '倉管組', '採購組'];
    $own = []; foreach ($depts as $dn) { $o = eg_demo_owner($db, $dn); if ($o) $own[$dn] = $o; }
    if (empty($own['管理課'])) throw new RuntimeException('找不到管理課的在職人員，無法指定負責人');
    $set = rm_settings($db); $nRec = 0; $nTrk = 0; $i = 0;
    rm_trk_risk_col($db);        // 先確認連結欄位存在（ALTER 會隱式 commit，不能放在交易中）
    // 只清「尚未確認」的舊溝通草稿（已確認的不動）
    $ids = array_column(eg_demo_qa($db, "SELECT rec_id FROM comm_record WHERE is_draft=1 AND YEAR(comm_date)=?", [$Y]), 'rec_id');
    if ($ids) { $in = implode(',', array_map('intval', $ids)); $db->exec("DELETE FROM comm_track WHERE src_rec_id IN ($in)"); $db->exec("DELETE FROM comm_record_item WHERE rec_id IN ($in)"); $db->exec("DELETE FROM comm_record WHERE rec_id IN ($in)"); }
    foreach (eg_demo_qa($db, "SELECT * FROM rm_item WHERE doc_id=? ORDER BY seq", [$riskDocId]) as $row) {
        // 拆句：分號／句號、以及「，由○○」（同一句裡交代給不同部門的事）；「以…」開頭的是目的說明，併回前一句
        $clauses = [];
        foreach (array_map('trim', preg_split('/(?:[；;。]+|[，,](?=\s*由))/u', (string)$row['measure'])) as $cl) {
            if (mb_strlen($cl) < 4) continue;
            if ($clauses && mb_substr($cl, 0, 1) === '以') { $clauses[count($clauses) - 1] .= '，' . $cl; continue; }
            $clauses[] = $cl;
        }
        if (!$clauses) continue;
        $label = '';
        $cx = (int)($row['ctx_item_id'] ?? 0); $src = $row;
        if ($cx > 0) { $q = eg_demo_q1($db, "SELECT dimension, stakeholder, needs, issue FROM rm_item WHERE item_id=?", [$cx]); if ($q) $src = array_merge($row, $q); }
        $ck = rm_rule_classify(trim(($src['stakeholder'] ?? '') . ' ' . ($src['needs'] ?? '') . ' ' . ($src['issue'] ?? '')), (string)($src['dimension'] ?? ''))[0] ?? '';
        $label = (string)(RM_RULE_CATS[$ck]['label'] ?? '');
        if ($label === '' || $label === '一般議題') { $lp = preg_split('/[，,。；;]/u', trim((string)$row['issue'])); $label = mb_substr($lp[0], 0, 24); }     // 沒有對到固定類別：取議題的第一個短句當標題
        $date = eg_demo_wd($db, min($today, date('Y-m-d', strtotime($doc['fill_date'] . ' +' . (1 + $i * 2) . ' day'))), -1);
        if ($date < $doc['fill_date']) $date = $doc['fill_date'];
        $i++;
        $items = []; $involved = []; $due = $Y . '-12-31'; $dueTxt = str_replace('-', '.', $due);
        $dn = (mb_strpos((string)($src['stakeholder'] ?? ''), '客戶') !== false) ? '業務課' : '管理課';     // 句中沒有部門名時的預設（客戶議題→業務課），之後沿用前一句的部門
        $nCl = count($clauses);
        foreach ($clauses as $ci => $cl) {
            $dn = eg_draft_clause_dept($cl, array_keys($own), $dn); $ow = $own[$dn] ?? $own['管理課'];
            // 句中提到的其他部門＝配合單位（例如「業務課與生管組每月核對」：負責業務課，配合生管組）
            $help = [];
            foreach (array_keys($own) as $d2) if ($d2 !== $ow['dept'] && mb_strpos($cl, $d2) !== false) $help[] = $d2;
            foreach (array_merge([$ow['dept']], $help) as $d2) $involved[$d2] = $own[$d2] ?? $ow;
            // 溝通問題＝要處理的議題（為什麼要溝通）；回覆內容＝決議（誰負責、怎麼做、何時前完成、由哪一條措施追蹤）——兩欄不重複同一段文字
            // 溝通問題要簡短：只寫「哪個議題、要確認什麼」（議題全文在風險表／處境分析表，不整段抄進來）；措施本身寫在回應措施與追蹤
            $q = '需確認「' . $cl . '」的負責單位、執行方式與完成期限（對應風險與機會因應措施管制表 ' . (trim((string)$row['confirm_no']) !== '' ? $row['confirm_no'] : '第 ' . (int)$row['seq'] . ' 項') . '）。';
            // 持續性工作（定期、每月、持續…）與一次性工作的期限寫法不同：持續性的要寫明是「本年度執行」並於年底檢視
            $recurring = (bool)preg_match('/(每[年月週周季日]|定期|持續|隨時|平時)/u', $cl);
            $a = '由' . $ow['dept'] . '（' . $ow['name'] . '）負責' . ($help ? '，' . implode('、', $help) . '配合' : '')
               . ($recurring ? '，持續執行並於 ' . $dueTxt . ' 前完成本年度執行與檢視' : '，預計 ' . $dueTxt . ' 前完成')
               . '；由措施追蹤 @@TRK@@ 追蹤執行情形。';
            $items[] = ['q' => $q, 'a' => $a, 'track' => ['owner' => $ow, 'due' => $due, 'action' => $cl]];
        }
        // 管道：公司內部以面談為主；涉及人數眾多（4 個部門以上）才用會議（勾「會議」要有會議紀錄佐證，所以不預設）
        // 溝通對象（類別＝員工）：涉及的部門＋人員，不限定一位
        $partyTxt = implode('、', array_map(function ($d2, $o2) { return $d2 . ' ' . $o2['name']; }, array_keys($involved), array_values($involved)));
        $made = eg_demo_record($db, $cats, ['date' => $date, 'type' => 'regular', 'cat' => '員工', 'party' => $partyTxt, 'ch' => (count($involved) >= 4 ? ['meeting'] : ['face']), 'maker' => $own['管理課'], 'items' => $items]);
        foreach ($made['tracks'] as $tid) {     // 回覆內容裡的追蹤編號：追蹤建立後才知道，逐條補上
            $db->prepare("UPDATE comm_record_item SET reply=REPLACE(reply,'@@TRK@@',(SELECT track_no FROM comm_track WHERE track_id=?)) WHERE track_id=?")->execute([(int)$tid, (int)$tid]);
        }
        if ($made['tracks'] && rm_trk_risk_col($db)) $db->exec("UPDATE comm_track SET src_risk_item_id=" . (int)$row['item_id'] . " WHERE track_id IN (" . implode(',', array_map('intval', $made['tracks'])) . ")");
        $nRec++; $nTrk += count($items);
    }
    return ['year' => $Y, 'records' => $nRec, 'tracks' => $nTrk];
}

/** 已經存在、但沒有連結的措施追蹤：措施內容完整出現在某一列因應措施裡的，補上自動連結（一次性補資料用；比對不到的不動） */
function eg_link_tracks_by_text(PDO $db, int $riskDocId): int
{
    if (!rm_trk_risk_col($db)) return 0;
    $doc = eg_demo_q1($db, "SELECT fill_date FROM rm_doc WHERE doc_id=?", [$riskDocId]); if (!$doc) return 0;
    $Y = (int)substr((string)$doc['fill_date'], 0, 4); $n = 0;
    $norm = function ($t) { return preg_replace('/[\s，,。；;、]+/u', '', (string)$t); };
    $items = eg_demo_qa($db, "SELECT item_id, measure FROM rm_item WHERE doc_id=?", [$riskDocId]);
    foreach (eg_demo_qa($db, "SELECT track_id, action FROM comm_track WHERE is_deleted=0 AND src_risk_item_id IS NULL AND YEAR(react_date)=?", [$Y]) as $t) {
        $a = $norm($t['action']); if (mb_strlen($a) < 8) continue;
        foreach ($items as $it) if (mb_strpos($norm($it['measure']), $a) !== false) { $db->prepare("UPDATE comm_track SET src_risk_item_id=? WHERE track_id=?")->execute([(int)$it['item_id'], (int)$t['track_id']]); $n++; break; }
    }
    return $n;
}
