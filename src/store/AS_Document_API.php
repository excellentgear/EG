<?php
/**
 * AS9100 文件管理 API
 * 路徑合規（CLAUDE.md 鐵律5）：DB 只存 file_name，完整路徑一律於讀取時以
 *   system_settings.as_doc_nas_dir（根）＋ 子資料夾（docs/{doc_id}）現場組出。
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

$db_connection = new DBConnection();
$db = $db_connection->getPDO();

$currentUserName = $_SESSION['userName'] ?? '';
$currentUserId   = 0;
if ($currentUserName !== '') {
    $st = $db->prepare("SELECT id FROM user WHERE user_uname = ?");
    $st->execute([$currentUserName]);
    $currentUserId = (int)($st->fetchColumn() ?: 0);
}
$currentCname = '';
if ($currentUserId) {
    $st = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
    $st->execute([$currentUserId]);
    $currentCname = (string)($st->fetchColumn() ?: $currentUserName);
}

// ── 共用工具 ───────────────────────────────────────────────────────
function jout($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

function asGetSetting(PDO $db, string $key): string {
    try {
        $s = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v !== false && $v !== null) ? trim($v) : '';
    } catch (Exception $e) { return ''; }
}
function asSetSetting(PDO $db, string $key, string $val): void {
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
       ->execute([$key, $val]);
}
/** NAS 根路徑（去尾斜線）。此為唯一存 DB 的路徑資訊，其餘一律現場組。 */
function asDocRoot(PDO $db): string {
    return rtrim(asGetSetting($db, 'as_doc_nas_dir'), "/\\");
}
/** 某文件的實體資料夾＝根 / docs / {doc_id}（doc_id 為不可變主鍵，符合路徑規範） */
function asDocDir(PDO $db, int $docId): string {
    return asDocRoot($db) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . $docId;
}
function asTplDir(PDO $db): string {
    return asDocRoot($db) . DIRECTORY_SEPARATOR . '_template';
}
function asSafeExt(string $orig): ?string {
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $blocked = ['php','php3','php4','php5','phtml','phar','exe','bat','sh','cmd','asp','aspx','jsp','py','rb','htaccess'];
    if ($ext === '' || in_array($ext, $blocked)) return null;
    return $ext;
}
function asMakeName(string $ext): string {
    return date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
}
/** 串流下載一個實體檔案 */
function asStream(string $fullpath, string $downloadName): void {
    if (!is_file($fullpath)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo '檔案不存在或 NAS 未連線'; exit; }
    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . filesize($fullpath));
    readfile($fullpath);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
