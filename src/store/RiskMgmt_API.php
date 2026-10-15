<?php
/**
 * 風險與機會管理 API —— 2026-10-14 建立（共用庫 src/common/risk_mgmt_lib.php）
 * 權限：rm_perms()（roles module='risk_mgmt'）
 *   一般在職員工  建立／編輯自己建立的表單
 *   rm_view       唯讀看全部
 *   rm_admin      全部＋模組設定＋刪除＋AS 文件綁定
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/risk_mgmt_rules_lib.php';   // 內含 risk_mgmt_lib／risk_mgmt_suggest_lib

function jout($a = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
set_exception_handler(function ($e) { jerr('系統錯誤：' . $e->getMessage(), 500); });

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    rm_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['rm_csrf'])) $_SESSION['rm_csrf'] = bin2hex(random_bytes(16));

$u = cm_current_user($db);
if (!$u) jerr('未登入', 401);
$P     = rm_perms($db, $u);
$uid   = (int)$P['uid'];
$uname = (string)$P['name'];
if (!$uid) jerr('無使用權限（帳號非在職狀態）', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* 寫入類動作：先驗登入再驗 CSRF（順序不可顛倒，見 _config.php 的說明） */
$WRITE = ['doc_save', 'doc_delete', 'setting_save', 'asdoc_save', 'risk_generate'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['rm_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code'=>'CSRF']);
}

