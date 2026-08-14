<?php
/**
 * 審核表單引擎（Review Form）共用函式庫 — 2026-08-11 新增
 * 首發模板：2-TD-04-01 仿冒零件防制審核表／2-TD-03-01 產品安全審核表（各自獨立模板，共用本引擎）
 *
 * 概念比照 meeting_record.php（模板→建立表單→逐列負責人簽名／通知簽名→審核/核准），
 * 但改成「管理員可自建模板」的通用引擎，不是寫死兩支頁面。
 *
 * 資料表（前綴 rf_，注意此前綴只用於 DB 表名；PHP 函式一律用 rvf_ 前綴——
 * role_features_helper.php 已經佔用 rf_ 前綴的函式命名空間，不可再撞名）：
 *   rf_template / rf_template_version / rf_template_maintainer_user /
 *   rf_instance / rf_instance_item / rf_instance_subitem / rf_instance_item_confirm
 *
 * 項目／小項兩層結構（2026-08-12 改版）：rf_instance_item 只是「項次分組容器」（項次編號＝這一組），
 * 實際內容/自訂欄位/負責部門/負責人/簽名全部在 rf_instance_subitem（每個小項各自獨立），
 * rf_instance_item_confirm.subitem_id 對應到小項不是項目。詳見 rvf_instance_items_get() 上方註解。
 *
 * 重用不新建：AS 文件綁定走 asdoc_lib.php（module code 動態組 'review_form_tpl_'.$id）；
 * 審核/核准走共用 approval_record（module='review_form', level='review'/'approval'，approval_lib.php）；
 * 通知走 live_event/live_event_target；核准人優先序鏈手法比照 vendor_audit_lib.php
 * 的 vendor_audit_plan_approver_pool()（ai-rules/19 第五節已定案為可複用寫法），但改讀本模板列
 * 自己的 approver_dept_id/approver_user_id（模板是動態建立的，不掛 org_role_lib 全站 registry）。
 */

require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/org_role_lib.php';

const RVF_APPROVER_METHODS = ['dept_or_user', 'auto_supervisor', 'top_approver'];

const RVF_FEATURES = [
    ['code' => 'rvf_view',          'group' => 'view', 'label' => '檢閱審核表單列表（沒勾也看得到自己建立的表單）'],
    ['code' => 'rvf_view_all',      'group' => 'view', 'label' => '檢視全部人員建立的表單'],
    ['code' => 'rvf_create',        'group' => 'op',   'label' => '建立/填寫/送出表單'],
    ['code' => 'rvf_print',         'group' => 'op',   'label' => '列印'],
    ['code' => 'rvf_template_admin','group' => 'op',   'label' => '模板管理（新建模板、AS文件綁定、審核/核准設定、維護部門設定）'],
];

/* ============================================================ 使用者/權限 ============================================================ */

function rvf_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rvf_perms(PDO $db, ?array $u): array {
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
    $codes = array_values(array_intersect($feat, array_column(RVF_FEATURES, 'code')));
    $has = function (string $code) use ($isAdmin, $feat, $codes) { return $isAdmin || in_array('all', $feat, true) || in_array($code, $codes, true); };
    return [
        'isAdmin'    => $isAdmin,
        'canAdmin'   => $has('rvf_template_admin'),
        'canCreate'  => $has('rvf_create'),
        'canView'    => $has('rvf_view') || $has('rvf_create') || $has('rvf_template_admin') || true, // 每個登入者至少看得到自己建立的表單
        'canViewAll' => $has('rvf_view_all') || $has('rvf_template_admin'),
        'canPrint'   => $has('rvf_print') || $has('rvf_create') || $has('rvf_template_admin'),
    ];
}

function rvf_verify_own_password(PDO $db, int $forUid, string $password): array {
    if ($forUid <= 0) return ['ok'=>false, 'msg'=>'請先選擇人員'];
    if ($password === '') return ['ok'=>false, 'msg'=>'請輸入密碼'];
    try {
        $st = $db->prepare("SELECT user_password FROM `user` WHERE id=?");
        $st->execute([$forUid]);
        $real = $st->fetchColumn();
        if ($real === false) return ['ok'=>false, 'msg'=>'查無此人員'];
        if (!hash_equals((string)$real, $password)) return ['ok'=>false, 'msg'=>'密碼錯誤，請由本人輸入自己的密碼'];
        return ['ok'=>true, 'msg'=>''];
    } catch (Throwable $e) { return ['ok'=>false, 'msg'=>'驗證失敗']; }
}

/* ============================================================ CSRF（比照 Leave_API.php 模式） ============================================================ */

function rvf_csrf_token(): string {
    if (empty($_SESSION['rvf_csrf'])) $_SESSION['rvf_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['rvf_csrf'];
}
function rvf_csrf_ok(?string $t): bool {
    return $t !== null && hash_equals((string)($_SESSION['rvf_csrf'] ?? ''), (string)$t);
}
function rvf_need_csrf(): void {
    if (!rvf_csrf_ok($_POST['csrf'] ?? null)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'error'=>'CSRF token 驗證失敗，請重新整理頁面']);
        exit;
    }
}

/* ============================================================ 部門主管 / 審核池 / 核准鏈 ============================================================ */

