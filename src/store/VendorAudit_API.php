<?php
/**
 * 供應商稽核管理 API（稽核批次模型）
 * 權限：vendor_audit_lib.php vendor_audit_perms()（roles module='vendor_audit'；admin⊃edit⊃view），fail-closed
 * 讀：GET；寫：POST，transaction。廠商主檔沿用 maker_list（本頁只維護稽核旗標/對象/紀錄）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/vendor_audit_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    vendor_audit_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = vendor_audit_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = vendor_audit_perms($db, $u);
if (!$perms['canView']) jerr('無供應商稽核檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 大類篩選條件（比照 master_data 廠商分頁：主檔大類 或 小類經階層歸屬） */
function va_maincat_cond(int $mainCat, array &$bind): string {
    $bind[] = $mainCat; $bind[] = $mainCat;
    return "(m.main_category_id = ? OR EXISTS (SELECT 1 FROM maker_sub_category_mapping mp
              JOIN maker_category_hierarchy h ON h.sub_cat_id=mp.sub_cat_id
              WHERE mp.maker_id_no=m.maker_id_no AND h.main_cat_id=?))";
}
function va_subcat_cond(int $subCat, array &$bind): string {
    $bind[] = $subCat;
    return "EXISTS (SELECT 1 FROM maker_sub_category_mapping mp2 WHERE mp2.maker_id_no=m.maker_id_no AND mp2.sub_cat_id=?)";
}
/** 逗號或陣列 → 乾淨字串陣列 */
function va_ids($v): array {
    if (is_array($v)) $arr = $v; else $arr = explode(',', (string)$v);
    $out = [];
    foreach ($arr as $x) { $x = trim((string)$x); if ($x !== '') $out[] = $x; }
    return array_values(array_unique($out));
}

switch ($action) {

case 'meta': {
    $cats = $db->query("SELECT main_cat_id, main_cat_name FROM dict_maker_main_category
                        WHERE is_active=1 ORDER BY sort_order, main_cat_id")->fetchAll(PDO::FETCH_ASSOC);
    jout(['perms'=>$perms, 'cur_year'=>(int)date('Y'), 'cur_month'=>(int)date('n'),
          'cur_half'=>((int)date('n') <= 6 ? 1 : 2), 'today'=>date('Y-m-d'),
          'cycle_months'=>vendor_audit_cycle_months($db), 'main_categories'=>$cats,
          'attach_base'=>vendor_eval_setting($db, 'vendor_audit_attach_base', ''),
          'as_doc'=>vendor_audit_bound_asdoc($db),
          'eval_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_eval_as_doc_id'),
          'record_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_record_as_doc_id'),
          'roster_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_roster_as_doc_id'),
          'company_name'=>vendor_audit_company_name($db),
          'items'=>vendor_audit_items(), 'item_max'=>VENDOR_AUDIT_ITEM_MAX,
          'total_max'=>VENDOR_AUDIT_TOTAL_MAX, 'pass_rate'=>VENDOR_AUDIT_PASS_RATE,
          'self_w'=>VENDOR_AUDIT_SELF_W, 'audit_w'=>VENDOR_AUDIT_AUDIT_W,
          'eval_settings'=>vendor_eval_settings($db)]);
}

