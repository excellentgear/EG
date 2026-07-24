<?php
/**
 * AS 線上表單設計器 API
 * 模板(schema定義) / 發布版本(凍結) / 填寫紀錄 / 簽核區 / 單一表單授權。
 * 權限：比照 as_doc 模組（管理員 or asdoc_update/settings）；另支援「單一表單授權」(as_form_grant)。
 * 表頭/表尾一律即時取值（鐵律5），不寫死進 schema。
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';
include_once $document_root . '/EGsystem/src/common/as_form_flow.php';

$db = (new DBConnection())->getPDO();

function jout($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// ── 使用者 ──
$uname = $_SESSION['userName'] ?? '';
if ($uname === '') jerr('未登入', 401);
$st = $db->prepare("SELECT id, user_cname FROM user WHERE user_uname = ?");
$st->execute([$uname]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) jerr('使用者不存在', 401);
$uid   = (int)$u['id'];
$cname = (string)($u['user_cname'] ?: $uname);

// ── as_doc 能力 ──
$asFeatures    = rf_load_user_features_override($db, $uid, 'as_doc');
$asIsRoleAdmin = in_array('all', $asFeatures, true);
$asPagePerm = '';
try {
    $pg = $db->query("SELECT page_id, group_id FROM system_module_pages WHERE page_url LIKE '%views/ADM/as_document_management.php' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($pg) {
        $p = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $p->execute([$uid, $pg['page_id']]);
        $perms = $p->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms) && !empty($pg['group_id'])) {
            $gc = $db->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $gc->execute([$pg['group_id']]);
            if ($code = $gc->fetchColumn()) {
                $p = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $p->execute([$uid, $code]);
                $perms = $p->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars = [];
        foreach ($perms as $x) { $chars = array_merge($chars, str_split($x)); }
        $asPagePerm = implode('', array_unique($chars));
    }
} catch (Exception $e) {}

$isAdmin  = $asIsRoleAdmin || strpos($asPagePerm, 'A') !== false;
// 可建立/設計「新」表單：管理員 or asdoc_update
$canBuild = $isAdmin || strpos($asPagePerm, 'U') !== false || in_array('asdoc_update', $asFeatures, true);

/** 是否可設計某張既有表單：管理員 or 具建表權 or 對該表單有生效中的單一授權 */
function canDesignTemplate(PDO $db, int $uid, bool $canBuild, int $templateId): bool {
    if ($canBuild) return true;
    if ($templateId <= 0) return false;
    $g = $db->prepare("SELECT 1 FROM as_form_grant WHERE template_id=? AND grantee_id=? AND revoked_at IS NULL LIMIT 1");
    $g->execute([$templateId, $uid]);
    return (bool)$g->fetchColumn();
}

/** 表頭/表尾即時值：company=本公司客戶全名、docNo/version=所屬 as_document */
function buildCtx(PDO $db, array $tpl): array {
    $company = '';
    try {
        if ($cr = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC)) {
            $company = $cr['customer_full'] ?: ($cr['customer'] ?? '');
        }
    } catch (Exception $e) {}
    $docNo = ''; $version = '';   // 版次不猜：僅取文件實際版次，無則留空
    if (!empty($tpl['form_doc_id'])) {
        $d = $db->prepare("SELECT doc_no, current_version FROM as_document WHERE id=?");
        $d->execute([$tpl['form_doc_id']]);
        if ($dr = $d->fetch(PDO::FETCH_ASSOC)) {
            $docNo = $dr['doc_no'] ?? '';
            $version = trim((string)($dr['current_version'] ?? ''));
        }
    }
    // 部門 id→名稱對照（固定部門欄位顯示時即時解析，改名自動連動）
    $deptMap = [];
    try {
        foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d2) {
            $deptMap[(string)$d2['id']] = $d2['name'];
        }
    } catch (Exception $e) {}
    return ['company'=>$company, 'docNo'=>$docNo, 'version'=>$version, 'deptMap'=>$deptMap];
}