/** 部門(含子部門)內具主管職級者；$maxLevel 有給值時只留職級不高於它的(數字越小職級越高，比照 vendor_audit 手法)。 */
function rvf_dept_managers(PDO $db, ?int $deptId, ?int $maxLevel = null): array {
    if (!$deptId) return [];
    $deptIds = eg_dept_subtree_ids($db, $deptId);
    if (!$deptIds) return [];
    try {
        $in = implode(',', array_fill(0, count($deptIds), '?'));
        $st = $db->prepare("SELECT u.id, u.user_cname, MIN(pl.level) AS lvl
                            FROM user_department_position_map m
                            JOIN user u ON u.id=m.user_id
                            JOIN position_level pl ON pl.position_id=m.position_id AND pl.level IS NOT NULL
                            WHERE m.department_id IN ($in) AND COALESCE(u.state,1) NOT IN (0,90)
                            GROUP BY u.id, u.user_cname");
        $st->execute($deptIds);
        $pool = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($maxLevel === null || (int)$r['lvl'] <= $maxLevel) $pool[] = ['id'=>(int)$r['id'], 'user_cname'=>$r['user_cname']];
        }
        return $pool;
    } catch (Throwable $e) { return []; }
}

/** 審核池（OR-gate）：審核部門內任一主管，不比較職級高低。 */
function rvf_review_pool(PDO $db, array $tpl): array {
    return rvf_dept_managers($db, $tpl['review_dept_id'] ? (int)$tpl['review_dept_id'] : null);
}

