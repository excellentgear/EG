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
    jout(['perms'=>$perms, 'cur_year'=>(int)date('Y'),
          'cur_half'=>((int)date('n') <= 6 ? 1 : 2), 'today'=>date('Y-m-d'),
          'cycle_months'=>vendor_audit_cycle_months($db), 'main_categories'=>$cats]);
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
        $st = $db->prepare("SELECT t.target_id, t.maker_id_no, t.audit_date, t.result, t.score, t.auditor,
                                   t.report_no, t.note, t.added_by_name,
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
    try {
        $db->beginTransaction();
        $rid = vendor_audit_round_id($db, $year, $half, true, $u);
        $ins = $db->prepare("INSERT IGNORE INTO vendor_audit_target (round_id, maker_id_no, added_by, added_by_name)
                             SELECT ?, m.maker_id_no, ?, ? FROM maker_list m
                             WHERE m.maker_id_no=? AND (m.status IS NULL OR m.status<>?)");
        $n = 0;
        foreach ($ids as $mid) { $ins->execute([$rid, $uid, $uname, $mid, VENDOR_AUDIT_DISABLED]); $n += $ins->rowCount(); }
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
        $ins = $db->prepare("INSERT IGNORE INTO vendor_audit_target (round_id, maker_id_no, added_by, added_by_name) VALUES (?,?,?,?)");
        foreach ($picked as $mid) $ins->execute([$rid, $mid, $uid, $uname]);
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

/* 登錄/修正稽核結果 */
case 'record_target': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $tid = (int)($_POST['target_id'] ?? 0);
    $st = $db->prepare("SELECT 1 FROM vendor_audit_target WHERE target_id=?");
    $st->execute([$tid]);
    if (!$st->fetchColumn()) jerr('找不到對象');
    $auditDate = trim((string)($_POST['audit_date'] ?? ''));
    $clear = ($auditDate === '');
    if (!$clear && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDate)) jerr('稽核日格式不正確');
    $result = in_array($_POST['result'] ?? '', ['pass','conditional','fail'], true) ? $_POST['result'] : null;
    $score = ($_POST['score'] ?? '') === '' ? null : (int)$_POST['score'];
    $auditor = trim((string)($_POST['auditor'] ?? '')) ?: null;
    $reportNo = trim((string)($_POST['report_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE vendor_audit_target SET audit_date=?, result=?, score=?, auditor=?, report_no=?, note=? WHERE target_id=?")
           ->execute([$clear ? null : $auditDate, $clear ? null : $result, $score, $auditor, $reportNo, $note, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout([]);
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
    jout(['cycle_months'=>vendor_audit_cycle_months($db)]);
}

/* 某廠商跨期稽核歷史 */
case 'vendor_history': {
    $mid = trim((string)($_GET['maker_id_no'] ?? ''));
    $st = $db->prepare("SELECT r.year, r.half, t.audit_date, t.result, t.score, t.auditor, t.report_no, t.note
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
