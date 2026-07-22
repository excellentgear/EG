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
    return ['company'=>$company, 'docNo'=>$docNo, 'version'=>$version];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
switch ($action) {

// ── 列出模板（可選 form_doc_id 篩選）──
case 'list': {
    $where = "is_deleted=0";
    $args = [];
    if (!empty($_GET['form_doc_id'])) { $where .= " AND form_doc_id=?"; $args[] = (int)$_GET['form_doc_id']; }
    $q = $db->prepare("SELECT id, form_doc_id, name, status, published_version, updated_at FROM as_form_template WHERE $where ORDER BY updated_at DESC, id DESC");
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
    jout(['ok'=>true, 'template'=>[
        'id'=>(int)$tpl['id'], 'form_doc_id'=>$tpl['form_doc_id'], 'name'=>$tpl['name'],
        'status'=>$tpl['status'], 'published_version'=>(int)$tpl['published_version'],
    ], 'schema'=>json_decode($tpl['current_schema'] ?: '{}'), 'ctx'=>buildCtx($db, $tpl),
       'canDesign'=>$canDesign]);
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
    jout(['ok'=>true]);
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
        jout(['ok'=>true, 'version'=>$newVer]);
    } catch (Exception $e) { $db->rollBack(); jerr('發布失敗：'.$e->getMessage(), 500); }
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
        $eligible = asf_resolve_approvers($db, $rule, $subUid);
        if (in_array($uid, $eligible, true) || $isAdmin) $mySections[] = ['approval_id'=>(int)$ap['id'], 'section_key'=>$ap['section_key'], 'section_label'=>$ap['section_label']];
    }
    // 簽名格顯示資料（__sig_<section>）
    $data = json_decode($inst['data_json'] ?: '{}', true) ?: [];
    foreach ($approvals as $ap) {
        if ($ap['status']==='approved') {
            $data['__sig_'.$ap['section_key']] = ['name'=>$ap['approver_name'], 'at'=>$ap['decided_at'] ? date('Y.m.d', strtotime($ap['decided_at'])) : ''];
        }
    }
    // ctx（表頭表尾即時）
    $ctx = buildCtx($db, ['form_doc_id'=>$inst['tpl_doc_id'], 'published_version'=>$inst['template_version']]);
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
            if ($k !== '' && (!isset($data[$k]) || trim((string)$data[$k])==='')) $missing[] = $k;
        }
    }
    if ($missing) jerr('必填欄位未填：'.implode('、', $missing));
    $sections = $schemaArr['sections'] ?? [];
    if (empty($sections)) jerr('此表單未設定簽核區，無法送出簽核');
    usort($sections, fn($a2,$b2)=>($a2['step']??0)<=>($b2['step']??0));

    $db->beginTransaction();
    try {
        // 重送（rejected）：清掉舊簽核列重建
        $db->prepare("DELETE FROM as_form_approval WHERE instance_id=?")->execute([$iid]);
        // submitter 區＝送出即自動完成（decided_at 用 DB NOW()，勿用 PHP date()——時區陷阱）
        $insDone = $db->prepare("INSERT INTO as_form_approval (instance_id, section_key, section_label, step_no, approver_rule_json, status, approver_id, approver_name, decided_at)
                                 VALUES (?,?,?,?,?,'approved',?,?,NOW())");
        $insPend = $db->prepare("INSERT INTO as_form_approval (instance_id, section_key, section_label, step_no, approver_rule_json, status)
                                 VALUES (?,?,?,?,?,'pending')");
        foreach ($sections as $s) {
            $rule = $s['rule'] ?? [];
            $common = [$iid, $s['key'] ?? 'main', $s['label'] ?? '', (int)($s['step'] ?? 1), json_encode($rule, JSON_UNESCAPED_UNICODE)];
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
            $appr = asf_resolve_approvers($db, $rule, $uid);
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

    $q = $db->prepare("SELECT a.*, i.created_by AS inst_creator, i.template_id, t.name AS tpl_name
                       FROM as_form_approval a JOIN as_form_instance i ON i.id=a.instance_id
                       JOIN as_form_template t ON t.id=i.template_id WHERE a.id=?");
    $q->execute([$aid]);
    $ap = $q->fetch(PDO::FETCH_ASSOC);
    if (!$ap) jerr('簽核紀錄不存在', 404);
    if ($ap['status'] !== 'pending') jerr('此區已被 '.($ap['approver_name'] ?: '其他人').' 處理過');
    $iid = (int)$ap['instance_id'];

    // 資格：解析規則（管理員恆可）
    $su = $db->prepare("SELECT id FROM user WHERE user_cname=? LIMIT 1");
    $su->execute([$ap['inst_creator']]);
    $subUid = (int)($su->fetchColumn() ?: 0);
    $rule = json_decode($ap['approver_rule_json'] ?: '{}', true) ?: [];
    $eligible = asf_resolve_approvers($db, $rule, $subUid);
    if (!in_array($uid, $eligible, true) && !$isAdmin) jerr('您不具此區簽核資格', 403);
    // 順序：前面 step 還有 pending 不可先簽
    $pmin = $db->prepare("SELECT MIN(step_no) FROM as_form_approval WHERE instance_id=? AND status='pending'");
    $pmin->execute([$iid]);
    if ((int)$pmin->fetchColumn() < (int)$ap['step_no']) jerr('前面關卡尚未完成，還不能簽此區');

    $db->beginTransaction();
    try {
        // 搶簽保護
        $upd = $db->prepare("UPDATE as_form_approval SET status=?, approver_id=?, approver_name=?, note=?, decided_at=NOW() WHERE id=? AND status='pending'");
        $upd->execute([$decision, $uid, $cname, $note, $aid]);
        if ($upd->rowCount()===0) { $db->rollBack(); jerr('此區剛被其他人處理，請重新整理'); }
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
                $appr2 = asf_resolve_approvers($db, $rule2, $subUid);
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
