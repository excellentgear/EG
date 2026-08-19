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
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';

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

// ── 權限（依 user_permissions.php 規則：頁面 ACRUD 矩陣 OR as_doc 模組角色，含職稱指派）──
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';

// 職稱為主、個人優先：個人在 as_doc 有指派角色→以個人為準；否則套用職稱指派；管理員(all)恆有效
$asFeatures    = $currentUserId ? rf_load_user_features_override($db, $currentUserId, 'as_doc') : [];
$asIsRoleAdmin = in_array('all', $asFeatures, true);

/** 頁面 ACRUD 字串（user_permissions.php 權限矩陣：page scope 優先、group scope 備援） */
function asPagePerm(PDO $db, int $uid): string {
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
$asPagePerm = $currentUserId ? asPagePerm($db, $currentUserId) : '';

// 免附件補登（asdoc_no_attach）：僅認「明確勾選的功能碼」，管理員不自動豁免——
// 避免正式運作時「改版必附申請單」管控被默默弱化；補舊資料時把角色勾給自己，用完移除。
$asNoAttach = in_array('asdoc_no_attach', $asFeatures, true);

/** 能力判斷：view/create/update/delete 走「頁面ACRUD OR 角色功能碼」；settings/edit_online 只認 A 或對應功能碼 */
function asCan(string $what): bool {
    global $asFeatures, $asIsRoleAdmin, $asPagePerm;
    if ($asIsRoleAdmin || strpos($asPagePerm, 'A') !== false) return true;
    $charMap = ['view'=>'R', 'create'=>'C', 'update'=>'U', 'delete'=>'D'];
    if (isset($charMap[$what]) && strpos($asPagePerm, $charMap[$what]) !== false) return true;
    // 上傳紀錄：與「新增文件」分開設定，但相容既有已具新增文件權限者
    if ($what === 'upload_record') return in_array('asdoc_upload_record', $asFeatures, true) || asCan('create');
    return in_array('asdoc_' . $what, $asFeatures, true);
}

// ── 共用工具 ───────────────────────────────────────────────────────
function jout($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

/** 版本號正規化：去空白＋英文一律轉大寫（a→A、a-1→A-1；數字與中文不受影響）。所有寫入版本號的入口統一套用。 */
function asNormVer($v): string { return mb_strtoupper(trim((string)$v), 'UTF-8'); }

function asGetSetting(PDO $db, string $key): string {
    try {
        $s = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v !== false && $v !== null) ? trim($v) : '';
    } catch (Exception $e) { return ''; }
}
/** 本公司全名（發票用）：唯一來源 customer_list.is_own_company=1，禁寫死（ai-rules/16 第一節） */
function asOwnCompanyName(PDO $db): string {
    try {
        $r = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) return trim($r['customer_full'] ?: $r['customer']);
    } catch (Exception $e) {}
    return '';
}
/** 結構總覽列印綁定的 AS 文件（未綁定回 null）；doc_no 已依 eg_asdoc_no() 規則附加版次（僅四階文件，見 ai-rules/16 第三節） */
function asTreeBoundDoc(PDO $db): ?array {
    $id = (int)asGetSetting($db, 'as_doc_tree_print_as_doc_id');
    if ($id <= 0) return null;
    $s = $db->prepare("SELECT id, doc_no, doc_name, current_version, doc_level FROM as_document WHERE id=? AND is_deleted=0");
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['doc_no'] = eg_asdoc_no($r);
    return $r;
}
/* ── 修改（製表）簽章人員任期 ──────────────────────────────────────
 * 文件管制總覽表各階的簽章日期＝該階最新修改日期，可能是好幾年前；
 * 那時的 AS 負責人不一定是現任（例：2026-07-13 交接），一律蓋現任＝簽章不實。
 * 故以任期表（誰、從哪天到哪天）依日期回推當時的負責人；任期內就算數（不再檢查在職／請假，
 * 使用者拍板：那天他本來就是負責人，事後離職不該讓舊表印不出章）。 */
function asEditorTermEnsure(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS as_doc_editor_term (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        start_date DATE NULL COMMENT '空=最早（不限）',
        end_date DATE NULL COMMENT '空=至今',
        note VARCHAR(100) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_range (start_date, end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS文件管制總覽表－修改(製表)簽章人員任期'");
    $done = true;
}
/** 任期清單（依起日排序；起日空者最前） */
function asEditorTerms(PDO $db): array {
    asEditorTermEnsure($db);
    $rows = $db->query("SELECT t.id, t.user_id, t.start_date, t.end_date, t.note, u.user_cname, u.state
                        FROM as_doc_editor_term t LEFT JOIN `user` u ON u.id = t.user_id
                        ORDER BY COALESCE(t.start_date,'0001-01-01') ASC, t.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r)=>[
        'id'=>(int)$r['id'], 'user_id'=>(int)$r['user_id'], 'name'=>(string)($r['user_cname'] ?? ''),
        'start_date'=>$r['start_date'], 'end_date'=>$r['end_date'], 'note'=>(string)($r['note'] ?? ''),
        'resigned'=>((int)($r['state'] ?? 1) === 0),
    ], $rows);
}
/** 某日期當時的簽章人（沒有任期涵蓋該日＝回 null，由畫面手選遞補） */
function asEditorOfDate(array $terms, string $date): ?array {
    foreach ($terms as $t) {
        if ($t['start_date'] && $date < $t['start_date']) continue;
        if ($t['end_date']   && $date > $t['end_date'])   continue;
        if ($t['name'] === '') continue;
        return ['id'=>$t['user_id'], 'name'=>$t['name'], 'term_id'=>$t['id']];
    }
    return null;
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
/** 表單填寫紀錄的實體資料夾＝根 / records / {form_doc_id} */
function asRecordDir(PDO $db, int $docId): string {
    return asDocRoot($db) . DIRECTORY_SEPARATOR . 'records' . DIRECTORY_SEPARATOR . $docId;
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
/** 串流下載/開啟一個實體檔案（$inline=true 時 PDF/圖片直接在瀏覽器開啟） */
function asStream(string $fullpath, string $downloadName, bool $inline = false): void {
    if (!is_file($fullpath)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo '檔案不存在或 NAS 未連線'; exit; }
    $ext = strtolower(pathinfo($fullpath, PATHINFO_EXTENSION));
    $inlineTypes = ['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','txt'=>'text/plain; charset=utf-8'];
    header_remove('Content-Type');
    if ($inline && isset($inlineTypes[$ext])) {
        header('Content-Type: ' . $inlineTypes[$ext]);
        header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    }
    header('Content-Length: ' . filesize($fullpath));
    readfile($fullpath);
    exit;
}

/**
 * Office 檔線上預覽：轉成 PDF 快取後回傳快取路徑（失敗回 null）。
 * 版本檔案不會變動（改版＝新檔），快取永久有效；共用 attachment_lib 的 LibreOffice 轉檔＋Chrome 備援。
 */
function asPreviewPdf(PDO $db, int $docId, string $fileName, ?string $dir = null): ?string {
    $dir = $dir ?? asDocDir($db, $docId);
    $src = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($src)) return null;
    $cache = $dir . DIRECTORY_SEPARATOR . 'preview_' . pathinfo($fileName, PATHINFO_FILENAME) . '.pdf';
    if (is_file($cache) && filesize($cache) > 0) return $cache;
    require_once __DIR__ . '/../common/attachment_lib.php';
    $tmpOut = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'asdoc_prev_' . bin2hex(random_bytes(4));
    @mkdir($tmpOut, 0775, true);
    // NAS UNC 路徑 PhpSpreadsheet/soffice 會讀取失敗（2026-07-16 實測），一律先複製到本機暫存再轉檔
    $local = $tmpOut . DIRECTORY_SEPARATOR . $fileName;
    if (!@copy($src, $local)) { return null; }
    $pdf = eg_att_soffice_convert($local, $tmpOut);
    if (!$pdf) {
        $pdf = eg_att_fallback_convert($local, strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), $tmpOut);
    }
    $ok = ($pdf && @copy($pdf, $cache));
    // 清本機暫存（盡力而為）
    @unlink($local); if ($pdf) @unlink($pdf); @rmdir($tmpOut);
    return $ok ? $cache : null;
}

/**
 * 版本號格式一致性檢查：新版本號需與此文件既有版本的「型式」一致。
 * 數字型（0.1 / 1.0 / 2.3.1）與英文字母型（A / B / C）不可混用；
 * 既有版本全為數字型 → 新版本必須是數字型，反之亦然（既有為空或混合型則不限制）。
 * $excludeVerId：修正現有版本號時排除自己。合法回 null，不合法回錯誤訊息。
 */
function asValidateVersionStyle(PDO $db, int $docId, string $newVersion, int $excludeVerId = 0): ?string {
    $st = $db->prepare("SELECT version FROM as_document_version WHERE doc_id=? AND id!=?");
    $st->execute([$docId, $excludeVerId]);
    // 表單首建可無版本號（空字串）——空版本不列入型式判斷
    $existing = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn($v) => trim((string)$v) !== ''));
    if (empty($existing)) return null;
    $isNum   = fn(string $v) => (bool)preg_match('/^\d+(\.\d+)*$/', trim($v));
    // 字母型兩種並行：A/B/C… 與 A-1/A-2…（字母加流水修訂號）
    $isAlpha = fn(string $v) => (bool)preg_match('/^[A-Za-z]+(-\d+)?$/', trim($v));
    $allNum   = count(array_filter($existing, $isNum))   === count($existing);
    $allAlpha = count(array_filter($existing, $isAlpha)) === count($existing);
    if ($allNum && !$isNum($newVersion))
        return "此文件版本號皆為數字型（如 " . implode('、', array_slice($existing, -3)) . "），新版本「{$newVersion}」格式不一致，請沿用數字型（如 " . end($existing) . " 之後的下一版）";
    if ($allAlpha && !$isAlpha($newVersion))
        return "此文件版本號皆為英文字母型（如 " . implode('、', array_slice($existing, -3)) . "），新版本「{$newVersion}」格式不一致，請沿用字母型";
    return null;
}

/**
 * 版本號大小比較：回 -1（$a 舊於 $b）／0（相同）／1（$a 新於 $b）；型式不同或無法比較回 null。
 * 數字型：各段依數值比較（1.10 > 1.2）；字母型：先比字母長度再字典序，同字母再比修訂號
 * （順序：A < A-1 < A-2 < B < B-1 < … < Z < AA）。改版檢查與「補舊版次」共用這支，避免兩套規則。
 */
function asVersionCmp(string $a, string $b): ?int {
    $a = trim($a); $b = trim($b);
    if ($a === '' || $b === '') return null;
    $numRe = '/^\d+(\.\d+)*$/'; $alphaRe = '/^([A-Za-z]+)(?:-(\d+))?$/';
    if (preg_match($numRe, $a) && preg_match($numRe, $b)) {
        $x = array_map('intval', explode('.', $a));
        $y = array_map('intval', explode('.', $b));
        for ($i = 0; $i < max(count($x), count($y)); $i++) {
            $c = ($x[$i] ?? 0) <=> ($y[$i] ?? 0);
            if ($c !== 0) return $c;
        }
        return 0;
    }
    if (preg_match($alphaRe, $a, $ma) && preg_match($alphaRe, $b, $mb)) {
        $la = strtoupper($ma[1]); $lb = strtoupper($mb[1]);
        $cmp = (strlen($la) !== strlen($lb)) ? (strlen($la) <=> strlen($lb)) : strcmp($la, $lb);
        if ($cmp === 0) $cmp = ((int)($ma[2] ?? 0)) <=> ((int)($mb[2] ?? 0));
        return $cmp <=> 0;
    }
    return null;
}

/**
 * 版本號新舊比較：改版的新版本號必須「大於」此文件現行版本（B 版之後不可改成 A 版、1.2 之後不可改成 0.3）。
 * 型式不同或無法比較時回 null（交由型式一致性檢查把關）。合法回 null，不合法回錯誤訊息。
 * 註：要補「比目前版本舊」的歷史紀錄請走 add_old_versions_batch（補舊版次），不要放寬這支。
 */
function asValidateVersionOrder(string $current, string $newVersion): ?string {
    $current = trim($current); $newVersion = trim($newVersion);
    if ($current === '') return null;
    $c = asVersionCmp($newVersion, $current);
    if ($c === null || $c > 0) return null;
    if ($c === 0) return "新版本號 {$newVersion} 與目前版本 {$current} 相同"; // 理論上被重複檢查先擋，保險
    if (preg_match('/^\d+(\.\d+)*$/', $current))
        return "新版本號 {$newVersion} 比目前版本 {$current} 舊，改版版本號必須往後推進";
    return "新版本號 {$newVersion} 未比目前版本 {$current} 新，改版版本號必須往後推進（如 {$current} 之後可用下一字母或加修訂號，例：A→A-1→B）";
}

/**
 * 換編號連動：把 $parentId 底下所有子孫文件的編號前綴，由「$oldNo-」改成「$newNo-」（遞迴）。
 * 只換「以舊母文件編號＋連字號」為開頭的子文件（如母 2-MM-01 → 子 2-MM-01-01）。回傳實際更新筆數。
 * $newDept 非 null 時：連同把換到編號的子文件所屬部門也改成 $newDept（換負責部門情境）。
 * 需在呼叫端的 transaction 內執行；碰到編號衝突丟 Exception 讓整批回滾。
 */
