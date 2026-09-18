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
$WRITE = ['score_save', 'score_cleanup', 'score_clear_suggest', 'summary_save', 'monitor_save',
          'setting_save', 'asdoc_save', 'print_log', 'cust_bind',
          'target_save', 'survey_save', 'survey_upload', 'survey_file_assign', 'survey_file_delete'];
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
        'target_count'=> count(cs_targets($db, $y, $q)),   // 0＝還沒建名單（此時列出全部有往來客戶）
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

/* ── 問卷作業（2-SM-02-02）────────────────────────────────── */

/* 問卷題目、等第與分數對照、本期名單／回收狀況／附件，一次give前端 */
case 'survey_meta': {
    list($y, $q) = csYQ();
    $files = [];
    foreach (cs_survey_files($db, $y, $q) as $f) {
        $files[] = ['id'=>(int)$f['id'], 'customer_id'=>(string)($f['customer_id'] ?? ''),
                    'customer_name'=>(string)($f['customer_name'] ?? ''),
                    'orig_name'=>(string)$f['orig_name'], 'size'=>(int)$f['file_size'],
                    'at'=>(string)$f['created_at'], 'by'=>(string)($f['created_by_name'] ?? '')];
    }
    $tg = [];
    foreach (cs_targets($db, $y, $q) as $cid => $t)
        $tg[] = ['customer_id'=>(string)$t['customer_id'], 'customer_name'=>(string)$t['customer_name'],
                 'pick_reason'=>(string)($t['pick_reason'] ?? ''), 'sent_date'=>$t['sent_date']];
    jout(['questions'=>cs_survey_questions($db), 'levels'=>cs_survey_levels($db),
          'cats'=>cs_survey_cats(), 'targets'=>$tg, 'files'=>$files,
          'ship_stats'=>cs_ship_stats($db, $y, $q)]);
}

