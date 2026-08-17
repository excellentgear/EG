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
if (!$perms['canView']) {
    // 沒有本模組角色，但目前是「簽核設定」解析出的簽核人(含代理)時，仍放行檢閱層級——
    // 否則被指派簽核的主管收到通知點進來卻進不了頁面，違反 ai-rules/17「通知要能直接看到內容並決行」。
    $isResolvedSigner = false;
    try {
        $sg = vendor_audit_resolve_signer($db, 0);
        if ($sg && (int)$sg['id'] === $uid) $isResolvedSigner = true;
        if (!$isResolvedSigner) {
            // 稽核計劃核准人現在是「部門內任一主管」的一批人(見 vendor_audit_plan_approver_pool)，
            // 不是單一固定的人，用「是否實際被通知去核准某年度計劃」判斷比重算整批人選更直接準確。
            $st = $db->prepare("SELECT 1 FROM live_event_target t JOIN live_event e ON e.id=t.live_event_id
                                WHERE e.ref_type='VENDOR_AUDIT_PLAN' AND t.target_id=? AND (e.enddate IS NULL OR e.enddate>=CURDATE()) LIMIT 1");
            $st->execute([$uid]);
            if ($st->fetchColumn()) $isResolvedSigner = true;
        }
    } catch (Throwable $e) {}
    if (!$isResolvedSigner) jerr('無供應商稽核檢閱權限', 403);
    $perms = ['isAdmin'=>false, 'canAdmin'=>false, 'canEdit'=>false, 'canView'=>true];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
/** 目前操作範疇(外包加工/採購)：GET/POST 皆可帶 scope，未帶或非法值一律回退 outsource(既有資料的預設 scope) */
$scope = vendor_audit_norm_scope($_GET['scope'] ?? $_POST['scope'] ?? '');

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
    jout(array_merge([
          'perms'=>$perms, 'cur_year'=>(int)date('Y'), 'cur_month'=>(int)date('n'),
          'cur_half'=>((int)date('n') <= 6 ? 1 : 2), 'today'=>date('Y-m-d'),
          'cycle_months'=>vendor_audit_cycle_months($db), 'main_categories'=>$cats,
          'attach_base'=>vendor_eval_setting($db, 'vendor_audit_attach_base', ''),
          'list_print_title'=>vendor_eval_setting($db, 'vendor_audit_list_print_title', ''),
          'as_doc'=>vendor_audit_bound_asdoc($db),
          'eval_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_eval_as_doc_id'),
          'record_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_record_as_doc_id'),
          'roster_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_roster_as_doc_id'),
          'plan_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_plan_as_doc_id'),
          'company_name'=>vendor_audit_company_name($db),
          'sign_setting'=>$perms['canAdmin'] ? vendor_audit_sign_setting($db) : null,
          'plan_sign_setting'=>$perms['canAdmin'] ? vendor_audit_plan_sign_setting($db) : null,
          'plan_approver_names'=>$perms['canAdmin'] ? array_column(vendor_audit_plan_approver_pool($db, $uid), 'user_cname') : [],
          'confirm_pw_allowed'=>eg_confirm_password_allowed($db, $uid),
          'eval_settings'=>vendor_eval_settings($db),
          'scope'=>$scope, 'scopes'=>[['v'=>'outsource','l'=>'外包加工(生管)'],['v'=>'purchase','l'=>'採購']],
        ], vendor_audit_checklist_config($db, $scope)));
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
    $scopeCond = vendor_audit_scope_sql_cond($scope, 'maker_list');
    if ($kw !== '') {
        $st = $db->prepare("SELECT maker_id_no, maker_id FROM maker_list
                            WHERE (status IS NULL OR status<>?) AND $scopeCond AND (maker_id LIKE ? OR maker_id_no LIKE ? OR maker_id_all LIKE ?)
                            ORDER BY maker_id LIMIT 50");
        $st->execute([VENDOR_AUDIT_DISABLED, "%$kw%", "%$kw%", "%$kw%"]);
    } else {
        $st = $db->prepare("SELECT maker_id_no, maker_id FROM maker_list
                            WHERE (status IS NULL OR status<>?) AND $scopeCond AND audit_managed=1 ORDER BY maker_id LIMIT 300");
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
                      WHERE (status IS NULL OR status<>'" . VENDOR_AUDIT_DISABLED . "') AND audit_managed=1
                        AND " . vendor_audit_scope_sql_cond($scope, 'maker_list') . " ORDER BY maker_id")
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
    // 定期評核表本身沒有單筆業務日期(選定年度的全廠商彙總報表)，比照該年度稽核計畫的送出日期回推
    // AS 編號版次(使用者明確要求：兩者用同一套業務日期認定，ai-rules/16 第三之四節)。
    $evalLock = vendor_audit_plan_lock_get($db, $year);
    $evalBizDate = $evalLock['submit_date'] ?? null;
    jout(['year'=>$year, 'settings'=>$set, 'vendors'=>$out,
          'eval_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_eval_as_doc_id', $evalBizDate)]);
}

/* ===== 合格供應商清冊（2-PH-01-04）===== */
/* 清冊清單：納管 或 手動列入(in_roster)，非停用；含定期評核建議等級 + 手動覆寫 */
case 'roster_list': {
    $year = (int)($_GET['year'] ?? '') ?: (int)date('Y');
    $set = vendor_eval_settings($db);
    $rows = $db->query("SELECT m.maker_id_no, m.maker_id, m.m_note, m.audit_managed, m.in_roster, m.roster_grade, m.main_category_id,
                               sc.sub_cat_names
                        FROM maker_list m " . vendor_audit_subcat_join() . "
                        WHERE (m.status IS NULL OR m.status<>'" . VENDOR_AUDIT_DISABLED . "') AND (m.audit_managed=1 OR m.in_roster=1)
                          AND " . vendor_audit_scope_sql_cond($scope) . "
                        ORDER BY m.main_category_id IS NULL, m.main_category_id, m.maker_id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $ev = vendor_periodic_eval($db, $r['maker_id_no'], $year, $set);
        $sg = $ev['full']['grade']; $ss = $ev['full']['score'];
        $out[] = [
            'maker_id_no'=>$r['maker_id_no'], 'maker_id'=>$r['maker_id'], 'm_note'=>$r['m_note'],
            'main_cat_name'=>$r['sub_cat_names'], 'is_managed'=>(int)$r['audit_managed'], 'in_roster'=>(int)$r['in_roster'],
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
/* 合格供應商清冊列印簽章名單：製表＝目前登入者(前端已知不必回傳)；
   審核＝eg_resolve_supervisor()解析目前登入者的部門上一階主管(同人再往上一層部門找，找不到回null由前端退回製表人)；
   核准＝org_role_lib全站共用「最高核准人員」(top_approver)。使用者2026-08-10明確要求的三段規則。 */
case 'roster_sign_info': {
    $reviewerId = eg_resolve_supervisor($db, $uid);
    $reviewerName = null;
    if ($reviewerId) {
        $st = $db->prepare("SELECT user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
        $st->execute([$reviewerId]);
        $reviewerName = $st->fetchColumn() ?: null;
    }
    $approver = eg_org_user($db, 'top_approver');
    jout(['reviewer_name' => $reviewerName, 'approver_name' => $approver['user_cname'] ?? null]);
}

/* 兩年未交易外包廠（有 bom_ing 發包史但最後發包 >2 年）：納管或在冊者，供確認後移除 */
case 'stale_vendors': {
    $st = $db->query("SELECT m.maker_id_no, m.maker_id, m.audit_managed, m.in_roster, sc.sub_cat_names AS main_cat_name,
                             MAX(bi.outsource_date) AS last_date
                      FROM maker_list m
                      JOIN bom_ing bi ON bi.maker_id_no=m.maker_id_no AND bi.outsource_date IS NOT NULL
                      " . vendor_audit_subcat_join() . "
                      WHERE (m.status IS NULL OR m.status<>'" . VENDOR_AUDIT_DISABLED . "')
                        AND (m.audit_managed=1 OR m.in_roster=1)
                        AND " . vendor_audit_scope_sql_cond($scope) . "
                      GROUP BY m.maker_id_no, m.maker_id, m.audit_managed, m.in_roster, sc.sub_cat_names
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
                                   t.status AS sign_status, t.signed_by_name, t.signed_at, t.signed_is_deputy,
                                   m.maker_id, m.status, sc.sub_cat_names AS main_cat_name
                            FROM vendor_audit_target t
                            JOIN maker_list m ON m.maker_id_no=t.maker_id_no
                            " . vendor_audit_subcat_join() . "
                            WHERE t.round_id=? AND " . vendor_audit_scope_sql_cond($scope) . "
                            ORDER BY t.audit_date IS NOT NULL, m.maker_id");
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

    $where = ["(m.status IS NULL OR m.status<>?)", vendor_audit_scope_sql_cond($scope)];
    $bind = [VENDOR_AUDIT_DISABLED];
    if ($managedOnly) $where[] = "m.audit_managed=1";
    if ($mainCat > 0) $where[] = va_maincat_cond($mainCat, $bind);
    if ($subCat > 0)  $where[] = va_subcat_cond($subCat, $bind);
    if ($kw !== '') { $where[] = "(m.maker_id LIKE ? OR m.maker_id_no LIKE ? OR m.maker_id_all LIKE ?)";
                      $bind[] = "%$kw%"; $bind[] = "%$kw%"; $bind[] = "%$kw%"; }
    if ($rid !== null) { $where[] = "NOT EXISTS (SELECT 1 FROM vendor_audit_target t WHERE t.round_id=? AND t.maker_id_no=m.maker_id_no)";
                         $bind[] = $rid; }

    $sql = "SELECT m.maker_id_no, m.maker_id, m.audit_managed, sc.sub_cat_names AS main_cat_name
            FROM maker_list m
            " . vendor_audit_subcat_join() . "
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
    if (vendor_audit_plan_locked($db, $year, $scope)) jerr($year.' 年度稽核計劃（'.vendor_audit_scope_label($scope).'）已送出鎖定，不可再增列對象');
    $ids = va_ids($_POST['maker_ids'] ?? '');
    if (!$ids) jerr('請選擇廠商');
    $pm = (int)($_POST['plan_month'] ?? 0); $pm = ($pm >= 1 && $pm <= 12) ? $pm : null;
    try {
        $db->beginTransaction();
        $rid = vendor_audit_round_id($db, $year, $half, true, $u);
        $ins = $db->prepare("INSERT IGNORE INTO vendor_audit_target (round_id, maker_id_no, plan_month, added_by, added_by_name)
                             SELECT ?, m.maker_id_no, ?, ?, ? FROM maker_list m
                             WHERE m.maker_id_no=? AND (m.status IS NULL OR m.status<>?) AND " . vendor_audit_scope_sql_cond($scope));
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
    if (vendor_audit_plan_locked($db, $year, $scope)) jerr($year.' 年度稽核計劃（'.vendor_audit_scope_label($scope).'）已送出鎖定，不可再增列對象');
    if ($n < 1) jerr('請輸入抽取家數');
    $mainCat = (int)($_POST['main_cat_id'] ?? 0);
    $subCat = (int)($_POST['sub_cat_id'] ?? 0);
    $pm = (int)($_POST['plan_month'] ?? 0); $pm = ($pm >= 1 && $pm <= 12) ? $pm : null;

    try {
        $db->beginTransaction();
        $rid = vendor_audit_round_id($db, $year, $half, true, $u);
        $where = ["(m.status IS NULL OR m.status<>?)", "m.audit_managed=1", vendor_audit_scope_sql_cond($scope),
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
    $st = $db->prepare("SELECT t.*, m.maker_id, m.main_category_id, sc.sub_cat_names AS main_cat_name, dc.main_cat_name AS main_category_name
                        FROM vendor_audit_target t
                        JOIN maker_list m ON m.maker_id_no=t.maker_id_no
                        LEFT JOIN dict_maker_main_category dc ON dc.main_cat_id=m.main_category_id
                        " . vendor_audit_subcat_join() . "
                        WHERE t.target_id=?");
    $st->execute([$tid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) jerr('找不到對象');
    $scores = json_decode((string)($t['scores_json'] ?? ''), true);
    // 這裡的 scope 一律以該供應商主檔實際歸屬為準(而非請求帶的 scope 參數)，因為同一期(round)可能同時有兩種 scope 的對象
    $targetScope = vendor_audit_scope_of($t['main_category_id'] !== null ? (int)$t['main_category_id'] : null);
    $auditors = vendor_audit_auditors($db, $targetScope);
    $cfg = vendor_audit_resolve_cfg($db, $t['checklist_snapshot'], $targetScope);
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
    $rejectInfo = null;
    if ($t['status'] === 'rejected') {
        $ap = eg_approval_latest($db, 'vendor_audit_sign', $tid, 'manager');
        if ($ap && $ap['status'] === 'rejected') $rejectInfo = ['by'=>$ap['approver_name'], 'at'=>$ap['decided_at'], 'note'=>$ap['note']];
    }
    // 查核表/記錄表都是「單一筆有自己稽核日期」的單據，AS 編號版次依該筆 audit_date 回推（ai-rules/16 第三之四節）
    $bizDate = $t['audit_date'] ?: null;
    $asDoc = vendor_audit_bound_asdoc($db, 'vendor_audit_as_doc_id', $bizDate);
    $recordDoc = vendor_audit_bound_asdoc($db, 'vendor_record_as_doc_id', $bizDate);
    jout(['target'=>[
        'target_id'=>(int)$t['target_id'], 'maker_id_no'=>$t['maker_id_no'], 'maker_id'=>$t['maker_id'],
        'main_cat_name'=>$t['main_cat_name'], 'scope'=>$targetScope, 'scope_label'=>vendor_audit_scope_label($targetScope),
        'audit_date'=>$t['audit_date'], 'auditor'=>$t['auditor'],
        'report_no'=>$t['report_no'], 'note'=>$t['note'], 'audit_mode'=>$t['audit_mode'], 'plan_month'=>$t['plan_month'],
        'self_evaluator'=>$t['self_evaluator'], 'conclusion'=>$t['conclusion'], 'review_type'=>$t['review_type'],
        'prod_type'=>vendor_audit_prod_type($t['main_category_name']),
        'self_rate'=>$t['self_rate'], 'audit_rate'=>$t['audit_rate'], 'overall_rate'=>$t['overall_rate'], 'judge'=>$t['judge'],
        'scores'=>is_array($scores) ? $scores : new stdClass(),
        'status'=>$t['status'] ?: 'draft', 'signed_by_name'=>$t['signed_by_name'], 'signed_at'=>$t['signed_at'],
        'signed_is_deputy'=>$t['signed_is_deputy'] ? true : false, 'reject_info'=>$rejectInfo,
        'checklist_cfg'=>$cfg,
        'as_doc_no'=>$asDoc['doc_no'] ?? '', 'record_as_doc_no'=>$recordDoc['doc_no'] ?? '',
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
    $st = $db->prepare("SELECT t.status, t.checklist_snapshot, m.main_category_id
                        FROM vendor_audit_target t JOIN maker_list m ON m.maker_id_no=t.maker_id_no WHERE t.target_id=?");
    $st->execute([$tid]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) jerr('找不到對象');
    $targetScope = vendor_audit_scope_of($cur['main_category_id'] !== null ? (int)$cur['main_category_id'] : null);
    if (in_array($cur['status'], ['pending','approved'], true)) jerr('此筆已送審核/已核准，請先重新整理確認狀態，如需修改請聯絡管理員');
    $auditDate = trim((string)($_POST['audit_date'] ?? ''));
    $clear = ($auditDate === '');
    if (!$clear && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDate)) jerr('稽核日格式不正確');
    $auditor = trim((string)($_POST['auditor'] ?? '')) ?: null;
    if (!$clear && $auditor === null) jerr('請填寫稽核員');
    $reportNo = trim((string)($_POST['report_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    $auditMode = in_array($_POST['audit_mode'] ?? '', ['first','again','self'], true) ? $_POST['audit_mode'] : null;
    $selfEval = trim((string)($_POST['self_evaluator'] ?? '')) ?: null;
    $supplierRep = trim((string)($_POST['supplier_rep'] ?? '')) ?: null;
    $conclusion = trim((string)($_POST['conclusion'] ?? '')) ?: null;
    $reviewType = in_array($_POST['review_type'] ?? '', ['site','self','abnormal'], true) ? $_POST['review_type'] : null;
    $pm = (int)($_POST['plan_month'] ?? 0); $pm = ($pm >= 1 && $pm <= 12) ? $pm : null;

    // scores：{item_id:{self,audit,note}}
    $scores = json_decode((string)($_POST['scores'] ?? ''), true);
    if (!is_array($scores)) $scores = [];
    $cfg = vendor_audit_resolve_cfg($db, $cur['checklist_snapshot'], $targetScope);
    $rates = vendor_audit_compute_rates($scores, $cfg);
    $hasScore = false;
    foreach ($scores as $s) { if (is_array($s) && ((isset($s['self']) && $s['self'] !== '') || (isset($s['audit']) && $s['audit'] !== ''))) { $hasScore = true; break; } }
    // 首次登錄分數時凍結當時查核表內容(類別/項次/滿分/權重/合格率)，之後管理員再調整查核表都不影響本筆
    $snapshotToSave = $cur['checklist_snapshot'] ?: ($hasScore ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null);

    if ($clear) {
        // 清空稽核（回到未稽核）
        try {
            $db->beginTransaction();
            $db->prepare("UPDATE vendor_audit_target SET audit_date=NULL, scores_json=NULL, self_rate=NULL, audit_rate=NULL,
                          overall_rate=NULL, judge=NULL, plan_month=?, audit_mode=?, self_evaluator=?, supplier_rep=?, conclusion=?,
                          auditor=?, report_no=?, note=?, review_type=?, status='draft', checklist_snapshot=NULL WHERE target_id=?")
               ->execute([$pm,$auditMode,$selfEval,$supplierRep,$conclusion,$auditor,$reportNo,$note,$reviewType,$tid]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
        jout(['cleared'=>true]);
    }

    $tt = $rates['total'];
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE vendor_audit_target SET audit_date=?, scores_json=?, self_rate=?, audit_rate=?, overall_rate=?,
                      judge=?, plan_month=?, audit_mode=?, self_evaluator=?, supplier_rep=?, conclusion=?, auditor=?, report_no=?, note=?,
                      review_type=?, checklist_snapshot=? WHERE target_id=?")
           ->execute([$auditDate, $hasScore ? json_encode($scores, JSON_UNESCAPED_UNICODE) : null,
                      $hasScore ? $tt['self_rate'] : null, $hasScore ? $tt['audit_rate'] : null,
                      $hasScore ? $tt['overall_rate'] : null, $hasScore ? $tt['judge'] : null,
                      $pm, $auditMode, $selfEval, $supplierRep, $conclusion, $auditor, $reportNo, $note,
                      $reviewType, $snapshotToSave, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['rates'=>$rates]);
}

/* 完成評鑑（全欄位完整性檢查通過才可）：依簽核設定自動核可或轉為待送審核 */
case 'complete_target': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $tid = (int)($_POST['target_id'] ?? 0);
    $auditDate = trim((string)($_POST['audit_date'] ?? ''));
    if ($auditDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDate)) jerr('請先填寫稽核日期');
    $auditor = trim((string)($_POST['auditor'] ?? '')) ?: null;
    $reportNo = trim((string)($_POST['report_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    $auditMode = in_array($_POST['audit_mode'] ?? '', ['first','again','self'], true) ? $_POST['audit_mode'] : null;
    $selfEval = trim((string)($_POST['self_evaluator'] ?? '')) ?: null;
    $supplierRep = trim((string)($_POST['supplier_rep'] ?? '')) ?: null;
    $conclusion = trim((string)($_POST['conclusion'] ?? '')) ?: null;
    $reviewType = in_array($_POST['review_type'] ?? '', ['site','self','abnormal'], true) ? $_POST['review_type'] : null;
    $pm = (int)($_POST['plan_month'] ?? 0); $pm = ($pm >= 1 && $pm <= 12) ? $pm : null;
    $scores = json_decode((string)($_POST['scores'] ?? ''), true);
    if (!is_array($scores)) $scores = [];

    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT t.status, t.checklist_snapshot, m.main_category_id
                            FROM vendor_audit_target t JOIN maker_list m ON m.maker_id_no=t.maker_id_no WHERE t.target_id=? FOR UPDATE");
        $st->execute([$tid]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) { $db->rollBack(); jerr('找不到對象'); }
        if (!in_array($cur['status'], ['draft','rejected'], true)) {
            $db->rollBack(); jerr('此筆狀態已變更(可能已完成/送審核)，請重新整理後再試');
        }
        $targetScope = vendor_audit_scope_of($cur['main_category_id'] !== null ? (int)$cur['main_category_id'] : null);
        $cfg = vendor_audit_resolve_cfg($db, $cur['checklist_snapshot'], $targetScope);
        $errs = vendor_audit_validate_complete(['auditor'=>$auditor, 'conclusion'=>$conclusion, 'review_type'=>$reviewType, 'scores'=>$scores], $cfg);
        if ($errs) { $db->rollBack(); jerr(implode('；', $errs)); }

        $rates = vendor_audit_compute_rates($scores, $cfg);
        $tt = $rates['total'];
        $snapshotToSave = $cur['checklist_snapshot'] ?: json_encode($cfg, JSON_UNESCAPED_UNICODE);

        $set = vendor_audit_sign_setting($db);
        $signer = vendor_audit_resolve_signer($db, $uid);
        $autoApprove = !empty($set['auto']) || ($signer && (int)$signer['id'] === $uid);
        if (!$autoApprove && !$signer) { $db->rollBack(); jerr('尚未設定簽核部門，請聯絡管理員先於「簽核設定」指定簽核部門'); }
        $newStatus = $autoApprove ? 'approved' : 'pending';
        $signedByName = $autoApprove ? ($signer ? $signer['name'] : $uname) : null;
        $signedIsDeputy = $autoApprove && $signer ? (!empty($signer['is_deputy']) ? 1 : 0) : null;
        $signedAt = $autoApprove ? date('Y-m-d H:i:s') : null;

        $db->prepare("UPDATE vendor_audit_target SET audit_date=?, scores_json=?, self_rate=?, audit_rate=?, overall_rate=?,
                      judge=?, plan_month=?, audit_mode=?, self_evaluator=?, supplier_rep=?, conclusion=?, auditor=?, report_no=?, note=?,
                      review_type=?, checklist_snapshot=?, status=?, completed_at=NOW(), completed_by=?, completed_by_name=?,
                      signed_by_name=?, signed_at=?, signed_is_deputy=? WHERE target_id=?")
           ->execute([$auditDate, json_encode($scores, JSON_UNESCAPED_UNICODE), $tt['self_rate'], $tt['audit_rate'],
                      $tt['overall_rate'], $tt['judge'], $pm, $auditMode, $selfEval, $supplierRep, $conclusion, $auditor, $reportNo, $note,
                      $reviewType, $snapshotToSave, $newStatus, $uid, $uname, $signedByName, $signedAt, $signedIsDeputy, $tid]);

        $aid = eg_approval_submit($db, 'vendor_audit_sign', $tid, 'manager', $uid, $uname);
        if ($autoApprove) {
            eg_approval_decide($db, $aid, $signer ? (int)$signer['id'] : $uid, $signedByName ?: $uname, 'approved', '系統自動核可/送審人即簽核人免審');
        }
        $db->commit();
        // 需簽核者：完成當下直接自動通知解析出的簽核人，不必再多按一次「送審核」
        if (!$autoApprove && $signer) {
            $mk = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no=(SELECT maker_id_no FROM vendor_audit_target WHERE target_id=?)");
            $mk->execute([$tid]);
            $makerId = (string)($mk->fetchColumn() ?: '');
            $leId = vendor_audit_notify_sign($db, $tid, (int)$signer['id'], $makerId, $uid, $uname);
            if ($leId) eg_approval_set_live_event($db, $aid, $leId);
        }
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['status'=>$newStatus]);
}

/* 送審核：完成待審者由此送出通知給解析出的簽核人（保留供舊流程/意外中斷後補送使用） */
case 'submit_sign': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $tid = (int)($_POST['target_id'] ?? 0);
    $st = $db->prepare("SELECT t.status, m.maker_id FROM vendor_audit_target t JOIN maker_list m ON m.maker_id_no=t.maker_id_no WHERE t.target_id=?");
    $st->execute([$tid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jerr('找不到對象');
    if ($row['status'] !== 'completed') jerr('此筆狀態非「已完成待送審」，請重新整理後再試');
    $signer = vendor_audit_resolve_signer($db, $uid);
    if (!$signer) jerr('尚未設定簽核部門，請聯絡管理員先於「簽核設定」指定簽核部門');
    try {
        $db->beginTransaction();
        $aid = eg_approval_submit($db, 'vendor_audit_sign', $tid, 'manager', $uid, $uname);
        $db->prepare("UPDATE vendor_audit_target SET status='pending' WHERE target_id=? AND status='completed'")->execute([$tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('送出失敗：'.$e->getMessage(), 500); }
    $leId = vendor_audit_notify_sign($db, $tid, (int)$signer['id'], (string)$row['maker_id'], $uid, $uname);
    if ($leId) eg_approval_set_live_event($db, $aid, $leId);
    jout(['status'=>'pending']);
}

/* 簽核決行：核准/退回(退回須填原因) */
case 'sign_decide': {
    $tid = (int)($_POST['target_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $noteIn = trim((string)($_POST['note'] ?? ''));
    if (!in_array($decision, ['approved','rejected'], true)) jerr('無效的簽核決定');
    if ($decision === 'rejected' && $noteIn === '') jerr('退回必須填寫原因');
    $st = $db->prepare("SELECT t.*, m.maker_id FROM vendor_audit_target t JOIN maker_list m ON m.maker_id_no=t.maker_id_no WHERE t.target_id=?");
    $st->execute([$tid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jerr('找不到對象');
    $approval = eg_approval_latest($db, 'vendor_audit_sign', $tid, 'manager');
    if (!$approval || $approval['status'] !== 'pending') jerr('此筆目前無待簽核紀錄');
    $signer = vendor_audit_resolve_signer($db, (int)($row['completed_by'] ?? 0));
    if (!$perms['canAdmin'] && (!$signer || (int)$signer['id'] !== $uid)) jerr('您不是此筆的簽核人', 403);

    $res = eg_approval_decide($db, (int)$approval['id'], $uid, $uname, $decision, $noteIn ?: null);
    if (!$res['success']) jerr($res['message']);
    try {
        if ($decision === 'approved') {
            $db->prepare("UPDATE vendor_audit_target SET status='approved', signed_by_name=?, signed_at=NOW(), signed_is_deputy=0 WHERE target_id=?")
               ->execute([$uname, $tid]);
        } else {
            $db->prepare("UPDATE vendor_audit_target SET status='rejected' WHERE target_id=?")->execute([$tid]);
        }
        vendor_audit_close_sign_notice($db, $tid, $uid);
    } catch (Throwable $e) { error_log('[vendor_audit] sign_decide post-process failed: '.$e->getMessage()); }
    vendor_audit_notify_sign_result($db, $tid, (string)$row['maker_id'], (int)($row['completed_by'] ?? 0), $uname, $decision, $noteIn ?: null);
    jout(['status'=>$decision==='approved'?'approved':'rejected']);
}

/* 簽核部門下拉選項(生管組本身或往上部門) */
case 'sign_dept_options': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    jout(['options'=>vendor_audit_sign_dept_options($db)]);
}
/* 儲存簽核設定(自動簽核開關+簽核部門) */
case 'save_sign_setting': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $auto = (int)($_POST['auto'] ?? 0) === 1 ? 1 : 0;
    $deptId = (int)($_POST['dept_id'] ?? 0) ?: null;
    vendor_audit_sign_save_setting($db, $auto, $deptId);
    jout(['setting'=>vendor_audit_sign_setting($db)]);
}

/* 查核表設定(可設定化題庫)：讀取/儲存 */
case 'get_checklist': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    jout(vendor_audit_checklist_config($db, $scope));
}
case 'save_checklist': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $cats = json_decode((string)($_POST['cats'] ?? ''), true);
    if (!is_array($cats) || !$cats) jerr('查核表內容不可為空');
    $selfW = (float)($_POST['self_w'] ?? VENDOR_AUDIT_SELF_W);
    $auditW = (float)($_POST['audit_w'] ?? VENDOR_AUDIT_AUDIT_W);
    $passRate = (float)($_POST['pass_rate'] ?? VENDOR_AUDIT_PASS_RATE);
    try {
        $db->beginTransaction();
        vendor_audit_checklist_save($db, $cats, $scope);
        vendor_audit_save_weights($db, $selfW, $auditW, $passRate, $scope);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(vendor_audit_checklist_config($db, $scope));
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
    if (array_key_exists('list_print_title', $_POST)) {
        $lt = trim((string)$_POST['list_print_title']);
        $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('vendor_audit_list_print_title', ?)
                            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $up->execute([$lt]);
    }
    foreach (['as_doc_id'=>'vendor_audit_as_doc_id', 'record_as_doc_id'=>'vendor_record_as_doc_id',
              'roster_as_doc_id'=>'vendor_roster_as_doc_id', 'eval_as_doc_id'=>'vendor_eval_as_doc_id',
              'plan_as_doc_id'=>'vendor_plan_as_doc_id'] as $pk=>$sk) {
        if (array_key_exists($pk, $_POST)) {
            $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $up->execute([$sk, (string)(int)$_POST[$pk]]);
        }
    }
    jout(['cycle_months'=>vendor_audit_cycle_months($db),
          'attach_base'=>vendor_eval_setting($db, 'vendor_audit_attach_base', ''),
          'list_print_title'=>vendor_eval_setting($db, 'vendor_audit_list_print_title', ''),
          'as_doc'=>vendor_audit_bound_asdoc($db),
          'record_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_record_as_doc_id'),
          'roster_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_roster_as_doc_id'),
          'eval_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_eval_as_doc_id'),
          'plan_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_plan_as_doc_id')]);
}

/* ===== 供應商稽核計劃(2-PH-01-06,年度版) ===== */
case 'plan_data': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $signSet = vendor_audit_plan_sign_setting($db);
    $lock = vendor_audit_plan_lock_get($db, $year, $scope);
    // 待核准中：顯示這筆實際的合格核准人清單(依送出者職級解析)；尚未送出：用目前使用者身分預覽會是誰(僅供參考)
    $approverNames = [];
    if ($signSet['need']) {
        $approverNames = array_column(
            vendor_audit_plan_approver_pool($db, $lock && !empty($lock['submitted_by']) ? (int)$lock['submitted_by'] : $uid),
            'user_cname'
        );
    }
    // 年度計畫本身沒有單筆業務日期，但「送出計畫」是明確的定案事件，AS 編號版次依 submit_date 回推
    // （ai-rules/16 第三之四節）；尚未送出時沒有 submit_date，退回今天最新版（等同現行行為）。
    $planBizDate = $lock['submit_date'] ?? null;
    // 「核准/退回」按鈕只給跟這筆待核准有關的人看：合格核准人、送出人本人(可自行退回)、或管理者；
    // 跟 plan_decide 的權限判斷保持一致，不讓完全無關的人也看到按鈕(使用者2026-08-10明確要求要「完全鎖住」)。
    $canDecide = false;
    if ($lock && $lock['status'] === 'pending') {
        $submittedBy = (int)($lock['submitted_by'] ?? 0);
        $decidePool = vendor_audit_plan_approver_pool($db, $submittedBy); // 已排除送出者本人
        if ($uid === $submittedBy) {
            // 送出者(常常同時是稽核員/管理者)一律不顯示核准/退回按鈕，避免誤按；
            // 除非真的完全解析不到其他合格核准人(pool為空)且本人是管理者，才放行讓送出者自己
            // 處理，避免計劃卡死——跟plan_decide的「除非真的不可避免」規則一致(使用者2026-08-10明確要求)。
            $canDecide = !$decidePool && $perms['canAdmin'];
        } else {
            $canDecide = $perms['canAdmin'] || in_array($uid, array_column($decidePool, 'id'), true);
        }
    }
    jout(['year'=>$year, 'scope'=>$scope, 'rows'=>vendor_audit_plan_data($db, $year, $scope), 'lock'=>$lock,
          'locked'=>vendor_audit_plan_locked($db, $year, $scope), 'sign_setting'=>$signSet,
          'approver_names'=>$approverNames, 'can_decide'=>$canDecide,
          'plan_as_doc'=>vendor_audit_bound_asdoc($db, 'vendor_plan_as_doc_id', $planBizDate),
          'company_name'=>vendor_audit_company_name($db)]);
}
case 'plan_submit': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000) jerr('年度不正確');
    $submitDate = trim((string)($_POST['submit_date'] ?? '')) ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $submitDate)) jerr('日期格式不正確');
    $lock = vendor_audit_plan_lock_get($db, $year, $scope);
    if ($lock && in_array($lock['status'], ['pending','approved'], true)) jerr('此年度計劃已送出，請重新整理確認狀態');
    $need = vendor_audit_plan_sign_setting($db)['need'];
    $pool = [];
    if ($need) {
        $pool = vendor_audit_plan_approver_pool($db, $uid);
        if (!$pool) jerr('尚未設定核准人員，請聯絡管理員先於「組織角色綁定設定」的「供應商稽核計劃核准」指定部門或人員');
    }
    $lock = vendor_audit_plan_submit($db, $year, $submitDate, $uid, $uname, $scope);
    if ($lock['status'] === 'pending') {
        vendor_audit_notify_plan_sign($db, $year, array_column($pool, 'id'), $uid, $uname, $scope);
    }
    jout(['lock'=>$lock]);
}
case 'plan_decide': {
    $year = (int)($_POST['year'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $noteIn = trim((string)($_POST['note'] ?? ''));
    $approvedDate = trim((string)($_POST['approved_date'] ?? '')) ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $approvedDate)) jerr('核准日期格式不正確');
    if (!in_array($decision, ['approved','rejected'], true)) jerr('無效的決定');
    if ($decision === 'rejected' && $noteIn === '') jerr('退回必須填寫原因');
    $lock = vendor_audit_plan_lock_get($db, $year, $scope);
    if (!$lock || $lock['status'] !== 'pending') jerr('此年度計劃目前無待核准紀錄');
    $submittedBy = (int)($lock['submitted_by'] ?? 0);
    $pool = vendor_audit_plan_approver_pool($db, $submittedBy); // 已排除送出者本人
    $isSubmitter = ($uid === $submittedBy);
    $inPool = in_array($uid, array_column($pool, 'id'), true);
    if ($decision === 'approved') {
        // 球員兼裁判只擋「核准」：若還有其他合格人選，即使是管理者也不准自己核准自己送出的計劃，
        // 只有全公司真的找不到別人(pool為空)時才放行讓送出者(仍須canAdmin)自行核准，避免卡死(使用者2026-08-10明確要求)。
        if ($isSubmitter) {
            if ($pool) jerr('您是本計劃的送出人，請改由其他核准人員核准，避免球員兼裁判', 403);
            if (!$perms['canAdmin']) jerr('您不是本計劃的核准人員', 403);
        } elseif (!$perms['canAdmin'] && !$inPool) {
            jerr('您不是本計劃的核准人員', 403);
        }
    } else {
        // 退回不算球員兼裁判：送出人自己撤回等同簡化版取消送出，允許；合格核准人/管理者也能退回。
        if (!$isSubmitter && !$inPool && !$perms['canAdmin']) jerr('您不是本計劃的核准人員', 403);
    }
    if ($decision === 'approved') {
        $db->prepare("UPDATE vendor_audit_plan_lock SET status='approved', approved_by_name=?, approved_at=NOW(), approved_date=? WHERE year=? AND scope=?")
           ->execute([$uname, $approvedDate, $year, $scope]);
    } else {
        $db->prepare("UPDATE vendor_audit_plan_lock SET status='rejected' WHERE year=? AND scope=?")->execute([$year, $scope]);
    }
    vendor_audit_close_plan_notice($db, $year, $uid, $scope);
    vendor_audit_notify_plan_result($db, $year, $lock['submitted_by'] ? (int)$lock['submitted_by'] : null, $uname, $decision, $noteIn ?: null, $scope);
    jout(['status'=>$decision]);
}
case 'get_approver_chain': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    jout(['chain'=>vendor_audit_plan_approver_chain($db), 'methods'=>VENDOR_AUDIT_APPROVER_METHODS]);
}
case 'save_approver_chain': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $chain = json_decode((string)($_POST['chain'] ?? '[]'), true);
    if (!is_array($chain)) jerr('格式不正確');
    vendor_audit_plan_approver_chain_save($db, $chain);
    jout(['chain'=>vendor_audit_plan_approver_chain($db)]);
}
/* 超級管理員或被授權的管理員：取消已送出/已核准的年度計畫，解除鎖定回到可增列對象的狀態
   (2026-08-06使用者明確要求；密碼驗證改用全站共用的操作確認密碼 confirm_password_lib.php，
   不再是vendor_audit自己的一套，也不限定僅id=1能執行) */
case 'plan_cancel': {
    if (!eg_confirm_password_allowed($db, $uid)) jerr('您沒有取消已送出計畫的權限', 403);
    $pwCheck = eg_confirm_password_verify($db, $uid, (string)($_POST['password'] ?? ''));
    if (!$pwCheck['ok']) jerr($pwCheck['msg']);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000) jerr('年度不正確');
    $lock = vendor_audit_plan_lock_get($db, $year, $scope);
    if (!$lock) jerr('此年度計畫尚未送出，無需取消');
    $db->prepare("DELETE FROM vendor_audit_plan_lock WHERE year=? AND scope=?")->execute([$year, $scope]);
    $db->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by) VALUES ('views/pm/vendor_audit.php', ?, ?, NOW(), ?)")
       ->execute(['取消送出計畫', $year.'年度稽核計畫（'.vendor_audit_scope_label($scope).'）原狀態：'.$lock['status'].'，已由 '.$uname.'（操作確認密碼驗證）取消鎖定，可重新增列對象/送出', $uname]);
    jout(['ok'=>true]);
}
case 'save_plan_sign_setting': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $need = (int)($_POST['need'] ?? 0) === 1 ? 1 : 0;
    vendor_audit_plan_sign_save_setting($db, $need);
    jout(['setting'=>vendor_audit_plan_sign_setting($db)]);
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
