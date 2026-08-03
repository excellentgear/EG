<?php
// =============================================================================
// views/QC/inspection_entry_v2.php   品管檢驗表 2.0（新版填寫介面）
// -----------------------------------------------------------------------------
// 為什麼有這一頁：舊頁 inspection_combined_prototype.php 把「設定標準」與「填實測值」
// 擠在同一張 9 欄表格，現場反映
//   ① 欄位太多、每 PCS 的輸入格只有 52~70px 很難點
//   ② 要在標準欄與實測欄之間跳來跳去、容易填錯列
//   ③ 平板/戴手套不好操作
//   ④ 填值時看不到該尺寸的公差，要自己心算有沒有超差
// 本頁針對這四點重做輸入介面：
//   - 三種檢視：逐項（一次專注一個尺寸）／逐件（一次專注一件）／總表（格狀，快速鍵盤）
//   - 規格（標準/上限/下限）永遠大字顯示在輸入格正上方，輸入即時判定並顯示偏差量
//   - 大觸控格 + 內建數字鍵盤（平板）；桌機保留 Enter/方向鍵連續輸入
//   - 「檢驗項目與標準」的編輯從主流程分離（總表按「編輯標準」才展開設定欄）
//
// ★ 後端完全沿用舊頁 API（inspection_combined_prototype.php 的 AJAX action）：
//   同一個 session、同一組 CSRF token、同一套 RBAC 與後端重算判定邏輯。
//   → 本頁不重複實作任何寫入邏輯，舊頁保持原狀可隨時對照/回退。
//   設定類跳窗（量具/幾何公差/樣板/抽樣規則/權限/異常單相關）與異常單決策流程
//   直接沿用舊頁同一份 HTML+JS（載入時由 build 區塊原樣併入，避免兩份分歧）。
//
// 載入效能：GET 只輸出 HTML，不做任何 DB 查詢；所有資料走 AJAX。
// =============================================================================
include_once '../../src/common/_config.php';