function asCascadeRenumber(PDO $db, int $parentId, string $oldNo, string $newNo, ?int $newDept = null): int {
    $st = $db->prepare("SELECT id, doc_no FROM as_document WHERE parent_doc_id=? AND is_deleted=0");
    $st->execute([$parentId]);
    $children = $st->fetchAll(PDO::FETCH_ASSOC);
    $cnt = 0;
    $updNo     = $db->prepare("UPDATE as_document SET doc_no=?, updated_at=NOW() WHERE id=?");
    $updNoDept = $db->prepare("UPDATE as_document SET doc_no=?, department_id=?, updated_at=NOW() WHERE id=?");
    $dupChk = $db->prepare("SELECT COUNT(*) FROM as_document WHERE doc_no=? AND is_deleted=0 AND id!=?");
    foreach ($children as $c) {
        $childOld = (string)$c['doc_no'];
        $childNew = $childOld;
        // 子編號＝舊母編號＋「-…」→ 換成新母編號＋「-…」；不符前綴者不動（仍遞迴其子孫）
        if (strpos($childOld, $oldNo . '-') === 0) {
            $childNew = $newNo . substr($childOld, strlen($oldNo));
            $dupChk->execute([$childNew, (int)$c['id']]);
            if ($dupChk->fetchColumn() > 0) throw new Exception("連動換編號衝突：{$childNew} 已被其他文件使用");
            if ($newDept !== null) $updNoDept->execute([$childNew, $newDept, (int)$c['id']]);
            else                   $updNo->execute([$childNew, (int)$c['id']]);
            $cnt++;
        }
        // 遞迴處理孫層（以「該子文件」的舊→新編號為前綴）
        $cnt += asCascadeRenumber($db, (int)$c['id'], $childOld, $childNew, $newDept);
    }
    return $cnt;
}