/* 設定本期受調查名單（整份取代——名單是一次挑好的，逐筆 diff 沒有意義） */
case 'target_save': {
    list($y, $q) = csYQ();
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('資料格式錯誤');
    if (count($items) > 500) jerr('一次最多 500 家');
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM cs_survey_target WHERE year=? AND quarter=?")->execute([$y, $q]);
        $ins = $db->prepare("INSERT INTO cs_survey_target
                             (year,quarter,customer_id,customer_name,pick_reason,created_by,created_by_name)
                             VALUES (?,?,?,?,?,?,?)");
        $n = 0; $seen = [];
        foreach ($items as $it) {
            $cnm = trim((string)($it['customer_name'] ?? ''));
            if ($cnm === '') continue;
            $cid = cs_score_cid((string)($it['customer_id'] ?? ''), $cnm);
            if (isset($seen[$cid])) continue;      // 同一家只留一列（唯一鍵也會擋，先擋在這裡訊息才看得懂）
            $seen[$cid] = 1;
            $ins->execute([$y, $q, $cid, $cnm, mb_substr((string)($it['pick_reason'] ?? 'manual'), 0, 50), $uid, $uname]);
            $n++;
        }
        $db->commit();
        jout(['saved'=>$n]);
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

/* 一份問卷的內容（含換算後的分數，前端即時預覽用） */
case 'survey_get': {
    list($y, $q) = csYQ();
    $cid = cs_score_cid((string)($_GET['customer_id'] ?? ''), (string)($_GET['customer_name'] ?? ''));
    $r = null;
    try {
        $st = $db->prepare("SELECT * FROM cs_survey WHERE year=? AND quarter=? AND customer_id=? LIMIT 1");
        $st->execute([$y, $q, $cid]); $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    jout(['survey'=>$r, 'answers'=>$r ? (json_decode((string)$r['answers_json'], true) ?: []) : []]);
}

/* 存一份問卷 → 同時把換算出來的五項分數寫進 cs_score（統計表的唯一顯示來源仍是 cs_score） */
case 'survey_save': {
    list($y, $q) = csYQ();
    $cnm = trim((string)($_POST['customer_name'] ?? ''));
    if ($cnm === '') jerr('缺少客戶');
    $cid  = cs_score_cid((string)($_POST['customer_id'] ?? ''), $cnm);
    $mode = (string)($_POST['mode'] ?? 'item');
    if (!in_array($mode, ['item', 'direct', 'total'], true)) jerr('未知的填答方式');
    $ans = json_decode((string)($_POST['answers'] ?? '{}'), true);
    if (!is_array($ans)) $ans = [];
    $sv = ['mode'=>$mode, 'answers'=>$ans,
           'total_score'   => ($_POST['total_score'] ?? '') === '' ? null : (float)$_POST['total_score'],
           'score_quality' => $_POST['score_quality']  ?? null, 'score_delivery'=> $_POST['score_delivery'] ?? null,
           'score_tech'    => $_POST['score_tech']     ?? null, 'score_service' => $_POST['score_service']  ?? null,
           'score_price'   => $_POST['score_price']    ?? null];
    $sc = cs_survey_scores($db, $sv);
    $rd = trim((string)($_POST['reply_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd)) $rd = null;
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO cs_survey (year,quarter,customer_id,customer_name,mode,answers_json,total_score,
                        respondent,respondent_title,reply_date,comment_text,created_by,created_by_name,updated_at,updated_by,updated_by_name)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)
                      ON DUPLICATE KEY UPDATE mode=VALUES(mode), answers_json=VALUES(answers_json),
                        total_score=VALUES(total_score), respondent=VALUES(respondent),
                        respondent_title=VALUES(respondent_title), reply_date=VALUES(reply_date),
                        comment_text=VALUES(comment_text), customer_name=VALUES(customer_name),
                        updated_at=NOW(), updated_by=VALUES(updated_by), updated_by_name=VALUES(updated_by_name)")
           ->execute([$y, $q, $cid, $cnm, $mode, json_encode($ans, JSON_UNESCAPED_UNICODE), $sv['total_score'],
                      mb_substr(trim((string)($_POST['respondent'] ?? '')), 0, 50),
                      mb_substr(trim((string)($_POST['respondent_title'] ?? '')), 0, 50), $rd,
                      mb_substr(trim((string)($_POST['comment_text'] ?? '')), 0, 2000),
                      $uid, $uname, $uid, $uname]);

        // 換算出來的分數寫進 cs_score：**只寫算得出來的那幾項**，算不出來的保持原值不清掉
        $cur = [];
        $st = $db->prepare("SELECT * FROM cs_score WHERE year=? AND quarter=? AND customer_id=? LIMIT 1");
        $st->execute([$y, $q, $cid]); $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $vals = [];
        foreach (['score_quality','score_delivery','score_tech','score_service','score_price'] as $f)
            $vals[$f] = $sc[$f] !== null ? $sc[$f] : (isset($cur[$f]) ? cs_score_norm($cur[$f]) : null);
        $db->prepare("INSERT INTO cs_score (year,quarter,customer_id,customer_name,
                        score_quality,score_delivery,score_tech,score_service,score_price,
                        created_by,created_by_name,updated_at,updated_by,updated_by_name)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)
                      ON DUPLICATE KEY UPDATE score_quality=VALUES(score_quality), score_delivery=VALUES(score_delivery),
                        score_tech=VALUES(score_tech), score_service=VALUES(score_service),
                        score_price=VALUES(score_price), customer_name=VALUES(customer_name),
                        updated_at=NOW(), updated_by=VALUES(updated_by), updated_by_name=VALUES(updated_by_name)")
           ->execute([$y, $q, $cid, $cnm, $vals['score_quality'], $vals['score_delivery'], $vals['score_tech'],
                      $vals['score_service'], $vals['score_price'], $uid, $uname, $uid, $uname]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout(['scores'=>$sc]);
}

/* 清除系統建議分：把「品質／交期」兩欄清成空白（那兩項應該由客戶問卷來填，見使用說明）。
   技術／服務／價格與備註一律不動——那是人填的。 */
case 'score_clear_suggest': {
    list($y, $q) = csYQ();
    try {
        /* **有回收問卷的那幾家不可以清**——他們的品質／交期分是客戶填在問卷上的數值，
           不是系統建議分。這兩者在 cs_score 裡長得一模一樣，唯一分得出來的依據就是有沒有 cs_survey。 */
        $up = $db->prepare("UPDATE cs_score s SET s.score_quality=NULL, s.score_delivery=NULL,
                              s.updated_at=NOW(), s.updated_by=?, s.updated_by_name=?
                            WHERE s.year=? AND s.quarter=?
                              AND (s.score_quality IS NOT NULL OR s.score_delivery IS NOT NULL)
                              AND NOT EXISTS (SELECT 1 FROM cs_survey v
                                               WHERE v.year=s.year AND v.quarter=s.quarter
                                                 AND v.customer_id=s.customer_id)");
        $up->execute([$uid, $uname, $y, $q]);
        $n = $up->rowCount();
        $kept = 0;
        try {
            $c = $db->prepare("SELECT COUNT(*) FROM cs_score s JOIN cs_survey v
                                 ON v.year=s.year AND v.quarter=s.quarter AND v.customer_id=s.customer_id
                               WHERE s.year=? AND s.quarter=?
                                 AND (s.score_quality IS NOT NULL OR s.score_delivery IS NOT NULL)");
            $c->execute([$y, $q]); $kept = (int)$c->fetchColumn();
        } catch (Throwable $e) {}
        // 清完變成整列都沒有資料的（沒分數也沒備註）就一併刪掉，不要留一堆空殼列
        $db->prepare("DELETE FROM cs_score WHERE year=? AND quarter=?
                        AND score_quality IS NULL AND score_delivery IS NULL AND score_tech IS NULL
                        AND score_service IS NULL AND score_price IS NULL AND TRIM(COALESCE(remark,''))=''")
           ->execute([$y, $q]);
        jout(['cleared'=>$n, 'kept'=>$kept]);
    } catch (Throwable $e) { jerr('清除失敗：' . $e->getMessage(), 500); }
}

/* 上傳客戶寄回來的問卷（可一次多檔；先傳進來，客戶之後再逐份指定）。
   檔案放共用附件根目錄底下的「客戶滿意度」資料夾，**DB 只存檔名**（鐵律5）。 */
case 'survey_upload': {
    list($y, $q) = csYQ();
    if (empty($_FILES['files'])) jerr('沒有收到檔案');
    $dir = cs_attach_dir($db);
    if (!is_dir($dir)) jerr('附件資料夾建立失敗，請確認 NAS 路徑設定');
    $OK = ['pdf','jpg','jpeg','png','gif','bmp','tif','tiff','doc','docx','xls','xlsx'];
    $f = $_FILES['files'];
    $cnt = is_array($f['name']) ? count($f['name']) : 0;
    if ($cnt < 1) jerr('沒有收到檔案');
    if ($cnt > 30) jerr('一次最多 30 個檔案');
    $done = []; $skip = [];
    for ($i = 0; $i < $cnt; $i++) {
        $orig = (string)$f['name'][$i];
        if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) { $skip[] = $orig . '（上傳失敗）'; continue; }
        if ((int)$f['size'][$i] > 30 * 1024 * 1024) { $skip[] = $orig . '（超過 30MB）'; continue; }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $OK, true)) { $skip[] = $orig . '（不支援的檔案類型）'; continue; }
        $new = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'][$i], rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $new)) {
            $skip[] = $orig . '（寫入失敗）'; continue;
        }
        try {
            $db->prepare("INSERT INTO cs_survey_file (year,quarter,file_name,orig_name,file_size,created_by,created_by_name)
                          VALUES (?,?,?,?,?,?,?)")
               ->execute([$y, $q, $new, mb_substr($orig, 0, 190), (int)$f['size'][$i], $uid, $uname]);
            $done[] = ['id'=>(int)$db->lastInsertId(), 'orig_name'=>$orig];
        } catch (Throwable $e) { $skip[] = $orig . '（寫入資料庫失敗）'; }
    }
    jout(['uploaded'=>$done, 'skipped'=>$skip]);
}

/* 指定某個附件是哪一家客戶的（傳完再逐份指定；也可以改指定或清空） */
case 'survey_file_assign': {
    list($y, $q) = csYQ();
    $id  = (int)($_POST['id'] ?? 0);
    $cnm = trim((string)($_POST['customer_name'] ?? ''));
    if (!$id) jerr('缺少附件');
    $cid = $cnm === '' ? null : cs_score_cid((string)($_POST['customer_id'] ?? ''), $cnm);
    try {
        $st = $db->prepare("UPDATE cs_survey_file SET customer_id=?, customer_name=? WHERE id=? AND year=? AND quarter=?");
        $st->execute([$cid, ($cnm === '' ? null : $cnm), $id, $y, $q]);
        if (!$st->rowCount()) {
            $c = $db->prepare("SELECT COUNT(*) FROM cs_survey_file WHERE id=?"); $c->execute([$id]);
            if (!$c->fetchColumn()) jerr('找不到這個附件（可能已被刪除，請重新整理）');
        }
    } catch (Throwable $e) { jerr('指定失敗：' . $e->getMessage(), 500); }
    jout(['customer_id'=>$cid, 'customer_name'=>$cnm]);
}

/* 刪除附件（連同磁碟上的檔案；檔案不在也照樣刪掉資料列，不然畫面上永遠清不掉） */
case 'survey_file_delete': {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jerr('缺少附件');
    try {
        $st = $db->prepare("SELECT file_name FROM cs_survey_file WHERE id=? LIMIT 1");
        $st->execute([$id]); $fn = $st->fetchColumn();
        if ($fn === false) jerr('找不到這個附件');
        $p = rtrim(cs_attach_dir($db), "\\/") . DIRECTORY_SEPARATOR . (string)$fn;
        if (is_file($p)) @unlink($p);
        $db->prepare("DELETE FROM cs_survey_file WHERE id=?")->execute([$id]);
    } catch (Throwable $e) { jerr('刪除失敗：' . $e->getMessage(), 500); }
    jout();
}

/* 下載／預覽附件（路徑一律由設定值即時組，檔名只准單純檔名） */
case 'survey_file_get': {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jerr('缺少附件');
    $r = null;
    try {
        $st = $db->prepare("SELECT file_name, orig_name FROM cs_survey_file WHERE id=? LIMIT 1");
        $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    if (!$r) jerr('找不到這個附件', 404);
    $fn = (string)$r['file_name'];
    if ($fn === '' || $fn !== basename($fn) || strpos($fn, '..') !== false) jerr('檔名不合法', 400);
    $p = rtrim(cs_attach_dir($db), "\\/") . DIRECTORY_SEPARATOR . $fn;
    if (!is_file($p)) jerr('檔案不存在（可能已被移動或刪除）', 404);
    require_once $document_root . '/EGsystem/src/common/attach_lib.php';
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $mime = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
             'gif'=>'image/gif','bmp'=>'image/bmp','tif'=>'image/tiff','tiff'=>'image/tiff'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($p));
    eg_attach_send_disposition((string)$r['orig_name']);
    readfile($p);
    exit;
}

/* 客戶主檔清單（綁定跳窗的挑選器用） */
case 'cust_master': {
    $rows = [];
    try {
        $st = $db->query("SELECT customer_id, customer, customer_full, customer_tel, customer_fax
                          FROM customer_list ORDER BY customer, customer_id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id'=>(string)$r['customer_id'], 'name'=>trim((string)$r['customer']),
                       'full'=>trim((string)$r['customer_full']),
                       // 問卷下方的電話／傳真由客戶基本資料帶出（使用者指定，不必人工填）
                       'tel'=>trim((string)($r['customer_tel'] ?? '')),
                       'fax'=>trim((string)($r['customer_fax'] ?? ''))];
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
    $mod   = $which === 'monitor' ? CS_ASDOC_MONITOR : ($which === 'survey' ? CS_ASDOC_SURVEY : CS_ASDOC_STAT);
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
        'own'          => cs_own_company($db),     // 問卷抬頭的服務電話／回傳傳真（禁寫死）
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
        'survey_doc_id'  => eg_asdoc_id($db, CS_ASDOC_SURVEY),
        'survey_doc'     => eg_asdoc_get($db, CS_ASDOC_SURVEY),
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
    if (!in_array($which, ['stat', 'monitor', 'survey'], true)) jerr('未知的文件類型');
    $id = (int)($_POST['doc_id'] ?? 0);
    if ($id) {
        $c = $db->prepare("SELECT id FROM as_document WHERE id=? AND is_deleted=0"); $c->execute([$id]);
        if (!$c->fetchColumn()) jerr('選擇的 AS 文件不存在或已刪除');
    }
    eg_asdoc_save($db, $which === 'monitor' ? CS_ASDOC_MONITOR
                       : ($which === 'survey' ? CS_ASDOC_SURVEY : CS_ASDOC_STAT), $id, $uname);
    jout();
}

default:
    jerr('未知的 action：' . $action, 404);
}
