<?php
/**
 * 風險與機會管理 API —— 2026-10-14 建立（共用庫 src/common/risk_mgmt_lib.php）
 * 權限：rm_perms()（roles module='risk_mgmt'）
 *   一般在職員工  建立／編輯自己建立的表單
 *   rm_view       唯讀看全部
 *   rm_admin      全部＋模組設定＋刪除＋AS 文件綁定
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/risk_mgmt_lib.php';

function jout($a = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
set_exception_handler(function ($e) { jerr('系統錯誤：' . $e->getMessage(), 500); });

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    rm_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['rm_csrf'])) $_SESSION['rm_csrf'] = bin2hex(random_bytes(16));

$u = cm_current_user($db);
if (!$u) jerr('未登入', 401);
$P     = rm_perms($db, $u);
$uid   = (int)$P['uid'];
$uname = (string)$P['name'];
if (!$uid) jerr('無使用權限（帳號非在職狀態）', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* 寫入類動作：先驗登入再驗 CSRF（順序不可顛倒，見 _config.php 的說明） */
$WRITE = ['doc_save', 'doc_delete', 'setting_save', 'asdoc_save'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['rm_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code'=>'CSRF']);
}

function rmDate($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
function rmS($v, int $max = 500): string { return mb_substr(trim((string)$v), 0, $max); }
function rmReqAdmin(array $P) { if (!$P['canAdmin']) jerr('需要風險與機會管理員權限', 403); }
function rmKind($v): string { $k = rmS($v, 10); return isset(RM_ASDOC_MODULES[$k]) ? $k : jerr('表單種類不正確'); }
function rmToday(PDO $db): string {
    try { return (string)$db->query("SELECT CURDATE()")->fetchColumn(); } catch (Throwable $e) { return date('Y-m-d'); }
}
function rmNow(PDO $db): string {
    try { return (string)$db->query("SELECT NOW()")->fetchColumn(); } catch (Throwable $e) { return date('Y-m-d H:i:s'); }
}
function rmDocGet(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM rm_doc WHERE doc_id=? AND is_deleted=0");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function rmCanSee(array $r, array $P): bool { return $P['canView'] || (int)$r['created_by'] === (int)$P['uid']; }
function rmCanEdit(array $r, array $P): bool { return $P['canAdmin'] || (int)$r['created_by'] === (int)$P['uid']; }

/** 明細列＋即時算出的風險等級（用「目前設定」，不讀存好的值） */
function rmItems(PDO $db, int $docId, array $set): array {
    $st = $db->prepare("SELECT * FROM rm_item WHERE doc_id=? ORDER BY seq, item_id");
    $st->execute([$docId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $lv = rm_level_calc($set, $r['severity'], $r['frequency']);
        $r['level'] = $lv ? array_merge($lv, rm_level_color($lv['rank'])) : null;
        // 連動組織處境分析表的議題：內外部／利害關係者／需求與期望／議題描述一律讀來源的「現在」內容
        $r['ctx_linked'] = false; $r['ctx_doc_no'] = null;
        if ((int)$r['ctx_item_id'] > 0) {
            $q = $db->prepare("SELECT i.scope, i.stakeholder, i.needs, i.issue, d.doc_no FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id
                               WHERE i.item_id=? AND d.kind='context' AND d.is_deleted=0");
            $q->execute([(int)$r['ctx_item_id']]);
            if ($c = $q->fetch(PDO::FETCH_ASSOC)) {
                $r['scope'] = $c['scope']; $r['stakeholder'] = $c['stakeholder']; $r['needs'] = $c['needs']; $r['issue'] = $c['issue'];
                $r['ctx_linked'] = true; $r['ctx_doc_no'] = $c['doc_no'];
            }
        }
    }
    unset($r);
    return $rows;
}

/* ---------------------------------------------------------------- meta */
if ($action === 'meta') {
    $set = rm_settings($db);
    $lvls = [];
    foreach ($set['rm_levels'] as $i => $lv) $lvls[] = array_merge($lv, rm_level_color($i));
    jout([
        'csrf'     => (string)$_SESSION['rm_csrf'],
        'perms'    => $P,
        'me'       => ['id'=>$uid, 'name'=>$uname],
        'today'    => rmToday($db),
        'settings' => $set,
        'levels'   => $lvls,
        'asdoc'    => rm_asdoc_all($db),
        'asdoc_list' => $P['canAdmin'] ? eg_asdoc_list($db) : [],
        'scopes'   => RM_SCOPES, 'ro_kinds' => RM_RO_KINDS,
        'depts'    => cm_dept_list($db),
        'roles'    => rm_roles_now($db),
    ]);
}

/* ---------------------------------------------------------------- 列表 */
if ($action === 'doc_list') {
    $kind = rmKind($_GET['kind'] ?? '');
    $per  = (int)($_GET['per'] ?? 20); if ($per !== 0) $per = max(5, min(100, $per));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $kw   = rmS($_GET['kw'] ?? '', 60);
    $year = (int)($_GET['year'] ?? 0);

    $where = ["d.kind=?", "d.is_deleted=0"]; $a = [$kind];
    if (!$P['canView']) { $where[] = "d.created_by=?"; $a[] = $uid; }
    if ($year > 0) { $where[] = "YEAR(d.fill_date)=?"; $a[] = $year; }
    if ($kw !== '') {
        $like = '%' . $kw . '%';
        $where[] = "(d.doc_no LIKE ? OR d.project_no LIKE ? OR d.dept_names LIKE ? OR d.created_by_name LIKE ?
                     OR EXISTS (SELECT 1 FROM rm_item i WHERE i.doc_id=d.doc_id AND (i.stakeholder LIKE ? OR i.needs LIKE ? OR i.issue LIKE ?
                          OR i.ro_text LIKE ? OR i.measure LIKE ? OR i.effect_check LIKE ? OR i.confirm_no LIKE ? OR i.dimension LIKE ?)))";
        for ($i = 0; $i < 12; $i++) $a[] = $like;
    }
    $w = implode(' AND ', $where);
    $st = $db->prepare("SELECT COUNT(*) FROM rm_doc d WHERE $w"); $st->execute($a);
    $total = (int)$st->fetchColumn();
    $lim = $per === 0 ? '' : ' LIMIT ' . (($page - 1) * $per) . ',' . $per;
    $st = $db->prepare("SELECT d.*, (SELECT COUNT(*) FROM rm_item i WHERE i.doc_id=d.doc_id) AS item_cnt
                        FROM rm_doc d WHERE $w ORDER BY d.fill_date DESC, d.doc_id DESC" . $lim);
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $set = rm_settings($db);
    foreach ($rows as &$r) {
        $r['top_level'] = null; $r['action_cnt'] = 0;
        if ($kind === 'risk') {
            $its = $db->prepare("SELECT severity, frequency FROM rm_item WHERE doc_id=?");
            $its->execute([(int)$r['doc_id']]);
            $best = null;
            foreach ($its->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $lv = rm_level_calc($set, $it['severity'], $it['frequency']);
                if (!$lv) continue;
                if ($lv['action']) $r['action_cnt']++;
                if (!$best || $lv['score'] > $best['score']) $best = $lv;
            }
            if ($best) $r['top_level'] = array_merge($best, rm_level_color($best['rank']));
        }
        $r['can_edit'] = rmCanEdit($r, $P);
    }
    unset($r);
    jout(['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per, 'print'=>rm_print_meta($db, $kind)]);
}

/* ---------------------------------------------------------------- 讀取一張 */
if ($action === 'doc_get') {
    $r = rmDocGet($db, (int)($_GET['doc_id'] ?? 0));
    if (!$r) jerr('查無此表單', 404);
    if (!rmCanSee($r, $P)) jerr('您沒有查看這張表單的權限', 403);
    $set = rm_settings($db);
    jout(['doc'=>$r, 'items'=>rmItems($db, (int)$r['doc_id'], $set), 'can_edit'=>rmCanEdit($r, $P),
          'print'=>rm_print_meta($db, $r['kind'], (string)$r['fill_date'])]);
}

/* ---------------------------------------------------------------- 可引用的組織處境議題（風險表挑「引用處境議題」用） */
if ($action === 'ctx_options') {
    $kw = rmS($_GET['kw'] ?? '', 60);
    $where = ["d.kind='context'", "d.is_deleted=0"]; $a = [];
    if (!$P['canView']) { $where[] = "d.created_by=?"; $a[] = $uid; }
    if ($kw !== '') {
        $where[] = "(i.issue LIKE ? OR i.stakeholder LIKE ? OR i.needs LIKE ? OR i.dimension LIKE ?)";
        for ($i = 0; $i < 4; $i++) $a[] = '%' . $kw . '%';
    }
    $st = $db->prepare("SELECT i.item_id, i.scope, i.dimension, i.stakeholder, i.needs, i.issue, d.doc_no, d.fill_date
                        FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY d.fill_date DESC, i.item_id DESC LIMIT 60");
    $st->execute($a);
    jout(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---------------------------------------------------------------- 儲存（新增／編輯，整張含明細） */
if ($action === 'doc_save') {
    $id   = (int)($_POST['doc_id'] ?? 0);
    $old  = $id ? rmDocGet($db, $id) : null;
    if ($id && !$old) jerr('查無此表單', 404);
    if ($old && !rmCanEdit($old, $P)) jerr('只有填表人或管理員可以修改這張表單', 403);
    $kind = $old ? (string)$old['kind'] : rmKind($_POST['kind'] ?? '');

    $fillDate = rmDate($_POST['fill_date'] ?? '');
    if (!$fillDate) jerr('請填寫填表日期');
    if ($fillDate > rmToday($db)) jerr('填表日期不可是未來日期');

    $set = rm_settings($db);
    $dimOk = array_map('strval', (array)$set['rm_dimensions']);

    // 相關部門：只收主檔裡真的存在的部門 id，名稱一律回查主檔（不採信前端送來的文字）
    $deptIds = json_decode((string)($_POST['dept_ids'] ?? '[]'), true);
    $deptIds = is_array($deptIds) ? array_values(array_unique(array_filter(array_map('intval', $deptIds)))) : [];
    $deptMap = [];
    foreach (cm_dept_list($db) as $d) $deptMap[(int)$d['id']] = (string)$d['name'];
    $deptIds = array_values(array_filter($deptIds, function ($x) use ($deptMap) { return isset($deptMap[$x]); }));
    $deptNames = implode('、', array_map(function ($x) use ($deptMap) { return $deptMap[$x]; }, $deptIds));

    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('明細內容不正確');
    $items = array_slice($items, 0, 200);

    $clean = []; $seq = 0;
    $sevOk = array_map(function ($r) { return (int)$r['v']; }, $set['rm_severity']);
    $frqOk = array_map(function ($r) { return (int)$r['v']; }, $set['rm_frequency']);
    foreach ($items as $n => $it) {
        $row = [
            'scope'       => in_array(($it['scope'] ?? ''), array_keys(RM_SCOPES), true) ? $it['scope'] : null,
            'dimension'   => null,
            'stakeholder' => rmS($it['stakeholder'] ?? '', 200),
            'needs'       => rmS($it['needs'] ?? '', 4000),
            'issue'       => rmS($it['issue'] ?? '', 4000),
            'ro_kind'     => null, 'ro_text' => '', 'severity' => null, 'frequency' => null,
            'confirm_no'  => rmS($it['confirm_no'] ?? '', 30),
            'measure'     => '', 'effect_check' => '', 'ctx_item_id' => null,
        ];
        if ($kind === 'context') {
            $dim = rmS($it['dimension'] ?? '', 40);
            if ($dim !== '' && !in_array($dim, $dimOk, true)) {
                // 構面清單被管理員改過、這列是舊值：允許原值保留（編輯舊單不該因此存不進去），新值一律要在清單內
                $keep = false;
                if ($old && !empty($it['item_id'])) {
                    $q = $db->prepare("SELECT dimension FROM rm_item WHERE item_id=? AND doc_id=?");
                    $q->execute([(int)$it['item_id'], $id]);
                    $keep = ((string)$q->fetchColumn() === $dim);
                }
                if (!$keep) jerr('第 ' . ($n + 1) . ' 列的構面「' . $dim . '」不在設定的清單內');
            }
            $row['dimension'] = $dim !== '' ? $dim : null;
        } else {
            $row['ro_kind'] = in_array(($it['ro_kind'] ?? ''), array_keys(RM_RO_KINDS), true) ? $it['ro_kind'] : null;
            $row['ro_text'] = rmS($it['ro_text'] ?? '', 4000);
            $row['measure'] = rmS($it['measure'] ?? '', 4000);
            $row['effect_check'] = rmS($it['effect_check'] ?? '', 4000);
            $sv = ($it['severity'] ?? '') === '' ? null : (int)$it['severity'];
            $fq = ($it['frequency'] ?? '') === '' ? null : (int)$it['frequency'];
            if ($sv !== null && !in_array($sv, $sevOk, true)) jerr('第 ' . ($n + 1) . ' 列的嚴重度不在設定的級距內');
            if ($fq !== null && !in_array($fq, $frqOk, true)) jerr('第 ' . ($n + 1) . ' 列的頻率不在設定的級距內');
            if (($sv === null) !== ($fq === null)) jerr('第 ' . ($n + 1) . ' 列的嚴重度與頻率要一起填（缺一個算不出風險等級）');
            $row['severity'] = $sv; $row['frequency'] = $fq;
            $cx = (int)($it['ctx_item_id'] ?? 0);
            if ($cx > 0) {
                $q = $db->prepare("SELECT 1 FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id WHERE i.item_id=? AND d.kind='context' AND d.is_deleted=0");
                $q->execute([$cx]);
                if ($q->fetchColumn()) {
                    $row['ctx_item_id'] = $cx;
                    // 連動中的四個欄位以來源為準（不採信前端送來的值，避免畫面與來源對不起來）
                    $q2 = $db->prepare("SELECT scope, stakeholder, needs, issue FROM rm_item WHERE item_id=?");
                    $q2->execute([$cx]);
                    if ($c = $q2->fetch(PDO::FETCH_ASSOC)) {
                        $row['scope'] = $c['scope']; $row['stakeholder'] = (string)$c['stakeholder'];
                        $row['needs'] = (string)$c['needs']; $row['issue'] = (string)$c['issue'];
                    }
                }
            }
        }
        // 整列都沒填東西就略過（末列多按出來的空白列）
        $blank = $row['stakeholder'] === '' && $row['needs'] === '' && $row['issue'] === '' && $row['ro_text'] === ''
              && $row['measure'] === '' && $row['effect_check'] === '' && $row['dimension'] === null && $row['severity'] === null;
        if ($blank) continue;
        $row['_old_id'] = (int)($it['item_id'] ?? 0);
        $clean[] = $row;
    }
    if (!$clean) jerr('至少要填寫一列內容');

    $now = rmNow($db);
    $db->beginTransaction();
    try {
        if ($old) {
            $db->prepare("UPDATE rm_doc SET project_no=?, dept_ids=?, dept_names=?, fill_date=?, remark=?, updated_at=? WHERE doc_id=?")
               ->execute([rmS($_POST['project_no'] ?? '', 60) ?: null, json_encode($deptIds), $deptNames ?: null, $fillDate,
                          rmS($_POST['remark'] ?? '', 500) ?: null, $now, $id]);
            // 填表日期改了，單號跟著重編（單號前八碼＝表單日期）；只有真的換日期才重編，避免無謂跳號
            if (substr(preg_replace('/\D/', '', (string)$old['fill_date']), 0, 8) !== preg_replace('/\D/', '', $fillDate)) {
                $db->prepare("UPDATE rm_doc SET doc_no=? WHERE doc_id=?")->execute([rm_next_doc_no($db, $fillDate), $id]);
            }
        } else {
            $db->prepare("INSERT INTO rm_doc (doc_no, kind, project_no, dept_ids, dept_names, fill_date, remark,
                            created_by, created_by_name, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([rm_next_doc_no($db, $fillDate), $kind, rmS($_POST['project_no'] ?? '', 60) ?: null, json_encode($deptIds),
                          $deptNames ?: null, $fillDate, rmS($_POST['remark'] ?? '', 500) ?: null, $uid, $uname, $now, $now]);
            $id = (int)$db->lastInsertId();
        }

        // 既有列：沿用原本的風險確認編號（不可因重存而重配）
        $oldConfirm = [];
        if ($old) {
            $q = $db->prepare("SELECT item_id, confirm_no FROM rm_item WHERE doc_id=?");
            $q->execute([$id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r0) $oldConfirm[(int)$r0['item_id']] = (string)$r0['confirm_no'];
        }
        // 先不刪舊列（其他單據的 ctx_item_id 可能指著組織處境表的列）：依 item_id 對應更新，沒對到的新增，多出來的才刪
        $keepIds = [];
        $seq = 0;
        foreach ($clean as $row) {
            $seq++;
            $oid = $row['_old_id'];
            $isOld = $oid && isset($oldConfirm[$oid]);
            $confirm = $row['confirm_no'];
            if ($isOld && $oldConfirm[$oid] !== '' && $confirm === '') $confirm = $oldConfirm[$oid];
            if ($kind === 'risk' && $confirm === '') {
                $lv = rm_level_calc($set, $row['severity'], $row['frequency']);
                if ($lv && $lv['action']) $confirm = rm_next_confirm_no($db, (string)$set['rm_confirm_prefix'], $fillDate);
            }
            $vals = [$seq, $row['scope'], $row['dimension'], $row['stakeholder'] ?: null, $row['needs'] ?: null, $row['issue'] ?: null,
                     $row['ro_kind'], $row['ro_text'] ?: null, $row['severity'], $row['frequency'], $confirm ?: null,
                     $row['measure'] ?: null, $row['effect_check'] ?: null, $row['ctx_item_id']];
            if ($isOld) {
                $db->prepare("UPDATE rm_item SET seq=?, scope=?, dimension=?, stakeholder=?, needs=?, issue=?, ro_kind=?, ro_text=?,
                                severity=?, frequency=?, confirm_no=?, measure=?, effect_check=?, ctx_item_id=? WHERE item_id=? AND doc_id=?")
                   ->execute(array_merge($vals, [$oid, $id]));
                $keepIds[] = $oid;
            } else {
                $db->prepare("INSERT INTO rm_item (doc_id, seq, scope, dimension, stakeholder, needs, issue, ro_kind, ro_text,
                                severity, frequency, confirm_no, measure, effect_check, ctx_item_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute(array_merge([$id], $vals));
                $keepIds[] = (int)$db->lastInsertId();
            }
        }
        if ($old) {
            $in = implode(',', array_map('intval', $keepIds));
            // 被刪掉的處境列若已被風險表引用：解除引用（不阻擋刪除，引用只是方便帶入）
            $db->exec("UPDATE rm_item SET ctx_item_id=NULL WHERE ctx_item_id IN (SELECT item_id FROM (SELECT item_id FROM rm_item WHERE doc_id=$id AND item_id NOT IN ($in)) t)");
            $db->exec("DELETE FROM rm_item WHERE doc_id=$id AND item_id NOT IN ($in)");
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout(['doc_id'=>$id]);
}

/* ---------------------------------------------------------------- 刪除 */
if ($action === 'doc_delete') {
    rmReqAdmin($P);
    $r = rmDocGet($db, (int)($_POST['doc_id'] ?? 0));
    if (!$r) jerr('查無此表單', 404);
    $db->prepare("UPDATE rm_doc SET is_deleted=1, updated_at=? WHERE doc_id=?")->execute([rmNow($db), (int)$r['doc_id']]);
    jout();
}

/* ---------------------------------------------------------------- 模組設定 */
if ($action === 'setting_save') {
    rmReqAdmin($P);
    $arr = json_decode((string)($_POST['settings'] ?? ''), true);
    if (!is_array($arr)) jerr('設定內容不正確');
    $toWrite = [];
    if (array_key_exists('rm_severity', $arr)) {
        [$v, $e] = rm_normalize_scale($arr['rm_severity'], '嚴重度'); if ($e) jerr($e); $toWrite['rm_severity'] = $v;
    }
    if (array_key_exists('rm_frequency', $arr)) {
        [$v, $e] = rm_normalize_scale($arr['rm_frequency'], '頻率'); if ($e) jerr($e); $toWrite['rm_frequency'] = $v;
    }
    if (array_key_exists('rm_method', $arr)) {
        if (!in_array($arr['rm_method'], ['multiply', 'add', 'max'], true)) jerr('分數算法不正確');
        $toWrite['rm_method'] = $arr['rm_method'];
    }
    if (array_key_exists('rm_levels', $arr)) {
        [$v, $e] = rm_normalize_levels($arr['rm_levels']); if ($e) jerr($e); $toWrite['rm_levels'] = $v;
    }
    if (array_key_exists('rm_dimensions', $arr)) {
        $dims = [];
        foreach ((array)$arr['rm_dimensions'] as $d) { $d = rmS($d, 40); if ($d !== '' && !in_array($d, $dims, true)) $dims[] = $d; }
        if (!$dims || count($dims) > 12) jerr('構面至少 1 個、最多 12 個');
        $toWrite['rm_dimensions'] = $dims;
    }
    if (array_key_exists('rm_confirm_prefix', $arr)) {
        $p = preg_replace('/[^A-Za-z0-9\-]/', '', rmS($arr['rm_confirm_prefix'], 6));
        if ($p === '') jerr('風險確認編號的前綴要填英數字（最多 6 碼）');
        $toWrite['rm_confirm_prefix'] = $p;
    }
    // 級距改了之後，既有資料若還用著被拿掉的值，那一列會算不出等級——先講清楚再讓管理員決定，不要默默存
    if ((isset($toWrite['rm_severity']) || isset($toWrite['rm_frequency'])) && empty($_POST['force'])) {
        $sv = array_map(function ($r) { return (int)$r['v']; }, $toWrite['rm_severity'] ?? rm_settings($db)['rm_severity']);
        $fq = array_map(function ($r) { return (int)$r['v']; }, $toWrite['rm_frequency'] ?? rm_settings($db)['rm_frequency']);
        $bad = (int)$db->query("SELECT COUNT(*) FROM rm_item i JOIN rm_doc d ON d.doc_id=i.doc_id WHERE d.is_deleted=0 AND i.severity IS NOT NULL
                  AND (i.severity NOT IN (" . implode(',', $sv) . ") OR i.frequency NOT IN (" . implode(',', $fq) . "))")->fetchColumn();
        if ($bad > 0) jerr('有 ' . $bad . ' 列既有資料用到「被你拿掉的級距數值」，改完之後那些列會算不出風險等級。請確認後再存。', 409, ['code'=>'NEED_FORCE', 'bad'=>$bad]);
    }
    foreach ($toWrite as $k => $v) rm_setting_write($db, $k, $v, $uname);
    $set = rm_settings($db);
    $lvls = [];
    foreach ($set['rm_levels'] as $i => $lv) $lvls[] = array_merge($lv, rm_level_color($i));
    jout(['settings'=>$set, 'levels'=>$lvls]);
}

if ($action === 'asdoc_save') {
    rmReqAdmin($P);
    $which = rmS($_POST['which'] ?? '', 20);
    if (!isset(RM_ASDOC_MODULES[$which])) jerr('表單代碼不正確');
    eg_asdoc_save($db, RM_ASDOC_MODULES[$which]['module'], (int)($_POST['doc_id'] ?? 0), $uname);
    jout(['asdoc'=>rm_asdoc_all($db)]);
}

/* 列印紀錄（ai-rules/23）一律由前端走共用的 EGPrintLog.record()，本 API 不另開，避免一次列印記兩筆。 */
jerr('無效的操作：' . $action, 400);
