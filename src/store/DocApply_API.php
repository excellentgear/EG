<?php
/**
 * 文件制、修申請單（2-DC-01-01）API
 * 權限：doc_apply_lib.php da_perms()（roles module='doc_apply'）。
 *       檢閱=唯讀、申請=開自己的單、管理員=全部（勾選會簽採用、自動簽核、批次列印/刪除、設定、AS綁定）。
 *       會簽權不看角色：被指派為該單某一列會簽人者即可會簽（含代理人）。
 * 送出必填檢查：後端一律再跑一次 da_validate()（前端已擋，不可只做半套，鐵律8）。
 * 時間戳：一律取 DB 時間（PHP date() 是 UTC、MySQL NOW() 是本地）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/doc_apply_lib.php';
include_once $document_root . '/EGsystem/src/common/confirm_password_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []){ http_response_code($code); echo json_encode(array_merge(['ok'=>false,'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    da_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = da_current_user($db);
if (!$u) jerr('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$P     = da_perms($db, $u);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 會簽人也要看得到單（即使沒有任何角色） */
function da_is_cosigner(PDO $db, int $applyId, int $uid): bool
{
    try {
        $st = $db->prepare("SELECT 1 FROM doc_apply_cosign WHERE apply_id=? AND signer_id=? LIMIT 1");
        $st->execute([$applyId, $uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function da_can_see(PDO $db, array $r, array $P, int $uid): bool
{
    if ($P['canView']) return true;
    return (int)($r['applicant_id'] ?? 0) === $uid || (int)($r['created_by'] ?? 0) === $uid
        || da_is_cosigner($db, (int)$r['apply_id'], $uid);
}

function da_can_edit_row(array $r, array $P, int $uid): bool
{
    if ($P['canAdmin']) return true;
    if ($r['status'] !== 'draft') return false;
    return $P['canEdit'] && ((int)($r['applicant_id'] ?? 0) === $uid || (int)($r['created_by'] ?? 0) === $uid);
}

/** POST 進來的明細陣列（前端以 JSON 字串送，避免欄位數爆掉） */
function da_json_arr(string $key): array
{
    $raw = $_POST[$key] ?? '';
    if ($raw === '') return [];
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

if (!$P['canView'] && !$P['canEdit'] && !in_array($action, ['detail', 'cosign_decide', 'meta'], true)) {
    jerr('無「文件制、修申請單」使用權限（請至權限設定頁指派角色）', 403);
}

try {
switch ($action) {

/* ══════════════════ meta：設定 / AS 文件 / 部門 / 人員 / 公司名 ══════════════════ */
case 'meta': {
    $set = da_settings($db);
    $doc = eg_asdoc_get($db, DA_ASDOC_MODULE);
    $depts = [];
    try { $depts = $db->query("SELECT id, name, level FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {}
    $people = [];
    try { $people = eg_people_list($db, []); } catch (Throwable $e) {}
    // 申請人下拉：逐「職務」列出（含兼任），依 部門 → 職稱 sort_order 排序（人員列表鐵則第 5 條）
    $posts = [];
    try { $posts = eg_people_posts($db, []); } catch (Throwable $e) {}
    $me = da_user_identity($db, $uid);
    // 圖章模板清單（會簽簽名欄／四格簽章／回收記錄各自可選）
    $tpls = [];
    try { $tpls = $db->query("SELECT id, tpl_name FROM stamp_template WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {}
    jout([
        'perms'    => $P,
        'settings' => $set,
        'sign_sources' => DA_SIGN_SOURCES,
        'doc_status'   => DA_DOC_STATUS,
        'doc_types'    => array_keys(DA_TYPE_LEVEL),
        'type_level'   => DA_TYPE_LEVEL,
        'asdoc'    => $doc,
        'asdoc_no' => eg_asdoc_no($doc),
        'departments'   => $depts,
        'cosign_depts'  => da_cosign_dept_options($db),
        'people'   => $people,
        'people_posts' => $posts,
        'change_presets' => da_change_presets($db),
        'me'       => ['id'=>$uid, 'name'=>$uname] + $me,
        'stamp_tpls' => $tpls,
        'stamp_main'   => da_stamp_template($db, 'da_stamp_tpl_id'),
        'stamp_cosign' => da_stamp_template($db, 'da_cosign_stamp_tpl_id'),
        'stamp_dist'   => da_stamp_template($db, 'da_dist_stamp_tpl_id'),
        'company'  => eg_company_full_name($db),
        'today'    => da_db_now($db)['d'],
    ]);
}

/* ══════════════════ AS 文件清單（綁定挑選器／文件狀況非制訂時選文件） ══════════════════ */
case 'asdoc_list':
    jout(['docs' => eg_asdoc_list($db)]);

case 'asdoc_info': {
    $info = da_asdoc_info($db, (int)($_GET['doc_id'] ?? 0));
    if (!$info) jerr('查無此 AS 文件');
    $info['cosign_default'] = da_cosign_default($db, (int)$info['id'], $info['department_id'] ? (int)$info['department_id'] : null);
    jout(['doc' => $info]);
}

case 'save_asdoc_bind': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    eg_asdoc_save($db, DA_ASDOC_MODULE, (int)($_POST['doc_id'] ?? 0), $uname);
    jout([]);
}

/* ══════════════════ 文件編碼自動產生（比照 as_document_management） ══════════════════ */
case 'suggest_doc_no': {
    $r = da_suggest_doc_no($db, (string)($_GET['level'] ?? ''), (int)($_GET['department_id'] ?? 0),
                           (int)($_GET['parent_doc_id'] ?? 0), (string)($_GET['code'] ?? ''));
    if ($r['status'] === 'error') jerr($r['message']);
    jout($r);
}

/** 母文件候選（表單制訂時要挑掛在哪一份程序書／標準書底下） */
case 'parent_docs': {
    $rows = [];
    try {
        $rows = $db->query("SELECT id, doc_no, doc_name, department_id FROM as_document
                            WHERE is_deleted=0 AND COALESCE(doc_type,'')<>'表單' AND COALESCE(doc_level,'')<>'四階'
                            ORDER BY doc_no")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    jout(['parents' => $rows]);
}

/* ══════════════════ 清單 ══════════════════ */
case 'list': {
    $kw     = trim((string)($_GET['kw'] ?? ''));
    $st     = trim((string)($_GET['status'] ?? ''));
    $from   = trim((string)($_GET['from'] ?? ''));
    $to     = trim((string)($_GET['to'] ?? ''));
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $size   = min(200, max(5, (int)($_GET['size'] ?? 10)));

    $w = ['COALESCE(a.is_deleted,0)=0']; $args = [];
    if (!$P['canView']) {
        // 只看得到自己的單與指派給自己會簽的單
        $w[] = '(a.applicant_id=? OR a.created_by=? OR EXISTS (SELECT 1 FROM doc_apply_cosign c WHERE c.apply_id=a.apply_id AND c.signer_id=?))';
        array_push($args, $uid, $uid, $uid);
    }
    if ($st !== '')   { $w[] = 'a.status=?';       $args[] = $st; }
    if ($from !== '') { $w[] = 'a.apply_date>=?';  $args[] = $from; }
    if ($to !== '')   { $w[] = 'a.apply_date<=?';  $args[] = $to; }
    if ($kw !== '') {
        // 全表搜尋：LIKE 掃過畫面看得到的欄位（禁 FULLTEXT，料號/編號含「-」會比對不到）
        $cols = ['a.apply_no','a.doc_no','a.doc_name','a.doc_type','a.doc_status','a.version',
                 'a.dept_name','a.applicant_name','a.auto_note','a.decide_note'];
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $cols)) . ')';
            foreach ($cols as $c) $args[] = '%' . $k . '%';
        }
    }
    $where = implode(' AND ', $w);

    $cnt = $db->prepare("SELECT COUNT(*) FROM doc_apply a WHERE $where");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();

    $sql = "SELECT a.*,
                   (SELECT COUNT(*) FROM doc_apply_cosign c WHERE c.apply_id=a.apply_id AND c.is_checked=1) AS cos_total,
                   (SELECT COUNT(*) FROM doc_apply_cosign c WHERE c.apply_id=a.apply_id AND c.is_checked=1 AND c.agree IS NOT NULL) AS cos_done,
                   (SELECT COUNT(*) FROM doc_apply_cosign c WHERE c.apply_id=a.apply_id AND c.is_checked=1 AND c.agree=0) AS cos_bad,
                   (SELECT MAX(p.printed_at) FROM doc_apply_print_log p WHERE p.apply_id=a.apply_id) AS last_print_at,
                   (SELECT COUNT(*) FROM doc_apply_print_log p WHERE p.apply_id=a.apply_id) AS print_count
            FROM doc_apply a WHERE $where
            ORDER BY a.apply_date DESC, a.apply_id DESC
            LIMIT " . (($page - 1) * $size) . ", $size";
    $q = $db->prepare($sql);
    $q->execute($args);
    jout(['rows'=>$q->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total, 'page'=>$page, 'size'=>$size]);
}

/* ══════════════════ CSV 匯出（後端對「全部符合條件」的資料算，不是只匯出目前這一頁） ══════════════════ */
case 'export_csv': {
    $kw   = trim((string)($_GET['kw'] ?? ''));
    $stF  = trim((string)($_GET['status'] ?? ''));
    $from = trim((string)($_GET['from'] ?? ''));
    $to   = trim((string)($_GET['to'] ?? ''));

    $w = ['COALESCE(a.is_deleted,0)=0']; $args = [];
    if (!$P['canView']) {
        $w[] = '(a.applicant_id=? OR a.created_by=? OR EXISTS (SELECT 1 FROM doc_apply_cosign c WHERE c.apply_id=a.apply_id AND c.signer_id=?))';
        array_push($args, $uid, $uid, $uid);
    }
    if ($stF !== '')  { $w[] = 'a.status=?';      $args[] = $stF; }
    if ($from !== '') { $w[] = 'a.apply_date>=?'; $args[] = $from; }
    if ($to !== '')   { $w[] = 'a.apply_date<=?'; $args[] = $to; }
    if ($kw !== '') {
        $cols = ['a.apply_no','a.doc_no','a.doc_name','a.doc_type','a.doc_status','a.version',
                 'a.dept_name','a.applicant_name','a.auto_note','a.decide_note'];
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $cols)) . ')';
            foreach ($cols as $c) $args[] = '%' . $k . '%';
        }
    }
    $sql = "SELECT a.*,
                   (SELECT COUNT(*) FROM doc_apply_cosign c WHERE c.apply_id=a.apply_id AND c.is_checked=1) AS cos_total,
                   (SELECT COUNT(*) FROM doc_apply_cosign c WHERE c.apply_id=a.apply_id AND c.is_checked=1 AND c.agree IS NOT NULL) AS cos_done,
                   (SELECT COUNT(*) FROM doc_apply_cosign c WHERE c.apply_id=a.apply_id AND c.is_checked=1 AND c.agree=0) AS cos_bad,
                   (SELECT MAX(p.printed_at) FROM doc_apply_print_log p WHERE p.apply_id=a.apply_id) AS last_print_at,
                   (SELECT COUNT(*) FROM doc_apply_print_log p WHERE p.apply_id=a.apply_id) AS print_count
            FROM doc_apply a WHERE " . implode(' AND ', $w) . "
            ORDER BY a.apply_date DESC, a.apply_id DESC";
    $q = $db->prepare($sql);
    $q->execute($args);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    $stName = ['draft'=>'草稿', 'submitted'=>'已送出', 'approved'=>'已核准', 'rejected'=>'已退回'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="doc_apply_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));    // BOM：Excel 開啟才不會亂碼
    fputcsv($out, ['單號','申請日期','文件狀況','文件類別','文件編碼','版本','文件名稱','申請部門','申請人',
                   '需會簽','會簽狀態','單據狀態','核准日期','最新列印日期','列印次數','備註']);
    foreach ($rows as $r) {
        $cs = '不需會簽';
        if ((int)$r['need_cosign']) {
            $t = (int)$r['cos_total']; $d = (int)$r['cos_done']; $b = (int)$r['cos_bad'];
            $cs = !$t ? '尚未指定' : ($d < $t ? "會簽中 $d/$t" : ($b ? "有不同意（$b）" : "全部同意 $d/$t"));
        }
        fputcsv($out, [
            $r['apply_no'], eg_fmt_date($r['apply_date']), $r['doc_status'], $r['doc_type'],
            $r['doc_no'], $r['version'], $r['doc_name'], $r['dept_name'], $r['applicant_name'],
            ((int)$r['need_cosign'] ? '是' : '否'), $cs,
            ($stName[$r['status']] ?? $r['status']) . ((int)$r['is_auto'] ? '（自動簽核）' : ''),
            eg_fmt_date($r['approved_date']),
            $r['last_print_at'] ? eg_fmt_date(substr((string)$r['last_print_at'], 0, 10)) : '未列印',
            (int)$r['print_count'], (string)$r['decide_note'],
        ]);
    }
    fclose($out);
    exit;
}

/* ══════════════════ 明細 ══════════════════ */
case 'detail': {
    $id = (int)($_GET['apply_id'] ?? 0);
    $r  = da_row($db, $id);
    if (!$r) jerr('查無此申請單', 404);
    if (!da_can_see($db, $r, $P, $uid)) jerr('無權限檢視此申請單', 403);
    $r['cosign_status'] = da_cosign_status($r);
    $r['can_edit']      = da_can_edit_row($r, $P, $uid);
    $r['my_cosign']     = da_cosign_rows_for_user($db, $id, $uid);
    $r['signer_preview'] = ($r['status'] === 'approved') ? null : da_resolve_signers($db, $r, false);
    $r['prints']        = [];
    try {
        $q = $db->prepare("SELECT printed_name, printed_at FROM doc_apply_print_log WHERE apply_id=? ORDER BY log_id DESC LIMIT 50");
        $q->execute([$id]); $r['prints'] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    jout(['row' => $r]);
}

/* ══════════════════ 建立 / 儲存（草稿） ══════════════════ */
case 'save': {
    if (!$P['canEdit']) jerr('無建立/編輯權限', 403);
    $id      = (int)($_POST['apply_id'] ?? 0);
    $changes = da_json_arr('changes');
    $dists   = da_json_arr('dists');
    $cosDept = array_values(array_filter(array_map('intval', da_json_arr('cosign_dept_ids'))));

    $applicantId = (int)($_POST['applicant_id'] ?? 0) ?: $uid;
    // 非管理員只能以自己名義開單
    if (!$P['canAdmin'] && $applicantId !== $uid) $applicantId = $uid;
    // 申請人的姓名／部門一律取「本單申請日期當時」的身分（補歷史單據才不會存成他現在的部門）
    $bizDay = trim((string)($_POST['apply_date'] ?? '')) ?: da_db_now($db)['d'];
    $ident  = da_user_identity_asof($db, $applicantId, $bizDay, (int)($_POST['dept_id'] ?? 0));

    // 申請部門是**推導欄位**：以申請人在該日期當時的那個職務為準，不採信前端送的值
    // （前端已改成唯讀自動帶出，後端同規則再算一次，避免直打 API 送出對不起來的部門，鐵律8）
    $deptId   = (int)($ident['dept_id'] ?? 0) ?: (int)($_POST['dept_id'] ?? 0);
    $deptName = (string)($ident['dept_name'] ?? '');
    if ($deptId && $deptName === '') {
        $q = $db->prepare("SELECT name FROM department WHERE id=?"); $q->execute([$deptId]);
        $deptName = (string)$q->fetchColumn();
    }

    $r = [
        'apply_date'   => $bizDay,
        'doc_status'   => trim((string)($_POST['doc_status'] ?? '')),
        'doc_type'     => trim((string)($_POST['doc_type'] ?? '')),
        'doc_name'     => trim((string)($_POST['doc_name'] ?? '')),
        'doc_no'       => trim((string)($_POST['doc_no'] ?? '')),
        'as_doc_id'    => (int)($_POST['as_doc_id'] ?? 0),
        'version'      => trim((string)($_POST['version'] ?? '')),
        'first_issue_date' => trim((string)($_POST['first_issue_date'] ?? '')),
        'dept_id'      => $deptId,
        'dept_name'    => $deptName,
        'applicant_id' => $applicantId,
        'applicant_name' => (string)$ident['user_name'],
        'need_overview'=> (int)($_POST['need_overview'] ?? 0) ? 1 : 0,
        'need_cosign'  => (int)($_POST['need_cosign'] ?? 0) ? 1 : 0,
        'cosign_dept_ids' => $cosDept,
    ];
    // 表單制訂不可有版次（後端一併清掉，防繞過前端）
    if (da_version_forbidden($r['doc_type'], $r['doc_status'])) $r['version'] = '';
    // 修正：首次發行日期一律由 AS 文件帶入，不採信前端
    if ($r['doc_status'] !== '制訂' && $r['as_doc_id']) {
        $fi = da_first_issue_date($db, $r['as_doc_id']);
        if ($fi) $r['first_issue_date'] = $fi;
    }
    $changeDate = $r['apply_date'];         // 版本變更日期＝本次申請日

    $now = da_db_now($db);
    $db->beginTransaction();
    if ($id > 0) {
        $old = da_row($db, $id);
        if (!$old) { $db->rollBack(); jerr('查無此申請單', 404); }
        if (!da_can_edit_row($old, $P, $uid)) { $db->rollBack(); jerr('此單已送出或非你可編輯', 403); }
        $db->prepare("UPDATE doc_apply SET apply_date=?, doc_status=?, doc_type=?, doc_name=?, doc_no=?,
                        as_doc_id=?, version=?, first_issue_date=?, change_date=?, dept_id=?, dept_name=?,
                        applicant_id=?, applicant_name=?, need_overview=?, need_cosign=?, updated_at=?
                      WHERE apply_id=?")
           ->execute([$r['apply_date'], $r['doc_status'], $r['doc_type'], $r['doc_name'], $r['doc_no'],
                      $r['as_doc_id'] ?: null, $r['version'], $r['first_issue_date'] ?: null, $changeDate,
                      $r['dept_id'] ?: null, $r['dept_name'], $r['applicant_id'], $r['applicant_name'],
                      $r['need_overview'], $r['need_cosign'], $now['dt'], $id]);
    } else {
        $db->prepare("INSERT INTO doc_apply
            (apply_no, apply_date, doc_status, doc_type, doc_name, doc_no, as_doc_id, version, first_issue_date,
             change_date, dept_id, dept_name, applicant_id, applicant_name, need_overview, need_cosign,
             status, source, created_by, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft','manual',?,?,?)")
           ->execute([da_next_no($db, $r['apply_date']), $r['apply_date'], $r['doc_status'], $r['doc_type'],
                      $r['doc_name'], $r['doc_no'], $r['as_doc_id'] ?: null, $r['version'],
                      $r['first_issue_date'] ?: null, $changeDate, $r['dept_id'] ?: null, $r['dept_name'],
                      $r['applicant_id'], $r['applicant_name'], $r['need_overview'], $r['need_cosign'],
                      $uid, $now['dt'], $now['dt']]);
        $id = (int)$db->lastInsertId();
    }

    // 制修訂內容
    $db->prepare("DELETE FROM doc_apply_change WHERE apply_id=?")->execute([$id]);
    $no = 0;
    foreach ($changes as $c) {
        $p = trim((string)($c['page_no'] ?? '')); $it = trim((string)($c['item'] ?? ''));
        $bf = trim((string)($c['before_txt'] ?? '')); $af = trim((string)($c['after_txt'] ?? ''));
        if ($p === '' && $it === '' && $bf === '' && $af === '') continue;
        $no++;
        $db->prepare("INSERT INTO doc_apply_change (apply_id,row_no,page_no,item,before_txt,after_txt) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $no, $p, $it, $bf, $af]);
    }

    // 核發／回收記錄（簽收者＝填寫單位；回收者固定文管中心負責人）
    // 回收者＝文管中心負責人；簽收者＝該填寫單位主管——兩者都取「本單申請日期當時」的那一位
    $docMgr = da_dept_manager_asof($db, eg_org_dept_ids($db, 'doc_dept'), $r['apply_date']);
    $db->prepare("DELETE FROM doc_apply_dist WHERE apply_id=?")->execute([$id]);
    $no = 0;
    foreach ($dists as $d) {
        $did = (int)($d['dept_id'] ?? 0);
        $dn  = trim((string)($d['dept_name'] ?? ''));
        if (!$did && $dn === '') continue;
        if ($did && $dn === '') {
            $q = $db->prepare("SELECT name FROM department WHERE id=?"); $q->execute([$did]); $dn = (string)$q->fetchColumn();
        }
        $no++;
        // 簽收者＝該填寫單位的主管（非申請人）；未指定人員時留白由紙本手簽
        $rid = (int)($d['receiver_id'] ?? 0);
        $rnm = trim((string)($d['receiver_name'] ?? ''));
        if (!$rid && $did) {
            $m = da_dept_manager_asof($db, eg_dept_subtree_ids($db, $did) ?: [$did], $r['apply_date']);
            if ($m) { $rid = (int)$m['id']; $rnm = (string)$m['user_cname']; }
        }
        $db->prepare("INSERT INTO doc_apply_dist
            (apply_id,row_no,dept_id,dept_name,issue_qty,issue_date,receiver_id,receiver_name,
             recall_qty,recall_date,recaller_id,recaller_name,note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$id, $no, $did ?: null, $dn,
                      trim((string)($d['issue_qty'] ?? '')), trim((string)($d['issue_date'] ?? '')) ?: null,
                      $rid ?: null, $rnm,
                      trim((string)($d['recall_qty'] ?? '')), trim((string)($d['recall_date'] ?? '')) ?: null,
                      $docMgr ? (int)$docMgr['id'] : null, $docMgr ? (string)$docMgr['user_cname'] : '',
                      trim((string)($d['note'] ?? ''))]);
    }

    // 會簽列（未勾需會簽＝清空）
    da_sync_cosign_rows($db, $id, $r['need_cosign'] ? $cosDept : [], $r['applicant_id'], $r['apply_date']);
    $db->commit();

    da_link_asdoc($db, $id);
    jout(['apply_id' => $id]);
}

/* ══════════════════ 送出（含必填檢查＋發會簽通知） ══════════════════ */
case 'submit': {
    $id = (int)($_POST['apply_id'] ?? 0);
    $r  = da_row($db, $id);
    if (!$r) jerr('查無此申請單', 404);
    if (!da_can_edit_row($r, $P, $uid)) jerr('此單已送出或非你可操作', 403);
    if ($r['status'] !== 'draft') jerr('此單已送出（請重新整理清單）');

    $chk = $r;
    $chk['cosign_dept_ids'] = array_map(fn($c) => (int)$c['dept_id'], $r['cosigns']);
    $errs = da_validate($db, $chk, $r['changes']);
    if ($errs) jerr('尚有必填欄位未完成', 400, ['fields' => $errs]);

    $now = da_db_now($db);
    $db->beginTransaction();
    $db->prepare("UPDATE doc_apply SET status='submitted', submit_date=?, submitted_at=?, updated_at=? WHERE apply_id=?")
       ->execute([$now['d'], $now['dt'], $now['dt'], $id]);
    $db->commit();

    // 發會簽通知（只發已勾選採用、且尚未表示意見的列）
    $sent = 0;
    if ((int)$r['need_cosign']) {
        $fresh = da_row($db, $id);
        foreach ($fresh['cosigns'] as $c) {
            if ((int)$c['is_checked'] !== 1 || $c['agree'] !== null || !$c['signer_id']) continue;
            $eid = da_notify_cosign($db, $fresh, $c, (int)$c['signer_id'], $uid);
            if ($eid) { $db->prepare("UPDATE doc_apply_cosign SET notice_id=? WHERE cos_id=?")->execute([$eid, (int)$c['cos_id']]); $sent++; }
        }
    }
    da_link_asdoc($db, $id);
    jout(['sent' => $sent]);
}

/* ══════════════════ 會簽單位「採用並簽」勾選（文管中心負責人／管理員） ══════════════════ */
case 'cosign_check': {
    if (!$P['canAdmin']) jerr('只有文管中心負責人／管理員可勾選會簽採用', 403);
    $id  = (int)($_POST['apply_id'] ?? 0);
    $ids = array_values(array_filter(array_map('intval', da_json_arr('cos_ids'))));
    $r   = da_row($db, $id);
    if (!$r) jerr('查無此申請單', 404);
    $db->beginTransaction();
    $db->prepare("UPDATE doc_apply_cosign SET is_checked=0 WHERE apply_id=?")->execute([$id]);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE doc_apply_cosign SET is_checked=1 WHERE apply_id=? AND cos_id IN ($in)")
           ->execute(array_merge([$id], $ids));
    }
    $db->commit();
    jout([]);
}

/* ══════════════════ 會簽（同意/不同意 + 意見 + 簽名） ══════════════════ */
case 'cosign_decide': {
    $cosId = (int)($_POST['cos_id'] ?? 0);
    $agree = $_POST['agree'] ?? '';
    $op    = trim((string)($_POST['opinion'] ?? ''));
    if ($agree !== '0' && $agree !== '1') jerr('請先選擇「同意」或「不同意」，再填寫會簽意見');

    $st = $db->prepare("SELECT * FROM doc_apply_cosign WHERE cos_id=?");
    $st->execute([$cosId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('查無此會簽列', 404);
    if ((int)$c['is_checked'] !== 1) jerr('此會簽單位尚未被勾選採用');
    if ($c['agree'] !== null) jerr('此會簽列已完成，請重新整理');
    if (!$P['isAdmin'] && (int)$c['signer_id'] !== $uid) jerr('此會簽列並非指派給你', 403);

    $r = da_row($db, (int)$c['apply_id']);
    if (!$r) jerr('查無此申請單', 404);
    if ($r['status'] !== 'submitted') jerr('此單目前狀態不可會簽');

    $now = da_db_now($db);
    $db->beginTransaction();
    $db->prepare("UPDATE doc_apply_cosign SET agree=?, opinion=?, signer_id=?, signer_name=?,
                    signed_date=?, signed_at=? WHERE cos_id=?")
       ->execute([(int)$agree, $op, $uid, $uname, $now['d'], $now['dt'], $cosId]);
    $db->commit();
    da_close_cosign_notice($db, $cosId);

    $fresh = da_row($db, (int)$c['apply_id']);
    $cs = da_cosign_status($fresh);
    if ($cs['code'] === 'agreed' || $cs['code'] === 'disagree') {
        da_notify_result($db, $fresh, (int)$fresh['applicant_id'],
            '您的文件制修申請單「' . $fresh['doc_name'] . '」會簽已完成：' . $cs['text'], $uid);
    }
    jout(['cosign_status' => $cs]);
}

/** 由通知點進來時：用 cos_id 反查所屬申請單與單位（供前端自動開會簽跳窗） */
case 'cosign_row': {
    $st = $db->prepare("SELECT cos_id, apply_id, dept_name, signer_id, agree, is_checked FROM doc_apply_cosign WHERE cos_id=?");
    $st->execute([(int)($_GET['cos_id'] ?? 0)]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('查無此會簽列', 404);
    jout(['cosign' => $c]);
}

/* ══════════════════ 核准／退回（四格簽章一次完成） ══════════════════ */
case 'decide': {
    if (!$P['canAdmin']) jerr('無核准權限', 403);
    $id   = (int)($_POST['apply_id'] ?? 0);
    $dec  = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $r    = da_row($db, $id);
    if (!$r) jerr('查無此申請單', 404);
    if ($r['status'] !== 'submitted') jerr('此單目前狀態不可核准／退回（請重新整理）');
    if ($dec === 'rejected' && $note === '') jerr('退回必須填寫原因');
    if (!in_array($dec, ['approved', 'rejected'], true)) jerr('參數錯誤');

    $now = da_db_now($db);
    $db->beginTransaction();
    if ($dec === 'rejected') {
        $db->prepare("UPDATE doc_apply SET status='rejected', decide_note=?, updated_at=? WHERE apply_id=?")
           ->execute([$note, $now['dt'], $id]);
    } else {
        $sg   = da_resolve_signers($db, $r, false);
        // 核准業務日期預設＝本單申請日期（使用者要求）；只有系統管理者可自行指定其他日期（ai-rules/21）
        $date = (string)$r['apply_date'];
        $in   = trim((string)($_POST['approved_date'] ?? ''));
        if ($in !== '' && $P['isAdmin']) $date = $in;
        $db->prepare("UPDATE doc_apply SET status='approved', approved_date=?, approved_at=?, decide_note=?,
                        sign_approve_id=?, sign_approve_name=?, sign_approve_date=?, sign_approve_dep=?,
                        sign_mgmt_id=?, sign_mgmt_name=?, sign_mgmt_date=?, sign_mgmt_dep=?,
                        sign_sup_id=?, sign_sup_name=?, sign_sup_date=?, sign_sup_dep=?,
                        sign_applicant_id=?, sign_applicant_name=?, sign_applicant_date=?, sign_applicant_dep=?,
                        updated_at=? WHERE apply_id=?")
           ->execute([$date, $now['dt'], $note,
                      $sg['approve']['id'], $sg['approve']['name'], $date, $sg['approve']['is_delegated'],
                      $sg['mgmt']['id'],    $sg['mgmt']['name'],    $date, $sg['mgmt']['is_delegated'],
                      $sg['sup']['id'],     $sg['sup']['name'],     $date, $sg['sup']['is_delegated'],
                      $sg['applicant']['id'], $sg['applicant']['name'], $r['apply_date'], 0,
                      $now['dt'], $id]);
    }
    $db->commit();
    da_link_asdoc($db, $id);

    $fresh = da_row($db, $id);
    da_notify_result($db, $fresh, (int)$fresh['applicant_id'],
        $uname . ($dec === 'approved' ? ' 已核准您的文件制修申請單。' : ' 已退回您的文件制修申請單。原因：') . $note, $uid);
    jout([]);
}

/* ══════════════════ 管理員自動簽核（需操作確認密碼） ══════════════════ */
case 'auto_sign': {
    if (!$P['canAdmin']) jerr('無自動簽核權限', 403);
    $ids  = array_values(array_filter(array_map('intval', da_json_arr('apply_ids'))));
    if (!$ids) $ids = array_filter([(int)($_POST['apply_id'] ?? 0)]);
    if (!$ids) jerr('請先勾選要自動簽核的申請單');
    $pw = (string)($_POST['confirm_password'] ?? '');
    $vr = eg_confirm_password_verify_scoped($db, $uid, $pw, 'doc_apply_auto_sign');
    if (empty($vr['ok'])) jerr($vr['msg'] ?? '操作確認密碼錯誤', 403);

    // 管理員可手動指定本次填表人與日期（補歷史紙本用）
    $ovUser = (int)($_POST['override_applicant_id'] ?? 0);
    $ovDate = trim((string)($_POST['override_date'] ?? ''));

    $done = 0; $skip = [];
    foreach ($ids as $id) {
        $r = da_row($db, $id);
        if (!$r) { $skip[] = "#$id 查無資料"; continue; }
        if ($r['status'] === 'approved') { $skip[] = ($r['apply_no'] ?: "#$id") . ' 已核准'; continue; }

        if ($ovUser) {
            // 手動指定的填表人也要用「該單業務日期當時」的身分（$ovDate 有填就以它為準）
            $identDay = trim((string)($_POST['override_date'] ?? '')) ?: (string)$r['apply_date'];
            $ident = da_user_identity_asof($db, $ovUser, $identDay, (int)($_POST['override_dept_id'] ?? 0));
            $r['applicant_id'] = $ovUser; $r['applicant_name'] = $ident['user_name'];
            // 兼任職務：前端送的是「該人在哪個部門的身分」，優先採用它，查不到才退回主要職務部門
            $ovDept = (int)($_POST['override_dept_id'] ?? 0);
            $useDept = $ovDept ?: (int)($ident['dept_id'] ?? 0);
            if ($useDept) {
                $r['dept_id'] = $useDept;
                $q = $db->prepare("SELECT name FROM department WHERE id=?"); $q->execute([$useDept]);
                $r['dept_name'] = (string)$q->fetchColumn();
            }
        }
        if ($ovDate !== '') $r['apply_date'] = $ovDate;

        $chk = $r;
        $chk['cosign_dept_ids'] = array_map(fn($c) => (int)$c['dept_id'], $r['cosigns']);
        $errs = da_validate($db, $chk, $r['changes']);
        if ($errs) { $skip[] = ($r['apply_no'] ?: "#$id") . '：' . implode('、', $errs); continue; }

        // ai-rules/21：業務日期＝申請日；精確時間戳錯開 5~30 分且不跨日
        $submittedAt = $r['apply_date'] . ' 09:00:00';
        $autoAt      = da_auto_sign_time($submittedAt);
        $bizDate     = $r['apply_date'];
        $sg          = da_resolve_signers($db, $r, true);

        $db->beginTransaction();
        $db->prepare("UPDATE doc_apply SET applicant_id=?, applicant_name=?, dept_id=?, dept_name=?, apply_date=?, change_date=?,
                        status='approved', is_auto=1, auto_note=?, submit_date=?, submitted_at=?, approved_date=?, approved_at=?,
                        sign_approve_id=?, sign_approve_name=?, sign_approve_date=?, sign_approve_dep=?,
                        sign_mgmt_id=?, sign_mgmt_name=?, sign_mgmt_date=?, sign_mgmt_dep=?,
                        sign_sup_id=?, sign_sup_name=?, sign_sup_date=?, sign_sup_dep=?,
                        sign_applicant_id=?, sign_applicant_name=?, sign_applicant_date=?, sign_applicant_dep=?,
                        updated_at=NOW() WHERE apply_id=?")
           ->execute([$r['applicant_id'] ?: null, $r['applicant_name'], $r['dept_id'] ?: null, $r['dept_name'],
                      $bizDate, $bizDate, '由 ' . $uname . ' 執行管理員自動簽核',
                      $bizDate, $submittedAt, $bizDate, $autoAt,
                      $sg['approve']['id'], $sg['approve']['name'], $bizDate, $sg['approve']['is_delegated'],
                      $sg['mgmt']['id'],    $sg['mgmt']['name'],    $bizDate, $sg['mgmt']['is_delegated'],
                      $sg['sup']['id'],     $sg['sup']['name'],     $bizDate, $sg['sup']['is_delegated'],
                      $sg['applicant']['id'], $sg['applicant']['name'], $bizDate, 0,
                      $id]);
        // 會簽列一併自動簽（同意），日期同業務日期、時間錯開
        foreach ($r['cosigns'] as $c) {
            if ((int)$c['is_checked'] !== 1 || $c['agree'] !== null) continue;
            $sgc = ($c['signer_id']) ? ['id'=>(int)$c['signer_id'], 'name'=>(string)$c['signer_name']]
                                     : da_cosign_signer($db, (int)$c['dept_id'], (int)$r['applicant_id'], true, $bizDate);
            $db->prepare("UPDATE doc_apply_cosign SET agree=1, signer_id=?, signer_name=?, signed_date=?, signed_at=?, is_auto=1 WHERE cos_id=?")
               ->execute([$sgc['id'] ?: null, $sgc['name'], $bizDate, da_auto_sign_time($submittedAt), (int)$c['cos_id']]);
            da_close_cosign_notice($db, (int)$c['cos_id']);
        }
        $db->commit();
        da_link_asdoc($db, $id);
        $done++;
    }
    jout(['done' => $done, 'skipped' => $skip]);
}

/* ══════════════════ 刪除（單筆／批次） ══════════════════ */
case 'delete': {
    $ids = array_values(array_filter(array_map('intval', da_json_arr('apply_ids'))));
    if (!$ids) $ids = array_filter([(int)($_POST['apply_id'] ?? 0)]);
    if (!$ids) jerr('請先勾選要刪除的申請單');
    $done = 0;
    foreach ($ids as $id) {
        $r = da_row($db, $id);
        if (!$r) continue;
        if (!$P['canAdmin'] && !($r['status'] === 'draft' && (int)$r['created_by'] === $uid)) continue;
        $db->prepare("UPDATE doc_apply SET is_deleted=1, updated_at=NOW() WHERE apply_id=?")->execute([$id]);
        foreach ($r['cosigns'] as $c) da_close_cosign_notice($db, (int)$c['cos_id']);
        $done++;
    }
    if (!$done) jerr('沒有可刪除的申請單（只有管理員或草稿的建立者可刪）', 403);
    jout(['done' => $done]);
}

/* ══════════════════ 建議建立：掃描缺申請單的 AS 文件／改版 ══════════════════ */
case 'suggest_scan': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    $since = trim((string)($_GET['since'] ?? ''));
    jout(['rows' => da_suggest_scan($db, $since, (int)($_GET['limit'] ?? 500))]);
}

case 'suggest_create': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    $vids = array_values(array_filter(array_map('intval', da_json_arr('version_ids'))));
    if (!$vids) jerr('請先勾選要建立的項目');
    $all  = da_suggest_scan($db, '', 2000);
    $byId = [];
    foreach ($all as $v) $byId[(int)$v['version_id']] = $v;
    $ok = 0; $fail = [];
    foreach ($vids as $vid) {
        if (!isset($byId[$vid])) { $fail[] = "#$vid 已有申請單或查無版本"; continue; }
        $db->beginTransaction();
        $aid = da_create_from_version($db, $byId[$vid], $uid, $uname);
        if ($aid) { $db->commit(); $ok++; } else { $db->rollBack(); $fail[] = "#$vid 建立失敗"; }
    }
    jout(['created' => $ok, 'failed' => $fail]);
}

/* ══════════════════ 會簽預設（部門分類＋單一文件覆寫） ══════════════════ */
case 'cosign_defaults': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    $rows = [];
    try {
        $rows = $db->query("SELECT d.*, CASE WHEN d.scope_type='dept' THEN dp.name ELSE CONCAT(ad.doc_no,'　',ad.doc_name) END AS scope_name
                            FROM doc_apply_cosign_default d
                            LEFT JOIN department dp ON d.scope_type='dept' AND dp.id=d.scope_id
                            LEFT JOIN as_document ad ON d.scope_type='doc' AND ad.id=d.scope_id
                            ORDER BY d.scope_type, d.scope_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    jout(['rows' => $rows, 'global' => da_cosign_default($db, 0, null)]);
}

case 'save_cosign_default': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    $type = (string)($_POST['scope_type'] ?? '');
    $sid  = (int)($_POST['scope_id'] ?? 0);
    $need = (int)($_POST['need_cosign'] ?? 0);
    $ids  = array_values(array_filter(array_map('intval', da_json_arr('dept_ids'))));
    if ($type === 'global') {
        da_save_setting($db, 'da_default_need_cosign', $need ? 1 : 0);
        da_save_setting($db, 'da_default_cosign_depts', implode(',', $ids));
        jout([]);
    }
    if (!in_array($type, ['dept', 'doc'], true) || $sid <= 0) jerr('參數錯誤');
    da_save_cosign_default($db, $type, $sid, $need, $ids, $uname);
    jout([]);
}

case 'delete_cosign_default': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    $db->prepare("DELETE FROM doc_apply_cosign_default WHERE def_id=?")->execute([(int)($_POST['def_id'] ?? 0)]);
    jout([]);
}

/* ══════════════════ 依業務日期回推當時的人員候選 ══════════════════ */
/* 補歷史單據時，申請人下拉要列「該申請日期當時在職」的人（含現已離職者）與當時的職務，
   不能用現況——否則當時的人選不到、當時還沒到職的人卻被列出（使用者明確要求）。 */
case 'people_asof': {
    $date = trim((string)($_GET['date'] ?? ''));
    jout(['rows' => da_people_posts_asof($db, $date), 'date' => $date]);
}

/* ══════════════════ 制修訂內容預設組 ══════════════════ */
case 'change_presets':
    jout(['rows' => da_change_presets($db, $P['canAdmin'])]);

case 'save_change_preset': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    $name = trim((string)($_POST['preset_name'] ?? ''));
    if ($name === '') jerr('請填寫預設組名稱');
    $rows = da_json_arr('rows');
    if (!$rows) jerr('請至少填寫一列制修訂內容');
    $id = da_save_change_preset($db, (int)($_POST['preset_id'] ?? 0), $name, $rows,
                                (int)($_POST['sort_order'] ?? 0), (int)($_POST['is_active'] ?? 1), $uname);
    jout(['preset_id' => $id]);
}

case 'delete_change_preset': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    da_delete_change_preset($db, (int)($_POST['preset_id'] ?? 0));
    jout([]);
}

/* ══════════════════ 模組設定（圖章模板／四格簽章來源） ══════════════════ */
case 'save_setting': {
    if (!$P['canAdmin']) jerr('無權限', 403);
    $k = (string)($_POST['key'] ?? '');
    if (!in_array($k, DA_SETTING_KEYS, true)) jerr('未知設定項');
    da_save_setting($db, $k, (string)($_POST['value'] ?? ''));
    jout([]);
}

/* ══════════════════ 列印用資料 ══════════════════ */
case 'print_meta': {
    $id = (int)($_GET['apply_id'] ?? 0);
    $r  = da_row($db, $id);
    if (!$r) jerr('查無此申請單', 404);
    if (!da_can_see($db, $r, $P, $uid)) jerr('無權限', 403);
    $docId = eg_asdoc_id($db, DA_ASDOC_MODULE);
    jout([
        'row'      => $r,
        'company'  => eg_company_full_name($db),
        // 表頭＝綁定 AS 文件的 doc_name（禁寫死，ai-rules/16）
        'doc_name' => $docId ? (string)(eg_asdoc_get($db, DA_ASDOC_MODULE)['doc_name'] ?? '') : '',
        // 頁尾右下角編號＝依本單業務日期回推當時生效版次（ai-rules/16 第三之四節）
        'doc_no'   => $docId ? eg_asdoc_no_asof_id($db, $docId, (string)$r['apply_date']) : '',
        'stamp_main'   => da_stamp_template($db, 'da_stamp_tpl_id'),
        'stamp_cosign' => da_stamp_template($db, 'da_cosign_stamp_tpl_id'),
        'stamp_dist'   => da_stamp_template($db, 'da_dist_stamp_tpl_id'),
    ]);
}

case 'print_log': {
    $ids = array_values(array_filter(array_map('intval', da_json_arr('apply_ids'))));
    if (!$ids) $ids = array_filter([(int)($_POST['apply_id'] ?? 0)]);
    foreach ($ids as $id) {
        $db->prepare("INSERT INTO doc_apply_print_log (apply_id, printed_by, printed_name, printed_at) VALUES (?,?,?,NOW())")
           ->execute([$id, $uid, $uname]);
    }
    jout(['logged' => count($ids)]);
}

default:
    jerr('未知動作：' . $action, 404);
}
} catch (Throwable $e) {
    if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $e2) {} }
    jerr('系統錯誤：' . $e->getMessage(), 500);
}
