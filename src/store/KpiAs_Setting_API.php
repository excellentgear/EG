<?php
/**
 * AS9100 關鍵績效指標 設定 API（僅 KPI 管理者）
 * 指標主檔/年度版本(目標/公式/參數/擔當者)、資料來源目錄、權限規則(部門×主管階級/指定人員)、
 * NAS附件路徑與上限、年度複製、變更歷史
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/kpi_as_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    kpi_as_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = kpi_as_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$perms = kpi_as_perms($db, $u);
if (!$perms['canAdmin']) jerr('僅KPI管理者可使用設定功能', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$curY = (int)date('Y');

switch ($action) {

case 'get_all': {
    $year = max(2025, min($curY, (int)($_GET['year'] ?? $curY)));
    kpi_as_ensure_year($db, $year);
    $st = $db->prepare("SELECT i.indicator_id, i.item_no, i.name, i.clause, i.stat_desc, i.freq, i.value_type,
                               i.sort_order, i.is_active AS ind_active,
                               y.iy_id, y.owner_user_id, y.owner_dept_id, y.owner_position_id, y.owner_display,
                               y.source_mode, y.calculator_key,
                               y.params_json, y.target_direction, y.target_value, y.target_unit, y.target_text,
                               y.is_active AS year_active, y.Modified_By, y.Modified_At
                        FROM kpi_as_indicator i
                        LEFT JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                        ORDER BY i.sort_order, i.item_no");
    $st->execute([$year]);
    $indicators = $st->fetchAll(PDO::FETCH_ASSOC);

    $rules = $db->query("SELECT r.*, d.name AS dept_name, us.user_cname AS user_name
                         FROM kpi_as_perm_rule r
                         LEFT JOIN department d ON d.id=r.dept_id
                         LEFT JOIN user us ON us.id=r.user_id
                         ORDER BY r.rule_id")->fetchAll(PDO::FETCH_ASSOC);

    $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $users = $db->query("SELECT id, user_cname FROM user WHERE user_cname IS NOT NULL AND user_cname<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $ptypes = $db->query("SELECT process_type_id, process_type FROM process_type WHERE is_active=1 ORDER BY process_type_id")->fetchAll(PDO::FETCH_ASSOC);
    $machines = $db->query("SELECT machine_id, machine, machine_type_id FROM machine_list WHERE state IS NULL OR state=0 ORDER BY machine_id")->fetchAll(PDO::FETCH_ASSOC);
    $rtypes = [];
    try { $rtypes = $db->query("SELECT type_id, type_name FROM ir_return_type ORDER BY type_id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

    // 部門→人員(含職稱/主管階級)：擔當者「先選部門再選人」用
    // 依職稱順序排序（position.sort_order 越小職位越大，排前面；比照 department_job_title_settings.php）
    $deptMembers = [];
    $st = $db->query("SELECT m.department_id, m.user_id, u.user_cname, m.is_main, m.position_id,
                             p.name AS position_name, p.sort_order AS pos_sort, pl.level AS mgr_level
                      FROM user_department_position_map m
                      JOIN user u ON u.id=m.user_id
                      LEFT JOIN position p ON p.id=m.position_id
                      LEFT JOIN position_level pl ON pl.position_id=m.position_id
                      WHERE u.user_cname IS NOT NULL AND u.user_cname<>''
                      ORDER BY m.department_id, (p.sort_order IS NULL), p.sort_order, m.user_id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $deptMembers[(int)$r['department_id']][] = [
            'user_id'=>(int)$r['user_id'], 'cname'=>$r['user_cname'],
            'position_id'=>$r['position_id']===null ? null : (int)$r['position_id'],
            'position_name'=>$r['position_name'],
            'pos_sort'=>$r['pos_sort']===null ? null : (int)$r['pos_sort'],
            'is_main'=>(int)$r['is_main'],
            'mgr_level'=>$r['mgr_level']===null ? null : (int)$r['mgr_level']];
    }

    jout([
        'year'=>$year, 'years'=>range(2025, $curY),
        'indicators'=>$indicators,
        'registry'=>kpi_as_registry(),
        'rules'=>$rules,
        'settings'=>[
            'attach_base'=>kpi_as_setting($db, 'kpi_attach_base'),
            'attach_base_effective'=>kpi_as_attach_base($db),
            'attach_base_ok'=>is_dir(kpi_as_attach_base($db)),
            'attach_max'=>kpi_as_attach_max($db),
        ],
        'dicts'=>['departments'=>$depts, 'users'=>$users, 'process_types'=>$ptypes,
                  'machines'=>$machines, 'return_types'=>$rtypes, 'dept_members'=>$deptMembers],
    ]);
}

/* ---------- 年度版本（目標/公式/參數/擔當者）：改了什麼就記什麼 ---------- */
case 'save_iy': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2025 || $year > $curY) jerr('年度不合法');
    kpi_as_ensure_year($db, $year);
    $st = $db->prepare("SELECT * FROM kpi_as_indicator_year WHERE indicator_id=? AND year=?");
    $st->execute([$iid, $year]);
    $old = $st->fetch(PDO::FETCH_ASSOC);
    if (!$old) jerr('找不到年度版本');

    $sourceMode = ($_POST['source_mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
    $calc = trim((string)($_POST['calculator_key'] ?? ''));
    if ($sourceMode === 'auto') {
        if ($calc !== '__builder__' && ($calc === '' || !isset(kpi_as_registry()[$calc])))
            jerr('自動模式必須選擇有效的資料來源');
    } else { $calc = null; }
    $paramsJson = trim((string)($_POST['params_json'] ?? ''));
    if ($paramsJson !== '') {
        $pj = json_decode($paramsJson, true);
        if (!is_array($pj)) jerr('參數JSON格式錯誤');
        $paramsJson = json_encode($pj, JSON_UNESCAPED_UNICODE);
    } else { $paramsJson = null; }
    $dir = in_array($_POST['target_direction'] ?? '', ['gte','lte','yes'], true) ? $_POST['target_direction'] : 'gte';
    $tval = ($_POST['target_value'] ?? '') === '' ? null : (float)$_POST['target_value'];
    $tunit = mb_substr(trim((string)($_POST['target_unit'] ?? '')), 0, 20);
    $ttext = mb_substr(trim((string)($_POST['target_text'] ?? '')), 0, 60);
    $ownerId = (int)($_POST['owner_user_id'] ?? 0) ?: null;
    $ownerDeptId = (int)($_POST['owner_dept_id'] ?? 0) ?: null;
    $ownerPosId = (int)($_POST['owner_position_id'] ?? 0) ?: null;
    $ownerDisp = mb_substr(trim((string)($_POST['owner_display'] ?? '')), 0, 50);
    $active = (int)($_POST['is_active'] ?? 1) ? 1 : 0;

    $new = ['owner_user_id'=>$ownerId, 'owner_dept_id'=>$ownerDeptId, 'owner_position_id'=>$ownerPosId,
            'owner_display'=>$ownerDisp, 'source_mode'=>$sourceMode,
            'calculator_key'=>$calc, 'params_json'=>$paramsJson, 'target_direction'=>$dir,
            'target_value'=>$tval, 'target_unit'=>$tunit, 'target_text'=>$ttext, 'is_active'=>$active];
    $db->beginTransaction();
    try {
        // 先存部門+職位，再存人員（比照使用者要求的儲存順序），單句 UPDATE 一次寫入
        $st = $db->prepare("UPDATE kpi_as_indicator_year SET owner_dept_id=?, owner_position_id=?, owner_user_id=?,
                            owner_display=?, source_mode=?,
                            calculator_key=?, params_json=?, target_direction=?, target_value=?, target_unit=?,
                            target_text=?, is_active=?, Modified_By=?, Modified_At=NOW()
                            WHERE indicator_id=? AND year=?");
        $st->execute([$ownerDeptId, $ownerPosId, $ownerId, $ownerDisp, $sourceMode, $calc, $paramsJson, $dir, $tval, $tunit, $ttext,
                      $active, $u['user_cname'], $iid, $year]);
        foreach ($new as $k => $v) {
            $ov = $old[$k];
            if ((string)$ov !== (string)$v) {
                kpi_as_log($db, $iid, $year, null, 'setting', $k, $ov, $v, null, $u);
            }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout([]);
}

/* ---------- 指標主檔 ---------- */
case 'save_indicator': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM kpi_as_indicator WHERE indicator_id=?");
    $st->execute([$iid]);
    $old = $st->fetch(PDO::FETCH_ASSOC);
    if (!$old) jerr('找不到指標');
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    if ($name === '') jerr('指標內容必填');
    $clause = mb_substr(trim((string)($_POST['clause'] ?? '')), 0, 200);
    $statDesc = mb_substr(trim((string)($_POST['stat_desc'] ?? '')), 0, 200);
    $freq = in_array($_POST['freq'] ?? '', ['monthly','quarterly','halfyear','yearly'], true) ? $_POST['freq'] : 'monthly';
    $vt = in_array($_POST['value_type'] ?? '', ['percent','count','score','rate','yesno'], true) ? $_POST['value_type'] : 'percent';
    $sort = (int)($_POST['sort_order'] ?? $old['sort_order']);
    $active = (int)($_POST['is_active'] ?? 1) ? 1 : 0;
    $db->beginTransaction();
    try {
        $st = $db->prepare("UPDATE kpi_as_indicator SET name=?, clause=?, stat_desc=?, freq=?, value_type=?,
                            sort_order=?, is_active=?, Modified_By=?, Modified_At=NOW() WHERE indicator_id=?");
        $st->execute([$name, $clause, $statDesc, $freq, $vt, $sort, $active, $u['user_cname'], $iid]);
        foreach (['name'=>$name,'clause'=>$clause,'stat_desc'=>$statDesc,'freq'=>$freq,'value_type'=>$vt,
                  'sort_order'=>$sort,'is_active'=>$active] as $k => $v) {
            if ((string)$old[$k] !== (string)$v) kpi_as_log($db, $iid, null, null, 'setting', 'indicator.'.$k, $old[$k], $v, null, $u);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout([]);
}

case 'add_indicator': {
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    if ($name === '') jerr('指標內容必填');
    $year = max(2025, min($curY, (int)($_POST['year'] ?? $curY)));
    $itemNo = (int)($_POST['item_no'] ?? 0);
    if ($itemNo <= 0) {
        $itemNo = (int)$db->query("SELECT COALESCE(MAX(item_no),0)+1 FROM kpi_as_indicator")->fetchColumn();
    }
    $st = $db->prepare("SELECT 1 FROM kpi_as_indicator WHERE item_no=?");
    $st->execute([$itemNo]);
    if ($st->fetchColumn()) jerr("項次 {$itemNo} 已存在");
    $freq = in_array($_POST['freq'] ?? '', ['monthly','quarterly','halfyear','yearly'], true) ? $_POST['freq'] : 'monthly';
    $vt = in_array($_POST['value_type'] ?? '', ['percent','count','score','rate','yesno'], true) ? $_POST['value_type'] : 'percent';
    $db->beginTransaction();
    try {
        $st = $db->prepare("INSERT INTO kpi_as_indicator (item_no,name,clause,stat_desc,freq,value_type,sort_order,Modified_By,Modified_At)
                            VALUES (?,?,?,?,?,?,?,?,NOW())");
        $st->execute([$itemNo, $name, mb_substr(trim((string)($_POST['clause'] ?? '')), 0, 200),
                      mb_substr(trim((string)($_POST['stat_desc'] ?? '')), 0, 200), $freq, $vt, $itemNo, $u['user_cname']]);
        $iid = (int)$db->lastInsertId();
        $st = $db->prepare("INSERT INTO kpi_as_indicator_year (indicator_id,year,source_mode,target_direction,Created_By)
                            VALUES (?,?,'manual','gte',?)");
        $st->execute([$iid, $year, $u['user_cname']]);
        kpi_as_log($db, $iid, $year, null, 'setting', 'add_indicator', null, $name, '新增指標 項次'.$itemNo, $u);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('新增失敗：'.$e->getMessage(), 500); }
    jout(['indicator_id'=>$iid ?? 0]);
}

case 'copy_year': {
    $from = (int)($_POST['from'] ?? 0);
    $to = (int)($_POST['to'] ?? 0);
    if ($from < 2025 || $to < 2025 || $to > $curY || $from === $to) jerr('年度不合法');
    $st = $db->prepare("INSERT INTO kpi_as_indicator_year
        (indicator_id,year,owner_user_id,owner_display,source_mode,calculator_key,params_json,
         target_direction,target_value,target_unit,target_text,Created_By)
        SELECT s.indicator_id, ?, s.owner_user_id, s.owner_display, s.source_mode, s.calculator_key, s.params_json,
               s.target_direction, s.target_value, s.target_unit, s.target_text, ?
        FROM kpi_as_indicator_year s
        WHERE s.year=? AND NOT EXISTS
          (SELECT 1 FROM kpi_as_indicator_year t WHERE t.indicator_id=s.indicator_id AND t.year=?)");
    $st->execute([$to, $u['user_cname'], $from, $to]);
    kpi_as_log($db, null, $to, null, 'setting', 'copy_year', $from, $to, "複製 {$from} 年設定至 {$to} 年（僅補缺漏）", $u);
    jout(['copied'=>$st->rowCount()]);
}

/* ---------- 附件/系統設定 ---------- */
case 'save_settings': {
    $base = trim((string)($_POST['attach_base'] ?? ''));
    $max = (int)($_POST['attach_max'] ?? 5);
    if ($max < 1 || $max > 50) jerr('每月附件上限需介於 1~50');
    $up = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                        VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                                updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)");
    $oldBase = kpi_as_setting($db, 'kpi_attach_base');
    $oldMax = kpi_as_attach_max($db);
    $up->execute(['kpi_attach_base', rtrim($base, '\\/'), $uid, $u['user_cname']]);
    $up->execute(['kpi_attach_max', (string)$max, $uid, $u['user_cname']]);
    if ($oldBase !== rtrim($base, '\\/')) kpi_as_log($db, null, null, null, 'setting', 'kpi_attach_base', $oldBase, $base, null, $u);
    if ($oldMax !== $max) kpi_as_log($db, null, null, null, 'setting', 'kpi_attach_max', $oldMax, $max, null, $u);
    jout(['attach_base_effective'=>kpi_as_attach_base($db), 'attach_base_ok'=>is_dir(kpi_as_attach_base($db))]);
}

/* ---------- 出貨目標達成率(週報)基礎設定：週目標金額/帳款起始日，共用 kpi_lib.php，Shipping_Analysis_new.php 存廢不影響這裡 ---------- */
case 'kpi_target_get': {
    require_once $document_root . '/EGsystem/src/common/kpi_lib.php';
    $y = (int)($_GET['year'] ?? $curY);
    $m = (int)($_GET['month'] ?? date('n'));
    jout(kpi_target_get($db, $y, $m));
}
case 'kpi_target_save': {
    require_once $document_root . '/EGsystem/src/common/kpi_lib.php';
    $y = (int)($_POST['year'] ?? 0);
    $m = (int)($_POST['month'] ?? 0);
    if ($y < 2000 || $m < 1 || $m > 12) jerr('年月參數錯誤');
    kpi_target_save($db, $y, $m, (float)($_POST['target_amount'] ?? 0), (int)($_POST['start_day'] ?? 1));
    jout([]);
}

/* ---------- 權限規則 ---------- */
case 'perm_add': {
    $pt = in_array($_POST['perm_type'] ?? '', ['view','fill','admin'], true) ? $_POST['perm_type'] : '';
    $rt = in_array($_POST['rule_type'] ?? '', ['dept_level','user'], true) ? $_POST['rule_type'] : '';
    if ($pt === '' || $rt === '') jerr('請選擇授權能力與規則類型');
    $deptId = null; $minLevel = null; $ruleUid = null;
    if ($rt === 'dept_level') {
        $deptId = (int)($_POST['dept_id'] ?? 0) ?: null;
        $minLevel = (int)($_POST['min_level'] ?? 0);
        if ($minLevel < 1 || $minLevel > 9) jerr('主管階級門檻需為 1(一階,最高)~9');
    } else {
        $ruleUid = (int)($_POST['user_id'] ?? 0);
        if ($ruleUid <= 0) jerr('請選擇指定人員');
    }
    $st = $db->prepare("INSERT INTO kpi_as_perm_rule (perm_type,rule_type,dept_id,min_level,user_id,Created_By)
                        VALUES (?,?,?,?,?,?)");
    $st->execute([$pt, $rt, $deptId, $minLevel, $ruleUid, $u['user_cname']]);
    kpi_as_log($db, null, null, null, 'perm', 'add',
               null, json_encode(['perm'=>$pt,'rule'=>$rt,'dept'=>$deptId,'level'=>$minLevel,'user'=>$ruleUid], JSON_UNESCAPED_UNICODE), null, $u);
    jout(['rule_id'=>(int)$db->lastInsertId()]);
}

case 'perm_del': {
    $rid = (int)($_POST['rule_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM kpi_as_perm_rule WHERE rule_id=?");
    $st->execute([$rid]);
    $old = $st->fetch(PDO::FETCH_ASSOC);
    if (!$old) jerr('找不到規則');
    $db->prepare("DELETE FROM kpi_as_perm_rule WHERE rule_id=?")->execute([$rid]);
    kpi_as_log($db, null, null, null, 'perm', 'delete',
               json_encode($old, JSON_UNESCAPED_UNICODE), null, null, $u);
    jout([]);
}

/* ---------- 變更歷史 ---------- */
case 'log_list': {
    $iid = (int)($_GET['indicator_id'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);
    $limit = min(500, max(20, (int)($_GET['limit'] ?? 200)));
    $sql = "SELECT l.*, i.item_no, i.name AS indicator_name
            FROM kpi_as_change_log l
            LEFT JOIN kpi_as_indicator i ON i.indicator_id=l.indicator_id WHERE 1=1";
    $bind = [];
    if ($iid > 0)  { $sql .= " AND l.indicator_id=?"; $bind[] = $iid; }
    if ($year > 0) { $sql .= " AND l.year=?"; $bind[] = $year; }
    $sql .= " ORDER BY l.log_id DESC LIMIT " . $limit;
    $st = $db->prepare($sql);
    $st->execute($bind);
    jout(['list'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---------- 資料來源目錄 no-code builder ---------- */
case 'get_catalog': {
    jout([
        'catalog'=>kpi_as_catalog($db),
        'ops'=>kpi_as_builder_ops(),
        'is_sysadmin'=>$perms['isAdmin'],   // 目錄增修(需知道表名)僅系統管理者
    ]);
}

// 取某篩選欄位的現有值（供值選單，非IT免手打）
case 'get_field_values': {
    $fid = (int)($_GET['field_id'] ?? 0);
    $st = $db->prepare("SELECT f.column_name, c.table_name
                        FROM kpi_ds_field f JOIN kpi_ds_catalog c ON c.ds_id=f.ds_id
                        WHERE f.field_id=? AND f.is_active=1 AND c.is_active=1");
    $st->execute([$fid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) jerr('欄位不存在');
    if (!kpi_as_ident_ok($r['column_name']) || !kpi_as_ident_ok($r['table_name'])) jerr('欄位名不合法');
    $col = '`' . $r['column_name'] . '`'; $tbl = '`' . $r['table_name'] . '`';
    $rows = $db->query("SELECT DISTINCT $col AS v FROM $tbl WHERE $col IS NOT NULL AND $col<>'' ORDER BY v LIMIT 300")
               ->fetchAll(PDO::FETCH_COLUMN);
    jout(['values'=>$rows]);
}

// 試算 builder（存檔前預覽某月結果）
case 'preview_builder': {
    $spec = json_decode((string)($_POST['spec'] ?? '{}'), true);
    if (!is_array($spec)) jerr('spec 格式錯誤');
    $year = max(2025, min($curY, (int)($_POST['year'] ?? $curY)));
    $month = (int)($_POST['month'] ?? (int)date('n'));
    $res = kpi_as_builder_compute($db, $year, $month, $spec);
    jout(['result'=>$res]);
}

case 'catalog_save': {
    if (!$perms['isAdmin']) jerr('資料表登記僅系統管理者可操作（需了解資料庫結構）', 403);
    $dsId = (int)($_POST['ds_id'] ?? 0);
    $label = mb_substr(trim((string)($_POST['ds_label'] ?? '')), 0, 60);
    $table = trim((string)($_POST['table_name'] ?? ''));
    $dateCol = trim((string)($_POST['date_column'] ?? ''));
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
    $active = (int)($_POST['is_active'] ?? 1) ? 1 : 0;
    if ($label === '' || !kpi_as_ident_ok($table) || !kpi_as_ident_ok($dateCol))
        jerr('名稱必填；資料表與日期欄需為合法識別字');
    // 驗證表與日期欄真的存在
    $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $chk->execute([$table, $dateCol]);
    if (!$chk->fetchColumn()) jerr("資料表 {$table} 或日期欄 {$dateCol} 不存在");
    if ($dsId > 0) {
        $st = $db->prepare("UPDATE kpi_ds_catalog SET ds_label=?, table_name=?, date_column=?, note=?, is_active=? WHERE ds_id=?");
        $st->execute([$label, $table, $dateCol, $note, $active, $dsId]);
    } else {
        $mx = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM kpi_ds_catalog")->fetchColumn();
        $st = $db->prepare("INSERT INTO kpi_ds_catalog (ds_label, table_name, date_column, note, is_active, sort_order, Created_By) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$label, $table, $dateCol, $note, $active, $mx, $u['user_cname']]);
        $dsId = (int)$db->lastInsertId();
    }
    kpi_as_log($db, null, null, null, 'catalog', 'save_ds', null, $label, $table, $u);
    jout(['ds_id'=>$dsId]);
}

case 'catalog_del': {
    if (!$perms['isAdmin']) jerr('僅系統管理者可刪除', 403);
    $dsId = (int)($_POST['ds_id'] ?? 0);
    $db->prepare("DELETE FROM kpi_ds_field WHERE ds_id=?")->execute([$dsId]);
    $db->prepare("DELETE FROM kpi_ds_catalog WHERE ds_id=?")->execute([$dsId]);
    kpi_as_log($db, null, null, null, 'catalog', 'del_ds', $dsId, null, null, $u);
    jout([]);
}

case 'field_save': {
    if (!$perms['isAdmin']) jerr('欄位登記僅系統管理者可操作', 403);
    $fid = (int)($_POST['field_id'] ?? 0);
    $dsId = (int)($_POST['ds_id'] ?? 0);
    $label = mb_substr(trim((string)($_POST['field_label'] ?? '')), 0, 60);
    $col = trim((string)($_POST['column_name'] ?? ''));
    $role = in_array($_POST['role'] ?? '', ['filter','measure'], true) ? $_POST['role'] : 'filter';
    $dtype = in_array($_POST['data_type'] ?? '', ['text','number','date'], true) ? $_POST['data_type'] : 'text';
    $active = (int)($_POST['is_active'] ?? 1) ? 1 : 0;
    if ($label === '' || !kpi_as_ident_ok($col)) jerr('欄位中文名必填；欄位名需為合法識別字');
    // 驗證欄位存在於該來源的表
    $st = $db->prepare("SELECT table_name FROM kpi_ds_catalog WHERE ds_id=?");
    $st->execute([$dsId]);
    $tbl = $st->fetchColumn();
    if (!$tbl) jerr('資料來源不存在');
    $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $chk->execute([$tbl, $col]);
    if (!$chk->fetchColumn()) jerr("欄位 {$col} 不存在於資料表 {$tbl}");
    if ($fid > 0) {
        $st = $db->prepare("UPDATE kpi_ds_field SET field_label=?, column_name=?, role=?, data_type=?, is_active=? WHERE field_id=?");
        $st->execute([$label, $col, $role, $dtype, $active, $fid]);
    } else {
        $mx = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM kpi_ds_field WHERE ds_id=" . (int)$dsId)->fetchColumn();
        $st = $db->prepare("INSERT INTO kpi_ds_field (ds_id, field_label, column_name, role, data_type, sort_order) VALUES (?,?,?,?,?,?)");
        $st->execute([$dsId, $label, $col, $role, $dtype, $mx]);
        $fid = (int)$db->lastInsertId();
    }
    kpi_as_log($db, null, null, null, 'catalog', 'save_field', null, $label, $col, $u);
    jout(['field_id'=>$fid]);
}

case 'field_del': {
    if (!$perms['isAdmin']) jerr('僅系統管理者可刪除', 403);
    $fid = (int)($_POST['field_id'] ?? 0);
    $db->prepare("DELETE FROM kpi_ds_field WHERE field_id=?")->execute([$fid]);
    kpi_as_log($db, null, null, null, 'catalog', 'del_field', $fid, null, null, $u);
    jout([]);
}

default:
    jerr('未知動作：' . $action);
}