/** 母文件驗證：表單/四階/已刪除的文件不可作為母文件。合法回 null，不合法回錯誤訊息。 */
function asValidateParent(PDO $db, ?int $parentId): ?string {
    if ($parentId === null || $parentId <= 0) return null;
    $st = $db->prepare("SELECT doc_no, doc_type, doc_level, is_deleted FROM as_document WHERE id=?");
    $st->execute([$parentId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return '母文件不存在';
    if ((int)$p['is_deleted'] === 1) return '母文件已被刪除，不可選用';
    if (($p['doc_type'] ?? '') === '表單' || ($p['doc_level'] ?? '') === '四階') {
        return "「{$p['doc_no']}」是表單/四階文件，不可作為母文件（母文件應為程序書/標準書等上階文件）";
    }
    return null;
}

$action = $_REQUEST['action'] ?? '';

// 各 action 所需能力
// download 不在此表：於 case 內依 inline 分流（inline 預覽=view；下載原檔=update，「有修改權限的人才可下載」）
$asGate = [
    'list_tags'=>'view', 'meta'=>'view', 'list_documents'=>'view', 'get_document'=>'view',
    'suggest_doc_no'=>'view', 'hashtag_list'=>'view',
    'download_template'=>'update',
    'create_document'=>'create', 'create_documents_batch'=>'create',
    'add_version'=>'update', 'update_document_meta'=>'update',
    'open_online'=>'edit_online',
    'delete_document'=>'delete', 'restore_document'=>'delete', 'delete_document_permanent'=>'delete',
    'delete_version_permanent'=>'delete',
    'add_tag'=>'settings', 'update_tag'=>'settings', 'delete_tag'=>'settings',
    'get_perms'=>'settings', 'save_perms'=>'settings',
    'get_settings'=>'settings', 'save_settings'=>'settings', 'upload_template'=>'settings',
    'tree_print_meta'=>'view', 'save_tree_as_doc'=>'settings', 'tree_signers'=>'view',
    'save_tree_auto'=>'settings', 'tree_submit_approval'=>'update',
    'tree_editor_terms'=>'view', 'save_tree_editor_terms'=>'settings',
    // tree_approval_decide 於 case 內檢查（核准人本人／管理員），核准人不一定有 AS 文件角色
    'save_dept_codes'=>'settings',
    'form_records_list'=>'view', 'form_records_upload'=>'upload_record', 'form_record_delete'=>'delete',
    'set_linked_module'=>'settings',
    'phrase_add'=>'update', 'phrase_delete'=>'update',
    'version_attach_file'=>'update', 'docs_add_tags'=>'update',
    // add_versions_batch 於 case 內另行檢查（僅限管理員）
    // form_record_download 於 case 內依 inline 分流（預覽=view / 原檔=download）
];
if (!$currentUserId) {
    if ($action === 'download' || $action === 'download_template') { http_response_code(403); exit('尚未登入'); }
    jout(['status'=>'error','message'=>'尚未登入']);
}
if (isset($asGate[$action]) && !asCan($asGate[$action])) {
    if ($action === 'download' || $action === 'download_template') { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('無權限'); }
    jout(['status'=>'error','message'=>'無權限執行此操作（請至權限設定頁指派 AS 文件管理角色或頁面權限）']);
}

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
    $depts = $db->query("SELECT id, name, level FROM department ORDER BY sort_order ASC, level, name")->fetchAll(PDO::FETCH_ASSOC);
    $deptCodes = $db->query("SELECT id, department_id, code, label FROM as_dept_code ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $poss  = $db->query("SELECT p.id,p.name,pl.level FROM position p LEFT JOIN position_level pl ON p.id=pl.position_id ORDER BY p.sort_order ASC, p.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $tags  = $db->query("SELECT id,name,color FROM as_doc_tag ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $users = $db->query("SELECT id,user_cname FROM user WHERE state IN (1,90,99) OR state IS NULL ORDER BY user_cname")->fetchAll(PDO::FETCH_ASSOC);
    // 母文件候選：排除表單/四階（表單不可再當母文件）；帶 department_id 供前端自動帶入所屬部門
    $parents = $db->query("SELECT id, doc_no, doc_name, department_id FROM as_document
                           WHERE is_deleted=0
                             AND COALESCE(doc_type,'') != '表單'
                             AND COALESCE(doc_level,'') != '四階'
                           ORDER BY doc_no")->fetchAll(PDO::FETCH_ASSOC);
    // 有文件的部門（供列表篩選下拉，只列出有資料者）
    $deptsWithDocs = $db->query("SELECT DISTINCT department_id FROM as_document WHERE is_deleted=0 AND department_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    // 制修訂頁次/摘要 常用文字
    $phrases = $db->query("SELECT id, field, phrase FROM as_doc_phrase ORDER BY field, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    jout(['status'=>'success','departments'=>$depts,'dept_codes'=>$deptCodes,'depts_with_docs'=>array_map('intval',$deptsWithDocs),'positions'=>$poss,'tags'=>$tags,'users'=>$users,'parents'=>$parents,'phrases'=>$phrases]);

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
    if ($kw !== '')   {
        // 搜尋範圍：文件編號/名稱＋底下紀錄(附件)的標題/備註（備註可打 #標籤 供搜尋）
        $where[] = "(d.doc_no LIKE ? OR d.doc_name LIKE ?
                     OR EXISTS (SELECT 1 FROM as_form_record fr2
                                WHERE fr2.form_doc_id = d.id AND fr2.is_deleted = 0
                                  AND (fr2.title LIKE ? OR fr2.note LIKE ?)))";
        $params[]="%$kw%"; $params[]="%$kw%"; $params[]="%$kw%"; $params[]="%$kw%";
    }
    if ($level!=='')  { $where[] = "d.doc_level = ?"; $params[]=$level; }
    if ($dept!=='')   { $where[] = "d.department_id = ?"; $params[]=(int)$dept; }
    if ($tag>0)       { $where[] = "d.id IN (SELECT doc_id FROM as_doc_tag_map WHERE tag_id = ?)"; $params[]=$tag; }
    $parentId = (int)($_GET['parent_id'] ?? 0);
    if ($parentId>0)  { $where[] = "d.parent_doc_id = ?"; $params[]=$parentId; }
    $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

    $sql = "SELECT d.*, dep.name AS dept_name,
                   v.revised_date, v.change_status, v.file_name AS current_file_name,
                   pd.doc_no AS parent_doc_no, pd.doc_name AS parent_doc_name,
                   (SELECT COUNT(*) FROM as_document c WHERE c.parent_doc_id = d.id AND c.is_deleted = 0) AS children_count,
                   (SELECT COUNT(*) FROM as_form_record fr WHERE fr.form_doc_id = d.id AND fr.is_deleted = 0) AS record_count,
                   rt.id AS rvf_tpl_id, rt.name AS rvf_tpl_name
            FROM as_document d
            LEFT JOIN department dep ON dep.id = d.department_id
            LEFT JOIN as_document_version v ON v.id = d.current_version_id
            LEFT JOIN as_document pd ON pd.id = d.parent_doc_id
            LEFT JOIN system_parameters rvfsp ON rvfsp.param_group='AS_DOC_BIND' AND rvfsp.param_key LIKE 'review_form_tpl\\_%' AND rvfsp.param_value = d.id
            LEFT JOIN rf_template rt ON rt.id = CAST(SUBSTRING(rvfsp.param_key, 17) AS UNSIGNED) AND rt.status='active'
            $wsql
            ORDER BY d.doc_no ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 關鍵字有命中「紀錄/附件」時，隨列回傳命中的附件明細（清單直接顯示，免點開跳窗）
    if ($docs && $kw !== '') {
        $ids = array_column($docs,'id');
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $mr = $db->prepare("SELECT id, form_doc_id, title, record_date, note
                            FROM as_form_record
                            WHERE form_doc_id IN ($ph) AND is_deleted=0 AND (title LIKE ? OR note LIKE ?)
                            ORDER BY record_date DESC, id DESC");
        $mr->execute(array_merge($ids, ["%$kw%", "%$kw%"]));
        $byDocR = [];
        foreach ($mr->fetchAll(PDO::FETCH_ASSOC) as $r) { $byDocR[$r['form_doc_id']][] = $r; }
        foreach ($docs as &$d) { $d['matched_records'] = $byDocR[$d['id']] ?? []; }
        unset($d);
    }

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
    $st = $db->prepare("SELECT d.*, dep.name AS dept_name, pd.doc_no AS parent_doc_no, pd.doc_name AS parent_doc_name
                        FROM as_document d
                        LEFT JOIN department dep ON dep.id=d.department_id
                        LEFT JOIN as_document pd ON pd.id=d.parent_doc_id
                        WHERE d.id=?");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    $ch = $db->prepare("SELECT id, doc_no, doc_name, current_version FROM as_document WHERE parent_doc_id=? AND is_deleted=0 ORDER BY doc_no");
    $ch->execute([$id]);
    $doc['children'] = $ch->fetchAll(PDO::FETCH_ASSOC);

    $vs = $db->prepare("SELECT v.*, dep.name AS dept_name_snapshot FROM as_document_version v LEFT JOIN department dep ON dep.id=v.department_id_snapshot WHERE v.doc_id=? ORDER BY v.revised_date DESC, v.id DESC");
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
    $parent  = ($_POST['parent_doc_id'] ?? '')!=='' ? (int)$_POST['parent_doc_id'] : null;
    $version = asNormVer($_POST['version'] ?? '');
    $rdate   = trim($_POST['revised_date'] ?? '') ?: null;
    $rpages  = trim($_POST['revised_pages'] ?? '') ?: null;
    $rsum    = trim($_POST['revised_summary'] ?? '') ?: null;
    $cstat   = trim($_POST['change_status'] ?? '制訂');
    $tagIds  = array_filter(array_map('intval', explode(',', $_POST['tag_ids'] ?? '')));

    if ($doc_no==='' || $doc_name==='')
        jout(['status'=>'error','message'=>'文件編號、名稱為必填']);
    // 表單首建可無版本號（改版才給號 A / A-1…）；其他類別維持必填
    if ($version==='' && $doc_type!=='表單')
        jout(['status'=>'error','message'=>'版本號為必填（僅表單類別首次建立可不填）']);
    if (!$rdate) jout(['status'=>'error','message'=>'請填寫修訂日期']);
    $dup = $db->prepare("SELECT COUNT(*) FROM as_document WHERE doc_no=? AND is_deleted=0");
    $dup->execute([$doc_no]);
    if ($dup->fetchColumn() > 0) jout(['status'=>'error','message'=>"文件編號 {$doc_no} 已存在"]);
    if ($pErr = asValidateParent($db, $parent)) jout(['status'=>'error','message'=>$pErr]);
    $hasFile = isset($_FILES['file']) && $_FILES['file']['error']===UPLOAD_ERR_OK;
    if (!$hasFile && !$asNoAttach)
        jout(['status'=>'error','message'=>'請上傳文件檔（如需補登舊資料免附件，請先取得「補登免附件」角色）']);
    $ext = null;
    if ($hasFile) {
        $ext = asSafeExt($_FILES['file']['name']);
        if (!$ext) jout(['status'=>'error','message'=>'不允許此文件類型']);
    }
    if (asDocRoot($db)==='') jout(['status'=>'error','message'=>'尚未設定 NAS 儲存路徑（請至系統設定）']);

    // 申請單（首版可選）
    $hasApply = isset($_FILES['apply_form']) && $_FILES['apply_form']['error']===UPLOAD_ERR_OK;
    $applyExt = null;
    if ($hasApply) { $applyExt = asSafeExt($_FILES['apply_form']['name']); if(!$applyExt) jout(['status'=>'error','message'=>'申請單檔案類型不允許']); }

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,department_id,parent_doc_id,current_version,created_by,created_at,updated_at)
                      VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
           ->execute([$doc_no,$doc_name,$doc_type,$level,$dept,$parent,$version,$GLOBALS['currentCname']]);
        $docId = (int)$db->lastInsertId();

        $dir = asDocDir($db, $docId);
        if (($hasFile || $hasApply) && !is_dir($dir) && !mkdir($dir, 0777, true)) throw new Exception('無法建立資料夾（NAS 未連線？）');

        $fname = null; $orig = null;
        if ($hasFile) {
            $fname = asMakeName($ext);
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception('文件寫入失敗');
            $orig = basename($_FILES['file']['name']);
        }

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
    $version= asNormVer($_POST['version'] ?? '');
    $rdate  = trim($_POST['revised_date'] ?? '') ?: null;
    $rpages = trim($_POST['revised_pages'] ?? '') ?: null;
    $rsum   = trim($_POST['revised_summary'] ?? '') ?: null;
    $cstat  = trim($_POST['change_status'] ?? '修正');
    if ($docId<=0 || $version==='') jout(['status'=>'error','message'=>'文件與版本號為必填']);
    if (!$rdate) jout(['status'=>'error','message'=>'請填寫修訂日期']);

    $st = $db->prepare("SELECT * FROM as_document WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    // 新版本號不可與此文件任何既有版本相同（含歷史版本，避免版本紀錄混淆）
    $vdup = $db->prepare("SELECT COUNT(*) FROM as_document_version WHERE doc_id=? AND version=?");
    $vdup->execute([$docId, $version]);
    if ($vdup->fetchColumn() > 0) jout(['status'=>'error','message'=>"版本號 {$version} 已存在於此文件的版本紀錄（含歷史版本），請使用新的版本號"]);
    // 版本號型式須與既有版本一致（數字型/字母型不可混用）
    if ($vErr = asValidateVersionStyle($db, $docId, $version)) jout(['status'=>'error','message'=>$vErr]);
    // 改版版本號必須比目前版本新（不可倒退）
    if ($vErr = asValidateVersionOrder((string)($doc['current_version'] ?? ''), $version)) jout(['status'=>'error','message'=>$vErr]);

    $hasFile  = isset($_FILES['file']) && $_FILES['file']['error']===UPLOAD_ERR_OK;
    $hasApply = isset($_FILES['apply_form']) && $_FILES['apply_form']['error']===UPLOAD_ERR_OK;
    if (!$hasFile && !$asNoAttach)
        jout(['status'=>'error','message'=>'請上傳新版文件檔']);
    // 改版一律需附「文件制修申請單(附件一)」；「補登免附件」角色豁免（補舊資料用）
    if (!$hasApply && !$asNoAttach)
        jout(['status'=>'error','message'=>'改版必須一併上傳「文件制修申請單」(附件一)']);
    $ext = null; $applyExt = null;
    if ($hasFile) {
        $ext = asSafeExt($_FILES['file']['name']);
        if (!$ext) jout(['status'=>'error','message'=>'不允許此文件類型']);
    }
    if ($hasApply) {
        $applyExt = asSafeExt($_FILES['apply_form']['name']);
        if (!$applyExt) jout(['status'=>'error','message'=>'申請單檔案類型不允許']);
    }

    $db->beginTransaction();
    try {
        $dir = asDocDir($db, $docId);
        if (($hasFile || $hasApply) && !is_dir($dir) && !mkdir($dir, 0777, true)) throw new Exception('無法建立資料夾（NAS 未連線？）');

        $fname = null; $orig = null;
        if ($hasFile) {
            $fname = asMakeName($ext);
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception('文件寫入失敗');
            $orig = basename($_FILES['file']['name']);
        }

        $applyName = null; $applyOrig = null;
        if ($hasApply) {
            $applyName = asMakeName($applyExt);
            if (!move_uploaded_file($_FILES['apply_form']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$applyName)) throw new Exception('申請單寫入失敗');
            $applyOrig = basename($_FILES['apply_form']['name']);
        }

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
    $parent  = ($_POST['parent_doc_id'] ?? '')!=='' ? (int)$_POST['parent_doc_id'] : null;
    $tagIds  = array_filter(array_map('intval', explode(',', $_POST['tag_ids'] ?? '')));
    if ($id<=0 || $doc_no==='' || $doc_name==='') jout(['status'=>'error','message'=>'資料不完整']);
    if ($parent === $id) $parent = null; // 不可自己當自己的母文件
    $dup = $db->prepare("SELECT COUNT(*) FROM as_document WHERE doc_no=? AND is_deleted=0 AND id!=?");
    $dup->execute([$doc_no, $id]);
    if ($dup->fetchColumn() > 0) jout(['status'=>'error','message'=>"文件編號 {$doc_no} 已被其他文件使用"]);
    if ($pErr = asValidateParent($db, $parent)) jout(['status'=>'error','message'=>$pErr]);

    // 目前版本資訊修正（誤植修正用，不產生新版本）
    $version = asNormVer($_POST['version'] ?? '');
    $cstat   = trim($_POST['change_status'] ?? '');
    $rdate   = trim($_POST['revised_date'] ?? '') ?: null;
    if ($version !== '' && !$rdate) jout(['status'=>'error','message'=>'請填寫修訂日期']);
    $rpages  = trim($_POST['revised_pages'] ?? '') ?: null;
    $rsum    = trim($_POST['revised_summary'] ?? '') ?: null;

    // 取更新前的編號（供子表單編號連動用）
    $oldNoStmt = $db->prepare("SELECT doc_no FROM as_document WHERE id=?");
    $oldNoStmt->execute([$id]);
    $oldNo = (string)$oldNoStmt->fetchColumn();
    $cascade = ($_POST['cascade_children'] ?? '') === '1';

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE as_document SET doc_no=?,doc_name=?,doc_type=?,doc_level=?,department_id=?,parent_doc_id=?,updated_at=NOW() WHERE id=?")
           ->execute([$doc_no,$doc_name,$doc_type,$level,$dept,$parent,$id]);

        // 換編號時連動更新底下表單編號：舊前綴「oldNo-」換成「新編號-」（含遞迴子孫）；
        // cascade_dept=1 時子文件所屬部門一併改成本文件的新部門（換負責部門情境）
        $cascadeCnt = 0;
        if ($cascade && $oldNo !== '' && $oldNo !== $doc_no) {
            $cascadeDept = (($_POST['cascade_dept'] ?? '') === '1') ? $dept : null;
            $cascadeCnt = asCascadeRenumber($db, $id, $oldNo, $doc_no, $cascadeDept);
        }

        if ($version !== '') {
            $cvId = $db->prepare("SELECT current_version_id FROM as_document WHERE id=?");
            $cvId->execute([$id]);
            $cvId = (int)$cvId->fetchColumn();
            if ($cvId) {
                // 修正後的版本號不可與此文件其他（歷史）版本相同，且型式須一致
                $vdup = $db->prepare("SELECT COUNT(*) FROM as_document_version WHERE doc_id=? AND version=? AND id!=?");
                $vdup->execute([$id, $version, $cvId]);
                if ($vdup->fetchColumn() > 0) throw new Exception("版本號 {$version} 已存在於此文件的歷史版本，請使用其他版本號");
                if ($vErr = asValidateVersionStyle($db, $id, $version, $cvId)) throw new Exception($vErr);
                $db->prepare("UPDATE as_document_version SET version=?, change_status=?, revised_date=?, revised_pages=?, revised_summary=?, doc_level_snapshot=?, department_id_snapshot=? WHERE id=?")
                   ->execute([$version, ($cstat ?: null), $rdate, $rpages, $rsum, $level, $dept, $cvId]);
                $db->prepare("UPDATE as_document SET current_version=? WHERE id=?")->execute([$version, $id]);
            }
        }
        $db->prepare("DELETE FROM as_doc_tag_map WHERE doc_id=?")->execute([$id]);
        if ($tagIds) { $ins=$db->prepare("INSERT IGNORE INTO as_doc_tag_map (doc_id,tag_id) VALUES (?,?)"); foreach($tagIds as $t) $ins->execute([$id,$t]); }
        $db->commit();
        jout(['status'=>'success','cascade_renumbered'=>$cascadeCnt]);
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

// ══════════════ 永久刪除（含改版紀錄與檔案；僅超級管理員，用於傳錯文件） ══════════════
case 'delete_document_permanent':
    $isSuper = ($currentUserId === 1 && $asIsRoleAdmin);
    if (!$isSuper) jout(['status'=>'error','message'=>'僅超級管理員可永久刪除文件']);
    $id  = (int)($_POST['id'] ?? 0);
    $pwd = (string)($_POST['password'] ?? '');
    if ($id<=0) jout(['status'=>'error','message'=>'無效 ID']);
    if ($pwd==='') jout(['status'=>'error','message'=>'請輸入超級管理員密碼']);
    $realPwd = $db->prepare("SELECT user_password FROM `user` WHERE id=1");
    $realPwd->execute();
    $realPwd = $realPwd->fetchColumn();
    if ($realPwd===false || !hash_equals((string)$realPwd, $pwd)) jout(['status'=>'error','message'=>'密碼錯誤，未執行刪除']);

    $doc = $db->prepare("SELECT doc_no, doc_name FROM as_document WHERE id=?");
    $doc->execute([$id]);
    $doc = $doc->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    $chk = function(string $sql, string $msg) use ($db,$id) {
        $s = $db->prepare($sql); $s->execute([$id]);
        if ($s->fetchColumn() > 0) jout(['status'=>'error','message'=>$msg]);
    };
    $chk("SELECT COUNT(*) FROM as_document WHERE parent_doc_id=? AND is_deleted=0", '此文件仍有子文件（表單），請先處理子文件');
    $chk("SELECT COUNT(*) FROM as_form_template WHERE form_doc_id=? AND is_deleted=0", '此文件已綁定線上表單設計，請先於表單設計器刪除該設計');
    $chk("SELECT COUNT(*) FROM as_form_record WHERE form_doc_id=? AND is_deleted=0", '此文件仍有填寫紀錄／附件，請先刪除');
    $chk("SELECT COUNT(*) FROM as_form_instance WHERE form_doc_id=? AND is_deleted=0", '此文件仍有線上表單填寫紀錄，請先處理');
    $bindChk = $db->prepare("SELECT param_key FROM system_parameters WHERE param_group='AS_DOC_BIND' AND param_value=? LIMIT 1");
    $bindChk->execute([(string)$id]);
    if ($bindKey = $bindChk->fetchColumn()) jout(['status'=>'error','message'=>"此文件已被其他模組（{$bindKey}）綁定為 AS 文件依據，請先解除綁定"]);

    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM as_doc_tag_map WHERE doc_id=?")->execute([$id]);
        $db->prepare("DELETE FROM as_doc_perm WHERE doc_id=?")->execute([$id]);
        $db->prepare("DELETE FROM as_document_version WHERE doc_id=?")->execute([$id]);
        $db->prepare("DELETE FROM as_document WHERE id=?")->execute([$id]);
        $db->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                      VALUES ('views/ADM/as_document_management.php', ?, ?, NOW(), ?)")
           ->execute(['永久刪除文件（傳錯文件）', "doc_no={$doc['doc_no']} doc_name={$doc['doc_name']}", $currentCname ?: $currentUserName]);
        $db->commit();
    } catch (Exception $e) { $db->rollBack(); jout(['status'=>'error','message'=>$e->getMessage()]); }

    require_once __DIR__ . '/../common/attachment_lib.php';
    eg_att_rrmdir(asDocDir($db, $id));
    jout(['status'=>'success']);

// ══════════════ 永久刪除單一改版紀錄（含附件；僅超級管理員，用於傳錯版本） ══════════════
case 'delete_version_permanent':
    $isSuper = ($currentUserId === 1 && $asIsRoleAdmin);
    if (!$isSuper) jout(['status'=>'error','message'=>'僅超級管理員可永久刪除改版紀錄']);
    $verId = (int)($_POST['version_id'] ?? 0);
    $pwd   = (string)($_POST['password'] ?? '');
    if ($verId<=0) jout(['status'=>'error','message'=>'無效版本 ID']);
    if ($pwd==='') jout(['status'=>'error','message'=>'請輸入超級管理員密碼']);
    $realPwd = $db->prepare("SELECT user_password FROM `user` WHERE id=1");
    $realPwd->execute();
    $realPwd = $realPwd->fetchColumn();
    if ($realPwd===false || !hash_equals((string)$realPwd, $pwd)) jout(['status'=>'error','message'=>'密碼錯誤，未執行刪除']);

    $ver = $db->prepare("SELECT * FROM as_document_version WHERE id=?");
    $ver->execute([$verId]);
    $ver = $ver->fetch(PDO::FETCH_ASSOC);
    if (!$ver) jout(['status'=>'error','message'=>'此改版紀錄不存在']);
    $docId = (int)$ver['doc_id'];

    $doc = $db->prepare("SELECT doc_no, doc_name, current_version_id FROM as_document WHERE id=?");
    $doc->execute([$docId]);
    $doc = $doc->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM as_document_version WHERE id=?")->execute([$verId]);
        // 若刪的正好是目前版本，改指向剩餘版本中最新建立的一筆(沒有則清空目前版本)
        if ((int)$doc['current_version_id'] === $verId) {
            $next = $db->prepare("SELECT id, version FROM as_document_version WHERE doc_id=? ORDER BY id DESC LIMIT 1");
            $next->execute([$docId]);
            $next = $next->fetch(PDO::FETCH_ASSOC);
            $db->prepare("UPDATE as_document SET current_version=?, current_version_id=?, updated_at=NOW() WHERE id=?")
               ->execute([$next['version'] ?? null, $next['id'] ?? null, $docId]);
        }
        $db->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                      VALUES ('views/ADM/as_document_management.php', ?, ?, NOW(), ?)")
           ->execute(['永久刪除改版紀錄（傳錯版本）',
               "doc_no={$doc['doc_no']} doc_name={$doc['doc_name']} version={$ver['version']}",
               $currentCname ?: $currentUserName]);
        $db->commit();
    } catch (Exception $e) { $db->rollBack(); jout(['status'=>'error','message'=>$e->getMessage()]); }

    // 刪該版本自己的檔案(文件檔/申請單/預覽快取)，同資料夾內其他版本檔名不同不受影響
    $dir = asDocDir($db, $docId);
    foreach ([$ver['file_name'], $ver['apply_form_file_name']] as $fn) {
        if (!$fn) continue;
        @unlink($dir . DIRECTORY_SEPARATOR . $fn);
        @unlink($dir . DIRECTORY_SEPARATOR . 'preview_' . pathinfo($fn, PATHINFO_FILENAME) . '.pdf');
    }
    jout(['status'=>'success']);

// ══════════════ 下載：某版本文件 / 申請單 / 目前版 ══════════════
case 'download':
    $verId = (int)($_GET['version_id'] ?? 0);
    $which = $_GET['which'] ?? 'file'; // file | apply
    if ($verId<=0) { http_response_code(400); exit('bad request'); }
    $st = $db->prepare("SELECT v.*, d.doc_no, d.doc_name FROM as_document_version v JOIN as_document d ON d.id=v.doc_id WHERE v.id=?");
    $st->execute([$verId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) { http_response_code(404); exit('版本不存在'); }
    $dir = asDocDir($db, (int)$v['doc_id']);
    $inline = (($_GET['inline'] ?? '') === '1');
    // 權限分流：inline 預覽=檢閱權；下載原檔=獨立「下載」權限（asdoc_download）
    if (!asCan($inline ? 'view' : 'download')) {
        http_response_code(403); header('Content-Type: text/plain; charset=utf-8');
        exit($inline ? '無檢閱權限' : '無下載權限（下載原檔需「下載」權限，檢閱者請用線上預覽）');
    }
    $fname = ($which==='apply') ? $v['apply_form_file_name'] : $v['file_name'];
    $oname = ($which==='apply') ? ($v['apply_form_original_name'] ?: $v['apply_form_file_name'])
                                : ($v['original_name'] ?: $v['file_name']);
    if ($which==='apply' && !$fname) { http_response_code(404); exit('此版本無申請單'); }
    if (!$fname) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('此版本未上傳文件檔（補登資料，可用「改版」補上檔案）'); }
    // 下載檔名一律改為「文件編號 文件名稱-版次」，副檔名沿用原檔（僅套用於文件本檔，申請單維持原檔名）
    if ($which !== 'apply') {
        $ext = pathinfo($oname, PATHINFO_EXTENSION);
        $base = trim(($v['doc_no'] ?? '') . ' ' . ($v['doc_name'] ?? ''));
        $ver  = trim((string)($v['version'] ?? ''));
        if ($base !== '') {
            $oname = $base . ($ver !== '' ? ('-' . $ver) : '') . ($ext !== '' ? ('.' . $ext) : '');
            $oname = preg_replace('/[\\\\\/:*?"<>|]/', '_', $oname);
        }
    }
    // 線上預覽：Office 檔先轉 PDF 快取再 inline 串流
    if ($inline) {
        $fext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if (in_array($fext, ['doc','docx','xls','xlsx','ppt','pptx','odt','ods'], true)) {
            $cache = asPreviewPdf($db, (int)$v['doc_id'], $fname);
            if ($cache) {
                asStream($cache, preg_replace('/\.[^.]+$/', '', $oname) . '.pdf', true);
            }
            // 轉檔失敗：有下載權限者落回下載原檔；純檢閱者回錯誤（不可經預覽管道取得原檔）
            if (!asCan('download')) {
                http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
                exit('預覽產生失敗（轉檔錯誤），請聯絡文管人員');
            }
        }
    }
    asStream($dir.DIRECTORY_SEPARATOR.$fname, $oname, $inline);
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
// ══════════════ 結構總覽列印：公司全名＋綁定的 AS 文件編號 ══════════════
// 依 ai-rules/16：大標題＝本公司全名（customer_list.is_own_company=1，禁寫死）、
// 表頭＝已綁定 AS 文件的「表單名稱」、頁尾右下＝該文件編號
case 'tree_print_meta':
    $pendId = (int)asGetSetting($db,'as_doc_tree_pending_id');
    $pending = null;
    if ($pendId > 0) {
        require_once __DIR__ . '/../common/approval_lib.php';
        $rec = eg_approval_latest($db, 'as_doc_tree', 0, 'approve');
        if ($rec && $rec['status']==='pending') $pending = ['id'=>(int)$rec['id'],'submitted_at'=>substr((string)$rec['submitted_at'],0,16),'submitted_by_name'=>$rec['submitted_by_name']];
        else asSetSetting($db,'as_doc_tree_pending_id','0');
    }
    jout(['status'=>'success',
          'company_name'=>asOwnCompanyName($db),
          'as_doc'=>asTreeBoundDoc($db),
          'auto_approve'=>asGetSetting($db,'as_doc_tree_auto_approve')==='1',
          'approved'=>json_decode(asGetSetting($db,'as_doc_tree_approved') ?: 'null', true),
          'pending'=>$pending,
          'as_docs'=>$db->query("SELECT id, doc_no, doc_name FROM as_document WHERE is_deleted=0 ORDER BY doc_no")->fetchAll(PDO::FETCH_ASSOC)]);

case 'save_tree_auto':
    asSetSetting($db, 'as_doc_tree_auto_approve', ($_POST['auto'] ?? '0')==='1' ? '1' : '0');
    jout(['status'=>'success']);

// 送出審核（未開「自動核可」時）：留 approval_record＋發待簽核通知給最高核准人員（ai-rules/17）
case 'tree_submit_approval':
    require_once __DIR__ . '/../common/approval_lib.php';
    require_once __DIR__ . '/../common/org_role_lib.php';
    $pages = json_decode((string)($_POST['pages'] ?? '[]'), true);
    if (!is_array($pages) || !$pages) jout(['status'=>'error','message'=>'沒有可送審的內容']);
    // 各階的修改簽章人可能不同（依任期回推），逐頁解析後存進快照；任期沒涵蓋的日期用畫面手選的人遞補
    $terms  = asEditorTerms($db);
    $fbId   = (int)($_POST['editor_id'] ?? 0);
    $fbName = '';
    if ($fbId) { $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $st->execute([$fbId]); $fbName = (string)($st->fetchColumn() ?: ''); }
    foreach ($pages as $i => $p) {
        $e = asEditorOfDate($terms, (string)($p['date'] ?? ''));
        $pages[$i]['editor_id']   = $e ? $e['id']   : $fbId;
        $pages[$i]['editor_name'] = $e ? $e['name'] : $fbName;
        if ($pages[$i]['editor_name'] === '')
            jout(['status'=>'error','message'=>($p['level'] ?? '').'（'.($p['date'] ?? '').'）沒有對應的修改簽章人員，請設定任期或於下拉選擇']);
    }
    $edId = (int)$pages[0]['editor_id']; $edName = (string)$pages[0]['editor_name'];   // 相容舊快照欄位
    $ap = eg_org_user($db, 'top_approver');
    if (!$ap) jout(['status'=>'error','message'=>'尚未綁定「最高核准人員」，請先至組織角色設定頁設定']);

    $old = eg_approval_latest($db, 'as_doc_tree', 0, 'approve');
    if ($old && $old['status']==='pending') jout(['status'=>'error','message'=>'已有一筆審核中的送審，請等待核准或退回']);

    $apId = eg_approval_submit($db, 'as_doc_tree', 0, 'approve', $currentUserId, $currentCname);
    asSetSetting($db, 'as_doc_tree_pending_id', (string)$apId);
    asSetSetting($db, 'as_doc_tree_pending', json_encode([
        'approval_id'=>$apId, 'pages'=>$pages, 'editor_id'=>$edId, 'editor_name'=>$edName,
        'approver_id'=>(int)$ap['id'], 'approver_name'=>$ap['user_cname'],
        'submitted_by'=>$currentUserId, 'submitted_by_name'=>$currentCname, 'submitted_at'=>date('Y-m-d H:i'),
    ], JSON_UNESCAPED_UNICODE));

    // 通知（點進去＝結構總覽審核頁，內容完整可看＋核准/退回鈕）
    $lvTxt = implode('、', array_map(fn($p)=>($p['level'].'（'.$p['count'].'份，最新修改 '.$p['date'].'，修改簽章 '.$p['editor_name'].'）'), $pages));
    $title = 'AS 文件結構總覽待核准';
    $content = $currentCname.' 送出「文件管制總覽表」變更請核准：'.$lvTxt.'。';
    $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                  WHERE ref_type='AS_TREE_APPROVAL' AND (enddate IS NULL OR enddate >= CURDATE())")->execute();
    $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                  VALUES (CURDATE(), NULL, ?, ?, 0, ?, 'AS文件結構總覽', 1, 'AS_TREE_APPROVAL', ?)")
       ->execute([$title, $content, $currentUserId, $apId]);
    $eventId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
       ->execute([$eventId, (int)$ap['id']]);
    eg_approval_set_live_event($db, $apId, $eventId);
    try {
        require_once __DIR__ . '/../push/push_send.php';
        eg_push_send_to_users($db, eg_push_event_recipients($db, $eventId), ['title'=>$title, 'body'=>mb_substr($content,0,480)]);
    } catch (Throwable $e) {}
    jout(['status'=>'success','pending'=>['id'=>$apId,'submitted_at'=>date('Y-m-d H:i'),'submitted_by_name'=>$currentCname]]);

// 核准／退回（由審核檢視頁按鈕呼叫）：核准才把快照轉正，列印才會蓋章
case 'tree_approval_decide':
    require_once __DIR__ . '/../common/approval_lib.php';
    $apId = (int)($_POST['approval_id'] ?? 0);
    $dec  = ($_POST['decision'] ?? '')==='approved' ? 'approved' : 'rejected';
    $note = trim((string)($_POST['note'] ?? ''));
    if ($dec==='rejected' && $note==='') jout(['status'=>'error','message'=>'退回必須填寫原因']);
    $snap = json_decode(asGetSetting($db,'as_doc_tree_pending') ?: 'null', true);
    if (!$snap || (int)($snap['approval_id'] ?? 0) !== $apId) jout(['status'=>'error','message'=>'找不到對應的送審內容（可能已被處理）']);
    if ((int)$snap['approver_id'] !== $currentUserId && !$asIsRoleAdmin) jout(['status'=>'error','message'=>'只有指定的核准人員可以決行']);

    $res = eg_approval_decide($db, $apId, $currentUserId, $currentCname, $dec, $note ?: null);
    if (!$res['success']) jout(['status'=>'error','message'=>$res['message']]);
    if ($dec === 'approved') {
        $snap['approved_at'] = date('Y-m-d H:i');
        $snap['approver_name'] = $currentCname;
        asSetSetting($db, 'as_doc_tree_approved', json_encode($snap, JSON_UNESCAPED_UNICODE));
    }
    asSetSetting($db, 'as_doc_tree_pending', '');
    asSetSetting($db, 'as_doc_tree_pending_id', '0');
    // 舊通知結束＋回發結果通知給送審者（ai-rules/17 第五條）
    $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                  WHERE ref_type='AS_TREE_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())")->execute([$apId]);
    $rTitle = $dec==='approved' ? 'AS 文件結構總覽已核准' : 'AS 文件結構總覽被退回';
    $rBody  = $dec==='approved' ? ($currentCname.' 已核准文件結構總覽，列印時會顯示簽章。'.($note?'（意見：'.$note.'）':''))
                                : ($currentCname.' 退回文件結構總覽，原因：'.$note.'，請修正後重新送出審核。');
    $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                  VALUES (CURDATE(), NULL, ?, ?, 0, NULL, 'AS文件結構總覽', 1, 'AS_TREE_RESULT', ?)")
       ->execute([$rTitle, $rBody, $apId]);
    $rEid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
       ->execute([$rEid, (int)$snap['submitted_by']]);
    try {
        require_once __DIR__ . '/../push/push_send.php';
        eg_push_send_to_users($db, eg_push_event_recipients($db, $rEid), ['title'=>$rTitle, 'body'=>mb_substr($rBody,0,480)]);
    } catch (Throwable $e) {}
    jout(['status'=>'success','message'=>$dec==='approved'?'已核准':'已退回']);

// 結構總覽列印的簽章人：核准＝組織綁定「最高核准人員」；修改＝user_permissions 指派為「AS負責人」者
// dates=YYYY-MM-DD,... （各階最新修改日期）；每人回傳各日期是否可簽（在職＋當天沒請假/留停）
case 'tree_signers':
    $dates = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['dates'] ?? ''))),
                                       fn($d)=>preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
    require_once __DIR__ . '/../common/org_role_lib.php';
    require_once __DIR__ . '/../common/leave_lib.php';
    require_once __DIR__ . '/../common/user_active_lib.php';

    /** 某人在各日期能否簽章（不在職＝全部不可；當天有請假/留停＝該日不可） */
    $availOf = function(int $uid) use ($db, $dates): array {
        $out = [];
        if (!eg_user_is_active($db, $uid)) {
            foreach ($dates as $d) $out[$d] = ['ok'=>false, 'why'=>'非在職（離職／留職停薪）'];
            return $out;
        }
        foreach ($dates as $d) {
            $busy = eg_leave_user_busy_in_range($db, $uid, $d.' 00:00:00', $d.' 23:59:59');
            $out[$d] = $busy ? ['ok'=>false, 'why'=>($busy['leave_name'] ?: '請假').'（'.substr($busy['start_datetime'],0,10).'~'.substr($busy['end_datetime'],0,10).'）']
                             : ['ok'=>true, 'why'=>''];
        }
        return $out;
    };

    // 核准：組織綁定 top_approver（設定頁 views/admin/org_role_setting.php，禁寫死人名）
    $ap = eg_org_user($db, 'top_approver');
    $approver = $ap ? ['id'=>(int)$ap['id'], 'name'=>$ap['user_cname'], 'avail'=>$availOf((int)$ap['id'])] : null;

    // 修改：AS負責人（as_doc 模組角色 asdoc_editor）——個人指派(user_roles)優先，無個人指派才看職稱指派
    $personal = [];  // uid => [role_code,...]
    $st = $db->query("SELECT ur.user_id, r.role_code FROM user_roles ur
                      JOIN roles r ON r.role_id=ur.role_id AND r.is_system=0 AND r.module='as_doc'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $personal[(int)$r['user_id']][] = $r['role_code'];
    $byPos = [];
    $st = $db->query("SELECT m.user_id, r.role_code FROM user_department_position_map m
                      JOIN position_roles pr ON pr.position_id=m.position_id
                      JOIN roles r ON r.role_id=pr.role_id AND r.module='as_doc'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byPos[(int)$r['user_id']][] = $r['role_code'];

    $editorIds = [];
    foreach (array_unique(array_merge(array_keys($personal), array_keys($byPos))) as $uid) {
        $eff = !empty($personal[$uid]) ? $personal[$uid] : ($byPos[$uid] ?? []);
        if (in_array('asdoc_editor', $eff, true)) $editorIds[] = (int)$uid;
    }
    $editors = [];
    if ($editorIds) {
        $ph = implode(',', array_fill(0, count($editorIds), '?'));
        $st = $db->prepare("SELECT id, user_cname FROM user WHERE id IN ($ph) ORDER BY user_cname");
        $st->execute($editorIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $editors[] = ['id'=>(int)$u['id'], 'name'=>$u['user_cname'], 'avail'=>$availOf((int)$u['id'])];
        }
    }
    // 任期回推：各階簽章日期對應「當時」的修改簽章人（沒設任期涵蓋的日期才用畫面手選的人）
    $terms  = asEditorTerms($db);
    $byDate = [];
    foreach ($dates as $d) { $e = asEditorOfDate($terms, $d); if ($e) $byDate[$d] = $e; }
    jout(['status'=>'success', 'approver'=>$approver, 'editors'=>$editors, 'dates'=>$dates,
          'by_date'=>$byDate, 'has_terms'=>count($terms) > 0]);

// 任期設定：清單＋可選人員（含已離職者——歷史任期本來就可能是已離職的人）
case 'tree_editor_terms':
    require_once __DIR__ . '/../common/people_lib.php';
    $cand = [];
    foreach (eg_people_list($db) as $p) {
        $cand[] = ['id'=>(int)$p['id'], 'name'=>$p['user_cname'],
                   'label'=>trim(($p['dept_name'] ?? '').' '.($p['position_name'] ?? '').' '.$p['user_cname'])];
    }
    // 已離職者 people_lib 一律不列（鐵則），但任期是歷史紀錄，需另行補上並標示
    $st = $db->query("SELECT u.id, u.user_cname, d.name AS dept_name
                      FROM `user` u
                      LEFT JOIN user_department_position_map m ON m.id = (SELECT m2.id FROM user_department_position_map m2 WHERE m2.user_id=u.id ORDER BY m2.is_main DESC, m2.id ASC LIMIT 1)
                      LEFT JOIN department d ON d.id = m.department_id
                      WHERE u.state = 0 ORDER BY u.user_cname");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $cand[] = ['id'=>(int)$u['id'], 'name'=>$u['user_cname'],
                   'label'=>trim(($u['dept_name'] ?? '').' '.$u['user_cname'].'（已離職）')];
    }
    jout(['status'=>'success', 'terms'=>asEditorTerms($db), 'candidates'=>$cand]);

case 'save_tree_editor_terms':
    asEditorTermEnsure($db);          // DDL 先做完再開 transaction（DDL 會隱式 commit）
    $rows = json_decode((string)($_POST['terms'] ?? '[]'), true);
    if (!is_array($rows)) jout(['status'=>'error','message'=>'資料格式錯誤']);
    $clean = [];
    foreach ($rows as $i => $r) {
        $uid = (int)($r['user_id'] ?? 0);
        $sd  = trim((string)($r['start_date'] ?? ''));
        $ed  = trim((string)($r['end_date'] ?? ''));
        if (!$uid) continue;                                   // 沒選人的空列直接略過
        foreach ([[$sd,'起日'],[$ed,'迄日']] as [$v,$lb])
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) jout(['status'=>'error','message'=>"第".($i+1)."列的{$lb}格式不正確"]);
        if ($sd !== '' && $ed !== '' && $sd > $ed) jout(['status'=>'error','message'=>"第".($i+1)."列：起日不可晚於迄日"]);
        $s = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $s->execute([$uid]);
        $nm = $s->fetchColumn();
        if ($nm === false) jout(['status'=>'error','message'=>"第".($i+1)."列的人員不存在"]);
        $clean[] = ['user_id'=>$uid, 'name'=>$nm, 'start'=>$sd, 'end'=>$ed, 'note'=>mb_substr(trim((string)($r['note'] ?? '')),0,100)];
    }
    // 任期不可重疊：同一天有兩個負責人時無法判斷該蓋誰的章
    foreach ($clean as $a => $x) foreach ($clean as $b => $y) {
        if ($b <= $a) continue;
        $xs = $x['start'] ?: '0001-01-01'; $xe = $x['end'] ?: '9999-12-31';
        $ys = $y['start'] ?: '0001-01-01'; $ye = $y['end'] ?: '9999-12-31';
        if ($xs <= $ye && $ys <= $xe)
            jout(['status'=>'error','message'=>"任期重疊：{$x['name']} 與 {$y['name']} 的期間有交集，請調整起訖日"]);
    }
    try {
        $db->beginTransaction();
        $db->exec("DELETE FROM as_doc_editor_term");
        $ins = $db->prepare("INSERT INTO as_doc_editor_term (user_id, start_date, end_date, note) VALUES (?,?,?,?)");
        foreach ($clean as $c) $ins->execute([$c['user_id'], $c['start'] ?: null, $c['end'] ?: null, $c['note']]);
        $db->commit();
    } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); jout(['status'=>'error','message'=>$e->getMessage()]); }
    jout(['status'=>'success','message'=>'已儲存任期設定','terms'=>asEditorTerms($db)]);

case 'save_tree_as_doc':
    $docId = (int)($_POST['as_doc_id'] ?? 0);
    if ($docId > 0) {
        $s = $db->prepare("SELECT COUNT(*) FROM as_document WHERE id=? AND is_deleted=0");
        $s->execute([$docId]);
        if (!$s->fetchColumn()) jout(['status'=>'error','message'=>'找不到該 AS 文件']);
    }
    asSetSetting($db, 'as_doc_tree_print_as_doc_id', (string)$docId);
    jout(['status'=>'success','message'=>$docId ? '已綁定' : '已解除綁定','as_doc'=>asTreeBoundDoc($db)]);

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

// ══════════════ 部門文件代碼（編號用；一部門可多組，如 資材課=PD廠內/PH委外） ══════════════
case 'save_dept_codes':
    $rows = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rows)) jout(['status'=>'error','message'=>'格式錯誤']);
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM as_dept_code");
        // sort_order=列表順序。同代碼對應多部門時，排序最前者＝由編號反查部門的「預設」
        $ins = $db->prepare("INSERT IGNORE INTO as_dept_code (department_id, code, label, sort_order) VALUES (?,?,?,?)");
        $sort = 0;
        foreach ($rows as $r) {
            $dId  = (int)($r['department_id'] ?? 0);
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($r['code'] ?? '')));
            $label= trim((string)($r['label'] ?? '')) ?: null;
            if ($dId > 0 && $code !== '') $ins->execute([$dId, $code, $label, ($sort += 10)]);
        }
        $db->commit();
        jout(['status'=>'success']);
    } catch (Exception $e) { $db->rollBack(); jout(['status'=>'error','message'=>$e->getMessage()]); }

