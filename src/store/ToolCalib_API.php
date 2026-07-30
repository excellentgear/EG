<?php
/**
 * 量測儀器校驗管理 API
 * 權限：tool_calib_lib.php tool_calib_perms()（roles module='tool_calib'；admin⊃edit⊃view），fail-closed
 * 讀：GET；寫：POST。所有寫入用 transaction。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/tool_calib_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    tool_calib_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = tool_calib_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = tool_calib_perms($db, $u);
if (!$perms['canView']) jerr('無量測儀器校驗檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 取單支量具（含類別名稱） */
function tc_get_tool(PDO $db, int $tid): ?array {
    $st = $db->prepare("SELECT t.*, l.QC_Tool AS category_name
                        FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                        WHERE t.Tool_id=?");
    $st->execute([$tid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 類別守門：不需校驗／不可設編號的類別不得掛量具編號（fail-closed，找不到類別直接擋） */
function tc_assert_category_usable(PDO $db, string $catId): void {
    $st = $db->prepare("SELECT QC_Tool, COALESCE(calib_required,1) AS req, COALESCE(has_tool_no,1) AS hasno
                        FROM qc_tool_list WHERE QC_Tool_List_id=? LIMIT 1");
    $st->execute([$catId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到量具類別');
    if ((int)$c['hasno'] !== 1) jerr('類別「'.$c['QC_Tool'].'」已設為不可設定量具編號（僅為檢驗方式），請改選其他類別');
    if ((int)$c['req'] !== 1) jerr('類別「'.$c['QC_Tool'].'」已設為不需校驗，不能在本頁建立/移入量具');
}

/** 依現有紀錄重算某支量具的下次應校驗日（刪除紀錄後修復用） */
function tc_recompute_due(PDO $db, int $tid): void {
    $st = $db->prepare("SELECT next_due, due_date FROM qc_tool_calibration
                        WHERE Tool_id=? ORDER BY calib_date DESC, calib_id DESC LIMIT 1");
    $st->execute([$tid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    // 有紀錄→用最近一次的 next_due；無紀錄→保留原到期(不動)
    if ($r && !empty($r['next_due'])) {
        $db->prepare("UPDATE qc_tool SET calibration_due=? WHERE Tool_id=?")->execute([$r['next_due'], $tid]);
    }
}

switch ($action) {

/* ---------- 基本資訊 ---------- */
case 'meta': {
    tool_calib_purge_temp_attach($db);          // 順路清除過期暫存附件
    $cfg = tool_calib_attach_cfg($db);
    jout(['perms'=>$perms, 'categories'=>tool_calib_categories($db), 'tabs'=>tool_calib_tabs($db),
          'cur_ym'=>date('Y-m'), 'today'=>date('Y-m-d'),
          'attach'=>['types'=>$cfg['types'], 'ext'=>$cfg['ext'], 'maxmb'=>$cfg['maxmb'],
                     'dir'=>$perms['canAdmin'] ? $cfg['dir'] : '',
                     'ext_raw'=>$cfg['ext_raw'], 'types_raw'=>$cfg['types_raw']]]);
}

/* ---------- 校驗附件設定（管理員；路徑只存設定值，DB 附件列只存檔名） ---------- */
case 'save_attach_settings': {
    if (!$perms['canAdmin']) jerr('無附件設定權限', 403);
    $dir   = trim((string)($_POST['dir'] ?? ''));
    $ext   = trim((string)($_POST['ext'] ?? ''));
    $maxmb = (int)($_POST['maxmb'] ?? 0);
    $types = trim((string)($_POST['types'] ?? ''));
    if ($dir === '') jerr('請填附件存放路徑');
    if ($ext === '') jerr('請填允許的副檔名');
    if ($maxmb <= 0 || $maxmb > 500) jerr('單檔上限請填 1～500（MB）');
    if ($types === '') jerr('請填至少一種文件類別');
    try {
        $db->beginTransaction();
        $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $st->execute(['tool_calib_attach_dir', $dir]);
        $st->execute(['tool_calib_attach_ext', $ext]);
        $st->execute(['tool_calib_attach_maxmb', (string)$maxmb]);
        $st->execute(['tool_calib_attach_types', $types]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    $cfg = tool_calib_attach_cfg($db);
    jout(['attach'=>['types'=>$cfg['types'], 'ext'=>$cfg['ext'], 'maxmb'=>$cfg['maxmb'], 'dir'=>$cfg['dir'],
                     'ext_raw'=>$cfg['ext_raw'], 'types_raw'=>$cfg['types_raw']]]);
}

/* ---------- 儀器清單 + 當月統計 ---------- */
case 'list': {
    $ym = $_GET['ym'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    [$y, $m] = array_map('intval', explode('-', $ym));

    $st = $db->query("SELECT t.Tool_id, t.Tool_No, t.QC_Tool_List_id, t.calibration_due,
                             t.calib_cycle_months, t.calib_managed, t.calib_method,
                             l.QC_Tool AS category_name,
                             COALESCE(l.calib_required,1) AS cat_required,
                             COALESCE(l.calib_tab,0)      AS cat_tab
                      FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                      ORDER BY t.calib_managed DESC, t.calibration_due IS NULL, t.calibration_due ASC, t.Tool_No ASC");
    $tools = $st->fetchAll(PDO::FETCH_ASSOC);

    // 每支最近一次校驗
    $last = [];
    foreach ($db->query("SELECT c.Tool_id, c.calib_date, c.result, c.method, c.cert_no, c.operator
                         FROM qc_tool_calibration c
                         JOIN (SELECT Tool_id, MAX(calib_date) md FROM qc_tool_calibration GROUP BY Tool_id) x
                              ON x.Tool_id=c.Tool_id AND x.md=c.calib_date")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $last[(int)$r['Tool_id']] = $r;
    }

    // 類別設「不需校驗」者（例如「目視」＝檢驗方式非量具）不列入本頁與 KPI，只回報筆數提示
    $rows = []; $excluded = 0;
    foreach ($tools as $t) {
        if ((int)$t['cat_required'] !== 1) { $excluded++; continue; }
        $t['calib_managed'] = (int)$t['calib_managed'];
        $t['cat_tab'] = (int)$t['cat_tab'];
        $t['status'] = tool_calib_status($t);
        $t['last'] = $last[(int)$t['Tool_id']] ?? null;
        $rows[] = $t;
    }

    $stat = tool_calib_kpi_compute($db, $y, $m, []);
    jout(['rows'=>$rows, 'ym'=>$ym, 'stat'=>$stat, 'perms'=>$perms,
          'categories'=>tool_calib_categories($db), 'tabs'=>tool_calib_tabs($db), 'excluded'=>$excluded]);
}

/* ---------- 類別校驗屬性設定（管理員；只改旗標，不改名稱/不新增刪除類別） ----------
 * 類別的新增/更名/刪除一律在「線上檢驗－量具設定」(inspection_combined_prototype.php)，本頁不重複提供。
 * 參數 items = JSON [{id, calib_required, has_tool_no, calib_tab, calib_tab_group}, ...]
 *   calib_tab_group：併入的自訂分頁 id；空值＝自成一頁（用類別名）
 */
case 'save_categories': {
    if (!$perms['canAdmin']) jerr('無類別設定權限', 403);
    $items = json_decode((string)($_POST['items'] ?? ''), true);
    if (!is_array($items) || !$items) jerr('無資料可儲存');
    $validTabs = array_column(tool_calib_tabs($db), 'tab_id');
    try {
        $db->beginTransaction();
        $up = $db->prepare("UPDATE qc_tool_list SET calib_required=?, has_tool_no=?, calib_tab=?, calib_tab_group=? WHERE QC_Tool_List_id=?");
        foreach ($items as $it) {
            $id = (int)($it['id'] ?? 0);
            if (!$id) continue;
            $req = (int)($it['calib_required'] ?? 0) === 1 ? 1 : 0;
            $hasNo = (int)($it['has_tool_no'] ?? 0) === 1 ? 1 : 0;
            $tab = ((int)($it['calib_tab'] ?? 0) === 1 && $req === 1) ? 1 : 0;   // 需校驗才可列入分頁
            $grp = (int)($it['calib_tab_group'] ?? 0);
            // 未列入分頁 or 指到不存在的分頁 → 一律歸零成「自成一頁」
            $grp = ($tab === 1 && $grp > 0 && in_array($grp, $validTabs, true)) ? $grp : null;
            $up->execute([$req, $hasNo, $tab, $grp, $id]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['categories'=>tool_calib_categories($db), 'tabs'=>tool_calib_tabs($db)]);
}

/* ---------- 自訂合併分頁：新增/更名（管理員） ---------- */
case 'save_tab': {
    if (!$perms['canAdmin']) jerr('無分頁設定權限', 403);
    $tabId = (int)($_POST['tab_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請輸入分頁名稱');
    if (mb_strlen($name) > 30) jerr('分頁名稱請在 30 字以內');
    $chk = $db->prepare("SELECT tab_id FROM qc_tool_calib_tab WHERE tab_name=? AND tab_id<>? LIMIT 1");
    $chk->execute([$name, $tabId]);
    if ($chk->fetchColumn()) jerr('分頁名稱已存在：'.$name);
    try {
        $db->beginTransaction();
        if ($tabId > 0) {
            $db->prepare("UPDATE qc_tool_calib_tab SET tab_name=? WHERE tab_id=?")->execute([$name, $tabId]);
        } else {
            $so = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM qc_tool_calib_tab")->fetchColumn();
            $db->prepare("INSERT INTO qc_tool_calib_tab (tab_name, sort_order) VALUES (?,?)")->execute([$name, $so]);
            $tabId = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['tab_id'=>$tabId, 'tabs'=>tool_calib_tabs($db), 'categories'=>tool_calib_categories($db)]);
}

/* ---------- 自訂合併分頁：刪除（管理員；成員類別退回「自成一頁」） ---------- */
case 'delete_tab': {
    if (!$perms['canAdmin']) jerr('無分頁設定權限', 403);
    $tabId = (int)($_POST['tab_id'] ?? 0);
    if (!$tabId) jerr('缺少分頁 id');
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE qc_tool_list SET calib_tab_group=NULL WHERE calib_tab_group=?")->execute([$tabId]);
        $db->prepare("DELETE FROM qc_tool_calib_tab WHERE tab_id=?")->execute([$tabId]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    jout(['tabs'=>tool_calib_tabs($db), 'categories'=>tool_calib_categories($db)]);
}

/* ---------- 新增儀器（管理員） ---------- */
case 'create_tool': {
    if (!$perms['canAdmin']) jerr('無新增儀器權限', 403);
    $no  = trim((string)($_POST['tool_no'] ?? ''));
    $cat = trim((string)($_POST['category_id'] ?? ''));
    if ($no === '' || $cat === '') jerr('請填量具編號與類別');
    tc_assert_category_usable($db, $cat);
    $st = $db->prepare("SELECT 1 FROM qc_tool WHERE Tool_No=? LIMIT 1");
    $st->execute([$no]);
    if ($st->fetchColumn()) jerr('量具編號已存在：'.$no);
    $cycle = ($_POST['cycle'] ?? '') === '' ? null : max(0, (int)$_POST['cycle']);
    $managed = (int)($_POST['managed'] ?? 0) === 1 ? 1 : 0;
    $method = trim((string)($_POST['method'] ?? '')) ?: null;
    $baseDue = trim((string)($_POST['baseline_due'] ?? '')) ?: null;
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO qc_tool (Tool_No, QC_Tool_List_id, Created_at, calib_cycle_months, calib_managed, calib_method, calibration_due)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$no, $cat, date('Y-m-d H:i:s'), $cycle, $managed, $method, $baseDue]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('新增失敗：'.$e->getMessage(), 500); }
    jout(['tool_id'=>(int)$db->lastInsertId()]);
}

/* ---------- 設定儀器（管理員）：校驗屬性 + 可編輯編號/類別 ---------- */
case 'save_tool': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $tid = (int)($_POST['tool_id'] ?? 0);
    $t = tc_get_tool($db, $tid);
    if (!$t) jerr('找不到量具');
    $cycle = ($_POST['cycle'] ?? '') === '' ? null : max(0, (int)$_POST['cycle']);
    $managed = (int)($_POST['managed'] ?? 0) === 1 ? 1 : 0;
    $method = trim((string)($_POST['method'] ?? '')) ?: null;
    // baseline_due：允許管理員設定/修正下次應校驗日（尚無紀錄或需校正時）
    $setBase = array_key_exists('baseline_due', $_POST);
    $baseDue = $setBase ? (trim((string)$_POST['baseline_due']) ?: null) : $t['calibration_due'];
    // 可編輯基本資料：量具編號 / 類別（有帶才更新）
    $newNo = array_key_exists('tool_no', $_POST) ? trim((string)$_POST['tool_no']) : $t['Tool_No'];
    $newCat = array_key_exists('category_id', $_POST) && $_POST['category_id'] !== '' ? trim((string)$_POST['category_id']) : $t['QC_Tool_List_id'];
    if ($newNo === '') jerr('量具編號不可空白');
    if ((string)$newCat !== (string)$t['QC_Tool_List_id']) tc_assert_category_usable($db, (string)$newCat);
    if ($newNo !== $t['Tool_No']) {
        $c = $db->prepare("SELECT 1 FROM qc_tool WHERE Tool_No=? AND Tool_id<>? LIMIT 1");
        $c->execute([$newNo, $tid]);
        if ($c->fetchColumn()) jerr('量具編號已存在：'.$newNo);
    }
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE qc_tool SET Tool_No=?, QC_Tool_List_id=?, calib_cycle_months=?, calib_managed=?, calib_method=?, calibration_due=? WHERE Tool_id=?")
           ->execute([$newNo, $newCat, $cycle, $managed, $method, $baseDue, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout([]);
}

/* ---------- 登錄一次校驗完成（登錄權） ---------- */
case 'record_calib': {
    if (!$perms['canEdit']) jerr('無校驗登錄權限', 403);
    $tid = (int)($_POST['tool_id'] ?? 0);
    $t = tc_get_tool($db, $tid);
    if (!$t) jerr('找不到量具');
    $calibDate = trim((string)($_POST['calib_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calibDate)) jerr('請選擇校驗完成日');
    $result = in_array($_POST['result'] ?? '', ['pass','fail','pass_adjust'], true) ? $_POST['result'] : 'pass';
    $method = trim((string)($_POST['method'] ?? '')) ?: ($t['calib_method'] ?: null);
    $operator = trim((string)($_POST['operator'] ?? '')) ?: null;
    $certNo = trim((string)($_POST['cert_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;

    $dueDate = $t['calibration_due'] ?: null;               // 本次滿足的到期日
    $cycle = $t['calib_cycle_months'] !== null ? (int)$t['calib_cycle_months'] : 0;
    $nextDue = $cycle > 0 ? tool_calib_add_months($calibDate, $cycle) : null;

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO qc_tool_calibration
            (Tool_id, due_date, calib_date, result, method, operator, cert_no, next_due, note, created_by, created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$tid, $dueDate, $calibDate, $result, $method, $operator, $certNo, $nextDue, $note, $uid, $uname]);
        // 前滾主檔到期日；並把預設校驗方式更新為本次方式
        $db->prepare("UPDATE qc_tool SET calibration_due=?, calib_method=COALESCE(?, calib_method) WHERE Tool_id=?")
           ->execute([$nextDue, $method, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('登錄失敗：'.$e->getMessage(), 500); }
    jout(['next_due'=>$nextDue]);
}

/* ---------- 編輯校驗紀錄（登錄權；修正誤登） ---------- */
case 'edit_calib': {
    if (!$perms['canEdit']) jerr('無編輯權限', 403);
    $cid = (int)($_POST['calib_id'] ?? 0);
    $st = $db->prepare("SELECT c.*, t.calib_cycle_months FROM qc_tool_calibration c
                        JOIN qc_tool t ON t.Tool_id=c.Tool_id WHERE c.calib_id=?");
    $st->execute([$cid]);
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) jerr('找不到紀錄');
    $tid = (int)$rec['Tool_id'];
    $calibDate = trim((string)($_POST['calib_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calibDate)) jerr('請選擇校驗完成日');
    $result = in_array($_POST['result'] ?? '', ['pass','fail','pass_adjust'], true) ? $_POST['result'] : 'pass';
    $method = trim((string)($_POST['method'] ?? '')) ?: null;
    $operator = trim((string)($_POST['operator'] ?? '')) ?: null;
    $certNo = trim((string)($_POST['cert_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    // 若改到期日基準（管理員）也允許
    $dueDate = array_key_exists('due_date', $_POST)
        ? (trim((string)$_POST['due_date']) ?: null) : $rec['due_date'];
    $cycle = $rec['calib_cycle_months'] !== null ? (int)$rec['calib_cycle_months'] : 0;
    $nextDue = $cycle > 0 ? tool_calib_add_months($calibDate, $cycle) : null;
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE qc_tool_calibration SET due_date=?, calib_date=?, result=?, method=?, operator=?, cert_no=?, next_due=?, note=? WHERE calib_id=?")
           ->execute([$dueDate, $calibDate, $result, $method, $operator, $certNo, $nextDue, $note, $cid]);
        // 若此為該量具最近一次校驗，前滾主檔到期日
        $latest = $db->prepare("SELECT calib_id FROM qc_tool_calibration WHERE Tool_id=? ORDER BY calib_date DESC, calib_id DESC LIMIT 1");
        $latest->execute([$tid]);
        if ((int)$latest->fetchColumn() === $cid)
            $db->prepare("UPDATE qc_tool SET calibration_due=? WHERE Tool_id=?")->execute([$nextDue, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['next_due'=>$nextDue]);
}

/* ---------- 批次校驗（一次登錄多支量具；外校/廠內批量校驗用） ----------
 * 參數：calib_date, method, operator, cert_no, note
 *       tools  = JSON [{tool_id, result}]（result 省略＝pass）
 *       attach = JSON [{attach_id, category_id, doc_type, note, tool_ids:[...]}]（暫存附件轉正＋一對多對應）
 */
case 'create_batch': {
    if (!$perms['canEdit']) jerr('無校驗登錄權限', 403);
    $calibDate = trim((string)($_POST['calib_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calibDate)) jerr('請選擇校驗完成日');
    $method   = trim((string)($_POST['method'] ?? '')) ?: null;
    $operator = trim((string)($_POST['operator'] ?? '')) ?: null;
    $certNo   = trim((string)($_POST['cert_no'] ?? '')) ?: null;
    $note     = trim((string)($_POST['note'] ?? '')) ?: null;
    $tools    = json_decode((string)($_POST['tools'] ?? ''), true);
    if (!is_array($tools) || !$tools) jerr('請至少選擇一支量具');
    $attach   = json_decode((string)($_POST['attach'] ?? '[]'), true);
    if (!is_array($attach)) $attach = [];

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO qc_tool_calib_batch (calib_date, method, operator, cert_no, note, tool_count, created_by, created_by_name)
                      VALUES (?,?,?,?,?,0,?,?)")
           ->execute([$calibDate, $method, $operator, $certNo, $note, $uid, $uname]);
        $batchId = (int)$db->lastInsertId();

        $insRec = $db->prepare("INSERT INTO qc_tool_calibration
            (Tool_id, due_date, calib_date, result, method, operator, cert_no, next_due, note, batch_id, created_by, created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $updTool = $db->prepare("UPDATE qc_tool SET calibration_due=?, calib_method=COALESCE(?, calib_method) WHERE Tool_id=?");
        $getTool = $db->prepare("SELECT Tool_id, calibration_due, calib_cycle_months, calib_method FROM qc_tool WHERE Tool_id=?");

        $done = 0; $skipped = [];
        foreach ($tools as $it) {
            $tid = (int)($it['tool_id'] ?? 0);
            if (!$tid) continue;
            $getTool->execute([$tid]);
            $t = $getTool->fetch(PDO::FETCH_ASSOC);
            if (!$t) { $skipped[] = $tid; continue; }
            $result = in_array($it['result'] ?? '', ['pass','fail','pass_adjust'], true) ? $it['result'] : 'pass';
            $mth = $method ?: ($t['calib_method'] ?: null);
            $cycle = $t['calib_cycle_months'] !== null ? (int)$t['calib_cycle_months'] : 0;
            $nextDue = $cycle > 0 ? tool_calib_add_months($calibDate, $cycle) : null;
            $insRec->execute([$tid, $t['calibration_due'] ?: null, $calibDate, $result, $mth, $operator, $certNo,
                              $nextDue, $note, $batchId, $uid, $uname]);
            $updTool->execute([$nextDue, $mth, $tid]);   // 前滾下次應校驗日（與單筆登錄同邏輯）
            $done++;
        }
        if (!$done) { $db->rollBack(); jerr('沒有成功登錄的量具（請確認所選量具仍存在）'); }
        $db->prepare("UPDATE qc_tool_calib_batch SET tool_count=? WHERE batch_id=?")->execute([$done, $batchId]);

        // 暫存附件轉正 + 重建一對多對應（限本人上傳的 temp，或本批已存在的 active）
        $upAtt = $db->prepare("UPDATE qc_tool_calib_attach
                               SET batch_id=?, status='active', expire_at=NULL, category_id=?, doc_type=?, note=?
                               WHERE attach_id=? AND (status='active' OR (status='temp' AND user_id=?))");
        $delMap = $db->prepare("DELETE FROM qc_tool_calib_attach_map WHERE attach_id=?");
        $insMap = $db->prepare("INSERT IGNORE INTO qc_tool_calib_attach_map (attach_id, Tool_id) VALUES (?,?)");
        $chkTool = $db->prepare("SELECT 1 FROM qc_tool WHERE Tool_id=? LIMIT 1");
        foreach ($attach as $a) {
            $aid = (int)($a['attach_id'] ?? 0);
            if (!$aid) continue;
            $cat = (int)($a['category_id'] ?? 0) ?: null;
            $dtp = trim((string)($a['doc_type'] ?? '')) ?: null;
            $ant = trim((string)($a['note'] ?? '')) ?: null;
            $upAtt->execute([$batchId, $cat, $dtp, $ant, $aid, $uid]);
            $delMap->execute([$aid]);
            foreach ((array)($a['tool_ids'] ?? []) as $mtid) {
                $mtid = (int)$mtid;
                if (!$mtid) continue;
                $chkTool->execute([$mtid]);
                if ($chkTool->fetchColumn()) $insMap->execute([$aid, $mtid]);
            }
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('批次登錄失敗：'.$e->getMessage(), 500); }
    jout(['batch_id'=>$batchId, 'done'=>$done, 'skipped'=>$skipped]);
}

/* ---------- 批次校驗紀錄列表／明細 ---------- */
case 'batch_list': {
    $rows = $db->query("SELECT b.*,
                               (SELECT COUNT(*) FROM qc_tool_calib_attach a WHERE a.batch_id=b.batch_id AND a.status='active') AS attach_count
                        FROM qc_tool_calib_batch b ORDER BY b.calib_date DESC, b.batch_id DESC LIMIT 200")
               ->fetchAll(PDO::FETCH_ASSOC);
    jout(['list'=>$rows]);
}
case 'batch_detail': {
    $bid = (int)($_GET['batch_id'] ?? 0);
    if (!$bid) jerr('缺少批次 id');
    $st = $db->prepare("SELECT * FROM qc_tool_calib_batch WHERE batch_id=?");
    $st->execute([$bid]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) jerr('找不到批次');
    $st = $db->prepare("SELECT c.calib_id, c.Tool_id, c.due_date, c.calib_date, c.result, t.Tool_No, l.QC_Tool AS category_name
                        FROM qc_tool_calibration c
                        JOIN qc_tool t ON t.Tool_id=c.Tool_id
                        LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                        WHERE c.batch_id=? ORDER BY t.Tool_No");
    $st->execute([$bid]);
    jout(['batch'=>$b, 'tools'=>$st->fetchAll(PDO::FETCH_ASSOC),
          'attaches'=>tool_calib_attach_list($db, $bid), 'can_admin'=>$perms['canAdmin']]);
}

/* ---------- 附件：上傳（batch_id=0＝新增批次中，先存 temp 兩天） ---------- */
case 'upload_attach': {
    if (!$perms['canEdit']) jerr('無附件上傳權限', 403);
    $batchId = (int)($_POST['batch_id'] ?? 0);
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('上傳失敗（請確認檔案大小與 PHP 上傳限制）');
    $cfg = tool_calib_attach_cfg($db);
    $orig = basename((string)$_FILES['file']['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $cfg['ext'], true)) jerr('不允許的檔案格式（可用：'.implode('、', $cfg['ext']).'）');
    if ((int)$_FILES['file']['size'] > $cfg['maxmb'] * 1024 * 1024) jerr('檔案超過上限 '.$cfg['maxmb'].' MB');
    if (!is_dir($cfg['dir']) && !@mkdir($cfg['dir'], 0777, true)) jerr('無法建立附件目錄，請確認附件設定的路徑：'.$cfg['dir'], 500);
    $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;   // DB 只存這個檔名（鐵律5）
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $cfg['dir'] . $fname)) jerr('檔案寫入失敗：'.$cfg['dir'], 500);
    $cat = (int)($_POST['category_id'] ?? 0) ?: null;
    $dtp = trim((string)($_POST['doc_type'] ?? '')) ?: null;
    $ant = trim((string)($_POST['note'] ?? '')) ?: null;
    try {
        if ($batchId > 0) {
            $db->prepare("INSERT INTO qc_tool_calib_attach (batch_id, category_id, doc_type, file_name, original_name, file_size, note, user_id, status)
                          VALUES (?,?,?,?,?,?,?,?,'active')")
               ->execute([$batchId, $cat, $dtp, $fname, $orig, (int)$_FILES['file']['size'], $ant, $uid]);
        } else {
            $db->prepare("INSERT INTO qc_tool_calib_attach (batch_id, category_id, doc_type, file_name, original_name, file_size, note, user_id, status, expire_at)
                          VALUES (0,?,?,?,?,?,?,?,'temp', DATE_ADD(NOW(), INTERVAL 2 DAY))")
               ->execute([$cat, $dtp, $fname, $orig, (int)$_FILES['file']['size'], $ant, $uid]);
        }
    } catch (Throwable $e) {
        if (is_file($cfg['dir'].$fname)) @unlink($cfg['dir'].$fname);
        jerr('附件登錄失敗：'.$e->getMessage(), 500);
    }
    jout(['attach_id'=>(int)$db->lastInsertId(), 'original_name'=>$orig,
          'file_size'=>(int)$_FILES['file']['size'], 'doc_type'=>$dtp]);
}

/* ---------- 附件：清單（依批次或依量具） ---------- */
case 'list_attach': {
    $bid = (int)($_GET['batch_id'] ?? 0);
    $tid = (int)($_GET['tool_id'] ?? 0);
    jout(['list'=>tool_calib_attach_list($db, $bid, $tid), 'can_admin'=>$perms['canAdmin']]);
}

/* ---------- 附件：下載（實體路徑一律用設定值＋檔名現場組） ---------- */
case 'download_attach': {
    $aid = (int)($_GET['attach_id'] ?? 0);
    $st = $db->prepare("SELECT file_name, original_name, status, user_id FROM qc_tool_calib_attach WHERE attach_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    if ($a['status'] === 'temp' && (int)$a['user_id'] !== $uid && !$perms['canAdmin']) jerr('無權限下載暫存附件', 403);
    $path = tool_calib_attach_file($db, $a['file_name']);
    if (!is_file($path)) jerr('檔案不存在（可能附件路徑設定已變更或檔案未搬移）：'.$path, 404);
    $name = $a['original_name'] ?: $a['file_name'];
    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ---------- 附件：刪除（temp 限上傳者本人；active 限管理員） ---------- */
case 'delete_attach': {
    $aid = (int)($_POST['attach_id'] ?? 0);
    $st = $db->prepare("SELECT file_name, status, user_id FROM qc_tool_calib_attach WHERE attach_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    if ($a['status'] === 'temp') {
        if ((int)$a['user_id'] !== $uid && !$perms['canAdmin']) jerr('暫存附件僅上傳者本人可刪除', 403);
    } elseif (!$perms['canAdmin']) {
        jerr('刪除正式附件需校驗管理員權限', 403);
    }
    $path = tool_calib_attach_file($db, $a['file_name']);
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM qc_tool_calib_attach_map WHERE attach_id=?")->execute([$aid]);
        $db->prepare("DELETE FROM qc_tool_calib_attach WHERE attach_id=?")->execute([$aid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    if (is_file($path)) @unlink($path);
    jout([]);
}

/* ---------- 校驗歷史 ---------- */
case 'history': {
    $tid = (int)($_GET['tool_id'] ?? 0);
    $t = tc_get_tool($db, $tid);
    if (!$t) jerr('找不到量具');
    $st = $db->prepare("SELECT calib_id, due_date, calib_date, result, method, operator, cert_no, next_due, note,
                               batch_id, created_by_name, created_at
                        FROM qc_tool_calibration WHERE Tool_id=? ORDER BY calib_date DESC, calib_id DESC");
    $st->execute([$tid]);
    $list = $st->fetchAll(PDO::FETCH_ASSOC);
    // 該量具的附件（一份報告可對應多支量具）→ 依批次掛到對應紀錄
    $byBatch = [];
    foreach (tool_calib_attach_list($db, 0, $tid) as $a) { $byBatch[(int)$a['batch_id']][] = $a; }
    foreach ($list as &$r) {
        $r['attaches'] = $byBatch[(int)($r['batch_id'] ?? 0)] ?? [];
    }
    jout(['tool'=>['Tool_No'=>$t['Tool_No'],'category_name'=>$t['category_name']],
          'list'=>$list, 'can_delete'=>$perms['canAdmin']]);
}

/* ---------- 刪除校驗紀錄（管理員；修正誤登） ---------- */
case 'delete_calib': {
    if (!$perms['canAdmin']) jerr('無刪除權限', 403);
    $cid = (int)($_POST['calib_id'] ?? 0);
    $st = $db->prepare("SELECT Tool_id, due_date FROM qc_tool_calibration WHERE calib_id=?");
    $st->execute([$cid]);
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) jerr('找不到紀錄');
    $tid = (int)$rec['Tool_id'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM qc_tool_calibration WHERE calib_id=?")->execute([$cid]);
        // 修復到期日：若刪掉的是最近一次，回復為其所滿足的到期日；否則依剩餘紀錄重算
        $chk = $db->prepare("SELECT COUNT(*) FROM qc_tool_calibration WHERE Tool_id=?");
        $chk->execute([$tid]);
        if ((int)$chk->fetchColumn() === 0) {
            if (!empty($rec['due_date']))
                $db->prepare("UPDATE qc_tool SET calibration_due=? WHERE Tool_id=?")->execute([$rec['due_date'], $tid]);
        } else {
            tc_recompute_due($db, $tid);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    jout([]);
}

default:
    jerr('未知動作：'.$action);
}
