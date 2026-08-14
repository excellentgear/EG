<?php
/**
 * 表單簽核設計器（Form Signer Designer）共用函式庫 — 2026-08-13 新增
 * 規格：`表單簽核設計器 規格文件.md`。管理員在上傳的表單原始檔(圖片/多頁PDF)上自由座標框選圖章/回覆內容區塊，
 * 設定意見階段(諮詢型/並簽,不卡關)與決策階段(1~2人,真正決定流程走向)的有序流程，一般使用者依樣板送出案件、
 * 簽核人蓋章回應，最終線上檢視/瀏覽器列印已簽核完成的合成文件(不產生伺服器端合併PDF，見規格待確認事項4確認)。
 *
 * 與既有 as_form_builder(固定格狀,無上傳)、review_form(欄位清單式,無上傳) 定位不同——本模組是唯一支援
 * 「保留上傳原始檔版面」的簽核引擎，三套獨立並存(使用者已確認)，不互相取代。
 *
 * 資料表前綴 fsd_（Form Sign Designer，已確認全站無命名衝突）：
 *   fsd_template / fsd_template_page / fsd_template_version /
 *   fsd_stage / fsd_stage_signer / fsd_field / fsd_case / fsd_case_response
 *
 * 設計時(live)資料表 fsd_stage/fsd_stage_signer/fsd_field 供設計頁 CRUD 用；「發布」時把目前設計狀態
 * 整包快照進 fsd_template_version.schema_json(比照 review_form 的 schema/instance 分離)，案件建立時
 * pin 住當下版本，之後樣板改版不影響已建立案件的階段/槽位/框選位置。
 *
 * 重用不新建：AS 文件綁定走 asdoc_lib.php(module code 動態組 'fsd_tpl_'.$id)；決策階段走共用 approval_record
 * (module='form_signer', level='stage_'.$seq，OR-gate 由 approval_lib.php 內建競態鎖保證)；意見階段(AND-gate,
 * 不卡關)無現成函式可用，用本檔的 fsd_case_response 自行追蹤；通知走 live_event/live_event_target；
 * 槽位解析重用 org_role_lib.php 的 eg_org_dept_manager()/eg_org_user()、delegate_lib.php 的 eg_resolve_supervisor()。
 */

require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/org_role_lib.php';

const FSD_SIGNER_MODES = ['user', 'dept_auto_manager', 'submitter_supervisor', 'top_approver', 'filler'];

const FSD_FEATURES = [
    ['code' => 'fsd_view',          'group' => 'view', 'label' => '檢閱案件列表（沒勾也看得到自己建立的案件）'],
    ['code' => 'fsd_view_all',      'group' => 'view', 'label' => '檢視全部人員建立的案件'],
    ['code' => 'fsd_create',        'group' => 'op',   'label' => '建立/送出案件'],
    ['code' => 'fsd_print',         'group' => 'op',   'label' => '列印'],
    ['code' => 'fsd_template_admin','group' => 'op',   'label' => '樣板管理（上傳原始檔、框選、階段/槽位設定、AS文件綁定）'],
];

/* ============================================================ 使用者/權限 ============================================================ */

function fsd_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fsd_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canCreate'=>false,'canView'=>false,'canViewAll'=>false,'canPrint'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    require_once __DIR__ . '/role_features_helper.php';
    $feat = rf_load_user_features_all($db, $uid);
    $codes = array_values(array_intersect($feat, array_column(FSD_FEATURES, 'code')));
    $has = function (string $code) use ($isAdmin, $feat, $codes) { return $isAdmin || in_array('all', $feat, true) || in_array($code, $codes, true); };
    return [
        'isAdmin'    => $isAdmin,
        'canAdmin'   => $has('fsd_template_admin'),
        'canCreate'  => $has('fsd_create'),
        'canView'    => $has('fsd_view') || $has('fsd_create') || $has('fsd_template_admin') || true, // 每個登入者至少看得到自己建立的案件
        'canViewAll' => $has('fsd_view_all') || $has('fsd_template_admin'),
        'canPrint'   => $has('fsd_print') || $has('fsd_create') || $has('fsd_template_admin'),
    ];
}

/* ============================================================ CSRF（比照 Leave_API.php／review_form_lib.php 模式） ============================================================ */

function fsd_csrf_token(): string {
    if (empty($_SESSION['fsd_csrf'])) $_SESSION['fsd_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['fsd_csrf'];
}
function fsd_csrf_ok(?string $t): bool {
    return $t !== null && hash_equals((string)($_SESSION['fsd_csrf'] ?? ''), (string)$t);
}
function fsd_need_csrf(): void {
    if (!fsd_csrf_ok($_POST['csrf'] ?? null)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'error'=>'CSRF token 驗證失敗，請重新整理頁面']);
        exit;
    }
}

/* ============================================================ 圖章框最小尺寸（換算自 ai-rules/18 全站列印圖章 91px＠96dpi） ============================================================ */

/** 圖章實際列印邊長 91px@96dpi ≈ 24.06mm，換算成該頁面寬高的最小分數。回覆框不設限（回傳0）。 */
/** $stampSchema有給(該樣板綁定的圖章模板schema)時，最小尺寸改依模板實際設定的公分數計算(使用者明確要求
 *  「圖章尺寸要依照圖章模板內設定的公分數」)；沒綁定模板時退回ai-rules/18的91px全站預設。
 *  換算：schema.size是模板設計寬度(px@96dpi)，ratio是高/寬比。 */
function fsd_field_min_frac(array $page, ?array $stampSchema = null): array {
    if ($stampSchema && !empty($stampSchema['size'])) {
        $sizePx = min(600, max(24, (float)$stampSchema['size']));
        $ratio = min(3, max(0.3, (float)($stampSchema['ratio'] ?? 1)));
        $minEdgeMmW = $sizePx / 96 * 25.4;
        $minEdgeMmH = $sizePx * $ratio / 96 * 25.4;
    } else {
        $minEdgeMmW = $minEdgeMmH = 91 / 96 * 25.4;
    }
    $widthMm  = (float)($page['width_pt']  ?? 0) / 72 * 25.4;
    $heightMm = (float)($page['height_pt'] ?? 0) / 72 * 25.4;
    return [
        'min_w' => $widthMm  > 0 ? $minEdgeMmW / $widthMm  : 0.05,
        'min_h' => $heightMm > 0 ? $minEdgeMmH / $heightMm : 0.05,
    ];
}

/* ============================================================ 槽位解析 ============================================================ */

/** 某人主要部門id(user_department_position_map.is_main=1)；查無回null。 */
function fsd_user_main_dept_id(PDO $db, int $uid): ?int {
    if (!$uid) return null;
    $st = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=? AND is_main=1 LIMIT 1");
    $st->execute([$uid]);
    $v = $st->fetchColumn();
    return $v ? (int)$v : null;
}

/**
 * 依 mode 解析出「恰好一人」（或 null）。純解析，不做 SoD 判斷（SoD 由呼叫端比對 fillerUid/applicantUid 處理）。
 * $case 需含 applicant_id；filler_id/filler_name 有給則用，沒有(舊資料/呼叫端未帶)則退回 applicant 本人
 * （使用者2026-08-14明確要求新增「填表人」概念：管理員代為建立案件時，簽核解析基準要以填表人為準，
 *   不是技術上按下建立的人）。
 */
