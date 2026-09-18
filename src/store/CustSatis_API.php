<?php
/**
 * 客戶滿意度（2-SM-02-03 統計資料表／2-SM-02-04 監控表）API —— 2026-09-18 建立
 *
 * 權限（cust_satis_lib.php cs_perms()，roles module='cust_satis'）：
 *   cs_admin 客戶滿意度管理員：填分數、綜合分析、維護監控表、改設定與 AS 綁定
 *   cs_view  檢閱：唯讀看全部（含列印）
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 *
 * 所有「自動指標」一律即時算（cust_satis_lib.php），不做快照——
 * 綁一張出貨單、補開一張退貨單，這裡的準交率與退貨率當下就要跟著變，
 * 存起來就會永遠停在存檔那天的數字，而且看不出來它是舊的。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/cust_satis_lib.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';
include_once $document_root . '/EGsystem/src/common/position_history_lib.php';

function jout($a = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    cs_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['cs_csrf'])) $_SESSION['cs_csrf'] = bin2hex(random_bytes(16));

$P = cs_perms($db, cs_current_user($db));
$uid = (int)$P['uid']; $uname = (string)$P['name'];
if (!$uid)            jerr('未登入或帳號非在職狀態', 401);
if (!$P['canView'])   jerr('您沒有客戶滿意度的檢閱權限，請洽管理員於「權限設定」開通', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* 寫入類先驗登入再驗 CSRF（順序不可顛倒，理由同其他模組：session 被 GC 掃掉時
   token 會在同一個請求裡重新產生、比對必定不過，但那是「已被登出」不是 CSRF 攻擊） */
$WRITE = ['score_save', 'score_cleanup', 'summary_save', 'monitor_save', 'setting_save', 'asdoc_save', 'print_log', 'cust_bind'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['cs_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code'=>'CSRF']);
    if (!$P['canAdmin']) jerr('需要「客戶滿意度管理員」權限才能修改', 403);
}

function csYQ(): array {
    $y = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
    $q = (int)($_GET['quarter'] ?? $_POST['quarter'] ?? 0);
    if ($y < 2000 || $y > 2100) $y = (int)date('Y');
    if ($q < 0 || $q > 4) $q = 0;
    return [$y, $q];
}
function csNum($v) {   // 分數：空字串＝沒填（null），不是 0 分
    if ($v === null || $v === '' ) return null;
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    return ($f < 0 || $f > 10) ? null : round($f, 1);
}

