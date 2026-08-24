<?php
/**
 * store_CAR_API.php — 異常矯正處理單 (CAR) 後端 AJAX 端點
 *
 * 前端：views/QA/correction_order.php
 * 共用：src/common/car_lib.php
 *
 * Phase 1 端點：
 *   search_counterparty / search_part / search_process / search_workorder
 *   search_qa_source / search_ir_source / list_depts / dept_users / own_customers
 *   create / load_page_data / get_detail
 * （Phase 2/3 的 assign / reply_submit / sign / decide / reissue 之後再補）
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

session_set_cookie_params(43200);
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）

require_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/car_lib.php';
require_once __DIR__ . '/../common/car_notify.php';

header('Content-Type: application/json; charset=utf-8');

function jout($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
// 業務驗證失敗：回 HTTP 200 + success:false（前端直接顯示訊息，不觸發 console 錯誤）
function jfail($msg) { echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE); exit; }
// 系統/權限錯誤：回對應 HTTP 狀態碼
function jerr($msg, $code = 400) { http_response_code($code); echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE); exit; }

if (!isset($_SESSION['userName'])) { jerr('未登入', 401); }

try {
    $conn = new DBConnection();
    $pdo  = $conn->getPDO();
} catch (Throwable $e) {
    jerr('資料庫連線失敗', 500);
}

$me       = car_current_user($pdo);
$features = rbac_user_features($pdo, (int)$me['id']);
function _carHas($f) { global $features; return rbac_has($features, $f); }

// 附件排序欄位（既有環境自動補欄；已存在時靜默略過）
try { $pdo->exec("ALTER TABLE car_attachment ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER description"); } catch (Throwable $_e) {}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

    // ── 客戶/供應商 混合模糊搜尋（列表標示 [客]/[廠]）────────────────────────
    case 'search_counterparty': {
        $kw   = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
        $only = $_POST['only'] ?? $_GET['only'] ?? '';   // ''|'customer'|'maker'|'own_customer'
        if ($kw === '') jout(['success' => true, 'data' => []]);
        $like = "%$kw%";
        $rows = [];

        if ($only === '' || $only === 'customer' || $only === 'own_customer') {
            $sql = "SELECT customer_id AS id, customer AS name, customer_full AS full_name, is_own_company
                    FROM customer_list
                    WHERE (customer_id LIKE :kw OR customer LIKE :kw OR customer_full LIKE :kw)
                      AND (is_inactive IS NULL OR is_inactive = 0)";
            if ($only === 'own_customer') $sql .= " AND is_own_company = 1";
            $sql .= " ORDER BY customer LIMIT 20";
            $st = $pdo->prepare($sql); $st->execute([':kw' => $like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['type' => 'customer', 'id' => $r['id'], 'name' => $r['name'],
                           'full' => $r['full_name'], 'tag' => '客', 'is_own' => (int)$r['is_own_company']];
            }
        }
        if ($only === '' || $only === 'maker') {
            $st = $pdo->prepare(
                "SELECT maker_id_no AS id, COALESCE(maker_id,'') AS name, COALESCE(maker_id_all,'') AS full_name
                 FROM maker_list
                 WHERE (maker_id_no LIKE :kw OR maker_id LIKE :kw OR maker_id_all LIKE :kw)
                   AND (status IS NULL OR status <> 'X')
                 ORDER BY maker_id_no LIMIT 20");
            $st->execute([':kw' => $like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['type' => 'maker', 'id' => $r['id'], 'name' => $r['name'],
                           'full' => $r['full_name'], 'tag' => '廠', 'is_own' => 0];
            }
        }
        jout(['success' => true, 'data' => $rows]);
    }

    // ── 料號模糊搜尋（綁定後帶出客戶）────────────────────────────────────────
    case 'search_part': {
        $kw = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
        if ($kw === '') jout(['success' => true, 'data' => []]);
        $st = $pdo->prepare(
            "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, d.Revision,
                    d.Customer_Id, c.customer AS client_name
             FROM d_setting d
             LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
             WHERE d.D_Setting_Id LIKE :kw OR d.Drawing_No LIKE :kw OR d.Spec_No LIKE :kw
             ORDER BY d.D_Setting_Id LIMIT 20");
        $st->execute([':kw' => "%$kw%"]);
        jout(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── 製程模糊搜尋（代號 / 中文 / 分類）───────────────────────────────────
    case 'search_process': {
        $kw = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
        if ($kw === '') jout(['success' => true, 'data' => []]);
        $st = $pdo->prepare(
            "SELECT pn.ProcessNo AS process_no, pn.ProcessName AS process_name,
                    COALESCE(pt.process_type,'') AS type_name
             FROM process_no pn
             LEFT JOIN process_type pt ON pn.process_type_id = pt.process_type_id
             WHERE pn.ProcessName LIKE :kw OR CAST(pn.ProcessNo AS CHAR) LIKE :kw OR pt.process_type LIKE :kw
             ORDER BY pn.ProcessNo LIMIT 20");
        $st->execute([':kw' => "%$kw%"]);
        jout(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── 廠內製令單號模糊搜尋（綁定 BOM，同 QC 異常單）─────────────────────────
    case 'search_workorder': {
        $kw   = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
        $part = trim($_POST['part'] ?? '');   // 已綁定料號(D_Setting_Id)時只列此料號的 BOM
        $sql = "SELECT bi.bom_ing_fid, bi.bom, b.d_id AS part_no, b.Client_Name AS client,
                       pn.ProcessName AS process, bi.sqty,
                       (SELECT ds.d_id FROM d_setting ds WHERE ds.D_Setting_Id = b.d_id LIMIT 1) AS ds_d_id,
                       (SELECT ds.Customer_Id FROM d_setting ds WHERE ds.D_Setting_Id = b.d_id LIMIT 1) AS ds_customer_id,
                       (SELECT c.customer FROM d_setting ds LEFT JOIN customer_list c ON c.customer_id = ds.Customer_Id
                          WHERE ds.D_Setting_Id = b.d_id LIMIT 1) AS ds_customer_name
                FROM bom_ing bi
                LEFT JOIN bom b ON bi.bom = b.bom
                LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                WHERE 1=1";
        $args = [];
        if ($part !== '') { $sql .= " AND b.d_id = :part"; $args[':part'] = $part; }
        if ($kw !== '')   { $sql .= " AND (bi.bom LIKE :kw OR b.d_id LIKE :kw OR b.Client_Name LIKE :kw)"; $args[':kw'] = "%$kw%"; }
        if ($part === '' && $kw === '') jout(['success' => true, 'data' => []]);
        $sql .= " ORDER BY bi.outsource_date DESC LIMIT 30";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        jout(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── 來源：品質異常處理單 搜尋 ────────────────────────────────────────────
    case 'search_qa_source': {
        $kw = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
        $st = $pdo->prepare(
            "SELECT id, abnormal_order_no, occurrence_date, sqty, LEFT(abnormal_phenomenon,40) AS phenomenon
             FROM qa_abnormal_order
             WHERE abnormal_order_no LIKE :kw
             ORDER BY id DESC LIMIT 20");
        $st->execute([':kw' => "%$kw%"]);
        jout(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── 來源：客戶退貨單 (IR) 搜尋 ───────────────────────────────────────────
    case 'search_ir_source': {
        $kw = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
        $st = $pdo->prepare(
            "SELECT IR_id, IR_no, Client_name, d_id, Qty, IR_date
             FROM ir_track
             WHERE IR_no LIKE :kw OR C_IR LIKE :kw OR Client_name LIKE :kw
             ORDER BY IR_id DESC LIMIT 20");
        $st->execute([':kw' => "%$kw%"]);
        jout(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── 已被開立過的責任單位（列表篩選下拉用；另含是否有廠商責任單）────────
    case 'used_resp_depts': {
        // 注意：DISTINCT 的 ORDER BY 欄位必須在選取清單內（MySQL 9 嚴格檢查）
        $depts = $pdo->query(
            "SELECT DISTINCT d.id, d.name, d.sort_order
             FROM car_order o JOIN department d ON d.id = o.resp_dept_id
             WHERE o.resp_dept_id IS NOT NULL ORDER BY d.sort_order, d.id")->fetchAll(PDO::FETCH_ASSOC);
        $hasMaker = (int)$pdo->query("SELECT COUNT(*) FROM car_order WHERE resp_type = 'maker'")->fetchColumn() > 0;
        jout(['success' => true, 'data' => $depts, 'has_maker' => $hasMaker]);
    }

    // ── 部門清單（責任單位多選用）──────────────────────────────────────────
    case 'list_depts': {
        $rows = $pdo->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        jout(['success' => true, 'data' => $rows]);
    }

    // ── 某部門人員（責任人員多選用）────────────────────────────────────────
    case 'dept_users': {
        $did = (int)($_POST['dept_id'] ?? $_GET['dept_id'] ?? 0);
        if (!$did) jout(['success' => true, 'data' => []]);
        $st = $pdo->prepare(
            "SELECT u.id, u.user_cname, p.name AS position_name
             FROM user_department_position_map m
             JOIN user u ON u.id = m.user_id
             LEFT JOIN position p ON p.id = m.position_id
             WHERE m.department_id = ? AND u.state IN (1,99)
             ORDER BY u.user_cname");
        $st->execute([$did]);
        jout(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── 本公司客戶（責任單位=本公司用）─────────────────────────────────────
    case 'own_customers': {
        $rows = $pdo->query(
            "SELECT customer_id AS id, customer AS name FROM customer_list
             WHERE is_own_company = 1 AND (is_inactive IS NULL OR is_inactive = 0)")->fetchAll(PDO::FETCH_ASSOC);
        jout(['success' => true, 'data' => $rows]);
    }

    // ── 建立異常矯正處理單（責任單位多選 → 原子拆 N 單）─────────────────────
    case 'create': {
        if (!_carHas('car_create')) jerr('您沒有開立異常矯正處理單的權限', 403);

        $source_type = $_POST['source_type'] ?? '';
        if (!in_array($source_type, ['QA', 'IR', 'OTHER'], true)) jfail('請選擇異常來源');
        $source_ref_id = (int)($_POST['source_ref_id'] ?? 0) ?: null;
        $source_no     = trim($_POST['source_no'] ?? '');
        $source_desc   = trim($_POST['source_desc'] ?? '');
        // 「其他」來源之對應單號/說明改為非必填
        if ($source_type === 'QA' && !$source_ref_id) jfail('請選擇對應的品質異常處理單');
        if ($source_type === 'IR' && !$source_ref_id) jfail('請選擇對應的客戶退貨單');

        $abnormal_desc = trim($_POST['abnormal_desc'] ?? '');
        if ($abnormal_desc === '') jfail('異常說明為必填');

        $counterparty_type = $_POST['counterparty_type'] ?? '';
        $customer_id = trim($_POST['customer_id'] ?? '');
        $maker_id_no = trim($_POST['maker_id_no'] ?? '');
        if ($counterparty_type !== 'customer' && $counterparty_type !== 'maker') $counterparty_type = null;

        $d_id        = (int)($_POST['d_id'] ?? 0) ?: null;
        $drawing_no  = trim($_POST['drawing_no'] ?? '');
        $bom_no      = trim($_POST['bom_no'] ?? '');
        $work_order  = trim($_POST['work_order'] ?? '');
        $bom_ing_fid = (int)($_POST['bom_ing_fid'] ?? 0) ?: null;
        $qty         = ($_POST['qty'] ?? '') === '' ? null : (float)$_POST['qty'];
        $temp_key    = trim($_POST['temp_key'] ?? '');
        $found_date  = trim($_POST['found_date'] ?? '') ?: null;

        // 開單身分（兼任時前端指定以哪個職務開立）
        $opener_dept_id     = (int)($_POST['opener_dept_id'] ?? 0) ?: null;
        $opener_position_id = (int)($_POST['opener_position_id'] ?? 0) ?: null;
        $opener_position_name = null;
        if ($opener_position_id) {
            $pst = $pdo->prepare("SELECT name FROM position WHERE id = ?");
            $pst->execute([$opener_position_id]);
            $opener_position_name = $pst->fetchColumn() ?: null;
        }
        // 依「所選職務」層級判定是否為主管職 → 決定直接開立或送申請
        $myPositions = car_user_positions($pdo, (int)$me['id']);
        $isSupervisorOpen = false;
        if ($opener_position_id) {
            $isSupervisorOpen = car_position_is_supervisor($pdo, $opener_position_id);
        } elseif (!$myPositions) {
            $isSupervisorOpen = true;   // 無任何職務身分(如純管理員) → 允許直接開立
        }
        if (rbac_has($features, 'all')) $isSupervisorOpen = $isSupervisorOpen; // 管理員仍依所選職務；如需一律直開可改此處
        // 非主管職開立且指定了部門 → 走申請；否則直接開立
        $isApplication = (!$isSupervisorOpen && $opener_dept_id);

        // 責任單位（可多選）→ 每個一張獨立單；空 = 一張無責任單位單
        $responsible = json_decode($_POST['responsible'] ?? '[]', true);
        if (!is_array($responsible)) $responsible = [];
        $targets = $responsible ?: [ ['type' => null] ];
        $n = count($targets);

        $pdo->beginTransaction();
        try {
            if ($isApplication) {
                $nos = array_fill(0, $n, null);         // 申請階段尚未配號
                $base = null;
                $group_no = 'APP' . uniqid();           // 臨時群組(核准時換成正式首號)
                $status = 'applying';
            } else {
                $nos  = car_alloc_numbers($pdo, $n);    // 直接開立：原子連號
                $base = $nos[0];
                $group_no = $base;
                $status = 'open';
            }

            $ins = $pdo->prepare(
                "INSERT INTO car_order
                   (car_no, group_no, source_type, source_ref_id, source_no, source_desc,
                    counterparty_type, customer_id, maker_id_no,
                    d_id, drawing_no, bom_no, work_order, bom_ing_fid, qty,
                    fill_date, found_date, created_by, created_by_name,
                    opener_dept_id, opener_position_id, opener_position_name, open_applied_at,
                    resp_type, resp_dept_id, resp_maker_id, resp_own_customer_id, resp_person_id, resp_display,
                    process_no, process_name, abnormal_desc, status, stage_since)
                 VALUES
                   (:car_no, :group_no, :source_type, :source_ref_id, :source_no, :source_desc,
                    :counterparty_type, :customer_id, :maker_id_no,
                    :d_id, :drawing_no, :bom_no, :work_order, :bom_ing_fid, :qty,
                    CURDATE(), :found_date, :created_by, :created_by_name,
                    :opener_dept_id, :opener_position_id, :opener_position_name, :open_applied_at,
                    :resp_type, :resp_dept_id, :resp_maker_id, :resp_own_customer_id, :resp_person_id, :resp_display,
                    :process_no, :process_name, :abnormal_desc, :status, NOW())");

            $created = [];
            foreach ($targets as $i => $t) {
                $rtype = $t['type'] ?? null;
                if (!in_array($rtype, ['dept', 'maker', 'own_customer', null], true)) $rtype = null;
                $r_dept   = ($rtype === 'dept' || $rtype === 'own_customer') ? ((int)($t['dept_id'] ?? 0) ?: null) : null;
                $r_maker  = ($rtype === 'maker') ? (trim($t['maker_id'] ?? '') ?: null) : null;
                $r_own    = ($rtype === 'own_customer') ? (trim($t['own_customer_id'] ?? '') ?: null) : null;
                $r_person = ($rtype === 'dept' || $rtype === 'own_customer') ? ((int)($t['person_id'] ?? 0) ?: null) : null;
                $r_disp   = trim($t['label'] ?? '');
                $p_no     = (int)($t['process_no'] ?? ($_POST['process_no'] ?? 0)) ?: null;
                $p_name   = trim($t['process_name'] ?? ($_POST['process_name'] ?? ''));

                $ins->execute([
                    ':car_no' => $nos[$i], ':group_no' => $group_no, ':status' => $status,
                    ':source_type' => $source_type, ':source_ref_id' => $source_ref_id,
                    ':source_no' => ($source_no ?: null), ':source_desc' => ($source_desc ?: null),
                    ':counterparty_type' => $counterparty_type,
                    ':customer_id' => ($customer_id ?: null), ':maker_id_no' => ($maker_id_no ?: null),
                    ':d_id' => $d_id, ':drawing_no' => ($drawing_no ?: null), ':bom_no' => ($bom_no ?: null),
                    ':work_order' => ($work_order ?: null), ':bom_ing_fid' => $bom_ing_fid, ':qty' => $qty,
                    ':found_date' => $found_date,
                    ':created_by' => $me['id'], ':created_by_name' => $me['name'],
                    ':opener_dept_id' => $opener_dept_id, ':opener_position_id' => $opener_position_id,
                    ':opener_position_name' => $opener_position_name,
                    ':open_applied_at' => ($isApplication ? date('Y-m-d H:i:s') : null),
                    ':resp_type' => $rtype, ':resp_dept_id' => $r_dept, ':resp_maker_id' => $r_maker,
                    ':resp_own_customer_id' => $r_own, ':resp_person_id' => $r_person, ':resp_display' => ($r_disp ?: null),
                    ':process_no' => $p_no, ':process_name' => ($p_name ?: null),
                    ':abnormal_desc' => $abnormal_desc,
                ]);
                $carId = (int)$pdo->lastInsertId();

                // 異常說明由填表人自動簽章（壓今日日期）
                $pdo->prepare(
                    "INSERT INTO car_signature (car_id, section, signed_by, signed_name, signed_at, signed_date_label)
                     VALUES (?, 'desc', ?, ?, NOW(), ?)")
                    ->execute([$carId, $me['id'], $me['name'], car_sign_date_label()]);

                car_log($pdo, $carId, ($isApplication ? 'apply' : 'create'), (int)$me['id'], $me['name'],
                        $isApplication ? ($n > 1 ? "提出開立申請（同事件 $n 單之一）" : '提出開立申請')
                                       : ($n > 1 ? "開立（同事件拆 $n 單之一）" : '開立單據'));

                // 責任單位已指定人員 → 該人員即回覆人，免主管指派（直接開立時）
                if (!$isApplication && $r_person) {
                    $pnSt = $pdo->prepare("SELECT user_cname FROM user WHERE id = ?");
                    $pnSt->execute([$r_person]); $pn = (string)($pnSt->fetchColumn() ?: '');
                    $pdo->prepare("UPDATE car_order SET status='assigned', assigned_to=?, assigned_to_name=?,
                                     assigned_by=?, assigned_by_name=?, assigned_at=NOW(), stage_since=NOW() WHERE id=?")
                        ->execute([$r_person, $pn, $me['id'], $me['name'], $carId]);
                    car_log($pdo, $carId, 'assign', (int)$me['id'], $me['name'], "責任單位已指定人員「{$pn}」，直接為回覆人（免指派）");
                }

                // desc 區附件（暫存 → 綁定）；多單則各自複製一份連結
                if ($temp_key !== '') car_link_desc_attachments($pdo, $temp_key, $carId, $me['id']);

                $created[] = ['id' => $carId, 'car_no' => $nos[$i]];
            }

            // 回寫來源品質異常單的 CAPA 單號（直接開立才有號；申請於核准時回寫）
            if (!$isApplication && $source_type === 'QA' && $source_ref_id) {
                $pdo->prepare("UPDATE qa_abnormal_order SET capa_order_no = ? WHERE id = ? AND (capa_order_no IS NULL OR capa_order_no = '')")
                    ->execute([$base, $source_ref_id]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jerr('建立失敗：' . $e->getMessage(), 500);
        }

        // ── 通知（commit 後發送，推播失敗不影響建立）──
        try {
            if ($isApplication) {
                // 申請：通知開單者所屬部門主管核准
                $sup = array_map(function ($s) { return (int)$s['id']; }, car_dept_supervisors($pdo, (int)$opener_dept_id));
                $first = $created[0] ?? null;
                if ($first) {
                    $ro = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $ro->execute([$first['id']]);
                    $row = $ro->fetch(PDO::FETCH_ASSOC) ?: [];
                    car_notify($pdo, (int)$first['id'],
                        car_notify_title('📝', $row, '開立申請待您核准'),
                        car_notify_body($pdo, $row, "申 請 人：{$me['name']}（{$opener_position_name}）" . ($n > 1 ? "\n本次申請共 {$n} 張" : '')),
                        $sup, (int)$me['id']);
                }
            } else {
                foreach ($created as $c) {
                    $ro = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $ro->execute([$c['id']]);
                    $row = $ro->fetch(PDO::FETCH_ASSOC);
                    if (!$row) continue;
                    if (!empty($row['assigned_to'])) {
                        car_notify($pdo, (int)$c['id'],
                            car_notify_title('🔧', $row, '指派您回覆'),
                            car_notify_body($pdo, $row, '您已被指定為回覆人，請填寫異常原因分析與處理情形。'),
                            [(int)$row['assigned_to']], (int)$me['id'], 'reply');   // 行動型：填寫送出前通知持續顯示
                        // 一併知會責任單位主管（依被開立部門查主管，兼任主管以該部門身分納入；回覆人已另收行動通知故排除）
                        car_notify($pdo, (int)$c['id'],
                            car_notify_title('📣', $row, '貴單位被開立矯正單'),
                            car_notify_body($pdo, $row, "已成立，貴單位人員「{$row['assigned_to_name']}」已指定為回覆人。"),
                            array_diff(car_primary_recipients($pdo, $row), [(int)$row['assigned_to']]), (int)$me['id']);
                    } else {
                        car_notify($pdo, (int)$c['id'],
                            car_notify_title('🔧', $row, '待指派回覆人'),
                            car_notify_body($pdo, $row, '請主管指派一名回覆人。'),
                            car_primary_recipients($pdo, $row), (int)$me['id']);
                    }
                }
            }
        } catch (Throwable $e) {}

        if ($isApplication) {
            jout(['success' => true, 'mode' => 'application', 'created' => $created, 'group_no' => $group_no,
                  'message' => "已送出開立申請（{$n} 單），待所屬部門主管核准後成立並產生單號。"]);
        }
        jout(['success' => true, 'mode' => 'open', 'created' => $created, 'group_no' => $group_no,
              'message' => ($n > 1 ? "已建立 {$n} 張單（首號 {$base}）" : "已建立單號 {$base}")]);
    }

    // ── 列表 + 統計 + 分頁 ───────────────────────────────────────────────────
    case 'load_page_data': {
        if (!_carHas('car_view')) jerr('您沒有檢閱權限', 403);
        $card     = $_POST['card']     ?? 'all';   // all|unclosed|rejected|closed
        $respDept = (int)($_POST['resp_dept'] ?? 0);
        $srcType  = $_POST['source_type'] ?? '';
        $kw       = trim($_POST['kw'] ?? '');
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $size     = (int)($_POST['size'] ?? 10);
        if (!in_array($size, [5, 10, 20, 50], true)) $size = 10;
        // all=1：總表列印/匯出用，一次撈全部（上限 2000）
        if (!empty($_POST['all'])) { $size = 2000; $page = 1; }
        $offset   = ($page - 1) * $size;

        // 非狀態卡片的共同條件（統計與列表共用）
        // resp 篩選：'' 全部｜'maker' 廠商責任｜數字 = 部門 id
        $respFilter = trim($_POST['resp'] ?? '');
        $base = " FROM car_order o WHERE 1=1";
        $args = [];
        if ($respFilter === 'maker') { $base .= " AND o.resp_type = 'maker'"; }
        elseif ($respFilter !== '' && ctype_digit($respFilter)) { $base .= " AND o.resp_dept_id = :rd"; $args[':rd'] = (int)$respFilter; }
        elseif ($respDept) { $base .= " AND o.resp_dept_id = :rd"; $args[':rd'] = $respDept; }
        if (in_array($srcType, ['QA', 'IR', 'OTHER'], true)) { $base .= " AND o.source_type = :stp"; $args[':stp'] = $srcType; }
        if ($kw !== '') {
            $base .= " AND (o.car_no LIKE :kw OR o.abnormal_desc LIKE :kw OR o.resp_display LIKE :kw OR o.source_no LIKE :kw)";
            $args[':kw'] = "%$kw%";
        }

        // 統計（依卡片語意）
        $stSql = "SELECT
            COUNT(*) AS all_cnt,
            SUM(CASE WHEN o.status IN ('applying','app_rejected') THEN 1 ELSE 0 END) AS pending_cnt,
            SUM(CASE WHEN o.status IN ('open','assigned','replying','pending_primary','pending_final') THEN 1 ELSE 0 END) AS unclosed_cnt,
            SUM(CASE WHEN o.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_cnt,
            SUM(CASE WHEN o.status = 'closed' THEN 1 ELSE 0 END) AS closed_cnt" . $base;
        $st = $pdo->prepare($stSql); $st->execute($args);
        $sc = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats = ['all' => (int)($sc['all_cnt'] ?? 0), 'pending' => (int)($sc['pending_cnt'] ?? 0),
                  'unclosed' => (int)($sc['unclosed_cnt'] ?? 0),
                  'rejected' => (int)($sc['rejected_cnt'] ?? 0), 'closed' => (int)($sc['closed_cnt'] ?? 0)];

        // 卡片條件
        $where = $base;
        if ($card === 'pending_open') $where .= " AND o.status IN ('applying','app_rejected')";
        elseif ($card === 'unclosed') $where .= " AND o.status IN ('open','assigned','replying','pending_primary','pending_final')";
        elseif ($card === 'rejected') $where .= " AND o.status = 'rejected'";
        elseif ($card === 'closed') $where .= " AND o.status = 'closed'";

        $cntSt = $pdo->prepare("SELECT COUNT(*)" . $where); $cntSt->execute($args);
        $total = (int)$cntSt->fetchColumn();

        // 先以 where 撈本頁 id（分頁），再回主查詢帶出顯示欄位（避免 JOIN 影響 LIMIT）
        $idSt = $pdo->prepare("SELECT o.id" . $where . " ORDER BY o.id DESC LIMIT :lim OFFSET :off");
        foreach ($args as $k => $v) $idSt->bindValue($k, $v);
        $idSt->bindValue(':lim', $size, PDO::PARAM_INT);
        $idSt->bindValue(':off', $offset, PDO::PARAM_INT);
        $idSt->execute();
        $ids = $idSt->fetchAll(PDO::FETCH_COLUMN);

        $rows = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $dSt = $pdo->prepare(
                "SELECT o.id, o.car_no, o.group_no, o.source_type, o.source_no,
                        o.counterparty_type, o.customer_id, o.maker_id_no,
                        o.d_id, o.drawing_no, o.resp_type, o.resp_dept_id, o.resp_display,
                        o.status, o.fill_date, o.created_by, o.created_by_name, o.reissue_of, o.reissue_seq,
                        o.assigned_to, o.assigned_to_name, o.assigned_at, o.created_at, o.stage_since,
                        d.name AS resp_dept_name,
                        cc.customer AS customer_name, mk.maker_id AS maker_name
                 FROM car_order o
                 LEFT JOIN department d  ON d.id = o.resp_dept_id
                 LEFT JOIN customer_list cc ON cc.customer_id = o.customer_id
                 LEFT JOIN maker_list mk ON mk.maker_id_no = o.maker_id_no
                 WHERE o.id IN ($in) ORDER BY o.id DESC");
            $dSt->execute($ids);
            $rows = $dSt->fetchAll(PDO::FETCH_ASSOC);

            // 每單最新一筆活動軌跡。
            // 機密遮罩：非授權者(扣款判定人員/最終決策者本人/系統管理員以外)看不到扣款軌跡——
            // 直接跳過 action='deduct' 列(顯示前一筆動態)，並去除機密字句。
            $canSeeDeduct = car_is_admin_deduct($pdo, (int)$me['id']) || car_is_final_decider($pdo, (int)$me['id']) || _carHas('all');
            $deductFilter = $canSeeDeduct ? '' : " AND action <> 'deduct'";
            $aSt = $pdo->prepare(
                "SELECT car_id, action, actor_name, note, created_at
                 FROM car_activity_log
                 WHERE id IN (SELECT MAX(id) FROM car_activity_log WHERE car_id IN ($in){$deductFilter} GROUP BY car_id)");
            $aSt->execute($ids);
            $latest = [];
            foreach ($aSt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                if (!$canSeeDeduct && !empty($a['note'])) {
                    $a['note'] = preg_replace('/判定不可結案（[^）]*）/u', '判定不可結案', $a['note']);
                    $a['note'] = str_replace('，待管理課扣款判定', '', $a['note']);
                }
                $latest[$a['car_id']] = $a;
            }

            $L = car_labels();
            $today = date('Y-m-d');
            $activeStatuses = ['applying','app_rejected','open','assigned','replying','pending_primary','pending_final'];
            foreach ($rows as &$r) {
                $r['status_label'] = $L['status'][$r['status']] ?? $r['status'];
                $r['source_label'] = $L['source_type'][$r['source_type']] ?? $r['source_type'];
                $r['counterparty_display'] = car_counterparty_display($pdo, $r['counterparty_type'],
                    $r['counterparty_type'] === 'maker' ? $r['maker_id_no'] : $r['customer_id']);
                $r['resp_show'] = $r['resp_display'] ?: ($r['resp_dept_name'] ?? '');
                $r['latest'] = $latest[$r['id']] ?? null;
                // 開立至今已歷工作天（僅未結案/未撤回/未退件之進行中單據；終態不計）
                $r['open_wd'] = in_array($r['status'], $activeStatuses, true)
                    ? car_working_days_between($pdo, (string)($r['fill_date'] ?: $r['created_at']), $today)
                    : null;
                // 目前關卡已停留工作天（供逾期判讀）
                $r['stage_wd'] = in_array($r['status'], $activeStatuses, true)
                    ? car_working_days_between($pdo, (string)($r['stage_since'] ?: $r['created_at']), $today)
                    : null;
            }
            unset($r);
        }

        jout(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page,
              'size' => $size, 'pages' => (int)ceil($total / $size), 'stats' => $stats,
              'remind_working_days' => (int)(car_setting($pdo, 'car_remind_working_days', '5') ?: 5),
              'print_header' => car_setting($pdo, 'car_print_header', ''),
              'print_footer' => car_setting($pdo, 'car_print_footer', '')]);
    }

    // ── 單筆完整資料（檢視 / 列印）──────────────────────────────────────────
    case 'get_detail': {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$id) jerr('缺少 id');
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?");
        $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jerr('查無此單', 404);
        // 檢閱權限 或 本單當事人（被指派/填表/簽核者可經通知連結開啟，即使無 car_view）
        if (!_carHas('car_view') && !car_is_stakeholder($pdo, $o, (int)$me['id']))
            jerr('您沒有檢閱權限，且非本單相關人員', 403);

        $L = car_labels();
        $o['status_label'] = $L['status'][$o['status']] ?? $o['status'];
        $o['source_label'] = $L['source_type'][$o['source_type']] ?? $o['source_type'];
        $o['counterparty_display'] = car_counterparty_display($pdo, $o['counterparty_type'],
            $o['counterparty_type'] === 'maker' ? $o['maker_id_no'] : $o['customer_id']);
        $o['created_by_title']      = car_user_title($pdo, $o['created_by'] ? (int)$o['created_by'] : null);
        $o['open_approved_by_title'] = car_user_title($pdo, $o['open_approved_by'] ? (int)$o['open_approved_by'] : null);

        $sg = $pdo->prepare("SELECT section, signed_by, signed_name, signed_date_label, signed_at, revoked
                             FROM car_signature WHERE car_id = ? ORDER BY id");
        $sg->execute([$id]); $sigs = $sg->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sigs as &$_s) { $_s['title'] = car_user_title($pdo, $_s['signed_by'] ? (int)$_s['signed_by'] : null); } unset($_s);
        $lg = $pdo->prepare("SELECT l.action, l.actor_id, COALESCE(u.user_cname, l.actor_name) AS actor_name, l.note, l.created_at
                             FROM car_activity_log l LEFT JOIN user u ON u.id = l.actor_id
                             WHERE l.car_id = ? ORDER BY l.id");
        $lg->execute([$id]); $acts = $lg->fetchAll(PDO::FETCH_ASSOC);
        foreach ($acts as &$_a) { $_a['title'] = car_user_title($pdo, $_a['actor_id'] ? (int)$_a['actor_id'] : null); } unset($_a);
        $at = $pdo->prepare("SELECT id, field_type, file_name, original_filename, file_size, tag_id, created_by, description, sort_order
                             FROM car_attachment WHERE car_id = ? ORDER BY sort_order, id");
        $at->execute([$id]); $atts = $at->fetchAll(PDO::FETCH_ASSOC);

        // 各區段目前是否已簽（同區段取最後一筆，未作廢才算已簽）
        $signed = [];
        foreach ($sigs as $s) $signed[$s['section']] = ((int)$s['revoked'] === 0);

        // 退件關聯：本單產生的 R 單、以及本單的母單
        $reissues = [];
        $rs = $pdo->prepare("SELECT id, car_no, status FROM car_order WHERE reissue_of = ?");
        $rs->execute([$id]);
        $reissues = $rs->fetchAll(PDO::FETCH_ASSOC);
        $parentNo = null;
        if (!empty($o['reissue_of'])) {
            $ps = $pdo->prepare("SELECT car_no FROM car_order WHERE id = ?");
            $ps->execute([(int)$o['reissue_of']]);
            $parentNo = $ps->fetchColumn() ?: null;
        }

        // 同事件群組（拆單/退件）
        $grp = [];
        if (!empty($o['group_no'])) {
            $gp = $pdo->prepare("SELECT id, car_no, status, resp_display FROM car_order WHERE group_no = ? ORDER BY car_no");
            $gp->execute([$o['group_no']]);
            $grp = $gp->fetchAll(PDO::FETCH_ASSOC);
        }

        // 目前使用者對此單可執行的動作
        $meId = (int)$me['id'];
        $isAssignee = (!empty($o['assigned_to']) && (int)$o['assigned_to'] === $meId);
        $canAssign  = car_can_assign_order($pdo, $o, $meId) || _carHas('car_assign');
        $canReply   = $isAssignee && in_array($o['status'], ['assigned', 'replying'], true);
        $canApprove = false;
        if ($o['status'] === 'applying') {
            if (_carHas('car_assign')) $canApprove = true;
            else foreach (car_dept_supervisors($pdo, (int)$o['opener_dept_id']) as $s) if ((int)$s['id'] === $meId) { $canApprove = true; break; }
        }
        $isCreator = ((int)$o['created_by'] === $meId);
        // 非開立人不可修改他人開立之單據（car_edit 不放行；唯系統管理員 all 例外）
        $canEditHeader = ($isCreator || _carHas('all'))
                         && in_array($o['status'], ['draft', 'applying', 'app_rejected', 'open'], true);
        $canResubmit = $isCreator && in_array($o['status'], ['app_rejected', 'draft'], true);
        $canWithdraw = $isCreator && $o['status'] === 'applying';
        $canSignPrimary = $o['status'] === 'pending_primary'
                          && (car_is_primary_candidate($pdo, $o, $meId) || _carHas('car_sign_primary'));
        $canFinal = $o['status'] === 'pending_final'
                    && (car_is_final_decider($pdo, $meId) || _carHas('car_sign_final'));
        $canDeduct = $o['status'] === 'closed' && empty($o['deduct_at'])
                     && (car_is_admin_deduct($pdo, $meId) || _carHas('car_manage_settings'));
        // 機密可視性：扣款判定與不可結案原因僅 扣款判定人員/最終決策者本人/系統管理員 可見。
        // 注意：未來實作代理簽核時，最終決策者的「代理人」不得納入此名單（見 圖章系統說明.md）。
        $canSeeDeduct = car_is_admin_deduct($pdo, $meId) || car_is_final_decider($pdo, $meId) || _carHas('all');
        if (!$canSeeDeduct) {
            $o['deduct_by'] = $o['deduct_by_name'] = $o['deduct_at'] = $o['deduct_amount'] = $o['deduct_note'] = null;
            $o['not_close_reason'] = null;
            // 軌跡遮罩：扣款判定列整筆隱藏；不可結案/結案列去除機密字句
            $acts = array_values(array_filter($acts, function ($a) { return ($a['action'] ?? '') !== 'deduct'; }));
            foreach ($acts as &$_a) {
                if (!empty($_a['note'])) {
                    $_a['note'] = preg_replace('/判定不可結案（[^）]*）/u', '判定不可結案', $_a['note']);
                    $_a['note'] = str_replace('，待管理課扣款判定', '', $_a['note']);
                }
            }
            unset($_a);
        }
        $perm = ['can_assign' => (bool)$canAssign, 'can_reply' => (bool)$canReply,
                 'can_approve' => (bool)$canApprove, 'is_assignee' => $isAssignee,
                 'can_edit_header' => (bool)$canEditHeader, 'can_resubmit' => (bool)$canResubmit,
                 'can_sign_primary' => (bool)$canSignPrimary, 'can_final' => (bool)$canFinal,
                 'can_deduct' => (bool)$canDeduct, 'can_see_deduct' => (bool)$canSeeDeduct,
                 'can_withdraw' => (bool)$canWithdraw,
                 'me_id' => $meId, 'me_name' => $me['name']];

        jout(['success' => true, 'order' => $o, 'labels' => $L,
              'signatures' => $sigs, 'signed' => $signed,
              'activity' => $acts, 'attachments' => $atts,
              'group' => $grp, 'reissues' => $reissues, 'parent_no' => $parentNo,
              'own_company' => car_own_company_full($pdo),
              'print_header' => car_setting($pdo, 'car_print_header', ''),
              'print_footer' => car_setting($pdo, 'car_print_footer', ''),
              'perm' => $perm]);
    }

    // ── 目前使用者的(部門,職務)身分（兼任時開單選擇用）──────────────────────
    case 'my_positions': {
        jout(['success' => true, 'data' => car_user_positions($pdo, (int)$me['id'])]);
    }

    // ── 主管核准開立申請 → 配號成立 ─────────────────────────────────────────
    case 'approve_open': {
        $group = trim($_POST['group_no'] ?? '');
        if ($group === '') jfail('缺少申請群組');
        $st = $pdo->prepare("SELECT * FROM car_order WHERE group_no = ? AND status = 'applying' ORDER BY id");
        $st->execute([$group]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) jfail('查無待核准的申請（可能已處理）');
        $deptId = (int)$rows[0]['opener_dept_id'];
        $ok = _carHas('car_assign');
        if (!$ok) foreach (car_dept_supervisors($pdo, $deptId) as $s) if ((int)$s['id'] === (int)$me['id']) { $ok = true; break; }
        if (!$ok) jerr('您不是此申請所屬部門的主管，無法核准', 403);

        $pdo->beginTransaction();
        try {
            $nn = count($rows);
            $nos = car_alloc_numbers($pdo, $nn);
            $first = $nos[0];
            $up = $pdo->prepare("UPDATE car_order SET car_no=?, group_no=?, status=?, stage_since=NOW(),
                                   open_approved_by=?, open_approved_by_name=?, open_approved_at=NOW() WHERE id=?");
            $upAssign = $pdo->prepare("UPDATE car_order SET assigned_to=?, assigned_to_name=?,
                                         assigned_by=?, assigned_by_name=?, assigned_at=NOW(), stage_since=NOW() WHERE id=?");
            $pnSt = $pdo->prepare("SELECT user_cname FROM user WHERE id = ?");
            foreach ($rows as $i => $r) {
                $rowStatus = !empty($r['resp_person_id']) ? 'assigned' : 'open';
                $up->execute([$nos[$i], $first, $rowStatus, $me['id'], $me['name'], $r['id']]);
                car_log($pdo, (int)$r['id'], 'approve_open', (int)$me['id'], $me['name'], "核准開立，成立單號 {$nos[$i]}");
                if (!empty($r['resp_person_id'])) {
                    $pnSt->execute([(int)$r['resp_person_id']]); $pn = (string)($pnSt->fetchColumn() ?: '');
                    $upAssign->execute([(int)$r['resp_person_id'], $pn, $me['id'], $me['name'], $r['id']]);
                    car_log($pdo, (int)$r['id'], 'assign', (int)$me['id'], $me['name'], "責任單位已指定人員「{$pn}」，直接為回覆人（免指派）");
                }
                if ($r['source_type'] === 'QA' && $r['source_ref_id']) {
                    $pdo->prepare("UPDATE qa_abnormal_order SET capa_order_no=? WHERE id=? AND (capa_order_no IS NULL OR capa_order_no='')")
                        ->execute([$first, $r['source_ref_id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jerr('核准失敗：' . $e->getMessage(), 500);
        }

        // 通知：申請人 + 各單（指定人員→回覆人；否則責任單位主管待指派）
        try {
            foreach ($rows as $i => $r) {
                $ro = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $ro->execute([$r['id']]);
                $row = $ro->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;
                if ($i === 0) {
                    car_notify($pdo, (int)$r['id'],
                        car_notify_title('✅', $row, '開立申請已核准成立'),
                        car_notify_body($pdo, $row, "核 准 人：{$me['name']}"),
                        [(int)$row['created_by']], (int)$me['id']);
                }
                if (!empty($row['assigned_to'])) {
                    car_notify($pdo, (int)$r['id'],
                        car_notify_title('🔧', $row, '指派您回覆'),
                        car_notify_body($pdo, $row, '您已被指定為回覆人，請填寫異常原因分析與處理情形。'),
                        [(int)$row['assigned_to']], (int)$me['id'], 'reply');   // 行動型
                    // 一併知會責任單位主管（依被開立部門查主管，兼任主管以該部門身分納入；回覆人已另收行動通知故排除）
                    car_notify($pdo, (int)$r['id'],
                        car_notify_title('📣', $row, '貴單位被開立矯正單'),
                        car_notify_body($pdo, $row, "已核准成立，貴單位人員「{$row['assigned_to_name']}」已指定為回覆人。"),
                        array_diff(car_primary_recipients($pdo, $row), [(int)$row['assigned_to']]), (int)$me['id']);
                } else {
                    car_notify($pdo, (int)$r['id'],
                        car_notify_title('🔧', $row, '待指派回覆人'),
                        car_notify_body($pdo, $row, '請主管指派一名回覆人。'),
                        car_primary_recipients($pdo, $row), (int)$me['id']);
                }
            }
        } catch (Throwable $e) {}
        jout(['success' => true, 'message' => "已核准成立，首號 {$first}", 'first' => $first]);
    }

    // ── 主管退回開立申請 ────────────────────────────────────────────────────
    case 'reject_open': {
        $group  = trim($_POST['group_no'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        if ($group === '') jfail('缺少申請群組');
        if ($reason === '') jfail('請填寫退回原因');
        $st = $pdo->prepare("SELECT id, opener_dept_id FROM car_order WHERE group_no = ? AND status = 'applying'");
        $st->execute([$group]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) jfail('查無待核准的申請（可能已處理）');
        $deptId = (int)$rows[0]['opener_dept_id'];
        $ok = _carHas('car_assign');
        if (!$ok) foreach (car_dept_supervisors($pdo, $deptId) as $s) if ((int)$s['id'] === (int)$me['id']) { $ok = true; break; }
        if (!$ok) jerr('您不是此申請所屬部門的主管，無法退回', 403);

        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare("UPDATE car_order SET status='app_rejected', open_reject_reason=?,
                                   open_approved_by=?, open_approved_by_name=?, open_approved_at=NOW(), stage_since=NOW() WHERE id=?");
            foreach ($rows as $r) {
                $up->execute([$reason, $me['id'], $me['name'], $r['id']]);
                car_log($pdo, (int)$r['id'], 'reject_open', (int)$me['id'], $me['name'], "退回開立申請：{$reason}");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jerr('退回失敗：' . $e->getMessage(), 500);
        }
        // 通知申請人：申請被退回
        try {
            $ro = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $ro->execute([(int)$rows[0]['id']]);
            $row = $ro->fetch(PDO::FETCH_ASSOC);
            if ($row) car_notify($pdo, (int)$rows[0]['id'],
                car_notify_title('↩️', $row, '開立申請被退回'),
                car_notify_body($pdo, $row, "退回原因：{$reason}\n退 回 人：{$me['name']}\n可修改內容後重新送出申請。"),
                [(int)$row['created_by']], (int)$me['id']);
        } catch (Throwable $e) {}
        jout(['success' => true, 'message' => '已退回申請']);
    }

    // ── 指派回覆人 候選名單 ─────────────────────────────────────────────────
    case 'get_assignees': {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT resp_type, resp_dept_id FROM car_order WHERE id = ?");
        $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        $rows = [];
        if ($o['resp_type'] === 'dept' && $o['resp_dept_id']) {
            $u = $pdo->prepare("SELECT u.id, u.user_cname, p.name AS position_name
                                FROM user_department_position_map m JOIN user u ON u.id = m.user_id
                                LEFT JOIN position p ON p.id = m.position_id
                                WHERE m.department_id = ? AND u.state IN (1,99) ORDER BY u.user_cname");
            $u->execute([(int)$o['resp_dept_id']]);
            $rows = $u->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($o['resp_type'] === 'maker') {
            // 廠商責任 → 由生管代填，候選為生管部門成員
            $ids = json_decode(car_setting($pdo, 'car_pm_dept_ids', '[]'), true);
            if (is_array($ids)) foreach ($ids as $d) {
                $u = $pdo->prepare("SELECT u.id, u.user_cname, p.name AS position_name
                                    FROM user_department_position_map m JOIN user u ON u.id = m.user_id
                                    LEFT JOIN position p ON p.id = m.position_id
                                    WHERE m.department_id = ? AND u.state IN (1,99)");
                $u->execute([(int)$d]);
                foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['id']] = $r;
            }
            $rows = array_values($rows);
        }
        jout(['success' => true, 'data' => $rows, 'resp_type' => $o['resp_type']]);
    }

    // ── 主管指派回覆人 ──────────────────────────────────────────────────────
    case 'assign': {
        $id = (int)($_POST['car_id'] ?? 0);
        $assignee = (int)($_POST['assignee_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if (!car_can_assign_order($pdo, $o, (int)$me['id']) && !_carHas('car_assign'))
            jerr('您不是此單責任單位的主管，無法指派', 403);
        if ($o['status'] !== 'open') jfail('目前狀態不可指派（可能已被指派）');
        if (!$assignee) jfail('請選擇回覆人');
        $un = $pdo->prepare("SELECT user_cname FROM user WHERE id = ?"); $un->execute([$assignee]);
        $aname = $un->fetchColumn() ?: '';
        $pdo->prepare("UPDATE car_order SET assigned_to=?, assigned_to_name=?, assigned_by=?, assigned_by_name=?,
                        assigned_at=NOW(), status='assigned', stage_since=NOW() WHERE id=?")
            ->execute([$assignee, $aname, $me['id'], $me['name'], $id]);
        car_log($pdo, $id, 'assign', (int)$me['id'], $me['name'], "指派「{$aname}」為回覆人");
        // 通知被指派者
        try {
            $ro = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $ro->execute([$id]);
            $row = $ro->fetch(PDO::FETCH_ASSOC);
            if ($row) car_notify($pdo, $id,
                car_notify_title('🔧', $row, '指派您回覆'),
                car_notify_body($pdo, $row, "指 派 人：{$me['name']}\n請填寫異常原因分析與處理情形並簽章送出。"),
                [$assignee], (int)$me['id'], 'reply');   // 行動型：填寫送出前通知持續顯示
        } catch (Throwable $e) {}
        jout(['success' => true, 'message' => "已指派 {$aname} 回覆"]);
    }

    // ── 被指派者：儲存三段草稿（已簽區段不覆寫）────────────────────────────
    case 'save_reply': {
        $id = (int)($_POST['car_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ((int)$o['assigned_to'] !== (int)$me['id']) jerr('您不是本單的回覆人', 403);
        if (!in_array($o['status'], ['assigned', 'replying'], true)) jfail('目前狀態不可填寫');
        $signed = car_signed_map($pdo, $id);

        $validCause = ['person','material','machine','method','tool','other'];
        $ciArr = json_decode($_POST['cause_investigation'] ?? '[]', true);
        if (!is_array($ciArr)) $ciArr = array_filter(array_map('trim', explode(',', (string)($_POST['cause_investigation'] ?? ''))));
        $ci = implode(',', array_values(array_intersect($validCause, $ciArr)));

        $sets = []; $p = [':id' => $id];
        if (empty($signed['cause'])) {
            $sets[] = 'cause_investigation=:ci'; $p[':ci'] = ($ci ?: null);
            $sets[] = 'cause_other=:co';        $p[':co'] = (trim($_POST['cause_other'] ?? '') ?: null);
            $sets[] = 'cause_detail=:cd';       $p[':cd'] = (trim($_POST['cause_detail'] ?? '') ?: null);
        }
        if (empty($signed['correction'])) {
            $disp = $_POST['disposition'] ?? '';
            if (!in_array($disp, ['special_accept','rework','scrap','return','other'], true)) $disp = null;
            $sets[] = 'disposition=:dp';        $p[':dp'] = $disp;
            $sets[] = 'disposition_other=:dpo'; $p[':dpo'] = (trim($_POST['disposition_other'] ?? '') ?: null);
            $sets[] = 'correction_measure=:cm'; $p[':cm'] = (trim($_POST['correction_measure'] ?? '') ?: null);
            $sets[] = 'correction_due=:cdue';   $p[':cdue'] = (trim($_POST['correction_due'] ?? '') ?: null);
        }
        if (empty($signed['prevention'])) {
            $sets[] = 'prevention_measure=:pm'; $p[':pm'] = (trim($_POST['prevention_measure'] ?? '') ?: null);
            $sets[] = 'prevention_due=:pdue';   $p[':pdue'] = (trim($_POST['prevention_due'] ?? '') ?: null);
        }
        $sets[] = "status='replying'";
        if ($o['status'] !== 'replying') $sets[] = "stage_since=NOW()";   // 進入「填寫中」起算逾期
        $pdo->prepare("UPDATE car_order SET " . implode(',', $sets) . " WHERE id=:id")->execute($p);
        jout(['success' => true, 'message' => '已儲存']);
    }

    // ── 被指派者：某區段簽章（壓日期；廠商責任壓廠商名）────────────────────
    case 'sign_section': {
        $id  = (int)($_POST['car_id'] ?? 0);
        $sec = $_POST['section'] ?? '';
        if (!in_array($sec, ['cause', 'correction', 'prevention'], true)) jfail('區段錯誤');
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ((int)$o['assigned_to'] !== (int)$me['id']) jerr('您不是本單的回覆人', 403);
        if (!in_array($o['status'], ['assigned', 'replying'], true)) jfail('目前狀態不可簽章');
        $signed = car_signed_map($pdo, $id);
        if (!empty($signed[$sec])) jfail('該區段已簽章，如需修改請先按「修改」');

        // 必填檢核（以 DB 現值）
        if ($sec === 'cause' && (($o['cause_investigation'] ?? '') === '' || trim((string)$o['cause_detail']) === ''))
            jfail('請先填寫原因調查與原因分析內容再簽章');
        if ($sec === 'correction' && (($o['disposition'] ?? '') === '' || trim((string)$o['correction_measure']) === ''))
            jfail('請先填寫處置方式與矯正措施再簽章');
        if ($sec === 'prevention' && trim((string)$o['prevention_measure']) === '')
            jfail('請先填寫預防措施再簽章');

        $signer = car_reply_signer_name($pdo, $o, $me['name']);
        $pdo->prepare("INSERT INTO car_signature (car_id, section, signed_by, signed_name, signed_at, signed_date_label)
                       VALUES (?, ?, ?, ?, NOW(), ?)")
            ->execute([$id, $sec, $me['id'], $signer, car_sign_date_label()]);
        car_log($pdo, $id, 'sign', (int)$me['id'], $me['name'], car_section_name($sec) . " 簽章（{$signer}）");
        jout(['success' => true, 'message' => '已簽章', 'signer' => $signer, 'date' => car_sign_date_label()]);
    }

    // ── 被指派者：取消某區段簽章以便修改 ───────────────────────────────────
    case 'unsign_section': {
        $id  = (int)($_POST['car_id'] ?? 0);
        $sec = $_POST['section'] ?? '';
        if (!in_array($sec, ['cause', 'correction', 'prevention'], true)) jfail('區段錯誤');
        $st = $pdo->prepare("SELECT assigned_to, status FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ((int)$o['assigned_to'] !== (int)$me['id']) jerr('您不是本單的回覆人', 403);
        if (!in_array($o['status'], ['assigned', 'replying'], true)) jfail('目前狀態不可修改');
        $pdo->prepare("UPDATE car_signature SET revoked=1 WHERE car_id=? AND section=? AND revoked=0")->execute([$id, $sec]);
        car_log($pdo, $id, 'unsign', (int)$me['id'], $me['name'], car_section_name($sec) . " 取消簽章重新修改");
        jout(['success' => true, 'message' => '已取消簽章，可修改後重新簽章']);
    }

    // ── 被指派者：三段完成、送出 → 待主管簽核 ──────────────────────────────
    case 'submit_reply': {
        $id = (int)($_POST['car_id'] ?? 0);
        $st = $pdo->prepare("SELECT assigned_to, status FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ((int)$o['assigned_to'] !== (int)$me['id']) jerr('您不是本單的回覆人', 403);
        if (!in_array($o['status'], ['assigned', 'replying'], true)) jfail('目前狀態不可送出');
        $signed = car_signed_map($pdo, $id);
        foreach (['cause', 'correction', 'prevention'] as $s) {
            if (empty($signed[$s])) jfail(car_section_name($s) . ' 尚未簽章，無法送出');
        }
        $pdo->prepare("UPDATE car_order SET status='pending_primary', submitted_at=NOW(), stage_since=NOW() WHERE id=?")->execute([$id]);
        car_log($pdo, $id, 'submit_reply', (int)$me['id'], $me['name'], '回覆完成送出，待主管簽核');
        car_notify_done($pdo, $id, (int)$me['id']);   // 完成填寫送出 → 清除本人的行動型通知
        // 通知首要決策者（責任單位主管／廠商→生管主管）簽核
        try {
            $ro = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $ro->execute([$id]);
            $row = $ro->fetch(PDO::FETCH_ASSOC);
            if ($row) car_notify($pdo, $id,
                car_notify_title('🖊️', $row, '待您簽核'),
                car_notify_body($pdo, $row, "回 覆 人：{$me['name']} 已完成三段填寫並簽章。"),
                car_primary_recipients($pdo, $row), (int)$me['id']);
        } catch (Throwable $e) {}
        jout(['success' => true, 'message' => '已送出，待主管簽核']);
    }

    // ── 首要決策者（責任單位主管／廠商責任→生管主管）簽核 ──────────────────
    case 'primary_sign': {
        $id = (int)($_POST['car_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ($o['status'] !== 'pending_primary') jfail('目前狀態不在「待主管簽核」');
        if (!car_is_primary_candidate($pdo, $o, (int)$me['id']) && !_carHas('car_sign_primary'))
            jerr('您不是本單責任單位的主管，無法簽核', 403);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO car_signature (car_id, section, signed_by, signed_name, signed_at, signed_date_label)
                           VALUES (?, 'primary', ?, ?, NOW(), ?)")
                ->execute([$id, $me['id'], $me['name'], car_sign_date_label()]);
            $pdo->prepare("UPDATE car_order SET status='pending_final', primary_by=?, primary_at=NOW(), stage_since=NOW() WHERE id=?")
                ->execute([$me['id'], $id]);
            car_log($pdo, $id, 'primary_sign', (int)$me['id'], $me['name'], '主管簽核通過，待總經理裁決');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jerr('簽核失敗：' . $e->getMessage(), 500);
        }
        // 通知最終決策者（總經理）裁決
        try {
            $fd = array_map(function ($d) { return (int)$d['id']; }, car_final_deciders($pdo));
            car_notify($pdo, $id,
                car_notify_title('🏁', $o, '待您最終裁決（結案/不可結案）'),
                car_notify_body($pdo, $o, "主管簽核：{$me['name']} 已簽核通過。"),
                $fd, (int)$me['id']);
        } catch (Throwable $e) {}
        jout(['success' => true, 'message' => '已簽核，待總經理裁決']);
    }

    // ── 首要決策者：退回重改（填原因，退回責任人重新填寫）──────────────────
    case 'primary_reject': {
        $id     = (int)($_POST['car_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') jfail('請填寫退回原因');
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ($o['status'] !== 'pending_primary') jfail('目前狀態不在「待主管簽核」');
        if (!car_is_primary_candidate($pdo, $o, (int)$me['id']) && !_carHas('car_sign_primary'))
            jerr('您不是本單責任單位的主管，無法退回', 403);
        if (!$o['assigned_to']) jfail('本單無回覆人可退回');

        // 退回「填寫中」，責任人可取消簽章修改後重新送出（不作廢三段簽章，讓責任人自行決定改哪段）
        $pdo->prepare("UPDATE car_order SET status='replying', stage_since=NOW() WHERE id=?")->execute([$id]);
        car_log($pdo, $id, 'primary_reject', (int)$me['id'], $me['name'], "主管退回重改：{$reason}");
        // 通知責任人：退回重改（行動型，重送前持續顯示；submit_reply 時 car_notify_done 銷單）
        try {
            car_notify($pdo, $id,
                car_notify_title('↩️', $o, '主管退回，請重新填寫'),
                car_notify_body($pdo, $o, "退回原因：{$reason}\n退 回 人：{$me['name']}\n請依原因修改後重新簽章送出。"),
                [(int)$o['assigned_to']], (int)$me['id'], 'reply');
        } catch (Throwable $e) {}
        jout(['success' => true, 'message' => '已退回責任人重新填寫']);
    }

    // ── 最終決策者（總經理）裁決：結案 或 不可結案→退件產生 R 單 ────────────
    case 'final_decide': {
        $id     = (int)($_POST['car_id'] ?? 0);
        $result = $_POST['result'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        if (!in_array($result, ['close', 'not_close'], true)) jfail('請選擇 結案 或 不可結案');
        if ($result === 'not_close' && $reason === '') jfail('不可結案需填寫原因');
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ($o['status'] !== 'pending_final') jfail('目前狀態不在「待總經理裁決」');
        if (!car_is_final_decider($pdo, (int)$me['id']) && !_carHas('car_sign_final'))
            jerr('您不是設定的最終決策者（總經理），無法裁決', 403);

        $pdo->beginTransaction();
        try {
            // 總經理簽章（勾選即自動簽章，結案日期押今日）
            $pdo->prepare("INSERT INTO car_signature (car_id, section, signed_by, signed_name, signed_at, signed_date_label)
                           VALUES (?, 'final', ?, ?, NOW(), ?)")
                ->execute([$id, $me['id'], $me['name'], car_sign_date_label()]);

            if ($result === 'close') {
                // 總經理結案時直接判定扣款：未填 = 不扣款(0)
                $amtRaw = trim($_POST['deduct_amount'] ?? '');
                if ($amtRaw !== '' && (!is_numeric($amtRaw) || (float)$amtRaw < 0)) { $pdo->rollBack(); jfail('扣款金額格式錯誤'); }
                $amt  = ($amtRaw === '') ? 0.0 : (float)$amtRaw;
                $note = trim($_POST['deduct_note'] ?? '');
                $pdo->prepare("UPDATE car_order SET status='closed', result='close', close_date=CURDATE(),
                                 final_by=?, final_at=NOW(), stage_since=NOW(),
                                 deduct_by=?, deduct_by_name=?, deduct_at=NOW(), deduct_amount=?, deduct_note=? WHERE id=?")
                    ->execute([$me['id'], $me['id'], $me['name'], $amt, ($note ?: null), $id]);
                car_log($pdo, $id, 'final_close', (int)$me['id'], $me['name'], '總經理核准結案（結案日 ' . date('Y.m.d') . '）');
                // 扣款內容記為機密軌跡列（action=deduct，未授權者看不到）
                $amtTxt = $amt > 0 ? ('扣款 ' . number_format($amt, 2)) : '不扣款';
                car_log($pdo, $id, 'deduct', (int)$me['id'], $me['name'], "總經理判定：{$amtTxt}" . ($note ? "（{$note}）" : ''));
                $pdo->commit();
                // 通知：主管+回覆人+填表人（結案結果，不含扣款）、管理課（扣款結果，機密收件人）
                try {
                    $who = car_primary_recipients($pdo, $o);
                    $who[] = (int)$o['assigned_to']; $who[] = (int)$o['created_by'];
                    car_notify($pdo, $id,
                        car_notify_title('✅', $o, '已結案'),
                        car_notify_body($pdo, $o, "總經理核准結案，結案日期 " . date('Y.m.d') . "。"),
                        $who, (int)$me['id']);
                    car_notify($pdo, $id,
                        car_notify_title('💰', $o, '結案扣款判定結果'),
                        car_notify_body($pdo, $o, "總經理判定：{$amtTxt}" . ($note ? "\n備　　註：{$note}" : '')),
                        car_deduct_recipients($pdo), (int)$me['id']);
                } catch (Throwable $e) {}
                jout(['success' => true, 'message' => '已結案（' . $amtTxt . '）']);
            }

            // 不可結案：原單標記 rejected + 產生 R 單（表頭帶入、三段重填）
            $pdo->prepare("UPDATE car_order SET status='rejected', result='not_close', not_close_reason=?,
                             final_by=?, final_at=NOW(), stage_since=NOW() WHERE id=?")->execute([$reason, $me['id'], $id]);

            list($rNo, $rSeq) = car_next_reissue_no($pdo, (string)$o['car_no']);
            $newStatus = !empty($o['resp_person_id']) ? 'assigned' : 'open';
            $pdo->prepare(
                "INSERT INTO car_order
                   (car_no, group_no, reissue_of, reissue_seq, source_type, source_ref_id, source_no, source_desc,
                    counterparty_type, customer_id, maker_id_no, d_id, drawing_no, bom_no, work_order, bom_ing_fid, qty,
                    fill_date, found_date, created_by, created_by_name,
                    opener_dept_id, opener_position_id, opener_position_name,
                    resp_type, resp_dept_id, resp_maker_id, resp_own_customer_id, resp_person_id, resp_display,
                    process_no, process_name, abnormal_desc, status, stage_since)
                 SELECT ?, group_no, id, ?, source_type, source_ref_id, source_no, source_desc,
                    counterparty_type, customer_id, maker_id_no, d_id, drawing_no, bom_no, work_order, bom_ing_fid, qty,
                    CURDATE(), found_date, created_by, created_by_name,
                    opener_dept_id, opener_position_id, opener_position_name,
                    resp_type, resp_dept_id, resp_maker_id, resp_own_customer_id, resp_person_id, resp_display,
                    process_no, process_name, abnormal_desc, ?, NOW()
                 FROM car_order WHERE id = ?")
                ->execute([$rNo, $rSeq, $newStatus, $id]);
            $newId = (int)$pdo->lastInsertId();

            // 複製異常說明簽章（表頭沿用原簽）
            $pdo->prepare("INSERT INTO car_signature (car_id, section, signed_by, signed_name, signed_at, signed_date_label)
                           SELECT ?, 'desc', signed_by, signed_name, signed_at, signed_date_label
                           FROM car_signature WHERE car_id = ? AND section = 'desc' AND revoked = 0 LIMIT 1")
                ->execute([$newId, $id]);

            // 指定人員者自動指派
            if ($newStatus === 'assigned') {
                $pnSt = $pdo->prepare("SELECT user_cname FROM user WHERE id = ?");
                $pnSt->execute([(int)$o['resp_person_id']]);
                $pn = (string)($pnSt->fetchColumn() ?: '');
                $pdo->prepare("UPDATE car_order SET assigned_to=?, assigned_to_name=?, assigned_by=?, assigned_by_name=?, assigned_at=NOW(), stage_since=NOW() WHERE id=?")
                    ->execute([(int)$o['resp_person_id'], $pn, $me['id'], $me['name'], $newId]);
            }

            car_log($pdo, $id, 'final_reject', (int)$me['id'], $me['name'], "判定不可結案（{$reason}），產生退件單 {$rNo}");
            car_log($pdo, $newId, 'reissue', (int)$me['id'], $me['name'], "由 {$o['car_no']} 退件產生（第 {$rSeq} 次），異常原因分析與處理情形需重新填寫");
            $pdo->commit();
            // 通知：主管+回覆人+填表人（退件結果與新單號，回覆人需重新填寫）
            try {
                $who = car_primary_recipients($pdo, $o);
                $who[] = (int)$o['assigned_to']; $who[] = (int)$o['created_by'];
                car_notify($pdo, $newId,
                    car_notify_title('❌', $o, "判定不可結案，退件單 {$rNo}"),
                    car_notify_body($pdo, $o, "已自動產生退件單 {$rNo}，異常原因分析與處理情形需重新填寫。"),   // 原因為機密，不隨通知外流
                    $who, (int)$me['id']);
            } catch (Throwable $e) {}
            jout(['success' => true, 'message' => "已判定不可結案，產生退件單 {$rNo}", 'reissue_no' => $rNo, 'reissue_id' => $newId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jerr('裁決失敗：' . $e->getMessage(), 500);
        }
    }

    // ── 簽核流程設定（讀）──────────────────────────────────────────────────
    case 'get_flow_settings': {
        if (!_carHas('car_manage_settings')) jerr('您沒有管理設定權限', 403);
        $depts = $pdo->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $positions = $pdo->query("SELECT id, name FROM position ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        // 指定通知人員名單（含姓名，供設定畫面顯示）
        $adminUids = json_decode(car_setting($pdo, 'car_admin_user_ids', '[]'), true) ?: [];
        $adminUsers = [];
        if ($adminUids) {
            $in = implode(',', array_map('intval', $adminUids));
            $adminUsers = $pdo->query("SELECT id, user_cname FROM user WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        }
        jout(['success' => true,
              'supervisor_min_level' => (int)(car_setting($pdo, 'car_supervisor_min_level', '2') ?: 2),
              'pm_dept_ids' => json_decode(car_setting($pdo, 'car_pm_dept_ids', '[]'), true) ?: [],
              'final_decider_position' => car_setting($pdo, 'car_final_decider_position', ''),
              'attach_root_path' => car_setting($pdo, 'car_attach_root_path', ''),
              'admin_dept_ids' => json_decode(car_setting($pdo, 'car_admin_dept_ids', '[]'), true) ?: [],
              'admin_users' => $adminUsers,
              'print_header' => car_setting($pdo, 'car_print_header', ''),
              'print_footer' => car_setting($pdo, 'car_print_footer', ''),
              'remind_working_days' => (int)(car_setting($pdo, 'car_remind_working_days', '5') ?: 5),
              'remind_enabled' => (car_setting($pdo, 'car_remind_enabled', '1') !== '0') ? 1 : 0,
              'depts' => $depts, 'positions' => $positions]);
    }

    // ── 簽核流程設定（存）──────────────────────────────────────────────────
    case 'save_flow_settings': {
        if (!_carHas('car_manage_settings')) jerr('您沒有管理設定權限', 403);
        $lvl = (int)($_POST['supervisor_min_level'] ?? 2);
        if ($lvl < 1 || $lvl > 3) $lvl = 2;
        $pm = json_decode($_POST['pm_dept_ids'] ?? '[]', true);
        if (!is_array($pm)) $pm = [];
        $pm = array_values(array_unique(array_map('intval', $pm)));
        $ad = json_decode($_POST['admin_dept_ids'] ?? '[]', true);
        if (!is_array($ad)) $ad = [];
        $ad = array_values(array_unique(array_map('intval', $ad)));
        $au = json_decode($_POST['admin_user_ids'] ?? '[]', true);
        if (!is_array($au)) $au = [];
        $au = array_slice(array_values(array_unique(array_map('intval', $au))), 0, 2);   // 指定判定人員上限 2 人
        $pos = trim($_POST['final_decider_position'] ?? '');
        $path = trim($_POST['attach_root_path'] ?? '');
        car_setting_set($pdo, 'car_supervisor_min_level', (string)$lvl, (int)$me['id']);
        car_setting_set($pdo, 'car_pm_dept_ids', json_encode($pm), (int)$me['id']);
        car_setting_set($pdo, 'car_admin_dept_ids', json_encode($ad), (int)$me['id']);
        car_setting_set($pdo, 'car_admin_user_ids', json_encode($au), (int)$me['id']);
        car_setting_set($pdo, 'car_final_decider_position', $pos, (int)$me['id']);
        if ($path !== '') car_setting_set($pdo, 'car_attach_root_path', $path, (int)$me['id']);
        car_setting_set($pdo, 'car_print_header', trim($_POST['print_header'] ?? ''), (int)$me['id']);
        car_setting_set($pdo, 'car_print_footer', trim($_POST['print_footer'] ?? ''), (int)$me['id']);
        $rwd = (int)($_POST['remind_working_days'] ?? 5);
        if ($rwd < 1) $rwd = 1; if ($rwd > 60) $rwd = 60;   // 逾期提醒工作天數 1~60
        car_setting_set($pdo, 'car_remind_working_days', (string)$rwd, (int)$me['id']);
        car_setting_set($pdo, 'car_remind_enabled', (($_POST['remind_enabled'] ?? '1') === '0') ? '0' : '1', (int)$me['id']);
        jout(['success' => true, 'message' => '設定已儲存']);
    }

    // ── 管理課扣款判定（結案後；留存簽核人/時間/金額/備註）──────────────────
    case 'deduct_sign': {
        $id     = (int)($_POST['car_id'] ?? 0);
        $amount = trim($_POST['amount'] ?? '');
        $note   = trim($_POST['note'] ?? '');
        if ($amount === '' || !is_numeric($amount) || (float)$amount < 0) jfail('請填寫扣款金額（0 表示不扣款）');
        $st = $pdo->prepare("SELECT status, deduct_at, car_no FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ($o['status'] !== 'closed') jfail('僅已結案單據可做扣款判定');
        if ($o['deduct_at']) jfail('本單已完成扣款判定');
        if (!car_is_admin_deduct($pdo, (int)$me['id']) && !_carHas('car_manage_settings'))
            jerr('您不是設定的管理課判定人員', 403);

        $pdo->prepare("UPDATE car_order SET deduct_by=?, deduct_by_name=?, deduct_at=NOW(), deduct_amount=?, deduct_note=? WHERE id=?")
            ->execute([$me['id'], $me['name'], (float)$amount, ($note ?: null), $id]);
        $amtTxt = ((float)$amount > 0) ? ('扣款 ' . number_format((float)$amount, 2)) : '不扣款';
        car_log($pdo, $id, 'deduct', (int)$me['id'], $me['name'], "管理課判定：{$amtTxt}" . ($note ? "（{$note}）" : ''));
        jout(['success' => true, 'message' => '已完成扣款判定']);
    }

    // ── 修改表頭（僅開立人本人；系統管理員例外；限 申請中/申請退回/待指派）───
    case 'update_order': {
        $id = (int)($_POST['car_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        $isCreator = ((int)$o['created_by'] === (int)$me['id']);
        if (!$isCreator && !_carHas('all')) jerr('非開立人不可修改他人開立之單據', 403);
        if (!in_array($o['status'], ['draft', 'applying', 'app_rejected', 'open'], true))
            jfail('此單已進入回覆/簽核流程，表頭不可修改');

        $source_type = $_POST['source_type'] ?? $o['source_type'];
        if (!in_array($source_type, ['QA', 'IR', 'OTHER'], true)) jfail('異常來源錯誤');
        $source_ref_id = (int)($_POST['source_ref_id'] ?? 0) ?: null;
        if ($source_type === 'QA' && !$source_ref_id) jfail('請選擇對應的品質異常處理單');
        if ($source_type === 'IR' && !$source_ref_id) jfail('請選擇對應的客戶退貨單');
        $abnormal_desc = trim($_POST['abnormal_desc'] ?? '');
        if ($abnormal_desc === '') jfail('異常說明為必填');
        $counterparty_type = $_POST['counterparty_type'] ?? '';
        if ($counterparty_type !== 'customer' && $counterparty_type !== 'maker') $counterparty_type = null;

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE car_order SET source_type=?, source_ref_id=?, source_no=?, source_desc=?,
                   counterparty_type=?, customer_id=?, maker_id_no=?,
                   d_id=?, drawing_no=?, bom_no=?, work_order=?, bom_ing_fid=?, qty=?, found_date=?,
                   abnormal_desc=? WHERE id=?")
                ->execute([
                    $source_type, $source_ref_id,
                    (trim($_POST['source_no'] ?? '') ?: null), (trim($_POST['source_desc'] ?? '') ?: null),
                    $counterparty_type,
                    (trim($_POST['customer_id'] ?? '') ?: null), (trim($_POST['maker_id_no'] ?? '') ?: null),
                    ((int)($_POST['d_id'] ?? 0) ?: null), (trim($_POST['drawing_no'] ?? '') ?: null),
                    (trim($_POST['bom_no'] ?? '') ?: null), (trim($_POST['work_order'] ?? '') ?: null),
                    ((int)($_POST['bom_ing_fid'] ?? 0) ?: null),
                    (($_POST['qty'] ?? '') === '' ? null : (float)$_POST['qty']),
                    (trim($_POST['found_date'] ?? '') ?: null),
                    $abnormal_desc, $id]);

            // 異常說明有變更 → 作廢原簽章、由修改者重新簽章
            if ($abnormal_desc !== (string)$o['abnormal_desc']) {
                $pdo->prepare("UPDATE car_signature SET revoked=1 WHERE car_id=? AND section='desc' AND revoked=0")->execute([$id]);
                $pdo->prepare("INSERT INTO car_signature (car_id, section, signed_by, signed_name, signed_at, signed_date_label)
                               VALUES (?, 'desc', ?, ?, NOW(), ?)")
                    ->execute([$id, $me['id'], $me['name'], car_sign_date_label()]);
            }
            car_log($pdo, $id, 'edit', (int)$me['id'], $me['name'], '修改表頭內容');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jerr('修改失敗：' . $e->getMessage(), 500);
        }
        jout(['success' => true, 'message' => '已儲存修改']);
    }

    // ── 申請人撤回申請（主管核准前；需填原因）───────────────────────────────
    case 'withdraw_application': {
        $id = (int)($_POST['car_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') jfail('請填寫撤回原因');
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ((int)$o['created_by'] !== (int)$me['id']) jerr('僅申請人本人可撤回', 403);
        if ($o['status'] !== 'applying') jfail('僅「申請中」（主管尚未核准）可撤回');
        $pdo->prepare("UPDATE car_order SET status='draft', open_reject_reason=?, stage_since=NOW() WHERE id=?")
            ->execute(['撤回：' . $reason, $id]);
        car_log($pdo, $id, 'withdraw', (int)$me['id'], $me['name'], "撤回開立申請：{$reason}");
        // 通知原收到申請的主管：已撤回
        try {
            $sup = array_map(function ($s) { return (int)$s['id']; }, car_dept_supervisors($pdo, (int)$o['opener_dept_id']));
            car_notify($pdo, $id,
                car_notify_title('↩️', $o, '開立申請已撤回'),
                car_notify_body($pdo, $o, "撤回原因：{$reason}\n撤 回 人：{$me['name']}"),
                $sup, (int)$me['id']);
        } catch (Throwable $e) {}
        jout(['success' => true, 'message' => '已撤回申請（可修改後重新送出）']);
    }

    // ── 申請退回/撤回後：修改完重新送出申請 ─────────────────────────────────
    case 'resubmit_application': {
        $id = (int)($_POST['car_id'] ?? 0);
        $st = $pdo->prepare("SELECT created_by, status FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ((int)$o['created_by'] !== (int)$me['id'] && !_carHas('all')) jerr('您不是本單申請人', 403);
        if ($o['status'] !== 'app_rejected' && $o['status'] !== 'draft') jfail('僅「申請退回」或「已撤回」狀態可重新送出');
        $pdo->prepare("UPDATE car_order SET status='applying', open_applied_at=NOW(), stage_since=NOW(),
                         open_reject_reason=NULL, open_approved_by=NULL, open_approved_by_name=NULL, open_approved_at=NULL
                       WHERE id=?")->execute([$id]);
        car_log($pdo, $id, 'reapply', (int)$me['id'], $me['name'], '修改後重新送出開立申請');
        // 通知開單者所屬部門主管重新核准
        try {
            $ro = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $ro->execute([$id]);
            $row = $ro->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $sup = array_map(function ($s) { return (int)$s['id']; }, car_dept_supervisors($pdo, (int)$row['opener_dept_id']));
                car_notify($pdo, $id,
                    car_notify_title('📝', $row, '開立申請（修改後重送）待您核准'),
                    car_notify_body($pdo, $row, "申 請 人：{$me['name']}"),
                    $sup, (int)$me['id']);
            }
        } catch (Throwable $e) {}
        jout(['success' => true, 'message' => '已重新送出申請，待主管核准']);
    }

    // ── 放棄申請（申請退回後開立人決定不再送出）→ 轉「已撤回」保留紀錄、停止逾期提醒 ─
    case 'abandon_application': {
        $id = (int)($_POST['car_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $st = $pdo->prepare("SELECT created_by, status, car_no FROM car_order WHERE id = ?"); $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        if ((int)$o['created_by'] !== (int)$me['id'] && !_carHas('all')) jerr('僅開立人本人可放棄申請', 403);
        if ($o['status'] !== 'app_rejected') jfail('僅「申請退回」狀態可放棄申請');
        $pdo->prepare("UPDATE car_order SET status='draft', stage_since=NOW() WHERE id=?")->execute([$id]);
        car_log($pdo, $id, 'abandon', (int)$me['id'], $me['name'], '放棄申請（轉為已撤回，保留紀錄，停止提醒）' . ($reason ? "：{$reason}" : ''));
        jout(['success' => true, 'message' => '已放棄申請（保留紀錄、停止提醒，日後仍可重新送出）']);
    }

    // ── 刪除單據（需 car_delete 且為開立人本人；系統管理員例外；連同簽章/軌跡/附件記錄一併刪除）
    case 'delete_order': {
        if (!_carHas('car_delete')) jerr('您沒有刪除權限', 403);
        $id = (int)($_POST['car_id'] ?? 0);
        if (!$id) jfail('缺少 car_id');
        $st = $pdo->prepare("SELECT car_no, source_type, source_ref_id, created_by FROM car_order WHERE id = ?");
        $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單（可能已刪除）');
        if ((int)$o['created_by'] !== (int)$me['id'] && !_carHas('all')) jerr('非開立人不可刪除他人開立之單據', 403);

        $pdo->beginTransaction();
        try {
            // 若來源品質異常單的 CAPA 號正是本單，清回空值
            if ($o['source_type'] === 'QA' && $o['source_ref_id'] && $o['car_no']) {
                $pdo->prepare("UPDATE qa_abnormal_order SET capa_order_no = NULL WHERE id = ? AND capa_order_no = ?")
                    ->execute([$o['source_ref_id'], $o['car_no']]);
            }
            $pdo->prepare("DELETE FROM car_signature   WHERE car_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM car_activity_log WHERE car_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM car_attachment  WHERE car_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM car_order       WHERE id = ?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jerr('刪除失敗：' . $e->getMessage(), 500);
        }
        jout(['success' => true, 'message' => '已刪除單據 ' . ($o['car_no'] ?: '（未配號）')]);
    }

    // ── CSV 匯出（依目前篩選；UTF-8 BOM 供 Excel 直開）───────────────────────
    case 'export_csv': {
        if (!_carHas('car_view')) jerr('您沒有檢閱權限', 403);
        $card = $_GET['card'] ?? $_POST['card'] ?? 'all';
        $resp = trim($_GET['resp'] ?? $_POST['resp'] ?? '');
        $src  = $_GET['source_type'] ?? $_POST['source_type'] ?? '';
        $kw   = trim($_GET['kw'] ?? $_POST['kw'] ?? '');

        $where = " FROM car_order o WHERE 1=1"; $args = [];
        if ($resp === 'maker') $where .= " AND o.resp_type='maker'";
        elseif ($resp !== '' && ctype_digit($resp)) { $where .= " AND o.resp_dept_id=:rd"; $args[':rd'] = (int)$resp; }
        if (in_array($src, ['QA','IR','OTHER'], true)) { $where .= " AND o.source_type=:stp"; $args[':stp'] = $src; }
        if ($kw !== '') { $where .= " AND (o.car_no LIKE :kw OR o.abnormal_desc LIKE :kw OR o.resp_display LIKE :kw OR o.source_no LIKE :kw)"; $args[':kw'] = "%$kw%"; }
        if ($card === 'pending_open') $where .= " AND o.status IN ('applying','app_rejected')";
        elseif ($card === 'unclosed') $where .= " AND o.status IN ('open','assigned','replying','pending_primary','pending_final')";
        elseif ($card === 'rejected') $where .= " AND o.status='rejected'";
        elseif ($card === 'closed') $where .= " AND o.status='closed'";

        // 以子查詢先套 WHERE/排序，再 JOIN 顯示欄位
        $st = $pdo->prepare(
            "SELECT t.*, d.name AS resp_dept_name,
                    COALESCE(cc.customer, mk.maker_id, '') AS cp_name
             FROM (SELECT o.*" . $where . " ORDER BY o.id DESC LIMIT 5000) t
             LEFT JOIN department d ON d.id = t.resp_dept_id
             LEFT JOIN customer_list cc ON cc.customer_id = t.customer_id
             LEFT JOIN maker_list mk ON mk.maker_id_no = t.maker_id_no");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // 機密欄位（不可結案原因/扣款）僅授權者匯出
        $canSeeDeduct = car_is_admin_deduct($pdo, (int)$me['id']) || car_is_final_decider($pdo, (int)$me['id']) || _carHas('all');

        $L = car_labels();
        $today = date('Y-m-d');
        $activeStatuses = ['applying','app_rejected','open','assigned','replying','pending_primary','pending_final'];
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('異常矯正處理單_' . date('Ymd_Hi') . '.csv'));
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM
        fputcsv($out, ['表單編號','狀態','已歷工作天','異常來源','來源單號','客戶/供應商','料號','製令BOM','數量',
                       '責任單位','開立人員','填表日期','發現日期','回覆人','結案日期','不可結案原因','扣款金額','扣款備註']);
        foreach ($rows as $r) {
            $owd = in_array($r['status'], $activeStatuses, true)
                ? car_working_days_between($pdo, (string)($r['fill_date'] ?: $r['created_at']), $today) : '';
            fputcsv($out, [
                $r['car_no'] ?: '（未配號）',
                $L['status'][$r['status']] ?? $r['status'],
                $owd,
                $L['source_type'][$r['source_type']] ?? $r['source_type'],
                $r['source_no'], $r['cp_name'], $r['drawing_no'], $r['work_order'], $r['qty'],
                $r['resp_display'] ?: $r['resp_dept_name'],
                $r['created_by_name'], $r['fill_date'], $r['found_date'], $r['assigned_to_name'],
                $r['close_date'],
                $canSeeDeduct ? $r['not_close_reason'] : '',
                $canSeeDeduct ? $r['deduct_amount'] : '',
                $canSeeDeduct ? $r['deduct_note'] : '',
            ]);
        }
        fclose($out);
        exit;
    }

    // ── 統計報表（依狀態/責任單位/來源/月份彙總）────────────────────────────
    case 'get_stats_report': {
        if (!_carHas('car_view')) jerr('您沒有檢閱權限', 403);
        // 扣款彙總為機密：僅扣款判定人員/最終決策者/系統管理員可見
        $canSeeDeduct = car_is_admin_deduct($pdo, (int)$me['id']) || car_is_final_decider($pdo, (int)$me['id']) || _carHas('all');
        $L = car_labels();
        $byStatus = $pdo->query("SELECT status, COUNT(*) c FROM car_order GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($byStatus as &$r) { $r['label'] = $L['status'][$r['status']] ?? $r['status']; } unset($r);
        $byResp = $pdo->query(
            "SELECT COALESCE(o.resp_display, d.name, '（未指定）') AS name, COUNT(*) c,
                    SUM(CASE WHEN o.status='closed' THEN 1 ELSE 0 END) closed_c,
                    SUM(CASE WHEN o.status='rejected' THEN 1 ELSE 0 END) rejected_c,
                    SUM(COALESCE(o.deduct_amount,0)) deduct_sum
             FROM car_order o LEFT JOIN department d ON d.id = o.resp_dept_id
             GROUP BY COALESCE(o.resp_display, d.name, '（未指定）') ORDER BY c DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $bySource = $pdo->query("SELECT source_type, COUNT(*) c FROM car_order GROUP BY source_type")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($bySource as &$r) { $r['label'] = $L['source_type'][$r['source_type']] ?? $r['source_type']; } unset($r);
        $byMonth = $pdo->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) c,
                    SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) closed_c
             FROM car_order GROUP BY m ORDER BY m DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
        if (!$canSeeDeduct) { foreach ($byResp as &$_r) { unset($_r['deduct_sum']); } unset($_r); }
        jout(['success' => true, 'by_status' => $byStatus, 'by_resp' => $byResp,
              'by_source' => $bySource, 'by_month' => $byMonth, 'show_deduct' => (bool)$canSeeDeduct]);
    }

    // ── 附件上傳（開單前用 temp_key 暫存；開單後用 car_id 直掛）───────────────
    case 'upload_attachment': {
        require_once __DIR__ . '/../common/attachment_lib.php';
        $carId   = (int)($_POST['car_id'] ?? 0);
        $tempKey = trim($_POST['temp_key'] ?? '');
        $section = $_POST['field_type'] ?? '';
        if (!in_array($section, ['desc', 'cause', 'correction', 'prevention', 'result'], true)) jfail('附件區段錯誤');
        if (!$carId && ($tempKey === '' || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $tempKey))) jfail('缺少 car_id 或 temp_key');
        if (empty($_FILES['file'])) jfail('未收到檔案');

        // 權限：desc=填表人/車修權限；三段=回覆人；result=決策/管理權限
        if ($carId) {
            $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$carId]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o) jfail('查無此單');
            $uid = (int)$me['id']; $ok = _carHas('all');
            if (!$ok) {
                if ($section === 'desc') $ok = ((int)$o['created_by'] === $uid);   // 非開立人不可修改他人單據（含附件）
                elseif ($section === 'result') $ok = _carHas('car_sign_primary') || _carHas('car_sign_final') || _carHas('car_manage_settings')
                                                    || car_is_primary_candidate($pdo, $o, $uid) || car_is_final_decider($pdo, $uid);
                else $ok = ((int)$o['assigned_to'] === $uid);
            }
            if (!$ok) jerr('您沒有此區段的附件上傳權限', 403);
        }

        $descTxt = trim($_POST['description'] ?? '');
        if ($descTxt === '') jfail('請填寫附件說明（每個附件都需填寫說明）');

        $v = eg_att_validate_upload($_FILES['file']);
        if (!$v['ok']) jfail($v['msg']);
        $ext  = $v['ext'];
        $orig = $_FILES['file']['name'];

        $root = rtrim(car_setting($pdo, 'car_attach_root_path', ''), "\\/");
        if ($root === '') jfail('尚未設定附件儲存根路徑（設定→簽核流程設定）');
        $sub = $carId ? preg_replace('/[^A-Za-z0-9R]/', '', (string)($o['car_no'] ?: ('ID' . $carId))) : ('_temp/' . $tempKey);
        $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) jfail("無法建立附件資料夾（請確認路徑可寫入）：{$dir}");

        $fname = date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest  = $dir . DIRECTORY_SEPARATOR . $fname;
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) jfail('檔案寫入失敗（請確認 NAS 路徑可存取）');

        // 排序值：同單(或同暫存鍵)同區段最大值 +1，新附件排在最後
        if ($carId) {
            $soSt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM car_attachment WHERE car_id = ? AND field_type = ?");
            $soSt->execute([$carId, $section]);
        } else {
            $soSt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM car_attachment WHERE temp_key = ? AND field_type = ? AND car_id IS NULL");
            $soSt->execute([$tempKey, $section]);
        }
        $sortOrder = (int)$soSt->fetchColumn();

        $pdo->prepare(
            "INSERT INTO car_attachment (car_id, temp_key, field_type, file_name, file_path, description, sort_order, original_filename, file_size, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([($carId ?: null), ($carId ? null : $tempKey), $section, $fname, $dest,
                       $descTxt, $sortOrder, $orig, (int)$_FILES['file']['size'], (int)$me['id']]);
        $attId = (int)$pdo->lastInsertId();
        if ($carId) car_log($pdo, $carId, 'attach', (int)$me['id'], $me['name'], car_section_name($section) . " 上傳附件「{$orig}」");
        jout(['success' => true, 'id' => $attId, 'file_name' => $fname, 'original_filename' => $orig,
              'field_type' => $section, 'message' => '附件已上傳']);
    }

    // ── 附件下載 ────────────────────────────────────────────────────────────
    case 'download_attachment': {
        $aid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM car_attachment WHERE id = ?"); $st->execute([$aid]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) jerr('附件不存在或無法存取', 404);
        $fullPath = carAttResolvePath($pdo, $a);
        if (!is_file($fullPath)) jerr('附件不存在或無法存取', 404);
        if (!_carHas('car_view') && $a['car_id']) {   // 當事人可下載自己單據的附件
            $so = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $so->execute([(int)$a['car_id']]);
            $ord = $so->fetch(PDO::FETCH_ASSOC);
            if (!$ord || !car_is_stakeholder($pdo, $ord, (int)$me['id'])) jerr('您沒有檢閱權限', 403);
        }
        $ext = strtolower(pathinfo($a['file_name'], PATHINFO_EXTENSION));
        $mimes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','pdf'=>'application/pdf'];
        header_remove('Content-Type');
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        $dl = isset($_GET['dl']) ? 'attachment' : 'inline';
        header("Content-Disposition: {$dl}; filename*=UTF-8''" . rawurlencode($a['original_filename'] ?: $a['file_name']));
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    // ── 附件刪除（嚴格僅上傳者本人，無任何例外）─────────────────────────────
    case 'delete_attachment': {
        $aid = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM car_attachment WHERE id = ?"); $st->execute([$aid]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) jfail('附件不存在');
        if ((int)$a['created_by'] !== (int)$me['id'])
            jerr('僅上傳者本人可刪除附件', 403);
        // 主管核准成立後，開立人不可再刪除異常說明區附件（申請中/退回/草稿階段仍可）
        if ($a['field_type'] === 'desc' && !empty($a['car_id'])) {
            $so = $pdo->prepare("SELECT status FROM car_order WHERE id = ?");
            $so->execute([(int)$a['car_id']]);
            $ost = (string)$so->fetchColumn();
            if (!in_array($ost, ['draft', 'applying', 'app_rejected'], true))
                jfail('主管已核准成立，異常說明附件不可再刪除');
        }
        $fullPath = carAttResolvePath($pdo, $a);
        if (is_file($fullPath)) @unlink($fullPath);
        $pdo->prepare("DELETE FROM car_attachment WHERE id = ?")->execute([$aid]);
        if ($a['car_id']) car_log($pdo, (int)$a['car_id'], 'attach_del', (int)$me['id'], $me['name'],
            car_section_name($a['field_type']) . " 刪除附件「" . ($a['original_filename'] ?: $a['file_name']) . '」');
        jout(['success' => true, 'message' => '附件已刪除']);
    }

    // ── 附件拖曳排序（權限同該區段的附件上傳權；編號「附件N」依此順序）────────
    case 'reorder_attachments': {
        $carId   = (int)($_POST['car_id'] ?? 0);
        $section = $_POST['field_type'] ?? '';
        $ids     = json_decode((string)($_POST['ids'] ?? '[]'), true);
        if (!$carId || !is_array($ids) || !$ids) jfail('缺少參數');
        if (!in_array($section, ['desc', 'cause', 'correction', 'prevention', 'result'], true)) jfail('附件區段錯誤');
        $st = $pdo->prepare("SELECT * FROM car_order WHERE id = ?"); $st->execute([$carId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) jfail('查無此單');
        $uid = (int)$me['id']; $ok = _carHas('all');
        if (!$ok) {
            if ($section === 'desc') $ok = ((int)$o['created_by'] === $uid);
            elseif ($section === 'result') $ok = _carHas('car_sign_primary') || _carHas('car_sign_final') || _carHas('car_manage_settings')
                                                || car_is_primary_candidate($pdo, $o, $uid) || car_is_final_decider($pdo, $uid);
            else $ok = ((int)$o['assigned_to'] === $uid);
        }
        if (!$ok) jerr('您沒有此區段的附件排序權限', 403);
        // 主管核准成立後，異常說明區附件順序鎖定（編號已隨單據成立，不可再變動）
        if ($section === 'desc' && !in_array($o['status'], ['draft', 'applying', 'app_rejected'], true))
            jfail('主管已核准成立，異常說明附件順序不可再變更');
        // 驗證 ids 皆屬本單本區段，避免竄改
        $chk = $pdo->prepare("SELECT id FROM car_attachment WHERE car_id = ? AND field_type = ?");
        $chk->execute([$carId, $section]);
        $valid = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach ($ids as $aid2) if (!in_array($aid2, $valid, true)) jfail('附件不屬於此單據區段');
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare("UPDATE car_attachment SET sort_order = ? WHERE id = ?");
            foreach ($ids as $pos => $aid2) $up->execute([$pos + 1, $aid2]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jerr('排序失敗：' . $e->getMessage(), 500);
        }
        jout(['success' => true, 'message' => '已更新附件順序']);
    }

    default:
        jerr('未知的動作: ' . $action, 404);
    }
} catch (Throwable $e) {
    error_log('[CAR_API] action=' . $action . ' error=' . $e->getMessage());   // 供日後從 php_error.log 直接定位
    jerr('伺服器錯誤：' . $e->getMessage(), 500);
}

/** desc 區暫存附件 → 綁定到某 car_id（拆多單時每單各複製一列連結） */
function car_link_desc_attachments(PDO $pdo, string $tempKey, int $carId, $uid): void {
    // 找出此 temp_key 的 desc 暫存列，複製給本單（保留暫存以供其他拆單複製）
    $st = $pdo->prepare("SELECT file_name, file_path, tag_id, description, sort_order, original_filename, file_size, preview_path
                         FROM car_attachment WHERE temp_key = ? AND field_type = 'desc' AND car_id IS NULL");
    $st->execute([$tempKey]);
    // temp_key 一併複製進新紀錄：實體檔案仍留在暫存資料夾（未搬動），路徑解析須靠 temp_key 才能找到正確位置
    $ins = $pdo->prepare(
        "INSERT INTO car_attachment
           (car_id, temp_key, field_type, file_name, file_path, tag_id, description, sort_order, original_filename, file_size, preview_path, created_by)
         VALUES (?, ?, 'desc', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $ins->execute([$carId, $tempKey, $a['file_name'], $a['file_path'], $a['tag_id'], $a['description'], (int)$a['sort_order'],
                       $a['original_filename'], $a['file_size'], $a['preview_path'], $uid]);
    }
}

/**
 * 附件路徑解析（NAS 路徑改造：不再信任 DB file_path 欄位，改用目前設定的根路徑 + 即時算出的子資料夾現場組路徑）。
 * $row 需含 car_id, temp_key, file_name；若 car_id 有值且 temp_key 為空，會另外查一次 car_no。
 * 判斷順序：temp_key 有值 → 一律視為仍在暫存資料夾（含「拆單複製後但未搬檔」的情況，見 car_link_desc_attachments）；
 * 否則才用 car_id 對應的單號資料夾。
 */
function carAttResolvePath(PDO $pdo, array $row): string {
    $root = rtrim(car_setting($pdo, 'car_attach_root_path', ''), "\\/");
    $tempKey = trim((string)($row['temp_key'] ?? ''));
    if ($tempKey !== '') {
        $sub = '_temp/' . $tempKey;
    } else {
        $carId = (int)($row['car_id'] ?? 0);
        $carNo = $row['car_no'] ?? null;
        if ($carNo === null && $carId > 0) {
            $st = $pdo->prepare("SELECT car_no FROM car_order WHERE id = ?");
            $st->execute([$carId]);
            $carNo = $st->fetchColumn();
        }
        $sub = preg_replace('/[^A-Za-z0-9R]/', '', (string)($carNo ?: ('ID' . $carId)));
    }
    $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
    return $dir . DIRECTORY_SEPARATOR . (string)($row['file_name'] ?? '');
}