// CSRF：與舊頁共用同一組 session token（後端比對的就是 $_SESSION['qc_csrf']）
if (empty($_SESSION['qc_csrf'])) { $_SESSION['qc_csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['qc_csrf'];

$isPopup = isset($_GET['popup']) && $_GET['popup'] == '1';

// 目前登入者中文名稱（底部固定列與列印的「檢驗人員」用）。
// 注意：登入時只寫入 $_SESSION['id'] 與 ['userName']，並沒有 user_cname；
// 側欄是自己去 user 表查名字，而 popup 模式不載入側欄 → 這裡直接以 id 查一次（PK 查詢，成本可忽略）。
$CURRENT_CNAME = trim((string)($_SESSION['user_cname'] ?? ''));
// 超級管理員（可用「完全刪除檢驗紀錄」）＝ id=1 ＋ user_status=9 ＋ 在職
$IS_SUPER = false;
if (!empty($_SESSION['id'])) {
    try {
        include_once '../../src/common/DBConnection.php';
        include_once '../../src/common/user_active_lib.php';
        $__pdo = (new DBConnection())->getPDO();
        $__st = $__pdo->prepare("SELECT user_cname, user_uname, user_status, state FROM user WHERE id=? LIMIT 1");
        $__st->execute([(int)$_SESSION['id']]);
        if ($__u = $__st->fetch(PDO::FETCH_ASSOC)) {
            if ($CURRENT_CNAME === '') $CURRENT_CNAME = trim((string)($__u['user_cname'] ?: $__u['user_uname']));
            $__blocked = (function_exists('eg_blocked_state_list') && $__u['state'] !== null
                          && in_array((int)$__u['state'], eg_blocked_state_list(), true));
            $IS_SUPER = ((int)$_SESSION['id'] === 1 && (int)$__u['user_status'] === 9 && !$__blocked);
        }
    } catch (Exception $e) { /* 取不到名字/權限不影響填寫 */ }
}
if ($CURRENT_CNAME === '') $CURRENT_CNAME = trim((string)($_SESSION['userName'] ?? $_SESSION['user_uname'] ?? ''));

// =============================================================================
// v2 專屬後端（只處理舊頁沒有的三件事，其餘一律仍打舊頁 API，不動舊檔）
//   ① 工程符號主檔 qc_symbol（Ø ± ▽ …）CRUD——僅管理員可增修刪
//   ② 抽驗數變更理由 qc_sample_change_log——記在該張檢驗表單下
//   ③ 無 BOM／無製程的臨時檢驗單（退貨、客訴、來料…）建立與查詢
// 以 v2action 參數區分，不與舊頁 action 衝突；CSRF 共用同一組 session token。
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['v2action'])) {
    header('Content-Type: application/json; charset=utf-8');
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/rbac.php';
    include_once '../../src/common/qc_inspection_lib.php'; // 共用：後端重算判定＋寫 qc_measurement

    $pdo = (new DBConnection())->getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $uid   = trim($_SESSION['id'] ?? $_SESSION['user_id'] ?? '');
    $feats = rbac_user_features($pdo, (int)$uid);
    $hasF  = function ($c) use ($feats) { return in_array('all', $feats, true) || in_array($c, $feats, true); };
    $isAdmin = in_array('all', $feats, true);
    $act = $_POST['v2action'];

    // 版本／表單型態：與舊頁同邏輯（此處為 v2 專用副本，避免 include 舊頁觸發它的 API 分支）
    $v2Version = function ($pdo, $d_id) {
        $s = $pdo->prepare("SELECT version_id FROM qc_inspection_version WHERE d_id=? AND is_active=1 ORDER BY version_id DESC LIMIT 1");
        $s->execute([$d_id]);
        if ($v = $s->fetchColumn()) return (int)$v;
        $pdo->prepare("INSERT INTO qc_inspection_version (d_id, version_label, source_type, is_active) VALUES (?, '目前使用中', 'REVISION', 1)")->execute([$d_id]);
        return (int)$pdo->lastInsertId();
    };
    $v2FormType = function ($pdo) {
        $v = $pdo->query("SELECT form_type_id FROM qc_inspection_form_type WHERE is_active=1 AND form_code='GENERAL' LIMIT 1")->fetchColumn();
        if ($v) return (int)$v;
        $v = $pdo->query("SELECT form_type_id FROM qc_inspection_form_type WHERE is_active=1 ORDER BY form_type_id ASC LIMIT 1")->fetchColumn();
        if ($v) return (int)$v;
        $pdo->exec("INSERT INTO qc_inspection_form_type (form_code, form_name, inspection_stage, is_active) VALUES ('GENERAL','一般檢','IPQC',1)");
        return (int)$pdo->lastInsertId();
    };

    try {
        $WRITE = ['sym_save', 'sym_delete', 'save_adhoc', 'log_sample_change', 'del_inspection',
                  'dwg_confirm', 'std_item_save', 'std_item_delete', 'std_version_activate', 'std_version_delete',
                  'print_cfg_save', 'tol_table_save', 'tol_table_delete'];
        if (in_array($act, $WRITE, true)) {
            $tok = $_POST['csrf'] ?? '';
            if (!is_string($tok) || $tok === '' || !hash_equals((string)($_SESSION['qc_csrf'] ?? ''), $tok)) {
                throw new Exception('連線憑證失效或不符，請重新整理頁面後再試 (CSRF)');
            }
        }

        // ---- ⓪ 列印設定：公司全名／AS 文件綁定／主管自動核可（ai-rules/16 列印文件標準）----
        //   公司全名與 AS 文件編號一律動態取、禁寫死；綁定只存 as_document.id
        $v2Set = function ($pdo, $k, $d = '') {
            try {
                $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
                $s->execute([$k]); $v = $s->fetchColumn();
                return ($v === false || $v === null || $v === '') ? $d : $v;
            } catch (Exception $e) { return $d; }
        };
        if ($act === 'print_cfg_get') {
            $canCfg = $isAdmin || $hasF('qc_print_approve_setting');
            $company = '';
            try {
                $r = $pdo->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($r) $company = trim((string)($r['customer_full'] ?: $r['customer']));
            } catch (Exception $e) {}
            $docId = (int)$v2Set($pdo, 'qc_inspection_as_doc_id', 0);
            $doc = ['id' => $docId, 'no' => '', 'name' => ''];
            if ($docId) {
                try {
                    $d = $pdo->prepare("SELECT doc_no, doc_name FROM as_document WHERE id=?");
                    $d->execute([$docId]);
                    if ($x = $d->fetch(PDO::FETCH_ASSOC)) { $doc['no'] = $x['doc_no']; $doc['name'] = $x['doc_name']; }
                } catch (Exception $e) {}
            }
            // 主管自動核可：勾選後主管審核欄自動蓋章（日期比照檢驗者的簽章日期認定方式）
            $auto  = (int)$v2Set($pdo, 'qc_auto_approve', 0);
            $appId = (int)$v2Set($pdo, 'qc_auto_approve_user', 0);
            $sign  = ['name' => '', 'deputy' => 0];
            if ($auto && $appId) {
                $signerId = $appId; $isDep = false;
                try {
                    include_once '../../src/common/delegate_lib.php';
                    // 代理一律走 delegate_lib（ai-rules/11）；本人今日有行程才會解析到代理人
                    $rs = eg_resolve_signer($pdo, $appId, ['applicant_id' => (int)$uid, 'flow_key' => 'qc_inspection', 'log' => false]);
                    $signerId = (int)$rs['signer_id']; $isDep = !empty($rs['is_delegated']);
                } catch (Throwable $e) {}
                try {
                    $n = $pdo->prepare("SELECT COALESCE(NULLIF(user_cname,''), user_uname) FROM user WHERE id=?");
                    $n->execute([$signerId]);
                    $sign = ['name' => (string)$n->fetchColumn(), 'deputy' => $isDep ? 1 : 0];
                } catch (Exception $e) {}
            }
            // 可選的核可主管＝「品管部門（含子部門）」底下所有主管，依職級由大到小
            //   品管部門是哪一個部門一律取自 views/admin/org_role_setting.php 的綁定（禁止各頁寫死部門 id）
            $people = []; $peopleHint = '';
            if ($canCfg) {
                try {
                    include_once '../../src/common/org_role_lib.php';
                    include_once '../../src/common/people_lib.php';
                    $qcDepts = eg_org_dept_ids($pdo, 'qc_dept');
                    if (!$qcDepts) {
                        $peopleHint = '尚未設定「品管部門」，請先到 系統管理 → 組織角色設定 綁定品管部門。';
                    } else {
                        $mgrs = eg_org_dept_managers($pdo, $qcDepts);       // 已依職級大到小
                        if (!$mgrs) {
                            $peopleHint = '品管部門底下沒有任何具主管職稱（經理／課長／組長…）的在職人員，請先於人事設定職稱與職級。';
                        } else {
                            $lv = []; foreach ($mgrs as $mg) $lv[(int)$mg['id']] = (int)$mg['level'];
                            // 人員列表一律走 people_lib（只列在職、標記長期請假、顯示職稱）
                            $rows = eg_people_list($pdo, ['dept_ids' => $qcDepts, 'user_ids' => array_keys($lv)]);
                            usort($rows, function ($a, $b) use ($lv) {
                                return ($lv[$a['id']] <=> $lv[$b['id']]) ?: ($a['position_sort'] <=> $b['position_sort']) ?: ($a['id'] <=> $b['id']);
                            });
                            foreach ($rows as $p) $people[] = ['id' => (int)$p['id'], 'name' => (string)($p['display'] ?? $p['user_cname'])];
                        }
                    }
                } catch (Throwable $e) { $peopleHint = '主管名單載入失敗：' . $e->getMessage(); }
            }
            echo json_encode(['success' => true, 'company' => $company, 'doc' => $doc,
                              'auto_approve' => $auto, 'approver_id' => $appId, 'approver' => $sign,
                              'can_cfg' => $canCfg, 'people' => $people, 'people_hint' => $peopleHint], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'print_cfg_save') {
            if (!$isAdmin && !$hasF('qc_print_approve_setting')) throw new Exception('您沒有「列印／主管自動核可設定」權限，請洽管理員於 設定 → 權限設定開通');
            $auto  = (int)($_POST['auto_approve'] ?? 0) ? 1 : 0;
            $appId = (int)($_POST['approver_id'] ?? 0);
            if ($auto && !$appId) throw new Exception('勾選自動核可時必須指定核可主管');
            $up = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by_id=VALUES(updated_by_id)");
            $pdo->beginTransaction();
            $up->execute(['qc_auto_approve', $auto, (int)$uid]);
            $up->execute(['qc_auto_approve_user', $appId, (int)$uid]);
            if (isset($_POST['as_doc_id'])) $up->execute(['qc_inspection_as_doc_id', (int)$_POST['as_doc_id'], (int)$uid]);
            $pdo->commit();
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- ① 工程符號主檔 ----
        if ($act === 'sym_list') {
            $rows = $pdo->query("SELECT sym_id, symbol, label, sort_order FROM qc_symbol WHERE is_active=1 ORDER BY sort_order ASC, sym_id ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'rows' => $rows, 'is_admin' => $isAdmin], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'sym_save') {
            if (!$isAdmin) throw new Exception('符號主檔僅管理員可維護');
            $sym = trim($_POST['symbol'] ?? '');
            $lab = trim($_POST['label'] ?? '');
            $srt = (int)($_POST['sort_order'] ?? 0);
            $id  = (int)($_POST['sym_id'] ?? 0);
            if ($sym === '') throw new Exception('符號不可空白');
            if (mb_strlen($sym) > 4) throw new Exception('符號請控制在 4 個字以內');
            if ($id) $pdo->prepare("UPDATE qc_symbol SET symbol=?, label=?, sort_order=? WHERE sym_id=?")->execute([$sym, $lab, $srt, $id]);
            else {
                if (!$srt) $srt = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+10 FROM qc_symbol")->fetchColumn();
                $pdo->prepare("INSERT INTO qc_symbol (symbol,label,sort_order,is_active) VALUES (?,?,?,1)")->execute([$sym, $lab, $srt]);
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'sym_delete') {
            if (!$isAdmin) throw new Exception('符號主檔僅管理員可維護');
            $pdo->prepare("DELETE FROM qc_symbol WHERE sym_id=?")->execute([(int)($_POST['sym_id'] ?? 0)]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- ② 抽驗數變更理由 ----
        if ($act === 'log_sample_change') {
            $qid = (int)($_POST['qc_form_id'] ?? 0);
            $rsn = trim($_POST['reason'] ?? '');
            if (!$qid || $rsn === '') throw new Exception('缺少檢驗單號或理由');
            $pdo->prepare("INSERT INTO qc_sample_change_log (qc_form_id, old_qty, new_qty, reason, changed_by, changed_at) VALUES (?,?,?,?,?,NOW())")
                ->execute([$qid, ($_POST['old_qty'] === '' ? null : (int)$_POST['old_qty']), (int)($_POST['new_qty'] ?? 0), mb_substr($rsn, 0, 255), $uid]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'get_sample_changes') {
            $s = $pdo->prepare("SELECT old_qty, new_qty, reason, changed_by, changed_at FROM qc_sample_change_log WHERE qc_form_id=? ORDER BY id ASC");
            $s->execute([(int)($_POST['qc_form_id'] ?? 0)]);
            echo json_encode(['success' => true, 'rows' => $s->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- ③ 無 BOM／無製程的臨時檢驗單 ----
        if ($act === 'part_search') {
            $kw = trim($_POST['keyword'] ?? '');
            $s  = $pdo->prepare("SELECT d_id, D_Setting_Id, Revision FROM d_setting WHERE D_Setting_Id LIKE ? ORDER BY D_Setting_Id ASC LIMIT 30");
            $s->execute(['%' . $kw . '%']);
            echo json_encode(['success' => true, 'rows' => $s->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'adhoc_list') {
            $s = $pdo->query(
                "SELECT f.qc_form_id, f.d_id, f.process_name, f.incoming_qty, f.sample_qty, f.ng_qty,
                        f.check_result, f.created_at, f.created_by, d.D_Setting_Id AS part_no
                 FROM qc_check_form f LEFT JOIN d_setting d ON d.d_id = f.d_id
                 WHERE f.bom_ing_fid = 0 AND f.status <> 'DRAFT'
                 ORDER BY f.qc_form_id DESC LIMIT 50");
            echo json_encode(['success' => true, 'rows' => $s->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'save_adhoc') {
            if (!$hasF('qc_fill_inspection')) throw new Exception('您沒有「填寫檢驗表單」權限');
            $d_id = (int)($_POST['d_id'] ?? 0);
            if ($d_id <= 0) throw new Exception('請先選擇料號');
            $process = trim($_POST['process_name'] ?? '');      // 檢驗類型（退貨/客訴/來料…），非 BOM 製程
            if ($process === '') $process = '臨時檢驗';
            $incoming = (int)($_POST['incoming_qty'] ?? 0);
            $sample   = (int)($_POST['sample_qty'] ?? 0);
            $remark   = trim($_POST['main_remark'] ?? '');
            $items    = json_decode($_POST['items'] ?? '[]', true); if (!is_array($items)) $items = [];
            $pcs      = json_decode($_POST['pcs_verdicts'] ?? '[]', true); if (!is_array($pcs)) $pcs = [];
            if (!$items) throw new Exception('請至少輸入一個檢驗項目');

            $version_id = $v2Version($pdo, $d_id);
            $form_type_id = $v2FormType($pdo);
            $pdo->beginTransaction();

            // 臨時檢驗單一律不改寫料號標準（update_std=false）：找得到同名標準就沿用，
            // 找不到才新建且 is_active=0，不污染該料號的正式檢驗標準。
            $findItem = $pdo->prepare("SELECT item_id FROM qc_inspection_item
                 WHERE version_id=? AND form_type_id=? AND (process_name <=> ?) AND item_name=? ORDER BY item_id DESC LIMIT 1");
            $insItem = $pdo->prepare("INSERT INTO qc_inspection_item
                 (version_id, form_type_id, process_name, item_code, item_name, standard_text,
                  min_value, max_value, plus_tolerance, minus_tolerance, result_type, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, 0)");
            $itemIds = [];
            foreach ($items as $idx => $it) {
                $name = trim($it['name'] ?? '');
                if ($name === '') { $itemIds[$idx] = null; continue; }
                $findItem->execute([$version_id, $form_type_id, $process, $name]);
                $iid = $findItem->fetchColumn();
                if (!$iid) {
                    $insItem->execute([$version_id, $form_type_id, $process, (string)($idx + 1), $name, ($it['std'] ?? ''),
                        (($it['up'] ?? '') !== '' ? $it['up'] : null), (($it['lo'] ?? '') !== '' ? $it['lo'] : null),
                        (($it['type'] ?? 'NUM') === 'OKNG' ? 'OKNG' : 'NUMERIC'), $idx + 1]);
                    $iid = (int)$pdo->lastInsertId();
                }
                $itemIds[$idx] = (int)$iid;
            }

            // bom_ing_fid=0 代表「非 BOM 來源」的臨時檢驗單
            $pdo->prepare("INSERT INTO qc_check_form
                 (bom_ing_fid, d_id, version_id, form_type_id, process_name, batch_no, round_no,
                  incoming_qty, sample_qty, ng_qty, check_result, status, main_remark, pcs_verdicts, check_date, created_by, created_at)
                 VALUES (0, ?, ?, ?, ?, 1, 1, ?, ?, 0, 'OK', 'SUBMITTED', ?, ?, NOW(), ?, NOW())")
                ->execute([$d_id, $version_id, (string)$form_type_id, $process, $incoming, $sample, $remark,
                           json_encode($pcs, JSON_UNESCAPED_UNICODE), $uid]);
            $qc_form_id = (int)$pdo->lastInsertId();

            $tot = qc_persist_readings($pdo, $qc_form_id, $items, $itemIds, $pcs, $uid);
            $pdo->prepare("UPDATE qc_check_form SET ng_qty=?, check_result=? WHERE qc_form_id=?")
                ->execute([$tot['ng_qty'], $tot['check_result'], $qc_form_id]);
            $pdo->commit();

            echo json_encode(['success' => true, 'qc_form_id' => $qc_form_id, 'summary' => [
                'bom_ing_fid' => 0, 'process' => $process, 'batch_no' => 1, 'round_no' => 1,
                'incoming_qty' => $incoming, 'sample_qty' => $sample, 'total_items' => count($items),
                'ng_qty' => $tot['ng_qty'], 'aod_qty' => $tot['aod_qty'], 'check_result' => $tot['check_result'],
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- ④ 完全刪除檢驗紀錄（僅超級管理員；測試用，破壞性操作）----
        // 認定為超級管理員的三個條件（缺一不可）：
        //   ① 目前登入者 id = 1
        //   ② 該帳號 user_status = 9（Login.php 內註明 case 9 = 超級管理員）
        //   ③ 在職（state 不在 eg_blocked_state_list() 的離職/留停清單內）
        // 另外真正執行刪除時，還必須再輸入一次 id=1 這個帳號的密碼。
        // 流程：先看影響分析 → 輸入大寫 Y ＋密碼 → transaction 刪除 → 寫 audit_log。
        $superAdmin = function () use ($pdo, $uid) {
            if ((int)$uid !== 1) return [false, '此功能僅限超級管理員（員工 ID = 1）使用'];
            $s = $pdo->prepare("SELECT id, user_uname, user_cname, user_password, user_status, state FROM user WHERE id=1 LIMIT 1");
            $s->execute();
            $u = $s->fetch(PDO::FETCH_ASSOC);
            if (!$u) return [false, '找不到超級管理員帳號'];
            if ((int)$u['user_status'] !== 9) return [false, '此帳號目前不是最高權限狀態，無法使用完全刪除'];
            if (function_exists('eg_blocked_state_list') && $u['state'] !== null
                && in_array((int)$u['state'], eg_blocked_state_list(), true)) {
                return [false, '此帳號目前非在職狀態，無法使用完全刪除'];
            }
            return [true, $u];
        };

        if ($act === 'del_preview' || $act === 'del_inspection') {
            include_once '../../src/common/user_active_lib.php';   // eg_blocked_state_list()
            list($okSa, $sa) = $superAdmin();
            if (!$okSa) throw new Exception($sa);
            $qid = (int)($_POST['qc_form_id'] ?? 0);
            if (!$qid) throw new Exception('缺少 qc_form_id');

            $f = $pdo->prepare(
                "SELECT f.*, d.D_Setting_Id AS part_no, qa.abnormal_order_no
                 FROM qc_check_form f
                 LEFT JOIN d_setting d ON d.d_id = f.d_id
                 LEFT JOIN qa_abnormal_order qa ON qa.id = f.abnormal_order_id
                 WHERE f.qc_form_id=?");
            $f->execute([$qid]);
            $form = $f->fetch(PDO::FETCH_ASSOC);
            if (!$form) throw new Exception('查無此檢驗紀錄（可能已被刪除）');

            $cnt = function ($sql, $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
            $nMeas = $cnt("SELECT COUNT(*) FROM qc_measurement WHERE qc_form_id=?", [$qid]);
            $nEdit = $cnt("SELECT COUNT(*) FROM qc_inspection_edit_log WHERE qc_form_id=?", [$qid]);
            $nSamp = $cnt("SELECT COUNT(*) FROM qc_sample_change_log WHERE qc_form_id=?", [$qid]);

            $info = [
                'qc_form_id'   => $qid,
                'part_no'      => $form['part_no'],
                'bom_ing_fid'  => (int)$form['bom_ing_fid'],
                'process_name' => $form['process_name'],
                'batch_no'     => (int)$form['batch_no'],
                'round_no'     => (int)$form['round_no'],
                'status'       => $form['status'],
                'check_result' => $form['check_result'],
                'ng_qty'       => (int)$form['ng_qty'],
                'created_by'   => $form['created_by'],
                'created_at'   => $form['created_at'],
                'abnormal_order_no' => $form['abnormal_order_no'],
                'n_measurement'     => $nMeas,
                'n_edit_log'        => $nEdit,
                'n_sample_change'   => $nSamp,
            ];

            if ($act === 'del_preview') {
                echo json_encode(['success' => true, 'info' => $info], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // ---- 真的刪 ----
            if (trim((string)($_POST['confirm'] ?? '')) !== 'Y') throw new Exception('請輸入大寫 Y 確認刪除');
            // 再驗一次超級管理員本人的密碼（本系統密碼為明文比對，見 src/store/Login.php）
            $pw = (string)($_POST['password'] ?? '');
            if ($pw === '' || !hash_equals((string)$sa['user_password'], $pw)) {
                throw new Exception('超級管理員密碼不正確，未執行刪除');
            }
            // 已開立異常單者不給刪：異常單是另一份已發出通知/待回簽的正式文件，
            // 直接刪掉檢驗紀錄會讓異常單指向不存在的來源。請先處理異常單。
            if (!empty($form['abnormal_order_id'])) {
                throw new Exception('此檢驗已開立異常單 ' . ($form['abnormal_order_no'] ?: ('#' . $form['abnormal_order_id'])) .
                                    '，不可直接刪除。請先處理/作廢該異常單。');
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM qc_measurement WHERE qc_form_id=?")->execute([$qid]);
            $pdo->prepare("DELETE FROM qc_inspection_edit_log WHERE qc_form_id=?")->execute([$qid]);
            $pdo->prepare("DELETE FROM qc_sample_change_log WHERE qc_form_id=?")->execute([$qid]);
            $pdo->prepare("DELETE FROM qc_check_form WHERE qc_form_id=?")->execute([$qid]);
            // 稽核：刪掉的內容整包留存，事後查得到誰刪了什麼
            try {
                $pdo->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                               VALUES ('DELETE','qc_check_form',?,?,?,?,?,NOW())")
                    ->execute([(string)$qid,
                        ('料號 ' . ($form['part_no'] ?? '') . ' / ' . ($form['process_name'] ?? '')),
                        json_encode(['deleted' => $info, 'form' => $form], JSON_UNESCAPED_UNICODE),
                        (int)$uid, $uid]);
            } catch (Exception $e) { /* 稽核寫入失敗不影響刪除結果 */ }
            $pdo->commit();

            echo json_encode(['success' => true, 'deleted' => $info], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- ⑤ 圖面變更提醒（受影響製程才跳）＋ 檢驗人員確認已更新檢驗項目 ----
        if ($act === 'dwg_alert') {
            $d_id = (int)($_POST['d_id'] ?? 0);
            $fid  = (int)($_POST['bom_ing_fid'] ?? 0);
            $proc = trim($_POST['process_name'] ?? '');
            if ($d_id <= 0) { echo json_encode(['success' => true, 'rows' => []], JSON_UNESCAPED_UNICODE); exit; }

            // 目前這一站在該 BOM 的加工順序（用來判斷「從某製程開始」是否已經輪到）
            $curSeq = null; $bom = null;
            if ($fid > 0) {
                $s = $pdo->prepare("SELECT bom, processing_sequence FROM bom_ing WHERE bom_ing_fid=?");
                $s->execute([$fid]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $bom = $r['bom']; $curSeq = ($r['processing_sequence'] === null ? null : (int)$r['processing_sequence']); }
            }

            $q = $pdo->prepare(
                "SELECT c.*, pn.ProcessName AS from_process_name
                 FROM qc_drawing_change c
                 LEFT JOIN process_no pn ON pn.ProcessNo = c.from_process_no
                 WHERE c.d_id=? AND c.status='OPEN' ORDER BY c.id DESC");
            $q->execute([$d_id]);
            $rows = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $affected = true;
                // 有指定起始製程時：查該製程在同一張 BOM 的加工順序，目前這站要在它之後（含）才提醒。
                // 查不到就一律提醒（寧可多提醒，也不要漏掉該檢查的尺寸）。
                if ($c['from_process_no'] !== null && $bom !== null && $curSeq !== null) {
                    $fs = $pdo->prepare("SELECT MIN(processing_sequence) FROM bom_ing WHERE bom=? AND process_no=?");
                    $fs->execute([$bom, (int)$c['from_process_no']]);
                    $fromSeq = $fs->fetchColumn();
                    if ($fromSeq !== null && $fromSeq !== false) $affected = ($curSeq >= (int)$fromSeq);
                }
                if (!$affected) continue;
                $cf = $pdo->prepare("SELECT confirmed_by, confirmed_at, note FROM qc_drawing_change_confirm
                                     WHERE change_id=? AND (process_name <=> ?) ORDER BY id DESC LIMIT 1");
                $cf->execute([(int)$c['id'], ($proc === '' ? null : $proc)]);
                $c['confirm'] = $cf->fetch(PDO::FETCH_ASSOC) ?: null;
                $rows[] = $c;
            }
            echo json_encode(['success' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'dwg_confirm') {
            if (!$hasF('qc_fill_inspection')) throw new Exception('您沒有「填寫檢驗表單」權限');
            $cid = (int)($_POST['change_id'] ?? 0);
            if (!$cid) throw new Exception('缺少變更單');
            $proc = trim($_POST['process_name'] ?? '');
            $vid  = ($_POST['version_id'] ?? '') === '' ? null : (int)$_POST['version_id'];
            $pdo->prepare("INSERT INTO qc_drawing_change_confirm (change_id, process_name, version_id, note, confirmed_by, confirmed_at)
                           VALUES (?,?,?,?,?,NOW())")
                ->execute([$cid, ($proc === '' ? null : $proc), $vid, mb_substr(trim($_POST['note'] ?? ''), 0, 255), $uid]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- ⑥ 檢驗標準管理（管理員／管理檢驗設定可改可刪，避免設定錯了改不掉）----
        if ($act === 'std_versions') {
            if (!$hasF('qc_manage_settings')) throw new Exception('需要「管理檢驗設定」權限');
            $d_id = (int)($_POST['d_id'] ?? 0);
            $s = $pdo->prepare("SELECT v.version_id, v.version_label, v.source_type, v.is_active,
                                       (SELECT COUNT(*) FROM qc_inspection_item i WHERE i.version_id=v.version_id) AS n_item,
                                       (SELECT COUNT(*) FROM qc_check_form f WHERE f.version_id=v.version_id) AS n_form
                                FROM qc_inspection_version v WHERE v.d_id=? ORDER BY v.version_id DESC");
            $s->execute([$d_id]);
            echo json_encode(['success' => true, 'rows' => $s->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'std_items') {
            if (!$hasF('qc_manage_settings')) throw new Exception('需要「管理檢驗設定」權限');
            $s = $pdo->prepare("SELECT item_id, process_name, item_code, item_name, standard_text,
                                       plus_tolerance, minus_tolerance, result_type, sort_order, is_active
                                FROM qc_inspection_item WHERE version_id=? ORDER BY process_name, sort_order, item_id");
            $s->execute([(int)($_POST['version_id'] ?? 0)]);
            $fmt = function ($v) { if ($v === null) return ''; $t = rtrim(rtrim((string)$v, '0'), '.'); return ($t === '' || $t === '-') ? '0' : $t; };
            $rows = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['plus_tolerance'] = $fmt($r['plus_tolerance']);
                $r['minus_tolerance'] = $fmt($r['minus_tolerance']);
                $rows[] = $r;
            }
            echo json_encode(['success' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'std_item_save') {
            if (!$hasF('qc_manage_settings')) throw new Exception('需要「管理檢驗設定」權限');
            $iid = (int)($_POST['item_id'] ?? 0);
            if (!$iid) throw new Exception('缺少項目');
            $nm = trim($_POST['item_name'] ?? '');
            if ($nm === '') throw new Exception('檢驗項目名稱不可空白');
            $pdo->prepare("UPDATE qc_inspection_item SET item_name=?, standard_text=?, plus_tolerance=?, minus_tolerance=?,
                           result_type=?, sort_order=?, is_active=? WHERE item_id=?")
                ->execute([$nm, trim($_POST['standard_text'] ?? ''),
                    (($_POST['plus_tolerance'] ?? '') === '' ? null : $_POST['plus_tolerance']),
                    (($_POST['minus_tolerance'] ?? '') === '' ? null : $_POST['minus_tolerance']),
                    (($_POST['result_type'] ?? '') === 'OKNG' ? 'OKNG' : 'NUMERIC'),
                    (int)($_POST['sort_order'] ?? 0), ((string)($_POST['is_active'] ?? '1') === '1' ? 1 : 0), $iid]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'std_item_delete') {
            if (!$hasF('qc_manage_settings')) throw new Exception('需要「管理檢驗設定」權限');
            $iid = (int)($_POST['item_id'] ?? 0);
            // 已有實測紀錄的項目不可硬刪（會讓歷史檢驗紀錄失去依據）→ 改為停用
            $used = $pdo->prepare("SELECT COUNT(*) FROM qc_measurement WHERE item_id=?");
            $used->execute([$iid]);
            if ((int)$used->fetchColumn() > 0) {
                $pdo->prepare("UPDATE qc_inspection_item SET is_active=0 WHERE item_id=?")->execute([$iid]);
                echo json_encode(['success' => true, 'softened' => true,
                    'message' => '此項目已有實測紀錄，不能真的刪除（會讓舊檢驗紀錄失去依據），已改為「停用」：之後不再帶出，但歷史查得到。'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM qc_inspection_item_tool_type WHERE item_id=?")->execute([$iid]);
            $pdo->prepare("DELETE FROM qc_inspection_item WHERE item_id=?")->execute([$iid]);
            $pdo->commit();
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'std_version_activate') {
            if (!$hasF('qc_manage_settings')) throw new Exception('需要「管理檢驗設定」權限');
            $d_id = (int)($_POST['d_id'] ?? 0); $vid = (int)($_POST['version_id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE qc_inspection_version SET is_active=0 WHERE d_id=?")->execute([$d_id]);
            $pdo->prepare("UPDATE qc_inspection_version SET is_active=1 WHERE version_id=?")->execute([$vid]);
            $pdo->commit();
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'std_version_delete') {
            if (!$isAdmin) throw new Exception('刪除整個標準版本僅限管理員');
            $vid = (int)($_POST['version_id'] ?? 0);
            $n = $pdo->prepare("SELECT COUNT(*) FROM qc_check_form WHERE version_id=?");
            $n->execute([$vid]);
            if ((int)$n->fetchColumn() > 0) throw new Exception('此版本已有檢驗紀錄使用，不可刪除（可改為停用）');
            $pdo->beginTransaction();
            $pdo->prepare("DELETE t FROM qc_inspection_item_tool_type t JOIN qc_inspection_item i ON t.item_id=i.item_id WHERE i.version_id=?")->execute([$vid]);
            $pdo->prepare("DELETE FROM qc_inspection_item WHERE version_id=?")->execute([$vid]);
            $pdo->prepare("DELETE FROM qc_inspection_version WHERE version_id=?")->execute([$vid]);
            $pdo->commit();
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- ⑦ 標準公差／客戶公差表：「自動套用公差」用（依標準值落在哪個區間帶入上下公差）----
        if ($act === 'tol_tables') {
            $rows = $pdo->query(
                "SELECT t.id, t.name, t.customer_id, c.customer AS customer_name,
                        (SELECT COUNT(*) FROM qc_tolerance_band b WHERE b.tolerance_table_id=t.id) AS band_count
                 FROM qc_tolerance_table t
                 LEFT JOIN customer_list c ON c.customer_id=t.customer_id
                 WHERE t.is_active=1
                 ORDER BY (t.customer_id IS NULL) ASC, t.name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            // 依目前料件客戶名稱找對應的客戶專屬公差表，供前端預選/標記推薦
            $clientName = trim((string)($_POST['client'] ?? ''));
            $matchId = 0;
            if ($clientName !== '') {
                foreach ($rows as $r) {
                    if ($r['customer_id'] && trim((string)$r['customer_name']) !== ''
                        && mb_strtolower(trim($r['customer_name'])) === mb_strtolower($clientName)) { $matchId = (int)$r['id']; break; }
                }
            }
            echo json_encode(['success'=>true, 'rows'=>$rows, 'match_id'=>$matchId,
                              'can_manage'=>($isAdmin || $hasF('qc_manage_settings'))], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'tol_bands') {
            $tid = (int)($_POST['table_id'] ?? 0);
            $s = $pdo->prepare("SELECT id, min_value, max_value, plus_tolerance, minus_tolerance FROM qc_tolerance_band WHERE tolerance_table_id=? ORDER BY min_value ASC");
            $s->execute([$tid]);
            echo json_encode(['success'=>true, 'rows'=>$s->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'tol_customer_options') {
            if (!$isAdmin && !$hasF('qc_manage_settings')) throw new Exception('需要「管理檢驗設定」權限');
            $rows = $pdo->query("SELECT customer_id, customer FROM customer_list WHERE is_inactive=0 AND is_own_company=0 ORDER BY customer ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'tol_table_save') {
            if (!$isAdmin && !$hasF('qc_manage_settings')) throw new Exception('需要「管理檢驗設定」權限');
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $cust = trim($_POST['customer_id'] ?? '');
            if ($name === '') throw new Exception('請輸入公差表名稱');
            $bands = json_decode($_POST['bands'] ?? '[]', true);
            if (!is_array($bands) || !count($bands)) throw new Exception('請至少新增一個公差區間');
            $clean = [];
            foreach ($bands as $b) {
                $mn = $b['min_value'] ?? null; $mx = $b['max_value'] ?? null;
                $pu = $b['plus_tolerance'] ?? null; $mi = $b['minus_tolerance'] ?? null;
                if ($mn === '' || $mn === null || $mx === '' || $mx === null || $pu === '' || $pu === null || $mi === '' || $mi === null) {
                    throw new Exception('公差區間欄位不可空白');
                }
                if (!is_numeric($mn) || !is_numeric($mx) || !is_numeric($pu) || !is_numeric($mi)) throw new Exception('公差區間必須是數字');
                if ((float)$mn > (float)$mx) throw new Exception('區間下限不可大於上限（' . $mn . ' ~ ' . $mx . '）');
                $clean[] = [(float)$mn, (float)$mx, (float)$pu, (float)$mi];
            }
            usort($clean, function ($a, $b) { return $a[0] <=> $b[0]; });
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare("UPDATE qc_tolerance_table SET name=?, customer_id=?, updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $cust !== '' ? $cust : null, $uid, $id]);
                $pdo->prepare("DELETE FROM qc_tolerance_band WHERE tolerance_table_id=?")->execute([$id]);
            } else {
                $pdo->prepare("INSERT INTO qc_tolerance_table (name, customer_id, is_active, created_by, created_at) VALUES (?, ?, 1, ?, NOW())")
                    ->execute([$name, $cust !== '' ? $cust : null, $uid]);
                $id = (int)$pdo->lastInsertId();
            }
            $insB = $pdo->prepare("INSERT INTO qc_tolerance_band (tolerance_table_id, min_value, max_value, plus_tolerance, minus_tolerance, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($clean as $i => $b) $insB->execute([$id, $b[0], $b[1], $b[2], $b[3], ($i + 1) * 10]);
            $pdo->commit();
            echo json_encode(['success'=>true, 'id'=>$id], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'tol_table_delete') {
            if (!$isAdmin && !$hasF('qc_manage_settings')) throw new Exception('需要「管理檢驗設定」權限');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('缺少公差表 id');
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM qc_tolerance_band WHERE tolerance_table_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM qc_tolerance_table WHERE id=?")->execute([$id]);
            $pdo->commit();
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new Exception('未知的 v2action: ' . $act);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>品管檢驗表 2.0</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
    /* ===================== 配色（全站規範：一律暖色系） =====================
       深棕字 #4A3524 / 砂 #F7E0BD / 琥珀 #F0A24B / 珊瑚紅 #DD5138（NG）
       顏色不是唯一資訊：OK/NG 一律同時有 ✔ / ✘ 文字標籤              */
    :root{
        --ink:#4A3524; --ink2:#6B4423; --cream:#FCF7F0; --sand:#F7E0BD;
        --amber:#F0A24B; --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC;
    }
    body.qc2 { background:#F6F1EA; }
    .qc2 .page-title h3 { color:var(--ink); }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
    input[type=number]{ -moz-appearance:textfield; appearance:textfield; }

    .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:14px; margin-bottom:12px; }
    .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:#4A3524; font-weight:bold; }
    .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
    .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
    .btn-warm-o:hover { background:var(--sand); color:var(--ink); }
    .btn-coral { background:var(--coral); border:1px solid #b9401f; color:#fff; font-weight:bold; }
    .btn-coral:hover,.btn-coral:focus { background:#b9401f; color:#fff; }

    /* ---------- 頂部固定情境列：料號/客戶/製程/數量隨時看得到 ---------- */
    .ctx-bar { position:sticky; top:0; z-index:900; background:#FFF8EE; border:1px solid var(--line);
               border-left:6px solid var(--amber); border-radius:6px; padding:8px 12px; margin-bottom:10px;
               display:flex; flex-wrap:wrap; gap:6px 20px; align-items:center; font-size:14px; color:var(--ink); }
    .ctx-bar b { color:#8a6a45; font-weight:normal; font-size:12px; display:block; line-height:1.1; }
    .ctx-bar .cv { font-weight:bold; font-size:15px; }
    .ctx-bar a.cv { color:var(--ink); text-decoration:underline; }

    /* ---------- 檢視切換 ---------- */
    .view-switch { display:inline-flex; border:1px solid var(--line); border-radius:20px; overflow:hidden; background:#fff; }
    .view-switch button { border:0; background:#fff; color:var(--ink2); padding:7px 16px; font-size:14px; }
    .view-switch button.on { background:var(--amber); color:#4A3524; font-weight:bold; }
    .toolbar-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:10px; }

    /* ---------- 項目/件別 膠囊列（可直接點跳，帶判定燈號） ---------- */
    .chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
    .chip { border:1px solid var(--line); background:#fff; border-radius:16px; padding:5px 12px; font-size:13px;
            color:var(--ink2); cursor:pointer; user-select:none; white-space:nowrap; }
    .chip.on { background:var(--ink2); border-color:var(--ink2); color:#fff; font-weight:bold; }
    .chip .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:5px; background:#D9C8B0; }
    .chip .dot.ok { background:var(--amber); }
    .chip .dot.ng { background:var(--coral); }
    .chip .cnt { font-size:11px; opacity:.75; margin-left:4px; }

    /* ---------- 專注卡片 ---------- */
    .fcard { background:#fff; border:1px solid var(--line); border-radius:10px; box-shadow:0 1px 3px rgba(120,90,50,.08); }
    .fcard-hd { padding:12px 16px; border-bottom:1px solid var(--line); background:var(--cream); border-radius:10px 10px 0 0; }
    .fcard-hd .idx { display:inline-block; min-width:34px; height:34px; line-height:34px; text-align:center; border-radius:8px;
                     background:var(--ink2); color:#fff; font-weight:bold; margin-right:10px; font-size:16px; }
    .fcard-hd .nm { font-size:22px; font-weight:bold; color:var(--ink); vertical-align:middle; }
    .fcard-bd { padding:14px 16px; }
    /* 規格帶：標準值與上下限就在輸入格正上方（痛點④） */
    .specbar { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
    .spec { background:var(--sand); border:1px solid var(--line); border-radius:8px; padding:6px 14px; min-width:96px; text-align:center; }
    .spec .k { font-size:11px; color:#8a6a45; }
    .spec .v { font-size:20px; font-weight:bold; color:var(--ink); line-height:1.2; }
    /* 標準值＝主角(大)，上下限＝配角(小)，一眼分得出來 */
    .spec.std { padding:4px 18px; }
    .spec.std .v { font-size:30px; }
    .spec.lim { background:#FFF6EA; min-width:84px; padding:6px 10px; }
    .spec.lim .k { font-size:10px; }
    .spec.lim .v { font-size:17px; color:#7A5A35; }
    .spec.tool { background:#fff; text-align:left; min-width:200px; }
    .spec.tool .v { font-size:14px; }
    /* 量具改用「點按鈕開跳窗挑」，不再用又長又難點的下拉 */
    .tool-btn { display:block; width:100%; text-align:left; border:1px solid var(--line); background:#fff; color:var(--ink);
                border-radius:6px; padding:7px 10px; font-size:14px; line-height:1.25; }
    .tool-btn:hover { background:var(--cream); border-color:var(--amber-d); }
    .tool-btn .tcat { font-weight:bold; }
    .tool-btn { white-space:normal; }              /* 編號帶規格後字串較長，允許換行不撐破欄寬 */
    .tool-btn .tno { color:#8a6a45; font-size:12px; margin-left:4px; }
    .tool-btn.none { color:#a08a6d; border-style:dashed; }
    #items-table .tool-btn { padding:4px 6px; font-size:12px; }
    /* 總表量具欄：比照列印版排版，類別/規格/編號各一行、字縮小、行距縮緊，減少總表逐列高度 */
    #items-table .tool-btn-compact { padding:2px 4px; line-height:1.15; }
    #items-table .tool-btn-compact span { display:block; font-size:11px; }
    /* 量具挑選跳窗：類型 → 編號，兩層都是大按鈕 */
    .tpick-grid { display:flex; flex-wrap:wrap; gap:8px; }
    .tpick-grid button { min-width:130px; min-height:52px; border:1px solid var(--line); background:#fff; color:var(--ink);
                         border-radius:8px; padding:8px 12px; font-size:15px; font-weight:bold; }
    .tpick-grid button:hover { background:var(--sand); border-color:var(--amber-d); }
    .tpick-grid button.on { background:var(--amber); border-color:var(--amber-d); }
    .tpick-grid button small { display:block; font-weight:normal; font-size:11px; color:#8a6a45; }
    .tpick-scope { background:var(--cream); border:1px solid var(--line); border-radius:6px; padding:8px 10px; margin-top:12px; font-size:13px; }
    .tpick-scope label { font-weight:normal; display:block; margin:2px 0; cursor:pointer; }

    /* ---------- 量測格（三種檢視同一尺寸；2026-07-30 依現場要求逐項/逐件改成與總表一致） ---------- */
    .cells { display:flex; flex-wrap:wrap; gap:6px; }
    .mcell { position:relative; width:72px; border:1px solid var(--line); border-radius:5px; background:#fff; padding:12px 2px 2px; text-align:center; }
    .mcell .mno { position:absolute; top:1px; left:3px; font-size:9px; color:#8a6a45; }
    .mcell .mval { width:100%; border:0; background:transparent; text-align:center; font-size:15px; font-weight:bold;
                   color:var(--ink); padding:0; height:22px; outline:none; }
    .mcell .mdev { display:block; font-size:9px; height:12px; line-height:12px; color:#8a6a45; }
    .mcell.c-ok { border-color:var(--amber); background:#FDF6EA; }
    .mcell.c-ok .mdev:before { content:'✔ '; }
    .mcell.c-ng { border-color:var(--coral); background:var(--coral); }
    .mcell.c-ng .mval { color:#fff; }
    .mcell.c-ng .mno { color:#FBE3DC; }
    .mcell.c-ng .mdev { color:#fff; font-weight:bold; }
    .mcell.c-ng .mdev:before { content:'✘ '; }
    .mcell.c-empty { border-style:dashed; border-color:#D9C8B0; }
    /* 疑似誤填（與標準值差異過大，例如漏打小數點／看錯量具）：底色比一般 NG 更深，並加 ⚠ */
    .mcell.c-warn { background:#8F3016 !important; border-color:#5E1F0E !important; }
    .mcell.c-warn .mval { color:#fff; }
    .mcell.c-warn .mno { color:#F3CDC2; }
    .mcell.c-warn .mdev { color:#FFD9A0; font-weight:bold; }
    .mcell.c-warn .mdev:before { content:''; }
    .mcell.c-warn .mtxt { color:#fff; }
    #dock .stat.warn { color:#8F3016; font-weight:bold; }
    .mcell.focus-on { box-shadow:0 0 0 3px rgba(240,162,75,.45); }
    .mcell.okng { cursor:pointer; user-select:none; padding-bottom:4px; }
    .mcell.okng .mtxt { display:block; font-size:13px; font-weight:bold; color:var(--ink); height:22px; line-height:22px; }
    .mcell.okng.c-ng .mtxt { color:#fff; }
    .mcell.okng.c-empty .mtxt { color:#b9a68d; }
    /* 逐件模式：一列一個檢驗項目 */
    /* 逐件模式：項目名稱與輸入格靠在一起（max-width 讓兩者不會被拉到左右兩端） */
    .prow { display:flex; align-items:center; gap:16px; padding:8px 0; border-bottom:1px dashed var(--line); max-width:660px; }
    .prow:last-child { border-bottom:0; }
    .prow .pnm { flex:1 1 auto; min-width:0; }
    .prow .pnm .n { font-size:16px; font-weight:bold; color:var(--ink); }
    .prow .pnm .s { font-size:12px; color:#8a6a45; }
    .prow .pin { flex:0 0 auto; }
    /* 加量測（同尺寸用第二支量具/方法再量一次） */
    .rdbox { border:1px dashed var(--line); border-radius:8px; padding:10px; margin-top:10px; background:#FBF7F1; }
    .rdbox .rdhd { display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:13px; color:var(--ink2); }
    .rdbox .rdhd select { max-width:220px; }

    /* ---------- 總表模式 ---------- */
    #items-table { table-layout:fixed; width:100%; background:#fff; }
    #items-table thead th { background:var(--cream); color:var(--ink); position:sticky; top:0; z-index:2; text-align:center;
                            white-space:nowrap; border-color:var(--line) !important; font-size:13px; }
    #items-table td { vertical-align:middle; border-color:var(--line) !important; }
    #items-table .g-name { font-weight:bold; color:var(--ink); }
    #items-table .g-spec { font-size:13px; color:var(--ink2); white-space:nowrap; }
    #items-table .table-input { width:100%; min-width:0; border:1px solid #ccc; padding:3px 5px; border-radius:3px; }
    #items-table .gcells { gap:4px; }
    /* 項目列的操作鈕（加量測/備註/刪除）改放在「檢驗項目」欄名稱下方，
       原本擺最右欄會被視窗右緣切掉看不到（2026-07-30 現場回饋） */
    .row-acts { margin-top:4px; font-size:12px; }
    .row-acts a { color:#8a6a45; text-decoration:none; margin-right:10px; white-space:nowrap; }
    .row-acts a:hover { color:var(--amber-d); text-decoration:underline; }
    .row-acts a.del:hover { color:var(--coral); }
    .row-acts a.has-note { color:var(--amber-d); font-weight:bold; }
    .gcells { display:flex; flex-wrap:wrap; gap:6px; }
    .pverdict { display:inline-block; min-width:82px; text-align:center; border-radius:6px; padding:6px 4px; font-weight:bold;
                border:2px solid var(--line); background:#fff; color:var(--ink); cursor:pointer; user-select:none; }
    .pverdict.ok { background:#FDF6EA; border-color:var(--amber); }
    .pverdict.ng { background:var(--coral); border-color:#b9401f; color:#fff; }
    .pverdict.manual { outline:2px dashed var(--amber-d); outline-offset:1px; }

    /* ---------- 底部固定摘要/動作列 ---------- */
    #dock { position:fixed; left:0; right:0; bottom:0; z-index:1000; background:#FFF8EE; border-top:2px solid var(--amber);
            box-shadow:0 -2px 8px rgba(120,90,50,.15); padding:8px 14px; }
    #dock .dockrow { display:flex; flex-wrap:wrap; align-items:center; gap:8px 18px; }
    #dock .stat { font-size:13px; color:var(--ink2); }
    #dock .stat b { font-size:18px; color:var(--ink); }
    #dock .stat.bad b { color:var(--coral); }
    .progbar { width:150px; height:8px; border-radius:4px; background:#EADFCE; overflow:hidden; display:inline-block; vertical-align:middle; }
    .progbar i { display:block; height:100%; background:var(--amber); }
    #dock-extra { display:none; padding-top:8px; border-top:1px dashed var(--line); margin-top:8px; }
    /* 底部空白由 JS 依 dock 實際高度設定（展開「數量/處置備註」時會變高），避免蓋住內容 */
    body.qc2 { padding-bottom:120px; }
    #dock .draft-note { font-size:12px; color:var(--amber-d); }

    /* ---------- 內建數字鍵盤（平板/戴手套） ---------- */
    #keypad { position:fixed; right:14px; bottom:104px; z-index:1001; background:#fff; border:1px solid var(--line);
              border-radius:10px; box-shadow:0 3px 12px rgba(120,90,50,.28); padding:8px; display:none; }
    #keypad .kp { display:grid; grid-template-columns:repeat(3,64px); gap:6px; }
    #keypad button { height:52px; font-size:20px; font-weight:bold; border:1px solid var(--line); background:var(--cream);
                     color:var(--ink); border-radius:8px; }
    #keypad button:active { background:var(--sand); }
    #keypad button.wide { grid-column:span 3; height:44px; font-size:16px; background:var(--amber); }
    #keypad .kphd { font-size:12px; color:#8a6a45; margin-bottom:6px; display:flex; justify-content:space-between; }

    .banner { border-radius:6px; padding:8px 12px; margin-bottom:10px; font-size:13px; }
    .banner-info { background:#FFF3E2; border:1px solid var(--line); color:var(--ink2); }
    .muted-help { color:#8a6a45; font-size:12px; }
    .batch-chip { display:inline-block; padding:6px 12px; margin:0 6px 6px 0; border-radius:18px; border:1px solid var(--line);
                  background:#fff; cursor:pointer; font-size:13px; user-select:none; color:var(--ink2); }
    .batch-chip.active { background:var(--ink2); border-color:var(--ink2); color:#fff; }
    .st-ok { color:var(--amber-d); } .st-ng { color:var(--coral); } .st-redo { color:#a9772f; } .st-wait { color:#8a6a45; }
    .batch-chip.active .st-ok,.batch-chip.active .st-ng,.batch-chip.active .st-redo,.batch-chip.active .st-wait { color:#fff; }
    .search-result-item { cursor:pointer; padding:8px 10px; border-bottom:1px solid var(--line); }
    .search-result-item:hover { background:var(--cream); }
    .page-title .dropdown-menu { right:0 !important; left:auto !important; }
    .history-row td { font-size:13px; }
    .tool-sel-label { font-size:11px; color:#6b5a45; }

    /* 平板：加大所有觸控目標 */
    /* 平板：格子與按鈕稍微加大以利觸控（桌機維持與總表一致的緊湊尺寸） */
    @media (max-width:1024px), (pointer:coarse) {
        .mcell { width:92px; padding-top:14px; }
        .mcell .mval { font-size:19px; height:28px; }
        .mcell .mdev { font-size:10px; height:14px; line-height:14px; }
        .mcell.okng .mtxt { font-size:16px; height:28px; line-height:28px; }
        .chip { padding:8px 14px; font-size:14px; }
        .btn-sm,.btn-xs { padding:8px 13px; font-size:14px; }
    }
    @media (max-width:600px){
        .mcell { width:calc(33% - 6px); }
        .spec { flex:1 1 45%; }
    }

    /* ---------- 列印：正式檢驗表版面（A4，交瀏覽器原生分頁，不用 JS 量高度） ---------- */
    #print-area { display:none; }
    @media print {
        /* @page margin:0 → 瀏覽器沒有空間印它自己的頁首頁尾（網址/日期/標題），版面留白改用 padding
           （ai-rules/16 第四之二節；代價是頁碼得用 position:fixed，單頁表單不印頁碼） */
        @page { size:A4 portrait; margin:0; }
        html, body { height:auto !important; overflow:visible !important; }
        body { background:#fff !important; padding:0 !important; margin:0 !important; }
        /* 畫面內容一律 display:none，**不可用 visibility:hidden**——後者被藏起來的元素仍佔版面高度，
           文件會比實際內容高出一大截，末尾就多印一頁全白的紙（2026-08-03 修） */
        body > *:not(#print-area) { display:none !important; }
        #print-area { display:block; position:static; width:auto; color:#000; font-size:12px; line-height:1.15;
                      padding:10mm 8mm 6mm; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        /* 表格內換行/多行文字（量具三行、公差上下限、檢驗項目名稱過長換行、備註…）一律緊貼成一行的行距，
           不要吃到 bootstrap body 的 line-height:1.42857（那是多行文字看起來一行一大格空白的根因） */
        #print-area table.pr-items, #print-area table.pr-items * { line-height:1.15; }
        #print-area > *:last-child { margin-bottom:0; }
        /* 大標題＝本公司全名（動態取，禁寫死）；副標題＝綁定 AS 文件的表單名稱 */
        #print-area .pr-co { text-align:center; font-size:22px; font-weight:bold; letter-spacing:1px; }
        #print-area .pr-title { text-align:center; font-size:16px; font-weight:bold; margin:2px 0 6px; }
        /* AS 文件編號：每頁固定右下角 */
        #print-area .pt-foot { position:fixed; right:8mm; bottom:5mm; font-size:9pt; color:#333; }
        #print-area table.pr-items th.c-tool, #print-area table.pr-items td.c-tool { width:66px; }
        /* 量具格：三行＝量具類別／量具規格／量具編號，字自動縮小、每行都完整顯示（不截字） */
        #print-area .tool2 { font-size:8.5px; line-height:1.15; word-break:break-all; white-space:normal; }
        #print-area .tool2 span { display:block; }
        #print-area .c-tol .lo { display:block; }
        #print-area table.pr-items th.c-no { width:34px; }
        #print-area table.pr-items th.c-std, #print-area table.pr-items th.c-tol { width:56px; }
        #print-area .pr-meta, #print-area .pr-meta * { line-height:1.15; }
        #print-area .pr-meta { width:100%; border-collapse:collapse; margin-bottom:6px; }
        #print-area .pr-meta td { border:1px solid #000; padding:3px 6px; }
        #print-area .pr-meta .k { background:#f0f0f0; font-weight:bold; white-space:nowrap; width:70px; }
        #print-area table.pr-items { width:100%; border-collapse:collapse; }
        #print-area table.pr-items th, #print-area table.pr-items td { border:1px solid #000; padding:3px 4px; text-align:center; }
        #print-area table.pr-items thead th { background:#eee; }
        #print-area table.pr-items thead { display:table-header-group; }
        #print-area table.pr-items tr { page-break-inside:avoid; }
        #print-area .pr-ng { color:#000; font-weight:bold; text-decoration:underline; }
        #print-area .pr-sign { margin-top:14px; width:100%; border-collapse:collapse; }
        #print-area .pr-sign td { border:1px solid #000; padding:6px 6px 4px; text-align:center; vertical-align:bottom; height:92px; width:50%; }
        #print-area .pr-sign .lbl { font-size:11px; color:#333; }
        #dock,#keypad { display:none !important; }
    }
    </style>
</head>
<body class="qc2 <?php echo $isPopup ? 'popup-mode' : 'nav-sm'; ?>">
<div class="container body">
    <div class="main_container">
        <?php if (!$isPopup) include '../partPage/sideAndTopBarMenu.html'; ?>

        <div class="<?php echo $isPopup ? 'col-md-12' : 'right_col'; ?>" role="main"<?php echo $isPopup ? ' style="width:100%;float:none;padding:15px;"' : ''; ?>>
            <div class="page-title">
                <div class="title_left"><h3>品管檢驗表 <small>2.0 新版填寫介面</small></h3></div>
                <div class="title_right">
                    <div class="pull-right">
                        <button class="btn btn-default btn-sm" id="btn-print"><i class="fa fa-print"></i> 列印</button>
                        <button class="btn btn-default btn-sm" id="btn-csv"><i class="fa fa-file-excel-o"></i> 匯出CSV</button>
                        <button class="btn btn-default btn-sm" id="btn-history"><i class="fa fa-history"></i> 歷史紀錄</button>
                        <div class="btn-group">
                            <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown"><i class="fa fa-cog"></i> 設定 <span class="caret"></span></button>
                            <ul class="dropdown-menu dropdown-menu-right">
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-tool-setting"><i class="fa fa-wrench"></i> 量具設定</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-special-setting"><i class="fa fa-cog"></i> 幾何公差管理</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-template-setting"><i class="fa fa-list-alt"></i> 通用樣板管理</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-std-manage"><i class="fa fa-sliders"></i> 檢驗標準管理（改／刪）</a></li>
                                <li><a href="drawing_change_log.php" target="_blank"><i class="fa fa-exchange"></i> 圖面變更紀錄（AS 2-PD-01-07）</a></li>
                                <li class="sampling-menu-item" style="display:none;"><a href="#" id="btn-sampling-setting"><i class="fa fa-list-ol"></i> 抽樣規則設定</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-qadept-setting"><i class="fa fa-sitemap"></i> 異常單回覆部門設定</a></li>
                                <li class="approve-menu-item" style="display:none;"><a href="#" id="btn-approve-setting"><i class="fa fa-check-square-o"></i> 主管審核自動核可設定</a></li>
                                <li><a href="#" id="btn-qadecide-setting"><i class="fa fa-gavel"></i> 異常單處置決策設定</a></li>
                                <li class="divider"></li>
                                <li><a href="#" id="btn-perm-setting"><i class="fa fa-key"></i> 權限設定（角色）</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="clearfix"></div>

            <div id="no-view-hint" class="alert alert-danger" style="display:none;"></div>
            <div class="banner banner-info" id="mode-banner"></div>

            <!-- 示範/獨立瀏覽模式才出現的待驗搜尋 -->
            <div id="step-search" style="display:none;">
                <div class="row">
                    <div class="col-md-6">
                        <div class="warm-panel">
                            <b>① 從製令待驗清單挑</b>
                            <div class="muted-help" style="margin-bottom:6px;">正常生產的檢驗：有 BOM、有製程</div>
                            <div class="input-group">
                                <input type="text" id="search-kw" class="form-control" placeholder="輸入部分料號 / BOM / 客戶後按搜尋">
                                <span class="input-group-btn"><button class="btn btn-warm" id="btn-search">搜尋</button></span>
                            </div>
                            <div id="search-results" style="border:1px solid #E4D3BC; margin-top:4px; max-height:220px; overflow:auto;"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="warm-panel">
                            <b>② 臨時檢驗單（退貨／客訴／來料，無製程）</b>
                            <div class="muted-help" style="margin-bottom:6px;">不是從製令來的檢驗，這裡自己開一張</div>
                            <button class="btn btn-warm btn-sm" id="btn-new-adhoc"><i class="fa fa-plus"></i> 建立臨時檢驗單</button>
                            <div style="margin-top:8px;"><b class="muted-help">最近的臨時檢驗單</b>
                                <div id="adhoc-list" style="max-height:200px;overflow:auto;margin-top:4px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 固定情境列（料號/客戶/BOM/製程/數量） -->
            <div class="ctx-bar" id="ctx-bar" style="display:none;"></div>

            <div id="main-area" style="display:none;">
                <div id="no-part-hint" class="alert alert-danger" style="display:none;">
                    <i class="fa fa-exclamation-circle"></i> 此料件尚未建立料號，請先到 <b>基本設定</b> 建立料號後再檢驗（暫無法儲存）。
                </div>
                <div id="no-perm-hint" class="alert alert-danger" style="display:none;">
                    <i class="fa fa-ban"></i> 您沒有<b>「填寫檢驗表單」</b>權限，僅能檢視。請洽管理員於 設定 → 權限設定 開通。
                </div>
                <div id="edit-mode-banner" class="alert alert-info" style="display:none;">
                    <i class="fa fa-pencil"></i> <b>修改模式</b>：正在修改歷程 qc_form_id=<span id="edit-form-id"></span>，儲存時需填修改原因，存檔後此筆會自動回鎖。
                    <button class="btn btn-xs btn-default pull-right" id="btn-exit-edit">取消修改，回到新檢驗</button>
                </div>

                <!-- 批次 / 歷程（預設收合，不佔填寫版面） -->
                <div class="warm-panel" style="padding:8px 12px;">
                    <a href="#" id="btn-toggle-batch" style="color:var(--ink2);font-weight:bold;text-decoration:none;">
                        <i class="fa fa-caret-right"></i> 批次與檢驗歷程 <span class="muted-help" id="batch-summary"></span></a>
                    <div id="batch-zone" style="display:none; margin-top:10px;">
                        <div id="batch-bar" style="margin-bottom:6px;"></div>
                        <div id="batch-history"></div>
                    </div>
                </div>

                <div id="no-std-hint" class="alert alert-warning" style="display:none;">
                    <i class="fa fa-exclamation-triangle"></i> 此料號／製程<b>尚未設定檢驗標準</b>。可按下方「新增檢驗項目」自行建立，或
                    <button class="btn btn-xs btn-warm-o" id="btn-import-tpl"><i class="fa fa-download"></i> 匯入通用樣板</button>
                    後微調。<b>勾選底部「同步更新標準」存檔後即成此料號標準，下次自動帶出。</b>
                </div>

                <!-- 檢視切換列（新增項目／符號／編號切換都放這裡，永遠看得到、不會被底部列蓋住） -->
                <div class="toolbar-row">
                    <span class="view-switch">
                        <button data-view="ITEM" title="一次專注一個尺寸，量完所有件再換下一個尺寸">逐項</button>
                        <button data-view="PCS"  title="一次專注一件，把該件所有尺寸量完">逐件</button>
                        <button data-view="GRID" title="傳統格狀總表，鍵盤連續輸入最快">總表</button>
                    </span>
                    <button class="btn btn-warm-o btn-sm" id="btn-add-row-top"><i class="fa fa-plus"></i> 新增檢驗項目</button>
                    <span class="btn-group">
                        <button class="btn btn-default btn-sm" id="btn-sym" title="插入工程符號（Ø ± ▽ …）到游標處">Ø± 符號</button>
                        <button class="btn btn-default btn-sm" id="btn-code-mode2" title="切換檢驗項目編號顯示方式"></button>
                    </span>
                    <button class="btn btn-default btn-sm" id="btn-apply-tol" title="依標準值自動帶入上下公差（只套用在上下限都還沒填的欄位）"><i class="fa fa-magic"></i> 自動套用公差</button>
                    <button class="btn btn-default btn-sm" id="btn-keypad"><i class="fa fa-keyboard-o"></i> 數字鍵盤</button>
                    <span class="muted-help" id="view-hint"></span>
                </div>

                <!-- 三種檢視（同一份資料模型，切換不會遺失已填內容） -->
                <div id="view-item" class="view-pane"></div>
                <div id="view-pcs"  class="view-pane" style="display:none;"></div>
                <div id="view-grid" class="view-pane" style="display:none;">
                    <div style="margin-bottom:6px;">
                        <label style="font-weight:normal;"><input type="checkbox" id="chk-std-edit"> 編輯標準（顯示項目名稱／公差／量具／型態欄位）</label>
                        <span class="muted-help pull-right">在最後一列的欄位按 <b>↓</b> 會自動新增一列；在全空的最後一列按 <b>↑</b> 會自動移除該列</span>
                    </div>
                    <div class="table-responsive" style="max-height:58vh; overflow:auto;">
                        <table class="table table-bordered" id="items-table">
                            <thead><tr id="grid-head"></tr></thead>
                            <tbody id="items-body"></tbody>
                            <tfoot><tr>
                                <td id="verdict-label" class="text-right" style="font-weight:bold;background:var(--cream);">判定結果<br>
                                    <span class="muted-help" style="font-weight:normal;">該件任一項 NG 即自動 NG；點擊可手動改判，雙擊恢復自動</span></td>
                                <td id="verdict-cells" style="background:var(--cream);"></td>
                                <td style="background:var(--cream);"></td>
                            </tr></tfoot>
                        </table>
                    </div>
                </div>

                <div style="margin:10px 0 6px;">
                    <button class="btn btn-warm-o btn-sm" id="btn-add-row"><i class="fa fa-plus"></i> 新增檢驗項目</button>
                    <button class="btn btn-default btn-sm" id="btn-import-tpl2"><i class="fa fa-download"></i> 匯入通用樣板</button>
                </div>
            </div>
        </div>

        <?php if (!$isPopup) include '../partPage/footer.html'; ?>
    </div>
</div>

<!-- ===================== 底部固定摘要 / 動作列 ===================== -->
<div id="dock" style="display:none;">
    <div class="dockrow">
        <span class="stat"><i class="fa fa-user"></i> 檢驗人員 <b><?php echo htmlspecialchars($CURRENT_CNAME !== '' ? $CURRENT_CNAME : '（未取得使用者名稱）', ENT_QUOTES, 'UTF-8'); ?></b></span>
        <span class="stat">進度 <b id="dk-prog">0/0</b> <span class="progbar"><i id="dk-progbar" style="width:0%"></i></span></span>
        <span class="stat bad">不良 <b id="dk-ng">0</b> 件</span>
        <span class="stat">整體判定 <b id="dk-judge">—</b></span>
        <span class="stat warn" id="dk-warn" style="display:none;"></span>
        <span class="draft-note" id="draft-status"></span>
        <span style="flex:1 1 auto;"></span>
        <button class="btn btn-default btn-sm" id="btn-dock-extra"><i class="fa fa-sliders"></i> 數量 / 處置備註</button>
        <button class="btn btn-default btn-sm" id="btn-cancel"><i class="fa fa-times"></i> 取消</button>
        <button class="btn btn-coral btn-sm" id="btn-redo"><i class="fa fa-undo"></i> 退回重做</button>
        <button class="btn btn-warm" id="btn-save"><i class="fa fa-save"></i> 儲存檢驗結果</button>
    </div>
    <div id="dock-extra">
        <div class="row">
            <div class="col-sm-2 form-group"><label class="muted-help">本批送驗數</label>
                <input type="number" class="form-control input-sm" id="inp-qty" value="0"></div>
            <div class="col-sm-2 form-group"><label class="muted-help">抽驗數（件）</label>
                <input type="number" class="form-control input-sm" id="inp-sample" value="5"></div>
            <div class="col-sm-2 form-group"><label class="muted-help">不良數（自動）</label>
                <input type="number" class="form-control input-sm" id="inp-ng" value="0" readonly></div>
            <div class="col-sm-6 form-group"><label class="muted-help">處置 / 備註</label>
                <input type="text" class="form-control input-sm" id="inp-remark" placeholder="例：尺寸 A 超差，退回重做…"></div>
        </div>
        <div class="row">
            <div class="col-sm-3 form-group"><label class="muted-help">容器 1（選填，供應用）</label>
                <div style="display:flex;gap:6px;">
                    <select class="form-control input-sm" id="insp-container-1" style="width:110px;">
                        <option value="">請選擇</option>
                        <option value="P">PP箱</option>
                        <option value="E">蝴蝶籠</option>
                        <option value="T">鐵桶</option>
                        <option value="板">棧板</option>
                    </select>
                    <input type="number" class="form-control input-sm" id="insp-quantity-1" min="0" step="1" placeholder="箱數" style="width:80px;">
                </div>
            </div>
            <div class="col-sm-3 form-group"><label class="muted-help">容器 2（選填）</label>
                <div style="display:flex;gap:6px;">
                    <select class="form-control input-sm" id="insp-container-2" style="width:110px;">
                        <option value="">請選擇</option>
                        <option value="P">PP箱</option>
                        <option value="E">蝴蝶籠</option>
                        <option value="T">鐵桶</option>
                        <option value="板">棧板</option>
                    </select>
                    <input type="number" class="form-control input-sm" id="insp-quantity-2" min="0" step="1" placeholder="箱數" style="width:80px;">
                </div>
            </div>
        </div>
        <label style="font-weight:normal;"><input type="checkbox" id="chk-save-std" checked> 存檔時同步更新此料號的檢驗標準（下次自動帶出）</label>
    </div>
</div>

<!-- ===================== 內建數字鍵盤 ===================== -->
<div id="keypad">
    <div class="kphd"><span>數字鍵盤</span><a href="#" id="kp-close">關閉</a></div>
    <div class="kp">
        <button data-k="7">7</button><button data-k="8">8</button><button data-k="9">9</button>
        <button data-k="4">4</button><button data-k="5">5</button><button data-k="6">6</button>
        <button data-k="1">1</button><button data-k="2">2</button><button data-k="3">3</button>
        <button data-k="-">−</button><button data-k="0">0</button><button data-k=".">.</button>
        <button data-k="BS"><i class="fa fa-long-arrow-left"></i></button>
        <button data-k="CL">清除</button>
        <button data-k="OK"><i class="fa fa-check"></i></button>
        <button class="wide" data-k="NEXT">下一格 <i class="fa fa-arrow-right"></i></button>
    </div>
</div>

<!-- ===================== 工程符號面板（插到游標處；管理員可增修刪） ===================== -->
<div id="sym-pad" onmousedown="event.preventDefault()"
     style="display:none;position:fixed;z-index:1200;background:#fff;border:1px solid #E4D3BC;border-radius:8px;
            padding:8px;box-shadow:0 4px 14px rgba(120,90,50,.3);width:266px;">
    <div style="font-size:12px;color:#8a6a45;margin-bottom:6px;">點一下插入到剛才的輸入欄游標處</div>
    <div id="sym-pad-list" style="display:flex;flex-wrap:wrap;gap:4px;"></div>
    <div id="sym-pad-admin" style="display:none;border-top:1px dashed #E4D3BC;margin-top:8px;padding-top:6px;">
        <a href="#" id="btn-sym-manage" style="font-size:12px;color:#C77C1A;"><i class="fa fa-cog"></i> 管理符號（新增／修改／刪除）</a>
    </div>
</div>

<!-- 符號主檔維護跳窗（僅管理員） -->
<div class="modal fade" id="symManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-font"></i> 工程符號管理 <small>（僅管理員）</small></h4>
        </div>
        <div class="modal-body">
            <form id="sym-form" class="form-inline" style="margin-bottom:10px;">
                <input type="hidden" id="sym-id">
                <input type="text" class="form-control input-sm" id="sym-char" placeholder="符號" style="width:80px;font-size:18px;text-align:center;" maxlength="4" required>
                <input type="text" class="form-control input-sm" id="sym-label" placeholder="說明（如 直徑）" style="width:180px;">
                <input type="number" class="form-control input-sm" id="sym-sort" placeholder="排序" style="width:80px;">
                <button type="submit" class="btn btn-warm btn-sm" id="btn-sym-save">新增</button>
                <button type="button" class="btn btn-default btn-sm" id="btn-sym-cancel" style="display:none;">取消編輯</button>
            </form>
            <div class="muted-help" style="margin-bottom:6px;">符號可直接從別處複製貼上（例如 ⌀ ⊥ ∥ ⌭）。排序數字小的排前面。</div>
            <table class="table table-condensed table-bordered">
                <thead><tr><th width="70">符號</th><th>說明</th><th width="70">排序</th><th width="110"></th></tr></thead>
                <tbody id="sym-list"></tbody>
            </table>
        </div>
        <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div></div>
</div>

<!-- ===================== 抽驗數變更理由（必填，會記錄在此檢驗單） ===================== -->
<div class="modal fade" id="sampleChgModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-list-ol"></i> 變更抽驗數</h4>
        </div>
        <div class="modal-body">
            <div class="muted-help" id="sc-info" style="margin-bottom:8px;"></div>
            <label>變更理由（必填，會記錄在這張檢驗單）</label>
            <textarea class="form-control" id="sc-reason" rows="3" placeholder="例：客戶要求加嚴抽驗／本批數量不足，改抽 3 件"></textarea>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" id="btn-sc-cancel">取消變更</button>
            <button class="btn btn-warm" id="btn-sc-ok">確定變更</button>
        </div>
    </div></div>
</div>

<!-- ===================== 無 BOM／無製程的臨時檢驗單 ===================== -->
<div class="modal fade" id="adhocModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-plus-square-o"></i> 建立臨時檢驗單（無製令／無製程）</h4>
        </div>
        <div class="modal-body">
            <div class="muted-help" style="margin-bottom:10px;">
                用於<b>退貨、客訴、來料、重工</b>等不是從製令待驗清單來、也沒有 BOM 製程的檢驗。
                存檔後一樣寫入正式檢驗表，可列印、可查歷史，只是「製程」欄記的是檢驗類型。
            </div>
            <div class="form-group">
                <label>檢驗類型</label>
                <div id="ah-type-btns" style="margin-bottom:6px;"></div>
                <input type="text" class="form-control input-sm" id="ah-type" placeholder="也可自行輸入，例如：客戶退貨複驗">
            </div>
            <div class="form-group">
                <label>料號（必填）</label>
                <div class="input-group">
                    <input type="text" class="form-control input-sm" id="ah-part-kw" placeholder="輸入部分料號後按搜尋">
                    <span class="input-group-btn"><button class="btn btn-default btn-sm" id="btn-ah-search">搜尋</button></span>
                </div>
                <div id="ah-part-results" style="border:1px solid #E4D3BC;margin-top:4px;max-height:160px;overflow:auto;display:none;"></div>
                <div id="ah-part-picked" class="muted-help" style="margin-top:4px;"></div>
            </div>
            <div class="row">
                <div class="col-xs-6 form-group"><label>送驗數</label><input type="number" class="form-control input-sm" id="ah-qty" value="0"></div>
                <div class="col-xs-6 form-group"><label>抽驗數（件）</label><input type="number" class="form-control input-sm" id="ah-sample" value="3"></div>
            </div>
            <div class="form-group"><label>備註</label><input type="text" class="form-control input-sm" id="ah-remark" placeholder="選填"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-warm" id="btn-ah-create">建立並開始填寫</button>
        </div>
    </div></div>
</div>

<!-- ===================== 檢驗標準管理（改／刪；需管理檢驗設定權限） ===================== -->
<div class="modal fade" id="stdManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-sliders"></i> 檢驗標準管理
                <small>設定錯了可以在這裡直接改或刪</small></h4>
        </div>
        <div class="modal-body">
            <div class="row">
                <div class="col-sm-7 form-group">
                    <label>料號</label>
                    <div class="input-group">
                        <input type="text" class="form-control input-sm" id="sm-part-kw" placeholder="輸入部分料號後按搜尋">
                        <span class="input-group-btn"><button class="btn btn-default btn-sm" id="btn-sm-search">搜尋</button></span>
                    </div>
                    <div id="sm-part-results" style="border:1px solid var(--line);margin-top:4px;max-height:140px;overflow:auto;display:none;"></div>
                </div>
                <div class="col-sm-5 form-group">
                    <label>標準版本</label>
                    <select class="form-control input-sm" id="sm-version"></select>
                    <div style="margin-top:4px;">
                        <button class="btn btn-xs btn-warm-o" id="btn-sm-activate">設為目前生效版本</button>
                        <button class="btn btn-xs btn-default" id="btn-sm-delver" style="color:#DD5138;">刪除此版本</button>
                    </div>
                </div>
            </div>
            <div class="muted-help" id="sm-hint" style="margin-bottom:6px;"></div>
            <div class="table-responsive" style="max-height:44vh;overflow:auto;">
                <table class="table table-condensed table-bordered" style="background:#fff;">
                    <thead><tr>
                        <th width="90">製程</th><th width="150">檢驗項目</th><th width="80">標準值</th>
                        <th width="70">上公差</th><th width="70">下公差</th><th width="76">型態</th>
                        <th width="56">排序</th><th width="56">啟用</th><th width="96"></th>
                    </tr></thead>
                    <tbody id="sm-items"><tr><td colspan="9" class="text-center muted-help">請先選擇料號</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div></div>
</div>

<!-- ===================== 完全刪除檢驗紀錄（僅超級管理員；測試用） ===================== -->
<div class="modal fade" id="delRecModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#8F3016;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;">&times;</button>
            <h4 class="modal-title"><i class="fa fa-trash"></i> 完全刪除檢驗紀錄（無法復原）</h4>
        </div>
        <div class="modal-body">
            <div id="del-info">載入中…</div>
            <div id="del-form" style="display:none;margin-top:12px;border-top:1px dashed var(--line);padding-top:10px;">
                <div class="form-group">
                    <label>請輸入大寫 <b>Y</b> 確認</label>
                    <input type="text" class="form-control input-sm" id="del-confirm" maxlength="1"
                           style="width:80px;text-align:center;font-size:18px;" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>超級管理員密碼</label>
                    <input type="password" class="form-control input-sm" id="del-password"
                           style="max-width:260px;" autocomplete="new-password">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-coral" id="btn-del-go" style="display:none;"><i class="fa fa-trash"></i> 確定完全刪除</button>
        </div>
    </div></div>
</div>

<!-- ===================== 量具挑選跳窗：先點類型、再點編號 ===================== -->
<div class="modal fade" id="toolPickModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-wrench"></i> 選擇量具 <small id="tp-for"></small></h4>
        </div>
        <div class="modal-body">
            <div id="tp-step1">
                <div class="muted-help" style="margin-bottom:6px;">① 先點量具<b>類型</b></div>
                <div class="tpick-grid" id="tp-cats"></div>
            </div>
            <div id="tp-step2" style="display:none;">
                <div class="muted-help" style="margin-bottom:6px;">
                    ② 再點量具<b>編號</b>　<a href="#" id="tp-back">← 換一個類型</a></div>
                <div class="tpick-grid" id="tp-nos"></div>
            </div>
            <div class="tpick-scope" id="tp-scope">
                <b>套用範圍</b>　<span class="muted-help">同一支量具常常好幾個尺寸共用，不必一欄一欄設</span>
                <label><input type="radio" name="tpscope" value="blank"> 套用到<b>所有尚未指定量具</b>的檢驗項目</label>
                <label><input type="radio" name="tpscope" value="one" checked> 只設定<b>這一個</b>項目</label>
                <label><input type="radio" name="tpscope" value="all"> 套用到<b>全部</b>檢驗項目（覆蓋既有設定）</label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default pull-left" id="tp-clear"><i class="fa fa-eraser"></i> 清除此項量具</button>
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        </div>
    </div></div>
</div>

<!-- 樣板選擇 Modal（示意：正式版接通用樣板） -->
<div class="modal fade" id="tplModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">選擇通用樣板</h4></div>
        <div class="modal-body"><div class="list-group" id="tpl-list"></div></div>
    </div></div>
</div>

<!-- 自動套用公差：選擇要套用的公差表 -->
<div class="modal fade" id="tolPickModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-magic"></i> 自動套用公差</h4>
        </div>
        <div class="modal-body">
            <div class="muted-help" style="margin-bottom:8px;">依各檢驗項目的<b>標準值</b>落在哪個區間，帶入上/下公差；<b>只套用在上下限都還沒填的欄位</b>，已填的不會被覆蓋。</div>
            <div id="tol-pick-list"></div>
            <div class="text-center" id="tol-pick-manage-wrap" style="margin-top:10px;display:none;">
                <a href="#" id="btn-tol-manage">管理公差表 →</a>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-warm" id="btn-tol-apply-go">套用</button>
        </div>
    </div></div>
</div>

<!-- 公差表管理（新增/編輯/刪除；僅具「管理檢驗設定」權限者可用） -->
<div class="modal fade" id="tolManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-sliders"></i> 公差表管理</h4>
        </div>
        <div class="modal-body">
            <div class="row">
                <div class="col-sm-4" style="border-right:1px solid var(--line);">
                    <div id="tol-mg-list" style="max-height:420px;overflow:auto;"></div>
                    <button class="btn btn-warm-o btn-sm btn-block" id="btn-tol-mg-new" style="margin-top:8px;"><i class="fa fa-plus"></i> 新增公差表</button>
                </div>
                <div class="col-sm-8">
                    <div id="tol-mg-editor"><div class="text-muted">請於左側選擇一個公差表，或新增一個。</div></div>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div></div>
</div>

<!-- NG 後詢問是否開立異常單 Modal（必選：開立 或 填原因不開立） -->
<div class="modal fade" id="ngAskModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#d9534f;color:#fff;border-radius:6px 6px 0 0;">
            <h4 class="modal-title"><i class="fa fa-exclamation-triangle"></i> 檢驗判定不良（NG）</h4>
        </div>
        <div class="modal-body">
            <p id="ng-ask-info" style="font-size:15px;"></p>
            <div class="text-center" style="margin:16px 0;">
                <button class="btn btn-danger btn-lg" id="btn-ng-open" style="margin-right:14px;"><i class="fa fa-file-text-o"></i> 開立異常單</button>
                <button class="btn btn-default btn-lg" id="btn-ng-skip">不開立（填原因）</button>
            </div>
            <div class="text-center">
                <button class="btn btn-link" id="btn-ng-later" style="color:#8a6a45;">
                    <i class="fa fa-clock-o"></i> 取消，稍後再決定</button>
                <div class="muted-help">檢驗結果<b>已經存檔</b>，關掉不影響紀錄；之後可從「批次與檢驗歷程」的<b>開異常單</b>按鈕補開。</div>
            </div>
            <div id="ng-skip-area" style="display:none;">
                <label>不開立異常單的原因（必填，會記錄於檢驗歷程）</label>
                <textarea class="form-control" id="ng-skip-reason" rows="2" placeholder="例：輕微偏差已現場處置、客戶允收…"></textarea>
                <div class="text-right" style="margin-top:8px;">
                    <button class="btn btn-primary btn-sm" id="btn-ng-skip-confirm"><i class="fa fa-check"></i> 確認不開立</button>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 修改紀錄 Modal -->
<div class="modal fade" id="logModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-history"></i> 檢驗歷程修改紀錄</h4></div>
        <div class="modal-body" id="log-modal-body"></div>
    </div></div>
</div>

<!-- 刪除角色 Modal（先列出該角色人員，可轉移角色，需輸入大寫 Y） -->
<div class="modal fade" id="roleDeleteModal" tabindex="-1" role="dialog" style="z-index:10600;">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title text-danger"><i class="fa fa-trash"></i> 刪除角色：<span id="del-role-name"></span></h4></div>
        <div class="modal-body">
            <div id="del-role-users" style="margin-bottom:10px;"></div>
            <div class="form-group" id="del-transfer-wrap" style="display:none;">
                <label>將上列人員轉移到角色：</label>
                <select id="del-transfer-role" class="form-control"></select>
                <p class="help-block">選「不轉移」則僅移除這些人員的此角色指派。</p>
            </div>
            <div class="form-group">
                <label class="text-danger">此操作無法復原。確認請輸入大寫 <b>Y</b>：</label>
                <input id="del-confirm-y" class="form-control" maxlength="1" style="width:80px;" autocomplete="off">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-danger" id="btn-confirm-del-role"><i class="fa fa-trash"></i> 確定刪除角色</button>
        </div>
    </div></div>
</div>

<!-- 權限設定 Modal（角色↔功能；沿用既有 roles 框架） -->
<div class="modal fade" id="permModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-key"></i> 權限設定（角色 → QC 功能）</h4></div>
        <div class="modal-body">
            <p class="text-muted" style="font-size:13px;">勾選各角色可使用的 QC 功能。<b>人員對應角色請至「人員權限(user_permissions)」頁面設定。</b>系統管理員角色預設擁有全部權限。</p>
            <div class="row">
                <div class="col-md-4">
                    <label>角色</label>
                    <div style="margin-bottom:6px;">
                        <button class="btn btn-xs btn-success" id="btn-add-role"><i class="fa fa-plus"></i> 新增</button>
                        <button class="btn btn-xs btn-default" id="btn-rename-role" disabled><i class="fa fa-pencil"></i> 重新命名</button>
                        <button class="btn btn-xs btn-danger" id="btn-delete-role" disabled><i class="fa fa-trash"></i> 刪除</button>
                    </div>
                    <div class="list-group" id="perm-role-list" style="max-height:320px;overflow:auto;"></div>
                </div>
                <div class="col-md-8">
                    <label>此角色可用的 QC 功能</label>
                    <div id="perm-feature-box" style="border:1px solid #eee;padding:10px;min-height:200px;">
                        <p class="text-muted">← 請先選擇角色</p>
                    </div>
                    <div class="text-right" style="margin-top:10px;">
                        <button class="btn btn-primary btn-sm" id="btn-save-perm" disabled><i class="fa fa-save"></i> 儲存此角色設定</button>
                    </div>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 主管審核自動核可設定 Modal（權限：qc_print_approve_setting，管理員固定可用） -->
<div class="modal fade" id="approveCfgModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#F0A24B;color:#4A3524;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-check-square-o"></i> 主管審核自動核可設定</h4></div>
        <div class="modal-body">
            <p class="muted-help">勾選後，列印的檢驗記錄表「主管審核」欄會自動蓋上指定主管的簽章；
               簽章日期比照檢驗者的認定方式（已存檔的紀錄用<b>檢驗日</b>、尚未存檔用<b>列印日</b>）。
               若當日該主管已由代理人代理，系統會自動改蓋代理人的章，並在章的右下角加「<b>代</b>」字。</p>
            <label style="font-weight:normal;"><input type="checkbox" id="ac-on"> 啟用主管審核自動核可</label>
            <div class="form-group" style="margin-top:8px;">
                <label>核可主管</label>
                <select id="ac-user" class="form-control input-sm" data-eg-filter="輸入姓名篩選…"></select>
                <p class="muted-help" style="margin-top:4px;">名單＝<b>品管部門（含子部門）底下所有主管</b>，依職級由大到小排列；
                   品管部門是哪一個部門取自 <a href="../admin/org_role_setting.php" target="_blank">系統管理 → 組織角色設定</a>。</p>
                <p class="text-danger" id="ac-hint" style="display:none;"></p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-warning" id="ac-save"><i class="fa fa-save"></i> 儲存</button>
        </div>
    </div></div>
</div>

<!-- 量具設定 Modal（種類 + 編號；與 inspection_standard_setting.php 共用資料表） -->
<div class="modal fade" id="toolManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-wrench"></i> 量具設定</h4></div>
        <div class="modal-body">
            <div class="row">
                <div class="col-md-5" style="border-right:1px solid #eee;">
                    <h4>1. 量具種類</h4>
                    <form id="tool-cat-form" class="form-inline" style="margin-bottom:10px;">
                        <input type="hidden" id="tc-id">
                        <div class="form-group"><input type="text" id="tc-name" class="form-control input-sm" placeholder="種類名稱 (如: 游標卡尺)" required></div>
                        <button type="submit" class="btn btn-primary btn-sm" id="btn-save-tc">新增</button>
                        <button type="button" class="btn btn-default btn-sm" id="btn-cancel-tc" style="display:none;">取消</button>
                    </form>
                    <div class="list-group" id="tool-cat-list" style="max-height:400px;overflow:auto;"></div>
                </div>
                <div class="col-md-7">
                    <h4>2. 量具編號</h4>
                    <div id="tool-instance-area" style="display:none;">
                        <p class="text-info">當前選擇種類：<strong id="current-cat-name"></strong></p>
                        <form id="tool-inst-form" class="form-inline" style="margin-bottom:10px;">
                            <input type="hidden" id="ti-id"><input type="hidden" id="ti-cat-id">
                            <div class="form-group"><input type="text" id="ti-no" class="form-control input-sm" placeholder="量具編號 (如: C01)" required></div>
                            <button type="submit" class="btn btn-success btn-sm" id="btn-save-ti">新增編號</button>
                            <button type="button" class="btn btn-default btn-sm" id="btn-cancel-ti" style="display:none;">取消</button>
                        </form>
                        <table class="table table-striped table-bordered table-condensed">
                            <thead><tr><th>編號</th><th width="80">操作</th></tr></thead>
                            <tbody id="tool-inst-list"></tbody>
                        </table>
                    </div>
                    <div id="tool-instance-empty" class="text-muted" style="padding-top:50px;text-align:center;">
                        <i class="fa fa-arrow-left"></i> 請先從左側選擇一個量具種類
                    </div>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 量具取代並刪除 Modal -->
<div class="modal fade" id="toolReplaceModal" tabindex="-1" role="dialog" style="z-index:10600;">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title">取代並刪除量具</h4></div>
        <div class="modal-body">
            <p class="text-danger">您即將刪除：<strong id="replace-old-name"></strong></p>
            <p>請選擇要將現有資料轉移到哪個量具種類：</p>
            <input type="hidden" id="replace-old-id">
            <select id="replace-new-id" class="form-control"></select>
            <p class="help-block"><small>執行後，舊種類將被刪除，所有關聯的檢驗項目與量具編號將移至新種類。</small></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-danger" id="btn-confirm-replace">確認取代並刪除</button>
        </div>
    </div></div>
</div>

<!-- 幾何公差管理 Modal -->
<div class="modal fade" id="specialItemManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-cog"></i> 幾何公差與特殊項目設定</h4></div>
        <div class="modal-body">
            <div class="well well-sm">
                <form id="special-item-form" class="form-inline">
                    <input type="hidden" id="si-id">
                    <div class="form-group"><input type="text" id="si-name" class="form-control input-sm" placeholder="名稱 (如: 真圓度)" required></div>
                    <div class="form-group"><input type="text" id="si-symbol" class="form-control input-sm" placeholder="符號 (如: ○)" size="5"></div>
                    <div class="form-group"><input type="text" id="si-code" class="form-control input-sm" placeholder="代碼/英文" size="10"></div>
                    <button type="submit" class="btn btn-primary btn-sm" id="btn-save-si">新增</button>
                    <button type="button" class="btn btn-default btn-sm" id="btn-cancel-si" style="display:none;">取消</button>
                </form>
            </div>
            <div class="list-group" id="manage-special-list" style="max-height:300px;overflow:auto;"></div>
        </div>
    </div></div>
</div>

<!-- 通用樣板管理 Modal -->
<div class="modal fade" id="templateManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-list-alt"></i> 通用檢驗樣板管理</h4></div>
        <div class="modal-body">
            <div class="alert alert-info" style="font-size:13px;">請先在主畫面「③ 檢驗項目」表格填好項目，再點「從當前表格建立樣板」。點「編輯」會把樣板載入主畫面表格修改後更新。</div>
            <div class="input-group" style="margin-bottom:10px;">
                <input type="text" id="new-template-name" class="form-control" placeholder="輸入新樣板名稱 (例如: 一般車件標準)">
                <span class="input-group-btn">
                    <button class="btn btn-default" type="button" id="btn-cancel-edit-template" style="display:none;">取消編輯</button>
                    <button class="btn btn-success" type="button" id="btn-create-template">從當前表格建立樣板</button>
                </span>
            </div>
            <hr style="margin:10px 0;">
            <div class="list-group" id="template-list"></div>
        </div>
    </div></div>
</div>

<!-- 抽樣規則設定 Modal -->
<div class="modal fade" id="samplingRuleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-list-ol"></i> 抽樣規則設定</h4></div>
        <div class="modal-body">
            <p class="text-muted" style="font-size:13px;">依「本批送驗數」落在哪個範圍決定建議抽驗數（載入待驗項目時自動帶入）。</p>
            <form id="rule-form" class="form-inline well well-sm">
                <input type="hidden" id="rule-id">
                <input type="number" id="rule-min" class="form-control input-sm" placeholder="最小數量" style="width:90px;" required>
                ~
                <input type="number" id="rule-max" class="form-control input-sm" placeholder="最大數量" style="width:90px;" required>
                ：抽
                <input type="number" id="rule-sample" class="form-control input-sm" placeholder="數量" style="width:70px;" required>
                <button type="submit" class="btn btn-primary btn-sm" id="btn-save-rule">新增</button>
                <button type="button" class="btn btn-default btn-sm" id="btn-cancel-rule" style="display:none;">取消</button>
            </form>
            <table class="table table-striped table-bordered table-condensed">
                <thead><tr><th>數量範圍</th><th>抽驗數</th><th width="110">操作</th></tr></thead>
                <tbody id="rule-list"></tbody>
            </table>
        </div>
    </div></div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<?php include '../QA/qa_abnormal_modal.php'; // 共用「開立品質異常單」跳窗元件（QAAbnormalModal） ?>

<!-- 異常單回覆部門設定 Modal（自 IR_Track 移入 2026-07-06；開單跳窗的「回覆部門」清單來源） -->
<div class="modal fade" id="qaDeptCfgModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-sitemap"></i> 品質異常單 — 可用部門設定</h4>
        </div>
        <div class="modal-body" style="max-height:64vh;overflow-y:auto;">
            <p class="text-muted" style="font-size:12.5px;"><i class="fa fa-info-circle"></i> 勾選的部門會出現在「開立異常單」跳窗的回覆部門清單並預設勾選；右側模式決定可指定人員的範圍。</p>
            <div id="qadept_cfg_container"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="btn-qadept-save">儲存設定</button>
        </div>
    </div></div>
</div>

<!-- 異常單處置決策設定 Modal（品管單位部門／首要決策者／最終決策者；代理人沿用 HR 使用者代理設定） -->
<div class="modal fade" id="qaDecideCfgModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-gavel"></i> 異常單處置決策設定</h4>
        </div>
        <div class="modal-body" style="max-height:66vh;overflow-y:auto;">
            <div class="form-group">
                <label>品管單位的部門 <small class="text-muted">（屬這些部門的可判定人員，系統會預設建議為首要決策者）</small></label>
                <div id="qadc_depts" style="max-height:150px;overflow-y:auto;border:1px solid #eee;border-radius:4px;padding:6px 10px;display:grid;grid-template-columns:1fr 1fr;"></div>
            </div>
            <div class="form-group">
                <label>首要決策者 <small class="text-muted">（異常單開立後優先送其判定）</small></label>
                <select class="form-control input-sm" id="qadc_primary"><option value="">請選擇...</option></select>
                <div id="qadc_primary_dep" style="font-size:12px;color:#5a6b7b;margin-top:3px;"></div>
            </div>
            <div class="form-group">
                <label>最終決策者 <small class="text-muted">（首要決策者判定「需最終裁決」或處置含「轉總經理裁示」時通知；裁決寫入總經理裁示欄位）</small></label>
                <select class="form-control input-sm" id="qadc_final"><option value="">請選擇...</option></select>
                <div id="qadc_final_dep" style="font-size:12px;color:#5a6b7b;margin-top:3px;"></div>
            </div>
            <div class="form-group">
                <label>次要決策者 <small class="text-muted">（首要決策者「請假」時，與首要決策者代理人一同收到判定通知、可代為判定；勾選並以 ↑↓ 排序。代理人同時具判定功能者，是否列為次要決策者在此設定）</small></label>
                <div id="qadc_secondary" style="border:1px solid #eee;border-radius:4px;padding:6px 10px;max-height:200px;overflow-y:auto;"></div>
            </div>
            <p class="text-muted" style="font-size:12px;">
                <i class="fa fa-info-circle"></i> 候選名單＝具「勾選/回覆異常處置」功能之人員；<b>屬品管單位者標示【品管】並建議設為首要決策者</b>。
                代理人沿用「人資設定 → 使用者代理設定」（user_delegate，含代理期間與順序），此處僅顯示不可修改。
                決策者當日尚有行程時，系統會同時通知其代理人（附行程時段），任一人判定後其他人即無須處理。
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qadc_save"><i class="fa fa-save"></i> 儲存設定</button>
        </div>
    </div></div>
</div>

<!-- 異常單處置決策 Modal（首要判定 / 最終裁決；由決策通知點入 ?decide_abnormal=N） -->
<div class="modal fade" id="qaDecideModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#c77c1a;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-gavel"></i> <span id="qadm_title">處置判定</span> — <span id="qadm_no"></span></h4>
        </div>
        <div class="modal-body" style="max-height:66vh;overflow-y:auto;">
            <table class="table table-bordered" style="font-size:13px;margin-bottom:10px;">
                <tr><th style="width:100px;background:#f8f9fa;">開單人</th><td id="qadm_creator"></td></tr>
                <tr><th style="background:#f8f9fa;">來源</th><td id="qadm_src"></td></tr>
                <tr><th style="background:#f8f9fa;">異常現象</th><td id="qadm_phen" style="white-space:pre-line;"></td></tr>
                <tr><th style="background:#f8f9fa;">原因說明</th><td id="qadm_detail" style="white-space:pre-line;"></td></tr>
                <tr id="qadm_prim_row" style="display:none;"><th style="background:#f8f9fa;">首要判定</th><td id="qadm_prim"></td></tr>
            </table>
            <div class="form-group">
                <label id="qadm_opt_label">處置方式 <span style="color:#d9534f;">*</span></label>
                <div id="qadm_opts" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            </div>
            <div class="form-group">
                <label id="qadm_note_label">處置說明</label>
                <textarea class="form-control" id="qadm_note" rows="3"></textarea>
            </div>
            <div class="form-group" id="qadm_final_wrap">
                <label style="font-weight:normal;cursor:pointer;"><input type="checkbox" id="qadm_need_final"> 需送「最終決策者」裁決 <small class="text-muted">（勾選「轉總經理裁示」時自動送出）</small></label>
            </div>
            <div style="text-align:right;">
                <a id="qadm_view" target="_blank" style="font-size:12.5px;">開啟異常單完整內容 <i class="fa fa-external-link"></i></a>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qadm_submit"><i class="fa fa-check"></i> 送出判定</button>
        </div>
    </div></div>
</div>

<!-- 異常單修改請求 Modal（無修改權限時：通知主管要求開放修改，原因必填） -->
<div class="modal fade" id="qaEditReqModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#c77c1a;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-lock"></i> 無修改權限 — 異常單 <span id="qaer-no"></span></h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <p>您目前沒有修改此異常單的權限（僅 管理員／QC主管／開單人／共同編輯者 可直接修改）。</p>
            <p><b>是否通知主管，要求開放修改此異常單？</b><br>
               <small class="text-muted">主管核准後僅您本人可修改，其他使用者仍不可修改。</small></p>
            <div class="form-group">
                <label>修改原因 <span style="color:#d9534f;">*</span></label>
                <textarea class="form-control" id="qaer-reason" rows="3" maxlength="255" placeholder="請說明需要修改此異常單的原因（必填）..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-warning" id="qaer-send"><i class="fa fa-paper-plane"></i> 通知主管要求開放修改</button>
        </div>
    </div></div>
</div>

<!-- 異常單修改請求核准 Modal（主管由通知點入，可快速開放修改） -->
<div class="modal fade" id="qaEditApproveModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-unlock-alt"></i> 異常單修改請求 — <span id="qaea-no"></span></h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <table class="table table-bordered" style="margin-bottom:10px;font-size:13px;">
                <tr><th style="width:110px;background:#f8f9fa;">請求人</th><td id="qaea-requester"></td></tr>
                <tr><th style="background:#f8f9fa;">修改原因</th><td id="qaea-reason" style="white-space:pre-line;"></td></tr>
                <tr><th style="background:#f8f9fa;">提出時間</th><td id="qaea-time"></td></tr>
                <tr><th style="background:#f8f9fa;">狀態</th><td id="qaea-status"></td></tr>
            </table>
            <p class="text-muted" style="font-size:12.5px;"><i class="fa fa-info-circle"></i> 「開放修改」後，<b>僅提出請求的使用者本人</b>可修改此異常單，其他使用者不可修改；所有修改皆會留下編輯記錄。</p>
        </div>
        <div class="modal-footer">
            <a class="btn btn-default pull-left" id="qaea-view" target="_blank"><i class="fa fa-file-text-o"></i> 檢視異常單</a>
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
            <button type="button" class="btn btn-success" id="qaea-approve"><i class="fa fa-unlock"></i> 開放修改</button>
        </div>
    </div></div>
</div>

<script>
$(function(){
    'use strict';
    var QA_API = '../../src/store/store_QA_Abnormal_API.php';
    var qs = new URLSearchParams(location.search);
    var qaEditAb  = parseInt(qs.get('edit_abnormal') || '', 10);
    var qaEditReq = parseInt(qs.get('edit_request') || '', 10);

    // 由公告/通知「編輯」或核准通知進入：開啟指定異常單的修改畫面（先檢查權限）
    if (qaEditAb){
        $.post(QA_API, {action:'check_edit_perm', id:qaEditAb}, function(r){
            if (!r || !r.success){ alert('無法檢查異常單修改權限：' + ((r && r.message) || '')); return; }
            if (r.can_edit){
                QAAbnormalModal.openEdit(qaEditAb, { title_suffix: r.order_no || '' });
            } else {
                $('#qaer-no').text(r.order_no || ('#' + qaEditAb));
                $('#qaer-reason').val('');
                $('#qaEditReqModal').data('oid', qaEditAb).modal('show');
            }
        }, 'json');
    }

    // ============ 處置決策（首要判定 / 最終裁決；?decide_abnormal=N 由決策通知點入） ============
    var qaDecideAb = parseInt(qs.get('decide_abnormal') || '', 10);
    var qadmStage = 'primary';
    var DISP_OPTS = ['特採','報廢','重工','需矯正','轉總經理裁示'];
    var GM_OPTS = ['特採','重工','報廢','需矯正'];
    function qadmRenderOpts(list, checkedCsv){
        var checked = String(checkedCsv || '').split(/[,、]/).map(function(s){ return s.trim(); });
        $('#qadm_opts').html(list.map(function(o){
            return '<label style="display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:13px;background:#f9f9f9;font-weight:normal;margin:0;">'
                 + '<input type="checkbox" class="qadm-opt" value="'+o+'" '+(checked.indexOf(o)>=0?'checked':'')+' style="margin:0;"> '+o+'</label>';
        }).join(''));
    }
    if (qaDecideAb){
        $.post(QA_API, {action:'get_decide_context', id:qaDecideAb}, function(r){
            if (!r || !r.success){ alert((r && r.message) || '載入決策情境失敗'); return; }
            if (!r.stage){
                if (confirm('此異常單目前沒有待決策事項（可能已完成判定）。要開啟異常單檢視頁嗎？')) window.open('../QA/qa_abnormal_view.php?id=' + qaDecideAb);
                return;
            }
            if (!r.allowed){ alert('您不是此階段的決策者或其代理人，無法判定。'); return; }
            qadmStage = r.stage;
            var o = r.order;
            $('#qadm_no').text(o.no || '');
            $('#qadm_creator').text(o.created_by_name || '');
            $('#qadm_src').text(o.source_desc || '');
            $('#qadm_phen').text(o.phenomenon || '');
            $('#qadm_detail').text(o.defect_detail || '');
            $('#qadm_view').attr('href', '../QA/qa_abnormal_view.php?id=' + o.id);
            if (r.stage === 'final'){
                $('#qadm_title').text('最終裁決');
                $('#qadm_opt_label').html('最終裁決 <small class="text-muted">（寫入總經理裁示欄位）</small>');
                $('#qadm_note_label').text('裁決說明');
                $('#qadm_prim_row').show();
                $('#qadm_prim').text((o.disposition || '').replace(/,/g, '、') + (o.disposition_note ? '｜' + o.disposition_note : ''));
                $('#qadm_final_wrap').hide();
                qadmRenderOpts(GM_OPTS, o.gm_decision);
                $('#qadm_note').val(o.gm_note || '');
            } else {
                $('#qadm_title').text('處置判定');
                $('#qadm_opt_label').html('處置方式 <span style="color:#d9534f;">*</span>');
                $('#qadm_note_label').text('處置說明');
                $('#qadm_prim_row').hide();
                $('#qadm_final_wrap').show();
                $('#qadm_need_final').prop('checked', false);
                qadmRenderOpts(DISP_OPTS, o.disposition);
                $('#qadm_note').val(o.disposition_note || '');
            }
            $('#qaDecideModal').data('oid', o.id).modal('show');
        }, 'json');
    }
    $('#qadm_submit').on('click', function(){
        var oid = $('#qaDecideModal').data('oid');
        var picked = $('.qadm-opt:checked').map(function(){ return this.value; }).get().join(',');
        var note = ($('#qadm_note').val() || '').trim();
        var data = { action:'decide', id:oid, stage:qadmStage };
        if (qadmStage === 'final'){
            if (!picked && !note){ alert('請勾選裁決或填寫裁決說明'); return; }
            data.gm_decision = picked; data.gm_note = note;
        } else {
            if (!picked){ alert('請至少勾選一項處置方式'); return; }
            data.disposition = picked; data.disposition_note = note;
            data.need_final = ($('#qadm_need_final').is(':checked') || picked.indexOf('轉總經理裁示') >= 0) ? 1 : 0;
        }
        var $b = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 送出中…');
        $.post(QA_API, data, function(r){
            $b.prop('disabled', false).html('<i class="fa fa-check"></i> 送出判定');
            if (!r || !r.success){ alert((r && r.message) || '送出失敗'); return; }
            $('#qaDecideModal').modal('hide');
            var msg = (qadmStage === 'final' ? '最終裁決已送出。' : '處置判定已送出。') + '相關人員的決策通知已自動結束。';
            if (r.warn) msg += '\n\n⚠ ' + r.warn;
            alert(msg);
        }, 'json').fail(function(){
            $b.prop('disabled', false).html('<i class="fa fa-check"></i> 送出判定');
            alert('連線失敗');
        });
    });

    // ============ 處置決策設定（品管單位部門／首要／最終決策者） ============
    $('#btn-qadecide-setting').on('click', function(e){
        e.preventDefault();
        $.post(QA_API, {action:'get_decision_setting'}, function(r){
            if (!r || !r.success){ alert('載入設定失敗'); return; }
            var qc = r.qc_dept_ids || [];
            // 品管部門已改由全站「組織角色綁定設定」決定（含子部門）→ 本頁一律反灰唯讀（2026-08-03）
            $('#qadc_depts').html((r.departments || []).map(function(d){
                return '<label style="font-weight:normal;margin:0 0 3px;color:#999;cursor:not-allowed;">'
                     + '<input type="checkbox" class="qadc-dept" value="'+d.id+'" '+(qc.indexOf(d.id)>=0?'checked':'')
                     + ' disabled> '+$('<i>').text(d.name).html()+'</label>';
            }).join('')
            + '<div style="grid-column:1/-1;font-size:12px;color:#8a6d45;margin-top:4px;">此項目已統一由'
            + '<a href="../admin/org_role_setting.php" target="_blank"><b>組織角色綁定設定</b></a>'
            + '的「品管部門」決定（含其子部門），僅能在該頁修改。</div>');
            function poolOpts(sel){
                var h = '<option value="">請選擇...</option>';
                (r.pool || []).forEach(function(p){
                    h += '<option value="'+p.id+'" data-dep="'+$('<i>').text((p.deputies||[]).map(function(x){return x.name;}).join('、')).html()+'">'
                       + (p.in_qc ? '【品管】' : '') + $('<i>').text(p.user_cname).html() + '</option>';
                });
                return h;
            }
            $('#qadc_primary').html(poolOpts()).val(String(r.primary || ''));
            $('#qadc_final').html(poolOpts()).val(String(r.final || ''));
            // ── 次要決策者：勾選＋排序（首要/最終本人不可勾；標示其是否為首要/最終的今日代理人）──
            (function renderSecondary(){
                var byId = {};
                (r.pool || []).forEach(function(p){ byId[p.id] = p; });
                var primDeps = (byId[r.primary] ? (byId[r.primary].deputies || []) : []).map(function(d){ return d.id; });
                var finDeps  = (byId[r.final]   ? (byId[r.final].deputies   || []) : []).map(function(d){ return d.id; });
                var sec = (r.secondary || []).filter(function(id){ return byId[id]; });
                var ordered = sec.concat((r.pool || []).map(function(p){ return p.id; }).filter(function(id){ return sec.indexOf(id) < 0; }));
                $('#qadc_secondary').html(ordered.map(function(id){
                    var p = byId[id];
                    var badge = '';
                    if (primDeps.indexOf(id) >= 0) badge += ' <span class="label label-primary">首要今日代理人</span>';
                    if (finDeps.indexOf(id)  >= 0) badge += ' <span class="label label-warning">最終今日代理人</span>';
                    return '<div class="qadc-sec-row" data-id="'+id+'" style="display:flex;align-items:center;gap:8px;padding:3px 0;border-bottom:1px solid #f5f5f5;">'
                         + '<label style="font-weight:normal;margin:0;cursor:pointer;flex:1;">'
                         + '<input type="checkbox" class="qadc-sec-chk" value="'+id+'" '+(sec.indexOf(id)>=0?'checked':'')+'> '
                         + (p.in_qc ? '【品管】' : '') + $('<i>').text(p.user_cname).html() + badge + '</label>'
                         + '<span class="qadc-sec-mv">'
                         + '<button type="button" class="btn btn-xs btn-default qadc-sec-up"><i class="fa fa-arrow-up"></i></button> '
                         + '<button type="button" class="btn btn-xs btn-default qadc-sec-down"><i class="fa fa-arrow-down"></i></button>'
                         + '</span></div>';
                }).join('') || '<span class="text-muted">尚無可判定人員</span>');
                function applySecDisable(){
                    var pid = String($('#qadc_primary').val() || ''), fid = String($('#qadc_final').val() || '');
                    $('#qadc_secondary .qadc-sec-row').each(function(){
                        var id = String($(this).data('id'));
                        var isPF = (id === pid || id === fid);
                        $(this).find('.qadc-sec-chk').prop('disabled', isPF);
                        if (isPF) $(this).find('.qadc-sec-chk').prop('checked', false);
                        $(this).css('opacity', isPF ? .5 : 1).attr('title', isPF ? '首要/最終決策者本人不需列為次要決策者' : '');
                    });
                }
                applySecDisable();
                $('#qadc_primary, #qadc_final').off('change.sec').on('change.sec', applySecDisable);
            })();
            function showDep(sel, box){
                var dep = $(sel + ' option:selected').data('dep') || '';
                $(box).html(sel && $(sel).val() ? ('今日生效代理人：' + (dep || '（無，可至 人資設定→使用者代理設定 指定）')) : '');
            }
            showDep('#qadc_primary', '#qadc_primary_dep'); showDep('#qadc_final', '#qadc_final_dep');
            $('#qadc_primary').off('change.dep').on('change.dep', function(){ showDep('#qadc_primary', '#qadc_primary_dep'); });
            $('#qadc_final').off('change.dep').on('change.dep', function(){ showDep('#qadc_final', '#qadc_final_dep'); });
            // 預設建議：尚未設定首要時，自動帶入第一位屬品管單位的可判定人員
            if (!r.primary){
                var sug = (r.pool || []).filter(function(p){ return p.in_qc; })[0];
                if (sug) { $('#qadc_primary').val(String(sug.id)).trigger('change.dep'); }
            }
            $('#qadc_save').prop('disabled', !r.can_manage).attr('title', r.can_manage ? '' : '僅主管（角色勾選「認定為主管」）可儲存');
            $('#qaDecideCfgModal').modal('show');
        }, 'json');
    });
    // 次要決策者排序（↑↓ 移整列）
    $(document).on('click', '.qadc-sec-up, .qadc-sec-down', function(){
        var $row = $(this).closest('.qadc-sec-row');
        if ($(this).hasClass('qadc-sec-up')) { var $prev = $row.prev('.qadc-sec-row'); if ($prev.length) $row.insertBefore($prev); }
        else { var $next = $row.next('.qadc-sec-row'); if ($next.length) $row.insertAfter($next); }
    });
    $('#qadc_save').on('click', function(){
        var qcDepts = $('.qadc-dept:checked').map(function(){ return parseInt(this.value, 10); }).get();
        var primaryId = $('#qadc_primary').val() || 0, finalId = $('#qadc_final').val() || 0;
        if (primaryId && primaryId === finalId){ alert('首要決策者與最終決策者不可為同一人'); return; }
        var secondary = $('#qadc_secondary .qadc-sec-chk:checked').map(function(){ return parseInt(this.value, 10); }).get();
        var $b = $(this).prop('disabled', true);
        $.post(QA_API, {action:'save_decision_setting', qc_dept_ids: JSON.stringify(qcDepts), primary: primaryId, final: finalId, secondary: JSON.stringify(secondary)}, function(r){
            $b.prop('disabled', false);
            if (!r || !r.success){ alert((r && r.message) || '儲存失敗'); return; }
            $('#qaDecideCfgModal').modal('hide');
            alert('處置決策設定已儲存');
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });

    // 送出修改請求（原因必填）→ 系統自動通知主管（主管通知內含快速開放修改按鈕）
    $('#qaer-send').on('click', function(){
        var oid = $('#qaEditReqModal').data('oid');
        var reason = ($('#qaer-reason').val() || '').trim();
        if (!reason){ alert('請填寫修改原因（必填）'); $('#qaer-reason').focus(); return; }
        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 送出中…');
        $.post(QA_API, {action:'request_edit', id:oid, reason:reason}, function(r){
            $btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> 通知主管要求開放修改');
            if (!r || !r.success){ alert('送出失敗：' + ((r && r.message) || '')); return; }
            $('#qaEditReqModal').modal('hide');
            if (r.no_supervisor){ alert(r.message || '目前尚無角色被勾選「認定為主管」，請洽管理員設定。'); }
            else alert('已通知主管。主管開放修改後，您會收到通知（點通知可直接進入修改畫面）。');
        }, 'json').fail(function(){
            $btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> 通知主管要求開放修改');
            alert('連線失敗');
        });
    });

    // 主管由通知點入：載入修改請求並提供快速「開放修改」
    if (qaEditReq){
        $.post(QA_API, {action:'get_edit_request', id:qaEditReq}, function(r){
            if (!r || !r.success){ alert((r && r.message) || '載入修改請求失敗'); return; }
            var d = r.data;
            $('#qaea-no').text(d.abnormal_order_no || '');
            $('#qaea-requester').text(d.requester_name || ('#' + d.requested_by));
            $('#qaea-reason').text(d.reason || '');
            $('#qaea-time').text(d.created_at || '');
            $('#qaea-view').attr('href', '../QA/qa_abnormal_view.php?id=' + d.abnormal_order_id);
            if (d.status === 'approved'){
                $('#qaea-status').html('<span class="label label-success">已開放</span> ' + (d.approver_name ? '（' + d.approver_name + '）' : ''));
                $('#qaea-approve').prop('disabled', true).text('已開放修改');
            } else {
                $('#qaea-status').html('<span class="label label-warning">待處理</span>');
                $('#qaea-approve').prop('disabled', !d.is_supervisor);
                if (!d.is_supervisor) $('#qaea-approve').attr('title', '僅主管可開放修改');
            }
            $('#qaEditApproveModal').data('rid', qaEditReq).modal('show');
        }, 'json');
    }

    // ============ 異常單回覆部門設定（自 IR_Track 移入） ============
    $('#btn-qadept-setting').on('click', function(e){
        e.preventDefault();
        var $c = $('#qadept_cfg_container').html('<span class="text-muted">載入中…</span>');
        $.post(QA_API, { action:'get_all_depts' }, function(r1){
            var depts = (r1 && r1.data) || [];
            $.post(QA_API, { action:'get_dept_config' }, function(r2){
                var cfgMap = {};
                if (r2 && r2.success && r2.config) r2.config.forEach(function(c){ cfgMap[c.id] = c.mode; });
                var h = '';
                depts.forEach(function(d){
                    var on = cfgMap.hasOwnProperty(d.id);
                    var mode = on ? cfgMap[d.id] : 0;
                    h += '<div class="row" style="margin:0;border-bottom:1px solid #f0f0f0;padding:5px 0;">'
                       + '<div class="col-xs-6"><label style="font-weight:normal;margin:0;cursor:pointer;">'
                       + '<input type="checkbox" class="qadept-cfg-chk" value="'+d.id+'" '+(on?'checked':'')+'> '+$('<i>').text(d.name).html()+'</label></div>'
                       + '<div class="col-xs-6"><select class="form-control input-sm qadept-cfg-mode" style="display:'+(on?'block':'none')+';">'
                       + '<option value="0"'+(mode==0?' selected':'')+'>本部門</option>'
                       + '<option value="1"'+(mode==1?' selected':'')+'>含下級部門</option>'
                       + '<option value="2"'+(mode==2?' selected':'')+'>僅下級主管</option>'
                       + '</select></div></div>';
                });
                $c.html(h || '<span class="text-muted">尚無部門資料</span>');
                $('#qaDeptCfgModal').modal('show');
            }, 'json');
        }, 'json');
    });
    $(document).on('change', '.qadept-cfg-chk', function(){
        $(this).closest('.row').find('.qadept-cfg-mode').toggle(this.checked);
    });
    $('#btn-qadept-save').on('click', function(){
        var depts = [];
        $('.qadept-cfg-chk:checked').each(function(){
            depts.push({ dept_id: $(this).val(), mode: $(this).closest('.row').find('.qadept-cfg-mode').val() || 0 });
        });
        var $b = $(this).prop('disabled', true);
        $.post(QA_API, { action:'save_dept_config', depts: JSON.stringify(depts) }, function(res){
            $b.prop('disabled', false);
            if (res && res.success){ $('#qaDeptCfgModal').modal('hide'); alert('可用部門設定已儲存'); }
            else alert('儲存失敗：' + ((res && res.message) || ''));
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });

    // 開放修改（僅主管；核准後通知請求者，且僅該使用者可修改）
    $('#qaea-approve').on('click', function(){
        var rid = $('#qaEditApproveModal').data('rid');
        if (!confirm('確認開放修改？（僅提出請求的使用者本人可修改此異常單）')) return;
        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中…');
        $.post(QA_API, {action:'approve_edit_request', id:rid}, function(r){
            if (!r || !r.success){
                $btn.prop('disabled', false).html('<i class="fa fa-unlock"></i> 開放修改');
                alert('開放失敗：' + ((r && r.message) || ''));
                return;
            }
            $btn.html('<i class="fa fa-check"></i> 已開放修改');
            $('#qaea-status').html('<span class="label label-success">已開放</span>');
            alert('已開放修改並通知提出請求的使用者。');
        }, 'json').fail(function(){
            $btn.prop('disabled', false).html('<i class="fa fa-unlock"></i> 開放修改');
            alert('連線失敗');
        });
    });
});
</script>

<script>
$(function(){
    'use strict';
    // ★ 後端沿用舊頁的 AJAX API（同 session / 同 CSRF / 同 RBAC / 同後端重算判定）
    var API  = 'inspection_combined_prototype.php';
    var V2API= 'inspection_entry_v2.php';   // v2 專屬後端（符號主檔／抽驗數變更／臨時檢驗單／完全刪除）
    // 超級管理員＝id 1 ＋ user_status 9 ＋ 在職（後端會再驗一次，這裡只決定按鈕顯不顯示）
    var IS_SUPER = <?php echo $IS_SUPER ? 'true' : 'false'; ?>;
    var CSRF = <?php echo json_encode($CSRF, JSON_UNESCAPED_SLASHES); ?>;
    // 存檔後自動建立允收/異常彙總紀錄要用（同舊頁 _updateQC_check_list_ok/qq.php 的 id= 參數）
    var CURRENT_UID = <?php echo json_encode((int)($_SESSION['id'] ?? 0)); ?>;
    $.ajaxPrefilter(function(opts){
        var m = (opts.type || opts.method || 'GET').toUpperCase();
        if (m !== 'POST') return;
        if (typeof opts.data === 'string'){
            if (opts.data.indexOf('csrf=') === -1) opts.data += (opts.data ? '&' : '') + 'csrf=' + encodeURIComponent(CSRF);
        } else if (opts.data && typeof opts.data === 'object' && !(opts.data instanceof FormData)){
            if (opts.data.csrf === undefined) opts.data.csrf = CSRF;
        } else if (opts.data == null){ opts.data = { csrf: CSRF }; }
    });
    $('body').append('<div id="print-area"></div>');
    // 底部固定列會遮住內容（現場回報「下一項」按鈕被蓋住）→ 依 dock 實際高度撐出底部空白
    function syncDockPad(){
        var h = $('#dock').is(':visible') ? $('#dock').outerHeight() : 0;
        $('body').css('padding-bottom', (h+24)+'px');
    }
    $(window).on('resize', syncDockPad);

    // =====================================================================
    // 狀態與資料模型
    //   MODEL.items[i] = { item_id,name,std,up,lo,type,remark,
    //                      readings:[ {tool_id, tool_cat, vals:[每件一格的原始輸入]} ] }
    //   readings[0] = 主量測；readings[1..] = 「加量測」（同尺寸換量具/方法再量一次）
    //   MODEL.pcs[i]  = { v:'OK'|'NG', m:0|1 }  m=1 代表使用者手動改判
    //   ★ 三種檢視都只是這份模型的不同畫法，切換檢視不會遺失任何已填內容。
    // =====================================================================
    var ctx = null;
    // 列印設定（公司全名／AS 文件／主管自動核可）：頁面載入時取一次，列印與設定跳窗共用
    var PRINTCFG = { company:'', doc:{no:'',name:''}, auto_approve:0, approver_id:0, approver:{name:'',deputy:0}, can_cfg:false, people:[] };
    function loadPrintCfg(cb){
        $.post(V2API, { v2action:'print_cfg_get' }, function(res){
            if(res && res.success){ PRINTCFG=res; window.__ownCompany=res.company||''; }
            $('.approve-menu-item').toggle(!!(res&&res.can_cfg));
            if(cb) cb();
        },'json');
    }
    var state = { sampleN:5, batches:[], curBatch:0, processes:[], curProc:0, demo:false,
                  is_supervisor:false, can_fill:true, canManageSettings:false, canManageSampling:false,
                  canView:true, editFormId:null, draftFormId:0 };
    var MODEL = { items:[], pcs:[] };
    var TOOLS = ['卡尺','分厘卡','投影機','三次元','針規','目視'];
    var TOOL_INSTANCES = [];                                  // [{id,no,cat}]
    var view      = localStorage.getItem('qc2_view')   || 'ITEM';   // ITEM / PCS / GRID
    var keypadOn  = localStorage.getItem('qc2_keypad') === '1';
    var codeMode  = localStorage.getItem('qc_item_code_mode') || 'ALPHA';
    var focusItem = 0, focusPcs = 0;
    var lastFocused = null;                                   // 給數字鍵盤用

    function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; }); }
    function codeLabel(i){
        if (codeMode !== 'ALPHA') return String(i+1);
        var s='', n=i+1;
        while(n>0){ var r=(n-1)%26; s=String.fromCharCode(65+r)+s; n=Math.floor((n-1)/26); }
        return s;
    }
    function trimNum(v){ // 小數尾 0 省略（3.50→3.5）
        if(v===''||v==null) return '';
        var s=String(v); if(s.indexOf('.')<0) return s;
        s=s.replace(/0+$/,'').replace(/\.$/,''); return s===''||s==='-' ? '0' : s;
    }
    function blankVals(n, def){ var a=[]; for(var i=0;i<n;i++) a.push(def||''); return a; }

    // ---------- 判定（與後端 qc_inspection_lib 同一套規則；後端仍會重算為準） ----------
    function judge(it, raw){
        if(raw===''||raw==null) return '';
        if(it.type==='OKNG') return (raw==='NG') ? 'NG' : 'OK';
        var base=parseFloat(it.std), up=parseFloat(it.up), lo=parseFloat(it.lo), v=parseFloat(raw);
        if(isNaN(v)||isNaN(base)) return '';
        var hi=base+(isNaN(up)?0:up), low=base+(isNaN(lo)?0:lo);
        return (v>hi||v<low) ? 'NG' : 'OK';
    }
    function limits(it){
        var base=parseFloat(it.std);
        if(isNaN(base)) return null;
        var up=parseFloat(it.up), lo=parseFloat(it.lo);
        return { hi:base+(isNaN(up)?0:up), low:base+(isNaN(lo)?0:lo), base:base };
    }
    // 偏差量：告訴現場「離標準多少、超出多少」，不必自己心算（痛點④）
    // compact＝總表用的短版（格子窄，長文字會被切掉）：▲=超上限、▼=超下限
    function devText(it, raw, compact){
        if(it.type==='OKNG') return '';
        if(raw===''||raw==null) return '';
        var L=limits(it), v=parseFloat(raw);
        if(!L||isNaN(v)) return '';
        var d=v-L.base, sign=(d>0?'+':'');
        if(v>L.hi) return (compact?'▲':'超上限 ')+trimNum((v-L.hi).toFixed(4));
        if(v<L.low) return (compact?'▼':'超下限 ')+trimNum((L.low-v).toFixed(4));
        return sign+trimNum(d.toFixed(4));
    }
    // 疑似誤填：與標準值差異「大到不像量測誤差」（漏打小數點、多打一位、看錯量具、抄錯列）。
    // 需同時超過「公差帶的 20 倍」與「標準值的 30%」才示警——只看公差倍數的話，
    // 精密件（公差 0.005）動不動就跳警告；只看百分比的話，粗公差件又抓不到。
    var OUTLIER_TOL_X = 20, OUTLIER_STD_RATIO = 0.30;
    function isOutlier(it, raw){
        if(!it || it.type==='OKNG' || raw===''||raw==null) return false;
        var base=parseFloat(it.std), v=parseFloat(raw);
        if(isNaN(base)||isNaN(v)) return false;
        var tol=Math.max(Math.abs(parseFloat(it.up)||0), Math.abs(parseFloat(it.lo)||0));
        if(tol<=0 && base===0) return false;          // 無標準也無公差 → 無從判斷，不亂示警
        var dev=Math.abs(v-base);
        return dev > Math.max(tol*OUTLIER_TOL_X, Math.abs(base)*OUTLIER_STD_RATIO);
    }
    function cellTitle(it, raw){
        if(!it || it.type==='OKNG' || raw===''||raw==null) return '';
        var full=devText(it, raw, false);
        if(!full) return '';
        var t='偏差：'+full;
        if(isOutlier(it, raw)) t += ' ⚠ 與標準值差異過大，請確認是否誤填（漏打小數點／看錯量具／抄錯列）';
        return t;
    }
    function itemVerdict(it){
        var any=false, filled=false;
        it.readings.forEach(function(rd){
            rd.vals.forEach(function(v){
                var j=judge(it,v);
                if(j){ filled=true; if(j==='NG') any=true; }
            });
        });
        return any ? 'NG' : (filled ? 'OK' : '');
    }
    function itemFilledCount(it){
        var n=0;
        for(var s=0;s<state.sampleN;s++){
            for(var r=0;r<it.readings.length;r++){ if(it.readings[r].vals[s]!=='' && it.readings[r].vals[s]!=null){ n++; break; } }
        }
        return n;
    }
    function pcsAutoNG(s){
        for(var i=0;i<MODEL.items.length;i++){
            var it=MODEL.items[i];
            for(var r=0;r<it.readings.length;r++){ if(judge(it, it.readings[r].vals[s])==='NG') return true; }
        }
        return false;
    }

    // =====================================================================
    // 量具（實例）：值＝Tool_id，顯示「類型 / 編號(規格)」，可追溯到實際那一支
    // 規格來自校驗模組「量具料號對應」綁的採購料號（purchase_spec），沒綁就只顯示編號
    // =====================================================================
    function loadToolInstances(){
        $.post(API, { action:'get_tool_manage_data' }, function(res){
            if(!res || !res.success) return;
            var cats={}; (res.categories||[]).forEach(function(c){ cats[c.QC_Tool_List_id]=c.QC_Tool; });
            TOOL_INSTANCES = (res.tools||[]).map(function(t){
                var sp=((t.spec_brand||'')+' '+(t.spec_text||'')).replace(/\s+/g,' ').trim();
                return { id:String(t.Tool_id), no:t.Tool_No, cat:cats[t.QC_Tool_List_id]||'', spec:sp };
            });
            render();
        }, 'json');
    }
    function toolInstById(id){
        if(id==null || id==='') return null;
        for(var i=0;i<TOOL_INSTANCES.length;i++){ if(TOOL_INSTANCES[i].id===String(id)) return TOOL_INSTANCES[i]; }
        return null;
    }
    // 量具編號的統一顯示格式：編號(規格)。舊資料編號本身已內含規格（例 A-002-Q (25-50mm)）就不重複附加
    function toolNoSpec(t){
        if(!t) return '';
        var no=String(t.no||'');
        if(!t.spec) return no;
        if(no.replace(/\s+/g,'').toLowerCase().indexOf(t.spec.replace(/\s+/g,'').toLowerCase())>=0) return no;
        return no+'('+t.spec+')';
    }
    // 量具改用「按鈕 → 跳窗挑」：下拉選單選項太多又擠，現場很難點（2026-07-29 回饋）
    // compact=true（總表用）：比照列印版量具格排版，類別/規格/編號各自一行、字縮小、行距縮緊，
    // 節省總表逐列高度；逐項/逐件視圖字級較大、單一情境不必省版面，維持原本樣式
    function toolBtn(i, r, compact){
        var t = toolInstById(MODEL.items[i].readings[r].tool_id);
        if(!t){
            return '<button type="button" class="tool-btn none" data-i="'+i+'" data-r="'+r+'" title="點此選擇量具"><i class="fa fa-wrench"></i> 點此選擇量具</button>';
        }
        if(compact){
            var no=String(t.no||'').trim(), spec=t.spec||'';
            var mm=no.match(/[（(]([^）)]*)[）)]\s*$/);
            if(mm){ if(!spec) spec=mm[1].trim(); no=no.slice(0,mm.index).trim(); }
            var lines=[t.cat||'', spec, no].filter(function(s){ return s!==''; })
                      .map(function(s){ return '<span>'+esc(s)+'</span>'; }).join('');
            return '<button type="button" class="tool-btn tool-btn-compact" data-i="'+i+'" data-r="'+r+'" title="點此選擇量具">'+lines+'</button>';
        }
        return '<button type="button" class="tool-btn" data-i="'+i+'" data-r="'+r+'" title="點此選擇量具">'+
               '<span class="tcat">'+esc(t.cat||'量具')+'</span><span class="tno">'+esc(toolNoSpec(t))+'</span></button>';
    }
    function firstInstOfCat(catName){
        if(!catName) return '';
        for(var i=0;i<TOOL_INSTANCES.length;i++){ if(TOOL_INSTANCES[i].cat===catName) return TOOL_INSTANCES[i].id; }
        return '';
    }
    function toolLabelById(id){
        if(!id) return '';
        for(var i=0;i<TOOL_INSTANCES.length;i++){ if(TOOL_INSTANCES[i].id===String(id)) return (TOOL_INSTANCES[i].cat?TOOL_INSTANCES[i].cat+' / ':'')+toolNoSpec(TOOL_INSTANCES[i]); }
        return '';
    }
    function refreshToolSelects(){ render(); }   // 相容：量具設定存檔後重繪

    // =====================================================================
    // 模型 ←→ 後端資料轉換
    // =====================================================================
    function normItem(r){
        var it = { item_id:(r.item_id||''), name:(r.name||''), std:(r.std==null?'':String(r.std)),
                   up:(r.up==null?'':String(r.up)), lo:(r.lo==null?'':String(r.lo)),
                   type:(r.type==='OKNG'?'OKNG':'NUM'), remark:(r.remark||''), readings:[] };
        var tid = (r.tool_id!=null && r.tool_id!=='') ? String(r.tool_id) : firstInstOfCat(r.tool||'');
        it.readings.push({ tool_id:tid, tool_cat:(r.tool||''), vals:valsFrom(r.samples, it.type) });
        (r.extra||[]).forEach(function(ex){
            it.readings.push({ tool_id:(ex.tool_id!=null && ex.tool_id!=='' ? String(ex.tool_id) : ''),
                               tool_cat:(ex.method||''), vals:valsFrom(ex.samples, it.type) });
        });
        return it;
    }
    function valsFrom(samples, type){
        // OK/NG 型沿用舊行為：未特別標示者視為 OK（現場只在不良時才標記）
        var a = blankVals(state.sampleN, type==='OKNG' ? 'OK' : '');
        (samples||[]).forEach(function(s,i){
            if(i>=a.length) return;
            var v = (s && typeof s==='object') ? s.v : s;
            a[i] = (v==null) ? '' : String(v);
        });
        return a;
    }
    function newItem(){
        return { item_id:'', name:'', std:'', up:'', lo:'', type:'NUM', remark:'',
                 readings:[{ tool_id:'', tool_cat:'', vals:blankVals(state.sampleN) }] };
    }
    function setSampleN(n){
        n = Math.max(1, parseInt(n)||1);
        if(n===state.sampleN) return;
        state.sampleN = n;
        MODEL.items.forEach(function(it){
            it.readings.forEach(function(rd){
                var def = (it.type==='OKNG') ? 'OK' : '';
                while(rd.vals.length<n) rd.vals.push(def);
                rd.vals.length = n;
            });
        });
        MODEL.pcs.length = 0;
        ensurePcs();
        if(focusPcs>=n) focusPcs=n-1;
        render();
    }
    function ensurePcs(){
        while(MODEL.pcs.length < state.sampleN) MODEL.pcs.push({ v:'OK', m:0 });
        MODEL.pcs.length = state.sampleN;
    }

    // 相容介面（供沿用自舊頁的「通用樣板管理」等程式碼呼叫）
    function renderItems(items){
        MODEL.items = (items||[]).map(normItem);
        MODEL.pcs = []; ensurePcs();
        focusItem = 0; focusPcs = 0;
        render();
    }
    // 送出用：完全維持舊頁 payload 格式（後端不用改）
    // 差異：數值型不再丟掉空格（改送完整長度陣列），空值後端會略過，
    //       修正舊版「第1件沒量、第2件的值被存成 sample_no=1」的錯位問題。
    function collectItems(){
        var out=[];
        MODEL.items.forEach(function(it){
            var name=(it.name||'').trim();
            if(!name) return;
            var mk=function(rd){
                var arr=[];
                for(var s=0;s<state.sampleN;s++){
                    var raw=rd.vals[s];
                    if(raw===''||raw==null){ arr.push({v:'', r:'OK'}); continue; }
                    var j=judge(it,raw);
                    arr.push({ v:String(raw), r:(j==='NG'?'NG':'OK') });
                }
                return arr;
            };
            var extra=[];
            for(var r=1;r<it.readings.length;r++){
                var rd=it.readings[r], s2=mk(rd);
                var any=s2.some(function(x){ return x.v!==''; });
                if(any || rd.tool_id) extra.push({ tool_id:rd.tool_id||'', samples:s2 });
            }
            out.push({
                item_id:it.item_id||'', name:name, std:it.std, up:it.up, lo:it.lo,
                tool_id:(it.readings[0].tool_id||''), tool:'',
                type:it.type, verdict:(itemVerdict(it)==='NG'?'NG':'OK'),
                samples:mk(it.readings[0]), extra:extra, remark:it.remark||''
            });
        });
        return out;
    }
    function collectPcsVerdicts(){ ensurePcs(); return MODEL.pcs.map(function(p){ return { v:p.v, m:p.m?1:0 }; }); }

    // =====================================================================
    // 繪製
    // =====================================================================
    function render(){
        ensurePcs();
        $('.view-switch button').removeClass('on').filter('[data-view="'+view+'"]').addClass('on');
        $('#view-item').toggle(view==='ITEM');
        $('#view-pcs').toggle(view==='PCS');
        $('#view-grid').toggle(view==='GRID');
        $('#view-hint').text(view==='ITEM' ? '一次專注一個尺寸：量完所有件再換下一個尺寸（換量具最少）'
                          : view==='PCS'  ? '一次專注一件：把該件所有尺寸量完再換下一件'
                                          : '格狀總表：Enter／方向鍵可連續輸入，適合桌機');
        if(view==='ITEM') renderItemView();
        else if(view==='PCS') renderPcsView();
        renderGrid();               // 總表恆繪（隱藏時也在 DOM，供列印/樣板等功能取用）
        $('#btn-code-mode2').html(codeMode==='ALPHA' ? '編號 <b>A,B,C</b> ⇄ 1,2,3' : '編號 <b>1,2,3</b> ⇄ A,B,C');
        recalc();
    }

    // ---------- 量測格 ----------
    function cellHtml(it, i, r, s, big){
        var raw = it.readings[r].vals[s];
        var cls, inner;
        if(it.type==='OKNG'){
            var v = (raw==='NG') ? 'NG' : (raw===''||raw==null ? '' : 'OK');
            cls = v==='NG' ? 'c-ng' : (v==='OK' ? 'c-ok' : 'c-empty');
            inner = '<span class="mtxt">'+(v==='NG'?'✘ NG':(v==='OK'?'✔ OK':'—'))+'</span>';
            return '<div class="mcell okng '+cls+'" data-i="'+i+'" data-r="'+r+'" data-s="'+s+'" tabindex="0" title="點擊切換 OK / NG">'+
                   '<span class="mno">#'+(s+1)+'</span>'+inner+'</div>';
        }
        var j = judge(it, raw), warn = isOutlier(it, raw);
        cls = j==='NG' ? 'c-ng' : (j==='OK' ? 'c-ok' : 'c-empty');
        if(warn) cls += ' c-warn';
        // 三種檢視的格子已統一為小格 → 一律用短版偏差文字，完整說明放 title
        return '<div class="mcell '+cls+'" data-i="'+i+'" data-r="'+r+'" data-s="'+s+'" title="'+esc(cellTitle(it,raw))+'">'+
               '<span class="mno">#'+(s+1)+'</span>'+
               '<input type="text" inputmode="decimal" class="mval" value="'+esc(raw)+'" '+
                      'data-i="'+i+'" data-r="'+r+'" data-s="'+s+'">'+
               '<span class="mdev">'+(warn?'⚠ ':'')+esc(devText(it,raw,true))+'</span></div>';
    }
    // 只更新單一格的外觀，避免重繪打斷輸入焦點
    function paintCell($cell){
        var i=+$cell.data('i'), r=+$cell.data('r'), s=+$cell.data('s');
        var it=MODEL.items[i]; if(!it) return;
        var raw=it.readings[r].vals[s];
        var j = (it.type==='OKNG') ? ((raw==='NG')?'NG':(raw===''?'':'OK')) : judge(it, raw);
        var warn = isOutlier(it, raw);
        var repaint=function($c){
            $c.removeClass('c-ok c-ng c-empty c-warn').addClass(j==='NG'?'c-ng':(j==='OK'?'c-ok':'c-empty'));
            if(warn) $c.addClass('c-warn');
            $c.attr('title', cellTitle(it, raw));
            if(it.type==='OKNG') $c.find('.mtxt').text(j==='NG'?'✘ NG':(j==='OK'?'✔ OK':'—'));
            else $c.find('.mdev').text((warn?'⚠ ':'')+devText(it, raw, true));
        };
        repaint($cell);
        // 同一格在別的檢視也要同步（總表恆在 DOM）
        $('.mcell[data-i="'+i+'"][data-r="'+r+'"][data-s="'+s+'"]').not($cell).each(function(){
            $(this).find('.mval').val(raw);
            repaint($(this));
        });
        updateItemVerdictCell(i);
    }
    // 總表「判定」欄：輸入後要立刻跟著變（原本只在整頁重繪時才更新，會停在「—」）
    function updateItemVerdictCell(i){
        var it=MODEL.items[i]; if(!it) return;
        var v=itemVerdict(it);
        $('#items-body td.g-verdict[data-i="'+i+'"]')
            .css('color', v==='NG'?'var(--coral)':'var(--ink)')
            .text(v==='NG'?'✘ NG':(v==='OK'?'✔ OK':'—'));
    }

    // ---------- 規格帶（標準/上限/下限/量具） ----------
    function specBar(it, i){
        var h='<div class="specbar">';
        if(it.type==='OKNG'){
            h+='<div class="spec std"><div class="k">判定方式</div><div class="v">OK / NG</div></div>'+
               '<div class="spec lim" style="min-width:200px;"><div class="k">判定基準</div><div class="v" style="font-size:15px;">'+esc(it.std||'目視/功能檢查')+'</div></div>';
        } else {
            var L=limits(it);
            h+='<div class="spec std"><div class="k">標準值</div><div class="v">'+esc(trimNum(it.std)||'—')+'</div></div>'+
               '<div class="spec lim"><div class="k">上限（'+esc(it.up||'0')+'）</div><div class="v">'+(L?trimNum(L.hi.toFixed(4)):'—')+'</div></div>'+
               '<div class="spec lim"><div class="k">下限（'+esc(it.lo||'0')+'）</div><div class="v">'+(L?trimNum(L.low.toFixed(4)):'—')+'</div></div>';
        }
        h+='<div class="spec tool"><div class="k">量具（可追溯編號）</div><div class="v">'+toolBtn(i,0)+'</div></div>';
        h+='</div>';
        return h;
    }

    // ---------- 檢視 A：逐項（預設） ----------
    function renderItemView(){
        var $p=$('#view-item');
        if(!MODEL.items.length){ $p.html(emptyHint()); return; }
        if(focusItem>=MODEL.items.length) focusItem=MODEL.items.length-1;
        var it=MODEL.items[focusItem], i=focusItem;

        var chips='<div class="chips">'+MODEL.items.map(function(x,ix){
            var v=itemVerdict(x), c=itemFilledCount(x);
            return '<span class="chip jump-item '+(ix===focusItem?'on':'')+'" data-ix="'+ix+'">'+
                   '<span class="dot '+(v==='NG'?'ng':(v==='OK'?'ok':''))+'"></span>'+codeLabel(ix)+' '+esc(x.name||'（未命名）')+
                   '<span class="cnt">'+c+'/'+state.sampleN+'</span></span>';
        }).join('')+'</div>';

        var h='<div class="fcard"><div class="fcard-hd">'+
              '<span class="idx">'+codeLabel(i)+'</span><span class="nm">'+esc(it.name||'（未命名項目）')+'</span>'+
              '<span class="pull-right" style="margin-top:4px;">'+
                '<button class="btn btn-xs btn-default btn-edit-std" data-i="'+i+'"><i class="fa fa-pencil"></i> 改標準</button> '+
                '<button class="btn btn-xs btn-default btn-add-reading" data-i="'+i+'" title="同尺寸再用其他量具/方法量一次（如三次元＋投影機）"><i class="fa fa-plus"></i> 加量測</button> '+
                '<button class="btn btn-xs btn-default btn-del-item" data-i="'+i+'"><i class="fa fa-trash"></i></button>'+
              '</span></div><div class="fcard-bd">';
        h+=specBar(it,i);
        h+='<div class="cells">';
        for(var s=0;s<state.sampleN;s++) h+=cellHtml(it,i,0,s,true);
        h+='</div>';
        for(var r=1;r<it.readings.length;r++){
            h+='<div class="rdbox"><div class="rdhd"><b>加量測 '+r+'</b>'+
               '<span style="min-width:220px;display:inline-block;">'+toolBtn(i,r)+'</span>'+
               '<a href="#" class="btn-del-reading" data-i="'+i+'" data-r="'+r+'" style="color:var(--coral);"><i class="fa fa-trash"></i> 移除</a></div><div class="cells">';
            for(var s2=0;s2<state.sampleN;s2++) h+=cellHtml(it,i,r,s2,true);
            h+='</div></div>';
        }
        h+='<div style="margin-top:12px;"><label class="muted-help">本項備註（如「毛邊已修」）</label>'+
           '<input type="text" class="form-control input-sm f-remark" data-i="'+i+'" value="'+esc(it.remark||'')+'" placeholder="選填"></div>';
        h+='</div><div class="fcard-hd" style="border-top:1px solid var(--line);border-bottom:0;border-radius:0 0 10px 10px;">'+
           '<button class="btn btn-warm-o nav-prev" '+(i<=0?'disabled':'')+'><i class="fa fa-arrow-left"></i> 上一項</button> '+
           '<span class="muted-help">第 '+(i+1)+' / '+MODEL.items.length+' 項</span> '+
           '<button class="btn btn-warm nav-next pull-right">'+(i>=MODEL.items.length-1?'完成，回總表':'下一項')+' <i class="fa fa-arrow-right"></i></button>'+
           '</div></div>';
        $p.html(chips+h);
    }

    // ---------- 檢視 B：逐件 ----------
    function renderPcsView(){
        var $p=$('#view-pcs');
        if(!MODEL.items.length){ $p.html(emptyHint()); return; }
        if(focusPcs>=state.sampleN) focusPcs=state.sampleN-1;
        var s=focusPcs;
        var chips='<div class="chips">';
        for(var k=0;k<state.sampleN;k++){
            var ng=pcsAutoNG(k), any=false;
            MODEL.items.forEach(function(it){ if(judge(it,it.readings[0].vals[k])) any=true; });
            chips+='<span class="chip jump-pcs '+(k===focusPcs?'on':'')+'" data-ix="'+k+'">'+
                   '<span class="dot '+(ng?'ng':(any?'ok':''))+'"></span>第 '+(k+1)+' 件</span>';
        }
        chips+='</div>';

        var h='<div class="fcard"><div class="fcard-hd"><span class="idx">'+(s+1)+'</span>'+
              '<span class="nm">第 '+(s+1)+' 件（共 '+state.sampleN+' 件）</span></div><div class="fcard-bd">';
        MODEL.items.forEach(function(it,i){
            var L=limits(it);
            var spec = it.type==='OKNG' ? esc(it.std||'目視/功能檢查')
                     : (esc(trimNum(it.std)||'—')+'　'+(L?('['+trimNum(L.low.toFixed(4))+' ~ '+trimNum(L.hi.toFixed(4))+']'):''));
            h+='<div class="prow"><div class="pnm"><div class="n">'+codeLabel(i)+'　'+esc(it.name||'（未命名）')+'</div>'+
               '<div class="s">'+spec+'　<span style="color:#a08a6d;">'+esc(toolLabelById(it.readings[0].tool_id))+'</span></div></div>'+
               '<div class="pin">'+cellHtml(it,i,0,s,true)+'</div></div>';
            for(var r=1;r<it.readings.length;r++){
                h+='<div class="prow" style="padding-left:24px;background:#FBF7F1;"><div class="pnm"><div class="s">↳ 加量測 '+r+'：'+esc(toolLabelById(it.readings[r].tool_id)||'未指定量具')+'</div></div>'+
                   '<div class="pin">'+cellHtml(it,i,r,s,true)+'</div></div>';
            }
        });
        h+='</div><div class="fcard-hd" style="border-top:1px solid var(--line);border-bottom:0;border-radius:0 0 10px 10px;">'+
           '<button class="btn btn-warm-o nav-prev" '+(s<=0?'disabled':'')+'><i class="fa fa-arrow-left"></i> 上一件</button> '+
           '<span class="muted-help">第 '+(s+1)+' / '+state.sampleN+' 件</span> '+
           '<button class="btn btn-warm nav-next pull-right">'+(s>=state.sampleN-1?'完成，回總表':'下一件')+' <i class="fa fa-arrow-right"></i></button>'+
           '</div></div>';
        $p.html(chips+h);
    }
    function emptyHint(){
        return '<div class="warm-panel text-center" style="padding:34px;color:#8a6a45;">'+
               '<i class="fa fa-list-alt" style="font-size:34px;"></i><br><br>尚未建立檢驗項目。<br>'+
               '請按下方「新增檢驗項目」或「匯入通用樣板」。</div>';
    }

    // ---------- 檢視 C：總表（標準欄預設收合＝只剩 4 欄，不再左右捲） ----------
    function renderGrid(){
        var stdEdit = $('#chk-std-edit').is(':checked');
        var head = '<th width="46">編號</th>';
        if(stdEdit){
            head += '<th width="150">檢驗項目</th><th width="82">標準值</th><th width="70">上公差</th><th width="70">下公差</th>'+
                    '<th width="140">量具</th><th width="76">型態</th>';
        } else {
            head += '<th width="190">檢驗項目</th><th width="180">標準（上/下限）</th><th width="150">量具</th>';
        }
        head += '<th>實測值（每件一格）</th><th width="70">判定</th>';
        $('#grid-head').html(head);
        var colsBefore = stdEdit ? 8 : 5;
        $('#verdict-label').attr('colspan', colsBefore-1);

        // 每列的操作鈕：放在「檢驗項目」欄名稱下方（原本放最右欄會被視窗右緣切掉）
        function rowActs(it, i){
            return '<div class="row-acts">'+
                   '<a href="#" class="btn-add-reading" data-i="'+i+'" title="同尺寸再用其他量具/方法量一次"><i class="fa fa-plus"></i> 加量測</a>'+
                   '<a href="#" class="btn-item-note'+(it.remark?' has-note':'')+'" data-i="'+i+'" title="'+esc(it.remark||'本項備註')+'"><i class="fa fa-comment-o"></i> 備註'+(it.remark?'✔':'')+'</a>'+
                   '<a href="#" class="btn-del-item del" data-i="'+i+'"><i class="fa fa-trash"></i> 刪除</a></div>';
        }

        var body='';
        MODEL.items.forEach(function(it,i){
            var L=limits(it), v=itemVerdict(it);
            body += '<tr data-i="'+i+'"><td class="text-center">'+codeLabel(i)+'</td>';
            if(stdEdit){
                body += '<td><input class="table-input f-name" data-i="'+i+'" value="'+esc(it.name)+'">'+rowActs(it,i)+'</td>'+
                        '<td><input class="table-input f-std" data-i="'+i+'" value="'+esc(it.std)+'"></td>'+
                        '<td><input class="table-input f-up" data-i="'+i+'" value="'+esc(it.up)+'" '+(it.type==='OKNG'?'readonly':'')+'></td>'+
                        '<td><input class="table-input f-lo" data-i="'+i+'" value="'+esc(it.lo)+'" '+(it.type==='OKNG'?'readonly':'')+'></td>'+
                        '<td>'+toolBtn(i,0,true)+'</td>'+
                        '<td><select class="table-input f-type" data-i="'+i+'">'+
                          '<option value="NUM" '+(it.type==='NUM'?'selected':'')+'>數值</option>'+
                          '<option value="OKNG" '+(it.type==='OKNG'?'selected':'')+'>OK/NG</option></select></td>';
            } else {
                body += '<td class="g-name">'+esc(it.name||'（未命名）')+rowActs(it,i)+'</td>'+
                        '<td class="g-spec">'+(it.type==='OKNG' ? esc(it.std||'OK/NG')
                            : (esc(trimNum(it.std)||'—')+(L?('<br><span class="muted-help">'+trimNum(L.low.toFixed(4))+' ~ '+trimNum(L.hi.toFixed(4))+'</span>'):'')))+'</td>'+
                        '<td>'+toolBtn(i,0,true)+'</td>';
            }
            var cells=''; for(var s=0;s<state.sampleN;s++) cells+=cellHtml(it,i,0,s,false);
            body += '<td><div class="gcells">'+cells+'</div></td>'+
                    '<td class="text-center g-verdict" data-i="'+i+'" style="font-weight:bold;color:'+(v==='NG'?'var(--coral)':'var(--ink)')+'">'+(v==='NG'?'✘ NG':(v==='OK'?'✔ OK':'—'))+'</td></tr>';
            for(var r=1;r<it.readings.length;r++){
                var sub=''; for(var s3=0;s3<state.sampleN;s3++) sub+=cellHtml(it,i,r,s3,false);
                // 對齊主列欄位：空(編號) + 併格(項目…) + 量具 + [型態空格] + 實測 + 判定
                var toolCol = stdEdit ? 6 : 4;              // 量具是第幾欄
                var afterTool = (colsBefore - toolCol - 1); // 量具與實測值之間還有幾欄（型態）
                body += '<tr style="background:#FBF7F1;"><td></td><td colspan="'+(toolCol-2)+'" class="text-right muted-help">↳ 加量測 '+r+
                        ' <a href="#" class="btn-del-reading" data-i="'+i+'" data-r="'+r+'" style="color:var(--coral);"><i class="fa fa-trash"></i></a></td>'+
                        '<td>'+toolBtn(i,r,true)+'</td>'+ (afterTool>0 ? '<td></td>' : '') +
                        '<td><div class="gcells">'+sub+'</div></td><td></td></tr>';
            }
        });
        // 表格最後永遠有一列「＋ 新增檢驗項目」，不必捲到表格外面找按鈕
        body += '<tr><td colspan="'+(colsBefore+1)+'" style="background:#FDFAF5;">'+
                '<a href="#" id="btn-add-row-grid" style="color:var(--amber-d);font-weight:bold;text-decoration:none;">'+
                '<i class="fa fa-plus-circle"></i> 新增檢驗項目</a>'+
                '<span class="muted-help" style="margin-left:12px;">（在最後一列欄位按 ↓ 也會自動加一列）</span></td></tr>';
        $('#items-body').html(body);

        var vh='';
        for(var s4=0;s4<state.sampleN;s4++){
            var p=MODEL.pcs[s4]||{v:'OK',m:0};
            var ng=(p.m? p.v==='NG' : pcsAutoNG(s4));
            var none=!MODEL.items.length;
            vh+='<span class="pverdict '+(none?'':(ng?'ng':'ok'))+' '+(p.m?'manual':'')+'" data-s="'+s4+'" title="點擊手動改判，雙擊恢復自動">'+
                (none?'—':(ng?'✘ NG':'✔ OK'))+'</span> ';
        }
        $('#verdict-cells').html(vh);
    }

    // ---------- 彙總（進度 / 不良數 / 整體判定） ----------
    function recalc(){
        ensurePcs();
        var hasItems = MODEL.items.length>0;
        var total = hasItems ? MODEL.items.length*state.sampleN : 0, filled = 0, ngPcs = 0;
        MODEL.items.forEach(function(it){ filled += itemFilledCount(it); });
        for(var s=0;s<state.sampleN;s++){
            var p=MODEL.pcs[s];
            var ng = p.m ? (p.v==='NG') : pcsAutoNG(s);
            if(!p.m) p.v = ng ? 'NG' : 'OK';
            if(hasItems && ng) ngPcs++;
        }
        $('#dk-prog').text(filled+'/'+total);
        $('#dk-progbar').css('width', total? Math.round(filled*100/total)+'%' : '0%');
        $('#dk-ng').text(ngPcs);
        $('#dk-judge').text(hasItems ? (ngPcs>0?'✘ 不良':'✔ 合格') : '—');
        $('#inp-ng').val(hasItems ? ngPcs : '');
        if(hasItems) $('input[name=judge][value="'+(ngPcs>0?'NG':'OK')+'"]').prop('checked', true);
        else $('input[name=judge]').prop('checked', false);
        // 判定列與項目膠囊燈號同步
        $('#verdict-cells .pverdict').each(function(){
            var s=+$(this).data('s'), p=MODEL.pcs[s]||{v:'OK',m:0};
            var ng=(p.m? p.v==='NG' : pcsAutoNG(s));
            $(this).removeClass('ok ng manual').addClass(hasItems ? (ng?'ng':'ok') : '').addClass(p.m?'manual':'')
                   .text(hasItems ? (ng?'✘ NG':'✔ OK') : '—');
        });
        $('#view-item .chip.jump-item').each(function(){
            var ix=+$(this).data('ix'), it=MODEL.items[ix]; if(!it) return;
            var v=itemVerdict(it);
            $(this).find('.dot').removeClass('ok ng').addClass(v==='NG'?'ng':(v==='OK'?'ok':''));
            $(this).find('.cnt').text(itemFilledCount(it)+'/'+state.sampleN);
        });
        MODEL.items.forEach(function(it,i){ updateItemVerdictCell(i); });
        // 疑似誤填提醒
        var warnN=0;
        MODEL.items.forEach(function(it){ it.readings.forEach(function(rd){ rd.vals.forEach(function(v){ if(isOutlier(it,v)) warnN++; }); }); });
        $('#dk-warn').html(warnN ? '<i class="fa fa-exclamation-triangle"></i> 疑似誤填 <b>'+warnN+'</b> 格，請確認' : '').toggle(warnN>0);
    }

    // =====================================================================
    // 輸入事件（三種檢視共用同一組委派：一律先寫回 MODEL，再只重畫該格）
    // =====================================================================
    $(document).on('input', '.mval', function(){
        var i=+$(this).data('i'), r=+$(this).data('r'), s=+$(this).data('s');
        if(!MODEL.items[i]) return;
        MODEL.items[i].readings[r].vals[s] = $(this).val();
        paintCell($(this).closest('.mcell'));
        recalc(); scheduleDraftSave();
    });
    // UI 規範：聚焦自動全選、有值雙擊清空
    // （插入工程符號後會把 symSkipSelect 拉起來，此時不可全選，否則接著打的字會蓋掉剛插的符號）
    function autoSelectOnFocus(el){
        setTimeout(function(){ if(symSkipSelect) return; try{ el.select(); }catch(_){ } }, 0);
    }
    $(document).on('focus', '.mval', function(){
        lastFocused=this;
        $('.mcell').removeClass('focus-on'); $(this).closest('.mcell').addClass('focus-on');
        autoSelectOnFocus(this);
    });
    $(document).on('dblclick', '.mval', function(){
        if($(this).val()===''){ return; }
        $(this).val('').trigger('input');
    });
    // OK/NG 格：點擊循環 OK → NG → OK
    $(document).on('click', '.mcell.okng', function(){
        var i=+$(this).data('i'), r=+$(this).data('r'), s=+$(this).data('s');
        var it=MODEL.items[i]; if(!it) return;
        var cur=it.readings[r].vals[s];
        it.readings[r].vals[s] = (cur==='NG') ? 'OK' : 'NG';
        paintCell($(this)); recalc(); scheduleDraftSave();
    });
    // ============ 鍵盤導航（全頁統一，符合專案 UI 規範：Enter 跳下一欄） ============
    // 涵蓋範圍：量測格、OK/NG 格、總表「編輯標準」的項目名稱/標準值/上下公差、逐項的本項備註。
    // （原本只有量測格有導航，填標準欄按 Enter 沒反應 → 2026-07-29 現場回饋修正）
    var NAV_SEL = 'input.mval, input.table-input, input.f-remark, .mcell.okng';
    function navPane(el){
        var $p = $(el).closest('.view-pane');
        return $p.length ? $p : $('.view-pane').filter(':visible').first();
    }
    function navList(el){
        return navPane(el).find(NAV_SEL).filter(':visible')
                 .filter(function(){ return !(this.readOnly || this.disabled); });
    }
    function navGo($list, idx){
        if(idx>=0 && idx<$list.length){ $list.eq(idx).focus(); return true; }
        return false;
    }
    // ←→ 只有在游標已經到字首/字尾時才跳格，否則留給瀏覽器移動游標（才改得動中間的字）
    function caretAtEnd(el){
        try{ return el.selectionStart===el.selectionEnd && el.selectionEnd===String(el.value).length; }catch(_){ return true; }
    }
    function caretAtStart(el){
        try{ return el.selectionStart===0 && el.selectionEnd===0; }catch(_){ return true; }
    }
    $(document).on('keydown', NAV_SEL, function(e){
        var k=e.key||'', code=e.keyCode||0, self=this, $self=$(this);
        var isEnter=(k==='Enter'||code===13), isRight=(k==='ArrowRight'||code===39),
            isLeft =(k==='ArrowLeft'||code===37), isDown=(k==='ArrowDown'||code===40),
            isUp   =(k==='ArrowUp'||code===38);
        if(!isEnter && !isRight && !isLeft && !isDown && !isUp){
            if((k===' '||code===32) && $self.hasClass('okng')){ e.preventDefault(); $self.trigger('click'); }
            return;
        }
        var $list=navList(this), idx=$list.index(this);
        if(idx<0) return;
        if(this.tagName==='INPUT' && isRight && !caretAtEnd(this)) return;
        if(this.tagName==='INPUT' && isLeft  && !caretAtStart(this)) return;

        if(isEnter || isRight){
            e.preventDefault();
            if(!navGo($list, idx+1)){
                if(view!=='GRID') autoAdvance(1);
                else $('#btn-save').focus();     // 最後一欄 → 焦點交給儲存鈕（不自動送出，避免誤存）
            }
            return;
        }
        if(isLeft){ e.preventDefault(); navGo($list, idx-1); return; }

        // ↑↓：量測格＝同一件的上下項目；標準欄＝同一欄的上下列
        e.preventDefault();
        var step = isDown ? 1 : -1;
        var myItem = parseInt($self.attr('data-i'), 10);

        // 專案 UI 規範：末列按 ↓ 自動加一列；在全空的最後一列按 ↑ 離開時自動移除該列
        if(view==='GRID' && !isNaN(myItem)){
            var last = MODEL.items.length-1;
            if(isDown && myItem===last){
                var keepCls=null;
                ['f-name','f-std','f-up','f-lo'].forEach(function(c){ if($(self).hasClass(c)) keepCls=c; });
                var keepS = $self.attr('data-s');
                addItemRow(false);
                setTimeout(function(){
                    var $tr=$('#items-body tr[data-i="'+(MODEL.items.length-1)+'"]');
                    var $t = keepCls ? $tr.find('input.'+keepCls)
                                     : $tr.find('.mval[data-s="'+keepS+'"], .mcell.okng[data-s="'+keepS+'"]');
                    ($t.length ? $t : $tr.find('input.f-name')).first().focus();
                }, 30);
                return;
            }
            if(isUp && myItem===last && last>0 && isItemEmpty(MODEL.items[last])){
                MODEL.items.pop();
                if(focusItem>=MODEL.items.length) focusItem=MODEL.items.length-1;
                render();
                setTimeout(function(){ $('#items-body tr[data-i="'+(MODEL.items.length-1)+'"] input.f-name').focus(); }, 30);
                return;
            }
        }

        if($self.hasClass('mval') || $self.hasClass('okng')){
            var s=$self.attr('data-s');
            var $col=navPane(this).find('.mval[data-s="'+s+'"], .mcell.okng[data-s="'+s+'"]').filter(':visible');
            if(!navGo($col, $col.index(this)+step) && view!=='GRID') autoAdvance(step);
        } else {
            var cls=null;
            ['f-name','f-std','f-up','f-lo','f-remark'].forEach(function(c){ if($(self).hasClass(c)) cls=c; });
            if(cls){
                var $col2=navPane(this).find('input.'+cls).filter(':visible');
                navGo($col2, $col2.index(this)+step);
            }
        }
    });
    // 「全空列」＝名稱/標準/公差/備註都空白，且沒有任何實測值
    function isItemEmpty(it){
        if(!it) return true;
        if((it.name||'').trim()!=='' || (it.std||'').trim()!=='' || (it.up||'').trim()!=='' ||
           (it.lo||'').trim()!=='' || (it.remark||'').trim()!=='') return false;
        for(var r=0;r<it.readings.length;r++){
            if(it.readings[r].tool_id) return false;
            for(var s=0;s<it.readings[r].vals.length;s++){
                var v=it.readings[r].vals[s];
                if(v!=='' && v!=null && !(it.type==='OKNG' && v==='OK')) return false;
            }
        }
        return true;
    }
    // 標準欄也套用「聚焦全選」，跟量測格一致
    $(document).on('focus', 'input.table-input, input.f-remark', function(){ autoSelectOnFocus(this); });
    $(document).on('focus', '.mcell.okng', function(){
        $('.mcell').removeClass('focus-on'); $(this).addClass('focus-on');
    });
    // 專注模式：最後一格填完自動翻到下一項/下一件
    function autoAdvance(step){
        step = step || 1;
        if(view==='ITEM'){
            var n=focusItem+step;
            if(n>=0 && n<MODEL.items.length){ focusItem=n; renderItemView(); recalc(); focusFirstCell(); }
        } else if(view==='PCS'){
            var m=focusPcs+step;
            if(m>=0 && m<state.sampleN){ focusPcs=m; renderPcsView(); recalc(); focusFirstCell(); }
        }
    }
    function focusFirstCell(){
        setTimeout(function(){
            $('.view-pane').filter(':visible').first().find('input.mval, .mcell.okng').first().focus();
        }, 30);
    }

    // 膠囊/導航切換
    $(document).on('click', '.jump-item', function(){ focusItem=+$(this).data('ix'); renderItemView(); recalc(); });
    $(document).on('click', '.jump-pcs',  function(){ focusPcs =+$(this).data('ix'); renderPcsView();  recalc(); });
    $(document).on('click', '#view-item .nav-prev', function(){ if(focusItem>0){ focusItem--; renderItemView(); recalc(); } });
    $(document).on('click', '#view-item .nav-next', function(){
        if(focusItem<MODEL.items.length-1){ focusItem++; renderItemView(); recalc(); }
        else { view='GRID'; localStorage.setItem('qc2_view',view); render(); }
    });
    $(document).on('click', '#view-pcs .nav-prev', function(){ if(focusPcs>0){ focusPcs--; renderPcsView(); recalc(); } });
    $(document).on('click', '#view-pcs .nav-next', function(){
        if(focusPcs<state.sampleN-1){ focusPcs++; renderPcsView(); recalc(); }
        else { view='GRID'; localStorage.setItem('qc2_view',view); render(); }
    });
    $('.view-switch').on('click','button', function(){
        view=$(this).data('view'); localStorage.setItem('qc2_view', view); render();
    });
    $('#chk-std-edit').on('change', function(){ renderGrid(); recalc(); });
    // 編號 A,B,C… ↔ 1,2,3… 切換（做成工具列按鈕，三種檢視都能按；偏好記在瀏覽器）
    $(document).on('click', '#btn-code-mode2', function(e){
        e.preventDefault();
        codeMode = (codeMode==='ALPHA') ? 'NUM' : 'ALPHA';
        localStorage.setItem('qc_item_code_mode', codeMode); render();
    });

    // ---------- 標準/量具/型態/備註 編修 ----------
    // ---------- 量具挑選跳窗（類型 → 編號；可一次套用到多個項目） ----------
    var tpTarget=null;
    $(document).on('click', '.tool-btn', function(){ openToolPicker(+$(this).data('i'), +$(this).data('r')); });
    function openToolPicker(i, r){
        var it=MODEL.items[i]; if(!it) return;
        tpTarget={ i:i, r:r };
        $('#tp-for').text('（'+codeLabel(i)+' '+(it.name||'未命名')+(r>0?(' · 加量測'+r):'')+'）');
        // 只有主量測、且表內不只一個項目時，才需要問套用範圍
        $('#tp-scope').toggle(r===0 && MODEL.items.length>1);
        var cats=[], cnt={};
        TOOL_INSTANCES.forEach(function(t){ var c=t.cat||'（未分類）'; if(cnt[c]===undefined){ cnt[c]=0; cats.push(c); } cnt[c]++; });
        $('#tp-cats').html(cats.length ? cats.map(function(c){
            return '<button type="button" class="tp-cat" data-c="'+esc(c)+'">'+esc(c)+'<small>'+cnt[c]+' 支</small></button>';
        }).join('') : '<div class="text-muted">尚未建立任何量具，請至 設定 → 量具設定 新增。</div>');
        $('#tp-step1').show(); $('#tp-step2').hide();
        $('#toolPickModal').modal('show');
    }
    $(document).on('click', '.tp-cat', function(){
        var cat=String($(this).attr('data-c'));
        var list=TOOL_INSTANCES.filter(function(t){ return (t.cat||'（未分類）')===cat; });
        $('#tp-nos').html(list.map(function(t){
            return '<button type="button" class="tp-no" data-id="'+t.id+'">'+esc(toolNoSpec(t))+'<small>'+esc(t.cat||'')+'</small></button>';
        }).join(''));
        $('#tp-step1').hide(); $('#tp-step2').show();
    });
    $(document).on('click', '#tp-back', function(e){ e.preventDefault(); $('#tp-step2').hide(); $('#tp-step1').show(); });
    $(document).on('click', '.tp-no', function(){ applyTool(String($(this).attr('data-id'))); });
    $('#tp-clear').on('click', function(){ applyTool(''); });
    function applyTool(tid){
        if(!tpTarget) return;
        var i=tpTarget.i, r=tpTarget.r, n=0;
        var scope = ($('#tp-scope').is(':visible')) ? ($('input[name=tpscope]:checked').val()||'one') : 'one';
        if(scope==='one'){ MODEL.items[i].readings[r].tool_id=tid; n=1; }
        else {
            MODEL.items.forEach(function(it){
                if(scope==='blank' && it.readings[0].tool_id) return;   // 只補「還沒設定」的
                it.readings[0].tool_id=tid; n++;
            });
            if(scope==='blank' && !MODEL.items[i].readings[0].tool_id){ MODEL.items[i].readings[0].tool_id=tid; n++; }
        }
        $('#toolPickModal').modal('hide');
        render(); scheduleDraftSave();
        var t=toolInstById(tid);
        if(n>1) flashMsg('已套用「'+((t?((t.cat?t.cat+' / ':'')+toolNoSpec(t)):'未指定'))+'」到 '+n+' 個檢驗項目');
    }
    function flashMsg(msg){
        var $m=$('#flash-msg');
        if(!$m.length) $m=$('<div id="flash-msg" style="position:fixed;left:50%;transform:translateX(-50%);bottom:140px;'+
            'background:#4A3524;color:#fff;padding:9px 20px;border-radius:20px;z-index:1100;font-size:14px;display:none;'+
            'box-shadow:0 2px 8px rgba(0,0,0,.25);"></div>').appendTo('body');
        $m.text(msg).stop(true,true).fadeIn(120).delay(2000).fadeOut(400);
    }
    $(document).on('input', '.f-name', function(){ var i=+$(this).data('i'); MODEL.items[i].name=$(this).val(); scheduleDraftSave(); });
    // 檢驗項目常與標準值同一個數字（例：Ø12.2、60°）。離開項目名稱欄時，若標準值仍空白
    // 且型態＝數值，自動去除符號取剩餘數字帶入標準值；標準值一旦有值就不再覆蓋（使用者填的優先）。
    $(document).on('blur', '.f-name', function(){
        var i=+$(this).data('i'), it=MODEL.items[i]; if(!it) return;
        if(it.type==='OKNG' || (it.std!=null && String(it.std).trim()!=='')) return;
        var m=String(it.name||'').match(/-?\d+(\.\d+)?/);
        if(!m) return;
        it.std=m[0];
        $('input.f-std[data-i="'+i+'"]').val(it.std);
        repaintItem(i);
    });
    $(document).on('input', '.f-std',  function(){ var i=+$(this).data('i'); MODEL.items[i].std =$(this).val(); repaintItem(i); });
    $(document).on('input', '.f-up',   function(){ var i=+$(this).data('i'); MODEL.items[i].up  =$(this).val(); repaintItem(i); });
    $(document).on('input', '.f-lo',   function(){ var i=+$(this).data('i'); MODEL.items[i].lo  =$(this).val(); repaintItem(i); });
    $(document).on('input', '.f-remark',function(){ var i=+$(this).data('i'); MODEL.items[i].remark=$(this).val(); scheduleDraftSave(); });
    $(document).on('change', '.f-type', function(){
        var i=+$(this).data('i'), t=$(this).val()==='OKNG'?'OKNG':'NUM';
        var it=MODEL.items[i]; it.type=t;
        it.readings.forEach(function(rd){ rd.vals=blankVals(state.sampleN, t==='OKNG'?'OK':''); });
        render(); scheduleDraftSave();
    });
    function repaintItem(i){
        $('.mcell[data-i="'+i+'"]').each(function(){ paintCell($(this)); });
        recalc(); scheduleDraftSave();
    }
    $(document).on('click', '.btn-edit-std', function(){
        view='GRID'; localStorage.setItem('qc2_view',view);
        $('#chk-std-edit').prop('checked', true); render();
        $('html,body').animate({ scrollTop:$('#items-table').offset().top-120 }, 250);
    });
    $(document).on('click', '.btn-add-reading', function(e){
        e.preventDefault();
        var i=+$(this).data('i'), it=MODEL.items[i];
        it.readings.push({ tool_id:'', tool_cat:'', vals:blankVals(state.sampleN, it.type==='OKNG'?'OK':'') });
        render(); scheduleDraftSave();
    });
    $(document).on('click', '.btn-del-reading', function(e){
        e.preventDefault();
        var i=+$(this).data('i'), r=+$(this).data('r');
        MODEL.items[i].readings.splice(r,1); render(); scheduleDraftSave();
    });
    $(document).on('click', '.btn-del-item', function(e){
        e.preventDefault();
        var i=+$(this).data('i');
        if(!confirm('確定刪除「'+(MODEL.items[i].name||'未命名項目')+'」？已填的實測值也會一併移除。')) return;
        MODEL.items.splice(i,1);
        if(focusItem>=MODEL.items.length) focusItem=Math.max(0,MODEL.items.length-1);
        render(); scheduleDraftSave();
    });
    $(document).on('click', '.btn-item-note', function(e){
        e.preventDefault();
        var i=+$(this).data('i');
        var v=prompt('本項目備註（處置/狀況，如「毛邊已修」）：', MODEL.items[i].remark||'');
        if(v===null) return;
        MODEL.items[i].remark=String(v).slice(0,255); render(); scheduleDraftSave();
    });
    // 新增檢驗項目：工具列、表格最後一列、原本表格下方三個入口都走這裡
    function addItemRow(focusName){
        MODEL.items.push(newItem());
        focusItem=MODEL.items.length-1;
        view='GRID'; localStorage.setItem('qc2_view',view);
        $('#chk-std-edit').prop('checked', true);
        $('#no-std-hint').hide(); render();
        if(focusName!==false) setTimeout(function(){ $('#items-body tr[data-i="'+focusItem+'"] .f-name').focus(); },50);
    }
    $(document).on('click', '#btn-add-row, #btn-add-row-top, #btn-add-row-grid', function(e){ e.preventDefault(); addItemRow(); });
    // 判定列：點擊手動改判、雙擊恢復自動
    $(document).on('click', '#verdict-cells .pverdict', function(){
        var s=+$(this).data('s'); if(!MODEL.items.length) return;
        var p=MODEL.pcs[s]; var cur=(p.m? p.v==='NG' : pcsAutoNG(s));
        p.m=1; p.v=cur?'OK':'NG'; recalc(); scheduleDraftSave();
    });
    $(document).on('dblclick', '#verdict-cells .pverdict', function(){
        var s=+$(this).data('s'); MODEL.pcs[s].m=0; recalc(); scheduleDraftSave();
    });
    // ---------- 抽驗數變更：必須填理由，理由會隨這張檢驗單一起留存 ----------
    var scPending=null;   // {from,to}
    $('#inp-sample').on('focus', function(){ $(this).data('prev', parseInt($(this).val())||state.sampleN); });
    $('#inp-sample').on('change', function(){
        var from = parseInt($(this).data('prev'));
        if(isNaN(from)) from = state.sampleN;
        var to = Math.max(1, parseInt($(this).val())||1);
        if(to===from){ return; }
        scPending={ from:from, to:to };
        $('#sc-info').html('原抽驗數 <b>'+from+'</b> 件 → 改為 <b>'+to+'</b> 件');
        $('#sc-reason').val('');
        $('#sampleChgModal').modal('show');
        setTimeout(function(){ $('#sc-reason').focus(); }, 300);
    });
    $('#btn-sc-cancel').on('click', function(){
        if(scPending) $('#inp-sample').val(scPending.from);
        scPending=null; $('#sampleChgModal').modal('hide');
    });
    $('#btn-sc-ok').on('click', function(){
        var rsn=$('#sc-reason').val().trim();
        if(!rsn){ alert('請填寫變更理由（必填，會記錄在這張檢驗單）'); $('#sc-reason').focus(); return; }
        if(!scPending) { $('#sampleChgModal').modal('hide'); return; }
        // 累積起來，存檔成功後一併寫入 qc_sample_change_log
        state.sampleChanges = state.sampleChanges || [];
        state.sampleChanges.push({ from:scPending.from, to:scPending.to, reason:rsn });
        setSampleN(scPending.to);
        $('#inp-sample').data('prev', scPending.to);
        renderSampleChangeBanner();
        scPending=null; $('#sampleChgModal').modal('hide'); scheduleDraftSave();
    });
    function renderSampleChangeBanner(){
        var list=state.sampleChanges||[];
        if(!list.length){ $('#sc-banner').remove(); return; }
        if(!$('#sc-banner').length) $('#mode-banner').after('<div id="sc-banner" class="banner banner-info"></div>');
        $('#sc-banner').html('<i class="fa fa-list-ol"></i> <b>抽驗數已變更</b>（存檔時會一併記錄在本檢驗單）：'+
            list.map(function(c){ return esc(c.from+' → '+c.to+' 件（'+c.reason+'）'); }).join('　／　'));
    }
    // 存檔成功後把變更理由寫進 qc_sample_change_log
    function flushSampleChanges(qcFormId){
        var list=state.sampleChanges||[];
        if(!qcFormId || !list.length) return;
        list.forEach(function(c){
            $.post(V2API, { v2action:'log_sample_change', qc_form_id:qcFormId,
                            old_qty:c.from, new_qty:c.to, reason:c.reason }, function(){}, 'json');
        });
        state.sampleChanges=[]; $('#sc-banner').remove();
    }
    $('#inp-qty,#inp-remark').on('input', scheduleDraftSave);
    $('#btn-dock-extra').on('click', function(){ $('#dock-extra').slideToggle(120, syncDockPad); });

    // =====================================================================
    // 工程符號（Ø ± ▽ …）：插到最後聚焦的文字欄游標處；主檔僅管理員可增修刪
    // 參考 views/Sales/image_editor.php 的符號列做法，但符號改由 qc_symbol 主檔維護
    // =====================================================================
    var SYMS=[], symAdmin=false, lastTextEl=null, symSkipSelect=false;
    // 記住最後聚焦的「可插符號」欄位：只有純文字欄位可以（項目名稱／備註／臨時單檢驗類型）
    $(document).on('focus', 'input.f-name, input.f-remark, #ah-type, #inp-remark', function(){ lastTextEl=this; });
    // 標準值與實測值要參與公差計算，插入符號會讓數值解析失敗 → 聚焦這些欄位時清掉插入目標
    $(document).on('focus', 'input.f-std, input.f-up, input.f-lo, input.mval', function(){ lastTextEl=null; });
    function loadSymbols(){
        $.post(V2API, { v2action:'sym_list' }, function(res){
            if(!res || !res.success) return;
            SYMS=res.rows||[]; symAdmin=!!res.is_admin;
            $('#sym-pad-list').html(SYMS.length ? SYMS.map(function(s){
                return '<button type="button" class="btn btn-default btn-sm sym-ins" data-s="'+esc(s.symbol)+'" '+
                       'title="'+esc(s.label||'')+'" style="min-width:38px;font-size:16px;padding:4px 6px;">'+esc(s.symbol)+'</button>';
            }).join('') : '<span class="muted-help">尚無符號</span>');
            $('#sym-pad-admin').toggle(symAdmin);
        }, 'json');
    }
    $('#btn-sym').on('click', function(e){
        e.preventDefault();
        var $pad=$('#sym-pad');
        if($pad.is(':visible')){ $pad.hide(); return; }
        var r=this.getBoundingClientRect();
        $pad.css({ left:Math.max(4, Math.min(r.left, $(window).width()-274))+'px', top:(r.bottom+4)+'px' }).show();
    });
    $(document).on('mousedown', function(e){
        if($('#sym-pad').is(':visible') && !$(e.target).closest('#sym-pad, #btn-sym').length) $('#sym-pad').hide();
    });
    $(document).on('click', '.sym-ins', function(){
        var s=String($(this).attr('data-s'));
        var el=lastTextEl && document.body.contains(lastTextEl) ? lastTextEl : null;
        if(!el){
            alert('請先點一下要插入符號的欄位（檢驗項目名稱或備註），再按符號。\n\n註：標準值／公差／實測值要參與計算，不提供符號插入。');
            return;
        }
        var st=el.selectionStart||0, en=el.selectionEnd||0, v=String(el.value||'');
        var pos=st+s.length;
        el.value = v.slice(0,st) + s + v.slice(en);
        // 插完游標要停在符號後面，才能接著打字；此時不可觸發「聚焦自動全選」（會把剛插的符號選起來被蓋掉）
        symSkipSelect = true;
        $(el).trigger('input');
        el.focus();
        try{ el.selectionStart = el.selectionEnd = pos; }catch(_){ }
        setTimeout(function(){ symSkipSelect=false; }, 80);
    });
    // ---- 符號主檔維護（僅管理員） ----
    $('#btn-sym-manage').on('click', function(e){ e.preventDefault(); $('#sym-pad').hide(); renderSymList(); $('#symManageModal').modal('show'); });
    function renderSymList(){
        $('#sym-list').html(SYMS.length ? SYMS.map(function(s){
            return '<tr><td class="text-center" style="font-size:20px;">'+esc(s.symbol)+'</td><td>'+esc(s.label||'')+'</td>'+
                   '<td>'+(s.sort_order||0)+'</td><td>'+
                   '<button class="btn btn-xs btn-default sym-edit" data-id="'+s.sym_id+'" data-c="'+esc(s.symbol)+'" data-l="'+esc(s.label||'')+'" data-o="'+(s.sort_order||0)+'"><i class="fa fa-pencil"></i> 修改</button> '+
                   '<button class="btn btn-xs btn-danger sym-del" data-id="'+s.sym_id+'"><i class="fa fa-trash"></i></button></td></tr>';
        }).join('') : '<tr><td colspan="4" class="text-center muted-help">尚無符號</td></tr>');
    }
    $('#sym-form').on('submit', function(e){
        e.preventDefault();
        $.post(V2API, { v2action:'sym_save', sym_id:$('#sym-id').val(), symbol:$('#sym-char').val(),
                        label:$('#sym-label').val(), sort_order:$('#sym-sort').val() }, function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetSymForm(); loadSymbolsThen(renderSymList);
        }, 'json');
    });
    $('#sym-list').on('click','.sym-edit', function(){
        $('#sym-id').val($(this).data('id')); $('#sym-char').val($(this).attr('data-c'));
        $('#sym-label').val($(this).attr('data-l')); $('#sym-sort').val($(this).attr('data-o'));
        $('#btn-sym-save').text('儲存修改'); $('#btn-sym-cancel').show();
    });
    $('#sym-list').on('click','.sym-del', function(){
        if(!confirm('確定刪除此符號？')) return;
        $.post(V2API, { v2action:'sym_delete', sym_id:$(this).data('id') }, function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadSymbolsThen(renderSymList);
        }, 'json');
    });
    $('#btn-sym-cancel').on('click', resetSymForm);
    function resetSymForm(){
        $('#sym-id').val(''); $('#sym-char').val(''); $('#sym-label').val(''); $('#sym-sort').val('');
        $('#btn-sym-save').text('新增'); $('#btn-sym-cancel').hide();
    }
    function loadSymbolsThen(cb){
        $.post(V2API, { v2action:'sym_list' }, function(res){
            if(res && res.success){ SYMS=res.rows||[]; symAdmin=!!res.is_admin;
                $('#sym-pad-list').html(SYMS.map(function(s){
                    return '<button type="button" class="btn btn-default btn-sm sym-ins" data-s="'+esc(s.symbol)+'" title="'+esc(s.label||'')+'" style="min-width:38px;font-size:16px;padding:4px 6px;">'+esc(s.symbol)+'</button>';
                }).join(''));
                $('#sym-pad-admin').toggle(symAdmin);
            }
            if(cb) cb();
        }, 'json');
    }

    // =====================================================================
    // 內建數字鍵盤（平板/戴手套；桌機可關）
    // =====================================================================
    function applyKeypad(){ $('#keypad').toggle(!!keypadOn); $('#btn-keypad').toggleClass('btn-warm', !!keypadOn); }
    $('#btn-keypad').on('click', function(){ keypadOn=!keypadOn; localStorage.setItem('qc2_keypad', keypadOn?'1':'0'); applyKeypad(); });
    $('#kp-close').on('click', function(e){ e.preventDefault(); keypadOn=false; localStorage.setItem('qc2_keypad','0'); applyKeypad(); });
    $('#keypad').on('mousedown', 'button', function(e){ e.preventDefault(); });   // 不奪走輸入焦點
    $('#keypad').on('click','button', function(){
        var k=$(this).data('k');
        var el=lastFocused && document.body.contains(lastFocused) ? lastFocused
             : $('.view-pane').filter(':visible').first().find('input.mval').get(0);
        if(!el){ return; }
        var $el=$(el), v=$el.val();
        if(k==='BS') v=v.slice(0,-1);
        else if(k==='CL') v='';
        else if(k==='OK'||k==='NEXT'){
            var $cells=navList(el), idx=$cells.index(el);
            if(!navGo($cells, idx+1)) autoAdvance(1);
            return;
        }
        else if(k==='-'){ v = (v.charAt(0)==='-') ? v.slice(1) : ('-'+v); }
        else if(k==='.'){ if(v.indexOf('.')<0) v=v+'.'; }
        else v=v+k;
        $el.val(v).trigger('input');
        el.focus();
    });

    // =====================================================================
    // 載入情境（沿用舊頁 load_context）
    // =====================================================================
    function getFid(){ return new URLSearchParams(location.search).get('bom_ing_fid'); }
    function applyMenuPerms(){
        $('.setting-menu-item').toggle(!!state.canManageSettings);
        $('.sampling-menu-item').toggle(!!state.canManageSampling);
    }
    $.post(API, { action:'get_my_perms' }, function(res){
        if(!res || !res.success) return;
        state.can_fill = res.can_fill !== false;
        state.is_supervisor = !!res.is_supervisor;
        state.canManageSettings = !!res.can_manage_settings;
        state.canManageSampling = !!res.can_manage_sampling;
        state.canView = !!res.can_view;
        applyMenuPerms();
        if(!state.canView && state.demo){
            $('#no-view-hint').html('<i class="fa fa-ban"></i> 您沒有檢閱檢驗表的權限，請洽管理員於 設定 → 權限設定 開通「唯讀檢閱」').show();
            $('#step-search').hide();
        }
    }, 'json');
    loadToolInstances();
    loadPrintCfg();
    loadSymbols();
    applyKeypad();

    var fid = getFid();
    if(fid){
        $('#mode-banner').html('<i class="fa fa-link"></i> 來自待驗清單：bom_ing_fid = <b>'+esc(fid)+'</b>；資料為真實內容，儲存會寫入正式檢驗表。');
        loadContext(fid);
    } else {
        state.demo = true;
        $('#mode-banner').html('<i class="fa fa-info-circle"></i> 請先選擇要檢驗的對象：'+
            '左邊是<b>製令待驗項目</b>（有 BOM、有製程）；右邊是<b>臨時檢驗單</b>（退貨／客訴／來料，沒有製令與製程）。');
        $('#step-search').show();
        loadAdhocList();
    }

    function loadContext(fid){
        $.post(API, { action:'load_context', bom_ing_fid:fid }, function(res){
            if(!res.success){
                if(res.no_view){
                    state.canView=false;
                    $('#no-view-hint').html('<i class="fa fa-ban"></i> '+esc(res.message||'您沒有檢閱檢驗表的權限')).show();
                    $('#main-area,#ctx-bar,#step-search,#dock').hide();
                    return;
                }
                alert('載入失敗：'+res.message); return;
            }
            ctx = res.context;
            if(res.tools && res.tools.length) TOOLS = res.tools;
            state.is_supervisor = !!res.is_supervisor;
            state.can_fill = res.can_fill !== false;
            state.canManageSettings = !!res.can_manage_settings;
            state.canManageSampling = !!res.can_manage_sampling;
            applyMenuPerms();
            state.sampleN = ctx.sample_qty || 5;
            state.processes = [ ctx.process || '檢驗' ];
            buildBatchesFromHistory(res.history || []);
            renderCtxBar();
            $('#main-area').show(); $('#dock').show(); syncDockPad();
            $('#inp-qty').val(ctx.order_qty || 0);
            $('#inp-sample').val(state.sampleN);
            $('#insp-container-1,#insp-container-2').val('');
            $('#insp-quantity-1,#insp-quantity-2').val('');
            renderBatches();
            renderItems(res.items || []);
            $('#no-std-hint').toggle(!res.has_std);
            var noPart = !ctx.d_id || ctx.d_id<=0;
            $('#no-part-hint').toggle(noPart);
            $('#no-perm-hint').toggle(!state.can_fill);
            $('#btn-save,#btn-redo').prop('disabled', noPart || !state.can_fill);
            maybeOfferDraft(res.draft_form_id || 0);
            checkDrawingChange();          // 圖面變更提醒（受影響製程才跳）
        }, 'json').fail(function(x){ alert('載入錯誤：'+x.responseText); });
    }
    function reloadContext(){ if(ctx) loadContext(ctx.bom_ing_fid); }

    // =====================================================================
    // 圖面變更提醒：客戶改圖後即使同料號同製程，檢驗內容也可能整組不同。
    // 只有「受影響的製程」才跳（變更單可指定從哪一站開始；留白＝全部製程）。
    // 檢驗人員確認「已依新版次更新檢驗項目」後，該製程就不再提醒。
    // =====================================================================
    function checkDrawingChange(){
        if(!ctx || !ctx.d_id) return;
        $.post(V2API, { v2action:'dwg_alert', d_id:ctx.d_id, bom_ing_fid:(ctx.bom_ing_fid||0), process_name:(ctx.process||'') },
        function(res){
            $('#dwg-banner').remove();
            if(!res || !res.success || !res.rows || !res.rows.length) return;
            var h = res.rows.map(function(c){
                var done = c.confirm && c.confirm.confirmed_at;
                return '<div style="padding:6px 0;border-top:1px dashed #E4D3BC;">'+
                    '<b>'+esc(c.change_no)+'</b>　版次 '+esc(c.old_revision||'—')+' → <b>'+esc(c.new_revision||'—')+'</b>'+
                    (c.from_process_no?('　<span class="muted-help">'+esc(c.from_process_name||'')+' 起受影響</span>'):'　<span class="muted-help">所有製程受影響</span>')+
                    '<br>'+esc(c.summary)+
                    (done
                      ? '<br><span style="color:var(--amber-d);font-weight:bold;">✔ 本製程已由 '+esc(c.confirm.confirmed_by||'')+' 於 '+String(c.confirm.confirmed_at).substring(0,16)+' 確認檢驗項目已更新</span>'
                      : '<br><button class="btn btn-xs btn-warm dwg-confirm" data-id="'+c.id+'" data-ver="'+(c.new_version_id||'')+'">'+
                        '<i class="fa fa-check"></i> 我已依新版次確認/更新本製程的檢驗項目</button> '+
                        '<a href="drawing_change_log.php?ack='+c.id+'" target="_blank" class="btn btn-xs btn-default">看變更明細</a>')+
                    '</div>';
            }).join('');
            $('#mode-banner').after(
                '<div id="dwg-banner" class="banner" style="background:#FFE9D6;border:2px solid #F0A24B;color:#6B4423;">'+
                '<i class="fa fa-exclamation-triangle"></i> <b>此料號有圖面變更，請先確認檢驗項目是否需要調整</b>'+h+'</div>');
        }, 'json');
    }
    $(document).on('click','.dwg-confirm', function(){
        var cid=$(this).data('id'), vid=$(this).data('ver');
        if(!confirm('確認本製程的檢驗項目已依新版次更新？\n（會記錄您的姓名與時間到該張圖面變更單）')) return;
        var note=prompt('補充說明（選填）：','');
        if(note===null) note='';
        $.post(V2API, { v2action:'dwg_confirm', change_id:cid, process_name:(ctx?ctx.process:''), version_id:vid, note:note },
        function(r){
            if(!r.success){ alert(r.message||'確認失敗'); return; }
            flashMsg('已記錄本製程的檢驗項目更新確認');
            checkDrawingChange();
        }, 'json');
    });

    // =====================================================================
    // 檢驗標準管理（改／刪）：避免一開始設定錯了就再也改不掉
    // =====================================================================
    var smPart=null;
    $('#btn-std-manage').on('click', function(e){
        e.preventDefault();
        smPart = (ctx && ctx.d_id>0) ? { d_id:ctx.d_id, part_no:ctx.part_no } : null;
        $('#sm-part-kw').val(smPart?smPart.part_no:''); $('#sm-part-results').hide().empty();
        $('#sm-items').html('<tr><td colspan="9" class="text-center muted-help">請先選擇料號</td></tr>');
        $('#sm-version').empty(); $('#sm-hint').text('');
        $('#stdManageModal').modal('show');
        if(smPart) smLoadVersions();
    });
    function smSearch(){
        $('#sm-part-results').show().html('<div class="search-result-item muted-help">搜尋中…</div>');
        $.post(V2API,{ v2action:'part_search', keyword:$('#sm-part-kw').val() }, function(r){
            if(!r.success){ $('#sm-part-results').html('<div class="search-result-item text-danger">'+esc(r.message)+'</div>'); return; }
            var rows=r.rows||[];
            $('#sm-part-results').html(rows.length ? rows.map(function(p){
                return '<div class="search-result-item sm-pick" data-id="'+p.d_id+'" data-no="'+esc(p.D_Setting_Id)+'">'+esc(p.D_Setting_Id)+'</div>';
            }).join('') : '<div class="search-result-item muted-help">查無此料號</div>');
        }, 'json');
    }
    $('#btn-sm-search').on('click', function(e){ e.preventDefault(); smSearch(); });
    $('#sm-part-kw').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); smSearch(); } });
    $(document).on('click','.sm-pick', function(){
        smPart={ d_id:parseInt($(this).data('id')), part_no:String($(this).attr('data-no')) };
        $('#sm-part-kw').val(smPart.part_no); $('#sm-part-results').hide();
        smLoadVersions();
    });
    function smLoadVersions(){
        if(!smPart) return;
        $.post(V2API,{ v2action:'std_versions', d_id:smPart.d_id }, function(r){
            if(!r.success){ alert(r.message); return; }
            var rows=r.rows||[];
            $('#sm-version').html(rows.map(function(v){
                return '<option value="'+v.version_id+'">'+esc(v.version_label)+(v.is_active==1?'（生效中）':'')+
                       '　項目 '+v.n_item+'　已用於 '+v.n_form+' 張檢驗</option>';
            }).join('') || '<option value="">（此料號尚無標準版本）</option>');
            smLoadItems();
        }, 'json');
    }
    $('#sm-version').on('change', smLoadItems);
    function smLoadItems(){
        var vid=$('#sm-version').val();
        if(!vid){ $('#sm-items').html('<tr><td colspan="9" class="text-center muted-help">此料號尚無標準</td></tr>'); return; }
        $.post(V2API,{ v2action:'std_items', version_id:vid }, function(r){
            if(!r.success){ alert(r.message); return; }
            var rows=r.rows||[];
            $('#sm-hint').html('共 '+rows.length+' 個項目。<b>停用</b>＝不再帶進新檢驗，但歷史紀錄仍查得到；已有實測紀錄的項目按刪除會自動改為停用。');
            $('#sm-items').html(rows.length ? rows.map(function(it){
                return '<tr data-id="'+it.item_id+'">'+
                  '<td><input class="form-control input-sm" value="'+esc(it.process_name||'')+'" readonly style="background:#f7f2ea;"></td>'+
                  '<td><input class="form-control input-sm si-name" value="'+esc(it.item_name||'')+'"></td>'+
                  '<td><input class="form-control input-sm si-std" value="'+esc(it.standard_text||'')+'"></td>'+
                  '<td><input class="form-control input-sm si-up" value="'+esc(it.plus_tolerance||'')+'"></td>'+
                  '<td><input class="form-control input-sm si-lo" value="'+esc(it.minus_tolerance||'')+'"></td>'+
                  '<td><select class="form-control input-sm si-type">'+
                     '<option value="NUMERIC" '+(it.result_type==='OKNG'?'':'selected')+'>數值</option>'+
                     '<option value="OKNG" '+(it.result_type==='OKNG'?'selected':'')+'>OK/NG</option></select></td>'+
                  '<td><input class="form-control input-sm si-sort" value="'+(it.sort_order||0)+'"></td>'+
                  '<td class="text-center"><input type="checkbox" class="si-act" '+(String(it.is_active)==='1'?'checked':'')+'></td>'+
                  '<td class="text-center" style="white-space:nowrap">'+
                     '<button class="btn btn-xs btn-warm si-save">存</button> '+
                     '<button class="btn btn-xs btn-default si-del" style="color:#DD5138;"><i class="fa fa-trash"></i></button></td></tr>';
            }).join('') : '<tr><td colspan="9" class="text-center muted-help">此版本沒有檢驗項目</td></tr>');
        }, 'json');
    }
    $('#sm-items').on('click','.si-save', function(){
        var $tr=$(this).closest('tr');
        $.post(V2API,{ v2action:'std_item_save', item_id:$tr.data('id'),
            item_name:$tr.find('.si-name').val(), standard_text:$tr.find('.si-std').val(),
            plus_tolerance:$tr.find('.si-up').val(), minus_tolerance:$tr.find('.si-lo').val(),
            result_type:$tr.find('.si-type').val(), sort_order:$tr.find('.si-sort').val(),
            is_active:($tr.find('.si-act').is(':checked')?'1':'0')
        }, function(r){
            if(!r.success){ alert(r.message||'儲存失敗'); return; }
            $tr.css('background','#FDF6EA'); setTimeout(function(){ $tr.css('background',''); }, 800);
        }, 'json');
    });
    $('#sm-items').on('click','.si-del', function(){
        var $tr=$(this).closest('tr');
        if(!confirm('確定刪除「'+$tr.find('.si-name').val()+'」這個檢驗項目？')) return;
        $.post(V2API,{ v2action:'std_item_delete', item_id:$tr.data('id') }, function(r){
            if(!r.success){ alert(r.message||'刪除失敗'); return; }
            if(r.softened) alert(r.message);
            smLoadItems();
        }, 'json');
    });
    $('#btn-sm-activate').on('click', function(){
        if(!smPart || !$('#sm-version').val()) return;
        if(!confirm('把這個版本設為此料號「目前生效」的檢驗標準？（下次檢驗會帶出這一版）')) return;
        $.post(V2API,{ v2action:'std_version_activate', d_id:smPart.d_id, version_id:$('#sm-version').val() }, function(r){
            if(!r.success){ alert(r.message); return; }
            smLoadVersions();
        }, 'json');
    });
    $('#btn-sm-delver').on('click', function(){
        if(!$('#sm-version').val()) return;
        if(!confirm('確定刪除整個標準版本與其所有檢驗項目？（已被檢驗紀錄使用的版本無法刪除）')) return;
        $.post(V2API,{ v2action:'std_version_delete', version_id:$('#sm-version').val() }, function(r){
            if(!r.success){ alert(r.message); return; }
            smLoadVersions();
        }, 'json');
    });

    function renderCtxBar(){
        var partCell = ctx.part_no
            ? '<a href="javascript:void(0)" class="cv" id="lnk-part-drawing" title="點擊開啟圖檔預覽">'+esc(ctx.part_no)+' <i class="fa fa-picture-o"></i></a>'
            : '<span class="cv">—</span>';
        $('#ctx-bar').show().html(
            '<div><b>料號</b>'+partCell+'</div>'+
            (ctx.adhoc ? '' : '<div><b>客戶</b><span class="cv">'+esc(ctx.client||'—')+'</span></div>')+
            '<div><b>製令 / BOM</b><span class="cv">'+esc(ctx.bom||'—')+'</span></div>'+
            '<div><b>'+(ctx.adhoc?'檢驗類型（無製程）':'製程')+'</b><span class="cv">'+esc(ctx.process||'—')+'</span></div>'+
            '<div><b>'+(ctx.adhoc?'送驗數':'訂單數')+'</b><span class="cv">'+(ctx.order_qty||0)+'</span></div>'+
            '<div><b>'+(ctx.adhoc?'抽驗數':'建議抽驗')+'</b><span class="cv">'+(ctx.sample_qty||0)+' 件</span></div>');
    }
    $('#ctx-bar').on('click','#lnk-part-drawing', function(){
        if(!ctx || !ctx.part_no) return;
        var w=screen.availWidth, h=screen.availHeight;
        var pw=Math.min(1400, Math.round(w*0.85)), ph=Math.min(900, Math.round(h*0.88));
        var url = ctx.part_no ? ('../pm/part_viewer.php?d_id='+encodeURIComponent(ctx.part_no)+(ctx.bom?'&bom='+encodeURIComponent(ctx.bom):''))
                              : ('../pm/bom_viewer.php?bom='+encodeURIComponent(ctx.bom||''));
        window.open(url, 'part_dv_'+(ctx.part_no||ctx.bom),
            'width='+pw+',height='+ph+',left='+Math.round((w-pw)/2)+',top='+Math.round((h-ph)/2)+',resizable=yes,scrollbars=yes');
    });

    // ---------- 待驗搜尋（示範模式） ----------
    function doSearch(){
        var kw=$('#search-kw').val().trim();
        $('#search-results').html('<div class="search-result-item muted-help">搜尋中…</div>');
        $.post(API,{action:'search_pending',keyword:kw},function(res){
            if(!res.success){ $('#search-results').html('<div class="search-result-item text-danger">搜尋失敗：'+esc(res.message||'')+'</div>'); return; }
            var d=res.data||[];
            if(!d.length){ $('#search-results').html('<div class="search-result-item muted-help">查無待驗項目</div>'); return; }
            $('#search-results').html(d.map(function(r){
                return '<div class="search-result-item" data-fid="'+r.bom_ing_fid+'"><b>'+esc(r.bom)+'</b> ／ 料號 '+esc(r.part_no||'')+' ／ '+esc(r.client||'')+
                       ' <span class="muted-help">'+esc(r.process||'')+' · 數量'+(r.sqty||0)+'</span></div>';
            }).join(''));
        },'json').fail(function(){ $('#search-results').html('<div class="search-result-item text-danger">搜尋錯誤</div>'); });
    }
    $('#btn-search').on('click', doSearch);
    $('#search-kw').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); doSearch(); } });
    $('#search-results').on('click','.search-result-item[data-fid]', function(){
        var f=$(this).data('fid'); if(!f) return;
        var pop=new URLSearchParams(location.search).get('popup');
        location.search=(pop?'?popup=1&':'?')+'bom_ing_fid='+f;
    });

    // =====================================================================
    // 臨時檢驗單（退貨／客訴／來料…）：沒有製令、沒有 BOM 製程也能開檢驗
    //   ctx.adhoc=true 時，存檔改走 v2 後端 save_adhoc（bom_ing_fid=0）
    // =====================================================================
    var AH_TYPES = ['退貨檢驗','客訴複驗','來料檢驗','重工後檢驗','庫存抽驗'];
    var ahPart = null;   // {d_id, part_no}
    function loadAdhocList(){
        $.post(V2API, { v2action:'adhoc_list' }, function(res){
            if(!res || !res.success){ $('#adhoc-list').html('<div class="muted-help">查詢失敗</div>'); return; }
            var rows=res.rows||[];
            $('#adhoc-list').html(rows.length ? rows.map(function(r){
                return '<div class="search-result-item">'+
                    (IS_SUPER ? '<button class="btn btn-xs act-del-rec pull-right" data-id="'+r.qc_form_id+'" title="完全刪除此筆（測試用，需密碼）" style="background:#8F3016;color:#fff;border:0;"><i class="fa fa-trash"></i></button>' : '')+
                    '<span class="ah-open" style="cursor:pointer;" data-id="'+r.qc_form_id+'">'+
                    '<b>'+esc(r.process_name||'臨時檢驗')+'</b> ／ 料號 '+esc(r.part_no||('d_id '+r.d_id))+
                    ' <span class="muted-help">'+String(r.created_at||'').substring(0,16)+
                    '　'+(r.check_result==='NG'?'<span class="st-ng">✘不良 '+(r.ng_qty||0)+'</span>':'<span class="st-ok">✔合格</span>')+
                    '　'+esc(r.created_by||'')+'</span></span></div>';
            }).join('') : '<div class="muted-help">目前沒有臨時檢驗單</div>');
        }, 'json');
    }
    $('#btn-new-adhoc').on('click', function(){
        ahPart=null;
        $('#ah-type-btns').html(AH_TYPES.map(function(t){
            return '<button type="button" class="btn btn-default btn-xs ah-type-pick" data-t="'+esc(t)+'" style="margin:0 4px 4px 0;">'+esc(t)+'</button>';
        }).join(''));
        $('#ah-type').val(AH_TYPES[0]);
        $('#ah-part-kw').val(''); $('#ah-part-results').hide().empty(); $('#ah-part-picked').empty();
        $('#ah-qty').val(0); $('#ah-sample').val(3); $('#ah-remark').val('');
        $('#adhocModal').modal('show');
    });
    $(document).on('click','.ah-type-pick', function(){ $('#ah-type').val($(this).attr('data-t')); });
    function ahSearch(){
        var kw=$('#ah-part-kw').val().trim();
        $('#ah-part-results').show().html('<div class="search-result-item muted-help">搜尋中…</div>');
        $.post(V2API, { v2action:'part_search', keyword:kw }, function(res){
            if(!res.success){ $('#ah-part-results').html('<div class="search-result-item text-danger">'+esc(res.message||'搜尋失敗')+'</div>'); return; }
            var rows=res.rows||[];
            $('#ah-part-results').html(rows.length ? rows.map(function(r){
                return '<div class="search-result-item ah-part-pick" data-id="'+r.d_id+'" data-no="'+esc(r.D_Setting_Id)+'">'+
                       esc(r.D_Setting_Id)+(r.Revision?' <span class="muted-help">Rev '+esc(r.Revision)+'</span>':'')+'</div>';
            }).join('') : '<div class="search-result-item muted-help">查無此料號</div>');
        }, 'json');
    }
    $('#btn-ah-search').on('click', function(e){ e.preventDefault(); ahSearch(); });
    $('#ah-part-kw').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); ahSearch(); } });
    $(document).on('click','.ah-part-pick', function(){
        ahPart={ d_id:parseInt($(this).data('id')), part_no:String($(this).attr('data-no')) };
        $('#ah-part-picked').html('已選料號：<b>'+esc(ahPart.part_no)+'</b>');
        $('#ah-part-results').hide();
    });
    $('#btn-ah-create').on('click', function(){
        var type=$('#ah-type').val().trim();
        if(!type){ alert('請選擇或輸入檢驗類型'); return; }
        if(!ahPart){ alert('請先搜尋並選擇料號'); $('#ah-part-kw').focus(); return; }
        var sample=Math.max(1, parseInt($('#ah-sample').val())||1);
        // 自行組出 ctx（不打 load_context，因為沒有 bom_ing_fid）
        ctx = { bom_ing_fid:0, bom:'—（無製令）', part_no:ahPart.part_no, client:'', order_qty:parseInt($('#ah-qty').val())||0,
                process:type, d_id:ahPart.d_id, sample_qty:sample, adhoc:true };
        state.demo=false; state.sampleN=sample; state.editFormId=null; state.sampleChanges=[];
        state.batches=[{ no:1, status:'WAIT', rounds:[] }]; state.curBatch=0;
        $('#adhocModal').modal('hide');
        $('#step-search').hide();
        $('#mode-banner').html('<i class="fa fa-plus-square-o"></i> <b>臨時檢驗單</b>（'+esc(type)+'）：此檢驗<b>沒有製令與製程</b>，存檔後一樣寫入正式檢驗表、可列印與查歷史。');
        renderCtxBar();
        $('#main-area').show(); $('#dock').show(); syncDockPad();
        $('#inp-qty').val(ctx.order_qty); $('#inp-sample').val(sample).data('prev', sample);
        $('#inp-remark').val($('#ah-remark').val());
        $('#chk-save-std').prop('checked', false).closest('label').hide();  // 臨時檢驗不改寫料號標準
        renderBatches();
        renderItems([]);
        $('#no-std-hint').hide();
        $('#btn-save,#btn-redo').prop('disabled', !state.can_fill);
        $('#btn-redo').hide();      // 臨時檢驗沒有「退回重做」批次概念
        addItemRow();               // 直接給一列可填
    });
    // 點最近的臨時檢驗單 → 用既有的歷程檢視/修改流程開啟
    $(document).on('click','.ah-open', function(){
        var qid=$(this).data('id');
        $.post(API, { action:'get_history_record', qc_form_id:qid }, function(res){
            if(!res.success){ alert('載入失敗：'+res.message); return; }
            var h=res.header;
            ctx = { bom_ing_fid:0, bom:'—（無製令）', part_no:'', client:'', order_qty:h.incoming_qty||0,
                    process:h.process_name||'臨時檢驗', d_id:0, sample_qty:h.sample_qty||3, adhoc:true, readonly:!res.can_edit };
            state.demo=false; state.sampleN=h.sample_qty||3;
            state.batches=[{ no:1, status:(h.check_result==='NG'?'NG':'OK'), rounds:[{
                date:String(h.check_date||'').substring(0,16), status:(h.check_result==='NG'?'NG':'OK'),
                qc_form_id:h.qc_form_id, round_no:1, ng_qty:h.ng_qty||0,
                edit_unlocked:h.edit_unlocked, self_grace:(res.self_grace?1:0), edit_log_count:0,
                ncr_decision:h.ncr_decision, abnormal_order_id:h.abnormal_order_id, abnormal_order_no:h.abnormal_order_no
            }] }]; state.curBatch=0;
            $('#step-search').hide();
            $('#mode-banner').html('<i class="fa fa-folder-open-o"></i> 已開啟臨時檢驗單 #'+qid+'（'+esc(ctx.process)+'）');
            renderCtxBar(); $('#main-area').show(); $('#dock').show(); syncDockPad();
            $('#chk-save-std').closest('label').hide();
            renderBatches();
            openEditRecord(qid);
        }, 'json');
    });

    // =====================================================================
    // 批次 / 檢驗歷程
    // =====================================================================
    function statusLabel(s){
        return ({ OK:'<span class="st-ok">✔合格</span>', NG:'<span class="st-ng">✘不良</span>',
                  REDO:'<span class="st-redo">⟳重做中</span>', WAIT:'<span class="st-wait">…待驗</span>' })[s]||'';
    }
    function buildBatchesFromHistory(history){
        state.batches=[]; var byBatch={};
        (history||[]).forEach(function(h){
            var b=h.batch_no||1;
            if(!byBatch[b]) byBatch[b]={ no:b, status:'WAIT', rounds:[] };
            byBatch[b].rounds.push({
                date:(h.check_date||h.created_at||''), status:(h.check_result==='NG'?'NG':'OK'),
                qc_form_id:h.qc_form_id, round_no:(h.round_no||1), ng_qty:(h.ng_qty||0),
                edit_unlocked:(parseInt(h.edit_unlocked)||0), self_grace:(parseInt(h.self_grace)||0),
                edit_log_count:(parseInt(h.edit_log_count)||0),
                last_edited_by:h.last_edited_by, last_edited_at:h.last_edited_at,
                ncr_decision:h.ncr_decision, ncr_skip_reason:h.ncr_skip_reason,
                abnormal_order_id:h.abnormal_order_id, abnormal_order_no:h.abnormal_order_no
            });
            byBatch[b].status=(h.check_result==='NG'?'NG':'OK');
        });
        Object.keys(byBatch).map(Number).sort(function(a,b){return a-b;}).forEach(function(k){ state.batches.push(byBatch[k]); });
        if(!state.batches.length) state.batches.push({ no:1, status:'WAIT', rounds:[] });
        state.curBatch = state.batches.length-1;
    }
    function renderBatches(){
        var h=state.batches.map(function(b,i){
            return '<span class="batch-chip '+(i===state.curBatch?'active':'')+'" data-i="'+i+'">批次'+b.no+' '+statusLabel(b.status)+'</span>';
        }).join('');
        h+='<span class="batch-chip" id="btn-new-batch" style="border-style:dashed;"><i class="fa fa-plus"></i> 新到貨批次</span>';
        $('#batch-bar').html(h);
        var b=state.batches[state.curBatch];
        $('#batch-summary').text('（目前批次'+(b?b.no:1)+'，已檢驗 '+(b?b.rounds.length:0)+' 次）');
        renderHistory();
    }
    $('#btn-toggle-batch').on('click', function(e){
        e.preventDefault();
        $('#batch-zone').slideToggle(120);
        $(this).find('i').toggleClass('fa-caret-right fa-caret-down');
    });
    $('#batch-bar').on('click','.batch-chip[data-i]', function(){ state.curBatch=$(this).data('i'); renderBatches(); });
    $('#batch-bar').on('click','#btn-new-batch', function(){
        state.batches.push({ no:state.batches.length+1, status:'WAIT', rounds:[] });
        state.curBatch=state.batches.length-1; renderBatches();
    });
    function renderHistory(){
        var b=state.batches[state.curBatch];
        if(!b || !b.rounds.length){ $('#batch-history').html('<div class="muted-help">此批次尚無檢驗紀錄。</div>'); return; }
        var rows=b.rounds.map(function(r,i){
            var act='';
            if(r.qc_form_id){
                var locked=!r.edit_unlocked, selfGrace=!!r.self_grace && state.can_fill;
                if(locked && !state.is_supervisor && !selfGrace) act+='<span class="muted-help" title="已鎖定，需主管開放"><i class="fa fa-lock"></i> 鎖定</span> ';
                if(locked && state.is_supervisor) act+='<button class="btn btn-xs btn-warm-o act-unlock" data-id="'+r.qc_form_id+'"><i class="fa fa-unlock-alt"></i> 開放修改</button> ';
                if((!locked && state.can_fill) || state.is_supervisor || selfGrace)
                    act+='<button class="btn btn-xs btn-warm act-edit" data-id="'+r.qc_form_id+'"'+(locked&&selfGrace?' title="本人寬限期內可自改"':'')+'><i class="fa fa-pencil"></i> 修改'+(locked&&selfGrace?'（本人）':'')+'</button> ';
                if(r.edit_log_count>0) act+='<button class="btn btn-xs btn-default act-log" data-id="'+r.qc_form_id+'"><i class="fa fa-history"></i> 紀錄</button> ';
                if(IS_SUPER) act+='<button class="btn btn-xs act-del-rec" data-id="'+r.qc_form_id+'" title="完全刪除此筆檢驗紀錄（測試用，需密碼）" style="background:#8F3016;color:#fff;border:0;"><i class="fa fa-trash"></i></button>';
            }
            var edited=r.last_edited_at ? ('<br><small class="muted-help">最後修改：'+esc(r.last_edited_by||'')+' '+esc(r.last_edited_at)+'</small>') : '';
            var ncr='';
            if(r.status==='NG' && r.qc_form_id){
                if(r.abnormal_order_no) ncr='<a class="btn btn-xs btn-default" href="../QA/qa_abnormal_view.php?id='+r.abnormal_order_id+'" target="_blank">'+esc(r.abnormal_order_no)+'</a>';
                else if(r.ncr_decision==='SKIP') ncr='<span class="label label-default" title="不開單原因：'+esc(r.ncr_skip_reason||'')+'">不開單</span>';
                else if(state.can_fill || state.is_supervisor) ncr='<button class="btn btn-xs btn-coral act-open-ncr" data-id="'+r.qc_form_id+'"><i class="fa fa-file-text-o"></i> 開異常單</button>';
                else ncr='<span class="label label-default">未開單</span>';
            }
            return '<tr class="history-row"><td>第'+(r.round_no||(i+1))+'次</td><td>'+esc(r.date)+edited+'</td><td>'+statusLabel(r.status)+
                   '</td><td>不良 '+(r.ng_qty||0)+'</td><td>'+ncr+'</td><td>'+act+'</td></tr>';
        }).join('');
        $('#batch-history').html(
            '<table class="table table-condensed table-bordered" style="background:#fff;"><thead>'+
            '<tr><th width="70">次數</th><th width="180">日期</th><th width="90">結果</th><th width="80">不良</th><th width="110">異常單</th><th width="230">操作</th></tr>'+
            '</thead><tbody>'+rows+'</tbody></table>');
    }
    $('#batch-history').on('click','.act-unlock', function(){
        var id=$(this).data('id');
        var reason=prompt('開放此筆修改的原因（會記錄）：','');
        if(reason===null) return;
        $.post(API,{action:'unlock_record',qc_form_id:id,reason:reason},function(res){
            if(!res.success){ alert('開放失敗：'+res.message); return; }
            alert('已開放此筆修改。'); reloadContext();
        },'json');
    });
    $('#batch-history').on('click','.act-edit', function(){ openEditRecord($(this).data('id')); });

    // ---------- 完全刪除檢驗紀錄（僅超級管理員；測試用） ----------
    var delTarget=null;
    $(document).on('click','.act-del-rec', function(){ openDeleteRec($(this).data('id')); });
    function openDeleteRec(qid){
        delTarget=qid;
        $('#del-info').html('載入中…'); $('#del-form').hide(); $('#btn-del-go').hide();
        $('#del-confirm').val(''); $('#del-password').val('');
        $('#delRecModal').modal('show');
        $.post(V2API, { v2action:'del_preview', qc_form_id:qid }, function(res){
            if(!res.success){ $('#del-info').html('<div class="alert alert-danger" style="margin:0;">'+esc(res.message||'查詢失敗')+'</div>'); return; }
            var d=res.info;
            var warn = d.abnormal_order_no
                ? '<div class="alert alert-danger" style="margin-top:10px;"><b>此檢驗已開立異常單 '+esc(d.abnormal_order_no)+'</b><br>'+
                  '異常單是另一份已發出通知、待回覆回簽的正式文件，直接刪掉來源檢驗會讓它指向不存在的紀錄，因此<b>不允許刪除</b>。請先處理或作廢該異常單。</div>'
                : '';
            $('#del-info').html(
                '<div class="muted-help">以下資料將被<b>永久刪除</b>，無法復原（會在系統稽核紀錄 audit_log 留下完整內容）：</div>'+
                '<table class="table table-condensed table-bordered" style="margin-top:8px;">'+
                '<tr><th width="130">檢驗單號</th><td>#'+d.qc_form_id+'　'+(d.bom_ing_fid>0?('製令待驗 fid '+d.bom_ing_fid):'<b>臨時檢驗單</b>（無製令）')+'</td></tr>'+
                '<tr><th>料號 / 製程</th><td>'+esc(d.part_no||'—')+'　/　'+esc(d.process_name||'—')+'</td></tr>'+
                '<tr><th>批次 / 複驗</th><td>批次 '+d.batch_no+'　第 '+d.round_no+' 次</td></tr>'+
                '<tr><th>判定</th><td>'+(d.check_result==='NG'?'<span class="st-ng">✘ 不良</span>':'<span class="st-ok">✔ 合格</span>')+'　不良 '+d.ng_qty+' 件</td></tr>'+
                '<tr><th>建立</th><td>'+esc(d.created_by||'')+'　'+String(d.created_at||'').substring(0,16)+'</td></tr>'+
                '<tr><th>連帶刪除</th><td>實測明細 <b>'+d.n_measurement+'</b> 筆　／　修改稽核 <b>'+d.n_edit_log+'</b> 筆　／　抽驗數變更 <b>'+d.n_sample_change+'</b> 筆</td></tr>'+
                '</table>'+warn+
                '<div class="muted-help">註：此料號的<b>檢驗標準（項目/公差）不會被刪除</b>，只刪這一張檢驗紀錄與其明細。</div>');
            if(!d.abnormal_order_no){ $('#del-form').show(); $('#btn-del-go').show(); setTimeout(function(){ $('#del-confirm').focus(); },200); }
        }, 'json');
    }
    $('#btn-del-go').on('click', function(){
        if(!delTarget) return;
        var y=$('#del-confirm').val().trim(), pw=$('#del-password').val();
        if(y!=='Y'){ alert('請輸入大寫 Y 確認'); $('#del-confirm').focus(); return; }
        if(!pw){ alert('請輸入超級管理員密碼'); $('#del-password').focus(); return; }
        var $b=$(this).prop('disabled',true);
        $.post(V2API, { v2action:'del_inspection', qc_form_id:delTarget, confirm:y, password:pw }, function(res){
            $b.prop('disabled',false);
            if(!res.success){ alert('刪除失敗：'+res.message); return; }
            $('#delRecModal').modal('hide');
            flashMsg('已完全刪除檢驗紀錄 #'+res.deleted.qc_form_id);
            delTarget=null;
            // 若刪掉的正是目前正在修改的那筆，先退出修改模式
            if(state.editFormId && String(state.editFormId)===String(res.deleted.qc_form_id)) state.editFormId=null;
            if(ctx && ctx.adhoc){ location.href = 'inspection_entry_v2.php' + (new URLSearchParams(location.search).get('popup')?'?popup=1':''); }
            else reloadContext();
        }, 'json').fail(function(x){ $b.prop('disabled',false); alert('刪除錯誤：'+x.responseText); });
    });
    $('#batch-history').on('click','.act-log',  function(){ viewEditLog($(this).data('id')); });
    $('#batch-history').on('click','.act-open-ncr', function(){
        var qid=$(this).data('id');
        $.post(API,{action:'get_history_record',qc_form_id:qid},function(res){
            if(!res.success){ alert('載入失敗：'+res.message); return; }
            if(res.header && res.header.abnormal_order_no){
                alert('此筆檢驗已開立異常單 '+res.header.abnormal_order_no+'，不可重複開立。'); reloadContext(); return;
            }
            QAAbnormalModal.open({
                source_type:'QC', source_id:qid,
                title_suffix:(ctx?('料號 '+ctx.part_no):''),
                prefill: ngPrefill(res.header.ng_qty, ngSummaryText(res.items)),
                onCreated:function(r){
                    $.post(API,{action:'set_ncr_decision',qc_form_id:qid,decision:'OPEN',abnormal_order_id:r.id},function(){
                        alert('異常單 '+r.no+' 已開立並發送通知。'); reloadContext();
                    },'json');
                }
            });
        },'json');
    });

    // ---------- 修改模式 ----------
    function openEditRecord(qcFormId){
        $.post(API,{action:'get_history_record',qc_form_id:qcFormId},function(res){
            if(!res.success){ alert('載入失敗：'+res.message); return; }
            if(!res.can_edit){ alert('此筆已鎖定，請主管先開放修改。'); return; }
            var h=res.header;
            state.editFormId=qcFormId;
            // 列印簽章用：已存檔紀錄的簽章日期＝檢驗日、檢驗員＝存檔者
            state.editMeta={ check_date:h.check_date||'', creator_name:h.creator_name||'' };
            state.sampleN=h.sample_qty||state.sampleN;
            $('#inp-qty').val(h.incoming_qty||0);
            $('#inp-sample').val(state.sampleN);
            $('#inp-remark').val(h.main_remark||'');
            renderItems(res.items||[]);
            (h.pcs_verdicts||[]).forEach(function(pv,i){
                if(!pv || !pv.m || !MODEL.pcs[i]) return;
                MODEL.pcs[i].m=1; MODEL.pcs[i].v=(pv.v==='NG'?'NG':'OK');
            });
            recalc();
            $('#no-std-hint').hide();
            $('#edit-form-id').text(qcFormId);
            $('#edit-mode-banner').show();
            $('#chk-save-std').prop('checked',false).closest('label').hide();
            $('#btn-save').html('<i class="fa fa-save"></i> 儲存修改');
            $('#btn-redo').hide();
            $('html,body').animate({scrollTop:0},200);
        },'json').fail(function(x){ alert('載入錯誤：'+x.responseText); });
    }
    function exitEditMode(){
        state.editFormId=null; state.editMeta=null;
        $('#edit-mode-banner').hide();
        $('#chk-save-std').prop('checked',true).closest('label').show();
        $('#btn-save').html('<i class="fa fa-save"></i> 儲存檢驗結果');
        $('#btn-redo').show();
        reloadContext();
    }
    $('#btn-exit-edit').on('click', function(){
        var qid=state.editFormId;
        if(qid){ $.post(API,{action:'relock_record',qc_form_id:qid},function(){ exitEditMode(); },'json').fail(exitEditMode); }
        else exitEditMode();
    });
    function viewEditLog(qcFormId){
        $.post(API,{action:'get_edit_log',qc_form_id:qcFormId},function(res){
            if(!res.success){ alert('查詢失敗：'+res.message); return; }
            var logs=res.logs||[];
            var html='<table class="table table-condensed table-bordered"><thead><tr><th>時間</th><th>行為</th><th>人員</th><th>原因/變更</th></tr></thead><tbody>';
            if(!logs.length) html+='<tr><td colspan="4" class="text-center muted-help">尚無修改紀錄</td></tr>';
            logs.forEach(function(l){
                var actMap={UNLOCK:'開放修改',EDIT:'修改',RELOCK:'回鎖'};
                var detail=esc(l.reason||'');
                if(l.changes_json) detail+=' <a href="#" class="show-diff" data-json=\''+esc(l.changes_json)+'\'>[改前/改後]</a>';
                html+='<tr><td>'+esc(l.changed_at)+'</td><td>'+(actMap[l.action]||l.action)+'</td><td>'+esc(l.user_cname||l.changed_by)+'</td><td>'+detail+'</td></tr>';
            });
            html+='</tbody></table>';
            $('#log-modal-body').html(html); $('#logModal').modal('show');
        },'json');
    }
    $('#log-modal-body').on('click','.show-diff', function(e){ e.preventDefault();
        try{ var d=JSON.parse($(this).attr('data-json')); alert('改前：\n'+JSON.stringify(d.before,null,2)+'\n\n改後：\n'+JSON.stringify(d.after,null,2)); }
        catch(_){ alert('無法解析變更內容'); }
    });

    // =====================================================================
    // 草稿 / 自動存檔（沿用 save_draft / get_draft / discard_draft）
    // =====================================================================
    var draftTimer=null, draftDirty=false;
    // 草稿走舊頁 save_draft，需要真實 bom_ing_fid；臨時檢驗單（adhoc）不適用
    function draftEligible(){ return ctx && !ctx.adhoc && !state.demo && !state.editFormId && state.can_fill && ctx.d_id>0 && ctx.bom_ing_fid>0; }
    function scheduleDraftSave(){
        if(!draftEligible()) return;
        draftDirty=true;
        if(draftTimer) clearTimeout(draftTimer);
        draftTimer=setTimeout(saveDraftNow, 2500);
    }
    function saveDraftNow(){
        if(!draftEligible() || !draftDirty) return;
        var items=collectItems(); if(!items.length){ draftDirty=false; return; }
        var b=state.batches[state.curBatch]||{no:1,rounds:[]};
        $.post(API,{ action:'save_draft', bom_ing_fid:ctx.bom_ing_fid, d_id:ctx.d_id, process_name:ctx.process,
            batch_no:b.no, round_no:(b.rounds.length+1),
            incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
            main_remark:$('#inp-remark').val(), items:JSON.stringify(items), pcs_verdicts:JSON.stringify(collectPcsVerdicts())
        }, function(res){
            if(res && res.success){
                draftDirty=false; state.draftFormId=res.draft_form_id;
                var t=new Date(), p=function(n){return('0'+n).slice(-2);};
                $('#draft-status').html('<i class="fa fa-check"></i> 已自動存草稿 '+p(t.getHours())+':'+p(t.getMinutes())+':'+p(t.getSeconds()));
            }
        }, 'json');
    }
    $(window).on('beforeunload', function(){ if(draftEligible() && draftDirty){ try{ saveDraftNow(); }catch(e){} } });
    function maybeOfferDraft(draftId){
        if(!draftId || state.editFormId){ $('#draft-banner').remove(); return; }
        state.draftFormId=draftId;
        if(!$('#draft-banner').length) $('#mode-banner').after('<div id="draft-banner" class="banner banner-info"></div>');
        $('#draft-banner').html('<i class="fa fa-clock-o"></i> 偵測到您先前<b>未送出的草稿</b>（關掉視窗前自動保存的內容）。'+
            '<button class="btn btn-xs btn-warm" id="btn-restore-draft" style="margin-left:8px;"><i class="fa fa-download"></i> 載回草稿</button> '+
            '<button class="btn btn-xs btn-default" id="btn-discard-draft"><i class="fa fa-trash"></i> 捨棄</button>').show();
    }
    $(document).on('click','#btn-restore-draft', function(){
        var did=state.draftFormId; if(!did) return;
        $.post(API,{action:'get_draft',qc_form_id:did}, function(res){
            if(!res.success || !res.draft){ alert('載回失敗或草稿已不存在'); $('#draft-banner').hide(); return; }
            var d=res.draft;
            state.sampleN=parseInt(d.sample_qty)||state.sampleN;
            $('#inp-qty').val(d.incoming_qty||0); $('#inp-sample').val(state.sampleN); $('#inp-remark').val(d.main_remark||'');
            renderItems(d.items||[]);
            (d.pcs||[]).forEach(function(pv,i){ if(pv && pv.m && MODEL.pcs[i]){ MODEL.pcs[i].m=1; MODEL.pcs[i].v=(pv.v==='NG'?'NG':'OK'); } });
            recalc();
            $('#no-std-hint').hide(); $('#draft-banner').hide();
        }, 'json');
    });
    $(document).on('click','#btn-discard-draft', function(){
        if(!confirm('確定捨棄此草稿？此動作無法復原。')) return;
        $.post(API,{action:'discard_draft',bom_ing_fid:(ctx?ctx.bom_ing_fid:0),qc_form_id:(state.draftFormId||0)}, function(){
            state.draftFormId=0; $('#draft-banner').hide();
        }, 'json');
    });

    // =====================================================================
    // 通用樣板匯入
    // =====================================================================
    function openTpl(){
        $('#tpl-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $('#tplModal').modal('show');
        $.post(API,{action:'manage_templates',sub_action:'list'},function(res){
            if(!res.success){ $('#tpl-list').html('<div class="list-group-item text-danger">載入失敗</div>'); return; }
            var d=res.data||[];
            $('#tpl-list').html(d.length ? d.map(function(t){
                return '<a href="#" class="list-group-item tpl-pick" data-id="'+t.template_id+'">'+esc(t.template_name)+'</a>';
            }).join('') : '<div class="list-group-item text-muted">尚無樣板（可到 設定 → 通用樣板管理 建立）</div>');
        },'json');
    }
    $('#btn-import-tpl,#btn-import-tpl2').on('click', function(e){ e.preventDefault(); openTpl(); });
    $('#tpl-list').on('click','.tpl-pick', function(e){
        e.preventDefault();
        var tid=$(this).data('id');
        $.post(API,{action:'manage_templates',sub_action:'get_items',template_id:tid},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var incoming=(res.items||[]).map(normItem);
            if(MODEL.items.length && !confirm('要「取代」目前的檢驗項目嗎？\n按「確定」取代，按「取消」則附加在後面。')){
                MODEL.items=MODEL.items.concat(incoming);
            } else {
                MODEL.items=incoming;
            }
            focusItem=0; $('#no-std-hint').hide(); $('#tplModal').modal('hide'); render(); scheduleDraftSave();
        },'json');
    });

    // =====================================================================
    // 標準公差／客戶公差：自動套用（依標準值落在哪個區間帶入上下公差）
    //   只套用在上下限都還沒填的欄位，已填的一律保留不覆蓋（使用者填的優先）
    // =====================================================================
    var TOL_TABLES = [];      // 目前可用公差表清單（含 customer_name/band_count）
    var TOL_CAN_MANAGE = false;
    var TOL_MATCH_ID = 0;
    var tolMgCustOptions = null;

    function openTolPick(){
        $('#tol-pick-list').html('<div class="text-muted">載入中…</div>');
        $('#tolPickModal').modal('show');
        $.post(V2API, { v2action:'tol_tables', client:(ctx&&ctx.client)||'' }, function(res){
            if(!res.success){ $('#tol-pick-list').html('<div class="text-danger">載入失敗：'+esc(res.message||'')+'</div>'); return; }
            TOL_TABLES = res.rows||[]; TOL_CAN_MANAGE = !!res.can_manage; TOL_MATCH_ID = res.match_id||0;
            $('#tol-pick-manage-wrap').toggle(TOL_CAN_MANAGE);
            if(!TOL_TABLES.length){
                $('#tol-pick-list').html('<div class="text-muted">尚未設定任何公差表。'+(TOL_CAN_MANAGE?'請按下方「管理公差表」新增。':'請洽管理員設定。')+'</div>');
                return;
            }
            $('#tol-pick-list').html(TOL_TABLES.map(function(t){
                var recommended = (TOL_MATCH_ID && t.id==TOL_MATCH_ID);
                var label = esc(t.name) + (t.customer_name? '　<span class="muted-help">（客戶：'+esc(t.customer_name)+'）</span>' : '　<span class="muted-help">（通用標準）</span>')
                            + '　<span class="muted-help">共 '+(t.band_count||0)+' 個區間</span>'
                            + (recommended? '　<span style="color:#3c763d;font-weight:bold;">★建議（客戶專屬）</span>' : '');
                return '<label class="radio" style="display:block;padding:6px 4px;border-bottom:1px solid #eee;">'
                     + '<input type="radio" name="tolpick" value="'+t.id+'" '+(recommended?'checked':'')+'> '+label+'</label>';
            }).join(''));
            if(!TOL_MATCH_ID){ $('#tol-pick-list input[name=tolpick]').first().prop('checked', true); }
        }, 'json');
    }
    $(document).on('click', '#btn-apply-tol', function(){ openTolPick(); });

    $(document).on('click', '#btn-tol-apply-go', function(){
        var tid = $('input[name=tolpick]:checked').val();
        if(!tid){ alert('請選擇一個公差表'); return; }
        $.post(V2API, { v2action:'tol_bands', table_id:tid }, function(res){
            if(!res.success){ alert('載入公差區間失敗：'+(res.message||'')); return; }
            var bands=(res.rows||[]).map(function(b){ return { lo:parseFloat(b.min_value), hi:parseFloat(b.max_value), up:b.plus_tolerance, mi:b.minus_tolerance }; });
            var applied=0, outOfRange=0;
            MODEL.items.forEach(function(it){
                if(it.type==='OKNG') return;
                if(String(it.up).trim()!=='' || String(it.lo).trim()!=='') return; // 已填不覆蓋
                var v = parseFloat(it.std);
                if(isNaN(v)) return;
                var b = bands.find(function(x){ return v>=x.lo && v<=x.hi; });
                if(!b){ outOfRange++; return; }
                it.up=trimNum(String(b.up)); it.lo=trimNum(String(b.mi));
                applied++;
            });
            $('#tolPickModal').modal('hide');
            render(); scheduleDraftSave();
            var msg='已套用 '+applied+' 個項目的上下公差。';
            if(outOfRange>0) msg+='\n另有 '+outOfRange+' 個項目的標準值不在此公差表任何區間內，請自行填寫。';
            alert(msg);
        }, 'json');
    });

    $(document).on('click', '#btn-tol-manage', function(e){
        e.preventDefault();
        $('#tolPickModal').modal('hide');
        openTolManage();
    });

    function openTolManage(){
        $('#tolManageModal').modal('show');
        renderTolMgList();
        renderTolMgEditor(null);
    }
    function renderTolMgList(){
        $('#tol-mg-list').html(TOL_TABLES.map(function(t){
            return '<a href="#" class="list-group-item tol-mg-pick" data-id="'+t.id+'">'+esc(t.name)
                 + (t.customer_name?'<br><small class="text-muted">客戶：'+esc(t.customer_name)+'</small>':'<br><small class="text-muted">通用標準</small>')+'</a>';
        }).join('') || '<div class="text-muted" style="padding:8px;">尚無公差表</div>');
    }
    function loadTolCustomerOptions(cb){
        if(tolMgCustOptions){ cb(tolMgCustOptions); return; }
        $.post(V2API,{v2action:'tol_customer_options'}, function(res){
            tolMgCustOptions = (res&&res.success)? (res.rows||[]) : [];
            cb(tolMgCustOptions);
        }, 'json');
    }
    function tolBandRowHtml(b){
        b=b||{};
        return '<tr><td><input type="number" step="any" class="form-control input-sm tb-min" value="'+esc(b.min_value!=null?b.min_value:'')+'"></td>'
             + '<td><input type="number" step="any" class="form-control input-sm tb-max" value="'+esc(b.max_value!=null?b.max_value:'')+'"></td>'
             + '<td><input type="number" step="any" class="form-control input-sm tb-plus" value="'+esc(b.plus_tolerance!=null?b.plus_tolerance:'')+'"></td>'
             + '<td><input type="number" step="any" class="form-control input-sm tb-minus" value="'+esc(b.minus_tolerance!=null?b.minus_tolerance:'')+'"></td>'
             + '<td><a href="#" class="tb-del text-danger"><i class="fa fa-trash"></i></a></td></tr>';
    }
    function renderTolEditorShell(t, bands){
        t=t||{id:0,name:'',customer_id:''};
        var html = '<div class="form-group"><label>公差表名稱</label><input type="text" class="form-control input-sm" id="tol-ed-name" value="'+esc(t.name||'')+'"></div>'
          + '<div class="form-group"><label>對應客戶（選填，留空＝通用標準）</label>'
          + '<div style="display:flex;gap:6px;align-items:center;">'
          + '<input type="text" class="form-control input-sm" id="tol-ed-cust-filter" placeholder="輸入客戶名稱篩選…" style="max-width:220px;">'
          + '<select class="form-control input-sm" id="tol-ed-cust" style="flex:1;"><option value="">（通用標準，不指定客戶）</option></select>'
          + '</div></div>'
          + '<table class="table table-condensed"><thead><tr><th>標準值下限</th><th>標準值上限</th><th>上公差</th><th>下公差</th><th></th></tr></thead>'
          + '<tbody id="tol-ed-bands">'+(bands&&bands.length? bands.map(tolBandRowHtml).join('') : tolBandRowHtml())+'</tbody></table>'
          + '<button class="btn btn-default btn-sm" id="btn-tol-ed-addrow"><i class="fa fa-plus"></i> 新增區間</button>'
          + '<div style="margin-top:12px;text-align:right;">'
          + (t.id? '<button class="btn btn-default" id="btn-tol-ed-del" style="color:#DD5138;margin-right:8px;"><i class="fa fa-trash"></i> 刪除此公差表</button>' : '')
          + '<button class="btn btn-warm" id="btn-tol-ed-save">儲存</button></div>';
        $('#tol-mg-editor').html(html).data('id', t.id||0);
        loadTolCustomerOptions(function(rows){
            var $sel=$('#tol-ed-cust');
            rows.forEach(function(r){ $sel.append($('<option>').val(r.customer_id).text(r.customer)); });
            if(t.customer_id) $sel.val(t.customer_id);
        });
    }
    function renderTolMgEditor(t){
        if(!t){ renderTolEditorShell(null, null); return; }
        $('#tol-mg-editor').html('<div class="text-muted">載入中…</div>');
        $.post(V2API,{v2action:'tol_bands', table_id:t.id}, function(res){
            renderTolEditorShell(t, (res&&res.success)? res.rows : []);
        }, 'json');
    }
    $(document).on('click', '.tol-mg-pick', function(e){
        e.preventDefault();
        var id=+$(this).data('id');
        var t=TOL_TABLES.find(function(x){ return x.id==id; });
        renderTolMgEditor(t);
    });
    $(document).on('click', '#btn-tol-mg-new', function(){ renderTolMgEditor(null); });
    $(document).on('click', '#btn-tol-ed-addrow', function(){ $('#tol-ed-bands').append(tolBandRowHtml()); });
    $(document).on('click', '.tb-del', function(e){
        e.preventDefault();
        var $tb=$('#tol-ed-bands');
        if($tb.find('tr').length<=1){ $(this).closest('tr').find('input').val(''); return; }
        $(this).closest('tr').remove();
    });
    // 客戶下拉打字篩選：此頁未載入全站共用 eg_input_rules.js（已有大量現有鍵盤/Enter流程，
    // 硬套可能改變既有輸入行為），這裡僅針對本篩選框做最小範圍的本地實作，符合「長清單可打字篩選」的精神
    $(document).on('input', '#tol-ed-cust-filter', function(){
        var kw=$(this).val().trim().toLowerCase();
        $('#tol-ed-cust option').each(function(){
            if($(this).val()==='') return; // 永遠保留「通用標準」選項
            var show = !kw || $(this).text().toLowerCase().indexOf(kw)>=0;
            $(this).toggle(show);
        });
    });
    $(document).on('click', '#btn-tol-ed-save', function(){
        var id=$('#tol-mg-editor').data('id')||0;
        var name=$('#tol-ed-name').val().trim();
        if(!name){ alert('請輸入公差表名稱'); return; }
        var custId=$('#tol-ed-cust').val()||'';
        var bands=[];
        $('#tol-ed-bands tr').each(function(){
            var $tr=$(this);
            var mn=$tr.find('.tb-min').val(), mx=$tr.find('.tb-max').val(), pu=$tr.find('.tb-plus').val(), mi=$tr.find('.tb-minus').val();
            if(mn===''&&mx===''&&pu===''&&mi==='') return; // 整列空白略過
            bands.push({min_value:mn, max_value:mx, plus_tolerance:pu, minus_tolerance:mi});
        });
        if(!bands.length){ alert('請至少填寫一個公差區間'); return; }
        $.post(V2API, { v2action:'tol_table_save', id:id, name:name, customer_id:custId, bands:JSON.stringify(bands) }, function(res){
            if(!res.success){ alert('儲存失敗：'+(res.message||'')); return; }
            alert('已儲存');
            refreshTolTables(function(){ renderTolMgList(); var t=TOL_TABLES.find(function(x){return x.id==res.id;}); if(t) renderTolMgEditor(t); });
        }, 'json');
    });
    $(document).on('click', '#btn-tol-ed-del', function(){
        var id=$('#tol-mg-editor').data('id')||0;
        if(!id) return;
        if(!confirm('確定要刪除此公差表？此動作無法復原。')) return;
        $.post(V2API,{v2action:'tol_table_delete', id:id}, function(res){
            if(!res.success){ alert('刪除失敗：'+(res.message||'')); return; }
            refreshTolTables(function(){ renderTolMgList(); renderTolMgEditor(null); });
        }, 'json');
    });
    function refreshTolTables(cb){
        $.post(V2API,{v2action:'tol_tables', client:(ctx&&ctx.client)||''}, function(res){
            if(res&&res.success){ TOL_TABLES=res.rows||[]; TOL_CAN_MANAGE=!!res.can_manage; TOL_MATCH_ID=res.match_id||0; }
            if(cb) cb();
        }, 'json');
    }

    // =====================================================================
    // 存檔前檢核：把「缺了什麼、缺在哪一項」講清楚，而不是存進去才發現沒有量具可追溯
    //   硬性擋下：① 有填實測值卻沒有項目名稱 ② 數值型項目有實測值卻沒選量具編號
    //             ③ 數值型項目有實測值卻沒填標準值（沒有標準就判不出 OK/NG）
    //   OK/NG 型（目視/功能檢查）不強制量具。
    // =====================================================================
    function validateBeforeSave(){
        var out=[];
        MODEL.items.forEach(function(it,i){
            var code=codeLabel(i), nm=(it.name||'').trim();
            it.readings.forEach(function(rd,r){
                var hasVal=false;
                for(var s=0;s<state.sampleN;s++){
                    var v=rd.vals[s];
                    if(v!=='' && v!=null && !(it.type==='OKNG' && v==='OK')) { hasVal=true; break; }
                }
                if(!hasVal) return;
                var where = code+(r>0?('（加量測 '+r+'）'):'')+'　'+(nm||'（未命名項目）');
                if(!nm) out.push({ i:i, r:r, field:'name', text:where+'：<b>未填檢驗項目名稱</b>' });
                if(it.type!=='OKNG'){
                    if(!rd.tool_id) out.push({ i:i, r:r, field:'tool', text:where+'：<b>未選擇量具編號</b>（品質紀錄需可追溯到實際使用的那一支量具）' });
                    if(r===0 && (it.std==null || String(it.std).trim()==='')) out.push({ i:i, r:r, field:'std', text:where+'：<b>未填標準值</b>（沒有標準值就無法判定 OK/NG）' });
                }
            });
        });
        return out;
    }
    function showValidateModal(probs){
        if(!$('#validateModal').length){
            $('body').append(
              '<div class="modal fade" id="validateModal" tabindex="-1" role="dialog"><div class="modal-dialog"><div class="modal-content">'+
              '<div class="modal-header" style="background:#DD5138;color:#fff;border-radius:6px 6px 0 0;">'+
              '<h4 class="modal-title"><i class="fa fa-exclamation-circle"></i> 尚未完成，無法存檔</h4></div>'+
              '<div class="modal-body"><div class="muted-help" style="margin-bottom:8px;">請補齊下列資料後再儲存（點一下可直接跳到該欄位）：</div>'+
              '<ol id="validate-list" style="padding-left:20px;line-height:1.9;"></ol></div>'+
              '<div class="modal-footer"><button class="btn btn-warm" data-dismiss="modal">我知道了，回去補</button></div>'+
              '</div></div></div>');
        }
        $('#validate-list').html(probs.map(function(p){
            return '<li><a href="#" class="vjump" data-i="'+p.i+'" data-r="'+p.r+'" data-f="'+p.field+'" style="color:var(--ink);">'+p.text+'</a></li>';
        }).join(''));
        $('#validateModal').modal('show');
    }
    $(document).on('click','.vjump', function(e){
        e.preventDefault();
        var i=+$(this).data('i'), r=+$(this).data('r'), f=$(this).data('f');
        $('#validateModal').modal('hide');
        view='GRID'; localStorage.setItem('qc2_view', view);
        if(f==='name' || f==='std') $('#chk-std-edit').prop('checked', true);
        render();
        setTimeout(function(){
            var $tr=$('#items-body tr[data-i="'+i+'"]');
            if(f==='tool'){ $('.tool-btn[data-i="'+i+'"][data-r="'+r+'"]').first().focus().click(); return; }
            var $t=$tr.find(f==='name' ? 'input.f-name' : 'input.f-std');
            if($t.length){ $t.focus(); $('html,body').animate({ scrollTop:$t.offset().top-160 }, 200); }
        }, 120);
    });

    // =====================================================================
    // 存檔後自動建立允收(OK)/異常(QQ)彙總紀錄，取代人工在待驗清單重複輸入一次
    // 數量邏輯（2026-08 使用者定案）：不良數(ng_qty)→異常；送驗數-不良數→允收；
    // 兩者可同時成立（同批部分合格部分不良），皆為 0 則略過該筆。
    // 容器僅隨允收(OK)寫入（沿用舊頁 _updateQC_check_list_ok.php 的 container/quantity 欄位）。
    // 沿用既有 store 端點，不重寫寫入邏輯；退回重做(asRedo)與臨時檢驗單(adhoc)不觸發。
    // =====================================================================
    function autoSubmitQcResult(fid, goodQty, badQty, remark, doneCb){
        if(!fid){ doneCb(null); return; }
        var containers = [$('#insp-container-1').val()||'', $('#insp-container-2').val()||''];
        var quantities = [$('#insp-quantity-1').val()||'', $('#insp-quantity-2').val()||''];
        var tasks=[];
        if(goodQty>0) tasks.push({ url:'../../src/store/_updateQC_check_list_ok.php', qtyField:'ok_total_qty', qty:goodQty, label:'允收', withContainer:true });
        if(badQty>0)  tasks.push({ url:'../../src/store/_updateQC_check_list_qq.php',  qtyField:'qq_total_qty', qty:badQty,  label:'異常', withContainer:false });
        if(!tasks.length){ doneCb({ skipped:true, goodQty:0, badQty:0, errors:[] }); return; }

        var errors=[];
        function runNext(idx){
            if(idx>=tasks.length){ doneCb({ skipped:false, goodQty:goodQty, badQty:badQty, errors:errors }); return; }
            var t=tasks[idx];
            var data={};
            data[t.qtyField]=[t.qty];
            data.QCmessage=[remark||''];
            data.qc_check_id=[''];
            if(t.withContainer){ data.container=containers; data.quantity=quantities; }
            $.post(t.url+'?bi='+encodeURIComponent(fid)+'&id='+encodeURIComponent(CURRENT_UID), data, function(res){
                if(!res || !res.success) errors.push(t.label+'：'+((res&&res.message)||'未知錯誤'));
                runNext(idx+1);
            },'json').fail(function(){
                errors.push(t.label+'：伺服器錯誤');
                runNext(idx+1);
            });
        }
        runNext(0);
    }

    // =====================================================================
    // 儲存 / 退回重做
    // =====================================================================
    function doSave(asRedo){
        if(state.demo){ alert('示範模式不寫入資料庫，請由待驗清單開啟實際待驗項目。'); return; }
        var items=collectItems();
        if(!items.length){ alert('請至少輸入一個檢驗項目'); return; }
        // 存檔前檢核：缺量具編號等硬性問題一律擋下，並明確告知哪一項缺什麼
        var probs = validateBeforeSave();
        if(probs.length){ showValidateModal(probs); return; }
        var unfilled = MODEL.items.filter(function(it){ return itemFilledCount(it)<state.sampleN; }).length;
        if(!asRedo && unfilled>0 && !confirm('尚有 '+unfilled+' 個檢驗項目未填滿 '+state.sampleN+' 件，仍要儲存嗎？')) return;
        // 疑似誤填：不擋存檔，但一定要再確認一次
        var warnCells=[];
        MODEL.items.forEach(function(it,i){
            it.readings.forEach(function(rd,r){
                rd.vals.forEach(function(v,s){
                    if(isOutlier(it,v)) warnCells.push(codeLabel(i)+' '+(it.name||'')+(r>0?('（加量測'+r+'）'):'')+' 第'+(s+1)+'件 = '+v+'（標準 '+it.std+'）');
                });
            });
        });
        if(warnCells.length && !confirm('下列實測值與標準值差異過大，可能是誤填：\n\n'+warnCells.join('\n')+
            '\n\n確定這些數值正確、要照這樣存檔嗎？')) return;

        if(state.editFormId){
            var reason=prompt('請填寫修改原因（必填，會記錄於稽核）：','');
            if(reason===null) return;
            if(reason.trim()===''){ alert('必須填寫修改原因'); return; }
            var $eb=$('#btn-save').prop('disabled',true);
            $.post(API,{ action:'update_inspection', qc_form_id:state.editFormId, reason:reason,
                incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
                main_remark:$('#inp-remark').val(), items:JSON.stringify(items),
                pcs_verdicts:JSON.stringify(collectPcsVerdicts())
            }, function(res){
                $eb.prop('disabled',false);
                if(!res.success){ alert('修改失敗：'+res.message); return; }
                var s=res.summary;
                flushSampleChanges(s.qc_form_id);
                if(window.opener && !window.opener.closed){
                    try{ window.opener.postMessage({type:'qc_inspection_done',bom_ing_fid:s.bom_ing_fid,summary:s,qc_form_id:s.qc_form_id,edited:true},'*'); }catch(e){}
                }
                alert('已儲存修改（qc_form_id='+s.qc_form_id+'）\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+'\n此筆已自動回鎖。');
                exitEditMode();
            },'json').fail(function(x){ $eb.prop('disabled',false); alert('修改錯誤：'+x.responseText); });
            return;
        }

        // 臨時檢驗單（無製令/無製程）：走 v2 後端 save_adhoc
        if(ctx.adhoc){
            var $ab=$('#btn-save').prop('disabled',true);
            $.post(V2API, { v2action:'save_adhoc', d_id:ctx.d_id, process_name:ctx.process,
                incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
                main_remark:$('#inp-remark').val(), items:JSON.stringify(items),
                pcs_verdicts:JSON.stringify(collectPcsVerdicts())
            }, function(res){
                $ab.prop('disabled',false);
                if(!res.success){ alert('儲存失敗：'+res.message); return; }
                var s=res.summary;
                flushSampleChanges(res.qc_form_id);
                state.batches[0].rounds.push({ date:'剛剛', status:s.check_result, qc_form_id:res.qc_form_id, round_no:1, ng_qty:s.ng_qty });
                state.batches[0].status=s.check_result;
                renderBatches();
                function done(){
                    alert('臨時檢驗單已儲存（qc_form_id='+res.qc_form_id+'）\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty);
                }
                if(s.check_result==='NG') openNgAsk(res.qc_form_id, s, items, done); else done();
            }, 'json').fail(function(x){ $ab.prop('disabled',false); alert('儲存錯誤：'+x.responseText); });
            return;
        }

        var b=state.batches[state.curBatch];
        var payload={ action:'save_inspection', bom_ing_fid:ctx.bom_ing_fid, d_id:ctx.d_id, part_no:ctx.part_no,
            process_name:ctx.process, batch_no:b.no, round_no:(b.rounds.length+1),
            incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
            main_remark:$('#inp-remark').val(), update_std:$('#chk-save-std').is(':checked')?'1':'0',
            items:JSON.stringify(items), pcs_verdicts:JSON.stringify(collectPcsVerdicts()) };
        var $btn=$(asRedo?'#btn-redo':'#btn-save').prop('disabled',true);
        $.post(API, payload, function(res){
            $btn.prop('disabled',false);
            if(!res.success){ alert('儲存失敗：'+res.message); return; }
            var s=res.summary;
            flushSampleChanges(res.qc_form_id);
            b.rounds.push({ date:'剛剛', status:(asRedo?'NG':s.check_result), qc_form_id:res.qc_form_id,
                            round_no:(b.rounds.length+1), ng_qty:s.ng_qty });
            b.status = asRedo ? 'REDO' : s.check_result;
            renderBatches();
            var hasOpener = window.opener && !window.opener.closed;

            function afterAutoSubmit(autoResult){
                if(hasOpener){
                    try{ window.opener.postMessage({ type:'qc_inspection_done', bom_ing_fid:s.bom_ing_fid, summary:s, qc_form_id:res.qc_form_id, redo:!!asRedo, autoSubmit:autoResult }, '*'); }catch(e){}
                }
                function autoMsg(){
                    if(!autoResult || autoResult.skipped) return '';
                    var t='\n已自動送出：允收 '+autoResult.goodQty+'　異常 '+autoResult.badQty;
                    if(autoResult.errors && autoResult.errors.length) t+='\n⚠ 部分自動送出失敗，請至待驗清單該列手動確認（'+autoResult.errors.join('；')+'）';
                    return t;
                }
                function finishSave(){
                    if(asRedo){ alert('已記錄退回重做（qc_form_id='+res.qc_form_id+'）。重做送回後可再驗一次。'); return; }
                    if(hasOpener){
                        try{ window.opener.focus(); }catch(e){}
                        window.close();
                        setTimeout(function(){
                            if(confirm('檢驗結果已儲存並回傳待驗清單。\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+autoMsg()+'\n按確定關閉本視窗。')) window.close();
                        }, 400);
                    } else {
                        alert('已儲存（qc_form_id='+res.qc_form_id+'）\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+'　允收(讓步)：'+s.aod_qty+autoMsg());
                        reloadContext();
                    }
                }
                if(s.check_result==='NG') openNgAsk(res.qc_form_id, s, items, finishSave);
                else finishSave();
            }

            if(asRedo){
                afterAutoSubmit(null); // 退回重做不自動建立允收/異常紀錄，維持人工判斷
            } else {
                var goodQty = Math.max(0, (parseInt($('#inp-qty').val())||0) - (s.ng_qty||0));
                var badQty  = s.ng_qty||0;
                autoSubmitQcResult(s.bom_ing_fid, goodQty, badQty, $('#inp-remark').val(), afterAutoSubmit);
            }
        },'json').fail(function(x){ $btn.prop('disabled',false); alert('儲存錯誤：'+x.responseText); });
    }
    $('#btn-save').on('click', function(){ doSave(false); });
    $('#btn-redo').on('click', function(){ if($('#inp-remark').val().trim()===''){ $('#inp-remark').val('退回重做'); } doSave(true); });
    $('#btn-cancel').on('click', function(){
        if(!confirm('確定取消？尚未儲存的輸入將不會保留。')) return;
        if(window.opener && !window.opener.closed) window.close();
        else if(history.length>1) history.back();
        else location.href='QC_check_list_test.php';
    });

    // =====================================================================
    // NG → 是否開立品質異常單
    // =====================================================================
    var ngCtx=null;
    function ngSummaryText(items){
        var lines=['品管檢驗判定 NG，NG 項目：'], n=0;
        (items||[]).forEach(function(it){
            if(it.verdict!=='NG') return;
            n++;
            var tol=(it.up||it.lo)?('（公差 '+(it.up?'+'+it.up:'')+(it.lo?' / '+it.lo:'')+'）'):'';
            var ngVals=(it.samples||[]).filter(function(sv){ return sv.r==='NG'; }).map(function(sv){ return sv.v; }).filter(function(v){ return v!==''; }).join(', ');
            lines.push(n+'. '+it.name+'：標準 '+(it.std||'-')+tol+(ngVals?('，NG 實測值：'+ngVals):''));
        });
        return lines.join('\n');
    }
    function ngPrefill(sqty, phenomenon){
        return { sqty:sqty, phenomenon:phenomenon, qa_ps:$('#inp-remark').val(),
                 bom_no:(ctx?ctx.bom:''), bom_process_fids:(ctx?String(ctx.bom_ing_fid):'') };
    }
    function openNgAsk(qcFormId, s, items, done){
        ngCtx={ qcFormId:qcFormId, summary:s, items:items, done:done, decided:false };
        $('#ng-ask-info').html('本次檢驗判定為<b class="text-danger">不良</b>（不良 <b>'+s.ng_qty+'</b> 件）。是否開立品質異常單？<br><small class="text-muted">開立後將自動通知回覆部門與相關人員，並要求回覆回簽。</small>');
        $('#ng-skip-area').hide(); $('#ng-skip-reason').val('');
        $('#ngAskModal').modal('show');
    }
    $('#btn-ng-open').on('click', function(){
        if(!ngCtx) return;
        $('#ngAskModal').modal('hide');
        QAAbnormalModal.open({
            source_type:'QC', source_id:ngCtx.qcFormId,
            title_suffix:(ctx?('料號 '+ctx.part_no):''),
            prefill: ngPrefill(ngCtx.summary.ng_qty, ngSummaryText(ngCtx.items)),
            onCreated:function(r){
                ngCtx.decided=true;
                var qid=ngCtx.qcFormId;
                $.post(API,{ action:'set_ncr_decision', qc_form_id:qid, decision:'OPEN', abnormal_order_id:r.id }, function(){
                    alert('異常單 '+r.no+' 已開立並發送通知。');
                    var d=ngCtx.done; ngCtx=null; if(d) d();
                }, 'json');
            }
        });
    });
    // 取消：不現在決定要不要開異常單（檢驗結果已存檔），之後可從歷程「開異常單」補開
    $('#btn-ng-later').on('click', function(){
        if(!ngCtx) { $('#ngAskModal').modal('hide'); return; }
        ngCtx.decided = true;                      // 避免開單跳窗的 hidden 事件把它again 叫回來
        $('#ngAskModal').modal('hide');
        var d=ngCtx.done; ngCtx=null; if(d) d();
    });
    $('#qamModal').on('hidden.bs.modal', function(){
        if(ngCtx && !ngCtx.decided) setTimeout(function(){ $('#ngAskModal').modal('show'); }, 300);
    });
    $('#btn-ng-skip').on('click', function(){ $('#ng-skip-area').slideDown(120); $('#ng-skip-reason').focus(); });
    $('#btn-ng-skip-confirm').on('click', function(){
        if(!ngCtx) return;
        var reason=$('#ng-skip-reason').val().trim();
        if(!reason){ alert('請填寫不開立異常單的原因'); $('#ng-skip-reason').focus(); return; }
        var qid=ngCtx.qcFormId;
        $.post(API,{action:'set_ncr_decision',qc_form_id:qid,decision:'SKIP',reason:reason}, function(r){
            if(!r.success){ alert(r.message||'儲存失敗'); return; }
            ngCtx.decided=true;
            $('#ngAskModal').modal('hide');
            var d=ngCtx.done; ngCtx=null; if(d) d();
        },'json');
    });

    // =====================================================================
    // 列印 / 匯出 CSV（版面沿用舊頁 2-QA-01-06，交瀏覽器原生分頁）
    // =====================================================================
    function currentMeta(){
        var ngPcs=0;
        for(var s=0;s<state.sampleN;s++){ var p=MODEL.pcs[s]; if(p && (p.m? p.v==='NG' : pcsAutoNG(s))) ngPcs++; }
        return { part:(ctx&&ctx.part_no)||'', client:(ctx&&ctx.client)||'', bom:(ctx&&ctx.bom)||'',
                 process:(ctx&&ctx.process)||'', incoming:parseInt($('#inp-qty').val())||0,
                 sample:parseInt($('#inp-sample').val())||0, remark:$('#inp-remark').val()||'',
                 judge:(MODEL.items.length && ngPcs>0)?'不良':'合格', ng:ngPcs };
    }
    // 簽章日期：已存檔的紀錄一律用「檢驗日」，尚未存檔的現場列印才用今天（使用者 2026-08-03 定案）
    function printSignDate(){
        var cd = state.editMeta && state.editMeta.check_date;
        if(cd) return String(cd).substring(0,10);
        var now=new Date(), pad=function(x){ return ('0'+x).slice(-2); };
        return now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
    }
    function buildPrintHtml(){
        var m=currentMeta(), items=collectItems(), n=state.sampleN;
        var dateStr=printSignDate();
        // 大標題＝本公司全名、副標題＝綁定 AS 文件的表單名稱（皆動態取，禁寫死；ai-rules/16）
        var head='<div class="pr-co">'+esc(PRINTCFG.company||'')+'</div>'+
            '<div class="pr-title">'+esc((PRINTCFG.doc&&PRINTCFG.doc.name)||'檢驗記錄表')+'</div>'+
            '<table class="pr-meta"><tr>'+
            '<td class="k">料號</td><td>'+esc(m.part)+'</td><td class="k">客戶</td><td>'+esc(m.client)+'</td><td class="k">日期</td><td>'+dateStr+'</td></tr>'+
            '<tr><td class="k">製令/BOM</td><td>'+esc(m.bom)+'</td><td class="k">製程</td><td>'+esc(m.process)+'</td><td class="k">送驗數</td><td>'+m.incoming+'</td></tr>'+
            '<tr><td class="k">抽驗數</td><td>'+m.sample+'</td><td class="k">整體判定</td><td>'+m.judge+'（不良 '+m.ng+'）</td><td class="k">備註</td><td>'+esc(m.remark)+'</td></tr></table>';
        var pcsHead=''; for(var i=1;i<=n;i++) pcsHead+='<th>'+i+'</th>';
        // 量具格：三行＝量具類別／量具規格／量具編號，字自動縮小換行，不再另印表格下方的量具對照列
        //   舊資料的規格是人工塞在編號括號裡（例 B-008-Q (0-25mm)）→ 拆出來當規格行，編號行只留編號本體
        var toolNoOf=function(id){
            var t=toolInstById(id);
            if(!t) return '';
            var no=String(t.no||'').trim(), spec=t.spec||'';
            var m=no.match(/[（(]([^）)]*)[）)]\s*$/);
            if(m){ if(!spec) spec=m[1].trim(); no=no.slice(0,m.index).trim(); }
            return '<div class="tool2">'+[t.cat||'', spec, no].filter(function(s){ return s!==''; })
                   .map(function(s){ return '<span>'+esc(s)+'</span>'; }).join('')+'</div>';
        };
        var body='';
        items.forEach(function(it,idx){
            var readings=[{tool:toolNoOf(it.tool_id), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:toolNoOf(ex.tool_id), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var cells='';
                for(var i2=0;i2<n;i2++){
                    var sv=(rd.samples||[])[i2];
                    var v=(sv && sv.v!=null && sv.v!=='')?sv.v:'';
                    cells+='<td'+((sv&&sv.r==='NG'&&v!=='')?' class="pr-ng"':'')+'>'+esc(v)+'</td>';
                }
                body+='<tr>'+
                    (ri===0?('<td rowspan="'+readings.length+'">'+codeLabel(idx)+'</td><td rowspan="'+readings.length+'" style="text-align:left">'+esc(it.name)+'</td><td rowspan="'+readings.length+'">'+esc(it.std||'')+'</td><td class="c-tol" rowspan="'+readings.length+'">'+esc(it.up||'')+(it.lo?('<span class="lo">'+esc(it.lo)+'</span>'):'')+'</td>'):'')+
                    '<td class="c-tool">'+(rd.tool||'')+'</td>'+cells+
                    (ri===0?('<td rowspan="'+readings.length+'">'+(it.verdict==='NG'?'NG':'OK')+'</td>'):'')+'</tr>';
                if(ri===0 && it.remark) body+='<tr><td colspan="'+(5+n)+'" style="text-align:left;font-size:11px">備註：'+esc(it.remark)+'</td></tr>';
            });
        });
        var tbl='<table class="pr-items"><thead><tr><th class="c-no">編號</th><th>檢驗項目</th><th class="c-std">標準</th>'+
                '<th class="c-tol">公差</th><th class="c-tool">量具</th>'+pcsHead+'<th>判定</th></tr></thead><tbody>'+body+'</tbody></table>';
        // 簽章：印章本身自帶日期（故不再另設日期欄）；代理人代簽由 EGStamp 於右下角加「代」字
        var insp = (state.editMeta && state.editMeta.creator_name) || <?php echo json_encode($CURRENT_CNAME, JSON_UNESCAPED_UNICODE); ?>;
        var appr = (PRINTCFG.auto_approve && PRINTCFG.approver && PRINTCFG.approver.name)
                 ? EGStamp.stamp(PRINTCFG.approver.name, dateStr, !!PRINTCFG.approver.deputy) : '';
        var sign='<table class="pr-sign"><tr>'+
                 '<td>'+EGStamp.stamp(insp, dateStr, false)+'<div class="lbl">檢驗員 Inspector</div></td>'+
                 '<td>'+appr+'<div class="lbl">主管審核 Approved</div></td></tr></table>';
        // AS 文件編號：每頁右下角，只印編號（ai-rules/16 第三節）
        var dno=(PRINTCFG.doc&&PRINTCFG.doc.no)||'';
        return head+tbl+sign+(dno?'<div class="pt-foot">'+esc(dno)+'</div>':'');
    }
    $('#btn-print').on('click', function(){
        if(!ctx){ alert('請先由待驗清單開啟一筆檢驗再列印。'); return; }
        if(!collectItems().length){ alert('尚無檢驗項目可列印。'); return; }
        $('#print-area').html(buildPrintHtml());
        window.print();
    });
    $('#btn-csv').on('click', function(){
        if(!ctx){ alert('請先開啟一筆檢驗再匯出。'); return; }
        var items=collectItems(); if(!items.length){ alert('尚無檢驗項目可匯出。'); return; }
        var n=state.sampleN, m=currentMeta();
        var head=['編號','檢驗項目','標準','上公差','下公差','量具'];
        for(var i=1;i<=n;i++) head.push('第'+i+'件');
        head.push('判定','備註');
        var q=function(s){ s=(s==null?'':String(s)); return '"'+s.replace(/"/g,'""')+'"'; };
        var lines=[head.map(q).join(',')];
        items.forEach(function(it,idx){
            var readings=[{tool:toolLabelById(it.tool_id), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:toolLabelById(ex.tool_id), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var row=[ri===0?codeLabel(idx):'', ri===0?it.name:'', ri===0?(it.std||''):'', ri===0?(it.up||''):'', ri===0?(it.lo||''):'', rd.tool||''];
                for(var i3=0;i3<n;i3++){ var sv=(rd.samples||[])[i3]; row.push((sv&&sv.v!=null)?sv.v:''); }
                row.push(ri===0?(it.verdict||''):'', ri===0?(it.remark||''):'');
                lines.push(row.map(q).join(','));
            });
        });
        var csv='﻿'+lines.join('\r\n');
        var blob=new Blob([csv],{type:'text/csv;charset=utf-8;'});
        var a=document.createElement('a'), url=URL.createObjectURL(blob);
        a.href=url; a.download='檢驗記錄_'+(m.part||'')+'_'+m.process+'.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    });

    // ============ 權限設定（角色 → QC 功能；用既有 Roles_API） ============
    var ROLES_API = '../../src/store/Roles_API.php';
    var QC_FEATURES = [
        { code:'qc_fill_inspection',   label:'填寫檢驗表單（儲存檢驗結果/退回重做）' },
        { code:'qc_edit_history',      label:'修改 / 開放檢驗歷程（主管）' },
        { code:'qc_manage_settings',   label:'管理檢驗設定（量具 / 幾何公差 / 通用樣板）' },
        { code:'qc_manage_sampling',   label:'抽樣規則設定（主管「修改/開放檢驗歷程」固定可用；此處可另單獨授權）' },
        { code:'qc_view_readonly',     label:'唯讀檢閱（僅可檢視檢驗表與異常單，不可修改/開單）' },
        { code:'qa_disposition_reply', label:'勾選 / 回覆異常單「異常處置方式、處置說明」' },
        { code:'qc_supervisor',        label:'認定為主管（收到並核准異常單修改請求、可直接修改異常單；與管理員不同）' },
        { code:'qc_print_approve_setting', label:'列印：主管審核自動核可設定（可指定核可主管）' }
    ];
    var QC_CODES = QC_FEATURES.map(function(f){ return f.code; });
    var _permRole = null, _permRoleCur = [], _permRolesData = [], _permRoleName = '', _permRoleSys = false;
    $('#btn-perm-setting').on('click', function(e){ e.preventDefault(); _permRole=null; loadPermRoles(); $('#permModal').modal('show'); });
    function loadPermRoles(){
        // 僅載入 QC 模組角色＋系統管理員（各頁面角色分開設定，唯管理員全頁共用）
        $.get(ROLES_API, { action:'get_roles', module:'qc' }, function(res){
            if(!res.success){ $('#perm-role-list').html('<div class="text-danger">載入角色失敗</div>'); return; }
            _permRolesData = res.data || [];
            $('#perm-role-list').html(_permRolesData.map(function(r){
                return '<a href="#" class="list-group-item perm-role" data-id="'+r.role_id+'" data-sys="'+r.is_system+'" data-name="'+esc(r.role_name)+'">'+esc(r.role_name)+(r.is_system==1?' <span class="label label-default">系統</span>':'')+'</a>';
            }).join(''));
            $('#btn-rename-role,#btn-delete-role').prop('disabled', true);
        },'json');
    }
    // 新增角色
    $('#btn-add-role').on('click', function(){
        var name=prompt('新增角色名稱：',''); if(name===null) return; name=name.trim(); if(!name) return;
        $.post(ROLES_API,{action:'save_role',role_name:name,module:'qc'},function(res){
            if(!res.success){ alert('新增失敗：'+(res.message||'')); return; } loadPermRoles();
        },'json');
    });
    // 重新命名
    $('#btn-rename-role').on('click', function(){
        if(!_permRole||_permRoleSys) return;
        var name=prompt('修改角色名稱：',_permRoleName); if(name===null) return; name=name.trim(); if(!name) return;
        $.post(ROLES_API,{action:'save_role',role_id:_permRole,role_name:name},function(res){
            if(!res.success){ alert('修改失敗：'+(res.message||'')); return; } loadPermRoles();
        },'json');
    });
    // 刪除角色（先列人員→可轉移→輸入 Y）
    $('#btn-delete-role').on('click', function(){
        if(!_permRole||_permRoleSys) return;
        $('#del-role-name').text(_permRoleName);
        $('#del-confirm-y').val('');
        $('#del-role-users').html('讀取人員中…');
        $('#del-transfer-wrap').hide();
        $('#roleDeleteModal').modal('show');
        $.get(ROLES_API,{action:'get_users'},function(res){
            var users=(res.data||[]).filter(function(u){ return (u.roles||[]).some(function(r){ return r.role_id==_permRole; }); });
            $('#roleDeleteModal').data('users', users);
            if(!users.length){ $('#del-role-users').html('<div class="alert alert-info" style="margin:0;">目前沒有人員被指派為此角色，可直接刪除。</div>'); }
            else {
                $('#del-role-users').html('<div class="alert alert-warning" style="margin:0;"><b>下列 '+users.length+' 位人員目前是「'+esc(_permRoleName)+'」：</b><br>'+users.map(function(u){ return esc(u.user_cname||u.user_uname||u.id); }).join('、')+'</div>');
                var opts='<option value="">不轉移（僅移除此角色指派）</option>'+_permRolesData.filter(function(r){ return r.role_id!=_permRole; }).map(function(r){ return '<option value="'+r.role_id+'">'+esc(r.role_name)+'</option>'; }).join('');
                $('#del-transfer-role').html(opts); $('#del-transfer-wrap').show();
            }
        },'json');
    });
    $('#btn-confirm-del-role').on('click', function(){
        if($('#del-confirm-y').val()!=='Y'){ alert('請輸入大寫 Y 以確認刪除'); return; }
        var users=$('#roleDeleteModal').data('users')||[];
        var transferTo=$('#del-transfer-role').val();
        var $b=$(this).prop('disabled',true);
        function doDelete(){
            $.post(ROLES_API,{action:'delete_role',role_id:_permRole},function(res){
                $b.prop('disabled',false);
                if(!res.success){ alert('刪除失敗：'+(res.message||'')); return; }
                $('#roleDeleteModal').modal('hide'); _permRole=null; $('#perm-feature-box').html('<p class="text-muted">← 請先選擇角色</p>'); $('#btn-save-perm').prop('disabled',true);
                loadPermRoles(); alert('角色已刪除。');
            },'json').fail(function(x){ $b.prop('disabled',false); alert('刪除錯誤：'+x.responseText); });
        }
        if(transferTo && users.length){
            // 先把人員指派到新角色，再刪除舊角色(delete_role 會移除舊指派)
            var i=0; (function next(){
                if(i>=users.length){ doDelete(); return; }
                $.post(ROLES_API,{action:'assign_user_role',user_id:users[i].id,role_id:transferTo},function(){ i++; next(); },'json')
                 .fail(function(){ i++; next(); });
            })();
        } else { doDelete(); }
    });

    $('#perm-role-list').on('click','.perm-role', function(e){ e.preventDefault();
        $('.perm-role').removeClass('active'); $(this).addClass('active');
        _permRole=$(this).data('id'); var isSys=$(this).data('sys')==1;
        _permRoleName=$(this).data('name'); _permRoleSys=isSys;
        $('#btn-rename-role,#btn-delete-role').prop('disabled', isSys);
        $.get(ROLES_API,{action:'get_role_features',role_id:_permRole},function(res){
            _permRoleCur = res.success ? (res.data||[]) : [];
            var all = _permRoleCur.indexOf('all')!==-1;
            var html = QC_FEATURES.map(function(f){
                var chk=(all||_permRoleCur.indexOf(f.code)!==-1)?'checked':'';
                return '<div class="checkbox"><label><input type="checkbox" class="perm-feat" value="'+f.code+'" '+chk+(isSys||all?' disabled':'')+'> '+f.label+'</label></div>';
            }).join('');
            if(all) html+='<p class="text-info">此角色擁有全部權限(all)，無需逐項勾選。</p>';
            if(isSys&&!all) html+='<p class="text-muted">系統角色不可修改。</p>';
            $('#perm-feature-box').html(html);
            $('#btn-save-perm').prop('disabled', isSys||all);
        },'json');
    });
    $('#btn-save-perm').on('click', function(){
        if(!_permRole) return;
        var checked=$('#perm-feature-box .perm-feat:checked').map(function(){return this.value;}).get();
        // 合併：保留此角色非 QC 的既有功能 + 本次勾選的 QC 功能（避免洗掉其他模組權限）
        var merged=_permRoleCur.filter(function(c){ return QC_CODES.indexOf(c)===-1; }).concat(checked);
        $.post(ROLES_API,{action:'save_role_features',role_id:_permRole,features:JSON.stringify(merged)},function(res){
            if(!res.success){ alert('儲存失敗：'+(res.message||'')); return; }
            alert('角色權限已儲存。'); _permRoleCur=merged;
        },'json').fail(function(x){ alert('儲存錯誤：'+x.responseText); });
    });

    // ============ 設定：主管審核自動核可 ============
    $('#btn-approve-setting').on('click', function(e){
        e.preventDefault();
        loadPrintCfg(function(){
            $('#ac-on').prop('checked', !!(PRINTCFG.auto_approve|0));
            $('#ac-user').html('<option value="">（請選擇）</option>'+(PRINTCFG.people||[]).map(function(p){
                return '<option value="'+p.id+'"'+(p.id==PRINTCFG.approver_id?' selected':'')+'>'+esc(p.name)+'</option>';
            }).join(''));
            $('#ac-hint').toggle(!!PRINTCFG.people_hint).text(PRINTCFG.people_hint||'');
            $('#approveCfgModal').modal('show');
        });
    });
    $('#ac-save').on('click', function(){
        $.post(V2API,{ v2action:'print_cfg_save', csrf:CSRF, auto_approve:$('#ac-on').is(':checked')?1:0,
                       approver_id:$('#ac-user').val()||0 }, function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            $('#approveCfgModal').modal('hide'); loadPrintCfg();
        },'json').fail(function(x){ alert('儲存失敗：'+x.responseText); });
    });

    // ============ 設定：量具設定（種類/編號 CRUD、取代刪除） ============
    var toolMg = { categories:[], tools:[] }, curToolCat = null;
    $('#btn-tool-setting').on('click', function(e){ e.preventDefault(); loadToolMg(); $('#toolManageModal').modal('show'); });
    function loadToolMg(){
        $('#tool-cat-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'get_tool_manage_data'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            toolMg = res;
            // 同步主表量具下拉來源（之後新增的檢驗項目列即用最新清單）
            TOOLS = res.categories.map(function(c){ return c.QC_Tool; });
            renderToolCats();
            if(curToolCat && res.categories.some(function(c){ return c.QC_Tool_List_id==curToolCat; })){
                renderToolInsts(curToolCat);
            } else {
                curToolCat=null; $('#tool-instance-area').hide(); $('#tool-instance-empty').show();
            }
        },'json');
    }
    function renderToolCats(){
        $('#tool-cat-list').html(toolMg.categories.map(function(c){
            return '<a href="#" class="list-group-item tool-cat-item '+(c.QC_Tool_List_id==curToolCat?'active':'')+'" data-id="'+c.QC_Tool_List_id+'" data-name="'+esc(c.QC_Tool)+'">'+esc(c.QC_Tool)+
                '<span class="pull-right">'+
                '<button class="btn btn-xs btn-warning btn-edit-tc" style="margin:0;" title="改名"><i class="fa fa-pencil"></i></button> '+
                '<button class="btn btn-xs btn-info btn-replace-tc" style="margin:0;" title="取代並刪除"><i class="fa fa-exchange"></i></button> '+
                '<button class="btn btn-xs btn-danger btn-del-tc" style="margin:0;" title="刪除"><i class="fa fa-trash"></i></button>'+
                '</span></a>';
        }).join('') || '<div class="list-group-item text-muted">尚無量具種類</div>');
    }
    $('#tool-cat-list').on('click','.tool-cat-item', function(e){
        if($(e.target).closest('button').length) return;
        e.preventDefault();
        curToolCat=$(this).data('id');
        $('#current-cat-name').text($(this).data('name'));
        $('#ti-cat-id').val(curToolCat);
        renderToolCats(); renderToolInsts(curToolCat);
    });
    function renderToolInsts(catId){
        $('#tool-instance-empty').hide(); $('#tool-instance-area').show();
        var list=toolMg.tools.filter(function(t){ return t.QC_Tool_List_id==catId; });
        $('#tool-inst-list').html(list.length ? list.map(function(t){
            var sp=((t.spec_brand||'')+' '+(t.spec_text||'')).replace(/\s+/g,' ').trim();
            if(sp && String(t.Tool_No||'').replace(/\s+/g,'').toLowerCase().indexOf(sp.replace(/\s+/g,'').toLowerCase())>=0) sp='';
            return '<tr><td>'+esc(t.Tool_No)+(sp?' <small class="text-muted">('+esc(sp)+')</small>':'')+'</td><td>'+
                '<button class="btn btn-xs btn-info btn-edit-ti" data-id="'+t.Tool_id+'" data-no="'+esc(t.Tool_No)+'"><i class="fa fa-pencil"></i></button> '+
                '<button class="btn btn-xs btn-danger btn-del-ti" data-id="'+t.Tool_id+'"><i class="fa fa-trash"></i></button></td></tr>';
        }).join('') : '<tr><td colspan="2" class="text-center text-muted">尚無編號</td></tr>');
    }
    $('#tool-cat-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_tool_category',id:$('#tc-id').val(),name:$('#tc-name').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            $('#tc-id').val(''); $('#tc-name').val('');
            $('#btn-save-tc').text('新增'); $('#btn-cancel-tc').hide();
            loadToolMg();
        },'json');
    });
    $('#tool-cat-list').on('click','.btn-edit-tc', function(){
        var $i=$(this).closest('.tool-cat-item');
        $('#tc-id').val($i.data('id')); $('#tc-name').val($i.data('name')).focus();
        $('#btn-save-tc').text('儲存'); $('#btn-cancel-tc').show();
    });
    $('#btn-cancel-tc').on('click', function(){
        $('#tc-id').val(''); $('#tc-name').val('');
        $('#btn-save-tc').text('新增'); $(this).hide();
    });
    $('#tool-cat-list').on('click','.btn-del-tc', function(){
        if(!confirm('確定刪除此量具種類？')) return;
        $.post(API,{action:'delete_tool_category',id:$(this).closest('.tool-cat-item').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            curToolCat=null; loadToolMg();
        },'json');
    });
    $('#tool-cat-list').on('click','.btn-replace-tc', function(e){
        e.stopPropagation();
        var $i=$(this).closest('.tool-cat-item'), oldId=$i.data('id');
        $('#replace-old-id').val(oldId); $('#replace-old-name').text($i.data('name'));
        $('#replace-new-id').html(toolMg.categories.filter(function(c){ return c.QC_Tool_List_id!=oldId; })
            .map(function(c){ return '<option value="'+c.QC_Tool_List_id+'">'+esc(c.QC_Tool)+'</option>'; }).join(''));
        $('#toolReplaceModal').modal('show');
    });
    $('#btn-confirm-replace').on('click', function(){
        $.post(API,{action:'replace_tool_category',old_id:$('#replace-old-id').val(),new_id:$('#replace-new-id').val()},function(res){
            if(!res.success){ alert(res.message||'取代失敗'); return; }
            $('#toolReplaceModal').modal('hide'); curToolCat=null; loadToolMg();
        },'json');
    });
    $('#tool-inst-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_tool_instance',id:$('#ti-id').val(),cat_id:$('#ti-cat-id').val(),no:$('#ti-no').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            $('#ti-id').val(''); $('#ti-no').val('');
            $('#btn-save-ti').text('新增編號'); $('#btn-cancel-ti').hide();
            loadToolMg();
        },'json');
    });
    $('#tool-inst-list').on('click','.btn-edit-ti', function(){
        $('#ti-id').val($(this).data('id')); $('#ti-no').val($(this).data('no')).focus();
        $('#btn-save-ti').text('儲存'); $('#btn-cancel-ti').show();
    });
    $('#btn-cancel-ti').on('click', function(){
        $('#ti-id').val(''); $('#ti-no').val('');
        $('#btn-save-ti').text('新增編號'); $(this).hide();
    });
    $('#tool-inst-list').on('click','.btn-del-ti', function(){
        if(!confirm('確定刪除此量具編號？')) return;
        $.post(API,{action:'delete_tool_instance',id:$(this).data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadToolMg();
        },'json');
    });

    // ============ 設定：幾何公差管理 ============
    $('#btn-special-setting').on('click', function(e){ e.preventDefault(); loadSpecialItems(); $('#specialItemManageModal').modal('show'); });
    function loadSpecialItems(){
        $('#manage-special-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'get_special_items'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var items=res.special_items||[];
            $('#manage-special-list').html(items.length ? items.map(function(it){
                return '<div class="list-group-item clearfix" data-id="'+it.id+'" data-name="'+esc(it.name)+'" data-symbol="'+esc(it.symbol||'')+'" data-code="'+esc(it.code||'')+'">'+
                    '<span class="badge pull-left" style="margin-right:10px;background:#777;">'+esc(it.symbol||'')+'</span>'+
                    '<strong>'+esc(it.name)+'</strong> <small class="text-muted">('+esc(it.code||'')+')</small>'+
                    '<div class="pull-right">'+
                    '<button class="btn btn-xs btn-info btn-edit-si"><i class="fa fa-pencil"></i></button> '+
                    '<button class="btn btn-xs btn-danger btn-del-si"><i class="fa fa-trash"></i></button>'+
                    '</div></div>';
            }).join('') : '<div class="list-group-item text-muted">尚無資料，請於上方新增</div>');
        },'json');
    }
    $('#special-item-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_special_item',id:$('#si-id').val(),name:$('#si-name').val(),symbol:$('#si-symbol').val(),code:$('#si-code').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetSiForm(); loadSpecialItems();
        },'json');
    });
    $('#manage-special-list').on('click','.btn-edit-si', function(){
        var $i=$(this).closest('.list-group-item');
        $('#si-id').val($i.data('id')); $('#si-name').val($i.data('name'));
        $('#si-symbol').val($i.data('symbol')); $('#si-code').val($i.data('code'));
        $('#btn-save-si').text('儲存'); $('#btn-cancel-si').show();
    });
    $('#btn-cancel-si').on('click', resetSiForm);
    function resetSiForm(){
        $('#si-id').val(''); $('#special-item-form')[0].reset();
        $('#btn-save-si').text('新增'); $('#btn-cancel-si').hide();
    }
    $('#manage-special-list').on('click','.btn-del-si', function(){
        if(!confirm('確定刪除？')) return;
        $.post(API,{action:'delete_special_item',id:$(this).closest('.list-group-item').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadSpecialItems();
        },'json');
    });

    // ============ 設定：通用樣板管理（建立/更新用主畫面表格內容） ============
    var editingTplId = null;
    $('#btn-template-setting').on('click', function(e){ e.preventDefault(); loadTplManage(); $('#templateManageModal').modal('show'); });
    function loadTplManage(){
        $('#template-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'manage_templates',sub_action:'list'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var d=res.data||[];
            $('#template-list').html(d.length ? d.map(function(t){
                return '<div class="list-group-item clearfix"><strong>'+esc(t.template_name)+'</strong>'+
                    '<div class="pull-right">'+
                    '<button class="btn btn-xs btn-info btn-edit-tpl" data-id="'+t.template_id+'" data-name="'+esc(t.template_name)+'"><i class="fa fa-pencil"></i> 編輯</button> '+
                    '<button class="btn btn-xs btn-danger btn-del-tpl" data-id="'+t.template_id+'"><i class="fa fa-trash"></i> 刪除</button>'+
                    '</div></div>';
            }).join('') : '<div class="list-group-item text-muted">尚無樣板</div>');
        },'json');
    }
    $('#btn-create-template').on('click', function(){
        var name=$('#new-template-name').val().trim();
        if(!name){ alert('請輸入樣板名稱'); $('#new-template-name').focus(); return; }
        var items=collectItems();
        if(!items.length){ alert('主畫面檢驗項目表是空的，無法建立樣板'); return; }
        var payload={action:'manage_templates',sub_action:'save',name:name,items:JSON.stringify(items)};
        if(editingTplId) payload.template_id=editingTplId;
        var $b=$(this).prop('disabled',true);
        $.post(API,payload,function(res){
            $b.prop('disabled',false);
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetTplEdit(); loadTplManage();
        },'json').fail(function(){ $b.prop('disabled',false); alert('儲存錯誤'); });
    });
    $('#template-list').on('click','.btn-edit-tpl', function(){
        if(!confirm('這將清空目前主畫面的檢驗項目表，並載入此樣板內容供編輯，確定嗎？')) return;
        var tid=$(this).data('id'), tname=$(this).data('name');
        $.post(API,{action:'manage_templates',sub_action:'get_items',template_id:tid},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            renderItems(res.items||[]);
            $('#no-std-hint').hide();
            editingTplId=tid;
            $('#new-template-name').val(tname);
            $('#btn-create-template').text('更新樣板').removeClass('btn-success').addClass('btn-warning');
            $('#btn-cancel-edit-template').show();
            $('#templateManageModal').modal('hide');
            $('html,body').animate({scrollTop:$('#items-table').offset().top-120},300);
        },'json');
    });
    $('#template-list').on('click','.btn-del-tpl', function(){
        if(!confirm('確定刪除此樣板？')) return;
        $.post(API,{action:'manage_templates',sub_action:'delete',template_id:$(this).data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadTplManage();
        },'json');
    });
    $('#btn-cancel-edit-template').on('click', resetTplEdit);
    function resetTplEdit(){
        editingTplId=null;
        $('#new-template-name').val('');
        $('#btn-create-template').text('從當前表格建立樣板').removeClass('btn-warning').addClass('btn-success');
        $('#btn-cancel-edit-template').hide();
    }

    // ============ 設定：抽樣規則 ============
    $('#btn-sampling-setting').on('click', function(e){ e.preventDefault(); loadRules(); $('#samplingRuleModal').modal('show'); });
    function loadRules(){
        $('#rule-list').html('<tr><td colspan="3" class="text-center text-muted">載入中…</td></tr>');
        $.post(API,{action:'manage_sampling_rules',sub_action:'list'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var d=res.data||[];
            $('#rule-list').html(d.length ? d.map(function(r){
                return '<tr data-id="'+r.rule_id+'" data-min="'+r.min_qty+'" data-max="'+r.max_qty+'" data-sample="'+r.sample_qty+'">'+
                    '<td>'+r.min_qty+' ~ '+r.max_qty+'</td><td>'+r.sample_qty+'</td><td>'+
                    '<button class="btn btn-xs btn-info btn-edit-rule"><i class="fa fa-pencil"></i></button> '+
                    '<button class="btn btn-xs btn-danger btn-del-rule"><i class="fa fa-trash"></i></button></td></tr>';
            }).join('') : '<tr><td colspan="3" class="text-center text-muted">尚無規則（無規則時系統用簡易推估：≥500抽8、≥100抽5、其餘抽3）</td></tr>');
        },'json');
    }
    $('#rule-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'manage_sampling_rules',sub_action:'save',id:$('#rule-id').val(),
            min:$('#rule-min').val(),max:$('#rule-max').val(),sample:$('#rule-sample').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetRuleForm(); loadRules();
        },'json');
    });
    $('#rule-list').on('click','.btn-edit-rule', function(){
        var $tr=$(this).closest('tr');
        $('#rule-id').val($tr.data('id'));
        $('#rule-min').val($tr.data('min')); $('#rule-max').val($tr.data('max')); $('#rule-sample').val($tr.data('sample'));
        $('#btn-save-rule').text('儲存'); $('#btn-cancel-rule').show();
    });
    $('#btn-cancel-rule').on('click', resetRuleForm);
    function resetRuleForm(){
        $('#rule-id').val(''); $('#rule-form')[0].reset();
        $('#btn-save-rule').text('新增'); $('#btn-cancel-rule').hide();
    }
    $('#rule-list').on('click','.btn-del-rule', function(){
        if(!confirm('確定刪除此規則？')) return;
        $.post(API,{action:'manage_sampling_rules',sub_action:'delete',id:$(this).closest('tr').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadRules();
        },'json');
    });

    // ============ #8 同料號歷次檢驗查詢 ============
    $('#btn-history').on('click', function(){
        if(!ctx || !ctx.d_id){ alert('請先由待驗清單開啟一筆檢驗（需有料號）再查詢歷史。'); return; }
        openPartHistory();
    });
    function ensureHistoryModal(){
        if($('#partHistModal').length) return;
        $('body').append(
          '<div class="modal fade" id="partHistModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">'+
          '<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>'+
          '<h4 class="modal-title"><i class="fa fa-history"></i> 同料號歷次檢驗</h4></div>'+
          '<div class="modal-body"><div id="partHistList">載入中…</div><div id="partHistDetail" style="margin-top:12px;"></div></div>'+
          '<div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>'+
          '</div></div></div>');
    }
    function openPartHistory(){
        ensureHistoryModal();
        $('#partHistDetail').empty(); $('#partHistList').html('載入中…');
        $('#partHistModal').modal('show');
        $.post(API,{action:'history_by_part', d_id:ctx.d_id}, function(res){
            if(!res.success){ $('#partHistList').html('查詢失敗：'+esc(res.message||'')); return; }
            var rows=res.rows||[];
            if(!rows.length){ $('#partHistList').html('<div class="text-muted">此料號尚無歷史檢驗紀錄。</div>'); return; }
            var h='<div class="text-muted" style="margin-bottom:6px;">料號 <b>'+esc(ctx.part_no||'')+'</b>　共 '+rows.length+' 筆（點「檢視」看逐項實測與同尺寸落點）</div>'+
              '<div style="max-height:230px;overflow:auto;"><table class="table table-condensed table-bordered"><thead><tr>'+
              '<th>日期</th><th>製令</th><th>製程</th><th>批/複</th><th>判定</th><th>不良</th><th>檢驗人</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r){
                var d=String(r.check_date||r.created_at||'').substring(0,16);
                h+='<tr><td>'+esc(d)+'</td><td>'+esc(r.bom||'')+'</td><td>'+esc(r.process_name||'')+'</td>'+
                   '<td>'+(r.batch_no||1)+'/'+(r.round_no||1)+'</td><td>'+statusLabel(r.check_result)+'</td>'+
                   '<td>'+(r.ng_qty||0)+'</td><td>'+esc(r.user_cname||r.created_by||'')+'</td>'+
                   '<td><button class="btn btn-xs btn-primary ph-view" data-id="'+r.qc_form_id+'">檢視</button></td></tr>';
            });
            h+='</tbody></table></div>';
            $('#partHistList').html(h);
        }, 'json');
    }
    $(document).on('click','.ph-view', function(){
        var id=$(this).data('id');
        $('#partHistDetail').html('載入中…');
        $.post(API,{action:'get_history_record',qc_form_id:id}, function(res){
            if(!res.success){ $('#partHistDetail').html('載入失敗：'+esc(res.message||'')); return; }
            $('#partHistDetail').html(renderHistDetail(res));
        }, 'json');
    });
    function renderHistDetail(res){
        var h=res.header, its=res.items||[];
        var out='<div class="well well-sm" style="margin-bottom:8px;"><b>逐項實測</b>（單號 '+h.qc_form_id+'；送驗 '+(h.incoming_qty||0)+'／抽驗 '+(h.sample_qty||0)+'；整體 '+(h.check_result==='NG'?'<span class="text-danger">不良</span>':'合格')+'）</div>';
        out+='<div style="max-height:300px;overflow:auto;"><table class="table table-condensed table-bordered"><thead><tr><th>項次</th><th>項目</th><th>標準</th><th>量具</th><th>實測（各PCS）</th><th>判定</th></tr></thead><tbody>';
        its.forEach(function(it,idx){
            var readings=[{tool:(it.tool||''), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:(ex.method||ex.tool_no||''), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var vals=(rd.samples||[]).map(function(s){ return (s&&s.v!==''&&s.v!=null)?('<span class="'+((s.r==='NG')?'text-danger':'')+'">'+esc(s.v)+'</span>'):'·'; }).join('　');
                out+='<tr>'+(ri===0?('<td rowspan="'+readings.length+'">'+esc(codeLabel(idx))+'</td><td rowspan="'+readings.length+'">'+esc(it.name)+'</td><td rowspan="'+readings.length+'">'+esc(it.std||'')+((it.up||it.lo)?(' ('+esc(it.up||'')+'/'+esc(it.lo||'')+')'):'')+'</td>'):'')+
                    '<td>'+esc(rd.tool||'')+'</td><td>'+vals+'</td>'+(ri===0?('<td rowspan="'+readings.length+'">'+(it.verdict==='NG'?'<span class="text-danger">NG</span>':(it.verdict==='AOD'?'特採':'OK'))+'</td>'):'')+'</tr>';
            });
            if(it.remark) out+='<tr><td colspan="6" class="text-muted" style="font-size:12px">備註：'+esc(it.remark)+'</td></tr>';
        });
        out+='</tbody></table></div>';
        return out;
    }
});
</script>
</body>
</html>