// ══════════════ 自動編號建議 ══════════════
// 有母文件：{母文件編號}-{下一個 2 位序號}；無母文件：{階數}-{部門代碼}-{下一個 2 位序號}
case 'suggest_doc_no':
    $levelMap = ['一階'=>'1','二階'=>'2','三階'=>'3','四階'=>'4'];
    $level    = trim($_GET['level'] ?? '');
    $deptId   = (int)($_GET['department_id'] ?? 0);
    $parentId = (int)($_GET['parent_doc_id'] ?? 0);

    if ($parentId > 0) {
        $st = $db->prepare("SELECT doc_no FROM as_document WHERE id=?");
        $st->execute([$parentId]);
        $pNo = $st->fetchColumn();
        if (!$pNo) jout(['status'=>'error','message'=>'母文件不存在']);
        $st = $db->prepare("SELECT doc_no FROM as_document WHERE parent_doc_id=?");
        $st->execute([$parentId]);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            if (preg_match('/^'.preg_quote($pNo,'/').'-(\d+)$/', $no, $m)) $max = max($max, (int)$m[1]);
        }
        jout(['status'=>'success','doc_no'=>$pNo.'-'.str_pad($max+1, 2, '0', STR_PAD_LEFT)]);
    }

    $digit = $levelMap[$level] ?? '';
    if ($digit==='') jout(['status'=>'error','message'=>'請先選擇文件階級']);
    if ($deptId<=0) jout(['status'=>'error','message'=>'請先選擇部門']);
    $st = $db->prepare("SELECT code, label FROM as_dept_code WHERE department_id=? ORDER BY sort_order, id");
    $st->execute([$deptId]);
    $codes = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($codes)) jout(['status'=>'error','message'=>'此部門尚未設定文件代碼（請至 系統設定 → 部門文件代碼 設定，如 技術課=TD）']);

    // 可用 code 參數指定（一部門多代碼時）
    $codeParam = strtoupper(trim($_GET['code'] ?? ''));
    if ($codeParam !== '') $codes = array_values(array_filter($codes, fn($c)=>$c['code']===$codeParam)) ?: $codes;

    $nextFor = function(string $code) use ($db, $digit): string {
        $prefix = $digit.'-'.$code.'-';
        $st = $db->prepare("SELECT doc_no FROM as_document WHERE doc_no LIKE ?");
        $st->execute([$prefix.'%']);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            if (preg_match('/^'.preg_quote($prefix,'/').'(\d+)$/', $no, $m)) $max = max($max, (int)$m[1]); // 只算直屬序號（排除 -01-01 表單）
        }
        return $prefix.str_pad($max+1, 2, '0', STR_PAD_LEFT);
    };

    if (count($codes) > 1) {
        // 一部門多組代碼：回傳各代碼的下一號，由前端選擇
        $options = array_map(fn($c)=>['code'=>$c['code'],'label'=>$c['label'],'doc_no'=>$nextFor($c['code'])], $codes);
        jout(['status'=>'choose','options'=>$options]);
    }
    jout(['status'=>'success','doc_no'=>$nextFor($codes[0]['code'])]);