/** 目前使用者身分（自動帶入欄位用）：姓名＋全部(部門,職稱)身分，主要(is_main)排最前 */
function buildUserCtx(PDO $db, int $uid, string $cname): array {
    $rows = [];
    try {
        $st = $db->prepare("SELECT d.name AS dept, p.name AS position, m.is_main
                            FROM user_department_position_map m
                            JOIN department d ON d.id=m.department_id
                            JOIN position p ON p.id=m.position_id
                            WHERE m.user_id=? ORDER BY m.is_main DESC, m.id");
        $st->execute([$uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    return ['name'=>$cname, 'positions'=>$rows];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
switch ($action) {

// ── 下拉選項 meta（職稱/部門，設計器簽核區用選的避免手打錯字）──
case 'meta': {
    $positions = $db->query("SELECT id, name FROM position ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $db->query("SELECT id, name FROM department ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    // 可綁定的四階表單文件（表尾文件編號由此連動）
    $formDocs = $db->query("SELECT id, doc_no, doc_name FROM as_document WHERE doc_type='表單' AND is_deleted=0 ORDER BY doc_no")->fetchAll(PDO::FETCH_ASSOC);
    jout(['ok'=>true, 'positions'=>$positions, 'departments'=>$departments, 'form_docs'=>$formDocs]);
}

// ── 刪除模板（軟刪除；既有填寫紀錄保留可查）──
case 'template_delete': {
    if (!$canBuild) jerr('無刪除權限', 403);
    $tid = (int)($_POST['template_id'] ?? 0);
    if (!$tid) jerr('缺 template_id');
    $db->prepare("UPDATE as_form_template SET is_deleted=1, updated_at=NOW() WHERE id=?")->execute([$tid]);
    jout(['ok'=>true]);
}

// ── 綁定/改綁文件編號（form_doc_id=0 解除綁定）──
case 'bind_doc': {
    $tid = (int)($_POST['template_id'] ?? 0);
    $fdid = (int)($_POST['form_doc_id'] ?? 0);
    if (!$tid) jerr('缺 template_id');
    if (!canDesignTemplate($db, $uid, $canBuild, $tid)) jerr('無權設定此表單', 403);
    if ($fdid) {
        $d = $db->prepare("SELECT id FROM as_document WHERE id=? AND doc_type='表單' AND is_deleted=0");
        $d->execute([$fdid]);
        if (!$d->fetchColumn()) jerr('文件不存在或非表單類');
    }
    $db->prepare("UPDATE as_form_template SET form_doc_id=?, updated_at=NOW() WHERE id=?")
       ->execute([$fdid ?: null, $tid]);
    jout(['ok'=>true]);
}

// ── 列出模板（可選 form_doc_id 篩選）──
case 'list': {
    $where = "t.is_deleted=0";
    $args = [];
    if (!empty($_GET['form_doc_id'])) { $where .= " AND t.form_doc_id=?"; $args[] = (int)$_GET['form_doc_id']; }
    $q = $db->prepare("SELECT t.id, t.form_doc_id, t.name, t.status, t.published_version, t.updated_at,
                              d.doc_no, d.current_version AS doc_version
                       FROM as_form_template t LEFT JOIN as_document d ON d.id=t.form_doc_id
                       WHERE $where ORDER BY t.updated_at DESC, t.id DESC");
    $q->execute($args);
    jout(['ok'=>true, 'rows'=>$q->fetchAll(PDO::FETCH_ASSOC), 'canBuild'=>$canBuild, 'isAdmin'=>$isAdmin]);
}

// ── 載入單一模板 + schema + 即時表頭表尾 ──
case 'load': {
    $tid = (int)($_GET['template_id'] ?? 0);
    if (!$tid) jerr('缺 template_id');
    $q = $db->prepare("SELECT * FROM as_form_template WHERE id=? AND is_deleted=0");
    $q->execute([$tid]);
    $tpl = $q->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) jerr('模板不存在', 404);
    $canDesign = canDesignTemplate($db, $uid, $canBuild, $tid);
    $ctx = buildCtx($db, $tpl);
    $ctx['user'] = buildUserCtx($db, $uid, $cname);   // 自動帶入欄位（姓名/部門/職稱）用
    jout(['ok'=>true, 'template'=>[
        'id'=>(int)$tpl['id'], 'form_doc_id'=>$tpl['form_doc_id'], 'name'=>$tpl['name'],
        'status'=>$tpl['status'], 'published_version'=>(int)$tpl['published_version'],
    ], 'schema'=>json_decode($tpl['current_schema'] ?: '{}'), 'ctx'=>$ctx,
       'canDesign'=>$canDesign,
       'manual_saved_at'=>$tpl['manual_saved_at'] ?? null]);
}

// ── 新建模板（可繫結四階表單 form_doc_id；須先有表名）──
case 'create': {
    if (!$canBuild) jerr('無建立表單權限', 403);
    $name = trim($_POST['name'] ?? '');
    if ($name === '') jerr('請輸入表單名稱');
    $formDocId = !empty($_POST['form_doc_id']) ? (int)$_POST['form_doc_id'] : null;
    $blank = json_encode(['meta'=>['title'=>$name],'grid'=>['cols'=>6],'cells'=>[],'sections'=>[],'crosscheck'=>[]], JSON_UNESCAPED_UNICODE);
    $ins = $db->prepare("INSERT INTO as_form_template (form_doc_id, name, current_schema, status, created_by) VALUES (?,?,?,'draft',?)");
    $ins->execute([$formDocId, $name, $blank, $cname]);
    jout(['ok'=>true, 'template_id'=>(int)$db->lastInsertId()]);
}

// ── 儲存 schema 草稿 ──
case 'save_schema': {
    $tid = (int)($_POST['template_id'] ?? 0);
    if (!$tid) jerr('缺 template_id');
    if (!canDesignTemplate($db, $uid, $canBuild, $tid)) jerr('無權設計此表單', 403);
    $raw = $_POST['schema_json'] ?? '';
    $decoded = json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE) jerr('schema JSON 格式錯誤：'.json_last_error_msg());
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        $db->prepare("UPDATE as_form_template SET current_schema=?, name=?, updated_at=NOW() WHERE id=?")->execute([$raw, $name, $tid]);
    } else {
        $db->prepare("UPDATE as_form_template SET current_schema=?, updated_at=NOW() WHERE id=?")->execute([$raw, $tid]);
    }
    // 手動存草稿（按鈕/發布）→ 另存手動快照，供「回手動存檔」還原（自動儲存不覆蓋此快照）
    if (($_POST['manual'] ?? '') == '1') {
        $db->prepare("UPDATE as_form_template SET manual_schema=?, manual_saved_at=NOW() WHERE id=?")->execute([$raw, $tid]);
    }
    jout(['ok'=>true]);
}

// ── 回到最後一次手動存草稿（放棄之後的自動儲存變動）──
case 'restore_manual': {
    $tid = (int)($_POST['template_id'] ?? 0);
    if (!$tid) jerr('缺 template_id');
    if (!canDesignTemplate($db, $uid, $canBuild, $tid)) jerr('無權操作此表單', 403);
    $q = $db->prepare("SELECT manual_schema, manual_saved_at FROM as_form_template WHERE id=? AND is_deleted=0");
    $q->execute([$tid]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['manual_schema']===null || $row['manual_schema']==='') jerr('尚無手動存檔快照（先按一次「存草稿」）');
    $db->prepare("UPDATE as_form_template SET current_schema=manual_schema, updated_at=NOW() WHERE id=?")->execute([$tid]);
    jout(['ok'=>true, 'schema'=>json_decode($row['manual_schema']), 'saved_at'=>$row['manual_saved_at']]);
}

// ── 發布：版號+1、凍結快照、狀態轉 published ──
case 'publish': {
    $tid = (int)($_POST['template_id'] ?? 0);
    if (!$tid) jerr('缺 template_id');
    if (!canDesignTemplate($db, $uid, $canBuild, $tid)) jerr('無權發布此表單', 403);
    $db->beginTransaction();
    try {
        $q = $db->prepare("SELECT current_schema, published_version FROM as_form_template WHERE id=? FOR UPDATE");
        $q->execute([$tid]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('模板不存在');
        if (json_last_error() === JSON_ERROR_NONE) { json_decode($row['current_schema']); }
        $newVer = (int)$row['published_version'] + 1;
        $db->prepare("INSERT INTO as_form_template_version (template_id, version, schema_json, published_by) VALUES (?,?,?,?)")
           ->execute([$tid, $newVer, $row['current_schema'], $cname]);
        $db->prepare("UPDATE as_form_template SET published_version=?, status='published', updated_at=NOW() WHERE id=?")
           ->execute([$newVer, $tid]);
        $db->commit();

        // ── 發布自動建議文件改版：綁定文件的模板再發布（非首版）→ 建議開「文件制修申請單」
        //    制修申請單模板＝綁定 doc_no='2-DC-01-01' 的已發布線上表單（動態查，不寫死 id）
        $suggest = null;
        try {
            $ti = $db->prepare("SELECT t.form_doc_id, d.doc_no, d.doc_name, d.current_version
                                FROM as_form_template t JOIN as_document d ON d.id=t.form_doc_id
                                WHERE t.id=?");
            $ti->execute([$tid]);
            $bound = $ti->fetch(PDO::FETCH_ASSOC);
            if ($bound && $newVer > 1) {
                $rv = $db->prepare("SELECT t.id, t.current_schema FROM as_form_template t JOIN as_document d ON d.id=t.form_doc_id
                                    WHERE d.doc_no='2-DC-01-01' AND t.status='published' AND t.is_deleted=0
                                    ORDER BY t.id LIMIT 1");
                $rv->execute();
                if ($rvRow = $rv->fetch(PDO::FETCH_ASSOC)) {
                    // 依制修申請單模板上「特殊用途(purpose)」標記的欄位，動態組 prefill（不寫死欄位代號）
                    $vals = [
                        'rev_type'        => '修訂',
                        'rev_target_no'   => $bound['doc_no'],
                        'rev_target_name' => $bound['doc_name'],
                        'rev_target_ver'  => $bound['current_version'],
                        'rev_reason'      => '線上表單設計改版（發布第 '.$newVer.' 版），申請文件改版。',
                    ];
                    $prefill = [];
                    $revSchema = json_decode($rvRow['current_schema'] ?: '{}', true) ?: [];
                    foreach (($revSchema['cells'] ?? []) as $c) {
                        $pp = $c['purpose'] ?? ''; $ck = $c['key'] ?? '';
                        if ($pp !== '' && $ck !== '' && isset($vals[$pp])) $prefill[$ck] = $vals[$pp];
                    }
                    $suggest = [
                        'template_id' => (int)$rvRow['id'],
                        'doc_no'      => $bound['doc_no'],
                        'doc_name'    => $bound['doc_name'],
                        'doc_version' => $bound['current_version'],
                        'prefill'     => $prefill,                       // 依 purpose 對應（可能為空＝未標記）
                        'reason'      => $vals['rev_reason'],            // 供前端 prompt 預設
                    ];
                }
            }
        } catch (Exception $e) { /* 建議失敗不影響發布 */ }
        jout(['ok'=>true, 'version'=>$newVer, 'suggest_revision'=>$suggest]);
    } catch (Exception $e) { $db->rollBack(); jerr('發布失敗：'.$e->getMessage(), 500); }
}

// ═══════════ 單一表單授權（as_form_grant）═══════════
// 有建表權者可把「某一張表單」授權給同部門組員設計/編輯；頁面顯示誰於何時授權給誰。

// ── 某表單的授權清單＋可授權的同部門人員 ──
case 'grant_list': {
    $tid = (int)($_GET['template_id'] ?? 0);
    if (!$tid) jerr('缺 template_id');
    $g = $db->prepare("SELECT g.*, u.user_cname AS grantee_cname FROM as_form_grant g LEFT JOIN user u ON u.id=g.grantee_id WHERE g.template_id=? ORDER BY g.id DESC");
    $g->execute([$tid]);
    // 可授權對象＝授權人（目前使用者）主部門的在職人員
    $dept = asf_user_main_dept($db, $uid);
    $members = [];
    if ($dept) {
        $m = $db->prepare("SELECT DISTINCT u.id, u.user_cname FROM user_department_position_map m JOIN user u ON u.id=m.user_id
                           WHERE m.department_id=? AND u.state IN (1,99) AND u.id<>? ORDER BY u.user_cname");
        $m->execute([$dept, $uid]);
        $members = $m->fetchAll(PDO::FETCH_ASSOC);
    }
    jout(['ok'=>true, 'rows'=>$g->fetchAll(PDO::FETCH_ASSOC), 'members'=>$members, 'canGrant'=>$canBuild]);
}

// ── 授權（僅具建表權者；對象限同部門）──
case 'grant_add': {
    if (!$canBuild) jerr('僅具建表權限者可授權', 403);
    $tid = (int)($_POST['template_id'] ?? 0);
    $gid = (int)($_POST['grantee_id'] ?? 0);
    if (!$tid || !$gid) jerr('缺 template_id / grantee_id');
    // 表單須已存在（先建表名才可授權）
    $t = $db->prepare("SELECT id FROM as_form_template WHERE id=? AND is_deleted=0");
    $t->execute([$tid]);
    if (!$t->fetchColumn()) jerr('表單不存在（須先建立表單名稱）', 404);
    // 對象限授權人同部門
    $dept = asf_user_main_dept($db, $uid);
    $chk = $db->prepare("SELECT COUNT(*) FROM user_department_position_map WHERE user_id=? AND department_id=?");
    $chk->execute([$gid, $dept]);
    if (!$isAdmin && !(int)$chk->fetchColumn()) jerr('僅能授權給同部門人員', 403);
    // 已有生效授權則不重複
    $dup = $db->prepare("SELECT id FROM as_form_grant WHERE template_id=? AND grantee_id=? AND revoked_at IS NULL");
    $dup->execute([$tid, $gid]);
    if ($dup->fetchColumn()) jerr('此人已有此表單的生效授權');
    $gn = $db->prepare("SELECT user_cname FROM user WHERE id=?");
    $gn->execute([$gid]);
    $granteeName = (string)($gn->fetchColumn() ?: $gid);
    $db->prepare("INSERT INTO as_form_grant (template_id, grantee_id, grantee_name, granted_by, granted_by_name) VALUES (?,?,?,?,?)")
       ->execute([$tid, $gid, $granteeName, $uid, $cname]);
    jout(['ok'=>true]);
}

// ── 撤銷授權 ──
case 'grant_revoke': {
    if (!$canBuild) jerr('僅具建表權限者可撤銷', 403);
    $gid = (int)($_POST['grant_id'] ?? 0);
    if (!$gid) jerr('缺 grant_id');
    $db->prepare("UPDATE as_form_grant SET revoked_at=NOW() WHERE id=? AND revoked_at IS NULL")->execute([$gid]);
    jout(['ok'=>true]);
}

// ═══════════ 填寫紀錄（instance）與簽核 ═══════════

// ── 建立/儲存草稿（未送出前可反覆改）──
case 'instance_save': {
    $tid = (int)($_POST['template_id'] ?? 0);
    $iid = (int)($_POST['instance_id'] ?? 0);
    if (!$tid && !$iid) jerr('缺 template_id 或 instance_id');
    $data = $_POST['data_json'] ?? '{}';
    json_decode($data);
    if (json_last_error() !== JSON_ERROR_NONE) jerr('data JSON 格式錯誤');
    if ($iid) {
        // 只有本人且尚未送出可改
        $q = $db->prepare("SELECT created_by, status FROM as_form_instance WHERE id=? AND is_deleted=0");
        $q->execute([$iid]);
        $inst = $q->fetch(PDO::FETCH_ASSOC);
        if (!$inst) jerr('紀錄不存在', 404);
        if ($inst['created_by'] !== $cname && !$isAdmin) jerr('僅填表本人可修改', 403);
        if (!in_array($inst['status'], ['draft','rejected'], true)) jerr('已送出的表單不可修改');
        $db->prepare("UPDATE as_form_instance SET data_json=?, title=?, updated_at=NOW() WHERE id=?")
           ->execute([$data, trim($_POST['title'] ?? '') ?: null, $iid]);
        jout(['ok'=>true, 'instance_id'=>$iid]);
    }
    // 新建：取模板已發布版
    $q = $db->prepare("SELECT id, form_doc_id, published_version FROM as_form_template WHERE id=? AND is_deleted=0");
    $q->execute([$tid]);
    $tpl = $q->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) jerr('模板不存在', 404);
    if (!(int)$tpl['published_version']) jerr('此表單尚未發布，不可填寫');
    $ins = $db->prepare("INSERT INTO as_form_instance (template_id, template_version, form_doc_id, title, data_json, status, created_by) VALUES (?,?,?,?,?,'draft',?)");
    $ins->execute([$tid, (int)$tpl['published_version'], $tpl['form_doc_id'], trim($_POST['title'] ?? '') ?: null, $data, $cname]);
    jout(['ok'=>true, 'instance_id'=>(int)$db->lastInsertId()]);
}

// ── 載入填寫紀錄（含凍結版 schema、簽核狀態、目前使用者可簽的區）──
case 'instance_load': {
    $iid = (int)($_GET['instance_id'] ?? 0);
    if (!$iid) jerr('缺 instance_id');
    $q = $db->prepare("SELECT i.*, t.name AS tpl_name, t.form_doc_id AS tpl_doc_id FROM as_form_instance i JOIN as_form_template t ON t.id=i.template_id WHERE i.id=? AND i.is_deleted=0");
    $q->execute([$iid]);
    $inst = $q->fetch(PDO::FETCH_ASSOC);
    if (!$inst) jerr('紀錄不存在', 404);
    // 凍結版 schema
    $v = $db->prepare("SELECT schema_json FROM as_form_template_version WHERE template_id=? AND version=?");
    $v->execute([(int)$inst['template_id'], (int)$inst['template_version']]);
    $schemaJson = $v->fetchColumn();
    if ($schemaJson === false) jerr('找不到凍結版 schema', 500);
    $schemaArr = json_decode($schemaJson, true) ?: [];
    // 簽核區狀態
    $a = $db->prepare("SELECT * FROM as_form_approval WHERE instance_id=? ORDER BY step_no, id");
    $a->execute([$iid]);
    $approvals = $a->fetchAll(PDO::FETCH_ASSOC);
    // 填寫值（規則解析 dept_manager 需要；簽名格顯示也用）
    $data = json_decode($inst['data_json'] ?: '{}', true) ?: [];
    // 目前 step（最小尚有 pending 的 step）；使用者可簽哪些區
    $curStep = null;
    foreach ($approvals as $ap) if ($ap['status']==='pending') { $curStep = (int)$ap['step_no']; break; }
    $mySections = [];
    // 送審人（規則解析需要）
    $subUid = 0;
    $su = $db->prepare("SELECT id FROM user WHERE user_cname=? LIMIT 1");
    $su->execute([$inst['created_by']]);
    $subUid = (int)($su->fetchColumn() ?: 0);
    foreach ($approvals as $ap) {
        if ($ap['status']!=='pending' || (int)$ap['step_no']!==$curStep) continue;
        $rule = json_decode($ap['approver_rule_json'] ?: '{}', true) ?: [];
        $eligible = asf_resolve_approvers($db, $rule, $subUid, $data);
        if (in_array($uid, $eligible, true) || $isAdmin) $mySections[] = ['approval_id'=>(int)$ap['id'], 'section_key'=>$ap['section_key'], 'section_label'=>$ap['section_label']];
    }
    foreach ($approvals as $ap) {
        if ($ap['status']==='approved') {
            $data['__sig_'.$ap['section_key']] = ['name'=>$ap['approver_name'], 'at'=>$ap['decided_at'] ? date('Y.m.d', strtotime($ap['decided_at'])) : ''];
        }
    }
    // ctx（表頭表尾即時＋使用者身分）
    $ctx = buildCtx($db, ['form_doc_id'=>$inst['tpl_doc_id'], 'published_version'=>$inst['template_version']]);
    $ctx['user'] = buildUserCtx($db, $uid, $cname);
    $canEdit = in_array($inst['status'], ['draft','rejected'], true) && ($inst['created_by']===$cname || $isAdmin);
    jout(['ok'=>true,
        'instance'=>['id'=>(int)$inst['id'], 'template_id'=>(int)$inst['template_id'], 'title'=>$inst['title'],
                     'status'=>$inst['status'], 'created_by'=>$inst['created_by'], 'created_at'=>$inst['created_at'],
                     'name'=>$inst['tpl_name']],
        'schema'=>$schemaArr, 'data'=>$data, 'ctx'=>$ctx,
        'approvals'=>array_map(fn($ap)=>['id'=>(int)$ap['id'],'section_key'=>$ap['section_key'],'section_label'=>$ap['section_label'],
                                          'step_no'=>(int)$ap['step_no'],'status'=>$ap['status'],'approver_name'=>$ap['approver_name'],
                                          'decided_at'=>$ap['decided_at'],'note'=>$ap['note']], $approvals),
        'my_sections'=>$mySections, 'can_edit'=>$canEdit]);
}

// ── 某模板的填寫紀錄清單 ──
case 'instance_list': {
    $tid = (int)($_GET['template_id'] ?? 0);
    if (!$tid) jerr('缺 template_id');
    $q = $db->prepare("SELECT id, title, status, created_by, created_at, updated_at FROM as_form_instance WHERE template_id=? AND is_deleted=0 ORDER BY id DESC LIMIT 200");
    $q->execute([$tid]);
    jout(['ok'=>true, 'rows'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── 送出：驗證必填 → 依 schema.sections 建簽核區 → submitter 區自動完成 → 通知第一關 ──
case 'instance_submit': {
    $iid = (int)($_POST['instance_id'] ?? 0);
    if (!$iid) jerr('缺 instance_id');
    $q = $db->prepare("SELECT i.*, t.name AS tpl_name FROM as_form_instance i JOIN as_form_template t ON t.id=i.template_id WHERE i.id=? AND i.is_deleted=0");
    $q->execute([$iid]);
    $inst = $q->fetch(PDO::FETCH_ASSOC);
    if (!$inst) jerr('紀錄不存在', 404);
    if ($inst['created_by'] !== $cname && !$isAdmin) jerr('僅填表本人可送出', 403);
    if (!in_array($inst['status'], ['draft','rejected'], true)) jerr('此紀錄已送出過');
    // 凍結版 schema
    $v = $db->prepare("SELECT schema_json FROM as_form_template_version WHERE template_id=? AND version=?");
    $v->execute([(int)$inst['template_id'], (int)$inst['template_version']]);
    $schemaArr = json_decode($v->fetchColumn() ?: '{}', true) ?: [];
    // 必填驗證（後端不信前端）
    $data = json_decode($inst['data_json'] ?: '{}', true) ?: [];
    $missing = [];
    foreach (($schemaArr['cells'] ?? []) as $cell) {
        if (($cell['type'] ?? '')==='field' && !empty($cell['required'])) {
            $k = $cell['key'] ?? '';
            if ($k === '') continue;
            $val = $data[$k] ?? null;
            // 勾選群組的值是陣列（至少勾一項才算有填）
            $empty = is_array($val) ? count($val)===0 : trim((string)$val)==='';
            if ($empty) $missing[] = $k;
        }
    }
    if ($missing) jerr('必填欄位未填：'.implode('、', $missing));
    // 格式規則驗證（編號等；設計器設定 cell.pattern＝regex，前後端都檢查）
    $badFmt = [];
    foreach (($schemaArr['cells'] ?? []) as $cell) {
        if (($cell['type'] ?? '')!=='field' || empty($cell['pattern'])) continue;
        $k = $cell['key'] ?? '';
        $val = $data[$k] ?? '';
        if ($k==='' || is_array($val) || trim((string)$val)==='') continue;   // 空值交給必填規則管
        $re = '/'.addcslashes((string)$cell['pattern'], '/').'/u';
        $m = @preg_match($re, (string)$val);
        if ($m === 0) $badFmt[] = $k.'（規則 '.$cell['pattern'].'）';
        // regex 本身無效（$m===false）不擋單，僅記錄
        if ($m === false) error_log('[AS_Form] invalid pattern for '.$k.': '.$cell['pattern']);
    }
    if ($badFmt) jerr('欄位格式不符：'.implode('、', $badFmt));
    $sections = $schemaArr['sections'] ?? [];
    if (empty($sections)) jerr('此表單未設定簽核區，無法送出簽核');
    usort($sections, fn($a2,$b2)=>($a2['step']??0)<=>($b2['step']??0));

    // 會簽來源欄位（cs_depts）：候選部門＋被勾選部門＋填表時順序
    // 會簽參與部門來源：優先「會簽區塊」(cs_block，部門在區塊上選定，全部參與)；否則相容舊「會簽部門勾選」欄(cs_depts，動態勾選)
    $csBlock = null; $csCell = null;
    foreach (($schemaArr['cells'] ?? []) as $cell) {
        if (($cell['type'] ?? '')==='cs_block' && $csBlock===null) $csBlock = $cell;
        if (($cell['ftype'] ?? '')==='cs_depts' && $csCell===null) $csCell = $cell;
    }
    $deptNames = [];
    try { foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $dn) $deptNames[(int)$dn['id']] = $dn['name']; } catch (Exception $e) {}

    $db->beginTransaction();
    try {
        // 重送（rejected）：清掉舊簽核列重建
        $db->prepare("DELETE FROM as_form_approval WHERE instance_id=?")->execute([$iid]);
        // submitter 區＝送出即自動完成（decided_at 用 DB NOW()，勿用 PHP date()——時區陷阱）
        $insDone = $db->prepare("INSERT INTO as_form_approval (instance_id, section_key, section_label, step_no, approver_rule_json, status, approver_id, approver_name, decided_at)
                                 VALUES (?,?,?,?,?,'approved',?,?,NOW())");
        $insPend = $db->prepare("INSERT INTO as_form_approval (instance_id, section_key, section_label, step_no, approver_rule_json, status)
                                 VALUES (?,?,?,?,?,'pending')");
        // step 一律 ×100 留空間給會簽排序（preset/filler 在同 step 內 +序號）
        foreach ($sections as $s) {
            $rule = $s['rule'] ?? [];
            $baseStep = (int)($s['step'] ?? 1) * 100;
            if (($rule['type'] ?? '')==='countersign') {
                // 參與部門：會簽區塊(cs_block)全部選定部門；或相容舊「會簽部門勾選」欄的被勾選部門
                $secKey = $s['key'] ?? 'cs';
                $ordered = [];
                if ($csBlock && ($csBlock['section'] ?? 'cs')===$secKey || $csBlock && !$csCell) {
                    // 啟用條件欄位（如「是否需會簽」）：值不含「是」→ 整個會簽區不建關卡
                    $enk = $csBlock['enable_key'] ?? '';
                    if ($enk !== '') {
                        $ev = $data[$enk] ?? '';
                        $enOn = is_array($ev) ? (in_array('是',$ev,true)||in_array('1',$ev,true))
                                              : ($ev==='是'||$ev==='1'||$ev===1||$ev===true);
                        if (!$enOn) continue;
                    }
                    // 會簽區塊：每列勾選(bk_use@dept)有勾的部門才建簽核關卡（沒勾＝只是不必填、不簽）
                    $bkc = $csBlock['key'] ?? 'cs';
                    foreach (($csBlock['dept_ids'] ?? []) as $idx=>$did) {
                        $uv = $data[$bkc.'_use@'.$did] ?? '';
                        $part = is_array($uv) ? count($uv)>0 : ($uv==='1' || $uv===1 || $uv===true);
                        if (!$part) continue;
                        $ordered[] = ['dept'=>(int)$did, 'seq'=>$idx];
                    }
                } elseif ($csCell) {
                    $ckRaw = $data[$csCell['key']] ?? [];
                    $checked = array_values(array_filter(array_map('intval', is_array($ckRaw) ? $ckRaw : explode(',', (string)$ckRaw))));
                    foreach (($csCell['dept_ids'] ?? []) as $idx=>$did) {
                        if (!in_array((int)$did, $checked, true)) continue;
                        $seq = $idx;
                        if (($rule['order'] ?? '')==='filler') {
                            $ov = $data[$csCell['key'].'_ord_'.$did] ?? '';
                            $seq = ($ov!=='' && is_numeric($ov)) ? (int)$ov : 999;
                        }
                        $ordered[] = ['dept'=>(int)$did, 'seq'=>$seq];
                    }
                } else {
                    throw new Exception('會簽簽核區需要表上有「會簽區塊」並選定部門');
                }
                usort($ordered, fn($x,$y)=>$x['seq']<=>$y['seq']);
                foreach ($ordered as $i2=>$od) {
                    $step2 = (($rule['order'] ?? 'parallel')==='parallel') ? $baseStep : $baseStep + $i2;
                    $ruleJson = json_encode(['type'=>'level', 'min_level'=>(int)($rule['min_level'] ?? 2),
                                             'dept_id'=>$od['dept'], 'disagree'=>$rule['disagree'] ?? 'continue'], JSON_UNESCAPED_UNICODE);
                    $insPend->execute([$iid, ($s['key'] ?? 'cs').'@'.$od['dept'],
                                       ($s['label'] ?? '會簽').'-'.($deptNames[$od['dept']] ?? $od['dept']),
                                       $step2, $ruleJson]);
                }
                continue;
            }
            $common = [$iid, $s['key'] ?? 'main', $s['label'] ?? '', $baseStep, json_encode($rule, JSON_UNESCAPED_UNICODE)];
            if (($rule['type'] ?? '')==='submitter') $insDone->execute([...$common, $uid, $cname]);
            else $insPend->execute($common);
        }
        $db->prepare("UPDATE as_form_instance SET status='in_review', updated_at=NOW() WHERE id=?")->execute([$iid]);
        $db->commit();
    } catch (Exception $e) { $db->rollBack(); jerr('送出失敗：'.$e->getMessage(), 500); }

    // 通知第一個 pending step 的所有區（通知失敗不影響送出）
    $a = $db->prepare("SELECT * FROM as_form_approval WHERE instance_id=? AND status='pending' ORDER BY step_no, id");
    $a->execute([$iid]);
    $pend = $a->fetchAll(PDO::FETCH_ASSOC);
    if ($pend) {
        $curStep = (int)$pend[0]['step_no'];
        foreach ($pend as $ap) {
            if ((int)$ap['step_no'] !== $curStep) break;
            $rule = json_decode($ap['approver_rule_json'] ?: '{}', true) ?: [];
            $appr = asf_resolve_approvers($db, $rule, $uid, $data);
            $le = asf_notify_sign($db, $iid, $inst['tpl_name'], $ap['section_label'] ?: $ap['section_key'], $appr, $uid, $cname);
            if ($le) $db->prepare("UPDATE as_form_approval SET live_event_id=? WHERE id=?")->execute([$le, (int)$ap['id']]);
        }
    } else {
        // 全部區都是 submitter → 直接完成
        $db->prepare("UPDATE as_form_instance SET status='approved', updated_at=NOW() WHERE id=?")->execute([$iid]);
    }
    jout(['ok'=>true]);
}

// ── 簽核（核准/駁回一個簽核區）──
case 'decide': {
    $aid = (int)($_POST['approval_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $note = trim($_POST['note'] ?? '') ?: null;
    if (!$aid) jerr('缺 approval_id');
    if (!in_array($decision, ['approved','rejected'], true)) jerr('無效決定');
    if ($decision==='rejected' && !$note) jerr('駁回必須填寫原因');

    $q = $db->prepare("SELECT a.*, i.created_by AS inst_creator, i.template_id, i.template_version, i.data_json, i.status AS inst_status, t.name AS tpl_name
                       FROM as_form_approval a JOIN as_form_instance i ON i.id=a.instance_id
                       JOIN as_form_template t ON t.id=i.template_id WHERE a.id=?");
    $q->execute([$aid]);
    $ap = $q->fetch(PDO::FETCH_ASSOC);
    if (!$ap) jerr('簽核紀錄不存在', 404);
    if ($ap['status'] !== 'pending') jerr('此區已被 '.($ap['approver_name'] ?: '其他人').' 處理過');
    if ($ap['inst_status'] !== 'in_review') jerr('此表單已'.($ap['inst_status']==='rejected'?'被駁回':'結案').'，不可再簽');
    $iid = (int)$ap['instance_id'];

    // 填寫值（資格解析 dept_manager／會簽合併都要用）
    $dataArr = json_decode($ap['data_json'] ?: '{}', true) ?: [];
    // 資格：解析規則（管理員恆可）
    $su = $db->prepare("SELECT id FROM user WHERE user_cname=? LIMIT 1");
    $su->execute([$ap['inst_creator']]);
    $subUid = (int)($su->fetchColumn() ?: 0);
    $rule = json_decode($ap['approver_rule_json'] ?: '{}', true) ?: [];
    $eligible = asf_resolve_approvers($db, $rule, $subUid, $dataArr);
    if (!in_array($uid, $eligible, true) && !$isAdmin) jerr('您不具此區簽核資格', 403);
    // 順序：前面 step 還有 pending 不可先簽
    $pmin = $db->prepare("SELECT MIN(step_no) FROM as_form_approval WHERE instance_id=? AND status='pending'");
    $pmin->execute([$iid]);
    if ((int)$pmin->fetchColumn() < (int)$ap['step_no']) jerr('前面關卡尚未完成，還不能簽此區');

    // ── 會簽區：收簽核人填寫的區塊欄位（同意/不同意＋意見等）──
    $sectionDept = 0;
    if (strpos($ap['section_key'], '@') !== false) $sectionDept = (int)substr($ap['section_key'], strpos($ap['section_key'], '@') + 1);
    $mergedCs = false;
    if ($sectionDept) {
        $sd = json_decode($_POST['section_data'] ?? '{}', true);
        $sd = is_array($sd) ? $sd : [];
        // 凍結版 schema：只收「屬於此會簽部門」的欄位（防越權寫入他區）
        $vq = $db->prepare("SELECT schema_json FROM as_form_template_version WHERE template_id=? AND version=?");
        $vq->execute([(int)$ap['template_id'], (int)$ap['template_version']]);
        $schemaCs = json_decode($vq->fetchColumn() ?: '{}', true) ?: [];
        $allowed = []; $requiredKeys = []; $decisionKey = null;
        foreach (($schemaCs['cells'] ?? []) as $cell) {
            // 會簽區塊（cs_block）：每部門一組 <key>_dec@<dept>／<key>_note@<dept>
            if (($cell['type'] ?? '')==='cs_block') {
                $bk = ($cell['key'] ?? '') ?: 'cs';
                if (($cell['show_dec'] ?? true) !== false) {
                    $k = $bk.'_dec@'.$sectionDept;
                    $allowed[$k] = true; $decisionKey = $k;
                    if (!empty($cell['dec_required'])) $requiredKeys[] = $k;
                }
                if (($cell['show_note'] ?? true) !== false) {
                    $k = $bk.'_note@'.$sectionDept;
                    $allowed[$k] = true;
                    if (!empty($cell['note_required'])) $requiredKeys[] = $k;
                }
                continue;
            }
            // 舊式：個別欄位標 cs_dept
            if (($cell['type'] ?? '')!=='field' || (int)($cell['cs_dept'] ?? 0)!==$sectionDept) continue;
            $k = $cell['key'] ?? ''; if ($k==='') continue;
            $allowed[$k] = true;
            if (!empty($cell['required'])) $requiredKeys[] = $k;
            if (($cell['ftype'] ?? '')==='cs_decision') $decisionKey = $k;
        }
        foreach ($sd as $k=>$v) { if (isset($allowed[$k])) { $dataArr[$k] = $v; $mergedCs = true; } }
        if ($decision === 'approved') {
            // 內容必填檢查（設計器設 required 的會簽欄位）
            $miss = [];
            foreach ($requiredKeys as $k) {
                $v = $dataArr[$k] ?? '';
                if (is_array($v) ? count($v)===0 : trim((string)$v)==='') $miss[] = $k;
            }
            if ($miss) jerr('會簽必填欄位未填：'.implode('、', $miss));
            // 不同意效果（每張表單可設）：return=退回填表人（轉為駁回）；continue=記錄意見繼續流程
            $ruleCs = json_decode($ap['approver_rule_json'] ?: '{}', true) ?: [];
            if ($decisionKey && trim((string)($dataArr[$decisionKey] ?? ''))==='不同意' && ($ruleCs['disagree'] ?? 'continue')==='return') {
                $decision = 'rejected';
                if (!$note) $note = '會簽不同意（'.($ap['section_label'] ?: $ap['section_key']).'）';
            }
        }
    }

    $db->beginTransaction();
    try {
        // 搶簽保護
        $upd = $db->prepare("UPDATE as_form_approval SET status=?, approver_id=?, approver_name=?, note=?, decided_at=NOW() WHERE id=? AND status='pending'");
        $upd->execute([$decision, $uid, $cname, $note, $aid]);
        if ($upd->rowCount()===0) { $db->rollBack(); jerr('此區剛被其他人處理，請重新整理'); }
        // 會簽欄位值併入填寫資料
        if ($mergedCs) {
            $db->prepare("UPDATE as_form_instance SET data_json=?, updated_at=NOW() WHERE id=?")
               ->execute([json_encode($dataArr, JSON_UNESCAPED_UNICODE), $iid]);
        }
        if ($decision==='rejected') {
            $db->prepare("UPDATE as_form_instance SET status='rejected', updated_at=NOW() WHERE id=?")->execute([$iid]);
        } else {
            // 全部完成？
            $left = $db->prepare("SELECT COUNT(*) FROM as_form_approval WHERE instance_id=? AND status='pending'");
            $left->execute([$iid]);
            if ((int)$left->fetchColumn()===0) {
                $db->prepare("UPDATE as_form_instance SET status='approved', updated_at=NOW() WHERE id=?")->execute([$iid]);
            }
        }
        $db->commit();
    } catch (Exception $e) { $db->rollBack(); jerr('簽核失敗：'.$e->getMessage(), 500); }

    // 通知（失敗不影響簽核）
    asf_close_sign_notice($db, (int)$ap['live_event_id'], $uid);
    if ($decision==='rejected') {
        asf_notify_result($db, $iid, $ap['tpl_name'], $subUid, $cname, 'rejected', $note);
    } else {
        // 下一關還有 pending → 發下一關通知；全完成 → 通知填表人
        $a2 = $db->prepare("SELECT * FROM as_form_approval WHERE instance_id=? AND status='pending' ORDER BY step_no, id");
        $a2->execute([$iid]);
        $pend = $a2->fetchAll(PDO::FETCH_ASSOC);
        if ($pend) {
            $curStep = (int)$pend[0]['step_no'];
            foreach ($pend as $nx) {
                if ((int)$nx['step_no'] !== $curStep) break;
                if (!empty($nx['live_event_id'])) continue;   // 已通知過（平行區）
                $rule2 = json_decode($nx['approver_rule_json'] ?: '{}', true) ?: [];
                $appr2 = asf_resolve_approvers($db, $rule2, $subUid, $dataArr);
                $le2 = asf_notify_sign($db, $iid, $ap['tpl_name'], $nx['section_label'] ?: $nx['section_key'], $appr2, $subUid, $ap['inst_creator']);
                if ($le2) $db->prepare("UPDATE as_form_approval SET live_event_id=? WHERE id=?")->execute([$le2, (int)$nx['id']]);
            }
        } else {
            asf_notify_result($db, $iid, $ap['tpl_name'], $subUid, $cname, 'approved', null);
        }
    }
    jout(['ok'=>true]);
}

default:
    jerr('未知 action: '.$action);
}
} catch (Exception $e) {
    jerr('伺服器錯誤：'.$e->getMessage(), 500);
}
