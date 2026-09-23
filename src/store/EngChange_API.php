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
          'sign_review', 'save_stage_fields', 'delete', 'save_setting', 'save_asdoc', 'log_print',
          'attach_save', 'attach_del', 'bulk_sign', 'fix_sign'];
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

/**
 * 這個人現在能不能改某一段的附件。
 * 申請內容那一段跟著「表頭能不能改」（草稿／被退回時申請人可改，管理員隨時可改）；
 * 設計分析那一段跟著技術課那一關的填寫權（含提早填寫），已經簽過就不能再動。
 */
function ec_attach_can_edit(PDO $db, array $r, string $slot, array $P, int $uid): bool
{
    if (!isset(EC_ATTACH_SLOTS[$slot])) return false;
    if ($slot === 'apply')  return ec_can_edit_row($r, $P, $uid);
    if ($slot === 'design') return ec_can_prefill_stage($db, $r, 'TD', $uid, (bool)$P['canAdmin']);
    return false;
}

/**
 * 這個人能不能刪這一張單。
 * 管理員：任何一張都可以。
 * 一般使用者（使用者要求 2026-09-23）：**只能刪自己建立、而且還沒送出（草稿）的那一張**——
 * 送出之後已經有簽核事實與通知在外面跑，刪掉會讓別人手上的待辦指向不存在的單。
 */
function ec_can_delete_row(array $r, array $P, int $uid): bool
{
    if ($P['canAdmin']) return true;
    if ((string)$r['status'] !== 'DRAFT') return false;
    return (int)($r['created_by'] ?? 0) === $uid || (int)($r['applicant_id'] ?? 0) === $uid;
}

