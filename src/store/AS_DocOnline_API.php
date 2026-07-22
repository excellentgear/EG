<?php
/**
 * AS 程序書「線上化編輯」API（結構化段落式編輯器）
 * 設計原則：純加法，不動 AS_Document_API.php 與既有頁面。
 *  - 草稿存 as_doc_content_section（一段一列）+ as_doc_draft（狀態/編輯鎖）
 *  - 發布時把整份內容 snapshot 寫入 as_document_version.content_json（既有表新增欄位）
 *  - 自動版本建議：草稿逐段 diff「目前已發布版的 content_json」→ 產生制修訂摘要/頁次/版次建議
 * 路徑合規（鐵律5）：本模組內容存 DB，不涉磁碟完整路徑；讀舊 Word 預抽時才即時組路徑。
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
$currentCname    = '';
if ($currentUserName !== '') {
    $st = $db->prepare("SELECT id, user_cname FROM user WHERE user_uname = ?");
    $st->execute([$currentUserName]);
    if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
        $currentUserId = (int)$u['id'];
        $currentCname  = (string)($u['user_cname'] ?: $currentUserName);
    }
}

include_once $document_root . '/EGsystem/src/common/role_features_helper.php';
$asFeatures    = $currentUserId ? rf_load_user_features_override($db, $currentUserId, 'as_doc') : [];
$asIsRoleAdmin = in_array('all', $asFeatures, true);

/** 頁面 ACRUD 字串（沿用 AS_Document_API 的解析，權限來源一致） */
function odPagePerm(PDO $db, int $uid): string {
    try {
        $st = $db->prepare("SELECT page_id, group_id FROM system_module_pages
                            WHERE page_url LIKE '%views/ADM/as_document_management.php' LIMIT 1");
        $st->execute();
        $pg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pg) return '';
        $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $st->execute([$uid, $pg['page_id']]);
        $perms = $st->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms) && !empty($pg['group_id'])) {
            $st = $db->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $st->execute([$pg['group_id']]);
            $gCode = $st->fetchColumn();
            if ($gCode) {
                $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $st->execute([$uid, $gCode]);
                $perms = $st->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars = [];
        foreach ($perms as $p) { $chars = array_merge($chars, str_split($p)); }
        return implode('', array_unique($chars));
    } catch (Exception $e) { return ''; }
}
$asPagePerm = $currentUserId ? odPagePerm($db, $currentUserId) : '';

$canView    = $asIsRoleAdmin || strpos($asPagePerm,'A')!==false || strpos($asPagePerm,'R')!==false || in_array('asdoc_view',$asFeatures,true);
// 結構化線上編輯：獨立功能碼 asdoc_online_edit（或頁面 U / 管理員）
$canEdit    = $asIsRoleAdmin || strpos($asPagePerm,'A')!==false || strpos($asPagePerm,'U')!==false || in_array('asdoc_online_edit',$asFeatures,true) || in_array('asdoc_update',$asFeatures,true);
// 發布＝建立新版本，視為 update 級
$canPublish = $canEdit;

