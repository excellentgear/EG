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
    $docNo = ''; $version = $tpl['published_version'] ? ('Ver.'.$tpl['published_version']) : '';
    if (!empty($tpl['form_doc_id'])) {
        $d = $db->prepare("SELECT doc_no, current_version FROM as_document WHERE id=?");
        $d->execute([$tpl['form_doc_id']]);
        if ($dr = $d->fetch(PDO::FETCH_ASSOC)) {
            $docNo = $dr['doc_no'] ?? '';
            if (!empty($dr['current_version'])) $version = $dr['current_version'];
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

default:
    jerr('未知 action: '.$action);
}
} catch (Exception $e) {
    jerr('伺服器錯誤：'.$e->getMessage(), 500);
}
