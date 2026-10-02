<?php
/**
 * ControlPlan_API.php — 管制計畫 CP（Control Plan）的資料介面
 * 建立：2026-10-02
 *
 * 所有判定規則（權限、編號、階段、自動帶入、驗證）一律放 src/common/control_plan_lib.php，
 * 本檔只負責收參數、驗權限、呼叫它、回 JSON（鐵律4）。
 * 鐵律8：前端擋過的每一條這裡一律再擋一次（權限、已核准不可改、階段必須存在、日期不可未來）。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/control_plan_lib.php';
require_once __DIR__ . '/../common/print_log_lib.php';

function jout($ok, $data = []) {
    echo json_encode(array_merge(['success' => $ok], is_array($data) ? $data : ['message' => $data]), JSON_UNESCAPED_UNICODE);
    exit;
}
function jerr($msg, $code = '') { jout(false, ['message' => $msg, 'code' => $code]); }

// 未捕捉的例外一律轉成 JSON——回空白 500 時畫面上只會「按了沒反應」，完全查不出原因
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) { http_response_code(401); jerr('尚未登入或登入已逾時，請重新整理頁面後再試', 'LOGIN'); }

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
cp_ensure_schema($db);
$perm = cp_perms($db, $uid);

if (!$perm['view']) { http_response_code(403); jerr('沒有管制計畫的檢視權限'); }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 寫入類一律驗 CSRF
if (isset($_POST['action'])) {
    $tok = $_POST['csrf'] ?? '';
    if (empty($_SESSION['cp_csrf']) || !hash_equals((string)$_SESSION['cp_csrf'], (string)$tok)) {
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 'CSRF');
    }
}

$jsonArr = function ($key) {
    $raw = $_POST[$key] ?? '';
    if ($raw === '' || $raw === null) return [];
    $v = json_decode((string)$raw, true);
    return is_array($v) ? $v : [];
};

switch ($action) {

/* ── 共用參考資料（頁面載入時抓一次）──────────────────── */
case 'bootstrap': {
    $tagInfo = cp_order_tag_status($db);
    jout(true, [
        'perm'            => $perm,
        'stages'          => cp_stages($db),
        'special_classes' => cp_special_classes($db),
        'reaction_opts'   => cp_reaction_opts($db, false),
        'as_doc'          => cp_print_meta($db),
        'tag_status'      => $tagInfo,
        'as_tag_defs'     => cp_as_tag_defs($db, false),
        'excluded_as_tags'=> cp_excluded_as_tags($db),
        'required_as_tags'=> cp_required_as_tags($db),
        'csrf'            => $_SESSION['cp_csrf'] ?? '',
    ]);
}

/* ── 清單 ──────────────────────────────────────────────── */
case 'list': {
    $r = cp_list($db, [
        'stage_id' => (int)($_GET['stage_id'] ?? 0),
        'status'   => trim((string)($_GET['status'] ?? '')),
        'kw'       => trim((string)($_GET['kw'] ?? '')),
        'page'     => (int)($_GET['page'] ?? 1),
        'per'      => (int)($_GET['per'] ?? 20),
    ]);
    jout(true, $r);
}

case 'get': {
    $cpId = (int)($_GET['cp_id'] ?? 0);
    $doc = $cpId > 0 ? cp_get($db, $cpId) : null;
    if (!$doc) jerr('找不到這份管制計畫。');
    jout(true, ['doc' => $doc]);
}

/* ── 自動帶入預覽（不寫入任何資料）────────────────────── */
case 'autofill': {
    $r = cp_autofill_preview($db, [
        'order_id'  => (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0),
        'part_d_id' => (int)($_GET['part_d_id'] ?? $_POST['part_d_id'] ?? 0),
        'bom'       => trim((string)($_GET['bom'] ?? $_POST['bom'] ?? '')),
        'stage_id'  => (int)($_GET['stage_id'] ?? $_POST['stage_id'] ?? 0),
    ]);
    jout(true, $r);
}

/* 該訂單綁到的製令（給「選製令」用） */
case 'order_boms': {
    $oid = (int)($_GET['order_id'] ?? 0);
    if ($oid <= 0) jerr('缺少訂單。');
    jout(true, ['boms' => cp_order_boms($db, $oid)]);
}