/* AS 表單清單（綁定列印表單名稱/編號用） */
case 'as_forms': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    try {
        $sql = "SELECT id, doc_no, doc_name FROM as_document
                WHERE doc_type='表單' AND (is_deleted IS NULL OR is_deleted=0)";
        $bind = [];
        if ($kw !== '') { $sql .= " AND (doc_no LIKE ? OR doc_name LIKE ?)"; $bind[] = "%$kw%"; $bind[] = "%$kw%"; }
        $sql .= " ORDER BY doc_no LIMIT 300";
        $st = $db->prepare($sql); $st->execute($bind);
        jout(['forms'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) { jout(['forms'=>[], 'note'=>'AS文件表無法讀取：'.$e->getMessage()]); }
}

/* 定期評核：廠商下拉（納管非停用；有關鍵字則全廠搜尋） */
case 'eval_vendors': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        $st = $db->prepare("SELECT maker_id_no, maker_id FROM maker_list
                            WHERE (status IS NULL OR status<>?) AND (maker_id LIKE ? OR maker_id_no LIKE ? OR maker_id_all LIKE ?)
                            ORDER BY maker_id LIMIT 50");
        $st->execute([VENDOR_AUDIT_DISABLED, "%$kw%", "%$kw%", "%$kw%"]);
    } else {
        $st = $db->prepare("SELECT maker_id_no, maker_id FROM maker_list
                            WHERE (status IS NULL OR status<>?) AND audit_managed=1 ORDER BY maker_id LIMIT 300");
        $st->execute([VENDOR_AUDIT_DISABLED]);
    }
    jout(['vendors'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* 定期評核：某廠商×年度 月不良率/特採率/遲交率 + 半年判定 */
case 'periodic_eval': {
    $mid = trim((string)($_GET['maker_id_no'] ?? ''));
    $year = (int)($_GET['year'] ?? date('Y'));
    if ($mid === '') jerr('請選擇廠商');
    $mk = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no=?");
    $mk->execute([$mid]);
    $name = $mk->fetchColumn();
    if ($name === false) jerr('找不到廠商');
    $set = vendor_eval_settings($db);
    $res = vendor_periodic_eval($db, $mid, $year, $set);
    jout(['maker_id_no'=>$mid, 'maker_name'=>$name, 'year'=>$year, 'settings'=>$set,
          'months'=>$res['months'], 'halves'=>$res['halves']]);
}

/* 定期評核：全部納管廠商（自動略過整年無資料者） */
case 'periodic_eval_all': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $set = vendor_eval_settings($db);
    $mk = $db->query("SELECT maker_id_no, maker_id FROM maker_list
                      WHERE (status IS NULL OR status<>'" . VENDOR_AUDIT_DISABLED . "') AND audit_managed=1 ORDER BY maker_id")
             ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($mk as $m) {
        $res = vendor_periodic_eval($db, $m['maker_id_no'], $year, $set);
        $h1 = $res['halves'][1]; $h2 = $res['halves'][2];
        if (($h1['qc_in'] + $h1['del_in'] + $h2['qc_in'] + $h2['del_in']) === 0) continue; // 整年無資料→略過
        $fail = ($h1['judge'] === 'fail' || $h2['judge'] === 'fail');
        $out[] = ['maker_id_no'=>$m['maker_id_no'], 'maker_name'=>$m['maker_id'],
                  'months'=>$res['months'], 'halves'=>$res['halves'], 'fail'=>$fail];
    }
    jout(['year'=>$year, 'settings'=>$set, 'vendors'=>$out]);
}

/* ===== 合格供應商清冊（2-PH-01-04）===== */
/* 清冊清單：納管 或 手動列入(in_roster)，非停用；含定期評核建議等級 + 手動覆寫 */
case 'roster_list': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $set = vendor_eval_settings($db);
    $rows = $db->query("SELECT m.maker_id_no, m.maker_id, m.m_note, m.audit_managed, m.in_roster, m.roster_grade, m.main_category_id,
                               dc.main_cat_name
                        FROM maker_list m LEFT JOIN dict_maker_main_category dc ON dc.main_cat_id=m.main_category_id
                        WHERE (m.status IS NULL OR m.status<>'" . VENDOR_AUDIT_DISABLED . "') AND (m.audit_managed=1 OR m.in_roster=1)
                        ORDER BY m.main_category_id IS NULL, m.main_category_id, m.maker_id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $ev = vendor_periodic_eval($db, $r['maker_id_no'], $year, $set);
        $sg = $ev['full']['grade']; $ss = $ev['full']['score'];
        $out[] = [
            'maker_id_no'=>$r['maker_id_no'], 'maker_id'=>$r['maker_id'], 'm_note'=>$r['m_note'],
            'main_cat_name'=>$r['main_cat_name'], 'is_managed'=>(int)$r['audit_managed'], 'in_roster'=>(int)$r['in_roster'],
            'suggest_grade'=>$sg, 'suggest_score'=>$ss, 'roster_grade'=>$r['roster_grade'],
            'final_grade'=>($r['roster_grade'] !== null && $r['roster_grade'] !== '') ? $r['roster_grade'] : $sg,
        ];
    }
    jout(['year'=>$year, 'settings'=>$set, 'rows'=>$out]);
}
/* 加入清冊（手動列入非納管廠商） */
case 'roster_add': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $ids = va_ids($_POST['maker_ids'] ?? '');
    if (!$ids) jerr('請選擇廠商');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE maker_list SET in_roster=1 WHERE maker_id_no IN ($ph)")->execute($ids);
    jout(['added'=>count($ids)]);
}
/* 移出清冊（僅清 in_roster；納管廠商仍會因 audit_managed 留在清冊） */
case 'roster_remove': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $mid = trim((string)($_POST['maker_id_no'] ?? ''));
    if ($mid==='') jerr('缺少廠商');
    $db->prepare("UPDATE maker_list SET in_roster=0 WHERE maker_id_no=?")->execute([$mid]);
    jout([]);
}
/* 批次設定/清除採用等級（覆寫建議；grade 空=清除改用建議值） */
case 'roster_set_grade': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $ids = va_ids($_POST['maker_ids'] ?? '');
    if (!$ids) jerr('請選擇廠商');
    $g = trim((string)($_POST['grade'] ?? '')) ?: null;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE maker_list SET roster_grade=? WHERE maker_id_no IN ($ph)")->execute(array_merge([$g], $ids));
    jout(['updated'=>count($ids)]);
}