switch ($action) {

case 'csrf':
    jout(['csrf' => $_SESSION['cs_csrf']]);

/* ── 統計資料表（2-SM-02-03）───────────────────────────────── */
case 'stat_list': {
    list($y, $q) = csYQ();
    $rows = cs_stat_rows($db, $y, $q);
    $mode = cs_undone_mode($db, $y);
    jout([
        'rows'        => $rows,
        'summary'     => cs_summary_get($db, $y, $q),
        'year'        => $y, 'quarter' => $q,
        'period'      => cs_period_label($y, $q),
        'range'       => cs_period_range($y, $q),
        'undone_mode' => $mode,
        'undone_label'=> cs_undone_mode_label($mode),
        'perm'        => ['admin'=>$P['canAdmin']],
    ]);
}

case 'score_save': {
    list($y, $q) = csYQ();
    $cid  = trim((string)($_POST['customer_id'] ?? ''));
    $cnm  = trim((string)($_POST['customer_name'] ?? ''));
    if ($cnm === '') jerr('缺少客戶');
    // 客戶代號可能是空的（ERP 的出貨簡稱在主檔查不到那一家），一律走唯一實作補成 `#簡稱`
    $cid = cs_score_cid($cid, $cnm);
    /* 【只更新「有送過來」的欄位】
       用 array_key_exists 判有沒有送，不是判空值（本專案既有慣例，見 CLAUDE.md 製表人那次）：
         沒送這個欄位   ＝呼叫端不打算動它 → 保留原值
         送了空字串     ＝真的要清空（＝尚未填，不是 0 分）
       不這樣分的話，任何只想改一格的呼叫（或舊版前端）都會把另外四項連帶清掉，
       而且畫面上要重新載入才看得出來——測試時就真的踩到了這一點。 */
    $FIELDS = ['score_quality', 'score_delivery', 'score_tech', 'score_service', 'score_price'];
    $cur = [];
    try {
        $st = $db->prepare("SELECT * FROM cs_score WHERE year=? AND quarter=? AND customer_id=? LIMIT 1");
        $st->execute([$y, $q, $cid]);
        $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    $vals = [];
    foreach ($FIELDS as $f) {
        $vals[$f] = array_key_exists($f, $_POST)
            ? csNum($_POST[$f])
            : (isset($cur[$f]) ? csNum($cur[$f]) : null);
    }
    $remark = array_key_exists('remark', $_POST)
        ? mb_substr(trim((string)$_POST['remark']), 0, 500)
        : (string)($cur['remark'] ?? '');
    // 把「當下的自動指標」一起存成快照：事後稽核問「當初為什麼給 9 分」時要查得到依據
    $snap = null;
    foreach (cs_auto_metrics($db, $y, $q) as $a) {
        if ((string)$a['customer_id'] === $cid || $a['customer_name'] === $cnm) { $snap = $a; break; }
    }
    try {
        $db->beginTransaction();
        $id = (int)($cur['id'] ?? 0);   // 上面撈 $cur 時已經取到了，不再多查一次
        if ($id) {
            $db->prepare("UPDATE cs_score SET customer_name=?, score_quality=?, score_delivery=?, score_tech=?,
                          score_service=?, score_price=?, remark=?, metrics_json=?,
                          updated_at=NOW(), updated_by=?, updated_by_name=? WHERE id=?")
               ->execute([$cnm, $vals['score_quality'], $vals['score_delivery'], $vals['score_tech'],
                          $vals['score_service'], $vals['score_price'], $remark,
                          $snap ? json_encode($snap, JSON_UNESCAPED_UNICODE) : null, $uid, $uname, $id]);
        } else {
            $db->prepare("INSERT INTO cs_score (year,quarter,customer_id,customer_name,score_quality,score_delivery,
                          score_tech,score_service,score_price,remark,metrics_json,created_by,created_by_name)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$y, $q, $cid, $cnm, $vals['score_quality'], $vals['score_delivery'], $vals['score_tech'],
                          $vals['score_service'], $vals['score_price'], $remark,
                          $snap ? json_encode($snap, JSON_UNESCAPED_UNICODE) : null, $uid, $uname]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    $row = $vals; $row['avg_score'] = cs_avg($vals + ['score_quality'=>$vals['score_quality']]);
    jout(['avg' => cs_avg($vals)]);
}

/* 清掉「本期間根本沒有出貨」卻留著評分的列。
   這些列多半是舊版把「只有訂單、沒有出貨」的客戶（例 NA 這種代號沒建主檔的假客戶）也列進表裡，
   再按一次「帶入系統建議分」整批建出來的。**只刪系統帶出來的**：
   技術／服務／價格任一有填、或備註有字＝有人真的填過，一律保留並回報，不可以連人填的一起刪。 */
case 'score_cleanup': {
    list($y, $q) = csYQ();
    $keep = [];
    foreach (cs_auto_metrics($db, $y, $q) as $a)   // 鍵一律過 cs_score_cid（空代號＝`#簡稱`），
        $keep[cs_score_cid((string)$a['customer_id'], (string)$a['customer_name'])] = 1;   // 不然會把有往來的也判成要刪
    $del = []; $kept = 0; $names = [];
    try {
        $st = $db->prepare("SELECT * FROM cs_score WHERE year=? AND quarter=?");
        $st->execute([$y, $q]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($keep[cs_score_cid((string)$r['customer_id'], (string)$r['customer_name'])])) continue;
            $human = $r['score_tech'] !== null || $r['score_service'] !== null || $r['score_price'] !== null
                     || trim((string)$r['remark']) !== '';
            if ($human) { $kept++; continue; }
            $del[] = (int)$r['id']; $names[] = (string)$r['customer_name'];
        }
    } catch (Throwable $e) { jerr('讀取失敗：' . $e->getMessage(), 500); }
    if (!empty($_POST['dry'])) jout(['del'=>count($del), 'kept'=>$kept, 'names'=>array_slice($names, 0, 30)]);
    if ($del) {
        try {
            $in = implode(',', array_fill(0, count($del), '?'));
            $db->prepare("DELETE FROM cs_score WHERE id IN ($in)")->execute($del);
        } catch (Throwable $e) { jerr('刪除失敗：' . $e->getMessage(), 500); }
    }
    jout(['deleted'=>count($del), 'kept'=>$kept]);
}

/* 客戶主檔清單（綁定跳窗的挑選器用） */
case 'cust_master': {
    $rows = [];
    try {
        $st = $db->query("SELECT customer_id, customer, customer_full FROM customer_list
                          ORDER BY customer, customer_id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id'=>(string)$r['customer_id'], 'name'=>trim((string)$r['customer']),
                       'full'=>trim((string)$r['customer_full'])];
        }
    } catch (Throwable $e) { jerr('讀取客戶主檔失敗：' . $e->getMessage(), 500); }
    jout(['rows'=>$rows]);
}

/* 把 ERP 上查不到主檔的出貨簡稱綁到某一家客戶。
   **一律轉呼叫會計模組的 `acc_customer_alias_bind()`（唯一實作）**，不要在這裡另寫一份：
   它會同時寫 `acc_customer_alias`、**回填 `is_list.Client_id`**（只補原本是空的）並留稽核，
   而且別名是全站共用的——這裡綁一次，對帳、應收、發票資料那邊同時對得起來。 */
case 'cust_bind': {
    require_once $document_root . '/EGsystem/src/common/acc_lib.php';
    $alias = trim((string)($_POST['alias'] ?? ''));
    $cid   = trim((string)($_POST['customer_id'] ?? ''));
    if ($alias === '') jerr('缺少要綁定的出貨對象名稱');
    if ($cid === '')   jerr('請選擇要對應的客戶');
    $u = null;
    try {
        $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? LIMIT 1");
        $st->execute([$uid]); $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    $r = acc_customer_alias_bind($db, $alias, $cid, $u);
    if (empty($r['success'])) jerr((string)($r['message'] ?? '綁定失敗'));

    /* 綁定前這個名稱在本模組是「沒有客戶代號」的客戶，評分與監控表是用 `#簡稱` 當鍵存的；
       綁完之後它會併進主檔那一家（鍵變成客戶代號），舊列不搬就會變成孤兒——
       畫面上多一列「本期無出貨（先前已評分）」，而人填過的分數看起來像不見了。
       主檔那邊已經有同一期的資料時一律不覆蓋（那是另一份人填的），只回報有幾期沒搬。 */
    $oldKey = cs_score_cid('', $alias); $moved = 0; $skipped = 0;
    foreach (['cs_score', 'cs_monitor'] as $tb) {
        try {
            $st = $db->prepare("SELECT DISTINCT year, quarter FROM {$tb} WHERE customer_id=?");
            $st->execute([$oldKey]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $chk = $db->prepare("SELECT COUNT(*) FROM {$tb} WHERE year=? AND quarter=? AND customer_id=?");
                $chk->execute([(int)$p['year'], (int)$p['quarter'], $cid]);
                if ((int)$chk->fetchColumn() > 0) { $skipped++; continue; }
                $up = $db->prepare("UPDATE {$tb} SET customer_id=?, customer_name=?
                                    WHERE year=? AND quarter=? AND customer_id=?");
                $up->execute([$cid, (string)($r['customer'] ?? $alias), (int)$p['year'], (int)$p['quarter'], $oldKey]);
                $moved += $up->rowCount();
            }
        } catch (Throwable $e) {}
    }
    $msg = (string)$r['message'];
    if ($moved)   $msg .= "，並把 {$moved} 筆已填的評分／監控表改掛到這家客戶";
    if ($skipped) $msg .= "（有 {$skipped} 期主檔那邊本來就有資料，保留不覆蓋）";
    jout(['message'=>$msg, 'filled'=>(int)($r['filled'] ?? 0), 'moved'=>$moved, 'skipped'=>$skipped,
          'customer'=>(string)($r['customer'] ?? '')]);
}

case 'summary_save': {
    list($y, $q) = csYQ();
    $txt = mb_substr((string)($_POST['analysis_text'] ?? ''), 0, 4000);
    $dt  = trim((string)($_POST['stat_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) $dt = null;
    try {
        $st = $db->prepare("SELECT id FROM cs_summary WHERE year=? AND quarter=? LIMIT 1");
        $st->execute([$y, $q]); $id = (int)$st->fetchColumn();
        if ($id) $db->prepare("UPDATE cs_summary SET analysis_text=?, stat_date=?, updated_at=NOW(), updated_by=?, updated_by_name=? WHERE id=?")
                    ->execute([$txt, $dt, $uid, $uname, $id]);
        else     $db->prepare("INSERT INTO cs_summary (year,quarter,analysis_text,stat_date,updated_at,updated_by,updated_by_name) VALUES (?,?,?,?,NOW(),?,?)")
                    ->execute([$y, $q, $txt, $dt, $uid, $uname]);
    } catch (Throwable $e) { jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout();
}

/* ── 監控表（2-SM-02-04）──────────────────────────────────── */
case 'monitor_get': {
    list($y, $q) = csYQ();
    $cid = trim((string)($_GET['customer_id'] ?? ''));
    $cnm = trim((string)($_GET['customer_name'] ?? ''));
    if ($cnm === '' && $cid === '') jerr('請先選擇客戶');
    $rows = cs_monitor_rows($db, $y, $q, $cid, $cnm);
    $m = null;
    foreach (cs_auto_metrics($db, $y, $q) as $a) {
        if ((string)$a['customer_id'] === $cid || $a['customer_name'] === $cnm) { $m = $a; break; }
    }
    $sum = cs_summary_get($db, $y, $q);
    jout(['rows'=>$rows, 'metrics'=>$m, 'period'=>cs_period_label($y, $q),
          'monitor_date'=>$sum['stat_date'] ?? null,
          'auto_keys'=>['satis_score'=>'客戶滿意度平均分(換算百分)', 'car_count'=>'客戶開立異常處理單件數',
                        'ontime_rate'=>'準交率 %', 'return_rate'=>'退貨率 %', ''=>'（人工填寫）'],
          'perm'=>['admin'=>$P['canAdmin']]]);
}

case 'monitor_save': {
    list($y, $q) = csYQ();
    $cid = trim((string)($_POST['customer_id'] ?? ''));
    $cnm = trim((string)($_POST['customer_name'] ?? ''));
    if ($cnm === '') jerr('缺少客戶');
    $cid = cs_score_cid($cid, $cnm);
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('資料格式錯誤');
    if (count($items) > 30) jerr('調查項目最多 30 列');
    $dt = trim((string)($_POST['monitor_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) $dt = null;
    $okKeys = ['', 'satis_score', 'car_count', 'ontime_rate', 'return_rate'];
    try {
        $db->beginTransaction();
        // 整份取代：這張表是「一個客戶一期一份」，逐列 diff 沒有意義又容易留下孤兒列
        $db->prepare("DELETE FROM cs_monitor WHERE year=? AND quarter=? AND customer_id=?")->execute([$y, $q, $cid]);
        $ins = $db->prepare("INSERT INTO cs_monitor (year,quarter,customer_id,customer_name,sort_order,item_name,
                             target_text,auto_key,customer_suggestion,action_plan,effect_followup,car_no,
                             monitor_date,updated_at,updated_by,updated_by_name)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)");
        $i = 0;
        foreach ($items as $it) {
            $nm = mb_substr(trim((string)($it['item_name'] ?? '')), 0, 100);
            if ($nm === '') continue;   // 空白列直接略過，不要存出一堆沒有名稱的項目
            $ak = (string)($it['auto_key'] ?? '');
            if (!in_array($ak, $okKeys, true)) jerr('不支援的自動指標：' . $ak);   // 白名單（鐵律8）
            $ins->execute([$y, $q, $cid, $cnm, $i++, $nm,
                mb_substr(trim((string)($it['target_text'] ?? '')), 0, 100) ?: null,
                $ak !== '' ? $ak : null,
                mb_substr(trim((string)($it['customer_suggestion'] ?? '')), 0, 500) ?: null,
                mb_substr(trim((string)($it['action_plan'] ?? '')), 0, 500) ?: null,
                mb_substr(trim((string)($it['effect_followup'] ?? '')), 0, 500) ?: null,
                mb_substr(trim((string)($it['car_no'] ?? '')), 0, 40) ?: null,
                $dt, $uid, $uname]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout();
}

/* ── 列印中繼資料（ai-rules/16）────────────────────────────── */
case 'print_meta': {
    list($y, $q) = csYQ();
    $which = (string)($_GET['which'] ?? 'stat');
    $mod   = $which === 'monitor' ? CS_ASDOC_MONITOR : CS_ASDOC_STAT;
    $sum   = cs_summary_get($db, $y, $q);
    // 業務日期＝表單上的日期（沒填就用期間最後一天，不是用今天——補印舊期間才對得起來）
    $biz = $sum['stat_date'] ?: cs_period_range($y, $q)[1];
    $docId = eg_asdoc_id($db, $mod);
    $doc   = eg_asdoc_get($db, $mod);
    // 製表人的部門職稱依業務日期回推當時職務（ai-rules/22）
    $dept = ''; $pos = '';
    try {
        $snap = eg_position_snapshot_at($db, $uid, $biz);
        if ($snap) {
            $r = null;
            foreach ($snap as $x) { if (!empty($x['is_main'])) { $r = $x; break; } }
            if (!$r) $r = $snap[0];
            $dept = (string)($r['department_name'] ?? ''); $pos = (string)($r['position_name'] ?? '');
        }
    } catch (Throwable $e) {}
    jout([
        'company'      => cs_company_name($db),
        'doc'          => $doc ? ['id'=>(int)$doc['id'], 'doc_no'=>$doc['doc_no'], 'doc_name'=>$doc['doc_name']] : null,
        'doc_no_print' => eg_asdoc_no_asof_id($db, $docId, $biz),
        'biz_date'     => $biz,
        'stamp_tpl'    => cs_stamp_tpl($db),
        'maker_name'   => $uname,
        'maker'        => ['dept'=>$dept, 'position'=>$pos],
    ]);
}

case 'print_log': {
    $name = mb_substr(trim((string)($_POST['doc_name'] ?? '')), 0, 200);
    if ($name !== '') { try { eg_print_log_add($db, ['source'=>'cust_satis', 'doc_name'=>$name, 'doc_kind'=>'form']); } catch (Throwable $e) {} }
    jout();
}

/* ── 設定（限管理員）──────────────────────────────────────── */
case 'setting_get': {
    if (!$P['canAdmin']) jerr('需要管理員權限', 403);
    jout([
        'as_docs'        => eg_asdoc_list($db),
        'stat_doc_id'    => eg_asdoc_id($db, CS_ASDOC_STAT),
        'stat_doc'       => eg_asdoc_get($db, CS_ASDOC_STAT),
        'monitor_doc_id' => eg_asdoc_id($db, CS_ASDOC_MONITOR),
        'monitor_doc'    => eg_asdoc_get($db, CS_ASDOC_MONITOR),
        'grade_delivery' => cs_grade_delivery($db),
        'grade_quality'  => cs_grade_quality($db),
        'monitor_items'  => cs_monitor_default_items($db),
        'stamp_tpls'     => cs_stamp_tpl_options($db),
        'stamp_tpl_id'   => cs_stamp_tpl_id($db),
    ]);
}

case 'setting_save': {
    $gd = json_decode((string)($_POST['grade_delivery'] ?? ''), true);
    $gq = json_decode((string)($_POST['grade_quality']  ?? ''), true);
    $mi = json_decode((string)($_POST['monitor_items']  ?? ''), true);
    if (is_array($gd)) cs_param_save($db, 'grade_delivery', $gd, $uname);
    if (is_array($gq)) cs_param_save($db, 'grade_quality',  $gq, $uname);
    if (is_array($mi)) cs_param_save($db, 'monitor_items',  $mi, $uname);
    if (array_key_exists('stamp_tpl_id', $_POST)) {
        $t = (int)$_POST['stamp_tpl_id'];
        if ($t) {
            $c = $db->prepare("SELECT id FROM stamp_template WHERE id=? AND is_active=1"); $c->execute([$t]);
            if (!$c->fetchColumn()) jerr('選擇的圖章模板不存在或已停用');
        }
        cs_param_save($db, 'stamp_tpl_id', $t, $uname);
    }
    jout();
}

case 'asdoc_save': {
    $which = (string)($_POST['which'] ?? '');
    if (!in_array($which, ['stat', 'monitor'], true)) jerr('未知的文件類型');
    $id = (int)($_POST['doc_id'] ?? 0);
    if ($id) {
        $c = $db->prepare("SELECT id FROM as_document WHERE id=? AND is_deleted=0"); $c->execute([$id]);
        if (!$c->fetchColumn()) jerr('選擇的 AS 文件不存在或已刪除');
    }
    eg_asdoc_save($db, $which === 'monitor' ? CS_ASDOC_MONITOR : CS_ASDOC_STAT, $id, $uname);
    jout();
}

default:
    jerr('未知的 action：' . $action, 404);
}