switch ($action) {

// ══════════════ 標籤 / 分類 ══════════════
case 'list_tags':
    $rows = $db->query("SELECT id,name,color,sort_order FROM as_doc_tag ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    jout(['status'=>'success','data'=>$rows]);

case 'add_tag':
    $name = trim($_POST['name'] ?? '');
    $color = trim($_POST['color'] ?? '#1ABB9C');
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($name === '') jout(['status'=>'error','message'=>'標籤名稱不可為空']);
    $db->prepare("INSERT INTO as_doc_tag (name,color,sort_order) VALUES (?,?,?)")->execute([$name,$color,$sort]);
    jout(['status'=>'success','id'=>(int)$db->lastInsertId()]);

case 'update_tag':
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $color = trim($_POST['color'] ?? '#1ABB9C');
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($id<=0 || $name==='') jout(['status'=>'error','message'=>'資料不完整']);
    $db->prepare("UPDATE as_doc_tag SET name=?,color=?,sort_order=? WHERE id=?")->execute([$name,$color,$sort,$id]);
    jout(['status'=>'success']);

case 'delete_tag':
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) jout(['status'=>'error','message'=>'無效 ID']);
    $db->prepare("DELETE FROM as_doc_tag_map WHERE tag_id=?")->execute([$id]);
    $db->prepare("DELETE FROM as_doc_tag WHERE id=?")->execute([$id]);
    jout(['status'=>'success']);

// ══════════════ 下拉選單資料 ══════════════
case 'meta':
    $depts = $db->query("SELECT id,name,level FROM department ORDER BY sort_order ASC, level, name")->fetchAll(PDO::FETCH_ASSOC);
    $poss  = $db->query("SELECT p.id,p.name,pl.level FROM position p LEFT JOIN position_level pl ON p.id=pl.position_id ORDER BY p.sort_order ASC, p.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $tags  = $db->query("SELECT id,name,color FROM as_doc_tag ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $users = $db->query("SELECT id,user_cname FROM user WHERE state IN (1,90,99) OR state IS NULL ORDER BY user_cname")->fetchAll(PDO::FETCH_ASSOC);
    jout(['status'=>'success','departments'=>$depts,'positions'=>$poss,'tags'=>$tags,'users'=>$users]);

// ══════════════ 文件清單（搜尋 / 篩選） ══════════════
case 'list_documents':
    $kw    = trim($_GET['keyword'] ?? '');
    $level = trim($_GET['level'] ?? '');
    $dept  = trim($_GET['department_id'] ?? '');
    $tag   = (int)($_GET['tag_id'] ?? 0);
    $incDeleted = ($_GET['include_deleted'] ?? '0') === '1';

    $where = [];
    $params = [];
    if (!$incDeleted) $where[] = "d.is_deleted = 0";
    if ($kw !== '')   { $where[] = "(d.doc_no LIKE ? OR d.doc_name LIKE ?)"; $params[]="%$kw%"; $params[]="%$kw%"; }
    if ($level!=='')  { $where[] = "d.doc_level = ?"; $params[]=$level; }
    if ($dept!=='')   { $where[] = "d.department_id = ?"; $params[]=(int)$dept; }
    if ($tag>0)       { $where[] = "d.id IN (SELECT doc_id FROM as_doc_tag_map WHERE tag_id = ?)"; $params[]=$tag; }
    $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

    $sql = "SELECT d.*, dep.name AS dept_name,
                   v.revised_date, v.change_status
            FROM as_document d
            LEFT JOIN department dep ON dep.id = d.department_id
            LEFT JOIN as_document_version v ON v.id = d.current_version_id
            $wsql
            ORDER BY d.doc_level, dep.name, d.doc_no";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 附上標籤
    if ($docs) {
        $ids = array_column($docs,'id');
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $tm = $db->prepare("SELECT m.doc_id, t.id, t.name, t.color FROM as_doc_tag_map m JOIN as_doc_tag t ON t.id=m.tag_id WHERE m.doc_id IN ($ph) ORDER BY t.sort_order");
        $tm->execute($ids);
        $byDoc = [];
        foreach ($tm->fetchAll(PDO::FETCH_ASSOC) as $r) { $byDoc[$r['doc_id']][] = $r; }
        foreach ($docs as &$d) { $d['tags'] = $byDoc[$d['id']] ?? []; }
    }
    jout(['status'=>'success','data'=>$docs]);

// ══════════════ 單一文件明細（含版本 / 標籤 / 權限） ══════════════
case 'get_document':
    $id = (int)($_GET['id'] ?? 0);
    if ($id<=0) jout(['status'=>'error','message'=>'無效 ID']);
    $st = $db->prepare("SELECT d.*, dep.name AS dept_name FROM as_document d LEFT JOIN department dep ON dep.id=d.department_id WHERE d.id=?");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    $vs = $db->prepare("SELECT v.*, dep.name AS dept_name_snapshot FROM as_document_version v LEFT JOIN department dep ON dep.id=v.department_id_snapshot WHERE v.doc_id=? ORDER BY v.id DESC");
    $vs->execute([$id]);
    $doc['versions'] = $vs->fetchAll(PDO::FETCH_ASSOC);

    $tg = $db->prepare("SELECT t.id,t.name,t.color FROM as_doc_tag_map m JOIN as_doc_tag t ON t.id=m.tag_id WHERE m.doc_id=? ORDER BY t.sort_order");
    $tg->execute([$id]);
    $doc['tags'] = $tg->fetchAll(PDO::FETCH_ASSOC);

    jout(['status'=>'success','data'=>$doc]);

// ══════════════ 新增文件（首版） ══════════════
case 'create_document':
    $doc_no  = trim($_POST['doc_no'] ?? '');
    $doc_name= trim($_POST['doc_name'] ?? '');
    $doc_type= trim($_POST['doc_type'] ?? '');
    $level   = trim($_POST['doc_level'] ?? '');
    $dept    = ($_POST['department_id'] ?? '')!=='' ? (int)$_POST['department_id'] : null;
    $version = trim($_POST['version'] ?? '');
    $rdate   = trim($_POST['revised_date'] ?? '') ?: null;
    $rpages  = trim($_POST['revised_pages'] ?? '') ?: null;
    $rsum    = trim($_POST['revised_summary'] ?? '') ?: null;
    $cstat   = trim($_POST['change_status'] ?? '制訂');
    $tagIds  = array_filter(array_map('intval', explode(',', $_POST['tag_ids'] ?? '')));

    if ($doc_no==='' || $doc_name==='' || $version==='')
        jout(['status'=>'error','message'=>'文件編號、名稱、版本號為必填']);
    if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK)
        jout(['status'=>'error','message'=>'請上傳文件檔']);
    $ext = asSafeExt($_FILES['file']['name']);
    if (!$ext) jout(['status'=>'error','message'=>'不允許此文件類型']);
    if (asDocRoot($db)==='') jout(['status'=>'error','message'=>'尚未設定 NAS 儲存路徑（請至系統設定）']);

    // 申請單（首版可選）
    $hasApply = isset($_FILES['apply_form']) && $_FILES['apply_form']['error']===UPLOAD_ERR_OK;
    $applyExt = null;
    if ($hasApply) { $applyExt = asSafeExt($_FILES['apply_form']['name']); if(!$applyExt) jout(['status'=>'error','message'=>'申請單檔案類型不允許']); }

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,department_id,current_version,created_by,created_at,updated_at)
                      VALUES (?,?,?,?,?,?,?,NOW(),NOW())")
           ->execute([$doc_no,$doc_name,$doc_type,$level,$dept,$version,$GLOBALS['currentCname']]);
        $docId = (int)$db->lastInsertId();

        $dir = asDocDir($db, $docId);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) throw new Exception('無法建立資料夾（NAS 未連線？）');

        $fname = asMakeName($ext);
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception('文件寫入失敗');
        $orig = basename($_FILES['file']['name']);

        $applyName = null; $applyOrig = null;
        if ($hasApply) {
            $applyName = asMakeName($applyExt);
            if (!move_uploaded_file($_FILES['apply_form']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$applyName)) throw new Exception('申請單寫入失敗');
            $applyOrig = basename($_FILES['apply_form']['name']);
        }

        $db->prepare("INSERT INTO as_document_version
              (doc_id,version,change_status,revised_date,revised_pages,revised_summary,doc_level_snapshot,department_id_snapshot,file_name,original_name,apply_form_file_name,apply_form_original_name,uploaded_by,uploaded_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
           ->execute([$docId,$version,$cstat,$rdate,$rpages,$rsum,$level,$dept,$fname,$orig,$applyName,$applyOrig,$GLOBALS['currentCname']]);
        $verId = (int)$db->lastInsertId();

        $db->prepare("UPDATE as_document SET current_version_id=? WHERE id=?")->execute([$verId,$docId]);

        if ($tagIds) {
            $ins = $db->prepare("INSERT IGNORE INTO as_doc_tag_map (doc_id,tag_id) VALUES (?,?)");
            foreach ($tagIds as $tid) $ins->execute([$docId,$tid]);
        }
        $db->commit();
        jout(['status'=>'success','id'=>$docId]);
    } catch (Exception $e) {
        $db->rollBack();
        jout(['status'=>'error','message'=>$e->getMessage()]);
    }

// ══════════════ 改版（新版本，舊版保留） ══════════════
case 'add_version':
    $docId  = (int)($_POST['doc_id'] ?? 0);
    $version= trim($_POST['version'] ?? '');
    $rdate  = trim($_POST['revised_date'] ?? '') ?: null;
    $rpages = trim($_POST['revised_pages'] ?? '') ?: null;
    $rsum   = trim($_POST['revised_summary'] ?? '') ?: null;
    $cstat  = trim($_POST['change_status'] ?? '修正');
    if ($docId<=0 || $version==='') jout(['status'=>'error','message'=>'文件與版本號為必填']);

    $st = $db->prepare("SELECT * FROM as_document WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK)
        jout(['status'=>'error','message'=>'請上傳新版文件檔']);
    $ext = asSafeExt($_FILES['file']['name']);
    if (!$ext) jout(['status'=>'error','message'=>'不允許此文件類型']);

    // 改版一律需附「文件制修申請單(附件一)」
    if (!isset($_FILES['apply_form']) || $_FILES['apply_form']['error']!==UPLOAD_ERR_OK)
        jout(['status'=>'error','message'=>'改版必須一併上傳「文件制修申請單」(附件一)']);
    $applyExt = asSafeExt($_FILES['apply_form']['name']);
    if (!$applyExt) jout(['status'=>'error','message'=>'申請單檔案類型不允許']);

    $db->beginTransaction();
    try {
        $dir = asDocDir($db, $docId);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) throw new Exception('無法建立資料夾（NAS 未連線？）');

        $fname = asMakeName($ext);
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception('文件寫入失敗');
        $orig = basename($_FILES['file']['name']);

        $applyName = asMakeName($applyExt);
        if (!move_uploaded_file($_FILES['apply_form']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$applyName)) throw new Exception('申請單寫入失敗');
        $applyOrig = basename($_FILES['apply_form']['name']);

        // 舊版快照沿用主檔當下的階級/部門
        $db->prepare("INSERT INTO as_document_version
              (doc_id,version,change_status,revised_date,revised_pages,revised_summary,doc_level_snapshot,department_id_snapshot,file_name,original_name,apply_form_file_name,apply_form_original_name,uploaded_by,uploaded_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
           ->execute([$docId,$version,$cstat,$rdate,$rpages,$rsum,$doc['doc_level'],$doc['department_id'],$fname,$orig,$applyName,$applyOrig,$GLOBALS['currentCname']]);
        $verId = (int)$db->lastInsertId();

        $db->prepare("UPDATE as_document SET current_version=?, current_version_id=?, updated_at=NOW() WHERE id=?")
           ->execute([$version,$verId,$docId]);
        $db->commit();
        jout(['status'=>'success','version_id'=>$verId]);
    } catch (Exception $e) {
        $db->rollBack();
        jout(['status'=>'error','message'=>$e->getMessage()]);
    }

// ══════════════ 編輯文件基本資料（不改版） ══════════════
case 'update_document_meta':
    $id = (int)($_POST['id'] ?? 0);
    $doc_no  = trim($_POST['doc_no'] ?? '');
    $doc_name= trim($_POST['doc_name'] ?? '');
    $doc_type= trim($_POST['doc_type'] ?? '');
    $level   = trim($_POST['doc_level'] ?? '');
    $dept    = ($_POST['department_id'] ?? '')!=='' ? (int)$_POST['department_id'] : null;
    $tagIds  = array_filter(array_map('intval', explode(',', $_POST['tag_ids'] ?? '')));
    if ($id<=0 || $doc_no==='' || $doc_name==='') jout(['status'=>'error','message'=>'資料不完整']);
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE as_document SET doc_no=?,doc_name=?,doc_type=?,doc_level=?,department_id=?,updated_at=NOW() WHERE id=?")
           ->execute([$doc_no,$doc_name,$doc_type,$level,$dept,$id]);
        $db->prepare("DELETE FROM as_doc_tag_map WHERE doc_id=?")->execute([$id]);
        if ($tagIds) { $ins=$db->prepare("INSERT IGNORE INTO as_doc_tag_map (doc_id,tag_id) VALUES (?,?)"); foreach($tagIds as $t) $ins->execute([$id,$t]); }
        $db->commit();
        jout(['status'=>'success']);
    } catch (Exception $e) { $db->rollBack(); jout(['status'=>'error','message'=>$e->getMessage()]); }

// ══════════════ 刪除文件（主檔軟刪除；版本與檔案仍留存可查） ══════════════
case 'delete_document':
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) jout(['status'=>'error','message'=>'無效 ID']);
    $db->prepare("UPDATE as_document SET is_deleted=1, updated_at=NOW() WHERE id=?")->execute([$id]);
    jout(['status'=>'success']);

case 'restore_document':
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) jout(['status'=>'error','message'=>'無效 ID']);
    $db->prepare("UPDATE as_document SET is_deleted=0, updated_at=NOW() WHERE id=?")->execute([$id]);
    jout(['status'=>'success']);

