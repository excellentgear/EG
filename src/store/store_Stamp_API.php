<?php
/**
 * 圖章管理 API — 清冊登記(stamp_register) + 掃描實體章資產(stamp_asset)
 * 權限：檢閱=所有登入者（印章樣式本就出現在單據上）；登記/上傳/停用/刪除=管理者 or stamp 模組角色（圖章管理員）。
 * 掃描章：上傳 jpg/png → GD 紅色抽取去背 → PNG(alpha) 存 {stamp_attach_base}/{user_id}.png（DB 只存檔名，鐵律5）。
 * asset_map / asset_img 供 resource/js/eg_stamp.js 於各簽核顯示端（報價單/CAR/AS表單）自動替換真章。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';
include_once $document_root . '/EGsystem/src/common/stamp_lib.php';

$db = (new DBConnection())->getPDO();

function jout($arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// 編號前綴內的日期變數，取號當下才即時展開成實際日期（BOM單號/訂單編號常見格式：B-民國年3碼+月日各2碼+流水號3碼 等）
// {民國年}=ROC年3碼(2026→115)｜{西元年}=西元年4碼｜{月}{日}=各2碼補零
function stamp_resolve_prefix_tokens(string $prefix): string {
    $y = (int)date('Y');
    return str_replace(
        ['{民國年}', '{西元年}', '{月}', '{日}'],
        [str_pad((string)($y - 1911), 3, '0', STR_PAD_LEFT), date('Y'), date('m'), date('d')],
        $prefix
    );
}

// ── 使用者 ──
$uname = $_SESSION['userName'] ?? '';
if ($uname === '') jerr('未登入', 401);
$st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname = ?");
$st->execute([$uname]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) jerr('使用者不存在', 401);
$uid   = (int)$u['id'];
$cname = (string)($u['user_cname'] ?: $uname);

// 管理者：user_status 9/90 或系統 admin 角色（沿用 imgedit_permission 慣例）
$isAdmin = in_array((int)$u['user_status'], [9, 90], true);
if (!$isAdmin) {
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Exception $e) {}
}
// 角色判定（module=stamp）：stamp_manage=圖章管理員、stamp_view=圖章檢閱（唯讀）。
// 清冊/匯出 fail-closed：未被指派角色者（含一般登入者）一律看不到清冊內容，防止圖章被瀏覽轉存惡意複製（2026-07-24 使用者要求）。
// 例外：asset_map / asset_img 維持所有登入者可讀——簽核章顯示在報價單/CAR/AS表單上，看得到單據的人本來就看得到章，鎖了會讓簽核顯示直接壞掉。
function hasStampRole(PDO $db, int $uid, array $codes): bool {
    try {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.module = 'stamp' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        if ($st->fetchColumn()) return true;
        $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                            JOIN position_roles pr ON pr.position_id = m.position_id
                            JOIN roles r ON r.role_id = pr.role_id
                            WHERE m.user_id = ? AND r.module = 'stamp' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}
$canManage = $isAdmin || hasStampRole($db, $uid, ['stamp_manage']);
$canView   = $canManage || hasStampRole($db, $uid, ['stamp_view']);
function needManage(bool $canManage){ if (!$canManage) jerr('無圖章管理權限（需管理者或「圖章管理員」角色）', 403); }
function needView(bool $canView){ if (!$canView) jerr('無圖章清冊檢閱權限（需「圖章檢閱」或「圖章管理員」角色，請洽管理者至人員權限設定指派）', 403); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
switch ($action) {

// ── 下拉/權限 meta（無檢閱權者只回權限旗標，不外洩人員/種類清單）──
case 'meta': {
    if (!$canView) jout(['ok'=>true, 'canView'=>false, 'canManage'=>false, 'isAdmin'=>false, 'canBatch'=>false, 'me'=>$cname]);
    $users = [];
    if ($canManage) {
        // dept_id = 該員主要部門（is_main 優先，供前端「部門→篩選人員」cascading 選單使用；同部門+人員綁定用）
        $users = $db->query("SELECT u.id, u.user_cname,
                                    (SELECT m.department_id FROM user_department_position_map m
                                     WHERE m.user_id = u.id ORDER BY m.is_main DESC, m.id LIMIT 1) AS dept_id
                             FROM user u WHERE (u.state IS NULL OR u.state <> 0)
                             ORDER BY CONVERT(u.user_cname USING utf8mb4) COLLATE utf8mb4_unicode_ci")->fetchAll(PDO::FETCH_ASSOC);
    }
    $types = $db->query("SELECT id, type_name, bind_targets, sort_order, is_active FROM stamp_type ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $depts = $canManage ? $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) : [];
    $positions = $canManage ? $db->query("SELECT id, name FROM position ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) : [];
    // 部門→職稱對照（department_position 主檔，非 user_department_position_map 實際指派）：職稱下拉依所選部門「該部門有設定的職稱」篩選
    $deptPositions = new stdClass();
    if ($canManage) {
        $dp = [];
        foreach ($db->query("SELECT department_id, position_id FROM department_position")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dp[(string)$r['department_id']][] = (int)$r['position_id'];
        }
        $deptPositions = $dp;
    }
    $tpls = $db->query("SELECT p.id, p.type_id, t.type_name, p.tpl_name
                        FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                        WHERE p.is_active=1 ORDER BY t.sort_order, p.id")->fetchAll(PDO::FETCH_ASSOC);
    $base = '';
    if ($canManage) $base = eg_stamp_base($db);
    jout(['ok'=>true, 'canView'=>true, 'canManage'=>$canManage, 'isAdmin'=>$isAdmin, 'canBatch'=>($isAdmin && $uid === 1), 'me'=>$cname,
          'users'=>$users, 'types'=>$types, 'depts'=>$depts, 'positions'=>$positions, 'dept_positions'=>$deptPositions, 'templates'=>$tpls,
          'base'=>$base, 'base_ok'=>$canManage ? is_dir($base) : null]);
}

// ── 印章種類主檔維護 ──
// bind_targets：逗號分隔 user/dept/position 子集；空字串=不綁定(不限制，新增登記三種持有對象皆可選)
case 'type_save': {
    needManage($canManage);
    $tid  = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['type_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 50) jerr('請輸入種類名稱（50字內）');
    $bt = array_values(array_intersect(
        array_filter(array_map('trim', explode(',', (string)($_POST['bind_targets'] ?? '')))),
        ['user','dept','position']
    ));
    $bindTargets = implode(',', $bt);
    $st = $db->prepare("SELECT id FROM stamp_type WHERE type_name=? AND id<>? LIMIT 1");
    $st->execute([$name, $tid]);
    if ($st->fetchColumn()) jerr('已有同名種類');
    if ($tid > 0) {
        $db->prepare("UPDATE stamp_type SET type_name=?, bind_targets=? WHERE id=?")->execute([$name, $bindTargets, $tid]);
    } else {
        $db->prepare("INSERT INTO stamp_type (type_name, bind_targets, sort_order, created_by)
                      VALUES (?, ?, (SELECT IFNULL(MAX(t2.sort_order),0)+1 FROM stamp_type t2), ?)")->execute([$name, $bindTargets, $cname]);
        $tid = (int)$db->lastInsertId();
    }
    jout(['ok'=>true, 'id'=>$tid]);
}
case 'type_toggle': {
    needManage($canManage);
    $tid = (int)($_POST['id'] ?? 0);
    if ($tid <= 0) jerr('參數錯誤');
    $db->prepare("UPDATE stamp_type SET is_active = 1 - is_active WHERE id=?")->execute([$tid]);
    jout(['ok'=>true]);
}
case 'type_delete': {
    needManage($canManage);
    $tid = (int)($_POST['id'] ?? 0);
    if ($tid <= 0) jerr('參數錯誤');
    $st = $db->prepare("SELECT COUNT(*) FROM stamp_register WHERE type_id=?");
    $st->execute([$tid]);
    if ((int)$st->fetchColumn() > 0) jerr('此種類已有登記資料使用中，不可刪除（可改用「停用」讓新登記選不到）');
    $db->prepare("DELETE FROM stamp_type WHERE id=?")->execute([$tid]);
    jout(['ok'=>true]);
}

// ── 儲存路徑設定（僅管理者）──
case 'set_base': {
    if (!$isAdmin) jerr('僅管理者可修改儲存路徑', 403);
    $dir = rtrim(trim((string)($_POST['base'] ?? '')), '\\/');
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                        VALUES ('stamp_attach_base', ?, ?, ?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)");
    $st->execute([$dir, $uid, $cname]);
    jout(['ok'=>true, 'base'=>eg_stamp_base($db), 'base_ok'=>is_dir(eg_stamp_base($db))]);
}

// ── 清冊列表（後端分頁＋彙總，匯出/列印用 all=1 回全部）──
case 'list': {
    needView($canView);
    $q      = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $typeId = (int)($_GET['type_id'] ?? 0);
    $per    = (int)($_GET['per'] ?? 10); if (!in_array($per, [5,10,20,50], true)) $per = 10;
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $all    = ($_GET['all'] ?? '') === '1';

    $where = []; $args = [];
    if ($q !== '')      { $where[] = "(CONVERT(u.user_cname USING utf8mb4) LIKE ? OR d.name LIKE ? OR p.name LIKE ?)"; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; }
    if ($status !== '') { $where[] = "r.status = ?"; $args[] = $status; }
    if ($typeId > 0)    { $where[] = "r.type_id = ?"; $args[] = $typeId; }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $joins = "FROM stamp_register r
              LEFT JOIN user u ON u.id = r.user_id
              LEFT JOIN department d ON d.id = r.dept_id
              LEFT JOIN position p ON p.id = r.position_id";

    $stc = $db->prepare("SELECT COUNT(*) $joins $w");
    $stc->execute($args); $total = (int)$stc->fetchColumn();

    $sts = $db->prepare("SELECT
                           SUM(CASE WHEN r.status='active' THEN 1 ELSE 0 END) AS active_cnt,
                           SUM(CASE WHEN r.status='revoked' THEN 1 ELSE 0 END) AS revoked_cnt
                         $joins $w");
    $sts->execute($args); $sum = $sts->fetch(PDO::FETCH_ASSOC) ?: ['active_cnt'=>0,'revoked_cnt'=>0];

    $limit = $all ? '' : ('LIMIT ' . (($page-1)*$per) . ',' . $per);
    $st = $db->prepare("SELECT r.id, r.user_id, r.dept_id, r.position_id, u.user_cname, d.name AS dept_name, p.name AS position_name,
                               CASE WHEN r.position_id IS NOT NULL THEN CONCAT(d.name,'／',p.name)
                                    WHEN r.user_id IS NOT NULL AND r.dept_id IS NOT NULL THEN CONCAT(u.user_cname,'（',d.name,'）')
                                    ELSE COALESCE(u.user_cname, d.name) END AS holder_name,
                               CASE WHEN r.position_id IS NOT NULL THEN 'position'
                                    WHEN r.user_id IS NOT NULL AND r.dept_id IS NOT NULL THEN 'user_dept'
                                    WHEN r.dept_id IS NOT NULL THEN 'dept' ELSE 'user' END AS holder_kind,
                               r.type_id, t.type_name, r.template_id, tp.tpl_name,
                               r.issue_date, r.revoke_date, r.status, r.note,
                               r.created_by, r.created_at,
                               a.file_name IS NOT NULL AS has_asset, a.band_top, a.band_bottom
                        $joins
                        LEFT JOIN stamp_type t ON t.id = r.type_id
                        LEFT JOIN stamp_template tp ON tp.id = r.template_id AND tp.is_active = 1
                        LEFT JOIN stamp_asset a ON a.user_id = r.user_id
                        $w ORDER BY r.status='active' DESC, r.issue_date DESC, r.id DESC $limit");
    $st->execute($args);
    jout(['ok'=>true, 'rows'=>$st->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total,
          'summary'=>['active'=>(int)$sum['active_cnt'], 'revoked'=>(int)$sum['revoked_cnt']],
          'canManage'=>$canManage]);
}

// ── 新增登記 ──
// holder_kind：'user'（個人）｜'dept'（部門）｜'position'（職稱＝該部門的該職稱，dept_id+position_id併用）
//            ｜'user_dept'（部門所屬人員＝該部門下的某個人，dept_id+user_id併用；種類同時綁定個人+課室時的組合登記）
case 'add': {
    needManage($canManage);
    $kind = (string)($_POST['holder_kind'] ?? 'user');
    if (!in_array($kind, ['user','dept','position','user_dept'], true)) jerr('持有對象類型錯誤');
    $tuid     = in_array($kind, ['user','user_dept'], true) ? ((int)($_POST['user_id'] ?? 0) ?: null) : null;
    $deptId   = in_array($kind, ['dept','position','user_dept'], true) ? ((int)($_POST['dept_id'] ?? 0) ?: null) : null;
    $posId    = $kind === 'position' ? ((int)($_POST['position_id'] ?? 0) ?: null) : null;
    $typeId = (int)($_POST['type_id'] ?? 0) ?: null;
    $tplId  = (int)($_POST['template_id'] ?? 0) ?: null;
    $issue = trim((string)($_POST['issue_date'] ?? ''));
    $note  = trim((string)($_POST['note'] ?? ''));
    if ($kind === 'user'      && $tuid === null) jerr('請選擇人員');
    if ($kind === 'dept'      && $deptId === null) jerr('請選擇部門');
    if ($kind === 'position'  && ($deptId === null || $posId === null)) jerr('請選擇部門與職稱');
    if ($kind === 'user_dept' && ($deptId === null || $tuid === null)) jerr('請選擇部門與人員');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) jerr('核發日期格式錯誤');
    if ($tplId !== null) {
        // 種類一律以模板實際所屬種類為準（不信任前端送來的 type_id，避免兩者不一致時清冊顯示與登記種類對不上）
        $st = $db->prepare("SELECT type_id FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$tplId]);
        if (($tplRow = $st->fetch(PDO::FETCH_ASSOC)) === false) jerr('模板不存在或已停用');
        $typeId = $tplRow['type_id'] !== null ? (int)$tplRow['type_id'] : null;
    }
    if ($typeId !== null) {
        $st = $db->prepare("SELECT bind_targets FROM stamp_type WHERE id=? AND is_active=1");
        $st->execute([$typeId]);
        $bt = $st->fetchColumn();
        if ($bt === false) jerr('印章種類不存在或已停用');
        if ($bt !== '' && $bt !== null) {
            $btArr = explode(',', $bt);
            $ok = $kind === 'user_dept'
                ? (in_array('user', $btArr, true) && in_array('dept', $btArr, true))
                : in_array($kind, $btArr, true);
            if (!$ok) jerr('此種類限定綁定對象為：' . str_replace(['user','dept','position'], ['個人','部門','職稱'], implode('/', $btArr)));
        }
    }
    if ($deptId !== null) {
        $st = $db->prepare("SELECT 1 FROM department WHERE id=?");
        $st->execute([$deptId]);
        if (!$st->fetchColumn()) jerr('部門不存在');
    }
    if ($posId !== null) {
        $st = $db->prepare("SELECT 1 FROM position WHERE id=?");
        $st->execute([$posId]);
        if (!$st->fetchColumn()) jerr('職稱不存在');
    }
    $db->beginTransaction();
    // 同一持有對象（人員/部門/部門+職稱）＋同一種類 同時僅一筆使用中；不同種類可同時持有
    $st = $db->prepare("SELECT 1 FROM stamp_register WHERE user_id <=> ? AND dept_id <=> ? AND position_id <=> ? AND status='active' AND type_id <=> ? LIMIT 1 FOR UPDATE");
    $st->execute([$tuid, $deptId, $posId, $typeId]);
    if ($st->fetchColumn()) { $db->rollBack(); jerr('該持有對象此種類已有一筆「使用中」圖章，請先停用舊登記再核發'); }
    $st = $db->prepare("INSERT INTO stamp_register (user_id, dept_id, position_id, type_id, template_id, issue_date, status, note, created_by) VALUES (?,?,?,?,?,?,'active',?,?)");
    $st->execute([$tuid, $deptId, $posId, $typeId, $tplId, $issue, $note, $cname]);
    $newId = (int)$db->lastInsertId();
    $db->commit();
    jout(['ok'=>true, 'id'=>$newId]);
}

// ── 修改登記（種類/核發日/備註）──
case 'update': {
    needManage($canManage);
    $id = (int)($_POST['id'] ?? 0);
    $typeId = (int)($_POST['type_id'] ?? 0) ?: null;
    $issue = trim((string)($_POST['issue_date'] ?? ''));
    $note  = trim((string)($_POST['note'] ?? ''));
    if ($id <= 0) jerr('參數錯誤');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) jerr('核發日期格式錯誤');
    $cur = $db->prepare("SELECT user_id, dept_id, position_id, type_id, status FROM stamp_register WHERE id=?");
    $cur->execute([$id]);
    $curRow = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$curRow) jerr('登記不存在');
    if ($curRow['status'] === 'active') {
        $st = $db->prepare("SELECT 1 FROM stamp_register WHERE user_id <=> ? AND dept_id <=> ? AND position_id <=> ? AND status='active' AND type_id <=> ? AND id<>? LIMIT 1");
        $st->execute([$curRow['user_id'], $curRow['dept_id'], $curRow['position_id'], $typeId, $id]);
        if ($st->fetchColumn()) jerr('該持有對象此種類已有另一筆「使用中」圖章');
    }
    // 種類改變時，登記當初選的模板已不屬於新種類，清空 template_id（清冊預覽退回「該新種類任一啟用模板」）
    $clearTplSql = ((string)$curRow['type_id'] !== (string)$typeId) ? ", template_id=NULL" : "";
    $st = $db->prepare("UPDATE stamp_register SET type_id=?, issue_date=?, note=?, modified_by=?, modified_at=NOW()$clearTplSql WHERE id=?");
    $st->execute([$typeId, $issue, $note, $cname, $id]);
    jout(['ok'=>true]);
}

// ── 停用/繳回 ──
case 'revoke': {
    needManage($canManage);
    $id = (int)($_POST['id'] ?? 0);
    $rd = trim((string)($_POST['revoke_date'] ?? ''));
    if ($id <= 0) jerr('參數錯誤');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd)) jerr('停用日期格式錯誤');
    $st = $db->prepare("UPDATE stamp_register SET status='revoked', revoke_date=?, modified_by=?, modified_at=NOW() WHERE id=? AND status='active'");
    $st->execute([$rd, $cname, $id]);
    if (!$st->rowCount()) jerr('此筆不是使用中狀態，無法停用');
    jout(['ok'=>true]);
}

// ── 刪除登記（誤登記時使用）──
case 'delete': {
    needManage($canManage);
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) jerr('參數錯誤');
    $st = $db->prepare("DELETE FROM stamp_register WHERE id=?");
    $st->execute([$id]);
    jout(['ok'=>true]);
}

// ── CSV 匯出（目前篩選條件下全部資料，後端重查）──
case 'csv': {
    needView($canView);
    $q      = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $typeId = (int)($_GET['type_id'] ?? 0);
    $where = []; $args = [];
    if ($q !== '')      { $where[] = "(CONVERT(u.user_cname USING utf8mb4) LIKE ? OR d.name LIKE ? OR p.name LIKE ?)"; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; }
    if ($status !== '') { $where[] = "r.status = ?"; $args[] = $status; }
    if ($typeId > 0)    { $where[] = "r.type_id = ?"; $args[] = $typeId; }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = $db->prepare("SELECT CASE WHEN r.position_id IS NOT NULL THEN CONCAT(d.name,'／',p.name)
                                     WHEN r.user_id IS NOT NULL AND r.dept_id IS NOT NULL THEN CONCAT(u.user_cname,'（',d.name,'）')
                                     ELSE COALESCE(u.user_cname, d.name) END AS holder_name,
                               CASE WHEN r.position_id IS NOT NULL THEN '職稱章'
                                    WHEN r.user_id IS NOT NULL AND r.dept_id IS NOT NULL THEN '部門人員章'
                                    WHEN r.dept_id IS NOT NULL THEN '部門章' ELSE '個人章' END AS holder_kind,
                               t.type_name, tp.tpl_name, r.issue_date, r.revoke_date, r.status, r.note, r.created_by, r.created_at,
                               a.file_name IS NOT NULL AS has_asset
                        FROM stamp_register r
                        LEFT JOIN user u ON u.id=r.user_id
                        LEFT JOIN department d ON d.id=r.dept_id
                        LEFT JOIN position p ON p.id=r.position_id
                        LEFT JOIN stamp_type t ON t.id=r.type_id
                        LEFT JOIN stamp_template tp ON tp.id=r.template_id AND tp.is_active=1
                        LEFT JOIN stamp_asset a ON a.user_id=r.user_id
                        $w ORDER BY r.status='active' DESC, r.issue_date DESC, r.id DESC");
    $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stamp_register_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['持有對象','對象別','種類','模板','核發日期','停用/繳回日期','狀態','備註','掃描章','登記人','登記時間']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, [$r['holder_name'], $r['holder_kind'], $r['type_name'] ?: '', $r['tpl_name'] ?: '', $r['issue_date'], $r['revoke_date'] ?: '',
                       $r['status']==='active' ? '使用中' : '已停用', $r['note'] ?: '',
                       $r['has_asset'] ? '已上傳' : '—', $r['created_by'] ?: '', $r['created_at'] ?: '']);
    }
    fclose($out);
    exit;
}

// ── 掃描章上傳（jpg/png → 紅色抽取去背 → PNG alpha）──
case 'asset_upload': {
    needManage($canManage);
    $tuid = (int)($_POST['user_id'] ?? 0);
    if ($tuid <= 0) jerr('請選擇人員');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('檔案上傳失敗');
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) jerr('檔案超過 20MB');
    $tmp = $_FILES['file']['tmp_name'];
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) jerr('僅接受 JPG / PNG 圖檔');
    $src = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($tmp) : @imagecreatefrompng($tmp);
    if (!$src) jerr('圖檔讀取失敗');

    // 等比縮至最長邊 800（掃描件建議 300dpi，縮小後仍足夠列印顯示）
    $w = imagesx($src); $h = imagesy($src);
    $maxSide = 800;
    if (max($w, $h) > $maxSide) {
        $sc = $maxSide / max($w, $h);
        $nw = (int)round($w * $sc); $nh = (int)round($h * $sc);
        $rs = imagecreatetruecolor($nw, $nh);
        imagealphablending($rs, false); imagesavealpha($rs, true);
        imagecopyresampled($rs, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src); $src = $rs; $w = $nw; $h = $nh;
    }

    // 紅色抽取去背：保留 R 通道明顯高於 G、B 的像素，其餘透明（圖章系統說明.md 定案演算法）
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    $kept = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($src, $x, $y);
            $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
            if ($r > 150 && ($r - $g) > 40 && ($r - $b) > 40) {
                imagesetpixel($dst, $x, $y, imagecolorallocatealpha($dst, $r, $g, $b, 0));
                $kept++;
            }
        }
    }
    imagedestroy($src);
    if ($kept < ($w * $h) * 0.002) { imagedestroy($dst); jerr('偵測不到紅色印泥（陰影或藍黑墨的掃描件去背品質差），請用紅色印泥重新掃描上傳'); }

    $base = eg_stamp_base($db);
    if (!is_dir($base) && !@mkdir($base, 0777, true)) jerr('儲存資料夾不存在且無法建立：' . $base);
    $fn = $tuid . '.png';
    if (!imagepng($dst, $base . DIRECTORY_SEPARATOR . $fn)) { imagedestroy($dst); jerr('檔案寫入失敗，請確認儲存路徑可寫入'); }
    imagedestroy($dst);

    $st = $db->prepare("INSERT INTO stamp_asset (user_id, file_name, created_by)
                        VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE file_name=VALUES(file_name), modified_at=NOW()");
    $st->execute([$tuid, $fn, $cname]);
    $a = eg_stamp_asset($db, $tuid);
    jout(['ok'=>true, 'asset'=>$a, 't'=>time()]);
}

// ── 日期帶位置儲存 ──
case 'asset_band': {
    needManage($canManage);
    $tuid = (int)($_POST['user_id'] ?? 0);
    $top = (float)($_POST['band_top'] ?? 0);
    $bot = (float)($_POST['band_bottom'] ?? 0);
    if ($tuid <= 0) jerr('參數錯誤');
    if ($top < 0 || $bot > 100 || $top >= $bot) jerr('日期帶範圍錯誤（上緣需小於下緣，0~100%）');
    $st = $db->prepare("UPDATE stamp_asset SET band_top=?, band_bottom=?, modified_at=NOW() WHERE user_id=?");
    $st->execute([$top, $bot, $tuid]);
    if (!$st->rowCount() && !eg_stamp_asset($db, $tuid)) jerr('尚未上傳掃描章');
    jout(['ok'=>true]);
}

// ── 刪除掃描章（回退純 SVG 章）──
case 'asset_delete': {
    needManage($canManage);
    $tuid = (int)($_POST['user_id'] ?? 0);
    if ($tuid <= 0) jerr('參數錯誤');
    $a = eg_stamp_asset($db, $tuid);
    if ($a) {
        $p = eg_stamp_file_path($db, $a);
        if ($p) @unlink($p);
        $db->prepare("DELETE FROM stamp_asset WHERE user_id=?")->execute([$tuid]);
    }
    jout(['ok'=>true]);
}

// ── 掃描章圖檔（所有登入者可讀；t= 參數配 mtime 做快取破壞）──
case 'asset_img': {
    $tuid = (int)($_GET['user_id'] ?? 0);
    $a = $tuid > 0 ? eg_stamp_asset($db, $tuid) : null;
    $p = $a ? eg_stamp_file_path($db, $a) : null;
    if (!$p) { http_response_code(404); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=604800');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

// ── 姓名→掃描章對照表（eg_stamp.js 每頁載入時自動抓）──
case 'asset_map': {
    jout(['ok'=>true, 'map'=>eg_stamp_asset_map($db)]);
}

// ══════════ 線上圖章設計模板（stamp_template）＋編號流水 ══════════

// ── 模板清單（圖章管理頁）──
case 'tpl_list': {
    needView($canView);
    $rows = $db->query("SELECT p.id, p.type_id, t.type_name, p.tpl_name, p.schema_json,
                               p.serial_prefix, p.serial_digits, p.serial_start, p.serial_step, p.serial_reset, p.is_active
                        FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                        ORDER BY p.is_active DESC, p.id")->fetchAll(PDO::FETCH_ASSOC);
    jout(['ok'=>true, 'rows'=>$rows]);
}

// ── 模板儲存（新增/修改）──
case 'tpl_save': {
    needManage($canManage);
    $tid  = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['tpl_name'] ?? ''));
    $typeId = (int)($_POST['type_id'] ?? 0) ?: null;
    $schemaRaw = (string)($_POST['schema'] ?? '');
    if ($name === '' || mb_strlen($name) > 50) jerr('請輸入模板名稱（50字內）');
    $schema = json_decode($schemaRaw, true);
    if (!is_array($schema) || empty($schema['rows']) || !is_array($schema['rows'])) jerr('模板內容格式錯誤（至少一列）');
    if ($typeId !== null) {
        $st = $db->prepare("SELECT 1 FROM stamp_type WHERE id=?");
        $st->execute([$typeId]);
        if (!$st->fetchColumn()) jerr('印章種類不存在');
    }
    $prefix = trim((string)($_POST['serial_prefix'] ?? ''));
    $digits = max(1, min(10, (int)($_POST['serial_digits'] ?? 3)));
    $start  = max(0, (int)($_POST['serial_start'] ?? 1));
    $step   = max(1, (int)($_POST['serial_step'] ?? 1));
    $reset  = in_array($_POST['serial_reset'] ?? 'none', ['none','year','month','day'], true) ? $_POST['serial_reset'] : 'none';
    if ($tid > 0) {
        $st = $db->prepare("UPDATE stamp_template SET type_id=?, tpl_name=?, schema_json=?, serial_prefix=?, serial_digits=?, serial_start=?, serial_step=?, serial_reset=?, modified_at=NOW() WHERE id=?");
        $st->execute([$typeId, $name, json_encode($schema, JSON_UNESCAPED_UNICODE), $prefix, $digits, $start, $step, $reset, $tid]);
    } else {
        $st = $db->prepare("INSERT INTO stamp_template (type_id, tpl_name, schema_json, serial_prefix, serial_digits, serial_start, serial_step, serial_reset, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([$typeId, $name, json_encode($schema, JSON_UNESCAPED_UNICODE), $prefix, $digits, $start, $step, $reset, $cname]);
        $tid = (int)$db->lastInsertId();
    }
    jout(['ok'=>true, 'id'=>$tid]);
}
case 'tpl_toggle': {
    needManage($canManage);
    $tid = (int)($_POST['id'] ?? 0);
    if ($tid <= 0) jerr('參數錯誤');
    $db->prepare("UPDATE stamp_template SET is_active = 1 - is_active, modified_at=NOW() WHERE id=?")->execute([$tid]);
    jout(['ok'=>true]);
}
case 'tpl_delete': {
    needManage($canManage);
    $tid = (int)($_POST['id'] ?? 0);
    if ($tid <= 0) jerr('參數錯誤');
    $usages = eg_stamp_template_usages($db, $tid);
    if ($usages) jerr("此模板已被套用，無法刪除：\n" . implode("\n", $usages) . "\n請先移除套用後再刪除。");
    $db->beginTransaction();
    $db->prepare("DELETE FROM stamp_serial WHERE template_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM stamp_template WHERE id=?")->execute([$tid]);
    $db->commit();
    jout(['ok'=>true]);
}

// ── 批圖蓋章挑選 meta（依種類→模板→持有對象；含變數 ctx）──
// 所有登入者可用：蓋章是在圖面上作業的人做的，模板是參數化設計（非掃描原圖），姓名/部門/職稱本系統各頁本就可見。
case 'pick_meta': {
    $tpls = $db->query("SELECT p.id, p.type_id, t.type_name, p.tpl_name, p.schema_json,
                               p.serial_prefix, p.serial_digits, p.serial_reset
                        FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                        WHERE p.is_active=1 ORDER BY t.sort_order, p.id")->fetchAll(PDO::FETCH_ASSOC);
    // 各種類「使用中」持有對象＋變數 ctx。個人章：name=本人、dept/position=主要部門職稱。
    // 部門章：name=空字串（部門不是人，不可把部門名塞進{姓名}變數，否則模板誤用{姓名}時會顯示成部門名）、dept=部門名、holder_name(僅供下拉顯示用)=部門名。
    // 職稱章(dept_id+position_id)：即時查該部門該職稱「現任者」，人員異動時蓋章自動帶新任者，不需重新登記——name=現任者姓名(無人在任則空)、dept/position=登記當下的部門/職稱名。
    $holders = $db->query("
        SELECT r.type_id, r.user_id, r.dept_id, r.position_id,
               COALESCE(u.user_cname, d.name) AS holder_name,
               COALESCE(u.user_cname, '') AS name,
               COALESCE(d.name, md.name, '')  AS dept,
               COALESCE(mp.name, '')          AS position
        FROM stamp_register r
        LEFT JOIN user u ON u.id = r.user_id
        LEFT JOIN department d ON d.id = r.dept_id
        LEFT JOIN user_department_position_map m ON m.user_id = r.user_id AND m.is_main = 1
        LEFT JOIN department md ON md.id = m.department_id
        LEFT JOIN position mp ON mp.id = m.position_id
        WHERE r.status = 'active' AND r.position_id IS NULL
        UNION ALL
        SELECT r.type_id, r.user_id, r.dept_id, r.position_id,
               CASE WHEN u2.user_cname IS NOT NULL THEN CONCAT(d2.name,'／',p2.name,'（',u2.user_cname,'）')
                    ELSE CONCAT(d2.name,'／',p2.name,'（尚無人員）') END AS holder_name,
               COALESCE(u2.user_cname, '') AS name,
               d2.name AS dept, p2.name AS position
        FROM stamp_register r
        JOIN department d2 ON d2.id = r.dept_id
        JOIN position p2 ON p2.id = r.position_id
        LEFT JOIN user_department_position_map m2 ON m2.department_id = r.dept_id AND m2.position_id = r.position_id
        LEFT JOIN user u2 ON u2.id = m2.user_id
        WHERE r.status = 'active' AND r.position_id IS NOT NULL
        ORDER BY type_id, holder_name")->fetchAll(PDO::FETCH_ASSOC);
    jout(['ok'=>true, 'templates'=>$tpls, 'holders'=>$holders]);
}

// ── 取下一個編號（依模板跳號規則；交易併發安全）──
case 'next_serial': {
    $tid = (int)($_POST['template_id'] ?? 0);
    if ($tid <= 0) jerr('參數錯誤');
    $st = $db->prepare("SELECT serial_prefix, serial_digits, serial_start, serial_step, serial_reset FROM stamp_template WHERE id=? AND is_active=1");
    $st->execute([$tid]);
    $tpl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) jerr('模板不存在或已停用');
    $period = $tpl['serial_reset'] === 'day' ? date('Y-m-d')
            : ($tpl['serial_reset'] === 'year' ? date('Y') : ($tpl['serial_reset'] === 'month' ? date('Y-m') : ''));
    $db->beginTransaction();
    $st = $db->prepare("SELECT last_no FROM stamp_serial WHERE template_id=? AND period=? FOR UPDATE");
    $st->execute([$tid, $period]);
    $last = $st->fetchColumn();
    $next = ($last === false || (int)$last === 0) ? max(1, (int)$tpl['serial_start']) : (int)$last + (int)$tpl['serial_step'];
    $db->prepare("INSERT INTO stamp_serial (template_id, period, last_no) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE last_no=VALUES(last_no)")->execute([$tid, $period, $next]);
    $db->commit();
    $serial = stamp_resolve_prefix_tokens($tpl['serial_prefix']) . str_pad((string)$next, (int)$tpl['serial_digits'], '0', STR_PAD_LEFT);
    jout(['ok'=>true, 'serial'=>$serial, 'no'=>$next, 'period'=>$period]);
}

// ══════════ 批次建立登記（僅超級管理員 員工ID=1）══════════
// 多選部門→抓部門成員（含主職位＋兼任職位部門，各自一列）；可同時多選模板一次建立多種章。
function needBatch(bool $isAdmin, int $uid) { if (!($isAdmin && $uid === 1)) jerr('僅超級管理員（員工ID=1）可使用批次建立功能', 403); }

// ── 選定部門的成員清單（一人可能因主/兼任職位出現多列，各自不同 department_id）──
case 'batch_members': {
    needManage($canManage); needBatch($isAdmin, $uid);
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['dept_ids'] ?? '')))));
    if (!$ids) jout(['ok'=>true, 'rows'=>[]]);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT m.user_id, u.user_cname, m.department_id, d.name AS dept_name, m.is_main
                        FROM user_department_position_map m
                        JOIN user u ON u.id = m.user_id
                        JOIN department d ON d.id = m.department_id
                        WHERE m.department_id IN ($in) AND (u.state IS NULL OR u.state <> 0)
                        ORDER BY d.sort_order, m.is_main DESC, CONVERT(u.user_cname USING utf8mb4) COLLATE utf8mb4_unicode_ci");
    $st->execute($ids);
    jout(['ok'=>true, 'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── 批次寫入 ──
// items：JSON 陣列 [{template_id,type_id,kind,user_id,dept_id}]；kind='user'＝個人章(不綁部門)｜'user_dept'＝部門所屬人員章(dept_id必填)
// 同一持有對象＋種類已有使用中登記＝略過（不視為錯誤，回傳略過筆數），沿用單筆新增同一套防重複規則。
case 'batch_add': {
    needManage($canManage); needBatch($isAdmin, $uid);
    $issue = trim((string)($_POST['issue_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) jerr('核發日期格式錯誤');
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items) || !$items) jerr('請至少勾選一筆');
    if (count($items) > 500) jerr('單次批次上限 500 筆，請分批建立');
    $created = 0; $skipped = 0;
    $db->beginTransaction();
    foreach ($items as $it) {
        $kind   = (string)($it['kind'] ?? '');
        $tuid   = (int)($it['user_id'] ?? 0) ?: null;
        $deptId = $kind === 'user_dept' ? ((int)($it['dept_id'] ?? 0) ?: null) : null;
        $tplId  = (int)($it['template_id'] ?? 0) ?: null;
        $typeId = (int)($it['type_id'] ?? 0) ?: null;
        if (!in_array($kind, ['user', 'user_dept'], true) || $tuid === null || ($kind === 'user_dept' && $deptId === null)) { $skipped++; continue; }
        if ($tplId !== null) {
            $st = $db->prepare("SELECT type_id FROM stamp_template WHERE id=? AND is_active=1");
            $st->execute([$tplId]);
            $tplRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($tplRow === false) { $skipped++; continue; }
            $typeId = $tplRow['type_id'] !== null ? (int)$tplRow['type_id'] : null;
        }
        $st = $db->prepare("SELECT 1 FROM stamp_register WHERE user_id <=> ? AND dept_id <=> ? AND position_id IS NULL AND status='active' AND type_id <=> ? LIMIT 1 FOR UPDATE");
        $st->execute([$tuid, $deptId, $typeId]);
        if ($st->fetchColumn()) { $skipped++; continue; }
        $st = $db->prepare("INSERT INTO stamp_register (user_id, dept_id, position_id, type_id, template_id, issue_date, status, note, created_by) VALUES (?,?,NULL,?,?,?,'active','批次建立',?)");
        $st->execute([$tuid, $deptId, $typeId, $tplId, $issue, $cname]);
        $created++;
    }
    $db->commit();
    jout(['ok'=>true, 'created'=>$created, 'skipped'=>$skipped]);
}

default: jerr('未知的 action');
}
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    jerr('系統錯誤：' . $e->getMessage(), 500);
}