// ══════════════ 線上開檔（工作副本，供直接打字/列印；不動已發行版本檔） ══════════════
case 'open_online':
    // 線上開檔：仿 BOM 總表（OreadyReply_ForPm_BaseOfTime2.php）的做法——
    // ms-office 協定只吃 HTTP URL（ms-excel:ofe|u|http://...，UNC/file: 會被新版 Office 判受限區域封鎖）。
    // 工作副本複製到本機 web 目錄 uploads/as_workcopy（本機暫存，不受 NAS 路徑規範限制），以 HTTP URL 開啟。
    $verId = (int)($_REQUEST['version_id'] ?? 0);
    if ($verId<=0) jout(['status'=>'error','message'=>'缺少版本 ID']);
    $st = $db->prepare("SELECT v.*, d.doc_no FROM as_document_version v JOIN as_document d ON d.id=v.doc_id WHERE v.id=?");
    $st->execute([$verId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) jout(['status'=>'error','message'=>'版本不存在']);
    if (!$v['file_name']) jout(['status'=>'error','message'=>'此版本未上傳文件檔（補登資料），無檔可開']);
    $src = asDocDir($db, (int)$v['doc_id']).DIRECTORY_SEPARATOR.$v['file_name'];
    if (!is_file($src)) jout(['status'=>'error','message'=>'檔案不存在或 NAS 未連線']);

    $ext = strtolower(pathinfo($v['file_name'], PATHINFO_EXTENSION));
    $schemes = ['xls'=>'ms-excel','xlsx'=>'ms-excel','xlsm'=>'ms-excel','doc'=>'ms-word','docx'=>'ms-word','ppt'=>'ms-powerpoint','pptx'=>'ms-powerpoint'];
    if (!isset($schemes[$ext])) jout(['status'=>'error','message'=>'此檔案格式不支援線上開啟（僅 Excel/Word/PPT）']);

    $workDir = rtrim($document_root, '/\\').'/EGsystem/uploads/as_workcopy';
    if (!is_dir($workDir) && !mkdir($workDir, 0777, true)) jout(['status'=>'error','message'=>'無法建立工作副本資料夾']);

    // 懶惰清理：刪除超過 7 天的工作副本
    foreach ((array)@scandir($workDir) as $f) {
        if ($f==='.'||$f==='..') continue;
        $fp = $workDir.'/'.$f;
        if (is_file($fp) && filemtime($fp) < time()-7*86400) @unlink($fp);
    }

    // 檔名純 ASCII（協定 URL 免編碼）＋亂數避免猜測
    $docNoAscii = preg_replace('/[^A-Za-z0-9._-]/', '_', $v['doc_no'] ?: ('doc'.$v['doc_id']));
    $verAscii   = preg_replace('/[^A-Za-z0-9._-]/', '_', $v['version'] ?: 'v');
    $copyName   = date('Ymd_His').'_'.bin2hex(random_bytes(3)).'_'.$docNoAscii.'_v'.$verAscii.'.'.$ext;
    if (!@copy($src, $workDir.'/'.$copyName)) jout(['status'=>'error','message'=>'建立工作副本失敗']);

    $url = 'http://'.($_SERVER['HTTP_HOST'] ?? 'localhost').'/EGsystem/uploads/as_workcopy/'.$copyName;
    jout(['status'=>'success','uri'=>$schemes[$ext].':ofe|u|'.$url,'url'=>$url,
          'note'=>'已建立工作副本並以 Office 開啟（同 BOM 總表模式）；打完資料請直接列印或另存，7 天後自動清除，不影響正式版本檔']);

// ══════════════ 批次上傳（同一母文件/共同預設值，多檔一次建立，附件逐檔對應） ══════════════
case 'create_documents_batch':
    $rows = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rows) || empty($rows)) jout(['status'=>'error','message'=>'無資料列']);
    if (asDocRoot($db)==='') jout(['status'=>'error','message'=>'尚未設定 NAS 儲存路徑']);

    $results = [];
    foreach ($rows as $i => $r) {
        $res = ['index'=>$i, 'doc_no'=>trim($r['doc_no'] ?? ''), 'success'=>false];
        try {
            $doc_no  = trim($r['doc_no'] ?? '');
            $doc_name= trim($r['doc_name'] ?? '');
            $version = asNormVer($r['version'] ?? '');
            if ($doc_no==='' || $doc_name==='') throw new Exception('編號/名稱必填');
            if ($version==='' && trim($r['doc_type'] ?? '')!=='表單') throw new Exception('版本號必填（僅表單首建可不填）');
            if (trim($r['revised_date'] ?? '') === '') throw new Exception('請填寫修訂日期');

            $dup = $db->prepare("SELECT COUNT(*) FROM as_document WHERE doc_no=? AND is_deleted=0");
            $dup->execute([$doc_no]);
            if ($dup->fetchColumn() > 0) throw new Exception("編號 {$doc_no} 已存在");

            $fkey = 'file_'.$i;
            if (!isset($_FILES[$fkey]) || $_FILES[$fkey]['error']!==UPLOAD_ERR_OK) throw new Exception('缺少文件檔');
            $ext = asSafeExt($_FILES[$fkey]['name']);
            if (!$ext) throw new Exception('不允許此檔案類型');

            $akey = 'apply_'.$i;   // 申請單逐檔對應（可無）
            $hasApply = isset($_FILES[$akey]) && $_FILES[$akey]['error']===UPLOAD_ERR_OK;
            $applyExt = $hasApply ? asSafeExt($_FILES[$akey]['name']) : null;
            if ($hasApply && !$applyExt) throw new Exception('申請單檔案類型不允許');

            $doc_type= trim($r['doc_type'] ?? '');
            $level   = trim($r['doc_level'] ?? '');
            $dept    = ($r['department_id'] ?? '')!=='' ? (int)$r['department_id'] : null;
            $parent  = ($r['parent_doc_id'] ?? '')!=='' ? (int)$r['parent_doc_id'] : null;
            if ($pErr = asValidateParent($db, $parent)) throw new Exception($pErr);
            $cstat   = trim($r['change_status'] ?? '制訂');
            $rdate   = trim($r['revised_date'] ?? '') ?: null;
            $rpages  = trim($r['revised_pages'] ?? '') ?: null;
            $rsum    = trim($r['revised_summary'] ?? '') ?: null;
            $tagIds  = array_filter(array_map('intval', (array)($r['tag_ids'] ?? [])));

            $db->beginTransaction();
            $db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,department_id,parent_doc_id,current_version,created_by,created_at,updated_at)
                          VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
               ->execute([$doc_no,$doc_name,$doc_type,$level,$dept,$parent,$version,$GLOBALS['currentCname']]);
            $docId = (int)$db->lastInsertId();

            $dir = asDocDir($db, $docId);
            if (!is_dir($dir) && !mkdir($dir, 0777, true)) throw new Exception('無法建立資料夾');

            $fname = asMakeName($ext);
            if (!move_uploaded_file($_FILES[$fkey]['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception('文件寫入失敗');
            $orig = basename($_FILES[$fkey]['name']);

            $applyName = null; $applyOrig = null;
            if ($hasApply) {
                $applyName = asMakeName($applyExt);
                if (!move_uploaded_file($_FILES[$akey]['tmp_name'], $dir.DIRECTORY_SEPARATOR.$applyName)) throw new Exception('申請單寫入失敗');
                $applyOrig = basename($_FILES[$akey]['name']);
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
            $res['success'] = true; $res['id'] = $docId;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $res['message'] = $e->getMessage();
        }
        $results[] = $res;
    }
    $okCnt = count(array_filter($results, fn($r)=>$r['success']));
    jout(['status'=>'success','ok'=>$okCnt,'total'=>count($rows),'results'=>$results]);

// ══════════════ 表單填寫紀錄（品質紀錄：紙本掃描/檔案上傳 + 電子化模組結果） ══════════════
case 'form_records_list':
    $docId = (int)($_GET['doc_id'] ?? 0);
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $size  = min(50, max(5, (int)($_GET['page_size'] ?? 10)));
    if ($docId<=0) jout(['status'=>'error','message'=>'無效 ID']);
    $st = $db->prepare("SELECT doc_no, doc_name, doc_type, linked_module FROM as_document WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);

    // 紙本紀錄（後端分頁，符合 ai-rules/08）
    $tot = $db->prepare("SELECT COUNT(*) FROM as_form_record WHERE form_doc_id=? AND is_deleted=0");
    $tot->execute([$docId]);
    $total = (int)$tot->fetchColumn();
    $st = $db->prepare("SELECT r.*, COALESCE(u.user_cname, r.uploaded_by) AS uploaded_by_name
                        FROM as_form_record r LEFT JOIN user u ON u.user_uname = r.uploaded_by
                        WHERE r.form_doc_id=? AND r.is_deleted=0
                        ORDER BY r.record_date DESC, r.id DESC
                        LIMIT ".(($page-1)*$size).",".$size);
    $st->execute([$docId]);
    $records = $st->fetchAll(PDO::FETCH_ASSOC);

    // 電子化模組結果（最新 20 筆＋模組頁連結；完整查詢至模組頁）
    $electronic = null;
    if ($doc['linked_module'] === 'car') {
        $rows = $db->query("SELECT id, car_no AS no, fill_date AS rec_date, source_desc AS title
                            FROM car_order ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        $cnt = (int)$db->query("SELECT COUNT(*) FROM car_order")->fetchColumn();
        $electronic = ['module'=>'car','module_name'=>'異常矯正處理單(CAR)','page_url'=>'../QA/correction_order.php','total'=>$cnt,'rows'=>$rows];
    } elseif ($doc['linked_module'] === 'qa_abnormal') {
        $rows = $db->query("SELECT id, abnormal_order_no AS no, occurrence_date AS rec_date, abnormal_phenomenon AS title
                            FROM qa_abnormal_order ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        $cnt = (int)$db->query("SELECT COUNT(*) FROM qa_abnormal_order")->fetchColumn();
        $electronic = ['module'=>'qa_abnormal','module_name'=>'品質異常處理單','page_url'=>'../QA/qa_abnormal_view.php','total'=>$cnt,'rows'=>$rows];
    }
    jout(['status'=>'success','doc'=>$doc,'records'=>$records,'total'=>$total,'page'=>$page,'page_size'=>$size,'electronic'=>$electronic]);

case 'form_records_upload':
    $docId = (int)($_POST['doc_id'] ?? 0);
    $rows  = json_decode($_POST['rows'] ?? '[]', true);
    if ($docId<=0 || !is_array($rows) || empty($rows)) jout(['status'=>'error','message'=>'無資料列']);
    if (asDocRoot($db)==='') jout(['status'=>'error','message'=>'尚未設定 NAS 儲存路徑']);
    $dir = asRecordDir($db, $docId);
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) jout(['status'=>'error','message'=>'無法建立紀錄資料夾（NAS 未連線？）']);
    $results = [];
    foreach ($rows as $i => $r) {
        $res = ['index'=>$i, 'success'=>false];
        try {
            $title = trim($r['title'] ?? '');
            if ($title==='') throw new Exception('標題必填');
            $rdate = trim($r['record_date'] ?? '') ?: null;
            $note  = trim($r['note'] ?? '') ?: null;
            $fkey  = 'file_'.$i;
            if (!isset($_FILES[$fkey]) || $_FILES[$fkey]['error']!==UPLOAD_ERR_OK) throw new Exception('缺少檔案');
            $ext = asSafeExt($_FILES[$fkey]['name']);
            if (!$ext) throw new Exception('不允許此檔案類型');
            $fname = asMakeName($ext);
            if (!move_uploaded_file($_FILES[$fkey]['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception('寫入失敗');
            $db->prepare("INSERT INTO as_form_record (form_doc_id,title,record_date,file_name,original_name,note,uploaded_by)
                          VALUES (?,?,?,?,?,?,?)")
               ->execute([$docId,$title,$rdate,$fname,basename($_FILES[$fkey]['name']),$note,$currentUserName]);
            $res['success'] = true; $res['id'] = (int)$db->lastInsertId();
        } catch (Exception $e) { $res['message'] = $e->getMessage(); }
        $results[] = $res;
    }
    $okCnt = count(array_filter($results, fn($r)=>$r['success']));
    jout(['status'=>'success','ok'=>$okCnt,'total'=>count($rows),'results'=>$results]);

case 'form_record_delete':
    $rid = (int)($_POST['id'] ?? 0);
    if ($rid<=0) jout(['status'=>'error','message'=>'無效 ID']);
    $db->prepare("UPDATE as_form_record SET is_deleted=1 WHERE id=?")->execute([$rid]);
    jout(['status'=>'success']);

case 'form_record_download':
    $rid = (int)($_GET['id'] ?? 0);
    $inline = (($_GET['inline'] ?? '') === '1');
    if (!asCan($inline ? 'view' : 'download')) {
        http_response_code(403); header('Content-Type: text/plain; charset=utf-8');
        exit($inline ? '無檢閱權限' : '無下載權限');
    }
    $st = $db->prepare("SELECT * FROM as_form_record WHERE id=?");
    $st->execute([$rid]);
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) { http_response_code(404); exit('紀錄不存在'); }
    $dir = asRecordDir($db, (int)$rec['form_doc_id']);
    if ($inline) {
        $fext = strtolower(pathinfo($rec['file_name'], PATHINFO_EXTENSION));
        if (in_array($fext, ['doc','docx','xls','xlsx','ppt','pptx','odt','ods'], true)) {
            $cache = asPreviewPdf($db, (int)$rec['form_doc_id'], $rec['file_name'], $dir);
            if ($cache) asStream($cache, preg_replace('/\.[^.]+$/', '', $rec['original_name'] ?: 'record') . '.pdf', true);
            if (!asCan('download')) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); exit('預覽產生失敗'); }
        }
    }
    asStream($dir.DIRECTORY_SEPARATOR.$rec['file_name'], $rec['original_name'] ?: $rec['file_name'], $inline);
    break;

// ══════════════ 附件 #標籤 總覽（掃描紀錄備註中的 #文字，供瀏覽點選篩選） ══════════════
case 'hashtag_list':
    $rows = $db->query("SELECT note FROM as_form_record WHERE is_deleted=0 AND note LIKE '%#%'")->fetchAll(PDO::FETCH_COLUMN);
    $tags = [];
    foreach ($rows as $note) {
        if (preg_match_all('/#([^\s#]+)/u', (string)$note, $m)) {
            foreach ($m[1] as $t) { $t = '#'.$t; $tags[$t] = ($tags[$t] ?? 0) + 1; }
        }
    }
    arsort($tags);
    $out = [];
    foreach ($tags as $t => $c) $out[] = ['tag'=>$t, 'cnt'=>$c];
    jout(['status'=>'success','data'=>$out]);

// ══════════════ 批次加標籤（勾選多份文件一次加上標籤；只加不移除） ══════════════
case 'docs_add_tags':
    $docIds = array_filter(array_map('intval', (array)json_decode($_POST['doc_ids'] ?? '[]', true)));
    $tagIds = array_filter(array_map('intval', (array)json_decode($_POST['tag_ids'] ?? '[]', true)));
    if (empty($docIds)) jout(['status'=>'error','message'=>'未勾選任何文件']);
    if (empty($tagIds)) jout(['status'=>'error','message'=>'未選擇任何標籤']);
    $ins = $db->prepare("INSERT IGNORE INTO as_doc_tag_map (doc_id, tag_id) VALUES (?,?)");
    foreach ($docIds as $dId) foreach ($tagIds as $tId) $ins->execute([$dId, $tId]);
    jout(['status'=>'success','docs'=>count($docIds),'tags'=>count($tagIds)]);

// ══════════════ 版本補檔（補登資料忘了附檔時；只允許補「空缺」，不可替換既有檔案） ══════════════
case 'version_attach_file':
    $verId = (int)($_POST['version_id'] ?? 0);
    $which = ($_POST['which'] ?? 'file') === 'apply' ? 'apply' : 'file';
    if ($verId<=0) jout(['status'=>'error','message'=>'缺少版本 ID']);
    $st = $db->prepare("SELECT * FROM as_document_version WHERE id=?");
    $st->execute([$verId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) jout(['status'=>'error','message'=>'版本不存在']);
    $col  = $which==='apply' ? 'apply_form_file_name' : 'file_name';
    $colO = $which==='apply' ? 'apply_form_original_name' : 'original_name';
    if (!empty($v[$col])) jout(['status'=>'error','message'=>'此版本已有檔案，不可替換（版本檔案為發行紀錄）；內容有誤請走「改版」']);
    if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) jout(['status'=>'error','message'=>'請選擇檔案']);
    $ext = asSafeExt($_FILES['file']['name']);
    if (!$ext) jout(['status'=>'error','message'=>'不允許此檔案類型']);
    $dir = asDocDir($db, (int)$v['doc_id']);
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) jout(['status'=>'error','message'=>'無法建立資料夾（NAS 未連線？）']);
    $fname = asMakeName($ext);
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) jout(['status'=>'error','message'=>'檔案寫入失敗']);
    $db->prepare("UPDATE as_document_version SET $col=?, $colO=? WHERE id=?")
       ->execute([$fname, basename($_FILES['file']['name']), $verId]);
    jout(['status'=>'success']);

// ══════════════ 替換「目前版本」的檔案（傳錯檔補救；歷史版本不可替換） ══════════════
/* version_attach_file 只補「空缺」，這支處理「已有檔案要換掉」。
 * 刻意只開放文件的 current_version_id 那一筆：舊版是已發行的紀錄，內容有誤一律走「改版」，
 * 否則歷史版本會被事後改寫、與紙本發行紀錄對不上（AS9100 可追溯性）。 */
case 'version_replace_file':
    if (!asCan('update')) jout(['status'=>'error','message'=>'無替換檔案權限']);
    $verId = (int)($_POST['version_id'] ?? 0);
    $which = ($_POST['which'] ?? 'file') === 'apply' ? 'apply' : 'file';
    if ($verId<=0) jout(['status'=>'error','message'=>'缺少版本 ID']);
    $st = $db->prepare("SELECT * FROM as_document_version WHERE id=?");
    $st->execute([$verId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) jout(['status'=>'error','message'=>'版本不存在']);
    $docId = (int)$v['doc_id'];
    $st = $db->prepare("SELECT doc_no, doc_name, current_version, current_version_id FROM as_document WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);
    if ((int)$doc['current_version_id'] !== $verId)
        jout(['status'=>'error','message'=>"只能替換『目前版本』（{$doc['current_version']}）的檔案；版本 {$v['version']} 是歷史發行紀錄，內容有誤請走「改版」建立新版本"]);
    $col  = $which==='apply' ? 'apply_form_file_name' : 'file_name';
    $colO = $which==='apply' ? 'apply_form_original_name' : 'original_name';
    if (empty($v[$col])) jout(['status'=>'error','message'=>'此版本尚無檔案，請用「補檔」上傳']);
    if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) jout(['status'=>'error','message'=>'請選擇檔案']);
    $ext = asSafeExt($_FILES['file']['name']);
    if (!$ext) jout(['status'=>'error','message'=>'不允許此檔案類型']);
    $dir = asDocDir($db, $docId);
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) jout(['status'=>'error','message'=>'無法建立資料夾（NAS 未連線？）']);
    $fname = asMakeName($ext); // 新檔名，成功寫入後才刪舊檔，中途失敗不會兩邊都沒有
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) jout(['status'=>'error','message'=>'檔案寫入失敗']);
    $oldName = (string)$v[$col];
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE as_document_version SET $col=?, $colO=? WHERE id=?")
           ->execute([$fname, basename($_FILES['file']['name']), $verId]);
        $db->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                      VALUES ('views/ADM/as_document_management.php', ?, ?, NOW(), ?)")
           ->execute(['替換目前版本檔案',
               "doc_no={$doc['doc_no']} doc_name={$doc['doc_name']} version={$v['version']} which={$which} old={$oldName} new=".basename($_FILES['file']['name']),
               $currentCname ?: $currentUserName]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack(); @unlink($dir.DIRECTORY_SEPARATOR.$fname);
        jout(['status'=>'error','message'=>$e->getMessage()]);
    }
    // 舊檔與其預覽快取（同資料夾內各版本檔名不同，不會誤刪其他版本）
    @unlink($dir.DIRECTORY_SEPARATOR.$oldName);
    @unlink($dir.DIRECTORY_SEPARATOR.'preview_'.pathinfo($oldName, PATHINFO_FILENAME).'.pdf');
    jout(['status'=>'success']);

// ══════════════ 批次補建版本（管理員限定；前期補件用，免制修申請單，逐列可附檔） ══════════════
case 'add_versions_batch':
    if (!($asIsRoleAdmin || strpos($asPagePerm,'A')!==false))
        jout(['status'=>'error','message'=>'此功能僅限管理員使用']);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $rows  = json_decode($_POST['rows'] ?? '[]', true);
    if ($docId<=0 || !is_array($rows) || empty($rows)) jout(['status'=>'error','message'=>'無資料列']);
    $st = $db->prepare("SELECT * FROM as_document WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);
    if (asDocRoot($db)==='') jout(['status'=>'error','message'=>'尚未設定 NAS 儲存路徑']);

    $dir = asDocDir($db, $docId);
    $movedFiles = [];
    $db->beginTransaction();
    try {
        $prevVersion = (string)($doc['current_version'] ?? '');
        $lastVerId = null; $lastVer = null;
        foreach ($rows as $i => $r) {
            $rowNo = $i + 1;
            $version = asNormVer($r['version'] ?? '');
            $rdate   = trim($r['revised_date'] ?? '') ?: null;
            if ($version==='') throw new Exception("第{$rowNo}列：版本號必填");
            if (!$rdate) throw new Exception("第{$rowNo}列：修訂日期必填");
            // 重複（含既有版本與本批前列）
            $dup = $db->prepare("SELECT COUNT(*) FROM as_document_version WHERE doc_id=? AND version=?");
            $dup->execute([$docId, $version]);
            if ($dup->fetchColumn() > 0) throw new Exception("第{$rowNo}列：版本號 {$version} 已存在");
            if ($vErr = asValidateVersionStyle($db, $docId, $version)) throw new Exception("第{$rowNo}列：".$vErr);
            if ($vErr = asValidateVersionOrder($prevVersion, $version)) throw new Exception("第{$rowNo}列：".$vErr);

            $fname = null; $orig = null;
            $fkey = 'file_'.$i;
            if (isset($_FILES[$fkey]) && $_FILES[$fkey]['error']===UPLOAD_ERR_OK) {
                $ext = asSafeExt($_FILES[$fkey]['name']);
                if (!$ext) throw new Exception("第{$rowNo}列：不允許此檔案類型");
                if (!is_dir($dir) && !mkdir($dir, 0777, true)) throw new Exception('無法建立資料夾（NAS 未連線？）');
                $fname = asMakeName($ext);
                if (!move_uploaded_file($_FILES[$fkey]['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception("第{$rowNo}列：檔案寫入失敗");
                $movedFiles[] = $dir.DIRECTORY_SEPARATOR.$fname;
                $orig = basename($_FILES[$fkey]['name']);
            }
            $db->prepare("INSERT INTO as_document_version
                  (doc_id,version,change_status,revised_date,revised_pages,revised_summary,doc_level_snapshot,department_id_snapshot,file_name,original_name,uploaded_by,uploaded_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$docId,$version,trim($r['change_status'] ?? '修正') ?: '修正',$rdate,
                          trim($r['revised_pages'] ?? '') ?: null, trim($r['revised_summary'] ?? '') ?: null,
                          $doc['doc_level'],$doc['department_id'],$fname,$orig,$GLOBALS['currentCname']]);
            $lastVerId = (int)$db->lastInsertId();
            $lastVer = $version;
            $prevVersion = $version;
        }
        if ($lastVerId) {
            $db->prepare("UPDATE as_document SET current_version=?, current_version_id=?, updated_at=NOW() WHERE id=?")
               ->execute([$lastVer, $lastVerId, $docId]);
        }
        $db->commit();
        jout(['status'=>'success','count'=>count($rows),'current_version'=>$lastVer]);
    } catch (Exception $e) {
        $db->rollBack();
        foreach ($movedFiles as $f) @unlink($f); // 交易失敗，清掉已搬入的檔案
        jout(['status'=>'error','message'=>$e->getMessage()]);
    }

// ══════════════ 補舊版次（僅超級管理員；補「比目前版本舊」的歷史紀錄，不動目前版本） ══════════════
/* 與 add_versions_batch 的差別：那支是「往後接新版」並把最後一列變成目前版本；
 * 這支是「往前補歷史」——目前版本、current_version_id 一律不動，只把漏掉的舊版紀錄補回版本歷史，
 * 讓 eg_asdoc_version_asof()（依業務日期回推當時版次，ai-rules/16 第三之四節）能查到正確的舊版次。 */
case 'add_old_versions_batch':
    if (!($currentUserId === 1 && $asIsRoleAdmin))
        jout(['status'=>'error','message'=>'補舊版次僅限超級管理員使用']);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $rows  = json_decode($_POST['rows'] ?? '[]', true);
    if ($docId<=0 || !is_array($rows) || empty($rows)) jout(['status'=>'error','message'=>'無資料列']);
    $st = $db->prepare("SELECT * FROM as_document WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) jout(['status'=>'error','message'=>'文件不存在']);
    $curVer = trim((string)($doc['current_version'] ?? ''));
    if ($curVer === '') jout(['status'=>'error','message'=>'此文件尚無目前版本，請改用「批次補建版本」由舊到新建立']);
    if (asDocRoot($db)==='') jout(['status'=>'error','message'=>'尚未設定 NAS 儲存路徑']);
    // 目前版本的修訂日期：補的舊版日期必須早於它，否則依日期回推版次時會被舊版蓋過（順序顛倒）
    $st = $db->prepare("SELECT revised_date FROM as_document_version WHERE doc_id=? AND version=? ORDER BY id DESC LIMIT 1");
    $st->execute([$docId, $curVer]);
    $curDate = (string)($st->fetchColumn() ?: '');

    $dir = asDocDir($db, $docId);
    $movedFiles = [];
    $db->beginTransaction();
    try {
        $prevVersion = ''; $prevDate = '';
        foreach ($rows as $i => $r) {
            $rowNo = $i + 1;
            $version = asNormVer($r['version'] ?? '');
            $rdate   = trim($r['revised_date'] ?? '') ?: null;
            if ($version==='') throw new Exception("第{$rowNo}列：版本號必填");
            if (!$rdate) throw new Exception("第{$rowNo}列：修訂日期必填");
            $dup = $db->prepare("SELECT COUNT(*) FROM as_document_version WHERE doc_id=? AND version=?");
            $dup->execute([$docId, $version]);
            if ($dup->fetchColumn() > 0) throw new Exception("第{$rowNo}列：版本號 {$version} 已存在於此文件的版本紀錄");
            if ($vErr = asValidateVersionStyle($db, $docId, $version)) throw new Exception("第{$rowNo}列：".$vErr);
            // 必須比目前版本「舊」
            $cmp = asVersionCmp($version, $curVer);
            if ($cmp === null) throw new Exception("第{$rowNo}列：版本號 {$version} 無法與目前版本 {$curVer} 比較新舊，請確認格式一致");
            if ($cmp >= 0) throw new Exception("第{$rowNo}列：版本號 {$version} 不比目前版本 {$curVer} 舊；補舊版次只能補歷史紀錄，要新增更新的版本請用「改版」或「批次補建版本」");
            // 本批內由舊到新
            if ($prevVersion !== '') {
                $c2 = asVersionCmp($version, $prevVersion);
                if ($c2 !== null && $c2 <= 0) throw new Exception("第{$rowNo}列：版本號 {$version} 未比上一列 {$prevVersion} 新，請由舊到新排列");
            }
            // 日期：早於目前版本、且不早於上一列（依業務日期回推版次靠 revised_date 排序）
            if ($curDate !== '' && $rdate >= $curDate)
                throw new Exception("第{$rowNo}列：修訂日期 {$rdate} 不早於目前版本 {$curVer} 的修訂日期 {$curDate}；舊版次的日期必須更早，否則依日期回推版次會取錯版");
            if ($prevDate !== '' && $rdate < $prevDate)
                throw new Exception("第{$rowNo}列：修訂日期 {$rdate} 早於上一列的 {$prevDate}，請由舊到新排列");

            $fname = null; $orig = null;
            $fkey = 'file_'.$i;
            if (isset($_FILES[$fkey]) && $_FILES[$fkey]['error']===UPLOAD_ERR_OK) {
                $ext = asSafeExt($_FILES[$fkey]['name']);
                if (!$ext) throw new Exception("第{$rowNo}列：不允許此檔案類型");
                if (!is_dir($dir) && !mkdir($dir, 0777, true)) throw new Exception('無法建立資料夾（NAS 未連線？）');
                $fname = asMakeName($ext);
                if (!move_uploaded_file($_FILES[$fkey]['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception("第{$rowNo}列：檔案寫入失敗");
                $movedFiles[] = $dir.DIRECTORY_SEPARATOR.$fname;
                $orig = basename($_FILES[$fkey]['name']);
            }
            $db->prepare("INSERT INTO as_document_version
                  (doc_id,version,change_status,revised_date,revised_pages,revised_summary,doc_level_snapshot,department_id_snapshot,file_name,original_name,uploaded_by,uploaded_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$docId,$version,trim($r['change_status'] ?? '修正') ?: '修正',$rdate,
                          trim($r['revised_pages'] ?? '') ?: null, trim($r['revised_summary'] ?? '') ?: null,
                          $doc['doc_level'],$doc['department_id'],$fname,$orig,$GLOBALS['currentCname']]);
            $prevVersion = $version;
            $prevDate = $rdate;
        }
        // 目前版本刻意不動（這裡補的都是舊版）
        $db->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                      VALUES ('views/ADM/as_document_management.php', ?, ?, NOW(), ?)")
           ->execute(['補舊版次（歷史版本紀錄）',
               "doc_no={$doc['doc_no']} doc_name={$doc['doc_name']} 目前版本={$curVer} 補入=".implode('、', array_map(fn($r)=>asNormVer($r['version'] ?? ''), $rows)),
               $currentCname ?: $currentUserName]);
        $db->commit();
        jout(['status'=>'success','count'=>count($rows),'current_version'=>$curVer]);
    } catch (Exception $e) {
        $db->rollBack();
        foreach ($movedFiles as $f) @unlink($f); // 交易失敗，清掉已搬入的檔案
        jout(['status'=>'error','message'=>$e->getMessage()]);
    }

// ══════════════ 程序書快速建檔（管理員限定；一次建立：文件＋全部版本＋底下表單各一版；免申請單） ══════════════
case 'create_document_full':
    if (!($asIsRoleAdmin || strpos($asPagePerm,'A')!==false))
        jout(['status'=>'error','message'=>'此功能僅限管理員使用']);
    $doc_no  = trim($_POST['doc_no'] ?? '');
    $doc_name= trim($_POST['doc_name'] ?? '');
    $doc_type= trim($_POST['doc_type'] ?? '程序');
    $level   = trim($_POST['doc_level'] ?? '二階');
    $dept    = ($_POST['department_id'] ?? '')!=='' ? (int)$_POST['department_id'] : null;
    $versions= json_decode($_POST['versions'] ?? '[]', true);
    $forms   = json_decode($_POST['forms'] ?? '[]', true);
    if ($doc_no==='' || $doc_name==='') jout(['status'=>'error','message'=>'文件編號、名稱為必填']);
    if (!is_array($versions) || empty($versions)) jout(['status'=>'error','message'=>'至少要有一個版本']);
    if (!is_array($forms)) $forms = [];
    $dup = $db->prepare("SELECT COUNT(*) FROM as_document WHERE doc_no=? AND is_deleted=0");
    $dup->execute([$doc_no]);
    if ($dup->fetchColumn() > 0) jout(['status'=>'error','message'=>"文件編號 {$doc_no} 已存在"]);
    if (asDocRoot($db)==='') jout(['status'=>'error','message'=>'尚未設定 NAS 儲存路徑']);

    $movedFiles = [];
    $db->beginTransaction();
    try {
        // 1. 建程序書主檔
        $db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,department_id,created_by,created_at,updated_at)
                      VALUES (?,?,?,?,?,?,NOW(),NOW())")
           ->execute([$doc_no,$doc_name,$doc_type,$level,$dept,$GLOBALS['currentCname']]);
        $docId = (int)$db->lastInsertId();
        $dir = asDocDir($db, $docId);

        // 2. 依序建立全部版本（重複/型式/順序檢查）
        $prevVersion = ''; $lastVerId = null; $lastVer = null;
        foreach ($versions as $i => $r) {
            $rowNo = $i + 1;
            $version = asNormVer($r['version'] ?? '');
            $rdate   = trim($r['revised_date'] ?? '') ?: null;
            if ($version==='') throw new Exception("版本第{$rowNo}列：版本號必填");
            if (!$rdate) throw new Exception("版本第{$rowNo}列：修訂日期必填");
            if ($vErr = asValidateVersionStyle($db, $docId, $version)) throw new Exception("版本第{$rowNo}列：".$vErr);
            if ($vErr = asValidateVersionOrder($prevVersion, $version)) throw new Exception("版本第{$rowNo}列：".$vErr);
            $fname = null; $orig = null;
            $fkey = 'vfile_'.$i;
            if (isset($_FILES[$fkey]) && $_FILES[$fkey]['error']===UPLOAD_ERR_OK) {
                $ext = asSafeExt($_FILES[$fkey]['name']);
                if (!$ext) throw new Exception("版本第{$rowNo}列：不允許此檔案類型");
                if (!is_dir($dir) && !mkdir($dir, 0777, true)) throw new Exception('無法建立資料夾（NAS 未連線？）');
                $fname = asMakeName($ext);
                if (!move_uploaded_file($_FILES[$fkey]['tmp_name'], $dir.DIRECTORY_SEPARATOR.$fname)) throw new Exception("版本第{$rowNo}列：檔案寫入失敗");
                $movedFiles[] = $dir.DIRECTORY_SEPARATOR.$fname;
                $orig = basename($_FILES[$fkey]['name']);
            }
            $db->prepare("INSERT INTO as_document_version
                  (doc_id,version,change_status,revised_date,revised_pages,revised_summary,doc_level_snapshot,department_id_snapshot,file_name,original_name,uploaded_by,uploaded_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$docId,$version,trim($r['change_status'] ?? ($i===0?'制訂':'修正')) ?: '修正',$rdate,
                          trim($r['revised_pages'] ?? '') ?: null, trim($r['revised_summary'] ?? '') ?: null,
                          $level,$dept,$fname,$orig,$GLOBALS['currentCname']]);
            $lastVerId = (int)$db->lastInsertId(); $lastVer = $version; $prevVersion = $version;
        }
        $db->prepare("UPDATE as_document SET current_version=?, current_version_id=? WHERE id=?")
           ->execute([$lastVer, $lastVerId, $docId]);

        // 程序書標籤
        $mainTags = array_filter(array_map('intval', explode(',', $_POST['tag_ids'] ?? '')));
        if ($mainTags) {
            $insTag = $db->prepare("INSERT IGNORE INTO as_doc_tag_map (doc_id,tag_id) VALUES (?,?)");
            foreach ($mainTags as $tid) $insTag->execute([$docId, $tid]);
        }

        // 3. 底下表單（各一版，parent=此程序書，四階/表單，部門承襲）
        $formCnt = 0;
        foreach ($forms as $i => $f) {
            $rowNo = $i + 1;
            $fNo   = trim($f['doc_no'] ?? '');
            $fName = trim($f['doc_name'] ?? '');
            $fVer  = asNormVer($f['version'] ?? '');
            $fDate = trim($f['revised_date'] ?? '') ?: null;
            if ($fNo==='' || $fName==='') throw new Exception("表單第{$rowNo}列：編號/名稱必填");
            if (!$fDate) throw new Exception("表單第{$rowNo}列：修訂日期必填");
            $dup2 = $db->prepare("SELECT COUNT(*) FROM as_document WHERE doc_no=? AND is_deleted=0");
            $dup2->execute([$fNo]);
            if ($dup2->fetchColumn() > 0) throw new Exception("表單第{$rowNo}列：編號 {$fNo} 已存在");

            $db->prepare("INSERT INTO as_document (doc_no,doc_name,doc_type,doc_level,department_id,parent_doc_id,current_version,created_by,created_at,updated_at)
                          VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
               ->execute([$fNo,$fName,'表單','四階',$dept,$docId,$fVer,$GLOBALS['currentCname']]);
            $fDocId = (int)$db->lastInsertId();
            $fDir = asDocDir($db, $fDocId);

            $fname = null; $orig = null;
            $fkey = 'ffile_'.$i;
            if (isset($_FILES[$fkey]) && $_FILES[$fkey]['error']===UPLOAD_ERR_OK) {
                $ext = asSafeExt($_FILES[$fkey]['name']);
                if (!$ext) throw new Exception("表單第{$rowNo}列：不允許此檔案類型");
                if (!is_dir($fDir) && !mkdir($fDir, 0777, true)) throw new Exception('無法建立表單資料夾');
                $fname = asMakeName($ext);
                if (!move_uploaded_file($_FILES[$fkey]['tmp_name'], $fDir.DIRECTORY_SEPARATOR.$fname)) throw new Exception("表單第{$rowNo}列：檔案寫入失敗");
                $movedFiles[] = $fDir.DIRECTORY_SEPARATOR.$fname;
                $orig = basename($_FILES[$fkey]['name']);
            }
            $db->prepare("INSERT INTO as_document_version
                  (doc_id,version,change_status,revised_date,revised_pages,revised_summary,doc_level_snapshot,department_id_snapshot,file_name,original_name,uploaded_by,uploaded_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$fDocId,$fVer,'制訂',$fDate,null,trim($f['revised_summary'] ?? '') ?: null,'四階',$dept,$fname,$orig,$GLOBALS['currentCname']]);
            $fVerId = (int)$db->lastInsertId();
            $db->prepare("UPDATE as_document SET current_version_id=? WHERE id=?")->execute([$fVerId,$fDocId]);
            // 表單標籤
            $fTags = array_filter(array_map('intval', (array)($f['tag_ids'] ?? [])));
            if ($fTags) {
                $insTag2 = $db->prepare("INSERT IGNORE INTO as_doc_tag_map (doc_id,tag_id) VALUES (?,?)");
                foreach ($fTags as $tid) $insTag2->execute([$fDocId, $tid]);
            }
            $formCnt++;
        }
        $db->commit();
        jout(['status'=>'success','doc_id'=>$docId,'doc_no'=>$doc_no,'versions'=>count($versions),'forms'=>$formCnt]);
    } catch (Exception $e) {
        $db->rollBack();
        foreach ($movedFiles as $f) @unlink($f);
        jout(['status'=>'error','message'=>$e->getMessage()]);
    }

// ══════════════ 制修訂頁次/摘要 常用文字（存 DB，重啟不消失） ══════════════
case 'phrase_add':
    $field  = trim($_POST['field'] ?? '');
    $phrase = trim($_POST['phrase'] ?? '');
    if (!in_array($field, ['pages','summary'], true)) jout(['status'=>'error','message'=>'欄位類型錯誤']);
    if ($phrase==='') jout(['status'=>'error','message'=>'內容不可為空']);
    if (mb_strlen($phrase) > 500) jout(['status'=>'error','message'=>'內容過長（上限500字）']);
    try {
        $db->prepare("INSERT INTO as_doc_phrase (field, phrase, created_by) VALUES (?,?,?)")
           ->execute([$field, $phrase, $currentCname]);
        jout(['status'=>'success','id'=>(int)$db->lastInsertId()]);
    } catch (Exception $e) {
        if ($e->getCode()==23000) jout(['status'=>'error','message'=>'此常用文字已存在']);
        jout(['status'=>'error','message'=>$e->getMessage()]);
    }

case 'phrase_delete':
    $pid = (int)($_POST['id'] ?? 0);
    if ($pid<=0) jout(['status'=>'error','message'=>'無效 ID']);
    $db->prepare("DELETE FROM as_doc_phrase WHERE id=?")->execute([$pid]);
    jout(['status'=>'success']);

case 'set_linked_module':
    $docId  = (int)($_POST['doc_id'] ?? 0);
    $module = trim($_POST['module'] ?? '');
    if ($docId<=0) jout(['status'=>'error','message'=>'無效 ID']);
    if (!in_array($module, ['', 'car', 'qa_abnormal'], true)) jout(['status'=>'error','message'=>'不支援的模組']);
    $db->prepare("UPDATE as_document SET linked_module=?, updated_at=NOW() WHERE id=?")->execute([$module ?: null, $docId]);
    jout(['status'=>'success']);

case 'flow_check_toggle':   // AS流程總覽·線上表單對照：表單正確／資料齊全 點檢（views/ADM/as_flow_guide.php）
    if (!asCan('view')) jout(['status'=>'error','message'=>'無權限']);
    $docId = (int)($_POST['doc_id'] ?? 0);
    $field = trim($_POST['field'] ?? '');
    $val   = (int)($_POST['value'] ?? 0) ? 1 : 0;
    if ($docId<=0) jout(['status'=>'error','message'=>'無效 ID']);
    if (!in_array($field, ['form_ok','data_ok'], true)) jout(['status'=>'error','message'=>'欄位錯誤']);
    $byCol = $field.'_by'; $atCol = $field.'_at';
    if ($val === 0 && !$asIsRoleAdmin) {   // 取消確認：只有原確認人本人或管理者可取消
        $chk = $db->prepare("SELECT $byCol FROM as_flow_form_check WHERE as_document_id=?");
        $chk->execute([$docId]);
        $curBy = (string)($chk->fetchColumn() ?: '');
        if ($curBy !== '' && $curBy !== $currentCname) {
            jout(['status'=>'error','message'=>'僅原確認人「'.$curBy.'」或管理者可取消']);
        }
    }
    $by = $val ? $currentCname : null;
    $st = $db->prepare("INSERT INTO as_flow_form_check (as_document_id, $field, $byCol, $atCol)
                        VALUES (?, ?, ?, ".($val ? "NOW()" : "NULL").")
                        ON DUPLICATE KEY UPDATE $field=VALUES($field), $byCol=VALUES($byCol),
                            $atCol=".($val ? "NOW()" : "NULL"));
    $st->execute([$docId, $val, $by]);
    jout(['status'=>'success', 'value'=>$val, 'by'=>$by, 'at'=>$val ? date('Y-m-d H:i') : null]);

case 'flow_issue_toggle':   // AS流程總覽·待處理問題：已修改／已檢查 點檢（views/ADM/as_flow_guide.php）
    if (!asCan('view')) jout(['status'=>'error','message'=>'無權限']);
    $issueKey = trim($_POST['issue_key'] ?? '');
    $field    = trim($_POST['field'] ?? '');
    $val      = (int)($_POST['value'] ?? 0) ? 1 : 0;
    if (!preg_match('/^[a-f0-9]{16}$/', $issueKey)) jout(['status'=>'error','message'=>'無效項目']);
    if (!in_array($field, ['fixed','checked'], true)) jout(['status'=>'error','message'=>'欄位錯誤']);
    $byCol = $field.'_by'; $atCol = $field.'_at';
    if ($val === 0 && !$asIsRoleAdmin) {   // 取消確認：只有原確認人本人或管理者可取消
        $chk = $db->prepare("SELECT $byCol FROM as_flow_issue_check WHERE issue_key=?");
        $chk->execute([$issueKey]);
        $curBy = (string)($chk->fetchColumn() ?: '');
        if ($curBy !== '' && $curBy !== $currentCname) {
            jout(['status'=>'error','message'=>'僅原確認人「'.$curBy.'」或管理者可取消']);
        }
    }
    $by = $val ? $currentCname : null;
    $st = $db->prepare("INSERT INTO as_flow_issue_check (issue_key, $field, $byCol, $atCol)
                        VALUES (?, ?, ?, ".($val ? "NOW()" : "NULL").")
                        ON DUPLICATE KEY UPDATE $field=VALUES($field), $byCol=VALUES($byCol),
                            $atCol=".($val ? "NOW()" : "NULL"));
    $st->execute([$issueKey, $val, $by]);
    jout(['status'=>'success', 'value'=>$val, 'by'=>$by, 'at'=>$val ? date('Y-m-d H:i') : null]);

default:
    jout(['status'=>'error','message'=>'無效的操作']);
}
} catch (Exception $e) {
    jout(['status'=>'error','message'=>'系統錯誤：'.$e->getMessage()]);
}
