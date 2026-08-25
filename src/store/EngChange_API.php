<?php
/**
 * 工程變更申請／審查／通知單（2-TD-01-01）API
 *
 * 權限：eng_change_lib.php 的 ec_perms()（roles module='eng_change'）
 *       檢閱＝唯讀全部／申請＝開自己的單／管理員＝全部（代開、刪除、改他人的單、設定、AS 綁定）。
 * 簽核權不看角色：由「這一關解析到的人是不是你」決定（含代理人），
 *                 因為各單位主管本來就不會特地去申請一個角色，用角色擋只會讓單子卡住。
 * 送出／各關卡必填檢查：後端一律再跑一次 ec_validate()／ec_validate_stage()（鐵律8 不做半套）。
 * 時間戳一律取 DB 時間（PHP date() 是 UTC、MySQL NOW() 是本地）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/eng_change_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function jout($a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ec_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['ec_csrf'])) $_SESSION['ec_csrf'] = bin2hex(random_bytes(16));

$u = ec_current_user($db);
if (!$u) jerr('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$P     = ec_perms($db, $u);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 寫入類動作先驗登入再驗 CSRF。順序不能顛倒：session 被 GC 掃掉時 token 會在同一個請求裡
// 重新產生、比對必定不過，但那其實是「已經被登出」不是 CSRF 攻擊，訊息講錯使用者只會一直重整
// 卻永遠存不進去（見 src/common/_config.php 的防護說明）。
$WRITE = ['create', 'save', 'submit', 'resubmit', 'sign_stage', 'reject', 'set_review_units',
          'sign_review', 'delete', 'save_setting', 'save_asdoc', 'log_print'];
if (in_array($action, $WRITE, true)) {
    if ($uid <= 0) jerr('登入已逾時，請重新登入後再儲存（您填的內容還在，重新登入後再按一次即可）', 401, ['code' => 'LOGIN']);
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['ec_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code' => 'CSRF']);
}

/* ---------------------------------------------------------------- 共用小工具 */

/** 這個人看不看得到這一張單 */
function ec_can_see(PDO $db, array $r, array $P, int $uid): bool
{
    if ($P['canView']) return true;
    if ((int)($r['applicant_id'] ?? 0) === $uid || (int)($r['created_by'] ?? 0) === $uid) return true;
    // 待你簽的那一關／指派給你的會審單位，即使沒有任何角色也要看得到，否則通知點進來是空白頁
    $stage = ec_current_stage($r);
    if ($stage !== '' && $stage !== 'REVIEW' && ec_can_sign_stage($db, $r, $stage, $uid, false)) return true;
    if ($stage === 'REVIEW') {
        foreach (ec_review_rows($db, (int)$r['ec_id']) as $rv) {
            if ($rv['needed'] && ec_can_sign_review($db, $r, (string)$rv['unit_key'], $uid, false)) return true;
        }
    }
    return false;
}

/** 這個人能不能改這一張單的內容（表頭那一段） */
function ec_can_edit_row(array $r, array $P, int $uid): bool
{
    if ($P['canAdmin']) return true;
    if (!$P['canEdit']) return false;
    // 自己開的、而且還在草稿或被退回時才可以改；送進簽核流程後內容就固定了
    if ((int)($r['created_by'] ?? 0) !== $uid && (int)($r['applicant_id'] ?? 0) !== $uid) return false;
    return in_array((string)$r['status'], ['DRAFT', 'REJECTED'], true);
}