function jout($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

if ($currentUserId <= 0) jout(['status'=>'error','message'=>'尚未登入']);
if (!$canView)          jout(['status'=>'error','message'=>'無權限']);

/** HTMLPurifier 清洗（存草稿用）：允許基本排版與表格，禁 script/style/on* */
function odPurify(string $html): string {
    static $pur = null;
    if ($pur === null) {
        $auto = $_SERVER['DOCUMENT_ROOT'].'/EGsystem/vendor/autoload.php';
        if (is_file($auto)) require_once $auto;
        if (class_exists('HTMLPurifier_Config')) {
            $cfg = HTMLPurifier_Config::createDefault();
            $cfg->set('HTML.Allowed',
                'p,br,b,strong,i,em,u,s,ul,ol,li,h4,h5,h6,blockquote,'
              .'table,thead,tbody,tr,td[colspan|rowspan],th[colspan|rowspan],'
              .'span[style],p[style],div[style],a[href],img[src|alt|width|height]');
            $cfg->set('CSS.AllowedProperties', 'text-align,font-weight,font-style,text-decoration,width,vertical-align');
            $cfg->set('Cache.SerializerPath', sys_get_temp_dir());
            $cfg->set('Attr.AllowedFrameTargets', ['_blank']);
            $pur = new HTMLPurifier($cfg);
        } else {
            $pur = false; // 無 purifier 時退回 strip_tags 白名單
        }
    }
    if ($pur) return $pur->purify($html);
    return strip_tags($html, '<p><br><b><strong><i><em><u><s><ul><ol><li><h4><h5><h6><table><thead><tbody><tr><td><th><span>');
}

/** HTML → 正規化純文字（供 diff 比對，忽略排版差異） */
function odPlain(string $html): string {
    $t = preg_replace('/<(br|\/p|\/li|\/tr|\/h[1-6])\s*>/i', "\n", $html);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/[ \t\x{3000}]+/u', ' ', $t);
    $t = preg_replace('/\s*\n\s*/u', "\n", $t);
    return trim($t);
}

/** 讀模板段落 */
function odTemplateSections(PDO $db, string $tplKey): array {
    $st = $db->prepare("SELECT section_key, title, sort_order FROM as_doc_section_template
                        WHERE template_key=? AND is_active=1 ORDER BY sort_order, id");
    $st->execute([$tplKey]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 取／建 draft 列 */
function odGetOrCreateDraft(PDO $db, int $docId, string $tplKey): array {
    $st = $db->prepare("SELECT * FROM as_doc_draft WHERE doc_id=?");
    $st->execute([$docId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if ($d) return $d;
    // 以文件目前版本作為 diff 基準
    $cvId = (int)($db->query("SELECT current_version_id FROM as_document WHERE id=".(int)$docId)->fetchColumn() ?: 0) ?: null;
    $db->prepare("INSERT INTO as_doc_draft (doc_id, template_key, based_on_version_id, status, updated_by, updated_at)
                  VALUES (?,?,?, 'draft', ?, NOW())")
       ->execute([$docId, $tplKey, $cvId, $GLOBALS['currentCname']]);
    $st->execute([$docId]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

// ── 清單：只列 程序書(二階/程序) 與 一階手冊，附草稿狀態 ──
case 'list': {
    $rows = $db->query(
        "SELECT d.id, d.doc_no, d.doc_name, d.doc_level, d.doc_type, d.current_version, d.current_version_id,
                dr.status AS draft_status, dr.template_key, dr.locked_by, dr.locked_by_name, dr.locked_at, dr.updated_at AS draft_updated_at,
                (SELECT COUNT(*) FROM as_doc_content_section s WHERE s.doc_id=d.id) AS has_content
           FROM as_document d
           LEFT JOIN as_doc_draft dr ON dr.doc_id=d.id
          WHERE d.is_deleted=0 AND (d.doc_level='一階' OR d.doc_type='程序')
          ORDER BY d.doc_level, d.doc_no"
    )->fetchAll(PDO::FETCH_ASSOC);
    jout(['status'=>'success','can_edit'=>$canEdit,'can_publish'=>$canPublish,'me'=>$currentUserId,'me_name'=>$currentCname,'rows'=>$rows]);
}

// ── 載入單一文件的編輯內容（草稿；無則依模板生成空段落） ──
case 'get': {
    $docId = (int)($_GET['doc_id'] ?? 0);
    if ($docId<=0) jout(['status'=>'error','message'=>'缺少 doc_id']);
    $st = $db->prepare("SELECT * FROM as_document WHERE id=? AND is_deleted=0");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    $tplKey = ($doc['doc_level']==='一階') ? 'manual' : 'procedure';
    $draft  = odGetOrCreateDraft($db, $docId, $tplKey);
    $tplKey = $draft['template_key'] ?: $tplKey;

    // 現有段落內容 → 合併為單一內文 HTML（單一 TinyMCE 編輯）
    $st = $db->prepare("SELECT section_key, title, sort_order, content_html FROM as_doc_content_section WHERE doc_id=? ORDER BY sort_order, id");
    $st->execute([$docId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $bodyHtml = '';
    if (count($rows)===1 && $rows[0]['section_key']==='body') {
        // 已是單一內文模型：直接用
        $bodyHtml = $rows[0]['content_html'];
    } elseif ($rows) {
        // 舊多段資料 → 併成內文，段落標題轉 H4
        foreach ($rows as $r) { $bodyHtml .= '<h4>'.htmlspecialchars($r['title'],ENT_QUOTES,'UTF-8').'</h4>'.($r['content_html'] ?: '<p><br></p>'); }
    } else {
        // 尚無內容 → 用模板標題生出空白骨架，供使用者填寫或匯入時整份取代
        foreach (odTemplateSections($db, $tplKey) as $t) { $bodyHtml .= '<h4>'.htmlspecialchars($t['title'],ENT_QUOTES,'UTF-8').'</h4><p><br></p>'; }
    }

    // 目前版本 metadata（封面用）
    $cv = null;
    if ($doc['current_version_id']) {
        $st = $db->prepare("SELECT version, change_status, revised_date FROM as_document_version WHERE id=?");
        $st->execute([$doc['current_version_id']]);
        $cv = $st->fetch(PDO::FETCH_ASSOC);
    }

    // 鎖狀態
    $lockStale = $draft['locked_at'] && (strtotime($draft['locked_at']) < time()-30*60);
    $lockedByOther = $draft['locked_by'] && (int)$draft['locked_by']!==$currentUserId && !$lockStale;

    jout(['status'=>'success','doc'=>$doc,'current_version'=>$cv,'template_key'=>$tplKey,
          'body_html'=>$bodyHtml,'draft_status'=>$draft['status'],
          'locked_by'=>$draft['locked_by'],'locked_by_name'=>$draft['locked_by_name'],
          'locked_by_other'=>$lockedByOther,'can_edit'=>$canEdit,'can_publish'=>$canPublish]);
}

// ── 取得編輯鎖 ──
case 'lock': {
    if (!$canEdit) jout(['status'=>'error','message'=>'無編輯權限']);
    $docId = (int)($_POST['doc_id'] ?? 0);
    if ($docId<=0) jout(['status'=>'error','message'=>'缺少 doc_id']);
    $tplKey = (($db->query("SELECT doc_level FROM as_document WHERE id=".(int)$docId)->fetchColumn())==='一階')?'manual':'procedure';
    $draft = odGetOrCreateDraft($db, $docId, $tplKey);
    $stale = $draft['locked_at'] && (strtotime($draft['locked_at']) < time()-30*60);
    if ($draft['locked_by'] && (int)$draft['locked_by']!==$currentUserId && !$stale) {
        jout(['status'=>'error','locked'=>true,'message'=>'文件正由「'.$draft['locked_by_name'].'」編輯中（'.$draft['locked_at'].'）']);
    }
    $db->prepare("UPDATE as_doc_draft SET locked_by=?, locked_by_name=?, locked_at=NOW() WHERE doc_id=?")
       ->execute([$currentUserId, $currentCname, $docId]);
    jout(['status'=>'success']);
}

case 'unlock': {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $db->prepare("UPDATE as_doc_draft SET locked_by=NULL, locked_by_name=NULL, locked_at=NULL WHERE doc_id=? AND locked_by=?")
       ->execute([$docId, $currentUserId]);
    jout(['status'=>'success']);
}

// ── 存草稿（需持有鎖）──
case 'save_draft': {
    if (!$canEdit) jout(['status'=>'error','message'=>'無編輯權限']);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $secJson = $_POST['sections'] ?? '';
    if ($docId<=0) jout(['status'=>'error','message'=>'缺少 doc_id']);
    $sections = json_decode($secJson, true);
    if (!is_array($sections)) jout(['status'=>'error','message'=>'段落資料格式錯誤']);

    // 鎖檢查（本人或未鎖或已過期才可存）
    $st = $db->prepare("SELECT locked_by, locked_at FROM as_doc_draft WHERE doc_id=?");
    $st->execute([$docId]);
    $lk = $st->fetch(PDO::FETCH_ASSOC);
    $stale = $lk && $lk['locked_at'] && (strtotime($lk['locked_at']) < time()-30*60);
    if ($lk && $lk['locked_by'] && (int)$lk['locked_by']!==$currentUserId && !$stale) {
        jout(['status'=>'error','message'=>'文件正由他人編輯中，無法存檔']);
    }

    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM as_doc_content_section WHERE doc_id=?")->execute([$docId]);
        $ins = $db->prepare("INSERT INTO as_doc_content_section (doc_id,section_key,title,sort_order,content_html,updated_by,updated_at)
                             VALUES (?,?,?,?,?,?,NOW())");
        $i = 0;
        foreach ($sections as $s) {
            $key   = trim((string)($s['section_key'] ?? '')) ?: ('custom_'.(++$i));
            $title = trim((string)($s['title'] ?? '（未命名）'));
            $html  = odPurify((string)($s['content_html'] ?? ''));
            $ins->execute([$docId, mb_substr($key,0,40), mb_substr($title,0,100), (int)($s['sort_order'] ?? ($i*10)), $html, $currentCname]);
        }
        $db->prepare("UPDATE as_doc_draft SET status='draft', updated_by=?, updated_at=NOW(), locked_by=?, locked_by_name=?, locked_at=NOW() WHERE doc_id=?")
           ->execute([$currentCname, $currentUserId, $currentCname, $docId]);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        jout(['status'=>'error','message'=>'存檔失敗：'.$e->getMessage()]);
    }
    jout(['status'=>'success','saved_at'=>date('Y-m-d H:i')]);
}

// ── 從舊 Word 檔預抽內文（.docx 用 PhpWord；.doc 舊二進位不支援，回提示）──
case 'prefill_from_word': {
    if (!$canEdit) jout(['status'=>'error','message'=>'無編輯權限']);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $st = $db->prepare("SELECT v.file_name, v.original_name, v.doc_id
                        FROM as_document d JOIN as_document_version v ON v.id=d.current_version_id
                        WHERE d.id=?");
    $st->execute([$docId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v || !$v['file_name']) jout(['status'=>'error','message'=>'此文件目前版本無檔可抽']);
    $ext = strtolower(pathinfo($v['file_name'], PATHINFO_EXTENSION));

    // 即時組路徑（鐵律5）：根 = as_doc_nas_dir，子資料夾 docs/{doc_id}
    $root = rtrim((function(PDO $db){ $s=$db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='as_doc_nas_dir'"); $s->execute(); return (string)$s->fetchColumn(); })($db), "/\\");
    $path = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.(int)$v['doc_id'].DIRECTORY_SEPARATOR.$v['file_name'];
    if (!is_file($path)) jout(['status'=>'error','message'=>'檔案不存在或 NAS 未連線']);

    if ($ext !== 'docx') {
        jout(['status'=>'error','message'=>'舊格式（.'.$ext.'）暫不支援自動預抽，請開啟原檔手動複製貼入（可用並排核對）']);
    }
    $auto = $document_root.'/EGsystem/vendor/autoload.php';
    if (is_file($auto)) require_once $auto;
    if (!class_exists('\\PhpOffice\\PhpWord\\IOFactory')) jout(['status'=>'error','message'=>'PhpWord 未安裝']);
    try {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($path, 'Word2007');
        $paras = [];
        foreach ($phpWord->getSections() as $sec) {
            foreach ($sec->getElements() as $el) {
                $txt = odExtractText($el);
                if (trim($txt) !== '') $paras[] = $txt;
            }
        }
        $html = '';
        foreach ($paras as $p) { $html .= '<p>'.htmlspecialchars($p, ENT_QUOTES, 'UTF-8').'</p>'; }
        jout(['status'=>'success','text_html'=>$html,'para_count'=>count($paras),
              'note'=>'已抽出原檔內文，請於編輯區分段整理（此為輔助，非自動歸段）']);
    } catch (Exception $e) {
        jout(['status'=>'error','message'=>'讀取失敗：'.$e->getMessage()]);
    }
}

// ── 版本建議：草稿逐段 diff 目前已發布版的 content_json ──
case 'suggest_version': {
    $docId = (int)($_GET['doc_id'] ?? $_POST['doc_id'] ?? 0);
    $st = $db->prepare("SELECT d.*, dr.based_on_version_id FROM as_document d LEFT JOIN as_doc_draft dr ON dr.doc_id=d.id WHERE d.id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    // 草稿目前內文（合併為單一 body）
    $st = $db->prepare("SELECT section_key, title, content_html FROM as_doc_content_section WHERE doc_id=? ORDER BY sort_order, id");
    $st->execute([$docId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $curBody = odRowsToBody($rows);
    $curMap  = odSplitHeadings($curBody);

    // 基準版本內文
    $baseMap = [];
    $baseVer = $doc['current_version'] ?: '';
    $hasBase = false;
    if (!empty($doc['based_on_version_id'])) {
        $st = $db->prepare("SELECT version, content_json FROM as_document_version WHERE id=?");
        $st->execute([$doc['based_on_version_id']]);
        if ($bv = $st->fetch(PDO::FETCH_ASSOC)) {
            $baseVer = $bv['version'];
            if (!empty($bv['content_json'])) {
                $arr = json_decode($bv['content_json'], true);
                if (is_array($arr)) { $baseMap = odSplitHeadings(odRowsToBody($arr)); $hasBase = true; }
            }
        }
    }

    // 依「標題（H4）」逐節比對純文字
    $changed = []; $added = []; $removed = [];
    foreach ($curMap as $title=>$txt) {
        if (!$hasBase) continue;
        if (!array_key_exists($title, $baseMap)) { if ($txt!=='') $added[] = $title; }
        elseif ($txt !== $baseMap[$title]) { $changed[] = $title; }
    }
    if ($hasBase) { foreach ($baseMap as $title=>$txt) { if (!array_key_exists($title,$curMap) && $txt!=='') $removed[] = $title; } }

    // 版次建議：基準版 +0.1；無基準（首次電子化）→ 沿用目前版、狀況制訂
    $suggestVer = odBumpVersion($baseVer);
    $status = $hasBase ? '修訂' : '制訂';
    if (!$hasBase) {
        $summary = '文件電子化建置，內容依原版 '.($baseVer?:'—').' 重製。';
        $pages   = '全冊';
        $suggestVer = $baseVer ?: '1.0';
    } else {
        $parts = [];
        if ($changed) $parts[] = '修訂：'.implode('、', $changed);
        if ($added)   $parts[] = '新增：'.implode('、', $added);
        if ($removed) $parts[] = '刪除：'.implode('、', $removed);
        $summary = $parts ? implode('；', $parts) : '內容無實質變更（僅排版調整）';
        $touched = count($changed)+count($added)+count($removed);
        $totalSec = max(1, count($curMap));
        $pages = ($touched >= $totalSec*0.5) ? '全冊' : implode('、', array_slice(array_merge($changed,$added), 0, 6));
        if ($pages==='') $pages='—';
    }

    jout(['status'=>'success','has_base'=>$hasBase,'base_version'=>$baseVer,
          'suggest_version'=>$suggestVer,'suggest_status'=>$status,
          'suggest_pages'=>$pages,'suggest_summary'=>$summary,
          'changed'=>$changed,'added'=>$added,'removed_count'=>count($removed),
          'can_publish'=>$canPublish]);
}

// ── 發布：把草稿 snapshot 成新版本（寫 as_document_version.content_json）──
case 'publish': {
    if (!$canPublish) jout(['status'=>'error','message'=>'無發布權限']);
    $docId  = (int)($_POST['doc_id'] ?? 0);
    $version = trim((string)($_POST['version'] ?? ''));
    $status  = trim((string)($_POST['change_status'] ?? '修訂'));
    $rdate   = trim((string)($_POST['revised_date'] ?? date('Y-m-d')));
    $pages   = trim((string)($_POST['revised_pages'] ?? ''));
    $summary = trim((string)($_POST['revised_summary'] ?? ''));
    if ($docId<=0 || $version==='') jout(['status'=>'error','message'=>'缺少文件或版本號']);

    $st = $db->prepare("SELECT * FROM as_document WHERE id=? AND is_deleted=0");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    // 版本號不可與既有版本重複
    $st = $db->prepare("SELECT COUNT(*) FROM as_document_version WHERE doc_id=? AND UPPER(version)=UPPER(?)");
    $st->execute([$docId, $version]);
    if ((int)$st->fetchColumn() > 0) jout(['status'=>'error','message'=>'版本號 '.$version.' 已存在']);

    // snapshot 目前草稿段落
    $st = $db->prepare("SELECT section_key, title, sort_order, content_html FROM as_doc_content_section WHERE doc_id=? ORDER BY sort_order, id");
    $st->execute([$docId]);
    $sections = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$sections) jout(['status'=>'error','message'=>'尚無內容可發布']);
    $contentJson = json_encode($sections, JSON_UNESCAPED_UNICODE);

    try {
        $db->beginTransaction();
        $ins = $db->prepare("INSERT INTO as_document_version
            (doc_id, version, change_status, revised_date, revised_pages, revised_summary,
             doc_level_snapshot, department_id_snapshot, content_json, uploaded_by, uploaded_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
        $ins->execute([$docId, $version, $status, $rdate ?: null, $pages, $summary,
                       $doc['doc_level'], $doc['department_id'], $contentJson, $currentCname]);
        $newVerId = (int)$db->lastInsertId();

        $db->prepare("UPDATE as_document SET current_version=?, current_version_id=?, updated_at=NOW() WHERE id=?")
           ->execute([$version, $newVerId, $docId]);
        // 草稿基準改指向新版本、狀態回 published、釋放鎖
        $db->prepare("UPDATE as_doc_draft SET based_on_version_id=?, status='published', locked_by=NULL, locked_by_name=NULL, locked_at=NULL, updated_by=?, updated_at=NOW() WHERE doc_id=?")
           ->execute([$newVerId, $currentCname, $docId]);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        jout(['status'=>'error','message'=>'發布失敗：'.$e->getMessage()]);
    }
    jout(['status'=>'success','version_id'=>$newVerId,'version'=>$version,'message'=>'已發布新版本 '.$version]);
}

// ── 組出唯讀文件 HTML（預覽／列印）──
case 'render': {
    $docId = (int)($_GET['doc_id'] ?? 0);
    $verId = (int)($_GET['version_id'] ?? 0); // 指定歷史版本則讀其 content_json
    $st = $db->prepare("SELECT * FROM as_document WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { header('Content-Type:text/plain'); echo '文件不存在'; exit; }

    $verLabel = $doc['current_version']; $rdate=''; $sections=[];
    if ($verId>0) {
        $st = $db->prepare("SELECT version, revised_date, content_json FROM as_document_version WHERE id=? AND doc_id=?");
        $st->execute([$verId,$docId]);
        if ($vr=$st->fetch(PDO::FETCH_ASSOC)) { $verLabel=$vr['version']; $rdate=$vr['revised_date']; $sections=json_decode($vr['content_json']?:'[]',true)?:[]; }
    } else {
        $st = $db->prepare("SELECT section_key,title,content_html FROM as_doc_content_section WHERE doc_id=? ORDER BY sort_order,id");
        $st->execute([$docId]);
        $sections = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    // 受控外框設定：英文名用設定值；中文名自動抓本公司(customer_list.is_own_company=1)，設定值可覆寫
    $coEn   = odSetting($db, 'as_doc_company_en', 'EXCELLENT GEAR TECHNOLOGY CO., LTD');
    $coZh   = odSetting($db, 'as_doc_company_zh', '') ?: odOwnCompany($db);
    $footer = odSetting($db, 'as_doc_footer_note', '本文件不得擅自塗改或影印');

    header('Content-Type: text/html; charset=utf-8');
    $e = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $body = odRowsToBody($sections) ?: '<p style="color:#aaa">（尚無內容）</p>';
    echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><title>'.$e($doc['doc_no'].' '.$doc['doc_name']).'</title>';
    echo '<style>
      @page{ size:A4; margin:14mm 14mm 14mm 14mm; }
      *{box-sizing:border-box;}
      body{font-family:"Microsoft JhengHei","新細明體",serif;color:#111;line-height:1.7;font-size:12pt;margin:0;background:#f2f2f2;}
      .sheet{max-width:186mm;margin:14px auto;background:#fff;padding:16px 20px;box-shadow:0 1px 6px rgba(0,0,0,.15);}
      /* 受控頁首（置頂表格，正常流排、不遮內文）*/
      .frame-hd{border-collapse:collapse;width:100%;margin-bottom:2px;}
      .frame-hd td{border:1px solid #000;padding:3px 8px;font-size:10.5pt;vertical-align:middle;}
      .frame-hd .co{width:64%;text-align:center;}
      .frame-hd .co .en{font-weight:700;font-size:11.5pt;letter-spacing:.3px;}
      .frame-hd .co .zh{font-size:11pt;margin-top:1px;}
      .frame-hd .co .dn{border-top:1px solid #000;margin-top:4px;padding-top:3px;text-align:left;font-size:11pt;}
      .frame-hd .lbl{width:12%;text-align:center;background:#f6f6f6;letter-spacing:2px;white-space:nowrap;}
      .frame-hd .val{width:24%;text-align:center;}
      .frame-ft{display:flex;justify-content:space-between;font-size:9.5pt;padding:4px 2px 0;border-top:1px solid #000;margin-top:14px;color:#333;}
      h3.doctitle{text-align:center;margin:10px 0 16px;font-size:15pt;}
      h4{margin:16px 0 6px;font-size:12.5pt;font-weight:700;}
      .content table{border-collapse:collapse;} .content td,.content th{border:1px solid #333;padding:3px 6px;}
      .content img{max-width:100%;height:auto;}
      .noprint{max-width:186mm;margin:6px auto 24px;text-align:center;}
      @media print{ body{background:#fff;} .sheet{box-shadow:none;margin:0;max-width:none;padding:0;} .noprint{display:none;} }
    </style></head><body>';
    echo '<div class="sheet">';
    // 頁首外框（置頂，逐頁重複與逐頁頁碼待 Word 範本→PDF 階段）
    echo '<table class="frame-hd"><tr>'
        .'<td class="co" rowspan="3"><div class="en">'.$e($coEn).'</div>'.($coZh?'<div class="zh">'.$e($coZh).'</div>':'')
        .'<div class="dn">文件名稱：'.$e($doc['doc_name']).'</div></td>'
        .'<td class="lbl">文件編號</td><td class="val">'.$e($doc['doc_no']).'</td></tr>'
        .'<tr><td class="lbl">頁　次</td><td class="val">&nbsp;</td></tr>'
        .'<tr><td class="lbl">版　次</td><td class="val">'.$e($verLabel).'</td></tr></table>';
    // 內文
    echo '<h3 class="doctitle">'.$e($doc['doc_name']).'</h3><div class="content">'.$body.'</div>';
    // 頁尾外框
    echo '<div class="frame-ft"><span>（'.$e($footer).'）</span><span>'.$e($doc['doc_no']).'</span></div>';
    echo '</div>'; // .sheet
    echo '<div class="noprint">'
        .'<button onclick="window.location=\''.$e('?action=export_pdf&doc_id='.$docId.($verId>0?'&version_id='.$verId:'')).'\'" style="padding:6px 18px;font-size:14px;font-weight:bold;">匯出 PDF（每頁頁首＋真頁碼，約 10 秒）</button> '
        .'<button onclick="window.print()" style="padding:6px 18px;font-size:14px;">瀏覽器直接列印（簡易）</button>'
        .'</div>';
    echo '</body></html>';
    exit;
}

// ── 匯出 PDF（Word 範本→LibreOffice；逐頁頁首頁尾+真頁碼+表格不切列）──
case 'export_pdf': {
    $docId = (int)($_GET['doc_id'] ?? 0);
    $verId = (int)($_GET['version_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM as_document WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { http_response_code(404); header('Content-Type:text/plain; charset=utf-8'); echo '文件不存在'; exit; }

    $verLabel = $doc['current_version']; $sections=[];
    if ($verId>0) {
        $st = $db->prepare("SELECT version, content_json FROM as_document_version WHERE id=? AND doc_id=?");
        $st->execute([$verId,$docId]);
        if ($vr=$st->fetch(PDO::FETCH_ASSOC)) { $verLabel=$vr['version']; $sections=json_decode($vr['content_json']?:'[]',true)?:[]; }
    } else {
        $st = $db->prepare("SELECT section_key,title,content_html FROM as_doc_content_section WHERE doc_id=? ORDER BY sort_order,id");
        $st->execute([$docId]);
        $sections = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $pdf = odExportPdf($db, $doc, $sections, $verLabel, $document_root);
    if (!$pdf || !is_file($pdf)) { http_response_code(500); header('Content-Type:text/plain; charset=utf-8'); echo 'PDF 產生失敗（LibreOffice 未啟動或內容轉檔錯誤），請改用「預覽」或稍後再試'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="'.rawurlencode($doc['doc_no']).'_v'.rawurlencode($verLabel).'.pdf"');
    header('Content-Length: '.filesize($pdf));
    readfile($pdf);
    @unlink($pdf);
    exit;
}

// ── 圖片上傳（TinyMCE images_upload_handler；存 NAS/online_img/{doc_id}，DB/內容只留檔名）──
case 'upload_image': {
    if (!$canEdit) { http_response_code(403); jout(['error'=>'無編輯權限']); }
    $docId = (int)($_POST['doc_id'] ?? $_GET['doc_id'] ?? 0);
    if ($docId<=0 || empty($_FILES['file'])) { http_response_code(400); jout(['error'=>'缺少檔案或 doc_id']); }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) { http_response_code(400); jout(['error'=>'上傳失敗（錯誤碼 '.$f['error'].'）']); }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png','jpg','jpeg','gif','webp','bmp'], true)) { http_response_code(400); jout(['error'=>'僅允許圖片格式']); }
    // 內容型別再驗一次，擋改副檔名的偽圖
    $info = @getimagesize($f['tmp_name']);
    if ($info === false) { http_response_code(400); jout(['error'=>'非有效圖片']); }
    $root = odNasRoot($db);
    if ($root==='') { http_response_code(500); jout(['error'=>'尚未設定 AS 文件根路徑']); }
    $dir = $root.DIRECTORY_SEPARATOR.'online_img'.DIRECTORY_SEPARATOR.$docId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { http_response_code(500); jout(['error'=>'無法建立圖片資料夾（NAS 未連線？）']); }
    $name = date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir.DIRECTORY_SEPARATOR.$name)) { http_response_code(500); jout(['error'=>'寫入失敗']); }
    // 回根相對 URL（只帶檔名參數，完整路徑讀取時現場組，符合鐵律5）
    echo json_encode(['location'=>'/EGsystem/src/store/AS_DocOnline_API.php?action=img&doc_id='.$docId.'&f='.rawurlencode($name)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 圖片 serve ──
case 'img': {
    $docId = (int)($_GET['doc_id'] ?? 0);
    $f = basename((string)($_GET['f'] ?? '')); // 防目錄穿越
    if ($docId<=0 || $f==='') { http_response_code(404); exit; }
    $path = odNasRoot($db).DIRECTORY_SEPARATOR.'online_img'.DIRECTORY_SEPARATOR.$docId.DIRECTORY_SEPARATOR.$f;
    if (!is_file($path)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp'][$ext] ?? 'application/octet-stream';
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

default:
    jout(['status'=>'error','message'=>'未知動作：'.$action]);
}

// ── 輔助函式 ──────────────────────────────────────
/** 讀 system_settings 單值，無則回預設 */
function odSetting(PDO $db, string $key, string $default=''): string {
    $s = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return ($v!==false && $v!==null && trim((string)$v)!=='') ? (string)$v : $default;
}
/** 內文 img 的 action=img URL → 本機檔案 base64 data URI（供 PhpWord 內嵌；讀不到則移除該 img） */
function odInlineImages(PDO $db, string $html): string {
    $root = odNasRoot($db);
    return preg_replace_callback(
        '/<img\b[^>]*\bsrc=["\']([^"\']*action=img[^"\']*)["\'][^>]*>/i',
        function($m) use ($root) {
            $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $q = [];
            parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
            $docId = (int)($q['doc_id'] ?? 0); $f = basename((string)($q['f'] ?? ''));
            if ($docId<=0 || $f==='') return '';
            $path = $root.DIRECTORY_SEPARATOR.'online_img'.DIRECTORY_SEPARATOR.$docId.DIRECTORY_SEPARATOR.$f;
            if (!is_file($path)) return '';
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp'][$ext] ?? 'image/png';
            $data = @file_get_contents($path);
            if ($data===false) return '';
            return '<img src="data:'.$mime.';base64,'.base64_encode($data).'"/>';
        }, $html) ?? $html;
}
/** PhpWord Html::addHtml 內部走 XML 解析：void 標籤(br/img/hr…)未自閉會整段解析失敗→內文全空。
 *  TinyMCE 與 DOM saveHTML 輸出皆為 HTML5 未自閉格式，匯出前必須先自閉。 */
function odXmlSafe(string $h): string {
    return preg_replace('/<(img|br|hr|col|input)((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(?<!\/)>/i', '<$1$2/>', $h);
}
/** 為內文表格補上框線（PhpWord Html 依 table border 屬性與 td/th style 才畫格線） */
function odTableBorders(string $html): string {
    if (stripos($html, '<table') === false) return $html;
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8"><div id="odroot">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    foreach ($d->getElementsByTagName('table') as $tb) {
        $tb->setAttribute('border', '1');
        $st = $tb->getAttribute('style');
        if (stripos($st, 'border-collapse') === false) $tb->setAttribute('style', rtrim($st, ';').';border-collapse:collapse;');
    }
    foreach (['td','th'] as $tag) {
        foreach (iterator_to_array($d->getElementsByTagName($tag)) as $c) {
            $st = $c->getAttribute('style');
            if (stripos($st, 'border') === false) $c->setAttribute('style', rtrim($st, ';').';border:1px solid #000000;');
        }
    }
    $root = $d->getElementById('odroot');
    if (!$root) return $html;
    $out = '';
    foreach ($root->childNodes as $n) { $out .= $d->saveHTML($n); }
    return $out;
}
/** 組出 AS 程序書 Word（含逐頁頁首頁尾+真頁碼）→ LibreOffice 轉 PDF，回傳 PDF 路徑或 null */
function odExportPdf(PDO $db, array $doc, array $sections, string $verLabel, string $documentRoot): ?string {
    require_once $documentRoot.'/EGsystem/vendor/autoload.php';
    require_once $documentRoot.'/EGsystem/src/common/attachment_lib.php';
    if (!class_exists('\\PhpOffice\\PhpWord\\PhpWord') || !function_exists('eg_att_soffice_convert')) return null;

    $coEn   = odSetting($db, 'as_doc_company_en', 'EXCELLENT GEAR TECHNOLOGY CO., LTD');
    $coZh   = odSetting($db, 'as_doc_company_zh', '') ?: odOwnCompany($db);
    $footer = odSetting($db, 'as_doc_footer_note', '本文件不得擅自塗改或影印');
    $docNo  = (string)$doc['doc_no']; $docName = (string)$doc['doc_name'];
    $body   = odXmlSafe(odTableBorders(odInlineImages($db, odRowsToBody($sections))));

    \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
    $pw = new \PhpOffice\PhpWord\PhpWord();
    $pw->getSettings()->setUpdateFields(true); // 讓 NUMPAGES/PAGE 開檔即更新
    $pw->setDefaultFontName('Microsoft JhengHei');
    $pw->setDefaultFontSize(12);
    $sec = $pw->addSection(['marginTop'=>900,'marginBottom'=>800,'marginLeft'=>900,'marginRight'=>900,
                            'headerHeight'=>200,'footerHeight'=>200]);

    // ── 頁首（每頁重複）：公司抬頭 + 文件編號/頁次/版次 ──
    $hd = $sec->addHeader();
    $t  = $hd->addTable(['borderSize'=>6,'borderColor'=>'000000','cellMargin'=>30,'alignment'=>'center']);
    $cwCo=6600; $cwL=1400; $cwV=2318;
    $t->addRow();
    $co = $t->addCell($cwCo, ['vMerge'=>'restart','valign'=>'center']);
    $co->addText($coEn, ['bold'=>true,'size'=>11], ['alignment'=>'center','spaceAfter'=>0,'spaceBefore'=>0]);
    if ($coZh) $co->addText($coZh, ['size'=>11], ['alignment'=>'center','spaceAfter'=>0,'spaceBefore'=>0]);
    $co->addText('文件名稱：'.$docName, ['size'=>11], ['spaceBefore'=>60,'spaceAfter'=>0]);
    $t->addCell($cwL,['valign'=>'center'])->addText('文件編號',['size'=>10],['alignment'=>'center']);
    $t->addCell($cwV,['valign'=>'center'])->addText($docNo,['size'=>10],['alignment'=>'center']);
    $t->addRow();
    $t->addCell($cwCo,['vMerge'=>'continue']);
    $t->addCell($cwL,['valign'=>'center'])->addText('頁　次',['size'=>10],['alignment'=>'center']);
    $pc = $t->addCell($cwV,['valign'=>'center']); $ptr = $pc->addTextRun(['alignment'=>'center']);
    $ptr->addField('PAGE'); $ptr->addText(' / ', ['size'=>10]); $ptr->addField('NUMPAGES');
    $t->addRow();
    $t->addCell($cwCo,['vMerge'=>'continue']);
    $t->addCell($cwL,['valign'=>'center'])->addText('版　次',['size'=>10],['alignment'=>'center']);
    $t->addCell($cwV,['valign'=>'center'])->addText($verLabel,['size'=>10],['alignment'=>'center']);

    // ── 頁尾（每頁重複）──
    $ft = $sec->addFooter();
    $ftt = $ft->addTable(['cellMargin'=>0]);
    $ftt->addRow();
    $ftt->addCell(7000)->addText('（'.$footer.'）',['size'=>9],['spaceAfter'=>0]);
    $ftt->addCell(3318)->addText($docNo,['size'=>9],['alignment'=>'right','spaceAfter'=>0]);

    // ── 標題 + 內文 ──
    $sec->addText($docName, ['bold'=>true,'size'=>15], ['alignment'=>'center','spaceAfter'=>200]);
    try {
        \PhpOffice\PhpWord\Shared\Html::addHtml($sec, $body, false, false);
    } catch (Throwable $e) {
        error_log('[as_online] Html::addHtml 失敗，改用純文字：'.$e->getMessage());
        $sec->addText(trim(strip_tags(str_replace(['</p>','<br>','<br/>','</h4>'], "\n", $body))), ['size'=>12]);
    }

    // ── 存 docx → LibreOffice 轉 PDF ──
    $tmp = rtrim(sys_get_temp_dir(),'\\/').DIRECTORY_SEPARATOR.'as_pdf_'.bin2hex(random_bytes(5));
    if (!@mkdir($tmp,0775,true)) return null;
    $docx = $tmp.DIRECTORY_SEPARATOR.'doc.docx';
    try { \PhpOffice\PhpWord\IOFactory::createWriter($pw,'Word2007')->save($docx); }
    catch (Throwable $e) { error_log('[as_online] docx 存檔失敗：'.$e->getMessage()); @eg_att_rrmdir($tmp); return null; }

    $pdf = eg_att_soffice_convert($docx, $tmp, 150);
    if (!$pdf || !is_file($pdf)) { @eg_att_rrmdir($tmp); return null; }
    // 搬到獨立暫存名（呼叫端讀完即刪），再清工作夾
    $out = rtrim(sys_get_temp_dir(),'\\/').DIRECTORY_SEPARATOR.'asout_'.bin2hex(random_bytes(5)).'.pdf';
    @copy($pdf, $out); @eg_att_rrmdir($tmp);
    return is_file($out) ? $out : null;
}
/** 本公司中文全名（customer_list.is_own_company=1 之 customer_full；同 AS_Form_API 慣例） */
function odOwnCompany(PDO $db): string {
    try {
        $v = $db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 AND (is_inactive IS NULL OR is_inactive=0) LIMIT 1")->fetchColumn();
        return $v ? (string)$v : '';
    } catch (Exception $e) { return ''; }
}
/** AS 文件 NAS 根路徑（去尾斜線）；唯一存 DB 的路徑資訊，其餘現場組（鐵律5） */
function odNasRoot(PDO $db): string {
    $s = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='as_doc_nas_dir'");
    $s->execute();
    return rtrim((string)$s->fetchColumn(), "/\\");
}
/** 段落列 → 單一內文 HTML（單一 body 直接用；舊多段則以 H4 標題串接） */
function odRowsToBody(array $rows): string {
    if (count($rows)===1 && ($rows[0]['section_key'] ?? '')==='body') return (string)($rows[0]['content_html'] ?? '');
    $b='';
    foreach ($rows as $r) {
        $t = trim((string)($r['title'] ?? ''));
        if ($t!=='') $b .= '<h4>'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'</h4>';
        $b .= (string)($r['content_html'] ?? '');
    }
    return $b;
}
/** 內文依 H4 標題切成 {標題 => 純文字}，供逐節 diff */
function odSplitHeadings(string $html): array {
    $parts = preg_split('/<h4[^>]*>(.*?)<\/h4>/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $map = [];
    $pre = odPlain($parts[0] ?? '');
    if ($pre!=='') $map['（前言）'] = $pre;
    for ($i=1; $i+1<count($parts); $i+=2) {
        $title = trim(strip_tags($parts[$i]));
        if ($title!=='') $map[$title] = odPlain($parts[$i+1] ?? '');
    }
    return $map;
}
/** 遞迴抽 PhpWord 元素文字 */
function odExtractText($el): string {
    $out = '';
    if (method_exists($el, 'getText')) {
        $t = $el->getText();
        if (is_string($t)) return $t;
    }
    if (method_exists($el, 'getElements')) {
        foreach ($el->getElements() as $c) { $out .= odExtractText($c); }
    }
    return $out;
}
/** 版本號 +0.1（3.0→3.1、3.9→4.0；非數字型回原值加註） */
function odBumpVersion(string $v): string {
    $v = trim($v);
    if ($v==='') return '1.0';
    if (preg_match('/^(\d+)\.(\d+)$/', $v, $m)) {
        $maj=(int)$m[1]; $min=(int)$m[2]+1;
        if ($min>=10){ $maj++; $min=0; }
        return $maj.'.'.$min;
    }
    if (preg_match('/^\d+$/', $v)) return ((int)$v + 1).'.0';
    return $v;
}