/** 把一列補上畫面要用的衍生欄位 */
function ec_decorate(PDO $db, array $r, array $P, int $uid): array
{
    $stage = ec_current_stage($r);
    $r['stage']        = $stage;
    $r['stage_label']  = $stage !== '' ? (EC_STAGES[$stage]['label'] ?? $stage) : '';
    $r['status_label'] = ec_status_label($r);
    $r['can_edit']     = ec_can_edit_row($r, $P, $uid) ? 1 : 0;
    // 日期是不是鎖住了（送出後即使是管理員也不給改，見 ec_date_locked 說明）
    $r['date_locked']  = ec_date_locked($r) ? 1 : 0;
    $r['can_delete']   = ec_can_delete_row($r, $P, $uid) ? 1 : 0;
    $r['can_sign']     = ($stage !== '' && $stage !== 'REVIEW'
                          && ec_can_sign_stage($db, $r, $stage, $uid, (bool)$P['canAdmin'])) ? 1 : 0;
    // 使用者本身就在該課室時，可以提早把自己那一段填好（填但不簽）
    // ★這裡要掃**全部有欄位可填的關卡**，不能只掃 EC_STAGE_DEPT（那份只有倉管與技術課）——
    //   漏掉「核准」的話，管理員在單子走到核准之前填不了核示結果，
    //   於是「一次代簽全部」永遠會卡在「請選擇核示結果」而完全用不起來（實測踩到）。
    $r['prefill'] = [];
    foreach (array_keys(EC_STAGES) as $st)
        if (ec_stage_editable_fields($st) && ec_can_prefill_stage($db, $r, $st, $uid, (bool)$P['canAdmin']))
            $r['prefill'][] = $st;
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
                'attach_slots'   => EC_ATTACH_SLOTS,
                'attach_rules'   => EC_ATTACH_RULES,
            ],
            // 附件提示文字（管理員可改；一般使用者也要拿得到，否則畫面上沒有說明）
            'attach_hint' => ['apply'  => (string)ec_settings($db)['ec_attach_hint_apply'],
                              'design' => (string)ec_settings($db)['ec_attach_hint_design']],
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
        // 各關卡目前解析到誰要簽（畫面上要看得到「現在輪到誰」）。
        // 使用者要求 2026-09-23：連**部門與職稱**一起顯示，而且多人可簽的關卡要把人全部列出來，
        // 不能只印名單第一位（否則看起來像只有經理能簽、課長其實也能簽卻看不到）。
        $signers = [];
        foreach (EC_STAGES as $k => $def) {
            if ($def['setting'] === '') continue;
            $pool = ec_stage_signer_pool($db, $r, $k);
            $list = [];
            foreach ($pool as $p) {
                // 名單自己帶了部門職稱（sup_above：他是以哪個單位的身分入選）就用那一份；
                // 沒帶的來源才回頭解析（兼任者會取職級最高那一筆）
                $idt = ['dept_name' => (string)($p['dept_name'] ?? ''), 'position_name' => (string)($p['position_name'] ?? '')];
                if ($idt['dept_name'] === '' && $idt['position_name'] === '')
                    $idt = ec_user_identity_asof($db, (int)$p['id'], (string)$r['apply_date']);
                $list[] = ['id' => (int)$p['id'], 'name' => (string)$p['name'],
                           'dept' => (string)$idt['dept_name'], 'position' => (string)$idt['position_name'],
                           'for_name' => (string)$p['for_name'],
                           'label' => trim(($idt['dept_name'] !== '' ? $idt['dept_name'] . '　' : '')
                                    . ($idt['position_name'] !== '' ? $idt['position_name'] . '　' : '')
                                    . (string)$p['name'])
                                    . ((string)$p['for_name'] !== '' ? '（代理 ' . (string)$p['for_name'] . '）' : '')];
            }
            $signers[$k] = ['name' => $pool ? (string)$pool[0]['name'] : '',
                            'for_name' => $pool ? (string)$pool[0]['for_name'] : '',
                            'list' => $list];
        }
        // 申請人在本單日期當時的部門／職稱（畫面上「申請職務」要印得出職稱；
        // 一般使用者不能查別人的職務，但這張單他本來就看得到，所以由後端直接給）
        $aidt = ec_user_identity_asof($db, (int)$r['applicant_id'], (string)$r['apply_date'],
                                      (int)$r['apply_dept_id']);
        $r['applicant_post_label'] = trim(((string)$aidt['dept_name'] !== '' ? (string)$aidt['dept_name']
                                            : (string)$r['apply_dept_name'])
                                   . '　' . (string)$aidt['position_name']);
        $reviews = ec_review_rows($db, $ecId);
        foreach ($reviews as &$rv) {
            $s = ec_review_signer($db, $r, (string)$rv['unit_key']);
            $rv['expect_name'] = $s['name'];
            $rv['can_sign'] = ((string)$r['status'] === 'REVIEW' && $rv['needed'] && !$rv['signed_at']
                               && ec_can_sign_review($db, $r, (string)$rv['unit_key'], $uid, (bool)$P['canAdmin'])) ? 1 : 0;
        }
        unset($rv);
        // 附件：已選的（含編號）＋各段可挑的標籤＋這個變更方式的附件規則
        $attachCats = [];
        foreach (array_keys(EC_ATTACH_SLOTS) as $slot) $attachCats[$slot] = ec_attach_allowed_cats($db, $slot);
        // 各簽章格目前蓋的是誰（畫面的「簽核紀錄」直接用這一份，不必從 approval_record 湊，
        // 申請人那一格本來就沒有 approval_record）。候選名單是重運算，這裡不帶，
        // 要代簽時才另外打 sign_slots。
        $slotState = [];
        foreach (ec_sign_slots($db, $r) as $s) {
            $st = ec_slot_state($db, $r, (string)$s['key']);
            $slotState[] = ['key' => $s['key'], 'label' => $s['label'], 'kind' => $s['kind'],
                            'signed' => $st['signed'], 'signer_name' => $st['name'],
                            'signed_at' => $st['at'],
                            // proxy_name 只給管理員看（畫面上的橘色小籤），一般使用者拿不到
                            'proxy_name' => $P['canAdmin'] ? $st['proxy_name'] : '',
                            'can_fix' => ($P['canAdmin'] && $st['signed'] && $st['proxy_name'] !== '') ? 1 : 0];
        }
        jout(['row' => $r, 'signers' => $signers, 'reviews' => $reviews,
              'sign_slots' => $slotState,
              'attachments' => ec_attach_rows($db, $ecId),
              'attach_cats' => $attachCats,
              'attach_rule' => ec_attach_rule($db, (string)$r['change_type']),
              'attach_rules_all' => array_combine(
                    array_keys(EC_CHANGE_TYPES),
                    array_map(fn($ct) => ec_attach_rule($db, $ct), array_keys(EC_CHANGE_TYPES))),
              'approvals' => ec_approval_history($db, $ecId)]);
    }

    if ($action === 'create') {
        if (!$P['canEdit']) jerr('沒有開立申請單的權限', 403);
        $p = ec_input_row();
        // 一般使用者只能以自己的名義開單（使用者要求 2026-08-25：申請人固定是開單的人、
        // 除管理員外不可更改）；管理員可代開（補歷史紙本）
        if (!$P['canAdmin']) {
            $p['applicant_id'] = $uid;
            $p['applicant_name'] = $uname;
        }
        $p = ec_fix_applicant_post($db, $p);
        $ecId = ec_create($db, $p, $uid, $uname);
        $r = ec_row($db, $ecId);
        jout(['ec_id' => $ecId, 'doc_no' => (string)$r['doc_no']]);
    }

    /**
     * 單號預覽（使用者要求 2026-09-23：日期一改，單號要跟著自動變）。
     * 純讀取、不寫入任何東西——**草稿階段的號碼只是預覽**，真正佔號在送出當下
     * 才由 ec_lock_doc_no_on_submit() 決定（見 ec_next_doc_no 的說明）。
     * 日期已經鎖住（送出後）時直接回目前的正式編號，不重算。
     */
    if ($action === 'doc_no_preview') {
        $ecId = (int)($_GET['id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_see($db, $r, $P, $uid)) jerr('沒有這張申請單的檢視權限', 403);
        if (ec_date_locked($r)) jout(['doc_no' => (string)$r['doc_no'], 'locked' => 1]);
        $date = trim((string)($_GET['apply_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = (string)$r['apply_date'];
        jout(['doc_no' => ec_next_doc_no($db, $date, $ecId), 'locked' => 0]);
    }

    if ($action === 'save') {
        $ecId = (int)($_POST['ec_id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_edit_row($r, $P, $uid))
            jerr('這張申請單已經送出，或不是你開立的，不能修改', 403);
        $p = ec_input_row();
        // 非管理員不可改申請人（連自己開的單也不行改成別人）
        if (!$P['canAdmin']) { $p['applicant_id'] = $uid; $p['applicant_name'] = $uname; }
        $p = ec_fix_applicant_post($db, $p);
        // 日期只有草稿／被退回時可以改，送出後一律鎖住（使用者要求 2026-09-23）——
        // 這一道**連管理員都不能繞過**：不採信前端送來的日期，直接沿用資料庫現有值
        $dateLocked = ec_date_locked($r);
        if ($dateLocked) $p['apply_date'] = (string)$r['apply_date'];
        $db->prepare("UPDATE eng_change SET apply_date=?, customer_id=?, customer_name=?, d_id=?, part_no=?,
                        apply_dept_id=?, apply_dept_name=?, applicant_id=?, applicant_name=?,
                        change_type=?, change_reason=?, updated_by=?, updated_at=NOW() WHERE ec_id=?")
           ->execute([$p['apply_date'], ($p['customer_id'] !== '' ? $p['customer_id'] : null), $p['customer_name'], $p['d_id'] ?: null, $p['part_no'],
                      $p['apply_dept_id'] ?: null, $p['apply_dept_name'], $p['applicant_id'] ?: null, $p['applicant_name'],
                      $p['change_type'], $p['change_reason'], $uid, $ecId]);
        // 日期改了就重編文件編號（前八碼永遠＝表單上的日期）；日期鎖住時不會變，不必重編
        if (!$dateLocked) ec_sync_doc_no($db, $ecId);
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
        // 由技術課決定要找哪些單位會審（紙本：↓以下僅技術課判定需會審才填寫↓）。
        // 技術課可以在單子走到自己這一關之前就先勾好（跟設計分析一起提早填），
        // 但「會審單位自己要填的內容」仍要等進入會審關卡才開放（見 sign_review）。
        if (!ec_can_prefill_stage($db, $r, 'TD', $uid, (bool)$P['canAdmin']))
            jerr('只有技術課可以勾選會審單位', 403);
        $units = json_decode((string)($_POST['units'] ?? '[]'), true);
        ec_set_review_units($db, $ecId, is_array($units) ? $units : []);
        jout(['reviews' => ec_review_rows($db, $ecId)]);
    }

    /** 提早填寫自己那一段（填但不簽）：例如技術課的人開單時順手把「設計分析」填好 */
    if ($action === 'save_stage_fields') {
        $ecId  = (int)($_POST['ec_id'] ?? 0);
        $stage = trim((string)($_POST['stage'] ?? ''));
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_prefill_stage($db, $r, $stage, $uid, (bool)$P['canAdmin']))
            jerr('你不是這一段的填寫人員，或這一關已經簽過了', 403);
        $fields = [];
        foreach (ec_stage_editable_fields($stage) as $f)
            if (isset($_POST[$f])) $fields[$f] = $_POST[$f];
        jout(ec_save_stage_fields($db, $ecId, $stage, $fields, $uid));
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
        // 管理員代簽時可以指定「代誰簽」（這一關有好幾位合格簽核人時）；
        // 非管理員送這個參數沒有作用——他本來就只能以自己的身分簽。
        $signAs = $P['canAdmin'] ? (int)($_POST['sign_as'] ?? 0) : 0;
        // 簽章時間也只有管理員代簽時可以自己指定（不可早於申請單日期，lib 會再擋一次）
        $signAt = $P['canAdmin'] ? trim((string)($_POST['sign_at'] ?? '')) : '';
        jout(ec_sign_stage($db, $ecId, $stage, $uid, $uname, $fields, $signAs, $signAt));
    }

    /* -------- 代簽（管理員）：一次代簽全部／事後更正某一格 -------- */

    /** 這張單有哪些簽章格、目前誰簽的、各格可以挑誰代簽（含選定日期當天的請假標註） */
    if ($action === 'sign_slots') {
        if (!$P['canAdmin']) jerr('只有管理員可以代簽', 403);
        $ecId = (int)($_GET['id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        $date = trim((string)($_GET['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = ec_db_now($db)['d'];
        $slots = [];
        foreach (ec_sign_slots($db, $r) as $s) {
            $st = ec_slot_state($db, $r, (string)$s['key']);
            $slots[] = [
                'key' => $s['key'], 'label' => $s['label'], 'kind' => $s['kind'],
                'signed' => $st['signed'], 'signer_id' => $st['user_id'], 'signer_name' => $st['name'],
                'signed_at' => $st['at'], 'proxy_name' => $st['proxy_name'],
                // 只有管理員代簽過的格子才可以事後更正（本人自己簽的不可以被改掉）
                'can_fix' => ($st['signed'] && $st['proxy_name'] !== '') ? 1 : 0,
                // 這一格對應的關卡還缺哪些必填欄位（代簽前就讓管理員看到，不要按下去才報）
                'missing' => ($s['kind'] === 'stage' && !$st['signed'])
                             ? array_values(ec_validate_stage($r, (string)$s['key'], $db, (int)$r['ec_id'])) : [],
                'candidates' => ec_slot_candidates($db, $r, (string)$s['key'], $date),
            ];
        }
        jout(['slots' => $slots, 'date' => $date, 'apply_date' => (string)$r['apply_date'],
              'today' => ec_db_now($db)['d']]);
    }

    if ($action === 'bulk_sign') {
        if (!$P['canAdmin']) jerr('只有管理員可以代簽', 403);
        $ecId  = (int)($_POST['ec_id'] ?? 0);
        $picks = json_decode((string)($_POST['picks'] ?? '{}'), true);
        // 各關卡自己的選項（庫存數量、設計分析結果、核示、需修改文件資料…）也可以在這裡一次填完
        // （使用者要求 2026-09-23），格式 {stage_key: {欄位:值}}；白名單在 lib 內再擋一次＝鐵律8
        $fields = json_decode((string)($_POST['fields'] ?? '{}'), true);
        jout(ec_bulk_proxy_sign($db, $ecId, is_array($picks) ? $picks : [],
                                trim((string)($_POST['date'] ?? '')), $uid, $uname,
                                is_array($fields) ? $fields : []));
    }

    if ($action === 'fix_sign') {
        if (!$P['canAdmin']) jerr('只有管理員可以更正簽章', 403);
        $ecId = (int)($_POST['ec_id'] ?? 0);
        jout(ec_fix_sign($db, $ecId, trim((string)($_POST['slot'] ?? '')),
                         (int)($_POST['user_id'] ?? 0), trim((string)($_POST['sign_at'] ?? '')), $uid, $uname));
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
        $ecId = (int)($_POST['ec_id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        // 管理員可刪任何一張；一般使用者只能刪自己建立且尚未送出的草稿（使用者要求 2026-09-23）
        if (!ec_can_delete_row($r, $P, $uid))
            jerr($P['canEdit'] ? '只能刪除自己建立、而且還沒送出的申請單' : '沒有刪除申請單的權限', 403);
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM eng_change_attach WHERE ec_id=?")->execute([$ecId]);
            $db->prepare("DELETE FROM eng_change_review WHERE ec_id=?")->execute([$ecId]);
            $db->prepare("DELETE FROM eng_change WHERE ec_id=?")->execute([$ecId]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        ec_close_notices($db, $ecId);
        jout(['deleted' => $ecId]);
    }

    /* -------- 附件（選定料號附件；只存參照，不複製檔案） -------- */

    /** 這張單的料號底下、某個標籤有哪些附件可以挑 */
    if ($action === 'attach_candidates') {
        $ecId = (int)($_GET['id'] ?? 0);
        $slot = trim((string)($_GET['slot'] ?? ''));
        $cat  = (int)($_GET['cat_id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_can_see($db, $r, $P, $uid)) jerr('沒有這張申請單的檢視權限', 403);
        if (!isset(EC_ATTACH_SLOTS[$slot])) jerr('無效的附件區塊', 400);
        $allow = array_column(ec_attach_allowed_cats($db, $slot), 'id');
        if (!in_array($cat, $allow, true)) jerr('這個附件標籤不在管理員允許的清單內', 400);
        if (!(int)$r['d_id']) jout(['rows' => [], 'no_part' => 1]);
        // 只列這張單的料號底下、掛了這個標籤、未刪除的附件（新到舊）
        $st = $db->prepare("SELECT pa.id, pa.filename, pa.original_name, pa.note, pa.revision,
                                   pa.issue_stamp_date, pa.uploaded_at,
                                   COALESCE(u.user_cname, pa.uploaded_by) AS uploaded_by
                              FROM part_attachments pa
                              LEFT JOIN `user` u ON u.id = pa.uploaded_by_id
                             WHERE pa.d_id = ? AND pa.deleted_at IS NULL
                               AND FIND_IN_SET(?, REPLACE(COALESCE(pa.category_ids,''), ' ', ''))
                             ORDER BY COALESCE(pa.issue_stamp_date, DATE(pa.uploaded_at)) DESC, pa.id DESC
                             LIMIT 200");
        $st->execute([(int)$r['d_id'], $cat]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$x) {
            $x['id']        = (int)$x['id'];
            $x['is_pdf']    = strtolower(pathinfo((string)$x['filename'], PATHINFO_EXTENSION)) === 'pdf' ? 1 : 0;
            $x['show_name'] = (string)($x['original_name'] ?: $x['filename']);
            $x['url']       = '../../src/store/Part_Attachment_API.php?action=download&id=' . $x['id'];
        }
        unset($x);
        jout(['rows' => $rows, 'need_page' => (int)EC_ATTACH_SLOTS[$slot]['need_page']]);
    }

    if ($action === 'attach_save') {
        $ecId = (int)($_POST['ec_id'] ?? 0);
        $slot = trim((string)($_POST['slot'] ?? ''));
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        if (!ec_attach_can_edit($db, $r, $slot, $P, $uid)) jerr('你現在不能修改這一段的附件', 403);
        jout(ec_attach_set($db, $ecId, $slot, (int)($_POST['cat_id'] ?? 0), (int)($_POST['attach_id'] ?? 0),
                           ((int)($_POST['page_no'] ?? 0)) ?: null, ((int)($_POST['page_count'] ?? 0)) ?: null, $uid));
    }

    if ($action === 'attach_del') {
        $ecId = (int)($_POST['ec_id'] ?? 0);
        $rowId = (int)($_POST['row_id'] ?? 0);
        $r = ec_row($db, $ecId);
        if (!$r) jerr('查無此申請單', 404);
        $slot = '';
        foreach (ec_attach_rows($db, $ecId) as $a) if ((int)$a['id'] === $rowId) { $slot = (string)$a['slot']; break; }
        if ($slot === '') jerr('查無這一筆附件', 404);
        if (!ec_attach_can_edit($db, $r, $slot, $P, $uid)) jerr('你現在不能修改這一段的附件', 403);
        jout(ec_attach_del($db, $ecId, $rowId));
    }

    /** 全部附件標籤（管理員設定「哪些標籤可以挑」用） */
    if ($action === 'attach_cat_list') {
        if (!$P['canAdmin']) jerr('只有管理員可以變更設定', 403);
        $out = [];
        foreach (ec_attach_cat_map($db) as $c) if ($c['is_active']) $out[] = ['id' => $c['id'], 'name' => $c['name']];
        jout(['rows' => $out]);
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

    /** 單一料號精確查詢：選定料號後用它把客戶帶出來。
     *  不能靠前端的關鍵字快取——從清單選取時 input 事件會先把快取清掉再發非同步查詢，
     *  緊接著的 change 事件查到的必定是空的（實測就是這個原因導致客戶沒帶出來）。 */
    if ($action === 'part_one') {
        $pn = trim((string)($_GET['part_no'] ?? ''));
        if ($pn === '') jout(['row' => null]);
        $st = $db->prepare("SELECT s.d_id, s.D_Setting_Id AS part_no, s.Customer_Id AS customer_id,
                                   COALESCE(c.customer,'') AS customer_name
                              FROM d_setting s LEFT JOIN customer_list c ON c.customer_id=s.Customer_Id
                             WHERE s.D_Setting_Id = ? ORDER BY s.d_id LIMIT 1");
        $st->execute([$pn]);
        jout(['row' => $st->fetch(PDO::FETCH_ASSOC) ?: null]);
    }

    /** 某人在指定日期當時的所有職務（含兼任）；申請人有兼任時要選用哪個身分申請 */
    if ($action === 'my_posts') {
        $date = trim((string)($_GET['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = ec_db_now($db)['d'];
        $who = (int)($_GET['user_id'] ?? 0) ?: $uid;
        // 只有管理員可以查別人的職務（一般人查別人＝準備冒名開單）
        if ($who !== $uid && !$P['canAdmin']) $who = $uid;
        jout(['rows' => ec_applicant_posts($db, $who, $date), 'user_id' => $who, 'date' => $date]);
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

/**
 * 申請人＋申請部門對不起來時，自動補成該人在該日期的主職（沒有主職就取第一個）。
 * 前端只列得出合法組合，這裡是防「直打 API 硬送別的部門」（鐵律8）。
 */
function ec_fix_applicant_post(PDO $db, array $p): array
{
    $uidA = (int)($p['applicant_id'] ?? 0);
    $date = (string)($p['apply_date'] ?? '');
    if ($uidA <= 0) return $p;
    if (ec_valid_applicant_post($db, $uidA, (int)($p['apply_dept_id'] ?? 0), $date)) return $p;
    $posts = ec_applicant_posts($db, $uidA, $date);
    if (!$posts) { $p['apply_dept_id'] = 0; $p['apply_dept_name'] = ''; return $p; }
    $pick = $posts[0];
    foreach ($posts as $x) if ($x['is_main']) { $pick = $x; break; }
    $p['apply_dept_id']   = $pick['dept_id'];
    $p['apply_dept_name'] = $pick['dept_name'];
    return $p;
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