// ══════════════ 下載：某版本文件 / 申請單 / 目前版 ══════════════
case 'download':
    $verId = (int)($_GET['version_id'] ?? 0);
    $which = $_GET['which'] ?? 'file'; // file | apply
    if ($verId<=0) { http_response_code(400); exit('bad request'); }
    $st = $db->prepare("SELECT * FROM as_document_version WHERE id=?");
    $st->execute([$verId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) { http_response_code(404); exit('版本不存在'); }
    $dir = asDocDir($db, (int)$v['doc_id']);
    if ($which==='apply') {
        if (!$v['apply_form_file_name']) { http_response_code(404); exit('此版本無申請單'); }
        asStream($dir.DIRECTORY_SEPARATOR.$v['apply_form_file_name'], $v['apply_form_original_name'] ?: $v['apply_form_file_name']);
    } else {
        asStream($dir.DIRECTORY_SEPARATOR.$v['file_name'], $v['original_name'] ?: $v['file_name']);
    }
    break;

// ══════════════ 權限規則 ══════════════
case 'get_perms':
    $docId = (int)($_GET['doc_id'] ?? 0);
    if ($docId<=0) jout(['status'=>'error','message'=>'無效 ID']);
    $st = $db->prepare("SELECT p.*, dep.name AS dept_name, pos.name AS position_name
                        FROM as_doc_perm p
                        LEFT JOIN department dep ON dep.id=p.department_id
                        LEFT JOIN position pos ON pos.id=p.position_id
                        WHERE p.doc_id=? ORDER BY p.id");
    $st->execute([$docId]);
    jout(['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);

case 'save_perms':
    $docId = (int)($_POST['doc_id'] ?? 0);
    $rows  = json_decode($_POST['rows'] ?? '[]', true);
    if ($docId<=0) jout(['status'=>'error','message'=>'無效 ID']);
    if (!is_array($rows)) $rows = [];
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM as_doc_perm WHERE doc_id=?")->execute([$docId]);
        $ins = $db->prepare("INSERT INTO as_doc_perm (doc_id,perm_type,department_id,position_id,min_level,can_read,can_download,can_update,can_delete)
                             VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $r) {
            $ptype = ($r['perm_type'] ?? 'position')==='level' ? 'level' : 'position';
            $dep   = ($r['department_id'] ?? '')!=='' ? (int)$r['department_id'] : null;
            $pos   = ($ptype==='position' && ($r['position_id'] ?? '')!=='') ? (int)$r['position_id'] : null;
            $minl  = ($ptype==='level' && ($r['min_level'] ?? '')!=='') ? (int)$r['min_level'] : null;
            $ins->execute([$docId,$ptype,$dep,$pos,$minl,
                !empty($r['can_read'])?1:0, !empty($r['can_download'])?1:0,
                !empty($r['can_update'])?1:0, !empty($r['can_delete'])?1:0]);
        }
        $db->commit();
        jout(['status'=>'success']);
    } catch (Exception $e) { $db->rollBack(); jout(['status'=>'error','message'=>$e->getMessage()]); }

// ══════════════ 系統設定（NAS 路徑 / AS 負責人 / 代理人 / 申請單範本） ══════════════
case 'get_settings':
    $ownerId  = asGetSetting($db,'as_doc_owner_user_id');
    $deputyId = asGetSetting($db,'as_doc_deputy_user_id');
    $names = [];
    foreach ([$ownerId,$deputyId] as $uid) {
        if ($uid!=='') {
            $s=$db->prepare("SELECT user_cname FROM user WHERE id=?"); $s->execute([(int)$uid]);
            $names[$uid]=$s->fetchColumn() ?: '';
        }
    }
    jout(['status'=>'success','data'=>[
        'nas_dir'=>asGetSetting($db,'as_doc_nas_dir'),
        'owner_user_id'=>$ownerId, 'owner_name'=>$ownerId!==''?($names[$ownerId]??''):'',
        'deputy_user_id'=>$deputyId, 'deputy_name'=>$deputyId!==''?($names[$deputyId]??''):'',
        'apply_form_tpl'=>asGetSetting($db,'as_doc_apply_form_tpl'),
    ]]);

case 'save_settings':
    $nas    = trim($_POST['nas_dir'] ?? '');
    $owner  = trim($_POST['owner_user_id'] ?? '');
    $deputy = trim($_POST['deputy_user_id'] ?? '');
    if ($nas!=='') asSetSetting($db,'as_doc_nas_dir',$nas);
    asSetSetting($db,'as_doc_owner_user_id',$owner);
    asSetSetting($db,'as_doc_deputy_user_id',$deputy);
    jout(['status'=>'success']);

case 'upload_template':
    if (asDocRoot($db)==='') jout(['status'=>'error','message'=>'尚未設定 NAS 路徑']);
    if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) jout(['status'=>'error','message'=>'請選擇範本檔']);
    $ext = asSafeExt($_FILES['file']['name']);
    if (!$ext) jout(['status'=>'error','message'=>'不允許此檔案類型']);
    $dir = asTplDir($db);
    if (!is_dir($dir) && !mkdir($dir,0777,true)) jout(['status'=>'error','message'=>'無法建立範本資料夾']);
    $fname = 'apply_form_tpl_'.date('Ymd_His').'.'.$ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) jout(['status'=>'error','message'=>'寫入失敗']);
    asSetSetting($db,'as_doc_apply_form_tpl',$fname);
    jout(['status'=>'success','file'=>$fname]);

case 'download_template':
    $tpl = asGetSetting($db,'as_doc_apply_form_tpl');
    if ($tpl==='') { http_response_code(404); exit('尚未上傳申請單範本'); }
    asStream(asTplDir($db).DIRECTORY_SEPARATOR.$tpl, '文件制修申請單_範本.'.pathinfo($tpl,PATHINFO_EXTENSION));
    break;

default:
    jout(['status'=>'error','message'=>'無效的操作']);
}
} catch (Exception $e) {
    jout(['status'=>'error','message'=>'系統錯誤：'.$e->getMessage()]);
}
