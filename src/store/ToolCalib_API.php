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
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';

/** 本模組可綁定 AS 文件編號的三個列印文件（ai-rules/16 第一之三節，一律走 asdoc_lib.php，白名單防呼叫端亂帶模組代碼） */
const TOOL_CALIB_ASDOC_MODULES = ['tool_calib_record' => '校驗紀錄', 'tool_calib_plan' => '校驗計畫表', 'tool_calib_dossier' => '檢驗設備履歷表(校驗歷史)',
    'tool_calib_equip_list' => '檢驗設備一覽表', 'tool_calib_equip_service' => '檢驗設備履歴表(故障維修紀錄)'];

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
// 超級管理員＝員工 id=1 本人且具管理者權限（清除測試資料等破壞性操作限他一人）
$isSuper = ($uid === 1 && $perms['isAdmin']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 取單支量具（含類別名稱） */
function tc_get_tool(PDO $db, int $tid): ?array {
    $st = $db->prepare("SELECT t.*, l.QC_Tool AS category_name
                        FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                        WHERE t.Tool_id=?");
    $st->execute([$tid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 校驗人員／單位解析（必填，前後端一致守門）
 * 內校 → 必須是具校驗人員資格的人員(qc_tool_calib_staff)；外校 → 必須是 maker_list 的廠商
 * 回傳 [顯示名稱, operator_user_id|null, vendor_id|null]
 */
function tc_resolve_operator(PDO $db, array $src): array {
    $method = trim((string)($src['method'] ?? ''));
    $sid    = (int)($src['operator_user_id'] ?? 0);
    $vid    = trim((string)($src['vendor_id'] ?? ''));
    $name   = trim((string)($src['operator'] ?? ''));
    if ($method === '內校') {
        if (!$sid) jerr('內校請選擇具校驗人員資格的人員');
        // 離職／特殊帳號一律不可登錄（人員列表鐵則的排除狀態）
        $st = $db->prepare("SELECT u.user_cname FROM qc_tool_calib_staff s JOIN `user` u ON u.id=s.user_id
                            WHERE s.user_id=? AND u.state NOT IN (" . EG_PEOPLE_EXCLUDE_STATES . ")");
        $st->execute([$sid]);
        $n = $st->fetchColumn();
        if (!$n) jerr('該人員不具校驗人員資格或已離職，請確認「校驗人員資格」設定');
        return [(string)$n, $sid, null];
    }
    if ($method === '外校') {
        if ($vid === '') jerr('外校請搜尋並選擇校驗廠商');
        $st = $db->prepare("SELECT COALESCE(NULLIF(maker_id_all,''), maker_id) FROM maker_list WHERE maker_id_no=?");
        $st->execute([$vid]);
        $n = $st->fetchColumn();
        if (!$n) jerr('找不到該廠商，請重新搜尋選擇');
        return [(string)$n, null, $vid];
    }
    if ($name === '') jerr('請填寫校驗人員／單位');
    return [$name, $sid ?: null, $vid ?: null];
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

/** 依「最近一次校驗完成日 ＋ 指定週期」重算下次應校驗月（cycle 用呼叫端此刻要套用的值，不是歷史紀錄當時的週期）。
 *  使用者 2026-08-12 明確要求：已有校驗紀錄的量具，週期後補/後改時下次應校驗月要能自動算出來，
 *  不能因為「登錄校驗當下週期還沒設」就卡在未設基準（qc_tool_calibration.next_due 是登錄當下算的，補設週期不會回頭改它）。
 *  無校驗紀錄或週期未設回 null，呼叫端應保留原值。 */
function tc_due_from_history(PDO $db, int $tid, ?int $cycle): ?string {
    if (!$cycle) return null;
    $st = $db->prepare("SELECT calib_date FROM qc_tool_calibration WHERE Tool_id=? ORDER BY calib_date DESC, calib_id DESC LIMIT 1");
    $st->execute([$tid]);
    $d = $st->fetchColumn();
    return $d ? tool_calib_next_due_month((string)$d, $cycle) : null;
}

switch ($action) {

/* ---------- 基本資訊 ---------- */
case 'meta': {
    tool_calib_purge_temp_attach($db);          // 順路清除過期暫存附件
    $cfg = tool_calib_attach_cfg($db);
    $metaStaff = tool_calib_qualified_staff($db);
    $asDocs = [];
    foreach (array_keys(TOOL_CALIB_ASDOC_MODULES) as $m) $asDocs[$m] = eg_asdoc_get($db, $m);
    $approvalCfg = tool_calib_approval_cfg($db);
    $out = ['perms'=>$perms, 'categories'=>tool_calib_categories($db), 'tabs'=>tool_calib_tabs($db),
          'cur_ym'=>date('Y-m'), 'today'=>date('Y-m-d'), 'cur_year'=>(int)date('Y'),
          'is_super'=>$isSuper,
          'staff'=>$metaStaff,
          'staff_multi_dept'=>eg_people_multi_dept($metaStaff),
          'qc_dept_set'=>count(tool_calib_qc_dept_ids($db)) > 0,
          'company_name'=>eg_company_full_name($db),
          'cur_user_name'=>$uname,
          'as_docs'=>$asDocs,
          // 圖章樣式(schema)一律回傳給所有看得到頁面的人（列印是全體使用者都會用到的動作，不限管理員）
          'list_stamp'=>tool_calib_stamp_tpl_get($db, (int)($approvalCfg['list_stamp_tpl_id'] ?? 0)),
          'footer_stamp'=>tool_calib_stamp_tpl_get($db, (int)($approvalCfg['footer_stamp_tpl_id'] ?? 0)),
          'attach'=>['types'=>$cfg['types'], 'ext'=>$cfg['ext'], 'maxmb'=>$cfg['maxmb'],
                     'dir'=>$perms['canAdmin'] ? $cfg['dir'] : '',
                     'ext_raw'=>$cfg['ext_raw'], 'types_raw'=>$cfg['types_raw']]];
    if ($perms['canAdmin']) {
        $out['approval'] = $approvalCfg;
        $out['departments'] = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    }
    jout($out);
}

/* ---------- AS 文件編號綁定（ai-rules/16 第一之三節；三個列印文件各自可綁） ---------- */
case 'asdoc_list': jout(['docs'=>eg_asdoc_list($db)]);
case 'save_asdoc': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $module = (string)($_POST['module'] ?? '');
    if (!isset(TOOL_CALIB_ASDOC_MODULES[$module])) jerr('不明的文件類型');
    $docId = (int)($_POST['doc_id'] ?? 0);
    eg_asdoc_save($db, $module, $docId, $uname);
    jout(['doc'=>eg_asdoc_get($db, $module)]);
}

/* ---------- 圖章樣式選單（供「逐列簽章」「製表/核准」設定挑選） ---------- */
case 'stamp_tpl_options': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    jout(['templates'=>tool_calib_stamp_tpl_options($db)]);
}

/* ---------- 核准設定（管理員）：是否需要主管核准內校紀錄／核准鏈／圖章樣式綁定 ---------- */
case 'save_approval_settings': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $chain = json_decode((string)($_POST['approver_chain'] ?? '["top_approver"]'), true);
    tool_calib_approval_cfg_save($db, [
        'need_approval'=>!empty($_POST['need_approval']),
        'approver_dept_id'=>(int)($_POST['approver_dept_id'] ?? 0) ?: null,
        'approver_user_id'=>(int)($_POST['approver_user_id'] ?? 0) ?: null,
        'approver_chain'=>is_array($chain) ? $chain : ['top_approver'],
        'list_stamp_tpl_id'=>(int)($_POST['list_stamp_tpl_id'] ?? 0) ?: null,
        'footer_stamp_tpl_id'=>(int)($_POST['footer_stamp_tpl_id'] ?? 0) ?: null,
    ]);
    jout(['approval'=>tool_calib_approval_cfg($db)]);
}

/* ---------- 校驗人員資格：候選（品管部門人員）與儲存 ---------- */
case 'staff_candidates': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $list = tool_calib_staff_candidates($db);
    jout(['list'=>$list, 'multi_dept'=>eg_people_multi_dept($list),
          'qc_dept_set'=>count(tool_calib_qc_dept_ids($db)) > 0]);
}
case 'save_staff': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $ids = json_decode((string)($_POST['user_ids'] ?? '[]'), true);
    if (!is_array($ids)) jerr('資料格式錯誤');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    // 只允許品管部門底下的人員（避免設定到別部門）
    $allow = array_column(tool_calib_staff_candidates($db), 'id');
    $bad = array_diff($ids, $allow);
    if ($bad) jerr('有人員不屬於品管部門，請重新整理後再設定');
    try {
        $db->beginTransaction();
        $db->exec("DELETE FROM qc_tool_calib_staff");
        if ($ids) {
            $ins = $db->prepare("INSERT INTO qc_tool_calib_staff (user_id, created_by) VALUES (?,?)");
            foreach ($ids as $id) $ins->execute([$id, $uid]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    $staff = tool_calib_qualified_staff($db);
    jout(['staff'=>$staff, 'staff_multi_dept'=>eg_people_multi_dept($staff)]);
}

/* ---------- 外校廠商模糊搜尋（maker_list：廠商ID／簡稱／全名） ---------- */
case 'vendor_search': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw === '') jout(['list'=>[]]);
    $like = '%' . $kw . '%';
    // maker_list 為 utf8mb3，中文比對一律 CONVERT 成 utf8mb4 再 LIKE（避免定序衝突）
    $st = $db->prepare("SELECT maker_id_no, maker_id, maker_id_all, m_category, status
                        FROM maker_list
                        WHERE CONVERT(maker_id_no USING utf8mb4) LIKE ?
                           OR CONVERT(maker_id USING utf8mb4) LIKE ?
                           OR CONVERT(COALESCE(maker_id_all,'') USING utf8mb4) LIKE ?
                        ORDER BY (CONVERT(maker_id_no USING utf8mb4) LIKE ?) DESC, maker_id_no
                        LIMIT 30");
    $st->execute([$like, $like, $like, $kw . '%']);
    jout(['list'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---------- 依類別批次設定校驗週期／納入統計／基準到期日（管理員） ----------
 * items = JSON [{category_id, cycle(''=不改), managed(-1不改/0/1), baseline_due(''=不改), overwrite(0只補空白/1全部覆寫)}]
 */
case 'bulk_set_cycle': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $items = json_decode((string)($_POST['items'] ?? ''), true);
    if (!is_array($items) || !$items) jerr('沒有要套用的類別');
    $applied = []; $total = 0;
    try {
        $db->beginTransaction();
        foreach ($items as $it) {
            $cat = (int)($it['category_id'] ?? 0);
            if (!$cat) continue;
            $cycleRaw = (string)($it['cycle'] ?? '');
            $managed  = array_key_exists('managed', $it) ? (int)$it['managed'] : -1;
            $baseRaw  = trim((string)($it['baseline_due'] ?? ''));
            $ovr      = (int)($it['overwrite'] ?? 0) === 1;
            if ($cycleRaw === '' && $managed === -1 && $baseRaw === '') continue;
            if ($baseRaw !== '' && !preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $baseRaw)) jerr('基準到期月格式錯誤：'.$baseRaw);
            if ($baseRaw !== '') $baseRaw = (string)tool_calib_month_start($baseRaw);

            $set = []; $p = [];
            if ($cycleRaw !== '') {
                $cyc = max(0, (int)$cycleRaw) ?: null;
                $set[] = $ovr ? "calib_cycle_months=?" : "calib_cycle_months=COALESCE(calib_cycle_months,?)";
                $p[] = $cyc;
            }
            if ($managed === 0 || $managed === 1) { $set[] = "calib_managed=?"; $p[] = $managed; }
            if ($baseRaw !== '') {
                $set[] = $ovr ? "calibration_due=?" : "calibration_due=COALESCE(calibration_due,?)";
                $p[] = $baseRaw;
            }
            if (!$set) continue;
            $p[] = $cat;
            $st = $db->prepare("UPDATE qc_tool SET ".implode(', ', $set)." WHERE QC_Tool_List_id=?");
            $st->execute($p);
            $n = $st->rowCount();
            $total += $n;
            $applied[] = ['category_id'=>$cat, 'rows'=>$n];
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('套用失敗：'.$e->getMessage(), 500); }
    jout(['applied'=>$applied, 'total'=>$total]);
}

/* ---------- 年度校驗紀錄／年度校驗計畫表 ---------- */
case 'year_records': {
    $y = (int)($_GET['year'] ?? date('Y'));
    if ($y < 2000 || $y > 2999) $y = (int)date('Y');
    jout(['year'=>$y, 'list'=>tool_calib_year_records($db, $y)]);
}
case 'year_plan': {
    $y = (int)($_GET['year'] ?? date('Y'));
    if ($y < 2000 || $y > 2999) $y = (int)date('Y');
    $rec = eg_approval_latest($db, 'tool_calib_plan', $y, 'approval');
    jout(['year'=>$y, 'list'=>tool_calib_year_plan($db, $y), 'approval'=>$rec ?: null]);
}

/* ---------- 年度校驗計畫表：送出核准／核准退回（使用者 2026-08-12 明確要求，核准人走核准鏈 ai-rules/19） ---------- */
case 'plan_submit': {
    if (!$perms['canEdit']) jerr('無校驗登錄權限', 403);
    $y = (int)($_POST['year'] ?? 0);
    if ($y < 2000 || $y > 2999) jerr('年度不正確');
    $pool = tool_calib_approver_pool($db, $uid);
    if (!$pool) jerr('核准人員解析不到合格人選，請聯絡管理員先設定核准鏈');
    $aid = eg_approval_submit($db, 'tool_calib_plan', $y, 'approval', $uid, $uname);
    tool_calib_notify($db, $y, array_column($pool, 'id'), $y . ' 年度校驗計畫表待您核准',
        $uname . ' 送出了 ' . $y . ' 年度校驗計畫表，請核准。', $uid, 'TOOL_CALIB_PLAN', 'sign');
    jout(['approval'=>eg_approval_latest($db, 'tool_calib_plan', $y, 'approval')]);
}
case 'plan_decide': {
    $y = (int)($_POST['year'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'rejected' ? 'rejected' : 'approved';
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    if ($decision === 'rejected' && !$note) jerr('請填寫退回原因');
    $rec = eg_approval_latest($db, 'tool_calib_plan', $y, 'approval');
    if (!$rec || $rec['status'] !== 'pending') jerr('目前沒有待您核准的年度計畫表');
    $pool = tool_calib_approver_pool($db, (int)$rec['submitted_by']);
    if (!$perms['canAdmin'] && !in_array($uid, array_column($pool, 'id'), true)) jerr('您不是本年度計畫表的核准人', 403);
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note);
    if (!$r['success']) jerr($r['message']);
    tool_calib_notify($db, $y, [(int)$rec['submitted_by']],
        $decision === 'rejected' ? $y . ' 年度校驗計畫表被退回' : $y . ' 年度校驗計畫表已核准',
        $uname . ($decision === 'rejected' ? (' 退回了 ' . $y . ' 年度校驗計畫表。退回原因：' . $note) : (' 已核准 ' . $y . ' 年度校驗計畫表。')),
        $uid, 'TOOL_CALIB_PLAN', 'read');
    jout(['approval'=>$r['record']]);
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

    // 採購料號（purchase_spec）尚未建表的環境不加 join，行為與加欄前相同
    $hasSpecTbl = false; $hasBrandCol = false;
    try {
        $hasSpecTbl = (bool)$db->query("SHOW TABLES LIKE 'purchase_spec'")->fetchColumn();
        if ($hasSpecTbl) $hasBrandCol = (bool)$db->query("SHOW COLUMNS FROM purchase_spec LIKE 'brand'")->fetchColumn();
    } catch (Throwable $e) {}
    $st = $db->query("SELECT t.Tool_id, t.Tool_No, t.QC_Tool_List_id, t.calibration_due,
                             t.calib_cycle_months, t.calib_managed, t.calib_method, t.purchase_spec_id,
                             t.manufacturer, t.spec_desc, t.purchase_date, t.note,
                             t.machine, t.machine_model, t.position, t.state, t.disabled_date,
                             l.QC_Tool AS category_name,
                             COALESCE(l.calib_required,1) AS cat_required,
                             COALESCE(l.calib_tab,0)      AS cat_tab"
                     . ($hasSpecTbl ? ", ps.spec_code, ps.spec_text, pi.item_name AS spec_item_name" : "")
                     . ($hasBrandCol ? ", ps.brand AS spec_brand" : "") . "
                      FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id"
                     . ($hasSpecTbl ? " LEFT JOIN purchase_spec ps ON ps.spec_id=t.purchase_spec_id
                                        LEFT JOIN purchase_item pi ON pi.item_id=ps.item_id" : "") . "
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
    $fixDue = $db->prepare("UPDATE qc_tool SET calibration_due=? WHERE Tool_id=?");
    foreach ($tools as $t) {
        if ((int)$t['cat_required'] !== 1) { $excluded++; continue; }
        $t['calib_managed'] = (int)$t['calib_managed'];
        $t['cat_tab'] = (int)$t['cat_tab'];
        $t['last'] = $last[(int)$t['Tool_id']] ?? null;
        // 順路自我修復：已有校驗紀錄且已設週期者，下次應校驗月一律用「最近校驗日＋週期」為準，
        // 修正「週期是校驗完成後才補設/改設」造成的未設基準／舊值（使用者 2026-08-12 明確要求）
        if ($t['last'] && $t['calib_cycle_months']) {
            $correctDue = tool_calib_next_due_month((string)$t['last']['calib_date'], (int)$t['calib_cycle_months']);
            if ($correctDue && $correctDue !== $t['calibration_due']) {
                $fixDue->execute([$correctDue, $t['Tool_id']]);
                $t['calibration_due'] = $correctDue;
            }
        }
        $t['status'] = tool_calib_status($t);
        $rows[] = $t;
    }

    $stat = tool_calib_kpi_compute($db, $y, $m, []);
    jout(['rows'=>$rows, 'ym'=>$ym, 'stat'=>$stat, 'perms'=>$perms,
          'see_spec_code'=>tool_calib_can_see_spec_code($db, $u, $perms),
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
    $baseDue = tool_calib_month_start(trim((string)($_POST['baseline_due'] ?? '')) ?: null);   // 到期以「月」為單位
    $manufacturer = trim((string)($_POST['manufacturer'] ?? '')) ?: null;
    $specDesc = trim((string)($_POST['spec_desc'] ?? '')) ?: null;
    $purchaseDate = trim((string)($_POST['purchase_date'] ?? '')) ?: null;
    $equipNote = trim((string)($_POST['equip_note'] ?? '')) ?: null;
    $machine = trim((string)($_POST['machine'] ?? '')) ?: null;
    $machineModel = trim((string)($_POST['machine_model'] ?? '')) ?: null;
    $position = trim((string)($_POST['position'] ?? '')) ?: null;
    $state = (int)($_POST['state'] ?? 0) === 1 ? 1 : 0;
    $disabledDate = $state ? (trim((string)($_POST['disabled_date'] ?? '')) ?: null) : null;
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO qc_tool (Tool_No, QC_Tool_List_id, Created_at, calib_cycle_months, calib_managed, calib_method, calibration_due, manufacturer, spec_desc, purchase_date, note, machine, machine_model, position, state, disabled_date)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$no, $cat, date('Y-m-d H:i:s'), $cycle, $managed, $method, $baseDue, $manufacturer, $specDesc, $purchaseDate, $equipNote, $machine, $machineModel, $position, $state, $disabledDate]);
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
    // baseline_due：允許管理員設定/修正下次應校驗「月」（尚無紀錄或需校正時）；
    // 未手動指定時，已有校驗紀錄者一律用「最近一次校驗日＋（新）週期」重算，不沿用可能因週期後補而漏算的舊值
    $setBase = array_key_exists('baseline_due', $_POST);
    $baseDue = $setBase ? tool_calib_month_start(trim((string)$_POST['baseline_due']) ?: null)
                        : (tc_due_from_history($db, $tid, $cycle) ?? tool_calib_month_start($t['calibration_due']));
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
    $manufacturer = array_key_exists('manufacturer', $_POST) ? (trim((string)$_POST['manufacturer']) ?: null) : $t['manufacturer'];
    $specDesc = array_key_exists('spec_desc', $_POST) ? (trim((string)$_POST['spec_desc']) ?: null) : $t['spec_desc'];
    $purchaseDate = array_key_exists('purchase_date', $_POST) ? (trim((string)$_POST['purchase_date']) ?: null) : $t['purchase_date'];
    $equipNote = array_key_exists('equip_note', $_POST) ? (trim((string)$_POST['equip_note']) ?: null) : $t['note'];
    $machine = array_key_exists('machine', $_POST) ? (trim((string)$_POST['machine']) ?: null) : $t['machine'];
    $machineModel = array_key_exists('machine_model', $_POST) ? (trim((string)$_POST['machine_model']) ?: null) : $t['machine_model'];
    $position = array_key_exists('position', $_POST) ? (trim((string)$_POST['position']) ?: null) : $t['position'];
    $state = array_key_exists('state', $_POST) ? ((int)$_POST['state'] === 1 ? 1 : 0) : (int)$t['state'];
    $disabledDate = $state ? (array_key_exists('disabled_date', $_POST) ? (trim((string)$_POST['disabled_date']) ?: null) : $t['disabled_date']) : null;
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE qc_tool SET Tool_No=?, QC_Tool_List_id=?, calib_cycle_months=?, calib_managed=?, calib_method=?, calibration_due=?,
                        manufacturer=?, spec_desc=?, purchase_date=?, note=?, machine=?, machine_model=?, position=?, state=?, disabled_date=? WHERE Tool_id=?")
           ->execute([$newNo, $newCat, $cycle, $managed, $method, $baseDue, $manufacturer, $specDesc, $purchaseDate, $equipNote, $machine, $machineModel, $position, $state, $disabledDate, $tid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout([]);
}

/* ============================================================
 * 檢驗設備一覽表（2026-08-17新增，AS9100文件視角；沿用 qc_tool 主檔不重複建表）
 * 保管人員歷程/履歴表(故障維修紀錄)/年度整份送簽 一律呼叫 equip_list_lib.php，equip_type 固定 'qc_tool'
 * ============================================================ */
case 'equip_list': {
    $kw = trim((string)($_GET['keyword'] ?? ''));
    $sql = "SELECT t.Tool_id, t.Tool_No, t.manufacturer, t.spec_desc, t.purchase_date, t.note,
                   l.QC_Tool AS category_name
            FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
            WHERE 1=1";
    $params = [];
    if ($kw !== '') {
        $sql .= " AND (t.Tool_No LIKE ? OR t.manufacturer LIKE ? OR l.QC_Tool LIKE ?)";
        for ($i=0;$i<3;$i++) $params[] = '%'.$kw.'%';
    }
    $sql .= " ORDER BY l.QC_Tool, t.Tool_No";
    $st = $db->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $assignMap = equip_list_resigned_map($db, 'qc_tool', array_column($rows, 'Tool_id'));
    foreach ($rows as &$r) {
        $r['Tool_id'] = (int)$r['Tool_id'];
        $r['assignee'] = $assignMap[$r['Tool_id']] ?? null;
    }
    jout(['rows' => $rows]);
}
case 'equip_asdoc_meta': {
    jout([
        'list_as_doc' => equip_list_bound_asdoc($db, 'qc_tool', 'list'),
        'service_as_doc' => equip_list_bound_asdoc($db, 'qc_tool', 'service'),
        'sign_setting' => equip_list_plan_sign_setting($db, 'qc_tool'),
        'approver_chain' => equip_list_plan_approver_chain($db, 'qc_tool'),
        'approver_methods' => EQUIP_LIST_APPROVER_METHODS,
    ]);
}
case 'equip_candidates': {
    $rows = eg_people_list($db, ['keyword' => trim((string)($_GET['keyword'] ?? ''))]);
    jout(['rows' => $rows]);
}
case 'equip_assignee_history': {
    $tid = (int)($_GET['tool_id'] ?? 0);
    if (!$tid) jerr('缺少量具');
    jout(['rows' => equip_list_assignee_history($db, 'qc_tool', $tid), 'current' => equip_list_current_assignee($db, 'qc_tool', $tid)]);
}
case 'equip_assignee_assign': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $tid = (int)($_POST['tool_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    $startDate = trim((string)($_POST['start_date'] ?? '')) ?: date('Y-m-d');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!$tid || !$userId) jerr('請選擇量具與人員');
    try { $cur = equip_list_assign_new($db, 'qc_tool', $tid, $userId, $startDate, $note ?: null, $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['current' => $cur]);
}
case 'equip_assignee_delete': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可刪除歷史紀錄', 403);
    $histId = (int)($_POST['hist_id'] ?? 0);
    if (!$histId) jerr('缺少紀錄');
    equip_list_history_delete($db, $histId);
    jout([]);
}
case 'equip_service_list': {
    $tid = (int)($_GET['tool_id'] ?? 0);
    if (!$tid) jerr('缺少量具');
    jout(['rows' => equip_service_log_list($db, 'qc_tool', $tid)]);
}
case 'equip_service_save': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $row = $_POST; $row['equip_type'] = 'qc_tool'; $row['equip_ref_id'] = $_POST['tool_id'] ?? 0;
    try { $saved = equip_service_log_save($db, $row, $uid, $uname); }
    catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['row' => $saved]);
}
case 'equip_service_delete': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可刪除履歴紀錄', 403);
    $logId = (int)($_POST['log_id'] ?? 0);
    if (!$logId) jerr('缺少紀錄');
    equip_service_log_delete($db, $logId);
    jout([]);
}
case 'equip_service_approve': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $logId = (int)($_POST['log_id'] ?? 0);
    $approvedDate = trim((string)($_POST['approved_date'] ?? '')) ?: date('Y-m-d');
    if (!$logId) jerr('缺少紀錄');
    equip_service_log_approve($db, $logId, $uid, $uname, $approvedDate, !empty($_POST['is_deputy']));
    jout([]);
}
case 'equip_plan_data': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $signSet = equip_list_plan_sign_setting($db, 'qc_tool');
    $lock = equip_list_plan_lock_get($db, 'qc_tool', $year);
    $decidePool = []; $canDecide = false;
    if ($lock && $lock['status'] === 'pending') {
        $submittedBy = (int)($lock['submitted_by'] ?? 0);
        $decidePool = equip_list_plan_approver_pool($db, 'qc_tool', $submittedBy);
        if ($uid === $submittedBy) $canDecide = !$decidePool && $perms['canAdmin'];
        else $canDecide = $perms['canAdmin'] || in_array($uid, array_column($decidePool, 'id'), true);
    }
    $bizDate = $lock['submit_date'] ?? null;
    jout(['year' => $year, 'lock' => $lock, 'sign_setting' => $signSet, 'can_decide' => $canDecide,
          'list_as_doc' => equip_list_bound_asdoc($db, 'qc_tool', 'list', $bizDate),
          'company_name' => eg_company_full_name($db)]);
}
case 'equip_plan_submit': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000) jerr('年度不正確');
    $submitDate = trim((string)($_POST['submit_date'] ?? '')) ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $submitDate)) jerr('日期格式不正確');
    $lock = equip_list_plan_lock_get($db, 'qc_tool', $year);
    if ($lock && in_array($lock['status'], ['pending','approved'], true)) jerr('此年度清單已送出，請重新整理確認狀態');
    $snapshot = json_decode((string)($_POST['snapshot'] ?? '[]'), true);
    if (!is_array($snapshot)) $snapshot = [];
    $need = equip_list_plan_sign_setting($db, 'qc_tool')['need'];
    $pool = [];
    if ($need) {
        $pool = equip_list_plan_approver_pool($db, 'qc_tool', $uid);
        if (!$pool) jerr('尚未設定合格的核准人員，請先至「組織角色綁定設定」指定「檢驗設備一覽表年度核准」');
    }
    $lock = equip_list_plan_submit($db, 'qc_tool', $year, $submitDate, $snapshot, $uid, $uname);
    if ($need && $pool) equip_list_notify_sign($db, 'qc_tool', $year, array_column($pool, 'id'), $uid, $uname);
    jout(['lock' => $lock]);
}
case 'equip_plan_decide': {
    $year = (int)($_POST['year'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $noteIn = trim((string)($_POST['note'] ?? ''));
    $approvedDate = trim((string)($_POST['approved_date'] ?? '')) ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $approvedDate)) jerr('核准日期格式不正確');
    if (!in_array($decision, ['approved','rejected'], true)) jerr('無效的決定');
    if ($decision === 'rejected' && $noteIn === '') jerr('退回必須填寫原因');
    $lock = equip_list_plan_lock_get($db, 'qc_tool', $year);
    if (!$lock || $lock['status'] !== 'pending') jerr('此年度清單目前無待核准紀錄');
    $submittedBy = (int)($lock['submitted_by'] ?? 0);
    $pool = equip_list_plan_approver_pool($db, 'qc_tool', $submittedBy);
    $isSubmitter = ($uid === $submittedBy);
    $inPool = in_array($uid, array_column($pool, 'id'), true);
    if (!$perms['canAdmin']) {
        if ($isSubmitter) { if ($pool) jerr('您是送出人，請由核准人員決行', 403); }
        elseif (!$inPool) jerr('您不是本清單的核准人員', 403);
    }
    if ($decision === 'approved') {
        $db->prepare("UPDATE equip_list_plan_lock SET status='approved', approved_by_name=?, approved_at=NOW(), approved_date=? WHERE equip_type='qc_tool' AND year=?")
           ->execute([$uname, $approvedDate, $year]);
    } else {
        $db->prepare("UPDATE equip_list_plan_lock SET status='rejected' WHERE equip_type='qc_tool' AND year=?")->execute([$year]);
    }
    equip_list_notify_sign_result($db, 'qc_tool', $year, $lock['submitted_by'] ? (int)$lock['submitted_by'] : null, $uname, $decision, $noteIn ?: null);
    jout([]);
}
case 'equip_sign_setting_save': {
    if (!$perms['canAdmin']) jerr('僅設備管理員可設定', 403);
    equip_list_plan_sign_save_setting($db, 'qc_tool', !empty($_POST['need']) ? 1 : 0);
    if (isset($_POST['chain'])) {
        $chain = json_decode((string)$_POST['chain'], true);
        if (is_array($chain)) equip_list_plan_approver_chain_save($db, 'qc_tool', $chain);
    }
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
    // 校驗人員／單位必填：內校＝有資格人員、外校＝maker_list 廠商
    [$operator, $opUid, $vendorId] = tc_resolve_operator($db, ['method'=>$method] + $_POST);
    $certNo = trim((string)($_POST['cert_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;

    $dueDate = tool_calib_month_start($t['calibration_due']);   // 本次滿足的到期月
    $cycle = $t['calib_cycle_months'] !== null ? (int)$t['calib_cycle_months'] : 0;
    $nextDue = tool_calib_next_due_month($calibDate, $cycle);   // 下次應校驗「月」

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO qc_tool_calibration
            (Tool_id, due_date, calib_date, result, method, operator, operator_user_id, vendor_id, cert_no, next_due, note, created_by, created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$tid, $dueDate, $calibDate, $result, $method, $operator, $opUid, $vendorId, $certNo, $nextDue, $note, $uid, $uname]);
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
    [$operator, $opUid, $vendorId] = tc_resolve_operator($db, ['method'=>$method] + $_POST);
    $certNo = trim((string)($_POST['cert_no'] ?? '')) ?: null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    // 若改到期日基準（管理員）也允許
    $dueDate = array_key_exists('due_date', $_POST)
        ? tool_calib_month_start(trim((string)$_POST['due_date']) ?: null)
        : tool_calib_month_start($rec['due_date']);
    $cycle = $rec['calib_cycle_months'] !== null ? (int)$rec['calib_cycle_months'] : 0;
    $nextDue = tool_calib_next_due_month($calibDate, $cycle);
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE qc_tool_calibration SET due_date=?, calib_date=?, result=?, method=?, operator=?,
                             operator_user_id=?, vendor_id=?, cert_no=?, next_due=?, note=? WHERE calib_id=?")
           ->execute([$dueDate, $calibDate, $result, $method, $operator, $opUid, $vendorId, $certNo, $nextDue, $note, $cid]);
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
    // 校驗人員／單位必填（內校＝有資格人員；外校＝maker_list 廠商）
    [$operator, $opUid, $vendorId] = tc_resolve_operator($db, ['method'=>$method] + $_POST);
    $certNo   = trim((string)($_POST['cert_no'] ?? '')) ?: null;
    $note     = trim((string)($_POST['note'] ?? '')) ?: null;
    $tools    = json_decode((string)($_POST['tools'] ?? ''), true);
    if (!is_array($tools) || !$tools) jerr('請至少選擇一支量具');
    $attach   = json_decode((string)($_POST['attach'] ?? '[]'), true);
    if (!is_array($attach)) $attach = [];

    // 內校覆驗者（使用者 2026-08-12 明確要求）：必須是具校驗人員資格者，且不可與校驗人員(operator)同一人
    $reviewerUid = 0; $reviewerName = null;
    if ($method === '內校') {
        $reviewerUid = (int)($_POST['reviewer_user_id'] ?? 0);
        if (!$reviewerUid) jerr('內校請選擇覆驗者');
        if ($opUid && $reviewerUid === (int)$opUid) jerr('覆驗者不可與校驗人員為同一人');
        $st = $db->prepare("SELECT u.user_cname FROM qc_tool_calib_staff s JOIN `user` u ON u.id=s.user_id
                            WHERE s.user_id=? AND u.state NOT IN (" . EG_PEOPLE_EXCLUDE_STATES . ")");
        $st->execute([$reviewerUid]);
        $rn = $st->fetchColumn();
        if (!$rn) jerr('覆驗者不具校驗人員資格或已離職，請重新選擇');
        $reviewerName = (string)$rn;
    }
    $approvalCfg = tool_calib_approval_cfg($db);
    $needApproval = $method === '內校' && !empty($approvalCfg['need_approval']);

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO qc_tool_calib_batch (calib_date, method, operator, operator_user_id, vendor_id, cert_no, note, tool_count,
                      reviewer_user_id, reviewer_name, approval_status, created_by, created_by_name)
                      VALUES (?,?,?,?,?,?,?,0,?,?,?,?,?)")
           ->execute([$calibDate, $method, $operator, $opUid, $vendorId, $certNo, $note,
                      $reviewerUid ?: null, $reviewerName, $needApproval ? 'pending' : 'none', $uid, $uname]);
        $batchId = (int)$db->lastInsertId();
        if ($needApproval) eg_approval_submit($db, 'tool_calib_batch', $batchId, 'approval', $uid, $uname);

        $insRec = $db->prepare("INSERT INTO qc_tool_calibration
            (Tool_id, due_date, calib_date, result, method, operator, operator_user_id, vendor_id, cert_no, next_due, note, batch_id, created_by, created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
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
            $nextDue = tool_calib_next_due_month($calibDate, $cycle);
            $insRec->execute([$tid, tool_calib_month_start($t['calibration_due']), $calibDate, $result, $mth, $operator, $opUid, $vendorId,
                              $certNo, $nextDue, $note, $batchId, $uid, $uname]);
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

    if ($needApproval) {
        $pool = tool_calib_approver_pool($db, $uid);
        if ($pool) {
            tool_calib_notify($db, $batchId, array_column($pool, 'id'), '有一筆內校紀錄待您核准',
                $uname . ' 登錄的一筆內校校驗紀錄（憑證編號：' . ($certNo ?: '無') . '）待您核准。', $uid, 'TOOL_CALIB_APPROVAL', 'sign');
        }
    }
    jout(['batch_id'=>$batchId, 'done'=>$done, 'skipped'=>$skipped, 'approval_status'=>$needApproval ? 'pending' : 'none']);
}

/* ---------- 內校核准／退回（管理員或核准鏈解析出的合格核准人） ---------- */
case 'batch_decide': {
    $bid = (int)($_POST['batch_id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'rejected' ? 'rejected' : 'approved';
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    if ($decision === 'rejected' && !$note) jerr('請填寫退回原因');
    $b = $db->prepare("SELECT * FROM qc_tool_calib_batch WHERE batch_id=?");
    $b->execute([$bid]);
    $batch = $b->fetch(PDO::FETCH_ASSOC);
    if (!$batch) jerr('找不到此批次');
    $rec = eg_approval_latest($db, 'tool_calib_batch', $bid, 'approval');
    if (!$rec || $rec['status'] !== 'pending') jerr('目前沒有待您核准的紀錄');
    $pool = tool_calib_approver_pool($db, (int)$batch['created_by']);
    if (!$perms['canAdmin'] && !in_array($uid, array_column($pool, 'id'), true)) jerr('您不是本筆紀錄的核准人', 403);
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note);
    if (!$r['success']) jerr($r['message']);
    $newStatus = $decision === 'rejected' ? 'rejected' : 'approved';
    $db->prepare("UPDATE qc_tool_calib_batch SET approval_status=? WHERE batch_id=?")->execute([$newStatus, $bid]);
    tool_calib_notify($db, $bid, [(int)$batch['created_by']],
        $decision === 'rejected' ? '一筆內校紀錄被退回' : '一筆內校紀錄已核准',
        $uname . ($decision === 'rejected' ? (' 退回了您登錄的內校紀錄。退回原因：' . $note) : ' 已核准您登錄的內校紀錄。'),
        $uid, 'TOOL_CALIB_APPROVAL', 'read');
    jout(['status'=>$newStatus]);
}

/* ---------- 一次核准所有待核准紀錄（僅核准，不可改日期；使用者 2026-08-12 明確要求） ----------
 * 只處理目前登入者有權核准的那些（canAdmin 或在核准鏈解析出的池子裡），不是 pending_approvals 清單以外的。
 */
case 'batch_decide_bulk': {
    $rows = $db->query("SELECT batch_id, created_by FROM qc_tool_calib_batch WHERE approval_status='pending'")->fetchAll(PDO::FETCH_ASSOC);
    $done = 0; $skipped = 0;
    foreach ($rows as $r) {
        $bid = (int)$r['batch_id'];
        $pool = tool_calib_approver_pool($db, (int)$r['created_by']);
        if (!$perms['canAdmin'] && !in_array($uid, array_column($pool, 'id'), true)) continue;
        $rec = eg_approval_latest($db, 'tool_calib_batch', $bid, 'approval');
        if (!$rec || $rec['status'] !== 'pending') continue;
        $res = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, 'approved', '（一次核准全部）');
        if (!$res['success']) { $skipped++; continue; }
        $db->prepare("UPDATE qc_tool_calib_batch SET approval_status='approved' WHERE batch_id=?")->execute([$bid]);
        tool_calib_notify($db, $bid, [(int)$r['created_by']], '一筆內校紀錄已核准',
            $uname . ' 已核准您登錄的內校紀錄。', $uid, 'TOOL_CALIB_APPROVAL', 'read');
        $done++;
    }
    jout(['done'=>$done, 'skipped'=>$skipped]);
}

/* ---------- 超級管理員：全部補登核准（補資料用，可指定簽核日期；僅員工 id=1） ----------
 * 不受核准鏈池子限制，一次把目前所有待核准紀錄全部核准；需輸入超級管理員密碼二次確認。
 */
case 'super_approve_all': {
    if (!$isSuper) jerr('僅超級管理員可使用此功能', 403);
    $date = trim((string)($_POST['decided_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jerr('請選擇簽核日期');
    $vp = tool_calib_verify_superadmin_password($db, (string)($_POST['password'] ?? ''));
    if (!$vp['ok']) jerr($vp['msg'], 403);
    $rows = $db->query("SELECT batch_id, created_by FROM qc_tool_calib_batch WHERE approval_status='pending'")->fetchAll(PDO::FETCH_ASSOC);
    $done = 0;
    foreach ($rows as $r) {
        $bid = (int)$r['batch_id'];
        $rec = eg_approval_latest($db, 'tool_calib_batch', $bid, 'approval');
        if (!$rec || $rec['status'] !== 'pending') continue;
        $res = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, 'approved', '（超級管理員補登核准）');
        if (!$res['success']) continue;
        $db->prepare("UPDATE approval_record SET decided_at=? WHERE id=?")->execute([$date . ' 12:00:00', (int)$rec['id']]);
        $db->prepare("UPDATE qc_tool_calib_batch SET approval_status='approved' WHERE batch_id=?")->execute([$bid]);
        tool_calib_notify($db, $bid, [(int)$r['created_by']], '一筆內校紀錄已核准（補登）',
            '超級管理員已補登核准您登錄的內校紀錄（簽核日期：' . $date . '）。', $uid, 'TOOL_CALIB_APPROVAL', 'read');
        $done++;
    }
    tool_calib_log_change($db, '超級管理員批次補登核准內校紀錄',
        "執行者：{$uname}（id={$uid}）\n簽核日期：{$date}\n核准筆數：{$done}", $uname);
    jout(['done'=>$done]);
}

/* ---------- 待我核准的內校紀錄清單 ---------- */
case 'pending_approvals': {
    $rows = $db->query("SELECT b.batch_id, b.calib_date, b.operator, b.reviewer_name, b.cert_no, b.tool_count,
                               b.created_by, b.created_by_name,
                               GROUP_CONCAT(t.Tool_No ORDER BY t.Tool_No SEPARATOR '、') AS tool_nos
                        FROM qc_tool_calib_batch b
                        JOIN qc_tool_calibration c ON c.batch_id=b.batch_id
                        JOIN qc_tool t ON t.Tool_id=c.Tool_id
                        WHERE b.approval_status='pending'
                        GROUP BY b.batch_id
                        ORDER BY b.calib_date DESC, b.batch_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$perms['canAdmin']) {
        $rows = array_values(array_filter($rows, function ($r) use ($db, $uid) {
            $pool = tool_calib_approver_pool($db, (int)$r['created_by']);
            return in_array($uid, array_column($pool, 'id'), true);
        }));
    }
    jout(['list'=>$rows]);
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
    $b['approval'] = eg_approval_latest($db, 'tool_calib_batch', $bid, 'approval');
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
    $spec = null;
    if (!empty($t['purchase_spec_id'])) {
        try {
            $sst = $db->prepare("SELECT ps.spec_code, ps.spec_text, ps.brand, pi.item_name
                                 FROM purchase_spec ps JOIN purchase_item pi ON pi.item_id=ps.item_id
                                 WHERE ps.spec_id=?");
            $sst->execute([$t['purchase_spec_id']]);
            $spec = $sst->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }
    $st = $db->prepare("SELECT c.calib_id, c.due_date, c.calib_date, c.result, c.method, c.operator, c.operator_user_id, c.vendor_id,
                               c.cert_no, c.next_due, c.note, c.batch_id, c.created_by_name, c.created_at,
                               b.reviewer_name, b.approval_status
                        FROM qc_tool_calibration c LEFT JOIN qc_tool_calib_batch b ON b.batch_id=c.batch_id
                        WHERE c.Tool_id=? ORDER BY c.calib_date DESC, c.calib_id DESC");
    $st->execute([$tid]);
    $list = $st->fetchAll(PDO::FETCH_ASSOC);
    // 該量具的附件（一份報告可對應多支量具）→ 依批次掛到對應紀錄
    $byBatch = [];
    foreach (tool_calib_attach_list($db, 0, $tid) as $a) { $byBatch[(int)$a['batch_id']][] = $a; }
    $approvals = tool_calib_batch_approvals($db, array_column($list, 'batch_id'));
    foreach ($list as &$r) {
        $r['attaches'] = $byBatch[(int)($r['batch_id'] ?? 0)] ?? [];
        $ap = $approvals[(int)($r['batch_id'] ?? 0)] ?? null;
        $r['approver_name'] = ($ap && $ap['status'] === 'approved') ? $ap['approver_name'] : null;
        $r['approved_at']   = ($ap && $ap['status'] === 'approved') ? $ap['decided_at'] : null;
    }
    unset($r);
    jout(['tool'=>['Tool_No'=>$t['Tool_No'], 'category_name'=>$t['category_name'],
                   'calib_cycle_months'=>$t['calib_cycle_months'], 'calib_method'=>$t['calib_method'],
                   'calib_managed'=>(int)$t['calib_managed'], 'calibration_due'=>$t['calibration_due'],
                   'spec'=>$spec],
          'list'=>$list, 'can_delete'=>$perms['canAdmin']]);
}

/* ---------- 使用紀錄（此量具反查用於哪些檢驗單；資料來自 qc_measurement.tool_id，見 CLAUDE.md 量具規格說明） ---------- */
case 'usage_history': {
    $tid = (int)($_GET['tool_id'] ?? 0);
    $t = tc_get_tool($db, $tid);
    if (!$t) jerr('找不到量具');
    $st = $db->prepare("SELECT f.qc_form_id, f.check_date, f.created_at, f.process_name, f.check_result, f.created_by,
                               ds.D_Setting_Id AS part_no,
                               COUNT(DISTINCT m.item_id) AS item_count
                        FROM qc_measurement m
                        JOIN qc_check_form f ON f.qc_form_id = m.qc_form_id
                        LEFT JOIN d_setting ds ON ds.d_id = f.d_id
                        WHERE m.tool_id = ?
                        GROUP BY f.qc_form_id, f.check_date, f.created_at, f.process_name, f.check_result, f.created_by, ds.D_Setting_Id
                        ORDER BY COALESCE(f.check_date, f.created_at) DESC, f.qc_form_id DESC
                        LIMIT 200");
    $st->execute([$tid]);
    $list = $st->fetchAll(PDO::FETCH_ASSOC);
    $names = [];
    if ($list) {
        $ids = array_unique(array_column($list, 'created_by'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $ns = $db->prepare("SELECT id, COALESCE(NULLIF(user_cname,''), user_uname) AS nm FROM user WHERE id IN ($in)");
        $ns->execute(array_values($ids));
        foreach ($ns->fetchAll(PDO::FETCH_ASSOC) as $n) $names[$n['id']] = $n['nm'];
    }
    foreach ($list as &$r) {
        $r['check_date'] = $r['check_date'] ?: substr((string)$r['created_at'], 0, 10);
        $r['creator_name'] = $names[$r['created_by']] ?? $r['created_by'];
        $r['item_count'] = (int)$r['item_count'];
        unset($r['created_at'], $r['created_by']);
    }
    unset($r);
    jout(['tool' => ['Tool_No' => $t['Tool_No'], 'category_name' => $t['category_name']], 'list' => $list]);
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

/* ---------- 量具料號對應：解析草稿（管理員；只讀不寫，可重複產生） ----------
 * 使用者 2026-07-30 定案：量具規格掛到「採購料號」(purchase_item→purchase_spec)，不另建量具規格主檔。
 * 舊資料規格塞在 Tool_No 字串裡 → 先解析成草稿讓使用者確認/修改，確認後才寫入（見 spec_apply）。
 */
case 'spec_draft': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    // 品牌清單由採購維護（purchase_brand），本頁只能選、不能建 → 只讀，不呼叫任何寫入
    $brands = [];
    try {
        require_once $document_root . '/EGsystem/src/common/purchase_lib.php';
        $brands = purchase_brand_list($db);
    } catch (Throwable $e) { /* 採購模組尚未初始化 → 品牌欄仍可自由輸入 */ }
    jout(['list'=>tool_calib_spec_draft($db),
          'purchase_categories'=>tool_calib_purchase_categories($db),
          'default_category_id'=>tool_calib_default_purchase_category($db),
          'units'=>tool_calib_units($db),
          'default_unit_id'=>tool_calib_default_unit_id($db),
          'brands'=>array_column($brands, 'brand_name')]);
}

/* ---------- 量具料號對應：確認後寫入（管理員；單一 transaction） ----------
 * items = JSON [{tool_id, item_name, spec, type, model, brand}]
 * auto=1 時忽略 items，直接用後端解析出來的草稿整批建立（scope: unbound=只做未對應者 / all=全部）
 * unit_id = 建立時帶入的單位，**預設 PCS**（使用者 2026-07-30 指定）
 * 同名品項沿用既有、同 item+同 spec_text 沿用既有規格 → 重跑不會重複建立。
 */
case 'spec_apply': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    require_once $document_root . '/EGsystem/src/common/purchase_lib.php';   // 料號編碼規則的唯一來源
    $catId = (int)($_POST['category_id'] ?? 0);
    if (!$catId) jerr('請選擇採購品類別（品項要掛在哪一類）');
    $chk = $db->prepare("SELECT COUNT(*) FROM stock_item_categories WHERE category_id=?");
    $chk->execute([$catId]);
    if (!(int)$chk->fetchColumn()) jerr('找不到該採購品類別，請重新整理後再試');

    // 單位：沒帶或帶 0 一律回退成預設 PCS；有帶就先確認存在
    $unitId = (int)($_POST['unit_id'] ?? 0);
    if (!$unitId) $unitId = tool_calib_default_unit_id($db);
    if ($unitId) {
        $chk = $db->prepare("SELECT COUNT(*) FROM stock_units WHERE unit_id=?");
        $chk->execute([$unitId]);
        if (!(int)$chk->fetchColumn()) jerr('找不到該單位，請重新整理後再試');
    }
    $unitId = $unitId ?: null;

    if ((int)($_POST['auto'] ?? 0) === 1) {
        // 批次自動建立：直接用後端解析的草稿，不需逐列確認（廠牌一律留空，日後於採購品主檔補）
        $scope = (($_POST['scope'] ?? 'unbound') === 'all') ? 'all' : 'unbound';
        $items = [];
        foreach (tool_calib_spec_draft($db) as $d) {
            if ($scope === 'unbound' && (int)$d['bound'] === 1) continue;
            $items[] = ['tool_id'=>$d['Tool_id'], 'item_name'=>$d['item_name'], 'spec'=>$d['spec'],
                        'type'=>$d['type'], 'brand'=>$d['brand'], 'model'=>$d['model']];
        }
        if (!$items) jerr($scope === 'unbound' ? '沒有尚未對應料號的量具（全部都已對應）' : '目前沒有量具資料');
    } else {
        $items = json_decode((string)($_POST['items'] ?? ''), true);
        if (!is_array($items) || !$items) jerr('沒有要建立的資料（請先產生草稿並勾選）');
    }

    // 品牌是 purchase_spec 的獨立欄位（不併進 spec_text）→ 先確保欄位/表存在
    purchase_ensure_brand_vendor_schema($db);

    $newItems = 0; $newSpecs = 0; $bound = 0; $skipped = [];
    try {
        $db->beginTransaction();
        $findItem = $db->prepare("SELECT item_id FROM purchase_item WHERE category_id=? AND item_name=? ORDER BY item_id LIMIT 1");
        $insItem  = $db->prepare("INSERT INTO purchase_item (item_code, category_id, item_name, default_unit_id, note, Created_By)
                                  VALUES (?,?,?,?,'由量測儀器校驗管理－量具料號對應建立',?)");
        // 同品項＋同規格文字＋同品牌才算同一個料號（不同品牌＝不同料號）
        $findSpec = $db->prepare("SELECT spec_id FROM purchase_spec
                                  WHERE item_id=? AND spec_text=? AND COALESCE(brand,'')=? ORDER BY spec_id LIMIT 1");
        $insSpec  = $db->prepare("INSERT INTO purchase_spec (item_id, spec_code, spec_text, brand, unit_id, Created_By) VALUES (?,?,?,?,?,?)");
        // 沿用既有料號時只補「目前空白」的單位，已設定過的不覆寫（不破壞既有資料）
        $fixItemUnit = $db->prepare("UPDATE purchase_item SET default_unit_id=? WHERE item_id=? AND default_unit_id IS NULL");
        $fixSpecUnit = $db->prepare("UPDATE purchase_spec SET unit_id=? WHERE spec_id=? AND unit_id IS NULL");
        $updTool  = $db->prepare("UPDATE qc_tool SET purchase_spec_id=? WHERE Tool_id=?");
        $getTool  = $db->prepare("SELECT Tool_No FROM qc_tool WHERE Tool_id=? LIMIT 1");

        foreach ($items as $it) {
            $tid = (int)($it['tool_id'] ?? 0);
            if (!$tid) continue;
            $getTool->execute([$tid]);
            $toolNo = $getTool->fetchColumn();
            if ($toolNo === false) { $skipped[] = $tid; continue; }          // 量具已被刪除
            $itemName = mb_substr(trim((string)($it['item_name'] ?? '')), 0, 100);
            // 交易中不可用 jerr()（會 exit 而不 rollback）→ 丟例外交給下面的 catch 統一回復
            if ($itemName === '') throw new RuntimeException('量具「'.$toolNo.'」的品項名稱不可空白');
            $specText = tool_calib_compose_spec_text([
                'spec' => (string)($it['spec'] ?? ''),
                'model' => (string)($it['model'] ?? ''), 'type' => (string)($it['type'] ?? ''),
            ]);
            $brand = mb_substr(trim((string)($it['brand'] ?? '')), 0, 60);

            $findItem->execute([$catId, $itemName]);
            $itemId = (int)$findItem->fetchColumn();
            if (!$itemId) {
                $insItem->execute([purchase_next_item_code($db, $catId), $catId, $itemName, $unitId, $uid]);
                $itemId = (int)$db->lastInsertId();
                $newItems++;
            } elseif ($unitId) {
                $fixItemUnit->execute([$unitId, $itemId]);      // 既有品項單位空白才補
            }
            $findSpec->execute([$itemId, $specText, $brand]);
            $specId = (int)$findSpec->fetchColumn();
            if (!$specId) {
                $insSpec->execute([$itemId, purchase_next_spec_code($db, $itemId), $specText, $brand ?: null, $unitId, $uid]);
                $specId = (int)$db->lastInsertId();
                $newSpecs++;
            } elseif ($unitId) {
                $fixSpecUnit->execute([$unitId, $specId]);      // 既有規格單位空白才補
            }
            $updTool->execute([$specId, $tid]);
            $bound++;
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('建立料號失敗：'.$e->getMessage(), 500); }
    jout(['new_items'=>$newItems, 'new_specs'=>$newSpecs, 'bound'=>$bound, 'skipped'=>$skipped,
          'unit_id'=>$unitId, 'total'=>count($items)]);
}

/* ---------- 清除測試資料（僅超級管理員 id=1；只清本模組交易資料，設定類一律保留） ---------- */
case 'clean_preview': {
    if (!$isSuper) jerr('僅超級管理員可使用此功能', 403);
    jout(['counts'=>tool_calib_clean_counts($db)]);
}
case 'clean_test_data': {
    if (!$isSuper) jerr('僅超級管理員可使用此功能', 403);
    if (trim((string)($_POST['confirm'] ?? '')) !== 'Y') jerr('請於確認欄輸入大寫 Y');
    $vp = tool_calib_verify_superadmin_password($db, (string)($_POST['password'] ?? ''));
    if (!$vp['ok']) jerr($vp['msg'], 403);

    $before = tool_calib_clean_counts($db);
    tool_calib_log_change($db, '清除量測儀器校驗測試資料（執行前）',
        "執行者：{$uname}（id={$uid}）\n清除前筆數："
        . "校驗紀錄 {$before['calibration']}／批次 {$before['batch']}／附件 {$before['attach']}"
        . "／附件對應 {$before['attach_map']}／待還原量具欄位 {$before['tool_reset']} 支\n"
        . "保留不動：類別旗標與自訂分頁、附件設定、校驗人員資格、量具主檔本身", $uname);

    // 實體檔路徑先算好（DB 列刪掉後就查不到檔名了）；一律走 tool_calib_attach_file()（鐵律5）
    $files = [];
    try {
        foreach ($db->query("SELECT file_name FROM qc_tool_calib_attach")->fetchAll(PDO::FETCH_COLUMN) as $fn) {
            $files[] = tool_calib_attach_file($db, (string)$fn);
        }
    } catch (Throwable $e) { /* 表不存在 */ }

    $deleted = [];
    try {
        $db->beginTransaction();
        $deleted['attach_map']  = (int)$db->exec("DELETE FROM qc_tool_calib_attach_map");
        $deleted['attach']      = (int)$db->exec("DELETE FROM qc_tool_calib_attach");
        $deleted['calibration'] = (int)$db->exec("DELETE FROM qc_tool_calibration");
        $deleted['batch']       = (int)$db->exec("DELETE FROM qc_tool_calib_batch");
        // 量具主檔只還原本模組的四個欄位，Tool_No／類別／purchase_spec_id 一律不動
        $deleted['tool_reset']  = (int)$db->exec(
            "UPDATE qc_tool SET calib_cycle_months=NULL, calibration_due=NULL, calib_managed=0, calib_method=NULL
             WHERE calib_cycle_months IS NOT NULL OR calibration_due IS NOT NULL
                OR calib_managed=1 OR calib_method IS NOT NULL");
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('清除失敗（已全部回復）：'.$e->getMessage(), 500); }

    $fileDeleted = 0;
    foreach ($files as $f) { if (is_file($f) && @unlink($f)) $fileDeleted++; }

    tool_calib_log_change($db, '清除量測儀器校驗測試資料（執行後）',
        "執行者：{$uname}（id={$uid}）\n實際刪除："
        . "校驗紀錄 {$deleted['calibration']}／批次 {$deleted['batch']}／附件 {$deleted['attach']}"
        . "（實體檔 {$fileDeleted}）／附件對應 {$deleted['attach_map']}／還原量具欄位 {$deleted['tool_reset']} 支", $uname);

    jout(['deleted'=>$deleted, 'files_deleted'=>$fileDeleted, 'counts'=>tool_calib_clean_counts($db)]);
}

default:
    jerr('未知動作：'.$action);
}