function fsd_resolve_signer(PDO $db, array $signer, array $case): ?array {
    $mode = $signer['mode'] ?? '';
    $applicantUid = (int)($case['applicant_id'] ?? 0);
    $fillerUid = (int)($case['filler_id'] ?? $applicantUid) ?: $applicantUid;
    $fillerName = (string)($case['filler_name'] ?? $case['applicant_name'] ?? '');
    switch ($mode) {
        case 'user':
            $uid = (int)($signer['user_id'] ?? 0);
            if (!$uid) return null;
            $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
            $st->execute([$uid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? ['id'=>(int)$r['id'], 'user_cname'=>$r['user_cname']] : null;
        case 'dept_auto_manager':
            $deptId = (int)($signer['dept_id'] ?? 0);
            if (!$deptId) $deptId = (int)(fsd_user_main_dept_id($db, $fillerUid) ?? 0); // 未指定部門→用填表人自己的部門(使用者明確要求)
            if (!$deptId) return null;
            $m = eg_org_dept_manager($db, $deptId);
            return $m ? ['id'=>(int)$m['id'], 'user_cname'=>$m['user_cname']] : null;
        case 'submitter_supervisor':
            $supId = eg_resolve_supervisor($db, $applicantUid);
            if (!$supId) return null;
            $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
            $st->execute([$supId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? ['id'=>(int)$r['id'], 'user_cname'=>$r['user_cname']] : null;
        case 'top_approver':
            $u = eg_org_user($db, 'top_approver');
            return $u ? ['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname']] : null;
        case 'filler':
            if (!$fillerUid) return null;
            $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
            $st->execute([$fillerUid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? ['id'=>(int)$r['id'], 'user_cname'=>$r['user_cname']] : ($fillerName !== '' ? ['id'=>$fillerUid, 'user_cname'=>$fillerName] : null);
        default:
            return null;
    }
}

/** 解析＋強制SoD：結果等於填表人或送出人本人視同該槽位無結果(該階段免簽略過)。回傳 ['user'=>arr|null,'skipped_sod'=>bool] */
function fsd_resolve_signer_for_case(PDO $db, array $signer, array $case): array {
    $u = fsd_resolve_signer($db, $signer, $case);
    $applicantUid = (int)($case['applicant_id'] ?? 0);
    $fillerUid = (int)($case['filler_id'] ?? $applicantUid) ?: $applicantUid;
    if ($u && ((int)$u['id'] === $applicantUid || (int)$u['id'] === $fillerUid)) return ['user'=>null, 'skipped_sod'=>true];
    return ['user'=>$u, 'skipped_sod'=>false];
}

/* ============================================================ 樣板 CRUD（設計時 live 表） ============================================================ */

function fsd_asdoc_module(int $templateId): string { return 'fsd_tpl_' . $templateId; }

function fsd_template_list(PDO $db): array {
    $rows = $db->query("SELECT * FROM fsd_template ORDER BY (status='active') DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['as_doc'] = eg_asdoc_get($db, fsd_asdoc_module((int)$r['id']));
    return $rows;
}

function fsd_template_get(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM fsd_template WHERE id=?");
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if ($t) {
        $t['as_doc'] = eg_asdoc_get($db, fsd_asdoc_module($id));
        $t['stamp_tpl'] = fsd_stamp_tpl_get($db, (int)($t['stamp_tpl_id'] ?? 0));
    }
    return $t ?: null;
}

/** 圖章模板(stamp_template)：id=0或查無資料回null。 */
function fsd_stamp_tpl_get(PDO $db, int $tplId): ?array {
    if (!$tplId) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$tplId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}

function fsd_stamp_tpl_options(PDO $db): array {
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name
                           FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

function fsd_template_set_stamp_tpl(PDO $db, int $id, int $stampTplId, string $byName): void {
    $db->prepare("UPDATE fsd_template SET stamp_tpl_id=?,updated_by=?,updated_at=NOW() WHERE id=?")
       ->execute([$stampTplId ?: null, $byName, $id]);
}

function fsd_template_create(PDO $db, string $name, string $fileType, ?string $fileName, int $pageCount, string $byName): int {
    $db->prepare("INSERT INTO fsd_template (name,file_type,file_name,page_count,status,published_version,created_by)
                  VALUES (?,?,?,?, 'active', 0, ?)")
       ->execute([$name, $fileType === 'pdf' ? 'pdf' : 'image', $fileName, max(1,$pageCount), $byName]);
    return (int)$db->lastInsertId();
}

function fsd_template_rename(PDO $db, int $id, string $name, string $byName): void {
    $db->prepare("UPDATE fsd_template SET name=?,updated_by=?,updated_at=NOW() WHERE id=?")->execute([$name, $byName, $id]);
}

function fsd_template_set_status(PDO $db, int $id, string $status, string $byName): void {
    $status = $status === 'inactive' ? 'inactive' : 'active';
    $db->prepare("UPDATE fsd_template SET status=?,updated_by=?,updated_at=NOW() WHERE id=?")->execute([$status, $byName, $id]);
}

/** 頁面尺寸快照：整批覆蓋(前端上傳/量測完一次送齊；旋轉時前端重算width_pt/height_pt(90/270互換)後也走這支)。
 *  width_pt/height_pt 一律代表「旋轉後(使用者實際看到)的有效尺寸」，rotation只給前端渲染時知道要轉幾度，
 *  伺服器端(最小框選尺寸計算等)不需要另外處理旋轉換算。 */
function fsd_template_pages_save(PDO $db, int $templateId, array $pages): void {
    $db->prepare("DELETE FROM fsd_template_page WHERE template_id=?")->execute([$templateId]);
    $ins = $db->prepare("INSERT INTO fsd_template_page (template_id,page_no,width_pt,height_pt,rotation,paper_size,crop_x,crop_y,crop_w,crop_h) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($pages as $p) {
        $paper = in_array($p['paper_size'] ?? '', ['A4','A3'], true) ? $p['paper_size'] : null;
        $ins->execute([$templateId, (int)$p['page_no'], (float)$p['width_pt'], (float)$p['height_pt'], (int)($p['rotation'] ?? 0) % 360,
            $paper, (float)($p['crop_x'] ?? 0), (float)($p['crop_y'] ?? 0), (float)($p['crop_w'] ?? 1), (float)($p['crop_h'] ?? 1)]);
    }
    $db->prepare("UPDATE fsd_template SET page_count=? WHERE id=?")->execute([count($pages) ?: 1, $templateId]);
}

function fsd_template_pages_get(PDO $db, int $templateId): array {
    $st = $db->prepare("SELECT * FROM fsd_template_page WHERE template_id=? ORDER BY page_no");
    $st->execute([$templateId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* -------- 階段（live） -------- */

function fsd_stage_list(PDO $db, int $templateId): array {
    $st = $db->prepare("SELECT * FROM fsd_stage WHERE template_id=? ORDER BY seq,id");
    $st->execute([$templateId]);
    $stages = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stages as &$s) $s['signers'] = fsd_stage_signer_list($db, (int)$s['id']);
    return $stages;
}

/** 存階段+槽位：以 id 對應差異更新(有id且存在→UPDATE，否則→INSERT，送出清單裡沒出現的既有列→DELETE)，
 *  不採用「delete+insert 全部重來」——那樣每次存檔階段/槽位 id 全部改變，已框選的 fsd_field(FK槽位id)
 *  會被連帶砍光，光是改個標籤文字都會把所有框選位置洗掉(2026-08-14使用者實測回報的根因，比照
 *  rvf_instance_items_save() 的差異更新手法修正，同一個坑review_form那邊已經踩過一次)。
 *  $stages = [['id'=>?,'stage_type'=>,'name'=>,'auto_sign'=>,'signers'=>[['id'=>?,'mode'=>,'user_id'=>,'dept_id'=>,'label'=>],...]],...] */
function fsd_stages_save(PDO $db, int $templateId, array $stages): void {
    $st = $db->prepare("SELECT id FROM fsd_stage WHERE template_id=?");
    $st->execute([$templateId]);
    $existingStageIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $keepStageIds = [];

    $insStage = $db->prepare("INSERT INTO fsd_stage (template_id,seq,stage_type,name,auto_sign) VALUES (?,?,?,?,?)");
    $updStage = $db->prepare("UPDATE fsd_stage SET seq=?,stage_type=?,name=?,auto_sign=? WHERE id=? AND template_id=?");
    $insSigner = $db->prepare("INSERT INTO fsd_stage_signer (stage_id,seq,mode,user_id,dept_id,label) VALUES (?,?,?,?,?,?)");
    $updSigner = $db->prepare("UPDATE fsd_stage_signer SET seq=?,mode=?,user_id=?,dept_id=?,label=? WHERE id=? AND stage_id=?");

    $seq = 1;
    foreach ($stages as $s) {
        $stageType = ($s['stage_type'] ?? '') === 'decision' ? 'decision' : 'advisory';
        $name = trim((string)($s['name'] ?? '')) ?: ('第'.$seq.'關');
        $autoSign = !empty($s['auto_sign']) ? 1 : 0;
        $stageId = (int)($s['id'] ?? 0);
        if ($stageId && in_array($stageId, $existingStageIds, true)) {
            $updStage->execute([$seq, $stageType, $name, $autoSign, $stageId, $templateId]);
        } else {
            $insStage->execute([$templateId, $seq, $stageType, $name, $autoSign]);
            $stageId = (int)$db->lastInsertId();
        }
        $keepStageIds[] = $stageId;

        $sgSt = $db->prepare("SELECT id FROM fsd_stage_signer WHERE stage_id=?");
        $sgSt->execute([$stageId]);
        $existingSignerIds = array_map('intval', $sgSt->fetchAll(PDO::FETCH_COLUMN));
        $keepSignerIds = [];
        $gseq = 1;
        foreach (($s['signers'] ?? []) as $sg) {
            $mode = in_array($sg['mode'] ?? '', FSD_SIGNER_MODES, true) ? $sg['mode'] : 'top_approver';
            $userId = (int)($sg['user_id'] ?? 0) ?: null;
            $deptId = (int)($sg['dept_id'] ?? 0) ?: null;
            $label = trim((string)($sg['label'] ?? '')) ?: null;
            $signerId = (int)($sg['id'] ?? 0);
            if ($signerId && in_array($signerId, $existingSignerIds, true)) {
                $updSigner->execute([$gseq, $mode, $userId, $deptId, $label, $signerId, $stageId]);
            } else {
                $insSigner->execute([$stageId, $gseq, $mode, $userId, $deptId, $label]);
                $signerId = (int)$db->lastInsertId();
            }
            $keepSignerIds[] = $signerId;
            $gseq++;
        }
        $removedSigners = array_values(array_diff($existingSignerIds, $keepSignerIds));
        if ($removedSigners) {
            $in = implode(',', $removedSigners);
            $db->exec("DELETE FROM fsd_field WHERE stage_signer_id IN ($in)");
            $db->exec("DELETE FROM fsd_stage_signer WHERE id IN ($in)");
        }
        $seq++;
    }
    $removedStages = array_values(array_diff($existingStageIds, $keepStageIds));
    if ($removedStages) {
        $in = implode(',', $removedStages);
        $db->exec("DELETE FROM fsd_field WHERE stage_signer_id IN (SELECT id FROM fsd_stage_signer WHERE stage_id IN ($in))");
        $db->exec("DELETE FROM fsd_stage_signer WHERE stage_id IN ($in)");
        $db->exec("DELETE FROM fsd_stage WHERE id IN ($in)");
    }
}

function fsd_stage_signer_list(PDO $db, int $stageId): array {
    $st = $db->prepare("SELECT * FROM fsd_stage_signer WHERE stage_id=? ORDER BY seq,id");
    $st->execute([$stageId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* -------- 框選區塊（live） -------- */

function fsd_field_list(PDO $db, int $templateId): array {
    $st = $db->prepare("SELECT * FROM fsd_field WHERE template_id=? ORDER BY page_no,id");
    $st->execute([$templateId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 存一個框選區塊；圖章框做最小尺寸驗證(表單三總則-錯誤即時偵測，前端已做一次，這裡後端同規則再擋一次)。 */
function fsd_field_save(PDO $db, int $templateId, array $f): array {
    $stageSignerId = (int)($f['stage_signer_id'] ?? 0);
    if (!$stageSignerId) return ['ok'=>false, 'msg'=>'請先指定此框選對應的簽核槽位'];
    $boxType = ($f['box_type'] ?? '') === 'reply' ? 'reply' : 'stamp';
    $pageNo = (int)($f['page_no'] ?? 1);
    $x = (float)($f['x'] ?? 0); $y = (float)($f['y'] ?? 0);
    $w = (float)($f['w'] ?? 0); $h = (float)($f['h'] ?? 0);
    if ($boxType === 'stamp') {
        $pst = $db->prepare("SELECT width_pt,height_pt FROM fsd_template_page WHERE template_id=? AND page_no=?");
        $pst->execute([$templateId, $pageNo]);
        $page = $pst->fetch(PDO::FETCH_ASSOC);
        if ($page) {
            $tpl = fsd_template_get($db, $templateId);
            $stampSchema = $tpl['stamp_tpl']['schema'] ?? null;
            $min = fsd_field_min_frac($page, $stampSchema);
            if ($w < $min['min_w'] || $h < $min['min_h']) {
                return ['ok'=>false, 'msg'=>sprintf('圖章框太小，至少需要頁面寬度%.1f%%、高度%.1f%%（依綁定的圖章模板設定尺寸換算，未綁定模板則比照全站列印91px標準），請拖大一點', $min['min_w']*100, $min['min_h']*100)];
            }
        }
    }
    $id = (int)($f['id'] ?? 0);
    if (!$id) {
        // 同一槽位+同一框型只保留一個框(拖放到已放過的標籤視同搬動既有框，不產生重複框)
        $dup = $db->prepare("SELECT id FROM fsd_field WHERE stage_signer_id=? AND box_type=?");
        $dup->execute([$stageSignerId, $boxType]);
        $id = (int)($dup->fetchColumn() ?: 0);
    }
    if ($id) {
        $db->prepare("UPDATE fsd_field SET stage_signer_id=?,page_no=?,box_type=?,x=?,y=?,w=?,h=? WHERE id=? AND template_id=?")
           ->execute([$stageSignerId, $pageNo, $boxType, $x, $y, $w, $h, $id, $templateId]);
    } else {
        $db->prepare("INSERT INTO fsd_field (template_id,stage_signer_id,page_no,box_type,x,y,w,h) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$templateId, $stageSignerId, $pageNo, $boxType, $x, $y, $w, $h]);
        $id = (int)$db->lastInsertId();
    }
    return ['ok'=>true, 'id'=>$id];
}

function fsd_field_delete(PDO $db, int $templateId, int $id): void {
    $db->prepare("DELETE FROM fsd_field WHERE id=? AND template_id=?")->execute([$id, $templateId]);
}

/** 整頁清空框選(旋轉該頁時用，旋轉後座標系不同，既有框選位置已不適用)。 */
function fsd_field_delete_by_page(PDO $db, int $templateId, int $pageNo): void {
    $db->prepare("DELETE FROM fsd_field WHERE template_id=? AND page_no=?")->execute([$templateId, $pageNo]);
}

/* -------- 發布：把目前 live 設計狀態整包快照進 fsd_template_version（比照 rvf_template_schema_save） -------- */

function fsd_template_schema_build(PDO $db, int $templateId): array {
    $tpl = fsd_template_get($db, $templateId);
    $pages = fsd_template_pages_get($db, $templateId);
    $stages = fsd_stage_list($db, $templateId);
    $fieldsRaw = fsd_field_list($db, $templateId);

    // 把 stage_signer_id 換成快照內穩定的 slot_key(如 s2_g1)，脫離設計時的 live id
    $signerIdToKey = [];
    $stageOut = [];
    $seq = 1;
    foreach ($stages as $s) {
        $signersOut = [];
        $gseq = 1;
        foreach ($s['signers'] as $sg) {
            $key = 's' . $seq . '_g' . $gseq;
            $signerIdToKey[(int)$sg['id']] = $key;
            $signersOut[] = [
                'slot_key'=>$key, 'mode'=>$sg['mode'], 'user_id'=>$sg['user_id'] ? (int)$sg['user_id'] : null,
                'dept_id'=>$sg['dept_id'] ? (int)$sg['dept_id'] : null, 'label'=>$sg['label'],
            ];
            $gseq++;
        }
        $stageOut[] = ['seq'=>$seq, 'stage_type'=>$s['stage_type'], 'name'=>$s['name'], 'auto_sign'=>(bool)$s['auto_sign'], 'signers'=>$signersOut];
        $seq++;
    }
    $fieldsOut = [];
    foreach ($fieldsRaw as $f) {
        $key = $signerIdToKey[(int)$f['stage_signer_id']] ?? null;
        if (!$key) continue; // 孤兒框(對應槽位已被刪除)，發布時自動略過
        $fieldsOut[] = ['slot_key'=>$key, 'box_type'=>$f['box_type'], 'page_no'=>(int)$f['page_no'],
            'x'=>(float)$f['x'], 'y'=>(float)$f['y'], 'w'=>(float)$f['w'], 'h'=>(float)$f['h']];
    }
    return [
        'file'=>['file_type'=>$tpl['file_type'] ?? 'image', 'file_name'=>$tpl['file_name'] ?? null, 'page_count'=>count($pages) ?: 1],
        'pages'=>array_map(fn($p) => ['page_no'=>(int)$p['page_no'], 'width_pt'=>(float)$p['width_pt'], 'height_pt'=>(float)$p['height_pt'],
            'rotation'=>(int)($p['rotation'] ?? 0), 'crop_x'=>(float)($p['crop_x'] ?? 0), 'crop_y'=>(float)($p['crop_y'] ?? 0),
            'crop_w'=>(float)($p['crop_w'] ?? 1), 'crop_h'=>(float)($p['crop_h'] ?? 1)], $pages),
        'stamp_tpl'=>$tpl['stamp_tpl'] ?? null,
        'stages'=>$stageOut,
        'fields'=>$fieldsOut,
    ];
}

function fsd_template_schema_publish(PDO $db, int $templateId, string $byName, ?int $bumpedAsDocVersionId = null): int {
    $tpl = fsd_template_get($db, $templateId);
    if (!$tpl) throw new Exception('找不到此樣板');
    $schema = fsd_template_schema_build($db, $templateId);
    if (empty($schema['stages'])) throw new Exception('請至少設定一個簽核階段才能發布');
    $newVersion = (int)$tpl['published_version'] + 1;
    $json = json_encode($schema, JSON_UNESCAPED_UNICODE);
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO fsd_template_version (template_id,version,schema_json,bumped_as_doc_version_id,created_by) VALUES (?,?,?,?,?)")
           ->execute([$templateId, $newVersion, $json, $bumpedAsDocVersionId, $byName]);
        $db->prepare("UPDATE fsd_template SET current_schema_json=?,published_version=?,updated_by=?,updated_at=NOW() WHERE id=?")
           ->execute([$json, $newVersion, $byName, $templateId]);
        $db->commit();
        return $newVersion;
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

function fsd_template_schema_at_version(PDO $db, int $templateId, int $version): ?array {
    $st = $db->prepare("SELECT schema_json FROM fsd_template_version WHERE template_id=? AND version=?");
    $st->execute([$templateId, $version]);
    $j = $st->fetchColumn();
    return $j ? (json_decode((string)$j, true) ?: null) : null;
}

/* ============================================================ 案件（case） ============================================================ */

function fsd_case_get(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM fsd_case WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fsd_case_list(PDO $db, int $templateId = 0, ?int $onlyApplicant = null): array {
    $sql = "SELECT c.*, t.name AS template_name FROM fsd_case c JOIN fsd_template t ON t.id=c.template_id WHERE 1=1";
    $params = [];
    if ($templateId) { $sql .= " AND c.template_id=?"; $params[] = $templateId; }
    if ($onlyApplicant !== null) { $sql .= " AND c.applicant_id=?"; $params[] = $onlyApplicant; }
    $sql .= " ORDER BY c.id DESC";
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fsd_case_schema(PDO $db, array $case): array {
    return fsd_template_schema_at_version($db, (int)$case['template_id'], (int)$case['template_version']) ?: ['stages'=>[], 'fields'=>[], 'pages'=>[], 'file'=>[]];
}

function fsd_case_responses(PDO $db, int $caseId): array {
    $st = $db->prepare("SELECT * FROM fsd_case_response WHERE case_id=? ORDER BY stage_seq, id");
    $st->execute([$caseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 案件進度摘要(供列表顯示簽核順序與狀態用，2026-08-14使用者明確要求)：依樣板快照stages順序，
 *  每階段列出各槽位「標籤(顯示用)＋已解析姓名＋狀態」；決策階段(線性)槽位間視覺上以箭頭串接，
 *  意見階段(並簽)槽位間並列無箭頭；階段與階段之間一律視為串接(本系統各階段本來就是逐關推進)。 */
function fsd_case_progress_chips(PDO $db, array $case, array $schema, array $responses): array {
    $bySlot = [];
    foreach ($responses as $r) $bySlot[$r['slot_key']] = $r;
    $modeLabel = ['user'=>'固定人員', 'dept_auto_manager'=>'部門主管', 'submitter_supervisor'=>'上一階主管', 'top_approver'=>'最高決策者', 'filler'=>'填表人'];
    $stages = $schema['stages'] ?? [];
    usort($stages, function($a, $b) { return ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0); });
    $out = [];
    foreach ($stages as $s) {
        $signers = [];
        foreach ($s['signers'] ?? [] as $sg) {
            $r = $bySlot[$sg['slot_key']] ?? null;
            $label = $sg['label'] !== '' && $sg['label'] !== null ? $sg['label'] : ($modeLabel[$sg['mode']] ?? $sg['mode']);
            $status = 'not_started'; $name = '';
            if ($r) {
                $name = $r['resolved_user_name'] ?? '';
                if ($r['decision'] === 'skipped_sod') $status = 'skipped';
                elseif ($r['decision'] === null) $status = 'pending';
                else $status = 'done';
            }
            $signers[] = ['label'=>$label, 'name'=>$name, 'status'=>$status];
        }
        $out[] = ['seq'=>(int)($s['seq'] ?? 0), 'stage_type'=>$s['stage_type'] ?? 'advisory', 'name'=>$s['name'] ?? '', 'signers'=>$signers];
    }
    return $out;
}

/* -------- 通知（比照 rvf_notify） -------- */

function fsd_notify(PDO $db, int $refId, array $toUids, string $title, string $content, int $fromUid, string $refType, string $mode = 'sign'): int {
    $toUids = array_values(array_unique(array_map('intval', $toUids)));
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '表單簽核', 1, ?, ?)")
           ->execute([$title, $content, $fromUid, $refType, $refId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
        foreach ($toUids as $tuid) $ins->execute([$eid, $tuid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/* -------- 自動簽核(ai-rules/21 三條鐵則，比照 rvf_auto_sign，僅用於決策階段) -------- */

/** 對決策階段單一槽位自動簽核；$cumulativeOffsetMin是從本階段開始算起累加的分鐘數(讓連續多槽位的自動簽核
 *  時間依序遞增,不會全部疊在同一秒,呼叫端逐槽位累加後傳入)，回傳這次用掉的累加值供下一槽位接續使用。 */
function fsd_auto_sign_decision(PDO $db, int $caseId, int $stageSeq, string $level, array $signer, string $slotKey, string $bizDate, int $cumulativeOffsetMin): int {
    $aid = eg_approval_submit($db, 'form_signer', $caseId, $level, (int)$signer['id'], $signer['user_cname']);
    eg_approval_decide($db, $aid, (int)$signer['id'], $signer['user_cname'], 'approved', '（系統自動簽核）');
    $cumulativeOffsetMin += random_int(5, 30);
    $db->prepare("UPDATE approval_record SET decided_at = LEAST(DATE_ADD(submitted_at, INTERVAL ? MINUTE), CONCAT(?, ' 23:59:59')) WHERE id=?")
       ->execute([$cumulativeOffsetMin, $bizDate, $aid]);
    $rec = eg_approval_latest($db, 'form_signer', $caseId, $level);
    $db->prepare("INSERT INTO fsd_case_response (case_id,stage_seq,slot_key,resolved_user_id,resolved_user_name,decision,is_auto,reply_text,responded_at)
                  VALUES (?,?,?,?,?, 'approved', 1, '（系統自動簽核）', ?)")
       ->execute([$caseId, $stageSeq, $slotKey, (int)$signer['id'], $signer['user_cname'], $rec['decided_at'] ?? date('Y-m-d H:i:s')]);
    return $cumulativeOffsetMin;
}

/** 意見階段全體槽位自動同意(auto_sign開啟時)，每人各自隨機錯開5~30分鐘(比照決策自動簽核的鐵則精神)。
 *  時間戳一律用DB NOW()+INTERVAL計算,不可用PHP time()/date()(PHP與DB時區可能差8小時，見as_form_builder記憶的跨模組教訓)。 */
function fsd_auto_sign_advisory_slot(PDO $db, int $caseId, int $stageSeq, string $slotKey, array $signer, string $bizDate): void {
    $offsetMin = random_int(5, 30);
    $db->prepare("INSERT INTO fsd_case_response (case_id,stage_seq,slot_key,resolved_user_id,resolved_user_name,decision,is_auto,reply_text,responded_at)
                  VALUES (?,?,?,?,?, 'agree', 1, '（系統自動簽核）',
                          LEAST(DATE_ADD(NOW(), INTERVAL ? MINUTE), CONCAT(?, ' 23:59:59')))")
       ->execute([$caseId, $stageSeq, $slotKey, (int)$signer['id'], $signer['user_cname'], $offsetMin, $bizDate]);
}

/** 依檢視者權限清洗回應紀錄：「系統自動簽核」字樣(is_auto=1)只有管理員能看到，一般/唯讀使用者一律隱藏該筆回覆文字與自動簽核事實，
 *  只保留姓名/決定/時間(看起來與真人簽核無異，2026-08-14使用者明確要求)。 */
function fsd_sanitize_responses_for_viewer(array $responses, bool $isAdminViewer): array {
    if ($isAdminViewer) return $responses;
    foreach ($responses as &$r) {
        if (!empty($r['is_auto'])) { $r['reply_text'] = null; }
        unset($r['is_auto']);
    }
    unset($r);
    return $responses;
}

function fsd_case_find_stage(array $schema, int $stageSeq): ?array {
    foreach ($schema['stages'] ?? [] as $s) if ((int)$s['seq'] === $stageSeq) return $s;
    return null;
}

/** 決策階段(規格「決定型/線性」)找出下一個「還沒有回應紀錄」的槽位；已有紀錄(不論真決定或SoD略過)視為該槽位已處理過。 */
function fsd_case_decision_next_pending_signer(PDO $db, int $caseId, int $stageSeq, array $stage): ?array {
    $st = $db->prepare("SELECT slot_key FROM fsd_case_response WHERE case_id=? AND stage_seq=?");
    $st->execute([$caseId, $stageSeq]);
    $done = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($stage['signers'] as $sg) if (!in_array($sg['slot_key'], $done, true)) return $sg;
    return null;
}

/** 決策階段線性推進：依序找下一個槽位，SoD/解析不到人自動略過並繼續往下一位；找到真人就送出approval_record+通知並停下等待；
 *  全部槽位都處理完(含全部略過)則整個階段結束，推進到下一關(或案件完成)。 */
function fsd_case_decision_advance(PDO $db, array $case, array $schema, int $stageSeq): array {
    $stage = fsd_case_find_stage($schema, $stageSeq);
    if (!$stage) return ['ok'=>false, 'msg'=>'找不到此階段'];
    $submitterUid = (int)$case['applicant_id'];
    while (true) {
        $sg = fsd_case_decision_next_pending_signer($db, (int)$case['id'], $stageSeq, $stage);
        if (!$sg) {
            // 這一關(含所有槽位)都處理完了：真的推進到下一關或整個案件完成(current_stage_seq已由呼叫端設為本關)
            return fsd_case_go_next_stage($db, $case, $schema);
        }
        $r = fsd_resolve_signer_for_case($db, $sg, $case);
        if ($r['skipped_sod'] || !$r['user']) {
            // SoD自動迴避，或解析不到人(部門無主管等，比照as_form_builder已知限制)：這一位跳過，繼續看下一位
            $db->prepare("INSERT IGNORE INTO fsd_case_response (case_id,stage_seq,slot_key,decision) VALUES (?,?,?, 'skipped_sod')")
               ->execute([$case['id'], $stageSeq, $sg['slot_key']]);
            continue;
        }
        $level = 'stage_' . $stageSeq;
        eg_approval_submit($db, 'form_signer', (int)$case['id'], $level, $submitterUid, $case['applicant_name']);
        fsd_notify($db, (int)$case['id'], [(int)$r['user']['id']],
            '「' . $stage['name'] . '」待您決策', $case['applicant_name'] . ' 送出的案件，「' . $stage['name'] . '」待您決策。',
            $submitterUid, 'FSD_DECISION', 'sign');
        return ['ok'=>true, 'status'=>'in_progress'];
    }
}

/** 決策階段整段自動簽核(auto_sign開啟時)：依序把每個槽位都自動核准過一輪(規格「線性」——不是只簽一位就跳過其他人)，
 *  全部槽位都解析不到人時比照人工流程退回最高決策者/送出者本人兜底單一決策。 */
function fsd_case_decision_auto_sign_all(PDO $db, array $case, array $stage, int $stageSeq, string $bizDate): void {
    $submitterUid = (int)$case['applicant_id'];
    $cumOffset = 0;
    $anySigned = false;
    foreach ($stage['signers'] as $sg) {
        $r = fsd_resolve_signer_for_case($db, $sg, $case);
        if ($r['skipped_sod'] || !$r['user']) {
            $db->prepare("INSERT IGNORE INTO fsd_case_response (case_id,stage_seq,slot_key,decision) VALUES (?,?,?, 'skipped_sod')")
               ->execute([$case['id'], $stageSeq, $sg['slot_key']]);
            continue;
        }
        $cumOffset = fsd_auto_sign_decision($db, (int)$case['id'], $stageSeq, 'stage_'.$stageSeq, $r['user'], $sg['slot_key'], $bizDate, $cumOffset);
        $anySigned = true;
    }
    if (!$anySigned) {
        // 整關都解析不到人：退回全站最高決策者，再不行就讓送出者自己決(比照 review_form 邊界情況)
        $top = eg_org_user($db, 'top_approver');
        $fallback = ($top && (int)$top['id'] !== $submitterUid) ? ['id'=>(int)$top['id'],'user_cname'=>$top['user_cname']] : ['id'=>$submitterUid,'user_cname'=>$case['applicant_name']];
        $slotKey = $stage['signers'][0]['slot_key'] ?? ('s'.$stageSeq.'_g1');
        fsd_auto_sign_decision($db, (int)$case['id'], $stageSeq, 'stage_'.$stageSeq, $fallback, $slotKey, $bizDate, 0);
    }
}

/** 開啟指定階段(schema.stages 1-based索引)：意見階段(並簽)一次通知全體槽位；決策階段(線性)依序開啟槽位，見上方函式。 */
function fsd_case_open_stage(PDO $db, array $case, array $schema, int $stageSeq): array {
    $stage = fsd_case_find_stage($schema, $stageSeq);
    if (!$stage) return ['ok'=>false, 'msg'=>'找不到此階段'];
    $submitterUid = (int)$case['applicant_id'];
    $bizDate = (string)($case['business_date'] ?: date('Y-m-d'));

    if ($stage['stage_type'] === 'advisory') {
        $activeSigners = []; // ['slot_key'=>, 'user'=>]
        foreach ($stage['signers'] as $sg) {
            $r = fsd_resolve_signer_for_case($db, $sg, $case);
            if ($r['skipped_sod']) {
                $db->prepare("INSERT IGNORE INTO fsd_case_response (case_id,stage_seq,slot_key,decision) VALUES (?,?,?, 'skipped_sod')")
                   ->execute([$case['id'], $stageSeq, $sg['slot_key']]);
                continue;
            }
            if (!$r['user']) continue; // 解析不到人(部門無主管等)，該槽位留空不擋流程，比照 as_form_builder 已知限制
            $activeSigners[] = ['slot_key'=>$sg['slot_key'], 'user'=>$r['user']];
        }
        if (!empty($stage['auto_sign'])) {
            foreach ($activeSigners as $as) fsd_auto_sign_advisory_slot($db, (int)$case['id'], $stageSeq, $as['slot_key'], $as['user'], $bizDate);
        } else {
            $ins = $db->prepare("INSERT IGNORE INTO fsd_case_response (case_id,stage_seq,slot_key,resolved_user_id,resolved_user_name) VALUES (?,?,?,?,?)");
            foreach ($activeSigners as $as) $ins->execute([$case['id'], $stageSeq, $as['slot_key'], $as['user']['id'], $as['user']['user_cname']]);
            if ($activeSigners) {
                fsd_notify($db, (int)$case['id'], array_column(array_column($activeSigners, 'user'), 'id'),
                    '「' . $stage['name'] . '」需要您的意見', $case['applicant_name'] . ' 送出的案件，「' . $stage['name'] . '」需要您表示同意/不同意並回覆。',
                    $submitterUid, 'FSD_ADVISORY', 'sign');
            }
        }
        return fsd_case_advance_if_ready($db, (int)$case['id']);
    }

    // 決策階段(決定型/線性)：多槽位是依序一關關來(審核→核准這種鏈)，不是誰先簽就算數的OR-gate
    if (!empty($stage['auto_sign'])) {
        fsd_case_decision_auto_sign_all($db, $case, $stage, $stageSeq, $bizDate);
        return fsd_case_go_next_stage($db, $case, $schema);
    }
    return fsd_case_decision_advance($db, $case, $schema, $stageSeq);
}

/** 意見階段是否全體(未SoD略過的)槽位都已回應；決策階段一律視為未完成(要靠實際 decide 動作推進，不走本函式)。 */
function fsd_stage_is_ready_to_advance(PDO $db, int $caseId, int $stageSeq): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM fsd_case_response WHERE case_id=? AND stage_seq=? AND decision IS NULL");
    $st->execute([$caseId, $stageSeq]);
    return (int)$st->fetchColumn() === 0;
}

/** 意見階段(或決策階段自動簽核後)檢查是否可推進到下一關；決策階段人工決行的推進走 fsd_case_decision_respond。 */
function fsd_case_advance_if_ready(PDO $db, int $caseId): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    $schema = fsd_case_schema($db, $case);
    $curSeq = (int)$case['current_stage_seq'];
    if ($curSeq > 0 && !fsd_stage_is_ready_to_advance($db, $caseId, $curSeq)) return ['ok'=>true, 'status'=>'in_progress'];
    return fsd_case_go_next_stage($db, $case, $schema);
}

function fsd_case_go_next_stage(PDO $db, array $case, array $schema): array {
    $nextSeq = (int)$case['current_stage_seq'] + 1;
    $stages = $schema['stages'] ?? [];
    $hasNext = false;
    foreach ($stages as $s) if ((int)$s['seq'] === $nextSeq) { $hasNext = true; break; }
    if (!$hasNext) {
        $db->prepare("UPDATE fsd_case SET status='approved',updated_at=NOW() WHERE id=?")->execute([$case['id']]);
        fsd_notify($db, (int)$case['id'], [(int)$case['applicant_id']], '您的案件已完成簽核',
            '「' . ($case['title'] ?: '案件') . '」已全部完成簽核流程。', (int)$case['applicant_id'], 'FSD_RESULT', 'read');
        return ['ok'=>true, 'status'=>'approved'];
    }
    $db->prepare("UPDATE fsd_case SET current_stage_seq=?,updated_at=NOW() WHERE id=?")->execute([$nextSeq, $case['id']]);
    $case['current_stage_seq'] = $nextSeq;
    return fsd_case_open_stage($db, $case, $schema, $nextSeq);
}

/* -------- 建立/送出案件 -------- */

/**
 * 建立案件＝草稿：案件要自己上傳要簽核的文件(不是沿用樣板的檔案，樣板只提供欄位提示/白名單，
 * 2026-08-14 使用者明確要求)。建立後停在 draft，前端接著要像樣板設計頁一樣把樣板已框選過的槽位
 * (白名單，見 fsd_case_field_whitelist)拖放到這份自己上傳的文件上，才能「存草稿」或「儲存並送出」。
 */
function fsd_case_create_draft(PDO $db, int $templateId, int $uid, string $uname, string $title, string $bizDate, string $fileType, string $fileName): array {
    $tpl = fsd_template_get($db, $templateId);
    if (!$tpl) return ['ok'=>false, 'msg'=>'找不到此樣板'];
    if ((int)$tpl['published_version'] < 1) return ['ok'=>false, 'msg'=>'此樣板尚未發布，請聯絡管理員'];
    if ($tpl['status'] !== 'active') return ['ok'=>false, 'msg'=>'此樣板已停用'];
    $bizDate = $bizDate ?: date('Y-m-d');
    $db->prepare("INSERT INTO fsd_case (template_id,template_version,file_type,file_name,title,applicant_id,applicant_name,filler_id,filler_name,business_date,status,current_stage_seq)
                  VALUES (?,?,?,?,?,?,?,?,?,?,'draft',0)")
       ->execute([$templateId, (int)$tpl['published_version'], $fileType ?: null, $fileName ?: null, $title ?: $tpl['name'], $uid, $uname, $uid, $uname, $bizDate]);
    return ['ok'=>true, 'id'=>(int)$db->lastInsertId()];
}

/** 事後回改填表人(僅超級管理員 id=1；比照 ai-rules/21 業務日期回改精神，一般人不可竄改簽核解析基準)。 */
function fsd_case_set_filler(PDO $db, int $caseId, int $byUid, int $newFillerId): array {
    if ($byUid !== 1) return ['ok'=>false, 'msg'=>'僅超級管理員可設定填表人'];
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
    $st->execute([$newFillerId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return ['ok'=>false, 'msg'=>'找不到此使用者或該使用者已離職'];
    $db->prepare("UPDATE fsd_case SET filler_id=?,filler_name=?,updated_at=NOW() WHERE id=?")->execute([$u['id'], $u['user_cname'], $caseId]);
    return ['ok'=>true, 'filler_id'=>(int)$u['id'], 'filler_name'=>$u['user_cname']];
}

/** 草稿階段允許重新上傳/更換文件(換一份重新框選，之前框選的位置一併清空，避免對到舊文件版面)。 */
function fsd_case_replace_file(PDO $db, int $caseId, string $fileType, string $fileName): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'draft') return ['ok'=>false, 'msg'=>'僅草稿狀態可更換文件'];
    $db->prepare("UPDATE fsd_case SET file_type=?,file_name=?,updated_at=NOW() WHERE id=?")->execute([$fileType, $fileName, $caseId]);
    $db->prepare("DELETE FROM fsd_case_field WHERE case_id=?")->execute([$caseId]);
    $db->prepare("DELETE FROM fsd_case_page WHERE case_id=?")->execute([$caseId]);
    return ['ok'=>true];
}

function fsd_case_pages_save(PDO $db, int $caseId, array $pages): void {
    $db->prepare("DELETE FROM fsd_case_page WHERE case_id=?")->execute([$caseId]);
    $ins = $db->prepare("INSERT INTO fsd_case_page (case_id,page_no,width_pt,height_pt,rotation,paper_size,crop_x,crop_y,crop_w,crop_h) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($pages as $p) {
        $paper = in_array($p['paper_size'] ?? '', ['A4','A3'], true) ? $p['paper_size'] : null;
        $ins->execute([$caseId, (int)$p['page_no'], (float)$p['width_pt'], (float)$p['height_pt'], (int)($p['rotation'] ?? 0) % 360,
            $paper, (float)($p['crop_x'] ?? 0), (float)($p['crop_y'] ?? 0), (float)($p['crop_w'] ?? 1), (float)($p['crop_h'] ?? 1)]);
    }
}

function fsd_case_pages_get(PDO $db, int $caseId): array {
    $st = $db->prepare("SELECT * FROM fsd_case_page WHERE case_id=? ORDER BY page_no");
    $st->execute([$caseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 白名單：案件能拖放的欄位＝樣板本身已框選過的(slot_key,box_type)組合而已；樣板沒框選過的欄位案件也不會有。 */
function fsd_case_field_whitelist(PDO $db, array $case): array {
    $schema = fsd_case_schema($db, $case);
    $out = [];
    foreach ($schema['fields'] ?? [] as $f) $out[$f['slot_key'] . '_' . $f['box_type']] = true;
    return $out;
}

function fsd_case_field_list(PDO $db, int $caseId): array {
    $st = $db->prepare("SELECT * FROM fsd_case_field WHERE case_id=? ORDER BY page_no,id");
    $st->execute([$caseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 存一個案件框選區塊；只允許草稿狀態編輯；(slot_key,box_type) 必須在樣板白名單內；圖章框最小尺寸驗證同樣板規則。 */
function fsd_case_field_save(PDO $db, int $caseId, array $f): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'draft') return ['ok'=>false, 'msg'=>'僅草稿狀態可調整框選'];
    $slotKey = trim((string)($f['slot_key'] ?? ''));
    $boxType = ($f['box_type'] ?? '') === 'reply' ? 'reply' : 'stamp';
    $whitelist = fsd_case_field_whitelist($db, $case);
    if (!$slotKey || !isset($whitelist[$slotKey . '_' . $boxType])) return ['ok'=>false, 'msg'=>'樣板未提供此欄位，無法框選（樣板本身沒有框選過這個位置）'];
    $pageNo = (int)($f['page_no'] ?? 1);
    $x = (float)($f['x'] ?? 0); $y = (float)($f['y'] ?? 0);
    $w = (float)($f['w'] ?? 0); $h = (float)($f['h'] ?? 0);
    if ($boxType === 'stamp') {
        $pst = $db->prepare("SELECT width_pt,height_pt FROM fsd_case_page WHERE case_id=? AND page_no=?");
        $pst->execute([$caseId, $pageNo]);
        $page = $pst->fetch(PDO::FETCH_ASSOC);
        if ($page) {
            $schema = fsd_case_schema($db, $case);
            $stampSchema = $schema['stamp_tpl']['schema'] ?? null;
            $min = fsd_field_min_frac($page, $stampSchema);
            if ($w < $min['min_w'] || $h < $min['min_h']) {
                return ['ok'=>false, 'msg'=>sprintf('圖章框太小，至少需要頁面寬度%.1f%%、高度%.1f%%（依樣板綁定的圖章模板設定尺寸換算，未綁定模板則比照全站列印91px標準），請拖大一點', $min['min_w']*100, $min['min_h']*100)];
            }
        }
    }
    $dup = $db->prepare("SELECT id FROM fsd_case_field WHERE case_id=? AND slot_key=? AND box_type=?");
    $dup->execute([$caseId, $slotKey, $boxType]);
    $id = (int)($dup->fetchColumn() ?: 0);
    if ($id) {
        $db->prepare("UPDATE fsd_case_field SET page_no=?,x=?,y=?,w=?,h=? WHERE id=?")->execute([$pageNo, $x, $y, $w, $h, $id]);
    } else {
        $db->prepare("INSERT INTO fsd_case_field (case_id,slot_key,box_type,page_no,x,y,w,h) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$caseId, $slotKey, $boxType, $pageNo, $x, $y, $w, $h]);
        $id = (int)$db->lastInsertId();
    }
    return ['ok'=>true, 'id'=>$id, 'fields'=>fsd_case_field_list($db, $caseId)];
}

function fsd_case_field_delete(PDO $db, int $caseId, int $fieldId): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'draft') return ['ok'=>false, 'msg'=>'僅草稿狀態可調整框選'];
    $db->prepare("DELETE FROM fsd_case_field WHERE id=? AND case_id=?")->execute([$fieldId, $caseId]);
    return ['ok'=>true, 'fields'=>fsd_case_field_list($db, $caseId)];
}

/** 整頁清空框選(旋轉該頁時用)。 */
function fsd_case_field_delete_by_page(PDO $db, int $caseId, int $pageNo): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'draft') return ['ok'=>false, 'msg'=>'僅草稿狀態可調整框選'];
    $db->prepare("DELETE FROM fsd_case_field WHERE case_id=? AND page_no=?")->execute([$caseId, $pageNo]);
    return ['ok'=>true, 'fields'=>fsd_case_field_list($db, $caseId)];
}

/** 草稿→送出：轉 in_progress 並開始跑第1關（原本 fsd_case_create 送出部分抽出來，讓建立與送出分開兩步）。 */
function fsd_case_submit(PDO $db, int $caseId, int $uid): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'draft') return ['ok'=>false, 'msg'=>'此案件已送出，不可重複送出'];
    if ((int)$case['applicant_id'] !== $uid) return ['ok'=>false, 'msg'=>'只有申請人本人可以送出'];
    if (!$case['file_name']) return ['ok'=>false, 'msg'=>'請先上傳要簽核的文件'];
    $db->prepare("UPDATE fsd_case SET status='in_progress',submitted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$caseId]);
    $case['status'] = 'in_progress';
    $schema = fsd_case_schema($db, $case);
    $r = fsd_case_go_next_stage($db, $case, $schema);
    if (!$r['ok']) return $r;
    return ['ok'=>true, 'status'=>$r['status'] ?? 'in_progress'];
}

/** 草稿刪除：申請人本人或管理員，什麼都還沒開始跑，直接硬刪不留紀錄。 */
function fsd_case_delete_draft(PDO $db, int $caseId, int $uid, bool $isAdminOrCanAdmin): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'draft') return ['ok'=>false, 'msg'=>'此案件已送出，請改用刪除功能（依權限走硬刪/軟刪流程）'];
    if ((int)$case['applicant_id'] !== $uid && !$isAdminOrCanAdmin) return ['ok'=>false, 'msg'=>'只有申請人本人或管理員可以刪除'];
    $db->prepare("DELETE FROM fsd_case_field WHERE case_id=?")->execute([$caseId]);
    $db->prepare("DELETE FROM fsd_case_page WHERE case_id=?")->execute([$caseId]);
    $db->prepare("DELETE FROM fsd_case WHERE id=?")->execute([$caseId]);
    return ['ok'=>true];
}

/** 超級管理員(id=1)硬刪：任何狀態的案件都能刪，不寫刪除紀錄。呼叫端(API)自行驗證 $uid===1，這裡不重複驗證。 */
function fsd_case_delete_hard(PDO $db, int $caseId): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    $db->prepare("DELETE FROM fsd_case_response WHERE case_id=?")->execute([$caseId]);
    $db->prepare("DELETE FROM approval_record WHERE module='form_signer' AND entity_id=?")->execute([$caseId]);
    $evIds = $db->prepare("SELECT id FROM live_event WHERE ref_type LIKE 'FSD_%' AND ref_id=?");
    $evIds->execute([$caseId]);
    $ids = array_map('intval', $evIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $in = implode(',', $ids);
        $db->exec("DELETE FROM live_event_target WHERE live_event_id IN ($in)");
        $db->exec("DELETE FROM live_event WHERE id IN ($in)");
    }
    $db->prepare("DELETE FROM fsd_case_field WHERE case_id=?")->execute([$caseId]);
    $db->prepare("DELETE FROM fsd_case_page WHERE case_id=?")->execute([$caseId]);
    $db->prepare("DELETE FROM fsd_case_delete_log WHERE case_id=?")->execute([$caseId]);
    $db->prepare("DELETE FROM fsd_case WHERE id=?")->execute([$caseId]);
    return ['ok'=>true];
}

/** 一般管理員(有操作確認密碼)軟刪：轉 void 並記錄可復原；呼叫端(API)自行驗證操作確認密碼，這裡不重複驗證。 */
function fsd_case_delete_soft(PDO $db, int $caseId, int $byUid, string $byName): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] === 'void') return ['ok'=>false, 'msg'=>'此案件已是刪除狀態'];
    $db->prepare("INSERT INTO fsd_case_delete_log (case_id,prior_status,deleted_by,deleted_by_name) VALUES (?,?,?,?)")
       ->execute([$caseId, $case['status'], $byUid, $byName]);
    $db->prepare("UPDATE fsd_case SET status='void',updated_at=NOW() WHERE id=?")->execute([$caseId]);
    return ['ok'=>true];
}

/** 復原軟刪：取回最近一筆尚未復原的刪除紀錄，把狀態改回去。 */
function fsd_case_restore(PDO $db, int $caseId, int $byUid, string $byName): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'void') return ['ok'=>false, 'msg'=>'此案件不是刪除狀態'];
    $st = $db->prepare("SELECT * FROM fsd_case_delete_log WHERE case_id=? AND restored_at IS NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$caseId]);
    $log = $st->fetch(PDO::FETCH_ASSOC);
    if (!$log) return ['ok'=>false, 'msg'=>'找不到可復原的刪除紀錄(可能是超級管理員硬刪,硬刪不留紀錄無法復原)'];
    $db->prepare("UPDATE fsd_case SET status=?,updated_at=NOW() WHERE id=?")->execute([$log['prior_status'], $caseId]);
    $db->prepare("UPDATE fsd_case_delete_log SET restored_by=?,restored_by_name=?,restored_at=NOW() WHERE id=?")
       ->execute([$byUid, $byName, $log['id']]);
    return ['ok'=>true, 'status'=>$log['prior_status']];
}

/** 已刪除(void)案件清單，含最近一筆刪除紀錄，供管理員檢視/復原。 */
function fsd_case_deleted_list(PDO $db): array {
    $rows = $db->query("SELECT c.*, t.name AS template_name FROM fsd_case c JOIN fsd_template t ON t.id=c.template_id
                        WHERE c.status='void' ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $st = $db->prepare("SELECT * FROM fsd_case_delete_log WHERE case_id=? AND restored_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$r['id']]);
        $r['delete_log'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $rows;
}

/** 意見階段回應：同意/不同意+回覆文字，沒有駁回動作、不卡關。 */
function fsd_case_advisory_respond(PDO $db, int $caseId, int $uid, string $uname, string $decision, string $replyText): array {
    if (!in_array($decision, ['agree','disagree'], true)) return ['ok'=>false, 'msg'=>'決定值不正確'];
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'in_progress') return ['ok'=>false, 'msg'=>'此案件已結束，不可再回應'];
    $st = $db->prepare("SELECT * FROM fsd_case_response WHERE case_id=? AND stage_seq=? AND resolved_user_id=? AND decision IS NULL");
    $st->execute([$caseId, (int)$case['current_stage_seq'], $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok'=>false, 'msg'=>'目前沒有待您回應的意見項目'];
    $db->prepare("UPDATE fsd_case_response SET decision=?,reply_text=?,responded_at=NOW() WHERE id=?")
       ->execute([$decision, $replyText, $row['id']]);
    return fsd_case_advance_if_ready($db, $caseId);
}

/** 決策階段回應：approved/rejected，OR-gate 由 eg_approval_decide 內建競態鎖保證先搶先贏。 */
/** 決策階段回應(規格「決定型/線性」)：多槽位是依序一關關來(如「審核」通過才輪到「核准」)，不是誰先簽就算數的OR-gate。
 *  駁回一律立即終止整個案件(不管是鏈中第幾位駁回)；核准則往同一階段的下一個槽位推進，該階段全部槽位都過了才算此關結束。 */
function fsd_case_decision_respond(PDO $db, int $caseId, int $uid, string $uname, string $decision, ?string $note = null): array {
    if (!in_array($decision, ['approved','rejected'], true)) return ['ok'=>false, 'msg'=>'決定值不正確'];
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'in_progress') return ['ok'=>false, 'msg'=>'此案件已結束，不可再處理'];
    $stageSeq = (int)$case['current_stage_seq'];
    $level = 'stage_' . $stageSeq;
    $rec = eg_approval_latest($db, 'form_signer', $caseId, $level);
    if (!$rec || $rec['status'] !== 'pending') return ['ok'=>false, 'msg'=>'目前沒有待您決策的項目'];
    $schema = fsd_case_schema($db, $case);
    $stage = fsd_case_find_stage($schema, $stageSeq);
    if (!$stage) return ['ok'=>false, 'msg'=>'找不到此階段'];
    $sg = fsd_case_decision_next_pending_signer($db, $caseId, $stageSeq, $stage);
    if (!$sg) return ['ok'=>false, 'msg'=>'目前沒有待您決策的項目'];
    $resolved = fsd_resolve_signer($db, $sg, $case);
    if (!$resolved || (int)$resolved['id'] !== $uid) return ['ok'=>false, 'msg'=>'您不是此案件目前這一位的決策人'];
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note);
    if (!$r['success']) return ['ok'=>false, 'msg'=>$r['message']];
    $db->prepare("INSERT INTO fsd_case_response (case_id,stage_seq,slot_key,resolved_user_id,resolved_user_name,decision,reply_text,responded_at)
                  VALUES (?,?,?,?,?,?,?,NOW())")
       ->execute([$caseId, $stageSeq, $sg['slot_key'], $uid, $uname, $decision, $note]);
    if ($decision === 'rejected') {
        $db->prepare("UPDATE fsd_case SET status='rejected',updated_at=NOW() WHERE id=?")->execute([$caseId]);
        fsd_notify($db, $caseId, [(int)$case['applicant_id']], '您的案件已被退回',
            $uname . ' 退回了「' . ($case['title'] ?: '案件') . '」。原因：' . ($note ?: '(未填寫)'), $uid, 'FSD_RESULT', 'read');
        return ['ok'=>true, 'status'=>'rejected'];
    }
    return fsd_case_decision_advance($db, $case, $schema, $stageSeq);
}

/** 催辦(比照使用者確認的「逾期僅提醒不強制」)：對目前階段尚未回應的人重發一次通知，不強制略過/不自動推進。 */
function fsd_case_urge(PDO $db, int $caseId, int $byUid): array {
    $case = fsd_case_get($db, $caseId);
    if (!$case) return ['ok'=>false, 'msg'=>'找不到此案件'];
    if ($case['status'] !== 'in_progress') return ['ok'=>false, 'msg'=>'此案件已結束'];
    $stageSeq = (int)$case['current_stage_seq'];
    $schema = fsd_case_schema($db, $case);
    $stage = null;
    foreach ($schema['stages'] ?? [] as $s) if ((int)$s['seq'] === $stageSeq) { $stage = $s; break; }
    if (!$stage) return ['ok'=>false, 'msg'=>'目前沒有進行中的階段'];
    if ($stage['stage_type'] === 'advisory') {
        $st = $db->prepare("SELECT resolved_user_id FROM fsd_case_response WHERE case_id=? AND stage_seq=? AND decision IS NULL AND resolved_user_id IS NOT NULL");
        $st->execute([$caseId, $stageSeq]);
        $uids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } else {
        // 決策階段(決定型/線性)：只催辦目前輪到的那一位，不是整關所有槽位成員
        $level = 'stage_' . $stageSeq;
        $rec = eg_approval_latest($db, 'form_signer', $caseId, $level);
        $uids = [];
        if ($rec && $rec['status'] === 'pending') {
            $pendingSg = fsd_case_decision_next_pending_signer($db, $caseId, $stageSeq, $stage);
            if ($pendingSg) {
                $r = fsd_resolve_signer($db, $pendingSg, $case);
                if ($r) $uids[] = (int)$r['id'];
            }
        }
    }
    if (!$uids) return ['ok'=>false, 'msg'=>'目前沒有待處理人員可催辦'];
    fsd_notify($db, $caseId, $uids, '【催辦】「' . $stage['name'] . '」尚待您處理',
        ($case['applicant_name'] ?? '') . ' 的案件「' . ($case['title'] ?: '') . '」仍在等候您處理，請盡快回應。', $byUid, 'FSD_URGE', 'sign');
    return ['ok'=>true];
}

/* ============================================================ 列印 ============================================================ */

function fsd_asdoc_no_display(PDO $db, int $templateId, ?string $bizDate = null): string {
    return eg_asdoc_no_asof($db, fsd_asdoc_module($templateId), $bizDate);
}