function rmDate($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
function rmS($v, int $max = 500): string { return mb_substr(trim((string)$v), 0, $max); }
function rmReqAdmin(array $P) { if (!$P['canAdmin']) jerr('需要風險與機會管理員權限', 403); }
function rmKind($v): string { $k = rmS($v, 10); return isset(RM_ASDOC_MODULES[$k]) ? $k : jerr('表單種類不正確'); }
function rmToday(PDO $db): string {
    try { return (string)$db->query("SELECT CURDATE()")->fetchColumn(); } catch (Throwable $e) { return date('Y-m-d'); }
}
function rmNow(PDO $db): string {
    try { return (string)$db->query("SELECT NOW()")->fetchColumn(); } catch (Throwable $e) { return date('Y-m-d H:i:s'); }
}
function rmDocGet(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM rm_doc WHERE doc_id=? AND is_deleted=0");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function rmCanSee(array $r, array $P): bool { return true; }   // 兩種表都是整家公司一個年度一張，所有在職員工都看得到（只有建立者與管理員可改）
function rmCanEdit(array $r, array $P): bool { return $P['canAdmin'] || (int)$r['created_by'] === (int)$P['uid']; }

/** 明細列＋即時算出的風險等級（用「目前設定」，不讀存好的值） */
function rmItems(PDO $db, int $docId, array $set): array {
    $st = $db->prepare("SELECT * FROM rm_item WHERE doc_id=? ORDER BY seq, item_id");
    $st->execute([$docId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $lv = rm_level_calc($set, $r['severity'], $r['frequency'], (string)($r['ro_kind'] ?? 'risk'));
        $r['level'] = $lv ? array_merge($lv, rm_level_color($lv['rank'])) : null;
        // 連動組織處境分析表的議題：內外部／利害關係者／需求與期望／議題描述一律讀來源的「現在」內容
        $r['ctx_linked'] = false; $r['ctx_doc_no'] = null;
        if ((int)$r['ctx_item_id'] > 0) {
            $q = $db->prepare("SELECT i.scope, i.stakeholder, i.needs, i.issue, d.doc_no FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id
                               WHERE i.item_id=? AND d.kind='context' AND d.is_deleted=0");
            $q->execute([(int)$r['ctx_item_id']]);
            if ($c = $q->fetch(PDO::FETCH_ASSOC)) {
                $r['scope'] = $c['scope']; $r['stakeholder'] = $c['stakeholder']; $r['needs'] = $c['needs']; $r['issue'] = $c['issue'];
                $r['ctx_linked'] = true; $r['ctx_doc_no'] = $c['doc_no'];
            }
        }
    }
    unset($r);
    return $rows;
}

/* ---------------------------------------------------------------- meta */
if ($action === 'meta') {
    $set = rm_settings($db);
    jout([
        'csrf'     => (string)$_SESSION['rm_csrf'],
        'perms'    => $P,
        'me'       => ['id'=>$uid, 'name'=>$uname],
        'today'    => rmToday($db),
        'settings' => $set,
        'levels'   => rm_levels_view($set),
        'asdoc'    => rm_asdoc_all($db),
        'asdoc_list' => $P['canAdmin'] ? eg_asdoc_list($db) : [],
        'scopes'   => RM_SCOPES, 'ro_kinds' => RM_RO_KINDS,
        'swot'     => rm_swot_view($set), 'guide' => rm_swot_guide(), 'effect_status' => RM_EFFECT_STATUS,
        'depts'    => cm_dept_list($db),
        'roles'    => rm_roles_now($db),
        'audit_procs' => rm_audit_procs_view($db),
    ]);
}

/* ---------------------------------------------------------------- 列表 */
if ($action === 'doc_list') {
    $kind = rmKind($_GET['kind'] ?? '');
    $per  = (int)($_GET['per'] ?? 20); if ($per !== 0) $per = max(5, min(100, $per));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $kw   = rmS($_GET['kw'] ?? '', 60);
    $year = (int)($_GET['year'] ?? 0);

    $where = ["d.kind=?", "d.is_deleted=0"]; $a = [$kind];
    // 兩種表都是整家公司一個年度一張，所有在職員工都看得到，不再依建立者過濾
    if ($year > 0) { $where[] = "YEAR(d.fill_date)=?"; $a[] = $year; }
    if ($kw !== '') {
        $like = '%' . $kw . '%';
        $where[] = "(d.doc_no LIKE ? OR d.dept_names LIKE ? OR d.created_by_name LIKE ?
                     OR EXISTS (SELECT 1 FROM rm_item i WHERE i.doc_id=d.doc_id AND (i.stakeholder LIKE ? OR i.needs LIKE ? OR i.issue LIKE ?
                          OR i.ro_text LIKE ? OR i.measure LIKE ? OR i.effect_check LIKE ? OR i.confirm_no LIKE ? OR i.dimension LIKE ?)))";
        for ($i = 0; $i < 11; $i++) $a[] = $like;
    }
    $w = implode(' AND ', $where);
    $st = $db->prepare("SELECT COUNT(*) FROM rm_doc d WHERE $w"); $st->execute($a);
    $total = (int)$st->fetchColumn();
    $lim = $per === 0 ? '' : ' LIMIT ' . (($page - 1) * $per) . ',' . $per;
    $st = $db->prepare("SELECT d.*, (SELECT COUNT(*) FROM rm_item i WHERE i.doc_id=d.doc_id) AS item_cnt
                        FROM rm_doc d WHERE $w ORDER BY d.fill_date DESC, d.doc_id DESC" . $lim);
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $set = rm_settings($db);
    foreach ($rows as &$r) {
        $r['top_level'] = null; $r['action_cnt'] = 0;
        if ($kind === 'risk') {
            // 「最高風險等級」只看風險（機會不是危險，不該把清單的等級拉高）；「需因應措施」則風險與機會都算
            $its = $db->prepare("SELECT severity, frequency, ro_kind FROM rm_item WHERE doc_id=?");
            $its->execute([(int)$r['doc_id']]);
            $best = null;
            foreach ($its->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $lv = rm_level_calc($set, $it['severity'], $it['frequency'], (string)($it['ro_kind'] ?? 'risk'));
                if (!$lv) continue;
                if ($lv['action']) $r['action_cnt']++;
                if ($lv['is_opp']) continue;
                if (!$best || $lv['score'] > $best['score']) $best = $lv;
            }
            if ($best) $r['top_level'] = array_merge($best, rm_level_color($best['rank']));
        }
        $r['can_edit'] = rmCanEdit($r, $P);
    }
    unset($r);
    jout(['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per, 'print'=>rm_print_meta($db, $kind)]);
}

/* ---------------------------------------------------------------- 讀取一張 */
if ($action === 'doc_get') {
    $r = rmDocGet($db, (int)($_GET['doc_id'] ?? 0));
    if (!$r) jerr('查無此表單', 404);
    if (!rmCanSee($r, $P)) jerr('您沒有查看這張表單的權限', 403);
    $set = rm_settings($db);
    $items = rmItems($db, (int)$r['doc_id'], $set);
    if ($r['kind'] === 'risk') {      // 因應措施的負責單位與預計完成日一律即時取自溝通管理的措施追蹤，不在本表另存一份
        $yr = (int)substr((string)$r['fill_date'], 0, 4);
        foreach ($items as &$it) { try { $it['tracks'] = rm_item_tracks($db, $yr, $it); } catch (Throwable $e) { $it['tracks'] = []; } }
        unset($it);
    }
    jout(['doc'=>$r, 'items'=>$items, 'can_edit'=>rmCanEdit($r, $P),
          'print'=>rm_print_meta($db, $r['kind'], (string)$r['fill_date'])]);
}

/* ---------------------------------------------------------------- 風險分布（表D）：各格實際有幾筆風險 */
if ($action === 'dist') {
    $where = ["d.kind='risk'", "d.is_deleted=0", "i.severity IS NOT NULL",
              "(i.ro_kind IS NULL OR i.ro_kind <> 'opportunity')"]; $a = [];
    $year = (int)($_GET['year'] ?? 0);
    if ($year > 0) { $where[] = "YEAR(d.fill_date)=?"; $a[] = $year; }
    $st = $db->prepare("SELECT i.severity, i.frequency, COUNT(*) c FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id
                        WHERE " . implode(' AND ', $where) . " GROUP BY i.severity, i.frequency");
    $st->execute($a);
    jout(['cells'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---------------------------------------------------------------- 空白表單列印用：表頭（公司名稱、表單名稱、AS 編號） */
if ($action === 'print_meta') {
    $k = ($_GET['kind'] ?? '') === 'context' ? 'context' : 'risk';
    jout(['print' => rm_print_meta($db, $k, rmToday($db))]);
}

/* ---------------------------------------------------------------- 組織處境分析表：整家公司一個年度一張 */
if ($action === 'ctx_year') {
    $year = (int)($_GET['year'] ?? 0);
    if ($year < 2000 || $year > 2100) $year = (int)substr(rmToday($db), 0, 4);
    $st = $db->prepare("SELECT * FROM rm_doc WHERE kind='context' AND is_deleted=0 AND YEAR(fill_date)=? ORDER BY doc_id LIMIT 1");
    $st->execute([$year]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $yrs = $db->query("SELECT DISTINCT YEAR(fill_date) y FROM rm_doc WHERE kind='context' AND is_deleted=0 ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    jout(['year'=>$year, 'doc'=>$r ?: null,
          'items'=>$r ? rmItems($db, (int)$r['doc_id'], rm_settings($db)) : [],
          'can_edit'=>$r ? rmCanEdit($r, $P) : true,     // 還沒建立：任何在職員工都可以建（建立者之後與管理員可改）
          'years'=>array_map('intval', $yrs),
          'print'=>rm_print_meta($db, 'context', $r ? (string)$r['fill_date'] : null)]);
}

/* ---------------------------------------------------------------- 依系統資料給的建議（不是外部 AI：全部來自本系統既有資料，可驗證、可追溯） */
if ($action === 'suggest') {
    $year = (int)($_GET['year'] ?? 0);
    if ($year < 2000 || $year > 2100) $year = (int)substr(rmToday($db), 0, 4);
    $out = [];
    // ① 去年表上的項目（沿用最省事）
    $st = $db->prepare("SELECT d.doc_id, YEAR(d.fill_date) y FROM rm_doc d WHERE d.kind='context' AND d.is_deleted=0 AND YEAR(d.fill_date)<? ORDER BY d.fill_date DESC LIMIT 1");
    $st->execute([$year]);
    if ($prev = $st->fetch(PDO::FETCH_ASSOC)) {
        $q = $db->prepare("SELECT dimension, stakeholder, needs, issue FROM rm_item WHERE doc_id=? ORDER BY seq");
        $q->execute([(int)$prev['doc_id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $it) {
            if (!isset(RM_SWOT[(string)$it['dimension']])) continue;
            $out[] = ['source'=>'去年（' . $prev['y'] . ' 年度）組織處境分析表', 'key'=>$it['dimension'], 'stakeholder'=>(string)$it['stakeholder'],
                      'needs'=>(string)$it['needs'], 'issue'=>(string)$it['issue'], 'why'=>'去年已列入，確認今年是否仍然適用'];
        }
    }
    // ② 溝通管理：還沒結案的利害關係者反應 → 需求與期望的線索
    try {
        $q = $db->prepare("SELECT party, content, due_date FROM comm_track WHERE is_deleted=0 AND is_closed=0 AND party<>'' AND YEAR(react_date)=? ORDER BY react_date DESC, track_id DESC LIMIT 8");
        $q->execute([(int)($_GET['data_year'] ?? 0) >= 2000 ? (int)$_GET['data_year'] : $year]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $out[] = ['source'=>'溝通管理・措施追蹤表（未結案）', 'key'=>'T', 'stakeholder'=>(string)$t['party'], 'needs'=>mb_substr((string)$t['content'], 0, 200),
                      'issue'=>'', 'why'=>'利害關係者提出且尚未解決的反應，可能是外部威脅或需要把握的機會（請自行判斷象限）'];
        }
    } catch (Throwable $e) {}
    // ③ 依當年實際資料即時算出的建議（KPI、出貨、人員、行事曆、委外、品質異常、法規溝通…；選哪一年就用那一年的資料）
    require_once __DIR__ . '/../common/risk_mgmt_rules_lib.php';
    $GLOBALS['RM_SG_LAST_ERR'] = [];
    // 資料年度：年初（1～3 月）填當年度的表時，當年資料還很少，預設用前一年的全年資料；使用者可在畫面上改選
    $dy = (int)($_GET['data_year'] ?? 0);
    if ($dy < 2000 || $dy > 2100) $dy = rm_data_year($db, $year);
    $auto = rm_suggest_context($db, $dy);
    $out = array_merge($auto, $out);                       // 規則算出的放最前面，再來是去年的表與未結案反應
    jout(['year'=>$year, 'data_year'=>$dy, 'rows'=>$out, 'warn'=>$GLOBALS['RM_SG_LAST_ERR']]);

}

/* ---------------------------------------------------------------- 判斷規則目錄（畫面「判斷規則」顯示） */
if ($action === 'rules_list') {
    require_once __DIR__ . '/../common/risk_mgmt_rules_lib.php';
    jout(['rules'=>rm_rule_catalog(), 'status'=>RM_EFFECT_STATUS]);
}

/* ---------------------------------------------------------------- 成效確認：依規則蒐集佐證並建議結果（不寫入，使用者看過、改過、填裁示後才按儲存） */
if ($action === 'risk_evidence') {
    require_once __DIR__ . '/../common/risk_mgmt_rules_lib.php';
    $row = json_decode((string)($_POST['row'] ?? ''), true);
    if (!is_array($row)) jerr('資料不正確');
    $year = (int)($_POST['year'] ?? 0); if ($year < 2000 || $year > 2100) $year = (int)substr(rmToday($db), 0, 4);
    // 連動處境議題時，一律用來源的最新內容判斷
    $cx = (int)($row['ctx_item_id'] ?? 0);
    if ($cx > 0) {
        $q = $db->prepare("SELECT dimension, stakeholder, needs, issue FROM rm_item WHERE item_id=?"); $q->execute([$cx]);
        if ($c0 = $q->fetch(PDO::FETCH_ASSOC)) { $row['dimension'] = $c0['dimension']; $row['stakeholder'] = $c0['stakeholder']; $row['needs'] = $c0['needs']; $row['issue'] = $c0['issue']; }
    }
    jout(['evidence'=>rm_rule_evidence($db, rm_settings($db), $row, $year)]);
}

/* ---------------------------------------------------------------- 由組織處境分析表產生風險與機會因應措施管制表（規則引擎替每個議題產生草稿） */
if ($action === 'risk_generate') {
    require_once __DIR__ . '/../common/risk_mgmt_rules_lib.php';
    $year = (int)($_POST['year'] ?? 0);
    $cy = (int)substr(rmToday($db), 0, 4);
    if ($year < 2000 || $year > $cy) jerr('年度不正確（不可建立未來年度）');
    $st = $db->prepare("SELECT * FROM rm_doc WHERE kind='context' AND is_deleted=0 AND YEAR(fill_date)=? ORDER BY doc_id LIMIT 1");
    $st->execute([$year]);
    $ctxDoc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ctxDoc) jerr('請先建立 ' . $year . ' 年度的組織處境分析表（年初先填處境分析，再由它產生風險與機會因應措施管制表）');
    $st = $db->prepare("SELECT * FROM rm_doc WHERE kind='risk' AND is_deleted=0 AND YEAR(fill_date)=? ORDER BY doc_id LIMIT 1");
    $st->execute([$year]);
    $riskDoc = $st->fetch(PDO::FETCH_ASSOC);
    if ($riskDoc && !rmCanEdit($riskDoc, $P)) jerr('這張表只有建立者或管理員可以補產生項目', 403);
    $dy = (int)($_POST['data_year'] ?? 0); if ($dy < 2000 || $dy > 2100) $dy = rm_data_year($db, $year);
    $set = rm_settings($db);
    $src = $db->prepare("SELECT * FROM rm_item WHERE doc_id=? AND dimension IN ('W','O','T') ORDER BY seq, item_id");
    $src->execute([(int)$ctxDoc['doc_id']]);
    $ctxItems = $src->fetchAll(PDO::FETCH_ASSOC);
    $linked = [];
    if ($riskDoc) { $lq = $db->prepare("SELECT ctx_item_id FROM rm_item WHERE doc_id=? AND ctx_item_id IS NOT NULL"); $lq->execute([(int)$riskDoc['doc_id']]); $linked = array_map('intval', $lq->fetchAll(PDO::FETCH_COLUMN)); }
    $todo = array_values(array_filter($ctxItems, function ($i) use ($linked) { return !in_array((int)$i['item_id'], $linked, true); }));
    if (!$todo) jerr($riskDoc ? '處境分析表的議題都已經有對應的風險項目了，沒有需要補產生的' : '這張處境分析表沒有可以產生風險／機會的議題（優勢象限不產生風險項目）');
    $now = rmNow($db);
    $db->beginTransaction();
    try {
        if (!$riskDoc) {
            $fd = $year === $cy ? rmToday($db) : $year . '-12-31';
            $no = rm_next_doc_no($db, $fd);
            $db->prepare("INSERT INTO rm_doc (doc_no, kind, dept_ids, fill_date, created_by, created_by_name, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$no, 'risk', '[]', $fd, $uid, $uname, $now, $now]);
            $docId = (int)$db->lastInsertId(); $seq = 0; $fill = $fd;
        } else {
            $docId = (int)$riskDoc['doc_id']; $fill = (string)$riskDoc['fill_date'];
            $seq = (int)$db->query("SELECT COALESCE(MAX(seq),0) FROM rm_item WHERE doc_id=$docId")->fetchColumn();
        }
        $summary = [];
        foreach ($todo as $c) {
            $seq++;
            $r = rm_rule_make_row($db, $set, $c, $dy, (int)substr($fill, 0, 4));
            $lv = rm_level_calc($set, $r['severity'], $r['frequency'], (string)$r['ro_kind']);
            $confirm = ($lv && $lv['action']) ? rm_next_confirm_no($db, (string)$set['rm_confirm_prefix'], $fill) : null;
            $db->prepare("INSERT INTO rm_item (doc_id, seq, scope, stakeholder, needs, issue, ro_kind, ro_text, severity, frequency, confirm_no, measure, effect_check, ctx_item_id, rule_ids)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$docId, $seq, $c['scope'], $c['stakeholder'], $c['needs'], $c['issue'], $r['ro_kind'], $r['ro_text'], $r['severity'], $r['frequency'], $confirm,
                          $r['measure'], $r['effect_check'], (int)$c['item_id'], $r['rule_ids']]);
            $summary[] = ['issue'=>mb_substr((string)$c['issue'], 0, 40), 'category'=>RM_RULE_CATS[$r['category']]['label'], 'level'=>$lv['name'] ?? '', 'why'=>$r['why']];
        }
        $db->prepare("UPDATE rm_doc SET updated_at=? WHERE doc_id=?")->execute([$now, $docId]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('產生失敗：' . $e->getMessage(), 500); }
    jout(['doc_id'=>$docId, 'created'=>!$riskDoc, 'added'=>count($todo), 'data_year'=>$dy, 'summary'=>$summary]);
}

/* ---------------------------------------------------------------- 可引用的組織處境議題（風險表挑「引用處境議題」用） */
if ($action === 'ctx_options') {
    $kw = rmS($_GET['kw'] ?? '', 60);
    $where = ["d.kind='context'", "d.is_deleted=0"]; $a = [];
    if ($kw !== '') {
        $where[] = "(i.issue LIKE ? OR i.stakeholder LIKE ? OR i.needs LIKE ? OR i.dimension LIKE ?)";
        for ($i = 0; $i < 4; $i++) $a[] = '%' . $kw . '%';
    }
    $st = $db->prepare("SELECT i.item_id, i.scope, i.dimension, i.stakeholder, i.needs, i.issue, d.doc_no, d.fill_date
                        FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY d.fill_date DESC, i.item_id DESC LIMIT 60");
    $st->execute($a);
    jout(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---------------------------------------------------------------- 儲存（新增／編輯，整張含明細） */
if ($action === 'doc_save') {
    $id   = (int)($_POST['doc_id'] ?? 0);
    $old  = $id ? rmDocGet($db, $id) : null;
    if ($id && !$old) jerr('查無此表單', 404);
    if ($old && !rmCanEdit($old, $P)) jerr('只有填表人或管理員可以修改這張表單', 403);
    $kind = $old ? (string)$old['kind'] : rmKind($_POST['kind'] ?? '');

    $fillDate = rmDate($_POST['fill_date'] ?? '');
    if (!$fillDate) jerr('請填寫填表日期');
    if ($fillDate > rmToday($db)) jerr('填表日期不可是未來日期');
    {
        /* 兩種表都是整家公司一個年度只有一張：AS9100D 6.1／4.1 沒有規定一年幾張，這是本公司程序書 2-GM-03 的做法
           （6.1.1 風險小組會議每年至少召開一次；6.7 有重大變化時開不定期會議＝在同一張表上更新，不另開新表） */
        $q = $db->prepare("SELECT doc_id, doc_no FROM rm_doc WHERE kind=? AND is_deleted=0 AND YEAR(fill_date)=? AND doc_id<>?");
        $q->execute([$kind, (int)substr($fillDate, 0, 4), $id]);
        if ($dup = $q->fetch(PDO::FETCH_ASSOC))
            jerr('「' . substr($fillDate, 0, 4) . '」年度已經有' . (rm_print_meta($db, $kind)['doc_name'] ?: '這張表') . '（單號 ' . $dup['doc_no'] . '），整家公司一個年度只會有一張，請直接開啟那一張編輯（年度中途有變動也是在同一張上更新）。', 409);
    }

    $set = rm_settings($db);

    // 相關部門：只收主檔裡真的存在的部門 id，名稱一律回查主檔（不採信前端送來的文字）
    $deptIds = json_decode((string)($_POST['dept_ids'] ?? '[]'), true);
    $deptIds = is_array($deptIds) ? array_values(array_unique(array_filter(array_map('intval', $deptIds)))) : [];
    $deptMap = [];
    foreach (cm_dept_list($db) as $d) $deptMap[(int)$d['id']] = (string)$d['name'];
    $deptIds = $kind === 'context' ? [] : array_values(array_filter($deptIds, function ($x) use ($deptMap) { return isset($deptMap[$x]); }));
    $deptNames = implode('、', array_map(function ($x) use ($deptMap) { return $deptMap[$x]; }, $deptIds));

    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('明細內容不正確');
    $items = array_slice($items, 0, 200);

    $clean = []; $seq = 0;
    $sevOk = array_map(function ($r) { return (int)$r['v']; }, $set['rm_impact']['rows']);
    $frqOk = array_map(function ($r) { return (int)$r['v']; }, $set['rm_likelihood']);
    foreach ($items as $n => $it) {
        $row = [
            'scope'       => in_array(($it['scope'] ?? ''), array_keys(RM_SCOPES), true) ? $it['scope'] : null,
            'dimension'   => null,
            'stakeholder' => rmS($it['stakeholder'] ?? '', 200),
            'needs'       => rmS($it['needs'] ?? '', 4000),
            'issue'       => rmS($it['issue'] ?? '', 4000),
            'ro_kind'     => null, 'ro_text' => '', 'severity' => null, 'frequency' => null,
            'confirm_no'  => rmS($it['confirm_no'] ?? '', 30),
            'measure'     => '', 'effect_check' => '', 'ctx_item_id' => null,
            'effect_status' => null, 'effect_date' => null, 'effect_ruling' => '', 'effect_evidence' => '', 'rule_ids' => null,
            'recheck_note' => '', 'recheck_date' => null,
        ];
        if ($kind === 'context') {
            $dim = strtoupper(rmS($it['dimension'] ?? '', 2));
            if (!isset(RM_SWOT[$dim])) jerr('第 ' . ($n + 1) . ' 列沒有指定 SWOT 象限（優勢／劣勢／機會／威脅）');
            $row['dimension'] = $dim;
            $row['scope'] = RM_SWOT[$dim]['scope'];            // 內部／外部由象限決定，不採信前端
        } else {
            $row['ro_kind'] = in_array(($it['ro_kind'] ?? ''), array_keys(RM_RO_KINDS), true) ? $it['ro_kind'] : null;
            $row['ro_text'] = rmS($it['ro_text'] ?? '', 4000);
            $row['measure'] = rmS($it['measure'] ?? '', 4000);
            $row['effect_check'] = rmS($it['effect_check'] ?? '', 4000);
            // 成效確認（裁示與佐證）：有結果就一定要有日期、裁示與佐證（AS9100D 6.1.2 要評估措施有效性並留下證據）
            $es = rmS($it['effect_status'] ?? '', 12);
            if ($es !== '' && !isset(RM_EFFECT_STATUS[$es])) jerr('第 ' . ($n + 1) . ' 列的成效確認結果不正確');
            $row['effect_status'] = $es !== '' && $es !== 'unconfirmed' ? $es : null;
            $row['effect_date'] = rmDate($it['effect_date'] ?? '');
            $row['effect_ruling'] = rmS($it['effect_ruling'] ?? '', 4000);
            $row['effect_evidence'] = rmS($it['effect_evidence'] ?? '', 8000);
            $row['recheck_note'] = rmS($it['recheck_note'] ?? '', 2000);
            $row['recheck_date'] = rmDate($it['recheck_date'] ?? '');
            if ($row['recheck_date'] && $row['recheck_date'] > rmToday($db)) jerr('第 ' . ($n + 1) . ' 列的重新評估日期不可是未來日期');
            if ($row['recheck_note'] !== '' && !$row['recheck_date']) jerr('第 ' . ($n + 1) . ' 列已填重新評估原因，請填重新評估日期');
            $row['rule_ids'] = preg_replace('/[^A-Za-z0-9,\-]/', '', rmS($it['rule_ids'] ?? '', 200)) ?: null;
            if ($row['effect_date'] && $row['effect_date'] > rmToday($db)) jerr('第 ' . ($n + 1) . ' 列的成效確認日期不可是未來日期');
            if (in_array($row['effect_status'], ['effective', 'partial', 'ineffective'], true)) {
                if (!$row['effect_date']) jerr('第 ' . ($n + 1) . ' 列已填成效確認結果，請填確認日期');
                if ($row['effect_ruling'] === '') jerr('第 ' . ($n + 1) . ' 列已填成效確認結果，請填裁示');
                if ($row['effect_evidence'] === '') jerr('第 ' . ($n + 1) . ' 列已填成效確認結果，請填佐證（可按「依資料產生佐證」）');
            }
            $sv = ($it['severity'] ?? '') === '' ? null : (int)$it['severity'];
            $fq = ($it['frequency'] ?? '') === '' ? null : (int)$it['frequency'];
            if ($sv !== null && !in_array($sv, $sevOk, true)) jerr('第 ' . ($n + 1) . ' 列的嚴重度(S)不在設定的級距內');
            if ($fq !== null && !in_array($fq, $frqOk, true)) jerr('第 ' . ($n + 1) . ' 列的發生率(O)不在設定的級距內');
            if (($sv === null) !== ($fq === null)) jerr('第 ' . ($n + 1) . ' 列的嚴重度(S)與發生率(O)要一起填（缺一個算不出風險等級）');
            $row['severity'] = $sv; $row['frequency'] = $fq;
            $cx = (int)($it['ctx_item_id'] ?? 0);
            if ($cx > 0) {
                $q = $db->prepare("SELECT 1 FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id WHERE i.item_id=? AND d.kind='context' AND d.is_deleted=0");
                $q->execute([$cx]);
                if ($q->fetchColumn()) {
                    $row['ctx_item_id'] = $cx;
                    // 連動中的四個欄位以來源為準（不採信前端送來的值，避免畫面與來源對不起來）
                    $q2 = $db->prepare("SELECT scope, stakeholder, needs, issue FROM rm_item WHERE item_id=?");
                    $q2->execute([$cx]);
                    if ($c = $q2->fetch(PDO::FETCH_ASSOC)) {
                        $row['scope'] = $c['scope']; $row['stakeholder'] = (string)$c['stakeholder'];
                        $row['needs'] = (string)$c['needs']; $row['issue'] = (string)$c['issue'];
                    }
                }
            }
        }
        // 整列都沒填東西就略過（末列多按出來的空白列）
        $blank = $row['stakeholder'] === '' && $row['needs'] === '' && $row['issue'] === '' && $row['ro_text'] === '' && $row['effect_status'] === null && $row['effect_ruling'] === ''
              && $row['measure'] === '' && $row['effect_check'] === '' && ($kind === 'context' || $row['dimension'] === null) && $row['severity'] === null;
        if ($blank) continue;
        $row['_old_id'] = (int)($it['item_id'] ?? 0);
        $clean[] = $row;
    }
    if (!$clean) jerr('至少要填寫一列內容');
    if ($kind === 'context') {      // 組織處境分析表：四個象限每一項至少兩筆（有填議題描述的才算）
        $cnt = array_fill_keys(array_keys(RM_SWOT), 0);
        foreach ($clean as $r) if ($r['issue'] !== '' && isset($cnt[$r['dimension']])) $cnt[$r['dimension']]++;
        $lack = [];
        foreach ($cnt as $d => $c) if ($c < 2) $lack[] = (['S'=>'優勢','W'=>'劣勢','O'=>'機會','T'=>'威脅'][$d] ?? $d) . '（目前 ' . $c . ' 筆）';
        if ($lack) jerr('組織處境分析表每個象限至少要填兩筆議題，尚不足：' . implode('、', $lack));
    }

    $now = rmNow($db);
    $db->beginTransaction();
    try {
        if ($old) {
            $db->prepare("UPDATE rm_doc SET project_no=?, dept_ids=?, dept_names=?, fill_date=?, remark=?, updated_at=? WHERE doc_id=?")
               ->execute([rmS($_POST['project_no'] ?? '', 60) ?: null, json_encode($deptIds), $deptNames ?: null, $fillDate,
                          rmS($_POST['remark'] ?? '', 500) ?: null, $now, $id]);
            // 填表日期改了，單號跟著重編（單號前八碼＝表單日期）；只有真的換日期才重編，避免無謂跳號
            if (substr(preg_replace('/\D/', '', (string)$old['fill_date']), 0, 8) !== preg_replace('/\D/', '', $fillDate)) {
                $db->prepare("UPDATE rm_doc SET doc_no=? WHERE doc_id=?")->execute([rm_next_doc_no($db, $fillDate), $id]);
            }
        } else {
            $db->prepare("INSERT INTO rm_doc (doc_no, kind, project_no, dept_ids, dept_names, fill_date, remark,
                            created_by, created_by_name, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([rm_next_doc_no($db, $fillDate), $kind, rmS($_POST['project_no'] ?? '', 60) ?: null, json_encode($deptIds),
                          $deptNames ?: null, $fillDate, rmS($_POST['remark'] ?? '', 500) ?: null, $uid, $uname, $now, $now]);
            $id = (int)$db->lastInsertId();
        }

        // 既有列：沿用原本的風險確認編號（不可因重存而重配）
        $oldConfirm = [];
        if ($old) {
            $q = $db->prepare("SELECT item_id, confirm_no FROM rm_item WHERE doc_id=?");
            $q->execute([$id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r0) $oldConfirm[(int)$r0['item_id']] = (string)$r0['confirm_no'];
        }
        // 先不刪舊列（其他單據的 ctx_item_id 可能指著組織處境表的列）：依 item_id 對應更新，沒對到的新增，多出來的才刪
        $keepIds = [];
        $seq = 0;
        foreach ($clean as $row) {
            $seq++;
            $oid = $row['_old_id'];
            $isOld = $oid && isset($oldConfirm[$oid]);
            $confirm = $row['confirm_no'];
            if ($isOld && $oldConfirm[$oid] !== '' && $confirm === '') $confirm = $oldConfirm[$oid];
            if ($kind === 'risk' && $confirm === '') {
                $lv = rm_level_calc($set, $row['severity'], $row['frequency'], (string)$row['ro_kind']);
                if ($lv && $lv['action']) $confirm = rm_next_confirm_no($db, (string)$set['rm_confirm_prefix'], $fillDate);
            }
            $vals = [$seq, $row['scope'], $row['dimension'], $row['stakeholder'] ?: null, $row['needs'] ?: null, $row['issue'] ?: null,
                     $row['ro_kind'], $row['ro_text'] ?: null, $row['severity'], $row['frequency'], $confirm ?: null,
                     $row['measure'] ?: null, $row['effect_check'] ?: null, $row['ctx_item_id'],
                     $row['effect_status'], $row['effect_date'], $row['effect_ruling'] ?: null, $row['effect_evidence'] ?: null, $row['rule_ids'],
                     $row['recheck_note'] ?: null, $row['recheck_date']];
            if ($isOld) {
                $db->prepare("UPDATE rm_item SET seq=?, scope=?, dimension=?, stakeholder=?, needs=?, issue=?, ro_kind=?, ro_text=?,
                                severity=?, frequency=?, confirm_no=?, measure=?, effect_check=?, ctx_item_id=?,
                                effect_status=?, effect_date=?, effect_ruling=?, effect_evidence=?, rule_ids=?,
                                recheck_note=?, recheck_date=? WHERE item_id=? AND doc_id=?")
                   ->execute(array_merge($vals, [$oid, $id]));
                $keepIds[] = $oid;
            } else {
                $db->prepare("INSERT INTO rm_item (doc_id, seq, scope, dimension, stakeholder, needs, issue, ro_kind, ro_text,
                                severity, frequency, confirm_no, measure, effect_check, ctx_item_id,
                                effect_status, effect_date, effect_ruling, effect_evidence, rule_ids,
                                recheck_note, recheck_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute(array_merge([$id], $vals));
                $keepIds[] = (int)$db->lastInsertId();
            }
        }
        if ($old) {
            $in = implode(',', array_map('intval', $keepIds));
            // 被刪掉的處境列若已被風險表引用：解除引用（不阻擋刪除，引用只是方便帶入）
            $db->exec("UPDATE rm_item SET ctx_item_id=NULL WHERE ctx_item_id IN (SELECT item_id FROM (SELECT item_id FROM rm_item WHERE doc_id=$id AND item_id NOT IN ($in)) t)");
            $db->exec("DELETE FROM rm_item WHERE doc_id=$id AND item_id NOT IN ($in)");
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout(['doc_id'=>$id]);
}

/* ---------------------------------------------------------------- 刪除 */
if ($action === 'doc_delete') {
    rmReqAdmin($P);
    $r = rmDocGet($db, (int)($_POST['doc_id'] ?? 0));
    if (!$r) jerr('查無此表單', 404);
    $db->prepare("UPDATE rm_doc SET is_deleted=1, updated_at=? WHERE doc_id=?")->execute([rmNow($db), (int)$r['doc_id']]);
    jout();
}

/* ---------------------------------------------------------------- 模組設定 */
if ($action === 'setting_save') {
    rmReqAdmin($P);
    $arr = json_decode((string)($_POST['settings'] ?? ''), true);
    if (!is_array($arr)) jerr('設定內容不正確');
    $toWrite = []; $cur = rm_settings($db);
    $impMap = null; $likMap = null;
    if (array_key_exists('rm_impact', $arr)) {
        [$v, $e, $impMap] = rm_normalize_impact($arr['rm_impact']); if ($e) jerr($e); $toWrite['rm_impact'] = $v;
        // orig 一定要是目前真的有的等級（別人剛改過就請他重新整理，不然對應會錯）
        $curV = array_map(function ($r) { return (int)$r['v']; }, $cur['rm_impact']['rows']);
        foreach (array_keys($impMap) as $o) if (!in_array($o, $curV, true)) jerr('嚴重度(S)分析的等級已被其他人更新，請重新整理頁面後再修改', 409);
    }
    if (array_key_exists('rm_likelihood', $arr)) {
        [$v, $e, $likMap] = rm_normalize_likelihood($arr['rm_likelihood']); if ($e) jerr($e); $toWrite['rm_likelihood'] = $v;
        $curV = array_map(function ($r) { return (int)$r['v']; }, $cur['rm_likelihood']);
        foreach (array_keys($likMap) as $o) if (!in_array($o, $curV, true)) jerr('發生率(O)評估的等級已被其他人更新，請重新整理頁面後再修改', 409);
    }
    if (array_key_exists('rm_method', $arr)) {
        if (!in_array($arr['rm_method'], ['multiply', 'add', 'max'], true)) jerr('分數算法不正確');
        $toWrite['rm_method'] = $arr['rm_method'];
    }
    // 風險準則：最後一級的上限＝改完之後可能的最高分，所以一定要用「改完之後」的嚴重度(S)／發生率(O)／算法來算
    $newImpRows = ($toWrite['rm_impact'] ?? $cur['rm_impact'])['rows'];
    $newLikRows = $toWrite['rm_likelihood'] ?? $cur['rm_likelihood'];
    $newMethod  = $toWrite['rm_method'] ?? $cur['rm_method'];
    $maxScore   = rm_max_score($newImpRows, $newLikRows, $newMethod);
    $savedNames = array_map(function ($r) { return (string)$r['name']; }, $cur['rm_levels']);
    if (array_key_exists('rm_levels', $arr)) {
        [$v, $e] = rm_normalize_levels($arr['rm_levels'], $maxScore, $savedNames); if ($e) jerr($e); $toWrite['rm_levels'] = $v;
    } elseif (isset($toWrite['rm_impact']) || isset($toWrite['rm_likelihood']) || isset($toWrite['rm_method'])) {
        $re = array_map(function ($r) { return ['name'=>$r['name'], 'orig_name'=>$r['name'], 'hi'=>$r['hi'], 'measure'=>$r['measure'], 'action'=>$r['action']]; }, $cur['rm_levels']);
        [$v, $e] = rm_normalize_levels($re, $maxScore, $savedNames);
        if ($e) jerr('嚴重度(S)／發生率(O)／算法改動後，原本的風險準則已對不上：' . $e . '。請一併調整風險準則再儲存。');
        $toWrite['rm_levels'] = $v;
    }
    if (array_key_exists('rm_dimensions', $arr)) {
        [$v, $e] = rm_normalize_swot_names($arr['rm_dimensions']); if ($e) jerr($e); $toWrite['rm_dimensions'] = $v;
    }
    if (array_key_exists('rm_opp_terms', $arr)) {
        $t = (array)$arr['rm_opp_terms']; $cur2 = $cur['rm_opp_terms'] ?? [];
        $o = [];
        foreach (['sev', 'freq', 'level'] as $k) {
            $v = mb_substr(trim((string)($t[$k] ?? '')), 0, 12);
            if ($v === '') $v = (string)($cur2[$k] ?? '');
            if ($v === '') jerr('機會的欄位名稱不可留空');
            $o[$k] = $v;
        }
        $toWrite['rm_opp_terms'] = $o;
    }
    if (array_key_exists('rm_outsource_tags', $arr)) {
        $valid = array_map(function ($r) { return (int)$r['tag_id']; }, rm_audit_procs_view($db));
        $toWrite['rm_outsource_tags'] = array_values(array_unique(array_filter(array_map('intval', (array)$arr['rm_outsource_tags']), function ($t) use ($valid) { return in_array($t, $valid, true); })));
    }
    if (array_key_exists('rm_confirm_prefix', $arr)) {
        $p = preg_replace('/[^A-Za-z0-9\-]/', '', rmS($arr['rm_confirm_prefix'], 6));
        if ($p === '') jerr('風險確認編號的前綴要填英數字（最多 6 碼）');
        $toWrite['rm_confirm_prefix'] = $p;
    }
    // 嚴重度(S)／發生率(O)的等級是自動依順序編號：刪掉其中一級，後面的等級數字會往前補（不跳號）。
    // 既有風險資料存的是等級數字，所以要一併換成新數字；被刪掉那一級的資料沒有對應的新等級，會變成「未評估」——先講清楚再讓管理員決定
    $remap = [];     // [欄位 => [舊值 => 新值]]
    if ($impMap !== null) { $remap['severity']  = $impMap; $curVals['severity']  = array_map(function ($r) { return (int)$r['v']; }, $cur['rm_impact']['rows']); }
    if ($likMap !== null) { $remap['frequency'] = $likMap; $curVals['frequency'] = array_map(function ($r) { return (int)$r['v']; }, $cur['rm_likelihood']); }
    $changed = [];   // 真的需要動資料的欄位
    $lost = 0;
    foreach ($remap as $col => $map) {
        $identity = true;
        foreach ($map as $o => $n2) if ($o !== $n2) $identity = false;
        $deleted = array_values(array_diff($curVals[$col], array_keys($map)));
        if (!$identity || $deleted) $changed[$col] = ['map'=>$map, 'deleted'=>$deleted];
        if ($deleted) {
            $lost += (int)$db->query("SELECT COUNT(*) FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id WHERE d.is_deleted=0 AND i.$col IN (" . implode(',', array_map('intval', $deleted)) . ")")->fetchColumn();
        }
    }
    if ($lost > 0 && empty($_POST['force']))
        jerr('有 ' . $lost . ' 列既有風險資料用到「被你刪除的等級」，儲存後那些列會變成「未評估」（需要重新選嚴重度(S)與發生率(O)）。請確認後再存。', 409, ['code'=>'NEED_FORCE', 'bad'=>$lost]);
    $db->beginTransaction();
    try {
        foreach ($toWrite as $k => $v) rm_setting_write($db, $k, $v, $uname);
        foreach ($changed as $col => $ch) {
            $case = ''; foreach ($ch['map'] as $o => $n2) $case .= ' WHEN ' . (int)$o . ' THEN ' . (int)$n2;
            $db->exec("UPDATE rm_item SET $col = CASE $col$case ELSE NULL END WHERE $col IS NOT NULL");
        }
        if ($changed) $db->exec("UPDATE rm_item SET severity=NULL, frequency=NULL WHERE (severity IS NULL) <> (frequency IS NULL)");   // 嚴重度(S)與發生率(O)要一起有、一起沒有
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存設定失敗：' . $e->getMessage(), 500); }
    $set = rm_settings($db);
    jout(['settings'=>$set, 'levels'=>rm_levels_view($set), 'swot'=>rm_swot_view($set)]);
}

if ($action === 'asdoc_save') {
    rmReqAdmin($P);
    $which = rmS($_POST['which'] ?? '', 20);
    if (!isset(RM_ASDOC_MODULES[$which])) jerr('表單代碼不正確');
    eg_asdoc_save($db, RM_ASDOC_MODULES[$which]['module'], (int)($_POST['doc_id'] ?? 0), $uname);
    jout(['asdoc'=>rm_asdoc_all($db)]);
}

/* 列印紀錄（ai-rules/23）一律由前端走共用的 EGPrintLog.record()，本 API 不另開，避免一次列印記兩筆。 */
jerr('無效的操作：' . $action, 400);