/* 兩年未交易外包廠（有 bom_ing 發包史但最後發包 >2 年）：納管或在冊者，供確認後移除 */
case 'stale_vendors': {
    $st = $db->query("SELECT m.maker_id_no, m.maker_id, m.audit_managed, m.in_roster, dc.main_cat_name,
                             MAX(bi.outsource_date) AS last_date
                      FROM maker_list m
                      JOIN bom_ing bi ON bi.maker_id_no=m.maker_id_no AND bi.outsource_date IS NOT NULL
                      LEFT JOIN dict_maker_main_category dc ON dc.main_cat_id=m.main_category_id
                      WHERE (m.status IS NULL OR m.status<>'" . VENDOR_AUDIT_DISABLED . "')
                        AND (m.audit_managed=1 OR m.in_roster=1)
                      GROUP BY m.maker_id_no, m.maker_id, m.audit_managed, m.in_roster, dc.main_cat_name
                      HAVING MAX(bi.outsource_date) < DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
                      ORDER BY last_date");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['audit_managed']=(int)$r['audit_managed']; $r['in_roster']=(int)$r['in_roster']; $r['last_date']=substr((string)$r['last_date'],0,10); }
    jout(['rows'=>$rows, 'cutoff'=>date('Y-m-d', strtotime('-2 years'))]);
}
/* 確認移除：取消納管+移出清冊，並刪未稽核之稽核對象(已稽核者保留可追溯) */
case 'stale_remove': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $ids = va_ids($_POST['maker_ids'] ?? '');
    if (!$ids) jerr('請選擇廠商');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE maker_list SET audit_managed=0, in_roster=0 WHERE maker_id_no IN ($ph)")->execute($ids);
        $db->prepare("DELETE FROM vendor_audit_target WHERE audit_date IS NULL AND maker_id_no IN ($ph)")->execute($ids);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('移除失敗：'.$e->getMessage(), 500); }
    jout(['removed'=>count($ids)]);
}

/* 定期評核門檻設定（管理員） */
case 'save_eval_settings': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    vendor_eval_save_settings($db, [
        'vendor_eval_ng_max'      => max(0, (float)($_POST['ng_max'] ?? 5)),
        'vendor_eval_special_max' => max(0, (float)($_POST['special_max'] ?? 100)),
        'vendor_eval_late_max'    => max(0, (float)($_POST['late_max'] ?? 30)),
        'vendor_eval_default_days'=> max(0, (int)($_POST['default_days'] ?? 7)),
    ]);
    if (array_key_exists('grades', $_POST)) {
        $g = json_decode((string)$_POST['grades'], true);
        if (is_array($g)) vendor_eval_save_grades($db, $g);
    }
    jout(['settings'=>vendor_eval_settings($db)]);
}