/* ── 搜尋：料號 / 訂單（挑選器用）─────────────────────── */
case 'search_part': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    if (mb_strlen($kw) < 1) jout(true, ['rows' => []]);
    $like = '%' . str_replace(['%','_'], ['\%','\_'], $kw) . '%';
    $st = $db->prepare(
        "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Revision, d.Remark,
                d.Customer_Id, c.customer
           FROM d_setting d
           LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
          WHERE d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ?
          ORDER BY d.D_Setting_Id, d.d_id
          LIMIT 50"
    );
    $st->execute([$like, $like]);
    jout(true, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

case 'search_order': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    if (mb_strlen($kw) < 1) jout(true, ['rows' => []]);
    $like = '%' . str_replace(['%','_'], ['\%','\_'], $kw) . '%';
    // Order_status 6=暫停/取消，一律排除（口徑與 order_analysis_lib 相同）
    $st = $db->prepare(
        "SELECT o.Order_id, o.Order_oo, o.d_id, o.d_id_ID, o.Client_name, o.Qty,
                o.Order_date, o.Specification
           FROM order_track o
          WHERE (o.Order_oo LIKE ? OR o.d_id LIKE ? OR o.Client_name LIKE ? OR o.C_order LIKE ?)
            AND (o.Order_status IS NULL OR o.Order_status <> 6)
          ORDER BY o.Order_date DESC, o.Order_id DESC
          LIMIT 50"
    );
    $st->execute([$like, $like, $like, $like]);
    jout(true, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

/* 量具挑選（特性列的檢具欄）——走 ai-rules/25 的唯一實作 qc_tool_pick_rows()，
   不自己寫 SQL：顯示哪幾個欄位是逐類別設定的，各頁自己拼字串一定會跟量測儀器校驗頁走鐘。
   帶 asof＝本單表單日期，讓「當時還沒停用」的量具也挑得到（補舊單據用）。 */
case 'search_tool': {
    require_once __DIR__ . '/../common/qc_tool_display_lib.php';
    $rows = qc_tool_pick_rows($db, [
        'kw'    => trim((string)($_GET['kw'] ?? '')),
        'asof'  => trim((string)($_GET['asof'] ?? '')),
        'limit' => 50,
    ]);
    foreach ($rows as &$r) { $r['disp'] = qc_tool_disp_label($db, $r); }
    unset($r);
    jout(true, ['rows' => $rows]);
}

/* 製程主檔（手動新增製程列用） */
case 'search_process': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    $like = '%' . str_replace(['%','_'], ['\%','\_'], $kw) . '%';
    $st = $db->prepare(
        "SELECT ProcessNo, ProcessName FROM process_no
          WHERE ProcessName LIKE ? OR ProcessNo LIKE ?
          ORDER BY ProcessNo LIMIT 80"
    );
    $st->execute([$like, $like]);
    jout(true, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

/* ── 存檔與流程 ────────────────────────────────────────── */
case 'save': {
    if (!$perm['edit']) { http_response_code(403); jerr('沒有建立或修改管制計畫的權限'); }
    $in = [
        'cp_id'      => (int)($_POST['cp_id'] ?? 0),
        'stage_id'   => (int)($_POST['stage_id'] ?? 0),
        'scope'      => trim((string)($_POST['scope'] ?? 'part')),
        'part_d_id'  => (int)($_POST['part_d_id'] ?? 0),
        'part_no_text'=> trim((string)($_POST['part_no_text'] ?? '')),
        'product_name'=> trim((string)($_POST['product_name'] ?? '')),
        'family_name'=> trim((string)($_POST['family_name'] ?? '')),
        'customer_id'=> trim((string)($_POST['customer_id'] ?? '')),
        'customer_name'=> trim((string)($_POST['customer_name'] ?? '')),
        'part_rev'   => trim((string)($_POST['part_rev'] ?? '')),
        'ver_no'     => trim((string)($_POST['ver_no'] ?? '')),
        'form_date'  => trim((string)($_POST['form_date'] ?? '')),
        'src_order_id'=> (int)($_POST['src_order_id'] ?? 0),
        'src_order_oo'=> trim((string)($_POST['src_order_oo'] ?? '')),
        'src_bom'    => trim((string)($_POST['src_bom'] ?? '')),
        'src_bom_date'=> trim((string)($_POST['src_bom_date'] ?? '')),
        'pfmea_doc_id'=> (int)($_POST['pfmea_doc_id'] ?? 0),
        'org_code'   => trim((string)($_POST['org_code'] ?? '')),
        'key_contact'=> trim((string)($_POST['key_contact'] ?? '')),
        'core_team'  => trim((string)($_POST['core_team'] ?? '')),
        'customer_eng_appr' => trim((string)($_POST['customer_eng_appr'] ?? '')),
        'customer_qa_appr'  => trim((string)($_POST['customer_qa_appr'] ?? '')),
        'other_appr' => trim((string)($_POST['other_appr'] ?? '')),
        'note'       => (string)($_POST['note'] ?? ''),
        'processes'  => $jsonArr('processes'),
        'family_parts' => $jsonArr('family_parts'),
    ];
    $r = cp_save($db, $in, $perm);
    if (!$r['ok']) jerr($r['msg']);
    jout(true, $r);
}

case 'submit': {
    $r = cp_submit($db, (int)($_POST['cp_id'] ?? 0), $perm);
    if (!$r['ok']) jerr($r['msg']);
    jout(true, $r);
}

case 'approve': {
    $r = cp_approve($db, (int)($_POST['cp_id'] ?? 0), $perm);
    if (!$r['ok']) jerr($r['msg']);
    jout(true, $r);
}

case 'reject': {
    $r = cp_reject($db, (int)($_POST['cp_id'] ?? 0), (string)($_POST['reason'] ?? ''), $perm);
    if (!$r['ok']) jerr($r['msg']);
    jout(true, $r);
}

case 'revise': {
    $r = cp_revise($db, (int)($_POST['cp_id'] ?? 0), trim((string)($_POST['note'] ?? '')), $perm);
    if (!$r['ok']) jerr($r['msg']);
    jout(true, $r);
}

case 'delete': {
    $r = cp_delete($db, (int)($_POST['cp_id'] ?? 0), $perm);
    if (!$r['ok']) { http_response_code(403); jerr($r['msg']); }
    jout(true, $r);
}

/* ── 建議建立清單 ─────────────────────────────────────── */
case 'suggest': {
    jout(true, cp_suggest_rows($db, ['stage_id' => (int)($_GET['stage_id'] ?? 0)]));
}

case 'suggest_ignore': {
    $r = cp_suggest_ignore($db, (int)($_POST['part_d_id'] ?? 0), (int)($_POST['stage_id'] ?? 0),
                           (string)($_POST['reason'] ?? ''), $perm);
    if (!$r['ok']) jerr($r['msg']);
    jout(true, $r);
}

case 'suggest_unignore': {
    $r = cp_suggest_unignore($db, (int)($_POST['part_d_id'] ?? 0), (int)($_POST['stage_id'] ?? 0), $perm);
    if (!$r['ok']) jerr($r['msg']);
    jout(true, $r);
}

case 'suggest_ignored_list': {
    $st = $db->query(
        "SELECT g.part_d_id, g.stage_id, g.reason, g.created_at,
                d.D_Setting_Id, s.stage_name, u.user_cname
           FROM cp_suggest_ignore g
           LEFT JOIN d_setting d ON d.d_id = g.part_d_id
           LEFT JOIN cp_stage s ON s.stage_id = g.stage_id
           LEFT JOIN user u ON u.id = g.created_by
          ORDER BY g.id DESC"
    );
    jout(true, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

/* ── 設定（限管理員）──────────────────────────────────── */
case 'stage_save': {
    if (!$perm['admin']) { http_response_code(403); jerr('只有管制計畫管理員可以改設定'); }
    $rows = $jsonArr('rows');
    if (!$rows) jerr('沒有要儲存的階段。');
    $db->beginTransaction();
    try {
        $st = $db->prepare("UPDATE cp_stage SET stage_name=?, default_freq=?, sort_order=?, is_active=?, note=? WHERE stage_id=?");
        $n = 0;
        foreach ($rows as $i => $r) {
            $id = (int)($r['stage_id'] ?? 0);
            $nm = trim((string)($r['stage_name'] ?? ''));
            if ($id <= 0 || $nm === '') continue;
            $st->execute([$nm, trim((string)($r['default_freq'] ?? '')), (int)($r['sort_order'] ?? ($i + 1)),
                          (int)!empty($r['is_active']), trim((string)($r['note'] ?? '')), $id]);
            $n++;
        }
        // 至少要留一段啟用，否則誰都建不了 CP
        $act = (int)$db->query("SELECT COUNT(*) FROM cp_stage WHERE is_active=1")->fetchColumn();
        if ($act === 0) { $db->rollBack(); jerr('至少要有一個階段是啟用的，否則無法建立任何管制計畫。'); }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage());
    }
    jout(true, ['message' => '已儲存 ' . $n . ' 個階段設定。', 'stages' => cp_stages($db)]);
}

case 'special_class_save': {
    if (!$perm['admin']) { http_response_code(403); jerr('只有管制計畫管理員可以改設定'); }
    $rows = $jsonArr('rows');
    $db->beginTransaction();
    try {
        $keep = [];
        $up = $db->prepare("UPDATE cp_special_class SET symbol=?, class_name=?, note=?, sort_order=?, is_active=? WHERE class_id=?");
        $ins = $db->prepare("INSERT INTO cp_special_class (symbol, class_name, note, sort_order, is_active) VALUES (?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $nm = trim((string)($r['class_name'] ?? ''));
            if ($nm === '') continue;
            $sym = trim((string)($r['symbol'] ?? ''));
            $note = trim((string)($r['note'] ?? ''));
            $so = (int)($r['sort_order'] ?? ($i + 1));
            $act = (int)!empty($r['is_active']);
            $id = (int)($r['class_id'] ?? 0);
            if ($id > 0) { $up->execute([$sym, $nm, $note, $so, $act, $id]); $keep[] = $id; }
            else { $ins->execute([$sym, $nm, $note, $so, $act]); $keep[] = (int)$db->lastInsertId(); }
        }
        // 沒送回來的＝畫面上被刪掉的；但已經被 CP 用到的不可真刪，改為停用（否則舊 CP 的特殊特性會變空白）
        $all = $db->query("SELECT class_id FROM cp_special_class")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($all as $cid) {
            $cid = (int)$cid;
            if (in_array($cid, $keep, true)) continue;
            $used = (int)$db->query("SELECT COUNT(*) FROM cp_item WHERE special_class_id=" . $cid)->fetchColumn();
            if ($used > 0) $db->exec("UPDATE cp_special_class SET is_active=0 WHERE class_id=" . $cid);
            else $db->exec("DELETE FROM cp_special_class WHERE class_id=" . $cid);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage());
    }
    jout(true, ['message' => '已儲存。', 'rows' => cp_special_classes($db)]);
}

case 'reaction_opt_save': {
    if (!$perm['admin']) { http_response_code(403); jerr('只有管制計畫管理員可以改設定'); }
    $rows = $jsonArr('rows');
    $db->beginTransaction();
    try {
        $keep = [];
        $up = $db->prepare("UPDATE cp_reaction_opt SET opt_text=?, sort_order=?, is_active=?, is_default=? WHERE opt_id=?");
        $ins = $db->prepare("INSERT INTO cp_reaction_opt (opt_text, sort_order, is_active, is_default) VALUES (?,?,?,?)");
        // 預設只能有一列（自動帶入時要有明確答案），所以先看畫面上勾了哪一列
        $defIdx = -1;
        foreach ($rows as $i => $r) { if (!empty($r['is_default'])) { $defIdx = $i; break; } }
        foreach ($rows as $i => $r) {
            $t = trim((string)($r['opt_text'] ?? ''));
            if ($t === '') continue;
            $so = (int)($r['sort_order'] ?? ($i + 1));
            $act = (int)!empty($r['is_active']);
            $def = ($i === $defIdx && $act) ? 1 : 0;   // 停用的那一列不可以同時是預設
            $id = (int)($r['opt_id'] ?? 0);
            if ($id > 0) { $up->execute([$t, $so, $act, $def, $id]); $keep[] = $id; }
            else { $ins->execute([$t, $so, $act, $def]); $keep[] = (int)$db->lastInsertId(); }
        }
        $all = $db->query("SELECT opt_id FROM cp_reaction_opt")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($all as $oid) {
            if (!in_array((int)$oid, $keep, true)) $db->exec("DELETE FROM cp_reaction_opt WHERE opt_id=" . (int)$oid);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage());
    }
    jout(true, ['message' => '已儲存。', 'rows' => cp_reaction_opts($db, false)]);
}

/* 哪些稽核製程標籤「不要求」建 CP（存排除名單；空＝全部稽核製程都要求）。
   標籤定義只讀訂單追蹤模組的 ot_as_proc_tag，本模組不自己做一套。 */
case 'excluded_as_tags_save': {
    if (!$perm['admin']) { http_response_code(403); jerr('只有管制計畫管理員可以改設定'); }
    $ids = $jsonArr('tag_ids');
    // 只能排除真正存在的稽核製程定義（鐵律8：擋掉亂送的 id，否則排除名單會留一堆垃圾）
    $valid = array_map(function ($d) { return (int)$d['tag_id']; }, cp_as_tag_defs($db, false));
    $ids = array_values(array_intersect(array_map('intval', $ids), $valid));
    cp_excluded_as_tags_save($db, $ids);
    $req = cp_required_as_tags($db);
    jout(true, [
        'message' => $ids
            ? ('已儲存：排除 ' . count($ids) . ' 個稽核製程，其餘 ' . count($req) . ' 個仍要求建管制計畫。')
            : ('已儲存：全部 ' . count($req) . ' 個稽核製程都要求建管制計畫。'),
        'excluded_as_tags' => cp_excluded_as_tags($db),
        'required_as_tags' => $req,
        'tag_status'       => cp_order_tag_status($db),
    ]);
}

/* 單張訂單的「需不需要 CP」判定（畫面挑到訂單時即時顯示） */
case 'order_as_tag': {
    $oid = (int)($_GET['order_id'] ?? 0);
    if ($oid <= 0) jerr('缺少訂單。');
    $t = cp_order_as_tag($db, $oid);
    if (!$t) jerr('找不到這張訂單。');
    jout(true, ['as_tag' => $t]);
}

/* 稽核製程標籤定義清單（設定頁用）。含停用的，並附「這個標籤目前掛了幾張訂單」
   ——管理員要排除某個稽核製程之前，會想知道影響多少張單。 */
case 'as_tag_list': {
    $defs = cp_as_tag_defs($db, false);
    $ex   = cp_excluded_as_tags($db);
    foreach ($defs as &$d) {
        $d['excluded'] = in_array((int)$d['tag_id'], $ex, true) ? 1 : 0;
        $d['n_order']  = 0;
        $d['n_part']   = 0;
        try {
            $st = $db->prepare(
                "SELECT COUNT(*) n_ord, COUNT(DISTINCT d_id_ID) n_part FROM order_track
                  WHERE as_tag_id = ? AND (Order_status IS NULL OR Order_status <> 6)"
            );
            $st->execute([(int)$d['tag_id']]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $d['n_order'] = (int)($r['n_ord'] ?? 0);
            $d['n_part']  = (int)($r['n_part'] ?? 0);
        } catch (Throwable $e) {}
        $d['label_single'] = cp_as_tag_label($d['proc_name'], 'single');
        $d['label_full']   = cp_as_tag_label($d['proc_name'], 'full');
    }
    unset($d);
    jout(true, ['rows' => $defs, 'status' => cp_order_tag_status($db)]);
}

/* AS 文件綁定（走共用 asdoc_lib，不自己存一份） */
case 'asdoc_save': {
    if (!$perm['admin']) { http_response_code(403); jerr('只有管制計畫管理員可以改設定'); }
    require_once __DIR__ . '/../common/asdoc_lib.php';
    $docId = (int)($_POST['doc_id'] ?? 0);
    if ($docId <= 0) jerr('請選擇要綁定的 AS 文件。');
    $chk = $db->prepare("SELECT COUNT(*) FROM as_document WHERE id=?");
    $chk->execute([$docId]);
    if ((int)$chk->fetchColumn() === 0) jerr('指定的 AS 文件不存在。');
    $uname = '';
    try {
        $q = $db->prepare("SELECT user_cname FROM user WHERE id=?");
        $q->execute([$uid]);
        $uname = (string)($q->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    eg_asdoc_save($db, CP_ASDOC_MODULE, $docId, $uname);
    jout(true, ['message' => '已綁定。', 'as_doc' => cp_print_meta($db)]);
}

/* 列印用資料（含表頭三固定元素，版次依本單表單日期回推） */
case 'print_meta': {
    $cpId = (int)($_GET['cp_id'] ?? 0);
    $doc = $cpId > 0 ? cp_get($db, $cpId) : null;
    if (!$doc) jerr('找不到這份管制計畫。');
    jout(true, [
        'doc'  => $doc,
        'meta' => cp_print_meta($db, (string)$doc['form_date']),
        'special_classes' => cp_special_classes($db),
    ]);
}

/* 列印紀錄（ai-rules/23：會列印的頁面一律留紀錄，doc_kind 一定要明確傳 form） */
case 'print_log': {
    $cpId = (int)($_POST['cp_id'] ?? 0);
    $name = trim((string)($_POST['doc_name'] ?? ''));
    if ($name === '') $name = '管制計畫';
    eg_print_log_add($db, [
        'source'    => 'control_plan',
        'doc_name'  => $name,
        'doc_kind'  => 'form',
        'ref_table' => 'cp_doc',
        'ref_id'    => $cpId ?: null,
        'part_no'   => trim((string)($_POST['part_no'] ?? '')),
        'user_id'   => $uid,
    ]);
    jout(true, ['message' => 'ok']);
}

default:
    jerr('無效的操作：' . $action);
}