function rvf_approver_pool_dept_or_user(PDO $db, array $tpl, int $submitterUid): array {
    $deptId = !empty($tpl['approver_dept_id']) ? (int)$tpl['approver_dept_id'] : null;
    $userId = !empty($tpl['approver_user_id']) ? (int)$tpl['approver_user_id'] : null;
    if ($userId) {
        try {
            $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
            $st->execute([$userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            return $u ? [$u] : [];
        } catch (Throwable $e) { return []; }
    }
    if (!$deptId) return [];
    $identity = eg_user_main_identity($db, $submitterUid);
    $lvl = $identity['level'] ?? null;
    return rvf_dept_managers($db, $deptId, $lvl);
}

function rvf_approver_pool_auto_supervisor(PDO $db, int $submitterUid): array {
    $supId = eg_resolve_supervisor($db, $submitterUid);
    if (!$supId || (int)$supId === $submitterUid) return [];
    try {
        $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
        $st->execute([$supId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ? [$u] : [];
    } catch (Throwable $e) { return []; }
}

function rvf_approver_pool_top_approver(PDO $db): array {
    $u = eg_org_user($db, 'top_approver');
    return $u ? [['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname']]] : [];
}

/** 核准人員解析——依模板設定的優先序鏈依序嘗試，取第一個有結果的方法；強制 SoD（送出者本人一律剔除）。 */
function rvf_approver_pool(PDO $db, array $tpl, int $submitterUid): array {
    $chain = json_decode((string)($tpl['approver_chain_json'] ?? ''), true);
    if (!is_array($chain) || !$chain) $chain = ['top_approver'];
    foreach ($chain as $method) {
        $pool = match ($method) {
            'dept_or_user'    => rvf_approver_pool_dept_or_user($db, $tpl, $submitterUid),
            'auto_supervisor' => rvf_approver_pool_auto_supervisor($db, $submitterUid),
            'top_approver'    => rvf_approver_pool_top_approver($db),
            default => [],
        };
        $pool = array_values(array_filter($pool, fn($p) => (int)$p['id'] !== $submitterUid));
        if ($pool) return $pool;
    }
    return [];
}

/* ============================================================ 模板 CRUD ============================================================ */

function rvf_asdoc_module(int $templateId): string { return 'review_form_tpl_' . $templateId; }

function rvf_template_list(PDO $db): array {
    $rows = $db->query("SELECT * FROM rf_template ORDER BY (status='active') DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['as_doc'] = eg_asdoc_get($db, rvf_asdoc_module((int)$r['id']));
    return $rows;
}

function rvf_template_get(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM rf_template WHERE id=?");
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if ($t) {
        $t['as_doc'] = eg_asdoc_get($db, rvf_asdoc_module($id));
        $t['list_stamp'] = rvf_stamp_tpl_get($db, (int)($t['list_stamp_tpl_id'] ?? 0));
        $t['footer_stamp'] = rvf_stamp_tpl_get($db, (int)($t['footer_stamp_tpl_id'] ?? 0));
    }
    return $t ?: null;
}

/** 圖章模板（stamp_template）：id=0 或查無資料回 null，呼叫端沒拿到就用 EGStamp 預設樣式。 */
function rvf_stamp_tpl_get(PDO $db, int $tplId): ?array {
    if (!$tplId) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$tplId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}

/** 圖章模板下拉選單用清單（供設定頁挑選）。 */
function rvf_stamp_tpl_options(PDO $db): array {
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name
                           FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 管理員限定：建立/修改模板設定（不含項次內容 schema，schema 走 rvf_template_schema_save）。 */
function rvf_template_settings_save(PDO $db, int $id, array $d, string $byName): int {
    $chain = json_encode(array_values(array_intersect($d['approver_chain'] ?? ['top_approver'], RVF_APPROVER_METHODS)) ?: ['top_approver']);
    $orientation = ($d['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
    if ($id) {
        $db->prepare("UPDATE rf_template SET name=?,paper_size=?,orientation=?,list_stamp_tpl_id=?,footer_stamp_tpl_id=?,
                      need_review=?,auto_review=?,review_dept_id=?,need_approval=?,auto_approval=?,
                      approver_dept_id=?,approver_user_id=?,approver_chain_json=?,maintain_dept_id=?,updated_by=?,updated_at=NOW() WHERE id=?")
           ->execute([$d['name'], $d['paper_size'], $orientation, $d['list_stamp_tpl_id'] ?: null, $d['footer_stamp_tpl_id'] ?: null,
                      $d['need_review']?1:0, $d['auto_review']?1:0, $d['review_dept_id'] ?: null,
                      $d['need_approval']?1:0, $d['auto_approval']?1:0, $d['approver_dept_id'] ?: null, $d['approver_user_id'] ?: null,
                      $chain, $d['maintain_dept_id'] ?: null, $byName, $id]);
        return $id;
    }
    $db->prepare("INSERT INTO rf_template (name,paper_size,orientation,list_stamp_tpl_id,footer_stamp_tpl_id,
                  need_review,auto_review,review_dept_id,need_approval,auto_approval,approver_dept_id,
                  approver_user_id,approver_chain_json,maintain_dept_id,current_schema_json,published_version,created_by)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)")
       ->execute([$d['name'], $d['paper_size'], $orientation, $d['list_stamp_tpl_id'] ?: null, $d['footer_stamp_tpl_id'] ?: null,
                  $d['need_review']?1:0, $d['auto_review']?1:0, $d['review_dept_id'] ?: null,
                  $d['need_approval']?1:0, $d['auto_approval']?1:0, $d['approver_dept_id'] ?: null, $d['approver_user_id'] ?: null,
                  $chain, $d['maintain_dept_id'] ?: null,
                  json_encode(['fields'=>[], 'sign_mode'=>'password'], JSON_UNESCAPED_UNICODE), $byName]);
    return (int)$db->lastInsertId();
}

/** 誰能改模板設定：僅管理員（呼叫端已用 $perms['canAdmin'] 守門，這支保留供 API 二次確認）。 */
function rvf_can_edit_settings(bool $isAdmin): bool { return $isAdmin; }

/** 誰能改「項次內容」：管理員 OR 維護部門內主管 OR 被指派的維護人員。 */
function rvf_can_edit_items(PDO $db, int $uid, bool $isAdmin, array $tpl): bool {
    if ($isAdmin) return true;
    if (!empty($tpl['maintain_dept_id'])) {
        foreach (rvf_dept_managers($db, (int)$tpl['maintain_dept_id']) as $m) if ((int)$m['id'] === $uid) return true;
    }
    $st = $db->prepare("SELECT 1 FROM rf_template_maintainer_user WHERE template_id=? AND user_id=?");
    $st->execute([(int)$tpl['id'], $uid]);
    return (bool)$st->fetchColumn();
}

/** 存項次內容 schema（存檔即生效，見鐵律確認：使用者已選定「存檔即改版生效」）。$bumpedAsDocVersionId 有值代表這次連動 AS 文件改版。 */
function rvf_template_schema_save(PDO $db, int $templateId, array $schema, string $byName, ?int $bumpedAsDocVersionId = null): int {
    $tpl = rvf_template_get($db, $templateId);
    if (!$tpl) throw new Exception('找不到此模板');
    $newVersion = (int)$tpl['published_version'] + 1;
    $json = json_encode($schema, JSON_UNESCAPED_UNICODE);
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO rf_template_version (template_id,version,schema_json,bumped_as_doc_version_id,created_by) VALUES (?,?,?,?,?)")
           ->execute([$templateId, $newVersion, $json, $bumpedAsDocVersionId, $byName]);
        $db->prepare("UPDATE rf_template SET current_schema_json=?,published_version=?,updated_by=?,updated_at=NOW() WHERE id=?")
           ->execute([$json, $newVersion, $byName, $templateId]);
        $db->commit();
        return $newVersion;
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

function rvf_template_schema_at_version(PDO $db, int $templateId, int $version): ?array {
    $st = $db->prepare("SELECT schema_json FROM rf_template_version WHERE template_id=? AND version=?");
    $st->execute([$templateId, $version]);
    $j = $st->fetchColumn();
    return $j ? (json_decode((string)$j, true) ?: null) : null;
}

function rvf_maintainer_list(PDO $db, int $templateId): array {
    $st = $db->prepare("SELECT mu.user_id, u.user_cname, mu.added_by, mu.added_at
                        FROM rf_template_maintainer_user mu JOIN user u ON u.id=mu.user_id
                        WHERE mu.template_id=? ORDER BY mu.added_at DESC");
    $st->execute([$templateId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function rvf_maintainer_add(PDO $db, int $templateId, int $userId, string $byName): void {
    $db->prepare("INSERT IGNORE INTO rf_template_maintainer_user (template_id,user_id,added_by) VALUES (?,?,?)")
       ->execute([$templateId, $userId, $byName]);
}
function rvf_maintainer_remove(PDO $db, int $templateId, int $userId): void {
    $db->prepare("DELETE FROM rf_template_maintainer_user WHERE template_id=? AND user_id=?")->execute([$templateId, $userId]);
}

/** 複製模板（2026-08-14 新增，使用者明確要求）：設定（審核/核准/圖章樣式/維護部門）與目前生效的項次欄位定義
 *  一起複製成一筆全新模板，名稱自動加「(複製)」尾碼避免混淆；維護人員名單也一併複製，省得管理員重新指派。
 *  **不複製 AS 文件綁定**——那是每個模板各自獨立的文件編號，複製出來的是全新模板，不該自動掛在來源模板
 *  的文件編號上（AS_DOC_BIND 用模板 id 當 key，複製出的新模板 id 本來就沒有綁定紀錄，天然是未綁定狀態，
 *  管理員複製後要記得自己到「編輯設定」另外綁）。複製出來的模板版本從 1 開始，不沿用來源模板的版本號
 *  （兩者送出後是完全獨立的模板，版本序不該互相牽扯）。 */
function rvf_template_duplicate(PDO $db, int $srcId, string $byName): int {
    $src = rvf_template_get($db, $srcId);
    if (!$src) throw new Exception('找不到來源模板');
    $newId = rvf_template_settings_save($db, 0, [
        'name' => $src['name'] . '(複製)',
        'paper_size' => $src['paper_size'],
        'orientation' => $src['orientation'],
        'list_stamp_tpl_id' => $src['list_stamp_tpl_id'],
        'footer_stamp_tpl_id' => $src['footer_stamp_tpl_id'],
        'need_review' => $src['need_review'], 'auto_review' => $src['auto_review'], 'review_dept_id' => $src['review_dept_id'],
        'need_approval' => $src['need_approval'], 'auto_approval' => $src['auto_approval'],
        'approver_dept_id' => $src['approver_dept_id'], 'approver_user_id' => $src['approver_user_id'],
        'approver_chain' => json_decode((string)$src['approver_chain_json'], true) ?: ['top_approver'],
        'maintain_dept_id' => $src['maintain_dept_id'],
    ], $byName);
    $schema = json_decode((string)$src['current_schema_json'], true) ?: ['fields'=>[], 'sign_mode'=>'password'];
    rvf_template_schema_save($db, $newId, $schema, $byName);
    foreach (rvf_maintainer_list($db, $srcId) as $m) rvf_maintainer_add($db, $newId, (int)$m['user_id'], $byName);
    return $newId;
}

/* ============================================================ 表單（instance） ============================================================ */

function rvf_instance_create(PDO $db, int $templateId, int $uid, string $uname, string $title, string $bizDate): int {
    $tpl = rvf_template_get($db, $templateId);
    if (!$tpl) throw new Exception('找不到此模板');
    $db->prepare("INSERT INTO rf_instance (template_id,template_version,title,business_date,status,created_by,created_by_name)
                  VALUES (?,?,?,?,'draft',?,?)")
       ->execute([$templateId, (int)$tpl['published_version'], $title, $bizDate ?: date('Y-m-d'), $uid, $uname]);
    return (int)$db->lastInsertId();
}

function rvf_instance_get(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM rf_instance WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rvf_instance_list(PDO $db, int $templateId = 0, ?int $onlyCreatedBy = null): array {
    $sql = "SELECT * FROM rf_instance WHERE 1=1";
    $params = [];
    if ($templateId) { $sql .= " AND template_id=?"; $params[] = $templateId; }
    if ($onlyCreatedBy !== null) { $sql .= " AND created_by=?"; $params[] = $onlyCreatedBy; }
    $sql .= " ORDER BY id DESC";
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* 項目／小項採兩層結構（2026-08-12 改版，使用者明確要求）：rf_instance_item 純粹是「項次分組容器」
 * （id/instance_id/sort_order，項次編號＝這一組），實際內容/自訂欄位/負責部門/負責人/簽名全部掛在
 * rf_instance_subitem（每個小項各自獨立，不合併），簽名紀錄 rf_instance_item_confirm.subitem_id 對應到小項。
 * 一個項目至少保留 1 筆小項；小項數量不限。舊欄位（content/data_json/subitems_json/owner_depts/owner_users/
 * date_values_json）留在 rf_instance_item 上不刪，但改版後不再寫入，只在完全沒有小項列時當退回值讀（理論上
 * 遷移後不會發生，純保險）。 */
function rvf_instance_items_get(PDO $db, int $instanceId): array {
    $st = $db->prepare("SELECT * FROM rf_instance_item WHERE instance_id=? ORDER BY sort_order,id");
    $st->execute([$instanceId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$it) {
        $sst = $db->prepare("SELECT * FROM rf_instance_subitem WHERE item_id=? ORDER BY sort_order,id");
        $sst->execute([$it['id']]);
        $subs = $sst->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) {
            $subs = [['id'=>0, 'item_id'=>$it['id'], 'sort_order'=>0,
                      'content'=>(string)$it['content'], 'data_json'=>$it['data_json'],
                      'owner_depts'=>$it['owner_depts'], 'owner_users'=>$it['owner_users']]];
        }
        foreach ($subs as &$s) {
            $s['id'] = (int)$s['id'];
            $s['data'] = json_decode((string)$s['data_json'], true) ?: new stdClass();
            $s['confirms'] = $s['id'] ? (function() use ($db, $s) {
                $cst = $db->prepare("SELECT * FROM rf_instance_item_confirm WHERE subitem_id=? ORDER BY signed_at");
                $cst->execute([$s['id']]);
                return $cst->fetchAll(PDO::FETCH_ASSOC);
            })() : [];
        }
        unset($s);
        $it['subitems'] = array_values($subs);
    }
    return $items;
}

/** 逐列存檔：項目與小項都以 id 對應差異更新（有 id 且存在→UPDATE，否則→INSERT），送出的清單裡沒出現的既有列→DELETE(連同簽名)。
 *  不採用「delete+insert 全部重來」——那會讓每次存檔後 id 全部改變，簽名/通知會對不上（meeting_record 就踩過這個坑，
 *  在那邊靠額外的 id remap 補救；這裡直接用差異更新從根本避開，比較乾淨)。只允許 status='draft' 時呼叫。 */
function rvf_instance_items_save(PDO $db, int $instanceId, array $items): void {
    $st = $db->prepare("SELECT id FROM rf_instance_item WHERE instance_id=?");
    $st->execute([$instanceId]);
    $existingItemIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $keepItemIds = [];
    $insItem = $db->prepare("INSERT INTO rf_instance_item (instance_id,sort_order) VALUES (?,?)");
    $updItem = $db->prepare("UPDATE rf_instance_item SET sort_order=? WHERE id=? AND instance_id=?");
    $sort = 0;
    foreach ($items as $it) {
        $itemId = (int)($it['id'] ?? 0);
        if ($itemId && in_array($itemId, $existingItemIds, true)) {
            $updItem->execute([$sort, $itemId, $instanceId]);
        } else {
            $insItem->execute([$instanceId, $sort]);
            $itemId = (int)$db->lastInsertId();
        }
        $keepItemIds[] = $itemId;
        rvf_subitems_save($db, $itemId, is_array($it['subitems'] ?? null) ? $it['subitems'] : []);
        $sort++;
    }
    $toDeleteItems = array_values(array_diff($existingItemIds, $keepItemIds));
    if ($toDeleteItems) {
        $in = implode(',', array_fill(0, count($toDeleteItems), '?'));
        $subSt = $db->prepare("SELECT id FROM rf_instance_subitem WHERE item_id IN ($in)");
        $subSt->execute($toDeleteItems);
        $subIds = array_map('intval', $subSt->fetchAll(PDO::FETCH_COLUMN));
        if ($subIds) {
            $in2 = implode(',', array_fill(0, count($subIds), '?'));
            $db->prepare("DELETE FROM rf_instance_item_confirm WHERE subitem_id IN ($in2)")->execute($subIds);
            $db->prepare("DELETE FROM rf_instance_subitem WHERE id IN ($in2)")->execute($subIds);
        }
        $db->prepare("DELETE FROM rf_instance_item WHERE id IN ($in)")->execute($toDeleteItems);
    }
}

/** 差異更新一個項目底下的所有小項（邏輯比照上面對項目本身的差異更新，保留 id 才能讓簽名對得上）。 */
function rvf_subitems_save(PDO $db, int $itemId, array $subitems): void {
    $st = $db->prepare("SELECT id FROM rf_instance_subitem WHERE item_id=?");
    $st->execute([$itemId]);
    $existingIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $keepIds = [];
    $ins = $db->prepare("INSERT INTO rf_instance_subitem (item_id,sort_order,content,data_json,owner_depts,owner_users) VALUES (?,?,?,?,?,?)");
    $upd = $db->prepare("UPDATE rf_instance_subitem SET sort_order=?,content=?,data_json=?,owner_depts=?,owner_users=? WHERE id=? AND item_id=?");
    if (!$subitems) $subitems = [['content'=>'', 'data'=>new stdClass(), 'owner_depts'=>[], 'owner_users'=>[]]];
    $sort = 0;
    foreach ($subitems as $s) {
        $content  = (string)($s['content'] ?? '');
        $dataJson = json_encode($s['data'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
        $ownDepts = implode(',', array_values(array_filter(array_map('intval', $s['owner_depts'] ?? []))));
        $ownUsers = implode(',', array_values(array_filter(array_map('intval', $s['owner_users'] ?? []))));
        $id = (int)($s['id'] ?? 0);
        if ($id && in_array($id, $existingIds, true)) {
            $upd->execute([$sort, $content, $dataJson, $ownDepts, $ownUsers, $id, $itemId]);
            $keepIds[] = $id;
        } else {
            $ins->execute([$itemId, $sort, $content, $dataJson, $ownDepts, $ownUsers]);
            $keepIds[] = (int)$db->lastInsertId();
        }
        $sort++;
    }
    $toDelete = array_values(array_diff($existingIds, $keepIds));
    if ($toDelete) {
        $in = implode(',', array_fill(0, count($toDelete), '?'));
        $db->prepare("DELETE FROM rf_instance_item_confirm WHERE subitem_id IN ($in)")->execute($toDelete);
        $db->prepare("DELETE FROM rf_instance_subitem WHERE id IN ($in)")->execute($toDelete);
    }
}

/* -------- 逐列負責人簽名（2026-08-12 改版：負責部門/負責人/簽名都改成掛在「小項」上，不是項目，
   每個小項各自獨立負責人＋各自簽；以下函式參數名沿用 $item 但實際傳入的是一筆小項陣列，欄位名相同不用改） -------- */

function rvf_item_required_signers(PDO $db, array $item): array {
    $ownerUsers = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_users'] ?? '')))));
    if ($ownerUsers) {
        $in = implode(',', array_fill(0, count($ownerUsers), '?'));
        $st = $db->prepare("SELECT id, user_cname FROM user WHERE id IN ($in) AND COALESCE(state,1) NOT IN (0,90)");
        $st->execute($ownerUsers);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $ownerDepts = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_depts'] ?? '')))));
    if ($ownerDepts) {
        $seen = []; $out = [];
        foreach ($ownerDepts as $d) foreach (rvf_dept_managers($db, $d) as $p) {
            if (!isset($seen[$p['id']])) { $seen[$p['id']] = 1; $out[] = $p; }
        }
        return $out;
    }
    return [];
}

/** 這個小項是否已完成簽名：指定人員模式每人都要簽(AND)；部門模式每個部門任一主管簽即算完成(OR，比照會議記錄)。 */
function rvf_item_is_fully_signed(PDO $db, array $item): bool {
    $doneUids = array_map(fn($c) => (int)$c['user_id'], $item['confirms'] ?? []);
    $ownerUsers = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_users'] ?? '')))));
    foreach ($ownerUsers as $u) if (!in_array($u, $doneUids, true)) return false;
    $ownerDepts = array_values(array_filter(array_map('intval', explode(',', (string)($item['owner_depts'] ?? '')))));
    foreach ($ownerDepts as $d) {
        $mgrIds = array_column(rvf_dept_managers($db, $d), 'id');
        if (!array_intersect($mgrIds, $doneUids)) return false;
    }
    return true;
}

function rvf_item_confirm(PDO $db, int $subitemId, int $forUid, string $password): array {
    $st = $db->prepare("SELECT s.*, i.instance_id FROM rf_instance_subitem s JOIN rf_instance_item i ON i.id=s.item_id WHERE s.id=?");
    $st->execute([$subitemId]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sub) return ['ok'=>false, 'msg'=>'找不到此小項'];
    $inst = rvf_instance_get($db, (int)$sub['instance_id']);
    if ($inst) {
        $schema = rvf_template_schema_at_version($db, (int)$inst['template_id'], (int)$inst['template_version']);
        if (($schema['sign_mode'] ?? 'password') === 'none') return ['ok'=>false, 'msg'=>'本模板不須簽名，前端不應顯示此動作'];
    }
    $signers = rvf_item_required_signers($db, $sub);
    $signer = null;
    foreach ($signers as $s) if ((int)$s['id'] === $forUid) { $signer = $s; break; }
    if (!$signer) return ['ok'=>false, 'msg'=>'您不是此項目的負責人'];
    $already = $db->prepare("SELECT 1 FROM rf_instance_item_confirm WHERE subitem_id=? AND user_id=?");
    $already->execute([$subitemId, $forUid]);
    if ($already->fetchColumn()) return ['ok'=>false, 'msg'=>'您已經簽過此項目'];
    $v = rvf_verify_own_password($db, $forUid, $password);
    if (!$v['ok']) return $v;
    $ownerDepts = array_values(array_filter(array_map('intval', explode(',', (string)($sub['owner_depts'] ?? '')))));
    $deptId = null; $deptName = null;
    if ($ownerDepts) {
        $st2 = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=? AND is_main=1 LIMIT 1");
        $st2->execute([$forUid]);
        $deptId = (int)$st2->fetchColumn() ?: null;
        if ($deptId) {
            $st3 = $db->prepare("SELECT name FROM department WHERE id=?");
            $st3->execute([$deptId]);
            $deptName = $st3->fetchColumn() ?: null;
        }
    }
    $db->prepare("INSERT IGNORE INTO rf_instance_item_confirm (subitem_id,user_id,user_name,dept_id,dept_name,signed_at) VALUES (?,?,?,?,?,NOW())")
       ->execute([$subitemId, $forUid, $signer['user_cname'], $deptId, $deptName]);
    return ['ok'=>true];
}

/* -------- 通知（比照 meeting_notify / meeting_notify_item_owners） -------- */

function rvf_notify(PDO $db, int $refId, array $toUids, string $title, string $content, int $fromUid, string $refType, string $mode = 'sign'): int {
    $toUids = array_values(array_unique(array_map('intval', $toUids)));
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '審核表單', 1, ?, ?)")
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

/* 2026-08-13 修正：通知標題/內文原本沒有帶模板名稱，跟同檔案其他 rvf_notify() 呼叫點（送審/核准/退回通知）不一致，
   使用者收到的通知只看得到「單一項目」這種項目本身的內容文字，看不出是哪張表單，完全不知道要去哪裡處理
   （使用者原話：「這個通知看不出是甚麼東西」）。改成比照其他呼叫點一律帶上「模板名稱」。 */
function rvf_notify_item_owners(PDO $db, int $instanceId, int $fromUid): void {
    $inst = rvf_instance_get($db, $instanceId);
    $tpl = $inst ? rvf_template_get($db, (int)$inst['template_id']) : null;
    $tplName = $tpl['name'] ?? '審核表單';
    $items = rvf_instance_items_get($db, $instanceId);
    foreach ($items as $it) {
        foreach ($it['subitems'] as $sub) {
            if (rvf_item_is_fully_signed($db, $sub)) continue;
            $doneUids = array_map(fn($c) => (int)$c['user_id'], $sub['confirms']);
            $need = [];
            $ownerUsers = array_values(array_filter(array_map('intval', explode(',', (string)($sub['owner_users'] ?? '')))));
            foreach ($ownerUsers as $u) if (!in_array($u, $doneUids, true)) $need[] = $u;
            $ownerDepts = array_values(array_filter(array_map('intval', explode(',', (string)($sub['owner_depts'] ?? '')))));
            foreach ($ownerDepts as $d) {
                $mgrIds = array_column(rvf_dept_managers($db, $d), 'id');
                if (!array_intersect($mgrIds, $doneUids)) $need = array_merge($need, $mgrIds);
            }
            $need = array_values(array_unique($need));
            if ($need) rvf_notify($db, $sub['id'], $need, '「' . $tplName . '」表單項目待您簽名確認',
                '「' . $tplName . '」的「' . ($sub['content'] ?: '(未命名項目)') . '」需要您確認並簽名。', $fromUid, 'RVF_ITEM_CONFIRM', 'reply');
        }
    }
}

/* -------- 送出／審核／核准 -------- */

/** 自動簽核（ai-rules/21 三條鐵則，2026-08-13 使用者實測後修正時間邏輯）：立即建立一筆已核准的 approval_record，
 *  簽核人歸屬走該關卡合格簽核池第一位，池空退回 top_approver；決行時間＝從「上一個關卡實際完成的時間」
 *  （$afterDatetime，第一關卡是送出時間，第二關卡是上一關卡自己的 decided_at）往後隨機 5分鐘~3小時，
 *  跨天鎖回送出日23:59——**基準點刻意用「上一關卡的時間」而不是固定用送出時間**，否則審核／核准各自獨立算隨機
 *  偏移量時，核准的偏移量剛好比審核小，時間軸就會出現「核准早於審核」這種不合理的先後順序（使用者明確要求
 *  「注意簽核順序的時間要正確」）。不改 approval_lib.php 本身，只在這裡事後 UPDATE decided_at，避免影響其他模組
 *  的人工簽核時間。免簽核不代表沒人要知道，簽完仍發一則通知讓送出者知道系統自動處理過（使用者明確要求，
 *  比照人工簽核決行後既有的 RVF_RESULT 通知模式，不新開通知類型）。
 *  @return array{signer_name:string, decided_at:string} decided_at 給下一關卡當 $afterDatetime 串聯用 */
function rvf_auto_sign(PDO $db, int $instanceId, string $level, array $tpl, int $submitterUid, string $submitterName, string $submitDate, string $afterDatetime): array {
    $pool = $level === 'review' ? rvf_review_pool($db, $tpl) : rvf_approver_pool($db, $tpl, $submitterUid);
    $signerId = $pool ? (int)$pool[0]['id'] : null;
    $signerName = $pool ? $pool[0]['user_cname'] : null;
    if (!$signerName) {
        $top = eg_org_user($db, 'top_approver');
        $signerId = $top ? (int)$top['id'] : null;
        $signerName = $top ? $top['user_cname'] : null;
    }
    if (!$signerName) { $signerId = $submitterUid; $signerName = $submitterName; }
    $aid = eg_approval_submit($db, 'review_form', $instanceId, $level, $submitterUid, $submitterName);
    eg_approval_decide($db, $aid, $signerId, $signerName, 'approved', '（系統自動簽核）');
    $offsetMin = random_int(5, 180);
    $db->prepare("UPDATE approval_record SET decided_at = LEAST(DATE_ADD(?, INTERVAL ? MINUTE), CONCAT(?, ' 23:59:59')) WHERE id=?")
       ->execute([$afterDatetime, $offsetMin, $submitDate, $aid]);
    $decidedAt = (string)$db->query("SELECT decided_at FROM approval_record WHERE id=" . (int)$aid)->fetchColumn();
    $levelLabel = $level === 'review' ? '審核' : '核准';
    rvf_notify($db, $instanceId, [$submitterUid], '「' . $tpl['name'] . '」' . $levelLabel . '已由系統自動簽核',
        '「' . $tpl['name'] . '」' . $levelLabel . '已自動簽核完成（送出日：' . $submitDate . '，簽核時間：' . $decidedAt . '），'
        . '簽核人：' . $signerName . '（系統自動簽核，非本人親自處理）。', $submitterUid, 'RVF_RESULT', 'read');
    return ['signer_name'=>$signerName, 'decided_at'=>$decidedAt];
}

/** 審核關卡結束(不論是不需要審核、自動簽核、或人工審核通過)後接著判斷要不要進入核准關卡；不需要就直接approved。
 *  submit() 與 rvf_review_decide() 都會走到這裡，抽成共用避免兩處各寫一份規則不一致。
 *  $afterDatetime：核准若自動簽核，隨機偏移的基準時間點（審核自動簽核剛完成就傳它的 decided_at；
 *  審核是人工完成或本來就不需要審核，就傳「現在」，確保核准的時間一定晚於審核完成的時間）。 */
function rvf_advance_to_approval(PDO $db, int $instanceId, array $tpl, array $inst, string $submitDate, string $afterDatetime): array {
    if (empty($tpl['need_approval'])) {
        $db->prepare("UPDATE rf_instance SET status='approved',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
        return ['ok'=>true, 'status'=>'approved'];
    }
    $submitterUid = (int)$inst['created_by'];
    $submitterName = (string)$inst['created_by_name'];
    if (!empty($tpl['auto_approval'])) {
        rvf_auto_sign($db, $instanceId, 'approval', $tpl, $submitterUid, $submitterName, $submitDate, $afterDatetime);
        $db->prepare("UPDATE rf_instance SET status='approved',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
        return ['ok'=>true, 'status'=>'approved'];
    }
    $apool = rvf_approver_pool($db, $tpl, $submitterUid);
    if (!$apool) {
        $db->prepare("UPDATE rf_instance SET status='approving',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
        return ['ok'=>false, 'msg'=>'核准人員解析不到合格人選，請聯絡管理員'];
    }
    eg_approval_submit($db, 'review_form', $instanceId, 'approval', $submitterUid, $submitterName);
    rvf_notify($db, $instanceId, array_column($apool, 'id'), '「' . $tpl['name'] . '」表單待您核准',
        $submitterName . ' 的「' . $tpl['name'] . '」待您核准。', $submitterUid, 'RVF_APPROVAL', 'sign');
    $db->prepare("UPDATE rf_instance SET status='approving',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
    return ['ok'=>true, 'status'=>'approving'];
}

function rvf_instance_submit(PDO $db, int $instanceId, int $uid, string $uname): array {
    $inst = rvf_instance_get($db, $instanceId);
    if (!$inst) return ['ok'=>false, 'msg'=>'找不到此表單'];
    if ((int)$inst['created_by'] !== $uid) return ['ok'=>false, 'msg'=>'只有填表人本人可以送出'];
    if ($inst['status'] !== 'draft') return ['ok'=>false, 'msg'=>'此表單已送出，不可重複送出'];
    $tpl = rvf_template_get($db, (int)$inst['template_id']);
    if (!$tpl) return ['ok'=>false, 'msg'=>'模板不存在'];

    // 送出日鐵則(ai-rules/21)：一般使用者不可自選，一律當下日期；業務日期跟精確時間戳分開存，送出後鎖定不可再改。
    $submitDate = date('Y-m-d');
    $submittedAt = date('Y-m-d H:i:s');
    $db->prepare("UPDATE rf_instance SET submit_date=?, submitted_at=? WHERE id=?")->execute([$submitDate, $submittedAt, $instanceId]);
    $inst['submit_date'] = $submitDate;

    $result = null;
    if (!empty($tpl['need_review'])) {
        if (!empty($tpl['auto_review'])) {
            $r = rvf_auto_sign($db, $instanceId, 'review', $tpl, $uid, $uname, $submitDate, $submittedAt);
            $result = rvf_advance_to_approval($db, $instanceId, $tpl, $inst, $submitDate, $r['decided_at']);
        } else {
            $pool = rvf_review_pool($db, $tpl);
            if (!$pool) return ['ok'=>false, 'msg'=>'審核部門尚未設定可審核的主管，請聯絡管理員'];
            eg_approval_submit($db, 'review_form', $instanceId, 'review', $uid, $uname);
            rvf_notify($db, $instanceId, array_column($pool, 'id'),
                '「' . $tpl['name'] . '」表單待您審核',
                $uname . ' 送出了一份「' . $tpl['name'] . '」，請審核。', $uid, 'RVF_REVIEW', 'sign');
            $db->prepare("UPDATE rf_instance SET status='reviewing',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
            $result = ['ok'=>true, 'status'=>'reviewing'];
        }
    } else {
        $result = rvf_advance_to_approval($db, $instanceId, $tpl, $inst, $submitDate, $submittedAt);
    }

    $schema = json_decode((string)$tpl['current_schema_json'], true) ?: [];
    if (($schema['sign_mode'] ?? 'password') === 'notify') rvf_notify_item_owners($db, $instanceId, $uid);

    return $result;
}

function rvf_review_decide(PDO $db, int $instanceId, int $uid, string $uname, string $decision, ?string $note = null): array {
    $rec = eg_approval_latest($db, 'review_form', $instanceId, 'review');
    if (!$rec || $rec['status'] !== 'pending') return ['ok'=>false, 'msg'=>'目前沒有待您審核的項目'];
    $inst = rvf_instance_get($db, $instanceId);
    $tpl = rvf_template_get($db, (int)$inst['template_id']);
    $pool = rvf_review_pool($db, $tpl);
    if (!in_array($uid, array_column($pool, 'id'), true)) return ['ok'=>false, 'msg'=>'您不是本表單的審核人'];
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note);
    if (!$r['success']) return ['ok'=>false, 'msg'=>$r['message']];
    if ($decision === 'rejected') {
        $db->prepare("UPDATE rf_instance SET status='rejected',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
        rvf_notify($db, $instanceId, [(int)$inst['created_by']], '「' . $tpl['name'] . '」表單被退回',
            $uname . ' 退回了「' . $tpl['name'] . '」。退回原因：' . $note, $uid, 'RVF_RESULT', 'read');
        return ['ok'=>true, 'status'=>'rejected'];
    }
    // 人工審核剛完成，核准若自動簽核，隨機偏移的基準時間點就是「現在」（審核完成的當下），
    // 確保核准時間一定晚於這次人工審核決行的時間。
    $result = rvf_advance_to_approval($db, $instanceId, $tpl, $inst, (string)($inst['submit_date'] ?: date('Y-m-d')), date('Y-m-d H:i:s'));
    if ($result['ok'] && $result['status'] === 'approved') {
        rvf_notify($db, $instanceId, [(int)$inst['created_by']], '「' . $tpl['name'] . '」表單已完成審核',
            $uname . ' 已審核通過「' . $tpl['name'] . '」。', $uid, 'RVF_RESULT', 'read');
    }
    return $result;
}

function rvf_approval_decide(PDO $db, int $instanceId, int $uid, string $uname, string $decision, ?string $note = null): array {
    $rec = eg_approval_latest($db, 'review_form', $instanceId, 'approval');
    if (!$rec || $rec['status'] !== 'pending') return ['ok'=>false, 'msg'=>'目前沒有待您核准的項目'];
    $inst = rvf_instance_get($db, $instanceId);
    $tpl = rvf_template_get($db, (int)$inst['template_id']);
    $pool = rvf_approver_pool($db, $tpl, (int)$inst['created_by']);
    if (!in_array($uid, array_column($pool, 'id'), true)) return ['ok'=>false, 'msg'=>'您不是本表單的核准人'];
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note);
    if (!$r['success']) return ['ok'=>false, 'msg'=>$r['message']];
    $newStatus = $decision === 'rejected' ? 'rejected' : 'approved';
    $db->prepare("UPDATE rf_instance SET status=?,updated_at=NOW() WHERE id=?")->execute([$newStatus, $instanceId]);
    $msg = $decision === 'rejected' ? ('被退回。退回原因：' . $note) : '已完成核准';
    rvf_notify($db, $instanceId, [(int)$inst['created_by']], '「' . $tpl['name'] . '」表單' . ($decision==='rejected'?'被退回':'已核准'),
        $uname . ' ' . $msg . '「' . $tpl['name'] . '」。', $uid, 'RVF_RESULT', 'read');
    return ['ok'=>true, 'status'=>$newStatus];
}

/** 超級管理員回改已送出單據的送出日（ai-rules/21 鐵則2，補登舊資料用；一般使用者不可呼叫，由 API 層守門）。
 *  同步調整該表單底下自動簽核紀錄的日期——只搬日期、保留原本抽到的時分秒，不重新抽亂數，也不動 submitted_at
 *  （那是「實際點擊送出」的稽核事實，不因補登而竄改）。 */
function rvf_instance_edit_submit_date(PDO $db, int $instanceId, string $newDate): array {
    $inst = rvf_instance_get($db, $instanceId);
    if (!$inst) return ['ok'=>false, 'msg'=>'找不到此表單'];
    if ($inst['status'] === 'draft') return ['ok'=>false, 'msg'=>'尚未送出的草稿沒有送出日可改'];
    if (!$inst['submit_date']) return ['ok'=>false, 'msg'=>'此表單無送出日紀錄（舊資料，無法回改）'];
    $db->prepare("UPDATE rf_instance SET submit_date=? WHERE id=?")->execute([$newDate, $instanceId]);
    foreach (['review', 'approval'] as $level) {
        $rec = eg_approval_latest($db, 'review_form', $instanceId, $level);
        if ($rec && $rec['status'] === 'approved' && strpos((string)$rec['note'], '系統自動簽核') !== false) {
            $db->prepare("UPDATE approval_record SET decided_at = CONCAT(?, ' ', TIME(decided_at)) WHERE id=?")
               ->execute([$newDate, $rec['id']]);
        }
    }
    return ['ok'=>true];
}

/* ============================================================ 列印 ============================================================ */

function rvf_asdoc_no_display(PDO $db, int $templateId, ?string $bizDate = null): string {
    return eg_asdoc_no_asof($db, rvf_asdoc_module($templateId), $bizDate);
}