/** 把一列補上畫面要用的衍生欄位 */
function ec_decorate(PDO $db, array $r, array $P, int $uid): array
{
    $stage = ec_current_stage($r);
    $r['stage']        = $stage;
    $r['stage_label']  = $stage !== '' ? (EC_STAGES[$stage]['label'] ?? $stage) : '';
    $r['status_label'] = ec_status_label($r);
    $r['can_edit']     = ec_can_edit_row($r, $P, $uid) ? 1 : 0;
    $r['can_sign']     = ($stage !== '' && $stage !== 'REVIEW'
                          && ec_can_sign_stage($db, $r, $stage, $uid, (bool)$P['canAdmin'])) ? 1 : 0;
    $r['my_review_units'] = [];
    if ($stage === 'REVIEW') {
        foreach (ec_review_rows($db, (int)$r['ec_id']) as $rv) {
            if ($rv['needed'] && !$rv['signed_at']
                && ec_can_sign_review($db, $r, (string)$rv['unit_key'], $uid, (bool)$P['canAdmin']))
                $r['my_review_units'][] = $rv['unit_key'];
        }
    }
    return $r;
}

function ec_status_label(array $r): string
{
    $s = (string)$r['status'];
    if ($s === 'DRAFT')    return '草稿';
    if ($s === 'CLOSED')   return '已結案';
    if ($s === 'REJECTED') return '已退回（' . (EC_STAGES[(string)$r['reject_stage']]['label'] ?? '') . '）';
    return '待簽核：' . (EC_STAGES[$s]['label'] ?? $s);
}

/* ---------------------------------------------------------------- 動作 */