/* 某大類下的加工項目(小類) */
case 'subcats': {
    $mainCat = (int)($_GET['main_cat_id'] ?? 0);
    if ($mainCat <= 0) jout(['subcats'=>[]]);
    $st = $db->prepare("SELECT s.sub_cat_id, s.sub_cat_name
                        FROM maker_category_hierarchy h
                        JOIN dict_maker_sub_category s ON s.sub_cat_id=h.sub_cat_id AND s.is_active=1
                        WHERE h.main_cat_id=? ORDER BY h.sort_order, s.sub_cat_id");
    $st->execute([$mainCat]);
    jout(['subcats'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* 本期對象 + 統計 + 提醒 */
case 'round': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $half = (int)($_GET['half'] ?? ((int)date('n') <= 6 ? 1 : 2));
    if ($half !== 1 && $half !== 2) $half = 1;
    $rid = vendor_audit_round_id($db, $year, $half, false);

    $targets = [];
    if ($rid !== null) {
        $st = $db->prepare("SELECT t.target_id, t.maker_id_no, t.audit_date, t.auditor, t.plan_month,
                                   t.report_no, t.note, t.added_by_name,
                                   t.overall_rate, t.self_rate, t.audit_rate, t.judge, t.audit_mode, t.conclusion,
                                   m.maker_id, m.status, dc.main_cat_name
                            FROM vendor_audit_target t
                            JOIN maker_list m ON m.maker_id_no=t.maker_id_no
                            LEFT JOIN dict_maker_main_category dc ON dc.main_cat_id=m.main_category_id
                            WHERE t.round_id=? ORDER BY t.audit_date IS NOT NULL, m.maker_id");
        $st->execute([$rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['disabled'] = ($r['status'] === VENDOR_AUDIT_DISABLED);
            $targets[] = $r;
        }
    }
    $stat = vendor_audit_kpi_compute($db, $year, $half === 1 ? 6 : 12, []);

    // 提醒：最近一期 + 週期
    $cyc = vendor_audit_cycle_months($db);
    $lastRow = $db->query("SELECT year, half FROM vendor_audit_round ORDER BY year DESC, half DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    jout(['year'=>$year, 'half'=>$half, 'round_exists'=>$rid !== null,
          'targets'=>$targets, 'stat'=>$stat, 'perms'=>$perms,
          'cycle_months'=>$cyc, 'last_round'=>$lastRow ?: null]);
}

/* 廠商池：可加入本期的候選（非停用；可選僅納管；大類/加工項目/關鍵字篩選；排除已在本期者） */
case 'pool': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $half = (int)($_GET['half'] ?? 1);
    $rid = vendor_audit_round_id($db, $year, $half, false);
    $mainCat = (int)($_GET['main_cat_id'] ?? 0);
    $subCat = (int)($_GET['sub_cat_id'] ?? 0);
    $kw = trim((string)($_GET['kw'] ?? ''));
    $managedOnly = (int)($_GET['managed_only'] ?? 0) === 1;

    $where = ["(m.status IS NULL OR m.status<>?)"];
    $bind = [VENDOR_AUDIT_DISABLED];
    if ($managedOnly) $where[] = "m.audit_managed=1";
    if ($mainCat > 0) $where[] = va_maincat_cond($mainCat, $bind);
    if ($subCat > 0)  $where[] = va_subcat_cond($subCat, $bind);
    if ($kw !== '') { $where[] = "(m.maker_id LIKE ? OR m.maker_id_no LIKE ? OR m.maker_id_all LIKE ?)";
                      $bind[] = "%$kw%"; $bind[] = "%$kw%"; $bind[] = "%$kw%"; }
    if ($rid !== null) { $where[] = "NOT EXISTS (SELECT 1 FROM vendor_audit_target t WHERE t.round_id=? AND t.maker_id_no=m.maker_id_no)";
                         $bind[] = $rid; }

    $sql = "SELECT m.maker_id_no, m.maker_id, m.audit_managed, dc.main_cat_name
            FROM maker_list m
            LEFT JOIN dict_maker_main_category dc ON dc.main_cat_id=m.main_category_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.audit_managed DESC, m.maker_id LIMIT 501";
    $st = $db->prepare($sql);
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $capped = count($rows) > 500;
    if ($capped) array_pop($rows);
    foreach ($rows as &$r2) $r2['audit_managed'] = (int)$r2['audit_managed'];
    jout(['pool'=>$rows, 'capped'=>$capped]);
}

/* 多選加入本期對象 */
case 'add_targets': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $year = (int)($_POST['year'] ?? 0);
    $half = (int)($_POST['half'] ?? 0);
    if ($year < 2000 || ($half !== 1 && $half !== 2)) jerr('期別不正確');
    $ids = va_ids($_POST['maker_ids'] ?? '');
    if (!$ids) jerr('請選擇廠商');
    $pm = (int)($_POST['plan_month'] ?? 0); $pm = ($pm >= 1 && $pm <= 12) ? $pm : null;
    try {
        $db->beginTransaction();
        $rid = vendor_audit_round_id($db, $year, $half, true, $u);
        $ins = $db->prepare("INSERT IGNORE INTO vendor_audit_target (round_id, maker_id_no, plan_month, added_by, added_by_name)
                             SELECT ?, m.maker_id_no, ?, ?, ? FROM maker_list m
                             WHERE m.maker_id_no=? AND (m.status IS NULL OR m.status<>?)");
        $n = 0;
        foreach ($ids as $mid) { $ins->execute([$rid, $pm, $uid, $uname, $mid, VENDOR_AUDIT_DISABLED]); $n += $ins->rowCount(); }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('加入失敗：'.$e->getMessage(), 500); }
    jout(['added'=>$n]);
}

/* 隨機抽 N 家加入（自納管、非停用、符合篩選、未在本期者中隨機） */
case 'random_targets': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $year = (int)($_POST['year'] ?? 0);
    $half = (int)($_POST['half'] ?? 0);
    $n = (int)($_POST['n'] ?? 0);
    if ($year < 2000 || ($half !== 1 && $half !== 2)) jerr('期別不正確');
    if ($n < 1) jerr('請輸入抽取家數');
    $mainCat = (int)($_POST['main_cat_id'] ?? 0);
    $subCat = (int)($_POST['sub_cat_id'] ?? 0);
    $pm = (int)($_POST['plan_month'] ?? 0); $pm = ($pm >= 1 && $pm <= 12) ? $pm : null;

    try {
        $db->beginTransaction();
        $rid = vendor_audit_round_id($db, $year, $half, true, $u);
        $where = ["(m.status IS NULL OR m.status<>?)", "m.audit_managed=1",
                  "NOT EXISTS (SELECT 1 FROM vendor_audit_target t WHERE t.round_id=? AND t.maker_id_no=m.maker_id_no)"];
        $bind = [VENDOR_AUDIT_DISABLED, $rid];
        if ($mainCat > 0) $where[] = va_maincat_cond($mainCat, $bind);
        if ($subCat > 0)  $where[] = va_subcat_cond($subCat, $bind);
        $sql = "SELECT m.maker_id_no FROM maker_list m WHERE " . implode(' AND ', $where) . " ORDER BY RAND() LIMIT $n";
        $st = $db->prepare($sql);
        $st->execute($bind);
        $picked = $st->fetchAll(PDO::FETCH_COLUMN);
        $ins = $db->prepare("INSERT IGNORE INTO vendor_audit_target (round_id, maker_id_no, plan_month, added_by, added_by_name) VALUES (?,?,?,?,?)");
        foreach ($picked as $mid) $ins->execute([$rid, $mid, $pm, $uid, $uname]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('隨機抽取失敗：'.$e->getMessage(), 500); }
    jout(['added'=>count($picked), 'note'=>count($picked) < $n ? '符合條件的納管廠商不足，僅抽到 '.count($picked).' 家' : '']);
}

/* 移除本期對象（尚未稽核者，或管理員） */
case 'remove_target': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $tid = (int)($_POST['target_id'] ?? 0);
    $st = $db->prepare("SELECT audit_date FROM vendor_audit_target WHERE target_id=?");
    $st->execute([$tid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jerr('找不到對象');
    if ($row['audit_date'] !== null && !$perms['canAdmin']) jerr('已稽核之對象僅管理員可移除');
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM vendor_audit_target WHERE target_id=?")->execute([$tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('移除失敗：'.$e->getMessage(), 500); }
    jout([]);
}

/* 讀取某對象的完整評鑑表單（供編輯） */
case 'get_form': {
    $tid = (int)($_GET['target_id'] ?? 0);
    $st = $db->prepare("SELECT t.*, m.maker_id, m.main_category_id, dc.main_cat_name
                        FROM vendor_audit_target t
                        JOIN maker_list m ON m.maker_id_no=t.maker_id_no
                        LEFT JOIN dict_maker_main_category dc ON dc.main_cat_id=m.main_category_id
                        WHERE t.target_id=?");
    $st->execute([$tid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) jerr('找不到對象');
    $scores = json_decode((string)($t['scores_json'] ?? ''), true);
    $scope = vendor_audit_scope_of($t['main_category_id'] !== null ? (int)$t['main_category_id'] : null);
    $auditors = vendor_audit_auditors($db, $scope);
    // 附件
    $at = $db->prepare("SELECT attach_id, original_name, note, created_by_name, created_at, file_name, year
                        FROM vendor_audit_attach WHERE target_id=? ORDER BY attach_id");
    $at->execute([$tid]);
    $attaches = [];
    foreach ($at->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $attaches[] = ['attach_id'=>(int)$a['attach_id'], 'original_name'=>$a['original_name'], 'note'=>$a['note'],
                       'uploaded_by'=>$a['created_by_name'], 'created_at'=>$a['created_at'],
                       'exists'=>vendor_audit_attach_path($db, $a) !== null];
    }
    jout(['target'=>[
        'target_id'=>(int)$t['target_id'], 'maker_id_no'=>$t['maker_id_no'], 'maker_id'=>$t['maker_id'],
        'main_cat_name'=>$t['main_cat_name'], 'scope'=>$scope, 'scope_label'=>vendor_audit_scope_label($scope),
        'audit_date'=>$t['audit_date'], 'auditor'=>$t['auditor'],
        'report_no'=>$t['report_no'], 'note'=>$t['note'], 'audit_mode'=>$t['audit_mode'], 'plan_month'=>$t['plan_month'],
        'self_evaluator'=>$t['self_evaluator'], 'conclusion'=>$t['conclusion'],
        'self_rate'=>$t['self_rate'], 'audit_rate'=>$t['audit_rate'], 'overall_rate'=>$t['overall_rate'], 'judge'=>$t['judge'],
        'scores'=>is_array($scores) ? $scores : new stdClass(),
    ], 'auditors'=>$auditors, 'attaches'=>$attaches]);
}

/* ===== 稽核員資格管理（管理員） ===== */
case 'people': {   // 部門人員（設定稽核員用）
    $deptId = (int)($_GET['dept_id'] ?? 0);
    if ($deptId <= 0) jout(['people'=>[]]);
    $st = $db->prepare("SELECT DISTINCT u.id, u.user_cname FROM user_department_position_map m
                        JOIN user u ON u.id=m.user_id
                        WHERE m.department_id=? AND u.user_cname IS NOT NULL AND u.user_cname<>''
                          AND (u.state IS NULL OR u.state<>0) ORDER BY u.user_cname");
    $st->execute([$deptId]);
    jout(['people'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}
case 'auditors_all': {
    $rows = $db->query("SELECT a.auditor_id, a.user_id, a.user_name, a.dept_id, a.dept_name, a.scope,
                               CASE WHEN u.id IS NULL THEN 1 WHEN u.state=0 THEN 1 ELSE 0 END AS has_left
                        FROM vendor_auditor a LEFT JOIN user u ON u.id=a.user_id
                        WHERE a.is_active=1 ORDER BY has_left, a.scope, a.dept_name, a.user_name")->fetchAll(PDO::FETCH_ASSOC);
    $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    jout(['auditors'=>$rows, 'departments'=>$depts,
          'scopes'=>[['v'=>'outsource','l'=>'外包加工'],['v'=>'purchase','l'=>'採購'],['v'=>'all','l'=>'通用']]]);
}
case 'add_auditors': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $deptId = (int)($_POST['dept_id'] ?? 0);
    $deptName = trim((string)($_POST['dept_name'] ?? '')) ?: null;
    $scope = in_array($_POST['scope'] ?? '', ['outsource','purchase','all'], true) ? $_POST['scope'] : 'all';
    $ids = va_ids($_POST['user_ids'] ?? '');
    if (!$ids) jerr('請選擇人員');
    try {
        $db->beginTransaction();
        $ins = $db->prepare("INSERT INTO vendor_auditor (user_id, user_name, dept_id, dept_name, scope, created_by, created_by_name)
                             SELECT u.id, u.user_cname, ?, ?, ?, ?, ? FROM user u WHERE u.id=?
                             ON DUPLICATE KEY UPDATE dept_id=VALUES(dept_id), dept_name=VALUES(dept_name), is_active=1");
        $n = 0;
        foreach ($ids as $u2) { $ins->execute([$deptId ?: null, $deptName, $scope, $uid, $uname, (int)$u2]); $n++; }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('新增失敗：'.$e->getMessage(), 500); }
    jout(['added'=>$n]);
}
case 'remove_auditor': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $aid = (int)($_POST['auditor_id'] ?? 0);
    $db->prepare("DELETE FROM vendor_auditor WHERE auditor_id=?")->execute([$aid]);
    jout([]);
}

/* ===== 稽核佐證附件（供應商自評等） ===== */
case 'attach_upload': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $tid = (int)($_POST['target_id'] ?? 0);
    $tr = $db->prepare("SELECT r.year FROM vendor_audit_target t JOIN vendor_audit_round r ON r.round_id=t.round_id WHERE t.target_id=?");
    $tr->execute([$tid]);
    $yr = $tr->fetchColumn();
    if ($yr === false) jerr('找不到對象');
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('請選擇檔案');
    if ($_FILES['file']['size'] > 20*1024*1024) jerr('單檔上限 20MB');
    $orig = (string)$_FILES['file']['name'];
    $ext = pathinfo($orig, PATHINFO_EXTENSION);
    $fn = 'va' . $tid . '_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8) . ($ext !== '' ? '.' . preg_replace('/[^A-Za-z0-9]/','',$ext) : '');
    $dir = vendor_audit_attach_base($db) . DIRECTORY_SEPARATOR . (int)$yr;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dir . DIRECTORY_SEPARATOR . $fn)) jerr('存檔失敗（請確認附件路徑設定與權限）', 500);
    $db->prepare("INSERT INTO vendor_audit_attach (target_id, year, file_name, original_name, note, created_by, created_by_name)
                  VALUES (?,?,?,?,?,?,?)")
       ->execute([$tid, (int)$yr, $fn, $orig, trim((string)($_POST['note'] ?? '')) ?: null, $uid, $uname]);
    jout(['attach_id'=>(int)$db->lastInsertId()]);
}
case 'attach_delete': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $aid = (int)($_POST['attach_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM vendor_audit_attach WHERE attach_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    $p = vendor_audit_attach_path($db, $a);
    if ($p) @unlink($p);
    $db->prepare("DELETE FROM vendor_audit_attach WHERE attach_id=?")->execute([$aid]);
    jout([]);
}
case 'attach_open': {
    $aid = (int)($_GET['attach_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM vendor_audit_attach WHERE attach_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) { http_response_code(404); exit('not found'); }
    $p = vendor_audit_attach_path($db, $a);
    if (!$p) { http_response_code(404); exit('檔案不存在(NAS路徑可能已變更)'); }
    $mime = function_exists('mime_content_type') ? (mime_content_type($p) ?: 'application/octet-stream') : 'application/octet-stream';
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode((string)$a['original_name']) . '"');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

/* 登錄/修正稽核評鑑表單（15項自評/稽核分→伺服器端算合格率判定） */
case 'record_target': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $tid = (int)($_POST['target_id'] ?? 0);
    $st = $db->prepare("SELECT 1 FROM vendor_audit_target WHERE target_id=?");
    $st->execute([$tid]);
    if (!$st->fetchColumn()) jerr('找不到對象');
    $auditDate = trim((string)($_POST['audit_date'] ?? ''));
    $clear = ($auditDate === '');
    if (!$clear && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDate)) jerr('稽核日格式不正確');
    $auditor = trim((string)($_POST['auditor'] ?? '')) ?: null;
    $reportNo = trim((string)($_POST['report_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    $auditMode = in_array($_POST['audit_mode'] ?? '', ['first','again','self'], true) ? $_POST['audit_mode'] : null;
    $selfEval = trim((string)($_POST['self_evaluator'] ?? '')) ?: null;
    $supplierRep = trim((string)($_POST['supplier_rep'] ?? '')) ?: null;
    $conclusion = trim((string)($_POST['conclusion'] ?? '')) ?: null;
    $pm = (int)($_POST['plan_month'] ?? 0); $pm = ($pm >= 1 && $pm <= 12) ? $pm : null;

    // scores：{item_id:{self,audit,note}}
    $scores = json_decode((string)($_POST['scores'] ?? ''), true);
    if (!is_array($scores)) $scores = [];
    $rates = vendor_audit_compute_rates($scores);
    $hasScore = false;
    foreach ($scores as $s) { if (is_array($s) && ((isset($s['self']) && $s['self'] !== '') || (isset($s['audit']) && $s['audit'] !== ''))) { $hasScore = true; break; } }

    if ($clear) {
        // 清空稽核（回到未稽核）
        try {
            $db->beginTransaction();
            $db->prepare("UPDATE vendor_audit_target SET audit_date=NULL, scores_json=NULL, self_rate=NULL, audit_rate=NULL,
                          overall_rate=NULL, judge=NULL, plan_month=?, audit_mode=?, self_evaluator=?, supplier_rep=?, conclusion=?,
                          auditor=?, report_no=?, note=? WHERE target_id=?")
               ->execute([$pm,$auditMode,$selfEval,$supplierRep,$conclusion,$auditor,$reportNo,$note,$tid]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
        jout(['cleared'=>true]);
    }

    $tt = $rates['total'];
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE vendor_audit_target SET audit_date=?, scores_json=?, self_rate=?, audit_rate=?, overall_rate=?,
                      judge=?, plan_month=?, audit_mode=?, self_evaluator=?, supplier_rep=?, conclusion=?, auditor=?, report_no=?, note=? WHERE target_id=?")
           ->execute([$auditDate, $hasScore ? json_encode($scores, JSON_UNESCAPED_UNICODE) : null,
                      $hasScore ? $tt['self_rate'] : null, $hasScore ? $tt['audit_rate'] : null,
                      $hasScore ? $tt['overall_rate'] : null, $hasScore ? $tt['judge'] : null,
                      $pm, $auditMode, $selfEval, $supplierRep, $conclusion, $auditor, $reportNo, $note, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['rates'=>$rates]);
}

/* 設定廠商是否納入稽核管理（管理員；支援批次） */
case 'set_managed': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $ids = va_ids($_POST['maker_ids'] ?? '');
    if (!$ids) jerr('請選擇廠商');
    $managed = (int)($_POST['managed'] ?? 0) === 1 ? 1 : 0;
    try {
        $db->beginTransaction();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE maker_list SET audit_managed=? WHERE maker_id_no IN ($ph)")
           ->execute(array_merge([$managed], $ids));
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('設定失敗：'.$e->getMessage(), 500); }
    jout(['updated'=>count($ids)]);
}

/* 全域稽核週期設定（管理員） */
case 'save_cycle': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    vendor_audit_set_cycle($db, (int)($_POST['cycle_months'] ?? 6));
    if (array_key_exists('attach_base', $_POST)) {
        $ab = trim((string)$_POST['attach_base']);
        $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('vendor_audit_attach_base', ?)
                            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $up->execute([$ab]);
    }
    foreach (['as_doc_id'=>'vendor_audit_as_doc_id', 'record_as_doc_id'=>'vendor_record_as_doc_id',
              'roster_as_doc_id'=>'vendor_roster_as_doc_id', 'eval_as_doc_id'=>'vendor_eval_as_doc_id'] as $pk=>$sk) {
        if (array_key_exists($pk, $_POST)) {
            $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $up->execute([$sk, (string)(int)$_POST[$pk]]);
        }
    }
    jout(['cycle_months'=>vendor_audit_cycle_months($db),
          'attach_base'=>vendor_eval_setting($db, 'vendor_audit_attach_base', ''),
          'as_doc'=>vendor_audit_bound_asdoc($db),
          'record_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_record_as_doc_id'),
          'roster_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_roster_as_doc_id'),
          'eval_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_eval_as_doc_id')]);
}

/* 某廠商跨期稽核歷史 */
case 'vendor_history': {
    $mid = trim((string)($_GET['maker_id_no'] ?? ''));
    $st = $db->prepare("SELECT r.year, r.half, t.audit_date, t.overall_rate, t.judge, t.auditor, t.report_no, t.note
                        FROM vendor_audit_target t JOIN vendor_audit_round r ON r.round_id=t.round_id
                        WHERE t.maker_id_no=? ORDER BY r.year DESC, r.half DESC");
    $st->execute([$mid]);
    $mk = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no=?");
    $mk->execute([$mid]);
    jout(['maker_name'=>$mk->fetchColumn() ?: $mid, 'list'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

default:
    jerr('未知動作：'.$action);
}