try {
    if ($action === 'csrf_token') {
        jout(['csrf' => (string)$_SESSION['ec_csrf']]);
    }

    /** 頁面開場資料：權限、設定、選項字典、AS 綁定 */
    if ($action === 'bootstrap') {
        if (!$P['canView'] && !$P['canEdit']) jerr('沒有檢閱權限', 403);
        $doc = eg_asdoc_get($db, EC_ASDOC_MODULE);
        jout([
            'csrf'  => (string)$_SESSION['ec_csrf'],
            'me'    => ['uid' => $uid, 'name' => $uname],
            'perms' => $P,
            'dict'  => [
                'change_types'   => EC_CHANGE_TYPES,
                'design_results' => EC_DESIGN_RESULTS,
                'old_stock'      => EC_OLD_STOCK,
                'verdicts'       => EC_VERDICTS,
                'stages'         => array_map(fn($s) => $s['label'], EC_STAGES),
                'review_units'   => EC_REVIEW_UNITS,
                'sign_sources'   => EC_SIGN_SOURCES,
            ],
            'settings' => $P['canAdmin'] ? ec_settings($db) : null,
            'as_doc'   => $doc ? ['id' => (int)$doc['id'], 'doc_no' => $doc['doc_no'], 'doc_name' => $doc['doc_name']] : null,
        ]);
    }

    if ($action === 'list') {
        if (!$P['canView'] && !$P['canEdit']) jerr('沒有檢閱權限', 403);
        $kw     = trim((string)($_GET['keyword'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $mine   = (int)($_GET['mine'] ?? 0);
        $todo   = (int)($_GET['todo'] ?? 0);
        $sql  = "SELECT * FROM eng_change WHERE 1=1";
        $args = [];
        if ($kw !== '') {
            // 全表搜尋：畫面上看得到的欄位都掃（ai-rules/08 全表搜尋鐵則，用 LIKE 不用 FULLTEXT）
            $cols = ['doc_no', 'part_no', 'customer_name', 'apply_dept_name', 'applicant_name',
                     'change_reason', 'design_note', 'verdict_note', 'verdict_other'];
            $ors = [];
            foreach ($cols as $c) { $ors[] = "`$c` LIKE ?"; $args[] = '%' . $kw . '%'; }
            $sql .= ' AND (' . implode(' OR ', $ors) . ')';
        }
        if ($status !== '') { $sql .= " AND status=?"; $args[] = $status; }
        if ($mine)          { $sql .= " AND (applicant_id=? OR created_by=?)"; $args[] = $uid; $args[] = $uid; }
        $sql .= " ORDER BY ec_id DESC LIMIT 300";
        $st = $db->prepare($sql); $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!ec_can_see($db, $r, $P, $uid)) continue;
            $d = ec_decorate($db, $r, $P, $uid);
            if ($todo && !$d['can_sign'] && !$d['my_review_units']) continue;   // 只看「待我簽」
            $rows[] = $d;
        }
        jout(['rows' => $rows]);
    }

    if ($action === 'get') {
        $ecId = (int)($_GET['id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_see($db, $r, $P, $uid)) jerr('沒有這張申請單的檢視權限', 403);
        $r = ec_decorate($db, $r, $P, $uid);
        // 各關卡目前解析到誰要簽（畫面上要看得到「現在輪到誰」）
        $signers = [];
        foreach (EC_STAGES as $k => $def) {
            if ($def['setting'] === '') continue;
            $s = ec_stage_signer($db, $r, $k);
            $signers[$k] = ['name' => $s['name'], 'for_name' => $s['for_name']];
        }
        $reviews = ec_review_rows($db, $ecId);
        foreach ($reviews as &$rv) {
            $s = ec_review_signer($db, $r, (string)$rv['unit_key']);
            $rv['expect_name'] = $s['name'];
            $rv['can_sign'] = ((string)$r['status'] === 'REVIEW' && $rv['needed'] && !$rv['signed_at']
                               && ec_can_sign_review($db, $r, (string)$rv['unit_key'], $uid, (bool)$P['canAdmin'])) ? 1 : 0;
        }
        unset($rv);
        jout(['row' => $r, 'signers' => $signers, 'reviews' => $reviews,
              'approvals' => ec_approval_history($db, $ecId)]);
    }

    if ($action === 'create') {
        if (!$P['canEdit']) jerr('沒有開立申請單的權限', 403);
        $p = ec_input_row();
        // 一般使用者只能以自己的名義開單；管理員可代開（補歷史紙本）
        if (!$P['canAdmin']) {
            $p['applicant_id'] = $uid;
            $p['applicant_name'] = $uname;
        }
        $ecId = ec_create($db, $p, $uid, $uname);
        $r = ec_row($db, $ecId);
        jout(['ec_id' => $ecId, 'doc_no' => (string)$r['doc_no']]);
    }

    if ($action === 'save') {
        $ecId = (int)($_POST['ec_id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_edit_row($r, $P, $uid))
            jerr('這張申請單已經送出，或不是你開立的，不能修改', 403);
        $p = ec_input_row();
        if (!$P['canAdmin']) { $p['applicant_id'] = (int)$r['applicant_id']; $p['applicant_name'] = (string)$r['applicant_name']; }
        $db->prepare("UPDATE eng_change SET apply_date=?, customer_id=?, customer_name=?, d_id=?, part_no=?,
                        apply_dept_id=?, apply_dept_name=?, applicant_id=?, applicant_name=?,
                        change_type=?, change_reason=?, updated_by=?, updated_at=NOW() WHERE ec_id=?")
           ->execute([$p['apply_date'], ($p['customer_id'] !== '' ? $p['customer_id'] : null), $p['customer_name'], $p['d_id'] ?: null, $p['part_no'],
                      $p['apply_dept_id'] ?: null, $p['apply_dept_name'], $p['applicant_id'] ?: null, $p['applicant_name'],
                      $p['change_type'], $p['change_reason'], $uid, $ecId]);
        // 日期改了就重編文件編號（前八碼永遠＝表單上的日期）
        ec_sync_doc_no($db, $ecId);
        $r2 = ec_row($db, $ecId);
        jout(['doc_no' => (string)$r2['doc_no'], 'errors' => ec_validate($db, $r2)]);
    }

    if ($action === 'submit') {
        $ecId = (int)($_POST['ec_id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_edit_row($r, $P, $uid)) jerr('不是你開立的申請單，不能送出', 403);
        jout(ec_submit($db, $ecId, $uid, $uname));
    }

    if ($action === 'resubmit') {
        $ecId = (int)($_POST['ec_id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_edit_row($r, $P, $uid)) jerr('不是你開立的申請單，不能重新送出', 403);
        jout(ec_resubmit($db, $ecId, $uid, $uname));
    }

    if ($action === 'set_review_units') {
        $ecId  = (int)($_POST['ec_id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        // 由技術課那一關決定要找哪些單位會審（紙本：↓以下僅技術課判定需會審才填寫↓）
        if (!ec_can_sign_stage($db, $r, 'TD', $uid, (bool)$P['canAdmin'])) jerr('只有技術課那一關可以勾選會審單位', 403);
        if ((string)$r['status'] !== 'TD') jerr('這張單目前不在技術課關卡', 400);
        $units = json_decode((string)($_POST['units'] ?? '[]'), true);
        ec_set_review_units($db, $ecId, is_array($units) ? $units : []);
        jout(['reviews' => ec_review_rows($db, $ecId)]);
    }

    if ($action === 'sign_stage') {
        $ecId  = (int)($_POST['ec_id'] ?? 0);
        $stage = trim((string)($_POST['stage'] ?? ''));
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!isset(EC_STAGES[$stage]) || $stage === 'REVIEW') jerr('無效的關卡', 400);
        if (!ec_can_sign_stage($db, $r, $stage, $uid, (bool)$P['canAdmin'])) jerr('這一關不是由你簽核', 403);
        // 只收這一關自己可以填的欄位（白名單在 lib，直打 API 也繞不過去＝鐵律8）
        $fields = [];
        foreach (ec_stage_editable_fields($stage) as $f)
            if (isset($_POST[$f])) $fields[$f] = $_POST[$f];
        jout(ec_sign_stage($db, $ecId, $stage, $uid, $uname, $fields));
    }

    if ($action === 'reject') {
        $ecId  = (int)($_POST['ec_id'] ?? 0);
        $stage = trim((string)($_POST['stage'] ?? ''));
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!isset(EC_STAGES[$stage])) jerr('無效的關卡', 400);
        $ok = ($stage === 'REVIEW')
            ? ($P['canAdmin'] || (bool)ec_decorate($db, $r, $P, $uid)['my_review_units'])
            : ec_can_sign_stage($db, $r, $stage, $uid, (bool)$P['canAdmin']);
        if (!$ok) jerr('這一關不是由你簽核', 403);
        jout(ec_reject($db, $ecId, $stage, $uid, $uname, (string)($_POST['reason'] ?? '')));
    }

    if ($action === 'sign_review') {
        $ecId = (int)($_POST['ec_id'] ?? 0);
        $unit = trim((string)($_POST['unit_key'] ?? ''));
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_sign_review($db, $r, $unit, $uid, (bool)$P['canAdmin'])) jerr('這個會審單位不是由你簽核', 403);
        $checks = json_decode((string)($_POST['checks'] ?? '{}'), true);
        $extras = json_decode((string)($_POST['extras'] ?? '{}'), true);
        jout(ec_sign_review($db, $ecId, $unit, $uid, $uname, [
            'checks'  => is_array($checks) ? $checks : [],
            'extras'  => is_array($extras) ? $extras : [],
            'opinion' => (string)($_POST['opinion'] ?? ''),
        ]));
    }

    if ($action === 'delete') {
        if (!$P['canAdmin']) jerr('只有管理員可以刪除申請單', 403);
        $ecId = (int)($_POST['ec_id'] ?? 0);
        if (!ec_row($db, $ecId)) jerr('查無此申請單', 404);
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM eng_change_review WHERE ec_id=?")->execute([$ecId]);
            $db->prepare("DELETE FROM eng_change WHERE ec_id=?")->execute([$ecId]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        ec_close_notices($db, $ecId);
        jout(['deleted' => $ecId]);
    }

    /** 列印所需資料（表頭公司全名、AS 編號與版次、各格簽章人與當時職稱） */
    if ($action === 'print_meta') {
        $ecId = (int)($_GET['id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_see($db, $r, $P, $uid)) jerr('沒有這張申請單的檢視權限', 403);
        jout(['row' => $r, 'reviews' => ec_review_rows($db, $ecId), 'meta' => ec_print_meta($db, $r)]);
    }

    /** 列印紀錄（ai-rules/23 鐵則：會列印的頁面一律留紀錄，且一次列印只記一筆） */
    if ($action === 'log_print') {
        $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
        $n = 0;
        foreach ((is_array($ids) ? $ids : []) as $id) {
            $r = ec_row($db, (int)$id);
            if (!$r || !ec_can_see($db, $r, $P, $uid)) continue;
            eg_print_log_add($db, [
                'source'    => '工程變更申請單',
                'doc_kind'  => 'form',
                'ref_table' => 'eng_change',
                'ref_id'    => (string)$r['ec_id'],
                'doc_name'  => '工程變更申請單 ' . (string)$r['doc_no'],
                'part_no'   => (string)$r['part_no'],
                'user_id'   => $uid,
                'user_name' => $uname,
            ]);
            $n++;
        }
        jout(['logged' => $n]);
    }

    /* -------- 管理員設定 -------- */

    if ($action === 'save_setting') {
        if (!$P['canAdmin']) jerr('只有管理員可以變更設定', 403);
        foreach (EC_SETTING_KEYS as $k)
            if (isset($_POST[$k])) ec_save_setting($db, $k, $_POST[$k]);
        jout(['settings' => ec_settings($db)]);
    }

    /** AS 文件清單（給共用挑選器 eg_asdoc_picker.js 用；禁止純下拉＝ai-rules/16 第一之三節） */
    if ($action === 'asdoc_list') {
        if (!$P['canAdmin']) jerr('只有管理員可以變更 AS 文件綁定', 403);
        jout(['docs' => eg_asdoc_list($db), 'current' => eg_asdoc_id($db, EC_ASDOC_MODULE)]);
    }

    if ($action === 'save_asdoc') {
        if (!$P['canAdmin']) jerr('只有管理員可以變更 AS 文件綁定', 403);
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($docId > 0) {
            $st = $db->prepare("SELECT 1 FROM as_document WHERE id=? AND is_deleted=0");
            $st->execute([$docId]);
            if (!$st->fetchColumn()) jerr('這份 AS 文件不存在或已刪除', 400);
        }
        eg_asdoc_save($db, EC_ASDOC_MODULE, $docId, $uname);
        $doc = eg_asdoc_get($db, EC_ASDOC_MODULE);
        jout(['as_doc' => $doc ? ['id' => (int)$doc['id'], 'doc_no' => $doc['doc_no'], 'doc_name' => $doc['doc_name']] : null]);
    }

    /* -------- 選單資料 -------- */

    /** 料號查詢（打字篩選用；選了料號自動帶出客戶） */
    if ($action === 'parts') {
        $kw = trim((string)($_GET['kw'] ?? ''));
        if ($kw === '') jout(['rows' => []]);
        $st = $db->prepare("SELECT s.d_id, s.D_Setting_Id AS part_no, s.Customer_Id AS customer_id,
                                   COALESCE(c.customer,'') AS customer_name
                              FROM d_setting s LEFT JOIN customer_list c ON c.customer_id=s.Customer_Id
                             WHERE s.D_Setting_Id LIKE ? OR c.customer LIKE ?
                             ORDER BY s.D_Setting_Id LIMIT 50");
        $st->execute(['%' . $kw . '%', '%' . $kw . '%']);
        jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /** 確認庫存自動帶入：這個料號目前的庫存數量與已完工待入庫數量（倉管那一關用） */
    if ($action === 'stock_snapshot') {
        $ecId = (int)($_GET['id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_see($db, $r, $P, $uid)) jerr('沒有這張申請單的檢視權限', 403);
        jout(['snap' => ec_stock_snapshot($db, (int)$r['d_id'], (string)$r['part_no'])]);
    }

    /** 部門清單（設定裡挑「指定人員」時先選課室用） */
    if ($action === 'departments') {
        if (!$P['canAdmin']) jerr('只有管理員可以變更設定', 403);
        $rows = [];
        try {
            $rows = $db->query("SELECT id, name FROM department ORDER BY COALESCE(sort_order,999), id")
                       ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        jout(['rows' => $rows]);
    }

    /** 某課室（含子部門）底下的人員；設定裡「指定人員（複選）」的候選清單。
     *  一律走 people_lib 的 eg_people_list()（人員列表鐵則：只列未離職、標長期請假、依職稱排序）。 */
    if ($action === 'dept_people') {
        if (!$P['canAdmin']) jerr('只有管理員可以變更設定', 403);
        $deptId = (int)($_GET['dept_id'] ?? 0);
        $ids = $deptId > 0 ? eg_dept_subtree_ids($db, $deptId) : [];
        $rows = eg_people_list($db, $ids ? ['dept_ids' => $ids] : []);
        jout(['rows' => $rows, 'dept_ids' => $ids]);
    }

    /** 申請人候選：依申請日期回推當時在職的人與當時的部門職稱（ai-rules/22） */
    if ($action === 'people') {
        $date = trim((string)($_GET['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = ec_db_now($db)['d'];
        jout(['rows' => ec_people_posts_asof($db, $date), 'date' => $date]);
    }

    jerr('無效的操作：' . $action, 400);

} catch (Throwable $e) {
    jerr($e->getMessage(), 400);
}

/** 表頭欄位的輸入整理（長度上限一律後端自己擋，直打 API 繞不過去） */
function ec_input_row(): array
{
    $cut = fn($v, $n) => mb_substr(trim((string)$v), 0, $n, 'UTF-8');
    $date = trim((string)($_POST['apply_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    return [
        'apply_date'      => $date,
        'customer_id'     => $cut($_POST['customer_id'] ?? '', 20),   // 字串（例 Z2001A），不可轉 int
        'customer_name'   => $cut($_POST['customer_name'] ?? '', 120),
        'd_id'            => (int)($_POST['d_id'] ?? 0),
        'part_no'         => $cut($_POST['part_no'] ?? '', 80),
        'apply_dept_id'   => (int)($_POST['apply_dept_id'] ?? 0),
        'apply_dept_name' => $cut($_POST['apply_dept_name'] ?? '', 60),
        'applicant_id'    => (int)($_POST['applicant_id'] ?? 0),
        'applicant_name'  => $cut($_POST['applicant_name'] ?? '', 60),
        'change_type'     => $cut($_POST['change_type'] ?? '', 20),
        'change_reason'   => $cut($_POST['change_reason'] ?? '', 2000),
    ];
}

/** 簽核歷程（讀全站共用的 approval_record；意見一律過遮蔽再輸出＝ai-rules/23） */
function ec_approval_history(PDO $db, int $ecId): array
{
    $out = [];
    try {
        $st = $db->prepare("SELECT level, status, submitted_by_name, submitted_at,
                                   approver_name, decided_at, note
                              FROM approval_record WHERE module=? AND entity_id=? ORDER BY id ASC");
        $st->execute([EC_APPROVAL_MODULE, $ecId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lv = (string)$r['level'];
            $label = strpos($lv, 'REVIEW:') === 0
                   ? ('會審－' . (EC_REVIEW_UNITS[substr($lv, 7)]['label'] ?? substr($lv, 7)))
                   : (EC_STAGES[$lv]['label'] ?? $lv);
            $note = (string)($r['note'] ?? '');
            if (function_exists('eg_sign_note_public')) $note = (string)eg_sign_note_public($note);
            $out[] = ['level' => $lv, 'label' => $label, 'status' => (string)$r['status'],
                      'submitted_by' => (string)$r['submitted_by_name'], 'submitted_at' => (string)$r['submitted_at'],
                      'approved_by' => (string)$r['approver_name'], 'approved_at' => (string)$r['decided_at'],
                      'note' => $note];
        }
    } catch (Throwable $e) {}
    return $out;
}
